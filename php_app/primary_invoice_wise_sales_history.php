<?php
include 'config.php';

/* ── delete handler ── */
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    mysqli_query($conn,"DELETE FROM primary_invoice_wise_sales_uploads WHERE id=$del_id");
    header('Location: primary_invoice_wise_sales_history.php?deleted=1');
    exit;
}

/* ── filters ── */
$f_date_from = trim($_GET['date_from'] ?? '');
$f_date_to   = trim($_GET['date_to']   ?? '');
$f_search    = trim($_GET['search']    ?? '');

$where = ['1=1'];
if ($f_date_from) $where[] = "u.delivery_date >= '".mysqli_real_escape_string($conn,$f_date_from)."'";
if ($f_date_to)   $where[] = "u.delivery_date <= '".mysqli_real_escape_string($conn,$f_date_to)."'";
if ($f_search)    $where[] = "(u.filename LIKE '%".mysqli_real_escape_string($conn,$f_search)."%' OR u.note LIKE '%".mysqli_real_escape_string($conn,$f_search)."%')";
$where_sql = implode(' AND ', $where);

/* ── pagination ── */
$per_page    = 25;
$page        = max(1, intval($_GET['page'] ?? 1));
$offset      = ($page-1)*$per_page;
$total_count = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) AS c FROM primary_invoice_wise_sales_uploads u WHERE $where_sql"))['c'];
$total_pages = max(1, ceil($total_count/$per_page));

