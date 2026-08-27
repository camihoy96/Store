<?php
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Content-Type: application/json');

// Check DB connection - from record folder
require_once __DIR__ . '/../dbconn.php';

if (!isset($conn) || $conn->connect_error) {
    http_response_code(503);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database unreachable',
        'time' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Quick lightweight query
$result = $conn->query('SELECT 1');
if (!$result) {
    http_response_code(503);
    echo json_encode([
        'status' => 'error',
        'message' => 'DB query failed',
        'time' => date('Y-m-d H:i:s')
    ]);
    exit;
}

http_response_code(200);
echo json_encode([
    'status' => 'ok',
    'message' => 'Server is reachable',
    'time' => date('Y-m-d H:i:s')
]);
exit;