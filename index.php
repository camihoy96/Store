<?php
session_start();
require('dbconn.php');

// Set default active tab
$activeTab = isset($_GET['tab']) && $_GET['tab'] === 'register' ? 'register' : 'login';

// Initialize messages
$successMessage = '';
$errorMessage = '';

// FIRST: Check for session success message (MUST BE BEFORE GET CHECK)
if (isset($_SESSION['registration_success'])) {
    $successMessage = $_SESSION['registration_success'];
    $activeTab = 'login';
}

// SECOND: Check for success message from URL parameter
if (isset($_GET['signup']) && $_GET['signup'] === 'success') {
    $successMessage = 'Registration successful! Please login with your credentials.';
    $activeTab = 'login';
    $_SESSION['registration_success'] = $successMessage;
}

// THIRD: Clear session message only after displaying
if (isset($_SESSION['registration_success']) && !empty($successMessage)) {
    unset($_SESSION['registration_success']);
}

// Preserve iframe parameter
$isIframe = isset($_POST['iframe']) || isset($_GET['iframe']) ||
           (isset($_SESSION['login_iframe']) && $_SESSION['login_iframe']);
if ($isIframe) {
    $_SESSION['login_iframe'] = true;
}

// Handle "Remember Me"
if (isset($_COOKIE['remembered_user'])) {
    $rememberedUsername = $_COOKIE['remembered_user'];
} else {
    $rememberedUsername = '';
}

// Handle LOGIN form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['username']) && !isset($_POST['registration'])) {
    $username = trim($conn->real_escape_string($_POST['username']));
    $password = $_POST['password'];
    $remember = isset($_POST['remember']) ? true : false;
    $redirect = isset($_POST['redirect']) ? $_POST['redirect'] : 'home.php';
    $isIframe = isset($_POST['iframe']) || isset($_GET['iframe']);

    $loginError = '';

    if (empty($username)) {
        $loginError = 'Username is required';
    } elseif (empty($password)) {
        $loginError = 'Password is required';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, type, fullname FROM new_user WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                $_SESSION['loggedin']   = true;
                $_SESSION['user_id']    = $user['id'];
                $_SESSION['username']   = $user['username'];
                $_SESSION['user_type']  = $user['type'];
                $_SESSION['fullname']   = $user['fullname'];
                $_SESSION['welcome_message'] = [
                    'status'  => 'success',
                    'message' => 'Welcome back, ' . htmlspecialchars($user['username']) . '!'
                ];

                if ($remember) {
                    setcookie('remembered_user', $username, time() + (30 * 24 * 60 * 60), '/');
                } else {
                    setcookie('remembered_user', '', time() - 3600, '/');
                }

                if ($isIframe) {
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
            } else {
                $loginError = 'Invalid password!';
            }
        } else {
            $loginError = 'Username not found!';
        }
        $stmt->close();
    }

    $errorMessage = $loginError;
    $activeTab = 'login';
}

// Handle REGISTRATION form submission
$regErrorMessages = [];
$regUsername = '';
$regFullname = '';
$regEmail    = '';
$regType     = 'cashier';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registration'])) {
    $regUsername     = trim($_POST['username'] ?? '');
    $regFullname     = trim($_POST['fullname'] ?? '');
    $regEmail        = trim($_POST['email'] ?? '');
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $registrationKey = trim($_POST['registration_key'] ?? '');
    $regType         = $_POST['type'] ?? 'cashier';

    $regErrorMessages = [];
    $activeTab = 'register';

    // Username validation (kept)
    if (empty($regUsername)) {
        $regErrorMessages[] = 'Username is required';
    } elseif (strlen($regUsername) < 4) {
        $regErrorMessages[] = 'Username must be at least 4 characters';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $regUsername)) {
        $regErrorMessages[] = 'Username can only contain letters, numbers, and underscores';
    }

    // Full name validation (kept)
    if (empty($regFullname)) {
        $regErrorMessages[] = 'Full name is required';
    } elseif (strlen($regFullname) < 2) {
        $regErrorMessages[] = 'Full name must be at least 2 characters';
    } elseif (strlen($regFullname) > 100) {
        $regErrorMessages[] = 'Full name cannot exceed 100 characters';
    }

    // Email validation (kept)
    if (empty($regEmail)) {
        $regErrorMessages[] = 'Email is required';
    } elseif (!filter_var($regEmail, FILTER_VALIDATE_EMAIL)) {
        $regErrorMessages[] = 'Invalid email format';
    } elseif (strlen($regEmail) > 100) {
        $regErrorMessages[] = 'Email cannot exceed 100 characters';
    }

    // ========== PASSWORD VALIDATION - RELAXED ==========
    // Only check if password is not empty (no other restrictions)
    if (empty($password)) {
        $regErrorMessages[] = 'Password is required';
    }
    // Removed: length check, uppercase, lowercase, number, special character requirements

    // Check if passwords match
    if ($password !== $confirmPassword) {
        $regErrorMessages[] = 'Passwords do not match';
    }

    // Registration key validation (kept)
    $validRegistrationKey = 'FOURACC';
    if (empty($registrationKey)) {
        $regErrorMessages[] = 'Registration key is required';
    } elseif ($registrationKey !== $validRegistrationKey) {
        $regErrorMessages[] = 'Invalid registration key';
    }

    // User type validation (kept)
    $allowedTypes = ['cashier', 'staff', 'admin'];
    if (empty($regType)) {
        $regErrorMessages[] = 'User type is required';
    } elseif (!in_array($regType, $allowedTypes)) {
        $regErrorMessages[] = 'Invalid user type selected';
    }

    // Check for existing records
    if (empty($regErrorMessages)) {
        $checkStmt = $conn->prepare("SELECT id FROM new_user WHERE username = ?");
        $checkStmt->bind_param("s", $regUsername);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            $regErrorMessages[] = 'Username already exists';
        }
        $checkStmt->close();

        $checkStmt2 = $conn->prepare("SELECT id FROM new_user WHERE email = ?");
        $checkStmt2->bind_param("s", $regEmail);
        $checkStmt2->execute();
        if ($checkStmt2->get_result()->num_rows > 0) {
            $regErrorMessages[] = 'Email already exists';
        }
        $checkStmt2->close();

        $checkStmt3 = $conn->prepare("SELECT id FROM new_user WHERE fullname = ?");
        $checkStmt3->bind_param("s", $regFullname);
        $checkStmt3->execute();
        if ($checkStmt3->get_result()->num_rows > 0) {
            $regErrorMessages[] = 'Full name already exists';
        }
        $checkStmt3->close();

        if (empty($regErrorMessages)) {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $insertStmt = $conn->prepare("INSERT INTO new_user (username, fullname, email, type, password) VALUES (?, ?, ?, ?, ?)");
            $insertStmt->bind_param("sssss", $regUsername, $regFullname, $regEmail, $regType, $hashedPassword);
            if ($insertStmt->execute()) {
                $_SESSION['registration_success'] = 'Registration successful! Please login with your credentials.';
                header("Location: index.php?signup=success&tab=login");
                exit();
            } else {
                $regErrorMessages[] = 'Database error: Unable to create account. Please try again.';
            }
            $insertStmt->close();
        }
    }
}

