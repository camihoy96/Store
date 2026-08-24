<?php
require('dbconn.php');
$id = $_GET['id'] ?? 0;

// Get business settings from system_settings table
$settings = [];
$businessName = 'Cozy Corner Café';
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
        
        // Use the same keys as your system_settings table
        $businessName = $settings['business_name'] ?? 'Cozy Corner Café';
        $addressLine1 = $settings['business_address'] ?? '';
        $addressLine2 = $settings['business_address2'] ?? '';
        $phonePrimary = $settings['business_phone'] ?? '';
        $siteLogo = $settings['logo_path'] ?? '';
    }
} catch (Exception $e) {
    // Table doesn't exist or error, use defaults
    // You can log the error if needed: error_log($e->getMessage());
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
    body {
      font-family: Arial, sans-serif;
      font-size: 12px;
      margin: 0;
      padding: 0;
    }

    .receipt {
      width: 54mm;
      padding: 10px;
      margin: auto;
    }

    .center {
      text-align: center;
    }

    .logo-img {
      max-width: 30%;
      height: auto;
    }

    hr {
      border: none;
      border-top: 1px dashed #000;
      margin: 8px 0;
    }

    .item-row {
      display: flex;
      justify-content: space-between;
    }

    .item-qty {
      margin-left: 4px;
    }
  </style>
</head>
<body onload="window.print()">
  <div class="receipt">
    <div class="center">
      <?php if (!empty($siteLogo) && file_exists(__DIR__ . '/../' . $siteLogo)): ?>
        <img src="/Store/<?= htmlspecialchars($siteLogo) ?>" alt="Logo" class="logo-img">
      <?php else: ?>
        <div style="font-size: 40px; margin: 10px 0;">☕</div>
      <?php endif; ?>
      <h2><?= htmlspecialchars($businessName) ?></h2>
      <div style="font-size: 10px;">
        <?= htmlspecialchars($fullAddress) ?><br>
        <?= htmlspecialchars($phonePrimary) ?>
      </div>
    </div>

    <hr>

    <div>Date: <?= $row['date'] ?></div>
    <div>Time: <?= date("g:i A", strtotime($row['time'])) ?></div>
    <div>Cashier: <?= htmlspecialchars($row['cashier_name']) ?></div>

    <hr>

    <?php foreach ($items as $item): ?>
      <div class="item-row">
        <span><?= htmlspecialchars($item['name']) ?> x<?= $item['qty'] ?></span>
        <span>₱<?= number_format($item['price'], 2) ?></span>
      </div>
    <?php endforeach; ?>

    <hr>

    <div>Total: ₱<?= number_format($row['total'], 2) ?></div>
    <div>Cash: ₱<?= number_format($row['paid'], 2) ?></div>
    <div>Change: ₱<?= number_format($row['change_due'], 2) ?></div>

    <hr>

    <div class="center">Thank you for your purchase!</div>
  </div>
</body>
</html>