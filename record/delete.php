<?php
session_start();
require('dbconn.php');

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = intval($_GET['id']);

    // Optional: Confirm transaction exists
    $check = $conn->query("SELECT * FROM transactions WHERE id = $id");
    if ($check->num_rows > 0) {
        $delete = $conn->query("DELETE FROM transactions WHERE id = $id");

        if ($delete) {
            // Set success message in session
            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => "Transaction ID #$id has been deleted."
            ];
        } else {
            $_SESSION['alert'] = [
                'type' => 'error',
                'message' => "Failed to delete transaction."
            ];
        }
    } else {
        $_SESSION['alert'] = [
            'type' => 'error',
            'message' => "Transaction not found."
        ];
    }
} else {
    $_SESSION['alert'] = [
        'type' => 'error',
        'message' => "Invalid ID."
    ];
}

// Redirect immediately
header("Location: record.php");
exit();
?>