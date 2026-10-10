<?php
include 'config.php';

/* ── Delete ── */
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    mysqli_query($conn, "DELETE FROM ushop_invoice_imports WHERE id=$del_id");
    header('Location: ushop_invoice_history.php?deleted=1');
    exit;
}

/* ── Filters ── */
$f_q     = trim($_GET['q']     ?? '');
$f_month = trim($_GET['month'] ?? '');
$f_date  = trim($_GET['date']  ?? '');

$where = ['1=1'];
if ($f_q)     $where[] = "(filename LIKE '%".mysqli_real_escape_string($conn,$f_q)."%' OR note LIKE '%".mysqli_real_escape_string($conn,$f_q)."%')";
if ($f_date)  $where[] = "import_date='".mysqli_real_escape_string($conn,$f_date)."'";
if ($f_month) $where[] = "DATE_FORMAT(import_date,'%Y-%m')='".mysqli_real_escape_string($conn,$f_month)."'";
$wsql = implode(' AND ', $where);

/* ── Pagination ── */
$page    = max(1,(int)($_GET['page']??1));
$per     = 25;
$offset  = ($page-1)*$per;
$total   = (int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM ushop_invoice_imports WHERE $wsql"))['c'];
$pages   = max(1,ceil($total/$per));

$rows = mysqli_query($conn,"SELECT * FROM ushop_invoice_imports WHERE $wsql ORDER BY imported_at DESC LIMIT $per OFFSET $offset");

/* ── Aggregates ── */
$agg = mysqli_fetch_assoc(mysqli_query($conn,"
  SELECT COUNT(*) cnt,
         COALESCE(SUM(total_invoices),0) sum_inv,
         COALESCE(SUM(total_items),0) sum_items,
         COALESCE(SUM(total_amount),0) sum_amt
  FROM ushop_invoice_imports WHERE $wsql
")) ?: [];

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.page-wrap{max-width:1280px;margin:0 auto;padding:0 8px;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:14px;flex-wrap:wrap;}
.breadcrumb a{color:#0e7490;text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}
.ph-row{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-teal{background:#0e7490;color:#fff;}.btn-teal:hover{background:#155e75;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-green{background:#15803d;color:#fff;}.btn-green:hover{background:#166534;}
.btn-purple{background:#7c3aed;color:#fff;}.btn-purple:hover{background:#6d28d9;}
.btn-sm{padding:5px 10px;font-size:11.5px;}
.sum-cards{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:18px;}
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 20px;flex:1;min-width:140px;box-shadow:0 1px 4px rgba(0,0,0,.04);border-top:3px solid #0e7490;}
.sum-card-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.sum-card-val{font-size:20px;font-weight:700;color:#0e7490;}
.filter-bar{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;}
.filter-group{display:flex;flex-direction:column;gap:4px;}
.filter-group label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;}
.filter-group input{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;font-family:inherit;color:#111827;outline:none;min-width:140px;}
.filter-group input:focus{border-color:#0e7490;}
.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table th{padding:10px 12px;text-align:left;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:11px;text-transform:uppercase;white-space:nowrap;}
.data-table td{padding:9px 12px;border-bottom:1px solid #f3f4f6;color:#111827;vertical-align:middle;}
.data-table tr:hover td{background:#f9fafb;}
.tr{text-align:right!important;}.tc{text-align:center!important;}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.badge-teal{background:#cffafe;color:#0e7490;}
.badge-green{background:#dcfce7;color:#15803d;}
.pagination{display:flex;align-items:center;gap:6px;padding:14px 18px;justify-content:flex-end;flex-wrap:wrap;}
.pagination a,.pagination span{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;}
.pagination a{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;}.pagination a:hover{background:#e5e7eb;}
.pagination .active{background:#0e7490;color:#fff;border-color:#0e7490;}
.alert{padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:16px;}
.alert-ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
</style>

<div class="page-wrap">
<div class="breadcrumb">
  <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
  <span class="sep">›</span>
  <a href="ushop_invoice_import.php"><i class="fa-solid fa-file-invoice"></i> Invoice Import</a>
  <span class="sep">›</span>
  <span style="color:#0e7490;font-weight:700;"><i class="fa-solid fa-clock-rotate-left"></i> Import History</span>
</div>

<?php if(isset($_GET['deleted'])): ?>
<div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i> Import deleted. All related invoices, items, payments and stock history removed.</div>
<?php endif; ?>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:19px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-clock-rotate-left" style="color:#0e7490;margin-right:8px;"></i>Invoice Import History
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">All POS invoice imports. Deleting removes all invoices, items, payments and stock history for that batch.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="ushop_invoice_import.php" class="btn btn-teal"><i class="fa-solid fa-upload"></i> New Import</a>
    <a href="ushop_invoice_list.php" class="btn btn-secondary"><i class="fa-solid fa-receipt"></i> All Invoices</a>
    <a href="ushop_stock_history.php" class="btn btn-secondary"><i class="fa-solid fa-layer-group"></i> Stock History</a>
  </div>
</div>

<div class="sum-cards">
  <div class="sum-card"><div class="sum-card-label">Imports</div><div class="sum-card-val"><?= number_format($agg['cnt']??0) ?></div></div>
  <div class="sum-card"><div class="sum-card-label">Invoices</div><div class="sum-card-val"><?= number_format($agg['sum_inv']??0) ?></div></div>
  <div class="sum-card"><div class="sum-card-label">Line Items</div><div class="sum-card-val"><?= number_format($agg['sum_items']??0) ?></div></div>
  <div class="sum-card"><div class="sum-card-label">Total Amount</div><div class="sum-card-val" style="font-size:15px;"><?= number_format($agg['sum_amt']??0,2) ?></div></div>
</div>

<form method="GET" class="filter-bar">
  <div class="filter-group"><label>Search</label><input type="text" name="q" value="<?= htmlspecialchars($f_q) ?>" placeholder="Filename or note…"></div>
  <div class="filter-group"><label>Date</label><input type="date" name="date" value="<?= htmlspecialchars($f_date) ?>"></div>
  <div class="filter-group"><label>Month</label><input type="month" name="month" value="<?= htmlspecialchars($f_month) ?>"></div>
  <div style="display:flex;gap:6px;align-items:flex-end;">
    <button type="submit" class="btn btn-teal btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
    <a href="ushop_invoice_history.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
  </div>
</form>

<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title"><i class="fa-solid fa-list" style="margin-right:6px;color:#0e7490;"></i>Import Batches
      <span style="font-size:11px;color:#9ca3af;font-weight:400;margin-left:8px;"><?= number_format($total) ?> record<?= $total!=1?'s':'' ?></span>
    </div>
  </div>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>#</th><th>Import Date</th><th>Filename</th><th>Date Range</th>
        <th class="tr">Invoices</th><th class="tr">Items</th><th class="tr">Amount</th>
        <th>Note</th><th>Imported At</th><th class="tc">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php $i=$offset+1; while($r=mysqli_fetch_assoc($rows)): ?>
    <tr>
      <td style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
      <td><span class="badge badge-teal"><?= date('d M Y',strtotime($r['import_date'])) ?></span></td>
      <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($r['filename']) ?>">
        <i class="fa-solid fa-file-excel" style="color:#22c55e;margin-right:5px;"></i><?= htmlspecialchars($r['filename']) ?>
      </td>
      <td style="font-size:11px;white-space:nowrap;color:#6b7280;">
        <?= $r['date_from'] ? date('d M Y',strtotime($r['date_from'])).' → '.date('d M Y',strtotime($r['date_to'])) : '—' ?>
      </td>
      <td class="tr"><span class="badge badge-teal"><?= number_format($r['total_invoices']) ?></span></td>
      <td class="tr"><strong><?= number_format($r['total_items']) ?></strong></td>
      <td class="tr" style="font-weight:700;color:#15803d;"><?= number_format($r['total_amount'],2) ?></td>
      <td style="font-size:11.5px;color:#6b7280;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($r['note']?:'—') ?></td>
      <td style="font-size:11.5px;white-space:nowrap;"><?= date('d M Y H:i',strtotime($r['imported_at'])) ?></td>
      <td class="tc" style="white-space:nowrap;">
        <a href="ushop_invoice_list.php?import_id=<?= $r['id'] ?>" class="btn btn-teal btn-sm" title="View invoices"><i class="fa-solid fa-receipt"></i></a>
        <a href="ushop_stock_history.php?import_id=<?= $r['id'] ?>" class="btn btn-green btn-sm" title="Stock history"><i class="fa-solid fa-layer-group"></i></a>
        <a href="ushop_invoice_history.php?delete=<?= $r['id'] ?>&<?= http_build_query(['q'=>$f_q,'date'=>$f_date,'month'=>$f_month,'page'=>$page]) ?>"
           class="btn btn-danger btn-sm"
           onclick="return confirm('Delete import #<?= $r['id'] ?> (<?= addslashes(htmlspecialchars($r['filename'])) ?>)?\n\nThis will permanently delete ALL invoices, items, payments, and stock history for this import.')"
           title="Delete import">
          <i class="fa-solid fa-trash"></i>
        </a>
      </td>
    </tr>
    <?php endwhile; ?>
    <?php if($total===0): ?>
    <tr><td colspan="10" style="text-align:center;padding:44px;color:#9ca3af;font-size:13px;">No import records found<?= $f_q||$f_date||$f_month?' matching your filters':'' ?>.</td></tr>
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
<?php include 'footer.php'; ?>
