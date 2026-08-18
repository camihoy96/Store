<?php
$pageTitle = 'Products';
require_once __DIR__ . '/../include/admin_header.php';

/* ═══════ HANDLE POST ═══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_product'])) {
        $id = intval($_POST['product_id']);
        $cr = $conn->query("SELECT code,image_path FROM products WHERE id=$id")->fetch_assoc();
        $code = $cr['code'];
        $name           = $conn->real_escape_string($_POST['name']);
        $category       = $conn->real_escape_string($_POST['category']);
        $brand          = $conn->real_escape_string($_POST['brand']);
        $seller_store   = $conn->real_escape_string($_POST['seller_store']);
        $purchase_price = floatval($_POST['purchase_price']);
        $price          = floatval($_POST['price']);
        $purchase_date  = $conn->real_escape_string($_POST['purchase_date']);
        $mtype          = $_POST['edit_measurement'] ?? 'pieces';
        $pieces         = ($mtype==='pieces') ? intval($_POST['pieces']) : 0;
        $kg_val         = ($mtype==='kg') ? floatval($_POST['kg']) : 0;
        $expiry_date    = (($_POST['edit_expiry_status']??'has_date')==='na'||empty($_POST['expiry_date'])) ? null : $_POST['expiry_date'];
        $product_image  = $cr['image_path'];

        if (isset($_FILES['edit_image_path']) && $_FILES['edit_image_path']['error']===UPLOAD_ERR_OK) {
            $dir = __DIR__.'/../uploads/';
            if (!is_dir($dir)) mkdir($dir,0755,true);
            $fn = uniqid('prod_',true).'.'.strtolower(pathinfo($_FILES['edit_image_path']['name'],PATHINFO_EXTENSION));
            if (move_uploaded_file($_FILES['edit_image_path']['tmp_name'],$dir.$fn)) {
                if (!empty($cr['image_path']) && file_exists(__DIR__.'/'.$cr['image_path'])) unlink(__DIR__.'/'.$cr['image_path']);
                $product_image = 'uploads/'.$fn;
            }
        }
        $stmt = $conn->prepare("UPDATE products SET code=?,name=?,category=?,brand=?,seller_store=?,
            purchase_price=?,price=?,pieces=?,kg=?,measurement_type=?,purchase_date=?,expiry_date=?,image_path=? WHERE id=?");
        $stmt->bind_param("sssssddidssssi",$code,$name,$category,$brand,$seller_store,
            $purchase_price,$price,$pieces,$kg_val,$mtype,$purchase_date,$expiry_date,$product_image,$id);
        $_SESSION['swal'] = $stmt->execute()
            ? ['type'=>'success','title'=>'Updated!','text'=>'Product updated successfully.']
            : ['type'=>'error','title'=>'Error','text'=>$stmt->error];
        $stmt->close();
        header("Location: product.php"); exit;
    } else {
        $r = $conn->query("SELECT MAX(CAST(SUBSTRING(code,2) AS UNSIGNED)) AS mc FROM products")->fetch_assoc();
        $code = 'P'.(($r['mc']??0)+1);
        $name           = $_POST['name'];
        $brand          = $_POST['brand'];
        $category       = $_POST['category'];
        $seller         = $_POST['seller'];
        $purchase_price = floatval($_POST['purchase_price']);
        $price          = floatval($_POST['price']);
        $purchase_date  = $_POST['purchase_date'];
        $expiry_date    = (($_POST['expiry_status']??'has_date')==='na'||empty($_POST['expiry_date'])) ? null : $_POST['expiry_date'];
        $mtype          = ($_POST['measurement']==='kg') ? 'kg' : 'pieces';
        $pieces         = ($mtype==='pieces') ? intval($_POST['pieces']) : 0;
        $kg_val         = ($mtype==='kg') ? floatval($_POST['kg']) : 0;
        $product_image  = null;
        if (isset($_FILES['image_path']) && $_FILES['image_path']['error']===UPLOAD_ERR_OK) {
            $dir = __DIR__.'/../uploads/';
            if (!is_dir($dir)) mkdir($dir,0755,true);
            $fn = uniqid('prod_',true).'.'.strtolower(pathinfo($_FILES['image_path']['name'],PATHINFO_EXTENSION));
            if (move_uploaded_file($_FILES['image_path']['tmp_name'],$dir.$fn)) $product_image='uploads/'.$fn;
        }
        $stmt = $conn->prepare("INSERT INTO products (code,name,category,price,purchase_price,brand,seller_store,
            expiry_date,purchase_date,image_path,pieces,kg,measurement_type) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("sssddsssssids",$code,$name,$category,$price,$purchase_price,$brand,$seller,
            $expiry_date,$purchase_date,$product_image,$pieces,$kg_val,$mtype);
        $_SESSION['swal'] = $stmt->execute()
            ? ['type'=>'success','title'=>'Added!','text'=>'Product added successfully.']
            : ['type'=>'error','title'=>'Error','text'=>$stmt->error];
        $stmt->close();
        header("Location: product.php"); exit;
    }
}

/* ═══════ ALERT DATA ═════════════════════════════════════════════════════ */
$today   = date('Y-m-d');
$warn3m  = date('Y-m-d',strtotime('+3 months'));
$lowThrPcs = 20; $lowThrKg = 20.0;

$lowStockItems   = $conn->query("SELECT id,code,name,measurement_type,pieces,kg FROM products WHERE (measurement_type='pieces' AND pieces>0 AND pieces<=$lowThrPcs) OR (measurement_type='kg' AND kg>0 AND kg<=$lowThrKg) ORDER BY CASE WHEN measurement_type='kg' THEN kg ELSE pieces END ASC")->fetch_all(MYSQLI_ASSOC);
$outOfStockItems = $conn->query("SELECT id,code,name,measurement_type FROM products WHERE (measurement_type='pieces' AND pieces=0) OR (measurement_type='kg' AND kg=0)")->fetch_all(MYSQLI_ASSOC);
$expiringItems   = $conn->query("SELECT id,code,name,expiry_date,DATEDIFF(expiry_date,CURDATE()) as days_remaining FROM products WHERE expiry_date IS NOT NULL AND expiry_date NOT IN('N/A','0000-00-00') AND expiry_date>=CURDATE() AND expiry_date<='$warn3m' AND DATEDIFF(expiry_date,CURDATE())>0 ORDER BY days_remaining ASC")->fetch_all(MYSQLI_ASSOC);
$expiredItems    = $conn->query("SELECT id,code,name,expiry_date,DATEDIFF(CURDATE(),expiry_date) as days_expired FROM products WHERE expiry_date IS NOT NULL AND expiry_date NOT IN('N/A','0000-00-00') AND expiry_date<CURDATE() ORDER BY days_expired DESC")->fetch_all(MYSQLI_ASSOC);

/* ═══════ CATEGORIES WITH COUNTS ════════════════════════════════════════ */
$catResult = $conn->query("SELECT category, COUNT(*) as cnt FROM products WHERE category != '' GROUP BY category ORDER BY category");
$categories = [];
$totalProducts = 0;
while ($c = $catResult->fetch_assoc()) {
    $categories[] = $c;
    $totalProducts += $c['cnt'];
}
$uncatCount = $conn->query("SELECT COUNT(*) as c FROM products WHERE category='' OR category IS NULL")->fetch_assoc()['c'];
$totalAll = $conn->query("SELECT COUNT(*) as c FROM products")->fetch_assoc()['c'];

/* ═══════ ACTIVE CATEGORY FILTER ════════════════════════════════════════ */
$activeCat = $_GET['cat'] ?? 'all';
$page      = max(1,(int)($_GET['page']??1));
$limit     = 20;
$offset    = ($page-1)*$limit;

$whereClause = "";
if ($activeCat === 'uncategorized') {
    $whereClause = "WHERE (category='' OR category IS NULL)";
} elseif ($activeCat !== 'all') {
    $safecat = $conn->real_escape_string($activeCat);
    $whereClause = "WHERE category='$safecat'";
}

