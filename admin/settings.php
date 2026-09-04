<?php
error_reporting(0); // Disable error output to prevent breaking JSON
ini_set('display_errors', 0);

// Start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require('../dbconn.php');

if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../access_denied.php");
    exit();
}

date_default_timezone_set('Asia/Manila');

// ─── DATABASE BACKUP ───────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'backup_db') {
    // Disable time limit for large databases
    set_time_limit(300);
    
    // Get all tables
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }
    
    if (empty($tables)) {
        die("No tables found in database.");
    }
    
    // Start building the SQL dump
    $sql = "-- --------------------------------------------------------\n";
    $sql .= "-- Database Backup\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Database: " . $conn->database . "\n";
    $sql .= "-- --------------------------------------------------------\n\n";
    $sql .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
    $sql .= "SET time_zone = '+00:00';\n\n";
    
    // Get database name for CREATE DATABASE statement
    $dbResult = $conn->query("SELECT DATABASE()");
    $dbName = $dbResult->fetch_row()[0];
    $sql .= "CREATE DATABASE IF NOT EXISTS `" . $dbName . "`;\n";
    $sql .= "USE `" . $dbName . "`;\n\n";
    
    foreach ($tables as $table) {
        // Get CREATE TABLE statement
        $createResult = $conn->query("SHOW CREATE TABLE `$table`");
        $createRow = $createResult->fetch_row();
        $sql .= "-- --------------------------------------------------------\n";
        $sql .= "-- Table structure for `$table`\n";
        $sql .= "-- --------------------------------------------------------\n\n";
        $sql .= $createRow[1] . ";\n\n";
        
        // Get table data
        $dataResult = $conn->query("SELECT * FROM `$table`");
        if ($dataResult && $dataResult->num_rows > 0) {
            $sql .= "--\n-- Dumping data for table `$table`\n--\n\n";
            
            $columns = [];
            $colInfo = $conn->query("SHOW COLUMNS FROM `$table`");
            while ($col = $colInfo->fetch_assoc()) {
                $columns[] = $col['Field'];
            }
            
            $rowCount = 0;
            while ($row = $dataResult->fetch_assoc()) {
                $rowCount++;
                $sql .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (";
                $values = [];
                foreach ($columns as $col) {
                    $value = $row[$col];
                    if ($value === null) {
                        $values[] = "NULL";
                    } else {
                        // Escape the value
                        $value = $conn->real_escape_string($value);
                        $values[] = "'" . $value . "'";
                    }
                }
                $sql .= implode(', ', $values) . ");\n";
                
                // Break into chunks to avoid memory issues
                if ($rowCount % 1000 === 0) {
                    $sql .= "\n";
                }
            }
            $sql .= "\n";
        }
    }
    
    // Create filename
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    
    // Set headers for download
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    echo $sql;
    exit;
}

// ─── HANDLE REGISTRATION KEY UPDATE ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_key') {
    $newKey = trim($_POST['registration_key'] ?? '');
    if (empty($newKey)) {
        $_SESSION['swal'] = ['type' => 'error', 'title' => 'Empty Key', 'text' => 'Registration key cannot be empty.'];
    } else {
        // Check if table exists, if not create it
        $conn->query("CREATE TABLE IF NOT EXISTS registration_keys (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            reg_key VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Delete old key and insert new one
        $conn->query("TRUNCATE TABLE registration_keys");
        $stmt = $conn->prepare("INSERT INTO registration_keys (reg_key) VALUES (?)");
        $stmt->bind_param("s", $newKey);
        if ($stmt->execute()) {
            $_SESSION['swal'] = ['type' => 'success', 'title' => 'Key Updated!', 'text' => 'Registration key has been updated.'];
        } else {
            $_SESSION['swal'] = ['type' => 'error', 'title' => 'Error', 'text' => 'Failed to update registration key.'];
        }
        $stmt->close();
    }
    header("Location: settings.php?tab=general");
    exit();
}

// ─── HANDLE AJAX REQUESTS ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    // Check for database connection
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit();
    }

    switch ($_POST['ajax_action']) {

        // ── Save General Settings ──────────────────────────────────────────────
        case 'save_settings':
            $success = true;
            $message = 'Settings saved successfully';
            
            // Decode the JSON settings
            $settingsArray = json_decode($_POST['settings'], true);
            
            if (!is_array($settingsArray)) {
                echo json_encode(['success' => false, 'message' => 'Invalid settings data']);
                exit();
            }
            
            foreach ($settingsArray as $key => $value) {
                $value = $conn->real_escape_string($value);
                $key   = $conn->real_escape_string($key);
                $sql   = "INSERT INTO system_settings (setting_key, setting_value)
                          VALUES ('$key', '$value')
                          ON DUPLICATE KEY UPDATE setting_value = '$value'";
                if (!$conn->query($sql)) {
                    $success = false;
                    $message = 'Error saving settings: ' . $conn->error;
                    break;
                }
            }
            echo json_encode(['success' => $success, 'message' => $message]);
            exit();

        // ── Add Payment Method ─────────────────────────────────────────────────
        case 'add_payment_method':
            // Check if columns exist
            $hasAccountName = false;
            $hasAccountNumber = false;
            
            $checkCol = $conn->query("SHOW COLUMNS FROM payment_methods LIKE 'account_name'");
            if ($checkCol && $checkCol->num_rows > 0) $hasAccountName = true;
            
            $checkCol2 = $conn->query("SHOW COLUMNS FROM payment_methods LIKE 'account_number'");
            if ($checkCol2 && $checkCol2->num_rows > 0) $hasAccountNumber = true;
            
            $name           = $conn->real_escape_string($_POST['name']           ?? '');
            $provider       = $conn->real_escape_string($_POST['provider']       ?? '');
            $display_order  = intval($_POST['display_order']  ?? 0);
            $is_active      = isset($_POST['is_active']) ? 1 : 0;
            $description    = $conn->real_escape_string($_POST['description']    ?? '');
            $account_name   = $hasAccountName ? $conn->real_escape_string($_POST['account_name'] ?? '') : '';
            $account_number = $hasAccountNumber ? $conn->real_escape_string($_POST['account_number'] ?? '') : '';

            $qr_path = '';
            if (isset($_FILES['qr_code']) && $_FILES['qr_code']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../qr/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                $ext      = pathinfo($_FILES['qr_code']['name'], PATHINFO_EXTENSION);
                $filename = strtolower(str_replace(' ', '_', $provider)) . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['qr_code']['tmp_name'], $upload_dir . $filename)) {
                    $qr_path = 'qr/' . $filename;
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to upload QR code']);
                    exit();
                }
            }

            // Build SQL based on existing columns
            if ($hasAccountName && $hasAccountNumber) {
                $sql = "INSERT INTO payment_methods
                          (name, provider, qr_code_path, account_name, account_number,
                           display_order, is_active, description)
                        VALUES
                          ('$name','$provider','$qr_path','$account_name','$account_number',
                           $display_order, $is_active, '$description')";
            } else {
                $sql = "INSERT INTO payment_methods
                          (name, provider, qr_code_path, display_order, is_active, description)
                        VALUES
                          ('$name','$provider','$qr_path', $display_order, $is_active, '$description')";
            }

            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Payment method added successfully', 'id' => $conn->insert_id]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database Error: ' . $conn->error]);
            }
            exit();

        // ── Update Payment Method ──────────────────────────────────────────────
        case 'update_payment_method':
            $id             = intval($_POST['id']);
            $name           = $conn->real_escape_string($_POST['name']           ?? '');
            $provider       = $conn->real_escape_string($_POST['provider']       ?? '');
            $display_order  = intval($_POST['display_order']  ?? 0);
            $is_active      = isset($_POST['is_active']) ? 1 : 0;
            $description    = $conn->real_escape_string($_POST['description']    ?? '');
            
            // Check if columns exist
            $hasAccountName = false;
            $hasAccountNumber = false;
            
            $checkCol = $conn->query("SHOW COLUMNS FROM payment_methods LIKE 'account_name'");
            if ($checkCol && $checkCol->num_rows > 0) $hasAccountName = true;
            
            $checkCol2 = $conn->query("SHOW COLUMNS FROM payment_methods LIKE 'account_number'");
            if ($checkCol2 && $checkCol2->num_rows > 0) $hasAccountNumber = true;
            
            $account_name   = $hasAccountName ? $conn->real_escape_string($_POST['account_name'] ?? '') : '';
            $account_number = $hasAccountNumber ? $conn->real_escape_string($_POST['account_number'] ?? '') : '';

            // Keep existing QR path unless a new file is uploaded
            $current = $conn->query("SELECT qr_code_path FROM payment_methods WHERE id = $id")->fetch_assoc();
            $qr_path = $current['qr_code_path'] ?? '';

            if (isset($_FILES['qr_code']) && $_FILES['qr_code']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../qr/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                $ext      = pathinfo($_FILES['qr_code']['name'], PATHINFO_EXTENSION);
                $filename = strtolower(str_replace(' ', '_', $provider)) . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['qr_code']['tmp_name'], $upload_dir . $filename)) {
                    // Delete old QR file if it exists
                    if ($qr_path && file_exists('../' . $qr_path)) unlink('../' . $qr_path);
                    $qr_path = 'qr/' . $filename;
                }
            }

            // Build UPDATE SQL based on existing columns
            if ($hasAccountName && $hasAccountNumber) {
                $sql = "UPDATE payment_methods SET
                          name           = '$name',
                          provider       = '$provider',
                          qr_code_path   = '$qr_path',
                          account_name   = '$account_name',
                          account_number = '$account_number',
                          display_order  = $display_order,
                          is_active      = $is_active,
                          description    = '$description'
                        WHERE id = $id";
            } else {
                $sql = "UPDATE payment_methods SET
                          name           = '$name',
                          provider       = '$provider',
                          qr_code_path   = '$qr_path',
                          display_order  = $display_order,
                          is_active      = $is_active,
                          description    = '$description'
                        WHERE id = $id";
            }

            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Payment method updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database Error: ' . $conn->error]);
            }
            exit();

        // ── Delete Payment Method ──────────────────────────────────────────────
        case 'delete_payment_method':
            $id      = intval($_POST['id']);
            $current = $conn->query("SELECT qr_code_path FROM payment_methods WHERE id = $id")->fetch_assoc();
            if ($current && $current['qr_code_path'] && file_exists('../' . $current['qr_code_path'])) {
                unlink('../' . $current['qr_code_path']);
            }
            if ($conn->query("DELETE FROM payment_methods WHERE id = $id")) {
                echo json_encode(['success' => true, 'message' => 'Payment method deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database Error: ' . $conn->error]);
            }
            exit();

        // ── Upload Logo ────────────────────────────────────────────────────────
        case 'upload_logo':
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../image/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                $ext         = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                $filename    = 'logo_' . time() . '.' . $ext;
                $target_path = $upload_dir . $filename;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_path)) {
                    $logo_path = 'image/' . $filename;
                    $conn->query("INSERT INTO system_settings (setting_key, setting_value)
                                  VALUES ('logo_path', '$logo_path')
                                  ON DUPLICATE KEY UPDATE setting_value = '$logo_path'");
                    echo json_encode(['success' => true, 'message' => 'Logo uploaded successfully', 'path' => $logo_path]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to upload logo']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'No file uploaded']);
            }
            exit();
    }
}

