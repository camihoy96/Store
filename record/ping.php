<?php
// Prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Content-Type: application/json');

// Optional: check DB connection too so "ONLINE" means DB is also reachable
require('../dbconn.php');

if(!isset($conn) || $conn->connect_error){
    http_response_code(503);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database unreachable',
        'time'    => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Quick lightweight query — no table scan
$result = $conn->query('SELECT 1');
if(!$result){
    http_response_code(503);
    echo json_encode([
        'status'  => 'error',
        'message' => 'DB query failed',
        'time'    => date('Y-m-d H:i:s')
    ]);
    exit;
}

http_response_code(200);
echo json_encode([
    'status'  => 'ok',
    'message' => 'Server is reachable',
    'time'    => date('Y-m-d H:i:s')
]);