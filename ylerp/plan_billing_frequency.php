<?php
/**
 * plan_billing_frequency.php — Analysis → Billing Frequency
 * Recommended bills per month (workbook sheet 10): bills needed at today's invoice size = target ÷ average invoice; a low-risk outlet may get ONE
 * more bill than its 3-month rhythm, risk > 20 never more bills than now, blocked / dormant / control outlets per their rules. Weekly split W1–W4.
 * A manual billing plan (bills per month, weekly split, billing days, effective date) can be set per outlet and overrides the recommendation.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/plan_lib.php'; require_once __DIR__ . '/plan_model_lib.php';
pl_q($conn, "CREATE TABLE IF NOT EXISTS customer_billing_plan (t_code VARCHAR(60) PRIMARY KEY, bills_per_month TINYINT NOT NULL DEFAULT 0, week_split VARCHAR(20), billing_days VARCHAR(40), effective_from DATE NULL, note VARCHAR(400), updated_by VARCHAR(100), updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$e = fn($v) => mysqli_real_escape_string($conn, (string)$v);
if (in_array($_POST['action'] ?? '', ['save_plan', 'remove_plan'], true)) {
    if (!pl_can_edit()) pl_json(['ok' => false, 'msg' => 'You do not have the Edit right on this page.']);
    $code = strtoupper(trim((string)($_POST['code'] ?? ''))); if ($code === '') pl_json(['ok' => false, 'msg' => 'No outlet.']);
    if ($_POST['action'] === 'remove_plan') { pl_q($conn, "DELETE FROM customer_billing_plan WHERE t_code = '{$e($code)}'"); pl_json(['ok' => true, 'msg' => 'Manual plan removed — the recommendation applies again.']); }
    $n = (int)($_POST['bills'] ?? -1); if ($n < 0 || $n > 12) pl_json(['ok' => false, 'msg' => 'Bills per month must be 0–12.']);
    $w = array_map('intval', array_slice(explode(',', (string)($_POST['weeks'] ?? '')), 0, 4)); while (count($w) < 4) $w[] = 0;
    if (array_sum($w) !== $n) pl_json(['ok' => false, 'msg' => 'The weekly split (W1–W4) must add up to ' . $n . ' bills.']);
    $days = implode(',', array_intersect(explode(',', (string)($_POST['days'] ?? '')), ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']));
    $eff = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['eff'] ?? '') ? "'" . $_POST['eff'] . "'" : 'NULL';
    $ok = pl_q($conn, "REPLACE INTO customer_billing_plan (t_code, bills_per_month, week_split, billing_days, effective_from, note, updated_by) VALUES ('{$e($code)}', $n, '" . implode(',', $w) . "', '{$e($days)}', $eff, '{$e(mb_substr(trim((string)($_POST['note'] ?? '')), 0, 400))}', '{$e(pl_user())}')");
    pl_json($ok ? ['ok' => true, 'msg' => 'Billing plan saved.', 'plan' => ['n' => $n, 'w' => implode(',', $w), 'd' => $days, 'e' => trim($eff, "'"), 'note' => trim((string)($_POST['note'] ?? ''))]] : ['ok' => false, 'msg' => 'Could not save.']);
}
$M = pm_get($conn, isset($_GET['recalc'])); pl_detail_endpoint($M);
$S = hs_settings($conn); $nextStart = hs_range(hs_add($S['first_cycle'], $S['months'] + 1), $S['start_day'])[0];
$plans = []; $r = pl_q($conn, "SELECT * FROM customer_billing_plan"); while ($r && ($x = mysqli_fetch_assoc($r))) $plans[strtoupper(trim($x['t_code']))] = ['n' => (int)$x['bills_per_month'], 'w' => $x['week_split'], 'd' => $x['billing_days'], 'e' => $x['effective_from'], 'note' => $x['note'], 'by' => $x['updated_by'], 'at' => $x['updated_at']];
$J = pl_slim($M['rows'], ['c', 'n', 'rep', 'b', 'cat', 'risk', 'pay', 'cs', 'b12', 'b3', 'inv', 'tgt', 'bill_cur', 'bill_rec', 'bill_w', 'bill_inv', 'bill_g', 'bill_when', 'cnt']);
foreach ($J as &$x) { $p = $plans[$x['c']] ?? null; $x['plan'] = $p; $x['eff_n'] = $p ? $p['n'] : $x['bill_rec']; $x['dir'] = $x['bill_rec'] > $x['bill_cur'] ? 'Increase' : ($x['bill_rec'] < $x['bill_cur'] ? 'Decrease' : 'Same'); $x['manual'] = $p ? 'Manual plan' : 'Recommended'; $x['bill_when'] = $x['bill_when'] === 'From the next sales cycle' ? 'From ' . $nextStart : $x['bill_when']; } unset($x);
ob_end_flush();
include 'header.php';
echo pl_css();
echo pl_frame('Billing Frequency Optimisation', 'fa-calendar-week', 'linear-gradient(120deg,#082f49,#0369a1 55%,#38bdf8)',
    'How many bills per month each outlet should get and in which weeks. <b>Recommended</b> = bills needed at today\'s invoice size (target ÷ average invoice of the last 12 cycles); a low-risk outlet may get <b>one</b> more bill than its 3-month rhythm, '
    . '<b>risk &gt; 20 never more bills than now</b> (more bills ≠ more credit); control & collect outlets bill again only after the previous bill is paid; blocked / dormant outlets per their rules. '
    . 'Increases start with the next sales cycle (<b>' . $nextStart . '</b>), decreases now. <b>Click an outlet</b> to set a manual plan (bills, weekly split, billing days, start date) — it overrides the recommendation. Data from the system on <b>' . pl_h($M['built_at']) . '</b>. <a href="?recalc=1"><i class="fa-solid fa-rotate"></i> Recalculate</a>');
echo pl_js();
?>
<script>
(function(){
  var ALL=<?= json_encode(array_values($J), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?>, CAN=<?= pl_can_edit() ? 'true' : 'false' ?>, NEXT=<?= json_encode($nextStart) ?>, $=function(i){return document.getElementById(i);}, esc=plEsc;
  var wk=function(w){ return (w||[]).map(function(v,i){ return '<span class="tag '+(v?'t-eq':'')+'" style="margin-right:2px;'+(v?'':'background:#f1f5f9;color:#94a3b8')+'">W'+(i+1)+(v>1?'×'+v:'')+'</span>'; }).join(''); };
  var P=plPage({data:ALL,exportTitle:'Billing Frequency Optimisation',exportName:'billing_frequency',exportSub:'Recommended and manual bills per month with the weekly split.',sort:{k:'tgt',dir:-1},
    filters:[{k:'dir',label:'Change'},{k:'manual',label:'Plan'},{k:'cat',label:'Category'},{k:'pay',label:'Pay mode'},{k:'rep',label:'Sales rep'},{k:'b',label:'Beat'}],
    cols:[{k:'c',h:'HUL',t:'s',s1:1,w:18},{k:'n',h:'Customer',t:'s',s2:1,w:30},{k:'rep',h:'Sales Rep',t:'s',w:24},{k:'b',h:'Beat',t:'s',w:20},{k:'cat',h:'Category',t:'s',w:22},{k:'risk',h:'Risk',fmt:function(x){return plMeter(x.risk);},xt:'d',w:8},{k:'pay',h:'Pay',t:'s',fmt:function(x){return plPm(x.pay);},w:9},
      {k:'b12',h:'Bills / month 12M',fmt:function(x){return x.b12.toFixed(2);},xt:'d',w:10},{k:'b3',h:'Bills / month 3M',fmt:function(x){return x.b3.toFixed(2);},xt:'d',w:10},{k:'inv',h:'Avg invoice 12M',w:13},{k:'tgt',h:'Monthly target',tot:1},
      {k:'bill_cur',h:'Current rhythm',xt:'i',w:9},{k:'bill_rec',h:'Recommended bills',fmt:function(x){ var d=x.bill_rec-x.bill_cur; return '<b>'+x.bill_rec+'</b>'+(d?' <small style="color:'+(d>0?'#15803d':'#b91c1c')+'">('+(d>0?'+':'')+d+')</small>':''); },xt:'i',tot:1,w:11},
      {k:'bill_w',h:'Weekly split',t:'s',fmt:function(x){return wk(x.bill_w);},ex:function(x){return (x.bill_w||[]).join(' / ');},w:14},{k:'bill_inv',h:'Proposed avg invoice',w:14},
      {k:'eff_n',h:'Plan in use',fmt:function(x){ return x.plan?'<span class="tag t-wa">Manual: '+x.plan.n+' / month</span> '+wk((x.plan.w||'').split(',').map(Number)):'<span class="z">recommended</span>'; },ex:function(x){return x.plan?('Manual '+x.plan.n+' ('+x.plan.w+')'+(x.plan.d?' '+x.plan.d:'')+(x.plan.e?' from '+x.plan.e:'')):'Recommended '+x.bill_rec;},xt:'s',w:26},
      {k:'bill_when',h:'When',t:'s',w:16},{k:'bill_g',h:'Billing guidance',t:'s',fmt:function(x){ return '<span style="font-size:12px;color:#475569">'+esc(x.bill_g)+'</span>'; },w:60}],
    kpis:function(rows,t){ var up=0,dn=0,man=0,cur=0; rows.forEach(function(x){ if(x.dir==='Increase') up++; if(x.dir==='Decrease') dn++; if(x.plan) man++; cur+=x.bill_cur; });
      $('kp').innerHTML=plKpi([['Outlets',plF0(rows.length),'fa-store',1],['Bills / month now',plF0(cur),'fa-receipt',2],['Bills / month recommended',plF0(t.bill_rec),'fa-calendar-check',3],['More bills',plF0(up),'fa-arrow-up',3],['Fewer bills',plF0(dn),'fa-arrow-down',6],['Manual plans',plF0(man),'fa-hand',4]]); },
    onRow:function(x){ $('dT').textContent=x.n; $('dS').textContent=x.c+' · '+x.rep+' · '+x.b; $('dB').innerHTML='Loading…'; D.open();
      $('dF').innerHTML=CAN?'<span class="msg" id="bm"></span>'+(x.plan?'<button class="b o" id="bRem">Remove manual plan</button>':'')+'<button class="b o" data-close>Close</button><button class="b" id="bSave"><i class="fa-solid fa-floppy-disk"></i> Save plan</button>':'<span class="msg">Edit right needed to set a plan.</span><button class="b o" data-close>Close</button>';
      plGet(x.c).then(function(m){ var pl=x.plan, w=pl?(pl.w||'0,0,0,0').split(',').map(Number):x.bill_w.slice(), days=pl&&pl.d?pl.d.split(','):[], DAYS=['Mon','Tue','Wed','Thu','Fri','Sat'];
        var hist='<div class="sec"><h4><i class="fa-solid fa-chart-column"></i> Bills per sales cycle (last 12)</h4><div class="mb">'+(m.cnt||[]).map(function(v){ var mx=Math.max.apply(null,(m.cnt||[1]).concat([1])); return '<div style="height:'+Math.max(3,v/mx*100)+'%" title="'+v+' bills"></div>'; }).join('')+'</div></div>';
        var form='<div class="sec"><h4><i class="fa-solid fa-lightbulb"></i> Recommendation</h4><div class="why">Now about <b>'+x.bill_cur+'</b> bills / month (12M avg '+x.b12.toFixed(2)+', 3M avg '+x.b3.toFixed(2)+', avg invoice '+plF0(x.inv)+'). Recommended <b>'+x.bill_rec+'</b> bills / month '+wk(x.bill_w)+' · avg invoice '+plF0(x.bill_inv)+' · <b>When:</b> '+esc(x.bill_when)+'<br>'+esc(x.bill_g)+'</div></div>'
          +(CAN?'<div class="sec fm"><h4><i class="fa-solid fa-pen-to-square"></i> Manual billing plan</h4><div class="two"><div><label>Bills per month</label><input id="bN" type="number" min="0" max="12" value="'+(pl?pl.n:x.bill_rec)+'"></div><div><label>Start from</label><input id="bE" type="date" value="'+esc(pl&&pl.e?pl.e:NEXT)+'"></div></div>'
            +'<label>Weekly split (W1 · W2 · W3 · W4 — must add up to the bills)</label><div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px">'+[0,1,2,3].map(function(i){ return '<input class="bw" type="number" min="0" max="6" value="'+(w[i]||0)+'">'; }).join('')+'</div>'
            +'<label>Billing days</label><div style="display:flex;gap:10px;flex-wrap:wrap">'+DAYS.map(function(d){ return '<label style="text-transform:none;font-size:13px;margin:0;display:flex;gap:4px;align-items:center"><input type="checkbox" class="bd" value="'+d+'"'+(days.indexOf(d)>=0?' checked':'')+' style="width:auto"> '+d+'</label>'; }).join('')+'</div>'
            +'<label>Note</label><input id="bNote" type="text" maxlength="400" value="'+esc(pl&&pl.note?pl.note:'')+'" placeholder="e.g. visit after market day; COD only"></div>':'');
        $('dB').innerHTML=form+hist+plDetail(m);
        if(!CAN) return;
        var split=function(){ var n=+$('bN').value||0, pat={0:[0,0,0,0],1:[1,0,0,0],2:[1,0,1,0],3:[1,1,1,0],4:[1,1,1,1],5:[2,1,1,1],6:[2,1,2,1],7:[2,2,2,1],8:[2,2,2,2]}[n]; if(pat) document.querySelectorAll('.bw').forEach(function(el,i){ el.value=pat[i]; }); };
        $('bN').oninput=split;
        $('bSave').onclick=function(){ var ws=[].map.call(document.querySelectorAll('.bw'),function(el){return +el.value||0;}), ds=[].filter.call(document.querySelectorAll('.bd'),function(el){return el.checked;}).map(function(el){return el.value;});
          $('bm').style.color='#475569'; $('bm').textContent='Saving…'; plPost('plan_billing_frequency.php',{action:'save_plan',code:x.c,bills:$('bN').value,weeks:ws.join(','),days:ds.join(','),eff:$('bE').value,note:$('bNote').value}).then(function(j){
            $('bm').style.color=j.ok?'#15803d':'#b91c1c'; $('bm').textContent=j.msg; if(j.ok){ x.plan=j.plan; x.manual='Manual plan'; x.eff_n=j.plan.n; P.redraw(); } }).catch(function(e){ $('bm').style.color='#b91c1c'; $('bm').textContent=e.message; }); };
        if($('bRem')) $('bRem').onclick=function(){ if(!confirm('Remove the manual plan?')) return; plPost('plan_billing_frequency.php',{action:'remove_plan',code:x.c}).then(function(j){ $('bm').style.color=j.ok?'#15803d':'#b91c1c'; $('bm').textContent=j.msg; if(j.ok){ x.plan=null; x.manual='Recommended'; x.eff_n=x.bill_rec; P.redraw(); } }); };
      }).catch(function(e){ $('dB').textContent=e.message; }); }});
  var D=plModal('dm');
})();
</script>
<?php include 'footer.php'; ?>
