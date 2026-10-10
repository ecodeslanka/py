<?php
/**
 * ushop_visa_reconciliation_report.php
 * VISA Card Invoice Reconciliation Report
 * Loads ONLY invoices that have a VISA payment (via ushop_invoice_payments).
 * Shows reconciled status, auth code, reconcile date, commission, net amount.
 * Filters: doc/invoice no, customer, reconciled status, date range, month+year.
 * CSV export respects all active filters.
 */
include 'config.php';

/* ── Filters ── */
$f_q         = trim($_GET['q']        ?? '');
$f_cust      = trim($_GET['cust']     ?? '');
$f_status    = trim($_GET['status']   ?? ''); // '', 'reconciled', 'unreconciled'
$f_date_from = trim($_GET['from']     ?? '');
$f_date_to   = trim($_GET['to']       ?? '');
$f_month     = trim($_GET['fmonth']   ?? '');
$f_year      = trim($_GET['fyear']    ?? '');

/* Resolve effective date range: explicit from/to wins, else month+year */
$eff_from = $f_date_from;
$eff_to   = $f_date_to;
if (!$eff_from && !$eff_to && $f_month !== '' && $f_year !== '' && is_numeric($f_month) && is_numeric($f_year)) {
    $mm = str_pad((int)$f_month, 2, '0', STR_PAD_LEFT);
    $eff_from = $f_year.'-'.$mm.'-01';
    $eff_to   = date('Y-m-t', strtotime($eff_from));
}

$where = ["inv.id IN (SELECT invoice_id FROM ushop_invoice_payments WHERE pay_type LIKE '%VISA%')"];
if ($f_q)         $where[] = "(inv.doc_no LIKE '%".mysqli_real_escape_string($conn,$f_q)."%' OR inv.unique_inv_no LIKE '%".mysqli_real_escape_string($conn,$f_q)."%')";
if ($f_cust)      $where[] = "(inv.customer_name LIKE '%".mysqli_real_escape_string($conn,$f_cust)."%' OR inv.customer_code LIKE '%".mysqli_real_escape_string($conn,$f_cust)."%')";
if ($f_status === 'reconciled')   $where[] = "inv.reconciled=1";
if ($f_status === 'unreconciled') $where[] = "(inv.reconciled=0 OR inv.reconciled IS NULL)";
if ($eff_from)    $where[] = "inv.invoice_date>='".mysqli_real_escape_string($conn,$eff_from)."'";
if ($eff_to)      $where[] = "inv.invoice_date<='".mysqli_real_escape_string($conn,$eff_to)."'";
$wsql = implode(' AND ',$where);

/* ── CSV export (respects filters, no pagination) ── */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="visa_reconciliation_report_'.date('Ymd_His').'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['Date','Doc No','Unique Invoice No','Customer','Customer Code','Amount','Status','Auth Code','Reconcile Date','Commission','Net Amount']);
    $exp = mysqli_query($conn,"
        SELECT inv.*, vr.created_at AS recon_date
        FROM ushop_invoices inv
        LEFT JOIN visa_reconciliations vr ON vr.id = inv.reconciliation_id
        WHERE $wsql
        ORDER BY inv.invoice_date DESC, inv.id DESC
    ");
    while ($er = mysqli_fetch_assoc($exp)) {
        $isRecon = ((int)($er['reconciled'] ?? 0)) === 1;
        fputcsv($out, [
            $er['invoice_date'],
            $er['doc_no'],
            $er['unique_inv_no'],
            $er['customer_name'],
            $er['customer_code'],
            number_format((float)$er['total_amount'],2,'.',''),
            $isRecon ? 'Reconciled' : 'Not Reconciled',
            $isRecon ? $er['auth_code'] : '',
            ($isRecon && $er['recon_date']) ? date('Y-m-d H:i',strtotime($er['recon_date'])) : '',
            $isRecon ? number_format((float)$er['visa_comm'],2,'.','') : '',
            $isRecon ? number_format((float)$er['visa_net'],2,'.','') : '',
        ]);
    }
    fclose($out);
    exit;
}

