<?php
/**
 * yms_quicklinks.php — "Quick shortcuts": each user's own shortcuts to menus and sub menus.
 *   • Header: the ⚡ Quick button opens the user's shortcuts on every page.
 *   • Home page (index.php): the shortcut tiles at the top.
 *   • Add: drag a sidebar page (sub menu) or a whole main menu onto the ⚡ button or the home tiles, click the ☆ next to a
 *     sidebar item, or use "Add / edit" (search every page). Drag shortcuts to change their order; × removes one.
 *   • Saved per user in the table user_quick_links. Names, icons and permissions come from the user's own sidebar
 *     (already filtered by role), so a shortcut to a page the user may no longer open simply is not shown.
 *   • Master → Quick Shortcuts Settings (ql_settings.php): switch the feature on / off for every user, and the company
 *     shortcuts every user gets (table company_quick_links; on/off in system_settings 'ql_enabled').
 * Called directly (yms_quicklinks.php?ql=…) it reads / saves the list.
 */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'yms_quicklinks.php') {
    include 'config.php';
    if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
    header('Content-Type: application/json; charset=utf-8');
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!$uid) { echo json_encode(['success' => false, 'message' => 'Please log in.']); exit; }
    ql_table($conn);
    if (($_GET['ql'] ?? '') === 'company' && $_SERVER['REQUEST_METHOD'] === 'POST') {   // Quick Shortcuts Settings: the shortcuts every user gets
        if (function_exists('rbac_can') && !rbac_can('edit', 'ql_settings')) { echo json_encode(['success' => false, 'message' => 'Your role cannot change the company shortcuts (Edit right on Quick Shortcuts Settings).']); exit; }
        $clean = ql_clean(json_decode($_POST['items'] ?? '[]', true));
        mysqli_begin_transaction($conn); @mysqli_query($conn, "DELETE FROM company_quick_links");
        foreach ($clean as $i => $k) { $e = mysqli_real_escape_string($conn, $k); @mysqli_query($conn, "INSERT INTO company_quick_links (page_key, sort_no) VALUES ('$e', $i)"); }
        mysqli_commit($conn);
        echo json_encode(['success' => true, 'items' => $clean]); exit;
    }
    if (($_GET['ql'] ?? '') === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $items = json_decode($_POST['items'] ?? '[]', true); if (!is_array($items)) $items = [];
        $clean = [];
        foreach ($items as $k) { $k = (string)$k; if (preg_match('/^(?:[a-z0-9_\-]{1,100}|menu:[^\'"<>\\\\]{1,90})$/i', $k) && !in_array($k, $clean, true)) $clean[] = $k; if (count($clean) >= 40) break; }
        mysqli_begin_transaction($conn);
        @mysqli_query($conn, "DELETE FROM user_quick_links WHERE user_id = $uid");
        foreach ($clean as $i => $k) { $e = mysqli_real_escape_string($conn, $k); @mysqli_query($conn, "INSERT INTO user_quick_links (user_id, page_key, sort_no) VALUES ($uid, '$e', $i)"); }
        mysqli_commit($conn);
        echo json_encode(['success' => true, 'items' => $clean]); exit;
    }
    echo json_encode(['success' => true, 'items' => ql_items($conn, $uid)]); exit;
}

