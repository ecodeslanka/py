<?php
// ── Yelo Group HMS — Add / Bulk Attendance ───────────────────────────────────

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/attendance_error.log');

if (file_exists(__DIR__ . '/config.php')) {
    include __DIR__ . '/config.php';
} else {
    $host = '127.0.0.1';
    $db   = 'u645685294_ylerp';
    $user = 'your_db_user';
    $pass = 'your_db_pass';
    $conn = mysqli_connect($host, $user, $pass, $db);
}

if (!$conn || mysqli_connect_errno()) {
    if (isset($_SERVER['REQUEST_METHOD']) && (
        ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['ajax_save']) || isset($_POST['ajax_delete']))) ||
        isset($_GET['ajax_load'])
    )) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . mysqli_connect_error()]);
        exit;
    }
    die('<h3 style="color:red;font-family:sans-serif;padding:20px;">Database connection failed: ' . htmlspecialchars(mysqli_connect_error()) . '</h3>');
}

mysqli_set_charset($conn, 'utf8mb4');

// ── Ensure holiday / leave tables exist (read-only lookups on this page) ─────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS special_holidays (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    target_type ENUM('all','staff_category','designation','employee') NOT NULL DEFAULT 'all',
    target_ids TEXT NULL COMMENT 'JSON array of IDs',
    notes TEXT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

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

// ── AJAX: Delete single attendance record ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete'])) {
    header('Content-Type: application/json');

    $employee_id = intval($_POST['employee_id'] ?? 0);
    $att_date    = mysqli_real_escape_string($conn, trim($_POST['att_date'] ?? ''));

    if (!$employee_id || !$att_date) {
        echo json_encode(['success' => false, 'message' => 'Invalid data.']);
        exit;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $att_date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
        exit;
    }

    $ok = mysqli_query($conn,
        "DELETE FROM attendance WHERE employee_id = $employee_id AND att_date = '$att_date' LIMIT 1"
    );

    if ($ok) {
        echo json_encode(['success' => true, 'message' => "Record for $att_date deleted."]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Save attendance ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');

    $employee_id = intval($_POST['employee_id'] ?? 0);
    $records     = $_POST['records'] ?? [];
    $saved = 0; $skipped = 0; $deleted = 0; $errors = [];

    if (!$employee_id) {
        echo json_encode(['success' => false, 'message' => 'No employee selected.']);
        exit;
    }

    if (!is_array($records)) {
        echo json_encode(['success' => false, 'message' => 'Invalid records data.']);
        exit;
    }

    foreach ($records as $rec) {
        $att_date  = mysqli_real_escape_string($conn, trim($rec['date']      ?? ''));
        $check_in  = mysqli_real_escape_string($conn, trim($rec['check_in']  ?? ''));
        $check_out = mysqli_real_escape_string($conn, trim($rec['check_out'] ?? ''));
        $attended  = intval($rec['attended'] ?? 0);

        if (!$att_date) { $skipped++; continue; }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $att_date)) { $skipped++; continue; }

        // ── FIX: if the day is UNTICKED, remove any existing DB record for it ──
        // Previously this branch was just "skipped", so unticking an existing
        // attended day never actually removed/updated the stored record.
        if (!$attended) {
            $check = mysqli_query($conn,
                "SELECT id FROM attendance WHERE employee_id = $employee_id AND att_date = '$att_date' LIMIT 1"
            );

            if ($check === false) {
                $errors[] = $att_date . ' (query error: ' . mysqli_error($conn) . ')';
                continue;
            }

            if (mysqli_num_rows($check) > 0) {
                $row = mysqli_fetch_assoc($check);
                $id  = intval($row['id']);
                $ok  = mysqli_query($conn, "DELETE FROM attendance WHERE id = $id LIMIT 1");
                if ($ok) {
                    $deleted++;
                } else {
                    $errors[] = $att_date . ' (delete error: ' . mysqli_error($conn) . ')';
                }
            } else {
                $skipped++;
            }
            continue;
        }

        $ci_dt = $check_in  ? "'$att_date $check_in:00'" : 'NULL';
        $co_dt = $check_out ? "'$att_date $check_out:00'" : 'NULL';

        $check = mysqli_query($conn,
            "SELECT id FROM attendance WHERE employee_id = $employee_id AND att_date = '$att_date' LIMIT 1"
        );

        if ($check === false) {
            $errors[] = $att_date . ' (query error: ' . mysqli_error($conn) . ')';
            continue;
        }

        if (mysqli_num_rows($check) > 0) {
            $row = mysqli_fetch_assoc($check);
            $id  = intval($row['id']);
            $ok  = mysqli_query($conn,
                "UPDATE attendance SET check_in = $ci_dt, check_out = $co_dt WHERE id = $id"
            );
        } else {
            $ok = mysqli_query($conn,
                "INSERT INTO attendance (employee_id, att_date, check_in, check_out)
                 VALUES ($employee_id, '$att_date', $ci_dt, $co_dt)"
            );
        }

        if ($ok) {
            $saved++;
        } else {
            $errors[] = $att_date . ' (' . mysqli_error($conn) . ')';
        }
    }

    echo json_encode([
        'success' => true,
        'saved'   => $saved,
        'deleted' => $deleted,
        'skipped' => $skipped,
        'errors'  => $errors,
        'message' => "$saved record(s) saved, $deleted removed, $skipped skipped."
    ]);
    exit;
}

