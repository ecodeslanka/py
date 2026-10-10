<?php
include 'config.php';

/* ── Filters ── */
$f_q         = trim($_GET['q']         ?? '');
$f_cust      = trim($_GET['cust']      ?? '');
$f_ptype     = trim($_GET['ptype']     ?? '');
$f_import_id = trim($_GET['import_id'] ?? '');
$f_date_from = trim($_GET['from']      ?? '');
$f_date_to   = trim($_GET['to']        ?? '');

$where = ['1=1'];
if ($f_q)         $where[] = "(inv.doc_no LIKE '%".mysqli_real_escape_string($conn,$f_q)."%' OR inv.unique_inv_no LIKE '%".mysqli_real_escape_string($conn,$f_q)."%')";
if ($f_cust)      $where[] = "(inv.customer_name LIKE '%".mysqli_real_escape_string($conn,$f_cust)."%' OR inv.customer_code LIKE '%".mysqli_real_escape_string($conn,$f_cust)."%')";
if ($f_ptype)     $where[] = "inv.id IN (SELECT invoice_id FROM ushop_invoice_payments WHERE pay_type LIKE '%".mysqli_real_escape_string($conn,$f_ptype)."%')";
if ($f_import_id && is_numeric($f_import_id)) $where[] = "inv.import_id=".intval($f_import_id);
if ($f_date_from) $where[] = "inv.invoice_date>='".mysqli_real_escape_string($conn,$f_date_from)."'";
if ($f_date_to)   $where[] = "inv.invoice_date<='".mysqli_real_escape_string($conn,$f_date_to)."'";
$wsql = implode(' AND ',$where);

