<?php
/**
 * view_cash_reports.php
 * List and view saved cash collection report snapshots.
 */
include 'config.php';
include 'header.php';

/* ── ensure table exists ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cash_collection_saved_reports (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    report_name     VARCHAR(255)  NOT NULL,
    report_type     VARCHAR(50)   NOT NULL DEFAULT 'daily',
    date_from       DATE          NOT NULL,
    date_to         DATE          NOT NULL,
    sr_code         VARCHAR(50)   NULL,
    saved_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    saved_by        VARCHAR(100)  NULL,
    total_sinv      DECIMAL(14,2) DEFAULT 0.00,
    total_cc        DECIMAL(14,2) DEFAULT 0.00,
    total_sr        DECIMAL(14,2) DEFAULT 0.00,
    total_coll      DECIMAL(14,2) DEFAULT 0.00,
    total_banked    DECIMAL(14,2) DEFAULT 0.00,
    total_handed    DECIMAL(14,2) DEFAULT 0.00,
    total_short     DECIMAL(14,2) DEFAULT 0.00,
    row_count       INT           DEFAULT 0,
    report_json     LONGTEXT      NOT NULL,
    INDEX idx_dates (date_from, date_to),
    INDEX idx_saved (saved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$col = mysqli_query($conn, "SHOW COLUMNS FROM cash_collection_saved_reports LIKE 'report_type'");
if ($col && mysqli_num_rows($col) === 0) {
    mysqli_query($conn, "ALTER TABLE cash_collection_saved_reports ADD COLUMN report_type VARCHAR(50) NOT NULL DEFAULT 'daily' AFTER report_name");
}

/* ── AJAX: delete report ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if ($_POST['action'] === 'delete' && !empty($_POST['id'])) {
        $del_id = intval($_POST['id']);
        if (mysqli_query($conn, "DELETE FROM cash_collection_saved_reports WHERE id=$del_id")) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
        }
    } elseif ($_POST['action'] === 'rename' && !empty($_POST['id']) && !empty($_POST['name'])) {
        $ren_id   = intval($_POST['id']);
        $ren_name = mysqli_real_escape_string($conn, trim($_POST['name']));
        if (mysqli_query($conn, "UPDATE cash_collection_saved_reports SET report_name='$ren_name' WHERE id=$ren_id")) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit;
}

/* ── VIEW single report ── */
$view_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$view_row = null;
$report_data = null;
if ($view_id > 0) {
    $vr = mysqli_query($conn, "SELECT * FROM cash_collection_saved_reports WHERE id=$view_id");
    if ($vr && $row = mysqli_fetch_assoc($vr)) {
        $view_row    = $row;
        $report_data = json_decode($row['report_json'], true);
    }
}

/* ── LIST: filters ── */
$f_type  = trim($_GET['type']  ?? '');
$f_from  = trim($_GET['from']  ?? '');
$f_to    = trim($_GET['to']    ?? '');
$f_name  = trim($_GET['name']  ?? '');
$page    = max(1, intval($_GET['page'] ?? 1));
$per     = 20;
$offset  = ($page - 1) * $per;

$where = '1=1';
if ($f_type)  $where .= " AND report_type='" . mysqli_real_escape_string($conn,$f_type) . "'";
if ($f_from)  $where .= " AND date_from >= '" . mysqli_real_escape_string($conn,$f_from) . "'";
if ($f_to)    $where .= " AND date_to   <= '" . mysqli_real_escape_string($conn,$f_to)   . "'";
if ($f_name)  $where .= " AND report_name LIKE '%" . mysqli_real_escape_string($conn,$f_name) . "%'";

$total_count = 0;
$cr = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM cash_collection_saved_reports WHERE $where");
if ($cr) { $cx = mysqli_fetch_assoc($cr); $total_count = intval($cx['cnt']); }

