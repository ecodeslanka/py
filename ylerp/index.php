<?php
/* ── Dashboard data: Unilever Receivable Summary (loaded after the page, only rows the user may view) ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'ur_summary') {
    ob_start();
    @ini_set('display_errors', '0'); @error_reporting(0);
    $__out = function ($arr) { $j = json_encode($arr, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR); while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo $j !== false ? $j : '{"success":false,"message":"Could not encode the summary."}'; exit; };
    register_shutdown_function(function () use ($__out) { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) $__out(['success' => false, 'message' => 'Server error: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')']); });
    include 'config.php';
    if (empty($_SESSION['user_id'])) $__out(['success' => false, 'message' => 'Please log in.']);
    try {
    require_once __DIR__ . '/unilever_receivable_lib.php';
    $F = ur_filters($_GET);
    /* what this role may open — worked out BEFORE the session is released */
    $allowed = []; foreach (['purchase_return_summary_report', 'damaged_claim', 'scheme_ledger_report', 'debit_note', 'dal', 'promoters_salary_cl', 'sr_incentive_cc', 'sscl_vat', 'pi_other_adjustments'] as $pg) $allowed[$pg] = rbac_can('view', $pg);
    /* the summary can take a few seconds: let go of the session file lock first, otherwise every other page / request of this user waits for it */
    session_write_close();
    /* short cache (90 s) per filter set — the Refresh button skips it */
    $ck = rtrim(sys_get_temp_dir(), '/\\') . '/yms_ur_' . md5(__DIR__ . json_encode($F)) . '.json';
    $d = null;
    if (empty($_GET['nocache']) && is_file($ck) && (time() - filemtime($ck)) < 90) $d = json_decode((string)@file_get_contents($ck), true);
    if (!is_array($d) || !isset($d['rows'])) { $d = ur_summary($conn, $F); @file_put_contents($ck, json_encode($d, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)); }
    $rows = array_values(array_filter($d['rows'], fn($r) => !empty($allowed[$r['page']])));   // only what this role may open
    $tot = ['receivable' => 0, 'received' => 0, 'paid' => 0, 'balance' => 0];
    foreach ($rows as $r) foreach ($tot as $k => $_) $tot[$k] += (float)($r[$k] ?? 0);
    $__out(['success' => true, 'rows' => $rows, 'total' => array_map(fn($v) => round($v, 2), $tot), 'debit_notes' => $d['debit_notes'] ?? 0, 'debit_notes_ok' => !empty($allowed['debit_note']),
            'allowed_any' => in_array(true, $allowed, true), 'as_at' => $F['as_at'], 'filters' => $F]);
    } catch (Throwable $ex) { $__out(['success' => false, 'message' => $ex->getMessage() . ' (' . basename($ex->getFile()) . ':' . $ex->getLine() . ')']); }
}
?>
<?php include 'header.php'; ?>

<!-- Dashboard Content -->
<div class="page-header">
    <h2 class="page-title">Dashboard</h2>
    <p class="page-subtitle">Welcome to Yelo Group Management System</p>
</div>

<!-- Unilever Receivable Summary -->
<div class="ur" id="urBox" style="display:none">
  <div class="ur-head">
    <div class="ur-title"><span class="ur-ic"><i class="fa-solid fa-scale-balanced"></i></span>
      <div><h3>Unilever Receivable Summary</h3><small id="urAsAt">live from Customer Ledger &amp; Vendor Ledger</small></div></div>
    <button type="button" class="ur-btn" id="urReload" title="Refresh"><i class="fa-solid fa-rotate"></i> Refresh</button>
  </div>
  <div class="ur-filters">
    <label>As at<input type="date" id="urAsAtIn"></label>
    <label>From<input type="date" id="urFrom"></label>
    <label>To<input type="date" id="urTo"></label>
    <div class="urm" id="urYM"><button type="button" class="urm-b"><span>All years</span> <i class="fa-solid fa-chevron-down"></i></button><div class="urm-m" id="urYMenu"></div></div>
    <div class="urm" id="urMM"><button type="button" class="urm-b"><span>All months</span> <i class="fa-solid fa-chevron-down"></i></button><div class="urm-m" id="urMMenu"></div></div>
    <label class="urck"><input type="checkbox" id="urLast"> With last year</label>
    <button type="button" class="ur-go" id="urApply"><i class="fa-solid fa-filter"></i> Apply</button>
    <button type="button" class="ur-rs" id="urReset">Reset</button>
  </div>
  <div class="ur-kpis" id="urKpis"></div>
  <div class="ur-scroll">
    <table class="ur-t">
      <thead><tr><th>Source</th><th>Description</th><th class="r">Receivable</th><th class="r">Received</th><th class="r">Paid</th><th class="r">Balance</th><th class="ur-rcv">Recovered</th></tr></thead>
      <tbody id="urBody"></tbody>
      <tfoot id="urFoot"></tfoot>
    </table>
  </div>
  <div class="ur-note"><span class="dot pos"></span> Balance + = Unilever still owes us &nbsp; <span class="dot neg"></span> Balance ( ) = received more than claimed &nbsp;·&nbsp; Scheme Discount &amp; Free Issues = the Scheme Ledger (debit / credit / closing balance) — click it to open the ledger. Click any other line to open its page.</div>
