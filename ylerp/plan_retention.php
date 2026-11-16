<?php
/**
 * plan_retention.php — Analysis → Retention & Reactivation (workbook sheet 11)
 * Dormant, declining and blocked outlets with what could be won back: reactivation (dormant), decline recovery and blocked-restorable potential.
 * These are opportunities that need field verification — they are NOT part of the confirmed recommended target.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/plan_lib.php'; require_once __DIR__ . '/plan_model_lib.php';
$M = pm_get($conn, isset($_GET['recalc'])); pl_detail_endpoint($M);
$J = [];
foreach ($M['rows'] as $x) {
    $c = $x['cat'][0]; $bl = strpos($x['cs'], 'BLOCKED') === 0; if ($c !== '7' && !$bl) continue;
    $st = $bl ? $x['cs'] : ($x['decl'] > 0 || ($x['a3'] > 0) ? 'Declining' : 'Dormant');
    $rec = $x['react'] + $x['decl'] + $x['rest']; $dues = $x['odb'] > 0 || $x['ret'] > 0 || $x['sent'] > 0;
    $safe = $bl ? 'No — reinstatement needed first' : ($x['nm'] ? 'No — confirm master status' : ($dues ? 'No — clear dues first' : ($x['risk'] > 40 ? 'No — risk above 40' : 'Yes')));
    $J[] = ['c' => $x['c'], 'n' => $x['n'], 'inm' => $x['nm'] ? 'No' : 'Yes', 'rep' => $x['rep'], 'b' => $x['b'], 'cat' => $x['cat'], 'st' => $st, 'lastp' => $x['lastp'] ? hs_label($x['lastp']) : 'none in 12 cycles', 'tot12' => $x['tot12'],
        'prior9' => $x['prior9'], 'a3' => $x['a3'], 'risk' => $x['risk'], 'odb' => $x['odb'], 'tgt' => $x['tgt'], 'react' => $x['react'], 'decl' => $x['decl'], 'rest' => $x['rest'], 'recov' => round($rec, 2), 'safe' => $safe, 'act' => $x['act'], 'pay' => $x['pay']];
}
ob_end_flush();
include 'header.php';
echo pl_css();
echo pl_frame('Retention & Reactivation', 'fa-person-walking-arrow-loop-left', 'linear-gradient(120deg,#3b0764,#7e22ce 55%,#c084fc)',
    '<b>Dormant</b> (no purchase in the last 3 cycles), <b>declining</b> (3-month average below half of the earlier months) and <b>blocked</b> outlets, with what could be won back: <b>reactivation</b> (share of the prior active-month average), '
    . '<b>decline recovery</b> (back towards the earlier level) and <b>blocked restorable</b> (current run-rate if reinstated). These need field verification and are <b>not</b> in the confirmed recommended target. '
    . '"Safe reactivation" = not blocked, in Party Master, no open dues and risk ≤ 40. Data from the system on <b>' . pl_h($M['built_at']) . '</b>. <a href="?recalc=1"><i class="fa-solid fa-rotate"></i> Recalculate</a>');
echo pl_js();
?>
<script>
(function(){
  var ALL=<?= json_encode($J, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?>, $=function(i){return document.getElementById(i);}, esc=plEsc;
  var stc=function(x){ return x.st==='Dormant'?plChip('Dormant','#7e22ce'):(x.st==='Declining'?plChip('Declining','#ea580c'):plChip(x.st.replace('BLOCKED ',''),'#dc2626')); };
  var P=plPage({data:ALL,exportTitle:'Retention & Reactivation',exportName:'retention_reactivation',exportSub:'Recovery values are opportunities that need field verification; they are not in the confirmed recommended target.',sort:{k:'recov',dir:-1},
    filters:[{k:'st',label:'Status'},{k:'safe',label:'Safe reactivation?'},{k:'inm',label:'In Party Master'},{k:'rep',label:'Sales rep (code · name)'},{k:'b',label:'Beat'}],
    cols:[{k:'c',h:'HUL',t:'s',s1:1,w:18},{k:'n',h:'Customer',t:'s',s2:1,w:28},{k:'inm',h:'In Party Master',t:'s',w:9},{k:'rep',h:'Sales Rep',t:'s',w:28},{k:'b',h:'Beat',t:'s',w:20},{k:'st',h:'Status',t:'s',fmt:stc,w:22},{k:'lastp',h:'Last purchase',t:'s',w:12},
      {k:'tot12',h:'Sales 12 cycles',tot:1},{k:'prior9',h:'Prior months avg'},{k:'a3',h:'Avg 3M'},{k:'risk',h:'Risk',fmt:function(x){return plMeter(x.risk);},xt:'d',w:8},{k:'odb',h:'Open overdue',tot:1},{k:'tgt',h:'Recommended (confirmed)',tot:1},
      {k:'react',h:'Reactivation potential',tot:1},{k:'decl',h:'Decline recovery',tot:1},{k:'rest',h:'Blocked restorable',tot:1},{k:'recov',h:'Total recoverable (verify)',tot:1,fmt:function(x){return '<b style="color:#7e22ce">'+plF0(x.recov)+'</b>';}},
      {k:'safe',h:'Safe reactivation?',t:'s',fmt:function(x){ return x.safe==='Yes'?'<span class="tag t-up">Yes</span>':'<span class="tag t-dn">'+esc(x.safe)+'</span>'; },w:26},{k:'act',h:'Action',t:'s',fmt:function(x){ return '<span style="font-size:12px;color:#475569">'+esc(x.act.length>100?x.act.slice(0,100)+'…':x.act)+'</span>'; },w:70}],
    kpis:function(rows,t){ var c={Dormant:0,Declining:0,Blocked:0}, safe=0, safeV=0; rows.forEach(function(x){ c[x.st.indexOf('BLOCKED')===0?'Blocked':x.st]++; if(x.safe==='Yes'){ safe++; safeV+=x.recov; } });
      $('kp').innerHTML=plKpi([['Dormant',plF0(c.Dormant),'fa-moon',1],['Declining',plF0(c.Declining),'fa-arrow-trend-down',4],['Blocked',plF0(c.Blocked),'fa-ban',6],['Total recoverable (verify)',plF0(t.recov),'fa-hand-holding-heart',5],['Safe to reactivate now',plF0(safe)+' · '+plF0(safeV),'fa-circle-check',3],['Sales lost vs 12-cycle total',plF0(t.tot12),'fa-chart-line',2]]); },
    onRow:function(x){ $('dT').textContent=x.n; $('dS').textContent=x.c+' · '+x.rep+' · '+x.b; $('dB').innerHTML='Loading…'; $('dF').innerHTML='<button class="b o" data-close>Close</button>'; D.open(); plGet(x.c).then(function(m){ $('dB').innerHTML='<div class="sec"><div class="why"><b>'+esc(x.st)+'</b> · last purchase '+esc(x.lastp)+' · total recoverable <b>'+plF0(x.recov)+'</b> · safe reactivation: <b>'+esc(x.safe)+'</b></div></div>'+plDetail(m); }).catch(function(e){ $('dB').textContent=e.message; }); }});
  var D=plModal('dm');
})();
</script>
<?php include 'footer.php'; ?>
