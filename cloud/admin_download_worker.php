<?php
// admin_upload/admin_download_worker.php — pulls cloud changes into local DB
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['loggedin'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Not logged in']);
    exit;
}
require_once __DIR__ . '/../dbconn.php';
header('Content-Type: application/json');

// ─── Config ─────────────────────────────────────────────────────────────────
$CLOUD_ENDPOINT = 'https://sjetrading.shop/cloud/send_changes.php';
$CLOUD_API_KEY  = 'ce6d7ea92a3106b91db7cb46cc5946d80eac3cb3fc9fb869e20715117446b7ec';

$DOWNLOADABLE = ['products', 'new_user', 'payment_methods'];

// ─── Helpers ────────────────────────────────────────────────────────────────
function getSetting($conn, $key) {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row['setting_value'] ?? null;
}

function setSetting($conn, $key, $val) {
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value)
                            VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param('ss', $key, $val);
    $stmt->execute();
}

function v($row, $key) {
    if (!is_array($row) || !array_key_exists($key, $row)) return null;
    $val = $row[$key];
    return ($val === '' || $val === null) ? null : $val;
}

$action = $_GET['action'] ?? '';

if ($action === 'download') {
    $changedSince = [];
    foreach ($DOWNLOADABLE as $tbl) {
        $since = getSetting($conn, 'last_download_' . $tbl . '_at');
        if ($since) $changedSince[$tbl] = $since;
    }

    $requestPayload = [
        'tables' => $DOWNLOADABLE,
        'changed_since' => $changedSince,
    ];

    $ch = curl_init($CLOUD_ENDPOINT);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-Key: ' . $CLOUD_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        echo json_encode(['ok' => false, 'message' => 'Failed to connect to cloud. HTTP ' . $httpCode . ($curlError ? ' - ' . $curlError : '')]);
        exit;
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['ok']) || !$data['ok']) {
        echo json_encode(['ok' => false, 'message' => 'Cloud returned an error: ' . ($data['message'] ?? 'Unknown error')]);
        exit;
    }

    $applied = [];
    $errors  = [];

    foreach ($data['data'] as $table => $rows) {
        if (empty($rows)) { $applied[$table] = 0; continue; }

        try {
            if ($table === 'products') {
                $sql = "INSERT INTO products
                    (id, code, name, category, price, brand, seller_store,
                     expiry_date, purchase_date, pieces, kg, measurement_type,
                     purchase_price, image_path, date_added)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                        code=VALUES(code), name=VALUES(name), category=VALUES(category),
                        price=VALUES(price), brand=VALUES(brand), seller_store=VALUES(seller_store),
                        expiry_date=VALUES(expiry_date), purchase_date=VALUES(purchase_date),
                        pieces=VALUES(pieces), kg=VALUES(kg),
                        measurement_type=VALUES(measurement_type),
                        purchase_price=VALUES(purchase_price),
                        image_path=VALUES(image_path)";
                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new Exception($conn->error);

                $checkBatchStmt = $conn->prepare("SELECT id FROM product_batches WHERE product_id = ? AND status = 'active' LIMIT 1");
                $insertBatchStmt = $conn->prepare("
                    INSERT INTO product_batches 
                    (product_id, code, name, category, brand, seller_store, 
                     purchase_price, price, quantity, kg, measurement_type, 
                     purchase_date, expiry_date, image_path, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                ");

                foreach ($rows as $p) {
                    $pid = (int)($p['id'] ?? 0);
                    if ($pid === 0) continue;
                    
                    $code = v($p,'code'); $name = v($p,'name'); $cat = v($p,'category');
                    $price = v($p,'price'); $brand = v($p,'brand'); $store = v($p,'seller_store');
                    $exp = v($p,'expiry_date'); $pur = v($p,'purchase_date');
                    $pieces = (int)(v($p,'pieces') ?? 0); 
                    $kg = (float)(v($p,'kg') ?? 0);
                    $mt = v($p,'measurement_type') ?? 'pieces';
                    $pp = v($p,'purchase_price'); $img = v($p,'image_path');
                    $added = v($p,'date_added');

                    // ✅ VERIFIED 15-CHARACTER TYPE STRING
                    $stmt->bind_param('isssdssssidsdss',
                        $pid, $code, $name, $cat, $price, $brand, $store,
                        $exp, $pur, $pieces, $kg, $mt, $pp, $img, $added
                    );
                    
                    try { 
                        $stmt->execute(); 
                        $applied[$table] = ($applied[$table] ?? 0) + 1; 
                        
                        $checkBatchStmt->bind_param('i', $pid);
                        $checkBatchStmt->execute();
                        $res = $checkBatchStmt->get_result();
                        
                        if ($res->num_rows === 0) {
                            // ✅ VERIFIED 14-CHARACTER TYPE STRING
                            $insertBatchStmt->bind_param('isssssddidssss', 
                                $pid, $code, $name, $cat, $brand, $store,
                                $pp, $price, $pieces, $kg, $mt, $pur, $exp, $img
                            );
                            $insertBatchStmt->execute();
                        }
                    } catch (Throwable $e) { 
                        $errors[] = "Product #$pid: ".$e->getMessage(); 
                    }
                }
                $checkBatchStmt->close();
                $insertBatchStmt->close();
            }
            elseif ($table === 'new_user') {
                $sql = "INSERT INTO new_user
                    (id, username, fullname, type, email, password, created_at)
                    VALUES (?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                        username=VALUES(username), fullname=VALUES(fullname),
                        type=VALUES(type), email=VALUES(email)";
                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new Exception($conn->error);
                $tempHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

                foreach ($rows as $u) {
                    $uid = (int)($u['id'] ?? 0);
                    if ($uid === 0) continue;
                    $un = v($u,'username'); $fn = v($u,'fullname');
                    $ty = v($u,'type'); $em = v($u,'email');
                    $ca = v($u,'created_at') ?? date('Y-m-d H:i:s');

                    $stmt->bind_param('issssss', $uid, $un, $fn, $ty, $em, $tempHash, $ca);
                    try { $stmt->execute(); $applied[$table] = ($applied[$table] ?? 0) + 1; }
                    catch (Throwable $e) { $errors[] = "User #$uid: ".$e->getMessage(); }
                }
            }
            elseif ($table === 'payment_methods') {
                $sql = "INSERT INTO payment_methods
                    (id, name, provider, qr_code_path, account_name, account_number,
                     is_active, display_order, description)
                    VALUES (?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                        name=VALUES(name), provider=VALUES(provider),
                        qr_code_path=VALUES(qr_code_path),
                        account_name=VALUES(account_name), account_number=VALUES(account_number),
                        is_active=VALUES(is_active), display_order=VALUES(display_order),
                        description=VALUES(description)";
                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new Exception($conn->error);

                foreach ($rows as $pm) {
                    $pmid = (int)($pm['id'] ?? 0);
                    if ($pmid === 0) continue;
                    $name = v($pm,'name'); $prov = v($pm,'provider');
                    $qr = v($pm,'qr_code_path'); $an = v($pm,'account_name');
                    $acct = v($pm,'account_number');
                    $active = (int)(v($pm,'is_active') ?? 1);
                    $ord = (int)(v($pm,'display_order') ?? 0);
                    $desc = v($pm,'description');

                    $stmt->bind_param('isssssiis', $pmid, $name, $prov, $qr, $an, $acct, $active, $ord, $desc);
                    try { $stmt->execute(); $applied[$table] = ($applied[$table] ?? 0) + 1; }
                    catch (Throwable $e) { $errors[] = "PaymentMethod #$pmid: ".$e->getMessage(); }
                }
            }
        } catch (Throwable $e) {
            $errors[] = "Table $table failed: " . $e->getMessage();
        }
    }

    $now = date('Y-m-d H:i:s');
    foreach ($DOWNLOADABLE as $tbl) {
        $tableHadError = false;
        foreach ($errors as $err) {
            if (stripos($err, $tbl) !== false || stripos($err, ucfirst($tbl)) !== false) {
                $tableHadError = true;
                break;
            }
        }
        if (!$tableHadError) {
            setSetting($conn, 'last_download_' . $tbl . '_at', $now);
        }
    }
    setSetting($conn, 'last_download_at', $now);

    echo json_encode([
        'ok'      => empty($errors),
        'applied' => $applied,
        'errors'  => $errors,
        'cloud_counts' => $data['counts'] ?? [],
        'synced_at' => $now,
    ]);
    exit;
}

if ($action === 'reset_markers') {
    foreach ($DOWNLOADABLE as $tbl) {
        setSetting($conn, 'last_download_' . $tbl . '_at', '1970-01-01 00:00:00');
    }
    echo json_encode(['ok' => true, 'reset' => $DOWNLOADABLE]);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Unknown action']);