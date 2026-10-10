<?php
ob_start();
include 'config.php';

/* ── filters ── */
$f_dep_date  = trim($_GET['dep_date']  ?? '');
$f_cash_date = trim($_GET['cash_date'] ?? '');
$f_del_date  = trim($_GET['del_date']  ?? '');

$filter_submitted = isset($_GET['dep_date']) || isset($_GET['cash_date']) || isset($_GET['del_date']);

/* ── AJAX: deposit detail popup ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'slip_details') {
    while (ob_get_level()) ob_end_clean();
    error_reporting(0);
    header('Content-Type: application/json');
    $dep_id = intval($_GET['dep_id'] ?? 0);
    if ($dep_id <= 0) { echo json_encode([]); exit; }

    $sql = "
        SELECT r.rep_code, r.amount, r.delivery_date,
               d.deposit_date, d.cash_receive_date, d.handed_over_bo, d.collected_by, d.remark,
               CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
                      COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,'')) AS bank_label,
               COALESCE(NULLIF(e.name_with_initials,''),e.employee_full_name) AS emp_name,
               (SELECT COUNT(*) FROM cc_cash_deposit_attachments a WHERE a.deposit_id=d.id) AS slip_count
        FROM cc_cash_deposit_reps r
        JOIN cc_cash_deposits d ON d.id = r.deposit_id
        LEFT JOIN company_bank_accounts cba ON cba.id=d.bank_account_id
        LEFT JOIN banks b ON b.bank_code=cba.bank_code
        LEFT JOIN bank_branches bb ON bb.bank_code=cba.bank_code AND bb.branch_code=cba.branch_code
        LEFT JOIN employees e ON e.id=d.employee_id
        WHERE d.id = $dep_id
        ORDER BY r.sort_order
    ";
    $res  = mysqli_query($conn, $sql);
    $rows = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    }
    echo json_encode($rows);
    exit;
}

/* ── main query ── */
$results     = [];
$grand_total = 0;
$grand_bo    = 0;

