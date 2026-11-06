<?php
session_start();
require('dbconn.php');

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];
$inTransaction = false;

try {
    $json = file_get_contents('php://input');
    if (empty($json)) throw new Exception('No input data received');

    $input = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('Invalid JSON input');

    $transactionId = $input['transaction_id'] ?? null;
    $actionType    = $input['action_type'] ?? null;
    $items         = $input['items'] ?? [];
    $reason        = trim($input['reason'] ?? '');
    $originalItems = $input['original_items'] ?? '[]';

    if (!$transactionId || !is_numeric($transactionId)) throw new Exception('Invalid Transaction ID');
    if (empty($reason)) throw new Exception('Reason is required');

    $username = $_SESSION['username'] ?? 'Unknown';

    $conn->begin_transaction();
    $inTransaction = true;

    // Fetch current transaction
    $fetch = $conn->prepare("SELECT items, paid FROM transactions WHERE id = ?");
    $fetch->bind_param("i", $transactionId);
    $fetch->execute();
    $currentTransaction = $fetch->get_result()->fetch_assoc();
    if (!$currentTransaction) throw new Exception('Transaction not found');

    $currentItems = json_decode($currentTransaction['items'] ?? '[]', true) ?: [];
    $originalPaid = (float)($currentTransaction['paid'] ?? 0);

    /* ════════════════════════════════════════════════════════════
       RESTORE STOCK — adds qty back to product_batches
       Matches schema: code NOT NULL, name NOT NULL, status enum
       ════════════════════════════════════════════════════════════ */
    function restoreStock($conn, $item) {
        $itemId = $item['id'] ?? null;
        if (!$itemId || str_starts_with((string)$itemId, 'custom-')) return false;

        $qty = (float)($item['qty'] ?? 0);
        if ($qty <= 0) return false;

        $unitType  = $item['measurement_type'] ?? 'pieces';
        $stockCol  = ($unitType === 'kg') ? 'kg' : 'quantity';
        $initCol   = ($unitType === 'kg') ? 'initial_kg' : 'initial_quantity';
        $itemIdInt = (int)$itemId;

        // Find the most recent batch for this product (any status)
        $stmt = $conn->prepare("
            SELECT id FROM product_batches
            WHERE product_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->bind_param('i', $itemIdInt);
        $stmt->execute();
        $batch = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($batch) {
            // Append qty back to existing batch and re-activate it
            $upd = $conn->prepare("
                UPDATE product_batches
                SET $stockCol = $stockCol + ?,
                    status = 'active'
                WHERE id = ?
            ");
            $batchId = (int)$batch['id'];
            $upd->bind_param('di', $qty, $batchId);
            if (!$upd->execute()) throw new Exception('Restore update failed: ' . $upd->error);
            $upd->close();
            return true;
        }

        // No batch exists → create a new one, copying metadata from products
        $prodQ = $conn->prepare("
            SELECT code, name, category, brand, seller_store,
                   price, purchase_price, measurement_type, image_path,
                   purchase_date, expiry_date
            FROM products WHERE id = ?
        ");
        $prodQ->bind_param('i', $itemIdInt);
        $prodQ->execute();
        $prod = $prodQ->get_result()->fetch_assoc();
        $prodQ->close();

        if (!$prod) return false; // Product no longer exists — skip

        $code   = $prod['code'] ?? ('P' . $itemIdInt);
        $name   = $prod['name'] ?? ($item['name'] ?? 'Unknown');
        $cat    = $prod['category'] ?? null;
        $brand  = $prod['brand'] ?? null;
        $store  = $prod['seller_store'] ?? null;
        $price  = (float)($prod['price'] ?? 0);
        $pprice = (float)($prod['purchase_price'] ?? 0);
        $mtype  = $prod['measurement_type'] ?? $unitType;
        $img    = $prod['image_path'] ?? null;
        $pur    = $prod['purchase_date'] ?? null;
        $exp    = $prod['expiry_date'] ?? null;

        // Prepare quantity / kg depending on unit
        $qtyInt    = ($unitType === 'kg') ? 0     : (int)$qty;
        $initQty   = ($unitType === 'kg') ? 0.00  : (float)$qty;
        $kgVal     = ($unitType === 'kg') ? $qty  : 0.00;
        $initKg    = ($unitType === 'kg') ? $qty  : 0.00;

        $ins = $conn->prepare("
            INSERT INTO product_batches
                (product_id, code, name, category, brand, seller_store,
                 purchase_price, price,
                 quantity, initial_quantity,
                 kg, initial_kg,
                 measurement_type, purchase_date, expiry_date, image_path, status)
            VALUES (?, ?, ?, ?, ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?, ?, ?, 'active')
        ");
        if (!$ins) throw new Exception('Prepare batch insert failed: ' . $conn->error);

        // type string: i s s s s s d d i d d d s s s s
        //              1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16
        $ins->bind_param(
            'isssssddidddssss',
            $itemIdInt, $code, $name, $cat, $brand, $store,
            $pprice, $price,
            $qtyInt, $initQty,
            $kgVal, $initKg,
            $mtype, $pur, $exp, $img
        );
        if (!$ins->execute()) throw new Exception('Batch insert failed: ' . $ins->error);
        $ins->close();
        return true;
    }

    if ($actionType === 'void_all') {
        // ─── VOID ENTIRE TRANSACTION ───
        // Restore stock for every item that isn't already voided
        foreach ($currentItems as $item) {
            if (($item['status'] ?? '') === 'voided') continue;
            restoreStock($conn, $item);
        }

        $stmt = $conn->prepare("UPDATE transactions SET
            status = 'voided',
            voided_by = ?,
            voided_at = NOW(),
            void_reason = ?,
            original_items = ?,
            edit_remarks = CONCAT(IFNULL(edit_remarks, ''), ?)
            WHERE id = ?");
        if (!$stmt) throw new Exception('Prepare (void) failed: ' . $conn->error);

        $voidRemark = "[" . date('Y-m-d H:i:s') . "] Entire transaction voided. Reason: " . $reason . "\n";
        $stmt->bind_param("ssssi", $username, $reason, $originalItems, $voidRemark, $transactionId);
        if (!$stmt->execute()) throw new Exception('Void execute failed: ' . $stmt->error);

    } else {
        // ─── EDIT TRANSACTION ───
        $updatedItems = [];
        $editRemarks  = ["[" . date('Y-m-d H:i:s') . "] Edit reason: " . $reason . "\n"];
        $newTotal     = 0;
        $hasEdits     = false;

        foreach ($items as $idx => $newItem) {
            $action  = $newItem['action'] ?? '';
            $oldItem = $currentItems[$idx] ?? [];

            if ($action === 'void') {
                // Restore FULL quantity of this item
                restoreStock($conn, $oldItem);
                $updatedItems[] = [
                    'id'               => $newItem['id'] ?? '',
                    'name'             => $newItem['name'] ?? '',
                    'qty'              => 0,
                    'price'            => 0,
                    'unit'             => $newItem['unit'] ?? '',
                    'status'           => 'voided',
                    'measurement_type' => (strpos($newItem['unit'] ?? '', 'kg') !== false) ? 'kg' : 'pieces'
                ];
                $editRemarks[] = "[" . date('Y-m-d H:i:s') . "] Voided item: " . ($newItem['name'] ?? '') . "\n";
                continue;
            }

            $newQty = (float)($newItem['qty'] ?? 0);
            $oldQty = (float)($oldItem['qty'] ?? 0);
            $price  = (float)($newItem['price'] ?? 0);
            $newTotal += $newQty * $price;

            // Restore only the difference if quantity was reduced
            if ($newQty < $oldQty) {
                $diffItem = $oldItem;
                $diffItem['qty'] = $oldQty - $newQty;
                restoreStock($conn, $diffItem);
            }

            $updatedItems[] = [
                'id'               => $newItem['id'] ?? '',
                'name'             => $newItem['name'] ?? '',
                'qty'              => $newQty,
                'price'            => $price,
                'unit'             => $newItem['unit'] ?? '',
                'status'           => '',
                'measurement_type' => (strpos($newItem['unit'] ?? '', 'kg') !== false) ? 'kg' : 'pieces'
            ];

            if ($action === 'edit') {
                $hasEdits = true;
                $editRemarks[] = "[" . date('Y-m-d H:i:s') . "] Edited item: " . ($newItem['name'] ?? '') . "\n";
            }
        }

        $itemsJson  = json_encode($updatedItems);
        $editRemark = implode("", $editRemarks);
        $status     = $hasEdits ? 'edited' : 'completed';

        $stmt = $conn->prepare("UPDATE transactions SET
            items = ?, original_items = ?, total = ?, paid = GREATEST(paid, ?),
            change_due = GREATEST(paid, ?) - ?, edit_remarks = CONCAT(IFNULL(edit_remarks, ''), ?),
            edited_by = ?, edited_at = NOW(), status = ? WHERE id = ?");
        if (!$stmt) throw new Exception('Prepare (edit) failed: ' . $conn->error);

        $stmt->bind_param("ssddddsssi",
            $itemsJson, $originalItems, $newTotal,
            $originalPaid, $originalPaid, $newTotal,
            $editRemark, $username, $status, $transactionId
        );
        if (!$stmt->execute()) throw new Exception('Edit execute failed: ' . $stmt->error);
    }

    $conn->commit();
    $response['success'] = true;
    $response['message'] = 'Action completed successfully';

} catch (Throwable $e) {
    if ($inTransaction) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
    }
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>