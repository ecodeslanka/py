<?php
/**
 * plan_target_scenarios.php — Analysis → Target Scenarios (Min / Moderate / Max risk)
 * Enter the Unilever monthly target (company total) and, if given, rep-wise targets. Every outlet's achievable sales in three scenarios
 * (workbook sheet 14) are used to split the target down to outlet level: inside a rep in proportion to the outlet's scenario sales,
 * and whatever is not covered by rep targets across the remaining reps' outlets the same way.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/plan_lib.php'; require_once __DIR__ . '/plan_model_lib.php';
pl_q($conn, "CREATE TABLE IF NOT EXISTS plan_targets (ym CHAR(7) NOT NULL, rep VARCHAR(150) NOT NULL DEFAULT '', amount DECIMAL(16,2) NOT NULL DEFAULT 0, updated_by VARCHAR(100), updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (ym, rep)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (($_GET['ajax'] ?? '') === 'targets') {
    $ym = preg_match('/^\d{4}-\d{2}$/', $_GET['ym'] ?? '') ? $_GET['ym'] : ''; $t = ['total' => '', 'reps' => []];
    $r = pl_q($conn, "SELECT rep, amount FROM plan_targets WHERE ym = '$ym'"); while ($r && ($x = mysqli_fetch_assoc($r))) { if ($x['rep'] === '') $t['total'] = (float)$x['amount']; else $t['reps'][$x['rep']] = (float)$x['amount']; }
    pl_json(['ok' => true] + $t);
}
$M = pm_get($conn, isset($_GET['recalc'])); pl_detail_endpoint($M);
$S = hs_settings($conn); $openYm = hs_add($S['first_cycle'], $S['months']); $openRg = hs_range($openYm, $S['start_day']);
$J = pl_slim($M['rows'], ['c', 'n', 'repc', 'rep', 'b', 'ch', 'pay', 'cat', 'risk', 'cs', 'base', 'tgt', 'todate', 'min', 'mod', 'max', 'mxr', 'basis', 'risk_max', 'pgo', 'res', 'nm']);
$months = []; for ($i = -2; $i <= 3; $i++) $months[] = hs_add($openYm, $i);
ob_end_flush();
include 'header.php';
echo pl_css();
$mid = '<div class="card"><div class="row" style="align-items:flex-end">
  <div class="fld"><label>Target month (sales cycle)</label><select id="tYm">' . implode('', array_map(fn($m) => '<option value="' . $m . '"' . ($m === $openYm ? ' selected' : '') . '>' . hs_label($m) . ($m === $openYm ? ' (open cycle)' : '') . '</option>', $months)) . '</select></div>
  <div class="fld"><label>Unilever monthly target — company total</label><div id="tTotV" style="font-size:20px;font-weight:800;color:#1e3a8a;padding:4px 0">—</div></div>
  <div class="fld"><label>Scenario for the outlet split</label><select id="tSc"><option value="min">Minimum risk</option><option value="mod" selected>Moderate risk</option><option value="max">Maximum risk</option></select></div>
  <a class="btn" id="tEdit" href="plan_target_entry.php"><i class="fa-solid fa-pen-to-square"></i> Enter / edit targets</a></div>
  <div class="grid3" style="margin:14px 0 0" id="scCards"></div>
  <div class="tw" style="margin-top:6px"><table style="width:100%"><thead><tr><th class="l">Sales Rep</th><th>Outlets</th><th>Baseline</th><th>Sales to date</th><th>Min-risk</th><th>Moderate</th><th>Max-risk</th><th style="background:#92400e">Rep target</th><th>Scenario forecast</th><th>Gap (target − forecast)</th><th>Coverage</th></tr></thead><tbody id="repB"></tbody><tfoot id="repF"></tfoot></table></div></div>';
echo pl_frame('Target Scenarios — Min · Moderate · Max risk', 'fa-bullseye', 'linear-gradient(120deg,#0f172a,#1e3a8a 55%,#6366f1)',
    'The monthly and rep-wise targets are entered on <a href="plan_target_entry.php">Target Entry</a>. The model shows how much each outlet can deliver in three scenarios, then splits the target down to every <b>outlet code</b>. '
    . '<b>Minimum risk</b> = risk-adjusted target (no extra credit) · <b>Moderate</b> = minimum + half of the extra upside for outlets with risk ≤ 40 that are not blocked · <b>Maximum risk</b> = demand if restrictions are relaxed (block lifted, +10 %, peer gap, reactivation). '
    . 'Open cycle ' . hs_label($openYm) . ': ' . $openRg[0] . ' → ' . $openRg[1] . '. Data from the system on <b>' . pl_h($M['built_at']) . '</b>. <a href="?recalc=1"><i class="fa-solid fa-rotate"></i> Recalculate</a>', $mid);
echo pl_js();
?>
<script>
(function(){
  var ALL=<?= json_encode(array_values($J), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?>, OPEN=<?= json_encode($openYm) ?>, $=function(i){return document.getElementById(i);}, esc=plEsc;
  var REPS={}, repList=[]; ALL.forEach(function(x){ var r=x.repc||x.rep||'UNASSIGNED'; x.repc=r; x.rep=x.rep||r; if(!REPS[r]){ REPS[r]=x.rep; repList.push(r); } }); repList.sort(function(a,b){ return String(REPS[a]).localeCompare(String(REPS[b])); });   /* reps are keyed by REP CODE */
  var TG={total:'',reps:{}}, P=null;
  /* scenario values for the chosen month: the open cycle counts sales to date, a future month starts from zero */
  function scen(x,ym){ if(ym===OPEN) return {min:x.min,mod:x.mod,max:x.max};
    var mn=Math.round(Math.max(x.tgt,0)/100)*100, mx=Math.max(x.mxr,mn), md=(x.cs.indexOf('BLOCKED')!==0&&x.risk<=40)?Math.round((mn+0.5*(mx-mn))/100)*100:mn; return {min:mn,mod:md,max:mx}; }
  function compute(){ var ym=$('tYm').value, sc=$('tSc').value, T=parseFloat(TG.total)||0, rt={}, sumR=0, open=ym===OPEN;
    repList.forEach(function(r){ var v=parseFloat(TG.reps[r])||0; if(v>0){ rt[r]=v; sumR+=v; } });
    var byRep={}, tot={min:0,mod:0,max:0,base:0,todate:0};
    ALL.forEach(function(x){ var s=scen(x,ym); x.smin=s.min; x.smod=s.mod; x.smax=s.max; x.sel=s[sc]; x.td=open?x.todate:0; var r=byRep[x.repc]=byRep[x.repc]||{n:0,min:0,mod:0,max:0,base:0,todate:0,sel:0};
      r.n++; r.min+=s.min; r.mod+=s.mod; r.max+=s.max; r.base+=x.base; r.todate+=x.td; r.sel+=x.sel; tot.min+=s.min; tot.mod+=s.mod; tot.max+=s.max; tot.base+=x.base; tot.todate+=x.td; });
    /* rest of the company target goes to reps without an own target */
    var rest=Math.max(0,T-sumR), restBase=0; repList.forEach(function(r){ if(!rt[r]) restBase+=byRep[r].sel; });
    ALL.forEach(function(x){ var r=byRep[x.repc], tr=rt[x.repc];
      if(tr) x.req=r.sel>0?x.sel*tr/r.sel:tr/r.n; else if(T>0) x.req=restBase>0?x.sel*rest/restBase:0; else x.req=x.sel;
      x.req=Math.round(x.req/100)*100; x.stretch=x.sel>0?x.req/x.sel-1:(x.req>0?1:0); x.bal=Math.max(0,x.req-x.td); x.xexp=(x.pay==='Credit'||x.pay==='Cheque'||x.cs.indexOf('BLOCKED')===0)?Math.max(0,x.req-x.smin):0; });
    /* scenario cards */
    var L={min:['Minimum risk','fa-shield','#16a34a'],mod:['Moderate risk','fa-scale-balanced','#f59e0b'],max:['Maximum risk','fa-fire','#dc2626']};
    $('scCards').innerHTML=['min','mod','max'].map(function(k){ var v=tot[k], gap=T>0?T-v:null, cov=T>0?v/T:null;
      return '<div class="card" style="border-top:5px solid '+L[k][2]+(k===sc?';box-shadow:0 0 0 2px '+L[k][2]:'')+'"><div class="ct"><i class="fa-solid '+L[k][1]+'" style="color:'+L[k][2]+'"></i> '+L[k][0]+(k===sc?' <span class="tag t-eq">used for the split</span>':'')+'</div>'
        +'<div style="font-size:24px;font-weight:800">Rs. '+plF0(v)+'</div><div style="font-size:12.5px;color:#475569;margin-top:4px">'+(T>0?('Target Rs. '+plF0(T)+' · <b style="color:'+(gap>0?'#b91c1c':'#15803d')+'">'+(gap>0?'short by Rs. '+plF0(gap):'covers the target (+'+plF0(-gap)+')')+'</b> · '+(cov*100).toFixed(1)+'% of target'):'Enter the monthly target to see the gap')+'</div>'
        +'<div style="height:10px;background:#f1f5f9;border-radius:99px;margin-top:8px;overflow:hidden"><i style="display:block;height:100%;width:'+Math.min(100,(cov||0)*100)+'%;background:'+L[k][2]+'"></i></div>'
        +'<div style="font-size:12px;color:#64748b;margin-top:6px">vs baseline Rs. '+plF0(tot.base)+' ('+(tot.base>0?((v/tot.base-1)*100).toFixed(1):'0')+'%)'+(ym===OPEN?' · sales to date Rs. '+plF0(tot.todate):'')+'</div></div>'; }).join('');
    /* rep table — rows are built once per month (so typing in a box keeps its focus); only the numbers are refreshed here */
    var tr={n:0,base:0,todate:0,min:0,mod:0,max:0,tg:0,sel:0};
    repList.forEach(function(r,i){ var o=byRep[r], t=rt[r]||0, sel=o[sc], row=document.querySelector('#repB tr[data-i="'+i+'"]'); ['n','base','todate','min','mod','max'].forEach(function(k){ tr[k]+=o[k]; }); tr.tg+=t; tr.sel+=sel; if(!row) return;
      var c=row.querySelectorAll('td'); c[1].textContent=o.n; c[2].textContent=plF0(o.base); c[3].textContent=plF0(o.todate); c[4].textContent=plF0(o.min); c[5].textContent=plF0(o.mod); c[6].textContent=plF0(o.max);
      c[8].innerHTML='<b>'+plF0(sel)+'</b>'; c[9].innerHTML=t?'<b style="color:'+(t>sel?'#b91c1c':'#15803d')+'">'+plF0(t-sel)+'</b>':''; c[10].textContent=t?(sel/t*100).toFixed(1)+'%':''; });
    $('repF').innerHTML='<tr><td class="l">TOTAL</td><td>'+tr.n+'</td><td>'+plF0(tr.base)+'</td><td>'+plF0(tr.todate)+'</td><td>'+plF0(tr.min)+'</td><td>'+plF0(tr.mod)+'</td><td>'+plF0(tr.max)+'</td><td>'+plF0(tr.tg)+'</td><td>'+plF0(tr.sel)+'</td><td>'+(tr.tg?plF0(tr.tg-tr.sel):'')+'</td><td>'+(tr.tg?(tr.sel/tr.tg*100).toFixed(1)+'%':'')+'</td></tr>';
  }
  function buildReps(){ $('repB').innerHTML=repList.map(function(r,i){ return '<tr data-i="'+i+'"><td class="l"><b>'+esc(REPS[r])+'</b></td><td></td><td></td><td></td><td></td><td></td><td></td><td style="background:#fffbeb;font-weight:700">'+(TG.reps[r]?plF0(TG.reps[r]):'<span class="z">—</span>')+'</td><td></td><td></td><td></td></tr>'; }).join(''); }
  var SC={min:'Min-risk',mod:'Moderate',max:'Max-risk'};
  P=plPage({data:ALL,exportTitle:'Target Scenarios — outlet split',exportName:'target_scenarios',exportSub:'Outlet targets split from the monthly target in proportion to the chosen scenario.',sort:{k:'req',dir:-1},
    filters:[{k:'rep',label:'Sales rep'},{k:'b',label:'Beat'},{k:'ch',label:'Channel'},{k:'cat',label:'Category'},{k:'pay',label:'Pay mode'}],
    cols:[{k:'c',h:'HUL',t:'s',s1:1,w:18},{k:'n',h:'Customer',t:'s',s2:1,w:30},{k:'rep',h:'Sales Rep',t:'s',w:26},{k:'b',h:'Beat',t:'s',w:22},{k:'ch',h:'Channel',t:'s',fmt:function(x){return plChip(x.ch);},w:22},{k:'pay',h:'Pay',t:'s',fmt:function(x){return plPm(x.pay);},w:9},
      {k:'cat',h:'Category',t:'s',w:24},{k:'risk',h:'Risk',fmt:function(x){return plMeter(x.risk);},xt:'d',w:8},{k:'base',h:'Baseline',tot:1},{k:'td',h:'Sales to date',tot:1},
      {k:'smin',h:'Min-risk',tot:1},{k:'smod',h:'Moderate',tot:1},{k:'smax',h:'Max-risk',tot:1},
      {k:'req',h:'Required target',tot:1,cls:'',fmt:function(x){ return '<b style="color:#1e3a8a">'+plF0(x.req)+'</b>'; },w:16},
      {k:'stretch',h:'Stretch vs scenario',fmt:function(x){ var v=x.stretch; return Math.abs(v)<0.005?'<span class="z">—</span>':'<span class="tag '+(v>0.2?'t-dn':(v>0?'t-wa':'t-up'))+'">'+(v>0?'+':'')+(v*100).toFixed(1)+'%</span>'; },xt:'p',w:12},
      {k:'bal',h:'Balance to sell',tot:1},{k:'xexp',h:'Extra credit exposure',tot:1,w:16},{k:'basis',h:'Max-risk basis',t:'s',w:40}],
    kpis:function(rows,t){ var str=rows.filter(function(x){return x.stretch>0.2;}).length;
      $('kp').innerHTML=plKpi([['Outlets',plF0(rows.length),'fa-store',1],['Required (outlet split)',plF0(t.req),'fa-bullseye',2],['Sales to date',plF0(t.td),'fa-cash-register',3],['Balance to sell',plF0(t.bal),'fa-hourglass-half',4],['Outlets asked > 20 % above scenario',plF0(str),'fa-triangle-exclamation',6],['Extra credit exposure',plF0(t.xexp),'fa-credit-card',5]]); },
    onRow:function(x){ $('dT').textContent=x.n; $('dS').textContent=x.c+' · '+x.rep+' · '+x.b; $('dB').innerHTML='Loading…'; $('dF').innerHTML=''; D.open();
      plGet(x.c).then(function(m){ var sc='<div class="sec"><h4><i class="fa-solid fa-bullseye"></i> Scenarios for this outlet</h4><table class="rk"><thead><tr><th>Scenario</th><th style="text-align:right">LKR</th><th>What it assumes</th></tr></thead><tbody>'
          +'<tr><td><b>Minimum risk</b></td><td class="n">'+plF0(x.smin)+'</td><td>Risk-adjusted target'+(x.td?' (or sales to date if higher)':'')+'; no extra credit exposure.</td></tr>'
          +'<tr><td><b>Moderate</b></td><td class="n">'+plF0(x.smod)+'</td><td>'+(x.smod>x.smin?'Minimum + half of the extra upside (risk ≤ 40, not blocked).':'Stays at minimum — risk above 40 or blocked.')+'</td></tr>'
          +'<tr><td><b>Maximum risk</b></td><td class="n">'+plF0(x.smax)+'</td><td>'+esc(x.basis)+'. Risk taken: '+esc(x.risk_max)+'.</td></tr>'
          +'<tr><td><b>Required (your target split)</b></td><td class="n" style="color:#1e3a8a"><b>'+plF0(x.req)+'</b></td><td>'+(x.stretch>0.005?'Asks '+(x.stretch*100).toFixed(1)+'% more than the chosen scenario — needs extra push or credit.':'Within the chosen scenario.')+' Balance to sell '+plF0(x.bal)+'.</td></tr></tbody></table></div>';
        $('dB').innerHTML=sc+plDetail(m); }).catch(function(e){ $('dB').textContent=e.message; }); }});
  var D=plModal('dm');
  function loadTargets(){ $('tEdit').href='plan_target_entry.php?ym='+$('tYm').value;
    fetch('plan_target_scenarios.php?ajax=targets&ym='+$('tYm').value,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){ TG={total:j.total,reps:j.reps||{}}; $('tTotV').innerHTML=j.total?'Rs. '+plF0(j.total):'<span style="font-size:13px;color:#b45309">No target entered for this month</span>'; buildReps(); compute(); P.apply(); })
      .catch(function(){ TG={total:'',reps:{}}; buildReps(); compute(); P.apply(); }); }
  $('tYm').onchange=loadTargets; $('tSc').onchange=function(){ compute(); P.apply(); };
  loadTargets();
})();
</script>
<?php include 'footer.php'; ?>
