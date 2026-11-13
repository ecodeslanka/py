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
    $tot = ['open' => 0, 'receivable' => 0, 'received' => 0, 'paid' => 0, 'balance' => 0];
    foreach ($rows as $r) foreach ($tot as $k => $_) $tot[$k] += (float)($r[$k] ?? 0);
    $tg = ['rec' => ['open' => 0, 'receivable' => 0, 'received' => 0, 'balance' => 0], 'paid' => ['open' => 0, 'paid' => 0, 'received' => 0, 'balance' => 0]];
    foreach ($rows as $r) { $g = $r['group'] ?? 'rec'; foreach ($tg[$g] as $k => $_) $tg[$g][$k] += (float)($r[$k] ?? 0); }
    foreach ($tg as $g => $a) foreach ($a as $k => $v) $tg[$g][$k] = round($v, 2);
    $__out(['success' => true, 'rows' => $rows, 'total' => array_map(fn($v) => round($v, 2), $tot), 'groups' => $tg, 'debit_notes' => $d['debit_notes'] ?? 0, 'debit_notes_ok' => !empty($allowed['debit_note']),
            'allowed_any' => in_array(true, $allowed, true), 'warn' => $d['warn'] ?? [], 'as_at' => $F['as_at'], 'mode' => $F['mode'], 'from' => $F['from'], 'to' => $F['to'], 'filters' => $F]);
    } catch (Throwable $ex) { $__out(['success' => false, 'message' => $ex->getMessage() . ' (' . basename($ex->getFile()) . ':' . $ex->getLine() . ')']); }
}
?>
<?php include 'header.php'; ?>

<!-- Dashboard Content -->
<div class="page-header">
    <h2 class="page-title">Dashboard</h2>
    <p class="page-subtitle">Welcome to Yelo Group Management System</p>
</div>

<!-- Unilever Receivable Summary dashboard -->
<div class="ur" id="urBox" style="display:none">
  <div class="ur-head">
    <div class="ur-title"><span class="ur-ic"><i class="fa-solid fa-scale-balanced"></i></span>
      <div><h3>Unilever Receivable Summary</h3><small id="urAsAt">live from Customer Ledger &amp; Vendor Ledger</small></div></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap"><a class="ur-btn" id="urPrint" href="unilever_summary_print.php" data-no-loader title="One A4 page: print or download as PDF" style="text-decoration:none"><i class="fa-solid fa-file-pdf"></i> Print / PDF</a>
    <button type="button" class="ur-btn" id="urReload" title="Recalculate now"><i class="fa-solid fa-rotate"></i> Refresh</button></div>
  </div>
  <div class="ur-filters">
    <div class="ur-mode" id="urMode"><button type="button" data-m="asat" class="on"><i class="fa-regular fa-calendar-check"></i> As at</button><button type="button" data-m="range"><i class="fa-solid fa-arrows-left-right"></i> Date range</button></div>
    <label id="urLAs">As at<input type="date" id="urAsAtIn"></label>
    <label class="urr">From<input type="date" id="urFrom"></label>
    <label class="urr">To<input type="date" id="urTo"></label>
    <div class="urm" id="urYM"><button type="button" class="urm-b"><span>All years</span> <i class="fa-solid fa-chevron-down"></i></button><div class="urm-m" id="urYMenu"></div></div>
    <div class="urm" id="urMM"><button type="button" class="urm-b"><span>All months</span> <i class="fa-solid fa-chevron-down"></i></button><div class="urm-m" id="urMMenu"></div></div>
    <label class="urck"><input type="checkbox" id="urLast"> With last year</label>
    <button type="button" class="ur-go" id="urApply"><i class="fa-solid fa-filter"></i> Apply</button>
    <button type="button" class="ur-rs" id="urReset">Reset</button>
  </div>

  <div class="ur-kpis" id="urKpis"></div>

  <div class="ur-charts">
    <div class="ur-card"><div class="ur-ch">Recovery</div><div class="ur-donutw" id="urDonut"></div></div>
    <div class="ur-card ur-wide"><div class="ur-ch">Balance by line <small>red = Unilever still owes us · green = received more than claimed</small></div><div id="urBars"></div></div>
  </div>

  <div class="ur-card ur-tcard">
    <div class="ur-ch"><span><i class="fa-solid fa-hand-holding-dollar" style="color:#2563eb"></i> Receivable from Unilever</span><small>Customer Ledger &amp; Vendor Ledger</small></div>
    <div class="ur-scroll"><table class="ur-t">
      <thead><tr><th>Source</th><th>Description</th><th class="r">Receivable</th><th class="r">Received</th><th class="r">Balance</th><th class="ur-rcv">Recovered</th></tr></thead>
      <tbody id="urBody"></tbody><tfoot id="urFoot"></tfoot></table></div>
  </div>

  <div class="ur-card ur-tcard" id="urPaidCard">
    <div class="ur-ch"><span><i class="fa-solid fa-money-bill-transfer" style="color:#d97706"></i> Paid by us — claimed back from Unilever</span><small>Drivers &amp; Loyalty · Promoters Salary · SR Incentive</small></div>
    <div class="ur-scroll"><table class="ur-t">
      <thead><tr><th>Source</th><th>Description</th><th class="r">Paid</th><th class="r">Received</th><th class="r">Balance</th><th class="ur-rcv">Recovered</th></tr></thead>
      <tbody id="urBody2"></tbody><tfoot id="urFoot2"></tfoot></table></div>
  </div>

  <div class="ur-note"><span class="dot pos"></span> Balance + = Unilever still owes us &nbsp; <span class="dot neg"></span> Balance ( ) = received more than claimed &nbsp;·&nbsp; Every line is the closing figure of its own ledger (debit / credit / balance) — click a line or a bar to open that ledger.</div>
