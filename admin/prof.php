<?php
// Start session only if not already active (header will handle it, but safe to keep)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require('../dbconn.php');

if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../access_denied.php"); exit();
}
$username = $_SESSION['username'];

// Handle Edit Profile
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='edit_profile') {
    $fullname = trim($_POST['fullname']);
    $email    = trim($_POST['email']);
    $stmt = $conn->prepare("UPDATE new_user SET fullname=?,email=? WHERE username=?");
    $stmt->bind_param("sss",$fullname,$email,$username);
    $_SESSION['swal'] = $stmt->execute()
        ? ['type'=>'success','title'=>'Profile Updated!','text'=>'Your profile has been saved.']
        : ['type'=>'error','title'=>'Error','text'=>'Failed to update profile.'];
    $stmt->close();
    header("Location: prof.php"); exit;
}

// Handle Change Password
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='change_password') {
    $new     = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    if (empty($new)) {
        $_SESSION['swal'] = ['type'=>'error','title'=>'Empty Password','text'=>'New password cannot be empty.'];
    } elseif (strlen($new) < 6) {
        $_SESSION['swal'] = ['type'=>'error','title'=>'Too Short','text'=>'Password must be at least 6 characters.'];
    } elseif ($new !== $confirm) {
        $_SESSION['swal'] = ['type'=>'error','title'=>'Mismatch','text'=>'Passwords do not match.'];
    } else {
        $pw   = password_hash($new,PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE new_user SET password=? WHERE username=?");
        $stmt->bind_param("ss",$pw,$username);
        $_SESSION['swal'] = $stmt->execute()
            ? ['type'=>'success','title'=>'Password Changed!','text'=>'Your password has been updated.']
            : ['type'=>'error','title'=>'Error','text'=>'Failed to change password.'];
        $stmt->close();
    }
    header("Location: prof.php"); exit;
}

// Get system settings
$systemSettings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $result->fetch_assoc()) {
    $systemSettings[$row['setting_key']] = $row['setting_value'];
}

$q = $conn->prepare("SELECT * FROM new_user WHERE username=?");
$q->bind_param("s",$username); $q->execute();
$user = $q->get_result()->fetch_assoc();
$q->close();

$initials = strtoupper(substr($user['fullname']??$username,0,2));

// Set page title for header (this will be used by admin_header.php)
$pageTitle = 'My Profile';
$activePage = 'prof';
?>
<style>
/* Page-specific styles only (header/footer styles are in the includes) */
:root{
  --orange:#ff8800;--orange-dk:#cc5500;--orange-lt:#ffaa44;
  --green:#00c853;--red:#ff4444;--yellow:#ffcc00;--blue:#4488ff;
  --bg:#111318;--bg2:#161b27;--bg3:#1e2330;
  --card:#1e2330;--card2:#242b3a;
  --border:#2a3145;--border2:#323d55;
  --text:#e8eaf0;--text2:#9aa3bc;--text3:#5a6380;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;font-size:13px;background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column;}

/* MAIN - adjusted margin for header */
.main{margin-top:52px;padding:22px 18px;flex:1;}

/* PROFILE HERO */
.profile-hero{
  background:linear-gradient(135deg,var(--orange),var(--orange-dk));
  border-radius:14px;padding:28px;
  display:flex;align-items:center;gap:22px;
  margin-bottom:16px;position:relative;overflow:hidden;
  box-shadow:0 6px 30px rgba(255,136,0,.3);
}
.profile-hero::before{content:'';position:absolute;right:-40px;top:-40px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.06);}
.profile-hero::after{content:'';position:absolute;right:40px;bottom:-60px;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.04);}
.ph-avatar{width:76px;height:76px;border-radius:50%;background:rgba(255,255,255,.2);border:3px solid rgba(255,255,255,.4);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:900;color:white;flex-shrink:0;position:relative;z-index:1;}
.ph-info{position:relative;z-index:1;}
.ph-name{font-size:20px;font-weight:800;color:white;margin-bottom:3px;}
.ph-sub{font-size:11px;color:rgba(255,255,255,.75);margin-bottom:8px;}
.ph-badge{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.3);border-radius:20px;padding:3px 12px;font-size:10px;font-weight:700;color:white;}

