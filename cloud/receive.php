<?php
// cloud/receive.php — runs on your web host
header('Content-Type: application/json');
require __DIR__ . '/db.php'; // provides $conn (mysqli)

$SHARED_API_KEY = 'CHANGE_ME_TO_A_LONG_RANDOM_STRING_1234567890';

if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== $SHARED_API_KEY) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Invalid API key']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON']);
    exit;
}

if (!empty($body['ping'])) {
    echo json_encode(['ok' => true, 'message' => 'pong', 'time' => date('c')]);
    exit;
}

$counts = [
    'transactions' => 0, 'products' => 0, 'users' => 0,
    'product_batches' => 0, 'expired_products' => 0,
    'restock_requests' => 0, 'inventory_audit' => 0, 'edit_deletion_log' => 0,
];

// Helper: bind value or NULL
function v($row, $key) {
    return isset($row[$key]) && $row[$key] !== '' ? $row[$key] : null;
}

// ─── TRANSACTIONS ────────────────────────────────────────────────────────────
if (!empty($body['data']['transactions'])) {
    $sql = "INSERT INTO transactions
        (id, cashier_name, date, time, total, paid, change_due,
         items, original_items, status, voided_by, edited_by,
         voided_at, edited_at, void_reason, edit_remarks,
         payment_method, reference_no)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            cashier_name   = VALUES(cashier_name),
            date           = VALUES(date),
            time           = VALUES(time),
            total          = VALUES(total),
            paid           = VALUES(paid),
            change_due     = VALUES(change_due),
            items          = VALUES(items),
            original_items = VALUES(original_items),
            status         = VALUES(status),
            voided_by      = VALUES(voided_by),
            edited_by      = VALUES(edited_by),
            voided_at      = VALUES(voided_at),
            edited_at      = VALUES(edited_at),
            void_reason    = VALUES(void_reason),
            edit_remarks   = VALUES(edit_remarks),
            payment_method = VALUES(payment_method),
            reference_no   = VALUES(reference_no)";
    $stmt = $conn->prepare($sql);
    foreach ($body['data']['transactions'] as $t) {
        $stmt->bind_param('isssdddssssssssss',
            $t['id'], v($t,'cashier_name'), v($t,'date'), v($t,'time'),
            v($t,'total'), v($t,'paid'), v($t,'change_due'),
            v($t,'items'), v($t,'original_items'), v($t,'status'),
            v($t,'voided_by'), v($t,'edited_by'),
            v($t,'voided_at'), v($t,'edited_at'),
            v($t,'void_reason'), v($t,'edit_remarks'),
            v($t,'payment_method') ?? 'Cash', v($t,'reference_no')
        );
        if ($stmt->execute()) $counts['transactions']++;
    }
}

// ─── PRODUCTS ────────────────────────────────────────────────────────────────
// `code` is UNIQUE and `id` is PK. We key on `id` — if an ID exists we update,
// otherwise insert. If a `code` collision happens on a new ID, MySQL will
// throw; we catch and skip so one bad row doesn't abort the whole sync.
if (!empty($body['data']['products'])) {
    $sql = "INSERT INTO products
        (id, code, name, category, price, brand, seller_store,
         expiry_date, purchase_date, pieces, kg, measurement_type,
         purchase_price, image_path, date_added)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            code             = VALUES(code),
            name             = VALUES(name),
            category         = VALUES(category),
            price            = VALUES(price),
            brand            = VALUES(brand),
            seller_store     = VALUES(seller_store),
            expiry_date      = VALUES(expiry_date),
            purchase_date    = VALUES(purchase_date),
            pieces           = VALUES(pieces),
            kg               = VALUES(kg),
            measurement_type = VALUES(measurement_type),
            purchase_price   = VALUES(purchase_price),
            image_path       = VALUES(image_path)";
    $stmt = $conn->prepare($sql);
    foreach ($body['data']['products'] as $p) {
        $stmt->bind_param('isssdssssiisss s',
            $p['id'], v($p,'code'), v($p,'name'), v($p,'category'),
            v($p,'price'), v($p,'brand'), v($p,'seller_store'),
            v($p,'expiry_date'), v($p,'purchase_date'),
            (int)(v($p,'pieces') ?? 0), (float)(v($p,'kg') ?? 0),
            v($p,'measurement_type') ?? 'pieces',
            v($p,'purchase_price'), v($p,'image_path'), v($p,'date_added')
        );
        try { if ($stmt->execute()) $counts['products']++; } catch (Exception $e) { /* skip */ }
    }
}

