<?php
// record.php - FIXED VERSION

// Start output buffering at the VERY beginning
ob_start();

// Check authentication FIRST (before any output)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get database connection
require_once __DIR__ . '/../dbconn.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: /Store/access_denied.php");
    exit();
}

// Check if payment columns exist
$hasPaymentMethod = false;
$hasReferenceNo   = false;
$chk1 = $conn->query("SHOW COLUMNS FROM transactions LIKE 'payment_method'");
if ($chk1 && $chk1->num_rows > 0) $hasPaymentMethod = true;
$chk2 = $conn->query("SHOW COLUMNS FROM transactions LIKE 'reference_no'");
if ($chk2 && $chk2->num_rows > 0) $hasReferenceNo = true;

// E-wallet color/icon map
$ewalletMeta = [
    'gcash'    => ['label'=>'GCash',    'color'=>'#0a6dff','icon'=>'💳'],
    'maya'     => ['label'=>'Maya',     'color'=>'#00b050','icon'=>'🎫'],
    'paymaya'  => ['label'=>'Maya',     'color'=>'#00b050','icon'=>'🎫'],
    'grabpay'  => ['label'=>'GrabPay',  'color'=>'#00b14f','icon'=>'💳'],
    'shopeepay'=> ['label'=>'ShopeePay','color'=>'#ee4d2d','icon'=>'💳'],
    'seabank'  => ['label'=>'SeaBank',  'color'=>'#2b4fff','icon'=>'🪪'],
    'coins'    => ['label'=>'Coins.ph', 'color'=>'#f5a623','icon'=>'💳'],
];

function getPaymentMeta($pm, $ewalletMeta) {
    if (!$pm || strtolower(trim($pm)) === 'cash' || trim($pm) === '') {
        return ['label'=>'Cash','color'=>'#2a6e1a','icon'=>'💵','type'=>'cash'];
    }
    $key = strtolower(str_replace([' ','-','_'],'',$pm));
    foreach ($ewalletMeta as $k => $meta) {
        if (strpos($key, $k) !== false) return array_merge($meta, ['type'=>'ewallet']);
    }
    return ['label'=>htmlspecialchars($pm),'color'=>'#7a5c00','icon'=>'📱','type'=>'ewallet'];
}

