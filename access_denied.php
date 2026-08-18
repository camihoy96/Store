<?php
// access_denied.php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Access Denied</title>
    <style>
        body {
            background-color: #f8d7da;
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
            max-width: 400px;
            width: 90%;
        }
        .icon {
            font-size: 60px;
            color: #dc3545;
            margin-bottom: 20px;
        }
        h1 {
            color: #dc3545;
            margin-bottom: 10px;
        }
        p {
            color: #555;
            margin-bottom: 20px;
        }
        a {
            background-color: #dc3545;
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 8px;
            transition: background-color 0.3s;
        }
        a:hover {
            background-color: #c82333;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🔒</div>
        <h1>Access Denied</h1>
        <p>You don’t have permission to access this page.</p>
        <a href="index.php">Back to Login</a>
    </div>
</body>
</html>
