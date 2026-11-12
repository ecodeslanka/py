<?php
/* ── Dashboard data: Unilever Receivable Summary (loaded after the page, only rows the user may view) ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'ur_summary') {
    ob_start();
    include 'config.php';
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'message' => 'Please log in.']); exit; }
    require_once __DIR__ . '/unilever_receivable_lib.php';
    $d = ur_summary($conn);
    $rows = array_values(array_filter($d['rows'], fn($r) => rbac_can('view', $r['page'])));   // only what this role may open
    $tot = ['receivable' => 0, 'received' => 0, 'paid' => 0, 'balance' => 0];
    foreach ($rows as $r) foreach ($tot as $k => $_) $tot[$k] += (float)($r[$k] ?? 0);
    echo json_encode(['success' => true, 'rows' => $rows, 'total' => array_map(fn($v) => round($v, 2), $tot), 'as_at' => $d['as_at']]);
    exit;
}
?>
<?php include 'header.php'; ?>

<!-- Dashboard Content -->
<div class="page-header">
    <h2 class="page-title">Dashboard</h2>
    <p class="page-subtitle">Welcome to Yelo Group Management System</p>
</div>

<!-- Unilever Receivable Summary -->
<div class="ur" id="urBox" style="display:none">
  <div class="ur-head">
    <div class="ur-title"><span class="ur-ic"><i class="fa-solid fa-scale-balanced"></i></span>
      <div><h3>Unilever Receivable Summary</h3><small id="urAsAt">live from Customer Ledger &amp; Vendor Ledger</small></div></div>
    <button type="button" class="ur-btn" id="urReload" title="Refresh"><i class="fa-solid fa-rotate"></i> Refresh</button>
  </div>
  <div class="ur-kpis" id="urKpis"></div>
  <div class="ur-scroll">
    <table class="ur-t">
      <thead><tr><th>Source</th><th>Description</th><th class="r">Receivable</th><th class="r">Received</th><th class="r">Paid</th><th class="r">Balance</th><th class="ur-rcv">Recovered</th></tr></thead>
      <tbody id="urBody"></tbody>
      <tfoot id="urFoot"></tfoot>
    </table>
  </div>
  <div class="ur-note"><span class="dot pos"></span> Balance + = Unilever still owes us &nbsp; <span class="dot neg"></span> Balance ( ) = received more than claimed &nbsp;·&nbsp; Balance = Receivable (or Paid) − Received. Click a line to open its page.</div>
</div>
<style>
.ur{background:#fff;border:1px solid #e5e7eb;border-radius:16px;margin-bottom:22px;overflow:hidden;box-shadow:0 8px 28px rgba(15,23,42,.07)}
.ur-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;background:linear-gradient(120deg,#0f172a,#1e3a8a 60%,#0e7490);color:#fff;flex-wrap:wrap}
.ur-title{display:flex;align-items:center;gap:12px}.ur-title h3{margin:0;font-size:18px;font-weight:800;letter-spacing:.2px}.ur-title small{opacity:.75;font-size:12px}
.ur-ic{width:42px;height:42px;border-radius:12px;background:rgba(255,255,255,.14);display:flex;align-items:center;justify-content:center;font-size:19px}
.ur-btn{border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.12);color:#fff;border-radius:9px;padding:7px 14px;font:600 12.5px inherit;cursor:pointer}.ur-btn:hover{background:rgba(255,255,255,.22)}
.ur-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;padding:16px 20px 6px}
.ur-k{border-radius:12px;padding:12px 14px;border:1px solid var(--c-b);background:var(--c-bg)}.ur-k span{display:block;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--c)}
.ur-k b{display:block;font-size:21px;margin-top:3px;color:#0f172a;font-variant-numeric:tabular-nums}
.ur-scroll{overflow-x:auto;padding:8px 20px 4px}.ur-t{width:100%;border-collapse:separate;border-spacing:0 6px;font-size:13.5px;min-width:760px}
.ur-t th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;text-align:left;padding:4px 12px;font-weight:700}.ur-t .r{text-align:right}
.ur-t tbody tr{background:#f8fafc;cursor:pointer;transition:transform .12s,box-shadow .12s}.ur-t tbody tr:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(15,23,42,.1);background:#fff}
.ur-t td{padding:11px 12px;border-top:1px solid #eef2f7;border-bottom:1px solid #eef2f7;font-variant-numeric:tabular-nums}.ur-t td:first-child{border-left:1px solid #eef2f7;border-radius:10px 0 0 10px}.ur-t td:last-child{border-right:1px solid #eef2f7;border-radius:0 10px 10px 0}
.ur-src{display:inline-block;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}.ur-src.cl{background:#dbeafe;color:#1d4ed8}.ur-src.cc{background:#ede9fe;color:#6d28d9}
.ur-desc{font-weight:700;color:#0f172a;display:flex;align-items:center;gap:9px}.ur-desc i{width:28px;height:28px;border-radius:8px;background:#e0f2fe;color:#0369a1;display:flex;align-items:center;justify-content:center;font-size:13px}
.ur-bal{font-weight:800;border-radius:8px;padding:3px 10px;display:inline-block;min-width:96px;text-align:right}.ur-bal.pos{background:#fee2e2;color:#b91c1c}.ur-bal.neg{background:#dcfce7;color:#15803d}.ur-bal.zero{background:#f1f5f9;color:#64748b}
.ur-rcv{width:150px}.ur-bar{height:9px;background:#e2e8f0;border-radius:99px;overflow:hidden}.ur-bar i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#22c55e,#16a34a)}.ur-bar i.warn{background:linear-gradient(90deg,#f59e0b,#d97706)}.ur-bar i.low{background:linear-gradient(90deg,#f87171,#dc2626)}
.ur-pc{font-size:11px;color:#64748b;margin-top:2px}.ur-dash{color:#cbd5e1}
.ur-t tfoot td{padding:13px 12px;font-weight:800;font-size:14.5px;background:#0f172a;color:#fff}.ur-t tfoot td:first-child{border-radius:10px 0 0 10px}.ur-t tfoot td:last-child{border-radius:0 10px 10px 0}
.ur-note{padding:8px 20px 16px;color:#64748b;font-size:12px}.ur-note .dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:4px}.dot.pos{background:#ef4444}.dot.neg{background:#22c55e}
body.ms-dark .ur,body.ms-dark .ur-t tbody tr{background:#1f2937;border-color:#374151}body.ms-dark .ur-t tbody tr:hover{background:#273244}body.ms-dark .ur-t td{border-color:#374151}body.ms-dark .ur-desc,body.ms-dark .ur-k b{color:#f3f4f6}body.ms-dark .ur-k{background:#111827}
@media(max-width:700px){.ur-head,.ur-kpis,.ur-scroll,.ur-note{padding-left:12px;padding-right:12px}}
</style>
<script>
(function(){
  var box=document.getElementById('urBox'); if(!box) return;
  var fmt=function(n){ return Number(n).toLocaleString('en-US',{maximumFractionDigits:0}); };
  var neg=function(n){ return n<0?'('+fmt(Math.abs(n))+')':fmt(n); };
  function load(){
    fetch('index.php?ajax=ur_summary',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      if(!d.success||!d.rows||!d.rows.length){ box.style.display='none'; return; }
      box.style.display='block'; document.getElementById('urAsAt').textContent='as at '+d.as_at+' · live from Customer Ledger & Vendor Ledger';
      var t=d.total, K=[['Receivable',t.receivable,'#2563eb','#eff6ff','#bfdbfe'],['Received',t.received,'#16a34a','#f0fdf4','#bbf7d0'],['Paid out',t.paid,'#d97706','#fffbeb','#fde68a'],['Net balance',t.balance,t.balance>0?'#dc2626':'#16a34a',t.balance>0?'#fef2f2':'#f0fdf4',t.balance>0?'#fecaca':'#bbf7d0']];
      document.getElementById('urKpis').innerHTML=K.map(function(k){ return '<div class="ur-k" style="--c:'+k[2]+';--c-bg:'+k[3]+';--c-b:'+k[4]+'"><span>'+k[0]+'</span><b>Rs. '+neg(k[1])+'</b></div>'; }).join('');
      document.getElementById('urBody').innerHTML=d.rows.map(function(r){
        var base=r.paid!=null?r.paid:(r.receivable||0), pc=base>0?Math.max(0,Math.min(100,Math.round((r.received||0)/base*100))):0, cls=pc>=95?'':(pc>=60?'warn':'low'), bc=r.balance>0.5?'pos':(r.balance<-0.5?'neg':'zero');
        return '<tr data-p="'+r.page+'.php"><td><span class="ur-src '+(r.source==='Customer Ledger'?'cl':'cc')+'">'+r.source+'</span></td>'
          +'<td><div class="ur-desc"><i class="fa-solid '+r.icon+'"></i>'+r.label.replace(/&/g,'&amp;')+'</div></td>'
          +'<td class="r">'+(r.receivable!=null?fmt(r.receivable):'<span class="ur-dash">—</span>')+'</td><td class="r">'+(r.received?fmt(r.received):'<span class="ur-dash">—</span>')+'</td>'
          +'<td class="r">'+(r.paid!=null?fmt(r.paid):'<span class="ur-dash">—</span>')+'</td><td class="r"><span class="ur-bal '+bc+'">'+neg(r.balance)+'</span></td>'
          +'<td class="ur-rcv"><div class="ur-bar"><i class="'+cls+'" style="width:'+pc+'%"></i></div><div class="ur-pc">'+pc+'% recovered</div></td></tr>'; }).join('');
      document.getElementById('urFoot').innerHTML='<tr><td colspan="2">Total</td><td class="r">'+fmt(t.receivable)+'</td><td class="r">'+fmt(t.received)+'</td><td class="r">'+fmt(t.paid)+'</td><td class="r">'+neg(t.balance)+'</td><td></td></tr>';
    }).catch(function(){ box.style.display='none'; });
  }
  document.getElementById('urBody').addEventListener('click',function(e){ var tr=e.target.closest('tr[data-p]'); if(tr) location.href=tr.dataset.p; });
  document.getElementById('urReload').addEventListener('click',load); load();
})();
</script>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Total Groups</span>
            <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
        </div>
        <div class="stat-value">24</div>
        <div class="stat-change">↑ 12% from last month</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Active Members</span>
            <div class="stat-icon"><i class="fa-solid fa-user-check"></i></div>
        </div>
        <div class="stat-value">1,482</div>
        <div class="stat-change">↑ 8% from last month</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Upcoming Events</span>
            <div class="stat-icon"><i class="fa-solid fa-calendar-days"></i></div>
        </div>
        <div class="stat-value">18</div>
        <div class="stat-change">↑ 5% from last month</div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Revenue</span>
            <div class="stat-icon"><i class="fa-solid fa-dollar-sign"></i></div>
        </div>
        <div class="stat-value">$45.2K</div>
        <div class="stat-change">↑ 15% from last month</div>
    </div>
</div>

<!-- Content Cards -->
<div class="content-card">
    <h3 class="card-title">Recent Activity</h3>
    <p style="color: #666; font-size: 13px;">Your group management activities will appear here.</p>
</div>

<div class="content-card">
    <h3 class="card-title">Quick Actions</h3>
    <p style="color: #666; font-size: 13px;">Create new groups, add members, or schedule events.</p>
</div>

<?php include 'footer.php'; ?>
