<?php
/**
 * plan_target_entry.php — Analysis → Target Entry
 * Enter the Unilever monthly target (company total) and the rep-wise targets for a sales month. Target Scenarios reads them from here.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/plan_lib.php'; require_once __DIR__ . '/plan_model_lib.php';
pl_q($conn, "CREATE TABLE IF NOT EXISTS plan_targets (ym CHAR(7) NOT NULL, rep VARCHAR(150) NOT NULL DEFAULT '', amount DECIMAL(16,2) NOT NULL DEFAULT 0, updated_by VARCHAR(100), updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (ym, rep)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$e = fn($v) => mysqli_real_escape_string($conn, (string)$v);
if (($_POST['action'] ?? '') === 'save_targets') {
    if (!pl_can_edit()) pl_json(['ok' => false, 'msg' => 'You do not have the Edit right on this page.']);
    $ym = preg_match('/^\d{4}-\d{2}$/', $_POST['ym'] ?? '') ? $_POST['ym'] : ''; if (!$ym) pl_json(['ok' => false, 'msg' => 'Pick a month.']);
    $u = $e(pl_user()); $all = ['' => $_POST['total'] ?? '']; foreach ((array)json_decode($_POST['reps'] ?? '{}', true) as $rep => $v) $all[(string)$rep] = $v;
    $n = 0; foreach ($all as $rep => $v) { $v = str_replace(',', '', trim((string)$v));
        if ($v === '' || !is_numeric($v) || (float)$v <= 0) pl_q($conn, "DELETE FROM plan_targets WHERE ym = '$ym' AND rep = '{$e($rep)}'");
        else { pl_q($conn, "REPLACE INTO plan_targets (ym, rep, amount, updated_by) VALUES ('$ym', '{$e($rep)}', " . round((float)$v, 2) . ", '$u')"); $n++; } }
    pl_json(['ok' => true, 'msg' => 'Saved ' . $n . ' target(s) for ' . date('M-y', strtotime($ym . '-01')) . '.']);
}
if (($_GET['ajax'] ?? '') === 'targets') {
    $ym = preg_match('/^\d{4}-\d{2}$/', $_GET['ym'] ?? '') ? $_GET['ym'] : ''; $t = ['total' => '', 'reps' => []];
    $r = pl_q($conn, "SELECT rep, amount FROM plan_targets WHERE ym = '$ym'"); while ($r && ($x = mysqli_fetch_assoc($r))) { if ($x['rep'] === '') $t['total'] = (float)$x['amount']; else $t['reps'][$x['rep']] = (float)$x['amount']; }
    pl_json(['ok' => true] + $t);
}
$M = pm_get($conn, isset($_GET['recalc']));
$S = hs_settings($conn); $openYm = hs_add($S['first_cycle'], $S['months']);
$sel = preg_match('/^\d{4}-\d{2}$/', $_GET['ym'] ?? '') ? $_GET['ym'] : $openYm;
$months = []; for ($i = -3; $i <= 6; $i++) $months[] = hs_add($openYm, $i); if (!in_array($sel, $months, true)) $months[] = $sel;
/* reference figures per rep (last complete cycle, baseline, scenarios for the open cycle) */
$R = []; foreach ($M['rows'] as $x) { $k = $x['rep'] ?: 'Unassigned'; $o = &$R[$k]; if (!$o) $o = ['n' => 0, 'base' => 0, 'last' => 0, 'min' => 0, 'mod' => 0, 'max' => 0];
    $o['n']++; $o['base'] += $x['base']; $o['last'] += $x['l'] ?? 0; $o['min'] += $x['min']; $o['mod'] += $x['mod']; $o['max'] += $x['max']; unset($o); }
