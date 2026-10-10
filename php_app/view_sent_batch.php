<?php
/**
 * view_sent_batch.php — View / Print a Sent Cheque Batch Document
 * Shows full details of a sent batch with all cheques listed.
 * ?print=1 auto-triggers print dialog.
 */

include 'config.php';
$batch_id = intval($_GET['id'] ?? 0);
$auto_print = intval($_GET['print'] ?? 0);

if (!$batch_id) {
    include 'header.php';
    echo '<div style="text-align:center;padding:80px;color:#dc2626;"><i class="fa-solid fa-triangle-exclamation" style="font-size:48px;display:block;margin-bottom:12px;"></i><h3>Invalid Batch</h3><p>No batch ID provided.</p><a href="sent_daily_cheques.php" style="color:#7c3aed;">← Back to Sent Cheques</a></div>';
    include 'footer.php';
    exit;
}

/* ── Fetch batch ── */
$br = mysqli_query($conn, "SELECT * FROM cheque_sent_batches WHERE id=$batch_id LIMIT 1");
$batch = $br ? mysqli_fetch_assoc($br) : null;
if (!$batch) {
    include 'header.php';
    echo '<div style="text-align:center;padding:80px;color:#dc2626;"><i class="fa-solid fa-triangle-exclamation" style="font-size:48px;display:block;margin-bottom:12px;"></i><h3>Batch Not Found</h3><p>The requested batch does not exist.</p><a href="sent_daily_cheques.php" style="color:#7c3aed;">← Back to Sent Cheques</a></div>';
    include 'footer.php';
    exit;
}

/* ── Fetch items ── */
$ir = mysqli_query($conn, "SELECT * FROM cheque_sent_items WHERE batch_id=$batch_id ORDER BY id ASC");
$items = [];
if ($ir) while ($row = mysqli_fetch_assoc($ir)) $items[] = $row;

$total_amt = 0;
foreach ($items as $item) $total_amt += floatval($item['total_amount']);

$sent_date = $batch['sent_date'];
$sent_date_fmt = '';
if ($sent_date && $sent_date !== '0000-00-00') {
    $dt = new DateTime($sent_date);
    $sent_date_fmt = $dt->format('d M Y');
}

$created_at_fmt = '';
if ($batch['created_at']) {
    $dt2 = new DateTime($batch['created_at']);
    $created_at_fmt = $dt2->format('d M Y H:i');
}

if (!$auto_print) {
    include 'header.php';
}
?>
<!DOCTYPE html>
<?php if ($auto_print): ?>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sent Document — <?=htmlspecialchars($batch['batch_code'])?></title>
<?php endif; ?>

<style>
*,*::before,*::after{box-sizing:border-box}

/* ═══ Print-optimized document styling ═══ */
.sent-doc{max-width:900px;margin:0 auto;font-family:'Inter','Segoe UI','Helvetica Neue',Arial,sans-serif;}