/* ── Pagination ── */
$page   = max(1,(int)($_GET['page']??1));
$per    = 30;
$offset = ($page-1)*$per;
$total  = (int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM ushop_invoices inv WHERE $wsql"))['c'];
$pages  = max(1,ceil($total/$per));

$rows = mysqli_query($conn,"
  SELECT inv.*, vr.created_at AS recon_date, vr.statement_date AS recon_stmt_date
  FROM ushop_invoices inv
  LEFT JOIN visa_reconciliations vr ON vr.id = inv.reconciliation_id
  WHERE $wsql
  ORDER BY inv.invoice_date DESC, inv.id DESC
  LIMIT $per OFFSET $offset
");

/* ── Summary ── */
$agg = mysqli_fetch_assoc(mysqli_query($conn,"
  SELECT COUNT(*) cnt,
         COALESCE(SUM(inv.total_amount),0) sum_amt,
         COALESCE(SUM(CASE WHEN inv.reconciled=1 THEN 1 ELSE 0 END),0) recon_cnt,
         COALESCE(SUM(CASE WHEN inv.reconciled=1 THEN inv.total_amount ELSE 0 END),0) recon_amt,
         COALESCE(SUM(CASE WHEN inv.reconciled=1 THEN inv.visa_comm ELSE 0 END),0) sum_comm,
         COALESCE(SUM(CASE WHEN inv.reconciled=1 THEN inv.visa_net ELSE 0 END),0) sum_net
  FROM ushop_invoices inv WHERE $wsql
")) ?: [];
$un_cnt = max(0,(int)($agg['cnt']??0) - (int)($agg['recon_cnt']??0));
$un_amt = max(0,(float)($agg['sum_amt']??0) - (float)($agg['recon_amt']??0));

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.page-wrap{max-width:1450px;margin:0 auto;padding:0 8px;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:14px;flex-wrap:wrap;}
.breadcrumb a{color:#0e7490;text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}
.ph-row{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-teal{background:#0e7490;color:#fff;}.btn-teal:hover{background:#155e75;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-green{background:#15803d;color:#fff;}.btn-green:hover{background:#166534;}
.btn-sm{padding:5px 10px;font-size:11.5px;}
.sum-cards{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:18px;}
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;flex:1;min-width:140px;box-shadow:0 1px 4px rgba(0,0,0,.04);border-top:3px solid #0e7490;}
.sum-card.green{border-top-color:#15803d;}
.sum-card.red{border-top-color:#dc2626;}
.sum-card.amber{border-top-color:#92400e;}
.sum-card-label{font-size:10.5px;color:#6b7280;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.sum-card-val{font-size:19px;font-weight:700;color:#0e7490;}
.sum-card-sub{font-size:10.5px;color:#9ca3af;margin-top:2px;}
.filter-bar{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;}
.filter-group{display:flex;flex-direction:column;gap:4px;}
.filter-group label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;}
.filter-group input,.filter-group select{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;font-family:inherit;color:#111827;outline:none;min-width:120px;}
.filter-group input:focus,.filter-group select:focus{border-color:#0e7490;}
.filter-group input.yr{min-width:80px;}
.filter-sep{width:1px;align-self:stretch;background:#e5e7eb;margin:0 4px;}
.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table th{padding:10px 12px;text-align:left;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:11px;text-transform:uppercase;white-space:nowrap;}
.data-table td{padding:9px 12px;border-bottom:1px solid #f3f4f6;color:#111827;vertical-align:middle;white-space:nowrap;}
.data-table tr:hover td{background:#f9fafb;}
.tr{text-align:right!important;}.tc{text-align:center!important;}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.badge-teal{background:#cffafe;color:#0e7490;}
.badge-blue{background:#dbeafe;color:#1e40af;}
.badge-green{background:#dcfce7;color:#15803d;}
.badge-red{background:#fee2e2;color:#dc2626;}
.badge-gray{background:#f3f4f6;color:#6b7280;}
.mono{font-family:monospace;}
.pagination{display:flex;align-items:center;gap:6px;padding:14px 18px;justify-content:flex-end;flex-wrap:wrap;}
.pagination a,.pagination span{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;}
.pagination a{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;}.pagination a:hover{background:#e5e7eb;}
.pagination .active{background:#0e7490;color:#fff;border-color:#0e7490;}
</style>

<div class="page-wrap">
<div class="breadcrumb">
  <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
  <span class="sep">›</span>
  <a href="ushop_invoice_list.php"><i class="fa-solid fa-receipt"></i> Invoices</a>
  <span class="sep">›</span>
  <a href="visa_reconciliation.php"><i class="fa-brands fa-cc-visa"></i> VISA Reconciliation</a>
  <span class="sep">›</span>
  <span style="color:#0e7490;font-weight:700;"><i class="fa-solid fa-chart-column"></i> Report</span>
</div>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:19px;font-weight:700;color:#111827;">
      <i class="fa-brands fa-cc-visa" style="color:#0e7490;margin-right:8px;"></i>VISA Reconciliation Report
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Only invoices with a VISA payment are shown. Filter by status, date range, or month/year.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="visa_reconciliation.php" class="btn btn-teal"><i class="fa-solid fa-code-compare"></i> Reconcile</a>
    <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET,['export'=>'csv']))) ?>" class="btn btn-green"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
  </div>
</div>

<div class="sum-cards">
  <div class="sum-card"><div class="sum-card-label">VISA Invoices</div><div class="sum-card-val"><?= number_format($agg['cnt']??0) ?></div></div>
  <div class="sum-card"><div class="sum-card-label">Total Amount</div><div class="sum-card-val" style="color:#374151;"><?= number_format($agg['sum_amt']??0,2) ?></div></div>
  <div class="sum-card green"><div class="sum-card-label">Reconciled</div><div class="sum-card-val" style="color:#15803d;"><?= number_format($agg['recon_cnt']??0) ?></div><div class="sum-card-sub"><?= number_format($agg['recon_amt']??0,2) ?> LKR</div></div>
  <div class="sum-card red"><div class="sum-card-label">Not Reconciled</div><div class="sum-card-val" style="color:#dc2626;"><?= number_format($un_cnt) ?></div><div class="sum-card-sub"><?= number_format($un_amt,2) ?> LKR</div></div>
  <div class="sum-card amber"><div class="sum-card-label">Commission</div><div class="sum-card-val" style="color:#92400e;"><?= number_format($agg['sum_comm']??0,2) ?></div></div>
  <div class="sum-card"><div class="sum-card-label">Net Amount</div><div class="sum-card-val" style="color:#0e7490;"><?= number_format($agg['sum_net']??0,2) ?></div></div>
</div>

<form method="GET" class="filter-bar">
  <div class="filter-group"><label>Doc / Invoice No</label><input type="text" name="q" value="<?= htmlspecialchars($f_q) ?>" placeholder="Doc or unique no…"></div>
  <div class="filter-group"><label>Customer</label><input type="text" name="cust" value="<?= htmlspecialchars($f_cust) ?>" placeholder="Name or code…"></div>
  <div class="filter-group"><label>Status</label>
    <select name="status">
      <option value="">All</option>
      <option value="reconciled" <?= $f_status==='reconciled'?'selected':'' ?>>Reconciled</option>
      <option value="unreconciled" <?= $f_status==='unreconciled'?'selected':'' ?>>Not Reconciled</option>
    </select>
  </div>
  <div class="filter-sep"></div>
  <div class="filter-group"><label>From</label><input type="date" name="from" value="<?= htmlspecialchars($f_date_from) ?>"></div>
  <div class="filter-group"><label>To</label><input type="date" name="to" value="<?= htmlspecialchars($f_date_to) ?>"></div>
  <div class="filter-sep"></div>
  <div class="filter-group"><label>Month</label>
    <select name="fmonth">
      <option value="">—</option>
      <?php
      $monthNames=['1'=>'January','2'=>'February','3'=>'March','4'=>'April','5'=>'May','6'=>'June','7'=>'July','8'=>'August','9'=>'September','10'=>'October','11'=>'November','12'=>'December'];
      foreach($monthNames as $mv=>$mn): ?>
        <option value="<?= $mv ?>" <?= ((string)$f_month===(string)$mv)?'selected':'' ?>><?= $mn ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group"><label>Year</label><input class="yr" type="number" name="fyear" value="<?= htmlspecialchars($f_year) ?>" placeholder="2026" min="2000" max="2100"></div>
  <div style="display:flex;gap:6px;align-items:flex-end;">
    <button type="submit" class="btn btn-teal btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
    <a href="ushop_visa_reconciliation_report.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
  </div>
</form>

<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title"><i class="fa-brands fa-cc-visa" style="margin-right:6px;color:#0e7490;"></i>VISA Invoices
      <span style="font-size:11px;color:#9ca3af;font-weight:400;margin-left:8px;"><?= number_format($total) ?> record<?= $total!=1?'s':'' ?></span>
    </div>
  </div>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>#</th><th>Date</th><th>Doc No</th><th>Unique Invoice No</th>
        <th>Customer</th><th>Code</th><th class="tr">Amount</th>
        <th class="tc">Status</th><th>Auth No</th><th>Reconcile Date</th>
        <th class="tr">Comm</th><th class="tr">Net</th>
      </tr>
    </thead>
    <tbody>
    <?php $i=$offset+1; while($r=mysqli_fetch_assoc($rows)):
      $isRecon = ((int)($r['reconciled'] ?? 0)) === 1;
    ?>
    <tr>
      <td style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
      <td><span class="badge badge-teal"><?= date('d M Y',strtotime($r['invoice_date'])) ?></span></td>
      <td style="font-weight:700;color:#0e7490;">
        <?= htmlspecialchars($r['doc_no']) ?>
        <a href="ushop_invoice_detail.php?id=<?= $r['id'] ?>" target="_blank" title="Open full invoice page" style="margin-left:6px;color:#9ca3af;font-size:10px;font-weight:400;text-decoration:none;"><i class="fa-solid fa-up-right-from-square"></i></a>
      </td>
      <td class="mono" style="font-size:11px;color:#6b7280;"><?= htmlspecialchars($r['unique_inv_no']?:'—') ?></td>
      <td><?= $r['customer_name'] ? htmlspecialchars($r['customer_name']) : '<span style="color:#9ca3af;font-size:11px;">Walk-in</span>' ?></td>
      <td><?= $r['customer_code'] ? '<span class="badge badge-blue">#'.htmlspecialchars($r['customer_code']).'</span>' : '<span style="color:#d1d5db;">—</span>' ?></td>
      <td class="tr" style="font-weight:700;color:#15803d;"><?= number_format($r['total_amount'],2) ?></td>
      <td class="tc">
        <?php if($isRecon): ?>
          <span class="badge badge-green"><i class="fa-solid fa-check"></i> Reconciled</span>
        <?php else: ?>
          <span class="badge badge-red"><i class="fa-solid fa-xmark"></i> Not Reconciled</span>
        <?php endif; ?>
      </td>
      <td class="mono" style="font-size:11px;"><?= $isRecon && $r['auth_code'] ? htmlspecialchars($r['auth_code']) : '<span style="color:#d1d5db;">—</span>' ?></td>
      <td style="font-size:11px;color:#6b7280;"><?= ($isRecon && $r['recon_date']) ? date('d M Y H:i',strtotime($r['recon_date'])) : '<span style="color:#d1d5db;">—</span>' ?></td>
      <td class="tr" style="color:#92400e;"><?= $isRecon ? number_format($r['visa_comm'],2) : '<span style="color:#d1d5db;">—</span>' ?></td>
      <td class="tr" style="font-weight:700;color:#0e7490;"><?= $isRecon ? number_format($r['visa_net'],2) : '<span style="color:#d1d5db;">—</span>' ?></td>
    </tr>
    <?php endwhile; ?>
    <?php if($total===0): ?>
    <tr><td colspan="12" style="text-align:center;padding:44px;color:#9ca3af;font-size:13px;">No VISA invoices found for the selected filters.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
  <?php if($pages>1): ?>
  <div class="pagination">
    <span style="font-size:12px;color:#6b7280;margin-right:6px;">Page <?= $page ?> of <?= $pages ?></span>
    <?php if($page>1): ?>
      <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET,['page'=>1]))) ?>">&laquo;</a>
      <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET,['page'=>$page-1]))) ?>">&lsaquo;</a>
    <?php endif; ?>
    <?php for($p=max(1,$page-3);$p<=min($pages,$page+3);$p++): ?>
      <?php if($p===$page): ?><span class="active"><?= $p ?></span>
      <?php else: ?><a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET,['page'=>$p]))) ?>"><?= $p ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if($page<$pages): ?>
      <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET,['page'=>$page+1]))) ?>">&rsaquo;</a>
      <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET,['page'=>$pages]))) ?>">&raquo;</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
</div>

<?php include 'footer.php'; ?>
