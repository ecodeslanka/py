<?php
include 'config.php';

/* ── helper ── */
function isValidDate($d) {
    return !empty($d) && $d !== '0000-00-00' && $d !== '0000-00-00 00:00:00' && strtotime($d) !== false && strtotime($d) > 0;
}

/* ── filters ── */
$f_dep_from      = trim($_GET['dep_from']      ?? '');
$f_dep_to        = trim($_GET['dep_to']        ?? '');
$f_cash_from     = trim($_GET['cash_from']     ?? '');
$f_cash_to       = trim($_GET['cash_to']       ?? '');
$f_del_from      = trim($_GET['del_from']      ?? '');
$f_del_to        = trim($_GET['del_to']        ?? '');
$f_dp            = trim($_GET['delivery_person'] ?? '');
$f_collected_by  = trim($_GET['collected_by']  ?? '');
$f_type          = trim($_GET['dep_type']      ?? '');

/* ── detect if filter was submitted ── */
$filter_submitted = isset($_GET['dep_from']) || isset($_GET['dep_to']) || isset($_GET['cash_from'])
    || isset($_GET['cash_to']) || isset($_GET['del_from']) || isset($_GET['del_to'])
    || isset($_GET['delivery_person']) || isset($_GET['collected_by']) || isset($_GET['dep_type']);

/* ── AJAX: details popup ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'details') {
    header('Content-Type: application/json');
    $dp       = mysqli_real_escape_string($conn, $_GET['dp']      ?? '');
    $col_by   = mysqli_real_escape_string($conn, $_GET['col_by']   ?? '');
    $grp      = $_GET['grp'] ?? 'dep';

    $wheres = ["p.delivery_person='$dp'"];
    if ($col_by !== '')  $wheres[] = "d.collected_by='$col_by'";
    if (!empty($_GET['dep_from']))  $wheres[] = "d.deposit_date >= '"  .mysqli_real_escape_string($conn,$_GET['dep_from'])."'";
    if (!empty($_GET['dep_to']))    $wheres[] = "d.deposit_date <= '"  .mysqli_real_escape_string($conn,$_GET['dep_to'])."'";
    if (!empty($_GET['cash_from'])) $wheres[] = "d.cash_receive_date >= '".mysqli_real_escape_string($conn,$_GET['cash_from'])."'";
    if (!empty($_GET['cash_to']))   $wheres[] = "d.cash_receive_date <= '".mysqli_real_escape_string($conn,$_GET['cash_to'])."'";
    if (!empty($_GET['del_from']))  $wheres[] = "p.delivery_date >= '"  .mysqli_real_escape_string($conn,$_GET['del_from'])."'";
    if (!empty($_GET['del_to']))    $wheres[] = "p.delivery_date <= '"  .mysqli_real_escape_string($conn,$_GET['del_to'])."'";
    if (!empty($_GET['dep_type'])) {
        if ($_GET['dep_type'] === 'bo')   $wheres[] = "d.handed_over_bo=1";
        if ($_GET['dep_type'] === 'bank') $wheres[] = "d.handed_over_bo=0";
    }

    $where_sql = implode(' AND ', $wheres);
    $res = mysqli_query($conn, "
        SELECT d.id, d.deposit_date, d.cash_receive_date, p.delivery_date,
               d.handed_over_bo, d.collected_by,
               p.amount AS person_amount,
               CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
                      COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,'')) AS bank_label,
               COALESCE(NULLIF(e.name_with_initials,''),e.employee_full_name) AS emp_name,
               (SELECT COUNT(*) FROM cc_cash_deposit_dp_attachments a WHERE a.deposit_id=d.id) AS slip_count,
               d.remark
        FROM cc_cash_deposit_dp_persons p
        JOIN cc_cash_deposit_dp d ON d.id = p.deposit_id
        LEFT JOIN company_bank_accounts cba ON cba.id=d.bank_account_id
        LEFT JOIN banks b ON b.bank_code=cba.bank_code
        LEFT JOIN bank_branches bb ON bb.bank_code=cba.bank_code AND bb.branch_code=cba.branch_code
        LEFT JOIN employees e ON e.id=d.employee_id
        WHERE $where_sql
        ORDER BY d.deposit_date DESC, d.id DESC
    ");
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    echo json_encode($rows);
    exit;
}

/* ── delivery person list (for filter dropdown) ── */
$all_dp = [];
$res = mysqli_query($conn, "SELECT DISTINCT delivery_person FROM cc_cash_deposit_dp_persons WHERE delivery_person IS NOT NULL AND delivery_person<>'' ORDER BY delivery_person");
while ($r = mysqli_fetch_assoc($res)) $all_dp[] = $r['delivery_person'];