ksort($R);
$hist = []; $r = pl_q($conn, "SELECT ym, SUM(rep = '') AS has_total, SUM(CASE WHEN rep = '' THEN amount ELSE 0 END) AS total, SUM(CASE WHEN rep <> '' THEN amount ELSE 0 END) AS reps, SUM(rep <> '') AS nrep, MAX(updated_at) AS at, MAX(updated_by) AS by_ FROM plan_targets GROUP BY ym ORDER BY ym DESC LIMIT 24");
while ($r && ($x = mysqli_fetch_assoc($r))) $hist[] = $x;
ob_end_flush();
include 'header.php';
echo pl_css();
$can = pl_can_edit();
?>
<div class="pl">
  <div class="hero" style="background:linear-gradient(120deg,#451a03,#b45309 55%,#f59e0b)"><h2><i class="fa-solid fa-pen-to-square"></i> Target Entry</h2>
    <p>Enter the <b>Unilever monthly target</b> (company total) and the <b>rep-wise targets</b> for a sales month (cycle <?= (int)$S['start_day'] ?>th → <?= (int)$S['start_day'] - 1 ?>th). Reference figures per rep are shown beside the boxes. <a href="plan_target_scenarios.php"><i class="fa-solid fa-bullseye"></i> Open Target Scenarios</a> to see how the target splits to every outlet in the Minimum / Moderate / Maximum risk scenarios.</p></div>
  <div class="card"><div class="row" style="align-items:flex-end">
    <div class="fld"><label>Sales month</label><select id="eYm"><?php foreach ($months as $m): ?><option value="<?= $m ?>" <?= $m === $sel ? 'selected' : '' ?>><?= hs_label($m) ?><?= $m === $openYm ? ' (open cycle)' : '' ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Unilever monthly target — company total (LKR)</label><input id="eTot" class="ti" type="number" min="0" step="1000" style="width:230px" placeholder="e.g. 145000000"<?= $can ? '' : ' disabled' ?>></div>
    <div class="fld"><label>Fill rep targets from the total</label><select id="eBy"><option value="base">in proportion to baseline</option><option value="last">in proportion to last cycle sales</option><option value="mod">in proportion to the moderate scenario</option></select></div>
    <button class="btn o" id="eFill" type="button"<?= $can ? '' : ' disabled' ?>><i class="fa-solid fa-wand-magic-sparkles"></i> Fill</button>
    <button class="btn o" id="eClear" type="button"<?= $can ? '' : ' disabled' ?>>Clear reps</button>
    <button class="btn" id="eSave" type="button"<?= $can ? '' : ' disabled title="Edit right needed"' ?>><i class="fa-solid fa-floppy-disk"></i> Save targets</button><span id="eMsg" style="font-size:12.5px"></span></div>
    <div id="eChk" style="margin-top:10px;font-size:13px"></div></div>
  <div class="tw" style="margin-bottom:14px"><table style="width:100%"><thead><tr><th class="l">Sales Rep</th><th>Outlets</th><th>Baseline</th><th>Last cycle sales</th><th>Min-risk</th><th>Moderate</th><th>Max-risk</th><th style="background:#92400e">Rep target (LKR)</th><th>vs baseline</th><th>Share of total</th></tr></thead><tbody id="eB"></tbody><tfoot id="eF"></tfoot></table></div>
  <?php if ($hist): ?><div class="card"><b>Saved targets</b><div class="tw" style="margin-top:8px"><table style="width:100%"><thead><tr><th class="l">Month</th><th>Company total</th><th>Rep targets (sum)</th><th>Reps</th><th>Difference</th><th class="l">Last saved</th><th class="l"></th></tr></thead><tbody>
  <?php foreach ($hist as $h): ?><tr><td class="l"><b><?= hs_label($h['ym']) ?></b></td><td><?= $h['has_total'] ? number_format((float)$h['total']) : '—' ?></td><td><?= number_format((float)$h['reps']) ?></td><td><?= (int)$h['nrep'] ?></td><td><?= $h['has_total'] && $h['nrep'] ? number_format((float)$h['total'] - (float)$h['reps']) : '' ?></td><td class="l"><?= pl_h($h['at']) ?> · <?= pl_h($h['by_']) ?></td><td class="l"><a href="?ym=<?= pl_h($h['ym']) ?>">Edit</a> · <a href="plan_target_scenarios.php">Scenarios</a></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
