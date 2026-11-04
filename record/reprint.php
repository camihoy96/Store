<?php
header('Content-Type: text/html; charset=UTF-8');
require('dbconn.php');

// ─── Payment meta helpers ───
$ewalletMeta = [
    'gcash'    => ['label'=>'GCash',    'color'=>'#0a6dff', 'icon'=>'💳'],
    'maya'     => ['label'=>'Maya',     'color'=>'#00b050', 'icon'=>'🎫'],
    'paymaya'  => ['label'=>'Maya',     'color'=>'#00b050', 'icon'=>'🎫'],
    'grabpay'  => ['label'=>'GrabPay',  'color'=>'#00b14f', 'icon'=>'💳'],
    'shopeepay'=> ['label'=>'ShopeePay','color'=>'#ee4d2d', 'icon'=>'💳'],
    'seabank'  => ['label'=>'SeaBank',  'color'=>'#2b4fff', 'icon'=>'🪪'],
    'coins'    => ['label'=>'Coins.ph', 'color'=>'#f5a623', 'icon'=>'💳'],
    'bpi'      => ['label'=>'BPI',      'color'=>'#b22222', 'icon'=>'🏦'],
    'bdo'      => ['label'=>'BDO',      'color'=>'#0033a0', 'icon'=>'🏦'],
    'metrobank'=> ['label'=>'MetroBank','color'=>'#00277a', 'icon'=>'🏦'],
    'unionbank'=> ['label'=>'UnionBank','color'=>'#ff6600', 'icon'=>'🏦'],
    'securitybank'=> ['label'=>'Security Bank','color'=>'#0066b3', 'icon'=>'🏦'],
    'rcbc'     => ['label'=>'RCBC',     'color'=>'#0055a5', 'icon'=>'🏦'],
];

function getPaymentMeta($pm, $ewalletMeta) {
    if (!$pm || strtolower(trim($pm)) === 'cash') {
        return ['label'=>'Cash', 'color'=>'#2a6e1a', 'icon'=>'cash', 'type'=>'cash'];
    }
    if (strtolower(trim($pm)) === 'split') {
        return ['label'=>'Split Payment', 'color'=>'#6a0dad', 'icon'=>'wallet', 'type'=>'split'];
    }
    $key = strtolower(str_replace([' ','-','_'], '', $pm));
    foreach ($ewalletMeta as $k => $meta) {
        if (strpos($key, $k) !== false) return array_merge($meta, ['type'=>'ewallet']);
    }
    return ['label'=>ucwords($pm), 'color'=>'#7a5c00', 'icon'=>'📱', 'type'=>'ewallet'];
}

$id = $_GET['id'] ?? 0;

// Get business settings
$settings = [];
$businessName = "Angel's Bakeshop";
$businessSubtitle = 'POS SYSTEM';
$addressLine1 = '';
$addressLine2 = '';
$phonePrimary = '';

try {
    $settingsQuery = "SELECT setting_key, setting_value FROM system_settings";
    $settingsResult = $conn->query($settingsQuery);
    if ($settingsResult) {
        while ($setting = $settingsResult->fetch_assoc()) {
            $settings[$setting['setting_key']] = $setting['setting_value'];
        }
        $businessName     = $settings['business_name']     ?? "Angel's Bakeshop";
        $businessSubtitle = $settings['business_subtitle'] ?? 'POS SYSTEM';
        $addressLine1     = $settings['business_address']  ?? '';
        $addressLine2     = $settings['business_address2'] ?? '';
        $phonePrimary     = $settings['business_phone']    ?? '';
    }
} catch (Exception $e) {}

$fullAddress = $addressLine1;
if (!empty($addressLine2)) {
    $fullAddress .= ', ' . $addressLine2;
}

// Get transaction
$stmt = $conn->prepare("SELECT * FROM transactions WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    echo "Transaction not found.";
    exit;
}

