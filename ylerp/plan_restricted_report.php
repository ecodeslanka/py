<?php
/** plan_restricted_report.php — Analysis → Restricted & High-Risk Customers (credit restriction, collection or management approval). Data: Import Planning Data. */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/plan_lib.php'; require_once __DIR__ . '/plan_model_lib.php';
$M = pm_get($conn, isset($_GET['recalc'])); $imp = ['filename' => 'system data', 'imported_at' => $M['built_at']];
$sel = array_values(array_filter($M['rows'], fn($x) => in_array($x['cat'][0], ['4', '5', '6'], true) || in_array($x['prio'][0], ['1', '2'], true)));   // categories 4, 5, 6 + any outlet with an overdue invoice or unsettled cheques — whatever its pay mode (cash outlets included)
usort($sel, fn($a, $b) => strcmp($a['prio'], $b['prio']) ?: ($b['exp'] <=> $a['exp'])); $rows = [];
foreach ($sel as $x) $rows[] = ['hul' => $x['c'], 'customer_name' => $x['n'], 'sales_rep' => $x['rep'], 'beat' => $x['b'], 'category' => $x['cat'], 'credit_status' => $x['cs'], 'pay_mode' => $x['pay'], 'risk_score' => $x['risk'], 'risk_band' => $x['band'], 'cv' => $x['cv'] ?? null, 'cv_label' => ($x['cv'] ?? null) === null ? '' : ($x['cv'] < 0.5 ? 'Steady' : ($x['cv'] <= 1 ? 'Variable' : 'Very volatile')), 'priority' => $x['prio'],
    'od_invoices' => $x['odn'], 'od_balance' => $x['odb'], 'od_max_days' => $x['odd'], 'ret_chq' => $x['ret'], 'sent_chq' => $x['sent'], 'exposure' => $x['exp'], 'violations' => $x['viol'], 'avg_days_over' => $x['avgd'], 'returned_cheques' => $x['rcq'], 'repeat_violator' => $x['repv'],
    'baseline' => $x['base'], 'target' => $x['tgt'], 'restorable' => $x['res'], 'status_conflict' => $x['conf'], 'approval' => $x['appr'], 'required_action' => $x['act'], 'm' => $x];
$F = ['q' => trim($_GET['q'] ?? ''), 'cat' => trim($_GET['cat'] ?? ''), 'cs' => trim($_GET['cs'] ?? ''), 'prio' => trim($_GET['prio'] ?? ''), 'band' => trim($_GET['band'] ?? ''), 'appr' => trim($_GET['appr'] ?? ''), 'rep' => trim($_GET['rep'] ?? ''), 'pay' => trim($_GET['pay'] ?? '')];

