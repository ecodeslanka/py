<?php
include 'config.php';

/* ══════════════════════════════════════════════════════════════════
   U SHOP — STOCK & BALANCE DASHBOARD
   ------------------------------------------------------------------
   Secondary Invoice Total   : SUM(final_bill_amount) FROM secondary_invoice_import_details
                               joined to secondary_invoice_imports, grouped by each
                               batch's delivery_date (same base data as Import History /
                               secondary_import_history.php)
   Monthly Summary Total     : SUM(grand_total)  FROM monthly_invoice_imports
                               (same figure used on ushop_monthly_category_report.php)
   Stock Value               : Secondary Invoice Total − Monthly Summary Total
   Physical Stock Value      : SUM(available qty × cost price) FROM ushop_stock_history
                               joined to ushop_items (same figure as the
                               "Total Value (Cost)" card on ushop_stock_report.php)
   Diff                      : Stock Value − Physical Stock Value
   Category Paid / Balance   : same effective paid / balance logic as
                               ushop_monthly_category_report.php
   VISA Paid / Balance       : same reconciled-VISA logic as
                               ushop_monthly_category_report.php
   Total Paid                : Category Paid + VISA Paid
   Total Balance Due         : Category Balance + VISA Balance
   Investment                : Stock Value + Total Balance Due
   Management Fee            : total / paid / balance (same source table)
══════════════════════════════════════════════════════════════════ */

/* ─────────────────────────────────────────────────
   YEAR FILTER
───────────────────────────────────────────────── */
$current_year = (int)date('Y');

