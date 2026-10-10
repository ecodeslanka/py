<?php
include 'config.php';
include 'header.php';

$field_summary_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$field_summary_id) { header('Location: field_summary_list.php'); exit; }

$summary_result = mysqli_query($conn,"SELECT * FROM field_summary WHERE id=$field_summary_id");
if (!$summary_result || mysqli_num_rows($summary_result)===0) { header('Location: field_summary_list.php'); exit; }
$summary = mysqli_fetch_assoc($summary_result);

/* ── Active payments ── */
$payments = mysqli_query($conn,
    "SELECT p.*,
            COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.invoice_num) AS cust_name,
            fsd.adjust_net_value AS invoice_amount
     FROM invoice_payments p
     LEFT JOIN field_summary_details fsd ON fsd.id = p.field_summary_detail_id
     LEFT JOIN customers c ON c.t_code = p.t_code
     WHERE p.field_summary_id = $field_summary_id
     ORDER BY p.invoice_num, p.created_at");

/* ── Cheques keyed by payment id ── */
$cheques_map = [];
$cheques_res = mysqli_query($conn,
    "SELECT ipc.* FROM invoice_payment_cheques ipc
     INNER JOIN invoice_payments ip ON ip.id = ipc.invoice_payment_id
     WHERE ip.field_summary_id = $field_summary_id
     ORDER BY ipc.invoice_payment_id, ipc.id");
if ($cheques_res) while ($ch = mysqli_fetch_assoc($cheques_res))
    $cheques_map[$ch['invoice_payment_id']][] = $ch;

/* ── Deletion audit log ── */
$deletion_log = [];
$reversal_cheques_map = [];
$log_tbl = mysqli_query($conn,
    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_reversals' LIMIT 1");
if ($log_tbl && mysqli_num_rows($log_tbl) > 0) {
    $lr = mysqli_query($conn,
        "SELECT pr.*,
                COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, pr.invoice_num) AS cust_name
         FROM payment_reversals pr
         LEFT JOIN field_summary_details fsd ON fsd.field_summary_id = pr.field_summary_id
                                             AND fsd.id = pr.field_summary_detail_id
         LEFT JOIN customers c ON c.t_code = pr.t_code
         WHERE pr.field_summary_id = $field_summary_id
         ORDER BY pr.reversed_at DESC");
    if ($lr) while ($row = mysqli_fetch_assoc($lr)) $deletion_log[] = $row;

    /* cheque snapshots */
    $log_chq_tbl = mysqli_query($conn,
        "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_reversal_cheques' LIMIT 1");
    if ($log_chq_tbl && mysqli_num_rows($log_chq_tbl) > 0) {
        $lcr = mysqli_query($conn,
            "SELECT prc.* FROM payment_reversal_cheques prc
             INNER JOIN payment_reversals pr ON pr.id = prc.payment_reversal_id
             WHERE pr.field_summary_id = $field_summary_id
             ORDER BY prc.payment_reversal_id, prc.id");
        if ($lcr) while ($lc = mysqli_fetch_assoc($lcr))
            $reversal_cheques_map[$lc['payment_reversal_id']][] = $lc;
    }
}

/* ── Invoice-level summary totals ── */
$inv_totals = [];
$it_res = mysqli_query($conn,
    "SELECT p.field_summary_detail_id, p.invoice_num,
            COALESCE(MAX(fsd.adjust_net_value),0) AS inv_amt,
            COALESCE(SUM(p.amount),0)             AS total_paid,
            GROUP_CONCAT(DISTINCT p.payment_method ORDER BY p.payment_method SEPARATOR '+') AS methods
     FROM invoice_payments p
     LEFT JOIN field_summary_details fsd ON fsd.id = p.field_summary_detail_id
     WHERE p.field_summary_id = $field_summary_id
     GROUP BY p.field_summary_detail_id, p.invoice_num");
while ($it = mysqli_fetch_assoc($it_res)) $inv_totals[$it['field_summary_detail_id']] = $it;

/* ── Grand totals ── */
$grand_inv = $grand_paid = $grand_deleted = 0;
foreach ($inv_totals as $t) { $grand_inv += floatval($t['inv_amt']); $grand_paid += floatval($t['total_paid']); }
foreach ($deletion_log as $dl) $grand_deleted += floatval($dl['amount']);
$grand_bal    = max(0, $grand_inv - $grand_paid);
$total_rows   = $payments ? mysqli_num_rows($payments) : 0;
$log_count    = count($deletion_log);
if ($payments) mysqli_data_seek($payments, 0);
?>
<style>
/* ── BASE ── */
*{box-sizing:border-box;}
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:20px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.card-title{font-size:15px;font-weight:700;margin-bottom:3px;color:#1f2937;}
.card-sub{font-size:12px;color:#6b7280;margin-bottom:14px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;text-decoration:none;transition:all .2s;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-danger{background:#fee2e2;color:#dc2626;border:1px solid #fecaca;}.btn-danger:hover{background:#dc2626;color:#fff;}
.btn-sm{padding:5px 10px;font-size:11px;border-radius:5px;}

/* ── SUMMARY CARDS ── */
.sum-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px;}
.sum-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px;}
.sum-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;display:flex;align-items:center;gap:5px;}
.sum-value{font-size:19px;font-weight:800;color:#1f2937;margin-top:5px;}
.sum-value.green{color:#166534;}.sum-value.red{color:#dc2626;}.sum-value.blue{color:#1d4ed8;}.sum-value.orange{color:#b45309;}

/* ── TABLES ── */
.ptable{width:100%;border-collapse:collapse;font-size:12.5px;}
.ptable th{padding:9px 8px;font-weight:700;font-size:11px;color:#78350f;text-align:left;background:#fef3c7;border-bottom:2px solid #fbbf24;white-space:nowrap;}
.ptable td{padding:8px;border-bottom:1px solid #f0f0f0;color:#333;vertical-align:top;}
.ptable tbody tr:hover{background:#fffbeb;}
.ptable tr.cash-row td:first-child{border-left:3px solid #22c55e;}
.ptable tr.cheque-row td:first-child{border-left:3px solid #3b82f6;}

/* ── AUDIT LOG TABLE ── */
.log-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.log-table th{padding:9px 8px;font-weight:700;font-size:11px;color:#991b1b;text-align:left;background:#fef2f2;border-bottom:2px solid #fca5a5;white-space:nowrap;}
.log-table td{padding:8px;border-bottom:1px solid #fee2e2;color:#374151;vertical-align:top;}
.log-table tbody tr:hover{background:#fff5f5;}
.log-table tr td:first-child{border-left:3px solid #ef4444;}

/* ── BADGES ── */
.mbadge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.mbadge.cash{background:#dcfce7;color:#166534;}
.mbadge.cheque{background:#dbeafe;color:#1e40af;}
.mbadge.deleted{background:#fee2e2;color:#dc2626;}
.emg-badge{background:#fef3c7;color:#92400e;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;}
.log-id-badge{background:#1e1b4b;color:#c7d2fe;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;font-family:monospace;}

.status-badge{padding:2px 9px;border-radius:12px;font-size:11px;font-weight:700;}
.status-settled{background:#dcfce7;color:#166534;}
.status-partial{background:#fef3c7;color:#92400e;}
.status-unpaid{background:#fef2f2;color:#dc2626;}

/* ── CHEQUE DETAILS ── */
.chq-detail{margin-top:6px;}
.chq-row{display:grid;grid-template-columns:repeat(5,1fr);gap:6px;background:#f8f7ff;border:1px solid #ddd6fe;border-radius:6px;padding:7px 10px;margin-bottom:5px;font-size:11px;}
.chq-cell{display:flex;flex-direction:column;}
.chq-cell-label{font-size:9px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;}
.chq-cell-value{font-weight:600;color:#1e1b4b;}

/* Cheque snapshot in log */
.chq-row.log-chq{background:#fff0f0;border-color:#fca5a5;}
.chq-row.log-chq .chq-cell-label{color:#dc2626;}
.chq-row.log-chq .chq-cell-value{color:#374151;}

/* ── NO DATA ── */
.no-data{text-align:center;padding:40px;color:#9ca3af;}

/* ── MODAL ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;}
.modal-overlay.active{display:flex;}
.modal-box{background:#fff;border-radius:14px;padding:30px 28px 24px;width:100%;max-width:480px;box-shadow:0 25px 70px rgba(0,0,0,.3);animation:slideUp .22s ease;}
@keyframes slideUp{from{transform:translateY(18px);opacity:0;}to{transform:none;opacity:1;}}
.modal-icon{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:24px;}
.modal-icon.danger{background:#fee2e2;color:#dc2626;}
.modal-title{font-size:18px;font-weight:800;text-align:center;color:#1f2937;margin-bottom:5px;}
.modal-sub{font-size:13px;text-align:center;color:#6b7280;margin-bottom:18px;line-height:1.5;}

.modal-payment-info{background:#f9fafb;border:1px solid #e5e5e5;border-radius:8px;padding:12px 14px;margin-bottom:16px;}
.info-row{display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px dashed #e5e5e5;font-size:13px;}
.info-row:last-child{border:none;padding-top:8px;}
.info-label{color:#6b7280;font-weight:500;}
.info-value{font-weight:700;color:#1f2937;}
.info-value.amount{color:#dc2626;font-size:16px;}

.warn-box{background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:12px;color:#78350f;display:flex;gap:8px;align-items:flex-start;line-height:1.5;}

.reason-group{margin-bottom:18px;}
.reason-group label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;}
.reason-group textarea{width:100%;border:1px solid #e5e5e5;border-radius:8px;padding:10px 12px;font-size:13px;font-family:'Inter',sans-serif;resize:vertical;min-height:85px;transition:border .2s;}
.reason-group textarea:focus{outline:none;border-color:#ef4444;box-shadow:0 0 0 3px rgba(239,68,68,.1);}

.modal-footer{display:flex;gap:10px;justify-content:flex-end;}
.btn-cancel-modal{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;padding:9px 18px;}.btn-cancel-modal:hover{background:#e5e5e5;}
.btn-confirm-delete{background:#dc2626;color:#fff;padding:9px 18px;}.btn-confirm-delete:hover{background:#b91c1c;}

/* ── TOAST ── */
.toast{position:fixed;top:20px;right:20px;z-index:10000;padding:13px 20px;border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.2);transform:translateX(140%);transition:transform .3s;display:flex;align-items:center;gap:8px;max-width:360px;}
.toast.show{transform:translateX(0);}
.toast.success{background:#166534;color:#fff;}
.toast.error{background:#dc2626;color:#fff;}

/* ── SECTION DIVIDER ── */
.section-divider{display:flex;align-items:center;gap:12px;margin:24px 0 16px;}
.section-divider hr{flex:1;border:none;border-top:1px dashed #e5e5e5;}
.section-divider span{font-size:12px;font-weight:700;color:#9ca3af;white-space:nowrap;text-transform:uppercase;letter-spacing:.05em;}

@media(max-width:900px){.sum-grid{grid-template-columns:1fr 1fr;}.chq-row{grid-template-columns:1fr 1fr;}}
@media(max-width:600px){.ptable,.log-table{font-size:11px;} th,td{padding:6px 5px !important;}}
@media print{.no-print,.modal-overlay,.toast{display:none!important;}}
</style>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <div>
      <h2 class="page-title"><i class="fa-solid fa-receipt"></i> Payment Records</h2>
      <p class="page-subtitle">
        FS: <strong><?php echo htmlspecialchars($summary['field_summary_code']); ?></strong>
        &nbsp;|&nbsp; Route: <?php echo htmlspecialchars($summary['route']); ?>
        &nbsp;|&nbsp; SR: <?php echo htmlspecialchars($summary['sr_code']); ?>
      </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;" class="no-print">
      <a href="edit_field_summary.php?id=<?php echo $field_summary_id; ?>" class="btn btn-secondary">
        <i class="fa-solid fa-edit"></i> Edit / Pay
      </a>
      <a href="view_field_summary.php?id=<?php echo $field_summary_id; ?>" class="btn btn-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back
      </a>
      <button onclick="window.print()" class="btn btn-secondary">
        <i class="fa-solid fa-print"></i> Print
      </button>
    </div>
  </div>
</div>

<!-- ── Summary Cards ── -->
<div class="sum-grid">
  <div class="sum-card">
    <div class="sum-label"><i class="fa-solid fa-file-invoice"></i> Total Invoice</div>
    <div class="sum-value">Rs. <?php echo number_format($grand_inv,2); ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-label"><i class="fa-solid fa-circle-check"></i> Total Paid</div>
    <div class="sum-value green">Rs. <?php echo number_format($grand_paid,2); ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-label"><i class="fa-solid fa-hourglass-half"></i> Balance</div>
    <div class="sum-value <?php echo $grand_bal > 0 ? 'red' : 'green'; ?>">
      Rs. <?php echo number_format($grand_bal,2); ?>
    </div>
  </div>
  <div class="sum-card">
    <div class="sum-label"><i class="fa-solid fa-trash-can"></i> Total Deleted</div>
    <div class="sum-value orange">Rs. <?php echo number_format($grand_deleted,2); ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-label"><i class="fa-solid fa-list-check"></i> Payments / Logs</div>
    <div class="sum-value blue"><?php echo $total_rows; ?> / <?php echo $log_count; ?></div>
  </div>
</div>

<!-- ── Invoice Balance Summary ── -->
<?php if(!empty($inv_totals)): ?>
<div class="content-card" style="margin-bottom:16px;">
  <h3 class="card-title">Invoice Balance Summary</h3>
  <div class="card-sub">Per-invoice totals for active payments</div>
  <div style="overflow-x:auto;">
  <table class="ptable">
    <thead>
      <tr>
        <th>Invoice</th><th>Customer</th><th>Methods</th>
        <th style="text-align:right;">Invoice Amt</th>
        <th style="text-align:right;">Total Paid</th>
        <th style="text-align:right;">Balance</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($inv_totals as $dt=>$t):
        $ia = floatval($t['inv_amt']); $pd = floatval($t['total_paid']); $bl = max(0,$ia-$pd);
        $cnr = mysqli_query($conn,
            "SELECT COALESCE(NULLIF(fsd.customer_name,''),c.shop_name,fsd.invoice_num) AS nm
             FROM field_summary_details fsd LEFT JOIN customers c ON c.t_code=fsd.t_code
             WHERE fsd.id=$dt LIMIT 1");
        $cn = $cnr ? mysqli_fetch_assoc($cnr) : null;
    ?>
    <tr>
      <td><strong><?php echo htmlspecialchars($t['invoice_num']); ?></strong></td>
      <td style="font-size:12px;"><?php echo htmlspecialchars($cn['nm'] ?? $t['invoice_num']); ?></td>
      <td>
        <?php foreach(explode('+',$t['methods']??'') as $m): $m=trim($m); if(!$m) continue; ?>
          <span class="mbadge <?php echo $m; ?>">
            <i class="fa-solid fa-<?php echo $m==='cash'?'coins':'money-check'; ?>"></i>
            <?php echo ucfirst($m); ?>
          </span>
        <?php endforeach; ?>
      </td>
      <td style="text-align:right;font-weight:600;"><?php echo number_format($ia,2); ?></td>
      <td style="text-align:right;font-weight:700;color:#166534;"><?php echo number_format($pd,2); ?></td>
      <td style="text-align:right;font-weight:700;color:<?php echo $bl>0.005?'#dc2626':'#166534'; ?>;">
        <?php echo number_format($bl,2); ?>
      </td>
      <td>
        <?php if($bl<=0.005): ?>
          <span class="status-badge status-settled"><i class="fa-solid fa-check"></i> Settled</span>
        <?php elseif($pd>0): ?>
          <span class="status-badge status-partial"><i class="fa-solid fa-hourglass-half"></i> Partial</span>
        <?php else: ?>
          <span class="status-badge status-unpaid"><i class="fa-solid fa-xmark"></i> Unpaid</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Active Payments ── -->
<div class="content-card">
  <h3 class="card-title">Active Payments</h3>
  <div class="card-sub">All current payment records — click <strong>Delete</strong> to remove a payment (a full audit log is saved automatically)</div>

  <?php if(!$payments || mysqli_num_rows($payments)===0): ?>
    <div class="no-data">
      <i class="fa-solid fa-inbox fa-2x" style="display:block;margin-bottom:8px;"></i>
      No active payments.
    </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="ptable">
    <thead>
      <tr>
        <th>#</th><th>Invoice</th><th>Customer</th><th>Method</th>
        <th>Pay Date</th><th>Collected By</th><th>Reference</th>
        <th style="text-align:right;">Amount</th>
        <th style="text-align:right;">To Bank</th>
        <th>Flags</th><th>Remarks</th>
        <th>Cheque Details</th>
        <th>Saved At</th>
        <th class="no-print">Action</th>
      </tr>
    </thead>
    <tbody>
    <?php $pn=1; while($p=mysqli_fetch_assoc($payments)): ?>
    <tr class="<?php echo $p['payment_method']; ?>-row" id="pay-row-<?php echo $p['id']; ?>">
      <td><?php echo $pn++; ?></td>
      <td><strong><?php echo htmlspecialchars($p['invoice_num']); ?></strong></td>
      <td style="font-size:12px;"><?php echo htmlspecialchars($p['cust_name'] ?? $p['t_code']); ?></td>
      <td>
        <span class="mbadge <?php echo $p['payment_method']; ?>">
          <i class="fa-solid fa-<?php echo $p['payment_method']==='cash'?'coins':'money-check'; ?>"></i>
          <?php echo ucfirst($p['payment_method']); ?>
        </span>
      </td>
      <td style="white-space:nowrap;"><?php echo $p['payment_date'] ? date('d M Y',strtotime($p['payment_date'])) : '—'; ?></td>
      <td style="font-size:12px;"><?php
        $cbm=['cc'=>'CC','sr'=>'SR','area_manager'=>'Area Mgr','office'=>'Office','other'=>'Other'];
        echo htmlspecialchars($cbm[$p['collected_by']] ?? $p['collected_by']);
      ?></td>
      <td style="font-size:12px;color:#6b7280;"><?php echo htmlspecialchars($p['reference_no'] ?: '—'); ?></td>
      <td style="text-align:right;font-weight:700;color:#166534;">
        Rs. <?php echo number_format($p['amount'],2); ?>
      </td>
      <td style="text-align:right;font-size:12px;color:#6b7280;">
        <?php echo $p['amount_to_bank']>0 ? 'Rs. '.number_format($p['amount_to_bank'],2) : '—'; ?>
      </td>
      <td style="font-size:11px;">
        <?php if(!empty($p['is_emergency_credit'])): ?>
          <span class="emg-badge"><i class="fa-solid fa-bolt"></i> Emergency</span><br>
        <?php endif; ?>
        <?php if($p['cheque_mode']): ?>
          <span style="color:#5b21b6;font-weight:600;"><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$p['cheque_mode']))); ?></span>
        <?php endif; ?>
      </td>
      <td style="font-size:12px;color:#6b7280;max-width:110px;"><?php echo htmlspecialchars($p['remarks'] ?: '—'); ?></td>
      <td>
        <?php if($p['payment_method']==='cheque' && !empty($cheques_map[$p['id']])): ?>
          <div class="chq-detail">
          <?php foreach($cheques_map[$p['id']] as $chq): ?>
            <div class="chq-row">
              <div class="chq-cell"><div class="chq-cell-label">Cheque No.</div><div class="chq-cell-value"><?php echo htmlspecialchars($chq['cheque_no']); ?></div></div>
              <div class="chq-cell"><div class="chq-cell-label">Date</div><div class="chq-cell-value"><?php echo $chq['cheque_date']?date('d/m/Y',strtotime($chq['cheque_date'])):'—'; ?></div></div>
              <div class="chq-cell"><div class="chq-cell-label">Amount</div><div class="chq-cell-value">Rs. <?php echo number_format($chq['amount'],2); ?></div></div>
              <div class="chq-cell"><div class="chq-cell-label">Bank</div><div class="chq-cell-value"><?php echo htmlspecialchars($chq['bank_code'] ? ($chq['bank_name'] ? $chq['bank_code'].' – '.$chq['bank_name'] : $chq['bank_code']) : '—'); ?></div></div>
              <div class="chq-cell"><div class="chq-cell-label">Branch</div><div class="chq-cell-value"><?php echo htmlspecialchars($chq['branch_code'] ? ($chq['branch_name'] ? $chq['branch_code'].' – '.$chq['branch_name'] : $chq['branch_code']) : '—'); ?></div></div>
            </div>
          <?php endforeach; ?>
          </div>
        <?php else: ?>
          <span style="font-size:11px;color:#9ca3af;">—</span>
        <?php endif; ?>
      </td>
      <td style="font-size:11px;color:#9ca3af;white-space:nowrap;"><?php echo date('d M y H:i',strtotime($p['created_at'])); ?></td>
      <td class="no-print">
        <button class="btn btn-danger btn-sm" onclick="openDeleteModal(
          <?php echo $p['id']; ?>,
          '<?php echo addslashes(htmlspecialchars($p['invoice_num'])); ?>',
          '<?php echo $p['payment_method']; ?>',
          <?php echo $p['amount']; ?>,
          '<?php echo addslashes(htmlspecialchars($p['cust_name'] ?? $p['t_code'])); ?>',
          <?php echo !empty($cheques_map[$p['id']]) ? count($cheques_map[$p['id']]) : 0; ?>
        )">
          <i class="fa-solid fa-trash-can"></i> Delete
        </button>
      </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<!-- ── Deletion Audit Log ── -->
<?php if($log_count > 0): ?>
<div class="section-divider no-print">
  <hr><span><i class="fa-solid fa-scroll"></i> Deletion Audit Log</span><hr>
</div>

<div class="content-card">
  <h3 class="card-title" style="color:#dc2626;">
    <i class="fa-solid fa-clock-rotate-left"></i> Payment Deletion Log
  </h3>
  <div class="card-sub">
    Complete history of every payment deleted from this field summary —
    <?php echo $log_count; ?> record(s) &nbsp;|&nbsp; Total deleted: <strong>Rs. <?php echo number_format($grand_deleted,2); ?></strong>
  </div>
  <div style="overflow-x:auto;">
  <table class="log-table">
    <thead>
      <tr>
        <th>Log #</th><th>Invoice</th><th>Customer</th><th>Method</th>
        <th>Pay Date</th><th>Collected By</th><th>Reference</th>
        <th style="text-align:right;">Amount</th>
        <th>Cheques</th>
        <th>Remarks</th>
        <th>Originally Saved</th>
        <th>Deleted By</th>
        <th>Reason</th>
        <th>Deleted At</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($deletion_log as $dl):
        $cbm = ['cc'=>'CC','sr'=>'SR','area_manager'=>'Area Mgr','office'=>'Office','other'=>'Other'];
        $dl_cheques = $reversal_cheques_map[$dl['id']] ?? [];
    ?>
    <tr>
      <td><span class="log-id-badge">#<?php echo $dl['id']; ?></span></td>
      <td><strong><?php echo htmlspecialchars($dl['invoice_num']); ?></strong></td>
      <td style="font-size:12px;"><?php echo htmlspecialchars($dl['cust_name'] ?? $dl['t_code']); ?></td>
      <td>
        <span class="mbadge deleted">
          <i class="fa-solid fa-<?php echo $dl['payment_method']==='cash'?'coins':'money-check'; ?>"></i>
          <?php echo ucfirst($dl['payment_method']); ?>
        </span>
      </td>
      <td style="white-space:nowrap;font-size:12px;">
        <?php echo $dl['payment_date'] ? date('d M Y',strtotime($dl['payment_date'])) : '—'; ?>
      </td>
      <td style="font-size:12px;"><?php echo htmlspecialchars($cbm[$dl['collected_by']] ?? $dl['collected_by']); ?></td>
      <td style="font-size:12px;color:#6b7280;"><?php echo htmlspecialchars($dl['reference_no'] ?: '—'); ?></td>
      <td style="text-align:right;font-weight:700;color:#dc2626;text-decoration:line-through;">
        Rs. <?php echo number_format($dl['amount'],2); ?>
      </td>
      <td>
        <?php if(!empty($dl_cheques)): ?>
          <div class="chq-detail">
          <?php foreach($dl_cheques as $lc): ?>
            <div class="chq-row log-chq">
              <div class="chq-cell"><div class="chq-cell-label">Cheque No.</div><div class="chq-cell-value"><?php echo htmlspecialchars($lc['cheque_no']); ?></div></div>
              <div class="chq-cell"><div class="chq-cell-label">Date</div><div class="chq-cell-value"><?php echo $lc['cheque_date']?date('d/m/Y',strtotime($lc['cheque_date'])):'—'; ?></div></div>
              <div class="chq-cell"><div class="chq-cell-label">Amount</div><div class="chq-cell-value">Rs. <?php echo number_format($lc['amount'],2); ?></div></div>
              <div class="chq-cell"><div class="chq-cell-label">Bank</div><div class="chq-cell-value"><?php echo htmlspecialchars($lc['bank_code'] ? ($lc['bank_name'] ? $lc['bank_code'].' – '.$lc['bank_name'] : $lc['bank_code']) : '—'); ?></div></div>
              <div class="chq-cell"><div class="chq-cell-label">Status was</div><div class="chq-cell-value" style="color:#b45309;"><?php echo htmlspecialchars(ucfirst($lc['cheque_status_at_reversal'] ?? 'pending')); ?></div></div>
            </div>
          <?php endforeach; ?>
          </div>
        <?php else: ?>
          <span style="font-size:11px;color:#9ca3af;">—</span>
        <?php endif; ?>
      </td>
      <td style="font-size:12px;color:#6b7280;max-width:110px;"><?php echo htmlspecialchars($dl['remarks'] ?: '—'); ?></td>
      <td style="font-size:11px;color:#9ca3af;white-space:nowrap;">
        <?php echo $dl['original_created_at'] ? date('d M y H:i',strtotime($dl['original_created_at'])) : '—'; ?>
      </td>
      <td style="font-size:12px;font-weight:600;color:#374151;"><?php echo htmlspecialchars($dl['reversed_by']); ?></td>
      <td style="font-size:12px;max-width:160px;">
        <span style="background:#fef2f2;border:1px solid #fecaca;padding:3px 8px;border-radius:4px;color:#dc2626;font-size:11px;display:inline-block;">
          <?php echo htmlspecialchars($dl['reason']); ?>
        </span>
      </td>
      <td style="font-size:11px;color:#9ca3af;white-space:nowrap;"><?php echo date('d M y H:i',strtotime($dl['reversed_at'])); ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<!-- ── DELETE MODAL ── -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal-box">
    <div class="modal-icon danger"><i class="fa-solid fa-trash-can"></i></div>
    <div class="modal-title">Delete This Payment?</div>
    <div class="modal-sub">The payment will be <strong>permanently deleted</strong>.<br>A complete audit record will be saved to the deletion log.</div>

    <div class="modal-payment-info" id="modalPaymentInfo"></div>

    <div class="warn-box" id="chequeWarnBox" style="display:none;">
      <i class="fa-solid fa-triangle-exclamation" style="margin-top:1px;flex-shrink:0;"></i>
      <span>This payment has <strong id="chequeCount"></strong> cheque(s) attached. All cheque details will be snapshotted in the audit log and removed from the cheques register.</span>
    </div>

    <div class="reason-group">
      <label><i class="fa-solid fa-comment-dots"></i> Reason for Deletion <span style="color:#dc2626;">*</span></label>
      <textarea id="deletionReason" placeholder="Enter the reason this payment is being deleted..."></textarea>
    </div>

    <div class="modal-footer">
      <button class="btn btn-cancel-modal" onclick="closeDeleteModal()">
        <i class="fa-solid fa-xmark"></i> Cancel
      </button>
      <button class="btn btn-confirm-delete" id="confirmDeleteBtn" onclick="confirmDelete()">
        <i class="fa-solid fa-trash-can"></i> Delete & Log
      </button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
let _pid = null;

function openDeleteModal(pid, invNum, method, amount, custName, chequeCount) {
  _pid = pid;
  document.getElementById('deletionReason').value = '';
  document.getElementById('deletionReason').style.borderColor = '';

  const info = document.getElementById('modalPaymentInfo');
  const methodLabel = method.charAt(0).toUpperCase() + method.slice(1);
  const icon = method === 'cash' ? 'coins' : 'money-check';
  info.innerHTML = `
    <div class="info-row"><span class="info-label">Invoice</span><span class="info-value">${invNum}</span></div>
    <div class="info-row"><span class="info-label">Customer</span><span class="info-value">${custName}</span></div>
    <div class="info-row"><span class="info-label">Method</span>
      <span class="info-value"><span class="mbadge ${method}"><i class="fa-solid fa-${icon}"></i> ${methodLabel}</span></span>
    </div>
    <div class="info-row"><span class="info-label">Amount to Delete</span>
      <span class="info-value amount">Rs. ${parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}</span>
    </div>
  `;

  const warnBox = document.getElementById('chequeWarnBox');
  if (chequeCount > 0) {
    document.getElementById('chequeCount').textContent = chequeCount;
    warnBox.style.display = 'flex';
  } else {
    warnBox.style.display = 'none';
  }

  document.getElementById('deleteModal').classList.add('active');
  setTimeout(() => document.getElementById('deletionReason').focus(), 200);
}

function closeDeleteModal() {
  document.getElementById('deleteModal').classList.remove('active');
  _pid = null;
}

document.getElementById('deleteModal').addEventListener('click', function(e) {
  if (e.target === this) closeDeleteModal();
});

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDeleteModal(); });

function confirmDelete() {
  const reason = document.getElementById('deletionReason').value.trim();
  if (!reason) {
    document.getElementById('deletionReason').style.borderColor = '#ef4444';
    document.getElementById('deletionReason').focus();
    showToast('Please enter a reason for deletion.', 'error');
    return;
  }

  const btn = document.getElementById('confirmDeleteBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting...';

  const fd = new FormData();
  fd.append('payment_id',  _pid);
  fd.append('reason',      reason);
  fd.append('reversed_by', 'operator'); /* Swap with session username if available */

  fetch('reverse_payment.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-trash-can"></i> Delete & Log';

      if (data.success) {
        closeDeleteModal();
        showToast('✓ ' + data.message, 'success');

        /* Remove the row from the table */
        const row = document.getElementById('pay-row-' + _pid);
        if (row) {
          row.style.transition = 'all .4s';
          row.style.background = '#fee2e2';
          row.style.opacity = '0';
          setTimeout(() => { row.remove(); }, 450);
        }

        /* Reload after a moment to refresh totals & show the log */
        setTimeout(() => location.reload(), 2000);
      } else {
        showToast('Error: ' + (data.error || 'Delete failed.'), 'error');
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-trash-can"></i> Delete & Log';
      showToast('Network error. Please try again.', 'error');
    });
}

function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = `toast ${type} show`;
  setTimeout(() => t.classList.remove('show'), 4500);
}
</script>

<?php include 'footer.php'; ?>