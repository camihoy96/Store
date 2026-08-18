<?php
session_start();
require('../dbconn.php');

// Only admin can update the key
if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../access_denied.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newKey = trim($_POST['registration_key']);

    if (!empty($newKey)) {
        // Save it in a table or config file
        // Example: save in a `settings` table with key_name = 'registration_key'
        $stmt = $conn->prepare("UPDATE settings SET key_value=? WHERE key_name='registration_key'");
        $stmt->bind_param("s", $newKey);
        if ($stmt->execute()) {
            $_SESSION['success'] = "Registration key updated successfully!";
        } else {
            $_SESSION['error'] = "Failed to update registration key.";
        }
        $stmt->close();
    } else {
        $_SESSION['error'] = "Registration key cannot be empty.";
    }
}

header("Location: prof.php");
exit();
?>
