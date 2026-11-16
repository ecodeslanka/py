<?php
/**
 * plan_credit_policy.php — Analysis → Credit Policy Recommendations
 * Per outlet: keep / tighten / relax the pay mode and policy (credit) days, or block temporarily — from the risk score, the sales volatility (CV)
 * and the payment behaviour. Any change is made by hand in the pop-up (saved to the customer record and logged).
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/plan_lib.php'; require_once __DIR__ . '/plan_model_lib.php';
pl_q($conn, "CREATE TABLE IF NOT EXISTS plan_policy_log (id INT AUTO_INCREMENT PRIMARY KEY, t_code VARCHAR(60), old_mode VARCHAR(20), new_mode VARCHAR(20), old_days INT, new_days INT, old_block VARCHAR(40), new_block VARCHAR(40), reason VARCHAR(500), done_by VARCHAR(100), done_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY k_t (t_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (($_POST['action'] ?? '') === 'apply') {
    if (!pl_can_edit()) pl_json(['ok' => false, 'msg' => 'You do not have the Edit right on this page.']);
    $e = fn($v) => mysqli_real_escape_string($conn, (string)$v); $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    $mode = strtolower(trim((string)($_POST['mode'] ?? ''))); $days = trim((string)($_POST['days'] ?? '0')); $block = (string)($_POST['block'] ?? 'keep'); $reason = trim((string)($_POST['reason'] ?? ''));
    if (!in_array($mode, ['cash', 'credit', 'cheque'], true)) pl_json(['ok' => false, 'msg' => 'Pick cash, credit or cheque.']);
    if (!ctype_digit($days) || (int)$days > 365) pl_json(['ok' => false, 'msg' => 'Policy days must be 0–365.']);
    $r = pl_q($conn, "SELECT * FROM customers WHERE UPPER(TRIM(t_code)) = '{$e($code)}' LIMIT 1"); $c = $r ? mysqli_fetch_assoc($r) : null; if (!$c) pl_json(['ok' => false, 'msg' => 'Customer not found in Customers.']);
    $set = ["payment_mode = '{$e($mode)}'", 'credit_days = ' . (int)$days]; $hasBl = array_key_exists('blacklisted', $c); $hasT = array_key_exists('blacklist_type', $c); $hasR = array_key_exists('blacklist_reason', $c);
    $oldBlock = !empty($c['blacklisted']) ? ($c['blacklist_type'] ?? 'blocked') : '';
    if ($hasBl && $block === 'temporary') { $set[] = 'blacklisted = 1'; if ($hasT) $set[] = "blacklist_type = 'temporary'"; if ($hasR) $set[] = "blacklist_reason = '{$e($reason !== '' ? $reason : 'Credit policy review')}'"; $newBlock = 'temporary'; }
    elseif ($hasBl && $block === 'unblock') { $set[] = 'blacklisted = 0'; if ($hasT) $set[] = 'blacklist_type = NULL'; $newBlock = ''; }
    else $newBlock = $oldBlock;
    if (!pl_q($conn, "UPDATE customers SET " . implode(', ', $set) . " WHERE UPPER(TRIM(t_code)) = '{$e($code)}'")) pl_json(['ok' => false, 'msg' => 'Could not save.']);
    pl_q($conn, "INSERT INTO plan_policy_log (t_code, old_mode, new_mode, old_days, new_days, old_block, new_block, reason, done_by) VALUES ('{$e($code)}', '{$e($c['payment_mode'] ?? '')}', '{$e($mode)}', " . (int)($c['credit_days'] ?? 0) . ", " . (int)$days . ", '{$e($oldBlock)}', '{$e($newBlock)}', '{$e($reason)}', '{$e(pl_user())}')");
    pl_json(['ok' => true, 'msg' => 'Saved — ' . ucfirst($mode) . ', ' . (int)$days . ' days' . ($newBlock !== $oldBlock ? ($newBlock ? ', temporarily blocked' : ', unblocked') : '') . '.', 'mode' => ucfirst($mode), 'days' => (int)$days, 'block' => $newBlock]);
}
if (($_GET['ajax'] ?? '') === 'log') { $e = mysqli_real_escape_string($conn, strtoupper(trim((string)($_GET['c'] ?? '')))); $o = []; $r = pl_q($conn, "SELECT * FROM plan_policy_log WHERE t_code = '$e' ORDER BY id DESC LIMIT 20"); while ($r && ($x = mysqli_fetch_assoc($r))) $o[] = $x; pl_json(['ok' => true, 'log' => $o]); }
$M = pm_get($conn, isset($_GET['recalc'])); pl_detail_endpoint($M);
$J = pl_slim($M['rows'], ['c', 'n', 'rep', 'b', 'cat', 'risk', 'band', 'cv', 'pay', 'pmraw', 'days', 'cs', 'blt', 'viol', 'avgd', 'ret', 'sent', 'odb', 'odd', 'pol_act', 'pol_mode', 'pol_days', 'pol_why', 'pol_when', 'base', 'tgt']);
foreach ($J as &$x) { $x['unchq'] = round(($x['ret'] ?? 0) + ($x['sent'] ?? 0), 2); $x['chg'] = (strtolower($x['pol_mode']) !== ($x['pmraw'] ?: 'cash') || (int)$x['pol_days'] !== (int)$x['days'] || stripos($x['pol_act'], 'block') !== false) ? 'Change' : 'No change'; } unset($x);
ob_end_flush();
include 'header.php';
echo pl_css();
echo pl_frame('Credit Policy Recommendations', 'fa-scale-balanced', 'linear-gradient(120deg,#1e1b4b,#4338ca 55%,#818cf8)',
    'For every outlet: <b>keep, tighten or relax</b> the pay mode and the policy (credit) days, or <b>block temporarily</b> — from the <b>risk score</b>, the <b>sales volatility (CV)</b> and the payment behaviour. '
    . 'Rules: risk &gt; 60 (or &gt; 40 with unsettled cheques) → temporary block, cash on delivery · credit outlets risk 40–60 → cash if CV &gt; 1 or repeat late payer, otherwise halve the days · risk 20–40 with a recent violation or 7+ days late → 7 days less · '
    . 'risk ≤ 20, no violations, CV &lt; 0.5, active 5 of 6 months, category 1–2 → 7 days more (max 30) · steady low-risk cash outlets (baseline ≥ 50,000) → candidate for cheque 7 days. <b>Click an outlet</b> to see why and to change it by hand. '
    . 'Data from the system on <b>' . pl_h($M['built_at']) . '</b>. <a href="?recalc=1"><i class="fa-solid fa-rotate"></i> Recalculate</a>');
echo pl_js();
?>
<script>
(function(){
  var ALL=<?= json_encode(array_values($J), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?>, CAN=<?= pl_can_edit() ? 'true' : 'false' ?>, $=function(i){return document.getElementById(i);}, esc=plEsc;
  var cvT=function(v){ return v==null?'<span class="z">—</span>':'<span class="tag '+(v<0.5?'t-up':(v<=1?'t-wa':'t-dn'))+'">'+v.toFixed(2)+'</span>'; };
  var P=plPage({data:ALL,exportTitle:'Credit Policy Recommendations',exportName:'credit_policy_recommendations',exportSub:'Recommended pay mode / policy days / block from risk score, sales volatility (CV) and payment behaviour.',sort:{k:'risk',dir:-1},
    filters:[{k:'pol_act',label:'Recommendation'},{k:'chg',label:'Change needed'},{k:'band',label:'Risk band'},{k:'pay',label:'Current mode'},{k:'rep',label:'Sales rep'},{k:'cat',label:'Category'}],
    cols:[{k:'c',h:'HUL',t:'s',s1:1,w:18},{k:'n',h:'Customer',t:'s',s2:1,w:30},{k:'rep',h:'Sales Rep',t:'s',w:24},{k:'b',h:'Beat',t:'s',w:20},{k:'cat',h:'Category',t:'s',w:22},
      {k:'risk',h:'Risk',fmt:function(x){return plMeter(x.risk);},xt:'d',w:8},{k:'band',h:'Band',t:'s',w:13},{k:'cv',h:'Sales CV',fmt:function(x){return cvT(x.cv);},xt:'d',w:9},
      {k:'pay',h:'Mode now',t:'s',fmt:function(x){return plPm(x.pay);},w:10},{k:'days',h:'Days now',xt:'i',w:8},{k:'cs',h:'Credit status',t:'s',w:22},
      {k:'viol',h:'Violations',xt:'i',w:9},{k:'avgd',h:'Avg days late',fmt:function(x){return x.avgd==null?'<span class="z">—</span>':x.avgd.toFixed(1);},xt:'d',w:9},{k:'unchq',h:'Unsettled cheques',tot:1},{k:'odb',h:'Overdue',tot:1},
      {k:'pol_act',h:'Recommendation',t:'s',fmt:function(x){return plTag(x.pol_act);},w:30},{k:'pol_mode',h:'Rec. mode',t:'s',fmt:function(x){return plPm(x.pol_mode);},w:10},{k:'pol_days',h:'Rec. days',fmt:function(x){ var d=x.pol_days-x.days; return '<b>'+x.pol_days+'</b>'+(d?' <small style="color:'+(d<0?'#b91c1c':'#15803d')+'">('+(d>0?'+':'')+d+')</small>':''); },xt:'i',w:9},
      {k:'pol_when',h:'When',t:'s',w:18},{k:'pol_why',h:'Why',t:'s',fmt:function(x){ return '<span style="color:#475569;font-size:12px">'+esc(x.pol_why.length>90?x.pol_why.slice(0,90)+'…':x.pol_why)+'</span>'; },w:70}],
    kpis:function(rows){ var c={}; rows.forEach(function(x){ var k=x.pol_act.indexOf('Temporary block')===0||x.pol_act.indexOf('Change mode')===0?'block':(x.pol_act.indexOf('Tighten')===0?'tight':(x.pol_act.indexOf('Relax')===0||x.pol_act.indexOf('Candidate')===0?'relax':(x.pol_act.indexOf('Keep blocked')===0||x.pol_act.indexOf('Review')===0?'bl':'keep'))); c[k]=(c[k]||0)+1; });
      $('kp').innerHTML=plKpi([['Outlets',plF0(rows.length),'fa-store',1],['Block / move to cash',plF0(c.block||0),'fa-ban',6],['Tighten policy days',plF0(c.tight||0),'fa-compress',4],['Relax / offer credit',plF0(c.relax||0),'fa-expand',3],['Blocked — keep / review',plF0(c.bl||0),'fa-lock',7],['Maintain',plF0(c.keep||0),'fa-equals',2]]); },
    onRow:function(x){ $('dT').textContent=x.n; $('dS').textContent=x.c+' · '+x.rep+' · '+x.b; $('dB').innerHTML='Loading…'; D.open();
      var blk=x.cs.indexOf('BLOCKED')===0, recBlock=x.pol_act.indexOf('Temporary block')===0;
      $('dF').innerHTML=CAN?'<span class="msg" id="pm"></span><button class="b o" data-close>Close</button><button class="b" id="pSave"><i class="fa-solid fa-floppy-disk"></i> Save change</button>':'<span class="msg">Edit right needed to change the policy.</span><button class="b o" data-close>Close</button>';
      plGet(x.c).then(function(m){
        var form='<div class="sec"><h4><i class="fa-solid fa-lightbulb"></i> Recommendation</h4><div class="why"><b>'+esc(x.pol_act)+'</b> → '+esc(x.pol_mode)+', '+x.pol_days+' days · <b>When:</b> '+esc(x.pol_when)+'<br>'+esc(x.pol_why)+'</div></div>'
          +(CAN?'<div class="sec fm"><h4><i class="fa-solid fa-pen-to-square"></i> Change the policy (prefilled with the recommendation)</h4><div class="two"><div><label>Pay mode</label><select id="fMode"><option value="cash">Cash</option><option value="credit">Credit</option><option value="cheque">Cheque</option></select></div><div><label>Policy days</label><input id="fDays" type="number" min="0" max="365"></div></div>'
            +'<label>Block status</label><select id="fBlock"><option value="keep">Keep as is ('+(blk?esc(x.cs):'Active')+')</option><option value="temporary">Temporary block</option><option value="unblock">Unblock</option></select><label>Reason / note (saved in the log)</label><input id="fReason" type="text" maxlength="400" placeholder="e.g. 3 returned cheques; review after dues cleared"></div>':'')
          +'<div class="sec"><h4><i class="fa-solid fa-clock-rotate-left"></i> Change history</h4><div id="pLog" style="font-size:12.5px;color:#64748b">Loading…</div></div>';
        $('dB').innerHTML=form+plDetail(m);
        if(CAN){ $('fMode').value=String(x.pol_mode).toLowerCase(); $('fDays').value=x.pol_days; $('fBlock').value=recBlock&&!blk?'temporary':'keep'; $('fReason').value=recBlock?x.pol_why.slice(0,380):'';
          $('pSave').onclick=function(){ $('pm').style.color='#475569'; $('pm').textContent='Saving…'; plPost('plan_credit_policy.php',{action:'apply',code:x.c,mode:$('fMode').value,days:$('fDays').value,block:$('fBlock').value,reason:$('fReason').value}).then(function(j){
              $('pm').style.color=j.ok?'#15803d':'#b91c1c'; $('pm').textContent=j.msg; if(j.ok){ x.pay=j.mode; x.pmraw=j.mode.toLowerCase(); x.days=j.days; if(j.block==='temporary') x.cs='BLOCKED (Temporary block)'; else if(j.block==='') x.cs='Active'; x.chg='No change'; P.redraw(); } }).catch(function(e){ $('pm').style.color='#b91c1c'; $('pm').textContent=e.message; }); }; }
        fetch('plan_credit_policy.php?ajax=log&c='+encodeURIComponent(x.c),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){ $('pLog').innerHTML=(j.log||[]).length?'<table class="rk"><thead><tr><th>When</th><th>By</th><th>Mode</th><th>Days</th><th>Block</th><th>Reason</th></tr></thead><tbody>'+j.log.map(function(l){ return '<tr><td>'+esc(l.done_at)+'</td><td>'+esc(l.done_by)+'</td><td>'+esc(l.old_mode)+' → <b>'+esc(l.new_mode)+'</b></td><td>'+l.old_days+' → <b>'+l.new_days+'</b></td><td>'+esc(l.old_block||'—')+' → <b>'+esc(l.new_block||'—')+'</b></td><td>'+esc(l.reason)+'</td></tr>'; }).join('')+'</tbody></table>':'No changes made from this page yet.'; });
      }).catch(function(e){ $('dB').textContent=e.message; }); }});
  var D=plModal('dm');
})();
</script>
<?php include 'footer.php'; ?>