</div>
<!-- Scheme Ledger window (above the site header) -->
<div class="ur-modal" id="urModal" aria-hidden="true"><div class="ur-mbox">
  <div class="ur-mhead"><div><b>Scheme Ledger</b><small id="urMSub"></small></div><a class="ur-mopen" id="urMOpen" target="_blank" rel="noopener" title="Open in a new tab"><i class="fa-solid fa-up-right-from-square"></i></a><button type="button" id="urMClose" title="Close (Esc)"><i class="fa-solid fa-xmark"></i></button></div>
  <div class="ur-mbody"><div class="ur-mload" id="urMLoad">Loading the ledger…</div><iframe id="urMFrame" title="Scheme Ledger"></iframe></div>
</div></div>
<style>
.ur{background:#fff;border:1px solid #e5e7eb;border-radius:16px;margin-bottom:22px;overflow:visible;box-shadow:0 8px 28px rgba(15,23,42,.07)}
.ur-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;background:linear-gradient(120deg,#0f172a,#1e3a8a 60%,#0e7490);color:#fff;flex-wrap:wrap;border-radius:16px 16px 0 0}
.ur-title{display:flex;align-items:center;gap:12px}.ur-title h3{margin:0;font-size:18px;font-weight:800;letter-spacing:.2px}.ur-title small{opacity:.75;font-size:12px}
.ur-ic{width:42px;height:42px;border-radius:12px;background:rgba(255,255,255,.14);display:flex;align-items:center;justify-content:center;font-size:19px}
.ur-btn{border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.12);color:#fff;border-radius:9px;padding:7px 14px;font:600 12.5px inherit;cursor:pointer}.ur-btn:hover{background:rgba(255,255,255,.22)}
.ur-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;padding:12px 20px;border-bottom:1px solid #eef2f7;background:#f8fafc}
.ur-filters label{display:flex;flex-direction:column;gap:3px;font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em}.ur-filters input[type=date]{padding:7px 9px;border:1.5px solid #d1d5db;border-radius:8px;font:inherit;font-size:13px;color:#111827;text-transform:none}
.ur-filters .urck{flex-direction:row;align-items:center;gap:7px;font-size:12.5px;text-transform:none;color:#334155;padding-bottom:8px;letter-spacing:0}.ur-go{padding:8px 16px;border:none;border-radius:8px;background:#1e3a8a;color:#fff;font:600 13px inherit;cursor:pointer}.ur-rs{padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:8px;background:#fff;color:#475569;font:600 13px inherit;cursor:pointer}
.urm{position:relative}.urm-b{min-width:130px;padding:7px 10px;border:1.5px solid #d1d5db;border-radius:8px;background:#fff;font:inherit;font-size:13px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:8px;height:35px;color:#111827}
.urm-m{display:none;position:absolute;z-index:80;top:100%;left:0;min-width:100%;margin-top:4px;background:#fff;border:1px solid #d1d5db;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.18);padding:8px;white-space:nowrap;max-height:300px;overflow:auto}.urm.open .urm-m{display:block}
.urm-m label{flex-direction:row;display:flex;gap:8px;align-items:center;padding:5px 8px;border-radius:6px;cursor:pointer;font-size:13px;text-transform:none;color:#111827;letter-spacing:0;font-weight:500}.urm-m label:hover{background:#f1f5f9}.urm-a{border-top:1px solid #eef0f3;margin-top:4px;padding:6px 8px 2px;font-size:12px;color:#6b7280}.urm-a a{color:#1e3a8a;font-weight:700;text-decoration:none}
.ur-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;padding:16px 20px 6px}
.ur-k{border-radius:12px;padding:12px 14px;border:1px solid var(--c-b);background:var(--c-bg)}.ur-k span{display:block;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--c)}
.ur-k b{display:block;font-size:21px;margin-top:3px;color:#0f172a;font-variant-numeric:tabular-nums}.ur-k a{text-decoration:none;color:inherit;display:block}.ur-k.lnk{cursor:pointer}.ur-k.lnk:hover{box-shadow:0 4px 14px rgba(15,23,42,.12)}
.ur-scroll{overflow-x:auto;padding:8px 20px 4px}.ur-t{width:100%;border-collapse:separate;border-spacing:0 6px;font-size:13.5px;min-width:700px}
.ur-t th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;text-align:left;padding:4px 12px;font-weight:700}.ur-t .r{text-align:right}
.ur-t tbody tr{background:#f8fafc;cursor:pointer;transition:transform .12s,box-shadow .12s}.ur-t tbody tr:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(15,23,42,.1);background:#fff}
.ur-t td{padding:11px 12px;border-top:1px solid #eef2f7;border-bottom:1px solid #eef2f7;font-variant-numeric:tabular-nums}.ur-t td:first-child{border-left:1px solid #eef2f7;border-radius:10px 0 0 10px}.ur-t td:last-child{border-right:1px solid #eef2f7;border-radius:0 10px 10px 0}
.ur-src{display:inline-block;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}.ur-src.cl{background:#dbeafe;color:#1d4ed8}.ur-src.cc{background:#ede9fe;color:#6d28d9}
.ur-desc{font-weight:700;color:#0f172a;display:flex;align-items:center;gap:9px}.ur-desc i{width:28px;height:28px;border-radius:8px;background:#e0f2fe;color:#0369a1;display:flex;align-items:center;justify-content:center;font-size:13px}.ur-desc .lk{font-size:10.5px;font-weight:700;color:#7c3aed;background:#f5f3ff;border-radius:99px;padding:1px 8px}
.ur-bal{font-weight:800;border-radius:8px;padding:3px 10px;display:inline-block;min-width:96px;text-align:right}.ur-bal.pos{background:#fee2e2;color:#b91c1c}.ur-bal.neg{background:#dcfce7;color:#15803d}.ur-bal.zero{background:#f1f5f9;color:#64748b}
.ur-rcv{width:150px}.ur-bar{height:9px;background:#e2e8f0;border-radius:99px;overflow:hidden}.ur-bar i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#22c55e,#16a34a)}.ur-bar i.warn{background:linear-gradient(90deg,#f59e0b,#d97706)}.ur-bar i.low{background:linear-gradient(90deg,#f87171,#dc2626)}
.ur-pc{font-size:11px;color:#64748b;margin-top:2px}.ur-dash{color:#cbd5e1}
.ur-t tfoot td{padding:13px 12px;font-weight:800;font-size:14.5px;background:#0f172a;color:#fff}.ur-t tfoot td:first-child{border-radius:10px 0 0 10px}.ur-t tfoot td:last-child{border-radius:0 10px 10px 0}
.ur-note{padding:8px 20px 16px;color:#64748b;font-size:12px}.ur-note .dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:4px}.dot.pos{background:#ef4444}.dot.neg{background:#22c55e}
.ur-modal{position:fixed;inset:0;z-index:2147483000;background:rgba(15,23,42,.62);display:none;align-items:center;justify-content:center;padding:14px}.ur-modal.open{display:flex}
.ur-mbox{background:#fff;border-radius:14px;width:100%;max-width:1700px;height:96vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 90px rgba(0,0,0,.4)}
.ur-mhead{display:flex;align-items:center;gap:12px;padding:12px 18px;background:linear-gradient(120deg,#0f172a,#1e3a8a);color:#fff}.ur-mhead b{font-size:16px;display:block}.ur-mhead small{opacity:.8;font-size:12px}
.ur-mhead #urMClose{margin-left:auto;width:36px;height:36px;border-radius:50%;border:none;background:#dc2626;color:#fff;font-size:16px;cursor:pointer}.ur-mopen{margin-left:auto;color:#fff;width:34px;height:34px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:rgba(255,255,255,.15);text-decoration:none}.ur-mopen+#urMClose{margin-left:8px}
.ur-mbody{position:relative;flex:1;min-height:0;background:#f9fafb}.ur-mbody iframe{width:100%;height:100%;border:0;background:#fff}.ur-mload{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#6b7280;font-size:14px;background:#f9fafb}.ur-mbody.ready .ur-mload{display:none}
body.ms-dark .ur,body.ms-dark .ur-t tbody tr{background:#1f2937;border-color:#374151}body.ms-dark .ur-t tbody tr:hover{background:#273244}body.ms-dark .ur-t td{border-color:#374151}body.ms-dark .ur-desc,body.ms-dark .ur-k b{color:#f3f4f6}body.ms-dark .ur-k{background:#111827}body.ms-dark .ur-filters{background:#111827;border-color:#374151}
@media(max-width:700px){.ur-head,.ur-filters,.ur-kpis,.ur-scroll,.ur-note{padding-left:12px;padding-right:12px}}
</style>
<script>
(function(){
  var box=document.getElementById('urBox'); if(!box) return;
  var $=function(i){ return document.getElementById(i); }, KEY='ur_filters_v2';
  var MON=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'], thisYear=new Date().getFullYear();
  var fmt=function(n){ return Number(n).toLocaleString('en-US',{maximumFractionDigits:0}); }, neg=function(n){ return n<0?'('+fmt(Math.abs(n))+')':fmt(n); };
  document.body.appendChild($('urModal'));
  /* year / month multi-select menus */
  function menu(id,items,label){ var m=$(id); m.innerHTML=items.map(function(it){ return '<label><input type="checkbox" value="'+it[0]+'"> '+it[1]+'</label>'; }).join('')+'<div class="urm-a"><a href="#" data-all>Select all</a> · <a href="#" data-none>Clear</a></div>'; }
  menu('urYMenu',[0,1,2,3,4,5].map(function(k){ return [thisYear-k,String(thisYear-k)]; })); menu('urMMenu',MON.map(function(n,i){ return [i+1,n]; }));
  function picked(id){ return [].filter.call($(id).querySelectorAll('input[type=checkbox]'),function(c){ return c.checked; }).map(function(c){ return c.value; }); }
  function setPicked(id,vals){ [].forEach.call($(id).querySelectorAll('input[type=checkbox]'),function(c){ c.checked=vals.indexOf(c.value)>-1; }); }
  function label(wrap,id,all,txt){ var p=picked(id); wrap.querySelector('.urm-b span').textContent=p.length?(txt?txt(p):p.join(', ')):all; }
  function sync(){ label($('urYM'),'urYMenu','All years'); label($('urMM'),'urMMenu','All months',function(p){ return p.map(function(v){ return MON[v-1]; }).join(', '); }); }
  [['urYM','urYMenu'],['urMM','urMMenu']].forEach(function(x){ var w=$(x[0]); w.querySelector('.urm-b').addEventListener('click',function(e){ e.stopPropagation(); var o=w.classList.contains('open'); [].forEach.call(document.querySelectorAll('.urm.open'),function(m){ m.classList.remove('open'); }); if(!o) w.classList.add('open'); });
    w.addEventListener('change',sync); w.addEventListener('click',function(e){ var a=e.target.closest('[data-all],[data-none]'); if(!a) return; e.preventDefault(); [].forEach.call($(x[1]).querySelectorAll('input[type=checkbox]'),function(c){ c.checked=a.hasAttribute('data-all'); }); sync(); }); });
  document.addEventListener('click',function(e){ if(!e.target.closest('.urm')) [].forEach.call(document.querySelectorAll('.urm.open'),function(m){ m.classList.remove('open'); }); });
  /* state */
  function today(){ var d=new Date(); return new Date(d.getTime()-d.getTimezoneOffset()*60000).toISOString().slice(0,10); }
  function read(){ var y=picked('urYMenu').map(Number), last=$('urLast').checked;
    if(last){ var s={}; (y.length?y:[thisYear]).forEach(function(v){ s[v]=1; s[v-1]=1; }); y=Object.keys(s).map(Number).sort(function(a,b){ return b-a; }); }
    return {as_at:$('urAsAtIn').value||today(),from:$('urFrom').value,to:$('urTo').value,years:y.join(','),months:picked('urMMenu').join(','),last:last?1:0,_y:picked('urYMenu')}; }
  function save(f){ try{ localStorage.setItem(KEY,JSON.stringify({as_at:f.as_at,from:f.from,to:f.to,y:f._y,m:f.months?f.months.split(','):[],last:f.last})); }catch(e){} }
  function restore(){ var s=null; try{ s=JSON.parse(localStorage.getItem(KEY)||'null'); }catch(e){}
    $('urAsAtIn').value=today(); if(!s) return; $('urFrom').value=s.from||''; $('urTo').value=s.to||''; $('urLast').checked=!!s.last; setPicked('urYMenu',s.y||[]); setPicked('urMMenu',s.m||[]); }
  var LEDGER='';
  function load(fresh){
    var f=read(); save(f); sync();
    var qs=new URLSearchParams({ajax:'ur_summary',as_at:f.as_at,from:f.from,to:f.to,years:f.years,months:f.months}); if(fresh) qs.set('nocache','1');
    $('urBody').style.opacity='.45'; $('urAsAt').textContent='loading…';
    fetch('index.php?'+qs.toString(),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      box.style.display='block';
      if(!d.success){ $('urAsAt').textContent='could not load'; $('urBody').style.opacity='1'; $('urKpis').innerHTML=''; $('urFoot').innerHTML=''; $('urBody').innerHTML='<tr><td colspan="7" style="padding:26px;text-align:center;cursor:default;color:#b91c1c;font-weight:600">The summary could not be loaded: '+String(d.message||'unknown error').replace(/</g,'&lt;')+' <button type="button" class="ur-go" onclick="document.getElementById(\'urReload\').click()" style="margin-left:10px">Try again</button></td></tr>'; return; }
      if(!d.rows.length){ $('urAsAt').textContent='nothing to show'; $('urBody').style.opacity='1'; $('urKpis').innerHTML=''; $('urFoot').innerHTML=''; $('urBody').innerHTML='<tr><td colspan="7" style="padding:26px;text-align:center;cursor:default;color:#64748b">'+(d.allowed_any===false?'Your role is not allowed to view any of the ledger pages in this summary. Ask the administrator to give you the View right on them (User Role).':'No ledger data found for these filters yet.')+'</td></tr>'; return; }
      var info=[]; if(f.years) info.push('year '+f.years.split(',').join(', ')); if(f.months) info.push(f.months.split(',').map(function(v){ return MON[v-1]; }).join(', ')); if(f.from||f.to) info.push((f.from||'…')+' → '+(f.to||'…'));
      $('urAsAt').textContent='as at '+d.as_at+(info.length?' · '+info.join(' · '):'')+' · live from Customer Ledger & Vendor Ledger';
      var t=d.total, K=[['Receivable',fmt(t.receivable),'#2563eb','#eff6ff','#bfdbfe',''],['Received',fmt(t.received),'#16a34a','#f0fdf4','#bbf7d0',''],['Paid out',fmt(t.paid),'#d97706','#fffbeb','#fde68a','']];
      K.push(['Net balance',neg(t.balance),t.balance>0?'#dc2626':'#16a34a',t.balance>0?'#fef2f2':'#f0fdf4',t.balance>0?'#fecaca':'#bbf7d0','']);
      $('urKpis').innerHTML=K.map(function(k){ return '<div class="ur-k'+(k[5]?' lnk':'')+'" style="--c:'+k[2]+';--c-bg:'+k[3]+';--c-b:'+k[4]+'"'+(k[5]?' data-go="'+k[5]+'" title="Open the Debit Notes page"':'')+'><span>'+k[0]+(k[5]?' <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:9px"></i>':'')+'</span><b>Rs. '+k[1]+'</b></div>'; }).join('');
      $('urBody').innerHTML=d.rows.map(function(r){
        var base=(r.paid!=null&&r.paid>0)?r.paid:(r.receivable||0), pc=base>0?Math.max(0,Math.min(100,Math.round((r.received||0)/base*100))):0, cls=pc>=95?'':(pc>=60?'warn':'low'), bc=r.balance>0.5?'pos':(r.balance<-0.5?'neg':'zero');
        return '<tr '+(r.ledger?'data-ledger="'+r.ledger+'"':'data-p="'+r.page+'.php"')+'><td><span class="ur-src '+(r.source==='Customer Ledger'?'cl':'cc')+'">'+r.source+'</span></td>'
          +'<td><div class="ur-desc"><i class="fa-solid '+r.icon+'"></i>'+r.label.replace(/&/g,'&amp;')+(r.ledger?' <span class="lk">ledger</span>':'')+'</div></td>'
          +'<td class="r">'+(r.receivable?fmt(r.receivable):'<span class="ur-dash">—</span>')+'</td><td class="r">'+(r.received?fmt(r.received):'<span class="ur-dash">—</span>')+'</td><td class="r">'+(r.paid!=null&&r.paid>0?fmt(r.paid):'<span class="ur-dash">—</span>')+'</td>'
          +'<td class="r"><span class="ur-bal '+bc+'">'+neg(r.balance)+'</span></td>'
          +'<td class="ur-rcv"><div class="ur-bar"><i class="'+cls+'" style="width:'+pc+'%"></i></div><div class="ur-pc">'+pc+'% recovered</div></td></tr>'; }).join('');
      $('urBody').style.opacity='1'; $('urFoot').innerHTML='<tr><td colspan="2">Total</td><td class="r">'+fmt(t.receivable)+'</td><td class="r">'+fmt(t.received)+'</td><td class="r">'+fmt(t.paid)+'</td><td class="r">'+neg(t.balance)+'</td><td></td></tr>';
    }).catch(function(err){ box.style.display='block'; $('urBody').style.opacity='1'; $('urAsAt').textContent='could not load'; $('urBody').innerHTML='<tr><td colspan="7" style="padding:26px;text-align:center;cursor:default;color:#b91c1c;font-weight:600">The summary could not be loaded (the server did not answer correctly). <button type="button" class="ur-go" onclick="document.getElementById(\'urReload\').click()" style="margin-left:10px">Try again</button></td></tr>'; });
  }
  /* Scheme Ledger window */
  var EMBED='header.header,.header,aside.sidebar,#sidebar,.sidebar-overlay,.ms-edge-toggle,footer.footer,.page-header{display:none!important;}body{padding-top:0!important;margin-top:0!important;}.main-content{margin-left:0!important;margin-top:0!important;padding-top:10px!important;width:100%!important;max-width:100%!important;}';
  function openLedger(url){ var fr=$('urMFrame'), body=document.querySelector('.ur-mbody'); body.classList.remove('ready'); $('urMOpen').href=url; $('urMSub').textContent=' ';
    fr.onload=function(){ try{ var d=fr.contentDocument; if(d&&d.head&&!d.getElementById('urcss')){ var st=d.createElement('style'); st.id='urcss'; st.textContent=EMBED; d.head.appendChild(st); } }catch(e){} body.classList.add('ready'); };
    fr.src=url; $('urModal').classList.add('open'); document.body.style.overflow='hidden'; }
  function closeLedger(){ $('urModal').classList.remove('open'); document.body.style.overflow=''; $('urMFrame').removeAttribute('src'); }
  $('urMClose').addEventListener('click',closeLedger); $('urModal').addEventListener('click',function(e){ if(e.target===$('urModal')) closeLedger(); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'&&$('urModal').classList.contains('open')) closeLedger(); });
  $('urBody').addEventListener('click',function(e){ var tr=e.target.closest('tr'); if(!tr) return; if(tr.dataset.ledger) openLedger(tr.dataset.ledger); else if(tr.dataset.p) location.href=tr.dataset.p; });
  $('urKpis').addEventListener('click',function(e){ var k=e.target.closest('[data-go]'); if(k) location.href=k.dataset.go; });
  $('urApply').addEventListener('click',function(){ load(false); }); $('urReload').addEventListener('click',function(){ load(true); });
  $('urReset').addEventListener('click',function(){ $('urAsAtIn').value=today(); $('urFrom').value=''; $('urTo').value=''; $('urLast').checked=false; setPicked('urYMenu',[]); setPicked('urMMenu',[]); load(); });
  restore(); sync(); load(false);
})();
</script>

<?php include 'footer.php'; ?>
