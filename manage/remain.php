<?php
session_start();
require('../dbconn.php');

if (!isset($_SESSION['loggedin'])) {
    header("Location: ../index.php");
    exit;
}
date_default_timezone_set('Asia/Manila');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['record_remain'])) {
        recordBreadRemain($conn);
    }
}

function recordBreadRemain($conn) {
    if (empty($_POST['bread_id']) || !isset($_POST['remaining_quantity']) || !isset($_POST['price'])) {
        $_SESSION['error'] = 'All fields are required'; return;
    }
    if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] <= 0) {
        $_SESSION['error'] = 'You must be logged in to record inventory'; return;
    }
    $bread_id  = intval($_POST['bread_id']);
    $quantity  = intval($_POST['remaining_quantity']);
    $price     = floatval($_POST['price']);
    $user_id   = $_SESSION['user_id'];
    $today     = date('Y-m-d');
    $now       = date('Y-m-d H:i:s');

    if (isset($_POST['edit_id']) && !empty($_POST['edit_id'])) {
        $edit_id = intval($_POST['edit_id']);
        $stmt = $conn->prepare("UPDATE bread_remain SET quantity=?,price=?,recorded_by=?,recorded_at=? WHERE id=?");
        $stmt->bind_param("idisi", $quantity, $price, $user_id, $now, $edit_id);
    } else {
        $stmt = $conn->prepare("INSERT INTO bread_remain (bread_id,quantity,price,date_recorded,recorded_at,recorded_by) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("iidssi", $bread_id, $quantity, $price, $today, $now, $user_id);
    }
    if ($stmt->execute()) {
        $_SESSION['success'] = 'Bread inventory recorded successfully';
        header("Location: ".$_SERVER['PHP_SELF']); exit();
    } else {
        $_SESSION['error'] = 'Error recording inventory: '.$conn->error;
    }
}

function getBreadsWithPrices($conn) {
    $chk = $conn->query("SHOW COLUMNS FROM breads LIKE 'price'");
    $q   = $chk->num_rows > 0
        ? "SELECT id,name,price FROM breads ORDER BY name"
        : "SELECT id,name,0.00 as price FROM breads ORDER BY name";
    $res = $conn->query($q); $breads = [];
    while ($r = $res->fetch_assoc()) $breads[] = $r;
    return $breads;
}

function getTodaysRemainingBreads($conn) {
    $today = date('Y-m-d');
    $stmt  = $conn->prepare(
        "SELECT br.id,br.bread_id,b.name,br.quantity,br.price,
                br.recorded_at,br.recorded_by,nu.fullname AS recorded_by_name,
                (br.quantity*br.price) AS total_value
         FROM bread_remain br
         JOIN breads b ON br.bread_id=b.id
         LEFT JOIN new_user nu ON br.recorded_by=nu.id
         WHERE DATE(br.date_recorded)=?
         ORDER BY br.recorded_at DESC"
    );
    $stmt->bind_param("s", $today); $stmt->execute();
    $res = $stmt->get_result(); $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    return $rows;
}

$breads         = getBreadsWithPrices($conn);
$todaysRemaining = getTodaysRemainingBreads($conn);
$totalValue     = array_sum(array_column($todaysRemaining, 'total_value'));
$totalItems     = !empty($todaysRemaining) ? array_sum(array_column($todaysRemaining, 'quantity')) : 0;

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
$logoPath = $systemSettings['logo_path'] ?? ''; // ADD THIS LINE

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>St4nger POS</title>
<link rel="stylesheet" href="../css/bootstrap-icons.css">
<script src="../js/sweetalert2.all.min.js"></script>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  font-size: 13px;
  background: #1e1e1e;
  color: #e0e0e0;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

