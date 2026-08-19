<?php
session_start();
require('dbconn.php');

// Detect if we're in iframe mode
$isIframe = isset($_GET['iframe']) && $_GET['iframe'] === 'true';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, username, password, type, fullname FROM new_user WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['loggedin']  = true;
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['user_type'] = $user['type'];
            $_SESSION['fullname']  = $user['fullname'];
            
            // If in iframe, send message to parent
            if ($isIframe) {
                ?>
                <!DOCTYPE html>
                <html>
                <head>
                    <script>
                        // Send message to parent window
                        window.parent.postMessage({
                            action: 'loginSuccess',
                            redirect: 'dashboard.php'
                        }, '*');
                    </script>
                </head>
                <body>
                    <p>Login successful! Redirecting...</p>
                </body>
                </html>
                <?php
                exit();
            } else {
                header("Location: dashboard.php");
                exit();
            }
        }
    }
    $_SESSION['login_error'] = 'Invalid username or password.';
    if ($isIframe) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <script>
                // Send error message to parent
                window.parent.postMessage({
                    action: 'loginError',
                    message: 'Invalid username or password.'
                }, '*');
            </script>
        </head>
        <body>
            <p>Login failed. Please try again.</p>
        </body>
        </html>
        <?php
        exit();
    } else {
        header("Location: login.php");
        exit();
    }
}

$errorMessage = '';
if (isset($_SESSION['login_error'])) {
    $errorMessage = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

// ─── FETCH SYSTEM SETTINGS ─────────────────────────────────────────────────────
$systemSettings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $result->fetch_assoc()) {
    $systemSettings[$row['setting_key']] = $row['setting_value'];
}

// Set defaults if not found
$businessName = $systemSettings['business_name'] ?? 'Angel\'s Bakeshop';
$businessSubtitle = $systemSettings['business_subtitle'] ?? 'POS SYSTEM';
$businessAddress = $systemSettings['business_address'] ?? 'Upper Batinguel, Dumaguete City, Negros Oriental 6200';
$businessPhone = $systemSettings['business_phone'] ?? '0905 615 2262';
$currencySymbol = $systemSettings['currency_symbol'] ?? '₱';
$enableCash = $systemSettings['enable_cash'] ?? '1';
$enableEwallet = $systemSettings['enable_ewallet'] ?? '1';
$receiptFooter = $systemSettings['receipt_footer'] ?? 'Thank you for your purchase!';
$autoPrintReceipt = $systemSettings['auto_print_receipt'] ?? '1';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>St4nger POS – Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800;900&display=swap" rel="stylesheet">
<style>
/* Your existing CSS remains the same */
:root {
  --orange: #ff8800; --orange-dk: #cc5500; --orange-lt: #ffaa44;
  --green: #00c853;  --red: #ff4444;       --yellow: #ffcc00;
  --blue: #4488ff;   --accent: #00bcd4;    --accent-dk: #008fa1;
  --bg: #0d1017;     --bg2: #111520;       --bg3: #161c2a;
  --card: #1a2030;   --card2: #1e2638;
  --border: #252f44; --border2: #2e3b58;
  --text: #e8edf8;   --text2: #8a9ab8;     --text3: #cfc5c5;;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Outfit', sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* ── ANIMATED BACKGROUND ── */
.bg-scene {
  position: fixed; inset: 0; z-index: 0; overflow: hidden;
}
.bg-grid {
  position: absolute; inset: 0;
  background-image:
    linear-gradient(rgba(255,136,0,.04) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,136,0,.04) 1px, transparent 1px);
  background-size: 48px 48px;
}
.bg-glow {
  position: absolute; border-radius: 50%; filter: blur(80px); opacity: .18;
  animation: driftGlow 12s ease-in-out infinite;
}
.glow-1 { width: 600px; height: 600px; background: var(--orange); top: -180px; left: -140px; animation-delay: 0s; }
.glow-2 { width: 500px; height: 500px; background: var(--accent); bottom: -120px; right: -100px; animation-delay: -6s; }
.glow-3 { width: 320px; height: 320px; background: var(--orange-lt); top: 40%; left: 55%; animation-delay: -3s; opacity: .10; }
@keyframes driftGlow {
  0%,100% { transform: translate(0,0) scale(1); }
  33%      { transform: translate(30px,-25px) scale(1.05); }
  66%      { transform: translate(-20px,20px) scale(.95); }
}

