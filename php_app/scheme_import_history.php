<?php
include 'config.php';
include 'header.php';

// Handle delete
if (isset($_GET['delete_import'])) {
    $import_id = intval($_GET['delete_import']);
    mysqli_query($conn, "DELETE FROM scheme_discount_import_details WHERE import_id = $import_id");
    if (mysqli_query($conn, "DELETE FROM scheme_discount_imports WHERE id = $import_id")) {
        $success_message = "Import deleted successfully!";
    }
}

// Pagination
$page     = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;

// Filters
$filter_date   = $_GET['filter_date']   ?? '';
$filter_status = $_GET['filter_status'] ?? '';

$where = [];
if ($filter_date)   $where[] = "bill_date_filter = '" . mysqli_real_escape_string($conn, $filter_date) . "'";
if ($filter_status) $where[] = "status = '"          . mysqli_real_escape_string($conn, $filter_status) . "'";
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total_rows = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM scheme_discount_imports $where_sql"))['cnt'] ?? 0;
$total_pages = max(1, ceil($total_rows / $per_page));

$imports = mysqli_query($conn, "
    SELECT * FROM scheme_discount_imports
    $where_sql
    ORDER BY imported_at DESC
    LIMIT $per_page OFFSET $offset
");
?>

<div class="page-header">
    <h2 class="page-title">
        <i class="fa-solid fa-clock-rotate-left"></i> Scheme Discount Import History
    </h2>
    <p class="page-subtitle">All previously imported Bill Wise Scheme Analysis files</p>
    <a href="import_scheme_discount.php" class="btn btn-primary" style="margin-top:10px;">
        <i class="fa-solid fa-upload"></i> New Import
    </a>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i> <?php echo $success_message; ?>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="content-card">
    <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <div class="form-group" style="margin:0;flex:1;min-width:160px;">
            <label class="form-label">Bill Date</label>
            <input type="date" name="filter_date" class="form-input" value="<?php echo htmlspecialchars($filter_date); ?>">
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:160px;">
            <label class="form-label">Status</label>
            <select name="filter_status" class="form-input">
                <option value="">All Statuses</option>
                <option value="completed" <?php echo $filter_status==='completed'?'selected':''; ?>>Completed</option>
                <option value="processing" <?php echo $filter_status==='processing'?'selected':''; ?>>Processing</option>
                <option value="failed" <?php echo $filter_status==='failed'?'selected':''; ?>>Failed</option>
            </select>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Filter</button>
            <a href="scheme_import_history.php" class="btn btn-secondary"><i class="fa-solid fa-xmark"></i> Clear</a>
        </div>
    </form>
</div>

<!-- Summary stats -->
<?php
$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COUNT(*) AS total_imports,
        SUM(total_records) AS total_records,
        SUM(imported_records) AS total_imported,
        SUM(failed_records) AS total_failed
    FROM scheme_discount_imports
    $where_sql
"));
?>
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff;color:#1e40af;"><i class="fa-solid fa-file-import"></i></div>
        <div class="stat-info">
            <span class="stat-val"><?php echo number_format($stats['total_imports']??0); ?></span>
            <span class="stat-lbl">Total Imports</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f0fdf4;color:#166534;"><i class="fa-solid fa-list-check"></i></div>
        <div class="stat-info">
            <span class="stat-val"><?php echo number_format($stats['total_records']??0); ?></span>
            <span class="stat-lbl">Total Records</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f0fdf4;color:#166534;"><i class="fa-solid fa-circle-check"></i></div>
        <div class="stat-info">
            <span class="stat-val"><?php echo number_format($stats['total_imported']??0); ?></span>
            <span class="stat-lbl">Imported</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fef2f2;color:#991b1b;"><i class="fa-solid fa-circle-xmark"></i></div>
        <div class="stat-info">
            <span class="stat-val"><?php echo number_format($stats['total_failed']??0); ?></span>
            <span class="stat-lbl">Failed</span>
        </div>
    </div>
</div>

<!-- Table -->
<div class="content-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h3 class="card-title" style="margin:0;">
            <i class="fa-solid fa-table"></i> Import Sessions
        </h3>
        <span style="font-size:13px;color:#6b7280;">
            <?php echo number_format($total_rows); ?> record(s)
        </span>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Bill Date</th>
                    <th>Filename</th>
                    <th>Total</th>
                    <th>Imported</th>
                    <th>Failed</th>
                    <th>Success Rate</th>
                    <th>Status</th>
                    <th>Imported At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($imports) === 0): ?>
                <tr><td colspan="10" class="empty-row"><i class="fa-solid fa-inbox"></i> No imports found</td></tr>
                <?php else: ?>
                <?php while ($row = mysqli_fetch_assoc($imports)):
                    $rate = $row['total_records'] > 0
                        ? round(($row['imported_records'] / $row['total_records']) * 100, 1)
                        : 0;
                    $rate_color = $rate >= 90 ? '#22c55e' : ($rate >= 50 ? '#f59e0b' : '#ef4444');
                ?>
                <tr>
                    <td><?php echo $row['id']; ?></td>
                    <td><strong><?php echo date('d M Y', strtotime($row['bill_date_filter'])); ?></strong></td>
                    <td>
                        <span title="<?php echo htmlspecialchars($row['filename']); ?>">
                            <i class="fa-solid fa-file-excel" style="color:#22c55e;"></i>
                            <?php echo htmlspecialchars(strlen($row['filename']) > 35
                                ? substr($row['filename'], 0, 35) . '…'
                                : $row['filename']); ?>
                        </span>
                    </td>
                    <td><strong><?php echo number_format($row['total_records']); ?></strong></td>
                    <td class="text-success"><?php echo number_format($row['imported_records']); ?></td>
                    <td class="<?php echo $row['failed_records'] > 0 ? 'text-error' : ''; ?>">
                        <?php echo number_format($row['failed_records']); ?>
                    </td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div class="mini-bar-track">
                                <div class="mini-bar-fill" style="width:<?php echo $rate; ?>%;background:<?php echo $rate_color; ?>;"></div>
                            </div>
                            <span style="font-size:12px;font-weight:700;color:<?php echo $rate_color; ?>;">
                                <?php echo $rate; ?>%
                            </span>
                        </div>
                    </td>
                    <td>
                        <?php
                        $badge = match($row['status']) {
                            'completed'  => ['badge-success', 'fa-circle-check',  'Completed'],
                            'processing' => ['badge-warning', 'fa-spinner',        'Processing'],
                            'failed'     => ['badge-error',   'fa-circle-xmark',  'Failed'],
                            default      => ['badge-inactive','fa-clock',          ucfirst($row['status'])],
                        };
                        ?>
                        <span class="badge <?php echo $badge[0]; ?>">
                            <i class="fa-solid <?php echo $badge[1]; ?>"></i>
                            <?php echo $badge[2]; ?>
                        </span>
                    </td>
                    <td style="color:#6b7280;font-size:12px;">
                        <?php echo date('d M Y H:i', strtotime($row['imported_at'])); ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="scheme_import_view.php?id=<?php echo $row['id']; ?>"
                               class="btn-action btn-view" title="View Details">
                                <i class="fa-solid fa-eye"></i>
                            </a>
                            <a href="?delete_import=<?php echo $row['id']; ?>"
                               class="btn-action btn-delete"
                               onclick="return confirm('Delete this import and all its records?')"
                               title="Delete">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php
        $params = http_build_query(array_filter([
            'filter_date'   => $filter_date,
            'filter_status' => $filter_status,
        ]));
        for ($p = 1; $p <= $total_pages; $p++):
        ?>
        <a href="?page=<?php echo $p; ?>&<?php echo $params; ?>"
           class="page-btn <?php echo $p === $page ? 'active' : ''; ?>">
            <?php echo $p; ?>
        </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<style>
