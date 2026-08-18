<?php
require('dbconn.php');
session_start();
header("Content-Type: text/plain");

if (!isset($_SESSION['loggedin'])) {
    echo "unauthorized";
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "invalid_id";
    exit;
}

$id = intval($_GET['id']);

// If it's a POST request (AJAX submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $conn->real_escape_string($_POST['name']);
    $category = $conn->real_escape_string($_POST['category']);
    $brand = $conn->real_escape_string($_POST['brand']);
    $seller_store = $conn->real_escape_string($_POST['seller_store']);
    $purchase_price = floatval($_POST['purchase_price']);
    $price = floatval($_POST['price']);
    $pieces = intval($_POST['pieces']);
    $purchase_date = $conn->real_escape_string($_POST['purchase_date']);
    $expiry_date = $conn->real_escape_string($_POST['expiry_date']);

    $update = $conn->query("UPDATE products SET 
        name = '$name',
        category = '$category',
        brand = '$brand',
        seller_store = '$seller_store',
        purchase_price = $purchase_price,
        price = $price,
        pieces = $pieces,
        purchase_date = '$purchase_date',
        expiry_date = '$expiry_date'
        WHERE id = $id
    ");

    echo $update ? "success" : "error";
    exit;
}

// If it's a GET request, show the form
header("Content-Type: text/html");
$result = $conn->query("SELECT * FROM products WHERE id = $id LIMIT 1");
if ($result->num_rows === 0) {
    echo "<p style='color:red;'>Product not found.</p>";
    exit;
}
$product = $result->fetch_assoc();
?>

<!-- Display form when not submitted via AJAX -->
<form method="POST" action="edit_product.php?id=<?= $id ?>">
  <label>Product Name:</label>
  <input type="text" name="name" value="<?= htmlspecialchars($product['name']) ?>" required>

  <label>Category:</label>
  <input type="text" name="category" value="<?= htmlspecialchars($product['category']) ?>" required>

  <label>Brand:</label>
  <input type="text" name="brand" value="<?= htmlspecialchars($product['brand']) ?>">

  <label>Seller Store:</label>
  <input type="text" name="seller_store" value="<?= htmlspecialchars($product['seller_store']) ?>">
      
  <label>Capital (₱):</label>
  <input type="number" name="purchase_price" step="0.01" value="<?= htmlspecialchars($product['purchase_price']) ?>" required>

  <label>Price (₱):</label>
  <input type="number" name="price" step="0.01" value="<?= htmlspecialchars($product['price']) ?>" required>

  <label>Quantity (pcs):</label>
  <input type="number" name="pieces" value="<?= htmlspecialchars($product['pieces']) ?>" required>

  <label>Purchase Date:</label>
  <input type="date" name="purchase_date" value="<?= htmlspecialchars($product['purchase_date']) ?>">

  <label>Expiry Date:</label>
  <input type="date" name="expiry_date" value="<?= htmlspecialchars($product['expiry_date']) ?>">

  <button type="submit" class="confirm-update">Update Product</button>
</form>
