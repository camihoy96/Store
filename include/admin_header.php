<?php
// include/admin_header.php
session_start();
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

// Page title - can be overridden before including this file
$pageTitle = $pageTitle ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> –St4nger POS</title>
<!-- FIXED: Changed to ../ for subdirectory pages -->
<link rel="stylesheet" href="../css/bootstrap-icons.css">
<script src="js/chart.js"></script>
<script src="../js/sweetalert2.all.min.js"></script>
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
  display: flex; flex-direction: column; align-items: center; line-height: 1.2;
  box-shadow: 0 0 20px rgba(255,136,0,0.3);
}
.logo-pill .lp-name { font-weight: 800; font-size: 11px; color: white; letter-spacing: 0.3px; }
.logo-pill .lp-sub  { font-size: 7px; color: rgba(255,255,255,0.75); letter-spacing: 2px; font-weight: 600; text-transform: uppercase; }

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
  background: var(--bg3); border: 1px solid var(--border);
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
</style>
</head>
<body>

<!-- ═══════════════════════════════════ TOP BAR -->
<div class="top-bar">
  <button class="menu-btn" id="menuBtn" onclick="toggleSidebar()">☰</button>
  <div class="logo-pill">
    <span class="lp-name"><?= htmlspecialchars($businessName) ?></span>
    <span class="lp-sub"><?= htmlspecialchars($businessSubtitle) ?></span>
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
  <?php else: ?>

  <?php endif; ?>
  <a class="tb-icon" href="../dashboard.php" title="Sales Inventory">📊</a>
  <a class="tb-icon" href="record/record.php" title="Sales Inventory">📝</a>
  <a class="tb-icon" href="admin/user.php" title="User Management">👨‍👩‍👧‍👦</a>
  <a class="tb-icon" href="admin/settings.php" title="Settings">⚙️</a>
  <a class="tb-icon" href="product/product.php" title="Manage Products">📋</a>
  <a class="tb-icon" href="product/item_reserve.php" title="Reserve Items">📦</a>
  <a class="tb-icon" href="admin/prof.php" title="Profile">🙍🏻‍♂️
    <?php if(isset($lowStockCount) && isset($expiringCount) && ($lowStockCount>0||$expiringCount>0)): ?><span class="notif-dot"></span><?php endif; ?>
  </a>
  <a class="tb-icon" href="product/bread.php" title="Manage Breads">💼</a>
  <a class="tb-icon" href="admin/bleft.php" title="Bread Left">📋</a>
  <a class="tb-icon" href="record/logout.php" title="Logout">🚪</a>
</div>

<!-- ═══════════════════════════════════ SIDEBAR -->
<div class="sidebar" id="sidebar">
  <div class="sb-section-label">Management</div>

  <button class="sb-group-btn" onclick="toggleSub(this)">
    <span class="sb-ico">📦</span><span>Product Category</span><span class="arrow">›</span>
  </button>
  <div class="sb-sub">
    <a href="product/product.php">📋 Manage Items</a>
    <a href="product/item_reserve.php">🗃 Reserve Items</a>
  </div>

  <button class="sb-group-btn" onclick="toggleSub(this)">
    <span class="sb-ico">📊</span><span>Sales Inventory</span><span class="arrow">›</span>
  </button>
  <div class="sb-sub">
    <a href="record/record.php">🧾 Manage Sales</a>
  </div>

  <div class="sb-divider"></div>
  <div class="sb-section-label">Administration</div>

  <button class="sb-group-btn" onclick="toggleSub(this)">
    <span class="sb-ico">👥</span><span>User Management</span><span class="arrow">›</span>
  </button>
  <div class="sb-sub">
    <a href="admin/user.php">⚙ Manage Users</a>
  </div>

  <button class="sb-group-btn" onclick="toggleSub(this)">
    <span class="sb-ico">🍞</span><span>Bread Management</span><span class="arrow">›</span>
  </button>
  <div class="sb-sub">
    <a href="product/bread.php">✍ Manage Breads</a>
    <a href="admin/bleft.php">📋 Bread Left</a>
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
</script>