<?php
include 'config.php';
include 'header.php';

/* ══════════════════════════════════════════
   DETECT WHICH DATE COLUMN field_summary USES
══════════════════════════════════════════ */
$date_col = 'delivery_date';
$chk = mysqli_query($conn, "SHOW COLUMNS FROM field_summary LIKE 'delivery_date'");
if (!$chk || mysqli_num_rows($chk) === 0) {
    $chk2 = mysqli_query($conn, "SHOW COLUMNS FROM field_summary LIKE 'visit_date'");
    if ($chk2 && mysqli_num_rows($chk2) > 0) {
        $date_col = 'visit_date';
    } else {
        $chk3 = mysqli_query($conn, "SHOW COLUMNS FROM field_summary LIKE 'summary_date'");
        if ($chk3 && mysqli_num_rows($chk3) > 0) $date_col = 'summary_date';
    }
}

/* ── safety net: make sure se_charges exists (in case this page runs before fs_se.php ever did) ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS se_charges (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  field_summary_id        INT           NOT NULL,
  field_summary_detail_id INT           NOT NULL,
  reason                  VARCHAR(100)  NOT NULL,
  employee_id             INT           NULL,
  employee_code           VARCHAR(50)   NULL,
  employee_name           VARCHAR(255)  NULL,
  employee_role           VARCHAR(150)  NULL,
  amount_employee         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount_company          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  remarks                 TEXT          NULL,
  mark_recreate_invoice   TINYINT(1)    NOT NULL DEFAULT 0,
  created_at              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_fs  (field_summary_id),
  INDEX idx_det (field_summary_detail_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ══════════════════════════════════════════
   FILTERS
══════════════════════════════════════════ */
$today          = date('Y-m-d');
$first_of_month = date('Y-m-01');
$date_from      = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : $first_of_month;
$date_to        = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? $_GET['date_to']   : $today;
$reason_filter  = isset($_GET['reason'])    ? trim($_GET['reason'])    : '';
$emp_filter     = isset($_GET['employee'])  ? trim($_GET['employee'])  : '';
$fs_code_filter = isset($_GET['fs_code'])   ? trim($_GET['fs_code'])   : '';
$recreate_only  = isset($_GET['recreate_only']) ? intval($_GET['recreate_only']) : 0;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = $first_of_month;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = $today;
if ($date_from > $date_to) { $tmp = $date_from; $date_from = $date_to; $date_to = $tmp; }

$date_from_esc = mysqli_real_escape_string($conn, $date_from);
$date_to_esc   = mysqli_real_escape_string($conn, $date_to);

$where = ["fs.$date_col BETWEEN '$date_from_esc' AND '$date_to_esc'"];
if ($reason_filter !== '')  $where[] = "sc.reason = '".mysqli_real_escape_string($conn,$reason_filter)."'";
if ($emp_filter !== '')     $where[] = "sc.employee_name LIKE '%".mysqli_real_escape_string($conn,$emp_filter)."%'";
if ($fs_code_filter !== '') $where[] = "fs.field_summary_code LIKE '%".mysqli_real_escape_string($conn,$fs_code_filter)."%'";
if ($recreate_only)         $where[] = "sc.mark_recreate_invoice = 1";
$where_sql = implode(' AND ', $where);

/* ══════════════════════════════════════════
   MAIN QUERY — every charge in range, with invoice context
══════════════════════════════════════════ */
$sql = "SELECT sc.*, fs.field_summary_code, fs.$date_col AS delivery_date, fs.route, fs.sr_code,
               d.invoice_num, d.t_code,
               COALESCE(NULLIF(d.customer_name,''), c.shop_name, d.invoice_num) AS customer_name,
               d.adjust_net_value, d.ikea_value, d.short_excess
        FROM se_charges sc
        JOIN field_summary_details d ON d.id = sc.field_summary_detail_id
        JOIN field_summary fs        ON fs.id = sc.field_summary_id
        LEFT JOIN customers c        ON c.t_code = d.t_code
        WHERE $where_sql
        ORDER BY fs.$date_col DESC, sc.created_at DESC";

