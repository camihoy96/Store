<?php
$host = "127.0.0.1";
$username = "store_app";
$password = "Store2026!";
$database = "store";
$port = 3310;

$conn = new mysqli($host, $username, $password, $database, $port);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>