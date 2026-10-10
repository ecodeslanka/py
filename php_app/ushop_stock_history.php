<?php
include 'config.php';

/* ── Filters ── */
$f_q         = trim($_GET['q']         ?? '');
$f_ucode     = trim($_GET['ucode']     ?? '');
$f_type      = trim($_GET['type']      ?? '');
$f_import_id = trim($_GET['import_id'] ?? '');
$f_date_from = trim($_GET['from']      ?? '');
$f_date_to   = trim($_GET['to']        ?? '');

$where = ['1=1'];
if ($f_q)         $where[] = "(s.product_code LIKE '%".mysqli_real_escape_string($conn,$f_q)."%' OR s.product_name LIKE '%".mysqli_real_escape_string($conn,$f_q)."%')";
if ($f_ucode)     $where[] = "s.unilever_code LIKE '%".mysqli_real_escape_string($conn,$f_ucode)."%'";
if ($f_type)      $where[] = "s.txn_type='".mysqli_real_escape_string($conn,$f_type)."'";
if ($f_import_id && is_numeric($f_import_id)) $where[] = "s.import_id=".intval($f_import_id);
if ($f_date_from) $where[] = "s.txn_date>='".mysqli_real_escape_string($conn,$f_date_from)."'";
if ($f_date_to)   $where[] = "s.txn_date<='".mysqli_real_escape_string($conn,$f_date_to)."'";

$where_sql = implode(' AND ', $where);

/* ── Pagination ── */
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset   = ($page - 1) * $per_page;
$total_q  = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) AS c FROM ushop_stock_history s WHERE $where_sql"));
$total    = (int)$total_q['c'];
$pages    = max(1, ceil($total / $per_page));