/* INFO GRID */
.info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px;margin-bottom:16px;}
.info-card{background:var(--card2);border:1px solid var(--border);border-radius:9px;padding:13px 15px;transition:border-color .15s;}
.info-card:hover{border-color:var(--orange);}
.ic-label{font-size:9px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;margin-bottom:5px;}
.ic-val{font-size:13px;font-weight:700;color:var(--text);}
.ic-val.orange{color:var(--orange-lt);}
.ic-val.green{color:#4dff88;}
.ic-val.blue{color:#88bbff;}

/* ACTION STRIP */
.action-strip{display:flex;gap:10px;flex-wrap:wrap;}
.act-btn{flex:1;min-width:10px;padding:12px 16px;border-radius:9px;cursor:pointer;display:flex;align-items:center;gap:10px;font-size:12px;font-weight:700;transition:all .18s;border:1.5px solid transparent;}
.act-btn:hover{filter:brightness(1.1);transform:translateY(-2px);}
.act-btn .ab-icon{font-size:22px;flex-shrink:0;}
.ab-text{display:flex;flex-direction:column;text-align:left;}
.ab-sub{font-size:10px;font-weight:400;color:rgba(255,255,255,.65);margin-top:1px;}
.ab-edit{background:linear-gradient(135deg,var(--orange),var(--orange-dk));color:white;box-shadow:0 4px 14px rgba(255,136,0,.3);border-color:var(--orange);}
.ab-pw{background:var(--card2);border-color:var(--border);color:var(--text2);}
.ab-pw .ab-sub{color:var(--text3);}
.ab-pw:hover{border-color:var(--orange);color:var(--orange-lt);background:rgba(255,136,0,.07);}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.82);backdrop-filter:blur(7px);z-index:9000;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal-box{background:var(--card2);border:1px solid var(--border2);border-radius:12px;width:90%;max-width:440px;overflow:hidden;box-shadow:0 24px 70px rgba(0,0,0,.8);animation:mfade .22s ease;}
@keyframes mfade{from{opacity:0;transform:scale(.95) translateY(-8px);}to{opacity:1;transform:none;}}
.modal-title-bar{background:linear-gradient(135deg,var(--orange),var(--orange-dk));padding:12px 16px;display:flex;align-items:center;justify-content:space-between;}
.modal-title-bar span{font-weight:700;font-size:13px;color:white;}
.mclose{background:rgba(0,0,0,.2);color:white;border:none;border-radius:4px;width:26px;height:26px;font-size:14px;cursor:pointer;font-weight:700;display:flex;align-items:center;justify-content:center;}
.mclose:hover{background:rgba(0,0,0,.5);}
.modal-body{padding:18px 20px;max-height:70vh;overflow-y:auto;}
.modal-foot{padding:10px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;}
.mfg{display:flex;flex-direction:column;gap:4px;margin-bottom:13px;}
.mfg label{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;}
.mfg input{background:var(--bg3);border:1.5px solid var(--border);color:var(--text);border-radius:6px;padding:9px 12px;font-size:12px;width:100%;transition:border-color .15s;}
.mfg input:focus{outline:none;border-color:var(--orange);background:rgba(255,136,0,.03);}
.mfg input::placeholder{color:var(--text3);}
.pw-wrap{position:relative;}
.pw-wrap input{padding-right:40px;}
.pw-toggle{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text3);cursor:pointer;font-size:14px;transition:color .15s;}
.pw-toggle:hover{color:var(--orange);}

/* Buttons */
.btn{padding:7px 16px;border:none;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:all .15s;white-space:nowrap;}
.btn:hover{filter:brightness(1.1);transform:translateY(-1px);}
.btn-orange{background:linear-gradient(135deg,var(--orange),var(--orange-dk));color:white;}
.btn-dark{background:var(--bg3);border:1px solid var(--border);color:var(--text2);}
.btn-dark:hover{background:var(--border2);filter:none;transform:none;}

