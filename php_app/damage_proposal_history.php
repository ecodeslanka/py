<?php
include 'config.php';
include 'header.php';

// ── Handle Delete ─────────────────────────────────────────────────────────────
if (isset($_GET['delete_import'])) {
    $del_id = intval($_GET['delete_import']);
    mysqli_query($conn, "DELETE FROM damage_proposal_transaction_details WHERE import_id = $del_id");
    if (mysqli_query($conn, "DELETE FROM damage_proposal_imports WHERE id = $del_id")) {
        $success_message = "Import deleted successfully.";
    } else {
        $error_message = "Failed to delete import record.";
    }
}

$current_year  = date('Y');
$filter_month  = isset($_GET['filter_month'])  ? intval($_GET['filter_month'])  : 0;
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status']         : '';
$filter_year   = isset($_GET['filter_year'])   ? intval($_GET['filter_year'])   : $current_year;

$month_names = ['','January','February','March','April','May','June',
                'July','August','September','October','November','December'];

$where = "proposal_year = " . intval($filter_year);
if ($filter_month)  $where .= " AND proposal_month = $filter_month";
if ($filter_status) $where .= " AND status = '" . mysqli_real_escape_string($conn, $filter_status) . "'";

$stats = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as ti, COALESCE(SUM(imported_records),0) as tr, COALESCE(SUM(failed_records),0) as tf
     FROM damage_proposal_imports"));

$result  = mysqli_query($conn, "SELECT * FROM damage_proposal_imports WHERE $where ORDER BY imported_at DESC");
$imports = [];
if ($result) while ($row = mysqli_fetch_assoc($result)) $imports[] = $row;
?>

<div class="page-header">
    <h2 class="page-title"><i class="fa-solid fa-history"></i> Damage &amp; Shortage — Import History</h2>
    <p class="page-subtitle">Manage previously imported Transaction Details reports</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success_message) ?></div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert alert-error"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($error_message) ?></div>
<?php endif; ?>

<div class="stats-row">
    <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff;color:#1e40af;"><i class="fa-solid fa-file-import"></i></div>
        <div class="stat-info"><span class="stat-value"><?= intval($stats['ti']) ?></span><span class="stat-label">Total Imports</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f0fdf4;color:#166534;"><i class="fa-solid fa-circle-check"></i></div>
        <div class="stat-info"><span class="stat-value"><?= number_format($stats['tr']) ?></span><span class="stat-label">Records Imported</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fef2f2;color:#991b1b;"><i class="fa-solid fa-circle-xmark"></i></div>
        <div class="stat-info"><span class="stat-value"><?= number_format($stats['tf']) ?></span><span class="stat-label">Records Failed</span></div>
    </div>
</div>

<div class="content-card">
    <form method="GET" class="filter-form">
        <div class="filter-row">
            <div class="filter-group">
                <label class="form-label">Year</label>
                <select name="filter_year" class="form-input">
                    <?php for ($y = $current_year-2; $y <= $current_year+1; $y++): ?>
                    <option value="<?= $y ?>" <?= $y==$filter_year?'selected':'' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="filter-group">
                <label class="form-label">Month</label>
                <select name="filter_month" class="form-input">
                    <option value="0">All Months</option>
                    <?php for ($m=1;$m<=12;$m++): ?>
                    <option value="<?= $m ?>" <?= $m==$filter_month?'selected':'' ?>><?= $month_names[$m] ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="filter-group">
                <label class="form-label">Status</label>
                <select name="filter_status" class="form-input">
                    <option value="">All Status</option>
                    <option value="completed"  <?= $filter_status==='completed' ?'selected':'' ?>>Completed</option>
                    <option value="processing" <?= $filter_status==='processing'?'selected':'' ?>>Processing</option>
                    <option value="failed"     <?= $filter_status==='failed'    ?'selected':'' ?>>Failed</option>
                </select>
            </div>
            <div class="filter-group filter-actions">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-search"></i> Filter</button>
                <a href="damage_proposal_history.php" class="btn btn-secondary"><i class="fa-solid fa-rotate-left"></i> Reset</a>
            </div>
        </div>
    </form>
</div>