// ─── NEW_USER ────────────────────────────────────────────────────────────────
// We do NOT copy passwords — cloud creates the account with a temp hash.
// The client must reset the password after first cloud login.
if (!empty($body['data']['users'])) {
    $sql = "INSERT INTO new_user
        (id, username, fullname, type, email, password, created_at)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            username  = VALUES(username),
            fullname  = VALUES(fullname),
            type      = VALUES(type),
            email     = VALUES(email)";
    $stmt = $conn->prepare($sql);
    $tempHash = password_hash('CHANGE_ME_ON_CLOUD', PASSWORD_DEFAULT);
    foreach ($body['data']['users'] as $u) {
        $createdAt = v($u,'created_at') ?? date('Y-m-d H:i:s');
        $stmt->bind_param('issssss',
            $u['id'], v($u,'username'), v($u,'fullname'),
            v($u,'type'), v($u,'email'), $tempHash, $createdAt
        );
        if ($stmt->execute()) $counts['users']++;
    }
}

// ─── PRODUCT_BATCHES ─────────────────────────────────────────────────────────
if (!empty($body['data']['product_batches'])) {
    $sql = "INSERT INTO product_batches
        (id, product_id, code, name, category, brand, seller_store,
         purchase_price, price, quantity, kg, measurement_type,
         purchase_date, expiry_date, batch_date, status, image_path)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            product_id       = VALUES(product_id),
            code             = VALUES(code),
            name             = VALUES(name),
            category         = VALUES(category),
            brand            = VALUES(brand),
            seller_store     = VALUES(seller_store),
            purchase_price   = VALUES(purchase_price),
            price            = VALUES(price),
            quantity         = VALUES(quantity),
            kg               = VALUES(kg),
            measurement_type = VALUES(measurement_type),
            purchase_date    = VALUES(purchase_date),
            expiry_date      = VALUES(expiry_date),
            batch_date       = VALUES(batch_date),
            status           = VALUES(status),
            image_path       = VALUES(image_path)";
    $stmt = $conn->prepare($sql);
    foreach ($body['data']['product_batches'] as $b) {
        $stmt->bind_param('iisssssssdississs',
            $b['id'], $b['product_id'], v($b,'code'), v($b,'name'),
            v($b,'category'), v($b,'brand'), v($b,'seller_store'),
            v($b,'purchase_price'), v($b,'price'),
            (int)(v($b,'quantity') ?? 0), (float)(v($b,'kg') ?? 0),
            v($b,'measurement_type') ?? 'pieces',
            v($b,'purchase_date'), v($b,'expiry_date'),
            v($b,'batch_date') ?? date('Y-m-d H:i:s'),
            v($b,'status') ?? 'active', v($b,'image_path')
        );
        if ($stmt->execute()) $counts['product_batches']++;
    }
}

