<?php
session_start();
require('dbconn.php');

if (!isset($_SESSION['loggedin'])) {
    header("Location: index.php");
    exit;
}

if (isset($_POST['id'], $_POST['reason'])) {
    $transaction_id = intval($_POST['id']);
    $reason = $conn->real_escape_string(trim($_POST['reason']));
    
    if (empty($reason)) {
        $_SESSION['error_msg'] = "Reason is required for voiding transactions";
        header("Location: transaction.php");
        exit;
    }
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // 1. Get the transaction details
        $stmt = $conn->prepare("SELECT items FROM transactions WHERE id = ?");
        $stmt->bind_param("i", $transaction_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception("Transaction not found");
        }
        
        $transaction = $result->fetch_assoc();
        $items = json_decode($transaction['items'], true);
        
        // 2. Restore product quantities
        foreach ($items as $item) {
            if (isset($item['product_id'])) {
                $product_id = intval($item['product_id']);
                $qty = floatval($item['qty']);
                
                // Determine if it's kg or pieces
                if (isset($item['measurement_type']) && $item['measurement_type'] == 'kg') {
                    $sql = "UPDATE products SET kg = kg + ? WHERE id = ?";
                } else {
                    $sql = "UPDATE products SET pieces = pieces + ? WHERE id = ?";
                }
                
                $update_stmt = $conn->prepare($sql);
                $update_stmt->bind_param("di", $qty, $product_id);
                $update_stmt->execute();
            }
        }
        
        // 3. Mark transaction as voided with reason
        $update_transaction = $conn->prepare("UPDATE transactions SET 
            status = 'voided', 
            voided_by = ?, 
            voided_at = NOW(),
            void_reason = ?
            WHERE id = ?");
        $update_transaction->bind_param("ssi", 
            $_SESSION['username'], 
            $reason,
            $transaction_id);
        $update_transaction->execute();
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success_msg'] = "Transaction #$transaction_id has been voided successfully";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_msg'] = "Error voiding transaction: " . $e->getMessage();
    }
    
    header("Location: transaction.php");
    exit;
}
?>