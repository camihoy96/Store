<?php
// include/footer.php
?>
<!-- STATUS BAR -->
<div class="status-bar">
  <span>St4nger POS v1.0</span>
  <span>Terminal 1001</span>
  <span><?= date('F j, Y') ?></span>
  <span class="status-offline" id="connStatus">● OFFLINE</span>
  <span>Help <span class="hint-key">F1</span></span>
  <span>Pay: <span class="hint-key">Enter</span></span>
  <span>E-Wallet: <span class="hint-key">F3</span></span>
  <span>Cancel: <span class="hint-key">F2</span></span>
  <span>Custom: <span class="hint-key">F8</span></span>
  <span>Search Input: <span class="hint-key">F5</span></span>
  <span>Close: <span class="hint-key">ESC</span></span>
</div>

<!-- ══ HIDDEN RECEIPT (print only) ═══════════════════════════════════ -->
<div id="receipt" style="display:none; padding:10px;">
  <div class="center">
    <h2><?= htmlspecialchars($businessName) ?></h2>
    <div style="font-size:10px;"><?= htmlspecialchars($businessAddress) ?><br><?= htmlspecialchars($businessPhone) ?></div>
  </div>
  <hr>
  <div>Date: <span id="r-date"></span> &nbsp; Time: <span id="r-time"></span></div>
  <div>Cashier: <span id="r-cashier"></span></div>
  <hr>
  <div id="r-items"></div>
  <hr>
  <div>Total: <?= $currencySymbol ?><span id="r-total"></span></div>
  <div id="r-payment-line"></div>
  <div id="r-change-line" style="display:none;">Change: <?= $currencySymbol ?><span id="r-change"></span></div>
  <hr>
  <div class="center"><?= htmlspecialchars($receiptFooter) ?></div>
</div>

<!-- ══ CASH PAYMENT MODAL ════════════════════════════════════════════ -->
<div class="modal-overlay" id="payModal">
  <div class="pay-modal">
    <div class="pay-modal-title">
      <span id="payModalTitle">PAYMENT</span>
      <button class="modal-x" onclick="closePayModal()">✕</button>
    </div>
    <div class="pay-modal-body">
      <div class="pm-summary" id="pmSummary"></div>
      <div class="pm-row">
        <span class="pm-label">Cash <?= $currencySymbol ?></span>
        <input type="number" class="pm-input" id="pmCash"
               placeholder="Enter amount" oninput="pmCalcChange()">
      </div>
      <div class="pm-change" id="pmChange">Change: <?= $currencySymbol ?>0.00</div>
      <div class="pm-btns">
        <button class="pm-btn cancel"  onclick="closePayModal()">Cancel</button>
        <button class="pm-btn proceed" onclick="processPayment()">✓ Confirm Pay</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ E-WALLET PAYMENT MODAL ════════════════════════════════════════ -->
