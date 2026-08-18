<?php
session_start();
require('dbconn.php');

// Check admin access
if (!isset($_SESSION['loggedin'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id = $_POST['product_id'];
    $pieces = (int)$_POST['pieces'];
    $action = $_POST['action']; // 'increase' or 'decrease'

    // Validate pieces is positive
    if ($pieces <= 0) {
        $_SESSION['error'] = "Quantity must be a positive number";
        header("Location: item_reserve.php");
        exit;
    }

    if ($action === 'increase') {
        $sql = "UPDATE products SET pieces = pieces + ? WHERE id = ?";
        $success_msg = "Increased quantity by $pieces. New quantity: ";
    } else {
        $sql = "UPDATE reserved_items SET pieces = GREATEST(0, pieces - ?) WHERE id = ?";
        $success_msg = "Decreased quantity by $pieces. New quantity: ";
    }

    // First update the quantity
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $pieces, $id);

    if ($stmt->execute()) {
        // Then get the updated quantity with a separate query
        $get_qty = $conn->prepare("SELECT pieces FROM reserved_items WHERE id = ?");
        $get_qty->bind_param("i", $id);
        $get_qty->execute();
        $result = $get_qty->get_result();
        $row = $result->fetch_assoc();
        $newQuantity = $row['pieces'];
        
        $_SESSION['success'] = $success_msg . $newQuantity;
    } else {
        $_SESSION['error'] = "Error updating quantity: " . htmlspecialchars($stmt->error);
    }
    
    header("Location: item_reserve.php");
    exit;
}
?>