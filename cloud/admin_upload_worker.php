<?php
// cloud/admin_upload_worker.php — local payload builder for cloud sync
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedin'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Not logged in']);
    exit;
}
require_once __DIR__ . '/../dbconn.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

function getSetting($conn, $key) {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row['setting_value'] ?? null;
}

function setSetting($conn, $key, $val) {
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value)
                            VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param('ss', $key, $val);
    $stmt->execute();
}

/**
 * Per-table "recency" column. This is the single source of truth for how
 * we determine "changed since last sync" for each table.
 */
$RECENCY_COLUMN = [
    'transactions'      => "CONCAT(`date`, ' ', `time`)",
    'products'          => "`date_added`",
    'new_user'          => "`created_at`",
    'product_batches'   => "`batch_date`",
    'expired_products'  => "`moved_at`",
    'restock_requests'  => "`requested_at`",
    'inventory_audit'   => "`created_at`",
    'edit_deletion_log' => "`deleted_at`",
];

/**
 * Mapping from the "logical type" the UI uses → the actual DB table(s).
 * Order matters if a type expands to several tables.
 */
$TYPE_TO_TABLES = [
    'transactions' => ['transactions'],
    'products'     => ['products'],
    'users'        => ['new_user'],
    'others'       => [
        'product_batches',
        'expired_products',
        'restock_requests',
        'inventory_audit',
        'edit_deletion_log',
    ],
];

/**
 * Build the WHERE clause that selects "records changed since $lastSync".
 * For transactions, we ALSO include everything that has not been uploaded
 * yet regardless of timestamp — voided records must propagate to the cloud.
 */
function buildWhere($conn, $table, $lastSync, $onlyUnsynced) {
    global $RECENCY_COLUMN;
    if (!isset($RECENCY_COLUMN[$table])) {
        return '';
    }
    if (!$onlyUnsynced || empty($lastSync)) {
        return '';
    }
    $col = $RECENCY_COLUMN[$table];
    $esc = $conn->real_escape_string($lastSync);

    // Voided transactions must ALWAYS sync so the cloud can mark them voided.
    if ($table === 'transactions') {
        return "WHERE (
            ({$col} > '$esc')
            OR (status = 'voided' AND {$col} > '1970-01-01 00:00:00' AND {$col} > (
                SELECT IFNULL(setting_value, '1970-01-01 00:00:00')
                FROM system_settings WHERE setting_key = 'last_sync_voided'
            ))
        )";
    }
    return "WHERE {$col} > '$esc'";
}

if ($action === 'build') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $onlyUnsynced = !empty($body['only_unsynced']);

    $requestedTypes = $body['types'] ?? ['transactions', 'products', 'users', 'others'];

    // Expand logical types to real table names.
    $tables = [];
    foreach ($requestedTypes as $t) {
        foreach (($TYPE_TO_TABLES[$t] ?? []) as $tbl) {
            $tables[] = $tbl;
        }
    }
    $tables = array_values(array_unique($tables));

    $out = [
        'ok'        => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'origin'    => gethostname(),
        'counts'    => [],
        'data'      => [],
    ];

    foreach ($tables as $table) {
        // Special-case: users must exclude password.
        $cols = ($table === 'new_user')
            ? 'id, username, fullname, type, email, created_at'
            : '*';

        $lastSync = getSetting($conn, 'last_sync_' . $table . '_at');
        $where    = buildWhere($conn, $table, $lastSync, $onlyUnsynced);

        $rows = [];
        $res = $conn->query("SELECT {$cols} FROM `{$table}` {$where} ORDER BY id ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) $rows[] = $row;
        }

        $out['counts'][$table] = count($rows);
        $out['data'][$table]   = $rows;
    }

    // Friendly aliases so the UI log still shows familiar names.
    $out['counts'] = array_merge(
        [
            'transactions' => $out['counts']['transactions'] ?? 0,
            'products'     => $out['counts']['products']     ?? 0,
            'users'        => $out['counts']['new_user']     ?? 0,
        ],
        $out['counts']
    );

    echo json_encode($out);
    exit;
}

if ($action === 'mark_synced') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $now  = date('Y-m-d H:i:s');

    // Only update the timestamp for tables that were actually pushed.
    // This prevents losing changes made on tables the user skipped.
    $syncedTables = $body['synced_tables'] ?? [];

    foreach ($syncedTables as $table) {
        setSetting($conn, 'last_sync_' . $table . '_at', $now);
    }

    // Global timestamp: only advance if ALL types were synced.
    $allTypes = ['transactions', 'products', 'new_user', 'product_batches',
                 'expired_products', 'restock_requests', 'inventory_audit',
                 'edit_deletion_log'];
    $allSynced = !array_diff($allTypes, $syncedTables);
    if ($allSynced) {
        setSetting($conn, 'last_sync_at', $now);
    }

    // Remember the last-synced voided marker so we don't re-send the same
    // voided transactions forever.
    setSetting($conn, 'last_sync_voided', $now);

    echo json_encode(['ok' => true, 'marked' => $syncedTables]);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Unknown action']);