<?php
require_once __DIR__ . '/../dbconn.php';

// ─── Cloud target ─────────────────────────────────────────────────────────────
// Change these to match your hosting account.
$CLOUD_ENDPOINT = 'https://yourdomain.com/cloud/receive.php';
$CLOUD_API_KEY  = 'CHANGE_ME_TO_A_LONG_RANDOM_STRING_1234567890';

// ─── Sync state (stored in system_settings) ──────────────────────────────────
$syncSettings = [];
$r = $conn->query("SELECT setting_key, setting_value FROM system_settings
                   WHERE setting_key IN
                   ('last_sync_at','last_sync_txns','last_sync_products','last_sync_users')");
while ($row = $r->fetch_assoc()) {
    $syncSettings[$row['setting_key']] = $row['setting_value'];
}
$lastSyncAt       = $syncSettings['last_sync_at']       ?? '';
$lastSyncTxns     = (int)($syncSettings['last_sync_txns']     ?? 0);
$lastSyncProducts = (int)($syncSettings['last_sync_products'] ?? 0);
$lastSyncUsers    = (int)($syncSettings['last_sync_users']    ?? 0);

// ─── Count unsynced records ──────────────────────────────────────────────────
// A row is "unsynced" if it was created after the last successful sync.
// Matches the actual schema:
//   transactions → CONCAT(date,' ',time)
//   products     → date_added
//   new_user     → created_at
$lastSyncEsc = $conn->real_escape_string($lastSyncAt);

$unsyncedTxns = 0;
$q = $conn->query("SELECT COUNT(*) c FROM transactions
                   WHERE (" . ($lastSyncAt ? "CONCAT(date,' ',time) > '$lastSyncEsc'" : "1=1") . ")
                   AND (status IS NULL OR status != 'voided')");
if ($q) $unsyncedTxns = (int)$q->fetch_assoc()['c'];

$unsyncedProducts = 0;
$q = $conn->query("SELECT COUNT(*) c FROM products
                   WHERE (" . ($lastSyncAt ? "date_added > '$lastSyncEsc'" : "1=1") . ")");
if ($q) $unsyncedProducts = (int)$q->fetch_assoc()['c'];

$unsyncedUsers = 0;
$q = $conn->query("SELECT COUNT(*) c FROM new_user
                   WHERE (" . ($lastSyncAt ? "created_at > '$lastSyncEsc'" : "1=1") . ")");
if ($q) $unsyncedUsers = (int)$q->fetch_assoc()['c'];

$pageTitle = 'Upload to Cloud';
require_once __DIR__ . '/../include/header.php';
?>

<style>
/* ── Unlock POS body layout for this page ───────────────────────── */
body {
  display: block !important;      /* undo flex from header.php */
  height: auto !important;        /* let page grow with content */
  min-height: 100vh !important;
  overflow: visible !important;   /* undo overflow:hidden */
}

/* ── Page wrapper ──────────────────────────────────────────────── */
.upload-wrap {
  margin-top: 44px;               /* clear fixed 42px top bar + 2px border */
  padding: 16px 16px 50px;        /* 50px bottom clears the 26px status bar */
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
/* ══ PAGE HEADER ══════════════════════════════════════════════════════ */
/* ── Force Header to Stay Fixed ──────────────────────────────── */
header, .top-bar, .header, #main-header { 
  position: fixed !important;
  top: 0 !important;
  left: 0 !important;
  right: 0 !important;
  height: 42px; /* Matches your 42px top bar comment */
  z-index: 9999 !important;
  /* Add background color if it becomes transparent when scrolling */
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
/* Ensure the status bar from footer.php displays properly */
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
        <input type="text" id="cloudEndpoint" value="<?= htmlspecialchars($CLOUD_ENDPOINT) ?>">
      </div>
    </div>
    <div class="u-row">
      <div style="flex:1;">
        <label>API Key (shared secret with cloud)</label>
        <input type="text" id="cloudApiKey" value="<?= htmlspecialchars($CLOUD_API_KEY) ?>">
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
  </div>

  <!-- Log -->
  <div class="u-card">
    <h3>📜 Upload Log</h3>
    <div class="u-log" id="uploadLog">
      <div class="line info">Ready. Click "Test Connection" to verify, then "Upload Now".</div>
    </div>
  </div>
</div>

<script>
const UPLOAD_PAGE = 'upload_worker.php';  // server-side processor (created below)

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
  document.getElementById('uploadLog').innerHTML =
    '<div class="line info">Log cleared.</div>';
}

function setUploading(on) {
  const btn = document.getElementById('btnUploadAll');
  btn.disabled = on;
  btn.textContent = on ? '⏳ Uploading…' : '☁️ Upload Now';
}

async function testConnection() {
  const endpoint = document.getElementById('cloudEndpoint').value.trim();
  const apiKey   = document.getElementById('cloudApiKey').value.trim();
  if (!endpoint) { log('Endpoint URL is empty.', 'err'); return; }

  log('Testing ' + endpoint + ' …', 'info');
  try {
    const res = await fetch(endpoint + '?ping=1', {
      method: 'POST',
      headers: { 'X-API-Key': apiKey, 'Content-Type': 'application/json' },
      body: JSON.stringify({ ping: true })
    });
    const data = await res.json().catch(() => ({}));
    if (res.ok && data.ok) {
      log('✅ Connection OK — cloud is reachable.', 'ok');
    } else {
      log('❌ Cloud responded with HTTP ' + res.status + ': ' + (data.message || 'unknown'), 'err');
    }
  } catch (e) {
    log('❌ Network error: ' + e.message, 'err');
  }
}

async function startUpload() {
  const endpoint     = document.getElementById('cloudEndpoint').value.trim();
  const apiKey       = document.getElementById('cloudApiKey').value.trim();
  const onlyUnsynced = document.getElementById('onlyUnsynced').checked;
  if (!endpoint) { log('Endpoint URL is empty.', 'err'); return; }

  setUploading(true);
  log('──────── Starting upload ────────', 'info');

  try {
    // Step 1: ask the local server to build the payload
    log('Collecting local data…', 'info');
    const buildRes = await fetch(UPLOAD_PAGE + '?action=build', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ only_unsynced: onlyUnsynced })
    });
    if (!buildRes.ok) throw new Error('Local build failed (HTTP ' + buildRes.status + ')');
    const payload = await buildRes.json();
    if (!payload.ok) throw new Error(payload.message || 'Build error');

    log('📦 Payload built: '
        + payload.counts.transactions + ' txns, '
        + payload.counts.products     + ' products, '
        + payload.counts.users        + ' users',
        'info');

    // Step 2: POST it to the cloud
    log('Uploading to cloud…', 'info');
    const upRes = await fetch(endpoint, {
      method: 'POST',
      headers: { 'X-API-Key': apiKey, 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const upData = await upRes.json().catch(() => ({}));
    if (!upRes.ok || !upData.ok) {
      throw new Error('Cloud rejected upload: ' + (upData.message || 'HTTP ' + upRes.status));
    }

    log('✅ Cloud acknowledged: '
        + (upData.inserted_txns     || 0) + ' txns, '
        + (upData.inserted_products || 0) + ' products, '
        + (upData.inserted_users    || 0) + ' users inserted/updated.',
        'ok');

    // Step 3: tell local DB to remember the sync timestamp
    await fetch(UPLOAD_PAGE + '?action=mark_synced', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ counts: payload.counts })
    });

    log('🕒 Local sync timestamp updated.', 'ok');
    log('──────── Upload complete ────────', 'ok');
  } catch (e) {
    log('❌ ' + e.message, 'err');
    log('──────── Upload aborted ────────', 'err');
  } finally {
    setUploading(false);
  }
}
</script>

<?php require_once __DIR__ . '/../include/footer.php'; ?>