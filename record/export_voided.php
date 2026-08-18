<?php
session_start();
require('dbconn.php');

// Check if user is admin
if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: access_denied.php");
    exit();
}

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment;filename=voided_transactions_'.date('Y-m-d').'.xls');
header('Cache-Control: max-age=0');

// Start output buffering
ob_start();

// Excel HTML template with styling
echo '<!DOCTYPE html>
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
        .filter-info {
            font-weight: bold;
            margin-bottom: 10px;
        }
        .filter-detail {
            margin-left: 20px;
            margin-bottom: 5px;
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
        .footer {
            text-align: center;
            font-style: italic;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="title">FOUR ACC ANGELS BAKESHOP</div>
    <div class="subtitle">VOIDED TRANSACTIONS REPORT</div>';

// Add filter information
echo '<div class="filter-info">FILTERS APPLIED:</div>';

$filterDetails = [];

// Get filter parameters
$searchTerm = $_GET['search'] ?? '';
$timeFilter = $_GET['time_filter'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

if ($searchTerm) {
    $filterDetails[] = 'Search: ' . htmlspecialchars($searchTerm);
}

if ($timeFilter) {
    $timeLabels = [
        'today' => 'Today',
        'week' => 'This Week',
        'month' => 'This Month',
        'year' => 'This Year'
    ];
    $filterDetails[] = 'Time Period: ' . ($timeLabels[$timeFilter] ?? $timeFilter);
}

if ($startDate && $endDate) {
    $filterDetails[] = 'Date Range: ' . $startDate . ' to ' . $endDate;
} elseif ($startDate) {
    $filterDetails[] = 'Start Date: ' . $startDate;
} elseif ($endDate) {
    $filterDetails[] = 'End Date: ' . $endDate;
}

if (!empty($filterDetails)) {
    foreach ($filterDetails as $detail) {
        echo '<div class="filter-detail">' . $detail . '</div>';
    }
} else {
    echo '<div class="filter-detail">All Voided Transactions</div>';
}

// Add report date
echo '<div class="report-date">Report Generated: ' . date("F j, Y") . ' at ' . date("g:i A") . '</div>';

// Start table with headers
echo '<table>
        <tr>
            <th>ID</th>
            <th>Cashier</th>
            <th>Original Date</th>
            <th>Amount</th>
            <th>Voided By</th>
            <th>Voided At</th>
            <th>Reason</th>
        </tr>';

// Build WHERE clause based on filters
$query = "SELECT * FROM transactions WHERE status = 'voided'";
$params = [];
$types = '';

if ($searchTerm) {
    $query .= " AND (id LIKE ? OR cashier_name LIKE ? OR voided_by LIKE ? OR void_reason LIKE ? OR total LIKE ?)";
    $searchPattern = '%' . $searchTerm . '%';
    $params = array_fill(0, 5, $searchPattern);
    $types = str_repeat('s', 5);
}

if ($timeFilter) {
    $now = new DateTime();
    switch ($timeFilter) {
        case 'today':
            $startDate = $now->format('Y-m-d');
            $query .= " AND DATE(voided_at) = ?";
            $params[] = $startDate;
            $types .= 's';
            break;
        case 'week':
            $startDate = $now->modify('this week')->format('Y-m-d');
            $query .= " AND DATE(voided_at) >= ?";
            $params[] = $startDate;
            $types .= 's';
            break;
        case 'month':
            $startDate = $now->format('Y-m-01');
            $query .= " AND DATE(voided_at) >= ?";
            $params[] = $startDate;
            $types .= 's';
            break;
        case 'year':
            $startDate = $now->format('Y-01-01');
            $query .= " AND DATE(voided_at) >= ?";
            $params[] = $startDate;
            $types .= 's';
            break;
    }
}

if ($startDate && $endDate) {
    $query .= " AND DATE(voided_at) BETWEEN ? AND ?";
    $params[] = $startDate;
    $params[] = $endDate;
    $types .= 'ss';
} elseif ($startDate) {
    $query .= " AND DATE(voided_at) >= ?";
    $params[] = $startDate;
    $types .= 's';
} elseif ($endDate) {
    $query .= " AND DATE(voided_at) <= ?";
    $params[] = $endDate;
    $types .= 's';
}

$query .= " ORDER BY voided_at DESC";

// Prepare and execute query
$stmt = $conn->prepare($query);

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$totalAmount = 0;
$totalCount = 0;

// Add data rows
while ($row = $result->fetch_assoc()) {
    $totalAmount += $row['total'];
    $totalCount++;
    
    echo '<tr>
            <td>' . htmlspecialchars($row['id']) . '</td>
            <td>' . htmlspecialchars($row['cashier_name']) . '</td>
            <td>' . htmlspecialchars($row['date']) . '</td>
            <td>&#8369;' . number_format($row['total'], 2) . '</td>
            <td>' . htmlspecialchars($row['voided_by']) . '</td>
            <td>' . htmlspecialchars($row['voided_at']) . '</td>
            <td>' . htmlspecialchars($row['void_reason'] ?? 'No reason provided') . '</td>
          </tr>';
}

// Add totals row
echo '<tr style="font-weight: bold; background-color: #f5f5f5;">
        <td colspan="3" style="text-align: right;">TOTALS:</td>
        <td>&#8369;' . number_format($totalAmount, 2) . '</td>
        <td colspan="3">' . $totalCount . ' voided transactions</td>
      </tr>';

// Close table and add footer
echo '</table>
      <div class="footer">End of Report</div>
      </body>
      </html>';

// Output the content
ob_end_flush();
exit;
?>