if (isset($_GET['export']) && class_exists('ZipArchive')) {
    require_once __DIR__ . '/rbac_xlsx.php'; while (ob_get_level()) ob_end_clean();
    $rows = array_values(array_filter($rows, function ($x) use ($F) {
        foreach ([['cat', 'category'], ['cs', 'credit_status'], ['prio', 'priority'], ['band', 'risk_band'], ['appr', 'approval'], ['rep', 'sales_rep'], ['pay', 'pay_mode']] as [$k, $c]) if ($F[$k] !== '' && $x[$c] !== $F[$k]) return false;
        return $F['q'] === '' || stripos($x['hul'] . ' ' . $x['customer_name'] . ' ' . $x['sales_rep'] . ' ' . $x['beat'], $F['q']) !== false; }));
    $X = new MiniXlsx(); $sh = $X->sheet('Restricted and High Risk'); $sh->tab = 'B91C1C'; $sh->landscape = true;
    $FM = ['font' => 'Arial']; $NUM = '#,##0;\(#,##0\);"-"'; $NAVY = '1F3864'; $HS = $FM + ['b' => 1, 'color' => 'FFFFFF', 'bg' => $NAVY, 'h' => 'center', 'wrap' => 1, 'border' => 1];
    $sh->set(1, 1, 'Restricted and High-Risk Customers — credit restriction, collection or management approval', $FM + ['b' => 1, 'sz' => 14, 'color' => $NAVY]);
    $sh->set(2, 1, 'Categories 4, 5, 6 plus any outlet with open overdue invoices or unsettled returned / sent-back cheques (all pay modes, cash included). Sorted by collection priority, then exposure. Calculated from the system data on ' . ($imp['imported_at'] ?? '') . '.', $FM + ['sz' => 9, 'color' => '595959']);
    $cols = [['HUL', 'hul', 's', 18], ['Customer Name', 'customer_name', 's', 30], ['Sales Rep', 'sales_rep', 's', 28], ['Beat', 'beat', 's', 24], ['Category', 'category', 's', 24], ['Credit Status', 'credit_status', 's', 30], ['Pay Mode', 'pay_mode', 's', 10], ['Risk Score', 'risk_score', 'd', 9], ['Risk Band', 'risk_band', 's', 14], ['Volatility (CV)', 'cv', 'd2', 11], ['Volatility', 'cv_label', 's', 14],
        ['Collection Priority', 'priority', 's', 14], ['Open Overdue Invoices', 'od_invoices', 'i', 10], ['Open Overdue Balance', 'od_balance', 'n', 15], ['Open Max Days Over', 'od_max_days', 'i', 10], ['Unsettled Returned Chq', 'ret_chq', 'n', 15], ['Unsettled Sent-back Chq', 'sent_chq', 'n', 15],
        ['Total Visible Exposure', 'exposure', 'n', 16], ['Violations', 'violations', 'i', 10], ['Avg Days Over', 'avg_days_over', 'd', 10], ['Returned Cheques', 'returned_cheques', 'i', 10], ['Repeat Violator', 'repeat_violator', 's', 10], ['Baseline', 'baseline', 'n', 14],
        ['Recommended Target', 'target', 'n', 14], ['Restorable if Authorised', 'restorable', 'n', 14], ['Status Conflict', 'status_conflict', 's', 34], ['Approval Required', 'approval', 's', 30], ['Required Action', 'required_action', 's', 90]];
    foreach ($cols as $i => $c) { $sh->set(4, $i + 1, $c[0], $HS); $sh->widths[$i + 1] = $c[3]; } $sh->heights[4] = 40; $r = 5; $n = count($cols); $tot = [];
    foreach ($rows as $x) { foreach ($cols as $i => [$h, $k, $t]) { $v = $x[$k]; $st = $FM;
            if ($t === 'n') { $v = (float)$v; $st += ['nfs' => $NUM]; $tot[$i] = ($tot[$i] ?? 0) + $v; } elseif ($t === 'i') { $v = $v === null ? '' : (int)$v; $st += ['h' => 'center', 'nfs' => '0']; } elseif ($t === 'd2') { $v = $v === null ? '' : (float)$v; $st += ['h' => 'center', 'nfs' => '0.00']; } elseif ($t === 'd') { $v = $v === null ? '' : (float)$v; $st += ['h' => 'center', 'nfs' => '0.0']; }
            if ($k === 'required_action') $st += ['wrap' => 1, 'v' => 'top'];
            if ($k === 'credit_status' && stripos((string)$x[$k], 'BLOCKED') === 0) $st += ['color' => 'B91C1C', 'b' => 1];
            if ($k === 'approval' && stripos((string)$x[$k], 'YES') === 0) $st += ['color' => 'B91C1C', 'b' => 1];
            $sh->set($r, $i + 1, $v, $st); } $r++; }
    $last = max(5, $r - 1); $rt = $r + 1; $TB = $FM + ['b' => 1, 'bg' => 'D9E1F2', 'border' => 1, 'nfs' => $NUM, 'color' => $NAVY];
    for ($c = 1; $c <= $n; $c++) $sh->set($rt, $c, '', $TB); $sh->set($rt, 1, 'TOTAL', $TB); $sh->set($rt, 2, count($rows) . ' customers', $TB);
    foreach ($tot as $i => $v) { $L = MiniXlsx::col($i + 1); $sh->set($rt, $i + 1, round($v, 2), $TB + ['f' => "SUM({$L}5:{$L}$last)"]); }
    $sh->freeze = [4, 2]; $sh->filter = 'A4:' . MiniXlsx::col($n) . $last; $sh->titles = ['rows' => '4:4', 'cols' => 'A:B'];
    $sh->condfmt[] = ['R5:R' . $last, 'FFFFFF', 'F87171', 95];
    $tmp = tempnam(sys_get_temp_dir(), 'pr'); $X->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="restricted_high_risk_customers_' . date('Ymd_His') . '.xlsx"'); header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
$J = array_map(fn($x) => ['c' => $x['hul'], 'n' => $x['customer_name'], 'rep' => $x['sales_rep'], 'b' => $x['beat'], 'cat' => $x['category'], 'cs' => $x['credit_status'], 'pay' => $x['pay_mode'], 'risk' => (float)$x['risk_score'], 'band' => $x['risk_band'], 'cv' => $x['cv'] === null ? null : (float)$x['cv'], 'prio' => $x['priority'],
    'odn' => (int)$x['od_invoices'], 'odb' => (float)$x['od_balance'], 'odd' => $x['od_max_days'] === null ? null : (int)$x['od_max_days'], 'ret' => (float)$x['ret_chq'], 'sent' => (float)$x['sent_chq'], 'exp' => (float)$x['exposure'], 'viol' => (int)$x['violations'],
    'avgd' => $x['avg_days_over'] === null ? null : (float)$x['avg_days_over'], 'rcq' => (int)$x['returned_cheques'], 'repv' => $x['repeat_violator'], 'base' => (float)$x['baseline'], 'tgt' => (float)$x['target'], 'res' => (float)$x['restorable'], 'conf' => $x['status_conflict'], 'appr' => $x['approval'], 'act' => $x['required_action'],
    'rk' => $x['m']['rk'], 'why' => $x['m']['why'], 'acts' => $x['m']['acts'], 'cap' => $x['m']['cap'], 'gpot' => $x['m']['gpot'], 'c78' => $x['m']['c78'], 'a3m' => $x['m']['a3'], 'a6mv' => $x['m']['a6'], 'a12' => $x['m']['a12'], 'gr' => $x['m']['gr'], 'v' => $x['m']['v'], 'med' => $x['m']['med'], 'pgo' => $x['m']['pgo']], $rows);
ob_end_flush();
include 'header.php';
echo pl_css();
?>
<div class="pl">
  <div class="hero" style="background:linear-gradient(120deg,#1c0a0a 0%,#7f1d1d 50%,#dc2626 100%)"><h2><i class="fa-solid fa-shield-halved"></i> Restricted &amp; High-Risk Customers</h2>
    <p>Categories <b>4, 5, 6</b> plus any outlet with an <b>open overdue invoice or unsettled returned / sent-back cheques</b> — <b>every pay mode, cash included</b> — sorted by <b>collection priority</b>, then exposure. <b>Click a row</b> for the full picture and the required action. Calculated live from the system data (sales history, customers, bills, cheques) on <b><?= pl_h($M['built_at']) ?></b>. <a href="?recalc=1"><i class="fa-solid fa-rotate"></i> Recalculate now</a></p></div>
  <?php if (!$rows): ?><div class="card" style="color:#92400e;background:#fffbeb">No restricted or high-risk customers found (or no sales history yet).</div><?php endif; ?>
  <div class="card"><div class="row">
    <div class="fld"><label>Search</label><input id="fQ" type="search" placeholder="HUL, customer, rep, beat"></div>
    <div class="fld"><label>Priority</label><select id="fPrio"></select></div><div class="fld"><label>Category</label><select id="fCat"></select></div><div class="fld"><label>Credit status</label><select id="fCs"></select></div>
    <div class="fld"><label>Risk band</label><select id="fBand"></select></div><div class="fld"><label>Approval</label><select id="fAppr"></select></div><div class="fld"><label>Sales rep</label><select id="fRep"></select></div><div class="fld"><label>Pay mode</label><select id="fPay"></select></div>
    <button class="btn o" id="fReset" type="button">Reset</button><a class="btn g" id="fX" data-no-loader href="#"><i class="fa-solid fa-file-excel"></i> Export Excel</a></div></div>
  <div class="kp"><div class="k k1"><i class="fa-solid fa-users"></i><span>Customers</span><b id="kN">0</b></div><div class="k k6"><i class="fa-solid fa-triangle-exclamation"></i><span>Critical priority</span><b id="kC">0</b></div><div class="k k7"><i class="fa-solid fa-ban"></i><span>Blocked</span><b id="kBl">0</b></div><div class="k k4"><i class="fa-solid fa-file-invoice-dollar"></i><span>Open overdue balance</span><b id="kO">0</b></div><div class="k k5"><i class="fa-solid fa-money-check"></i><span>Unsettled cheques</span><b id="kQ">0</b></div><div class="k k6"><i class="fa-solid fa-fire"></i><span>Total visible exposure</span><b id="kE">0</b></div><div class="k k2"><i class="fa-solid fa-user-shield"></i><span>Need management approval</span><b id="kAp">0</b></div></div>
  <div class="grid3"><div class="card"><div class="ct"><i class="fa-solid fa-gauge-high" style="color:#dc2626"></i> Risk band</div><div id="chBand"></div></div>
    <div class="card"><div class="ct"><i class="fa-solid fa-layer-group" style="color:#4f46e5"></i> Category</div><div id="chCat"></div></div>
    <div class="card"><div class="ct"><i class="fa-solid fa-fire" style="color:#ea580c"></i> Top 10 by exposure</div><div id="chTop"></div></div></div>
  <div class="tw" id="tw"><table><thead id="th"><tr><th class="s1 l" data-k="c" data-t="s">HUL</th><th class="s2 l" data-k="n" data-t="s">Customer Name</th><th class="l" data-k="rep" data-t="s">Sales Rep</th><th class="l" data-k="b" data-t="s">Beat</th><th class="l" data-k="cat" data-t="s">Category</th><th class="l" data-k="cs" data-t="s">Credit Status</th><th class="l" data-k="pay" data-t="s">Pay Mode</th><th data-k="risk" data-t="n">Risk Score</th><th data-k="cv" data-t="n">Volatility (CV)</th><th class="l" data-k="prio" data-t="s">Priority</th>
    <th data-k="odn" data-t="n">Overdue Inv.</th><th data-k="odb" data-t="n">Overdue Balance</th><th data-k="odd" data-t="n">Max Days Over</th><th data-k="ret" data-t="n">Returned Chq</th><th data-k="sent" data-t="n">Sent-back Chq</th><th data-k="exp" data-t="n">Total Exposure</th><th data-k="viol" data-t="n">Violations</th><th data-k="avgd" data-t="n">Avg Days Over</th><th data-k="rcq" data-t="n">Ret. Chq Count</th><th class="l" data-k="repv" data-t="s">Repeat</th><th data-k="base" data-t="n">Baseline</th><th data-k="tgt" data-t="n">Rec. Target</th><th data-k="res" data-t="n">Restorable</th><th class="l" data-k="appr" data-t="s">Approval</th></tr></thead><tbody id="tb"></tbody>
    <tfoot><tr><td class="l s1" style="z-index:4">TOTAL</td><td class="l s2" style="z-index:4" id="fN"></td><td colspan="9"></td><td id="fO"></td><td></td><td id="fR"></td><td id="fS"></td><td id="fE"></td><td colspan="4"></td><td id="fB"></td><td id="fT"></td><td id="fRs"></td><td></td></tr></tfoot></table></div>
</div>
<div class="plm" id="dm"><div class="bx"><div class="hd" id="dH" style="background:linear-gradient(120deg,#1c0a0a,#7f1d1d 55%,#dc2626)"><div><b id="dT" style="font-size:18px"></b><div id="dS" style="font-size:12.5px;opacity:.85"></div></div><button type="button" id="dX">&times;</button></div><div class="bd" id="dB"></div></div></div>
<?= pl_js() ?>
<script>
(function(){
  var ALL=<?= json_encode($J, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?>, $=function(i){return document.getElementById(i);}, esc=plEsc;
  var F={q:'',prio:'',cat:'',cs:'',band:'',appr:'',rep:'',pay:''}, cur=ALL, sort={k:'exp',dir:-1}, ids={prio:['fPrio','prio'],cat:['fCat','cat'],cs:['fCs','cs'],band:['fBand','band'],appr:['fAppr','appr'],rep:['fRep','rep'],pay:['fPay','pay']};
  Object.keys(ids).forEach(function(k){ var key=ids[k][1],s={},a=[]; ALL.forEach(function(x){ if(x[key]!==''&&!s[x[key]]){ s[x[key]]=1; a.push(x[key]); } }); a.sort(); $(ids[k][0]).innerHTML='<option value="">All</option>'+a.map(function(v){return '<option>'+esc(v)+'</option>';}).join(''); });
  function riskCol(v){ return v<=20?'#16a34a':(v<=40?'#f59e0b':(v<=60?'#ea580c':'#dc2626')); }
  var BC={'A':'#16a34a','B':'#0ea5e9','C':'#f59e0b','D':'#dc2626','E':'#7f1d1d'}, PC={'1':'#dc2626','2':'#ea580c','3':'#f59e0b','4':'#0ea5e9','5':'#64748b'}, CC={'2':'#0ea5e9','3':'#10b981','4':'#f59e0b','5':'#ea580c','6':'#dc2626'};
  function money(v){ return v?plF0(v):'<span class="z">—</span>'; }
  var T=PLVT({tw:$('tw'),tb:$('tb'),cols:23,rh:38,height:'68vh',rowHtml:function(x,i){
    var blocked=x.cs.indexOf('BLOCKED')===0, yes=x.appr.indexOf('YES')===0;
    return '<tr class="r'+(i%2?' ev':'')+'" data-i="'+i+'"><td class="l s1">'+esc(x.c)+'</td><td class="l s2" title="'+esc(x.n)+'">'+esc(x.n)+'</td><td class="l">'+esc(x.rep)+'</td><td class="l">'+esc(x.b)+'</td><td class="l">'+plChip(x.cat,CC[x.cat.charAt(0)])+'</td>'
     +'<td class="l">'+(blocked?'<span class="chip" style="--c:#dc2626"><i class="fa-solid fa-ban"></i> '+esc(x.cs.replace('BLOCKED ',''))+'</span>':'<span class="chip" style="--c:#16a34a">'+esc(x.cs)+'</span>')+'</td><td class="l">'+plPm(x.pay)+'</td>'
     +'<td><span class="meter"><i style="width:'+Math.min(100,x.risk)+'%;background:'+riskCol(x.risk)+'"></i></span>'+x.risk.toFixed(1)+' <small style="color:#64748b">'+esc(x.band.charAt(0))+'</small></td><td>'+plCv(x.cv)+'</td><td class="l">'+plChip(x.prio,PC[x.prio.charAt(0)])+'</td>'
     +'<td>'+(x.odn||'<span class="z">0</span>')+'</td><td style="color:#b91c1c;font-weight:700">'+money(x.odb)+'</td><td>'+(x.odd==null?'<span class="z">—</span>':x.odd)+'</td><td>'+money(x.ret)+'</td><td>'+money(x.sent)+'</td><td style="font-weight:800;color:#7f1d1d">'+money(x.exp)+'</td><td>'+x.viol+'</td><td>'+(x.avgd==null?'<span class="z">—</span>':x.avgd.toFixed(1))+'</td><td>'+(x.rcq||'<span class="z">0</span>')+'</td><td class="l">'+(x.repv?'<span class="chip" style="--c:#dc2626">Repeat</span>':'<span class="z">—</span>')+'</td>'
     +'<td>'+plF0(x.base)+'</td><td>'+plF0(x.tgt)+'</td><td>'+money(x.res)+'</td><td class="l">'+(yes?'<span class="chip" style="--c:#dc2626">Mgmt approval</span>':(x.appr.indexOf('Recommended')===0?'<span class="chip" style="--c:#f59e0b">Recommended</span>':'<span class="z">No</span>'))+'</td></tr>'; }});
  plSortable($('th'),function(){return cur;},function(r){ cur=r; T.setRows(cur); },sort);
  function bars(id,items,fmt,max){ $(id).innerHTML=items.map(function(it){ return '<div class="hb"><span class="lb" title="'+esc(it[0])+'">'+esc(it[0])+'</span><span class="tr"><i style="width:'+Math.max(2,it[1]/max*100)+'%;'+(it[3]?'background:'+it[3]:'')+'"></i></span><span class="vl">'+fmt(it)+'</span></div>'; }).join('')||'<div style="color:#94a3b8;font-size:12px">—</div>'; }
  function apply(){ var q=F.q.toLowerCase();
    cur=ALL.filter(function(x){ return (!F.prio||x.prio===F.prio)&&(!F.cat||x.cat===F.cat)&&(!F.cs||x.cs===F.cs)&&(!F.band||x.band===F.band)&&(!F.appr||x.appr===F.appr)&&(!F.rep||x.rep===F.rep)&&(!F.pay||x.pay===F.pay)&&(!q||(x.c+' '+x.n+' '+x.rep+' '+x.b).toLowerCase().indexOf(q)>=0); });
    cur.sort(function(a,b){ var k=sort.k,x=a[k],y=b[k]; if(x==null) x=-1e18; if(y==null) y=-1e18; return (typeof x==='number'?(x-y):String(x).localeCompare(String(y)))*sort.dir; });
    var o=0,rt=0,se=0,ex=0,bs=0,tg=0,rs=0,crit=0,blk=0,ap=0,bands={},cats={}; cur.forEach(function(x){ o+=x.odb; rt+=x.ret; se+=x.sent; ex+=x.exp; bs+=x.base; tg+=x.tgt; rs+=x.res; if(x.prio.charAt(0)==='1') crit++; if(x.cs.indexOf('BLOCKED')===0) blk++; if(x.appr.indexOf('YES')===0) ap++; bands[x.band]=(bands[x.band]||0)+1; cats[x.cat]=(cats[x.cat]||0)+1; });
    $('kN').textContent=plF0(cur.length); $('kC').textContent=plF0(crit); $('kBl').textContent=plF0(blk); $('kO').textContent=plF0(o); $('kQ').textContent=plF0(rt+se); $('kE').textContent=plF0(ex); $('kAp').textContent=plF0(ap);
    $('fN').textContent=plF0(cur.length)+' customers'; $('fO').textContent=plF0(o); $('fR').textContent=plF0(rt); $('fS').textContent=plF0(se); $('fE').textContent=plF0(ex); $('fB').textContent=plF0(bs); $('fT').textContent=plF0(tg); $('fRs').textContent=plF0(rs);
    var bk=Object.keys(bands).sort(); bars('chBand',bk.map(function(k){return [k,bands[k],bands[k],BC[k.charAt(0)]];}),function(it){ return it[2]+' customers'; },Math.max.apply(null,bk.map(function(k){return bands[k];}).concat([1])));
    var ck=Object.keys(cats).sort(); bars('chCat',ck.map(function(k){return [k,cats[k],cats[k],CC[k.charAt(0)]];}),function(it){ return it[2]+' customers'; },Math.max.apply(null,ck.map(function(k){return cats[k];}).concat([1])));
    var top=cur.slice().sort(function(a,b){return b.exp-a.exp;}).slice(0,10); bars('chTop',top.map(function(x){return [x.n,x.exp,x.exp,'linear-gradient(90deg,#fca5a5,#dc2626)'];}),function(it){ return plF0(it[1]); },Math.max.apply(null,top.map(function(c){return c.exp;}).concat([1])));
    T.setRows(cur); var u=new URLSearchParams({export:1,q:F.q,cat:F.cat,cs:F.cs,prio:F.prio,band:F.band,appr:F.appr,rep:F.rep,pay:F.pay}); $('fX').href='?'+u.toString(); }
  var tm=null; $('fQ').oninput=function(){ F.q=this.value; clearTimeout(tm); tm=setTimeout(apply,120); };
  Object.keys(ids).forEach(function(k){ $(ids[k][0]).onchange=function(){ F[k]=this.value; apply(); }; });
  $('fReset').onclick=function(){ Object.keys(F).forEach(function(k){F[k]='';}); $('fQ').value=''; Object.keys(ids).forEach(function(k){ $(ids[k][0]).value=''; }); apply(); };
  /* detail modal */
  var DM=$('dm'); document.body.appendChild(DM);
  $('tb').addEventListener('click',function(e){ var tr=e.target.closest('tr.r'); if(!tr) return; var x=cur[+tr.dataset.i]; if(!x) return;
    $('dT').textContent=x.n; $('dS').textContent=x.c+' · '+x.rep+' · '+x.b;
    function g(l,v){ return '<div><small>'+l+'</small><b>'+v+'</b></div>'; }
    var facts='<div class="gr" style="margin-top:10px">'+g('Approval required',esc(x.appr))+g('Open overdue invoices',x.odn)+g('Open overdue balance',plF2(x.odb))+g('Max days over (open)',x.odd==null?'—':x.odd)+g('Unsettled returned cheques',plF2(x.ret))+g('Unsettled sent-back cheques',plF2(x.sent))+g('Total visible exposure','<span style="color:#b91c1c">'+plF2(x.exp)+'</span>')
      +g('Violations',x.viol)+g('Avg days over',x.avgd==null?'—':x.avgd.toFixed(1))+g('Returned cheques',x.rcq)+g('Repeat violator',x.repv?'Yes':'No')+g('Restorable if authorised',plF2(x.res))+'</div>';
    var d=Object.assign({},x,{a3:x.a3m,a6:x.a6mv}); $('dB').innerHTML=plDetail(d,facts); DM.classList.add('open'); });
  $('dX').onclick=function(){ DM.classList.remove('open'); }; DM.addEventListener('click',function(e){ if(e.target===DM) DM.classList.remove('open'); }); document.addEventListener('keydown',function(e){ if(e.key==='Escape') DM.classList.remove('open'); });
  apply();
})();
</script>
<?php include 'footer.php'; ?>
