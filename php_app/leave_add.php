<?php
include 'config.php';

function getHolidayDatesForEmployee($conn, $emp_id, $from_date, $to_date) {
    $holidays = [];

    $res = mysqli_query($conn,
        "SELECT holiday_date, holiday_name FROM public_holidays
         WHERE active=1 AND holiday_date BETWEEN '$from_date' AND '$to_date'
         ORDER BY holiday_date");
    while ($r = mysqli_fetch_assoc($res))
        $holidays[$r['holiday_date']] = ['name' => $r['holiday_name'], 'type' => 'public'];

    $emp = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT e.id, e.designation_id,
                COALESCE(sc.id, 0) AS staff_category_id
         FROM employees e
         LEFT JOIN staff_categories sc ON sc.id = (
             SELECT staff_category_id FROM employees WHERE id = e.id LIMIT 1
         )
         WHERE e.id = $emp_id LIMIT 1"));

    $designation_id    = $emp ? intval($emp['designation_id'])    : 0;
    $staff_category_id = $emp ? intval($emp['staff_category_id']) : 0;

    $sh_res = mysqli_query($conn,
        "SELECT title, date_from, date_to, target_type, target_ids
         FROM special_holidays
         WHERE active=1
           AND date_from <= '$to_date'
           AND date_to   >= '$from_date'");

    while ($sh = mysqli_fetch_assoc($sh_res)) {
        $applies = false;
        if ($sh['target_type'] === 'all') {
            $applies = true;
        } else {
            $ids = json_decode($sh['target_ids'], true);
            if (is_array($ids)) {
                if ($sh['target_type'] === 'employee')       $applies = in_array($emp_id,            $ids);
                if ($sh['target_type'] === 'designation')    $applies = in_array($designation_id,    $ids);
                if ($sh['target_type'] === 'staff_category') $applies = in_array($staff_category_id, $ids);
            }
        }
        if (!$applies) continue;

        $cur = new DateTime(max($sh['date_from'], $from_date));
        $end = new DateTime(min($sh['date_to'],   $to_date));
        while ($cur <= $end) {
            $dk = $cur->format('Y-m-d');
            if (!isset($holidays[$dk]))
                $holidays[$dk] = ['name' => $sh['title'], 'type' => 'special'];
            $cur->modify('+1 day');
        }
    }

    return $holidays;
}

function countLeaveDaysExcludingHolidays($conn, $emp_id, $start, $end) {
    $holidays = getHolidayDatesForEmployee($conn, $emp_id, $start, $end);
    $cur  = new DateTime($start);
    $edt  = new DateTime($end);
    $days = 0;
    $skipped = [];
    while ($cur <= $edt) {
        $dk = $cur->format('Y-m-d');
        if (isset($holidays[$dk]))
            $skipped[$dk] = $holidays[$dk];
        else
            $days++;
        $cur->modify('+1 day');
    }
    return ['days' => $days, 'skipped' => $skipped];
}

function findEndDateForNDays($conn, $emp_id, $start, $n) {
    $cur      = new DateTime($start);
    $counted  = 0;
    $max_iter = 365;
    while ($counted < $n && $max_iter-- > 0) {
        $dk   = $cur->format('Y-m-d');
        $hols = getHolidayDatesForEmployee($conn, $emp_id, $dk, $dk);
        if (!isset($hols[$dk])) $counted++;
        if ($counted < $n) $cur->modify('+1 day');
    }
    return $cur->format('Y-m-d');
}

function hasUsedSpecialLeaveThisYear($conn, $emp_id, $year) {
    $ys = "$year-01-01";
    $ye = "$year-12-31";
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) AS c FROM leave_applications
         WHERE employee_id=$emp_id
           AND leave_type='Annual Leave'
           AND days_count >= 5
           AND start_date BETWEEN '$ys' AND '$ye'
           AND status != 'Rejected'"));
    return intval($row['c']) > 0;
}

// ─── AJAX: EPF number lookup ──────────────────────────────────────────────
if (isset($_GET['ajax_epf_lookup'])) {
    $epf_no = mysqli_real_escape_string($conn, trim($_GET['epf_no']));
    if ($epf_no === '') { echo json_encode(['error' => 'Empty EPF number']); exit; }
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT e.id, e.employee_id, e.employee_full_name, e.name_with_initials,
                e.epf_number, d.designation_name
         FROM employees e
         LEFT JOIN designations d ON e.designation_id = d.id
         WHERE e.epf_number = '$epf_no'
         LIMIT 1"));
    if (!$row) {
        echo json_encode(['error' => 'No employee found with EPF number: ' . htmlspecialchars($_GET['epf_no'])]);
        exit;
    }
    echo json_encode([
        'id'          => $row['id'],
        'employee_id' => $row['employee_id'],
        'full_name'   => $row['employee_full_name'],
        'initials'    => $row['name_with_initials'],
        'epf_number'  => $row['epf_number'],
        'designation' => $row['designation_name'] ?? '',
    ]);
    exit;
}

// ─── AJAX: employee leave balance + tenure info ───────────────────────────
if (isset($_GET['ajax_employee'])) {
    $emp_id = intval($_GET['emp_id']);
    $year   = isset($_GET['year']) && intval($_GET['year']) > 2000
                ? intval($_GET['year'])
                : (int)date('Y');

    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT date_of_join FROM employees WHERE id=$emp_id LIMIT 1"));
    if (!$row) { echo json_encode(['error' => 'Not found']); exit; }

    $join_date   = $row['date_of_join'];
    $join_ts     = strtotime($join_date);
    $join_year   = (int)date('Y', $join_ts);
    $join_month  = (int)date('n', $join_ts);

    $today   = new DateTime();
    $doj     = new DateTime($join_date);
    $interval = $today->diff($doj);
    $total_months    = ($interval->y * 12) + $interval->m;
    $is_new_employee = $total_months < 12;
    $monthly_limit   = $is_new_employee ? 2 : 4;

    $exp_parts = [];
    if ($interval->y > 0) $exp_parts[] = $interval->y . ' yr' . ($interval->y > 1 ? 's' : '');
    if ($interval->m > 0) $exp_parts[] = $interval->m . ' mo';
    if (empty($exp_parts)) $exp_parts[] = $interval->d . ' day' . ($interval->d !== 1 ? 's' : '');
    $experience_str = implode(' ', $exp_parts);

    $ae = 0; $an = '';
    if ($year == $join_year) {
        $ae = 0; $an = 'No annual leave in joining year (' . $join_year . ')';
    } elseif ($year == $join_year + 1) {
        if      ($join_month < 4)  { $ae = 14; $an = 'First year: 14 days (joined before Apr 1)'; }
        elseif  ($join_month < 7)  { $ae = 10; $an = 'First year: 10 days (joined before Jul 1)'; }
        elseif  ($join_month < 10) { $ae = 7;  $an = 'First year: 7 days (joined before Oct 1)'; }
        else                       { $ae = 4;  $an = 'First year: 4 days (joined Oct 1 or after)'; }
    } else {
        $ae = 14; $an = '14 days per year';
    }

    $ce = 7;
    $ys = "$year-01-01"; $ye = "$year-12-31";

    $ua = (int)mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(days_count),0) AS t FROM leave_applications
         WHERE employee_id=$emp_id AND leave_type='Annual Leave'
         AND start_date BETWEEN '$ys' AND '$ye' AND status!='Rejected'"))['t'];

    $uc = (int)mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(days_count),0) AS t FROM leave_applications
         WHERE employee_id=$emp_id AND leave_type='Casual Leave'
         AND start_date BETWEEN '$ys' AND '$ye' AND status!='Rejected'"))['t'];

    $annual_balance       = max(0, $ae - $ua);
    $casual_balance       = max(0, $ce - $uc);
    $special_already_used = hasUsedSpecialLeaveThisYear($conn, $emp_id, $year);
    $special_blocked_low  = ($annual_balance <= 8);
    $special_eligible     = (!$special_blocked_low) && (!$special_already_used);

    echo json_encode([
        'annual_entitlement'   => $ae,
        'annual_used'          => $ua,
        'annual_balance'       => $annual_balance,
        'annual_note'          => $an,
        'casual_entitlement'   => $ce,
        'casual_used'          => $uc,
        'casual_balance'       => $casual_balance,
        'year'                 => $year,
        'join_date'            => date('M j, Y', $join_ts),
        'experience'           => $experience_str,
        'total_months'         => $total_months,
        'is_new_employee'      => $is_new_employee,
        'monthly_limit'        => $monthly_limit,
        'special_eligible'     => $special_eligible,
        'special_blocked_low'  => $special_blocked_low,
        'special_already_used' => $special_already_used,
        'special_used_count'   => $special_already_used ? 1 : 0,
    ]);
    exit;
}