// ─── FETCH SETTINGS ────────────────────────────────────────────────────────────
$settings = [];
$result   = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($result) while ($row = $result->fetch_assoc()) $settings[$row['setting_key']] = $row['setting_value'];

// ─── FETCH PAYMENT METHODS ─────────────────────────────────────────────────────
$payment_methods = [];
$result = $conn->query("SELECT * FROM payment_methods ORDER BY display_order ASC");
if ($result) while ($row = $result->fetch_assoc()) $payment_methods[] = $row;

// ─── FETCH REGISTRATION KEY ────────────────────────────────────────────────────
// Check if table exists
$conn->query("CREATE TABLE IF NOT EXISTS registration_keys (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    reg_key VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$keyResult  = $conn->query("SELECT reg_key FROM registration_keys ORDER BY id DESC LIMIT 1");
$currentKey = $keyResult->fetch_assoc()['reg_key'] ?? 'FOURACC';

// ─── SET PAGE TITLE FOR HEADER ────────────────────────────────────────────────
$pageTitle = 'System Settings';
$activePage = 'settings';

// ─── DISPLAY SWEETALERT FROM SESSION ───────────────────────────────────────────
if (isset($_SESSION['swal'])) {
    $swal = $_SESSION['swal'];
    echo "<script>document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            icon: '{$swal['type']}',
            title: '{$swal['title']}',
            text: '{$swal['text']}',
            confirmButtonColor: '#ff8800',
            background: '#1e2330',
            color: '#e8eaf0'
        });
    });</script>";
    unset($_SESSION['swal']);
}

// Save theme colors
$themeColors = [
    'theme_bg_color',
    'theme_card_color',
    'theme_accent_color'
];

foreach ($themeColors as $colorKey) {
    if (isset($_POST[$colorKey])) {
        $colorValue = $_POST[$colorKey];
        // Validate hex color
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $colorValue)) {
            // Save to system_settings
            $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->bind_param("ss", $colorKey, $colorValue);
            $stmt->execute();
        }
    }
}
?>

<style>
:root {
  --orange: #ff8800;
  --orange-dk: #cc5500;
  --orange-lt: #ffaa33;
  --green: #00c853;
  --red: #ff4444;
  --blue: #4488ff;
  --bg: #111318;
  --bg2: #181c22;
  --bg3: #1e2330;
  --card: #1e2330;
  --card2: #242b3a;
  --border: #2a3145;
  --border2: #323d55;
  --text: #e8eaf0;
  --text2: #9aa3bc;
  --text3: #5a6380;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Inter', 'Segoe UI', sans-serif;
  font-size: 13px;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

/* ── Main ── */
.main {
  margin-top: 52px;
  padding: 20px;
  flex: 1;
  margin-bottom: 26px;
}
.main.sidebar-open { margin-left: 240px; }

/* ── Page Header ── */
.page-header { margin-bottom: 24px; }
.page-header h2 { font-size: 24px; font-weight: 800; color: var(--text); margin-bottom: 4px; }
.page-header p  { font-size: 12px; color: var(--text3); }

/* ── Tabs ── */
.tabs {
  display: flex; gap: 8px;
  border-bottom: 2px solid var(--border);
  margin-bottom: 24px;
}
.tab-btn {
  padding: 10px 20px;
  background: transparent; border: none;
  color: var(--text3); font-size: 13px; font-weight: 600;
  cursor: pointer; transition: all 0.2s; position: relative;
}
.tab-btn:hover { color: var(--orange-lt); }
.tab-btn.active { color: var(--orange); }
.tab-btn.active::after {
  content: ''; position: absolute;
  bottom: -2px; left: 0; right: 0;
  height: 2px; background: var(--orange);
}
.tab-content        { display: none; }
.tab-content.active { display: block; }

/* ── Settings Grid & Cards ── */
.settings-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
  gap: 20px;
  margin-bottom: 24px;
}
.settings-card {
  background: var(--card); border: 1px solid var(--border);
  border-radius: 12px; padding: 20px;
}
.settings-card h3 { font-size: 14px; font-weight: 700; margin-bottom: 16px; color: var(--orange-lt); }

