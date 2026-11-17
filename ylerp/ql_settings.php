<?php
/**
 * ql_settings.php — Master → Quick Shortcuts Settings
 *   • Quick shortcuts on / off for every user (system_settings 'ql_enabled').
 *   • Company shortcuts: shortcuts every user gets automatically (table company_quick_links). Each user still sees only
 *     the ones their role may open, and can add their own after them.
 */
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/yms_quicklinks.php';
ql_table($conn);
$canEdit = function_exists('rbac_can') ? rbac_can('edit') : true;
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!$canEdit) $err = 'Your role cannot change these settings (Edit right on this page is needed).';
    else {
        $u = mysqli_real_escape_string($conn, $_SESSION['username'] ?? ($_SESSION['user_name'] ?? 'system'));
        $v = empty($_POST['enabled']) ? '0' : '1';
        @mysqli_query($conn, "INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES ('ql_enabled', '$v', '$u') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)");
        $msg = $v === '1' ? 'Quick shortcuts are ON for every user.' : 'Quick shortcuts are switched OFF for every user.';
    }
}
$on = ql_enabled($conn); $company = ql_company($conn);
$nUsers = 0; $nLinks = 0; $r = @mysqli_query($conn, "SELECT COUNT(DISTINCT user_id), COUNT(*) FROM user_quick_links"); if ($r && ($x = mysqli_fetch_row($r))) { $nUsers = (int)$x[0]; $nLinks = (int)$x[1]; }
include 'header.php';
?>
<style>
.qls{max-width:1000px;font-family:'Inter',system-ui,sans-serif;color:#0f172a;padding:4px 2px 20px}
.qls .hero{display:flex;gap:16px;align-items:center;border-radius:20px;padding:20px 24px;color:#fff;background:linear-gradient(120deg,#78350f,#d97706 55%,#f59e0b);box-shadow:0 16px 36px -18px rgba(180,83,9,.8);margin-bottom:16px}
.qls .hero .ic{width:64px;height:64px;border-radius:18px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:30px}
.qls .hero h2{margin:0;font-size:22px}.qls .hero p{margin:5px 0 0;opacity:.92;font-size:13px}
.qls .card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:14px 18px;box-shadow:0 6px 18px -12px rgba(15,23,42,.25);margin-bottom:14px}
.qls .row{display:flex;gap:16px;align-items:center}.qls .row .tx{flex:1}.qls .row b{display:block;font-size:14.5px}.qls .row small{display:block;color:#64748b;font-size:12.5px;margin-top:3px}
.qls .sw{position:relative;width:50px;height:28px;flex:0 0 auto}.qls .sw input{opacity:0;width:0;height:0}.qls .sw span{position:absolute;inset:0;border-radius:99px;background:#cbd5e1;cursor:pointer;transition:.2s}
.qls .sw span:before{content:'';position:absolute;left:3px;top:3px;width:22px;height:22px;border-radius:50%;background:#fff;box-shadow:0 2px 5px rgba(0,0,0,.2);transition:.2s}.qls .sw input:checked+span{background:#d97706}.qls .sw input:checked+span:before{transform:translateX(22px)}
.qls .btn{border:none;border-radius:11px;padding:10px 18px;font-weight:800;font-size:13px;color:#fff;background:linear-gradient(135deg,#f59e0b,#b45309);cursor:pointer}.qls .btn:disabled{opacity:.5;cursor:not-allowed}
.qls .ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:12px;padding:10px 14px;margin-bottom:12px;font-size:13px;font-weight:600}.qls .no{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px;padding:10px 14px;margin-bottom:12px;font-size:13px;font-weight:600}
.qls .two{display:grid;grid-template-columns:1.2fr 1fr;gap:14px}@media(max-width:800px){.qls .two{grid-template-columns:1fr}}
.qls input[type=search]{width:100%;border:1.5px solid #e2e8f0;border-radius:11px;padding:9px 12px;font:500 13px 'Inter',sans-serif;outline:none;margin-bottom:8px}
.qls .lst{max-height:430px;overflow:auto}.qls .g{font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;margin:10px 2px 4px}
.qls .pr{display:flex;align-items:center;gap:9px;padding:7px 8px;border-radius:10px;font-size:13px;cursor:pointer}.qls .pr:hover{background:#f8fafc}.qls .pr span{flex:1;min-width:0}.qls .pr small{display:block;color:#94a3b8;font-size:11px}
.qls .fi{width:26px;height:26px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;flex:0 0 auto}
.qls .pr .ad{border:1.5px solid #c4b5fd;background:#fff;color:#6d28d9;border-radius:8px;padding:3px 9px;font:800 11.5px 'Inter',sans-serif}.qls .pr.on .ad{background:#7c3aed;border-color:#7c3aed;color:#fff}
.qls .co{display:flex;flex-direction:column;gap:6px;min-height:80px}.qls .co .mi{display:flex;align-items:center;gap:9px;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:11px;padding:7px 9px;font-size:12.5px;font-weight:700;cursor:grab}
.qls .co .mi span{flex:1}.qls .co .mi button{border:none;background:#fee2e2;color:#b91c1c;border-radius:7px;width:24px;height:24px;cursor:pointer}.qls .co .mi.drag{opacity:.35}.qls .co .mi.over{outline:2px dashed #7c3aed}
.qls .st{font-size:12.5px;color:#64748b}
</style>
<div class="qls">
  <div class="hero"><div class="ic">⚡</div><div><h2>Quick Shortcuts Settings</h2><p>Turn the ⚡ Quick shortcuts on for every user, and choose the company shortcuts everyone gets.</p></div></div>
  <?php if ($msg): ?><div class="ok">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?><?php if ($err): ?><div class="no">⛔ <?= htmlspecialchars($err) ?></div><?php endif; ?>
  <form method="post" class="card"><input type="hidden" name="action" value="save">
    <div class="row"><div class="tx"><b>Quick shortcuts for every user</b><small>The ⚡ Quick button in the header and "My quick shortcuts" on the home page. Now <?= $nUsers ?> user(s) have <?= $nLinks ?> own shortcut(s).</small></div>
      <label class="sw"><input type="checkbox" name="enabled" value="1" <?= $on ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?> onchange="this.form.submit()"><span></span></label></div>
  </form>
  <div class="card"><div class="row" style="margin-bottom:10px"><div class="tx"><b>🏢 Company shortcuts — every user gets these</b><small>Shown first in every user's ⚡ Quick and on the home page (each user only sees the pages their role may open). Users cannot remove them; they can add their own after them.</small></div></div>
    <div class="two"><div><input type="search" id="qsQ" placeholder="Search a page or menu…"><div class="lst" id="qsL"></div></div>
      <div><div style="font-weight:800;font-size:13px;color:#5b21b6;margin-bottom:8px">Company shortcuts <span id="qsN" style="font-weight:600;color:#7c3aed"></span> <small style="color:#94a3b8;font-weight:500">· drag to order</small></div><div class="co" id="qsC"></div>
        <div style="display:flex;gap:10px;align-items:center;margin-top:12px"><button class="btn" type="button" id="qsSave" <?= $canEdit ? '' : 'disabled' ?>>💾 Save company shortcuts</button><span class="st" id="qsMsg"></span></div></div></div></div>
</div>
<script>
(function(){
  var $=function(i){return document.getElementById(i);}, C=<?= json_encode(array_values($company)) ?>, M={};
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
  function col(k){ return window.QL&&QL.col?QL.col(k):'#7c3aed'; }
  function lst(){ var q=$('qsQ').value.toLowerCase().trim(), g={}; Object.keys(M).forEach(function(k){ var x=M[k]; if(q&&(x.l+' '+x.w+' '+k).toLowerCase().indexOf(q)<0) return; var grp=x.menu?'Whole menus':(x.w.split(' › ')[0]||'Pages'); (g[grp]=g[grp]||[]).push(x); });
    var h=''; Object.keys(g).sort(function(a,b){ return a==='Whole menus'?-1:(b==='Whole menus'?1:a.localeCompare(b)); }).forEach(function(grp){ h+='<div class="g">'+esc(grp)+'</div>'+g[grp].map(function(x){ var on=C.indexOf(x.k)>=0; return '<div class="pr'+(on?' on':'')+'" data-k="'+esc(x.k)+'"><i class="fi '+esc(x.i)+'" style="background:'+col(x.k)+'"></i><span>'+esc(x.l)+'<small>'+esc(x.w)+'</small></span><button type="button" class="ad">'+(on?'✓ Added':'+ Add')+'</button></div>'; }).join(''); });
    $('qsL').innerHTML=h||'<div class="st">No page matches.</div>'; }
  function co(){ $('qsN').textContent='('+C.length+')'; $('qsC').innerHTML=C.map(function(k){ var x=M[k]||{l:k,i:'fa-solid fa-file',w:''}; return '<div class="mi" draggable="true" data-k="'+esc(k)+'"><i class="fa-solid fa-grip-vertical" style="color:#c4b5fd"></i><i class="fi '+esc(x.i)+'" style="background:'+col(k)+'"></i><span>'+esc(x.l)+'</span><button type="button" data-rm="'+esc(k)+'">×</button></div>'; }).join('')||'<div class="st">None yet — add pages from the left.</div>'; }
  function all(){ lst(); co(); }
  $('qsQ').addEventListener('input',lst);
  $('qsL').addEventListener('click',function(e){ var r=e.target.closest('[data-k]'); if(!r) return; var k=r.dataset.k, i=C.indexOf(k); if(i>=0) C.splice(i,1); else if(C.length<40) C.push(k); all(); $('qsMsg').textContent='Not saved yet'; });
  $('qsC').addEventListener('click',function(e){ var b=e.target.closest('[data-rm]'); if(!b) return; C.splice(C.indexOf(b.dataset.rm),1); all(); $('qsMsg').textContent='Not saved yet'; });
  var dk=null; $('qsC').addEventListener('dragstart',function(e){ var t=e.target.closest('.mi'); if(!t) return; dk=t.dataset.k; t.classList.add('drag'); e.stopPropagation(); try{ e.dataTransfer.setData('text/plain',dk); }catch(_){} });
  $('qsC').addEventListener('dragover',function(e){ if(!dk) return; e.preventDefault(); e.stopPropagation(); document.querySelectorAll('#qsC .over').forEach(function(x){x.classList.remove('over');}); var t=e.target.closest('.mi'); if(t) t.classList.add('over'); });
  $('qsC').addEventListener('drop',function(e){ if(!dk) return; e.preventDefault(); e.stopPropagation(); var t=e.target.closest('.mi'); var i=C.indexOf(dk); C.splice(i,1); var j=t?C.indexOf(t.dataset.k):C.length; if(j<0) j=C.length; C.splice(j,0,dk); dk=null; co(); $('qsMsg').textContent='Not saved yet'; });
  $('qsC').addEventListener('dragend',function(){ dk=null; document.querySelectorAll('#qsC .drag,#qsC .over').forEach(function(x){x.classList.remove('drag','over');}); });
  $('qsSave').addEventListener('click',function(){ var f=new FormData(); f.append('items',JSON.stringify(C)); $('qsMsg').textContent='Saving…';
    fetch('yms_quicklinks.php?ql=company',{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){ if(!d.success) throw new Error(d.message); $('qsMsg').style.color='#15803d'; $('qsMsg').textContent='✓ Saved — every user sees these on the next page they open.'; if(window.QL){ QL.company=d.items; QL.render(); } })
      .catch(function(e){ $('qsMsg').style.color='#b91c1c'; $('qsMsg').textContent=e.message; }); });
  function start(){ M=(window.QL&&QL.pages)?QL.pages():{}; all(); }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',function(){ setTimeout(start,0); }); else setTimeout(start,0);
})();
</script>
<?php include 'footer.php'; ?>