<div class="modal-overlay" id="walletModal">
  <div class="pay-modal">
    <div class="pay-modal-title">
      <span id="walletModalTitle">E-WALLET PAYMENT</span>
      <button class="modal-x" onclick="closeWalletModal()">✕</button>
    </div>
    <div class="pay-modal-body">
      <div class="pm-summary" id="wmSummary"></div>
      <div class="pm-row">
        <span class="pm-label">Payment Method</span>
        <div style="display:flex;gap:6px;flex:1;">
          <select class="pm-input" id="wmProvider" onchange="updateWalletHints()" style="flex:1;">
            <option value="">-- Select Payment --</option>
            <?php foreach ($paymentMethods as $pm): ?>
            <option value="<?= htmlspecialchars($pm['provider']) ?>"
                    data-qr="<?= htmlspecialchars($pm['qr_code_path'] ?? '') ?>"
                    data-name="<?= htmlspecialchars($pm['name']) ?>"
                    data-account-name="<?= htmlspecialchars($pm['account_name'] ?? '') ?>"
                    data-account-number="<?= htmlspecialchars($pm['account_number'] ?? '') ?>">
              <?= htmlspecialchars($pm['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <button type="button" id="btnShowQR" onclick="toggleQRCode()"
                  style="padding:7px 12px;background:linear-gradient(180deg,#4a90e2,#357abd);
                         color:white;border:none;border-radius:4px;font-size:11px;
                         font-weight:600;cursor:pointer;white-space:nowrap;display:none;">
            📷 Show QR
          </button>
        </div>
      </div>

      <!-- QR Card -->
      <div id="qrContainer" style="display:none;margin:4px 0 12px;">
        <div id="qrCard" style="border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.2);max-width:260px;margin:0 auto;">
          <div id="qrCardHeader" style="padding:14px 16px 12px;text-align:center;background:#0070e0;">
            <div id="qrLogoText" style="font-size:20px;font-weight:900;color:white;letter-spacing:1px;margin-bottom:6px;"></div>
            <div id="qrAccountName" style="font-size:15px;font-weight:800;color:white;letter-spacing:1.5px;margin-bottom:2px;"></div>
            <div id="qrAccountNumber" style="font-size:13px;font-weight:600;color:rgba(255,255,255,0.88);letter-spacing:1px;"></div>
          </div>
          <div style="background:white;padding:14px;text-align:center;">
            <div style="width:200px;height:200px;margin:0 auto;border-radius:10px;border:3px solid #eee;display:flex;align-items:center;justify-content:center;overflow:hidden;background:white;">
              <img id="qrImage" src="" alt="QR Code" style="width:100%;height:100%;object-fit:contain;display:none;">
              <div id="qrPlaceholder" style="text-align:center;padding:10px;">
                <div style="font-size:46px;">📱</div>
                <div style="font-size:10px;color:#aaa;margin-top:6px;line-height:1.5;">No QR image configured.<br>Upload one in Settings → Payment Methods.</div>
              </div>
            </div>
            <div id="qrAmountLabel" style="margin-top:10px;font-size:20px;font-weight:900;color:#006600;"></div>
            <div style="margin-top:4px;font-size:11px;color:#888;">Ask customer to scan with their <strong id="qrScanLabel">e-wallet</strong> app</div>
            <button type="button" onclick="toggleQRCode()" style="margin-top:10px;padding:5px 22px;background:#888;color:white;border:none;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;">✕ Hide QR</button>
          </div>
        </div>
      </div>

      <div class="pm-row">
        <span class="pm-label">Ref. No.</span>
        <input type="text" class="pm-input" id="wmRefNo"
               placeholder="Enter transaction reference" maxlength="50">
      </div>
      <div class="pm-row">
        <span class="pm-label">Amount <?= $currencySymbol ?></span>
        <input type="number" class="pm-input" id="wmAmount" placeholder="0.00" step="0.01" readonly>
      </div>
      <div class="pm-change" id="wmStatus">Awaiting confirmation</div>
      <div class="pm-btns">
        <button class="pm-btn cancel"  onclick="closeWalletModal()">Cancel</button>
        <button class="pm-btn proceed" onclick="processWalletPayment()">✓ Confirm</button>
      </div>
      <div style="margin-top:10px;font-size:10px;color:#888;text-align:center;">
        💡 Ensure customer payment is completed before confirming.
      </div>
    </div>
  </div>
</div>

<!-- ══ ERROR MODAL ════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="errModal">
  <div class="err-modal">
    <div class="pay-modal-title">
      <span>NOTICE</span>
      <button class="modal-x" onclick="closeErr()">✕</button>
    </div>
    <div class="err-bar"></div>
    <div class="err-body">
      <div class="err-icon">✕</div>
      <span class="err-msg" id="errMsg">An error occurred.</span>
    </div>
    <div class="err-foot"><button class="ok-btn" onclick="closeErr()">OK</button></div>
  </div>
</div>

<!-- ══ DASHBOARD LOGIN MODAL ══════════════════════════════════════════ -->
<div class="modal-overlay" id="dashModal">
  <div class="dash-modal">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
      <h2>🔑 Login</h2>
      <button onclick="closeDashLogin()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#888;">✖</button>
    </div>
    <iframe id="dashIframe" src="" style="width:100%;height:600px;border:none;border-radius:6px;"></iframe>
  </div>
</div>

<!-- ══ HELP MODAL ════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="helpModal">
  <div style="background:white;border-radius:10px;width:580px;max-width:95vw;max-height:85vh;overflow:hidden;box-shadow:0 16px 50px rgba(0,0,0,0.6);display:flex;flex-direction:column;">
    <div style="background:linear-gradient(180deg,#ff9900,#ff6600);padding:12px 16px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
      <span style="font-weight:800;font-size:15px;color:white;letter-spacing:1px;">📖 POS KEYBOARD SHORTCUTS & GUIDE</span>
      <button onclick="closeHelp()" style="background:#aa2200;color:white;border:none;border-radius:3px;width:24px;height:24px;font-size:14px;cursor:pointer;font-weight:700;">✕</button>
    </div>
    <div style="padding:18px;overflow-y:auto;color:#222;font-size:13px;">
      <div style="font-weight:800;font-size:12px;color:#ff6600;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;border-bottom:2px solid #ff6600;padding-bottom:4px;">⌨️ Keyboard Shortcuts</div>
      <table style="width:100%;border-collapse:collapse;margin-bottom:18px;">
        <thead><tr style="background:#f5f5f5;">
          <th style="padding:7px 10px;text-align:left;font-size:11px;color:#555;border-bottom:1px solid #ddd;">Key</th>
          <th style="padding:7px 10px;text-align:left;font-size:11px;color:#555;border-bottom:1px solid #ddd;">Action</th>
          <th style="padding:7px 10px;text-align:left;font-size:11px;color:#555;border-bottom:1px solid #ddd;">Description</th>
        </tr></thead>
        <tbody>
           <tr style="border-bottom:1px solid #f0f0f0;"><td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">Esc</span></td><td style="padding:7px 10px;font-weight:600;">Close</td><td style="padding:7px 10px;color:#666;">Close modal</td></tr>
          <tr style="border-bottom:1px solid #f0f0f0;"><td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">Enter</span></td><td style="padding:7px 10px;font-weight:600;">Cash Payment</td><td style="padding:7px 10px;color:#666;">Opens the cash payment modal</td></tr>
          <tr style="border-bottom:1px solid #f0f0f0;background:#fafafa;"><td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">F2</span></td><td style="padding:7px 10px;font-weight:600;">Clear / Cancel</td><td style="padding:7px 10px;color:#666;">Clears all items from the current order</td></tr>
          <tr style="border-bottom:1px solid #f0f0f0;"><td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">F3</span></td><td style="padding:7px 10px;font-weight:600;">E-Wallet Payment</td><td style="padding:7px 10px;color:#666;">Opens GCash / Maya / GrabPay payment modal</td></tr>
          <tr style="border-bottom:1px solid #f0f0f0;background:#fafafa;"><td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">F5</span></td><td style="padding:7px 10px;font-weight:600;">Focus Search</td><td style="padding:7px 10px;color:#666;">Jumps focus to the product search bar</td></tr>
          <tr style="border-bottom:1px solid #f0f0f0;"><td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">F8</span></td><td style="padding:7px 10px;font-weight:600;">Custom Product</td><td style="padding:7px 10px;color:#666;">Focuses the custom product name input</td></tr>
          <tr style="background:#fafafa;"><td style="padding:7px 10px;"><span style="background:#333;color:#1ffa02;font-family:monospace;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;">F1</span></td><td style="padding:7px 10px;font-weight:600;">Help</td><td style="padding:7px 10px;color:#666;">Opens this help guide</td></tr>
        </tbody>
      </table>
      <div style="font-weight:800;font-size:12px;color:#ff6600;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;border-bottom:2px solid #ff6600;padding-bottom:4px;">🛒 How to Process a Sale</div>
      <ol style="padding-left:18px;line-height:2;color:#444;margin-bottom:18px;">
        <li>Click a product from the menu grid to add it to the order panel.</li>
        <li>Adjust quantity directly in the order panel by editing the qty field.</li>
        <li>Enter the cash amount or press <strong>Enter</strong> to open payment.</li>
        <li>Confirm the payment — a receipt will print automatically.</li>
      </ol>
      <div style="font-weight:800;font-size:12px;color:#ff6600;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;border-bottom:2px solid #ff6600;padding-bottom:4px;">📱 E-Wallet Payment</div>
      <ul style="padding-left:18px;line-height:2;color:#444;margin-bottom:8px;">
        <li>Press <strong>F3</strong> or click <strong>E-Wallet</strong> to open the modal.</li>
        <li>Select the provider (GCash, Maya, GrabPay, etc.).</li>
        <li>Click <strong>Show QR</strong> and let the customer scan.</li>
        <li>Enter the <strong>Reference No.</strong> from the customer's receipt.</li>
        <li>Click <strong>Confirm</strong> to finalize.</li>
      </ul>
    </div>
    <div style="background:#f5f5f5;border-top:1px solid #ddd;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;font-size:11px;color:#888;">
      <span><?= htmlspecialchars($businessName) ?> POS v1.0</span>
      <button onclick="closeHelp()" style="background:linear-gradient(180deg,#ff9900,#cc6600);color:white;border:none;border-radius:4px;padding:6px 20px;font-size:12px;font-weight:700;cursor:pointer;">Close</button>
    </div>
  </div>
</div>

<script>
// Common JavaScript functions
function updateClock() {
  document.getElementById('currentTime').textContent =
    new Date().toLocaleString('en-US',{timeZone:'Asia/Manila',weekday:'short',year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});
}
setInterval(updateClock,1000); updateClock();

function toggleSidebar() {
  const sb = document.getElementById('sidebar');
  sb.style.display = sb.style.display==='flex' ? 'none' : 'flex';
  document.getElementById('menuBtn').textContent = sb.style.display==='flex' ? '✖' : '☰';
}

function openDashLogin() {
  document.getElementById('dashIframe').src = 'login.php?redirect=dashboard.php&iframe=true';
  document.getElementById('dashModal').classList.add('show');
}
function closeDashLogin() {
  document.getElementById('dashModal').classList.remove('show');
  document.getElementById('dashIframe').src = '';
}
document.getElementById('dashModal').addEventListener('click',function(e){ if(e.target===this) closeDashLogin(); });
window.addEventListener('message',function(e){
  if(e.origin!==window.location.origin) return;
  if(e.data.action==='loginSuccess'){
    closeDashLogin();
    window.location.href = e.data.redirect+(e.data.redirect.includes('?')?'&':'?')+'loggedin=true';
  }
});

function showErr(msg) { document.getElementById('errMsg').textContent=msg; document.getElementById('errModal').classList.add('show'); }
function closeErr()   { document.getElementById('errModal').classList.remove('show'); }
function openHelp()   { document.getElementById('helpModal').classList.add('show'); }
function closeHelp()  { document.getElementById('helpModal').classList.remove('show'); }
document.getElementById('helpModal').addEventListener('click',function(e){ if(e.target===this) closeHelp(); });

// Cart protection functions
let pendingNavigation = null;
let warningDismissed = false;

function hasCartItems() {
  return typeof checkout !== 'undefined' && checkout.length > 0;
}

function updateCartProtection() {
  const hasItems = hasCartItems();
  const topIcons = document.querySelectorAll('.top-icon');
  const sidebarLinks = document.querySelectorAll('.sidebar a');
  const menuBtn = document.getElementById('menuBtn');
  
  topIcons.forEach(icon => {
    if (hasItems) {
      icon.classList.add('disabled');
      icon.setAttribute('data-original-title', icon.title || '');
      icon.title = 'Complete or void your order first';
    } else {
      icon.classList.remove('disabled');
      icon.title = icon.getAttribute('data-original-title') || '';
    }
  });
  
  sidebarLinks.forEach(link => {
    if (hasItems) {
      link.classList.add('disabled');
      link.setAttribute('data-original-href', link.getAttribute('href'));
      link.removeAttribute('href');
    } else {
      link.classList.remove('disabled');
      const origHref = link.getAttribute('data-original-href');
      if (origHref) link.setAttribute('href', origHref);
    }
  });
  
  if (menuBtn) {
    if (hasItems) {
      menuBtn.classList.add('disabled');
      menuBtn.title = 'Complete or void your order first';
    } else {
      menuBtn.classList.remove('disabled');
      menuBtn.title = '';
    }
  }
  
  const badge = document.getElementById('cartWarning');
  if (badge) {
    if (hasItems && !warningDismissed) {
      badge.style.display = 'block';
      clearTimeout(window._warningTimer);
      window._warningTimer = setTimeout(() => {
        badge.style.display = 'none';
        warningDismissed = true;
      }, 10000);
    } else if (!hasItems) {
      badge.style.display = 'none';
      warningDismissed = false;
    }
  }
  
  const connStatus = document.getElementById('connStatus');
  if (connStatus && hasItems) {
    if (!document.getElementById('cartStatusHint')) {
      const hint = document.createElement('span');
      hint.id = 'cartStatusHint';
      hint.style.color = '#ff8800';
      hint.style.fontWeight = '700';
      hint.innerHTML = '⚠️ Cart Active — Complete or Void First';
      connStatus.parentNode.insertBefore(hint, connStatus.nextSibling);
    }
  } else {
    const hint = document.getElementById('cartStatusHint');
    if (hint) hint.remove();
  }
}

function dismissWarning() {
  warningDismissed = true;
  const badge = document.getElementById('cartWarning');
  if (badge) badge.style.display = 'none';
  clearTimeout(window._warningTimer);
}

document.addEventListener('click', function(e) {
  let target = e.target;
  while (target) {
    if (target.classList.contains('disabled') && 
        (target.classList.contains('top-icon') || 
         target.classList.contains('menu-btn') ||
         target.tagName === 'A')) {
      e.preventDefault();
      e.stopPropagation();
      if (hasCartItems()) {
        showNavConfirmation(target);
      }
      return;
    }
    target = target.parentElement;
  }
});

function showNavConfirmation(clickedElement) {
  const modal = document.getElementById('navConfirmModal');
  document.getElementById('navCartCount').textContent = checkout.length;
  
  if (clickedElement.tagName === 'A') {
    pendingNavigation = clickedElement.getAttribute('data-original-href') || 
                       clickedElement.getAttribute('href');
  } else {
    const onclick = clickedElement.getAttribute('onclick');
    pendingNavigation = onclick;
  }
  
  modal.classList.add('show');
}

function cancelNavigation() {
  document.getElementById('navConfirmModal').classList.remove('show');
  pendingNavigation = null;
  warningDismissed = false;
  updateCartProtection();
}

function confirmNavigation() {
  document.getElementById('navConfirmModal').classList.remove('show');
  
  if (typeof clearCheckout === 'function') {
    clearCheckout();
  }
  
  setTimeout(() => {
    if (pendingNavigation) {
      if (pendingNavigation.includes('location.href') || 
          pendingNavigation.includes('onclick')) {
        const func = new Function(pendingNavigation);
        func();
      } else if (pendingNavigation.startsWith('http') || 
                 pendingNavigation.startsWith('/') || 
                 pendingNavigation.includes('.php')) {
        window.location.href = pendingNavigation;
      }
    }
    pendingNavigation = null;
  }, 100);
}

document.getElementById('sidebar').addEventListener('click', function(e) {
  const link = e.target.closest('a');
  if (link && hasCartItems() && link.classList.contains('disabled')) {
    e.preventDefault();
    showNavConfirmation(link);
  }
});

window.addEventListener('beforeunload', function(e) {
  if (hasCartItems()) {
    e.preventDefault();
    e.returnValue = 'You have items in your cart. Are you sure you want to leave?';
    return e.returnValue;
  }
});
</script>
</body>
</html>