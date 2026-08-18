<?php
header('Content-Type: application/json');
session_start();
require('dbconn.php');

// Handle CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'add_bread':
            addBread();
            break;
        case 'get_breads':
            getBreads();
            break;
        case 'delete_bread':
            deleteBread();
            break;
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function addBread() {
    global $pdo;
    
    if (empty($_POST['bread_name'])) {
        throw new Exception('Bread name is required');
    }

    $breadName = trim($_POST['bread_name']);
    
    $stmt = $pdo->prepare("INSERT INTO breads (name) VALUES (?)");
    $stmt->execute([$breadName]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Bread added successfully',
        'bread' => [
            'id' => $pdo->lastInsertId(),
            'name' => $breadName
        ]
    ]);
}

function getBreads() {
    global $pdo;
    
    $stmt = $pdo->query("SELECT * FROM breads ORDER BY created_at DESC");
    $breads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'breads' => $breads
    ]);
}

function deleteBread() {
    global $pdo;
    
    if (empty($_POST['bread_id'])) {
        throw new Exception('Bread ID is required');
    }
    
    $stmt = $pdo->prepare("DELETE FROM breads WHERE id = ?");
    $stmt->execute([$_POST['bread_id']]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Bread deleted successfully'
    ]);
}