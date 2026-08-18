<?php
session_start();
require('dbconn.php');

if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: access_denied.php");
    exit();
}

$product_id = intval($_POST['product_id']);
$adjustment = floatval($_POST['quantity']);
$unit = $conn->real_escape_string($_POST['unit']);
$action = $_POST['action'];

// Get current quantity
$result = $conn->query("SELECT quantity FROM reserved_items WHERE id = $product_id");
$row = $result->fetch_assoc();
$current_quantity = $row['quantity'];

// Calculate new quantity
if ($action === 'increase') {
    $new_quantity = $current_quantity + $adjustment;
} else {
    $new_quantity = $current_quantity - $adjustment;
    if ($new_quantity < 0) $new_quantity = 0;
}

// Update database
$conn->query("UPDATE reserved_items SET quantity = $new_quantity WHERE id = $product_id");

// Redirect back with success message
$_SESSION['success'] = "Quantity updated successfully! New quantity: $new_quantity $unit";
header("Location: item_reserve.php");
exit;
?>