$result      = $conn->query("SELECT * FROM products $whereClause ORDER BY id DESC LIMIT $limit OFFSET $offset");
$countForCat = $conn->query("SELECT COUNT(*) as c FROM products $whereClause")->fetch_assoc()['c'];
$totalPages  = ceil($countForCat/$limit);
?>

<style>
/* Page-specific styles */
.page-hero{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;}
.page-hero h2{font-size:18px;font-weight:800;color:var(--text);}
.page-hero p{font-size:11px;color:var(--text3);margin-top:2px;}

/* Category tabs */
.cat-bar{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:14px;}
.cat-bar-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;}
.cat-bar-title{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:1px;}
.cat-search-wrap{display:flex;gap:0;}
.cat-search-wrap input{background:var(--bg3);border:1.5px solid var(--border);border-right:none;color:var(--text);border-radius:6px 0 0 6px;padding:5px 10px;font-size:11px;width:180px;}
.cat-search-wrap input:focus{outline:none;border-color:var(--orange);}
.cat-search-wrap button{background:var(--orange);border:1.5px solid var(--orange);color:white;border-radius:0 6px 6px 0;padding:5px 10px;cursor:pointer;font-size:11px;}

.cat-tabs-scroll{display:flex;gap:6px;overflow-x:auto;padding-bottom:4px;flex-wrap:wrap;}
.cat-tabs-scroll::-webkit-scrollbar{height:3px;}
.cat-tabs-scroll::-webkit-scrollbar-thumb{background:var(--border2);border-radius:2px;}

.cat-tab-btn{display:flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;border:1.5px solid var(--border);background:var(--bg3);color:var(--text2);cursor:pointer;font-size:11px;font-weight:600;white-space:nowrap;text-decoration:none;transition:all .18s;position:relative;}
.cat-tab-btn:hover{background:var(--card2);border-color:var(--border2);color:var(--text);}
.cat-tab-btn.active{background:linear-gradient(135deg,var(--orange),var(--orange-dk));border-color:var(--orange);color:white;box-shadow:0 3px 14px rgba(255,136,0,.3);}
.cat-tab-btn .ct-count{background:rgba(255,255,255,.18);color:white;border-radius:10px;padding:1px 7px;font-size:9px;font-weight:700;}
.cat-tab-btn:not(.active) .ct-count{background:var(--border2);color:var(--text3);}
.cat-tab-btn .ct-icon{font-size:14px;}
.cat-add-btn{display:inline-flex;align-items:center;gap:3px;margin-left:4px;padding:1px 6px;border-radius:4px;background:rgba(255,255,255,.2);color:white;font-size:9px;font-weight:700;cursor:pointer;border:none;transition:background .15s;}
.cat-add-btn:hover{background:rgba(255,255,255,.35);}
.cat-tab-btn:not(.active) .cat-add-btn{background:rgba(255,136,0,.15);color:var(--orange-lt);}
.cat-tab-btn:not(.active) .cat-add-btn:hover{background:rgba(255,136,0,.3);}

