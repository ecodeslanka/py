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

// Helper: run a prepared statement and return single scalar value
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

// Handle delete
if (isset($_POST['delete_id'])) {
    $del_id = (int)$_POST['delete_id'];
    mysqli_begin_transaction($conn);
    try {
        $s1 = mysqli_prepare($conn, "DELETE FROM daily_stock_items WHERE upload_id = ?");
        mysqli_stmt_bind_param($s1, 'i', $del_id);
        mysqli_stmt_execute($s1);
        mysqli_stmt_close($s1);

        $s2 = mysqli_prepare($conn, "DELETE FROM daily_stock_uploads WHERE id = ?");
        mysqli_stmt_bind_param($s2, 'i', $del_id);
        mysqli_stmt_execute($s2);
        mysqli_stmt_close($s2);

        mysqli_commit($conn);
        $delete_success = true;
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $delete_error = $e->getMessage();
    }
}

// Filters
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to   = $_GET['date_to']   ?? '';
$filter_search    = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$where  = ['1=1'];
$params = [];
if ($filter_date_from) { $where[] = 'u.delivery_date >= ?'; $params[] = $filter_date_from; }
if ($filter_date_to)   { $where[] = 'u.delivery_date <= ?'; $params[] = $filter_date_to; }
if ($filter_search) {
    $where[] = '(u.upload_ref LIKE ? OR u.original_filename LIKE ?)';
    $params[] = "%$filter_search%";
    $params[] = "%$filter_search%";
}
$where_sql = implode(' AND ', $where);

$total       = (int)db_scalar($conn, "SELECT COUNT(*) FROM daily_stock_uploads u WHERE $where_sql", $params);
$total_pages = ceil($total / $limit);

$list_params   = array_merge($params, [$limit, $offset]);
$uploads       = db_query($conn,
    "SELECT u.*, usr.username as uploader_name
     FROM daily_stock_uploads u
     LEFT JOIN users usr ON u.uploaded_by = usr.id
     WHERE $where_sql
     ORDER BY u.delivery_date DESC, u.uploaded_at DESC
     LIMIT ? OFFSET ?",
    $list_params
);

// Summary stats
$summary = db_query($conn,
    "SELECT COUNT(*) as total_uploads,
            COALESCE(SUM(total_rows),0) as total_records,
            MIN(delivery_date) as first_date,
            MAX(delivery_date) as last_date
     FROM daily_stock_uploads"
)[0] ?? ['total_uploads'=>0,'total_records'=>0,'first_date'=>null,'last_date'=>null];
?>
<?php include 'header.php'; ?>

