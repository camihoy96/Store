<?php
require_once __DIR__ . '/../include/header.php';

// Handle Edit Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_profile') {
    $fullname = trim($_POST['fullname']);
    $email    = trim($_POST['email']);
    $username = $_SESSION['username'];
    $stmt = $conn->prepare("UPDATE new_user SET fullname=?,email=? WHERE username=?");
    $stmt->bind_param("sss", $fullname, $email, $username);
    $_SESSION['swal'] = $stmt->execute()
        ? ['icon'=>'success','title'=>'Profile updated successfully!']
        : ['icon'=>'error','title'=>'Failed to update profile.'];
    $stmt->close();
    header("Location: ".$_SERVER['PHP_SELF']); exit;
}

// Handle Change Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current = $_POST['current_password'];
    $new     = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];
    $username = $_SESSION['username'];
    $stmt = $conn->prepare("SELECT password FROM new_user WHERE username=?");
    $stmt->bind_param("s", $username); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!password_verify($current, $row['password'])) {
        $_SESSION['swal'] = ['icon'=>'error','title'=>'Current password is incorrect.'];
    } elseif ($new !== $confirm) {
        $_SESSION['swal'] = ['icon'=>'error','title'=>'New passwords do not match.'];
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE new_user SET password=? WHERE username=?");
        $stmt->bind_param("ss", $hash, $username);
        $_SESSION['swal'] = $stmt->execute()
            ? ['icon'=>'success','title'=>'Password changed successfully!']
            : ['icon'=>'error','title'=>'Failed to change password.'];
        $stmt->close();
    }
    header("Location: ".$_SERVER['PHP_SELF']); exit;
}

// Load user
$username = $_SESSION['username'];
$stmt = $conn->prepare("SELECT * FROM new_user WHERE username=?");
$stmt->bind_param("s", $username); $stmt->execute();
$user = $stmt->get_result()->fetch_assoc(); $stmt->close();
?>