// ── AJAX: Load existing attendance (+ holidays + leaves for the employee) ────
if (isset($_GET['ajax_load'])) {
    header('Content-Type: application/json');

    $emp_id = intval($_GET['employee_id'] ?? 0);
    $year   = intval($_GET['year']  ?? date('Y'));
    $month  = intval($_GET['month'] ?? date('n'));

    if ($year < 2000 || $year > 2100) $year = (int)date('Y');
    if ($month < 1   || $month > 12)  $month = (int)date('n');

    $from = sprintf('%04d-%02d-01', $year, $month);
    $to   = date('Y-m-t', strtotime($from));

    $existing = [];
    if ($emp_id > 0) {
        $res = mysqli_query($conn,
            "SELECT att_date, check_in, check_out FROM attendance
             WHERE employee_id = $emp_id AND att_date BETWEEN '$from' AND '$to'
             ORDER BY att_date ASC"
        );

        if ($res === false) {
            echo json_encode(['success' => false, 'message' => 'Query failed: ' . mysqli_error($conn)]);
            exit;
        }

        while ($r = mysqli_fetch_assoc($res)) {
            $existing[$r['att_date']] = [
                'check_in'  => $r['check_in']  ? substr($r['check_in'],  11, 5) : '',
                'check_out' => $r['check_out'] ? substr($r['check_out'], 11, 5) : '',
            ];
        }
    }

    // ── Special Holidays applicable to this employee within the period ──────
    $holidays = [];
    if ($emp_id > 0) {
        $emp_cat_id = 0; $emp_des_id = 0;
        $empRes = mysqli_query($conn, "SELECT staff_category_id, designation_id FROM employees WHERE id = $emp_id LIMIT 1");
        if ($empRes && ($empRow = mysqli_fetch_assoc($empRes))) {
            $emp_cat_id = intval($empRow['staff_category_id'] ?? 0);
            $emp_des_id = intval($empRow['designation_id'] ?? 0);
        }

        $hres = mysqli_query($conn,
            "SELECT title, date_from, date_to, target_type, target_ids FROM special_holidays
             WHERE active = 1 AND date_from <= '$to' AND date_to >= '$from'"
        );
        if ($hres) {
            while ($h = mysqli_fetch_assoc($hres)) {
                $applies = false;
                if ($h['target_type'] === 'all') {
                    $applies = true;
                } else {
                    $ids = json_decode($h['target_ids'] ?? '[]', true);
                    $ids = is_array($ids) ? $ids : [];
                    if ($h['target_type'] === 'staff_category' && $emp_cat_id && in_array($emp_cat_id, $ids)) $applies = true;
                    if ($h['target_type'] === 'designation'    && $emp_des_id && in_array($emp_des_id, $ids)) $applies = true;
                    if ($h['target_type'] === 'employee'       && in_array($emp_id, $ids)) $applies = true;
                }
                if (!$applies) continue;

                $d_start = ($h['date_from'] > $from) ? $h['date_from'] : $from;
                $d_end   = ($h['date_to']   < $to)   ? $h['date_to']   : $to;
                $cur = strtotime($d_start);
                $end = strtotime($d_end);
                while ($cur <= $end) {
                    $dk = date('Y-m-d', $cur);
                    $holidays[$dk] = isset($holidays[$dk]) ? $holidays[$dk] . ' / ' . $h['title'] : $h['title'];
                    $cur = strtotime('+1 day', $cur);
                }
            }
        }
    }

    // ── Leave applications for this employee within the period ──────────────
    $leaves = [];
    if ($emp_id > 0) {
        $lres = mysqli_query($conn,
            "SELECT leave_type, start_date, end_date, status FROM leave_applications
             WHERE employee_id = $emp_id AND start_date <= '$to' AND end_date >= '$from'"
        );
        if ($lres) {
            while ($l = mysqli_fetch_assoc($lres)) {
                $d_start = ($l['start_date'] > $from) ? $l['start_date'] : $from;
                $d_end   = ($l['end_date']   < $to)   ? $l['end_date']   : $to;
                $cur = strtotime($d_start);
                $end = strtotime($d_end);
                while ($cur <= $end) {
                    $dk = date('Y-m-d', $cur);
                    $leaves[$dk] = ['type' => $l['leave_type'], 'status' => $l['status']];
                    $cur = strtotime('+1 day', $cur);
                }
            }
        }
    }

    echo json_encode([
        'success'  => true,
        'existing' => $existing,
        'holidays' => $holidays,
        'leaves'   => $leaves,
    ]);
    exit;
}

// ── Employee list ─────────────────────────────────────────────────────────────
$emp_res = mysqli_query($conn,
    "SELECT id, employee_id, employee_full_name FROM employees WHERE active = 1 ORDER BY employee_full_name"
);

if ($emp_res === false) {
    die('<h3 style="color:red;font-family:sans-serif;padding:20px;">Failed to load employees: ' . htmlspecialchars(mysqli_error($conn)) . '</h3>');
}

$employees = [];
while ($e = mysqli_fetch_assoc($emp_res)) {
    $employees[] = $e;
}

if (file_exists(__DIR__ . '/header.php')) {
    include __DIR__ . '/header.php';
} else {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Add Attendance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    </head><body style="font-family:sans-serif;background:#f3f4f6;padding:20px;">';
}
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-calendar-plus" style="color:#2563eb;margin-right:8px;"></i>Add Attendance
            </h2>
            <p class="page-subtitle">Select an employee, choose month/year, mark attended days and set times — then save.</p>
        </div>
        <a href="attendance_report.php" class="btn btn-light">
            <i class="fa-solid fa-arrow-left"></i> Back to Report
        </a>
    </div>
