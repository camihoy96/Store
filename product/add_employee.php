<?php
session_start();
require('dbconn.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $conn->real_escape_string($_POST['name']);
    $email = $conn->real_escape_string($_POST['email']);
    $position = $conn->real_escape_string($_POST['position']);
    $phone = $conn->real_escape_string($_POST['phone']);
    
    // Validate inputs
    if (empty($name) || empty($email) || empty($position) || empty($phone)) {
        $_SESSION['error'] = "All fields are required!";
        header("Location: employees.php");
        exit();
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Invalid email format!";
        header("Location: employees.php");
        exit();
    }
    
    // Check if email already exists
    $check = $conn->query("SELECT id FROM employees WHERE email = '$email'");
    if ($check->num_rows > 0) {
        $_SESSION['error'] = "Email already exists!";
        header("Location: employees.php");
        exit();
    }
    
    // Insert new employee
    $sql = "INSERT INTO employees (name, email, position, phone) VALUES ('$name', '$email', '$position', '$phone')";
    
    if ($conn->query($sql)) {
        $_SESSION['message'] = "Employee added successfully!";
    } else {
        $_SESSION['error'] = "Error adding employee: " . $conn->error;
    }
    
    $conn->close();
    header("Location: employees.php");
    exit();
} else {
    header("Location: employees.php");
    exit();
}
?>