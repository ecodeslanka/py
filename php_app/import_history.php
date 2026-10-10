<?php
include 'config.php';
include 'header.php';

// Handle delete loading summary import
if (isset($_GET['delete_import'])) {
    $import_id = intval($_GET['delete_import']);
    mysqli_query($conn, "DELETE FROM loading_summary_import_details WHERE import_id = $import_id");
    if (mysqli_query($conn, "DELETE FROM loading_summary_imports WHERE id = $import_id")) {
        $success_message = "Import record deleted successfully!";
    } else {
        $error_message = "Failed to delete import record.";
    }
}

// Handle delete secondary invoice import
if (isset($_GET['delete_sinv'])) {
    $import_id = intval($_GET['delete_sinv']);
    mysqli_query($conn, "DELETE FROM secondary_invoice_import_details WHERE import_id = $import_id");
    if (mysqli_query($conn, "DELETE FROM secondary_invoice_imports WHERE id = $import_id")) {
        $success_message = "Secondary Invoice import deleted successfully!";
    } else {
        $error_message = "Failed to delete secondary invoice import record.";
    }
}

// Filters
$filter_date    = isset($_GET['filter_date'])    ? $_GET['filter_date']    : '';
$filter_status  = isset($_GET['filter_status'])  ? $_GET['filter_status']  : '';
$filter_invoice = isset($_GET['filter_invoice']) ? trim($_GET['filter_invoice']) : '';

// ── If invoice number filter is set, find matching delivery dates ─────────────
$invoice_dates = null; // null = no filter; array = restrict to these dates
if ($filter_invoice !== '') {
    $esc_inv = mysqli_real_escape_string($conn, $filter_invoice);
    $invoice_dates = [];

    // Search loading summary details
    $inv_q1 = mysqli_query($conn, "SELECT DISTINCT delivery_date FROM loading_summary_import_details WHERE bill_no LIKE '%$esc_inv%'");
    if ($inv_q1) while ($r = mysqli_fetch_row($inv_q1)) $invoice_dates[] = date('Y-m-d', strtotime($r[0]));

    // Search secondary invoice details
    $inv_q2 = mysqli_query($conn, "SELECT DISTINCT delivery_date FROM secondary_invoice_import_details WHERE bill_no LIKE '%$esc_inv%'");
    if ($inv_q2) while ($r = mysqli_fetch_row($inv_q2)) $invoice_dates[] = date('Y-m-d', strtotime($r[0]));

    $invoice_dates = array_unique($invoice_dates);
}

// ── Loading Summary query ─────────────────────────────────────────────────────
$where = "1=1";
if ($filter_date) {
    $fd = mysqli_real_escape_string($conn, $filter_date);
    $where .= " AND i.delivery_date = '$fd'";
}
if ($filter_status) {
    $fs = mysqli_real_escape_string($conn, $filter_status);
    $where .= " AND i.status = '$fs'";
}
if ($invoice_dates !== null) {
    if (count($invoice_dates) === 0) {
        $where .= " AND 1=0"; // no matches
    } else {
        $in_dates = implode("','", array_map(fn($d) => mysqli_real_escape_string($conn, $d), $invoice_dates));
        $where .= " AND i.delivery_date IN ('$in_dates')";
    }
}

$query = "SELECT i.*,
          COALESCE(SUM(d.final_bill_amount), 0) as total_amount,
          COUNT(DISTINCT d.route_code) as route_count
          FROM loading_summary_imports i
          LEFT JOIN loading_summary_import_details d ON d.import_id = i.id AND d.status = 'imported'
          WHERE $where
          GROUP BY i.id, i.delivery_date, i.filename, i.total_records, i.imported_records, i.failed_records, i.status, i.imported_by, i.imported_at
          ORDER BY i.imported_at DESC";
$result = mysqli_query($conn, $query);

