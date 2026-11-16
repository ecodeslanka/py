<?php
/** plan_development_report.php — Analysis → Development Opportunities (ranked low-risk outlets with headroom). Data: Import Planning Data. */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/plan_lib.php'; require_once __DIR__ . '/plan_model_lib.php';
$M = pm_get($conn, isset($_GET['recalc'])); $imp = ['filename' => 'system data', 'imported_at' => $M['built_at']];
$dev = array_values(array_filter($M['rows'], fn($x) => in_array($x['cat'][0], ['1', '2'], true) && $x['risk'] <= 20 && strpos($x['cs'], 'BLOCKED') !== 0 && $x['add'] > 0));
usort($dev, fn($a, $b) => ($b['add'] + $b['pgo']) <=> ($a['add'] + $a['pgo'])); $rows = [];   // rank = additional sales + 25% of the remaining channel-median gap
foreach ($dev as $i => $x) $rows[] = ['rank_no' => $i + 1, 'hul' => $x['c'], 'customer_name' => $x['n'], 'sales_rep' => $x['rep'], 'beat' => $x['b'], 'channel' => $x['ch'], 'category' => $x['cat'], 'risk_score' => $x['risk'], 'pay_mode' => $x['pay'],
    'avg12' => $x['a12'], 'baseline' => $x['base'], 'target' => $x['tgt'], 'additional' => $x['add'], 'growth' => $x['gr'], 'med' => $x['med'], 'pgo' => $x['pgo'], 'a6m' => $x['a6m'], 'm' => $x];
/* same filters as the screen (used by the Excel export) */
$F = ['q' => trim($_GET['q'] ?? ''), 'cat' => trim($_GET['cat'] ?? ''), 'ch' => trim($_GET['ch'] ?? ''), 'rep' => trim($_GET['rep'] ?? ''), 'beat' => trim($_GET['beat'] ?? ''), 'pay' => trim($_GET['pay'] ?? '')];

