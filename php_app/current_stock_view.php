<?php
include 'config.php';

/* ── filter inputs ── */
$f_upload_id  = intval($_GET['upload_id']   ?? 0);
$f_entry_from = trim($_GET['entry_from']    ?? '');
$f_entry_to   = trim($_GET['entry_to']      ?? '');
$f_division   = trim($_GET['division']      ?? '');
$f_location   = trim($_GET['location']      ?? '');
$f_product    = trim($_GET['product']       ?? '');
$f_exp_from   = trim($_GET['exp_from']      ?? '');
$f_exp_to     = trim($_GET['exp_to']        ?? '');
$f_days_min   = trim($_GET['days_min']      ?? '');
$f_days_max   = trim($_GET['days_max']      ?? '');

$filter_submitted = isset($_GET['entry_from']) || isset($_GET['entry_to']) || isset($_GET['division'])
    || isset($_GET['location']) || isset($_GET['product']) || isset($_GET['upload_id'])
    || isset($_GET['exp_from']) || isset($_GET['exp_to']) || isset($_GET['days_min']) || isset($_GET['days_max']);

/* ── build WHERE ── */
$where = ['1=1'];
if ($f_upload_id)  $where[] = "d.upload_id=$f_upload_id";
if ($f_entry_from) $where[] = "d.entry_date >= '".mysqli_real_escape_string($conn,$f_entry_from)."'";
if ($f_entry_to)   $where[] = "d.entry_date <= '".mysqli_real_escape_string($conn,$f_entry_to)."'";
if ($f_division)   $where[] = "d.division = '".mysqli_real_escape_string($conn,$f_division)."'";
if ($f_location)   $where[] = "d.location = '".mysqli_real_escape_string($conn,$f_location)."'";
if ($f_product)    $where[] = "d.product_name LIKE '%".mysqli_real_escape_string($conn,$f_product)."%'";
if ($f_exp_from)   $where[] = "d.expiry_date >= '".mysqli_real_escape_string($conn,$f_exp_from)."'";
if ($f_exp_to)     $where[] = "d.expiry_date <= '".mysqli_real_escape_string($conn,$f_exp_to)."'";
if ($f_days_min !== '') $where[] = "d.no_of_days_to_expire >= ".intval($f_days_min);
if ($f_days_max !== '') $where[] = "d.no_of_days_to_expire <= ".intval($f_days_max);
$where_sql = implode(' AND ', $where);

/* ── dropdown options ── */
$all_divisions = [];
$res = mysqli_query($conn,"SELECT DISTINCT division FROM current_stock_data WHERE division IS NOT NULL AND division<>'' ORDER BY division");
while ($r=mysqli_fetch_row($res)) $all_divisions[]=$r[0];

$all_locations = [];
$res = mysqli_query($conn,"SELECT DISTINCT location FROM current_stock_data WHERE location IS NOT NULL AND location<>'' ORDER BY location");
while ($r=mysqli_fetch_row($res)) $all_locations[]=$r[0];

$all_uploads = [];
$res = mysqli_query($conn,"SELECT id,entry_date,filename,total_rows FROM current_stock_uploads ORDER BY entry_date DESC,uploaded_at DESC");
while ($r=mysqli_fetch_assoc($res)) $all_uploads[]=$r;

/* ── pagination ── */
$per_page = 50;
$page = max(1,intval($_GET['page'] ?? 1));
$offset = ($page-1)*$per_page;

$total_count = 0;
$rows = [];
$summary = null;