$result = mysqli_query($conn, $sql);

$charges = [];
if ($result) while ($row = mysqli_fetch_assoc($result)) $charges[] = $row;

/* ══════════════════════════════════════════
   AGGREGATIONS
══════════════════════════════════════════ */

/* — Employee-wise summary — */
$by_employee = [];   // key: employee_name
foreach ($charges as $c) {
    $emp_key   = $c['employee_name'] !== '' && $c['employee_name'] !== null ? $c['employee_name'] : '— No Employee (Company Only) —';
    $emp_role  = $c['employee_role'] ?: '';
    if (!isset($by_employee[$emp_key])) {
        $by_employee[$emp_key] = [
            'employee_name'   => $emp_key,
            'employee_role'   => $emp_role,
            'count'           => 0,
            'amount_employee' => 0.0,
            'amount_company'  => 0.0,
            'reasons'         => [],   // reason => count
        ];
    }
    $by_employee[$emp_key]['count']++;
    $by_employee[$emp_key]['amount_employee'] += floatval($c['amount_employee']);
    $by_employee[$emp_key]['amount_company']  += floatval($c['amount_company']);
    if (!isset($by_employee[$emp_key]['reasons'][$c['reason']])) $by_employee[$emp_key]['reasons'][$c['reason']] = 0;
    $by_employee[$emp_key]['reasons'][$c['reason']]++;
}
uasort($by_employee, function($a,$b){ return ($b['amount_employee']+$b['amount_company']) <=> ($a['amount_employee']+$a['amount_company']); });

/* — Reason-wise summary (the "final report") — */
$by_reason = [];   // reason => ['count'=>, 'amount_employee'=>, 'amount_company'=>]
foreach ($charges as $c) {
    $rs = $c['reason'];
    if (!isset($by_reason[$rs])) $by_reason[$rs] = ['count'=>0,'amount_employee'=>0.0,'amount_company'=>0.0];
    $by_reason[$rs]['count']++;
    $by_reason[$rs]['amount_employee'] += floatval($c['amount_employee']);
    $by_reason[$rs]['amount_company']  += floatval($c['amount_company']);
}
krsort($by_reason);

/* — Short vs Excess counts, based on the underlying invoice rows behind these charges (distinct detail ids) — */
$seen_detail_ids = [];
$short_count = 0; $excess_count = 0; $short_total = 0.0; $excess_total = 0.0;
$recreate_flag_count = 0;
$grand_amount_employee = 0.0; $grand_amount_company = 0.0;
foreach ($charges as $c) {
    $grand_amount_employee += floatval($c['amount_employee']);
    $grand_amount_company  += floatval($c['amount_company']);
    if (intval($c['mark_recreate_invoice']) === 1) $recreate_flag_count++;

    $did = intval($c['field_summary_detail_id']);
    if (!isset($seen_detail_ids[$did])) {
        $seen_detail_ids[$did] = true;
        $se = floatval($c['short_excess']);
        if ($se < 0) { $short_count++;  $short_total  += $se; }
        if ($se > 0) { $excess_count++; $excess_total += $se; }
    }
}
$grand_total_amount = $grand_amount_employee + $grand_amount_company;
$total_charges      = count($charges);
$distinct_invoices  = count($seen_detail_ids);

/* ══════════════════════════════════════════
   CSV EXPORT (detailed rows)
══════════════════════════════════════════ */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fs_shortage_charge_report_'.$date_from.'_to_'.$date_to.'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Delivery Date','Field Summary Code','Route','SR Code','Invoice','T-Code','Customer',
                   'Final B.V','Ikea Value','Short/Excess','Reason','Employee','Employee Role',
                   'Charged to Employee','Absorbed by Company','Recreate Invoice','Remarks','Recorded On']);
    foreach ($charges as $c) {
        fputcsv($out, [
            date('Y-m-d', strtotime($c['delivery_date'])),
            $c['field_summary_code'], $c['route'], $c['sr_code'],
            $c['invoice_num'], $c['t_code'], $c['customer_name'],
            number_format(floatval($c['adjust_net_value']),2,'.',''),
            number_format(floatval($c['ikea_value']),2,'.',''),
            number_format(floatval($c['short_excess']),2,'.',''),
            $c['reason'], $c['employee_name'], $c['employee_role'],
            number_format(floatval($c['amount_employee']),2,'.',''),
            number_format(floatval($c['amount_company']),2,'.',''),
            intval($c['mark_recreate_invoice']) === 1 ? 'Yes' : 'No',
            $c['remarks'],
            date('Y-m-d H:i', strtotime($c['created_at'])),
        ]);
    }
    fclose($out);
    exit;
}

