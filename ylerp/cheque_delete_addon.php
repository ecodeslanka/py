<?php
/**
 * cheque_delete_addon.php — adds a "Delete Cheque" button to the View cheque window of cheques.php
 * Also adds a red Delete button next to Edit on each cheque row, and in the Verify window.
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
.cdel-row-btn{background:#dc2626;color:#fff;border:none;border-radius:6px;padding:4px 9px;font-size:11px;font-weight:700;cursor:pointer;margin-left:4px;display:inline-flex;align-items:center;gap:4px}
.cdel-row-btn:hover{background:#b91c1c}
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
  /* Delete Cheque: (1) red Delete button next to Edit on every row, (2) in the View window, (3) in the Verify window */
  const BLOCK = [];   // every status can be deleted
  let cur = null;
  function info(id){
    const tr = document.getElementById('row-' + id), d = tr ? tr.dataset : {};
    return {id: id, no: d.cheqno || d.chequeorig || '', cust: d.cust || '', amt: d.amt || '', status: (d.status || '').toLowerCase()};
  }
  function idFrom(onclick, fn){ const m = String(onclick || '').match(new RegExp(fn + '\\(\\s*[\'"]?(\\d+)')); return m ? m[1] : null; }
  /* remember which cheque a window was opened for (works whatever way the page opens it) */
  document.addEventListener('click', function(e){
    const b = e.target.closest('[onclick]'); if (!b) return;
    const oc = b.getAttribute('onclick');
    const id = idFrom(oc, 'openViewModal') || idFrom(oc, 'openVerifyModal');
    if (id) { cur = info(id); setTimeout(addModalBtns, 50); setTimeout(addModalBtns, 600); }
  }, true);
  ['openViewModal','openVerifyModal'].forEach(fn => {
    const orig = window[fn];
    if (typeof orig !== 'function') return;
    window[fn] = function(id){ cur = info(id); const r = orig.apply(this, arguments); setTimeout(addModalBtns, 50); setTimeout(addModalBtns, 600); return r; };
  });
  function mkBtn(small){
    const b = document.createElement('button');
    b.type = 'button'; b.className = small ? 'cdel-row-btn' : 'vbtn vbtn-cdel cdel-modal-btn';
    b.innerHTML = '<i class="fa-solid fa-trash"></i>' + (small ? ' Delete' : ' Delete Cheque');
    return b;
  }
  function addModalBtns(){
    if (!cur) return;
    const feet = [];
    const vf = document.querySelector('#viewModal .viewmodal-foot'); if (vf) feet.push(vf);
    const vc = document.querySelector('#verifyModal .vbtn-cancel'); if (vc && vc.parentElement) feet.push(vc.parentElement);
    feet.forEach(f => {
      let b = f.querySelector('.cdel-modal-btn');
      if (!b) { b = mkBtn(false); b.onclick = () => cdelOpen(cur); f.insertBefore(b, f.firstChild); }
      b.style.display = BLOCK.includes(cur.status) ? 'none' : '';
    });
  }
  function addRowBtns(){
    document.querySelectorAll('tr[id^="row-"]').forEach(tr => {
      if (tr.querySelector('.cdel-row-btn')) return;
      const id = tr.id.slice(4); if (!/^\d+$/.test(id)) return;
      if (BLOCK.includes((tr.dataset.status || '').toLowerCase())) return;
      const anchor = tr.querySelector('.edit-row-btn, .verify-btn, .view-verified-btn');
      if (!anchor) return;
      const b = mkBtn(true); b.title = 'Delete cheque';
      b.onclick = (e) => { e.stopPropagation(); cdelOpen(info(id)); };
      anchor.parentElement.appendChild(b);
    });
  }
  let t = null;
  new MutationObserver(() => { clearTimeout(t); t = setTimeout(addRowBtns, 120); }).observe(document.body, {childList:true, subtree:true});
  document.addEventListener('DOMContentLoaded', addRowBtns); setTimeout(addRowBtns, 300); setTimeout(addRowBtns, 1500);

  function esc(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  window.cdelOpen = function(c){
    if (c) cur = c;
    if (!cur || !cur.id) return;
    document.getElementById('cdelInfo').innerHTML = 'Cheque No: <b>' + esc(cur.no || ('#' + cur.id)) + '</b><br>Customer: <b>' + esc(cur.cust || '—') + '</b><br>Amount: <b>Rs. ' + esc(cur.amt || '—') + '</b><br>Status: <b>' + esc(cur.status || '—') + '</b>' + (['deposited','cleared','realized','returned'].includes(cur.status) ? '<br><b style="color:#b45309">⚠ This cheque is ' + esc(cur.status) + ' — check the bank / return records after deleting.</b>' : '') + '<br><span style="color:#b91c1c">This removes the cheque from the register. A copy is kept for the record.</span>';
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
      if (typeof closeViewModal === 'function') try { closeViewModal(); } catch(e) {}
      if (typeof closeVerifyModal === 'function') try { closeVerifyModal(); } catch(e) {}
      const tr = document.getElementById('row-' + cur.id); if (tr) tr.remove();
      if (typeof showToast === 'function') showToast('✓ ' + d.message, 'ok'); else alert(d.message);
    } catch(e) {
      err.textContent = e.message; err.style.display = 'block';
    } finally { go.disabled = false; go.innerHTML = '<i class="fa-solid fa-trash"></i> Delete'; }
  };
  document.getElementById('cdelModal').addEventListener('click', function(e){ if (e.target === this) cdelClose(); });
})();
</script>
