<?php
include 'config.php';
include 'header.php';

// Handle delete
if (isset($_GET['delete_import'])) {
    $import_id = intval($_GET['delete_import']);
    mysqli_query($conn, "DELETE FROM unloading_summary_import_details WHERE import_id = $import_id");
    if (mysqli_query($conn, "DELETE FROM unloading_summary_imports WHERE id = $import_id")) {
        $success_message = "Import record deleted successfully!";
    } else {
        $error_message = "Failed to delete import record.";
    }
}

// Filters
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$filter_date   = isset($_GET['filter_date'])   ? $_GET['filter_date']   : '';

// Build query
$where = "1=1";
if ($filter_status) {
    $fs    = mysqli_real_escape_string($conn, $filter_status);
    $where .= " AND i.status = '$fs'";
}
if ($filter_date) {
    $fd    = mysqli_real_escape_string($conn, $filter_date);
    $where .= " AND DATE(i.imported_at) = '$fd'";
}

// Get imports with summary
$query = "SELECT i.*,
          COUNT(DISTINCT d.delivery_person_code) as person_count,
          COUNT(DISTINCT d.sku_code) as sku_count,
          SUM(d.adj_qty_good_units) as total_good,
          SUM(d.adj_qty_damage) as total_damage
          FROM unloading_summary_imports i
          LEFT JOIN unloading_summary_import_details d ON d.import_id = i.id AND d.status = 'imported'
          WHERE $where
          GROUP BY i.id
          ORDER BY i.imported_at DESC";
$result = mysqli_query($conn, $query);

// Overall stats
$stats_query = "SELECT
    COUNT(*) as total_imports,
    SUM(imported_records) as total_imported,
    SUM(failed_records) as total_failed
    FROM unloading_summary_imports";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);
?>

<div class="page-header">
    <h2 class="page-title">
        <i class="fa-solid fa-history"></i> Unloading Import History
    </h2>
    <p class="page-subtitle">View and manage previously imported unloading summary files</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i>
    <?php echo $success_message; ?>
</div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
<div class="alert alert-error">
    <i class="fa-solid fa-circle-xmark"></i>
    <?php echo $error_message; ?>
</div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="stats-row">
    <div class="stat-card">
        <div class="stat-icon" style="background: #eff6ff; color: #1e40af;">
            <i class="fa-solid fa-file-import"></i>
        </div>
        <div class="stat-info">
            <span class="stat-value"><?php echo intval($stats['total_imports']); ?></span>
            <span class="stat-label">Total Imports</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #f0fdf4; color: #166534;">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <div class="stat-info">
            <span class="stat-value"><?php echo intval($stats['total_imported']); ?></span>
            <span class="stat-label">Records Imported</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #fef2f2; color: #991b1b;">
            <i class="fa-solid fa-circle-xmark"></i>
        </div>
        <div class="stat-info">
            <span class="stat-value"><?php echo intval($stats['total_failed']); ?></span>
            <span class="stat-label">Records Failed</span>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="content-card">
    <form method="GET" class="filter-form">
        <div class="filter-row">
            <div class="filter-group">
                <label class="form-label">Imported Date</label>
                <input type="date" name="filter_date" class="form-input" value="<?php echo htmlspecialchars($filter_date); ?>">
            </div>
            <div class="filter-group">
                <label class="form-label">Status</label>
                <select name="filter_status" class="form-input">
                    <option value="">All Status</option>
                    <option value="completed"  <?php echo $filter_status === 'completed'  ? 'selected' : ''; ?>>Completed</option>
                    <option value="processing" <?php echo $filter_status === 'processing' ? 'selected' : ''; ?>>Processing</option>
                    <option value="failed"     <?php echo $filter_status === 'failed'     ? 'selected' : ''; ?>>Failed</option>
                    <option value="pending"    <?php echo $filter_status === 'pending'    ? 'selected' : ''; ?>>Pending</option>
                </select>
            </div>
            <div class="filter-group filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-search"></i> Filter
                </button>
                <a href="unloading_import_history.php" class="btn btn-secondary">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </a>
            </div>
        </div>
    </form>
</div>

