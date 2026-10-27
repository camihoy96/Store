<?php
// manage/upload_worker.php — local payload builder for cloud sync
session_start();
if (!isset($_SESSION['loggedin'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Not logged in']);
    exit;
}
require_once __DIR__ . '/../dbconn.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

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

if ($action === 'build') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $onlyUnsynced = !empty($body['only_unsynced']);
    $lastSync = getSetting($conn, 'last_sync_at') ?: '1970-01-01 00:00:00';
    $lastSyncEsc = $conn->real_escape_string($lastSync);

    // ─── transactions ──────────────────────────────────────────────
    $txnWhere = $onlyUnsynced ? "AND CONCAT(date,' ',time) > '$lastSyncEsc'" : "";
    $transactions = [];
    $res = $conn->query("SELECT * FROM transactions
                         WHERE (status IS NULL OR status != 'voided') $txnWhere
                         ORDER BY id ASC");
    while ($row = $res->fetch_assoc()) $transactions[] = $row;

    // ─── products (uses date_added, no updated_at) ────────────────
    $prodWhere = $onlyUnsynced ? "WHERE date_added > '$lastSyncEsc'" : "";
    $products = [];
    $res = $conn->query("SELECT * FROM products $prodWhere ORDER BY id ASC");
    while ($row = $res->fetch_assoc()) $products[] = $row;

    // ─── new_user (created_at) — omit password ────────────────────
    $userWhere = $onlyUnsynced ? "WHERE created_at > '$lastSyncEsc'" : "";
    $users = [];
    $res = $conn->query("SELECT id, username, fullname, type, email, created_at
                         FROM new_user $userWhere ORDER BY id ASC");
    while ($row = $res->fetch_assoc()) $users[] = $row;

    // ─── product_batches (batch_date as recency) ──────────────────
    $batchWhere = $onlyUnsynced ? "WHERE batch_date > '$lastSyncEsc'" : "";
    $batches = [];
    $res = $conn->query("SELECT * FROM product_batches $batchWhere ORDER BY id ASC");
    while ($row = $res->fetch_assoc()) $batches[] = $row;

    // ─── expired_products (moved_at as recency) ───────────────────
    $expWhere = $onlyUnsynced ? "WHERE moved_at > '$lastSyncEsc'" : "";
    $expired = [];
    $res = $conn->query("SELECT * FROM expired_products $expWhere ORDER BY id ASC");
    while ($row = $res->fetch_assoc()) $expired[] = $row;

    // ─── restock_requests (requested_at as recency) ───────────────
    $reqWhere = $onlyUnsynced ? "WHERE requested_at > '$lastSyncEsc'" : "";
    $restocks = [];
    $res = $conn->query("SELECT * FROM restock_requests $reqWhere ORDER BY id ASC");
    while ($row = $res->fetch_assoc()) $restocks[] = $row;

    // ─── inventory_audit (created_at) ─────────────────────────────
    $auditWhere = $onlyUnsynced ? "WHERE created_at > '$lastSyncEsc'" : "";
    $audits = [];
    $res = $conn->query("SELECT * FROM inventory_audit $auditWhere ORDER BY id ASC");
    while ($row = $res->fetch_assoc()) $audits[] = $row;

    // ─── edit_deletion_log (deleted_at) ───────────────────────────
    $logWhere = $onlyUnsynced ? "WHERE deleted_at > '$lastSyncEsc'" : "";
    $logs = [];
    $res = $conn->query("SELECT * FROM edit_deletion_log $logWhere ORDER BY id ASC");
    while ($row = $res->fetch_assoc()) $logs[] = $row;

    echo json_encode([
        'ok'        => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'origin'    => gethostname(),
        'counts' => [
            'transactions'     => count($transactions),
            'products'         => count($products),
            'users'            => count($users),
            'product_batches'  => count($batches),
            'expired_products' => count($expired),
            'restock_requests' => count($restocks),
            'inventory_audit'  => count($audits),
            'edit_deletion_log'=> count($logs),
        ],
        'data' => [
            'transactions'      => $transactions,
            'products'          => $products,
            'users'             => $users,
            'product_batches'   => $batches,
            'expired_products'  => $expired,
            'restock_requests'  => $restocks,
            'inventory_audit'   => $audits,
            'edit_deletion_log' => $logs,
        ],
    ]);
    exit;
}

if ($action === 'mark_synced') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $now  = date('Y-m-d H:i:s');
    setSetting($conn, 'last_sync_at', $now);
    foreach (($body['counts'] ?? []) as $k => $v) {
        setSetting($conn, 'last_sync_' . $k, (string)$v);
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Unknown action']);