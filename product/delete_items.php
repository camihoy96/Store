<?php
session_start();
require_once __DIR__ . '/../dbconn.php';

// 1. Check if user is logged in (DO NOT destroy session here)
if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: /../index.php");
    exit();
}
// 2. Get and sanitize inputs
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$table = isset($_GET['table']) ? trim($_GET['table']) : 'products'; // Default to products for backward compatibility
// 3. Security: Whitelist allowed tables to prevent SQL injection
$allowed_tables = ['products', 'reserved_items'];
if (!in_array($table, $allowed_tables)) {
    $_SESSION['swal'] = ['type' => 'error', 'title' => 'Error', 'text' => 'Invalid table specified.'];
    header("Location: item_reserve.php");
    exit();
}
// 4. Determine where to redirect back to based on the table
$redirect_url = ($table === 'reserved_items') ? 'item_reserve.php' : 'inventory.php'; // Change 'inventory.php' to your actual products page if different

if ($id > 0) {
    // Use prepared statement to safely delete the item
    $stmt = $conn->prepare("DELETE FROM `$table` WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['swal'] = ['type' => 'success', 'title' => 'Deleted!', 'text' => 'Item removed successfully.'];
        
        // Optional: If deleting from reserved_items, also delete the associated image file to save space
        if ($table === 'reserved_items') {
            $img_check = $conn->query("SELECT image_path FROM reserved_items WHERE id = $id")->fetch_assoc();
            if (!empty($img_check['image_path']) && file_exists(__DIR__ . '/../' . $img_check['image_path'])) {
                @unlink(__DIR__ . '/../' . $img_check['image_path']);
            }
        }
    } else {
        $_SESSION['swal'] = ['type' => 'error', 'title' => 'Error', 'text' => 'Failed to delete item: ' . $conn->error];
    }
    $stmt->close();
} else {
    $_SESSION['swal'] = ['type' => 'error', 'title' => 'Error', 'text' => 'Invalid Item ID.'];
}

$conn->close();

// 5. Redirect back safely (Session remains completely intact)
header("Location: " . $redirect_url);
exit();
?>