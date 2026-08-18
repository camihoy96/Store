<?php
session_start();
require('dbconn.php');

if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: access_denied.php");
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    header("Location: record.php");
    exit;
}

$voided_by = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Admin';

// Update transaction status to voided
$stmt = $conn->prepare("UPDATE transactions SET 
    status = 'voided', 
    voided_by = ?, 
    voided_at = NOW() 
    WHERE id = ? AND (status IS NULL OR status != 'voided')");
$stmt->bind_param("si", $voided_by, $id);

if ($stmt->execute()) {
    $_SESSION['success_msg'] = "Transaction #$id has been voided.";
} else {
    $_SESSION['error_msg'] = "Failed to void transaction.";
}

header("Location: record.php");
exit;
?>