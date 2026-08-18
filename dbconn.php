<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$servername = "127.0.0.1:3306";
$username = "root";
$password = "";
$dbname = "Store";

$conn = new mysqli($servername, $username, $password, $dbname, 3306);
$conn->set_charset("utf8mb4");
?>