/* ── FLOATING PARTICLES ── */
.particles { position: absolute; inset: 0; pointer-events: none; }
.particle {
  position: absolute; border-radius: 50%;
  background: var(--orange-lt); opacity: 0;
  animation: floatUp var(--dur) ease-in infinite;
  animation-delay: var(--delay);
  width: var(--size); height: var(--size);
  left: var(--x);
}
@keyframes floatUp {
  0%   { opacity: 0;   transform: translateY(0)    scale(0); }
  10%  { opacity: .6; }
  80%  { opacity: .2; }
  100% { opacity: 0;   transform: translateY(-80vh) scale(1.2); }
}

/* ── MAIN LAYOUT ── */
.page {
  position: relative; z-index: 1;
  min-height: 100vh;
  display: grid;
  grid-template-columns: 1fr 460px;
}

/* ── LEFT PANEL ── */
.left-panel {
  display: flex; flex-direction: column;
  justify-content: center; align-items: flex-start;
  padding: 60px 70px;
  position: relative;
}
.brand-pill {
  display: inline-flex; align-items: center; gap: 10px;
  background: rgba(255,136,0,.12); border: 1px solid rgba(255,136,0,.25);
  border-radius: 40px; padding: 7px 18px;
  font-size: 11px; font-weight: 700; color: var(--orange-lt);
  letter-spacing: 2px; text-transform: uppercase;
  margin-bottom: 32px;
  animation: fadeSlideUp .6s ease both;
}
.brand-pill .dot { width: 7px; height: 7px; border-radius: 50%; background: var(--orange); box-shadow: 0 0 8px var(--orange); }
.hero-title {
  font-size: 54px; font-weight: 900; line-height: 1.05;
  color: var(--text); margin-bottom: 18px;
  animation: fadeSlideUp .6s .1s ease both;
}
.hero-title span { color: var(--orange); }
.hero-sub {
  font-size: 15px; color: var(--text2); line-height: 1.7;
  max-width: 420px; margin-bottom: 48px;
  animation: fadeSlideUp .6s .2s ease both;
}
.feature-list {
  display: flex; flex-direction: column; gap: 14px;
  animation: fadeSlideUp .6s .3s ease both;
}
.feat-item {
  display: flex; align-items: center; gap: 12px;
  font-size: 13px; color: var(--text2); font-weight: 500;
}
.feat-icon {
  width: 34px; height: 34px; border-radius: 8px; flex-shrink: 0;
  background: var(--card2); border: 1px solid var(--border2);
  display: flex; align-items: center; justify-content: center;
  font-size: 15px;
}
.divider-v {
  position: absolute; top: 10%; bottom: 10%; right: 0; width: 1px;
  background: linear-gradient(180deg, transparent, var(--border2) 30%, var(--border2) 70%, transparent);
}

/* ── RIGHT PANEL (LOGIN CARD) ── */
.right-panel {
  display: flex; align-items: center; justify-content: center;
  padding: 40px 48px; margin-top: -30px;
  background: rgba(13,16,23,.7);
  border-left: 1px solid var(--border);
  backdrop-filter: blur(18px);
  -webkit-backdrop-filter: blur(18px);
}
.login-card {
  width: 100%; max-width: 380px;
  animation: fadeSlideUp .65s .15s ease both;
}
.card-badge {
  display: flex; align-items: center; gap: 8px;
  margin-bottom: 32px;
}
.cb-logo {
  background: linear-gradient(135deg, var(--orange), var(--orange-dk));
  border-radius: 10px; width: 42px; height: 42px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; box-shadow: 0 4px 20px rgba(255,136,0,.35);
}
.cb-text .t1 { font-size: 14px; font-weight: 800; color: var(--text); }
.cb-text .t2 { font-size: 10px; color: var(--text3); font-weight: 500; letter-spacing: 1px; text-transform: uppercase; }

