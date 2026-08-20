<?php
require('dbconn.php');
$id = $_GET['id'] ?? 0;

// Get business settings from site_settings table
$settingsQuery = "SELECT setting_key, setting_value FROM site_settings";
$settingsResult = $conn->query($settingsQuery);
$settings = [];

if ($settingsResult) {
    while ($setting = $settingsResult->fetch_assoc()) {
        $settings[$setting['setting_key']] = $setting['setting_value'];
    }
}

$businessName = $settings['cafe_name'] ?? 'Cozy Corner Café';
$addressLine1 = $settings['address_line1'] ?? '';
$addressLine2 = $settings['address_line2'] ?? '';
$phonePrimary = $settings['phone_primary'] ?? '';
$siteLogo = $settings['site_logo'] ?? '';

// Build full address
$fullAddress = $addressLine1;
if (!empty($addressLine2)) {
    $fullAddress .= ', ' . $addressLine2;
}

// Build logo path
$logoPath = '';
if (!empty($siteLogo)) {
    $logoFullPath = __DIR__ . '/' . $siteLogo;
    if (file_exists($logoFullPath)) {
        $logoPath = $siteLogo;
    }
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

    img {
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
      <?php if (!empty($logoPath)): ?>
        <img src="<?= htmlspecialchars($logoPath) ?>" alt="Logo">
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