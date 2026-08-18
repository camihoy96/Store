<?php
require('dbconn.php');

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="transactions.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['ID', 'Cashier', 'Date', 'Time', 'Total', 'Paid', 'Change', 'Items']);

$result = $conn->query("SELECT * FROM transactions ORDER BY id DESC");
while ($row = $result->fetch_assoc()) {
    // Decode items if it's JSON
    $itemsData = json_decode($row['items'], true);
    $formattedItems = '';

    if (is_array($itemsData)) {
        $formattedItems = implode('; ', array_map(function($item) {
            return $item['name'] . ' (x' . $item['qty'] . ')';
        }, $itemsData));
    } else {
        $formattedItems = $row['items']; // fallback if not JSON
    }

    fputcsv($output, [
        $row['id'],
        $row['cashier_name'],
        $row['date'],
        date("g:i A", strtotime($row['time'])),
        $row['total'],
        $row['paid'],
        $row['change_due'],
        $formattedItems
    ]);
}

fclose($output);
exit;
?>