.card-title { font-size: 26px; font-weight: 800; color: var(--text); margin-bottom: 6px; }
.card-sub   { font-size: 12px; color: var(--text3); margin-bottom: 32px; }

/* Error alert */
.alert-error {
  display: none; align-items: center; gap: 9px;
  background: rgba(255,68,68,.1); border: 1px solid rgba(255,68,68,.25);
  border-radius: 8px; padding: 10px 13px;
  font-size: 12px; color: #ff9999; font-weight: 500;
  margin-bottom: 20px;
  animation: shakeX .4s ease;
}
.alert-error.show { display: flex; }
@keyframes shakeX {
  0%,100% { transform: translateX(0); }
  20%,60% { transform: translateX(-6px); }
  40%,80% { transform: translateX(6px); }
}

/* Form */
.form-group { margin-bottom: 18px; position: relative; }
.form-group label {
  display: block; font-size: 10px; font-weight: 700;
  color: var(--text3); text-transform: uppercase; letter-spacing: 1px;
  margin-bottom: 7px;
}
.input-wrap { position: relative; }
.input-icon {
  position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
  font-size: 14px; pointer-events: none; color: var(--text3);
  transition: color .2s;
}
.form-input {
  width: 100%; background: var(--bg3);
  border: 1.5px solid var(--border2);
  color: var(--text); border-radius: 9px;
  padding: 12px 13px 12px 40px;
  font-size: 13px; font-family: 'Outfit', sans-serif;
  transition: all .2s;
}
.form-input:focus {
  outline: none; border-color: var(--orange);
  background: rgba(255,136,0,.04);
  box-shadow: 0 0 0 3px rgba(255,136,0,.1);
}
.form-input:focus + .focus-ring { opacity: 1; }
.form-input::placeholder { color: var(--text3); font-size: 12px; }
.form-group:focus-within .input-icon { color: var(--orange-lt); }

/* Password toggle */
.eye-btn {
  position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer;
  font-size: 14px; color: var(--text3); transition: color .2s; padding: 0;
}
.eye-btn:hover { color: var(--orange-lt); }