<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title"><i class="fa-solid fa-table-list"></i> Import Records</h3>
        <a href="import_damage_proposal.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> New Import</a>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Period</th>
                    <th>Filename</th>
                    <th>Imported At</th>
                    <th>Total</th>
                    <th>Imported</th>
                    <th>Failed</th>
                    <th>Remarks</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($imports) > 0): $n=1; foreach ($imports as $imp): ?>
                <tr>
                    <td><?= $n++ ?></td>
                    <td><span class="period-badge"><?= $month_names[$imp['proposal_month']] ?> <?= $imp['proposal_year'] ?></span></td>
                    <td class="td-file">
                        <i class="fa-solid fa-file-excel" style="color:#166534;flex-shrink:0;"></i>
                        <span title="<?= htmlspecialchars($imp['filename']) ?>"><?= htmlspecialchars(mb_strimwidth($imp['filename'],0,45,'…')) ?></span>
                    </td>
                    <td class="td-date"><?= date('Y-m-d H:i', strtotime($imp['imported_at'])) ?></td>
                    <td class="td-num"><?= number_format($imp['total_records']) ?></td>
                    <td class="td-num td-ok"><?= number_format($imp['imported_records']) ?></td>
                    <td class="td-num"><?= $imp['failed_records']>0 ? '<span style="color:#991b1b;font-weight:700;">'.number_format($imp['failed_records']).'</span>' : '<span style="color:#ccc;">—</span>' ?></td>
                    <td class="td-remarks"><?= $imp['remarks'] ? htmlspecialchars($imp['remarks']) : '<span style="color:#ccc;">—</span>' ?></td>
                    <td><?= statusBadge($imp['status']) ?></td>
                    <td>
                        <div class="action-buttons">
                            <a href="damage_proposal_view.php?id=<?= $imp['id'] ?>" class="btn-action btn-view" title="View"><i class="fa-solid fa-eye"></i></a>
                            <a href="javascript:void(0)" onclick="confirmDel('damage_proposal_history.php?delete_import=<?= $imp['id'] ?>','<?= htmlspecialchars(addslashes($imp['filename'])) ?>')" class="btn-action btn-delete" title="Delete"><i class="fa-solid fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="10" class="empty-state">
                    <i class="fa-solid fa-inbox"></i>
                    <p>No import records found for the selected filters.</p>
                    <a href="import_damage_proposal.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-upload"></i> Import Now</a>
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php function statusBadge($s) {
    $m=['completed'=>['badge-success','fa-circle-check'],'processing'=>['badge-warning','fa-spinner fa-spin'],'failed'=>['badge-error','fa-circle-xmark']];
    [$c,$i]=$m[$s]??['badge-inactive','fa-clock'];
    return "<span class='badge $c'><i class='fa-solid $i'></i> ".ucfirst($s)."</span>";
} ?>

<style>
.page-header{margin-bottom:24px}
.page-title{font-size:22px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:10px;margin:0 0 6px}
.page-subtitle{color:#6b7280;font-size:14px;margin:0}
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:24px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;color:#1f2937;display:flex;align-items:center;gap:8px;margin:0}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.alert{padding:13px 16px;border-radius:8px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:14px}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:20px;display:flex;align-items:center;gap:16px}
.stat-icon{width:48px;height:48px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px}
.stat-info{display:flex;flex-direction:column}
.stat-value{font-size:24px;font-weight:700;color:#1f2937}
.stat-label{font-size:12px;color:#6b7280}
.filter-row{display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap}
.filter-group{flex:1;min-width:140px}
.filter-actions{display:flex;gap:8px;flex-shrink:0}
.form-label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px}
.form-input{width:100%;padding:10px 14px;border:1px solid #e5e5e5;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box}
.form-input:focus{outline:none;border-color:#000}
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead th{background:#fafafa;padding:10px 12px;text-align:left;font-size:12px;font-weight:700;color:#374151;border-bottom:2px solid #e5e5e5;white-space:nowrap}
.data-table tbody tr{border-bottom:1px solid #f0f0f0}
.data-table tbody tr:hover{background:#fafafa}
.data-table td{padding:12px;color:#333;vertical-align:middle}
.td-date{white-space:nowrap;font-size:12px;color:#6b7280}
.td-num{text-align:center;font-weight:600}
.td-ok{color:#166534}
.td-file{display:flex;align-items:center;gap:8px;max-width:260px}
.td-remarks{max-width:180px;font-size:12px;color:#6b7280}
.period-badge{background:#eff6ff;color:#1e40af;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;white-space:nowrap}
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
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap}
.badge-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.badge-warning{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.badge-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.badge-inactive{background:#fafafa;color:#666;border:1px solid #e5e5e5}
.empty-state{text-align:center;padding:60px 20px;color:#999}
.empty-state i{font-size:48px;display:block;margin-bottom:12px;color:#ddd}
.empty-state p{margin-bottom:16px;font-size:14px}
@media(max-width:768px){.stats-row{grid-template-columns:1fr}.filter-row{flex-direction:column}}
</style>
<script>
function confirmDel(url,name){
    if(confirm('Delete import "'+name+'" and ALL its records?\n\nThis cannot be undone.'))window.location.href=url;
}
</script>
<?php include 'footer.php'; ?>