/* ─── Registration Key Card ─── */
.reg-key-card {
  background: var(--card); border: 1px solid var(--border);
  border-radius: 12px; padding: 20px;
  margin-bottom: 20px;
}
.reg-key-card h3 { font-size: 14px; font-weight: 700; margin-bottom: 16px; color: var(--orange-lt); }
.reg-key-row {
  background: var(--bg3); border: 1px solid var(--border);
  border-radius: 8px; padding: 12px 16px;
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 16px;
}
.reg-key-txt {
  font-family: monospace; font-size: 14px;
  color: var(--orange-lt); font-weight: 700;
  letter-spacing: 1px;
}
.copy-btn-sm {
  background: var(--bg); border: 1px solid var(--border);
  border-radius: 6px; padding: 4px 12px;
  font-size: 11px; color: var(--text2); cursor: pointer;
  transition: all 0.2s;
}
.copy-btn-sm:hover { background: var(--orange); border-color: var(--orange); color: white; }

/* ─── Info Box ─── */
.info-box {
  background: rgba(255,136,0,0.1); border: 1px solid rgba(255,136,0,0.2);
  border-radius: 8px; padding: 12px; font-size: 12px;
  color: var(--text2); margin-bottom: 16px;
}
.info-box.warning {
  background: rgba(255,204,0,0.1);
  border-color: rgba(255,204,0,0.2);
  color: var(--yellow);
}
.info-box.danger {
  background: rgba(255,68,68,0.1);
  border-color: rgba(255,68,68,0.2);
  color: #ff8888;
}

/* ─── Form Groups ─── */
.form-group { margin-bottom: 16px; }
.form-group label {
  display: block; font-size: 11px; font-weight: 600;
  color: var(--text3); margin-bottom: 6px;
  text-transform: uppercase; letter-spacing: 0.5px;
}
.form-group input,
.form-group textarea,
.form-group select {
  width: 100%; padding: 8px 12px;
  background: var(--bg3); border: 1px solid var(--border);
  border-radius: 6px; color: var(--text);
  font-size: 13px; font-family: inherit;
  transition: all 0.2s;
}
.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus { outline: none; border-color: var(--orange); }
.form-group textarea { min-height: 80px; resize: vertical; }
.form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

/* ── Payment Methods Table ── */
.payment-methods-table {
  width: 100%; border-collapse: collapse;
  background: var(--card); border-radius: 12px; overflow: hidden;
}
.payment-methods-table th {
  background: var(--card2); padding: 12px 16px;
  text-align: left; font-size: 11px; font-weight: 700;
  color: var(--text3); text-transform: uppercase; letter-spacing: 0.5px;
}
.payment-methods-table td {
  padding: 12px 16px; border-bottom: 1px solid var(--border); font-size: 12px;
}
.payment-methods-table tr:last-child td { border-bottom: none; }
.qr-preview { width: 50px; height: 50px; object-fit: cover; border-radius: 6px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 600; }
.badge-active   { background: rgba(0,200,83,0.2);  color: var(--green); }
.badge-inactive { background: rgba(255,68,68,0.2); color: var(--red); }
.btn-icon { background: transparent; border: none; cursor: pointer; font-size: 16px; padding: 4px 8px; border-radius: 4px; transition: all 0.2s; }
.btn-icon:hover { background: var(--bg3); }
.btn-edit   { color: var(--blue); }
.btn-delete { color: var(--red); }