$items = json_decode($row['items'], true);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Reprint Receipt - <?= htmlspecialchars($businessName) ?></title>
<style>
    /* ── SCREEN STYLES ── */
    body {
        font-family: "Courier New", monospace;
        font-size: 10px;
        font-weight: 400;
        line-height: 1.4;
        color: #000;
        margin: 0;
        background: #e8e8e8;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    .toolbar {
        background: #333;
        color: white;
        padding: 10px 20px;
        width: 100%;
        box-sizing: border-box;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .toolbar button {
        padding: 8px 16px;
        font-size: 14px;
        font-weight: bold;
        cursor: pointer;
        background: #ff8800;
        color: white;
        border: none;
        border-radius: 4px;
    }
    .toolbar button:hover { background: #cc6600; }

    /* The receipt preview container */
    .receipt-wrapper {
        width: 58mm;
        background: white;
        box-shadow: 0 0 15px rgba(0,0,0,0.3);
        margin: 20px auto;
        padding: 2mm;
    }

    /* ── RECEIPT INNER STYLES ── */
    .receipt {
        width: 48mm;
        max-width: 48mm;
        padding: 1mm 0;
        margin: 0 auto;
    }
    .center { text-align: center; }
    .logo-img {
        display: block; margin: 0 auto 4px;
        max-width: 55%; max-height: 18mm;
        width: auto; height: auto;
    }
    .brand {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 13px; font-weight: 900 !important;
        letter-spacing: 0.3px; margin: 2px 0 0; line-height: 1.2;
    }
    .subtitle {
        font-size: 8px; font-weight: 700;
        letter-spacing: 1.2px; text-transform: uppercase; margin-top: 1px;
    }
    .meta {
        font-size: 9px; line-height: 1.4; margin-top: 4px;
        font-weight: 700;
    }
    hr {
        border: none; border-top: 1px dashed #000; margin: 3px 0;
    }
    .meta-row {
        display: flex; justify-content: space-between;
        font-size: 9px; font-weight: 700;
    }
    .item-row {
        display: flex; justify-content: space-between; gap: 4px;
        font-size: 9px; margin: 2px 0;
    }
    .item-row .name { 
        flex: 1; word-break: break-word; 
        font-weight: 700;
    }
    .item-row .price { 
        white-space: nowrap; text-align: right; 
        font-weight: 700;
    }
    .item-qty { 
        margin-left: 2px; opacity: 1; 
        font-weight: 400;
    }
    
    .totals { font-size: 10px; font-weight: 700; }
    .totals div {
        display: flex; justify-content: space-between;
        margin: 1px 0; font-weight: 700;
    }
    .totals .grand {
        font-weight: 900 !important; font-size: 12px;
        margin-top: 2px; padding-top: 2px;
        border-top: 1px dashed #000;
    }
    .footer { text-align: center; font-size: 9px; font-weight: 700; margin-top: 4px; }

       /* ── PRINT STYLES ── */
    @media print {
        /* Tell the browser the paper size */
        @page { 
            size: 58mm auto; 
            margin: 0; 
        }
        
        /* Hide the on-screen toolbar */
        body { 
            background: white; 
            margin: 0; 
            padding: 0;
        }
        .toolbar { display: none !important; }
        
        /* Remove all the fake screen shadows and borders */
        .receipt-wrapper {
            width: 150% !important; 
            box-shadow: none !important; 
            margin: 0 !important; 
            padding: 0 !important;
            display: block;
        }
        
        /* 
         * THE FIX: 
         * Instead of 48mm, we set it to 52mm.
         * This gives a 3mm safe margin on the left and right, 
         * preventing the thermal printer from chopping off the edge.
         */
        .receipt { 
            width: 52mm !important; 
            max-width: 52mm !important;
            margin: 0 auto !important; /* Centers it on the page */
            padding: 0 !important;
        }
        
        /* Ensure bold fonts print correctly */
        * {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
    }
</style>
</head>
<body>

  <div class="toolbar">
    <span>🖨️ Receipt Preview (58mm)</span>
    <button onclick="window.print()">Print Now</button>
  </div>

  <!-- The receipt is rendered directly on the page -->
  <div class="receipt-wrapper">
      <div class="receipt">
        <div class="center">
          <img src="../image/logo.png" alt="Logo" class="logo-img">
          <div class="brand"><?= htmlspecialchars($businessName) ?></div>
          <?php if (!empty($businessSubtitle)): ?>
            <div class="subtitle"><?= htmlspecialchars($businessSubtitle) ?></div>
          <?php endif; ?>
          <div class="meta">
            <?= htmlspecialchars($fullAddress) ?><br>
            <?= htmlspecialchars($phonePrimary) ?>
          </div>
        </div>
        
        <hr>
        <div class="meta-row"><span>Date</span><span><?= date("F j, Y", strtotime($row['date'])) ?></span></div>
        <div class="meta-row"><span>Time</span><span><?= date("g:i A", strtotime($row['time'])) ?></span></div>
        <div class="meta-row"><span>Cashier</span><span><?= htmlspecialchars($row['cashier_name']) ?></span></div>
        <hr>
        
        <?php if (is_array($items)): ?>
          <?php foreach ($items as $item): ?>
            <?php
              $itemName  = $item['name']  ?? '';
              $itemQty   = (float)($item['qty']   ?? 0);
              $itemPrice = (float)($item['price'] ?? 0);
              $itemUnit  = $item['unit'] ?? ($item['measurement_type'] === 'kg' ? 'kg' : 'pcs');
              $qtyDisp   = ($itemUnit === 'kg') ? rtrim(rtrim(number_format($itemQty, 3), '0'), '.') : (int)$itemQty;
            ?>
            <div class="item-row">
              <span class="name"><?= htmlspecialchars($itemName) ?> <span class="item-qty">x<?= $qtyDisp ?><?= $itemUnit === 'kg' ? 'kg' : '' ?></span></span>
              <span class="price">₱<?= number_format($itemPrice * $itemQty, 2) ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        
        <hr>
        <div class="totals">
          <div class="grand"><span>TOTAL</span><span>₱<?= number_format((float)$row['total'], 2) ?></span></div>
          
          <?php
            $pmRaw         = trim($row['payment_method'] ?? '');
            $refNo         = trim($row['reference_no']       ?? '');
            $splitCash     = (float)($row['split_cash_amount']       ?? 0);
            $splitWallet   = (float)($row['split_wallet_amount']     ?? 0);
            $splitProvider = trim($row['split_wallet_provider']      ?? '');

            if ($pmRaw === '') {
                $pmRaw = ($refNo !== '') ? 'E-Wallet' : 'Cash';
            }

            $pmMeta    = getPaymentMeta($pmRaw, $ewalletMeta);
            $isCash    = $pmMeta['type'] === 'cash';
            $isSplit   = $pmMeta['type'] === 'split';
            $isEwallet = $pmMeta['type'] === 'ewallet';
            $hasRef    = ($isEwallet || $isSplit) && $refNo !== '';
            $label     = $pmMeta['label'];
          ?>

          <?php if ($isSplit): ?>
            <?php if ($splitCash > 0): ?>
              <div><span>Cash</span><span>₱<?= number_format($splitCash, 2) ?></span></div>
            <?php endif; ?>
            <?php if ($splitWallet > 0): ?>
              <div><span><?= htmlspecialchars($splitProvider !== '' ? $splitProvider : 'E-Wallet') ?></span><span>₱<?= number_format($splitWallet, 2) ?></span></div>
            <?php endif; ?>
            <?php if ($hasRef): ?>
              <div><span>Ref</span><span><?= htmlspecialchars($refNo) ?></span></div>
            <?php endif; ?>

          <?php elseif ($isCash): ?>
            <div><span>Tendered</span><span>₱<?= number_format((float)$row['paid'], 2) ?></span></div>

          <?php else: ?>
            <div><span><?= htmlspecialchars($label) ?></span><span>₱<?= number_format((float)$row['total'], 2) ?></span></div>
            <?php if ($hasRef): ?>
              <div><span>Ref</span><span><?= htmlspecialchars($refNo) ?></span></div>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($isCash || ($isSplit && $splitCash > 0)): ?>
            <div><span>Change</span><span>₱<?= number_format((float)$row['change_due'], 2) ?></span></div>
          <?php endif; ?>
        </div>
        <hr>
        <div class="footer">Thank you for your purchase!</div>
      </div>
  </div>

  <script>
    // Optional: Auto-trigger print after 500ms (gives the logo time to load).
    // Uncomment the lines below if you want it to print automatically.
    /*
    window.addEventListener('load', function() {
      setTimeout(function() {
        window.print();
      }, 500);
    });
    */
  </script>
</body>
</html>