if ($filter_submitted) {
    $where_parts = ['1=1'];
    if ($f_dep_date)  $where_parts[] = "d.deposit_date = '".mysqli_real_escape_string($conn, $f_dep_date)."'";
    if ($f_cash_date) $where_parts[] = "d.cash_receive_date = '".mysqli_real_escape_string($conn, $f_cash_date)."'";
    if ($f_del_date)  $where_parts[] = "r.delivery_date = '".mysqli_real_escape_string($conn, $f_del_date)."'";
    $where_sql = implode(' AND ', $where_parts);

    /* ── Step 1: get all matching deposit+rep rows ── */
    $sql = "
        SELECT
            r.rep_code,
            d.collected_by,
            d.id            AS deposit_id,
            d.deposit_date,
            d.cash_receive_date,
            r.delivery_date,
            d.handed_over_bo,
            r.amount        AS rep_amount,
            d.remark,
            CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
                   COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,'')) AS bank_label,
            COALESCE(NULLIF(e.name_with_initials,''),e.employee_full_name) AS emp_name
        FROM cc_cash_deposit_reps r
        JOIN cc_cash_deposits d ON d.id = r.deposit_id
        LEFT JOIN company_bank_accounts cba ON cba.id=d.bank_account_id
        LEFT JOIN banks b ON b.bank_code=cba.bank_code
        LEFT JOIN bank_branches bb ON bb.bank_code=cba.bank_code AND bb.branch_code=cba.branch_code
        LEFT JOIN employees e ON e.id=d.employee_id
        WHERE $where_sql
        ORDER BY d.id ASC, r.sort_order ASC
    ";
    $res = mysqli_query($conn, $sql);

    /* ── Step 2: pre-compute full deposit totals (sum of all rep rows per deposit) ── */
    $deposit_totals = [];
    $dt_res = mysqli_query($conn, "SELECT deposit_id, SUM(amount) AS dep_total FROM cc_cash_deposit_reps GROUP BY deposit_id");
    if ($dt_res) {
        while ($dt = mysqli_fetch_assoc($dt_res))
            $deposit_totals[intval($dt['deposit_id'])] = floatval($dt['dep_total']);
    }

    /* ── Step 3: pivot
         Key  = rep_code|collected_by
         total    = sum of this rep's own amounts (for summary totals)
         bo_total = rep's BO portion
         slips[]  = one entry per DEPOSIT (deduped by deposit_id), amount = FULL deposit total
    ── */
    $rep_map = [];

    while ($row = mysqli_fetch_assoc($res)) {
        $rc     = $row['rep_code'];
        $cb     = strtolower($row['collected_by'] ?? '');
        $key    = $rc . '|' . $cb;
        $rep_amt = floatval($row['rep_amount']);
        $isBO   = intval($row['handed_over_bo']) === 1;
        $dep_id = intval($row['deposit_id']);

        if (!isset($rep_map[$key])) {
            $rep_map[$key] = [
                'rep_code'     => $rc,
                'collected_by' => $row['collected_by'],
                'total'        => 0,
                'bo_total'     => 0,
                'slips'        => [],       // one entry per unique deposit_id (bank only)
                '_seen'        => [],       // internal dedup
            ];
        }

        /* accumulate per-rep totals */
        $rep_map[$key]['total'] += $rep_amt;
        if ($isBO) {
            $rep_map[$key]['bo_total'] += $rep_amt;
        } else {
            /* bank deposit — add ONE slip column per deposit_id, using FULL deposit total */
            if (!in_array($dep_id, $rep_map[$key]['_seen'])) {
                $rep_map[$key]['_seen'][]  = $dep_id;
                $full_total = $deposit_totals[$dep_id] ?? $rep_amt;
                $rep_map[$key]['slips'][] = [
                    'deposit_id'       => $dep_id,
                    'amount'           => $full_total,   // full deposit total
                    'deposit_date'     => $row['deposit_date'],
                    'cash_receive_date'=> $row['cash_receive_date'],
                    'bank_label'       => $row['bank_label'],
                    'remark'           => $row['remark'],
                ];
            }
        }

        $grand_total += $rep_amt;
        if ($isBO) $grand_bo += $rep_amt;
    }

    /* clean up internal keys */
    foreach ($rep_map as &$r) unset($r['_seen']);
    unset($r);

    $results = array_values($rep_map);

    /* split CC / SR */
    $cc_rows = array_values(array_filter($results, fn($r) => strtolower($r['collected_by']) === 'cc'));
    $sr_rows = array_values(array_filter($results, fn($r) => strtolower($r['collected_by']) === 'sr'));

    $cc_total    = array_sum(array_column($cc_rows, 'total'));
    $sr_total    = array_sum(array_column($sr_rows, 'total'));
    $cc_bo       = array_sum(array_column($cc_rows, 'bo_total'));
    $sr_bo       = array_sum(array_column($sr_rows, 'bo_total'));

    $cc_max_deps = 0;
    foreach ($cc_rows as $r) if (count($r['slips']) > $cc_max_deps) $cc_max_deps = count($r['slips']);
    $sr_max_deps = 0;
    foreach ($sr_rows as $r) if (count($r['slips']) > $sr_max_deps) $sr_max_deps = count($r['slips']);
}

