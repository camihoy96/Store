<?php
/**
 * record/transaction.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Handles POST from home.php POS terminal.
 * Accepts JSON body with cash OR e-wallet payment data.
 * Saves payment_method and reference_no into the transactions table.
 *
 * Expected JSON fields:
 *   items[]           array of cart items
 *   total             string  "123.00"
 *   paid              string  amount tendered
 *   change            string  change due
 *   cashier           string  cashier full name
 *   date              string  "YYYY-MM-DD"
 *   time              string  "HH:MM:SS"
 *   payment_method    string  "Cash" | "GCash" | "Maya" | etc.  (optional, default Cash)
 *   reference_no      string  e-wallet ref number                (optional)
 * ─────────────────────────────────────────────────────────────────────────────
 */

session_start();
header('Content-Type: application/json');

/* ── Guard: must be logged in ─────────────────────────────────────── */
if (!isset($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated.']);
    exit;
}

/* ── Only accept POST ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

/* ── Parse JSON body ──────────────────────────────────────────────── */
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload.']);
    exit;
}

/* ── DB connection ────────────────────────────────────────────────── */
require('../dbconn.php');

date_default_timezone_set('Asia/Manila');

/* ── Extract & sanitise fields ────────────────────────────────────── */
$items        = $data['items']          ?? [];
$total        = (float)($data['total']  ?? 0);
$paid         = (float)($data['paid']   ?? 0);
$change       = (float)($data['change'] ?? 0);
$cashier      = trim($data['cashier']   ?? ($_SESSION['fullname'] ?? 'Unknown'));
$date         = $data['date']           ?? date('Y-m-d');
$time         = $data['time']           ?? date('H:i:s');

/* Payment method — normalise provider name from wallet modal */
$rawMethod    = trim($data['payment_method'] ?? 'Cash');
$referenceNo  = trim($data['reference_no']   ?? '');

/* ── Resolve payment_method label ─────────────────────────────────── */
/*
 * home.php processWalletPayment() sends:
 *   payment_method : "wallet"
 *   wallet_provider: "GCash" | "Maya" | etc.
 *
 * home.php processPayment() (cash) sends nothing extra → defaults to "Cash"
 *
 * We store the provider name (GCash, Maya …) rather than the generic "wallet"
 * so the transaction records table can display it directly.
 */
if (strtolower($rawMethod) === 'wallet' || strtolower($rawMethod) === 'ewallet') {
    $provider    = trim($data['wallet_provider'] ?? '');
    $referenceNo = trim($data['wallet_ref']      ?? $referenceNo);
    $paymentMethod = $provider !== '' ? $provider : 'E-Wallet';
} else {
    $paymentMethod = ($rawMethod !== '') ? $rawMethod : 'Cash';
}

/* ── Validate ─────────────────────────────────────────────────────── */
if (empty($items)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No items provided.']);
    exit;
}
if ($total <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid total amount.']);
    exit;
}
if (strtolower($paymentMethod) !== 'cash' && empty($referenceNo)) {
    /* E-wallet payments require a reference number */
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Reference number is required for e-wallet payments.']);
    exit;
}

/* ── Encode items to JSON ─────────────────────────────────────────── */
$itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

/* ── Check if payment columns exist (graceful upgrade) ────────────── */
$hasPaymentMethod = false;
$hasReferenceNo   = false;

$chk1 = $conn->query("SHOW COLUMNS FROM transactions LIKE 'payment_method'");
if ($chk1 && $chk1->num_rows > 0) $hasPaymentMethod = true;

$chk2 = $conn->query("SHOW COLUMNS FROM transactions LIKE 'reference_no'");
if ($chk2 && $chk2->num_rows > 0) $hasReferenceNo = true;

/* ── Auto-create columns if missing ───────────────────────────────── */
if (!$hasPaymentMethod) {
    $conn->query("ALTER TABLE transactions ADD COLUMN payment_method VARCHAR(50) DEFAULT 'Cash' AFTER change_due");
    $hasPaymentMethod = true;
}
if (!$hasReferenceNo) {
    $conn->query("ALTER TABLE transactions ADD COLUMN reference_no VARCHAR(100) DEFAULT NULL AFTER payment_method");
    $hasReferenceNo = true;
}

/* ── Insert transaction ───────────────────────────────────────────── */
$sql = "INSERT INTO transactions
            (cashier_name, date, time, total, paid, change_due, items, payment_method, reference_no)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param(
    'sssdddsss',
    $cashier,
    $date,
    $time,
    $total,
    $paid,
    $change,
    $itemsJson,
    $paymentMethod,
    $referenceNo
);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB execute failed: ' . $stmt->error]);
    exit;
}

$transactionId = $conn->insert_id;
$stmt->close();

/* ── Deduct stock for each item ───────────────────────────────────── */
foreach ($items as $item) {
    $itemId = $item['id'] ?? null;

    /* Skip custom products (id starts with "custom-") */
    if (!$itemId || str_starts_with((string)$itemId, 'custom-')) continue;

    $qty  = (float)($item['qty']  ?? 0);
    $type = $item['measurement_type'] ?? 'pieces';

    if ($type === 'kg') {
        $upd = $conn->prepare("UPDATE products SET kg = GREATEST(0, kg - ?) WHERE id = ?");
        $upd->bind_param('di', $qty, $itemId);
    } else {
        $qtyInt = (int)$qty;
        $upd = $conn->prepare("UPDATE products SET pieces = GREATEST(0, pieces - ?) WHERE id = ?");
        $upd->bind_param('ii', $qtyInt, $itemId);
    }
    $upd->execute();
    $upd->close();
}

/* ── Success ──────────────────────────────────────────────────────── */
echo json_encode([
    'status'         => 'success',
    'transaction_id' => $transactionId,
    'payment_method' => $paymentMethod,
    'reference_no'   => $referenceNo,
    'total'          => number_format($total, 2),
    'paid'           => number_format($paid,  2),
    'change'         => number_format($change, 2),
]);
exit;