/* ── only run main query if filter submitted ── */
$raw_rows = [];
$pivot = [];
$grand_total = $grand_bo = $grand_bank = $grand_slips = 0;
$cc_total = $sr_total = 0;
$cc_rows = [];
$sr_rows_data = [];

if ($filter_submitted) {
    /* ── build WHERE for main query ── */
    $where_parts = ['1=1'];
    if ($f_dep_from)     $where_parts[] = "d.deposit_date >= '$f_dep_from'";
    if ($f_dep_to)       $where_parts[] = "d.deposit_date <= '$f_dep_to'";
    if ($f_cash_from)    $where_parts[] = "d.cash_receive_date >= '$f_cash_from'";
    if ($f_cash_to)      $where_parts[] = "d.cash_receive_date <= '$f_cash_to'";
    if ($f_del_from)     $where_parts[] = "p.delivery_date >= '$f_del_from'";
    if ($f_del_to)       $where_parts[] = "p.delivery_date <= '$f_del_to'";
    if ($f_dp)           $where_parts[] = "p.delivery_person = '".mysqli_real_escape_string($conn,$f_dp)."'";
    if ($f_collected_by === 'sr') $where_parts[] = "d.collected_by='sr'";
    if ($f_collected_by === 'cc') $where_parts[] = "d.collected_by='cc'";
    if ($f_type === 'bo')   $where_parts[] = "d.handed_over_bo=1";
    if ($f_type === 'bank') $where_parts[] = "d.handed_over_bo=0";
    $where_sql = implode(' AND ', $where_parts);

    /* ── main aggregation query ── */
    $sql = "
        SELECT
            p.delivery_person,
            d.collected_by,
            SUM(p.amount)                                        AS total_amount,
            SUM(CASE WHEN d.handed_over_bo=1 THEN p.amount ELSE 0 END) AS bo_amount,
            SUM(CASE WHEN d.handed_over_bo=0 THEN p.amount ELSE 0 END) AS bank_amount,
            COUNT(DISTINCT d.id)                                 AS dep_count,
            SUM((SELECT COUNT(*) FROM cc_cash_deposit_dp_attachments a WHERE a.deposit_id=d.id)) AS slip_count
        FROM cc_cash_deposit_dp_persons p
        JOIN cc_cash_deposit_dp d ON d.id = p.deposit_id
        WHERE $where_sql
        GROUP BY p.delivery_person, d.collected_by
        ORDER BY p.delivery_person ASC, d.collected_by ASC
    ";
    $res     = mysqli_query($conn, $sql);
    while ($row = mysqli_fetch_assoc($res)) $raw_rows[] = $row;

    /* ── pivot ── */
    foreach ($raw_rows as $row) {
        $dpn = $row['delivery_person'];
        $cb  = strtolower($row['collected_by'] ?? '') ?: 'other';
        if (!isset($pivot[$dpn])) $pivot[$dpn] = [];
        $pivot[$dpn][$cb] = $row;
    }

    /* ── grand totals ── */
    foreach ($raw_rows as $row) {
        $grand_total += floatval($row['total_amount']);
        $grand_bo    += floatval($row['bo_amount']);
        $grand_bank  += floatval($row['bank_amount']);
        $grand_slips += intval($row['slip_count']);
    }
    $cc_total = array_sum(array_map(fn($r)=>floatval($r['total_amount']), array_filter($raw_rows, fn($r)=>strtolower($r['collected_by']==='cc'))));
    $sr_total = array_sum(array_map(fn($r)=>floatval($r['total_amount']), array_filter($raw_rows, fn($r)=>strtolower($r['collected_by']==='sr'))));
    $cc_rows      = array_filter($raw_rows, fn($r)=>strtolower($r['collected_by']==='cc'));
    $sr_rows_data = array_filter($raw_rows, fn($r)=>strtolower($r['collected_by']==='sr'));
}

/* ── build filter query string for AJAX (pass-through) ── */
$filter_qs = http_build_query([
    'dep_from'     => $f_dep_from,
    'dep_to'       => $f_dep_to,
    'cash_from'    => $f_cash_from,
    'cash_to'      => $f_cash_to,
    'del_from'     => $f_del_from,
    'del_to'       => $f_del_to,
    'dep_type'     => $f_type,
]);

