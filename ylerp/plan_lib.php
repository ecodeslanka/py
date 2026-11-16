<?php
/**
 * plan_lib.php — shared by the Planning reports (Development Opportunities · Restricted & High-Risk).
 * Look & feel + virtual-scroll table JS shared by the two planning reports (data comes from plan_model_lib.php).
 */
function pl_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function pl_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
/** keep only the listed keys of every model row (pages send small JSON; the full row is loaded on click) */
function pl_slim(array $rows, array $keys) { $k = array_flip($keys); return array_map(fn($x) => array_intersect_key($x, $k), $rows); }
/** ?ajax=detail&c=HUL → the full model row (risk breakdown, actions, sales history …) */
function pl_detail_endpoint(array $M) {
    if (($_GET['ajax'] ?? '') !== 'detail') return;
    while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); $c = strtoupper(trim((string)($_GET['c'] ?? '')));
    foreach ($M['rows'] as $x) if ($x['c'] === $c) { echo json_encode(['ok' => true, 'x' => $x], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit; }
    echo json_encode(['ok' => false, 'msg' => 'Outlet not found.']); exit;
}
function pl_can_edit() { return function_exists('rbac_can') ? (bool)rbac_can('edit') : true; }
function pl_json($a) { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit; }
function pl_user() { return $_SESSION['username'] ?? ($_SESSION['user_name'] ?? ($_SESSION['full_name'] ?? 'system')); }
/** the common page frame: hero, filter card, KPI strip, optional middle html, virtual table, detail modal */
function pl_frame($title, $icon, $grad, $desc, $middle = '') {
    return '<div class="pl"><div class="hero" style="background:' . $grad . '"><h2><i class="fa-solid ' . $icon . '"></i> ' . $title . '</h2><p>' . $desc . '</p></div>' . $middle
        . '<div class="card" id="flt"></div><div class="kp" id="kp"></div><div id="charts"></div>'
        . '<div class="tw" id="tw"><table><thead id="th"></thead><tbody id="tb"></tbody><tfoot id="tf"></tfoot></table></div></div>'
        . '<div class="plm" id="dm"><div class="bx"><div class="hd" style="background:' . $grad . '"><div><b id="dT" style="font-size:18px"></b><div id="dS" style="font-size:12.5px;opacity:.85"></div></div><button type="button" data-close>&times;</button></div><div class="bd" id="dB"></div><div class="ft" id="dF"></div></div></div>';
}
/** saved Unilever targets of a month split to outlets (same rule as Target Scenarios): a rep's target over that rep's outlets in proportion to
 *  the chosen scenario; the company total not covered by rep targets over the outlets of reps without a target. Returns [code => amount] */