$avail_years = [];
$ay_res = mysqli_query($conn, "
    SELECT DISTINCT import_year AS y FROM monthly_invoice_imports
    UNION
    SELECT DISTINCT YEAR(delivery_date) AS y FROM secondary_invoice_imports
");
if ($ay_res) while ($r = mysqli_fetch_assoc($ay_res)) if ($r['y'] !== null) $avail_years[] = (int)$r['y'];
if (empty($avail_years)) $avail_years = [$current_year];

$year_options = array_unique(array_merge(range($current_year - 3, $current_year + 1), $avail_years));
rsort($year_options);

$is_all = (($_GET['year'] ?? '') === 'all');
$sel_year = (isset($_GET['year']) && $_GET['year'] !== '' && !$is_all) ? (int)$_GET['year'] : $current_year;

if ($is_all) {
    $selected_years = $avail_years;
} else {
    $selected_years = [$sel_year];
}
$selected_years = array_values(array_unique(array_map('intval', $selected_years)));
$years_in = implode(',', $selected_years);
if ($years_in === '') $years_in = (string)$current_year;

$year_label = $is_all ? 'All Years' : (string)$sel_year;

/* helper */
function q1val($conn, $sql, $col) {
    $res = mysqli_query($conn, $sql);
    if (!$res) return 0.0;
    $row = mysqli_fetch_assoc($res);
    return $row ? (float)($row[$col] ?? 0) : 0.0;
}

/* ─────────────────────────────────────────────────
   1) SECONDARY INVOICE TOTAL — base data from the Secondary Invoice
      import system (secondary_invoice_imports / secondary_invoice_
      import_details — the same data shown on Import History /
      secondary_import_history.php), summed by each batch's own
      delivery_date (not ushop_invoice_imports — that's the separate
      U Shop POS invoice import).
───────────────────────────────────────────────── */
$secondary_invoice_total = q1val($conn, "
    SELECT COALESCE(SUM(sd.final_bill_amount),0) AS tot
    FROM secondary_invoice_import_details sd
    JOIN secondary_invoice_imports si ON si.id = sd.import_id
    WHERE sd.status = 'imported'
      AND YEAR(si.delivery_date) IN ($years_in)
", 'tot');

/* ─────────────────────────────────────────────────
   2) MONTHLY INVOICE SUMMARY TOTAL (monthly_invoice_imports)
───────────────────────────────────────────────── */
$mi_row = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(grand_total),0) AS grand_total,
           COALESCE(SUM(total_visa),0)  AS total_visa
    FROM monthly_invoice_imports
    WHERE import_year IN ($years_in)
")) ?: ['grand_total' => 0, 'total_visa' => 0];
$monthly_summary_total = (float)$mi_row['grand_total'];
$visa_summary           = (float)$mi_row['total_visa'];

/* ─────────────────────────────────────────────────
   3) STOCK VALUE = Secondary Invoice Total − Monthly Summary Total
───────────────────────────────────────────────── */
$stock_value = $secondary_invoice_total - $monthly_summary_total;

/* ─────────────────────────────────────────────────
   4) PHYSICAL STOCK VALUE = available qty × COST PRICE (live snapshot,
      same figure as ushop_stock_report.php "Total Value (Cost)")
───────────────────────────────────────────────── */
$physical_stock_value = q1val($conn, "
    SELECT COALESCE(SUM(COALESCE(s.avail,0) * COALESCE(i.cost_price,0)),0) AS tot
    FROM ushop_items i
    LEFT JOIN (
        SELECT product_code,
               SUM(CASE WHEN txn_type='STOCK_OUT' THEN -qty ELSE qty END) AS avail
        FROM ushop_stock_history
        GROUP BY product_code
    ) s ON s.product_code = i.product_code
", 'tot');

/* ─────────────────────────────────────────────────
   5) DIFF = Stock Value − Physical Stock Value
───────────────────────────────────────────────── */
$diff_value = $stock_value - $physical_stock_value;

/* ─────────────────────────────────────────────────
   6) CATEGORIES — invoiced / paid / balance
      (mirrors ushop_monthly_category_report.php logic)
───────────────────────────────────────────────── */
$categories = [];
$cat_res = mysqli_query($conn, "SELECT id, name FROM ushop_letter_categories ORDER BY name");
while ($r = mysqli_fetch_assoc($cat_res)) $categories[(int)$r['id']] = $r['name'];

$cat_amt_base = q1val($conn, "SELECT COALESCE(SUM(total_amount),0) AS tot FROM ushop_payment_invoices WHERE YEAR(invoice_date) IN ($years_in)", 'tot');
$cat_adj_tot  = q1val($conn, "SELECT COALESCE(SUM(adj_amount),0) AS tot FROM ushop_monthly_cat_adjustments WHERE adj_year IN ($years_in)", 'tot');
$cat_bo_base  = q1val($conn, "
    SELECT COALESCE(SUM(bop.amount),0) AS tot
    FROM ushop_backoffice_payments bop
    JOIN ushop_payment_invoices pi ON pi.id = bop.payment_invoice_id
    WHERE YEAR(pi.invoice_date) IN ($years_in)
", 'tot');
$cat_paid_adj_tot = q1val($conn, "SELECT COALESCE(SUM(paid_adj),0) AS tot FROM ushop_monthly_cat_paid_adjustments WHERE adj_year IN ($years_in)", 'tot');

$category_invoiced = $cat_amt_base + $cat_adj_tot;
$category_paid      = $cat_bo_base + $cat_paid_adj_tot;
$category_balance   = $category_invoiced - $category_paid;

/* Per-category breakdown */
$cb_amt = []; $cb_adj = []; $cb_bo = []; $cb_padj = [];
$r1 = mysqli_query($conn, "SELECT category_id, COALESCE(SUM(total_amount),0) v FROM ushop_payment_invoices WHERE YEAR(invoice_date) IN ($years_in) GROUP BY category_id");
while ($r = mysqli_fetch_assoc($r1)) $cb_amt[(int)$r['category_id']] = (float)$r['v'];
$r2 = mysqli_query($conn, "SELECT category_id, COALESCE(SUM(adj_amount),0) v FROM ushop_monthly_cat_adjustments WHERE adj_year IN ($years_in) GROUP BY category_id");
while ($r = mysqli_fetch_assoc($r2)) $cb_adj[(int)$r['category_id']] = (float)$r['v'];
$r3 = mysqli_query($conn, "
    SELECT pi.category_id, COALESCE(SUM(bop.amount),0) v
    FROM ushop_backoffice_payments bop
    JOIN ushop_payment_invoices pi ON pi.id = bop.payment_invoice_id
    WHERE YEAR(pi.invoice_date) IN ($years_in)
    GROUP BY pi.category_id
");
while ($r = mysqli_fetch_assoc($r3)) $cb_bo[(int)$r['category_id']] = (float)$r['v'];
$r4 = mysqli_query($conn, "SELECT category_id, COALESCE(SUM(paid_adj),0) v FROM ushop_monthly_cat_paid_adjustments WHERE adj_year IN ($years_in) GROUP BY category_id");
while ($r = mysqli_fetch_assoc($r4)) $cb_padj[(int)$r['category_id']] = (float)$r['v'];

$cat_breakdown = [];
foreach ($categories as $cid => $cname) {
    $inv = ($cb_amt[$cid] ?? 0) + ($cb_adj[$cid] ?? 0);
    $pd  = ($cb_bo[$cid]  ?? 0) + ($cb_padj[$cid] ?? 0);
    $cat_breakdown[] = [
        'name'     => $cname,
        'invoiced' => $inv,
        'paid'     => $pd,
        'balance'  => $inv - $pd,
    ];
}

/* ─────────────────────────────────────────────────
   7) VISA — reconciled actual + adjustment vs. summary
      (mirrors ushop_monthly_category_report.php logic)
───────────────────────────────────────────────── */
$visa_actual = q1val($conn, "
    SELECT COALESCE(SUM(inv.total_amount),0) AS tot
    FROM ushop_invoices inv
    WHERE inv.reconciled = 1
      AND inv.id IN (SELECT invoice_id FROM ushop_invoice_payments WHERE pay_type LIKE '%VISA%')
      AND YEAR(inv.invoice_date) IN ($years_in)
", 'tot');
$visa_adj = q1val($conn, "SELECT COALESCE(SUM(visa_adj),0) AS tot FROM ushop_monthly_visa_adjustments WHERE adj_year IN ($years_in)", 'tot');

$visa_paid    = $visa_actual + $visa_adj;      /* effective VISA paid    */
$visa_balance = $visa_summary - $visa_paid;    /* summary vs. reconciled */

/* ─────────────────────────────────────────────────
   8) TOTALS
───────────────────────────────────────────────── */
$total_paid        = $category_paid + $visa_paid;
$total_balance_due = $category_balance + $visa_balance;

/* ─────────────────────────────────────────────────
   8b) INVESTMENT = Stock Value + Total Balance Due
───────────────────────────────────────────────── */
$investment = $stock_value + $total_balance_due;

/* ─────────────────────────────────────────────────
   9) MANAGEMENT FEE
      (mirrors ushop_monthly_category_report.php logic)
───────────────────────────────────────────────── */
$mf_total = q1val($conn, "
    SELECT COALESCE(SUM(mgmt_fee_amount),0) AS tot
    FROM ushop_payment_invoices
    WHERE mgmt_fee_amount > 0
      AND SUBSTRING(COALESCE(payment_month, DATE_FORMAT(invoice_date,'%Y-%m')),1,4) IN ($years_in)
", 'tot');
$mf_paid_base = q1val($conn, "
    SELECT COALESCE(SUM(sub.bo_paid),0) AS tot
    FROM (
        SELECT pi.id, COALESCE(SUM(bop.amount),0) AS bo_paid
        FROM ushop_payment_invoices pi
        LEFT JOIN ushop_backoffice_payments bop ON bop.payment_invoice_id = pi.id
        WHERE pi.mgmt_fee_amount > 0
          AND SUBSTRING(COALESCE(pi.payment_month, DATE_FORMAT(pi.invoice_date,'%Y-%m')),1,4) IN ($years_in)
        GROUP BY pi.id
    ) sub
", 'tot');
$mf_paid_adj = q1val($conn, "SELECT COALESCE(SUM(paid_adj),0) AS tot FROM ushop_monthly_mgmt_fee_paid_adj WHERE adj_year IN ($years_in)", 'tot');

$mf_paid    = $mf_paid_base + $mf_paid_adj;
$mf_balance = $mf_total - $mf_paid;

function fmt($v) { return number_format((float)$v, 2); }
function tone($v) { return $v > 0.01 ? 'bad' : ($v < -0.01 ? 'warn' : 'good'); }

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.page-wrap{max-width:1400px;margin:0 auto;padding:0 8px 40px;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:14px;flex-wrap:wrap;}
.breadcrumb a{color:#0e7490;text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}

.ph-row{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.ph-row h2{margin:0;font-size:19px;font-weight:700;color:#111827;}
.ph-row p{margin:4px 0 0;font-size:12px;color:#6b7280;}

.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-teal{background:#0e7490;color:#fff;}.btn-teal:hover{background:#155e75;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}

.filter-bar{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:22px;}
.filter-group{display:flex;flex-direction:column;gap:4px;}
.filter-group label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
.filter-group select{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;color:#111827;outline:none;min-width:150px;background:#fff;}
.filter-group select:focus{border-color:#0e7490;}

.section-title{font-size:13px;font-weight:800;color:#111827;text-transform:uppercase;letter-spacing:.5px;margin:26px 0 12px;display:flex;align-items:center;gap:8px;}
.section-title i{color:#0e7490;}
.section-title .hint{font-size:11px;font-weight:500;color:#9ca3af;text-transform:none;letter-spacing:0;margin-left:4px;}

.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;}
.kpi-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 18px;box-shadow:0 1px 6px rgba(0,0,0,.04);position:relative;overflow:hidden;}
.kpi-card::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;}
.kpi-card.c-blue::before{background:#2563eb;}
.kpi-card.c-purple::before{background:#7c3aed;}
.kpi-card.c-teal::before{background:#0e7490;}
.kpi-card.c-green::before{background:#15803d;}
.kpi-card.c-amber::before{background:#d97706;}
.kpi-card.c-red::before{background:#dc2626;}
.kpi-card.c-gray::before{background:#6b7280;}
.kpi-label{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;}
.kpi-value{font-size:20px;font-weight:800;color:#111827;line-height:1.15;}
.kpi-formula{font-size:10.5px;color:#9ca3af;margin-top:6px;}
.kpi-value.good{color:#15803d;}
.kpi-value.bad{color:#dc2626;}
.kpi-value.warn{color:#d97706;}

.tag{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:700;margin-top:6px;}
.tag.good{background:#dcfce7;color:#15803d;}
.tag.bad{background:#fee2e2;color:#dc2626;}
.tag.warn{background:#fef3c7;color:#92400e;}

.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-top:14px;}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table th{padding:10px 14px;text-align:right;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:10.5px;text-transform:uppercase;white-space:nowrap;}
.data-table th:first-child{text-align:left;}
.data-table td{padding:9px 14px;border-bottom:1px solid #f3f4f6;color:#111827;text-align:right;}
.data-table td:first-child{text-align:left;font-weight:600;color:#0e7490;}
.data-table tr:hover td{background:#f9fafb;}
.data-table tfoot td{font-weight:800;background:#f9fafb;border-top:2px solid #0e7490;}
.txt-green{color:#15803d;font-weight:700;}
.txt-red{color:#dc2626;font-weight:700;}
.txt-dim{color:#9ca3af;}
.empty-state{text-align:center;padding:40px 20px;color:#9ca3af;font-size:13px;}

@media(max-width:768px){.filter-bar{flex-direction:column;align-items:stretch;}}
</style>

<div class="page-wrap">
<div class="breadcrumb">
  <a href="ushop_dashboard_report.php"><i class="fa-solid fa-house"></i> Dashboard</a>
  <span class="sep">›</span>
  <span style="color:#0e7490;font-weight:700;"><i class="fa-solid fa-gauge-high"></i> U Shop Stock &amp; Balance Dashboard</span>
</div>

<div class="ph-row">
  <div>
    <h2><i class="fa-solid fa-gauge-high" style="color:#0e7490;margin-right:8px;"></i>U Shop Stock &amp; Balance Dashboard</h2>
    <p>Secondary invoices, monthly summary, stock valuation, category/VISA payments and management fee — <?= htmlspecialchars($year_label) ?></p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="secondary_import_history.php" class="btn btn-secondary"><i class="fa-solid fa-clock-rotate-left"></i> Import History</a>
    <a href="ushop_monthly_category_report.php" class="btn btn-secondary"><i class="fa-solid fa-table-cells-large"></i> Category Report</a>
    <a href="ushop_stock_report.php" class="btn btn-secondary"><i class="fa-solid fa-boxes-stacked"></i> Stock Report</a>
  </div>
</div>

<form method="GET" class="filter-bar">
  <div class="filter-group">
    <label>Year</label>
    <select name="year" onchange="this.form.submit()">
      <option value="all" <?= $is_all ? 'selected' : '' ?>>All Years</option>
      <?php foreach ($year_options as $y): ?>
        <option value="<?= $y ?>" <?= (!$is_all && $y === $sel_year) ? 'selected' : '' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <a href="ushop_dashboard_report.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-xmark"></i> Reset</a>
  </div>
</form>

<!-- ══════════ SECTION 1: INVOICE & STOCK VALUE ══════════ -->
<div class="section-title"><i class="fa-solid fa-file-invoice-dollar"></i> Invoice &amp; Stock Value</div>
<div class="kpi-grid">
  <div class="kpi-card c-blue">
    <div class="kpi-label">Secondary Invoice Total</div>
    <div class="kpi-value">Rs. <?= fmt($secondary_invoice_total) ?></div>
    <div class="kpi-formula">Source: Secondary Invoice Imports, by delivery_date</div>
  </div>
  <div class="kpi-card c-purple">
    <div class="kpi-label">Monthly Invoice Summary Total</div>
    <div class="kpi-value">Rs. <?= fmt($monthly_summary_total) ?></div>
    <div class="kpi-formula">Source: Monthly Category Report (monthly_invoice_imports)</div>
  </div>
  <div class="kpi-card c-teal">
    <div class="kpi-label">Stock Value</div>
    <div class="kpi-value"><?= $stock_value < 0 ? '−' : '' ?>Rs. <?= fmt(abs($stock_value)) ?></div>
    <div class="kpi-formula">Secondary Invoice Total − Monthly Summary Total</div>
  </div>
  <div class="kpi-card c-green">
    <div class="kpi-label">Physical Stock Value</div>
    <div class="kpi-value">Rs. <?= fmt($physical_stock_value) ?></div>
    <div class="kpi-formula">U Shop Stock Report: Available Qty × Cost Price</div>
  </div>
  <div class="kpi-card c-amber">
    <div class="kpi-label">Diff</div>
    <div class="kpi-value <?= tone($diff_value) ?>"><?= $diff_value < 0 ? '−' : '' ?>Rs. <?= fmt(abs($diff_value)) ?></div>
    <div class="kpi-formula">Stock Value − Physical Stock Value</div>
  </div>
</div>

<!-- ══════════ SECTION 2: CATEGORY & VISA PAYMENTS ══════════ -->
<div class="section-title"><i class="fa-solid fa-money-check-dollar"></i> Category &amp; VISA Payments</div>
<div class="kpi-grid">
  <div class="kpi-card c-green">
    <div class="kpi-label">Categories Paid</div>
    <div class="kpi-value">Rs. <?= fmt($category_paid) ?></div>
    <div class="kpi-formula">Back-office paid + paid adjustments</div>
  </div>
  <div class="kpi-card c-green">
    <div class="kpi-label">VISA Paid</div>
    <div class="kpi-value">Rs. <?= fmt($visa_paid) ?></div>
    <div class="kpi-formula">Reconciled VISA + VISA adjustment</div>
  </div>
  <div class="kpi-card c-teal">
    <div class="kpi-label">Total Paid</div>
    <div class="kpi-value">Rs. <?= fmt($total_paid) ?></div>
    <div class="kpi-formula">Categories Paid + VISA Paid</div>
  </div>
  <div class="kpi-card c-red">
    <div class="kpi-label">Category Balance Due</div>
    <div class="kpi-value <?= tone($category_balance) ?>"><?= $category_balance < 0 ? '−' : '' ?>Rs. <?= fmt(abs($category_balance)) ?></div>
    <div class="kpi-formula">Category Invoiced − Categories Paid</div>
  </div>
  <div class="kpi-card c-red">
    <div class="kpi-label">VISA Balance Due</div>
    <div class="kpi-value <?= tone($visa_balance) ?>"><?= $visa_balance < 0 ? '−' : '' ?>Rs. <?= fmt(abs($visa_balance)) ?></div>
    <div class="kpi-formula">VISA Summary − VISA Paid</div>
  </div>
  <div class="kpi-card c-red">
    <div class="kpi-label">Total Balance Due</div>
    <div class="kpi-value <?= tone($total_balance_due) ?>"><?= $total_balance_due < 0 ? '−' : '' ?>Rs. <?= fmt(abs($total_balance_due)) ?></div>
    <div class="kpi-formula">Category Balance + VISA Balance</div>
  </div>
</div>

<!-- ══════════ SECTION 2b: INVESTMENT ══════════ -->
<div class="section-title"><i class="fa-solid fa-sack-dollar"></i> Investment</div>
<div class="kpi-grid">
  <div class="kpi-card c-purple">
    <div class="kpi-label">Investment</div>
    <div class="kpi-value"><?= $investment < 0 ? '−' : '' ?>Rs. <?= fmt(abs($investment)) ?></div>
    <div class="kpi-formula">Stock Value + Total Balance Due</div>
  </div>
</div>

<!-- Category breakdown table -->
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title"><i class="fa-solid fa-layer-group" style="margin-right:6px;color:#0e7490;"></i>Category Breakdown</div>
  </div>
  <div class="dt-wrap">
    <table class="data-table">
      <thead><tr><th>Category</th><th>Invoiced</th><th>Paid</th><th>Balance</th></tr></thead>
      <tbody>
      <?php if ($cat_breakdown): ?>
        <?php foreach ($cat_breakdown as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['name']) ?></td>
          <td><?= fmt($c['invoiced']) ?></td>
          <td class="txt-green"><?= fmt($c['paid']) ?></td>
          <td class="<?= $c['balance'] > 0.01 ? 'txt-red' : 'txt-green' ?>"><?= $c['balance'] > 0.01 ? fmt($c['balance']) : '0.00' ?></td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="4" class="empty-state">No category data for <?= htmlspecialchars($year_label) ?>.</td></tr>
      <?php endif; ?>
      </tbody>
      <?php if ($cat_breakdown): ?>
      <tfoot>
        <tr>
          <td>Total</td>
          <td><?= fmt($category_invoiced) ?></td>
          <td><?= fmt($category_paid) ?></td>
          <td class="<?= $category_balance > 0.01 ? 'txt-red' : 'txt-green' ?>"><?= fmt($category_balance) ?></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<!-- ══════════ SECTION 3: MANAGEMENT FEE ══════════ -->
<div class="section-title"><i class="fa-solid fa-hand-holding-dollar"></i> Management Fee</div>
<div class="kpi-grid">
  <div class="kpi-card c-gray">
    <div class="kpi-label">Management Fee (Total)</div>
    <div class="kpi-value">Rs. <?= fmt($mf_total) ?></div>
    <div class="kpi-formula">Sum of mgmt_fee_amount on payment invoices</div>
  </div>
  <div class="kpi-card c-green">
    <div class="kpi-label">Management Fee Paid</div>
    <div class="kpi-value">Rs. <?= fmt($mf_paid) ?></div>
    <div class="kpi-formula">Back-office paid + paid adjustments</div>
  </div>
  <div class="kpi-card c-red">
    <div class="kpi-label">Management Fee Balance</div>
    <div class="kpi-value <?= tone($mf_balance) ?>"><?= $mf_balance < 0 ? '−' : '' ?>Rs. <?= fmt(abs($mf_balance)) ?></div>
    <div class="kpi-formula">Management Fee − Management Fee Paid</div>
    <?php if ($mf_total > 0): $mf_pct = min(100, ($mf_paid / $mf_total) * 100); ?>
      <span class="tag <?= $mf_pct >= 99.9 ? 'good' : ($mf_paid > 0 ? 'warn' : 'bad') ?>">
        <?= number_format($mf_pct,1) ?>% paid
      </span>
    <?php endif; ?>
  </div>
</div>

</div><!-- /page-wrap -->

<?php include 'footer.php'; ?>