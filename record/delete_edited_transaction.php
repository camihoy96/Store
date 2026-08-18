<?php
session_start();
require('dbconn.php');

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

try {
    // Get JSON input
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    // Validate input
    if (empty($data['transaction_id']) || empty($data['edit_id'])) {
        throw new Exception('Missing required parameters');
    }
    
    $transactionId = (int)$data['transaction_id'];
    $editId = (int)$data['edit_id'];
    $userId = $_SESSION['user_id'] ?? null;
    
    if (!$userId) {
        throw new Exception('Authentication required');
    }
    
    // Begin transaction
    $conn->begin_transaction();
    
    // 1. First get the original transaction data
    $stmt = $conn->prepare("SELECT items, original_items FROM transactions WHERE id = ?");
    $stmt->bind_param("i", $transactionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $transaction = $result->fetch_assoc();
    
    if (!$transaction) {
        throw new Exception('Transaction not found');
    }
    
    // 2. Restore original items (remove the edit)
    $stmt = $conn->prepare("UPDATE transactions SET 
        items = original_items,
        original_items = '[]',
        edited_by = NULL,
        edited_at = NULL,
        edit_remarks = CONCAT(IFNULL(edit_remarks, ''), ?)
        WHERE id = ?");
    
    $remark = "[" . date('Y-m-d H:i:s') . "] Edit #$editId deleted by {$_SESSION['username']}. ";
    $remark .= "Restored original items.\n";
    
    $stmt->bind_param("si", $remark, $transactionId);
    $stmt->execute();
    
    // 3. Log the deletion
    $stmt = $conn->prepare("INSERT INTO edit_deletion_log 
        (transaction_id, edit_id, deleted_by, deleted_at, reason) 
        VALUES (?, ?, ?, NOW(), 'Manual deletion by user')");
    $stmt->bind_param("iii", $transactionId, $editId, $userId);
    $stmt->execute();
    
    $conn->commit();
    $response['success'] = true;
    $response['message'] = 'Edit successfully deleted and original transaction restored';
    
} catch (Exception $e) {
    $conn->rollback();
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>