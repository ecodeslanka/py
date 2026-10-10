<?php
include 'config.php';
include 'header.php';

// ── Handle Delete ─────────────────────────────────────────────────────────────
if (isset($_GET['delete_import'])) {
    $del_id = intval($_GET['delete_import']);
    mysqli_query($conn, "DELETE FROM ulcl_ledger  WHERE import_id = $del_id");
    if (mysqli_query($conn, "DELETE FROM ulcl_imports WHERE id = $del_id")) {
        $success_message = "Import deleted successfully.";
    } else {
        $error_message = "Failed to delete import record.";
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$f_company   = isset($_GET['f_company'])   ? $_GET['f_company']   : '';
$f_date_from = isset($_GET['f_date_from']) ? trim($_GET['f_date_from']) : '';
$f_date_to   = isset($_GET['f_date_to'])   ? trim($_GET['f_date_to'])   : '';
$f_status    = isset($_GET['f_status'])    ? $_GET['f_status']    : '';

$where_parts = ['1=1'];
if ($f_company)   { $w = mysqli_real_escape_string($conn,$f_company);   $where_parts[] = "company='$w'"; }
if ($f_date_from) { $w = mysqli_real_escape_string($conn,$f_date_from); $where_parts[] = "entry_date>='$w'"; }
if ($f_date_to)   { $w = mysqli_real_escape_string($conn,$f_date_to);   $where_parts[] = "entry_date<='$w'"; }
if ($f_status)    { $w = mysqli_real_escape_string($conn,$f_status);    $where_parts[] = "status='$w'"; }
$where = implode(' AND ', $where_parts);

// ── Stats (always across ALL records, ignoring filters) ───────────────────────
$sq = mysqli_query($conn, "SELECT
    COUNT(*)                           AS ti,
    COALESCE(SUM(total_records),0)     AS tr,
    COALESCE(SUM(imported_records),0)  AS ti2,
    COALESCE(SUM(duplicate_records),0) AS td,
    COALESCE(SUM(failed_records),0)    AS tf
    FROM ulcl_imports");
$s = $sq ? mysqli_fetch_assoc($sq) : ['ti'=>0,'tr'=>0,'ti2'=>0,'td'=>0,'tf'=>0];

// ── Filtered records ──────────────────────────────────────────────────────────
$result  = mysqli_query($conn, "SELECT * FROM ulcl_imports WHERE $where ORDER BY imported_at DESC");
$imports = [];
if ($result) while ($row = mysqli_fetch_assoc($result)) $imports[] = $row;

// ── Helpers ───────────────────────────────────────────────────────────────────
function statusBadge($st) {
    $m = [
        'completed'  => ['badge-success', 'fa-circle-check'],
        'processing' => ['badge-warning', 'fa-spinner fa-spin'],
        'failed'     => ['badge-error',   'fa-circle-xmark'],
    ];
    [$c, $i] = $m[$st] ?? ['badge-inactive', 'fa-clock'];
    return "<span class='badge $c'><i class='fa-solid $i'></i> " . ucfirst($st) . "</span>";
}
function companyBadge($co) {
    $col = $co === 'ULCL'
        ? 'style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe"'
        : 'style="background:#fdf4ff;color:#7e22ce;border:1px solid #e9d5ff"';
    return "<span class='badge' $col>" . htmlspecialchars($co) . "</span>";
}
?>

<div class="page-header">
    <div>
        <h2 class="page-title"><i class="fa-solid fa-history"></i> Unilever Customer Ledger — Import History</h2>
        <p class="page-subtitle">Manage previously imported ULCL / USLL ledger records</p>
    </div>
    <a href="import_ulcl.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> New Import</a>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success_message) ?></div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert alert-error"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($error_message) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-row">
    <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff;color:#1e40af;"><i class="fa-solid fa-file-import"></i></div>
        <div class="stat-info">
            <span class="stat-value"><?= number_format($s['ti']) ?></span>
            <span class="stat-label">Total Imports</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f5f3ff;color:#7e22ce;"><i class="fa-solid fa-list"></i></div>
        <div class="stat-info">
            <span class="stat-value"><?= number_format($s['tr']) ?></span>
            <span class="stat-label">Total Rows</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f0fdf4;color:#166534;"><i class="fa-solid fa-circle-check"></i></div>
        <div class="stat-info">
            <span class="stat-value"><?= number_format($s['ti2']) ?></span>
            <span class="stat-label">Imported</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fef3c7;color:#92400e;"><i class="fa-solid fa-copy"></i></div>
        <div class="stat-info">
            <span class="stat-value"><?= number_format($s['td']) ?></span>
            <span class="stat-label">Duplicates Skipped</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fef2f2;color:#991b1b;"><i class="fa-solid fa-circle-xmark"></i></div>
        <div class="stat-info">
            <span class="stat-value"><?= number_format($s['tf']) ?></span>
            <span class="stat-label">Failed</span>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="content-card">
    <form method="GET" class="filter-form">
        <div class="filter-row">
            <div class="filter-group">
                <label class="form-label">Company</label>
                <select name="f_company" class="form-input">
                    <option value="">All Companies</option>
                    <option value="ULCL" <?= $f_company === 'ULCL' ? 'selected' : '' ?>>ULCL</option>
                    <option value="USLL" <?= $f_company === 'USLL' ? 'selected' : '' ?>>USLL</option>
                </select>
            </div>
            <div class="filter-group">
                <label class="form-label">Entry Date From</label>
                <input type="date" name="f_date_from" class="form-input" value="<?= htmlspecialchars($f_date_from) ?>">
            </div>
            <div class="filter-group">
                <label class="form-label">Entry Date To</label>
                <input type="date" name="f_date_to" class="form-input" value="<?= htmlspecialchars($f_date_to) ?>">
            </div>
            <div class="filter-group">
                <label class="form-label">Status</label>
                <select name="f_status" class="form-input">
                    <option value="">All Status</option>
                    <option value="completed"  <?= $f_status === 'completed'  ? 'selected' : '' ?>>Completed</option>
                    <option value="processing" <?= $f_status === 'processing' ? 'selected' : '' ?>>Processing</option>
                    <option value="failed"     <?= $f_status === 'failed'     ? 'selected' : '' ?>>Failed</option>
                </select>
            </div>
            <div class="filter-group filter-actions">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-search"></i> Filter</button>
                <a href="ulcl_history.php" class="btn btn-secondary"><i class="fa-solid fa-rotate-left"></i> Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- Table -->
<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title">
            <i class="fa-solid fa-table-list"></i> Import Records
            <?php if (count($imports)): ?>
                <span class="record-count"><?= count($imports) ?></span>
            <?php endif; ?>
        </h3>
        <a href="import_ulcl.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> New Import</a>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Company</th>
                    <th>Entry Date</th>
                    <th>Filename</th>
                    <th>Imported At</th>
                    <th>Total</th>
                    <th>Imported</th>
                    <th>Duplicates</th>
                    <th>Failed</th>
                    <th>Remarks</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (count($imports)): $n = 1; foreach ($imports as $imp): ?>
                <tr>
                    <td class="td-seq"><?= $n++ ?></td>
                    <td><?= companyBadge($imp['company']) ?></td>
                    <td>
                        <span class="date-badge">
                            <i class="fa-solid fa-calendar-day"></i>
                            <?= date('d M Y', strtotime($imp['entry_date'])) ?>
                        </span>
                    </td>
                    <td class="td-file">
                        <i class="fa-solid fa-file-excel" style="color:#166534;flex-shrink:0;"></i>
                        <span title="<?= htmlspecialchars($imp['filename']) ?>">
                            <?= htmlspecialchars(mb_strimwidth($imp['filename'], 0, 45, '…')) ?>
                        </span>
                    </td>
                    <td class="td-date"><?= date('Y-m-d H:i', strtotime($imp['imported_at'])) ?></td>
                    <td class="td-num"><?= number_format($imp['total_records']) ?></td>
                    <td class="td-num td-ok"><?= number_format($imp['imported_records']) ?></td>
                    <td class="td-num">
                        <?= $imp['duplicate_records'] > 0
                            ? '<span style="color:#92400e;font-weight:700;">' . number_format($imp['duplicate_records']) . '</span>'
                            : '<span style="color:#ccc">—</span>' ?>
                    </td>
                    <td class="td-num">
                        <?= $imp['failed_records'] > 0
                            ? '<span style="color:#991b1b;font-weight:700;">' . number_format($imp['failed_records']) . '</span>'
                            : '<span style="color:#ccc">—</span>' ?>
                    </td>
                    <td class="td-remarks">
                        <?= $imp['remarks'] ? htmlspecialchars($imp['remarks']) : '<span style="color:#ccc">—</span>' ?>
                    </td>
                    <td><?= statusBadge($imp['status']) ?></td>
                    <td>
                        <div class="action-buttons">
                            <a href="ulcl_view.php?id=<?= $imp['id'] ?>" class="btn-action btn-view" title="View Records">
                                <i class="fa-solid fa-eye"></i>
                            </a>
                            <a href="javascript:void(0)"
                               onclick="confirmDel('ulcl_history.php?delete_import=<?= $imp['id'] ?>','<?= htmlspecialchars(addslashes($imp['filename'])) ?>')"
                               class="btn-action btn-delete" title="Delete">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr>
                    <td colspan="12" class="empty-state">
                        <i class="fa-solid fa-inbox"></i>
                        <p>No import records found<?= ($f_company || $f_date_from || $f_date_to || $f_status) ? ' for the selected filters' : '' ?>.</p>
                        <a href="import_ulcl.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-upload"></i> Import Now</a>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.page-header{margin-bottom:24px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px}
.page-title{font-size:22px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:10px;margin:0 0 6px}
.page-subtitle{color:#6b7280;font-size:14px;margin:0}
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:24px;margin-bottom:20px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.card-title{font-size:16px;font-weight:600;color:#1f2937;display:flex;align-items:center;gap:8px;margin:0}
.record-count{background:#eff6ff;color:#1e40af;font-size:11px;padding:2px 8px;border-radius:99px;font-weight:700}
.alert{padding:13px 16px;border-radius:8px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:14px}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.stats-row{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:16px;display:flex;align-items:center;gap:14px}
.stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.stat-info{display:flex;flex-direction:column}
.stat-value{font-size:22px;font-weight:700;color:#1f2937}
.stat-label{font-size:11px;color:#6b7280}
.filter-row{display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap}
.filter-group{flex:1;min-width:130px}
.filter-actions{display:flex;gap:8px;flex-shrink:0}
.form-label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px}
.form-input{width:100%;padding:9px 12px;border:1px solid #e5e5e5;border-radius:7px;font-size:13px;font-family:inherit;box-sizing:border-box}
.form-input:focus{outline:none;border-color:#000}
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead th{background:#fafafa;padding:10px 12px;text-align:left;font-size:11px;font-weight:700;color:#374151;border-bottom:2px solid #e5e5e5;white-space:nowrap}
.data-table tbody tr{border-bottom:1px solid #f0f0f0}
.data-table tbody tr:hover{background:#fafafa}
.data-table td{padding:11px 12px;color:#333;vertical-align:middle}
.td-seq{color:#9ca3af;font-size:11px}
.td-date{white-space:nowrap;font-size:12px;color:#6b7280}
.td-num{text-align:center;font-weight:600}
.td-ok{color:#166534}
.td-file{display:flex;align-items:center;gap:8px;max-width:260px}
.td-remarks{max-width:160px;font-size:12px;color:#6b7280}
.date-badge{background:#eff6ff;color:#1e40af;padding:4px 10px;border-radius:8px;font-size:12px;font-weight:600;white-space:nowrap;display:inline-flex;align-items:center;gap:5px}
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap}
.badge-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.badge-warning{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.badge-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.badge-inactive{background:#fafafa;color:#666;border:1px solid #e5e5e5}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s}
.btn-sm{padding:8px 14px;font-size:13px}
.btn-primary{background:#000;color:#fff}
.btn-primary:hover{background:#333}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}
.action-buttons{display:flex;gap:5px}
.btn-action{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#666;cursor:pointer;transition:all .2s;text-decoration:none;font-size:12px}
.btn-view{background:#eff6ff;color:#1e40af;border-color:#bfdbfe}
.btn-view:hover{background:#1e40af;color:#fff}
.btn-delete:hover{background:#ef4444;color:#fff;border-color:#ef4444}
.empty-state{text-align:center;padding:60px 20px;color:#999}
.empty-state i{font-size:48px;display:block;margin-bottom:12px;color:#ddd}
.empty-state p{margin-bottom:16px;font-size:14px}
@media(max-width:900px){
    .stats-row{grid-template-columns:repeat(2,1fr)}
    .filter-row{flex-direction:column}
    .page-header{flex-direction:column}
}
</style>

<script>
function confirmDel(url, name) {
    if (confirm('Delete import "' + name + '" and ALL its ledger records?\n\nThis cannot be undone.'))
        window.location.href = url;
}
</script>

<?php include 'footer.php'; ?>