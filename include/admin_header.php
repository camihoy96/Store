<?php
// include/admin_header.php
// Only start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../dbconn.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: access_denied.php"); exit();
}
date_default_timezone_set('Asia/Manila');

// Fetch system settings
$systemSettings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $result->fetch_assoc()) {
    $systemSettings[$row['setting_key']] = $row['setting_value'];
}

// Set defaults if not found
$businessName = $systemSettings['business_name'] ?? 'St4nger POS';
$businessSubtitle = $systemSettings['business_subtitle'] ?? 'POS SYSTEM';
$businessAddress = $systemSettings['business_address'] ?? 'Dumaguete City, Negros Oriental 6200';
$businessPhone = $systemSettings['business_phone'] ?? '0905 615 2262';
$currencySymbol = $systemSettings['currency_symbol'] ?? '₱';
$logoPath = $systemSettings['logo_path'] ?? '';

// Page title - can be overridden before including this file
$pageTitle = $pageTitle ?? 'Dashboard';

// Determine the correct base path for assets based on current file location
// Get the current script path relative to the root
$scriptPath = $_SERVER['SCRIPT_NAME'];
// Remove the filename to get the directory path
$scriptDir = dirname($scriptPath);
// Count how many levels deep we are from the root
$depth = substr_count(trim($scriptDir, '/'), '/');
// Build the relative path to root
$relativePath = str_repeat('../', $depth);
// If we're at root level, use empty string
if ($depth === 0) {
    $relativePath = '';
}

// For logo path - use absolute path from root
$logoFullPath = !empty($logoPath) ? '/Store/' . $logoPath : '';

// For other assets (CSS, JS) - use relative path
$assetPath = $relativePath;

// Determine active page for highlighting
$currentPage = basename($_SERVER['SCRIPT_NAME']);
$currentDir = basename(dirname($_SERVER['SCRIPT_NAME']));

// Map current file to active page identifier
$activePage = $activePage ?? '';
if (empty($activePage)) {
    // Auto-detect based on current file
    if ($currentPage === 'dashboard.php') {
        $activePage = 'dashboard';
    } elseif ($currentPage === 'user.php' && $currentDir === 'admin') {
        $activePage = 'users';
    } elseif ($currentPage === 'prof.php' && $currentDir === 'admin') {
        $activePage = 'profile';
    } elseif ($currentPage === 'settings.php' && $currentDir === 'admin') {
        $activePage = 'settings';
    } elseif ($currentPage === 'product.php' && $currentDir === 'product') {
        $activePage = 'products';
    } elseif ($currentPage === 'item_reserve.php' && $currentDir === 'product') {
        $activePage = 'reserve';
    } elseif ($currentPage === 'bread.php' && $currentDir === 'product') {
        $activePage = 'breads';
    } elseif ($currentPage === 'bleft.php' && $currentDir === 'admin') {
        $activePage = 'bleft';
    } elseif ($currentPage === 'record.php' && $currentDir === 'record') {
        $activePage = 'records';
    }
}

// Helper function to check if a page is active
function isActive($pageId) {
    global $activePage;
    return $activePage === $pageId ? 'active' : '';
}

