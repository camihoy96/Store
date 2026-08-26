<?php
// Start session only if not already active (header will handle it, but safe to keep)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require('../dbconn.php');

if (!isset($_SESSION['loggedin']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../access_denied.php"); exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_bread'])) {
        if (empty($_POST['bread_name']) || !isset($_POST['price'])) {
            $_SESSION['swal'] = ['type'=>'error','title'=>'Missing Fields','text'=>'Name and price are required.'];
        } elseif (floatval($_POST['price']) <= 0) {
            $_SESSION['swal'] = ['type'=>'error','title'=>'Invalid Price','text'=>'Price must be greater than 0.'];
        } else {
            $name  = trim($_POST['bread_name']);
            $price = floatval($_POST['price']);
            $chk   = $conn->prepare("SELECT id FROM breads WHERE name=?");
            $chk->bind_param("s",$name); $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $_SESSION['swal'] = ['type'=>'error','title'=>'Duplicate','text'=>'A bread with this name already exists.'];
            } else {
                $stmt = $conn->prepare("INSERT INTO breads (name,price) VALUES (?,?)");
                $stmt->bind_param("sd",$name,$price);
                $_SESSION['swal'] = $stmt->execute()
                    ? ['type'=>'success','title'=>'Added!','text'=>"$name added successfully."]
                    : ['type'=>'error','title'=>'Error','text'=>$conn->error];
            }
        }
    } elseif (isset($_POST['edit_bread'])) {
        $id    = intval($_POST['bread_id']);
        $name  = trim($_POST['bread_name']);
        $price = floatval($_POST['price']);
        if (empty($name) || $price <= 0) {
            $_SESSION['swal'] = ['type'=>'error','title'=>'Invalid Input','text'=>'Name and a valid price are required.'];
        } else {
            $stmt = $conn->prepare("UPDATE breads SET name=?,price=? WHERE id=?");
            $stmt->bind_param("sdi",$name,$price,$id);
            $_SESSION['swal'] = $stmt->execute()
                ? ['type'=>'success','title'=>'Updated!','text'=>"Bread updated successfully."]
                : ['type'=>'error','title'=>'Error','text'=>$conn->error];
        }
    }
    header("Location: ".$_SERVER['PHP_SELF']); exit;
}

function getBreads($conn) {
    $r = $conn->query("SELECT * FROM breads ORDER BY name ASC");
    return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
}

// Get system settings for header
$systemSettings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $result->fetch_assoc()) {
    $systemSettings[$row['setting_key']] = $row['setting_value'];
}

$breads     = getBreads($conn);
$totalBreads = count($breads);
$avgPrice   = $totalBreads > 0 ? array_sum(array_column($breads,'price')) / $totalBreads : 0;
$maxPrice   = $totalBreads > 0 ? max(array_column($breads,'price')) : 0;
$minPrice   = $totalBreads > 0 ? min(array_column($breads,'price')) : 0;

// Set page title for header
$pageTitle = 'Bread Management';
$activePage = 'bread';
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

/* MAIN - adjusted for header */
.main{margin-top:52px;padding:18px;flex:1;}
.page-hero{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;}
.page-hero h2{font-size:18px;font-weight:800;color:var(--text);}
.page-hero p{font-size:11px;color:var(--text3);margin-top:2px;}