if (isset($_GET['export']) && class_exists('ZipArchive')) {
    require_once __DIR__ . '/rbac_xlsx.php'; while (ob_get_level()) ob_end_clean();
    $rows = array_values(array_filter($rows, function ($x) use ($F) {
        if ($F['cat'] !== '' && $x['category'] !== $F['cat']) return false; if ($F['ch'] !== '' && $x['channel'] !== $F['ch']) return false; if ($F['rep'] !== '' && $x['sales_rep'] !== $F['rep']) return false;
        if ($F['beat'] !== '' && $x['beat'] !== $F['beat']) return false; if ($F['pay'] !== '' && $x['pay_mode'] !== $F['pay']) return false;
        return $F['q'] === '' || stripos($x['hul'] . ' ' . $x['customer_name'] . ' ' . $x['sales_rep'] . ' ' . $x['beat'], $F['q']) !== false; }));
    $X = new MiniXlsx(); $sh = $X->sheet('Development Opportunities'); $sh->tab = '1F3864'; $sh->landscape = true;
    $FM = ['font' => 'Arial']; $NUM = '#,##0;\(#,##0\);"-"'; $NAVY = '1F3864'; $HS = $FM + ['b' => 1, 'color' => 'FFFFFF', 'bg' => $NAVY, 'h' => 'center', 'wrap' => 1, 'border' => 1];
    $sh->set(1, 1, 'Outlet Development Opportunities — ranked low-risk outlets with headroom', $FM + ['b' => 1, 'sz' => 14, 'color' => $NAVY]);
    $sh->set(2, 1, 'Calculated from the system data on ' . ($imp['imported_at'] ?? '') . '. Rank = additional sales + 25% of the remaining gap to the channel median. Recommended target = ROUND(MIN(baseline + growth, capacity), -2); additional sales = target − baseline.', $FM + ['sz' => 9, 'color' => '595959']);
    foreach (['Rank', 'HUL', 'Customer Name', 'Sales Rep', 'Beat', 'Secondary Channel', 'Category', 'Risk Score', 'Pay Mode', 'Avg 12M', 'Baseline', 'Recommended Target', 'Additional Sales (confirmed)', 'Growth %', 'Channel Median Baseline', 'Peer Gap Opportunity (verify)', 'Active of last 6M'] as $i => $h) $sh->set(4, $i + 1, $h, $HS);
    $sh->heights[4] = 36; $r = 5;
    foreach ($rows as $x) { foreach ([(int)$x['rank_no'], $x['hul'], $x['customer_name'], $x['sales_rep'], $x['beat'], $x['channel'], $x['category']] as $i => $v) $sh->set($r, $i + 1, $v, $FM);
        $sh->set($r, 8, (float)$x['risk_score'], $FM + ['nfs' => '0.0', 'h' => 'center']); $sh->set($r, 9, $x['pay_mode'], $FM + ['h' => 'center']);
        foreach ([10 => 'avg12', 11 => 'baseline'] as $c => $k) $sh->set($r, $c, (float)$x[$k], $FM + ['nfs' => $NUM]);
        $sh->set($r, 12, (float)$x['target'], $FM + ['nfs' => $NUM]); $sh->set($r, 13, (float)$x['additional'], $FM + ['nfs' => $NUM]); $sh->set($r, 14, (float)$x['growth'], $FM + ['nfs' => '0.0%;\(0.0%\);"-"']); $sh->set($r, 15, (float)$x['med'], $FM + ['nfs' => $NUM]); $sh->set($r, 16, (float)$x['pgo'], $FM + ['nfs' => $NUM]); $sh->set($r, 17, (int)$x['a6m'], $FM + ['h' => 'center', 'nfs' => '0']); $r++; }
    $last = max(5, $r - 1); $rt = $r + 1; $TB = $FM + ['b' => 1, 'bg' => 'D9E1F2', 'border' => 1, 'nfs' => $NUM, 'color' => $NAVY];
    for ($c = 1; $c <= 17; $c++) $sh->set($rt, $c, '', $TB); $sh->set($rt, 1, 'TOTAL', $TB); $sh->set($rt, 3, count($rows) . ' outlets', $TB);
    foreach ([10, 11, 12, 13, 16] as $c) { $L = MiniXlsx::col($c); $sum = 0; foreach ($rows as $x) $sum += (float)$x[[10 => 'avg12', 11 => 'baseline', 12 => 'target', 13 => 'additional', 16 => 'pgo'][$c]]; $sh->set($rt, $c, round($sum, 2), $TB + ['f' => "SUM({$L}5:{$L}$last)"]); }
    foreach ([6, 18, 30, 26, 24, 24, 24, 11, 11, 14, 14, 18, 20, 11, 18, 18, 12] as $i => $w) $sh->widths[$i + 1] = $w; $sh->widths[1] = 7; $sh->widths[2] = 18; $sh->freeze = [4, 3]; $sh->filter = 'A4:Q' . $last; $sh->titles = ['rows' => '4:4'];
    $sh->condfmt[] = ['M5:M' . $last, 'FFFFFF', '63BE7B', 95];
    $tmp = tempnam(sys_get_temp_dir(), 'pd'); $X->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="development_opportunities_' . date('Ymd_His') . '.xlsx"'); header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
$J = array_map(fn($x) => ['r' => (int)$x['rank_no'], 'c' => $x['hul'], 'n' => $x['customer_name'], 'rep' => $x['sales_rep'], 'b' => $x['beat'], 'ch' => $x['channel'], 'cat' => $x['category'], 'risk' => (float)$x['risk_score'], 'pay' => $x['pay_mode'],
    'a12' => round((float)$x['avg12'], 2), 'base' => round((float)$x['baseline'], 2), 'tgt' => round((float)$x['target'], 2), 'add' => round((float)$x['additional'], 2), 'gr' => (float)$x['growth'], 'med' => round((float)$x['med'], 2), 'pgo' => round((float)$x['pgo'], 2), 'a6' => (int)$x['a6m'],
    'cs' => $x['m']['cs'], 'prio' => $x['m']['prio'], 'band' => $x['m']['band'], 'rk' => $x['m']['rk'], 'why' => $x['m']['why'], 'acts' => $x['m']['acts'], 'cap' => $x['m']['cap'], 'gpot' => $x['m']['gpot'], 'c78' => $x['m']['c78'], 'a3m' => $x['m']['a3'], 'a6mv' => $x['m']['a6'], 'v' => $x['m']['v'], 'conf' => $x['m']['conf']], $rows);
ob_end_flush();
include 'header.php';
echo pl_css();
?>
<div class="pl">
  <div class="hero" style="background:linear-gradient(120deg,#052e16 0%,#047857 55%,#10b981 100%)"><h2><i class="fa-solid fa-seedling"></i> Development Opportunities</h2>
    <p>Ranked low-risk outlets with headroom — the outlets where extra sales are confirmed possible. <b>Rank = additional sales + 25% of the remaining gap to the channel median · target = ROUND(MIN(baseline + growth, capacity), −2).</b> Click a column title to sort · <b>click an outlet</b> to see how its risk score, category and target were made, and the actions. Calculated live from the system data (sales history, customers, bills, cheques) on <b><?= pl_h($M['built_at']) ?></b>. <a href="?recalc=1"><i class="fa-solid fa-rotate"></i> Recalculate now</a></p></div>
  <?php if (!$rows): ?><div class="card" style="color:#92400e;background:#fffbeb">No sales history found yet — import the Invoice Wise Sales file (Analysis → Import Secondary Invoices) first.</div><?php endif; ?>
  <div class="card"><div class="row">
    <div class="fld"><label>Search</label><input id="fQ" type="search" placeholder="HUL, customer, rep, beat"></div>
    <div class="fld"><label>Category</label><select id="fCat"></select></div><div class="fld"><label>Secondary channel</label><select id="fCh"></select></div>
    <div class="fld"><label>Sales rep</label><select id="fRep"></select></div><div class="fld"><label>Beat</label><select id="fBeat"></select></div><div class="fld"><label>Pay mode</label><select id="fPay"></select></div>
    <button class="btn o" id="fReset" type="button">Reset</button><a class="btn g" id="fX" data-no-loader href="#"><i class="fa-solid fa-file-excel"></i> Export Excel</a></div></div>
  <div class="kp"><div class="k k1"><i class="fa-solid fa-store"></i><span>Outlets</span><b id="kN">0</b></div><div class="k k2"><i class="fa-solid fa-chart-line"></i><span>Avg 12M (current)</span><b id="kA">0</b></div><div class="k k7"><i class="fa-solid fa-scale-balanced"></i><span>Baseline</span><b id="kB">0</b></div><div class="k k3"><i class="fa-solid fa-bullseye"></i><span>Recommended target</span><b id="kT">0</b></div><div class="k k4"><i class="fa-solid fa-circle-plus"></i><span>Additional sales (confirmed)</span><b id="kD">0</b></div><div class="k k5"><i class="fa-solid fa-arrow-trend-up"></i><span>Uplift on baseline</span><b id="kG">0%</b></div></div>
  <div class="grid3"><div class="card"><div class="ct"><i class="fa-solid fa-layer-group" style="color:#059669"></i> By category</div><div id="chCat"></div></div>
    <div class="card"><div class="ct"><i class="fa-solid fa-store" style="color:#4f46e5"></i> Additional sales by channel (top 8)</div><div id="chCh"></div></div>
    <div class="card"><div class="ct"><i class="fa-solid fa-trophy" style="color:#f59e0b"></i> Top 10 outlets by additional sales</div><div id="chTop"></div></div></div>
  <div class="tw" id="tw"><table><thead id="th"><tr><th class="s1 l" data-k="c" data-t="s">HUL</th><th class="s2 l" data-k="n" data-t="s">Customer Name</th><th data-k="r" data-t="n">Rank</th><th class="l" data-k="rep" data-t="s">Sales Rep</th><th class="l" data-k="b" data-t="s">Beat</th><th class="l" data-k="ch" data-t="s">Secondary Channel</th><th class="l" data-k="cat" data-t="s">Category</th><th data-k="risk" data-t="n">Risk Score</th><th class="l" data-k="pay" data-t="s">Pay Mode</th><th data-k="a12" data-t="n">Avg 12M</th><th data-k="base" data-t="n">Baseline</th><th data-k="tgt" data-t="n">Recommended Target</th><th data-k="add" data-t="n">Additional Sales</th><th data-k="gr" data-t="n">Growth %</th><th data-k="med" data-t="n">Channel Median</th><th data-k="pgo" data-t="n">Peer Gap Opp.</th><th data-k="a6" data-t="n">Active 6M</th></tr></thead><tbody id="tb"></tbody>
    <tfoot><tr><td class="l s1" style="z-index:4">TOTAL</td><td class="l s2" style="z-index:4" id="fN"></td><td colspan="7"></td><td id="fA"></td><td id="fB"></td><td id="fT"></td><td id="fD"></td><td></td><td></td><td id="fP"></td><td></td></tr></tfoot></table></div>
</div>
<div class="plm" id="dm"><div class="bx"><div class="hd" style="background:linear-gradient(120deg,#052e16,#047857 55%,#10b981)"><div><b id="dT" style="font-size:18px"></b><div id="dS" style="font-size:12.5px;opacity:.85"></div></div><button type="button" id="dX">&times;</button></div><div class="bd" id="dB"></div></div></div>
<?= pl_js() ?>
<script>
(function(){
  var ALL=<?= json_encode($J, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?>, $=function(i){return document.getElementById(i);};
  var F={q:'',cat:'',ch:'',rep:'',beat:'',pay:''}, cur=ALL, sort={k:'r',dir:1};
  function opts(id,key,label){ var s={},a=[]; ALL.forEach(function(x){ if(x[key]!==''&&!s[x[key]]){ s[x[key]]=1; a.push(x[key]); } }); a.sort(); $(id).innerHTML='<option value="">All</option>'+a.map(function(v){return '<option>'+plEsc(v)+'</option>';}).join(''); }
  opts('fCat','cat'); opts('fCh','ch'); opts('fRep','rep'); opts('fBeat','b'); opts('fPay','pay');
  function riskCol(v){ return v<=20?'#16a34a':(v<=40?'#f59e0b':(v<=60?'#ea580c':'#dc2626')); }
  var CC={'1':'#059669','2':'#0ea5e9'};
  var T=PLVT({tw:$('tw'),tb:$('tb'),cols:17,rh:38,height:'68vh',rowHtml:function(x,i){
    return '<tr class="r'+(i%2?' ev':'')+'" data-i="'+i+'"><td class="l s1">'+plEsc(x.c)+'</td><td class="l s2" title="'+plEsc(x.n)+'">'+plEsc(x.n)+'</td><td><b>'+x.r+'</b></td><td class="l">'+plEsc(x.rep)+'</td><td class="l">'+plEsc(x.b)+'</td><td class="l">'+plChip(x.ch)+'</td><td class="l">'+plChip(x.cat,CC[x.cat.charAt(0)])+'</td>'
     +'<td><span class="meter"><i style="width:'+Math.min(100,x.risk)+'%;background:'+riskCol(x.risk)+'"></i></span>'+x.risk.toFixed(1)+'</td><td class="l">'+plPm(x.pay)+'</td><td>'+plF0(x.a12)+'</td><td>'+plF0(x.base)+'</td><td><b>'+plF0(x.tgt)+'</b></td><td style="color:#047857;font-weight:700">+'+plF0(x.add)+'</td><td><span class="pl2">'+(x.gr*100).toFixed(1)+'%</span></td><td>'+plF0(x.med)+'</td><td style="color:#b45309">'+(x.pgo?plF0(x.pgo):'<span class="z">—</span>')+'</td><td>'+x.a6+' / 6</td></tr>'; }});
  plSortable($('th'),function(){return cur;},function(r){ cur=r; T.setRows(cur); },sort);
  function bars(id,items,fmt,max){ $(id).innerHTML=items.map(function(it){ return '<div class="hb"><span class="lb" title="'+plEsc(it[0])+'">'+plEsc(it[0])+'</span><span class="tr"><i style="width:'+Math.max(2,it[1]/max*100)+'%;'+(it[3]?'background:'+it[3]:'')+'"></i></span><span class="vl">'+fmt(it)+'</span></div>'; }).join('')||'<div style="color:#94a3b8;font-size:12px">—</div>'; }
  function apply(){ var q=F.q.toLowerCase();
    cur=ALL.filter(function(x){ return (!F.cat||x.cat===F.cat)&&(!F.ch||x.ch===F.ch)&&(!F.rep||x.rep===F.rep)&&(!F.beat||x.b===F.beat)&&(!F.pay||x.pay===F.pay)&&(!q||(x.c+' '+x.n+' '+x.rep+' '+x.b).toLowerCase().indexOf(q)>=0); });
    cur.sort(function(a,b){ var k=sort.k,num=typeof a[k]==='number'; return (num?(a[k]-b[k]):String(a[k]).localeCompare(String(b[k])))*sort.dir; });
    var a12=0,base=0,tgt=0,add=0,pgo=0,cat={},ch={}; cur.forEach(function(x){ pgo+=x.pgo; a12+=x.a12; base+=x.base; tgt+=x.tgt; add+=x.add; (cat[x.cat]=cat[x.cat]||[0,0]); cat[x.cat][0]++; cat[x.cat][1]+=x.add; ch[x.ch]=(ch[x.ch]||0)+x.add; });
    $('kN').textContent=plF0(cur.length); $('kA').textContent=plF0(a12); $('kB').textContent=plF0(base); $('kT').textContent=plF0(tgt); $('kD').textContent=plF0(add); $('kG').textContent=(base>0?(add/base*100).toFixed(1):'0.0')+'%';
    $('fN').textContent=plF0(cur.length)+' outlets'; $('fA').textContent=plF0(a12); $('fB').textContent=plF0(base); $('fT').textContent=plF0(tgt); $('fD').textContent=plF0(add); $('fP').textContent=plF0(pgo);
    var cats=Object.keys(cat).sort(), mx=Math.max.apply(null,cats.map(function(k){return cat[k][1];}).concat([1]));
    bars('chCat',cats.map(function(k){return [k,cat[k][1],cat[k][0],CC[k.charAt(0)]];}),function(it){ return it[2]+' outlets · +'+plF0(it[1]); },mx);
    var cl=Object.keys(ch).map(function(k){return [k,ch[k]];}).sort(function(a,b){return b[1]-a[1];}).slice(0,8); bars('chCh',cl,function(it){ return '+'+plF0(it[1]); },Math.max.apply(null,cl.map(function(c){return c[1];}).concat([1])));
    var top=cur.slice().sort(function(a,b){return b.add-a.add;}).slice(0,10); bars('chTop',top.map(function(x){return [x.n,x.add];}),function(it){ return '+'+plF0(it[1]); },Math.max.apply(null,top.map(function(c){return c.add;}).concat([1])));
    T.setRows(cur);
    var u=new URLSearchParams({export:1,q:F.q,cat:F.cat,ch:F.ch,rep:F.rep,beat:F.beat,pay:F.pay}); $('fX').href='?'+u.toString(); }
  var DM=$('dm'); document.body.appendChild(DM);
  $('tb').addEventListener('click',function(e){ var tr=e.target.closest('tr.r'); if(!tr) return; var x=cur[+tr.dataset.i]; if(!x) return;
    $('dT').textContent=x.n; $('dS').textContent=x.c+' · '+x.rep+' · '+x.b+' · '+x.ch+'  ·  Rank #'+x.r;
    var d=Object.assign({},x,{a3:x.a3m,a6:x.a6mv});
    $('dB').innerHTML=plDetail(d,'<div class="gr" style="margin-top:10px"><div><small>Avg 12M</small><b>'+plF2(x.a12)+'</b></div><div><small>Baseline</small><b>'+plF2(x.base)+'</b></div><div><small>Recommended target</small><b style="color:#047857">'+plF2(x.tgt)+'</b></div><div><small>Additional sales</small><b style="color:#047857">+'+plF2(x.add)+'</b></div><div><small>Growth</small><b>'+(x.gr*100).toFixed(1)+'%</b></div><div><small>Peer gap opportunity</small><b>'+plF2(x.pgo)+'</b></div></div>'); DM.classList.add('open'); });
  $('dX').onclick=function(){ DM.classList.remove('open'); }; DM.addEventListener('click',function(e){ if(e.target===DM) DM.classList.remove('open'); }); document.addEventListener('keydown',function(e){ if(e.key==='Escape') DM.classList.remove('open'); });
  var tm=null; function go(){ clearTimeout(tm); tm=setTimeout(apply,120); }
  $('fQ').oninput=function(){ F.q=this.value; go(); }; [['fCat','cat'],['fCh','ch'],['fRep','rep'],['fBeat','beat'],['fPay','pay']].forEach(function(p){ $(p[0]).onchange=function(){ F[p[1]]=this.value; apply(); }; });
  $('fReset').onclick=function(){ F={q:'',cat:'',ch:'',rep:'',beat:'',pay:''}; $('fQ').value=''; ['fCat','fCh','fRep','fBeat','fPay'].forEach(function(i){ $(i).value=''; }); apply(); };
  apply();
})();
</script>
<?php include 'footer.php'; ?>
