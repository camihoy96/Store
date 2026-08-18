<?php
session_start();
require('dbconn.php');

if (!isset($_SESSION['loggedin'])) {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('Asia/Manila');
$exportTimestamp = time();
$exportDate = date('Y-m-d', $exportTimestamp);
$exportDateTime = date('F j, Y \a\t g:i A', $exportTimestamp);

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment;filename="products_export_'.$exportDate.'.xls"');
echo "\xEF\xBB\xBF";        
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; }
        .title { 
            font-size: 16pt; 
            font-weight: bold; 
            text-align: center;
            margin-bottom: 10px;
        }
        .subtitle {
            font-size: 14pt;
            text-align: center;
            margin-bottom: 20px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 20px;
        }
        th {
            background-color: #2c3e50;
            color: white;
            font-weight: bold;
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
        }
        td {
            padding: 6px;
            border: 1px solid #ddd;
        }
        .category-header {
            background-color: #e0e0e0;
            font-weight: bold;
            padding: 8px;
            text-align: left;
            font-size: 1.1em;
            border-bottom: 2px solid #999;
            border-top: 2px solid #999;
        }
        .report-date {
            text-align: right;
            margin-bottom: 20px;
            font-style: italic;
        }
        .side {
            text-align: left;
            margin-bottom: 20px;
            font-style: italic;
        }
        .total-row {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .grand-total-row {
            background-color: #d9d9d9;
            font-weight: bold;
            font-size: 1.1em;
        }
    </style>
</head>
<body>
    <div class="title">FOUR ACC Point of Sale INVENTORY MANAGEMENT SYSTEM</div>
    <div class="subtitle">ITEMS INVENTORY REPORT</div>
    <div class="report-date">
        <h4 class="side">Balance Items:</h4>    
        Report Generated: <?= $exportDateTime ?>
    </div>
    
    <table>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Category</th>
            <th>Brand</th>
            <th>Seller Store</th>
            <th>Capital Price</th>
            <th>Price Per Item</th>
            <th>Date Added</th>
            <th>Date of Purchase</th>
            <th>Expiry Date</th>
            <th>Quantity</th>
            <th>Measurement</th>
        </tr>
       <?php
// Get all products ordered by category, then by name (A-Z)
$query = "SELECT *, 
         IF(measurement_type = 'kg', kg, pieces) AS quantity,
         measurement_type
         FROM products 
         ORDER BY category, name ASC";
$result = $conn->query($query);

$currentCategory = '';
$categoryKgTotal = 0;
$categoryPiecesTotal = 0;
$categoryCapitalSum = 0; // CHANGED: Now just sums purchase prices
$grandKgTotal = 0;
$grandPiecesTotal = 0;
$grandCapitalSum = 0; // CHANGED: Now just sums purchase prices

while ($row = $result->fetch_assoc()):
    // REMOVED: No more quantity multiplication
    $capitalValue = $row['purchase_price']; // Just the unit price
    
    if ($row['category'] != $currentCategory):
        // If not first category, show category totals
        if ($currentCategory != ''):
?>
        <tr class="total-row">
            <td colspan="10"><?= strtoupper($currentCategory) ?> SUBTOTAL</td>
            <td><?= $categoryKgTotal ?> kg</td>
            <td><?= $categoryPiecesTotal ?> pcs</td>
        </tr>
        <?php
        endif;
        
        // Reset category totals
        $currentCategory = $row['category'];
        $categoryKgTotal = 0;
        $categoryPiecesTotal = 0;
        $categoryCapitalSum = 0;
    ?>
    <tr>
        <td colspan="12" class="category-header"><?= strtoupper($currentCategory) ?></td>
    </tr>
    <?php endif; ?>
    
    <tr>
        <td><?= $row['id'] ?></td>
        <td><?= htmlspecialchars($row['name']) ?></td>
        <td><?= htmlspecialchars($row['category']) ?></td>
        <td><?= htmlspecialchars($row['brand']) ?></td>
        <td><?= htmlspecialchars($row['seller_store']) ?></td>
        <td><?= htmlentities('₱', ENT_COMPAT, 'UTF-8') . number_format($row['purchase_price'], 2) ?></td>
        <td><?= htmlentities('₱', ENT_COMPAT, 'UTF-8') . number_format($row['price'], 2) ?></td>
        <td><?= date("j-M-y", strtotime($row['date_added'])) ?></td>
        <td><?= date("j-M-y", strtotime($row['purchase_date'])) ?></td>
        <td><?= date("j-M-y", strtotime($row['expiry_date'])) ?></td>
        <td><?= $row['measurement_type'] == 'kg' ? number_format($row['kg'], 2) : number_format($row['pieces'], 0) ?></td>
        <td><?= $row['measurement_type'] == 'kg' ? 'kg' : 'pcs' ?></td>
    </tr>
    <?php
    // Add to category totals
    if ($row['measurement_type'] == 'kg') {
        $categoryKgTotal += $row['kg'];
        $grandKgTotal += $row['kg'];
    } else {
        $categoryPiecesTotal += $row['pieces'];
        $grandPiecesTotal += $row['pieces'];
    }
    
    // CHANGED: Just sum the purchase prices
    $categoryCapitalSum += $row['purchase_price'];
    $grandCapitalSum += $row['purchase_price'];
    
endwhile;

// Show final category totals
if ($currentCategory != ''):
?>
<tr class="total-row">
    <td colspan="10"><?= strtoupper($currentCategory) ?> SUBTOTAL</td>
    <td><?= number_format($categoryKgTotal, 2) ?> kg</td>
    <td><?= number_format($categoryPiecesTotal, 0) ?> pcs</td>
</tr>
<?php endif; ?>
</table>

<!-- Totals Table -->
<table>
    <tr class="total-row">
        <td colspan="2">CATEGORY TOTALS</td>
        <td>Total KG</td>
        <td>Total Pieces</td>
        <td>Total Capital Sum</td> <!-- Changed label -->
    </tr>
    <?php
    // Reset and get categories again to show all category totals
    $result->data_seek(0);
    $categoryTotals = [];
    $currentCategory = '';
    
    while ($row = $result->fetch_assoc()):
        if ($row['category'] != $currentCategory) {
            $currentCategory = $row['category'];
            $categoryTotals[$currentCategory] = [
                'kg' => 0,
                'pieces' => 0,
                'capital_sum' => 0 // CHANGED: Just sums purchase prices
            ];
        }
        
        if ($row['measurement_type'] == 'kg') {
            $categoryTotals[$currentCategory]['kg'] += $row['kg'];
        } else {
            $categoryTotals[$currentCategory]['pieces'] += $row['pieces'];
        }
        
        // CHANGED: Just sum the purchase prices
        $categoryTotals[$currentCategory]['capital_sum'] += $row['purchase_price'];
    endwhile;
    
    foreach ($categoryTotals as $category => $totals):
    ?>
    <tr>
        <td colspan="2"><?= strtoupper($category) ?></td>
        <td><?= number_format($totals['kg'], 2) ?> kg</td>
        <td><?= number_format($totals['pieces'], 0) ?> pcs</td>
        <td>₱<?= number_format($totals['capital_sum'], 2) ?></td>
    </tr>
    <?php endforeach; ?>
    
    <!-- Grand Total Row -->
    <tr class="grand-total-row">
        <td colspan="2">GRAND TOTAL</td>
        <td><?= number_format($grandKgTotal, 2) ?> kg</td>
        <td><?= number_format($grandPiecesTotal, 0) ?> pcs</td>
        <td>₱<?= number_format($grandCapitalSum, 2) ?></td>
    </tr>
</table>  
    <div style="text-align: center; font-style: italic; margin-top: 20px;">
        End of Report
    </div>
</body>
</html>
<?php
ob_end_flush();
exit;
?>