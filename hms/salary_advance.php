<?php
// ── salary_advance_self.php ────────────────────────────────────────────────
// Self-Service Salary Advance — Employee submits their own advance request
// Style: iOS-native mobile shell (matches leave_self.php / daily_plan.php)
// ─────────────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Asia/Colombo');

if (!isset($_SESSION['employee'])) {
    header('Location: login.php');
    exit;
}

require_once 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);

/* ── Designation-wise advance limit (set in the ERP: Salary Configuration) — same rules as the ERP salary_advance_limit_lib.php ── */
if (!function_exists('sal_adv_ensure')) {
    function sal_adv_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
    function sal_adv_ensure($conn) {
        static $done = false; if ($done) return; $done = true;
        sal_adv_q($conn, "CREATE TABLE IF NOT EXISTS salary_advance_limits (
            designation_id INT NOT NULL PRIMARY KEY, limit_type VARCHAR(10) NOT NULL DEFAULT 'amount', limit_value DECIMAL(12,2) NOT NULL DEFAULT 0,
            auto_approve TINYINT(1) NOT NULL DEFAULT 1, updated_by VARCHAR(100) NULL, updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    function sal_adv_limits($conn) {
        sal_adv_ensure($conn); $o = [];
        $r = sal_adv_q($conn, "SELECT * FROM salary_advance_limits"); while ($r && ($x = mysqli_fetch_assoc($r))) $o[(int)$x['designation_id']] = $x;
        return $o;
    }
    /** limit of one employee: null when the designation has no limit */
    function sal_adv_info($conn, $emp_id) {
        sal_adv_ensure($conn); $emp_id = (int)$emp_id;
        $r = sal_adv_q($conn, "SELECT e.id, e.employee_full_name, e.designation_id, e.basic_salary, d.designation_name FROM employees e LEFT JOIN designations d ON d.id = e.designation_id WHERE e.id = $emp_id");
        $e = $r ? mysqli_fetch_assoc($r) : null; if (!$e) return null;
        $basic = (float)($e['basic_salary'] ?? 0);
        if ($basic <= 0 && (int)$e['designation_id'] > 0) { $r = sal_adv_q($conn, "SELECT basic_salary FROM sr_salary_config WHERE designation_id = " . (int)$e['designation_id']); if ($r && ($x = mysqli_fetch_row($r))) $basic = (float)$x[0]; }
        $r = sal_adv_q($conn, "SELECT * FROM salary_advance_limits WHERE designation_id = " . (int)$e['designation_id']); $L = $r ? mysqli_fetch_assoc($r) : null;
        if (!$L || (float)$L['limit_value'] <= 0) return ['limit' => null, 'basic' => $basic, 'designation' => $e['designation_name'] ?? '', 'auto' => false, 'type' => '', 'value' => 0];
        $limit = $L['limit_type'] === 'rate' ? round($basic * (float)$L['limit_value'] / 100, 2) : round((float)$L['limit_value'], 2);
        return ['limit' => $limit, 'basic' => $basic, 'designation' => $e['designation_name'] ?? '', 'auto' => (int)$L['auto_approve'] === 1, 'type' => $L['limit_type'], 'value' => (float)$L['limit_value']];
    }
    /** advances already requested in the payroll period (not rejected / cancelled) */
    function sal_adv_used($conn, $emp_id, $period_id, $exclude_id = 0) {
        $emp_id = (int)$emp_id; $period_id = (int)$period_id; if (!$period_id) return 0.0;
        $r = sal_adv_q($conn, "SELECT COALESCE(SUM(amount),0) FROM salary_advances WHERE employee_id = $emp_id AND payroll_period_id = $period_id
                               AND COALESCE(status,'') NOT IN ('Rejected','Cancelled')" . ($exclude_id ? " AND id <> " . (int)$exclude_id : ""));
        return $r ? round((float)mysqli_fetch_row($r)[0], 2) : 0.0;
    }
    /** check a request: ok, msg (when over), auto (approve now), limit / used / left */
    function sal_adv_check($conn, $emp_id, $amount, $period_id, $exclude_id = 0) {
        $i = sal_adv_info($conn, $emp_id);
        if (!$i || $i['limit'] === null) return ['ok' => true, 'auto' => false, 'limit' => null, 'used' => 0, 'left' => null, 'msg' => ''];
        $used = sal_adv_used($conn, $emp_id, $period_id, $exclude_id); $left = round($i['limit'] - $used, 2);
        $how = $i['type'] === 'rate' ? rtrim(rtrim(number_format($i['value'], 2), '0'), '.') . '% of basic Rs. ' . number_format($i['basic'], 2) : 'fixed';
        if (round((float)$amount, 2) > $left + 0.005)
            return ['ok' => false, 'auto' => false, 'limit' => $i['limit'], 'used' => $used, 'left' => max(0, $left),
                    'msg' => 'Over the advance limit: ' . ($i['designation'] !== '' ? $i['designation'] . ' ' : '') . 'limit Rs. ' . number_format($i['limit'], 2) . " ($how)"
                           . ($used > 0 ? ', already requested Rs. ' . number_format($used, 2) . ' this period' : '') . ', can request up to Rs. ' . number_format(max(0, $left), 2) . '.'];
        return ['ok' => true, 'auto' => $i['auto'], 'limit' => $i['limit'], 'used' => $used, 'left' => $left, 'msg' => ''];
    }
}


/* the request this employee already has for a payroll period (not Rejected / Cancelled) — one request per payroll period */
function sa_existing($conn, $emp_id, $period_id) {
    $r = sal_adv_q($conn, "SELECT id, amount, status, request_date FROM salary_advances WHERE employee_id = " . (int)$emp_id . " AND payroll_period_id = " . (int)$period_id . "
                           AND COALESCE(status,'') NOT IN ('Rejected','Cancelled') ORDER BY id DESC LIMIT 1");
    return $r ? mysqli_fetch_assoc($r) : null;
}

// ── Session employee ──────────────────────────────────────────────────────
$sessEmp  = $_SESSION['employee'];
$empId    = intval($sessEmp['id']                 ?? 0);
$empCode  = $sessEmp['employee_id']               ?? '';
$empName  = $sessEmp['employee_full_name']         ?? ($sessEmp['name_with_initials'] ?? '');
$empDesig = $sessEmp['designation_name']           ?? '';

if (!$empId) {
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT e.id, e.employee_id, e.employee_full_name, e.name_with_initials,
                e.basic_salary, d.designation_name
         FROM employees e
         LEFT JOIN designations d ON e.designation_id = d.id
         WHERE e.employee_id = '" . mysqli_real_escape_string($conn, $empCode) . "'
         LIMIT 1"));
    if ($row) {
        $empId    = intval($row['id']);
        $empCode  = $row['employee_id'];
        $empName  = $row['employee_full_name'] ?: $row['name_with_initials'];
        $empDesig = $row['designation_name'] ?? '';
    }
}

$month_names = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

// ── Load basic salary ────────────────────────────────────────────────────
$basic_salary = 0;
$emp_row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT e.basic_salary, e.date_of_join, d.designation_name
     FROM employees e
     LEFT JOIN designations d ON e.designation_id = d.id
     WHERE e.id=$empId LIMIT 1"));
if ($emp_row) {
    $basic_salary = floatval($emp_row['basic_salary']);
    $empDesig     = $empDesig ?: ($emp_row['designation_name'] ?? '');
}

// ── Load open payroll periods ────────────────────────────────────────────
$periods = [];
$pr = mysqli_query($conn, "SELECT * FROM payroll_periods WHERE status='Open' ORDER BY year DESC, month DESC");
while ($p = mysqli_fetch_assoc($pr)) $periods[] = $p;
$last_open = $periods[0] ?? null;

$all_periods = [];
$apr = mysqli_query($conn, "SELECT * FROM payroll_periods ORDER BY year DESC, month DESC");
while ($p = mysqli_fetch_assoc($apr)) $all_periods[] = $p;
$any_period = $all_periods[0] ?? null;

$payroll_closed = empty($periods);
$sa_limit = sal_adv_info($conn, $empId);

// ── POST: submit advance ─────────────────────────────────────────────────
$save_error = ''; $save_success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_advance'])) {
    if ($payroll_closed) {
        $save_error = 'No open payroll period. Cannot submit advance request.';
    } else {
        $amount    = floatval($_POST['amount'] ?? 0);
        $req_date  = mysqli_real_escape_string($conn, $_POST['request_date'] ?? date('Y-m-d'));
        $reason    = mysqli_real_escape_string($conn, trim($_POST['reason'] ?? ''));
        $period_id = intval($_POST['payroll_period_id'] ?? 0);

        if ($amount <= 0)    $save_error = 'Amount must be greater than 0.';
        elseif (!$period_id) $save_error = 'Please select a payroll period.';
        else {
            // Check period still open
            $pchk = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT status FROM payroll_periods WHERE id=$period_id"));
            $dup = ($pchk && $pchk['status'] !== 'Locked') ? sa_existing($conn, $empId, $period_id) : null;
            $lim = ($pchk && $pchk['status'] !== 'Locked' && !$dup) ? sal_adv_check($conn, $empId, $amount, $period_id) : null;
            if (!$pchk || $pchk['status'] === 'Locked') {
                $save_error = 'Selected payroll period is locked.';
            } elseif ($dup) {
                $save_error = 'Already requested for this payroll period: LKR ' . number_format((float)$dup['amount'], 2) . ' on ' . date('d M Y', strtotime($dup['request_date'])) . ' (' . $dup['status'] . '). Only one advance request is allowed per payroll period.';
            } elseif (!$lim['ok']) {
                $save_error = $lim['msg'];
            } else {
                $created_by = mysqli_real_escape_string($conn,
                    $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'Employee');
                /* within the designation limit and auto approve is on → approved now (same as the ERP) */
                $auto = $lim['limit'] !== null && $lim['auto'];
                $ok = mysqli_query($conn,
                    "INSERT INTO salary_advances (employee_id, request_date, amount, reason, payroll_period_id, status, created_by" . ($auto ? ", approved_by, approved_at, remarks" : "") . ")
                     VALUES ($empId, '$req_date', $amount, '$reason', $period_id, '" . ($auto ? 'Approved' : 'Pending') . "', '$created_by'"
                     . ($auto ? ", 'Auto (within advance limit)', NOW(), 'Auto approved: within the designation advance limit Rs. " . number_format($lim['limit'], 2) . "'" : "") . ")");
                if ($ok) { $save_success = $auto ? 'Advance request approved automatically (within your advance limit)!' : 'Advance request submitted successfully!'; $_POST = []; }
                else $save_error = 'Could not save the request. Please try again.';
            }
        }
    }
}

// requests already made in the open periods + what is left of the limit (after any save above)
$period_info = [];
foreach ($periods as $p) {
    $ex = sa_existing($conn, $empId, $p['id']); $used = sal_adv_used($conn, $empId, $p['id']);
    $period_info[(int)$p['id']] = ['label' => $month_names[$p['month']] . ' ' . $p['year'],
        'existing' => $ex ? ['amount' => (float)$ex['amount'], 'status' => $ex['status'], 'date' => date('d M Y', strtotime($ex['request_date']))] : null,
        'limit' => ($sa_limit && $sa_limit['limit'] !== null) ? $sa_limit['limit'] : null, 'left' => ($sa_limit && $sa_limit['limit'] !== null) ? max(0, round($sa_limit['limit'] - $used, 2)) : null,
        'auto' => $sa_limit ? (bool)$sa_limit['auto'] : false, 'type' => $sa_limit['type'] ?? '', 'value' => $sa_limit['value'] ?? 0];
}

// ── Load advance history ──────────────────────────────────────────────────
$year_filter   = intval($_GET['year']   ?? date('Y'));
$status_filter = $_GET['status']        ?? 'all';
$ys = $year_filter . '-01-01';
$ye = $year_filter . '-12-31';
$status_sql = $status_filter !== 'all'
    ? " AND sa.status='" . mysqli_real_escape_string($conn, $status_filter) . "'"
    : '';

$history = [];
$hist_res = mysqli_query($conn,
    "SELECT sa.*, pp.month AS period_month, pp.year AS period_year, pp.status AS period_status
     FROM salary_advances sa
     LEFT JOIN payroll_periods pp ON pp.id = sa.payroll_period_id
     WHERE sa.employee_id=$empId
       AND sa.request_date BETWEEN '$ys' AND '$ye'
       $status_sql
     ORDER BY sa.request_date DESC, sa.id DESC");
if ($hist_res) while ($r = mysqli_fetch_assoc($hist_res)) $history[] = $r;

// Status counts
$counts_res = mysqli_query($conn,
    "SELECT status, COUNT(*) AS c, COALESCE(SUM(amount),0) AS amt
     FROM salary_advances
     WHERE employee_id=$empId AND request_date BETWEEN '$ys' AND '$ye'
     GROUP BY status");
$status_counts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
$status_amounts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
if ($counts_res) {
    while ($r = mysqli_fetch_assoc($counts_res)) {
        $status_counts[$r['status']]  = intval($r['c']);
        $status_amounts[$r['status']] = floatval($r['amt']);
    }
}
$total_count = array_sum($status_counts);

// Initials
$initials = '';
foreach (explode(' ', $empName) as $w) $initials .= strtoupper($w[0] ?? '');
$initials = substr($initials, 0, 2) ?: 'EE';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Yelo HMS — Salary Advance</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
/* ── TOKENS ── */
:root{
  --sf:-apple-system,'SF Pro Display','SF Pro Text','Helvetica Neue','Inter',sans-serif;
  --yellow:#F5C518;--yellow-dim:rgba(245,197,24,0.16);--yellow-glow:rgba(245,197,24,0.38);
  --bg:#F2F2F7;--bg2:#FFFFFF;--bg3:rgba(118,118,128,0.10);
  --label:#000000;--label2:rgba(60,60,67,0.60);--label3:rgba(60,60,67,0.28);
  --sep:rgba(60,60,67,0.13);
  --red:#FF3B30;--green:#34C759;--blue:#007AFF;--orange:#FF9F0A;--purple:#AF52DE;
  --shadow:0 2px 20px rgba(0,0,0,0.07),0 1px 3px rgba(0,0,0,0.05);
  --shadow-lg:0 8px 32px rgba(0,0,0,0.13),0 2px 8px rgba(0,0,0,0.06);
  --island:#000;
}
[data-theme="dark"]{
  --bg:#000000;--bg2:#1C1C1E;--bg3:rgba(118,118,128,0.22);
  --label:#FFFFFF;--label2:rgba(235,235,245,0.60);--label3:rgba(235,235,245,0.18);
  --sep:rgba(84,84,88,0.60);
  --shadow:0 2px 20px rgba(0,0,0,0.50);
  --shadow-lg:0 8px 32px rgba(0,0,0,0.60);
  --island:#1C1C1E;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent;}
html,body{height:100%;background:var(--bg);font-family:var(--sf);-webkit-font-smoothing:antialiased;overscroll-behavior:none;transition:background .3s;}
body{display:flex;justify-content:center;}
.shell{width:100%;max-width:480px;min-height:100dvh;background:var(--bg);display:flex;flex-direction:column;transition:background .3s;}

/* TOPBAR */
.topbar{display:flex;align-items:center;justify-content:space-between;padding:14px 20px 0;flex-shrink:0;position:sticky;top:0;z-index:100;background:var(--bg);transition:background .3s;}
.topbar-left{display:flex;align-items:center;gap:10px;}
.back-btn{height:34px;width:34px;background:var(--bg3);border:none;border-radius:50%;font-size:15px;color:var(--label);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;text-decoration:none;}
.back-btn:active{opacity:.7;transform:scale(.95);}
.island{width:118px;height:34px;background:var(--island);border-radius:20px;transition:background .3s;}
.icon-pill{height:34px;width:34px;background:var(--bg3);border:none;border-radius:20px;font-size:13px;color:var(--label);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;}
.icon-pill:active{opacity:.7;transform:scale(.96);}

.scroll{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none;padding-bottom:40px;}
.scroll::-webkit-scrollbar{display:none;}

.large-title{padding:8px 22px 16px;font-size:34px;font-weight:800;letter-spacing:-1px;color:var(--label);transition:color .3s;}
.large-title em{color:var(--yellow);font-style:normal;}

@keyframes fadeUp{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);}}
@keyframes spin{to{transform:rotate(360deg);}}
@keyframes barGrow{from{width:0;}to{width:var(--w);}}

/* ALERT */
.dp-alert{margin:0 20px 14px;padding:12px 16px;border-radius:14px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;animation:fadeUp .3s both;}
.dp-alert-ok {background:rgba(52,199,89,.12);color:var(--green);border:1px solid rgba(52,199,89,.25);}
.dp-alert-err{background:rgba(255,59,48,.10);color:var(--red); border:1px solid rgba(255,59,48,.20);}

/* CARDS */
.dp-card{margin:0 20px 14px;background:var(--bg2);border-radius:22px;overflow:hidden;box-shadow:var(--shadow);animation:fadeUp .4s both;transition:background .3s;}
.dp-card-head{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:.5px solid var(--sep);}
.dp-card-icon{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
.dp-card-title{font-size:14px;font-weight:700;color:var(--label);letter-spacing:-.2px;transition:color .3s;}
.dp-card-body{padding:16px 18px;}

/* EMPLOYEE CARD */
.emp-card-inner{display:flex;align-items:center;gap:14px;}
.emp-avatar{width:52px;height:52px;border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:800;background:var(--yellow-dim);color:#b8900c;border:2px solid rgba(245,197,24,.3);flex-shrink:0;letter-spacing:-.5px;}
.emp-details{flex:1;min-width:0;}
.emp-name{font-size:16px;font-weight:800;color:var(--label);letter-spacing:-.3px;margin-bottom:3px;transition:color .3s;}
.emp-meta{display:flex;flex-wrap:wrap;gap:6px;}
.emp-chip{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:8px;background:var(--bg3);color:var(--label2);}
.emp-chip i{font-size:10px;}

/* SALARY INFO TILE */
.salary-tile{margin-top:14px;padding:14px 16px;border-radius:16px;background:linear-gradient(135deg,rgba(245,197,24,.12) 0%,rgba(245,197,24,.05) 100%);border:1px solid rgba(245,197,24,.25);}
.salary-tile-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#b8900c;margin-bottom:4px;}
.salary-tile-val{font-size:28px;font-weight:900;letter-spacing:-1px;color:var(--label);transition:color .3s;}
.salary-tile-sub{font-size:11px;color:var(--label3);margin-top:2px;transition:color .3s;}

/* PAYROLL PERIOD BANNER */
.period-banner{margin-top:12px;display:flex;align-items:center;gap:8px;padding:10px 13px;border-radius:12px;font-size:12px;font-weight:600;}
.period-open  {background:rgba(52,199,89,.09); border:1px solid rgba(52,199,89,.22); color:var(--green);}
.period-locked{background:rgba(255,59,48,.09); border:1px solid rgba(255,59,48,.20); color:var(--red);}
.period-none  {background:rgba(255,159,10,.09);border:1px solid rgba(255,159,10,.22);color:var(--orange);}
.period-banner i{font-size:13px;flex-shrink:0;}

/* FORM */
.dp-row  {display:grid;gap:14px;margin-bottom:14px;}
.dp-row-2{grid-template-columns:1fr 1fr;}
.dp-row-1{grid-template-columns:1fr;}
@media(max-width:400px){.dp-row-2{grid-template-columns:1fr;}}

.dp-group{display:flex;flex-direction:column;gap:6px;}
.dp-label{font-size:11px;font-weight:700;color:var(--label2);text-transform:uppercase;letter-spacing:.4px;transition:color .3s;}
.dp-input,.dp-select,.dp-textarea{border:1px solid var(--sep);border-radius:12px;padding:0 14px;font-size:14px;font-family:var(--sf);color:var(--label);background:var(--bg3);width:100%;transition:border-color .2s,background .3s;outline:none;-webkit-appearance:none;}
.dp-input,.dp-select{height:44px;}
.dp-textarea{padding:12px 14px;height:90px;resize:none;line-height:1.5;}
.dp-input:focus,.dp-select:focus,.dp-textarea:focus{border-color:var(--yellow);background:var(--bg2);box-shadow:0 0 0 3px var(--yellow-dim);}
.dp-input::placeholder,.dp-textarea::placeholder{color:var(--label3);}
.dp-hint{font-size:11px;color:var(--label3);transition:color .3s;}

/* Amount input with prefix */
.amount-wrap{position:relative;}
.amount-prefix{position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:12px;font-weight:700;color:var(--label3);pointer-events:none;transition:color .3s;}
.dp-input-amount{padding-left:52px!important;}

/* Percentage bar */
.pct-bar-wrap{margin-top:10px;padding:12px 14px;border-radius:14px;background:var(--bg3);border:1px solid var(--sep);display:none;}
.pct-bar-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.pct-bar-lbl{font-size:11px;font-weight:700;color:var(--label2);text-transform:uppercase;letter-spacing:.3px;transition:color .3s;}
.pct-bar-val{font-size:16px;font-weight:900;letter-spacing:-.5px;}
.pct-track{height:6px;background:rgba(118,118,128,.18);border-radius:6px;overflow:hidden;}
.pct-fill{height:100%;border-radius:6px;transition:width .4s,background .3s;}
.pct-warn{margin-top:10px;display:none;padding:9px 12px;border-radius:10px;font-size:12px;font-weight:600;background:rgba(255,59,48,.09);border:1px solid rgba(255,59,48,.20);color:var(--red);display:none;align-items:center;gap:7px;}
.pct-warn i{font-size:13px;flex-shrink:0;}

/* Save button */
.dp-save-btn{width:100%;height:54px;background:var(--label);color:var(--bg);border:none;border-radius:16px;font-size:16px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;font-family:var(--sf);transition:all .2s;letter-spacing:-.2px;margin-top:14px;box-shadow:0 4px 20px rgba(0,0,0,.14);}
.dp-save-btn:not(:disabled):active{transform:scale(.97);}
.dp-save-btn:disabled{opacity:.32;cursor:not-allowed;box-shadow:none;}
.btn-spinner{width:20px;height:20px;border:2.5px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;display:none;}

/* SEGMENT TABS */
.seg-wrap{padding:0 20px 14px;}
.seg{display:flex;background:var(--bg3);border-radius:12px;padding:3px;gap:2px;}
.seg-btn{flex:1;height:34px;border:none;border-radius:10px;font-size:12px;font-weight:700;color:var(--label2);background:transparent;cursor:pointer;font-family:var(--sf);transition:all .2s;}
.seg-btn.active{background:var(--bg2);color:var(--label);box-shadow:0 1px 6px rgba(0,0,0,.10);}

/* STATS ROW */
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:0 20px 14px;}
.stat-tile{background:var(--bg2);border-radius:14px;padding:12px 12px 12px 14px;box-shadow:var(--shadow);border-left:3px solid transparent;transition:background .3s;}
.stat-tile.all  {border-left-color:var(--blue);}
.stat-tile.pend {border-left-color:var(--orange);}
.stat-tile.appr {border-left-color:var(--green);}
.stat-tile.rej  {border-left-color:var(--red);}
.stat-n{font-size:22px;font-weight:900;color:var(--label);letter-spacing:-1px;line-height:1;transition:color .3s;}
.stat-l{font-size:9px;font-weight:700;color:var(--label3);text-transform:uppercase;letter-spacing:.3px;margin-top:2px;transition:color .3s;}
.stat-tile.pend .stat-n{color:var(--orange);}
.stat-tile.appr .stat-n{color:var(--green);}
.stat-tile.rej  .stat-n{color:var(--red);}

/* FILTER BAR */
.filter-bar{display:flex;align-items:center;gap:8px;padding:0 20px 14px;flex-wrap:wrap;}
.filter-pill{display:flex;align-items:center;gap:5px;height:32px;padding:0 13px;border-radius:10px;border:1px solid var(--sep);background:var(--bg3);font-size:12px;font-weight:600;color:var(--label2);cursor:pointer;font-family:var(--sf);transition:all .2s;white-space:nowrap;text-decoration:none;}
.filter-pill.active{background:var(--label);color:var(--bg);border-color:var(--label);}

/* HISTORY LIST */
.hist-list{padding:0 20px;display:flex;flex-direction:column;gap:10px;}
.hist-item{background:var(--bg2);border-radius:18px;overflow:hidden;box-shadow:var(--shadow);animation:fadeUp .35s both;transition:background .3s;}
.hist-item-top{display:flex;align-items:flex-start;gap:12px;padding:14px 16px;}
.hist-icon{width:40px;height:40px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.hist-icon.pend {background:rgba(255,159,10,.12);color:var(--orange);}
.hist-icon.appr {background:rgba(52,199,89,.12); color:var(--green);}
.hist-icon.rej  {background:rgba(255,59,48,.10);  color:var(--red);}
.hist-info{flex:1;min-width:0;}
.hist-amount{font-size:18px;font-weight:900;color:var(--label);letter-spacing:-.5px;line-height:1;transition:color .3s;}
.hist-period{font-size:12px;color:var(--label2);margin-top:3px;transition:color .3s;}
.hist-status-bar{display:flex;align-items:center;justify-content:space-between;padding:9px 16px;border-top:.5px solid var(--sep);}
.status-pill{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:800;padding:4px 11px;border-radius:9px;text-transform:uppercase;letter-spacing:.3px;}
.status-pill.pending {background:rgba(255,159,10,.12);color:var(--orange);border:1px solid rgba(255,159,10,.28);}
.status-pill.approved{background:rgba(52,199,89,.10); color:var(--green); border:1px solid rgba(52,199,89,.25);}
.status-pill.rejected{background:rgba(255,59,48,.09); color:var(--red);   border:1px solid rgba(255,59,48,.20);}
.status-dot{width:6px;height:6px;border-radius:50%;background:currentColor;}
.hist-remark{font-size:11px;color:var(--label3);max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;transition:color .3s;}
.hist-expand-btn{display:flex;align-items:center;gap:4px;font-size:11px;font-weight:700;color:var(--label3);background:none;border:none;cursor:pointer;padding:0;font-family:var(--sf);}
.hist-extra{padding:0 16px 12px;border-top:.5px solid var(--sep);}
.hist-extra-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;padding-top:10px;}
.extra-cell{background:var(--bg3);border-radius:10px;padding:8px 10px;}
.extra-lbl{font-size:9px;font-weight:700;color:var(--label3);text-transform:uppercase;letter-spacing:.3px;transition:color .3s;}
.extra-val{font-size:12px;font-weight:700;color:var(--label);margin-top:2px;transition:color .3s;}

/* PCT badge on history item */
.hist-pct{font-size:11px;font-weight:700;padding:3px 9px;border-radius:8px;white-space:nowrap;}
.pct-ok  {background:rgba(52,199,89,.10); color:var(--green);}
.pct-med {background:rgba(255,159,10,.12);color:var(--orange);}
.pct-high{background:rgba(255,59,48,.09); color:var(--red);}

/* EMPTY STATE */
.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:48px 24px;text-align:center;color:var(--label3);}
.empty-state i{font-size:44px;margin-bottom:12px;display:block;}
.empty-state strong{font-size:15px;font-weight:700;color:var(--label2);display:block;margin-bottom:4px;transition:color .3s;}
.empty-state span{font-size:12px;}

.section-hidden{display:none;}

/* HOME BAR */
.home-bar-wrap{height:34px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.home-bar{width:134px;height:5px;background:var(--label);border-radius:3px;opacity:.15;}

/* TOAST */
.toast{position:fixed;bottom:calc(34px + 16px);left:50%;transform:translateX(-50%) translateY(20px);background:var(--label);color:var(--bg);padding:11px 20px;border-radius:14px;font-size:14px;font-weight:600;white-space:nowrap;z-index:9999;opacity:0;transition:all .28s;pointer-events:none;box-shadow:0 8px 24px rgba(0,0,0,.2);}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0);}
.toast.ok   {background:var(--green);color:#fff;}
.toast.error{background:var(--red);  color:#fff;}
</style>
</head>
<body>
<div class="shell">

  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-left">
      <a href="index.php" class="back-btn" aria-label="Back">
        <i class="fa-solid fa-chevron-left"></i>
      </a>
    
    </div>
    <button class="icon-pill" id="themeBtn" aria-label="Toggle theme">
      <i class="fa-solid fa-moon" id="themeIcon"></i>
    </button>
  </div>

  <div class="scroll">

    <div class="large-title">Salary<br><em>Advance.</em></div>

    <!-- SEGMENT TABS -->
    <div class="seg-wrap">
      <div class="seg">
        <button class="seg-btn active" id="tabApply" onclick="switchTab('apply')">
          <i class="fa-solid fa-plus" style="font-size:10px;margin-right:4px"></i> New Request
        </button>
        <button class="seg-btn" id="tabHistory" onclick="switchTab('history')">
          <i class="fa-solid fa-clock-rotate-left" style="font-size:10px;margin-right:4px"></i> My History
        </button>
      </div>
    </div>

    <!-- ══ APPLY TAB ══ -->
    <div id="sectionApply">

      <?php if ($save_success): ?>
      <div class="dp-alert dp-alert-ok">
        <i class="fa-solid fa-circle-check"></i>
        <span><?= htmlspecialchars($save_success) ?></span>
      </div>
      <?php endif; ?>
      <?php if ($save_error): ?>
      <div class="dp-alert dp-alert-err">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span><?= htmlspecialchars($save_error) ?></span>
      </div>
      <?php endif; ?>

      <!-- PROFILE + SALARY CARD -->
      <div class="dp-card" style="animation-delay:.04s">
        <div class="dp-card-head">
          <div class="dp-card-icon" style="background:rgba(245,197,24,.16);color:#b8900c">
            <i class="fa-solid fa-id-badge"></i>
          </div>
          <span class="dp-card-title">Your Profile</span>
        </div>
        <div class="dp-card-body">

          <div class="emp-card-inner">
            <div class="emp-avatar"><?= htmlspecialchars($initials) ?></div>
            <div class="emp-details">
              <div class="emp-name"><?= htmlspecialchars($empName) ?></div>
              <div class="emp-meta">
                <span class="emp-chip"><i class="fa-solid fa-fingerprint"></i> <?= htmlspecialchars($empCode) ?></span>
                <?php if ($empDesig): ?>
                <span class="emp-chip"><i class="fa-solid fa-briefcase"></i> <?= htmlspecialchars($empDesig) ?></span>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- Basic Salary tile -->
          <?php if ($basic_salary > 0): ?>
          <div class="salary-tile">
            <div class="salary-tile-lbl"><i class="fa-solid fa-money-bill-wave" style="margin-right:4px"></i> Basic Salary</div>
            <div class="salary-tile-val">LKR <?= number_format($basic_salary, 2) ?></div>
            <div class="salary-tile-sub">Your gross monthly salary before deductions</div>
          </div>
          <?php endif; ?>

          <!-- Payroll period status -->
          <?php if ($last_open): ?>
          <div class="period-banner period-open">
            <i class="fa-solid fa-lock-open"></i>
            <span>Open period: <strong><?= $month_names[$last_open['month']] . ' ' . $last_open['year'] ?></strong></span>
          </div>
          <?php elseif ($any_period): ?>
          <div class="period-banner period-locked">
            <i class="fa-solid fa-lock"></i>
            <span>Last period (<strong><?= $month_names[$any_period['month']] . ' ' . $any_period['year'] ?></strong>) is locked — no open periods</span>
          </div>
          <?php else: ?>
          <div class="period-banner period-none">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>No payroll periods found</span>
          </div>
          <?php endif; ?>

        </div>
      </div>

      <!-- REQUEST FORM -->
      <div class="dp-card" style="animation-delay:.08s">
        <div class="dp-card-head">
          <div class="dp-card-icon" style="background:rgba(255,159,10,.12);color:var(--orange)">
            <i class="fa-solid fa-hand-holding-dollar"></i>
          </div>
          <span class="dp-card-title">Request Details</span>
        </div>
        <div class="dp-card-body">

          <?php if ($payroll_closed): ?>
          <div style="text-align:center;padding:24px 16px 8px;">
            <div style="width:56px;height:56px;border-radius:16px;background:rgba(255,59,48,.10);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:24px;color:var(--red);">
              <i class="fa-solid fa-lock"></i>
            </div>
            <div style="font-size:15px;font-weight:700;color:var(--label);margin-bottom:6px;transition:color .3s;">Submissions Closed</div>
            <div style="font-size:13px;color:var(--label2);line-height:1.5;transition:color .3s;">
              <?php if ($any_period): ?>
                The payroll period for <strong><?= $month_names[$any_period['month']] . ' ' . $any_period['year'] ?></strong> is locked.<br>New requests cannot be submitted until a period is opened.
              <?php else: ?>
                No payroll periods have been configured yet. Please contact HR.
              <?php endif; ?>
            </div>
          </div>

          <?php else: ?>

          <form method="POST" id="advanceForm">
            <input type="hidden" name="submit_advance" value="1">

            <!-- Amount -->
            <div class="dp-row dp-row-1" style="margin-bottom:0">
              <div class="dp-group">
                <label class="dp-label">Advance Amount <span style="color:var(--red)">*</span></label>
                <div class="amount-wrap">
                  <span class="amount-prefix">LKR</span>
                  <input type="number" name="amount" id="amountInput" class="dp-input dp-input-amount"
                         min="1" step="0.01" required placeholder="0.00"
                         value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>"
                         oninput="calcPct();saCheck()">
                </div>
              </div>
            </div>

            <!-- Percentage bar -->
            <div class="pct-bar-wrap" id="pctBar">
              <div class="pct-bar-top">
                <span class="pct-bar-lbl">% of Basic Salary</span>
                <span class="pct-bar-val" id="pctVal">0%</span>
              </div>
              <div class="pct-track">
                <div class="pct-fill" id="pctFill" style="width:0%"></div>
              </div>
              <div class="pct-warn" id="pctWarn">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>Amount exceeds 75% of basic salary. You may still proceed.</span>
              </div>
            </div>

            <!-- Date + Period -->
            <div class="dp-row dp-row-2" style="margin-top:14px;margin-bottom:14px">
              <div class="dp-group">
                <label class="dp-label">Request Date <span style="color:var(--red)">*</span></label>
                <input type="date" name="request_date" class="dp-input" required
                       value="<?= htmlspecialchars($_POST['request_date'] ?? date('Y-m-d')) ?>">
              </div>
              <div class="dp-group">
                <label class="dp-label">Payroll Period <span style="color:var(--red)">*</span></label>
                <select name="payroll_period_id" id="periodSel" class="dp-select" required onchange="saCheck()">
                  <option value="">— Select —</option>
                  <?php foreach ($periods as $p): ?>
                  <option value="<?= $p['id'] ?>"
                    <?= (isset($_POST['payroll_period_id']) && $_POST['payroll_period_id'] == $p['id'])
                        ? 'selected'
                        : ($last_open && $p['id'] == $last_open['id'] ? 'selected' : '') ?>>
                    <?= $month_names[$p['month']] . ' ' . $p['year'] ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <!-- Already requested / advance limit for the chosen period -->
            <div id="saInfo" style="display:none;margin:-4px 0 14px;padding:10px 13px;border-radius:12px;font-size:12px;font-weight:600;line-height:1.45;"></div>

            <!-- Reason -->
            <div class="dp-row dp-row-1" style="margin-bottom:0">
              <div class="dp-group">
                <label class="dp-label">Reason <span style="color:var(--label3);text-transform:none;font-weight:400">Optional</span></label>
                <textarea name="reason" class="dp-textarea" placeholder="Reason for advance request…"><?= htmlspecialchars($_POST['reason'] ?? '') ?></textarea>
              </div>
            </div>

            <!-- Policy note -->
            <div style="margin-top:12px;padding:10px 13px;border-radius:12px;background:var(--bg3);border:1px solid var(--sep);font-size:11px;color:var(--label3);display:flex;gap:7px;align-items:flex-start;transition:color .3s;">
              <i class="fa-solid fa-circle-info" style="color:var(--blue);margin-top:1px;flex-shrink:0"></i>
              <span>Only <b>one advance request per payroll period</b> is allowed. Requests above your advance limit cannot be sent; within the limit they may be approved automatically. Approved advances will be deducted from your salary.</span>
            </div>

            <button type="submit" class="dp-save-btn" id="saveBtn">
              <div class="btn-spinner" id="btnSpinner"></div>
              <i class="fa-solid fa-paper-plane" id="btnIcon"></i>
              <span id="btnLabel">Submit Request</span>
            </button>
          </form>

          <?php endif; ?>

        </div>
      </div>

    </div><!-- /sectionApply -->

    <!-- ══ HISTORY TAB ══ -->
    <div id="sectionHistory" class="section-hidden">

      <!-- Stats -->
      <div class="stats-row">
        <div class="stat-tile pend">
          <div class="stat-n"><?= $status_counts['Pending'] ?></div>
          <div class="stat-l">Pending</div>
        </div>
        <div class="stat-tile appr">
          <div class="stat-n"><?= $status_counts['Approved'] ?></div>
          <div class="stat-l">Approved</div>
        </div>
        <div class="stat-tile rej">
          <div class="stat-n"><?= $status_counts['Rejected'] ?></div>
          <div class="stat-l">Rejected</div>
        </div>
      </div>

      <!-- Approved amount summary -->
      <?php if ($status_amounts['Approved'] > 0): ?>
      <div style="margin:0 20px 14px;padding:14px 16px;border-radius:16px;background:rgba(52,199,89,.09);border:1px solid rgba(52,199,89,.22);display:flex;align-items:center;gap:12px;">
        <div style="width:38px;height:38px;border-radius:12px;background:rgba(52,199,89,.15);color:var(--green);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;">
          <i class="fa-solid fa-circle-check"></i>
        </div>
        <div>
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:var(--green);margin-bottom:2px;">Total Approved <?= $year_filter ?></div>
          <div style="font-size:20px;font-weight:900;letter-spacing:-.5px;color:var(--label);transition:color .3s;">LKR <?= number_format($status_amounts['Approved'], 2) ?></div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Year + Status filter -->
      <div class="filter-bar">
        <?php
        $cur_year = (int)date('Y');
        for ($y = $cur_year; $y >= $cur_year - 2; $y--):
        ?>
        <a href="?tab=history&year=<?= $y ?>&status=<?= urlencode($status_filter) ?>"
           class="filter-pill <?= $year_filter == $y ? 'active' : '' ?>">
          <?= $y ?>
        </a>
        <?php endfor; ?>
        <div style="flex:1"></div>
        <?php foreach (['all' => 'All', 'Pending' => 'Pending', 'Approved' => 'Approved', 'Rejected' => 'Rejected'] as $k => $v): ?>
        <a href="?tab=history&year=<?= $year_filter ?>&status=<?= urlencode($k) ?>"
           class="filter-pill <?= $status_filter === $k ? 'active' : '' ?>"
           style="font-size:11px;padding:0 10px;">
          <?= $v ?>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- History list -->
      <div class="hist-list">
        <?php if (empty($history)): ?>
        <div class="empty-state">
          <i class="fa-solid fa-hand-holding-dollar"></i>
          <strong>No requests found</strong>
          <span>No <?= $status_filter !== 'all' ? strtolower($status_filter).' ' : '' ?>advance requests in <?= $year_filter ?></span>
        </div>
        <?php else: ?>
        <?php foreach ($history as $i => $adv):
          $status     = $adv['status'] ?? 'Pending';
          $statusKey  = strtolower($status);
          $amount     = floatval($adv['amount']);
          $pct        = $basic_salary > 0 ? round($amount / $basic_salary * 100, 1) : 0;
          $pctClass   = $pct >= 75 ? 'pct-high' : ($pct >= 50 ? 'pct-med' : 'pct-ok');
          $statusIcon = $status === 'Approved' ? 'fa-circle-check'
                      : ($status === 'Rejected' ? 'fa-circle-xmark' : 'fa-clock');
          $periodLabel = $adv['period_month']
              ? $month_names[$adv['period_month']] . ' ' . $adv['period_year']
              : '—';
          $appliedOn = !empty($adv['created_at']) ? date('d M Y', strtotime($adv['created_at'])) : '—';
        ?>
        <div class="hist-item" style="animation-delay:<?= $i * .04 ?>s">
          <div class="hist-item-top">
            <div class="hist-icon <?= $statusKey ?>">
              <i class="fa-solid <?= $statusIcon ?>"></i>
            </div>
            <div class="hist-info">
              <div class="hist-amount">LKR <?= number_format($amount, 2) ?></div>
              <div class="hist-period">
                <i class="fa-solid fa-calendar" style="font-size:10px;margin-right:3px;"></i>
                <?= $adv['request_date'] ? date('d M Y', strtotime($adv['request_date'])) : '—' ?>
                &nbsp;·&nbsp; <?= htmlspecialchars($periodLabel) ?>
              </div>
            </div>
            <?php if ($basic_salary > 0 && $pct > 0): ?>
            <span class="hist-pct <?= $pctClass ?>"><?= $pct ?>%</span>
            <?php endif; ?>
          </div>
          <div class="hist-status-bar">
            <span class="status-pill <?= $statusKey ?>">
              <span class="status-dot"></span>
              <i class="fa-solid <?= $statusIcon ?>" style="font-size:10px"></i>
              <?= htmlspecialchars($status) ?>
            </span>
            <?php if (!empty($adv['reason'])): ?>
            <span class="hist-remark"><?= htmlspecialchars($adv['reason']) ?></span>
            <?php endif; ?>
            <button class="hist-expand-btn" onclick="toggleExtra(this)">
              <i class="fa-solid fa-chevron-down" style="font-size:9px;transition:transform .2s"></i> Details
            </button>
          </div>
          <div class="hist-extra" style="display:none">
            <div class="hist-extra-grid">
              <div class="extra-cell">
                <div class="extra-lbl">Amount</div>
                <div class="extra-val">LKR <?= number_format($amount, 2) ?></div>
              </div>
              <div class="extra-cell">
                <div class="extra-lbl">Payroll Period</div>
                <div class="extra-val"><?= htmlspecialchars($periodLabel) ?></div>
              </div>
              <div class="extra-cell">
                <div class="extra-lbl">Request Date</div>
                <div class="extra-val"><?= $adv['request_date'] ? date('d M Y', strtotime($adv['request_date'])) : '—' ?></div>
              </div>
              <div class="extra-cell">
                <div class="extra-lbl">Status</div>
                <div class="extra-val" style="color:<?= $status==='Approved'?'var(--green)':($status==='Rejected'?'var(--red)':'var(--orange)') ?>"><?= htmlspecialchars($status) ?></div>
              </div>
              <?php if (!empty($adv['approved_by'])): ?>
              <div class="extra-cell">
                <div class="extra-lbl"><?= $status === 'Rejected' ? 'Rejected By' : 'Approved By' ?></div>
                <div class="extra-val"><?= htmlspecialchars($adv['approved_by']) ?></div>
              </div>
              <?php endif; ?>
              <?php if (!empty($adv['approved_at'])): ?>
              <div class="extra-cell">
                <div class="extra-lbl"><?= $status === 'Rejected' ? 'Rejected On' : 'Approved On' ?></div>
                <div class="extra-val"><?= date('d M Y', strtotime($adv['approved_at'])) ?></div>
              </div>
              <?php endif; ?>
              <?php if (!empty($adv['reason'])): ?>
              <div class="extra-cell" style="grid-column:1/-1">
                <div class="extra-lbl">Reason</div>
                <div class="extra-val"><?= htmlspecialchars($adv['reason']) ?></div>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>

    </div><!-- /sectionHistory -->

  </div><!-- /scroll -->

  <div class="home-bar-wrap"><div class="home-bar"></div></div>
</div><!-- /shell -->

<div class="toast" id="toast"></div>

<script>
/* ── THEME ── */
(function(){
  var html=document.documentElement,btn=document.getElementById('themeBtn'),icon=document.getElementById('themeIcon');
  function apply(t){html.setAttribute('data-theme',t);icon.className=t==='dark'?'fa-solid fa-sun':'fa-solid fa-moon';localStorage.setItem('yelo_theme',t);}
  var s=localStorage.getItem('yelo_theme');
  if(s) apply(s);
  else if(window.matchMedia('(prefers-color-scheme:dark)').matches) apply('dark');
  btn.addEventListener('click',function(){apply(html.getAttribute('data-theme')==='dark'?'light':'dark');});
})();

/* ── TAB SWITCHING ── */
function switchTab(t) {
  document.getElementById('sectionApply').classList.toggle('section-hidden',   t !== 'apply');
  document.getElementById('sectionHistory').classList.toggle('section-hidden', t !== 'history');
  document.getElementById('tabApply').classList.toggle('active',   t === 'apply');
  document.getElementById('tabHistory').classList.toggle('active', t !== 'apply');
}
(function(){
  var p = new URLSearchParams(window.location.search);
  if (p.get('tab') === 'history') switchTab('history');
})();

/* ── PERCENTAGE CALC ── */
var basicSalary = <?= $basic_salary > 0 ? $basic_salary : 0 ?>;

function calcPct() {
  var amount = parseFloat(document.getElementById('amountInput').value) || 0;
  var bar    = document.getElementById('pctBar');
  var fill   = document.getElementById('pctFill');
  var val    = document.getElementById('pctVal');
  var warn   = document.getElementById('pctWarn');

  if (basicSalary <= 0 || amount <= 0) { bar.style.display = 'none'; return; }

  var pct    = (amount / basicSalary) * 100;
  var capped = Math.min(pct, 100);
  var color  = pct >= 75 ? 'var(--red)' : (pct >= 50 ? 'var(--orange)' : 'var(--green)');

  bar.style.display    = 'block';
  fill.style.width     = capped + '%';
  fill.style.background = color;
  val.textContent      = pct.toFixed(1) + '%';
  val.style.color      = color;
  warn.style.display   = pct >= 75 ? 'flex' : 'none';
}

/* ── ONE REQUEST PER PAYROLL PERIOD + ADVANCE LIMIT ── */
var PERIOD_INFO = <?= json_encode($period_info) ?>;
function saFmt(n){ return Number(n||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function saCheck() {
  var box = document.getElementById('saInfo'), sel = document.getElementById('periodSel'), btn = document.getElementById('saveBtn');
  if (!box || !sel) return;
  var pi = PERIOD_INFO[sel.value], amt = parseFloat((document.getElementById('amountInput')||{}).value) || 0;
  var red = 'background:rgba(255,59,48,.09);border:1px solid rgba(255,59,48,.22);color:var(--red);', grn = 'background:rgba(52,199,89,.09);border:1px solid rgba(52,199,89,.22);color:var(--green);';
  box.style.display = 'none'; if (btn) btn.disabled = false;
  if (!pi) return;
  if (pi.existing) {
    box.setAttribute('style', box.getAttribute('style').replace(/background[^;]*;border[^;]*;color[^;]*;/g,'') + red); box.style.display = 'block';
    box.innerHTML = '<i class="fa-solid fa-ban"></i> Already requested for <b>' + pi.label + '</b>: LKR ' + saFmt(pi.existing.amount) + ' on ' + pi.existing.date + ' (<b>' + pi.existing.status + '</b>). Only one request per payroll period.';
    if (btn) btn.disabled = true; return;
  }
  if (pi.limit !== null) {
    var over = amt > pi.left + 0.005, how = pi.type === 'rate' ? (+pi.value) + '% of basic' : 'fixed';
    box.setAttribute('style', box.getAttribute('style').replace(/background[^;]*;border[^;]*;color[^;]*;/g,'') + (over ? red : grn)); box.style.display = 'block';
    box.innerHTML = over
      ? '<i class="fa-solid fa-ban"></i> Over your advance limit. Limit LKR ' + saFmt(pi.limit) + ' (' + how + ') · you can request up to <b>LKR ' + saFmt(pi.left) + '</b>.'
      : '<i class="fa-solid fa-circle-check"></i> Advance limit LKR ' + saFmt(pi.limit) + ' (' + how + ') · you can request up to <b>LKR ' + saFmt(pi.left) + '</b>' + (pi.auto ? ' · approved automatically' : ' · goes for approval') + '.';
    if (btn && over) btn.disabled = true;
  }
}
document.addEventListener('DOMContentLoaded', saCheck); saCheck();

/* ── FORM SUBMIT ── */
var advForm = document.getElementById('advanceForm');
if (advForm) {
  advForm.addEventListener('submit', function() {
    var btn = document.getElementById('saveBtn');
    btn.disabled = true;
    document.getElementById('btnSpinner').style.display = 'block';
    document.getElementById('btnIcon').style.display    = 'none';
    document.getElementById('btnLabel').textContent     = 'Submitting…';
  });
}

/* ── TOGGLE EXTRA ── */
function toggleExtra(btn) {
  var item  = btn.closest('.hist-item');
  var extra = item.querySelector('.hist-extra');
  var open  = extra.style.display === 'block';
  extra.style.display = open ? 'none' : 'block';
  var icon = btn.querySelector('i');
  icon.style.transform  = open ? 'rotate(0deg)' : 'rotate(180deg)';
  icon.style.transition = 'transform .2s';
}

/* ── TOAST ── */
var _tt;
function showToast(msg, type) {
  var t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast' + (type ? ' ' + type : '');
  void t.offsetWidth;
  t.classList.add('show');
  clearTimeout(_tt);
  _tt = setTimeout(function(){ t.classList.remove('show'); }, 3000);
}

/* ── INIT ── */
window.addEventListener('DOMContentLoaded', function() {
  calcPct();
  <?php if ($save_success): ?>
  showToast('<?= addslashes($save_success) ?>', 'ok');
  <?php endif; ?>
  <?php if ($save_error): ?>
  showToast('<?= addslashes($save_error) ?>', 'error');
  <?php endif; ?>
});
</script>
</body>
</html>