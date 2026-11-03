<?php
require('dbconn.php');
// ─── Payment meta helpers — MUST match transaction.php ───
$ewalletMeta = [
    'gcash'    => ['label'=>'GCash',    'color'=>'#0a6dff', 'icon'=>'💳'],
    'maya'     => ['label'=>'Maya',     'color'=>'#00b050', 'icon'=>'🎫'],
    'paymaya'  => ['label'=>'Maya',     'color'=>'#00b050', 'icon'=>'🎫'],
    'grabpay'  => ['label'=>'GrabPay',  'color'=>'#00b14f', 'icon'=>'💳'],
    'shopeepay'=> ['label'=>'ShopeePay','color'=>'#ee4d2d', 'icon'=>'💳'],
    'seabank'  => ['label'=>'SeaBank',  'color'=>'#2b4fff', 'icon'=>'🪪'],
    'coins'    => ['label'=>'Coins.ph', 'color'=>'#f5a623', 'icon'=>'💳'],
    // Common banks (optional but useful)
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
    $key = strtolower(str_replace([' ','-','_'],'',$raw));
    foreach ($ewalletMeta as $k => $meta) {
        if (strpos($key, $k) !== false) return array_merge($meta, ['type'=>'ewallet']);
    }
    return ['label'=>ucwords($raw), 'color'=>'#7a5c00', 'icon'=>'📱', 'type'=>'ewallet'];
}
$id = $_GET['id'] ?? 0;

// Get business settings from system_settings table
$settings = [];
$businessName = "Angel's Bakeshop";
$businessSubtitle = 'POS SYSTEM';
$addressLine1 = '';
$addressLine2 = '';
$phonePrimary = '';
$siteLogo = '';

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
        $siteLogo         = $settings['logo_path']         ?? '';
    }
} catch (Exception $e) {}