if (!isset($errorMessage) && isset($_SESSION['login_error'])) {
    $errorMessage = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

$rememberedUsername = isset($_COOKIE['remembered_user']) ? $_COOKIE['remembered_user'] : '';
// ─── FETCH SYSTEM SETTINGS ─────────────────────────────────────────────────────
$systemSettings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $result->fetch_assoc()) {
    $systemSettings[$row['setting_key']] = $row['setting_value'];
}

$businessName     = $systemSettings['business_name']       ?? 'Angel\'s Bakeshop';
$businessSubtitle = $systemSettings['business_subtitle']   ?? 'POS SYSTEM';
$businessAddress  = $systemSettings['business_address']    ?? 'Upper Batinguel, Dumaguete City, Negros Oriental 6200';
$businessPhone    = $systemSettings['business_phone']      ?? '0905 615 2262';
$currencySymbol   = $systemSettings['currency_symbol']     ?? '₱';
$enableCash       = $systemSettings['enable_cash']         ?? '1';
$enableEwallet    = $systemSettings['enable_ewallet']      ?? '1';
$receiptFooter    = $systemSettings['receipt_footer']      ?? 'Thank you for your purchase!';
$autoPrintReceipt = $systemSettings['auto_print_receipt']  ?? '1';
$logoPath         = $systemSettings['logo_path']           ?? ''; // ADD THIS LINE
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>St4nger — POS Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800;900&family=Barlow:wght@300;400;500;600&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════════
   RESET & ROOT
═══════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg-void:     #111111;
  --bg-deep:     #171717;
  --bg-surface:  #1e1e1e;
  --bg-raised:   #252525;
  --bg-panel:    #2a2a2a;
  --border-dim:  #2e2e2e;
  --border-mid:  #3a3a3a;
  --border-lit:  #4a4a4a;

  --amber:       #ff8800;
  --amber-hot:   #ff6000;
  --amber-glow:  #ffaa33;
  --amber-dim:   #cc6600;
  --amber-pale:  rgba(255,136,0,0.12);

  --text-bright: #f0ece4;
  --text-mid:    #b0a898;
  --text-dim:    #d5cfcb;
  --text-green:  #9bcdac;
  --text-red:    #ff5555;
  --text-yellow: #ffcc44;

  --font-display: 'Barlow Condensed', sans-serif;
  --font-body:    'Barlow', sans-serif;
  --font-mono:    'Share Tech Mono', monospace;

  --radius:      6px;
  --radius-lg:   10px;
}

body {
  font-family: var(--font-body);
  background: var(--bg-void);
  color: var(--text-bright);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* ═══════════════════════════════════════════════════════
   BACKGROUND GRID TEXTURE
═══════════════════════════════════════════════════════ */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background-image:
    linear-gradient(rgba(255,136,0,0.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,136,0,0.03) 1px, transparent 1px);
  background-size: 40px 40px;
  pointer-events: none;
  z-index: 0;
}

/* Corner scanline flicker */
body::after {
  content: '';
  position: fixed;
  inset: 0;
  background: repeating-linear-gradient(
    0deg,
    transparent,
    transparent 2px,
    rgba(0,0,0,0.04) 2px,
    rgba(0,0,0,0.04) 4px
  );
  pointer-events: none;
  z-index: 0;
}

