<?php
include 'config.php';

$id     = (int)($_GET['id'] ?? 0);
/* AJAX fragment mode: called via fetch() from ushop_invoice_list.php (row expand).
   Standalone mode (default when opened/linked directly): full page with header/footer/breadcrumb. */
$isAjax = (isset($_GET['ajax']) && $_GET['ajax'] === '1')
       || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

function ushop_detail_not_found($isAjax, $msg) {
    if ($isAjax) { echo '<span style="color:red;">'.htmlspecialchars($msg).'</span>'; exit; }
    include 'header.php';
    echo '<div style="max-width:640px;margin:60px auto;text-align:center;">
            <p style="color:#dc2626;font-weight:700;font-size:14px;">'.htmlspecialchars($msg).'</p>
            <a href="ushop_invoice_list.php" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none;background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;margin-top:10px;"><i class="fa-solid fa-arrow-left"></i> Back to Invoices</a>
          </div>';
    include 'footer.php';
    exit;
}

if (!$id) ushop_detail_not_found($isAjax, 'Invalid invoice ID.');

$inv = mysqli_fetch_assoc(mysqli_query($conn, "
  SELECT inv.*, imp.filename, imp.import_date AS imp_date
  FROM ushop_invoices inv
  LEFT JOIN ushop_invoice_imports imp ON imp.id = inv.import_id
  WHERE inv.id=$id
"));

if (!$inv) ushop_detail_not_found($isAjax, 'Invoice not found (it may have been deleted).');

$items = mysqli_query($conn,"SELECT * FROM ushop_invoice_items WHERE invoice_id=$id ORDER BY id");
$pays  = mysqli_query($conn,"SELECT * FROM ushop_invoice_payments WHERE invoice_id=$id ORDER BY id");

if (!$isAjax) include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.mini-table{width:100%;border-collapse:collapse;font-size:11.5px;margin-bottom:10px;}
.mini-table th{padding:6px 8px;background:#f1f5f9;border-bottom:1px solid #e2e8f0;color:#374151;font-size:10.5px;text-transform:uppercase;text-align:left;}
.mini-table td{padding:5px 8px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
.mini-table tr:last-child td{border-bottom:none;}
.tr{text-align:right!important;}
.ucode{display:inline-block;background:#dbeafe;color:#1e40af;padding:1px 6px;border-radius:4px;font-size:10.5px;font-weight:700;font-family:monospace;}
.pay-CREDIT{background:#fef3c7;color:#92400e;}
.pay-VISA{background:#dbeafe;color:#1e40af;}
.pay-CASH{background:#dcfce7;color:#15803d;}
.pay-OTHER{background:#f3f4f6;color:#374151;}
.pay-chip{display:inline-flex;align-items:center;gap:3px;padding:2px 10px;border-radius:20px;font-size:11.5px;font-weight:700;margin:2px;}
.sec-label{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;margin-top:4px;}
<?php if (!$isAjax): ?>
.page-wrap{max-width:1100px;margin:0 auto;padding:0 8px;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:14px;flex-wrap:wrap;}
.breadcrumb a{color:#0e7490;text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-teal{background:#0e7490;color:#fff;}.btn-teal:hover{background:#155e75;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-sm{padding:5px 10px;font-size:11.5px;}
.ph-row{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.summary-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 22px;margin-bottom:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.summary-grid{display:flex;flex-wrap:wrap;gap:22px;}
.summary-item{min-width:140px;}
.summary-item .lbl{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;}
.summary-item .val{font-size:14px;font-weight:700;color:#111827;}
.detail-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 22px;}
<?php endif; ?>
</style>

<?php if (!$isAjax): ?>
<div class="page-wrap">
<div class="breadcrumb">
  <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
  <span class="sep">›</span>
  <a href="ushop_invoice_import.php"><i class="fa-solid fa-file-invoice"></i> Invoice Import</a>
  <span class="sep">›</span>
  <a href="ushop_invoice_history.php"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
  <span class="sep">›</span>
  <a href="ushop_invoice_list.php<?= $inv['import_id'] ? '?import_id='.(int)$inv['import_id'] : '' ?>"><i class="fa-solid fa-receipt"></i> Invoices</a>
  <span class="sep">›</span>
  <span style="color:#0e7490;font-weight:700;">Invoice #<?= (int)$inv['id'] ?></span>
</div>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:19px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-file-invoice" style="color:#0e7490;margin-right:8px;"></i>Invoice <?= htmlspecialchars($inv['doc_no']) ?>
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">
      <?= $inv['invoice_date'] ? date('d M Y', strtotime($inv['invoice_date'])) : 'No date' ?>
      <?php if ($inv['cashier']): ?> &nbsp;•&nbsp; Cashier: <?= htmlspecialchars($inv['cashier']) ?><?php endif; ?>
      <?php if ($inv['filename']): ?> &nbsp;•&nbsp; Import: <?= htmlspecialchars($inv['filename']) ?><?php endif; ?>
    </p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="ushop_invoice_list.php<?= $inv['import_id'] ? '?import_id='.(int)$inv['import_id'] : '' ?>" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to Invoices</a>
    <a href="ushop_invoice_history.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
  </div>
</div>

<div class="summary-card">
  <div class="summary-grid">
    <div class="summary-item"><div class="lbl">Doc No</div><div class="val"><?= htmlspecialchars($inv['doc_no']) ?></div></div>
    <div class="summary-item"><div class="lbl">Unique Invoice No</div><div class="val"><?= htmlspecialchars($inv['unique_inv_no'] ?: '—') ?></div></div>
    <div class="summary-item"><div class="lbl">Customer</div><div class="val"><?= $inv['customer_name'] ? htmlspecialchars($inv['customer_name']) : 'Walk-in' ?><?= $inv['customer_code'] ? ' <span style="color:#0e7490;">#'.htmlspecialchars($inv['customer_code']).'</span>' : '' ?></div></div>
    <div class="summary-item"><div class="lbl">Total Qty</div><div class="val"><?= number_format($inv['total_qty']) ?></div></div>
    <div class="summary-item"><div class="lbl">Total Discount</div><div class="val" style="color:#92400e;"><?= number_format($inv['total_discount'],2) ?></div></div>
    <div class="summary-item"><div class="lbl">Total Amount</div><div class="val" style="color:#15803d;"><?= number_format($inv['total_amount'],2) ?></div></div>
  </div>
</div>

<div class="detail-card">
<?php endif; ?>

<?php if (mysqli_num_rows($items) > 0): ?>
<div class="sec-label"><i class="fa-solid fa-boxes-stacked" style="margin-right:4px;color:#0e7490;"></i>Items (<?= mysqli_num_rows($items) ?>)</div>
<table class="mini-table">
  <thead>
    <tr>
      <th>Product Code</th><th>Product Name</th><th>Unilever Code</th>
      <th class="tr">Price</th><th class="tr">Qty</th><th class="tr">Discount</th><th class="tr">Amount</th>
    </tr>
  </thead>
  <tbody>
  <?php while($it=mysqli_fetch_assoc($items)): ?>
  <tr>
    <td style="font-family:monospace;font-size:10.5px;color:#6b7280;"><?= htmlspecialchars($it['product_code']) ?></td>
    <td><?= htmlspecialchars($it['product_name']) ?></td>
    <td><?= $it['unilever_code'] ? '<span class="ucode">'.htmlspecialchars($it['unilever_code']).'</span>' : '<span style="color:#d1d5db;">—</span>' ?></td>
    <td class="tr"><?= number_format($it['price'],2) ?></td>
    <td class="tr"><strong><?= number_format($it['qty'],0) ?></strong></td>
    <td class="tr" style="color:#92400e;"><?= number_format($it['discount'],2) ?></td>
    <td class="tr" style="font-weight:700;color:#15803d;"><?= number_format($it['amount'],2) ?></td>
  </tr>
  <?php endwhile; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if (mysqli_num_rows($pays) > 0): ?>
<div class="sec-label" style="margin-top:8px;"><i class="fa-solid fa-credit-card" style="margin-right:4px;color:#0e7490;"></i>Payments</div>
<div style="display:flex;flex-wrap:wrap;gap:6px;padding-bottom:6px;">
<?php while($py=mysqli_fetch_assoc($pays)):
  $cls = str_contains($py['pay_type'],'CREDIT')?'CREDIT':(str_contains($py['pay_type'],'VISA')?'VISA':(str_contains($py['pay_type'],'CASH')?'CASH':'OTHER'));
?>
  <span class="pay-chip pay-<?= $cls ?>">
    <i class="fa-solid fa-<?= $cls==='CREDIT'?'file-invoice-dollar':($cls==='VISA'?'credit-card':'money-bill-wave') ?>"></i>
    <?= htmlspecialchars($py['pay_type']) ?>: <strong><?= number_format($py['amount'],2) ?></strong>
  </span>
<?php endwhile; ?>
</div>
<?php endif; ?>

<?php if (mysqli_num_rows($items) === 0 && mysqli_num_rows($pays) === 0): ?>
<p style="color:#9ca3af;font-size:12.5px;text-align:center;padding:20px 0;">No items or payments recorded for this invoice.</p>
<?php endif; ?>

<?php if (!$isAjax): ?>
</div><!-- .detail-card -->
</div><!-- .page-wrap -->
<?php include 'footer.php'; ?>
<?php endif; ?>