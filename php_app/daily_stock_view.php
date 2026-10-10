<?php
include_once 'auth.php';
requireLogin();
$current_user = getCurrentUser();
include_once 'config.php';

// Helper: run a prepared statement and return all rows as assoc array
function db_query($conn, $sql, $params = []) {
    $stmt = mysqli_prepare($conn, $sql);
    if ($params) {
        $types = str_repeat('s', count($params));
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;
    mysqli_stmt_close($stmt);
    return $rows;
}

// Helper: return single scalar value
function db_scalar($conn, $sql, $params = []) {
    $stmt = mysqli_prepare($conn, $sql);
    if ($params) {
        $types = str_repeat('s', count($params));
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_row($result);
    mysqli_stmt_close($stmt);
    return $row ? $row[0] : null;
}

$upload_id = (int)($_GET['id'] ?? 0);
if (!$upload_id) { header('Location: daily_stock_list.php'); exit; }

// Get upload header
$upload_rows = db_query($conn,
    "SELECT u.*, usr.username as uploader_name
     FROM daily_stock_uploads u
     LEFT JOIN users usr ON u.uploaded_by = usr.id
     WHERE u.id = ?",
    [$upload_id]
);
$upload = $upload_rows[0] ?? null;
if (!$upload) { header('Location: daily_stock_list.php'); exit; }

// Filters
$filter_search   = trim($_GET['search']   ?? '');
$filter_division = trim($_GET['division'] ?? '');
$filter_location = trim($_GET['location'] ?? '');
$filter_expiry   = trim($_GET['expiry']   ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 50;
$offset = ($page - 1) * $limit;

$where  = ['upload_id = ?'];
$params = [$upload_id];

if ($filter_search) {
    $where[] = '(product_name LIKE ? OR sku7 LIKE ? OR basepack_code LIKE ? OR batch_code LIKE ?)';
    $s = "%$filter_search%";
    $params = array_merge($params, [$s, $s, $s, $s]);
}
if ($filter_division) { $where[] = 'division = ?';              $params[] = $filter_division; }
if ($filter_location) { $where[] = 'location = ?';              $params[] = $filter_location; }
if ($filter_expiry === 'expired') { $where[] = 'no_of_days_to_expire < 0'; }
if ($filter_expiry === 'near')    { $where[] = 'no_of_days_to_expire BETWEEN 0 AND 30'; }
if ($filter_expiry === 'ok')      { $where[] = 'no_of_days_to_expire > 30'; }

$where_sql = implode(' AND ', $where);

$total       = (int)db_scalar($conn, "SELECT COUNT(*) FROM daily_stock_items WHERE $where_sql", $params);
$total_pages = ceil($total / $limit);

$items = db_query($conn,
    "SELECT * FROM daily_stock_items WHERE $where_sql ORDER BY sr_no ASC LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);

// Filter options
$divisions = array_column(
    db_query($conn, "SELECT DISTINCT division FROM daily_stock_items WHERE upload_id = ? AND division != '' ORDER BY division", [$upload_id]),
    'division'
);
$locations = array_column(
    db_query($conn, "SELECT DISTINCT location FROM daily_stock_items WHERE upload_id = ? AND location != '' ORDER BY location", [$upload_id]),
    'location'
);

// Summary for this upload
$stats_rows = db_query($conn,
    "SELECT
        COUNT(*) as total_items,
        COALESCE(SUM(units),0) as total_units,
        COALESCE(SUM(cur_stk_value),0) as total_value,
        COALESCE(SUM(tonnage),0) as total_tonnage,
        SUM(CASE WHEN no_of_days_to_expire < 0 THEN 1 ELSE 0 END) as expired_count,
        SUM(CASE WHEN no_of_days_to_expire BETWEEN 0 AND 30 THEN 1 ELSE 0 END) as near_expiry_count
     FROM daily_stock_items WHERE upload_id = ?",
    [$upload_id]
);
$stats = $stats_rows[0] ?? [];
?>
<?php include 'header.php'; ?>

<style>
.page-card {
    background: #fff; border-radius: 12px; border: 1px solid #e5e7eb;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06); overflow: hidden; margin-bottom: 20px;
}
.info-strip {
    display: grid; grid-template-columns: repeat(6, 1fr); gap: 0;
    border-bottom: 1px solid #e5e7eb;
}
.info-strip-item {
    padding: 16px 20px; border-right: 1px solid #e5e7eb;
}
.info-strip-item:last-child { border-right: none; }
.info-val  { font-size: 18px; font-weight: 700; color: #111; line-height: 1.2; }
.info-lbl  { font-size: 11px; color: #9ca3af; font-weight: 500; margin-top: 2px; }

.filter-bar {
    padding: 14px 20px; background: #f9fafb; border-bottom: 1px solid #e5e7eb;
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
.filter-input, .filter-select {
    padding: 7px 11px; border: 1.5px solid #d1d5db; border-radius: 7px;
    font-size: 13px; font-family: 'Inter',sans-serif; color: #111; background: #fff;
    outline: none; transition: border-color 0.2s;
}
.filter-input:focus, .filter-select:focus { border-color: #000; }
.btn {
    padding: 8px 16px; border-radius: 7px; font-size: 13px; font-weight: 600;
    font-family: 'Inter',sans-serif; cursor: pointer; border: none;
    display: inline-flex; align-items: center; gap: 6px; transition: all 0.15s;
    text-decoration: none;
}
.btn-primary { background: #000; color: #fff; }
.btn-primary:hover { background: #1a1a1a; }
.btn-outline { background: #fff; color: #374151; border: 1.5px solid #d1d5db; }
.btn-outline:hover { border-color: #000; color: #000; }
.btn-sm { padding: 6px 12px; font-size: 12px; }

/* Table */
.data-table { width: 100%; border-collapse: collapse; }
.data-table th {
    background: #f9fafb; font-weight: 600; color: #6b7280; padding: 10px 12px;
    text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;
    border-bottom: 1px solid #e5e7eb; white-space: nowrap; position: sticky; top: 0; z-index: 1;
}
.data-table td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; color: #374151; font-size: 12px; vertical-align: middle; }
.data-table tr:last-child td { border-bottom: none; }
.data-table tbody tr:hover td { background: #fafafa; }

.badge {
    display: inline-flex; align-items: center; padding: 2px 8px;
    border-radius: 20px; font-size: 11px; font-weight: 500;
}
.badge-red    { background: #fef2f2; color: #dc2626; }
.badge-yellow { background: #fffbeb; color: #d97706; }
.badge-green  { background: #f0fdf4; color: #16a34a; }
.badge-gray   { background: #f3f4f6; color: #374151; }
.badge-blue   { background: #eff6ff; color: #1d4ed8; }

/* Pagination */
.pagination { display:flex; align-items:center; gap:5px; padding:14px 20px; flex-wrap:wrap; border-top:1px solid #e5e7eb; }
.page-btn {
    min-width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center;
    border-radius:6px; font-size:12px; font-weight:500; cursor:pointer; text-decoration:none;
    border:1.5px solid #d1d5db; color:#374151; background:#fff; transition:all 0.15s;
}
.page-btn:hover { border-color:#000; color:#000; }
.page-btn.active { background:#000; color:#fff; border-color:#000; }
.page-btn.disabled { opacity:0.4; pointer-events:none; }

/* Upload meta card */
.meta-card {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
    padding: 16px 20px; margin-bottom: 20px;
    display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
}
.meta-item { display: flex; flex-direction: column; gap: 2px; }
.meta-lbl  { font-size: 11px; color: #9ca3af; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.meta-val  { font-size: 13px; font-weight: 600; color: #111; }

/* Export bar */
.export-bar {
    display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
    padding: 14px 20px; border-top: 1px solid #e5e7eb; background: #f9fafb;
}

@media(max-width:900px) {
    .info-strip { grid-template-columns: repeat(3,1fr); }
}
@media(max-width:600px) {
    .info-strip { grid-template-columns: repeat(2,1fr); }
    .filter-bar { flex-direction:column; align-items:stretch; }
}
</style>

<div style="padding:24px;">
    <!-- Page Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
        <div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
                <a href="daily_stock_list.php" style="color:#9ca3af;font-size:13px;text-decoration:none;display:flex;align-items:center;gap:5px;">
                    <i class="fa-solid fa-arrow-left"></i> All Uploads
                </a>
                <span style="color:#d1d5db;">/</span>
                <span style="font-size:13px;color:#374151;"><?php echo htmlspecialchars($upload['upload_ref']); ?></span>
            </div>
            <h1 style="font-size:20px;font-weight:700;color:#111;margin:0;">
                Stock Records — <?php echo date('d F Y', strtotime($upload['delivery_date'])); ?>
            </h1>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button onclick="exportCSV()" class="btn btn-outline btn-sm">
                <i class="fa-solid fa-download"></i> Export CSV
            </button>
            <a href="daily_stock_list.php" class="btn btn-outline btn-sm">
                <i class="fa-solid fa-list"></i> All Uploads
            </a>
        </div>
    </div>

    <!-- Meta Card -->
    <div class="meta-card">
        <div class="meta-item">
            <div class="meta-lbl">Reference</div>
            <div class="meta-val"><?php echo htmlspecialchars($upload['upload_ref']); ?></div>
        </div>
        <div class="meta-item">
            <div class="meta-lbl">Delivery Date</div>
            <div class="meta-val"><?php echo date('d M Y', strtotime($upload['delivery_date'])); ?></div>
        </div>
        <div class="meta-item">
            <div class="meta-lbl">Uploaded By</div>
            <div class="meta-val"><?php echo htmlspecialchars($upload['uploader_name'] ?? '—'); ?></div>
        </div>
        <div class="meta-item">
            <div class="meta-lbl">Uploaded At</div>
            <div class="meta-val"><?php echo date('d M Y, h:i A', strtotime($upload['uploaded_at'])); ?></div>
        </div>
        <div class="meta-item">
            <div class="meta-lbl">Source File</div>
            <div class="meta-val" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($upload['original_filename']); ?>">
                <i class="fa-solid fa-file-excel" style="color:#16a34a;"></i>
                <?php echo htmlspecialchars($upload['original_filename']); ?>
            </div>
        </div>
        <?php if ($upload['notes']): ?>
        <div class="meta-item">
            <div class="meta-lbl">Notes</div>
            <div class="meta-val"><?php echo htmlspecialchars($upload['notes']); ?></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Stats Strip -->
    <div class="page-card">
        <div class="info-strip">
            <div class="info-strip-item">
                <div class="info-val"><?php echo number_format($stats['total_items']); ?></div>
                <div class="info-lbl">Total SKU Lines</div>
            </div>
            <div class="info-strip-item">
                <div class="info-val"><?php echo number_format($stats['total_units']); ?></div>
                <div class="info-lbl">Total Units</div>
            </div>
            <div class="info-strip-item">
                <div class="info-val">Rs <?php echo number_format($stats['total_value'], 2); ?></div>
                <div class="info-lbl">Total Stock Value</div>
            </div>
            <div class="info-strip-item">
                <div class="info-val"><?php echo number_format($stats['total_tonnage'], 3); ?></div>
                <div class="info-lbl">Total Tonnage</div>
            </div>
            <div class="info-strip-item">
                <div class="info-val" style="color:#dc2626;"><?php echo number_format($stats['expired_count']); ?></div>
                <div class="info-lbl">Expired Items</div>
            </div>
            <div class="info-strip-item">
                <div class="info-val" style="color:#d97706;"><?php echo number_format($stats['near_expiry_count']); ?></div>
                <div class="info-lbl">Near Expiry (≤30d)</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <form method="GET" class="filter-bar">
            <input type="hidden" name="id" value="<?php echo $upload_id; ?>">
            <div style="display:flex;align-items:center;gap:6px;">
                <i class="fa-solid fa-magnifying-glass" style="color:#9ca3af;font-size:12px;"></i>
                <input type="text" name="search" class="filter-input"
                       placeholder="Product / SKU / Batch..."
                       value="<?php echo htmlspecialchars($filter_search); ?>" style="width:190px;">
            </div>
            <select name="division" class="filter-select">
                <option value="">All Divisions</option>
                <?php foreach ($divisions as $d): ?>
                    <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $filter_division == $d ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($d); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="location" class="filter-select">
                <option value="">All Locations</option>
                <?php foreach ($locations as $l): ?>
                    <option value="<?php echo htmlspecialchars($l); ?>" <?php echo $filter_location == $l ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($l); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="expiry" class="filter-select">
                <option value="">All Expiry</option>
                <option value="expired" <?php echo $filter_expiry == 'expired' ? 'selected' : ''; ?>>Expired</option>
                <option value="near"    <?php echo $filter_expiry == 'near'    ? 'selected' : ''; ?>>Near Expiry (≤30d)</option>
                <option value="ok"      <?php echo $filter_expiry == 'ok'      ? 'selected' : ''; ?>>OK (&gt;30d)</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
            <?php if ($filter_search || $filter_division || $filter_location || $filter_expiry): ?>
                <a href="daily_stock_view.php?id=<?php echo $upload_id; ?>" class="btn btn-outline btn-sm">
                    <i class="fa-solid fa-xmark"></i> Clear
                </a>
            <?php endif; ?>
            <span style="font-size:12px;color:#9ca3af;margin-left:auto;">
                Showing <?php echo number_format(min($total, $offset+1)); ?>–<?php echo number_format(min($total, $offset+$limit)); ?> of <?php echo number_format($total); ?>
            </span>
        </form>

        <!-- Table -->
        <div style="overflow-x:auto; max-height:520px; overflow-y:auto;">
            <table class="data-table" id="stockTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Sr No</th>
                        <th>Division</th>
                        <th>BP Code</th>
                        <th>SKU7</th>
                        <th>Product Name</th>
                        <th>Location</th>
                        <th>PKM</th>
                        <th>Batch</th>
                        <th>Expiry Date</th>
                        <th>Days Left</th>
                        <th>UPC</th>
                        <th>Units</th>
                        <th>Stk Days</th>
                        <th>Pur.Rate</th>
                        <th>Pur.+Tax</th>
                        <th>TUR</th>
                        <th>MRP</th>
                        <th>Stk Value</th>
                        <th>Tonnage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($items) === 0): ?>
                        <tr>
                            <td colspan="20" style="text-align:center;padding:40px;color:#9ca3af;">
                                <i class="fa-solid fa-inbox" style="font-size:28px;display:block;margin-bottom:8px;"></i>
                                No records found
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $i => $row):
                            $days = $row['no_of_days_to_expire'];
                            if ($days === null) $expiry_badge = '<span class="badge badge-gray">—</span>';
                            elseif ($days < 0)  $expiry_badge = '<span class="badge badge-red">Expired</span>';
                            elseif ($days <= 30) $expiry_badge = '<span class="badge badge-yellow">⚠ ' . $days . 'd</span>';
                            else $expiry_badge = '<span class="badge badge-green">' . $days . 'd</span>';
                        ?>
                        <tr>
                            <td style="color:#9ca3af;"><?php echo $offset + $i + 1; ?></td>
                            <td><?php echo $row['sr_no']; ?></td>
                            <td><span class="badge badge-blue"><?php echo htmlspecialchars($row['division'] ?? '—'); ?></span></td>
                            <td style="font-family:monospace;font-size:11px;"><?php echo htmlspecialchars($row['basepack_code'] ?? '—'); ?></td>
                            <td style="font-family:monospace;font-size:11px;"><?php echo htmlspecialchars($row['sku7'] ?? '—'); ?></td>
                            <td style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($row['product_name'] ?? ''); ?>">
                                <strong><?php echo htmlspecialchars($row['product_name'] ?? '—'); ?></strong>
                            </td>
                            <td style="font-size:11px;color:#6b7280;"><?php echo htmlspecialchars($row['location'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['pkm'] ?? '—'); ?></td>
                            <td style="font-family:monospace;font-size:11px;"><?php echo htmlspecialchars($row['batch_code'] ?? '—'); ?></td>
                            <td style="white-space:nowrap;"><?php echo $row['expiry_date'] ? date('d M Y', strtotime($row['expiry_date'])) : '—'; ?></td>
                            <td><?php echo $expiry_badge; ?></td>
                            <td><?php echo htmlspecialchars($row['upc'] ?? '—'); ?></td>
                            <td style="font-weight:600;"><?php echo number_format($row['units'] ?? 0); ?></td>
                            <td><?php echo htmlspecialchars($row['stocks_in_days'] ?? '—'); ?></td>
                            <td><?php echo $row['pur_rate'] !== null ? number_format($row['pur_rate'], 3) : '—'; ?></td>
                            <td><?php echo $row['pur_rate_tax'] !== null ? number_format($row['pur_rate_tax'], 3) : '—'; ?></td>
                            <td><?php echo $row['tur'] !== null ? number_format($row['tur'], 3) : '—'; ?></td>
                            <td><?php echo $row['mrp'] !== null ? number_format($row['mrp'], 2) : '—'; ?></td>
                            <td style="font-weight:600;"><?php echo $row['cur_stk_value'] !== null ? number_format($row['cur_stk_value'], 2) : '—'; ?></td>
                            <td><?php echo $row['tonnage'] !== null ? number_format($row['tonnage'], 4) : '—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1,$page-1)])); ?>"
               class="page-btn <?php echo $page<=1?'disabled':''; ?>"><i class="fa-solid fa-chevron-left"></i></a>
            <?php for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page'=>$p])); ?>"
                   class="page-btn <?php echo $p==$page?'active':''; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($total_pages,$page+1)])); ?>"
               class="page-btn <?php echo $page>=$total_pages?'disabled':''; ?>"><i class="fa-solid fa-chevron-right"></i></a>
            <span style="font-size:12px;color:#9ca3af;margin-left:8px;">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
        </div>
        <?php endif; ?>

        <!-- Export Bar -->
        <div class="export-bar">
            <span style="font-size:12px;color:#9ca3af;"><i class="fa-solid fa-circle-info"></i> Showing <?php echo $limit; ?> records per page</span>
            <button onclick="exportCSV()" class="btn btn-outline btn-sm" style="margin-left:auto;">
                <i class="fa-solid fa-file-csv"></i> Export This Page (CSV)
            </button>
        </div>
    </div>
</div>

<script>
function exportCSV() {
    const table = document.getElementById('stockTable');
    const rows = table.querySelectorAll('tr');
    let csv = [];
    rows.forEach(row => {
        let cols = row.querySelectorAll('th, td');
        let line = Array.from(cols).map(c => {
            let txt = c.innerText.replace(/\n/g,' ').trim();
            if (txt.includes(',')) txt = '"' + txt + '"';
            return txt;
        });
        csv.push(line.join(','));
    });
    const blob = new Blob([csv.join('\n')], {type: 'text/csv'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'stock_<?php echo $upload['delivery_date']; ?>_page<?php echo $page; ?>.csv';
    a.click();
    URL.revokeObjectURL(url);
}
</script>

<?php include 'footer.php'; ?>