/* ═══════════════════════════════════════════════════════
   TOP STATUS BAR (mimics home.php)
═══════════════════════════════════════════════════════ */
.sys-topbar {
  position: relative;
  z-index: 10;
  background: linear-gradient(180deg, #3a3a3a 0%, #262626 100%);
  border-bottom: 2px solid #111;
  box-shadow: 0 2px 12px rgba(0,0,0,0.6);
  height: 44px;
  display: flex;
  align-items: center;
  padding: 0 16px;
  gap: 10px;
  flex-shrink: 0;
}

.logo-block {
  background: linear-gradient(135deg, var(--amber), var(--amber-hot));
  border-radius: var(--radius);
  padding: 4px 14px;
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 8px;
  line-height: 1.15;
}
.logo-block .logo-img {
  max-height: 28px;
  width: auto;
  display: block;
  border-radius: 3px;
  flex-shrink: 0;
}
.logo-block .logo-text {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
}
.logo-block .brand { 
  font-family: var(--font-display); 
  font-weight: 900; 
  font-size: 13px; 
  color: white; 
  letter-spacing: 0.5px; 
}
.logo-block .sub   { 
  font-family: var(--font-display); 
  font-size: 8px; 
  color: rgba(255,255,255,0.82); 
  letter-spacing: 2px; 
  font-weight: 600; 
}

.topbar-title {
  font-family: var(--font-display);
  font-size: 14px;
  font-weight: 700;
  color: #ccc;
  letter-spacing: 1px;
  text-transform: uppercase;
}

.topbar-spacer { flex: 1; }

.topbar-clock {
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--amber-glow);
  letter-spacing: 0.5px;
}

.topbar-pill {
  background: var(--bg-raised);
  border: 1px solid var(--border-mid);
  border-radius: 3px;
  padding: 2px 8px;
  font-family: var(--font-mono);
  font-size: 10px;
  color: var(--text-green);
  letter-spacing: 0.5px;
}

/* ═══════════════════════════════════════════════════════
   MAIN LAYOUT
═══════════════════════════════════════════════════════ */
.page-wrap {
  position: relative;
  z-index: 1;
  flex: 1;
  display: flex;
  overflow: hidden;
}

/* ─── LEFT BRANDING PANEL ─── */
.brand-panel {
  width: 42%;
  position: relative;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  overflow: hidden;
  flex-shrink: 0;
}

.brand-bg {
  position: absolute;
  inset: 0;
  background-image: url('image/photo.jpg');
  background-size: cover;
  background-position: center;
  filter: brightness(0.35) saturate(0.6);
  transition: filter 0.6s ease;
}

.brand-panel:hover .brand-bg {
  filter: brightness(0.42) saturate(0.75);
}

.brand-overlay {
  position: absolute;
  inset: 0;
  background: linear-gradient(
    to top,
    rgba(10, 10, 10, 0) 0%,
    rgba(10, 10, 10, 0.03)  40%,
    rgba(17, 17, 17, 0)  100%
  );
}

/* Amber accent stripe on right edge */
.brand-panel::after {
  content: '';
  position: absolute;
  top: 0; right: 0;
  width: 3px;
  height: 100%;
  background: linear-gradient(180deg, transparent 0%, var(--amber) 40%, var(--amber-hot) 70%, transparent 100%);
  opacity: 0.7;
}

.brand-content {
  position: relative;
  z-index: 2;
  padding: 0 40px 44px;
}

.brand-eyebrow {
  font-family: var(--font-mono);
  font-size: 10px;
  color: rgba(0, 255, 13, 0.96);
  letter-spacing: 3px;
  text-transform: uppercase;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.brand-eyebrow::before {
  content: '';
  display: inline-block;
  width: 22px;
  height: 1px;
  background: var(--amber);
}

.brand-name {
  font-family: var(--font-display);
  font-weight: 900;
  font-size: 48px;
  line-height: 0.95;
  color: var(--text-bright);
  letter-spacing: -0.5px;
  margin-bottom: 6px;
  text-transform: uppercase;
}

.brand-name span {
    font-family: var(--font-mono);
  color: rgb(143, 252, 0);
  font-weight: 100;
  font-size: 15px;
}

.brand-tagline {
  font-family: var(--font-body);
  font-size: 13px;
  font-weight: 300;
  color: #ffff;
  line-height: 1.6;
  max-width: 300px;
  margin-bottom: 28px;
}

.brand-stats {
  display: flex;
  gap: 20px;
}

.stat-chip {
  background: rgba(255,136,0,0.1);
  border: 1px solid rgba(255,136,0,0.25);
  border-radius: var(--radius);
  padding: 8px 14px;
  text-align: center;
}
.stat-chip .val {
  font-family: var(--font-display);
  font-weight: 800;
  font-size: 20px;
  color: var(--amber);
  line-height: 1;
}
.stat-chip .lbl {
  font-family: var(--font-mono);
  font-size: 9px;
  color: var(--text-dim);
  letter-spacing: 1px;
  text-transform: uppercase;
  margin-top: 2px;
}

/* ─── RIGHT FORM PANEL ─── */
.form-panel {
  flex: 1;
  background: var(--bg-deep);
  display: flex;
  flex-direction: column;
  overflow-y: auto;
}

.form-panel::-webkit-scrollbar { width: 4px; }
.form-panel::-webkit-scrollbar-thumb { background: var(--border-mid); border-radius: 2px; }

.form-inner {
  flex: 1;
  display: flex;
  flex-direction: column;
  padding: 32px 44px;
  max-width: 500px;
  width: 100%;
  margin: 0 auto;
}

/* Form header */
.form-header {
  margin-bottom: 24px;
  animation: fadeSlideDown 0.5s ease both;
}

.form-header-label {
  font-family: var(--font-mono);
  font-size: 10px;
  color: rgb(179, 255, 0);
  letter-spacing: 3px;
  text-transform: uppercase;
  margin-bottom: 6px;
}

.form-header-title {
  font-family: var(--font-display);
  font-weight: 800;
  font-size: 28px;
  color: var(--text-bright);
  letter-spacing: 0.3px;
  line-height: 1;
}

.form-header-sub {
  font-size: 13px;
  color: var(--text-dim);
  margin-top: 4px;
}

/* ─── TAB NAV ─── */
.tab-nav {
  display: flex;
  background: var(--bg-surface);
  border: 1px solid var(--border-dim);
  border-radius: var(--radius);
  padding: 4px;
  margin-bottom: 22px;
  gap: 3px;
  animation: fadeSlideDown 0.5s 0.05s ease both;
}

.tab-btn {
  flex: 1;
  padding: 9px 16px;
  background: none;
  border: none;
  font-family: var(--font-display);
  font-size: 14px;
  font-weight: 700;
  letter-spacing: 0.8px;
  text-transform: uppercase;
  color: var(--text-dim);
  cursor: pointer;
  border-radius: 4px;
  transition: all 0.2s ease;
}
.tab-btn:hover { color: var(--text-mid); background: var(--bg-raised); }
.tab-btn.active {
  background: linear-gradient(135deg, var(--amber), var(--amber-hot));
  color: white;
  box-shadow: 0 2px 10px rgba(255,136,0,0.35);
}

/* ─── TAB CONTENT ─── */
.tab-content { display: none; animation: fadeSlideDown 0.3s ease both; }
.tab-content.active { display: block; }

/* ─── ALERT MESSAGES ─── */
.msg-box {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 14px;
  border-radius: var(--radius);
  font-size: 13px;
  font-weight: 500;
  margin-bottom: 18px;
  animation: fadeSlideDown 0.4s ease;
}
.msg-box.success {
  background: rgba(95,223,138,0.1);
  border: 1px solid rgba(95,223,138,0.3);
  color: var(--text-green);
}
.msg-box.error {
  background: rgba(255,85,85,0.1);
  border: 1px solid rgba(255,85,85,0.3);
  color: var(--text-red);
}
.msg-box .msg-icon { font-size: 16px; flex-shrink: 0; margin-top: 1px; }
.msg-box .msg-text { flex: 1; line-height: 1.5; }

.error-list {
  list-style: none;
  margin: 6px 0 0;
  padding: 0;
}
.error-list li {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  margin-bottom: 3px;
  color: var(--text-red);
  opacity: 0.9;
}
.error-list li::before { content: '›'; font-size: 14px; font-weight: 700; }

/* ─── FORM GROUPS ─── */
.form-group {
  margin-bottom: 16px;
  position: relative;
}

.form-group label {
  display: block;
  font-family: var(--font-mono);
  font-size: 10px;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  color: var(--text-dim);
  margin-bottom: 6px;
}

.input-wrap {
  position: relative;
}

.input-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 15px;
  pointer-events: none;
  opacity: 0.55;
  z-index: 1;
}