include 'header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<style>
*{box-sizing:border-box;}
.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-success{background:#15803d;color:#fff;}.btn-success:hover{background:#166534;}
.btn-sm{padding:5px 12px;font-size:12px;}

.summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px;}
@media(max-width:700px){.summary-grid{grid-template-columns:1fr;}}
.sum-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.sum-card-label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;}
.sum-card-val{font-size:20px;font-weight:800;color:#1e40af;}
.sum-card.cc .sum-card-val{color:#065f46;}
.sum-card.sr .sum-card-val{color:#92400e;}
.sum-card.total .sum-card-val{color:#111827;}

.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:16px 20px;margin-bottom:18px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.filter-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-bottom:8px;}
.filter-row{display:grid;gap:10px;grid-template-columns:1fr 1fr 1fr auto;}
@media(max-width:700px){.filter-row{grid-template-columns:1fr 1fr;}}
.fg label{font-size:11.5px;font-weight:700;color:#374151;display:block;margin-bottom:4px;}
.fctrl{padding:7px 10px;border:1px solid #e0e0e0;border-radius:6px;font-size:12.5px;width:100%;font-family:inherit;background:#fff;color:#111;outline:none;}
.fctrl:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1);}

.prompt-box{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:60px 20px;text-align:center;color:#9ca3af;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.prompt-box i{font-size:40px;display:block;margin-bottom:14px;opacity:.3;}
.prompt-box p{margin:6px 0 0;font-size:14px;line-height:1.6;}

.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:24px;}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;border-bottom:1px solid #f0f0f0;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead th{padding:8px 8px;text-align:left;font-weight:700;font-size:11px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;}
.data-table thead th.tr{text-align:right;}
.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr:hover td{background:#f0f7ff;}
.data-table td{padding:7px 8px;color:#374151;vertical-align:middle;background:#fff;}
.data-table td.tr{text-align:right;}
.data-table td.tc{text-align:center;}
.data-table tfoot td{padding:8px 8px;font-weight:800;font-size:12.5px;background:#0f172a;color:#e2e8f0;}
.data-table tfoot td.tr{text-align:right;}

.dep-header{background:#312e81!important;color:#c7d2fe!important;font-size:10px!important;text-align:center!important;border-left:2px solid #4338ca!important;}
.dep-cell{text-align:right;font-weight:600;color:#1e40af;border-left:2px solid #eef2ff;cursor:pointer;white-space:nowrap;padding:6px 10px!important;transition:background .12s;}
.dep-cell:hover{background:#dbeafe!important;}
.dep-cell .dep-label{display:block;font-size:9px;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:.03em;margin-bottom:2px;}
.dep-cell .dep-amt{font-size:12px;font-weight:700;}
.dep-cell-empty{text-align:center;color:#e5e7eb;border-left:2px solid #f3f4f6;padding:6px 10px!important;}

.badge-sr{background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;}
.badge-cc{background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;}
.rep-code-badge{background:#dbeafe;color:#1e40af;border-radius:5px;padding:2px 7px;font-size:11px;font-weight:700;}
.pill{padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;background:#dbeafe;color:#1e40af;}

/* modal */
.modal-backdrop{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;padding:16px;}
.modal-backdrop.open{display:flex;}
body.modal-open{overflow:hidden;}
.modal-dialog{background:#fff;border-radius:12px;width:100%;max-width:700px;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 24px 70px rgba(0,0,0,.3);overflow:hidden;}
.modal-hdr{padding:16px 22px;border-bottom:1px solid #e5e5e5;background:#fafafa;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.modal-hdr h3{font-size:15px;font-weight:700;color:#1f2937;margin:0;}
.modal-x{width:30px;height:30px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#6b7280;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;}
.modal-x:hover{background:#f5f5f5;color:#111;}
.modal-body{overflow-y:auto;flex:1;padding:20px 22px;}
.modal-ftr{padding:11px 22px;border-top:1px solid #e5e5e5;background:#fafafa;display:flex;justify-content:flex-end;}
.detail-meta{display:grid;grid-template-columns:1fr 1fr;gap:8px 20px;margin-bottom:16px;font-size:12.5px;}
.detail-meta-item{display:flex;flex-direction:column;gap:2px;}
.detail-meta-item .lbl{font-size:10.5px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em;}
.detail-meta-item .val{font-size:12.5px;color:#1f2937;font-weight:600;}
.detail-table{width:100%;border-collapse:collapse;font-size:12px;}
.detail-table thead th{padding:7px 8px;text-align:left;font-weight:700;font-size:11px;background:#1e1b4b;color:#e0e7ff;white-space:nowrap;}
.detail-table thead th.tr{text-align:right;}
.detail-table tbody tr{border-bottom:1px solid #f3f4f6;}
.detail-table tbody tr:hover td{background:#f0f7ff;}
.detail-table td{padding:7px 8px;color:#374151;vertical-align:middle;}
.detail-table td.tr{text-align:right;}
.detail-table tfoot td{padding:7px 8px;font-weight:800;font-size:12px;background:#0f172a;color:#e2e8f0;}
.detail-table tfoot td.tr{text-align:right;}
.date-chip{background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;display:inline-block;padding:2px 7px;border-radius:6px;font-size:11px;font-weight:600;}
.section-lbl{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e5e7eb;padding-bottom:5px;margin:0 0 12px;}

#toast{position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:100020;padding:12px 24px;border-radius:9px;font-size:13px;font-weight:700;font-family:inherit;box-shadow:0 8px 28px rgba(0,0,0,.2);display:none;pointer-events:none;}
.toast-ok{background:#166534;color:#fff;}.toast-err{background:#dc2626;color:#fff;}
</style>

<!-- PAGE HEADER -->
<div class="ph-row">
  <div>
    <h2 class="page-title" style="margin:0 0 4px;">Slip-Wise Summary</h2>
    <p style="margin:0;font-size:13px;color:#6b7280;">Rep-wise breakdown — each column = one full bank slip entry.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <?php if ($filter_submitted && !empty($results)): ?>
    <button class="btn btn-success btn-sm" onclick="exportExcel()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
    <button class="btn btn-primary btn-sm" onclick="openPrintReport()"><i class="fa-solid fa-print"></i> Print Report</button>
    <?php endif; ?>
    <a href="cc_cash_deposit.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to Deposits</a>
  </div>
</div>

<!-- FILTERS -->
<div class="filter-card">
  <form method="GET" id="filterForm">
    <div class="filter-section-title"><i class="fa-solid fa-calendar-day" style="margin-right:5px;"></i>Select Date</div>
    <div class="filter-row">
      <div class="fg">
        <label>Deposit Date</label>
        <input type="date" name="dep_date" class="fctrl" value="<?= htmlspecialchars($f_dep_date) ?>">
      </div>
      <div class="fg">
        <label>Cash Receive Date</label>
        <input type="date" name="cash_date" class="fctrl" value="<?= htmlspecialchars($f_cash_date) ?>">
      </div>
      <div class="fg">
        <label>Delivery Date</label>
        <input type="date" name="del_date" class="fctrl" value="<?= htmlspecialchars($f_del_date) ?>">
      </div>
      <div class="fg" style="display:flex;align-items:flex-end;gap:8px;">
        <button type="submit" class="btn btn-primary" style="flex:1;height:35px;font-size:12.5px;"><i class="fa-solid fa-magnifying-glass"></i> Load Report</button>
        <a href="bank_deposit_summary_slip.php" class="btn btn-secondary" style="height:35px;" title="Clear"><i class="fa-solid fa-rotate-left"></i></a>
      </div>
    </div>
    <?php if($f_dep_date||$f_cash_date||$f_del_date): ?>
    <div style="margin-top:10px;font-size:11px;color:#6b7280;display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
      <span style="font-weight:700;">Active filters:</span>
      <?= $f_dep_date  ? '<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:6px;font-weight:600;">Deposit: '.htmlspecialchars($f_dep_date).'</span>' : '' ?>
      <?= $f_cash_date ? '<span style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:6px;font-weight:600;">Cash Recv: '.htmlspecialchars($f_cash_date).'</span>' : '' ?>
      <?= $f_del_date  ? '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:6px;font-weight:600;">Delivery: '.htmlspecialchars($f_del_date).'</span>' : '' ?>
      <a href="bank_deposit_summary_slip.php" style="color:#dc2626;font-weight:600;">&#x2715; Clear all</a>
    </div>
    <?php endif; ?>
  </form>
</div>

<?php if (!$filter_submitted): ?>
<div class="prompt-box">
  <i class="fa-solid fa-receipt"></i>
  <p><strong>Select a date above and click "Load Report"</strong><br>to view the slip-wise summary.</p>
</div>

<?php elseif (empty($results)): ?>
<div class="summary-grid">
  <div class="sum-card cc"><div class="sum-card-label"><i class="fa-solid fa-user-tie" style="margin-right:4px;"></i>CC Total</div><div class="sum-card-val">0.00</div></div>
  <div class="sum-card sr"><div class="sum-card-label"><i class="fa-solid fa-person-walking" style="margin-right:4px;"></i>SR Total</div><div class="sum-card-val">0.00</div></div>
  <div class="sum-card total"><div class="sum-card-label"><i class="fa-solid fa-sigma" style="margin-right:4px;"></i>Grand Total</div><div class="sum-card-val">0.00</div></div>
</div>
<div class="table-card">
  <div style="text-align:center;padding:60px 20px;color:#9ca3af;">
    <i class="fa-solid fa-receipt" style="font-size:36px;display:block;margin-bottom:14px;opacity:.3;"></i>
    <p>No slips found for the selected date.<br>Try a different date.</p>
  </div>
</div>

<?php else: ?>
<!-- SUMMARY CARDS -->
<div class="summary-grid">
  <div class="sum-card cc">
    <div class="sum-card-label"><i class="fa-solid fa-user-tie" style="margin-right:4px;"></i>CC Total Collection</div>
    <div class="sum-card-val"><?= number_format($cc_total, 2) ?></div>
  </div>
  <div class="sum-card sr">
    <div class="sum-card-label"><i class="fa-solid fa-person-walking" style="margin-right:4px;"></i>SR Total Collection</div>
    <div class="sum-card-val"><?= number_format($sr_total, 2) ?></div>
  </div>
  <div class="sum-card total">
    <div class="sum-card-label"><i class="fa-solid fa-sigma" style="margin-right:4px;"></i>Grand Total</div>
    <div class="sum-card-val"><?= number_format($grand_total, 2) ?></div>
  </div>
</div>

<?php
/* ── table renderer ── */
function renderDepositTable(string $title, array $rows, int $max_deps, string $tbl_id): void {
    if (empty($rows)) return;
    $t_total = array_sum(array_column($rows, 'total'));
    $t_bo    = array_sum(array_column($rows, 'bo_total'));
?>
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title"><?= htmlspecialchars($title) ?> <span class="pill"><?= count($rows) ?> reps</span></div>
  </div>
  <div class="dt-wrap">
    <table class="data-table" id="<?= $tbl_id ?>">
      <thead>
        <tr>
          <th>#</th>
          <th>Rep Code</th>
          <th>Deposited By</th>
          <th class="tr">Total</th>
          <th class="tr">BO Handover</th>
          <?php for ($d = 1; $d <= $max_deps; $d++): ?>
          <th class="dep-header">Slip <?= $d ?></th>
          <?php endfor; ?>
        </tr>
      </thead>
      <tbody>
      <?php $i = 1; foreach ($rows as $rep):
          $cb      = strtolower($rep['collected_by'] ?? '');
          $cb_html = $cb === 'sr'
              ? '<span class="badge-sr">SR</span>'
              : '<span class="badge-cc">CC</span>';
      ?>
        <tr>
          <td style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
          <td><span class="rep-code-badge"><?= htmlspecialchars($rep['rep_code']) ?></span></td>
          <td><?= $cb_html ?></td>
          <td class="tr" style="font-weight:700;"><?= number_format($rep['total'], 2) ?></td>
          <td class="tr">
            <?php if ($rep['bo_total'] > 0): ?>
              <span style="font-weight:700;color:#5b21b6;"><?= number_format($rep['bo_total'], 2) ?></span>
            <?php else: ?>
              <span style="color:#d1d5db;">—</span>
            <?php endif; ?>
          </td>
          <?php for ($d = 0; $d < $max_deps; $d++): ?>
            <?php if (isset($rep['slips'][$d])):
                $slip   = $rep['slips'][$d];
                $amt    = floatval($slip['amount']);
                $dep_id = intval($slip['deposit_id']);
            ?>
            <td class="dep-cell" onclick="showDepDetail(<?= $dep_id ?>)">
              <span class="dep-label">Slip <?= $d + 1 ?></span>
              <span class="dep-amt"><?= number_format($amt, 2) ?></span>
            </td>
            <?php else: ?>
            <td class="dep-cell-empty">—</td>
            <?php endif; ?>
          <?php endfor; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3" style="text-align:right;opacity:.7;font-size:11px;">TOTAL — <?= count($rows) ?> reps</td>
          <td class="tr"><?= number_format($t_total, 2) ?></td>
          <td class="tr"><?= number_format($t_bo, 2) ?></td>
          <?php for ($d = 0; $d < $max_deps; $d++):
              $col_total = 0;
              $seen_deps_ft = [];
              foreach ($rows as $rep) {
                  if (isset($rep['slips'][$d])) {
                      $did = intval($rep['slips'][$d]['deposit_id']);
                      /* each deposit total counted once in footer */
                      if (!in_array($did, $seen_deps_ft)) {
                          $seen_deps_ft[] = $did;
                          $col_total += floatval($rep['slips'][$d]['amount']);
                      }
                  }
              }
          ?>
          <td class="tr" style="border-left:2px solid #1e3a5f;"><?= number_format($col_total, 2) ?></td>
          <?php endfor; ?>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php } ?>

<?php if (!empty($cc_rows)): renderDepositTable('CC Slip Summary', $cc_rows, $cc_max_deps, 'tbl_cc'); endif; ?>
<?php if (!empty($sr_rows)): renderDepositTable('SR Collection & Slip Summary', $sr_rows, $sr_max_deps, 'tbl_sr'); endif; ?>

<!-- TOTALS SUMMARY -->
<div class="table-card">
  <div class="table-toolbar"><div class="tbl-title">Totals Summary</div></div>
  <table class="data-table" id="tbl_totals">
    <thead>
      <tr>
        <th>Category</th>
        <th class="tr">Total Collection</th>
        <th class="tr">BO Handover</th>
        <th class="tr">Bank Slips</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><span class="badge-cc">CC</span>&nbsp; Cash Collector</td>
        <td class="tr" style="font-weight:700;"><?= number_format($cc_total, 2) ?></td>
        <td class="tr"><?= number_format($cc_bo, 2) ?></td>
        <td class="tr"><?= number_format($cc_total - $cc_bo, 2) ?></td>
      </tr>
      <tr>
        <td><span class="badge-sr">SR</span>&nbsp; Sales Rep</td>
        <td class="tr" style="font-weight:700;"><?= number_format($sr_total, 2) ?></td>
        <td class="tr"><?= number_format($sr_bo, 2) ?></td>
        <td class="tr"><?= number_format($sr_total - $sr_bo, 2) ?></td>
      </tr>
    </tbody>
    <tfoot>
      <tr>
        <td>GRAND TOTAL</td>
        <td class="tr"><?= number_format($grand_total, 2) ?></td>
        <td class="tr"><?= number_format($grand_bo, 2) ?></td>
        <td class="tr"><?= number_format($grand_total - $grand_bo, 2) ?></td>
      </tr>
    </tfoot>
  </table>
</div>
<?php endif; ?>

<!-- SLIP DETAIL MODAL -->
<div class="modal-backdrop" id="depModal">
  <div class="modal-dialog">
    <div class="modal-hdr">
      <h3 id="depModalTitle">Slip Details</h3>
      <button class="modal-x" onclick="closeDepModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" id="depModalBody">
      <div style="text-align:center;padding:40px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading…</div>
    </div>
    <div class="modal-ftr">
      <button class="btn btn-secondary btn-sm" onclick="closeDepModal()">Close</button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
var AJAX_URL = '<?= htmlspecialchars($_SERVER['PHP_SELF'] ?? basename(__FILE__)) ?>';

/* ── slip detail modal ── */
function showDepDetail(depId) {
  document.getElementById('depModalTitle').textContent = 'Slip #' + depId;
  document.getElementById('depModalBody').innerHTML =
    '<div style="text-align:center;padding:40px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading…</div>';
  document.getElementById('depModal').classList.add('open');
  document.body.classList.add('modal-open');

  fetch(AJAX_URL + '?ajax=slip_details&dep_id=' + depId)
    .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
    .then(text => {
      let rows;
      try { rows = JSON.parse(text); }
      catch(e) {
        document.getElementById('depModalBody').innerHTML =
          '<div style="color:#dc2626;padding:20px;">Invalid server response.</div>';
        return;
      }
      if (!rows || rows.length === 0) {
        document.getElementById('depModalBody').innerHTML =
          '<div style="text-align:center;padding:40px;color:#9ca3af;">No details found.</div>';
        return;
      }
      const r0 = rows[0];
      document.getElementById('depModalTitle').textContent = 'Slip #' + depId + ' — ' + escHtml(r0.bank_label || '').replace(/^\s*\/\s*|\s*\/\s*$/g,'').trim();

      /* meta info */
      let html = '<div class="detail-meta">';
      html += '<div class="detail-meta-item"><span class="lbl">Deposit Date</span><span class="val">' + (fmtDate(r0.deposit_date) ? '<span class="date-chip">'+fmtDate(r0.deposit_date)+'</span>' : '—') + '</span></div>';
      html += '<div class="detail-meta-item"><span class="lbl">Cash Receive Date</span><span class="val">' + (fmtDate(r0.cash_receive_date) ? '<span class="date-chip">'+fmtDate(r0.cash_receive_date)+'</span>' : '—') + '</span></div>';
      html += '<div class="detail-meta-item"><span class="lbl">Bank</span><span class="val">' + escHtml((r0.bank_label||'').replace(/^\s*\/\s*|\s*\/\s*$/g,'').trim()||'—') + '</span></div>';
      if (r0.remark) html += '<div class="detail-meta-item"><span class="lbl">Remark</span><span class="val">' + escHtml(r0.remark) + '</span></div>';
      html += '</div>';

      /* rep breakdown table */
      html += '<div class="section-lbl">Rep Breakdown</div>';
      let total = 0;
      html += '<table class="detail-table"><thead><tr><th>#</th><th>Rep Code</th><th>Delivery Date</th><th class="tr">Amount</th></tr></thead><tbody>';
      rows.forEach((r, i) => {
        const amt = parseFloat(r.amount);
        total += amt;
        html += '<tr>';
        html += '<td style="color:#9ca3af;font-size:11px;">' + (i+1) + '</td>';
        html += '<td><span class="rep-code-badge">' + escHtml(r.rep_code) + '</span></td>';
        html += '<td>' + (fmtDate(r.delivery_date) ? '<span class="date-chip">'+fmtDate(r.delivery_date)+'</span>' : '<span style="color:#d1d5db;">—</span>') + '</td>';
        html += '<td class="tr" style="font-weight:700;color:#1e40af;">' + amt.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) + '</td>';
        html += '</tr>';
      });
      html += '</tbody><tfoot><tr>';
      html += '<td colspan="3" style="text-align:right;font-size:11px;opacity:.7;">' + rows.length + ' rep(s)</td>';
      html += '<td class="tr">' + total.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) + '</td>';
      html += '</tr></tfoot></table>';

      document.getElementById('depModalBody').innerHTML = html;
    })
    .catch(err => {
      document.getElementById('depModalBody').innerHTML =
        '<div style="text-align:center;padding:40px;color:#dc2626;">Failed to load.<br><small style="color:#9ca3af;">' + escHtml(err.message||'') + '</small></div>';
    });
}

function closeDepModal() {
  document.getElementById('depModal').classList.remove('open');
  document.body.classList.remove('modal-open');
}
document.getElementById('depModal').addEventListener('click', function(e){ if(e.target===this) closeDepModal(); });
document.addEventListener('keydown', e => { if(e.key==='Escape') closeDepModal(); });

/* ── export excel ── */
function exportExcel() {
  const tables = document.querySelectorAll('.data-table');
  if (!tables.length) { showToast('No data to export.','err'); return; }
  const wb = XLSX.utils.book_new();
  tables.forEach(tbl => {
    const id = tbl.id || 'Sheet';
    let name = id === 'tbl_cc' ? 'CC Summary' : id === 'tbl_sr' ? 'SR Summary' : 'Totals';
    const ws = XLSX.utils.table_to_sheet(tbl);
    XLSX.utils.book_append_sheet(wb, ws, name.substring(0,30));
  });
  const today = new Date();
  const fname = 'slip_wise_summary_' + today.getFullYear()
    + String(today.getMonth()+1).padStart(2,'0')
    + String(today.getDate()).padStart(2,'0') + '.xlsx';
  XLSX.writeFile(wb, fname);
  showToast('Exported successfully.','ok');
}

function openPrintReport() {
  const params = new URLSearchParams(window.location.search);
  window.open('slip_wise_print.php?' + params.toString(), '_blank');
}

/* ── helpers ── */
function fmtDate(s) {
  if (!s || s === '' || s === '0000-00-00' || s === null) return '';
  const d = new Date(s);
  if (isNaN(d.getTime()) || d.getFullYear() <= 0) return '';
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  return String(d.getDate()).padStart(2,'0') + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
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