// ─── AJAX: monthly leave days used for the selected month ─────────────────
// NOTE: Medical Leave is excluded here so it never counts toward, or is
// affected by, the monthly leave-day limit that applies to other leave types.
if (isset($_GET['ajax_monthly'])) {
    $emp_id = intval($_GET['emp_id']);
    $month  = mysqli_real_escape_string($conn, $_GET['month']);
    $ms     = $month . '-01';
    $me     = date('Y-m-t', strtotime($ms));

    $days_used = (int)mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(days_count),0) AS t FROM leave_applications
         WHERE employee_id=$emp_id
         AND start_date BETWEEN '$ms' AND '$me'
         AND status!='Rejected'
         AND leave_type!='Medical Leave'"))['t'];

    echo json_encode(['used_this_month' => $days_used]);
    exit;
}

// ─── AJAX: holidays within a date range for an employee ──────────────────
if (isset($_GET['ajax_holidays'])) {
    $emp_id = intval($_GET['emp_id']);
    $start  = mysqli_real_escape_string($conn, $_GET['start']);
    $end    = mysqli_real_escape_string($conn, $_GET['end']);
    if (!$start || !$end || $end < $start) { echo json_encode(['holidays' => [], 'leave_days' => 0]); exit; }

    $hols   = getHolidayDatesForEmployee($conn, $emp_id, $start, $end);
    $result = [];
    foreach ($hols as $date => $info) {
        $result[] = [
            'date' => $date,
            'name' => $info['name'],
            'type' => $info['type'],
            'dow'  => (new DateTime($date))->format('D'),
        ];
    }
    usort($result, fn($a,$b) => strcmp($a['date'], $b['date']));

    $cur = new DateTime($start); $edt = new DateTime($end); $days = 0;
    while ($cur <= $edt) {
        if (!isset($hols[$cur->format('Y-m-d')])) $days++;
        $cur->modify('+1 day');
    }

    echo json_encode(['holidays' => $result, 'leave_days' => $days]);
    exit;
}

// ─── AJAX: find end date for exactly N leave days (skipping holidays) ─────
if (isset($_GET['ajax_special_end'])) {
    $emp_id = intval($_GET['emp_id']);
    $start  = mysqli_real_escape_string($conn, $_GET['start']);
    if (!$start) { echo json_encode(['end_date' => '', 'holidays' => [], 'skipped_count' => 0]); exit; }

    $end_date = findEndDateForNDays($conn, $emp_id, $start, 5);

    $hols     = getHolidayDatesForEmployee($conn, $emp_id, $start, $end_date);
    $hol_list = [];
    foreach ($hols as $date => $info) {
        $hol_list[] = [
            'date' => $date,
            'name' => $info['name'],
            'type' => $info['type'],
            'dow'  => (new DateTime($date))->format('D'),
        ];
    }
    usort($hol_list, fn($a,$b) => strcmp($a['date'], $b['date']));

    $cal_days = (new DateTime($start))->diff(new DateTime($end_date))->days + 1;

    echo json_encode([
        'end_date'      => $end_date,
        'holidays'      => $hol_list,
        'skipped_count' => count($hol_list),
        'leave_days'    => 5,
        'cal_days'      => $cal_days,
    ]);
    exit;
}

// ─── POST: submit leave application ──────────────────────────────────────
$success_msg = ''; $error_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_leave'])) {
    $emp_id     = intval($_POST['employee_id']);
    $leave_type = mysqli_real_escape_string($conn, trim($_POST['leave_type']));
    $start_date = mysqli_real_escape_string($conn, $_POST['start_date']);
    $end_date   = mysqli_real_escape_string($conn, $_POST['end_date']);
    $remark     = mysqli_real_escape_string($conn, trim($_POST['remark'] ?? ''));

    $is_special_leave = ($leave_type === 'Special Leave');
    $is_medical_leave = ($leave_type === 'Medical Leave');

    if ($is_special_leave) {
        $leave_type  = 'Annual Leave';
        $correct_end = findEndDateForNDays($conn, $emp_id, $start_date, 5);
        $end_date    = mysqli_real_escape_string($conn, $correct_end);
        $days_count  = 5;
    } else {
        $leave_data = countLeaveDaysExcludingHolidays($conn, $emp_id, $start_date, $end_date);
        $days_count = $leave_data['days'];
    }

    $year_check  = (int)date('Y', strtotime($start_date));
    $month_check = date('Y-m', strtotime($start_date));
    $ms_check    = $month_check . '-01';
    $me_check    = date('Y-m-t', strtotime($ms_check));
    $ys_check    = $year_check . '-01-01';
    $ye_check    = $year_check . '-12-31';

    $emp_row  = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT date_of_join FROM employees WHERE id=$emp_id LIMIT 1"));
    $doj_sv   = new DateTime($emp_row['date_of_join']);
    $today_sv = new DateTime();
    $diff_sv  = $today_sv->diff($doj_sv);
    $months_sv = ($diff_sv->y * 12) + $diff_sv->m;
    $limit_sv  = $months_sv < 12 ? 2 : 4;

    // NOTE: Medical Leave is excluded from this count so it never counts
    // toward, or is limited by, the monthly leave-day limit.
    $used_days_sv = (int)mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(days_count),0) AS t FROM leave_applications
         WHERE employee_id=$emp_id
         AND start_date BETWEEN '$ms_check' AND '$me_check'
         AND status!='Rejected'
         AND leave_type!='Medical Leave'"))['t'];

    // ── Medical Leave: no balance or monthly limit checks ─────────────────
    if (!$is_medical_leave) {

        if ($is_special_leave) {
            $join_year_sv  = (int)date('Y', strtotime($emp_row['date_of_join']));
            $join_month_sv = (int)date('n', strtotime($emp_row['date_of_join']));
            $ae_sv = 0;
            if ($year_check == $join_year_sv) {
                $ae_sv = 0;
            } elseif ($year_check == $join_year_sv + 1) {
                if      ($join_month_sv < 4)  $ae_sv = 14;
                elseif  ($join_month_sv < 7)  $ae_sv = 10;
                elseif  ($join_month_sv < 10) $ae_sv = 7;
                else                          $ae_sv = 4;
            } else {
                $ae_sv = 14;
            }

            $ua_sv = (int)mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COALESCE(SUM(days_count),0) AS t FROM leave_applications
                 WHERE employee_id=$emp_id AND leave_type='Annual Leave'
                 AND start_date BETWEEN '$ys_check' AND '$ye_check' AND status!='Rejected'"))['t'];
            $annual_bal_sv = max(0, $ae_sv - $ua_sv);

            if ($annual_bal_sv <= 8) {
                $error_msg = "Cannot submit Special Leave: annual leave balance must be more than 8 days (current: $annual_bal_sv).";
            } else {
                if (hasUsedSpecialLeaveThisYear($conn, $emp_id, $year_check)) {
                    $error_msg = "Cannot submit: a 5-day Annual Leave (Special Leave) has already been taken in $year_check.";
                }
            }

        } else {
            // ── Annual Leave balance server-side check ──
            if ($leave_type === 'Annual Leave') {
                $join_year_sv2  = (int)date('Y', strtotime($emp_row['date_of_join']));
                $join_month_sv2 = (int)date('n', strtotime($emp_row['date_of_join']));
                $ae_sv2 = 0;
                if ($year_check == $join_year_sv2) {
                    $ae_sv2 = 0;
                } elseif ($year_check == $join_year_sv2 + 1) {
                    if      ($join_month_sv2 < 4)  $ae_sv2 = 14;
                    elseif  ($join_month_sv2 < 7)  $ae_sv2 = 10;
                    elseif  ($join_month_sv2 < 10) $ae_sv2 = 7;
                    else                           $ae_sv2 = 4;
                } else {
                    $ae_sv2 = 14;
                }
                $ua_sv2 = (int)mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT COALESCE(SUM(days_count),0) AS t FROM leave_applications
                     WHERE employee_id=$emp_id AND leave_type='Annual Leave'
                     AND start_date BETWEEN '$ys_check' AND '$ye_check' AND status!='Rejected'"))['t'];
                $annual_bal_sv2 = max(0, $ae_sv2 - $ua_sv2);
                if ($days_count > $annual_bal_sv2) {
                    $error_msg = "Cannot submit: this leave requires $days_count day(s) but only $annual_bal_sv2 annual leave day(s) remain for $year_check.";
                }
            }

            // ── Casual Leave balance server-side check ──
            if (!$error_msg && $leave_type === 'Casual Leave') {
                $uc_sv = (int)mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT COALESCE(SUM(days_count),0) AS t FROM leave_applications
                     WHERE employee_id=$emp_id AND leave_type='Casual Leave'
                     AND start_date BETWEEN '$ys_check' AND '$ye_check' AND status!='Rejected'"))['t'];
                $casual_bal_sv = max(0, 7 - $uc_sv);
                if ($days_count > $casual_bal_sv) {
                    $error_msg = "Cannot submit: this leave requires $days_count day(s) but only $casual_bal_sv casual leave day(s) remain for $year_check.";
                }
            }

            // ── Monthly limit check (skipped for Medical Leave) ──
            if (!$error_msg) {
                $label    = $months_sv < 12 ? 'new employee' : 'employee';
                $mn       = date('F Y', strtotime($start_date));
                $total_if_approved = $used_days_sv + $days_count;

                if ($total_if_approved > $limit_sv) {
                    $available = $limit_sv - $used_days_sv;
                    if ($available <= 0) {
                        $error_msg = "Cannot submit: this $label has already used $used_days_sv/$limit_sv leave day(s) allowed in $mn.";
                    } else {
                        $error_msg = "Cannot submit: this leave has $days_count leave day(s) but only $available day(s) remain for $mn (limit: $limit_sv, used: $used_days_sv).";
                    }
                }
            }
        }

    } // end if (!$is_medical_leave)

    if (!$error_msg) {
        $ref = '';
        if (!empty($_FILES['reference_doc']['name']) && $_FILES['reference_doc']['error'] === UPLOAD_ERR_OK) {
            $ud = 'uploads/leaves/';
            if (!file_exists($ud)) mkdir($ud, 0777, true);
            $ext = pathinfo($_FILES['reference_doc']['name'], PATHINFO_EXTENSION);
            $p   = $ud . 'leave_' . $emp_id . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['reference_doc']['tmp_name'], $p)) $ref = $p;
        }
        $re = mysqli_real_escape_string($conn, $ref);
        mysqli_query($conn,
            "INSERT INTO leave_applications(employee_id, leave_type, start_date, end_date, days_count, remark, reference_doc)
             VALUES($emp_id, '$leave_type', '$start_date', '$end_date', $days_count, '$remark', '$re')");
        header('Location: leave_list.php?added=1');
        exit;
    }
}

