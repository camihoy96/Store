<?php
// get_product.php
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include only the database connection
require_once __DIR__ . '/../dbconn.php';

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Set JSON header
header('Content-Type: application/json');

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['error' => 'No product ID provided']);
    exit();
}

$id = intval($_GET['id']);

// Get product details
$productQuery = "SELECT * FROM products WHERE id = $id";
$productResult = $conn->query($productQuery);

if (!$productResult || $productResult->num_rows === 0) {
    echo json_encode(['error' => 'Product not found with ID: ' . $id]);
    exit();
}

$product = $productResult->fetch_assoc();

// Add 'quantity' field for compatibility (map from 'pieces')
if (!isset($product['quantity']) && isset($product['pieces'])) {
    $product['quantity'] = $product['pieces'];
}

// Get active batches for this product
$batchesQuery = "SELECT * FROM product_batches WHERE product_id = $id AND status = 'active' ORDER BY id DESC";
$batchesResult = $conn->query($batchesQuery);
$batches = [];

if ($batchesResult) {
    while ($batch = $batchesResult->fetch_assoc()) {
        $batches[] = $batch;
    }
}

// Calculate total stock from batches
$total_quantity = 0;
$total_kg = 0;
foreach ($batches as $batch) {
    $total_quantity += (int)$batch['quantity'];
    $total_kg += (float)$batch['kg'];
}

// Add batches and totals to product data
$product['batches'] = $batches;
$product['total_quantity'] = $total_quantity;
$product['total_kg'] = $total_kg;

// Close connection
$conn->close();

echo json_encode($product);
exit();
?>