function ql_clean($items) {
    $clean = []; if (!is_array($items)) return $clean;
    foreach ($items as $k) { $k = (string)$k; if (preg_match('/^(?:[a-z0-9_\-]{1,100}|menu:[^\'"<>\\\\]{1,90})$/i', $k) && !in_array($k, $clean, true)) $clean[] = $k; if (count($clean) >= 40) break; }
    return $clean;
}
function ql_enabled($conn) {
    if (!$conn) return true;
    $r = @mysqli_query($conn, "SELECT setting_value FROM system_settings WHERE setting_key = 'ql_enabled'");
    return !($r && ($x = mysqli_fetch_row($r)) && $x[0] === '0');
}
function ql_company($conn) {
    if (!$conn) return []; ql_table($conn); $o = [];
    $r = @mysqli_query($conn, "SELECT page_key FROM company_quick_links ORDER BY sort_no");
    while ($r && ($x = mysqli_fetch_row($r))) $o[] = $x[0];
    return $o;
}
function ql_table($conn) {
    static $done = false; if ($done || !$conn) return; $done = true;
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS company_quick_links (page_key VARCHAR(120) NOT NULL PRIMARY KEY, sort_no INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS system_settings (setting_key VARCHAR(80) NOT NULL PRIMARY KEY, setting_value VARCHAR(255) NULL, updated_by VARCHAR(100) NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS user_quick_links (user_id INT NOT NULL, page_key VARCHAR(120) NOT NULL, sort_no INT NOT NULL DEFAULT 0,
                          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, page_key), KEY k_user (user_id, sort_no)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function ql_items($conn, $uid) {
    if (!$conn || !$uid) return [];
    ql_table($conn); $o = [];
    $r = @mysqli_query($conn, "SELECT page_key FROM user_quick_links WHERE user_id = " . (int)$uid . " ORDER BY sort_no, created_at");
    while ($r && ($x = mysqli_fetch_row($r))) $o[] = $x[0];
    return $o;
}

/** header: the ⚡ Quick button + its drop-down, the picker window and the script (once per page) */
function ql_header_button() {
    global $conn;
    if (empty($_SESSION['user_id'])) return;
    $GLOBALS['QL_ON'] = ql_enabled($conn ?? null);
    $settingsPage = basename($_SERVER['PHP_SELF'] ?? '') === 'ql_settings.php';
    if (!$GLOBALS['QL_ON'] && !$settingsPage) return;                     // switched off for every user (the settings page still needs the script)
    $items = ql_items($conn ?? null, (int)$_SESSION['user_id']); $company = ql_company($conn ?? null);
    ?>
<style>
.ql-wrap{position:relative}
.ql-btn{position:relative;display:flex;align-items:center;gap:7px;height:40px;padding:0 13px;border:1px solid #e5e7eb;background:#fff;border-radius:10px;cursor:pointer;font:700 13px 'Inter',system-ui,sans-serif;color:#111827;transition:all .15s}
.ql-btn:hover{background:#f9fafb}.ql-btn i{color:#f59e0b}.ql-btn .ql-n{min-width:19px;height:19px;border-radius:99px;background:#fef3c7;color:#92400e;font-size:11px;display:inline-flex;align-items:center;justify-content:center;padding:0 5px}
.ql-btn.drop{background:#fffbeb;border-color:#f59e0b;box-shadow:0 0 0 4px rgba(245,158,11,.25);transform:scale(1.06)}
.ql-drop{display:none;position:absolute;right:0;top:48px;width:400px;max-width:94vw;background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 18px 40px rgba(0,0,0,.16);z-index:1300;overflow:hidden;font-family:'Inter',system-ui,sans-serif}
.ql-drop.open{display:block;animation:qlIn .16s ease-out}@keyframes qlIn{from{opacity:0;transform:translateY(-6px)}}
.ql-top{display:flex;align-items:center;gap:8px;padding:12px 14px;border-bottom:1px solid #f1f5f9;font-weight:800;font-size:14px;color:#111827}.ql-top i{color:#f59e0b}.ql-top a{margin-left:auto;font-size:12px;color:#4f46e5;font-weight:700;cursor:pointer;text-decoration:none}
.ql-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:12px;max-height:360px;overflow:auto}
.ql-it{position:relative;display:flex;flex-direction:column;align-items:center;gap:6px;padding:12px 6px 10px;border-radius:12px;border:1px solid #f1f5f9;background:#fafbff;text-decoration:none;color:#1e293b;font-size:11.5px;font-weight:700;text-align:center;line-height:1.25;cursor:pointer;transition:all .15s}
.ql-it:hover{border-color:#c7d2fe;background:#eef2ff;transform:translateY(-2px)}
.ql-it .qi{width:36px;height:36px;border-radius:11px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px}
.ql-it .qx{position:absolute;right:4px;top:4px;width:18px;height:18px;border-radius:50%;border:none;background:#fee2e2;color:#b91c1c;font-size:11px;line-height:18px;cursor:pointer;display:none;padding:0}.ql-it:hover .qx{display:block}
.ql-it .qco{position:absolute;right:4px;top:3px;font-size:10px}.ql-it.co{background:#f5f3ff;border-color:#ddd6fe}
.ql-it.dragging{opacity:.35}.ql-it.over{outline:2px dashed #f59e0b;outline-offset:2px}
.ql-empty{grid-column:1/-1;text-align:center;color:#64748b;font-size:12.5px;padding:16px 8px;line-height:1.6}
.ql-hint{padding:9px 14px;border-top:1px solid #f1f5f9;font-size:11.5px;color:#64748b;background:#fafafa}
.ql-star{position:absolute;right:30px;top:50%;transform:translateY(-50%);width:22px;height:22px;border-radius:7px;border:none;background:transparent;color:#cbd5e1;font-size:13px;cursor:pointer;display:none;align-items:center;justify-content:center;z-index:2;padding:0}
.ql-star.on{color:#f59e0b;display:flex}a:hover>.ql-star,.menu-item:hover>.ql-star,.ql-star:hover{display:flex}.ql-star:hover{background:rgba(245,158,11,.15);color:#f59e0b}
.ql-dz{position:fixed;left:50%;top:70px;transform:translateX(-50%);z-index:2147482000;background:#111827;color:#fff;border-radius:99px;padding:10px 18px;font:700 13px 'Inter',system-ui,sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.3);display:none}
.ql-dz.show{display:block;animation:qlIn .15s}
.ql-pk-bg{position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:2147482500;display:none;align-items:center;justify-content:center;padding:14px;font-family:'Inter',system-ui,sans-serif}.ql-pk-bg.open{display:flex}
.ql-pk{width:min(880px,100%);height:min(620px,92vh);background:#fff;border-radius:18px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 30px 70px -20px rgba(15,23,42,.5)}
.ql-pk-hd{display:flex;align-items:center;gap:10px;padding:14px 18px;color:#fff;background:linear-gradient(120deg,#78350f,#d97706 55%,#f59e0b)}.ql-pk-hd b{font-size:16px}.ql-pk-hd small{display:block;font-size:12px;opacity:.9}.ql-pk-hd button{margin-left:auto;width:34px;height:34px;border:none;border-radius:10px;background:rgba(255,255,255,.2);color:#fff;font-size:16px;cursor:pointer}
.ql-pk-bd{flex:1;display:grid;grid-template-columns:1.2fr 1fr;min-height:0}
.ql-pk-l,.ql-pk-r{display:flex;flex-direction:column;min-height:0;padding:12px 14px}.ql-pk-r{background:#fffbeb;border-left:1px solid #fde68a}
.ql-pk-l input{border:1.5px solid #e2e8f0;border-radius:11px;padding:9px 12px;font:500 13px 'Inter',system-ui,sans-serif;outline:none;margin-bottom:8px}.ql-pk-l input:focus{border-color:#f59e0b}
.ql-pk-list{flex:1;overflow:auto}.ql-pk-g{font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.4px;margin:10px 2px 4px}
.ql-pk-row{display:flex;align-items:center;gap:9px;padding:7px 8px;border-radius:10px;font-size:13px;cursor:pointer}.ql-pk-row:hover{background:#f8fafc}
.ql-pk-row i.fi{width:26px;height:26px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;flex:0 0 auto}
.ql-pk-row span{flex:1;min-width:0}.ql-pk-row small{display:block;color:#94a3b8;font-size:11px}.ql-pk-row .ad{border:1.5px solid #fcd34d;background:#fff;color:#92400e;border-radius:8px;padding:3px 9px;font:800 11.5px 'Inter',sans-serif;cursor:pointer}
.ql-pk-row.on .ad{background:#f59e0b;border-color:#f59e0b;color:#fff}
.ql-pk-r h4{margin:0 0 8px;font-size:13.5px;color:#78350f;display:flex;align-items:center;gap:6px}
.ql-mine{flex:1;overflow:auto;display:flex;flex-direction:column;gap:6px}
.ql-mine .mi{display:flex;align-items:center;gap:9px;background:#fff;border:1px solid #fde68a;border-radius:11px;padding:7px 9px;font-size:12.5px;font-weight:700;cursor:grab}
.ql-mine .mi i.fi{width:26px;height:26px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px}.ql-mine .mi span{flex:1;min-width:0}.ql-mine .mi button{border:none;background:#fee2e2;color:#b91c1c;border-radius:7px;width:24px;height:24px;cursor:pointer}
.ql-mine .mi.dragging{opacity:.35}.ql-mine .mi.over{outline:2px dashed #f59e0b}
.ql-pop{position:fixed;z-index:2147482600;background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 16px 36px rgba(0,0,0,.18);padding:8px;min-width:240px;max-height:60vh;overflow:auto;font-family:'Inter',system-ui,sans-serif}
.ql-pop b{display:block;font-size:12px;color:#94a3b8;text-transform:uppercase;padding:4px 8px}.ql-pop a{display:flex;gap:9px;align-items:center;padding:8px 9px;border-radius:9px;text-decoration:none;color:#1e293b;font-size:13px;font-weight:600}.ql-pop a:hover{background:#eef2ff}
@media (max-width:700px){.ql-pk-bd{grid-template-columns:1fr}.ql-pk-r{border-left:none;border-top:1px solid #fde68a;max-height:40%}.ql-btn .ql-t{display:none}}
</style>
<div class="ql-wrap" id="qlWrap" data-perm-skip<?= $GLOBALS['QL_ON'] ? '' : ' style="display:none"' ?>>
  <button class="ql-btn" id="qlBtn" type="button" title="My quick shortcuts — drag a menu here to add it"><i class="fa-solid fa-bolt"></i><span class="ql-t">Quick</span><span class="ql-n" id="qlN"><?= count(array_unique(array_merge($company, $items))) ?></span></button>
  <div class="ql-drop" id="qlDrop"><div class="ql-top"><i class="fa-solid fa-bolt"></i> My quick shortcuts<a id="qlEdit"><i class="fa-solid fa-pen"></i> Add / edit</a></div>
    <div class="ql-grid" id="qlGrid"></div><div class="ql-hint">💡 Drag any menu or sub menu from the left onto <b>⚡ Quick</b>, or click its ☆.</div></div>
</div>
<div class="ql-dz" id="qlDz">⚡ Drop on <b>Quick</b> (top) or on the home shortcuts to add it</div>
<div class="ql-pk-bg" id="qlPk" data-perm-skip><div class="ql-pk">
  <div class="ql-pk-hd"><i class="fa-solid fa-bolt" style="font-size:20px"></i><div><b>My quick shortcuts</b><small>Add pages or whole menus, drag to change the order</small></div><button type="button" id="qlPkX">✕</button></div>
  <div class="ql-pk-bd"><div class="ql-pk-l"><input id="qlPkQ" type="search" placeholder="Search a page or menu…"><div class="ql-pk-list" id="qlPkList"></div></div>
    <div class="ql-pk-r"><h4><i class="fa-solid fa-star" style="color:#f59e0b"></i> My shortcuts <small id="qlPkN" style="font-weight:600;color:#a16207"></small></h4><div class="ql-mine" id="qlMine"></div></div></div></div></div>
<script>
(function(){
  if(window.QL) return; var Q=window.QL={items:<?= json_encode(array_values($items)) ?>,company:<?= json_encode(array_values($company)) ?>,map:null};
  var $=function(i){return document.getElementById(i);}, COL=['#4f46e5','#0ea5e9','#10b981','#f59e0b','#ef4444','#8b5cf6','#14b8a6','#ec4899','#6366f1','#0284c7'];
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
  function col(k){ var h=0; for(var i=0;i<k.length;i++) h=(h*31+k.charCodeAt(i))>>>0; return COL[h%COL.length]; }
  /* everything the user may open, read from the sidebar (already filtered by role) */
  function txt(el){ return el?String(el.textContent||'').replace(/\s+/g,' ').trim():''; }
  function iconOf(el){ var i=el&&el.querySelector('i[class*="fa-"]:not(.submenu-toggle)'); if(i) return i.className.replace(/\b(menu-icon|icon-\w+)\b/g,'').trim(); return el&&el.querySelector('img')?'fa-solid fa-layer-group':'fa-solid fa-file'; }
  function mlabel(mi){ return mi.getAttribute('data-tooltip')||txt(mi.querySelector('.menu-item-content span:not(.menu-icon)'))||txt(mi.querySelector('span:not(.menu-icon)')); }
  /* top-level menu headings that have a list of pages under them (expandable menus and the always-open report headings) */
  function heads(sb){ return [].filter.call(sb.querySelectorAll('.menu-item'),function(mi){ var n=mi.nextElementSibling; return !mi.closest('.submenu')&&n&&n.classList.contains('submenu'); }); }
  function build(){ if(Q.map) return Q.map; var M={}, sb=document.querySelector('#sidebar,.sidebar'); if(!sb) return Q.map=M;
    sb.querySelectorAll('a[href]').forEach(function(a){ var h=(a.getAttribute('href')||'').split('?')[0]; var m=h.match(/^([a-z0-9_\-]+)\.php$/i); if(!m) return; var p=m[1].toLowerCase(); if(p==='logout'||M[p]) return;
      var sub=a.closest('.submenu'), path=[]; var s2=sub; while(s2){ var hd=s2.previousElementSibling; if(hd&&hd.classList.contains('menu-item')) path.unshift(mlabel(hd)); s2=s2.parentElement&&s2.parentElement.closest('.submenu'); }
      var sec=a.closest('.menu-section'); var st=sec&&sec.querySelector('.menu-title span'); if(st&&txt(st)&&path[0]!==txt(st)) path.unshift(txt(st));
      M[p]={k:p,l:txt(a.querySelector('span'))||txt(a),i:iconOf(a),w:path.filter(Boolean).join(' › '),u:a.getAttribute('href')}; });
    heads(sb).forEach(function(mi){ var l=mlabel(mi); var subm=mi.nextElementSibling; if(!l||!subm||!subm.classList.contains('submenu')) return;
      var pages=[]; subm.querySelectorAll('a[href]').forEach(function(a){ var m=(a.getAttribute('href')||'').match(/^([a-z0-9_\-]+)\.php/i); if(m&&M[m[1].toLowerCase()]) pages.push(m[1].toLowerCase()); });
      if(pages.length) M['menu:'+l]={k:'menu:'+l,l:l,i:iconOf(mi),w:'Menu · '+pages.length+' pages',menu:pages}; });
    return Q.map=M; }
  function tile(x,cls){ var co=x.co; return '<'+(x.menu?'div':'a href="'+esc(x.u)+'"')+' class="'+cls+(co?' co':'')+'"'+(co?'':' draggable="true"')+' data-k="'+esc(x.k)+'" title="'+esc(x.l+(x.w?' — '+x.w:'')+(co?' · company shortcut (set by the administrator)':''))+'"><span class="qi" style="background:'+col(x.k)+'"><i class="'+esc(x.i)+'"></i></span>'+esc(x.l)+(x.menu?' ▾':'')+(co?'<span class="qco" title="Company shortcut">🏢</span>':'<button type="button" class="qx" data-rm="'+esc(x.k)+'" title="Remove">×</button>')+'</'+(x.menu?'div':'a')+'>'; }
  function cmp(){ var M=build(); return Q.company.map(function(k){ return M[k]?Object.assign({},M[k],{co:1}):null; }).filter(Boolean); }
  function mine(){ var M=build(); return Q.items.filter(function(k){ return Q.company.indexOf(k)<0; }).map(function(k){return M[k];}).filter(Boolean); }
  Q.pages=function(){ return build(); }; Q.col=function(k){ return col(k); };
  function render(){ var L=cmp().concat(mine()); $('qlN').textContent=L.length;
    $('qlGrid').innerHTML=L.length?L.map(function(x){return tile(x,'ql-it');}).join(''):'<div class="ql-empty">No shortcuts yet.<br>Drag a menu from the left onto <b>⚡ Quick</b>,<br>click a ☆ in the menu, or use <b>Add / edit</b>.</div>';
    var H=$('qlHome'); if(H){ H.innerHTML='<div class="qlh-grid">'+L.map(function(x){return tile(x,'ql-it qlh');}).join('')+'<div class="ql-it qlh qlh-add" id="qlHAdd" title="Add a shortcut"><span class="qi" style="background:#f1f5f9;color:#94a3b8"><i class="fa-solid fa-plus"></i></span>Add shortcut</div></div>'+(L.length?'':'<div class="qlh-tip">💡 Drag any menu or sub menu from the left into this box, click a ☆ in the menu, or click <b>Add shortcut</b>.</div>'); }
    document.querySelectorAll('.ql-star').forEach(function(s){ s.classList.toggle('on',Q.items.indexOf(s.dataset.k)>=0||Q.company.indexOf(s.dataset.k)>=0); s.title=Q.items.indexOf(s.dataset.k)>=0?'Remove from quick shortcuts':'Add to quick shortcuts'; });
    if($('qlPk').classList.contains('open')) pk(); }
  function save(){ var f=new FormData(); f.append('items',JSON.stringify(Q.items)); fetch('yms_quicklinks.php?ql=save',{method:'POST',body:f,credentials:'same-origin'}).catch(function(){}); render(); }
  function addK(k,at){ if(!k||!build()[k]) return; var i=Q.items.indexOf(k); if(i>=0) Q.items.splice(i,1); if(at==null||at<0||at>Q.items.length) Q.items.push(k); else Q.items.splice(at,0,k); save(); flash(); }
  function rmK(k){ var i=Q.items.indexOf(k); if(i>=0){ Q.items.splice(i,1); save(); } }
  function flash(){ var b=$('qlBtn'); b.classList.add('drop'); setTimeout(function(){ b.classList.remove('drop'); },500); }
  Q.add=addK; Q.remove=rmK; Q.render=render;
  /* sidebar: ☆ buttons + drag source */
  function prep(){ var M=build(); var sb=document.querySelector('#sidebar,.sidebar'); if(!sb) return;
    sb.querySelectorAll('a[href]').forEach(function(a){ var m=(a.getAttribute('href')||'').match(/^([a-z0-9_\-]+)\.php/i); if(!m||!M[m[1].toLowerCase()]||a.querySelector('.ql-star')) return; var k=m[1].toLowerCase();
      a.setAttribute('draggable','true'); a.dataset.qlk=k; if(getComputedStyle(a).position==='static') a.style.position='relative';
      var s=document.createElement('button'); s.type='button'; s.className='ql-star'; s.dataset.k=k; s.innerHTML='★'; a.appendChild(s); });
    heads(sb).forEach(function(mi){ var k='menu:'+mlabel(mi); if(!M[k]||mi.querySelector('.ql-star')) return;
      mi.setAttribute('draggable','true'); mi.dataset.qlk=k; if(getComputedStyle(mi).position==='static') mi.style.position='relative'; var s=document.createElement('button'); s.type='button'; s.className='ql-star'; s.dataset.k=k; s.innerHTML='★'; s.style.right='34px'; mi.appendChild(s); });
    render(); }
  document.addEventListener('click',function(e){ var s=e.target.closest('.ql-star'); if(s){ e.preventDefault(); e.stopPropagation(); if(Q.company.indexOf(s.dataset.k)>=0){ s.title='Company shortcut — set by the administrator'; return; } Q.items.indexOf(s.dataset.k)>=0?rmK(s.dataset.k):addK(s.dataset.k); return; }
    var x=e.target.closest('[data-rm]'); if(x){ e.preventDefault(); e.stopPropagation(); rmK(x.dataset.rm); return; }
    var mt=e.target.closest('.ql-it[data-k^="menu:"]'); if(mt){ e.preventDefault(); popMenu(mt); return; }
    if(e.target.closest('#qlHAdd')){ openPk(); return; }
    if(!e.target.closest('.ql-pop')) document.querySelectorAll('.ql-pop').forEach(function(p){p.remove();});
    if(!e.target.closest('#qlWrap')) $('qlDrop').classList.remove('open'); }, true);
  function popMenu(el){ document.querySelectorAll('.ql-pop').forEach(function(p){p.remove();}); var M=build(), x=M[el.dataset.k]; if(!x) return; var p=document.createElement('div'); p.className='ql-pop';
    p.innerHTML='<b>'+esc(x.l)+'</b>'+x.menu.map(function(k){ var y=M[k]; return '<a href="'+esc(y.u)+'"><i class="'+esc(y.i)+'" style="width:16px;color:'+col(k)+'"></i>'+esc(y.l)+'</a>'; }).join(''); document.body.appendChild(p);
    var r=el.getBoundingClientRect(); p.style.top=Math.min(r.bottom+6,window.innerHeight-p.offsetHeight-8)+'px'; p.style.left=Math.max(8,Math.min(r.left,window.innerWidth-p.offsetWidth-8))+'px'; }
  /* drag & drop: sidebar → ⚡ / home tiles; tiles → new order */
  var dragK=null, fromList=false;
  document.addEventListener('dragstart',function(e){ var t=e.target.closest&&e.target.closest('[data-qlk],.ql-it[data-k],.ql-mine .mi'); if(!t) return; dragK=t.dataset.qlk||t.dataset.k; fromList=!t.dataset.qlk; if(!dragK) return;
    try{ e.dataTransfer.setData('text/plain',dragK); e.dataTransfer.effectAllowed='copyMove'; }catch(_){} t.classList.add('dragging'); if(!fromList) $('qlDz').classList.add('show'); });
  document.addEventListener('dragend',function(e){ document.querySelectorAll('.dragging,.over').forEach(function(x){x.classList.remove('dragging','over');}); $('qlBtn').classList.remove('drop'); $('qlDz').classList.remove('show'); var H=$('qlHome'); if(H) H.classList.remove('drop'); dragK=null; });
  function zone(e){ return e.target.closest&&(e.target.closest('#qlBtn,#qlDrop')?'btn':(e.target.closest('#qlHome')?'home':(e.target.closest('#qlMine')?'mine':null))); }
  document.addEventListener('dragover',function(e){ if(!dragK) return; var z=zone(e); if(!z) return; e.preventDefault(); if(z==='btn') $('qlBtn').classList.add('drop'); if(z==='home') $('qlHome').classList.add('drop');
    document.querySelectorAll('.over').forEach(function(x){x.classList.remove('over');}); var t=e.target.closest('.ql-it[data-k],.ql-mine .mi'); if(t) t.classList.add('over'); });
  document.addEventListener('dragleave',function(e){ if(e.target.id==='qlBtn') $('qlBtn').classList.remove('drop'); });
  document.addEventListener('drop',function(e){ if(!dragK) return; var z=zone(e); if(!z) return; e.preventDefault(); var t=e.target.closest('.ql-it[data-k],.ql-mine .mi'), at=t?Q.items.indexOf(t.dataset.k):null; if(at!=null&&at<0) at=null;
    if(fromList&&at!=null){ var i=Q.items.indexOf(dragK); if(i>=0&&i<at) at--; } addK(dragK,at); });
  /* header drop-down */
  $('qlBtn').addEventListener('click',function(e){ e.stopPropagation(); $('qlDrop').classList.toggle('open'); });
  $('qlEdit').addEventListener('click',function(){ $('qlDrop').classList.remove('open'); openPk(); });
  /* picker */
  function openPk(){ $('qlPk').classList.add('open'); $('qlPkQ').value=''; pk(); setTimeout(function(){ $('qlPkQ').focus(); },100); }
  $('qlPkX').addEventListener('click',function(){ $('qlPk').classList.remove('open'); });
  $('qlPk').addEventListener('click',function(e){ if(e.target.id==='qlPk') $('qlPk').classList.remove('open'); });
  $('qlPkQ').addEventListener('input',pk);
  function pk(){ var M=build(), q=$('qlPkQ').value.toLowerCase().trim(), g={};
    Object.keys(M).forEach(function(k){ var x=M[k]; if(q&&(x.l+' '+x.w+' '+k).toLowerCase().indexOf(q)<0) return; var grp=x.menu?'Whole menus':(x.w.split(' › ')[0]||'Pages'); (g[grp]=g[grp]||[]).push(x); });
    var h=''; Object.keys(g).sort(function(a,b){ return a==='Whole menus'?-1:(b==='Whole menus'?1:a.localeCompare(b)); }).forEach(function(grp){ h+='<div class="ql-pk-g">'+esc(grp)+'</div>'+g[grp].map(function(x){ var on=Q.items.indexOf(x.k)>=0||Q.company.indexOf(x.k)>=0; if(Q.company.indexOf(x.k)>=0) return '<div class="ql-pk-row on"><i class="fi '+esc(x.i)+'" style="background:'+col(x.k)+'"></i><span>'+esc(x.l)+'<small>'+esc(x.w)+'</small></span><span style="font-size:11px;font-weight:800;color:#6d28d9">🏢 Company</span></div>'; return '<div class="ql-pk-row'+(on?' on':'')+'" data-pk="'+esc(x.k)+'"><i class="fi '+esc(x.i)+'" style="background:'+col(x.k)+'"></i><span>'+esc(x.l)+'<small>'+esc(x.w)+'</small></span><button type="button" class="ad">'+(on?'✓ Added':'+ Add')+'</button></div>'; }).join(''); });
    $('qlPkList').innerHTML=h||'<div class="ql-empty">No page matches.</div>';
    var L=mine(), C=cmp(); $('qlPkN').textContent='('+(L.length+C.length)+')';
    $('qlMine').innerHTML=L.map(function(x){ return '<div class="mi" draggable="true" data-k="'+esc(x.k)+'"><i class="fa-solid fa-grip-vertical" style="color:#d6d3d1"></i><i class="fi '+esc(x.i)+'" style="background:'+col(x.k)+'"></i><span>'+esc(x.l)+'</span><button type="button" data-rm="'+esc(x.k)+'" title="Remove">×</button></div>'; }).join('')||'<div class="ql-empty">Click <b>+ Add</b> on the left,<br>or drag a menu from the sidebar.</div>';
    if(C.length) $('qlMine').insertAdjacentHTML('afterbegin','<div style="font-size:11px;font-weight:800;color:#6d28d9;margin:0 2px 2px">🏢 Company shortcuts (for every user)</div>'+C.map(function(x){ return '<div class="mi" style="background:#f5f3ff;border-color:#ddd6fe;cursor:default"><i class="fi '+esc(x.i)+'" style="background:'+col(x.k)+'"></i><span>'+esc(x.l)+'</span></div>'; }).join('')+(L.length?'<div style="font-size:11px;font-weight:800;color:#a16207;margin:6px 2px 2px">⭐ My own</div>':'')); }
  $('qlPkList').addEventListener('click',function(e){ var r=e.target.closest('[data-pk]'); if(!r) return; var k=r.dataset.pk; Q.items.indexOf(k)>=0?rmK(k):addK(k); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'){ $('qlPk').classList.remove('open'); $('qlDrop').classList.remove('open'); } });
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',prep); else setTimeout(prep,0);
})();
</script>
<?php
}

/** home page: the shortcut tiles (filled by the header script) */
function ql_home_section() {
    if (empty($_SESSION['user_id']) || empty($GLOBALS['QL_ON'])) return;
    ?>
<style>
#qlHome{background:#fff;border:1.5px dashed #fde68a;border-radius:16px;padding:12px 14px;margin:0 0 16px;transition:all .15s}
#qlHome.drop{background:#fffbeb;border-color:#f59e0b;box-shadow:0 0 0 4px rgba(245,158,11,.18)}
.qlh-hd{display:flex;align-items:center;gap:8px;font:800 14px 'Inter',system-ui,sans-serif;color:#78350f;margin:0 0 10px}.qlh-hd i{color:#f59e0b}.qlh-hd a{margin-left:auto;font-size:12px;color:#4f46e5;font-weight:700;text-decoration:none;cursor:pointer}
.qlh-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(118px,1fr));gap:10px}
.ql-it.qlh{background:#fff;border:1px solid #f1f5f9;box-shadow:0 4px 12px -8px rgba(15,23,42,.3);font-family:'Inter',system-ui,sans-serif}
.ql-it.qlh .qi{width:42px;height:42px;font-size:17px;border-radius:13px}
.qlh-add{border-style:dashed!important;color:#94a3b8!important}
.qlh-tip{font:500 12.5px 'Inter',system-ui,sans-serif;color:#64748b;margin-top:10px}
</style>
<div class="qlh-hd"><i class="fa-solid fa-bolt"></i> My quick shortcuts <a onclick="document.getElementById('qlHAdd')&&document.getElementById('qlHAdd').click()"><i class="fa-solid fa-pen"></i> Add / edit</a></div>
<div id="qlHome"></div>
<script>if(window.QL&&QL.render) QL.render();</script>
<?php
}