if ($filter_submitted || $f_upload_id) {
    $cnt = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) AS c FROM current_stock_data d WHERE $where_sql"));
    $total_count = intval($cnt['c']);
    $total_pages = max(1,ceil($total_count/$per_page));

    $res = mysqli_query($conn,"
        SELECT d.*, u.entry_date AS u_entry_date, u.filename
        FROM current_stock_data d
        JOIN current_stock_uploads u ON u.id=d.upload_id
        WHERE $where_sql
        ORDER BY d.entry_date DESC, d.id ASC
        LIMIT $per_page OFFSET $offset
    ");
    while ($r=mysqli_fetch_assoc($res)) $rows[]=$r;

    $summary = mysqli_fetch_assoc(mysqli_query($conn,"
        SELECT
          COUNT(*) AS total_rows,
          SUM(units) AS total_units,
          SUM(cur_stk_value) AS total_value,
          SUM(tonnage) AS total_tonnage,
          COUNT(DISTINCT division) AS div_count,
          COUNT(DISTINCT location) AS loc_count,
          COUNT(DISTINCT product_name) AS prod_count
        FROM current_stock_data d WHERE $where_sql
    "));
} else {
    $total_pages = 1;
}

/* ── upload info if viewing specific upload ── */
$upload_info = null;
if ($f_upload_id) {
    $upload_info = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM current_stock_uploads WHERE id=$f_upload_id"));
}

include 'header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<style>
*{box-sizing:border-box;}
.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-success{background:#15803d;color:#fff;}.btn-success:hover{background:#166534;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-sm{padding:5px 12px;font-size:12px;}

.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;}
@media(max-width:900px){.summary-grid{grid-template-columns:1fr 1fr;}}
.sum-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.sum-card-label{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;}
.sum-card-val{font-size:19px;font-weight:800;color:#1e40af;}
.sum-card.green .sum-card-val{color:#065f46;}
.sum-card.amber .sum-card-val{color:#92400e;}

.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:16px 20px;margin-bottom:18px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.filter-section-title{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-bottom:8px;}
.filter-row{display:grid;gap:10px;}
.filter-r1{grid-template-columns:1fr 1fr 1fr 1fr 1fr;}
.filter-r2{grid-template-columns:1fr 1fr 1fr 1fr 1fr 1fr auto;}
@media(max-width:1100px){.filter-r1,.filter-r2{grid-template-columns:1fr 1fr 1fr;}}
@media(max-width:700px){.filter-r1,.filter-r2{grid-template-columns:1fr 1fr;}}
.fg label{font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;}
.fctrl{padding:7px 10px;border:1px solid #e0e0e0;border-radius:6px;font-size:12px;width:100%;font-family:inherit;background:#fff;color:#111;outline:none;}
.fctrl:focus{border-color:#3b82f6;}
.filter-divider{border:none;border-top:1px dashed #e5e7eb;margin:12px 0;}

.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:18px;}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:11.5px;}
.data-table thead th{padding:7px 7px;text-align:left;font-weight:700;font-size:10.5px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;}
.data-table thead th.tr{text-align:right;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr:hover td{background:#f0f7ff;}
.data-table td{padding:6px 7px;color:#374151;vertical-align:middle;background:#fff;}
.data-table td.tr{text-align:right;}
.data-table tfoot td{padding:7px 7px;font-weight:800;font-size:11.5px;background:#0f172a;color:#e2e8f0;}
.data-table tfoot td.tr{text-align:right;}
.badge{display:inline-block;padding:2px 9px;border-radius:10px;font-size:10px;font-weight:700;}
.badge-blue{background:#dbeafe;color:#1e40af;}
.badge-green{background:#d1fae5;color:#065f46;}
.badge-red{background:#fee2e2;color:#991b1b;}
.badge-amber{background:#fef3c7;color:#92400e;}
.badge-purple{background:#ede9fe;color:#5b21b6;}
.pill{padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;background:#dbeafe;color:#1e40af;}
.rs{font-size:9.5px;font-weight:600;opacity:.7;margin-right:1px;}

/* upload info banner */
.upload-banner{background:linear-gradient(90deg,#1e1b4b,#1e40af);color:#fff;padding:12px 20px;border-radius:10px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
.upload-banner strong{font-size:13.5px;}
.upload-banner span{font-size:12px;opacity:.8;}

/* prompt box */
.prompt-box{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:60px 20px;text-align:center;color:#9ca3af;box-shadow:0 1px 3px rgba(0,0,0,.04);}

/* days expiry colouring */
.exp-ok{color:#065f46;font-weight:700;}
.exp-warn{color:#92400e;font-weight:700;}
.exp-crit{color:#991b1b;font-weight:700;}

/* pagination */
.pager{display:flex;justify-content:center;align-items:center;gap:6px;padding:14px;}
.pager a,.pager span{padding:5px 12px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;border:1px solid #e5e5e5;}
.pager a{color:#1e40af;background:#fff;}.pager a:hover{background:#eff6ff;}
.pager span.active{background:#1e40af;color:#fff;border-color:#1e40af;}
.pager span.disabled{color:#d1d5db;background:#f9f9f9;}

/* select2 overrides */
.select2-container--default .select2-selection--single{height:33px!important;border:1px solid #e0e0e0!important;border-radius:6px!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:31px!important;padding-left:10px!important;font-size:12px!important;font-family:inherit!important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:31px!important;}
.select2-dropdown{font-size:12px!important;font-family:inherit!important;}

/* toast */
#toast{position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:100020;padding:12px 26px;border-radius:9px;font-size:13px;font-weight:700;font-family:inherit;box-shadow:0 8px 28px rgba(0,0,0,.2);display:none;pointer-events:none;}
.toast-ok{background:#166534;color:#fff;}.toast-err{background:#dc2626;color:#fff;}
</style>

<div class="ph-row">
  <div>
    <h2 class="page-title" style="margin:0 0 4px;">Current Stock Data</h2>
    <p style="margin:0;font-size:13px;color:#6b7280;">Browse, filter and export uploaded current stock records.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <?php if ($filter_submitted || $f_upload_id): ?>
    <button class="btn btn-success btn-sm" onclick="exportExcel()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
    <?php endif; ?>
    <a href="current_stock_upload.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-upload"></i> Upload</a>
    <a href="current_stock_history.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
  </div>
</div>

<?php if ($upload_info): ?>
<div class="upload-banner">
  <div>
    <strong><i class="fa-solid fa-file-excel" style="margin-right:6px;"></i><?= htmlspecialchars($upload_info['filename']) ?></strong><br>
    <span>Entry Date: <?= date('d M Y',strtotime($upload_info['entry_date'])) ?> &nbsp;·&nbsp; <?= number_format($upload_info['total_rows']) ?> rows &nbsp;·&nbsp; Uploaded <?= date('d M Y H:i',strtotime($upload_info['uploaded_at'])) ?></span>
  </div>
  <a href="current_stock_view.php" class="btn btn-secondary btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.2);">
    <i class="fa-solid fa-filter"></i> All Uploads
  </a>
</div>
<?php endif; ?>

<!-- SUMMARY CARDS -->
<?php if ($summary): ?>
<div class="summary-grid">
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-table-rows" style="margin-right:4px;"></i>Total Records</div>
    <div class="sum-card-val"><?= number_format($summary['total_rows']) ?></div>
  </div>
  <div class="sum-card green">
    <div class="sum-card-label"><i class="fa-solid fa-boxes-stacked" style="margin-right:4px;"></i>Total Units</div>
    <div class="sum-card-val"><?= number_format($summary['total_units']) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-indian-rupee-sign" style="margin-right:4px;"></i>Stock Value (Rs.)</div>
    <div class="sum-card-val" style="font-size:15px;"><?= number_format($summary['total_value'],2) ?></div>
  </div>
  <div class="sum-card amber">
    <div class="sum-card-label"><i class="fa-solid fa-weight-scale" style="margin-right:4px;"></i>Total Tonnage</div>
    <div class="sum-card-val"><?= number_format($summary['total_tonnage'],3) ?></div>
  </div>
</div>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;">
  <span style="background:#f0f7ff;border:1px solid #dbeafe;color:#1e40af;padding:5px 14px;border-radius:8px;font-size:12px;font-weight:700;">
    <i class="fa-solid fa-layer-group" style="margin-right:4px;"></i><?= number_format($summary['div_count']) ?> Divisions
  </span>
  <span style="background:#f0fdf4;border:1px solid #bbf7d0;color:#065f46;padding:5px 14px;border-radius:8px;font-size:12px;font-weight:700;">
    <i class="fa-solid fa-location-dot" style="margin-right:4px;"></i><?= number_format($summary['loc_count']) ?> Locations
  </span>
  <span style="background:#fef3c7;border:1px solid #fde68a;color:#92400e;padding:5px 14px;border-radius:8px;font-size:12px;font-weight:700;">
    <i class="fa-solid fa-box" style="margin-right:4px;"></i><?= number_format($summary['prod_count']) ?> Products
  </span>
</div>
<?php endif; ?>

<!-- FILTERS -->
<div class="filter-card">
  <form method="GET" id="filterForm">
    <?php if ($f_upload_id): ?><input type="hidden" name="upload_id" value="<?= $f_upload_id ?>">
    <?php endif; ?>
    <div class="filter-section-title"><i class="fa-solid fa-calendar-days" style="margin-right:5px;"></i>Date Filters</div>
    <div class="filter-row filter-r1">
      <div class="fg">
        <label>Entry Date — From</label>
        <input type="date" name="entry_from" class="fctrl" value="<?= htmlspecialchars($f_entry_from) ?>">
      </div>
      <div class="fg">
        <label>Entry Date — To</label>
        <input type="date" name="entry_to" class="fctrl" value="<?= htmlspecialchars($f_entry_to) ?>">
      </div>
      <div class="fg">
        <label>Expiry Date — From</label>
        <input type="date" name="exp_from" class="fctrl" value="<?= htmlspecialchars($f_exp_from) ?>">
      </div>
      <div class="fg">
        <label>Expiry Date — To</label>
        <input type="date" name="exp_to" class="fctrl" value="<?= htmlspecialchars($f_exp_to) ?>">
      </div>
      <div class="fg">
        <label>Upload Batch</label>
        <select name="upload_id" id="fUploadId" class="fctrl" style="width:100%;">
          <option value="">— All Uploads —</option>
          <?php foreach ($all_uploads as $u): ?>
          <option value="<?= $u['id'] ?>" <?= ($f_upload_id==$u['id'])?'selected':'' ?>>
            <?= date('d M Y',strtotime($u['entry_date'])) ?> — <?= number_format($u['total_rows']) ?> rows
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <hr class="filter-divider">
    <div class="filter-section-title"><i class="fa-solid fa-sliders" style="margin-right:5px;"></i>Product Filters</div>
    <div class="filter-row filter-r2">
      <div class="fg">
        <label>Division</label>
        <select name="division" id="fDivision" class="fctrl" style="width:100%;">
          <option value="">— All Divisions —</option>
          <?php foreach ($all_divisions as $d): ?>
          <option value="<?= htmlspecialchars($d) ?>" <?= ($f_division===$d)?'selected':'' ?>><?= htmlspecialchars($d) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label>Location</label>
        <select name="location" id="fLocation" class="fctrl" style="width:100%;">
          <option value="">— All Locations —</option>
          <?php foreach ($all_locations as $l): ?>
          <option value="<?= htmlspecialchars($l) ?>" <?= ($f_location===$l)?'selected':'' ?>><?= htmlspecialchars($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label>Product Name</label>
        <input type="text" name="product" class="fctrl" placeholder="Search product…" value="<?= htmlspecialchars($f_product) ?>">
      </div>
      <div class="fg">
        <label>Days to Expire — Min</label>
        <input type="number" name="days_min" class="fctrl" placeholder="e.g. 0" value="<?= htmlspecialchars($f_days_min) ?>">
      </div>
      <div class="fg">
        <label>Days to Expire — Max</label>
        <input type="number" name="days_max" class="fctrl" placeholder="e.g. 90" value="<?= htmlspecialchars($f_days_max) ?>">
      </div>
      <div class="fg" style="display:flex;align-items:flex-end;gap:6px;">
        <button type="submit" class="btn btn-primary" style="height:33px;flex:1;font-size:12px;"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
        <a href="current_stock_view.php" class="btn btn-secondary" style="height:33px;" title="Clear"><i class="fa-solid fa-rotate-left"></i></a>
      </div>
    </div>
    <!-- Active filter chips -->
    <?php $has_filters = $f_entry_from||$f_entry_to||$f_division||$f_location||$f_product||$f_upload_id||$f_exp_from||$f_exp_to||$f_days_min!==''||$f_days_max!==''; ?>
    <?php if ($has_filters): ?>
    <div style="margin-top:10px;font-size:11px;color:#6b7280;display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
      <span style="font-weight:700;">Active:</span>
      <?= $f_upload_id  ? '<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:6px;font-weight:600;">Batch #'.$f_upload_id.'</span>' : '' ?>
      <?= $f_entry_from ? '<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:6px;font-weight:600;">Entry ≥ '.htmlspecialchars($f_entry_from).'</span>' : '' ?>
      <?= $f_entry_to   ? '<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:6px;font-weight:600;">Entry ≤ '.htmlspecialchars($f_entry_to).'</span>' : '' ?>
      <?= $f_division   ? '<span style="background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:6px;font-weight:600;">'.htmlspecialchars($f_division).'</span>' : '' ?>
      <?= $f_location   ? '<span style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:6px;font-weight:600;">'.htmlspecialchars($f_location).'</span>' : '' ?>
      <?= $f_product    ? '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:6px;font-weight:600;">Product: '.htmlspecialchars($f_product).'</span>' : '' ?>
      <?= $f_exp_from   ? '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:6px;font-weight:600;">Exp ≥ '.htmlspecialchars($f_exp_from).'</span>' : '' ?>
      <?= $f_exp_to     ? '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:6px;font-weight:600;">Exp ≤ '.htmlspecialchars($f_exp_to).'</span>' : '' ?>
      <?= $f_days_min!=='' ? '<span style="background:#f0fdf4;color:#065f46;padding:2px 8px;border-radius:6px;font-weight:600;">Days ≥ '.htmlspecialchars($f_days_min).'</span>' : '' ?>
      <?= $f_days_max!=='' ? '<span style="background:#fef2f2;color:#991b1b;padding:2px 8px;border-radius:6px;font-weight:600;">Days ≤ '.htmlspecialchars($f_days_max).'</span>' : '' ?>
      <a href="current_stock_view.php" style="color:#dc2626;font-weight:600;">✕ Clear all</a>
    </div>
    <?php endif; ?>
  </form>
</div>

<?php if (!$filter_submitted && !$f_upload_id): ?>
<div class="prompt-box">
  <i class="fa-solid fa-filter" style="font-size:36px;display:block;margin-bottom:12px;opacity:.3;"></i>
  <p><strong>Select filters above and click "Filter"</strong><br>to browse Current Stock data.</p>
  <p style="font-size:12px;margin-top:8px;">Or <a href="?entry_from=<?= date('Y-m-d',strtotime('-30 days')) ?>&entry_to=<?= date('Y-m-d') ?>" style="color:#1e40af;font-weight:700;">view last 30 days</a></p>
</div>

<?php elseif (empty($rows)): ?>
<div class="table-card">
  <div style="text-align:center;padding:60px;color:#9ca3af;">
    <i class="fa-solid fa-magnifying-glass" style="font-size:32px;display:block;margin-bottom:12px;opacity:.3;"></i>
    <p>No records found for the selected filters.</p>
  </div>
</div>

<?php else: ?>
<!-- DATA TABLE -->
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title">
      Stock Records <span class="pill"><?= number_format($total_count) ?></span>
      <span style="font-size:11px;color:#9ca3af;font-weight:400;margin-left:6px;">Page <?= $page ?> of <?= $total_pages ?></span>
    </div>
    <button class="btn btn-success btn-sm" onclick="exportExcel()"><i class="fa-solid fa-file-excel"></i> Export Page</button>
  </div>
  <div class="dt-wrap">
  <table class="data-table" id="stockTable">
    <thead>
      <tr>
        <th>#</th>
        <th>Entry Date</th>
        <th>Sr No</th>
        <th>Division</th>
        <th>Basepack</th>
        <th>SKU7</th>
        <th>Product Name</th>
        <th>Location</th>
        <th>PKM</th>
        <th>Batch</th>
        <th>Exp Month</th>
        <th>Expiry Date</th>
        <th class="tr">Days to Exp</th>
        <th class="tr">UPC</th>
        <th class="tr">Units</th>
        <th>Stk Days</th>
        <th class="tr">Pur.Rate</th>
        <th class="tr">Pur+Tax</th>
        <th class="tr">TUR</th>
        <th class="tr">MRP</th>
        <th class="tr">Stk Value</th>
        <th class="tr">Tonnage</th>
      </tr>
    </thead>
    <tbody>
    <?php $i=($page-1)*$per_page+1; foreach ($rows as $r):
        $days = $r['no_of_days_to_expire'];
        $days_cls = $days === null ? '' : ($days < 0 ? 'exp-crit' : ($days <= 30 ? 'exp-crit' : ($days <= 90 ? 'exp-warn' : 'exp-ok')));
        $exp_badge = $r['expiry_date'] && $r['expiry_date']!=='0000-00-00'
            ? date('d M Y', strtotime($r['expiry_date'])) : '—';
    ?>
      <tr>
        <td style="color:#9ca3af;font-size:10px;"><?= $i++ ?></td>
        <td><span class="badge badge-blue" style="font-size:9.5px;"><?= date('d M Y',strtotime($r['entry_date'])) ?></span></td>
        <td style="font-size:10.5px;color:#6b7280;"><?= intval($r['sr_no']) ?></td>
        <td><span class="badge badge-purple"><?= htmlspecialchars($r['division']??'—') ?></span></td>
        <td style="font-size:10.5px;"><?= htmlspecialchars($r['basepack_code']??'—') ?></td>
        <td style="font-size:10.5px;"><?= htmlspecialchars($r['sku7']??'—') ?></td>
        <td style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:11px;" title="<?= htmlspecialchars($r['product_name']??'') ?>"><?= htmlspecialchars($r['product_name']??'—') ?></td>
        <td style="font-size:10.5px;white-space:nowrap;"><?= htmlspecialchars($r['location']??'—') ?></td>
        <td class="tr" style="font-size:10.5px;"><?= $r['pkm']!==null ? number_format($r['pkm'],0) : '—' ?></td>
        <td style="font-size:10.5px;"><?= htmlspecialchars($r['batch_code']??'—') ?></td>
        <td style="font-size:10.5px;"><?= htmlspecialchars($r['expiry_month']??'—') ?></td>
        <td style="font-size:10.5px;white-space:nowrap;"><?= $exp_badge ?></td>
        <td class="tr <?= $days_cls ?>"><?= $days!==null ? number_format($days) : '—' ?></td>
        <td class="tr" style="font-size:10.5px;"><?= $r['upc']!==null ? number_format($r['upc']) : '—' ?></td>
        <td class="tr" style="font-weight:700;"><?= $r['units']!==null ? number_format($r['units']) : '—' ?></td>
        <td style="font-size:10.5px;"><?= htmlspecialchars($r['stocks_in_days']??'—') ?></td>
        <td class="tr" style="font-size:10.5px;"><?= $r['pur_rate']!==null ? number_format($r['pur_rate'],3) : '—' ?></td>
        <td class="tr" style="font-size:10.5px;"><?= $r['pur_rate_tax']!==null ? number_format($r['pur_rate_tax'],3) : '—' ?></td>
        <td class="tr" style="font-size:10.5px;"><?= $r['tur']!==null ? number_format($r['tur'],3) : '—' ?></td>
        <td class="tr" style="font-size:10.5px;"><?= $r['mrp']!==null ? number_format($r['mrp'],2) : '—' ?></td>
        <td class="tr" style="font-weight:700;color:#065f46;"><?= $r['cur_stk_value']!==null ? '<span class="rs">Rs.</span>'.number_format($r['cur_stk_value'],2) : '—' ?></td>
        <td class="tr" style="font-size:10.5px;"><?= $r['tonnage']!==null ? number_format($r['tonnage'],4) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="14" style="text-align:right;font-size:10.5px;opacity:.7;">TOTALS — <?= number_format($total_count) ?> records</td>
        <td class="tr"><?= number_format($summary['total_units']) ?></td>
        <td></td>
        <td colspan="3"></td>
        <td class="tr"><span class="rs">Rs.</span><?= number_format($summary['total_value'],2) ?></td>
        <td class="tr"><?= number_format($summary['total_tonnage'],4) ?></td>
      </tr>
    </tfoot>
  </table>
  </div>

  <!-- PAGINATION -->
  <?php if ($total_pages > 1):
    $base_qs = http_build_query(['entry_from'=>$f_entry_from,'entry_to'=>$f_entry_to,'division'=>$f_division,
      'location'=>$f_location,'product'=>$f_product,'upload_id'=>$f_upload_id ?: '',
      'exp_from'=>$f_exp_from,'exp_to'=>$f_exp_to,'days_min'=>$f_days_min,'days_max'=>$f_days_max]);
  ?>
  <div class="pager">
    <?php if ($page>1): ?>
      <a href="?page=<?=$page-1?>&<?=$base_qs?>"><i class="fa-solid fa-chevron-left"></i></a>
    <?php else: ?><span class="disabled"><i class="fa-solid fa-chevron-left"></i></span><?php endif; ?>

    <?php for ($p=max(1,$page-2);$p<=min($total_pages,$page+2);$p++): ?>
      <?php if ($p===$page): ?>
        <span class="active"><?=$p?></span>
      <?php else: ?>
        <a href="?page=<?=$p?>&<?=$base_qs?>"><?=$p?></a>
      <?php endif; ?>
    <?php endfor; ?>

    <?php if ($page<$total_pages): ?>
      <a href="?page=<?=$page+1?>&<?=$base_qs?>"><i class="fa-solid fa-chevron-right"></i></a>
    <?php else: ?><span class="disabled"><i class="fa-solid fa-chevron-right"></i></span><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div id="toast"></div>

<script>
$(function(){
  $('#fDivision,#fLocation,#fUploadId').select2({allowClear:true,width:'100%'});
});

function exportExcel() {
  const tbl = document.getElementById('stockTable');
  if (!tbl) { showToast('No data to export.','err'); return; }
  const wb = XLSX.utils.book_new();
  const ws = XLSX.utils.table_to_sheet(tbl);
  XLSX.utils.book_append_sheet(wb,'Current Stock','Current Stock');
  const dt = new Date();
  XLSX.writeFile(wb,'current_stock_'+dt.getFullYear()+String(dt.getMonth()+1).padStart(2,'0')+String(dt.getDate()).padStart(2,'0')+'.xlsx');
  showToast('Exported successfully.','ok');
}

function showToast(msg,type){
  const t=document.getElementById('toast');
  t.className=type==='ok'?'toast-ok':'toast-err';
  t.textContent=msg;t.style.display='block';t.style.opacity='1';
  clearTimeout(t._t);
  t._t=setTimeout(()=>{t.style.opacity='0';setTimeout(()=>t.style.display='none',300);},2800);
}
</script>

<?php include 'footer.php'; ?>
