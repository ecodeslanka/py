<?php
/**
 * plan_sr_action_plans.php — Analysis → SR Action Plans (workbook sheet 8)
 * Per sales rep (by REP CODE) and beat: every outlet's historical average, Unilever target (saved targets split to the outlet), recommended target,
 * payment & risk profile, recommended bills and the weekly LKR plan, collection priority, category, the development / collection action and approval.
 * Collection actions come BEFORE new credit billing. Pick a rep and print the plan for the field.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/plan_lib.php'; require_once __DIR__ . '/plan_model_lib.php';
$M = pm_get($conn, isset($_GET['recalc'])); pl_detail_endpoint($M);
$S = hs_settings($conn); $openYm = hs_add($S['first_cycle'], $S['months']);
$UT = pl_outlet_targets($conn, $M['rows'], $openYm, 'mod');
$J = [];
foreach ($M['rows'] as $x) {
    $w = $x['bill_w'] ?? [0, 0, 0, 0]; $L = max(1, array_sum($w)); $t = $x['tgt'];
    $J[] = ['repc' => $x['repc'], 'rep' => $x['rep'], 'b' => $x['b'], 'c' => $x['c'], 'n' => $x['n'], 'pch' => $x['pch'], 'ch' => $x['ch'], 'a12' => $x['a12'], 'ut' => $UT[$x['c']] ?? null, 'tgt' => $t,
        'gpct' => $x['a12'] > 0 ? round($t / $x['a12'] - 1, 4) : null, 'pay' => $x['pay'], 'risk' => $x['risk'], 'band' => $x['band'], 'cs' => $x['cs'], 'viol' => $x['viol'], 'rcq' => $x['rcq'], 'sbn' => $x['sent'] > 0 ? 1 : 0,
        'canc' => '', 'b12' => $x['b12'], 'bill_rec' => $x['bill_rec'], 'w1' => round($t * $w[0] / $L, -2), 'w2' => round($t * $w[1] / $L, -2), 'w3' => round($t * $w[2] / $L, -2), 'w4' => round($t * $w[3] / $L, -2),
        'prio' => $x['prio'], 'cat' => $x['cat'], 'act' => $x['act'], 'appr' => $x['appr'], 'odb' => $x['odb'], 'uns' => round($x['ret'] + $x['sent'], 2)];
}
usort($J, fn($a, $b) => [$a['rep'], $a['b'], $a['prio'], -$a['tgt']] <=> [$b['rep'], $b['b'], $b['prio'], -$b['tgt']]);
ob_end_flush();
include 'header.php';
echo pl_css();
$mid = '<div class="card" id="repCard" style="display:none"></div>';
echo pl_frame('SR Action Plans', 'fa-route', 'linear-gradient(120deg,#1e1b4b,#6d28d9 55%,#a78bfa)',
    'One plan per <b>sales rep (by rep code)</b> and beat: target, payment profile, recommended bills with the <b>weekly LKR plan</b>, collection priority and the action for every outlet. <b>Collection actions come before new credit billing.</b> '
    . 'Unilever target = the saved ' . hs_label($openYm) . ' targets (Target Entry) split to the outlet in proportion to the moderate scenario. Pick a rep to see the rep summary and <b>print</b> the plan. Data from the system on <b>' . pl_h($M['built_at']) . '</b>. <a href="?recalc=1"><i class="fa-solid fa-rotate"></i> Recalculate</a>', $mid);
echo pl_js();
?>
<style>@media print{ #sidebar,.sidebar,header,.topbar,nav,#flt,.hero a,#charts,.btn{display:none!important} .pl .tw{max-height:none!important;height:auto!important;overflow:visible!important} .pl table{font-size:9.5px} .pl td,.pl th{padding:3px 5px} body{background:#fff} }</style>
<script>
(function(){
  var ALL=<?= json_encode($J, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?>, $=function(i){return document.getElementById(i);}, esc=plEsc;
  var pr=function(x){ var c={'1':'#dc2626','2':'#ea580c','3':'#f59e0b','4':'#0ea5e9','5':'#64748b'}[x.prio.charAt(0)]; return plChip(x.prio,c); };
  var P=plPage({data:ALL,exportTitle:'SR Action Plans',exportName:'sr_action_plans',exportSub:'Per sales rep (rep code) and beat. Collection actions come before new credit billing.',sort:{k:'rep',dir:1},
    filters:[{k:'rep',label:'Sales rep (code · name)'},{k:'b',label:'Beat'},{k:'prio',label:'Collection priority'},{k:'cat',label:'Category'},{k:'pay',label:'Pay mode'}],
    cols:[{k:'rep',h:'Sales Rep',t:'s',w:30},{k:'b',h:'Beat',t:'s',w:22},{k:'c',h:'HUL Code',t:'s',s1:0,w:18,fmt:function(x){return '<b style="color:#1e3a8a">'+esc(x.c)+'</b>';}},{k:'n',h:'Customer',t:'s',w:28},{k:'pch',h:'Primary Channel',t:'s',w:20},{k:'ch',h:'Secondary Channel',t:'s',w:20},
      {k:'a12',h:'Historical avg',tot:1},{k:'ut',h:'Unilever target',tot:1,fmt:function(x){return x.ut==null?'<span class="z">—</span>':'<b>'+plF0(x.ut)+'</b>';}},{k:'tgt',h:'Recommended target',tot:1,fmt:function(x){return '<b style="color:#047857">'+plF0(x.tgt)+'</b>';}},
      {k:'gpct',h:'Growth %',fmt:function(x){return x.gpct==null?'<span class="z">—</span>':(x.gpct*100).toFixed(1)+'%';},xt:'p',w:9},{k:'pay',h:'Pay',t:'s',fmt:function(x){return plPm(x.pay);},w:9},{k:'risk',h:'Risk',fmt:function(x){return plMeter(x.risk);},xt:'d',w:8},{k:'band',h:'Risk band',t:'s',w:13},{k:'cs',h:'Blacklist status',t:'s',w:20},
      {k:'viol',h:'Violations',xt:'i',w:8},{k:'rcq',h:'Chq returns',xt:'i',w:8},{k:'uns',h:'Unsettled chq',tot:1},{k:'odb',h:'Overdue',tot:1},{k:'b12',h:'Bills / month (12M)',fmt:function(x){return (+x.b12).toFixed(1);},xt:'d',w:9},{k:'bill_rec',h:'Rec. bills',xt:'i',tot:1,w:8},
      {k:'w1',h:'Week 1',tot:1},{k:'w2',h:'Week 2',tot:1},{k:'w3',h:'Week 3',tot:1},{k:'w4',h:'Week 4',tot:1},{k:'prio',h:'Collection priority',t:'s',fmt:pr,w:16},{k:'cat',h:'Category',t:'s',w:22},
      {k:'act',h:'Action',t:'s',fmt:function(x){ return '<span style="font-size:12px;color:#475569">'+esc(x.act.length>110?x.act.slice(0,110)+'…':x.act)+'</span>'; },w:70},{k:'appr',h:'Approval',t:'s',w:24}],
    kpis:function(rows,t){ var col=rows.filter(function(x){return x.prio.charAt(0)<'3';}).length;
      $('kp').innerHTML=plKpi([['Outlets',plF0(rows.length),'fa-store',1],['Historical avg / month',plF0(t.a12),'fa-chart-line',2],['Recommended target',plF0(t.tgt),'fa-bullseye',3],['Unilever target (split)',plF0(t.ut||0),'fa-flag',4],['Collect first (Critical / High)',plF0(col),'fa-hand-holding-dollar',6],['Overdue + unsettled cheques',plF0((t.odb||0)+(t.uns||0)),'fa-triangle-exclamation',5]]);
      var reps={}; rows.forEach(function(x){ reps[x.rep]=1; }); var k=Object.keys(reps);
      if(k.length===1){ var r=k[0], beats={}; rows.forEach(function(x){ var b=beats[x.b]=beats[x.b]||{n:0,tgt:0,col:0}; b.n++; b.tgt+=x.tgt; if(x.prio.charAt(0)<'3') b.col++; });
        $('repCard').style.display=''; $('repCard').innerHTML='<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><div><div style="font-size:12px;color:#64748b;text-transform:uppercase;font-weight:700">Sales rep</div><div style="font-size:20px;font-weight:800;color:#1e3a8a">'+esc(r)+'</div></div><button class="btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print this plan</button></div>'
          +'<div class="tw" style="margin-top:10px"><table style="width:100%"><thead><tr><th class="l">Beat</th><th>Outlets</th><th>Recommended target</th><th>Collect first</th></tr></thead><tbody>'+Object.keys(beats).sort().map(function(b){ var o=beats[b]; return '<tr><td class="l"><b>'+esc(b)+'</b></td><td>'+o.n+'</td><td>'+plF0(o.tgt)+'</td><td>'+(o.col?'<b style="color:#b91c1c">'+o.col+'</b>':'<span class="z">0</span>')+'</td></tr>'; }).join('')+'</tbody></table></div>'; }
      else { $('repCard').style.display='none'; } },
    onRow:function(x){ $('dT').textContent=x.n; $('dS').textContent=x.c+' · '+x.rep+' · '+x.b; $('dB').innerHTML='Loading…'; $('dF').innerHTML='<button class="b o" data-close>Close</button>'; D.open();
      plGet(x.c).then(function(m){ $('dB').innerHTML='<div class="sec"><h4><i class="fa-solid fa-calendar-week"></i> This month\'s plan</h4><table class="rk"><thead><tr><th>Week 1</th><th>Week 2</th><th>Week 3</th><th>Week 4</th><th>Total</th><th>Unilever target</th></tr></thead><tbody><tr><td class="n">'+plF0(x.w1)+'</td><td class="n">'+plF0(x.w2)+'</td><td class="n">'+plF0(x.w3)+'</td><td class="n">'+plF0(x.w4)+'</td><td class="n"><b>'+plF0(x.tgt)+'</b></td><td class="n">'+(x.ut==null?'—':plF0(x.ut))+'</td></tr></tbody></table></div>'+plDetail(m); }).catch(function(e){ $('dB').textContent=e.message; }); }});
  var D=plModal('dm');
})();
</script>
<?php include 'footer.php'; ?>
