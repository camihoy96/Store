<?php
ob_start();
$pageTitle = 'Products';
require_once __DIR__ . '/../include/admin_header.php';

/* ═══════ AUTO-MOVE EXPIRED BATCHES (RUN ON PAGE LOAD) ════════════════ */
function moveExpiredBatches($conn) {
    $today = date('Y-m-d');
    
    // Find batches expiring today or already expired
    $expiringBatches = $conn->query("
        SELECT * FROM product_batches 
        WHERE status = 'active' 
        AND expiry_date IS NOT NULL 
        AND expiry_date <= '$today'
        AND (quantity > 0 OR kg > 0)
    ");
    
    while ($batch = $expiringBatches->fetch_assoc()) {
        // Move to expired_products
        $stmt = $conn->prepare("
            INSERT INTO expired_products 
            (batch_id, product_id, code, name, category, brand, seller_store, 
             purchase_price, price, quantity, kg, measurement_type, 
             purchase_date, expiry_date, expired_date, days_expired, batch_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $expired_date = $today;
        $days_expired = (strtotime($today) - strtotime($batch['expiry_date'])) / (60*60*24);
        $days_expired = max(0, (int)$days_expired);
        
        $stmt->bind_param(
            "iiissssdddsssssis",
            $batch['id'],
            $batch['product_id'],
            $batch['code'],
            $batch['name'],
            $batch['category'],
            $batch['brand'],
            $batch['seller_store'],
            $batch['purchase_price'],
            $batch['price'],
            $batch['quantity'],
            $batch['kg'],
            $batch['measurement_type'],
            $batch['purchase_date'],
            $batch['expiry_date'],
            $expired_date,
            $days_expired,
            $batch['batch_date']
        );
        $stmt->execute();
        
        // Update batch status to expired
        $conn->query("UPDATE product_batches SET status = 'expired' WHERE id = " . $batch['id']);
        
        // Log audit
        $audit = $conn->prepare("
            INSERT INTO inventory_audit 
            (batch_id, product_id, action, previous_quantity, new_quantity, notes) 
            VALUES (?, ?, 'expired_moved', ?, 0, ?)
        ");
        $qty = $batch['measurement_type'] == 'kg' ? 0 : $batch['quantity'];
        $notes = "Expired batch moved to expired_products. Expiry: {$batch['expiry_date']}";
        $audit->bind_param("iiis", $batch['id'], $batch['product_id'], $qty, $notes);
        $audit->execute();
        
        $stmt->close();
        $audit->close();
    }
}

// Auto-clean soldout batches (after 3 days)
function cleanSoldoutBatches($conn) {
    $threeDaysAgo = date('Y-m-d', strtotime('-3 days'));
    
    // Delete soldout batches older than 3 days
    $conn->query("
        DELETE FROM product_batches 
        WHERE status = 'soldout' 
        AND batch_date <= '$threeDaysAgo'
        AND quantity = 0 
        AND kg = 0
    ");
}

// Run maintenance functions
moveExpiredBatches($conn);
cleanSoldoutBatches($conn);

/* ═══════ HANDLE POST ═══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
   // Handle edit product (update product details)
if (isset($_POST['update_product'])) {
    $id = intval($_POST['product_id']);
    $batch_id = intval($_POST['edit_batch_id'] ?? 0);
    $name = $conn->real_escape_string($_POST['name']);
    $category = $conn->real_escape_string($_POST['category']);
    $brand = $conn->real_escape_string($_POST['brand']);
    $price = floatval($_POST['price']);
    $mtype = $_POST['edit_measurement'] ?? 'pieces';
    $quantity = ($mtype === 'pieces') ? intval($_POST['pieces']) : 0;
    $kg_val = ($mtype === 'kg') ? floatval($_POST['kg']) : 0;
    $purchase_price = floatval($_POST['purchase_price']);
    $seller_store = $conn->real_escape_string($_POST['seller_store']);
    $purchase_date = $_POST['purchase_date'];
    $expiry_date = (($_POST['edit_expiry_status']??'has_date')==='na'||empty($_POST['expiry_date'])) ? null : $_POST['expiry_date'];
    
    // Handle image upload
    $product_image = null;
    if (isset($_FILES['edit_image_path']) && $_FILES['edit_image_path']['error'] === UPLOAD_ERR_OK) {
        $dir = __DIR__.'/../uploads/';
        if (!is_dir($dir)) mkdir($dir,0755,true);
        $fn = uniqid('prod_',true).'.'.strtolower(pathinfo($_FILES['edit_image_path']['name'],PATHINFO_EXTENSION));
        if (move_uploaded_file($_FILES['edit_image_path']['tmp_name'], $dir.$fn)) {
            $product_image = 'uploads/' . $fn;
            
            // Delete old image from products table
            $old_img = $conn->query("SELECT image_path FROM products WHERE id=$id")->fetch_assoc();
            if (!empty($old_img['image_path']) && file_exists(__DIR__.'/../'.$old_img['image_path'])) {
                unlink(__DIR__.'/../'.$old_img['image_path']);
            }
        }
    }
    
    // Update main product record
    if ($product_image) {
        $sql = "UPDATE products SET 
                name='$name', category='$category', brand='$brand', price=$price, 
                purchase_price=$purchase_price, seller_store='$seller_store',
                purchase_date=".($purchase_date ? "'$purchase_date'" : "NULL").", 
                expiry_date=".($expiry_date ? "'$expiry_date'" : "NULL").", 
                pieces=$quantity, kg=$kg_val, measurement_type='$mtype', 
                image_path='$product_image' 
                WHERE id=$id";
    } else {
        $sql = "UPDATE products SET 
                name='$name', category='$category', brand='$brand', price=$price, 
                purchase_price=$purchase_price, seller_store='$seller_store',
                purchase_date=".($purchase_date ? "'$purchase_date'" : "NULL").", 
                expiry_date=".($expiry_date ? "'$expiry_date'" : "NULL").", 
                pieces=$quantity, kg=$kg_val, measurement_type='$mtype' 
                WHERE id=$id";
    }
    
    if ($conn->query($sql)) {
        // Update the specific batch if batch_id > 0, otherwise update latest active
        if ($batch_id > 0) {
            $updateBatchSql = "UPDATE product_batches SET 
                    name='$name', category='$category', brand='$brand', price=$price,
                    purchase_price=$purchase_price, seller_store='$seller_store',
                    purchase_date=".($purchase_date ? "'$purchase_date'" : "NULL").", 
                    expiry_date=".($expiry_date ? "'$expiry_date'" : "NULL").", 
                    quantity=$quantity, kg=$kg_val, measurement_type='$mtype'";
            
            if ($product_image) {
                $updateBatchSql .= ", image_path='$product_image'";
            }
            
            $updateBatchSql .= " WHERE id = $batch_id";
        } else {
            $updateBatchSql = "UPDATE product_batches SET 
                    name='$name', category='$category', brand='$brand', price=$price,
                    purchase_price=$purchase_price, seller_store='$seller_store',
                    purchase_date=".($purchase_date ? "'$purchase_date'" : "NULL").", 
                    expiry_date=".($expiry_date ? "'$expiry_date'" : "NULL").", 
                    quantity=$quantity, kg=$kg_val, measurement_type='$mtype'";
            
            if ($product_image) {
                $updateBatchSql .= ", image_path='$product_image'";
            }
            
            $updateBatchSql .= " WHERE product_id=$id AND status='active' ORDER BY id DESC LIMIT 1";
        }
        
        $conn->query($updateBatchSql);
        
        $_SESSION['swal'] = ['type'=>'success', 'title'=>'Updated!', 'text'=>'Product updated successfully.'];
    } else {
        $_SESSION['swal'] = ['type'=>'error', 'title'=>'Error', 'text'=>$conn->error];
    }
    
    header("Location: product.php"); 
    exit;
}
    
    // Update main product record - Use 'pieces' instead of 'quantity'
if ($product_image) {
    $sql = "UPDATE products SET 
            name='$name', category='$category', brand='$brand', price=$price, 
            purchase_price=$purchase_price, seller_store='$seller_store',
            purchase_date=".($purchase_date ? "'$purchase_date'" : "NULL").", 
            expiry_date=".($expiry_date ? "'$expiry_date'" : "NULL").", 
            pieces=$quantity, kg=$kg_val, measurement_type='$mtype', 
            image_path='$product_image' 
            WHERE id=$id";
} else {
    $sql = "UPDATE products SET 
            name='$name', category='$category', brand='$brand', price=$price, 
            purchase_price=$purchase_price, seller_store='$seller_store',
            purchase_date=".($purchase_date ? "'$purchase_date'" : "NULL").", 
            expiry_date=".($expiry_date ? "'$expiry_date'" : "NULL").", 
            pieces=$quantity, kg=$kg_val, measurement_type='$mtype' 
            WHERE id=$id";
}
    
    if ($conn->query($sql)) {
        // Also update the latest active batch record - Fixed to use quantity instead of pieces
        $updateBatchSql = "UPDATE product_batches SET 
                name='$name', category='$category', brand='$brand', price=$price,
                purchase_price=$purchase_price, seller_store='$seller_store',
                purchase_date=".($purchase_date ? "'$purchase_date'" : "NULL").", 
                expiry_date=".($expiry_date ? "'$expiry_date'" : "NULL").", 
                quantity=$quantity, kg=$kg_val, measurement_type='$mtype'";
        
        if ($product_image) {
            $updateBatchSql .= ", image_path='$product_image'";
        }
        
        $updateBatchSql .= " WHERE product_id=$id AND status='active' ORDER BY id DESC LIMIT 1";
        
        $conn->query($updateBatchSql);
        
        $_SESSION['swal'] = ['type'=>'success', 'title'=>'Updated!', 'text'=>'Product updated successfully.'];
    } else {
        $_SESSION['swal'] = ['type'=>'error', 'title'=>'Error', 'text'=>$conn->error];
    }
    
    header("Location: product.php"); 
    exit;
}
    
  // Handle new product addition
if (isset($_POST['add_product'])) {
    $r = $conn->query("SELECT MAX(CAST(SUBSTRING(code,2) AS UNSIGNED)) AS mc FROM products")->fetch_assoc();
    $code = 'P'.(($r['mc']??0)+1);
    
    // Check for duplicates
    $name_check = $conn->real_escape_string(trim($_POST['name']));
    $category_check = $conn->real_escape_string(trim($_POST['category']));
    
    $check_stmt = $conn->prepare("SELECT id, name, category FROM products WHERE name = ? AND category = ?");
    $check_stmt->bind_param("ss", $name_check, $category_check);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $existing = $check_result->fetch_assoc();
        $_SESSION['swal'] = [
            'type' => 'warning',
            'title' => '⚠️ Duplicate Product Found!',
            'text' => "A product named '{$existing['name']}' already exists in the '{$existing['category']}' category."
        ];
        $check_stmt->close();
        header("Location: product.php");
        exit;
    }
    $check_stmt->close();
    
    $name = $conn->real_escape_string($_POST['name']);
    $brand = $conn->real_escape_string($_POST['brand'] ?? '');
    $category = $conn->real_escape_string($_POST['category']);
    $seller = $conn->real_escape_string($_POST['seller']);
    $purchase_price = floatval($_POST['purchase_price']);
    $price = floatval($_POST['price']);
    $purchase_date = $_POST['purchase_date'] ?: date('Y-m-d');
    $expiry_date = (($_POST['expiry_status']??'has_date')==='na'||empty($_POST['expiry_date'])) ? null : $_POST['expiry_date'];
    $mtype = ($_POST['measurement']==='kg') ? 'kg' : 'pieces';
    $quantity = ($mtype==='pieces') ? intval($_POST['pieces']) : 0;
    $kg_val = ($mtype==='kg') ? floatval($_POST['kg']) : 0;
    $product_image = null;
    
   if (isset($_FILES['image_path']) && $_FILES['image_path']['error']===UPLOAD_ERR_OK) {
    $dir = __DIR__.'/../uploads/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        error_log("Created uploads directory: " . $dir);
    }
    
    $fn = uniqid('prod_', true) . '.' . strtolower(pathinfo($_FILES['image_path']['name'], PATHINFO_EXTENSION));
    $targetPath = $dir . $fn;
    
    if (move_uploaded_file($_FILES['image_path']['tmp_name'], $targetPath)) {
        $product_image = 'uploads/' . $fn;
        error_log("Image uploaded successfully to: " . $targetPath);
        error_log("Image path stored in DB: " . $product_image);
    } else {
        error_log("Failed to upload image. Error: " . $_FILES['image_path']['error']);
        $product_image = null;
    }
}
    
    // Insert main product record - Fixed to use quantity instead of pieces
   $sql = "INSERT INTO products 
        (code, name, category, brand, seller_store, 
         purchase_price, price, pieces, kg, measurement_type,
         purchase_date, expiry_date, image_path) 
        VALUES ('$code', '$name', '$category', '$brand', '$seller',
                $purchase_price, $price, $quantity, $kg_val, '$mtype',
                ".($purchase_date ? "'$purchase_date'" : "NULL").", 
                ".($expiry_date ? "'$expiry_date'" : "NULL").", 
                ".($product_image ? "'$product_image'" : "NULL").")";
    
    if ($conn->query($sql)) {
        $product_id = $conn->insert_id;
        
        // Also create a batch record for tracking
        $batch_sql = "INSERT INTO product_batches 
                (product_id, code, name, category, brand, seller_store, 
                 purchase_price, price, quantity, kg, measurement_type, 
                 purchase_date, expiry_date, image_path, status)
                VALUES ($product_id, '$code', '$name', '$category', '$brand', '$seller',
                        $purchase_price, $price, $quantity, $kg_val, '$mtype',
                        ".($purchase_date ? "'$purchase_date'" : "NULL").", 
                        ".($expiry_date ? "'$expiry_date'" : "NULL").", 
                        ".($product_image ? "'$product_image'" : "NULL").", 'active')";
        
        $conn->query($batch_sql);
        $batch_id = $conn->insert_id;
        
        // Log audit
        $audit_sql = "INSERT INTO inventory_audit 
                (batch_id, product_id, action, new_quantity, new_kg, seller_store, purchase_price, notes)
                VALUES ($batch_id, $product_id, 'restock', $quantity, $kg_val, '$seller', $purchase_price, 'Initial product creation')";
        $conn->query($audit_sql);
        
        $_SESSION['swal'] = ['type'=>'success', 'title'=>'Added!', 'text'=>'Product added successfully with batch tracking.'];
    } else {
        $_SESSION['swal'] = ['type'=>'error', 'title'=>'Error', 'text'=>$conn->error];
    }
    
    header("Location: product.php"); 
    exit;
}
    
 // Handle restock (new batch arrival)
if (isset($_POST['restock_product'])) {
    $product_id = intval($_POST['product_id']);
    $seller = $conn->real_escape_string($_POST['restock_seller']);
    $purchase_price = floatval($_POST['restock_purchase_price']);
    $price = floatval($_POST['restock_price']);
    $purchase_date = $_POST['restock_purchase_date'] ?: date('Y-m-d');
    $expiry_date = (($_POST['restock_expiry_status']??'has_date')==='na'||empty($_POST['restock_expiry_date'])) ? null : $_POST['restock_expiry_date'];
    $mtype = $_POST['restock_measurement'] ?? 'pieces';
    $quantity = ($mtype==='pieces') ? intval($_POST['restock_pieces']) : 0;
    $kg_val = ($mtype==='kg') ? floatval($_POST['restock_kg']) : 0;
    
    // Get product details
    $product = $conn->query("SELECT code, name, category, brand, image_path FROM products WHERE id=$product_id")->fetch_assoc();
    
    if (!$product) {
        $_SESSION['swal'] = ['type'=>'error', 'title'=>'Error', 'text'=>'Product not found!'];
        header("Location: product.php");
        exit;
    }
    
    // Create new batch
    $batch_sql = "INSERT INTO product_batches 
            (product_id, code, name, category, brand, seller_store, 
             purchase_price, price, quantity, kg, measurement_type, 
             purchase_date, expiry_date, image_path, status)
            VALUES ($product_id, '{$product['code']}', '{$product['name']}', 
                    '{$product['category']}', '{$product['brand']}', '$seller',
                    $purchase_price, $price, $quantity, $kg_val, '$mtype',
                    ".($purchase_date ? "'$purchase_date'" : "NULL").", 
                    ".($expiry_date ? "'$expiry_date'" : "NULL").", 
                    ".($product['image_path'] ? "'{$product['image_path']}'" : "NULL").", 'active')";
    
    if ($conn->query($batch_sql)) {
        $batch_id = $conn->insert_id;
        
        // Update main product quantity (sum of all active batches)
        $total = $conn->query("
            SELECT SUM(quantity) as total_qty, SUM(kg) as total_kg 
            FROM product_batches 
            WHERE product_id = $product_id AND status = 'active'
        ")->fetch_assoc();
        
        $new_qty = (int)($total['total_qty'] ?? 0);
        $new_kg = (float)($total['total_kg'] ?? 0);
        
   $conn->query("UPDATE products SET pieces = $new_qty, kg = $new_kg WHERE id = $product_id");
        
        // Log audit
        $audit_sql = "INSERT INTO inventory_audit 
                (batch_id, product_id, action, new_quantity, new_kg, seller_store, purchase_price, notes)
                VALUES ($batch_id, $product_id, 'restock', $quantity, $kg_val, '$seller', $purchase_price, 'New stock arrival')";
        $conn->query($audit_sql);
        
        $_SESSION['swal'] = ['type'=>'success', 'title'=>'Restocked!', 'text'=>'New batch added successfully.'];
    } else {
        $_SESSION['swal'] = ['type'=>'error', 'title'=>'Error', 'text'=>$conn->error];
    }
    
    header("Location: product.php"); 
    exit;
}
    
    // Handle selling/deducting from specific batch
    else if (isset($_POST['sell_batch'])) {
    $batch_id = intval($_POST['batch_id']);
    $product_id = intval($_POST['product_id']);
    $sell_qty = intval($_POST['sell_quantity']);
    
    // If batch_id is 0, get the latest active batch
    if ($batch_id === 0) {
        $batch = $conn->query("SELECT * FROM product_batches WHERE product_id = $product_id AND status = 'active' AND (quantity > 0 OR kg > 0) ORDER BY id DESC LIMIT 1")->fetch_assoc();
        if ($batch) {
            $batch_id = $batch['id'];
        }
    } else {
        $batch = $conn->query("SELECT * FROM product_batches WHERE id = $batch_id")->fetch_assoc();
    }
        
        if ($batch['measurement_type'] == 'pieces') {
            $new_qty = max(0, $batch['quantity'] - $sell_qty);
            $new_kg = 0;
            $conn->query("UPDATE product_batches SET quantity = $new_qty WHERE id = $batch_id");
        } else {
            $new_kg = max(0, $batch['kg'] - floatval($sell_qty));
            $new_qty = 0;
            $conn->query("UPDATE product_batches SET kg = $new_kg WHERE id = $batch_id");
        }
        
        // Update status if soldout
        if ($new_qty == 0 && $new_kg == 0) {
            $conn->query("UPDATE product_batches SET status = 'soldout' WHERE id = $batch_id");
        }
        
        // Update main product quantity
        $total_qty = $conn->query("
            SELECT SUM(quantity) as total_qty, SUM(kg) as total_kg 
            FROM product_batches 
            WHERE product_id = $product_id AND status = 'active'
        ")->fetch_assoc();
        
        $conn->query("
            UPDATE products SET 
            pieces = " . (int)$total_qty['total_qty'] . ",
            kg = " . (float)$total_qty['total_kg'] . "
            WHERE id = $product_id
        ");
        
        // Log audit
        $audit = $conn->prepare("
            INSERT INTO inventory_audit 
            (batch_id, product_id, action, previous_quantity, new_quantity, notes)
            VALUES (?, ?, 'sold', ?, ?, ?)
        ");
        $qty_sold = $batch['measurement_type'] == 'pieces' ? $sell_qty : 0;
        $notes = "Sold {$sell_qty} " . ($batch['measurement_type'] == 'pieces' ? 'pieces' : 'kg');
        $audit->bind_param("iiiss", $batch_id, $product_id, $qty_sold, $new_qty, $notes);
        $audit->execute();
        $audit->close();
        
        $_SESSION['swal'] = ['type'=>'success', 'title'=>'Sold!', 'text'=>'Stock deducted successfully.'];
        header("Location: product.php"); 
        exit;
    }


/* ═══════ ALERT DATA ═════════════════════════════════════════════════════ */
$today = date('Y-m-d');
$warn3m = date('Y-m-d', strtotime('+3 months'));
$fiveDaysFromNow = date('Y-m-d', strtotime('+5 days'));

// Get view mode from URL (active or expired)
$viewMode = $_GET['view'] ?? 'active';

// Get batches with their details
if ($viewMode === 'expired') {
    // Show expired products from the expired_products table
    $activeBatches = $conn->query("
        SELECT * FROM expired_products 
        ORDER BY expired_date DESC 
        LIMIT 100
    ")->fetch_all(MYSQLI_ASSOC);
    
    // For alert counts (only show active counts when viewing active)
    $lowStockBatches = [];
    $expiringBatches = [];
    $expiredBatches = $activeBatches;
    $lowStockItems = [];
    $outOfStockItems = [];
    $expiringItems = [];
    $expiredItems = $activeBatches;
} else {
    // Show products with aggregated batch data (latest batch only)
    $query = "
        SELECT 
            p.id AS product_id,
            p.code,
            p.name,
            p.category,
            p.brand,
            p.seller_store,
            p.purchase_price,
            p.price,
            p.measurement_type,
            p.pieces,
            p.kg,
            p.image_path,
            p.purchase_date,
            p.expiry_date,
            (SELECT SUM(quantity) FROM product_batches WHERE product_id = p.id AND status = 'active') AS total_quantity,
            (SELECT SUM(kg) FROM product_batches WHERE product_id = p.id AND status = 'active') AS total_kg,
            (SELECT MAX(expiry_date) FROM product_batches WHERE product_id = p.id AND status = 'active') AS latest_expiry_date,
            (SELECT MIN(expiry_date) FROM product_batches WHERE product_id = p.id AND status = 'active' AND expiry_date IS NOT NULL) AS nearest_expiry_date,
            (SELECT COUNT(*) FROM product_batches WHERE product_id = p.id AND status = 'active') AS batch_count,
            DATEDIFF((SELECT MIN(expiry_date) FROM product_batches WHERE product_id = p.id AND status = 'active' AND expiry_date IS NOT NULL), CURDATE()) as days_remaining
        FROM products p
        WHERE p.id IN (SELECT DISTINCT product_id FROM product_batches WHERE status = 'active')
        ORDER BY p.id DESC
    ";
    
    $result = $conn->query($query);
    
    // Debug: Check if query failed
    if (!$result) {
        error_log("SQL Error: " . $conn->error);
        echo "<!-- SQL Error: " . $conn->error . " -->";
        $activeBatches = [];
    } else {
        $activeBatches = $result->fetch_all(MYSQLI_ASSOC);
        error_log("Products found: " . count($activeBatches));
        echo "<!-- Products count: " . count($activeBatches) . " -->";
    }
    
    // Get low stock batches
    $lowStockBatches = $conn->query("
    SELECT 
        p.*,
        (SELECT SUM(quantity) FROM product_batches WHERE product_id = p.id AND status = 'active') AS total_quantity,
        (SELECT SUM(kg) FROM product_batches WHERE product_id = p.id AND status = 'active') AS total_kg
    FROM products p
    WHERE p.id IN (SELECT DISTINCT product_id FROM product_batches WHERE status = 'active')
    AND ((p.measurement_type = 'pieces' AND (SELECT SUM(quantity) FROM product_batches WHERE product_id = p.id AND status = 'active') > 0 
          AND (SELECT SUM(quantity) FROM product_batches WHERE product_id = p.id AND status = 'active') <= 20) 
         OR (p.measurement_type = 'kg' AND (SELECT SUM(kg) FROM product_batches WHERE product_id = p.id AND status = 'active') > 0 
          AND (SELECT SUM(kg) FROM product_batches WHERE product_id = p.id AND status = 'active') <= 20.0))
    ORDER BY 
        CASE WHEN p.measurement_type = 'kg' THEN (SELECT SUM(kg) FROM product_batches WHERE product_id = p.id AND status = 'active')
             ELSE (SELECT SUM(quantity) FROM product_batches WHERE product_id = p.id AND status = 'active') END ASC
")->fetch_all(MYSQLI_ASSOC);
    
    // Get expiring batches (within 5 days)
    $expiringBatches = $conn->query("
        SELECT b.*,
               DATEDIFF(b.expiry_date, CURDATE()) as days_remaining
        FROM product_batches b
        WHERE b.status = 'active' 
        AND b.expiry_date IS NOT NULL 
        AND b.expiry_date <= '$fiveDaysFromNow'
        AND b.expiry_date >= CURDATE()
        AND ((b.measurement_type = 'pieces' AND b.quantity > 0) 
             OR (b.measurement_type = 'kg' AND b.kg > 0))
        ORDER BY b.expiry_date ASC
    ")->fetch_all(MYSQLI_ASSOC);
    
    // Get expired batches (already moved to expired_products)
    $expiredBatches = $conn->query("
        SELECT * FROM expired_products 
        ORDER BY expired_date DESC 
        LIMIT 50
    ")->fetch_all(MYSQLI_ASSOC);
    
    // For backward compatibility with alerts
    $lowStockItems = $lowStockBatches;
    $outOfStockItems = [];
    $expiringItems = $expiringBatches;
    $expiredItems = $expiredBatches;
}

/* ═══════ CATEGORIES WITH COUNTS ════════════════════════════════════════ */
$catResult = $conn->query("SELECT category, COUNT(*) as cnt FROM products WHERE category != '' GROUP BY category ORDER BY category");
$categories = [];
$totalProducts = 0;
while ($c = $catResult->fetch_assoc()) {
    $categories[] = $c;
    $totalProducts += $c['cnt'];
}
$uncatCount = $conn->query("SELECT COUNT(*) as c FROM products WHERE category='' OR category IS NULL")->fetch_assoc()['c'];
$totalAll = $conn->query("SELECT COUNT(*) as c FROM products")->fetch_assoc()['c'];

/* ═══════ ACTIVE CATEGORY FILTER ════════════════════════════════════════ */
$activeCat = $_GET['cat'] ?? 'all';
$page = max(1, (int)($_GET['page']??1));
$limit = 20;
$offset = ($page-1)*$limit;

$whereClause = "";
if ($activeCat === 'uncategorized') {
    $whereClause = "WHERE (category='' OR category IS NULL)";
} elseif ($activeCat !== 'all') {
    $safecat = $conn->real_escape_string($activeCat);
    $whereClause = "WHERE category='$safecat'";
}

$result = $conn->query("SELECT * FROM products $whereClause ORDER BY id DESC LIMIT $limit OFFSET $offset");
$countForCat = $conn->query("SELECT COUNT(*) as c FROM products $whereClause")->fetch_assoc()['c'];
$totalPages = ceil($countForCat/$limit);
ob_end_flush();
?>
<style>
  /* Prevent body scroll when modal is open */
body.modal-open {
    overflow: hidden;
}

/* Modal overlay - prevent click outside from closing */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9000;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.5); /* Semi-transparent background */
    pointer-events: auto; /* Allow clicks on overlay but we'll prevent closing */
}

.modal-overlay.show {
    display: flex;
}
/* Page-specific styles */
.page-hero{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;}
.page-hero h2{font-size:18px;font-weight:800;color:var(--text);}
.page-hero p{font-size:11px;color:var(--text3);margin-top:2px;}

/* Category tabs */
.cat-bar{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:14px;}
.cat-bar-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;}
.cat-bar-title{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:1px;}
.cat-search-wrap{display:flex;gap:0;}
.cat-search-wrap input{background:var(--bg3);border:1.5px solid var(--border);border-right:none;color:var(--text);border-radius:6px 0 0 6px;padding:5px 10px;font-size:11px;width:180px;}
.cat-search-wrap input:focus{outline:none;border-color:var(--orange);}
.cat-search-wrap button{background:var(--orange);border:1.5px solid var(--orange);color:white;border-radius:0 6px 6px 0;padding:5px 10px;cursor:pointer;font-size:11px;}

.cat-tabs-scroll{display:flex;gap:6px;overflow-x:auto;padding-bottom:4px;flex-wrap:wrap;}
.cat-tabs-scroll::-webkit-scrollbar{height:3px;}
.cat-tabs-scroll::-webkit-scrollbar-thumb{background:var(--border2);border-radius:2px;}

.cat-tab-btn{display:flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;border:1.5px solid var(--border);background:var(--bg3);color:var(--text2);cursor:pointer;font-size:11px;font-weight:600;white-space:nowrap;text-decoration:none;transition:all .18s;position:relative;}
.cat-tab-btn:hover{background:var(--card2);border-color:var(--border2);color:var(--text);}
.cat-tab-btn.active{background:linear-gradient(135deg,var(--orange),var(--orange-dk));border-color:var(--orange);color:white;box-shadow:0 3px 14px rgba(255,136,0,.3);}
.cat-tab-btn .ct-count{background:rgba(255,255,255,.18);color:white;border-radius:10px;padding:1px 7px;font-size:9px;font-weight:700;}
.cat-tab-btn:not(.active) .ct-count{background:var(--border2);color:var(--text3);}
.cat-tab-btn .ct-icon{font-size:14px;}
.cat-add-btn{display:inline-flex;align-items:center;gap:3px;margin-left:4px;padding:1px 6px;border-radius:4px;background:rgba(255,255,255,.2);color:white;font-size:9px;font-weight:700;cursor:pointer;border:none;transition:background .15s;}
.cat-add-btn:hover{background:rgba(255,255,255,.35);}
.cat-tab-btn:not(.active) .cat-add-btn{background:rgba(255,136,0,.15);color:var(--orange-lt);}
.cat-tab-btn:not(.active) .cat-add-btn:hover{background:rgba(255,136,0,.3);}

/* Summary strip */
.sum-strip{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;}
.sum-tile{background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:10px 14px;flex:1;min-width:130px;transition:border-color .15s;}
.sum-tile:hover{border-color:var(--orange);}
.sum-tile .st-label{font-size:9px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;}
.sum-tile .st-val{font-size:18px;font-weight:900;}
.st-val.orange{color:var(--orange-lt);}
.st-val.green{color:#4dff88;}
.st-val.red{color:#ff8888;}
.st-val.yellow{color:var(--yellow);}

/* Legend */
.legend-strip{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;align-items:center;}
.legend-chip{display:flex;align-items:center;gap:4px;background:var(--card2);border:1px solid var(--border);border-radius:20px;padding:3px 9px;font-size:10px;color:var(--text2);}
.lc-dot{width:7px;height:7px;border-radius:50%;}

/* Panel */
.panel{background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:14px;}
.panel-header{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:linear-gradient(90deg,var(--card2),var(--card));}
.panel-title{font-size:12px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:7px;}
.pt-dot{width:7px;height:7px;border-radius:50%;background:var(--orange);box-shadow:0 0 5px var(--orange);}

/* Table */
.tbl-wrap{overflow-x:auto;}
.tbl-wrap::-webkit-scrollbar{height:3px;}
.tbl-wrap::-webkit-scrollbar-thumb{background:var(--border2);}
.data-tbl{width:100%;border-collapse:collapse;min-width:980px;}
.data-tbl thead tr{background:linear-gradient(90deg,var(--orange),var(--orange-dk));}
.data-tbl thead th{padding:9px 11px;font-size:10px;font-weight:700;color:rgba(255,255,255,.9);text-transform:uppercase;letter-spacing:.8px;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);}
.data-tbl thead th:last-child{border-right:none;}
.data-tbl tbody tr{border-bottom:1px solid var(--border);transition:background .1s;}
.data-tbl tbody tr:hover{background:rgba(255,255,255,.025);}
.data-tbl tbody td{padding:8px 11px;font-size:11px;color:var(--text2);vertical-align:middle;}
.td-code{background:rgba(255,136,0,.1);color:var(--orange-lt);border-radius:4px;padding:2px 6px;font-size:10px;font-weight:700;font-family:monospace;}
.td-name{color:var(--text);font-weight:600;}
.td-price{color:#4dff88;font-weight:700;}
.td-cap{color:var(--text3);}
.cat-chip{display:inline-flex;align-items:center;gap:3px;background:rgba(68,136,255,.12);color:#88bbff;border:1px solid rgba(68,136,255,.2);border-radius:12px;padding:2px 8px;font-size:10px;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none;}
.cat-chip:hover{background:rgba(68,136,255,.25);color:#aaccff;}
.row-expired td{background:rgba(192,57,43,.06);}
.row-oos td{background:rgba(233,30,99,.06);}
.row-low td{background:rgba(248,29,14,.04);}
.row-nearing td{background:rgba(255,152,0,.04);}

/* Bell icon */
.bell-wrap {
  position: relative;
  display: inline-flex;
  cursor: help;
  margin-left: 6px;
  vertical-align: middle;
  animation: bell-pulse 2s ease-in-out infinite;
}
.bell-wrap .bi {
  font-size: 14px;
  filter: drop-shadow(0 0 3px rgba(255,255,255,0.3));
}
.bell-wrap.ls .bi { color: #2bff00; }   /* Low Stock - Bright Red */
.bell-wrap.oos .bi { color: #ff2d95; }   /* Out of Stock - Hot Pink */
.bell-wrap.exp .bi { color: #6200ff; }   /* Nearing Expiry - Orange */
.bell-wrap.exd .bi { color: #ffffff; }   /* Expired - Crimson */
.bell-wrap.both .bi { color: #ff1493; }  /* Both issues - Deep Pink */
.bell-tip {
  visibility: hidden;
  opacity: 0;
  position: absolute;
  bottom: calc(100% + 8px);
  left: 50%;
  transform: translateX(-50%);
  background: #1e2330;
  border: 1px solid var(--border2);
  color: var(--text);
  border-radius: 6px;
  padding: 6px 10px;
  font-size: 10px;
  white-space: nowrap;
  z-index: 999;
  transition: opacity 0.2s, visibility 0.2s;
  pointer-events: none;
  box-shadow: 0 4px 15px rgba(0,0,0,0.5);
}
.bell-tip::after {
  content: '';
  position: absolute;
  top: 100%;
  left: 50%;
  transform: translateX(-50%);
  border: 5px solid transparent;
  border-top-color: #1e2330;
}
.bell-wrap:hover .bell-tip {
  visibility: visible;
  opacity: 1;
}

/* Also add a small colored dot indicator */
.bell-wrap::before {
  content: '';
  position: absolute;
  top: -2px;
  right: -2px;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  border: 1px solid var(--bg);
}
.bell-wrap.ls::before { background: #ff3b30; }
.bell-wrap.oos::before { background: #ff2d95; }
.bell-wrap.exp::before { background: #ffa500; }
.bell-wrap.exd::before { background: #dc143c; }
.bell-wrap.both::before { background: #ff1493; }
@keyframes bell-pulse {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.15); }
}
/* Badges */
.qty-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;}
.qty-ok{background:rgba(0,200,83,.12);color:#4dff88;border:1px solid rgba(0,200,83,.2);}
.qty-low{background:rgba(248,29,14,.12);color:#ff8888;border:1px solid rgba(248,29,14,.2);}
.qty-oos{background:rgba(233,30,99,.12);color:#ff88cc;border:1px solid rgba(233,30,99,.2);}
.exp-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:12px;font-size:10px;font-weight:700;}
.exp-ok{background:rgba(0,200,83,.1);color:#4dff88;}
.exp-warn{background:rgba(255,152,0,.1);color:#ffcc66;}
.exp-crit{background:rgba(255,69,0,.1);color:#ff8888;}
.exp-dead{background:rgba(192,57,43,.15);color:#ff6666;}

/* Product image */
.prod-img{width:42px;height:42px;object-fit:cover;border-radius:6px;border:1px solid var(--border);}
.prod-img-ph{width:42px;height:42px;background:var(--bg3);border-radius:6px;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:16px;color:var(--text3);}

/* Refill form */
.refill-form{display:flex;align-items:center;gap:4px;}
.refill-inp{background:var(--bg3);border:1px solid var(--border);color:var(--text);border-radius:4px;padding:4px 6px;width:65px;font-size:11px;text-align:center;}
.refill-inp:focus{outline:none;border-color:var(--orange);}
.rf-btn{width:25px;height:25px;border:none;border-radius:4px;cursor:pointer;font-size:13px;font-weight:700;display:flex;align-items:center;justify-content:center;transition:filter .15s;}
.rf-btn:hover{filter:brightness(1.15);}
.rf-dec{background:linear-gradient(135deg,var(--red),#aa1111);color:white;}
.rf-inc{background:linear-gradient(135deg,var(--green),#007a2e);color:white;}

/* Pager */
.pager{display:flex;gap:4px;margin-top:10px;justify-content:center;padding:10px;}
.pg-btn{background:var(--card2);border:1px solid var(--border);color:var(--text2);border-radius:5px;padding:4px 10px;font-size:11px;text-decoration:none;transition:all .15s;}
.pg-btn:hover{background:var(--orange);border-color:var(--orange);color:white;}
.pg-btn.active{background:linear-gradient(135deg,var(--orange),var(--orange-dk));border-color:var(--orange);color:white;}

/* Buttons */
.btn{padding:6px 14px;border:none;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none;transition:all .15s;white-space:nowrap;}
.btn:hover{filter:brightness(1.1);transform:translateY(-1px);}
.btn-orange{background:linear-gradient(135deg,var(--orange),var(--orange-dk));color:white;}
.btn-red{background:linear-gradient(135deg,var(--red),#aa1111);color:white;}
.btn-dark{background:var(--bg3);border:1px solid var(--border);color:var(--text2);}
.btn-dark:hover{background:var(--border2);filter:none;transform:none;}
.btn-green{background:linear-gradient(135deg,var(--green),#007a2e);color:white;}
.btn-blue{background:linear-gradient(135deg,var(--blue),#1a4fa0);color:white;}
.btn-sm{padding:3px 9px;font-size:10px;}

/* Modal - No border-radius, draggable */
.modal-overlay{display:none;position:fixed;inset:0; z-index:9000;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal-box{
  background:var(--card2);
  border:1px solid var(--border2);
  border-radius:0; /* No border-radius */
  width:96%;max-width:720px;max-height:92vh;
  display:flex;flex-direction:column;overflow:hidden;
  box-shadow:0 28px 80px rgba(0,0,0,.8);
  animation:mfade .22s ease;
  cursor:grab;position:relative;
}
.modal-box:active{cursor:grabbing;}
@keyframes mfade{from{opacity:0;transform:scale(.95) translateY(-10px);}to{opacity:1;transform:none;}}
.modal-hdr{padding:16px 20px 0;flex-shrink:0;cursor:grab;}
.modal-hdr:active{cursor:grabbing;}
.modal-hdr-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
.modal-hdr-top h3{font-size:15px;font-weight:800;color:var(--text);}
.mclose{
  background:var(--bg3);color:var(--text2);
  border:1px solid var(--border);
  border-radius:0; /* No border-radius */
  width:28px;height:28px;font-size:14px;cursor:pointer;
  font-weight:700;display:flex;align-items:center;justify-content:center;
}
.mclose:hover{background:var(--red);color:white;border-color:var(--red);}
.modal-cat-pill{
  display:inline-flex;align-items:center;gap:5px;
  background:rgba(255,136,0,.15);
  border:1px solid rgba(255,136,0,.3);
  border-radius:0; /* No border-radius */
  padding:3px 10px;font-size:11px;color:var(--orange-lt);font-weight:600;
}
.step-tabs{display:flex;gap:0;border-bottom:1px solid var(--border);}
.step-tab{
  flex:1;padding:9px 6px;text-align:center;
  font-size:11px;font-weight:600;color:var(--text3);
  cursor:pointer;border-bottom:2px solid transparent;
  transition:all .15s;display:flex;align-items:center;justify-content:center;gap:5px;
}
.step-tab:hover{color:var(--text2);}
.step-tab.active{color:var(--orange-lt);border-bottom-color:var(--orange);}
.step-tab .st-num{
  width:18px;height:18px;border-radius:50%; /* Keep circle for step numbers */
  background:var(--bg3);border:1px solid var(--border2);
  font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.step-tab.active .st-num{background:var(--orange);border-color:var(--orange);color:white;}
.step-tab.done .st-num{background:var(--green);border-color:var(--green);color:white;}
.step-tab.done{color:#66dd88;}
.modal-body{padding:18px 20px;overflow-y:auto;flex:1;}
.modal-body::-webkit-scrollbar{width:4px;}
.modal-body::-webkit-scrollbar-thumb{background:var(--border2);}
.step-panel{display:none;}
.step-panel.active{display:block;animation:stepfade .2s ease;}
@keyframes stepfade{from{opacity:0;transform:translateX(8px);}to{opacity:1;transform:none;}}
.form-section{margin-bottom:16px;}
.form-section-title{
  font-size:10px;font-weight:700;color:var(--text3);
  text-transform:uppercase;letter-spacing:1.2px;
  margin-bottom:10px;padding-bottom:6px;
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:6px;
}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:11px;}
.form-grid.g1{grid-template-columns:1fr;}
.fg-span2{grid-column:span 2;}
.form-group{display:flex;flex-direction:column;gap:4px;}
.form-group label{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;}
.form-input,.form-select{
  background:var(--bg3);border:1.5px solid var(--border);
  color:var(--text);border-radius:0; /* No border-radius */
  padding:8px 11px;font-size:12px;transition:all .15s;width:100%;
}
.form-input:focus,.form-select:focus{outline:none;border-color:var(--orange);background:rgba(255,136,0,.04);}
.form-input::placeholder{color:var(--text3);}
.form-select option{background:var(--card2);}
.toggle-group{display:flex;gap:6px;}
.toggle-opt{flex:1;}
.toggle-opt input{display:none;}
.toggle-opt label{
  display:flex;align-items:center;justify-content:center;gap:5px;
  padding:8px;background:var(--bg3);
  border:1.5px solid var(--border);
  border-radius:0; /* No border-radius */
  cursor:pointer;font-size:11px;font-weight:600;
  color:var(--text2);transition:all .15s;text-align:center;
}
.toggle-opt input:checked+label{background:rgba(255,136,0,.12);border-color:var(--orange);color:var(--orange-lt);}
.img-upload-zone{
  border:2px dashed var(--border2);
  border-radius:0; /* No border-radius */
  padding:18px;text-align:center;cursor:pointer;
  transition:all .15s;position:relative;overflow:hidden;
}
.img-upload-zone:hover{border-color:var(--orange);background:rgba(255,136,0,.03);}
.img-upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.img-upload-zone .uz-icon{font-size:24px;margin-bottom:5px;opacity:.5;}
.img-upload-zone .uz-text{font-size:11px;color:var(--text3);}
.img-preview-zone{
  width:100%;max-height:120px;object-fit:contain;
  border-radius:0; /* No border-radius */
  border:1px solid var(--border);display:none;margin-top:8px;
}
/* Action buttons - improved */
.action-btns {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}
.action-btns .btn {
    width: 30px;
    height: 30px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
}
.action-btns .btn:hover {
    transform: scale(1.1);
    filter: brightness(1.2);
}
.action-btns .btn-dark {
    background: var(--bg3);
    border: 1px solid var(--border);
    color: var(--text2);
}
.action-btns .btn-dark:hover {
    background: var(--border2);
    color: var(--text);
}
.action-btns .btn-blue {
    background: linear-gradient(135deg, #4a7cf7, #3b5fbf);
    color: white;
}
.action-btns .btn-orange {
    background: linear-gradient(135deg, var(--orange), var(--orange-dk));
    color: white;
}
.action-btns .btn-red {
    background: linear-gradient(135deg, var(--red), #aa1111);
    color: white;
}
.modal-foot{
  padding:12px 20px;border-top:1px solid var(--border);
  display:flex;justify-content:space-between;align-items:center;flex-shrink:0;gap:8px;
}
.step-indicator{font-size:10px;color:var(--text3);}
@media(max-width:640px){.form-grid{grid-template-columns:1fr;}.fg-span2{grid-column:span 1;}.cat-tabs-scroll{flex-wrap:nowrap;}}
</style>

<!-- MAIN -->
<div class="main" id="mainContent">

  <div class="page-hero">
    <div>
      <h2>📦 Products Management</h2>
      <p><?= $totalAll ?> total products across <?= count($categories) ?> categories</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="export_products.php" class="btn btn-green">📊 Export Excel</a>
      <button class="btn btn-orange" onclick="openAddModal('')">➕ Add New Product</button>
    </div>
  </div>
  <!-- ════════════ CATEGORY TABS ════════════ -->
  <div class="cat-bar">
    <div class="cat-bar-top">
      <span class="cat-bar-title">🗂 Browse by Category</span>
      <div class="cat-search-wrap">
        <input type="text" id="tableSearch" placeholder="🔍 Search products…" oninput="searchTable()">
        <button onclick="clearSearch()">✕</button>
      </div>
    </div>

    <div class="cat-tabs-scroll">
      <!-- ALL -->
      <a href="product.php?cat=all"
         class="cat-tab-btn <?= $activeCat==='all'?'active':'' ?>">
        <span class="ct-icon">🗃</span>
        All Products
        <span class="ct-count"><?= $totalAll ?></span>
        <button class="cat-add-btn" onclick="event.preventDefault();openAddModal('')">+</button>
      </a>

      <!-- Per category -->
      <?php
      $catIcons = ['bread'=>'🍞','pastry'=>'🥐','cake'=>'🎂','drink'=>'🥤','beverage'=>'🍹',
                   'snack'=>'🍿','cookie'=>'🍪','pie'=>'🥧','donut'=>'🍩','roll'=>'🥖',
                   'general'=>'📦','other'=>'🏷'];
      foreach ($categories as $cat):
        $catName  = $cat['category'];
        $catSlug  = urlencode($catName);
        $isActive = ($activeCat === $catName);
        $icon     = '📦';
        foreach ($catIcons as $k=>$v) if (stripos($catName,$k)!==false){$icon=$v;break;}
      ?>
      <a href="product.php?cat=<?= $catSlug ?>"
         class="cat-tab-btn <?= $isActive?'active':'' ?>">
        <span class="ct-icon"><?= $icon ?></span>
        <?= htmlspecialchars($catName) ?>
        <span class="ct-count"><?= $cat['cnt'] ?></span>
        <button class="cat-add-btn"
                onclick="event.preventDefault();openAddModal('<?= htmlspecialchars($catName,ENT_QUOTES) ?>')">
          +
        </button>
      </a>
      <?php endforeach; ?>

      <!-- Uncategorized -->
      <?php if ($uncatCount > 0): ?>
      <a href="product.php?cat=uncategorized"
         class="cat-tab-btn <?= $activeCat==='uncategorized'?'active':'' ?>">
        <span class="ct-icon">🏷</span>
        Uncategorized
        <span class="ct-count"><?= $uncatCount ?></span>
        <button class="cat-add-btn" onclick="event.preventDefault();openAddModal('')">+</button>
      </a>
      <?php endif; ?>
    </div>
  </div>

   <!-- Summary tiles -->
  <div class="sum-strip">
    <div class="sum-tile">
      <div class="st-label">Showing</div>
      <div class="st-val orange"><?= count($activeBatches) ?></div>
    </div>
    <?php if($viewMode === 'active'): ?>
    <div class="sum-tile">
      <div class="st-label">Low Stock</div>
      <div class="st-val red"><?= count($lowStockBatches) ?></div>
    </div>
    <div class="sum-tile">
      <div class="st-label">Out of Stock</div>
      <div class="st-val red"><?= count($outOfStockItems) ?></div>
    </div>
    <div class="sum-tile">
      <div class="st-label">Nearing Expiry</div>
      <div class="st-val yellow"><?= count($expiringBatches) ?></div>
    </div>
    <div class="sum-tile">
      <div class="st-label">Expired</div>
      <div class="st-val red"><?= count($expiredBatches) ?></div>
    </div>
    <?php else: ?>
    <div class="sum-tile" style="grid-column:span 2;">
      <div class="st-label">Total Expired Items</div>
      <div class="st-val red"><?= count($expiredBatches) ?></div>
    </div>
    <?php endif; ?>
  </div>
  <!-- View Toggle Buttons -->
  <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
    <a href="product.php?view=active<?= $activeCat !== 'all' ? '&cat='.urlencode($activeCat) : '' ?>" 
       class="btn <?= $viewMode === 'active' ? 'btn-orange' : 'btn-dark' ?>">
      📦 Active Products (<?= count($activeBatches) ?>)
    </a>
    <a href="product.php?view=expired<?= $activeCat !== 'all' ? '&cat='.urlencode($activeCat) : '' ?>" 
       class="btn <?= $viewMode === 'expired' ? 'btn-red' : 'btn-dark' ?>">
      🗑️ Expired Products (<?= count($expiredBatches) ?>)
    </a>
    <?php if($viewMode === 'expired'): ?>
    <button class="btn btn-green" onclick="location.reload()">
      🔄 Refresh
    </button>
    <?php endif; ?>
  </div>
  <!-- Legend + alerts button -->
 <div class="legend-strip">
  <div class="legend-chip">
    <div class="lc-dot" style="background:#ff3b30;box-shadow:0 0 6px #ff3b30;"></div>
    Low Stock
  </div>
  <div class="legend-chip">
    <div class="lc-dot" style="background:#ff2d95;box-shadow:0 0 6px #ff2d95;"></div>
    Out of Stock
  </div>
  <div class="legend-chip">
    <div class="lc-dot" style="background:#ffa500;box-shadow:0 0 6px #ffa500;"></div>
    Nearing Expiry
  </div>
  <div class="legend-chip">
    <div class="lc-dot" style="background:#dc143c;box-shadow:0 0 6px #dc143c;"></div>
    Expired
  </div>
  <?php if(count($lowStockItems)+count($outOfStockItems)+count($expiringItems)+count($expiredItems)>0): ?>
  <button class="btn btn-red btn-sm" onclick="showAlerts()" style="margin-left:auto;">
    🚨 Inventory Alerts (<?= count($lowStockItems)+count($outOfStockItems)+count($expiringItems)+count($expiredItems) ?>)
  </button>
  <?php endif; ?>
</div>

    <!-- Products table -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">
        <div class="pt-dot"></div>
        <?php if($viewMode === 'expired'): ?>
          🗑️ Expired Products
        <?php elseif($activeCat==='all'): ?>
          All Products
        <?php elseif($activeCat==='uncategorized'): ?>
          Uncategorized Products
        <?php else: ?>
          <?= htmlspecialchars($activeCat) ?>
        <?php endif; ?>
        <span style="background:var(--bg3);color:var(--text3);border-radius:10px;padding:1px 8px;font-size:10px;">
          <?= $viewMode === 'expired' ? count($expiredBatches) : count($activeBatches) ?>
        </span>
      </div>
      <div style="display:flex;gap:6px;align-items:center;">
        <span style="font-size:10px;color:var(--text3);">Page <?= $page ?> of <?= max(1,$totalPages) ?></span>
        <?php if($viewMode !== 'expired'): ?>
        <?php endif; ?>
      </div>
    </div>
    <div style="padding:0;">
      <div class="tbl-wrap">
        <table class="data-tbl" id="productTable">
          <thead>
            <tr>
              <th>Code</th><th>Name</th><th>Category</th><th>Brand</th>
              <th>Seller</th><th>Capital</th><th>Price</th>
              <th>Purchased</th><th>Expiry</th><th>Image</th>
              <th>Stock</th><th>Refill/Deduct</th><th>Actions</th>
            </tr>
          </thead>
                <tbody>
<?php if (empty($activeBatches)): ?>
<tr>
    <td colspan="13" style="text-align:center;padding:40px;color:var(--text3);">
        <?= $viewMode === 'expired' ? 'No expired products found.' : 'No products found. Add your first product!' ?>
    </td>
</tr>
<?php else: ?>
<?php foreach ($activeBatches as $batch): 
    // Use aggregated quantities
    $curQty = ($batch['measurement_type'] === 'kg') ? (float)$batch['total_kg'] : (int)$batch['total_quantity'];
    $unit = ($batch['measurement_type'] === 'kg') ? 'kg' : 'pcs';
    $lowThr = ($batch['measurement_type'] === 'kg') ? 20.0 : 20;
    $isOOS = $curQty == 0;
    $isLow = !$isOOS && $curQty <= $lowThr;
    
    // Use the nearest expiry date for expiry display
    $expiry_date = !empty($batch['nearest_expiry_date']) ? $batch['nearest_expiry_date'] : $batch['expiry_date'];
    $hasExp = !empty($expiry_date);
    $isExpd = $hasExp && $expiry_date < $today;
    $isNear = $hasExp && !$isExpd && $expiry_date <= $fiveDaysFromNow;
    $dLeft = $hasExp ? floor((strtotime($expiry_date) - strtotime($today)) / (3600*24)) : null;
    
    $rowClass = '';
    if ($isExpd) $rowClass = 'row-expired';
    elseif ($isOOS) $rowClass = 'row-oos';
    elseif ($isLow) $rowClass = 'row-low';
    elseif ($isNear) $rowClass = 'row-nearing';
    
    $qtyClass = $isOOS ? 'qty-oos' : ($isLow ? 'qty-low' : 'qty-ok');
?>
<tr class="<?= $rowClass ?>">
    <td><span class="td-code"><?= htmlspecialchars($batch['code']) ?></span></td>
    <td>
        <span class="td-name"><?= htmlspecialchars($batch['name']) ?></span>
        <?php if ($viewMode !== 'expired'): ?>
        <?php if ($isNear && $isLow): ?>
        <span class="bell-wrap both">
            <i class="bi bi-bell-fill"></i>
            <span class="bell-tip">Low stock + Expires in <?= $dLeft ?>d</span>
        </span>
        <?php elseif ($isNear): ?>
        <span class="bell-wrap exp">
            <i class="bi bi-bell-fill"></i>
            <span class="bell-tip">Expires in <?= $dLeft ?>d</span>
        </span>
        <?php elseif ($isLow): ?>
        <span class="bell-wrap ls">
            <i class="bi bi-bell-fill"></i>
            <span class="bell-tip">Low stock: <?= $curQty ?> <?= $unit ?> left</span>
        </span>
        <?php endif; ?>
        <?php endif; ?>
    </td>
    <td><a href="product.php?cat=<?= urlencode($batch['category']) ?>" class="cat-chip"><?= htmlspecialchars($batch['category'] ?: '—') ?></a></td>
    <td style="color:var(--text2);"><?= htmlspecialchars($batch['brand']) ?></td>
    <td style="color:var(--text3);font-size:10px;"><?= htmlspecialchars($batch['seller_store']) ?></td>
    <td class="td-cap">₱<?= number_format($batch['purchase_price'], 2) ?></td>
    <td class="td-price">₱<?= number_format($batch['price'], 2) ?></td>
   <td>
    <?php if (!empty($batch['purchase_date'])): ?>
        <span class="exp-badge exp-ok" style="background:rgba(68,136,255,.1);color:#88bbff;">
            📅 <?= date('M j, Y', strtotime($batch['purchase_date'])) ?>
        </span>
    <?php else: ?>
        <span style="color:var(--text3);">N/A</span>
    <?php endif; ?>
</td>
    <td>
       <?php if ($hasExp): ?>
    <?php if ($isExpd): ?>
        <span class="exp-badge exp-dead"><?= date('M j, Y', strtotime($expiry_date)) ?></span>
    <?php elseif ($dLeft !== null && $dLeft <= 5): ?>
        <span class="exp-badge exp-crit"><?= date('M j', strtotime($expiry_date)) ?> (<?= $dLeft ?>d)</span>
    <?php elseif ($dLeft !== null && $dLeft <= 30): ?>
        <span class="exp-badge exp-warn"><?= date('M j', strtotime($expiry_date)) ?> (<?= $dLeft ?>d)</span>
    <?php else: ?>
        <span class="exp-badge exp-ok"><?= date('M j, Y', strtotime($expiry_date)) ?></span>
    <?php endif; ?>
<?php else: ?>
    <span style="color:var(--text3);">N/A</span>
<?php endif; ?>
    </td>
   <td>
    <?php 
$image_path = !empty($batch['image_path']) ? $batch['image_path'] : null;
// Debug: Check if image path exists
error_log("Image path from DB: " . ($image_path ?? 'null'));
error_log("Full path: " . ($image_path ? __DIR__ . '/../' . $image_path : 'no image'));

if (!empty($image_path)): 
    // Try different path combinations
    $fullPath = __DIR__ . '/../' . $image_path;
    $altPath = __DIR__ . '/' . $image_path;
    $rootPath = $_SERVER['DOCUMENT_ROOT'] . '/Store/' . $image_path;
    
    if (file_exists($fullPath)): ?>
        <img src="/Store/<?= htmlspecialchars($image_path) ?>" class="prod-img" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
        <div class="prod-img-ph" style="display:none;">📦</div>
    <?php elseif (file_exists($altPath)): ?>
        <img src="<?= htmlspecialchars($image_path) ?>" class="prod-img" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
        <div class="prod-img-ph" style="display:none;">📦</div>
    <?php else: ?>
        <div class="prod-img-ph">📦</div>
    <?php endif; ?>
<?php else: ?>
    <div class="prod-img-ph">📦</div>
<?php endif; ?>
</td>
    <td>
        <span class="qty-badge <?= $qtyClass ?>">
            <?= $curQty ?> <?= $unit ?>
        </span>
    </td>
    <td>
        <?php if ($viewMode !== 'expired'): ?>
        <form action="product.php" method="POST" class="refill-form" style="flex-wrap:wrap;">
    <input type="hidden" name="sell_batch" value="1">
    <input type="hidden" name="batch_id" value="0">
    <input type="hidden" name="product_id" value="<?= $batch['product_id'] ?>">
            <input type="number" name="sell_quantity" placeholder="0" min="1" max="<?= $curQty ?>" class="refill-inp" required>
            <button type="submit" name="action" value="sell" class="rf-btn rf-dec" title="Sell">−</button>
            <button type="button" class="rf-btn rf-inc" title="Restock" onclick="openRestockModal(<?= $batch['product_id'] ?>)">+</button>
        </form>
        <?php else: ?>
        <span style="color:var(--text3);font-size:10px;">
            Expired: <?= date('M j, Y', strtotime($batch['expired_date'] ?? $batch['expiry_date'])) ?>
        </span>
        <?php endif; ?>
    </td>
    <td style="white-space:nowrap;">
    <?php if ($viewMode !== 'expired'): ?>
    <div style="display:flex;gap:4px;flex-wrap:wrap;">
        <!-- View Button -->
        <button class="btn btn-dark btn-sm" onclick="openViewModal(<?= $batch['product_id'] ?>)" title="View Product">
            👁️
        </button>
        <!-- Edit Button -->
        <button class="btn btn-blue btn-sm" onclick="openEditModal(<?= $batch['product_id'] ?>)" title="Edit Product">
            ✏️
        </button>
        <!-- Restock Button -->
        <button class="btn btn-orange btn-sm" onclick="openRestockModal(<?= $batch['product_id'] ?>)" title="Restock">
            📦
        </button>
        <!-- Delete Button -->
        <button class="btn btn-red btn-sm" onclick="confirmDelete(<?= $batch['product_id'] ?>)" title="Delete Product">
            🗑️
        </button>
    </div>
    <?php else: ?>
    <span style="color:var(--text3);font-size:10px;">Archived</span>
    <?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
        </table>
      </div>
      <?php if($totalPages>1): ?>
      <div class="pager">
        <?php
        $qpBase = 'product.php?cat='.urlencode($activeCat);
        if($page>1) echo '<a class="pg-btn" href="'.$qpBase.'&page='.($page-1).'">‹</a>';
        for($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++) echo '<a class="pg-btn'.($i==$page?' active':'').'" href="'.$qpBase.'&page='.$i.'">'.$i.'</a>';
        if($page<$totalPages) echo '<a class="pg-btn" href="'.$qpBase.'&page='.($page+1).'">›</a>';
        ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div><!-- end main -->

<!-- ═══════ ADD PRODUCT MODAL (3 Steps) ════════════════════════════════ -->
<div class="modal-overlay" id="addModal">
  <div class="modal-box" id="addModalBox">
    <div class="modal-hdr" id="addModalHdr">
      <div class="modal-hdr-top">
        <div style="display:flex;align-items:center;gap:10px;">
          <h3>➕ Add New Product</h3>
          <span class="modal-cat-pill" id="addCatPill" style="display:none;"></span>
        </div>
        <button class="mclose" onclick="closeModal('addModal')">✕</button>
      </div>
      <div class="step-tabs">
        <div class="step-tab active" onclick="goStep('add',1)" id="addTab1"><span class="st-num">1</span> Basic Info</div>
        <div class="step-tab" onclick="goStep('add',2)" id="addTab2"><span class="st-num">2</span> Stock & Pricing</div>
        <div class="step-tab" onclick="goStep('add',3)" id="addTab3"><span class="st-num">3</span> Dates & Photo</div>
      </div>
    </div>

    <form id="addProductForm" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="add_product" value="1">
      <div class="modal-body">

        <!-- STEP 1 -->
        <div class="step-panel active" id="addStep1">
          <div class="form-section">
            <div class="form-section-title">🏷 Product Identity</div>
            <div class="form-grid">
              <div class="form-group fg-span2">
                <label>Product Name *</label>
                <input type="text" name="name" id="addName" class="form-input" placeholder="e.g. Pandesal" required>
              </div>
              <div class="form-group">
                <label>Brand</label>
                <input type="text" name="brand" class="form-input" placeholder="e.g. Golden Crust">
              </div>
              <div class="form-group">
                <label>Category *</label>
                <input type="text" name="category" id="addCategory" list="catList" class="form-input" placeholder="e.g. Bread" required>
                <datalist id="catList">
                  <?php foreach($categories as $c): ?>
                    <option value="<?= htmlspecialchars($c['category']) ?>">
                  <?php endforeach; ?>
                </datalist>
              </div>
              <div class="form-group fg-span2">
                <label>Seller / Store *</label>
                <input type="text" name="seller" class="form-input" placeholder="e.g. Angeles Bakery Supplier" required>
              </div>
            </div>
          </div>
        </div>

        <!-- STEP 2 -->
        <div class="step-panel" id="addStep2">
          <div class="form-section">
            <div class="form-section-title">💰 Pricing</div>
            <div class="form-grid">
              <div class="form-group">
                <label>Capital Price (₱) *</label>
                <input type="number" name="purchase_price" class="form-input" placeholder="0.00" step="0.01" min="0" required>
              </div>
              <div class="form-group">
                <label>Selling Price (₱) *</label>
                <input type="number" name="price" class="form-input" placeholder="0.00" step="0.01" min="0" required>
              </div>
            </div>
          </div>
          <div class="form-section">
            <div class="form-section-title">📦 Measurement & Quantity</div>
            <div class="toggle-group" style="margin-bottom:11px;">
              <div class="toggle-opt">
                <input type="radio" name="measurement" id="addMPcs" value="pieces" checked onchange="toggleAddMeasure()">
                <label for="addMPcs">🧮 Pieces</label>
              </div>
              <div class="toggle-opt">
                <input type="radio" name="measurement" id="addMKg" value="kg" onchange="toggleAddMeasure()">
                <label for="addMKg">⚖ Kilograms</label>
              </div>
            </div>
            <div id="addPcsWrap">
              <div class="form-group"><label>Initial Qty (pieces)</label>
                <input type="number" name="pieces" class="form-input" placeholder="0" min="1" value="1"></div>
            </div>
            <div id="addKgWrap" style="display:none;">
              <div class="form-group"><label>Initial Qty (kg)</label>
                <input type="number" name="kg" class="form-input" placeholder="0.00" min="0.01" step="0.01" value="1"></div>
            </div>
          </div>
        </div>

        <!-- STEP 3 -->
        <div class="step-panel" id="addStep3">
          <div class="form-section">
            <div class="form-section-title">📅 Dates</div>
            <div class="form-grid">
              <div class="form-group">
                <label>Date of Purchase</label>
                <input type="date" name="purchase_date" class="form-input">
              </div>
              <div class="form-group">
                <label>Expiry Status</label>
                <div class="toggle-group" style="gap:4px;">
                  <div class="toggle-opt">
                    <input type="radio" name="expiry_status" id="addExpHas" value="has_date" checked onchange="toggleAddExpiry()">
                    <label for="addExpHas" style="font-size:10px;">📅 Set Date</label>
                  </div>
                  <div class="toggle-opt">
                    <input type="radio" name="expiry_status" id="addExpNA" value="na" onchange="toggleAddExpiry()">
                    <label for="addExpNA" style="font-size:10px;">∞ N/A</label>
                  </div>
                </div>
              </div>
              <div class="form-group" id="addExpiryField">
                <label>Expiry Date</label>
                <input type="date" name="expiry_date" class="form-input">
              </div>
            </div>
          </div>
          <div class="form-section">
            <div class="form-section-title">🖼 Product Photo (Optional)</div>
            <div class="img-upload-zone">
              <input type="file" name="image_path" id="addImgInput" accept="image/*" onchange="previewAddImg(this)">
              <div class="uz-icon">📷</div>
              <div class="uz-text">Click to upload<br><small style="color:var(--text3);">JPG, PNG accepted</small></div>
              <img id="addImgPreview" class="img-preview-zone" src="" alt="">
            </div>
          </div>
        </div>

      </div>
      <div class="modal-foot">
        <span class="step-indicator" id="addStepInd">Step 1 of 3</span>
        <div style="display:flex;gap:7px;">
          <button type="button" class="btn btn-dark" id="addPrevBtn" onclick="prevStep('add')" style="display:none;">‹ Back</button>
          <button type="button" class="btn btn-orange" id="addNextBtn" onclick="nextStep('add',3)">Next ›</button>
          <button type="submit" class="btn btn-green" id="addSubmitBtn" style="display:none;">✓ Add Product</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ═══════ RESTOCK MODAL ════════════════════════════════════════════════ -->
<div class="modal-overlay" id="restockModal">
  <div class="modal-box" id="restockModalBox">
    <div class="modal-hdr" id="restockModalHdr">
      <div class="modal-hdr-top">
        <h3>📦 Restock / New Arrival</h3>
        <button class="mclose" onclick="closeModal('restockModal')">✕</button>
      </div>
      <div class="step-tabs">
        <div class="step-tab active" onclick="goStep('restock',1)" id="restockTab1">
          <span class="st-num">1</span> Supplier Info
        </div>
        <div class="step-tab" onclick="goStep('restock',2)" id="restockTab2">
          <span class="st-num">2</span> Stock & Pricing
        </div>
        <div class="step-tab" onclick="goStep('restock',3)" id="restockTab3">
          <span class="st-num">3</span> Dates & Confirm
        </div>
      </div>
    </div>

    <form id="restockProductForm" method="POST" novalidate>
      <input type="hidden" name="restock_product" value="1">
      <input type="hidden" name="product_id" id="restockProductId">
      <div class="modal-body">
        <!-- STEP 1 -->
        <div class="step-panel active" id="restockStep1">
          <div class="form-section">
            <div class="form-section-title">🏷 Supplier Information</div>
            <div class="form-grid">
              <div class="form-group fg-span2">
                <label>Product Name</label>
                <input type="text" id="restockProductName" class="form-input" disabled>
              </div>
              <div class="form-group fg-span2">
                <label>Seller / Supplier *</label>
               <input type="text" name="restock_seller" class="form-input" placeholder="e.g. New Supplier Name" required>
              </div>
              <div class="form-group fg-span2">
                <label>Notes (Optional)</label>
                <input type="text" name="restock_notes" class="form-input" placeholder="Any notes about this batch">
              </div>
            </div>
          </div>
        </div>

        <!-- STEP 2 -->
        <div class="step-panel" id="restockStep2">
          <div class="form-section">
            <div class="form-section-title">💰 Pricing</div>
            <div class="form-grid">
              <div class="form-group">
                <label>Capital Price (₱) *</label>
               <input type="number" name="restock_purchase_price" class="form-input" placeholder="0.00" step="0.01" min="0">
              </div>
              <div class="form-group">
                <label>Selling Price (₱) *</label>
               <input type="number" name="restock_price" class="form-input" placeholder="0.00" step="0.01" min="0">
              </div>
            </div>
          </div>
          <div class="form-section">
            <div class="form-section-title">📦 Quantity</div>
            <div class="toggle-group" style="margin-bottom:11px;">
              <div class="toggle-opt">
                <input type="radio" name="restock_measurement" id="restockMPcs" value="pieces" checked onchange="toggleRestockMeasure()">
                <label for="restockMPcs">🧮 Pieces</label>
              </div>
              <div class="toggle-opt">
                <input type="radio" name="restock_measurement" id="restockMKg" value="kg" onchange="toggleRestockMeasure()">
                <label for="restockMKg">⚖ Kilograms</label>
              </div>
            </div>
            <div id="restockPcsWrap">
              <div class="form-group"><label>Quantity (pieces) *</label>
               <input type="number" name="restock_pieces" class="form-input" placeholder="0" min="1"></div>
            </div>
            <div id="restockKgWrap" style="display:none;">
              <div class="form-group"><label>Quantity (kg) *</label>
              <input type="number" name="restock_kg" class="form-input" placeholder="0.00" min="0.01" step="0.01"></div>
            </div>
          </div>
        </div>

        <!-- STEP 3 -->
        <div class="step-panel" id="restockStep3">
          <div class="form-section">
            <div class="form-section-title">📅 Dates</div>
            <div class="form-grid">
              <div class="form-group">
                <label>Date of Purchase</label>
                <input type="date" name="restock_purchase_date" class="form-input" value="<?= date('Y-m-d') ?>">
              </div>
              <div class="form-group">
                <label>Expiry Status</label>
                <div class="toggle-group" style="gap:4px;">
                  <div class="toggle-opt">
                    <input type="radio" name="restock_expiry_status" id="restockExpHas" value="has_date" checked onchange="toggleRestockExpiry()">
                    <label for="restockExpHas" style="font-size:10px;">📅 Set Date</label>
                  </div>
                  <div class="toggle-opt">
                    <input type="radio" name="restock_expiry_status" id="restockExpNA" value="na" onchange="toggleRestockExpiry()">
                    <label for="restockExpNA" style="font-size:10px;">∞ N/A</label>
                  </div>
                </div>
              </div>
              <div class="form-group" id="restockExpiryField">
                <label>Expiry Date</label>
                <input type="date" name="restock_expiry_date" class="form-input">
              </div>
            </div>
          </div>
          <div class="form-section" style="background:rgba(255,136,0,.05);padding:12px;border:1px solid rgba(255,136,0,.2);">
            <div style="font-size:11px;color:var(--text2);">
              <strong>📌 Note:</strong> This will create a new batch with its own seller, price, and expiry date.
              Each batch is tracked separately for accurate inventory management.
            </div>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <span class="step-indicator" id="restockStepInd">Step 1 of 3</span>
        <div style="display:flex;gap:7px;">
          <button type="button" class="btn btn-dark" id="restockPrevBtn" onclick="prevStep('restock')" style="display:none;">‹ Back</button>
          <button type="button" class="btn btn-orange" id="restockNextBtn" onclick="nextStep('restock',3)">Next ›</button>
          <button type="submit" class="btn btn-green" id="restockSubmitBtn" style="display:none;">✓ Confirm Restock</button>
        </div>
      </div>
    </form>
  </div>
</div>
<!-- ═══════ VIEW PRODUCT MODAL ════════════════════════════════════════════ -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-box" id="viewModalBox">
        <div class="modal-hdr" id="viewModalHdr">
            <div class="modal-hdr-top">
                <h3>👁️ Product Details</h3>
                <button class="mclose" onclick="closeModal('viewModal')">✕</button>
            </div>
        </div>
        <div class="modal-body" id="viewModalBody">
            <div style="text-align:center;padding:20px;">
                <div class="spinner">Loading...</div>
            </div>
        </div>
        <div class="modal-foot">
            <span></span>
            <button class="btn btn-dark" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>
<!-- MOVE THIS HERE - BEFORE JS DATA -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box" id="editModalBox">
        <div class="modal-hdr" id="editModalHdr">
            <div class="modal-hdr-top">
                <h3>✏️ Edit Product</h3>
                <button class="mclose" onclick="closeModal('editModal')">✕</button>
            </div>
            <div class="step-tabs">
                <div class="step-tab active" onclick="goStep('edit',1)" id="editTab1">
                    <span class="st-num">1</span> Basic Info
                </div>
                <div class="step-tab" onclick="goStep('edit',2)" id="editTab2">
                    <span class="st-num">2</span> Stock & Pricing
                </div>
                <div class="step-tab" onclick="goStep('edit',3)" id="editTab3">
                    <span class="st-num">3</span> Dates & Photo
                </div>
            </div>
        </div>
  <form id="editProductForm" method="POST" enctype="multipart/form-data" onsubmit="return submitEditForm(this)">
    <input type="hidden" name="update_product" value="1">
    <input type="hidden" name="product_id" id="editProductId">
    <input type="hidden" name="edit_batch_id" id="editBatchId" value="0">
            <div class="modal-body" id="editModalBody">
                <!-- EDIT STEP 1 -->
                <div class="step-panel active" id="editStep1">
                    <div class="form-section">
                        <div class="form-section-title">🏷️ Product Identity</div>
                        <div class="form-grid">
                            <div class="form-group fg-span2">
                                <label>Product Name *</label>
                                <input type="text" name="name" id="editName" class="form-input" required>
                            </div>
                            <div class="form-group">
                                <label>Brand</label>
                                <input type="text" name="brand" id="editBrand" class="form-input">
                            </div>
                            <div class="form-group">
                                <label>Category *</label>
                                <input type="text" name="category" id="editCategory" list="catList" class="form-input" required>
                            </div>
                            <div class="form-group fg-span2">
                                <label>Seller / Store</label>
                                <input type="text" name="seller_store" id="editSellerStore" class="form-input">
                            </div>
                        </div>
                    </div>
                </div>
                <!-- EDIT STEP 2 -->
                <div class="step-panel" id="editStep2">
                    <div class="form-section">
                        <div class="form-section-title">💰 Pricing</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Capital Price (₱) *</label>
                                <input type="number" name="purchase_price" id="editPurchasePrice" class="form-input" step="0.01" min="0" required>
                            </div>
                            <div class="form-group">
                                <label>Selling Price (₱) *</label>
                                <input type="number" name="price" id="editPrice" class="form-input" step="0.01" min="0" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-section">
                        <div class="form-section-title">📦 Measurement & Quantity</div>
                        <div class="toggle-group" style="margin-bottom:11px;">
                            <div class="toggle-opt">
                                <input type="radio" name="edit_measurement" id="editMPcs" value="pieces" onchange="toggleEditMeasure()">
                                <label for="editMPcs">🧮 Pieces</label>
                            </div>
                            <div class="toggle-opt">
                                <input type="radio" name="edit_measurement" id="editMKg" value="kg" onchange="toggleEditMeasure()">
                                <label for="editMKg">⚖ Kilograms</label>
                            </div>
                        </div>
                        <div id="editPcsWrap">
                            <div class="form-group">
                                <label>Quantity (pieces)</label>
                                <input type="number" name="pieces" id="editPieces" class="form-input" min="0">
                            </div>
                        </div>
                        <div id="editKgWrap" style="display:none;">
                            <div class="form-group">
                                <label>Quantity (kg)</label>
                                <input type="number" name="kg" id="editKg" class="form-input" min="0" step="0.01">
                            </div>
                        </div>
                    </div>
                </div>
                <!-- EDIT STEP 3 -->
                <div class="step-panel" id="editStep3">
                    <div class="form-section">
                        <div class="form-section-title">📅 Dates</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Date of Purchase</label>
                                <input type="date" name="purchase_date" id="editPurchaseDate" class="form-input">
                            </div>
                            <div class="form-group">
                                <label>Expiry Status</label>
                                <div class="toggle-group" style="gap:4px;">
                                    <div class="toggle-opt">
                                        <input type="radio" name="edit_expiry_status" id="editExpHas" value="has_date" checked onchange="toggleEditExpiry()">
                                        <label for="editExpHas" style="font-size:10px;">📅 Set Date</label>
                                    </div>
                                    <div class="toggle-opt">
                                        <input type="radio" name="edit_expiry_status" id="editExpNA" value="na" onchange="toggleEditExpiry()">
                                        <label for="editExpNA" style="font-size:10px;">∞ N/A</label>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group" id="editExpiryField">
                                <label>Expiry Date</label>
                                <input type="date" name="expiry_date" id="editExpiryDate" class="form-input">
                            </div>
                        </div>
                    </div>
                    <div class="form-section">
                        <div class="form-section-title">🖼️ Product Photo</div>
                        <div style="display:flex;gap:12px;align-items:flex-start;">
                            <img id="editImgPreview" src="" alt="" style="width:68px;height:68px;object-fit:cover;border-radius:8px;border:1px solid var(--border);flex-shrink:0;display:none;">
                            <div class="img-upload-zone" style="flex:1;">
                                <input type="file" name="edit_image_path" id="editImgInput" accept="image/*" onchange="previewEditImg(this)">
                                <div class="uz-icon">📷</div>
                                <div class="uz-text">Click to change<br><small style="color:var(--text3);">Leave empty to keep current</small></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <span class="step-indicator" id="editStepInd">Step 1 of 3</span>
                <div style="display:flex;gap:7px;">
                    <button type="button" class="btn btn-dark" id="editPrevBtn" onclick="prevStep('edit')" style="display:none;">‹ Back</button>
                    <button type="button" class="btn btn-orange" id="editNextBtn" onclick="nextStep('edit',3)">Next ›</button>
                    <button type="submit" class="btn btn-green" id="editSubmitBtn" style="display:none;">💾 Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>
<!-- ═══════ BATCH SELECTION MODAL ════════════════════════════════════════ -->
<div class="modal-overlay" id="batchSelectModal">
    <div class="modal-box" id="batchSelectModalBox">
        <div class="modal-hdr" id="batchSelectModalHdr">
            <div class="modal-hdr-top">
                <h3>📦 Select Batch to Edit</h3>
                <button class="mclose" onclick="closeModal('batchSelectModal')">✕</button>
            </div>
        </div>
        <div class="modal-body" id="batchSelectModalBody">
            <div style="text-align:center;padding:20px;">
                <div class="spinner">Loading batches...</div>
            </div>
        </div>
        <div class="modal-foot">
            <span style="font-size:10px;color:var(--text3);">Choose a batch to edit its details</span>
            <button class="btn btn-dark" onclick="closeModal('batchSelectModal')">Cancel</button>
        </div>
    </div>
</div>
<!-- JS DATA -->
<script>
<?php
// Ensure all variables exist with default values
if (!isset($lowStockItems) || !is_array($lowStockItems)) $lowStockItems = [];
if (!isset($outOfStockItems) || !is_array($outOfStockItems)) $outOfStockItems = [];
if (!isset($expiringItems) || !is_array($expiringItems)) $expiringItems = [];
if (!isset($expiredItems) || !is_array($expiredItems)) $expiredItems = [];

// For lowStockItems - map safely with error handling
$lowStockMapped = [];
try {
    foreach ($lowStockItems as $r) {
        $lowStockMapped[] = [
            'code' => isset($r['code']) ? $r['code'] : '',
            'name' => isset($r['name']) ? $r['name'] : '',
            'measurement_type' => isset($r['measurement_type']) ? $r['measurement_type'] : '',
            'pieces' => isset($r['pieces']) ? (int)$r['pieces'] : 0,
            'kg' => isset($r['kg']) ? (float)$r['kg'] : 0
        ];
    }
} catch (Exception $e) {
    $lowStockMapped = [];
}

// For outOfStockItems - map safely
$outOfStockMapped = [];
try {
    foreach ($outOfStockItems as $r) {
        $outOfStockMapped[] = [
            'code' => isset($r['code']) ? $r['code'] : '',
            'name' => isset($r['name']) ? $r['name'] : '',
            'measurement_type' => isset($r['measurement_type']) ? $r['measurement_type'] : ''
        ];
    }
} catch (Exception $e) {
    $outOfStockMapped = [];
}

// For expiringItems - clean up
$expiringMapped = [];
try {
    foreach ($expiringItems as $r) {
        $expiringMapped[] = [
            'id' => isset($r['id']) ? (int)$r['id'] : 0,
            'code' => isset($r['code']) ? $r['code'] : '',
            'name' => isset($r['name']) ? $r['name'] : '',
            'expiry_date' => isset($r['expiry_date']) ? $r['expiry_date'] : '',
            'days_remaining' => isset($r['days_remaining']) ? (int)$r['days_remaining'] : 0
        ];
    }
} catch (Exception $e) {
    $expiringMapped = [];
}

// For expiredItems - clean up
$expiredMapped = [];
try {
    foreach ($expiredItems as $r) {
        $expiredMapped[] = [
            'id' => isset($r['id']) ? (int)$r['id'] : 0,
            'code' => isset($r['code']) ? $r['code'] : '',
            'name' => isset($r['name']) ? $r['name'] : '',
            'expiry_date' => isset($r['expiry_date']) ? $r['expiry_date'] : '',
            'days_expired' => isset($r['days_expired']) ? (int)$r['days_expired'] : 0
        ];
    }
} catch (Exception $e) {
    $expiredMapped = [];
}

// Now encode with error suppression
$lowStockJson = json_encode($lowStockMapped);
$outOfStockJson = json_encode($outOfStockMapped);
$expiringJson = json_encode($expiringMapped);
$expiredJson = json_encode($expiredMapped);

// If JSON encoding failed, use empty array
if ($lowStockJson === false) $lowStockJson = '[]';
if ($outOfStockJson === false) $outOfStockJson = '[]';
if ($expiringJson === false) $expiringJson = '[]';
if ($expiredJson === false) $expiredJson = '[]';
?>
const lowStockItems   = <?= $lowStockJson ?>;
const outOfStockItems = <?= $outOfStockJson ?>;
const expiringItems   = <?= $expiringJson ?>;
const expiredItems    = <?= $expiredJson ?>;
</script>
<script>
/* Clock */
function updateClock(){document.getElementById('currentTime').textContent=new Date().toLocaleString('en-US',{timeZone:'Asia/Manila',weekday:'short',year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});}
setInterval(updateClock,1000); updateClock();

/* Sidebar */
function toggleSidebar(){const sb=document.getElementById('sidebar');sb.style.display=sb.style.display==='flex'?'none':'flex';document.getElementById('menuBtn').textContent=sb.style.display==='flex'?'✖':'☰';}
function toggleSub(btn){const sub=btn.nextElementSibling;const o=sub.classList.toggle('open');btn.classList.toggle('open',o);}

/* Modal */
function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('show');
        document.body.classList.remove('modal-open');
    }
}
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('show');
        document.body.classList.add('modal-open');
    }
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' || e.key === 'Esc') {
        // Close all open modals
        document.querySelectorAll('.modal-overlay.show').forEach(modal => {
            modal.classList.remove('show');
        });
        document.body.classList.remove('modal-open');
    }
});

/* ── Open Add Modal with optional category pre-fill ── */
function openAddModal(cat) {
    // Reset steps
    stepState.add = 1; 
    renderStep('add');
    
    // Pre-fill category
    const catInput = document.getElementById('addCategory');
    const pill = document.getElementById('addCatPill');
    if (cat) {
        catInput.value = cat;
        pill.textContent = '📂 ' + cat;
        pill.style.display = 'inline-flex';
    } else {
        catInput.value = '';
        pill.style.display = 'none';
    }
    
    // Use openModal function
    openModal('addModal');
    
    // Make draggable
    makeDraggable('addModalBox', 'addModalHdr');
}

/* ── Step logic ── */
const stepState = {add: 1, edit: 1, restock: 1};

function renderStep(p) {
    // Check if stepState[p] exists
    if (!stepState[p]) return;
    
    const cur = stepState[p], total = 3;
    for (let i = 1; i <= total; i++) {
        const panel = document.getElementById(p + 'Step' + i);
        if (panel) panel.classList.toggle('active', i === cur);
        
        const tab = document.getElementById(p + 'Tab' + i);
        if (tab) {
            tab.classList.remove('active', 'done');
            if (i === cur) tab.classList.add('active');
            else if (i < cur) tab.classList.add('done');
        }
    }
    
    const indicator = document.getElementById(p + 'StepInd');
    if (indicator) indicator.textContent = 'Step ' + cur + ' of ' + total;
    
    const prev = document.getElementById(p + 'PrevBtn');
    const next = document.getElementById(p + 'NextBtn');
    const sub = document.getElementById(p + 'SubmitBtn');
    
    if (prev) prev.style.display = cur > 1 ? '' : 'none';
    if (next) next.style.display = cur < total ? '' : 'none';
    if (sub) sub.style.display = cur === total ? '' : 'none';
}
function nextStep(p, total) {
    // Check if we're on the restock form and moving from step 1 to step 2
    if (p === 'restock' && stepState[p] === 1) {
        const sellerInput = document.querySelector('input[name="restock_seller"]');
        if (sellerInput && !sellerInput.value.trim()) {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Information',
                text: 'Please enter the seller/supplier name.',
                background: '#1e2330',
                color: '#e8eaf0'
            });
            return;
        }
    }
    
    // Check if we're on the restock form and moving from step 2 to step 3
    if (p === 'restock' && stepState[p] === 2) {
        const measurement = document.querySelector('input[name="restock_measurement"]:checked');
        if (measurement && measurement.value === 'pieces') {
            const piecesInput = document.querySelector('input[name="restock_pieces"]');
            if (piecesInput && (!piecesInput.value || parseInt(piecesInput.value) < 1)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing Information',
                    text: 'Please enter the quantity in pieces.',
                    background: '#1e2330',
                    color: '#e8eaf0'
                });
                return;
            }
        } else if (measurement && measurement.value === 'kg') {
            const kgInput = document.querySelector('input[name="restock_kg"]');
            if (kgInput && (!kgInput.value || parseFloat(kgInput.value) < 0.01)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing Information',
                    text: 'Please enter the quantity in kg.',
                    background: '#1e2330',
                    color: '#e8eaf0'
                });
                return;
            }
        }
        
        const purchasePrice = document.querySelector('input[name="restock_purchase_price"]');
        const price = document.querySelector('input[name="restock_price"]');
        if (purchasePrice && !purchasePrice.value) {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Information',
                text: 'Please enter the capital price.',
                background: '#1e2330',
                color: '#e8eaf0'
            });
            return;
        }
        if (price && !price.value) {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Information',
                text: 'Please enter the selling price.',
                background: '#1e2330',
                color: '#e8eaf0'
            });
            return;
        }
    }
    
    // Move to next step
    if (stepState[p] < total) {
        stepState[p]++;
        renderStep(p);
    }
}
function prevStep(p){if(stepState[p]>1){stepState[p]--;renderStep(p);}}
function goStep(p,n){stepState[p]=n;renderStep(p);}

/* ── Measurement toggles ── */
function toggleAddMeasure(){
  const v = document.querySelector('input[name="measurement"]:checked');
  if (!v) return;
  const val = v.value;
  const pcsWrap = document.getElementById('addPcsWrap');
  const kgWrap = document.getElementById('addKgWrap');
  if (pcsWrap) pcsWrap.style.display = val === 'pieces' ? '' : 'none';
  if (kgWrap) kgWrap.style.display = val === 'kg' ? '' : 'none';
}

function toggleEditMeasure(){
  const v = document.querySelector('input[name="edit_measurement"]:checked');
  if (!v) {
    // If no radio is checked, default to pieces
    const pcsRadio = document.getElementById('editMPcs');
    if (pcsRadio) pcsRadio.checked = true;
    const pcsWrap = document.getElementById('editPcsWrap');
    const kgWrap = document.getElementById('editKgWrap');
    if (pcsWrap) pcsWrap.style.display = '';
    if (kgWrap) kgWrap.style.display = 'none';
    return;
  }
  const val = v.value;
  const pcsWrap = document.getElementById('editPcsWrap');
  const kgWrap = document.getElementById('editKgWrap');
  if (pcsWrap) pcsWrap.style.display = val === 'pieces' ? '' : 'none';
  if (kgWrap) kgWrap.style.display = val === 'kg' ? '' : 'none';
}

function toggleAddExpiry(){
  const v = document.querySelector('input[name="expiry_status"]:checked');
  if (!v) return;
  const val = v.value;
  const expiryField = document.getElementById('addExpiryField');
  if (expiryField) expiryField.style.display = val === 'na' ? 'none' : '';
}

function toggleEditExpiry(){
  const v = document.querySelector('input[name="edit_expiry_status"]:checked');
  if (!v) {
    // If no radio is checked, default to has_date
    const hasRadio = document.getElementById('editExpHas');
    if (hasRadio) hasRadio.checked = true;
    const expiryField = document.getElementById('editExpiryField');
    if (expiryField) expiryField.style.display = '';
    return;
  }
  const val = v.value;
  const expiryField = document.getElementById('editExpiryField');
  if (expiryField) expiryField.style.display = val === 'na' ? 'none' : '';
}
/* ── Image preview ── */
function previewAddImg(input){
  if(!input.files||!input.files[0]) return;
  const r=new FileReader(); r.onload=e=>{const img=document.getElementById('addImgPreview');img.src=e.target.result;img.style.display='block';};
  r.readAsDataURL(input.files[0]);
}
function previewEditImg(input){
  if(!input.files||!input.files[0]) return;
  const r=new FileReader(); r.onload=e=>{const img=document.getElementById('editImgPreview');img.src=e.target.result;img.style.display='block';};
  r.readAsDataURL(input.files[0]);
}

/* ── Open Edit Modal ── */
/* ── Open Edit Modal - Show batch selection first ── */
function openEditModal(id) {
    // Show batch selection modal first
    const batchModal = document.getElementById('batchSelectModal');
    const batchBody = document.getElementById('batchSelectModalBody');
    
    if (!batchModal || !batchBody) {
        console.error('Batch selection modal not found');
        return;
    }
    
    // Show loading
    batchBody.innerHTML = '<div style="text-align:center;padding:20px;"><div class="spinner">Loading batches...</div></div>';
    openModal('batchSelectModal');
    makeDraggable('batchSelectModalBox', 'batchSelectModalHdr');
    
    fetch(`get_product.php?id=${id}`)
        .then(r => r.json())
        .then(p => {
            if (p.error) throw new Error(p.error);
            
            if (p.batches && p.batches.length > 0) {
                let batchesHtml = `
                    <div style="padding:10px;">
                        <div style="margin-bottom:10px;padding:10px;background:var(--bg3);border-radius:8px;">
                            <span style="font-weight:700;color:var(--text);">${p.name}</span>
                            <span class="td-code" style="margin-left:8px;">${p.code}</span>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:8px;">
                `;
                
                p.batches.forEach(batch => {
                    const statusColor = batch.status === 'active' ? '#4dff88' : batch.status === 'soldout' ? '#ff8888' : '#ffcc66';
                    const qty = batch.measurement_type === 'kg' ? parseFloat(batch.kg).toFixed(2) + ' kg' : parseInt(batch.quantity) + ' pcs';
                    
                    batchesHtml += `
                        <div style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:12px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;transition:all .15s;" 
                             onmouseover="this.style.borderColor='var(--orange)'" 
                             onmouseout="this.style.borderColor='var(--border)'"
                             onclick="openEditBatchModal(${p.id}, ${batch.id})">
                            <div>
                                <div style="font-weight:700;color:var(--text);">Batch #${batch.id}</div>
                                <div style="font-size:10px;color:var(--text3);margin-top:3px;">
                                    Seller: ${batch.seller_store || '—'} | Qty: ${qty} | Expiry: ${batch.expiry_date || 'N/A'}
                                </div>
                            </div>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <span style="font-size:9px;padding:2px 8px;border-radius:10px;background:${statusColor}20;color:${statusColor};font-weight:700;">${batch.status.toUpperCase()}</span>
                                <span style="color:var(--orange-lt);">✏️</span>
                            </div>
                        </div>
                    `;
                });
                
                batchesHtml += `
                        </div>
                    </div>
                `;
                
                batchBody.innerHTML = batchesHtml;
            } else {
                batchBody.innerHTML = `
                    <div style="text-align:center;padding:40px;color:var(--text3);">
                        <div style="font-size:48px;">📦</div>
                        <p>No batches available for this product</p>
                    </div>
                `;
            }
        })
        .catch(e => {
            batchBody.innerHTML = `
                <div style="text-align:center;padding:40px;color:#ff4444;">
                    <p>Error loading batches</p>
                    <p style="font-size:12px;color:var(--text3);">${e.message}</p>
                </div>
            `;
        });
}

/* ── Open Edit Modal for specific batch ── */
function openEditBatchModal(productId, batchId) {
    // Close batch selection modal
    closeModal('batchSelectModal');
    
    // Open edit modal
    const modalEl = document.getElementById('editModal');
    if (!modalEl) {
        console.error('Edit modal not found');
        return;
    }
    
    openModal('editModal');
    makeDraggable('editModalBox', 'editModalHdr');
    
    // Show loading state
    const loadingOverlay = document.createElement('div');
    loadingOverlay.id = 'editLoadingOverlay';
    loadingOverlay.style.cssText = 'position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(30,35,48,0.9);display:flex;align-items:center;justify-content:center;z-index:10;';
    loadingOverlay.innerHTML = '<div style="text-align:center;color:#fff;">Loading batch data...</div>';
    
    const existingOverlay = document.getElementById('editLoadingOverlay');
    if (existingOverlay) existingOverlay.remove();
    
    const modalBox = document.getElementById('editModalBox');
    if (modalBox) {
        modalBox.style.position = 'relative';
        modalBox.appendChild(loadingOverlay);
    }
    
    fetch(`get_batch.php?id=${batchId}`)
        .then(r => {
            if (!r.ok) throw new Error(`HTTP ${r.status}: ${r.statusText}`);
            return r.json();
        })
        .then(batch => {
            if (batch.error) throw new Error(batch.error);
            
            // Remove loading overlay
            const overlay = document.getElementById('editLoadingOverlay');
            if (overlay) overlay.remove();
            
            // Helper functions
            function setValue(id, value, defaultValue = '') {
                const el = document.getElementById(id);
                if (el) {
                    el.value = (value !== null && value !== undefined) ? value : defaultValue;
                    return true;
                }
                return false;
            }
            
            function setChecked(id, checked) {
                const el = document.getElementById(id);
                if (el) {
                    el.checked = checked;
                    return true;
                }
                return false;
            }
            
            // Set batch ID
            setValue('editProductId', batch.product_id);
            setValue('editBatchId', batch.id);
            
            // Set all values from batch data
            setValue('editName', batch.name);
            setValue('editCategory', batch.category);
            setValue('editBrand', batch.brand);
            setValue('editSellerStore', batch.seller_store);
            setValue('editPurchasePrice', batch.purchase_price);
            setValue('editPrice', batch.price);
            setValue('editPurchaseDate', batch.purchase_date);
            
            // Handle expiry
            const hasExp = batch.expiry_date && batch.expiry_date !== 'N/A' && batch.expiry_date !== '0000-00-00';
            setChecked('editExpHas', hasExp);
            setChecked('editExpNA', !hasExp);
            setValue('editExpiryDate', hasExp ? batch.expiry_date : '');
            
            // Handle measurement type
            const mt = batch.measurement_type || 'pieces';
            setChecked('editMPcs', mt === 'pieces');
            setChecked('editMKg', mt === 'kg');
            setValue('editPieces', batch.quantity, 0);
            setValue('editKg', parseFloat(batch.kg || 0).toFixed(2), '0.00');
            
            // Handle image preview
            const prevEl = document.getElementById('editImgPreview');
            if (prevEl) {
                if (batch.image_path) {
                    prevEl.src = '/Store/' + batch.image_path;
                    prevEl.style.display = 'block';
                } else {
                    prevEl.src = '';
                    prevEl.style.display = 'none';
                }
            }
            
            // Toggle fields
            toggleEditMeasure();
            toggleEditExpiry();
            
            // Reset step
            if (typeof stepState !== 'undefined') {
                stepState.edit = 1;
                if (typeof renderStep === 'function') {
                    renderStep('edit');
                }
            }
        })
        .catch(e => {
            console.error('Edit error:', e);
            
            // Remove loading overlay
            const overlay = document.getElementById('editLoadingOverlay');
            if (overlay) overlay.remove();
            
            closeModal('editModal');
            
            Swal.fire({
                icon: 'error',
                title: 'Error Loading Batch',
                text: e.message || 'Failed to load batch details.',
                background: '#1e2330',
                color: '#e8eaf0'
            });
        });
}
document.getElementById('restockProductForm').addEventListener('submit', function(e) {
    // Check if we're on the last step
    if (stepState.restock !== 3) {
        e.preventDefault();
        // Navigate to step 3
        if (stepState.restock === 1) {
            nextStep('restock', 3);
        } else if (stepState.restock === 2) {
            nextStep('restock', 3);
        }
        return false;
    }
    
    // Validate step 3 fields
    const purchaseDate = document.querySelector('input[name="restock_purchase_date"]');
    if (purchaseDate && !purchaseDate.value) {
        purchaseDate.value = new Date().toISOString().split('T')[0];
    }
    
    // Allow form submission
    return true;
});
/* ── Drag functionality for all modals ── */
function makeDraggable(modalBoxId, handleId) {
    const modalBox = document.getElementById(modalBoxId);
    const handle = document.getElementById(handleId) || modalBox;
    
    if (!modalBox || !handle) return;
    
    let isDragging = false;
    let startX, startY, initialX, initialY;
    
    handle.style.cursor = 'grab';
    
    handle.addEventListener('mousedown', function(e) {
        // Don't start dragging if clicking on a button, input, select, or textarea
        if (e.target.closest('button') || 
            e.target.closest('input') || 
            e.target.closest('select') || 
            e.target.closest('textarea') ||
            e.target.closest('a')) {
            return;
        }
        
        isDragging = true;
        const rect = modalBox.getBoundingClientRect();
        startX = e.clientX;
        startY = e.clientY;
        initialX = rect.left;
        initialY = rect.top;
        
        // Set position to fixed if not already
        if (!modalBox.style.position || modalBox.style.position === 'relative') {
            modalBox.style.position = 'fixed';
            modalBox.style.left = initialX + 'px';
            modalBox.style.top = initialY + 'px';
            modalBox.style.margin = '0';
        }
        
        handle.style.cursor = 'grabbing';
        e.preventDefault();
    });
    
    document.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        
        const deltaX = e.clientX - startX;
        const deltaY = e.clientY - startY;
        
        // Optional: Keep modal within viewport bounds
        const maxX = window.innerWidth - modalBox.offsetWidth;
        const maxY = window.innerHeight - modalBox.offsetHeight;
        
        let newX = initialX + deltaX;
        let newY = initialY + deltaY;
        
        // Constrain to viewport
        newX = Math.max(0, Math.min(newX, maxX));
        newY = Math.max(0, Math.min(newY, maxY));
        
        modalBox.style.left = newX + 'px';
        modalBox.style.top = newY + 'px';
    });
    
    document.addEventListener('mouseup', function() {
        if (isDragging) {
            isDragging = false;
            handle.style.cursor = 'grab';
        }
    });
}

/* ── Edit submit ── */
function submitEditForm(form){
  event.preventDefault();
  if(!form.checkValidity()){form.reportValidity();return false;}
  const btn=document.getElementById('editSubmitBtn');
  btn.disabled=true;btn.textContent='⏳ Saving…';
  fetch('product.php',{method:'POST',body:new FormData(form),redirect:'follow'})
    .then(r=>{if(r.redirected)window.location.href=r.url;else window.location.reload();})
    .catch(e=>{Swal.fire({icon:'error',title:'Error',text:e.message,background:'#1e2330',color:'#e8eaf0'});btn.disabled=false;btn.textContent='💾 Save Changes';});
  return false;
}

/* ── Delete ── */
function confirmDelete(id){
  Swal.fire({title:'Delete Product?',text:'This cannot be undone.',icon:'warning',showCancelButton:true,
    confirmButtonColor:'#ff4444',cancelButtonColor:'#555',confirmButtonText:'Yes, delete!',
    background:'#1e2330',color:'#e8eaf0'
  }).then(r=>{if(r.isConfirmed)window.location.href='delete_product.php?id='+id;});
}

/* ── Table search ── */
function searchTable(){
  const q=document.getElementById('tableSearch').value.toUpperCase();
  document.querySelectorAll('#productTable tbody tr').forEach(row=>{
    row.style.display=Array.from(row.cells).some(c=>c.textContent.toUpperCase().includes(q))?'':'none';
  });
}
function clearSearch(){document.getElementById('tableSearch').value='';document.querySelectorAll('#productTable tbody tr').forEach(r=>r.style.display='');}

/* ── Inventory Alerts ── */
/* ── Inventory Alerts ── */
function showAlerts() {
    // Safety check - make sure variables exist
    const lowItems = typeof lowStockItems !== 'undefined' ? lowStockItems : [];
    const outItems = typeof outOfStockItems !== 'undefined' ? outOfStockItems : [];
    const expiringItemsList = typeof expiringItems !== 'undefined' ? expiringItems : [];
    const expiredItemsList = typeof expiredItems !== 'undefined' ? expiredItems : [];
    
    function fmtR(d) {
        if (d < 0) return `<span style="color:#c0392b;font-weight:700;">EXPIRED ${Math.abs(d)}d ago</span>`;
        if (d === 0) return `<span style="color:#ff0000;font-weight:700;">TODAY</span>`;
        if (d <= 7) return `<span style="color:#ff4500;font-weight:700;">${d}d</span>`;
        const m = Math.floor(d / 30), rd = d % 30;
        return `<span style="color:#f0ad4e;">${m}mo${rd > 0 ? ' ' + rd + 'd' : ''}</span>`;
    }
    
    const tblStyle = 'width:100%;border-collapse:collapse;font-size:11px;';
    const thStyle = 'padding:4px 8px;text-align:left;color:#9aa3bc;font-size:10px;border-bottom:1px solid #2a3145;';
    const tdStyle = 'padding:5px 8px;border-bottom:1px solid #1e2330;';
    let html = '<div style="text-align:left;max-height:58vh;overflow-y:auto;color:#e8eaf0;">';
    
    if (outItems.length) {
        html += `<div style="margin-bottom:12px;"><div style="color:#e91e63;font-weight:700;margin-bottom:6px;">⛔ OUT OF STOCK (${outItems.length})</div><table style="${tblStyle}"><tr><th style="${thStyle}">Code</th><th style="${thStyle}">Product</th><th style="${thStyle}">Type</th></tr>`;
        outItems.forEach(i => html += `<tr><td style="${tdStyle}">${i.code}</td><td style="${tdStyle}">${i.name}</td><td style="${tdStyle}"><span style="background:rgba(233,30,99,.15);color:#ff88cc;border-radius:10px;padding:1px 7px;font-size:10px;">${i.measurement_type}</span></td></tr>`);
        html += '</table></div>';
    }
    
    if (lowItems.length) {
        html += `<div style="margin-bottom:12px;"><div style="color:#f81d0e;font-weight:700;margin-bottom:6px;">⚠ LOW STOCK (${lowItems.length})</div><table style="${tblStyle}"><tr><th style="${thStyle}">Code</th><th style="${thStyle}">Product</th><th style="${thStyle}">Qty</th></tr>`;
        lowItems.forEach(i => {
            const q = i.measurement_type === 'kg' ? parseFloat(i.kg).toFixed(2) : parseInt(i.pieces);
            const u = i.measurement_type === 'kg' ? 'kg' : 'pcs';
            html += `<tr><td style="${tdStyle}">${i.code}</td><td style="${tdStyle}">${i.name}</td><td style="${tdStyle};color:#ff8888;font-weight:700;">${q} ${u}</td></tr>`;
        });
        html += '</table></div>';
    }
    
    if (expiringItemsList.length) {
        html += `<div style="margin-bottom:12px;"><div style="color:#ff9800;font-weight:700;margin-bottom:6px;">🕐 NEARING EXPIRY (${expiringItemsList.length})</div><table style="${tblStyle}"><tr><th style="${thStyle}">Code</th><th style="${thStyle}">Product</th><th style="${thStyle}">Expiry</th><th style="${thStyle}">Remaining</th></tr>`;
        expiringItemsList.forEach(i => html += `<tr><td style="${tdStyle}">${i.code}</td><td style="${tdStyle}">${i.name}</td><td style="${tdStyle};color:#ffcc66;">${i.expiry_date}</td><td style="${tdStyle}">${fmtR(parseInt(i.days_remaining))}</td></tr>`);
        html += '</table></div>';
    }
    
    if (expiredItemsList.length) {
        html += `<div style="margin-bottom:6px;"><div style="color:#c0392b;font-weight:700;margin-bottom:6px;">💀 EXPIRED (${expiredItemsList.length})</div><table style="${tblStyle}"><tr><th style="${thStyle}">Code</th><th style="${thStyle}">Product</th><th style="${thStyle}">Expired</th></tr>`;
        expiredItemsList.forEach(i => html += `<tr><td style="${tdStyle}">${i.code}</td><td style="${tdStyle}">${i.name}</td><td style="${tdStyle};color:#ff6666;">${i.expiry_date} (${i.days_expired}d ago)</td></tr>`);
        html += '</table></div>';
    }
    
    html += '</div>';
    
    Swal.fire({
        title: '🚨 Inventory Alerts',
        html: html,
        icon: 'warning',
        confirmButtonText: '✓ Acknowledged',
        confirmButtonColor: '#ff8800',
        width: '780px',
        background: '#1e2330',
        color: '#e8eaf0',
        showCloseButton: true,
        allowOutsideClick: false
    });
}
/* Auto-alerts + session SweetAlert */
document.addEventListener('DOMContentLoaded',function(){
  <?php if(count($lowStockItems)+count($outOfStockItems)+count($expiringItems)+count($expiredItems)>0): ?>
  setTimeout(showAlerts,700);
  <?php endif; ?>
  <?php if(isset($_SESSION['swal'])): ?>
  Swal.fire({icon:'<?= $_SESSION['swal']['type'] ?>',title:'<?= addslashes($_SESSION['swal']['title']) ?>',
    text:'<?= addslashes($_SESSION['swal']['text']) ?>',confirmButtonColor:'#ff8800',background:'#1e2330',color:'#e8eaf0'});
  <?php unset($_SESSION['swal']); endif; ?>
});
/* ── Restock Modal Functions ── */
function openRestockModal(productId) {
    // Reset steps - only if stepState.restock exists
    if (typeof stepState !== 'undefined' && stepState.restock !== undefined) {
        stepState.restock = 1;
        renderStep('restock');
    }
    
    // Fetch product details
    fetch(`get_product.php?id=${productId}`)
        .then(r => {
            if (!r.ok) throw new Error('Network response was not ok');
            return r.json();
        })
        .then(p => {
            if (p.error) throw new Error(p.error);
            
            // Check if elements exist before setting values
            const productIdEl = document.getElementById('restockProductId');
            const productNameEl = document.getElementById('restockProductName');
            const purchasePriceEl = document.getElementById('restock_purchase_price');
            const priceEl = document.getElementById('restock_price');
            const modalEl = document.getElementById('restockModal');
            
            // Only set values if elements exist
            if (productIdEl) productIdEl.value = p.id || '';
            if (productNameEl) productNameEl.value = p.name || '';
            if (purchasePriceEl) purchasePriceEl.value = p.purchase_price || '';
            if (priceEl) priceEl.value = p.price || '';
            
            // Show the modal
           if (modalEl) {
    openModal('restockModal');
    makeDraggable('restockModalBox', 'restockModalHdr');
}
        })
        .catch(e => {
            console.error('Restock error:', e);
            Swal.fire({
                icon: 'error', 
                title: 'Error', 
                text: e.message || 'Failed to load product details', 
                background: '#1e2330', 
                color: '#e8eaf0'
            });
        });
}
function toggleRestockMeasure() {
    const checked = document.querySelector('input[name="restock_measurement"]:checked');
    if (!checked) return;
    
    const v = checked.value;
    const pcsWrap = document.getElementById('restockPcsWrap');
    const kgWrap = document.getElementById('restockKgWrap');
    
    if (pcsWrap) pcsWrap.style.display = v === 'pieces' ? '' : 'none';
    if (kgWrap) kgWrap.style.display = v === 'kg' ? '' : 'none';
}

function toggleRestockExpiry() {
    const checked = document.querySelector('input[name="restock_expiry_status"]:checked');
    if (!checked) return;
    
    const v = checked.value;
    const expiryField = document.getElementById('restockExpiryField');
    
    if (expiryField) expiryField.style.display = v === 'na' ? 'none' : '';
}
/* ── View Product Modal ── */
function openViewModal(id) {
    const modal = document.getElementById('viewModal');
    const body = document.getElementById('viewModalBody');
    
    // Show loading
    body.innerHTML = '<div style="text-align:center;padding:20px;"><div class="spinner">Loading...</div></div>';
    openModal('viewModal');
    makeDraggable('viewModalBox', 'viewModalHdr');
    
    fetch(`get_product.php?id=${id}`)
        .then(r => r.json())
        .then(p => {
            if (p.error) throw new Error(p.error);
            
            // Build batch history table
            let batchesHtml = '';
            if (p.batches && p.batches.length > 0) {
                batchesHtml = `
                    <div style="margin-top:15px;background:var(--bg3);border-radius:8px;padding:15px;">
                        <div style="font-size:11px;font-weight:700;color:var(--orange-lt);margin-bottom:10px;">
                            📦 Batch History (${p.batches.length} batches)
                        </div>
                        <div style="overflow-x:auto;">
                            <table style="width:100%;border-collapse:collapse;font-size:10px;">
                                <thead>
                                    <tr style="background:var(--card2);">
                                        <th style="padding:6px;text-align:left;color:var(--text3);border-bottom:1px solid var(--border);">Batch ID</th>
                                        <th style="padding:6px;text-align:left;color:var(--text3);border-bottom:1px solid var(--border);">Seller</th>
                                        <th style="padding:6px;text-align:left;color:var(--text3);border-bottom:1px solid var(--border);">Capital</th>
                                        <th style="padding:6px;text-align:left;color:var(--text3);border-bottom:1px solid var(--border);">Price</th>
                                        <th style="padding:6px;text-align:left;color:var(--text3);border-bottom:1px solid var(--border);">Qty</th>
                                        <th style="padding:6px;text-align:left;color:var(--text3);border-bottom:1px solid var(--border);">Purchased</th>
                                        <th style="padding:6px;text-align:left;color:var(--text3);border-bottom:1px solid var(--border);">Expiry</th>
                                        <th style="padding:6px;text-align:left;color:var(--text3);border-bottom:1px solid var(--border);">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${p.batches.map(batch => {
                                        const statusColor = batch.status === 'active' ? '#4dff88' : batch.status === 'soldout' ? '#ff8888' : '#ffcc66';
                                        const qty = batch.measurement_type === 'kg' ? parseFloat(batch.kg).toFixed(2) + ' kg' : parseInt(batch.quantity) + ' pcs';
                                        return `
                                            <tr style="border-bottom:1px solid var(--border);">
                                                <td style="padding:5px;color:var(--text2);">#${batch.id}</td>
                                                <td style="padding:5px;color:var(--text2);">${batch.seller_store || '—'}</td>
                                                <td style="padding:5px;color:var(--text2);">₱${parseFloat(batch.purchase_price || 0).toFixed(2)}</td>
                                                <td style="padding:5px;color:var(--text2);">₱${parseFloat(batch.price || 0).toFixed(2)}</td>
                                                <td style="padding:5px;color:var(--text2);">${qty}</td>
                                                <td style="padding:5px;color:var(--text2);">${batch.purchase_date || '—'}</td>
                                                <td style="padding:5px;color:var(--text2);">${batch.expiry_date || 'N/A'}</td>
                                                <td style="padding:5px;color:${statusColor};font-weight:700;">${batch.status.toUpperCase()}</td>
                                            </tr>
                                        `;
                                    }).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            } else {
                batchesHtml = `
                    <div style="margin-top:15px;background:var(--bg3);border-radius:8px;padding:15px;text-align:center;color:var(--text3);">
                        No batch history available
                    </div>
                `;
            }
            
            // Build product details HTML
            let html = `
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;padding:10px;">
                    <div style="grid-column:span 2;text-align:center;padding:10px;background:var(--bg3);border-radius:8px;">
                        ${p.image_path ? `<img src="/Store/${p.image_path}" style="max-width:150px;max-height:150px;object-fit:contain;border-radius:8px;" onerror="this.style.display='none';this.nextElementSibling.style.display='block';"><div style="font-size:48px;display:none;">📦</div>` : '<div style="font-size:48px;">📦</div>'}
                        <h3 style="margin:10px 0 5px;color:var(--text);">${p.name || 'N/A'}</h3>
                        <span class="td-code" style="font-size:12px;">${p.code || 'N/A'}</span>
                    </div>
                    <div style="background:var(--bg3);padding:12px;border-radius:8px;">
                        <div style="font-size:10px;color:var(--text3);text-transform:uppercase;">Category</div>
                        <div style="font-weight:600;color:var(--text);">${p.category || '—'}</div>
                    </div>
                    <div style="background:var(--bg3);padding:12px;border-radius:8px;">
                        <div style="font-size:10px;color:var(--text3);text-transform:uppercase;">Brand</div>
                        <div style="font-weight:600;color:var(--text);">${p.brand || '—'}</div>
                    </div>
                    <div style="background:var(--bg3);padding:12px;border-radius:8px;">
                        <div style="font-size:10px;color:var(--text3);text-transform:uppercase;">Total Stock</div>
                        <div style="font-weight:600;color:#4dff88;">${p.measurement_type === 'kg' ? (parseFloat(p.total_kg || 0).toFixed(2) + ' kg') : (parseInt(p.total_quantity || 0) + ' pcs')}</div>
                    </div>
                    <div style="background:var(--bg3);padding:12px;border-radius:8px;">
                        <div style="font-size:10px;color:var(--text3);text-transform:uppercase;">Measurement Type</div>
                        <div style="font-weight:600;color:var(--text);">${p.measurement_type === 'kg' ? '⚖ Kilograms' : '🧮 Pieces'}</div>
                    </div>
                </div>
                ${batchesHtml}
            `;
            
            body.innerHTML = html;
        })
        .catch(e => {
            body.innerHTML = `
                <div style="text-align:center;padding:40px;color:#ff4444;">
                    <div style="font-size:48px;">❌</div>
                    <p>Error loading product details</p>
                    <p style="font-size:12px;color:var(--text3);">${e.message}</p>
                </div>
            `;
        });
}
/* ── Delete ── */
function confirmDelete(id) {
    Swal.fire({
        title: 'Delete Product?',
        text: 'This cannot be undone. All batches and history will be removed.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ff4444',
        cancelButtonColor: '#555',
        confirmButtonText: 'Yes, delete!',
        cancelButtonText: 'Cancel',
        background: '#1e2330',
        color: '#e8eaf0'
    }).then(result => {
        if (result.isConfirmed) {
            window.location.href = `delete_product.php?id=${id}`;
        }
    });
}
/* Connectivity */
function checkConn(){
  fetch('/Store/record/ping.php', {cache:'no-store'})
    .catch(() => {
      // Try alternate paths
      return fetch('../record/ping.php', {cache:'no-store'})
        .catch(() => {
          // If ping.php doesn't exist, just set offline
          return Promise.reject('ping.php not found');
        });
    })
    .then(r => {
      const el = document.getElementById('connStatus');
      if (!el) return;
      el.className = r.ok ? 's-conn online' : 's-conn offline';
      const span = el.querySelector('span');
      if (span) span.textContent = r.ok ? 'ONLINE' : 'OFFLINE';
    })
    .catch(() => {
      const el = document.getElementById('connStatus');
      if (!el) return;
      el.className = 's-conn offline';
      const span = el.querySelector('span');
      if (span) span.textContent = 'OFFLINE';
    });
}
setInterval(checkConn,15000); checkConn();
// Initialize draggable modals on page load
document.addEventListener('DOMContentLoaded', function() {
    makeDraggable('addModalBox', 'addModalHdr');
    makeDraggable('editModalBox', 'editModalHdr');
    makeDraggable('restockModalBox', 'restockModalHdr');
    makeDraggable('viewModalBox', 'viewModalHdr');
});
</script>
<?php require_once __DIR__ . '/../include/admin_footer.php'; ?>
</body>
</html>