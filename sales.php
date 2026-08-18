<?php
session_start();

// ─── OPTIONAL: Uncomment to enforce login ─────────────────────────────────────
if (!isset($_SESSION['loggedin'])) { header("Location: index.php"); exit; }

require('dbconn.php');

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

// ─── FETCH PAYMENT METHODS ─────────────────────────────────────────────────────
$paymentMethods = [];
$result = $conn->query("SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY display_order ASC");
while ($row = $result->fetch_assoc()) {
    $paymentMethods[] = $row;
}

// ─── LOAD PRODUCTS ─────────────────────────────────────────────────────────────
$products = [];
$result = $conn->query(
    "SELECT id, CONCAT('P', id) AS code, name, price,
            pieces, kg, measurement_type, image_path, brand, category
     FROM products ORDER BY category, name"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['pieces']           = $row['pieces'] ?? 0;
        $row['kg']               = $row['kg'] ?? 0;
        $row['measurement_type'] = $row['measurement_type'] ?? 'pieces';
        $row['image_path']       = !empty($row['image_path'])
            ? 'product/' . $row['image_path']
            : 'image/cake.jfif';
        $row['is_low_stock']     = ($row['measurement_type'] === 'kg')
            ? ($row['kg'] < 5.0) : ($row['pieces'] < 5);
        $products[] = $row;
    }
}

// ─── UNIQUE CATEGORIES ─────────────────────────────────────────────────────────
$categories = [];
foreach ($products as $p) {
    $cat = trim($p['category'] ?? 'General');
    if ($cat && !in_array($cat, $categories)) $categories[] = $cat;
}
if (empty($categories)) $categories = ['All'];

// ─── LOAD BREAD TYPES ──────────────────────────────────────────────────────────
$breads = [];
$br = $conn->query("SELECT name, price FROM breads ORDER BY name");
if ($br) while ($row = $br->fetch_assoc()) $breads[] = $row;

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

