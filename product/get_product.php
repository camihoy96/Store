<?php
require('dbconn.php');
header("Content-Type: application/json");

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['error' => 'Invalid ID']);
    exit;
}

$id = intval($_GET['id']);
$result = $conn->query("SELECT * FROM products WHERE id = $id LIMIT 1");

if ($result->num_rows === 0) {
    echo json_encode(['error' => 'Product not found']);
    exit;
}

$product = $result->fetch_assoc();

// Handle expiry date properly
if (empty($product['expiry_date']) || 
    $product['expiry_date'] == '0000-00-00' || 
    $product['expiry_date'] == 'N/A') {
    $product['expiry_date'] = null;
}

// Handle kg/pieces values
if ($product['measurement_type'] === 'kg') {
    $product['kg'] = floatval($product['kg']);
    $product['pieces'] = 0;
} else {
    $product['pieces'] = intval($product['pieces']);
    $product['kg'] = 0.00;
}

echo json_encode($product);
exit;