$reports = [];
$lr = mysqli_query($conn, "SELECT id,report_name,report_type,date_from,date_to,sr_code,saved_at,saved_by,
    total_sinv,total_cc,total_sr,total_coll,total_banked,total_handed,total_short,row_count
    FROM cash_collection_saved_reports WHERE $where ORDER BY saved_at DESC LIMIT $per OFFSET $offset");
if ($lr) while ($r = mysqli_fetch_assoc($lr)) $reports[] = $r;

$total_pages = max(1, ceil($total_count / $per));

function fmt_num($v){ return number_format(floatval($v), 2); }
function fmt_date($d){ return $d ? date('d M Y', strtotime($d)) : '—'; }
function short_badge($v){
    $n = floatval($v);
    if (abs($n) < 0.005) return '<span class="badge b-ok">Balanced</span>';
    if ($n > 0)          return '<span class="badge b-sht">▼ Short '.number_format($n,2).'</span>';
    return '<span class="badge b-exc">▲ Excess '.number_format(abs($n),2).'</span>';
}
function type_badge($t){
    if($t === 'cc_summary') return '<span class="badge b-cc"><i class="fa-solid fa-user-tie"></i> CC Summary</span>';
    if($t === 'sr_summary') return '<span class="badge b-sr"><i class="fa-solid fa-person-walking"></i> SR Summary</span>';
    return '<span class="badge b-daily"><i class="fa-solid fa-calendar-day"></i> Daily</span>';
}
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');
:root{
  --bg:#eef1f6;--surface:#fff;--bdr:#d4d9e3;--bdrs:#e8ecf2;
  --tx:#111827;--txm:#4b5563;--txs:#9ca3af;
  --fn:'Inter',sans-serif;--mn:'JetBrains Mono',monospace;--r:10px;
  --sh:0 1px 3px rgba(0,0,0,.07),0 4px 14px rgba(0,0,0,.05);
  --acc:#5b21b6;--acc2:#7c3aed;
  --g0:#1e293b;--total:#0f172a;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:12px;}
.pg{padding:14px 14px 50px;}
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px;}
.pg-h1{font-size:20px;font-weight:800;letter-spacing:-.025em;}
.pg-h1 em{color:var(--acc2);font-style:normal;}
.pg-sub{font-size:10px;color:var(--txs);margin-top:2px;}

/* filter bar */
.fbar{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:9px 14px;margin-bottom:12px;display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;box-shadow:var(--sh);}
.fg{display:flex;flex-direction:column;gap:3px;}
.fg label{font-size:10px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.06em;}
.fg input,.fg select{padding:6px 10px;border:1.5px solid var(--bdr);border-radius:7px;font-size:12px;font-family:var(--fn);color:var(--tx);background:#fff;outline:none;}
.fg input:focus,.fg select:focus{border-color:var(--acc2);}
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 15px;border:none;border-radius:7px;font-size:12px;font-weight:700;font-family:var(--fn);cursor:pointer;text-decoration:none;transition:all .15s;}
.btn-primary{background:var(--acc);color:#fff;}.btn-primary:hover{background:var(--acc2);}
.btn-ghost{background:#f1f5f9;color:var(--txm);border:1px solid var(--bdr);}.btn-ghost:hover{background:#e2e8f0;}
.btn-danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}.btn-danger:hover{background:#dc2626;color:#fff;}
.btn-edit  {background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;}.btn-edit:hover{background:#1e40af;color:#fff;}
.btn-back  {background:#f5f3ff;color:#5b21b6;border:1px solid #ddd6fe;}.btn-back:hover{background:#5b21b6;color:#fff;}
.btn-print {background:#f0fdf4;color:#166534;border:1px solid #86efac;}.btn-print:hover{background:#166534;color:#fff;}

/* badges */
.badge{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;white-space:nowrap;}
.b-ok   {background:#dcfce7;color:#166534;}
.b-sht  {background:#fee2e2;color:#991b1b;}
.b-exc  {background:#dbeafe;color:#1e40af;}
.b-daily{background:#f0fdf4;color:#166534;}
.b-cc   {background:#ccfbf1;color:#0f766e;}
.b-sr   {background:#ecfdf5;color:#047857;}

/* ── LIST VIEW ── */
.stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px;}
.stat-card{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:10px 14px;box-shadow:var(--sh);border-left:3px solid #e5e7eb;}
.stat-card.purple{border-left-color:#a855f7;}.stat-card.blue{border-left-color:#3b82f6;}.stat-card.green{border-left-color:#22c55e;}.stat-card.teal{border-left-color:#14b8a6;}
.stat-lbl{font-size:9px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.stat-val{font-size:18px;font-weight:800;font-family:var(--mn);}

.tc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);overflow:hidden;box-shadow:var(--sh);}
.tc-bar{display:flex;justify-content:space-between;align-items:center;padding:9px 14px;border-bottom:1px solid var(--bdrs);flex-wrap:wrap;gap:6px;}
.tc-ttl{font-size:13px;font-weight:700;display:flex;align-items:center;gap:7px;}
.pill{padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;background:#f1f5f9;color:#475569;}

table.rpt-tbl{width:100%;border-collapse:collapse;font-size:11px;}
.rpt-tbl thead th{background:var(--g0);color:#e2e8f0;padding:8px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;text-align:left;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);}
.rpt-tbl thead th:last-child{border-right:none;}
.rpt-tbl tbody tr td{padding:9px 10px;border-bottom:1px solid var(--bdrs);vertical-align:middle;white-space:nowrap;}
.rpt-tbl tbody tr:last-child td{border-bottom:none;}
.rpt-tbl tbody tr:hover td{background:#f8f5ff;}
.rpt-tbl tbody tr.stripe td{background:#fafbfc;}
.rn{font-family:var(--mn);font-size:10.5px;}
.rpt-name{font-weight:700;color:#1e293b;font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;}
.rpt-name:hover{color:var(--acc2);}
.rpt-name i{font-size:9px;opacity:0;transition:opacity .15s;}
.rpt-name:hover i{opacity:1;}
.rpt-meta{font-size:10px;color:var(--txs);margin-top:2px;}
.actions{display:flex;gap:5px;align-items:center;}
.act-btn{border:none;border-radius:5px;padding:4px 9px;font-size:10px;font-weight:600;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:3px;transition:all .15s;white-space:nowrap;}
.act-view {background:#f5f3ff;color:#5b21b6;border:1px solid #ddd6fe;}.act-view:hover{background:#5b21b6;color:#fff;}
.act-edit {background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;}.act-edit:hover{background:#1e40af;color:#fff;}
.act-del  {background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}.act-del:hover{background:#dc2626;color:#fff;}
.empty{text-align:center;padding:50px 20px;color:var(--txs);}
.empty i{font-size:40px;display:block;margin-bottom:10px;opacity:.2;}

/* pagination */
.pager{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-top:1px solid var(--bdrs);font-size:11px;flex-wrap:wrap;gap:6px;}
.pager-info{color:var(--txs);}
.pager-btns{display:flex;gap:4px;}
.pager-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border:1px solid var(--bdr);border-radius:6px;background:#fff;color:var(--txm);font-size:11px;font-weight:600;text-decoration:none;transition:all .15s;}
.pager-btn:hover{background:var(--acc);color:#fff;border-color:var(--acc);}
.pager-btn.active{background:var(--acc);color:#fff;border-color:var(--acc);}
.pager-btn.disabled{opacity:.4;pointer-events:none;}

/* ── DETAIL VIEW ── */
.dv-header{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:14px 18px;margin-bottom:12px;box-shadow:var(--sh);}
.dv-title{font-size:18px;font-weight:800;color:var(--tx);margin-bottom:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.dv-meta-row{display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:11px;color:var(--txm);}
.dv-meta-item{display:flex;align-items:center;gap:4px;}
.dv-meta-item i{color:var(--txs);font-size:10px;}
.dv-cards{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;margin-bottom:12px;}
.dvc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:9px 12px;box-shadow:var(--sh);border-top:3px solid #e5e7eb;}
.dvc.teal{border-top-color:#14b8a6;}.dvc.green{border-top-color:#22c55e;}.dvc.blue{border-top-color:#3b82f6;}
.dvc.red{border-top-color:#ef4444;}.dvc.purple{border-top-color:#a855f7;}.dvc.indigo{border-top-color:#6366f1;}
.dvc-lbl{font-size:9px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.dvc-val{font-size:13px;font-weight:800;font-family:var(--mn);}

/* detail table — reuse cct pattern */
.tscroll{overflow-x:auto;}
table.cct{width:100%;border-collapse:collapse;font-size:11px;}
.cct .G th{padding:5px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#fff;text-align:center;white-space:nowrap;border-right:2px solid rgba(255,255,255,.18);}
.cct .G th.tl{text-align:left;}.cct .G th:last-child{border-right:none;}
.cct .S th{padding:4px 5px;font-size:9px;font-weight:700;text-transform:uppercase;color:rgba(255,255,255,.9);text-align:center;white-space:nowrap;border-right:1px solid rgba(255,255,255,.12);border-bottom:2px solid var(--bdr);}
.cct .S th.tl{text-align:left;}.cct .S th:last-child{border-right:none;}
.cct .TH td{background:#e0f2fe;color:#075985;font-weight:800;font-size:10.5px;padding:4px 5px;text-align:right;border-bottom:2px solid #7dd3fc;white-space:nowrap;font-family:var(--mn);}
.cct .TH td.tl{text-align:left;font-family:var(--fn);color:#0c4a6e;}
.cct tbody tr td{background:#fff;}
.cct tbody tr.stripe td{background:#fafbfc;}
.cct tbody tr:hover td{background:#f5f3ff!important;}
.cct tbody td{padding:4px 5px;text-align:right;white-space:nowrap;}
.cct tbody td.tl{text-align:left;}
.cct tfoot td{padding:4px 5px;font-weight:800;font-size:11px;background:var(--total);color:#e2e8f0;border-top:2px solid #334155;text-align:right;white-space:nowrap;font-family:var(--mn);}
.cct tfoot td.tl{text-align:left;color:#94a3b8;font-family:var(--fn);}
.cct td.stk,.cct th.stk{position:sticky;left:0;z-index:2;box-shadow:3px 0 6px rgba(0,0,0,.08);}
.cct thead th.stk{z-index:4;}

/* group header colours — daily report */
.G .h0  {background:#1e293b;}.G .h1  {background:#1e4d8c;}.G .h2  {background:#7a3f10;}
.G .hcc {background:#1a6640;}.G .hsr {background:#0f766e;}.G .htc {background:#374151;}
.G .hdep{background:#1e4d8c;}.G .hvar{background:#7c2d12;}.G .htr {background:#0c4a6e;}
.G .hnew{background:#312e81;}.G .hrec{background:#5b21b6;}
.S .s0  {background:#334155;}.S .s1  {background:#16408a;}.S .s2  {background:#6a3510;}
.S .scc {background:#155535;}.S .ssr {background:#0d6462;}.S .stc {background:#2d3748;}
.S .sdep{background:#14532d;}.S .svar{background:#7c2d12;}
.S .str {background:#075985;}.S .snew{background:#272069;}.S .srec{background:#4c1d95;}

/* group header colours — CC summary */
.G .hcs_cc  {background:#0f766e;}.G .hcs_dep {background:#065f46;}
.G .hcs_sht {background:#991b1b;}.G .hcs_exc {background:#1e40af;}
.G .hcs_var {background:#374151;}.G .hcs_tr  {background:#0c4a6e;}
.G .hcs_new {background:#312e81;}.G .hcs_rec {background:#5b21b6;}
.S .scs_cc  {background:#0d6462;}.S .scs_dep_bank{background:#054f38;}.S .scs_dep_bo{background:#064e3b;}
.S .scs_sht {background:#7f1d1d;}.S .scs_exc{background:#1e3a8a;}
.S .scs_var {background:#1f2937;}.S .scs_tr {background:#075985;}
.S .scs_new {background:#272069;}.S .scs_rec{background:#4c1d95;}.S .scs_rec2{background:#5b21b6;}.S .scs_rec3{background:#6d28d9;}

.num{font-family:var(--mn);font-size:10.5px;}
.dash{color:#d1d5db;}
.d-ok {color:#16a34a;font-weight:700;font-family:var(--mn);font-size:10px;}
.d-exc{color:#16a34a;font-weight:700;font-family:var(--mn);font-size:10px;}
.d-sht{color:#dc2626;font-weight:700;font-family:var(--mn);font-size:10px;}
.sv   {color:#dc2626;font-weight:700;font-family:var(--mn);}
.ev   {color:#1d4ed8;font-weight:700;font-family:var(--mn);}
.val-charge{color:#dc2626;font-weight:600;font-family:var(--mn);}
.val-absorb{color:#0369a1;font-weight:600;font-family:var(--mn);}
.pv-ok     {color:#16a34a;font-weight:700;font-family:var(--mn);}
.pv-exc-pay{color:#d97706;font-weight:700;font-family:var(--mn);}
.pv-sht-pay{color:#dc2626;font-weight:700;font-family:var(--mn);}
.adj-in {color:#059669;font-weight:700;font-family:var(--mn);}
.adj-out{color:#d97706;font-weight:700;font-family:var(--mn);}

/* rename inline */
.rename-input{border:1.5px solid var(--acc2);border-radius:6px;padding:3px 8px;font-size:12px;font-family:var(--fn);outline:none;width:260px;}
.rename-save{background:var(--acc);color:#fff;border:none;border-radius:5px;padding:4px 10px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;margin-left:5px;}
.rename-cancel{background:#f1f5f9;color:var(--txm);border:1px solid var(--bdr);border-radius:5px;padding:4px 9px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;margin-left:4px;}

#vrToast{position:fixed;bottom:20px;right:20px;padding:9px 16px;border-radius:8px;font-size:12px;font-weight:600;z-index:9999;display:none;opacity:0;transition:opacity .3s;}
.toast-ok{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.toast-err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}

.print-only{display:none;}
@media print{
  .no-print{display:none!important;}
  .print-only{display:block;}
  body{background:#fff;font-size:10px;}
  .pg{padding:4px 4px 10px;}
  .dv-header{box-shadow:none;border:1px solid #ccc;}
  .tc{box-shadow:none;border:1px solid #ccc;}
  .tscroll{overflow:visible;}
  table.cct{font-size:9px;}
  .cct .G th,.cct .S th{padding:3px;font-size:8px;}
  .cct tbody td,.cct .TH td,.cct tfoot td{padding:2px 3px;}
  th,tfoot td,.cct .G th,.cct .S th,.dvc{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .cct td.stk,.cct th.stk{position:static;box-shadow:none;}
  .dv-cards{grid-template-columns:repeat(3,1fr);gap:4px;}
}
@media(max-width:1100px){.dv-cards{grid-template-columns:repeat(3,1fr);}}
@media(max-width:700px){.dv-cards{grid-template-columns:1fr 1fr;}.stat-row{grid-template-columns:1fr 1fr;}}
</style>

<?php
/* ─────────────────────────────────────────
   HELPER RENDER FUNCTIONS (detail view)
   ───────────────────────────────────────── */
function rv($v){ $n=floatval($v); return $n==0?'<span class="dash">—</span>':'<span class="num">'.number_format($n,2).'</span>'; }
function rt($v){ $n=floatval($v); return $n==0?'—':number_format($n,2); }
function rse($v){
    $n=floatval($v);
    if(abs($n)<0.005) return '<span class="d-ok">0.00 ✓</span>';
    if($n<0)          return '<span class="d-exc">▲ '.number_format(abs($n),2).'</span>';
    return '<span class="d-sht">▼ '.number_format($n,2).'</span>';
}
function radj($v){
    $n=floatval($v);
    if(abs($n)<0.005) return '<span class="dash">—</span>';
    if($n>0)          return '<span class="adj-in">+'.number_format($n,2).'</span>';
    return '<span class="adj-out">'.number_format($n,2).'</span>';
}
function rpvar($v){
    if($v===null||$v==='') return '<span class="dash">—</span>';
    $v=floatval($v);
    if(abs($v)<0.005)   return '<span class="pv-ok">0.00</span>';
    if($v<0)            return '<span class="pv-exc-pay">'.number_format($v,2).'</span>';
    return '<span class="pv-sht-pay">+'.number_format($v,2).'</span>';
}
function rtr_combined($out,$in){
    $out=floatval($out);$in=floatval($in);
    if($out<=0&&$in<=0) return '<span class="dash">—</span>';
    $r='';
    if($out>0) $r.='<span style="color:#ef4444;font-family:var(--mn);font-size:10.5px;font-weight:700;">▼'.number_format($out,2).'</span>';
    if($out>0&&$in>0)  $r.='<br>';
    if($in >0) $r.='<span style="color:#22c55e;font-family:var(--mn);font-size:10.5px;font-weight:700;">▲'.number_format($in,2).'</span>';
    return $r;
}
function rshort($v){$n=floatval($v);return $n<=0?'<span class="dash">—</span>':'<span class="sv">'.number_format($n,2).'</span>';}
function rexcess($v){$n=floatval($v);return $n<=0?'<span class="dash">—</span>':'<span class="ev">'.number_format($n,2).'</span>';}
?>

<div class="pg">

<?php if ($view_id > 0 && $view_row && $report_data): /* ════ DETAIL VIEW ════ */
    $meta    = $report_data['meta']   ?? [];
    $totals  = $report_data['totals'] ?? [];
    $rows    = $report_data['rows']   ?? [];
    $rtype   = $view_row['report_type'] ?? 'daily';
    $is_cc   = ($rtype === 'cc_summary');
    $is_sr   = ($rtype === 'sr_summary');
?>
<div class="print-only" style="text-align:center;padding:6px 0 8px;border-bottom:2px solid #5b21b6;margin-bottom:10px;">
    <div style="font-size:16px;font-weight:800;"><?php echo htmlspecialchars($view_row['report_name']);?></div>
    <div style="font-size:11px;color:#4b5563;margin-top:3px;">
        <?php echo fmt_date($view_row['date_from']).' — '.fmt_date($view_row['date_to']);?>
        <?php echo $view_row['sr_code']?' · Rep: '.htmlspecialchars($view_row['sr_code']):'';?>
        <?php echo $view_row['saved_by']?' · Saved by: '.htmlspecialchars($view_row['saved_by']):'';?>
        · Printed: <?php echo date('d M Y H:i');?>
    </div>
</div>

<!-- Header -->
<div class="dv-header no-print">
    <div class="dv-title">
        <i class="fa-solid fa-file-chart-column" style="color:var(--acc2);font-size:16px;"></i>
        <?php echo htmlspecialchars($view_row['report_name']);?>
        <?php echo type_badge($rtype);?>
        <?php echo short_badge($view_row['total_short']);?>
    </div>
    <div class="dv-meta-row">
        <div class="dv-meta-item"><i class="fa-solid fa-calendar-range"></i> <?php echo fmt_date($view_row['date_from']).' — '.fmt_date($view_row['date_to']);?></div>
        <?php if($view_row['sr_code']):?><div class="dv-meta-item"><i class="fa-solid fa-id-badge"></i> Rep: <strong><?php echo htmlspecialchars($view_row['sr_code']);?></strong></div><?php endif;?>
        <div class="dv-meta-item"><i class="fa-solid fa-table-rows"></i> <?php echo intval($view_row['row_count']);?> row(s)</div>
        <div class="dv-meta-item"><i class="fa-solid fa-clock"></i> Saved <?php echo date('d M Y H:i', strtotime($view_row['saved_at']));?></div>
        <?php if($view_row['saved_by']):?><div class="dv-meta-item"><i class="fa-solid fa-user"></i> <?php echo htmlspecialchars($view_row['saved_by']);?></div><?php endif;?>
    </div>
</div>
<div class="no-print" style="display:flex;gap:7px;margin-bottom:12px;flex-wrap:wrap;">
    <a href="view_cash_reports.php" class="btn btn-back"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
    <button onclick="window.print()" class="btn btn-print"><i class="fa-solid fa-print"></i> Print</button>
    <?php if($rtype==='daily'):?>
    <a href="cash_collection.php?date_from=<?php echo $view_row['date_from'];?>&date_to=<?php echo $view_row['date_to'];?><?php echo $view_row['sr_code']?'&sr_code='.urlencode($view_row['sr_code']):'';?>&search=1" target="_blank" class="btn btn-ghost"><i class="fa-solid fa-arrow-up-right-from-square"></i> Open Live Report</a>
    <?php elseif($rtype==='sr_summary'):?>
    <a href="sr_collection_summary.php?date_from=<?php echo $view_row['date_from'];?>&date_to=<?php echo $view_row['date_to'];?><?php echo $view_row['sr_code']?'&sr_code='.urlencode($view_row['sr_code']):'';?>&search=1" target="_blank" class="btn btn-ghost"><i class="fa-solid fa-arrow-up-right-from-square"></i> Open Live Report</a>
    <?php else:?>
    <a href="cc_collection_summary.php?date_from=<?php echo $view_row['date_from'];?>&date_to=<?php echo $view_row['date_to'];?><?php echo $view_row['sr_code']?'&sr_code='.urlencode($view_row['sr_code']):'';?>&search=1" target="_blank" class="btn btn-ghost"><i class="fa-solid fa-arrow-up-right-from-square"></i> Open Live Report</a>
    <?php endif;?>
</div>

<!-- Summary cards -->
<div class="dv-cards">
<?php if(!$is_cc && !$is_sr): /* ── Daily ── */ ?>
    <div class="dvc teal"><div class="dvc-lbl"><i class="fa-solid fa-file-invoice-dollar"></i> Sec. Invoice</div><div class="dvc-val" style="color:#0f766e;">Rs. <?php echo fmt_num($totals['sinv']??0);?></div></div>
    <div class="dvc teal"><div class="dvc-lbl"><i class="fa-solid fa-user-tie"></i> CC Total</div><div class="dvc-val" style="color:#0f766e;">Rs. <?php echo fmt_num($totals['cc_total']??0);?></div></div>
    <div class="dvc green"><div class="dvc-lbl"><i class="fa-solid fa-person-walking"></i> SR Total</div><div class="dvc-val" style="color:#166534;">Rs. <?php echo fmt_num($totals['sr_total']??0);?></div></div>
    <div class="dvc blue"><div class="dvc-lbl"><i class="fa-solid fa-building-columns"></i> Total Coll.</div><div class="dvc-val" style="color:#1e40af;">Rs. <?php echo fmt_num($totals['total_coll']??0);?></div></div>
    <div class="dvc green"><div class="dvc-lbl"><i class="fa-solid fa-vault"></i> Deposited</div><div class="dvc-val" style="color:#166534;">Rs. <?php echo fmt_num(($totals['banked']??0)+($totals['handed']??0));?></div></div>
    <?php $nse=$totals['new_short_excess']??0; $sht=$nse>0;?>
    <div class="dvc <?php echo $sht?'red':'green';?>"><div class="dvc-lbl"><i class="fa-solid fa-triangle-exclamation"></i> Net Short/Excess</div><div class="dvc-val" style="color:<?php echo $sht?'#991b1b':'#166534';?>;">Rs. <?php echo fmt_num(abs($nse));?><?php echo $nse<0?' <small style="font-size:10px;">(Excess)</small>':($nse==0?' <small>(OK)</small>':'');?></div></div>
<?php elseif($is_cc): /* ── CC Summary ── */ ?>
    <div class="dvc teal"><div class="dvc-lbl"><i class="fa-solid fa-file-invoice-dollar"></i> Sec. Invoice</div><div class="dvc-val" style="color:#0f766e;">Rs. <?php echo fmt_num($totals['sinv']??0);?></div></div>
    <div class="dvc teal"><div class="dvc-lbl"><i class="fa-solid fa-user-tie"></i> CC Collected</div><div class="dvc-val" style="color:#0f766e;">Rs. <?php echo fmt_num($totals['cc_total']??0);?></div></div>
    <div class="dvc green"><div class="dvc-lbl"><i class="fa-solid fa-building-columns"></i> Banked (CC)</div><div class="dvc-val" style="color:#166534;">Rs. <?php echo fmt_num($totals['banked_cc']??0);?></div></div>
    <div class="dvc blue"><div class="dvc-lbl"><i class="fa-solid fa-building"></i> Handed BO (CC)</div><div class="dvc-val" style="color:#1e40af;">Rs. <?php echo fmt_num($totals['handed_cc']??0);?></div></div>
    <div class="dvc indigo"><div class="dvc-lbl"><i class="fa-solid fa-right-left"></i> Net Cross Charge</div><div class="dvc-val" style="color:#4338ca;">Rs. <?php echo fmt_num(abs($totals['shortage_adj']??0));?></div></div>
    <?php $nse=$totals['new_short_excess']??0; $sht=$nse>0;?>
    <div class="dvc <?php echo $sht?'red':'green';?>"><div class="dvc-lbl"><i class="fa-solid fa-triangle-exclamation"></i> Net Short/Excess</div><div class="dvc-val" style="color:<?php echo $sht?'#991b1b':'#166534';?>;">Rs. <?php echo fmt_num(abs($nse));?><?php echo $nse<0?' <small style="font-size:10px;">(Excess)</small>':($nse==0?' <small>(OK)</small>':'');?></div></div>
<?php else: /* ── SR Summary ── */ ?>
    <div class="dvc teal"><div class="dvc-lbl"><i class="fa-solid fa-file-invoice-dollar"></i> Sec. Invoice</div><div class="dvc-val" style="color:#0f766e;">Rs. <?php echo fmt_num($totals['sinv']??0);?></div></div>
    <div class="dvc teal"><div class="dvc-lbl"><i class="fa-solid fa-person-walking"></i> SR Collected</div><div class="dvc-val" style="color:#0f766e;">Rs. <?php echo fmt_num($totals['sr_total']??0);?></div></div>
    <div class="dvc green"><div class="dvc-lbl"><i class="fa-solid fa-building-columns"></i> Banked (SR)</div><div class="dvc-val" style="color:#166534;">Rs. <?php echo fmt_num($totals['banked_sr']??0);?></div></div>
    <div class="dvc blue"><div class="dvc-lbl"><i class="fa-solid fa-building"></i> Handed BO (SR)</div><div class="dvc-val" style="color:#1e40af;">Rs. <?php echo fmt_num($totals['handed_sr']??0);?></div></div>
    <div class="dvc indigo"><div class="dvc-lbl"><i class="fa-solid fa-right-left"></i> Net Cross Charge</div><div class="dvc-val" style="color:#4338ca;">Rs. <?php echo fmt_num(abs($totals['shortage_adj']??0));?></div></div>
    <?php $nse=floatval($totals['new_short_excess']??0); $sht=$nse>0.005;?>
    <div class="dvc <?php echo $sht?'red':'green';?>"><div class="dvc-lbl"><i class="fa-solid fa-triangle-exclamation"></i> Net Shortage</div><div class="dvc-val" style="color:<?php echo $sht?'#991b1b':'#166534';?>;">Rs. <?php echo fmt_num($nse);?><?php echo $nse==0?' <small>(OK)</small>':'';?></div></div>
<?php endif;?>
</div>

<!-- Detail table -->
<div class="tc">
  <div class="tc-bar no-print">
    <div style="font-size:13px;font-weight:700;display:flex;align-items:center;gap:7px;flex-wrap:wrap;">
      <i class="fa-solid fa-table-cells-large"></i>
      <?php echo $is_sr ? 'SR Collection Summary Detail' : ($is_cc ? 'CC Collection Summary Detail' : 'Daily Cash Collection Detail'); ?>
      <span class="pill"><?php echo count($rows);?> row(s)</span>
    </div>
  </div>
  <div class="tscroll">
<?php if(!$is_cc && !$is_sr): /* ════ DAILY TABLE ════ */ ?>
  <table class="cct">
    <thead>
      <tr class="G">
        <th class="h0 tl stk" rowspan="2" style="min-width:78px;">Rep</th>
        <th class="h0 tl"     rowspan="2" style="min-width:80px;">Del. Date</th>
        <th class="h1"        colspan="1">Sec. Invoice</th>
        <th class="h2"        colspan="4">Invoice Payments</th>
        <th class="hcc"       colspan="6">CC Collection</th>
        <th class="hsr"       colspan="6">SR Collection</th>
        <th class="htc"       colspan="1">Total</th>
        <th class="hdep"      colspan="4">Deposits</th>
        <th class="hvar"      colspan="4">Variance &amp; Shortage</th>
        <th class="htr"       colspan="1">Cross Charge</th>
        <th class="hnew"      colspan="1">Final Short/Excess</th>
        <th class="hrec"      colspan="3">Shortage Recovery</th>
      </tr>
      <tr class="S">
        <th class="s1"  style="min-width:105px;">Sec. Inv.</th>
        <th class="s2"  style="min-width:88px;">Cash Paid</th>
        <th class="s2"  style="min-width:88px;">Cheque</th>
        <th class="s2"  style="min-width:78px;">Credit</th>
        <th class="s2"  style="min-width:58px;">Diff</th>
        <th class="scc" style="min-width:88px;">Daily Sale</th>
        <th class="scc" style="min-width:88px;">Rcvd Crd.</th>
        <th class="scc" style="min-width:80px;">Rtn Chq</th>
        <th class="scc" style="min-width:78px;">Rtn Chgs</th>
        <th class="scc" style="min-width:78px;">Sent Back</th>
        <th class="scc" style="min-width:85px;">CC Total</th>
        <th class="ssr" style="min-width:88px;">Daily Sale</th>
        <th class="ssr" style="min-width:88px;">Rcvd Crd.</th>
        <th class="ssr" style="min-width:80px;">Rtn Chq</th>
        <th class="ssr" style="min-width:78px;">Rtn Chgs</th>
        <th class="ssr" style="min-width:78px;">Sent Back</th>
        <th class="ssr" style="min-width:85px;">SR Total</th>
        <th class="stc" style="min-width:92px;">Grand Total</th>
        <th class="sdep" style="min-width:88px;">Dep. CC</th>
        <th class="sdep" style="min-width:88px;">Dep. SR</th>
        <th class="sdep" style="min-width:88px;">BO CC</th>
        <th class="sdep" style="min-width:88px;">BO SR</th>
        <th class="svar" style="min-width:82px;">Var. CC</th>
        <th class="svar" style="min-width:82px;">Var. SR</th>
        <th class="svar" style="min-width:82px;">Short CC</th>
        <th class="svar" style="min-width:82px;">Short SR</th>
        <th class="str"  style="min-width:110px;">Cross Charge</th>
        <th class="snew" style="min-width:95px;">Final S/E</th>
        <th class="srec" style="min-width:90px;">Charge</th>
        <th class="srec" style="min-width:90px;">Absorb</th>
        <th class="srec" style="min-width:80px;">Pay Var.</th>
      </tr>
      <tr class="TH">
        <td class="tl stk" colspan="2"><i class="fa-solid fa-sigma"></i> Total</td>
        <td><?php echo rt($totals['sinv']??0);?></td>
        <td><?php echo rt($totals['cash_paid']??0);?></td>
        <td><?php echo rt($totals['cheque_paid']??0);?></td>
        <td><?php echo rt($totals['credit']??0);?></td>
        <td><?php echo rse($totals['pay_diff']??0);?></td>
        <td><?php echo rt($totals['cc_daily_sale']??0);?></td>
        <td><?php echo rt($totals['cc_rcvd_credit']??0);?></td>
        <td><?php echo rt($totals['cc_rcvd_rtn_chq']??0);?></td>
        <td><?php echo rt($totals['cc_rcvd_rtn_chgs']??0);?></td>
        <td><?php echo rt($totals['cc_rcvd_sent_back']??0);?></td>
        <td><?php echo rt($totals['cc_total']??0);?></td>
        <td><?php echo rt($totals['sr_daily_sale']??0);?></td>
        <td><?php echo rt($totals['sr_rcvd_credit']??0);?></td>
        <td><?php echo rt($totals['sr_rcvd_rtn_chq']??0);?></td>
        <td><?php echo rt($totals['sr_rcvd_rtn_chgs']??0);?></td>
        <td><?php echo rt($totals['sr_rcvd_sent_back']??0);?></td>
        <td><?php echo rt($totals['sr_total']??0);?></td>
        <td><?php echo rt($totals['total_coll']??0);?></td>
        <td><?php echo rt($totals['banked_cc']??0);?></td>
        <td><?php echo rt($totals['banked_sr']??0);?></td>
        <td><?php echo rt($totals['handed_cc']??0);?></td>
        <td><?php echo rt($totals['handed_sr']??0);?></td>
        <td><?php echo rse($totals['variance_cc']??0);?></td>
        <td><?php echo rse($totals['variance_sr']??0);?></td>
        <?php $sc=$totals['short_cc']??0;$ss=$totals['short_sr']??0;?>
        <td><?php echo $sc>0?'<span class="sv">'.number_format($sc,2).'</span>':'—';?></td>
        <td><?php echo $ss>0?'<span class="sv">'.number_format($ss,2).'</span>':'—';?></td>
        <td><?php echo rtr_combined($totals['t_out']??0,$totals['t_in']??0);?></td>
        <td><?php echo rse($totals['new_short_excess']??0);?></td>
        <td><?php echo rt($totals['p_charge']??0);?></td>
        <td><?php echo rt($totals['p_absorb']??0);?></td>
        <td>—</td>
      </tr>
    </thead>
    <tbody>
    <?php foreach($rows as $i=>$r):$stripe=($i%2!==0)?'stripe':'';?>
    <tr class="<?php echo $stripe;?>">
      <td class="tl stk" style="font-weight:700;"><?php echo htmlspecialchars($r['sr_code']??'');?></td>
      <td style="font-family:var(--mn);font-size:10px;color:var(--txm);"><?php echo isset($r['del_date'])?date('d M Y',strtotime($r['del_date'])):'—';?></td>
      <td style="font-weight:700;color:#1e40af;"><?php echo rv($r['sinv']??0);?></td>
      <td><?php echo rv($r['cash_paid']??0);?></td>
      <td><?php echo rv($r['cheque_paid']??0);?></td>
      <td><?php echo rv($r['credit']??0);?></td>
      <td><?php echo rse($r['pay_diff']??0);?></td>
      <td style="background:rgba(26,102,64,.05);"><?php echo rv($r['cc_daily_sale']??0);?></td>
      <td style="background:rgba(26,102,64,.05);"><?php echo rv($r['cc_rcvd_credit']??0);?></td>
      <td style="background:rgba(26,102,64,.05);"><?php echo rv($r['cc_rcvd_rtn_chq']??0);?></td>
      <td style="background:rgba(26,102,64,.05);"><?php echo rv($r['cc_rcvd_rtn_chgs']??0);?></td>
      <td style="background:rgba(26,102,64,.05);"><?php echo rv($r['cc_rcvd_sent_back']??0);?></td>
      <td style="background:rgba(26,102,64,.05);font-weight:700;color:#166534;"><?php echo rv($r['cc_total']??0);?></td>
      <td style="background:rgba(15,118,110,.05);"><?php echo rv($r['sr_daily_sale']??0);?></td>
      <td style="background:rgba(15,118,110,.05);"><?php echo rv($r['sr_rcvd_credit']??0);?></td>
      <td style="background:rgba(15,118,110,.05);"><?php echo rv($r['sr_rcvd_rtn_chq']??0);?></td>
      <td style="background:rgba(15,118,110,.05);"><?php echo rv($r['sr_rcvd_rtn_chgs']??0);?></td>
      <td style="background:rgba(15,118,110,.05);"><?php echo rv($r['sr_rcvd_sent_back']??0);?></td>
      <td style="background:rgba(15,118,110,.05);font-weight:700;color:#0f766e;"><?php echo rv($r['sr_total']??0);?></td>
      <td style="font-weight:800;"><?php echo rv($r['total_coll']??0);?></td>
      <td><?php echo rv($r['banked_cc']??0);?></td>
      <td><?php echo rv($r['banked_sr']??0);?></td>
      <td><?php echo rv($r['handed_cc']??0);?></td>
      <td><?php echo rv($r['handed_sr']??0);?></td>
      <td><?php echo rse($r['variance_cc']??0);?></td>
      <td><?php echo rse($r['variance_sr']??0);?></td>
      <?php $sc2=floatval($r['short_cc']??0);$ss2=floatval($r['short_sr']??0);?>
      <td><?php echo $sc2>0?'<span class="sv">'.number_format($sc2,2).'</span>':'<span class="dash">—</span>';?></td>
      <td><?php echo $ss2>0?'<span class="sv">'.number_format($ss2,2).'</span>':'<span class="dash">—</span>';?></td>
      <td style="background:rgba(12,74,110,.07);"><?php echo rtr_combined($r['t_out']??0,$r['t_in']??0);?></td>
      <td style="background:rgba(49,46,129,.07);"><?php echo rse($r['new_short_excess']??0);?></td>
      <?php $pc=floatval($r['p_charge']??0);$pa=floatval($r['p_absorb']??0);?>
      <td style="background:rgba(91,33,182,.05);"><?php echo $pc>0?'<span class="val-charge">'.number_format($pc,2).'</span>':'<span class="dash">—</span>';?></td>
      <td style="background:rgba(91,33,182,.05);"><?php echo $pa>0?'<span class="val-absorb">'.number_format($pa,2).'</span>':'<span class="dash">—</span>';?></td>
      <td><?php echo rpvar($r['p_variance']??null);?></td>
    </tr>
    <?php endforeach;?>
    </tbody>
    <tfoot>
      <tr>
        <td class="tl stk" colspan="2">TOTAL — <?php echo count($rows);?> row(s)</td>
        <td><?php echo rt($totals['sinv']??0);?></td>
        <td><?php echo rt($totals['cash_paid']??0);?></td>
        <td><?php echo rt($totals['cheque_paid']??0);?></td>
        <td><?php echo rt($totals['credit']??0);?></td>
        <td><?php echo rse($totals['pay_diff']??0);?></td>
        <td><?php echo rt($totals['cc_daily_sale']??0);?></td>
        <td><?php echo rt($totals['cc_rcvd_credit']??0);?></td>
        <td><?php echo rt($totals['cc_rcvd_rtn_chq']??0);?></td>
        <td><?php echo rt($totals['cc_rcvd_rtn_chgs']??0);?></td>
        <td><?php echo rt($totals['cc_rcvd_sent_back']??0);?></td>
        <td><?php echo rt($totals['cc_total']??0);?></td>
        <td><?php echo rt($totals['sr_daily_sale']??0);?></td>
        <td><?php echo rt($totals['sr_rcvd_credit']??0);?></td>
        <td><?php echo rt($totals['sr_rcvd_rtn_chq']??0);?></td>
        <td><?php echo rt($totals['sr_rcvd_rtn_chgs']??0);?></td>
        <td><?php echo rt($totals['sr_rcvd_sent_back']??0);?></td>
        <td><?php echo rt($totals['sr_total']??0);?></td>
        <td><?php echo rt($totals['total_coll']??0);?></td>
        <td><?php echo rt($totals['banked_cc']??0);?></td>
        <td><?php echo rt($totals['banked_sr']??0);?></td>
        <td><?php echo rt($totals['handed_cc']??0);?></td>
        <td><?php echo rt($totals['handed_sr']??0);?></td>
        <td><?php echo rse($totals['variance_cc']??0);?></td>
        <td><?php echo rse($totals['variance_sr']??0);?></td>
        <?php $sc=$totals['short_cc']??0;$ss=$totals['short_sr']??0;?>
        <td><?php echo $sc>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($sc,2).'</span>':'—';?></td>
        <td><?php echo $ss>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($ss,2).'</span>':'—';?></td>
        <td><?php echo rtr_combined($totals['t_out']??0,$totals['t_in']??0);?></td>
        <td><?php echo rse($totals['new_short_excess']??0);?></td>
        <td><?php echo rt($totals['p_charge']??0);?></td>
        <td><?php echo rt($totals['p_absorb']??0);?></td>
        <td>—</td>
      </tr>
    </tfoot>
  </table>

<?php elseif($is_cc): /* ════ CC SUMMARY TABLE ════ */ ?>
  <table class="cct">
    <thead>
      <tr class="G">
        <th class="h0 tl stk" rowspan="2" style="min-width:78px;">Rep</th>
        <th class="h0 tl"     rowspan="2" style="min-width:150px;">Date Range</th>
        <th class="h1"        colspan="1">Sec. Invoice</th>
        <th class="hcs_cc"    colspan="6">CC Collection</th>
        <th class="hcs_dep"   colspan="2">CC Deposits</th>
        <th class="hcs_sht"   colspan="1">Shortage</th>
        <th class="hcs_exc"   colspan="1">Excess</th>
        <th class="hcs_var"   colspan="1">Short/Excess</th>
        <th class="hcs_tr"    colspan="1">Cross Charge</th>
        <th class="hcs_new"   colspan="1">Final Short/Excess</th>
        <th class="hcs_rec"   colspan="3">Shortage Recovery</th>
      </tr>
      <tr class="S">
        <th class="s1"         style="min-width:108px;">Sec. Inv. Amt</th>
        <th class="scs_cc"     style="min-width:90px;">Daily Sale</th>
        <th class="scs_cc"     style="min-width:90px;">Credit Rcvd</th>
        <th class="scs_cc"     style="min-width:82px;">Rtn Cheque</th>
        <th class="scs_cc"     style="min-width:78px;">Rtn Chgs</th>
        <th class="scs_cc"     style="min-width:78px;">Sent Back</th>
        <th class="scs_cc"     style="min-width:90px;">CC Total</th>
        <th class="scs_dep_bank" style="min-width:96px;">Bank</th>
        <th class="scs_dep_bo"   style="min-width:96px;">Back Office</th>
        <th class="scs_sht"    style="min-width:82px;">Short Amt</th>
        <th class="scs_exc"    style="min-width:82px;">Excess Amt</th>
        <th class="scs_var"    style="min-width:82px;">Short/Excess</th>
        <th class="scs_tr"     style="min-width:110px;">Cross Charge</th>
        <th class="scs_new"    style="min-width:92px;">Final S/E</th>
        <th class="scs_rec"    style="min-width:90px;">Charge</th>
        <th class="scs_rec2"   style="min-width:90px;">Absorb</th>
        <th class="scs_rec3"   style="min-width:80px;">Pay Var.</th>
      </tr>
      <tr class="TH">
        <td class="tl stk" colspan="2"><i class="fa-solid fa-sigma"></i> Total</td>
        <td><?php echo rt($totals['sinv']??0);?></td>
        <td><?php echo rt($totals['cc_daily_sale']??0);?></td>
        <td><?php echo rt($totals['cc_rcvd_credit']??0);?></td>
        <td><?php echo rt($totals['cc_rcvd_rtn_chq']??0);?></td>
        <td><?php echo rt($totals['cc_rcvd_rtn_chgs']??0);?></td>
        <td><?php echo rt($totals['cc_rcvd_sent_back']??0);?></td>
        <td style="color:#0f766e;font-weight:800;"><?php echo rt($totals['cc_total']??0);?></td>
        <td><?php echo rt($totals['banked_cc']??0);?></td>
        <td><?php echo rt($totals['handed_cc']??0);?></td>
        <?php $ts=$totals['cash_short']??0;$te=$totals['cash_excess']??0;?>
        <td><?php echo $ts>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($ts,2).'</span>':'—';?></td>
        <td><?php echo $te>0?'<span style="color:#1d4ed8;font-weight:800;">'.number_format($te,2).'</span>':'—';?></td>
        <?php $tv=floatval($totals['cc_total']??0)-floatval($totals['banked_cc']??0)-floatval($totals['handed_cc']??0);?>
        <td><?php echo rse($tv);?></td>
        <td><?php echo rtr_combined($totals['t_out']??0,$totals['t_in']??0);?></td>
        <td><?php echo rse($totals['new_short_excess']??0);?></td>
        <td><?php echo rt($totals['p_charge']??0);?></td>
        <td><?php echo rt($totals['p_absorb']??0);?></td>
        <td>—</td>
      </tr>
    </thead>
    <tbody>
    <?php foreach($rows as $i=>$r):$stripe=($i%2!==0)?'stripe':'';?>
    <tr class="<?php echo $stripe;?>">
      <td class="tl stk" style="font-weight:700;"><?php echo htmlspecialchars($r['sr_code']??'');?></td>
      <td style="font-family:var(--mn);font-size:10px;color:var(--txm);"><?php echo htmlspecialchars($r['del_date']??'');?></td>
      <td style="font-weight:700;color:#1e40af;"><?php echo rv($r['sinv']??0);?></td>
      <td style="background:rgba(15,118,110,.05);"><?php echo rv($r['cc_daily_sale']??0);?></td>
      <td style="background:rgba(15,118,110,.05);"><?php echo rv($r['cc_rcvd_credit']??0);?></td>
      <td style="background:rgba(15,118,110,.05);"><?php echo rv($r['cc_rcvd_rtn_chq']??0);?></td>
      <td style="background:rgba(15,118,110,.05);"><?php echo rv($r['cc_rcvd_rtn_chgs']??0);?></td>
      <td style="background:rgba(15,118,110,.05);"><?php echo rv($r['cc_rcvd_sent_back']??0);?></td>
      <td style="background:rgba(15,118,110,.05);font-weight:700;color:#0f766e;"><?php echo rv($r['cc_total']??0);?></td>
      <td><?php echo rv($r['banked_cc']??0);?></td>
      <td><?php echo rv($r['handed_cc']??0);?></td>
      <?php $cs=floatval($r['cash_short']??0);$ce=floatval($r['cash_excess']??0);?>
      <td style="background:rgba(153,27,27,.05);"><?php echo $cs>0?'<span class="sv">'.number_format($cs,2).'</span>':'<span class="dash">—</span>';?></td>
      <td style="background:rgba(30,64,175,.05);"><?php echo $ce>0?'<span class="ev">'.number_format($ce,2).'</span>':'<span class="dash">—</span>';?></td>
      <td><?php echo rse($r['bank_diff']??0);?></td>
      <td style="background:rgba(12,74,110,.07);"><?php echo rtr_combined($r['t_out']??0,$r['t_in']??0);?></td>
      <td style="background:rgba(49,46,129,.07);"><?php echo rse($r['new_short_excess']??0);?></td>
      <?php $pc=floatval($r['p_charge']??0);$pa=floatval($r['p_absorb']??0);?>
      <td style="background:rgba(91,33,182,.05);"><?php echo $pc>0?'<span class="val-charge">'.number_format($pc,2).'</span>':'<span class="dash">—</span>';?></td>
      <td style="background:rgba(91,33,182,.05);"><?php echo $pa>0?'<span class="val-absorb">'.number_format($pa,2).'</span>':'<span class="dash">—</span>';?></td>
      <td><?php echo rpvar($r['p_variance']??null);?></td>
    </tr>
    <?php endforeach;?>
    </tbody>
    <tfoot>
      <tr>
        <td class="tl stk" colspan="2">TOTAL — <?php echo count($rows);?> rep(s)</td>
        <td><?php echo rt($totals['sinv']??0);?></td>
        <td><?php echo rt($totals['cc_daily_sale']??0);?></td>
        <td><?php echo rt($totals['cc_rcvd_credit']??0);?></td>
        <td><?php echo rt($totals['cc_rcvd_rtn_chq']??0);?></td>
        <td><?php echo rt($totals['cc_rcvd_rtn_chgs']??0);?></td>
        <td><?php echo rt($totals['cc_rcvd_sent_back']??0);?></td>
        <td><?php echo rt($totals['cc_total']??0);?></td>
        <td><?php echo rt($totals['banked_cc']??0);?></td>
        <td><?php echo rt($totals['handed_cc']??0);?></td>
        <?php $ts=$totals['cash_short']??0;$te=$totals['cash_excess']??0;?>
        <td><?php echo $ts>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($ts,2).'</span>':'—';?></td>
        <td><?php echo $te>0?'<span style="color:#1d4ed8;font-weight:800;">'.number_format($te,2).'</span>':'—';?></td>
        <?php $fv=floatval($totals['cc_total']??0)-floatval($totals['banked_cc']??0)-floatval($totals['handed_cc']??0);?>
        <td><?php echo rse($fv);?></td>
        <td><?php echo rtr_combined($totals['t_out']??0,$totals['t_in']??0);?></td>
        <td><?php echo rse($totals['new_short_excess']??0);?></td>
        <td><?php echo rt($totals['p_charge']??0);?></td>
        <td><?php echo rt($totals['p_absorb']??0);?></td>
        <td>—</td>
      </tr>
    </tfoot>
  </table>
<?php elseif($is_sr): /* ════ SR SUMMARY TABLE ════ */ ?>
  <table class="cct">
    <thead>
      <tr class="G">
        <th class="h0 tl stk" rowspan="2" style="min-width:78px;">Rep</th>
        <th class="h0 tl"     rowspan="2" style="min-width:150px;">Date Range</th>
        <th class="h1"        colspan="1">Sec. Invoice</th>
        <th class="hsr"       colspan="6">SR Collection</th>
        <th class="hdep"      colspan="2">SR Deposits</th>
        <th class="hshort"    colspan="1">Shortage</th>
        <th class="hvar"      colspan="1">Short/Excess</th>
        <th class="htr"       colspan="1">Cross Charge</th>
        <th class="hnew"      colspan="1">Final Shortage</th>
        <th class="hrec"      colspan="3">Shortage Recovery</th>
      </tr>
      <tr class="S">
        <th class="ssinv"      style="min-width:108px;">Sec. Inv. Amt</th>
        <th class="ssr"        style="min-width:90px;">Daily Sale</th>
        <th class="ssr"        style="min-width:90px;">Credit Rcvd</th>
        <th class="ssr"        style="min-width:82px;">Rtn Cheque</th>
        <th class="ssr"        style="min-width:78px;">Rtn Chgs</th>
        <th class="ssr"        style="min-width:78px;">Sent Back</th>
        <th class="ssr"        style="min-width:90px;">SR Total</th>
        <th class="sdep_bank"  style="min-width:96px;">Bank</th>
        <th class="sdep_bo"    style="min-width:96px;">Back Office</th>
        <th class="sshort"     style="min-width:82px;">Short Amt</th>
        <th class="svar"       style="min-width:82px;">Short/Excess</th>
        <th class="str"        style="min-width:110px;">Cross Charge</th>
        <th class="snew"       style="min-width:92px;">Final S.</th>
        <th class="srec"       style="min-width:90px;">Charge</th>
        <th class="srec"       style="min-width:90px;">Absorb</th>
        <th class="srec"       style="min-width:80px;">Pay Var.</th>
      </tr>
      <tr class="TH">
        <td class="tl stk" colspan="2"><i class="fa-solid fa-sigma"></i> Total</td>
        <td><?php echo rt($totals['sinv']??0);?></td>
        <td><?php echo rt($totals['sr_daily_sale']??0);?></td>
        <td><?php echo rt($totals['sr_rcvd_credit']??0);?></td>
        <td><?php echo rt($totals['sr_rcvd_rtn_chq']??0);?></td>
        <td><?php echo rt($totals['sr_rcvd_rtn_chgs']??0);?></td>
        <td><?php echo rt($totals['sr_rcvd_sent_back']??0);?></td>
        <td style="color:#0f766e;font-weight:800;"><?php echo rt($totals['sr_total']??0);?></td>
        <td><?php echo rt($totals['banked_sr']??0);?></td>
        <td><?php echo rt($totals['handed_sr']??0);?></td>
        <?php $ts=floatval($totals['cash_short']??0);?>
        <td><?php echo $ts>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($ts,2).'</span>':'—';?></td>
        <?php $sv=floatval($totals['sr_total']??0)-floatval($totals['banked_sr']??0)-floatval($totals['handed_sr']??0);?>
        <td><?php echo rse($sv);?></td>
        <td><?php echo rtr_combined($totals['t_out']??0,$totals['t_in']??0);?></td>
        <td><?php echo rse($totals['new_short_excess']??0);?></td>
        <td><?php echo rt($totals['p_charge']??0);?></td>
        <td><?php echo rt($totals['p_absorb']??0);?></td>
        <td>—</td>
      </tr>
    </thead>
    <tbody>
    <?php foreach($rows as $i=>$r):$stripe=($i%2!==0)?'stripe':'';?>
    <tr class="<?php echo $stripe;?>">
      <td class="tl stk" style="font-weight:700;"><?php echo htmlspecialchars($r['sr_code']??'');?></td>
      <td style="font-family:var(--mn);font-size:10px;color:var(--txm);"><?php echo htmlspecialchars($r['del_date']??'');?></td>
      <td style="font-weight:700;color:#1e40af;"><?php echo rv($r['sinv']??0);?></td>
      <td style="background:rgba(15,118,110,.05);"><?php echo rv($r['sr_daily_sale']??0);?></td>
      <td style="background:rgba(15,118,110,.05);"><?php echo rv($r['sr_rcvd_credit']??0);?></td>
      <td style="background:rgba(15,118,110,.05);"><?php echo rv($r['sr_rcvd_rtn_chq']??0);?></td>
      <td style="background:rgba(15,118,110,.05);"><?php echo rv($r['sr_rcvd_rtn_chgs']??0);?></td>
      <td style="background:rgba(15,118,110,.05);"><?php echo rv($r['sr_rcvd_sent_back']??0);?></td>
      <td style="background:rgba(15,118,110,.05);font-weight:700;color:#0f766e;"><?php echo rv($r['sr_total']??0);?></td>
      <td><?php echo rv($r['banked_sr']??0);?></td>
      <td><?php echo rv($r['handed_sr']??0);?></td>
      <?php $cs=floatval($r['cash_short']??0);?>
      <td style="background:rgba(153,27,27,.05);"><?php echo $cs>0?'<span class="sv">'.number_format($cs,2).'</span>':'<span class="dash">—</span>';?></td>
      <td><?php echo rse($r['bank_diff']??0);?></td>
      <td style="background:rgba(12,74,110,.07);"><?php echo rtr_combined($r['t_out']??0,$r['t_in']??0);?></td>
      <td style="background:rgba(49,46,129,.07);"><?php echo rse($r['new_short_excess']??0);?></td>
      <?php $pc=floatval($r['p_charge']??0);$pa=floatval($r['p_absorb']??0);?>
      <td style="background:rgba(91,33,182,.05);"><?php echo $pc>0?'<span class="val-charge">'.number_format($pc,2).'</span>':'<span class="dash">—</span>';?></td>
      <td style="background:rgba(91,33,182,.05);"><?php echo $pa>0?'<span class="val-absorb">'.number_format($pa,2).'</span>':'<span class="dash">—</span>';?></td>
      <td><?php echo rpvar($r['p_variance']??null);?></td>
    </tr>
    <?php endforeach;?>
    </tbody>
    <tfoot>
      <tr>
        <td class="tl stk" colspan="2">TOTAL — <?php echo count($rows);?> rep(s)</td>
        <td><?php echo rt($totals['sinv']??0);?></td>
        <td><?php echo rt($totals['sr_daily_sale']??0);?></td>
        <td><?php echo rt($totals['sr_rcvd_credit']??0);?></td>
        <td><?php echo rt($totals['sr_rcvd_rtn_chq']??0);?></td>
        <td><?php echo rt($totals['sr_rcvd_rtn_chgs']??0);?></td>
        <td><?php echo rt($totals['sr_rcvd_sent_back']??0);?></td>
        <td><?php echo rt($totals['sr_total']??0);?></td>
        <td><?php echo rt($totals['banked_sr']??0);?></td>
        <td><?php echo rt($totals['handed_sr']??0);?></td>
        <?php $ts=floatval($totals['cash_short']??0);?>
        <td><?php echo $ts>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($ts,2).'</span>':'—';?></td>
        <?php $sv=floatval($totals['sr_total']??0)-floatval($totals['banked_sr']??0)-floatval($totals['handed_sr']??0);?>
        <td><?php echo rse($sv);?></td>
        <td><?php echo rtr_combined($totals['t_out']??0,$totals['t_in']??0);?></td>
        <td><?php echo rse($totals['new_short_excess']??0);?></td>
        <td><?php echo rt($totals['p_charge']??0);?></td>
        <td><?php echo rt($totals['p_absorb']??0);?></td>
        <td>—</td>
      </tr>
    </tfoot>
  </table>
<?php endif;?>
  </div><!-- tscroll -->
</div><!-- tc -->

<?php else: /* ════ LIST VIEW ════ */
    /* aggregate stats */
    $stat_total = $total_count;
    $stat_daily = 0; $stat_cc = 0; $stat_sr = 0;
    $cr2 = mysqli_query($conn,"SELECT report_type, COUNT(*) AS c FROM cash_collection_saved_reports GROUP BY report_type");
    if($cr2) while($rx=mysqli_fetch_assoc($cr2)){
        if($rx['report_type']==='cc_summary')     $stat_cc=intval($rx['c']);
        elseif($rx['report_type']==='sr_summary') $stat_sr=intval($rx['c']);
        else $stat_daily+=intval($rx['c']);
    }
?>
<!-- Topbar -->
<div class="topbar">
  <div>
    <div class="pg-h1"><i class="fa-solid fa-folder-open" style="color:var(--acc2);font-size:16px;"></i> Saved <em>Reports</em></div>
    <div class="pg-sub">Cash Collection &amp; CC Summary snapshots — view, rename or delete</div>
  </div>
  <div style="display:flex;gap:7px;align-items:center;flex-wrap:wrap;">
    <a href="cash_collection.php" class="btn btn-ghost"><i class="fa-solid fa-calendar-day"></i> Daily Report</a>
    <a href="cc_collection_summary.php" class="btn btn-ghost"><i class="fa-solid fa-user-tie"></i> CC Summary</a>
    <a href="sr_collection_summary.php" class="btn btn-ghost"><i class="fa-solid fa-person-walking"></i> SR Summary</a>
  </div>
</div>

<!-- Stat cards -->
<div class="stat-row" style="grid-template-columns:repeat(5,1fr);">
  <div class="stat-card purple"><div class="stat-lbl"><i class="fa-solid fa-folder"></i> Total Saved</div><div class="stat-val" style="color:#7c3aed;"><?php echo $stat_total;?></div></div>
  <div class="stat-card blue">  <div class="stat-lbl"><i class="fa-solid fa-calendar-day"></i> Daily Reports</div><div class="stat-val" style="color:#1e40af;"><?php echo $stat_daily;?></div></div>
  <div class="stat-card teal">  <div class="stat-lbl"><i class="fa-solid fa-user-tie"></i> CC Summaries</div><div class="stat-val" style="color:#0f766e;"><?php echo $stat_cc;?></div></div>
  <div class="stat-card green"> <div class="stat-lbl"><i class="fa-solid fa-person-walking"></i> SR Summaries</div><div class="stat-val" style="color:#047857;"><?php echo $stat_sr;?></div></div>
  <div class="stat-card green"> <div class="stat-lbl"><i class="fa-solid fa-clock-rotate-left"></i> Showing</div><div class="stat-val" style="color:#166534;"><?php echo count($reports);?></div></div>
</div>

<!-- Filter bar -->
<div class="fbar">
  <form method="GET" style="display:contents;">
    <div class="fg"><label>Type</label>
      <select name="type">
        <option value="">— All Types —</option>
        <option value="daily"      <?php echo $f_type==='daily'?'selected':'';?>>Daily</option>
        <option value="cc_summary" <?php echo $f_type==='cc_summary'?'selected':'';?>>CC Summary</option>
        <option value="sr_summary" <?php echo $f_type==='sr_summary'?'selected':'';?>>SR Summary</option>
      </select>
    </div>
    <div class="fg"><label>Date From ≥</label><input type="date" name="from" value="<?php echo htmlspecialchars($f_from);?>"></div>
    <div class="fg"><label>Date To ≤</label><input type="date" name="to"   value="<?php echo htmlspecialchars($f_to);?>"></div>
    <div class="fg" style="min-width:200px;"><label>Report Name</label><input type="text" name="name" value="<?php echo htmlspecialchars($f_name);?>" placeholder="Search…"></div>
    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
    <a href="view_cash_reports.php" class="btn btn-ghost"><i class="fa-solid fa-rotate-left"></i> Reset</a>
  </form>
</div>

<!-- List table -->
<div class="tc">
  <div class="tc-bar">
    <div class="tc-ttl"><i class="fa-solid fa-list"></i> Report List <span class="pill"><?php echo $total_count;?> total</span></div>
    <div style="font-size:11px;color:var(--txs);">Page <?php echo $page;?> of <?php echo $total_pages;?></div>
  </div>

  <?php if(empty($reports)):?>
  <div class="empty"><i class="fa-solid fa-folder-open"></i><p style="font-size:14px;font-weight:600;margin-bottom:5px;">No reports saved yet</p><p>Use <strong>Save Report</strong> on the Daily or CC Summary report pages.</p></div>
  <?php else:?>
  <div style="overflow-x:auto;">
  <table class="rpt-tbl">
    <thead>
      <tr>
        <th style="width:32px;">#</th>
        <th>Report Name</th>
        <th>Type</th>
        <th>Date Range</th>
        <th>Rep</th>
        <th>Rows</th>
        <th>CC Total</th>
        <th>SR Total</th>
        <th>Net Short/Excess</th>
        <th>Saved At</th>
        <th>Saved By</th>
        <th style="width:160px;">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($reports as $i=>$rep):$stripe=($i%2!==0)?'stripe':'';?>
    <tr class="<?php echo $stripe;?>" id="row-<?php echo $rep['id'];?>">
      <td class="rn" style="color:var(--txs);"><?php echo $rep['id'];?></td>
      <td>
        <div class="rpt-name" id="name-display-<?php echo $rep['id'];?>" onclick="window.location='view_cash_reports.php?id=<?php echo $rep['id'];?>'">
          <?php echo htmlspecialchars($rep['report_name']);?>
          <i class="fa-solid fa-arrow-up-right-from-square"></i>
        </div>
        <div id="name-edit-<?php echo $rep['id'];?>" style="display:none;align-items:center;flex-wrap:wrap;gap:4px;">
          <input type="text" class="rename-input" id="rename-val-<?php echo $rep['id'];?>" value="<?php echo htmlspecialchars($rep['report_name']);?>">
          <button class="rename-save" onclick="doRename(<?php echo $rep['id'];?>)"><i class="fa-solid fa-check"></i> Save</button>
          <button class="rename-cancel" onclick="cancelRename(<?php echo $rep['id'];?>)">Cancel</button>
        </div>
        <div class="rpt-meta">ID #<?php echo $rep['id'];?> · <?php echo intval($rep['row_count']);?> row(s)</div>
      </td>
      <td><?php echo type_badge($rep['report_type']);?></td>
      <td class="rn"><?php echo fmt_date($rep['date_from']);?><?php if($rep['date_from']!==$rep['date_to']):?><br><span style="color:var(--txs);">→ <?php echo fmt_date($rep['date_to']);?></span><?php endif;?></td>
      <td><?php echo $rep['sr_code']?'<span style="font-family:var(--mn);font-weight:600;">'.htmlspecialchars($rep['sr_code']).'</span>':'<span style="color:var(--txs);">All</span>';?></td>
      <td class="rn" style="text-align:right;"><?php echo intval($rep['row_count']);?></td>
      <td class="rn" style="text-align:right;color:#0f766e;"><?php echo $rep['total_cc']>0?'Rs. '.fmt_num($rep['total_cc']):'<span style="color:var(--txs);">—</span>';?></td>
      <td class="rn" style="text-align:right;color:#166534;"><?php echo $rep['total_sr']>0?'Rs. '.fmt_num($rep['total_sr']):'<span style="color:var(--txs);">—</span>';?></td>
      <td><?php echo short_badge($rep['total_short']);?></td>
      <td class="rn" style="color:var(--txm);"><?php echo date('d M Y', strtotime($rep['saved_at']));?><br><span style="color:var(--txs);"><?php echo date('H:i', strtotime($rep['saved_at']));?></span></td>
      <td><?php echo $rep['saved_by']?htmlspecialchars($rep['saved_by']):'<span style="color:var(--txs);">—</span>';?></td>
      <td>
        <div class="actions">
          <button class="act-btn act-view" onclick="window.location='view_cash_reports.php?id=<?php echo $rep['id'];?>'"><i class="fa-solid fa-eye"></i> View</button>
          <button class="act-btn act-edit" onclick="startRename(<?php echo $rep['id'];?>)" title="Rename"><i class="fa-solid fa-pen"></i></button>
          <button class="act-btn act-del"  onclick="doDelete(<?php echo $rep['id'];?>, '<?php echo addslashes(htmlspecialchars($rep['report_name']));?>')" title="Delete"><i class="fa-solid fa-trash"></i></button>
        </div>
      </td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  </div>

  <!-- Pagination -->
  <?php if($total_pages > 1): ?>
  <div class="pager">
    <div class="pager-info">Showing <?php echo (($page-1)*$per+1);?>–<?php echo min($page*$per,$total_count);?> of <?php echo $total_count;?> reports</div>
    <div class="pager-btns">
      <?php
      $qs = http_build_query(array_filter(['type'=>$f_type,'from'=>$f_from,'to'=>$f_to,'name'=>$f_name]));
      $qs = $qs ? '&'.$qs : '';
      ?>
      <a href="?page=1<?php echo $qs;?>" class="pager-btn <?php echo $page==1?'disabled':'';?>"><i class="fa-solid fa-angles-left"></i></a>
      <a href="?page=<?php echo max(1,$page-1);?><?php echo $qs;?>" class="pager-btn <?php echo $page==1?'disabled':'';?>"><i class="fa-solid fa-angle-left"></i></a>
      <?php for($p=max(1,$page-2);$p<=min($total_pages,$page+2);$p++):?>
      <a href="?page=<?php echo $p;?><?php echo $qs;?>" class="pager-btn <?php echo $p==$page?'active':'';?>"><?php echo $p;?></a>
      <?php endfor;?>
      <a href="?page=<?php echo min($total_pages,$page+1);?><?php echo $qs;?>" class="pager-btn <?php echo $page>=$total_pages?'disabled':'';?>"><i class="fa-solid fa-angle-right"></i></a>
      <a href="?page=<?php echo $total_pages;?><?php echo $qs;?>" class="pager-btn <?php echo $page>=$total_pages?'disabled':'';?>"><i class="fa-solid fa-angles-right"></i></a>
    </div>
  </div>
  <?php endif;?>
  <?php endif;?>
</div><!-- tc -->

<?php endif; /* end list/detail fork */ ?>
</div><!-- pg -->

<div id="vrToast"></div>

<script>
function showToast(msg,type){
    var t=document.getElementById('vrToast');
    t.className=type==='ok'?'toast-ok':'toast-err';
    t.textContent=msg;t.style.display='block';t.style.opacity='1';
    clearTimeout(t._t);
    t._t=setTimeout(function(){t.style.opacity='0';setTimeout(function(){t.style.display='none';},300);},2800);
}

function doDelete(id, name){
    if(!confirm('Delete report "'+name+'"?\nThis cannot be undone.')) return;
    var fd=new FormData();fd.append('action','delete');fd.append('id',id);
    fetch('view_cash_reports.php',{method:'POST',body:fd})
    .then(function(r){return r.json();}).then(function(res){
        if(res.success){
            var row=document.getElementById('row-'+id);
            if(row){row.style.transition='opacity .3s';row.style.opacity='0';setTimeout(function(){row.remove();},320);}
            showToast('Report deleted.','ok');
        } else showToast('Error: '+(res.message||'Unknown'),'err');
    }).catch(function(err){showToast('Network error: '+err.message,'err');});
}

function startRename(id){
    document.getElementById('name-display-'+id).style.display='none';
    var ed=document.getElementById('name-edit-'+id);ed.style.display='flex';
    document.getElementById('rename-val-'+id).focus();
}
function cancelRename(id){
    document.getElementById('name-edit-'+id).style.display='none';
    document.getElementById('name-display-'+id).style.display='flex';
}
function doRename(id){
    var name=document.getElementById('rename-val-'+id).value.trim();
    if(!name){showToast('Name cannot be empty.','err');return;}
    var fd=new FormData();fd.append('action','rename');fd.append('id',id);fd.append('name',name);
    fetch('view_cash_reports.php',{method:'POST',body:fd})
    .then(function(r){return r.json();}).then(function(res){
        if(res.success){
            cancelRename(id);
            var disp=document.getElementById('name-display-'+id);
            if(disp) disp.innerHTML=escHtml(name)+' <i class="fa-solid fa-arrow-up-right-from-square"></i>';
            showToast('Renamed successfully.','ok');
        } else showToast('Error: '+(res.message||'Unknown'),'err');
    }).catch(function(err){showToast('Network error: '+err.message,'err');});
}
function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>
<?php include 'footer.php'; ?>