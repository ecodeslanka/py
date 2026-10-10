<?php
include 'config.php';

// Ensure table exists
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS leave_applications (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    employee_id     INT NOT NULL,
    leave_type      ENUM('Annual Leave','Casual Leave','Medical Leave','Public Holiday Leave') NOT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    days_count      INT NOT NULL DEFAULT 1,
    remark          TEXT DEFAULT NULL,
    reference_doc   VARCHAR(255) DEFAULT NULL,
    status          ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    applied_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
)");

// ── Bulk Approve ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_approve'])) {
    $ids = isset($_POST['selected_ids']) ? $_POST['selected_ids'] : [];
    if (!empty($ids)) {
        $safe_ids = implode(',', array_map('intval', $ids));
        mysqli_query($conn, "UPDATE leave_applications SET status='Approved' WHERE id IN ($safe_ids) AND status='Pending'");
    }
    header('Location: leave_list.php?msg=bulk_approved'); exit;
}

// ── Bulk Reject ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_reject'])) {
    $ids = isset($_POST['selected_ids']) ? $_POST['selected_ids'] : [];
    if (!empty($ids)) {
        $safe_ids = implode(',', array_map('intval', $ids));
        mysqli_query($conn, "UPDATE leave_applications SET status='Rejected' WHERE id IN ($safe_ids) AND status='Pending'");
    }
    header('Location: leave_list.php?msg=bulk_rejected'); exit;
}

// ── Delete ────────────────────────────────────────────────────────────────────
if (isset($_GET['delete_leave'])) {
    $lid = intval($_GET['delete_leave']);
    $d   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT reference_doc FROM leave_applications WHERE id=$lid"));
    if ($d && $d['reference_doc'] && file_exists($d['reference_doc'])) unlink($d['reference_doc']);
    mysqli_query($conn, "DELETE FROM leave_applications WHERE id=$lid");
    header('Location: leave_list.php?msg=deleted'); exit;
}

// ── Status update (single approve / reject) ───────────────────────────────────
if (isset($_GET['action']) && isset($_GET['leave_id'])) {
    $lid = intval($_GET['leave_id']);
    $st  = $_GET['action'] === 'approve' ? 'Approved' : 'Rejected';
    mysqli_query($conn, "UPDATE leave_applications SET status='$st' WHERE id=$lid");
    header('Location: leave_list.php?msg=updated'); exit;
}

