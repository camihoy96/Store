<?php
// include/admin_footer.php
?>
<!-- ═══════════════════════════════════ STATUS BAR -->
<div class="status-bar">
  <span>St4nger POS v1.0</span>
  <span class="sb-sep">|</span>
  <span>Admin Dashboard</span>
  <span class="sb-sep">|</span>
  <span><?= date('F j, Y') ?></span>
  <span class="sb-sep">|</span>
  <span><?= htmlspecialchars($_SESSION['username']??'Admin') ?></span>
  <div class="sb-conn offline" id="connStatus">
    <div class="cdot"></div><span>OFFLINE</span>
  </div>
</div>

<div class="footer">&copy; <?= date('Y') ?> St4nger Dev. All rights reserved.</div>

<script>
/* Connectivity */
function checkConn(){
  const el=document.getElementById('connStatus');
  fetch('record/ping.php',{cache:'no-store'})
    .then(r=>{ el.className=r.ok?'sb-conn online':'sb-conn offline'; el.querySelector('span').textContent=r.ok?'ONLINE':'OFFLINE'; })
    .catch(()=>{ el.className='sb-conn offline'; el.querySelector('span').textContent='OFFLINE'; });
}
setInterval(checkConn,15000); checkConn();
</script>
</body>
</html>