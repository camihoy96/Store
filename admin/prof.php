<?php
session_start();
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

$q = $conn->prepare("SELECT * FROM new_user WHERE username=?");
$q->bind_param("s",$username); $q->execute();
$user = $q->get_result()->fetch_assoc();
$q->close();

$initials = strtoupper(substr($user['fullname']??$username,0,2));
// Set defaults if not found
$businessName = $systemSettings['business_name'] ?? 'Angel\'s Bakeshop';
$businessSubtitle = $systemSettings['business_subtitle'] ?? 'POS SYSTEM';
$businessAddress = $systemSettings['business_address'] ?? 'Upper Batinguel, Dumaguete City, Negros Oriental 6200';
$businessPhone = $systemSettings['business_phone'] ?? '0905 615 2262';
$currencySymbol = $systemSettings['currency_symbol'] ?? '₱';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Profile – Angel's Bakeshop</title>
<link rel="stylesheet" href="../css/bootstrap-icons.css">
<script src="../js/sweetalert2.all.min.js"></script>
<style>
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

/* TOP BAR */
.top-bar{height:50px;background:linear-gradient(90deg,#0d1117,#161b27);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 14px;gap:8px;position:fixed;top:0;left:0;right:0;z-index:1000;box-shadow:0 1px 20px rgba(0,0,0,.6);}
.logo-pill{background:linear-gradient(135deg,var(--orange),#ff4400);border-radius:7px;padding:4px 12px;display:flex;flex-direction:column;align-items:center;line-height:1.2;box-shadow:0 0 18px rgba(255,136,0,.3);}
.logo-pill .lp-name{font-weight:800;font-size:11px;color:white;}
.logo-pill .lp-sub{font-size:7px;color:rgba(255,255,255,.75);letter-spacing:2px;font-weight:600;text-transform:uppercase;}
.tb-div{width:1px;height:22px;background:var(--border2);margin:0 2px;}
.tb-title{font-size:14px;font-weight:700;color:var(--text);}
.tb-clock{font-size:11px;color:var(--orange-lt);font-weight:600;}
.tb-spacer{flex:1;}
.menu-btn{background:var(--bg3);border:1px solid var(--border);border-radius:6px;color:var(--text2);font-size:16px;cursor:pointer;width:32px;height:32px;display:flex;align-items:center;justify-content:center;transition:all .15s;}
.menu-btn:hover{background:var(--orange);border-color:var(--orange);color:white;}
.tb-icon{width:32px;height:32px;background:var(--bg3);border:1px solid var(--border);border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:13px;text-decoration:none;color:var(--text2);transition:all .15s;}
.tb-icon:hover{background:var(--orange);border-color:var(--orange);color:white;}

/* SIDEBAR */
.sidebar{width:230px;background:linear-gradient(180deg,#0f1419,#111822);position:fixed;top:50px;left:0;height:calc(100vh - 50px - 24px);display:none;flex-direction:column;z-index:800;border-right:1px solid var(--border);overflow-y:auto;}
.sb-label{font-size:9px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:1.5px;padding:12px 14px 5px;}
.sb-btn{width:100%;background:transparent;border:none;color:var(--text2);padding:9px 14px;text-align:left;cursor:pointer;font-size:12px;font-weight:600;display:flex;align-items:center;gap:9px;transition:all .15s;border-left:3px solid transparent;}
.sb-btn:hover,.sb-btn.open{background:rgba(255,136,0,.08);color:var(--orange-lt);border-left-color:var(--orange);}
.sb-btn .arrow{margin-left:auto;font-size:10px;transition:transform .2s;color:var(--text3);}
.sb-btn.open .arrow{transform:rotate(90deg);}
.sb-sub{display:none;flex-direction:column;}
.sb-sub.open{display:flex;}
.sb-sub a{display:flex;align-items:center;gap:8px;padding:7px 14px 7px 40px;color:var(--text3);text-decoration:none;font-size:11px;border-left:3px solid transparent;transition:all .15s;}
.sb-sub a:hover{background:rgba(255,136,0,.08);color:var(--orange-lt);border-left-color:var(--orange);}
.sb-div{height:1px;background:var(--border);margin:5px 12px;}
.sb-link{display:flex;align-items:center;gap:9px;padding:9px 14px;color:var(--text2);text-decoration:none;font-size:12px;font-weight:600;border-left:3px solid transparent;transition:all .15s;}
.sb-link:hover,.sb-link.active{background:rgba(255,136,0,.08);color:var(--orange-lt);border-left-color:var(--orange);}

/* MAIN */
.main{margin-top:50px;padding:22px 18px;flex:1;}

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

/* Reg key */
.reg-key-row{display:flex;align-items:center;gap:8px;margin-top:5px;cursor:pointer;transition:opacity .15s;}
.reg-key-row:hover{opacity:.8;}
.reg-key-txt{background:var(--bg3);border:1.5px solid var(--border);border-radius:6px;padding:6px 11px;font-family:monospace;font-size:11px;color:var(--orange-lt);flex:1;}
.copy-btn-sm{background:var(--border2);border:none;color:var(--text3);border-radius:5px;padding:6px 9px;cursor:pointer;font-size:11px;transition:all .15s;flex-shrink:0;}
.copy-btn-sm:hover{background:var(--orange);color:white;}

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
.ab-key{background:var(--card2);border-color:var(--border);color:var(--text2);}
.ab-key .ab-sub{color:var(--text3);}
.ab-key:hover{border-color:var(--blue);color:#88bbff;background:rgba(68,136,255,.07);}

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
.info-box{background:rgba(255,136,0,.08);border:1px solid rgba(255,136,0,.2);border-radius:7px;padding:10px 13px;margin-bottom:14px;font-size:11px;color:var(--orange-lt);}

/* Buttons */
.btn{padding:7px 16px;border:none;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:all .15s;white-space:nowrap;}
.btn:hover{filter:brightness(1.1);transform:translateY(-1px);}
.btn-orange{background:linear-gradient(135deg,var(--orange),var(--orange-dk));color:white;}
.btn-dark{background:var(--bg3);border:1px solid var(--border);color:var(--text2);}
.btn-dark:hover{background:var(--border2);filter:none;transform:none;}

/* Copy toast */
.copy-toast{position:fixed;bottom:28px;left:50%;transform:translateX(-50%) translateY(10px);background:linear-gradient(135deg,var(--green),#007a2e);color:white;padding:8px 18px;border-radius:20px;font-size:11px;font-weight:700;box-shadow:0 6px 20px rgba(0,0,0,.4);opacity:0;transition:all .3s;pointer-events:none;z-index:9999;}
.copy-toast.show{opacity:1;transform:translateX(-50%) translateY(0);}

/* Status bar */
.status-bar{background:#0a0d14;border-top:1px solid var(--border);padding:0 12px;height:24px;display:flex;align-items:center;gap:14px;font-size:10px;color:var(--text3);flex-shrink:0;}
.s-sep{color:var(--border2);}
.s-conn{display:flex;align-items:center;gap:4px;margin-left:auto;}
.s-conn .cdot{width:6px;height:6px;border-radius:50%;}
.s-conn.online .cdot{background:var(--green);box-shadow:0 0 5px var(--green);}
.s-conn.offline .cdot{background:var(--red);box-shadow:0 0 5px var(--red);}
.s-conn.online span{color:var(--green);}
.s-conn.offline span{color:var(--red);}
.footer{text-align:center;padding:7px;background:#0a0d14;color:var(--text3);font-size:10px;border-top:1px solid var(--border);}

@media(max-width:600px){.profile-hero{flex-direction:column;text-align:center;}.act-btn{min-width:100%;}}
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="top-bar">
  <button class="menu-btn" id="menuBtn" onclick="toggleSidebar()">☰</button>
  <div class="logo-pill">
    <span class="lp-name"><?= htmlspecialchars($businessName) ?></span>
    <span class="lp-sub"><?= htmlspecialchars($businessSubtitle) ?></span>
  </div>
  <div class="tb-div"></div>
  <span class="tb-title">My Profile</span>
  <div class="tb-div"></div>
  <span class="tb-clock" id="currentTime"></span>
  <div class="tb-spacer"></div>
  <a class="tb-icon" href="../Dashboard.php" title="Dashboard">📊</a>
  <a class="tb-icon" href="../logout.php"     title="Logout">🚪</a>
</div>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
  <div class="sb-label">Admin</div>
  <a class="sb-link" href="../Dashboard.php">📊 Dashboard</a>
  <div class="sb-div"></div>
  <div class="sb-label">Products</div>
  <button class="sb-btn" onclick="toggleSub(this)"><span>📦</span><span>Product Category</span><span class="arrow">›</span></button>
  <div class="sb-sub">
    <a href="../product/product.php">📋 Manage Items</a>
    <a href="../product/item_reserve.php">🗃 Reserve Items</a>
  </div>
  <button class="sb-btn" onclick="toggleSub(this)"><span>🍞</span><span>Bread</span><span class="arrow">›</span></button>
  <div class="sb-sub">
    <a href="../product/bread.php">✏ Manage Bread Names</a>
    <a href="bleft.php">🧺 Bread Inventory</a>
  </div>
  <div class="sb-div"></div>
  <div class="sb-label">Records & Users</div>
  <button class="sb-btn" onclick="toggleSub(this)"><span>📋</span><span>Sales Records</span><span class="arrow">›</span></button>
  <div class="sb-sub"><a href="../record/record.php">🧾 Manage Records</a></div>
  <a class="sb-link" href="user.php">👥 Manage Users</a>
  <div class="sb-div"></div>
  <a class="sb-link active" href="prof.php">👤 My Profile</a>
</div>

<!-- MAIN -->
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

<!-- STATUS BAR -->
<div class="status-bar">
  <span>ANGEL'S BAKESHOP POS v1.0</span><span class="s-sep">|</span>
  <span>Admin Profile</span><span class="s-sep">|</span>
  <span><?= date('F j, Y') ?></span>
  <div class="s-conn offline" id="connStatus"><div class="cdot"></div><span>OFFLINE</span></div>
</div>
<div class="footer">&copy; <?= date('Y') ?> St4nger Dev. All rights reserved.</div>

<!-- COPY TOAST -->
<div class="copy-toast" id="copyToast">✓ Key copied to clipboard!</div>

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
/* Clock */
function updateClock(){document.getElementById('currentTime').textContent=new Date().toLocaleString('en-US',{timeZone:'Asia/Manila',weekday:'short',year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});}
setInterval(updateClock,1000); updateClock();

/* Sidebar */
function toggleSidebar(){const sb=document.getElementById('sidebar');sb.style.display=sb.style.display==='flex'?'none':'flex';document.getElementById('menuBtn').textContent=sb.style.display==='flex'?'✖':'☰';}
function toggleSub(btn){const sub=btn.nextElementSibling;const o=sub.classList.toggle('open');btn.classList.toggle('open',o);}

/* Connectivity */
function checkConn(){
  fetch('../record/ping.php',{cache:'no-store'})
    .then(r=>{const el=document.getElementById('connStatus');el.className=r.ok?'s-conn online':'s-conn offline';el.querySelector('span').textContent=r.ok?'ONLINE':'OFFLINE';})
    .catch(()=>{const el=document.getElementById('connStatus');el.className='s-conn offline';el.querySelector('span').textContent='OFFLINE';});
}
setInterval(checkConn,15000); checkConn();

/* Modal */
function openModal(id){document.getElementById(id).classList.add('show');}
function closeModal(id){document.getElementById(id).classList.remove('show');}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('show');}));

/* Password toggle */
function togglePw(id,btn){const inp=document.getElementById(id);if(inp.type==='password'){inp.type='text';btn.textContent='🙈';}else{inp.type='password';btn.textContent='👁';}}

/* Copy key */
function copyKey(){
  const key=document.getElementById('regKey').textContent.trim();
  if(!key||key==='Not set') return;
  navigator.clipboard.writeText(key).then(()=>{
    const t=document.getElementById('copyToast');t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2200);
  });
}

/* Session SweetAlert */
<?php if(isset($_SESSION['swal'])): ?>
document.addEventListener('DOMContentLoaded',function(){
  Swal.fire({icon:'<?= $_SESSION['swal']['type'] ?>',title:'<?= addslashes($_SESSION['swal']['title']) ?>',
    text:'<?= addslashes($_SESSION['swal']['text']) ?>',confirmButtonColor:'#ff8800',
    background:'#1e2330',color:'#e8eaf0',timer:3500,timerProgressBar:true});
});
<?php unset($_SESSION['swal']); endif; ?>
</script>
</body>
</html>