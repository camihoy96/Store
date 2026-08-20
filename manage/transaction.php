<?php
require_once __DIR__ . '/../dbconn.php';
// Check if payment columns exist ────────────────────────────────────
$hasPaymentMethod = false;
$hasReferenceNo   = false;
$colCheck = $conn->query("SHOW COLUMNS FROM transactions LIKE 'payment_method'");
if ($colCheck && $colCheck->num_rows > 0) $hasPaymentMethod = true;
$colCheck2 = $conn->query("SHOW COLUMNS FROM transactions LIKE 'reference_no'");
if ($colCheck2 && $colCheck2->num_rows > 0) $hasReferenceNo = true;

// Get business name for exports
$systemSettings = [];
$settRes = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($settRes) while ($sRow = $settRes->fetch_assoc()) $systemSettings[$sRow['setting_key']] = $sRow['setting_value'];
$businessName = $systemSettings['business_name'] ?? 'Angel\'s Bakeshop';

/* ─── EXCEL EXPORT ─────────────────────────────────────────────────────── */
if (isset($_POST['export'])) {
    require_once __DIR__ . '/../dbconn.php';
    header('Content-Type: application/vnd.ms-excel');
    $filename = 'todays_transactions_' . date('Y-m-d') . ($_POST['export'] !== 'all' ? '_filtered' : '_all') . '.xls';
    header('Content-Disposition: attachment;filename=' . $filename);
    ob_start();
    
    $filterInfo = '';
    if ($_POST['export'] !== 'all' && !empty($_POST['trans_id'])) {
        $filterInfo = ' (Filtered: Trans #' . htmlspecialchars($_POST['trans_id']) . ')';
    }
    
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        body{font-family:Arial,sans-serif;}
        .title{font-size:16pt;font-weight:bold;text-align:center;margin-bottom:10px;}
        .subtitle{font-size:14pt;text-align:center;margin-bottom:20px;}
        table{border-collapse:collapse;width:100%;margin-bottom:20px;}
        th{background-color:#2c3e50;color:white;font-weight:bold;padding:8px;text-align:left;border:1px solid #ddd;}
        td{padding:6px;border:1px solid #ddd;}
        .items-table{width:100%;border-collapse:collapse;font-size:12px;}
        .items-table th{background:#f5f5f5;text-align:left;padding:3px;color:black;}
        .items-table td{padding:3px;border-bottom:1px solid #eee;}
    </style></head><body>
    <div class="title">' . htmlspecialchars($businessName) . '</div>
    <div class="subtitle">TODAY\'S TRANSACTION RECORDS' . $filterInfo . '</div>';
    echo '<div>DATE: ' . date('F j, Y') . '</div>';
    echo '<div style="text-align:right;font-style:italic;">Generated: ' . date("F j, Y") . ' at ' . date("g:i A") . '</div>';

    $pmCol = $hasPaymentMethod ? ', payment_method' : '';
    $rfCol = $hasReferenceNo   ? ', reference_no'   : '';
    echo '<table cellspacing="0">
        <thead>
            <tr>
                <th>Trans #</th>
                <th>Cashier</th>
                <th>Date</th>
                <th>Time</th>
                <th>Payment</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Change</th>
                <th>Items</th>
            </tr>
        </thead>
        <tbody>';

    $q = "SELECT *{$pmCol}{$rfCol} FROM transactions WHERE date = CURDATE() AND (status IS NULL OR status != 'voided')";
    $params=[]; $types='';
    if ($_POST['export']!=='all' && !empty($_POST['trans_id'])){
        $q.=" AND id = ?"; 
        $params[]=intval($_POST['trans_id']); 
        $types.='i';
    }
    $q.=" ORDER BY id DESC";
    $stmt=$conn->prepare($q);
    if(!empty($params)) $stmt->bind_param($types,...$params);
    $stmt->execute(); 
    $res=$stmt->get_result();
    $tS=0;$tP=0;$tC=0;$tPcs=0;$tKg=0;$pTotals=[];
    
    while($row=$res->fetch_assoc()){
        $items=json_decode($row['items'],true);
        $fi='';
        if(is_array($items)){
            $fi='<table class="items-table"><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Price</th><th>Total</th></tr>';
            foreach($items as $item){
                $n=htmlspecialchars($item['name']??'');$qty=$item['qty']??0;
                $u=$item['measurement_type']??'pc';$p=number_format($item['price']??0,2);
                $t=number_format($qty*($item['price']??0),2);
                $fi.='<tr><td>'.$n.'</td><td>'.$qty.'</td><td>'.($u==='kg'?'kg':'pc').'</td><td>&#8369;'.$p.'</td><td>&#8369;'.$t.'</td></tr>';
                if(!isset($pTotals[$n])) $pTotals[$n]=['quantity'=>0,'revenue'=>0,'unit'=>$u];
                $pTotals[$n]['quantity']+=$qty; $pTotals[$n]['revenue']+=($qty*($item['price']??0));
                if($u==='kg') $tKg+=(float)$qty; else $tPcs+=(int)$qty;
            }
            $fi.='</table>';
        } else $fi=htmlspecialchars($row['items']);
        $tS+=$row['total']; $tP+=$row['paid']; $tC+=$row['change_due'];
        $pm = $hasPaymentMethod ? htmlspecialchars($row['payment_method'] ?? 'Cash') : 'Cash';
        $rf = $hasReferenceNo   ? htmlspecialchars($row['reference_no'] ?? '')        : '';
        $pmDisplay = $pm . ($rf ? ' (Ref: '.$rf.')' : '');
        
        echo '<tr>
                <td style="text-align:center;">' . $row['id'] . '</td>
                <td>' . htmlspecialchars($row['cashier_name']) . '</td>
                <td>' . date("F j, Y",strtotime($row['date'])) . '</td>
                <td>' . date("g:i A",strtotime($row['time'])) . '</td>
                <td>' . $pmDisplay . '</td>
                <td style="text-align:right;">&#8369;' . number_format($row['total'],2) . '</td>
                <td style="text-align:right;">&#8369;' . number_format($row['paid'],2) . '</td>
                <td style="text-align:right;">&#8369;' . number_format($row['change_due'],2) . '</td>
                <td>' . $fi . '</td>
              </tr>';
    }
    
    $kgDisplay = $tKg == floor($tKg) ? (int)$tKg : rtrim(rtrim(number_format($tKg,3),'0'),'.');
    echo '<tr style="font-weight:bold;background:#f5f5f5;">
            <td colspan="5" style="text-align:right;">TOTALS:</td>
            <td style="text-align:right;">&#8369;' . number_format($tS,2) . '</td>
            <td style="text-align:right;">&#8369;' . number_format($tP,2) . '</td>
            <td style="text-align:right;">&#8369;' . number_format($tC,2) . '</td>
            <td>' . $tPcs . ' pc / ' . $kgDisplay . ' kg</td>
          </tr>';
    echo '</tbody></table>';
    echo '<div style="text-align:center;font-style:italic;">End of Report</div></body></html>';
    ob_end_flush(); 
    exit;
}

/* ─── PDF EXPORT ───────────────────────────────────────────────────────── */
if (isset($_GET['export']) && in_array($_GET['export'],['pdf','pdf_all'])) {
    require(__DIR__ . '/../fpdf.php');
    ob_end_clean();
    
    $filterInfo = '';
    if ($_GET['export'] === 'pdf' && !empty($_GET['trans_id'])) {
        $filterInfo = ' (Filtered: Trans #' . htmlspecialchars($_GET['trans_id']) . ')';
    }
    
    $q = "SELECT * FROM transactions WHERE date = CURDATE() AND (status IS NULL OR status != 'voided')";
    if ($_GET['export'] === 'pdf' && !empty($_GET['trans_id'])) {
        $q .= " AND id = " . intval($_GET['trans_id']);
    }
    $q .= " ORDER BY id DESC";
    $res = $conn->query($q);
    
    $tS=0;$tP=0;$tC=0;$tPcs=0;$tKg=0.0;
    $tmp = $conn->query($q);
    while($r=$tmp->fetch_assoc()){
        $tS+=$r['total'];$tP+=$r['paid'];$tC+=$r['change_due'];
        $its=json_decode($r['items'],true);
        if(is_array($its)) foreach($its as $it){
            $q2=$it['qty']??0;
            if(isset($it['measurement_type'])&&$it['measurement_type']==='kg') $tKg+=(float)$q2;
            else $tPcs+=(int)$q2;
        }
    }
    
    $pdf = new FPDF(); 
    $pdf->AddPage(); 
    $pdf->SetFont('Arial','B',14);
    $pdf->Cell(0,10, htmlspecialchars($businessName), 0,1,'C');
    $pdf->SetFont('Arial','B',12);
    $pdf->Cell(0,8, "TODAY'S TRANSACTION RECORDS" . $filterInfo, 0,1,'C');
    $pdf->SetFont('Arial','',10); 
    $pdf->Cell(0,6,'Date: ' . date('F j, Y'), 0,1,'C');
    $pdf->Cell(0,6,'Generated: ' . date("F j, Y") . ' at ' . date("g:i A"), 0,1,'R');
    $pdf->Ln(5);
    
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell(15,8,'Trans #',1);
    $pdf->Cell(35,8,'Cashier',1);
    $pdf->Cell(22,8,'Date',1);
    $pdf->Cell(18,8,'Time',1);
    $pdf->Cell(22,8,'Payment',1);
    $pdf->Cell(22,8,'Total',1);
    $pdf->Cell(22,8,'Paid',1);
    $pdf->Cell(24,8,'Change',1);
    $pdf->Ln();
    
    $pdf->SetFont('Arial','',9);
    $res->data_seek(0);
    while($row=$res->fetch_assoc()){
        $pm = $hasPaymentMethod ? ($row['payment_method'] ?? 'Cash') : 'Cash';
        $rf = $hasReferenceNo   ? ($row['reference_no'] ?? '')        : '';
        $pmStr = $pm . ($rf ? ' #' . substr($rf,0,10) : '');
        
        $pdf->Cell(15,8,$row['id'],1);
        $pdf->Cell(35,8,substr($row['cashier_name'],0,20),1);
        $pdf->Cell(22,8,date("M j, Y",strtotime($row['date'])),1);
        $pdf->Cell(18,8,date("g:i A",strtotime($row['time'])),1);
        $pdf->Cell(22,8,substr($pmStr,0,18),1);
        $pdf->Cell(22,8,'PHP ' . number_format($row['total'],2),1,0,'R');
        $pdf->Cell(22,8,'PHP ' . number_format($row['paid'],2),1,0,'R');
        $pdf->Cell(24,8,'PHP ' . number_format($row['change_due'],2),1,0,'R');
        $pdf->Ln();
        
        $its=json_decode($row['items'],true);
        if(is_array($its)){ 
            $pdf->SetFont('Arial','',8);
            foreach($its as $it){
                $nm=substr($it['name']??'Unnamed',0,30);
                $qty=$it['qty']??0;
                $u=isset($it['measurement_type'])&&$it['measurement_type']==='kg'?'kg':'pc';
                $pr=$it['price']??0;
                $pdf->Cell(15,4,'',0);
                $pdf->Cell(160,4,"- $nm x $qty $u @ PHP " . number_format($pr,2) . " = PHP " . number_format($qty*$pr,2),0,1);
            }
            $pdf->SetFont('Arial','',9);
            $pdf->Ln(2);
        }
    }
    
    $kgD = $tKg == floor($tKg) ? (int)$tKg : rtrim(rtrim(number_format($tKg,3),'0'),'.');
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell(97,8,'TOTALS:',1,0,'R');
    $pdf->Cell(22,8,'PHP ' . number_format($tS,2),1,0,'R');
    $pdf->Cell(22,8,'PHP ' . number_format($tP,2),1,0,'R');
    $pdf->Cell(24,8,'PHP ' . number_format($tC,2),1,0,'R');
    $pdf->Ln();
    $pdf->Cell(97,8,'',0);
    $pdf->Cell(66,8,'Total Items: ' . $tPcs . ' pcs / ' . $kgD . ' kg',1,1,'C');
    
    $pdf->Output('D','Transaction_Report_' . date('Ymd_His') . ($_GET['export']==='pdf'?'_filtered':'_all') . '.pdf'); 
    exit;
}
require_once __DIR__ . '/../include/header.php';
/* ─── PAGE QUERY ─────────────────────────────────────────────────────── */
$query = "SELECT * FROM transactions WHERE date = CURDATE() AND (status IS NULL OR status != 'voided')";
if (!empty($_GET['trans_id'])) {
    $query .= " AND id = " . intval($_GET['trans_id']);
}
$query .= " ORDER BY id DESC";
$result = $conn->query($query);

$totalSales=0;$totalPaid=0;$totalChange=0;$totalPiecesSold=0;$totalKgSold=0;$productTotals=[];
$allTransactionsData = [];

$result->data_seek(0);
while($row=$result->fetch_assoc()){
    if($row['status']==='voided') continue;
    $txTotal=0;
    if(!empty($row['items'])){
        $items=json_decode($row['items'],true);
        if(is_array($items)) foreach($items as $item){
            if(($item['status']??'')==='voided') continue;
            $qty=$item['qty']??0; $price=$item['price']??0;
            $txTotal+=$qty*$price;
            $nm=$item['name']??'Unnamed';
            if(!isset($productTotals[$nm])) $productTotals[$nm]=['quantity'=>0,'revenue'=>0,'unit'=>$item['measurement_type']??'pc'];
            $productTotals[$nm]['quantity']+=$qty;
            $productTotals[$nm]['revenue']+=($qty*$price);
            if(isset($item['measurement_type'])&&$item['measurement_type']==='kg') $totalKgSold+=(float)$qty;
            else $totalPiecesSold+=(int)$qty;
        }
    }
    $totalSales+=$txTotal; $totalPaid+=$row['paid']; $totalChange+=$row['change_due'];
    
    $allTransactionsData[$row['id']] = [
        'id'             => $row['id'],
        'items'          => $row['items'],
        'original_items' => $row['original_items'] ?? '[]',
        'status'         => $row['status'] ?? '',
    ];
}
$result->data_seek(0);
uasort($productTotals,function($a,$b){ return $b['revenue']<=>$a['revenue']; });

/* ─── E-WALLET COLORS MAP ──────────────────────────────────────────────── */
$ewalletMeta = [
    'gcash'   => ['label'=>'GCash',   'color'=>'#0a6dff', 'icon'=>'wallet'],
    'maya'    => ['label'=>'Maya',    'color'=>'#00b050', 'icon'=>'wallet'],
    'paymaya' => ['label'=>'Maya',    'color'=>'#00b050', 'icon'=>'wallet'],
    'shopeepay'=> ['label'=>'ShopeePay','color'=>'#ee4d2d','icon'=>'wallet'],
    'grabpay' => ['label'=>'GrabPay', 'color'=>'#00b14f', 'icon'=>'wallet'],
    'seabank' => ['label'=>'SeaBank', 'color'=>'#2b4fff', 'icon'=>'wallet'],
    'coins'   => ['label'=>'Coins.ph','color'=>'#f5a623', 'icon'=>'wallet'],
];

function getPaymentMeta($pm, $ewalletMeta) {
    if (!$pm || strtolower(trim($pm)) === 'cash') {
        return ['label'=>'Cash', 'color'=>'#2a6e1a', 'icon'=>'cash', 'type'=>'cash'];
    }
    $key = strtolower(str_replace([' ','-','_'],'',$pm));
    foreach ($ewalletMeta as $k => $meta) {
        if (strpos($key, $k) !== false) return array_merge($meta, ['type'=>'ewallet']);
    }
    return ['label'=>htmlspecialchars($pm), 'color'=>'#7a5c00', 'icon'=>'wallet', 'type'=>'ewallet'];
}

// SVG icon helper function
function getPaymentIcon($iconType) {
    if ($iconType === 'cash') {
        return '<svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/></svg>';
    }
    return '<svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor"><path d="M21 18v1c0 1.1-.9 2-2 2H5c-1.11 0-2-.9-2-2V5c0-1.1.89-2 2-2h14c1.1 0 2 .9 2 2v1h-9c-1.11 0-2 .9-2 2v8c0 1.1.89 2 2 2h9zm-9-2h10V8H12v8zm4-2.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>';
}

// Check for flash messages
if (isset($_SESSION['success_msg'])) {
    echo '<div id="php-success-msg" data-msg="' . htmlspecialchars($_SESSION['success_msg']) . '" style="display:none;"></div>';
    unset($_SESSION['success_msg']);
}
if (isset($_SESSION['error_msg'])) {
    echo '<div id="php-error-msg" data-msg="' . htmlspecialchars($_SESSION['error_msg']) . '" style="display:none;"></div>';
    unset($_SESSION['error_msg']);
}
?>

<style>
/* ══ MAIN ══════════════════════════════════════════════════════════════ */
.main-content { 
  margin-top: 44px; 
  padding: 14px 16px; 
  flex: 1; 
  overflow-y: auto; 
  max-height: calc(100vh - 44px - 26px);
}
/* ══ PAGE HEADER ══════════════════════════════════════════════════════ */
.page-header {
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 12px; padding-bottom: 10px;
  border-bottom: 2px solid #333;
}
.page-header h2 { font-size: 17px; font-weight: 800; color: #f0f0f0; display: flex; align-items: center; gap: 8px; }
.page-header h2 svg { width: 20px; height: 20px; fill: #ff8800; }
.page-header .date-badge {
  background: linear-gradient(135deg, #ff8800, #cc5500);
  color: white; padding: 3px 10px; border-radius: 4px;
  font-size: 11px; font-weight: 700;
}

/* ── Cache status indicator ── */
.cache-indicator {
  display: inline-flex; align-items: center; gap: 5px;
  background: #1a2a1a; border: 1px solid #2a4a2a;
  border-radius: 4px; padding: 3px 8px;
  font-size: 10px; color: #66bb66;
  transition: all 0.3s;
}
.cache-indicator.stale { background: #2a1a00; border-color: #4a3a00; color: #ffaa44; }
.cache-indicator.refreshing { background: #1a1a3a; border-color: #2a2a5a; color: #88aaff; }
.cache-indicator .ci-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; display: inline-block; }
.cache-indicator.refreshing .ci-dot { animation: ci-pulse 0.8s infinite; }
@keyframes ci-pulse { 0%,100%{opacity:1} 50%{opacity:0.3} }

/* ══ FILTER CARD ══════════════════════════════════════════════════════ */
.filter-card {
  background: #2a2a2a; border: 1px solid #3a3a3a;
  border-radius: 6px; padding: 12px 14px; margin-bottom: 12px;
  display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap;
}
.filter-card label { font-size: 11px; color: #ffffff; font-weight: 600; display: block; margin-bottom: 4px; }
.filter-input {
  background: #333; border: 1.5px solid #c7fc08; color: #eee;
  border-radius: 4px; padding: 6px 10px; font-size: 12px;
  width: 200px; transition: border-color 0.15s;
}
.filter-input:focus { outline: none; border-color: #ff8800; background: #3a3a3a; }
.filter-input::placeholder { color: #c4bebe; }

/* ══ BUTTONS ══════════════════════════════════════════════════════════ */
.btn {
  padding: 6px 14px; border: none; border-radius: 4px;
  font-size: 12px; font-weight: 600; cursor: pointer;
  display: inline-flex; align-items: center; gap: 5px;
  text-decoration: none; transition: filter 0.15s; white-space: nowrap;
}
.btn svg { width: 14px; height: 14px; fill: currentColor; }
.btn:hover { filter: brightness(1.1); }
.btn-orange  { background: linear-gradient(135deg, #ff9900, #cc6600); color: white; }
.btn-dark    { background: linear-gradient(135deg, #555, #333); color: #ddd; }
.btn-green   { background: linear-gradient(135deg, #28a745, #1a6e2e); color: white; }
.btn-blue    { background: linear-gradient(135deg, #3a7bd5, #1a4fa0); color: white; }
.btn-red     { background: linear-gradient(135deg, #e53935, #b71c1c); color: white; }
.btn-yellow  { background: linear-gradient(135deg, #f5a623, #c47d0a); color: #111; }
.btn-sm      { padding: 4px 10px; font-size: 11px; }

.export-strip { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }

/* ══ TABLE ══════════════════════════════════════════════════════════════ */
.table-wrap {
  background: #242424;
  border-radius: 6px;
  border: 1px solid #333;
  position: relative;
  overflow: hidden;
  height: 100%;
  max-height: 550px;
}

.tbl-scroll {
  overflow: auto;
  height: 100%;
  max-height: 550px;
  width: 100%;
}

.tbl-scroll::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}
.tbl-scroll::-webkit-scrollbar-track {
  background: #1a1a1a;
  border-radius: 4px;
}
.tbl-scroll::-webkit-scrollbar-thumb {
  background: #555;
  border-radius: 4px;
}
.tbl-scroll::-webkit-scrollbar-thumb:hover {
  background: #ff8800;
}

table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  min-width: 1200px;
  table-layout: auto;
}

/* Sticky header - THIS KEEPS HEADERS FIXED AT TOP */
thead {
  position: sticky;
  top: 0;
  z-index: 20;
}
thead tr {
  background: linear-gradient(180deg, #ff9900, #cc6600);
}
thead th {
  padding: 9px 12px;
  font-size: 11px;
  font-weight: 700;
  color: white;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  border-right: 1px solid rgba(255,255,255,0.15);
  white-space: nowrap;
  position: sticky;
  top: 0;
  z-index: 20;
  background: linear-gradient(180deg, #ff9900, #cc6600);
  box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}
thead th:last-child { border-right: none; }

/* Sticky first column (Trans #) - keeps it visible when scrolling horizontally */
thead th:first-child,
tbody td:first-child {
  position: sticky;
  left: 0;
  z-index: 10;
  background: #242424;
}
thead th:first-child {
  z-index: 25;
  background: linear-gradient(180deg, #ff9900, #cc6600);
}
tbody td:first-child {
  z-index: 10;
  background: #242424;
}

tbody tr {
  border-bottom: 1px solid #2e2e2e;
  transition: background 0.1s;
}
tbody tr:hover { background: #2d2d2d; }
tbody tr:hover td:first-child {
  background: #2d2d2d;
}
tbody tr.voided-row { opacity: 0.55; }
tbody tr.voided-row td:first-child {
  background: #242424;
  opacity: 0.55;
}

tbody td {
  padding: 8px 12px;
  font-size: 12px;
  color: #d0d0d0;
  vertical-align: top;
  background: #242424;
}

/* Totals row - not sticky */
.totals-row td {
  background: #2a2a2a !important;
  border-top: 2px solid #ff8800;
  font-weight: 700;
  color: #ffcc66;
  padding: 10px 12px;
}
.totals-row td:first-child {
  background: #2a2a2a !important;
  z-index: 5;
}

/* Breakdown row */
.breakdown-row td {
  background: #222 !important;
  padding: 12px;
}
.breakdown-row td:first-child {
  background: #222 !important;
}

tbody td .id-badge {
  background: #333; color: #ffcc66;
  border-radius: 3px; padding: 1px 6px; font-size: 11px; font-weight: 700;
}
tbody td .money        { color: #66dd88; font-weight: 700; }
tbody td .money-paid   { color: #ffcc66; font-weight: 700; }
tbody td .money-change { color: #88ccff; font-weight: 600; }

/* ── Payment badges ── */
.pay-badge {
  display: inline-flex; align-items: center; gap: 4px;
  border-radius: 4px; padding: 3px 8px;
  font-size: 11px; font-weight: 700; cursor: default;
  white-space: nowrap; user-select: none;
}
.pay-badge.clickable { cursor: pointer; }
.pay-badge.clickable:hover { filter: brightness(1.15); }
.pay-badge .pay-icon { display: inline-flex; }
.pay-badge .pay-ref {
  font-size: 9px; font-weight: 500; opacity: 0.85;
  background: rgba(0,0,0,0.25); border-radius: 3px;
  padding: 1px 4px; margin-left: 2px;
}

/* ── Audit trail cell ── */
.audit-cell { font-size: 10px; }
.audit-tag {
  display: inline-flex; align-items: center; gap: 3px;
  border-radius: 3px; padding: 2px 6px; font-size: 10px;
  font-weight: 700; margin-bottom: 3px; white-space: nowrap;
}
.audit-voided { background: #5a0000; color: #ff8888; }
.audit-edited { background: #4a3a00; color: #ffe466; }
.audit-ok     { background: #1a3a1a; color: #88dd88; }
.audit-detail { color: #888; font-size: 10px; line-height: 1.5; margin-top: 2px; }

/* Items mini table */
.items-mini { max-height: 140px; overflow-y: auto; display: block; width: max-content; max-width: 100%; }
.items-mini::-webkit-scrollbar { width: 3px; }
.items-mini::-webkit-scrollbar-thumb { background: #555; }
.items-tbl { width: max-content !important; min-width: 0 !important; border-collapse: collapse !important; border-spacing: 0 !important; table-layout: auto !important; font-size: 11px; }
.items-tbl th, .items-tbl td { padding: 2px 3px !important; white-space: nowrap; width: auto !important; }
.items-tbl th { background: #1a1a1a; color: #aaa; font-weight: 600; border-bottom: 1px solid #333; }
.items-tbl td { color: #ccc; border-bottom: 1px solid #2a2a2a; }
.items-tbl th:nth-child(1), .items-tbl td:nth-child(1) { text-align: left; padding-right: 5px !important; }
.items-tbl th:nth-child(2), .items-tbl td:nth-child(2) { text-align: center; padding-left: 3px !important; padding-right: 3px !important; }
.items-tbl th:nth-child(3), .items-tbl td:nth-child(3) { text-align: right; padding-left: 3px !important; padding-right: 3px !important; }
.items-tbl th:nth-child(4), .items-tbl td:nth-child(4) { text-align: right; padding-left: 3px !important; padding-right: 3px !important; }
.items-tbl th:nth-child(5), .items-tbl td:nth-child(5) { padding-left: 3px !important; padding-right: 0 !important; }
.badge-voided { background: #7a0000; color: #ffaaaa; border-radius: 3px; padding: 1px 4px; font-size: 10px; }
.badge-edited { background: #7a5500; color: #ffe680; border-radius: 3px; padding: 1px 4px; font-size: 10px; }
/* Product breakdown */
.breakdown-row td { background: #222; padding: 12px; }
.breakdown-title { font-size: 11px; font-weight: 700; color: #ff8800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
.breakdown-title svg { width: 16px; height: 16px; fill: #ff8800; }
.breakdown-grid { display: flex; flex-wrap: wrap; gap: 8px; }
.breakdown-card { background: #2e2e2e; border: 1px solid #3a3a3a; border-radius: 5px; padding: 8px 10px; min-width: 130px; transition: border-color 0.15s; }
.breakdown-card:hover { border-color: #ff8800; }
.bc-name { font-size: 11px; font-weight: 700; color: #f0f0f0; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px; }
.bc-row  { display: flex; justify-content: space-between; font-size: 10px; }
.bc-label { color: #888; }
.bc-val   { color: #ffcc66; font-weight: 600; }
.bc-rev   { color: #66dd88; font-weight: 700; }

/* ══ MODAL ══════════════════════════════════════════════════════════ */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,0.75);
  z-index: 9000; align-items: center; justify-content: center;
}
.modal-overlay.show { display: flex; }
.modal-box {
  background: #282828; border: 1.5px solid #3a3a3a;
  border-radius: 8px; width: 90%; max-width: 720px;
  max-height: 88vh; display: flex; flex-direction: column;
  box-shadow: 0 20px 60px rgba(0,0,0,0.7); overflow: hidden;
  cursor: grab; position: relative;
}
.modal-box:active { cursor: grabbing; }
.modal-title-bar {
  background: linear-gradient(135deg, #ff9900, #cc6600);
  padding: 10px 14px; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;
  cursor: grab;
}
.modal-title-bar:active { cursor: grabbing; }
.modal-title-bar span { font-weight: 700; font-size: 13px; color: white; letter-spacing: 0.5px; }
.modal-x {
  background: rgba(0,0,0,0.25); color: white; border: none;
  border-radius: 3px; width: 24px; height: 24px; font-size: 14px;
  cursor: pointer; font-weight: 700; display: flex; align-items: center; justify-content: center;
}
.modal-x:hover { background: rgba(0,0,0,0.5); }
.modal-body { padding: 14px; overflow-y: auto; flex: 1; }
.modal-footer { padding: 10px 14px; border-top: 1px solid #333; display: flex; gap: 8px; justify-content: flex-end; flex-shrink: 0; }

.form-label    { font-size: 11px; color: #999; font-weight: 600; display: block; margin-bottom: 4px; }
.form-input    { width: 100%; background: #333; border: 1.5px solid #555; color: #eee; border-radius: 4px; padding: 7px 10px; font-size: 12px; }
.form-input:focus { outline: none; border-color: #ff8800; background: #3a3a3a; }
.form-select   { width: 100%; background: #333; border: 1.5px solid #555; color: #eee; border-radius: 4px; padding: 7px 10px; font-size: 12px; }
.form-select:focus { outline: none; border-color: #ff8800; }
.form-textarea { width: 100%; background: #333; border: 1.5px solid #555; color: #eee; border-radius: 4px; padding: 7px 10px; font-size: 12px; resize: vertical; min-height: 70px; }
.form-textarea:focus { outline: none; border-color: #ff8800; }

.action-tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
.action-tbl th { background: #1e1e1e; color: #aaa; padding: 7px 8px; font-weight: 600; border-bottom: 1px solid #333; }
.action-tbl td { padding: 6px 8px; border-bottom: 1px solid #2a2a2a; }
.action-tbl .form-input  { padding: 4px 6px; font-size: 11px; }
.action-tbl .form-select { padding: 4px 6px; font-size: 11px; }

.new-total-bar {
  background: #1e1e1e; padding: 8px 12px; border-radius: 4px;
  margin-top: 8px; display: flex; justify-content: space-between;
}
.new-total-bar .nt-label { color: #888; font-size: 12px; }
.new-total-bar .nt-val   { color: #66dd88; font-size: 15px; font-weight: 800; }
.mb-3 { margin-bottom: 12px; }

/* Reference popup */
.ref-popup-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,0.65);
  z-index: 9500; align-items: center; justify-content: center;
}
.ref-popup-overlay.show { display: flex; }
.ref-popup {
  background: #252525; border: 2px solid #444;
  border-radius: 10px; padding: 20px 24px; min-width: 280px; max-width: 380px;
  box-shadow: 0 16px 40px rgba(0,0,0,0.7); text-align: center;
  cursor: grab; position: relative;
}
.ref-popup:active { cursor: grabbing; }
.ref-popup .rp-icon { margin-bottom: 6px; display: inline-flex; }
.ref-popup .rp-icon svg { width: 32px; height: 32px; }
.ref-popup .rp-label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
.ref-popup .rp-name  { font-size: 18px; font-weight: 800; color: #f0f0f0; margin-bottom: 12px; }
.ref-popup .rp-ref-label { font-size: 11px; color: #888; margin-bottom: 4px; }
.ref-popup .rp-ref   { font-size: 15px; font-weight: 700; color: #ffcc66; background: #1e1e1e; border-radius: 5px; padding: 6px 14px; letter-spacing: 1px; display: inline-block; }
.ref-popup .rp-close { margin-top: 14px; background: #444; border: none; color: #ccc; border-radius: 4px; padding: 6px 18px; cursor: pointer; font-size: 12px; }
.ref-popup .rp-close:hover { background: #ff8800; color: white; }
/* ══ FOOTER FIX ══════════════════════════════════════════════════════ */
/* Ensure the status bar from footer.php displays properly */
.status-bar {
  background: #111;
  border-top: 1px solid #222;
  padding: 3px 12px;
  font-size: 10px;
  color: #fdf7f7;
  display: flex;
  gap: 14px;
  height: 26px;
  align-items: center;
  flex-shrink: 0;
  width: 100%;
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: 100;
}
.status-bar span {
  border-right: 1px solid #2a2a2a;
  padding-right: 14px;
}
.status-bar span:last-child {
  border-right: none;
  margin-left: auto;
}
.stat-offline { color: #ff4444 !important; font-weight: 700; }
.stat-online  { color: #44ff88 !important; font-weight: 700; }

/* Add bottom padding to main content to prevent content from being hidden behind status bar */
.main-content {
  padding-bottom: 40px;
  max-height: calc(100vh - 44px - 26px - 10px);
}
</style>

<!-- MAIN -->
<div class="main-content" id="mainContent">

  <div class="page-header">
    <h2>
      <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
      Transaction Records
    </h2>
    <span class="date-badge"><?= date('F j, Y') ?></span>
    <small style="color:#aaa;font-size:11px;">Showing today's transactions only</small>
    <span class="cache-indicator" id="cacheIndicator" title="Transaction data cache status">
      <span class="ci-dot"></span>
      <span id="cacheLabel">Cache ready</span>
    </span>
  </div>

  <!-- Filter -->
  <form method="get">
    <div class="filter-card">
      <div>
        <label>Transaction #</label>
        <input type="text" name="trans_id" class="filter-input"
               placeholder="Enter transaction number"
               value="<?= htmlspecialchars($_GET['trans_id'] ?? '') ?>">
      </div>
      <button type="submit" class="btn btn-orange">
        <svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
        Filter
      </button>
      <button type="button" class="btn btn-dark" onclick="location.href='?'">
        <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        Clear
      </button>
    </div>
  </form>

  <!-- Export strip -->
  <div class="export-strip">
    <form method="post" style="display:inline;">
      <input type="hidden" name="trans_id" value="<?= htmlspecialchars($_GET['trans_id'] ?? '') ?>">
      <button type="submit" name="export" value="filtered" class="btn btn-green">
        <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
        Export Filtered (Excel)
      </button>
    </form>
    <form method="post" style="display:inline;">
      <button type="submit" name="export" value="all" class="btn btn-blue">
        <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
        Export All (Excel)
      </button>
    </form>
    <a href="?export=pdf&trans_id=<?= urlencode($_GET['trans_id'] ?? '') ?>"
       class="btn btn-red">
      <svg viewBox="0 0 24 24"><path d="M20 2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-8.5 7.5c0 .83-.67 1.5-1.5 1.5H9v2H7.5V7H10c.83 0 1.5.67 1.5 1.5v1zm5 2c0 .83-.67 1.5-1.5 1.5h-2.5V7H15c.83 0 1.5.67 1.5 1.5v3zm4-3H19v1h1.5V11H19v2h-1.5V7h3v1.5zM9 9.5h1v-1H9v1zM4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm10 5.5h1v-3h-1v3z"/></svg>
      Export Filtered (PDF)
    </a>
    <a href="?export=pdf_all" class="btn btn-yellow">
      <svg viewBox="0 0 24 24"><path d="M20 2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-8.5 7.5c0 .83-.67 1.5-1.5 1.5H9v2H7.5V7H10c.83 0 1.5.67 1.5 1.5v1zm5 2c0 .83-.67 1.5-1.5 1.5h-2.5V7H15c.83 0 1.5.67 1.5 1.5v3zm4-3H19v1h1.5V11H19v2h-1.5V7h3v1.5zM9 9.5h1v-1H9v1zM4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm10 5.5h1v-3h-1v3z"/></svg>
      Export All (PDF)
    </a>
  </div>

  <!-- Table -->
  <div class="table-wrap">
    <div class="tbl-scroll">
      <table>
        <thead>
          <tr>
            <th>Trans #</th>
            <th>Cashier</th>
            <th>Date</th>
            <th>Time</th>
            <th>Payment</th>
            <th>Total</th>
            <th>Paid</th>
            <th>Change</th>
            <th>Items</th>
            <th>Status / Actions</th>
          </tr>
        </thead>
        <tbody>

        <?php
        $result->data_seek(0);
        while ($row = $result->fetch_assoc()):
            $isVoided = ($row['status'] === 'voided');

            $pm     = $hasPaymentMethod ? ($row['payment_method'] ?? 'Cash') : 'Cash';
            $refNo  = $hasReferenceNo   ? ($row['reference_no']   ?? '')      : '';
            if (!$pm) $pm = 'Cash';
            $pmMeta = getPaymentMeta($pm, $ewalletMeta);

            $wasEdited = !empty($row['edited_by']) || !empty($row['edit_remarks']) || !empty($row['edited_at']);
        ?>
          <tr class="<?= $isVoided ? 'voided-row' : '' ?>">

            <td><span class="id-badge"><?= $row['id'] ?></span></td>

            <td style="white-space:nowrap;"><?= htmlspecialchars($row['cashier_name']) ?></td>

            <td style="white-space:nowrap;"><?= date('M j, Y', strtotime($row['date'])) ?></td>

            <td style="white-space:nowrap;"><?= date('g:i A', strtotime($row['time'])) ?></td>

           <td>
              <?php
              $badgeStyle  = 'background:'.($pmMeta['color'] ?? '#555').';color:white;';
              $isEwallet   = $pmMeta['type'] === 'ewallet';
              $hasRef      = $isEwallet && !empty($refNo);
              ?>
              <span class="pay-badge <?= $hasRef ? 'clickable' : '' ?>"
                    style="<?= $badgeStyle ?>"
                    data-ref-label="<?= htmlspecialchars($pmMeta['label']) ?>"
                    data-ref-icon="<?= htmlspecialchars($pmMeta['icon']) ?>"
                    data-ref-number="<?= htmlspecialchars($refNo) ?>"
                    <?= $hasRef ? 'onclick="showRef(this)"' : '' ?>
                    title="<?= $hasRef ? 'Click to view reference number' : '' ?>">
                <span class="pay-icon"><?= getPaymentIcon($pmMeta['icon']) ?></span>
                <?= htmlspecialchars($pmMeta['label']) ?>
                <?php if ($hasRef): ?>
                  <span class="pay-ref">Ref #</span>
                <?php endif; ?>
              </span>
            </td>

            <td><span class="money"><?= $currencySymbol ?><?= number_format($row['total'],2) ?></span></td>

            <td><span class="money-paid"><?= $currencySymbol ?><?= number_format($row['paid'],2) ?></span></td>

            <td><span class="money-change"><?= $currencySymbol ?><?= number_format($row['change_due'],2) ?></span></td>

            <td>
              <?php
              if (!empty($row['items'])) {
                  $items     = json_decode($row['items'], true);
                  $origItems = json_decode($row['original_items'] ?? '[]', true);
                  if (is_array($items)) {
                      echo '<div class="items-mini"><table class="items-tbl">';
                      echo '<tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th><th></th></tr>';
                      foreach ($items as $idx => $item) {
                          $nm    = htmlspecialchars($item['name'] ?? '');
                          $qty   = (float)($item['qty'] ?? 0);
                          $unit  = htmlspecialchars($item['unit'] ?? '');
                          $price = (float)($item['price'] ?? 0);
                          $tot   = $qty * $price;
                          $st    = $item['status'] ?? '';
                          $orig  = $origItems[$idx] ?? null;
                          $edited = $orig && (
                              ($orig['name']??'')  !== ($item['name']??'')  ||
                              ($orig['qty']??0)    != $qty                  ||
                              ($orig['price']??0)  != $price
                          );
                          echo '<tr>';
                          echo '<td>'.($st==='voided'?'<del>'.$nm.'</del>':$nm);
                          if ($edited) echo ' <span class="badge-edited">Edited</span>';
                          echo '</td>';
                          echo '<td style="text-align:center;">'.($st==='voided'?'<del>'.number_format($qty,2).'</del>':number_format($qty,2)).' '.$unit.'</td>';
                          echo '<td style="text-align:right;">'.($st==='voided'?'<del>'.$currencySymbol.number_format($price,2).'</del>':$currencySymbol.number_format($price,2)).'</td>';
                          echo '<td style="text-align:right;">'.($st==='voided'?'<del>'.$currencySymbol.'0.00</del>':$currencySymbol.number_format($tot,2)).'</td>';
                          echo '<td>'.($st==='voided'?'<span class="badge-voided">Voided</span>':'').'</td>';
                          echo '</tr>';
                      }
                      echo '</table></div>';
                  } else echo '<span style="color:#666;font-size:11px;">Parse error</span>';
              } else echo '<span style="color:#555;font-size:11px;">No items</span>';
              ?>
            </td>

            <td style="white-space:nowrap; min-width:160px;">
              <?php if ($isVoided): ?>
                <div class="audit-cell">
                  <div><span class="audit-tag audit-voided">VOIDED</span></div>
                  <?php if (!empty($row['voided_by'])): ?>
                  <div class="audit-detail">
                    By: <strong style="color:#ffaaaa;"><?= htmlspecialchars($row['voided_by']) ?></strong><br>
                    <?= !empty($row['voided_at']) ? date('M j, Y g:i A', strtotime($row['voided_at'])) : '' ?>
                    <?php if (!empty($row['void_reason'])): ?>
                      <br><em style="color:#ff8888;">"<?= htmlspecialchars(mb_strimwidth($row['void_reason'],0,50,'…')) ?>"</em>
                    <?php endif; ?>
                  </div>
                  <?php endif; ?>
                </div>

              <?php else: ?>
                <div class="audit-cell" style="margin-bottom:6px;">
                  <?php if ($wasEdited): ?>
                    <div><span class="audit-tag audit-edited">EDITED</span></div>
                    <div class="audit-detail">
                      By: <strong style="color:#ffe466;"><?= htmlspecialchars($row['edited_by'] ?? '') ?></strong><br>
                      <?= !empty($row['edited_at']) ? date('M j, Y g:i A', strtotime($row['edited_at'])) : '' ?>
                      <?php if (!empty($row['edit_remarks'])): ?>
                        <br><em style="color:#ffe466;">"<?= htmlspecialchars(mb_strimwidth($row['edit_remarks'],0,50,'…')) ?>"</em>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <span class="audit-tag audit-ok">OK</span>
                  <?php endif; ?>
                </div>

                <div style="display:flex;flex-direction:column;gap:4px;">
                  <a href="../record/reprint.php?id=<?= $row['id'] ?>"
                     target="_blank" class="btn btn-blue btn-sm">
                    <svg viewBox="0 0 24 24"><path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>
                    Print
                  </a>
                  <button class="btn btn-orange btn-sm action-btn"
                          data-transaction-id="<?= $row['id'] ?>">
                    <svg viewBox="0 0 24 24"><path d="M19.43 12.98c.04-.32.07-.64.07-.98s-.03-.66-.08-.98l2.11-1.65c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.4-1.08-.73-1.69-.98L14.5 2.42c-.03-.24-.24-.42-.5-.42h-4c-.26 0-.47.18-.5.42l-.36 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.23-.08-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64l2.11 1.65c-.04.32-.08.65-.08.98s.03.66.08.98l-2.11 1.65c-.19.15-.24.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1c.52.4 1.08.73 1.69.98l.36 2.65c.04.24.24.42.5.42h4c.26 0 .47-.18.5-.42l.36-2.65c.61-.25 1.17-.59 1.69-.98l2.49 1c.23.08.49 0 .61-.22l2-3.46c.12-.22.07-.49-.12-.64l-2.11-1.65zM12 15.5c-1.93 0-3.5-1.57-3.5-3.5s1.57-3.5 3.5-3.5 3.5 1.57 3.5 3.5-1.57 3.5-3.5 3.5z"/></svg>
                    Actions
                  </button>
                </div>
              <?php endif; ?>
            </td>

           </tr>
        <?php endwhile; ?>

          <tr class="breakdown-row">
            <td colspan="10">
              <div class="breakdown-title">
                <svg viewBox="0 0 24 24"><path d="M20 6h-8l-2-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm0 12H4V8h16v10z"/></svg>
                Product Breakdown
              </div>
              <div class="breakdown-grid">
                <?php foreach ($productTotals as $pName => $pt):
                  $ud = ($pt['unit']==='kg') ? 'kg' : 'pc';
                  $qd = ($pt['unit']==='kg')
                    ? (($pt['quantity']==floor($pt['quantity']))?(int)$pt['quantity']:rtrim(rtrim(number_format($pt['quantity'],3),'0'),'.'))
                    : (int)$pt['quantity'];
                ?>
                <div class="breakdown-card">
                  <div class="bc-name" title="<?= htmlspecialchars($pName) ?>"><?= htmlspecialchars($pName) ?></div>
                  <div class="bc-row"><span class="bc-label">Qty:</span>  <span class="bc-val"><?= $qd ?> <?= $ud ?></span></div>
                  <div class="bc-row"><span class="bc-label">Sales:</span><span class="bc-rev"><?= $currencySymbol ?><?= number_format($pt['revenue'],2) ?></span></div>
                </div>
                <?php endforeach; ?>
              </div>
            </td>
          </tr>

          <tr class="totals-row">
            <td colspan="5" style="text-align:right; color:#aaa; font-size:12px;">TOTALS (excl. voided):</td>
            <td><span class="money"><?= $currencySymbol ?><?= number_format($totalSales,2) ?></span></td>
            <td><span class="money-paid"><?= $currencySymbol ?><?= number_format($totalPaid,2) ?></span></td>
            <td><span class="money-change"><?= $currencySymbol ?><?= number_format($totalChange,2) ?></span></td>
            <td colspan="2">
              <?php if($totalPiecesSold>0||$totalKgSold>0): ?>
                <span style="background:#1a3a1a;color:#88dd88;border-radius:3px;padding:2px 8px;font-size:11px;">
                  <?= $totalPiecesSold>0 ? $totalPiecesSold.' pc' : '' ?>
                  <?= ($totalPiecesSold>0&&$totalKgSold>0) ? ' | ' : '' ?>
                  <?= $totalKgSold>0 ? rtrim(rtrim(number_format($totalKgSold,3),'0'),'.').' kg' : '' ?>
                </span>
              <?php else: ?><span style="color:#555;">No items</span><?php endif; ?>
            </td>
          </tr>

        </tbody>
       </table>
    </div>
  </div>
</div>

<!-- ══ REFERENCE NUMBER POPUP ══════════════════════════════════════════ -->
<div class="ref-popup-overlay" id="refPopup" onclick="if(event.target===this)closeRef()">
  <div class="ref-popup draggable-modal" id="refPopupBox">
    <div class="rp-icon" id="refIcon"></div>
    <div class="rp-label">E-Wallet Payment</div>
    <div class="rp-name" id="refName"></div>
    <div class="rp-ref-label">Reference Number</div>
    <div class="rp-ref" id="refNumber"></div>
    <br>
    <button class="rp-close" onclick="closeRef()">Close</button>
  </div>
</div>

<!-- ══ ACTION MODAL ══════════════════════════════════════════════════ -->
<div class="modal-overlay" id="actionModal">
  <div class="modal-box draggable-modal" id="actionModalBox">
    <div class="modal-title-bar drag-handle" id="actionModalHandle">
      <span>Transaction Actions – <span id="modalTxnId"></span></span>
      <button class="modal-x" onclick="closeActionModal()">✕</button>
    </div>
    <form id="actionForm" method="post" action="process_transaction_action.php">
      <div class="modal-body">
        <input type="hidden" name="transaction_id" id="actionTransactionId">
        <input type="hidden" name="original_items" id="originalItems">

        <div id="warningBanner" style="display:none;background:#3a0000;border:1px solid #aa2200;border-radius:4px;padding:8px 12px;margin-bottom:10px;color:#ffaaaa;font-size:12px;">
          <span id="warningMsg"></span>
        </div>

        <div style="overflow-x:auto;margin-bottom:10px;">
          <table class="action-tbl">
            <thead>
              <tr>
                <th>Item</th><th>Qty</th><th>Price</th><th>Total</th><th>Action</th>
              </tr>
            </thead>
            <tbody id="itemsTableBody"></tbody>
          </table>
          <div class="new-total-bar" id="totalBar" style="display:none;">
            <span class="nt-label">New Total:</span>
            <span class="nt-val" id="newTotal">₱0.00</span>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label">Bulk Action</label>
          <select class="form-select" id="actionType" name="action_type">
            <option value="">Select action for entire transaction</option>
            <option value="void_all">Void Entire Transaction</option>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label">Reason / Remarks <span style="color:#ff4444;">*</span></label>
          <textarea class="form-textarea" id="actionReason" name="reason" required
                    placeholder="Required — describe the reason for this action…"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-dark" onclick="closeActionModal()">Cancel</button>
        <button type="submit" class="btn btn-orange">Confirm Action</button>
      </div>
    </form>
  </div>
</div>

<script>
/* ── Embedded transaction cache (set once on page load by PHP) ── */
const TXN_CACHE = <?= json_encode($allTransactionsData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

/* ─── DRAG FUNCTIONALITY FOR MODALS ──────────────────────────────── */
function makeDraggable(modalElement, handleElement) {
  let isDragging = false;
  let startX, startY, initialX, initialY;
  
  const dragHandle = handleElement || modalElement;
  
  dragHandle.addEventListener('mousedown', function(e) {
    if (e.target.closest('button') || e.target.closest('input') || e.target.closest('select') || e.target.closest('textarea')) {
      return;
    }
    
    isDragging = true;
    
    const rect = modalElement.getBoundingClientRect();
    startX = e.clientX;
    startY = e.clientY;
    initialX = rect.left;
    initialY = rect.top;
    
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

/* ─── Initialize drag for modals ─────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
  // Make action modal draggable
  const actionModalBox = document.getElementById('actionModalBox');
  const actionModalHandle = document.getElementById('actionModalHandle');
  if (actionModalBox && actionModalHandle) {
    makeDraggable(actionModalBox, actionModalHandle);
  }
  
  // Make reference popup draggable
  const refPopupBox = document.getElementById('refPopupBox');
  if (refPopupBox) {
    makeDraggable(refPopupBox, refPopupBox);
  }
});

/* ─── Cache indicator helpers ─── */
function setCacheStatus(state, label){
  const el = document.getElementById('cacheIndicator');
  const lb = document.getElementById('cacheLabel');
  el.className = 'cache-indicator' + (state !== 'ok' ? ' ' + state : '');
  lb.textContent = label;
}

/* ─── Flash PHP messages ─── */
window.addEventListener('DOMContentLoaded',function(){
  const sm=document.getElementById('php-success-msg');
  const em=document.getElementById('php-error-msg');
  if(sm) Swal.fire({icon:'success',title:'Success',text:sm.dataset.msg,timer:3000,timerProgressBar:true,confirmButtonColor:'#ff6600'});
  if(em) Swal.fire({icon:'error',title:'Error',text:em.dataset.msg,confirmButtonColor:'#ff6600'});

  const count = Object.keys(TXN_CACHE).length;
  setCacheStatus('ok', count + ' txn' + (count!==1?'s':'') + ' cached');
});

/* ─── Connectivity ─── */
function checkConn(){
  fetch('../record/ping.php',{cache:'no-store'})
    .then(r=>{
      const el=document.getElementById('connStatus');
      el.textContent=r.ok?'● ONLINE':'● OFFLINE';
      el.className=r.ok?'stat-online':'stat-offline';
    })
    .catch(()=>{
      const el=document.getElementById('connStatus');
      el.textContent='● OFFLINE';
      el.className='stat-offline';
    });
}
setInterval(checkConn,15000); checkConn();

/* ─── HTML escape helper ─── */
function esc(s){
  if(!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

/* ─── Reference number popup ─── */
function showRef(element){
  const iconType = element.dataset.refIcon;
  document.getElementById('refIcon').innerHTML = getPaymentIcon(iconType);
  document.getElementById('refName').textContent   = element.dataset.refLabel;
  document.getElementById('refNumber').textContent = element.dataset.refNumber;
  document.getElementById('refPopup').classList.add('show');
}
function closeRef(){
  document.getElementById('refPopup').classList.remove('show');
  const refPopupBox = document.getElementById('refPopupBox');
  if (refPopupBox) {
    refPopupBox.style.position = '';
    refPopupBox.style.left = '';
    refPopupBox.style.top = '';
    refPopupBox.style.margin = '';
  }
}

function getPaymentIcon(iconType) {
  if (iconType === 'cash') {
    return '<svg viewBox="0 0 24 24" width="32" height="32" fill="#66dd88"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/></svg>';
  }
  return '<svg viewBox="0 0 24 24" width="32" height="32" fill="#ffcc66"><path d="M21 18v1c0 1.1-.9 2-2 2H5c-1.11 0-2-.9-2-2V5c0-1.1.89-2 2-2h14c1.1 0 2 .9 2 2v1h-9c-1.11 0-2 .9-2 2v8c0 1.1.89 2 2 2h9zm-9-2h10V8H12v8zm4-2.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>';
}

/* ─── Action modal ─── */
function closeActionModal(){ 
  document.getElementById('actionModal').classList.remove('show'); 
  const modalBox = document.getElementById('actionModalBox');
  if (modalBox) {
    modalBox.style.position = '';
    modalBox.style.left = '';
    modalBox.style.top = '';
    modalBox.style.margin = '';
  }
}
document.getElementById('actionModal').addEventListener('click',function(e){ if(e.target===this) closeActionModal(); });

document.querySelectorAll('.action-btn').forEach(btn=>{
  btn.addEventListener('click', function(){
    const txnId = this.dataset.transactionId;

    const cached = TXN_CACHE[txnId];
    if (!cached) {
      Swal.fire({icon:'error',title:'Cache miss',text:'Transaction #'+txnId+' not found in cache. Please refresh the page.',confirmButtonColor:'#ff6600'});
      return;
    }

    document.getElementById('actionTransactionId').value = txnId;
    document.getElementById('modalTxnId').textContent    = '#' + txnId;
    document.getElementById('originalItems').value       = cached.original_items || '[]';
    document.getElementById('actionReason').value        = '';
    document.getElementById('actionType').value          = '';
    document.getElementById('warningBanner').style.display = 'none';

    try {
      const items     = JSON.parse(cached.items);
      const origItems = JSON.parse(cached.original_items || '[]');
      const tbody     = document.getElementById('itemsTableBody');
      tbody.innerHTML = '';
      let total = 0;

      items.forEach((item, idx) => {
        const orig      = origItems[idx] || {};
        const itTotal   = (item.qty||0) * (item.price||0);
        total          += itTotal;
        const tr        = document.createElement('tr');
        const isVoided  = item.status === 'voided';
        tr.innerHTML = `
          <td><input type="text" class="form-input" name="items[${idx}][name]"
                value="${esc(item.name||'')}" ${isVoided?'readonly':''}></td>
          <td>
            <input type="number" class="form-input qty-input" name="items[${idx}][qty]"
                   min="0" step="0.01" value="${item.qty||0}" ${isVoided?'readonly':''} style="width:70px;">
            <input type="hidden" name="items[${idx}][unit]" value="${esc(item.unit||'')}">
          </td>
          <td><input type="number" class="form-input price-input" name="items[${idx}][price]"
                min="0" step="0.01" value="${item.price||0}" ${isVoided?'readonly':''} style="width:80px;"></td>
          <td style="text-align:right;color:#66dd88;">₱${itTotal.toFixed(2)}</td>
          <td>
            <select class="form-select item-action" name="items[${idx}][action]" style="min-width:90px;">
              <option value="">No action</option>
              <option value="void" ${isVoided?'selected':''}>Void</option>
              <option value="edit">Edit</option>
            </select>
            <input type="hidden" name="items[${idx}][id]"     value="${esc(item.id||'')}">
            <input type="hidden" name="items[${idx}][status]" value="${esc(item.status||'')}">
          </td>`;
        tbody.appendChild(tr);
      });

      document.getElementById('totalBar').style.display = 'flex';
      document.getElementById('newTotal').textContent   = '₱' + total.toFixed(2);

      tbody.querySelectorAll('.qty-input,.price-input').forEach(inp =>
        inp.addEventListener('input', calcNewTotal)
      );

      document.getElementById('actionModal').classList.add('show');

    } catch(err) {
      Swal.fire({icon:'error',title:'Parse Error',text:'Failed to load items from cache: '+err.message,confirmButtonColor:'#ff6600'});
    }
  });
});

function calcNewTotal(){
  let t = 0;
  document.querySelectorAll('#itemsTableBody tr').forEach(row => {
    const qty   = parseFloat(row.querySelector('.qty-input').value)   || 0;
    const price = parseFloat(row.querySelector('.price-input').value) || 0;
    const act   = row.querySelector('.item-action').value;
    if (act !== 'void') t += qty * price;
    const tc = row.querySelector('td:nth-child(4)');
    if (tc) tc.textContent = '₱' + (qty*price).toFixed(2);
  });
  document.getElementById('newTotal').textContent = '₱' + t.toFixed(2);
}

/* ─── Action form submit ─── */
document.getElementById('actionForm').addEventListener('submit', async function(e){
  e.preventDefault();
  const submitBtn = this.querySelector('button[type="submit"]');
  const origText  = submitBtn.innerHTML;
  submitBtn.innerHTML = 'Processing...';
  submitBtn.disabled  = true;

  const formData = {
    transaction_id : document.getElementById('actionTransactionId').value,
    action_type    : document.getElementById('actionType').value,
    reason         : document.getElementById('actionReason').value,
    original_items : document.getElementById('originalItems').value,
    items          : []
  };

  if (!formData.reason.trim()) {
    await Swal.fire({icon:'error',title:'Reason Required',text:'Please provide a reason.',confirmButtonColor:'#ff6600'});
    submitBtn.innerHTML = origText;
    submitBtn.disabled  = false;
    return;
  }

  document.querySelectorAll('#itemsTableBody tr').forEach(row => {
    const act = row.querySelector('[name$="[action]"]').value;
    formData.items.push({
      id    : row.querySelector('[name$="[id]"]')?.value    || '',
      name  : row.querySelector('[name$="[name]"]')?.value  || '',
      qty   : parseFloat(row.querySelector('[name$="[qty]"]')?.value)   || 0,
      price : parseFloat(row.querySelector('[name$="[price]"]')?.value) || 0,
      unit  : row.querySelector('[name$="[unit]"]')?.value  || '',
      action: act,
      status: act === 'void' ? 'voided' : ''
    });
  });

  const actionLabel = formData.action_type === 'void_all' ? 'VOID ENTIRE TRANSACTION' : 'MODIFY TRANSACTION';
  const conf = await Swal.fire({
    title:'Confirm Action',
    html:`<strong>This cannot be undone!</strong><br><br>Action: <strong>${actionLabel}</strong><br>Transaction: <strong>#${formData.transaction_id}</strong>`,
    icon:'warning', showCancelButton:true,
    confirmButtonColor:'#ff8800', cancelButtonColor:'#555',
    confirmButtonText:'Yes, proceed', cancelButtonText:'Cancel', focusCancel:true
  });

  if (!conf.isConfirmed) {
    submitBtn.innerHTML = origText;
    submitBtn.disabled  = false;
    return;
  }

  setCacheStatus('refreshing', 'Saving...');

  try {
    const res = await fetch(this.action, {
      method  : 'POST',
      headers : {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
      body    : JSON.stringify(formData)
    });
    if (!res.ok) throw new Error('Network error (HTTP ' + res.status + ')');
    const data = await res.json();

    if (data.success) {
      closeActionModal();
      delete TXN_CACHE[formData.transaction_id];
      setCacheStatus('stale', 'Refreshing...');

      const done = formData.action_type === 'void_all' ? 'voided' : 'modified';
      await Swal.fire({
        icon:'success', title:'Done!',
        html:`Transaction <strong>#${formData.transaction_id}</strong> has been ${done}.<br><small>${formData.reason}</small>`,
        confirmButtonColor:'#ff8800', timer:2200, timerProgressBar:true,
        willClose:()=>location.reload()
      });
    } else {
      setCacheStatus('ok', Object.keys(TXN_CACHE).length + ' txns cached');
      await Swal.fire({icon:'error',title:'Failed',text:data.message||'Action failed.',confirmButtonColor:'#ff6600'});
    }
  } catch(err) {
    setCacheStatus('stale', 'Error — reload page');
    await Swal.fire({icon:'error',title:'Error',text:err.message,confirmButtonColor:'#ff6600'});
  }

  submitBtn.innerHTML = origText;
  submitBtn.disabled  = false;
});

/* ─── Auto-refresh cache every 30 seconds ─── */
(function startCacheRefresh(){
  const REFRESH_MS = 30_000;

  async function refreshCache(){
    if (document.getElementById('actionModal').classList.contains('show')) return;

    setCacheStatus('refreshing', 'Syncing...');
    try {
      const res = await fetch('get_transactions_json.php?date=today&_=' + Date.now(), {cache:'no-store'});
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      if (data && data.transactions) {
        let count = 0;
        data.transactions.forEach(txn => {
          TXN_CACHE[txn.id] = {
            id             : txn.id,
            items          : txn.items,
            original_items : txn.original_items || '[]',
            status         : txn.status || ''
          };
          count++;
        });
        setCacheStatus('ok', count + ' txn' + (count!==1?'s':'') + ' cached');
      } else {
        throw new Error('Bad response');
      }
    } catch(e) {
      const count = Object.keys(TXN_CACHE).length;
      setCacheStatus('ok', count + ' txn' + (count!==1?'s':'') + ' cached');
    }
  }

  setInterval(refreshCache, REFRESH_MS);
})();
</script>

<?php require_once __DIR__ . '/../include/footer.php'; ?>