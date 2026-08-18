<?php
require('dbconn.php');
header("Content-Type: application/json");

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['error' => 'Invalid ID']);
    exit;
}

$id = intval($_GET['id']);
$result = $conn->query("SELECT * FROM products WHERE id = $id LIMIT 1");

if ($result->num_rows === 0) {
    echo json_encode(['error' => 'Product not found']);
    exit;
}

echo json_encode($result->fetch_assoc());