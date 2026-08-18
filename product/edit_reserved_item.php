<?php
require('dbconn.php');
session_start();
if (!isset($_SESSION['loggedin'])) {
    header("Location: index.php");
    exit;
}
$id = $_GET['id'];
$result = $conn->query("SELECT * FROM reserved_items WHERE id = $id");
if ($result->num_rows === 0) {
  echo "<p>Item not found.</p>";
  exit;
}
$item = $result->fetch_assoc();
?>

<h3>Edit Reserved Item</h3>
<form method="POST" action="update_reserved_item.php">
  <input type="hidden" name="id" value="<?= $item['id'] ?>">
  <label>Name:</label>
  <input type="text" name="name" value="<?= htmlspecialchars($item['name']) ?>" required>

  <label>Category:</label>
  <input type="text" name="category" value="<?= htmlspecialchars($item['category']) ?>" required>
  
   <label>Brand:</label>
  <input type="text" name="brand" value="<?= htmlspecialchars($item['brand']) ?>" required>
  
  <label>Seller Store:</label>
  <input type="text" name="seller_store" value="<?= htmlspecialchars($item['seller_store']) ?>" required>
  
  <label>Capital Price:</label>
  <input type="number" step="0.01" name="capital_price" value="<?= $item['capital_price'] ?>" required>
  
  <label>Purchase Date:</label>
  <input type="date" name="purchase_date" value="<?= $item['purchase_date'] ?>" required>
  
  <label>Expiry Date:</label>
  <input type="date" name="expiry_date" value="<?= $item['expiry_date'] ?>">
  
  <label>Cases:</label>
  <input type="number" name="cases" value="<?= $item['cases'] ?>" required>
  
  <label>Pieces:</label>
  <input type="number" name="pieces" value="<?= $item['pieces'] ?>" required>

  <button type="submit">Update</button>
</form>
