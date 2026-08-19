<?php
session_start();

if (!isset($_SESSION['loggedin'])) { header("Location: index.php"); exit; }

require('dbconn.php');

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
  display: flex; flex-direction: column; align-items: center; line-height: 1.1;
}
.logo-block .brand { font-weight: 900; font-size: 13px; color: white; letter-spacing: 0.5px; }
.logo-block .sub   { font-size: 8px; color: rgba(255,255,255,0.85); letter-spacing: 1.5px; font-weight: 600; }
.top-clock { color: #ffcc66; font-weight: 700; font-size: 12px; margin-left: 15px; }
.top-spacer { flex: 1; }
.top-icon-group { display: flex; gap: 3px; }
.top-icon {
  width: 34px; height: 30px;
  background: linear-gradient(180deg, #e7d8d8, #e2dada);
  border: 1px solid #666; border-radius: 4px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: 14px; color: #ccc; transition: background 0.15s;
}
.top-icon:hover { background: linear-gradient(180deg, #ff8800, #cc5500); color: white; border-color: #ff8800; }
.menu-btn {
  background: linear-gradient(180deg, #555, #3a3a3a);
  border: 1px solid #666; border-radius: 4px;
  color: white; font-size: 18px; cursor: pointer;
  width: 34px; height: 30px;
  display: flex; align-items: center; justify-content: center;
  transition: background 0.15s;
}
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
  transition: background 0.15s;
}
.sidebar a:hover { background: #ff8800; color: white; }

/* ══ MAIN LAYOUT ══════════════════════════════════════════════════════ */
.main-wrap { display: flex; flex: 1; overflow: hidden; }

/* ══ LEFT – ORDER PANEL ══════════════════════════════════════════════ */
.order-panel {
  width: 268px; background: #f0f0f0;
  display: flex; flex-direction: column;
  border-right: 2px solid #888; flex-shrink: 0; color: #222;
}
.order-type {
  background: linear-gradient(180deg, #e0e0e0, #d0d0d0);
  text-align: center; padding: 6px;
  font-weight: 700; font-size: 13px; color: #333;
  border-bottom: 1px solid #bbb; flex-shrink: 0;
}
.order-items { flex: 1; overflow-y: auto; }
.order-items::-webkit-scrollbar { width: 4px; }
.order-items::-webkit-scrollbar-thumb { background: #aaa; border-radius: 2px; }
.order-item {
  display: flex; align-items: center;
  padding: 5px 8px; border-bottom: 1px solid #ddd;
  cursor: pointer; font-size: 12px; gap: 4px; transition: background 0.1s;
}
.order-item:hover { background: #ddeeff; }
.order-item.selected { background: #2a6fc9; color: white; }
.order-item .qty   { width: 18px; font-weight: 700; flex-shrink: 0; }
.order-item .name  { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.order-item .price { min-width: 56px; text-align: right; font-weight: 600; }
.order-item .del   { color: #cc0000; font-weight: 900; font-size: 15px; cursor: pointer; padding: 0 2px; line-height: 1; }
.order-item.selected .del { color: #ffaaaa; }
.order-empty { text-align: center; color: #888; padding: 30px 10px; font-size: 12px; }

.order-footer { background: #ddd; border-top: 2px solid #bbb; padding: 8px; flex-shrink: 0; }
.total-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 2px; }
.total-label  { font-size: 11px; color: #0f0e0e; }
.total-count  { font-size: 13px; font-weight: 700; color: #444; }
.total-amount { font-size: 24px; font-weight: 900; color: #111; }
.cash-row { display: flex; gap: 6px; margin-top: 6px; align-items: center; }
.cash-row label { font-size: 11px; color: #555; font-weight: 600; white-space: nowrap; }
.cash-input {
  flex: 1; padding: 5px 8px;
  border: 1.5px solid #999; border-radius: 4px;
  font-size: 14px; font-weight: 700; background: white;
}
.cash-input:focus { outline: none; border-color: #ff8800; }
.change-row { margin-top: 4px; text-align: right; font-size: 12px; font-weight: 700; color: #006600; min-height: 18px; }
.change-row.short { color: #cc0000; }
.pay-btns { display: flex; gap: 5px; margin-top: 7px; }
.pay-btn {
  flex: 1; padding: 10px 4px; border: none; border-radius: 5px;
  cursor: pointer; font-weight: 700; font-size: 12px;
  display: flex; flex-direction: column; align-items: center; gap: 2px;
  transition: filter 0.15s; color: white;
}
.pay-btn:hover { filter: brightness(1.15); }
.pay-btn.cash   { background: linear-gradient(180deg, #ff9900, #dd6600); }
.pay-btn.wallet { background: linear-gradient(180deg, #dd3333, #aa1111); }
.pay-btn.more   { background: linear-gradient(180deg, #555, #333); }
.pay-btn .icon  { font-size: 16px; }

/* ══ RIGHT – MENU PANEL ══════════════════════════════════════════════ */
.menu-panel { flex: 1; display: flex; flex-direction: column; background: #2a2a2a; min-width: 0; }
.cat-tabs { display: flex; background: #444; flex-shrink: 0; overflow-x: auto; border-bottom: 2px solid #222; }
.cat-tabs::-webkit-scrollbar { height: 3px; }
.cat-tabs::-webkit-scrollbar-thumb { background: #ff8800; }
.cat-tab {
  padding: 9px 16px; font-size: 12px; font-weight: 600;
  cursor: pointer; background: #555; color: #ccc;
  border: none; white-space: nowrap;
  border-right: 1px solid #3a3a3a; transition: background 0.15s; flex-shrink: 0;
}
.cat-tab:hover { background: #666; color: white; }
.cat-tab.active { background: linear-gradient(180deg, #ff9900, #ff6600); color: white; }

.sub-bar { display: flex; align-items: center; background: #333; padding: 5px 8px; gap: 8px; flex-shrink: 0; border-bottom: 1px solid #222; }
.search-input {
  flex: 1; padding: 6px 10px;
  background: #555; border: 1.5px solid #666;
  border-radius: 5px; color: white; font-size: 12px; transition: border-color 0.15s;
}
.search-input::placeholder { color: #c0bbbb; }
.search-input:focus { outline: none; border-color: #ff8800; background: #444; }
.hint-key { background: #444; border: 1px solid #666; border-radius: 3px; padding: 2px 6px; font-family: monospace; color: #1ffa02; font-size: 10px; }

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
.menu-item:hover  { border-color: #ff8800; transform: translateY(-1px); }
.menu-item.out    { opacity: 0.45; cursor: not-allowed; }
.menu-item.low-s  { border-color: #cc8800; }
.menu-item-img    { height: 60px; background-size: cover; background-position: center; background-color: #333; flex-shrink: 0; }
.menu-item-info   { flex: 1; padding: 4px 5px; display: flex; flex-direction: column; justify-content: center; }
.menu-item-name   { font-size: 10px; font-weight: 600; line-height: 1.2; color: #f0f0f0; }
.menu-item-price  { font-size: 10px; color: #ffcc66; font-weight: 700; margin-top: 1px; }
.menu-item-qty    { font-size: 9px; color: #ddcdcd; }
.menu-item-qty.low       { color: #ff8800; font-weight: 700; }
.menu-item-qty.out-label { color: #cc4444; font-weight: 700; }
.out-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; }
.out-overlay span { background: #cc0000; color: white; font-size: 9px; font-weight: 700; padding: 2px 6px; border-radius: 3px; }
.low-badge { position: absolute; top: 3px; right: 3px; background: #cc8800; color: white; font-size: 8px; font-weight: 700; padding: 1px 4px; border-radius: 2px; }
.no-items { grid-column: 1/-1; text-align: center; color: #666; padding: 40px; font-size: 14px; }

/* Custom strip */
.custom-strip { background: #222; border-top: 1px solid #333; padding: 6px 8px; flex-shrink: 0; }
.custom-strip-title { font-size: 10px; font-weight: 700; color: #fff8f8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
.custom-row { display: flex; gap: 5px; flex-wrap: wrap; }
.custom-input { background: #3a3a3a; border: 1px solid #555; color: #eee; border-radius: 4px; padding: 5px 7px; font-size: 12px; transition: border-color 0.15s; }
.custom-input::placeholder { color: #b8b4b4; }
.custom-input:focus { outline: none; border-color: #ff8800; background: #444; }
.ci-name { flex: 2; min-width: 100px; }
.ci-type { flex: 2; min-width: 120px; }
.ci-price { flex: 1; min-width: 70px; }
.ci-qty  { width: 60px; }
.ci-unit { width: 90px; }
.btn-custom-add { padding: 5px 14px; background: linear-gradient(180deg, #ff9900, #cc6600); color: white; border: none; border-radius: 4px; font-size: 12px; font-weight: 700; cursor: pointer; transition: filter 0.15s; white-space: nowrap; }
.btn-custom-add:hover { filter: brightness(1.1); }

.customer-bar { background: #3f3939; border-top: 1px solid #222; padding: 5px 10px; font-size: 11px; color: #ffeeee; flex-shrink: 0; display: flex; justify-content: space-between; align-items: center; }
.customer-bar .cashier-name { color: #67d40e; font-weight: 700; }

/* ══ STATUS BAR ══════════════════════════════════════════════════════ */
.status-bar { background: #1a1a1a; border-top: 1px solid #111; display: flex; align-items: center; padding: 3px 10px; gap: 16px; font-size: 10px; color: #fffefe; flex-shrink: 0; height: 28px; }
.status-bar span { border-right: 1px solid #333; padding-right: 16px; }
.status-bar span:last-child { border-right: none; }
.status-offline { color: #ec4747 !important; font-weight: 700; }
.status-online  { color: #6bf112 !important; font-weight: 700; }

/* ══ MODALS ══════════════════════════════════════════════════════════ */
.modal-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.45); display: none; align-items: center; justify-content: center; z-index: 9999; }
.modal-overlay.show { display: flex; }

.pay-modal { background: linear-gradient(180deg, #e0e0e0, #cccccc); border: 2px solid #888; border-radius: 7px; width: 370px; box-shadow: 0 12px 40px rgba(0,0,0,0.6); overflow: hidden; }
.pay-modal-title { background: linear-gradient(180deg, #ff9900, #ff6600); padding: 8px 14px; display: flex; justify-content: space-between; align-items: center; }
.pay-modal-title span { font-weight: 700; font-size: 13px; color: white; letter-spacing: 1px; }
.modal-x { background: #aa2200; color: white; border: none; border-radius: 3px; width: 22px; height: 22px; font-size: 13px; cursor: pointer; font-weight: 700; display: flex; align-items: center; justify-content: center; }
.modal-x:hover { background: #cc0000; }
.pay-modal-body { padding: 16px; color: #222; }
.pm-row { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
.pm-label { font-size: 11px; font-weight: 600; width: 80px; color: #444; }
.pm-input { flex: 1; padding: 7px 10px; border: 1.5px solid #aaa; border-radius: 4px; font-size: 14px; background: white; font-weight: 700; }
.pm-input:focus { outline: none; border-color: #ff8800; }
.pm-summary { background: #f8f8f8; border: 1px solid #ddd; border-radius: 4px; padding: 10px; margin-bottom: 12px; font-size: 12px; max-height: 180px; overflow-y: auto; }
.pm-summary-row { display: flex; justify-content: space-between; margin-bottom: 3px; }
.pm-summary-row.total { font-size: 15px; font-weight: 800; color: #006600; margin-top: 4px; border-top: 1px solid #ddd; padding-top: 4px; }
.pm-change { text-align: center; padding: 6px; border-radius: 4px; font-size: 13px; font-weight: 700; margin-bottom: 12px; background: #e8f5e8; color: #006600; transition: background 0.2s; }
.pm-change.short { background: #fde8e8; color: #cc0000; }
.pm-btns { display: flex; gap: 8px; }
.pm-btn { flex: 1; padding: 9px; border: none; border-radius: 5px; font-size: 13px; font-weight: 700; cursor: pointer; transition: filter 0.15s; color: white; }
.pm-btn:hover { filter: brightness(1.1); }
.pm-btn.cancel  { background: linear-gradient(180deg, #888, #555); }
.pm-btn.proceed { background: linear-gradient(180deg, #ff9900, #cc6600); }

.err-modal { background: linear-gradient(180deg, #eee, #ddd); border: 2px solid #888; border-radius: 7px; width: 320px; box-shadow: 0 12px 40px rgba(0,0,0,0.6); overflow: hidden; }
.err-bar { background: #cc2200; padding: 5px 14px; display: flex; justify-content: space-between; align-items: center; }
.err-bar span { font-weight: 700; font-size: 12px; color: white; }
.err-body { padding: 20px 16px; display: flex; gap: 14px; align-items: center; color: #222; }
.err-icon { width: 44px; height: 44px; background: #cc0000; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 22px; font-weight: 900; flex-shrink: 0; }
.err-msg { font-size: 13px; line-height: 1.4; }
.err-foot { padding: 0 16px 16px; display: flex; justify-content: center; }
.ok-btn { background: linear-gradient(180deg, #888, #666); color: white; border: none; border-radius: 4px; padding: 7px 44px; font-size: 13px; font-weight: 700; cursor: pointer; }
.ok-btn:hover { filter: brightness(1.1); }

.dash-modal { background: white; border-radius: 10px; width: 200%; max-width: 90%; height: 90%; padding: 24px; box-shadow: 0 16px 50px rgba(0,0,0,0.5); max-height: 150vh; overflow-y: auto; color: #222; }
.dash-modal h2 { color: #ff6600; text-align: center; margin-bottom: 8px; }
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
}

.confirm-box h3 {
  color: #ff8800;
  margin-bottom: 12px;
  font-size: 16px;
}

.confirm-box p {
  color: #ccc;
  font-size: 13px;
  margin-bottom: 20px;
  line-height: 1.5;
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
  cursor: grab;
  position: relative;
}

.logout-modal:active {
  cursor: grabbing;
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
.confirm-btn:hover { filter: brightness(1.15); }

.confirm-btn.stay {
  background: linear-gradient(180deg, #ff9900, #cc6600);
}
/* Custom Success Modal */
.success-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.7);
  display: none;
  align-items: center;
  justify-content: center;
  z-index: 10002;
}
.success-modal-overlay.show { display: flex; }
.modal-overlay#errModal {
  z-index: 9998;
}
.success-modal-overlay {
  z-index: 10002;
}
.success-modal {
  background: #282828;
  border: 2px solid #00c853;
  border-radius: 12px;
  padding: 30px;
  width: 400px;
  max-width: 90vw;
  box-shadow: 0 20px 60px rgba(0,200,83,0.3);
  text-align: center;
  cursor: grab;
  position: relative;
  animation: successIn 0.3s ease;
}
.success-modal:active { cursor: grabbing; }

@keyframes successIn {
  from { opacity: 0; transform: scale(0.9) translateY(-20px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}

.success-modal .sm-icon {
  width: 80px;
  height: 80px;
  margin: 0 auto 20px;
  background: linear-gradient(135deg, #00c853, #009624);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 40px;
  animation: successPulse 2s ease-in-out infinite;
}

@keyframes successPulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(0,200,83,0.4); }
  50% { box-shadow: 0 0 0 15px rgba(0,200,83,0); }
}

.success-modal h3 {
  color: #f0f0f0;
  font-size: 22px;
  font-weight: 800;
  margin-bottom: 8px;
}

.success-modal .sm-subtitle {
  color: #aaa;
  font-size: 13px;
  margin-bottom: 20px;
}

.success-modal .sm-details {
  background: #1e1e1e;
  border: 1px solid #333;
  border-radius: 8px;
  padding: 15px;
  margin-bottom: 20px;
  text-align: left;
}

.success-modal .sm-row {
  display: flex;
  justify-content: space-between;
  padding: 6px 0;
  font-size: 13px;
  border-bottom: 1px solid #2a2a2a;
}
.success-modal .sm-row:last-child { border-bottom: none; }
.success-modal .sm-label { color: #999; }
.success-modal .sm-value { color: #f0f0f0; font-weight: 600; }
.success-modal .sm-value.green { color: #4dff88; font-weight: 800; font-size: 16px; }

.success-modal .sm-btn {
  width: 100%;
  padding: 12px;
  border: none;
  border-radius: 8px;
  background: linear-gradient(135deg, #00c853, #009624);
  color: white;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.15s;
}
.success-modal .sm-btn:hover {
  filter: brightness(1.1);
  transform: translateY(-1px);
}
.confirm-btn.void {
  background: linear-gradient(180deg, #cc0000, #990000);
}
/* ══ PRINT RECEIPT ═══════════════════════════════════════════════════ */
@media print {
  body * { visibility: hidden !important; }
  #receipt, #receipt * { visibility: visible !important; }
  #receipt {
    position: fixed !important;
    left: 150% !important;
    top: 0 !important;
    transform: translateX(-50%) !important;
    width: <?= htmlspecialchars($systemSettings['receipt_width'] ?? '58') ?>mm !important;
    font-family: 'Courier New', monospace;
    font-size: 11px;
    padding: 8px;
    line-height: 1.4;
    z-index: 99999 !important;
    margin: 0 !important;
    background: white !important;
    color: #000 !important;
  }
  #receipt .center { text-align: center; }
  #receipt hr { border: none; border-top: 1px dashed #000; margin: 4px 0; }
  #receipt h2 { font-size: 14px; margin-bottom: 2px; }
  #receipt div { font-size: 11px; }
  
  /* Hide all modals and overlays during print */
  .modal-overlay,
  .success-modal-overlay,
  .logout-modal-overlay,
  .confirm-overlay {
    display: none !important;
  }
}
/* Prevent Enter from submitting forms in modals */
.pay-modal input[type="number"],
.pay-modal input[type="text"],
.pay-modal select {
  -webkit-appearance: none;
  appearance: none;
}
.success-modal .sm-btn:focus {
  outline: 3px solid rgba(0,200,83,0.5);
  outline-offset: 2px;
}
</style>
</head>
<body>
<!-- CONFIRMATION MODAL FOR NAVIGATION -->
<div class="confirm-overlay" id="navConfirmModal">
  <div class="confirm-box">
    <h3>⚠️ Cart Contains Items</h3>
    <p>Your cart has <strong id="navCartCount">0</strong> item(s). Navigating away will <strong style="color:#ff4444;">VOID</strong> these items.<br><br>
    What would you like to do?</p>
    <div class="confirm-btns">
      <button class="confirm-btn stay" onclick="cancelNavigation()">↩ Stay & Complete Order</button>
      <button class="confirm-btn void" onclick="confirmNavigation()">✕ Void & Continue</button>
    </div>
  </div>
</div>
<!-- TOP BAR -->
<div class="top-bar">
  <button class="menu-btn" id="menuBtn" onclick="toggleSidebar()">☰</button>
  <div class="logo-block">
    <span class="brand"><?= htmlspecialchars($businessName) ?></span>
    <span class="sub"><?= htmlspecialchars($businessSubtitle) ?></span>
  </div>
  <span class="top-clock" id="currentTime"></span>
  <div style="font-size:10px; margin-left:45px;"><?= htmlspecialchars($businessAddress) ?></div>
  <div class="top-spacer"></div>
  <div class="top-icon-group">
    <div class="top-icon" title="Records"    onclick="location.href='manage/transaction.php'">📋</div>
    <div class="top-icon" title="Profile"    onclick="location.href='profile/prof.php'">👤</div>
    <div class="top-icon" title="Bread Left" onclick="location.href='manage/remain.php'">🧺</div>
    <div class="top-icon" title="Login"      onclick="openDashLogin()">🔑</div>
    <div class="top-icon" title="Logout" onclick="openLogoutModal()">🚪</div>
  </div>
</div>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
  <a href="manage/transaction.php">📋 &nbsp;Manage Records</a>
  <a href="profile/prof.php">👤 &nbsp;Manage Profile</a>
  <a href="manage/remain.php">🧺 &nbsp;Record Bread Left</a>
 <a href="#" onclick="event.preventDefault(); openLogoutModal();">🚪 &nbsp;Logout</a>
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
    <div class="cat-tabs" id="catTabs">
      <button class="cat-tab active" onclick="filterByCategory('ALL',this)">All</button>
      <?php foreach ($categories as $cat): ?>
        <button class="cat-tab"
                onclick="filterByCategory('<?= htmlspecialchars($cat, ENT_QUOTES) ?>',this)">
          <?= htmlspecialchars($cat) ?>
        </button>
      <?php endforeach; ?>
    </div>
    <div class="sub-bar">
      <input type="text" class="search-input" id="searchInput"
             placeholder="🔍  Search by name or code…"
             oninput="filterProducts()" autofocus>
    </div>
    <div class="menu-grid" id="menuGrid"></div>

    <!-- Custom product strip -->
    <div class="custom-strip">
      <div class="custom-strip-title">➕ Add Custom Product</div>
      <div class="custom-row">
        <div style="display:flex;flex-direction:column;gap:2px;flex:2;min-width:100px;">
          <label style="font-size:9px;color:#fff;font-weight:600;text-transform:uppercase;letter-spacing:.8px;">Custom Name</label>
          <input type="text" class="custom-input ci-name" id="ciName"
                 placeholder="Enter product name…" oninput="onCustomNameInput()">
        </div>
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
        <div style="display:flex;flex-direction:column;gap:2px;flex:1;min-width:70px;">
          <label style="font-size:9px;color:#fff;font-weight:600;text-transform:uppercase;letter-spacing:.8px;">Price (<?= $currencySymbol ?>)</label>
          <input type="number" class="custom-input ci-price" id="ciPrice" placeholder="0.00" min="0" step="0.01">
        </div>
        <div style="display:flex;flex-direction:column;gap:2px;width:60px;">
          <label style="font-size:9px;color:#fff;font-weight:600;text-transform:uppercase;letter-spacing:.8px;">Qty</label>
          <input type="number" class="custom-input ci-qty" id="ciQty" placeholder="Qty" min="1" step="1" value="1">
        </div>
        <div style="display:flex;flex-direction:column;gap:2px;width:90px;">
          <label style="font-size:9px;color:#fff;font-weight:600;text-transform:uppercase;letter-spacing:.8px;">Unit</label>
          <select class="custom-input ci-unit" id="ciUnit">
            <option value="pcs">Pieces</option>
            <option value="kg">Kilograms</option>
          </select>
        </div>
        <div style="display:flex;flex-direction:column;gap:2px;justify-content:flex-end;">
          <label style="font-size:9px;color:transparent;letter-spacing:.8px;">_</label>
          <button class="btn-custom-add" onclick="addCustomProduct()">Add 🛒</button>
        </div>
      </div>
    </div>

    <div class="customer-bar">
      <span>Cashier: <span class="cashier-name" id="activeCashier"><?= $cashierName ?></span></span>
      <span><?= date('F j, Y') ?></span>
    </div>
  </div>
</div>

<!-- STATUS BAR -->
<div class="status-bar">
  <span>St4nger POS v1.0</span>
  <span>Terminal 1001</span>
  <span><?= date('F j, Y') ?></span>
  <span class="status-offline" id="connStatus">● OFFLINE</span>
  <span>Help <span class="hint-key">F1</span></span>
  <span>Pay: <span class="hint-key">Enter</span></span>
  <span>E-Wallet: <span class="hint-key">F3</span></span>
  <span>Cancel: <span class="hint-key">F2</span></span>
  <span>Custom: <span class="hint-key">F8</span></span>
  <span>Search Input: <span class="hint-key">F5</span></span>
  <span>Close: <span class="hint-key">ESC</span></span>
</div>

<!-- ══ HIDDEN RECEIPT (print only) ═══════════════════════════════════ -->
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
  <div>Total: <?= $currencySymbol ?><span id="r-total"></span></div>
  <div id="r-payment-line"></div>
  <div id="r-change-line" style="display:none;">Change: <?= $currencySymbol ?><span id="r-change"></span></div>
  <hr>
  <div class="center"><?= htmlspecialchars($receiptFooter) ?></div>
</div>

<!-- ══ CASH PAYMENT MODAL ════════════════════════════════════════════ -->
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
  <input type="number" class="pm-input" id="pmCash" placeholder="Enter amount" oninput="pmCalcChange()">
</div>
  <div class="pm-change" id="pmChange">Change: <?= $currencySymbol ?>0.00</div>
  <div class="pm-btns">
    <button class="pm-btn cancel"  onclick="closePayModal()">Cancel</button>
    <button class="pm-btn proceed" onclick="processPayment()">✓ Confirm Pay</button>
  </div>
</div>
  </div>
</div>

<!-- ══ E-WALLET PAYMENT MODAL ════════════════════════════════════════ -->
<div class="modal-overlay" id="walletModal">
  <div class="pay-modal">
    <div class="pay-modal-title">
      <span id="walletModalTitle">E-WALLET PAYMENT</span>
      <button class="modal-x" onclick="closeWalletModal()">✕</button>
    </div>
    <div class="pay-modal-body">
      <div class="pm-summary" id="wmSummary"></div>
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
                  style="padding:7px 12px;background:linear-gradient(180deg,#4a90e2,#357abd);
                         color:white;border:none;border-radius:4px;font-size:11px;
                         font-weight:600;cursor:pointer;white-space:nowrap;display:none;">
            📷 Show QR
          </button>
        </div>
      </div>

      <!-- QR Card -->
      <div id="qrContainer" style="display:none;margin:4px 0 12px;">
        <div id="qrCard" style="border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.2);max-width:260px;margin:0 auto;">
          <div id="qrCardHeader" style="padding:14px 16px 12px;text-align:center;background:#0070e0;">
            <div id="qrLogoText" style="font-size:20px;font-weight:900;color:white;letter-spacing:1px;margin-bottom:6px;"></div>
            <div id="qrAccountName" style="font-size:15px;font-weight:800;color:white;letter-spacing:1.5px;margin-bottom:2px;"></div>
            <div id="qrAccountNumber" style="font-size:13px;font-weight:600;color:rgba(255,255,255,0.88);letter-spacing:1px;"></div>
          </div>
          <div style="background:white;padding:14px;text-align:center;">
            <div style="width:200px;height:200px;margin:0 auto;border-radius:10px;border:3px solid #eee;display:flex;align-items:center;justify-content:center;overflow:hidden;background:white;">
              <img id="qrImage" src="" alt="QR Code" style="width:100%;height:100%;object-fit:contain;display:none;">
              <div id="qrPlaceholder" style="text-align:center;padding:10px;">
                <div style="font-size:46px;">📱</div>
                <div style="font-size:10px;color:#aaa;margin-top:6px;line-height:1.5;">No QR image configured.<br>Upload one in Settings → Payment Methods.</div>
              </div>
            </div>
            <div id="qrAmountLabel" style="margin-top:10px;font-size:20px;font-weight:900;color:#006600;"></div>
            <div style="margin-top:4px;font-size:11px;color:#888;">Ask customer to scan with their <strong id="qrScanLabel">e-wallet</strong> app</div>
            <button type="button" onclick="toggleQRCode()" style="margin-top:10px;padding:5px 22px;background:#888;color:white;border:none;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;">✕ Hide QR</button>
          </div>
        </div>
      </div>

      <div class="pm-row">
        <span class="pm-label">Ref. No.</span>
        <input type="text" class="pm-input" id="wmRefNo"
               placeholder="Enter transaction reference" maxlength="50">
      </div>
      <div class="pm-row">
        <span class="pm-label">Amount <?= $currencySymbol ?></span>
        <input type="number" class="pm-input" id="wmAmount" placeholder="0.00" step="0.01" readonly>
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

<!-- ══ ERROR MODAL ════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="errModal">
  <div class="err-modal">
    <div class="pay-modal-title">
      <span>NOTICE</span>
      <button class="modal-x" onclick="closeErr()">✕</button>
    </div>
    <div class="err-bar"></div>
    <div class="err-body">
      <div class="err-icon">✕</div>
      <span class="err-msg" id="errMsg">An error occurred.</span>
    </div>
    <div class="err-foot"><button class="ok-btn" onclick="closeErr()">OK</button></div>
  </div>
</div>

<!-- ══ DASHBOARD LOGIN MODAL ══════════════════════════════════════════ -->
<div class="modal-overlay" id="dashModal">
  <div class="dash-modal">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
      <h2>🔑 Login</h2>
      <button onclick="closeDashLogin()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#888;">✖</button>
    </div>
    <iframe id="dashIframe" src="" style="width:100%;height:600px;border:none;border-radius:6px;"></iframe>
  </div>
</div>

<!-- ══ HELP MODAL ════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="helpModal">
  <div style="background:white;border-radius:10px;width:580px;max-width:95vw;max-height:85vh;overflow:hidden;box-shadow:0 16px 50px rgba(0,0,0,0.6);display:flex;flex-direction:column;">
    <div style="background:linear-gradient(180deg,#ff9900,#ff6600);padding:12px 16px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
      <span style="font-weight:800;font-size:15px;color:white;letter-spacing:1px;">📖 POS KEYBOARD SHORTCUTS & GUIDE</span>
      <button onclick="closeHelp()" style="background:#aa2200;color:white;border:none;border-radius:3px;width:24px;height:24px;font-size:14px;cursor:pointer;font-weight:700;">✕</button>
    </div>
    <div style="padding:18px;overflow-y:auto;color:#222;font-size:13px;">
      <div style="font-weight:800;font-size:12px;color:#ff6600;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;border-bottom:2px solid #ff6600;padding-bottom:4px;">⌨️ Keyboard Shortcuts</div>
      <table style="width:100%;border-collapse:collapse;margin-bottom:18px;">
        <thead><tr style="background:#f5f5f5;">
          <th style="padding:7px 10px;text-align:left;font-size:11px;color:#555;border-bottom:1px solid #ddd;">Key</th>
          <th style="padding:7px 10px;text-align:left;font-size:11px;color:#555;border-bottom:1px solid #ddd;">Action</th>
          <th style="padding:7px 10px;text-align:left;font-size:11px;color:#555;border-bottom:1px solid #ddd;">Description</th>
        </tr></thead>
        <tbody>
           <tr style="border-bottom:1px solid #f0f0f0;"><td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">Esc</span></td><td style="padding:7px 10px;font-weight:600;">Close</td><td style="padding:7px 10px;color:#666;">Close modal</td></tr>
          <tr style="border-bottom:1px solid #f0f0f0;"><td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">Enter</span></td><td style="padding:7px 10px;font-weight:600;">Cash Payment</td><td style="padding:7px 10px;color:#666;">Opens the cash payment modal</td></tr>
          <tr style="border-bottom:1px solid #f0f0f0;background:#fafafa;"><td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">F2</span></td><td style="padding:7px 10px;font-weight:600;">Clear / Cancel</td><td style="padding:7px 10px;color:#666;">Clears all items from the current order</td></tr>
          <tr style="border-bottom:1px solid #f0f0f0;"><td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">F3</span></td><td style="padding:7px 10px;font-weight:600;">E-Wallet Payment</td><td style="padding:7px 10px;color:#666;">Opens GCash / Maya / GrabPay payment modal</td></tr>
          <tr style="border-bottom:1px solid #f0f0f0;background:#fafafa;"><td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">F5</span></td><td style="padding:7px 10px;font-weight:600;">Focus Search</td><td style="padding:7px 10px;color:#666;">Jumps focus to the product search bar</td></tr>
          <tr style="border-bottom:1px solid #f0f0f0;"><td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">F8</span></td><td style="padding:7px 10px;font-weight:600;">Custom Product</td><td style="padding:7px 10px;color:#666;">Focuses the custom product name input</td></tr>
          <tr style="background:#fafafa;"><td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">F1</span></td><td style="padding:7px 10px;font-weight:600;">Help</td><td style="padding:7px 10px;color:#666;">Opens this help guide</td></tr>
        </tbody>
      </table>
      <div style="font-weight:800;font-size:12px;color:#ff6600;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;border-bottom:2px solid #ff6600;padding-bottom:4px;">🛒 How to Process a Sale</div>
      <ol style="padding-left:18px;line-height:2;color:#444;margin-bottom:18px;">
        <li>Click a product from the menu grid to add it to the order panel.</li>
        <li>Adjust quantity directly in the order panel by editing the qty field.</li>
        <li>Enter the cash amount or press <strong>Enter</strong> to open payment.</li>
        <li>Confirm the payment — a receipt will print automatically.</li>
      </ol>
      <div style="font-weight:800;font-size:12px;color:#ff6600;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;border-bottom:2px solid #ff6600;padding-bottom:4px;">📱 E-Wallet Payment</div>
      <ul style="padding-left:18px;line-height:2;color:#444;margin-bottom:8px;">
        <li>Press <strong>F3</strong> or click <strong>E-Wallet</strong> to open the modal.</li>
        <li>Select the provider (GCash, Maya, GrabPay, etc.).</li>
        <li>Click <strong>Show QR</strong> and let the customer scan.</li>
        <li>Enter the <strong>Reference No.</strong> from the customer's receipt.</li>
        <li>Click <strong>Confirm</strong> to finalize.</li>
      </ul>
    </div>
    <div style="background:#f5f5f5;border-top:1px solid #ddd;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;font-size:11px;color:#888;">
      <span><?= htmlspecialchars($businessName) ?> POS v1.0</span>
      <button onclick="closeHelp()" style="background:linear-gradient(180deg,#ff9900,#cc6600);color:white;border:none;border-radius:4px;padding:6px 20px;font-size:12px;font-weight:700;cursor:pointer;">Close</button>
    </div>
  </div>
</div>
<!-- LOGOUT CONFIRMATION MODAL -->
<div class="logout-modal-overlay" id="logoutModal">
  <div class="logout-modal" id="logoutModalBox">
    <div class="lm-icon">🚪</div>
    <h3>Confirm Logout</h3>
    <p>Are you sure you want to logout from <strong><?= htmlspecialchars($businessName) ?></strong>?<br>
    You will need to login again to access the POS system.</p>
    <div class="lm-btns">
      <button class="lm-btn cancel" onclick="closeLogoutModal()">✕ Cancel</button>
      <button class="lm-btn confirm" onclick="confirmLogout()">🚪 Logout</button>
    </div>
  </div>
</div>
<!-- SUCCESS MODAL -->
<div class="success-modal-overlay" id="successModal">
  <div class="success-modal" id="successModalBox">
    <div class="sm-icon">✓</div>
    <h3 id="smTitle">Payment Successful!</h3>
    <p class="sm-subtitle" id="smSubtitle">Transaction completed successfully</p>
    <div class="sm-details">
      <div class="sm-row">
        <span class="sm-label">Transaction #</span>
        <span class="sm-value" id="smTxnId">—</span>
      </div>
      <div class="sm-row">
        <span class="sm-label">Payment Method</span>
        <span class="sm-value" id="smMethod">—</span>
      </div>
      <div class="sm-row">
        <span class="sm-label">Cashier</span>
        <span class="sm-value" id="smCashier">—</span>
      </div>
      <div class="sm-row" id="smRefRow" style="display:none;">
        <span class="sm-label">Reference No.</span>
        <span class="sm-value" id="smRef">—</span>
      </div>
      <div class="sm-row">
        <span class="sm-label">Total</span>
        <span class="sm-value green" id="smTotal">—</span>
      </div>
      <div class="sm-row" id="smPaidRow">
        <span class="sm-label">Paid</span>
        <span class="sm-value" id="smPaid">—</span>
      </div>
      <div class="sm-row" id="smChangeRow">
        <span class="sm-label">Change</span>
        <span class="sm-value" id="smChange">—</span>
      </div>
    </div>
    <button class="sm-btn" onclick="closeSuccessModal()">
  ✓ Done <span style="font-size:10px;opacity:0.7;margin-left:6px;">(Enter)</span>
</button>
  </div>
</div>
<!-- ══════════════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════════════════ -->
<script>
/* ── PHP constants passed to JS ───────────────────────────────────── */
const currencySymbol   = '<?= addslashes($currencySymbol) ?>';
const autoPrintReceipt = <?= $autoPrintReceipt ? 'true' : 'false' ?>;

/* ── Product data ─────────────────────────────────────────────────── */
const products = <?= json_encode($products, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

/* ── Cache ────────────────────────────────────────────────────────── */
const Cache = (() => {
  const _store = {
    products: null, categories: null, breads: null, productMap: null,
    transactions: [], lastPing: null, lastPingAt: 0
  };
  return {
    initProducts(arr) {
      _store.products = arr; _store.productMap = {};
      arr.forEach(p => { _store.productMap[p.id] = p; });
    },
    getProducts()              { return _store.products; },
    getProductById(id)         { return _store.productMap?.[id] ?? null; },
    updateProductStock(id, field, value) {
      const p = _store.productMap?.[id]; if (p) p[field] = value;
    },
    initCategories(arr)        { _store.categories = arr; },
    initBreads(arr)            { _store.breads = arr; },
    getBreads()                { return _store.breads; },
    pushTransaction(txn) {
      _store.transactions.unshift(txn);
      if (_store.transactions.length > 20) _store.transactions.pop();
    },
    setConnStatus(online) { _store.lastPing = online; _store.lastPingAt = Date.now(); },
    getConnStatus()  { return _store.lastPing; },
    getConnAge()     { return Date.now() - _store.lastPingAt; }
  };
})();

Cache.initProducts(products);
Cache.initCategories((() => {
  const cats = [];
  products.forEach(p => { const c = (p.category||'General').trim(); if(c && !cats.includes(c)) cats.push(c); });
  return cats;
})());

/* ── Clock ────────────────────────────────────────────────────────── */
function updateClock() {
  document.getElementById('currentTime').textContent =
    new Date().toLocaleString('en-US',{timeZone:'Asia/Manila',weekday:'short',year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});
}
setInterval(updateClock,1000); updateClock();

/* ── Sidebar ──────────────────────────────────────────────────────── */
function toggleSidebar() {
  const sb = document.getElementById('sidebar');
  sb.style.display = sb.style.display==='flex' ? 'none' : 'flex';
  document.getElementById('menuBtn').textContent = sb.style.display==='flex' ? '✖' : '☰';
}

/* ── Modals ───────────────────────────────────────────────────────── */
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

function showErr(msg) {
  // Close any open success modal
  if (document.getElementById('successModal').classList.contains('show')) {
    document.getElementById('successModal').classList.remove('show');
  }
  
  document.getElementById('errMsg').textContent = msg;
  document.getElementById('errModal').classList.add('show');
}

function closeErr()   { document.getElementById('errModal').classList.remove('show'); }
function openHelp()   { document.getElementById('helpModal').classList.add('show'); }
function closeHelp()  { document.getElementById('helpModal').classList.remove('show'); }
document.getElementById('helpModal').addEventListener('click',function(e){ if(e.target===this) closeHelp(); });

/* ── State ────────────────────────────────────────────────────────── */
let checkout = [], currentCategory = 'ALL';

/* ── Product grid ─────────────────────────────────────────────────── */
function renderGrid(list) {
  const grid  = document.getElementById('menuGrid');
  const items = list !== undefined ? list : getFiltered();
  grid.innerHTML = '';
  if (!items.length) { grid.innerHTML='<div class="no-items">No products found.</div>'; return; }
  items.forEach(p => {
    const out = (p.measurement_type==='kg'&&parseFloat(p.kg)<=0)||(p.measurement_type!=='kg'&&parseInt(p.pieces)<=0);
    const low = p.is_low_stock && !out;
    const qty = p.measurement_type==='kg' ? parseFloat(p.kg).toFixed(2)+' kg' : parseInt(p.pieces)+' pcs';
    const el  = document.createElement('div');
    el.className = 'menu-item'+(out?' out':'')+(low?' low-s':'');
    el.innerHTML = `
      ${low?'<span class="low-badge">Low</span>':''}
      <div class="menu-item-img" style="background-image:url('${p.image_path}')" onerror="this.style.backgroundImage='url(image/cake.jfif)'"></div>
      <div class="menu-item-info">
        <div class="menu-item-name">${p.name}</div>
        <div class="menu-item-price">${currencySymbol}${parseFloat(p.price).toFixed(2)}</div>
        <div class="menu-item-qty ${out?'out-label':low?'low':''}">${out?'OUT OF STOCK':qty}</div>
      </div>
      ${out?'<div class="out-overlay"><span>OUT</span></div>':''}`;
    if (!out) el.onclick = () => addToCart(p);
    grid.appendChild(el);
  });
}

function getFiltered() {
  const q = (document.getElementById('searchInput').value||'').trim().toLowerCase();
  return Cache.getProducts().filter(p => {
    const cm = currentCategory==='ALL' || (p.category||'').trim()===currentCategory;
    const nm = !q || p.name.toLowerCase().includes(q) || p.code.toLowerCase().includes(q) || (p.brand&&p.brand.toLowerCase().includes(q));
    return cm && nm;
  });
}

function filterByCategory(cat,btn) {
  currentCategory = cat;
  document.querySelectorAll('.cat-tab').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('searchInput').value = '';
  renderGrid();
}
function filterProducts() { renderGrid(); }

/* ── Cart ─────────────────────────────────────────────────────────── */
function addToCart(p) {
  const ex = checkout.find(i => i.id===p.id);
  if (ex) { ex.qty++; }
  else checkout.push({ id:p.id, name:p.name, price:parseFloat(p.price), qty:1, measurement_type:p.measurement_type, unit:p.measurement_type==='kg'?'kg':'pcs' });
  const src = Cache.getProductById(p.id);
  if (src) {
    if (src.measurement_type==='kg') Cache.updateProductStock(p.id,'kg',parseFloat((src.kg-1).toFixed(2)));
    else Cache.updateProductStock(p.id,'pieces',src.pieces-1);
  }
  renderOrderPanel(); renderGrid();
}

function renderOrderPanel() {
  const ol = document.getElementById('orderList');
  if (!checkout.length) {
    ol.innerHTML = '<div class="order-empty">No items added yet. 🛒</div>';
    document.getElementById('itemCount').textContent = '0';
    document.getElementById('totalAmount').textContent = '0.00';
    document.getElementById('changeRow').textContent = '—';
    document.getElementById('changeRow').className = 'change-row';
    return;
  }
  ol.innerHTML = '';
  checkout.forEach((item,idx) => {
    const row  = document.createElement('div');
    row.className = 'order-item';
    const isKg = item.measurement_type==='kg';
    row.innerHTML = `
      <input type="number" class="qty-input" value="${item.qty}" min="${isKg?'0.01':'1'}" step="${isKg?'0.01':'1'}"
             style="width:48px;padding:2px 4px;background:#fff9;border:1.5px solid #aaa;border-radius:4px;font-size:12px;font-weight:700;color:#111;text-align:center;"
             onclick="event.stopPropagation()" onchange="updateItemQty(${idx},this.value)" onkeydown="if(event.key==='Enter')this.blur();">
      <span class="name" title="${item.name}">${item.name}</span>
      <span style="font-size:10px;color:#888;flex-shrink:0;">${isKg?'kg':'pcs'}</span>
      <span class="price">${currencySymbol}${(item.price*item.qty).toFixed(2)}</span>
      <span class="del" onclick="removeItem(event,${idx})">✕</span>`;
    row.onclick = () => { document.querySelectorAll('.order-item').forEach(r=>r.classList.remove('selected')); row.classList.add('selected'); };
    ol.appendChild(row);
  });
  const total = checkout.reduce((s,i)=>s+i.price*i.qty,0);
  document.getElementById('itemCount').textContent = checkout.length;
  document.getElementById('totalAmount').textContent = total.toFixed(2);
  autoCalcChange();
}

function removeItem(e,idx) {
  e.stopPropagation();
  const item = checkout[idx], src = Cache.getProductById(item.id);
  if (src) {
    if (src.measurement_type==='kg') Cache.updateProductStock(item.id,'kg',parseFloat((src.kg+item.qty).toFixed(2)));
    else Cache.updateProductStock(item.id,'pieces',src.pieces+item.qty);
  }
  checkout.splice(idx,1); renderOrderPanel(); renderGrid();
}

function clearCheckout() {
  checkout.forEach(item => {
    const src = Cache.getProductById(item.id);
    if (src) {
      if (src.measurement_type==='kg') Cache.updateProductStock(item.id,'kg',parseFloat((src.kg+item.qty).toFixed(2)));
      else Cache.updateProductStock(item.id,'pieces',src.pieces+item.qty);
    }
  });
  checkout = [];
  document.getElementById('amountPaid').value = '';
  renderOrderPanel(); renderGrid();
}

function autoCalcChange() {
  const paid  = parseFloat(document.getElementById('amountPaid').value)||0;
  const total = checkout.reduce((s,i)=>s+i.price*i.qty,0);
  const row   = document.getElementById('changeRow');
  if (!paid) { row.textContent='—'; row.className='change-row'; return; }
  if (paid>=total) { row.textContent='Change: '+currencySymbol+(paid-total).toFixed(2); row.className='change-row'; }
  else             { row.textContent='Short: '+currencySymbol+(total-paid).toFixed(2);  row.className='change-row short'; }
}

function updateItemQty(idx,newVal) {
  const item   = checkout[idx];
  const isKg   = item.measurement_type==='kg';
  const parsed = isKg ? parseFloat(newVal) : parseInt(newVal);
  if (isNaN(parsed)||parsed<=0) { showErr('Quantity must be greater than zero.'); renderOrderPanel(); return; }
  const src = Cache.getProductById(item.id);
  if (src && !item.custom) {
    const diff = parsed-item.qty;
    if (isKg) {
      if (diff>0&&diff>src.kg) { showErr(`Only ${(src.kg+item.qty).toFixed(2)} kg available for "${item.name}".`); renderOrderPanel(); return; }
      Cache.updateProductStock(item.id,'kg',parseFloat((src.kg-diff).toFixed(2)));
    } else {
      if (diff>0&&diff>src.pieces) { showErr(`Only ${src.pieces+item.qty} pcs available for "${item.name}".`); renderOrderPanel(); return; }
      Cache.updateProductStock(item.id,'pieces',src.pieces-diff);
    }
  }
  item.qty = isKg ? parseFloat(parsed.toFixed(2)) : parsed;
  renderOrderPanel(); renderGrid();
}
// ── Handle Enter key in payment modals ───────────────────────────
document.addEventListener('keydown', function(e) {
  // Check if payment modal is open
  if (e.key === 'Enter' && document.getElementById('payModal').classList.contains('show')) {
    e.preventDefault();
    e.stopPropagation();
    
    const activeElement = document.activeElement;
    
    // If focus is on the cash input OR confirm button, process payment
    if (activeElement && (activeElement.id === 'pmCash' || 
        (activeElement.classList && activeElement.classList.contains('pm-btn') && 
         activeElement.classList.contains('proceed')))) {
      processPayment();
      return;
    }
  }
  
  // Handle Enter key in wallet payment modal
  if (e.key === 'Enter' && document.getElementById('walletModal').classList.contains('show')) {
    const activeElement = document.activeElement;
    
    if (activeElement && activeElement.id === 'wmRefNo') {
      e.preventDefault();
      activeElement.blur();
      return;
    }
    
    if (activeElement && activeElement.classList && 
        activeElement.classList.contains('pm-btn') && 
        activeElement.classList.contains('proceed')) {
      e.preventDefault();
      processWalletPayment();
    }
  }
});

/* ── Cash payment modal ───────────────────────────────────────────── */
function openPayModal(method) {
  if (!checkout.length) return showErr('Please add items to the order first.🛒');
  const total = checkout.reduce((s,i)=>s+i.price*i.qty,0);
  document.getElementById('payModalTitle').textContent = (method||'CASH')+' PAYMENT';
  document.getElementById('pmSummary').innerHTML =
    checkout.map(i=>`<div class="pm-summary-row"><span>${i.qty}× ${i.name}</span><span>${currencySymbol}${(i.price*i.qty).toFixed(2)}</span></div>`).join('')+
    `<div class="pm-summary-row total"><span>TOTAL</span><span>${currencySymbol}${total.toFixed(2)}</span></div>`;
  document.getElementById('pmCash').value = '';
  document.getElementById('pmChange').textContent = 'Change: '+currencySymbol+'0.00';
  document.getElementById('pmChange').className = 'pm-change';
  document.getElementById('payModal').classList.add('show');
  
  // Focus and select the cash input
  setTimeout(()=>{
    const cashInput = document.getElementById('pmCash');
    cashInput.focus();
    cashInput.select();
  }, 100);
}
function closePayModal() { document.getElementById('payModal').classList.remove('show'); }

function pmCalcChange() {
  const paid  = parseFloat(document.getElementById('pmCash').value)||0;
  const total = checkout.reduce((s,i)=>s+i.price*i.qty,0);
  const el    = document.getElementById('pmChange');
  if (paid>=total) { el.textContent='Change: '+currencySymbol+(paid-total).toFixed(2); el.className='pm-change'; }
  else             { el.textContent='Short by: '+currencySymbol+(total-paid).toFixed(2); el.className='pm-change short'; }
}

/* ── E-wallet modal ───────────────────────────────────────────────── */
function openWalletModal() {
  if (!checkout.length) return showErr('Please add items to the order first.');
  const total = checkout.reduce((s,i)=>s+i.price*i.qty,0);
  document.getElementById('wmSummary').innerHTML =
    checkout.map(i=>`<div class="pm-summary-row"><span>${i.qty}× ${i.name}</span><span>${currencySymbol}${(i.price*i.qty).toFixed(2)}</span></div>`).join('')+
    `<div class="pm-summary-row total"><span>TOTAL</span><span>${currencySymbol}${total.toFixed(2)}</span></div>`;
  document.getElementById('wmProvider').value = '';
  document.getElementById('wmRefNo').value    = '';
  document.getElementById('wmAmount').value   = total.toFixed(2);
  document.getElementById('wmStatus').textContent = 'Awaiting confirmation';
  document.getElementById('wmStatus').className   = 'pm-change';
  document.getElementById('qrContainer').style.display = 'none';
  document.getElementById('btnShowQR').style.display   = 'none';
  document.getElementById('btnShowQR').innerHTML       = '📷 Show QR';
  document.getElementById('walletModal').classList.add('show');
  setTimeout(()=>document.getElementById('wmProvider').focus(),100);
}
function closeWalletModal() {
  document.getElementById('walletModal').classList.remove('show');
  document.getElementById('qrContainer').style.display = 'none';
  document.getElementById('btnShowQR').innerHTML       = '📷 Show QR';
  document.getElementById('btnShowQR').style.display   = 'none';
}
document.getElementById('walletModal').addEventListener('click',function(e){ if(e.target===this) closeWalletModal(); });

const providerColors = {
  GCash:   '#0070e0',
  Maya:    '#008f4c',
  GrabPay: '#00b14f',
  Other:   '#888888'
};

function updateWalletHints() {
  const provider = document.getElementById('wmProvider').value;
  const qrBtn    = document.getElementById('btnShowQR');
  const qrBox    = document.getElementById('qrContainer');
  const status   = document.getElementById('wmStatus');
  const hints    = { GCash:'Check GCash app for transaction status', Maya:'Verify in Maya app before confirming', GrabPay:'Ensure GrabPay shows "Success"', Other:'Keep proof of payment for verification' };
  status.textContent = hints[provider] || 'Awaiting confirmation';
  status.className   = 'pm-change';
  if (provider) {
    const color = providerColors[provider] || '#555555';
    qrBtn.style.display    = 'inline-flex';
    qrBtn.style.alignItems = 'center';
    qrBtn.style.gap        = '4px';
    qrBtn.style.background = `linear-gradient(180deg,${color}cc,${color})`;
    if (qrBox.style.display!=='none') buildQRCard();
  } else {
    qrBtn.style.display = 'none';
    qrBox.style.display = 'none';
  }
}

function buildQRCard() {
  const sel           = document.getElementById('wmProvider');
  const opt           = sel.options[sel.selectedIndex];
  const provider      = sel.value;
  const qrPath        = opt.dataset.qr            || '';
  const accountName   = opt.dataset.accountName   || '';
  const accountNumber = opt.dataset.accountNumber || '';
  const displayName   = opt.dataset.name          || provider;
  const total         = document.getElementById('wmAmount').value;
  const color         = providerColors[provider]   || '#555555';
  document.getElementById('qrCardHeader').style.background = color;
  document.getElementById('qrLogoText').textContent        = displayName;
  document.getElementById('qrAccountName').textContent     = accountName;
  document.getElementById('qrAccountNumber').textContent   = accountNumber;
  document.getElementById('qrScanLabel').textContent       = displayName;
  document.getElementById('qrAmountLabel').textContent     = total ? currencySymbol+parseFloat(total).toFixed(2) : '';
  const imgEl = document.getElementById('qrImage');
  const phEl  = document.getElementById('qrPlaceholder');
  if (qrPath) {
    imgEl.src = qrPath; imgEl.style.display='block'; phEl.style.display='none';
    imgEl.onerror = function(){ this.style.display='none'; phEl.style.display='block'; };
  } else { imgEl.style.display='none'; phEl.style.display='block'; }
}

function toggleQRCode() {
  const qrBox = document.getElementById('qrContainer');
  const qrBtn = document.getElementById('btnShowQR');
  if (qrBox.style.display!=='none') { qrBox.style.display='none'; qrBtn.innerHTML='📷 Show QR'; return; }
  if (!document.getElementById('wmProvider').value) return;
  buildQRCard();
  qrBox.style.display = 'block';
  qrBtn.innerHTML     = '✕ Hide QR';
}

/* ══════════════════════════════════════════════════════════════════
   RECEIPT HELPER  — called by both processPayment & processWalletPayment
══════════════════════════════════════════════════════════════════ */
function _fillReceiptCommon(date, time, cashier, itemsData, total) {
  document.getElementById('r-date').textContent    = date;
  document.getElementById('r-time').textContent    = time;
  document.getElementById('r-cashier').textContent = cashier;
  document.getElementById('r-total').textContent   = total.toFixed(2);
  document.getElementById('r-items').innerHTML     = itemsData
    .map(i => `<div>${i.qty}${i.unit} × ${i.name} = ${currencySymbol}${i.total}</div>`)
    .join('');
}
let isPrinting = false;
/* ─── Custom Print Function ─────────────────────────────────────── */
function printReceipt() {
  if (isPrinting) return; // Prevent double printing
  isPrinting = true;
  
  const receiptWidth = '<?= htmlspecialchars($systemSettings['receipt_width'] ?? '58') ?>';
  const receiptElement = document.getElementById('receipt');
  
  const printWindow = window.open('', '_blank', `width=${Math.round(receiptWidth * 3.78)},height=600`);
  
  if (!printWindow) {
    isPrinting = false;
    window.print();
    return;
  }
  
  printWindow.document.write(`
    <!DOCTYPE html>
    <html>
    <head>
      <title>Receipt</title>
      <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
          width: ${receiptWidth}mm;
          font-family: 'Courier New', monospace;
          font-size: 11px;
          line-height: 1.4;
          padding: 8px;
          color: #000;
          background: #fff;
        }
        .center { text-align: center; }
        h2 { font-size: 14px; margin-bottom: 2px; }
        hr { border: none; border-top: 1px dashed #000; margin: 4px 0; }
        .item-row { display: flex; justify-content: space-between; margin-bottom: 2px; }
        .total-row { display: flex; justify-content: space-between; font-weight: bold; margin-top: 4px; }
        .footer { text-align: center; margin-top: 8px; font-size: 10px; }
      </style>
    </head>
    <body>
      ${receiptElement.innerHTML}
    </body>
    </html>
  `);
  
  printWindow.document.close();
  
  printWindow.onload = function() {
    setTimeout(() => {
      printWindow.print();
      setTimeout(() => {
        printWindow.close();
        isPrinting = false; // Reset flag after printing
      }, 500);
    }, 100);
  };
}
let isProcessingPayment = false;
/* ══════════════════════════════════════════════════════════════════
   CASH PAYMENT
══════════════════════════════════════════════════════════════════ */
async function processPayment() {
  if (isProcessingPayment) return; // Prevent double execution
  isProcessingPayment = true;
  const cashier = document.getElementById('activeCashier').textContent.trim();
  if (!cashier || cashier==='Not logged in') return showErr('Please log in before processing payment.');
  if (!checkout.length) return showErr('Cart is empty.');

  const total  = checkout.reduce((s,i)=>s+i.price*i.qty,0);
  const paid   = parseFloat(document.getElementById('pmCash').value)||0;
  if (paid < total) return showErr('Insufficient payment. Please enter the correct amount.');

  const change = paid-total;
  const now    = new Date();
  const date   = now.toISOString().split('T')[0];
  const time   = now.toTimeString().split(' ')[0];

  const itemsData = checkout.map(item=>({
    id:item.id, name:item.name, qty:item.qty, price:item.price,
    measurement_type:item.measurement_type, unit:item.unit,
    total:(item.price*item.qty).toFixed(2)
  }));

  try {
    const res = await fetch('record/transaction.php',{
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({
        items:itemsData, total:total.toFixed(2),
        paid:paid.toFixed(2), change:change.toFixed(2),
        cashier, date, time,
        payment_method:'Cash',
        reference_no:''
      })
    });
    const data = await res.json();
    if (!res.ok||data.status!=='success') throw new Error(data.message||'Transaction failed.');

    // Close payment modal first
    closePayModal();

    /* Fill receipt */
    _fillReceiptCommon(date, time, cashier, itemsData, total);
    document.getElementById('r-payment-line').innerHTML = `Cash: ${currencySymbol}${paid.toFixed(2)}`;
    document.getElementById('r-change-line').style.display = '';
    document.getElementById('r-change').textContent = change.toFixed(2);

    // Print receipt using custom function
    if (autoPrintReceipt) {
      await new Promise(r=>setTimeout(r,100));
      printReceipt();
    }

    // Clear cart and cache AFTER printing
    Cache.pushTransaction({ id:data.transaction_id||Date.now(), date, time, cashier, items:itemsData, total:total.toFixed(2), paid:paid.toFixed(2), change:change.toFixed(2), method:'Cash' });
    clearCheckout();

    // NOW show success modal AFTER printing is done
    await new Promise(r=>setTimeout(r,300)); // Delay for print window
   openSuccessModal({
    title: 'Payment Successful! 💵',
    subtitle: 'Cash payment completed successfully',
    txnId: data.transaction_id || 'N/A',
    method: '💵 Cash',
    cashier: cashier,
    total: total.toFixed(2),
    paid: paid.toFixed(2),
    change: change.toFixed(2)
  });
} catch (err) {
  // Silently handle any modal errors
  console.log('Success modal error:', err);
}
}

/* ══════════════════════════════════════════════════════════════════
   E-WALLET PAYMENT
══════════════════════════════════════════════════════════════════ */
async function processWalletPayment() {
  if (isProcessingPayment) return;
  isProcessingPayment = true;
  const cashier = document.getElementById('activeCashier').textContent.trim();
  if (!cashier || cashier==='Not logged in') return showErr('Please log in before processing payment.');
  if (!checkout.length) return showErr('Cart is empty.');

  const sel          = document.getElementById('wmProvider');
  const provider     = sel.value;
  const selectedOpt  = sel.options[sel.selectedIndex];
  const providerName = selectedOpt.dataset.name || provider;
  const refNo        = document.getElementById('wmRefNo').value.trim();
  const total        = checkout.reduce((s,i)=>s+i.price*i.qty,0);

  if (!provider)       return showErr('Please select an e-wallet provider.');
  if (!refNo)          return showErr('Please enter the transaction reference number.');
  if (refNo.length<5)  return showErr('Reference number seems too short. Please verify.');

  const now  = new Date();
  const date = now.toISOString().split('T')[0];
  const time = now.toTimeString().split(' ')[0];

  const itemsData = checkout.map(item=>({
    id:item.id, name:item.name, qty:item.qty, price:item.price,
    measurement_type:item.measurement_type, unit:item.unit,
    total:(item.price*item.qty).toFixed(2)
  }));

  try {
    const res = await fetch('record/transaction.php',{
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({
        items:itemsData, total:total.toFixed(2),
        paid:total.toFixed(2), change:'0.00',
        cashier, date, time,
        payment_method:'wallet',
        wallet_provider:providerName,
        wallet_ref:refNo
      })
    });
    const data = await res.json();
    if (!res.ok||data.status!=='success') throw new Error(data.message||'Transaction failed.');

    // Close wallet modal first
    closeWalletModal();

    /* Fill receipt */
    _fillReceiptCommon(date, time, cashier, itemsData, total);
    document.getElementById('r-payment-line').innerHTML =
      `${providerName}: ${currencySymbol}${total.toFixed(2)}<br>Ref: ${refNo}`;
    document.getElementById('r-change-line').style.display = 'none';

    // Print receipt using custom function
    if (autoPrintReceipt) {
      await new Promise(r=>setTimeout(r,100));
      printReceipt();
    }

    // Clear cart and cache AFTER printing
    Cache.pushTransaction({ id:data.transaction_id||Date.now(), date, time, cashier, items:itemsData, total:total.toFixed(2), paid:total.toFixed(2), change:'0.00', method:providerName, ref:refNo });
    clearCheckout();

    // NOW show success modal AFTER printing is done
    await new Promise(r=>setTimeout(r,300)); // Delay for print window
     openSuccessModal({
    title: `${providerName} Payment Confirmed! 📱`,
    subtitle: 'E-wallet payment completed successfully',
    txnId: data.transaction_id || 'N/A',
    method: `${providerName} 📱`,
    cashier: cashier,
    ref: refNo,
    total: total.toFixed(2)
  });
} catch (err) {
  console.log('Success modal error:', err);
}
}
/* ── Custom product ───────────────────────────────────────────────── */
function ciPriceFromType() {
  const sel=document.getElementById('ciType'), priceEl=document.getElementById('ciPrice'),
        unitEl=document.getElementById('ciUnit'), qtyEl=document.getElementById('ciQty'),
        nameEl=document.getElementById('ciName'), chosen=sel.value;
  if (chosen) {
    priceEl.value=sel.options[sel.selectedIndex].getAttribute('data-price')||'';
    priceEl.readOnly=true; priceEl.style.background='#2a2a2a'; priceEl.style.color='#888';
    unitEl.value='pcs'; unitEl.disabled=true; unitEl.style.opacity='0.5';
    qtyEl.step='1'; qtyEl.setAttribute('min','1');
    nameEl.disabled=true; nameEl.style.opacity='0.5'; nameEl.value='';
  } else {
    priceEl.readOnly=false; priceEl.style.background=''; priceEl.style.color='';
    unitEl.disabled=false; unitEl.style.opacity='';
    nameEl.disabled=false; nameEl.style.opacity='';
    qtyEl.step='1';
  }
}

function onCustomNameInput() {
  const nameEl=document.getElementById('ciName'), typeEl=document.getElementById('ciType'),
        priceEl=document.getElementById('ciPrice'), unitEl=document.getElementById('ciUnit');
  if (nameEl.value.trim()) {
    typeEl.value=''; typeEl.disabled=true; typeEl.style.opacity='0.5';
    priceEl.readOnly=false; priceEl.style.background=''; priceEl.style.color='';
    unitEl.disabled=false; unitEl.style.opacity='';
  } else { typeEl.disabled=false; typeEl.style.opacity=''; }
}

document.getElementById('ciUnit').addEventListener('change',function(){
  const qtyEl=document.getElementById('ciQty'), typeEl=document.getElementById('ciType');
  if (this.value==='kg') {
    qtyEl.step='0.01'; qtyEl.setAttribute('min','0.01'); qtyEl.oninput=null; qtyEl.value='';
    typeEl.value=''; typeEl.disabled=true; typeEl.style.opacity='0.5';
    const priceEl=document.getElementById('ciPrice');
    priceEl.readOnly=false; priceEl.style.background=''; priceEl.style.color='';
  } else {
    qtyEl.step='1'; qtyEl.setAttribute('min','1'); qtyEl.value='1';
    qtyEl.oninput=function(){ this.value=this.value.replace(/[^0-9]/g,''); };
    const nameEl=document.getElementById('ciName');
    if (!nameEl.value.trim()) { typeEl.disabled=false; typeEl.style.opacity=''; }
  }
});

function addCustomProduct() {
  const nameEl=document.getElementById('ciName'), typeEl=document.getElementById('ciType'),
        priceEl=document.getElementById('ciPrice'), qtyEl=document.getElementById('ciQty'),
        unitEl=document.getElementById('ciUnit');
  const name=nameEl.value.trim(), type=typeEl.value;
  const price=parseFloat(priceEl.value), unit=unitEl.value;
  const qty=(unit==='kg')?parseFloat(qtyEl.value):parseInt(qtyEl.value);
  if (!name&&!type) return showErr('Please enter a custom product name OR select a bread type.');
  if (isNaN(price)||price<=0) return showErr('Please enter a valid price greater than zero.');
  if (isNaN(qty)||qty<=0) return showErr('Please enter a valid quantity greater than zero.');
  const label = name ? (type?`${name} (${type})`:name) : type;
  checkout.push({ id:'custom-'+Date.now(), name:label, price, qty, measurement_type:unit, unit, custom:true });
  nameEl.value=''; nameEl.disabled=false; nameEl.style.opacity='';
  typeEl.value=''; typeEl.disabled=false; typeEl.style.opacity='';
  priceEl.value=''; priceEl.readOnly=false; priceEl.style.background=''; priceEl.style.color='';
  qtyEl.value='1'; qtyEl.step='1'; qtyEl.oninput=function(){ this.value=this.value.replace(/[^0-9]/g,''); };
  unitEl.value='pcs'; unitEl.disabled=false; unitEl.style.opacity='';
  renderOrderPanel();
}

/* ── Connectivity ─────────────────────────────────────────────────── */
function checkConn() {
  const el = document.getElementById('connStatus');
  if (Cache.getConnAge()<10000&&Cache.getConnStatus()!==null) {
    el.textContent=Cache.getConnStatus()?'● ONLINE':'● OFFLINE';
    el.className  =Cache.getConnStatus()?'status-online':'status-offline'; return;
  }
  el.textContent='● CHECKING'; el.className=''; el.style.color='#ffcc66';
  fetch('record/ping.php',{cache:'no-store'})
    .then(async r=>{
      const data=await r.json().catch(()=>({}));
      const online=r.ok&&data.status==='ok';
      Cache.setConnStatus(online);
      el.textContent=online?'● ONLINE':'● OFFLINE';
      el.className  =online?'status-online':'status-offline';
      el.style.color='';
      if (online&&data.time) el.title=`Last ping: ${data.time}`;
    })
    .catch(()=>{ Cache.setConnStatus(false); el.textContent='● OFFLINE'; el.className='status-offline'; el.style.color=''; });
}
setInterval(checkConn,15000); checkConn();

/* ── Keyboard shortcuts ───────────────────────────────────────────── */
document.addEventListener('keydown',function(e){
  const typing = ['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName);

  /* ── ESC: close whichever modal is open (priority order: top-most first) ── */
  if (e.key === 'Escape') {
    e.preventDefault();
    if (document.getElementById('helpModal').classList.contains('show'))   { closeHelp();         return; }
    if (document.getElementById('errModal').classList.contains('show'))    { closeErr();          return; }
    if (document.getElementById('walletModal').classList.contains('show')) { closeWalletModal();  return; }
    if (document.getElementById('payModal').classList.contains('show'))    { closePayModal();     return; }
    if (document.getElementById('dashModal').classList.contains('show'))   { closeDashLogin();    return; }
    return;
  }

  if (e.key==='F1'||e.keyCode===112){ e.preventDefault(); openHelp(); return; }
  if (e.key==='F5'||e.keyCode===116){ e.preventDefault(); const s=document.getElementById('searchInput'); s.focus(); s.select(); return; }
  if (e.key==='F8'||e.keyCode===119){ e.preventDefault(); document.getElementById('ciName').focus(); return; }
  if (typing) return;
  if (e.key==='Enter'||e.keyCode===13){ e.preventDefault(); openPayModal('Cash'); return; }
  if (e.key==='F2'||e.keyCode===113){ e.preventDefault(); clearCheckout(); return; }
  if (e.key==='F3'||e.keyCode===114){ e.preventDefault(); openWalletModal(); return; }
});

document.getElementById('searchInput').addEventListener('keypress',function(e){
  if (e.key==='Enter') {
    const q=this.value.trim().toLowerCase();
    const p=Cache.getProducts().find(x=>x.code.toLowerCase()===q||x.name.toLowerCase()===q);
    if (p){ addToCart(p); this.value=''; renderGrid(); }
  }
});
/* ── CART PROTECTION SYSTEM ──────────────────────────────────────── */
let pendingNavigation = null;
let warningDismissed = false;

function hasCartItems() {
  return checkout.length > 0;
}

function updateCartProtection() {
  const hasItems = hasCartItems();
  const topIcons = document.querySelectorAll('.top-icon');
  const sidebarLinks = document.querySelectorAll('.sidebar a');
  const menuBtn = document.getElementById('menuBtn');
  
  // Toggle disabled state on navigation elements
  topIcons.forEach(icon => {
    if (hasItems) {
      icon.classList.add('disabled');
      icon.setAttribute('data-original-title', icon.title || '');
      icon.title = 'Complete or void your order first';
    } else {
      icon.classList.remove('disabled');
      icon.title = icon.getAttribute('data-original-title') || '';
    }
  });
  
  sidebarLinks.forEach(link => {
    if (hasItems) {
      link.classList.add('disabled');
      link.setAttribute('data-original-href', link.getAttribute('href'));
      link.removeAttribute('href');
    } else {
      link.classList.remove('disabled');
      const origHref = link.getAttribute('data-original-href');
      if (origHref) link.setAttribute('href', origHref);
    }
  });
  
  if (menuBtn) {
    if (hasItems) {
      menuBtn.classList.add('disabled');
      menuBtn.title = 'Complete or void your order first';
    } else {
      menuBtn.classList.remove('disabled');
      menuBtn.title = '';
    }
  }
  
  // Show/hide warning badge
  const badge = document.getElementById('cartWarning');
  if (badge) {
    if (hasItems && !warningDismissed) {
      badge.style.display = 'block';
      // Auto-hide after 10 seconds
      clearTimeout(window._warningTimer);
      window._warningTimer = setTimeout(() => {
        badge.style.display = 'none';
        warningDismissed = true;
      }, 10000);
    } else if (!hasItems) {
      badge.style.display = 'none';
      warningDismissed = false;
    }
  }
  
  // Update status bar hint
  const connStatus = document.getElementById('connStatus');
  if (connStatus && hasItems) {
    if (!document.getElementById('cartStatusHint')) {
      const hint = document.createElement('span');
      hint.id = 'cartStatusHint';
      hint.style.color = '#ff8800';
      hint.style.fontWeight = '700';
      hint.innerHTML = '⚠️ Cart Active — Complete or Void First';
      connStatus.parentNode.insertBefore(hint, connStatus.nextSibling);
    }
  } else {
    const hint = document.getElementById('cartStatusHint');
    if (hint) hint.remove();
  }
}

function dismissWarning() {
  warningDismissed = true;
  const badge = document.getElementById('cartWarning');
  if (badge) badge.style.display = 'none';
  clearTimeout(window._warningTimer);
}

// Handle clicks on disabled elements
document.addEventListener('click', function(e) {
  // Check if clicked element or its parent is a disabled nav element
  let target = e.target;
  while (target) {
    if (target.classList.contains('disabled') && 
        (target.classList.contains('top-icon') || 
         target.classList.contains('menu-btn') ||
         target.tagName === 'A')) {
      e.preventDefault();
      e.stopPropagation();
      if (hasCartItems()) {
        showNavConfirmation(target);
      }
      return;
    }
    target = target.parentElement;
  }
});

function showNavConfirmation(clickedElement) {
  const modal = document.getElementById('navConfirmModal');
  document.getElementById('navCartCount').textContent = checkout.length;
  
  // Store the intended navigation
  if (clickedElement.tagName === 'A') {
    pendingNavigation = clickedElement.getAttribute('data-original-href') || 
                       clickedElement.getAttribute('href');
  } else {
    const onclick = clickedElement.getAttribute('onclick');
    pendingNavigation = onclick;
  }
  
  modal.classList.add('show');
}

function cancelNavigation() {
  document.getElementById('navConfirmModal').classList.remove('show');
  pendingNavigation = null;
  // Re-show warning if it was dismissed
  warningDismissed = false;
  updateCartProtection();
}

function confirmNavigation() {
  document.getElementById('navConfirmModal').classList.remove('show');
  
  // Void the cart
  clearCheckout();
  
  // Execute the pending navigation after a brief delay
  setTimeout(() => {
    if (pendingNavigation) {
      if (pendingNavigation.includes('location.href') || 
          pendingNavigation.includes('onclick')) {
        // Execute the onclick function
        const func = new Function(pendingNavigation);
        func();
      } else if (pendingNavigation.startsWith('http') || 
                 pendingNavigation.startsWith('/') || 
                 pendingNavigation.includes('.php')) {
        window.location.href = pendingNavigation;
      }
    }
    pendingNavigation = null;
  }, 100);
}

// Intercept sidebar link clicks
document.getElementById('sidebar').addEventListener('click', function(e) {
  const link = e.target.closest('a');
  if (link && hasCartItems() && link.classList.contains('disabled')) {
    e.preventDefault();
    showNavConfirmation(link);
  }
});

// Intercept browser navigation attempts
window.addEventListener('beforeunload', function(e) {
  if (hasCartItems()) {
    e.preventDefault();
    e.returnValue = 'You have items in your cart. Are you sure you want to leave?';
    return e.returnValue;
  }
});

// Override the existing renderOrderPanel to include protection updates
const originalRenderOrderPanel = renderOrderPanel;
renderOrderPanel = function() {
  originalRenderOrderPanel();
  updateCartProtection();
};

// Override clearCheckout to reset protection
const originalClearCheckout = clearCheckout;
clearCheckout = function() {
  originalClearCheckout();
  warningDismissed = false;
  updateCartProtection();
};

// Initialize on load
document.addEventListener('DOMContentLoaded', function() {
  updateCartProtection();
});
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

// Drag functionality for logout modal
(function() {
  const modalBox = document.getElementById('logoutModalBox');
  
  if (!modalBox) return;
  
  let isDragging = false;
  let startX, startY, initialX, initialY;
  
  modalBox.addEventListener('mousedown', function(e) {
    // Don't initiate drag if clicking on a button
    if (e.target.closest('button')) return;
    
    isDragging = true;
    
    const rect = modalBox.getBoundingClientRect();
    startX = e.clientX;
    startY = e.clientY;
    initialX = rect.left;
    initialY = rect.top;
    
    if (!modalBox.style.position || modalBox.style.position === 'relative') {
      modalBox.style.position = 'fixed';
      modalBox.style.left = initialX + 'px';
      modalBox.style.top = initialY + 'px';
      modalBox.style.margin = '0';
    }
    
    modalBox.style.cursor = 'grabbing';
    e.preventDefault();
  });
  
  document.addEventListener('mousemove', function(e) {
    if (!isDragging) return;
    
    const deltaX = e.clientX - startX;
    const deltaY = e.clientY - startY;
    
    modalBox.style.left = (initialX + deltaX) + 'px';
    modalBox.style.top = (initialY + deltaY) + 'px';
  });
  
  document.addEventListener('mouseup', function() {
    if (isDragging) {
      isDragging = false;
      modalBox.style.cursor = 'grab';
    }
  });
})();
/* ── Init ─────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded',function(){
  Cache.initBreads((() => {
    const opts=document.getElementById('ciType')?.options??[];
    const arr=[];
    for(let i=1;i<opts.length;i++) arr.push({ name:opts[i].value, price:parseFloat(opts[i].getAttribute('data-price'))||0 });
    return arr;
  })());
  renderGrid();
  renderOrderPanel();
  ciPriceFromType();
});
/* ─── Success Modal ─────────────────────────────────────────────── */
function openSuccessModal(data) {
   if (document.getElementById('errModal').classList.contains('show')) {
    document.getElementById('errModal').classList.remove('show');
  }
  document.getElementById('smTitle').textContent = data.title || 'Payment Successful!';
  document.getElementById('smSubtitle').textContent = data.subtitle || 'Transaction completed successfully';
  document.getElementById('smTxnId').textContent = '#' + (data.txnId || '—');
  document.getElementById('smMethod').textContent = data.method || '—';
  document.getElementById('smCashier').textContent = data.cashier || '—';
  document.getElementById('smTotal').textContent = currencySymbol + (data.total || '0.00');
  
  // Show/hide reference row
  if (data.ref) {
    document.getElementById('smRefRow').style.display = 'flex';
    document.getElementById('smRef').textContent = data.ref;
  } else {
    document.getElementById('smRefRow').style.display = 'none';
  }
  
  // Show/hide paid/change rows
  if (data.paid !== undefined) {
    document.getElementById('smPaidRow').style.display = 'flex';
    document.getElementById('smPaid').textContent = currencySymbol + data.paid;
  } else {
    document.getElementById('smPaidRow').style.display = 'none';
  }
  
  if (data.change !== undefined && data.change > 0) {
    document.getElementById('smChangeRow').style.display = 'flex';
    document.getElementById('smChange').textContent = currencySymbol + data.change;
  } else {
    document.getElementById('smChangeRow').style.display = 'none';
  }
  
  document.getElementById('successModal').classList.add('show');
  
  // Focus the Done button so Enter key works immediately
  setTimeout(() => {
    const doneBtn = document.querySelector('.success-modal .sm-btn');
    if (doneBtn) doneBtn.focus();
  }, 100);
}

function closeSuccessModal() {
  document.getElementById('successModal').classList.remove('show');
  // Reset position
  const modalBox = document.getElementById('successModalBox');
  if (modalBox) {
    modalBox.style.position = '';
    modalBox.style.left = '';
    modalBox.style.top = '';
    modalBox.style.margin = '';
  }
  
  // Refocus search input for next transaction
  setTimeout(() => {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) searchInput.focus();
  }, 100);
}

// Enter key to close success modal
document.addEventListener('keydown', function(e) {
  if (e.key === 'Enter' && document.getElementById('successModal').classList.contains('show')) {
    e.preventDefault();
    closeSuccessModal();
  }
});

// Drag functionality for success modal
(function() {
  const modalBox = document.getElementById('successModalBox');
  if (!modalBox) return;
  let isDragging = false, startX, startY, initialX, initialY;
  
  modalBox.addEventListener('mousedown', function(e) {
    if (e.target.closest('button')) return;
    isDragging = true;
    const rect = modalBox.getBoundingClientRect();
    startX = e.clientX; startY = e.clientY;
    initialX = rect.left; initialY = rect.top;
    modalBox.style.position = 'fixed';
    modalBox.style.left = initialX + 'px';
    modalBox.style.top = initialY + 'px';
    modalBox.style.margin = '0';
    modalBox.style.cursor = 'grabbing';
    e.preventDefault();
  });
  
  document.addEventListener('mousemove', function(e) {
    if (!isDragging) return;
    modalBox.style.left = (initialX + e.clientX - startX) + 'px';
    modalBox.style.top = (initialY + e.clientY - startY) + 'px';
  });
  
  document.addEventListener('mouseup', function() {
    isDragging = false;
    modalBox.style.cursor = 'grab';
  });
})();
</script>
</body>
</html>