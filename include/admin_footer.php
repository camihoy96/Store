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
<style>
/* ═══════ UNIVERSAL CUSTOM MODAL STYLES ═══════ */
.modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 99999 !important; /* Forces above all headers/other modals */
  align-items: center;
  justify-content: center;
  padding: 16px;
  box-sizing: border-box;
}

.modal-overlay.show {
  display: flex;
  animation: modalFadeIn 0.2s ease;
}

@keyframes modalFadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.modal-box {
  background: var(--card, #1e2330);
  border: 1px solid var(--border, #2a3145);
  border-radius: 12px;
  width: 100%;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  animation: modalSlideUp 0.25s ease;
}

@keyframes modalSlideUp {
  from { opacity: 0; transform: scale(0.95) translateY(10px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}

.modal-hdr {
  padding: 16px 20px 0;
  flex-shrink: 0;
}

.modal-hdr-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.modal-hdr-top h3 {
  font-size: 16px;
  font-weight: 800;
  color: var(--text, #e8eaf0);
  margin: 0;
}

.mclose {
  background: var(--bg3, #181c22);
  color: var(--text2, #9aa3bc);
  border: 1px solid var(--border, #2a3145);
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

.mclose:hover {
  background: var(--red, #ff4444);
  color: white;
  border-color: var(--red, #ff4444);
}

.modal-body {
  padding: 0 20px 20px;
  overflow-y: auto;
  flex: 1;
}

.modal-foot {
  padding: 14px 20px;
  border-top: 1px solid var(--border, #2a3145);
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  flex-shrink: 0;
  background: var(--card, #1e2330);
}

/* Fallback Button Styles (in case global styles aren't loaded yet) */
.btn {
  padding: 8px 16px;
  border: none;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  transition: all 0.15s;
  text-decoration: none;
}

.btn:hover {
  filter: brightness(1.1);
  transform: translateY(-1px);
}

.btn:active {
  transform: translateY(0);
}

.btn-dark {
  background: var(--bg3, #181c22);
  border: 1px solid var(--border, #2a3145);
  color: var(--text2, #9aa3bc);
}

.btn-dark:hover {
  background: var(--border, #2a3145);
  color: var(--text, #e8eaf0);
  filter: none;
  transform: none;
}

.btn-red {
  background: linear-gradient(135deg, var(--red, #ff4444), #aa1111);
  color: white;
}

.btn-red:hover {
  filter: brightness(1.1);
  transform: translateY(-1px);
}
</style>

<!-- ═══════ UNIVERSAL CUSTOM MODALS (Replaces SweetAlert2) ═══════ -->
<div class="modal-overlay" id="customAlertModal">
  <div class="modal-box" style="max-width: 420px;">
    <div class="modal-hdr">
      <div class="modal-hdr-top">
        <h3 id="customAlertTitle">Alert</h3>
        <button class="mclose" onclick="closeModal('customAlertModal')">✕</button>
      </div>
    </div>
    <div class="modal-body" style="text-align: center; padding: 20px;">
      <div id="customAlertIcon" style="font-size: 48px; margin-bottom: 12px;">ℹ️</div>
      <div id="customAlertMessage" style="color: var(--text2); font-size: 13px; line-height: 1.5;"></div>
    </div>
    <div class="modal-foot" style="justify-content: center;">
      <button class="btn btn-dark" onclick="closeModal('customAlertModal')" style="min-width: 100px;">OK</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="customConfirmModal">
  <div class="modal-box" style="max-width: 420px;">
    <div class="modal-hdr">
      <div class="modal-hdr-top">
        <h3 id="customConfirmTitle">Confirm Action</h3>
        <button class="mclose" onclick="closeModal('customConfirmModal')">✕</button>
      </div>
    </div>
    <div class="modal-body" style="text-align: center; padding: 20px;">
      <div style="font-size: 48px; margin-bottom: 12px;">⚠️</div>
      <div id="customConfirmMessage" style="color: var(--text2); font-size: 13px; line-height: 1.5;"></div>
    </div>
    <div class="modal-foot" style="justify-content: center; gap: 10px;">
      <button class="btn btn-dark" onclick="closeModal('customConfirmModal')" style="min-width: 100px;">Cancel</button>
      <button class="btn btn-red" id="customConfirmBtn" style="min-width: 100px;">Confirm</button>
    </div>
  </div>
</div>

<script>
/* Universal Modal Helpers */
function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('show');
        document.body.classList.remove('modal-open');
    }
}

function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('show');
        document.body.classList.add('modal-open');
    }
}

function showCustomAlert(title, message, type = 'info', isHtml = false) {
    document.getElementById('customAlertTitle').textContent = title;
    const msgEl = document.getElementById('customAlertMessage');
    const iconEl = document.getElementById('customAlertIcon');
    
    if (isHtml) { 
        msgEl.innerHTML = message; 
        msgEl.style.textAlign = 'left'; 
    } else { 
        msgEl.textContent = message; 
        msgEl.style.textAlign = 'center'; 
    }
    
    if (type === 'error') iconEl.textContent = '❌';
    else if (type === 'warning') iconEl.textContent = '⚠️';
    else if (type === 'success') iconEl.textContent = '✅';
    else iconEl.textContent = 'ℹ️';
    
    openModal('customAlertModal');
}

function showCustomConfirm(title, message, onConfirm, isHtml = false) {
    document.getElementById('customConfirmTitle').textContent = title;
    const msgEl = document.getElementById('customConfirmMessage');
    
    if (isHtml) msgEl.innerHTML = message; 
    else msgEl.textContent = message;
    
    const btn = document.getElementById('customConfirmBtn');
    // Clone to remove old event listeners and prevent stacking
    const newBtn = btn.cloneNode(true);
    btn.parentNode.replaceChild(newBtn, btn);
    
    newBtn.addEventListener('click', () => { 
        closeModal('customConfirmModal'); 
        onConfirm(); 
    });
    
    openModal('customConfirmModal');
}

// Close modal when clicking outside the box
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal(this.id);
        }
    });
});
</script>
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