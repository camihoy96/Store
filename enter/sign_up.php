<?php
session_start();
// Initialize variables
$errorMessages = [];
$successMessage = '';
$username = '';
$fullname = '';
$email = '';
$type = 'cashier'; // Changed default to 'cashier' to match your select options

// Get redirect parameter and iframe status
$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'index.php';
$isIframe = isset($_GET['iframe']) || (isset($_SESSION['login_iframe']) && $_SESSION['login_iframe']);
if ($isIframe) {
    $_SESSION['login_iframe'] = true;
}
// Database connection
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'store';

// Create database connection
try {
    $conn = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $username = trim($_POST['username'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $registrationKey = trim($_POST['registration_key'] ?? '');
    $type = $_POST['type'] ?? 'cashier'; // Changed default to 'cashier'
    
    // Get redirect from form
    $redirect = isset($_POST['redirect']) ? $_POST['redirect'] : $redirect;
    
    // Get iframe status from POST as well
    $isIframe = isset($_POST['iframe']) || $isIframe;
    if ($isIframe) {
        $_SESSION['login_iframe'] = true;
    }

    // Validate inputs
    if (empty($username)) {
        $errorMessages[] = 'Username is required';
    } elseif (strlen($username) < 4) {
        $errorMessages[] = 'Username must be at least 4 characters';
    }

    if (empty($fullname)) {
        $errorMessages[] = 'Full name is required';
    }

    if (empty($email)) {
        $errorMessages[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessages[] = 'Invalid email format';
    }

    if (empty($password)) {
        $errorMessages[] = 'Password is required';
    } elseif (strlen($password) < 8) {
        $errorMessages[] = 'Password must be at least 8 characters';
    }

    if ($password !== $confirmPassword) {
        $errorMessages[] = 'Passwords do not match';
    }

    // Validate registration key
    $keyStmt = $conn->prepare("SELECT reg_key FROM registration_keys ORDER BY id DESC LIMIT 1");
    $keyStmt->execute();
    $validKey = $keyStmt->fetch(PDO::FETCH_ASSOC)['reg_key'] ?? 'FOURACC';
    if ($registrationKey !== $validKey) {
        $errorMessages[] = 'Invalid registration key';
    }

    // If no errors, proceed with registration
    if (empty($errorMessages)) {
        try {
            // Check if username, email or fullname already exists
            $stmt = $conn->prepare("SELECT id FROM new_user WHERE username = ? OR email = ? OR fullname = ?");
            $stmt->execute([$username, $email, $fullname]);
            
            if ($stmt->rowCount() > 0) {
                $errorMessages[] = 'Username, email or full name already exists';
            } else {
                // Hash password
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert new user with selected type
                $stmt = $conn->prepare("INSERT INTO new_user (username, fullname, email, type, password) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$username, $fullname, $email, $type, $hashedPassword]);
                
                if ($isIframe) {
                    // Redirect back to login with iframe context and success message
                    $loginUrl = "login.php?iframe=true&redirect=" . urlencode($redirect) . "&signup=success";
                    header("Location: $loginUrl");
                    exit();
                } else {
                    $successMessage = 'Registration successful! You can now <a href="login.php">login</a>.';
                    // Clear form
                    $username = '';
                    $fullname = '';
                    $email = '';
                    $type = 'cashier';
                }
            }
        } catch(PDOException $e) {
            $errorMessages[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Check for success message from redirect
if (isset($_GET['signup']) && $_GET['signup'] === 'success' && !$successMessage) {
    $successMessage = 'Registration successful! You can now login.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FOUR ACC Registration</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
        }
        
        body {
            background-color: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .signup-header {
            top: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: .5rem;
        }
        
        .signup-title {
            color: #333;
            font-size: 22px;
            margin-left: 10px;
        }
        
        .logo {
            height: 100px;
        }
        
        .error {
            color: red;
            margin-bottom: 1rem;
            padding: 0.5rem;
            background-color: #ffeeee;
            border-radius: 4px;
        }
        
        .success {
            color: green;
            margin-bottom: 1rem;
            padding: 0.5rem;
            background-color: #eeffee;
            border-radius: 4px;
        }
        
        input, select {
            width: 100%;
            padding: 0.75rem;
            margin-bottom: 1rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }
        
        button {
            width: 40%;
            padding: 0.75rem;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            cursor: pointer;
            margin-bottom: 1rem;
            margin-left: 32%;
        }
        
        button:hover {
            background-color: #0056b3;
        }
        
        .login-link {
            text-align: center;
            color: #666;
        }
        
        .login-link a {
            color: #0008ffff;
            text-decoration: none;
        }
        
        .login-link a:hover {
            text-decoration: none;
        }
        
        .admin-notice {
            background-color: #fff3cd;
            color: #856404;
            padding: 0.75rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="signup-container">
        <form class="signup-form" method="post">
            <div class="signup-header">
                <h2 class="signup-title">POS Registration</h2>
                <img src="../image/logo.png" alt="Logo" class="logo">
            </div>
            
            <div class="admin-notice">
                <strong>Register Here</strong>
            </div>
            
            <?php if (!empty($errorMessages)): ?>
                <?php foreach ($errorMessages as $error): ?>
                    <div class="error"><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if (!empty($successMessage)): ?>
                <div class="success"><?= $successMessage ?></div>
            <?php endif; ?>
            
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
            <?php if ($isIframe): ?>
                <input type="hidden" name="iframe" value="true">
            <?php endif; ?>
            
            <input type="text" name="username" placeholder="Username" value="<?= htmlspecialchars($username) ?>" required>
            <input type="text" name="fullname" placeholder="Full Name" value="<?= htmlspecialchars($fullname) ?>" required>
            <input type="email" name="email" placeholder="Email" value="<?= htmlspecialchars($email) ?>" required>
            
            <select name="type" required>
                <option value="cashier" <?= $type === 'cashier' ? 'selected' : '' ?>>Cashier</option>
                <option value="staff" <?= $type === 'staff' ? 'selected' : '' ?>>Staff</option>
                <option value="admin" <?= $type === 'admin' ? 'selected' : '' ?>>Administrator</option>
            </select>
            
            <input type="password" name="password" placeholder="Password (min 8 characters)" required>
            <input type="password" name="confirm_password" placeholder="Confirm Password" required>
            <input type="password" name="registration_key" placeholder="Registration Key" required>
            <button type="submit">Register</button>
            
            <p class="login-link">Already have an account? 
                <a href="login.php?<?= 
                    ($isIframe ? 'iframe=true&' : '') . 
                    'redirect=' . urlencode($redirect) ?>">
                    Login here.
                </a>
            </p>
        </form>
    </div>

    <script>
    // Handle navigation for iframe context
    document.addEventListener('DOMContentLoaded', function() {
        const loginLink = document.querySelector('.login-link a');
        
        if (loginLink) {
            if (window !== window.parent) {
                // We're in an iframe - handle with postMessage
                loginLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    // Try to send message to parent
                    try {
                        window.parent.postMessage({
                            action: 'navigateToLogin',
                            redirect: '<?= htmlspecialchars($redirect) ?>'
                        }, '*');
                        
                        // Fallback: if no response after short delay, navigate directly
                        setTimeout(function() {
                            window.location.href = loginLink.href;
                        }, 100);
                    } catch (error) {
                        // If postMessage fails, navigate directly
                        window.location.href = loginLink.href;
                    }
                });
            } else {
                // We're not in an iframe - ensure the link has the correct URL
                const url = new URL(loginLink.href);
                url.searchParams.set('redirect', '<?= htmlspecialchars($redirect) ?>');
                loginLink.href = url.toString();
            }
        }
    });
</script>
</body>
</html>