$rows = mysqli_query($conn,"
  SELECT s.*, imp.filename, imp.import_date
  FROM ushop_stock_history s
  LEFT JOIN ushop_items_imports imp ON imp.id=s.import_id
  WHERE $where_sql
  ORDER BY s.txn_date DESC, s.id DESC
  LIMIT $per_page OFFSET $offset
");

/* ── Summary ── */
$agg = mysqli_fetch_assoc(mysqli_query($conn,"
  SELECT COUNT(*) AS cnt,
         COALESCE(SUM(CASE WHEN txn_type='OPENING'    THEN qty ELSE 0 END),0) AS opening_qty,
         COALESCE(SUM(CASE WHEN txn_type='STOCK_IN'   THEN qty ELSE 0 END),0) AS stockin_qty,
         COALESCE(SUM(CASE WHEN txn_type='STOCK_OUT'  THEN qty ELSE 0 END),0) AS stockout_qty,
         COALESCE(SUM(total_cost),0) AS total_cost_val
  FROM ushop_stock_history s WHERE $where_sql
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

.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table th{padding:10px 12px;text-align:left;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:11px;text-transform:uppercase;white-space:nowrap;}
.data-table td{padding:8px 12px;border-bottom:1px solid #f3f4f6;color:#111827;vertical-align:middle;}
.data-table tr:hover td{background:#f9fafb;}
.tr{text-align:right!important;}.tc{text-align:center!important;}

.txn-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.txn-OPENING{background:#dbeafe;color:#1e40af;}
.txn-STOCK_IN{background:#dcfce7;color:#15803d;}
.txn-STOCK_OUT{background:#fee2e2;color:#dc2626;}
.txn-ADJUSTMENT{background:#fef3c7;color:#92400e;}

.ucode{display:inline-block;background:#dbeafe;color:#1e40af;padding:2px 7px;border-radius:5px;font-size:11px;font-weight:700;font-family:monospace;}
.no-code{color:#d1d5db;font-size:11px;}

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
  <span style="color:#0e7490;font-weight:700;"><i class="fa-solid fa-layer-group"></i> Stock History</span>
</div>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:19px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-layer-group" style="color:#0e7490;margin-right:8px;"></i>UShop Stock History
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Opening stock transactions recorded from each import. Future stock movements will also appear here.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="ushop_items_import.php" class="btn btn-teal"><i class="fa-solid fa-upload"></i> Import</a>
    <a href="ushop_items_view.php" class="btn btn-secondary"><i class="fa-solid fa-cubes"></i> Items</a>
    <a href="ushop_items_import_history.php" class="btn btn-secondary"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
  </div>
</div>

<!-- SUMMARY CARDS -->
<div class="sum-cards">
  <div class="sum-card">
    <div class="sum-card-label">Total Transactions</div>
    <div class="sum-card-val"><?= number_format($agg['cnt'] ?? 0) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label">Opening Stock Qty</div>
    <div class="sum-card-val" style="color:#1e40af;"><?= number_format($agg['opening_qty'] ?? 0) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label">Stock In Qty</div>
    <div class="sum-card-val" style="color:#15803d;"><?= number_format($agg['stockin_qty'] ?? 0) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label">Stock Out Qty</div>
    <div class="sum-card-val" style="color:#dc2626;"><?= number_format($agg['stockout_qty'] ?? 0) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label">Total Cost Value</div>
    <div class="sum-card-val" style="font-size:15px;padding-top:4px;"><?= number_format($agg['total_cost_val'] ?? 0, 2) ?></div>
  </div>
</div>

<!-- FILTERS -->
<form method="GET" class="filter-bar">
  <div class="filter-group">
    <label>Search</label>
    <input type="text" name="q" value="<?= htmlspecialchars($f_q) ?>" placeholder="Code or name…" style="min-width:180px;">
  </div>
  <div class="filter-group">
    <label>Unilever Code</label>
    <input type="text" name="ucode" value="<?= htmlspecialchars($f_ucode) ?>" placeholder="e.g. 3904001">
  </div>
  <div class="filter-group">
    <label>Transaction Type</label>
    <select name="type">
      <option value="">All Types</option>
      <option value="OPENING"    <?= $f_type==='OPENING'?'selected':'' ?>>Opening Stock</option>
      <option value="STOCK_IN"   <?= $f_type==='STOCK_IN'?'selected':'' ?>>Stock In</option>
      <option value="STOCK_OUT"  <?= $f_type==='STOCK_OUT'?'selected':'' ?>>Stock Out</option>
      <option value="ADJUSTMENT" <?= $f_type==='ADJUSTMENT'?'selected':'' ?>>Adjustment</option>
    </select>
  </div>
  <div class="filter-group">
    <label>Date From</label>
    <input type="date" name="from" value="<?= htmlspecialchars($f_date_from) ?>">
  </div>
  <div class="filter-group">
    <label>Date To</label>
    <input type="date" name="to" value="<?= htmlspecialchars($f_date_to) ?>">
  </div>
  <?php if ($f_import_id): ?>
  <input type="hidden" name="import_id" value="<?= (int)$f_import_id ?>">
  <?php endif; ?>
  <div style="display:flex;gap:6px;align-items:flex-end;">
    <button type="submit" class="btn btn-teal btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
    <a href="ushop_stock_history.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
  </div>
</form>

<!-- TABLE -->
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title"><i class="fa-solid fa-layer-group" style="margin-right:6px;color:#0e7490;"></i>Stock Transactions
      <span style="font-size:11px;color:#9ca3af;font-weight:400;margin-left:8px;"><?= number_format($total) ?> record<?= $total!=1?'s':'' ?></span>
    </div>
  </div>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Date</th>
        <th>Type</th>
        <th>Product Code</th>
        <th>Unilever Code</th>
        <th>Product Name</th>
        <th class="tr">Qty</th>
        <th class="tr">MRP</th>
        <th class="tr">Cost</th>
        <th class="tr">Total MRP</th>
        <th class="tr">Total Cost</th>
        <th class="tr">Avg Cost</th>
        <th>Import File</th>
        <th>Note</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $i = $offset + 1;
    while ($row = mysqli_fetch_assoc($rows)):
    ?>
      <tr>
        <td style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
        <td style="white-space:nowrap;font-size:11.5px;"><?= date('d M Y', strtotime($row['txn_date'])) ?></td>
        <td><span class="txn-badge txn-<?= $row['txn_type'] ?>"><?= str_replace('_',' ',$row['txn_type']) ?></span></td>
        <td style="font-size:11px;font-family:monospace;color:#374151;"><?= htmlspecialchars($row['product_code']) ?></td>
        <td>
          <?php if ($row['unilever_code']): ?>
            <span class="ucode"><?= htmlspecialchars($row['unilever_code']) ?></span>
          <?php else: ?>
            <span class="no-code">—</span>
          <?php endif; ?>
        </td>
        <td style="max-width:240px;font-size:11.5px;" title="<?= htmlspecialchars($row['product_name']) ?>">
          <?= htmlspecialchars(mb_substr($row['product_name'] ?? '', 0, 55)).(mb_strlen($row['product_name']??'')>55?'…':'') ?>
        </td>
        <td class="tr" style="font-weight:700;color:<?= $row['txn_type']==='STOCK_OUT'?'#dc2626':'#15803d' ?>;">
          <?= $row['qty'] !== null ? number_format($row['qty'],0) : '—' ?>
        </td>
        <td class="tr" style="font-size:11.5px;"><?= $row['mrp']        !== null ? number_format($row['mrp'],2)        : '—' ?></td>
        <td class="tr" style="font-size:11.5px;"><?= $row['cost_price'] !== null ? number_format($row['cost_price'],2) : '—' ?></td>
        <td class="tr" style="font-size:11.5px;"><?= $row['total_mrp']  !== null ? number_format($row['total_mrp'],2)  : '—' ?></td>
        <td class="tr" style="font-size:11.5px;"><?= $row['total_cost'] !== null ? number_format($row['total_cost'],2) : '—' ?></td>
        <td class="tr" style="font-size:11.5px;"><?= $row['avg_cost']   !== null ? number_format($row['avg_cost'],2)   : '—' ?></td>
        <td style="font-size:11px;color:#6b7280;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($row['filename']??'') ?>">
          <?= htmlspecialchars($row['filename'] ?: '—') ?>
        </td>
        <td style="font-size:11px;color:#9ca3af;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($row['note']??'') ?>">
          <?= htmlspecialchars($row['note'] ?: '—') ?>
        </td>
      </tr>
    <?php endwhile; ?>
    <?php if ($total === 0): ?>
      <tr><td colspan="14" style="text-align:center;padding:44px;color:#9ca3af;font-size:13px;">No stock transactions found<?= $f_q||$f_ucode||$f_type ? ' matching your filters' : '' ?>.</td></tr>
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
