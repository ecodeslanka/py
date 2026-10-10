<?php
include 'config.php';

/* ── Delete handler ── */
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    // FK CASCADE will delete stock_history rows automatically
    mysqli_query($conn, "DELETE FROM ushop_items_imports WHERE id=$del_id");
    header('Location: ushop_items_import_history.php?deleted=1');
    exit;
}

/* ── Filters ── */
$where   = ['1=1'];
$f_date  = trim($_GET['date']  ?? '');
$f_q     = trim($_GET['q']     ?? '');
$f_month = trim($_GET['month'] ?? '');

if ($f_date)  $where[] = "import_date='".mysqli_real_escape_string($conn,$f_date)."'";
if ($f_month) $where[] = "DATE_FORMAT(import_date,'%Y-%m')='".mysqli_real_escape_string($conn,$f_month)."'";
if ($f_q)     $where[] = "(filename LIKE '%".mysqli_real_escape_string($conn,$f_q)."%' OR note LIKE '%".mysqli_real_escape_string($conn,$f_q)."%')";

$where_sql = implode(' AND ', $where);

/* ── Pagination ── */
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset   = ($page - 1) * $per_page;
$total_q  = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) AS c FROM ushop_items_imports WHERE $where_sql"));
$total    = (int)$total_q['c'];
$pages    = max(1, ceil($total / $per_page));

$rows = mysqli_query($conn,"SELECT * FROM ushop_items_imports WHERE $where_sql ORDER BY imported_at DESC LIMIT $per_page OFFSET $offset");

