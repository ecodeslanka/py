<?php
ob_start(); // buffer everything so stray whitespace/BOM from includes can't break the Excel export headers below
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
mysqli_report(MYSQLI_REPORT_OFF);
$session_user = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';

// ── CREATE TABLE IF NOT EXISTS ─────────────────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS salary_advances (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        employee_id     INT NOT NULL,
        request_date    DATE NOT NULL,
        amount          DECIMAL(12,2) NOT NULL,
        reason          TEXT,
        payroll_period_id INT,
        status          ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
        approved_by     VARCHAR(100),
        approved_at     DATETIME,
        rejection_note  TEXT,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── SAFE MIGRATIONS (columns used by this page / add_salary_advance.php) ───
try {
    $existing_cols = [];
    $colres = mysqli_query($conn, "SHOW COLUMNS FROM salary_advances");
    if ($colres) { while ($c = mysqli_fetch_assoc($colres)) $existing_cols[] = $c['Field']; }
    if (!in_array('remarks', $existing_cols)) {
        mysqli_query($conn, "ALTER TABLE salary_advances ADD COLUMN remarks TEXT NULL AFTER rejection_note");
    }
    if (!in_array('created_by', $existing_cols)) {
        mysqli_query($conn, "ALTER TABLE salary_advances ADD COLUMN created_by VARCHAR(100) NULL AFTER reason");
    }
} catch (\Throwable $e) { /* non-fatal — page still works without these columns */ }

$month_names = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

// ── AJAX: INLINE AMOUNT UPDATE (double-click a row's amount, edit, hit Update) ─────────────
// Handled as its own early request/response cycle (JSON, no full page reload). This is more
// reliable than relying on a nested <form> submit inside the bulk-select table (which could
// get swallowed by the surrounding form on Enter in some browsers), and it reports back the
// value actually stored in the DB so the on-screen amount — and anything reading it, like the
// Approve modal — is always in sync with what was saved.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajax_update_amount') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    $aid    = intval($_POST['advance_id'] ?? 0);
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $response = ['success' => false, 'message' => 'Please enter a valid amount greater than 0.'];
    if ($aid > 0 && $amount > 0) {
        mysqli_query($conn, "UPDATE salary_advances SET amount=$amount WHERE id=$aid");
        if (mysqli_error($conn)) {
            $response = ['success' => false, 'message' => 'Database error while updating the amount.'];
        } else {
            // Read back the stored value regardless of whether affected_rows was 0 — that only
            // means the new value matched the old one, which is not a failure.
            $check = mysqli_query($conn, "SELECT amount FROM salary_advances WHERE id=$aid");
            $row   = $check ? mysqli_fetch_assoc($check) : null;
            if ($row) {
                $response = ['success' => true, 'id' => $aid, 'amount' => (float)$row['amount']];
            } else {
                $response = ['success' => false, 'message' => 'That request could not be found.'];
            }
        }
    }
    echo json_encode($response);
    exit;
}

// ── FETCH ALL PAYROLL PERIODS FOR DROPDOWNS ────────────────────────────────
$payroll_periods_list = [];
$pp_res = mysqli_query($conn, "SELECT id, year, month, status FROM payroll_periods ORDER BY year DESC, month DESC");
if ($pp_res) while ($pp = mysqli_fetch_assoc($pp_res)) $payroll_periods_list[] = $pp;

