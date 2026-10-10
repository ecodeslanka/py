<?php
/**
 * daily_sent_cheques.php — Daily Sent Cheques Report
 */

if (session_status() === PHP_SESSION_NONE) session_start();

/* ── AJAX: get report items ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'report_items') {
    include 'config.php';
    header('Content-Type: application/json');
    $rid = intval($_GET['report_id'] ?? 0);
    if (!$rid) { echo json_encode(['success'=>false,'items'=>[]]); exit; }
    $rows = [];
    $r = mysqli_query($conn, "SELECT * FROM daily_cheque_report_items WHERE report_id=$rid ORDER BY cheque_no ASC");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    echo json_encode(['success'=>true,'items'=>$rows]);
    exit;
}

/* ── AJAX: delete report ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_report') {
    include 'config.php';
    header('Content-Type: application/json');
    $rid = intval($_POST['report_id'] ?? 0);
    if (!$rid) { echo json_encode(['success'=>false,'error'=>'Invalid ID']); exit; }
    mysqli_query($conn, "DELETE FROM daily_cheque_report_items WHERE report_id=$rid");
    mysqli_query($conn, "DELETE FROM daily_cheque_reports WHERE id=$rid");
    echo json_encode(['success'=>true]);
    exit;
}

include 'config.php';
include 'header.php';

/* ── Ensure tables exist ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS daily_cheque_reports (
    id INT AUTO_INCREMENT PRIMARY KEY, report_date DATE NOT NULL, remark TEXT,
    total_cheques INT DEFAULT 0, total_amount DECIMAL(15,2) DEFAULT 0, filter_snapshot TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(100) DEFAULT 'system',
    INDEX idx_date (report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS daily_cheque_report_items (
    id INT AUTO_INCREMENT PRIMARY KEY, report_id INT NOT NULL, cheque_id INT NOT NULL,
    cheque_no VARCHAR(100), cheque_date DATE, t_code VARCHAR(50), customer_name VARCHAR(255),
    bank_code VARCHAR(50), bank_name VARCHAR(255), branch_code VARCHAR(50), branch_name VARCHAR(255),
    total_amount DECIMAL(15,2) DEFAULT 0, status VARCHAR(50), sr_code VARCHAR(50),
    delivery_date DATE, cheque_mode VARCHAR(50),
    INDEX idx_rid (report_id), INDEX idx_cid (cheque_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Filters ── */
$f_from = trim($_GET['date_from'] ?? '');
$f_to   = trim($_GET['date_to'] ?? '');
$f_search = trim($_GET['q'] ?? '');

$where = ["1=1"];
if ($f_from)   $where[] = "r.report_date >= '".mysqli_real_escape_string($conn,$f_from)."'";
if ($f_to)     $where[] = "r.report_date <= '".mysqli_real_escape_string($conn,$f_to)."'";
if ($f_search) {
    $s = '%'.mysqli_real_escape_string($conn,$f_search).'%';
    $where[] = "(r.remark LIKE '$s' OR r.created_by LIKE '$s' OR CAST(r.id AS CHAR) LIKE '$s')";
}
$where_sql = implode(' AND ', $where);

$reports = [];
$res = mysqli_query($conn, "SELECT r.* FROM daily_cheque_reports r WHERE $where_sql ORDER BY r.report_date DESC, r.created_at DESC");
if ($res) while ($row = mysqli_fetch_assoc($res)) $reports[] = $row;