/* Summary strip */
.sum-strip{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;}
.sum-tile{background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:10px 14px;flex:1;min-width:130px;transition:border-color .15s;}
.sum-tile:hover{border-color:var(--orange);}
.sum-tile .st-label{font-size:9px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;}
.sum-tile .st-val{font-size:18px;font-weight:900;}
.st-val.orange{color:var(--orange-lt);}
.st-val.green{color:#4dff88;}
.st-val.red{color:#ff8888;}
.st-val.yellow{color:var(--yellow);}

/* Legend */
.legend-strip{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;align-items:center;}
.legend-chip{display:flex;align-items:center;gap:4px;background:var(--card2);border:1px solid var(--border);border-radius:20px;padding:3px 9px;font-size:10px;color:var(--text2);}
.lc-dot{width:7px;height:7px;border-radius:50%;}

/* Panel */
.panel{background:var(--card);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:14px;}
.panel-header{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:linear-gradient(90deg,var(--card2),var(--card));}
.panel-title{font-size:12px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:7px;}
.pt-dot{width:7px;height:7px;border-radius:50%;background:var(--orange);box-shadow:0 0 5px var(--orange);}

/* Table */
.tbl-wrap{overflow-x:auto;}
.tbl-wrap::-webkit-scrollbar{height:3px;}
.tbl-wrap::-webkit-scrollbar-thumb{background:var(--border2);}
.data-tbl{width:100%;border-collapse:collapse;min-width:980px;}
.data-tbl thead tr{background:linear-gradient(90deg,var(--orange),var(--orange-dk));}
.data-tbl thead th{padding:9px 11px;font-size:10px;font-weight:700;color:rgba(255,255,255,.9);text-transform:uppercase;letter-spacing:.8px;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);}
.data-tbl thead th:last-child{border-right:none;}
.data-tbl tbody tr{border-bottom:1px solid var(--border);transition:background .1s;}
.data-tbl tbody tr:hover{background:rgba(255,255,255,.025);}
.data-tbl tbody td{padding:8px 11px;font-size:11px;color:var(--text2);vertical-align:middle;}
.td-code{background:rgba(255,136,0,.1);color:var(--orange-lt);border-radius:4px;padding:2px 6px;font-size:10px;font-weight:700;font-family:monospace;}
.td-name{color:var(--text);font-weight:600;}
.td-price{color:#4dff88;font-weight:700;}
.td-cap{color:var(--text3);}
.cat-chip{display:inline-flex;align-items:center;gap:3px;background:rgba(68,136,255,.12);color:#88bbff;border:1px solid rgba(68,136,255,.2);border-radius:12px;padding:2px 8px;font-size:10px;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none;}
.cat-chip:hover{background:rgba(68,136,255,.25);color:#aaccff;}
.row-expired td{background:rgba(192,57,43,.06);}
.row-oos td{background:rgba(233,30,99,.06);}
.row-low td{background:rgba(248,29,14,.04);}
.row-nearing td{background:rgba(255,152,0,.04);}

/* Bell icon */
.bell-wrap {
  position: relative;
  display: inline-flex;
  cursor: help;
  margin-left: 6px;
  vertical-align: middle;
  animation: bell-pulse 2s ease-in-out infinite;
}
.bell-wrap .bi {
  font-size: 14px;
  filter: drop-shadow(0 0 3px rgba(255,255,255,0.3));
}
.bell-wrap.ls .bi { color: #ff3b30; }   /* Low Stock - Bright Red */
.bell-wrap.oos .bi { color: #ff2d95; }   /* Out of Stock - Hot Pink */
.bell-wrap.exp .bi { color: #ffa500; }   /* Nearing Expiry - Orange */
.bell-wrap.exd .bi { color: #ff0436; }   /* Expired - Crimson */
.bell-wrap.both .bi { color: #ff1493; }  /* Both issues - Deep Pink */
.bell-tip {
  visibility: hidden;
  opacity: 0;
  position: absolute;
  bottom: calc(100% + 8px);
  left: 50%;
  transform: translateX(-50%);
  background: #1e2330;
  border: 1px solid var(--border2);
  color: var(--text);
  border-radius: 6px;
  padding: 6px 10px;
  font-size: 10px;
  white-space: nowrap;
  z-index: 999;
  transition: opacity 0.2s, visibility 0.2s;
  pointer-events: none;
  box-shadow: 0 4px 15px rgba(0,0,0,0.5);
}
.bell-tip::after {
  content: '';
  position: absolute;
  top: 100%;
  left: 50%;
  transform: translateX(-50%);
  border: 5px solid transparent;
  border-top-color: #1e2330;
}
.bell-wrap:hover .bell-tip {
  visibility: visible;
  opacity: 1;
}

/* Also add a small colored dot indicator */
.bell-wrap::before {
  content: '';
  position: absolute;
  top: -2px;
  right: -2px;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  border: 1px solid var(--bg);
}
.bell-wrap.ls::before { background: #ff3b30; }
.bell-wrap.oos::before { background: #ff2d95; }
.bell-wrap.exp::before { background: #ffa500; }
.bell-wrap.exd::before { background: #dc143c; }
.bell-wrap.both::before { background: #ff1493; }
@keyframes bell-pulse {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.15); }
}
/* Badges */
.qty-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;}
.qty-ok{background:rgba(0,200,83,.12);color:#4dff88;border:1px solid rgba(0,200,83,.2);}
.qty-low{background:rgba(248,29,14,.12);color:#ff8888;border:1px solid rgba(248,29,14,.2);}
.qty-oos{background:rgba(233,30,99,.12);color:#ff88cc;border:1px solid rgba(233,30,99,.2);}
.exp-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:12px;font-size:10px;font-weight:700;}
.exp-ok{background:rgba(0,200,83,.1);color:#4dff88;}
.exp-warn{background:rgba(255,152,0,.1);color:#ffcc66;}
.exp-crit{background:rgba(255,69,0,.1);color:#ff8888;}
.exp-dead{background:rgba(192,57,43,.15);color:#ff6666;}

/* Product image */
.prod-img{width:42px;height:42px;object-fit:cover;border-radius:6px;border:1px solid var(--border);}
.prod-img-ph{width:42px;height:42px;background:var(--bg3);border-radius:6px;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:16px;color:var(--text3);}

/* Refill form */
.refill-form{display:flex;align-items:center;gap:4px;}
.refill-inp{background:var(--bg3);border:1px solid var(--border);color:var(--text);border-radius:4px;padding:4px 6px;width:65px;font-size:11px;text-align:center;}
.refill-inp:focus{outline:none;border-color:var(--orange);}
.rf-btn{width:25px;height:25px;border:none;border-radius:4px;cursor:pointer;font-size:13px;font-weight:700;display:flex;align-items:center;justify-content:center;transition:filter .15s;}
.rf-btn:hover{filter:brightness(1.15);}
.rf-dec{background:linear-gradient(135deg,var(--red),#aa1111);color:white;}
.rf-inc{background:linear-gradient(135deg,var(--green),#007a2e);color:white;}

/* Pager */
.pager{display:flex;gap:4px;margin-top:10px;justify-content:center;padding:10px;}
.pg-btn{background:var(--card2);border:1px solid var(--border);color:var(--text2);border-radius:5px;padding:4px 10px;font-size:11px;text-decoration:none;transition:all .15s;}
.pg-btn:hover{background:var(--orange);border-color:var(--orange);color:white;}
.pg-btn.active{background:linear-gradient(135deg,var(--orange),var(--orange-dk));border-color:var(--orange);color:white;}

/* Buttons */
.btn{padding:6px 14px;border:none;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none;transition:all .15s;white-space:nowrap;}
.btn:hover{filter:brightness(1.1);transform:translateY(-1px);}
.btn-orange{background:linear-gradient(135deg,var(--orange),var(--orange-dk));color:white;}
.btn-red{background:linear-gradient(135deg,var(--red),#aa1111);color:white;}
.btn-dark{background:var(--bg3);border:1px solid var(--border);color:var(--text2);}
.btn-dark:hover{background:var(--border2);filter:none;transform:none;}
.btn-green{background:linear-gradient(135deg,var(--green),#007a2e);color:white;}
.btn-blue{background:linear-gradient(135deg,var(--blue),#1a4fa0);color:white;}
.btn-sm{padding:3px 9px;font-size:10px;}

/* Modal - No border-radius, draggable */
.modal-overlay{display:none;position:fixed;inset:0; z-index:9000;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal-box{
  background:var(--card2);
  border:1px solid var(--border2);
  border-radius:0; /* No border-radius */
  width:96%;max-width:720px;max-height:92vh;
  display:flex;flex-direction:column;overflow:hidden;
  box-shadow:0 28px 80px rgba(0,0,0,.8);
  animation:mfade .22s ease;
  cursor:grab;position:relative;
}
.modal-box:active{cursor:grabbing;}
@keyframes mfade{from{opacity:0;transform:scale(.95) translateY(-10px);}to{opacity:1;transform:none;}}
.modal-hdr{padding:16px 20px 0;flex-shrink:0;cursor:grab;}
.modal-hdr:active{cursor:grabbing;}
.modal-hdr-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
.modal-hdr-top h3{font-size:15px;font-weight:800;color:var(--text);}
.mclose{
  background:var(--bg3);color:var(--text2);
  border:1px solid var(--border);
  border-radius:0; /* No border-radius */
  width:28px;height:28px;font-size:14px;cursor:pointer;
  font-weight:700;display:flex;align-items:center;justify-content:center;
}
.mclose:hover{background:var(--red);color:white;border-color:var(--red);}
.modal-cat-pill{
  display:inline-flex;align-items:center;gap:5px;
  background:rgba(255,136,0,.15);
  border:1px solid rgba(255,136,0,.3);
  border-radius:0; /* No border-radius */
  padding:3px 10px;font-size:11px;color:var(--orange-lt);font-weight:600;
}
.step-tabs{display:flex;gap:0;border-bottom:1px solid var(--border);}
.step-tab{
  flex:1;padding:9px 6px;text-align:center;
  font-size:11px;font-weight:600;color:var(--text3);
  cursor:pointer;border-bottom:2px solid transparent;
  transition:all .15s;display:flex;align-items:center;justify-content:center;gap:5px;
}
.step-tab:hover{color:var(--text2);}
.step-tab.active{color:var(--orange-lt);border-bottom-color:var(--orange);}
.step-tab .st-num{
  width:18px;height:18px;border-radius:50%; /* Keep circle for step numbers */
  background:var(--bg3);border:1px solid var(--border2);
  font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.step-tab.active .st-num{background:var(--orange);border-color:var(--orange);color:white;}
.step-tab.done .st-num{background:var(--green);border-color:var(--green);color:white;}
.step-tab.done{color:#66dd88;}
.modal-body{padding:18px 20px;overflow-y:auto;flex:1;}
.modal-body::-webkit-scrollbar{width:4px;}
.modal-body::-webkit-scrollbar-thumb{background:var(--border2);}
.step-panel{display:none;}
.step-panel.active{display:block;animation:stepfade .2s ease;}
@keyframes stepfade{from{opacity:0;transform:translateX(8px);}to{opacity:1;transform:none;}}
.form-section{margin-bottom:16px;}
.form-section-title{
  font-size:10px;font-weight:700;color:var(--text3);
  text-transform:uppercase;letter-spacing:1.2px;
  margin-bottom:10px;padding-bottom:6px;
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:6px;
}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:11px;}
.form-grid.g1{grid-template-columns:1fr;}
.fg-span2{grid-column:span 2;}
.form-group{display:flex;flex-direction:column;gap:4px;}
.form-group label{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;}
.form-input,.form-select{
  background:var(--bg3);border:1.5px solid var(--border);
  color:var(--text);border-radius:0; /* No border-radius */
  padding:8px 11px;font-size:12px;transition:all .15s;width:100%;
}
.form-input:focus,.form-select:focus{outline:none;border-color:var(--orange);background:rgba(255,136,0,.04);}
.form-input::placeholder{color:var(--text3);}
.form-select option{background:var(--card2);}
.toggle-group{display:flex;gap:6px;}
.toggle-opt{flex:1;}
.toggle-opt input{display:none;}
.toggle-opt label{
  display:flex;align-items:center;justify-content:center;gap:5px;
  padding:8px;background:var(--bg3);
  border:1.5px solid var(--border);
  border-radius:0; /* No border-radius */
  cursor:pointer;font-size:11px;font-weight:600;
  color:var(--text2);transition:all .15s;text-align:center;
}
.toggle-opt input:checked+label{background:rgba(255,136,0,.12);border-color:var(--orange);color:var(--orange-lt);}
.img-upload-zone{
  border:2px dashed var(--border2);
  border-radius:0; /* No border-radius */
  padding:18px;text-align:center;cursor:pointer;
  transition:all .15s;position:relative;overflow:hidden;
}
.img-upload-zone:hover{border-color:var(--orange);background:rgba(255,136,0,.03);}
.img-upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.img-upload-zone .uz-icon{font-size:24px;margin-bottom:5px;opacity:.5;}
.img-upload-zone .uz-text{font-size:11px;color:var(--text3);}
.img-preview-zone{
  width:100%;max-height:120px;object-fit:contain;
  border-radius:0; /* No border-radius */
  border:1px solid var(--border);display:none;margin-top:8px;
}
.modal-foot{
  padding:12px 20px;border-top:1px solid var(--border);
  display:flex;justify-content:space-between;align-items:center;flex-shrink:0;gap:8px;
}
.step-indicator{font-size:10px;color:var(--text3);}
@media(max-width:640px){.form-grid{grid-template-columns:1fr;}.fg-span2{grid-column:span 1;}.cat-tabs-scroll{flex-wrap:nowrap;}}
</style>

<!-- MAIN -->
<div class="main" id="mainContent">

  <div class="page-hero">
    <div>
      <h2>📦 Products Management</h2>
      <p><?= $totalAll ?> total products across <?= count($categories) ?> categories</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="export_products.php" class="btn btn-green">📊 Export Excel</a>
      <button class="btn btn-orange" onclick="openAddModal('')">➕ Add New Product</button>
    </div>
  </div>
  <!-- ════════════ CATEGORY TABS ════════════ -->
  <div class="cat-bar">
    <div class="cat-bar-top">
      <span class="cat-bar-title">🗂 Browse by Category</span>
      <div class="cat-search-wrap">
        <input type="text" id="tableSearch" placeholder="🔍 Search products…" oninput="searchTable()">
        <button onclick="clearSearch()">✕</button>
      </div>
    </div>

    <div class="cat-tabs-scroll">
      <!-- ALL -->
      <a href="product.php?cat=all"
         class="cat-tab-btn <?= $activeCat==='all'?'active':'' ?>">
        <span class="ct-icon">🗃</span>
        All Products
        <span class="ct-count"><?= $totalAll ?></span>
        <button class="cat-add-btn" onclick="event.preventDefault();openAddModal('')">+</button>
      </a>

      <!-- Per category -->
      <?php
      $catIcons = ['bread'=>'🍞','pastry'=>'🥐','cake'=>'🎂','drink'=>'🥤','beverage'=>'🍹',
                   'snack'=>'🍿','cookie'=>'🍪','pie'=>'🥧','donut'=>'🍩','roll'=>'🥖',
                   'general'=>'📦','other'=>'🏷'];
      foreach ($categories as $cat):
        $catName  = $cat['category'];
        $catSlug  = urlencode($catName);
        $isActive = ($activeCat === $catName);
        $icon     = '📦';
        foreach ($catIcons as $k=>$v) if (stripos($catName,$k)!==false){$icon=$v;break;}
      ?>
      <a href="product.php?cat=<?= $catSlug ?>"
         class="cat-tab-btn <?= $isActive?'active':'' ?>">
        <span class="ct-icon"><?= $icon ?></span>
        <?= htmlspecialchars($catName) ?>
        <span class="ct-count"><?= $cat['cnt'] ?></span>
        <button class="cat-add-btn"
                onclick="event.preventDefault();openAddModal('<?= htmlspecialchars($catName,ENT_QUOTES) ?>')">
          +
        </button>
      </a>
      <?php endforeach; ?>

      <!-- Uncategorized -->
      <?php if ($uncatCount > 0): ?>
      <a href="product.php?cat=uncategorized"
         class="cat-tab-btn <?= $activeCat==='uncategorized'?'active':'' ?>">
        <span class="ct-icon">🏷</span>
        Uncategorized
        <span class="ct-count"><?= $uncatCount ?></span>
        <button class="cat-add-btn" onclick="event.preventDefault();openAddModal('')">+</button>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Summary tiles -->
  <div class="sum-strip">
    <div class="sum-tile">
      <div class="st-label">Showing</div>
      <div class="st-val orange"><?= $countForCat ?></div>
    </div>
    <div class="sum-tile">
      <div class="st-label">Low Stock</div>
      <div class="st-val red"><?= count($lowStockItems) ?></div>
    </div>
    <div class="sum-tile">
      <div class="st-label">Out of Stock</div>
      <div class="st-val red"><?= count($outOfStockItems) ?></div>
    </div>
    <div class="sum-tile">
      <div class="st-label">Nearing Expiry</div>
      <div class="st-val yellow"><?= count($expiringItems) ?></div>
    </div>
    <div class="sum-tile">
      <div class="st-label">Expired</div>
      <div class="st-val red"><?= count($expiredItems) ?></div>
    </div>
  </div>

  <!-- Legend + alerts button -->
 <div class="legend-strip">
  <div class="legend-chip">
    <div class="lc-dot" style="background:#ff3b30;box-shadow:0 0 6px #ff3b30;"></div>
    Low Stock
  </div>
  <div class="legend-chip">
    <div class="lc-dot" style="background:#ff2d95;box-shadow:0 0 6px #ff2d95;"></div>
    Out of Stock
  </div>
  <div class="legend-chip">
    <div class="lc-dot" style="background:#ffa500;box-shadow:0 0 6px #ffa500;"></div>
    Nearing Expiry
  </div>
  <div class="legend-chip">
    <div class="lc-dot" style="background:#dc143c;box-shadow:0 0 6px #dc143c;"></div>
    Expired
  </div>
  <?php if(count($lowStockItems)+count($outOfStockItems)+count($expiringItems)+count($expiredItems)>0): ?>
  <button class="btn btn-red btn-sm" onclick="showAlerts()" style="margin-left:auto;">
    🚨 Inventory Alerts (<?= count($lowStockItems)+count($outOfStockItems)+count($expiringItems)+count($expiredItems) ?>)
  </button>
  <?php endif; ?>
</div>

  <!-- Products table -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">
        <div class="pt-dot"></div>
        <?php if($activeCat==='all'): ?>All Products
        <?php elseif($activeCat==='uncategorized'): ?>Uncategorized Products
        <?php else: ?><?= htmlspecialchars($activeCat) ?><?php endif; ?>
        <span style="background:var(--bg3);color:var(--text3);border-radius:10px;padding:1px 8px;font-size:10px;"><?= $countForCat ?></span>
      </div>
      <div style="display:flex;gap:6px;align-items:center;">
        <span style="font-size:10px;color:var(--text3);">Page <?= $page ?> of <?= max(1,$totalPages) ?></span>
        <button class="btn btn-orange btn-sm" onclick="openAddModal('<?= htmlspecialchars($activeCat==='all'?'':$activeCat,ENT_QUOTES) ?>')">
          ➕ Add to <?= $activeCat==='all'?'Products':htmlspecialchars($activeCat) ?>
        </button>
      </div>
    </div>
    <div style="padding:0;">
      <div class="tbl-wrap">
        <table class="data-tbl" id="productTable">
          <thead>
            <tr>
              <th>Code</th><th>Name</th><th>Category</th><th>Brand</th>
              <th>Seller</th><th>Capital</th><th>Price</th>
              <th>Purchased</th><th>Expiry</th><th>Image</th>
              <th>Stock</th><th>Refill/Deduct</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php $result->data_seek(0); while($row=$result->fetch_assoc()):
            $curQty = ($row['measurement_type']==='kg') ? (float)$row['kg'] : (int)$row['pieces'];
            $unit   = ($row['measurement_type']==='kg') ? 'kg' : 'pcs';
            $lowThr = ($row['measurement_type']==='kg') ? 20.0 : 20;
            $isOOS  = $curQty == 0;
            $isLow  = !$isOOS && $curQty <= $lowThr;
            $t3m    = date('Y-m-d',strtotime('+3 months'));
            $hasExp = !empty($row['expiry_date']) && !in_array($row['expiry_date'],['N/A','0000-00-00']);
            $isExpd = $hasExp && $row['expiry_date'] < $today;
            $isNear = $hasExp && !$isExpd && $row['expiry_date'] <= $t3m;
            $dLeft  = $hasExp ? floor((strtotime($row['expiry_date'])-strtotime($today))/(3600*24)) : null;

            $rowClass='';
            if($isExpd)     $rowClass='row-expired';
            elseif($isOOS)  $rowClass='row-oos';
            elseif($isLow)  $rowClass='row-low';
            elseif($isNear) $rowClass='row-nearing';

            $bellClass=''; $bellTip='';
            if($isOOS)            { $bellClass='oos'; $bellTip='OUT OF STOCK'; }
            elseif($isExpd)       { $bellClass='exd'; $bellTip='EXPIRED '.abs($dLeft).'d ago'; }
            elseif($isLow&&$isNear){ $bellClass='both';$bellTip='Low stock + Nearing expiry';}
            elseif($isLow)        { $bellClass='ls';  $bellTip='Low: '.$curQty.' '.$unit.' left';}
            elseif($isNear)       { $bellClass='exp'; $bellTip='Expires in '.$dLeft.'d';}

            $qtyClass = $isOOS ? 'qty-oos' : ($isLow ? 'qty-low' : 'qty-ok');

            if(!$hasExp)           { $expText='N/A';       $expClass=''; }
            elseif($isExpd)        { $expText='Expired ('.abs($dLeft).'d)'; $expClass='exp-dead';}
            elseif($dLeft!==null && $dLeft<=7)  { $expText=date('M j,Y',strtotime($row['expiry_date'])).' ('.$dLeft.'d)'; $expClass='exp-crit';}
            elseif($isNear)        { $expText=date('M j,Y',strtotime($row['expiry_date'])).' ('.$dLeft.'d)'; $expClass='exp-warn';}
            else                   { $expText=date('M j,Y',strtotime($row['expiry_date']));$expClass='exp-ok';}

            $catDisplay = htmlspecialchars($row['category'] ?: '—');
            $catLink    = $row['category'] ? 'product.php?cat='.urlencode($row['category']) : '#';
          ?>
          <tr class="<?= $rowClass ?>">
            <td><span class="td-code"><?= htmlspecialchars($row['code']) ?></span></td>
            <td>
              <span class="td-name"><?= htmlspecialchars($row['name']) ?></span>
              <?php if($bellClass): ?>
              <span class="bell-wrap <?= $bellClass ?>">
                <i class="bi bi-bell-fill"></i>
                <span class="bell-tip"><?= $bellTip ?></span>
              </span>
              <?php endif; ?>
            </td>
            <td>
              <a href="<?= $catLink ?>" class="cat-chip"><?= $catDisplay ?></a>
            </td>
            <td style="color:var(--text2);"><?= htmlspecialchars($row['brand']) ?></td>
            <td style="color:var(--text3);font-size:10px;"><?= htmlspecialchars($row['seller_store']) ?></td>
            <td class="td-cap">₱<?= number_format($row['purchase_price'],2) ?></td>
            <td class="td-price">₱<?= number_format($row['price'],2) ?></td>
            <td style="color:var(--text3);font-size:10px;white-space:nowrap;"><?= htmlspecialchars($row['purchase_date']) ?></td>
            <td>
              <?php if($expClass): ?>
                <span class="exp-badge <?= $expClass ?>"><?= $expText ?></span>
              <?php else: ?>
                <span style="color:var(--text3);">N/A</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if(!empty($row['image_path'])&&file_exists($row['image_path'])): ?>
                <img src="<?= htmlspecialchars($row['image_path']) ?>" class="prod-img" alt="">
              <?php else: ?>
                <div class="prod-img-ph">📦</div>
              <?php endif; ?>
            </td>
            <td>
              <span class="qty-badge <?= $qtyClass ?>">
                <?= ($row['measurement_type']==='kg')?number_format($curQty,2):$curQty ?> <?= $unit ?>
              </span>
            </td>
            <td>
              <form action="refill_product.php" method="POST" class="refill-form">
                <input type="hidden" name="product_id" value="<?= $row['id'] ?>">
                <input type="hidden" name="measurement_type" value="<?= $row['measurement_type'] ?>">
                <?php if($row['measurement_type']==='kg'): ?>
                  <input type="number" name="kg" placeholder="0.00" min="0.01" step="0.01" class="refill-inp" required>
                  <span style="font-size:9px;color:var(--text3);">kg</span>
                <?php else: ?>
                  <input type="number" name="pieces" placeholder="0" min="1" class="refill-inp" required>
                  <span style="font-size:9px;color:var(--text3);">pcs</span>
                <?php endif; ?>
                <button type="submit" name="action" value="decrease" class="rf-btn rf-dec" title="Decrease">−</button>
                <button type="submit" name="action" value="increase" class="rf-btn rf-inc" title="Add">+</button>
              </form>
            </td>
            <td style="white-space:nowrap;">
              <button class="btn btn-blue btn-sm" onclick="openEditModal(<?= $row['id'] ?>)">✏</button>
              <button class="btn btn-red btn-sm"  onclick="confirmDelete(<?= $row['id'] ?>)">🗑</button>
            </td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <?php if($totalPages>1): ?>
      <div class="pager">
        <?php
        $qpBase = 'product.php?cat='.urlencode($activeCat);
        if($page>1) echo '<a class="pg-btn" href="'.$qpBase.'&page='.($page-1).'">‹</a>';
        for($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++) echo '<a class="pg-btn'.($i==$page?' active':'').'" href="'.$qpBase.'&page='.$i.'">'.$i.'</a>';
        if($page<$totalPages) echo '<a class="pg-btn" href="'.$qpBase.'&page='.($page+1).'">›</a>';
        ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div><!-- end main -->

<!-- ═══════ ADD PRODUCT MODAL (3 Steps) ════════════════════════════════ -->
<div class="modal-overlay" id="addModal">
  <div class="modal-box" id="addModalBox">
    <div class="modal-hdr" id="addModalHdr">
      <div class="modal-hdr-top">
        <div style="display:flex;align-items:center;gap:10px;">
          <h3>➕ Add New Product</h3>
          <span class="modal-cat-pill" id="addCatPill" style="display:none;"></span>
        </div>
        <button class="mclose" onclick="closeModal('addModal')">✕</button>
      </div>
      <div class="step-tabs">
        <div class="step-tab active" onclick="goStep('add',1)" id="addTab1"><span class="st-num">1</span> Basic Info</div>
        <div class="step-tab" onclick="goStep('add',2)" id="addTab2"><span class="st-num">2</span> Stock & Pricing</div>
        <div class="step-tab" onclick="goStep('add',3)" id="addTab3"><span class="st-num">3</span> Dates & Photo</div>
      </div>
    </div>

    <form id="addProductForm" method="POST" enctype="multipart/form-data">
      <div class="modal-body">

        <!-- STEP 1 -->
        <div class="step-panel active" id="addStep1">
          <div class="form-section">
            <div class="form-section-title">🏷 Product Identity</div>
            <div class="form-grid">
              <div class="form-group fg-span2">
                <label>Product Name *</label>
                <input type="text" name="name" id="addName" class="form-input" placeholder="e.g. Pandesal" required>
              </div>
              <div class="form-group">
                <label>Brand</label>
                <input type="text" name="brand" class="form-input" placeholder="e.g. Golden Crust">
              </div>
              <div class="form-group">
                <label>Category *</label>
                <input type="text" name="category" id="addCategory" list="catList" class="form-input" placeholder="e.g. Bread" required>
                <datalist id="catList">
                  <?php foreach($categories as $c): ?>
                    <option value="<?= htmlspecialchars($c['category']) ?>">
                  <?php endforeach; ?>
                </datalist>
              </div>
              <div class="form-group fg-span2">
                <label>Seller / Store *</label>
                <input type="text" name="seller" class="form-input" placeholder="e.g. Angeles Bakery Supplier" required>
              </div>
            </div>
          </div>
        </div>

        <!-- STEP 2 -->
        <div class="step-panel" id="addStep2">
          <div class="form-section">
            <div class="form-section-title">💰 Pricing</div>
            <div class="form-grid">
              <div class="form-group">
                <label>Capital Price (₱) *</label>
                <input type="number" name="purchase_price" class="form-input" placeholder="0.00" step="0.01" min="0" required>
              </div>
              <div class="form-group">
                <label>Selling Price (₱) *</label>
                <input type="number" name="price" class="form-input" placeholder="0.00" step="0.01" min="0" required>
              </div>
            </div>
          </div>
          <div class="form-section">
            <div class="form-section-title">📦 Measurement & Quantity</div>
            <div class="toggle-group" style="margin-bottom:11px;">
              <div class="toggle-opt">
                <input type="radio" name="measurement" id="addMPcs" value="pieces" checked onchange="toggleAddMeasure()">
                <label for="addMPcs">🧮 Pieces</label>
              </div>
              <div class="toggle-opt">
                <input type="radio" name="measurement" id="addMKg" value="kg" onchange="toggleAddMeasure()">
                <label for="addMKg">⚖ Kilograms</label>
              </div>
            </div>
            <div id="addPcsWrap">
              <div class="form-group"><label>Initial Qty (pieces)</label>
                <input type="number" name="pieces" class="form-input" placeholder="0" min="1" value="1"></div>
            </div>
            <div id="addKgWrap" style="display:none;">
              <div class="form-group"><label>Initial Qty (kg)</label>
                <input type="number" name="kg" class="form-input" placeholder="0.00" min="0.01" step="0.01" value="1"></div>
            </div>
          </div>
        </div>

        <!-- STEP 3 -->
        <div class="step-panel" id="addStep3">
          <div class="form-section">
            <div class="form-section-title">📅 Dates</div>
            <div class="form-grid">
              <div class="form-group">
                <label>Date of Purchase</label>
                <input type="date" name="purchase_date" class="form-input">
              </div>
              <div class="form-group">
                <label>Expiry Status</label>
                <div class="toggle-group" style="gap:4px;">
                  <div class="toggle-opt">
                    <input type="radio" name="expiry_status" id="addExpHas" value="has_date" checked onchange="toggleAddExpiry()">
                    <label for="addExpHas" style="font-size:10px;">📅 Set Date</label>
                  </div>
                  <div class="toggle-opt">
                    <input type="radio" name="expiry_status" id="addExpNA" value="na" onchange="toggleAddExpiry()">
                    <label for="addExpNA" style="font-size:10px;">∞ N/A</label>
                  </div>
                </div>
              </div>
              <div class="form-group" id="addExpiryField">
                <label>Expiry Date</label>
                <input type="date" name="expiry_date" class="form-input">
              </div>
            </div>
          </div>
          <div class="form-section">
            <div class="form-section-title">🖼 Product Photo (Optional)</div>
            <div class="img-upload-zone">
              <input type="file" name="image_path" id="addImgInput" accept="image/*" onchange="previewAddImg(this)">
              <div class="uz-icon">📷</div>
              <div class="uz-text">Click to upload<br><small style="color:var(--text3);">JPG, PNG accepted</small></div>
              <img id="addImgPreview" class="img-preview-zone" src="" alt="">
            </div>
          </div>
        </div>

      </div>
      <div class="modal-foot">
        <span class="step-indicator" id="addStepInd">Step 1 of 3</span>
        <div style="display:flex;gap:7px;">
          <button type="button" class="btn btn-dark" id="addPrevBtn" onclick="prevStep('add')" style="display:none;">‹ Back</button>
          <button type="button" class="btn btn-orange" id="addNextBtn" onclick="nextStep('add',3)">Next ›</button>
          <button type="submit" class="btn btn-green" id="addSubmitBtn" style="display:none;">✓ Add Product</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ═══════ EDIT PRODUCT MODAL (3 Steps) ═══════════════════════════════ -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box" id="editModalBox">
    <div class="modal-hdr" id="editModalHdr">
  <div class="modal-box">
    <div class="modal-hdr">
      <div class="modal-hdr-top">
        <h3>✏ Edit Product</h3>
        <button class="mclose" onclick="closeModal('editModal')">✕</button>
      </div>
      <div class="step-tabs">
        <div class="step-tab active" onclick="goStep('edit',1)" id="editTab1"><span class="st-num">1</span> Basic Info</div>
        <div class="step-tab" onclick="goStep('edit',2)" id="editTab2"><span class="st-num">2</span> Stock & Pricing</div>
        <div class="step-tab" onclick="goStep('edit',3)" id="editTab3"><span class="st-num">3</span> Dates & Photo</div>
      </div>
    </div>
    <form id="editProductForm" method="POST" enctype="multipart/form-data" onsubmit="return submitEditForm(this)">
      <input type="hidden" name="update_product" value="1">
      <input type="hidden" name="product_id" id="editProductId">
      <div class="modal-body">
        <!-- EDIT STEP 1 -->
        <div class="step-panel active" id="editStep1">
          <div class="form-section">
            <div class="form-section-title">🏷 Product Identity</div>
            <div class="form-grid">
              <div class="form-group fg-span2"><label>Product Name *</label>
                <input type="text" name="name" id="editName" class="form-input" required></div>
              <div class="form-group"><label>Brand</label>
                <input type="text" name="brand" id="editBrand" class="form-input"></div>
              <div class="form-group"><label>Category *</label>
                <input type="text" name="category" id="editCategory" list="catList" class="form-input" required></div>
              <div class="form-group fg-span2"><label>Seller / Store</label>
                <input type="text" name="seller_store" id="editSellerStore" class="form-input"></div>
            </div>
          </div>
        </div>
        <!-- EDIT STEP 2 -->
        <div class="step-panel" id="editStep2">
          <div class="form-section">
            <div class="form-section-title">💰 Pricing</div>
            <div class="form-grid">
              <div class="form-group"><label>Capital Price (₱) *</label>
                <input type="number" name="purchase_price" id="editPurchasePrice" class="form-input" step="0.01" min="0" required></div>
              <div class="form-group"><label>Selling Price (₱) *</label>
                <input type="number" name="price" id="editPrice" class="form-input" step="0.01" min="0" required></div>
            </div>
          </div>
          <div class="form-section">
            <div class="form-section-title">📦 Measurement & Quantity</div>
            <div class="toggle-group" style="margin-bottom:11px;">
              <div class="toggle-opt">
                <input type="radio" name="edit_measurement" id="editMPcs" value="pieces" onchange="toggleEditMeasure()">
                <label for="editMPcs">🧮 Pieces</label>
              </div>
              <div class="toggle-opt">
                <input type="radio" name="edit_measurement" id="editMKg" value="kg" onchange="toggleEditMeasure()">
                <label for="editMKg">⚖ Kilograms</label>
              </div>
            </div>
            <div id="editPcsWrap"><div class="form-group"><label>Quantity (pieces)</label>
              <input type="number" name="pieces" id="editPieces" class="form-input" min="0"></div></div>
            <div id="editKgWrap" style="display:none;"><div class="form-group"><label>Quantity (kg)</label>
              <input type="number" name="kg" id="editKg" class="form-input" min="0" step="0.01"></div></div>
          </div>
        </div>
        <!-- EDIT STEP 3 -->
        <div class="step-panel" id="editStep3">
          <div class="form-section">
            <div class="form-section-title">📅 Dates</div>
            <div class="form-grid">
              <div class="form-group"><label>Date of Purchase</label>
                <input type="date" name="purchase_date" id="editPurchaseDate" class="form-input"></div>
              <div class="form-group"><label>Expiry Status</label>
                <div class="toggle-group" style="gap:4px;">
                  <div class="toggle-opt">
                    <input type="radio" name="edit_expiry_status" id="editExpHas" value="has_date" checked onchange="toggleEditExpiry()">
                    <label for="editExpHas" style="font-size:10px;">📅 Set Date</label>
                  </div>
                  <div class="toggle-opt">
                    <input type="radio" name="edit_expiry_status" id="editExpNA" value="na" onchange="toggleEditExpiry()">
                    <label for="editExpNA" style="font-size:10px;">∞ N/A</label>
                  </div>
                </div>
              </div>
              <div class="form-group" id="editExpiryField"><label>Expiry Date</label>
                <input type="date" name="expiry_date" id="editExpiryDate" class="form-input"></div>
            </div>
          </div>
          <div class="form-section">
            <div class="form-section-title">🖼 Product Photo</div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
              <img id="editImgPreview" src="" alt="" style="width:68px;height:68px;object-fit:cover;border-radius:8px;border:1px solid var(--border);flex-shrink:0;display:none;">
              <div class="img-upload-zone" style="flex:1;">
                <input type="file" name="edit_image_path" id="editImgInput" accept="image/*" onchange="previewEditImg(this)">
                <div class="uz-icon">📷</div>
                <div class="uz-text">Click to change<br><small style="color:var(--text3);">Leave empty to keep current</small></div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <span class="step-indicator" id="editStepInd">Step 1 of 3</span>
        <div style="display:flex;gap:7px;">
          <button type="button" class="btn btn-dark" id="editPrevBtn" onclick="prevStep('edit')" style="display:none;">‹ Back</button>
          <button type="button" class="btn btn-orange" id="editNextBtn" onclick="nextStep('edit',3)">Next ›</button>
          <button type="submit" class="btn btn-green" id="editSubmitBtn" style="display:none;">💾 Save Changes</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- JS DATA -->
<script>
const lowStockItems   = <?= json_encode(array_map(fn($r)=>['code'=>$r['code'],'name'=>$r['name'],'measurement_type'=>$r['measurement_type'],'pieces'=>$r['pieces'],'kg'=>$r['kg']],$lowStockItems)) ?>;
const outOfStockItems = <?= json_encode(array_map(fn($r)=>['code'=>$r['code'],'name'=>$r['name'],'measurement_type'=>$r['measurement_type']],$outOfStockItems)) ?>;
const expiringItems   = <?= json_encode($expiringItems) ?>;
const expiredItems    = <?= json_encode($expiredItems) ?>;
</script>

<script>
/* Clock */
function updateClock(){document.getElementById('currentTime').textContent=new Date().toLocaleString('en-US',{timeZone:'Asia/Manila',weekday:'short',year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});}
setInterval(updateClock,1000); updateClock();

/* Sidebar */
function toggleSidebar(){const sb=document.getElementById('sidebar');sb.style.display=sb.style.display==='flex'?'none':'flex';document.getElementById('menuBtn').textContent=sb.style.display==='flex'?'✖':'☰';}
function toggleSub(btn){const sub=btn.nextElementSibling;const o=sub.classList.toggle('open');btn.classList.toggle('open',o);}

/* Modal */
function closeModal(id){document.getElementById(id).classList.remove('show');}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('show');}));

/* ── Open Add Modal with optional category pre-fill ── */
function openAddModal(cat){
  // Reset steps
  stepState.add = 1; renderStep('add');
  // Pre-fill category
  const catInput = document.getElementById('addCategory');
  const pill     = document.getElementById('addCatPill');
  if(cat){
    catInput.value = cat;
    pill.textContent = '📂 ' + cat;
    pill.style.display = 'inline-flex';
  } else {
    catInput.value = '';
    pill.style.display = 'none';
  }
  document.getElementById('addModal').classList.add('show');
}

/* ── Step logic ── */
const stepState = {add:1, edit:1};
function renderStep(p){
  const cur=stepState[p], total=3;
  for(let i=1;i<=total;i++){
    const panel=document.getElementById(p+'Step'+i);
    if(panel) panel.classList.toggle('active',i===cur);
    const tab=document.getElementById(p+'Tab'+i);
    if(tab){tab.classList.remove('active','done');if(i===cur)tab.classList.add('active');else if(i<cur)tab.classList.add('done');}
  }
  document.getElementById(p+'StepInd').textContent='Step '+cur+' of '+total;
  const prev=document.getElementById(p+'PrevBtn');
  const next=document.getElementById(p+'NextBtn');
  const sub =document.getElementById(p+'SubmitBtn');
  if(prev) prev.style.display=cur>1?'':'none';
  if(next) next.style.display=cur<total?'':'none';
  if(sub)  sub.style.display=cur===total?'':'none';
}
function nextStep(p,total){if(stepState[p]<total){stepState[p]++;renderStep(p);}}
function prevStep(p){if(stepState[p]>1){stepState[p]--;renderStep(p);}}
function goStep(p,n){stepState[p]=n;renderStep(p);}

/* ── Measurement toggles ── */
function toggleAddMeasure(){
  const v=document.querySelector('input[name="measurement"]:checked').value;
  document.getElementById('addPcsWrap').style.display=v==='pieces'?'':'none';
  document.getElementById('addKgWrap').style.display=v==='kg'?'':'none';
}
function toggleEditMeasure(){
  const v=document.querySelector('input[name="edit_measurement"]:checked').value;
  document.getElementById('editPcsWrap').style.display=v==='pieces'?'':'none';
  document.getElementById('editKgWrap').style.display=v==='kg'?'':'none';
}
function toggleAddExpiry(){
  const v=document.querySelector('input[name="expiry_status"]:checked').value;
  document.getElementById('addExpiryField').style.display=v==='na'?'none':'';
}
function toggleEditExpiry(){
  const v=document.querySelector('input[name="edit_expiry_status"]:checked').value;
  document.getElementById('editExpiryField').style.display=v==='na'?'none':'';
}

/* ── Image preview ── */
function previewAddImg(input){
  if(!input.files||!input.files[0]) return;
  const r=new FileReader(); r.onload=e=>{const img=document.getElementById('addImgPreview');img.src=e.target.result;img.style.display='block';};
  r.readAsDataURL(input.files[0]);
}
function previewEditImg(input){
  if(!input.files||!input.files[0]) return;
  const r=new FileReader(); r.onload=e=>{const img=document.getElementById('editImgPreview');img.src=e.target.result;img.style.display='block';};
  r.readAsDataURL(input.files[0]);
}

/* ── Open Edit Modal ── */
function openEditModal(id){
  fetch(`get_product.php?id=${id}`)
    .then(r=>r.json())
    .then(p=>{
      if(p.error) throw new Error(p.error);
      document.getElementById('editProductId').value=p.id;
      document.getElementById('editName').value=p.name||'';
      document.getElementById('editCategory').value=p.category||'';
      document.getElementById('editBrand').value=p.brand||'';
      document.getElementById('editSellerStore').value=p.seller_store||'';
      document.getElementById('editPurchasePrice').value=p.purchase_price||'';
      document.getElementById('editPrice').value=p.price||'';
      document.getElementById('editPurchaseDate').value=p.purchase_date||'';
      const hasExp=p.expiry_date&&p.expiry_date!=='N/A'&&p.expiry_date!=='0000-00-00';
      document.getElementById('editExpHas').checked=hasExp;
      document.getElementById('editExpNA').checked=!hasExp;
      document.getElementById('editExpiryDate').value=hasExp?p.expiry_date:'';
      toggleEditExpiry();
      const mt=p.measurement_type||'pieces';
      document.getElementById('editMPcs').checked=mt==='pieces';
      document.getElementById('editMKg').checked=mt==='kg';
      document.getElementById('editPieces').value=p.pieces||0;
      document.getElementById('editKg').value=parseFloat(p.kg||0).toFixed(2);
      toggleEditMeasure();
      const prev=document.getElementById('editImgPreview');
      if(p.image_path){prev.src='../'+p.image_path;prev.style.display='block';}
      else prev.style.display='none';
      stepState.edit=1; renderStep('edit');
      document.getElementById('editModal').classList.add('show');
    })
    .catch(e=>Swal.fire({icon:'error',title:'Error',text:e.message,background:'#1e2330',color:'#e8eaf0'}));
}
/* ── Drag functionality for all modals ── */
function makeDraggable(modalBoxId, handleId) {
  const modalBox = document.getElementById(modalBoxId);
  const handle = document.getElementById(handleId) || modalBox;
  
  if (!modalBox) return;
  
  let isDragging = false;
  let startX, startY, initialX, initialY;
  
  handle.addEventListener('mousedown', function(e) {
    if (e.target.closest('button') || e.target.closest('input') || e.target.closest('select') || e.target.closest('textarea')) return;
    isDragging = true;
    const rect = modalBox.getBoundingClientRect();
    startX = e.clientX;
    startY = e.clientY;
    initialX = rect.left;
    initialY = rect.top;
    if (!modalBox.style.position || modalBox.style.position === 'relative') {
      modalBox.style.position = 'fixed';
      modalBox.style.left = initialX + 'px';
      modalBox.style.top = initialY + 'px';
      modalBox.style.margin = '0';
    }
    handle.style.cursor = 'grabbing';
    e.preventDefault();
  });
  
  document.addEventListener('mousemove', function(e) {
    if (!isDragging) return;
    const deltaX = e.clientX - startX;
    const deltaY = e.clientY - startY;
    modalBox.style.left = (initialX + deltaX) + 'px';
    modalBox.style.top = (initialY + deltaY) + 'px';
  });
  
  document.addEventListener('mouseup', function() {
    if (isDragging) {
      isDragging = false;
      handle.style.cursor = 'grab';
    }
  });
}

// Initialize drag for both modals
document.addEventListener('DOMContentLoaded', function() {
  makeDraggable('addModalBox', 'addModalHdr');
  makeDraggable('editModalBox', 'editModalHdr');
});
/* ── Edit submit ── */
function submitEditForm(form){
  event.preventDefault();
  if(!form.checkValidity()){form.reportValidity();return false;}
  const btn=document.getElementById('editSubmitBtn');
  btn.disabled=true;btn.textContent='⏳ Saving…';
  fetch('product.php',{method:'POST',body:new FormData(form),redirect:'follow'})
    .then(r=>{if(r.redirected)window.location.href=r.url;else window.location.reload();})
    .catch(e=>{Swal.fire({icon:'error',title:'Error',text:e.message,background:'#1e2330',color:'#e8eaf0'});btn.disabled=false;btn.textContent='💾 Save Changes';});
  return false;
}

/* ── Delete ── */
function confirmDelete(id){
  Swal.fire({title:'Delete Product?',text:'This cannot be undone.',icon:'warning',showCancelButton:true,
    confirmButtonColor:'#ff4444',cancelButtonColor:'#555',confirmButtonText:'Yes, delete!',
    background:'#1e2330',color:'#e8eaf0'
  }).then(r=>{if(r.isConfirmed)window.location.href='delete_product.php?id='+id;});
}

/* ── Table search ── */
function searchTable(){
  const q=document.getElementById('tableSearch').value.toUpperCase();
  document.querySelectorAll('#productTable tbody tr').forEach(row=>{
    row.style.display=Array.from(row.cells).some(c=>c.textContent.toUpperCase().includes(q))?'':'none';
  });
}
function clearSearch(){document.getElementById('tableSearch').value='';document.querySelectorAll('#productTable tbody tr').forEach(r=>r.style.display='');}

/* ── Inventory Alerts ── */
function showAlerts(){
  function fmtR(d){if(d<0)return`<span style="color:#c0392b;font-weight:700;">EXPIRED ${Math.abs(d)}d ago</span>`;if(d===0)return`<span style="color:#ff0000;font-weight:700;">TODAY</span>`;if(d<=7)return`<span style="color:#ff4500;font-weight:700;">${d}d</span>`;const m=Math.floor(d/30),rd=d%30;return`<span style="color:#f0ad4e;">${m}mo${rd>0?' '+rd+'d':''}</span>`;}
  const tblStyle='width:100%;border-collapse:collapse;font-size:11px;';
  const thStyle='padding:4px 8px;text-align:left;color:#9aa3bc;font-size:10px;border-bottom:1px solid #2a3145;';
  const tdStyle='padding:5px 8px;border-bottom:1px solid #1e2330;';
  let html='<div style="text-align:left;max-height:58vh;overflow-y:auto;color:#e8eaf0;">';
  if(outOfStockItems.length){
    html+=`<div style="margin-bottom:12px;"><div style="color:#e91e63;font-weight:700;margin-bottom:6px;">⛔ OUT OF STOCK (${outOfStockItems.length})</div><table style="${tblStyle}"><tr><th style="${thStyle}">Code</th><th style="${thStyle}">Product</th><th style="${thStyle}">Type</th></tr>`;
    outOfStockItems.forEach(i=>html+=`<tr><td style="${tdStyle}">${i.code}</td><td style="${tdStyle}">${i.name}</td><td style="${tdStyle}"><span style="background:rgba(233,30,99,.15);color:#ff88cc;border-radius:10px;padding:1px 7px;font-size:10px;">${i.measurement_type}</span></td></tr>`);
    html+='</table></div>';
  }
  if(lowStockItems.length){
    html+=`<div style="margin-bottom:12px;"><div style="color:#f81d0e;font-weight:700;margin-bottom:6px;">⚠ LOW STOCK (${lowStockItems.length})</div><table style="${tblStyle}"><tr><th style="${thStyle}">Code</th><th style="${thStyle}">Product</th><th style="${thStyle}">Qty</th></tr>`;
    lowStockItems.forEach(i=>{const q=i.measurement_type==='kg'?parseFloat(i.kg).toFixed(2):parseInt(i.pieces);const u=i.measurement_type==='kg'?'kg':'pcs';html+=`<tr><td style="${tdStyle}">${i.code}</td><td style="${tdStyle}">${i.name}</td><td style="${tdStyle};color:#ff8888;font-weight:700;">${q} ${u}</td></tr>`;});
    html+='</table></div>';
  }
  if(expiringItems.length){
    html+=`<div style="margin-bottom:12px;"><div style="color:#ff9800;font-weight:700;margin-bottom:6px;">🕐 NEARING EXPIRY (${expiringItems.length})</div><table style="${tblStyle}"><tr><th style="${thStyle}">Code</th><th style="${thStyle}">Product</th><th style="${thStyle}">Expiry</th><th style="${thStyle}">Remaining</th></tr>`;
    expiringItems.forEach(i=>html+=`<tr><td style="${tdStyle}">${i.code}</td><td style="${tdStyle}">${i.name}</td><td style="${tdStyle};color:#ffcc66;">${i.expiry_date}</td><td style="${tdStyle}">${fmtR(parseInt(i.days_remaining))}</td></tr>`);
    html+='</table></div>';
  }
  if(expiredItems.length){
    html+=`<div style="margin-bottom:6px;"><div style="color:#c0392b;font-weight:700;margin-bottom:6px;">💀 EXPIRED (${expiredItems.length})</div><table style="${tblStyle}"><tr><th style="${thStyle}">Code</th><th style="${thStyle}">Product</th><th style="${thStyle}">Expired</th></tr>`;
    expiredItems.forEach(i=>html+=`<tr><td style="${tdStyle}">${i.code}</td><td style="${tdStyle}">${i.name}</td><td style="${tdStyle};color:#ff6666;">${i.expiry_date} (${i.days_expired}d ago)</td></tr>`);
    html+='</table></div>';
  }
  html+='</div>';
  Swal.fire({title:'🚨 Inventory Alerts',html,icon:'warning',confirmButtonText:'✓ Acknowledged',
    confirmButtonColor:'#ff8800',width:'780px',background:'#1e2330',color:'#e8eaf0',
    showCloseButton:true,allowOutsideClick:false});
}

/* Auto-alerts + session SweetAlert */
document.addEventListener('DOMContentLoaded',function(){
  <?php if(count($lowStockItems)+count($outOfStockItems)+count($expiringItems)+count($expiredItems)>0): ?>
  setTimeout(showAlerts,700);
  <?php endif; ?>
  <?php if(isset($_SESSION['swal'])): ?>
  Swal.fire({icon:'<?= $_SESSION['swal']['type'] ?>',title:'<?= addslashes($_SESSION['swal']['title']) ?>',
    text:'<?= addslashes($_SESSION['swal']['text']) ?>',confirmButtonColor:'#ff8800',background:'#1e2330',color:'#e8eaf0'});
  <?php unset($_SESSION['swal']); endif; ?>
});

/* Connectivity */
function checkConn(){
  fetch('../record/ping.php',{cache:'no-store'})
    .then(r=>{const el=document.getElementById('connStatus');el.className=r.ok?'s-conn online':'s-conn offline';el.querySelector('span').textContent=r.ok?'ONLINE':'OFFLINE';})
    .catch(()=>{const el=document.getElementById('connStatus');el.className='s-conn offline';el.querySelector('span').textContent='OFFLINE';});
}
setInterval(checkConn,15000); checkConn();
</script>
<?php require_once __DIR__ . '/../include/admin_footer.php'; ?>
</body>
</html>