.field {
  width: 100%;
  padding: 10px 12px 10px 40px;
  background: var(--bg-surface);
  border: 1.5px solid var(--border-mid);
  border-radius: var(--radius);
  font-family: var(--font-body);
  font-size: 13px;
  color: var(--text-bright);
  transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
  outline: none;
  appearance: none;
  -webkit-appearance: none;
}
.field::placeholder { color: var(--text-dim); font-size: 12px; }
.field:focus {
  border-color: var(--amber);
  background: var(--bg-raised);
  box-shadow: 0 0 0 3px rgba(255,136,0,0.12);
}
.field.input-error {
  border-color: var(--text-red);
  box-shadow: 0 0 0 3px rgba(255,85,85,0.1);
}
.field option { background: var(--bg-surface); color: var(--text-bright); }

.pw-toggle {
  position: absolute;
  right: 11px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  cursor: pointer;
  color: var(--text-dim);
  font-size: 15px;
  padding: 4px;
  transition: color 0.2s;
  z-index: 2;
}
.pw-toggle:hover { color: var(--amber); }

/* ─── PASSWORD STRENGTH ─── */
.pw-strength-bar {
  height: 3px;
  background: var(--border-dim);
  border-radius: 2px;
  margin-top: 6px;
  overflow: hidden;
}
.pw-strength-fill {
  height: 100%;
  width: 0;
  border-radius: 2px;
  transition: all 0.3s ease;
}
.strength-weak   { background: #ff5555; width: 25%; }
.strength-fair   { background: #ffaa33; width: 50%; }
.strength-good   { background: #4488ff; width: 75%; }
.strength-strong { background: var(--text-green); width: 100%; }

.pw-requirements {
  margin-top: 8px;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 3px 10px;
}
.req {
  font-family: var(--font-mono);
  font-size: 10px;
  display: flex;
  align-items: center;
  gap: 5px;
  color: var(--text-dim);
  transition: color 0.2s;
}
.req.met { color: var(--text-green); }
.req .ri { font-size: 11px; }

/* ─── PASSWORD MATCH ─── */
.pw-match {
  font-family: var(--font-mono);
  font-size: 10px;
  margin-top: 5px;
  letter-spacing: 0.5px;
}

/* ─── FORM OPTIONS ROW ─── */
.options-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 18px;
}
.remember-wrap {
  display: flex;
  align-items: center;
  gap: 7px;
  cursor: pointer;
}
.remember-wrap input[type="checkbox"] {
  width: 15px;
  height: 15px;
  accent-color: var(--amber);
  cursor: pointer;
}
.remember-wrap span {
  font-size: 12px;
  color: var(--text-dim);
  cursor: pointer;
  transition: color 0.2s;
}
.remember-wrap:hover span { color: var(--text-mid); }

/* ─── SUBMIT BUTTON ─── */
.submit-btn {
  width: 60%;
  padding: 12px 20px;
  background: linear-gradient(135deg, var(--amber) 0%, var(--amber-hot) 100%);
  color: white;
  border: none;
  margin-left: 80px;
  border-radius: var(--radius);
  font-family: var(--font-display);
  font-size: 16px;
  font-weight: 700;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  cursor: pointer;
  transition: all 0.2s ease;
  box-shadow: 0 4px 16px rgba(255,136,0,0.3);
  position: relative;
  overflow: hidden;
}
.submit-btn::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.12), transparent);
  transform: translateX(-100%);
  transition: transform 0.4s ease;
}
.submit-btn:hover::after { transform: translateX(100%); }
.submit-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 6px 20px rgba(255,136,0,0.42);
}
.submit-btn:active { transform: translateY(0); }
.submit-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
/* ─── ADMIN BUTTON ─── */
.admin-btn-container {
  position: absolute;
  top: 30px;
  right: 30px;
  z-index: 10;
}

