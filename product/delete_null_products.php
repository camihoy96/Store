<?php
require('dbconn.php');

// Start transaction for safety
$conn->begin_transaction();

try {
    // First count the records to be deleted
    $count = $conn->query("SELECT COUNT(*) FROM products 
                          WHERE name IS NULL 
                          OR category IS NULL 
                          OR (pieces IS NULL AND kg IS NULL)")->fetch_row()[0];
    
    echo "Found $count products with null values to delete<br>";
    
    if ($count > 0) {
        // Delete the records
        $conn->query("DELETE FROM products 
                     WHERE name IS NULL 
                     OR category IS NULL 
                     OR (pieces IS NULL AND kg IS NULL)");
        
        echo "Deleted $count products with null values<br>";
        $conn->commit();
    } else {
        echo "No products with null values found<br>";
    }
} catch (Exception $e) {
    $conn->rollback();
    die("Error: " . $e->getMessage());
}

$conn->close();
?>