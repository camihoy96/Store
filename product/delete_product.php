<?php
session_start();
require('dbconn.php');

if (!isset($_SESSION['loggedin'])) {
    header("Location: index.php");
    exit;
}

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = intval($_GET['id']);

    try {
        // Use prepared statement to prevent SQL injection
        $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Product deleted successfully";
        } else {
            $_SESSION['error'] = "Error deleting product: " . $conn->error;
        }
    } catch (mysqli_sql_exception $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
    }
} else {
    $_SESSION['error'] = "Invalid product ID";
}

header("Location: product.php");
exit;
?>