<?php
// Start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require('../dbconn.php');

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../access_denied.php"); exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_remain'])) {
    if (empty($_POST['bread_id']) || !isset($_POST['remaining_quantity']) || !isset($_POST['price'])) {
        $_SESSION['swal'] = ['type'=>'error','title'=>'Missing Fields','text'=>'All fields are required.'];
    } elseif (!isset($_SESSION['user_id']) || $_SESSION['user_id'] <= 0) {
        $_SESSION['swal'] = ['type'=>'error','title'=>'Not Logged In','text'=>'You must be logged in to record inventory.'];
    } else {
        $bread_id = intval($_POST['bread_id']);
        $quantity = intval($_POST['remaining_quantity']);
        $price    = floatval($_POST['price']);
        $user_id  = $_SESSION['user_id'];
        $today    = date('Y-m-d');
        $now      = date('Y-m-d H:i:s');

        if (!empty($_POST['edit_id'])) {
            $eid  = intval($_POST['edit_id']);
            $stmt = $conn->prepare("UPDATE bread_remain SET quantity=?,price=?,recorded_by=?,recorded_at=? WHERE id=?");
            $stmt->bind_param("idisi", $quantity, $price, $user_id, $now, $eid);
        } else {
            $stmt = $conn->prepare("INSERT INTO bread_remain (bread_id,quantity,price,date_recorded,recorded_at,recorded_by) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param("iidssi", $bread_id, $quantity, $price, $today, $now, $user_id);
        }
        $_SESSION['swal'] = $stmt->execute()
            ? ['type'=>'success','title'=>'Saved!','text'=>'Bread inventory recorded successfully.']
            : ['type'=>'error','title'=>'Error','text'=>$conn->error];
    }
    header("Location: ".$_SERVER['PHP_SELF']); exit;
}

/* ═══════ FILTER ═══════════════════════════════════════════════════════ */
$filterDate = $_GET['filter_date'] ?? date('Y-m-d');
$filterType = $_GET['filter_type'] ?? 'today';

/* ═══════ DATA ══════════════════════════════════════════════════════════ */
function getBreadsWithPrices($conn) {
    $chk = $conn->query("SHOW COLUMNS FROM breads LIKE 'price'");
    $q   = $chk->num_rows > 0
        ? "SELECT id,name,price FROM breads ORDER BY name"
        : "SELECT id,name,0.00 as price FROM breads ORDER BY name";
    return $conn->query($q)->fetch_all(MYSQLI_ASSOC);
}

function getFilteredRemaining($conn, $filterDate, $filterType) {
    $q = "SELECT br.id,br.bread_id,b.name,br.quantity,br.price,
                 br.recorded_at,br.recorded_by,nu.fullname as recorded_by_name,
                 (br.quantity*br.price) as total_value
          FROM bread_remain br
          JOIN breads b ON br.bread_id=b.id
          LEFT JOIN new_user nu ON br.recorded_by=nu.id";
    switch($filterType) {
        case 'month': $q.=" WHERE YEAR(br.date_recorded)=YEAR(?) AND MONTH(br.date_recorded)=MONTH(?)"; break;
        case 'year':  $q.=" WHERE YEAR(br.date_recorded)=YEAR(?)"; break;
        default:      $q.=" WHERE DATE(br.date_recorded)=?"; break;
    }
    $q.=" ORDER BY br.recorded_at DESC,b.name";
    $stmt=$conn->prepare($q);
    if($filterType==='month') $stmt->bind_param("ss",$filterDate,$filterDate);
    else $stmt->bind_param("s",$filterDate);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get system settings for header
$systemSettings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $result->fetch_assoc()) {
    $systemSettings[$row['setting_key']] = $row['setting_value'];
}

$breads    = getBreadsWithPrices($conn);
$records   = getFilteredRemaining($conn, $filterDate, $filterType);
$totalVal  = array_sum(array_column($records,'total_value'));
$totalQty  = array_sum(array_column($records,'quantity'));
$avgPrice  = $totalQty > 0 ? $totalVal/$totalQty : 0;

// Period label
$periodLabel = match($filterType) {
    'month' => date('F Y', strtotime($filterDate)),
    'year'  => date('Y', strtotime($filterDate)),
    default => date('F j, Y', strtotime($filterDate)),
};

