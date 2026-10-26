<?php
// delete_expired.php - Updated with correct path
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the correct database connection file
// The file is dbconn.php in the root Store folder
require_once __DIR__ . '/../dbconn.php';

session_start();

// Handle single delete
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    // Check if record exists
    $check = $conn->query("SELECT id FROM expired_products WHERE id = $id");
    if ($check->num_rows === 0) {
        $_SESSION['swal'] = ['type' => 'error', 'title' => 'Not Found', 'text' => 'Expired record not found.'];
        header("Location: product.php?view=expired");
        exit;
    }
    
    // Delete the record
    $sql = "DELETE FROM expired_products WHERE id = $id";
    
    if ($conn->query($sql)) {
        $_SESSION['swal'] = ['type' => 'success', 'title' => 'Deleted!', 'text' => 'Expired record deleted successfully.'];
    } else {
        $_SESSION['swal'] = ['type' => 'error', 'title' => 'Error', 'text' => $conn->error];
    }
    
    header("Location: product.php?view=expired");
    exit;
}

// Handle bulk delete (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_expired'])) {
    header('Content-Type: application/json');
    
    $ids = json_decode($_POST['ids'], true);
    
    if (!is_array($ids) || empty($ids)) {
        echo json_encode(['success' => false, 'message' => 'No IDs provided']);
        exit;
    }
    
    // Sanitize IDs
    $ids = array_map('intval', $ids);
    $idsString = implode(',', $ids);
    
    $sql = "DELETE FROM expired_products WHERE id IN ($idsString)";
    $result = $conn->query($sql);
    
    if ($result) {
        echo json_encode(['success' => true, 'deleted' => count($ids)]);
    } else {
        echo json_encode(['success' => false, 'message' => $conn->error]);
    }
    exit;
}

// If no action
header("Location: product.php");
exit;
?>