// ─── EXPIRED_PRODUCTS ────────────────────────────────────────────────────────
if (!empty($body['data']['expired_products'])) {
    $sql = "INSERT INTO expired_products
        (id, batch_id, product_id, code, name, category, brand, seller_store,
         purchase_price, price, quantity, kg, measurement_type,
         purchase_date, expiry_date, expired_date, days_expired,
         batch_date, moved_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            batch_id         = VALUES(batch_id),
            product_id       = VALUES(product_id),
            code             = VALUES(code),
            name             = VALUES(name),
            category         = VALUES(category),
            brand            = VALUES(brand),
            seller_store     = VALUES(seller_store),
            purchase_price   = VALUES(purchase_price),
            price            = VALUES(price),
            quantity         = VALUES(quantity),
            kg               = VALUES(kg),
            measurement_type = VALUES(measurement_type),
            purchase_date    = VALUES(purchase_date),
            expiry_date      = VALUES(expiry_date),
            expired_date     = VALUES(expired_date),
            days_expired     = VALUES(days_expired),
            batch_date       = VALUES(batch_date),
            moved_at         = VALUES(moved_at)";
    $stmt = $conn->prepare($sql);
    foreach ($body['data']['expired_products'] as $e) {
        $stmt->bind_param('iiisssss sddissiiis',
            $e['id'], $e['batch_id'], $e['product_id'],
            v($e,'code'), v($e,'name'), v($e,'category'),
            v($e,'brand'), v($e,'seller_store'),
            v($e,'purchase_price'), v($e,'price'),
            (int)(v($e,'quantity') ?? 0), (float)(v($e,'kg') ?? 0),
            v($e,'measurement_type') ?? 'pieces',
            v($e,'purchase_date'), v($e,'expiry_date'), v($e,'expired_date'),
            (int)(v($e,'days_expired') ?? 0),
            v($e,'batch_date'), v($e,'moved_at') ?? date('Y-m-d H:i:s')
        );
        if ($stmt->execute()) $counts['expired_products']++;
    }
}

// ─── RESTOCK_REQUESTS ────────────────────────────────────────────────────────
if (!empty($body['data']['restock_requests'])) {
    $sql = "INSERT INTO restock_requests
        (id, product_id, code, name, category, brand, seller_store,
         purchase_price, price, quantity, kg, measurement_type,
         purchase_date, expiry_date, notes, status,
         requested_at, approved_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            product_id       = VALUES(product_id),
            code             = VALUES(code),
            name             = VALUES(name),
            category         = VALUES(category),
            brand            = VALUES(brand),
            seller_store     = VALUES(seller_store),
            purchase_price   = VALUES(purchase_price),
            price            = VALUES(price),
            quantity         = VALUES(quantity),
            kg               = VALUES(kg),
            measurement_type = VALUES(measurement_type),
            purchase_date    = VALUES(purchase_date),
            expiry_date      = VALUES(expiry_date),
            notes            = VALUES(notes),
            status           = VALUES(status),
            approved_at      = VALUES(approved_at)";
    $stmt = $conn->prepare($sql);
    foreach ($body['data']['restock_requests'] as $r) {
        $stmt->bind_param('iisssssddississsss',
            $r['id'], $r['product_id'], v($r,'code'), v($r,'name'),
            v($r,'category'), v($r,'brand'), v($r,'seller_store'),
            v($r,'purchase_price'), v($r,'price'),
            (int)(v($r,'quantity') ?? 0), (float)(v($r,'kg') ?? 0),
            v($r,'measurement_type') ?? 'pieces',
            v($r,'purchase_date'), v($r,'expiry_date'),
            v($r,'notes'), v($r,'status') ?? 'pending',
            v($r,'requested_at') ?? date('Y-m-d H:i:s'),
            v($r,'approved_at')
        );
        if ($stmt->execute()) $counts['restock_requests']++;
    }
}

// ─── INVENTORY_AUDIT ─────────────────────────────────────────────────────────
if (!empty($body['data']['inventory_audit'])) {
    $sql = "INSERT INTO inventory_audit
        (id, batch_id, product_id, action,
         previous_quantity, new_quantity, previous_kg, new_kg,
         seller_store, purchase_price, notes, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            batch_id          = VALUES(batch_id),
            product_id        = VALUES(product_id),
            action            = VALUES(action),
            previous_quantity = VALUES(previous_quantity),
            new_quantity      = VALUES(new_quantity),
            previous_kg       = VALUES(previous_kg),
            new_kg            = VALUES(new_kg),
            seller_store      = VALUES(seller_store),
            purchase_price    = VALUES(purchase_price),
            notes             = VALUES(notes),
            created_at        = VALUES(created_at)";
    $stmt = $conn->prepare($sql);
    foreach ($body['data']['inventory_audit'] as $a) {
        $stmt->bind_param('iiisisddsdss',
            $a['id'], v($a,'batch_id'), v($a,'product_id'), v($a,'action'),
            (int)(v($a,'previous_quantity') ?? 0),
            (int)(v($a,'new_quantity') ?? 0),
            (float)(v($a,'previous_kg') ?? 0),
            (float)(v($a,'new_kg') ?? 0),
            v($a,'seller_store'), v($a,'purchase_price'),
            v($a,'notes'), v($a,'created_at') ?? date('Y-m-d H:i:s')
        );
        if ($stmt->execute()) $counts['inventory_audit']++;
    }
}

// ─── EDIT_DELETION_LOG ───────────────────────────────────────────────────────
if (!empty($body['data']['edit_deletion_log'])) {
    $sql = "INSERT INTO edit_deletion_log
        (id, transaction_id, edit_id, deleted_by, deleted_at, reason)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            transaction_id = VALUES(transaction_id),
            edit_id        = VALUES(edit_id),
            deleted_by     = VALUES(deleted_by),
            deleted_at     = VALUES(deleted_at),
            reason         = VALUES(reason)";
    $stmt = $conn->prepare($sql);
    foreach ($body['data']['edit_deletion_log'] as $l) {
        $stmt->bind_param('iiiiss',
            $l['id'], $l['transaction_id'], $l['edit_id'],
            $l['deleted_by'], $l['deleted_at'], v($l,'reason')
        );
        if ($stmt->execute()) $counts['edit_deletion_log']++;
    }
}

// ─── SYNC LOG ────────────────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    origin VARCHAR(255),
    transactions INT DEFAULT 0, products INT DEFAULT 0, users INT DEFAULT 0,
    product_batches INT DEFAULT 0, expired_products INT DEFAULT 0,
    restock_requests INT DEFAULT 0, inventory_audit INT DEFAULT 0,
    edit_deletion_log INT DEFAULT 0
)");
$stmt = $conn->prepare("INSERT INTO sync_log
    (origin, transactions, products, users, product_batches,
     expired_products, restock_requests, inventory_audit, edit_deletion_log)
    VALUES (?,?,?,?,?,?,?,?,?)");
$stmt->bind_param('siiiiiiii',
    $body['origin'],
    $counts['transactions'], $counts['products'], $counts['users'],
    $counts['product_batches'], $counts['expired_products'],
    $counts['restock_requests'], $counts['inventory_audit'], $counts['edit_deletion_log']
);
$stmt->execute();

echo json_encode([
    'ok'      => true,
    'counts'  => $counts,
    'inserted_txns'     => $counts['transactions'],
    'inserted_products' => $counts['products'],
    'inserted_users'    => $counts['users'],
    'received_at' => date('c'),
]);