<style>
/* ══ MAIN ══ */
.main-content { margin-top: 44px; padding: 20px 16px; flex: 1; background: #f5f5f5; }

/* ══ PAGE HEADER ══ */
.page-header {
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 20px; padding-bottom: 15px;
  border-bottom: 2px solid #ddd;
  background: white;
  padding: 15px 20px;
  border-radius: 8px 8px 0 0;
  margin: -20px -16px 20px -16px;
}
.page-header h2 { font-size: 18px; font-weight: 800; color: #333; }
.page-header .header-subtitle { color: #666; font-size: 12px; }

/* ══ PROFILE LAYOUT ══ */
.profile-wrap {
  max-width: 820px; margin: 0 auto;
  display: flex; flex-direction: column; gap: 16px;
}

/* ══ PROFILE CARD ══ */
.profile-card {
  background: white; border: 1px solid #e0e0e0;
  border-radius: 10px; overflow: hidden;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.profile-card-header {
  background: linear-gradient(135deg, #ff9900, #cc5500);
  padding: 25px 30px; display: flex; align-items: center; gap: 20px;
}
.avatar {
  width: 70px; height: 70px; border-radius: 50%;
  background: rgba(255,255,255,0.2);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  border: 3px solid rgba(255,255,255,0.4);
}
.avatar svg { width: 35px; height: 35px; fill: white; }
.profile-card-header .pch-info h2 {
  font-size: 20px; font-weight: 800; color: white; margin-bottom: 4px;
}
.profile-card-header .pch-info p {
  font-size: 12px; color: rgba(255,255,255,0.85); font-weight: 600;
  text-transform: uppercase; letter-spacing: 1px;
}

.profile-card-body { padding: 25px 30px; }

/* Info grid */
.info-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 15px; margin-bottom: 25px;
}
.info-tile {
  background: #f9f9f9; border: 1px solid #e8e8e8;
  border-radius: 8px; padding: 15px 18px;
  transition: all 0.15s;
}
.info-tile:hover { border-color: #ff8800; box-shadow: 0 2px 8px rgba(255,136,0,0.15); }
.info-tile .it-label {
  font-size: 10px; color: #999; text-transform: uppercase;
  letter-spacing: 1px; margin-bottom: 6px; font-weight: 700;
}
.info-tile .it-val {
  font-size: 14px; font-weight: 700; color: #333;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.it-val.orange { color: #e67e00; }
.it-val.green  { color: #28a745; }
.it-val.blue   { color: #007bff; }

/* Action buttons row */
.action-row { display: flex; gap: 12px; flex-wrap: wrap; }

/* ══ BUTTONS ══ */
.btn {
  padding: 10px 20px; border: none; border-radius: 6px;
  font-size: 13px; font-weight: 700; cursor: pointer;
  display: inline-flex; align-items: center; gap: 8px;
  text-decoration: none; transition: all 0.15s; white-space: nowrap;
}
.btn svg { width: 16px; height: 16px; fill: currentColor; }
.btn:hover { filter: brightness(1.1); transform: translateY(-1px); }
.btn-orange  { background: linear-gradient(135deg, #ff9900, #cc6600); color: white; }
.btn-dark    { background: linear-gradient(135deg, #555, #333); color: #ddd; }
.btn-outline {
  background: white; color: #ff8800;
  border: 2px solid #ff8800; border-radius: 6px;
  padding: 8px 18px; font-size: 13px; font-weight: 700;
  cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
  transition: all 0.15s;
}
.btn-outline svg { width: 16px; height: 16px; fill: currentColor; }
.btn-outline:hover { background: #ff8800; color: white; transform: translateY(-1px); }

/* ══ MODAL ══ */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,0.5); backdrop-filter: blur(4px);
  z-index: 9000; align-items: center; justify-content: center;
}
.modal-overlay.show { display: flex; }
.modal-box {
  background: white; border: 1px solid #e0e0e0; border-radius: 10px;
  width: 90%; max-width: 460px; overflow: hidden;
  box-shadow: 0 20px 60px rgba(0,0,0,0.3);
  animation: mfade 0.2s ease;
}
@keyframes mfade { from { opacity:0; transform:translateY(-16px); } to { opacity:1; transform:none; } }

.modal-title-bar {
  background: linear-gradient(135deg, #ff9900, #cc6600);
  padding: 15px 20px; display: flex; justify-content: space-between; align-items: center;
}
.modal-title-bar span { font-weight: 700; font-size: 15px; color: white; }
.modal-x {
  background: rgba(0,0,0,0.2); color: white; border: none;
  border-radius: 4px; width: 28px; height: 28px; font-size: 16px;
  cursor: pointer; font-weight: 700; display: flex; align-items: center; justify-content: center;
}
.modal-x:hover { background: rgba(0,0,0,0.4); }
.modal-body   { padding: 20px 25px; }
.modal-footer { padding: 15px 25px; border-top: 1px solid #e0e0e0; display: flex; gap: 10px; justify-content: flex-end; background: #f9f9f9; }

/* Form elements inside modal */
.form-group { margin-bottom: 18px; }
.form-label {
  display: block; margin-bottom: 6px;
  font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; letter-spacing: 0.5px;
}
.form-input {
  width: 100%; background: white; border: 1.5px solid #ccc;
  color: #333; border-radius: 6px; padding: 10px 12px; font-size: 13px;
  transition: border-color 0.15s;
}
.form-input:focus { outline: none; border-color: #ff8800; box-shadow: 0 0 0 3px rgba(255,136,0,0.1); }

/* password toggle wrapper */
.pw-wrap { position: relative; }
.pw-wrap .form-input { padding-right: 40px; }
.pw-toggle {
  position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  background: none; border: none; color: #999; cursor: pointer;
  width: 20px; height: 20px; padding: 0;
  transition: color 0.15s;
}
.pw-toggle svg { width: 20px; height: 20px; fill: currentColor; }
.pw-toggle:hover { color: #ff8800; }

/* ══ STATUS BAR ══ */
.status-bar {
  background: #111; border-top: 1px solid #222;
  padding: 3px 12px; font-size: 10px; color: #fdf7f7;
  display: flex; gap: 14px; height: 26px; align-items: center; flex-shrink: 0;
}
.status-bar span { border-right: 1px solid #2a2a2a; padding-right: 14px; }
.status-bar span:last-child { border-right: none; margin-left: auto; }
.stat-offline { color: #ff4444 !important; font-weight: 700; }
.stat-online  { color: #44ff88 !important; font-weight: 700; }

/* ══ FOOTER ══ */
.footer { text-align: center; padding: 8px; background: #111; color: #ffffff; font-size: 11px; flex-shrink: 0; }

@media (max-width: 600px) {
  .info-grid { grid-template-columns: 1fr 1fr; }
  .profile-card-header { flex-direction: column; text-align: center; }
}
</style>

<!-- MAIN -->
<div class="main-content" id="mainContent">
  <div class="page-header">
    <h2>User Profile</h2>
    <span class="header-subtitle">Manage your account settings</span>
  </div>

  <div class="profile-wrap">

    <!-- Profile card -->
    <div class="profile-card">
      <div class="profile-card-header">
        <div class="avatar">
          <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
        </div>
        <div class="pch-info">
          <h2><?= htmlspecialchars($user['fullname']) ?></h2>
          <p><?= htmlspecialchars($user['type'] ?? 'Staff') ?> · <?= htmlspecialchars($user['username']) ?></p>
        </div>
      </div>

      <div class="profile-card-body">
        <div class="info-grid">
          <div class="info-tile">
            <div class="it-label">Full Name</div>
            <div class="it-val orange"><?= htmlspecialchars($user['fullname']) ?></div>
          </div>
          <div class="info-tile">
            <div class="it-label">Username</div>
            <div class="it-val"><?= htmlspecialchars($user['username']) ?></div>
          </div>
          <div class="info-tile">
            <div class="it-label">Email</div>
            <div class="it-val blue"><?= htmlspecialchars($user['email']) ?></div>
          </div>
          <div class="info-tile">
            <div class="it-label">Account Type</div>
            <div class="it-val green"><?= htmlspecialchars($user['type'] ?? '—') ?></div>
          </div>
          <div class="info-tile">
            <div class="it-label">Date Created</div>
            <div class="it-val" style="color:#999;font-size:12px;"><?= htmlspecialchars($user['created_at']) ?></div>
          </div>
        </div>

        <div class="action-row">
          <button class="btn btn-orange" onclick="openModal('editModal')">
            <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
            Edit Profile
          </button>
          <button class="btn-outline" onclick="openModal('pwModal')">
            <svg viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
            Change Password
          </button>
        </div>
      </div>
    </div>

  </div><!-- end profile-wrap -->
</div><!-- end main-content -->

<!-- ══ EDIT PROFILE MODAL ══ -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box">
    <div class="modal-title-bar">
      <span>Edit Profile</span>
      <button class="modal-x" onclick="closeModal('editModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="edit_profile">
      <input type="hidden" name="username" value="<?= htmlspecialchars($user['username']) ?>">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Full Name</label>
          <input type="text" name="fullname" class="form-input"
                 value="<?= htmlspecialchars($user['fullname']) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-input"
                 value="<?= htmlspecialchars($user['email']) ?>" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-dark" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-orange">
          <svg viewBox="0 0 24 24"><path d="M17 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zm-5 16c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm3-10H5V5h10v4z"/></svg>
          Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══ CHANGE PASSWORD MODAL ══ -->
<div class="modal-overlay" id="pwModal">
  <div class="modal-box">
    <div class="modal-title-bar">
      <span>Change Password</span>
      <button class="modal-x" onclick="closeModal('pwModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="change_password">
      <input type="hidden" name="username" value="<?= htmlspecialchars($user['username']) ?>">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Current Password</label>
          <div class="pw-wrap">
            <input type="password" name="current_password" class="form-input" id="pw_current" required>
            <button type="button" class="pw-toggle" onclick="togglePw('pw_current',this)">
              <svg viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
            </button>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">New Password</label>
          <div class="pw-wrap">
            <input type="password" name="new_password" class="form-input" id="pw_new" required>
            <button type="button" class="pw-toggle" onclick="togglePw('pw_new',this)">
              <svg viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
            </button>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Confirm New Password</label>
          <div class="pw-wrap">
            <input type="password" name="confirm_password" class="form-input" id="pw_confirm" required>
            <button type="button" class="pw-toggle" onclick="togglePw('pw_confirm',this)">
              <svg viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
            </button>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-dark" onclick="closeModal('pwModal')">Cancel</button>
        <button type="submit" class="btn btn-orange">
          <svg viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
          Update Password
        </button>
      </div>
    </form>
  </div>
</div>

<script>
/* ─── Modal helpers ─── */
function openModal(id){ document.getElementById(id).classList.add('show'); }
function closeModal(id){ document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('.modal-overlay').forEach(m=>{
  m.addEventListener('click',function(e){ if(e.target===this) this.classList.remove('show'); });
});

/* ─── Password toggle ─── */
function togglePw(id, btn){
  const inp=document.getElementById(id);
  if(inp.type==='password'){ 
    inp.type='text'; 
    btn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/></svg>';
  }
  else { 
    inp.type='password'; 
    btn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>';
  }
}

/* ─── SweetAlert flash from PHP ─── */
<?php if (isset($_SESSION['swal'])): ?>
  window.addEventListener('DOMContentLoaded',function(){
    Swal.fire({
      icon: '<?= $_SESSION['swal']['icon'] ?>',
      title: '<?= addslashes($_SESSION['swal']['title']) ?>',
      confirmButtonColor: '#ff8800',
      background: '#fff',
      color: '#333'
    });
  });
  <?php unset($_SESSION['swal']); ?>
<?php endif; ?>

/* ─── Connectivity ─── */
function checkConn(){
  fetch('../record/ping.php',{cache:'no-store'})
    .then(r=>{const el=document.getElementById('connStatus');el.textContent=r.ok?'● ONLINE':'● OFFLINE';el.className=r.ok?'stat-online':'stat-offline';})
    .catch(()=>{const el=document.getElementById('connStatus');el.textContent='● OFFLINE';el.className='stat-offline';});
}
setInterval(checkConn,15000); checkConn();
</script>

<?php require_once __DIR__ . '/../include/footer.php'; ?>