/* ── Buttons ── */
.btn {
  padding: 8px 20px; border: none; border-radius: 6px;
  font-size: 12px; font-weight: 600; cursor: pointer;
  transition: all 0.2s;
  display: inline-flex; align-items: center; gap: 6px;
  text-decoration: none;
}
.btn-primary   { background: linear-gradient(135deg, var(--orange), var(--orange-dk)); color: white; }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(255,136,0,0.3); }
.btn-secondary { background: var(--bg3); border: 1px solid var(--border); color: var(--text2); }
.btn-secondary:hover { background: var(--border2); color: var(--text); }
.btn-danger    { background: linear-gradient(135deg, var(--red), #aa1111); color: white; }
.btn-danger:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(255,68,68,0.3); }
.btn-success   { background: linear-gradient(135deg, var(--green), #007a2e); color: white; }
.btn-success:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,200,83,0.3); }
.btn-sm { padding: 4px 12px; font-size: 11px; }

/* ── Backup Card ── */
.backup-card {
  background: var(--card); border: 1px solid var(--border);
  border-radius: 12px; padding: 20px;
  margin-top: 20px;
}
.backup-card h3 { font-size: 14px; font-weight: 700; margin-bottom: 16px; color: var(--orange-lt); }
.backup-info {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.backup-stat {
  background: var(--bg3); border: 1px solid var(--border);
  border-radius: 8px; padding: 12px 16px;
}
.backup-stat .label { font-size: 10px; color: var(--text3); text-transform: uppercase; letter-spacing: 0.5px; }
.backup-stat .value { font-size: 16px; font-weight: 700; color: var(--text); margin-top: 4px; }
.backup-actions { display: flex; gap: 10px; flex-wrap: wrap; }

/* ── Modal ── */
.modal {
  display: none;
  position: fixed; top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.8); backdrop-filter: blur(6px);
  z-index: 9000; align-items: center; justify-content: center;
}
.modal.show { display: flex; }
.modal-content {
  background: var(--card2); border: 1px solid var(--border);
  border-radius: 12px; width: 90%; max-width: 520px;
  max-height: 90vh; overflow-y: auto;
}
.modal-header {
  padding: 16px 20px; border-bottom: 1px solid var(--border);
  display: flex; justify-content: space-between; align-items: center;
  position: sticky; top: 0; background: var(--card2); z-index: 1;
}
.modal-header h3 { font-size: 16px; font-weight: 700; color: var(--orange-lt); }
.modal-close { background: transparent; border: none; font-size: 20px; cursor: pointer; color: var(--text3); }
.modal-body   { padding: 20px; }
.modal-footer {
  padding: 16px 20px; border-top: 1px solid var(--border);
  display: flex; justify-content: flex-end; gap: 10px;
  position: sticky; bottom: 0; background: var(--card2);
}

/* QR preview in modal */
.qr-preview-box {
  display: none; margin-top: 8px; text-align: center;
  background: var(--bg3); border-radius: 8px; padding: 10px;
}
.qr-preview-box img { max-width: 150px; max-height: 150px; border-radius: 6px; }

/* Logo Preview */
.logo-preview { margin-top: 10px; padding: 10px; background: var(--bg3); border-radius: 8px; text-align: center; }
.logo-preview img { max-width: 100%; max-height: 100px; border-radius: 4px; }
</style>

<!-- Include Admin Header -->
<?php include('../include/admin_header.php'); ?>

<!-- Main -->
<div class="main" id="mainContent">
  <div class="page-header">
    <h2>⚙️ System Settings</h2>
    <p>Configure business information, payment methods, registration key, and system preferences</p>
  </div>

  <!-- Tabs -->
  <div class="tabs">
    <button class="tab-btn active" onclick="switchTab('general')">General</button>
    <button class="tab-btn"        onclick="switchTab('payment')">Payment Methods</button>
    <button class="tab-btn"        onclick="switchTab('appearance')">Appearance</button>
    <button class="tab-btn"        onclick="switchTab('backup')">💾 Backup</button>
  </div>

  <!-- ══ GENERAL TAB ══════════════════════════════════════════════════════════ -->
  <div id="tab-general" class="tab-content active">
    
    <!-- Registration Key Card -->
    <div class="reg-key-card">
      <h3>🔐 Registration Key</h3>
      <div class="info-box">
        ⚠ The registration key is required when new users sign up. Keep it secure and share only with trusted staff.
      </div>
      <div class="reg-key-row">
        <div class="reg-key-txt" id="regKey"><?= htmlspecialchars($currentKey) ?></div>
        <button class="copy-btn-sm" onclick="copyKey()">📋 Copy</button>
      </div>
      <button class="btn btn-primary" onclick="openKeyModal()">🗝 Change Registration Key</button>
    </div>

    <form id="generalSettingsForm">
      <div class="settings-grid">

        <div class="settings-card">
          <h3>🏢 Business Information</h3>
          <div class="form-group">
            <label>Business Name</label>
            <input type="text" name="business_name"
                   value="<?= htmlspecialchars($settings['business_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>System Subtitle</label>
            <input type="text" name="business_subtitle"
                   value="<?= htmlspecialchars($settings['business_subtitle'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Business Address</label>
            <textarea name="business_address"><?= htmlspecialchars($settings['business_address'] ?? 'Upper Batinguel, Dumaguete City, Negros Oriental 6200') ?></textarea>
          </div>
          <div class="form-group">
            <label>Contact Number</label>
            <input type="text" name="business_phone"
                   value="<?= htmlspecialchars($settings['business_phone'] ?? '0905 615 2262') ?>">
          </div>
        </div>

        <div class="settings-card">
          <h3>💰 Financial Settings</h3>
          <div class="form-group">
            <label>Currency Symbol</label>
            <input type="text" name="currency_symbol" maxlength="3"
                   value="<?= htmlspecialchars($settings['currency_symbol'] ?? '₱') ?>">
          </div>
          <div class="form-group">
            <label>Tax Rate (%)</label>
            <input type="number" name="tax_rate" step="0.01"
                   value="<?= htmlspecialchars($settings['tax_rate'] ?? '0') ?>">
          </div>
          <div class="form-group">
            <label>Enable Cash Payments</label>
            <select name="enable_cash">
              <option value="1" <?= ($settings['enable_cash'] ?? '1') == '1' ? 'selected' : '' ?>>Yes</option>
              <option value="0" <?= ($settings['enable_cash'] ?? '1') == '0' ? 'selected' : '' ?>>No</option>
            </select>
          </div>
          <div class="form-group">
            <label>Enable E-Wallet Payments</label>
            <select name="enable_ewallet">
              <option value="1" <?= ($settings['enable_ewallet'] ?? '1') == '1' ? 'selected' : '' ?>>Yes</option>
              <option value="0" <?= ($settings['enable_ewallet'] ?? '1') == '0' ? 'selected' : '' ?>>No</option>
            </select>
          </div>
        </div>

        <div class="settings-card">
          <h3>📊 Stock Thresholds</h3>
          <div class="form-group">
            <label>Low Stock Threshold (Pieces)</label>
            <input type="number" name="low_stock_threshold_pieces"
                   value="<?= htmlspecialchars($settings['low_stock_threshold_pieces'] ?? '20') ?>">
          </div>
          <div class="form-group">
            <label>Low Stock Threshold (kg)</label>
            <input type="number" name="low_stock_threshold_kg" step="0.1"
                   value="<?= htmlspecialchars($settings['low_stock_threshold_kg'] ?? '20') ?>">
          </div>
        </div>
    <div class="settings-card">
    <h3>🛒 Custom Product Settings</h3>
    <div class="form-group">
        <label>Enable Custom Product Strip</label>
        <select name="enable_custom_product">
            <option value="1" <?= ($settings['enable_custom_product'] ?? '1') == '1' ? 'selected' : '' ?>>Yes - Show on POS</option>
            <option value="0" <?= ($settings['enable_custom_product'] ?? '1') == '0' ? 'selected' : '' ?>>No - Hide from POS</option>
        </select>
    </div>
    <div class="form-group">
        <label>Custom Product Label</label>
        <input type="text" name="custom_product_label" 
               value="<?= htmlspecialchars($settings['custom_product_label'] ?? '➕ Add Custom Product') ?>">
    </div>
   <div class="form-group">
    <label>Show Bread Dropdown</label>  <!-- This can be anything -->
    <select name="enable_bread_type">   <!-- This MUST stay the same for saving -->
        <option value="1" <?= ($settings['enable_bread_type'] ?? '1') == '1' ? 'selected' : '' ?>>Yes - Show Bread Type</option>
        <option value="0" <?= ($settings['enable_bread_type'] ?? '1') == '0' ? 'selected' : '' ?>>No - Hide Bread Type</option>
    </select>
</div>
    <hr style="border-color:var(--border);margin:16px 0;">
    <div style="font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;">
        Field Labels
    </div>
    <div class="form-group">
        <label>Custom Name Label</label>
        <input type="text" name="custom_name_label" 
               value="<?= htmlspecialchars($settings['custom_name_label'] ?? 'Custom Name') ?>">
    </div>
    <div class="form-group">
        <label>Bread Type Label</label>
        <input type="text" name="bread_type_label" 
               value="<?= htmlspecialchars($settings['bread_type_label'] ?? 'Bread Type') ?>">
    </div>
    <div class="form-group">
        <label>Price Label</label>
        <input type="text" name="price_label" 
               value="<?= htmlspecialchars($settings['price_label'] ?? 'Price') ?>">
    </div>
    <div class="form-group">
        <label>Qty Label</label>
        <input type="text" name="qty_label" 
               value="<?= htmlspecialchars($settings['qty_label'] ?? 'Qty') ?>">
    </div>
    <div class="form-group">
        <label>Unit Label</label>
        <input type="text" name="unit_label" 
               value="<?= htmlspecialchars($settings['unit_label'] ?? 'Unit') ?>">
    </div>
    <div class="form-group">
        <label>Add Button Label</label>
        <input type="text" name="add_button_label" 
               value="<?= htmlspecialchars($settings['add_button_label'] ?? 'Add 🛒') ?>">
    </div>
</div>
        <div class="settings-card">
          <h3>🖨️ Receipt Settings</h3>
          <div class="form-group">
            <label>Receipt Footer Text</label>
            <textarea name="receipt_footer"><?= htmlspecialchars($settings['receipt_footer'] ?? 'Thank you for your purchase!') ?></textarea>
          </div>
          <div class="form-group">
            <label>Auto Print Receipt</label>
            <select name="auto_print_receipt">
              <option value="1" <?= ($settings['auto_print_receipt'] ?? '1') == '1' ? 'selected' : '' ?>>Yes</option>
              <option value="0" <?= ($settings['auto_print_receipt'] ?? '1') == '0' ? 'selected' : '' ?>>No</option>
            </select>
          </div>
          <div class="form-group">
            <label>Receipt Width (mm)</label>
            <input type="number" name="receipt_width"
                   value="<?= htmlspecialchars($settings['receipt_width'] ?? '58') ?>">
          </div>
        </div>

      </div>
      <div style="display:flex;justify-content:flex-end;margin-bottom:20px;">
        <button type="submit" class="btn btn-primary">💾 Save All Settings</button>
      </div>
    </form>
  </div>

  <!-- ══ PAYMENT METHODS TAB ═══════════════════════════════════════════════════ -->
  <div id="tab-payment" class="tab-content">
    <div style="margin-bottom:20px;display:flex;justify-content:flex-end;">
      <button class="btn btn-primary" onclick="openPaymentModal()">➕ Add Payment Method</button>
    </div>

    <table class="payment-methods-table">
      <thead>
        <tr>
          <th>QR Code</th>
          <th>Name / Account</th>
          <th>Provider</th>
          <th>Order</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="paymentMethodsList">
        <?php foreach ($payment_methods as $pm): ?>
        <tr data-id="<?= $pm['id'] ?>"
            data-name="<?= htmlspecialchars($pm['name']) ?>"
            data-provider="<?= htmlspecialchars($pm['provider']) ?>"
            data-account-name="<?= htmlspecialchars($pm['account_name'] ?? '') ?>"
            data-account-number="<?= htmlspecialchars($pm['account_number'] ?? '') ?>"
            data-order="<?= $pm['display_order'] ?>"
            data-desc="<?= htmlspecialchars($pm['description'] ?? '') ?>"
            data-active="<?= $pm['is_active'] ?>"
            data-qr="<?= htmlspecialchars($pm['qr_code_path'] ?? '') ?>">
          <td>
            <?php if (!empty($pm['qr_code_path']) && file_exists('../' . $pm['qr_code_path'])): ?>
              <img src="../<?= htmlspecialchars($pm['qr_code_path']) ?>" class="qr-preview" alt="QR">
            <?php else: ?>
              <span style="color:var(--text3);font-size:11px;">No QR</span>
            <?php endif; ?>
          </td>
          <td>
            <strong><?= htmlspecialchars($pm['name']) ?></strong>
            <?php if (!empty($pm['account_name'])): ?>
              <div style="font-size:10px;color:var(--text3);margin-top:2px;">
                <?= htmlspecialchars($pm['account_name']) ?>
                <?php if (!empty($pm['account_number'])): ?>
                  &nbsp;·&nbsp;<?= htmlspecialchars($pm['account_number']) ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($pm['provider']) ?></td>
          <td><?= $pm['display_order'] ?></td>
          <td>
            <span class="badge <?= $pm['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
              <?= $pm['is_active'] ? 'Active' : 'Inactive' ?>
            </span>
          </td>
          <td>
            <button class="btn-icon btn-edit"   onclick="editPaymentMethod(<?= $pm['id'] ?>)" title="Edit">✏️</button>
            <button class="btn-icon btn-delete" onclick="deletePaymentMethod(<?= $pm['id'] ?>)" title="Delete">🗑️</button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($payment_methods)): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--text3);padding:30px;">No payment methods added yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ══ APPEARANCE TAB ════════════════════════════════════════════════════════ -->