/* ═══════════ EXCEL EXPORT - MOVED TO TOP ══════════════════════════════════════════════ */
if (isset($_POST['export'])) {
    // Clear any output buffers
    ob_end_clean();
    
    header('Content-Type: application/vnd.ms-excel');
    $exportType = $_POST['export'] === 'all' ? 'ALL_TRANSACTIONS' : 'FILTERED_TRANSACTIONS';
    $filename = 'transaction_records_' . date('Y-m-d') . ($_POST['export'] !== 'all' ? '_filtered' : '_all') . '.xls';
    header('Content-Disposition: attachment;filename=' . $filename);
    
    // Get filter values from POST
    $filterDate    = $_POST['date'] ?? '';
    $filterMonth   = $_POST['month'] ?? '';
    $filterYear    = $_POST['year'] ?? '';
    $filterTransId = $_POST['trans_id'] ?? '';

    // Get business settings for the report header
    $settings = [];
    $businessName = 'Cozy Corner Café';
    try {
        $settingsQuery = "SELECT setting_key, setting_value FROM system_settings";
        $settingsResult = $conn->query($settingsQuery);
        if ($settingsResult) {
            while ($setting = $settingsResult->fetch_assoc()) {
                $settings[$setting['setting_key']] = $setting['setting_value'];
            }
            $businessName = $settings['business_name'] ?? 'Cozy Corner Café';
        }
    } catch (Exception $e) {
        // Use default
    }

    $filterInfo = '';
    if ($_POST['export'] !== 'all') {
        $filters = [];
        if (!empty($filterDate))    $filters[] = 'Date: ' . $filterDate;
        if (!empty($filterTransId)) $filters[] = 'Transaction #: ' . $filterTransId;
        if (!empty($filterMonth))   $filters[] = 'Month: ' . date('F', mktime(0,0,0,$filterMonth,1));
        if (!empty($filterYear))    $filters[] = 'Year: ' . $filterYear;
        $filterInfo = !empty($filters) ? ' (Filtered: ' . implode(', ', $filters) . ')' : ' (Filtered)';
    } else {
        $filterInfo = ' (All Transactions)';
    }

    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        body{font-family:Arial,sans-serif;}
        .title{font-size:16pt;font-weight:bold;text-align:center;color:#2c3e50;}
        .subtitle{font-size:12pt;text-align:center;margin-bottom:5px;color:#555;}
        .report-type{font-size:10pt;text-align:center;margin-bottom:15px;color:#ff6600;font-weight:bold;}
        th{background:#2c3e50;color:white;padding:8px;border:1px solid #ddd;}
        td{padding:6px;border:1px solid #ddd;}
        .items-table th{background:#f5f5f5;color:black;}
        .totals{background:#f0f0f0;font-weight:bold;}
        .footer{text-align:center;font-style:italic;margin-top:20px;color:#888;}
    </style></head><body>
    <div class="title">' . htmlspecialchars($businessName) . '</div>
    <div class="subtitle">TRANSACTION RECORDS REPORT</div>
    <div class="report-type">' . htmlspecialchars($filterInfo) . '</div>
    <div style="text-align:right;font-style:italic;margin-bottom:15px;">Generated: ' . date("F j, Y") . ' at ' . date("g:i A") . '</div>';

    echo '<table cellspacing="0"><thead><tr>
        <th>Trans #</th><th>Cashier</th><th>Date</th><th>Time</th>
        <th>Payment</th><th>Total</th><th>Paid</th><th>Change</th><th>Items</th>
    </tr></thead><tbody>';

    $q = "SELECT * FROM transactions WHERE (status IS NULL OR status != 'voided')";
    $params=[]; $types='';
    if ($_POST['export'] !== 'all') {
        if (!empty($filterDate))    { $q .= " AND date=?";        $params[] = $filterDate;            $types .= 's'; }
        if (!empty($filterTransId)) { $q .= " AND id = ?";        $params[] = intval($filterTransId); $types .= 'i'; }
        if (!empty($filterMonth))   { $q .= " AND MONTH(date)=?"; $params[] = intval($filterMonth);    $types .= 'i'; }
        if (!empty($filterYear))    { $q .= " AND YEAR(date)=?";  $params[] = intval($filterYear);     $types .= 'i'; }
    }
    $q .= " ORDER BY id DESC";
    $stmt = $conn->prepare($q);
    if(!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $tS=0; $tP=0; $tC=0; $tPcs=0; $tKg=0;

    while($row = $res->fetch_assoc()){
        $its = json_decode($row['items'], true); $fi = '';
        if(is_array($its)){
            $fi = '<table class="items-table" width="100%"><thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody>';
            foreach($its as $it){
                $n = htmlspecialchars($it['name'] ?? ''); 
                $qty = $it['qty'] ?? 0; 
                $pr = $it['price'] ?? 0;
                $fi .= '<tr><td>'.$n.'</td><td>'.$qty.'</td><td>&#8369;'.number_format($pr,2).'</td><td>&#8369;'.number_format($qty*$pr,2).'</td></tr>';
                if(($it['measurement_type'] ?? '') === 'kg') $tKg += (float)$qty; else $tPcs += (int)$qty;
            }
            $fi .= '</tbody></table>';
        }
        $tS += $row['total']; $tP += $row['paid']; $tC += $row['change_due'];
        $pm  = $hasPaymentMethod ? ($row['payment_method'] ?? 'Cash') : 'Cash';
        $ref = $hasReferenceNo   ? ($row['reference_no']   ?? '')      : '';
        if(!$pm) $pm = 'Cash';
        $pmDisplay = htmlspecialchars(strtoupper($pm)) . ($ref ? ' (Ref: '.htmlspecialchars($ref).')' : '');
        echo '<tr>
                <td style="text-align:center;">' . $row['id'] . '</td>
                <td>' . htmlspecialchars($row['cashier_name']) . '</td>
                <td>' . date("F j, Y", strtotime($row['date'])) . '</td>
                <td>' . date("g:i A", strtotime($row['time'])) . '</td>
                <td>' . $pmDisplay . '</td>
                <td style="text-align:right;">&#8369;' . number_format($row['total'],2) . '</td>
                <td style="text-align:right;">&#8369;' . number_format($row['paid'],2) . '</td>
                <td style="text-align:right;">&#8369;' . number_format($row['change_due'],2) . '</td>
                <td>' . $fi . '</td>
              </tr>';
    }
    $kgDisplay = $tKg == floor($tKg) ? (int)$tKg : rtrim(rtrim(number_format($tKg,3),'0'),'.');
    echo '<tr class="totals">
            <td colspan="5" style="text-align:right;"><strong>TOTALS:</strong></td>
            <td style="text-align:right;"><strong>&#8369;' . number_format($tS,2) . '</strong></td>
            <td style="text-align:right;"><strong>&#8369;' . number_format($tP,2) . '</strong></td>
            <td style="text-align:right;"><strong>&#8369;' . number_format($tC,2) . '</strong></td>
            <td><strong>' . $tPcs . ' pc / ' . $kgDisplay . ' kg</strong></td>
          </tr></tbody></table>';
    echo '<div class="footer">End of Report - Generated on ' . date("F j, Y g:i A") . '</div></body></html>';
    exit;
}

/* ═══════════ PDF EXPORT - MOVED TO TOP ════════════════════════════════════════════════ */
if (isset($_GET['export']) && in_array($_GET['export'],['pdf','pdf_all'])) {
    // Clear any output buffers
    ob_end_clean();
    
    require(__DIR__ . '/../fpdf.php');
    
    // Get business settings for the report header
    $settings = [];
    $businessName = 'Cozy Corner Café';
    try {
        $settingsQuery = "SELECT setting_key, setting_value FROM system_settings";
        $settingsResult = $conn->query($settingsQuery);
        if ($settingsResult) {
            while ($setting = $settingsResult->fetch_assoc()) {
                $settings[$setting['setting_key']] = $setting['setting_value'];
            }
            $businessName = $settings['business_name'] ?? 'Cozy Corner Café';
        }
    } catch (Exception $e) {
        // Use default
    }
    
    $filterInfo = '';
    if ($_GET['export'] === 'pdf' && !empty($_GET)) {
        $filters = [];
        if (!empty($_GET['date'])) $filters[] = 'Date: ' . $_GET['date'];
        if (!empty($_GET['trans_id'])) $filters[] = 'Trans #: ' . $_GET['trans_id'];
        if (!empty($_GET['month'])) $filters[] = 'Month: ' . date('F', mktime(0,0,0,$_GET['month'],1));
        if (!empty($_GET['year'])) $filters[] = 'Year: ' . $_GET['year'];
        $filterInfo = !empty($filters) ? 'Filtered: ' . implode(', ', $filters) : '';
    } else {
        $filterInfo = 'All Transactions';
    }
    $q="SELECT * FROM transactions WHERE (status IS NULL OR status != 'voided')";
    if($_GET['export']==='pdf'){
        if(!empty($_GET['date']))     $q.=" AND date='".$conn->real_escape_string($_GET['date'])."'";
        if(!empty($_GET['trans_id'])) $q.=" AND id = ".intval($_GET['trans_id']);
        if(!empty($_GET['month']))    $q.=" AND MONTH(date)=".intval($_GET['month']);
        if(!empty($_GET['year']))     $q.=" AND YEAR(date)=".intval($_GET['year']);
    }
    $q.=" ORDER BY id DESC";
    $res=$conn->query($q);
    $tS=0;$tP=0;$tC=0;$tPcs=0;$tKg=0.0;
    $tmp=$conn->query($q);
    while($r=$tmp->fetch_assoc()){
        $tS+=$r['total'];$tP+=$r['paid'];$tC+=$r['change_due'];
        $its=json_decode($r['items'],true);
        if(is_array($its)) foreach($its as $it){
            $qty=$it['qty']??0;
            if(($it['measurement_type']??'')==='kg') $tKg+=(float)$qty; else $tPcs+=(int)$qty;
        }
    }
    $pdf = new FPDF(); $pdf->AddPage();
    $pdf->SetFont('Arial','B',16); $pdf->Cell(0,10, htmlspecialchars($businessName), 0,1,'C');
    $pdf->SetFont('Arial','B',12); $pdf->Cell(0,8,'TRANSACTION RECORDS REPORT', 0,1,'C');
    $pdf->SetFont('Arial','I',10); $pdf->Cell(0,6, $filterInfo, 0,1,'C');
    $pdf->SetFont('Arial','',9);   $pdf->Cell(0,6,'Generated: ' . date("F j, Y") . ' at ' . date("g:i A"), 0,1,'R');
    $pdf->Ln(5);
    $pdf->SetFont('Arial','B',9);
    $pdf->Cell(15,8,'Trans #',1); $pdf->Cell(35,8,'Cashier',1); $pdf->Cell(25,8,'Date',1);
    $pdf->Cell(20,8,'Time',1);    $pdf->Cell(25,8,'Payment',1); $pdf->Cell(22,8,'Total',1);
    $pdf->Cell(22,8,'Paid',1);    $pdf->Cell(20,8,'Change',1);  $pdf->Ln();
    $pdf->SetFont('Arial','',8);
    $res->data_seek(0);
    while($row=$res->fetch_assoc()){
        $pm  = $hasPaymentMethod ? ($row['payment_method'] ?? 'Cash') : 'Cash';
        $ref = $hasReferenceNo   ? ($row['reference_no']   ?? '')      : '';
        if(!$pm) $pm='Cash';
        $pmStr = strtoupper($pm) . ($ref ? ' #' . substr($ref,0,10) : '');
        $pdf->Cell(15,8,$row['id'],1); $pdf->Cell(35,8,substr($row['cashier_name'],0,20),1);
        $pdf->Cell(25,8,date("M j, Y",strtotime($row['date'])),1); $pdf->Cell(20,8,date("g:i A",strtotime($row['time'])),1);
        $pdf->Cell(25,8,$pmStr,1);
        $pdf->Cell(22,8,'PHP ' . number_format($row['total'],2),1,0,'R');
        $pdf->Cell(22,8,'PHP ' . number_format($row['paid'],2),1,0,'R');
        $pdf->Cell(20,8,'PHP ' . number_format($row['change_due'],2),1,0,'R'); $pdf->Ln();
        $its=json_decode($row['items'],true);
        if(is_array($its) && count($its)>0){
            $pdf->SetFont('Arial','',7);
            foreach($its as $it){
                $nm=substr($it['name']??'',0,25); $qty=$it['qty']??0; $pr=$it['price']??0;
                $pdf->Cell(15,4,'',0);
                $pdf->Cell(160,4,"- ".$nm." x ".$qty." @ PHP ".number_format($pr,2)." = PHP ".number_format($qty*$pr,2),0,1);
            }
            $pdf->SetFont('Arial','',8); $pdf->Ln(2);
        }
    }
    $kgDisplay = $tKg == floor($tKg) ? (int)$tKg : rtrim(rtrim(number_format($tKg,3),'0'),'.');
    $pdf->SetFont('Arial','B',9);
    $pdf->Cell(95,8,'TOTALS:',1,0,'R');
    $pdf->Cell(22,8,'PHP ' . number_format($tS,2),1,0,'R');
    $pdf->Cell(22,8,'PHP ' . number_format($tP,2),1,0,'R');
    $pdf->Cell(20,8,'PHP ' . number_format($tC,2),1,0,'R'); $pdf->Ln();
    $pdf->Cell(95,8,'',0);
    $pdf->Cell(66,8,'Total Items: ' . $tPcs . ' pcs / ' . $kgDisplay . ' kg',1,1,'C');
    $pdf->Output('D','Transaction_Report_' . date('Ymd_His') . ($_GET['export']==='pdf'?'_filtered':'_all') . '.pdf');
    exit;
}

// If we get here, it's not an export, so include the admin header
$pageTitle = 'Records';
require_once __DIR__ . '/../include/admin_header.php';

$ipp = 10;

// Active transactions
$pg  = max(1, (int)($_GET['page']??1));
$off = ($pg-1)*$ipp;
$fq  = "SELECT * FROM transactions WHERE (status IS NULL OR status != 'voided')";
if(!empty($_GET['date']))     $fq.=" AND date='".$conn->real_escape_string($_GET['date'])."'";
if(!empty($_GET['trans_id'])) $fq.=" AND id = ".intval($_GET['trans_id']);
if(!empty($_GET['month']))    $fq.=" AND MONTH(date)=".intval($_GET['month']);
if(!empty($_GET['year']))     $fq.=" AND YEAR(date)=".intval($_GET['year']);
$fq.=" ORDER BY id DESC";

$totalRows  = $conn->query(str_replace("SELECT *","SELECT COUNT(*) as c",$fq))->fetch_assoc()['c'];
$totalPages = ceil($totalRows/$ipp);
$result     = $conn->query($fq." LIMIT $ipp OFFSET $off");

// Totals + CACHE BUILD for active transactions
$totalSales=0; $totalPaid=0; $totalChange=0; $totalPiecesSold=0; $totalKgSold=0;
$productTotals=[];
$cashCount=0; $ewalletCount=0; $ewalletBreakdown=[];
$activeTxnCache = [];

$all = $conn->query($fq);
while($row=$all->fetch_assoc()){
    if($row['status']==='voided') continue;
    $totalSales+=$row['total']; $totalPaid+=$row['paid']; $totalChange+=$row['change_due'];
    $pm = $hasPaymentMethod ? strtolower(trim($row['payment_method']??'cash')) : 'cash';
    if(!$pm) $pm='cash';
    if($pm==='cash'){ $cashCount++; } else { $ewalletCount++; $ewalletBreakdown[$pm] = ($ewalletBreakdown[$pm]??0)+1; }
    $its=json_decode($row['items'],true);
    if(is_array($its)) foreach($its as $it){
        if(($it['status']??'')==='voided') continue;
        $nm=$it['name']??'Unnamed'; $qty=$it['qty']??0; $pr=$it['price']??0;
        if(!isset($productTotals[$nm])) $productTotals[$nm]=['quantity'=>0,'revenue'=>0,'unit'=>$it['measurement_type']??'pc'];
        $productTotals[$nm]['quantity']+=$qty; $productTotals[$nm]['revenue']+=($qty*$pr);
        if(($it['measurement_type']??'')==='kg') $totalKgSold+=(float)$qty; else $totalPiecesSold+=(int)$qty;
    }

    $pmRaw  = $hasPaymentMethod ? ($row['payment_method'] ?? 'Cash') : 'Cash';
    $refRaw = $hasReferenceNo   ? ($row['reference_no']   ?? '')      : '';
    if(!$pmRaw) $pmRaw = 'Cash';
    $meta = getPaymentMeta($pmRaw, $ewalletMeta);
    $activeTxnCache[$row['id']] = [
        'id'             => $row['id'],
        'cashier'        => $row['cashier_name'],
        'date'           => $row['date'],
        'items'          => $row['items'],
        'original_items' => $row['original_items'] ?? '[]',
        'status'         => $row['status'] ?? '',
        'edited_by'      => $row['edited_by'] ?? '',
        'edited_at'      => $row['edited_at'] ?? '',
        'edit_remarks'   => $row['edit_remarks'] ?? '',
        'pm_label'       => $meta['label'],
        'pm_icon'        => $meta['icon'],
        'pm_color'       => $meta['color'],
        'pm_type'        => $meta['type'],
        'pm_ref'         => $refRaw,
    ];
}
uasort($productTotals,fn($a,$b)=>$b['revenue']<=>$a['revenue']);

// Voided
$vpg  = max(1,(int)($_GET['voided_page']??1));
$voff = ($vpg-1)*$ipp;
$voidedTotal  = $conn->query("SELECT COUNT(*) as c FROM transactions WHERE status='voided'")->fetch_assoc()['c'];
$voidedPages  = ceil($voidedTotal/$ipp);
$voidedStats  = $conn->query("SELECT COUNT(*) as cnt, COALESCE(SUM(total),0) as tot FROM transactions WHERE status='voided'")->fetch_assoc();
$voidedResult = $conn->query("SELECT * FROM transactions WHERE status='voided' ORDER BY voided_at DESC LIMIT $ipp OFFSET $voff");

$voidedTxnCache = [];
$vAll = $conn->query("SELECT * FROM transactions WHERE status='voided' ORDER BY voided_at DESC");
while($v = $vAll->fetch_assoc()){
    $vpmRaw  = $hasPaymentMethod ? ($v['payment_method'] ?? 'Cash') : 'Cash';
    $vrefRaw = $hasReferenceNo   ? ($v['reference_no']   ?? '')      : '';
    if(!$vpmRaw) $vpmRaw = 'Cash';
    $vmeta = getPaymentMeta($vpmRaw, $ewalletMeta);
    $voidedTxnCache[$v['id']] = [
        'id'          => $v['id'],
        'cashier'     => $v['cashier_name'],
        'date'        => $v['date'],
        'items'       => $v['items'],
        'status'      => 'voided',
        'total'       => $v['total'],
        'voided_by'   => $v['voided_by']   ?? '',
        'voided_at'   => $v['voided_at']   ?? '',
        'void_reason' => $v['void_reason'] ?? '',
        'pm_label'    => $vmeta['label'],
        'pm_icon'     => $vmeta['icon'],
        'pm_color'    => $vmeta['color'],
        'pm_type'     => $vmeta['type'],
        'pm_ref'      => $vrefRaw,
    ];
}

// Edited
$epg  = max(1,(int)($_GET['edited_page']??1));
$eoff = ($epg-1)*$ipp;
$editedTotal  = $conn->query("SELECT COUNT(*) as c FROM transactions WHERE edited_by IS NOT NULL")->fetch_assoc()['c'] ?? 0;
$editedPages  = ceil($editedTotal/$ipp);
$editedResult = $conn->query("SELECT * FROM transactions WHERE edited_by IS NOT NULL ORDER BY edited_at DESC LIMIT $ipp OFFSET $eoff");

$editedTxnCache = [];
$eAll = $conn->query("SELECT * FROM transactions WHERE edited_by IS NOT NULL ORDER BY edited_at DESC");
while($ed = $eAll->fetch_assoc()){
    $epmRaw  = $hasPaymentMethod ? ($ed['payment_method'] ?? 'Cash') : 'Cash';
    $erefRaw = $hasReferenceNo   ? ($ed['reference_no']   ?? '')      : '';
    if(!$epmRaw) $epmRaw = 'Cash';
    $emeta = getPaymentMeta($epmRaw, $ewalletMeta);
    $editedTxnCache[$ed['id']] = [
        'id'             => $ed['id'],
        'cashier'        => $ed['cashier_name'],
        'date'           => $ed['date'],
        'items'          => $ed['items'],
        'original_items' => $ed['original_items'] ?? '[]',
        'status'         => 'edited',
        'edited_by'      => $ed['edited_by']   ?? '',
        'edited_at'      => $ed['edited_at']   ?? '',
        'edit_remarks'   => $ed['edit_remarks'] ?? '',
        'pm_label'       => $emeta['label'],
        'pm_icon'        => $emeta['icon'],
        'pm_color'       => $emeta['color'],
        'pm_type'        => $emeta['type'],
        'pm_ref'         => $erefRaw,
    ];
}
?>

<style>
/* Page-specific styles */
.page-hero{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;}
.hero-left h2{font-size:18px;font-weight:800;color:var(--text);}
.hero-left p{font-size:11px;color:var(--text3);margin-top:2px;}
.hero-right{display:flex;align-items:center;gap:8px;}
.cache-pill{display:inline-flex;align-items:center;gap:5px;background:rgba(0,200,83,.08);border:1px solid rgba(0,200,83,.18);border-radius:20px;padding:3px 10px;font-size:10px;color:#4dff88;transition:all .3s;cursor:default;}
.cache-pill.stale{background:rgba(255,204,0,.08);border-color:rgba(255,204,0,.2);color:var(--yellow);}
.cache-pill.syncing{background:rgba(68,136,255,.08);border-color:rgba(68,136,255,.2);color:#88bbff;}
.cache-pill .cp-dot{width:5px;height:5px;border-radius:50%;background:currentColor;display:inline-block;}
.cache-pill.syncing .cp-dot{animation:cpulse .7s infinite;}
@keyframes cpulse{0%,100%{opacity:1}50%{opacity:.25}}

.tab-nav{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px;}
.tab-btn{display:flex;align-items:center;gap:7px;padding:9px 18px;border-radius:8px;border:1px solid var(--border);background:var(--card);color:var(--text2);cursor:pointer;font-size:12px;font-weight:600;transition:all .2s;}
.tab-btn:hover{background:var(--card2);border-color:var(--border2);color:var(--text);}
.tab-btn.active{background:linear-gradient(135deg,var(--orange),var(--orange-dk));border-color:var(--orange);color:white;box-shadow:0 4px 16px rgba(255,136,0,.25);}
.tab-btn .tb-count{background:rgba(255,255,255,.18);color:white;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:700;}
.tab-btn:not(.active) .tb-count{background:var(--bg3);color:var(--text3);}
.tb-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.tb-dot.orange{background:var(--orange);box-shadow:0 0 5px var(--orange);}
.tb-dot.red{background:var(--red);box-shadow:0 0 5px var(--red);}
.tb-dot.yellow{background:var(--yellow);box-shadow:0 0 5px var(--yellow);}
.tab-panel{display:none;animation:fadein .2s ease;}
.tab-panel.active{display:block;}
@keyframes fadein{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:none;}}

.panel{background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:14px;}
.panel-header{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:linear-gradient(90deg,var(--card2),var(--card));}
.panel-title{font-size:12px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:7px;}
.pt-dot{width:7px;height:7px;border-radius:50%;}
.pt-dot.orange{background:var(--orange);box-shadow:0 0 5px var(--orange);}
.pt-dot.red{background:var(--red);box-shadow:0 0 5px var(--red);}
.pt-dot.yellow{background:var(--yellow);box-shadow:0 0 5px var(--yellow);}
.panel-body{padding:14px;}
.panel-body.no-pad{padding:0;}

.filter-strip{background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:12px 14px;margin-bottom:14px;display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;}
.filter-strip label{font-size:10px;color:var(--text3);font-weight:600;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px;}
.f-input{background:var(--card2);border:1.5px solid var(--border);color:var(--text);border-radius:5px;padding:6px 10px;font-size:12px;transition:border-color .15s;}
.f-input:focus{outline:none;border-color:var(--orange);background:var(--card);}
.f-input option{background:var(--card2);}
.export-strip{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;}

.sum-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:14px;}
.sum-tile{background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:12px 14px;transition:border-color .15s;}
.sum-tile:hover{border-color:var(--orange);}
.st-label{font-size:9px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;margin-bottom:5px;}
.st-val{font-size:18px;font-weight:900;}
.st-val.orange{color:var(--orange-lt);}.st-val.green{color:#4dff88;}.st-val.blue{color:#88bbff;}.st-val.red{color:#ff8888;}.st-val.yellow{color:var(--yellow);}

.tbl-wrap{overflow-x:auto;}.tbl-wrap::-webkit-scrollbar{height:3px;}.tbl-wrap::-webkit-scrollbar-thumb{background:var(--border2);}
.data-tbl{width:100%;border-collapse:collapse;min-width:800px;}
.data-tbl thead tr{background:linear-gradient(90deg,var(--orange),var(--orange-dk));}
.data-tbl thead th{padding:9px 12px;font-size:10px;font-weight:700;color:rgba(255,255,255,.9);text-transform:uppercase;letter-spacing:.8px;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);}
.data-tbl thead th:last-child{border-right:none;}
.data-tbl tbody tr{border-bottom:1px solid var(--border);transition:background .1s;}
.data-tbl tbody tr:hover{background:rgba(255,255,255,.025);}
.data-tbl tbody tr:last-child{border-bottom:none;}
.data-tbl tbody td{padding:8px 12px;font-size:11px;color:var(--text2);vertical-align:top;}
.td-id{background:rgba(255,136,0,.1);color:var(--orange-lt);border-radius:4px;padding:2px 6px;font-size:10px;font-weight:700;font-family:monospace;}
.td-money{color:#4dff88;font-weight:700;}
.td-paid{color:var(--yellow);font-weight:700;}
.td-change{color:#88bbff;font-weight:600;}
.td-date{color:var(--text3);font-size:10px;}
.td-name{color:var(--text);font-weight:500;}
.td-empty{text-align:center;color:var(--text3);padding:24px!important;}

.pay-badge{display:inline-flex;align-items:center;gap:4px;border-radius:5px;padding:3px 8px;font-size:11px;font-weight:700;white-space:nowrap;user-select:none;}
.pay-badge.clickable{cursor:pointer;transition:filter .15s;}
.pay-badge.clickable:hover{filter:brightness(1.2);}
.pay-badge .pay-ref-chip{font-size:9px;background:rgba(0,0,0,.25);border-radius:3px;padding:1px 4px;margin-left:2px;}

.audit-tag{display:inline-flex;align-items:center;gap:3px;border-radius:4px;padding:2px 7px;font-size:10px;font-weight:700;}
.a-ok{background:rgba(0,200,83,.12);color:#4dff88;border:1px solid rgba(0,200,83,.2);}
.a-voided{background:rgba(255,68,68,.12);color:#ff8888;border:1px solid rgba(255,68,68,.2);}
.a-edited{background:rgba(255,204,0,.12);color:var(--yellow);border:1px solid rgba(255,204,0,.2);}

.items-mini{max-height:130px;overflow-y:auto;}
.items-mini::-webkit-scrollbar{width:2px;}.items-mini::-webkit-scrollbar-thumb{background:var(--border2);}
.items-tbl{width:100%;border-collapse:collapse;font-size:10px;}
.items-tbl th{background:#1a1a2a;color:var(--text3);padding:2px 5px;font-weight:600;border-bottom:1px solid var(--border);}
.items-tbl td{padding:3px 5px;border-bottom:1px solid rgba(255,255,255,.04);color:var(--text2);}

.badge{display:inline-flex;align-items:center;gap:2px;padding:2px 7px;border-radius:20px;font-size:9px;font-weight:700;letter-spacing:.3px;}
.b-danger{background:rgba(255,68,68,.15);color:#ff8888;border:1px solid rgba(255,68,68,.2);}
.b-warning{background:rgba(255,204,0,.15);color:var(--yellow);border:1px solid rgba(255,204,0,.2);}
.b-success{background:rgba(0,200,83,.15);color:#66dd88;border:1px solid rgba(0,200,83,.2);}
.b-info{background:rgba(68,136,255,.15);color:#88bbff;border:1px solid rgba(68,136,255,.2);}
.b-orange{background:rgba(255,136,0,.15);color:var(--orange-lt);border:1px solid rgba(255,136,0,.2);}

.breakdown-grid{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;}
.bk-card{background:var(--bg3);border:1px solid var(--border);border-radius:7px;padding:9px 12px;min-width:130px;transition:border-color .15s;}
.bk-card:hover{border-color:var(--orange);}
.bk-name{font-size:11px;font-weight:700;color:var(--text);margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px;}
.bk-row{display:flex;justify-content:space-between;font-size:10px;margin-bottom:2px;}
.bk-label{color:var(--text3);}
.bk-val{color:var(--orange-lt);font-weight:600;}
.bk-rev{color:#4dff88;font-weight:700;}

.pager{display:flex;gap:4px;margin-top:12px;justify-content:center;}
.pg-btn{background:var(--card2);border:1px solid var(--border);color:var(--text2);border-radius:5px;padding:4px 10px;font-size:11px;text-decoration:none;transition:all .15s;}
.pg-btn:hover{background:var(--orange);border-color:var(--orange);color:white;}
.pg-btn.active{background:linear-gradient(135deg,var(--orange),var(--orange-dk));border-color:var(--orange);color:white;}

.btn{padding:5px 13px;border:none;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:4px;text-decoration:none;transition:all .15s;white-space:nowrap;}
.btn:hover{filter:brightness(1.1);transform:translateY(-1px);}
.btn-orange{background:linear-gradient(135deg,var(--orange),var(--orange-dk));color:white;}
.btn-red{background:linear-gradient(135deg,var(--red),#aa1111);color:white;}
.btn-dark{background:var(--bg3);border:1px solid var(--border);color:var(--text2);}
.btn-dark:hover{background:var(--border2);color:var(--text);filter:none;transform:none;}
.btn-green{background:linear-gradient(135deg,var(--green),#007a2e);color:white;}
.btn-blue{background:linear-gradient(135deg,var(--blue),#1a4fa0);color:white;}
.btn-yellow{background:linear-gradient(135deg,var(--yellow),#cc9900);color:#111;}
.btn-sm{padding:3px 9px;font-size:10px;}

/* Modal - No border-radius, no backdrop filter, draggable */
.modal-overlay{display:none;position:fixed;inset:0; z-index:9000;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal-box{background:var(--card2);border:1px solid var(--border2);border-radius:0;width:92%;max-width:700px;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 70px rgba(0,0,0,.8);cursor:grab;position:relative;}
.modal-box:active{cursor:grabbing;}
.modal-title-bar{background:linear-gradient(90deg,var(--orange),var(--orange-dk));padding:12px 16px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;cursor:grab;}
.modal-title-bar:active{cursor:grabbing;}
.modal-title-bar span{font-weight:700;font-size:13px;color:white;}
.mclose{background:rgba(0,0,0,.2);color:white;border:none;border-radius:0;width:26px;height:26px;font-size:14px;cursor:pointer;font-weight:700;display:flex;align-items:center;justify-content:center;}
.mclose:hover{background:rgba(0,0,0,.5);}
.modal-body{padding:16px;overflow-y:auto;flex:1;}
.modal-foot{padding:10px 16px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;flex-shrink:0;}

.ref-popup-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9500;align-items:center;justify-content:center;}
.ref-popup-overlay.show{display:flex;}
.ref-popup{background:var(--card2);border:2px solid var(--border2);border-radius:0;padding:22px 26px;min-width:290px;max-width:380px;box-shadow:0 16px 40px rgba(0,0,0,.7);text-align:center;cursor:grab;position:relative;}
.ref-popup:active{cursor:grabbing;}
.rp-icon{font-size:34px;margin-bottom:6px;}
.rp-label{font-size:10px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;}
.rp-name{font-size:20px;font-weight:900;color:var(--text);margin-bottom:14px;}
.rp-ref-label{font-size:10px;color:var(--text3);margin-bottom:5px;}
.rp-ref{font-size:16px;font-weight:700;color:var(--yellow);background:var(--bg3);border-radius:0;padding:8px 16px;letter-spacing:1.5px;display:inline-block;border:1px solid var(--border2);}
.rp-actions{display:flex;gap:8px;justify-content:center;margin-top:14px;}
</style>

<!-- MAIN -->
<div class="main" id="mainContent">

  <div class="page-hero">
    <div class="hero-left">
      <h2>📋 Transaction Records</h2>
      <p>Browse, filter, and manage all transaction data — including payment methods and e-wallet references.</p>
    </div>
    <div class="hero-right">
      <!-- Cache status indicator -->
      <span class="cache-pill" id="cachePill" title="In-memory transaction cache status">
        <span class="cp-dot"></span>
        <span id="cacheLabel">Loading…</span>
      </span>
    </div>
  </div>

  <!-- TABS -->
  <div class="tab-nav">
    <button class="tab-btn active" onclick="switchTab('active',this)">
      <div class="tb-dot orange"></div> Active Transaction
      <span class="tb-count"><?= number_format($totalRows) ?></span>
    </button>
    <button class="tab-btn" onclick="switchTab('voided',this)">
      <div class="tb-dot red"></div> Voided Transaction
      <span class="tb-count"><?= number_format($voidedTotal) ?></span>
    </button>
    <button class="tab-btn" onclick="switchTab('edited',this)">
      <div class="tb-dot yellow"></div> Edited Transaction
      <span class="tb-count"><?= number_format($editedTotal) ?></span>
    </button>
    <button class="tab-btn" onclick="switchTab('breakdown',this)">
      <div class="tb-dot orange"></div> Product Breakdown
      <span class="tb-count"><?= count($productTotals) ?></span>
    </button>
  </div>

  <!-- ══ TAB: ACTIVE ══════════════════════════════════════════════════════ -->
  <div class="tab-panel active" id="tab-active">
    <form method="get">
      <div class="filter-strip">
        <div><label>Date</label><input type="date" name="date" class="f-input" value="<?= htmlspecialchars($_GET['date']??'') ?>"></div>
        <div>
          <label>Month</label>
          <select name="month" class="f-input">
            <option value="">All Months</option>
            <?php $months=['January','February','March','April','May','June','July','August','September','October','November','December'];
            foreach($months as $i=>$m){ $n=$i+1; $sel=($_GET['month']??'')==$n?'selected':''; echo "<option value='$n' $sel>$m</option>"; } ?>
          </select>
        </div>
        <div>
          <label>Year</label>
          <select name="year" class="f-input">
            <option value="">All Years</option>
            <?php for($y=date('Y');$y>=2020;$y--){ $sel=($_GET['year']??'')==$y?'selected':''; echo "<option value='$y' $sel>$y</option>"; } ?>
          </select>
        </div>
        <div><label>Transaction #</label><input type="text" name="trans_id" class="f-input" placeholder="Search by ID..." value="<?= htmlspecialchars($_GET['trans_id']??'') ?>"></div>
        <button type="submit" class="btn btn-orange">🔍 Filter</button>
        <a href="record.php" class="btn btn-dark">✕ Clear</a>
      </div>
    </form>

    <div class="export-strip">
       <form method="post" action="" style="display:inline;">
        <input type="hidden" name="date"     value="<?= htmlspecialchars($_GET['date'] ?? '') ?>">
        <input type="hidden" name="month"    value="<?= htmlspecialchars($_GET['month'] ?? '') ?>">
        <input type="hidden" name="year"     value="<?= htmlspecialchars($_GET['year'] ?? '') ?>">
        <input type="hidden" name="trans_id" value="<?= htmlspecialchars($_GET['trans_id'] ?? '') ?>">
        <button type="submit" name="export" value="filtered" class="btn btn-green">📊 Export Filtered (Excel)</button>
    </form>
       <form method="post" action="" style="display:inline;">
        <button type="submit" name="export" value="all" class="btn btn-blue">📊 Export All (Excel)</button>
    </form>
      <?php
    $pdfFilteredUrl = '?export=pdf';
    if (!empty($_GET['date']))     $pdfFilteredUrl .= '&date=' . urlencode($_GET['date']);
    if (!empty($_GET['month']))    $pdfFilteredUrl .= '&month=' . urlencode($_GET['month']);
    if (!empty($_GET['year']))     $pdfFilteredUrl .= '&year=' . urlencode($_GET['year']);
    if (!empty($_GET['trans_id'])) $pdfFilteredUrl .= '&trans_id=' . urlencode($_GET['trans_id']);
    ?>
     <a href="<?= $pdfFilteredUrl ?>" class="btn btn-red">📄 Export Filtered (PDF)</a>
      <a href="?export=pdf_all" class="btn btn-yellow">📄 Export All (PDF)</a>
    </div>

    <div class="sum-grid">
      <div class="sum-tile"><div class="st-label">Total Sales</div><div class="st-val green">₱<?= number_format($totalSales,2) ?></div></div>
      <div class="sum-tile"><div class="st-label">Total Paid</div><div class="st-val yellow">₱<?= number_format($totalPaid,2) ?></div></div>
      <div class="sum-tile"><div class="st-label">Total Change</div><div class="st-val blue">₱<?= number_format($totalChange,2) ?></div></div>
      <div class="sum-tile"><div class="st-label">Pieces Sold</div><div class="st-val orange"><?= $totalPiecesSold ?> pcs</div></div>
      <?php if($totalKgSold>0): ?><div class="sum-tile"><div class="st-label">KG Sold</div><div class="st-val orange"><?= rtrim(rtrim(number_format($totalKgSold,3),'0'),'.') ?> kg</div></div><?php endif; ?>
      <div class="sum-tile"><div class="st-label">Transactions</div><div class="st-val orange"><?= number_format($totalRows) ?></div></div>
      <div class="sum-tile"><div class="st-label">💵 Cash</div><div class="st-val green"><?= $cashCount ?></div></div>
      <div class="sum-tile"><div class="st-label">📱 E-Wallet</div><div class="st-val blue"><?= $ewalletCount ?></div></div>
      <?php foreach($ewalletBreakdown as $ep=>$ec): ?>
      <div class="sum-tile"><div class="st-label"><?= htmlspecialchars(strtoupper($ep)) ?></div><div class="st-val blue"><?= $ec ?></div></div>
      <?php endforeach; ?>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><div class="pt-dot orange"></div>All Transactions (excl. voided)</div>
        <span style="font-size:10px;color:var(--text3);">Page <?= $pg ?> of <?= max(1,$totalPages) ?></span>
      </div>
      <div class="panel-body no-pad">
        <div class="tbl-wrap">
          <table class="data-tbl">
            <thead><tr><th>Trans #</th><th>Cashier</th><th>Date</th><th>Time</th><th>Payment</th><th>Total</th><th>Paid</th><th>Change</th><th>Items</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
              <?php $result->data_seek(0); while($row=$result->fetch_assoc()):
                $pm    = $hasPaymentMethod ? ($row['payment_method'] ?? 'Cash') : 'Cash';
                $refNo = $hasReferenceNo   ? ($row['reference_no']   ?? '')      : '';
                if(!$pm) $pm='Cash';
                $pmMeta   = getPaymentMeta($pm, $ewalletMeta);
                $hasRef   = $pmMeta['type']==='ewallet' && !empty($refNo);
                $wasEdited= !empty($row['edited_by']);
              ?>
              <tr>
                <td><span class="td-id"><?= $row['id'] ?></span></td>
                <td><span class="td-name"><?= htmlspecialchars($row['cashier_name']) ?></span></td>
                <td><span class="td-date"><?= date('M j, Y',strtotime($row['date'])) ?></span></td>
                <td><span class="td-date"><?= date('g:i A',strtotime($row['time'])) ?></span></td>
                <td>
                  <!-- Payment badge reads from JS cache on click — no heavy data-* needed -->
                  <span class="pay-badge <?= $hasRef?'clickable':'' ?>"
                        style="background:<?= $pmMeta['color'] ?>;color:white;"
                        data-txn-id="<?= $row['id'] ?>" data-src="active"
                        <?= $hasRef?'onclick="showRefFromCache(this)"':'' ?>
                        title="<?= $hasRef?'Click to view reference':'Payment: '.htmlspecialchars($pmMeta['label']) ?>">
                    <?= $pmMeta['icon'] ?> <?= htmlspecialchars($pmMeta['label']) ?>
                    <?php if($hasRef): ?><span class="pay-ref-chip">Ref #</span><?php endif; ?>
                  </span>
                </td>
                <td><span class="td-money">₱<?= number_format($row['total'],2) ?></span></td>
                <td><span class="td-paid">₱<?= number_format($row['paid'],2) ?></span></td>
                <td><span class="td-change">₱<?= number_format($row['change_due'],2) ?></span></td>
                <td>
                  <?php $its=json_decode($row['items'],true); if(is_array($its)): ?>
                  <div class="items-mini"><table class="items-tbl">
                    <tr><th>Item</th><th>Qty</th><th>Price</th><th>Sub</th></tr>
                    <?php foreach($its as $it):
                      $qty=(float)($it['qty']??0);$pr=(float)($it['price']??0);$st=$it['status']??'';
                    ?>
                    <tr>
                      <td><?= htmlspecialchars($it['name']??'') ?></td>
                      <td style="text-align:center;"><?= $st==='voided'?'<del>'.number_format($qty,2).'</del>':number_format($qty,2) ?> <?= htmlspecialchars($it['unit']??'') ?></td>
                      <td style="text-align:right;"><?= $st==='voided'?'<del>₱'.number_format($pr,2).'</del>':'₱'.number_format($pr,2) ?></td>
                      <td style="text-align:right;"><?= $st==='voided'?'<del>₱0.00</del>':'₱'.number_format($qty*$pr,2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </table></div>
                  <?php else: ?><span style="color:var(--text3);font-size:10px;">No items</span><?php endif; ?>
                </td>
                <td>
                  <?php if($wasEdited): ?>
                    <span class="audit-tag a-edited">✏ Edited</span>
                    <div style="color:var(--text3);font-size:10px;margin-top:3px;">
                      By <?= htmlspecialchars($row['edited_by']) ?><br>
                      <?= !empty($row['edited_at'])?date('M j g:i A',strtotime($row['edited_at'])):'' ?>
                    </div>
                  <?php else: ?>
                    <span class="audit-tag a-ok">✅ OK</span>
                  <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                  <a href="reprint.php?id=<?= $row['id'] ?>" target="_blank" class="btn btn-blue btn-sm">🖨 Print</a>
                  <?php if($row['status']!=='voided'): ?>
                  <!-- View Items — reads from JS cache, only carries the ID -->
                  <button class="btn btn-orange btn-sm view-items-btn"
                          data-txn-id="<?= $row['id'] ?>"
                          data-src="active">🔍 View</button>
                  <a href="void_transaction.php?id=<?= $row['id'] ?>" class="btn btn-red btn-sm" onclick="return confirmVoid(event)">🗑 Void</a>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endwhile; ?>
              <?php if($totalRows===0): ?><tr><td colspan="11" class="td-empty">No transactions found.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if($totalPages>1): ?>
        <div class="pager" style="padding:12px;">
          <?php
          $qp=''; foreach($_GET as $k=>$v) if($k!=='page') $qp.='&'.urlencode($k).'='.urlencode($v);
          if($pg>1) echo '<a class="pg-btn" href="?page='.($pg-1).$qp.'">‹</a>';
          for($i=max(1,$pg-2);$i<=min($totalPages,$pg+2);$i++) echo '<a class="pg-btn'.($i==$pg?' active':'').'" href="?page='.$i.$qp.'">'.$i.'</a>';
          if($pg<$totalPages) echo '<a class="pg-btn" href="?page='.($pg+1).$qp.'">›</a>';
          ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ══ TAB: VOIDED ══════════════════════════════════════════════════════ -->
  <div class="tab-panel" id="tab-voided">
    <div class="sum-grid">
      <div class="sum-tile"><div class="st-label">Voided Count</div><div class="st-val red"><?= $voidedStats['cnt'] ?></div></div>
      <div class="sum-tile"><div class="st-label">Total Voided Amount</div><div class="st-val red">₱<?= number_format($voidedStats['tot'],2) ?></div></div>
      <div class="sum-tile"><div class="st-label">Avg Voided</div><div class="st-val yellow">₱<?= $voidedStats['cnt']>0?number_format($voidedStats['tot']/$voidedStats['cnt'],2):'0.00' ?></div></div>
    </div>
    <div class="filter-strip">
      <div><label>Search</label><input type="text" id="voidedSearch" class="f-input" placeholder="Search voided…" style="width:220px;"></div>
      <div>
        <label>Time Period</label>
        <select id="voidedTimeFlt" class="f-input">
          <option value="all">All Time</option>
          <option value="today">Today</option>
          <option value="week">This Week</option>
          <option value="month">This Month</option>
          <option value="year">This Year</option>
        </select>
      </div>
    </div>
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><div class="pt-dot red"></div>Voided Transactions</div>
        <span style="font-size:10px;color:var(--text3);">Page <?= $vpg ?> of <?= max(1,$voidedPages) ?></span>
      </div>
      <div class="panel-body no-pad">
        <div class="tbl-wrap">
          <table class="data-tbl" id="voidedTbl">
            <thead><tr><th>Trans #</th><th>Cashier</th><th>Date</th><th>Payment</th><th>Amount</th><th>Voided By</th><th>Voided At</th><th>Reason</th><th>Actions</th></tr></thead>
            <tbody>
              <?php $voidedResult->data_seek(0); while($v=$voidedResult->fetch_assoc()):
                $vpm   = $hasPaymentMethod ? ($v['payment_method'] ?? 'Cash') : 'Cash';
                $vref  = $hasReferenceNo   ? ($v['reference_no']   ?? '')      : '';
                if(!$vpm) $vpm='Cash';
                $vpmMeta  = getPaymentMeta($vpm, $ewalletMeta);
                $vHasRef  = $vpmMeta['type']==='ewallet' && !empty($vref);
              ?>
              <tr data-voided-at="<?= $v['voided_at']??'' ?>">
                <td><span class="td-id"><?= $v['id'] ?></span></td>
                <td class="td-name"><?= htmlspecialchars($v['cashier_name']) ?></td>
                <td><span class="td-date"><?= date('M j, Y',strtotime($v['date'])) ?></span></td>
                <td>
                  <span class="pay-badge <?= $vHasRef?'clickable':'' ?>"
                        style="background:<?= $vpmMeta['color'] ?>;color:white;"
                        data-txn-id="<?= $v['id'] ?>" data-src="voided"
                        <?= $vHasRef?'onclick="showRefFromCache(this)"':'' ?>
                        title="<?= $vHasRef?'Click to view reference':'Payment' ?>">
                    <?= $vpmMeta['icon'] ?> <?= htmlspecialchars($vpmMeta['label']) ?>
                    <?php if($vHasRef): ?><span class="pay-ref-chip">Ref #</span><?php endif; ?>
                  </span>
                </td>
                <td><span class="td-money" style="color:#ff8888;">₱<?= number_format($v['total'],2) ?></span></td>
                <td style="color:var(--text2);"><?= htmlspecialchars($v['voided_by']??'') ?></td>
                <td><span class="td-date"><?= !empty($v['voided_at'])?date('M j, Y g:i A',strtotime($v['voided_at'])):'' ?></span></td>
                <td style="color:var(--text3);font-size:10px;max-width:150px;">
                  <?php $vr=trim($v['void_reason']??''); echo $vr?htmlspecialchars(mb_substr($vr,0,55).(mb_strlen($vr)>55?'…':'')):'<span style="color:var(--text3);">—</span>'; ?>
                </td>
                <td style="white-space:nowrap;">
                  <!-- View button reads from JS cache -->
                  <button class="btn btn-blue btn-sm view-items-btn"
                          data-txn-id="<?= $v['id'] ?>"
                          data-src="voided">🔍 View</button>
                  <button class="btn btn-red btn-sm delete-voided-btn" data-id="<?= $v['id'] ?>">🗑 Delete</button>
                </td>
              </tr>
              <?php endwhile; ?>
              <?php if($voidedTotal===0): ?><tr><td colspan="9" class="td-empty">No voided transactions.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if($voidedPages>1): ?>
        <div class="pager" style="padding:12px;">
          <?php if($vpg>1) echo '<a class="pg-btn" href="?voided_page='.($vpg-1).'">‹</a>';
          for($i=max(1,$vpg-2);$i<=min($voidedPages,$vpg+2);$i++) echo '<a class="pg-btn'.($i==$vpg?' active':'').'" href="?voided_page='.$i.'">'.$i.'</a>';
          if($vpg<$voidedPages) echo '<a class="pg-btn" href="?voided_page='.($vpg+1).'">›</a>'; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ══ TAB: EDITED ══════════════════════════════════════════════════════ -->
  <div class="tab-panel" id="tab-edited">
    <div class="sum-grid">
      <div class="sum-tile"><div class="st-label">Edited Records</div><div class="st-val yellow"><?= $editedTotal ?></div></div>
    </div>
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><div class="pt-dot yellow"></div>Edited Transactions</div>
        <span style="font-size:10px;color:var(--text3);">Page <?= $epg ?> of <?= max(1,$editedPages) ?></span>
      </div>
      <div class="panel-body no-pad">
        <div class="tbl-wrap">
          <table class="data-tbl">
            <thead><tr><th>Trans #</th><th>Cashier</th><th>Date</th><th>Payment</th><th>Amount</th><th>Edited By</th><th>Edited At</th><th>Remarks</th><th>Actions</th></tr></thead>
            <tbody>
              <?php if($editedResult && $editedTotal>0): $editedResult->data_seek(0); while($ed=$editedResult->fetch_assoc()):
                $epm   = $hasPaymentMethod ? ($ed['payment_method'] ?? 'Cash') : 'Cash';
                $eref  = $hasReferenceNo   ? ($ed['reference_no']   ?? '')      : '';
                if(!$epm) $epm='Cash';
                $epmMeta = getPaymentMeta($epm, $ewalletMeta);
                $eHasRef = $epmMeta['type']==='ewallet' && !empty($eref);
              ?>
              <tr>
                <td><span class="td-id"><?= $ed['id'] ?></span></td>
                <td class="td-name"><?= htmlspecialchars($ed['cashier_name']) ?></td>
                <td><span class="td-date"><?= date('M j, Y',strtotime($ed['date'])) ?></span></td>
                <td>
                  <span class="pay-badge <?= $eHasRef?'clickable':'' ?>"
                        style="background:<?= $epmMeta['color'] ?>;color:white;"
                        data-txn-id="<?= $ed['id'] ?>" data-src="edited"
                        <?= $eHasRef?'onclick="showRefFromCache(this)"':'' ?>
                        title="<?= $eHasRef?'Click to view reference':'Payment' ?>">
                    <?= $epmMeta['icon'] ?> <?= htmlspecialchars($epmMeta['label']) ?>
                    <?php if($eHasRef): ?><span class="pay-ref-chip">Ref #</span><?php endif; ?>
                  </span>
                </td>
                <td><span class="td-money">₱<?= number_format($ed['total'],2) ?></span></td>
                <td style="color:var(--text2);"><?= htmlspecialchars($ed['edited_by']??'') ?></td>
                <td><span class="td-date"><?= !empty($ed['edited_at'])?date('M j, Y g:i A',strtotime($ed['edited_at'])):'' ?></span></td>
                <td style="color:var(--text3);font-size:10px;max-width:150px;">
                  <?php $er=trim($ed['edit_remarks']??''); echo $er?htmlspecialchars(mb_substr($er,0,55).(mb_strlen($er)>55?'…':'')):'<span style="color:var(--text3);">—</span>'; ?>
                </td>
                <td>
                  <button class="btn btn-blue btn-sm view-items-btn"
                          data-txn-id="<?= $ed['id'] ?>"
                          data-src="edited">🔍 View</button>
                </td>
              </tr>
              <?php endwhile; else: ?>
              <tr><td colspan="9" class="td-empty">No edited transactions found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if($editedPages>1): ?>
        <div class="pager" style="padding:12px;">
          <?php if($epg>1) echo '<a class="pg-btn" href="?edited_page='.($epg-1).'">‹</a>';
          for($i=max(1,$epg-2);$i<=min($editedPages,$epg+2);$i++) echo '<a class="pg-btn'.($i==$epg?' active':'').'" href="?edited_page='.$i.'">'.$i.'</a>';
          if($epg<$editedPages) echo '<a class="pg-btn" href="?edited_page='.($epg+1).'">›</a>'; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ══ TAB: BREAKDOWN ══════════════════════════════════════════════════ -->
  <div class="tab-panel" id="tab-breakdown">
    <div class="sum-grid">
      <div class="sum-tile"><div class="st-label">Unique Products</div><div class="st-val orange"><?= count($productTotals) ?></div></div>
      <div class="sum-tile"><div class="st-label">Total Revenue</div><div class="st-val green">₱<?= number_format(array_sum(array_column($productTotals,'revenue')),2) ?></div></div>
      <div class="sum-tile"><div class="st-label">Pieces Sold</div><div class="st-val orange"><?= $totalPiecesSold ?> pcs</div></div>
      <?php if($totalKgSold>0): ?><div class="sum-tile"><div class="st-label">KG Sold</div><div class="st-val orange"><?= rtrim(rtrim(number_format($totalKgSold,3),'0'),'.') ?> kg</div></div><?php endif; ?>
    </div>
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><div class="pt-dot orange"></div>Product Sales Breakdown</div>
        <span style="font-size:10px;color:var(--text3);">Sorted by highest revenue</span>
      </div>
      <div class="panel-body no-pad">
        <div class="tbl-wrap">
          <table class="data-tbl">
            <thead><tr><th>Product</th><th>Unit</th><th>Qty Sold</th><th>Total Revenue</th><th>Share %</th></tr></thead>
            <tbody>
              <?php $grandRev=array_sum(array_column($productTotals,'revenue'));
              foreach($productTotals as $pn=>$pt):
                $ud=($pt['unit']==='kg')?'kg':'pc';
                $qd=($pt['unit']==='kg')?(($pt['quantity']==floor($pt['quantity']))?(int)$pt['quantity']:rtrim(rtrim(number_format($pt['quantity'],3),'0'),'.')):((int)$pt['quantity']);
                $share=$grandRev>0?round(($pt['revenue']/$grandRev)*100,1):0;
              ?>
              <tr>
                <td class="td-name"><?= htmlspecialchars($pn) ?></td>
                <td><span class="badge b-orange"><?= $ud ?></span></td>
                <td><span style="color:var(--orange-lt);font-weight:700;"><?= $qd ?></span></td>
                <td><span class="td-money">₱<?= number_format($pt['revenue'],2) ?></span></td>
                <td>
                  <div style="display:flex;align-items:center;gap:8px;">
                    <div style="flex:1;height:5px;background:var(--border);border-radius:3px;overflow:hidden;">
                      <div style="height:100%;width:<?= max(2,$share) ?>%;background:linear-gradient(90deg,var(--orange),var(--orange-lt));border-radius:3px;"></div>
                    </div>
                    <span style="font-size:10px;color:var(--text3);min-width:32px;"><?= $share ?>%</span>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if(empty($productTotals)): ?><tr><td colspan="5" class="td-empty">No product data.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if(!empty($productTotals)): ?>
        <div style="padding:14px;border-top:1px solid var(--border);">
          <div style="font-size:10px;color:var(--text3);margin-bottom:10px;text-transform:uppercase;letter-spacing:1px;">Card View</div>
          <div class="breakdown-grid">
            <?php foreach($productTotals as $pn=>$pt):
              $ud=($pt['unit']==='kg')?'kg':'pc';
              $qd=($pt['unit']==='kg')?(($pt['quantity']==floor($pt['quantity']))?(int)$pt['quantity']:rtrim(rtrim(number_format($pt['quantity'],3),'0'),'.')):((int)$pt['quantity']);
            ?>
            <div class="bk-card">
              <div class="bk-name" title="<?= htmlspecialchars($pn) ?>"><?= htmlspecialchars($pn) ?></div>
              <div class="bk-row"><span class="bk-label">Qty:</span><span class="bk-val"><?= $qd ?> <?= $ud ?></span></div>
              <div class="bk-row"><span class="bk-label">Sales:</span><span class="bk-rev">₱<?= number_format($pt['revenue'],2) ?></span></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /main -->

<!-- ══ REFERENCE POPUP ════════════════════════════════════════════════════ -->
<div class="ref-popup-overlay" id="refPopup" onclick="if(event.target===this)closeRef()">
   <div class="ref-popup" id="refPopupBox">
    <div class="rp-icon" id="refIcon"></div>
    <div class="rp-label">E-Wallet Payment Reference</div>
    <div class="rp-name" id="refProviderName"></div>
    <div class="rp-ref-label">Reference Number</div>
    <div class="rp-ref" id="refNumber"></div>
    <div class="rp-actions">
      <button class="btn btn-dark btn-sm" onclick="closeRef()">✕ Close</button>
      <button class="btn btn-orange btn-sm" onclick="copyRef()">📋 Copy</button>
    </div>
  </div>
</div>

<!-- ══ ITEMS VIEW MODAL ════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="itemsModal">
  <div class="modal-box" id="itemsModalBox">
    <div class="modal-title-bar" id="itemsModalHdr">
      <span id="modalTitle">Transaction Items</span>
      <button class="mclose" onclick="closeItemsModal()">✕</button>
    </div>
    <div class="modal-body">
      <div id="mInfoBadges" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;"></div>
      <div id="mPaymentInfo" class="minfo blue" style="display:none;margin-bottom:12px;">
        <div class="mi-title">💳 Payment Method</div>
        <div class="mi-sub" id="mPaymentDetail"></div>
      </div>
      <div id="mVoidInfo" class="minfo red" style="display:none;">
        <div class="mi-title">⛔ VOIDED TRANSACTION</div>
        <div class="mi-sub" id="mVoidReason"></div>
        <div class="mi-sub" id="mVoidedBy" style="margin-top:4px;"></div>
      </div>
      <div id="mEditInfo" class="minfo yellow" style="display:none;">
        <div class="mi-title">✏ EDITED TRANSACTION</div>
        <div class="mi-sub" id="mEditRemarks"></div>
        <div class="mi-sub" id="mEditedBy" style="margin-top:4px;"></div>
      </div>
      <div class="tbl-wrap">
        <table class="data-tbl">
          <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th><th>Status</th></tr></thead>
          <tbody id="mItemsBody"></tbody>
          <tfoot id="mItemsFoot"></tfoot>
        </table>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-dark" onclick="closeItemsModal()">Close</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TRANSACTION CACHES — PHP embeds all three datasets as JS objects.
     Keys are transaction IDs for O(1) lookup.
     Buttons only carry data-txn-id + data-src; the modal/popup reads
     from these objects instantly — no DOM attribute parsing, no extra
     HTTP requests.
════════════════════════════════════════════════════════════════════════ -->
<script>
/* ── Embedded caches (populated at page-paint time by PHP) ── */
const ACTIVE_CACHE  = <?= json_encode($activeTxnCache,  JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const VOIDED_CACHE  = <?= json_encode($voidedTxnCache,  JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const EDITED_CACHE  = <?= json_encode($editedTxnCache,  JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

/*
 * CACHE STRATEGY
 * ──────────────────────────────────────────────────────────────────
 * PHP builds three associative arrays (ACTIVE, VOIDED, EDITED) and
 * encodes them as JS objects embedded in the page.  Every modal open,
 * ref-popup, and delete operation reads from these objects — zero
 * additional HTTP requests.
 *
 * After a successful delete (voided tab) the entry is removed from
 * VOIDED_CACHE immediately so re-opens before any page reload are
 * safe.  A full page reload always refreshes all three caches.
 *
 * The 60-second background sync polls get_transactions_json.php
 * (same helper from the cashier transaction.php) and merges fresh
 * data in.  If that endpoint is absent the page keeps working from
 * the initial embedded snapshot.
 */

/* ── Helper: resolve cache by source string ── */
function getCache(src){
  return src==='voided' ? VOIDED_CACHE : src==='edited' ? EDITED_CACHE : ACTIVE_CACHE;
}

/* ── Cache indicator ── */
function setCachePill(state, label){
  const el = document.getElementById('cachePill');
  const lb = document.getElementById('cacheLabel');
  el.className = 'cache-pill' + (state!=='ok'?' '+state:'');
  lb.textContent = label;
}

/* ── Clock ── */
function updateClock(){document.getElementById('currentTime').textContent=new Date().toLocaleString('en-US',{timeZone:'Asia/Manila',weekday:'short',year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});}
setInterval(updateClock,1000); updateClock();

/* ── Sidebar ── */
function toggleSidebar(){const sb=document.getElementById('sidebar');sb.style.display=sb.style.display==='flex'?'none':'flex';document.getElementById('menuBtn').textContent=sb.style.display==='flex'?'✖':'☰';}
function toggleSub(btn){const sub=btn.nextElementSibling;const open=sub.classList.toggle('open');btn.classList.toggle('open',open);}

/* ── Tab switcher ── */
function switchTab(id,btn){
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById('tab-'+id).classList.add('active');
  btn.classList.add('active');
}

/* ── Connectivity ── */
function checkConn(){
  fetch('../record/ping.php',{cache:'no-store'})
    .then(r=>{const el=document.getElementById('connStatus');el.className=r.ok?'s-conn online':'s-conn offline';el.querySelector('span').textContent=r.ok?'ONLINE':'OFFLINE';})
    .catch(()=>{const el=document.getElementById('connStatus');el.className='s-conn offline';el.querySelector('span').textContent='OFFLINE';});
}
setInterval(checkConn,15000); checkConn();

/* ── Cache pill init ── */
window.addEventListener('DOMContentLoaded',()=>{
  const total = Object.keys(ACTIVE_CACHE).length + Object.keys(VOIDED_CACHE).length + Object.keys(EDITED_CACHE).length;
  setCachePill('ok', total + ' records cached');
});

/* ── Void confirm ── */
function confirmVoid(e){
  e.preventDefault();
  const url = e.currentTarget.href;
  Swal.fire({title:'Confirm Void',text:'Void this transaction? It will be moved to Voided Transactions.',icon:'warning',
    showCancelButton:true,confirmButtonColor:'#ff4444',cancelButtonColor:'#555',
    confirmButtonText:'Yes, void it!',background:'#1e2330',color:'#e8eaf0'
  }).then(r=>{if(r.isConfirmed) window.location.href=url;});
  return false;
}

/* ── Voided client-side search (runs against DOM rows + uses cache for date filter) ── */
document.getElementById('voidedSearch').addEventListener('input',function(){
  const q=this.value.toLowerCase();
  document.querySelectorAll('#voidedTbl tbody tr').forEach(r=>{
    r.style.display=Array.from(r.cells).some(c=>c.textContent.toLowerCase().includes(q))?'':'none';
  });
});
document.getElementById('voidedTimeFlt').addEventListener('change',function(){
  const v=this.value; const now=new Date(); let start=null;
  if(v==='today'){start=new Date(now);start.setHours(0,0,0,0);}
  else if(v==='week'){start=new Date(now);start.setDate(now.getDate()-now.getDay());}
  else if(v==='month'){start=new Date(now.getFullYear(),now.getMonth(),1);}
  else if(v==='year'){start=new Date(now.getFullYear(),0,1);}
  document.querySelectorAll('#voidedTbl tbody tr[data-voided-at]').forEach(r=>{
    if(!start){r.style.display='';return;}
    try{const d=new Date(r.dataset.voidedAt);r.style.display=d>=start?'':'none';}catch{r.style.display='none';}
  });
});

/* ── Delete voided ── */
document.querySelectorAll('.delete-voided-btn').forEach(btn=>{
  btn.addEventListener('click',function(){
    const id=this.dataset.id; const row=this.closest('tr');
    Swal.fire({title:'Delete Permanently?',text:'This voided transaction will be removed forever.',icon:'warning',
      showCancelButton:true,confirmButtonColor:'#ff4444',cancelButtonColor:'#555',
      confirmButtonText:'Yes, delete!',background:'#1e2330',color:'#e8eaf0'
    }).then(r=>{
      if(!r.isConfirmed) return;
      this.disabled=true;
      fetch('delete_voided.php?id='+id,{method:'POST',headers:{'Content-Type':'application/json'}})
        .then(r=>r.json()).then(data=>{
          if(data.success){
            row.remove();
            delete VOIDED_CACHE[id]; // ← invalidate from cache immediately
            const total = Object.keys(ACTIVE_CACHE).length + Object.keys(VOIDED_CACHE).length + Object.keys(EDITED_CACHE).length;
            setCachePill('ok', total + ' records cached');
            Swal.fire({icon:'success',title:'Deleted!',timer:1500,showConfirmButton:false,background:'#1e2330',color:'#e8eaf0'});
          } else Swal.fire({icon:'error',title:'Error',text:data.message||'Failed.',background:'#1e2330',color:'#e8eaf0'});
        }).catch(e=>Swal.fire({icon:'error',title:'Error',text:e.toString(),background:'#1e2330',color:'#e8eaf0'}))
        .finally(()=>this.disabled=false);
    });
  });
});

/* ── Reference popup — reads from JS cache ── */
function showRefFromCache(el){
  const src = el.dataset.src;
  const id  = el.dataset.txnId;
  const txn = getCache(src)[id];
  if(!txn){ showFallbackRef(el); return; }
  document.getElementById('refIcon').textContent         = txn.pm_icon  || '📱';
  document.getElementById('refProviderName').textContent = txn.pm_label || 'E-Wallet';
  document.getElementById('refNumber').textContent       = txn.pm_ref   || '—';
  document.getElementById('refPopup').classList.add('show');
}
function showFallbackRef(el){
  // Graceful fallback to data-* if cache somehow missed the entry
  document.getElementById('refIcon').textContent         = el.dataset.refIcon   || '📱';
  document.getElementById('refProviderName').textContent = el.dataset.refLabel  || 'E-Wallet';
  document.getElementById('refNumber').textContent       = el.dataset.refNumber || '—';
  document.getElementById('refPopup').classList.add('show');
}
function closeRef(){ document.getElementById('refPopup').classList.remove('show'); }
function copyRef(){
  const ref = document.getElementById('refNumber').textContent;
  navigator.clipboard.writeText(ref)
    .then(()=>Swal.fire({icon:'success',title:'Copied!',text:'Reference number copied.',timer:1400,showConfirmButton:false,background:'#1e2330',color:'#e8eaf0'}))
    .catch(()=>Swal.fire({icon:'info',title:'Ref No.',text:ref,background:'#1e2330',color:'#e8eaf0'}));
}

/* ── Items View Modal — reads from JS cache ── */
function closeItemsModal(){ document.getElementById('itemsModal').classList.remove('show'); }
document.getElementById('itemsModal').addEventListener('click',function(e){if(e.target===this)closeItemsModal();});

document.querySelectorAll('.view-items-btn').forEach(btn=>{
  btn.addEventListener('click', function(){
    const src = this.dataset.src;
    const id  = this.dataset.txnId;

    // ── Instant O(1) cache lookup — no DOM attribute parsing ──
    const txn = getCache(src)[id];
    if(!txn){
      Swal.fire({icon:'error',title:'Cache miss',text:'Transaction #'+id+' not found in cache. Refresh the page.',confirmButtonColor:'#ff8800',background:'#1e2330',color:'#e8eaf0'});
      return;
    }

    let items=[], origItems=[];
    try{ items     = JSON.parse(txn.items          || '[]'); }catch{}
    try{ origItems = JSON.parse(txn.original_items || '[]'); }catch{}

    document.getElementById('modalTitle').textContent =
      'Transaction #' + txn.id + ' — ' + txn.cashier;

    /* Status badges */
    const statusBadge = txn.status==='voided'
      ? '<span class="badge b-danger">VOIDED</span>'
      : txn.status==='edited' || txn.edited_by
        ? '<span class="badge b-warning">EDITED</span>'
        : '<span class="badge b-success">COMPLETED</span>';
    document.getElementById('mInfoBadges').innerHTML = `
      <span class="badge b-orange">ID: #${txn.id}</span>
      <span class="badge b-info">${txn.cashier}</span>
      <span class="badge b-info">${new Date(txn.date+'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}</span>
      ${statusBadge}
      <span class="badge" style="background:${txn.pm_color||'#555'};color:white;">${txn.pm_icon||'💳'} ${txn.pm_label||'Cash'}</span>`;

    /* Payment info */
    const pi = document.getElementById('mPaymentInfo');
    if(txn.pm_label && txn.pm_label.toLowerCase()!=='cash'){
      pi.style.display='block';
      document.getElementById('mPaymentDetail').innerHTML =
        `<strong>${txn.pm_icon||'📱'} ${txn.pm_label}</strong>` +
        (txn.pm_ref ? ` &nbsp;·&nbsp; Ref: <strong style="color:var(--yellow);">${txn.pm_ref}</strong>` : '');
    } else pi.style.display='none';

    /* Void info */
    const vi = document.getElementById('mVoidInfo');
    if(txn.status==='voided'){
      vi.style.display='block';
      document.getElementById('mVoidReason').textContent = 'Reason: '+(txn.void_reason||'No reason provided');
      document.getElementById('mVoidedBy').textContent   = 'Voided by '+(txn.voided_by||'Unknown')+' on '+(txn.voided_at||'');
    } else vi.style.display='none';

    /* Edit info */
    const ei = document.getElementById('mEditInfo');
    if(txn.edited_by){
      ei.style.display='block';
      document.getElementById('mEditRemarks').textContent = 'Remarks: '+(txn.edit_remarks||'No remarks');
      document.getElementById('mEditedBy').textContent    = 'Edited by '+(txn.edited_by||'Unknown')+' on '+(txn.edited_at||'');
    } else ei.style.display='none';

    /* Items rows */
    const tbody = document.getElementById('mItemsBody');
    tbody.innerHTML = '';
    let total = 0;
    items.forEach((it,idx)=>{
      const orig  = origItems[idx] || {};
      const qty   = parseFloat(it.qty)   || 0;
      const pr    = parseFloat(it.price) || 0;
      const tot   = qty * pr;
      const st    = it.status || '';
      const edited = st!=='voided' && orig.name &&
        (orig.name!==it.name || parseFloat(orig.qty)!==qty || parseFloat(orig.price)!==pr);
      if(st!=='voided') total += tot;
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td class="td-name">${it.name||''}${edited?'<span class="badge b-warning" style="margin-left:4px;">Edited</span>':''}</td>
        <td style="text-align:center;">${st==='voided'?'<del>'+qty.toFixed(2)+'</del>':qty.toFixed(2)} ${it.unit||''}</td>
        <td style="text-align:right;">${st==='voided'?'<del>₱'+pr.toFixed(2)+'</del>':'₱'+pr.toFixed(2)}</td>
        <td style="text-align:right;">${st==='voided'?'<del>₱0.00</del>':'<span class="td-money">₱'+tot.toFixed(2)+'</span>'}</td>
        <td>${st==='voided'?'<span class="badge b-danger">Voided</span>':''}</td>`;
      tbody.appendChild(tr);
    });
    document.getElementById('mItemsFoot').innerHTML =
      `<tr style="border-top:1px solid var(--border);">
         <td colspan="3" style="text-align:right;font-weight:700;color:var(--text2);padding:8px 12px;">Total:</td>
         <td style="text-align:right;padding:8px 12px;"><span class="td-money">₱${total.toFixed(2)}</span></td>
         <td></td>
       </tr>`;

    document.getElementById('itemsModal').classList.add('show');
  });
});

/* ── URL tab param ── */
const urlTab = new URLSearchParams(window.location.search).get('tab');
if(urlTab){ const btn=document.querySelector(`.tab-btn[onclick*="'${urlTab}'"]`); if(btn) btn.click(); }

/* ── 60-second background cache sync ────────────────────────────────────
   Polls get_transactions_json.php and silently merges fresh rows into
   the three cache objects. No page flicker.  Requires the helper file
   to exist — fails gracefully if it doesn't. ── */
(function startSync(){
  const INTERVAL = 60_000;
  async function sync(){
    // Don't disrupt an open modal
    if(document.getElementById('itemsModal').classList.contains('show')) return;
    setCachePill('syncing','Syncing…');
    try{
      const r = await fetch('get_transactions_json.php?include=all&_='+Date.now(),{cache:'no-store'});
      if(!r.ok) throw new Error('HTTP '+r.status);
      const data = await r.json();
      let count = 0;
      (data.active  ||[]).forEach(t=>{ ACTIVE_CACHE[t.id]  = t; count++; });
      (data.voided  ||[]).forEach(t=>{ VOIDED_CACHE[t.id]  = t; count++; });
      (data.edited  ||[]).forEach(t=>{ EDITED_CACHE[t.id]  = t; count++; });
      setCachePill('ok', count+' records cached');
    } catch {
      // Silently fall back to embedded snapshot
      const total = Object.keys(ACTIVE_CACHE).length + Object.keys(VOIDED_CACHE).length + Object.keys(EDITED_CACHE).length;
      setCachePill('ok', total+' records cached');
    }
  }
  setInterval(sync, INTERVAL);
})();

/* Drag functionality */
function makeDraggable(modalBoxId, handleId) {
  const modalBox = document.getElementById(modalBoxId);
  const handle = document.getElementById(handleId) || modalBox;
  if (!modalBox) return;
  let isDragging = false, startX, startY, initialX, initialY;
  handle.addEventListener('mousedown', function(e) {
    if (e.target.closest('button') || e.target.closest('input') || e.target.closest('select')) return;
    isDragging = true;
    const rect = modalBox.getBoundingClientRect();
    startX = e.clientX; startY = e.clientY;
    initialX = rect.left; initialY = rect.top;
    modalBox.style.position = 'fixed';
    modalBox.style.left = initialX + 'px';
    modalBox.style.top = initialY + 'px';
    modalBox.style.margin = '0';
    handle.style.cursor = 'grabbing';
    e.preventDefault();
  });
  document.addEventListener('mousemove', function(e) {
    if (!isDragging) return;
    modalBox.style.left = (initialX + e.clientX - startX) + 'px';
    modalBox.style.top = (initialY + e.clientY - startY) + 'px';
  });
  document.addEventListener('mouseup', function() {
    isDragging = false;
    handle.style.cursor = 'grab';
  });
}

document.addEventListener('DOMContentLoaded', function() {
  makeDraggable('itemsModalBox', 'itemsModalHdr');
  makeDraggable('refPopupBox', null);
});
</script>

<?php require_once __DIR__ . '/../include/admin_footer.php'; ?>