/* distinct reasons + employees for filter dropdowns */
$all_reasons = [];
$rr = mysqli_query($conn, "SELECT DISTINCT reason FROM se_charges WHERE reason <> '' ORDER BY reason");
if ($rr) while ($x = mysqli_fetch_assoc($rr)) $all_reasons[] = $x['reason'];

$all_employees = [];
$er = mysqli_query($conn, "SELECT DISTINCT employee_name FROM se_charges WHERE employee_name IS NOT NULL AND employee_name <> '' ORDER BY employee_name");
if ($er) while ($x = mysqli_fetch_assoc($er)) $all_employees[] = $x['employee_name'];
?>
<style>
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px;}
.card-title{font-size:16px;font-weight:600;margin-bottom:12px;color:#1f2937;}
.hint-text{font-size:12px;color:#6b7280;margin-bottom:16px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;font-family:'Inter',sans-serif;text-decoration:none;}
.btn-primary{background:#000;color:#fff;}.btn-primary:hover{background:#333;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-export{background:#166534;color:#fff;}.btn-export:hover{background:#14532d;}

.filter-bar{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;background:#fafafa;border:1px solid #e5e5e5;border-radius:8px;padding:14px 16px;margin-bottom:18px;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.04em;}
.fctrl{padding:8px 10px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;color:#333;background:#fff;outline:none;min-width:150px;}
.fctrl:focus{border-color:#000;}
.quick-range{display:flex;gap:6px;flex-wrap:wrap;margin-top:-6px;margin-bottom:14px;}
.quick-range button{padding:6px 10px;border:1px solid #e0e0e0;border-radius:5px;background:#fff;font-size:11px;font-weight:600;color:#374151;cursor:pointer;font-family:'Inter',sans-serif;}
.quick-range button:hover{background:#f0f0f0;}
.chk-fg{display:flex;align-items:center;gap:6px;padding-bottom:8px;}
.chk-fg label{font-size:12px;font-weight:600;color:#374151;text-transform:none;letter-spacing:0;cursor:pointer;}

/* ─── SUMMARY / FINAL REPORT CARDS ─── */
.summary-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px;}
.sum-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:14px 16px;}
.sum-card .lbl{font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.sum-card .val{font-size:20px;font-weight:800;}
.sum-card.rows .val{color:#374151;}
.sum-card.short .val{color:#dc2626;}
.sum-card.excess .val{color:#166534;}
.sum-card.net .val{color:#1e40af;}
.sum-card.emp .val{color:#166534;}
.sum-card.comp .val{color:#1e40af;}
.sum-card.recreate .val{color:#92400e;}

/* ─── reason-wise final report table ─── */
.reason-table{width:100%;border-collapse:collapse;font-size:13px;margin-top:6px;}
.reason-table th{background:#fef3c7;color:#78350f;padding:9px 10px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;}
.reason-table td{padding:9px 10px;border-bottom:1px solid #f0f0f0;}
.reason-table tr:last-child td{border-bottom:none;}
.reason-badge{display:inline-block;background:#f3e8ff;color:#5b21b6;padding:3px 10px;border-radius:12px;font-size:11.5px;font-weight:700;}
.reason-table tfoot td{font-weight:800;border-top:2px solid #e5e5e5;background:#fafafa;}

/* ─── employee-wise table ─── */
.emp-table{width:100%;border-collapse:collapse;font-size:13px;margin-top:6px;}
.emp-table th{background:#e0f2fe;color:#0369a1;padding:9px 10px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;}
.emp-table td{padding:9px 10px;border-bottom:1px solid #f0f0f0;vertical-align:top;}
.emp-table tr:last-child td{border-bottom:none;}
.emp-name{font-weight:700;color:#1f2937;}
.emp-role-badge{display:inline-block;background:#eef2ff;color:#3730a3;padding:2px 8px;border-radius:10px;font-size:10.5px;font-weight:700;margin-left:6px;}
.reason-pill{display:inline-block;background:#f3f4f6;color:#374151;padding:2px 8px;border-radius:10px;font-size:10.5px;font-weight:600;margin:2px 4px 2px 0;}
.emp-table tfoot td{font-weight:800;border-top:2px solid #e5e5e5;background:#fafafa;}

/* ─── detailed report table ─── */
.table-responsive{overflow-x:auto;overflow-y:auto;max-height:70vh;border:1px solid #e5e5e5;border-radius:8px;}
.data-table{width:100%;min-width:1100px;border-collapse:separate;border-spacing:0;font-size:12.5px;table-layout:fixed;}
.data-table thead th{padding:10px 8px;font-weight:700;color:#78350f;font-size:11px;white-space:nowrap;text-align:left;position:sticky;top:0;z-index:10;background:#fef3c7;box-shadow:0 2px 0 #fbbf24;overflow:hidden;text-overflow:ellipsis;}
.data-table tbody td{padding:7px 8px;color:#333;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.data-table tbody tr{border-bottom:1px solid #e8e8e8;}
.data-table tbody tr:hover{filter:brightness(.97);}
.data-table tbody tr.recreate-row{background:#fffbeb;}
.data-table tbody tr.recreate-row:hover{filter:brightness(.98);}

.se-badge{display:inline-block;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:700;white-space:nowrap;}
.se-excess{background:#f0fdf4;color:#166534;}.se-short{background:#fef2f2;color:#991b1b;}.se-neutral{background:#f5f5f5;color:#999;}
.recreate-tag{display:inline-flex;align-items:center;gap:4px;background:#f59e0b;color:#fff;border-radius:10px;padding:2px 8px;font-size:10px;font-weight:700;}

.no-data{text-align:center;padding:40px 20px;color:#9ca3af;font-size:14px;}
.no-data i{font-size:32px;display:block;margin-bottom:10px;opacity:.5;}

.section-title{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:700;color:#1f2937;margin-bottom:12px;}
.section-title i{color:#7c3aed;}

@media(max-width:900px){.summary-strip{grid-template-columns:1fr 1fr;}.filter-bar{flex-direction:column;align-items:stretch;}.fctrl{min-width:0;}}
</style>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <div>
      <h2 class="page-title"><i class="fa-solid fa-user-tag"></i> Field Summary Shortage Charge Report</h2>
      <p class="page-subtitle">Employee-wise short/excess charges, reason-wise totals, and a full detailed listing</p>
    </div>
    <div style="display:flex;gap:8px;">
      <a href="fs_se.php" class="btn btn-secondary"><i class="fa-solid fa-scale-balanced"></i> Short/Excess Report</a>
      <a href="field_summary_list.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>
  </div>
</div>

<div class="content-card">
  <h3 class="card-title">Filters</h3>
  <form method="GET" action="fs_shortage_charge_report.php" id="filterForm">
    <div class="filter-bar">
      <div class="fg">
        <label>Delivery Date From</label>
        <input type="date" name="date_from" id="dateFrom" class="fctrl" value="<?php echo htmlspecialchars($date_from); ?>">
      </div>
      <div class="fg">
        <label>Delivery Date To</label>
        <input type="date" name="date_to" id="dateTo" class="fctrl" value="<?php echo htmlspecialchars($date_to); ?>">
      </div>
      <div class="fg">
        <label>Reason</label>
        <select name="reason" class="fctrl">
          <option value="">All Reasons</option>
          <?php foreach ($all_reasons as $rs): ?>
          <option value="<?php echo htmlspecialchars($rs); ?>" <?php echo $reason_filter===$rs?'selected':''; ?>><?php echo htmlspecialchars($rs); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label>Employee</label>
        <select name="employee" class="fctrl">
          <option value="">All Employees</option>
          <?php foreach ($all_employees as $en): ?>
          <option value="<?php echo htmlspecialchars($en); ?>" <?php echo $emp_filter===$en?'selected':''; ?>><?php echo htmlspecialchars($en); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label>Field Summary Code</label>
        <input type="text" name="fs_code" class="fctrl" placeholder="e.g. FS-2026-..." value="<?php echo htmlspecialchars($fs_code_filter); ?>">
      </div>
      <div class="chk-fg">
        <input type="checkbox" name="recreate_only" id="recreateOnly" value="1" <?php echo $recreate_only?'checked':''; ?>>
        <label for="recreateOnly">Only "Recreate Invoice" marked</label>
      </div>
      <div class="fg">
        <label>&nbsp;</label>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Apply</button>
      </div>
      <div class="fg">
        <label>&nbsp;</label>
        <a class="btn btn-export" id="exportBtn" href="#"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
      </div>
    </div>
    <div class="quick-range">
      <button type="button" onclick="setRange(0,0)">Today</button>
      <button type="button" onclick="setRangeThisWeek()">This Week</button>
      <button type="button" onclick="setRangeThisMonth()">This Month</button>
      <button type="button" onclick="setRange(29,0)">Last 30 Days</button>
      <button type="button" onclick="setRange(89,0)">Last 90 Days</button>
    </div>
  </form>

  <!-- ═══ FINAL SUMMARY ═══ -->
  <div class="summary-strip">
    <div class="sum-card rows"><div class="lbl"><i class="fa-solid fa-receipt"></i> Total Charges</div><div class="val"><?php echo $total_charges; ?></div></div>
    <div class="sum-card short"><div class="lbl"><i class="fa-solid fa-arrow-down"></i> Short Invoices</div><div class="val"><?php echo $short_count; ?> <span style="font-size:12px;font-weight:600;">(<?php echo number_format($short_total,2); ?>)</span></div></div>
    <div class="sum-card excess"><div class="lbl"><i class="fa-solid fa-arrow-up"></i> Excess Invoices</div><div class="val">+<?php echo $excess_count; ?> <span style="font-size:12px;font-weight:600;">(+<?php echo number_format($excess_total,2); ?>)</span></div></div>
    <div class="sum-card recreate"><div class="lbl"><i class="fa-solid fa-rotate"></i> Marked to Recreate</div><div class="val"><?php echo $recreate_flag_count; ?></div></div>
    <div class="sum-card emp"><div class="lbl"><i class="fa-solid fa-user"></i> Charged to Employees</div><div class="val">Rs. <?php echo number_format($grand_amount_employee,2); ?></div></div>
    <div class="sum-card comp"><div class="lbl"><i class="fa-solid fa-building"></i> Absorbed by Company</div><div class="val">Rs. <?php echo number_format($grand_amount_company,2); ?></div></div>
    <div class="sum-card net"><div class="lbl"><i class="fa-solid fa-sigma"></i> Total Charged Amount</div><div class="val">Rs. <?php echo number_format($grand_total_amount,2); ?></div></div>
    <div class="sum-card rows"><div class="lbl"><i class="fa-solid fa-file-invoice"></i> Distinct Invoices Charged</div><div class="val"><?php echo $distinct_invoices; ?></div></div>
  </div>
</div>

<!-- ═══ FINAL REPORT — REASON WISE ═══ -->
<div class="content-card">
  <div class="section-title"><i class="fa-solid fa-list-check"></i> Final Report — By Reason</div>
  <?php if (empty($by_reason)): ?>
    <div class="no-data"><i class="fa-solid fa-inbox"></i>No charges found for the selected filters.</div>
  <?php else: ?>
  <table class="reason-table">
    <thead><tr>
      <th>Reason</th>
      <th style="text-align:right;">No. of Charges</th>
      <th style="text-align:right;">Charged to Employee (Rs.)</th>
      <th style="text-align:right;">Absorbed by Company (Rs.)</th>
      <th style="text-align:right;">Total (Rs.)</th>
    </tr></thead>
    <tbody>
    <?php foreach ($by_reason as $reason => $rdata): ?>
      <tr>
        <td><span class="reason-badge"><?php echo htmlspecialchars($reason); ?></span></td>
        <td style="text-align:right;"><?php echo $rdata['count']; ?></td>
        <td style="text-align:right;"><?php echo number_format($rdata['amount_employee'],2); ?></td>
        <td style="text-align:right;"><?php echo number_format($rdata['amount_company'],2); ?></td>
        <td style="text-align:right;"><?php echo number_format($rdata['amount_employee']+$rdata['amount_company'],2); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <td>TOTAL</td>
      <td style="text-align:right;"><?php echo $total_charges; ?></td>
      <td style="text-align:right;"><?php echo number_format($grand_amount_employee,2); ?></td>
      <td style="text-align:right;"><?php echo number_format($grand_amount_company,2); ?></td>
      <td style="text-align:right;"><?php echo number_format($grand_total_amount,2); ?></td>
    </tr></tfoot>
  </table>
  <?php endif; ?>
</div>

<!-- ═══ EMPLOYEE WISE REPORT ═══ -->
<div class="content-card">
  <div class="section-title"><i class="fa-solid fa-users"></i> Employee-Wise Short/Excess Charge</div>
  <?php if (empty($by_employee)): ?>
    <div class="no-data"><i class="fa-solid fa-inbox"></i>No employee charges found for the selected filters.</div>
  <?php else: ?>
  <table class="emp-table">
    <thead><tr>
      <th>Employee</th>
      <th style="text-align:right;">No. of Charges</th>
      <th style="text-align:right;">Charged to Employee (Rs.)</th>
      <th style="text-align:right;">Absorbed by Company (Rs.)</th>
      <th style="text-align:right;">Total (Rs.)</th>
      <th>Reasons</th>
    </tr></thead>
    <tbody>
    <?php foreach ($by_employee as $emp): ?>
      <tr>
        <td>
          <span class="emp-name"><?php echo htmlspecialchars($emp['employee_name']); ?></span>
          <?php if ($emp['employee_role']): ?><span class="emp-role-badge"><?php echo htmlspecialchars($emp['employee_role']); ?></span><?php endif; ?>
        </td>
        <td style="text-align:right;"><?php echo $emp['count']; ?></td>
        <td style="text-align:right;"><?php echo number_format($emp['amount_employee'],2); ?></td>
        <td style="text-align:right;"><?php echo number_format($emp['amount_company'],2); ?></td>
        <td style="text-align:right;font-weight:700;"><?php echo number_format($emp['amount_employee']+$emp['amount_company'],2); ?></td>
        <td>
          <?php foreach ($emp['reasons'] as $rs => $cnt): ?>
            <span class="reason-pill"><?php echo htmlspecialchars($rs); ?> × <?php echo $cnt; ?></span>
          <?php endforeach; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <td>TOTAL</td>
      <td style="text-align:right;"><?php echo $total_charges; ?></td>
      <td style="text-align:right;"><?php echo number_format($grand_amount_employee,2); ?></td>
      <td style="text-align:right;"><?php echo number_format($grand_amount_company,2); ?></td>
      <td style="text-align:right;"><?php echo number_format($grand_total_amount,2); ?></td>
      <td></td>
    </tr></tfoot>
  </table>
  <?php endif; ?>
</div>

<!-- ═══ DETAILED REPORT ═══ -->
<div class="content-card">
  <div class="section-title"><i class="fa-solid fa-table-list"></i> Detailed Report</div>
  <p class="hint-text">Every charge record in the selected range. Rows highlighted amber are marked <strong>To Recreate Invoice</strong>.</p>
  <div class="table-responsive">
  <table class="data-table">
    <colgroup>
      <col style="width:90px;"><col style="width:130px;"><col style="width:110px;"><col style="width:150px;">
      <col style="width:auto;"><col style="width:130px;"><col style="width:150px;"><col style="width:110px;">
      <col style="width:110px;"><col style="width:110px;"><col style="width:90px;"><col style="width:auto;">
    </colgroup>
    <thead><tr>
      <th>Delivery Date</th>
      <th>FS Code</th>
      <th>Invoice</th>
      <th>T-Code</th>
      <th>Customer</th>
      <th>Reason</th>
      <th>Employee</th>
      <th style="text-align:right;">Short/Excess</th>
      <th style="text-align:right;">To Employee</th>
      <th style="text-align:right;">To Company</th>
      <th>Recreate?</th>
      <th>Remarks</th>
    </tr></thead>
    <tbody>
    <?php if (empty($charges)): ?>
      <tr><td colspan="12"><div class="no-data"><i class="fa-solid fa-inbox"></i>No charges found for the selected filters.</div></td></tr>
    <?php else: foreach ($charges as $c):
        $se = floatval($c['short_excess']);
        $se_cls = $se>0?'se-excess':($se<0?'se-short':'se-neutral');
        $se_txt = $se==0?'—':(($se>0?'+':'').number_format($se,2));
        $is_recreate = intval($c['mark_recreate_invoice'])===1;
    ?>
      <tr class="<?php echo $is_recreate?'recreate-row':''; ?>">
        <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($c['delivery_date']))); ?></td>
        <td><?php echo htmlspecialchars($c['field_summary_code']); ?></td>
        <td><?php echo htmlspecialchars($c['invoice_num']); ?></td>
        <td><?php echo htmlspecialchars($c['t_code']); ?></td>
        <td><?php echo htmlspecialchars($c['customer_name']); ?></td>
        <td><span class="reason-badge"><?php echo htmlspecialchars($c['reason']); ?></span></td>
        <td><?php echo $c['employee_name'] ? htmlspecialchars($c['employee_name']) : '<span style="color:#999">—</span>'; ?></td>
        <td style="text-align:right;"><span class="se-badge <?php echo $se_cls; ?>"><?php echo $se_txt; ?></span></td>
        <td style="text-align:right;"><?php echo number_format(floatval($c['amount_employee']),2); ?></td>
        <td style="text-align:right;"><?php echo number_format(floatval($c['amount_company']),2); ?></td>
        <td><?php echo $is_recreate ? '<span class="recreate-tag"><i class="fa-solid fa-rotate"></i> Yes</span>' : '<span style="color:#999">No</span>'; ?></td>
        <td><?php echo htmlspecialchars($c['remarks']); ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
function setRange(daysAgoFrom, daysAgoTo){
    const today = new Date();
    const f = new Date(today); f.setDate(f.getDate()-daysAgoFrom);
    const t = new Date(today); t.setDate(t.getDate()-daysAgoTo);
    document.getElementById('dateFrom').value = f.toISOString().slice(0,10);
    document.getElementById('dateTo').value   = t.toISOString().slice(0,10);
}
function setRangeThisWeek(){
    const today = new Date();
    const day = today.getDay() === 0 ? 7 : today.getDay();
    const monday = new Date(today); monday.setDate(today.getDate() - day + 1);
    document.getElementById('dateFrom').value = monday.toISOString().slice(0,10);
    document.getElementById('dateTo').value   = today.toISOString().slice(0,10);
}
function setRangeThisMonth(){
    const today = new Date();
    const first = new Date(today.getFullYear(), today.getMonth(), 1);
    document.getElementById('dateFrom').value = first.toISOString().slice(0,10);
    document.getElementById('dateTo').value   = today.toISOString().slice(0,10);
}
function buildExportUrl(){
    const params = new URLSearchParams(new FormData(document.getElementById('filterForm')));
    params.set('export','csv');
    return 'fs_shortage_charge_report.php?' + params.toString();
}
document.getElementById('exportBtn').addEventListener('click', function(e){
    e.preventDefault();
    window.location.href = buildExportUrl();
});
</script>

<?php include 'footer.php'; ?>