// Build loading summary data keyed by delivery_date
$ls_data = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $ddate = date('Y-m-d', strtotime($row['delivery_date']));
        $ls_data[$ddate] = $row;
    }
}

// ── Secondary Invoice query ───────────────────────────────────────────────────
$sinv_where = "1=1";
if ($filter_date) {
    $sinv_where .= " AND si.delivery_date = '$fd'";
}
if ($filter_status) {
    $sinv_where .= " AND si.status = '$fs'";
}
if ($invoice_dates !== null) {
    if (count($invoice_dates) === 0) {
        $sinv_where .= " AND 1=0";
    } else {
        $sinv_where .= " AND si.delivery_date IN ('$in_dates')";
    }
}

$sinv_query = "SELECT si.*,
               COALESCE(SUM(sd.final_bill_amount), 0) as total_amount,
               COUNT(DISTINCT sd.route_code) as route_count
               FROM secondary_invoice_imports si
               LEFT JOIN secondary_invoice_import_details sd ON sd.import_id = si.id AND sd.status = 'imported'
               WHERE $sinv_where
               GROUP BY si.id, si.delivery_date, si.filename, si.total_records, si.imported_records, si.failed_records, si.status, si.imported_by, si.imported_at
               ORDER BY si.imported_at DESC";
$sinv_result = mysqli_query($conn, $sinv_query);

// Build secondary invoice data keyed by delivery_date
$sinv_data = [];
if ($sinv_result) {
    while ($row = mysqli_fetch_assoc($sinv_result)) {
        $ddate = date('Y-m-d', strtotime($row['delivery_date']));
        $sinv_data[$ddate] = $row;
    }
}

// ── Merge all delivery dates ─────────────────────────────────────────────────
$all_dates = array_unique(array_merge(array_keys($ls_data), array_keys($sinv_data)));
rsort($all_dates); // newest first

// ── Overall stats (both tables combined) ─────────────────────────────────────
$stats_query = "SELECT
    (SELECT COUNT(*) FROM loading_summary_imports) +
    (SELECT COUNT(*) FROM secondary_invoice_imports WHERE 1) as total_imports,
    (SELECT COALESCE(SUM(imported_records),0) FROM loading_summary_imports) +
    (SELECT COALESCE(SUM(imported_records),0) FROM secondary_invoice_imports WHERE 1) as total_imported,
    (SELECT COALESCE(SUM(failed_records),0)   FROM loading_summary_imports) +
    (SELECT COALESCE(SUM(failed_records),0)   FROM secondary_invoice_imports WHERE 1) as total_failed";
$stats_result = mysqli_query($conn, $stats_query);
if (!$stats_result) {
    $stats_query2 = "SELECT COUNT(*) as total_imports,
                     SUM(imported_records) as total_imported,
                     SUM(failed_records) as total_failed
                     FROM loading_summary_imports";
    $stats_result = mysqli_query($conn, $stats_query2);
}
$stats = mysqli_fetch_assoc($stats_result);
?>