.admin {
  padding: 8px 16px;
  background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
  color: white;
  border: 1px solid rgba(255,255,255,0.15);
  border-radius: 20px;
  font-family: var(--font-display);
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 1px;
  text-transform: uppercase;
  cursor: pointer;
  transition: all 0.2s ease;
  box-shadow: 0 2px 8px rgba(0,0,0,0.2);
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: center;
  gap: 6px;
}

.admin::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.12), transparent);
  transform: translateX(-100%);
  transition: transform 0.4s ease;
}

.admin:hover::after {
  transform: translateX(100%);
}

.admin:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.3);
  background: linear-gradient(135deg, #3a5a70 0%, #2a3a48 100%);
}

.admin:active {
  transform: translateY(0);
}
/* ─── SWITCH TAB LINK ─── */
.switch-link {
  text-align: center;
  margin-top: 20px;
  font-size: 12px;
  color: var(--text-dim);
  font-family: var(--font-mono);
}
.switch-link a {
  color: var(--amber);
  text-decoration: none;
  cursor: pointer;
  font-weight: 600;
  transition: color 0.2s;
}
.switch-link a:hover { color: var(--amber-glow); text-decoration: underline; }

/* ─── DIVIDER ─── */
.form-divider {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 6px 0 18px;
}
.form-divider::before,
.form-divider::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--border-dim);
}
.form-divider span {
  font-family: var(--font-mono);
  font-size: 9px;
  color: var(--text-dim);
  letter-spacing: 2px;
  text-transform: uppercase;
}

/* ─── SCROLLABLE REGISTER FORM ─── */
.scrollable-form {
  max-height: 340px;
  overflow-y: auto;
  padding-right: 6px;
  margin-bottom: 14px;
}
.scrollable-form::-webkit-scrollbar { width: 3px; }
.scrollable-form::-webkit-scrollbar-thumb { background: var(--border-mid); border-radius: 2px; }
.scrollable-form::-webkit-scrollbar-thumb:hover { background: var(--amber-dim); }

/* ─── SECURITY BADGES ─── */
.security-badges {
  display: flex;
  gap: 14px;
  justify-content: center;
  margin-top: 16px;
}
.sec-badge {
  font-family: var(--font-mono);
  font-size: 9px;
  color: var(--text-dim);
  letter-spacing: 0.5px;
  display: flex;
  align-items: center;
  gap: 4px;
}
.sec-badge .dot {
  width: 5px; height: 5px;
  border-radius: 50%;
  background: var(--text-green);
  animation: pulse 2s infinite;
}

/* ═══════════════════════════════════════════════════════
   BOTTOM STATUS BAR (mimics home.php)
═══════════════════════════════════════════════════════ */
.sys-statusbar {
  position: relative;
  z-index: 10;
  background: #111;
  border-top: 1px solid #1a1a1a;
  height: 26px;
  display: flex;
  align-items: center;
  padding: 0 14px;
  gap: 18px;
  flex-shrink: 0;
}
.sys-statusbar span {
  font-family: var(--font-mono);
  font-size: 10px;
  color: var(--text-dim);
  border-right: 1px solid var(--border-dim);
  padding-right: 18px;
}
.sys-statusbar span:last-child { border-right: none; margin-left: auto; }
.sys-statusbar .s-online { color: var(--text-green); font-weight: 700; }
.sys-statusbar .s-offline { color: var(--text-red); font-weight: 700; }

/* ═══════════════════════════════════════════════════════
   ANIMATIONS
═══════════════════════════════════════════════════════ */
@keyframes fadeSlideDown {
  from { opacity: 0; transform: translateY(-10px); }
  to   { opacity: 1; transform: translateY(0); }
}
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}
@keyframes shake {
  0%, 100% { transform: translateX(0); }
  20% { transform: translateX(-5px); }
  60% { transform: translateX(5px); }
}
.shake { animation: shake 0.4s ease; }

/* ═══════════════════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════════════════ */
@media (max-width: 900px) {
  .brand-panel { display: none; }
  .form-inner  { padding: 24px 28px; }
  body { overflow: auto; }
}
@media (max-width: 480px) {
  .form-inner { padding: 20px 16px; }
  .form-header-title { font-size: 22px; }
}
</style>
</head>
<body>

<!-- ══ TOP STATUS BAR ══════════════════════════════════════════ -->
<div class="sys-topbar">
  <div class="logo-block">
    <?php if (!empty($logoPath) && file_exists($logoPath)): ?>
      <img src="<?= htmlspecialchars($logoPath) ?>" alt="Logo" class="logo-img">
    <?php endif; ?>
    <div class="logo-text">
      <span class="brand"><?= htmlspecialchars($businessName) ?></span>
      <span class="sub"><?= htmlspecialchars($businessSubtitle) ?></span>
    </div>
  </div>
  <span class="topbar-title">Terminal Access</span>
  <div class="topbar-spacer"></div>
  <span class="topbar-clock" id="topClock">--:--:-- --</span>
  <span class="topbar-pill" id="connPill">● OFFLINE</span>