include 'header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<link  href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<style>
*{box-sizing:border-box;}
/* ── layout ── */
.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-success{background:#15803d;color:#fff;}.btn-success:hover{background:#166534;}
.btn-sm{padding:5px 12px;font-size:12px;}
/* ── summary cards ── */
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;}
@media(max-width:900px){.summary-grid{grid-template-columns:1fr 1fr;}}
@media(max-width:500px){.summary-grid{grid-template-columns:1fr;}}
.sum-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.sum-card-label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;}
.sum-card-val{font-size:20px;font-weight:800;color:#1e40af;}
.sum-card.cc .sum-card-val{color:#065f46;}
.sum-card.sr .sum-card-val{color:#92400e;}
.sum-card.total .sum-card-val{color:#111827;}
/* ── filter card ── */
.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:16px 20px;margin-bottom:18px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.filter-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-bottom:8px;}
.filter-row{display:grid;gap:10px;}
.filter-row-1{grid-template-columns:1fr 1fr 1fr 1fr 1fr auto;}
.filter-row-2{grid-template-columns:1fr 1fr 1fr 1fr auto;}
@media(max-width:1100px){.filter-row-1,.filter-row-2{grid-template-columns:1fr 1fr 1fr;}}
@media(max-width:700px){.filter-row-1,.filter-row-2{grid-template-columns:1fr 1fr;}}
.fg label{font-size:11.5px;font-weight:700;color:#374151;display:block;margin-bottom:4px;}
.fctrl{padding:7px 10px;border:1px solid #e0e0e0;border-radius:6px;font-size:12.5px;width:100%;font-family:inherit;background:#fff;color:#111;outline:none;}
.fctrl:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1);}
.filter-divider{border:none;border-top:1px dashed #e5e7eb;margin:12px 0;}
/* ── empty prompt ── */
.prompt-box{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:60px 20px;text-align:center;color:#9ca3af;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.prompt-box i{font-size:40px;display:block;margin-bottom:14px;opacity:.3;}
.prompt-box p{margin:6px 0 0;font-size:14px;line-height:1.6;}
/* ── table ── */
.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:24px;}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;border-bottom:1px solid #f0f0f0;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead th{padding:7px 6px;text-align:left;font-weight:700;font-size:11px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;}
.data-table thead th.tr{text-align:right;}
.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr:hover td{background:#f0f7ff;}
.data-table td{padding:6px 6px;color:#374151;vertical-align:middle;background:#fff;}
.data-table td.tr{text-align:right;}
.data-table td.tc{text-align:center;}
.data-table tfoot td{padding:7px 6px;font-weight:800;font-size:12.5px;background:#0f172a;color:#e2e8f0;}
.data-table tfoot td.tr{text-align:right;}
/* Rs. currency label */
.rs{font-size:10px;font-weight:600;opacity:.7;margin-right:1px;letter-spacing:0;}
/* clickable totals */
.clickable-total{color:#1e40af;font-weight:800;cursor:pointer;text-decoration:underline dotted;transition:color .15s;}
.clickable-total:hover{color:#1d4ed8;}
/* badges */
.badge-sr{background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;}
.badge-cc{background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;}
.badge-bo{background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:9px;font-size:10px;font-weight:700;}
.badge-bank{background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:9px;font-size:10px;font-weight:700;}
.dp-badge{background:#dbeafe;color:#1e40af;border-radius:5px;padding:2px 7px;font-size:11px;font-weight:700;}
.pill{padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;background:#dbeafe;color:#1e40af;}
/* ── Detail Modal ── */
.modal-backdrop{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;padding:16px;}
.modal-backdrop.open{display:flex;}
body.modal-open{overflow:hidden;}
.modal-dialog{background:#fff;border-radius:12px;width:100%;max-width:900px;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 24px 70px rgba(0,0,0,.3);overflow:hidden;}
.modal-hdr{padding:16px 22px;border-bottom:1px solid #e5e5e5;background:#fafafa;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.modal-hdr h3{font-size:16px;font-weight:700;color:#1f2937;margin:0;}
.modal-x{width:30px;height:30px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#6b7280;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;}
.modal-x:hover{background:#f5f5f5;color:#111;}
.modal-body{overflow-y:auto;flex:1;padding:20px 22px;}
.modal-ftr{padding:11px 22px;border-top:1px solid #e5e5e5;background:#fafafa;display:flex;justify-content:flex-end;gap:8px;flex-shrink:0;}
.detail-table{width:100%;border-collapse:collapse;font-size:12px;}
.detail-table thead th{padding:6px 6px;text-align:left;font-weight:700;font-size:11px;background:#1e1b4b;color:#e0e7ff;white-space:nowrap;}
.detail-table thead th.tr{text-align:right;}
.detail-table tbody tr{border-bottom:1px solid #f3f4f6;}
.detail-table tbody tr:hover td{background:#f0f7ff;}
.detail-table td{padding:5px 6px;color:#374151;vertical-align:middle;}
.detail-table td.tr{text-align:right;}
.detail-table tfoot td{padding:6px 6px;font-weight:800;font-size:12px;background:#0f172a;color:#e2e8f0;}
.detail-table tfoot td.tr{text-align:right;}
.date-chip{background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;display:inline-block;padding:2px 7px;border-radius:6px;font-size:11px;font-weight:600;}
.loading-spinner{text-align:center;padding:40px;color:#9ca3af;}
/* toast */
#toast{position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:100020;padding:12px 24px;border-radius:9px;font-size:13px;font-weight:700;font-family:inherit;box-shadow:0 8px 28px rgba(0,0,0,.2);display:none;pointer-events:none;}
.toast-ok{background:#166534;color:#fff;}.toast-err{background:#dc2626;color:#fff;}
/* select2 */
.select2-container--default .select2-selection--single{height:35px!important;border:1px solid #e0e0e0!important;border-radius:6px!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:33px!important;padding-left:10px!important;font-size:12.5px!important;font-family:inherit!important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:33px!important;}
.select2-dropdown{border:1px solid #e0e0e0!important;border-radius:7px!important;font-size:13px!important;font-family:inherit!important;box-shadow:0 4px 16px rgba(0,0,0,.12)!important;}
</style>

<!-- PAGE HEADER -->
<div class="ph-row">
  <div>
    <h2 class="page-title" style="margin:0 0 4px;">Bank Deposit Summary — Delivery Person</h2>
    <p style="margin:0;font-size:13px;color:#6b7280;">Delivery-person-wise cash collection &amp; deposit analysis with BO handover and bank slip totals.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <?php if ($filter_submitted && !empty($raw_rows)): ?>
    <button class="btn btn-success btn-sm" onclick="exportExcel()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
    <?php endif; ?>
    <a href="cc_cash_deposit_dp.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to Deposits</a>
  </div>
</div>

<!-- FILTERS -->
<div class="filter-card">
  <form method="GET" id="filterForm">
    <div class="filter-section-title"><i class="fa-solid fa-calendar-days" style="margin-right:5px;"></i>Date Range Filters</div>
    <div class="filter-row filter-row-1">
      <div class="fg">
        <label>Deposit Date — From</label>
        <input type="date" name="dep_from" class="fctrl" value="<?= htmlspecialchars($f_dep_from) ?>">
      </div>
      <div class="fg">
        <label>Deposit Date — To</label>
        <input type="date" name="dep_to" class="fctrl" value="<?= htmlspecialchars($f_dep_to) ?>">
      </div>
      <div class="fg">
        <label>Cash Receive Date — From</label>
        <input type="date" name="cash_from" class="fctrl" value="<?= htmlspecialchars($f_cash_from) ?>">
      </div>
      <div class="fg">
        <label>Cash Receive Date — To</label>
        <input type="date" name="cash_to" class="fctrl" value="<?= htmlspecialchars($f_cash_to) ?>">
      </div>
      <div class="fg">
        <label>Delivery Date — From</label>
        <input type="date" name="del_from" class="fctrl" value="<?= htmlspecialchars($f_del_from) ?>">
      </div>
      <div class="fg">
        <label>Delivery Date — To</label>
        <input type="date" name="del_to" class="fctrl" value="<?= htmlspecialchars($f_del_to) ?>">
      </div>
    </div>
    <hr class="filter-divider">
    <div class="filter-section-title"><i class="fa-solid fa-sliders" style="margin-right:5px;"></i>Other Filters</div>
    <div class="filter-row filter-row-2">
      <div class="fg">
        <label>Delivery Person</label>
        <select name="delivery_person" id="fFilterDp" class="fctrl" style="width:100%;">
          <option value="">— All Delivery Persons —</option>
          <?php foreach ($all_dp as $dpn): ?>
          <option value="<?= htmlspecialchars($dpn) ?>" <?= $f_dp===$dpn?'selected':'' ?>><?= htmlspecialchars($dpn) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label>Collected By</label>
        <select name="collected_by" class="fctrl">
          <option value="">— All —</option>
          <option value="sr" <?= $f_collected_by==='sr'?'selected':'' ?>>Sales Rep (SR)</option>
          <option value="cc" <?= $f_collected_by==='cc'?'selected':'' ?>>Cash Collector (CC)</option>
        </select>
      </div>
      <div class="fg">
        <label>Deposit Type</label>
        <select name="dep_type" class="fctrl">
          <option value="">— All Types —</option>
          <option value="bank" <?= $f_type==='bank'?'selected':'' ?>>Bank Deposit</option>
          <option value="bo"   <?= $f_type==='bo'  ?'selected':'' ?>>Handed to BO</option>
        </select>
      </div>
      <div class="fg" style="display:flex;align-items:flex-end;gap:8px;">
        <button type="submit" class="btn btn-primary" style="flex:1;height:35px;font-size:12.5px;"><i class="fa-solid fa-magnifying-glass"></i> Apply Filters</button>
        <a href="bank_deposit_summary_dp.php" class="btn btn-secondary" style="height:35px;" title="Clear"><i class="fa-solid fa-rotate-left"></i></a>
      </div>
    </div>
    <?php if($f_dep_from||$f_dep_to||$f_cash_from||$f_cash_to||$f_del_from||$f_del_to||$f_dp||$f_collected_by||$f_type): ?>
    <div style="margin-top:10px;font-size:11px;color:#6b7280;display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
      <span style="font-weight:700;">Active filters:</span>
      <?= $f_dep_from  ? '<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:6px;font-weight:600;">Deposit ≥ '.htmlspecialchars($f_dep_from).'</span>' : '' ?>
      <?= $f_dep_to    ? '<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:6px;font-weight:600;">Deposit ≤ '.htmlspecialchars($f_dep_to).'</span>' : '' ?>
      <?= $f_cash_from ? '<span style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:6px;font-weight:600;">Cash Recv ≥ '.htmlspecialchars($f_cash_from).'</span>' : '' ?>
      <?= $f_cash_to   ? '<span style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:6px;font-weight:600;">Cash Recv ≤ '.htmlspecialchars($f_cash_to).'</span>' : '' ?>
      <?= $f_del_from  ? '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:6px;font-weight:600;">Delivery ≥ '.htmlspecialchars($f_del_from).'</span>' : '' ?>
      <?= $f_del_to    ? '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:6px;font-weight:600;">Delivery ≤ '.htmlspecialchars($f_del_to).'</span>' : '' ?>
      <?= $f_dp        ? '<span style="background:#f3e8ff;color:#6d28d9;padding:2px 8px;border-radius:6px;font-weight:600;">Delivery Person: '.htmlspecialchars($f_dp).'</span>' : '' ?>
      <?= $f_collected_by ? '<span style="background:#fce7f3;color:#9d174d;padding:2px 8px;border-radius:6px;font-weight:600;">By: '.strtoupper(htmlspecialchars($f_collected_by)).'</span>' : '' ?>
      <?= $f_type      ? '<span style="background:#e0e7ff;color:#3730a3;padding:2px 8px;border-radius:6px;font-weight:600;">'.($f_type==='bo'?'Handed to BO':'Bank Deposit').'</span>' : '' ?>
      <a href="bank_deposit_summary_dp.php" style="color:#dc2626;font-weight:600;">✕ Clear all</a>
    </div>
    <?php endif; ?>
  </form>
</div>

<?php if (!$filter_submitted): ?>
<!-- ── PROMPT STATE (no filter applied yet) ── -->
<div class="prompt-box">
  <i class="fa-solid fa-filter"></i>
  <p><strong>Select filters above and click "Apply Filters"</strong><br>to load the Bank Deposit Summary report.</p>
</div>

<?php elseif (empty($raw_rows)): ?>
<!-- ── SUMMARY CARDS (zeroed) ── -->
<div class="summary-grid">
  <div class="sum-card cc"><div class="sum-card-label"><i class="fa-solid fa-user-tie" style="margin-right:4px;"></i>CC Total Collection</div><div class="sum-card-val"><span class="rs">Rs.</span>0.00</div></div>
  <div class="sum-card sr"><div class="sum-card-label"><i class="fa-solid fa-person-walking" style="margin-right:4px;"></i>SR Total Collection</div><div class="sum-card-val"><span class="rs">Rs.</span>0.00</div></div>
  <div class="sum-card"><div class="sum-card-label"><i class="fa-solid fa-building-columns" style="margin-right:4px;"></i>Total Bank Deposits</div><div class="sum-card-val" style="color:#15803d;"><span class="rs">Rs.</span>0.00</div></div>
  <div class="sum-card total"><div class="sum-card-label"><i class="fa-solid fa-sigma" style="margin-right:4px;"></i>Grand Total</div><div class="sum-card-val"><span class="rs">Rs.</span>0.00</div></div>
</div>
<div class="table-card">
  <div style="text-align:center;padding:60px 20px;color:#9ca3af;">
    <i class="fa-solid fa-chart-bar" style="font-size:36px;display:block;margin-bottom:14px;opacity:.3;"></i>
    <p>No data found for the selected filters.<br>Try adjusting the date ranges.</p>
  </div>
</div>

<?php else: ?>
<!-- ── SUMMARY CARDS ── -->
<div class="summary-grid">
  <div class="sum-card cc">
    <div class="sum-card-label"><i class="fa-solid fa-user-tie" style="margin-right:4px;"></i>CC Total Collection</div>
    <div class="sum-card-val"><span class="rs">Rs.</span><?= number_format($cc_total, 2) ?></div>
  </div>
  <div class="sum-card sr">
    <div class="sum-card-label"><i class="fa-solid fa-person-walking" style="margin-right:4px;"></i>SR Total Collection</div>
    <div class="sum-card-val"><span class="rs">Rs.</span><?= number_format($sr_total, 2) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-building-columns" style="margin-right:4px;"></i>Total Bank Deposits</div>
    <div class="sum-card-val" style="color:#15803d;"><span class="rs">Rs.</span><?= number_format($grand_bank, 2) ?></div>
  </div>
  <div class="sum-card total">
    <div class="sum-card-label"><i class="fa-solid fa-sigma" style="margin-right:4px;"></i>Grand Total</div>
    <div class="sum-card-val"><span class="rs">Rs.</span><?= number_format($grand_total, 2) ?></div>
  </div>
</div>

<?php
function renderSummaryTable(string $title, array $rows, string $col_by, string $filter_qs, string $f_dep_from, string $f_dep_to, string $f_cash_from, string $f_cash_to, string $f_del_from, string $f_del_to, string $f_type): void {
    $t_total = array_sum(array_column($rows,'total_amount'));
    $t_bo    = array_sum(array_column($rows,'bo_amount'));
    $t_bank  = array_sum(array_column($rows,'bank_amount'));
    $t_slips = array_sum(array_column($rows,'slip_count'));
    ?>
    <div class="table-card">
      <div class="table-toolbar">
        <div class="tbl-title"><?= htmlspecialchars($title) ?> <span class="pill"><?= count($rows) ?></span></div>
      </div>
      <div class="dt-wrap">
      <table class="data-table" id="tbl_<?= $col_by ?>">
        <thead>
          <tr>
            <th>#</th>
            <th>Delivery Person</th>
            <th>Collected By</th>
            <th class="tr">Total Collection (Rs.)</th>
            <th class="tr">BO Handover (Rs.)</th>
            <th class="tr">Slips (Rs.)</th>
            <th class="tc">No. of Slips</th>
          </tr>
        </thead>
        <tbody>
        <?php $i=1; foreach ($rows as $row):
            $cb_html = strtolower($row['collected_by']) === 'sr'
                ? '<span class="badge-sr">SR</span>'
                : '<span class="badge-cc">CC</span>';
            $qs = $filter_qs.'&col_by='.urlencode($row['collected_by']).'&dp='.urlencode($row['delivery_person'])
                 .'&dep_from='.urlencode($f_dep_from).'&dep_to='.urlencode($f_dep_to)
                 .'&cash_from='.urlencode($f_cash_from).'&cash_to='.urlencode($f_cash_to)
                 .'&del_from='.urlencode($f_del_from).'&del_to='.urlencode($f_del_to);
        ?>
          <tr>
            <td style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
            <td><span class="dp-badge"><?= htmlspecialchars($row['delivery_person']) ?></span></td>
            <td><?= $cb_html ?></td>
            <td class="tr">
              <span class="clickable-total" onclick="showDetails('<?= htmlspecialchars($row['delivery_person'],ENT_QUOTES) ?>','<?= htmlspecialchars($row['collected_by'],ENT_QUOTES) ?>','<?= htmlspecialchars($qs,ENT_QUOTES) ?>','all')">
                <span class="rs">Rs.</span><?= number_format(floatval($row['total_amount']),2) ?>
              </span>
            </td>
            <td class="tr">
              <?php if (floatval($row['bo_amount']) > 0): ?>
              <span class="clickable-total" onclick="showDetails('<?= htmlspecialchars($row['delivery_person'],ENT_QUOTES) ?>','<?= htmlspecialchars($row['collected_by'],ENT_QUOTES) ?>','<?= htmlspecialchars($qs.'&dep_type=bo',ENT_QUOTES) ?>','BO')">
                <span class="rs">Rs.</span><?= number_format(floatval($row['bo_amount']),2) ?>
              </span>
              <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
            </td>
            <td class="tr">
              <?php if (floatval($row['bank_amount']) > 0): ?>
              <span class="clickable-total" onclick="showDetails('<?= htmlspecialchars($row['delivery_person'],ENT_QUOTES) ?>','<?= htmlspecialchars($row['collected_by'],ENT_QUOTES) ?>','<?= htmlspecialchars($qs.'&dep_type=bank',ENT_QUOTES) ?>','Slips')">
                <span class="rs">Rs.</span><?= number_format(floatval($row['bank_amount']),2) ?>
              </span>
              <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
            </td>
            <td class="tc" style="font-weight:600;"><?= intval($row['dep_count']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="3" style="text-align:right;opacity:.7;font-size:11px;">TOTAL — <?= count($rows) ?> persons</td>
            <td class="tr"><span class="rs">Rs.</span><?= number_format($t_total,2) ?></td>
            <td class="tr"><span class="rs">Rs.</span><?= number_format($t_bo,2) ?></td>
            <td class="tr"><span class="rs">Rs.</span><?= number_format($t_bank,2) ?></td>
            <td class="tc"><?= array_sum(array_column($rows,'dep_count')) ?></td>
          </tr>
        </tfoot>
      </table>
      </div>
    </div>
    <?php
}

if ($f_collected_by !== 'sr' && !empty($cc_rows)):
    renderSummaryTable('CC Deposit Summary', array_values($cc_rows), 'cc', $filter_qs, $f_dep_from, $f_dep_to, $f_cash_from, $f_cash_to, $f_del_from, $f_del_to, $f_type);
endif;

if ($f_collected_by !== 'cc' && !empty($sr_rows_data)):
    renderSummaryTable('SR Collection & Deposit Summary', array_values($sr_rows_data), 'sr', $filter_qs, $f_dep_from, $f_dep_to, $f_cash_from, $f_cash_to, $f_del_from, $f_del_to, $f_type);
endif;

if ($f_collected_by === '' && (!empty($cc_rows) || !empty($sr_rows_data))):
?>
<!-- ── GRAND TOTAL CARD ── -->
<div class="table-card">
  <div class="table-toolbar"><div class="tbl-title">Grand Total</div></div>
  <table class="data-table">
    <thead>
      <tr>
        <th>Category</th>
        <th class="tr">Total (Rs.)</th>
        <th class="tr">BO Handover (Rs.)</th>
        <th class="tr">Bank Deposit (Rs.)</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><span class="badge-cc">CC</span> Cash Collector</td>
        <td class="tr" style="font-weight:700;"><span class="rs">Rs.</span><?= number_format($cc_total,2) ?></td>
        <td class="tr"><span class="rs">Rs.</span><?= number_format(array_sum(array_map(fn($r)=>floatval($r['bo_amount']),  array_values($cc_rows))),2) ?></td>
        <td class="tr"><span class="rs">Rs.</span><?= number_format(array_sum(array_map(fn($r)=>floatval($r['bank_amount']),array_values($cc_rows))),2) ?></td>
      </tr>
      <tr>
        <td><span class="badge-sr">SR</span> Sales Rep</td>
        <td class="tr" style="font-weight:700;"><span class="rs">Rs.</span><?= number_format($sr_total,2) ?></td>
        <td class="tr"><span class="rs">Rs.</span><?= number_format(array_sum(array_map(fn($r)=>floatval($r['bo_amount']),  array_values($sr_rows_data))),2) ?></td>
        <td class="tr"><span class="rs">Rs.</span><?= number_format(array_sum(array_map(fn($r)=>floatval($r['bank_amount']),array_values($sr_rows_data))),2) ?></td>
      </tr>
    </tbody>
    <tfoot>
      <tr>
        <td>GRAND TOTAL</td>
        <td class="tr"><span class="rs">Rs.</span><?= number_format($grand_total,2) ?></td>
        <td class="tr"><span class="rs">Rs.</span><?= number_format($grand_bo,2) ?></td>
        <td class="tr"><span class="rs">Rs.</span><?= number_format($grand_bank,2) ?></td>
      </tr>
    </tfoot>
  </table>
</div>
<?php endif; ?>

<?php endif; // end filter_submitted / empty check ?>

<!-- ═══════════════ DETAIL MODAL ═══════════════ -->
<div class="modal-backdrop" id="detailModal">
  <div class="modal-dialog">
    <div class="modal-hdr">
      <h3 id="detailModalTitle">Deposit Details</h3>
      <button class="modal-x" onclick="closeDetailModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" id="detailModalBody">
      <div class="loading-spinner"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading…</div>
    </div>
    <div class="modal-ftr">
      <button class="btn btn-secondary btn-sm" onclick="closeDetailModal()">Close</button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
$(function(){
  $('#fFilterDp').select2({placeholder:'— All Delivery Persons —', allowClear:true, width:'100%'});
});

/* ─── Detail Modal ─── */
function showDetails(dp, collectedBy, qs, label) {
  document.getElementById('detailModalTitle').textContent =
    'Details — Delivery Person: ' + dp + '  (' + (collectedBy||'').toUpperCase() + ')' + (label !== 'all' ? '  · ' + label : '');
  document.getElementById('detailModalBody').innerHTML =
    '<div class="loading-spinner"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading…</div>';
  document.getElementById('detailModal').classList.add('open');
  document.body.classList.add('modal-open');

  const url = 'bank_deposit_summary_dp.php?ajax=details&' + qs;
  fetch(url)
    .then(r => r.json())
    .then(rows => {
      if (!rows || rows.length === 0) {
        document.getElementById('detailModalBody').innerHTML =
          '<div style="text-align:center;padding:40px;color:#9ca3af;">No records found.</div>';
        return;
      }
      let total = 0;
      let html = `
      <table class="detail-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Deposit Date</th>
            <th>Cash Recv Date</th>
            <th>Delivery Date</th>
            <th>Type</th>
            <th>Bank / Employee</th>
            <th class="tr">Amount (Rs.)</th>
            <th>Remark</th>
          </tr>
        </thead>
        <tbody>`;
      rows.forEach((r, i) => {
        const isBO   = parseInt(r.handed_over_bo) === 1;
        const dest   = isBO ? escHtml(r.emp_name || '—') : escHtml((r.bank_label||'').replace(/^\s*\/\s*|\s*\/\s*$/g,'').trim() || '—');
        const typeHtml = isBO ? '<span class="badge-bo">BO</span>' : '<span class="badge-bank">Bank</span>';
        const amt    = parseFloat(r.person_amount);
        total += amt;
        const fmtAmt = amt.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
        const depDt  = fmtDate(r.deposit_date);
        const cashDt = fmtDate(r.cash_receive_date);
        const delDt  = fmtDate(r.delivery_date);
        html += `<tr>
          <td style="color:#9ca3af;font-size:11px;">${i+1}</td>
          <td>${depDt  ? '<span class="date-chip">'+depDt+'</span>'  : '<span style="color:#d1d5db;">—</span>'}</td>
          <td>${cashDt ? '<span class="date-chip">'+cashDt+'</span>' : '<span style="color:#d1d5db;">—</span>'}</td>
          <td>${delDt  ? '<span class="date-chip">'+delDt+'</span>'  : '<span style="color:#d1d5db;">—</span>'}</td>
          <td>${typeHtml}</td>
          <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${dest}</td>
          <td class="tr" style="font-weight:700;color:#1e40af;"><span class="rs">Rs.</span>${fmtAmt}</td>
          <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:#6b7280;">${escHtml(r.remark||'—')}</td>
        </tr>`;
      });
      html += `</tbody>
        <tfoot>
          <tr>
            <td colspan="6" style="text-align:right;font-size:11px;opacity:.7;">${rows.length} record${rows.length!==1?'s':''}</td>
            <td class="tr"><span class="rs">Rs.</span>${total.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
            <td></td>
          </tr>
        </tfoot>
      </table>`;
      document.getElementById('detailModalBody').innerHTML = html;
    })
    .catch(() => {
      document.getElementById('detailModalBody').innerHTML =
        '<div style="text-align:center;padding:40px;color:#dc2626;">Failed to load data.</div>';
    });
}

function closeDetailModal() {
  document.getElementById('detailModal').classList.remove('open');
  document.body.classList.remove('modal-open');
}
document.getElementById('detailModal').addEventListener('click', function(e){ if(e.target===this) closeDetailModal(); });
document.addEventListener('keydown', e => { if(e.key==='Escape') closeDetailModal(); });

/* ─── Export Excel ─── */
function exportExcel() {
  const tables = document.querySelectorAll('.data-table');
  if (!tables.length) { showToast('No data to export.','err'); return; }

  const wb = XLSX.utils.book_new();
  tables.forEach((tbl, idx) => {
    const title = tbl.closest('.table-card')?.querySelector('.tbl-title')?.textContent?.trim() || ('Sheet'+(idx+1));
    const ws = XLSX.utils.table_to_sheet(tbl);
    XLSX.utils.book_append_sheet(wb, ws, title.substring(0,30));
  });

  const today = new Date();
  const fname = 'bank_deposit_summary_dp_'
    + today.getFullYear()
    + String(today.getMonth()+1).padStart(2,'0')
    + String(today.getDate()).padStart(2,'0')
    + '.xlsx';

  XLSX.writeFile(wb, fname);
  showToast('Exported successfully.','ok');
}

/* ─── Helpers ─── */
function fmtDate(s) {
  if (!s || s === '' || s === '0000-00-00' || s === null) return '';
  const d = new Date(s);
  if (isNaN(d.getTime()) || d.getFullYear() <= 0) return '';
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  return d.getDate().toString().padStart(2,'0')+' '+months[d.getMonth()]+' '+d.getFullYear();
}

function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function showToast(msg, type) {
  const t = document.getElementById('toast');
  t.className = type === 'ok' ? 'toast-ok' : 'toast-err';
  t.textContent = msg; t.style.display = 'block'; t.style.opacity = '1';
  clearTimeout(t._t);
  t._t = setTimeout(()=>{ t.style.opacity='0'; setTimeout(()=>t.style.display='none',300); }, 2800);
}
</script>

<?php include 'footer.php'; ?>
