<?php
require_once __DIR__ . '/../dbconn.php';
// ─── Cloud target ─────────────────────────────────────────────────────────────
$CLOUD_ENDPOINT = 'https://sjetrading.shop/cloud/receive.php';
$CLOUD_API_KEY  = 'ce6d7ea92a3106b91db7cb46cc5946d80eac3cb3fc9fb869e20715117446b7ec';
// ─── Per-table recency columns ───────────────────────────────────────────────
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
// ─── Sync state ──────────────────────────────────────────────────────────────
$syncSettings = [];
$r = $conn->query("SELECT setting_key, setting_value FROM system_settings
                   WHERE setting_key LIKE 'last_sync%'");
while ($row = $r->fetch_assoc()) {
    $syncSettings[$row['setting_key']] = $row['setting_value'];
}
$lastSyncAt    = $syncSettings['last_sync_at'] ?? '';
$lastSyncVoided = $syncSettings['last_sync_voided'] ?? '1970-01-01 00:00:00';

// ─── Per-table "unsynced" count helper ───────────────────────────────────────
function unsyncedCount($conn, $table, $lastSyncAt, $lastSyncVoided) {
    global $RECENCY_COLUMN;
    if (!isset($RECENCY_COLUMN[$table])) return 0;
    $col = $RECENCY_COLUMN[$table];
    $esc = $conn->real_escape_string($lastSyncAt);

    // Transactions: count new ones AND voided-but-never-synced ones.
    if ($table === 'transactions') {
        $vEsc = $conn->real_escape_string($lastSyncVoided);
        $sql = "SELECT COUNT(*) c FROM `{$table}`
                WHERE ({$col} > '$esc')
                   OR (status = 'voided' AND {$col} > '$vEsc')";
    } else {
        $sql = "SELECT COUNT(*) c FROM `{$table}` WHERE {$col} > '$esc'";
    }
    $q = $conn->query($sql);
    return $q ? (int)$q->fetch_assoc()['c'] : 0;
}

$unsyncedTxns     = $lastSyncAt ? unsyncedCount($conn, 'transactions',     $lastSyncAt, $lastSyncVoided) : 0;
$unsyncedProducts = $lastSyncAt ? unsyncedCount($conn, 'products',         $lastSyncAt, $lastSyncVoided) : 0;
$unsyncedUsers    = $lastSyncAt ? unsyncedCount($conn, 'new_user',         $lastSyncAt, $lastSyncVoided) : 0;

$pageTitle = 'Upload to Cloud';
require_once __DIR__ . '/../include/header.php';
?>

<style>
/* ── Unlock POS body layout for this page ───────────────────────── */
body {
  display: block !important;
  height: auto !important;
  min-height: 100vh !important;
  overflow-y: auto !important;
}
body.modal-open {
  overflow: hidden !important;
}

/* ── Page wrapper ──────────────────────────────────────────────── */
.upload-wrap {
  margin-top: 44px;
  padding: 16px 16px 50px;
  max-width: 960px;
  margin-left: auto;
  margin-right: auto;
}

.upload-hero { text-align: center; margin-bottom: 22px; }
.upload-hero h2 { font-size: 20px; font-weight: 800; color: var(--orange-lt); }
.upload-hero p  { font-size: 12px; color: var(--text2); margin-top: 4px; }

.u-stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 12px;
  margin-bottom: 20px;
}
.u-stat {
  background: var(--card); border: 1px solid var(--border);
  border-radius: 10px; padding: 14px 16px;
}
.u-stat .lbl { font-size: 10px; color: var(--text3); text-transform: uppercase; letter-spacing: 0.5px; }
.u-stat .val { font-size: 22px; font-weight: 800; color: var(--orange-lt); margin-top: 4px; }
.u-stat.ok    .val { color: #4dff88; }
.u-stat.warn  .val { color: #ffcc66; }
.u-stat.err   .val { color: #ff8888; }

.u-card {
  background: var(--card); border: 1px solid var(--border);
  border-radius: 10px; padding: 18px; margin-bottom: 16px;
}
.u-card h3 { font-size: 13px; font-weight: 700; color: var(--orange-lt);
             margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }

.u-row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
.u-row label { font-size: 10px; color: var(--text3); text-transform: uppercase;
               letter-spacing: 0.5px; display: block; margin-bottom: 4px; }
.u-row input[type=text] {
  flex: 1; min-width: 200px; padding: 8px 10px;
  background: var(--bg3); border: 1.5px solid var(--border);
  border-radius: 6px; color: var(--text); font-size: 12px;
}
.u-row input[type=text]:focus { outline: none; border-color: var(--orange); }

.u-btn {
  padding: 10px 22px; border: none; border-radius: 6px;
  font-size: 13px; font-weight: 700; cursor: pointer;
  display: inline-flex; align-items: center; gap: 8px;
  transition: filter 0.15s, transform 0.1s;
}
.u-btn:hover { filter: brightness(1.1); transform: translateY(-1px); }
.u-btn:disabled { opacity: 0.5; cursor: not-allowed; filter: none; transform: none; }
.u-btn-primary { background: linear-gradient(135deg, var(--orange), var(--orange-dk)); color: #fff; }
.u-btn-success { background: linear-gradient(135deg, #00c853, #007a2e); color: #fff; }
.u-btn-secondary { background: var(--bg3); border: 1px solid var(--border); color: var(--text2); }

.u-log {
  background: #0a0d14; border: 1px solid var(--border);
  border-radius: 8px; padding: 12px; max-height: 320px;
  overflow-y: auto; font-family: ui-monospace, "Consolas", monospace;
  font-size: 11px; line-height: 1.55;
}
.u-log .line { color: #c8cfdb; }
.u-log .ok   { color: #4dff88; }
.u-log .err  { color: #ff8888; }
.u-log .info { color: #88bbff; }
.u-log .warn { color: #ffcc66; }

.u-toggle-row { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.u-toggle-row label { font-size: 12px; color: var(--text2); cursor: pointer; }

/* ══ MODAL STYLES ══════════════════════════════════════════════════════ */
.modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 9999;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.modal-overlay.show { display: flex; }

.modal-box {
  background: var(--card, #1a1a1a);
  border: 1px solid var(--border, #333);
  border-radius: 12px;
  width: 100%;
  max-width: 500px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-shadow: 0 20px 60px rgba(0,0,0,0.5);
  animation: modalFadeIn 0.2s ease;
}
@keyframes modalFadeIn {
  from { opacity: 0; transform: scale(0.95) translateY(10px); }
  to { opacity: 1; transform: none; }
}
.modal-hdr { padding: 16px 20px 0; flex-shrink: 0; }
.modal-hdr-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.modal-hdr-top h3 {
  font-size: 16px;
  font-weight: 800;
  color: var(--text, #f0f0f0);
  margin: 0;
}
.mclose {
  background: var(--bg3, #2a2a2a);
  color: var(--text2, #aaa);
  border: 1px solid var(--border, #333);
  border-radius: 6px;
  width: 28px;
  height: 28px;
  font-size: 14px;
  cursor: pointer;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s;
}
.mclose:hover { background: #ff4444; color: white; border-color: #ff4444; }

.modal-body { padding: 0 20px 20px; overflow-y: auto; flex: 1; }
.modal-foot {
  padding: 14px 20px;
  border-top: 1px solid var(--border, #333);
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  flex-shrink: 0;
  background: var(--card, #1a1a1a);
}

/* Upload Option Cards */
.upload-option {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  background: var(--bg3, #222);
  border: 1.5px solid var(--border, #333);
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.15s;
}
.upload-option:hover {
  border-color: var(--orange);
  background: rgba(255, 136, 0, 0.05);
}
.upload-option input[type="checkbox"] {
  width: 18px;
  height: 18px;
  accent-color: var(--orange);
  cursor: pointer;
  flex-shrink: 0;
}
.upload-option-info { flex: 1; }
.upload-option-title {
  font-weight: 700;
  color: var(--text, #f0f0f0);
  font-size: 13px;
}
.upload-option-desc {
  font-size: 11px;
  color: var(--text3, #888);
  margin-top: 2px;
}

/* ── Force Header to Stay Fixed ──────────────────────────────── */
header, .top-bar, .header, #main-header { 
  position: fixed !important;
  top: 0 !important;
  left: 0 !important;
  right: 0 !important;
  height: 42px;
  z-index: 9999 !important;
  background-color: var(--card, #1a1a1a) !important; 
}
.page-header {
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 12px; padding-bottom: 10px;
  border-bottom: 2px solid #333;
}
.page-header h2 { font-size: 17px; font-weight: 800; color: #f0f0f0; display: flex; align-items: center; gap: 8px; }
.page-header h2 svg { width: 20px; height: 20px; fill: #ff8800; }
.page-header .date-badge {
  background: linear-gradient(135deg, #ff8800, #cc5500);
  color: white; padding: 3px 10px; border-radius: 4px;
  font-size: 11px; font-weight: 700;
}

/* ══ FOOTER FIX ══════════════════════════════════════════════════════ */
.status-bar {
  background: #111;
  border-top: 1px solid #222;
  padding: 3px 12px;
  font-size: 10px;
  color: #fdf7f7;
  display: flex;
  gap: 14px;
  height: 26px;
  align-items: center;
  flex-shrink: 0;
  width: 100%;
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: 100;
}
.status-bar span {
  border-right: 1px solid #2a2a2a;
  padding-right: 14px;
}
.status-bar span:last-child {
  border-right: none;
  margin-left: auto;
}
.stat-offline { color: #ff4444 !important; font-weight: 700; }
.stat-online  { color: #44ff88 !important; font-weight: 700; }
/* ── Make log text selectable + copyable ──────────────────────── */
.u-log,
.u-log * {
  user-select: text !important;
  -webkit-user-select: text !important;
  -moz-user-select: text !important;
  -ms-user-select: text !important;
  cursor: text;
}

.u-log .line {
  user-select: text !important;
  -webkit-user-select: text !important;
}
</style>

<div class="upload-wrap">
  <div class="upload-hero">
    <h2>☁️ Upload Data to Cloud</h2>
    <p>Push sales, products, and users from this offline POS to your online dashboard.</p>
  </div>
  <!-- Stats -->
  <div class="u-stat-grid">
    <div class="u-stat">
      <div class="lbl">Unsynced Sales</div>
      <div class="val <?= $unsyncedTxns > 0 ? 'warn' : 'ok' ?>"><?= number_format($unsyncedTxns) ?></div>
    </div>
    <div class="u-stat">
      <div class="lbl">Unsynced Products</div>
      <div class="val <?= $unsyncedProducts > 0 ? 'warn' : 'ok' ?>"><?= number_format($unsyncedProducts) ?></div>
    </div>
    <div class="u-stat">
      <div class="lbl">Unsynced Users</div>
      <div class="val <?= $unsyncedUsers > 0 ? 'warn' : 'ok' ?>"><?= number_format($unsyncedUsers) ?></div>
    </div>
    <div class="u-stat">
      <div class="lbl">Last Sync</div>
      <div class="val" style="font-size:13px;color:var(--text2);">
        <?= $lastSyncAt ? htmlspecialchars(date('M j, Y g:i A', strtotime($lastSyncAt))) : 'Never' ?>
      </div>
    </div>
  </div>
  <!-- Settings -->
<div class="u-card">
  <h3>⚙️ Cloud Configuration</h3>
  <div class="u-row">
    <div style="flex:1;">
      <label>Cloud Endpoint URL</label>
      <input type="text" id="cloudEndpoint" value="<?= htmlspecialchars($CLOUD_ENDPOINT) ?>" readonly
             style="cursor:not-allowed;opacity:0.85;"
             title="Set in upload.php — read-only">
    </div>
  </div>
  <div class="u-row">
    <div style="flex:1;">
      <label>API Key (shared secret with cloud)</label>
      <div style="display:flex;gap:6px;">
        <input type="password" id="cloudApiKey" value="<?= htmlspecialchars($CLOUD_API_KEY) ?>" readonly
               style="flex:1;cursor:not-allowed;opacity:0.85;"
               title="Set in upload.php — read-only">
        <button type="button" class="u-btn u-btn-secondary" style="padding:8px 12px;flex-shrink:0;"
                onclick="toggleApiKeyVisibility(this)" title="Show / hide key">
          👁
        </button>
      </div>
    </div>
  </div>
  <div class="u-toggle-row">
    <input type="checkbox" id="onlyUnsynced" checked style="width:16px;height:16px;accent-color:var(--orange);">
    <label for="onlyUnsynced">Only upload records changed since the last sync</label>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;">
    <button class="u-btn u-btn-success" id="btnUploadAll" onclick="startUpload()">
      ☁️ Upload Now
    </button>
    <button class="u-btn u-btn-secondary" onclick="testConnection()">
      🔌 Test Connection
    </button>
    <button class="u-btn u-btn-secondary" onclick="clearLog()">
      🧹 Clear Log
    </button>
  </div>
  <div style="margin-top:12px;font-size:10px;color:var(--text3);">
    ℹ️ Endpoint URL and API key are fixed values set in <code>upload.php</code>. To change them, edit that file on the server and reload this page.
  </div>
</div>
  <!-- Log -->
  <div class="u-card">
    <h3>📜 Upload Log</h3>
    <div class="u-log" id="uploadLog">
      <div class="line info">Ready. Click "Test Connection" to verify, then "Upload Now".</div>
    </div>
  </div>
</div>

<!-- ═══ UPLOAD SELECTION MODAL ═══ -->
<div class="modal-overlay" id="uploadSelectModal">
  <div class="modal-box">
    <div class="modal-hdr">
      <div class="modal-hdr-top">
        <h3>☁️ Select Data to Upload</h3>
        <button class="mclose" onclick="closeUploadModal()">✕</button>
      </div>
    </div>
    <div class="modal-body">
      <p style="font-size: 12px; color: var(--text2); margin-bottom: 16px;">
        Choose which data types you want to upload to the cloud. Uncheck any you wish to skip.
      </p>
      <div style="display: flex; flex-direction: column; gap: 10px;">
        <label class="upload-option">
          <input type="checkbox" id="chkTxns" checked>
          <div class="upload-option-info">
            <div class="upload-option-title">Transactions (Sales)</div>
            <div class="upload-option-desc"><?= number_format($unsyncedTxns) ?> unsynced records</div>
          </div>
        </label>
        <label class="upload-option">
          <input type="checkbox" id="chkProducts" checked>
          <div class="upload-option-info">
            <div class="upload-option-title">Products & Inventory</div>
            <div class="upload-option-desc"><?= number_format($unsyncedProducts) ?> unsynced records</div>
          </div>
        </label>
        <label class="upload-option">
          <input type="checkbox" id="chkUsers" checked>
          <div class="upload-option-info">
            <div class="upload-option-title">Users / Staff</div>
            <div class="upload-option-desc"><?= number_format($unsyncedUsers) ?> unsynced records</div>
          </div>
        </label>
        <label class="upload-option">
          <input type="checkbox" id="chkOthers" checked>
          <div class="upload-option-info">
            <div class="upload-option-title">Other Data</div>
            <div class="upload-option-desc">System configurations, batches, and auxiliary data</div>
          </div>
        </label>
      </div>
    </div>
    <div class="modal-foot">
      <button class="u-btn u-btn-secondary" onclick="closeUploadModal()">Cancel</button>
      <button class="u-btn u-btn-success" onclick="confirmUpload()">☁️ Proceed with Upload</button>
    </div>
  </div>
</div>

<script>
const UPLOAD_PAGE = 'upload_worker.php';  // server-side processor

function log(msg, cls) {
  const box = document.getElementById('uploadLog');
  const line = document.createElement('div');
  line.className = 'line ' + (cls || '');
  const t = new Date().toLocaleTimeString('en-US', { hour12: false });
  line.textContent = '[' + t + '] ' + msg;
  box.appendChild(line);
  box.scrollTop = box.scrollHeight;
}

function clearLog() {
  document.getElementById('uploadLog').innerHTML = '<div class="line info">Log cleared.</div>';
}

function setUploading(on) {
  const btn = document.getElementById('btnUploadAll');
  btn.disabled = on;
  btn.textContent = on ? '⏳ Uploading…' : '☁️ Upload Now';
}

function openUploadModal() {
  document.getElementById('uploadSelectModal').classList.add('show');
  document.body.classList.add('modal-open');
}

function closeUploadModal() {
  document.getElementById('uploadSelectModal').classList.remove('show');
  document.body.classList.remove('modal-open');
}
async function testConnection() {
  const endpoint = document.getElementById('cloudEndpoint').value.trim();
  const apiKey   = document.getElementById('cloudApiKey').value.trim();
  if (!endpoint) {
    log('⚠️ Cloud endpoint URL is empty. Please enter the URL before testing.', 'warn');
    return;
  }
  log('🔌 Testing connection to ' + endpoint + ' …', 'info');
  try {
    const res  = await fetch(endpoint + '?ping=1', {
      method: 'POST',
      headers: { 'X-API-Key': apiKey, 'Content-Type': 'application/json' },
      body: JSON.stringify({ ping: true })
    });
    const data = await res.json().catch(() => ({}));
    if (res.ok && data.ok) {
      log('✅ Connection is good — Cloud is reachable.', 'ok');
    } else if (res.status === 401 || res.status === 403) {
      log('❌ No connection — Cloud rejected the API key. Check that the key matches on both sides.', 'err');
    } else {
      log('❌ No connection — Cloud is unreachable (HTTP ' + res.status + ': ' + (data.message || 'unknown error') + ').', 'err');
    }
  } catch (e) {
    log('❌ No connection — Cloud is unreachable. Network error: ' + e.message, 'err');
  }
}

// Intercept upload button to show modal first
function startUpload() {
  const endpoint = document.getElementById('cloudEndpoint').value.trim();
  if (!endpoint) { 
    log('Endpoint URL is empty.', 'err'); 
    return; 
  }
  openUploadModal();
}

async function confirmUpload() {
  const endpoint     = document.getElementById('cloudEndpoint').value.trim();
  const apiKey       = document.getElementById('cloudApiKey').value.trim();
  const onlyUnsynced = document.getElementById('onlyUnsynced').checked;
  // Gather selected logical types
  const types = [];
  if (document.getElementById('chkTxns').checked)     types.push('transactions');
  if (document.getElementById('chkProducts').checked) types.push('products');
  if (document.getElementById('chkUsers').checked)    types.push('users');
  if (document.getElementById('chkOthers').checked)   types.push('others');
  if (types.length === 0) {
    log('⚠️ Please select at least one data type to upload.', 'warn');
    return;
  }
  closeUploadModal();
  setUploading(true);
  log('──────── Starting upload ────────', 'info');
  log('Selected types: ' + types.join(', '), 'info');
  try {
    // Step 1: build payload locally
    log('Collecting local data…', 'info');
    const buildRes = await fetch(UPLOAD_PAGE + '?action=build', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ only_unsynced: onlyUnsynced, types: types })
    });
    if (!buildRes.ok) throw new Error('Local build failed (HTTP ' + buildRes.status + ')');
    const payload = await buildRes.json();
    if (!payload.ok) throw new Error(payload.message || 'Build error');
    log('📦 Payload built: '
        + (payload.counts.transactions || 0) + ' txns, '
        + (payload.counts.products     || 0) + ' products, '
        + (payload.counts.users        || 0) + ' users, '
        + (payload.counts.product_batches   || 0) + ' batches, '
        + (payload.counts.expired_products  || 0) + ' expired, '
        + (payload.counts.restock_requests  || 0) + ' restocks, '
        + (payload.counts.inventory_audit   || 0) + ' audits, '
        + (payload.counts.edit_deletion_log || 0) + ' edit-logs',
        'info');
    // Step 2: POST to cloud
    log('Uploading to cloud…', 'info');
    const upRes = await fetch(endpoint, {
      method: 'POST',
      headers: { 'X-API-Key': apiKey, 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const rawText = await upRes.text();
console.log('CLOUD RAW RESPONSE:', rawText);
let upData = {};
try { upData = JSON.parse(rawText); } catch (e) { upData = { raw: rawText }; }

if (!upRes.ok || !upData.ok) {
  throw new Error('Cloud rejected upload: HTTP ' + upRes.status + ' — ' + (upData.message || rawText.slice(0, 500)));
}
    log('✅ Cloud acknowledged: '
        + (upData.inserted_txns     || 0) + ' txns, '
        + (upData.inserted_products || 0) + ' products, '
        + (upData.inserted_users    || 0) + ' users inserted/updated.',
        'ok');
    // Step 3: tell local DB to advance per-table sync markers
    // IMPORTANT: we pass the actual DB tables we synced, not the logical types.
    const syncedTables = [];
    if (payload.counts.transactions     !== undefined) syncedTables.push('transactions');
    if (payload.counts.products         !== undefined) syncedTables.push('products');
    if (payload.counts.new_user         !== undefined) syncedTables.push('new_user');
    if (payload.counts.product_batches  !== undefined) syncedTables.push('product_batches');
    if (payload.counts.expired_products !== undefined) syncedTables.push('expired_products');
    if (payload.counts.restock_requests !== undefined) syncedTables.push('restock_requests');
    if (payload.counts.inventory_audit  !== undefined) syncedTables.push('inventory_audit');
    if (payload.counts.edit_deletion_log!== undefined) syncedTables.push('edit_deletion_log');
    await fetch(UPLOAD_PAGE + '?action=mark_synced', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ synced_tables: syncedTables })
    });
    log('🕒 Local sync timestamps updated for: ' + syncedTables.join(', '), 'ok');
    log('──────── Upload complete ────────', 'ok');
    setTimeout(() => location.reload(), 1500);
  } catch (e) {
    log('❌ ' + e.message, 'err');
    log('──────── Upload aborted ────────', 'err');
  } finally {
    setUploading(false);
  }
}
function toggleApiKeyVisibility(btn) {
  const input = document.getElementById('cloudApiKey');
  if (input.type === 'password') {
    input.type = 'text';
    btn.textContent = '🙈';
  } else {
    input.type = 'password';
    btn.textContent = '👁';
  }
}
document.getElementById('uploadSelectModal').addEventListener('click', function (e) {
  if (e.target === this) closeUploadModal();
});
</script>

<?php require_once __DIR__ . '/../include/footer.php'; ?>