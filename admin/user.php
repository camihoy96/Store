<?php
session_start();
require('../dbconn.php');

if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../access_denied.php"); exit();
}

// Handle Edit
if (isset($_POST['edit_user'])) {
    $id       = intval($_POST['id']);
    $username = $conn->real_escape_string($_POST['username']);
    $fullname = $conn->real_escape_string($_POST['fullname']);
    $type     = $conn->real_escape_string($_POST['type']);
    $email    = $conn->real_escape_string($_POST['email']);

    if (!empty($_POST['password'])) {
        $pw   = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE new_user SET username=?,fullname=?,type=?,email=?,password=? WHERE id=?");
        $stmt->bind_param("sssssi",$username,$fullname,$type,$email,$pw,$id);
    } else {
        $stmt = $conn->prepare("UPDATE new_user SET username=?,fullname=?,type=?,email=? WHERE id=?");
        $stmt->bind_param("ssssi",$username,$fullname,$type,$email,$id);
    }
    $stmt->execute();
    $_SESSION['swal'] = ['type'=>'success','title'=>'Updated!','text'=>'User updated successfully.'];
    header("Location: user.php"); exit;
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id   = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM new_user WHERE id=?");
    $stmt->bind_param("i",$id); $stmt->execute();
    $_SESSION['swal'] = ['type'=>'success','title'=>'Deleted!','text'=>'User deleted successfully.'];
    header("Location: user.php"); exit;
}

$users = $conn->query("SELECT * FROM new_user ORDER BY created_at DESC");
$allUsers = $users->fetch_all(MYSQLI_ASSOC);

// Counts
$adminCount   = count(array_filter($allUsers, fn($u) => $u['type']==='admin'));
$cashierCount = count(array_filter($allUsers, fn($u) => $u['type']==='cashier'));
$staffCount   = count(array_filter($allUsers, fn($u) => $u['type']==='staff'));
$totalCount   = count($allUsers);

// Get system settings for header/footer
$systemSettings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $result->fetch_assoc()) {
    $systemSettings[$row['setting_key']] = $row['setting_value'];
}

$businessName = $systemSettings['business_name'] ?? 'Angel\'s Bakeshop';
$businessSubtitle = $systemSettings['business_subtitle'] ?? 'POS SYSTEM';
$businessAddress = $systemSettings['business_address'] ?? 'Upper Batinguel, Dumaguete City, Negros Oriental 6200';
$businessPhone = $systemSettings['business_phone'] ?? '0905 615 2262';
$currencySymbol = $systemSettings['currency_symbol'] ?? '₱';

