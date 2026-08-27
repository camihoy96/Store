<?php
// get_batch.php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../dbconn.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

header('Content-Type: application/json');

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['error' => 'No batch ID provided']);
    exit();
}

$id = intval($_GET['id']);

$batchQuery = "SELECT * FROM product_batches WHERE id = $id";
$batchResult = $conn->query($batchQuery);

if (!$batchResult || $batchResult->num_rows === 0) {
    echo json_encode(['error' => 'Batch not found']);
    exit();
}

$batch = $batchResult->fetch_assoc();

// Also get product image if batch doesn't have one
if (empty($batch['image_path'])) {
    $productQuery = "SELECT image_path FROM products WHERE id = " . $batch['product_id'];
    $productResult = $conn->query($productQuery);
    if ($productResult && $productResult->num_rows > 0) {
        $product = $productResult->fetch_assoc();
        $batch['image_path'] = $product['image_path'];
    }
}

$conn->close();

echo json_encode($batch);
exit();
?>