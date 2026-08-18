<?php
session_start();
require('../dbconn.php');
// Check for success message from signup
if (isset($_GET['signup']) && $_GET['signup'] === 'success') {
    $successMessage = 'Registration successful! Please login with your credentials.';
}

// Preserve iframe parameter when navigating to/from signup
$isIframe = isset($_POST['iframe']) || isset($_GET['iframe']) || 
           (isset($_SESSION['login_iframe']) && $_SESSION['login_iframe']);
           
if ($isIframe) {
    $_SESSION['login_iframe'] = true;
}
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];
    $redirect = isset($_POST['redirect']) ? $_POST['redirect'] : '../index.php';
    $isIframe = isset($_POST['iframe']) || isset($_GET['iframe']);
    
    // Updated query to include all fields from new_user table
    $stmt = $conn->prepare("SELECT id, username, password, type, fullname FROM new_user WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['loggedin'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_type'] = $user['type']; // Store user type in session
            $_SESSION['fullname'] = $user['fullname']; // Store full name in session
            
            if ($isIframe) {
                // Return success response to parent window
                echo "<script>
                    window.parent.postMessage({
                        action: 'loginSuccess',
                        redirect: '$redirect'
                    }, '*');
                </script>";
                exit();
            } else {
                header("Location: $redirect");
                exit();
            }
        }
    }
    
    // If we get here, login failed
    if ($isIframe) {
        $errorMessage = 'Invalid username or password!';
    } else {
        $_SESSION['login_error'] = 'Invalid username or password!';
        header("Location: login.php?redirect=" . urlencode($redirect));
        exit();
    }
}

// Check for existing error messages
if (!isset($errorMessage) && isset($_SESSION['login_error'])) {
    $errorMessage = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>POS Login</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        
        .login-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
           margin-bottom: 5px;
        }
        .login-title {
            margin: 0;
            font-size: 36px;
            color: #333;
            margin-left:10px;
        }
        .logo {
            height: 100px;
            width: auto;
        }
        .login-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .login-form input {
            padding: 14px;
            width: 250px;
            font-size: 14px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .login-form input:focus {
            border-color: #2980b9;
            outline: none;
        }
        .login-form button {
            padding: 12px;
            background-color: #2980b9;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            margin-left: 80px;
            width: 120px;
        }
        .login-form button:hover {
            background-color: #3498db;
        }
        .error {
            color: #e74c3c;
            margin-top: 10px;
            text-align: center;
            font-size: 14px;
            padding: 10px;
            background-color: #ffeeee;
            border-radius: 4px;
        }
        .signup-link {
         text-decoration: none;
          color: blue; 
        }
        .signup-link:hover {
            text-decoration: underline;
             text-decoration: none;
        }
        .success {
    color: #27ae60;
    margin-top: 10px;
    text-align: center;
    font-size: 14px;
    padding: 10px;
    background-color: #eeffee;
    border-radius: 4px;
    border: 1px solid #27ae60;
}
    </style>
</head>
<body>
    <div class="login-container">
        <form class="login-form" method="post">
            <div class="login-header">
                <h2 class="login-title">Admin login</h2>
                <img src="../image/logo.png" alt="Logo" class="logo">
            </div>
            <?php if (!empty($errorMessage)): ?>
                <div class="error"><?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>
           <?php if (!empty($successMessage)): ?>
    <div class="success"><?= htmlspecialchars($successMessage) ?></div>
<?php endif; ?>
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($_GET['redirect'] ?? '../index.php') ?>">
            <?php if (isset($_GET['iframe'])): ?>
                <input type="hidden" name="iframe" value="true">
            <?php endif; ?>
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
            <p>Don't have an account?<a href="sign_up.php" class="signup-link"> Register here.</a><p>
        </form>
    </div>

    <script>
        // Handle error messages from parent if this is in an iframe
        window.addEventListener('message', function(e) {
            if (e.data.action === 'showError') {
                const errorDiv = document.querySelector('.error') || document.createElement('div');
                errorDiv.className = 'error';
                errorDiv.textContent = e.data.message;
                
                if (!document.querySelector('.error')) {
                    document.querySelector('.login-form').prepend(errorDiv);
                }
            }
        });

        // If we're in an iframe and have an error message on load, send it to parent
        document.addEventListener('DOMContentLoaded', function() {
            const errorMessage = document.querySelector('.error')?.textContent;
            if (window !== window.parent && errorMessage) {
                window.parent.postMessage({
                    action: 'loginError',
                    message: errorMessage
                }, '*');
            }
        });
        // In login.php, add this to handle signup clicks
document.addEventListener('DOMContentLoaded', function() {
    const signupLink = document.querySelector('.signup-link');
    if (signupLink && window !== window.parent) {
        signupLink.addEventListener('click', function(e) {
            e.preventDefault();
            // Tell parent to update iframe to signup page
            window.parent.postMessage({
                action: 'navigateToSignup',
                redirect: '<?= htmlspecialchars($redirect) ?>'
            }, '*');
        });
    }
});
    </script>
</body>
</html>