<div id="tab-appearance" class="tab-content">
  <div class="settings-grid">
    <div class="settings-card">
      <h3>🎨 Logo & Branding</h3>
      <div class="form-group">
        <label>Upload Logo</label>
        <input type="file" id="logoUpload" accept="image/*" onchange="previewLogo(this)">
        <div class="logo-preview" id="logoPreview">
          <?php if (!empty($settings['logo_path']) && file_exists('../' . $settings['logo_path'])): ?>
            <img src="../<?= htmlspecialchars($settings['logo_path']) ?>" alt="Logo">
          <?php else: ?>
            <p style="color:var(--text3);">No logo uploaded</p>
          <?php endif; ?>
        </div>
      </div>
      <button class="btn btn-primary" onclick="uploadLogo()">⬆ Upload Logo</button>
    </div>
    <!-- ✅ NEW: Theme Color Settings -->
    <div class="settings-card">
      <h3>🎨 Theme Colors</h3>
      <div class="form-group">
        <label>Background Color</label>
        <div style="display:flex;gap:10px;align-items:center;">
          <input type="color" id="themeBgColor" 
                 name="theme_bg_color"
                 value="<?= htmlspecialchars($settings['theme_bg_color'] ?? '#111318') ?>"
                 style="width:60px;height:40px;border:2px solid var(--border);border-radius:6px;cursor:pointer;background:none;padding:2px;">
          <input type="text" id="themeBgColorText" 
                 value="<?= htmlspecialchars($settings['theme_bg_color'] ?? '#111318') ?>"
                 style="font-family:monospace;font-size:12px;background:var(--bg3);border:1.5px solid var(--border);border-radius:6px;padding:8px 12px;color:var(--text3);width:120px;"
                 onchange="document.getElementById('themeBgColor').value = this.value; updateThemePreview();">
        </div>
        <small style="color:var(--text3);display:block;margin-top:4px;">
          Main page background color (dark themes recommended)
        </small>
      </div>
      <div class="form-group">
        <label>Card/Surface Color</label>
        <div style="display:flex;gap:10px;align-items:center;">
          <input type="color" id="themeCardColor" 
                 name="theme_card_color"
                 value="<?= htmlspecialchars($settings['theme_card_color'] ?? '#1e2330') ?>"
                 style="width:60px;height:40px;border:2px solid var(--border);border-radius:6px;cursor:pointer;background:none;padding:2px;">
          <input type="text" id="themeCardColorText" 
                 value="<?= htmlspecialchars($settings['theme_card_color'] ?? '#1e2330') ?>"
                 style="font-family:monospace;font-size:12px;background:var(--bg3);border:1.5px solid var(--border);border-radius:6px;padding:8px 12px;color:var(--text3);width:120px;"
                 onchange="document.getElementById('themeCardColor').value = this.value; updateThemePreview();">
        </div>
      </div>
      <div class="form-group">
        <label>Accent Color</label>
        <div style="display:flex;gap:10px;align-items:center;">
          <input type="color" id="themeAccentColor" 
                 name="theme_accent_color"
                 value="<?= htmlspecialchars($settings['theme_accent_color'] ?? '#ff8800') ?>"
                 style="width:60px;height:40px;border:2px solid var(--border);border-radius:6px;cursor:pointer;background:none;padding:2px;">
          <input type="text" id="themeAccentColorText" 
                 value="<?= htmlspecialchars($settings['theme_accent_color'] ?? '#ff8800') ?>"
                 style="font-family:monospace;font-size:12px;background:var(--bg3);border:1.5px solid var(--border);border-radius:6px;padding:8px 12px;color:var(--text3);width:120px;"
                 onchange="document.getElementById('themeAccentColor').value = this.value; updateThemePreview();">
        </div>
      </div>
    <div class="form-group">
    <label>Logo Pill Background</label>
    <div style="display:flex;gap:10px;align-items:center;">
      <input type="color" id="logoPillBgColor" 
             name="logo_pill_bg_color"
             value="<?= htmlspecialchars($settings['logo_pill_bg_color'] ?? '#ff8800') ?>"
             style="width:60px;height:40px;border:2px solid var(--border);border-radius:6px;cursor:pointer;background:none;padding:2px;">
      <input type="text" id="logoPillBgColorText" 
             value="<?= htmlspecialchars($settings['logo_pill_bg_color'] ?? '#ff8800') ?>"
             style="font-family:monospace;font-size:12px;background:var(--bg3);border:1.5px solid var(--border);border-radius:6px;padding:8px 12px;color:var(--text3);width:120px;"
             onchange="document.getElementById('logoPillBgColor').value = this.value; updateThemePreview();">
    </div>
    <small style="color:var(--text3);display:block;margin-top:4px;">
      Background color for the logo/branding pill in the header
    </small>
  </div>
  <!-- ✅ ADD THIS: Logo Pill Text Color -->
  <div class="form-group">
    <label>Logo Pill Text Color</label>
    <div style="display:flex;gap:10px;align-items:center;">
      <input type="color" id="logoPillTextColor" 
             name="logo_pill_text_color"
             value="<?= htmlspecialchars($settings['logo_pill_text_color'] ?? '#ffffff') ?>"
             style="width:60px;height:40px;border:2px solid var(--border);border-radius:6px;cursor:pointer;background:none;padding:2px;">
      <input type="text" id="logoPillTextColorText" 
             value="<?= htmlspecialchars($settings['logo_pill_text_color'] ?? '#ffffff') ?>"
             style="font-family:monospace;font-size:12px;background:var(--bg3);border:1.5px solid var(--border);border-radius:6px;padding:8px 12px;color:var(--text3);width:120px;"
             onchange="document.getElementById('logoPillTextColor').value = this.value; updateThemePreview();">
    </div>
    <small style="color:var(--text3);display:block;margin-top:4px;">
      Text color for the logo/branding text
    </small>
  </div>
  <!-- Update preset buttons to include logo colors -->
    <div style="margin-top:16px;">
        <label style="font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;display:block;">
          Quick Presets
        </label>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
          <button type="button" class="btn btn-secondary" style="font-size:10px;padding:6px 12px;"
                  onclick="applyThemePreset('#111318', '#1e2330', '#ff8800', '#ff8800', '#ffffff')">
            🟠 Orange Dark
          </button>
          <button type="button" class="btn btn-secondary" style="font-size:10px;padding:6px 12px;"
                  onclick="applyThemePreset('#0a0e14', '#1a2029', '#00c853', '#00c853', '#ffffff')">
            🟢 Green Dark
          </button>
          <button type="button" class="btn btn-secondary" style="font-size:10px;padding:6px 12px;"
                  onclick="applyThemePreset('#0d1117', '#161b22', '#4488ff', '#4488ff', '#ffffff')">
            🔵 Blue Dark
          </button>
          <button type="button" class="btn btn-secondary" style="font-size:10px;padding:6px 12px;"
                  onclick="applyThemePreset('#1a0a0a', '#261515', '#ff4444', '#ff4444', '#ffffff')">
            🔴 Red Dark
          </button>
          <button type="button" class="btn btn-secondary" style="font-size:10px;padding:6px 12px;"
                  onclick="applyThemePreset('#ffffff', '#f5f5f5', '#ff8800', '#ff8800', '#ffffff')">
            ⚪ Light Mode
          </button>
        </div>
      </div>
      <!-- ✅ ADD THIS: Save Button for Appearance Settings -->
      <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px;">
        <button type="button" class="btn btn-secondary" onclick="resetThemeColors()">🔄 Reset to Default</button>
        <button type="button" class="btn btn-primary" onclick="saveThemeColors()">💾 Save Theme Colors</button>
      </div>
    </div>
  </div>