</div>
<?= pl_js() ?>
<script>
(function(){
  var R=<?= json_encode($R, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>, CAN=<?= $can ? 'true' : 'false' ?>, $=function(i){return document.getElementById(i);}, esc=plEsc, reps=Object.keys(R);
  $('eB').innerHTML=reps.map(function(r,i){ var o=R[r]; return '<tr data-i="'+i+'"><td class="l"><b>'+esc(r)+'</b></td><td>'+o.n+'</td><td>'+plF0(o.base)+'</td><td>'+plF0(o.last)+'</td><td>'+plF0(o.min)+'</td><td>'+plF0(o.mod)+'</td><td>'+plF0(o.max)+'</td>'
    +'<td><input class="ti" type="number" min="0" step="1000" data-rep="'+esc(r)+'"'+(CAN?'':' disabled')+'></td><td class="vb"></td><td class="sh"></td></tr>'; }).join('');
  var inputs=function(){ return [].slice.call(document.querySelectorAll('#eB input[data-rep]')); };
  function refresh(){ var T=parseFloat($('eTot').value)||0, sum=0, t={n:0,base:0,last:0,min:0,mod:0,max:0};
    inputs().forEach(function(el,i){ var r=reps[i], o=R[r], v=parseFloat(el.value)||0, tr=el.closest('tr'); sum+=v; ['n','base','last','min','mod','max'].forEach(function(k){ t[k]+=o[k]; });
      tr.querySelector('.vb').innerHTML=v?'<b style="color:'+(v>o.base?'#b45309':'#15803d')+'">'+(o.base>0?((v/o.base-1)*100).toFixed(1)+'%':'—')+'</b>':''; tr.querySelector('.sh').textContent=v&&T?(v/T*100).toFixed(1)+'%':''; });
    $('eF').innerHTML='<tr><td class="l">TOTAL</td><td>'+t.n+'</td><td>'+plF0(t.base)+'</td><td>'+plF0(t.last)+'</td><td>'+plF0(t.min)+'</td><td>'+plF0(t.mod)+'</td><td>'+plF0(t.max)+'</td><td>'+plF0(sum)+'</td><td>'+(sum&&t.base?((sum/t.base-1)*100).toFixed(1)+'%':'')+'</td><td>'+(sum&&T?(sum/T*100).toFixed(1)+'%':'')+'</td></tr>';
    var d=T-sum; $('eChk').innerHTML=!T&&!sum?'<span style="color:#64748b">Enter the company total and / or the rep targets.</span>':(T&&sum?(Math.abs(d)<1?'<span style="color:#15803d;font-weight:700"><i class="fa-solid fa-circle-check"></i> Rep targets add up to the company total.</span>':'<span style="color:#b45309;font-weight:700"><i class="fa-solid fa-triangle-exclamation"></i> Rep targets add up to Rs. '+plF0(sum)+' — '+(d>0?'Rs. '+plF0(d)+' not yet given to any rep (Target Scenarios spreads it over the reps without a target).':'Rs. '+plF0(-d)+' more than the company total.')+'</span>'):'')
      +(T?' <span style="color:#64748b">· company total vs baseline '+(t.base?((T/t.base-1)*100).toFixed(1)+'%':'')+' · vs moderate scenario '+(t.mod?((T/t.mod-1)*100).toFixed(1)+'%':'')+'</span>':''); }
  function load(){ $('eMsg').textContent=''; fetch('plan_target_entry.php?ajax=targets&ym='+$('eYm').value,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){ $('eTot').value=j.total||''; inputs().forEach(function(el,i){ el.value=(j.reps||{})[reps[i]]||''; }); refresh(); }).catch(function(){ refresh(); }); }
  $('eYm').onchange=function(){ history.replaceState(null,'','?ym='+this.value); load(); };
  $('eTot').oninput=refresh; document.getElementById('eB').addEventListener('input',refresh);
  $('eFill').onclick=function(){ var T=parseFloat($('eTot').value)||0; if(!T){ alert('Enter the company total first.'); return; } var k=$('eBy').value, s=0; reps.forEach(function(r){ s+=R[r][k]; });
    inputs().forEach(function(el,i){ el.value=s>0?Math.round(T*R[reps[i]][k]/s/1000)*1000:''; }); var d=T-inputs().reduce(function(a,el){return a+(+el.value||0);},0), big=inputs().slice().sort(function(a,b){return (+b.value)-(+a.value);})[0]; if(big&&d) big.value=(+big.value)+d; refresh(); };
  $('eClear').onclick=function(){ inputs().forEach(function(el){ el.value=''; }); refresh(); };
  $('eSave').onclick=function(){ var o={}; inputs().forEach(function(el,i){ o[reps[i]]=el.value; }); $('eMsg').style.color='#475569'; $('eMsg').textContent='Saving…';
    plPost('plan_target_entry.php',{action:'save_targets',ym:$('eYm').value,total:$('eTot').value,reps:JSON.stringify(o)}).then(function(j){ $('eMsg').style.color=j.ok?'#15803d':'#b91c1c'; $('eMsg').innerHTML=esc(j.msg)+(j.ok?' <a href="plan_target_scenarios.php">See the scenarios →</a>':''); }).catch(function(e){ $('eMsg').style.color='#b91c1c'; $('eMsg').textContent=e.message; }); };
  load();
})();
</script>
<?php include 'footer.php'; ?>