// ─────────────────────────────────────────────────────────────────────────
// EXCEL EXPORT (shared renderer — used by both "Export Excel" (filtered list)
// and "Export Selected" (bulk-checked rows) so both produce a real,
// Excel-native table: correct numeric cells, no thousands-separator text bug,
// proper headers/merged title, opens cleanly without the "format doesn't
// match extension" warning.
// ─────────────────────────────────────────────────────────────────────────
function export_salary_advances_excel($rows, $month_names, $subtitle, $meta_lines = []) {
    // Discard anything accidentally buffered before this point (BOM, whitespace,
    // stray notices from config.php/includes) so it can't corrupt the download
    // or cause "headers already sent" — which is what makes the browser hang
    // on "loading" instead of downloading the file.
    while (ob_get_level() > 0) { ob_end_clean(); }

    $filename = 'salary_advances_' . date('Ymd_His') . '.xls';
    if (!headers_sent()) {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
    }

    $pending_count = 0; $approved_count = 0; $rejected_count = 0; $total_approved = 0; $total_amount = 0;
    foreach ($rows as $a) {
        $total_amount += (float)$a['amount'];
        if ($a['status'] === 'Pending')  $pending_count++;
        if ($a['status'] === 'Approved') { $approved_count++; $total_approved += (float)$a['amount']; }
        if ($a['status'] === 'Rejected') $rejected_count++;
    }

    echo <<<HTMLHEAD
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]>
<xml>
<x:ExcelWorkbook>
<x:ExcelWorksheets>
<x:ExcelWorksheet>
<x:Name>Salary Advances</x:Name>
<x:WorksheetOptions>
<x:DisplayGridlines/>
</x:WorksheetOptions>
</x:ExcelWorksheet>
</x:ExcelWorksheets>
</x:ExcelWorkbook>
</xml>
<![endif]-->
<style>
table{border-collapse:collapse;font-family:Calibri,Arial,sans-serif;}
td,th{border:1px solid #d0d0d0;padding:5px 9px;font-size:11pt;white-space:nowrap;}
th{background:#18181b;color:#ffffff;font-weight:bold;text-align:center;}
.title{font-size:16pt;font-weight:bold;border:none;}
.subtitle{font-size:10pt;color:#555555;border:none;}
.blank{border:none;}
.num{mso-number-format:"0.00";text-align:right;}
.center{text-align:center;}
.summary-lbl{font-weight:bold;background:#f3f4f6;}
.pending{background:#fef3c7;color:#92400e;font-weight:bold;}
.approved{background:#dcfce7;color:#166534;font-weight:bold;}
.rejected{background:#fee2e2;color:#991b1b;font-weight:bold;}
</style>
</head>
<body>
<table>
HTMLHEAD;

    echo '<tr><td class="title blank" colspan="13">Salary Advance Requests</td></tr>' . "\n";
    echo '<tr><td class="subtitle blank" colspan="13">' . htmlspecialchars($subtitle) . ' &nbsp;|&nbsp; Exported: ' . date('d M Y H:i') . '</td></tr>' . "\n";
    foreach ($meta_lines as $line) {
        echo '<tr><td class="subtitle blank" colspan="13">' . htmlspecialchars($line) . '</td></tr>' . "\n";
    }
    echo '<tr><td class="blank" colspan="13">&nbsp;</td></tr>' . "\n";

    $headers = ['#','Employee Name','Employee ID','Request Date','Payroll Period','Amount (LKR)','Basic Salary (LKR)','% of Basic','Status','Approved By','Approved At','Reason','Rejection Note'];
    echo '<tr>';
    foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
    echo '</tr>' . "\n";

    foreach ($rows as $i => $a) {
        $basic       = (float)($a['basic_salary'] ?? 0);
        $amount      = (float)($a['amount'] ?? 0);
        $pct         = $basic > 0 ? round($amount / $basic * 100, 1) : 0;
        $period_str  = !empty($a['pay_year']) ? $month_names[$a['pay_month']] . ' ' . $a['pay_year'] : '—';
        $approved_at = !empty($a['approved_at']) ? date('d M Y H:i', strtotime($a['approved_at'])) : '';
        $status_cls  = $a['status'] === 'Pending' ? 'pending' : ($a['status'] === 'Approved' ? 'approved' : 'rejected');
        $reason      = str_replace(["\t","\r","\n"], ' ', $a['reason'] ?? '');
        $rej_note    = str_replace(["\t","\r","\n"], ' ', $a['rejection_note'] ?? '');

        echo '<tr>';
        echo '<td class="center">' . ($i + 1) . '</td>';
        echo '<td>' . htmlspecialchars($a['emp_name'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($a['employee_id'] ?? '') . '</td>';
        echo '<td>' . date('d M Y', strtotime($a['request_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($period_str) . '</td>';
        echo '<td class="num">' . number_format($amount, 2, '.', '') . '</td>';
        echo '<td class="num">' . number_format($basic, 2, '.', '') . '</td>';
        echo '<td class="center">' . number_format($pct, 1) . '%</td>';
        echo '<td class="center ' . $status_cls . '">' . htmlspecialchars($a['status']) . '</td>';
        echo '<td>' . htmlspecialchars($a['approved_by'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($approved_at) . '</td>';
        echo '<td>' . htmlspecialchars($reason) . '</td>';
        echo '<td>' . htmlspecialchars($rej_note) . '</td>';
        echo '</tr>' . "\n";
    }

    echo '<tr><td class="blank" colspan="13">&nbsp;</td></tr>' . "\n";
    echo '<tr><td class="summary-lbl" colspan="2">Summary</td></tr>' . "\n";
    echo '<tr><td class="summary-lbl">Total Records</td><td class="num">' . count($rows) . '</td></tr>' . "\n";
    echo '<tr><td class="summary-lbl">Pending</td><td class="num">' . $pending_count . '</td></tr>' . "\n";
    echo '<tr><td class="summary-lbl">Approved</td><td class="num">' . $approved_count . '</td></tr>' . "\n";
    echo '<tr><td class="summary-lbl">Rejected</td><td class="num">' . $rejected_count . '</td></tr>' . "\n";
    echo '<tr><td class="summary-lbl">Total Amount — All Rows (LKR)</td><td class="num">' . number_format($total_amount, 2, '.', '') . '</td></tr>' . "\n";
    echo '<tr><td class="summary-lbl">Total Approved Amount (LKR)</td><td class="num">' . number_format($total_approved, 2, '.', '') . '</td></tr>' . "\n";

    echo '</table></body></html>';
}

// ── EXPORT SELECTED (POST, from the bulk action bar) ───────────────────────
$bulk_notice = ''; $bulk_notice_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_selected') {
    $ids = isset($_POST['adv_ids']) && is_array($_POST['adv_ids'])
        ? array_values(array_filter(array_map('intval', $_POST['adv_ids'])))
        : [];
    if (!empty($ids)) {
        $id_list = implode(',', $ids);
        $sel_res = mysqli_query($conn, "
            SELECT sa.*, e.employee_full_name as emp_name, e.employee_id, e.basic_salary,
                   pp.year as pay_year, pp.month as pay_month, pp.status as period_status
            FROM salary_advances sa
            JOIN employees e ON sa.employee_id = e.id
            LEFT JOIN payroll_periods pp ON sa.payroll_period_id = pp.id
            WHERE sa.id IN ($id_list)
            ORDER BY sa.created_at DESC
        ");
        $sel_rows = [];
        if ($sel_res) while ($r = mysqli_fetch_assoc($sel_res)) $sel_rows[] = $r;
        export_salary_advances_excel($sel_rows, $month_names, count($sel_rows) . ' Selected Record(s)');
        exit;
    }
    $bulk_notice = "No requests were selected — nothing to export.";
    $bulk_notice_type = 'warning';
}

// ── FILTERS ────────────────────────────────────────────────────────────────
$filter_status    = isset($_GET['status'])            ? $_GET['status']                    : '';
$filter_emp       = isset($_GET['employee_id'])       ? intval($_GET['employee_id'])        : 0;
$filter_period_id = isset($_GET['payroll_period_id']) ? intval($_GET['payroll_period_id'])  : 0;
$search           = isset($_GET['search'])            ? mysqli_real_escape_string($conn, $_GET['search']) : '';

$where = "WHERE 1=1";
if ($filter_status)    $where .= " AND sa.status='".mysqli_real_escape_string($conn, $filter_status)."'";
if ($filter_emp)       $where .= " AND sa.employee_id=$filter_emp";
if ($filter_period_id) $where .= " AND sa.payroll_period_id=$filter_period_id";
if ($search)           $where .= " AND (e.employee_full_name LIKE '%$search%' OR e.employee_id LIKE '%$search%')";

// ── HANDLE MUTATIONS (approve / reject / delete / update amount / bulk change period) ──────
// Run these BEFORE the list query below so the table reflects fresh data
// immediately, without needing a second page load.
$msg = $bulk_notice; $msg_type = $bulk_notice_type;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'approve') {
        $aid     = intval($_POST['advance_id']);
        $by      = mysqli_real_escape_string($conn, $session_user);
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
        mysqli_query($conn, "UPDATE salary_advances SET status='Approved', approved_by='$by', approved_at=NOW(), remarks='$remarks' WHERE id=$aid AND status='Pending'");
        $msg = "Salary advance approved successfully."; $msg_type = 'success';
    }

    if ($_POST['action'] === 'reject') {
        $aid     = intval($_POST['advance_id']);
        $note    = mysqli_real_escape_string($conn, $_POST['rejection_note'] ?? '');
        $by      = mysqli_real_escape_string($conn, $session_user);
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
        mysqli_query($conn, "UPDATE salary_advances SET status='Rejected', approved_by='$by', approved_at=NOW(), rejection_note='$note', remarks='$remarks' WHERE id=$aid AND status='Pending'");
        $msg = "Salary advance rejected."; $msg_type = 'danger';
    }

    if ($_POST['action'] === 'delete') {
        $aid = intval($_POST['advance_id']);
        mysqli_query($conn, "DELETE FROM salary_advances WHERE id=$aid AND status='Pending'");
        $msg = "Request deleted."; $msg_type = 'warning';
    }

    // ── INLINE AMOUNT UPDATE now handled via the ajax_update_amount endpoint above (no page reload) ──

    // ── BULK APPROVE (select rows via checkboxes / "select all", approve them all in one click) ──
    if ($_POST['action'] === 'bulk_approve') {
        $ids = isset($_POST['adv_ids']) && is_array($_POST['adv_ids'])
            ? array_values(array_filter(array_map('intval', $_POST['adv_ids'])))
            : [];
        if (empty($ids)) {
            $msg = "No requests were selected."; $msg_type = 'warning';
        } else {
            $id_list = implode(',', $ids);
            $by = mysqli_real_escape_string($conn, $session_user);
            mysqli_query($conn, "UPDATE salary_advances SET status='Approved', approved_by='$by', approved_at=NOW() WHERE id IN ($id_list) AND status='Pending'");
            $affected = mysqli_affected_rows($conn);
            if ($affected > 0) {
                $skipped = count($ids) - $affected;
                $msg = $affected . " request(s) approved successfully." . ($skipped > 0 ? " ($skipped already non-Pending were skipped.)" : "");
                $msg_type = 'success';
            } else {
                $msg = "No Pending requests among the selection could be approved (they may already be Approved/Rejected)."; $msg_type = 'warning';
            }
        }
    }

    if ($_POST['action'] === 'bulk_change_period') {
        $ids = isset($_POST['adv_ids']) && is_array($_POST['adv_ids'])
            ? array_values(array_filter(array_map('intval', $_POST['adv_ids'])))
            : [];
        $period_raw = $_POST['bulk_payroll_period_id'] ?? '__none__';

        if (empty($ids)) {
            $msg = "No requests were selected."; $msg_type = 'warning';
        } elseif ($period_raw === '__none__' || $period_raw === '') {
            $msg = "Please choose a payroll period option first."; $msg_type = 'warning';
        } else {
            $id_list = implode(',', $ids);
            if ($period_raw === 'clear') {
                mysqli_query($conn, "UPDATE salary_advances SET payroll_period_id=NULL WHERE id IN ($id_list)");
                $msg = count($ids) . " request(s) cleared of payroll period."; $msg_type = 'success';
            } else {
                $new_period = intval($period_raw);
                if ($new_period > 0) {
                    mysqli_query($conn, "UPDATE salary_advances SET payroll_period_id=$new_period WHERE id IN ($id_list)");
                    $msg = count($ids) . " request(s) moved to the selected payroll period."; $msg_type = 'success';
                } else {
                    $msg = "Invalid payroll period selected."; $msg_type = 'danger';
                }
            }
        }
    }
}

// ── FETCH LIST (after mutations, so display is always up to date) ──────────
$advances = [];
$res = mysqli_query($conn, "
    SELECT sa.*,
           e.employee_full_name, e.employee_id, e.basic_salary,
           e.employee_full_name as emp_name,
           pp.year as pay_year, pp.month as pay_month, pp.status as period_status
    FROM salary_advances sa
    JOIN employees e ON sa.employee_id = e.id
    LEFT JOIN payroll_periods pp ON sa.payroll_period_id = pp.id
    $where
    ORDER BY sa.created_at DESC
");
if ($res) while ($r = mysqli_fetch_assoc($res)) $advances[] = $r;

// ── EXPORT (GET, full filtered list) ────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $period_label = '';
    if ($filter_period_id) {
        foreach ($payroll_periods_list as $pp) {
            if ($pp['id'] == $filter_period_id) {
                $period_label = $month_names[$pp['month']] . ' ' . $pp['year'];
                break;
            }
        }
    }
    $meta = [];
    if ($filter_status || $search || $filter_period_id) {
        $active = [];
        if ($filter_status)    $active[] = 'Status: ' . $filter_status;
        if ($search)           $active[] = 'Search: ' . $search;
        if ($filter_period_id) $active[] = 'Period: ' . $period_label;
        $meta[] = 'Filters: ' . implode(' | ', $active);
    }
    export_salary_advances_excel($advances, $month_names, 'Full Filtered List', $meta);
    exit;
}

// Stats
$stats = ['Pending'=>0,'Approved'=>0,'Rejected'=>0,'total_amount'=>0];
foreach ($advances as $a) {
    $stats[$a['status']]++;
    if ($a['status']==='Approved') $stats['total_amount'] += $a['amount'];
}

include 'header.php';

// Build export URL preserving current filters (full filtered list export)
$export_params = array_filter([
    'export'           => 'excel',
    'status'           => $filter_status,
    'search'           => $_GET['search'] ?? '',
    'payroll_period_id'=> $filter_period_id ?: '',
    'employee_id'      => $filter_emp ?: '',
]);
$export_url = 'salary_advance.php?' . http_build_query($export_params);
?>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;margin:0 0 4px;color:#111;display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-hand-holding-dollar" style="color:#3b82f6;"></i> Salary Advance Requests
        </h2>
        <p style="font-size:13px;color:#666;margin:0;">Manage employee salary advance requests and approvals</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="<?php echo htmlspecialchars($export_url); ?>" class="sa-btn sa-btn-excel" title="Export current filtered view to Excel">
            <i class="fa-solid fa-file-excel"></i> Export Excel
        </a>
        <a href="add_salary_advance.php" class="sa-btn sa-btn-p">
            <i class="fa-solid fa-plus"></i> New Request
        </a>
        <a href="bulk_salary_advance.php" class="sa-btn sa-btn-p">
            <i class="fa-solid fa-layer-group"></i> Bulk Request
        </a>
    </div>
</div>

<?php if ($msg): ?>
<div class="sa-alert sa-alert-<?php echo $msg_type; ?>" style="margin-bottom:14px;">
    <i class="fa-solid fa-<?php echo $msg_type==='success'?'circle-check':($msg_type==='danger'?'circle-xmark':'triangle-exclamation'); ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<!-- HINT: how the inline amount edit works -->
<div style="display:flex;align-items:center;gap:8px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;font-size:12px;font-weight:600;padding:8px 14px;border-radius:8px;margin-bottom:14px;">
    <i class="fa-solid fa-circle-info"></i>
    Tip: Double-click <strong>any</strong> row's amount to edit it inline, then click <i class="fa-solid fa-check"></i> to update — this only changes the amount, status is untouched. Or tick the header checkbox to select all (or pick individual rows) and hit <strong>Approve Selected</strong> to approve them all at once.
</div>

<!-- STATS CARDS -->
<div class="sa-stats-row">
    <div class="sa-stat-card sa-stat-blue">
        <div class="sa-stat-icon"><i class="fa-solid fa-clock"></i></div>
        <div><div class="sa-stat-val"><?php echo $stats['Pending']; ?></div><div class="sa-stat-lbl">Pending</div></div>
    </div>
    <div class="sa-stat-card sa-stat-green">
        <div class="sa-stat-icon"><i class="fa-solid fa-circle-check"></i></div>
        <div><div class="sa-stat-val"><?php echo $stats['Approved']; ?></div><div class="sa-stat-lbl">Approved</div></div>
    </div>
    <div class="sa-stat-card sa-stat-red">
        <div class="sa-stat-icon"><i class="fa-solid fa-circle-xmark"></i></div>
        <div><div class="sa-stat-val"><?php echo $stats['Rejected']; ?></div><div class="sa-stat-lbl">Rejected</div></div>
    </div>
    <div class="sa-stat-card sa-stat-purple">
        <div class="sa-stat-icon"><i class="fa-solid fa-coins"></i></div>
        <div><div class="sa-stat-val">LKR <?php echo number_format($stats['total_amount'],2); ?></div><div class="sa-stat-lbl">Total Approved</div></div>
    </div>
</div>

<!-- FILTERS -->
<div class="sa-filter-bar">
    <form method="GET" style="display:contents;">

        <!-- Search -->
        <input type="text" name="search" placeholder="Search employee..." class="sa-search"
               value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">

        <!-- Status -->
        <select name="status" class="sa-select">
            <option value="">All Status</option>
            <?php foreach(['Pending','Approved','Rejected'] as $s): ?>
            <option value="<?php echo $s; ?>" <?php echo $filter_status===$s?'selected':''; ?>><?php echo $s; ?></option>
            <?php endforeach; ?>
        </select>

        <!-- Payroll Period (from payroll_periods table) -->
        <select name="payroll_period_id" class="sa-select">
            <option value="">All Payroll Periods</option>
            <?php foreach($payroll_periods_list as $pp): ?>
            <option value="<?php echo $pp['id']; ?>"
                <?php echo $filter_period_id == $pp['id'] ? 'selected' : ''; ?>>
                <?php echo $month_names[$pp['month']].' '.$pp['year'];
                      echo $pp['status'] === 'Locked' ? ' 🔒' : ''; ?>
            </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="sa-btn sa-btn-g">
            <i class="fa-solid fa-magnifying-glass"></i> Filter
        </button>

        <?php if ($filter_status || $search || $filter_emp || $filter_period_id): ?>
        <a href="salary_advance.php" class="sa-btn sa-btn-g">
            <i class="fa-solid fa-xmark"></i> Clear
        </a>
        <?php endif; ?>

    </form>
</div>

<!-- ACTIVE FILTER TAGS -->
<?php
$selected_period_label = '';
if ($filter_period_id) {
    foreach($payroll_periods_list as $pp) {
        if ($pp['id'] == $filter_period_id) {
            $selected_period_label = $month_names[$pp['month']].' '.$pp['year'];
            break;
        }
    }
}
?>
<?php if ($filter_status || $search || $filter_period_id): ?>
<div class="sa-active-filters">
    <span style="font-size:11px;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:.4px;">Active Filters:</span>
    <?php if ($filter_status): ?>
        <span class="sa-filter-tag">Status: <?php echo htmlspecialchars($filter_status); ?> <a href="?<?php echo http_build_query(array_merge($_GET,['status'=>''])); ?>">&times;</a></span>
    <?php endif; ?>
    <?php if ($search): ?>
        <span class="sa-filter-tag">Search: <?php echo htmlspecialchars($search); ?> <a href="?<?php echo http_build_query(array_merge($_GET,['search'=>''])); ?>">&times;</a></span>
    <?php endif; ?>
    <?php if ($filter_period_id): ?>
        <span class="sa-filter-tag">Period: <?php echo htmlspecialchars($selected_period_label); ?> <a href="?<?php echo http_build_query(array_merge($_GET,['payroll_period_id'=>''])); ?>">&times;</a></span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- BULK FORM (wraps the bulk-action bar + table so checkboxes post together) -->
<form method="POST" id="bulkForm">

    <!-- BULK ACTION BAR (shown once at least one row is checked) -->
    <div id="bulkBar" class="sa-bulkbar" style="display:none;">
        <div class="sa-bulkbar-count"><i class="fa-solid fa-square-check"></i> <span id="bulkCount">0</span> selected</div>
        <div class="sa-bulkbar-actions">
            <select name="bulk_payroll_period_id" class="sa-select" id="bulkPeriodSelect">
                <option value="__none__">— Change Payroll Period To —</option>
                <option value="clear">🚫 No Period (Clear)</option>
                <?php foreach($payroll_periods_list as $pp): ?>
                <option value="<?php echo $pp['id']; ?>">
                    <?php echo $month_names[$pp['month']].' '.$pp['year'];
                          echo $pp['status'] === 'Locked' ? ' 🔒' : ''; ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="action" value="bulk_change_period" class="sa-btn sa-btn-p" onclick="return confirmBulkPeriod();">
                <i class="fa-solid fa-calendar-days"></i> Apply Period
            </button>
            <button type="submit" name="action" value="bulk_approve" class="sa-btn sa-btn-success" onclick="return confirmBulkApprove();">
                <i class="fa-solid fa-check-double"></i> Approve Selected
            </button>
            <button type="submit" name="action" value="export_selected" class="sa-btn sa-btn-excel">
                <i class="fa-solid fa-file-excel"></i> Export Selected
            </button>
            <button type="button" class="sa-btn sa-btn-g" onclick="clearSelection()">
                <i class="fa-solid fa-xmark"></i> Clear Selection
            </button>
        </div>
    </div>

    <!-- TABLE -->
    <div class="sa-card">
        <div class="sa-table-wrap">
            <table class="sa-table">
                <thead>
                    <tr>
                        <th style="width:36px;"><input type="checkbox" id="selectAllCb" onclick="toggleAll(this)"></th>
                        <th>#</th>
                        <th>Employee</th>
                        <th>Request Date</th>
                        <th>Payroll Period</th>
                        <th>Amount</th>
                        <th>% of Basic</th>
                        <th>Status</th>
                        <th>Approved By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($advances)): ?>
                    <tr><td colspan="10" style="text-align:center;padding:40px;color:#9ca3af;">
                        <i class="fa-solid fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>
                        No salary advance requests found.
                    </td></tr>
                    <?php else: foreach ($advances as $i => $a):
                        $pct = $a['basic_salary'] > 0 ? ($a['amount'] / $a['basic_salary'] * 100) : 0;
                        $pct_class = $pct >= 75 ? 'sa-pct-danger' : 'sa-pct-ok';
                    ?>
                    <tr>
                        <td><input type="checkbox" class="row-cb" name="adv_ids[]" value="<?php echo $a['id']; ?>" onchange="updateBulkBar()"></td>
                        <td style="color:#9ca3af;font-size:12px;"><?php echo $i+1; ?></td>
                        <td>
                            <div style="font-weight:600;color:#111;"><?php echo htmlspecialchars($a['emp_name']); ?></div>
                            <div style="font-size:11px;color:#9ca3af;"><?php echo htmlspecialchars($a['employee_id']); ?></div>
                        </td>
                        <td><?php echo date('d M Y', strtotime($a['request_date'])); ?></td>
                        <td>
                            <?php if ($a['pay_year']): ?>
                                <span class="sa-period-badge <?php echo $a['period_status']==='Locked'?'sa-period-locked':'sa-period-open'; ?>">
                                    <?php echo $month_names[$a['pay_month']].' '.$a['pay_year']; ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#ccc;font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:700;color:#111;">
                            <div class="sa-amt-wrap" data-basic="<?php echo (float)$a['basic_salary']; ?>">
                                <span class="sa-amt-display"
                                      id="amt_display_<?php echo $a['id']; ?>"
                                      data-amount="<?php echo (float)$a['amount']; ?>"
                                      ondblclick="enableAmountEdit(<?php echo $a['id']; ?>)"
                                      title="Double-click to edit amount">
                                    LKR <span id="amt_text_<?php echo $a['id']; ?>"><?php echo number_format($a['amount'],2); ?></span>
                                    <i class="fa-solid fa-pen sa-amt-pencil"></i>
                                </span>
                                <div class="sa-amt-edit" id="amt_edit_<?php echo $a['id']; ?>" style="display:none;">
                                    <div style="display:flex;align-items:center;gap:6px;">
                                        <input type="number" step="0.01" min="0.01"
                                               class="sa-amt-input"
                                               id="amt_input_<?php echo $a['id']; ?>"
                                               onkeydown="if(event.key==='Enter'){event.preventDefault();saveAmount(<?php echo $a['id']; ?>);} if(event.key==='Escape'){event.preventDefault();cancelAmountEdit(<?php echo $a['id']; ?>);}">
                                        <div class="sa-amt-edit-actions">
                                            <button type="button" class="sa-amt-btn sa-amt-save" title="Update Amount" onclick="saveAmount(<?php echo $a['id']; ?>)"><i class="fa-solid fa-check"></i></button>
                                            <button type="button" class="sa-amt-btn sa-amt-cancel" title="Cancel" onclick="cancelAmountEdit(<?php echo $a['id']; ?>)"><i class="fa-solid fa-xmark"></i></button>
                                        </div>
                                    </div>
                                    <div class="sa-amt-status" id="amt_status_<?php echo $a['id']; ?>"></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="sa-pct-badge <?php echo $pct_class; ?>" id="pct_badge_<?php echo $a['id']; ?>">
                                <i class="fa-solid fa-triangle-exclamation" id="pct_warn_<?php echo $a['id']; ?>" style="<?php echo $pct >= 75 ? '' : 'display:none;'; ?>"></i>
                                <span id="pct_text_<?php echo $a['id']; ?>"><?php echo number_format($pct,1); ?>%</span>
                            </span>
                            <div style="font-size:10px;color:#9ca3af;margin-top:2px;">Basic: LKR <?php echo number_format($a['basic_salary'],0); ?></div>
                        </td>
                        <td>
                            <?php
                            $sbadge = ['Pending'=>'sa-badge-pending','Approved'=>'sa-badge-approved','Rejected'=>'sa-badge-rejected'];
                            $sicon  = ['Pending'=>'clock','Approved'=>'circle-check','Rejected'=>'circle-xmark'];
                            ?>
                            <span class="sa-badge <?php echo $sbadge[$a['status']]; ?>">
                                <i class="fa-solid fa-<?php echo $sicon[$a['status']]; ?>"></i>
                                <?php echo $a['status']; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($a['approved_by']): ?>
                                <div style="font-size:12px;font-weight:600;"><?php echo htmlspecialchars($a['approved_by']); ?></div>
                                <div style="font-size:11px;color:#9ca3af;"><?php echo $a['approved_at'] ? date('d M Y', strtotime($a['approved_at'])) : ''; ?></div>
                            <?php else: echo '<span style="color:#ccc;font-size:12px;">—</span>'; endif; ?>
                        </td>
                        <td>
                            <div class="sa-actions">
                                <a href="edit_salary_advance.php?id=<?php echo $a['id']; ?>" class="sa-icon-btn sa-icon-edit" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <?php if ($a['status']==='Pending'): ?>
                                <button type="button" onclick="openApproveModal(<?php echo $a['id']; ?>,<?php echo htmlspecialchars(json_encode($a['emp_name'])); ?>,getRowAmount(<?php echo $a['id']; ?>))" class="sa-icon-btn sa-icon-approve" title="Approve"><i class="fa-solid fa-check"></i></button>
                                <button type="button" onclick="openRejectModal(<?php echo $a['id']; ?>,<?php echo htmlspecialchars(json_encode($a['emp_name'])); ?>)" class="sa-icon-btn sa-icon-reject" title="Reject"><i class="fa-solid fa-xmark"></i></button>
                                <button type="button" onclick="deleteRequest(<?php echo $a['id']; ?>,'<?php echo addslashes($a['emp_name']); ?>')" class="sa-icon-btn sa-icon-delete" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>

<!-- APPROVE MODAL -->
<div id="approveModal" class="sa-modal-bg" style="display:none;" onclick="if(event.target===this)this.style.display='none'">
    <div class="sa-modal">
        <div class="sa-modal-h">
            <i class="fa-solid fa-circle-check" style="color:#22c55e;font-size:18px;"></i>
            <h3>Approve Salary Advance</h3>
            <button onclick="document.getElementById('approveModal').style.display='none'" class="sa-modal-close">&times;</button>
        </div>
        <form method="POST" id="approveForm">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="advance_id" id="approve_id">
            <div class="sa-modal-b">
                <p style="margin:0 0 12px;font-size:14px;">Approving advance for <strong id="approve_emp_name"></strong></p>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px;margin-bottom:16px;">
                    <div style="font-size:13px;color:#166534;">Amount: <strong id="approve_amount"></strong></div>
                    <div style="font-size:12px;color:#6b7280;margin-top:6px;">Approving as: <strong><?php echo htmlspecialchars($session_user); ?></strong></div>
                </div>
                <div class="sa-form-group">
                    <label class="sa-form-label">Remarks <span style="font-size:11px;color:#9ca3af;">(Optional)</span></label>
                    <textarea name="remarks" class="sa-form-input" rows="3" placeholder="Add any remarks or notes..."></textarea>
                </div>
            </div>
            <div class="sa-modal-f">
                <button type="submit" class="sa-btn sa-btn-success"><i class="fa-solid fa-check"></i> Confirm Approval</button>
                <button type="button" onclick="document.getElementById('approveModal').style.display='none'" class="sa-btn sa-btn-g">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- REJECT MODAL -->
<div id="rejectModal" class="sa-modal-bg" style="display:none;" onclick="if(event.target===this)this.style.display='none'">
    <div class="sa-modal">
        <div class="sa-modal-h">
            <i class="fa-solid fa-circle-xmark" style="color:#ef4444;font-size:18px;"></i>
            <h3>Reject Salary Advance</h3>
            <button onclick="document.getElementById('rejectModal').style.display='none'" class="sa-modal-close">&times;</button>
        </div>
        <form method="POST" id="rejectForm">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="advance_id" id="reject_id">
            <div class="sa-modal-b">
                <p style="margin:0 0 16px;font-size:14px;">Rejecting advance for <strong id="reject_emp_name"></strong></p>
                <div style="background:#fff5f5;border:1px solid #fecaca;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:12px;color:#991b1b;">
                    Rejecting as: <strong><?php echo htmlspecialchars($session_user); ?></strong>
                </div>
                <div class="sa-form-group">
                    <label class="sa-form-label">Rejection Note <span style="color:#ef4444;">*</span></label>
                    <textarea name="rejection_note" class="sa-form-input" rows="2" placeholder="Reason for rejection..." required></textarea>
                </div>
                <div class="sa-form-group">
                    <label class="sa-form-label">Remarks <span style="font-size:11px;color:#9ca3af;">(Optional)</span></label>
                    <textarea name="remarks" class="sa-form-input" rows="2" placeholder="Additional remarks..."></textarea>
                </div>
            </div>
            <div class="sa-modal-f">
                <button type="submit" class="sa-btn sa-btn-danger"><i class="fa-solid fa-xmark"></i> Confirm Rejection</button>
                <button type="button" onclick="document.getElementById('rejectModal').style.display='none'" class="sa-btn sa-btn-g">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE FORM (hidden) -->
<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="advance_id" id="delete_id">
</form>


<style>
*{box-sizing:border-box;}
.sa-stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px;}
.sa-stat-card{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 20px;box-shadow:0 2px 8px rgba(0,0,0,.04);}
.sa-stat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.sa-stat-blue .sa-stat-icon{background:#dbeafe;color:#2563eb;}
.sa-stat-green .sa-stat-icon{background:#dcfce7;color:#16a34a;}
.sa-stat-red .sa-stat-icon{background:#fee2e2;color:#dc2626;}
.sa-stat-purple .sa-stat-icon{background:#ede9fe;color:#7c3aed;}
.sa-stat-val{font-size:18px;font-weight:800;color:#111;line-height:1.2;}
.sa-stat-lbl{font-size:11px;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:.4px;}

.sa-filter-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;align-items:center;}
.sa-search{padding:9px 14px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;width:200px;}
.sa-search:focus{outline:none;border-color:#3b82f6;}
.sa-select{padding:9px 14px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;background:#fff;cursor:pointer;}
.sa-select:focus{outline:none;border-color:#3b82f6;}

.sa-active-filters{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px;}
.sa-filter-tag{display:inline-flex;align-items:center;gap:5px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;}
.sa-filter-tag a{color:#1d4ed8;text-decoration:none;font-size:13px;line-height:1;margin-left:2px;}
.sa-filter-tag a:hover{color:#991b1b;}

.sa-bulkbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;background:#111;color:#fff;border-radius:12px;padding:12px 18px;margin-bottom:12px;box-shadow:0 4px 14px rgba(0,0,0,.15);position:sticky;top:8px;z-index:50;}
.sa-bulkbar-count{font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;}
.sa-bulkbar-count i{color:#3b82f6;}
.sa-bulkbar-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.sa-bulkbar-actions .sa-select{background:#1f1f22;color:#fff;border-color:#3a3a3f;}
.sa-bulkbar-actions .sa-btn-g{background:#2a2a2e;color:#fff;border-color:#3a3a3f;}
.sa-bulkbar-actions .sa-btn-g:hover{background:#3a3a3f;}

.sa-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.05);margin-bottom:16px;}
.sa-table-wrap{overflow-x:auto;}
.sa-table{width:100%;border-collapse:collapse;font-size:13px;}
.sa-table thead tr{background:#18181b;}
.sa-table thead th{padding:11px 14px;color:#fff;font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;border-right:1px solid #2a2a2e;white-space:nowrap;}
.sa-table thead th:last-child{border-right:none;}
.sa-table tbody tr{border-bottom:1px solid #f0f0f0;}
.sa-table tbody tr:hover{background:#f8faff;}
.sa-table td{padding:12px 14px;vertical-align:middle;}

.sa-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.sa-badge-pending{background:#fef3c7;color:#92400e;}
.sa-badge-approved{background:#dcfce7;color:#166534;}
.sa-badge-rejected{background:#fee2e2;color:#991b1b;}

.sa-pct-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;}
.sa-pct-ok{background:#dcfce7;color:#166534;}
.sa-pct-danger{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}

.sa-period-badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.sa-period-open{background:#dcfce7;color:#166534;}
.sa-period-locked{background:#f3f4f6;color:#6b7280;}

/* Inline double-click amount editor */
.sa-amt-wrap{position:relative;display:inline-block;min-width:110px;}
.sa-amt-display{cursor:pointer;display:inline-flex;align-items:center;gap:6px;padding:3px 6px;border-radius:6px;border:1px dashed transparent;}
.sa-amt-display:hover{background:#eff6ff;border-color:#bfdbfe;}
.sa-amt-pencil{font-size:10px;color:#9ca3af;opacity:0;transition:opacity .15s;}
.sa-amt-display:hover .sa-amt-pencil{opacity:1;}
.sa-amt-edit{display:none;}
.sa-amt-input{width:110px;padding:6px 8px;border:1.5px solid #3b82f6;border-radius:6px;font-size:13px;font-weight:700;font-family:inherit;color:#111;}
.sa-amt-input:focus{outline:none;box-shadow:0 0 0 3px rgba(59,130,246,.15);}
.sa-amt-edit-actions{display:flex;gap:4px;}
.sa-amt-btn{width:26px;height:26px;border-radius:6px;border:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:11px;color:#fff;flex-shrink:0;}
.sa-amt-btn:disabled{opacity:.6;cursor:not-allowed;}
.sa-amt-save{background:#22c55e;} .sa-amt-save:hover{background:#16a34a;}
.sa-amt-cancel{background:#9ca3af;} .sa-amt-cancel:hover{background:#6b7280;}
.sa-amt-status{font-size:11px;font-weight:600;margin-top:4px;min-height:14px;color:#2563eb;}
.sa-amt-status-err{color:#dc2626;}

.sa-actions{display:flex;gap:5px;}
.sa-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;cursor:pointer;font-size:12px;color:#6b7280;transition:all .15s;text-decoration:none;}
.sa-icon-edit:hover{background:#3b82f6;color:#fff;border-color:#3b82f6;}
.sa-icon-approve:hover{background:#22c55e;color:#fff;border-color:#22c55e;}
.sa-icon-reject:hover{background:#f59e0b;color:#fff;border-color:#f59e0b;}
.sa-icon-delete:hover{background:#ef4444;color:#fff;border-color:#ef4444;}

.sa-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap;}
.sa-btn-p{background:#111;color:#fff;} .sa-btn-p:hover{background:#333;}
.sa-btn-g{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;} .sa-btn-g:hover{background:#eee;}
.sa-btn-success{background:#22c55e;color:#fff;} .sa-btn-success:hover{background:#16a34a;}
.sa-btn-danger{background:#ef4444;color:#fff;} .sa-btn-danger:hover{background:#dc2626;}
.sa-btn-excel{background:#166534;color:#fff;} .sa-btn-excel:hover{background:#15803d;}

.sa-alert{display:flex;align-items:center;gap:9px;padding:11px 16px;border-radius:8px;font-size:13px;}
.sa-alert-success{background:#dcfce7;border:1px solid #bbf7d0;color:#166534;}
.sa-alert-danger{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.sa-alert-warning{background:#fef3c7;border:1px solid #fde68a;color:#92400e;}

.sa-modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;}
.sa-modal{background:#fff;border-radius:14px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.2);overflow:hidden;}
.sa-modal-h{display:flex;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid #f0f0f0;background:#fafafa;}
.sa-modal-h h3{font-size:14px;font-weight:700;margin:0;flex:1;color:#111;}
.sa-modal-close{background:none;border:none;font-size:22px;color:#aaa;cursor:pointer;} .sa-modal-close:hover{color:#111;}
.sa-modal-b{padding:20px;}
.sa-modal-f{display:flex;gap:8px;padding:14px 20px;border-top:1px solid #f0f0f0;background:#fafafa;}

.sa-form-group{margin-bottom:14px;}
.sa-form-label{display:block;font-size:12px;font-weight:600;color:#555;margin-bottom:6px;text-transform:uppercase;letter-spacing:.3px;}
.sa-form-input{width:100%;padding:10px 14px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;color:#111;}
.sa-form-input:focus{outline:none;border-color:#3b82f6;}

@media(max-width:900px){.sa-stats-row{grid-template-columns:1fr 1fr;}}
@media(max-width:540px){.sa-stats-row{grid-template-columns:1fr;}.sa-search{width:100%;}.sa-filter-bar{flex-direction:column;align-items:stretch;}.sa-select{width:100%;}.sa-bulkbar{flex-direction:column;align-items:stretch;}.sa-bulkbar-actions{flex-direction:column;align-items:stretch;}}
</style>

<script>
function openApproveModal(id, name, amount) {
    document.getElementById('approve_id').value = id;
    document.getElementById('approve_emp_name').textContent = name;
    document.getElementById('approve_amount').textContent = 'LKR ' + parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits:2});
    document.getElementById('approveModal').style.display = 'flex';
}
function openRejectModal(id, name) {
    document.getElementById('reject_id').value = id;
    document.getElementById('reject_emp_name').textContent = name;
    document.getElementById('rejectModal').style.display = 'flex';
}
function deleteRequest(id, name) {
    if (confirm('Delete salary advance request for ' + name + '? This cannot be undone.')) {
        document.getElementById('delete_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}

// ── BULK SELECT ──────────────────────────────────────────────────────────
function toggleAll(cb) {
    document.querySelectorAll('.row-cb').forEach(el => el.checked = cb.checked);
    updateBulkBar();
}
function updateBulkBar() {
    const checked = document.querySelectorAll('.row-cb:checked');
    const all     = document.querySelectorAll('.row-cb');
    const bar     = document.getElementById('bulkBar');
    document.getElementById('bulkCount').textContent = checked.length;
    bar.style.display = checked.length > 0 ? 'flex' : 'none';
    document.getElementById('selectAllCb').checked = all.length > 0 && checked.length === all.length;
}
function clearSelection() {
    document.querySelectorAll('.row-cb').forEach(el => el.checked = false);
    document.getElementById('selectAllCb').checked = false;
    updateBulkBar();
}
function confirmBulkApprove() {
    const checked = document.querySelectorAll('.row-cb:checked').length;
    if (checked === 0) { alert('Please select at least one request first.'); return false; }
    return confirm('Approve ' + checked + ' selected request(s)? Only requests still marked Pending will be approved — Approved/Rejected rows in the selection are skipped.');
}
function confirmBulkPeriod() {
    const sel = document.getElementById('bulkPeriodSelect');
    const checked = document.querySelectorAll('.row-cb:checked').length;
    if (checked === 0) { alert('Please select at least one request first.'); return false; }
    if (sel.value === '__none__') { alert('Please choose a payroll period option first.'); return false; }
    const label = sel.options[sel.selectedIndex].text.trim();
    return confirm('Change payroll period to "' + label + '" for ' + checked + ' selected request(s)?');
}

// ── INLINE DOUBLE-CLICK AMOUNT EDIT (AJAX — no page reload) ────────────────────────────────
// Double-click any row's amount -> shows an input + Update / Cancel. Saves via fetch() to the
// ajax_update_amount endpoint, so pressing Enter always works reliably (no risk of the click/keypress
// being swallowed by the surrounding bulk-select <form>) and the confirmed stored value is read back
// from the server. The Approve button reads this same live value via getRowAmount(), so an inline
// edit is always reflected in the Approve modal even before the page is refreshed.
function getRowAmount(id) {
    const display = document.getElementById('amt_display_' + id);
    return display ? parseFloat(display.dataset.amount) : 0;
}
function enableAmountEdit(id) {
    const display = document.getElementById('amt_display_' + id);
    const editBox = document.getElementById('amt_edit_' + id);
    const input   = document.getElementById('amt_input_' + id);
    const status  = document.getElementById('amt_status_' + id);
    if (!display || !editBox || !input) return;
    display.style.display = 'none';
    editBox.style.display = 'block';
    input.value = display.dataset.amount;
    if (status) status.textContent = '';
    input.focus();
    input.select();
}
function cancelAmountEdit(id) {
    const display = document.getElementById('amt_display_' + id);
    const editBox = document.getElementById('amt_edit_' + id);
    const status  = document.getElementById('amt_status_' + id);
    if (!display || !editBox) return;
    editBox.style.display = 'none';
    display.style.display = 'inline-flex';
    if (status) status.textContent = '';
}
function saveAmount(id) {
    const input  = document.getElementById('amt_input_' + id);
    const status = document.getElementById('amt_status_' + id);
    const saveBtn = document.querySelector('#amt_edit_' + id + ' .sa-amt-save');
    const val = parseFloat(input.value);

    if (isNaN(val) || val <= 0) {
        if (status) { status.textContent = 'Enter a valid amount greater than 0.'; status.className = 'sa-amt-status sa-amt-status-err'; }
        input.focus();
        return;
    }

    if (saveBtn) saveBtn.disabled = true;
    if (status) { status.textContent = 'Saving…'; status.className = 'sa-amt-status'; }

    const fd = new FormData();
    fd.append('action', 'ajax_update_amount');
    fd.append('advance_id', id);
    fd.append('amount', val);

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (saveBtn) saveBtn.disabled = false;
        if (data && data.success) {
            const display = document.getElementById('amt_display_' + id);
            const text    = document.getElementById('amt_text_' + id);
            const newAmt  = parseFloat(data.amount);
            if (display) display.dataset.amount = newAmt;
            if (text) text.textContent = newAmt.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});

            // Refresh the "% of Basic" badge next to it using the row's basic salary.
            const wrap = display ? display.closest('.sa-amt-wrap') : null;
            const basic = wrap ? parseFloat(wrap.dataset.basic) : 0;
            const pctText = document.getElementById('pct_text_' + id);
            const pctWarn = document.getElementById('pct_warn_' + id);
            const pctBadge = document.getElementById('pct_badge_' + id);
            if (basic > 0 && pctText) {
                const pct = (newAmt / basic) * 100;
                pctText.textContent = pct.toFixed(1) + '%';
                if (pctWarn) pctWarn.style.display = pct >= 75 ? 'inline' : 'none';
                if (pctBadge) pctBadge.className = 'sa-pct-badge ' + (pct >= 75 ? 'sa-pct-danger' : 'sa-pct-ok');
            }

            cancelAmountEdit(id);
        } else {
            const msg = (data && data.message) ? data.message : 'Could not update the amount.';
            if (status) { status.textContent = msg; status.className = 'sa-amt-status sa-amt-status-err'; }
        }
    })
    .catch(() => {
        if (saveBtn) saveBtn.disabled = false;
        if (status) { status.textContent = 'Network error — please try again.'; status.className = 'sa-amt-status sa-amt-status-err'; }
    });
}
</script>

<?php include 'footer.php'; ?>