@media(max-width:600px){.profile-hero{flex-direction:column;text-align:center;}.act-btn{min-width:100%;}}
</style>
<!-- Include Admin Header (handles topbar, sidebar, session, settings) -->
<?php include('../include/admin_header.php'); ?>
<!-- MAIN CONTENT -->
<div class="main" id="mainContent">

  <!-- Profile Hero Banner -->
  <div class="profile-hero">
    <div class="ph-avatar"><?= $initials ?></div>
    <div class="ph-info">
      <div class="ph-name"><?= htmlspecialchars($user['fullname']) ?></div>
      <div class="ph-sub">@<?= htmlspecialchars($user['username']) ?> &nbsp;·&nbsp; <?= htmlspecialchars($user['email']) ?></div>
      <div class="ph-badge">🛡 <?= ucfirst(htmlspecialchars($user['type'])) ?></div>
    </div>
  </div>
  <!-- Info cards -->
  <div class="info-grid">
    <div class="info-card">
      <div class="ic-label">👤 Full Name</div>
      <div class="ic-val"><?= htmlspecialchars($user['fullname']) ?></div>
    </div>
    <div class="info-card">
      <div class="ic-label">🔑 Username</div>
      <div class="ic-val orange">@<?= htmlspecialchars($user['username']) ?></div>
    </div>
    <div class="info-card">
      <div class="ic-label">📧 Email</div>
      <div class="ic-val blue"><?= htmlspecialchars($user['email']) ?></div>
    </div>
    <div class="info-card">
      <div class="ic-label">🛡 Account Type</div>
      <div class="ic-val green"><?= ucfirst(htmlspecialchars($user['type'])) ?></div>
    </div>
    <div class="info-card">
      <div class="ic-label">📅 Member Since</div>
      <div class="ic-val"><?= date('F j, Y',strtotime($user['created_at'])) ?></div>
    </div>
  </div>

  <!-- Action buttons -->
  <div class="action-strip">
    <button class="act-btn ab-edit" onclick="openModal('editModal')">
      <span class="ab-icon">✏</span>
      <span class="ab-text">Edit Profile<span class="ab-sub">Update name & email</span></span>
    </button>
    <button class="act-btn ab-pw" onclick="openModal('pwModal')">
      <span class="ab-icon">🔒</span>
      <span class="ab-text" style="color:var(--text);">Change Password<span class="ab-sub">Update your password</span></span>
    </button>
  </div>

</div><!-- end main -->

<!-- Include Admin Footer (handles status bar, connectivity, footer) -->
<?php include('../include/admin_footer.php'); ?>

<!-- EDIT PROFILE MODAL -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box">
    <div class="modal-title-bar"><span>✏ Edit Profile</span><button class="mclose" onclick="closeModal('editModal')">✕</button></div>
    <form method="POST">
      <input type="hidden" name="action" value="edit_profile">
      <div class="modal-body">
        <div class="mfg">
          <label>Full Name *</label>
          <input type="text" name="fullname" value="<?= htmlspecialchars($user['fullname']) ?>" required placeholder="Your full name">
        </div>
        <div class="mfg">
          <label>Email *</label>
          <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required placeholder="your@email.com">
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-dark" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-orange">💾 Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- CHANGE PASSWORD MODAL -->
<div class="modal-overlay" id="pwModal">
  <div class="modal-box">
    <div class="modal-title-bar"><span>🔒 Change Password</span><button class="mclose" onclick="closeModal('pwModal')">✕</button></div>
    <form method="POST">
      <input type="hidden" name="action" value="change_password">
      <div class="modal-body">
        <div class="mfg">
          <label>New Password *</label>
          <div class="pw-wrap">
            <input type="password" name="new_password" id="npw" required placeholder="Enter new password" minlength="6">
            <button type="button" class="pw-toggle" onclick="togglePw('npw',this)">👁</button>
          </div>
        </div>
        <div class="mfg">
          <label>Confirm New Password *</label>
          <div class="pw-wrap">
            <input type="password" name="confirm_password" id="cfpw" required placeholder="Confirm new password">
            <button type="button" class="pw-toggle" onclick="togglePw('cfpw',this)">👁</button>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-dark" onclick="closeModal('pwModal')">Cancel</button>
        <button type="submit" class="btn btn-orange">🔒 Update Password</button>
      </div>
    </form>
  </div>
</div>

<script>
/* Clock - handled by admin_header.php */
/* Sidebar - handled by admin_header.php */
/* Connectivity - handled by admin_footer.php */

/* Modal helpers */
function openModal(id){document.getElementById(id).classList.add('show');}
function closeModal(id){document.getElementById(id).classList.remove('show');}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('show');}));

/* Password toggle */
function togglePw(id,btn){const inp=document.getElementById(id);if(inp.type==='password'){inp.type='text';btn.textContent='🙈';}else{inp.type='password';btn.textContent='👁';}}

/* Session SweetAlert */
<?php if(isset($_SESSION['swal'])): ?>
document.addEventListener('DOMContentLoaded',function(){
  Swal.fire({icon:'<?= $_SESSION['swal']['type'] ?>',title:'<?= addslashes($_SESSION['swal']['title']) ?>',
    text:'<?= addslashes($_SESSION['swal']['text']) ?>',confirmButtonColor:'#ff8800',
    background:'#1e2330',color:'#e8eaf0',timer:3500,timerProgressBar:true});
});
<?php unset($_SESSION['swal']); endif; ?>
</script>