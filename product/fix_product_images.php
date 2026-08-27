<?php
$pageTitle = 'Database Migration - Add Images';
require_once __DIR__ . '/../include/admin_header.php';

// Add image_path column if it doesn't exist
$check_column = $conn->query("SHOW COLUMNS FROM product_batches LIKE 'image_path'");
if ($check_column->num_rows == 0) {
    $conn->query("ALTER TABLE product_batches ADD COLUMN image_path VARCHAR(255) NULL DEFAULT NULL");
    echo "<div style='color:#4dff88;'>✅ Added image_path column to product_batches table</div>";
} else {
    echo "<div style='color:#ffcc66;'>⏭️ image_path column already exists</div>";
}

// Update existing batches with images from products
$update_batches = $conn->query("
    UPDATE product_batches b
    INNER JOIN products p ON b.product_id = p.id
    SET b.image_path = p.image_path
    WHERE b.image_path IS NULL AND p.image_path IS NOT NULL
");

$affected = $conn->affected_rows;
echo "<div style='color:#4dff88;'>✅ Updated $affected batches with product images</div>";

// Verify the update
$verify = $conn->query("
    SELECT COUNT(*) as cnt 
    FROM product_batches b
    INNER JOIN products p ON b.product_id = p.id
    WHERE p.image_path IS NOT NULL AND b.image_path IS NULL
");
$remaining = $verify->fetch_assoc()['cnt'];

if ($remaining > 0) {
    echo "<div style='color:#ffcc66;'>⚠️ $remaining products still need image migration</div>";
} else {
    echo "<div style='color:#4dff88;'>✅ All images have been migrated successfully!</div>";
}

echo "<div style='margin-top:20px;text-align:center;'>";
echo "<a href='product.php' class='btn btn-orange'>📦 Go to Products</a>";
echo "</div>";

require_once __DIR__ . '/../include/admin_footer.php';
?>