<?php
$pageTitle = 'Database Migration';
require_once __DIR__ . '/../include/admin_header.php';

// Check if migration has already been done
$check = $conn->query("SELECT COUNT(*) as cnt FROM product_batches");
$count = $check->fetch_assoc()['cnt'];

// Check how many products exist
$productCount = $conn->query("SELECT COUNT(*) as cnt FROM products")->fetch_assoc()['cnt'];

if ($count > 0 && $count >= $productCount) {
    echo "<div style='padding:40px;text-align:center;'>";
    echo "<h2>✅ Migration Already Complete!</h2>";
    echo "<p style='color:var(--text2);'>Found $count batches for $productCount products.</p>";
    echo "<p><a href='product.php' class='btn btn-orange' style='margin-top:20px;'>📦 Go to Products</a></p>";
    echo "</div>";
    require_once __DIR__ . '/../include/admin_footer.php';
    exit;
}

// Get all products that don't have batches yet
$products = $conn->query("
    SELECT p.* 
    FROM products p
    LEFT JOIN product_batches b ON p.id = b.product_id
    WHERE b.id IS NULL
");

$migrated = 0;
$errors = [];
$skipped = 0;

echo "<div style='max-width:800px;margin:0 auto;padding:30px;'>";
echo "<h2>📊 Migrating Products to Batches</h2>";
echo "<p style='color:var(--text2);'>This will create batch records for all existing products.</p>";
echo "<hr style='border-color:var(--border);margin:20px 0;'>";

while ($p = $products->fetch_assoc()) {
    // Check if product already has a batch
    $exists = $conn->query("SELECT id FROM product_batches WHERE product_id = {$p['id']} LIMIT 1");
    if ($exists->num_rows > 0) {
        $skipped++;
        continue;
    }
    
    // Prepare values as variables for bind_param with NULL handling
    $product_id = (int)$p['id'];
    
    // Handle NULL or empty code
    $code = !empty($p['code']) ? $p['code'] : 'P' . $p['id'];
    
    $name = !empty($p['name']) ? $p['name'] : 'Product ' . $p['id'];
    $category = !empty($p['category']) ? $p['category'] : 'Uncategorized';
    $brand = !empty($p['brand']) ? $p['brand'] : '';
    $seller = !empty($p['seller_store']) ? $p['seller_store'] : 'Initial Stock';
    $purchase_price = isset($p['purchase_price']) ? (float)$p['purchase_price'] : 0;
    $price = isset($p['price']) ? (float)$p['price'] : 0;
    $quantity = ($p['measurement_type'] === 'pieces') ? (int)$p['pieces'] : 0;
    $kg = ($p['measurement_type'] === 'kg') ? (float)$p['kg'] : 0;
    $measurement_type = !empty($p['measurement_type']) ? $p['measurement_type'] : 'pieces';
    $purchase_date = !empty($p['purchase_date']) && $p['purchase_date'] !== '0000-00-00' ? $p['purchase_date'] : date('Y-m-d');
    
    // Handle expiry date - allow NULL
    $expiry = null;
    if (!empty($p['expiry_date']) && $p['expiry_date'] !== 'N/A' && $p['expiry_date'] !== '0000-00-00') {
        $expiry = $p['expiry_date'];
    }
    
    // Insert each product as a batch
    $stmt = $conn->prepare("
        INSERT INTO product_batches 
        (product_id, code, name, category, brand, seller_store, 
         purchase_price, price, quantity, kg, measurement_type, 
         purchase_date, expiry_date, status, batch_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
    ");
    
    // Use string type for all values in bind_param
    $stmt->bind_param(
        "isssssdddssss",
        $product_id,
        $code,
        $name,
        $category,
        $brand,
        $seller,
        $purchase_price,
        $price,
        $quantity,
        $kg,
        $measurement_type,
        $purchase_date,
        $expiry
    );
    
    if ($stmt->execute()) {
        $migrated++;
        echo "<div style='color:#4dff88;font-size:13px;'>✅ Migrated: {$code} - {$name}</div>";
    } else {
        $errors[] = "Error migrating product ID {$p['id']} (Code: {$code}): " . $stmt->error;
        echo "<div style='color:#ff4444;font-size:13px;'>❌ Error: ID {$p['id']} - " . $stmt->error . "</div>";
    }
    $stmt->close();
}

echo "<hr style='border-color:var(--border);margin:20px 0;'>";
echo "<div style='padding:20px;background:var(--card2);border:1px solid var(--border);border-radius:8px;'>";
echo "<h3>📋 Migration Summary</h3>";
echo "<ul style='list-style:none;padding:0;'>";
echo "<li>✅ Migrated: <strong>$migrated</strong> products</li>";
echo "<li>⏭️ Skipped (already have batches): <strong>$skipped</strong> products</li>";
if (!empty($errors)) {
    echo "<li>❌ Errors: <strong>" . count($errors) . "</strong></li>";
}
echo "</ul>";

if (!empty($errors)) {
    echo "<h4>Error Details:</h4><ul>";
    foreach ($errors as $e) {
        echo "<li style='color:#ff4444;'>$e</li>";
    }
    echo "</ul>";
}

// Verify migration
$newCount = $conn->query("SELECT COUNT(*) as cnt FROM product_batches")->fetch_assoc()['cnt'];
echo "<p style='margin-top:15px;'>Total batches now: <strong>$newCount</strong></p>";
echo "</div>";

// Also check if any products still don't have batches
$remaining = $conn->query("
    SELECT COUNT(*) as cnt 
    FROM products p
    LEFT JOIN product_batches b ON p.id = b.product_id
    WHERE b.id IS NULL
")->fetch_assoc()['cnt'];

if ($remaining > 0) {
    echo "<p style='color:#ff9800;margin-top:10px;'>⚠️ $remaining products still don't have batches. You may need to check for errors above.</p>";
} else {
    echo "<p style='color:#4dff88;margin-top:10px;'>✅ All products have been migrated successfully!</p>";
}

echo "<div style='margin-top:30px;text-align:center;'>";
echo "<a href='product.php' class='btn btn-orange'>📦 Go to Products</a>";
echo "</div>";
echo "</div>";

require_once __DIR__ . '/../include/admin_footer.php';
?>