/* STAT TILES */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:16px;}
.stat-tile{background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:12px 14px;transition:border-color .15s;}
.stat-tile:hover{border-color:var(--orange);}
.st-icon{font-size:18px;margin-bottom:4px;}
.st-label{font-size:9px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px;}
.st-val{font-size:18px;font-weight:900;}
.st-val.orange{color:var(--orange-lt);}
.st-val.green {color:#4dff88;}
.st-val.blue  {color:#88bbff;}
.st-val.yellow{color:var(--yellow);}
.st-val.red   {color:#ff8888;}

/* LAYOUT */
.two-col{display:grid;grid-template-columns:1fr 2fr;gap:14px;}
@media(max-width:800px){.two-col{grid-template-columns:1fr;}}

/* PANEL */
.panel{background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:14px;}
.panel-header{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:linear-gradient(90deg,var(--card2),var(--card));}
.panel-title{font-size:12px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:7px;}
.pt-dot{width:7px;height:7px;border-radius:50%;background:var(--orange);box-shadow:0 0 5px var(--orange);}
.panel-body{padding:16px;}

/* ADD FORM */
.add-form-grid{display:flex;flex-direction:column;gap:12px;}
.fg{display:flex;flex-direction:column;gap:4px;}
.fg label{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;}
.fg-input{background:var(--bg3);border:1.5px solid var(--border);color:var(--text);border-radius:6px;padding:9px 12px;font-size:13px;transition:border-color .15s;width:100%;}
.fg-input:focus{outline:none;border-color:var(--orange);background:rgba(255,136,0,.03);}
.fg-input::placeholder{color:var(--text3);}
.price-wrap{display:flex;align-items:center;gap:0;}
.price-prefix{background:var(--border2);border:1.5px solid var(--border);border-right:none;color:var(--text3);border-radius:6px 0 0 6px;padding:9px 11px;font-size:13px;font-weight:700;}
.price-wrap .fg-input{border-radius:0 6px 6px 0;}

/* TABLE */
.tbl-wrap{overflow-x:auto;}
.data-tbl{width:100%;border-collapse:collapse;}
.data-tbl thead tr{background:linear-gradient(90deg,var(--orange),var(--orange-dk));}
.data-tbl thead th{padding:9px 12px;font-size:10px;font-weight:700;color:rgba(255,255,255,.9);text-transform:uppercase;letter-spacing:.8px;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);}
.data-tbl thead th:last-child{border-right:none;}
.data-tbl tbody tr{border-bottom:1px solid var(--border);transition:background .1s;}
.data-tbl tbody tr:hover{background:rgba(255,255,255,.025);}
.data-tbl tbody td{padding:9px 12px;font-size:12px;color:var(--text2);vertical-align:middle;}
.td-id   {color:var(--text3);font-size:10px;font-family:monospace;}
.td-name {color:var(--text);font-weight:600;}
.td-price{color:#4dff88;font-weight:700;}
.td-empty{text-align:center;color:var(--text3);padding:28px!important;}

/* Search bar */
.tbl-search{background:var(--bg3);border:1.5px solid var(--border);color:var(--text);border-radius:6px;padding:5px 10px;font-size:11px;width:200px;transition:border-color .15s;}
.tbl-search:focus{outline:none;border-color:var(--orange);}
.tbl-search::placeholder{color:var(--text3);}

/* Buttons */
.btn{padding:6px 14px;border:none;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none;transition:all .15s;white-space:nowrap;}
.btn:hover{filter:brightness(1.1);transform:translateY(-1px);}
.btn-orange{background:linear-gradient(135deg,var(--orange),var(--orange-dk));color:white;}
.btn-red   {background:linear-gradient(135deg,var(--red),#aa1111);color:white;}
.btn-dark  {background:var(--bg3);border:1px solid var(--border);color:var(--text2);}
.btn-dark:hover{background:var(--border2);filter:none;transform:none;}
.btn-blue  {background:linear-gradient(135deg,var(--blue),#1a4fa0);color:white;}
.btn-green {background:linear-gradient(135deg,var(--green),#007a2e);color:white;}
.btn-sm    {padding:3px 10px;font-size:10px;}
.btn-full  {width:100%;justify-content:center;padding:10px;}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);backdrop-filter:blur(6px);z-index:9000;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal-box{background:var(--card2);border:1px solid var(--border2);border-radius:12px;width:90%;max-width:420px;overflow:hidden;box-shadow:0 24px 70px rgba(0,0,0,.8);animation:mfade .2s ease;}
@keyframes mfade{from{opacity:0;transform:scale(.95) translateY(-8px);}to{opacity:1;transform:none;}}
.modal-title-bar{background:linear-gradient(135deg,var(--orange),var(--orange-dk));padding:12px 16px;display:flex;align-items:center;justify-content:space-between;}
.modal-title-bar span{font-weight:700;font-size:13px;color:white;}
.mclose{background:rgba(0,0,0,.2);color:white;border:none;border-radius:4px;width:26px;height:26px;font-size:14px;cursor:pointer;font-weight:700;display:flex;align-items:center;justify-content:center;}
.mclose:hover{background:rgba(0,0,0,.5);}
.modal-body{padding:18px 20px;}
.modal-foot{padding:10px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;}
.mfg{display:flex;flex-direction:column;gap:4px;margin-bottom:13px;}
.mfg label{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;}
.mfg input{background:var(--bg3);border:1.5px solid var(--border);color:var(--text);border-radius:6px;padding:9px 12px;font-size:13px;width:100%;transition:border-color .15s;}
.mfg input:focus{outline:none;border-color:var(--orange);background:rgba(255,136,0,.03);}
.mfg input::placeholder{color:var(--text3);}
.mprice-wrap{display:flex;}
.mprice-prefix{background:var(--border2);border:1.5px solid var(--border);border-right:none;color:var(--text3);border-radius:6px 0 0 6px;padding:9px 11px;font-size:13px;font-weight:700;}
.mprice-wrap input{border-radius:0 6px 6px 0;}

@keyframes fadeout{to{opacity:0;transform:translateX(20px);}}
</style>
<!-- Include Admin Header -->
<?php include('../include/admin_header.php'); ?>
<!-- MAIN CONTENT -->
<div class="main" id="mainContent">
  <div class="page-hero">
    <div>
      <h2>🍞 Bread Management</h2>
      <p><?= $totalBreads ?> bread types registered</p>
    </div>
  </div>
  <!-- Stat tiles -->
  <div class="stat-grid">
    <div class="stat-tile">
      <div class="st-icon">🍞</div>
      <div class="st-label">Total Breads</div>
      <div class="st-val orange"><?= $totalBreads ?></div>
    </div>
    <div class="stat-tile">
      <div class="st-icon">📊</div>
      <div class="st-label">Avg Price</div>
      <div class="st-val green">₱<?= number_format($avgPrice,2) ?></div>
    </div>
    <div class="stat-tile">
      <div class="st-icon">⬆</div>
      <div class="st-label">Highest Price</div>
      <div class="st-val yellow">₱<?= number_format($maxPrice,2) ?></div>
    </div>
    <div class="stat-tile">
      <div class="st-icon">⬇</div>
      <div class="st-label">Lowest Price</div>
      <div class="st-val blue">₱<?= number_format($minPrice,2) ?></div>
    </div>
  </div>

  <!-- Two-col: Add form + Table -->
  <div class="two-col">

    <!-- LEFT: Add Form -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title"><div class="pt-dot"></div>➕ Add New Bread</div>
      </div>
      <div class="panel-body">
        <form method="POST" id="addForm">
          <input type="hidden" name="add_bread" value="1">
          <div class="add-form-grid">
            <div class="fg">
              <label>Bread Name *</label>
              <input type="text" name="bread_name" class="fg-input" placeholder="e.g. Pandesal" required>
            </div>
            <div class="fg">
              <label>Price per Piece (₱) *</label>
              <div class="price-wrap">
                <span class="price-prefix">₱</span>
                <input type="number" name="price" class="fg-input" placeholder="0.00" min="0.01" step="0.01" required>
              </div>
            </div>
            <button type="submit" class="btn btn-orange btn-full">💾 Add Bread</button>
          </div>
        </form>

        <!-- Quick stats block -->
        <div style="margin-top:20px;padding-top:14px;border-top:1px solid var(--border);">
          <div style="font-size:10px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;">Quick Info</div>
          <div style="display:flex;flex-direction:column;gap:7px;">
            <div style="display:flex;justify-content:space-between;font-size:11px;">
              <span style="color:var(--text3);">Total Bread Types:</span>
              <span style="color:var(--orange-lt);font-weight:700;"><?= $totalBreads ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:11px;">
              <span style="color:var(--text3);">Average Price:</span>
              <span style="color:#4dff88;font-weight:700;">₱<?= number_format($avgPrice,2) ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:11px;">
              <span style="color:var(--text3);">Price Range:</span>
              <span style="color:#88bbff;font-weight:700;">₱<?= number_format($minPrice,2) ?> – ₱<?= number_format($maxPrice,2) ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- RIGHT: Table -->
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">
          <div class="pt-dot"></div>
          Bread List
          <span style="background:var(--bg3);color:var(--text3);border-radius:10px;padding:1px 8px;font-size:10px;"><?= $totalBreads ?></span>
        </div>
        <input type="text" class="tbl-search" id="tblSearch" placeholder="🔍 Search breads…" oninput="searchTable()">
      </div>
      <div style="padding:0;">
        <div class="tbl-wrap">
          <table class="data-tbl" id="breadTable">
            <thead>
              <tr>
                <th>#</th>
                <th>Bread Name</th>
                <th style="text-align:right;">Price</th>
                <th style="text-align:center;">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if(!empty($breads)): $i=1; foreach($breads as $b): ?>
            <tr class="bread-row">
              <td class="td-id"><?= $i++ ?></td>
              <td class="td-name"><?= htmlspecialchars($b['name']) ?></td>
              <td style="text-align:right;" class="td-price">₱<?= number_format($b['price'],2) ?></td>
              <td style="text-align:center;white-space:nowrap;">
                <button class="btn btn-blue btn-sm edit-btn"
                        data-id="<?= $b['id'] ?>"
                        data-name="<?= htmlspecialchars($b['name'],ENT_QUOTES) ?>"
                        data-price="<?= $b['price'] ?>">✏ Edit</button>
                <button class="btn btn-red btn-sm delete-btn"
                        data-id="<?= $b['id'] ?>"
                        data-name="<?= htmlspecialchars($b['name'],ENT_QUOTES) ?>">🗑</button>
              </td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="4" class="td-empty">🍞 No breads yet. Use the form to add one!</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div><!-- end two-col -->

</div><!-- end main -->

<!-- Include Admin Footer -->
<?php include('../include/admin_footer.php'); ?>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box">
    <div class="modal-title-bar">
      <span>✏ Edit Bread</span>
      <button class="mclose" onclick="closeModal('editModal')">✕</button>
    </div>
    <form method="POST" id="editForm">
      <input type="hidden" name="edit_bread" value="1">
      <input type="hidden" name="bread_id" id="editBreadId">
      <div class="modal-body">
        <div class="mfg">
          <label>Bread Name *</label>
          <input type="text" name="bread_name" id="editBreadName" placeholder="Bread name" required>
        </div>
        <div class="mfg">
          <label>Price per Piece (₱) *</label>
          <div class="mprice-wrap">
            <span class="mprice-prefix">₱</span>
            <input type="number" name="price" id="editBreadPrice" placeholder="0.00" min="0.01" step="0.01" required>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-dark" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-orange">💾 Update Bread</button>
      </div>
    </form>
  </div>
</div>

<script>
/* Clock - handled by admin_header.php */
/* Sidebar - handled by admin_header.php */
/* Connectivity - handled by admin_footer.php */

/* Modal helpers */
function closeModal(id){document.getElementById(id).classList.remove('show');}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('show');}));

/* Table search */
function searchTable(){
  const q=document.getElementById('tblSearch').value.toUpperCase();
  document.querySelectorAll('#breadTable tbody .bread-row').forEach(row=>{
    row.style.display=row.querySelector('.td-name').textContent.toUpperCase().includes(q)?'':'none';
  });
}

/* Session SweetAlert */
<?php if(isset($_SESSION['swal'])): ?>
document.addEventListener('DOMContentLoaded',function(){
  Swal.fire({icon:'<?= $_SESSION['swal']['type'] ?>',title:'<?= addslashes($_SESSION['swal']['title']) ?>',
    text:'<?= addslashes($_SESSION['swal']['text']) ?>',confirmButtonColor:'#ff8800',
    background:'#1e2330',color:'#e8eaf0',timer:3000,timerProgressBar:true});
});
<?php unset($_SESSION['swal']); endif; ?>

/* Add form submit confirmation */
document.getElementById('addForm').addEventListener('submit',function(e){
  e.preventDefault();
  const nm=this.querySelector('[name="bread_name"]').value.trim();
  const pr=parseFloat(this.querySelector('[name="price"]').value)||0;
  if(!nm||pr<=0) return Swal.fire({icon:'error',title:'Check your inputs.',background:'#1e2330',color:'#e8eaf0'});
  Swal.fire({
    title:'Add Bread?',
    html:`<div style="text-align:left;font-size:12px;color:#e8eaf0;">
      <table style="width:100%;border-collapse:collapse;">
        <tr style="border-bottom:1px solid #2a3145;"><td style="padding:5px 8px;color:#9aa3bc;">Name:</td><td style="padding:5px 8px;font-weight:700;color:#ffaa44;">${nm}</td></tr>
        <tr><td style="padding:5px 8px;color:#9aa3bc;">Price:</td><td style="padding:5px 8px;color:#4dff88;font-weight:700;">₱${pr.toFixed(2)}</td></tr>
      </table></div>`,
    icon:'question',showCancelButton:true,
    confirmButtonColor:'#ff8800',cancelButtonColor:'#555',
    confirmButtonText:'✓ Add',cancelButtonText:'Cancel',
    background:'#1e2330',color:'#e8eaf0'
  }).then(r=>{if(r.isConfirmed) this.submit();});
});

/* Edit buttons */
document.querySelectorAll('.edit-btn').forEach(btn=>{
  btn.addEventListener('click',function(){
    document.getElementById('editBreadId').value=this.dataset.id;
    document.getElementById('editBreadName').value=this.dataset.name;
    document.getElementById('editBreadPrice').value=this.dataset.price;
    document.getElementById('editModal').classList.add('show');
  });
});

/* Edit form submit */
document.getElementById('editForm').addEventListener('submit',function(e){
  e.preventDefault();
  const nm=document.getElementById('editBreadName').value.trim();
  const pr=parseFloat(document.getElementById('editBreadPrice').value)||0;
  Swal.fire({
    title:'Update Bread?',
    html:`<div style="text-align:left;font-size:12px;color:#e8eaf0;">
      <table style="width:100%;border-collapse:collapse;">
        <tr style="border-bottom:1px solid #2a3145;"><td style="padding:5px 8px;color:#9aa3bc;">Name:</td><td style="padding:5px 8px;font-weight:700;color:#ffaa44;">${nm}</td></tr>
        <tr><td style="padding:5px 8px;color:#9aa3bc;">New Price:</td><td style="padding:5px 8px;color:#4dff88;font-weight:700;">₱${pr.toFixed(2)}</td></tr>
      </table></div>`,
    icon:'question',showCancelButton:true,
    confirmButtonColor:'#ff8800',cancelButtonColor:'#555',
    confirmButtonText:'✓ Update',cancelButtonText:'Cancel',
    background:'#1e2330',color:'#e8eaf0'
  }).then(r=>{if(r.isConfirmed){closeModal('editModal');this.submit();}});
});

/* Delete buttons */
document.querySelectorAll('.delete-btn').forEach(btn=>{
  btn.addEventListener('click',function(){
    const id=this.dataset.id, nm=this.dataset.name;
    const row=this.closest('tr');
    Swal.fire({
      title:'Delete Bread?',
      html:`Delete <strong style="color:#ffaa44;">${nm}</strong>?<br><small style="color:#9aa3bc;">This cannot be undone.</small>`,
      icon:'warning',showCancelButton:true,
      confirmButtonColor:'#ff4444',cancelButtonColor:'#555',
      confirmButtonText:'Yes, delete!',cancelButtonText:'Cancel',
      background:'#1e2330',color:'#e8eaf0',
      showLoaderOnConfirm:true,
      preConfirm:async()=>{
        try{
          const res=await fetch('delete_bread.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'bread_id='+id});
          return res.json();
        }catch(e){Swal.showValidationMessage('Request failed: '+e);}
      },allowOutsideClick:()=>!Swal.isLoading()
    }).then(result=>{
      if(result.isConfirmed){
        if(result.value.status==='success'){
          row.style.animation='fadeout .3s ease forwards';
          setTimeout(()=>{row.remove();
            // Update count badge
            const cnt=document.querySelectorAll('#breadTable tbody .bread-row').length;
            document.querySelectorAll('.panel-title span').forEach(s=>{if(s.textContent.match(/^\d+$/))s.textContent=cnt;});
          },300);
          Swal.fire({icon:'success',title:'Deleted!',toast:true,position:'top-end',showConfirmButton:false,timer:1800,background:'#1e2330',color:'#e8eaf0'});
        } else {
          Swal.fire({icon:'error',title:'Error',text:result.value.message||'Failed to delete.',background:'#1e2330',color:'#e8eaf0'});
        }
      }
    });
  });
});
</script>