<!-- History Table -->
<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title">
            <i class="fa-solid fa-list"></i> Import Records
        </h3>
        <a href="import_unloading.php" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-plus"></i> New Import
        </a>
        <a href="unloading_reimport_logs.php" class="btn btn-sm" style="background:#b45309;color:#fff;display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;">
            <i class="fa-solid fa-clock-rotate-left"></i> Re-Import Logs
        </a>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Delivery Date</th>
                    <th>Filename</th>
                    <th>Total</th>
                    <th>Imported</th>
                    <th>Failed</th>
                    <th>Persons</th>
                    <th>SKUs</th>
                    <th>Total Good Adj</th>
                    <th>Total Dmg Adj</th>
                    <th>Status</th>
                    <th>Imported At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result && mysqli_num_rows($result) > 0): ?>
                    <?php $row_num = 1; while ($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td><?php echo $row_num++; ?></td>
                        <td style="font-weight:600;"><?php echo $row['delivery_date'] ? date('Y-m-d', strtotime($row['delivery_date'])) : '-'; ?></td>
                        <td>
                            <span class="filename-text" title="<?php echo htmlspecialchars($row['filename']); ?>">
                                <i class="fa-solid fa-file-excel" style="color: #166534;"></i>
                                <?php echo htmlspecialchars($row['filename']); ?>
                            </span>
                        </td>
                        <td style="font-weight: 600;"><?php echo intval($row['total_records']); ?></td>
                        <td class="text-success"><?php echo intval($row['imported_records']); ?></td>
                        <td class="<?php echo intval($row['failed_records']) > 0 ? 'text-error' : ''; ?>">
                            <?php echo intval($row['failed_records']); ?>
                        </td>
                        <td><?php echo intval($row['person_count']); ?></td>
                        <td><?php echo intval($row['sku_count']); ?></td>
                        <td style="text-align: right; font-weight: 600;"><?php echo number_format($row['total_good'] ?? 0, 2); ?></td>
                        <td style="text-align: right; font-weight: 600;"><?php echo number_format($row['total_damage'] ?? 0, 2); ?></td>
                        <td>
                            <?php
                            $status = $row['status'];
                            $badge_class = 'badge-inactive';
                            $icon = 'fa-clock';
                            if ($status === 'completed')  { $badge_class = 'badge-success'; $icon = 'fa-circle-check'; }
                            elseif ($status === 'processing') { $badge_class = 'badge-warning'; $icon = 'fa-spinner fa-spin'; }
                            elseif ($status === 'failed')  { $badge_class = 'badge-error';   $icon = 'fa-circle-xmark'; }
                            ?>
                            <span class="badge <?php echo $badge_class; ?>">
                                <i class="fa-solid <?php echo $icon; ?>"></i>
                                <?php echo ucfirst($status); ?>
                            </span>
                        </td>
                        <td style="font-size: 12px; color: #666;">
                            <?php echo date('Y-m-d H:i', strtotime($row['imported_at'])); ?>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <a href="unloading_import_history_view.php?id=<?php echo $row['id']; ?>" class="btn-action btn-view" title="View Details">
                                    <i class="fa-solid fa-eye"></i>
                                </a>
                                <a href="javascript:void(0)" onclick="deleteImport(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['filename']); ?>')" class="btn-action btn-delete" title="Delete">
                                    <i class="fa-solid fa-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="13" class="empty-state">
                            <i class="fa-solid fa-inbox"></i>
                            <p>No import records found</p>
                            <a href="import_unloading.php" class="btn btn-primary btn-sm">
                                <i class="fa-solid fa-upload"></i> Import Now
                            </a>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.content-card {
    background: #ffffff;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}
.card-title {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 0;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 8px;
}
.card-header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}
.stats-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}
.stat-card {
    background: #ffffff;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
}
.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}
.stat-info { display: flex; flex-direction: column; }
.stat-value { font-size: 24px; font-weight: 700; color: #1f2937; }
.stat-label { font-size: 12px; color: #6b7280; margin-top: 2px; }
.filter-form { margin: 0; }
.filter-row { display: flex; gap: 16px; align-items: flex-end; }
.filter-group { flex: 1; }
.filter-actions { display: flex; gap: 8px; flex: none; }
.form-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #374151; }
.form-input {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #e5e5e5;
    border-radius: 6px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    box-sizing: border-box;
}
.form-input:focus { outline: none; border-color: #000000; }
.alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; font-size: 13px; }
.alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    font-family: 'Inter', sans-serif;
    text-decoration: none;
}
.btn-sm { padding: 8px 14px; font-size: 13px; }
.btn-primary { background: #000000; color: #ffffff; }
.btn-primary:hover { background: #333333; }
.btn-secondary { background: #f5f5f5; color: #333333; border: 1px solid #e5e5e5; }
.btn-secondary:hover { background: #e5e5e5; }
.table-responsive { overflow-x: auto; }
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table thead { background: #fafafa; border-bottom: 2px solid #e5e5e5; }
.data-table th { padding: 12px; text-align: left; font-weight: 600; color: #333333; font-size: 12px; white-space: nowrap; }
.data-table tbody tr { border-bottom: 1px solid #f0f0f0; }
.data-table tbody tr:hover { background: #fafafa; }
.data-table td { padding: 12px; color: #333333; }
.text-success { color: #22c55e; font-weight: 600; }
.text-error   { color: #ef4444; font-weight: 600; }
.filename-text { display: inline-flex; align-items: center; gap: 6px; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.badge-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.badge-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.badge-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.badge-inactive { background: #fafafa; color: #666666; border: 1px solid #e5e5e5; }
.action-buttons { display: flex; gap: 6px; }
.btn-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 6px;
    border: 1px solid #e5e5e5;
    background: #ffffff;
    color: #666666;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    font-size: 12px;
}
.btn-action:hover { transform: translateY(-2px); box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
.btn-view { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
.btn-view:hover { background: #1e40af; color: #ffffff; }
.btn-delete:hover { background: #ef4444; color: #ffffff; border-color: #ef4444; }
.empty-state { text-align: center; padding: 60px 20px !important; color: #999; }
.empty-state i { font-size: 48px; display: block; margin-bottom: 12px; color: #ddd; }
.empty-state p { margin-bottom: 16px; font-size: 14px; }
@media (max-width: 768px) {
    .stats-row { grid-template-columns: 1fr; }
    .filter-row { flex-direction: column; }
}
</style>

<script>
function deleteImport(id, filename) {
    if (confirm('Delete import "' + filename + '" and all its records?\n\nThis action cannot be undone.')) {
        window.location.href = 'unloading_import_history.php?delete_import=' + id;
    }
}
</script>

<?php include 'footer.php'; ?>