/* ── Stats ── */
$stat_res = mysqli_query($conn, "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_cheques),0) AS tot_chq, COALESCE(SUM(total_amount),0) AS tot_amt FROM daily_cheque_reports");
$stats = $stat_res ? mysqli_fetch_assoc($stat_res) : ['cnt'=>0,'tot_chq'=>0,'tot_amt'=>0];
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box}
.page-title{font-size:22px;font-weight:800;color:#1e1b4b;margin:0 0 4px}
.page-subtitle{font-size:13px;color:#6b7280;margin:0}
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s;white-space:nowrap}
.btn-primary{background:#6366f1;color:#fff}.btn-primary:hover{background:#4f46e5}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover{background:#e8e8e8}
.btn-sm{padding:5px 12px;font-size:11px}
.btn-back{background:linear-gradient(135deg,#6366f1,#818cf8);color:#fff;border:none;border-radius:7px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:inherit;transition:all .2s;text-decoration:none;white-space:nowrap;}
.btn-back:hover{filter:brightness(1.1);color:#fff}
.stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.stat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px}
.stat-value{font-size:22px;font-weight:800;line-height:1}
.sv-violet{color:#7c3aed}.sv-blue{color:#2563eb}.sv-green{color:#16a34a}
.stat-sub{font-size:10px;color:#9ca3af;margin-top:4px}

/* Filter card */
.filter-bar{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:end;gap:12px;flex-wrap:wrap;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.ffg{display:flex;flex-direction:column;gap:5px}
.ffg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em}
.ffg input{border:1px solid #e5e5e5;border-radius:7px;padding:8px 11px;font-size:13px;font-family:inherit;color:#1f2937;transition:border .2s}
.ffg input:focus{outline:none;border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.1)}

/* Table */
.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #f0f0f0;background:#fafafa;flex-wrap:wrap;gap:8px}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:7px}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap}
.p-violet{background:#ede9fe;color:#5b21b6}
.dt-outer{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;min-width:900px}
.data-table thead th{padding:10px 10px;text-align:left;font-weight:700;font-size:10.5px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08)}
.data-table thead th:last-child{border-right:none}
.data-table thead th.tr{text-align:right}
.data-table thead th.tc{text-align:center}
.data-table tbody tr{border-bottom:1px solid #f0f2f5;transition:background .12s}
.data-table tbody tr:hover td{background:#f5f3ff!important}
.data-table td{padding:9px 10px;color:#374151;vertical-align:middle;background:#fff}
.tr{text-align:right}.tc{text-align:center}
.mono{font-family:'Courier New',monospace;font-weight:700;letter-spacing:.02em}
.date-txt{font-size:12px;color:#374151;white-space:nowrap}
.date-txt.empty{color:#d1d5db}
.remark-txt{max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:#6b7280}
.user-badge{display:inline-flex;align-items:center;gap:3px;background:#ede9fe;color:#5b21b6;border:1px solid #ddd6fe;border-radius:20px;padding:2px 9px;font-size:10px;font-weight:600}
.expand-btn{background:none;border:1.5px solid #d1d5db;border-radius:6px;width:28px;height:28px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#6b7280;transition:all .2s;flex-shrink:0}
.expand-btn:hover{background:#f5f3ff;border-color:#7c3aed;color:#7c3aed}
.expand-btn.open{background:#7c3aed;border-color:#7c3aed;color:#fff;transform:rotate(180deg)}
.delete-btn{display:inline-flex;align-items:center;gap:4px;background:#fee2e2;color:#dc2626;border:1.5px solid #fca5a5;border-radius:6px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .2s;white-space:nowrap}
.delete-btn:hover{background:#dc2626;color:#fff;border-color:#dc2626}
.view-link{display:inline-flex;align-items:center;gap:4px;background:#ede9fe;color:#5b21b6;border:1.5px solid #ddd6fe;border-radius:6px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .2s;white-space:nowrap;text-decoration:none;}
.view-link:hover{background:#7c3aed;color:#fff;border-color:#7c3aed}
tr.sub-row{display:none}
tr.sub-row.visible{display:table-row}
tr.sub-row td{padding:0;background:#f8f7ff}
.sub-loading{padding:16px 48px;color:#7c3aed;font-size:12px;display:flex;align-items:center;gap:8px}
.sub-items-wrap{padding:14px 20px 14px 48px;border-bottom:2px solid #ddd6fe;background:linear-gradient(135deg,#faf5ff,#f5f3ff)}
.sub-items-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#7c3aed;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.sub-items-count{background:#ede9fe;color:#5b21b6;padding:1px 8px;border-radius:12px;font-size:10px;font-weight:700}
.sub-tbl{width:100%;border-collapse:collapse;font-size:11.5px}
.sub-tbl thead th{padding:7px 8px;text-align:left;font-weight:700;font-size:10px;color:#7c3aed;background:#ede9fe;white-space:nowrap;border-bottom:1px solid #ddd6fe}
.sub-tbl thead th.tr{text-align:right}
.sub-tbl thead th.tc{text-align:center}
.sub-tbl tbody td{padding:6px 8px;color:#374151;border-bottom:1px solid #f3f0ff;background:transparent}
.sub-tbl tbody tr:hover td{background:#ede9fe}
.sub-tbl tfoot td{padding:8px;font-weight:800;font-size:12px;background:#ede9fe;color:#4c1d95;border-top:2px solid #ddd6fe}
.sub-tbl tfoot td.tr{text-align:right}
.sr-pill{background:#ede9fe;color:#5b21b6;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;white-space:nowrap}
.status-pill{display:inline-block;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;white-space:nowrap}
.sp-pending{background:#fef3c7;color:#92400e}.sp-to_be_bank{background:#e0f2fe;color:#0369a1}
.sp-deposited{background:#dbeafe;color:#1e40af}.sp-sent_back{background:#fdf4ff;color:#7e22ce}
.sp-cleared{background:#dcfce7;color:#166534}.sp-returned{background:#fee2e2;color:#991b1b}
.empty-state{text-align:center;padding:60px 20px;color:#9ca3af}
.empty-state i{font-size:48px;display:block;margin-bottom:14px;opacity:.3}
.empty-state p{font-size:14px;font-weight:500}

/* Toast */
#toast2{position:fixed;bottom:28px;right:28px;z-index:99999;padding:12px 22px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 6px 24px rgba(0,0,0,.2);color:#fff;transform:translateY(80px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none}
#toast2.show{transform:translateY(0);opacity:1}

@keyframes vmodalIn{from{transform:translateY(-40px) scale(.97);opacity:0}to{transform:translateY(0) scale(1);opacity:1}}
@media(max-width:900px){.stat-grid{grid-template-columns:1fr 1fr}.filter-bar{flex-direction:column}}
@media(max-width:640px){.stat-grid{grid-template-columns:1fr}}
@media print{.no-print{display:none!important}.data-table thead th{background:#1e1b4b!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>

<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;" class="no-print">
  <div>
    <h2 class="page-title"><i class="fa-solid fa-clipboard-list" style="color:#7c3aed;"></i> Daily Sent Cheques Report</h2>
    <p class="page-subtitle">View saved daily cheque report snapshots — expand to see cheque details.</p>
  </div>
  <div style="display:flex;gap:8px;" class="no-print">
    <a href="cheques.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Cheque Register</a>
    <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</button>
  </div>
</div>

<!-- STAT CARDS -->
<div class="stat-grid">
  <div class="stat-card"><div class="stat-label"><i class="fa-solid fa-book-open" style="color:#7c3aed;"></i> Total Reports</div><div class="stat-value sv-violet"><?=intval($stats['cnt'])?></div></div>
  <div class="stat-card"><div class="stat-label"><i class="fa-solid fa-money-check" style="color:#2563eb;"></i> Total Cheques Saved</div><div class="stat-value sv-blue"><?=number_format(intval($stats['tot_chq']))?></div></div>
  <div class="stat-card"><div class="stat-label"><i class="fa-solid fa-coins" style="color:#16a34a;"></i> Total Amount</div><div class="stat-value sv-green">Rs.&nbsp;<?=number_format(floatval($stats['tot_amt']),0)?></div></div>
</div>

<!-- FILTER BAR -->
<div class="filter-bar no-print">
  <div class="ffg"><label><i class="fa-solid fa-calendar-day"></i> Date From</label><input type="date" id="fDateFrom" value="<?=htmlspecialchars($f_from)?>"></div>
  <div class="ffg"><label><i class="fa-solid fa-calendar-day"></i> Date To</label><input type="date" id="fDateTo" value="<?=htmlspecialchars($f_to)?>"></div>
  <div class="ffg"><label><i class="fa-solid fa-magnifying-glass"></i> Search</label><input type="text" id="fSearch" value="<?=htmlspecialchars($f_search)?>" placeholder="Remark, user, ID…" style="width:200px;"></div>
  <button class="btn btn-primary btn-sm" onclick="applyFilter()" style="align-self:flex-end;"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
  <button class="btn btn-secondary btn-sm" onclick="clearFilter()" style="align-self:flex-end;" title="Clear"><i class="fa-solid fa-rotate-left"></i></button>
</div>

<!-- TABLE -->
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title"><i class="fa-solid fa-list"></i> Saved Reports <span class="pill p-violet"><?=count($reports)?> reports</span></div>
  </div>
  <div class="dt-outer">
  <table class="data-table" id="reportTable">
    <thead><tr>
      <th style="width:40px" class="tc no-print"></th>
      <th style="width:50px">#</th>
      <th class="tc">Report ID</th>
      <th class="tc">Sent Date</th>
      <th class="tc">Cheques</th>
      <th class="tr">Total Amount</th>
      <th>Remark</th>
      <th class="tc">Created</th>
      <th class="tc">By</th>
      <th class="tc no-print" style="min-width:140px;">Actions</th>
    </tr></thead>
    <tbody>
    <?php if(!count($reports)): ?>
      <tr><td colspan="10"><div class="empty-state"><i class="fa-solid fa-clipboard-list"></i><p>No daily reports found.</p></div></td></tr>
    <?php else: foreach($reports as $i=>$rp): ?>
      <tr id="rrow-<?=$rp['id']?>">
        <td class="tc no-print">
          <button class="expand-btn" id="expbtn-<?=$rp['id']?>" onclick="toggleReportItems(<?=$rp['id']?>)" title="Show cheques"><i class="fa-solid fa-chevron-down"></i></button>
        </td>
        <td style="color:#9ca3af;font-size:11px;font-weight:600;"><?=$i+1?></td>
        <td class="tc"><span class="mono" style="color:#7c3aed;font-size:13px;">#<?=$rp['id']?></span></td>
        <td class="tc"><?php $d=new DateTime($rp['report_date']);echo '<span class="date-txt">'.$d->format('d M Y').'</span>';?></td>
        <td class="tc"><span style="font-weight:800;color:#1f2937;font-size:14px;"><?=intval($rp['total_cheques'])?></span></td>
        <td class="tr"><span class="mono" style="color:#1f2937;font-size:13px;">Rs.&nbsp;<?=number_format(floatval($rp['total_amount']),2)?></span></td>
        <td><span class="remark-txt" title="<?=htmlspecialchars($rp['remark']??'')?>"><?=htmlspecialchars($rp['remark']??'—')?></span></td>
        <td class="tc"><?php if($rp['created_at']){$ct=new DateTime($rp['created_at']);echo '<span class="date-txt">'.$ct->format('d M Y H:i').'</span>';}else echo '—';?></td>
        <td class="tc"><span class="user-badge"><i class="fa-solid fa-user" style="font-size:9px;"></i> <?=htmlspecialchars($rp['created_by']??'system')?></span></td>
        <td class="tc no-print">
          <div style="display:flex;align-items:center;justify-content:center;gap:6px;">
            <a href="daily_sent_cheques_view.php?id=<?=$rp['id']?>" class="view-link" title="Full view"><i class="fa-solid fa-eye"></i> View</a>
            <button class="delete-btn" onclick="deleteReport(<?=$rp['id']?>)" title="Delete report"><i class="fa-solid fa-trash-can"></i></button>
          </div>
        </td>
      </tr>
      <tr class="sub-row" id="sub-<?=$rp['id']?>"><td colspan="10">
        <div class="sub-loading" id="subldr-<?=$rp['id']?>" style="display:none;"><i class="fa-solid fa-spinner fa-spin"></i> Loading cheques…</div>
        <div class="sub-items-wrap" id="subcnt-<?=$rp['id']?>" style="display:none;"></div>
      </td></tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div id="toast2"></div>

<script>
function applyFilter(){
    const from=document.getElementById('fDateFrom').value;
    const to=document.getElementById('fDateTo').value;
    const q=document.getElementById('fSearch').value.trim();
    const params=new URLSearchParams();
    if(from)params.set('date_from',from);
    if(to)params.set('date_to',to);
    if(q)params.set('q',q);
    window.location.href='daily_sent_cheques.php?'+params.toString();
}
function clearFilter(){window.location.href='daily_sent_cheques.php';}

const loadedReports=new Set();
async function toggleReportItems(id){
    const sub=document.getElementById('sub-'+id),ldr=document.getElementById('subldr-'+id),cnt=document.getElementById('subcnt-'+id),btn=document.getElementById('expbtn-'+id);
    if(!sub)return;
    if(sub.classList.contains('visible')){sub.classList.remove('visible');btn.classList.remove('open');return;}
    sub.classList.add('visible');btn.classList.add('open');
    if(loadedReports.has(id)){ldr.style.display='none';cnt.style.display='block';return;}
    ldr.style.display='flex';cnt.style.display='none';
    try{
        const res=await fetch('daily_sent_cheques.php?ajax=report_items&report_id='+id);
        const data=await res.json();
        if(!data.success){ldr.innerHTML='Error loading items';return;}
        renderItems(id,data.items);loadedReports.add(id);
    }catch(e){ldr.innerHTML='Network error';}
}

function fmtDate(d){if(!d||d==='0000-00-00')return '<span class="date-txt empty">—</span>';const dt=new Date(d);const m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];return `<span class="date-txt">${String(dt.getDate()).padStart(2,'0')} ${m[dt.getMonth()]} ${dt.getFullYear()}</span>`;}
function esc(s){if(s===null||s===undefined)return '';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function statusPill(s){const st=(s||'pending').toLowerCase();const map={pending:'sp-pending',to_be_bank:'sp-to_be_bank',deposited:'sp-deposited',sent_back:'sp-sent_back',cleared:'sp-cleared',returned:'sp-returned'};const labels={pending:'Pending',to_be_bank:'To Be Bank',deposited:'Deposited',sent_back:'Sent Back',cleared:'Cleared',returned:'Returned'};return `<span class="status-pill ${map[st]||''}">${esc(labels[st]||s)}</span>`;}

function renderItems(id,items){
    const ldr=document.getElementById('subldr-'+id),cnt=document.getElementById('subcnt-'+id);
    if(!items.length){cnt.innerHTML='<div style="padding:20px 48px;color:#9ca3af;font-size:12px;">No cheque items in this report.</div>';ldr.style.display='none';cnt.style.display='block';return;}
    let total=0;
    let h=`<div class="sub-items-title"><i class="fa-solid fa-money-check"></i> Cheques in Report <span class="sub-items-count">${items.length}</span></div>
    <table class="sub-tbl"><thead><tr>
      <th>#</th><th class="tc">SR</th><th class="tc">Delivery</th><th class="tc">Cheque Date</th><th>Cheque No</th>
      <th>Bank / Branch</th><th class="tc">Bank Code</th><th class="tr">Amount</th><th class="tc">Status</th>
      <th>T-Code</th><th>Customer</th>
    </tr></thead><tbody>`;
    items.forEach((item,idx)=>{
        const amt=parseFloat(item.total_amount||0);total+=amt;
        h+=`<tr>
          <td style="color:#9ca3af;font-size:10px;">${idx+1}</td>
          <td class="tc"><span class="sr-pill">${esc(item.sr_code||'—')}</span></td>
          <td class="tc">${fmtDate(item.delivery_date)}</td>
          <td class="tc">${fmtDate(item.cheque_date)}</td>
          <td><span class="mono" style="color:#4338ca;font-size:11.5px;">${esc(item.cheque_no)}</span></td>
          <td><span style="font-size:11px;">${esc(item.bank_name||'—')}</span>${item.branch_name?`<br><span style="font-size:10px;color:#9ca3af;">${esc(item.branch_name)}</span>`:''}</td>
          <td class="tc"><span class="mono" style="font-size:10px;">${esc(item.bank_code||'—')}</span></td>
          <td class="tr"><span class="mono" style="font-size:12px;">Rs.&nbsp;${amt.toLocaleString('en-US',{minimumFractionDigits:2})}</span></td>
          <td class="tc">${statusPill(item.status)}</td>
          <td><span class="mono" style="font-size:10.5px;color:#4338ca;">${esc(item.t_code||'—')}</span></td>
          <td style="font-size:11px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(item.customer_name)}">${esc(item.customer_name||'—')}</td>
        </tr>`;
    });
    h+=`</tbody><tfoot><tr><td colspan="7">TOTAL — ${items.length} cheques</td><td class="tr">Rs.&nbsp;${total.toLocaleString('en-US',{minimumFractionDigits:2})}</td><td colspan="3"></td></tr></tfoot></table>`;
    cnt.innerHTML=h;ldr.style.display='none';cnt.style.display='block';
}

async function deleteReport(id){
    if(!confirm('Delete this daily report and all its items? This cannot be undone.'))return;
    const fd=new FormData();fd.append('ajax_action','delete_report');fd.append('report_id',id);
    try{const res=await fetch('daily_sent_cheques.php',{method:'POST',body:fd});const data=await res.json();
        if(data.success){showToast2('Report deleted ✓','ok');const row=document.getElementById('rrow-'+id);const sub=document.getElementById('sub-'+id);if(row)row.remove();if(sub)sub.remove();}
        else showToast2(data.error||'Delete failed','err');
    }catch(e){showToast2('Network error','err');}
}

function showToast2(msg,type){const t=document.getElementById('toast2');t.style.background=type==='ok'?'#166534':'#dc2626';t.textContent=msg;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),3200);}
</script>

<?php include 'footer.php'; ?>