// Set page title for header
$pageTitle = 'User Management';
$activePage = 'user';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Management – Admin</title>
<link rel="stylesheet" href="../css/bootstrap-icons.css">
<script src="../js/sweetalert2.all.min.js"></script>
<style>
/* Keep only the page-specific styles that aren't in admin_header.css */
:root{
  --orange:#ff8800;--orange-dk:#cc5500;--orange-lt:#ffaa44;
  --green:#00c853;--red:#ff4444;--yellow:#ffcc00;--blue:#4488ff;
  --purple:#9966ff;
  --bg:#111318;--bg2:#161b27;--bg3:#1e2330;
  --card:#1e2330;--card2:#242b3a;
  --border:#2a3145;--border2:#323d55;
  --text:#e8eaf0;--text2:#9aa3bc;--text3:#5a6380;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;font-size:13px;background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column;}

/* MAIN - Adjusted for header/footer */
.main{margin-top:50px;padding:18px;flex:1;}
.page-hero{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;}
.page-hero h2{font-size:18px;font-weight:800;color:var(--text);}
.page-hero p{font-size:11px;color:var(--text3);margin-top:2px;}

/* STAT TILES */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:16px;}
.stat-tile{background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:12px 14px;transition:border-color .15s;cursor:pointer;}
.stat-tile:hover{border-color:var(--orange);}
.stat-tile.active-filter{border-color:var(--orange);background:rgba(255,136,0,.08);}
.st-icon{font-size:18px;margin-bottom:4px;}
.st-label{font-size:9px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px;}
.st-val{font-size:20px;font-weight:900;}
.st-val.orange{color:var(--orange-lt);}
.st-val.green {color:#4dff88;}
.st-val.blue  {color:#88bbff;}
.st-val.purple{color:#cc99ff;}
.st-val.yellow{color:var(--yellow);}

/* ACTION BAR */
.action-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px;}
.search-wrap{display:flex;gap:0;flex:1;min-width:200px;max-width:340px;}
.search-wrap input{flex:1;background:var(--card2);border:1.5px solid var(--border);border-right:none;color:var(--text);border-radius:7px 0 0 7px;padding:7px 11px;font-size:12px;}
.search-wrap input:focus{outline:none;border-color:var(--orange);}
.search-wrap input::placeholder{color:var(--text3);}
.search-wrap button{background:var(--orange);border:1.5px solid var(--orange);color:white;border-radius:0 7px 7px 0;padding:7px 11px;cursor:pointer;font-size:12px;}

/* FILTER TABS */
.filter-tabs{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:12px;}
.filter-tab{padding:4px 12px;border-radius:20px;border:1.5px solid var(--border);background:var(--bg3);color:var(--text2);font-size:11px;font-weight:600;cursor:pointer;transition:all .15s;}
.filter-tab:hover{border-color:var(--border2);color:var(--text);}
.filter-tab.active{background:linear-gradient(135deg,var(--orange),var(--orange-dk));border-color:var(--orange);color:white;}

/* PANEL */
.panel{background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
.panel-header{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:linear-gradient(90deg,var(--card2),var(--card));}
.panel-title{font-size:12px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:7px;}
.pt-dot{width:7px;height:7px;border-radius:50%;background:var(--orange);box-shadow:0 0 5px var(--orange);}

/* TABLE */
.tbl-wrap{overflow-x:auto;}
.tbl-wrap::-webkit-scrollbar{height:3px;}
.tbl-wrap::-webkit-scrollbar-thumb{background:var(--border2);}
.data-tbl{width:100%;border-collapse:collapse;min-width:700px;}
.data-tbl thead tr{background:linear-gradient(90deg,var(--orange),var(--orange-dk));}
.data-tbl thead th{padding:9px 12px;font-size:10px;font-weight:700;color:rgba(255,255,255,.9);text-transform:uppercase;letter-spacing:.8px;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);}
.data-tbl thead th:last-child{border-right:none;}
.data-tbl tbody tr{border-bottom:1px solid var(--border);transition:background .1s;}
.data-tbl tbody tr:hover{background:rgba(255,255,255,.025);}
.data-tbl tbody td{padding:9px 12px;font-size:11px;color:var(--text2);vertical-align:middle;}
.td-id{color:var(--text3);font-size:10px;font-family:monospace;}
.td-name{color:var(--text);font-weight:600;}
.td-user{color:var(--text2);}
.td-email{color:#88bbff;font-size:10px;}
.td-date{color:var(--text3);font-size:10px;}
.td-empty{text-align:center;color:var(--text3);padding:28px!important;}

/* Role badges */
.role-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;letter-spacing:.3px;}
.rb-admin  {background:rgba(153,102,255,.15);color:#cc99ff;border:1px solid rgba(153,102,255,.2);}
.rb-cashier{background:rgba(255,136,0,.15);color:var(--orange-lt);border:1px solid rgba(255,136,0,.2);}
.rb-staff  {background:rgba(0,200,83,.15);color:#66dd88;border:1px solid rgba(0,200,83,.2);}
.rb-other  {background:rgba(154,163,188,.1);color:var(--text3);border:1px solid var(--border);}

/* Avatar */
.user-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--orange),var(--orange-dk));display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:white;flex-shrink:0;margin-right:7px;}
.user-cell{display:flex;align-items:center;}

/* Buttons */
.btn{padding:6px 14px;border:none;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none;transition:all .15s;white-space:nowrap;}
.btn:hover{filter:brightness(1.1);transform:translateY(-1px);}
.btn-orange{background:linear-gradient(135deg,var(--orange),var(--orange-dk));color:white;}
.btn-red   {background:linear-gradient(135deg,var(--red),#aa1111);color:white;}
.btn-dark  {background:var(--bg3);border:1px solid var(--border);color:var(--text2);}
.btn-dark:hover{background:var(--border2);filter:none;transform:none;}
.btn-blue  {background:linear-gradient(135deg,var(--blue),#1a4fa0);color:white;}
.btn-green {background:linear-gradient(135deg,var(--green),#007a2e);color:white;}
.btn-sm    {padding:3px 9px;font-size:10px;}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);backdrop-filter:blur(6px);z-index:9000;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal-box{background:var(--card2);border:1px solid var(--border2);border-radius:12px;overflow:hidden;box-shadow:0 24px 70px rgba(0,0,0,.8);animation:mfade .2s ease;}
@keyframes mfade{from{opacity:0;transform:scale(.95) translateY(-8px);}to{opacity:1;transform:none;}}
.modal-title-bar{background:linear-gradient(135deg,var(--orange),var(--orange-dk));padding:12px 16px;display:flex;align-items:center;justify-content:space-between;}
.modal-title-bar span{font-weight:700;font-size:13px;color:white;}
.mclose{background:rgba(0,0,0,.2);color:white;border:none;border-radius:4px;width:26px;height:26px;font-size:14px;cursor:pointer;font-weight:700;display:flex;align-items:center;justify-content:center;}
.mclose:hover{background:rgba(0,0,0,.5);}
.modal-body{padding:18px 20px;overflow-y:auto;}
.modal-foot{padding:10px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;}

/* Edit modal */
.edit-modal-box{width:90%;max-width:440px;}
.mfg{display:flex;flex-direction:column;gap:4px;margin-bottom:12px;}
.mfg label{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;}
.mfg input,.mfg select{background:var(--bg3);border:1.5px solid var(--border);color:var(--text);border-radius:6px;padding:8px 11px;font-size:12px;width:100%;transition:border-color .15s;}
.mfg input:focus,.mfg select:focus{outline:none;border-color:var(--orange);background:rgba(255,136,0,.03);}
.mfg input::placeholder{color:var(--text3);}
.mfg select option{background:var(--card2);}
.pw-wrap{position:relative;}
.pw-wrap input{padding-right:36px;}
.pw-toggle{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text3);cursor:pointer;font-size:14px;transition:color .15s;}
.pw-toggle:hover{color:var(--orange);}

/* Signup iframe modal */
.iframe-modal-box{width:92%;max-width:480px;}
.iframe-modal-box iframe{width:100%;height:520px;border:none;border-radius:0 0 10px 10px;}
</style>
</head>
<body>

<!-- Include Admin Header -->
<?php include('../include/admin_header.php'); ?>

<!-- MAIN CONTENT -->
<div class="main" id="mainContent">

  <div class="page-hero">
    <div>
      <h2>👥 User Management</h2>
      <p><?= $totalCount ?> users registered in the system</p>
    </div>
    <button class="btn btn-orange" onclick="openSignupModal()">➕ Add New User</button>
  </div>

  <!-- Stat tiles -->
  <div class="stat-grid">
    <div class="stat-tile" onclick="filterByRole('all',this)">
      <div class="st-icon">👥</div>
      <div class="st-label">All Users</div>
      <div class="st-val orange"><?= $totalCount ?></div>
    </div>
    <div class="stat-tile" onclick="filterByRole('admin',this)">
      <div class="st-icon">🛡</div>
      <div class="st-label">Admins</div>
      <div class="st-val purple"><?= $adminCount ?></div>
    </div>
    <div class="stat-tile" onclick="filterByRole('cashier',this)">
      <div class="st-icon">💳</div>
      <div class="st-label">Cashiers</div>
      <div class="st-val orange"><?= $cashierCount ?></div>
    </div>
    <div class="stat-tile" onclick="filterByRole('staff',this)">
      <div class="st-icon">👔</div>
      <div class="st-label">Staff</div>
      <div class="st-val green"><?= $staffCount ?></div>
    </div>
  </div>

  <!-- Action bar -->
  <div class="action-bar">
    <div class="search-wrap">
      <input type="text" id="searchInput" placeholder="🔍 Search users…" oninput="searchTable()">
      <button onclick="clearSearch()">✕</button>
    </div>

    <!-- Role filter tabs -->
    <div class="filter-tabs">
      <div class="filter-tab active" data-role="all"      onclick="filterByRole('all',null,this)">All</div>
      <div class="filter-tab"        data-role="admin"    onclick="filterByRole('admin',null,this)">🛡 Admin</div>
      <div class="filter-tab"        data-role="cashier"  onclick="filterByRole('cashier',null,this)">💳 Cashier</div>
      <div class="filter-tab"        data-role="staff"    onclick="filterByRole('staff',null,this)">👔 Staff</div>
    </div>
  </div>

  <!-- User table -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">
        <div class="pt-dot"></div>
        All Users
        <span id="shownCount" style="background:var(--bg3);color:var(--text3);border-radius:10px;padding:1px 8px;font-size:10px;"><?= $totalCount ?></span>
      </div>
      <span style="font-size:10px;color:var(--text3);"><?= date('F j, Y') ?></span>
    </div>
    <div style="padding:0;">
      <div class="tbl-wrap">
        <table class="data-tbl" id="userTable">
          <thead>
            <tr>
              <th>#</th>
              <th>User</th>
              <th>Email</th>
              <th>Role</th>
              <th>Created</th>
              <th style="text-align:center;">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($allUsers as $u):
            $initials = strtoupper(substr($u['fullname']??$u['username'],0,1));
            $roleBadge = match($u['type']) {
                'admin'   => ['rb-admin',  '🛡 Admin'],
                'cashier' => ['rb-cashier','💳 Cashier'],
                'staff'   => ['rb-staff',  '👔 Staff'],
                default   => ['rb-other',  $u['type']],
            };
          ?>
          <tr data-role="<?= htmlspecialchars($u['type']) ?>">
            <td class="td-id"><?= $u['id'] ?></td>
            <td>
              <div class="user-cell">
                <div class="user-avatar"><?= $initials ?></div>
                <div>
                  <div class="td-name"><?= htmlspecialchars($u['fullname']) ?></div>
                  <div style="color:var(--text3);font-size:10px;">@<?= htmlspecialchars($u['username']) ?></div>
                </div>
              </div>
            </td>
            <td class="td-email"><?= htmlspecialchars($u['email']) ?></td>
            <td><span class="role-badge <?= $roleBadge[0] ?>"><?= $roleBadge[1] ?></span></td>
            <td class="td-date"><?= date('M j, Y g:i A',strtotime($u['created_at'])) ?></td>
            <td style="text-align:center;white-space:nowrap;">
              <button class="btn btn-blue btn-sm edit-btn"
                      data-user='<?= htmlspecialchars(json_encode($u),ENT_QUOTES) ?>'>✏ Edit</button>
              <a class="btn btn-red btn-sm"
                 href="user.php?delete=<?= $u['id'] ?>"
                 onclick="return confirmDelete(event,this)">🗑</a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($allUsers)): ?>
          <tr><td colspan="6" class="td-empty">No users found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- end main -->

<!-- Include Admin Footer -->
<?php include('../include/admin_footer.php'); ?>

<!-- SIGNUP MODAL (iframe) -->
<div class="modal-overlay" id="signupModal">
  <div class="modal-box iframe-modal-box">
    <div class="modal-title-bar">
      <span>➕ Add New User</span>
      <button class="mclose" onclick="closeSignupModal()">✕</button>
    </div>
    <iframe id="signupFrame" src="" scrolling="auto"></iframe>
  </div>
</div>

<!-- EDIT USER MODAL -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box edit-modal-box">
    <div class="modal-title-bar">
      <span>✏ Edit User</span>
      <button class="mclose" onclick="closeModal('editModal')">✕</button>
    </div>
    <form method="POST" id="editForm">
      <input type="hidden" name="edit_user" value="1">
      <input type="hidden" name="id" id="editId">
      <div class="modal-body" style="max-height:70vh;overflow-y:auto;">
        <div class="mfg">
          <label>Username *</label>
          <input type="text" name="username" id="editUsername" required placeholder="Username">
        </div>
        <div class="mfg">
          <label>Full Name *</label>
          <input type="text" name="fullname" id="editFullname" required placeholder="Full name">
        </div>
        <div class="mfg">
          <label>Email *</label>
          <input type="email" name="email" id="editEmail" required placeholder="email@example.com">
        </div>
        <div class="mfg">
          <label>Role *</label>
          <select name="type" id="editType" required>
            <option value="admin">🛡 Admin</option>
            <option value="cashier">💳 Cashier</option>
            <option value="staff">👔 Staff</option>
          </select>
        </div>
        <div class="mfg">
          <label>New Password <span style="color:var(--text3);font-weight:400;text-transform:none;">(leave blank to keep)</span></label>
          <div class="pw-wrap">
            <input type="password" name="password" id="editPassword" placeholder="••••••••">
            <button type="button" class="pw-toggle" onclick="togglePw('editPassword',this)">👁</button>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-dark" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-orange">💾 Update User</button>
      </div>
    </form>
  </div>
</div>

<script>
/* Clock is now handled by admin_header.php */

/* Connectivity - now handled by admin_footer.php */

/* Session flash */
<?php if(isset($_SESSION['swal'])): ?>
document.addEventListener('DOMContentLoaded',function(){
  Swal.fire({icon:'<?= $_SESSION['swal']['type'] ?>',title:'<?= addslashes($_SESSION['swal']['title']) ?>',
    text:'<?= addslashes($_SESSION['swal']['text']) ?>',confirmButtonColor:'#ff8800',
    background:'#1e2330',color:'#e8eaf0',timer:3000,timerProgressBar:true});
});
<?php unset($_SESSION['swal']); endif; ?>

/* Modal helpers */
function closeModal(id){document.getElementById(id).classList.remove('show');}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('show');}));

/* Signup modal */
function openSignupModal(){
  document.getElementById('signupFrame').src='../enter/sign_up.php?iframe=true';
  document.getElementById('signupModal').classList.add('show');
}
function closeSignupModal(){
  document.getElementById('signupModal').classList.remove('show');
  document.getElementById('signupFrame').src='';
}
window.addEventListener('message',function(e){
  if(e.origin!==window.location.origin) return;
  if(e.data.action==='signupSuccess') { closeSignupModal(); location.reload(); }
});

/* Edit modal */
document.querySelectorAll('.edit-btn').forEach(btn=>{
  btn.addEventListener('click',function(){
    const u=JSON.parse(this.dataset.user);
    document.getElementById('editId').value=u.id;
    document.getElementById('editUsername').value=u.username;
    document.getElementById('editFullname').value=u.fullname;
    document.getElementById('editEmail').value=u.email;
    document.getElementById('editType').value=u.type;
    document.getElementById('editPassword').value='';
    document.getElementById('editModal').classList.add('show');
  });
});

/* Password toggle */
function togglePw(id,btn){
  const inp=document.getElementById(id);
  if(inp.type==='password'){inp.type='text';btn.textContent='🙈';}
  else{inp.type='password';btn.textContent='👁';}
}

/* Delete confirm */
function confirmDelete(e,el){
  e.preventDefault();
  const url=el.getAttribute('href');
  const row=el.closest('tr');
  const name=row.querySelector('.td-name')?.textContent||'this user';
  Swal.fire({
    title:'Delete User?',
    html:`Delete <strong style="color:#ffaa44;">${name}</strong>?<br><small style="color:#9aa3bc;">This cannot be undone.</small>`,
    icon:'warning',showCancelButton:true,
    confirmButtonColor:'#ff4444',cancelButtonColor:'#555',
    confirmButtonText:'Yes, delete!',cancelButtonText:'Cancel',
    background:'#1e2330',color:'#e8eaf0'
  }).then(r=>{if(r.isConfirmed) window.location.href=url;});
  return false;
}

/* Search */
function searchTable(){
  const q=document.getElementById('searchInput').value.toUpperCase();
  updateVisible(q, currentRole);
}
function clearSearch(){
  document.getElementById('searchInput').value='';
  updateVisible('', currentRole);
}

/* Role filter */
let currentRole='all';
function filterByRole(role, tile, tab){
  currentRole=role;
  // Update tiles
  document.querySelectorAll('.stat-tile').forEach(t=>t.classList.remove('active-filter'));
  if(tile) tile.classList.add('active-filter');
  // Update tabs
  document.querySelectorAll('.filter-tab').forEach(t=>t.classList.remove('active'));
  if(tab) tab.classList.add('active');
  else {
    // sync tab if called from tile
    document.querySelectorAll('.filter-tab').forEach(t=>{ if(t.dataset.role===role) t.classList.add('active'); });
  }
  updateVisible(document.getElementById('searchInput').value.toUpperCase(), role);
}

function updateVisible(q, role){
  let visible=0;
  document.querySelectorAll('#userTable tbody tr').forEach(row=>{
    const matchRole = role==='all' || row.dataset.role===role;
    const matchQ    = !q || row.innerText.toUpperCase().includes(q);
    const show      = matchRole && matchQ;
    row.style.display = show ? '' : 'none';
    if(show) visible++;
  });
  document.getElementById('shownCount').textContent=visible;
}
</script>
</body>
</html>