</div>
</div>

  <!-- ══ BACKUP TAB ═══════════════════════════════════════════════════════════ -->
  <div id="tab-backup" class="tab-content">
    <div class="backup-card">
      <h3>💾 Database Backup</h3>
      
      <div class="info-box warning">
        ⚠️ Creating a backup will download your entire database as a SQL file. 
        This file contains all your data including transactions, products, users, and settings.
        Keep it secure and store it in a safe location.
      </div>

      <?php
      // Get database statistics
      $tableCount = 0;
      $totalRows = 0;
      $totalSize = 0;
      
      $tablesResult = $conn->query("SHOW TABLES");
      if ($tablesResult) {
          $tableCount = $tablesResult->num_rows;
          while ($row = $tablesResult->fetch_row()) {
              $tableName = $row[0];
              $rowResult = $conn->query("SELECT COUNT(*) as count FROM `$tableName`");
              if ($rowResult) {
                  $totalRows += $rowResult->fetch_assoc()['count'];
              }
              $sizeResult = $conn->query("SHOW TABLE STATUS LIKE '$tableName'");
              if ($sizeResult) {
                  $sizeRow = $sizeResult->fetch_assoc();
                  $totalSize += $sizeRow['Data_length'] + $sizeRow['Index_length'];
              }
          }
      }
      
      $sizeFormatted = '';
      if ($totalSize > 0) {
          $units = ['B', 'KB', 'MB', 'GB'];
          $i = 0;
          while ($totalSize >= 1024 && $i < 3) {
              $totalSize /= 1024;
              $i++;
          }
          $sizeFormatted = round($totalSize, 2) . ' ' . $units[$i];
      } else {
          $sizeFormatted = '0 B';
      }
      ?>

      <div class="backup-info">
        <div class="backup-stat">
          <div class="label">Tables</div>
          <div class="value"><?= $tableCount ?></div>
        </div>
        <div class="backup-stat">
          <div class="label">Total Records</div>
          <div class="value"><?= number_format($totalRows) ?></div>
        </div>
        <div class="backup-stat">
          <div class="label">Database Size</div>
          <div class="value"><?= $sizeFormatted ?></div>
        </div>
        <div class="backup-stat">
          <div class="label">Last Backup</div>
          <div class="value" style="font-size:13px;color:var(--text3);">
            <?php
            // Check if backup directory exists and get latest backup file
            $backupDir = '../backups/';
            $latestBackup = null;
            if (file_exists($backupDir)) {
                $files = glob($backupDir . '*.sql');
                if (!empty($files)) {
                    $latestBackup = basename(end($files));
                }
            }
            echo $latestBackup ? date('Y-m-d H:i:s', filemtime($backupDir . $latestBackup)) : 'No backup found';
            ?>
          </div>
        </div>
      </div>

      <div class="backup-actions">
        <a href="?action=backup_db" class="btn btn-success">
          💾 Download Full Backup
        </a>
        <button class="btn btn-primary" onclick="backupWithTimestamp()">
          ⏰ Backup with Timestamp
        </button>
      </div>
      
      <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);">
        <div class="info-box" style="font-size:11px;">
          💡 <strong>Tip:</strong> Regular backups are essential for data safety. 
          We recommend backing up at least once a week, or before making major system changes.
          The backup will be downloaded as a .sql file which can be imported using phpMyAdmin or MySQL command line.
        </div>
      </div>
    </div>
  </div>
</div><!-- end .main -->

<!-- Include Admin Footer -->
<?php include('../include/admin_footer.php'); ?>

<!-- ══ REGISTRATION KEY MODAL ══════════════════════════════════════════════════ -->
<div id="keyModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>🗝 Change Registration Key</h3>
      <button class="modal-close" onclick="closeKeyModal()">✕</button>
    </div>
    <form method="POST" action="settings.php">
      <input type="hidden" name="action" value="update_key">
      <div class="modal-body">
        <div class="info-box">
          ⚠ The registration key is required when new users sign up. Keep it secure and share only with trusted staff.
        </div>
        <div class="form-group">
          <label>Current Key</label>
          <div style="background:var(--bg3);border:1.5px solid var(--border);border-radius:6px;padding:9px 12px;font-family:monospace;font-size:12px;color:var(--text3);">
            <?= htmlspecialchars($currentKey) ?>
          </div>
        </div>
        <div class="form-group">
          <label>New Registration Key *</label>
          <input type="text" name="registration_key" required placeholder="Enter new registration key">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeKeyModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">🗝 Update Key</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ PAYMENT METHOD MODAL ══════════════════════════════════════════════════ -->
<div id="paymentModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3 id="modalTitle">Add Payment Method</h3>
      <button class="modal-close" onclick="closePaymentModal()">✕</button>
    </div>
    <form id="paymentMethodForm" enctype="multipart/form-data">
      <div class="modal-body">
        <input type="hidden" name="ajax_action" id="ajaxAction" value="add_payment_method">
        <input type="hidden" name="id" id="paymentId">

        <div class="form-group">
          <label>Payment Name</label>
          <input type="text" name="name" id="paymentName" required
                 placeholder="e.g., GCash, Maya, GrabPay">
        </div>

        <div class="form-group">
          <label>Provider Key</label>
          <input type="text" name="provider" id="paymentProvider" required
                 placeholder="e.g., GCash (must match exactly)">
          <small style="color:var(--text3);display:block;margin-top:4px;">
            Used to match QR config in POS. Recommended: GCash, Maya, GrabPay, or Other
          </small>
        </div>

        <div class="form-row-2">
          <div class="form-group">
            <label>Account Name</label>
            <input type="text" name="account_name" id="paymentAccountName"
                   placeholder="JUAN DELA CRUZ">
          </div>
          <div class="form-group">
            <label>Account Number</label>
            <input type="text" name="account_number" id="paymentAccountNumber"
                   placeholder="09191234567">
          </div>
        </div>

        <div class="form-group">
          <label>QR Code Image</label>
          <input type="file" name="qr_code" id="paymentQr" accept="image/*"
                 onchange="previewQR(this)">
          <small style="color:var(--text3);display:block;margin-top:4px;">
            Leave empty to keep the current QR code image
          </small>
          <div class="qr-preview-box" id="qrPreviewBox">
            <img id="qrPreviewImg" src="" alt="QR Preview">
          </div>
        </div>

        <div class="form-row-2">
          <div class="form-group">
            <label>Display Order</label>
            <input type="number" name="display_order" id="paymentOrder" value="0">
          </div>
          <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:2px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:0;text-transform:none;letter-spacing:0;">
              <input type="checkbox" name="is_active" id="paymentActive" value="1" checked
                     style="width:16px;height:16px;accent-color:var(--orange);">
              <span style="font-size:12px;color:var(--text2);">Active</span>
            </label>
          </div>
        </div>

        <div class="form-group">
          <label>Description (optional)</label>
          <textarea name="description" id="paymentDescription" rows="2"
                    placeholder="e.g., Scan QR to pay via GCash"></textarea>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closePaymentModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">💾 Save</button>
      </div>
    </form>
  </div>