function pl_outlet_targets($conn, array $rows, $ym, $sc = 'mod') {
    $T = 0; $R = []; $r = pl_q($conn, "SELECT rep, amount FROM plan_targets WHERE ym = '" . mysqli_real_escape_string($conn, $ym) . "'");
    while ($r && ($x = mysqli_fetch_assoc($r))) { if ($x['rep'] === '') $T = (float)$x['amount']; else $R[$x['rep']] = (float)$x['amount']; }
    if (!$T && !$R) return [];
    $by = []; $restBase = 0; foreach ($rows as $x) { $k = $x['repc'] ?? $x['rep']; $by[$k] = ($by[$k] ?? 0) + $x[$sc]; if (empty($R[$k])) $restBase += $x[$sc]; }
    $rest = max(0, $T - array_sum($R)); $out = [];
    foreach ($rows as $x) { $k = $x['repc'] ?? $x['rep']; $v = !empty($R[$k]) ? ($by[$k] > 0 ? $x[$sc] * $R[$k] / $by[$k] : 0) : ($T > 0 && $restBase > 0 ? $x[$sc] * $rest / $restBase : 0); $out[$x['c']] = round($v, -2); }
    return $out;
}
/** shared look (scoped to .pl) */
function pl_css() { return <<<'CSS'
<style>
.pl{padding:16px;font-family:'Inter',system-ui,sans-serif;color:#0f172a}
.pl .hero{border-radius:16px;padding:20px 24px;margin-bottom:14px;color:#fff;position:relative;overflow:hidden;box-shadow:0 10px 30px -12px rgba(15,23,42,.55)}
.pl .hero:after{content:"";position:absolute;right:-60px;top:-60px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.18),transparent 70%)}
.pl .hero h2{margin:0 0 4px;font-size:24px;font-weight:800;letter-spacing:-.3px}.pl .hero p{margin:0;font-size:13px;opacity:.88;max-width:1100px;line-height:1.55}.pl .hero a{color:#e0e7ff}
.pl .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px 16px;margin-bottom:14px}
.pl .row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.pl .fld{display:flex;flex-direction:column;gap:3px}.pl .fld label{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
.pl input,.pl select{padding:8px 10px;border:1.5px solid #cbd5e1;border-radius:10px;font:inherit;font-size:13px;max-width:210px;background:#fff}.pl input:focus,.pl select:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.18)}
.pl .btn{padding:9px 16px;border:none;border-radius:10px;background:linear-gradient(135deg,#1e3a8a,#4f46e5);color:#fff;font:600 13px inherit;cursor:pointer;text-decoration:none;display:inline-flex;gap:6px;align-items:center;box-shadow:0 4px 12px -4px rgba(79,70,229,.6)}.pl .btn.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe;box-shadow:none}.pl .btn.g{background:linear-gradient(135deg,#059669,#16a34a)}
.pl .kp{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,190px),1fr));gap:12px;margin-bottom:14px}.pl .k{border-radius:14px;padding:12px 16px;color:#fff;position:relative;overflow:hidden}.pl .k i{position:absolute;right:12px;top:10px;font-size:26px;opacity:.28}.pl .k span{font-size:11px;text-transform:uppercase;font-weight:700;display:block;opacity:.88;letter-spacing:.4px}.pl .k b{font-size:20px;white-space:nowrap}
.pl .k1{background:linear-gradient(135deg,#4f46e5,#7c3aed)}.pl .k2{background:linear-gradient(135deg,#0ea5e9,#2563eb)}.pl .k3{background:linear-gradient(135deg,#10b981,#059669)}.pl .k4{background:linear-gradient(135deg,#f59e0b,#ea580c)}.pl .k5{background:linear-gradient(135deg,#ec4899,#be185d)}.pl .k6{background:linear-gradient(135deg,#ef4444,#b91c1c)}.pl .k7{background:linear-gradient(135deg,#64748b,#334155)}
.pl .grid3{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,330px),1fr));gap:14px;margin-bottom:14px}.pl .grid3 .card{margin:0}.pl .ct{font-weight:800;margin-bottom:8px;font-size:13.5px}
.pl .hb{display:flex;align-items:center;gap:8px;margin:5px 0;font-size:12px}.pl .hb .lb{width:165px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#334155}.pl .hb .tr{flex:1;background:#f1f5f9;border-radius:99px;height:12px;overflow:hidden}.pl .hb .tr i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#818cf8,#4f46e5)}.pl .hb .vl{width:92px;text-align:right;font-weight:700;font-variant-numeric:tabular-nums}
.pl .tw{overflow:auto;border:1px solid #e2e8f0;border-radius:14px;background:#fff;box-shadow:0 8px 24px -14px rgba(15,23,42,.35)}.pl table{border-collapse:separate;border-spacing:0;font-size:12.5px;width:max-content;min-width:100%}
.pl th{background:linear-gradient(180deg,#1e3a8a,#1e2f6e);color:#fff;padding:10px 10px;text-align:right;position:sticky;top:0;z-index:2;white-space:nowrap;font-size:11.5px;letter-spacing:.3px;cursor:pointer;user-select:none}.pl th.l,.pl td.l{text-align:left}.pl th:hover{background:#312e81}.pl th .ar{opacity:.7;margin-left:3px;font-size:10px}
.pl td{padding:5px 9px;border-bottom:1px solid #f1f5f9;text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.pl tr.r{height:38px;cursor:pointer}.pl tr.r td{height:38px;box-sizing:border-box}.pl tr.r.ev td{background-color:#fbfcff}.pl tr.r:hover td{background:#eef2ff!important}
.pl td.s1,.pl th.s1{position:sticky;left:0;z-index:3;background:#fff;min-width:150px;font-weight:700;color:#1e3a8a}.pl th.s1{background:#1e3a8a;color:#fff}.pl td.s2,.pl th.s2{position:sticky;left:150px;z-index:3;background:#fff;min-width:200px;max-width:200px;overflow:hidden;text-overflow:ellipsis;box-shadow:6px 0 8px -6px rgba(15,23,42,.25)}.pl th.s2{background:#1e3a8a}.pl tr.r.ev td.s1,.pl tr.r.ev td.s2{background:#fbfcff}
.pl .vs td{padding:0!important;border:0!important;background:none!important;box-shadow:none!important}.pl .z{color:#cbd5e1}
.pl tfoot td{background:linear-gradient(180deg,#fde68a,#fcd34d);font-weight:800;position:sticky;bottom:0;color:#451a03;z-index:2}
.pl .chip{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;color:var(--c);background:color-mix(in srgb,var(--c) 14%,#fff);border:1px solid color-mix(in srgb,var(--c) 35%,#fff)}
.pl .pm{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;background:#f1f5f9;color:#334155}.pl .pm-credit{background:#ede9fe;color:#5b21b6}.pl .pm-cash{background:#dcfce7;color:#166534}.pl .pm-cheque{background:#fef3c7;color:#92400e}
.pl .meter{display:inline-block;width:56px;height:8px;border-radius:99px;background:#e2e8f0;vertical-align:middle;margin-right:6px;overflow:hidden}.pl .meter i{display:block;height:100%;border-radius:99px}
.pl .pl2{display:inline-block;padding:1px 8px;border-radius:99px;font-size:11.5px;font-weight:700;background:#dcfce7;color:#166534}
.plm{position:fixed;inset:0;background:rgba(15,23,42,.6);display:none;align-items:center;justify-content:center;z-index:2147483000;padding:14px;font-family:'Inter',system-ui,sans-serif}.plm.open{display:flex}
.plm .bx{background:#fff;border-radius:16px;width:100%;max-width:880px;max-height:92vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px -20px rgba(15,23,42,.6)}.plm .hd{padding:14px 20px;color:#fff;display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.plm .hd button{background:none;border:none;color:#fff;font-size:26px;line-height:1;cursor:pointer}
.plm .bd{padding:16px 20px;overflow:auto}.plm .gr{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px}.plm .gr div{background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:8px 12px}.plm .gr small{display:block;font-size:10.5px;color:#64748b;text-transform:uppercase;font-weight:700;letter-spacing:.3px}.plm .gr b{font-size:14px}
.plm .sec{margin-top:16px}.plm .sec h4{margin:0 0 8px;font-size:14px;color:#1e3a8a;display:flex;gap:8px;align-items:center}.plm table.rk{width:100%;border-collapse:collapse;font-size:12.5px}.plm table.rk th{background:#eef2ff;color:#1e3a8a;text-align:left;padding:6px 8px;font-size:11px;text-transform:uppercase;letter-spacing:.3px}.plm table.rk td{padding:7px 8px;border-bottom:1px solid #f1f5f9;vertical-align:top}.plm table.rk td.n{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.plm .sc{height:10px;border-radius:99px;background:#e2e8f0;overflow:hidden;min-width:80px}.plm .sc i{display:block;height:100%;border-radius:99px}.plm .gauge{position:relative;height:16px;border-radius:99px;background:linear-gradient(90deg,#16a34a 0 20%,#0ea5e9 20% 40%,#f59e0b 40% 60%,#ea580c 60% 80%,#dc2626 80% 100%);margin:10px 0 4px}.plm .gauge b{position:absolute;top:-6px;width:4px;height:28px;border-radius:3px;background:#0f172a;box-shadow:0 0 0 2px #fff}
.plm .glab{display:flex;justify-content:space-between;font-size:10.5px;color:#64748b}.plm .why{background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:10px 14px;font-size:13px;line-height:1.55;color:#1e3a8a}.plm ol.st{margin:0;padding-left:20px;font-size:13px;line-height:1.7}.plm ul.ac{margin:0;padding-left:0;list-style:none}.plm ul.ac li{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:8px 12px;margin-bottom:6px;font-size:13px;line-height:1.5;color:#78350f;display:flex;gap:8px}.plm ul.ac li i{color:#d97706;margin-top:3px}
.plm .mb{display:flex;align-items:flex-end;gap:4px;height:70px;margin-bottom:14px}.plm .mb div{flex:1;background:linear-gradient(180deg,#818cf8,#4f46e5);border-radius:4px 4px 2px 2px;min-height:2px}.plm .mb div.nb{background:repeating-linear-gradient(45deg,#e2e8f0,#e2e8f0 3px,#f1f5f9 3px,#f1f5f9 6px);height:100%!important}.plm .ml{display:flex;gap:4px;font-size:9.5px;color:#64748b}.plm .ml span{flex:1;text-align:center}
.plm .fm label{display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin:10px 0 4px}.plm .fm input,.plm .fm select,.plm .fm textarea{width:100%;padding:9px 11px;border:1.5px solid #cbd5e1;border-radius:10px;font:inherit;font-size:14px;box-sizing:border-box}.plm .fm .two{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.plm .ft{padding:12px 20px;background:#f8fafc;display:flex;gap:10px;justify-content:flex-end;align-items:center}.plm .ft .b{padding:9px 18px;border:none;border-radius:10px;background:linear-gradient(135deg,#1e3a8a,#4f46e5);color:#fff;font:600 13px 'Inter',system-ui,sans-serif;cursor:pointer}.plm .ft .b.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe}.plm .ft .b.g{background:linear-gradient(135deg,#059669,#16a34a)}.plm .ft .msg{margin-right:auto;font-size:12.5px}
.pl .tag{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:800}.pl .t-up{background:#dcfce7;color:#166534}.pl .t-dn{background:#fee2e2;color:#991b1b}.pl .t-eq{background:#e0e7ff;color:#3730a3}.pl .t-bl{background:#1f2937;color:#fff}.pl .t-wa{background:#fef3c7;color:#92400e}
.pl input.ti{width:130px;text-align:right;padding:6px 8px;border:1.5px solid #fcd34d;background:#fffbeb;border-radius:8px;font:600 13px inherit}
.plm .act{margin-top:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:12px 14px;font-size:13.5px;line-height:1.55;color:#78350f}
</style>
CSS; }

/** shared JS helpers + virtual-scroll table. Usage: var T = PLVT({tw, tb, cols, rh, rowHtml}); T.setRows(arr); */
function pl_js() { return <<<'JS'
<script>
(function(w){
  w.plEsc=function(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); };
  w.plF0=function(v){ return Math.round(v||0).toString().replace(/\B(?=(\d{3})+(?!\d))/g,','); };
  w.plF2=function(v){ return (Math.round((v||0)*100)/100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,','); };
  var PAL=['#6366f1','#0ea5e9','#10b981','#f59e0b','#ec4899','#8b5cf6','#14b8a6','#ef4444'];
  w.plChip=function(t,c){ var h=0; t=String(t==null?'':t); for(var i=0;i<t.length;i++) h=(h*31+t.charCodeAt(i))|0; return '<span class="chip" style="--c:'+(c||PAL[Math.abs(h)%PAL.length])+'">'+plEsc(t)+'</span>'; };
  w.plPm=function(p){ return p?'<span class="pm pm-'+plEsc(String(p).toLowerCase())+'">'+plEsc(p)+'</span>':'<span class="z">—</span>'; };
  w.PLVT=function(o){ var rows=[],lo=-1,hi=-1,tick=false,tw=o.tw,tb=o.tb,RH=o.rh||38,BUF=14,C=o.cols;
    function draw(f){ var n=rows.length; if(!n){ tb.innerHTML='<tr><td class="l" colspan="'+C+'" style="padding:26px;color:#9ca3af;text-align:center">Nothing matches these filters.</td></tr>'; lo=hi=-1; return; }
      var top=tw.scrollTop,vh=tw.clientHeight,s=Math.max(0,Math.floor(top/RH)-BUF),e=Math.min(n,Math.ceil((top+vh)/RH)+BUF); if(!f&&s===lo&&e===hi) return; lo=s; hi=e; var p=[];
      if(s>0) p.push('<tr class="vs" style="height:'+(s*RH)+'px"><td colspan="'+C+'"></td></tr>'); for(var i=s;i<e;i++) p.push(o.rowHtml(rows[i],i)); if(e<n) p.push('<tr class="vs" style="height:'+((n-e)*RH)+'px"><td colspan="'+C+'"></td></tr>'); tb.innerHTML=p.join(''); }
    tw.addEventListener('scroll',function(){ if(tick) return; tick=true; requestAnimationFrame(function(){ tick=false; draw(false); }); },{passive:true}); w.addEventListener('resize',function(){ draw(true); });
    tw.style.height=(o.height||'70vh'); tw.style.maxHeight='none';
    return {setRows:function(r){ rows=r; tw.scrollTop=0; draw(true); },redraw:function(){ draw(true); },rows:function(){ return rows; }}; };
  var FAC=[['Credit policy violations',25],['Cheque returns',20],['Blacklisting status',20],['Payment delays & overdue',15],['Cheques sent back',10],['Bill cancellation',5],['Billing irregularity',5]];
  w.plDetail=function(x,facts){
    var h='', col=function(v){ return v<=20?'#16a34a':(v<=40?'#0ea5e9':(v<=60?'#f59e0b':(v<=80?'#ea580c':'#dc2626'))); };
    h+='<div class="gr"><div><small>Category</small><b>'+plEsc(x.cat)+'</b></div><div><small>Credit status</small><b>'+plEsc(x.cs)+'</b></div><div><small>Pay mode</small><b>'+plEsc(x.pay||'—')+'</b></div><div><small>Collection priority</small><b>'+plEsc(x.prio)+'</b></div></div>';
    if(facts) h+=facts;
    /* 1 · risk score */
    h+='<div class="sec"><h4><i class="fa-solid fa-gauge-high"></i> How the risk score was made — <span style="color:'+col(x.risk)+'">'+x.risk.toFixed(1)+' / 100 · '+plEsc(x.band)+'</span></h4><div class="gauge"><b style="left:calc('+Math.min(100,x.risk)+'% - 2px)"></b></div><div class="glab"><span>0 A · Low</span><span>20 B · Moderate</span><span>40 C · Elevated</span><span>60 D · High</span><span>80 E · Critical</span></div>';
    if(x.rk){ var tot=0; h+='<table class="rk" style="margin-top:10px"><thead><tr><th>Factor</th><th>What was measured</th><th>Sub-score</th><th class="n" style="text-align:right">Weight</th><th style="text-align:right">Points</th></tr></thead><tbody>';
      for(var i=0;i<FAC.length;i++){ var sub=x.rk[i][0], pts=FAC[i][1]*sub; tot+=pts; h+='<tr><td><b>'+FAC[i][0]+'</b></td><td style="color:#475569">'+plEsc(x.rk[i][1])+'</td><td><div class="sc"><i style="width:'+Math.round(sub*100)+'%;background:'+col(sub*100)+'"></i></div><small>'+Math.round(sub*100)+'%</small></td><td class="n">× '+FAC[i][1]+'%</td><td class="n"><b>'+pts.toFixed(1)+'</b></td></tr>'; }
      h+='<tr><td colspan="4" style="text-align:right"><b>Risk score = sum of points</b></td><td class="n"><b style="color:'+col(tot)+'">'+tot.toFixed(1)+'</b></td></tr></tbody></table>'; }
    h+='</div>';
    /* 2 · category */
    if(x.why) h+='<div class="sec"><h4><i class="fa-solid fa-layer-group"></i> Why category “'+plEsc(x.cat)+'”</h4><div class="why">'+plEsc(x.why)+'</div></div>';
    /* 3 · target */
    h+='<div class="sec"><h4><i class="fa-solid fa-bullseye"></i> How the recommended target was calculated</h4><ol class="st">'
      +'<li>Baseline = 20% × Avg 12M + 30% × Avg 6M + 35% × Avg 3M + 15% × latest month = <b>'+plF0(x.base)+'</b> <span style="color:#64748b">(Avg 12M '+plF0(x.a12)+' · 6M '+plF0(x.a6)+' · 3M '+plF0(x.a3)+')</span></li>'
      +'<li>Growth % = <b>'+(x.gr*100).toFixed(1)+'%</b> → growth potential = baseline × growth = <b>'+plF0(x.gpot)+'</b></li>'
      +'<li>Capacity (credit-adjusted ceiling for this category) = <b>'+plF0(x.cap)+'</b></li>'
      +'<li>Target = ROUND('+(x.c78?'capacity':'MIN(baseline + growth, capacity)')+', −2) = <b style="color:#047857">'+plF0(x.tgt)+'</b></li>'
      +'<li>Additional sales = target − baseline = <b>'+(x.tgt-x.base>=0?'+':'')+plF0(x.tgt-x.base)+'</b>'+(x.med?' · channel median baseline '+plF0(x.med)+(x.pgo?' · peer-gap opportunity 25% × (median − target) = <b>'+plF0(x.pgo)+'</b>':''):'')+'</li></ol></div>';
    /* 4 · actions */
    var ac=x.acts&&x.acts.length?x.acts:(x.act?[x.act]:[]);
    if(ac.length) h+='<div class="sec"><h4><i class="fa-solid fa-list-check"></i> Actions</h4><ul class="ac">'+ac.map(function(a){ return '<li><i class="fa-solid fa-circle-check"></i><span>'+plEsc(a)+'</span></li>'; }).join('')+'</ul></div>';
    if(x.conf) h+='<div class="why" style="background:#fef2f2;border-color:#fecaca;color:#7f1d1d;margin-top:12px"><b><i class="fa-solid fa-triangle-exclamation"></i> Status conflict:</b> '+plEsc(x.conf)+'</div>';
    /* 5 · monthly sales */
    if(x.v&&x.v.length){ var mx=0; x.v.forEach(function(v){ if(v>mx) mx=v; });
      h+='<div class="sec"><h4><i class="fa-solid fa-chart-column"></i> Monthly sales (sales cycles)</h4><div class="mb">'+x.v.map(function(v){ return v==null?'<div class="nb" title="before first purchase"></div>':'<div style="height:'+Math.max(3,(mx>0?v/mx*100:0))+'%" title="'+plF0(v)+'"></div>'; }).join('')+'</div></div>'; }
    return h; };
  /* generic report page: filters + KPI + virtual table + sort + footer totals + Excel export (plan_export.php) */
  w.plPage=function(cfg){
    var $=function(i){return document.getElementById(i);}, ALL=cfg.data, cur=ALL, F={q:''}, st=cfg.sort||{k:cfg.cols[0].k,dir:1}, C=cfg.cols;
    var fb='<div class="fld"><label>Search</label><input id="pfQ" type="search" placeholder="'+(cfg.searchPh||'HUL, customer, rep, beat')+'"></div>';
    (cfg.filters||[]).forEach(function(f,i){ var s={},a=[]; ALL.forEach(function(x){ var v=x[f.k]; if(v!==''&&v!=null&&!s[v]){ s[v]=1; a.push(v); } }); a.sort(); F[f.k]='';
      fb+='<div class="fld"><label>'+f.label+'</label><select id="pf'+i+'"><option value="">All</option>'+a.map(function(v){return '<option>'+plEsc(v)+'</option>';}).join('')+'</select></div>'; });
    fb+='<button class="btn o" id="pfR" type="button">Reset</button><button class="btn g" id="pfX" type="button"><i class="fa-solid fa-file-excel"></i> Export Excel</button>';
    $(cfg.flt||'flt').innerHTML='<div class="row">'+fb+'</div>';
    var th='<tr>'+C.map(function(c){ return '<th class="'+(c.s1?'s1 ':'')+(c.s2?'s2 ':'')+(c.t==='s'?'l':'')+'" data-k="'+c.k+'" data-t="'+(c.t==='s'?'s':'n')+'"'+(c.tip?' title="'+plEsc(c.tip)+'"':'')+'>'+c.h+'</th>'; }).join('')+'</tr>';
    $(cfg.th||'th').innerHTML=th;
    var T=PLVT({tw:$(cfg.tw||'tw'),tb:$(cfg.tb||'tb'),cols:C.length,rh:cfg.rowH||38,height:cfg.height||'64vh',rowHtml:function(x,i){
      return '<tr class="r'+(i%2?' ev':'')+'" data-i="'+i+'">'+C.map(function(c){ var v=x[c.k], h=c.fmt?c.fmt(x):(c.t==='s'?plEsc(v==null?'':v):(v==null||v===''?'<span class="z">—</span>':plF0(v)));
        return '<td class="'+(c.s1?'s1 ':'')+(c.s2?'s2 ':'')+(c.t==='s'?'l ':'')+(c.cls||'')+'"'+(c.s2?' title="'+plEsc(v)+'"':'')+'>'+h+'</td>'; }).join('')+'</tr>'; }});
    function tot(){ var t={}; C.forEach(function(c){ if(c.tot){ var s=0; cur.forEach(function(x){ s+=+(x[c.k]||0); }); t[c.k]=s; } });
      $(cfg.tf||'tf').innerHTML='<tr>'+C.map(function(c,i){ var cls=(c.s1?'s1 ':'')+(c.s2?'s2 ':'')+(c.t==='s'?'l':''); var z=(c.s1||c.s2)?' style="z-index:4"':'';
        if(i===0) return '<td class="'+cls+'"'+z+'>TOTAL</td>'; if(i===1) return '<td class="'+cls+'"'+z+'>'+plF0(cur.length)+' rows</td>';
        return '<td class="'+cls+'">'+(c.tot?plF0(t[c.k]):'')+'</td>'; }).join('')+'</tr>'; return t; }
    function apply(){ var q=F.q.toLowerCase(), keys=cfg.search||['c','n','rep','b'];
      cur=ALL.filter(function(x){ for(var i=0;i<(cfg.filters||[]).length;i++){ var f=cfg.filters[i]; if(F[f.k]!==''&&String(x[f.k])!==F[f.k]) return false; }
        if(cfg.extraFilter&&!cfg.extraFilter(x)) return false; if(!q) return true; return keys.map(function(k){return x[k]||'';}).join(' ').toLowerCase().indexOf(q)>=0; });
      cur.sort(function(a,b){ var k=st.k,x=a[k],y=b[k]; if(x==null) x=-1e18; if(y==null) y=-1e18; return (typeof x==='number'&&typeof y==='number'?(x-y):String(x).localeCompare(String(y)))*st.dir; });
      var t=tot(); if(cfg.kpis) cfg.kpis(cur,t); T.setRows(cur); }
    plSortable($(cfg.th||'th'),function(){return cur;},function(r){ cur=r; T.setRows(cur); },st);
    var tm=null; $('pfQ').oninput=function(){ F.q=this.value; clearTimeout(tm); tm=setTimeout(apply,120); };
    (cfg.filters||[]).forEach(function(f,i){ $('pf'+i).onchange=function(){ F[f.k]=this.value; apply(); }; });
    $('pfR').onclick=function(){ F.q=''; $('pfQ').value=''; (cfg.filters||[]).forEach(function(f,i){ F[f.k]=''; $('pf'+i).value=''; }); apply(); };
    $('pfX').onclick=function(){ var cols=C.filter(function(c){return c.x!==false;});
      var body={title:cfg.exportTitle||document.title,sub:cfg.exportSub||'',name:cfg.exportName||'report',cols:cols.map(function(c){return {h:c.xh||c.h.replace(/<[^>]*>/g,''),t:c.xt||(c.t==='s'?'s':'n'),w:c.w||(c.t==='s'?22:14),tot:!!c.tot};}),
        rows:cur.map(function(x){ return cols.map(function(c){ var v=c.ex?c.ex(x):x[c.k]; return v==null?'':v; }); })};
      var f=document.createElement('form'); f.method='POST'; f.action='plan_export.php'; f.target='_self'; var i=document.createElement('input'); i.type='hidden'; i.name='payload'; i.value=JSON.stringify(body); f.appendChild(i); document.body.appendChild(f); f.submit(); f.remove(); };
    $(cfg.tb||'tb').addEventListener('click',function(e){ if(e.target.closest('button,a,input,select')) return; var tr=e.target.closest('tr.r'); if(!tr||!cfg.onRow) return; var x=cur[+tr.dataset.i]; if(x) cfg.onRow(x); });
    apply();
    return {apply:apply,rows:function(){return cur;},all:function(){return ALL;},redraw:function(){ T.redraw(); tot(); if(cfg.kpis) cfg.kpis(cur,tot()); }};
  };
  w.plModal=function(id,grad){ var m=document.getElementById(id); document.body.appendChild(m); var close=function(){ m.classList.remove('open'); };
    m.addEventListener('click',function(e){ if(e.target===m||e.target.closest('[data-close]')) close(); }); document.addEventListener('keydown',function(e){ if(e.key==='Escape') close(); }); return {open:function(){ m.classList.add('open'); },close:close}; };
  w.plPost=function(url,data){ var fd=new FormData(); Object.keys(data).forEach(function(k){ fd.append(k,data[k]); }); return fetch(url,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.text();}).then(function(t){ try{ return JSON.parse(t); }catch(e){ throw new Error('Not saved — you may not have the Edit right on this page.'); } }); };
  w.plGet=function(code){ return fetch(location.pathname+'?ajax=detail&c='+encodeURIComponent(code),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){ if(!j.ok) throw new Error(j.msg||'Not found'); return j.x; }); };
  w.plTag=function(t){ var c=/increase|relax|candidate|set limit|add/i.test(t)?'t-up':(/block|freeze|cash on delivery only|change mode/i.test(t)?'t-bl':(/decrease|tighten|keep blocked/i.test(t)?'t-dn':(/review|watch|reinstat/i.test(t)?'t-wa':'t-eq'))); return '<span class="tag '+c+'">'+plEsc(t)+'</span>'; };
  w.plMeter=function(v){ var c=v<=20?'#16a34a':(v<=40?'#0ea5e9':(v<=60?'#f59e0b':(v<=80?'#ea580c':'#dc2626'))); return '<span class="meter"><i style="width:'+Math.min(100,v)+'%;background:'+c+'"></i></span>'+(+v).toFixed(1); };
  w.plKpi=function(items){ return items.map(function(k,i){ return '<div class="k k'+(k[3]||((i%7)+1))+'"><i class="fa-solid '+(k[2]||'fa-chart-simple')+'"></i><span>'+k[0]+'</span><b>'+k[1]+'</b></div>'; }).join(''); };
  /* sortable header: <th data-k="key" data-t="n|s"> */
  w.plSortable=function(thead,get,set,state){ thead.addEventListener('click',function(e){ var th=e.target.closest('th[data-k]'); if(!th) return; var k=th.dataset.k,num=th.dataset.t==='n'; state.dir=(state.k===k&&state.dir===1)?-1:1; state.k=k;
      var r=get().slice(); r.sort(function(a,b){ var x=a[k],y=b[k]; if(x==null) x=num?-1e18:''; if(y==null) y=num?-1e18:''; return (num?(x-y):String(x).localeCompare(String(y)))*state.dir; }); set(r);
      thead.querySelectorAll('.ar').forEach(function(a){a.remove();}); th.insertAdjacentHTML('beforeend','<span class="ar">'+(state.dir===1?'▲':'▼')+'</span>'); }); };
})(window);
</script>
JS; }