.page-header{margin-bottom:24px;}
.page-title{font-size:22px;font-weight:700;color:#1f2937;margin:0 0 4px;display:flex;align-items:center;gap:10px;}
.page-subtitle{color:#6b7280;font-size:14px;margin:0;}
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px;}
.card-title{font-size:16px;font-weight:600;margin-bottom:16px;color:#1f2937;display:flex;align-items:center;gap:8px;}
.alert{padding:12px 16px;border-radius:6px;margin-bottom:20px;display:flex;align-items:center;gap:8px;font-size:13px;}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.form-group{margin-bottom:16px;}
.form-label{display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:#374151;}
.form-input{width:100%;padding:9px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;box-sizing:border-box;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s;}
.btn-primary{background:#000;color:#fff;}
.btn-primary:hover{background:#333;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}
.btn-secondary:hover{background:#e5e5e5;}

/* Stats */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px;}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:16px;display:flex;align-items:center;gap:14px;}
.stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.stat-val{display:block;font-size:22px;font-weight:700;color:#1f2937;line-height:1;}
.stat-lbl{display:block;font-size:12px;color:#6b7280;margin-top:3px;}

/* Table */
.table-responsive{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:13px;}
.data-table thead{background:#fafafa;border-bottom:2px solid #e5e5e5;}
.data-table th{padding:11px 12px;text-align:left;font-weight:600;color:#374151;font-size:12px;white-space:nowrap;}
.data-table tbody tr{border-bottom:1px solid #f0f0f0;}
.data-table tbody tr:hover{background:#fafafa;}
.data-table td{padding:11px 12px;color:#333;vertical-align:middle;}
.empty-row{text-align:center;color:#9ca3af;padding:40px!important;font-size:14px;}
.empty-row i{margin-right:8px;}
.text-success{color:#22c55e;font-weight:600;}
.text-error{color:#ef4444;font-weight:600;}

/* Mini progress bar */
.mini-bar-track{width:70px;height:6px;background:#f0f0f0;border-radius:99px;overflow:hidden;}
.mini-bar-fill{height:100%;border-radius:99px;transition:width .3s;}

/* Badges */
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;}
.badge-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.badge-warning{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.badge-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.badge-inactive{background:#fafafa;color:#666;border:1px solid #e5e5e5;}

/* Action buttons */
.action-buttons{display:flex;gap:6px;}
.btn-action{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#666;cursor:pointer;transition:all .2s;text-decoration:none;font-size:12px;}
.btn-action:hover{transform:translateY(-2px);box-shadow:0 2px 6px rgba(0,0,0,.1);}
.btn-view{background:#eff6ff;color:#1e40af;border-color:#bfdbfe;}
.btn-view:hover{background:#1e40af;color:#fff;}
.btn-delete:hover{background:#ef4444;color:#fff;border-color:#ef4444;}

/* Pagination */
.pagination{display:flex;gap:6px;justify-content:center;margin-top:20px;flex-wrap:wrap;}
.page-btn{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#333;text-decoration:none;font-size:13px;font-weight:600;transition:all .2s;}
.page-btn:hover{background:#f0f0f0;}
.page-btn.active{background:#000;color:#fff;border-color:#000;}

@media(max-width:768px){
    .stats-grid{grid-template-columns:repeat(2,1fr);}
}
</style>

<?php include 'footer.php'; ?>
