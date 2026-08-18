<?php
// Enable full error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

require('dbconn.php'); // Ensure this file returns a valid $conn object

header('Content-Type: application/json');

// 1. Verify database connection
if (!$conn || $conn->connect_error) {
    die(json_encode([
        'status' => 'error',
        'message' => 'Database connection failed',
        'error' => $conn->connect_error ?? 'Unknown'
    ]));
}

// 2. Validate session and input
if (!isset($_SESSION['user_id'])) {
    die(json_encode(['status' => 'error', 'message' => 'Session expired']));
}

if (!isset($_POST['id']) || !ctype_digit($_POST['id'])) {
    die(json_encode(['status' => 'error', 'message' => 'Invalid ID']));
}

$record_id = (int)$_POST['id'];

try {
    // 3. Verify the record exists first
    $check = $conn->prepare("SELECT id FROM bread_remain WHERE id = ?");
    $check->bind_param("i", $record_id);
    $check->execute();
    
    if ($check->get_result()->num_rows === 0) {
        die(json_encode(['status' => 'error', 'message' => 'Record not found']));
    }

    // 4. Disable foreign key checks temporarily (if needed)
    $conn->query("SET FOREIGN_KEY_CHECKS=0");
    
    // 5. Execute deletion with verification
    $stmt = $conn->prepare("DELETE FROM bread_remain WHERE id = ?");
    $stmt->bind_param("i", $record_id);
    
    if ($stmt->execute()) {
        // Verify deletion
        $verify = $conn->prepare("SELECT 1 FROM bread_remain WHERE id = ? LIMIT 1");
        $verify->bind_param("i", $record_id);
        $verify->execute();
        
        if ($verify->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'success', 'message' => 'Record deleted']);
        } else {
            throw new Exception("Deletion verification failed");
        }
    } else {
        throw new Exception("Delete query failed: " . $conn->error);
    }
    
    // Re-enable foreign key checks
    $conn->query("SET FOREIGN_KEY_CHECKS=1");
    
} catch (Exception $e) {
    // Log detailed error
    error_log("Deletion Error: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Database operation failed',
        'debug' => $e->getMessage() // Remove in production
    ]);
}

$conn->close();
?>