<div class="page-header">
    <h2 class="page-title">
        <i class="fa-solid fa-history"></i> Import History
    </h2>
    <p class="page-subtitle">View and manage previously imported loading summary and secondary invoice files</p>
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
                <label class="form-label">Delivery Date</label>
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
            <div class="filter-group">
                <label class="form-label">Invoice No</label>
                <input type="text" name="filter_invoice" class="form-input"
                       placeholder="Search invoice no…"
                       value="<?php echo htmlspecialchars($filter_invoice); ?>">
            </div>
            <div class="filter-group filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-search"></i> Filter
                </button>
                <a href="import_history.php" class="btn btn-secondary">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </a>
            </div>
        </div>
    </form>
    <?php if ($filter_invoice !== '' && $invoice_dates !== null): ?>
    <div style="margin-top:10px; font-size:12px; color:#6b7280;">
        <?php if (count($invoice_dates) > 0): ?>
            <i class="fa-solid fa-circle-info" style="color:#3b82f6;"></i>
            Showing <strong><?php echo count($all_dates); ?></strong> delivery date(s) containing invoice
            <strong style="color:#1f2937;"><?php echo htmlspecialchars($filter_invoice); ?></strong>
        <?php else: ?>
            <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;"></i>
            No records found for invoice <strong style="color:#1f2937;"><?php echo htmlspecialchars($filter_invoice); ?></strong>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!--  COMBINED COMPARISON TABLE                                                -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title">
            <i class="fa-solid fa-code-compare"></i> Import Comparison
        </h3>
        <div class="header-actions">
            <a href="import_fsum.php" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-plus"></i> Create Pre - Secondary Invoice
            </a>
            <a href="import_secondary_invoices.php" class="btn btn-purple btn-sm">
                <i class="fa-solid fa-plus"></i> Update Secondary Invoice
            </a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="data-table comparison-table">
            <thead>
                <tr class="header-group-row">
                    <th rowspan="2" class="th-fixed">#</th>
                    <th rowspan="2" class="th-fixed">Delivery Date</th>
                    <th colspan="3" class="th-group th-group-invoices">No of Invoices</th>
                    <th colspan="3" class="th-group th-group-bill">Final Bill Value</th>
                    <th colspan="2" class="th-group th-group-routes">No of Routes</th>
                    <th rowspan="2" class="th-fixed">Actions</th>
                </tr>
                <tr class="header-sub-row">
                    <!-- No of Invoices sub-headers -->
                    <th class="th-sub th-sub-invoices">Pre-Secondary</th>
                    <th class="th-sub th-sub-invoices">Secondary</th>
                    <th class="th-sub th-sub-invoices th-variance">Variance</th>
                    <!-- Final Bill Value sub-headers -->
                    <th class="th-sub th-sub-bill">Pre-Secondary</th>
                    <th class="th-sub th-sub-bill">Secondary</th>
                    <th class="th-sub th-sub-bill th-variance">Variance</th>
                    <!-- No of Routes sub-headers -->
                    <th class="th-sub th-sub-routes">Pre-Secondary</th>
                    <th class="th-sub th-sub-routes">Secondary</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($all_dates) > 0): ?>
                    <?php $row_num = 1; foreach ($all_dates as $ddate): ?>
                    <?php
                        $ls  = isset($ls_data[$ddate])   ? $ls_data[$ddate]   : null;
                        $si  = isset($sinv_data[$ddate]) ? $sinv_data[$ddate] : null;

                        // Invoice counts
                        $ls_invoices   = $ls  ? intval($ls['imported_records'])  : 0;
                        $si_invoices   = $si  ? intval($si['imported_records'])  : 0;
                        $inv_variance  = $ls_invoices - $si_invoices;

                        // Bill amounts
                        $ls_amount     = $ls  ? floatval($ls['total_amount'])    : 0;
                        $si_amount     = $si  ? floatval($si['total_amount'])    : 0;
                        $amt_variance  = $ls_amount - $si_amount;

                        // Route counts
                        $ls_routes     = $ls  ? intval($ls['route_count'])       : 0;
                        $si_routes     = $si  ? intval($si['route_count'])       : 0;

                        // Highlight row if it matched the invoice search
                        $row_highlight = ($filter_invoice !== '' && $invoice_dates !== null && in_array($ddate, $invoice_dates)) ? ' tr-invoice-match' : '';
                    ?>
                    <tr class="<?php echo $row_highlight; ?>">
                        <td><?php echo $row_num++; ?></td>
                        <td class="td-date td-bold"><?php echo $ddate; ?></td>

                        <!-- No of Invoices -->
                        <td class="td-num"><?php echo $ls_invoices ?: '<span class="td-empty">—</span>'; ?></td>
                        <td class="td-num"><?php echo $si_invoices ?: '<span class="td-empty">—</span>'; ?></td>
                        <td class="td-num td-variance <?php echo $inv_variance > 0 ? 'var-positive' : ($inv_variance < 0 ? 'var-negative' : 'var-zero'); ?>">
                            <?php
                                if ($ls && $si) {
                                    echo ($inv_variance > 0 ? '+' : '') . $inv_variance;
                                } else {
                                    echo '<span class="td-empty">—</span>';
                                }
                            ?>
                        </td>

                        <!-- Final Bill Value -->
                        <td class="td-num td-amount"><?php echo $ls ? number_format($ls_amount, 2) : '<span class="td-empty">—</span>'; ?></td>
                        <td class="td-num td-amount"><?php echo $si ? number_format($si_amount, 2) : '<span class="td-empty">—</span>'; ?></td>
                        <td class="td-num td-amount td-variance <?php echo $amt_variance > 0 ? 'var-positive' : ($amt_variance < 0 ? 'var-negative' : 'var-zero'); ?>">
                            <?php
                                if ($ls && $si) {
                                    echo ($amt_variance > 0 ? '+' : '') . number_format($amt_variance, 2);
                                } else {
                                    echo '<span class="td-empty">—</span>';
                                }
                            ?>
                        </td>

                        <!-- No of Routes -->
                        <td class="td-num"><?php echo $ls_routes ?: '<span class="td-empty">—</span>'; ?></td>
                        <td class="td-num"><?php echo $si_routes ?: '<span class="td-empty">—</span>'; ?></td>

                        <!-- Actions -->
                        <td>
                            <div class="action-buttons">
                                <?php if ($ls): ?>
                                <a href="import_history_view.php?id=<?php echo $ls['id']; ?>" class="btn-action btn-view" title="View Loading Summary">
                                    <i class="fa-solid fa-eye"></i>
                                </a>
                                <a href="printfs.php?id=<?php echo $ls['id']; ?>" class="btn-action btn-print" title="Print Loading Summary" target="_blank">
                                    <i class="fa-solid fa-print"></i>
                                </a>
                                      <a href="printfs_lucas.php?id=<?php echo $ls['id']; ?>" class="btn-action btn-print" title="Print Loading Summary Lucas" target="_blank">
                                    <i class="fa-solid fa-print">L</i>
                                </a>
                                <a href="javascript:void(0)" onclick="confirmDelete('import_history.php?delete_import=<?php echo $ls['id']; ?>', '<?php echo htmlspecialchars($ls['filename']); ?>')" class="btn-action btn-delete" title="Delete Loading Summary">
                                    <i class="fa-solid fa-trash"></i>
                                </a>
                                <?php endif; ?>
                                <?php if ($si): ?>
                                <a href="secondary_import_history.php?filter_date=<?php echo $si['delivery_date']; ?>" class="btn-action btn-view-secondary" title="View Secondary Invoice">
                                    <i class="fa-solid fa-receipt"></i>
                                </a>
                                <a href="javascript:void(0)" onclick="confirmDelete('import_history.php?delete_sinv=<?php echo $si['id']; ?>', '<?php echo htmlspecialchars($si['filename']); ?>')" class="btn-action btn-delete" title="Delete Secondary Invoice">
                                    <i class="fa-solid fa-trash"></i>
                                </a>
                                <?php endif; ?>
                                <a href="risk_customer_bills_report.php?date_from=<?php echo $ddate; ?>&date_to=<?php echo $ddate; ?>" class="btn-action btn-risk" title="Risk / Blacklisted Customer Bills for this delivery date">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="11" class="empty-state">
                            <i class="fa-solid fa-inbox"></i>
                            <p><?php echo $filter_invoice !== '' ? 'No import records found for invoice <strong>' . htmlspecialchars($filter_invoice) . '</strong>' : 'No import records found'; ?></p>
                            <div class="empty-actions">
                                <a href="import_fsum.php" class="btn btn-primary btn-sm">
                                    <i class="fa-solid fa-upload"></i> Import Loading Summary
                                </a>
                                <a href="import_secondary_invoices.php" class="btn btn-purple btn-sm">
                                    <i class="fa-solid fa-upload"></i> Import Secondary Invoice
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
function renderStatusBadge($status) {
    $badge_class = 'badge-inactive';
    $icon = 'fa-clock';
    if ($status === 'completed')  { $badge_class = 'badge-success'; $icon = 'fa-circle-check'; }
    elseif ($status === 'processing') { $badge_class = 'badge-warning'; $icon = 'fa-spinner fa-spin'; }
    elseif ($status === 'failed') { $badge_class = 'badge-error';   $icon = 'fa-circle-xmark'; }
    return '<span class="badge ' . $badge_class . '"><i class="fa-solid ' . $icon . '"></i> ' . ucfirst($status) . '</span>';
}
?>