<style>
.page-card {
    background: #fff; border-radius: 12px; border: 1px solid #e5e7eb;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06); overflow: hidden;
}
.page-card-header {
    padding: 18px 24px; border-bottom: 1px solid #e5e7eb;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
}
.page-card-header h2 { font-size: 15px; font-weight: 700; color: #111; margin: 0; display:flex;align-items:center;gap:8px; }
.filter-bar {
    padding: 16px 24px; background: #f9fafb; border-bottom: 1px solid #e5e7eb;
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
}
.filter-input {
    padding: 8px 12px; border: 1.5px solid #d1d5db; border-radius: 7px;
    font-size: 13px; font-family: 'Inter',sans-serif; color: #111; background: #fff;
    outline: none; transition: border-color 0.2s;
}
.filter-input:focus { border-color: #000; }
.btn {
    padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 600;
    font-family: 'Inter',sans-serif; cursor: pointer; border: none;
    display: inline-flex; align-items: center; gap: 7px; transition: all 0.15s;
}
.btn-primary { background: #000; color: #fff; }
.btn-primary:hover { background: #1a1a1a; }
.btn-outline { background: #fff; color: #374151; border: 1.5px solid #d1d5db; }
.btn-outline:hover { border-color: #000; color: #000; }
.btn-danger { background: #fef2f2; color: #dc2626; border: 1.5px solid #fecaca; }
.btn-danger:hover { background: #fee2e2; }
.btn-sm { padding: 6px 12px; font-size: 12px; }
.btn-xs { padding: 4px 10px; font-size: 12px; }

/* Table */
.data-table { width: 100%; border-collapse: collapse; }
.data-table th {
    background: #f9fafb; font-weight: 600; color: #6b7280; padding: 12px 16px;
    text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;
    border-bottom: 1px solid #e5e7eb; white-space: nowrap;
}
.data-table td { padding: 13px 16px; border-bottom: 1px solid #f3f4f6; color: #374151; font-size: 13px; vertical-align: middle; }
.data-table tr:last-child td { border-bottom: none; }
.data-table tbody tr:hover td { background: #fafafa; }

.badge {
    display: inline-flex; align-items: center; padding: 3px 10px;
    border-radius: 20px; font-size: 12px; font-weight: 500;
}
.badge-blue { background: #eff6ff; color: #1d4ed8; }
.badge-green { background: #f0fdf4; color: #16a34a; }
.badge-gray { background: #f3f4f6; color: #374151; }

/* Stats strip */
.stats-strip { display:grid; grid-template-columns:repeat(4,1fr); gap:0; border-bottom:1px solid #e5e7eb; }
.stat-item { padding:18px 24px; border-right:1px solid #e5e7eb; }
.stat-item:last-child { border-right:none; }
.stat-val { font-size:22px; font-weight:700; color:#111; line-height:1.2; }
.stat-lbl { font-size:12px; color:#9ca3af; font-weight:500; margin-top:2px; }

/* Pagination */
.pagination { display:flex; align-items:center; gap:6px; padding:16px 24px; border-top:1px solid #e5e7eb; flex-wrap:wrap; }
.page-btn {
    min-width:34px; height:34px; display:inline-flex; align-items:center; justify-content:center;
    border-radius:7px; font-size:13px; font-weight:500; cursor:pointer; text-decoration:none;
    border: 1.5px solid #d1d5db; color:#374151; background:#fff; transition:all 0.15s;
}
.page-btn:hover { border-color:#000; color:#000; }
.page-btn.active { background:#000; color:#fff; border-color:#000; }
.page-btn.disabled { opacity:0.4; pointer-events:none; }

/* Alert */
.alert { padding:13px 16px; border-radius:8px; margin-bottom:20px; font-size:13px; display:flex;align-items:center;gap:10px; }
.alert-success { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
.alert-danger  { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }

/* Delete modal */
.modal-overlay {
    display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999;
    align-items:center; justify-content:center;
}
.modal-overlay.active { display:flex; }
.modal-box {
    background:#fff; border-radius:14px; padding:28px 32px; max-width:420px; width:90%;
    box-shadow:0 20px 60px rgba(0,0,0,0.2);
}
.modal-title { font-size:16px; font-weight:700; color:#111; margin:0 0 8px; }
.modal-body  { font-size:13px; color:#6b7280; margin:0 0 20px; line-height:1.6; }
.modal-actions { display:flex; gap:10px; justify-content:flex-end; }

@media(max-width:768px) {
    .stats-strip { grid-template-columns:1fr 1fr; }
    .filter-bar { flex-direction:column; align-items:stretch; }
    .data-table th, .data-table td { padding:10px 12px; }
}
</style>

<div style="padding:24px;">
    <!-- Page Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
        <div>
            <h1 style="font-size:20px;font-weight:700;color:#111;margin:0;">
                <i class="fa-solid fa-boxes-stacked"></i> Daily Stock Uploads
            </h1>
            <p style="font-size:13px;color:#9ca3af;margin:4px 0 0;">History of all stock file imports</p>
        </div>
        <a href="daily_stock_import_history.php" class="btn btn-primary">
            <i class="fa-solid fa-cloud-arrow-up"></i> Upload New Stock
        </a>
    </div>

    <?php if (!empty($delete_success)): ?>
        <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> Upload deleted successfully.</div>
    <?php endif; ?>
    <?php if (!empty($delete_error)): ?>
        <div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Error: <?php echo htmlspecialchars($delete_error); ?></div>
    <?php endif; ?>

    <div class="page-card">
        <!-- Stats Strip -->
        <div class="stats-strip">
            <div class="stat-item">
                <div class="stat-val"><?php echo number_format($summary['total_uploads']); ?></div>
                <div class="stat-lbl">Total Uploads</div>
            </div>
            <div class="stat-item">
                <div class="stat-val"><?php echo number_format($summary['total_records']); ?></div>
                <div class="stat-lbl">Total Records</div>
            </div>
            <div class="stat-item">
                <div class="stat-val"><?php echo $summary['first_date'] ? date('d M Y', strtotime($summary['first_date'])) : '—'; ?></div>
                <div class="stat-lbl">Earliest Upload</div>
            </div>
            <div class="stat-item">
                <div class="stat-val"><?php echo $summary['last_date'] ? date('d M Y', strtotime($summary['last_date'])) : '—'; ?></div>
                <div class="stat-lbl">Latest Upload</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <form method="GET" class="filter-bar">
            <div style="display:flex;align-items:center;gap:6px;">
                <i class="fa-solid fa-magnifying-glass" style="color:#9ca3af;font-size:13px;"></i>
                <input type="text" name="search" class="filter-input"
                       placeholder="Search ref / filename..."
                       value="<?php echo htmlspecialchars($filter_search); ?>" style="width:200px;">
            </div>
            <div style="display:flex;align-items:center;gap:6px;">
                <span style="font-size:12px;color:#9ca3af;font-weight:600;">FROM</span>
                <input type="date" name="date_from" class="filter-input" value="<?php echo $filter_date_from; ?>">
            </div>
            <div style="display:flex;align-items:center;gap:6px;">
                <span style="font-size:12px;color:#9ca3af;font-weight:600;">TO</span>
                <input type="date" name="date_to" class="filter-input" value="<?php echo $filter_date_to; ?>">
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
            <?php if ($filter_search || $filter_date_from || $filter_date_to): ?>
                <a href="daily_stock_list.php" class="btn btn-outline btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
            <?php endif; ?>
            <span style="font-size:12px;color:#9ca3af;margin-left:auto;">
                <?php echo number_format($total); ?> upload<?php echo $total != 1 ? 's' : ''; ?> found
            </span>
        </form>

        <!-- Table -->
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Reference</th>
                        <th>Delivery Date</th>
                        <th>File Name</th>
                        <th>Records</th>
                        <th>Uploaded By</th>
                        <th>Uploaded At</th>
                        <th>Notes</th>
                        <th style="text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($uploads) === 0): ?>
                        <tr>
                            <td colspan="9" style="text-align:center;padding:40px;color:#9ca3af;">
                                <i class="fa-solid fa-inbox" style="font-size:28px;display:block;margin-bottom:8px;"></i>
                                No uploads found
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($uploads as $i => $u): ?>
                        <tr>
                            <td style="color:#9ca3af;font-size:12px;"><?php echo $offset + $i + 1; ?></td>
                            <td>
                                <span class="badge badge-blue" style="font-size:11px;letter-spacing:0.3px;">
                                    <?php echo htmlspecialchars($u['upload_ref']); ?>
                                </span>
                            </td>
                            <td>
                                <div style="font-weight:600;color:#111;"><?php echo date('d M Y', strtotime($u['delivery_date'])); ?></div>
                                <div style="font-size:11px;color:#9ca3af;"><?php echo date('l', strtotime($u['delivery_date'])); ?></div>
                            </td>
                            <td style="max-width:180px;">
                                <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:#6b7280;" title="<?php echo htmlspecialchars($u['original_filename']); ?>">
                                    <i class="fa-solid fa-file-excel" style="color:#16a34a;margin-right:4px;"></i>
                                    <?php echo htmlspecialchars($u['original_filename']); ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-green"><?php echo number_format($u['total_rows']); ?> rows</span>
                            </td>
                            <td>
                                <div style="font-weight:500;"><?php echo htmlspecialchars($u['uploader_name'] ?? '—'); ?></div>
                            </td>
                            <td style="white-space:nowrap;">
                                <div><?php echo date('d M Y', strtotime($u['uploaded_at'])); ?></div>
                                <div style="font-size:11px;color:#9ca3af;"><?php echo date('h:i A', strtotime($u['uploaded_at'])); ?></div>
                            </td>
                            <td style="max-width:150px;">
                                <div style="font-size:12px;color:#6b7280;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($u['notes'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($u['notes'] ?: '—'); ?>
                                </div>
                            </td>
                            <td>
                                <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                                    <a href="daily_stock_view.php?id=<?php echo $u['id']; ?>"
                                       class="btn btn-outline btn-xs" title="View Records">
                                        <i class="fa-solid fa-eye"></i> View
                                    </a>
                                    <button class="btn btn-danger btn-xs" title="Delete Upload"
                                            onclick="confirmDelete(<?php echo $u['id']; ?>, '<?php echo addslashes(date('d M Y', strtotime($u['delivery_date']))); ?>', <?php echo $u['total_rows']; ?>)">
                                        <i class="fa-solid fa-trash"></i> Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $page-1)])); ?>"
               class="page-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                <i class="fa-solid fa-chevron-left"></i>
            </a>
            <?php for ($p = max(1, $page-2); $p <= min($total_pages, $page+2); $p++): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>"
                   class="page-btn <?php echo $p == $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($total_pages, $page+1)])); ?>"
               class="page-btn <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                <i class="fa-solid fa-chevron-right"></i>
            </a>
            <span style="font-size:12px;color:#9ca3af;margin-left:8px;">
                Page <?php echo $page; ?> of <?php echo $total_pages; ?>
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
            <div style="width:44px;height:44px;background:#fef2f2;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;font-size:18px;"></i>
            </div>
            <div class="modal-title">Delete Upload?</div>
        </div>
        <div class="modal-body" id="deleteModalBody">
            This will permanently delete all stock records for this upload date.
        </div>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="delete_id" id="deleteId">
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fa-solid fa-trash"></i> Yes, Delete
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function confirmDelete(id, date, rows) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteModalBody').innerHTML =
        `This will permanently delete <strong>${rows.toLocaleString()} stock records</strong> for delivery date <strong>${date}</strong>. This action cannot be undone.`;
    document.getElementById('deleteModal').classList.add('active');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
}
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});
</script>

<?php include 'footer.php'; ?>