</div>

<!-- Step 1 -->
<div class="content-card" style="margin-bottom:16px;">
    <div class="card-section-title">
        <i class="fa-solid fa-1" style="background:#2563eb;color:#fff;border-radius:50%;width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;margin-right:8px;"></i>
        Select Employee &amp; Period
    </div>

    <div class="top-selectors">
        <div class="selector-group" style="flex:2;min-width:260px;">
            <label class="field-label">Employee <span class="req">*</span></label>
            <select id="empSelect" style="width:100%;">
                <option value="">— Choose Employee —</option>
                <?php foreach ($employees as $e): ?>
                <option value="<?php echo intval($e['id']); ?>"
                        data-code="<?php echo htmlspecialchars($e['employee_id'], ENT_QUOTES); ?>">
                    <?php echo htmlspecialchars($e['employee_id'] . ' — ' . $e['employee_full_name'], ENT_QUOTES); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="selector-group" style="flex:1;min-width:150px;">
            <label class="field-label">Month <span class="req">*</span></label>
            <select id="selMonth" class="native-select">
                <?php
                $months = ['January','February','March','April','May','June',
                           'July','August','September','October','November','December'];
                $curM = (int)date('n');
                foreach ($months as $mi => $mn):
                    $sel = ($mi + 1 === $curM) ? 'selected' : '';
                ?>
                <option value="<?php echo $mi + 1; ?>" <?php echo $sel; ?>><?php echo $mn; ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="selector-group" style="flex:1;min-width:120px;">
            <label class="field-label">Year <span class="req">*</span></label>
            <select id="selYear" class="native-select">
                <?php
                $curY = (int)date('Y');
                for ($y = $curY - 2; $y <= $curY + 1; $y++):
                    $sel = ($y === $curY) ? 'selected' : '';
                ?>
                <option value="<?php echo $y; ?>" <?php echo $sel; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="selector-group" style="justify-content:flex-end;align-items:flex-end;">
            <button id="btnLoad" class="btn btn-primary" onclick="loadCalendar()">
                <i class="fa-solid fa-calendar-days"></i> Load Calendar
            </button>
        </div>
    </div>
</div>

<!-- Step 2 -->
<div class="content-card" id="calendarCard" style="display:none;margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <div>
            <div class="card-section-title" style="margin:0;">
                <i class="fa-solid fa-2" style="background:#2563eb;color:#fff;border-radius:50%;width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;margin-right:8px;"></i>
                Mark Attendance — <span id="calTitle" style="color:#2563eb;"></span>
            </div>
            <div style="font-size:12px;color:#6b7280;margin-top:4px;" id="empInfo"></div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:6px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:6px 12px;">
                <span style="font-size:11px;font-weight:600;color:#0369a1;">Default Times:</span>
                <input type="time" id="defaultIn"  value="07:30" class="time-mini" title="Default Check In">
                <span style="font-size:11px;color:#6b7280;">→</span>
                <input type="time" id="defaultOut" value="17:30" class="time-mini" title="Default Check Out">
                <button onclick="applyDefaultTimes()" class="btn-xs btn-blue" title="Apply to all checked rows">
                    <i class="fa-solid fa-arrow-rotate-right"></i> Apply All
                </button>
            </div>
            <button onclick="markAll(true)"  class="btn-xs btn-green"><i class="fa-solid fa-check-double"></i> Mark All</button>
            <button onclick="markAll(false)" class="btn-xs btn-gray"><i class="fa-solid fa-xmark"></i> Clear All</button>
        </div>
    </div>

    <!-- Legend -->
    <div style="display:flex;gap:12px;margin-bottom:12px;flex-wrap:wrap;">
        <div class="legend-item"><span class="legend-dot" style="background:#dcfce7;border:1px solid #86efac;"></span> Attended</div>
        <div class="legend-item"><span class="legend-dot" style="background:#fff7ed;border:1px solid #fed7aa;"></span> Existing Record</div>
        <div class="legend-item"><span class="legend-dot" style="background:#ffedd5;border:1px solid #fb923c;"></span> <i class="fa-solid fa-umbrella-beach" style="color:#c2410c;font-size:10px;"></i> Holiday</div>
        <div class="legend-item"><span class="legend-dot" style="background:#ede9fe;border:1px solid #c4b5fd;"></span> <i class="fa-solid fa-plane-departure" style="color:#7c3aed;font-size:10px;"></i> On Leave</div>
        <div class="legend-item"><span class="legend-dot" style="background:#fef9c3;border:1px solid #fde047;"></span> Friday</div>
        <div class="legend-item"><span class="legend-dot" style="background:#fce7f3;border:1px solid #f9a8d4;"></span> Saturday</div>
        <div class="legend-item"><span class="legend-dot" style="background:#fef2f2;border:1px solid #fca5a5;"></span> Sunday</div>
        <div class="legend-item"><span class="legend-dot" style="background:#f9fafb;border:1px solid #e5e7eb;"></span> Not Marked</div>
    </div>

    <div class="table-responsive">
        <table class="att-table" id="attTable">
            <thead>
                <tr>
                    <th style="width:36px;">
                        <input type="checkbox" id="chkAll" onchange="toggleAll(this)" title="Select all">
                    </th>
                    <th>Date</th>
                    <th>Day</th>
                    <th>Status</th>
                    <th>Check In</th>
                    <th>Check Out</th>
                    <th>Hours</th>
                    <th>Note</th>
                    <th style="width:60px;">Delete</th>
                </tr>
            </thead>
            <tbody id="attBody">
                <tr>
                    <td colspan="9" style="text-align:center;padding:40px;color:#9ca3af;">
                        <i class="fa-solid fa-calendar-days" style="font-size:32px;margin-bottom:12px;display:block;"></i>
                        Select an employee and click <strong>Load Calendar</strong>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="summary-bar" id="summaryBar">
        <div class="sum-item"><span class="sum-dot green"></span><span id="sumAttended">0</span> Attended</div>
        <div class="sum-item"><span class="sum-dot orange"></span><span id="sumExisting">0</span> Existing</div>
        <div class="sum-item"><span class="sum-dot blue"></span><span id="sumTotal">0</span> Total Days</div>
    </div>