</div>
<!-- Scheme Ledger window (above the site header) -->
<div class="ur-modal" id="urModal" aria-hidden="true"><div class="ur-mbox">
  <div class="ur-mhead"><div><b id="urMTitle">Ledger</b><small id="urMSub"></small></div><a class="ur-mopen" id="urMOpen" target="_blank" rel="noopener" title="Open in a new tab"><i class="fa-solid fa-up-right-from-square"></i></a><button type="button" id="urMClose" title="Close (Esc)"><i class="fa-solid fa-xmark"></i></button></div>
  <div class="ur-mbody"><div class="ur-mload" id="urMLoad">Loading the ledger…</div><iframe id="urMFrame" title="Scheme Ledger"></iframe></div>
</div></div>
<style>
.ur{margin-bottom:22px}
.ur-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:20px 24px;background:radial-gradient(circle at 12% 0%,#2563eb 0,transparent 45%),linear-gradient(120deg,#0b1220,#1e3a8a 62%,#0e7490);color:#fff;flex-wrap:wrap;border-radius:18px;box-shadow:0 12px 34px rgba(30,58,138,.35)}
.ur-title{display:flex;align-items:center;gap:14px}.ur-title h3{margin:0;font-size:21px;font-weight:800;letter-spacing:.2px}.ur-title small{opacity:.78;font-size:12.5px}
.ur-ic{width:50px;height:50px;border-radius:14px;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;font-size:22px;backdrop-filter:blur(4px)}
.ur-btn{border:1px solid rgba(255,255,255,.4);background:rgba(255,255,255,.14);color:#fff;border-radius:10px;padding:8px 16px;font:600 13px inherit;cursor:pointer}.ur-btn:hover{background:rgba(255,255,255,.26)}
.ur-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;padding:14px 18px;margin:14px 0;background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 2px 10px rgba(15,23,42,.05)}
.ur-filters label{display:flex;flex-direction:column;gap:3px;font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em}.ur-filters input[type=date]{padding:7px 9px;border:1.5px solid #d1d5db;border-radius:8px;font:inherit;font-size:13px;color:#111827;text-transform:none}
.ur-mode{display:inline-flex;background:#f1f5f9;border-radius:10px;padding:3px;gap:2px;align-self:flex-end}.ur-mode button{border:none;background:transparent;padding:7px 13px;border-radius:8px;font:600 12.5px inherit;color:#475569;cursor:pointer}.ur-mode button.on{background:#1e3a8a;color:#fff;box-shadow:0 2px 6px rgba(30,58,138,.3)}
.ur-filters input:disabled{background:#f1f5f9;color:#9ca3af;cursor:not-allowed}.ur-filters label.off{opacity:.55}
.ur-op{display:none}.ur-range .ur-op{display:table-cell}
.ur-filters .urck{flex-direction:row;align-items:center;gap:7px;font-size:12.5px;text-transform:none;color:#334155;padding-bottom:8px;letter-spacing:0}.ur-go{padding:8px 18px;border:none;border-radius:9px;background:linear-gradient(120deg,#1e3a8a,#2563eb);color:#fff;font:600 13px inherit;cursor:pointer}.ur-rs{padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:9px;background:#fff;color:#475569;font:600 13px inherit;cursor:pointer}
.urm{position:relative}.urm-b{min-width:130px;padding:7px 10px;border:1.5px solid #d1d5db;border-radius:8px;background:#fff;font:inherit;font-size:13px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:8px;height:35px;color:#111827}
.urm-m{display:none;position:absolute;z-index:80;top:100%;left:0;min-width:100%;margin-top:4px;background:#fff;border:1px solid #d1d5db;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.18);padding:8px;white-space:nowrap;max-height:300px;overflow:auto}.urm.open .urm-m{display:block}
.urm-m label{flex-direction:row;display:flex;gap:8px;align-items:center;padding:5px 8px;border-radius:6px;cursor:pointer;font-size:13px;text-transform:none;color:#111827;letter-spacing:0;font-weight:500}.urm-m label:hover{background:#f1f5f9}.urm-a{border-top:1px solid #eef0f3;margin-top:4px;padding:6px 8px 2px;font-size:12px;color:#6b7280}.urm-a a{color:#1e3a8a;font-weight:700;text-decoration:none}
.ur-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin-bottom:14px}
.ur-k{position:relative;overflow:hidden;border-radius:16px;padding:16px 18px;color:#fff;background:linear-gradient(135deg,var(--a),var(--b));box-shadow:0 10px 26px var(--s)}
.ur-k::after{content:"";position:absolute;right:-26px;top:-26px;width:110px;height:110px;border-radius:50%;background:rgba(255,255,255,.14)}
.ur-k span{display:flex;align-items:center;gap:8px;font-size:11.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;opacity:.92}.ur-k span i{width:30px;height:30px;border-radius:9px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:14px}
.ur-k b{display:block;font-size:25px;margin-top:10px;font-variant-numeric:tabular-nums;letter-spacing:-.3px}.ur-k small{display:block;margin-top:2px;font-size:11.5px;opacity:.85}
.ur-charts{display:grid;grid-template-columns:minmax(260px,340px) 1fr;gap:14px;margin-bottom:14px}@media(max-width:900px){.ur-charts{grid-template-columns:1fr}}
.ur-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:16px 18px;box-shadow:0 4px 16px rgba(15,23,42,.06)}.ur-tcard{margin-bottom:14px;padding:14px 16px}
.ur-ch{display:flex;align-items:baseline;gap:10px;justify-content:space-between;flex-wrap:wrap;font-weight:800;font-size:14.5px;color:#0f172a;margin-bottom:10px}.ur-ch small{font-weight:500;font-size:11.5px;color:#94a3b8}
.ur-donutw{display:flex;align-items:center;justify-content:center;gap:18px;flex-wrap:wrap;min-height:170px}.ur-dn text{font:800 26px Inter,system-ui,sans-serif;fill:#0f172a}.ur-dn text.s{font:600 11px Inter,system-ui,sans-serif;fill:#64748b}.ur-lg{font-size:12.5px;color:#475569;line-height:1.9}.ur-lg i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px}.ur-lg b{color:#0f172a;font-variant-numeric:tabular-nums}
.ur-bar-row{display:grid;grid-template-columns:minmax(120px,210px) 1fr 108px;gap:10px;align-items:center;padding:5px 0;font-size:12.5px;cursor:pointer}.ur-bar-row:hover .bl{color:#1e3a8a}.ur-bar-row .bl{font-weight:600;color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ur-bar-row .bt{height:14px;background:#f1f5f9;border-radius:99px;position:relative;overflow:hidden}.ur-bar-row .bt i{position:absolute;top:0;bottom:0;border-radius:99px}.ur-bar-row .bv{text-align:right;font-weight:800;font-variant-numeric:tabular-nums}
.ur-scroll{overflow-x:auto}.ur-t{width:100%;border-collapse:separate;border-spacing:0 6px;font-size:13.5px;min-width:700px}
.ur-t th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;text-align:left;padding:4px 12px;font-weight:700}.ur-t .r{text-align:right}
.ur-t tbody tr{background:#f8fafc;cursor:pointer;transition:transform .12s,box-shadow .12s}.ur-t tbody tr:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(15,23,42,.1);background:#fff}
.ur-t td{padding:11px 12px;border-top:1px solid #eef2f7;border-bottom:1px solid #eef2f7;font-variant-numeric:tabular-nums}.ur-t td:first-child{border-left:1px solid #eef2f7;border-radius:10px 0 0 10px}.ur-t td:last-child{border-right:1px solid #eef2f7;border-radius:0 10px 10px 0}
.ur-src{display:inline-block;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}.ur-src.cl{background:#dbeafe;color:#1d4ed8}.ur-src.cc{background:#ede9fe;color:#6d28d9}
.ur-desc{font-weight:700;color:#0f172a;display:flex;align-items:center;gap:9px}.ur-desc i{width:28px;height:28px;border-radius:8px;background:#e0f2fe;color:#0369a1;display:flex;align-items:center;justify-content:center;font-size:13px}.ur-desc .lk{font-size:10.5px;font-weight:700;color:#7c3aed;background:#f5f3ff;border-radius:99px;padding:1px 8px}
.ur-bal{font-weight:800;border-radius:8px;padding:3px 10px;display:inline-block;min-width:96px;text-align:right}.ur-bal.pos{background:#fee2e2;color:#b91c1c}.ur-bal.neg{background:#dcfce7;color:#15803d}.ur-bal.zero{background:#f1f5f9;color:#64748b}
.ur-rcv{width:150px}.ur-bar{height:9px;background:#e2e8f0;border-radius:99px;overflow:hidden}.ur-bar i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#22c55e,#16a34a)}.ur-bar i.warn{background:linear-gradient(90deg,#f59e0b,#d97706)}.ur-bar i.low{background:linear-gradient(90deg,#f87171,#dc2626)}
.ur-pc{font-size:11px;color:#64748b;margin-top:2px}.ur-dash{color:#cbd5e1}
.ur-t tfoot td{padding:13px 12px;font-weight:800;font-size:14.5px;background:#0f172a;color:#fff}.ur-t tfoot td:first-child{border-radius:10px 0 0 10px}.ur-t tfoot td:last-child{border-radius:0 10px 10px 0}
.ur-note{padding:4px 6px 16px;color:#64748b;font-size:12px}.ur-note .dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:4px}.dot.pos{background:#ef4444}.dot.neg{background:#22c55e}
.ur-modal{position:fixed;inset:0;z-index:2147483000;background:rgba(15,23,42,.62);display:none;align-items:center;justify-content:center;padding:14px}.ur-modal.open{display:flex}
.ur-mbox{background:#fff;border-radius:14px;width:100%;max-width:1700px;height:96vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 90px rgba(0,0,0,.4)}
.ur-mhead{display:flex;align-items:center;gap:12px;padding:12px 18px;background:linear-gradient(120deg,#0f172a,#1e3a8a);color:#fff}.ur-mhead b{font-size:16px;display:block}.ur-mhead small{opacity:.8;font-size:12px}
.ur-mhead #urMClose{margin-left:auto;width:36px;height:36px;border-radius:50%;border:none;background:#dc2626;color:#fff;font-size:16px;cursor:pointer}.ur-mopen{margin-left:auto;color:#fff;width:34px;height:34px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:rgba(255,255,255,.15);text-decoration:none}.ur-mopen+#urMClose{margin-left:8px}
.ur-mbody{position:relative;flex:1;min-height:0;background:#f9fafb}.ur-mbody iframe{width:100%;height:100%;border:0;background:#fff}.ur-mload{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#6b7280;font-size:14px;background:#f9fafb}.ur-mbody.ready .ur-mload{display:none}
body.ms-dark .ur-card,body.ms-dark .ur-filters,body.ms-dark .ur-t tbody tr{background:#1f2937;border-color:#374151}body.ms-dark .ur-t tbody tr:hover{background:#273244}body.ms-dark .ur-t td{border-color:#374151}body.ms-dark .ur-desc,body.ms-dark .ur-ch,body.ms-dark .ur-dn text,body.ms-dark .ur-lg b{color:#f3f4f6;fill:#f3f4f6}body.ms-dark .ur-bar-row .bt{background:#374151}
@media(max-width:700px){.ur-head{padding:14px}.ur-bar-row{grid-template-columns:1fr 90px}.ur-bar-row .bt{display:none}}
</style>
<script>
(function(){
  var box=document.getElementById('urBox'); if(!box) return;
  var $=function(i){ return document.getElementById(i); }, KEY='ur_filters_v2';
  var MON=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'], thisYear=new Date().getFullYear();
  var fmt=function(n){ return Number(n).toLocaleString('en-US',{maximumFractionDigits:0}); }, neg=function(n){ return n<0?'('+fmt(Math.abs(n))+')':fmt(n); };
  var esc=function(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
  document.body.appendChild($('urModal'));
  function menu(id,items){ var m=$(id); m.innerHTML=items.map(function(it){ return '<label><input type="checkbox" value="'+it[0]+'"> '+it[1]+'</label>'; }).join('')+'<div class="urm-a"><a href="#" data-all>Select all</a> · <a href="#" data-none>Clear</a></div>'; }
  menu('urYMenu',[0,1,2,3,4,5].map(function(k){ return [thisYear-k,String(thisYear-k)]; })); menu('urMMenu',MON.map(function(n,i){ return [i+1,n]; }));
  function picked(id){ return [].filter.call($(id).querySelectorAll('input[type=checkbox]'),function(c){ return c.checked; }).map(function(c){ return c.value; }); }
  function setPicked(id,vals){ [].forEach.call($(id).querySelectorAll('input[type=checkbox]'),function(c){ c.checked=vals.indexOf(c.value)>-1; }); }
  function label(wrap,id,all,txt){ var p=picked(id); wrap.querySelector('.urm-b span').textContent=p.length?(txt?txt(p):p.join(', ')):all; }
  function sync(){ label($('urYM'),'urYMenu','All years'); label($('urMM'),'urMMenu','All months',function(p){ return p.map(function(v){ return MON[v-1]; }).join(', '); }); }
  [['urYM','urYMenu'],['urMM','urMMenu']].forEach(function(x){ var w=$(x[0]); w.querySelector('.urm-b').addEventListener('click',function(e){ e.stopPropagation(); var o=w.classList.contains('open'); [].forEach.call(document.querySelectorAll('.urm.open'),function(m){ m.classList.remove('open'); }); if(!o) w.classList.add('open'); });
    w.addEventListener('change',sync); w.addEventListener('click',function(e){ var a=e.target.closest('[data-all],[data-none]'); if(!a) return; e.preventDefault(); [].forEach.call($(x[1]).querySelectorAll('input[type=checkbox]'),function(c){ c.checked=a.hasAttribute('data-all'); }); sync(); }); });
  document.addEventListener('click',function(e){ if(!e.target.closest('.urm')) [].forEach.call(document.querySelectorAll('.urm.open'),function(m){ m.classList.remove('open'); }); });
  function today(){ var d=new Date(); return new Date(d.getTime()-d.getTimezoneOffset()*60000).toISOString().slice(0,10); }
  /* As at and Date range exclude each other: choosing one switches the other off */
  var MODE='asat';
  function setMode(m){ MODE=m; [].forEach.call(document.querySelectorAll('#urMode button'),function(b){ b.classList.toggle('on',b.dataset.m===m); });
    $('urAsAtIn').disabled=(m==='range'); $('urLAs').classList.toggle('off',m==='range');
    [].forEach.call(document.querySelectorAll('.urr'),function(l){ l.classList.toggle('off',m!=='range'); l.querySelector('input').disabled=(m!=='range'); });
    if(m==='asat'){ $('urFrom').value=''; $('urTo').value=''; if(!$('urAsAtIn').value) $('urAsAtIn').value=today(); } }
  document.getElementById('urMode').addEventListener('click',function(e){ var b=e.target.closest('button'); if(b) setMode(b.dataset.m); });
  function read(){ var y=picked('urYMenu').map(Number), last=$('urLast').checked;
    if(last){ var s={}; (y.length?y:[thisYear]).forEach(function(v){ s[v]=1; s[v-1]=1; }); y=Object.keys(s).map(Number).sort(function(a,b){ return b-a; }); }
    var range=MODE==='range'&&($('urFrom').value||$('urTo').value);
    return {mode:range?'range':'asat',as_at:$('urAsAtIn').value||today(),from:range?$('urFrom').value:'',to:range?$('urTo').value:'',years:y.join(','),months:picked('urMMenu').join(','),last:last?1:0,_y:picked('urYMenu')}; }
  function save(f){ try{ localStorage.setItem(KEY,JSON.stringify({mode:f.mode,as_at:f.as_at,from:f.from,to:f.to,y:f._y,m:f.months?f.months.split(','):[],last:f.last})); }catch(e){} }
  function restore(){ var s=null; try{ s=JSON.parse(localStorage.getItem(KEY)||'null'); }catch(e){}
    $('urAsAtIn').value=today(); if(!s){ setMode('asat'); return; } $('urFrom').value=s.from||''; $('urTo').value=s.to||''; $('urLast').checked=!!s.last; setPicked('urYMenu',s.y||[]); setPicked('urMMenu',s.m||[]); setMode(s.mode==='range'?'range':'asat'); if(s.mode==='range'){ $('urFrom').value=s.from||''; $('urTo').value=s.to||''; } }

  /* ── pieces ── */
  function kpi(icon,lab,val,sub,a,b,s){ return '<div class="ur-k" style="--a:'+a+';--b:'+b+';--s:'+s+'"><span><i class="fa-solid '+icon+'"></i>'+lab+'</span><b>Rs. '+val+'</b><small>'+sub+'</small></div>'; }
  function donut(pct,got,left){
    var r=62,c=2*Math.PI*r,p=Math.max(0,Math.min(100,pct)),dash=c*p/100;
    return '<svg class="ur-dn" width="170" height="170" viewBox="0 0 170 170"><circle cx="85" cy="85" r="'+r+'" fill="none" stroke="#e2e8f0" stroke-width="20"/><circle cx="85" cy="85" r="'+r+'" fill="none" stroke="'+(p>=90?'#16a34a':(p>=60?'#f59e0b':'#ef4444'))+'" stroke-width="20" stroke-linecap="round" stroke-dasharray="'+dash+' '+(c-dash)+'" transform="rotate(-90 85 85)"/><text x="85" y="88" text-anchor="middle">'+Math.round(p)+'%</text><text class="s" x="85" y="106" text-anchor="middle">recovered</text></svg>'
      +'<div class="ur-lg"><div><i style="background:#16a34a"></i>Received <b>Rs. '+fmt(got)+'</b></div><div><i style="background:#ef4444"></i>Still to receive <b>Rs. '+fmt(Math.max(0,left))+'</b></div></div>';
  }
  function bars(rows){
    var items=rows.map(function(r){ return {l:r.label,v:r.balance,r:r}; }).sort(function(a,b){ return Math.abs(b.v)-Math.abs(a.v); }), mx=Math.max.apply(null,items.map(function(i){ return Math.abs(i.v); }).concat([1]));
    return items.map(function(i){ var w=Math.max(2,Math.abs(i.v)/mx*100), col=i.v>0.5?'linear-gradient(90deg,#f87171,#dc2626)':'linear-gradient(90deg,#4ade80,#16a34a)';
      return '<div class="ur-bar-row" '+(i.r.ledger?'data-ledger="'+esc(i.r.ledger)+'" data-title="'+esc(i.l)+' — Ledger"':'data-p="'+esc(i.r.page)+'.php"')+' title="'+esc(i.l)+'"><div class="bl">'+esc(i.l)+'</div><div class="bt"><i style="left:0;width:'+w+'%;background:'+col+'"></i></div><div class="bv" style="color:'+(i.v>0.5?'#b91c1c':'#15803d')+'">'+neg(i.v)+'</div></div>'; }).join('');
  }
  function line(r,base,paidMode){
    var pc=base>0?Math.max(0,Math.min(100,Math.round((r.received||0)/base*100))):0, cls=pc>=95?'':(pc>=60?'warn':'low'), bc=r.balance>0.5?'pos':(r.balance<-0.5?'neg':'zero');
    return '<tr '+(r.ledger?'data-ledger="'+esc(r.ledger)+'" data-title="'+esc(r.label)+' — Ledger"':'data-p="'+esc(r.page)+'.php"')+'><td><span class="ur-src '+(r.source==='Customer Ledger'?'cl':'cc')+'">'+esc(r.source)+'</span></td>'
      +'<td><div class="ur-desc"><i class="fa-solid '+esc(r.icon)+'"></i>'+esc(r.label)+(r.ledger?' <span class="lk">ledger</span>':'')+'</div></td>'
      +'<td class="r">'+(base?fmt(base):'<span class="ur-dash">—</span>')+'</td><td class="r">'+(r.received?fmt(r.received):'<span class="ur-dash">—</span>')+'</td>'
      +'<td class="r"><span class="ur-bal '+bc+'">'+neg(r.balance)+'</span></td>'
      +'<td class="ur-rcv"><div class="ur-bar"><i class="'+cls+'" style="width:'+pc+'%"></i></div><div class="ur-pc">'+pc+'% recovered</div></td></tr>';
  }
  function foot(first,tot){ return '<tr><td colspan="2">Total</td><td class="r">'+fmt(tot[first])+'</td><td class="r">'+fmt(tot.received)+'</td><td class="r">'+neg(tot.balance)+'</td><td></td></tr>'; }
  function err(msg,retry){ box.style.display='block'; $('urAsAt').textContent='could not load'; $('urKpis').innerHTML=''; $('urDonut').innerHTML=''; $('urBars').innerHTML=''; $('urFoot').innerHTML=''; $('urFoot2').innerHTML=''; $('urBody2').innerHTML=''; $('urPaidCard').style.display='none';
    $('urBody').style.opacity='1'; $('urBody').innerHTML='<tr><td colspan="6" style="padding:26px;text-align:center;cursor:default;color:'+(retry?'#b91c1c':'#64748b')+';font-weight:600">'+msg+(retry?' <button type="button" class="ur-go" onclick="document.getElementById(\'urReload\').click()" style="margin-left:10px">Try again</button>':'')+'</td></tr>'; }

  function load(fresh){
    var f=read(); save(f); sync();
    var qs=new URLSearchParams({ajax:'ur_summary',mode:f.mode,as_at:f.as_at,from:f.from,to:f.to,years:f.years,months:f.months}); if(fresh) qs.set('nocache','1');
    $('urBody').style.opacity='.45'; $('urAsAt').textContent='loading…';
    $('urPrint').href='unilever_summary_print.php?'+new URLSearchParams({mode:f.mode,as_at:f.as_at,from:f.from,to:f.to,years:f.years,months:f.months}).toString();
    fetch('index.php?'+qs.toString(),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      box.style.display='block'; $('urPaidCard').style.display='';
      if(!d.success){ err('The summary could not be loaded: '+esc(d.message||'unknown error'),true); return; }
      if(!d.rows.length){ err(d.allowed_any===false?'Your role is not allowed to view any of the ledger pages in this summary. Ask the administrator to give you the View right on them (User Role).':'No ledger data found for these filters yet.',false); return; }
      var info=[]; if(f.years) info.push('year '+f.years.split(',').join(', ')); if(f.months) info.push(f.months.split(',').map(function(v){ return MON[v-1]; }).join(', '));
      var range=d.mode==='range';
      $('urAsAt').textContent=(range?'period '+(d.from||'…')+' → '+(d.to||'…')+' · only the transactions inside the range (balance = debit − credit)':'as at '+d.as_at+' · closing balances')+(info.length?' · '+info.join(' · '):'')+' · live from Customer Ledger & Vendor Ledger'+((d.warn&&d.warn.length)?' · ⚠ '+d.warn.join(' '):'');
      var G=d.groups||{rec:{receivable:0,received:0,balance:0},paid:{paid:0,received:0,balance:0}}, t=d.total, claimed=(G.rec.receivable||0)+(G.paid.paid||0), pct=claimed>0?(t.received/claimed*100):0;
      $('urKpis').innerHTML=kpi('fa-hand-holding-dollar','Receivable',fmt(G.rec.receivable||0),'from Unilever','#2563eb','#1d4ed8','rgba(37,99,235,.35)')
        +kpi('fa-money-bill-transfer','Paid out',fmt(G.paid.paid||0),'claimed back','#f59e0b','#d97706','rgba(217,119,6,.35)')
        +kpi('fa-circle-check','Received',fmt(t.received),Math.round(pct)+'% of everything claimed','#22c55e','#15803d','rgba(21,128,61,.35)')
        +kpi('fa-scale-balanced','Net balance',neg(t.balance),t.balance>0?'Unilever owes us':'settled / over-received',t.balance>0?'#ef4444':'#14b8a6',t.balance>0?'#b91c1c':'#0f766e',t.balance>0?'rgba(185,28,28,.35)':'rgba(15,118,110,.35)');
      $('urDonut').innerHTML=donut(pct,t.received,claimed-t.received); $('urBars').innerHTML=bars(d.rows);
      var rec=d.rows.filter(function(r){ return (r.group||'rec')==='rec'; }), paid=d.rows.filter(function(r){ return r.group==='paid'; });
      $('urBody').style.opacity='1'; $('urBody').innerHTML=rec.map(function(r){ return line(r,r.receivable||0); }).join('')||'<tr><td colspan="6" style="padding:18px;text-align:center;cursor:default;color:#94a3b8">No lines.</td></tr>'; $('urFoot').innerHTML=foot('receivable',G.rec);
      $('urPaidCard').style.display=paid.length?'':'none'; $('urBody2').innerHTML=paid.map(function(r){ return line(r,r.paid||0); }).join(''); $('urFoot2').innerHTML=foot('paid',G.paid);
    }).catch(function(){ err('The summary could not be loaded (the server did not answer correctly).',true); });
  }
  var EMBED='header.header,.header,aside.sidebar,#sidebar,.sidebar-overlay,.ms-edge-toggle,footer.footer,.page-header{display:none!important;}body{padding-top:0!important;margin-top:0!important;}.main-content{margin-left:0!important;margin-top:0!important;padding-top:10px!important;width:100%!important;max-width:100%!important;}';
  function openLedger(url,title){ var fr=$('urMFrame'), body=document.querySelector('.ur-mbody'); body.classList.remove('ready'); $('urMOpen').href=url; $('urMTitle').textContent=title||'Ledger'; $('urMSub').textContent=' ';
    fr.onload=function(){ try{ var d=fr.contentDocument; if(d&&d.head&&!d.getElementById('urcss')){ var st=d.createElement('style'); st.id='urcss'; st.textContent=EMBED; d.head.appendChild(st); } }catch(e){} body.classList.add('ready'); };
    fr.src=url; $('urModal').classList.add('open'); document.body.style.overflow='hidden'; }
  function closeLedger(){ $('urModal').classList.remove('open'); document.body.style.overflow=''; $('urMFrame').removeAttribute('src'); }
  $('urMClose').addEventListener('click',closeLedger); $('urModal').addEventListener('click',function(e){ if(e.target===$('urModal')) closeLedger(); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'&&$('urModal').classList.contains('open')) closeLedger(); });
  box.addEventListener('click',function(e){ var t=e.target.closest('tr[data-ledger],tr[data-p],.ur-bar-row'); if(!t||!box.contains(t)) return; if(t.dataset.ledger) openLedger(t.dataset.ledger,t.dataset.title); else if(t.dataset.p) location.href=t.dataset.p; });
  $('urApply').addEventListener('click',function(){ load(false); }); $('urReload').addEventListener('click',function(){ load(true); });
  $('urReset').addEventListener('click',function(){ $('urAsAtIn').value=today(); $('urFrom').value=''; $('urTo').value=''; $('urLast').checked=false; setPicked('urYMenu',[]); setPicked('urMMenu',[]); setMode('asat'); load(false); });
  restore(); sync(); load(false);
})();
</script>

<?php include 'footer.php'; ?>