// Build full address
$fullAddress = $addressLine1;
if (!empty($addressLine2)) {
    $fullAddress .= ', ' . $addressLine2;
}
$stmt = $conn->prepare("
    INSERT INTO transactions
        (cashier_name, date, time, total, paid, change_due, items,
         payment_method, reference_no,
         split_cash_amount, split_wallet_amount, split_wallet_provider)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$stmt->bind_param(
    'sssdddsssdds',          // adjust types to match your columns
    $cashier_name,
    $date,
    $time,
    $total,
    $paid,
    $change_due,
    $items_json,
    $payment_method,         // 'cash' | 'gcash' | 'maya' | 'bpi' | 'split'
    $reference_no,           // '' for cash, ref for e-wallet
    $split_cash_amount,      // 0 unless split
    $split_wallet_amount,    // 0 unless split
    $split_wallet_provider   // '' unless split
);
// Get transaction
$stmt = $conn->prepare("SELECT * FROM transactions WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if (!$row) {
    echo "Transaction not found.";
    exit;
}

$items = json_decode($row['items'], true);
?>
<!DOCTYPE html>
<html>
<head>
  <title>Reprint Receipt - <?= htmlspecialchars($businessName) ?></title>
<style>
    body {
        font-family: "Courier New", monospace;
        font-size: 10px;
        font-weight: 400;
        line-height: 1.4;
        color: #000;
        margin: 0;
        background: #e8e8e8;              /* subtle backdrop so the white iframe pops */
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        align-items: center;              /* center horizontally */
        justify-content: flex-start;      /* keep toolbar at top */
    }
    .brand,
    .totals .grand {
        font-weight: 700;
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

    /* Receipt preview — centered with proper spacing */
    #receipt-frame {
        width: 58mm;
        height: calc(100vh - 100px);      /* fill remaining height nicely */
        max-height: 90vh;
        border: none;
        background: white;
        box-shadow: 0 0 15px rgba(0,0,0,0.3);
        margin: 20px auto;                /* horizontal auto → centers in flex; also vertical margin */
        display: block;
    }
</style>
</head>
<body>

  <div class="toolbar">
    <span>🖨️ Receipt Preview (58mm)</span>
    <button onclick="document.getElementById('receipt-frame').contentWindow.print()">Print Now</button>
  </div>

  <!-- The iframe acts as the popup window -->
  <iframe id="receipt-frame" srcdoc="
    <!DOCTYPE html>
    <html>
    <head>
     
<style>
    @page {
        size: 58mm auto;
        margin: 0;
    }

    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
        font-weight: 600 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

       body {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 9px;
        line-height: 1.35;
        font-weight: 600;
        color: #000;
        background: #fff;
        width: 100%;
        margin: 0 auto;              /* ← center the body inside the page */
        display: flex;
        justify-content: center;     /* ← and center its child horizontally */
    }

    .receipt {
        width: 48mm;
        max-width: 48mm;
        padding: 1mm 0;
        margin: 0 auto;              /* ← belt & suspenders: center the receipt block */
    }

    .center {
        text-align: center;
    }

    .logo-img {
        display: block;
        margin: 0 auto 4px;
        max-width: 55%;
        max-height: 18mm;
        width: auto;
        height: auto;
    }

    .brand {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 13px;
        font-weight: 900 !important;
        letter-spacing: 0.3px;
        margin: 2px 0 0;
        line-height: 1.2;
    }

    .subtitle {
        font-size: 8px;
        font-weight: 700;
        letter-spacing: 1.2px;
        text-transform: uppercase;
        margin-top: 1px;
    }

    .meta {
        font-size: 9px;
        line-height: 1.4;
        margin-top: 4px;
    }

    hr {
        border: none;
        border-top: 1px dashed #000;
        margin: 3px 0;
    }

    .meta-row {
        display: flex;
        justify-content: space-between;
        font-size: 9px;
        font-weight: 700;
    }

    .item-row {
        display: flex;
        justify-content: space-between;
        gap: 4px;
        font-size: 9px;
        font-weight: 500;
        margin: 1px 0;
    }

    .item-row .name {
        flex: 1;
        word-break: break-word;
    }

    .item-row .price {
        white-space: nowrap;
        text-align: right;
    }

    .item-qty {
        margin-left: 2px;
        opacity: 1;
    }

    .totals {
        font-size: 10px;
        font-weight: 700;
    }

    .totals div {
        display: flex;
        justify-content: space-between;
        margin: 1px 0;
        font-weight: 700;
    }

    .totals .grand {
        font-weight: 900 !important;
        font-size: 12px;
        margin-top: 2px;
        padding-top: 2px;
        border-top: 1px dashed #000;
    }

    .footer {
        text-align: center;
        font-size: 9px;
        font-weight: 700;
        margin-top: 4px;
    }
</style>

    </head>
    <body>
      <div class='receipt'>
        <div class='center'>
         <img src="image/logo.png" alt="Logo"
         style="display: block; margin: 0 auto 4px; max-width: 55%; max-height: 18mm; width: auto; height: auto;">
          <div class='brand'><?= htmlspecialchars($businessName) ?></div>
          <?php if (!empty($businessSubtitle)): ?>
            <div class='subtitle'><?= htmlspecialchars($businessSubtitle) ?></div>
          <?php endif; ?>
          <div class='meta'>
            <?= htmlspecialchars($fullAddress) ?><br>
            <?= htmlspecialchars($phonePrimary) ?>
          </div>
        </div>
        <hr>
        <div class='meta-row'><span>Date</span><span><?= $row['date'] ?></span></div>
        <div class='meta-row'><span>Time</span><span><?= date("g:i A", strtotime($row['time'])) ?></span></div>
        <div class='meta-row'><span>Cashier</span><span><?= htmlspecialchars($row['cashier_name']) ?></span></div>
        <hr>
        <?php foreach ($items as $item): ?>
          <div class='item-row'>
            <span class='name'><?= htmlspecialchars($item['name']) ?> <span class='item-qty'>x<?= $item['qty'] ?></span></span>
            <span class='price'>₱<?= number_format($item['price'] * $item['qty'], 2) ?></span>
          </div>
        <?php endforeach; ?>
        <hr>
     <div class='totals'>
  <div class='grand'><span>TOTAL</span><span>₱<?= number_format($row['total'], 2) ?></span></div>
  <?php
    // ─── Read raw payment fields ───
    $pmRaw         = trim($row['payment_method'] ?? '');
    $refNo         = trim($row['reference_no']       ?? '');
    $splitCash     = (float)($row['split_cash_amount']       ?? 0);
    $splitWallet   = (float)($row['split_wallet_amount']     ?? 0);
    $splitProvider = trim($row['split_wallet_provider']      ?? '');

    // ─── Infer e-wallet when payment_method is empty but ref exists ───
    if ($pmRaw === '') {
        $pmRaw = ($refNo !== '') ? 'E-Wallet' : 'Cash';
    }

    $pmMeta    = getPaymentMeta($pmRaw, $ewalletMeta);
    $isCash    = $pmMeta['type'] === 'cash';
    $isSplit   = $pmMeta['type'] === 'split';
    $isEwallet = $pmMeta['type'] === 'ewallet';
    $hasRef    = ($isEwallet || $isSplit) && $refNo !== '';
$label = $pmMeta['label'];
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
    <div><span>Tendered</span><span>₱<?= number_format($row['paid'], 2) ?></span></div>

  <?php else: /* e-wallet or bank */ ?>
    <div><span><?= htmlspecialchars($label) ?></span><span>₱<?= number_format($row['total'], 2) ?></span></div>
    <?php if ($hasRef): ?>
      <div><span>Ref</span><span><?= htmlspecialchars($refNo) ?></span></div>
    <?php endif; ?>
  <?php endif; ?>

    <?php if ($isCash || ($isSplit && $splitCash > 0)): ?>
    <div><span>Change</span><span>₱<?= number_format($row['change_due'], 2) ?></span></div>
  <?php endif; ?>
</div>
        <hr>
        <div class='footer'>Thank you for your purchase!</div>
      </div>
    </body>
    </html>
  "></iframe>

  <script>
    // Automatically trigger print inside the iframe after it loads
    window.addEventListener('load', function() {
      const iframe = document.getElementById('receipt-frame');
      
      // Wait for the iframe to fully render
      setTimeout(function() {
        if (iframe.contentWindow) {
          iframe.contentWindow.focus();
          iframe.contentWindow.print();
          
          // Optional: Close the tab after printing (only works if opened via window.open, 
          // so it might not close automatically here, but the print dialog will show).
        }
      }, 500); // 500ms delay ensures the logo is loaded
    });
  </script>
</body>
</html>