</div>

<!-- ══ MAIN PAGE ══════════════════════════════════════════════ -->
<div class="page-wrap">
  <!-- ── LEFT BRANDING ── -->
<div class="brand-panel">
  <div class="brand-bg"></div>
  <div class="brand-overlay"></div>
  <div class="brand-content">
    <div class="admin-btn-container">
      <button type="button" class="admin" onclick="window.location.href='login.php'">
        ← Admin Access
      </button>
    </div>
    <div class="brand-eyebrow">Powered by: St4nger Dev</div>
    <div class="brand-name"><?= htmlspecialchars($businessName) ?><br><span><?= htmlspecialchars($businessAddress) ?></span></div>
    <p class="brand-tagline">Freshly baked goods deserve smart management. From daily sales to bread inventory — your complete POS solution.</p>
    <div class="brand-stats">
      <div class="stat-chip">
        <div class="val">POS</div>
        <div class="lbl">Terminal</div>
      </div>
      <div class="stat-chip">
        <div class="val">v1.0</div>
        <div class="lbl">St4nger Dev</div>
      </div>
      <div class="stat-chip">
        <div class="val">📋</div>
        <div class="lbl">Records</div>
      </div>
    </div>
  </div>
</div>

  <!-- ── RIGHT FORM PANEL ── -->
  <div class="form-panel">
    <div class="form-inner">

      <!-- Header -->
      <div class="form-header">
        <div class="form-header-label">Operator Authentication</div>
        <div class="form-header-title">System Login</div>
        <div class="form-header-sub">Sign in or register to access the terminal</div>
      </div>

      <!-- Global messages -->
      <?php if (!empty($successMessage)): ?>
        <div class="msg-box success alert-msg">
          <span class="msg-icon">✅</span>
          <span class="msg-text"><?= htmlspecialchars($successMessage) ?></span>
        </div>
      <?php endif; ?>
      <?php if (!empty($errorMessage)): ?>
        <div class="msg-box error alert-msg shake">
          <span class="msg-icon">⚠</span>
          <span class="msg-text"><?= htmlspecialchars($errorMessage) ?></span>
        </div>
      <?php endif; ?>

      <!-- Tab Navigation -->
      <div class="tab-nav">
        <button class="tab-btn <?= $activeTab === 'login'    ? 'active' : '' ?>" id="login-tab"    onclick="switchTab('login')">Login</button>
        <button class="tab-btn <?= $activeTab === 'register' ? 'active' : '' ?>" id="register-tab" onclick="switchTab('register')">Register</button>
      </div>

     <!-- ── LOGIN TAB ── -->
<div class="tab-content <?= $activeTab === 'login' ? 'active' : '' ?>" id="login-content">
  <form method="post" id="loginForm" autocomplete="off">

    <div class="form-group">
      <label for="username">Username</label>
      <div class="input-wrap">
        <span class="input-icon">👤</span>
        <input type="text" class="field <?= !empty($errorMessage) ? 'input-error' : '' ?>"
               id="username" name="username" placeholder="Enter your username" required
               value="<?= htmlspecialchars($rememberedUsername ?: ($_POST['username'] ?? '')) ?>">
      </div>
    </div>

    <div class="form-group">
      <label for="password">Password</label>
      <div class="input-wrap">
        <span class="input-icon">🔒</span>
        <input type="password" class="field <?= !empty($errorMessage) ? 'input-error' : '' ?>"
               id="password" name="password" placeholder="Enter your password" required>
        <button type="button" class="pw-toggle" onclick="togglePw('password','toggleIconPw')">
          <span id="toggleIconPw">👁️</span>
        </button>
      </div>
    </div>

    <div class="options-row">
      <label class="remember-wrap">
        <input type="checkbox" name="remember" <?= !empty($rememberedUsername) ? 'checked' : '' ?>>
        <span>Remember me</span>
      </label>
    </div>

    <button type="submit" class="submit-btn">→ Sign In</button>

    <div class="switch-link">
      No account? <a onclick="switchTab('register')">Register here</a>
    </div>

  </form>
