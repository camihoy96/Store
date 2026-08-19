<?php
// include/header.php
session_start();

if (!isset($_SESSION['loggedin'])) { header("Location: index.php"); exit; }

require_once __DIR__ . '/../dbconn.php';

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
$logoPath         = $systemSettings['logo_path']           ?? '';

// ─── FETCH PAYMENT METHODS ─────────────────────────────────────────────────────
$paymentMethods = [];
$result = $conn->query("SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY display_order ASC");
while ($row = $result->fetch_assoc()) {
    $paymentMethods[] = $row;
}

// ─── CASHIER ───────────────────────────────────────────────────────────────────
$cashierName = isset($_SESSION['fullname'])
    ? htmlspecialchars($_SESSION['fullname'])
    : 'Default Cashier';

date_default_timezone_set('Asia/Manila');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>St4nger POS</title>
<script src="js/sweetalert2.min.js"></script>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  font-size: 13px;
  background: #1a1a1a;
  color: #f0f0f0;
  height: 100vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  user-select: none;
}

/* ══ TOP BAR ══════════════════════════════════════════════════════════ */
.top-bar {
  background: linear-gradient(180deg, #3a3a3a 0%, #2a2a2a 100%);
  display: flex; align-items: center;
  padding: 0 8px; height: 42px;
  border-bottom: 2px solid #111;
  flex-shrink: 0; gap: 6px;
}
.logo-block {
  background: linear-gradient(135deg, #ff8800, #ff6000);
  border-radius: 6px; padding: 4px 12px;
  display: flex; 
  flex-direction: row;
  align-items: center;
  gap: 8px;
  line-height: 1.1;
  min-height: 32px;
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
  font-weight: 900; 
  font-size: 13px; 
  color: white; 
  letter-spacing: 0.5px; 
  margin-bottom: 1px;
}
.logo-block .sub   { 
  font-size: 9px; 
  color: rgba(255,255,255,0.85); 
  letter-spacing: 1.5px; 
  font-weight: 600; 
}
.top-clock { color: #ffcc66; font-weight: 700; font-size: 12px; margin-left: 15px; }
.top-spacer { flex: 1; }
.top-icon-group { display: flex; gap: 3px; }
.top-icon {
  width: 34px; height: 30px;
  background: linear-gradient(180deg, #e27272, #724a4a);
  border: 1px solid #666; border-radius: 4px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; color: #ccc; transition: all 0.15s;
  padding: 0;
}
.top-icon svg { width: 16px; height: 16px; fill: currentColor; }
.top-icon:hover { background: linear-gradient(180deg, #ff8800, #cc5500); color: white; border-color: #ff8800; }
.menu-btn {
  background: linear-gradient(180deg, #555, #3a3a3a);
  border: 1px solid #666; border-radius: 4px;
  color: white; cursor: pointer;
  width: 34px; height: 30px;
  display: flex; align-items: center; justify-content: center;
  transition: all 0.15s;
  padding: 0;
}
.menu-btn svg { width: 18px; height: 18px; fill: currentColor; }
.menu-btn:hover { background: linear-gradient(180deg, #ff8800, #cc5500); border-color: #ff8800; }

/* ══ SIDEBAR ══════════════════════════════════════════════════════════ */
.sidebar {
  width: 220px; background: #1c2a1e;
  position: fixed; top: 42px; left: 0;
  height: calc(100vh - 42px - 28px);
  display: none; flex-direction: column;
  z-index: 800; box-shadow: 3px 0 12px rgba(0,0,0,0.5);
  border-right: 1px solid #2a4030;
}
.sidebar a {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px; color: #aaccb0; text-decoration: none;
  font-size: 13px; border-bottom: 1px solid #1e3022;
  transition: all 0.15s;
}
.sidebar a svg { width: 16px; height: 16px; fill: currentColor; flex-shrink: 0; }
.sidebar a:hover { background: #ff8800; color: white; }

/* ══ CART PROTECTION ═══════════════════════════════════════════════ */
.top-icon.disabled, .menu-btn.disabled, .sidebar a.disabled {
  opacity: 0.4;
  pointer-events: none;
  cursor: not-allowed;
  filter: grayscale(1);
}

.cart-warning-badge {
  position: fixed;
  top: 50px;
  right: 20px;
  background: linear-gradient(135deg, #ff4444, #cc0000);
  color: white;
  padding: 10px 20px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 700;
  z-index: 9999;
  box-shadow: 0 4px 15px rgba(255,0,0,0.3);
  animation: slideIn 0.3s ease, pulse 2s infinite;
  display: none;
  max-width: 300px;
}

@keyframes slideIn {
  from { transform: translateX(100%); opacity: 0; }
  to { transform: translateX(0); opacity: 1; }
}

@keyframes pulse {
  0%, 100% { box-shadow: 0 4px 15px rgba(255,0,0,0.3); }
  50% { box-shadow: 0 4px 25px rgba(255,0,0,0.6); }
}

.cart-warning-badge .close-warning {
  cursor: pointer;
  float: right;
  margin-left: 10px;
  font-weight: 900;
  opacity: 0.8;
}

.cart-warning-badge .close-warning:hover { opacity: 1; }

/* Modal overlay for cart confirmation */
.confirm-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.7);
  display: none;
  align-items: center;
  justify-content: center;
  z-index: 10000;
}

.confirm-overlay.show {
  display: flex;
}

.confirm-box {
  background: linear-gradient(180deg, #3a3a3a, #2a2a2a);
  border: 2px solid #ff8800;
  border-radius: 8px;
  padding: 24px;
  width: 400px;
  max-width: 90vw;
  box-shadow: 0 8px 32px rgba(0,0,0,0.5);
  cursor: grab;
  position: relative;
}

.confirm-box:active {
  cursor: grabbing;
}

.confirm-box h3 {
  color: #ff8800;
  margin-bottom: 12px;
  font-size: 16px;
  display: flex;
  align-items: center;
  gap: 8px;
  pointer-events: none;
}

.confirm-box h3 svg { width: 20px; height: 20px; fill: #ff8800; }

.confirm-box p {
  color: #ccc;
  font-size: 13px;
  margin-bottom: 20px;
  line-height: 1.5;
  pointer-events: none;
}

.confirm-btns {
  display: flex;
  gap: 10px;
}

.confirm-btn {
  flex: 1;
  padding: 10px;
  border: none;
  border-radius: 5px;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  color: white;
  transition: filter 0.15s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}

.confirm-btn svg { width: 14px; height: 14px; fill: currentColor; }

.confirm-btn:hover { filter: brightness(1.15); }

.confirm-btn.stay {
  background: linear-gradient(180deg, #ff9900, #cc6600);
}

.confirm-btn.void {
  background: linear-gradient(180deg, #cc0000, #990000);
}

/* ══ LOGOUT MODAL ═══════════════════════════════════════════════ */
.logout-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.7);
  display: none;
  align-items: center;
  justify-content: center;
  z-index: 10001;
}

.logout-modal-overlay.show {
  display: flex;
}

.logout-modal {
  background: #282828;
  border: 2px solid #ff8800;
  border-radius: 12px;
  padding: 30px;
  width: 400px;
  max-width: 90vw;
  box-shadow: 0 20px 60px rgba(0,0,0,0.7);
  text-align: center;
  animation: logoutIn 0.3s ease;
  cursor: grab;
  position: relative;
}

.logout-modal:active {
  cursor: grabbing;
}

@keyframes logoutIn {
  from { opacity: 0; transform: scale(0.9); }
  to { opacity: 1; transform: scale(1); }
}

.logout-modal .lm-icon {
  width: 70px;
  height: 70px;
  margin: 0 auto 20px;
  background: linear-gradient(135deg, #ff4444, #cc0000);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
}

.logout-modal .lm-icon svg {
  width: 35px;
  height: 35px;
  fill: white;
}

.logout-modal h3 {
  color: #f0f0f0;
  font-size: 20px;
  font-weight: 800;
  margin-bottom: 10px;
  pointer-events: none;
}

.logout-modal p {
  color: #aaa;
  font-size: 13px;
  line-height: 1.6;
  margin-bottom: 25px;
  pointer-events: none;
}

.logout-modal p strong {
  color: #ffcc66;
}

.logout-modal .lm-btns {
  display: flex;
  gap: 10px;
}

.logout-modal .lm-btn {
  flex: 1;
  padding: 12px;
  border: none;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.15s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.logout-modal .lm-btn svg {
  width: 16px;
  height: 16px;
  fill: currentColor;
}

.logout-modal .lm-btn:hover {
  filter: brightness(1.1);
  transform: translateY(-1px);
}

.logout-modal .lm-btn.cancel {
  background: #444;
  color: #ddd;
}

.logout-modal .lm-btn.confirm {
  background: linear-gradient(135deg, #ff4444, #cc0000);
  color: white;
}

/* ══ GENERIC DRAGGABLE MODAL STYLES ═══════════════════════════════ */
.draggable-modal {
  cursor: grab;
  position: relative;
}

.draggable-modal:active {
  cursor: grabbing;
}

.draggable-modal .drag-handle {
  cursor: grab;
}

.draggable-modal .drag-handle:active {
  cursor: grabbing;
}
</style>
</head>
<body>
<!-- CONFIRMATION MODAL FOR NAVIGATION -->
<div class="confirm-overlay" id="navConfirmModal">
  <div class="confirm-box draggable-modal" id="navConfirmBox">
    <h3>
      <svg viewBox="0 0 24 24"><path d="M12 2L1 21h22L12 2zm0 6l7.53 12H4.47L12 8zm-1 3v4h2v-4h-2zm0 6v2h2v-2h-2z"/></svg>
      Cart Contains Items
    </h3>
    <p>Your cart has <strong id="navCartCount">0</strong> item(s). Navigating away will <strong style="color:#ff4444;">VOID</strong> these items.<br><br>
    What would you like to do?</p>
    <div class="confirm-btns">
      <button class="confirm-btn stay" onclick="cancelNavigation()">
        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
        Stay & Complete Order
      </button>
      <button class="confirm-btn void" onclick="confirmNavigation()">
        <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        Void & Continue
      </button>
    </div>
  </div>
</div>

<!-- LOGOUT CONFIRMATION MODAL -->
<div class="logout-modal-overlay" id="logoutModal">
  <div class="logout-modal draggable-modal" id="logoutModalBox">
    <div class="lm-icon">
      <svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
    </div>
    <h3>Confirm Logout</h3>
    <p>Are you sure you want to logout from <strong><?= htmlspecialchars($businessName) ?></strong>?<br>
    You will need to login again to access the POS system.</p>
    <div class="lm-btns">
      <button class="lm-btn cancel" onclick="closeLogoutModal()">
        <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        Cancel
      </button>
      <button class="lm-btn confirm" onclick="confirmLogout()">
        <svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
        Logout
      </button>
    </div>
  </div>
</div>

<!-- TOP BAR -->
<div class="top-bar">
  <button class="menu-btn" id="menuBtn" onclick="toggleSidebar()">
    <svg viewBox="0 0 24 24"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
  </button>
  <div class="logo-block">
    <?php if (!empty($logoPath) && file_exists(__DIR__ . '/../' . $logoPath)): ?>
      <img src="../<?= htmlspecialchars($logoPath) ?>" alt="Logo" class="logo-img">
    <?php endif; ?>
    <div class="logo-text">
      <span class="brand"><?= htmlspecialchars($businessName) ?></span>
      <span class="sub"><?= htmlspecialchars($businessSubtitle) ?></span>
    </div>
  </div>
  <span class="top-clock" id="currentTime"></span>
  <div style="font-size:10px; margin-left:45px;"><?= htmlspecialchars($businessAddress) ?></div>
  <div class="top-spacer"></div>
  <div class="top-icon-group">
    <div class="top-icon" title="Home" onclick="location.href='../home.php'">
      <svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
    </div>
    <div class="top-icon" title="Records" onclick="location.href='../manage/transaction.php'">
      <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
    </div>
    <div class="top-icon" title="Profile" onclick="location.href='../profile/prof.php'">
      <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
    </div>
    <div class="top-icon" title="Bread Left" onclick="location.href='../manage/remain.php'">
      <svg viewBox="0 0 24 24"><path d="M20 5H4c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 12H4V7h16v10zM6 9h2v2H6V9zm0 4h2v2H6v-2zm4-4h2v2h-2V9zm0 4h2v2h-2v-2zm4-4h2v2h-2V9zm0 4h2v2h-2v-2z"/></svg>
    </div>
    <div class="top-icon" title="Logout" onclick="openLogoutModal()">
      <svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
    </div>
  </div>
</div>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
  <a href="../home.php">
    <svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
    Home
  </a>
  <a href="../manage/transaction.php">
    <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
    Manage Records
  </a>
  <a href="../profile/prof.php">
    <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
    Manage Profile
  </a>
  <a href="../manage/remain.php">
    <svg viewBox="0 0 24 24"><path d="M20 5H4c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 12H4V7h16v10zM6 9h2v2H6V9zm0 4h2v2H6v-2zm4-4h2v2h-2V9zm0 4h2v2h-2v-2zm4-4h2v2h-2V9zm0 4h2v2h-2v-2z"/></svg>
    Record Bread Left
  </a>
  <a href="#" onclick="event.preventDefault(); openLogoutModal();">
    <svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
    Logout
  </a>
</div>

<div class="cart-warning-badge" id="cartWarning" style="display:none;">
  <span class="close-warning" onclick="dismissWarning()">✕</span>
  ⚠️ Complete or void your current order before navigating away!
</div>

<script>
// ─── DRAG FUNCTIONALITY FOR MODALS ────────────────────────────────
function makeDraggable(modalElement, handleElement) {
  let isDragging = false;
  let startX, startY, initialX, initialY;
  
  const dragHandle = handleElement || modalElement;
  
  dragHandle.addEventListener('mousedown', function(e) {
    // Don't initiate drag if clicking on a button or input
    if (e.target.closest('button') || e.target.closest('input') || e.target.closest('select') || e.target.closest('textarea')) {
      return;
    }
    
    isDragging = true;
    
    // Get current position
    const rect = modalElement.getBoundingClientRect();
    startX = e.clientX;
    startY = e.clientY;
    initialX = rect.left;
    initialY = rect.top;
    
    // Set initial position if not already set
    if (!modalElement.style.position || modalElement.style.position === 'relative') {
      modalElement.style.position = 'fixed';
      modalElement.style.left = initialX + 'px';
      modalElement.style.top = initialY + 'px';
      modalElement.style.margin = '0';
    }
    
    dragHandle.style.cursor = 'grabbing';
    e.preventDefault();
  });
  
  document.addEventListener('mousemove', function(e) {
    if (!isDragging) return;
    
    const deltaX = e.clientX - startX;
    const deltaY = e.clientY - startY;
    
    modalElement.style.left = (initialX + deltaX) + 'px';
    modalElement.style.top = (initialY + deltaY) + 'px';
  });
  
  document.addEventListener('mouseup', function() {
    if (isDragging) {
      isDragging = false;
      dragHandle.style.cursor = 'grab';
    }
  });
}

// ─── Initialize drag for all modals ───────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  // Make cart confirmation modal draggable
  const navConfirmBox = document.getElementById('navConfirmBox');
  if (navConfirmBox) {
    makeDraggable(navConfirmBox, navConfirmBox);
  }
  
  // Make logout modal draggable
  const logoutModalBox = document.getElementById('logoutModalBox');
  if (logoutModalBox) {
    makeDraggable(logoutModalBox, logoutModalBox);
  }
  
  // Make any other modals draggable
  document.querySelectorAll('.modal-box, .pay-modal, .err-modal').forEach(function(modal) {
    if (modal.id !== 'navConfirmBox' && modal.id !== 'logoutModalBox') {
      makeDraggable(modal, modal);
    }
  });
});

// ─── Logout modal functions ───────────────────────────────────────
function openLogoutModal() {
  document.getElementById('logoutModal').classList.add('show');
}

function closeLogoutModal() {
  document.getElementById('logoutModal').classList.remove('show');
  // Reset position when closing
  const modalBox = document.getElementById('logoutModalBox');
  if (modalBox) {
    modalBox.style.position = '';
    modalBox.style.left = '';
    modalBox.style.top = '';
    modalBox.style.margin = '';
  }
}

function confirmLogout() {
  window.location.href = 'logout.php';
}

// Close logout modal when clicking outside
document.getElementById('logoutModal').addEventListener('click', function(e) {
  if (e.target === this) {
    closeLogoutModal();
  }
});

// Close logout modal with Escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape' && document.getElementById('logoutModal').classList.contains('show')) {
    closeLogoutModal();
  }
});
</script>