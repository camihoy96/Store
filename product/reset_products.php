<?php
// reset_products.php
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

// Display confirmation page
if (!isset($_POST['confirm_reset'])) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Reset Products Database</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                background: #f5f5f5;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
                margin: 0;
            }
            .container {
                background: white;
                padding: 40px;
                border-radius: 10px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.1);
                max-width: 500px;
                text-align: center;
            }
            h1 {
                color: #d32f2f;
                margin-bottom: 20px;
            }
            .warning {
                background: #fff3e0;
                border: 1px solid #ff9800;
                padding: 15px;
                border-radius: 5px;
                margin: 20px 0;
                color: #e65100;
            }
            .btn {
                display: inline-block;
                padding: 12px 30px;
                margin: 10px;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                font-size: 16px;
                font-weight: bold;
                text-decoration: none;
            }
            .btn-danger {
                background: #d32f2f;
                color: white;
            }
            .btn-danger:hover {
                background: #b71c1c;
            }
            .btn-cancel {
                background: #9e9e9e;
                color: white;
            }
            .btn-cancel:hover {
                background: #757575;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>⚠️ WARNING: Reset Products Database</h1>
            <div class="warning">
                <strong>This action will delete:</strong><br>
                • All products<br>
                • All product batches<br>
                • All expired products<br>
                • All restock requests<br>
                • All inventory audit records<br>
                <br>
                <strong>This action CANNOT be undone!</strong>
            </div>
            <form method="POST">
                <input type="hidden" name="confirm_reset" value="1">
                <button type="submit" class="btn btn-danger">Yes, Reset Everything</button>
                <a href="product.php" class="btn btn-cancel">Cancel</a>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// If confirmed, perform the reset
if (isset($_POST['confirm_reset'])) {
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Delete in correct order to respect foreign keys
        $tables = [
            'inventory_audit',
            'expired_products',
            'restock_requests',
            'edit_deletion_log',
            'product_batches',
            'products'
        ];
        
        $results = [];
        foreach ($tables as $table) {
            // Check if table exists
            $checkTable = $conn->query("SHOW TABLES LIKE '$table'");
            if ($checkTable->num_rows > 0) {
                $deleteResult = $conn->query("DELETE FROM $table");
                if ($deleteResult) {
                    $results[$table] = $conn->affected_rows . " rows deleted";
                } else {
                    $results[$table] = "Error: " . $conn->error;
                }
            } else {
                $results[$table] = "Table doesn't exist";
            }
        }
        
        // Reset auto-increment
        $conn->query("ALTER TABLE products AUTO_INCREMENT = 1");
        $conn->query("ALTER TABLE product_batches AUTO_INCREMENT = 1");
        $conn->query("ALTER TABLE expired_products AUTO_INCREMENT = 1");
        $conn->query("ALTER TABLE restock_requests AUTO_INCREMENT = 1");
        $conn->query("ALTER TABLE inventory_audit AUTO_INCREMENT = 1");
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['swal'] = [
            'type' => 'success',
            'title' => 'Database Reset!',
            'text' => 'All products and related data have been cleared successfully.'
        ];
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        
        $_SESSION['swal'] = [
            'type' => 'error',
            'title' => 'Reset Failed',
            'text' => 'Error: ' . $e->getMessage()
        ];
    }
    
    $conn->close();
    
    // Redirect back to products page
    header("Location: product.php");
    exit();
}
?>