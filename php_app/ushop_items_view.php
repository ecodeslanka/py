<?php
include 'config.php';

/* ── Filters ── */
$f_q          = trim($_GET['q']          ?? '');
$f_ucode      = trim($_GET['ucode']      ?? '');
$f_dept       = trim($_GET['dept']       ?? '');
$f_cat        = trim($_GET['cat']        ?? '');
$f_import_id  = trim($_GET['import_id']  ?? '');
$f_has_code   = trim($_GET['has_code']   ?? '');

$where = ['1=1'];
if ($f_q)        $where[] = "(i.product_code LIKE '%".mysqli_real_escape_string($conn,$f_q)."%' OR i.product_name LIKE '%".mysqli_real_escape_string($conn,$f_q)."%')";
if ($f_ucode)    $where[] = "i.unilever_code LIKE '%".mysqli_real_escape_string($conn,$f_ucode)."%'";
if ($f_dept)     $where[] = "i.department='".mysqli_real_escape_string($conn,$f_dept)."'";
if ($f_cat)      $where[] = "i.category='".mysqli_real_escape_string($conn,$f_cat)."'";
if ($f_has_code === '1') $where[] = "i.unilever_code IS NOT NULL AND i.unilever_code != ''";
if ($f_has_code === '0') $where[] = "(i.unilever_code IS NULL OR i.unilever_code = '')";

// Filter by import: show items that appeared in a specific import via stock_history
$join = '';
if ($f_import_id && is_numeric($f_import_id)) {
    $join    = " INNER JOIN ushop_stock_history sh ON sh.product_code=i.product_code AND sh.import_id=".intval($f_import_id);
    $import_info = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM ushop_items_imports WHERE id=".intval($f_import_id)));
}

$where_sql = implode(' AND ', $where);

/* ── Distinct filter options ── */
$depts = mysqli_query($conn,"SELECT DISTINCT department FROM ushop_items WHERE department IS NOT NULL ORDER BY department");
$cats  = mysqli_query($conn,"SELECT DISTINCT category  FROM ushop_items WHERE category  IS NOT NULL ORDER BY category");

/* ── Pagination ── */
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset   = ($page - 1) * $per_page;
$total_q  = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) AS c FROM ushop_items i $join WHERE $where_sql"));
$total    = (int)$total_q['c'];
$pages    = max(1, ceil($total / $per_page));

