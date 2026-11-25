<?php
/**
 * salary_advance_limit_lib.php — designation-wise salary advance limit (set in Salary Configuration)
 *   limit_type 'amount' → a fixed amount per payroll period
 *   limit_type 'rate'   → % of the employee's basic salary per payroll period
 *   auto_approve 1      → a request within the limit is approved straight away
 * An employee cannot have advances (not Rejected / Cancelled) in one payroll period above the limit.
 * Called directly with ?emp=ID&period=ID[&exclude=ID] it answers JSON for the request forms.
 */
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

/* JSON for the request forms: salary_advance_limit_lib.php?emp=..&period=..&amount=..&exclude=.. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__) && isset($_GET['emp'])) {
    include_once __DIR__ . '/config.php';
    header('Content-Type: application/json');
    $i = sal_adv_info($conn, (int)$_GET['emp']);
    $c = sal_adv_check($conn, (int)$_GET['emp'], (float)($_GET['amount'] ?? 0), (int)($_GET['period'] ?? 0), (int)($_GET['exclude'] ?? 0));
    echo json_encode(['ok' => true, 'has_limit' => $i && $i['limit'] !== null, 'limit' => $c['limit'], 'used' => $c['used'], 'left' => $c['left'], 'auto' => $c['auto'] || ($i['auto'] ?? false),
                      'type' => $i['type'] ?? '', 'value' => $i['value'] ?? 0, 'basic' => $i['basic'] ?? 0, 'over' => !$c['ok'], 'msg' => $c['msg']]);
    exit;
}