</div>

<script>
/* ─── Tabs ─── */
function switchTab(tab){
  document.querySelectorAll('.tab-btn').forEach((b,i) => {
    const tabs = ['general', 'payment', 'appearance', 'backup'];
    b.classList.toggle('active', tabs[i] === tab);
  });
  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  document.getElementById('tab-' + tab).classList.add('active');
  
  const url = new URL(window.location);
  url.searchParams.set('tab', tab);
  history.replaceState({}, '', url);
}

/* ─── Copy Registration Key ─── */
function copyKey(){
  const keyText = document.getElementById('regKey').textContent;
  navigator.clipboard.writeText(keyText)
    .then(() => {
      Swal.fire({ icon:'success', title:'Copied!', text:'Registration key copied to clipboard.', timer:1500, showConfirmButton:false, background:'#1e2330', color:'#e8eaf0' });
    })
    .catch(() => {
      Swal.fire({ icon:'error', title:'Failed', text:'Unable to copy key.', confirmButtonColor:'#ff8800', background:'#1e2330', color:'#e8eaf0' });
    });
}

/* ─── Registration Key Modal ─── */
function openKeyModal(){
  document.getElementById('keyModal').classList.add('show');
}
function closeKeyModal(){
  document.getElementById('keyModal').classList.remove('show');
}

/* ─── Save General Settings ─── */
document.getElementById('generalSettingsForm').addEventListener('submit', async function(e){
  e.preventDefault();
  
  const submitBtn = this.querySelector('button[type="submit"]');
  const originalText = submitBtn.textContent;
  submitBtn.disabled = true;
  submitBtn.textContent = '⏳ Saving...';
  
  const formData = new FormData(this);
  const settings = {};
  for(let [key, value] of formData.entries()) {
    settings[key] = value;
  }
  
  const requestData = new FormData();
  requestData.append('ajax_action', 'save_settings');
  requestData.append('settings', JSON.stringify(settings));
  
  try {
    const res = await fetch('settings.php', { method: 'POST', body: requestData });
    const data = await res.json();
    
    Swal.fire({ 
      icon: data.success ? 'success' : 'error',
      title: data.success ? 'Settings Saved' : 'Error',
      text: data.message, 
      confirmButtonColor: '#ff8800',
      background: '#1e2330',
      color: '#e8eaf0'
    });
  } catch (error) {
    Swal.fire({ icon:'error', title:'Error', text:'Failed to save settings: ' + error.message, confirmButtonColor:'#ff8800', background:'#1e2330', color:'#e8eaf0' });
  } finally {
    submitBtn.disabled = false;
    submitBtn.textContent = originalText;
  }
});

/* ─── QR image preview ─── */
function previewQR(input){
  const box = document.getElementById('qrPreviewBox');
  const img = document.getElementById('qrPreviewImg');
  if(input.files && input.files[0]){
    const r = new FileReader();
    r.onload = e => { img.src = e.target.result; box.style.display = 'block'; };
    r.readAsDataURL(input.files[0]);
  }
}

/* ─── Logo preview ─── */
function previewLogo(input){
  const preview = document.getElementById('logoPreview');
  if(input.files && input.files[0]){
    const r = new FileReader();
    r.onload = e => { preview.innerHTML = `<img src="${e.target.result}" alt="Logo">`; };
    r.readAsDataURL(input.files[0]);
  }
}

/* ─── Payment Modal: Open (Add) ─── */
function openPaymentModal(){
  document.getElementById('paymentMethodForm').reset();
  document.getElementById('paymentId').value          = '';
  document.getElementById('ajaxAction').value         = 'add_payment_method';
  document.getElementById('modalTitle').textContent   = 'Add Payment Method';
  document.getElementById('qrPreviewBox').style.display = 'none';
  document.getElementById('paymentModal').classList.add('show');
}
function updateThemePreview() {
  const bgColor = document.getElementById('themeBgColor').value;
  const cardColor = document.getElementById('themeCardColor').value;
  const accentColor = document.getElementById('themeAccentColor').value;
  const logoPillBg = document.getElementById('logoPillBgColor').value;
  const logoPillText = document.getElementById('logoPillTextColor').value;
  
  // Update text inputs
  document.getElementById('themeBgColorText').value = bgColor;
  document.getElementById('themeCardColorText').value = cardColor;
  document.getElementById('themeAccentColorText').value = accentColor;
  document.getElementById('logoPillBgColorText').value = logoPillBg;
  document.getElementById('logoPillTextColorText').value = logoPillText;
  
  // Apply live preview
  document.documentElement.style.setProperty('--bg', bgColor);
  document.documentElement.style.setProperty('--card', cardColor);
  document.documentElement.style.setProperty('--card2', cardColor);
  document.documentElement.style.setProperty('--orange', accentColor);
  document.documentElement.style.setProperty('--orange-dk', accentColor);
  document.documentElement.style.setProperty('--orange-lt', accentColor);
  document.documentElement.style.setProperty('--logo-pill-bg', logoPillBg);
  document.documentElement.style.setProperty('--logo-pill-text', logoPillText);
}

function applyThemePreset(bg, card, accent, logoBg, logoText) {
  document.getElementById('themeBgColor').value = bg;
  document.getElementById('themeCardColor').value = card;
  document.getElementById('themeAccentColor').value = accent;
  document.getElementById('logoPillBgColor').value = logoBg;
  document.getElementById('logoPillTextColor').value = logoText;
  updateThemePreview();
}

// Color picker event listeners
document.getElementById('themeBgColor').addEventListener('input', updateThemePreview);
document.getElementById('themeCardColor').addEventListener('input', updateThemePreview);
document.getElementById('themeAccentColor').addEventListener('input', updateThemePreview);
document.getElementById('logoPillBgColor').addEventListener('input', updateThemePreview);
document.getElementById('logoPillTextColor').addEventListener('input', updateThemePreview);
/* ─── Save Theme Colors Separately ─── */
async function saveThemeColors() {
  const submitBtn = event.target;
  const originalText = submitBtn.textContent;
  submitBtn.disabled = true;
  submitBtn.textContent = '⏳ Saving...';
  
  const settings = {
    theme_bg_color: document.getElementById('themeBgColor').value,
    theme_card_color: document.getElementById('themeCardColor').value,
    theme_accent_color: document.getElementById('themeAccentColor').value,
    logo_pill_bg_color: document.getElementById('logoPillBgColor').value,
    logo_pill_text_color: document.getElementById('logoPillTextColor').value
  };
  
  const requestData = new FormData();
  requestData.append('ajax_action', 'save_settings');
  requestData.append('settings', JSON.stringify(settings));
  
  try {
    const res = await fetch('settings.php', { method: 'POST', body: requestData });
    const data = await res.json();
    
    Swal.fire({ 
      icon: data.success ? 'success' : 'error',
      title: data.success ? 'Theme Saved!' : 'Error',
      text: data.message, 
      confirmButtonColor: '#ff8800',
      background: '#1e2330',
      color: '#e8eaf0'
    }).then(() => {
      if (data.success) {
        // Reload to apply the new theme globally
        location.reload();
      }
    });
  } catch (error) {
    Swal.fire({ 
      icon: 'error', 
      title: 'Error', 
      text: 'Failed to save theme: ' + error.message, 
      confirmButtonColor: '#ff8800',
      background: '#1e2330',
      color: '#e8eaf0'
    });
  } finally {
    submitBtn.disabled = false;
    submitBtn.textContent = originalText;
  }
}