/* ── Pagination ── */
$page   = max(1,(int)($_GET['page']??1));
$per    = 30;
$offset = ($page-1)*$per;
$total  = (int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM ushop_invoices inv WHERE $wsql"))['c'];
$pages  = max(1,ceil($total/$per));

$rows = mysqli_query($conn,"
  SELECT inv.*, imp.filename, imp.import_date as imp_date,
         pay.payment_types
  FROM ushop_invoices inv
  LEFT JOIN ushop_invoice_imports imp ON imp.id=inv.import_id
  LEFT JOIN (
    SELECT invoice_id, GROUP_CONCAT(DISTINCT pay_type ORDER BY pay_type SEPARATOR ', ') AS payment_types
    FROM ushop_invoice_payments
    GROUP BY invoice_id
  ) pay ON pay.invoice_id = inv.id
  WHERE $wsql
  ORDER BY inv.invoice_date DESC, inv.id DESC
  LIMIT $per OFFSET $offset
");

/* ── Summary ── */
$agg = mysqli_fetch_assoc(mysqli_query($conn,"
  SELECT COUNT(*) cnt,
         COALESCE(SUM(inv.total_discount),0) sum_disc,
         COALESCE(SUM(inv.total_amount),0) sum_amt
  FROM ushop_invoices inv WHERE $wsql
")) ?: [];

/* ── Import filter label ── */
$import_label = '';
if ($f_import_id) {
    $imp_r = mysqli_fetch_assoc(mysqli_query($conn,"SELECT filename FROM ushop_invoice_imports WHERE id=".intval($f_import_id)));
    $import_label = $imp_r['filename'] ?? '';
}

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.page-wrap{max-width:1400px;margin:0 auto;padding:0 8px;}
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
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 20px;flex:1;min-width:130px;box-shadow:0 1px 4px rgba(0,0,0,.04);border-top:3px solid #0e7490;}
.sum-card-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.sum-card-val{font-size:20px;font-weight:700;color:#0e7490;}
.filter-bar{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;}
.filter-group{display:flex;flex-direction:column;gap:4px;}
.filter-group label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;}
.filter-group input,.filter-group select{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;font-family:inherit;color:#111827;outline:none;min-width:130px;}
.filter-group input:focus,.filter-group select:focus{border-color:#0e7490;}
.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table th{padding:10px 12px;text-align:left;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:11px;text-transform:uppercase;white-space:nowrap;}
.data-table td{padding:0;border-bottom:1px solid #f3f4f6;color:#111827;vertical-align:top;}
.data-table .td-pad{padding:9px 12px;}
.data-table tr.main-row:hover .td-pad{background:#f9fafb;}
.tr{text-align:right!important;}.tc{text-align:center!important;}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.badge-teal{background:#cffafe;color:#0e7490;}
.badge-blue{background:#dbeafe;color:#1e40af;}
.badge-green{background:#dcfce7;color:#15803d;}
.badge-orange{background:#fef3c7;color:#92400e;}
.badge-gray{background:#f3f4f6;color:#6b7280;}
/* Sub rows */
.sub-row{display:none;background:#f9fafb;}
.sub-row.open{display:table-row;}
.sub-cell{padding:0 12px 10px 30px!important;border-bottom:1px solid #f3f4f6;}
.mini-table{width:100%;border-collapse:collapse;font-size:11.5px;}
.mini-table th{padding:6px 8px;background:#f1f5f9;border-bottom:1px solid #e2e8f0;color:#374151;font-size:10.5px;text-transform:uppercase;}
.mini-table td{padding:5px 8px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
.mini-table tr:last-child td{border-bottom:none;}
.ucode{display:inline-block;background:#dbeafe;color:#1e40af;padding:1px 6px;border-radius:4px;font-size:10.5px;font-weight:700;font-family:monospace;}
.expand-btn{cursor:pointer;background:none;border:none;color:#0e7490;font-size:11px;padding:0 4px;}
.pay-chip{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;margin:1px;}
.pay-CREDIT{background:#fef3c7;color:#92400e;}
.pay-VISA{background:#dbeafe;color:#1e40af;}
.pay-CASH{background:#dcfce7;color:#15803d;}
.pay-OTHER{background:#f3f4f6;color:#374151;}
.pagination{display:flex;align-items:center;gap:6px;padding:14px 18px;justify-content:flex-end;flex-wrap:wrap;}
.pagination a,.pagination span{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;}
.pagination a{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;}.pagination a:hover{background:#e5e7eb;}
.pagination .active{background:#0e7490;color:#fff;border-color:#0e7490;}
.imp-badge{display:inline-block;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;margin-bottom:12px;}
</style>

<div class="page-wrap">
<div class="breadcrumb">
  <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
  <span class="sep">›</span>
  <a href="ushop_invoice_import.php"><i class="fa-solid fa-file-invoice"></i> Invoice Import</a>
  <span class="sep">›</span>
  <a href="ushop_invoice_history.php"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
  <span class="sep">›</span>
  <span style="color:#0e7490;font-weight:700;"><i class="fa-solid fa-receipt"></i> Invoices</span>
</div>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:19px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-receipt" style="color:#0e7490;margin-right:8px;"></i>UShop POS Invoices
    </h2>
    <?php if($import_label): ?>
    <div class="imp-badge" style="margin-top:6px;"><i class="fa-solid fa-filter"></i> Filtered: <?= htmlspecialchars($import_label) ?></div>
    <?php endif; ?>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Click any row to expand items &amp; payments. Click customer code badge to filter.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="ushop_invoice_import.php" class="btn btn-teal"><i class="fa-solid fa-upload"></i> Import</a>
    <a href="ushop_invoice_history.php" class="btn btn-secondary"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
    <a href="ushop_stock_history.php" class="btn btn-secondary"><i class="fa-solid fa-layer-group"></i> Stock</a>
  </div>
</div>

<div class="sum-cards">
  <div class="sum-card"><div class="sum-card-label">Invoices</div><div class="sum-card-val"><?= number_format($agg['cnt']??0) ?></div></div>
  <div class="sum-card"><div class="sum-card-label">Total Discount</div><div class="sum-card-val" style="color:#92400e;"><?= number_format($agg['sum_disc']??0,2) ?></div></div>
  <div class="sum-card"><div class="sum-card-label">Total Amount</div><div class="sum-card-val" style="color:#15803d;"><?= number_format($agg['sum_amt']??0,2) ?></div></div>
</div>

<form method="GET" class="filter-bar">
  <?php if($f_import_id): ?><input type="hidden" name="import_id" value="<?= (int)$f_import_id ?>"> <?php endif; ?>
  <div class="filter-group"><label>Doc / Invoice No</label><input type="text" name="q" value="<?= htmlspecialchars($f_q) ?>" placeholder="Doc or unique no…"></div>
  <div class="filter-group"><label>Customer</label><input type="text" name="cust" value="<?= htmlspecialchars($f_cust) ?>" placeholder="Name or code…"></div>
  <div class="filter-group"><label>Payment Type</label>
    <select name="ptype">
      <option value="">All</option>
      <option value="CREDIT" <?= $f_ptype==='CREDIT'?'selected':'' ?>>Credit</option>
      <option value="VISA" <?= $f_ptype==='VISA'?'selected':'' ?>>Visa Card</option>
      <option value="CASH" <?= $f_ptype==='CASH'?'selected':'' ?>>Cash</option>
    </select>
  </div>
  <div class="filter-group"><label>From</label><input type="date" name="from" value="<?= htmlspecialchars($f_date_from) ?>"></div>
  <div class="filter-group"><label>To</label><input type="date" name="to" value="<?= htmlspecialchars($f_date_to) ?>"></div>
  <div style="display:flex;gap:6px;align-items:flex-end;">
    <button type="submit" class="btn btn-teal btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
    <a href="ushop_invoice_list.php<?= $f_import_id?"?import_id=$f_import_id":'' ?>" class="btn btn-secondary btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
  </div>
</form>

<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title"><i class="fa-solid fa-receipt" style="margin-right:6px;color:#0e7490;"></i>Invoices
      <span style="font-size:11px;color:#9ca3af;font-weight:400;margin-left:8px;"><?= number_format($total) ?> record<?= $total!=1?'s':'' ?></span>
    </div>
    <span style="font-size:11.5px;color:#6b7280;"><i class="fa-solid fa-circle-info"></i> Click row to expand items</span>
  </div>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th style="width:32px;"></th>
        <th>#</th><th>Date</th><th>Doc No</th><th>Unique Invoice No</th>
        <th>Customer</th><th>Code</th><th>Payment</th>
        <th class="tr">Discount</th><th class="tr">Amount</th><th>Batch</th>
      </tr>
    </thead>
    <tbody>
    <?php $i=$offset+1; while($r=mysqli_fetch_assoc($rows)):
      $payTypesStr = $r['payment_types'] ?: '';
      $payClass = str_contains($payTypesStr,'CREDIT') ? 'CREDIT' : (str_contains($payTypesStr,'VISA') ? 'VISA' : (str_contains($payTypesStr,'CASH') ? 'CASH' : 'OTHER'));
    ?>
    <tr class="main-row" onclick="toggleRow(<?= $r['id'] ?>)" style="cursor:pointer;">
      <td class="td-pad tc"><i class="fa-solid fa-chevron-right" id="ch<?= $r['id'] ?>" style="font-size:10px;color:#9ca3af;transition:transform .2s;"></i></td>
      <td class="td-pad" style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
      <td class="td-pad" style="white-space:nowrap;"><span class="badge badge-teal"><?= date('d M Y',strtotime($r['invoice_date'])) ?></span></td>
      <td class="td-pad" style="font-weight:700;color:#0e7490;">
        <?= htmlspecialchars($r['doc_no']) ?>
        <a href="ushop_invoice_detail.php?id=<?= $r['id'] ?>" target="_blank" onclick="event.stopPropagation();" title="Open full invoice page" style="margin-left:6px;color:#9ca3af;font-size:10px;font-weight:400;text-decoration:none;"><i class="fa-solid fa-up-right-from-square"></i></a>
      </td>
      <td class="td-pad" style="font-size:11px;font-family:monospace;color:#6b7280;"><?= htmlspecialchars($r['unique_inv_no']?:'—') ?></td>
      <td class="td-pad"><?= $r['customer_name'] ? htmlspecialchars($r['customer_name']) : '<span style="color:#9ca3af;font-size:11px;">Walk-in</span>' ?></td>
      <td class="td-pad"><?= $r['customer_code'] ? '<span class="badge badge-blue">#'.htmlspecialchars($r['customer_code']).'</span>' : '<span style="color:#d1d5db;">—</span>' ?></td>
      <td class="td-pad"><?= $payTypesStr ? '<span class="pay-chip pay-'.$payClass.'">'.htmlspecialchars($payTypesStr).'</span>' : '<span style="color:#d1d5db;">—</span>' ?></td>
      <td class="td-pad tr" style="color:#92400e;"><?= number_format($r['total_discount'],2) ?></td>
      <td class="td-pad tr" style="font-weight:700;color:#15803d;"><?= number_format($r['total_amount'],2) ?></td>
      <td class="td-pad" style="font-size:10.5px;color:#9ca3af;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($r['filename']??'') ?>"><?= htmlspecialchars($r['filename']?:'—') ?></td>
    </tr>
    <tr class="sub-row" id="sub<?= $r['id'] ?>">
      <td class="sub-cell" colspan="11">
        <div id="subContent<?= $r['id'] ?>" style="padding:6px 0;">
          <span style="color:#9ca3af;font-size:11.5px;">Loading…</span>
        </div>
      </td>
    </tr>
    <?php endwhile; ?>
    <?php if($total===0): ?>
    <tr><td colspan="11" style="text-align:center;padding:44px;color:#9ca3af;font-size:13px;">No invoices found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
  <?php if($pages>1): ?>
  <div class="pagination">
    <span style="font-size:12px;color:#6b7280;margin-right:6px;">Page <?= $page ?> of <?= $pages ?></span>
    <?php if($page>1): ?>
      <a href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>">&laquo;</a>
      <a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>">&lsaquo;</a>
    <?php endif; ?>
    <?php for($p=max(1,$page-3);$p<=min($pages,$page+3);$p++): ?>
      <?php if($p===$page): ?><span class="active"><?= $p ?></span>
      <?php else: ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$p])) ?>"><?= $p ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if($page<$pages): ?>
      <a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>">&rsaquo;</a>
      <a href="?<?= http_build_query(array_merge($_GET,['page'=>$pages])) ?>">&raquo;</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
</div>

<script>
const openRows = {};
async function toggleRow(id){
  const sub  = document.getElementById('sub'+id);
  const ch   = document.getElementById('ch'+id);
  const cont = document.getElementById('subContent'+id);
  if (openRows[id]) {
    sub.classList.remove('open');
    ch.style.transform='';
    openRows[id]=false;
    return;
  }
  sub.classList.add('open');
  ch.style.transform='rotate(90deg)';
  openRows[id]=true;
  if (cont.dataset.loaded) return;
  cont.dataset.loaded='1';
  const resp = await fetch('ushop_invoice_detail.php?id='+id+'&ajax=1');
  cont.innerHTML = await resp.text();
}
</script>

<?php include 'footer.php'; ?>