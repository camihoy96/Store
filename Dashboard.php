<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/include/admin_header.php';

// ─── QUERIES ──────────────────────────────────────────────────────────────────
$inventorySummary = $conn->query("
    SELECT COUNT(*) as total_products,
           SUM(kg) as total_kg,
           SUM(pieces) as total_pieces,
           SUM(price * IF(measurement_type='kg', kg, pieces)) as total_inventory_value
    FROM products
")->fetch_assoc();

$lowStockThresholdPieces = 20;
$lowStockThresholdKg     = 20.0;

$lowStockQuery = $conn->query("
    SELECT id, name, measurement_type, pieces, kg
    FROM products
    WHERE (measurement_type='pieces' AND pieces>0 AND pieces<=$lowStockThresholdPieces)
       OR (measurement_type='kg'     AND kg>0     AND kg<=$lowStockThresholdKg)
    ORDER BY CASE WHEN measurement_type='kg' THEN kg ELSE pieces END ASC
    LIMIT 20
") or die($conn->error);

$lowStockItems = [];
while ($r = $lowStockQuery->fetch_assoc()) $lowStockItems[] = $r;
$lowStockCount = count($lowStockItems);

$recentTransactions = $conn->query("
    SELECT id, cashier_name, date, time, total
    FROM transactions ORDER BY date DESC, time DESC LIMIT 8
");

$todaySales = $conn->query("
    SELECT COALESCE(SUM(total),0) as today_total, COUNT(*) as today_count
    FROM transactions WHERE date = CURDATE()
")->fetch_assoc();

$yesterdaySales = $conn->query("
    SELECT COALESCE(SUM(total),0) as yesterday_total
    FROM transactions WHERE date = DATE_SUB(CURDATE(),INTERVAL 1 DAY)
")->fetch_assoc();

$dailySales = $conn->query("
    SELECT DATE(date) as day, SUM(total) as total
    FROM transactions WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(date) ORDER BY DATE(date)
");
$monthlySales = $conn->query("
    SELECT DATE_FORMAT(date,'%Y-%m') as month, SUM(total) as total
    FROM transactions WHERE date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(date,'%Y-%m') ORDER BY DATE_FORMAT(date,'%Y-%m')
");

$dailyLabels=[];$dailyData=[];
while($r=$dailySales->fetch_assoc()){ $dailyLabels[]=date('D M j',strtotime($r['day'])); $dailyData[]=(float)$r['total']; }
$monthlyLabels=[];$monthlyData=[];
while($r=$monthlySales->fetch_assoc()){ $monthlyLabels[]=date('M Y',strtotime($r['month'].'-01')); $monthlyData[]=(float)$r['total']; }

$expiringCount = $conn->query("
    SELECT COUNT(*) as count FROM products
    WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
")->fetch_assoc()['count'];

$expiringSoon = $conn->query("
    SELECT name, expiry_date,
           DATEDIFF(expiry_date, CURDATE()) as days_left,
           PERIOD_DIFF(DATE_FORMAT(expiry_date,'%Y%m'), DATE_FORMAT(CURDATE(),'%Y%m')) as months_left
    FROM products
    WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
    ORDER BY expiry_date ASC LIMIT 6
");

// Today vs yesterday trend
$todayTotal     = (float)$todaySales['today_total'];
$yesterdayTotal = (float)$yesterdaySales['yesterday_total'];
$trendPct       = $yesterdayTotal > 0 ? round((($todayTotal - $yesterdayTotal) / $yesterdayTotal) * 100, 1) : 0;
$trendUp        = $trendPct >= 0;

function formatTimeRemaining($days, $months) {
    if ($months >= 1) { $rd = $days % 30; return $months.' month'.($months>1?'s':'').($rd>0?' '.$rd.'d':''); }
    if ($days >= 7)   { $w = floor($days/7); $rd = $days%7; return $w.'w'.($rd>0?' '.$rd.'d':''); }
    return $days.' day'.($days!=1?'s':'');
}

// Donut data: low-stock vs ok
$totalProducts  = (int)$inventorySummary['total_products'];
$healthyProducts = max(0, $totalProducts - $lowStockCount);
?>

<style>
/* ═══════════════════════════════════ PAGE HERO */
.page-hero {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 20px; flex-wrap: wrap; gap: 10px;
}
.hero-left h2 { font-size: 20px; font-weight: 800; color: var(--text); margin-bottom: 2px; }
.hero-left p  { font-size: 12px; color: var(--text3); }
.hero-right { display: flex; align-items: center; gap: 8px; }
.hero-date {
  background: var(--card); border: 1px solid var(--border);
  border-radius: 20px; padding: 5px 14px;
  font-size: 11px; color: var(--text2); font-weight: 600;
}

/* ═══════════════════════════════════ KPI GRID */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 12px; margin-bottom: 20px;
}

.kpi-card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 12px; padding: 18px;
  position: relative; overflow: hidden;
  transition: transform 0.2s, border-color 0.2s, box-shadow 0.2s;
  cursor: default;
}
.kpi-card:hover { transform: translateY(-3px); box-shadow: 0 8px 30px rgba(0,0,0,0.4); }
.kpi-card.clickable { cursor: pointer; }

.kpi-card::before {
  content: ''; position: absolute;
  top: 0; left: 0; right: 0; height: 3px;
  border-radius: 12px 12px 0 0;
}
.kpi-card.accent-orange::before { background: linear-gradient(90deg, var(--orange), var(--orange-lt)); }
.kpi-card.accent-green::before  { background: linear-gradient(90deg, var(--green), #00ff88); }
.kpi-card.accent-blue::before   { background: linear-gradient(90deg, var(--blue), #88ccff); }
.kpi-card.accent-red::before    { background: linear-gradient(90deg, var(--red), #ff8888); }
.kpi-card.accent-yellow::before { background: linear-gradient(90deg, var(--yellow), #ffee66); }

.kpi-card:hover.accent-orange { border-color: var(--orange); box-shadow: 0 8px 30px rgba(255,136,0,0.15); }
.kpi-card:hover.accent-green  { border-color: var(--green);  box-shadow: 0 8px 30px rgba(0,200,83,0.15); }
.kpi-card:hover.accent-blue   { border-color: var(--blue);   box-shadow: 0 8px 30px rgba(68,136,255,0.15); }
.kpi-card:hover.accent-red    { border-color: var(--red);    box-shadow: 0 8px 30px rgba(255,68,68,0.15); }
.kpi-card:hover.accent-yellow { border-color: var(--yellow); box-shadow: 0 8px 30px rgba(255,204,0,0.15); }

.kpi-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 10px; }
.kpi-icon {
  width: 40px; height: 40px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center; font-size: 18px;
}
.kpi-icon.orange { background: rgba(255,136,0,0.15); }
.kpi-icon.green  { background: rgba(0,200,83,0.15); }
.kpi-icon.blue   { background: rgba(68,136,255,0.15); }
.kpi-icon.red    { background: rgba(255,68,68,0.15); }
.kpi-icon.yellow { background: rgba(255,204,0,0.15); }

.kpi-trend {
  font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 10px;
  display: flex; align-items: center; gap: 2px;
}
.kpi-trend.up   { background: rgba(0,200,83,0.15); color: var(--green); }
.kpi-trend.down { background: rgba(255,68,68,0.15); color: var(--red); }
.kpi-trend.flat { background: rgba(154,163,188,0.15); color: var(--text3); }

.kpi-val { font-size: 26px; font-weight: 900; color: var(--text); line-height: 1; margin-bottom: 4px; }
.kpi-val.orange { color: var(--orange-lt); }
.kpi-val.green  { color: #4dff88; }
.kpi-val.blue   { color: #88bbff; }
.kpi-val.red    { color: #ff8888; }
.kpi-val.yellow { color: var(--yellow); }

.kpi-label { font-size: 10px; color: var(--text3); font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; }
.kpi-sub   { font-size: 11px; color: var(--text2); margin-top: 2px; }

/* ═══════════════════════════════════ GRID LAYOUTS */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
.grid-3 { display: grid; grid-template-columns: 2fr 1fr; gap: 14px; margin-bottom: 14px; }
.grid-3b { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-bottom: 14px; }
@media(max-width:900px){ .grid-2,.grid-3,.grid-3b{ grid-template-columns:1fr; } }

/* ═══════════════════════════════════ PANEL CARD */
.panel {
  background: var(--card); border: 1px solid var(--border);
  border-radius: 12px; overflow: hidden;
}
.panel-header {
  padding: 14px 18px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  background: linear-gradient(90deg, var(--card2) 0%, var(--card) 100%);
}
.panel-title {
  font-size: 12px; font-weight: 700; color: var(--text); letter-spacing: 0.3px;
  display: flex; align-items: center; gap: 7px;
}
.panel-title .pt-dot {
  width: 7px; height: 7px; border-radius: 50%;
}
.panel-title .pt-dot.orange { background: var(--orange); box-shadow: 0 0 6px var(--orange); }
.panel-title .pt-dot.green  { background: var(--green);  box-shadow: 0 0 6px var(--green); }
.panel-title .pt-dot.red    { background: var(--red);    box-shadow: 0 0 6px var(--red); }
.panel-title .pt-dot.yellow { background: var(--yellow); box-shadow: 0 0 6px var(--yellow); }
.panel-title .pt-dot.blue   { background: var(--blue);   box-shadow: 0 0 6px var(--blue); }

.panel-body { padding: 16px 18px; }
.panel-body.no-pad { padding: 0; }

/* ═══════════════════════════════════ CHART */
.chart-box { height: 220px; position: relative; }

/* ═══════════════════════════════════ TABLE */
.data-table-wrap { overflow-x: auto; }
.data-table-wrap::-webkit-scrollbar { height: 3px; }
.data-table-wrap::-webkit-scrollbar-thumb { background: var(--border2); }

.data-table { width: 100%; border-collapse: collapse; }
.data-table thead tr { background: linear-gradient(90deg, var(--orange) 0%, var(--orange-dk) 100%); }
.data-table thead th {
  padding: 9px 14px; font-size: 10px; font-weight: 700;
  color: rgba(255,255,255,0.9); text-transform: uppercase;
  letter-spacing: 0.8px; white-space: nowrap;
  border-right: 1px solid rgba(255,255,255,0.1);
}
.data-table thead th:last-child { border-right: none; }
.data-table tbody tr { border-bottom: 1px solid var(--border); transition: background 0.1s; }
.data-table tbody tr:hover { background: rgba(255,255,255,0.03); }
.data-table tbody tr:last-child { border-bottom: none; }
.data-table tbody td { padding: 9px 14px; font-size: 11px; color: var(--text2); vertical-align: middle; }
.data-table tbody td.empty { text-align: center; color: var(--text3); padding: 24px; }

.tc-id    { background: rgba(255,136,0,0.1); color: var(--orange-lt); border-radius: 4px; padding: 2px 7px; font-size: 10px; font-weight: 700; font-family: monospace; }
.tc-money { color: #4dff88; font-weight: 700; }
.tc-date  { color: var(--text3); font-size: 10px; }
.tc-name  { color: var(--text); font-weight: 500; }

/* ═══════════════════════════════════ BADGE */
.badge {
  display: inline-flex; align-items: center; gap: 3px;
  padding: 2px 8px; border-radius: 20px;
  font-size: 10px; font-weight: 700; letter-spacing: 0.3px;
}
.badge-danger   { background: rgba(255,68,68,0.15);   color: #ff8888; border: 1px solid rgba(255,68,68,0.2); }
.badge-warning  { background: rgba(255,204,0,0.15);   color: var(--yellow); border: 1px solid rgba(255,204,0,0.2); }
.badge-success  { background: rgba(0,200,83,0.15);    color: #66dd88; border: 1px solid rgba(0,200,83,0.2); }
.badge-info     { background: rgba(68,136,255,0.15);  color: #88bbff; border: 1px solid rgba(68,136,255,0.2); }
.badge-orange   { background: rgba(255,136,0,0.15);   color: var(--orange-lt); border: 1px solid rgba(255,136,0,0.2); }

/* ═══════════════════════════════════ PROGRESS BAR */
.prog-wrap { margin-top: 8px; }
.prog-bar { height: 5px; background: var(--border2); border-radius: 3px; overflow: hidden; }
.prog-fill { height: 100%; border-radius: 3px; transition: width 1s ease; }
.prog-fill.orange { background: linear-gradient(90deg, var(--orange), var(--orange-lt)); }
.prog-fill.red    { background: linear-gradient(90deg, var(--red), #ff8888); }
.prog-fill.green  { background: linear-gradient(90deg, var(--green), #66dd88); }

/* ═══════════════════════════════════ STOCK ITEM ROW */
.stock-item {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 0; border-bottom: 1px solid var(--border);
}
.stock-item:last-child { border-bottom: none; }
.stock-item .si-name { flex: 1; font-size: 12px; color: var(--text); font-weight: 500; }
.stock-item .si-qty  { font-size: 11px; font-weight: 700; min-width: 55px; text-align: right; }

/* ═══════════════════════════════════ EXPIRY ROW */
.expiry-item {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 0; border-bottom: 1px solid var(--border);
}
.expiry-item:last-child { border-bottom: none; }
.expiry-item .ei-left { flex: 1; }
.expiry-item .ei-name { font-size: 12px; color: var(--text); font-weight: 500; }
.expiry-item .ei-date { font-size: 10px; color: var(--text3); margin-top: 1px; }
.expiry-item .ei-right { text-align: right; }
.expiry-item .ei-time { font-size: 11px; font-weight: 700; }

/* ═══════════════════════════════════ INVENTORY MINI-STATS */
.inv-stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.inv-stat {
  background: var(--bg2); border: 1px solid var(--border); border-radius: 8px; padding: 12px;
}
.inv-stat .is-label { font-size: 9px; color: var(--text3); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
.inv-stat .is-val   { font-size: 16px; font-weight: 800; }

/* ═══════════════════════════════════ BTN */
.btn {
  padding: 5px 13px; border: none; border-radius: 6px;
  font-size: 11px; font-weight: 600; cursor: pointer;
  display: inline-flex; align-items: center; gap: 4px;
  text-decoration: none; transition: all 0.15s; white-space: nowrap; letter-spacing: 0.2px;
}
.btn:hover { filter: brightness(1.1); transform: translateY(-1px); }
.btn-orange { background: linear-gradient(135deg, var(--orange), var(--orange-dk)); color: white; }
.btn-red    { background: linear-gradient(135deg, var(--red), #aa1111); color: white; }
.btn-dark   { background: var(--bg3); border: 1px solid var(--border); color: var(--text2); }
.btn-dark:hover { background: var(--border2); color: var(--text); }

/* ═══════════════════════════════════ ALERT MODAL */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0, 0, 0, 0.47);
  z-index: 9000; align-items: center; justify-content: center;
}
.modal-overlay.show { display: flex; }

.modal-box {
  background: var(--card2); border: 1px solid #fffbfa;
 width: 92%; max-width: 680px; max-height: 88vh;
  display: flex; flex-direction: column; overflow: hidden;
  box-shadow: 0 0 60px rgba(255,68,68,0.25), 0 30px 80px rgba(0,0,0,0.8);
  animation: modal-in 0.25s ease, pulse-glow 3s ease-in-out infinite;
  cursor: grab; position: relative;
}
.modal-box:active { cursor: grabbing; }
@keyframes modal-in { from { opacity:0; transform:scale(0.95) translateY(-10px); } to { opacity:1; transform:none; } }
@keyframes pulse-glow {
  0%,100% { box-shadow: 0 0 40px rgba(255,68,68,0.2), 0 30px 80px rgba(0,0,0,0.8); }
  50%      { box-shadow: 0 0 70px rgba(255,68,68,0.4), 0 30px 80px rgba(0,0,0,0.8); }
}

.modal-hdr {
  border-bottom: 1px solid #aa2200;
  padding: 14px 20px; display: flex; align-items: center; gap: 12px; flex-shrink: 0;
  cursor: grab;
}
.modal-hdr:active { cursor: grabbing; }
.modal-hdr .mh-icon { font-size: 22px; }
.modal-hdr .mh-title { font-size: 14px; font-weight: 800; color: #ff8888; }
.modal-hdr .mh-sub   { font-size: 11px; color: #cc6666; }
.modal-hdr .mh-close {
  margin-left: auto; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);
  color: #ff8888; border-radius: 6px; width: 28px; height: 28px; font-size: 13px;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  transition: all 0.15s; font-weight: 700;
}
.modal-hdr .mh-close:hover { background: var(--red); color: white; }

.modal-body { padding: 18px 20px; overflow-y: auto; flex: 1; }
.modal-foot { padding: 12px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; flex-shrink: 0; }

.alert-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media(max-width:560px){ .alert-cols { grid-template-columns: 1fr; } }

.alert-section { background: var(--bg2); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.alert-section-hdr {
  padding: 9px 14px; display: flex; justify-content: space-between; align-items: center;
  font-size: 11px; font-weight: 700; border-bottom: 1px solid var(--border);
}
.alert-section-hdr.red    { background: rgba(255,68,68,0.1); color: #ff8888; }
.alert-section-hdr.yellow { background: rgba(255,204,0,0.1); color: var(--yellow); }
.alert-list { list-style: none; }
.alert-list li {
  display: flex; justify-content: space-between; align-items: center;
  padding: 8px 14px; border-bottom: 1px solid var(--border);
  font-size: 11px; transition: background 0.1s;
}
.alert-list li:last-child { border-bottom: none; }
.alert-list li:hover { background: rgba(255,255,255,0.02); }
.alert-list li .al-name { color: var(--text); font-weight: 500; }
</style>

<!-- ═══════════════════════════════════ MAIN -->
<div class="main" id="mainContent">

  <!-- Page Hero -->
  <div class="page-hero">
    <div class="hero-left">
      <h2>📊 Inventory Dashboard</h2>
      <p>Welcome back, <strong style="color:var(--orange-lt);"><?= htmlspecialchars($_SESSION['username']??'Admin') ?></strong> — here's what's happening today.</p>
    </div>
    <div class="hero-right">
      <div class="hero-date"><?= date('l, F j, Y') ?></div>
      <?php if($lowStockCount>0||$expiringCount>0): ?>
      <button class="btn btn-red" onclick="openAlertModal()">🚨 <?= $lowStockCount+$expiringCount ?> Alerts</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- KPI Cards -->
  <div class="kpi-grid">

    <div class="kpi-card accent-orange">
      <div class="kpi-top">
        <div class="kpi-icon orange">📦</div>
        <?php
          $todayTxCount = (int)$todaySales['today_count'];
        ?>
        <div class="kpi-trend flat">↔ <?= $todayTxCount ?> txn today</div>
      </div>
      <div class="kpi-val orange"><?= $inventorySummary['total_products'] ?></div>
      <div class="kpi-label">Total Products</div>
      <div class="kpi-sub"><?= number_format($inventorySummary['total_kg'],1) ?> kg + <?= number_format($inventorySummary['total_pieces'],0) ?> pcs in stock</div>
    </div>

    <div class="kpi-card accent-green">
      <div class="kpi-top">
        <div class="kpi-icon green">💰</div>
        <div class="kpi-trend <?= $trendUp?'up':'down' ?>"><?= $trendUp?'▲':'▼' ?> <?= abs($trendPct) ?>% vs yesterday</div>
      </div>
      <div class="kpi-val green">₱<?= number_format($todayTotal,2) ?></div>
      <div class="kpi-label">Today's Sales</div>
      <div class="kpi-sub">Total stock value ₱<?= number_format($inventorySummary['total_inventory_value'],2) ?></div>
    </div>

    <div class="kpi-card accent-blue">
      <div class="kpi-top">
        <div class="kpi-icon blue">📈</div>
        <div class="kpi-trend flat">Last 12 months</div>
      </div>
      <div class="kpi-val blue">₱<?= number_format(array_sum($monthlyData),2) ?></div>
      <div class="kpi-label">Total Sales (Annual)</div>
      <div class="kpi-sub"><?= count($monthlyData) ?> months of data</div>
    </div>

    <?php if($lowStockCount>0): ?>
    <div class="kpi-card accent-red clickable" onclick="openAlertModal()">
      <div class="kpi-top">
        <div class="kpi-icon red">⚠️</div>
        <div class="kpi-trend down">Needs restock</div>
      </div>
      <div class="kpi-val red"><?= $lowStockCount ?></div>
      <div class="kpi-label">Low Stock Items</div>
      <div class="kpi-sub">Click to view details</div>
    </div>
    <?php else: ?>
    <div class="kpi-card accent-green">
      <div class="kpi-top">
        <div class="kpi-icon green">✅</div>
        <div class="kpi-trend up">Healthy</div>
      </div>
      <div class="kpi-val green">OK</div>
      <div class="kpi-label">Stock Levels</div>
      <div class="kpi-sub">All products above threshold</div>
    </div>
    <?php endif; ?>

    <?php if($expiringCount>0): ?>
    <div class="kpi-card accent-yellow clickable" onclick="openAlertModal()">
      <div class="kpi-top">
        <div class="kpi-icon yellow">📅</div>
        <div class="kpi-trend down">Within 6 months</div>
      </div>
      <div class="kpi-val yellow"><?= $expiringCount ?></div>
      <div class="kpi-label">Expiring Products</div>
      <div class="kpi-sub">Click to review</div>
    </div>
    <?php endif; ?>

  </div><!-- end kpi-grid -->

  <!-- Charts Row -->
  <div class="grid-2" style="margin-bottom:14px;">
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">
          <div class="pt-dot orange"></div>
          Weekly Sales Performance
        </div>
        <span style="font-size:10px;color:var(--text3);">Last 7 days</span>
      </div>
      <div class="panel-body">
        <div class="chart-box"><canvas id="dailySalesChart"></canvas></div>
      </div>
    </div>
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">
          <div class="pt-dot green"></div>
          Monthly Revenue Trend
        </div>
        <span style="font-size:10px;color:var(--text3);">Last 12 months</span>
      </div>
      <div class="panel-body">
        <div class="chart-box"><canvas id="monthlySalesChart"></canvas></div>
      </div>
    </div>
  </div>

  <!-- Bottom Row: Transactions + Low Stock + Expiry + Inventory -->
  <div class="grid-2">

    <!-- Recent Transactions -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">
          <div class="pt-dot orange"></div>
          Recent Transactions
        </div>
        <a href="record/record.php" class="btn btn-orange">View All →</a>
      </div>
      <div class="panel-body no-pad">
        <div class="data-table-wrap">
          <table class="data-table">
            <thead><tr><th>#</th><th>Cashier</th><th>Date & Time</th><th>Amount</th></tr></thead>
            <tbody>
              <?php if($recentTransactions->num_rows>0):
                $recentTransactions->data_seek(0);
                while($t=$recentTransactions->fetch_assoc()): ?>
              <tr>
                <td><span class="tc-id">#<?= $t['id'] ?></span></td>
                <td><span class="tc-name"><?= htmlspecialchars($t['cashier_name']) ?></span></td>
                <td><span class="tc-date"><?= date('M j, g:i A',strtotime($t['date'].' '.$t['time'])) ?></span></td>
                <td><span class="tc-money">₱<?= number_format($t['total'],2) ?></span></td>
              </tr>
              <?php endwhile; else: ?>
              <tr><td colspan="4" class="empty">No transactions found</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Right column: Low Stock + Expiry stacked -->
    <div style="display:flex;flex-direction:column;gap:14px;">

      <!-- Low Stock -->
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title">
            <div class="pt-dot red"></div>
            Low Stock Items
            <?php if($lowStockCount>0): ?>
              <span class="badge badge-danger" style="margin-left:4px;"><?= $lowStockCount ?></span>
            <?php endif; ?>
          </div>
          <a href="product/product.php?filter=low" class="btn btn-red">View All</a>
        </div>
        <div class="panel-body" style="padding:12px 18px;">
          <?php if($lowStockCount>0):
            $shown = array_slice($lowStockItems, 0, 5);
            foreach($shown as $item):
              $mt   = $item['measurement_type']??'pieces';
              $qty  = ($mt==='kg')?(float)$item['kg']:(int)$item['pieces'];
              $unit = ($mt==='kg')?'kg':'pcs';
              $crit = ($mt==='kg')?($qty<5.0):($qty<5);
              $pct  = $crit ? 15 : min(60, ($qty / ($mt==='kg'?$lowStockThresholdKg:$lowStockThresholdPieces)) * 100);
          ?>
          <div class="stock-item">
            <span class="si-name"><?= htmlspecialchars($item['name']) ?></span>
            <span class="badge <?= $crit?'badge-danger':'badge-warning' ?>"><?= $crit?'Critical':'Low' ?></span>
            <span class="si-qty" style="color:<?= $crit?'#ff8888':'#ffcc66' ?>">
              <?= $mt==='kg'?number_format($qty,2):$qty ?> <?= $unit ?>
            </span>
          </div>
          <div class="prog-wrap" style="margin-top:-2px;margin-bottom:4px;">
            <div class="prog-bar"><div class="prog-fill <?= $crit?'red':'orange' ?>" style="width:<?= $pct ?>%"></div></div>
          </div>
          <?php endforeach; else: ?>
          <div style="text-align:center;color:var(--text3);padding:16px 0;font-size:12px;">✅ All stock levels are healthy</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Expiring Soon -->
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title">
            <div class="pt-dot yellow"></div>
            Expiring Products
            <span class="badge badge-warning" style="margin-left:4px;"><?= $expiringCount ?></span>
          </div>
          <a href="product/product.php?filter=expiry" class="btn btn-dark">View All</a>
        </div>
        <div class="panel-body" style="padding:12px 18px;">
          <?php
          $expiringSoon->data_seek(0);
          $hasExp=false;
          while($item=$expiringSoon->fetch_assoc()):
            $hasExp=true;
            $dl=$item['days_left']; $ml=$item['months_left'];
            $ft=formatTimeRemaining($dl,$ml);
            if($dl<=7)       { $bc='badge-danger';  $tc='#ff8888'; }
            elseif($dl<=30)  { $bc='badge-warning'; $tc='#ffcc66'; }
            elseif($ml<=3)   { $bc='badge-info';    $tc='#88bbff'; }
            else             { $bc='badge-success'; $tc='#66dd88'; }
          ?>
          <div class="expiry-item">
            <div class="ei-left">
              <div class="ei-name"><?= htmlspecialchars($item['name']) ?></div>
              <div class="ei-date"><?= date('M j, Y',strtotime($item['expiry_date'])) ?></div>
            </div>
            <div class="ei-right">
              <div class="ei-time" style="color:<?= $tc ?>"><?= $ft ?></div>
              <span class="badge <?= $bc ?>" style="margin-top:3px;"><?= $dl<=7?'Critical':($dl<=30?'Warning':($ml<=3?'Notice':'Monitor')) ?></span>
            </div>
          </div>
          <?php endwhile;
          if(!$hasExp): ?>
          <div style="text-align:center;color:var(--text3);padding:16px 0;font-size:12px;">✅ No products expiring within 6 months</div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div><!-- end grid-2 -->

  <!-- Inventory Summary + Donut -->
  <div class="grid-3b" style="margin-bottom:0;">
    <div class="panel" style="grid-column:span 2;">
      <div class="panel-header">
        <div class="panel-title">
          <div class="pt-dot green"></div>
          Inventory Summary
        </div>
      </div>
      <div class="panel-body">
        <div class="inv-stat-grid">
          <div class="inv-stat">
            <div class="is-label">Total KG Stock</div>
            <div class="is-val" style="color:var(--orange-lt);"><?= number_format($inventorySummary['total_kg'],2) ?> kg</div>
          </div>
          <div class="inv-stat">
            <div class="is-label">Total Pieces Stock</div>
            <div class="is-val" style="color:var(--orange-lt);"><?= number_format($inventorySummary['total_pieces'],0) ?> pcs</div>
          </div>
          <div class="inv-stat">
            <div class="is-label">Total Stock Value</div>
            <div class="is-val" style="color:#4dff88;">₱<?= number_format($inventorySummary['total_inventory_value'],2) ?></div>
          </div>
          <div class="inv-stat">
            <div class="is-label">Products Registered</div>
            <div class="is-val" style="color:#88bbff;"><?= $inventorySummary['total_products'] ?> items</div>
          </div>
        </div>
      </div>
    </div>
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">
          <div class="pt-dot orange"></div>
          Stock Health
        </div>
      </div>
      <div class="panel-body" style="display:flex;flex-direction:column;align-items:center;">
        <div style="height:150px;width:150px;position:relative;">
          <canvas id="stockDonut"></canvas>
          <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;">
            <div style="font-size:20px;font-weight:900;color:var(--text);"><?= $totalProducts ?></div>
            <div style="font-size:9px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;">Products</div>
          </div>
        </div>
        <div style="display:flex;gap:14px;margin-top:12px;font-size:10px;color:var(--text3);">
          <span><span style="color:#4dff88;">●</span> Healthy (<?= $healthyProducts ?>)</span>
          <span><span style="color:#ff8888;">●</span> Low Stock (<?= $lowStockCount ?>)</span>
        </div>
      </div>
    </div>
  </div>

</div><!-- end main -->

<!-- ALERT MODAL -->
<div class="modal-overlay" id="alertModal">
  <div class="modal-box" id="alertModalBox">
    <div class="modal-hdr" id="alertModalHandle">
      <div class="mh-icon">🚨</div>
      <div>
        <div class="mh-title">Inventory Requires Attention</div>
        <div class="mh-sub"><?= $lowStockCount+$expiringCount ?> item(s) need your review before proceeding</div>
      </div>
      <button class="mh-close" onclick="closeAlertModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="alert-cols">
        <div class="alert-section">
          <div class="alert-section-hdr red">
            <span>⚠ Low Stock (<?= $lowStockCount ?>)</span>
            <span class="badge badge-danger"><?= $lowStockCount ?></span>
          </div>
          <ul class="alert-list">
            <?php if($lowStockCount>0): foreach($lowStockItems as $item):
              $mt   = $item['measurement_type']??'pieces';
              $qty  = ($mt==='kg')?(float)$item['kg']:(int)$item['pieces'];
              $unit = ($mt==='kg')?'kg':'pcs';
              $crit = ($mt==='kg')?($qty<5.0):($qty<5);
            ?>
            <li>
              <span class="al-name"><?= htmlspecialchars($item['name']) ?></span>
              <span class="badge <?= $crit?'badge-danger':'badge-warning' ?>">
                <?= $mt==='kg'?number_format($qty,2):$qty ?> <?= $unit ?>
              </span>
            </li>
            <?php endforeach; else: ?>
            <li><span style="color:var(--text3);">No low stock items</span></li>
            <?php endif; ?>
          </ul>
        </div>
        <div class="alert-section">
          <div class="alert-section-hdr yellow">
            <span>📅 Expiring Soon (<?= $expiringCount ?>)</span>
            <span class="badge badge-warning"><?= $expiringCount ?></span>
          </div>
          <ul class="alert-list">
            <?php if($expiringCount>0):
              $expiringSoon->data_seek(0);
              while($item=$expiringSoon->fetch_assoc()):
                $dl=$item['days_left']; $ml=$item['months_left'];
                $ft=formatTimeRemaining($dl,$ml);
                $col=$dl<=7?'#ff8888':($dl<=30?'#ffcc66':'#888');
            ?>
            <li>
              <span class="al-name"><?= htmlspecialchars($item['name']) ?></span>
              <small style="color:<?= $col ?>;font-weight:700;"><?= $ft ?></small>
            </li>
            <?php endwhile; else: ?>
            <li><span style="color:var(--text3);">No items expiring soon</span></li>
            <?php endif; ?>
          </ul>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-red" onclick="closeAlertModal()">✓ Acknowledged</button>
    </div>
  </div>
</div>

<script>
/* Alert modal */
function openAlertModal(){ document.getElementById('alertModal').classList.add('show'); }
function closeAlertModal(){ 
  document.getElementById('alertModal').classList.remove('show'); 
  const modalBox = document.getElementById('alertModalBox');
  if (modalBox) {
    modalBox.style.position = '';
    modalBox.style.left = '';
    modalBox.style.top = '';
    modalBox.style.margin = '';
  }
}
document.getElementById('alertModal').addEventListener('click',function(e){ if(e.target===this) closeAlertModal(); });

<?php if($lowStockCount>0||$expiringCount>0): ?>
window.addEventListener('DOMContentLoaded',()=>{ setTimeout(openAlertModal,600); });
<?php endif; ?>

// Drag functionality for alert modal
(function() {
  const modalBox = document.getElementById('alertModalBox');
  const modalHandle = document.getElementById('alertModalHandle');
  
  if (!modalBox || !modalHandle) return;
  
  let isDragging = false;
  let startX, startY, initialX, initialY;
  
  modalHandle.addEventListener('mousedown', function(e) {
    if (e.target.closest('button')) return;
    isDragging = true;
    const rect = modalBox.getBoundingClientRect();
    startX = e.clientX; startY = e.clientY;
    initialX = rect.left; initialY = rect.top;
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

/* Chart global defaults */
Chart.defaults.color='#5a6380';
Chart.defaults.borderColor='rgba(255,255,255,0.05)';
Chart.defaults.font.family="'Inter','Segoe UI',sans-serif";

const tooltipDefaults = {
  backgroundColor:'#1e2330',
  borderColor:'#2a3145',
  borderWidth:1,
  titleColor:'#e8eaf0',
  bodyColor:'#9aa3bc',
  padding:10,
  cornerRadius:8,
  callbacks:{ label: c=>' ₱'+parseFloat(c.raw).toLocaleString('en-PH',{minimumFractionDigits:2}) }
};

/* Weekly Sales Bar Chart */
new Chart(document.getElementById('dailySalesChart'),{
  type:'bar',
  data:{
    labels:<?= json_encode($dailyLabels) ?>,
    datasets:[{
      label:'Daily Sales',
      data:<?= json_encode($dailyData) ?>,
      backgroundColor:ctx=>{
        const g=ctx.chart.ctx.createLinearGradient(0,0,0,220);
        g.addColorStop(0,'rgba(255,136,0,0.8)');
        g.addColorStop(1,'rgba(255,68,0,0.2)');
        return g;
      },
      borderColor:'rgba(255,136,0,1)',
      borderWidth:0,
      borderRadius:6,
      borderSkipped:false,
      hoverBackgroundColor:'rgba(255,170,50,0.9)'
    }]
  },
  options:{
    maintainAspectRatio:false,
    plugins:{ legend:{display:false}, tooltip:tooltipDefaults },
    scales:{
      x:{ grid:{display:false}, ticks:{color:'#5a6380',font:{size:10}} },
      y:{ beginAtZero:true, grid:{color:'rgba(255,255,255,0.04)'}, ticks:{color:'#5a6380',font:{size:10},callback:v=>v>=1000?'₱'+(v/1000).toFixed(0)+'k':'₱'+v} }
    }
  }
});

/* Monthly Sales Line Chart */
new Chart(document.getElementById('monthlySalesChart'),{
  type:'line',
  data:{
    labels:<?= json_encode($monthlyLabels) ?>,
    datasets:[{
      label:'Monthly Sales',
      data:<?= json_encode($monthlyData) ?>,
      fill:true,
      backgroundColor:ctx=>{
        const g=ctx.chart.ctx.createLinearGradient(0,0,0,220);
        g.addColorStop(0,'rgba(0,200,83,0.25)');
        g.addColorStop(1,'rgba(0,200,83,0.02)');
        return g;
      },
      borderColor:'rgba(0,200,83,1)',
      pointBackgroundColor:'rgba(0,200,83,1)',
      pointBorderColor:'#1e2330',
      pointBorderWidth:2,
      pointRadius:4,
      pointHoverRadius:6,
      tension:0.4
    }]
  },
  options:{
    maintainAspectRatio:false,
    plugins:{ legend:{display:false}, tooltip:tooltipDefaults },
    scales:{
      x:{ grid:{display:false}, ticks:{color:'#5a6380',font:{size:10}} },
      y:{ beginAtZero:true, grid:{color:'rgba(255,255,255,0.04)'}, ticks:{color:'#5a6380',font:{size:10},callback:v=>v>=1000?'₱'+(v/1000).toFixed(0)+'k':'₱'+v} }
    }
  }
});

/* Stock Health Donut */
new Chart(document.getElementById('stockDonut'),{
  type:'doughnut',
  data:{
    labels:['Healthy','Low Stock'],
    datasets:[{
      data:[<?= $healthyProducts ?>,<?= $lowStockCount ?>],
      backgroundColor:['rgba(0,200,83,0.85)','rgba(255,68,68,0.85)'],
      borderColor:['#1e2330','#1e2330'],
      borderWidth:3,
      hoverOffset:4
    }]
  },
  options:{
    maintainAspectRatio:false,
    cutout:'72%',
    plugins:{
      legend:{display:false},
      tooltip:{
        backgroundColor:'#1e2330', borderColor:'#2a3145', borderWidth:1,
        titleColor:'#e8eaf0', bodyColor:'#9aa3bc', padding:8, cornerRadius:8
      }
    }
  }
});
</script>

<?php require_once __DIR__ . '/include/admin_footer.php'; ?>