/* ══════════════════════════════ TOP BAR ══════════════════════════════ */
.top-bar {
  background: linear-gradient(180deg, #3a3a3a 0%, #2a2a2a 100%);
  display: flex;
  align-items: center;
  padding: 0 8px;
  height: 42px;
  border-bottom: 2px solid #111;
  flex-shrink: 0;
  gap: 6px;
}

.logo-block {
  background: linear-gradient(135deg, #ff8800, #ff6000);
  border-radius: 6px;
  padding: 4px 12px;
  display: flex;
  flex-direction: column;
  align-items: center;
  line-height: 1.1;
}
.logo-block .brand { font-weight: 900; font-size: 13px; color: white; letter-spacing: 0.5px; }
.logo-block .sub   { font-size: 8px; color: rgba(255,255,255,0.85); letter-spacing: 1.5px; font-weight: 600; }

.top-clock {
  color: #ffcc66;
  font-weight: 700;
  font-size: 12px;
  margin-left: 15px;
}

.top-spacer { flex: 1; }

.top-icon-group { display: flex; gap: 3px; }
.top-icon {
  width: 34px; height: 30px;
  background: linear-gradient(180deg, #e7d8d8, #e2dada);
  border: 1px solid #666;
  border-radius: 4px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: 14px; color: #ccc;
  transition: background 0.15s;
}
.top-icon:hover { background: linear-gradient(180deg, #ff8800, #cc5500); color: white; border-color: #ff8800; }

.menu-btn {
  background: linear-gradient(180deg, #555, #3a3a3a);
  border: 1px solid #666;
  border-radius: 4px;
  color: white;
  font-size: 18px;
  cursor: pointer;
  width: 34px; height: 30px;
  display: flex; align-items: center; justify-content: center;
  transition: background 0.15s;
}
.menu-btn:hover { background: linear-gradient(180deg, #ff8800, #cc5500); border-color: #ff8800; }

/* ══════════════════════════════ SIDEBAR ══════════════════════════════ */
.sidebar {
  width: 220px;
  background: #1c2a1e;
  position: fixed;
  top: 42px; left: 0;
  height: calc(100vh - 42px - 28px);
  display: none;
  flex-direction: column;
  z-index: 800;
  box-shadow: 3px 0 12px rgba(0,0,0,0.5);
  border-right: 1px solid #2a4030;
}
.sidebar a {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px; color: #aaccb0; text-decoration: none;
  font-size: 13px; border-bottom: 1px solid #1e3022;
  transition: background 0.15s;
}
.sidebar a:hover { background: #ff8800; color: white; }

/* ══════════════════════════════ MAIN LAYOUT ══════════════════════════════ */
.main-wrap {
  display: flex;
  flex: 1;
  overflow: hidden;
}

/* ══════════════════════════════ LEFT – ORDER PANEL ══════════════════════════════ */
.order-panel {
  width: 268px;
  background: #f0f0f0;
  display: flex;
  flex-direction: column;
  border-right: 2px solid #888;
  flex-shrink: 0;
  color: #222;
}

.order-type {
  background: linear-gradient(180deg, #e0e0e0, #d0d0d0);
  text-align: center;
  padding: 6px;
  font-weight: 700;
  font-size: 13px;
  color: #333;
  border-bottom: 1px solid #bbb;
  flex-shrink: 0;
}

.order-items {
  flex: 1;
  overflow-y: auto;
}
.order-items::-webkit-scrollbar { width: 4px; }
.order-items::-webkit-scrollbar-thumb { background: #aaa; border-radius: 2px; }

.order-item {
  display: flex;
  align-items: center;
  padding: 5px 8px;
  border-bottom: 1px solid #ddd;
  cursor: pointer;
  font-size: 12px;
  gap: 4px;
  transition: background 0.1s;
}
.order-item:hover { background: #ddeeff; }
.order-item.selected { background: #2a6fc9; color: white; }
.order-item .qty  { width: 18px; font-weight: 700; flex-shrink: 0; }
.order-item .name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.order-item .price { min-width: 56px; text-align: right; font-weight: 600; }
.order-item .del  {
  color: #cc0000; font-weight: 900; font-size: 15px; cursor: pointer;
  padding: 0 2px; line-height: 1;
}
.order-item.selected .del { color: #ffaaaa; }
.order-empty {
  text-align: center; color: #888;
  padding: 30px 10px; font-size: 12px;
}

/* Order footer */
.order-footer {
  background: #ddd;
  border-top: 2px solid #bbb;
  padding: 8px;
  flex-shrink: 0;
}
.total-row {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin-bottom: 2px;
}
.total-label  { font-size: 11px; color: #0f0e0e; }
.total-count  { font-size: 13px; font-weight: 700; color: #444; }
.total-amount { font-size: 24px; font-weight: 900; color: #111; }

.cash-row {
  display: flex; gap: 6px; margin-top: 6px; align-items: center; 
}
.cash-row label { font-size: 11px; color: #555; font-weight: 600; white-space: nowrap; }
.cash-input {
  flex: 1; padding: 5px 8px;
  border: 1.5px solid #999; border-radius: 4px;
  font-size: 14px; font-weight: 700; background: white;
}
.cash-input:focus { outline: none; border-color: #ff8800; }

.change-row {
  margin-top: 4px; text-align: right;
  font-size: 12px; font-weight: 700; color: #006600; min-height: 18px;
}
.change-row.short { color: #cc0000; }

.pay-btns { display: flex; gap: 5px; margin-top: 7px; }
.pay-btn {
  flex: 1; padding: 10px 4px; border: none; border-radius: 5px;
  cursor: pointer; font-weight: 700; font-size: 12px;
  display: flex; flex-direction: column; align-items: center; gap: 2px;
  transition: filter 0.15s; color: white;
}
.pay-btn:hover { filter: brightness(1.15); }
.pay-btn.cash { background: linear-gradient(180deg, #ff9900, #dd6600); }
.pay-btn.wallet { background: linear-gradient(180deg, #dd3333, #aa1111); }
.pay-btn.more { background: linear-gradient(180deg, #555, #333); }
.pay-btn .icon { font-size: 16px; }

/* ══════════════════════════════ RIGHT – MENU PANEL ══════════════════════════════ */
.menu-panel {
  flex: 1; display: flex; flex-direction: column;
  background: #2a2a2a; min-width: 0;
}

/* Category tabs */
.cat-tabs {
  display: flex; background: #444;
  flex-shrink: 0; overflow-x: auto; border-bottom: 2px solid #222;
}
.cat-tabs::-webkit-scrollbar { height: 3px; }
.cat-tabs::-webkit-scrollbar-thumb { background: #ff8800; }
.cat-tab {
  padding: 9px 16px; font-size: 12px; font-weight: 600;
  cursor: pointer; background: #555; color: #ccc;
  border: none; white-space: nowrap;
  border-right: 1px solid #3a3a3a;
  transition: background 0.15s; flex-shrink: 0;
}
.cat-tab:hover { background: #666; color: white; }
.cat-tab.active { background: linear-gradient(180deg, #ff9900, #ff6600); color: white; }

/* Search bar */
.sub-bar {
  display: flex; align-items: center;
  background: #333; padding: 5px 8px;
  gap: 8px; flex-shrink: 0; border-bottom: 1px solid #222;
}
.search-input {
  flex: 1; padding: 6px 10px;
  background: #555; border: 1.5px solid #666;
  border-radius: 5px; color: white; font-size: 12px;
  transition: border-color 0.15s;
}
.search-input::placeholder { color: #c0bbbb; }
.search-input:focus { outline: none; border-color: #ff8800; background: #444; }
.shortcut-hint { font-size: 10px; color: #ffffff; display: flex; gap: 8px; flex-shrink: 0; }
.hint-key {
  background: #444; border: 1px solid #666; border-radius: 3px;
  padding: 2px 6px; font-family: monospace; color: #1ffa02; font-size: 10px;
}

/* Menu grid */
.menu-grid {
  flex: 1; display: grid;
  grid-template-columns: repeat(auto-fill, minmax(115px, 1fr));
  gap: 3px; padding: 5px; overflow-y: auto; align-content: start;
}
.menu-grid::-webkit-scrollbar { width: 5px; }
.menu-grid::-webkit-scrollbar-thumb { background: #555; border-radius: 3px; }

.menu-item {
  background: linear-gradient(180deg, #4a4a4a, #3a3a3a);
  color: #eee; border-radius: 5px; cursor: pointer;
  border: 1px solid #555; overflow: hidden;
  display: flex; flex-direction: column; height: 110px;
  transition: border-color 0.15s, transform 0.1s; position: relative;
}
.menu-item:hover { border-color: #ff8800; transform: translateY(-1px); }
.menu-item.out  { opacity: 0.45; cursor: not-allowed; }
.menu-item.low-s { border-color: #cc8800; }
.menu-item-img {
  height: 60px; background-size: cover;
  background-position: center; background-color: #333; flex-shrink: 0;
}
.menu-item-info {
  flex: 1; padding: 4px 5px;
  display: flex; flex-direction: column; justify-content: center;
}
.menu-item-name  { font-size: 10px; font-weight: 600; line-height: 1.2; color: #f0f0f0; }
.menu-item-price { font-size: 10px; color: #ffcc66; font-weight: 700; margin-top: 1px; }
.menu-item-qty   { font-size: 9px; color: #ddcdcd; }
.menu-item-qty.low { color: #ff8800; font-weight: 700; }
.menu-item-qty.out-label { color: #cc4444; font-weight: 700; }
.out-overlay {
  position: absolute; inset: 0;
  background: rgba(0,0,0,0.45);
  display: flex; align-items: center; justify-content: center;
}
.out-overlay span {
  background: #cc0000; color: white;
  font-size: 9px; font-weight: 700; padding: 2px 6px; border-radius: 3px;
}
.low-badge {
  position: absolute; top: 3px; right: 3px;
  background: #cc8800; color: white;
  font-size: 8px; font-weight: 700; padding: 1px 4px; border-radius: 2px;
}
.no-items { grid-column: 1/-1; text-align: center; color: #666; padding: 40px; font-size: 14px; }

/* Custom product strip */
.custom-strip {
  background: #222; border-top: 1px solid #333;
  padding: 6px 8px; flex-shrink: 0;
}
.custom-strip-title {
  font-size: 10px; font-weight: 700; color: #fff8f8;
  text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px;
}
.custom-row { display: flex; gap: 5px; flex-wrap: wrap; }
.custom-input {
  background: #3a3a3a; border: 1px solid #555;
  color: #eee; border-radius: 4px; padding: 5px 7px; font-size: 12px;
  transition: border-color 0.15s;
}
.custom-input::placeholder { color: #b8b4b4; }
.custom-input:focus { outline: none; border-color: #ff8800; background: #444; }
.ci-name  { flex: 2; min-width: 100px; }
.ci-type  { flex: 2; min-width: 120px; }
.ci-price { flex: 1; min-width: 70px; }
.ci-qty   { width: 60px; }
.ci-unit  { width: 90px; }
.btn-custom-add {
  padding: 5px 14px; background: linear-gradient(180deg, #ff9900, #cc6600);
  color: white; border: none; border-radius: 4px;
  font-size: 12px; font-weight: 700; cursor: pointer;
  transition: filter 0.15s; white-space: nowrap;
}
.btn-custom-add:hover { filter: brightness(1.1); }

/* Customer / cashier bar */
.customer-bar {
  background: #3f3939; border-top: 1px solid #222;
  padding: 5px 10px; font-size: 11px; color: #ffeeee;
  flex-shrink: 0; display: flex; justify-content: space-between; align-items: center;
}
.customer-bar .cashier-name { color: #67d40e; font-weight: 700; }

/* ══════════════════════════════ STATUS BAR ══════════════════════════════ */
.status-bar {
  background: #1a1a1a; border-top: 1px solid #111;
  display: flex; align-items: center;
  padding: 3px 10px; gap: 16px; font-size: 10px; color: #fffefe;
  flex-shrink: 0; height: 28px;
}
.status-bar span { border-right: 1px solid #333; padding-right: 16px; }
.status-bar span:last-child { border-right: none; }
.status-offline { color: #ec4747 !important; font-weight: 700; }
.status-online  { color: #6bf112 !important; font-weight: 700; }

/* ══════════════════════════════ MODALS ══════════════════════════════ */
.modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.6); backdrop-filter: blur(3px);
  display: none; align-items: center; justify-content: center; z-index: 9999;
}
.modal-overlay.show { display: flex; }

/* Payment modal */
.pay-modal {
  background: linear-gradient(180deg, #e0e0e0, #cccccc);
  border: 2px solid #888; border-radius: 7px; width: 370px;
  box-shadow: 0 12px 40px rgba(0,0,0,0.6); overflow: hidden;
}
.pay-modal-title {
  background: linear-gradient(180deg, #ff9900, #ff6600);
  padding: 8px 14px; display: flex; justify-content: space-between; align-items: center;
}
.pay-modal-title span { font-weight: 700; font-size: 13px; color: white; letter-spacing: 1px; }
.modal-x {
  background: #aa2200; color: white; border: none; border-radius: 3px;
  width: 22px; height: 22px; font-size: 13px; cursor: pointer; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
}
.modal-x:hover { background: #cc0000; }
.pay-modal-body { padding: 16px; color: #222; }
.pm-row { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
.pm-label { font-size: 11px; font-weight: 600; width: 80px; color: #444; }
.pm-input {
  flex: 1; padding: 7px 10px; border: 1.5px solid #aaa; border-radius: 4px;
  font-size: 14px; background: white; font-weight: 700;
}
.pm-input:focus { outline: none; border-color: #ff8800; }
.pm-summary {
  background: #f8f8f8; border: 1px solid #ddd;
  border-radius: 4px; padding: 10px; margin-bottom: 12px; font-size: 12px;
  max-height: 180px; overflow-y: auto;
}
.pm-summary-row { display: flex; justify-content: space-between; margin-bottom: 3px; }
.pm-summary-row.total { font-size: 15px; font-weight: 800; color: #006600; margin-top: 4px; border-top: 1px solid #ddd; padding-top: 4px; }
.pm-change {
  text-align: center; padding: 6px; border-radius: 4px;
  font-size: 13px; font-weight: 700; margin-bottom: 12px;
  background: #e8f5e8; color: #006600; transition: background 0.2s;
}
.pm-change.short { background: #fde8e8; color: #cc0000; }
.pm-btns { display: flex; gap: 8px; }
.pm-btn {
  flex: 1; padding: 9px; border: none; border-radius: 5px;
  font-size: 13px; font-weight: 700; cursor: pointer; transition: filter 0.15s; color: white;
}
.pm-btn:hover { filter: brightness(1.1); }
.pm-btn.cancel  { background: linear-gradient(180deg, #888, #555); }
.pm-btn.proceed { background: linear-gradient(180deg, #ff9900, #cc6600); }

/* Error modal */
.err-modal {
  background: linear-gradient(180deg, #eee, #ddd);
  border: 2px solid #888; border-radius: 7px; width: 320px;
  box-shadow: 0 12px 40px rgba(0,0,0,0.6); overflow: hidden;
}
.err-bar {
  background: #cc2200; padding: 5px 14px;
  display: flex; justify-content: space-between; align-items: center;
}
.err-bar span { font-weight: 700; font-size: 12px; color: white; }
.err-body { padding: 20px 16px; display: flex; gap: 14px; align-items: center; color: #222; }
.err-icon {
  width: 44px; height: 44px; background: #cc0000; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  color: white; font-size: 22px; font-weight: 900; flex-shrink: 0;
}
.err-msg { font-size: 13px; line-height: 1.4; }
.err-foot { padding: 0 16px 16px; display: flex; justify-content: center; }
.ok-btn {
  background: linear-gradient(180deg, #888, #666); color: white;
  border: none; border-radius: 4px; padding: 7px 44px;
  font-size: 13px; font-weight: 700; cursor: pointer;
}
.ok-btn:hover { filter: brightness(1.1); }

/* Dashboard login modal */
.dash-modal {
  background: white; border-radius: 10px; width: 200%; max-width: 90%; height: 90%;
  padding: 24px; box-shadow: 0 16px 50px rgba(0,0,0,0.5);
  max-height: 150vh; overflow-y: auto; color: #222;
}
.dash-modal h2 { color: #ff6600; text-align: center; margin-bottom: 8px; }

/* Print */
@media print {
  body * { visibility: hidden; }
  #receipt, #receipt * { visibility: visible; }
  #receipt {
    position: absolute; left: 0; top: 0; width: 58mm;
    font-family: monospace; font-size: 12px; padding: 5px; line-height: 1.3;
  }
  #receipt .center { text-align: center; }
  #receipt hr { border: none; border-top: 1px dashed #000; margin: 4px 0; }
}
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="top-bar">
  <button class="menu-btn" id="menuBtn" onclick="toggleSidebar()">☰</button>
  <div class="logo-block">
    <span class="brand"><?= htmlspecialchars($businessName) ?></span>
     <span class="sub"><?= htmlspecialchars($businessSubtitle) ?></span>
  </div>
  <span class="top-clock" id="currentTime"></span>
  <div style="font-size:10px; margin-left: 45px;"><?= htmlspecialchars($businessAddress) ?></div>
  <div class="top-spacer"></div>
  <div class="top-icon-group">
    <div class="top-icon" title="Records"    onclick="location.href='manage/transaction.php'">📋</div>
    <div class="top-icon" title="Profile"    onclick="location.href='profile/prof.php'">👤</div>
    <div class="top-icon" title="Bread Left" onclick="location.href='manage/remain.php'">🧺</div>
    <div class="top-icon" title="Login"      onclick="openDashLogin()">🔑</div>
    <div class="top-icon" title="Logout"     onclick="location.href='logout.php'">🚪</div>
  </div>
</div>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
  <a href="manage/transaction.php">📋 &nbsp;Manage Records</a>
  <a href="profile/prof.php">👤 &nbsp;Manage Profile</a>
  <a href="manage/remain.php">🧺 &nbsp;Record Bread Left</a>
  <a href="logout.php">🚪 &nbsp;Logout</a>
</div>

<!-- MAIN WRAP -->
<div class="main-wrap">

  <!-- ──── LEFT: Order Panel ──── -->
  <div class="order-panel">
    <div class="order-type">---Purchase Details---</div>

    <div class="order-items" id="orderList">
      <div class="order-empty">No items added yet.</div>
    </div>

    <div class="order-footer">
      <div class="total-row">
        <span class="total-label">Items</span>
        <span class="total-count" id="itemCount">0</span>
      </div>
      <div class="total-row">
        <span class="total-label" style="font-size:13px;font-weight:700;">Total</span>
        <span class="total-amount"><?= $currencySymbol ?><span id="totalAmount">0.00</span></span>
      </div>
      <div class="cash-row">
        <label>Cash <?= $currencySymbol ?></label>
        <input type="number" class="cash-input" id="amountPaid"
               placeholder="0.00" oninput="autoCalcChange()">
      </div>
      <div class="change-row" id="changeRow">—</div>
      <div class="pay-btns">
        <?php if ($enableCash == '1'): ?>
  <button class="pay-btn cash" onclick="openPayModal('Cash')">
    <span class="icon">💵</span><span>Cash</span>
  </button>
  <?php endif; ?>
      <?php if ($enableEwallet == '1'): ?>
  <button class="pay-btn wallet" onclick="openWalletModal()">
    <span class="icon">📱</span><span>E-Wallet</span>
  </button>
  <?php endif; ?>
        <button class="pay-btn more" onclick="clearCheckout()">
          <span class="icon">✕</span><span>Clear</span>
        </button>
      </div>
    </div>
  </div>

  <!-- ──── RIGHT: Menu Panel ──── -->
  <div class="menu-panel">

    <!-- Category tabs (from DB) -->
    <div class="cat-tabs" id="catTabs">
      <button class="cat-tab active" onclick="filterByCategory('ALL',this)">All</button>
      <?php foreach ($categories as $cat): ?>
        <button class="cat-tab"
                onclick="filterByCategory('<?= htmlspecialchars($cat, ENT_QUOTES) ?>',this)">
          <?= htmlspecialchars($cat) ?>
        </button>
      <?php endforeach; ?>
    </div>

    <!-- Search + shortcuts -->
    <div class="sub-bar">
      <input type="text" class="search-input" id="searchInput"
             placeholder="🔍  Search by name or code…"
             oninput="filterProducts()" autofocus>
    </div>

    <!-- Product grid -->
    <div class="menu-grid" id="menuGrid"></div>

    <!-- Custom product strip -->
<div class="custom-strip">
  <div class="custom-strip-title">➕ Add Custom Product</div>
  <div class="custom-row">

    <!-- Custom Name -->
    <div style="display:flex;flex-direction:column;gap:2px;flex:2;min-width:100px;">
      <label style="font-size:9px;color:#fff;font-weight:600;text-transform:uppercase;letter-spacing:.8px;">Custom Name</label>
      <input type="text" class="custom-input ci-name" id="ciName"
             placeholder="Enter product name…"
             oninput="onCustomNameInput()">
    </div>

    <!-- Bread Type -->
    <div style="display:flex;flex-direction:column;gap:2px;flex:2;min-width:120px;">
      <label style="font-size:9px;color:#fff;font-weight:600;text-transform:uppercase;letter-spacing:.8px;">Bread Type</label>
      <select class="custom-input ci-type" id="ciType" onchange="ciPriceFromType()">
        <option value="" data-price="0">-- Select Bread --</option>
        <?php foreach ($breads as $b): ?>
          <option value="<?= htmlspecialchars($b['name']) ?>"
                  data-price="<?= htmlspecialchars($b['price']) ?>">
            <?= htmlspecialchars($b['name']) ?> (<?= $currencySymbol ?><?= number_format($b['price'],2) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Price -->
    <div style="display:flex;flex-direction:column;gap:2px;flex:1;min-width:70px;">
      <label style="font-size:9px;color:#fff;font-weight:600;text-transform:uppercase;letter-spacing:.8px;">Price (<?= $currencySymbol ?>)</label>
      <input type="number" class="custom-input ci-price" id="ciPrice"
             placeholder="0.00" min="0" step="0.01">
    </div>

    <!-- Quantity -->
    <div style="display:flex;flex-direction:column;gap:2px;width:60px;">
      <label style="font-size:9px;color:#fff;font-weight:600;text-transform:uppercase;letter-spacing:.8px;">Qty</label>
      <input type="number" class="custom-input ci-qty" id="ciQty"
             placeholder="Qty" min="1" step="1" value="1"">
    </div>

    <!-- Unit -->
    <div style="display:flex;flex-direction:column;gap:2px;width:90px;">
      <label style="font-size:9px;color:#fff;font-weight:600;text-transform:uppercase;letter-spacing:.8px;">Unit</label>
      <select class="custom-input ci-unit" id="ciUnit">
        <option value="pcs">Pieces</option>
        <option value="kg">Kilograms</option>
      </select>
    </div>

    <!-- Add Button (aligned to bottom) -->
    <div style="display:flex;flex-direction:column;gap:2px;justify-content:flex-end;">
      <label style="font-size:9px;color:transparent;letter-spacing:.8px;">_</label>
      <button class="btn-custom-add" id="addproduct" onclick="addCustomProduct()">Add ▶</button>
    </div>

  </div>
</div>

    <!-- Cashier bar -->
    <div class="customer-bar">
      <span>Cashier: <span class="cashier-name" id="activeCashier"><?= $cashierName ?></span></span>
      <span><?php echo date('F j, Y'); ?></span>
    </div>
  </div>
</div>

<!-- STATUS BAR -->
<div class="status-bar">
  <span>St4nger POS v1.0</span>
  <span>Terminal 1001</span>
  <span><?php echo date('F j, Y'); ?></span>
  <span class="status-offline" id="connStatus">● OFFLINE</span>
  <span>Help <span class="hint-key">F1</span></span>
  <span>Pay: <span class="hint-key">Enter</span></span>
  <span>E-Wallet: <span class="hint-key">F3</span></span>
  <span>Cancel: <span class="hint-key">F2</span></span>
  <span>Custom: <span class="hint-key">F8</span></span>
  <span>Menu: <span class="hint-key">F5</span></span>
</div>

<!-- HIDDEN RECEIPT (print only) -->
<div id="receipt" style="display:none; padding:10px;">
  <div class="center">
    <h2><?= htmlspecialchars($businessName) ?></h2>
    <div style="font-size:10px;"><?= htmlspecialchars($businessAddress) ?><br><?= htmlspecialchars($businessPhone) ?></div>
  </div>
  <hr>
  <div>Date: <span id="r-date"></span> &nbsp; Time: <span id="r-time"></span></div>
  <div>Cashier: <span id="r-cashier"></span></div>
  <hr>
  <div id="r-items"></div>
  <hr>
  <div>Total:   <?= $currencySymbol ?><span id="r-total"></span></div>
  <div id="r-payment-line"><!-- filled by JS --></div>
  <div id="r-change-line" style="display:none;">Change:  <?= $currencySymbol ?><span id="r-change"></span></div>
  <hr>
  <div class="center"><?= htmlspecialchars($receiptFooter) ?></div>
</div>
 

<!-- PAYMENT MODAL -->
<div class="modal-overlay" id="payModal">
  <div class="pay-modal">
    <div class="pay-modal-title">
      <span id="payModalTitle">PAYMENT</span>
      <button class="modal-x" onclick="closePayModal()">✕</button>
    </div>
    <div class="pay-modal-body">
      <div class="pm-summary" id="pmSummary"></div>
      <div class="pm-row">
        <span class="pm-label">Cash <?= $currencySymbol ?></span>
        <input type="number" class="pm-input" id="pmCash"
               placeholder="Enter amount" oninput="pmCalcChange()">
      </div>
      <div class="pm-change" id="pmChange">Change: <?= $currencySymbol ?>0.00</div>
      <div class="pm-btns">
        <button class="pm-btn cancel"  onclick="closePayModal()">Cancel</button>
        <button class="pm-btn proceed" onclick="processPayment()">✓ Confirm Pay</button>
      </div>
    </div>
  </div>
</div>
<!-- E-WALLET PAYMENT MODAL -->
<div class="modal-overlay" id="walletModal">
  <div class="pay-modal">
    <div class="pay-modal-title">
      <span id="walletModalTitle">E-WALLET PAYMENT</span>
      <button class="modal-x" onclick="closeWalletModal()">✕</button>
    </div>
    <div class="pay-modal-body">
      <div class="pm-summary" id="wmSummary"></div>

      <!-- Provider selector + Show QR button -->
      <div class="pm-row">
        <span class="pm-label">Payment Method</span>
        <div style="display:flex;gap:6px;flex:1;">
          <select class="pm-input" id="wmProvider" onchange="updateWalletHints()" style="flex:1;">
            <option value="">-- Select Payment --</option>
            <?php foreach ($paymentMethods as $pm): ?>
            <option value="<?= htmlspecialchars($pm['provider']) ?>"
                    data-qr="<?= htmlspecialchars($pm['qr_code_path'] ?? '') ?>"
                    data-name="<?= htmlspecialchars($pm['name']) ?>"
                    data-account-name="<?= htmlspecialchars($pm['account_name'] ?? '') ?>"
                    data-account-number="<?= htmlspecialchars($pm['account_number'] ?? '') ?>">
              <?= htmlspecialchars($pm['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <button type="button" id="btnShowQR" onclick="toggleQRCode()"
                  style="padding:7px 12px;
                         background:linear-gradient(180deg,#4a90e2,#357abd);
                         color:white;border:none;border-radius:4px;font-size:11px;
                         font-weight:600;cursor:pointer;white-space:nowrap;display:none;">
            📷 Show QR
          </button>
        </div>
      </div>

      <!-- ── Branded QR Card (hidden until Show QR clicked) ── -->
      <div id="qrContainer" style="display:none;margin:4px 0 12px;">
        <div id="qrCard" style="border-radius:12px;overflow:hidden;
             box-shadow:0 4px 20px rgba(0,0,0,0.2);
             max-width:260px;margin:0 auto;">

          <!-- Colored header -->
          <div id="qrCardHeader" style="padding:14px 16px 12px;
               text-align:center;background:#0070e0;">

            <!-- Provider name as text (logo fallback) -->
            <div id="qrLogoText"
                 style="font-size:20px;font-weight:900;color:white;
                        letter-spacing:1px;margin-bottom:6px;"></div>

            <!-- Account name -->
            <div id="qrAccountName"
                 style="font-size:15px;font-weight:800;color:white;
                        letter-spacing:1.5px;margin-bottom:2px;"></div>

            <!-- Account number -->
            <div id="qrAccountNumber"
                 style="font-size:13px;font-weight:600;
                        color:rgba(255,255,255,0.88);letter-spacing:1px;"></div>
          </div>

          <!-- White card body with QR image -->
          <div style="background:white;padding:14px;text-align:center;">

            <!-- QR frame -->
            <div style="width:200px;height:200px;margin:0 auto;
                 border-radius:10px;border:3px solid #eee;
                 display:flex;align-items:center;justify-content:center;
                 overflow:hidden;background:white;">
              <img id="qrImage" src="" alt="QR Code"
                   style="width:100%;height:100%;object-fit:contain;display:none;">
              <div id="qrPlaceholder" style="text-align:center;padding:10px;">
                <div style="font-size:46px;">📱</div>
                <div style="font-size:10px;color:#aaa;margin-top:6px;line-height:1.5;">
                  No QR image configured.<br>Upload one in Settings → Payment Methods.
                </div>
              </div>
            </div>

            <!-- Amount -->
            <div id="qrAmountLabel"
                 style="margin-top:10px;font-size:20px;font-weight:900;color:#006600;"></div>

            <!-- Instruction -->
            <div style="margin-top:4px;font-size:11px;color:#888;">
              Ask customer to scan with their
              <strong id="qrScanLabel">e-wallet</strong> app
            </div>

            <!-- Hide button -->
            <button type="button" onclick="toggleQRCode()"
                    style="margin-top:10px;padding:5px 22px;background:#888;
                           color:white;border:none;border-radius:4px;
                           font-size:11px;font-weight:600;cursor:pointer;">
              ✕ Hide QR
            </button>
          </div>
        </div>
      </div>

      <!-- Ref No -->
      <div class="pm-row">
        <span class="pm-label">Ref. No.</span>
        <input type="text" class="pm-input" id="wmRefNo"
               placeholder="Enter transaction reference" maxlength="50">
      </div>

      <!-- Amount (readonly) -->
      <div class="pm-row">
        <span class="pm-label">Amount <?= $currencySymbol ?></span>
        <input type="number" class="pm-input" id="wmAmount"
               placeholder="0.00" step="0.01" readonly>
      </div>

      <div class="pm-change" id="wmStatus">Awaiting confirmation</div>

      <div class="pm-btns">
        <button class="pm-btn cancel"  onclick="closeWalletModal()">Cancel</button>
        <button class="pm-btn proceed" onclick="processWalletPayment()">✓ Confirm</button>
      </div>

      <div style="margin-top:10px;font-size:10px;color:#888;text-align:center;">
        💡 Ensure customer payment is completed before confirming.
      </div>
    </div>
  </div>
</div>
<!-- ERROR MODAL -->
<div class="modal-overlay" id="errModal">
  <div class="err-modal">
    <div class="pay-modal-title">
      <span>NOTICE</span>
      <button class="modal-x" onclick="closeErr()">✕</button>
    </div>
    <div class="err-bar">
    </div>
    <div class="err-body">
      <div class="err-icon">✕</div>
      <span class="err-msg" id="errMsg">An error occurred.</span>
    </div>
    <div class="err-foot"><button class="ok-btn" onclick="closeErr()">OK</button></div>
  </div>
</div>

<!-- DASHBOARD LOGIN MODAL -->
<div class="modal-overlay" id="dashModal">
  <div class="dash-modal">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
      <h2>🔑 Login</h2>
      <button onclick="closeDashLogin()"
              style="background:none;border:none;font-size:22px;cursor:pointer;color:#888;">✖</button>
    </div>
    <iframe id="dashIframe" src=""
            style="width:100%;height:600px;border:none;border-radius:6px;"></iframe>
  </div>
</div>
<!-- HELP MODAL -->
<div class="modal-overlay" id="helpModal">
  <div style="background:white;border-radius:10px;width:580px;max-width:95vw;
              max-height:85vh;overflow:hidden;box-shadow:0 16px 50px rgba(0,0,0,0.6);
              display:flex;flex-direction:column;">

    <!-- Header -->
    <div style="background:linear-gradient(180deg,#ff9900,#ff6600);
                padding:12px 16px;display:flex;justify-content:space-between;align-items:center;
                flex-shrink:0;">
      <span style="font-weight:800;font-size:15px;color:white;letter-spacing:1px;">
        📖 POS KEYBOARD SHORTCUTS & GUIDE
      </span>
      <button onclick="closeHelp()"
              style="background:#aa2200;color:white;border:none;border-radius:3px;
                     width:24px;height:24px;font-size:14px;cursor:pointer;font-weight:700;">✕</button>
    </div>

    <!-- Body -->
    <div style="padding:18px;overflow-y:auto;color:#222;font-size:13px;">

      <!-- Keyboard Shortcuts -->
      <div style="font-weight:800;font-size:12px;color:#ff6600;text-transform:uppercase;
                  letter-spacing:1px;margin-bottom:10px;border-bottom:2px solid #ff6600;
                  padding-bottom:4px;">⌨️ Keyboard Shortcuts</div>
      <table style="width:100%;border-collapse:collapse;margin-bottom:18px;">
        <thead>
          <tr style="background:#f5f5f5;">
            <th style="padding:7px 10px;text-align:left;font-size:11px;color:#555;border-bottom:1px solid #ddd;">Key</th>
            <th style="padding:7px 10px;text-align:left;font-size:11px;color:#555;border-bottom:1px solid #ddd;">Action</th>
            <th style="padding:7px 10px;text-align:left;font-size:11px;color:#555;border-bottom:1px solid #ddd;">Description</th>
          </tr>
        </thead>
        <tbody>
          <tr style="border-bottom:1px solid #f0f0f0;">
            <td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">Enter</span></td>
            <td style="padding:7px 10px;font-weight:600;">Cash Payment</td>
            <td style="padding:7px 10px;color:#666;">Opens the cash payment modal</td>
          </tr>
          <tr style="border-bottom:1px solid #f0f0f0;background:#fafafa;">
            <td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">F2</span></td>
            <td style="padding:7px 10px;font-weight:600;">Clear / Cancel</td>
            <td style="padding:7px 10px;color:#666;">Clears all items from the current order</td>
          </tr>
          <tr style="border-bottom:1px solid #f0f0f0;">
            <td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">F3</span></td>
            <td style="padding:7px 10px;font-weight:600;">E-Wallet Payment</td>
            <td style="padding:7px 10px;color:#666;">Opens GCash / Maya / GrabPay payment modal</td>
          </tr>
          <tr style="border-bottom:1px solid #f0f0f0;background:#fafafa;">
            <td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">F5</span></td>
            <td style="padding:7px 10px;font-weight:600;">Focus Search / Menu</td>
            <td style="padding:7px 10px;color:#666;">Jumps focus to the product search bar</td>
          </tr>
          <tr style="border-bottom:1px solid #f0f0f0;">
            <td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">F8</span></td>
            <td style="padding:7px 10px;font-weight:600;">Custom Product</td>
            <td style="padding:7px 10px;color:#666;">Focuses the custom product name input</td>
          </tr>
          <tr style="background:#fafafa;">
            <td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">F1</span></td>
            <td style="padding:7px 10px;font-weight:600;">Help</td>
            <td style="padding:7px 10px;color:#666;">Opens this help guide</td>
          </tr>
        </tbody>
      </table>

      <!-- How to Process a Sale -->
      <div style="font-weight:800;font-size:12px;color:#ff6600;text-transform:uppercase;
                  letter-spacing:1px;margin-bottom:10px;border-bottom:2px solid #ff6600;
                  padding-bottom:4px;">🛒 How to Process a Sale</div>
      <ol style="padding-left:18px;line-height:2;color:#444;margin-bottom:18px;">
        <li>Click a product from the menu grid to add it to the order panel.</li>
        <li>Adjust quantity directly in the order panel by editing the qty field.</li>
        <li>Enter the cash amount in the <strong>Cash <?= $currencySymbol ?></strong> field or press <strong>Enter</strong> to open payment.</li>
        <li>Confirm the payment — a receipt will print automatically.</li>
      </ol>

      <!-- Custom Products -->
      <div style="font-weight:800;font-size:12px;color:#ff6600;text-transform:uppercase;
                  letter-spacing:1px;margin-bottom:10px;border-bottom:2px solid #ff6600;
                  padding-bottom:4px;">➕ Adding Custom Products</div>
      <ul style="padding-left:18px;line-height:2;color:#444;margin-bottom:18px;">
        <li>Use the <strong>Custom Product strip</strong> at the bottom of the menu panel.</li>
        <li>Either type a <strong>Custom Name</strong> OR select a <strong>Bread Type</strong> — not both.</li>
        <li>Selecting a bread type auto-fills the price from the database (read-only).</li>
        <li>Select <strong>Kilograms</strong> as unit to allow decimal quantities (e.g. 1.5 kg).</li>
        <li>Press <strong>F8</strong> to quickly jump to the custom name field.</li>
      </ul>

      <!-- E-Wallet -->
      <div style="font-weight:800;font-size:12px;color:#ff6600;text-transform:uppercase;
                  letter-spacing:1px;margin-bottom:10px;border-bottom:2px solid #ff6600;
                  padding-bottom:4px;">📱 E-Wallet Payment</div>
      <ul style="padding-left:18px;line-height:2;color:#444;margin-bottom:18px;">
        <li>Press <strong>F3</strong> or click the <strong>E-Wallet</strong> button to open the modal.</li>
        <li>Select the provider (GCash, Maya, GrabPay, Other).</li>
        <li>Click <strong>Show QR</strong> and let the customer scan with their app.</li>
        <li>Once the customer completes payment, enter the <strong>Reference No.</strong> from their receipt.</li>
        <li>Click <strong>Confirm</strong> to finalize the transaction.</li>
      </ul>

      <!-- Status Indicators -->
      <div style="font-weight:800;font-size:12px;color:#ff6600;text-transform:uppercase;
                  letter-spacing:1px;margin-bottom:10px;border-bottom:2px solid #ff6600;
                  padding-bottom:4px;">🔌 Status Indicators</div>
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:8px;">
        <div style="display:flex;align-items:center;gap:6px;background:#f5f5f5;
                    padding:6px 12px;border-radius:20px;">
          <span style="color:#6bf112;font-weight:700;">● ONLINE</span>
          <span style="color:#666;font-size:11px;">Server & DB reachable</span>
        </div>
        <div style="display:flex;align-items:center;gap:6px;background:#f5f5f5;
                    padding:6px 12px;border-radius:20px;">
          <span style="color:#ec4747;font-weight:700;">● OFFLINE</span>
          <span style="color:#666;font-size:11px;">No server connection</span>
        </div>
        <div style="display:flex;align-items:center;gap:6px;background:#f5f5f5;
                    padding:6px 12px;border-radius:20px;">
          <span style="color:#ffcc66;font-weight:700;">● CHECKING</span>
          <span style="color:#666;font-size:11px;">Testing connection...</span>
        </div>
      </div>
    </div>

    <!-- Footer -->
    <div style="background:#f5f5f5;border-top:1px solid #ddd;padding:10px 16px;
                display:flex;justify-content:space-between;align-items:center;
                flex-shrink:0;font-size:11px;color:#888;">
      <span>Angel's Bakeshop POS v1.0 — Upper Batinguel, Dumaguete City</span>
      <button onclick="closeHelp()"
              style="background:linear-gradient(180deg,#ff9900,#cc6600);color:white;
                     border:none;border-radius:4px;padding:6px 20px;
                     font-size:12px;font-weight:700;cursor:pointer;">Close</button>
    </div>
  </div>
</div>
<!-- PRODUCT DATA -->
<script>
/* 1. Declare products first from PHP to avoid disappearance of products in the panel */
const products = <?php echo json_encode($products, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

/* 2. Then Define Cache second */
const Cache = (() => {
  const _store = {
    products:     null,
    categories:   null,
    breads:       null,
    productMap:   null,
    transactions: [],
    lastPing:     null,
    lastPingAt:   0
  };
  return {
    initProducts(arr) {
      _store.products   = arr;
      _store.productMap = {};
      arr.forEach(p => { _store.productMap[p.id] = p; });
    },
    getProducts()              { return _store.products; },
    getProductById(id)         { return _store.productMap?.[id] ?? null; },
    updateProductStock(id, field, value) {
      const p = _store.productMap?.[id];
      if(p) p[field] = value;
    },
    initCategories(arr)        { _store.categories = arr; },
    getCategories()            { return _store.categories; },
    initBreads(arr)            { _store.breads = arr; },
    getBreads()                { return _store.breads; },
    pushTransaction(txn) {
      _store.transactions.unshift(txn);
      if(_store.transactions.length > 20) _store.transactions.pop();
    },
    getTransaction(id)         { return _store.transactions.find(t => t.id === id) ?? null; },
    getRecentTransactions()    { return _store.transactions; },
    setConnStatus(online) {
      _store.lastPing   = online;
      _store.lastPingAt = Date.now();
    },
    getConnStatus()  { return _store.lastPing; },
    getConnAge()     { return Date.now() - _store.lastPingAt; }
  };
})();

/* 3. Seed cache LAST — after both products and Cache exist */
Cache.initProducts(products);

Cache.initCategories((() => {
  const cats = [];
  products.forEach(p => {
    const c = (p.category || 'General').trim();
    if(c && !cats.includes(c)) cats.push(c);
  });
  return cats;
})());
</script>
<script>
/* ─── Clock ─── */
function updateClock() {
  document.getElementById('currentTime').textContent =
    new Date().toLocaleString('en-US',{
      timeZone:'Asia/Manila', weekday:'short', year:'numeric',
      month:'short', day:'numeric', hour:'2-digit',
      minute:'2-digit', second:'2-digit', hour12:true
    });
}
setInterval(updateClock,1000); updateClock();

/* ─── Sidebar ─── */
function toggleSidebar() {
  const sb = document.getElementById('sidebar');
  sb.style.display = sb.style.display==='flex' ? 'none' : 'flex';
  document.getElementById('menuBtn').textContent = sb.style.display==='flex' ? '✖' : '☰';
}

/* ─── Dash login modal ─── */
function openDashLogin() {
  document.getElementById('dashIframe').src = 'login.php?redirect=dashboard.php&iframe=true';
  document.getElementById('dashModal').classList.add('show');
}
function closeDashLogin() {
  document.getElementById('dashModal').classList.remove('show');
  document.getElementById('dashIframe').src = '';
}
document.getElementById('dashModal').addEventListener('click',function(e){ if(e.target===this) closeDashLogin(); });
window.addEventListener('message',function(e){
  if(e.origin!==window.location.origin) return;
  if(e.data.action==='loginSuccess'){
    closeDashLogin();
    window.location.href = e.data.redirect+(e.data.redirect.includes('?')?'&':'?')+'loggedin=true';
  }
});

/* ─── Error modal ─── */
function showErr(msg){
  document.getElementById('errMsg').textContent=msg;
  document.getElementById('errModal').classList.add('show');
}
function closeErr(){ document.getElementById('errModal').classList.remove('show'); }

/* ─── State ─── */
let checkout=[], currentCategory='ALL';

/* ─── Render grid ─── */
function renderGrid(list){
  const grid=document.getElementById('menuGrid');
  const items=list!==undefined?list:getFiltered();
  grid.innerHTML='';
  if(!items.length){ grid.innerHTML='<div class="no-items">No products found.</div>'; return; }
  items.forEach(p=>{
    const out = (p.measurement_type==='kg'&&parseFloat(p.kg)<=0)||
                (p.measurement_type!=='kg'&&parseInt(p.pieces)<=0);
    const low = p.is_low_stock&&!out;
    const qty = p.measurement_type==='kg'
      ? parseFloat(p.kg).toFixed(2)+' kg'
      : parseInt(p.pieces)+' pcs';
    const el=document.createElement('div');
    el.className='menu-item'+(out?' out':'')+(low?' low-s':'');
    el.innerHTML=`
      ${low?'<span class="low-badge">Low</span>':''}
      <div class="menu-item-img"
           style="background-image:url('${p.image_path}')"
           onerror="this.style.backgroundImage='url(image/cake.jfif)'"></div>
      <div class="menu-item-info">
        <div class="menu-item-name">${p.name}</div>
        <div class="menu-item-price">₱${parseFloat(p.price).toFixed(2)}</div>
        <div class="menu-item-qty ${out?'out-label':low?'low':''}">
          ${out?'OUT OF STOCK':qty}
        </div>
      </div>
      ${out?'<div class="out-overlay"><span>OUT</span></div>':''}`;
    if(!out) el.onclick=()=>addToCart(p);
    grid.appendChild(el);
  });
}

function getFiltered(){
  const q = (document.getElementById('searchInput').value || '').trim().toLowerCase();
  return Cache.getProducts().filter(p => {
    const cm = currentCategory === 'ALL' || (p.category || '').trim() === currentCategory;
    const nm = !q || p.name.toLowerCase().includes(q) ||
               p.code.toLowerCase().includes(q) ||
               (p.brand && p.brand.toLowerCase().includes(q));
    return cm && nm;
  });
}

function filterByCategory(cat,btn){
  currentCategory=cat;
  document.querySelectorAll('.cat-tab').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('searchInput').value='';
  renderGrid();
}
function filterProducts(){ renderGrid(); }

/* ─── Add to cart ─── */
function addToCart(p){
  const ex = checkout.find(i => i.id === p.id);
  if(ex){ ex.qty++; }
  else checkout.push({
    id: p.id, name: p.name, price: parseFloat(p.price), qty: 1,
    measurement_type: p.measurement_type,
    unit: p.measurement_type === 'kg' ? 'kg' : 'pcs'
  });

  // Update cache stock — no re-fetch needed
  const src = Cache.getProductById(p.id);
  if(src){
    if(src.measurement_type === 'kg')
      Cache.updateProductStock(p.id, 'kg', parseFloat((src.kg - 1).toFixed(2)));
    else
      Cache.updateProductStock(p.id, 'pieces', src.pieces - 1);
  }
  renderOrderPanel(); renderGrid();
}

/* ─── Render order panel ─── */
function renderOrderPanel(){
  const ol=document.getElementById('orderList');
  if(!checkout.length){
    ol.innerHTML='<div class="order-empty">No items added yet.</div>';
    document.getElementById('itemCount').textContent='0';
    document.getElementById('totalAmount').textContent='0.00';
    document.getElementById('changeRow').textContent='—';
    document.getElementById('changeRow').className='change-row';
    return;
  }
  ol.innerHTML='';
  checkout.forEach((item,idx)=>{
    const row=document.createElement('div');
    row.className='order-item';
    const isKg = item.measurement_type==='kg';
    const step = isKg ? '0.01' : '1';
    const min  = isKg ? '0.01' : '1';

    row.innerHTML=`
      <input type="number"
             class="qty-input"
             value="${item.qty}"
             min="${min}"
             step="${step}"
             title="Edit quantity"
             style="
               width:48px;
               padding:2px 4px;
               background:#fff9;
               border:1.5px solid #aaa;
               border-radius:4px;
               font-size:12px;
               font-weight:700;
               color:#111;
               text-align:center;
             "
             onclick="event.stopPropagation()"
             onchange="updateItemQty(${idx}, this.value)"
             onkeydown="if(event.key==='Enter'){this.blur();}">
      <span class="name" title="${item.name}">${item.name}</span>
      <span class="unit-label" style="font-size:10px;color:#888;flex-shrink:0;">${isKg?'kg':'pcs'}</span>
      <span class="price" id="item-price-${idx}">₱${(item.price*item.qty).toFixed(2)}</span>
      <span class="del" onclick="removeItem(event,${idx})">✕</span>`;

    row.onclick=()=>{
      document.querySelectorAll('.order-item').forEach(r=>r.classList.remove('selected'));
      row.classList.add('selected');
    };
    ol.appendChild(row);
  });

  const total=checkout.reduce((s,i)=>s+i.price*i.qty,0);
  document.getElementById('itemCount').textContent=checkout.length;
  document.getElementById('totalAmount').textContent=total.toFixed(2);
  autoCalcChange();
}
function removeItem(e, idx){
  e.stopPropagation();
  const item = checkout[idx];
  const src  = Cache.getProductById(item.id);
  if(src){
    if(src.measurement_type === 'kg')
      Cache.updateProductStock(item.id, 'kg', parseFloat((src.kg + item.qty).toFixed(2)));
    else
      Cache.updateProductStock(item.id, 'pieces', src.pieces + item.qty);
  }
  checkout.splice(idx, 1);
  renderOrderPanel(); renderGrid();
}

function clearCheckout(){
  checkout.forEach(item => {
    const src = Cache.getProductById(item.id);
    if(src){
      if(src.measurement_type === 'kg')
        Cache.updateProductStock(item.id, 'kg', parseFloat((src.kg + item.qty).toFixed(2)));
      else
        Cache.updateProductStock(item.id, 'pieces', src.pieces + item.qty);
    }
  });
  checkout = [];
  document.getElementById('amountPaid').value = '';
  renderOrderPanel(); renderGrid();
}

/* ─── Change calc ─── */
function autoCalcChange(){
  const paid=parseFloat(document.getElementById('amountPaid').value)||0;
  const total=checkout.reduce((s,i)=>s+i.price*i.qty,0);
  const row=document.getElementById('changeRow');
  if(!paid){ row.textContent='—'; row.className='change-row'; return; }
  if(paid>=total){ row.textContent='Change: ₱'+(paid-total).toFixed(2); row.className='change-row'; }
  else { row.textContent='Short: ₱'+(total-paid).toFixed(2); row.className='change-row short'; }
}

/* ─── Payment modal ─── */
function openPayModal(method){
  if(!checkout.length) return showErr('Please add items to the order first.');
  const total=checkout.reduce((s,i)=>s+i.price*i.qty,0);
  document.getElementById('payModalTitle').textContent=(method||'CASH')+' PAYMENT';
  document.getElementById('pmSummary').innerHTML=
    checkout.map(i=>`<div class="pm-summary-row"><span>${i.qty}× ${i.name}</span><span>₱${(i.price*i.qty).toFixed(2)}</span></div>`).join('')+
    `<div class="pm-summary-row total"><span>TOTAL</span><span>₱${total.toFixed(2)}</span></div>`;
  document.getElementById('pmCash').value='';
  document.getElementById('pmChange').textContent='Change: ₱0.00';
  document.getElementById('pmChange').className='pm-change';
  document.getElementById('payModal').classList.add('show');
  setTimeout(()=>document.getElementById('pmCash').focus(),100);
}
function closePayModal(){ document.getElementById('payModal').classList.remove('show'); }

/* ─── E-Wallet Modal ─── */
function openWalletModal(){
  if(!checkout.length) return showErr('Please add items to the order first.');
  
  const total = checkout.reduce((s,i) => s + i.price * i.qty, 0);
  
  // Populate summary
  document.getElementById('wmSummary').innerHTML =
    checkout.map(i => `<div class="pm-summary-row"><span>${i.qty}× ${i.name}</span><span>₱${(i.price*i.qty).toFixed(2)}</span></div>`).join('') +
    `<div class="pm-summary-row total"><span>TOTAL</span><span>₱${total.toFixed(2)}</span></div>`;
  
  // Reset fields
  document.getElementById('wmProvider').value = '';
  document.getElementById('wmRefNo').value = '';
  document.getElementById('wmAmount').value = total.toFixed(2);
  document.getElementById('wmStatus').textContent = 'Awaiting confirmation';
  document.getElementById('wmStatus').className = 'pm-change';
  
  // Show modal & focus
  document.getElementById('walletModal').classList.add('show');
  setTimeout(() => document.getElementById('wmProvider').focus(), 100);
}


function closeWalletModal(){
  document.getElementById('walletModal').classList.remove('show');
}

/* ─── Provider color map (used for header background) ─── */
const providerColors = {
   GCash:   { icon: '💙', color: '#0070e0', image: 'image/logo.png'   },
  Maya:    { icon: '💚', color: '#008f4c', image: 'qr/maya.png'    },
  GrabPay: { icon: '💚', color: '#00b14f', image: 'qr/grabpay.png' },
  Other:   { icon: '💳', color: '#888888', image: ''                }
};

function updateWalletHints(){
  const sel      = document.getElementById('wmProvider');
  const provider = sel.value;
  const status   = document.getElementById('wmStatus');
  const qrBtn    = document.getElementById('btnShowQR');
  const qrBox    = document.getElementById('qrContainer');

  const hints = {
    GCash:   'Check GCash app for transaction status',
    Maya:    'Verify in Maya app before confirming',
    GrabPay: 'Ensure GrabPay shows "Success"',
    Other:   'Keep proof of payment for verification'
  };

  status.textContent = hints[provider] || 'Awaiting confirmation';
  status.className   = 'pm-change';

  if(provider){
    const color = providerColors[provider] || '#555555';
    qrBtn.style.display    = 'inline-flex';
    qrBtn.style.alignItems = 'center';
    qrBtn.style.gap        = '4px';
    qrBtn.style.background = `linear-gradient(180deg,${color}cc,${color})`;

    // If QR is already visible, refresh it for the new provider
    if(qrBox.style.display !== 'none') buildQRCard();

  } else {
    qrBtn.style.display = 'none';
    qrBox.style.display = 'none';
  }
}

function buildQRCard(){
  const sel    = document.getElementById('wmProvider');
  const opt    = sel.options[sel.selectedIndex];
  const provider      = sel.value;
  const qrPath        = opt.dataset.qr            || '';
  const accountName   = opt.dataset.accountName   || '';
  const accountNumber = opt.dataset.accountNumber || '';
  const displayName   = opt.dataset.name          || provider;
  const total         = document.getElementById('wmAmount').value;
  const color         = providerColors[provider]   || '#555555';

  // Header
  document.getElementById('qrCardHeader').style.background = color;
  document.getElementById('qrLogoText').textContent        = displayName;
  document.getElementById('qrAccountName').textContent     = accountName;
  document.getElementById('qrAccountNumber').textContent   = accountNumber;
  document.getElementById('qrScanLabel').textContent       = displayName;

  // Amount
  document.getElementById('qrAmountLabel').textContent =
    total ? `₱${parseFloat(total).toFixed(2)}` : '';

  // QR image
  const imgEl = document.getElementById('qrImage');
  const phEl  = document.getElementById('qrPlaceholder');
  if(qrPath){
    imgEl.src            = qrPath;
    imgEl.style.display  = 'block';
    phEl.style.display   = 'none';
    imgEl.onerror = function(){
      this.style.display = 'none';
      phEl.style.display = 'block';
    };
  } else {
    imgEl.style.display = 'none';
    phEl.style.display  = 'block';
  }
}

function toggleQRCode(){
  const qrBox = document.getElementById('qrContainer');
  const qrBtn = document.getElementById('btnShowQR');

  if(qrBox.style.display !== 'none'){
    qrBox.style.display = 'none';
    qrBtn.innerHTML     = '📷 Show QR';
    return;
  }

  if(!document.getElementById('wmProvider').value) return;

  buildQRCard();
  qrBox.style.display = 'block';
  qrBtn.innerHTML     = '✕ Hide QR';
}

function closeWalletModal(){
  document.getElementById('walletModal').classList.remove('show');
  document.getElementById('qrContainer').style.display  = 'none';
  document.getElementById('btnShowQR').innerHTML         = '📷 Show QR';
  document.getElementById('btnShowQR').style.display     = 'none';
}
// Add currency symbol to JavaScript
const currencySymbol = '<?= $currencySymbol ?>';
const autoPrintReceipt = <?= $autoPrintReceipt ? 'true' : 'false' ?>;

async function processWalletPayment() {
  const cashier = document.getElementById('activeCashier').textContent.trim();
  if (!cashier || cashier === 'Not logged in')
    return showErr('Please log in before processing payment.');
  if (!checkout.length)
    return showErr('Cart is empty.');
 
  const provider = document.getElementById('wmProvider').value;
  const refNo    = document.getElementById('wmRefNo').value.trim();
  const total    = checkout.reduce((s, i) => s + i.price * i.qty, 0);
 
  /* ── Validation ── */
  if (!provider)
    return showErr('Please select an e-wallet provider.');
  if (!refNo)
    return showErr('Please enter the transaction reference number.');
  if (refNo.length < 5)
    return showErr('Reference number seems too short. Please verify.');
 
  const now  = new Date();
  const date = now.toISOString().split('T')[0];
  const time = now.toTimeString().split(' ')[0];
 
  /* Get the human-readable name from the <option> data attribute */
  const sel          = document.getElementById('wmProvider');
  const selectedOpt  = sel.options[sel.selectedIndex];
  const providerName = selectedOpt.dataset.name || provider; // e.g. "GCash", "Maya"
 
  const itemsData = checkout.map(item => ({
    id:               item.id,
    name:             item.name,
    qty:              item.qty,
    price:            item.price,
    measurement_type: item.measurement_type,
    unit:             item.unit,
    total:            (item.price * item.qty).toFixed(2)
  }));
 
  try {
    const res = await fetch('record/transaction.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        items:           itemsData,
        total:           total.toFixed(2),
        paid:            total.toFixed(2),  // e-wallet = exact amount
        change:          '0.00',
        cashier,
        date,
        time,
        payment_method:  'wallet',          // backend resolves → providerName
        wallet_provider: providerName,      // "GCash" / "Maya" / etc.
        wallet_ref:      refNo              // reference number
      })
    });
 
    const data = await res.json();
    if (!res.ok || data.status !== 'success')
      throw new Error(data.message || 'Transaction failed.');
 
    /* ── Fill receipt ── */
    _fillReceiptCommon(date, time, cashier, itemsData, total);
    document.getElementById('r-payment-line').innerHTML =
      `${providerName}: ${currencySymbol}${total.toFixed(2)}<br>Ref: ${refNo}`;
    document.getElementById('r-change-line').style.display = 'none'; // no change for e-wallet
 
    const rcpt = document.getElementById('receipt');
    rcpt.style.display = 'block';
    await new Promise(r => setTimeout(r, 80));
    if (autoPrintReceipt) window.print();
    rcpt.style.display = 'none';
 
    closeWalletModal();
 
    if (typeof Swal !== 'undefined') {
      await Swal.fire({
        icon:  'success',
        title: `${providerName} Payment Confirmed! 📱`,
        html:  `Txn <strong>#${data.transaction_id || 'N/A'}</strong><br>
                Provider: <strong>${providerName}</strong><br>
                Ref: <strong>${refNo}</strong><br>
                Total: <strong>${currencySymbol}${total.toFixed(2)}</strong>`,
        confirmButtonColor: '#ff6600'
      });
    }
 
    Cache.pushTransaction({
      id:       data.transaction_id || Date.now(),
      date, time, cashier,
      items:    itemsData,
      total:    total.toFixed(2),
      paid:     total.toFixed(2),
      change:   '0.00',
      method:   providerName,
      ref:      refNo
    });
 
    clearCheckout();
 
  } catch (err) {
    showErr(err.message);
  }
}

// Close wallet modal when clicking outside
document.getElementById('walletModal')?.addEventListener('click', function(e){
  if(e.target === this) closeWalletModal();
});
function pmCalcChange(){
  const paid=parseFloat(document.getElementById('pmCash').value)||0;
  const total=checkout.reduce((s,i)=>s+i.price*i.qty,0);
  const el=document.getElementById('pmChange');
  if(paid>=total){ el.textContent='Change: ₱'+(paid-total).toFixed(2); el.className='pm-change'; }
  else { el.textContent='Short by: ₱'+(total-paid).toFixed(2); el.className='pm-change short'; }
}

/* ─── Process payment ─── */
async function processPayment() {
  const cashier = document.getElementById('activeCashier').textContent.trim();
  if (!cashier || cashier === 'Not logged in')
    return showErr('Please log in before processing payment.');
  if (!checkout.length)
    return showErr('Cart is empty.');
 
  const total  = checkout.reduce((s, i) => s + i.price * i.qty, 0);
  const paid   = parseFloat(document.getElementById('pmCash').value) || 0;
 
  if (paid < total)
    return showErr('Insufficient payment. Please enter the correct amount.');
 
  const change = paid - total;
  const now    = new Date();
  const date   = now.toISOString().split('T')[0];
  const time   = now.toTimeString().split(' ')[0];
 
  const itemsData = checkout.map(item => ({
    id:               item.id,
    name:             item.name,
    qty:              item.qty,
    price:            item.price,
    measurement_type: item.measurement_type,
    unit:             item.unit,
    total:            (item.price * item.qty).toFixed(2)
  }));
 
  try {
    const res = await fetch('record/transaction.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        items:          itemsData,
        total:          total.toFixed(2),
        paid:           paid.toFixed(2),
        change:         change.toFixed(2),
        cashier,
        date,
        time,
        payment_method: 'Cash',   // ← explicit Cash label
        reference_no:   ''        // ← no reference for cash
      })
    });
 
    const data = await res.json();
    if (!res.ok || data.status !== 'success')
      throw new Error(data.message || 'Transaction failed.');
 
    /* ── Fill receipt ── */
    _fillReceiptCommon(date, time, cashier, itemsData, total);
    document.getElementById('r-payment-line').innerHTML =
      `Cash: ${currencySymbol}${paid.toFixed(2)}`;
    document.getElementById('r-change-line').style.display = '';
    document.getElementById('r-change').textContent = change.toFixed(2);
 
    const rcpt = document.getElementById('receipt');
    rcpt.style.display = 'block';
    await new Promise(r => setTimeout(r, 80));
    if (autoPrintReceipt) window.print();
    rcpt.style.display = 'none';
 
    closePayModal();
 
    if (typeof Swal !== 'undefined') {
      await Swal.fire({
        icon:  'success',
        title: 'Payment Successful! 💵',
        html:  `Txn <strong>#${data.transaction_id || 'N/A'}</strong><br>
                Total ${currencySymbol}${total.toFixed(2)} &nbsp;·&nbsp;
                Cash ${currencySymbol}${paid.toFixed(2)} &nbsp;·&nbsp;
                Change ${currencySymbol}${change.toFixed(2)}`,
        confirmButtonColor: '#ff6600'
      });
    }
 
    Cache.pushTransaction({
      id:     data.transaction_id || Date.now(),
      date, time, cashier,
      items:  itemsData,
      total:  total.toFixed(2),
      paid:   paid.toFixed(2),
      change: change.toFixed(2),
      method: 'Cash'
    });
 
    clearCheckout();
 
  } catch (err) {
    showErr(err.message);
  }
}

/* ─── Custom product helpers ─── */

function ciPriceFromType(){
  const sel     = document.getElementById('ciType');
  const priceEl = document.getElementById('ciPrice');
  const unitEl  = document.getElementById('ciUnit');
  const qtyEl   = document.getElementById('ciQty');
  const nameEl  = document.getElementById('ciName');
  const chosen  = sel.value;

  if(chosen){
    // Bread selected → lock price, name, unit, force integers
    const price = sel.options[sel.selectedIndex].getAttribute('data-price') || '';
    priceEl.value    = price;
    priceEl.readOnly = true;
    priceEl.style.background = '#2a2a2a';
    priceEl.style.color      = '#888';

    unitEl.value    = 'pcs';
    unitEl.disabled = true;
    unitEl.style.opacity = '0.5';

    qtyEl.step = '1';
    qtyEl.setAttribute('min','1');

    nameEl.disabled = true;
    nameEl.style.opacity = '0.5';
    nameEl.value = '';

  } else {
    // Bread deselected → unlock everything
    priceEl.readOnly = false;
    priceEl.style.background = '';
    priceEl.style.color      = '';

    unitEl.disabled  = false;
    unitEl.style.opacity = '';

    nameEl.disabled  = false;
    nameEl.style.opacity = '';

    qtyEl.step = '1';
  }
}

function onCustomNameInput(){
  const nameEl  = document.getElementById('ciName');
  const typeEl  = document.getElementById('ciType');
  const priceEl = document.getElementById('ciPrice');
  const unitEl  = document.getElementById('ciUnit');

  if(nameEl.value.trim()){
    // Custom name typed → disable bread select
    typeEl.value    = '';
    typeEl.disabled = true;
    typeEl.style.opacity = '0.5';

    // Unlock price and unit in case bread was previously chosen
    priceEl.readOnly = false;
    priceEl.style.background = '';
    priceEl.style.color      = '';

    unitEl.disabled  = false;
    unitEl.style.opacity = '';

  } else {
    // Name cleared → re-enable bread select
    typeEl.disabled  = false;
    typeEl.style.opacity = '';
  }
}
document.getElementById('ciUnit').addEventListener('change', function(){
  const qtyEl  = document.getElementById('ciQty');
  const typeEl = document.getElementById('ciType');

  if(this.value === 'kg'){
    qtyEl.step  = '0.01';
    qtyEl.setAttribute('min','0.01');
    qtyEl.oninput = null;   // allow decimals — no stripping
    qtyEl.value = '';        // clear so user types fresh decimal value

    typeEl.value    = '';
    typeEl.disabled = true;
    typeEl.style.opacity = '0.5';

    const priceEl = document.getElementById('ciPrice');
    priceEl.readOnly = false;
    priceEl.style.background = '';
    priceEl.style.color = '';

  } else {
    qtyEl.step  = '1';
    qtyEl.setAttribute('min','1');
    qtyEl.value = '1';
    qtyEl.oninput = function(){ this.value = this.value.replace(/[^0-9]/g,''); };

    const nameEl = document.getElementById('ciName');
    if(!nameEl.value.trim()){
      typeEl.disabled = false;
      typeEl.style.opacity = '';
    }
  }
});
function addCustomProduct(){
  const nameEl  = document.getElementById('ciName');
  const typeEl  = document.getElementById('ciType');
  const priceEl = document.getElementById('ciPrice');
  const qtyEl   = document.getElementById('ciQty');
  const unitEl  = document.getElementById('ciUnit');

  const name  = nameEl.value.trim();
  const type  = typeEl.value;
  const price = parseFloat(priceEl.value);
  const unit  = unitEl.value;
  const qty   = (unit === 'kg') ? parseFloat(qtyEl.value) : parseInt(qtyEl.value);

  // Validation
  if(!name && !type)
    return showErr('Please enter a custom product name OR select a bread type.');
  if(isNaN(price) || price <= 0)
    return showErr('Please enter a valid price greater than zero.');
  if(isNaN(qty) || qty <= 0)
    return showErr('Please enter a valid quantity greater than zero.');

  const label = name
    ? (type ? `${name} (${type})` : name)
    : type;

  checkout.push({
    id: 'custom-' + Date.now(),
    name: label,
    price,
    qty,
    measurement_type: unit,
    unit,
    custom: true
  });

  // Reset fields
  nameEl.value    = '';
  nameEl.disabled = false;
  nameEl.style.opacity = '';

  typeEl.value    = '';
  typeEl.disabled = false;
  typeEl.style.opacity = '';

  priceEl.value    = '';
  priceEl.readOnly = false;
  priceEl.style.background = '';
  priceEl.style.color      = '';

  qtyEl.value  = '1';
  qtyEl.step   = '1';
  qtyEl.oninput = function(){ this.value = this.value.replace(/[^0-9]/g,''); };

  unitEl.value    = 'pcs';
  unitEl.disabled = false;
  unitEl.style.opacity = '';

  renderOrderPanel();
}
function updateItemQty(idx, newVal){
  const item   = checkout[idx];
  const isKg   = item.measurement_type === 'kg';
  const parsed = isKg ? parseFloat(newVal) : parseInt(newVal);

  if(isNaN(parsed) || parsed <= 0){
    showErr('Quantity must be greater than zero.');
    renderOrderPanel(); return;
  }

  const src = Cache.getProductById(item.id);
  if(src && !item.custom){
    const diff = parsed - item.qty;
    if(isKg){
      if(diff > 0 && diff > src.kg){
        showErr(`Only ${(src.kg + item.qty).toFixed(2)} kg available for "${item.name}".`);
        renderOrderPanel(); return;
      }
      Cache.updateProductStock(item.id, 'kg', parseFloat((src.kg - diff).toFixed(2)));
    } else {
      if(diff > 0 && diff > src.pieces){
        showErr(`Only ${src.pieces + item.qty} pcs available for "${item.name}".`);
        renderOrderPanel(); return;
      }
      Cache.updateProductStock(item.id, 'pieces', src.pieces - diff);
    }
  }

  item.qty = isKg ? parseFloat(parsed.toFixed(2)) : parsed;
  renderOrderPanel(); renderGrid();
}
/* ─── Connectivity ping ─── */
function checkConn(){
  const el = document.getElementById('connStatus');

  // Reuse cached result if fresh (within 10 seconds)
  if(Cache.getConnAge() < 10000 && Cache.getConnStatus() !== null){
    el.textContent = Cache.getConnStatus() ? '● ONLINE' : '● OFFLINE';
    el.className   = Cache.getConnStatus() ? 'status-online' : 'status-offline';
    return;
  }

  // Show checking state while waiting
  el.textContent = '● CHECKING';
  el.className   = '';
  el.style.color = '#ffcc66';

  fetch('record/ping.php', { cache: 'no-store' })
    .then(async r => {
      const data = await r.json().catch(() => ({}));
      const online = r.ok && data.status === 'ok';

      Cache.setConnStatus(online);
      el.textContent = online ? '● ONLINE' : '● OFFLINE';
      el.className   = online ? 'status-online' : 'status-offline';
      el.style.color = '';

      // Show server time in status bar if online
      if(online && data.time){
        el.title = `Last ping: ${data.time}`;
      }
    })
    .catch(() => {
      Cache.setConnStatus(false);
      el.textContent = '● OFFLINE';
      el.className   = 'status-offline';
      el.style.color = '';
      el.title       = 'Server unreachable';
    });
}

/* ─── Keyboard shortcuts ─── */
document.addEventListener('keydown', function(e){
  const tag    = document.activeElement.tagName;
  const typing = ['INPUT','TEXTAREA','SELECT'].includes(tag);

  // F1 — Help (works even while typing)
  if(e.key === 'F1' || e.keyCode === 112){
    e.preventDefault();
    openHelp();
    return;
  }

  // F5 — Focus search bar (works even while typing)
  if(e.key === 'F5' || e.keyCode === 116){
    e.preventDefault();
    const s = document.getElementById('searchInput');
    s.focus(); s.select();
    return;
  }

  // F8 — Focus custom product name (works even while typing)
  if(e.key === 'F8' || e.keyCode === 119){
    e.preventDefault();
    document.getElementById('ciName').focus();
    return;
  }

  // Keys below only fire when NOT typing in an input
  if(typing) return;

  // Enter — Cash payment
  if(e.key === 'Enter' || e.keyCode === 13){
    e.preventDefault();
    openPayModal('Cash');
    return;
  }

  // F2 — Clear checkout
  if(e.key === 'F2' || e.keyCode === 113){
    e.preventDefault();
    clearCheckout();
    return;
  }

  // F3 — E-Wallet
  if(e.key === 'F3' || e.keyCode === 114){
    e.preventDefault();
    openWalletModal();
    return;
  }
});

// Search bar Enter — quick add by code or exact name
document.getElementById('searchInput').addEventListener('keypress', function(e){
  if(e.key === 'Enter'){
    const q = this.value.trim().toLowerCase();
    const p = Cache.getProducts().find(
      x => x.code.toLowerCase() === q || x.name.toLowerCase() === q
    );
    if(p){ addToCart(p); this.value = ''; renderGrid(); }
  }
});
/* ─── Help modal ─── */
function openHelp(){
  document.getElementById('helpModal').classList.add('show');
}
function closeHelp(){
  document.getElementById('helpModal').classList.remove('show');
}
// Close on backdrop click
document.getElementById('helpModal').addEventListener('click', function(e){
  if(e.target === this) closeHelp();
});
// Run every 15 seconds
setInterval(checkConn, 15000);
checkConn(); // run immediately on load

/* ─── Init ─── */
document.addEventListener('DOMContentLoaded', function(){
  // Seed breads cache from the select options (DOM is ready here)
  Cache.initBreads((() => {
    const opts = document.getElementById('ciType')?.options ?? [];
    const arr  = [];
    for(let i = 1; i < opts.length; i++){
      arr.push({
        name:  opts[i].value,
        price: parseFloat(opts[i].getAttribute('data-price')) || 0
      });
    }
    return arr;
  })());

  renderGrid();
  renderOrderPanel();
  ciPriceFromType();
});
</script>
</body>
</html>