$uploads = mysqli_query($conn,"
  SELECT u.*
  FROM primary_invoice_wise_sales_uploads u
  WHERE $where_sql
  ORDER BY u.uploaded_at DESC
  LIMIT $per_page OFFSET $offset
");

$stats = mysqli_fetch_assoc(mysqli_query($conn,"
  SELECT COUNT(*) AS total_uploads,
         COALESCE(SUM(total_rows),0) AS total_rows,
         COUNT(DISTINCT delivery_date) AS unique_dates
  FROM primary_invoice_wise_sales_uploads
")) ?: ['total_uploads'=>0,'total_rows'=>0,'unique_dates'=>0];

include 'header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>

<style>
*{box-sizing:border-box;}
.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-success{background:#15803d;color:#fff;}.btn-success:hover{background:#166534;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-purple{background:#7c3aed;color:#fff;}.btn-purple:hover{background:#6d28d9;}
.btn-sm{padding:5px 10px;font-size:12px;}

/* Label pill */
.primary-label{display:inline-flex;align-items:center;gap:5px;background:#faf5ff;border:1px solid #ddd6fe;color:#7c3aed;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:700;margin-left:8px;vertical-align:middle;}

/* Summary Cards */
.sum-cards{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:22px;}
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 22px;flex:1;min-width:150px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.sum-card-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.sum-card-val{font-size:22px;font-weight:700;color:#111827;}
.sum-card-accent{border-top:3px solid #7c3aed;}

/* Filter bar */
.filter-bar{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;margin-bottom:18px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;}
.fb-group{display:flex;flex-direction:column;gap:4px;}
.fb-group label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;}
.fb-group input{padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:12px;font-family:inherit;color:#111827;outline:none;}
.fb-group input:focus{border-color:#7c3aed;}

/* Table */
.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.04);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table th{padding:10px 12px;text-align:left;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:11px;text-transform:uppercase;white-space:nowrap;}
.data-table td{padding:10px 12px;border-bottom:1px solid #f3f4f6;color:#111827;}
.data-table tr:hover td{background:#f9fafb;}
.tr{text-align:right!important;}
.tc{text-align:center!important;}

.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.badge-purple{background:#ede9fe;color:#7c3aed;}
.badge-green{background:#dcfce7;color:#15803d;}

/* Pagination */
.pag{display:flex;align-items:center;gap:6px;padding:12px 16px;border-top:1px solid #f3f4f6;flex-wrap:wrap;}
.pag a,.pag span{padding:5px 10px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;border:1px solid #e5e7eb;color:#374151;}
.pag a:hover{background:#f5f5f5;}
.pag .active{background:#7c3aed;color:#fff;border-color:#7c3aed;}
.pag-info{font-size:12px;color:#6b7280;margin-left:auto;}

/* Alert */
.alert{padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:16px;}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}

/* Breadcrumb */
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:16px;flex-wrap:wrap;}
.breadcrumb a{color:#7c3aed;text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}
</style>

<!-- Breadcrumb -->
<div class="breadcrumb">
  <a href="primary_invoice_wise_sales_upload.php"><i class="fa-solid fa-star"></i> Primary Upload</a>
  <span class="sep">›</span>
  <span style="color:#7c3aed;font-weight:700;"><i class="fa-solid fa-clock-rotate-left"></i> History</span>
</div>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-clock-rotate-left" style="color:#7c3aed;margin-right:8px;"></i>Primary Invoice Wise Sales — Upload History
      <span class="primary-label"><i class="fa-solid fa-circle-check"></i> PRIMARY</span>
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">All Primary Invoice Wise Sales upload sessions and their status.</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="primary_invoice_wise_sales_upload.php" class="btn btn-success"><i class="fa-solid fa-file-arrow-up"></i> New Upload</a>
    <a href="primary_invoice_wise_sales_view.php" class="btn btn-purple"><i class="fa-solid fa-table"></i> View Data</a>
    <a href="invoice_wise_sales_history.php" class="btn btn-secondary"><i class="fa-solid fa-truck"></i> Secondary History</a>
  </div>
</div>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> Primary upload deleted successfully.</div>
<?php endif; ?>

<!-- SUMMARY CARDS -->
<div class="sum-cards">
  <div class="sum-card sum-card-accent">
    <div class="sum-card-label"><i class="fa-solid fa-upload" style="margin-right:4px;color:#7c3aed;"></i>Total Uploads</div>
    <div class="sum-card-val" style="color:#7c3aed;"><?= number_format($stats['total_uploads']) ?></div>
  </div>
  <div class="sum-card sum-card-accent">
    <div class="sum-card-label"><i class="fa-solid fa-list-ol" style="margin-right:4px;color:#7c3aed;"></i>Total Rows</div>
    <div class="sum-card-val" style="color:#7c3aed;"><?= number_format($stats['total_rows']) ?></div>
  </div>
  <div class="sum-card sum-card-accent">
    <div class="sum-card-label"><i class="fa-solid fa-truck" style="margin-right:4px;color:#7c3aed;"></i>Delivery Dates</div>
    <div class="sum-card-val" style="color:#7c3aed;"><?= number_format($stats['unique_dates']) ?></div>
  </div>
</div>

<!-- FILTER BAR -->
<form method="GET" action="primary_invoice_wise_sales_history.php">
<div class="filter-bar">
  <div class="fb-group">
    <label>Delivery Date From</label>
    <input type="date" name="date_from" value="<?= htmlspecialchars($f_date_from) ?>">
  </div>
  <div class="fb-group">
    <label>Delivery Date To</label>
    <input type="date" name="date_to" value="<?= htmlspecialchars($f_date_to) ?>">
  </div>
  <div class="fb-group">
    <label>Search Filename / Note</label>
    <input type="text" name="search" value="<?= htmlspecialchars($f_search) ?>" placeholder="filename or note…" style="min-width:200px;">
  </div>
  <button type="submit" class="btn btn-purple"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
  <a href="primary_invoice_wise_sales_history.php" class="btn btn-secondary"><i class="fa-solid fa-rotate-left"></i> Clear</a>
</div>
</form>

<!-- UPLOADS TABLE -->
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title"><?= number_format($total_count) ?> primary upload<?= $total_count!=1?'s':'' ?></div>
  </div>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Delivery Date</th>
        <th>Filename</th>
        <th class="tr">Rows</th>
        <th>Note</th>
        <th>Uploaded At</th>
        <th class="tc">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $i = ($page-1)*$per_page + 1;
    while ($r = mysqli_fetch_assoc($uploads)):
    ?>
      <tr>
        <td style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
        <td><span class="badge badge-purple"><?= date('d M Y',strtotime($r['delivery_date'])) ?></span></td>
        <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11.5px;" title="<?= htmlspecialchars($r['filename']) ?>">
          <?= htmlspecialchars($r['filename']) ?>
        </td>
        <td class="tr"><span class="badge badge-green"><?= number_format($r['total_rows']) ?></span></td>
        <td style="font-size:11.5px;color:#6b7280;"><?= htmlspecialchars($r['note'] ?: '—') ?></td>
        <td style="font-size:11.5px;"><?= date('d M Y H:i',strtotime($r['uploaded_at'])) ?></td>
        <td class="tc" style="white-space:nowrap;">
          <a href="primary_invoice_wise_sales_view.php?upload_id=<?= $r['id'] ?>" class="btn btn-purple btn-sm" title="View Data"><i class="fa-solid fa-eye"></i></a>
          <a href="primary_invoice_wise_sales_history.php?delete=<?= $r['id'] ?>" class="btn btn-danger btn-sm"
             onclick="return confirm('Delete this primary upload and ALL its data rows? This cannot be undone.')" title="Delete">
            <i class="fa-solid fa-trash"></i>
          </a>
        </td>
      </tr>
    <?php endwhile; ?>
    <?php if ($total_count === 0): ?>
      <tr><td colspan="7" style="text-align:center;padding:50px;color:#9ca3af;">No primary uploads found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>

  <!-- PAGINATION -->
  <?php if ($total_pages > 1):
    $qs = $_GET; unset($qs['page']);
    $base = '?'.http_build_query($qs).'&page=';
  ?>
  <div class="pag">
    <?php if ($page>1): ?><a href="<?= $base.($page-1) ?>"><i class="fa-solid fa-chevron-left"></i> Prev</a><?php endif; ?>
    <?php
    $start=max(1,$page-2); $end=min($total_pages,$page+2);
    if ($start>1) echo '<span>…</span>';
    for ($p=$start;$p<=$end;$p++) {
        echo $p==$page ? "<span class='active'>$p</span>" : "<a href='{$base}{$p}'>$p</a>";
    }
    if ($end<$total_pages) echo '<span>…</span>';
    ?>
    <?php if ($page<$total_pages): ?><a href="<?= $base.($page+1) ?>">Next <i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
    <span class="pag-info">Page <?= $page ?> of <?= $total_pages ?> &nbsp;·&nbsp; <?= number_format($total_count) ?> uploads</span>
  </div>
  <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