$items = mysqli_query($conn,"
  SELECT i.*,
    COALESCE((SELECT SUM(qty) FROM ushop_stock_history WHERE product_code=i.product_code AND txn_type='OPENING'),0) AS opening_qty
  FROM ushop_items i $join
  WHERE $where_sql
  ORDER BY i.department, i.category, i.product_name
  LIMIT $per_page OFFSET $offset
");

/* ── Summary ── */
$summary = mysqli_fetch_assoc(mysqli_query($conn,"
  SELECT COUNT(*) AS total,
         SUM(unilever_code IS NOT NULL AND unilever_code != '') AS with_code,
         COUNT(DISTINCT department) AS depts,
         COUNT(DISTINCT category)   AS cats
  FROM ushop_items
")) ?: [];

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.page-wrap{max-width:1400px;margin:0 auto;padding:0 8px;}
.ph-row{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:14px;flex-wrap:wrap;}
.breadcrumb a{color:#0e7490;text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-teal{background:#0e7490;color:#fff;}.btn-teal:hover{background:#155e75;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-green{background:#15803d;color:#fff;}.btn-green:hover{background:#166534;}
.btn-sm{padding:5px 10px;font-size:11.5px;}

.sum-cards{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:18px;}
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 20px;flex:1;min-width:140px;box-shadow:0 1px 4px rgba(0,0,0,.04);border-top:3px solid #0e7490;}
.sum-card-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.sum-card-val{font-size:20px;font-weight:700;color:#0e7490;}

.filter-bar{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;}
.filter-group{display:flex;flex-direction:column;gap:4px;}
.filter-group label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;}
.filter-group input,.filter-group select{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;font-family:inherit;color:#111827;outline:none;min-width:140px;}
.filter-group input:focus,.filter-group select:focus{border-color:#0e7490;}

.import-banner{background:#f0fdfa;border:1px solid #a5f3fc;border-radius:8px;padding:10px 14px;font-size:12px;color:#0e7490;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;}

.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table th{padding:10px 12px;text-align:left;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:11px;text-transform:uppercase;white-space:nowrap;}
.data-table td{padding:8px 12px;border-bottom:1px solid #f3f4f6;color:#111827;vertical-align:middle;}
.data-table tr:hover td{background:#f9fafb;}
.tr{text-align:right!important;}.tc{text-align:center!important;}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.badge-teal{background:#cffafe;color:#0e7490;}
.badge-green{background:#dcfce7;color:#15803d;}
.badge-gray{background:#f3f4f6;color:#6b7280;}
.badge-purple{background:#ede9fe;color:#7c3aed;}

.ucode{display:inline-block;background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:700;font-family:monospace;letter-spacing:.5px;}
.no-code{color:#d1d5db;font-size:11px;font-style:italic;}

.pagination{display:flex;align-items:center;gap:6px;padding:14px 18px;justify-content:flex-end;flex-wrap:wrap;}
.pagination a,.pagination span{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;}
.pagination a{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;}.pagination a:hover{background:#e5e7eb;}
.pagination .active{background:#0e7490;color:#fff;border-color:#0e7490;}
</style>

<div class="page-wrap">
<div class="breadcrumb">
  <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
  <span class="sep">›</span>
  <a href="ushop_items_import.php"><i class="fa-solid fa-boxes-stacked"></i> UShop Items Import</a>
  <span class="sep">›</span>
  <span style="color:#0e7490;font-weight:700;"><i class="fa-solid fa-table"></i> View Items</span>
</div>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:19px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-cubes" style="color:#0e7490;margin-right:8px;"></i>UShop Items
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">All imported items with Unilever codes and opening stock quantities.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="ushop_items_import.php" class="btn btn-teal"><i class="fa-solid fa-upload"></i> Import</a>
    <a href="ushop_stock_history.php" class="btn btn-green"><i class="fa-solid fa-layer-group"></i> Stock History</a>
    <a href="ushop_items_import_history.php" class="btn btn-secondary"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
  </div>
</div>

<?php if ($f_import_id && isset($import_info)): ?>
<div class="import-banner">
  <span><i class="fa-solid fa-filter"></i> Showing items from import: <strong><?= htmlspecialchars($import_info['filename']) ?></strong> (<?= date('d M Y', strtotime($import_info['import_date'])) ?>)</span>
  <a href="ushop_items_view.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-xmark"></i> Clear Filter</a>
</div>
<?php endif; ?>

<!-- SUMMARY CARDS -->
<div class="sum-cards">
  <div class="sum-card">
    <div class="sum-card-label">Total Items</div>
    <div class="sum-card-val"><?= number_format($summary['total'] ?? 0) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label">With Unilever Code</div>
    <div class="sum-card-val"><?= number_format($summary['with_code'] ?? 0) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label">Departments</div>
    <div class="sum-card-val"><?= number_format($summary['depts'] ?? 0) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label">Categories</div>
    <div class="sum-card-val"><?= number_format($summary['cats'] ?? 0) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label">Showing</div>
    <div class="sum-card-val"><?= number_format($total) ?></div>
  </div>
</div>

<!-- FILTERS -->
<form method="GET" class="filter-bar">
  <?php if($f_import_id): ?><input type="hidden" name="import_id" value="<?= (int)$f_import_id ?>"> <?php endif; ?>
  <div class="filter-group">
    <label>Search</label>
    <input type="text" name="q" value="<?= htmlspecialchars($f_q) ?>" placeholder="Code or name…" style="min-width:200px;">
  </div>
  <div class="filter-group">
    <label>Unilever Code</label>
    <input type="text" name="ucode" value="<?= htmlspecialchars($f_ucode) ?>" placeholder="e.g. 3904001">
  </div>
  <div class="filter-group">
    <label>Department</label>
    <select name="dept">
      <option value="">All Departments</option>
      <?php while($d = mysqli_fetch_assoc($depts)): ?>
        <option value="<?= htmlspecialchars($d['department']) ?>" <?= $f_dept===$d['department']?'selected':'' ?>><?= htmlspecialchars($d['department']) ?></option>
      <?php endwhile; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Category</label>
    <select name="cat">
      <option value="">All Categories</option>
      <?php while($c = mysqli_fetch_assoc($cats)): ?>
        <option value="<?= htmlspecialchars($c['category']) ?>" <?= $f_cat===$c['category']?'selected':'' ?>><?= htmlspecialchars($c['category']) ?></option>
      <?php endwhile; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Unilever Code</label>
    <select name="has_code">
      <option value="">All items</option>
      <option value="1" <?= $f_has_code==='1'?'selected':'' ?>>Has code only</option>
      <option value="0" <?= $f_has_code==='0'?'selected':'' ?>>No code only</option>
    </select>
  </div>
  <div style="display:flex;gap:6px;align-items:flex-end;">
    <button type="submit" class="btn btn-teal btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
    <a href="ushop_items_view.php<?= $f_import_id ? '?import_id='.$f_import_id : '' ?>" class="btn btn-secondary btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
  </div>
</form>

<!-- TABLE -->
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title"><i class="fa-solid fa-cubes" style="margin-right:6px;color:#0e7490;"></i>Items
      <span style="font-size:11px;color:#9ca3af;font-weight:400;margin-left:8px;"><?= number_format($total) ?> item<?= $total!=1?'s':'' ?></span>
    </div>
  </div>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Product Code</th>
        <th>Unilever Code</th>
        <th>Product Name</th>
        <th>Department</th>
        <th>Category</th>
        <th>Supplier</th>
        <th class="tr">MRP</th>
        <th class="tr">Cost Price</th>
        <th class="tr">Opening Qty</th>
        <th>Updated</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $i = $offset + 1;
    while ($row = mysqli_fetch_assoc($items)):
        $ucode = $row['unilever_code'];
        $oqty  = (float)$row['opening_qty'];
    ?>
      <tr>
        <td style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
        <td style="font-size:11px;font-family:monospace;color:#374151;"><?= htmlspecialchars($row['product_code']) ?></td>
        <td>
          <?php if ($ucode): ?>
            <span class="ucode"><?= htmlspecialchars($ucode) ?></span>
          <?php else: ?>
            <span class="no-code">—</span>
          <?php endif; ?>
        </td>
        <td style="max-width:280px;font-size:12px;" title="<?= htmlspecialchars($row['product_name']) ?>">
          <?= htmlspecialchars(mb_substr($row['product_name'] ?? '', 0, 70)).(mb_strlen($row['product_name']??'')>70?'…':'') ?>
        </td>
        <td style="font-size:11px;">
          <?php if ($row['department']): ?>
            <span class="badge badge-gray" style="font-size:10px;"><?= htmlspecialchars($row['department']) ?></span>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td style="font-size:11px;">
          <?php if ($row['category']): ?>
            <span class="badge badge-teal" style="font-size:10px;"><?= htmlspecialchars($row['category']) ?></span>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td style="font-size:11px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($row['supplier']??'') ?>"><?= htmlspecialchars($row['supplier'] ?: '—') ?></td>
        <td class="tr" style="font-size:11.5px;"><?= $row['mrp'] !== null ? number_format($row['mrp'],2) : '—' ?></td>
        <td class="tr" style="font-size:11.5px;"><?= $row['cost_price'] !== null ? number_format($row['cost_price'],2) : '—' ?></td>
        <td class="tr">
          <?php if ($oqty > 0): ?>
            <span class="badge badge-green"><?= number_format($oqty,0) ?></span>
          <?php else: ?>
            <span style="color:#d1d5db;font-size:11px;">0</span>
          <?php endif; ?>
        </td>
        <td style="font-size:11px;color:#9ca3af;white-space:nowrap;"><?= date('d M y', strtotime($row['updated_at'])) ?></td>
      </tr>
    <?php endwhile; ?>
    <?php if ($total === 0): ?>
      <tr><td colspan="11" style="text-align:center;padding:44px;color:#9ca3af;font-size:13px;">No items found<?= $f_q||$f_dept||$f_cat||$f_ucode ? ' matching your filters' : '' ?>.</td></tr>
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
      <?php if ($p === $page): ?><span class="active"><?= $p ?></span>
      <?php else: ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$p])) ?>"><?= $p ?></a>
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
