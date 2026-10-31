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
    echo json_encode(['error' => 'No ID provided']);
    exit();
}

$id = intval($_GET['id']);

// 1. Determine which table to query (default to 'products' for backward compatibility)
$table = isset($_GET['table']) ? trim($_GET['table']) : 'products';

// 2. SECURITY: Whitelist allowed tables to prevent SQL injection
$allowed_tables = ['products', 'reserved_items'];
if (!in_array($table, $allowed_tables)) {
    echo json_encode(['error' => 'Invalid table specified.']);
    exit();
}

// 3. Get item details dynamically based on the table
$productQuery = "SELECT * FROM `$table` WHERE id = $id";
$productResult = $conn->query($productQuery);

if (!$productResult || $productResult->num_rows === 0) {
    echo json_encode(['error' => "Item not found with ID: $id in table: $table"]);
    exit();
}

$product = $productResult->fetch_assoc();

// Add 'quantity' field for compatibility (map from 'pieces' if old schema)
if (!isset($product['quantity']) && isset($product['pieces'])) {
    $product['quantity'] = $product['pieces'];
}

// 4. Only fetch batches if we are querying the main 'products' table
if ($table === 'products') {
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
} else {
    // For reserved_items, provide safe defaults so the JS doesn't break
    $product['batches'] = [];
    $product['total_quantity'] = $product['quantity'] ?? 0;
    $product['total_kg'] = (isset($product['measurement_type']) && $product['measurement_type'] === 'kg') ? (float)$product['quantity'] : 0;
}

// Close connection
$conn->close();

echo json_encode($product);
exit();
?>