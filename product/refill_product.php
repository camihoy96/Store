<?php
session_start();
require('dbconn.php');

if (!isset($_POST['product_id']) || !isset($_POST['action'])) {
    $_SESSION['error'] = "Invalid request";
    header("Location: product.php");
    exit;
}

$product_id = intval($_POST['product_id']);
$action = $_POST['action'];
$measurement_type = $_POST['measurement_type'];

if ($measurement_type === 'kg') {
    $quantity = floatval($_POST['kg']);
    $column = 'kg';
} else {
    $quantity = intval($_POST['pieces']);
    $column = 'pieces';
}

// Get current quantity
$result = $conn->query("SELECT $column FROM products WHERE id = $product_id");
$current = $result->fetch_assoc()[$column];

// Calculate new quantity
if ($action === 'increase') {
    $new_quantity = $current + $quantity;
} else {
    $new_quantity = max(0, $current - $quantity);
}

// Update database
$update = $conn->query("UPDATE products SET $column = $new_quantity WHERE id = $product_id");

if ($update) {
    $_SESSION['success'] = "Product quantity updated successfully!";
} else {
    $_SESSION['error'] = "Error updating product: " . $conn->error;
}

header("Location: product.php");
exit;
?>