// ── Load employees for filter dropdown ───────────────────────────────────────
$emp_result   = mysqli_query($conn,
    "SELECT e.id, e.employee_id, e.name_with_initials, e.employee_full_name
     FROM employees e
     WHERE e.status IN ('Probation','Permanent')
     ORDER BY e.employee_id ASC");
$all_employees = [];
while ($r = mysqli_fetch_assoc($emp_result)) $all_employees[] = $r;

// ── Filters ───────────────────────────────────────────────────────────────────
$f_emp    = isset($_GET['f_emp'])    ? intval($_GET['f_emp'])                              : 0;
$f_type   = isset($_GET['f_type'])   ? mysqli_real_escape_string($conn, $_GET['f_type'])   : '';
$f_status = isset($_GET['f_status']) ? mysqli_real_escape_string($conn, $_GET['f_status']) : '';
$f_month  = isset($_GET['f_month'])  ? mysqli_real_escape_string($conn, $_GET['f_month'])  : '';

$hw = "WHERE 1=1";
if ($f_emp)    $hw .= " AND la.employee_id=$f_emp";
if ($f_type)   $hw .= " AND la.leave_type='$f_type'";
if ($f_status) $hw .= " AND la.status='$f_status'";
if ($f_month)  $hw .= " AND DATE_FORMAT(la.start_date,'%Y-%m')='$f_month'";

// ── Export to Excel (respects current filters, includes ALL matching rows) ───
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $ehist = mysqli_query($conn,
        "SELECT la.*, e.employee_id AS emp_code, e.name_with_initials, e.employee_full_name
         FROM leave_applications la
         JOIN employees e ON la.employee_id = e.id
         $hw
         ORDER BY la.start_date DESC, la.applied_at DESC");

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="leave_applications_' . date('Y-m-d_His') . '.xls"');
    header('Pragma: public');
    header('Cache-Control: max-age=0');

    // UTF-8 BOM so names/remarks with special characters render correctly in Excel
    echo "\xEF\xBB\xBF";

    echo '<table border="1">';
    echo '<tr>
            <th>#</th>
            <th>Employee ID</th>
            <th>Employee Name</th>
            <th>Leave Type</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th>Total Days</th>
            <th>Remarks / Notes</th>
            <th>Status</th>
            <th>Date Applied</th>
          </tr>';

    $n = 1;
    while ($lv = mysqli_fetch_assoc($ehist)) {
        echo '<tr>';
        echo '<td>' . $n++ . '</td>';
        echo '<td>' . htmlspecialchars($lv['emp_code']) . '</td>';
        echo '<td>' . htmlspecialchars($lv['name_with_initials'] ?: $lv['employee_full_name']) . '</td>';
        echo '<td>' . htmlspecialchars($lv['leave_type']) . '</td>';
        echo '<td>' . date('d M Y', strtotime($lv['start_date'])) . '</td>';
        echo '<td>' . date('d M Y', strtotime($lv['end_date'])) . '</td>';
        echo '<td>' . (int)$lv['days_count'] . '</td>';
        echo '<td>' . htmlspecialchars($lv['remark'] ?: '') . '</td>';
        echo '<td>' . htmlspecialchars($lv['status']) . '</td>';
        echo '<td>' . date('d M Y h:i A', strtotime($lv['applied_at'])) . '</td>';
        echo '</tr>';
    }

    echo '</table>';
    exit;
}

// Build query string for the Export button so it carries forward current filters
$export_params = $_GET;
$export_params['export'] = 'excel';
$export_qs = http_build_query($export_params);

$hist = mysqli_query($conn,
    "SELECT la.*, e.employee_id AS emp_code, e.name_with_initials, e.employee_full_name
     FROM leave_applications la
     JOIN employees e ON la.employee_id = e.id
     $hw
     ORDER BY la.start_date DESC, la.applied_at DESC
     LIMIT 500");
$leave_list = [];
while ($r = mysqli_fetch_assoc($hist)) $leave_list[] = $r;

$total        = count($leave_list);
$cnt_pending  = count(array_filter($leave_list, fn($l) => $l['status'] === 'Pending'));
$cnt_approved = count(array_filter($leave_list, fn($l) => $l['status'] === 'Approved'));
$cnt_rejected = count(array_filter($leave_list, fn($l) => $l['status'] === 'Rejected'));

include 'header.php';
?>

<!-- ── Notifications ──────────────────────────────────────────────────────── -->
<?php
$msg_map = [
    'deleted'       => ['warning', 'trash',        'Leave application has been permanently deleted.'],
    'updated'       => ['success', 'circle-check', 'Leave application status updated successfully.'],
    'bulk_approved' => ['success', 'circle-check', 'Selected pending leave applications have been approved.'],
    'bulk_rejected' => ['warning', 'ban',          'Selected pending leave applications have been rejected.'],
    'added'         => ['success', 'circle-check', 'New leave application submitted successfully.'],
];
$msg_key = $_GET['msg'] ?? ($_GET['added'] ? 'added' : null);
if ($msg_key && isset($msg_map[$msg_key])):
    [$mc, $mi, $mt] = $msg_map[$msg_key];
?>
<div class="alert alert-<?php echo $mc; ?>">
    <i class="fa-solid fa-<?php echo $mi; ?>"></i> <?php echo $mt; ?>
</div>
<?php endif; ?>

<!-- ── Page Header ────────────────────────────────────────────────────────── -->
<div class="page-header">
    <div class="ph-inner">
        <div class="ph-text">
            <h2 class="page-title"><i class="fa-solid fa-calendar-days"></i> Leave Applications</h2>
            <p class="page-subtitle">View, filter, and manage all employee leave records in one place</p>
        </div>
        <a href="leave_add.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> New Application</a>
    </div>
</div>

<!-- ── Summary Strip ─────────────────────────────────────────────────────── -->
<div class="sum-strip">
    <div class="si">
        <span class="sn"><?php echo $total; ?></span>
        <span class="sl">Total Applications</span>
    </div>
    <div class="sd"></div>
    <div class="si">
        <span class="sn sw"><?php echo $cnt_pending; ?></span>
        <span class="sl">Awaiting Approval</span>
    </div>
    <div class="sd"></div>
    <div class="si">
        <span class="sn sg"><?php echo $cnt_approved; ?></span>
        <span class="sl">Approved</span>
    </div>
    <div class="sd"></div>
    <div class="si">
        <span class="sn sr"><?php echo $cnt_rejected; ?></span>
        <span class="sl">Rejected</span>
    </div>
    <?php if ($cnt_pending > 0): ?>
    <div class="sd"></div>
    <div class="si">
        <span class="sn sp"><?php echo $cnt_pending; ?></span>
        <span class="sl">Needs Action</span>
    </div>
    <?php endif; ?>
</div>

<!-- ── Filter Bar ─────────────────────────────────────────────────────────── -->
<div class="filter-bar">
    <div class="filter-bar-title"><i class="fa-solid fa-sliders"></i> Filter &amp; Search Leave Records</div>
    <form method="GET" class="filter-form">
        <div class="fg">
            <label class="fl">Employee Name / ID</label>
            <select name="f_emp" class="fi" id="hist_emp_select">
                <option value="">— All Employees —</option>
                <?php foreach ($all_employees as $e): ?>
                <option value="<?php echo $e['id']; ?>" <?php echo $f_emp==$e['id']?'selected':''; ?>>
                    <?php echo htmlspecialchars($e['employee_id'].' — '.($e['name_with_initials']?:$e['employee_full_name'])); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label class="fl">Leave Category / Type</label>
            <select name="f_type" class="fi">
                <option value="">— All Leave Types —</option>
                <?php foreach (['Annual Leave','Casual Leave','Medical Leave','Public Holiday Leave'] as $lt): ?>
                <option value="<?php echo $lt; ?>" <?php echo $f_type===$lt?'selected':''; ?>><?php echo $lt; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label class="fl">Application Status</label>
            <select name="f_status" class="fi">
                <option value="">— All Statuses —</option>
                <option value="Pending"  <?php echo $f_status==='Pending' ?'selected':''; ?>>Pending Approval</option>
                <option value="Approved" <?php echo $f_status==='Approved'?'selected':''; ?>>Approved</option>
                <option value="Rejected" <?php echo $f_status==='Rejected'?'selected':''; ?>>Rejected</option>
            </select>
        </div>
        <div class="fg">
            <label class="fl">Leave Start Month / Year</label>
            <input type="month" name="f_month" class="fi" value="<?php echo htmlspecialchars($f_month); ?>" placeholder="Select month">
        </div>
        <div class="fg fg-actions">
            <label class="fl">&nbsp;</label>
            <div style="display:flex;gap:6px;">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Apply Filters</button>
                <a href="leave_list.php" class="btn btn-ghost"><i class="fa-solid fa-rotate-left"></i> Reset</a>
                <a href="?<?php echo htmlspecialchars($export_qs); ?>" class="btn btn-export" title="Export current filtered results to Excel">
                    <i class="fa-solid fa-file-excel"></i> Export to Excel
                </a>
            </div>
        </div>
        <div class="filter-count">
            <i class="fa-solid fa-table-list"></i>
            <b><?php echo $total; ?></b> record<?php echo $total!==1?'s':''; ?> found
        </div>
    </form>
</div>

<!-- ── Bulk Action Bar (only if pending records exist) ───────────────────── -->
<?php if ($cnt_pending > 0): ?>
<form method="POST" id="bulk-form">
    <div class="bulk-bar" id="bulk-bar">
        <div class="bulk-bar-left">
            <label class="check-all-wrap">
                <input type="checkbox" id="check-all" title="Select all pending">
                <span class="check-label">Select All Pending (<span id="sel-count">0</span> / <?php echo $cnt_pending; ?> selected)</span>
            </label>
        </div>
        <div class="bulk-bar-right">
            <button type="submit" name="bulk_approve" class="btn btn-approve"
                onclick="return confirmBulk('approve')"
                id="btn-bulk-approve" disabled>
                <i class="fa-solid fa-check-double"></i> Approve Selected
            </button>
            <button type="submit" name="bulk_reject" class="btn btn-reject"
                onclick="return confirmBulk('reject')"
                id="btn-bulk-reject" disabled>
                <i class="fa-solid fa-ban"></i> Reject Selected
            </button>
        </div>
    </div>
<?php endif; ?>

<!-- ── Leave Table ────────────────────────────────────────────────────────── -->
<div class="lv-card">
    <div class="table-wrap">
        <?php if (empty($leave_list)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-calendar-xmark"></i>
            <p>No leave applications found matching your filters.</p>
            <a href="leave_add.php" class="btn btn-primary" style="margin-top:12px;"><i class="fa-solid fa-plus"></i> Add New Application</a>
        </div>
        <?php else: ?>
        <table class="lv-table">
            <thead>
                <tr>
                    <?php if ($cnt_pending > 0): ?>
                    <th class="th-check" title="Bulk select pending rows"><i class="fa-regular fa-square-check"></i></th>
                    <?php endif; ?>
                    <th class="th-num">#</th>
                    <th>Employee</th>
                    <th>Leave Category / Type</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th class="th-c">Total Days</th>
                    <th>Remarks / Notes</th>
                    <th class="th-c">Reference Doc</th>
                    <th>Application Status</th>
                    <th>Date Applied</th>
                    <th class="th-c">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($leave_list as $i => $lv):
                $lc = [
                    'Annual Leave'          => ['#3b82f6','#eff6ff'],
                    'Casual Leave'          => ['#8b5cf6','#f5f3ff'],
                    'Medical Leave'         => ['#ef4444','#fef2f2'],
                    'Public Holiday Leave'  => ['#f59e0b','#fffbeb'],
                ][$lv['leave_type']] ?? ['#6b7280','#f9fafb'];
                $isPending = $lv['status'] === 'Pending';
            ?>
            <tr class="lv-<?php echo strtolower($lv['status']); ?>" data-pending="<?php echo $isPending?'1':'0'; ?>">
                <?php if ($cnt_pending > 0): ?>
                <td class="td-check">
                    <?php if ($isPending): ?>
                    <input type="checkbox" name="selected_ids[]" value="<?php echo $lv['id']; ?>" class="row-check">
                    <?php else: ?>
                    <span class="check-na" title="Not pending">—</span>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td class="td-num"><?php echo $i + 1; ?></td>
                <td class="td-emp">
                    <div class="en"><?php echo htmlspecialchars($lv['emp_code']); ?></div>
                    <div class="es"><?php echo htmlspecialchars($lv['name_with_initials'] ?: $lv['employee_full_name']); ?></div>
                </td>
                <td>
                    <span class="lt-b" style="color:<?php echo $lc[0]; ?>;background:<?php echo $lc[1]; ?>;border:1px solid <?php echo $lc[0]; ?>33;">
                        <?php echo htmlspecialchars($lv['leave_type']); ?>
                    </span>
                </td>
                <td class="td-d"><?php echo date('d M Y', strtotime($lv['start_date'])); ?></td>
                <td class="td-d"><?php echo date('d M Y', strtotime($lv['end_date'])); ?></td>
                <td class="td-days"><?php echo $lv['days_count']; ?> <span class="day-label">day<?php echo $lv['days_count']!=1?'s':''; ?></span></td>
                <td class="td-rem"><?php echo $lv['remark'] ? '<span title="'.htmlspecialchars($lv['remark']).'">'.htmlspecialchars(mb_strimwidth($lv['remark'],0,40,'…')).'</span>' : '<span class="na">—</span>'; ?></td>
                <td class="td-c">
                    <?php if ($lv['reference_doc']): ?>
                    <a href="<?php echo htmlspecialchars($lv['reference_doc']); ?>" target="_blank" class="ab av" title="View Attached Document"><i class="fa-solid fa-paperclip"></i></a>
                    <?php else: ?><span class="na">—</span><?php endif; ?>
                </td>
                <td>
                    <?php
                    $sc = ['Pending'=>'bw','Approved'=>'bg','Rejected'=>'br'][$lv['status']] ?? 'bw';
                    $icons = ['Pending'=>'clock','Approved'=>'circle-check','Rejected'=>'circle-xmark'];
                    $ic = $icons[$lv['status']] ?? 'clock';
                    echo '<span class="badge '.$sc.'"><i class="fa-solid fa-'.$ic.'"></i> '.$lv['status'].'</span>';
                    ?>
                </td>
                <td class="td-d">
                    <?php echo date('d M Y', strtotime($lv['applied_at'])); ?>
                    <div class="es"><?php echo date('h:i A', strtotime($lv['applied_at'])); ?></div>
                </td>
                <td>
                    <div class="act-row">
                        <a href="leave_edit.php?id=<?php echo $lv['id']; ?>" class="ab ae" title="Edit Application"><i class="fa-solid fa-pen"></i></a>
                        <?php if ($isPending): ?>
                        <a href="?action=approve&leave_id=<?php echo $lv['id']; ?>" class="ab aa" title="Approve This Leave" onclick="return confirm('Approve this leave application?')"><i class="fa-solid fa-check"></i></a>
                        <a href="?action=reject&leave_id=<?php echo $lv['id']; ?>" class="ab arj" title="Reject This Leave" onclick="return confirm('Reject this leave application?')"><i class="fa-solid fa-xmark"></i></a>
                        <?php endif; ?>
                        <a href="?delete_leave=<?php echo $lv['id']; ?>" class="ab ad" title="Delete Application" onclick="return confirm('Permanently delete this leave application? This cannot be undone.')"><i class="fa-solid fa-trash"></i></a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php if ($cnt_pending > 0): ?>
</form>
<?php endif; ?>

<!-- ── Styles ─────────────────────────────────────────────────────────────── -->
<style>
*, *::before, *::after { box-sizing: border-box; }

/* ── Page header ── */
.page-header { margin-bottom: 20px; }
.ph-inner { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
.ph-text {}
.page-title { font-size: 22px; font-weight: 800; margin: 0 0 4px; color: #0f172a; display: flex; align-items: center; gap: 10px; letter-spacing: -.3px; }
.page-title i { color: #3b82f6; }
.page-subtitle { font-size: 13px; color: #64748b; margin: 0; }

/* ── Summary strip ── */
.sum-strip {
    display: flex; align-items: center; gap: 0;
    background: #fff; border: 1px solid #e2e8f0;
    border-radius: 12px; margin-bottom: 14px;
    overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.si { display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 14px 28px; flex: 1; }
.sn { font-size: 28px; font-weight: 900; color: #0f172a; line-height: 1; }
.sw { color: #f59e0b; } .sg { color: #22c55e; } .sr { color: #ef4444; } .sp { color: #8b5cf6; }
.sl { font-size: 10px; color: #94a3b8; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; white-space: nowrap; }
.sd { width: 1px; background: #e2e8f0; align-self: stretch; }

/* ── Filter bar ── */
.filter-bar {
    background: #fff; border: 1px solid #e2e8f0;
    border-radius: 12px; padding: 16px 18px; margin-bottom: 14px;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.filter-bar-title { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 12px; display: flex; align-items: center; gap: 7px; }
.filter-form { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
.fg { display: flex; flex-direction: column; gap: 5px; }
.fl { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .4px; }
.fi {
    padding: 9px 12px; border: 1.5px solid #e2e8f0;
    border-radius: 8px; font-size: 12.5px; font-family: inherit;
    min-width: 200px; background: #fff; color: #0f172a;
    transition: border-color .15s, box-shadow .15s;
    height: 40px;
}
.fi:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
.fg-actions { }
.filter-count {
    margin-left: auto; align-self: flex-end;
    font-size: 12px; color: #475569;
    background: #f1f5f9; padding: 8px 14px;
    border-radius: 8px; display: flex; align-items: center; gap: 6px;
    border: 1px solid #e2e8f0;
}
.filter-count b { color: #0f172a; font-size: 14px; }

/* ── Bulk action bar ── */
.bulk-bar {
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
    background: linear-gradient(135deg, #fefce8 0%, #fffbeb 100%);
    border: 1.5px solid #fcd34d;
    border-radius: 10px; padding: 11px 16px; margin-bottom: 10px;
}
.bulk-bar-left { display: flex; align-items: center; gap: 12px; }
.bulk-bar-right { display: flex; gap: 8px; }
.check-all-wrap { display: flex; align-items: center; gap: 8px; cursor: pointer; }
.check-all-wrap input[type="checkbox"] { width: 16px; height: 16px; accent-color: #3b82f6; cursor: pointer; }
.check-label { font-size: 13px; font-weight: 600; color: #92400e; }

/* ── Card & Table ── */
.lv-card {
    background: #fff; border: 1px solid #e2e8f0;
    border-radius: 12px; overflow: hidden;
    box-shadow: 0 1px 6px rgba(0,0,0,.05);
    margin-bottom: 20px;
}
.table-wrap { overflow-x: auto; }
.lv-table { width: 100%; border-collapse: collapse; font-size: 12.5px; min-width: 960px; }
.lv-table thead th {
    background: #0f172a; color: #94a3b8;
    padding: 11px 13px; text-align: left;
    font-size: 10.5px; font-weight: 700;
    letter-spacing: .5px; text-transform: uppercase;
    white-space: nowrap;
}
.lv-table thead th.th-c, .lv-table td.td-c { text-align: center; }
.lv-table thead th.th-num, .lv-table td.td-num { width: 40px; color: #94a3b8; font-size: 11px; }
.lv-table thead th.th-check, .lv-table td.td-check { width: 42px; text-align: center; }
.lv-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .1s; }
.lv-table tbody tr:last-child { border-bottom: none; }
.lv-table tbody tr:hover { background: #f8faff; }
.lv-table td { padding: 10px 13px; vertical-align: middle; }

/* Status row accents */
.lv-pending  { border-left: 3px solid #f59e0b; }
.lv-approved { border-left: 3px solid #22c55e; }
.lv-rejected { border-left: 3px solid #ef4444; opacity: .7; }

/* Cells */
.td-d { white-space: nowrap; font-size: 12px; color: #475569; }
.td-rem { font-size: 12px; color: #475569; max-width: 150px; }
.td-days { text-align: center; font-weight: 800; font-size: 15px; color: #0f172a; }
.day-label { font-size: 10px; font-weight: 500; color: #94a3b8; }
.en { font-weight: 700; font-size: 12.5px; color: #0f172a; }
.es { font-size: 10.5px; color: #94a3b8; margin-top: 1px; }
.na { color: #cbd5e1; }
.lt-b {
    display: inline-block; padding: 3px 10px;
    border-radius: 20px; font-size: 10.5px; font-weight: 700;
    white-space: nowrap; letter-spacing: .2px;
}
.badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 10.5px; font-weight: 700; white-space: nowrap;
}
.bg { background: #dcfce7; color: #166534; }
.bw { background: #fef3c7; color: #92400e; }
.br { background: #fee2e2; color: #991b1b; }

/* Checkbox cells */
.td-check input[type="checkbox"] { width: 15px; height: 15px; accent-color: #3b82f6; cursor: pointer; }
.check-na { color: #e2e8f0; font-size: 14px; }
tr[data-pending="1"].row-selected { background: #eff6ff !important; }

/* Action buttons */
.act-row { display: flex; gap: 4px; justify-content: center; }
.ab {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 7px;
    border: 1px solid #e2e8f0; background: #fff;
    color: #94a3b8; text-decoration: none;
    font-size: 11px; transition: all .15s; cursor: pointer;
}
.av:hover { background: #3b82f6; color: #fff; border-color: #3b82f6; }
.ae:hover { background: #0f172a; color: #fff; border-color: #0f172a; }
.aa:hover { background: #22c55e; color: #fff; border-color: #22c55e; }
.arj:hover { background: #f59e0b; color: #fff; border-color: #f59e0b; }
.ad:hover { background: #ef4444; color: #fff; border-color: #ef4444; }

/* Empty state */
.empty-state { text-align: center; padding: 70px 20px; color: #cbd5e1; }
.empty-state i { font-size: 48px; display: block; margin-bottom: 14px; }
.empty-state p { font-size: 14px; margin: 0; color: #94a3b8; }

/* Buttons */
.btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 18px; border: none; border-radius: 8px;
    font-size: 13px; font-weight: 600; cursor: pointer;
    transition: all .18s; text-decoration: none;
    font-family: inherit; white-space: nowrap; height: 40px;
}
.btn-primary { background: #0f172a; color: #fff; }
.btn-primary:hover { background: #1e293b; }
.btn-ghost { background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; }
.btn-ghost:hover { background: #e2e8f0; }
.btn-approve { background: #22c55e; color: #fff; }
.btn-approve:hover:not(:disabled) { background: #16a34a; }
.btn-approve:disabled { opacity: .4; cursor: not-allowed; }
.btn-reject { background: #f59e0b; color: #fff; }
.btn-reject:hover:not(:disabled) { background: #d97706; }
.btn-reject:disabled { opacity: .4; cursor: not-allowed; }
.btn-export { background: #107c41; color: #fff; }
.btn-export:hover { background: #0c5e31; }

/* Alerts */
.alert {
    display: flex; align-items: center; gap: 9px;
    padding: 11px 15px; border-radius: 9px;
    font-size: 13px; font-weight: 500; margin-bottom: 14px;
}
.alert-success { background: #dcfce7; border: 1px solid #bbf7d0; color: #166534; }
.alert-warning  { background: #fef3c7; border: 1px solid #fcd34d; color: #92400e; }

/* Select2 overrides */
.select2-container .select2-selection--single { height: 40px !important; border: 1.5px solid #e2e8f0 !important; border-radius: 8px !important; }
.select2-container .select2-selection--single .select2-selection__rendered { line-height: 40px !important; padding-left: 12px !important; font-size: 12.5px !important; color: #0f172a !important; }
.select2-container .select2-selection--single .select2-selection__arrow { height: 38px !important; }
.select2-container--open .select2-selection--single { border-color: #3b82f6 !important; box-shadow: 0 0 0 3px rgba(59,130,246,.12) !important; }
.select2-dropdown { border: 1.5px solid #e2e8f0 !important; border-radius: 9px !important; box-shadow: 0 8px 30px rgba(0,0,0,.1) !important; font-size: 12.5px !important; }
.select2-results__option--highlighted { background: #3b82f6 !important; }
</style>

<!-- ── Scripts ────────────────────────────────────────────────────────────── -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(function(){
    $('#hist_emp_select').select2({ placeholder: '— All Employees —', allowClear: true, width: '100%' });
});

// ── Bulk select logic ────────────────────────────────────────────────────────
const checkAll      = document.getElementById('check-all');
const btnApprove    = document.getElementById('btn-bulk-approve');
const btnReject     = document.getElementById('btn-bulk-reject');
const selCountEl    = document.getElementById('sel-count');

function getChecked() {
    return document.querySelectorAll('.row-check:checked');
}
function updateBulkBar() {
    const n = getChecked().length;
    if (selCountEl) selCountEl.textContent = n;
    const disabled = n === 0;
    if (btnApprove) btnApprove.disabled = disabled;
    if (btnReject)  btnReject.disabled  = disabled;
    if (checkAll) {
        const total = document.querySelectorAll('.row-check').length;
        checkAll.indeterminate = n > 0 && n < total;
        checkAll.checked = n === total && total > 0;
    }
    // highlight selected rows
    document.querySelectorAll('.row-check').forEach(cb => {
        cb.closest('tr').classList.toggle('row-selected', cb.checked);
    });
}

if (checkAll) {
    checkAll.addEventListener('change', function() {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
        updateBulkBar();
    });
}
document.querySelectorAll('.row-check').forEach(cb => {
    cb.addEventListener('change', updateBulkBar);
});

function confirmBulk(action) {
    const n = getChecked().length;
    if (n === 0) { alert('Please select at least one pending application.'); return false; }
    const label = action === 'approve' ? 'approve' : 'reject';
    return confirm(`Are you sure you want to ${label} ${n} selected leave application${n>1?'s':''}?`);
}
</script>

<?php include 'footer.php'; ?>