<style>
.content-card { background:#fff; border:1px solid #e5e5e5; border-radius:8px; padding:20px; margin-bottom:20px; }
.card-title { font-size:16px; font-weight:600; margin-bottom:0; color:#1f2937; display:flex; align-items:center; gap:8px; }
.card-header-row { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
.header-actions { display:flex; gap:8px; }
.stats-row { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:20px; }
.stat-card { background:#fff; border:1px solid #e5e5e5; border-radius:8px; padding:20px; display:flex; align-items:center; gap:16px; }
.stat-icon { width:48px; height:48px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; }
.stat-info { display:flex; flex-direction:column; }
.stat-value { font-size:24px; font-weight:700; color:#1f2937; }
.stat-label { font-size:12px; color:#6b7280; }
.filter-form { }
.filter-row { display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap; }
.filter-group { flex:1; min-width:160px; }
.filter-actions { display:flex; gap:8px; flex-shrink:0; }
.form-label { display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:#374151; }
.form-input { width:100%; padding:10px 14px; border:1px solid #e5e5e5; border-radius:6px; font-size:14px; font-family:'Inter',sans-serif; box-sizing:border-box; }
.form-input:focus { outline:none; border-color:#000; box-shadow:0 0 0 2px rgba(0,0,0,.05); }
.alert { padding:12px 16px; border-radius:6px; margin-bottom:20px; display:flex; align-items:center; gap:8px; font-size:13px; }
.alert-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.alert-error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }

/* Buttons */
.btn { display:inline-flex; align-items:center; gap:6px; padding:10px 20px; border:none; border-radius:6px; font-size:14px; font-weight:600; cursor:pointer; transition:all .3s; font-family:'Inter',sans-serif; text-decoration:none; }
.btn-sm { padding:8px 14px; font-size:13px; }
.btn-primary { background:#000; color:#fff; }
.btn-primary:hover { background:#333; }
.btn-secondary { background:#f5f5f5; color:#333; border:1px solid #e5e5e5; }
.btn-secondary:hover { background:#e5e5e5; }
.btn-purple { background:#7c3aed; color:#fff; border:1px solid #7c3aed; }
.btn-purple:hover { background:#6d28d9; }

/* ── Comparison Table ───────────────────────────────────────────────── */
.table-responsive { overflow-x:auto; }
.data-table { width:100%; border-collapse:collapse; font-size:13px; }

/* Group headers */
.comparison-table .header-group-row th {
    text-align:center; font-size:12px; font-weight:700; color:#1f2937;
    padding:10px 12px; border-bottom:1px solid #e5e5e5;
}
.th-fixed { background:#fafafa !important; }
.th-group { letter-spacing:0.3px; }
.th-group-invoices { background:#eff6ff; color:#1e40af; border-left:2px solid #bfdbfe; border-right:2px solid #bfdbfe; }
.th-group-bill { background:#fef3c7; color:#92400e; border-left:2px solid #fde68a; border-right:2px solid #fde68a; }
.th-group-routes { background:#fce7f3; color:#9d174d; border-left:2px solid #fbcfe8; border-right:2px solid #fbcfe8; }

/* Sub-headers */
.header-sub-row th { padding:8px 12px; font-size:11px; font-weight:600; text-align:center; white-space:nowrap; }
.th-sub-invoices { background:#f0f7ff; color:#1e40af; border-bottom:2px solid #bfdbfe; }
.th-sub-bill { background:#fffbeb; color:#92400e; border-bottom:2px solid #fde68a; }
.th-sub-routes { background:#fdf2f8; color:#9d174d; border-bottom:2px solid #fbcfe8; }
.th-variance { font-style:italic; }

/* Body cells */
.comparison-table tbody tr { border-bottom:1px solid #f0f0f0; }
.comparison-table tbody tr:hover { background:#fafafa; }
.comparison-table td { padding:12px; color:#333; }
.td-date { white-space:nowrap; font-size:13px; }
.td-bold { font-weight:700; }
.td-num { text-align:center; font-weight:600; }
.td-amount { text-align:right; font-family:'JetBrains Mono','Fira Code',monospace; font-size:12px; }
.td-empty { color:#ccc; font-weight:400; }
.td-variance { font-weight:700; }
.var-positive { color:#dc2626; }
.var-negative { color:#16a34a; }
.var-zero { color:#6b7280; }

/* Invoice search match highlight */
.tr-invoice-match { background:#fffbeb !important; }
.tr-invoice-match td { border-left: none; }
.tr-invoice-match td:first-child { border-left:3px solid #f59e0b; }

/* Action buttons */
.action-buttons { display:flex; gap:5px; flex-wrap:nowrap; justify-content:center; }
.btn-action { display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:6px; border:1px solid #e5e5e5; background:#fff; color:#666; cursor:pointer; transition:all .2s; text-decoration:none; font-size:12px; }
.btn-action:hover { transform:translateY(-2px); box-shadow:0 2px 6px rgba(0,0,0,.1); }
.btn-view { background:#eff6ff; color:#1e40af; border-color:#bfdbfe; }
.btn-view:hover { background:#1e40af; color:#fff; }
.btn-view-secondary { background:#f5f3ff; color:#7c3aed; border-color:#ddd6fe; }
.btn-view-secondary:hover { background:#7c3aed; color:#fff; }
.btn-delete:hover { background:#ef4444; color:#fff; border-color:#ef4444; }
.btn-print { background:#f0fdf4; color:#166534; border-color:#bbf7d0; }
.btn-print:hover { background:#166534; color:#fff; border-color:#166534; }
.btn-risk { background:#fff7ed; color:#9a3412; border-color:#fed7aa; }
.btn-risk:hover { background:#9a3412; color:#fff; border-color:#9a3412; }

/* Empty state */
.empty-state { text-align:center; padding:60px 20px !important; color:#999; }
.empty-state i { font-size:48px; display:block; margin-bottom:12px; color:#ddd; }
.empty-state p { margin-bottom:16px; font-size:14px; }
.empty-actions { display:flex; gap:8px; justify-content:center; }

/* Badge (kept for reuse) */
.badge { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:12px; font-size:11px; font-weight:600; white-space:nowrap; }
.badge-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.badge-warning { background:#fef3c7; color:#92400e; border:1px solid #fde68a; }
.badge-error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
.badge-inactive { background:#fafafa; color:#666; border:1px solid #e5e5e5; }

@media(max-width:768px){
    .stats-row{grid-template-columns:1fr}
    .filter-row{flex-direction:column}
    .header-actions{flex-direction:column}
}
</style>

<script>
function confirmDelete(url, filename) {
    if (confirm('Delete import "' + filename + '" and all its records?\n\nThis action cannot be undone.')) {
        window.location.href = url;
    }
}
</script>

<?php include 'footer.php'; ?>