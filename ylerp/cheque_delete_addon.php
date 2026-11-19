<?php
/**
 * cheque_delete_addon.php — adds a "Delete Cheque" button to the View cheque window of cheques.php
 * Put this ONE line in cheques.php, just before   include 'footer.php';
 *      <?php include 'cheque_delete_addon.php'; ?>
 * The delete itself is done by cheque_delete_action.php.
 */
$cdel_allowed = true;
if (function_exists('rbac_can') && !rbac_can('delete', 'cheques')) $cdel_allowed = false;
if ($cdel_allowed && function_exists('rbac_btn_can') && !rbac_btn_can('chq_delete', 'cheques')) $cdel_allowed = false;
if (!$cdel_allowed) return;
?>
<style>
#cdelModal{display:none;position:fixed;inset:0;z-index:10000001;background:rgba(0,0,0,.6);align-items:center;justify-content:center;padding:16px}
#cdelModal.open{display:flex}
#cdelModal .cd-box{background:#fff;border-radius:14px;width:100%;max-width:440px;box-shadow:0 24px 70px rgba(0,0,0,.4);overflow:hidden}
#cdelModal .cd-hd{background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;padding:14px 18px;font-weight:800;font-size:15px;display:flex;gap:8px;align-items:center}
#cdelModal .cd-bd{padding:18px;font-size:13px;color:#374151}
#cdelModal .cd-info{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 12px;margin-bottom:12px;line-height:1.6}
#cdelModal textarea{width:100%;min-height:70px;border:1.5px solid #d1d5db;border-radius:8px;padding:8px;font-size:13px;box-sizing:border-box}
#cdelModal .cd-err{color:#b91c1c;font-weight:700;margin-top:8px;display:none}
#cdelModal .cd-ft{display:flex;gap:10px;padding:12px 18px;background:#f9fafb;border-top:1px solid #e5e7eb}
#cdelModal .cd-ft button{flex:1;border:none;border-radius:8px;padding:10px;font-weight:700;cursor:pointer;font-size:13px}
.vbtn-cdel{background:#dc2626!important;color:#fff!important;border:none;border-radius:8px;padding:9px 16px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;justify-content:center}
.vbtn-cdel:hover{background:#b91c1c!important}
</style>
<div id="cdelModal">
  <div class="cd-box">
    <div class="cd-hd"><i class="fa-solid fa-trash"></i> Delete Cheque</div>
    <div class="cd-bd">
      <div class="cd-info" id="cdelInfo"></div>
      <label style="font-weight:700;display:block;margin-bottom:5px">Reason for delete <span style="color:#dc2626">*</span></label>
      <textarea id="cdelReason" placeholder="Why is this cheque deleted? (e.g. entered twice)"></textarea>
      <div class="cd-err" id="cdelErr"></div>
    </div>
    <div class="cd-ft">
      <button type="button" style="background:#e5e7eb;color:#374151" onclick="cdelClose()">Cancel</button>
      <button type="button" id="cdelGo" style="background:#dc2626;color:#fff" onclick="cdelDo()"><i class="fa-solid fa-trash"></i> Delete</button>
    </div>
  </div>
</div>
<script>
(function(){
  let cur = null;   // the cheque now open in the View window
  const orig = window.openViewModal;
  if (typeof orig === 'function') {
    window.openViewModal = function(id, chequeNo, tcode, custName, amt){
      cur = {id: id, no: chequeNo || '', cust: custName || '', amt: amt || ''};
      const r = orig.apply(this, arguments);
      addBtn();
      return r;
    };
  }
  function addBtn(){
    const foot = document.querySelector('#viewModal .viewmodal-foot');
    if (!foot || document.getElementById('cdelBtn')) return;
    const b = document.createElement('button');
    b.type = 'button'; b.id = 'cdelBtn'; b.className = 'vbtn vbtn-cdel';
    b.innerHTML = '<i class="fa-solid fa-trash"></i> Delete Cheque';
    b.onclick = cdelOpen;
    foot.insertBefore(b, foot.firstChild);
  }
  function esc(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  window.cdelOpen = function(){
    if (!cur || !cur.id) return;
    document.getElementById('cdelInfo').innerHTML = 'Cheque No: <b>' + esc(cur.no) + '</b><br>Customer: <b>' + esc(cur.cust || '—') + '</b><br>Amount: <b>Rs. ' + esc(cur.amt || '—') + '</b><br><span style="color:#b91c1c">This removes the cheque from the register. A copy is kept for the record.</span>';
    document.getElementById('cdelReason').value = '';
    document.getElementById('cdelErr').style.display = 'none';
    document.getElementById('cdelModal').classList.add('open');
    setTimeout(() => document.getElementById('cdelReason').focus(), 50);
  };
  window.cdelClose = function(){ document.getElementById('cdelModal').classList.remove('open'); };
  window.cdelDo = async function(){
    const reason = document.getElementById('cdelReason').value.trim();
    const err = document.getElementById('cdelErr');
    if (!reason) { err.textContent = 'Give a reason.'; err.style.display = 'block'; return; }
    const go = document.getElementById('cdelGo'); go.disabled = true; go.textContent = 'Deleting...';
    try {
      const fd = new FormData(); fd.append('cheque_id', cur.id); fd.append('reason', reason);
      const res = await fetch('cheque_delete_action.php', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}});
      const raw = await res.text(); let d;
      try { d = JSON.parse(raw); } catch(e) { throw new Error('Server error: ' + raw.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().slice(0,250)); }
      if (!d.success) throw new Error(d.message || 'Could not delete.');
      cdelClose();
      if (typeof closeViewModal === 'function') closeViewModal();
      if (typeof showToast === 'function') showToast('✓ ' + d.message, 'ok'); else alert(d.message);
      setTimeout(() => location.reload(), 900);
    } catch(e) {
      err.textContent = e.message; err.style.display = 'block';
    } finally { go.disabled = false; go.innerHTML = '<i class="fa-solid fa-trash"></i> Delete'; }
  };
  document.getElementById('cdelModal').addEventListener('click', function(e){ if (e.target === this) cdelClose(); });
})();
</script>