/* ─── Reset Theme Colors to Default ─── */
function resetThemeColors() {
  applyThemePreset('#111318', '#1e2330', '#ff8800', '#ff8800', '#ffffff');
  
  Swal.fire({
    icon: 'info',
    title: 'Theme Reset',
    text: 'Colors reset to default. Click "Save Theme Colors" to apply.',
    confirmButtonColor: '#ff8800',
    background: '#1e2330',
    color: '#e8eaf0'
  });
}
/* ─── Payment Modal: Open (Edit) ─── */
function editPaymentMethod(id){
  const row = document.querySelector(`tr[data-id="${id}"]`);
  if(!row) return;

  document.getElementById('paymentId').value            = id;
  document.getElementById('ajaxAction').value           = 'update_payment_method';
  document.getElementById('paymentName').value          = row.dataset.name          || '';
  document.getElementById('paymentProvider').value      = row.dataset.provider      || '';
  document.getElementById('paymentAccountName').value   = row.dataset.accountName   || '';
  document.getElementById('paymentAccountNumber').value = row.dataset.accountNumber || '';
  document.getElementById('paymentOrder').value         = row.dataset.order         || '0';
  document.getElementById('paymentDescription').value   = row.dataset.desc          || '';
  document.getElementById('paymentActive').checked      = row.dataset.active        === '1';
  document.getElementById('qrPreviewBox').style.display = 'none';
  document.getElementById('modalTitle').textContent     = 'Edit Payment Method';

  // Show existing QR thumbnail if available
  const qrPath = row.dataset.qr;
  if(qrPath){
    const box = document.getElementById('qrPreviewBox');
    const img = document.getElementById('qrPreviewImg');
    img.src = '../' + qrPath;
    box.style.display = 'block';
  }

  document.getElementById('paymentModal').classList.add('show');
}

function closePaymentModal(){
  document.getElementById('paymentModal').classList.remove('show');
}

/* ─── Payment Method Form Submit ─── */
document.getElementById('paymentMethodForm').addEventListener('submit', async function(e){
  e.preventDefault();
  
  const submitBtn = this.querySelector('button[type="submit"]');
  const originalText = submitBtn.textContent;
  submitBtn.disabled = true;
  submitBtn.textContent = '⏳ Saving...';
  
  const formData = new FormData(this);
  
  try {
    const res = await fetch('settings.php', { method: 'POST', body: formData });
    const data = await res.json();
    
    if(data.success){
      closePaymentModal();
      await Swal.fire({ 
        icon: 'success', 
        title: 'Success', 
        text: data.message, 
        confirmButtonColor: '#ff8800',
        timer: 1500,
        showConfirmButton: true,
        background: '#1e2330',
        color: '#e8eaf0'
      });
      location.reload();
    } else {
      closePaymentModal();
      await Swal.fire({ 
        icon: 'error', 
        title: 'Error', 
        text: data.message, 
        confirmButtonColor: '#ff8800',
        background: '#1e2330',
        color: '#e8eaf0'
      });
    }
  } catch (error) {
    closePaymentModal();
    Swal.fire({ 
      icon: 'error', 
      title: 'Error', 
      text: 'Failed to save payment method: ' + error.message, 
      confirmButtonColor: '#ff8800',
      background: '#1e2330',
      color: '#e8eaf0'
    });
  } finally {
    submitBtn.disabled = false;
    submitBtn.textContent = originalText;
  }
});

/* ─── Delete Payment Method ─── */
async function deletePaymentMethod(id){
  const result = await Swal.fire({
    title:'Delete Payment Method?', 
    text:'This action cannot be undone.',
    icon:'warning', 
    showCancelButton:true,
    confirmButtonColor:'#ff4444', 
    cancelButtonColor:'#5a6380', 
    confirmButtonText:'Yes, delete',
    background:'#1e2330',
    color:'#e8eaf0'
  });
  if(!result.isConfirmed) return;
  
  const fd = new FormData();
  fd.append('ajax_action', 'delete_payment_method');
  fd.append('id', id);
  
  try {
    const res = await fetch('settings.php', { method: 'POST', body: fd });
    const data = await res.json();
    if(data.success){
      await Swal.fire({ 
        icon:'success', 
        title:'Deleted', 
        text: data.message, 
        confirmButtonColor:'#ff8800',
        timer: 1500,
        showConfirmButton: true,
        background:'#1e2330',
        color:'#e8eaf0'
      });
      location.reload();
    } else {
      Swal.fire({ 
        icon:'error', 
        title:'Error', 
        text: data.message, 
        confirmButtonColor:'#ff8800',
        background:'#1e2330',
        color:'#e8eaf0'
      });
    }
  } catch (error) {
    Swal.fire({ 
      icon:'error', 
      title:'Error', 
      text:'Failed to delete: ' + error.message, 
      confirmButtonColor:'#ff8800',
      background:'#1e2330',
      color:'#e8eaf0'
    });
  }
}

/* ─── Upload Logo ─── */
async function uploadLogo(){
  const file = document.getElementById('logoUpload').files[0];
  if(!file){
    Swal.fire({ icon:'warning', title:'No File', text:'Please select an image file', confirmButtonColor:'#ff8800', background:'#1e2330', color:'#e8eaf0' });
    return;
  }
  
  const fd = new FormData();
  fd.append('ajax_action', 'upload_logo');
  fd.append('logo', file);
  
  const uploadBtn = event.target;
  const originalText = uploadBtn.textContent;
  uploadBtn.disabled = true;
  uploadBtn.textContent = '⏳ Uploading...';
  
  try {
    const res = await fetch('settings.php', { method: 'POST', body: fd });
    const data = await res.json();
    if(data.success){
      Swal.fire({ icon:'success', title:'Logo Uploaded', text: data.message, confirmButtonColor:'#ff8800', background:'#1e2330', color:'#e8eaf0' })
          .then(() => location.reload());
    } else {
      Swal.fire({ icon:'error', title:'Error', text: data.message, confirmButtonColor:'#ff8800', background:'#1e2330', color:'#e8eaf0' });
    }
  } catch (error) {
    Swal.fire({ icon:'error', title:'Error', text:'Failed to upload logo: ' + error.message, confirmButtonColor:'#ff8800', background:'#1e2330', color:'#e8eaf0' });
  } finally {
    uploadBtn.disabled = false;
    uploadBtn.textContent = originalText;
  }
}

/* ─── Database Backup ─── */
function backupWithTimestamp() {
  Swal.fire({
    title: 'Create Backup?',
    text: 'This will download a full database backup with timestamp in the filename.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#00c853',
    cancelButtonColor: '#5a6380',
    confirmButtonText: 'Yes, Backup Now',
    background: '#1e2330',
    color: '#e8eaf0'
  }).then((result) => {
    if (result.isConfirmed) {
      // Show loading
      Swal.fire({
        title: 'Creating Backup...',
        text: 'Please wait while the backup is being generated.',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        willOpen: () => {
          Swal.showLoading();
        },
        background: '#1e2330',
        color: '#e8eaf0'
      });
      
      // Trigger download with timestamp
      window.location.href = '?action=backup_db';
      
      // Close loading after a moment
      setTimeout(() => {
        Swal.close();
      }, 2000);
    }
  });
}

// Add event listener for backup link to show loading feedback
document.querySelector('a[href="?action=backup_db"]')?.addEventListener('click', function(e) {
  // Let the download proceed naturally
  Swal.fire({
    title: 'Backup Starting...',
    text: 'Your database backup will download shortly.',
    icon: 'info',
    timer: 2000,
    showConfirmButton: false,
    background: '#1e2330',
    color: '#e8eaf0'
  });
});

/* ─── Close modals on backdrop click ─── */
document.getElementById('paymentModal').addEventListener('click', function(e){
  if(e.target === this) closePaymentModal();
});
document.getElementById('keyModal').addEventListener('click', function(e){
  if(e.target === this) closeKeyModal();
});

/* ─── Handle URL tab parameter ─── */
const urlTab = new URLSearchParams(window.location.search).get('tab');
if(urlTab && ['general', 'payment', 'appearance', 'backup'].includes(urlTab)){
  switchTab(urlTab);
}
</script>
