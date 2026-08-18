<?php
session_start();
require('dbconn.php');

if (!isset($_SESSION['loggedin'])) {
    header("Location: index.php");
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$transactionId = intval($_GET['id']);
$query = "DELETE FROM transactions WHERE id = ? AND status = 'voided'";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $transactionId);
$success = $stmt->execute();

echo json_encode([
    'success' => $success,
    'message' => $success ? 'Transaction deleted' : 'Error deleting transaction'
]);
?>