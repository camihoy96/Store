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

/* ── Begin transaction so record + stock deduction are atomic ────── */
$conn->begin_transaction();

try {

    /* ── Insert transaction ───────────────────────────────────────── */
    $sql = "INSERT INTO transactions
                (cashier_name, date, time, total, paid, change_due, items, payment_method, reference_no)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('DB prepare failed: ' . $conn->error);
    }

    $stmt->bind_param(
        'sssdddsss',
        $cashier, $date, $time,
        $total, $paid, $change,
        $itemsJson, $paymentMethod, $referenceNo
    );

    if (!$stmt->execute()) {
        throw new Exception('DB execute failed: ' . $stmt->error);
    }

    $transactionId = $conn->insert_id;
    $stmt->close();

    /* ── Deduct stock from product_batches (FIFO) ─────────────────── */
    foreach ($items as $item) {
        $itemId = $item['id'] ?? null;

        /* Skip custom products */
        if (!$itemId || str_starts_with((string)$itemId, 'custom-')) continue;

        $qty = (float)($item['qty'] ?? 0);
        if ($qty <= 0) continue;

        $itemIdInt = (int)$itemId;
        $unitType  = $item['measurement_type'] ?? 'pieces';
        
        // Determine which column to use based on the unit type
        $stockColumn = ($unitType === 'kg') ? 'kg' : 'quantity';

        /* Fetch active batches oldest-first, lock rows */
        $batchQ = $conn->prepare(
            "SELECT id, $stockColumn AS current_stock
             FROM product_batches
             WHERE product_id = ? AND status = 'active' AND $stockColumn > 0
             ORDER BY id ASC
             FOR UPDATE"
        );
        if (!$batchQ) {
            throw new Exception('Prepare batch select failed: ' . $conn->error);
        }
        $batchQ->bind_param('i', $itemIdInt);
        $batchQ->execute();
        $batchRes = $batchQ->get_result();

        $updQ = $conn->prepare(
            "UPDATE product_batches SET $stockColumn = $stockColumn - ? WHERE id = ?"
        );
        if (!$updQ) {
            throw new Exception('Prepare batch update failed: ' . $conn->error);
        }

        $remaining = $qty;
        while ($b = $batchRes->fetch_assoc()) {
            if ($remaining <= 0.000001) break;

            $available = (float)$b['current_stock'];
            $take = min($remaining, $available);
            if ($take <= 0) continue;

            $updQ->bind_param('di', $take, $b['id']);
            if (!$updQ->execute()) {
                throw new Exception('Batch update failed: ' . $updQ->error);
            }
            if ($updQ->affected_rows === 0) {
                throw new Exception("Batch {$b['id']} update affected 0 rows.");
            }
            $remaining -= $take;
        }

        $batchQ->close();
        $updQ->close();

        if ($remaining > 0.000001) {
            throw new Exception(
                "Insufficient stock for '{$item['name']}' (short {$remaining})."
            );
        }

        /* Mark emptied batches as depleted so they no longer count */
        $conn->query(
            "UPDATE product_batches
             SET status = 'depleted'
             WHERE product_id = {$itemIdInt}
               AND status = 'active'
               AND $stockColumn <= 0"
        );
    }

    /* ── Commit — record + all deductions persist together ────────── */
    $conn->commit();

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

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ]);
    exit;
}