// Helper function to check if a page is the current page
function isCurrentPage($href) {
    $currentUrl = $_SERVER['REQUEST_URI'];
    // Remove query string
    $currentPath = strtok($currentUrl, '?');
    // Check if the href matches the current path
    return $currentPath === $href || $currentPath === '/Store' . $href;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> –St4nger POS</title>

<!-- ===== FAVICON ===== -->
<!-- Using absolute paths with leading slash for root-relative URLs -->
<!-- This ensures it works from any subdirectory: /, /admin/, /record/, /product/ etc. -->
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

<link rel="stylesheet" href="<?= $assetPath ?>css/bootstrap-icons.css">
<script src="js/chart.js"></script>
<script src="<?= $assetPath ?>js/sweetalert2.all.min.js"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');
:root {
  --orange:     #ff8800;
  --orange-dk:  #cc5500;
  --orange-lt:  #ffaa33;
  --green:      #00c853;
  --green-dk:   #009624;
  --red:        #ff4444;
  --blue:       #4488ff;
  --yellow:     #ffcc00;
  --bg:         #111318;
  --bg2:        #181c22;
  --bg3:        #1e2330;
  --card:       #1e2330;
  --card2:      #242b3a;
  --border:     #2a3145;
  --border2:    #323d55;
  --text:       #e8eaf0;
  --text2:      #9aa3bc;
  --text3:      #f7f7f9;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Inter', 'Segoe UI', sans-serif;
  font-size: 13px;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  display: flex; flex-direction: column;
}

/* ═══════════════════════════════════ TOP BAR */
.top-bar {
  height: 52px;
  background: linear-gradient(90deg, #0d1117 0%, #161b27 100%);
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; padding: 0 16px; gap: 10px;
  position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
  box-shadow: 0 1px 20px rgba(0,0,0,0.6);
}

.logo-pill {
  background: linear-gradient(135deg, var(--orange), #ff4400);
  border-radius: 8px; padding: 5px 14px;
  display: flex; 
  flex-direction: row;
  align-items: center;
  gap: 10px;
  box-shadow: 0 0 20px rgba(255,136,0,0.3);
  transition: all 0.3s ease;
  min-height: 40px;
}

.logo-pill .logo-img {
   height: 60px;
  width: auto;
  max-width: 120px;
  display: block;
  border-radius: 3px;
  flex-shrink: 0;
  object-fit: contain;
}

.logo-pill .logo-text {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  line-height: 1.2;
}

.logo-pill .lp-name { 
  font-weight: 800; font-size: 11px; color: white; letter-spacing: 0.3px;
}
.logo-pill .lp-sub  { 
  font-size: 7px; color: rgba(255,255,255,0.75); letter-spacing: 2px; 
  font-weight: 600; text-transform: uppercase;
}

.tb-divider { width: 1px; height: 24px; background: var(--border2); margin: 0 4px; }

.tb-title { font-size: 14px; font-weight: 700; color: var(--text); letter-spacing: 0.2px; }
.tb-clock { font-size: 11px; color: var(--orange-lt); font-weight: 600; font-variant-numeric: tabular-nums; }

.tb-spacer { flex: 1; }

.tb-badge {
  display: flex; align-items: center; gap: 6px;
  background: var(--bg3); border: 1px solid var(--border);
  border-radius: 20px; padding: 4px 12px 4px 8px;
  font-size: 11px; color: var(--text2); cursor: default;
}
.tb-badge .dot { width: 7px; height: 7px; border-radius: 50%; background: var(--green); box-shadow: 0 0 6px var(--green); }
.tb-badge.warn .dot { background: var(--red); box-shadow: 0 0 6px var(--red); }

.menu-btn {
  background: var(--bg3); border: 1px solid var(--border);
  border-radius: 6px; color: var(--text2); font-size: 16px;
  cursor: pointer; width: 34px; height: 34px;
  display: flex; align-items: center; justify-content: center;
  transition: all 0.15s;
}
.menu-btn:hover { background: var(--orange); border-color: var(--orange); color: white; }

.tb-icon {
  width: 34px; height: 34px;
  background: #ffffff; border: 1px solid var(--border);
  border-radius: 6px; display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: 14px; text-decoration: none; color: var(--text2);
  transition: all 0.15s; position: relative;
}
.tb-icon:hover { background: var(--orange); border-color: var(--orange); color: white; }
.tb-icon .notif-dot {
  position: absolute; top: 5px; right: 5px;
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--red); border: 1px solid var(--bg);
  box-shadow: 0 0 5px var(--red);
}

/* Active top bar icon */
.tb-icon.active {
  background: #78f701;
  border-color: var(--orange);
  color: white;
  box-shadow: 0 0 15px rgb(255, 255, 255);
}

/* ═══════════════════════════════════ SIDEBAR */
.sidebar {
  width: 240px; background: linear-gradient(180deg, #0f1419 0%, #111822 100%);
  position: fixed; top: 52px; left: 0;
  height: calc(100vh - 52px - 26px);
  display: none; flex-direction: column;
  z-index: 800;
  border-right: 1px solid var(--border);
  box-shadow: 4px 0 20px rgba(0,0,0,0.4);
  overflow-y: auto;
}
.sidebar::-webkit-scrollbar { width: 3px; }
.sidebar::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 2px; }

.sb-section-label {
  font-size: 9px; font-weight: 700; color: var(--text3);
  text-transform: uppercase; letter-spacing: 1.5px;
  padding: 14px 16px 6px;
}

.sb-group-btn {
  width: 100%; background: transparent; border: none;
  color: var(--text2); padding: 10px 16px; text-align: left; cursor: pointer;
  font-size: 12px; font-weight: 600;
  display: flex; align-items: center; gap: 10px;
  transition: all 0.15s; border-left: 3px solid transparent;
}
.sb-group-btn .sb-ico { font-size: 15px; width: 18px; text-align: center; }
.sb-group-btn .arrow  { margin-left: auto; font-size: 10px; transition: transform 0.2s; color: var(--text3); }
.sb-group-btn:hover   { background: rgba(255,136,0,0.08); color: var(--orange-lt); border-left-color: var(--orange); }
.sb-group-btn.open    { color: var(--orange-lt); border-left-color: var(--orange); }
.sb-group-btn.open .arrow { transform: rotate(90deg); }

.sb-sub { display: none; flex-direction: column; }
.sb-sub.open { display: flex; }
.sb-sub a {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 16px 8px 44px; color: var(--text3);
  text-decoration: none; font-size: 11px; font-weight: 500;
  border-left: 3px solid transparent;
  transition: all 0.15s;
}
.sb-sub a:hover { background: rgba(255,136,0,0.08); color: var(--orange-lt); border-left-color: var(--orange); }

/* Active sidebar link */
.sb-sub a.active {
  background: rgba(255,136,0,0.12);
  color: var(--orange-lt);
  border-left-color: var(--orange);
  font-weight: 600;
}

.sb-divider { height: 1px; background: var(--border); margin: 6px 14px; }

/* ═══════════════════════════════════ MAIN */
.main { margin-top: 52px; padding: 20px; flex: 1; }

/* ═══════════════════════════════════ STATUS BAR */
.status-bar {
  background: #0a0d14; border-top: 1px solid var(--border);
  padding: 0 14px; height: 26px;
  display: flex; align-items: center; gap: 16px;
  font-size: 10px; color: var(--text3); flex-shrink: 0;
}
.status-bar .sb-sep { color: var(--border2); }
.sb-conn { display: flex; align-items: center; gap: 4px; margin-left: auto; }
.sb-conn .cdot { width: 6px; height: 6px; border-radius: 50%; }
.sb-conn.online  .cdot { background: var(--green); box-shadow: 0 0 5px var(--green); }
.sb-conn.offline .cdot { background: var(--red);   box-shadow: 0 0 5px var(--red); }
.sb-conn.online  span  { color: var(--green); }
.sb-conn.offline span  { color: var(--red); }

.footer { text-align: center; padding: 7px; background: #0a0d14; color: var(--text3); font-size: 10px; border-top: 1px solid var(--border); }

/* Disabled link styles */
.tb-icon.disabled {
  opacity: 0.6;
  cursor: default;
  pointer-events: none;
}
.tb-icon.disabled:hover {
  background: var(--bg3);
  border-color: var(--border);
  color: var(--text2);
}
.sb-sub a.disabled {
  opacity: 0.6;
  cursor: default;
  pointer-events: none;
}
.sb-sub a.disabled:hover {
  background: transparent;
  color: var(--text3);
  border-left-color: transparent;
}
/* Top icon image styling */
.tb-icon img {
  width: 22px;
  height: 22px;
  object-fit: contain;
  transition: filter 0.15s;
}
.tb-icon:hover img {
}
.tb-icon.active img {
}
/* ═══════════════════════════════════ LOGOUT MODAL ═══════════════════════════════════ */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.7);
  display: none;
  align-items: center;
  justify-content: center;
  z-index: 10001;
}

.modal-overlay.show {
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
  font-size: 35px;
  pointer-events: none;
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
/* Sidebar icon styling */
.sb-group-btn .sb-ico img {
  width: 20px;
  height: 20px;
  object-fit: contain;
  filter: brightness(0.8) invert(0.8);
  transition: filter 0.15s;
}
.sb-group-btn:hover .sb-ico img,
.sb-group-btn.open .sb-ico img {
  filter: brightness(1) invert(1);
}

.sb-sub a img {
  width: 18px;
  height: 18px;
  object-fit: contain;
  filter: brightness(0.6) invert(0.6);
  transition: filter 0.15s;
}
.sb-sub a:hover img,
.sb-sub a.active img {
  filter: brightness(1) invert(1);
}
</style>
</head>
<body>

<!-- ═══════════════════════════════════ TOP BAR -->
<div class="top-bar">
  <button class="menu-btn" id="menuBtn" onclick="toggleSidebar()">☰</button>
  
  <div class="logo-pill">
    <?php if (!empty($logoPath) && file_exists(__DIR__ . '/../' . $logoPath)): ?>
      <img src="/Store/<?= htmlspecialchars($logoPath) ?>" alt="Logo" class="logo-img">
    <?php endif; ?>
    <div class="logo-text">
      <span class="lp-name"><?= htmlspecialchars($businessName) ?></span>
      <span class="lp-sub"><?= htmlspecialchars($businessSubtitle) ?></span>
    </div>
  </div>
  
  <div class="tb-divider"></div>
  <span class="tb-title" id="topBarTitle"><?= htmlspecialchars($pageTitle) ?></span>
  <div class="tb-divider"></div>
  <span class="tb-clock" id="currentTime"></span>
  <div style="font-size:10px; margin-left:45px;"><?= htmlspecialchars($businessAddress) ?></div>
  <div class="tb-spacer"></div>

  <?php if(isset($lowStockCount) && isset($expiringCount) && ($lowStockCount>0||$expiringCount>0)): ?>
  <div class="tb-badge warn" onclick="openAlertModal()" style="cursor:pointer;" title="View alerts">
    <div class="dot"></div>
    <span><?= $lowStockCount+$expiringCount ?> Alerts</span>
  </div>
  <?php endif; ?>
  <!-- Top Bar Icons with active highlighting -->
  <a class="tb-icon <?= $activePage === 'dashboard' ? 'active disabled' : '' ?>" 
     href="/Store/dashboard.php" title="Dashboard"><img src="image/icons/dashboard.png" alt="Profile" style="width: 25px; height: 25px; object-fit:contain;"></a>
  <a class="tb-icon <?= $activePage === 'records' ? 'active disabled' : '' ?>" 
     href="/Store/record/record.php" title="Sales Inventory"><img src="image/icons/mail-attachment.png" alt="Records" style="width: 20px; height: 20px; object-fit:contain;"></a>
  <a class="tb-icon <?= $activePage === 'users' ? 'active disabled' : '' ?>" 
     href="/Store/admin/user.php" title="User Management"><img src="image/icons/management.png" alt="Users" style="width: 25px; height: 25px; object-fit:contain;"></a>
  <a class="tb-icon <?= $activePage === 'settings' ? 'active disabled' : '' ?>" 
     href="/Store/admin/settings.php" title="Settings"><img src="image/icons/gear.png" alt="Settings" style="width: 25px; height: 25px; object-fit:contain;"></a>
  <a class="tb-icon <?= $activePage === 'products' ? 'active disabled' : '' ?>" 
     href="/Store/product/product.php" title="Manage Products"><img src="image/icons/product-management.png" alt="Products" style="width: 26px; height: 26px; object-fit:contain;"></a>
  <a class="tb-icon <?= $activePage === 'reserve' ? 'active disabled' : '' ?>" 
     href="/Store/product/item_reserve.php" title="Reserve Items"><img src="image/icons/reserve.png" alt="Reserve Items" style="width: 25px; height: 25px; object-fit:contain;"></a>
  <a class="tb-icon <?= $activePage === 'profile' ? 'active disabled' : '' ?>" 
     href="/Store/admin/prof.php" title="Profile"><img src="image/icons/man.png" alt="Profile" style="width: 25px; height: 25px; object-fit:contain;">
    <?php if(isset($lowStockCount) && isset($expiringCount) && ($lowStockCount>0||$expiringCount>0)): ?><span class="notif-dot"></span><?php endif; ?>
  </a>
  <a class="tb-icon <?= $activePage === 'breads' ? 'active disabled' : '' ?>" 
     href="/Store/product/bread.php" title="Manage Breads"><img src="image/icons/bakery.png" alt="Breads" style="width: 20px; height: 20px; object-fit:contain;"></a>
  <a class="tb-icon <?= $activePage === 'bleft' ? 'active disabled' : '' ?>" 
     href="/Store/admin/bleft.php" title="Bread Left"><img src="image/icons/inventory.png" alt="Bread Left" style="width: 20px; height: 20px; object-fit:contain;"></a>
  <a class="tb-icon" href="#" onclick="event.preventDefault(); openLogoutModal();" title="Logout"><img src="image/icons/power.png" alt="Logout" style="width: 20px; height: 20px; object-fit:contain;"></a>
</div>


<!-- ═══════════════════════════════════ TOP BAR -->
<div class="top-bar">
  <button class="menu-btn" id="menuBtn" onclick="toggleSidebar()">☰</button>
  
  <div class="logo-pill">
    <?php if (!empty($logoPath) && file_exists(__DIR__ . '/../' . $logoPath)): ?>
      <img src="/Store/<?= htmlspecialchars($logoPath) ?>" alt="Logo" class="logo-img">
    <?php endif; ?>
    <div class="logo-text">
      <span class="lp-name"><?= htmlspecialchars($businessName) ?></span>
      <span class="lp-sub"><?= htmlspecialchars($businessSubtitle) ?></span>
    </div>
  </div>
  
  <div class="tb-divider"></div>
  <span class="tb-title" id="topBarTitle"><?= htmlspecialchars($pageTitle) ?></span>
  <div class="tb-divider"></div>
  <span class="tb-clock" id="currentTime"></span>
  <div style="font-size:10px; margin-left:45px;"><?= htmlspecialchars($businessAddress) ?></div>
  <div class="tb-spacer"></div>

  <?php if(isset($lowStockCount) && isset($expiringCount) && ($lowStockCount>0||$expiringCount>0)): ?>
  <div class="tb-badge warn" onclick="openAlertModal()" style="cursor:pointer;" title="View alerts">
    <div class="dot"></div>
    <span><?= $lowStockCount+$expiringCount ?> Alerts</span>
  </div>
  <?php endif; ?>

  <!-- Top Bar Icons with active highlighting - Using absolute paths from /Store/ -->
  <a class="tb-icon <?= $activePage === 'dashboard' ? 'active disabled' : '' ?>" 
     href="/Store/dashboard.php" title="Dashboard">
    <img src="/Store/image/icons/admin-panel.png" alt="Dashboard">
  </a>
  <a class="tb-icon <?= $activePage === 'records' ? 'active disabled' : '' ?>" 
     href="/Store/record/record.php" title="Sales Inventory">
    <img src="/Store/image/icons/mail-attachment.png" alt="Records">
  </a>
  <a class="tb-icon <?= $activePage === 'users' ? 'active disabled' : '' ?>" 
     href="/Store/admin/user.php" title="User Management">
    <img src="/Store/image/icons/management.png" alt="Users">
  </a>
  <a class="tb-icon <?= $activePage === 'settings' ? 'active disabled' : '' ?>" 
     href="/Store/admin/settings.php" title="Settings">
    <img src="/Store/image/icons/gear.png" alt="Settings">
  </a>
  <a class="tb-icon <?= $activePage === 'products' ? 'active disabled' : '' ?>" 
     href="/Store/product/product.php" title="Manage Products">
    <img src="/Store/image/icons/product-management.png" alt="Products">
  </a>
  <a class="tb-icon <?= $activePage === 'reserve' ? 'active disabled' : '' ?>" 
     href="/Store/product/item_reserve.php" title="Reserve Items">
    <img src="/Store/image/icons/reserve.png" alt="Reserve Items">
  </a>
  <a class="tb-icon <?= $activePage === 'profile' ? 'active disabled' : '' ?>" 
     href="/Store/admin/prof.php" title="Profile">
    <img src="/Store/image/icons/man.png" alt="Profile">
    <?php if(isset($lowStockCount) && isset($expiringCount) && ($lowStockCount>0||$expiringCount>0)): ?><span class="notif-dot"></span><?php endif; ?>
  </a>
  <a class="tb-icon <?= $activePage === 'breads' ? 'active disabled' : '' ?>" 
     href="/Store/product/bread.php" title="Manage Breads">
    <img src="/Store/image/icons/bakery.png" alt="Breads">
  </a>
  <a class="tb-icon <?= $activePage === 'bleft' ? 'active disabled' : '' ?>" 
     href="/Store/admin/bleft.php" title="Bread Left">
    <img src="/Store/image/icons/inventory.png" alt="Bread Left">
  </a>
  <a class="tb-icon" href="#" onclick="event.preventDefault(); openLogoutModal();" title="Logout">
  <img src="/Store/image/icons/power.png" alt="Logout">
</a>
</div>

<!-- ═══════════════════════════════════ SIDEBAR -->
<div class="sidebar" id="sidebar">
  <div class="sb-section-label">Management</div>

  <button class="sb-group-btn" onclick="toggleSub(this)">
    <span class="sb-ico">
      <img src="/Store/image/icons/product-management.png" alt="Products">
    </span>
    <span>Product Category</span>
    <span class="arrow">›</span>
  </button>
  <div class="sb-sub <?= ($activePage === 'products' || $activePage === 'reserve') ? 'open' : '' ?>">
    <a href="/Store/product/product.php" class="<?= $activePage === 'products' ? 'active disabled' : '' ?>">
      <img src="/Store/image/icons/mail-attachment.png" alt="Products"> Manage Items
    </a>
    <a href="/Store/product/item_reserve.php" class="<?= $activePage === 'reserve' ? 'active disabled' : '' ?>">
      <img src="/Store/image/icons/reserve.png" alt="Reserve Items"> Reserve Items
    </a>
  </div>

  <button class="sb-group-btn" onclick="toggleSub(this)">
    <span class="sb-ico">
      <img src="/Store/image/icons/mail-attachment.png" alt="Records">
    </span>
    <span>Sales Inventory</span>
    <span class="arrow">›</span>
  </button>
  <div class="sb-sub <?= $activePage === 'records' ? 'open' : '' ?>">
    <a href="/Store/record/record.php" class="<?= $activePage === 'records' ? 'active disabled' : '' ?>">
      <img src="/Store/image/icons/mail-attachment.png" alt="Records"> Manage Sales
    </a>
  </div>

  <div class="sb-divider"></div>
  <div class="sb-section-label">Administration</div>

  <button class="sb-group-btn" onclick="toggleSub(this)">
    <span class="sb-ico">
      <img src="/Store/image/icons/management.png" alt="Users">
    </span>
    <span>User Management</span>
    <span class="arrow">›</span>
  </button>
  <div class="sb-sub <?= $activePage === 'users' ? 'open' : '' ?>">
    <a href="/Store/admin/user.php" class="<?= $activePage === 'users' ? 'active disabled' : '' ?>">
      <img src="/Store/image/icons/management.png" alt="Users"> Manage Users
    </a>
  </div>

  <button class="sb-group-btn" onclick="toggleSub(this)">
    <span class="sb-ico">
      <img src="/Store/image/icons/bakery.png" alt="Breads">
    </span>
    <span>Bread Management</span>
    <span class="arrow">›</span>
  </button>
  <div class="sb-sub <?= ($activePage === 'breads' || $activePage === 'bleft') ? 'open' : '' ?>">
    <a href="/Store/product/bread.php" class="<?= $activePage === 'breads' ? 'active disabled' : '' ?>">
      <img src="/Store/image/icons/bakery.png" alt="Breads"> Manage Breads
    </a>
    <a href="/Store/admin/bleft.php" class="<?= $activePage === 'bleft' ? 'active disabled' : '' ?>">
      <img src="/Store/image/icons/inventory.png" alt="Bread Left"> Bread Left
    </a>
  </div>

  <div class="sb-divider"></div>
  
  <button class="sb-group-btn" onclick="toggleSub(this)">
    <span class="sb-ico">
      <img src="/Store/image/icons/gear.png" alt="Settings">
    </span>
    <span>Settings</span>
    <span class="arrow">›</span>
  </button>
  <div class="sb-sub <?= $activePage === 'settings' ? 'open' : '' ?>">
    <a href="/Store/admin/settings.php" class="<?= $activePage === 'settings' ? 'active disabled' : '' ?>">
      <img src="/Store/image/icons/gear.png" alt="Settings"> System Settings
    </a>
  </div>

  <div class="sb-divider"></div>
  
  <div class="sb-section-label">Account</div>
  <div class="sb-sub">
    <a href="/Store/admin/prof.php" class="<?= $activePage === 'profile' ? 'active disabled' : '' ?>">
      <img src="/Store/image/icons/man.png" alt="Profile"> My Profile
    </a>
  </div>
</div>
<!-- ═══════════════════════════════════ LOGOUT CONFIRMATION MODAL -->
<div class="modal-overlay" id="logoutModal">
  <div class="logout-modal" id="logoutModalBox">
    <div class="lm-icon">🚪</div>
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
<script>
/* Clock */
function updateClock(){
  document.getElementById('currentTime').textContent=new Date().toLocaleString('en-US',{
    timeZone:'Asia/Manila',weekday:'short',year:'numeric',month:'short',
    day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true
  });
}
setInterval(updateClock,1000); updateClock();

/* Sidebar */
function toggleSidebar(){
  const sb=document.getElementById('sidebar');
  sb.style.display=sb.style.display==='flex'?'none':'flex';
  document.getElementById('menuBtn').textContent=sb.style.display==='flex'?'✖':'☰';
}
function toggleSub(btn){
  const sub=btn.nextElementSibling;
  const open=sub.classList.toggle('open');
  btn.classList.toggle('open',open);
}
/* ─── Logout Modal ─────────────────────────────────────────────── */
function openLogoutModal() {
  document.getElementById('logoutModal').classList.add('show');
}

function closeLogoutModal() {
  document.getElementById('logoutModal').classList.remove('show');
  // Reset position
  const modalBox = document.getElementById('logoutModalBox');
  if (modalBox) {
    modalBox.style.position = '';
    modalBox.style.left = '';
    modalBox.style.top = '';
    modalBox.style.margin = '';
  }
}

function confirmLogout() {
  window.location.href = '/Store/record/logout.php';
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