// Set page title for header
$pageTitle = 'Bread Inventory';
$activePage = 'bleft';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bread Inventory – Admin</title>
<link rel="stylesheet" href="../css/bootstrap-icons.css">
<script src="../js/sweetalert2.all.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
<style>
/* Page-specific styles only (header/footer styles are in the includes) */
:root{
  --orange:#ff8800;--orange-dk:#cc5500;--orange-lt:#ffaa44;
  --green:#00c853;--red:#ff4444;--yellow:#ffcc00;--blue:#4488ff;
  --bg:#111318;--bg2:#161b27;--bg3:#1e2330;
  --card:#1e2330;--card2:#242b3a;
  --border:#2a3145;--border2:#323d55;
  --text:#e8eaf0;--text2:#9aa3bc;--text3:#5a6380;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;font-size:13px;background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column;}

/* MAIN - adjusted for header */
.main{margin-top:52px;padding:18px;flex:1;}
.page-hero{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;}
.page-hero h2{font-size:18px;font-weight:800;color:var(--text);}
.page-hero p{font-size:11px;color:var(--text3);margin-top:2px;}

/* ═══ FILTER CARD ═══ */
.filter-card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin-bottom:14px;}
.filter-row{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;}
.f-group{display:flex;flex-direction:column;gap:4px;}
.f-label{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;}
.f-input,.f-select{background:var(--bg3);border:1.5px solid var(--border);color:var(--text);border-radius:6px;padding:7px 11px;font-size:12px;transition:border-color .15s;}
.f-input:focus,.f-select:focus{outline:none;border-color:var(--orange);}
.f-select option{background:var(--card2);}