</div>
      <!-- ── REGISTER TAB ── -->
      <div class="tab-content <?= $activeTab === 'register' ? 'active' : '' ?>" id="register-content">
        <form method="post" id="registrationForm" novalidate autocomplete="off">
          <input type="hidden" name="registration" value="true">

          <?php if (!empty($regErrorMessages)): ?>
            <div class="msg-box error alert-msg">
              <span class="msg-icon">⚠</span>
              <div class="msg-text">
                <strong>Registration failed</strong>
                <ul class="error-list">
                  <?php foreach ($regErrorMessages as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>
          <?php endif; ?>

          <div class="scrollable-form">

            <div class="form-group">
              <label for="reg_username">Username</label>
              <div class="input-wrap">
                <span class="input-icon">👤</span>
                <input type="text" class="field <?= isset($_POST['registration']) && (empty($regUsername) || strlen($regUsername) < 4) ? 'input-error' : '' ?>"
                       id="reg_username" name="username"
                       placeholder="Min. 4 chars, letters/numbers/_"
                       value="<?= htmlspecialchars($regUsername) ?>"
                       required minlength="4" maxlength="30" pattern="[a-zA-Z0-9_]+">
              </div>
              <div class="pw-match" id="usernameHint" style="color:var(--text-dim);"></div>
            </div>

            <div class="form-group">
              <label for="reg_fullname">Full Name</label>
              <div class="input-wrap">
                <span class="input-icon">📝</span>
                <input type="text" class="field <?= isset($_POST['registration']) && empty($regFullname) ? 'input-error' : '' ?>"
                       id="reg_fullname" name="fullname"
                       placeholder="Enter your full name"
                       value="<?= htmlspecialchars($regFullname) ?>"
                       required minlength="2" maxlength="100">
              </div>
            </div>

            <div class="form-group">
              <label for="reg_email">Email Address</label>
              <div class="input-wrap">
                <span class="input-icon">📧</span>
                <input type="email" class="field <?= isset($_POST['registration']) && empty($regEmail) ? 'input-error' : '' ?>"
                       id="reg_email" name="email"
                       placeholder="Enter your email"
                       value="<?= htmlspecialchars($regEmail) ?>"
                       required maxlength="100">
              </div>
            </div>

            <div class="form-group">
              <label>User Type</label>
              <div class="input-wrap">
                <span class="input-icon">🎖</span>
                <select class="field <?= isset($_POST['registration']) && empty($regType) ? 'input-error' : '' ?>"
                        name="type" required>
                  <option value="">— Select Role —</option>
                  <option value="cashier" <?= $regType === 'cashier' ? 'selected' : '' ?>>Cashier</option>
                  <option value="staff"   <?= $regType === 'staff'   ? 'selected' : '' ?>>Staff</option>
                  <option value="admin"   <?= $regType === 'admin'   ? 'selected' : '' ?>>Administrator</option>
                </select>
              </div>
            </div>

           <div class="form-group">
  <label for="reg_password">Password</label>
  <div class="input-wrap">
    <span class="input-icon">🔒</span>
    <input type="password" class="field" id="reg_password" name="password"
           placeholder="Enter your password (any length/type)"
           required>
    <button type="button" class="pw-toggle" onclick="togglePw('reg_password','toggleIconRegPw')">
      <span id="toggleIconRegPw">👁️</span>
    </button>
  </div>
  <!-- Hide strength meter and requirements -->
  <div class="pw-strength-bar" style="display: none;"><div class="pw-strength-fill" id="pwStrengthFill"></div></div>
  <div class="pw-requirements" style="display: none;">
    <div class="req unmet" id="reqLen">  <span class="ri">⭕</span> 8+ chars</div>
    <div class="req unmet" id="reqUp">   <span class="ri">⭕</span> Uppercase</div>
    <div class="req unmet" id="reqLow">  <span class="ri">⭕</span> Lowercase</div>
    <div class="req unmet" id="reqNum">  <span class="ri">⭕</span> Number</div>
    <div class="req unmet" id="reqSpec"> <span class="ri">⭕</span> Special char</div>
  </div>
</div>
            <div class="form-group">
              <label for="reg_confirm_password">Confirm Password</label>
              <div class="input-wrap">
                <span class="input-icon">🔒</span>
                <input type="password" class="field" id="reg_confirm_password" name="confirm_password"
                       placeholder="Repeat your password" required>
                <button type="button" class="pw-toggle" onclick="togglePw('reg_confirm_password','toggleIconConfPw')">
                  <span id="toggleIconConfPw">👁️</span>
                </button>
              </div>
              <div class="pw-match" id="pwMatchMsg"></div>
            </div>

            <div class="form-group">
              <label for="registration_key">Registration Key</label>
              <div class="input-wrap">
                <span class="input-icon">🔑</span>
                <input type="password" class="field" id="registration_key" name="registration_key"
                       placeholder="Enter authorisation key" required>
              </div>
            </div>

          </div><!-- /scrollable-form -->

          <button type="submit" class="submit-btn" id="submitBtn">→ Create Account</button>

          <div class="security-badges">
            <span class="sec-badge"><span class="dot"></span> Encrypted</span>
            <span class="sec-badge"><span class="dot"></span> Protected</span>
            <span class="sec-badge"><span class="dot"></span> Secure</span>
          </div>

          <div class="switch-link">
            Already have an account? <a onclick="switchTab('login')">Login here</a>
          </div>

        </form>
      </div>
    </div><!-- /form-inner -->
  </div><!-- /form-panel -->

</div><!-- /page-wrap -->

<!-- ══ BOTTOM STATUS BAR ══════════════════════════════════════ -->
<div class="sys-statusbar">
  <span>St4nger POS v1.0</span>
  <span><?= htmlspecialchars($businessName) ?></span>
  <span id="statusDate"><?= date('F j, Y') ?></span>
  <span class="s-offline" id="connStatus">● OFFLINE</span>
</div>

<!-- ═══════════════════════════════════════════════════════════
     JAVASCRIPT
═══════════════════════════════════════════════════════════ -->
<script>
/* ─── Clock ─── */
function updateClock() {
  const now = new Date().toLocaleString('en-US', {
    timeZone: 'Asia/Manila',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true
  });
  document.getElementById('topClock').textContent = now;
}
setInterval(updateClock, 1000); updateClock();

/* ─── Connectivity check ─── */
function checkConn() {
  const pill   = document.getElementById('connPill');
  const status = document.getElementById('connStatus');
  fetch('record/ping.php', { cache: 'no-store' })
    .then(r => {
      const online = r.ok;
      pill.textContent   = online ? '● ONLINE'  : '● OFFLINE';
      pill.style.color   = online ? 'var(--text-green)' : 'var(--text-red)';
      status.textContent = online ? '● ONLINE'  : '● OFFLINE';
      status.className   = online ? 's-online'  : 's-offline';
    })
    .catch(() => {
      pill.textContent   = '● OFFLINE';
      pill.style.color   = 'var(--text-red)';
      status.textContent = '● OFFLINE';
      status.className   = 's-offline';
    });
}
setInterval(checkConn, 15000); checkConn();

/* ─── Tab switching ─── */
function switchTab(name) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.getElementById(name + '-tab').classList.add('active');
  document.getElementById(name + '-content').classList.add('active');
  const url = new URL(window.location);
  url.searchParams.set('tab', name);
  history.replaceState({}, '', url);
  // Focus first input
  const firstInput = document.getElementById(name + '-content').querySelector('input, select');
  if (firstInput) setTimeout(() => firstInput.focus(), 80);
}

