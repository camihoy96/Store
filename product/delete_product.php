<?php
// delete_product.php
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include only the database connection
require_once __DIR__ . '/../dbconn.php';

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: access_denied.php");
    exit();
}

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['swal'] = ['type'=>'error', 'title'=>'Error', 'text'=>'No product ID provided!'];
    header("Location: product.php");
    exit();
}

$id = intval($_GET['id']);

// Get product info for logging and image deletion
$productQuery = "SELECT * FROM products WHERE id = $id";
$productResult = $conn->query($productQuery);

if (!$productResult || $productResult->num_rows === 0) {
    $_SESSION['swal'] = ['type'=>'error', 'title'=>'Error', 'text'=>'Product not found!'];
    header("Location: product.php");
    exit();
}

$product = $productResult->fetch_assoc();

// Start transaction for data integrity
$conn->begin_transaction();

try {
    // Delete from expired_products if the table exists
    $checkTable = $conn->query("SHOW TABLES LIKE 'expired_products'");
    if ($checkTable->num_rows > 0) {
        $conn->query("DELETE FROM expired_products WHERE product_id = $id");
    }
    
    // Delete from restock_requests if the table exists
    $checkTable = $conn->query("SHOW TABLES LIKE 'restock_requests'");
    if ($checkTable->num_rows > 0) {
        $conn->query("DELETE FROM restock_requests WHERE product_id = $id");
    }
    
    // DON'T INSERT into edit_deletion_log - it has FK constraint issues
    // Instead, just log to a simple file or skip this part
    // If you need to log deletions, create a separate table without FK constraints
    
    // Delete inventory audit records if table exists
    $checkTable = $conn->query("SHOW TABLES LIKE 'inventory_audit'");
    if ($checkTable->num_rows > 0) {
        $conn->query("DELETE FROM inventory_audit WHERE product_id = $id");
    }
    
    // Delete product batches
    $conn->query("DELETE FROM product_batches WHERE product_id = $id");
    
    // Delete the product
    $deleteResult = $conn->query("DELETE FROM products WHERE id = $id");
    
    if (!$deleteResult) {
        throw new Exception($conn->error);
    }
    
    // Commit transaction
    $conn->commit();
    
    // Delete image file if exists (after successful DB deletion)
    if (!empty($product['image_path'])) {
        $imagePath = __DIR__ . '/../' . $product['image_path'];
        if (file_exists($imagePath)) {
            @unlink($imagePath);
        }
    }
    
    $_SESSION['swal'] = [
        'type' => 'success', 
        'title' => 'Deleted!', 
        'text' => 'Product "' . htmlspecialchars($product['name']) . '" deleted successfully.'
    ];
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    
    $_SESSION['swal'] = [
        'type' => 'error', 
        'title' => 'Error', 
        'text' => 'Failed to delete product: ' . $e->getMessage()
    ];
}

// Close connection
$conn->close();

// Redirect back to products page
header("Location: product.php");
exit();
?>