.doc-header{background:linear-gradient(135deg,#1e1b4b,#312e81);color:#fff;padding:24px 28px;border-radius:12px 12px 0 0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}
.doc-header-left h1{font-size:20px;font-weight:800;margin:0 0 4px;}
.doc-header-left p{font-size:12px;opacity:.7;margin:0;}
.doc-header-right{text-align:right;}
.doc-batch-code{background:rgba(255,255,255,.15);border-radius:8px;padding:8px 16px;font-family:'Courier New',monospace;font-size:16px;font-weight:800;letter-spacing:.04em;display:inline-block;margin-bottom:4px;}
.doc-date{font-size:12px;opacity:.8;}

.doc-body{background:#fff;border:1px solid #e5e5e5;border-top:none;padding:24px 28px;border-radius:0 0 12px 12px;}

.doc-info-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;padding:16px;background:#f5f3ff;border:1.5px solid #ddd6fe;border-radius:10px;}
.doc-info-item{display:flex;flex-direction:column;gap:3px;}
.doc-info-lbl{font-size:9px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.06em;}
.doc-info-val{font-size:14px;font-weight:700;color:#1e1b4b;}
.doc-info-val.mono{font-family:'Courier New',monospace;}

.doc-remark{background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;margin-bottom:20px;font-size:12px;color:#92400e;}
.doc-remark strong{font-weight:700;text-transform:uppercase;font-size:10px;letter-spacing:.04em;}

.doc-table{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:20px;}
.doc-table thead th{background:#1e1b4b;color:#e0e7ff;padding:10px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;border-right:1px solid rgba(255,255,255,.1);}
.doc-table thead th:last-child{border-right:none;}
.doc-table thead th.tr{text-align:right}
.doc-table thead th.tc{text-align:center}
.doc-table tbody td{padding:8px 10px;border-bottom:1px solid #f0f0f0;color:#374151;vertical-align:middle;}
.doc-table tbody tr:hover td{background:#faf5ff;}
.doc-table tbody tr:nth-child(even) td{background:#fafafa;}
.doc-table tfoot td{padding:10px;font-weight:800;font-size:12px;background:#f5f3ff;border-top:2px solid #c4b5fd;color:#4c1d95;}
.doc-table tfoot td.tr{text-align:right}
.doc-mono{font-family:'Courier New',monospace;font-weight:700;letter-spacing:.02em}
.doc-amt{font-weight:700;text-align:right;white-space:nowrap}
.doc-sr{background:#ede9fe;color:#5b21b6;padding:2px 7px;border-radius:6px;font-size:10px;font-weight:700;white-space:nowrap;}

.doc-footer{display:grid;grid-template-columns:1fr 1fr 1fr;gap:30px;margin-top:40px;padding-top:20px;border-top:1px dashed #d1d5db;}
.doc-sign-box{text-align:center;}
.doc-sign-line{border-bottom:1px solid #374151;margin-bottom:6px;height:40px;}
.doc-sign-label{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;}

.doc-nav{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.doc-nav .btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s;white-space:nowrap}
.btn-back{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-back:hover{background:#e8e8e8}
.btn-print{background:#7c3aed;color:#fff}.btn-print:hover{background:#6d28d9}
.btn-list{background:#0369a1;color:#fff}.btn-list:hover{background:#075985}

@media print{
    .doc-nav,.no-print{display:none!important}
    body{margin:0;padding:0;}
    .sent-doc{max-width:100%;margin:0;}
    .doc-header{background:#1e1b4b!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;border-radius:0;}
    .doc-body{border:none;padding:16px 20px;border-radius:0;}
    .doc-table thead th{background:#1e1b4b!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .doc-table tfoot td{background:#f5f3ff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .doc-info-grid{background:#f5f3ff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}
@media(max-width:768px){.doc-info-grid{grid-template-columns:1fr 1fr}.doc-footer{grid-template-columns:1fr}}
</style>

<?php if ($auto_print): ?>
</head><body style="margin:20px;">
<?php endif; ?>

<div class="sent-doc">
  <!-- Navigation (hidden on print) -->
  <div class="doc-nav no-print">
    <a href="sent_daily_cheques.php" class="btn btn-back"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
    <a href="cheques.php" class="btn btn-list"><i class="fa-solid fa-money-check"></i> Cheque Register</a>
    <button onclick="window.print()" class="btn btn-print"><i class="fa-solid fa-print"></i> Print Document</button>
  </div>

  <!-- Document Header -->
  <div class="doc-header">
    <div class="doc-header-left">
      <h1><i class="fa-solid fa-paper-plane"></i> Sent Cheques Document</h1>
      <p>Yelo Group — Cheque Dispatch Record</p>
    </div>
    <div class="doc-header-right">
      <div class="doc-batch-code"><?=htmlspecialchars($batch['batch_code'])?></div>
      <div class="doc-date"><?=$sent_date_fmt ?: '—'?></div>
    </div>
  </div>

  <!-- Document Body -->
  <div class="doc-body">
    <!-- Info Grid -->
    <div class="doc-info-grid">
      <div class="doc-info-item"><span class="doc-info-lbl">Batch Code</span><span class="doc-info-val mono"><?=htmlspecialchars($batch['batch_code'])?></span></div>
      <div class="doc-info-item"><span class="doc-info-lbl">Sent Date</span><span class="doc-info-val"><?=$sent_date_fmt ?: '—'?></span></div>
      <div class="doc-info-item"><span class="doc-info-lbl">Total Cheques</span><span class="doc-info-val"><?=count($items)?></span></div>
      <div class="doc-info-item"><span class="doc-info-lbl">Total Amount</span><span class="doc-info-val">Rs.&nbsp;<?=number_format($total_amt,2)?></span></div>
      <div class="doc-info-item"><span class="doc-info-lbl">Created By</span><span class="doc-info-val" style="font-size:12px;"><?=htmlspecialchars($batch['created_by'] ?? 'system')?></span></div>
      <div class="doc-info-item"><span class="doc-info-lbl">Created At</span><span class="doc-info-val" style="font-size:12px;"><?=$created_at_fmt ?: '—'?></span></div>
    </div>

    <?php if ($batch['remark']): ?>
    <div class="doc-remark"><strong><i class="fa-solid fa-comment-dots"></i> Remark:</strong> <?=htmlspecialchars($batch['remark'])?></div>
    <?php endif; ?>

    <!-- Cheque Items Table -->
    <table class="doc-table">
      <thead><tr>
        <th style="width:35px;">#</th>
        <th>Cheque No.</th>
        <th class="tc">Cheque Date</th>
        <th class="tc">SR Code</th>
        <th class="tc">Delivery Date</th>
        <th>T-Code</th>
        <th>Customer</th>
        <th>Bank</th>
        <th class="tc">Bank Code</th>
        <th class="tc">Branch Code</th>
        <th class="tr">Amount</th>
      </tr></thead>
      <tbody>
        <?php if (empty($items)): ?>
        <tr><td colspan="11" style="text-align:center;padding:30px;color:#9ca3af;">No cheques in this batch.</td></tr>
        <?php else: $n=0; $sum=0; foreach ($items as $item): $n++; $amt=floatval($item['total_amount']); $sum+=$amt; ?>
        <tr>
          <td style="color:#9ca3af;font-size:11px;font-weight:600;"><?=$n?></td>
          <td><span class="doc-mono" style="color:#4338ca;"><?=htmlspecialchars($item['cheque_no'] ?? '—')?></span></td>
          <td class="tc"><?php
            $cd = $item['cheque_date'] ?? '';
            if ($cd && $cd !== '0000-00-00') { $dtt = new DateTime($cd); echo '<span style="font-size:11.5px;">'.$dtt->format('d M Y').'</span>'; }
            else echo '<span style="color:#d1d5db;">—</span>';
          ?></td>
          <td class="tc"><span class="doc-sr"><?=htmlspecialchars($item['sr_code'] ?? '—')?></span></td>
          <td class="tc"><?php
            $dd = $item['delivery_date'] ?? '';
            if ($dd && $dd !== '0000-00-00') { $dtt2 = new DateTime($dd); echo '<span style="font-size:11.5px;">'.$dtt2->format('d M Y').'</span>'; }
            else echo '<span style="color:#d1d5db;">—</span>';
          ?></td>
          <td><span class="doc-mono" style="font-size:11px;color:#4338ca;"><?=htmlspecialchars($item['t_code'] ?? '—')?></span></td>
          <td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=htmlspecialchars($item['customer_name'] ?? '—')?></td>
          <td><?=htmlspecialchars($item['bank_name'] ?? '—')?><?php if($item['branch_name']): ?><div style="font-size:10px;color:#6b7280;"><?=htmlspecialchars($item['branch_name'])?></div><?php endif; ?></td>
          <td class="tc"><span class="doc-mono" style="font-size:11px;"><?=htmlspecialchars($item['bank_code'] ?? '—')?></span></td>
          <td class="tc"><span class="doc-mono" style="font-size:11px;"><?=htmlspecialchars($item['branch_code'] ?? '—')?></span></td>
          <td class="doc-amt">Rs.&nbsp;<?=number_format($amt,2)?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if (!empty($items)): ?>
      <tfoot><tr>
        <td colspan="10" style="font-weight:800;">TOTAL — <?=count($items)?> CHEQUES</td>
        <td class="tr" style="font-weight:800;font-size:13px;">Rs.&nbsp;<?=number_format($sum,2)?></td>
      </tr></tfoot>
      <?php endif; ?>
    </table>

    <!-- Signature Section -->
    <div class="doc-footer">
      <div class="doc-sign-box"><div class="doc-sign-line"></div><div class="doc-sign-label">Prepared By</div></div>
      <div class="doc-sign-box"><div class="doc-sign-line"></div><div class="doc-sign-label">Checked By</div></div>
      <div class="doc-sign-box"><div class="doc-sign-line"></div><div class="doc-sign-label">Received By</div></div>
    </div>
  </div>
</div>

<?php if ($auto_print): ?>
<script>window.onload=function(){setTimeout(function(){window.print();},500);};</script>
</body></html>
<?php else: ?>
<?php include 'footer.php'; ?>
<?php endif; ?>