</div>

<!-- Save Bar -->
<div class="save-bar" id="saveBar" style="display:none;">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <div id="saveInfo" style="font-size:13px;color:#374151;"></div>
        <div id="saveMsg"  style="font-size:13px;font-weight:600;"></div>
    </div>
    <div style="display:flex;gap:8px;">
        <button onclick="resetAll()" class="btn btn-light"><i class="fa-solid fa-rotate-left"></i> Reset</button>
        <button onclick="saveAttendance()" class="btn btn-primary" id="btnSave">
            <i class="fa-solid fa-floppy-disk"></i> Save Attendance
        </button>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div id="deleteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99998;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:28px 30px;max-width:380px;width:90%;box-shadow:0 12px 40px rgba(0,0,0,.2);animation:slideInRight .2s ease;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <div style="width:42px;height:42px;background:#fef2f2;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fa-solid fa-trash" style="color:#dc2626;font-size:18px;"></i>
            </div>
            <div>
                <div style="font-weight:700;font-size:15px;color:#111827;">Delete Attendance Record</div>
                <div style="font-size:12px;color:#6b7280;">This action cannot be undone.</div>
            </div>
        </div>
        <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;margin-bottom:18px;font-size:13px;color:#7f1d1d;" id="deleteModalMsg">
            Are you sure you want to delete the attendance record for <strong id="deleteDateLabel"></strong>?
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button onclick="closeDeleteModal()" class="btn btn-light">Cancel</button>
            <button onclick="confirmDelete()" class="btn btn-danger" id="btnConfirmDelete">
                <i class="fa-solid fa-trash"></i> Delete
            </button>
        </div>
    </div>
</div>

