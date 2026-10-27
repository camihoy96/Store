<?php
require('dbconn.php');
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
} catch (Exception $e) {
    // Table doesn't exist or error, use defaults
}

// Build full address
$fullAddress = $addressLine1;
if (!empty($addressLine2)) {
    $fullAddress .= ', ' . $addressLine2;
}

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
  @page {
  size: 58mm auto;  
  margin: 0;
}
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: Arial, sans-serif;
      font-size: 11px;
      line-height: 1.35;
      color: #000;
      background: #fff;
    }

    .receipt {
      width: 54mm;
      padding: 3mm 2mm;
      margin: 0 auto;
    }

    .center { text-align: center; }

    .logo-img {
      display: block;
      margin: 0 auto 4px;
      max-width: 55%;
      max-height: 18mm;
      width: auto;
      height: auto;
    }
    .logo-fallback {
      font-size: 32px;
      line-height: 1;
      margin: 4px 0;
    }

    .brand {
      font-size: 14px;
      font-weight: 700;
      letter-spacing: 0.3px;
      margin: 2px 0 0;
      line-height: 1.2;
    }
    .subtitle {
      font-size: 8px;
      letter-spacing: 1.2px;
      text-transform: uppercase;
      margin-top: 1px;
      opacity: 0.75;
    }
    .meta {
      font-size: 9px;
      line-height: 1.35;
      margin-top: 4px;
    }

    hr {
      border: none;
      border-top: 1px dashed #000;
      margin: 5px 0;
    }

    .meta-row {
      display: flex;
      justify-content: space-between;
      font-size: 10px;
    }

    .item-row {
      display: flex;
      justify-content: space-between;
      gap: 4px;
      font-size: 11px;
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
    .item-qty { margin-left: 3px; opacity: 0.8; }

    .totals { font-size: 11px; }
    .totals div {
      display: flex;
      justify-content: space-between;
      margin: 1px 0;
    }
    .totals .grand {
      font-weight: 700;
      font-size: 12px;
      margin-top: 3px;
      padding-top: 3px;
      border-top: 1px dashed #000;
    }

    .footer {
      text-align: center;
      font-size: 10px;
      margin-top: 6px;
    }
  </style>
</head>
<body>
  <div class="receipt">
   <!-- Header -->
<div class="center">
  <?php if (!empty($siteLogo) && file_exists(__DIR__ . '/../' . ltrim($siteLogo, '/'))): ?>
    <img src="/Store/<?= htmlspecialchars(ltrim($siteLogo, '/')) ?>" alt="Logo" class="logo-img">
  <?php else: ?>
    <img src="../image/logo.png" alt="Logo" class="logo-img">
  <?php endif; ?>

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
    <!-- Transaction meta -->
    <div class="meta-row"><span>Date</span><span><?= $row['date'] ?></span></div>
    <div class="meta-row"><span>Time</span><span><?= date("g:i A", strtotime($row['time'])) ?></span></div>
    <div class="meta-row"><span>Cashier</span><span><?= htmlspecialchars($row['cashier_name']) ?></span></div>
    <hr>
    <!-- Items -->
    <?php foreach ($items as $item): ?>
      <div class="item-row">
        <span class="name"><?= htmlspecialchars($item['name']) ?> <span class="item-qty">x<?= $item['qty'] ?></span></span>
        <span class="price">₱<?= number_format($item['price'] * $item['qty'], 2) ?></span>
      </div>
    <?php endforeach; ?>
    <hr>
    <!-- Totals -->
    <div class="totals">
      <div class="grand"><span>TOTAL</span><span>₱<?= number_format($row['total'], 2) ?></span></div>
      <?php
        // Payment line — mirror what home.php writes after payment
        $pm = strtolower(trim($row['payment_method'] ?? 'cash'));
        $ref = $row['reference_no'] ?? '';
        if ($pm === 'wallet' || $pm === 'e-wallet' || $pm === 'ewallet') {
          // Provider name is stored per-transaction; fall back to generic label
          $providerLabel = !empty($row['wallet_provider']) ? $row['wallet_provider'] : 'E-Wallet';
          echo '<div><span>' . htmlspecialchars($providerLabel) . '</span><span>₱' . number_format($row['total'], 2) . '</span></div>';
          if (!empty($ref)) {
            echo '<div><span>Ref</span><span>' . htmlspecialchars($ref) . '</span></div>';
          }
        } else {
          echo '<div><span>Cash</span><span>₱' . number_format($row['paid'], 2) . '</span></div>';
        }
      ?>
      <div><span>Change</span><span>₱<?= number_format($row['change_due'], 2) ?></span></div>
    </div>
    <hr>
    <!-- Footer -->
    <div class="footer">
      Thank you for your purchase!
    </div>
  </div>
  <script>
  // Print after layout + image load, with a small delay so Chrome
  // receives the final page before --kiosk-printing fires.
  window.addEventListener('load', function () {
    // Wait for the logo (if any) to decode
    var logo = document.querySelector('.logo-img');
    function go() {
      // Extra tick so Chrome paints the 54mm page fully before printing
      setTimeout(function () {
        window.focus();     // bring the new tab to front
        window.print();
      }, 200);
    }
    if (logo && !logo.complete) {
      logo.addEventListener('load',  go, { once: true });
      logo.addEventListener('error', go, { once: true });
    } else {
      go();
    }
  });
</script>
</body>
</html>