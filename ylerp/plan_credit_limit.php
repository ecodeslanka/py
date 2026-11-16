<?php
/**
 * plan_credit_limit.php — Analysis → Credit Limit Management
 * Recommended limit = monthly target × (policy days + 7) ÷ 30 × band factor (A 1.20 · B 1.00 · C 0.75 · D/E 0), rounded to 1,000 — the credit needed to bill
 * the target on the outlet's terms. Increase only for low risk with no violations; decrease for risk > 40, a recent violation, unsettled cheques or a limit far above need.
 * Limits are changed by hand (customers.credit_limit) and every change is logged.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/plan_lib.php'; require_once __DIR__ . '/plan_model_lib.php';
pl_q($conn, "CREATE TABLE IF NOT EXISTS plan_credit_limit_log (id INT AUTO_INCREMENT PRIMARY KEY, t_code VARCHAR(60), old_limit DECIMAL(16,2), new_limit DECIMAL(16,2), recommended DECIMAL(16,2), reason VARCHAR(500), done_by VARCHAR(100), done_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY k_t (t_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$e = fn($v) => mysqli_real_escape_string($conn, (string)$v);
if (($_POST['action'] ?? '') === 'save_limit') {
    if (!pl_can_edit()) pl_json(['ok' => false, 'msg' => 'You do not have the Edit right on this page.']);
    $code = strtoupper(trim((string)($_POST['code'] ?? ''))); $v = str_replace(',', '', trim((string)($_POST['limit'] ?? '')));
    if (!is_numeric($v) || (float)$v < 0) pl_json(['ok' => false, 'msg' => 'The limit must be a number (0 or more).']);
    $c = pl_q($conn, "SHOW COLUMNS FROM customers LIKE 'credit_limit'"); if ($c && !mysqli_num_rows($c)) pl_q($conn, "ALTER TABLE customers ADD COLUMN credit_limit DECIMAL(15,2) NULL");
    $r = pl_q($conn, "SELECT COALESCE(credit_limit,0) FROM customers WHERE UPPER(TRIM(t_code)) = '{$e($code)}' LIMIT 1"); $old = $r ? mysqli_fetch_row($r) : null; if (!$old) pl_json(['ok' => false, 'msg' => 'Customer not found in Customers.']);
    if (!pl_q($conn, "UPDATE customers SET credit_limit = " . round((float)$v, 2) . " WHERE UPPER(TRIM(t_code)) = '{$e($code)}'")) pl_json(['ok' => false, 'msg' => 'Could not save.']);
    pl_q($conn, "INSERT INTO plan_credit_limit_log (t_code, old_limit, new_limit, recommended, reason, done_by) VALUES ('{$e($code)}', " . (float)$old[0] . ", " . round((float)$v, 2) . ", " . (float)($_POST['rec'] ?? 0) . ", '{$e(mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 500))}', '{$e(pl_user())}')");
    pl_json(['ok' => true, 'msg' => 'Credit limit saved: ' . number_format((float)$v, 2), 'limit' => round((float)$v, 2)]);
}
if (($_GET['ajax'] ?? '') === 'log') { $o = []; $r = pl_q($conn, "SELECT * FROM plan_credit_limit_log WHERE t_code = '{$e(strtoupper(trim((string)($_GET['c'] ?? ''))))}' ORDER BY id DESC LIMIT 20"); while ($r && ($x = mysqli_fetch_assoc($r))) $o[] = $x; pl_json(['ok' => true, 'log' => $o]); }
$M = pm_get($conn, isset($_GET['recalc'])); pl_detail_endpoint($M);
$J = pl_slim($M['rows'], ['c', 'n', 'rep', 'b', 'cat', 'risk', 'band', 'pay', 'cs', 'days', 'cl', 'ob', 'obn', 'odb', 'odd', 'viol', 'ret', 'sent', 'tgt', 'min', 'pol_mode', 'pol_days', 'lim_act', 'lim_prop', 'lim_util', 'lim_why', 'lim_when', 'cv', 'a12', 'a6', 'a3', 'l']);
foreach ($J as &$x) { $x['diff'] = round($x['lim_prop'] - $x['cl'], 2); $x['uncq'] = round($x['ret'] + $x['sent'], 2); } unset($x);
ob_end_flush();
include 'header.php';
echo pl_css();
echo pl_frame('Credit Limit Management', 'fa-credit-card', 'linear-gradient(120deg,#022c22,#047857 55%,#34d399)',
    'Recommended limit = <b>monthly target × (policy days + 7) ÷ 30 × band factor</b> (A 1.20 · B 1.00 · C 0.75 · D/E 0), rounded to 1,000 — the credit an outlet needs to buy its target on its terms. '
    . '<b>Increase</b> only for low risk (≤ 20) with no violations · <b>decrease</b> for risk &gt; 40, a violation in the last 60 days, unsettled cheques, or a limit far above need · <b>freeze to 0</b> for blocked / cash-on-delivery outlets. '
    . 'Utilisation = open (unpaid) bills ÷ limit. <b>Click an outlet</b> to change its limit by hand (saved to the customer and logged). Data from the system on <b>' . pl_h($M['built_at']) . '</b>. <a href="?recalc=1"><i class="fa-solid fa-rotate"></i> Recalculate</a>');
echo pl_js();
?>
<script>
(function(){
  var ALL=<?= json_encode(array_values($J), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?>, CAN=<?= pl_can_edit() ? 'true' : 'false' ?>, $=function(i){return document.getElementById(i);}, esc=plEsc;
  var ut=function(v){ if(v==null) return '<span class="z">—</span>'; var p=v*100; return '<span class="meter"><i style="width:'+Math.min(100,p)+'%;background:'+(p>90?'#dc2626':(p>70?'#f59e0b':'#16a34a'))+'"></i></span>'+p.toFixed(0)+'%'; };
  var P=plPage({data:ALL,exportTitle:'Credit Limit Management',exportName:'credit_limits',exportSub:'Recommended credit limits from target, policy days and risk band.',sort:{k:'diff',dir:-1},
    extraFilter:function(x){ return !($('onlyCredit')&&$('onlyCredit').checked)||x.cl>0||x.lim_prop>0; },
    filters:[{k:'lim_act',label:'Recommendation'},{k:'band',label:'Risk band'},{k:'pay',label:'Pay mode'},{k:'rep',label:'Sales rep'},{k:'cat',label:'Category'}],
    cols:[{k:'c',h:'HUL',t:'s',s1:1,w:18},{k:'n',h:'Customer',t:'s',s2:1,w:30},{k:'rep',h:'Sales Rep',t:'s',w:24},{k:'b',h:'Beat',t:'s',w:20},{k:'pay',h:'Pay',t:'s',fmt:function(x){return plPm(x.pay);},w:9},{k:'days',h:'Policy days',xt:'i',w:8},
      {k:'risk',h:'Risk',fmt:function(x){return plMeter(x.risk);},xt:'d',w:8},{k:'band',h:'Band',t:'s',w:13},{k:'cl',h:'Current limit',tot:1,w:14},{k:'ob',h:'Open bills',tot:1},{k:'lim_util',h:'Utilisation',fmt:function(x){return ut(x.lim_util);},xt:'p',w:10},
      {k:'odb',h:'Overdue',tot:1},{k:'uncq',h:'Unsettled cheques',tot:1},{k:'cv',h:'Volatility (CV)',fmt:function(x){return plCv(x.cv);},xt:'d',w:11},{k:'a12',h:'12-month avg sales',tot:1},{k:'a6',h:'6-month avg sales',tot:1},{k:'a3',h:'3-month avg sales',tot:1},{k:'l',h:'Last month sales',tot:1},{k:'tgt',h:'Monthly target',tot:1},{k:'lim_prop',h:'Recommended limit',tot:1,fmt:function(x){return '<b style="color:#047857">'+plF0(x.lim_prop)+'</b>';},w:15},
      {k:'diff',h:'Change',fmt:function(x){ return x.diff?'<b style="color:'+(x.diff>0?'#15803d':'#b91c1c')+'">'+(x.diff>0?'+':'')+plF0(x.diff)+'</b>':'<span class="z">—</span>'; },tot:1},
      {k:'lim_act',h:'Recommendation',t:'s',fmt:function(x){return plTag(x.lim_act);},w:22},{k:'lim_when',h:'When',t:'s',w:18},{k:'lim_why',h:'Why',t:'s',fmt:function(x){ return '<span style="font-size:12px;color:#475569">'+esc(x.lim_why)+'</span>'; },w:60}],
    kpis:function(rows,t){ var c={}; rows.forEach(function(x){ var k=x.lim_act.split(' ')[0]; c[k]=(c[k]||0)+1; });
      $('kp').innerHTML=plKpi([['Outlets',plF0(rows.length),'fa-store',1],['Current limits',plF0(t.cl),'fa-credit-card',2],['Recommended limits',plF0(t.lim_prop),'fa-bullseye',3],['Increase',plF0(c.Increase||0),'fa-arrow-up',3],['Decrease / freeze',plF0((c.Decrease||0)),'fa-arrow-down',6],['Set limit (none yet)',plF0(c.Set||0),'fa-plus',4],['Maintain',plF0(c.Maintain||0),'fa-equals',7]]); },
    onRow:function(x){ $('dT').textContent=x.n; $('dS').textContent=x.c+' · '+x.rep+' · '+x.b; $('dB').innerHTML='Loading…'; D.open();
      $('dF').innerHTML=CAN?'<span class="msg" id="lm"></span><button class="b o" data-close>Close</button><button class="b" id="lSave"><i class="fa-solid fa-floppy-disk"></i> Save limit</button>':'<span class="msg">Edit right needed to change the limit.</span><button class="b o" data-close>Close</button>';
      plGet(x.c).then(function(m){
        var calc='<div class="sec"><h4><i class="fa-solid fa-calculator"></i> How the recommended limit was worked out</h4><ol class="st"><li>Monthly target = <b>'+plF0(Math.max(x.tgt,x.min))+'</b></li><li>Terms = '+esc(x.pol_mode)+', <b>'+x.pol_days+'</b> policy days (+7 days buffer) → '+(Math.max(7,x.pol_days)+7)+' / 30 of a month on credit</li>'
          +'<li>Band '+esc(x.band)+' → factor <b>'+(x.risk<=20?'1.20':(x.risk<=40?'1.00':(x.risk<=60?'0.75':'0')))+'</b></li><li>Recommended limit = <b style="color:#047857">'+plF0(x.lim_prop)+'</b> (current '+plF0(x.cl)+', open bills '+plF0(x.ob)+' in '+x.obn+' bill(s), utilisation '+(x.lim_util==null?'—':(x.lim_util*100).toFixed(0)+'%')+')</li></ol>'
          +'<div class="why" style="margin-top:8px"><b>'+esc(x.lim_act)+'</b> · <b>When:</b> '+esc(x.lim_when)+'<br>'+esc(x.lim_why)+'</div></div>'
          +(CAN?'<div class="sec fm"><h4><i class="fa-solid fa-pen-to-square"></i> Change the credit limit</h4><div class="two"><div><label>New credit limit (LKR)</label><input id="lV" type="number" min="0" step="1000" value="'+(x.lim_prop||x.cl||0)+'"></div><div><label>Recommended</label><input type="text" disabled value="'+plF0(x.lim_prop)+'"></div></div><label>Reason (saved in the log)</label><input id="lR" type="text" maxlength="500" placeholder="e.g. reviewed with sales manager"></div>':'')
          +'<div class="sec"><h4><i class="fa-solid fa-clock-rotate-left"></i> Limit history</h4><div id="lLog" style="font-size:12.5px;color:#64748b">Loading…</div></div>';
        $('dB').innerHTML=calc+plDetail(m);
        if(CAN) $('lSave').onclick=function(){ $('lm').style.color='#475569'; $('lm').textContent='Saving…'; plPost('plan_credit_limit.php',{action:'save_limit',code:x.c,limit:$('lV').value,rec:x.lim_prop,reason:$('lR').value}).then(function(j){
            $('lm').style.color=j.ok?'#15803d':'#b91c1c'; $('lm').textContent=j.msg; if(j.ok){ x.cl=j.limit; x.diff=Math.round((x.lim_prop-x.cl)*100)/100; x.lim_util=x.cl>0?x.ob/x.cl:null; P.redraw(); } }).catch(function(e){ $('lm').style.color='#b91c1c'; $('lm').textContent=e.message; }); };
        fetch('plan_credit_limit.php?ajax=log&c='+encodeURIComponent(x.c),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){ $('lLog').innerHTML=(j.log||[]).length?'<table class="rk"><thead><tr><th>When</th><th>By</th><th style="text-align:right">Old</th><th style="text-align:right">New</th><th style="text-align:right">Recommended</th><th>Reason</th></tr></thead><tbody>'+j.log.map(function(l){ return '<tr><td>'+esc(l.done_at)+'</td><td>'+esc(l.done_by)+'</td><td class="n">'+plF0(+l.old_limit)+'</td><td class="n"><b>'+plF0(+l.new_limit)+'</b></td><td class="n">'+plF0(+l.recommended)+'</td><td>'+esc(l.reason)+'</td></tr>'; }).join('')+'</tbody></table>':'No changes made from this page yet.'; });
      }).catch(function(e){ $('dB').textContent=e.message; }); }});
  var D=plModal('dm');
  /* "credit outlets only" switch next to the filters */
  var row=document.querySelector('#flt .row'); row.insertAdjacentHTML('beforeend','<label style="display:flex;gap:6px;align-items:center;font-size:13px;font-weight:600;color:#334155"><input type="checkbox" id="onlyCredit" checked style="width:auto"> Credit / cheque outlets only</label>');
  $('onlyCredit').onchange=function(){ P.apply(); }; P.apply();
})();
</script>
<?php include 'footer.php'; ?>