/* ══ TOP BAR ══ */
.top-bar {
  background: linear-gradient(180deg, #3a3a3a, #262626);
  height: 44px;
  display: flex; align-items: center; padding: 0 10px; gap: 8px;
  position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
  border-bottom: 2px solid #111;
  box-shadow: 0 2px 10px rgba(0,0,0,0.5);
}
.logo-block {
  background: linear-gradient(135deg, #ff8800, #ff5500);
  border-radius: 5px; padding: 3px 12px;
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
  font-weight: 900; 
  font-size: 12px; 
  color: white; 
  letter-spacing: 0.5px; 
}
.logo-block .sub   { 
  font-size: 7.5px; 
  color: rgba(255,255,255,0.82); 
  letter-spacing: 1.5px; 
  font-weight: 600; 
}
.top-clock  { color: #ffcc66; font-weight: 700; font-size: 11px; margin-left: 8px; }
.top-spacer { flex: 1; }
.top-title  { font-size: 15px; font-weight: 700; color: #f0f0f0; }

.menu-btn {
  background: linear-gradient(180deg, #555, #3a3a3a);
  border: 1px solid #666; border-radius: 4px; color: white;
  font-size: 17px; cursor: pointer; width: 32px; height: 28px;
  display: flex; align-items: center; justify-content: center;
  transition: background 0.15s;
}
.menu-btn:hover { background: linear-gradient(180deg, #ff8800, #cc5500); border-color: #ff8800; }

.top-icon-group { display: flex; gap: 10px; }
.top-icon {
  width: 34px; height: 30px;
  background: linear-gradient(180deg, #e7d8d8, #e2dada);
  border: 1px solid #666; border-radius: 4px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: 14px; color: #ccc; transition: background 0.15s;
}
.top-icon:hover { background: linear-gradient(180deg, #ff8800, #cc5500); color: white; border-color: #ff8800; }

/* ══ SIDEBAR ══ */
.sidebar {
  width: 220px; background: #1c2a1e;
  position: fixed; top: 44px; left: 0;
  height: calc(100vh - 44px - 26px);
  display: none; flex-direction: column;
  z-index: 800; box-shadow: 3px 0 12px rgba(0,0,0,0.5);
  border-right: 1px solid #2a4030;
}
.sidebar a {
  display: flex; align-items: center; gap: 10px;
  padding: 11px 16px; color: #aaccb0; text-decoration: none;
  font-size: 13px; border-bottom: 1px solid #1e3022;
  transition: background 0.15s;
}
.sidebar a:hover { background: #ff8800; color: white; }

/* ══ MAIN ══ */
.main-content { margin-top: 44px; padding: 14px 16px; flex: 1; }

/* ══ PAGE HEADER ══ */
.page-header {
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 14px; padding-bottom: 10px;
  border-bottom: 2px solid #333;
}
.page-header h2 { font-size: 17px; font-weight: 800; color: #f0f0f0; }
.date-badge {
  background: linear-gradient(135deg, #ff8800, #cc5500);
  color: white; padding: 3px 10px; border-radius: 4px;
  font-size: 11px; font-weight: 700;
}

/* ══ CARDS ══ */
.card {
  background: #2a2a2a; border: 1px solid #353535;
  border-radius: 7px; overflow: hidden; margin-bottom: 14px;
}
.card-header {
  background: linear-gradient(180deg, #323232, #2a2a2a);
  border-bottom: 1px solid #3a3a3a;
  padding: 10px 14px; display: flex; align-items: center; justify-content: space-between;
}
.card-header .card-title {
  font-size: 13px; font-weight: 700; color: #f0c060; margin: 0;
}
.card-body { padding: 14px; }

/* ══ SUMMARY STRIP ══ */
.summary-strip {
  display: flex; gap: 10px; margin-bottom: 14px; flex-wrap: wrap;
}
.summary-tile {
  flex: 1; min-width: 140px;
  background: linear-gradient(135deg, #2e2e2e, #242424);
  border: 1px solid #3a3a3a; border-radius: 7px;
  padding: 12px 16px;
}
.summary-tile .s-label { font-size: 10px; color: #ffffff; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
.summary-tile .s-val   { font-size: 22px; font-weight: 900; color: #f0f0f0; }
.summary-tile .s-val.green { color: #66dd88; }
.summary-tile .s-val.orange { color: #ffcc66; }

/* ══ FORM ══ */
.form-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group.grow { flex: 1; min-width: 160px; }
.form-label { font-size: 11px; color: #ececec; font-weight: 600; }

.form-select, .form-input {
  background: #333; border: 1.5px solid #555;
  color: #eee; border-radius: 4px; padding: 7px 10px;
  font-size: 12px; transition: border-color 0.15s;
  width: 100%;
}
.form-select:focus, .form-input:focus { outline: none; border-color: #ff8800; background: #3a3a3a; }
.form-select option { background: #333; }
.form-input::placeholder { color: #666; }

.price-wrap { display: flex; gap: 0; }
.price-wrap .form-input { border-radius: 4px 0 0 4px; }
.autofill-btn {
  background: linear-gradient(180deg, #555, #3a3a3a);
  border: 1.5px solid #555; border-left: none;
  color: #aaa; border-radius: 0 4px 4px 0;
  padding: 0 10px; cursor: pointer; font-size: 13px;
  transition: background 0.15s;
}
.autofill-btn:hover { background: linear-gradient(180deg, #ff8800, #cc6600); color: white; border-color: #ff8800; }

/* ══ BUTTONS ══ */
.btn {
  padding: 7px 16px; border: none; border-radius: 4px;
  font-size: 12px; font-weight: 600; cursor: pointer;
  display: inline-flex; align-items: center; gap: 5px;
  text-decoration: none; transition: filter 0.15s; white-space: nowrap;
}
.btn:hover { filter: brightness(1.1); }
.btn-orange  { background: linear-gradient(135deg, #ff9900, #cc6600); color: white; }
.btn-dark    { background: linear-gradient(135deg, #555, #333); color: #ddd; }
.btn-green   { background: linear-gradient(135deg, #28a745, #1a6e2e); color: white; }
.btn-blue    { background: linear-gradient(135deg, #3a7bd5, #1a4fa0); color: white; }
.btn-red     { background: linear-gradient(135deg, #e53935, #b71c1c); color: white; }
.btn-yellow  { background: linear-gradient(135deg, #f5a623, #c47d0a); color: #111; }
.btn-sm      { padding: 4px 10px; font-size: 11px; }
.btn-save    { background: linear-gradient(135deg, #ff9900, #cc6600); color: white; min-width: 80px; }
.btn-save.edit-mode { background: linear-gradient(135deg, #f5a623, #c47d0a); color: #111; }

/* ══ TABLE ══ */
.table-wrap { overflow-x: auto; }
.table-wrap::-webkit-scrollbar { height: 4px; }
.table-wrap::-webkit-scrollbar-thumb { background: #555; border-radius: 2px; }

table { width: 100%; border-collapse: collapse; min-width: 700px; }
thead tr { background: linear-gradient(180deg, #ff9900, #cc6600); }
thead th {
  padding: 9px 12px; font-size: 11px; font-weight: 700;
  color: white; text-transform: uppercase; letter-spacing: 0.5px;
  border-right: 1px solid rgba(255,255,255,0.15); white-space: nowrap;
}
thead th:last-child { border-right: none; }
tbody tr { border-bottom: 1px solid #2e2e2e; transition: background 0.1s; }
tbody tr:hover { background: #2d2d2d; }
tbody td { padding: 8px 12px; font-size: 12px; color: #d0d0d0; vertical-align: middle; }

.totals-tr td {
  background: #242424; border-top: 2px solid #ff8800;
  font-weight: 700; color: #ffcc66; padding: 9px 12px;
}
.money-green  { color: #66dd88; font-weight: 700; }
.money-orange { color: #ffcc66; font-weight: 700; }
.money-blue   { color: #88ccff; font-weight: 600; }

/* ══ EMPTY STATE ══ */
.empty-state {
  text-align: center; padding: 40px 20px; color: #555;
}
.empty-state .ei { font-size: 40px; margin-bottom: 10px; opacity: 0.4; }
.empty-state p { font-size: 13px; }

/* ══ STATUS BAR ══ */
.status-bar {
  background: #111; border-top: 1px solid #222;
  padding: 3px 12px; font-size: 10px; color: #ffffff;
  display: flex; gap: 14px; height: 26px; align-items: center; flex-shrink: 0;
}
.status-bar span { border-right: 1px solid #2a2a2a; padding-right: 14px; }
.status-bar span:last-child { border-right: none; margin-left: auto; }
.stat-offline { color: #ff4444 !important; font-weight: 700; }
.stat-online  { color: #44ff88 !important; font-weight: 700; }

/* ══ MODAL ══ */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,0.7);
  z-index: 9000; align-items: center; justify-content: center;
}
.modal-overlay.show { display: flex; }
.modal-box {
  background: #282828; border: 1.5px solid #3a3a3a; border-radius: 8px;
  width: 90%; max-width: 400px; overflow: hidden;
  box-shadow: 0 20px 60px rgba(0,0,0,0.7);
  cursor: grab;
  position: relative;
}
.modal-box:active { cursor: grabbing; }
.modal-title-bar {
  background: linear-gradient(135deg, #cc2200, #880000);
  padding: 10px 14px; display: flex; justify-content: space-between; align-items: center;
  cursor: grab;
}
.modal-title-bar:active { cursor: grabbing; }
.modal-title-bar span { font-weight: 700; font-size: 13px; color: white; }
.modal-x {
  background: rgba(0,0,0,0.25); color: white; border: none;
  border-radius: 3px; width: 24px; height: 24px; font-size: 14px;
  cursor: pointer; font-weight: 700; display: flex; align-items: center; justify-content: center;
}
.modal-x:hover { background: rgba(0,0,0,0.5); }
.modal-body   { padding: 16px; }
.modal-footer { padding: 10px 14px; border-top: 1px solid #333; display: flex; gap: 8px; justify-content: flex-end; }

/* ══ FOOTER ══ */
.footer { text-align: center; padding: 8px; background: #111; color: #fcfcfc; font-size: 11px; flex-shrink: 0; }
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="top-bar">
  <button class="menu-btn" id="menuBtn" onclick="toggleSidebar()">☰</button>
  <div class="logo-block">
    <?php if (!empty($logoPath) && file_exists('../' . $logoPath)): ?>
      <img src="../<?= htmlspecialchars($logoPath) ?>" alt="Logo" class="logo-img">
    <?php endif; ?>
    <div class="logo-text">
      <span class="brand"><?= htmlspecialchars($businessName) ?></span>
      <span class="sub"><?= htmlspecialchars($businessSubtitle) ?></span>
    </div>
  </div>
  <span class="top-title" style="margin-left:8px;">Bread Inventory</span>
  <span class="top-clock" id="currentTime"></span>
  <div style="font-size:12px; margin-left:15px;"><?= htmlspecialchars($businessAddress) ?></div>
  <div class="top-spacer"></div>
  <div class="top-icon-group">
    <a class="top-icon" href="../home.php"title="POS Home"><img src="../image/icons/POS.png" alt="Home" style="width: 20px; height:20px; object-fit:contain;"></a>
    <a class="top-icon" href="../profile/prof.php"title="Profile"><img src="../image/icons/man.png" alt="Profile" style="width:20px; height:20px; object-fit:contain;"></a>
    <a class="top-icon" href="transaction.php"itle="Records"><img src="../image/icons/inventory.png" alt="Records" style="width:25px; height:25px; object-fit:contain;"></a>
    <a class="top-icon" href="../logout.php"title="Logout"><img src="../image/icons/power.png" alt="Logout" style="width:20px; height:20px; object-fit:contain;"></a>
  </div>
</div>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
  <a href="../home.php">🏠 &nbsp;Home / POS</a>
  <a href="../profile/prof.php">👤 &nbsp;Manage Profile</a>
  <a href="transaction.php">📋 &nbsp;Manage Records</a>
  <a href="../logout.php">🚪 &nbsp;Logout</a>
</div>

<!-- MAIN -->
<div class="main-content" id="mainContent">

  <div class="page-header">
    <h2>🧺 Daily Bread Inventory</h2>
    <span class="date-badge"><?= date('F j, Y, l') ?></span>
  </div>

  <!-- Summary tiles -->
  <div class="summary-strip">
    <div class="summary-tile">
      <div class="s-label">Today's Items</div>
      <div class="s-val orange"><?= number_format($totalItems) ?></div>
    </div>
    <div class="summary-tile">
      <div class="s-label">Total Value</div>
      <div class="s-val green">₱<?= number_format($totalValue, 2) ?></div>
    </div>
    <div class="summary-tile">
      <div class="s-label">Bread Types</div>
      <div class="s-val"><?= count($todaysRemaining) ?></div>
    </div>
  </div>

  <!-- Flash messages (handled via JS/Swal) -->
  <?php if (isset($_SESSION['success'])): ?>
    <div id="php-success" data-msg="<?= htmlspecialchars($_SESSION['success']) ?>" style="display:none;"></div>
    <?php unset($_SESSION['success']); ?>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div id="php-error" data-msg="<?= htmlspecialchars($_SESSION['error']) ?>" style="display:none;"></div>
    <?php unset($_SESSION['error']); ?>
  <?php endif; ?>

  <!-- Form card -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">➕ Bread Inventory Form</span>
      <span style="font-size:12px;color:#fff;">Fill in all fields and click Save</span>
    </div>
    <div class="card-body">
      <form method="POST" id="inventoryForm">
        <input type="hidden" name="record_remain" value="1">
        <input type="hidden" name="edit_id" id="edit_id" value="">
        <div class="form-row">

          <div class="form-group grow" style="flex:2;">
            <label class="form-label">Bread Type</label>
            <select class="form-select" id="bread_id" name="bread_id" required>
              <option value="">-- Select Bread --</option>
              <?php foreach ($breads as $b): ?>
                <option value="<?= $b['id'] ?>"
                        data-price="<?= $b['price'] ?>"
                        data-name="<?= htmlspecialchars($b['name']) ?>">
                  <?= htmlspecialchars($b['name']) ?> (₱<?= number_format($b['price'],2) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group" style="width:110px;">
            <label class="form-label">Quantity</label>
            <input type="number" class="form-input" id="remaining_quantity"
                   name="remaining_quantity" min="0" step="1" placeholder="0" required>
          </div>

          <div class="form-group" style="width:160px;">
            <label class="form-label">Price / Piece (₱)</label>
            <div class="price-wrap">
              <input type="number" class="form-input" id="price"
                     name="price" min="0" step="0.01" placeholder="0.00" required>
              <button type="button" class="autofill-btn" id="autofillPrice" title="Reset to default price">↺</button>
            </div>
          </div>

          <div class="form-group" style="align-self:flex-end;">
            <button type="submit" class="btn btn-save" id="submitBtn">💾 Save</button>
          </div>

          <div class="form-group" style="align-self:flex-end;">
            <button type="button" class="btn btn-dark btn-sm" id="cancelEditBtn"
                    onclick="resetFormState()" style="display:none;">✕ Cancel</button>
          </div>

        </div>
      </form>
    </div>
  </div>

  <!-- Inventory table card -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">📋 Today's Bread Inventory</span>
      <button class="btn btn-dark btn-sm" id="printBtn">🖨 Print</button>
    </div>
    <div class="card-body" style="padding:0;">
      <?php if (!empty($todaysRemaining)): ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Bread Name</th>
                <th style="text-align:right;">Qty</th>
                <th style="text-align:right;">Price</th>
                <th style="text-align:right;">Total</th>
                <th>Recorded At</th>
                <th>Recorded By</th>
                <th style="text-align:center;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($todaysRemaining as $item): ?>
                <tr class="inventory-row">
                  <td><?= htmlspecialchars($item['name']) ?></td>
                  <td style="text-align:right;" class="money-orange"><?= number_format($item['quantity']) ?></td>
                  <td style="text-align:right;" class="money-blue">₱<?= number_format($item['price'],2) ?></td>
                  <td style="text-align:right;" class="money-green">₱<?= number_format($item['total_value'],2) ?></td>
                  <td style="white-space:nowrap;color:#888;"><?= date('M j, Y h:i A', strtotime($item['recorded_at'])) ?></td>
                  <td style="color:#aaa;"><?= htmlspecialchars($item['recorded_by_name'] ?? 'System') ?></td>
                  <td style="text-align:center;white-space:nowrap;">
                    <button class="btn btn-blue btn-sm edit-btn"
                            data-id="<?= $item['id'] ?>"
                            data-bread-id="<?= $item['bread_id'] ?>"
                            data-quantity="<?= $item['quantity'] ?>"
                            data-price="<?= $item['price'] ?>">
                      ✏ Edit
                    </button>
                    <button class="btn btn-red btn-sm delete-btn"
                            data-id="<?= $item['id'] ?>"
                            data-name="<?= htmlspecialchars($item['name']) ?>">
                      🗑 Delete
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
              <tr class="totals-tr">
                <td colspan="3" style="text-align:right;">TOTALS:</td>
                <td style="text-align:right;">₱<?= number_format(array_sum(array_column($todaysRemaining,'total_value')),2) ?></td>
                <td colspan="3"></td>
              </tr>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="empty-state">
          <div class="ei">🧺</div>
          <p style="color:#fff;font-weight:600;">No inventory records for today</p>
          <p style="color:#fff;">Use the form above to add records.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div><!-- end main-content -->

<!-- STATUS BAR -->
<div class="status-bar">
  <span>ASt4nger POS v1.0</span>
  <span>Bread Inventory</span>
  <span><?= date('F j, Y') ?></span>
  <span>Cashier: <?= htmlspecialchars($_SESSION['fullname'] ?? 'N/A') ?></span>
  <span class="stat-offline" id="connStatus">● OFFLINE</span>
</div>

<!-- FOOTER -->
<div class="footer">&copy; <?= date('Y') ?> St4nger Dev. All rights reserved.</div>

<!-- DELETE CONFIRMATION MODAL -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal-box" id="deleteModalBox">
    <div class="modal-title-bar" id="deleteModalHandle">
      <span>🗑 Confirm Deletion</span>
      <button class="modal-x" onclick="closeDeleteModal()">✕</button>
    </div>
    <div class="modal-body">
      <p style="margin-bottom:10px;">Are you sure you want to delete the record for
        <strong style="color:#ffcc66;" id="deleteBreadName"></strong>?
      </p>
      <p style="color:#ff8888;font-size:12px;">⚠ This action cannot be undone.</p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-dark" onclick="closeDeleteModal()">Cancel</button>
      <button class="btn btn-red" id="confirmDelete">🗑 Delete</button>
    </div>
  </div>
</div>

<script>
/* ─── Clock ─── */
function updateClock(){
  document.getElementById('currentTime').textContent=new Date().toLocaleString('en-US',{
    timeZone:'Asia/Manila',weekday:'short',year:'numeric',month:'short',
    day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true
  });
}
setInterval(updateClock,1000); updateClock();

/* ─── Sidebar ─── */
function toggleSidebar(){
  const sb=document.getElementById('sidebar');
  sb.style.display=sb.style.display==='flex'?'none':'flex';
  document.getElementById('menuBtn').textContent=sb.style.display==='flex'?'✖':'☰';
}

/* ─── Connectivity ─── */
function checkConn(){
  fetch('../record/ping.php',{cache:'no-store'})
    .then(r=>{const el=document.getElementById('connStatus');el.textContent=r.ok?'● ONLINE':'● OFFLINE';el.className=r.ok?'stat-online':'stat-offline';})
    .catch(()=>{const el=document.getElementById('connStatus');el.textContent='● OFFLINE';el.className='stat-offline';});
}
setInterval(checkConn,15000); checkConn();

/* ─── Flash messages ─── */
window.addEventListener('DOMContentLoaded',function(){
  const sm=document.getElementById('php-success');
  const em=document.getElementById('php-error');
  if(sm) Swal.fire({icon:'success',title:'Success!',text:sm.dataset.msg,
    toast:true,position:'top-end',showConfirmButton:false,timer:3000,timerProgressBar:true});
  if(em) Swal.fire({icon:'error',title:'Error',text:em.dataset.msg,confirmButtonColor:'#ff6600'});
});

/* ─── Auto-fill price on bread select ─── */
document.getElementById('bread_id').addEventListener('change',function(){
  const opt=this.options[this.selectedIndex];
  if(opt.value) document.getElementById('price').value=opt.getAttribute('data-price');
});

document.getElementById('autofillPrice').addEventListener('click',function(){
  const opt=document.getElementById('bread_id').options[document.getElementById('bread_id').selectedIndex];
  if(opt.value){
    document.getElementById('price').value=opt.getAttribute('data-price');
    Swal.fire({icon:'success',title:'Price reset',toast:true,position:'top-end',
      showConfirmButton:false,timer:1500});
  }
});

/* ─── Form state ─── */
function resetFormState(){
  document.getElementById('edit_id').value='';
  document.getElementById('submitBtn').textContent='💾 Save';
  document.getElementById('submitBtn').classList.remove('edit-mode');
  document.getElementById('cancelEditBtn').style.display='none';
  document.getElementById('inventoryForm').reset();
}

/* ─── Edit buttons ─── */
document.querySelectorAll('.edit-btn').forEach(btn=>{
  btn.addEventListener('click',function(){
    document.getElementById('edit_id').value=this.dataset.id;
    document.getElementById('bread_id').value=this.dataset.breadId;
    document.getElementById('remaining_quantity').value=this.dataset.quantity;
    document.getElementById('price').value=this.dataset.price;
    document.getElementById('submitBtn').textContent='💾 Update';
    document.getElementById('submitBtn').classList.add('edit-mode');
    document.getElementById('cancelEditBtn').style.display='inline-flex';
    document.querySelector('form').scrollIntoView({behavior:'smooth'});
  });
});

/* ─── Delete modal ─── */
function closeDeleteModal(){ 
  document.getElementById('deleteModal').classList.remove('show'); 
  // Reset position
  const modalBox = document.getElementById('deleteModalBox');
  if (modalBox) {
    modalBox.style.position = '';
    modalBox.style.left = '';
    modalBox.style.top = '';
    modalBox.style.margin = '';
  }
}
document.getElementById('deleteModal').addEventListener('click',function(e){ if(e.target===this) closeDeleteModal(); });

let deleteId=null;
document.querySelectorAll('.delete-btn').forEach(btn=>{
  btn.addEventListener('click',function(){
    deleteId=this.dataset.id;
    document.getElementById('deleteBreadName').textContent=this.dataset.name;
    document.getElementById('deleteModal').classList.add('show');
  });
});

document.getElementById('confirmDelete').addEventListener('click',function(){
  fetch('../admin/delete_inventory.php',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'id='+deleteId
  })
  .then(r=>r.json())
  .then(data=>{
    if(data.status==='success'){
      closeDeleteModal();
      Swal.fire({icon:'success',title:'Deleted!',toast:true,position:'top-end',
        showConfirmButton:false,timer:1800,willClose:()=>location.reload()});
    } else {
      Swal.fire({icon:'error',title:'Error',text:data.message||'Failed to delete.',confirmButtonColor:'#ff6600'});
    }
  })
  .catch(err=>Swal.fire({icon:'error',title:'Network Error',text:err.toString(),confirmButtonColor:'#ff6600'}));
});

/* ─── Form submit confirmation ─── */
document.getElementById('inventoryForm').addEventListener('submit',function(e){
  e.preventDefault();
  const form = this;
  const breadSel=document.getElementById('bread_id');
  const breadName=breadSel.options[breadSel.selectedIndex].getAttribute('data-name');
  const qty=document.getElementById('remaining_quantity').value;
  const price=document.getElementById('price').value;
  const total=(qty*price).toFixed(2);
  const isEdit=document.getElementById('edit_id').value!=='';

  if(!breadName) return Swal.fire({icon:'error',title:'Error',text:'Please select a bread type.',confirmButtonColor:'#ff6600'});

  Swal.fire({
    title: isEdit?'Update Inventory Record':'Add New Inventory Record',
    html:`<div style="text-align:left;font-size:13px;">
      <p style="margin-bottom:8px;">You are ${isEdit?'updating':'adding'} a record for:</p>
      <table style="width:100%;border-collapse:collapse;">
        <tr style="border-bottom:1px solid #333;"><td style="padding:5px;color:#999;">Bread:</td><td style="padding:5px;font-weight:700;color:#ffcc66;">${breadName}</td></tr>
        <tr style="border-bottom:1px solid #333;"><td style="padding:5px;color:#999;">Quantity:</td><td style="padding:5px;">${qty} pcs</td></tr>
        <tr style="border-bottom:1px solid #333;"><td style="padding:5px;color:#999;">Price:</td><td style="padding:5px;">₱${parseFloat(price).toFixed(2)}</td></tr>
        <tr><td style="padding:5px;color:#999;">Total Value:</td><td style="padding:5px;font-weight:700;color:#66dd88;">₱${total}</td></tr>
      </table>
    </div>`,
    icon:'question',
    showCancelButton:true,
    confirmButtonColor:'#ff8800',
    cancelButtonColor:'#555',
    confirmButtonText: isEdit?'✓ Update':'✓ Add Record',
    cancelButtonText:'Cancel',
    background:'#282828', color:'#e0e0e0'
  }).then((result)=>{ 
    if(result.isConfirmed) {
      form.submit();
    }
  });
});

/* ─── DRAG FUNCTIONALITY FOR DELETE MODAL ─── */
(function() {
  const modalBox = document.getElementById('deleteModalBox');
  const modalHandle = document.getElementById('deleteModalHandle');
  
  if (!modalBox || !modalHandle) return;
  
  let isDragging = false;
  let startX, startY, initialX, initialY;
  
  modalHandle.addEventListener('mousedown', function(e) {
    // Don't initiate drag if clicking on the close button
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
    
    modalHandle.style.cursor = 'grabbing';
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
      modalHandle.style.cursor = 'grab';
    }
  });
})();

/* ─── Print ─── */
document.getElementById('printBtn').addEventListener('click',function(){
  const rows=Array.from(document.querySelectorAll('.inventory-row')).map(r=>`
    <tr><td>${r.cells[0].textContent}</td>
        <td style="text-align:right;">${r.cells[1].textContent}</td>
        <td style="text-align:right;">${r.cells[2].textContent}</td>
        <td style="text-align:right;">${r.cells[3].textContent}</td></tr>`).join('');
  const w=window.open('','_blank');
  w.document.write(`<html><head><title>Bread Inventory Report</title>
    <style>
      body{font-family:Arial,sans-serif;padding:20px;}
      h3,h4{text-align:center;margin:4px 0;}
      table{width:100%;border-collapse:collapse;margin-top:16px;}
      th{background:#333;color:white;padding:7px 10px;text-align:left;}
      td{padding:6px 10px;border-bottom:1px solid #ddd;}
      .tot{font-weight:bold;background:#f5f5f5;}
      .sig{margin-top:40px;display:flex;justify-content:space-around;}
      .sig div{text-align:center;}
    </style></head><body>
    <h3>Four ACC Angels Bakeshop</h3>
    <h4>Daily Bread Inventory Report</h4>
    <p style="text-align:center;">${new Date().toLocaleDateString('en-US',{weekday:'long',year:'numeric',month:'long',day:'numeric'})}</p>
    <table><thead><tr><th>Bread</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Price</th><th style="text-align:right;">Total</th></tr></thead>
    <tbody>${rows}
    <tr class="tot"><td colspan="3" style="text-align:right;">TOTAL VALUE:</td><td style="text-align:right;">₱<?= number_format($totalValue,2) ?></td></tr>
    </tbody></table>
    <div class="sig"><div><p>_________________________</p><p>Prepared By</p></div>
    <div><p>_________________________</p><p>Verified By</p></div></div>
    <script>window.onload=function(){window.print();setTimeout(()=>window.close(),400);}<\/script>
    </body></html>`);
  w.document.close();
});
</script>
</body>
</html>