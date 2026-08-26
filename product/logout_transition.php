<?php
// logout_transition.php
$message = isset($_SESSION['logout_message']) ? $_SESSION['logout_message'] : "You have been logged out";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> –St4nger POS</title>
<!-- ===== FAVICON ===== -->
<link rel="icon" type="image/png" sizes="32x32" href="/Store/image/favicon/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/Store/image/favicon/favicon-16x16.png">
<link rel="apple-touch-icon" href="/Store/image/favicon/apple-touch-icon.png">
<link rel="shortcut icon" href="/Store/image/favicon/favicon.ico">
<!-- For Android Chrome -->
<link rel="icon" type="image/png" sizes="192x192" href="/Store/image/favicon/android-chrome-192x192.png">
<link rel="icon" type="image/png" sizes="512x512" href="/Store/image/favicon/android-chrome-512x512.png">
<!-- Web App Manifest (if you have one) -->
<link rel="manifest" href="/Store/image/favicon/site.webmanifest">
<meta name="msapplication-TileColor" content="#ff8800">
<meta name="theme-color" content="#111318">
<!-- ===== END FAVICON ===== -->
    <style>
        body {
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            height: 100vh;
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: 'Arial', sans-serif;
            color: white;
            overflow: hidden;
        }
        
        .logout-container {
            text-align: center;
            z-index: 10;
        }
        
        h1 {
            font-size: 2.5rem;
            margin-bottom: 2rem;
        }
        
        .loader {
            width: 80px;
            height: 80px;
            margin: 0 auto;
            position: relative;
        }
        
        .loader:before {
            content: '';
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 8px solid rgba(255, 255, 255, 0.1);
            position: absolute;
            top: 0;
            left: 0;
        }
        
        .loader:after {
            content: '';
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 8px solid transparent;
            border-top-color: #fff;
            position: absolute;
            top: 0;
            left: 0;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .confetti {
            position: absolute;
            width: 10px;
            height: 10px;
            background-color: #f00;
            opacity: 0;
        }
        
        .message {
            margin-top: 2rem;
            font-size: 1.2rem;
            opacity: 0;
            animation: fadeIn 1s forwards 1s;
        }
        
        @keyframes fadeIn {
            to { opacity: 1; }
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <h1>Logging Out</h1>
        <div class="loader"></div>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
    </div>

    <script>
        // Create confetti effect
        function createConfetti() {
            const colors = ['#f00', '#0f0', '#00f', '#ff0', '#f0f', '#0ff'];
            for (let i = 0; i < 100; i++) {
                const confetti = document.createElement('div');
                confetti.className = 'confetti';
                confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                confetti.style.left = Math.random() * 100 + 'vw';
                confetti.style.top = -10 + 'px';
                confetti.style.transform = 'rotate(' + Math.random() * 360 + 'deg)';
                document.body.appendChild(confetti);
                
                // Animate each confetti piece
                animateConfetti(confetti);
            }
        }
        
        function animateConfetti(element) {
            const duration = Math.random() * 3 + 2;
            const animation = element.animate([
                { top: '-10px', opacity: 0 },
                { top: '10%', opacity: 1 },
                { top: '100vh', opacity: 0 }
            ], {
                duration: duration * 1000,
                delay: Math.random() * 2000
            });
            
            animation.onfinish = () => element.remove();
        }
        
        // Start animations
        setTimeout(createConfetti, 500);
        
        // Redirect after animations complete
        setTimeout(() => {
            window.location.href = "../index.php";
        }, 3000);
    </script>
</body>
</html>