// ─── Load employees ───────────────────────────────────────────────────────
$emp_result = mysqli_query($conn,
    "SELECT e.id, e.employee_id, e.name_with_initials, e.employee_full_name,
            e.date_of_join, d.designation_name
     FROM employees e
     LEFT JOIN designations d ON e.designation_id = d.id
     ORDER BY e.employee_id ASC");
$all_employees = [];
while ($r = mysqli_fetch_assoc($emp_result)) $all_employees[] = $r;

include 'header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">

<!-- Breadcrumb -->
<div class="breadcrumb">
    <a href="leave_list.php"><i class="fa-solid fa-calendar-days"></i> Leave Applications</a>
    <i class="fa-solid fa-chevron-right bc-sep"></i>
    <span>New Application</span>
</div>

<!-- Page header -->
<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title"><i class="fa-solid fa-plus-circle"></i> New Leave Application</h2>
            <p class="page-subtitle">Submit a leave request for an employee</p>
        </div>
        <a href="leave_list.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
    </div>
</div>

<!-- MAIN LAYOUT: form + holiday sidebar -->
<div class="lv-layout">

<!-- ══ LEFT: FORM CARD ══ -->
<div class="lv-main">
<div class="lv-card">
    <div class="lv-head">
        <i class="fa-solid fa-file-circle-plus"></i>
        <h3>Leave Application Form</h3>
    </div>
    <div class="lv-body">

        <?php if ($error_msg): ?>
        <div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="leaveForm">
            <input type="hidden" name="submit_leave" value="1">
            <input type="hidden" name="days_count"   id="days_count_input" value="1">

            <!-- ══ EPF Number Quick Lookup ══ -->
            <div class="lv-row" style="margin-bottom:10px;">
                <div class="lv-g" style="max-width:320px;">
                    <label class="lv-lbl">
                        <i class="fa-solid fa-id-card" style="color:#6366f1;font-size:11px;"></i>
                        EPF No. Quick Lookup
                        <span class="lv-opt">(Press Enter to find employee)</span>
                    </label>
                    <div class="epf-lookup-wrap">
                        <input
                            type="text"
                            id="epf_lookup_input"
                            class="lv-in epf-lookup-input"
                            placeholder="Type EPF number & press Enter…"
                            autocomplete="off"
                            spellcheck="false"
                        >
                        <span class="epf-lookup-spinner" id="epf_lookup_spinner" style="display:none;">
                            <i class="fa-solid fa-spinner fa-spin"></i>
                        </span>
                    </div>
                    <div id="epf_lookup_result" class="epf-lookup-result" style="display:none;"></div>
                </div>
            </div>

            <!-- Row 1: Employee + Leave Type -->
            <div class="lv-row">
                <div class="lv-g lv-wide">
                    <label class="lv-lbl">Employee <span class="req">*</span></label>
                    <select name="employee_id" id="employee_select" required>
                        <option value="">Search employee name or ID...</option>
                        <?php foreach ($all_employees as $e): ?>
                        <option value="<?php echo $e['id']; ?>">
                            <?php
                            echo htmlspecialchars($e['employee_id'] . ' — ' . ($e['name_with_initials'] ?: $e['employee_full_name']));
                            echo $e['designation_name'] ? ' (' . $e['designation_name'] . ')' : '';
                            ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lv-g">
                    <label class="lv-lbl">Leave Type <span class="req">*</span></label>
                    <select name="leave_type" id="leave_type" class="lv-in" required onchange="onLeaveTypeChange()">
                        <option value="">Select type...</option>
                        <option value="Annual Leave">Annual Leave</option>
                        <option value="Casual Leave">Casual Leave</option>
                        <option value="Medical Leave">Medical Leave</option>
                        <option value="Public Holiday Leave">Public Holiday Leave</option>
                        <option value="Special Leave" id="special_leave_option" style="display:none;">
                            ⭐ Special Leave (5 Days)
                        </option>
                    </select>
                </div>
            </div>

            <!-- Tenure Banner -->
            <div id="tenure_banner" class="tenure-banner" style="display:none;">
                <div class="tenure-inner">
                    <div class="tenure-avatar" id="tenure_avatar">
                        <i class="fa-solid fa-user"></i>
                    </div>
                    <div class="tenure-details">
                        <div class="tenure-top">
                            <span class="tenure-badge" id="tenure_badge">New Employee</span>
                            <span class="tenure-exp"   id="tenure_exp">—</span>
                        </div>
                        <div class="tenure-meta">
                            <span><i class="fa-solid fa-calendar-plus"></i> Joined: <strong id="tenure_join">—</strong></span>
                            <span class="tenure-sep">•</span>
                            <span><i class="fa-solid fa-calendar-check"></i> Monthly limit: <strong id="tenure_limit_label">—</strong> days</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Balance Banner -->
            <div id="balance_banner" class="bal-banner" style="display:none;">
                <div class="bal-title" id="bal_year_label">Year <?php echo date('Y'); ?> Leave Balances</div>
                <div class="bal-grid">
                    <div class="bal-card bal-annual">
                        <div class="bal-top">
                            <span class="bal-lbl"><i class="fa-solid fa-umbrella-beach"></i> Annual Leave</span>
                            <span class="bal-nums"><b id="bal_annual_remain">—</b> / <span id="bal_annual_total">—</span> days</span>
                        </div>
                        <div class="bal-prog"><div class="bal-fill bal-fill-a" id="bal_annual_bar"></div></div>
                        <div class="bal-note" id="bal_annual_note"></div>
                    </div>
                    <div class="bal-card bal-casual">
                        <div class="bal-top">
                            <span class="bal-lbl"><i class="fa-solid fa-person-walking"></i> Casual Leave</span>
                            <span class="bal-nums"><b id="bal_casual_remain">—</b> / 7 days</span>
                        </div>
                        <div class="bal-prog"><div class="bal-fill bal-fill-c" id="bal_casual_bar"></div></div>
                        <div class="bal-note">7 days per year</div>
                    </div>
                    <div class="bal-card bal-other">
                        <div class="bal-top">
                            <span class="bal-lbl"><i class="fa-solid fa-notes-medical"></i> Medical / Public Holiday</span>
                        </div>
                        <div class="bal-note" style="margin-top:8px;">No balance limit — apply anytime</div>
                    </div>
                </div>
                <div id="special_leave_banner" style="display:none; margin-top:12px;"></div>
                <div id="monthly_status" class="monthly-status" style="display:none;"></div>
            </div>

            <!-- Medical Leave Info Box -->
            <div id="medical_leave_info" class="medical-info-box" style="display:none;">
                <div class="medical-info-inner">
                    <div class="medical-info-icon"><i class="fa-solid fa-notes-medical"></i></div>
                    <div class="medical-info-text">
                        <strong>Medical Leave — No Balance or Monthly Limit Restrictions</strong>
                        <p>Medical Leave has no annual balance deduction and is not subject to monthly leave day limits. Submit for any number of days without restriction.</p>
                        <ul>
                            <li><i class="fa-solid fa-check"></i> No annual leave balance deducted</li>
                            <li><i class="fa-solid fa-check"></i> No monthly leave day limit applies</li>
                            <li><i class="fa-solid fa-check"></i> Any date range is allowed</li>
                            <li><i class="fa-solid fa-file-medical"></i> Attach a medical certificate as reference if available</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Special Leave Info Box -->
            <div id="special_leave_info" class="special-info-box" style="display:none;">
                <div class="special-info-inner">
                    <div class="special-info-icon"><i class="fa-solid fa-star"></i></div>
                    <div class="special-info-text">
                        <strong>Special Leave — 5 Days deducted from Annual Leave (Holidays Auto-Skipped)</strong>
                        <p>The end date is auto-calculated to cover exactly 5 <em>non-holiday</em> leave days from your start date. This leave is saved as Annual Leave and deducted from the annual balance. Public holidays and special holidays within the range are automatically skipped.</p>
                        <ul>
                            <li><i class="fa-solid fa-check"></i> Requires annual leave balance of <strong>more than 8 days</strong></li>
                            <li><i class="fa-solid fa-check"></i> Allowed <strong>once per year only</strong></li>
                            <li><i class="fa-solid fa-check"></i> Covers exactly <strong>5 non-holiday days</strong></li>
                            <li><i class="fa-solid fa-check"></i> Saved as <strong>Annual Leave</strong> — deducted from annual balance</li>
                            <li><i class="fa-solid fa-calendar-xmark"></i> Holidays within range are <strong>skipped automatically</strong></li>
                            <li><i class="fa-solid fa-ban"></i> Not available if annual balance is <strong>8 days or below</strong></li>
                            <li><i class="fa-solid fa-ban"></i> Not available if a <strong>5-day Annual Leave already taken</strong> this year</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Row 2: Dates -->
            <div class="lv-row">
                <div class="lv-g">
                    <label class="lv-lbl">Start Date <span class="req">*</span></label>
                    <input type="date" name="start_date" id="start_date" class="lv-in" required onchange="onDateChange()">
                </div>
                <div class="lv-g">
                    <label class="lv-lbl">End Date <span class="req">*</span>
                        <span id="end_date_locked_label" style="display:none;" class="lv-opt locked-label">
                            <i class="fa-solid fa-lock"></i> Auto-set (5 leave days)
                        </span>
                    </label>
                    <input type="date" name="end_date" id="end_date" class="lv-in" required onchange="onDateChange()">
                </div>
                <div class="lv-g" style="flex:0 0 130px;min-width:110px;">
                    <label class="lv-lbl">Leave Days</label>
                    <div class="days-box" id="days_display">—</div>
                </div>
            </div>

            <!-- Row 3: Remark + Doc -->
            <div class="lv-row">
                <div class="lv-g lv-wide">
                    <label class="lv-lbl">Remark <span class="lv-opt">(Optional)</span></label>
                    <input type="text" name="remark" class="lv-in" placeholder="Enter reason or note...">
                </div>
                <div class="lv-g">
                    <label class="lv-lbl">Reference Document <span class="lv-opt">(Optional)</span></label>
                    <input type="file" name="reference_doc" class="lv-in" accept=".pdf,.jpg,.jpeg,.png">
                </div>
            </div>

            <!-- Actions -->
            <div style="display:flex;gap:10px;margin-top:8px;flex-wrap:wrap;align-items:center;">
                <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                    <i class="fa-solid fa-paper-plane"></i> Submit Application
                </button>
                <button type="button" class="btn btn-ghost" onclick="resetForm()">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </button>
                <a href="leave_list.php" class="btn btn-ghost">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </a>
                <span id="submit_block_reason" class="submit-block-msg" style="display:none;"></span>
            </div>
        </form>
    </div>
</div>
</div><!-- end lv-main -->

<!-- ══ RIGHT: HOLIDAY SIDEBAR ══ -->
<div class="lv-sidebar" id="holiday_sidebar">
    <div class="hol-panel">
        <div class="hol-panel-head">
            <i class="fa-solid fa-calendar-xmark"></i>
            <span>Holidays in Range</span>
            <span class="hol-count-badge" id="hol_count_badge" style="display:none;">0</span>
        </div>
        <div id="hol_panel_body" class="hol-panel-body">
            <div class="hol-empty">
                <i class="fa-solid fa-calendar-days"></i>
                <p>Select an employee and date range to see holidays</p>
            </div>
        </div>
    </div>
</div><!-- end lv-sidebar -->

</div><!-- end lv-layout -->

<!-- ═══ STYLES ═══════════════════════════════════════════════════ -->
<style>
*{box-sizing:border-box;}

.breadcrumb{display:flex;align-items:center;gap:8px;font-size:12px;margin-bottom:14px;color:#888;}
.breadcrumb a{color:#3b82f6;text-decoration:none;display:flex;align-items:center;gap:5px;}
.breadcrumb a:hover{text-decoration:underline;}
.bc-sep{font-size:10px;color:#ccc;}
.page-header{margin-bottom:18px;}
.page-title{font-size:22px;font-weight:700;margin:0 0 4px;color:#111;display:flex;align-items:center;gap:10px;}
.page-title i{color:#3b82f6;}
.page-subtitle{font-size:13px;color:#666;margin:0;}

.lv-layout{display:flex;gap:18px;align-items:flex-start;}
.lv-main{flex:1;min-width:0;}
.lv-sidebar{width:280px;flex-shrink:0;position:sticky;top:16px;}

.lv-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 1px 8px rgba(0,0,0,.06);}
.lv-head{display:flex;align-items:center;gap:10px;padding:14px 20px;border-bottom:1px solid #f0f0f0;background:#fafafa;}
.lv-head i{color:#3b82f6;font-size:16px;}
.lv-head h3{font-size:14px;font-weight:700;margin:0;color:#111;}
.lv-body{padding:22px 20px;}

.lv-row{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:16px;}
.lv-g{display:flex;flex-direction:column;gap:5px;flex:1;min-width:180px;}
.lv-wide{flex:2;}
.lv-lbl{font-size:11px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.4px;}
.lv-opt{font-size:10px;font-weight:400;color:#aaa;text-transform:none;letter-spacing:0;}
.locked-label{color:#d97706 !important;font-weight:600;}
.lv-in,.lv-g select{padding:10px 13px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;color:#111;background:#fff;transition:border .2s;width:100%;}
.lv-in:focus,.lv-g select:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px #3b82f614;}
.lv-in[readonly]{background:#fafaf0;border-color:#fde68a;color:#92400e;cursor:not-allowed;}
.req{color:#ef4444;}
.days-box{height:44px;border-radius:8px;border:1.5px solid #e5e7eb;background:#f0f7ff;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:900;color:#3b82f6;}
.days-box.is-special{background:linear-gradient(135deg,#fefce8,#fef3c7);border-color:#fbbf24;color:#d97706;}
.days-box.is-medical{background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-color:#86efac;color:#166534;}

/* ── EPF Lookup ── */
.epf-lookup-wrap{position:relative;display:flex;align-items:center;}
.epf-lookup-input{padding-right:36px !important;}
.epf-lookup-input:focus{border-color:#6366f1 !important;box-shadow:0 0 0 3px #6366f114 !important;}
.epf-lookup-spinner{position:absolute;right:11px;color:#6366f1;font-size:13px;pointer-events:none;}
.epf-lookup-result{margin-top:6px;border-radius:8px;font-size:12px;padding:9px 12px;display:flex;align-items:center;gap:8px;animation:fadeInDown .2s ease;}
.epf-lookup-result.epf-res-ok{background:#f0fdf4;border:1.5px solid #bbf7d0;color:#166534;}
.epf-lookup-result.epf-res-ok i{color:#22c55e;font-size:14px;flex-shrink:0;}
.epf-lookup-result.epf-res-err{background:#fef2f2;border:1.5px solid #fecaca;color:#991b1b;}
.epf-lookup-result.epf-res-err i{color:#ef4444;font-size:14px;flex-shrink:0;}
.epf-res-text strong{display:block;font-weight:700;font-size:12px;margin-bottom:1px;}
.epf-res-text span{font-size:11px;opacity:.8;}
@keyframes fadeInDown{from{opacity:0;transform:translateY(-5px);}to{opacity:1;transform:translateY(0);}}

.tenure-banner{background:linear-gradient(135deg,#f0f7ff 0%,#fafafa 100%);border:1.5px solid #dbeafe;border-radius:10px;padding:14px 16px;margin-bottom:12px;}
.tenure-banner.is-new{background:linear-gradient(135deg,#fff7ed 0%,#fafafa 100%);border-color:#fed7aa;}
.tenure-inner{display:flex;align-items:center;gap:14px;}
.tenure-avatar{width:44px;height:44px;border-radius:50%;background:#dbeafe;color:#3b82f6;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;border:2px solid #bfdbfe;}
.tenure-banner.is-new .tenure-avatar{background:#fed7aa;color:#c2410c;border-color:#fdba74;}
.tenure-details{flex:1;min-width:0;}
.tenure-top{display:flex;align-items:center;gap:10px;margin-bottom:5px;flex-wrap:wrap;}
.tenure-badge{padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:#dbeafe;color:#1e40af;}
.tenure-banner.is-new .tenure-badge{background:#fed7aa;color:#92400e;}
.tenure-exp{font-size:13px;font-weight:700;color:#111;}
.tenure-meta{display:flex;align-items:center;gap:8px;font-size:12px;color:#6b7280;flex-wrap:wrap;}
.tenure-meta i{font-size:11px;color:#9ca3af;}
.tenure-meta strong{color:#374151;}
.tenure-sep{color:#d1d5db;}

.bal-banner{background:#f8faff;border:1.5px solid #dbeafe;border-radius:10px;padding:14px 16px;margin-bottom:16px;}
.bal-title{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;}
.bal-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px;}
.bal-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;}
.bal-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;}
.bal-lbl{font-size:11px;font-weight:700;color:#555;display:flex;align-items:center;gap:5px;}
.bal-nums{font-size:12px;color:#555;}
.bal-nums b{font-size:20px;font-weight:900;}
.bal-annual .bal-nums b{color:#3b82f6;}
.bal-casual .bal-nums b{color:#8b5cf6;}
.bal-prog{height:4px;background:#f0f0f0;border-radius:4px;overflow:hidden;margin-bottom:4px;}
.bal-fill{height:100%;border-radius:4px;transition:width .5s;width:0%;}
.bal-fill-a{background:#3b82f6;}
.bal-fill-c{background:#8b5cf6;}
.bal-note{font-size:10px;color:#9ca3af;}

.special-elig-box{display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;font-size:13px;font-weight:600;}
.special-elig-box.elig-ok{background:linear-gradient(135deg,#fefce8,#fef9c3);border:1.5px solid #fbbf24;color:#92400e;}
.special-elig-box.elig-ok i.star{color:#f59e0b;font-size:16px;}
.special-elig-box.elig-used{background:#f0fdf4;border:1.5px solid #bbf7d0;color:#166534;}
.special-elig-box.elig-used i{color:#22c55e;}
.special-elig-box.elig-low{background:#fef2f2;border:1.5px solid #fecaca;color:#991b1b;}
.special-elig-box.elig-low i{color:#ef4444;}
.special-elig-detail{flex:1;}
.special-elig-detail .elig-title{font-size:12px;font-weight:800;margin-bottom:2px;}
.special-elig-detail .elig-sub{font-size:11px;font-weight:400;opacity:.8;}

/* ── Medical Leave Info Box ── */
.medical-info-box{background:linear-gradient(135deg,#f0fdf4 0%,#f7fffe 100%);border:1.5px solid #86efac;border-radius:10px;padding:14px 16px;margin-bottom:16px;animation:fadeInDown .3s ease;}
.medical-info-inner{display:flex;gap:14px;align-items:flex-start;}
.medical-info-icon{width:40px;height:40px;border-radius:50%;background:#dcfce7;color:#16a34a;border:2px solid #86efac;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.medical-info-text strong{font-size:13px;color:#14532d;display:block;margin-bottom:5px;}
.medical-info-text p{font-size:12px;color:#166534;margin:0 0 8px;}
.medical-info-text ul{margin:0;padding:0 0 0 2px;list-style:none;display:flex;flex-direction:column;gap:4px;}
.medical-info-text ul li{font-size:11px;color:#166534;display:flex;align-items:center;gap:6px;}
.medical-info-text ul li i{width:12px;flex-shrink:0;}
.medical-info-text ul li i.fa-check{color:#16a34a;}
.medical-info-text ul li i.fa-file-medical{color:#0891b2;}

.special-info-box{background:linear-gradient(135deg,#fefce8 0%,#fffbeb 100%);border:1.5px solid #fbbf24;border-radius:10px;padding:14px 16px;margin-bottom:16px;animation:fadeInDown .3s ease;}
.special-info-inner{display:flex;gap:14px;align-items:flex-start;}
.special-info-icon{width:40px;height:40px;border-radius:50%;background:#fef3c7;color:#d97706;border:2px solid #fbbf24;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.special-info-text strong{font-size:13px;color:#92400e;display:block;margin-bottom:5px;}
.special-info-text p{font-size:12px;color:#78350f;margin:0 0 8px;}
.special-info-text ul{margin:0;padding:0 0 0 2px;list-style:none;display:flex;flex-direction:column;gap:4px;}
.special-info-text ul li{font-size:11px;color:#78350f;display:flex;align-items:center;gap:6px;}
.special-info-text ul li i{width:12px;flex-shrink:0;}
.special-info-text ul li i.fa-check{color:#16a34a;}
.special-info-text ul li i.fa-ban{color:#dc2626;}
.special-info-text ul li i.fa-calendar-xmark{color:#d97706;}

.monthly-status{display:flex;align-items:center;gap:10px;margin-top:12px;padding:11px 14px;border-radius:8px;font-size:13px;font-weight:600;}
.monthly-status i{font-size:15px;flex-shrink:0;}
.monthly-status.status-ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;}
.monthly-status.status-ok i{color:#22c55e;}
.monthly-status.status-warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;}
.monthly-status.status-warn i{color:#f97316;}
.monthly-status.status-blocked{background:#fef2f2;border:1.5px solid #fecaca;color:#991b1b;}
.monthly-status.status-blocked i{color:#ef4444;}

.submit-block-msg{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#dc2626;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:6px 12px;}

.alert{display:flex;align-items:center;gap:9px;padding:11px 14px;border-radius:8px;font-size:13px;margin-bottom:16px;}
.alert-danger{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}

.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none;font-family:inherit;}
.btn:disabled{opacity:.4;cursor:not-allowed;}
.btn-primary{background:#111;color:#fff;}
.btn-primary:not(:disabled):hover{background:#333;}
.btn-ghost{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}
.btn-ghost:hover{background:#e9e9e9;}

.hol-panel{background:#fff;border:1.5px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 1px 8px rgba(0,0,0,.05);}
.hol-panel-head{display:flex;align-items:center;gap:8px;padding:12px 16px;background:#fafafa;border-bottom:1px solid #e5e7eb;font-size:13px;font-weight:700;color:#374151;}
.hol-panel-head i{color:#ef4444;font-size:15px;}
.hol-count-badge{margin-left:auto;background:#ef4444;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;}
.hol-panel-body{padding:10px;max-height:560px;overflow-y:auto;}
.hol-empty{text-align:center;padding:30px 16px;color:#9ca3af;}
.hol-empty i{font-size:32px;color:#e5e7eb;display:block;margin-bottom:8px;}
.hol-empty p{font-size:12px;margin:0;}

.hol-item{display:flex;align-items:flex-start;gap:10px;padding:9px 10px;border-radius:8px;margin-bottom:6px;animation:fadeIn .2s ease;}
@keyframes fadeIn{from{opacity:0;transform:translateX(6px);}to{opacity:1;transform:translateX(0);}}
.hol-item.hol-public{background:#fef2f2;border:1px solid #fecaca;}
.hol-item.hol-special{background:#fff7ed;border:1px solid #fed7aa;}
.hol-item-date{flex-shrink:0;text-align:center;background:#fff;border-radius:6px;padding:4px 8px;min-width:52px;border:1px solid #e5e7eb;}
.hol-item-date .hol-day{font-size:18px;font-weight:900;line-height:1;color:#111;}
.hol-item-date .hol-mon{font-size:9px;font-weight:700;text-transform:uppercase;color:#9ca3af;letter-spacing:.4px;}
.hol-item-date .hol-dow{font-size:9px;font-weight:600;color:#6b7280;margin-top:1px;}
.hol-item-info{flex:1;min-width:0;}
.hol-item-name{font-size:12px;font-weight:700;color:#111;margin-bottom:3px;line-height:1.3;}
.hol-item-badge{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;}
.hol-badge-public{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.hol-badge-special{background:#fff7ed;color:#92400e;border:1px solid #fed7aa;}

.hol-summary{display:flex;gap:6px;padding:8px 10px 2px;flex-wrap:wrap;}
.hol-sum-chip{display:flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:4px 10px;border-radius:6px;}
.hol-sum-chip.chip-range{background:#f0f7ff;color:#1e40af;border:1px solid #bfdbfe;}
.hol-sum-chip.chip-skip{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.hol-sum-chip.chip-leave{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}

.hol-none-msg{display:flex;flex-direction:column;align-items:center;padding:20px 12px;text-align:center;color:#166534;}
.hol-none-msg i{font-size:28px;color:#22c55e;margin-bottom:8px;}
.hol-none-msg strong{font-size:13px;font-weight:700;margin-bottom:3px;}
.hol-none-msg span{font-size:11px;color:#6b7280;}

.hol-loading{display:flex;align-items:center;justify-content:center;gap:8px;padding:24px;color:#9ca3af;font-size:13px;}
.hol-loading i{font-size:18px;color:#3b82f6;}

.select2-container .select2-selection--single{height:44px!important;border:1.5px solid #e5e7eb!important;border-radius:8px!important;}
.select2-container .select2-selection--single .select2-selection__rendered{line-height:44px!important;padding-left:13px!important;font-size:13px!important;color:#111!important;}
.select2-container .select2-selection--single .select2-selection__arrow{height:42px!important;}
.select2-container--open .select2-selection--single{border-color:#3b82f6!important;}
.select2-dropdown{border:1.5px solid #e5e7eb!important;border-radius:8px!important;box-shadow:0 4px 20px rgba(0,0,0,.1)!important;font-size:13px!important;}
.select2-results__option--highlighted{background:#3b82f6!important;}

@media(max-width:900px){
    .lv-layout{flex-direction:column;}
    .lv-sidebar{width:100%;position:static;}
    .hol-panel-body{max-height:300px;}
}
@media(max-width:680px){
    .lv-row{flex-direction:column;}
    .lv-wide{flex:1;}
    .bal-grid{grid-template-columns:1fr;}
    .tenure-inner{flex-wrap:wrap;}
    .special-info-inner{flex-wrap:wrap;}
    .medical-info-inner{flex-wrap:wrap;}
}
</style>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(function () {
    $('#employee_select').select2({ placeholder: 'Search by name or employee ID...', allowClear: true, width: '100%' });
    $('#employee_select').on('change', function () {
        const v = $(this).val();
        if (!v) { resetBal(); return; }
        loadBal(v);
    });
});

let _eid                = null;
let _lastBalYear        = null;
let _isNewEmployee      = false;
let _monthlyLimit       = 2;
let _monthlyUsed        = 0;
let _monthChecked       = null;
let _specialEligible    = false;
let _specialBlockedLow  = false;
let _specialAlreadyUsed = false;
let _annualBalance      = 0;
let _casualBalance      = 0;
let _holidayCheckXhr    = null;
let _currentLeaveDays   = 0;

const MONTHS_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

function getSelectedYear()  { const sd = document.getElementById('start_date').value; return sd ? sd.slice(0,4) : new Date().getFullYear().toString(); }
function getSelectedMonth() { const sd = document.getElementById('start_date').value; return sd ? sd.slice(0,7) : null; }
function isSpecialMode()    { return document.getElementById('leave_type').value === 'Special Leave'; }
function isMedicalMode()    { return document.getElementById('leave_type').value === 'Medical Leave'; }

function fmtDate(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    return d.getDate() + ' ' + MONTHS_SHORT[d.getMonth()] + ' ' + d.getFullYear();
}

// ══ EPF Number Quick Lookup ══
document.getElementById('epf_lookup_input').addEventListener('keydown', function(e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const epfNo = this.value.trim();
    if (!epfNo) return;

    const spinner = document.getElementById('epf_lookup_spinner');
    const result  = document.getElementById('epf_lookup_result');
    spinner.style.display = 'inline';
    result.style.display  = 'none';

    fetch('leave_add.php?ajax_epf_lookup=1&epf_no=' + encodeURIComponent(epfNo))
        .then(r => r.json())
        .then(d => {
            spinner.style.display = 'none';
            if (d.error) {
                result.className   = 'epf-lookup-result epf-res-err';
                result.style.display = 'flex';
                result.innerHTML   = `<i class="fa-solid fa-circle-xmark"></i>
                    <div class="epf-res-text">
                        <strong>Not Found</strong>
                        <span>${d.error}</span>
                    </div>`;
                return;
            }
            const displayName = d.employee_id + ' — ' + (d.initials || d.full_name)
                              + (d.designation ? ' (' + d.designation + ')' : '');
            const sel = $('#employee_select');
            if (sel.find('option[value="' + d.id + '"]').length === 0) {
                sel.append(new Option(displayName, d.id, true, true));
            }
            sel.val(d.id).trigger('change');

            result.className     = 'epf-lookup-result epf-res-ok';
            result.style.display = 'flex';
            result.innerHTML     = `<i class="fa-solid fa-circle-check"></i>
                <div class="epf-res-text">
                    <strong>${d.full_name}</strong>
                    <span>EPF: ${d.epf_number} &nbsp;·&nbsp; ID: ${d.employee_id}${d.designation ? ' &nbsp;·&nbsp; ' + d.designation : ''}</span>
                </div>`;
        })
        .catch(() => {
            spinner.style.display = 'none';
            result.className      = 'epf-lookup-result epf-res-err';
            result.style.display  = 'flex';
            result.innerHTML      = `<i class="fa-solid fa-circle-xmark"></i>
                <div class="epf-res-text"><strong>Error</strong><span>Could not reach server. Please try again.</span></div>`;
        });
});

document.getElementById('epf_lookup_input').addEventListener('input', function() {
    document.getElementById('epf_lookup_result').style.display = 'none';
});

function loadBal(id, forceYear) {
    _eid = id;
    const yr = forceYear || getSelectedYear();
    fetch('leave_add.php?ajax_employee=1&emp_id=' + id + '&year=' + yr)
        .then(r => r.json())
        .then(d => {
            _lastBalYear        = d.year;
            _isNewEmployee      = d.is_new_employee;
            _monthlyLimit       = d.monthly_limit;
            _annualBalance      = d.annual_balance;
            _casualBalance      = d.casual_balance;
            _specialEligible    = d.special_eligible;
            _specialBlockedLow  = d.special_blocked_low;
            _specialAlreadyUsed = d.special_already_used;

            const tb = document.getElementById('tenure_banner');
            tb.style.display = 'block';
            tb.className     = 'tenure-banner' + (d.is_new_employee ? ' is-new' : '');
            document.getElementById('tenure_badge').textContent        = d.is_new_employee ? 'New Employee (< 1 year)' : 'Experienced Employee (1+ year)';
            document.getElementById('tenure_exp').textContent          = d.experience + ' experience';
            document.getElementById('tenure_join').textContent         = d.join_date;
            document.getElementById('tenure_limit_label').textContent  = d.monthly_limit;

            document.getElementById('balance_banner').style.display   = 'block';
            document.getElementById('bal_year_label').textContent      = 'Year ' + d.year + ' Leave Balances';
            document.getElementById('bal_annual_remain').textContent   = d.annual_balance;
            document.getElementById('bal_annual_total').textContent    = d.annual_entitlement;
            document.getElementById('bal_annual_note').textContent     = d.annual_note;
            const ap = d.annual_entitlement > 0 ? Math.round(d.annual_balance / d.annual_entitlement * 100) : 0;
            document.getElementById('bal_annual_bar').style.width      = ap + '%';
            if (d.annual_entitlement === 0) {
                document.getElementById('bal_annual_remain').textContent = '—';
                document.getElementById('bal_annual_total').textContent  = '—';
            }
            document.getElementById('bal_casual_remain').textContent   = d.casual_balance;
            document.getElementById('bal_casual_bar').style.width      = Math.round(d.casual_balance / 7 * 100) + '%';

            renderSpecialEligBanner(d);

            // ── Annual Leave option: hide if balance is 0 ──────────────────
            const annualOpt = document.querySelector('#leave_type option[value="Annual Leave"]');
            if (annualOpt) {
                if (d.annual_balance <= 0) {
                    annualOpt.style.display = 'none';
                    annualOpt.disabled = true;
                    if (document.getElementById('leave_type').value === 'Annual Leave') {
                        document.getElementById('leave_type').value = '';
                        onLeaveTypeChange();
                    }
                } else {
                    annualOpt.style.display = '';
                    annualOpt.disabled = false;
                }
            }

            // ── Casual Leave option: hide if balance is 0 ─────────────────
            const casualOpt = document.querySelector('#leave_type option[value="Casual Leave"]');
            if (casualOpt) {
                if (d.casual_balance <= 0) {
                    casualOpt.style.display = 'none';
                    casualOpt.disabled = true;
                    if (document.getElementById('leave_type').value === 'Casual Leave') {
                        document.getElementById('leave_type').value = '';
                        onLeaveTypeChange();
                    }
                } else {
                    casualOpt.style.display = '';
                    casualOpt.disabled = false;
                }
            }

            // ── Special Leave option ───────────────────────────────────────
            const opt = document.getElementById('special_leave_option');
            if (d.special_eligible) {
                opt.style.display = '';
            } else {
                opt.style.display = 'none';
                if (isSpecialMode()) {
                    document.getElementById('leave_type').value = '';
                    onLeaveTypeChange();
                }
            }

            recheckDates();
        });
}

function renderSpecialEligBanner(d) {
    const box = document.getElementById('special_leave_banner');
    if (d.special_blocked_low) {
        box.style.display = 'block';
        box.innerHTML = `<div class="special-elig-box elig-low">
            <i class="fa-solid fa-circle-xmark"></i>
            <div class="special-elig-detail">
                <div class="elig-title">Special Leave Not Available</div>
                <div class="elig-sub">Annual balance is <strong>${d.annual_balance} days</strong> — must be more than 8 days to qualify.</div>
            </div></div>`;
    } else if (d.special_already_used) {
        box.style.display = 'block';
        box.innerHTML = `<div class="special-elig-box elig-used">
            <i class="fa-solid fa-circle-check"></i>
            <div class="special-elig-detail">
                <div class="elig-title">Special Leave Already Used This Year</div>
                <div class="elig-sub">A 5-day Annual Leave has already been taken in ${d.year}. Only one per year is allowed.</div>
            </div></div>`;
    } else if (d.special_eligible) {
        box.style.display = 'block';
        box.innerHTML = `<div class="special-elig-box elig-ok">
            <i class="fa-solid fa-star star"></i>
            <div class="special-elig-detail">
                <div class="elig-title">⭐ Special Leave Available!</div>
                <div class="elig-sub">Annual balance is <strong>${d.annual_balance} days</strong> (more than 8). Can apply for one 5-day Special Leave this year.</div>
            </div></div>`;
    } else {
        box.style.display = 'none';
    }
}

function onLeaveTypeChange() {
    const infoBox        = document.getElementById('special_leave_info');
    const medicalInfoBox = document.getElementById('medical_leave_info');
    const endInput       = document.getElementById('end_date');
    const endLockLabel   = document.getElementById('end_date_locked_label');
    const daysBox        = document.getElementById('days_display');
    const monthlyStatus  = document.getElementById('monthly_status');

    // Hide both info boxes first
    infoBox.style.display        = 'none';
    medicalInfoBox.style.display = 'none';

    if (isSpecialMode()) {
        infoBox.style.display      = 'block';
        endInput.readOnly          = true;
        endLockLabel.style.display = 'inline';
        daysBox.classList.add('is-special');
        daysBox.classList.remove('is-medical');
        monthlyStatus.style.display = 'none'; // no monthly status for special
        const sd = document.getElementById('start_date').value;
        if (sd && _eid) {
            loadSpecialEndDate(sd);
        } else {
            daysBox.textContent = '5 days';
            document.getElementById('days_count_input').value = 5;
        }
    } else if (isMedicalMode()) {
        medicalInfoBox.style.display = 'block';
        endInput.readOnly            = false;
        endLockLabel.style.display   = 'none';
        daysBox.classList.remove('is-special');
        daysBox.classList.add('is-medical');
        monthlyStatus.style.display = 'none'; // no monthly status for medical
        recheckDates();
    } else {
        endInput.readOnly          = false;
        endLockLabel.style.display = 'none';
        daysBox.classList.remove('is-special');
        daysBox.classList.remove('is-medical');
        recheckDates();
    }
    validateForm();
}

function loadSpecialEndDate(startVal) {
    if (!_eid || !startVal) return;
    showHolidayLoading();
    fetch(`leave_add.php?ajax_special_end=1&emp_id=${_eid}&start=${startVal}`)
        .then(r => r.json())
        .then(d => {
            document.getElementById('end_date').value             = d.end_date;
            document.getElementById('days_count_input').value     = 5;
            document.getElementById('days_display').textContent   = '5 days';
            _currentLeaveDays = 5;
            renderHolidaySidebar(d.holidays, startVal, d.end_date, d.leave_days, d.cal_days);
            checkMonthly();
        });
}

function onDateChange() {
    const s = document.getElementById('start_date').value;
    const e = document.getElementById('end_date').value;

    if (s) document.getElementById('end_date').min = s;

    if (isSpecialMode()) {
        if (s && _eid) {
            loadSpecialEndDate(s);
        } else if (s) {
            document.getElementById('days_display').textContent = '5 days';
            document.getElementById('days_count_input').value   = 5;
        }
        if (_eid) {
            const yr = getSelectedYear();
            if (yr !== String(_lastBalYear)) loadBal(_eid, yr);
            else validateForm();
        }
        return;
    }

    if (s && e) {
        const sd = new Date(s + 'T00:00:00');
        const ed = new Date(e + 'T00:00:00');
        if (ed < sd) { document.getElementById('end_date').value = s; onDateChange(); return; }

        if (_eid) {
            loadHolidaysAndCount(s, e);
        } else {
            const diff    = Math.round((ed - sd) / 86400000);
            const calDays = Math.max(1, diff + 1);
            document.getElementById('days_display').textContent   = calDays + (calDays === 1 ? ' day' : ' days');
            document.getElementById('days_count_input').value     = calDays;
            _currentLeaveDays = calDays;
        }
    } else {
        document.getElementById('days_display').textContent = '—';
        document.getElementById('days_count_input').value   = 1;
        _currentLeaveDays = 0;
        clearHolidaySidebar();
    }

    if (_eid) {
        const yr = getSelectedYear();
        if (yr !== String(_lastBalYear)) loadBal(_eid, yr);
        else validateForm();
    }
}

function loadHolidaysAndCount(s, e) {
    if (!_eid || !s || !e) return;
    showHolidayLoading();
    if (_holidayCheckXhr) clearTimeout(_holidayCheckXhr);
    _holidayCheckXhr = setTimeout(() => {
        fetch(`leave_add.php?ajax_holidays=1&emp_id=${_eid}&start=${s}&end=${e}`)
            .then(r => r.json())
            .then(d => {
                _currentLeaveDays = d.leave_days;
                document.getElementById('days_count_input').value   = d.leave_days;
                document.getElementById('days_display').textContent = d.leave_days + (d.leave_days === 1 ? ' day' : ' days');

                const calDays = Math.round((new Date(e+'T00:00:00') - new Date(s+'T00:00:00')) / 86400000) + 1;
                renderHolidaySidebar(d.holidays, s, e, d.leave_days, calDays);

                // Only check monthly if NOT medical leave
                if (!isMedicalMode()) {
                    const month = s.slice(0,7);
                    if (month !== _monthChecked) {
                        checkMonthlyWithValue(month);
                    } else {
                        validateForm();
                    }
                } else {
                    validateForm();
                }
            });
    }, 300);
}

function recheckDates() {
    const s = document.getElementById('start_date').value;
    const e = document.getElementById('end_date').value;
    if (s && e && _eid && !isSpecialMode()) loadHolidaysAndCount(s, e);
    else if (s && e && !_eid && !isSpecialMode()) onDateChange();
}

function checkMonthly() {
    const sd = document.getElementById('start_date').value;
    if (!_eid || !sd) { validateForm(); return; }
    // Skip monthly check entirely for medical leave
    if (isMedicalMode()) { validateForm(); return; }
    checkMonthlyWithValue(sd.slice(0,7));
}

function checkMonthlyWithValue(month) {
    _monthChecked = month;
    // Skip monthly check entirely for special or medical leave
    if (isSpecialMode() || isMedicalMode()) { validateForm(); return; }
    fetch('leave_add.php?ajax_monthly=1&emp_id=' + _eid + '&month=' + month)
        .then(r => r.json())
        .then(d => {
            _monthlyUsed = d.used_this_month;
            renderMonthlyStatus(document.getElementById('start_date').value);
            validateForm();
        });
}

function renderMonthlyStatus(sd) {
    const box = document.getElementById('monthly_status');
    // Never show monthly status for special or medical leave
    if (isSpecialMode() || isMedicalMode()) { box.style.display = 'none'; return; }
    if (!sd) { box.style.display = 'none'; return; }

    const used      = _monthlyUsed;
    const lim       = _monthlyLimit;
    const mn        = new Date(sd + 'T00:00:00').toLocaleString('default', { month: 'long', year: 'numeric' });
    const empType   = _isNewEmployee ? 'New employee' : 'Employee';
    const remaining = lim - used;

    box.style.display = 'flex';

    const wouldExceed = (_currentLeaveDays > 0) && (used + _currentLeaveDays > lim);

    if (used >= lim) {
        box.className = 'monthly-status status-blocked';
        box.innerHTML = `<i class="fa-solid fa-ban"></i>
            <span><strong>Leave day limit reached for ${mn}.</strong>
            ${empType} has used <strong>${used}/${lim}</strong> allowed leave day(s). Submission is blocked.</span>`;
    } else if (wouldExceed) {
        box.className = 'monthly-status status-blocked';
        box.innerHTML = `<i class="fa-solid fa-ban"></i>
            <span><strong>This leave exceeds the monthly limit for ${mn}.</strong>
            ${empType} has <strong>${remaining}</strong> day(s) remaining but this request covers <strong>${_currentLeaveDays}</strong> day(s). Submission is blocked.</span>`;
    } else if (remaining === 1) {
        box.className = 'monthly-status status-warn';
        box.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i>
            <span>Warning: ${used}/${lim} leave days used in ${mn}. Only <strong>1 leave day remaining</strong> this month.</span>`;
    } else {
        box.className = 'monthly-status status-ok';
        box.innerHTML = `<i class="fa-solid fa-circle-check"></i>
            <span>${used}/${lim} leave days used in ${mn}. <strong>${remaining} leave day(s) available</strong> this month.</span>`;
    }
}

function validateForm() {
    const emp    = $('#employee_select').val();
    const type   = document.getElementById('leave_type').value;
    const sd     = document.getElementById('start_date').value;
    const ed     = document.getElementById('end_date').value;
    const btn    = document.getElementById('submitBtn');
    const msg    = document.getElementById('submit_block_reason');
    const basicOk = !!(emp && type && sd && ed);

    msg.style.display = 'none';
    msg.innerHTML = '';

    // ── Medical Leave: no restrictions — allow submit if basic fields filled ──
    if (isMedicalMode()) {
        btn.disabled = !basicOk;
        return;
    }

    // ── Special Leave ──────────────────────────────────────────────────────
    if (isSpecialMode()) {
        let ok = true; let blockMsg = '';
        if (!_specialEligible) {
            ok = false;
            if (_specialBlockedLow)
                blockMsg = `<i class="fa-solid fa-ban"></i> Annual balance (${_annualBalance} days) is 8 or below — Special Leave blocked`;
            else if (_specialAlreadyUsed)
                blockMsg = `<i class="fa-solid fa-ban"></i> A 5-day Annual Leave already taken this year`;
            else
                blockMsg = `<i class="fa-solid fa-ban"></i> Annual balance must be more than 8 days (current: ${_annualBalance})`;
        }
        btn.disabled = !(basicOk && ok);
        if (basicOk && !ok) { msg.style.display = 'inline-flex'; msg.innerHTML = blockMsg; }
        return;
    }

    // ── Annual Leave balance check ─────────────────────────────────────────
    if (_eid && type === 'Annual Leave' && _currentLeaveDays > 0) {
        if (_annualBalance <= 0) {
            btn.disabled = true;
            msg.style.display = 'inline-flex';
            msg.innerHTML = `<i class="fa-solid fa-ban"></i> No annual leave balance remaining (0 days left)`;
            return;
        }
        if (_currentLeaveDays > _annualBalance) {
            btn.disabled = true;
            msg.style.display = 'inline-flex';
            msg.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> This leave requires <strong>${_currentLeaveDays}</strong> day(s) but only <strong>${_annualBalance}</strong> annual day(s) remain`;
            return;
        }
    }

    // ── Casual Leave balance check ─────────────────────────────────────────
    if (_eid && type === 'Casual Leave' && _currentLeaveDays > 0) {
        if (_casualBalance <= 0) {
            btn.disabled = true;
            msg.style.display = 'inline-flex';
            msg.innerHTML = `<i class="fa-solid fa-ban"></i> No casual leave balance remaining (0 days left)`;
            return;
        }
        if (_currentLeaveDays > _casualBalance) {
            btn.disabled = true;
            msg.style.display = 'inline-flex';
            msg.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> This leave requires <strong>${_currentLeaveDays}</strong> day(s) but only <strong>${_casualBalance}</strong> casual day(s) remain`;
            return;
        }
    }

    // ── Monthly limit check ────────────────────────────────────────────────
    const limitOk    = _eid ? (_monthlyUsed < _monthlyLimit) : true;
    const monthReady = _eid && sd ? (_monthChecked === sd.slice(0,7)) : true;
    const wouldExceed = _eid && sd && _currentLeaveDays > 0
                        ? ((_monthlyUsed + _currentLeaveDays) > _monthlyLimit)
                        : false;

    btn.disabled = !(basicOk && limitOk && monthReady && !wouldExceed);

    if (basicOk && _eid) {
        if (!limitOk) {
            const mn = new Date(sd + 'T00:00:00').toLocaleString('default', { month: 'long', year: 'numeric' });
            msg.style.display = 'inline-flex';
            msg.innerHTML = `<i class="fa-solid fa-ban"></i> Limit of ${_monthlyLimit} leave day(s) reached for ${mn}`;
        } else if (wouldExceed) {
            const mn    = new Date(sd + 'T00:00:00').toLocaleString('default', { month: 'long', year: 'numeric' });
            const avail = _monthlyLimit - _monthlyUsed;
            msg.style.display = 'inline-flex';
            msg.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> This leave has <strong>${_currentLeaveDays}</strong> leave day(s) but only <strong>${avail}</strong> remain for ${mn}`;
        }
    }
}

function showHolidayLoading() {
    document.getElementById('hol_count_badge').style.display = 'none';
    document.getElementById('hol_panel_body').innerHTML = `
        <div class="hol-loading">
            <i class="fa-solid fa-spinner fa-spin"></i>
            <span>Checking holidays…</span>
        </div>`;
}

function clearHolidaySidebar() {
    document.getElementById('hol_count_badge').style.display = 'none';
    document.getElementById('hol_panel_body').innerHTML = `
        <div class="hol-empty">
            <i class="fa-solid fa-calendar-days"></i>
            <p>Select an employee and date range to see holidays</p>
        </div>`;
}

function renderHolidaySidebar(holidays, startDate, endDate, leaveDays, calDays) {
    const body  = document.getElementById('hol_panel_body');
    const badge = document.getElementById('hol_count_badge');

    let summaryHtml = `<div class="hol-summary">
        <div class="hol-sum-chip chip-range">
            <i class="fa-solid fa-calendar-range"></i>
            ${fmtDate(startDate)} → ${fmtDate(endDate)}
        </div>`;

    if (holidays.length > 0) {
        summaryHtml += `
        <div class="hol-sum-chip chip-skip">
            <i class="fa-solid fa-calendar-xmark"></i>
            ${holidays.length} holiday${holidays.length !== 1 ? 's' : ''} skipped
        </div>`;
    }

    summaryHtml += `
        <div class="hol-sum-chip chip-leave">
            <i class="fa-solid fa-check-circle"></i>
            ${leaveDays} leave day${leaveDays !== 1 ? 's' : ''}
        </div>
    </div>`;

    if (holidays.length === 0) {
        badge.style.display = 'none';
        body.innerHTML = summaryHtml + `
            <div class="hol-none-msg">
                <i class="fa-solid fa-circle-check"></i>
                <strong>No holidays in this range</strong>
                <span>All ${calDays} calendar day${calDays !== 1 ? 's' : ''} count as leave days</span>
            </div>`;
        return;
    }

    badge.textContent   = holidays.length;
    badge.style.display = 'inline-flex';

    let listHtml = '';
    holidays.forEach(h => {
        const d    = new Date(h.date + 'T00:00:00');
        const day  = d.getDate();
        const mon  = MONTHS_SHORT[d.getMonth()];
        const cls  = h.type === 'public' ? 'hol-public' : 'hol-special';
        const bCls = h.type === 'public' ? 'hol-badge-public' : 'hol-badge-special';
        const bTxt = h.type === 'public' ? '<i class="fa-solid fa-flag"></i> Public Holiday' : '<i class="fa-solid fa-umbrella-beach"></i> Special Holiday';
        listHtml += `
        <div class="hol-item ${cls}">
            <div class="hol-item-date">
                <div class="hol-day">${day}</div>
                <div class="hol-mon">${mon}</div>
                <div class="hol-dow">${h.dow}</div>
            </div>
            <div class="hol-item-info">
                <div class="hol-item-name">${h.name}</div>
                <span class="hol-item-badge ${bCls}">${bTxt}</span>
            </div>
        </div>`;
    });

    body.innerHTML = summaryHtml + listHtml;
}

function resetBal() {
    _eid = null; _isNewEmployee = false; _monthlyLimit = 2;
    _monthlyUsed = 0; _monthChecked = null;
    _specialEligible = false; _specialBlockedLow = false;
    _specialAlreadyUsed = false; _annualBalance = 0; _casualBalance = 0; _currentLeaveDays = 0;
    document.getElementById('tenure_banner').style.display          = 'none';
    document.getElementById('balance_banner').style.display         = 'none';
    document.getElementById('monthly_status').style.display         = 'none';
    document.getElementById('special_leave_banner').style.display   = 'none';
    document.getElementById('special_leave_info').style.display     = 'none';
    document.getElementById('medical_leave_info').style.display     = 'none';
    document.getElementById('days_display').textContent             = '—';
    document.getElementById('days_display').classList.remove('is-special');
    document.getElementById('days_display').classList.remove('is-medical');
    document.getElementById('days_count_input').value               = 1;
    document.getElementById('submitBtn').disabled                    = true;
    document.getElementById('submit_block_reason').style.display    = 'none';
    document.getElementById('end_date').readOnly                    = false;
    document.getElementById('end_date_locked_label').style.display  = 'none';

    // Restore all leave type options to visible/enabled on reset
    const annualOpt = document.querySelector('#leave_type option[value="Annual Leave"]');
    if (annualOpt) { annualOpt.style.display = ''; annualOpt.disabled = false; }
    const casualOpt = document.querySelector('#leave_type option[value="Casual Leave"]');
    if (casualOpt) { casualOpt.style.display = ''; casualOpt.disabled = false; }
    const opt = document.getElementById('special_leave_option');
    if (opt) opt.style.display = 'none';

    clearHolidaySidebar();
}

function resetForm() {
    resetBal();
    $('#employee_select').val(null).trigger('change');
    document.getElementById('leaveForm').reset();
    document.getElementById('days_display').textContent = '—';
    document.getElementById('days_display').classList.remove('is-special');
    document.getElementById('days_display').classList.remove('is-medical');
    document.getElementById('epf_lookup_input').value    = '';
    document.getElementById('epf_lookup_result').style.display = 'none';
}
</script>

<?php include 'footer.php'; ?>