/* Submit */
.btn-submit {
  width: 100%; padding: 13px;
  background: linear-gradient(135deg, var(--orange), var(--orange-dk));
  border: none; border-radius: 9px;
  font-family: 'Outfit', sans-serif;
  font-size: 14px; font-weight: 700; color: white;
  cursor: pointer; margin-top: 6px;
  position: relative; overflow: hidden;
  transition: all .2s;
  box-shadow: 0 6px 24px rgba(255,136,0,.3);
}
.btn-submit:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 32px rgba(255,136,0,.45);
}
.btn-submit:active { transform: translateY(0); }
.btn-submit .shimmer {
  position: absolute; inset: 0;
  background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,.15) 50%, transparent 100%);
  transform: translateX(-100%);
  transition: transform .5s ease;
}
.btn-submit:hover .shimmer { transform: translateX(100%); }
.btn-submit.loading { pointer-events: none; opacity: .8; }
.spinner {
  display: none; width: 16px; height: 16px;
  border: 2px solid rgba(255,255,255,.3);
  border-top-color: white; border-radius: 50%;
  animation: spin .7s linear infinite;
  margin: 0 auto;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Footer note */
.card-footer {
  margin-top: 24px; padding-top: 20px;
  border-top: 1px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  gap: 6px; font-size: 11px; color: var(--text3);
}
.sec-badge {
  display: inline-flex; align-items: center; gap: 4px;
  background: rgba(0,200,83,.08); border: 1px solid rgba(0,200,83,.18);
  border-radius: 20px; padding: 2px 9px;
  font-size: 10px; color: #66dd99; font-weight: 600;
}
.sec-dot { width: 5px; height: 5px; border-radius: 50%; background: #00c853; box-shadow: 0 0 5px #00c853; }

/* Status bar */
.status-bar {
  position: fixed; bottom: 0; left: 0; right: 0; z-index: 10;
  background: #070a0f; border-top: 1px solid var(--border);
  height: 24px; display: flex; align-items: center;
  padding: 0 16px; gap: 14px; font-size: 10px; color: var(--text3);
  color: #fff;
}
.s-sep { color: var(--border2); }
.s-conn { display: flex; align-items: center; gap: 4px; margin-left: auto; }
.cdot { width: 5px; height: 5px; border-radius: 50%; }
.online .cdot { background: var(--green); box-shadow: 0 0 5px var(--green); }
.offline .cdot { background: var(--red); }
.online span { color: var(--green); }
.offline span { color: var(--red); }

/* Animations */
@keyframes fadeSlideUp {
  from { opacity: 0; transform: translateY(20px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* Responsive */
@media (max-width: 860px) {
  .page { grid-template-columns: 1fr; }
  .left-panel { display: none; }
  .right-panel {
    min-height: 100vh; border-left: none;
    background: rgba(10,13,20,.92);
  }
}
/* ─── ADMIN BUTTON ─── */
.admin {
  padding: 8px 16px;
  background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
  color: white;
  border: 1px solid rgba(255,255,255,0.15);
  border-radius: 20px;
  font-family: var(--font-display);
  font-size: 12px;
  font-weight: 600;
  margin-top: 20px;
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
</style>
</head>
<body>

<!-- Background -->
<div class="bg-scene">
  <div class="bg-grid"></div>
  <div class="bg-glow glow-1"></div>
  <div class="bg-glow glow-2"></div>
  <div class="bg-glow glow-3"></div>
  <div class="particles" id="particles"></div>
</div>

<div class="page">
  <!-- ── LEFT PANEL ── -->
  <div class="left-panel">
    <div class="brand-pill"><div class="dot"></div><span class="brand"><?= htmlspecialchars($businessName) ?></span> &nbsp;·&nbsp; Admin Portal</div>
    <h1 class="hero-title">Manage your<br><span>Store</span><br>with precision.</h1>
    <p class="hero-sub">A complete POS & inventory management system built for <?= htmlspecialchars($businessName) ?>. Track stock, record sales, manage users, and more — all from one dashboard.</p>
    <div class="feature-list">
      <div class="feat-item"><div class="feat-icon">📦</div>Real-time inventory & stock alerts</div>
      <div class="feat-item"><div class="feat-icon">🧾</div>Point-of-sale & receipt generation</div>
      <div class="feat-item"><div class="feat-icon">📊</div>Sales analytics & export reports</div>
      <div class="feat-item"><div class="feat-icon">🔔</div>Expiry & low-stock notifications</div>
    </div>
    
    <div class="divider-v"></div>
    <button type="button" class="admin" onclick="window.location.href='index.php'">
   ← back to Login
</button>
  </div>

  <!-- ── RIGHT PANEL (LOGIN CARD) ── -->
  <div class="right-panel">
    <div class="login-card">

      <div class="card-badge">
        <div class="cb-logo">🍞</div>
        <div class="cb-text">
          <div class="t1"><?= htmlspecialchars($businessName) ?></div>
          <div class="t2">Admin Management Portal</div>
        </div>
      </div>

      <div class="card-title">Welcome back</div>
      <div class="card-sub">Sign in to access your admin dashboard</div>

      <!-- Error message -->
      <?php if (!empty($errorMessage)): ?>
      <div class="alert-error show" id="alertError">
        <span>⚠</span> <?= htmlspecialchars($errorMessage) ?>
      </div>
      <?php else: ?>
      <div class="alert-error" id="alertError">
        <span>⚠</span> <span id="alertText"></span>
      </div>
      <?php endif; ?>

      <form method="POST" action="login.php<?= $isIframe ? '?iframe=true' : '' ?>" id="loginForm" autocomplete="off">
        <div class="form-group">
          <label for="username">Username</label>
          <div class="input-wrap">
            <span class="input-icon">👤</span>
            <input type="text" id="username" name="username" class="form-input"
                   placeholder="Enter your username" required autocomplete="username">
          </div>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <div class="input-wrap">
            <span class="input-icon">🔒</span>
            <input type="password" id="password" name="password" class="form-input"
                   placeholder="Enter your password" required autocomplete="current-password">
            <button type="button" class="eye-btn" id="eyeBtn" onclick="togglePw()" title="Show/hide password">👁</button>
          </div>
        </div>

        <button type="submit" class="btn-submit" id="submitBtn">
          <span class="shimmer"></span>
          <span id="btnText">Sign In to Dashboard</span>
          <div class="spinner" id="spinner"></div>
        </button>
      </form>

      <div class="card-footer">
        <div class="sec-badge"><div class="sec-dot"></div> Secure Login</div>
        &nbsp;·&nbsp; Protected session &nbsp;·&nbsp; Admin only
      </div>

    </div>
  </div>

</div>

<!-- STATUS BAR -->
<div class="status-bar">
  <span>St4nger POS v1.0</span>
  <span class="s-sep">|</span>
  <span>Login Portal</span>
  <span class="s-sep">|</span>
  <span id="clockBar"></span>
  <div class="s-conn offline" id="connStatus"><div class="cdot"></div><span>OFFLINE</span></div>
</div>

<script>
/* ── Particles ── */
(function(){
  const wrap = document.getElementById('particles');
  for (let i = 0; i < 22; i++) {
    const p = document.createElement('div');
    p.className = 'particle';
    p.style.cssText = `
      --x:${Math.random()*100}%;
      --size:${2+Math.random()*4}px;
      --dur:${6+Math.random()*10}s;
      --delay:${Math.random()*10}s;
      bottom:-10px;
    `;
    if (i % 3 === 1) p.style.background = 'var(--accent-lt)';
    wrap.appendChild(p);
  }
})();

/* ── Clock ── */
function updateClock(){
  document.getElementById('clockBar').textContent =
    new Date().toLocaleString('en-US',{timeZone:'Asia/Manila',weekday:'short',
      year:'numeric',month:'short',day:'numeric',
      hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});
}
setInterval(updateClock, 1000); updateClock();

/* ── Password toggle ── */
function togglePw(){
  const inp = document.getElementById('password');
  const btn = document.getElementById('eyeBtn');
  if (inp.type === 'password') { inp.type = 'text'; btn.textContent = '🙈'; }
  else                         { inp.type = 'password'; btn.textContent = '👁'; }
}

/* ── Form submit loading state ── */
document.getElementById('loginForm').addEventListener('submit', function(){
  const btn  = document.getElementById('submitBtn');
  const txt  = document.getElementById('btnText');
  const spin = document.getElementById('spinner');
  btn.classList.add('loading');
  txt.style.display = 'none';
  spin.style.display = 'block';
});

/* ── Auto-hide error after 5s ── */
const ae = document.getElementById('alertError');
if (ae && ae.classList.contains('show')) {
  setTimeout(() => { ae.style.opacity = '0'; ae.style.transition = 'opacity .5s'; }, 5000);
}

/* ── Connectivity ── */
function checkConn(){
  fetch('record/ping.php', {cache:'no-store'})
    .then(r => {
      const el = document.getElementById('connStatus');
      el.className = r.ok ? 's-conn online' : 's-conn offline';
      el.querySelector('span').textContent = r.ok ? 'ONLINE' : 'OFFLINE';
    })
    .catch(() => {
      const el = document.getElementById('connStatus');
      el.className = 's-conn offline';
      el.querySelector('span').textContent = 'OFFLINE';
    });
}
setInterval(checkConn, 15000); checkConn();

/* ── Handle iframe messages (for error display) ── */
window.addEventListener('message', function(e) {
  if (e.data.action === 'loginError') {
    const alertDiv = document.getElementById('alertError');
    const alertText = document.getElementById('alertText');
    if (alertText) alertText.textContent = e.data.message;
    alertDiv.classList.add('show');
    
    // Reset button state
    const btn = document.getElementById('submitBtn');
    const txt = document.getElementById('btnText');
    const spin = document.getElementById('spinner');
    btn.classList.remove('loading');
    txt.style.display = 'block';
    spin.style.display = 'none';
    
    setTimeout(() => {
      alertDiv.classList.remove('show');
    }, 5000);
  }
});
</script>
</body>
</html>