/* ── Aggregates ── */
$agg = mysqli_fetch_assoc(mysqli_query($conn,"
  SELECT COUNT(*) AS cnt,
         COALESCE(SUM(total_rows),0)    AS sum_rows,
         COALESCE(SUM(new_items),0)     AS sum_new,
         COALESCE(SUM(updated_items),0) AS sum_upd,
         COALESCE(SUM(skipped_rows),0)  AS sum_skip
  FROM ushop_items_imports WHERE $where_sql
")) ?: [];

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.page-wrap{max-width:1280px;margin:0 auto;padding:0 8px;}
.ph-row{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:14px;flex-wrap:wrap;}
.breadcrumb a{color:#0e7490;text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-teal{background:#0e7490;color:#fff;}.btn-teal:hover{background:#155e75;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-green{background:#15803d;color:#fff;}.btn-green:hover{background:#166534;}
.btn-sm{padding:5px 10px;font-size:11.5px;}

.sum-cards{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:18px;}
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 20px;flex:1;min-width:140px;box-shadow:0 1px 4px rgba(0,0,0,.04);border-top:3px solid #0e7490;}
.sum-card-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.sum-card-val{font-size:20px;font-weight:700;color:#0e7490;}

.filter-bar{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;}
.filter-group{display:flex;flex-direction:column;gap:4px;min-width:170px;}
.filter-group label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;}
.filter-group input,.filter-group select{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;font-family:inherit;color:#111827;outline:none;}
.filter-group input:focus,.filter-group select:focus{border-color:#0e7490;}

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
.badge-orange{background:#fef3c7;color:#92400e;}
.badge-gray{background:#f3f4f6;color:#6b7280;}

.pagination{display:flex;align-items:center;gap:6px;padding:14px 18px;justify-content:flex-end;flex-wrap:wrap;}
.pagination a,.pagination span{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;}
.pagination a{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;}.pagination a:hover{background:#e5e7eb;}
.pagination .active{background:#0e7490;color:#fff;border-color:#0e7490;}
.pagination .disabled{color:#d1d5db;cursor:default;}

.alert{padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:16px;}
.alert-ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
</style>

<div class="page-wrap">
<div class="breadcrumb">
  <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
  <span class="sep">›</span>
  <a href="ushop_items_import.php"><i class="fa-solid fa-boxes-stacked"></i> UShop Items Import</a>
  <span class="sep">›</span>
  <span style="color:#0e7490;font-weight:700;"><i class="fa-solid fa-clock-rotate-left"></i> Import History</span>
</div>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i> Import deleted successfully. Related stock history entries have also been removed.</div>
<?php endif; ?>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:19px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-clock-rotate-left" style="color:#0e7490;margin-right:8px;"></i>UShop Items — Import History
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">All past imports with row counts. Deleting an import also removes its stock history entries.</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="ushop_items_import.php" class="btn btn-teal"><i class="fa-solid fa-upload"></i> New Import</a>
    <a href="ushop_items_view.php" class="btn btn-secondary"><i class="fa-solid fa-table"></i> View Items</a>
  </div>
</div>

<!-- SUMMARY CARDS -->
<div class="sum-cards">
  <div class="sum-card">
    <div class="sum-card-label">Imports Found</div>
    <div class="sum-card-val"><?= number_format($agg['cnt'] ?? 0) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label">Total Rows</div>
    <div class="sum-card-val"><?= number_format($agg['sum_rows'] ?? 0) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label">New Items</div>
    <div class="sum-card-val" style="color:#15803d;"><?= number_format($agg['sum_new'] ?? 0) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label">Updated</div>
    <div class="sum-card-val" style="color:#92400e;"><?= number_format($agg['sum_upd'] ?? 0) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label">Skipped</div>
    <div class="sum-card-val" style="color:#6b7280;"><?= number_format($agg['sum_skip'] ?? 0) ?></div>
  </div>
</div>

<!-- FILTERS -->
<form method="GET" class="filter-bar">
  <div class="filter-group">
    <label>Search</label>
    <input type="text" name="q" value="<?= htmlspecialchars($f_q) ?>" placeholder="Filename or note…">
  </div>
  <div class="filter-group">
    <label>Specific Date</label>
    <input type="date" name="date" value="<?= htmlspecialchars($f_date) ?>">
  </div>
  <div class="filter-group">
    <label>Month</label>
    <input type="month" name="month" value="<?= htmlspecialchars($f_month) ?>">
  </div>
  <div style="display:flex;gap:6px;align-items:flex-end;">
    <button type="submit" class="btn btn-teal btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
    <a href="ushop_items_import_history.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
  </div>
</form>

<!-- TABLE -->
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title"><i class="fa-solid fa-list" style="margin-right:6px;color:#0e7490;"></i>Import Records
      <span style="font-size:11px;color:#9ca3af;font-weight:400;margin-left:8px;"><?= number_format($total) ?> record<?= $total!=1?'s':'' ?></span>
    </div>
  </div>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Import Date</th>
        <th>Filename</th>
        <th class="tr">Total</th>
        <th class="tr">New</th>
        <th class="tr">Updated</th>
        <th class="tr">Skipped</th>
        <th>Note</th>
        <th>Imported At</th>
        <th class="tc">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $i = $offset + 1;
    while ($r = mysqli_fetch_assoc($rows)):
    ?>
      <tr>
        <td style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
        <td><span class="badge badge-teal"><?= date('d M Y', strtotime($r['import_date'])) ?></span></td>
        <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11.5px;" title="<?= htmlspecialchars($r['filename']) ?>">
          <i class="fa-solid fa-file-excel" style="color:#22c55e;margin-right:5px;"></i><?= htmlspecialchars($r['filename']) ?>
        </td>
        <td class="tr"><strong><?= number_format($r['total_rows']) ?></strong></td>
        <td class="tr"><span class="badge badge-green"><?= number_format($r['new_items']) ?></span></td>
        <td class="tr"><span class="badge badge-orange"><?= number_format($r['updated_items']) ?></span></td>
        <td class="tr"><span class="badge badge-gray"><?= number_format($r['skipped_rows']) ?></span></td>
        <td style="font-size:11.5px;color:#6b7280;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($r['note'] ?: '—') ?></td>
        <td style="font-size:11.5px;white-space:nowrap;"><?= date('d M Y H:i', strtotime($r['imported_at'])) ?></td>
        <td class="tc" style="white-space:nowrap;">
          <a href="ushop_items_view.php?import_id=<?= $r['id'] ?>" class="btn btn-teal btn-sm" title="View imported items"><i class="fa-solid fa-boxes-stacked"></i></a>
          <a href="ushop_stock_history.php?import_id=<?= $r['id'] ?>" class="btn btn-green btn-sm" title="View stock history"><i class="fa-solid fa-layer-group"></i></a>
          <a href="ushop_items_import_history.php?delete=<?= $r['id'] ?>&<?= http_build_query(['q'=>$f_q,'date'=>$f_date,'month'=>$f_month,'page'=>$page]) ?>"
             class="btn btn-danger btn-sm"
             onclick="return confirm('Delete import #<?= $r['id'] ?> (<?= addslashes(htmlspecialchars($r['filename'])) ?>)?\n\nThis will permanently delete all stock history entries from this import too.')"
             title="Delete import">
            <i class="fa-solid fa-trash"></i>
          </a>
        </td>
      </tr>
    <?php endwhile; ?>
    <?php if ($total === 0): ?>
      <tr><td colspan="10" style="text-align:center;padding:44px;color:#9ca3af;font-size:13px;">
        No import records found<?= $f_q||$f_date||$f_month ? ' matching your filters' : '' ?>.
      </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pagination">
    <span style="font-size:12px;color:#6b7280;margin-right:6px;">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page > 1): ?>
      <a href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>">&laquo;</a>
      <a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>">&lsaquo;</a>
    <?php endif; ?>
    <?php for ($p = max(1,$page-3); $p <= min($pages,$page+3); $p++): ?>
      <?php if ($p === $page): ?>
        <span class="active"><?= $p ?></span>
      <?php else: ?>
        <a href="?<?= http_build_query(array_merge($_GET,['page'=>$p])) ?>"><?= $p ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $pages): ?>
      <a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>">&rsaquo;</a>
      <a href="?<?= http_build_query(array_merge($_GET,['page'=>$pages])) ?>">&raquo;</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

</div><!-- .page-wrap -->

<?php include 'footer.php'; ?>