/* ═══ STAT TILES ═══ */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin-bottom:14px;}
.stat-tile{background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:13px 15px;transition:border-color .15s;}
.stat-tile:hover{border-color:var(--orange);}
.stat-tile .st-icon{font-size:20px;margin-bottom:5px;}
.stat-tile .st-label{font-size:9px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px;}
.stat-tile .st-val{font-size:20px;font-weight:900;}
.st-val.orange{color:var(--orange-lt);}
.st-val.green {color:#4dff88;}
.st-val.blue  {color:#88bbff;}
.st-val.yellow{color:var(--yellow);}

/* ═══ TWO-COL LAYOUT ═══ */
.two-col{display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-bottom:14px;}
@media(max-width:900px){.two-col{grid-template-columns:1fr;}}

/* ═══ PANEL ═══ */
.panel{background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
.panel-header{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:linear-gradient(90deg,var(--card2),var(--card));}
.panel-title{font-size:12px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:7px;}
.pt-dot{width:7px;height:7px;border-radius:50%;background:var(--orange);box-shadow:0 0 5px var(--orange);}
.panel-body{padding:14px 16px;}

/* ═══ FORM ═══ */
.form-grid{display:grid;grid-template-columns:2fr 1fr 1fr auto auto;gap:10px;align-items:flex-end;}
@media(max-width:768px){.form-grid{grid-template-columns:1fr 1fr;}}
.fg{display:flex;flex-direction:column;gap:4px;}
.fg label{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;}
.fg-input,.fg-select{background:var(--bg3);border:1.5px solid var(--border);color:var(--text);border-radius:6px;padding:8px 11px;font-size:12px;transition:border-color .15s;width:100%;}
.fg-input:focus,.fg-select:focus{outline:none;border-color:var(--orange);}
.fg-select option{background:var(--card2);}
.price-wrap{display:flex;gap:0;}
.price-wrap .fg-input{border-radius:6px 0 0 6px;border-right:none;}
.autofill-btn{background:var(--bg3);border:1.5px solid var(--border);border-left:none;color:var(--text3);border-radius:0 6px 6px 0;padding:0 10px;cursor:pointer;font-size:12px;transition:all .15s;}
.autofill-btn:hover{background:var(--orange);color:white;border-color:var(--orange);}

/* ═══ TABLE ═══ */
.tbl-wrap{overflow-x:auto;}
.tbl-wrap::-webkit-scrollbar{height:3px;}
.tbl-wrap::-webkit-scrollbar-thumb{background:var(--border2);}
.data-tbl{width:100%;border-collapse:collapse;min-width:640px;}
.data-tbl thead tr{background:linear-gradient(90deg,var(--orange),var(--orange-dk));}
.data-tbl thead th{padding:9px 12px;font-size:10px;font-weight:700;color:rgba(255,255,255,.9);text-transform:uppercase;letter-spacing:.8px;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);}
.data-tbl thead th:last-child{border-right:none;}
.data-tbl tbody tr{border-bottom:1px solid var(--border);transition:background .1s;}
.data-tbl tbody tr:hover{background:rgba(255,255,255,.025);}
.data-tbl tbody td{padding:9px 12px;font-size:11px;color:var(--text2);vertical-align:middle;}
.data-tbl tfoot tr{border-top:2px solid var(--orange);}
.data-tbl tfoot td{padding:9px 12px;font-size:11px;font-weight:700;color:var(--orange-lt);}
.td-name{color:var(--text);font-weight:600;}
.td-money{color:#4dff88;font-weight:700;}
.td-date{color:var(--text3);font-size:10px;}
.td-empty{text-align:center;color:var(--text3);padding:32px!important;}

/* Summary panel */
.sum-panel{background:linear-gradient(135deg,var(--orange),var(--orange-dk));border-radius:10px;padding:18px;height:100%;display:flex;flex-direction:column;justify-content:center;gap:10px;}
.sum-panel .sp-row{display:flex;justify-content:space-between;align-items:baseline;}
.sum-panel .sp-label{font-size:11px;color:rgba(255,255,255,.7);font-weight:600;}
.sum-panel .sp-val{font-size:20px;font-weight:900;color:white;}
.sum-panel .sp-period{font-size:10px;color:rgba(255,255,255,.6);margin-bottom:8px;}

/* Search bar in panel header */
.tbl-search{background:var(--bg3);border:1.5px solid var(--border);color:var(--text);border-radius:6px;padding:5px 10px;font-size:11px;width:180px;transition:border-color .15s;}
.tbl-search:focus{outline:none;border-color:var(--orange);}

/* Buttons */
.btn{padding:6px 14px;border:none;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none;transition:all .15s;white-space:nowrap;}
.btn:hover{filter:brightness(1.1);transform:translateY(-1px);}
.btn-orange{background:linear-gradient(135deg,var(--orange),var(--orange-dk));color:white;}
.btn-red   {background:linear-gradient(135deg,var(--red),#aa1111);color:white;}
.btn-dark  {background:var(--bg3);border:1px solid var(--border);color:var(--text2);}
.btn-dark:hover{background:var(--border2);filter:none;transform:none;}
.btn-green {background:linear-gradient(135deg,var(--green),#007a2e);color:white;}
.btn-blue  {background:linear-gradient(135deg,var(--blue),#1a4fa0);color:white;}
.btn-sm    {padding:3px 9px;font-size:10px;}

/* Export dropdown */
.export-wrap{position:relative;}
.export-menu{display:none;position:absolute;right:0;top:calc(100% + 4px);background:var(--card2);border:1px solid var(--border2);border-radius:8px;min-width:180px;z-index:200;box-shadow:0 8px 24px rgba(0,0,0,.5);overflow:hidden;}
.export-menu.show{display:block;}
.export-item{display:flex;align-items:center;gap:8px;padding:9px 14px;font-size:11px;font-weight:600;color:var(--text2);cursor:pointer;transition:background .15s;border:none;background:none;width:100%;text-align:left;}
.export-item:hover{background:rgba(255,136,0,.1);color:var(--orange-lt);}
.export-item .ei-icon{font-size:14px;}

/* Delete modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(5px);z-index:9000;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal-box{background:var(--card2);border:1.5px solid #aa2200;border-radius:10px;width:90%;max-width:400px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.8);}
.modal-title-bar{background:linear-gradient(135deg,#cc2200,#880000);padding:11px 16px;display:flex;justify-content:space-between;align-items:center;}
.modal-title-bar span{font-weight:700;font-size:13px;color:white;}
.mclose{background:rgba(0,0,0,.25);color:white;border:none;border-radius:4px;width:24px;height:24px;font-size:13px;cursor:pointer;font-weight:700;display:flex;align-items:center;justify-content:center;}
.mclose:hover{background:rgba(0,0,0,.5);}
.modal-body{padding:16px 18px;color:var(--text2);font-size:12px;}
.modal-foot{padding:10px 16px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;}

/* Print receipt */
@media print{body *{visibility:hidden;}#printArea,#printArea *{visibility:visible;}#printArea{position:absolute;left:0;top:0;width:100%;font-family:monospace;font-size:12px;}}
</style>
</head>
<body>

<!-- Include Admin Header -->
<?php include('../include/admin_header.php'); ?>

<!-- MAIN CONTENT -->
<div class="main" id="mainContent">

  <div class="page-hero">
    <div>
      <h2>🧺 Bread Inventory</h2>
      <p>Viewing: <strong style="color:var(--orange-lt);"><?= $periodLabel ?></strong> — <?= ucfirst($filterType) ?> view</p>
    </div>
    <div style="display:flex;gap:8px;">
      <div class="export-wrap">
        <button class="btn btn-dark" onclick="toggleExportMenu()">📤 Export ▾</button>
        <div class="export-menu" id="exportMenu">
          <button class="export-item" onclick="doExport('excel')"><span class="ei-icon">📊</span> Export Excel</button>
          <button class="export-item" onclick="doExport('pdf')"><span class="ei-icon">📄</span> Export PDF</button>
        </div>
      </div>
      <button class="btn btn-orange" onclick="window.print()">🖨 Print</button>
    </div>
  </div>

  <!-- Filter card -->
  <div class="filter-card">
    <form method="GET" id="filterForm">
      <div class="filter-row">
        <div class="f-group">
          <span class="f-label">Date</span>
          <input type="date" name="filter_date" class="f-input" value="<?= htmlspecialchars($filterDate) ?>">
        </div>
        <div class="f-group">
          <span class="f-label">Filter Type</span>
          <select name="filter_type" class="f-select">
            <option value="today"  <?= $filterType==='today' ?'selected':'' ?>>Daily</option>
            <option value="month"  <?= $filterType==='month' ?'selected':'' ?>>Monthly</option>
            <option value="year"   <?= $filterType==='year'  ?'selected':'' ?>>Yearly</option>
          </select>
        </div>
        <button type="submit" class="btn btn-orange" style="align-self:flex-end;">🔍 Filter</button>
        <a href="?filter_date=<?= date('Y-m-d') ?>&filter_type=today" class="btn btn-dark" style="align-self:flex-end;">📅 Today</a>
      </div>
    </form>
  </div>

  <!-- Stat tiles -->
  <div class="stat-grid">
    <div class="stat-tile">
      <div class="st-icon">🍞</div>
      <div class="st-label">Total Items</div>
      <div class="st-val orange"><?= number_format($totalQty) ?></div>
    </div>
    <div class="stat-tile">
      <div class="st-icon">💰</div>
      <div class="st-label">Total Value</div>
      <div class="st-val green">₱<?= number_format($totalVal,2) ?></div>
    </div>
    <div class="stat-tile">
      <div class="st-icon">📋</div>
      <div class="st-label">Bread Types Recorded</div>
      <div class="st-val blue"><?= count($records) ?></div>
    </div>
    <div class="stat-tile">
      <div class="st-icon">📊</div>
      <div class="st-label">Available Bread Types</div>
      <div class="st-val yellow"><?= count($breads) ?></div>
    </div>
    <div class="stat-tile">
      <div class="st-icon">📈</div>
      <div class="st-label">Avg Price / Piece</div>
      <div class="st-val orange">₱<?= number_format($avgPrice,2) ?></div>
    </div>
  </div>

  <!-- Form + Summary -->
  <div class="two-col">

    <!-- Record Form -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><div class="pt-dot"></div>➕ Record Bread Inventory</div>
      </div>
      <div class="panel-body">
        <form method="POST" id="inventoryForm">
          <input type="hidden" name="record_remain" value="1">
          <input type="hidden" name="edit_id" id="edit_id" value="">
          <div class="form-grid">
            <div class="fg">
              <label>Bread Type *</label>
              <select name="bread_id" id="bread_id" class="fg-select" required>
                <option value="">-- Select Bread --</option>
                <?php foreach($breads as $b): ?>
                <option value="<?= $b['id'] ?>" data-price="<?= $b['price'] ?>" data-name="<?= htmlspecialchars($b['name']) ?>">
                  <?= htmlspecialchars($b['name']) ?> (₱<?= number_format($b['price'],2) ?>)
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="fg">
              <label>Quantity *</label>
              <input type="number" name="remaining_quantity" id="remaining_quantity" class="fg-input" min="0" step="1" placeholder="0" required>
            </div>
            <div class="fg">
              <label>Price / Piece (₱) *</label>
              <div class="price-wrap">
                <input type="number" name="price" id="price" class="fg-input" min="0" step="0.01" placeholder="0.00" required>
                <button type="button" class="autofill-btn" id="autofillBtn" title="Reset to default price">↺</button>
              </div>
            </div>
            <div class="fg" style="align-self:flex-end;">
              <button type="submit" class="btn btn-orange" id="submitBtn" style="width:100%;padding:8px;">💾 Save</button>
            </div>
            <div class="fg" style="align-self:flex-end;">
              <button type="button" class="btn btn-dark" id="cancelEditBtn" onclick="resetForm()" style="display:none;width:100%;padding:8px;">✕ Cancel</button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Summary panel -->
    <div class="sum-panel">
      <div class="sp-period">📅 <?= strtoupper($filterType) ?> VIEW — <?= strtoupper($periodLabel) ?></div>
      <div class="sp-row">
        <span class="sp-label">Total Items</span>
        <span class="sp-val"><?= number_format($totalQty) ?></span>
      </div>
      <div class="sp-row">
        <span class="sp-label">Total Value</span>
        <span class="sp-val">₱<?= number_format($totalVal,2) ?></span>
      </div>
      <div class="sp-row">
        <span class="sp-label">Records</span>
        <span class="sp-val"><?= count($records) ?></span>
      </div>
    </div>

  </div>

  <!-- Inventory Table -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">
        <div class="pt-dot"></div>
        <?= ucfirst($filterType) ?> Bread Inventory
        <span style="background:var(--bg3);color:var(--text3);border-radius:10px;padding:1px 8px;font-size:10px;"><?= count($records) ?> records</span>
      </div>
      <input type="text" class="tbl-search" id="tblSearch" placeholder="🔍 Search…" oninput="searchTable()">
    </div>
    <div style="padding:0;">
      <div class="tbl-wrap">
        <table class="data-tbl" id="invTable">
          <thead>
            <tr>
              <th>Bread Name</th>
              <th style="text-align:right;">Qty</th>
              <th style="text-align:right;">Price</th>
              <th style="text-align:right;">Total Value</th>
              <th>Recorded At</th>
              <th>Recorded By</th>
              <th style="text-align:center;">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if(!empty($records)): foreach($records as $item): ?>
          <tr class="inv-row">
            <td class="td-name"><?= htmlspecialchars($item['name']) ?></td>
            <td style="text-align:right;color:var(--orange-lt);font-weight:700;"><?= number_format($item['quantity']) ?></td>
            <td style="text-align:right;color:#88bbff;">₱<?= number_format($item['price'],2) ?></td>
            <td style="text-align:right;" class="td-money">₱<?= number_format($item['total_value'],2) ?></td>
            <td class="td-date"><?= date('M j, Y g:i A',strtotime($item['recorded_at'])) ?></td>
            <td style="color:var(--text2);"><?= htmlspecialchars($item['recorded_by_name']??'System') ?></td>
            <td style="text-align:center;white-space:nowrap;">
              <button class="btn btn-blue btn-sm edit-btn"
                      data-id="<?= $item['id'] ?>"
                      data-bread-id="<?= $item['bread_id'] ?>"
                      data-quantity="<?= $item['quantity'] ?>"
                      data-price="<?= $item['price'] ?>">✏ Edit</button>
              <button class="btn btn-red btn-sm delete-btn"
                      data-id="<?= $item['id'] ?>"
                      data-name="<?= htmlspecialchars($item['name']) ?>">🗑</button>
            </td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="7" class="td-empty">
            🧺 No records found for <strong style="color:var(--orange-lt);"><?= $periodLabel ?></strong><br>
            <span style="font-size:11px;">Use the form above to add records.</span>
          </td></tr>
          <?php endif; ?>
          </tbody>
          <?php if(!empty($records)): ?>
          <tfoot>
            <tr>
              <td colspan="3" style="text-align:right;">TOTALS:</td>
              <td style="text-align:right;">₱<?= number_format($totalVal,2) ?></td>
              <td colspan="3"></td>
            </tr>
          </tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>
  </div>

</div><!-- end main -->

<!-- Include Admin Footer -->
<?php include('../include/admin_footer.php'); ?>

<!-- Hidden print area -->
<div id="printArea" style="display:none;">
  <div style="text-align:center;margin-bottom:12px;">
    <h3>Four ACC Angels Bakeshop</h3>
    <p>Bread Inventory Report — <?= $periodLabel ?></p>
    <p style="font-size:11px;">Printed: <?= date('F j, Y g:i A') ?></p>
  </div>
  <table border="1" cellpadding="5" style="width:100%;border-collapse:collapse;font-size:12px;">
    <tr style="background:#333;color:white;"><th>Bread Name</th><th>Qty</th><th>Price</th><th>Total</th><th>Recorded At</th><th>By</th></tr>
    <?php foreach($records as $r): ?>
    <tr><td><?= htmlspecialchars($r['name']) ?></td><td><?= $r['quantity'] ?></td>
        <td>₱<?= number_format($r['price'],2) ?></td><td>₱<?= number_format($r['total_value'],2) ?></td>
        <td><?= date('M j, Y g:i A',strtotime($r['recorded_at'])) ?></td>
        <td><?= htmlspecialchars($r['recorded_by_name']??'System') ?></td></tr>
    <?php endforeach; ?>
    <tr><td colspan="3" style="text-align:right;font-weight:bold;">TOTAL:</td>
        <td style="font-weight:bold;">₱<?= number_format($totalVal,2) ?></td><td colspan="2"></td></tr>
  </table>
</div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal-box">
    <div class="modal-title-bar">
      <span>🗑 Confirm Deletion</span>
      <button class="mclose" onclick="closeDeleteModal()">✕</button>
    </div>
    <div class="modal-body">
      <p>Are you sure you want to delete the record for <strong style="color:var(--orange-lt);" id="deleteName"></strong>?</p>
      <p style="color:#ff8888;margin-top:6px;font-size:11px;">⚠ This action cannot be undone.</p>
    </div>
    <div class="modal-foot">
      <button class="btn btn-dark" onclick="closeDeleteModal()">Cancel</button>
      <button class="btn btn-red" id="confirmDeleteBtn">🗑 Delete</button>
    </div>
  </div>
</div>

<script>
/* Clock - handled by admin_header.php */
/* Sidebar - handled by admin_header.php */
/* Connectivity - handled by admin_footer.php */

/* Swal session flash */
<?php if(isset($_SESSION['swal'])): ?>
document.addEventListener('DOMContentLoaded',function(){
  Swal.fire({icon:'<?= $_SESSION['swal']['type'] ?>',title:'<?= addslashes($_SESSION['swal']['title']) ?>',
    text:'<?= addslashes($_SESSION['swal']['text']) ?>',confirmButtonColor:'#ff8800',
    background:'#1e2330',color:'#e8eaf0',timer:3000,timerProgressBar:true});
});
<?php unset($_SESSION['swal']); endif; ?>

/* Auto-fill price on bread select */
document.getElementById('bread_id').addEventListener('change',function(){
  const opt=this.options[this.selectedIndex];
  if(opt.value) document.getElementById('price').value=opt.getAttribute('data-price');
  resetFormMeta();
});
document.getElementById('autofillBtn').addEventListener('click',function(){
  const opt=document.getElementById('bread_id').options[document.getElementById('bread_id').selectedIndex];
  if(opt.value){
    document.getElementById('price').value=opt.getAttribute('data-price');
    Swal.fire({icon:'success',title:'Price reset',toast:true,position:'top-end',showConfirmButton:false,timer:1400,background:'#1e2330',color:'#e8eaf0'});
  }
});

function resetFormMeta(){
  document.getElementById('edit_id').value='';
  const btn=document.getElementById('submitBtn');
  btn.textContent='💾 Save'; btn.className='btn btn-orange';
  document.getElementById('cancelEditBtn').style.display='none';
}
function resetForm(){
  document.getElementById('inventoryForm').reset();
  resetFormMeta();
}

/* Edit buttons */
document.querySelectorAll('.edit-btn').forEach(btn=>{
  btn.addEventListener('click',function(){
    document.getElementById('edit_id').value=this.dataset.id;
    document.getElementById('bread_id').value=this.dataset.breadId;
    document.getElementById('remaining_quantity').value=this.dataset.quantity;
    document.getElementById('price').value=this.dataset.price;
    const sbtn=document.getElementById('submitBtn');
    sbtn.textContent='💾 Update'; sbtn.className='btn btn-yellow';
    document.getElementById('cancelEditBtn').style.display='inline-flex';
    document.querySelector('form').scrollIntoView({behavior:'smooth'});
  });
});

/* Form submit confirmation */
document.getElementById('inventoryForm').addEventListener('submit',function(e){
  e.preventDefault();
  const sel=document.getElementById('bread_id');
  const breadName=sel.options[sel.selectedIndex].getAttribute('data-name');
  const qty=document.getElementById('remaining_quantity').value;
  const price=document.getElementById('price').value;
  const total=(qty*price).toFixed(2);
  const isEdit=document.getElementById('edit_id').value!=='';
  if(!breadName) return Swal.fire({icon:'error',title:'Select a bread type',confirmButtonColor:'#ff8800',background:'#1e2330',color:'#e8eaf0'});
  Swal.fire({
    title:isEdit?'Update Record?':'Save Record?',
    html:`<div style="text-align:left;font-size:12px;color:#e8eaf0;">
      <table style="width:100%;border-collapse:collapse;">
        <tr style="border-bottom:1px solid #2a3145;"><td style="padding:5px 8px;color:#9aa3bc;">Bread:</td><td style="padding:5px 8px;font-weight:700;color:#ffaa44;">${breadName}</td></tr>
        <tr style="border-bottom:1px solid #2a3145;"><td style="padding:5px 8px;color:#9aa3bc;">Quantity:</td><td style="padding:5px 8px;">${qty} pcs</td></tr>
        <tr style="border-bottom:1px solid #2a3145;"><td style="padding:5px 8px;color:#9aa3bc;">Price:</td><td style="padding:5px 8px;">₱${parseFloat(price).toFixed(2)}</td></tr>
        <tr><td style="padding:5px 8px;color:#9aa3bc;">Total Value:</td><td style="padding:5px 8px;font-weight:700;color:#4dff88;">₱${total}</td></tr>
      </table></div>`,
    icon:'question',showCancelButton:true,
    confirmButtonColor:'#ff8800',cancelButtonColor:'#555',
    confirmButtonText:isEdit?'✓ Update':'✓ Save',cancelButtonText:'Cancel',
    background:'#1e2330',color:'#e8eaf0'
  }).then(r=>{if(r.isConfirmed) this.submit();});
});

/* Table search */
function searchTable(){
  const q=document.getElementById('tblSearch').value.toUpperCase();
  document.querySelectorAll('#invTable tbody .inv-row').forEach(row=>{
    row.style.display=row.cells[0].textContent.toUpperCase().includes(q)?'':'none';
  });
}

/* Export menu */
function toggleExportMenu(){const m=document.getElementById('exportMenu');m.classList.toggle('show');}
document.addEventListener('click',function(e){
  if(!e.target.closest('.export-wrap')) document.getElementById('exportMenu').classList.remove('show');
});

function doExport(fmt){
  document.getElementById('exportMenu').classList.remove('show');
  const rows=Array.from(document.querySelectorAll('#invTable tbody .inv-row')).filter(r=>r.style.display!=='none');
  if(!rows.length) return Swal.fire({icon:'warning',title:'No data to export',background:'#1e2330',color:'#e8eaf0'});

  const items=rows.map(r=>({
    name:r.cells[0].textContent.trim(),
    qty:r.cells[1].textContent.trim(),
    price:r.cells[2].textContent.trim(),
    total:r.cells[3].textContent.trim(),
    recordedAt:r.cells[4].textContent.trim(),
    by:r.cells[5].textContent.trim()
  }));
  const period='<?= $periodLabel ?>';
  const date=new Date().toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'});

  if(fmt==='excel'){
    const html=`<!DOCTYPE html><html><head><meta charset="UTF-8">
    <style>body{font-family:Arial;}th{background:#ff8800;color:white;padding:8px;border:1px solid #ddd;}td{padding:6px;border:1px solid #ddd;}</style>
    </head><body>
    <div style="font-size:16pt;font-weight:bold;text-align:center;">FOUR ACC ANGELS BAKESHOP</div>
    <div style="font-size:13pt;text-align:center;margin-bottom:8px;">BREAD INVENTORY REPORT — ${period.toUpperCase()}</div>
    <div style="text-align:right;font-style:italic;margin-bottom:10px;">Generated: ${date}</div>
    <table><tr><th>Bread Name</th><th>Qty</th><th>Price</th><th>Total Value</th><th>Recorded At</th><th>Recorded By</th></tr>
    ${items.map(i=>`<tr><td>${i.name}</td><td>${i.qty}</td><td>${i.price}</td><td>${i.total}</td><td>${i.recordedAt}</td><td>${i.by}</td></tr>`).join('')}
    <tr style="font-weight:bold;background:#f5f5f5;"><td colspan="3" style="text-align:right;">TOTAL:</td><td>₱<?= number_format($totalVal,2) ?></td><td colspan="2"></td></tr>
    </table><div style="text-align:center;font-style:italic;margin-top:16px;">End of Report</div></body></html>`;
    const blob=new Blob([html],{type:'application/vnd.ms-excel'});
    const a=document.createElement('a'); a.href=URL.createObjectURL(blob);
    a.download='Bread_Inventory_<?= date('Ymd') ?>.xls'; a.click();
    Swal.fire({icon:'success',title:'Exported!',toast:true,position:'top-end',showConfirmButton:false,timer:1800,background:'#1e2330',color:'#e8eaf0'});
  } else {
    const {jsPDF}=window.jspdf;
    if(!jsPDF) return Swal.fire({icon:'error',title:'PDF library not loaded.',background:'#1e2330',color:'#e8eaf0'});
    const doc=new jsPDF('landscape','mm');
    doc.setFillColor(255,136,0); doc.rect(0,0,doc.internal.pageSize.getWidth(),20,'F');
    doc.setTextColor(255,255,255); doc.setFontSize(14); doc.setFont('helvetica','bold');
    doc.text('FOUR ACC ANGELS BAKESHOP — BREAD INVENTORY',148,13,{align:'center'});
    doc.setTextColor(80,80,80); doc.setFontSize(10); doc.setFont('helvetica','normal');
    doc.text(`Period: ${period}   |   Generated: ${date}`,20,28);
    doc.autoTable({
      startY:34,
      head:[['Bread Name','Qty','Price','Total Value','Recorded At','Recorded By']],
      body:items.map(i=>[i.name,i.qty,i.price,i.total,i.recordedAt,i.by]),
      headStyles:{fillColor:[255,136,0],textColor:[255,255,255],fontStyle:'bold'},
      bodyStyles:{textColor:[50,50,50],fontSize:9},
      alternateRowStyles:{fillColor:[245,245,245]},
      theme:'grid'
    });
    doc.setFont('helvetica','bold'); doc.setTextColor(255,136,0);
    doc.text(`TOTAL VALUE: ₱<?= number_format($totalVal,2) ?>`,260,doc.lastAutoTable.finalY+8,{align:'right'});
    doc.save('Bread_Inventory_<?= date('Ymd') ?>.pdf');
    Swal.fire({icon:'success',title:'PDF exported!',toast:true,position:'top-end',showConfirmButton:false,timer:1800,background:'#1e2330',color:'#e8eaf0'});
  }
}

/* Print override — show printArea */
window.addEventListener('beforeprint',()=>document.getElementById('printArea').style.display='block');
window.addEventListener('afterprint', ()=>document.getElementById('printArea').style.display='none');

/* Delete modal */
function closeDeleteModal(){document.getElementById('deleteModal').classList.remove('show');}
document.getElementById('deleteModal').addEventListener('click',function(e){if(e.target===this)closeDeleteModal();});

let deleteId=null;
document.querySelectorAll('.delete-btn').forEach(btn=>{
  btn.addEventListener('click',function(){
    deleteId=this.dataset.id;
    document.getElementById('deleteName').textContent=this.dataset.name;
    document.getElementById('deleteModal').classList.add('show');
  });
});
document.getElementById('confirmDeleteBtn').addEventListener('click',function(){
  fetch('delete_inventory.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'id='+deleteId})
    .then(r=>r.json())
    .then(data=>{
      if(data.status==='success'){
        closeDeleteModal();
        Swal.fire({icon:'success',title:'Deleted!',toast:true,position:'top-end',showConfirmButton:false,timer:1600,background:'#1e2330',color:'#e8eaf0',willClose:()=>location.reload()});
      } else Swal.fire({icon:'error',title:'Error',text:data.message||'Delete failed.',background:'#1e2330',color:'#e8eaf0'});
    })
    .catch(e=>Swal.fire({icon:'error',title:'Network Error',text:e.toString(),background:'#1e2330',color:'#e8eaf0'}));
});
</script>
</body>
</html>