<style>
.page-header   { margin-bottom:20px; }
.page-title    { font-size:24px;font-weight:700;color:#111827;margin:0 0 3px; }
.page-subtitle { font-size:13px;color:#6b7280;margin:0; }

.content-card {
    background:#fff;border-radius:12px;
    box-shadow:0 1px 4px rgba(0,0,0,.08);padding:20px 22px;
}
.card-section-title {
    font-size:14px;font-weight:700;color:#111827;
    display:flex;align-items:center;margin-bottom:16px;
}
.field-label { font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;display:block; }
.req { color:#ef4444; }

.top-selectors { display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap; }
.selector-group { display:flex;flex-direction:column; }

.select2-container--default .select2-selection--single {
    height:38px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;line-height:38px;
}
.select2-container--default .select2-selection--single .select2-selection__rendered { line-height:38px;padding-left:12px;color:#111827; }
.select2-container--default .select2-selection--single .select2-selection__arrow { height:36px;right:6px; }
.select2-container--default.select2-container--focus .select2-selection--single { border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12); }
.select2-dropdown { border-color:#d1d5db;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);font-size:13px; }
.select2-container--default .select2-results__option--highlighted[aria-selected] { background:#2563eb; }
.select2-search--dropdown .select2-search__field { border:1px solid #d1d5db;border-radius:6px;padding:6px 10px;font-size:13px; }

.native-select {
    height:38px;padding:0 10px;border:1px solid #d1d5db;border-radius:8px;
    font-size:13px;font-family:inherit;background:#fff;color:#111827;
    appearance:auto;cursor:pointer;width:100%;
}
.native-select:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12); }

.btn {
    display:inline-flex;align-items:center;gap:6px;padding:9px 16px;
    border:none;border-radius:8px;font-size:13px;font-weight:600;
    cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap;
}
.btn-primary { background:#2563eb;color:#fff; }
.btn-primary:hover { background:#1d4ed8;transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,99,235,.35); }
.btn-primary:disabled { background:#93c5fd;cursor:not-allowed;transform:none;box-shadow:none; }
.btn-light { background:#f9fafb;color:#374151;border:1px solid #d1d5db; }
.btn-light:hover { background:#f3f4f6; }
.btn-danger { background:#dc2626;color:#fff; }
.btn-danger:hover { background:#b91c1c; }
.btn-danger:disabled { background:#fca5a5;cursor:not-allowed; }

.btn-xs {
    display:inline-flex;align-items:center;gap:4px;padding:5px 10px;
    border:none;border-radius:6px;font-size:11px;font-weight:600;
    cursor:pointer;transition:all .15s;font-family:inherit;
}
.btn-green { background:#dcfce7;color:#16a34a; }
.btn-green:hover { background:#bbf7d0; }
.btn-gray  { background:#f3f4f6;color:#6b7280; }
.btn-gray:hover  { background:#e5e7eb; }
.btn-blue  { background:#dbeafe;color:#2563eb; }
.btn-blue:hover  { background:#bfdbfe; }

/* Delete row button */
.btn-del-row {
    display:inline-flex;align-items:center;justify-content:center;
    width:30px;height:30px;border:none;border-radius:6px;
    background:#fef2f2;color:#dc2626;cursor:pointer;
    font-size:13px;transition:all .15s;
}
.btn-del-row:hover { background:#fee2e2;transform:scale(1.1); }
.btn-del-row:disabled { opacity:.35;cursor:not-allowed;transform:none; }

.time-mini {
    width:88px;padding:4px 7px;border:1px solid #d1d5db;border-radius:6px;
    font-size:12px;font-family:monospace;color:#111827;background:#fff;
    transition:border-color .15s;
}
.time-mini:focus { outline:none;border-color:#2563eb; }

.table-responsive { overflow-x:auto; }
.att-table { width:100%;border-collapse:collapse;font-size:13px; }
.att-table thead { background:#f8fafc;border-bottom:2px solid #e2e8f0; }
.att-table th {
    padding:9px 12px;text-align:left;font-weight:600;
    color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;
}
.att-table tbody tr { border-bottom:1px solid #f1f5f9;transition:background .12s; }
.att-table tbody tr:hover { background:#f8fafc; }
.att-table td { padding:7px 12px;vertical-align:middle; }

.row-attended  { background:#f0fdf4 !important; }
.row-existing  { background:#fff7ed !important; }
.row-holiday   { background:#ffedd5 !important; }
.row-leave     { background:#ede9fe !important; }
.row-sunday    { background:#fef2f2 !important; }
.row-friday    { background:#fef9c3 !important; }
.row-saturday  { background:#fce7f3 !important; }
.row-attended:hover { background:#dcfce7 !important; }
.row-existing:hover { background:#fed7aa55 !important; }
.row-holiday:hover  { background:#fed7aa88 !important; }
.row-leave:hover    { background:#ddd6fe88 !important; }

.day-badge { display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600; }
.day-badge.sunday   { background:#fee2e2;color:#dc2626; }
.day-badge.friday   { background:#fef08a;color:#854d0e; }
.day-badge.saturday { background:#fce7f3;color:#9d174d; }
.day-badge.weekday  { background:#e0f2fe;color:#0369a1; }
.day-badge.today    { background:#2563eb;color:#fff; }

.st-badge { display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600; }
.st-new      { background:#f3f4f6;color:#9ca3af; }
.st-existing { background:#fed7aa;color:#c2410c; }
.st-marked   { background:#dcfce7;color:#16a34a; }

.att-time {
    width:90px;padding:5px 8px;border:1px solid #e5e7eb;border-radius:6px;
    font-size:12px;font-family:monospace;color:#374151;background:#f9fafb;
    transition:border-color .15s;
}
.att-time:focus { outline:none;border-color:#2563eb;background:#fff;box-shadow:0 0 0 2px rgba(37,99,235,.1); }
.att-time:disabled { background:#f3f4f6;color:#9ca3af;cursor:not-allowed; }

.hours-val { font-size:12px;font-weight:700;color:#d97706;font-family:monospace; }
.att-chk { width:16px;height:16px;cursor:pointer;accent-color:#2563eb; }

.legend-item { display:flex;align-items:center;gap:6px;font-size:11px;color:#6b7280; }
.legend-dot  { width:14px;height:14px;border-radius:3px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center; }

.summary-bar {
    display:flex;gap:20px;margin-top:16px;padding:12px 16px;
    background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;flex-wrap:wrap;
}
.sum-item { display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#374151; }
.sum-dot  { width:10px;height:10px;border-radius:50%;flex-shrink:0; }
.sum-dot.green  { background:#22c55e; }
.sum-dot.orange { background:#f97316; }
.sum-dot.blue   { background:#3b82f6; }

.save-bar {
    position:sticky;bottom:16px;z-index:100;
    background:#fff;border:1px solid #e2e8f0;border-radius:12px;
    box-shadow:0 4px 20px rgba(0,0,0,.12);
    padding:14px 20px;display:flex;justify-content:space-between;align-items:center;
    flex-wrap:wrap;gap:10px;margin-top:4px;
}

@media(max-width:768px) {
    .top-selectors { flex-direction:column; }
    .selector-group { width:100% !important; }
    .save-bar { flex-direction:column; }
    .summary-bar { gap:12px; }
}

@keyframes slideInRight {
    from { opacity:0;transform:translateX(20px); }
    to   { opacity:1;transform:translateX(0); }
}
</style>

<script>
let existingMap = {};
let holidayMap  = {};
let leaveMap    = {};
let calYear = 0, calMonth = 0, calDays = 0;
let deleteTarget = null; // { empId, date }

$(document).ready(function () {
    $('#empSelect').select2({
        placeholder: '🔍 Search by ID or name…',
        allowClear: true,
        width: '100%'
    });
});

function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function leaveColor(status) {
    if (status === 'Approved') return '#16a34a';
    if (status === 'Rejected') return '#dc2626';
    return '#d97706'; // Pending
}

// ── Load Calendar ─────────────────────────────────────────────────────────────
function loadCalendar() {
    const empId = $('#empSelect').val();
    const month = parseInt(document.getElementById('selMonth').value);
    const year  = parseInt(document.getElementById('selYear').value);

    if (!empId) {
        showToast('⚠️ Please select an employee first.', 'warn');
        $('#empSelect').select2('open');
        return;
    }

    calYear = year; calMonth = month;
    const daysInMonth = new Date(year, month, 0).getDate();
    calDays = daysInMonth;

    const monthNames = ['January','February','March','April','May','June',
                        'July','August','September','October','November','December'];
    document.getElementById('calTitle').textContent = monthNames[month - 1] + ' ' + year;

    const opt = document.querySelector('#empSelect option[value="' + empId + '"]');
    document.getElementById('empInfo').textContent = opt ? opt.textContent.trim() : '';

    const card = document.getElementById('calendarCard');
    card.style.display = 'block';
    document.getElementById('attBody').innerHTML =
        '<tr><td colspan="9" style="text-align:center;padding:32px;color:#6b7280;">' +
        '<i class="fa-solid fa-spinner fa-spin" style="margin-right:8px;"></i>Loading…</td></tr>';

    document.getElementById('saveBar').style.display = 'none';
    document.getElementById('chkAll').checked = false;

    fetch('add_attendance.php?ajax_load=1&employee_id=' + empId + '&year=' + year + '&month=' + month)
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            if (!data.success && data.message) {
                showToast('❌ ' + data.message, 'error');
                return;
            }
            existingMap = data.existing || {};
            holidayMap  = data.holidays || {};
            leaveMap    = data.leaves   || {};
            buildCalendarRows(year, month, daysInMonth);
            updateSummary();
            document.getElementById('saveBar').style.display = 'flex';
            card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        })
        .catch(function (err) {
            showToast('❌ Failed to load records: ' + err.message, 'error');
            document.getElementById('attBody').innerHTML =
                '<tr><td colspan="9" style="text-align:center;padding:32px;color:#ef4444;">' +
                '<i class="fa-solid fa-triangle-exclamation" style="margin-right:8px;"></i>' +
                'Failed to load. Check your connection and try again.</td></tr>';
        });
}

// ── Build rows ─────────────────────────────────────────────────────────────────
// ALL days are fully enabled — no disabling of Sunday or any day
function buildCalendarRows(year, month, daysInMonth) {
    const tbody   = document.getElementById('attBody');
    const today   = new Date().toISOString().split('T')[0];
    const defIn   = document.getElementById('defaultIn').value  || '07:30';
    const defOut  = document.getElementById('defaultOut').value || '17:30';
    const dayFull = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

    let html = '';
    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr  = year + '-' + String(month).padStart(2,'0') + '-' + String(d).padStart(2,'0');
        const dow      = new Date(dateStr + 'T00:00:00').getDay();

        const isSunday   = (dow === 0);
        const isFriday   = (dow === 5);
        const isSaturday = (dow === 6);
        const isToday    = dateStr === today;
        const hasRecord  = Object.prototype.hasOwnProperty.call(existingMap, dateStr);

        const holidayTitle = holidayMap[dateStr] || '';
        const isHoliday     = !!holidayTitle;
        const leaveInfo      = leaveMap[dateStr] || null;

        const existIn  = hasRecord ? existingMap[dateStr].check_in  : '';
        const existOut = hasRecord ? existingMap[dateStr].check_out : '';

        // Row coloring: existing record > holiday > leave > weekend
        let rowClass = '';
        if (hasRecord) {
            rowClass = 'row-existing';
        } else if (isHoliday) {
            rowClass = 'row-holiday';
        } else if (leaveInfo) {
            rowClass = 'row-leave';
        } else if (isSunday) {
            rowClass = 'row-sunday';
        } else if (isSaturday) {
            rowClass = 'row-saturday';
        } else if (isFriday) {
            rowClass = 'row-friday';
        }

        // Day badge class
        let dayBadge = 'weekday';
        if (isToday)         dayBadge = 'today';
        else if (isSunday)   dayBadge = 'sunday';
        else if (isFriday)   dayBadge = 'friday';
        else if (isSaturday) dayBadge = 'saturday';

        const stLabel = hasRecord ? 'Existing' : 'New';
        const stClass = hasRecord ? 'st-existing' : 'st-new';

        // ALL days get default times — nothing is disabled
        const ciVal = existIn  || defIn;
        const coVal = existOut || defOut;

        // Pre-check: existing records and today auto-checked; all days are checkable
        const preChecked = (hasRecord || isToday) ? 'checked' : '';

        html += '<tr class="' + rowClass + '" id="row-' + dateStr + '" data-date="' + dateStr + '">';
        html += '<td><input type="checkbox" class="att-chk row-chk" id="chk-' + dateStr + '" data-date="' + dateStr + '" ' + preChecked + ' onchange="onRowCheck(\'' + dateStr + '\',this)"></td>';
        html += '<td><strong>' + d + '</strong><span style="font-size:11px;color:#9ca3af;margin-left:4px;">' + dateStr + '</span></td>';
        html += '<td><span class="day-badge ' + dayBadge + '">' + dayFull[dow] + '</span></td>';
        html += '<td><span class="st-badge ' + stClass + '" id="st-' + dateStr + '">' + stLabel + '</span></td>';
        html += '<td><input type="time" class="att-time" id="ci-' + dateStr + '" value="' + ciVal + '" onchange="calcHours(\'' + dateStr + '\')"></td>';
        html += '<td><input type="time" class="att-time" id="co-' + dateStr + '" value="' + coVal + '" onchange="calcHours(\'' + dateStr + '\')"></td>';
        html += '<td><span class="hours-val" id="hrs-' + dateStr + '">—</span></td>';
        html += '<td style="font-size:11px;color:#9ca3af;">';
        if (hasRecord)  html += '<i class="fa-solid fa-database" style="color:#f97316;" title="Has existing record"></i> ';
        if (isHoliday)  html += '<i class="fa-solid fa-umbrella-beach" style="color:#c2410c;" title="Holiday: ' + escapeHtml(holidayTitle) + '"></i> ';
        if (leaveInfo)  html += '<i class="fa-solid fa-plane-departure" style="color:' + leaveColor(leaveInfo.status) + ';" title="Leave: ' + escapeHtml(leaveInfo.type) + ' (' + escapeHtml(leaveInfo.status) + ')"></i> ';
        if (isToday)    html += '<i class="fa-solid fa-star" style="color:#2563eb;" title="Today"></i>';
        if (isFriday)   html += '<span style="font-size:10px;color:#92400e;font-weight:600;">FRI</span>';
        if (isSaturday) html += '<span style="font-size:10px;color:#9d174d;font-weight:600;">SAT</span>';
        if (isSunday)   html += '<span style="font-size:10px;color:#dc2626;font-weight:600;">SUN</span>';
        html += '</td>';
        // Delete button — only for rows with existing DB records
        html += '<td>';
        if (hasRecord) {
            html += '<button class="btn-del-row" id="delbtn-' + dateStr + '" title="Delete record for ' + dateStr + '" onclick="openDeleteModal(\'' + dateStr + '\')">' +
                    '<i class="fa-solid fa-trash-can"></i></button>';
        } else {
            html += '<span style="color:#e5e7eb;font-size:11px;">—</span>';
        }
        html += '</td>';
        html += '</tr>';
    }
    tbody.innerHTML = html;

    // Calculate hours and apply attended highlight for pre-checked rows
    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = year + '-' + String(month).padStart(2,'0') + '-' + String(d).padStart(2,'0');
        calcHours(dateStr);
        const chk = document.getElementById('chk-' + dateStr);
        if (chk && chk.checked) {
            const row = document.getElementById('row-' + dateStr);
            if (row) row.classList.add('row-attended');
        }
    }
}

// ── Row check ──────────────────────────────────────────────────────────────────
function onRowCheck(dateStr, chk) {
    const row = document.getElementById('row-' + dateStr);
    const ci  = document.getElementById('ci-' + dateStr);
    const co  = document.getElementById('co-' + dateStr);
    const st  = document.getElementById('st-' + dateStr);
    const isExisting = Object.prototype.hasOwnProperty.call(existingMap, dateStr);
    const dow = new Date(dateStr + 'T00:00:00').getDay();

    if (chk.checked) {
        row.classList.remove('row-existing','row-friday','row-saturday','row-sunday','row-holiday','row-leave');
        row.classList.add('row-attended');
        if (!ci.value) ci.value = document.getElementById('defaultIn').value  || '07:30';
        if (!co.value) co.value = document.getElementById('defaultOut').value || '17:30';
        st.className   = 'st-badge st-marked';
        st.textContent = isExisting ? '✓ Update' : '✓ Save';
    } else {
        row.classList.remove('row-attended');
        // NOTE: when unticked, this row will be DELETED from the DB on save
        // (see PHP ajax_save fix). Reflect that in the status badge.
        row.classList.remove('row-existing');
        if (holidayMap[dateStr]) {
            row.classList.add('row-holiday');
        } else if (leaveMap[dateStr]) {
            row.classList.add('row-leave');
        } else if (dow === 0) {
            row.classList.add('row-sunday');
        } else if (dow === 6) {
            row.classList.add('row-saturday');
        } else if (dow === 5) {
            row.classList.add('row-friday');
        }
        st.className   = 'st-badge st-new';
        st.textContent = isExisting ? '✕ Will remove' : 'New';
    }
    calcHours(dateStr);
    updateSummary();
}

// ── Mark all / clear all ───────────────────────────────────────────────────────
function markAll(state) {
    document.querySelectorAll('.row-chk').forEach(function (chk) {
        if (chk.checked !== state) {
            chk.checked = state;
            onRowCheck(chk.dataset.date, chk);
        }
    });
    document.getElementById('chkAll').checked = state;
    updateSummary();
}
function toggleAll(master) { markAll(master.checked); }

// ── Apply default times ────────────────────────────────────────────────────────
function applyDefaultTimes() {
    const defIn  = document.getElementById('defaultIn').value  || '07:30';
    const defOut = document.getElementById('defaultOut').value || '17:30';
    document.querySelectorAll('.row-chk').forEach(function (chk) {
        if (chk.checked) {
            const d = chk.dataset.date;
            document.getElementById('ci-' + d).value = defIn;
            document.getElementById('co-' + d).value = defOut;
            calcHours(d);
        }
    });
}

// ── Calculate hours ────────────────────────────────────────────────────────────
function calcHours(dateStr) {
    const ciEl = document.getElementById('ci-' + dateStr);
    const coEl = document.getElementById('co-' + dateStr);
    const hEl  = document.getElementById('hrs-' + dateStr);
    if (!ciEl || !coEl || !hEl) return;
    if (ciEl.value && coEl.value) {
        const parts1 = ciEl.value.split(':').map(Number);
        const parts2 = coEl.value.split(':').map(Number);
        const mins = (parts2[0] * 60 + parts2[1]) - (parts1[0] * 60 + parts1[1]);
        if (mins > 0) {
            hEl.textContent = Math.floor(mins / 60) + 'h ' + (mins % 60) + 'm';
            hEl.style.color = '#d97706';
        } else {
            hEl.textContent = '—';
            hEl.style.color = '#ef4444';
        }
    } else {
        hEl.textContent = '—';
        hEl.style.color = '#9ca3af';
    }
}

// ── Summary ────────────────────────────────────────────────────────────────────
function updateSummary() {
    let attended = 0, existing = 0;
    document.querySelectorAll('.row-chk').forEach(function (chk) {
        if (chk.checked) attended++;
    });
    const prefix = calYear + '-' + String(calMonth).padStart(2, '0');
    Object.keys(existingMap).forEach(function (d) {
        if (d.startsWith(prefix)) existing++;
    });
    document.getElementById('sumAttended').textContent = attended;
    document.getElementById('sumExisting').textContent = existing;
    document.getElementById('sumTotal').textContent    = calDays;
    document.getElementById('saveInfo').textContent    =
        attended + ' day(s) will be saved for ' + document.getElementById('calTitle').textContent;
}

// ── Save attendance ────────────────────────────────────────────────────────────
function saveAttendance() {
    const empId = $('#empSelect').val();
    if (!empId) { showToast('⚠️ No employee selected.', 'warn'); return; }

    const records = [];
    document.querySelectorAll('.row-chk').forEach(function (chk) {
        const d   = chk.dataset.date;
        const ci  = document.getElementById('ci-' + d);
        const co  = document.getElementById('co-' + d);
        records.push({
            date:      d,
            check_in:  ci ? ci.value : '',
            check_out: co ? co.value : '',
            attended:  chk.checked ? 1 : 0
        });
    });

    // Send ALL rows (ticked AND unticked) — unticked rows tell the server
    // to remove any existing record for that date.
    const attended = records.filter(function (r) { return r.attended; }).length;
    const toRemove = records.filter(function (r) {
        return !r.attended && Object.prototype.hasOwnProperty.call(existingMap, r.date);
    }).length;

    if (!attended && !toRemove) { showToast('⚠️ No changes to save.', 'warn'); return; }

    const btn = document.getElementById('btnSave');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('ajax_save', '1');
    fd.append('employee_id', empId);
    records.forEach(function (r, i) {
        fd.append('records[' + i + '][date]',      r.date);
        fd.append('records[' + i + '][check_in]',  r.check_in);
        fd.append('records[' + i + '][check_out]', r.check_out);
        fd.append('records[' + i + '][attended]',  r.attended);
    });

    fetch('add_attendance.php', { method: 'POST', body: fd })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Attendance';
            if (data.success) {
                showToast('✅ ' + data.message, 'success');
                document.getElementById('saveMsg').innerHTML =
                    '<span style="color:#16a34a;"><i class="fa-solid fa-circle-check"></i> ' + data.message + '</span>';
                setTimeout(function () { loadCalendar(); }, 1200);
            } else {
                showToast('❌ ' + (data.message || 'Save failed.'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Attendance';
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

// ── Delete Modal ───────────────────────────────────────────────────────────────
function openDeleteModal(dateStr) {
    const empId = $('#empSelect').val();
    if (!empId) { showToast('⚠️ No employee selected.', 'warn'); return; }
    deleteTarget = { empId: empId, date: dateStr };
    document.getElementById('deleteDateLabel').textContent = dateStr;
    const modal = document.getElementById('deleteModal');
    modal.style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
    deleteTarget = null;
}

function confirmDelete() {
    if (!deleteTarget) return;

    // Capture date BEFORE closeDeleteModal() nulls deleteTarget
    const deletedDate = deleteTarget.date;
    const deletedEmpId = deleteTarget.empId;

    const btn = document.getElementById('btnConfirmDelete');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';

    const fd = new FormData();
    fd.append('ajax_delete', '1');
    fd.append('employee_id', deletedEmpId);
    fd.append('att_date',    deletedDate);

    fetch('add_attendance.php', { method: 'POST', body: fd })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete';
            closeDeleteModal();
            if (data.success) {
                showToast('🗑️ ' + data.message, 'success');
                delete existingMap[deletedDate];
                setTimeout(function () { loadCalendar(); }, 600);
            } else {
                showToast('❌ ' + (data.message || 'Delete failed.'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete';
            closeDeleteModal();
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

// Close modal on backdrop click
document.getElementById('deleteModal').addEventListener('click', function (e) {
    if (e.target === this) closeDeleteModal();
});

// ── Reset ──────────────────────────────────────────────────────────────────────
function resetAll() {
    document.getElementById('calendarCard').style.display = 'none';
    document.getElementById('saveBar').style.display      = 'none';
    $('#empSelect').val(null).trigger('change');
    existingMap = {};
    holidayMap  = {};
    leaveMap    = {};
}

// ── Toast ──────────────────────────────────────────────────────────────────────
function showToast(msg, type) {
    const colors = { success: '#16a34a', error: '#dc2626', warn: '#d97706' };
    const t = document.createElement('div');
    t.style.cssText =
        'position:fixed;top:20px;right:20px;z-index:99999;background:#fff;' +
        'border-left:4px solid ' + (colors[type] || '#2563eb') + ';border-radius:8px;' +
        'padding:12px 18px;font-size:13px;font-weight:600;color:#111827;' +
        'box-shadow:0 8px 24px rgba(0,0,0,.15);animation:slideInRight .25s ease;max-width:380px;';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () {
        t.style.opacity    = '0';
        t.style.transition = 'opacity .3s';
        setTimeout(function () { t.remove(); }, 300);
    }, 3500);
}
</script>

<?php
if (file_exists(__DIR__ . '/footer.php')) {
    include __DIR__ . '/footer.php';
} else {
    echo '</body></html>';
}
?>