<?php
session_start();
require('dbconn.php');

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

try {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    // Validate input
    if (empty($data['transaction_id']) || empty($data['original_items'])) {
        throw new Exception('Missing required parameters');
    }
    
    $transactionId = (int)$data['transaction_id'];
    $originalItems = $data['original_items'];
    $username = $_SESSION['username'] ?? 'System';
    
    // Verify transaction exists
    $stmt = $conn->prepare("SELECT id, items FROM transactions WHERE id = ?");
    $stmt->bind_param("i", $transactionId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Transaction not found');
    }
    
    $transaction = $result->fetch_assoc();
    $currentItems = $transaction['items'];
    
    // Begin transaction
    $conn->begin_transaction();
    
    // Update transaction to revert changes
    $stmt = $conn->prepare("UPDATE transactions SET 
        items = ?,
        original_items = '[]',
        status = 'completed',
        edited_by = NULL,
        edited_at = NULL,
        edit_remarks = CONCAT(IFNULL(edit_remarks, ''), ?)
        WHERE id = ?");
    
    $remark = "[" . date('Y-m-d H:i:s') . "] Reverted to original state by $username. ";
    $remark .= "Previous items: " . substr($currentItems, 0, 100) . "...\n";
    
    $stmt->bind_param("ssi", $originalItems, $remark, $transactionId);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to update transaction: ' . $stmt->error);
    }
    
    $conn->commit();
    $response['success'] = true;
    $response['message'] = 'Transaction successfully reverted to original state';
    
} catch (Exception $e) {
    $conn->rollback();
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>