/* ─── Password toggle ─── */
function togglePw(fieldId, iconId) {
  const field = document.getElementById(fieldId);
  const icon  = document.getElementById(iconId);
  if (field.type === 'password') { field.type = 'text';     icon.textContent = '🙈'; }
  else                            { field.type = 'password'; icon.textContent = '👁️'; }
}

/* ─── Password match only (no strength meter) ─── */
const pwField = document.getElementById('reg_password');
const confField = document.getElementById('reg_confirm_password');

function checkPwMatch() {
  const pw   = (pwField || {}).value || '';
  const conf = (confField || {}).value || '';
  const msg  = document.getElementById('pwMatchMsg');
  if (!msg) return;
  if (!conf) {
    msg.textContent = '';
    msg.style.color = '';
    if (confField) confField.classList.remove('input-error');
  } else if (pw === conf) {
    msg.textContent = '✓ Passwords match';
    msg.style.color = 'var(--text-green)';
    if (confField) confField.classList.remove('input-error');
  } else {
    msg.textContent = '✗ Passwords do not match';
    msg.style.color = 'var(--text-red)';
    if (confField) confField.classList.add('input-error');
  }
}

if (confField) {
  confField.addEventListener('input', checkPwMatch);
}
function checkPwMatch() {
  const pw   = (document.getElementById('reg_password')         || {}).value || '';
  const conf = (document.getElementById('reg_confirm_password') || {}).value || '';
  const msg  = document.getElementById('pwMatchMsg');
  if (!msg) return;
  if (!conf)          { msg.textContent = ''; msg.style.color = ''; confField.classList.remove('input-error'); }
  else if (pw === conf) { msg.textContent = '✓ Passwords match'; msg.style.color = 'var(--text-green)'; confField.classList.remove('input-error'); }
  else                  { msg.textContent = '✗ Passwords do not match'; msg.style.color = 'var(--text-red)'; confField.classList.add('input-error'); }
}

/* ─── Username live hint ─── */
const regUname = document.getElementById('reg_username');
if (regUname) {
  regUname.addEventListener('input', function() {
    const hint = document.getElementById('usernameHint');
    const v = this.value;
    if (!v) { hint.textContent = ''; return; }
    if (v.length < 4) { hint.textContent = 'Too short'; hint.style.color = 'var(--text-red)'; }
    else if (!/^[a-zA-Z0-9_]+$/.test(v)) { hint.textContent = 'Invalid characters'; hint.style.color = 'var(--text-red)'; }
    else { hint.textContent = 'Looks good'; hint.style.color = 'var(--text-green)'; }
  });
}

/* ─── Registration form submit (simplified) ─── */
const regForm = document.getElementById('registrationForm');
if (regForm) {
  let submitting = false;
  regForm.addEventListener('submit', function(e) {
    if (submitting) { e.preventDefault(); return; }
    
    const btn = document.getElementById('submitBtn');
    const pw = document.getElementById('reg_password').value;
    const conf = document.getElementById('reg_confirm_password').value;
    
    // Only check if password is not empty and matches
    if (!pw || pw !== conf) {
      e.preventDefault();
      if (!pw) {
        const errorBox = document.querySelector('#register-content .msg-box.error');
        if (!errorBox) {
          const box = document.createElement('div');
          box.className = 'msg-box error alert-msg shake';
          box.innerHTML = '<span class="msg-icon">⚠</span><span class="msg-text">Password is required and must match confirmation.</span>';
          regForm.prepend(box);
          setTimeout(() => box.remove(), 4000);
        }
      }
      return;
    }
    
    submitting = true;
    btn.disabled = true;
    btn.textContent = '⏳ Processing…';
    // Reset after submission (will redirect on success anyway)
    setTimeout(() => { 
      submitting = false; 
      btn.disabled = false; 
      btn.textContent = '→ Create Account'; 
    }, 3000);
  });
}
/* ─── Auto-dismiss alerts after 5s ─── */
setTimeout(() => {
  document.querySelectorAll('.alert-msg').forEach(el => {
    el.style.transition = 'opacity 0.5s ease';
    el.style.opacity = '0';
    setTimeout(() => el.remove(), 500);
  });
}, 5000);

/* ─── Remove signup param from URL ─── */
if (window.location.search.includes('signup=success')) {
  history.replaceState({}, document.title, window.location.pathname);
}

/* ─── Auto-focus logic on load ─── */
document.addEventListener('DOMContentLoaded', function() {
  switchTab('<?= $activeTab ?>');
  const uname = document.getElementById('username');
  if (uname && uname.value) {
    const pwEl = document.getElementById('password');
    if (pwEl) pwEl.focus();
  }
});
</script>
</body>
</html>