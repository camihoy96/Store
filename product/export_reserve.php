<?php
session_start();
require('dbconn.php');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo "<script>document.addEventListener('DOMContentLoaded', function() { openLoginModal(); });</script>";
} 

date_default_timezone_set('Asia/Manila');
$exportTimestamp = time();
$exportDate = date('Y-m-d', $exportTimestamp);
$exportDateTime = date('F j, Y \a\t g:i A', $exportTimestamp);

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment;filename="reserve_export_'.$exportDate.'.xls"');
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
        .report-date {
            text-align: right;
            margin-bottom: 20px;
            font-style: italic;
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
    <div class="subtitle">RESERVED ITEMS REPORT</div>
    <div class="report-date">
        <h4 class="side">Balance Reserve Items:</h4>    
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
            <th>Date Added</th>
            <th>Date of Purchase</th>
            <th>Expiry Date</th>
            <th>Quantity</th>
        </tr>
        <?php
        // Get reserved items ordered by category then name (A-Z)
        $result = $conn->query("SELECT * FROM reserved_items ORDER BY category, name ASC");
        
        $currentCategory = '';
        $categoryQuantities = [];
        $grandTotalQuantities = [];
        
        while ($row = $result->fetch_assoc()):
            if ($row['category'] != $currentCategory):
                // If not first category, show category totals
                if ($currentCategory != ''):
        ?>
            <tr class="total-row">
                <td colspan="9"><?= strtoupper($currentCategory) ?> SUBTOTAL</td>
                <td>
                    <?php 
                    foreach ($categoryQuantities as $unit => $amount) {
                        if ($amount > 0) {
                            echo number_format($amount, 2) . ' ' . $unit . '<br>';
                        }
                    }
                    ?>
                </td>
            </tr>
            <?php
                endif;
                
                // Reset category totals
                $currentCategory = $row['category'];
                $categoryQuantities = [];
            ?>
            <tr>
                <td colspan="10" class="category-header"><?= strtoupper($currentCategory) ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td><?= $row['id'] ?></td>
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><?= htmlspecialchars($row['category']) ?></td>
                <td><?= htmlspecialchars($row['brand']) ?></td>
                <td><?= htmlspecialchars($row['seller_store']) ?></td>
                <td><?= htmlentities('₱', ENT_COMPAT, 'UTF-8') . number_format($row['purchase_price'], 2) ?></td>
                <td><?= date("F j, Y", strtotime($row['date_added'])) ?></td>
                <td><?= date("F j, Y", strtotime($row['purchase_date'])) ?></td>
                <td><?= date("F j, Y", strtotime($row['expiry_date'])) ?></td>
                <td><?= number_format($row['quantity'], 2) ?> <?= htmlspecialchars($row['unit']) ?></td>
            </tr>
            <?php
            // Add to category totals
            if (!isset($categoryQuantities[$row['unit']])) {
                $categoryQuantities[$row['unit']] = 0;
            }
            $categoryQuantities[$row['unit']] += $row['quantity'];
            
            // Add to grand totals
            if (!isset($grandTotalQuantities[$row['unit']])) {
                $grandTotalQuantities[$row['unit']] = 0;
            }
            $grandTotalQuantities[$row['unit']] += $row['quantity'];
            
        endwhile;
        
        // Show final category totals
        if ($currentCategory != ''):
        ?>
        <tr class="total-row">
            <td colspan="9"><?= strtoupper($currentCategory) ?> SUBTOTAL</td>
            <td>
                <?php 
                foreach ($categoryQuantities as $unit => $amount) {
                    if ($amount > 0) {
                        echo number_format($amount, 2) . ' ' . $unit . '<br>';
                    }
                }
                ?>
            </td>
        </tr>
        <?php endif; ?>
        
        <!-- Grand Total Row -->
        <tr class="grand-total-row">
            <td colspan="9">GRAND TOTAL</td>
            <td>
                <?php 
                foreach ($grandTotalQuantities as $unit => $amount) {
                    if ($amount > 0) {
                        echo number_format($amount, 2) . ' ' . $unit . '<br>';
                    }
                }
                ?>
            </td>
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