<?php
// delete_bread.php - Backend handler for bread deletion
session_start();
require('dbconn.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bread_id'])) {
    $bread_id = intval($_POST['bread_id']);
    
    $stmt = $conn->prepare("DELETE FROM breads WHERE id = ?");
    $stmt->bind_param("i", $bread_id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $response = [
                'status' => 'success',
                'message' => 'Bread deleted successfully'
            ];
        } else {
            $response = [
                'status' => 'error',
                'message' => 'No bread found with that ID'
            ];
        }
    } else {
        $response = [
            'status' => 'error',
            'message' => 'Error deleting bread: ' . $conn->error
        ];
    }
    
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}
?>