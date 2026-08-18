<?php
session_start();
require('dbconn.php');

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

try {
    // Get and validate JSON input
    $json = file_get_contents('php://input');
    if (empty($json)) {
        throw new Exception('No input data received');
    }

    $input = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }

    // Validate required fields
    $transactionId = $input['transaction_id'] ?? null;
    $actionType = $input['action_type'] ?? null;
    $items = $input['items'] ?? [];
    $reason = trim($input['reason'] ?? '');
    $originalItems = $input['original_items'] ?? '[]';

    if (!$transactionId || !is_numeric($transactionId)) {
        throw new Exception('Invalid Transaction ID');
    }
    
    if (empty($reason)) {
        throw new Exception('Reason is required');
    }

    // Start transaction
    $conn->begin_transaction();

    if ($actionType === 'void_all') {
        // Void entire transaction
        $stmt = $conn->prepare("UPDATE transactions SET 
            status = 'voided', 
            voided_by = ?, 
            voided_at = NOW(), 
            void_reason = ?,
            original_items = ?,
            edit_remarks = CONCAT(IFNULL(edit_remarks, ''), ?)
            WHERE id = ?");
            
        $voidRemark = "[" . date('Y-m-d H:i:s') . "] Entire transaction voided. Reason: " . $reason . "\n";
        $stmt->bind_param("ssssi", 
            $_SESSION['username'], 
            $reason,
            $originalItems,
            $voidRemark,
            $transactionId
        );

} else {
    // First, get the current items from database to use as original_items
    $stmt = $conn->prepare("SELECT items FROM transactions WHERE id = ?");
    $stmt->bind_param("i", $transactionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $currentTransaction = $result->fetch_assoc();
    
    // Use current items as original_items if they exist
    $originalItems = $currentTransaction['items'] ?? '[]';
    
    // Rest of your existing edit processing code...
    $updatedItems = [];
    $editRemarks = ["[" . date('Y-m-d H:i:s') . "] Edit reason: " . $reason . "\n"];
        $newTotal = 0;
        $hasEdits = false;
        
        foreach ($items as $item) {
            $itemId = $item['id'] ?? null;
            $itemName = $item['name'] ?? 'unknown item';
            $action = $item['action'] ?? '';
            
            if ($action === 'void') {
                // Handle voided items
                $updatedItems[] = [
                    'id' => $itemId,
                    'name' => $itemName,
                    'qty' => 0,
                    'price' => 0,
                    'unit' => $item['unit'] ?? '',
                    'status' => 'voided',
                    'measurement_type' => isset($item['unit']) && strpos($item['unit'], 'kg') !== false ? 'kg' : 'pieces'
                ];
                $editRemarks[] = "[" . date('Y-m-d H:i:s') . "] Voided item: $itemName\n";
                continue;
            }
            
            // Process edited/kept items
            $qty = floatval($item['qty'] ?? 0);
            $price = floatval($item['price'] ?? 0);
            $newTotal += $qty * $price;
            
            $updatedItem = [
                'id' => $itemId,
                'name' => $itemName,
                'qty' => $qty,
                'price' => $price,
                'unit' => $item['unit'] ?? '',
                'status' => '',
                'measurement_type' => isset($item['unit']) && strpos($item['unit'], 'kg') !== false ? 'kg' : 'pieces'
            ];
            
            if ($action === 'edit') {
                $hasEdits = true;
                $editRemarks[] = "[" . date('Y-m-d H:i:s') . "] Edited item: $itemName\n";
            }
            
            $updatedItems[] = $updatedItem;
        }
        
        // Update transaction with edit info
        $stmt = $conn->prepare("UPDATE transactions SET 
            items = ?,
            original_items = ?,
            total = ?,
            paid = GREATEST(paid, ?),
            change_due = GREATEST(paid, ?) - ?,
            edit_remarks = CONCAT(IFNULL(edit_remarks, ''), ?),
            edited_by = ?,
            edited_at = NOW(),
            status = ?
            WHERE id = ?");
            
        $itemsJson = json_encode($updatedItems);
        $editRemark = implode("", $editRemarks);
        $username = $_SESSION['username'];
        $status = $hasEdits ? 'edited' : 'completed';
        
        $stmt->bind_param("ssddddsssi", 
            $itemsJson, 
            $originalItems,
            $newTotal, 
            $newTotal, 
            $newTotal, 
            $newTotal,
            $editRemark,
            $username,
            $status,
            $transactionId
        );
    }
    
    if (!$stmt->execute()) {
        throw new Exception('Database update failed: ' . $stmt->error);
    }
    
    $conn->commit();
    $response['success'] = true;
    $response['message'] = 'Action completed successfully';
    
} catch (Exception $e) {
    $conn->rollback();
    $response['message'] = $e->getMessage();
    http_response_code(400); // Bad request
}

echo json_encode($response);
?>