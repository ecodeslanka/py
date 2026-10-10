<?php
ob_start();
include 'config.php';

// ── Ensure st_salary_sheet_entries table ──────────────────────────────────────
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS st_salary_sheet_entries (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    payroll_period_id       INT NOT NULL,
    employee_id             INT NOT NULL,
    employee_code           VARCHAR(50),
    employee_name           VARCHAR(200),
    epf_number              VARCHAR(50),
    date_of_join            DATE,
    status                  VARCHAR(50),
    category_code           VARCHAR(50),
    designation_name        VARCHAR(200),
    company_code            VARCHAR(50),
    branch_code             VARCHAR(50),
    att_count               INT DEFAULT 0,
    norm_days               INT DEFAULT 0,
    holidays                INT DEFAULT 0,
    att_rate                DECIMAL(6,2) DEFAULT 0,
    per_day                 DECIMAL(12,4) DEFAULT 0,
    no_pay_days             DECIMAL(8,2) DEFAULT 0,
    no_pay_amount           DECIMAL(12,2) DEFAULT 0,
    att_allow               DECIMAL(12,2) DEFAULT 0,
    att_allow_tier          VARCHAR(10) DEFAULT '',
    basic                   DECIMAL(12,2) DEFAULT 0,
    stores_damage_amt       DECIMAL(12,2) DEFAULT 0,
    rsqm_ach                DECIMAL(6,2)  DEFAULT 0,
    rsqm_amt                DECIMAL(12,2) DEFAULT 0,
    punctuality_ach         DECIMAL(6,2)  DEFAULT 0,
    punctuality_amt         DECIMAL(12,2) DEFAULT 0,
    loading_unloading_ach   DECIMAL(6,2)  DEFAULT 0,
    loading_unloading_amt   DECIMAL(12,2) DEFAULT 0,
    ccfot_ach               DECIMAL(6,2)  DEFAULT 0,
    ccfot_amt               DECIMAL(12,2) DEFAULT 0,
    meal                    DECIMAL(12,2) DEFAULT 0,
    arrears                 DECIMAL(12,2) DEFAULT 0,
    total_earnings          DECIMAL(12,2) DEFAULT 0,
    epf_emp                 DECIMAL(12,2) DEFAULT 0,
    welfare_amt             DECIMAL(12,2) DEFAULT 0,
    w_loan                  DECIMAL(12,2) DEFAULT 0,
    w_soc                   DECIMAL(12,2) DEFAULT 0,
    donations               DECIMAL(12,2) DEFAULT 0,
    sal_adv                 DECIMAL(12,2) DEFAULT 0,
    advance_from_entry      TINYINT(1) DEFAULT 0,
    loan_ded                DECIMAL(12,2) DEFAULT 0,
    credit_r                DECIMAL(12,2) DEFAULT 0,
    retention               DECIMAL(12,2) DEFAULT 0,
    excess_p                DECIMAL(12,2) DEFAULT 0,
    total_deductions        DECIMAL(12,2) DEFAULT 0,
    net_salary              DECIMAL(12,2) DEFAULT 0,
    epf_er                  DECIMAL(12,2) DEFAULT 0,
    etf_er                  DECIMAL(12,2) DEFAULT 0,
    insurance               DECIMAL(12,2) DEFAULT 0,
    bonus                   DECIMAL(12,2) DEFAULT 0,
    gratuity                DECIMAL(12,2) DEFAULT 0,
    cost_bp                 DECIMAL(12,2) DEFAULT 0,
    total_benefit           DECIMAL(12,2) DEFAULT 0,
    override_excess         DECIMAL(12,2) DEFAULT NULL,
    override_arrears        DECIMAL(12,2) DEFAULT NULL,
    additional_earnings     TEXT DEFAULT NULL,
    additional_deductions   TEXT DEFAULT NULL,
    sheet_status            VARCHAR(20) DEFAULT 'Pending',
    created_at              DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_period_emp (payroll_period_id, employee_id),
    INDEX idx_period (payroll_period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Safe column upgrades
$upgrade_cols = [
    "ALTER TABLE st_salary_sheet_entries ADD COLUMN IF NOT EXISTS att_allow       DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE st_salary_sheet_entries ADD COLUMN IF NOT EXISTS att_allow_tier  VARCHAR(10) DEFAULT ''",
    "ALTER TABLE st_salary_sheet_entries ADD COLUMN IF NOT EXISTS additional_earnings   TEXT DEFAULT NULL",
    "ALTER TABLE st_salary_sheet_entries ADD COLUMN IF NOT EXISTS additional_deductions TEXT DEFAULT NULL",
    "ALTER TABLE st_salary_sheet_entries ADD COLUMN IF NOT EXISTS sheet_status VARCHAR(20) DEFAULT 'Pending'",
];
foreach ($upgrade_cols as $sql) @mysqli_query($conn, $sql);

// Loan tables (shared)
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS loan_requests (
    id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL,
    request_date DATE, payroll_period_id INT, loan_type VARCHAR(100),
    loan_amount DECIMAL(12,2) DEFAULT 0, no_of_instalments INT DEFAULT 1,
    monthly_instalment DECIMAL(12,2) DEFAULT 0, interest_rate DECIMAL(8,4) DEFAULT 0,
    interest_frequency VARCHAR(20) DEFAULT 'Monthly', total_interest DECIMAL(12,2) DEFAULT 0,
    total_repayment DECIMAL(12,2) DEFAULT 0, reason TEXT, status VARCHAR(30) DEFAULT 'Pending',
    created_by VARCHAR(100), created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_employee (employee_id), INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS loan_payments (
    id INT AUTO_INCREMENT PRIMARY KEY, loan_request_id INT NOT NULL,
    employee_id INT NOT NULL, payroll_period_id INT NOT NULL, payment_date DATE,
    instalment_number INT DEFAULT 1, principal_paid DECIMAL(12,2) DEFAULT 0,
    interest_paid DECIMAL(12,2) DEFAULT 0, total_paid DECIMAL(12,2) DEFAULT 0,
    balance_before DECIMAL(12,2) DEFAULT 0, balance_after DECIMAL(12,2) DEFAULT 0,
    notes TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_loan (loan_request_id), INDEX idx_employee (employee_id),
    INDEX idx_period (payroll_period_id),
    UNIQUE KEY uq_loan_period (loan_request_id, payroll_period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ── AJAX endpoints ─────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // DELETE SHEET
    if ($_GET['ajax'] === 'delete_sheet' && isset($_GET['period_id'])) {
        $pid = intval($_GET['period_id']);
        $chk = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT sheet_status FROM st_salary_sheet_entries WHERE payroll_period_id=$pid LIMIT 1"));
        if ($chk && $chk['sheet_status'] === 'Confirmed') {
            echo json_encode(['ok' => false, 'msg' => 'Cannot delete a Confirmed salary sheet.']); exit;
        }
        mysqli_query($conn, "DELETE FROM st_salary_sheet_entries WHERE payroll_period_id=$pid");
        echo json_encode(['ok' => true, 'deleted' => mysqli_affected_rows($conn)]); exit;
    }

    // CONFIRM SHEET — locks it and records loan payments
    if ($_GET['ajax'] === 'confirm_sheet' && isset($_GET['period_id'])) {
        $pid = intval($_GET['period_id']);
        $chk = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS cnt FROM st_salary_sheet_entries WHERE payroll_period_id=$pid AND sheet_status='Confirmed'"));
        if (intval($chk['cnt']) > 0) { echo json_encode(['ok' => false, 'msg' => 'Sheet already confirmed.']); exit; }
        $emp_res = mysqli_query($conn, "SELECT employee_id FROM st_salary_sheet_entries WHERE payroll_period_id=$pid");
        $emp_ids = [];
        while ($er = mysqli_fetch_assoc($emp_res)) $emp_ids[] = intval($er['employee_id']);
        if (empty($emp_ids)) { echo json_encode(['ok' => false, 'msg' => 'No employees in sheet.']); exit; }
        $payment_date = date('Y-m-d'); $payments_created = 0; $errors = [];
        foreach ($emp_ids as $eid) {
            $loan_res = mysqli_query($conn,
                "SELECT lr.*, COALESCE((SELECT SUM(lp.total_paid) FROM loan_payments lp WHERE lp.loan_request_id=lr.id),0) AS paid_so_far
                 FROM loan_requests lr WHERE lr.employee_id=$eid AND lr.status='Approved' ORDER BY lr.id");
            while ($loan = mysqli_fetch_assoc($loan_res)) {
                $loan_id        = intval($loan['id']);
                $total_repayment= floatval($loan['total_repayment']) ?: floatval($loan['loan_amount']);
                $paid_so_far    = floatval($loan['paid_so_far']);
                $balance_before = round($total_repayment - $paid_so_far, 2);
                if ($balance_before <= 0) continue;
                $already = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT id FROM loan_payments WHERE loan_request_id=$loan_id AND payroll_period_id=$pid"));
                if ($already) continue;
                $monthly_inst   = floatval($loan['monthly_instalment']);
                $this_payment   = min($monthly_inst, $balance_before);
                $balance_after  = round($balance_before - $this_payment, 2);
                $inst_num = intval(mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT COUNT(*) AS cnt FROM loan_payments WHERE loan_request_id=$loan_id"))['cnt']) + 1;
                $no_inst     = max(1, intval($loan['no_of_instalments']));
                $interest_per= round(floatval($loan['total_interest']) / $no_inst, 2);
                $principal_paid = round($this_payment - $interest_per, 2);
                if ($principal_paid < 0) { $interest_per = $this_payment; $principal_paid = 0; }
                $ins = mysqli_query($conn, "INSERT INTO loan_payments
                    (loan_request_id,employee_id,payroll_period_id,payment_date,instalment_number,
                     principal_paid,interest_paid,total_paid,balance_before,balance_after)
                    VALUES ($loan_id,$eid,$pid,'$payment_date',$inst_num,$principal_paid,$interest_per,
                    $this_payment,$balance_before,$balance_after)");
                if ($ins) {
                    $payments_created++;
                    if ($balance_after <= 0) mysqli_query($conn, "UPDATE loan_requests SET status='Completed' WHERE id=$loan_id");
                } else { $errors[] = "Loan #$loan_id: ".mysqli_error($conn); }
            }
        }
        mysqli_query($conn, "UPDATE st_salary_sheet_entries SET sheet_status='Confirmed' WHERE payroll_period_id=$pid");
        echo json_encode(['ok' => true, 'payments_created' => $payments_created, 'errors' => $errors]); exit;
    }

    // UPDATE OVERRIDES (arrears, excess, additional rows)
    if ($_GET['ajax'] === 'update_overrides' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body     = json_decode(file_get_contents('php://input'), true);
        $pid      = intval($body['period_id']   ?? 0);
        $eid      = intval($body['employee_id'] ?? 0);
        $excess   = isset($body['excess'])  ? floatval($body['excess'])  : null;
        $arrears  = isset($body['arrears']) ? floatval($body['arrears']) : null;
        $add_earn = isset($body['additional_earnings'])   ? $body['additional_earnings']   : null;
        $add_ded  = isset($body['additional_deductions']) ? $body['additional_deductions'] : null;
        if ($pid && $eid) {
            $row = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT * FROM st_salary_sheet_entries WHERE payroll_period_id=$pid AND employee_id=$eid"));
            if ($row) {
                if ($row['sheet_status'] === 'Confirmed') {
                    echo json_encode(['ok' => false, 'msg' => 'Sheet is confirmed. No edits allowed.']); exit;
                }
                $new_excess  = $excess  !== null ? $excess  : floatval($row['excess_p']);
                $new_arrears = $arrears !== null ? $arrears : floatval($row['arrears']);
                $add_earn_total = 0; $add_ded_total = 0;
                if (is_array($add_earn)) foreach ($add_earn as $ae) $add_earn_total += floatval($ae['amount'] ?? 0);
                if (is_array($add_ded))  foreach ($add_ded  as $ad) $add_ded_total  += floatval($ad['amount'] ?? 0);

                $new_total_earnings = floatval($row['basic'])
                    + floatval($row['att_allow'])
                    + floatval($row['stores_damage_amt'])
                    + floatval($row['rsqm_amt'])
                    + floatval($row['punctuality_amt'])
                    + floatval($row['loading_unloading_amt'])
                    + floatval($row['ccfot_amt'])
                    + floatval($row['meal'])
                    + $new_arrears + $add_earn_total;

                $new_total_ded = floatval($row['epf_emp'])
                    + floatval($row['welfare_amt']) + floatval($row['w_loan'])
                    + floatval($row['w_soc'])       + floatval($row['donations'])
                    + floatval($row['sal_adv'])     + floatval($row['loan_ded'])
                    + floatval($row['credit_r'])    + floatval($row['no_pay_amount'])
                    + floatval($row['retention'])   + $new_excess + $add_ded_total;

                $new_net = $new_total_earnings - $new_total_ded;
                $new_cost_bp = floatval($row['epf_er']) + floatval($row['etf_er'])
                    + floatval($row['meal']) + floatval($row['bonus']) + floatval($row['basic']);
                $new_total_benefit = floatval($row['basic'])
                    + floatval($row['att_allow'])
                    + floatval($row['rsqm_amt'])        + floatval($row['punctuality_amt'])
                    + floatval($row['loading_unloading_amt']) + floatval($row['ccfot_amt'])
                    + floatval($row['meal'])
                    + floatval($row['insurance'])       + floatval($row['epf_er'])
                    + floatval($row['etf_er'])          + floatval($row['bonus'])
                    + floatval($row['gratuity']);

                $esc_add_earn = $add_earn !== null
                    ? "'".mysqli_real_escape_string($conn, json_encode($add_earn))."'"
                    : "additional_earnings";
                $esc_add_ded  = $add_ded  !== null
                    ? "'".mysqli_real_escape_string($conn, json_encode($add_ded)) ."'"
                    : "additional_deductions";

                mysqli_query($conn, "UPDATE st_salary_sheet_entries SET
                    arrears=$new_arrears, excess_p=$new_excess,
                    total_earnings=$new_total_earnings, total_deductions=$new_total_ded,
                    net_salary=$new_net, cost_bp=$new_cost_bp, total_benefit=$new_total_benefit,
                    override_excess=".($excess  !== null ? $new_excess  : 'override_excess').",
                    override_arrears=".($arrears !== null ? $new_arrears : 'override_arrears').",
                    additional_earnings=$esc_add_earn, additional_deductions=$esc_add_ded
                    WHERE payroll_period_id=$pid AND employee_id=$eid");

                echo json_encode(['ok' => true,
                    'total_earnings'   => $new_total_earnings,
                    'total_deductions' => $new_total_ded,
                    'net_salary'       => $new_net,
                    'cost_bp'          => $new_cost_bp,
                    'total_benefit'    => $new_total_benefit,
                    'add_earn_total'   => $add_earn_total,
                    'add_ded_total'    => $add_ded_total,
                ]); exit;
            }
        }
        echo json_encode(['ok' => false, 'msg' => 'Row not found']); exit;
    }

    if ($_GET['ajax'] === 'check_sheet' && isset($_GET['period_id'])) {
        $pid = intval($_GET['period_id']);
        $cnt = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS cnt FROM st_salary_sheet_entries WHERE payroll_period_id=$pid"));
        echo json_encode(['exists' => intval($cnt['cnt']) > 0, 'count' => intval($cnt['cnt'])]); exit;
    }
    if ($_GET['ajax'] === 'load_sheet' && isset($_GET['period_id'])) {
        $pid  = intval($_GET['period_id']);
        $res  = mysqli_query($conn, "SELECT * FROM st_salary_sheet_entries WHERE payroll_period_id=$pid ORDER BY company_code,employee_code");
        $data = [];
        if ($res) while ($r = mysqli_fetch_assoc($res)) $data[] = $r;
        echo json_encode($data); exit;
    }
    exit;
}

// ── POST: save sheet ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_sheet') {
    $pid     = intval($_POST['period_id'] ?? 0);
    $payload = json_decode($_POST['rows_json'] ?? '[]', true);
    if ($pid && !empty($payload)) {
        foreach ($payload as $row) {
            $eid = intval($row['employee_db_id']);
            $map = [
                'payroll_period_id'    => $pid,
                'employee_id'          => $eid,
                'employee_code'        => $row['code']         ?? '',
                'employee_name'        => $row['name']         ?? '',
                'epf_number'           => $row['epf']          ?? '',
                'date_of_join'         => $row['join_raw']     ?? null,
                'status'               => $row['status']       ?? '',
                'category_code'        => 'ST',
                'designation_name'     => $row['desig']        ?? '',
                'company_code'         => $row['company_code'] ?? '',
                'branch_code'          => $row['branch_code']  ?? '',
                'att_count'            => floatval($row['att_count']            ?? 0),
                'norm_days'            => floatval($row['norm_days']            ?? 0),
                'holidays'             => floatval($row['holidays']             ?? 0),
                'att_rate'             => floatval($row['att_rate']             ?? 0),
                'per_day'              => floatval($row['per_day']              ?? 0),
                'no_pay_days'          => floatval($row['no_pay_days']          ?? 0),
                'no_pay_amount'        => floatval($row['no_pay_amount']        ?? 0),
                'att_allow'            => floatval($row['att_allow']            ?? 0),
                'att_allow_tier'       => $row['att_allow_tier'] ?? '',
                'basic'                => floatval($row['basic']                ?? 0),
                'stores_damage_amt'    => floatval($row['stores_damage_amt']    ?? 0),
                'rsqm_ach'             => floatval($row['rsqm_ach']             ?? 0),
                'rsqm_amt'             => floatval($row['rsqm_amt']             ?? 0),
                'punctuality_ach'      => floatval($row['punctuality_ach']      ?? 0),
                'punctuality_amt'      => floatval($row['punctuality_amt']      ?? 0),
                'loading_unloading_ach'=> floatval($row['loading_unloading_ach']?? 0),
                'loading_unloading_amt'=> floatval($row['loading_unloading_amt']?? 0),
                'ccfot_ach'            => floatval($row['ccfot_ach']            ?? 0),
                'ccfot_amt'            => floatval($row['ccfot_amt']            ?? 0),
                'meal'                 => floatval($row['meal']                 ?? 0),
                'arrears'              => floatval($row['arrears']              ?? 0),
                'total_earnings'       => floatval($row['total_earnings']       ?? 0),
                'epf_emp'              => floatval($row['epf_emp']              ?? 0),
                'welfare_amt'          => floatval($row['welfare_amt']          ?? 0),
                'w_loan'               => floatval($row['w_loan']               ?? 0),
                'w_soc'                => floatval($row['w_soc']                ?? 0),
                'donations'            => floatval($row['donations']            ?? 0),
                'sal_adv'              => floatval($row['sal_adv']              ?? 0),
                'advance_from_entry'   => intval($row['advance_from_entry']     ?? 0),
                'loan_ded'             => floatval($row['loan_ded']             ?? 0),
                'credit_r'             => floatval($row['credit_r']             ?? 0),
                'retention'            => floatval($row['retention']            ?? 0),
                'excess_p'             => floatval($row['excess_p']             ?? 0),
                'total_deductions'     => floatval($row['total_deductions']     ?? 0),
                'net_salary'           => floatval($row['net_salary']           ?? 0),
                'epf_er'               => floatval($row['epf_er']               ?? 0),
                'etf_er'               => floatval($row['etf_er']               ?? 0),
                'insurance'            => floatval($row['insurance']            ?? 0),
                'bonus'                => floatval($row['bonus']                ?? 0),
                'gratuity'             => floatval($row['gratuity']             ?? 0),
                'cost_bp'              => floatval($row['cost_bp']              ?? 0),
                'total_benefit'        => floatval($row['total_benefit']        ?? 0),
                'override_excess'      => null,
                'override_arrears'     => null,
                'additional_earnings'  => null,
                'additional_deductions'=> null,
                'sheet_status'         => 'Pending',
            ];
            $ins_keys = $ins_vals = $upd_parts = [];
            foreach ($map as $k => $v) {
                $ins_keys[] = "`$k`";
                $esc = ($v === null) ? 'NULL' : "'".mysqli_real_escape_string($conn, (string)$v)."'";
                $ins_vals[] = $esc;
                if (!in_array($k, ['payroll_period_id', 'employee_id', 'sheet_status']))
                    $upd_parts[] = "`$k` = $esc";
            }
            $upd_parts[] = "`updated_at` = NOW()";
            mysqli_query($conn,
                "INSERT INTO st_salary_sheet_entries (".implode(',', $ins_keys).")
                 VALUES (".implode(',', $ins_vals).")
                 ON DUPLICATE KEY UPDATE ".implode(',', $upd_parts));
        }
        header("Location: st_salary.php?period_id=$pid&sheet=saved"); exit;
    }
}

// ── Payroll period ─────────────────────────────────────────────────────────────
$sel_period_id = isset($_GET['period_id']) ? intval($_GET['period_id']) : 0;
$all_periods = [];
$pp_res = mysqli_query($conn, "SELECT id,year,month,status FROM payroll_periods ORDER BY year DESC,month DESC");
if ($pp_res) while ($p = mysqli_fetch_assoc($pp_res)) $all_periods[] = $p;
if (!$sel_period_id) {
    foreach ($all_periods as $pp) {
        if (($pp['status'] ?? '') === 'Open') { $sel_period_id = $pp['id']; break; }
    }
    if (!$sel_period_id && !empty($all_periods)) $sel_period_id = $all_periods[0]['id'];
}
$active_period = null;
foreach ($all_periods as $pp) { if ($pp['id'] == $sel_period_id) { $active_period = $pp; break; } }

// ── Saved sheet status ─────────────────────────────────────────────────────────
$saved_sheet_count = 0; $saved_sheet_status = 'Pending';
if ($sel_period_id) {
    $sc = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) AS cnt, MAX(sheet_status) AS sstatus
         FROM st_salary_sheet_entries WHERE payroll_period_id=$sel_period_id"));
    $saved_sheet_count  = intval($sc['cnt']);
    $saved_sheet_status = $sc['sstatus'] ?? 'Pending';
}
$has_saved_sheet = $saved_sheet_count > 0;
$is_confirmed    = $saved_sheet_status === 'Confirmed';

// ── Attendance helpers ─────────────────────────────────────────────────────────
if ($active_period) {
    $pp_year  = (int)$active_period['year'];
    $pp_month = (int)$active_period['month'];
    $from_date        = sprintf('%04d-%02d-01', $pp_year, $pp_month);
    $to_date          = date('Y-m-t', strtotime($from_date));
    $month_total_days = (int)date('t', strtotime($from_date));

    $sh_res  = mysqli_query($conn,
        "SELECT * FROM special_holidays WHERE active=1 AND date_from<='$to_date' AND date_to>='$from_date'");
    $sh_rows = [];
    if ($sh_res) while ($sh = mysqli_fetch_assoc($sh_res)) $sh_rows[] = $sh;
    $sh_date_map = [];
    foreach ($sh_rows as $sh) {
        $start = new DateTime(max($sh['date_from'], $from_date));
        $end   = new DateTime(min($sh['date_to'],   $to_date));
        $end->modify('+1 day');
        foreach (new DatePeriod($start, new DateInterval('P1D'), $end) as $dt) {
            $d = $dt->format('Y-m-d');
            if (!isset($sh_date_map[$d])) $sh_date_map[$d] = [];
            $sh_date_map[$d][] = $sh;
        }
    }
    $att_res = mysqli_query($conn,
        "SELECT employee_id, COUNT(*) AS cnt FROM attendance
         WHERE att_date BETWEEN '$from_date' AND '$to_date' GROUP BY employee_id");
    $att_count_map = [];
    if ($att_res) while ($r = mysqli_fetch_assoc($att_res)) $att_count_map[(int)$r['employee_id']] = (int)$r['cnt'];

    $GLOBALS['_st_sh_date_map']    = $sh_date_map;
    $GLOBALS['_st_att_count_map']  = $att_count_map;
    $GLOBALS['_st_month_total']    = $month_total_days;
}

function st_calc_att($emp, $sh_date_map, $att_count_map, $month_total_days) {
    $eid       = (int)$emp['id'];
    $att_count = $att_count_map[$eid] ?? 0;
    $holidays  = 0;
    foreach ($sh_date_map as $date => $shs) {
        foreach ($shs as $sh) {
            $applies = false;
            if ($sh['target_type'] === 'all') { $applies = true; }
            elseif (!empty($sh['target_ids'])) {
                $ids = json_decode($sh['target_ids'], true) ?? [];
                if ($sh['target_type'] === 'employee'       && in_array($eid,                                $ids)) $applies = true;
                if ($sh['target_type'] === 'staff_category' && in_array((int)($emp['staff_category_id']??0), $ids)) $applies = true;
                if ($sh['target_type'] === 'designation'    && in_array((int)($emp['designation_id']??0),    $ids)) $applies = true;
            }
            if ($applies) { $holidays++; break; }
        }
    }
    $norm_days = max(1, $month_total_days - $holidays);
    $rate      = round(($att_count / $norm_days) * 100, 2);
    return ['att' => $att_count, 'norm' => $norm_days, 'holidays' => $holidays, 'rate' => $rate];
}

// ── EPF / ETF settings ─────────────────────────────────────────────────────────
$epf_settings      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM epf_etf_settings LIMIT 1"));
$epf_employer_rate = $epf_settings ? floatval($epf_settings['epf_employer']) : 12;
$epf_employee_rate = $epf_settings ? floatval($epf_settings['epf_employee']) : 8;
$etf_employer_rate = $epf_settings ? floatval($epf_settings['etf_employer']) : 3;

// ── Fetch ST category & employees ──────────────────────────────────────────────
$st_cat_res  = mysqli_query($conn, "SELECT id FROM staff_categories WHERE category_code='ST' AND active=1");
$st_cat_ids  = [];
if ($st_cat_res) while ($r = mysqli_fetch_assoc($st_cat_res)) $st_cat_ids[] = (int)$r['id'];
$st_desig_ids = [];
if (!empty($st_cat_ids)) {
    $cat_in   = implode(',', $st_cat_ids);
    $dsig_res = mysqli_query($conn, "SELECT id FROM designations WHERE staff_category_id IN ($cat_in) AND active=1");
    if ($dsig_res) while ($r = mysqli_fetch_assoc($dsig_res)) $st_desig_ids[] = (int)$r['id'];
}
$where_parts = [];
if (!empty($st_cat_ids))   $where_parts[] = "e.staff_category_id IN (".implode(',', $st_cat_ids).")";
if (!empty($st_desig_ids)) $where_parts[] = "e.designation_id IN (".implode(',', $st_desig_ids).")";
if (empty($where_parts))   $where_parts[] = "EXISTS (SELECT 1 FROM staff_categories sc2 WHERE sc2.id=e.staff_category_id AND sc2.category_code='ST')";
$where_sql = "(".implode(' OR ', $where_parts).") AND e.active=1";

$filter_status  = !empty($_GET['filter_status'])  ? mysqli_real_escape_string($conn, $_GET['filter_status'])  : '';
$filter_company = !empty($_GET['filter_company']) ? intval($_GET['filter_company']) : '';
$filter_branch  = !empty($_GET['filter_branch'])  ? intval($_GET['filter_branch'])  : '';
if ($filter_status)  $where_sql .= " AND e.status='$filter_status'";
if ($filter_company) $where_sql .= " AND e.company_id=$filter_company";
if ($filter_branch)  $where_sql .= " AND e.branch_id=$filter_branch";

$result    = mysqli_query($conn, "
    SELECT e.*, c.company_name, c.company_code, b.branch_name, b.branch_code,
           d.designation_name, d.designation_code, sc.category_name, sc.category_code
    FROM employees e
    LEFT JOIN companies        c  ON e.company_id        = c.id
    LEFT JOIN branches         b  ON e.branch_id         = b.id
    LEFT JOIN designations     d  ON e.designation_id    = d.id
    LEFT JOIN staff_categories sc ON e.staff_category_id = sc.id
    WHERE $where_sql ORDER BY c.company_code, e.employee_id");
$employees = [];
while ($row = mysqli_fetch_assoc($result)) $employees[] = $row;

// ── ST salary config (from sr_salary_config) ───────────────────────────────────
// ST attendance tiers: attendance_85 → amount if att_rate ≥ 85%
//                      attendance_90 → amount if att_rate ≥ 90%  (takes priority)
$st_salary_cfg = [];
if (!empty($employees)) {
    $desig_ids = array_unique(array_filter(array_column($employees, 'designation_id')));
    if (!empty($desig_ids)) {
        $did_in  = implode(',', array_map('intval', $desig_ids));
        $cfg_res = mysqli_query($conn, "SELECT * FROM sr_salary_config WHERE designation_id IN ($did_in)");
        if ($cfg_res) while ($row = mysqli_fetch_assoc($cfg_res)) $st_salary_cfg[$row['designation_id']] = $row;
    }
}

// ── Incentive entries (ST schema from incentive_entry.php) ─────────────────────
$inc_entries = [];
if ($sel_period_id && !empty($employees)) {
    $emp_ids_str = implode(',', array_map(fn($e) => (int)$e['id'], $employees));
    $ie_res = mysqli_query($conn,
        "SELECT * FROM incentive_entries WHERE payroll_period_id=$sel_period_id AND employee_id IN ($emp_ids_str)");
    if ($ie_res) while ($ie = mysqli_fetch_assoc($ie_res)) {
        $inc_entries[(int)$ie['employee_id']][$ie['component_label']] = (float)$ie['amount'];
    }
}

// ── Loans ──────────────────────────────────────────────────────────────────────
$loan_map = [];
if (!empty($employees)) {
    $emp_ids_all = implode(',', array_map(fn($e) => (int)$e['id'], $employees));
    $loan_res = mysqli_query($conn,
        "SELECT lr.*,
            COALESCE((SELECT SUM(lp.total_paid) FROM loan_payments lp WHERE lp.loan_request_id=lr.id),0) AS paid_so_far
         FROM loan_requests lr
         WHERE lr.employee_id IN ($emp_ids_all) AND lr.status='Approved'
         ORDER BY lr.employee_id, lr.id");
    if ($loan_res) while ($ln = mysqli_fetch_assoc($loan_res)) {
        $eid         = (int)$ln['employee_id'];
        $total_repay = floatval($ln['total_repayment']) ?: floatval($ln['loan_amount']);
        $balance     = round($total_repay - floatval($ln['paid_so_far']), 2);
        if ($balance <= 0) continue;
        $monthly     = floatval($ln['monthly_instalment']);
        if (!isset($loan_map[$eid])) $loan_map[$eid] = [];
        $loan_map[$eid][] = [
            'loan_id'            => (int)$ln['id'],
            'loan_type'          => $ln['loan_type'],
            'loan_amount'        => floatval($ln['loan_amount']),
            'monthly_instalment' => $monthly,
            'total_repayment'    => $total_repay,
            'paid_so_far'        => floatval($ln['paid_so_far']),
            'balance'            => $balance,
            'this_deduct'        => min($monthly, $balance),
            'no_of_instalments'  => intval($ln['no_of_instalments']),
            'total_interest'     => floatval($ln['total_interest']),
        ];
    }
}

// ── Salary advances ────────────────────────────────────────────────────────────
$advance_period_map = [];
if (!empty($employees) && $active_period) {
    $emp_ids_str = implode(',', array_map(fn($e) => (int)$e['id'], $employees));
    $adv_from    = sprintf('%04d-%02d-01', (int)$active_period['year'], (int)$active_period['month']);
    $adv_to      = date('Y-m-t', strtotime($adv_from));
    $adv_res     = mysqli_query($conn,
        "SELECT employee_id, SUM(amount) AS total_advance FROM salary_advances
         WHERE status='Approved' AND employee_id IN ($emp_ids_str)
           AND request_date BETWEEN '$adv_from' AND '$adv_to'
         GROUP BY employee_id");
    if ($adv_res) while ($ar = mysqli_fetch_assoc($adv_res))
        $advance_period_map[(int)$ar['employee_id']] = floatval($ar['total_advance']);
}

// ── Saved overrides ────────────────────────────────────────────────────────────
$saved_overrides = [];
if ($has_saved_sheet) {
    $or_res = mysqli_query($conn,
        "SELECT employee_id, override_excess, override_arrears,
                additional_earnings, additional_deductions, sheet_status
         FROM st_salary_sheet_entries WHERE payroll_period_id=$sel_period_id");
    if ($or_res) while ($or = mysqli_fetch_assoc($or_res))
        $saved_overrides[(int)$or['employee_id']] = $or;
}

// ── Helpers ────────────────────────────────────────────────────────────────────
$companies_res = mysqli_query($conn,
    "SELECT id, company_code, company_name FROM companies WHERE active=1 ORDER BY company_name");
$month_names   = ['','January','February','March','April','May','June',
                  'July','August','September','October','November','December'];

// Read incentive entry values (achievement % and earned amount)
function st_inc_ach(array $ie, int $eid, string $ikey): float {
    return (float)($ie[$eid]['__iach__'.$ikey]    ?? 0);
}
function st_inc_amt(array $ie, int $eid, string $ikey): float {
    return (float)($ie[$eid]['__incent__'.$ikey]  ?? 0);
}
function st_inc_reimb(array $ie, int $eid, string $rkey): float {
    return (float)($ie[$eid]['__reimb__'.$rkey]   ?? 0);
}

// ── Attendance-Allowance tier resolver ────────────────────────────────────────
// Returns ['amount' => float, 'tier' => '90%'|'85%'|'']
// Uses attendance_90 (≥90%) then attendance_85 (≥85%) from sr_salary_config
function st_resolve_att_allow(float $att_rate, array $cfg): array {
    $allow_90 = floatval($cfg['attendance_90'] ?? 0);
    $allow_85 = floatval($cfg['attendance_85'] ?? 0);
    if ($att_rate >= 90 && $allow_90 > 0) return ['amount' => $allow_90, 'tier' => '90%'];
    if ($att_rate >= 85 && $allow_85 > 0) return ['amount' => $allow_85, 'tier' => '85%'];
    return ['amount' => 0.0, 'tier' => ''];
}

// ── Build per-employee row data ────────────────────────────────────────────────
$rows = [];
foreach ($employees as $emp) {
    $eid = (int)$emp['id'];
    $sh  = $GLOBALS['_st_sh_date_map']   ?? [];
    $ac  = $GLOBALS['_st_att_count_map'] ?? [];
    $mtd = $GLOBALS['_st_month_total']   ?? 30;
    $att = $active_period ? st_calc_att($emp, $sh, $ac, $mtd)
                           : ['att' => 0, 'norm' => 0, 'holidays' => 0, 'rate' => 0];

    $override_excess   = isset($saved_overrides[$eid]) && $saved_overrides[$eid]['override_excess']  !== null
        ? floatval($saved_overrides[$eid]['override_excess'])  : null;
    $override_arrears  = isset($saved_overrides[$eid]) && $saved_overrides[$eid]['override_arrears'] !== null
        ? floatval($saved_overrides[$eid]['override_arrears']) : null;
    $add_earn_saved    = isset($saved_overrides[$eid]) && !empty($saved_overrides[$eid]['additional_earnings'])
        ? json_decode($saved_overrides[$eid]['additional_earnings'],  true) : [];
    $add_ded_saved     = isset($saved_overrides[$eid]) && !empty($saved_overrides[$eid]['additional_deductions'])
        ? json_decode($saved_overrides[$eid]['additional_deductions'], true) : [];

    $did = $emp['designation_id'];
    $cfg = $st_salary_cfg[$did] ?? [];

    $basic     = floatval($cfg['basic_salary'] ?? $emp['basic_salary'] ?? 0);
    $norm_days = max(1, $att['norm']);
    $per_day   = $basic > 0 ? round($basic / $norm_days, 4) : 0;
    $no_pay_days   = floatval($emp['no_pay'] ?? 0);
    $no_pay_amount = $per_day > 0 ? round($per_day * $no_pay_days, 2) : 0;

    // ── Attendance Allowance — tiered by att_rate from sr_salary_config ──────
    $att_allow_data  = st_resolve_att_allow((float)$att['rate'], $cfg);
    $att_allow       = $att_allow_data['amount'];
    $att_allow_tier  = $att_allow_data['tier'];
    $cfg_att_allow_85= floatval($cfg['attendance_85'] ?? 0);
    $cfg_att_allow_90= floatval($cfg['attendance_90'] ?? 0);

    // ── Incentives from incentive_entries ────────────────────────────────────
    $stores_damage_ach = st_inc_ach($inc_entries, $eid, 'stores_damage');
    $stores_damage_amt = st_inc_amt($inc_entries, $eid, 'stores_damage');
    $cfg_damage        = floatval($cfg['stores_damage_lo'] ?? 0);

    $rsqm_ach = st_inc_ach($inc_entries, $eid, 'rsqm');
    $rsqm_amt = st_inc_amt($inc_entries, $eid, 'rsqm');
    $rsqm_tier_label = $rsqm_ach >= 70 ? '≥70%' : ($rsqm_ach >= 60 ? '≥60%' : '');

    $punctuality_ach = st_inc_ach($inc_entries, $eid, 'punctuality');
    $punctuality_amt = st_inc_amt($inc_entries, $eid, 'punctuality');
    $punct_tier_label = $punctuality_ach >= 85 ? '≥85%' : ($punctuality_ach >= 75 ? '≥75%' : '');

    $loading_unloading_ach = st_inc_ach($inc_entries, $eid, 'loading_unloading');
    $loading_unloading_amt = st_inc_amt($inc_entries, $eid, 'loading_unloading');
    $cfg_lu = floatval($cfg['loading_unloading'] ?? 0);

    $ccfot_ach = st_inc_ach($inc_entries, $eid, 'ccfot_st');
    $ccfot_amt = st_inc_amt($inc_entries, $eid, 'ccfot_st');
    $cfg_ccfot = floatval($cfg['ccfot_st'] ?? 0);

    // Meal reimbursement: att_rate × cfg (ST reimb_direct = false)
    $cfg_meal    = floatval($cfg['meal_reimbursement'] ?? $emp['reimbursement_meal'] ?? 0);
    $meal_saved  = st_inc_reimb($inc_entries, $eid, 'meal_reimbursement');
    $meal = $meal_saved > 0 ? $meal_saved
          : ($cfg_meal > 0 && $norm_days > 0 ? round(($att['rate'] / 100) * $cfg_meal, 2) : $cfg_meal);

    $arrears   = $override_arrears !== null ? $override_arrears : floatval($emp['arrears_salary'] ?? 0);
    $insurance = floatval($emp['insurance_amount'] ?? 0);
    $welfare_amt = floatval($emp['welfare_amount'] ?? 0);

    $add_earn_total = 0; $add_ded_total = 0;
    if (is_array($add_earn_saved)) foreach ($add_earn_saved as $ae) $add_earn_total += floatval($ae['amount'] ?? 0);
    if (is_array($add_ded_saved))  foreach ($add_ded_saved  as $ad) $add_ded_total  += floatval($ad['amount'] ?? 0);

    $total_earnings = $basic + $att_allow + $stores_damage_amt + $rsqm_amt
                    + $punctuality_amt + $loading_unloading_amt + $ccfot_amt
                    + $meal + $arrears + $add_earn_total;

    $epf_emp  = round($basic * $epf_employee_rate / 100, 2);
    $epf_er   = round($basic * $epf_employer_rate / 100, 2);
    $etf_er   = round($basic * $etf_employer_rate / 100, 2);
    $w_loan   = floatval($emp['welfare_loan_deduction'] ?? 0);
    $w_soc    = floatval($emp['welfare_society']        ?? 0);
    $donations= floatval($emp['donations']              ?? 0);
    $sal_adv  = $advance_period_map[$eid] ?? floatval($emp['salary_advance'] ?? 0);
    $credit_r = floatval($emp['credit_recovery']        ?? 0);
    $retention= floatval($emp['retention']              ?? 0);
    $excess_p = $override_excess !== null ? $override_excess : floatval($emp['excess_payment'] ?? 0);
    $loan_ded = array_sum(array_column($loan_map[$eid] ?? [], 'this_deduct'))
              ?: floatval($emp['loan_deduction'] ?? 0);

    $total_deductions = $epf_emp + $welfare_amt + $w_loan + $w_soc + $donations
                      + $sal_adv + $loan_ded + $credit_r + $no_pay_amount
                      + $retention + $excess_p + $add_ded_total;

    $net_salary    = $total_earnings - $total_deductions;
    $bonus         = $basic > 0 ? round($basic / 12, 2) : 0;
    $gratuity      = $basic > 0 ? round($basic / 24, 2) : 0;
    $cost_bp       = $epf_er + $etf_er + $meal + $bonus + $basic;
    $total_benefit = $basic + $att_allow + $rsqm_amt + $punctuality_amt
                   + $loading_unloading_amt + $ccfot_amt
                   + $meal + $insurance + $epf_er + $etf_er + $bonus + $gratuity;

    $advance_from_entry = isset($advance_period_map[$eid]);

    $rows[$eid] = compact(
        'basic', 'att_allow', 'att_allow_tier', 'cfg_att_allow_85', 'cfg_att_allow_90',
        'stores_damage_ach', 'stores_damage_amt', 'cfg_damage',
        'rsqm_ach', 'rsqm_amt', 'rsqm_tier_label',
        'punctuality_ach', 'punctuality_amt', 'punct_tier_label',
        'loading_unloading_ach', 'loading_unloading_amt', 'cfg_lu',
        'ccfot_ach', 'ccfot_amt', 'cfg_ccfot',
        'meal', 'cfg_meal', 'arrears', 'insurance',
        'no_pay_days', 'no_pay_amount', 'per_day',
        'total_earnings', 'epf_emp', 'welfare_amt', 'w_loan', 'w_soc',
        'donations', 'sal_adv', 'loan_ded', 'credit_r', 'retention', 'excess_p',
        'total_deductions', 'net_salary', 'total_benefit', 'epf_er', 'etf_er',
        'bonus', 'gratuity', 'cost_bp', 'att', 'norm_days',
        'advance_from_entry', 'add_earn_saved', 'add_ded_saved',
        'add_earn_total', 'add_ded_total'
    );
    $rows[$eid]['emp_loans'] = $loan_map[$eid] ?? [];
}

include 'header.php';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<!-- Same font stack as off_salary.php -->
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Space+Mono:wght@400;700&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<style>
/* ═══════════════════════════════════════════════════════════
   DESIGN TOKENS  — matches off_salary.php palette exactly
   but with ST brand accent (amber/crimson warehouse theme)
═══════════════════════════════════════════════════════════ */
:root {
    --ink:       #0a0a0a;
    --ink2:      #444;
    --ink3:      #888;
    --bg:        #f2f1ee;
    --surface:   #ffffff;
    --border:    #dddbd4;
    --border2:   #c8c6bf;
    --mono:      'Space Mono', monospace;
    --sans:      'DM Sans', sans-serif;
    --display:   'Syne', sans-serif;
    --r:         6px;
    --r-lg:      12px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,.06),0 2px 8px rgba(0,0,0,.04);
    --shadow:    0 2px 8px rgba(0,0,0,.08),0 8px 24px rgba(0,0,0,.05);
    /* ST brand */
    --st-dark:   #1c0a00;
    --st-red:    #8b1a1a;
    --st-orange: #c04000;
    --st-amber:  #d97706;
    --st-green:  #166534;
    --st-blue:   #1d4ed8;
    --st-teal:   #0f766e;
}
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--sans); background: var(--bg); color: var(--ink); }

/* ── PAGE HEADER (same structure as off_salary.php) ── */
.st-page-hdr {
    background: var(--st-dark);
    color: #fff;
    padding: 22px 28px;
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px; flex-wrap: wrap;
    border-radius: 0 0 var(--r-lg) var(--r-lg);
    margin-bottom: 20px;
    position: relative; overflow: hidden;
}
/* diagonal stripe overlay — warehouse feel */
.st-page-hdr::before {
    content: '';
    position: absolute; inset: 0;
    background: repeating-linear-gradient(
        -52deg, transparent, transparent 18px,
        rgba(255,140,30,.05) 18px, rgba(255,140,30,.05) 36px);
    pointer-events: none;
}
.st-hdr-eyebrow {
    font-family: var(--display);
    font-size: 10px; font-weight: 700; letter-spacing: 2px;
    text-transform: uppercase; color: rgba(255,170,60,.65);
    margin-bottom: 4px; display: flex; align-items: center; gap: 7px;
}
.st-hdr-eyebrow::before { content:''; width:18px; height:2px; background:var(--st-amber); display:block; }
.st-hdr-title {
    font-family: var(--display);
    font-size: 22px; font-weight: 800; letter-spacing: -.2px;
    display: flex; align-items: center; gap: 10px;
}
.st-hdr-title i { color: var(--st-amber); }
.st-hdr-sub { font-size: 12px; color: rgba(255,255,255,.45); margin-top: 5px; }
.st-cat-pill {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(217,119,6,.2); border: 1px solid rgba(217,119,6,.4);
    border-radius: 20px; padding: 3px 10px;
    font-size: 11px; font-weight: 700; color: var(--st-amber); margin-top: 7px;
    font-family: var(--display);
}
.st-hdr-right { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; position: relative; z-index: 1; }
.period-pill {
    display: flex; align-items: center; gap: 10px;
    background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.15);
    border-radius: var(--r-lg); padding: 8px 14px;
}
.period-pill-label { font-size: 9px; color: rgba(255,200,100,.7); font-weight: 700; text-transform: uppercase; letter-spacing: .5px; font-family: var(--display); }
.period-pill select { background: transparent; border: none; outline: none; color: #fff; font-size: 14px; font-weight: 700; font-family: var(--display); cursor: pointer; }
.period-pill select option { background: #1c0a00; color: #fff; }

/* ── BUTTONS (exact same as off_salary.php) ── */
.btn { display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:var(--r);font-size:13px;font-weight:600;font-family:var(--sans);cursor:pointer;transition:all .18s;border:none;text-decoration:none; }
.btn-white{background:#fff;color:var(--ink);}.btn-white:hover{background:#f0f0ea;}
.btn-outline{background:transparent;color:#fff;border:1.5px solid rgba(255,255,255,.25);}.btn-outline:hover{background:rgba(255,255,255,.08);}
.btn-green{background:#22c55e;color:#fff;}.btn-green:hover{background:#16a34a;}
.btn-ghost{background:var(--bg);color:var(--ink2);border:1px solid var(--border);}.btn-ghost:hover{background:var(--border);}
.btn-sm{padding:7px 13px;font-size:12px;}
.btn-primary{background:var(--st-dark);color:#fff;}.btn-primary:hover{background:#2d1000;}
.btn-red{background:#dc2626;color:#fff;}.btn-red:hover{background:#b91c1c;}
.btn-amber{background:var(--st-amber);color:#fff;}.btn-amber:hover{background:#b45309;}
.btn-orange{background:var(--st-orange);color:#fff;}.btn-orange:hover{background:#9a3400;}
.btn-blue{background:var(--st-blue);color:#fff;}.btn-blue:hover{background:#1d4ed8;}
.btn-purple{background:#7c3aed;color:#fff;}.btn-purple:hover{background:#6d28d9;}
.btn:disabled{opacity:.55;cursor:not-allowed;}

/* ── SHEET BANNER ── */
.sheet-banner{border-radius:var(--r-lg);padding:14px 20px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;font-size:13px;font-weight:600;font-family:var(--sans);}
.sheet-banner-saved{background:#fff7ed;border:2px solid #fed7aa;color:#7c2d12;}
.sheet-banner-confirmed{background:#ecfdf5;border:2px solid #6ee7b7;color:#064e3b;}
.sheet-banner-unsaved{background:#fffbeb;border:2px dashed var(--st-amber);color:#78350f;}
.sheet-banner-left{display:flex;align-items:center;gap:10px;}
.sheet-banner-actions{display:flex;gap:8px;flex-wrap:wrap;}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:10px;font-weight:800;font-family:var(--display);}
.status-pending{background:#fff7ed;color:#c2410c;border:1px solid #fdba74;}
.status-confirmed{background:#dcfce7;color:#166534;border:1px solid #86efac;}

/* ── FILTER BAR ── */
.filter-bar{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);margin:0 0 14px;box-shadow:var(--shadow-sm);overflow:hidden;}
.filter-toggle{display:flex;align-items:center;justify-content:space-between;padding:11px 18px;cursor:pointer;user-select:none;font-size:13px;font-weight:600;color:var(--ink2);font-family:var(--display);}
.filter-toggle:hover{background:var(--bg);}
.filter-body{display:none;padding:0 18px 14px;border-top:1px solid var(--border);}
.filter-body.open{display:block;}
.filter-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:12px;}
.filter-group label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--ink3);margin-bottom:4px;font-family:var(--display);}
.filter-select{width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:var(--r);font-size:12px;font-family:var(--sans);background:#fff;color:var(--ink);}
.filter-select:focus{outline:none;border-color:var(--st-orange);}
.filter-actions{display:flex;gap:8px;margin-top:10px;}

/* ── STATS ROW ── */
.stats-row{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;}
.stat-chip{background:var(--surface);border:1px solid var(--border);border-radius:30px;padding:6px 14px;display:flex;align-items:center;gap:7px;font-size:12px;font-weight:500;color:var(--ink2);box-shadow:var(--shadow-sm);}
.stat-chip strong{color:var(--ink);font-weight:800;}
.dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.dot-red{background:#ef4444;}.dot-green{background:#22c55e;}.dot-amber{background:#f59e0b;}
.dot-blue{background:#3b82f6;}.dot-teal{background:#14b8a6;}.dot-orange{background:#f97316;}

/* ── SUMMARY CARDS ── */
.totals-section{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:18px 22px;margin-bottom:14px;box-shadow:var(--shadow-sm);}
.totals-section-title{font-family:var(--display);font-size:13px;font-weight:700;margin-bottom:12px;display:flex;align-items:center;gap:8px;color:var(--ink2);}
.totals-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:10px;}
.total-card{border-radius:var(--r-lg);padding:12px 14px;border:1px solid var(--border);}
.tc-earn{background:#fefce8;border-color:#fde047;}.tc-ded{background:#fef2f2;border-color:#fca5a5;}
.tc-net{background:#f0fdf4;border-color:#86efac;}.tc-ben{background:#eff6ff;border-color:#bfdbfe;}
.tc-loan{background:#f5f3ff;border-color:#c4b5fd;}.tc-nopay{background:#fff7ed;border-color:#fed7aa;}
.tc-incent{background:#fff1f2;border-color:#fecdd3;}.tc-attallow{background:#f0fdfa;border-color:#99f6e4;}
.total-card-label{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;margin-bottom:5px;font-family:var(--display);}
.tc-earn .total-card-label{color:#713f12;}.tc-ded .total-card-label{color:#7f1d1d;}
.tc-net .total-card-label{color:#14532d;}.tc-ben .total-card-label{color:#1e3a8a;}
.tc-loan .total-card-label{color:#4c1d95;}.tc-nopay .total-card-label{color:#7c2d12;}
.tc-incent .total-card-label{color:#881337;}.tc-attallow .total-card-label{color:#0f766e;}
.total-card-value{font-family:var(--mono);font-size:13px;font-weight:700;}
.tc-earn .total-card-value{color:#713f12;}.tc-ded .total-card-value{color:#991b1b;}
.tc-net .total-card-value{color:#14532d;}.tc-ben .total-card-value{color:#1e40af;}
.tc-loan .total-card-value{color:#6d28d9;}.tc-nopay .total-card-value{color:#92400e;}
.tc-incent .total-card-value{color:#be123c;}.tc-attallow .total-card-value{color:#0f766e;}

/* ── MAIN TABLE CARD ── */
.salary-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);box-shadow:var(--shadow);overflow:hidden;margin-bottom:24px;}
.salary-card-header{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;background:#fafaf8;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:10px;}
.salary-card-title{font-family:var(--display);font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;}
.salary-scroll{overflow-x:auto;}
.salary-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1900px;}

/* ── STICKY COLS ── */
.col-s1{position:sticky;left:0;background:var(--surface);z-index:2;min-width:110px;}
.col-s2{position:sticky;left:110px;background:var(--surface);z-index:2;min-width:90px;}
.col-s3{position:sticky;left:200px;background:var(--surface);z-index:2;min-width:90px;}
.col-s4{position:sticky;left:290px;background:var(--surface);z-index:2;min-width:170px;}
.col-s5{position:sticky;left:460px;background:var(--surface);z-index:2;min-width:130px;border-right:2px solid var(--border2);}
.salary-table tbody tr:hover .col-s1,.salary-table tbody tr:hover .col-s2,
.salary-table tbody tr:hover .col-s3,.salary-table tbody tr:hover .col-s4,
.salary-table tbody tr:hover .col-s5{background:#f5f5f8;}

/* ── HEADER ROWS ── */
.salary-table thead th{padding:8px 10px;text-align:center;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;background:#1c0a00;color:#a1a1a8;border-right:1px solid #2d1800;white-space:nowrap;position:sticky;top:0;z-index:3;font-family:var(--display);}
.salary-table thead th.th-group{font-size:10px;font-weight:900;border-bottom:1px solid #2d1800;padding:9px 10px;}
.salary-table thead th.th-emp{background:#0c0500;color:#e5e7eb;text-align:left;border-right:none;}
.th-att{background:#0f2030 !important;color:#93c5fd !important;}
.th-attallow{background:#063020 !important;color:#6ee7b7 !important;}
.th-dam{background:#3d0f0f !important;color:#fca5a5 !important;}
.th-rsqm{background:#1a1000 !important;color:#fde047 !important;}
.th-punct{background:#1a0a20 !important;color:#d8b4fe !important;}
.th-lu{background:#0f1a3d !important;color:#93c5fd !important;}
.th-ccfot{background:#0f3d1e !important;color:#a7f3d0 !important;}
.th-reimb{background:#2a1500 !important;color:#fdba74 !important;}
.th-earn{background:#0a2a10 !important;color:#86efac !important;}
.th-ded{background:#3d0f0f !important;color:#fca5a5 !important;}
.th-net{background:#0a1a3d !important;color:#93c5fd !important;}
.th-ben{background:#1a0a00 !important;color:#fde047 !important;}
.th-loan{background:#2a0f3d !important;color:#d8b4fe !important;}
.salary-table thead th.col-s1,.salary-table thead th.col-s2,.salary-table thead th.col-s3,
.salary-table thead th.col-s4,.salary-table thead th.col-s5{z-index:5;}

/* ── BODY ── */
.salary-table tbody tr{border-bottom:1px solid #f2f2ee;transition:background .1s;}
.salary-table tbody tr:hover{background:#f8f8fc;}
.salary-table tbody td{padding:8px 10px;vertical-align:middle;border-right:1px solid #f2f2ee;text-align:right;}
.salary-table tbody td.td-left{text-align:left;}
.salary-table tbody td.td-center{text-align:center;}
.td-att{background:rgba(15,32,48,.025);}
.td-attallow{background:rgba(6,48,32,.03);}
.td-dam{background:rgba(61,15,15,.03);}
.td-rsqm{background:rgba(26,16,0,.025);}
.td-punct{background:rgba(26,10,32,.025);}
.td-lu{background:rgba(15,26,61,.025);}
.td-ccfot{background:rgba(15,61,30,.025);}
.td-reimb{background:rgba(42,21,0,.025);}
.td-earn{background:rgba(10,42,16,.03);}
.td-ded{background:rgba(61,15,15,.025);}
.td-net{background:rgba(10,26,61,.04);}
.td-ben{background:rgba(26,10,0,.025);}
.td-loan{background:rgba(42,15,61,.025);}

/* ── GRAND TOTAL ROW ── */
.salary-table tbody tr.tr-total{background:#1c0a00 !important;font-weight:700;border-top:2px solid #3d2200;}
.salary-table tbody tr.tr-total td{color:#e5e7eb;font-family:var(--mono);font-size:10.5px;padding:9px;border-right:1px solid #2d1800;}
.salary-table tbody tr.tr-total .col-s1,.salary-table tbody tr.tr-total .col-s2,
.salary-table tbody tr.tr-total .col-s3,.salary-table tbody tr.tr-total .col-s4,
.salary-table tbody tr.tr-total .col-s5{background:#1c0a00;}

/* ── MONEY CLASSES ── */
.m-earn{font-family:var(--mono);font-size:11px;font-weight:600;color:#166534;}
.m-incent{font-family:var(--mono);font-size:11px;font-weight:600;color:#b45309;}
.m-ded{font-family:var(--mono);font-size:11px;font-weight:600;color:#991b1b;}
.m-net{font-family:var(--mono);font-size:12px;font-weight:700;color:#1d4ed8;}
.m-ben{font-family:var(--mono);font-size:12px;font-weight:700;color:#78350f;}
.m-loan{font-family:var(--mono);font-size:11px;font-weight:700;color:#6d28d9;}
.m-teal{font-family:var(--mono);font-size:11px;font-weight:700;color:#0f766e;}
.m-zero{color:#d1d5db;font-family:var(--mono);font-size:11px;}

/* ── EMPLOYEE CELL ── */
.emp-id-tag{font-family:var(--mono);font-size:10px;font-weight:700;color:var(--st-orange);background:#fff7ed;padding:1px 5px;border-radius:2px;}
.emp-name-cell{font-size:12.5px;font-weight:700;color:var(--ink);}
.emp-meta{font-size:10px;color:var(--ink3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;}
.s-badge{display:inline-block;padding:2px 7px;border-radius:3px;font-size:9px;font-weight:700;font-family:var(--display);}
.s-probation{background:#fff7ed;color:#92400e;}.s-permanent{background:#dcfce7;color:#15803d;}
.s-resigned{background:#f3f4f6;color:#6b7280;}.s-terminated{background:#fee2e2;color:#991b1b;}
.cat-chip{display:inline-block;padding:2px 8px;background:#fff7ed;color:var(--st-orange);border-radius:3px;font-size:9px;font-weight:800;border:1px solid #fed7aa;font-family:var(--display);}

/* ── ATT PILL ── */
.att-pill{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap;font-family:var(--mono);}
.att-high{background:#dcfce7;color:#14532d;border:1px solid #86efac;}
.att-mid{background:#fef9c3;color:#78350f;border:1px solid #fde047;}
.att-low{background:#fee2e2;color:#7f1d1d;border:1px solid #fca5a5;}
.att-zero{background:#f3f4f6;color:#9ca3af;border:1px solid #e5e7eb;}

/* ── ATT ALLOWANCE TIER BADGE ── */
.attallow-tier{display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:800;font-family:var(--display);margin-left:3px;white-space:nowrap;}
.attallow-t90{background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;}
.attallow-t85{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;}
.attallow-none{background:#f3f4f6;color:#9ca3af;border:1px solid #e5e7eb;}

/* ── TIER BADGES (incentives) ── */
.tier-badge{display:inline-block;padding:1px 5px;border-radius:2px;font-size:9px;font-weight:800;font-family:var(--display);margin-left:3px;}
.tier-t2{background:#fef9c3;color:#713f12;border:1px solid #fde047;}
.tier-t1{background:#fefce8;color:#a16207;border:1px solid #fef08a;}
.tier-met{background:#dcfce7;color:#14532d;border:1px solid #86efac;}
.tier-miss{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}

.row-btn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:var(--r);border:1px solid var(--border);background:var(--surface);color:var(--ink3);cursor:pointer;font-size:11px;transition:all .15s;text-decoration:none;}
.row-btn:hover{background:var(--st-orange);color:#fff;border-color:var(--st-orange);}
.src-badge{display:inline-block;font-size:9px;font-weight:700;padding:1px 5px;border-radius:2px;vertical-align:middle;margin-left:3px;line-height:1.4;}
.src-live{background:#dcfce7;color:#166534;}.src-emp{background:#f3f4f6;color:#6b7280;}.src-loan{background:#f3e8ff;color:#7c3aed;}
.loan-count-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:10px;font-size:9px;font-weight:700;background:#f3e8ff;color:#7c3aed;white-space:nowrap;}
.loan-count-zero{background:#f3f4f6;color:#9ca3af;}

/* ── MODAL ── */
.modal-bg{display:none;position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,.6);align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(4px);}
.modal-bg.show{display:flex;}
.modal-box{background:var(--surface);border-radius:16px;width:100%;max-width:1200px;max-height:94vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 32px 100px rgba(0,0,0,.3);animation:modalIn .3s cubic-bezier(.22,.68,0,1.2);}
@keyframes modalIn{from{opacity:0;transform:translateY(24px) scale(.96)}to{opacity:1;transform:none}}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:20px 26px;border-bottom:1px solid var(--border);background:#fafaf8;flex-shrink:0;}
.modal-emp-name{font-family:var(--display);font-size:20px;font-weight:800;color:var(--ink);}
.modal-emp-meta{font-size:12px;color:var(--ink3);display:flex;gap:12px;flex-wrap:wrap;margin-top:4px;align-items:center;}
.modal-close{background:none;border:none;cursor:pointer;color:var(--ink3);font-size:18px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;border-radius:var(--r);transition:all .15s;}
.modal-close:hover{background:#f3f4f6;color:var(--ink);}
.modal-body{padding:0;overflow-y:auto;flex:1;}
.modal-footer{padding:16px 26px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:10px;flex-shrink:0;background:#fafaf8;}
.modal-footer-links{display:flex;gap:8px;flex-wrap:wrap;}

/* NET HERO BAND */
.net-hero-band{background:linear-gradient(135deg,#1c0a00 0%,#3d1500 50%,#1c0800 100%);padding:24px 26px;display:grid;grid-template-columns:1fr auto 1fr auto 1fr auto 1fr auto 1fr;gap:0;align-items:center;}
.nh-item{text-align:center;padding:4px 8px;}
.nh-div{width:1px;background:rgba(255,255,255,.12);align-self:stretch;margin:0 4px;}
.nh-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:rgba(255,255,255,.45);margin-bottom:5px;font-family:var(--display);}
.nh-value{font-family:var(--mono);font-size:18px;font-weight:700;}
.nh-earn{color:#86efac;}.nh-ded{color:#fca5a5;}.nh-net{color:#fff;font-size:24px;font-weight:800;}.nh-ben{color:#fde047;}.nh-incent{color:#fdba74;}

/* ATT BAND */
.att-band{padding:14px 26px;display:flex;gap:14px;background:#f8f8fc;border-bottom:1px solid var(--border);flex-wrap:wrap;}
.att-card{background:#fff;border:1px solid var(--border);border-radius:var(--r-lg);padding:12px 18px;display:flex;flex-direction:column;gap:3px;flex:1;min-width:120px;}
.att-card-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--ink3);font-family:var(--display);}
.att-card-value{font-family:var(--mono);font-size:18px;font-weight:800;color:var(--st-dark);}
.att-card-sub{font-size:9px;color:var(--ink3);}
/* Attendance Allowance card gets a special teal highlight */
.att-card.card-attallow{border-color:#5eead4;background:#f0fdfa;}
.att-card.card-attallow .att-card-label{color:#0f766e;}
.att-card.card-attallow .att-card-value{color:#0f766e;}

/* INCENTIVE BAND */
.incent-band{padding:14px 26px;background:#fffbf2;border-bottom:1px solid #fed7aa;}
.incent-band-title{font-family:var(--display);font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--st-orange);margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.incent-cards-row{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;}
.ic-card{border-radius:var(--r-lg);padding:12px 14px;border:1px solid var(--border);}
.ic-damage{background:#fff0f0;border-color:#fca5a5;}
.ic-rsqm{background:#fffbeb;border-color:#fde68a;}
.ic-punct{background:#faf5ff;border-color:#c4b5fd;}
.ic-lu{background:#eff6ff;border-color:#bfdbfe;}
.ic-ccfot{background:#f0fdf4;border-color:#86efac;}
.ic-label{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;font-family:var(--display);}
.ic-damage .ic-label{color:#991b1b;}.ic-rsqm .ic-label{color:#713f12;}.ic-punct .ic-label{color:#4c1d95;}.ic-lu .ic-label{color:#1e3a8a;}.ic-ccfot .ic-label{color:#14532d;}
.ic-ach{font-family:var(--mono);font-size:13px;font-weight:700;margin-bottom:2px;}
.ic-damage .ic-ach{color:#dc2626;}.ic-rsqm .ic-ach{color:#a16207;}.ic-punct .ic-ach{color:#6d28d9;}.ic-lu .ic-ach{color:#1d4ed8;}.ic-ccfot .ic-ach{color:#166534;}
.ic-amt{font-family:var(--mono);font-size:16px;font-weight:800;}
.ic-damage .ic-amt{color:#991b1b;}.ic-rsqm .ic-amt{color:#92400e;}.ic-punct .ic-amt{color:#5b21b6;}.ic-lu .ic-amt{color:#1e3a8a;}.ic-ccfot .ic-amt{color:#14532d;}
.ic-tier{font-size:9px;color:var(--ink3);margin-top:3px;}

/* LOAN BAND */
.loan-band{padding:14px 26px;background:#fdf6ff;border-bottom:1px solid #e9d5ff;}
.loan-band-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#7c3aed;margin-bottom:8px;display:flex;align-items:center;gap:6px;font-family:var(--display);}
.loan-cards-row{display:flex;gap:10px;flex-wrap:wrap;}
.loan-detail-card{background:#fff;border:1.5px solid #c4b5fd;border-radius:10px;padding:12px 14px;min-width:210px;flex:1;}
.loan-detail-card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:7px;}
.loan-detail-card-type{font-size:11px;font-weight:800;color:#6d28d9;display:flex;align-items:center;gap:5px;font-family:var(--display);}
.loan-detail-row{display:flex;justify-content:space-between;font-size:11px;padding:3px 0;border-bottom:1px solid #f5f0ff;}
.loan-detail-row:last-child{border-bottom:none;}
.loan-detail-label{color:#9ca3af;}
.loan-detail-val{font-family:var(--mono);font-weight:700;color:var(--ink);}
.loan-progress-bar{height:5px;background:#e9d5ff;border-radius:3px;overflow:hidden;margin-top:5px;}
.loan-progress-fill{height:100%;background:linear-gradient(90deg,#7c3aed,#a78bfa);border-radius:3px;}
.loan-no-loans{font-size:12px;color:#9ca3af;display:flex;align-items:center;gap:6px;padding:6px 0;}

/* MODAL SECTIONS */
.modal-sections{padding:20px 26px;display:grid;grid-template-columns:1fr 1fr;gap:18px;}
.modal-section{border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden;}
.ms-header{display:flex;align-items:center;gap:8px;padding:11px 16px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);font-family:var(--display);}
.ms-earn{background:#f0fdf4;color:#14532d;}.ms-ded{background:#fef2f2;color:#7f1d1d;}
.ms-att{background:#eff6ff;color:#1e3a8a;}.ms-ben{background:#fffbeb;color:#78350f;}
.ms-body{padding:2px 0;}
.ms-row{display:flex;justify-content:space-between;align-items:center;padding:7px 16px;border-bottom:1px solid #f8f8f6;font-size:12.5px;}
.ms-row:last-child{border-bottom:none;}
.ms-row-label{color:var(--ink2);}
.ms-row-value{font-family:var(--mono);font-weight:600;font-size:11px;}
.ms-zero .ms-row-value{color:#d1d5db;}
.ms-earn-val .ms-row-value{color:#166534;}.ms-ded-val .ms-row-value{color:#991b1b;}
.ms-loan-val .ms-row-value{color:#7c3aed;}.ms-att-val .ms-row-value{color:#1d4ed8;}
.ms-ben-val .ms-row-value{color:#78350f;}.ms-teal-val .ms-row-value{color:#0f766e;}
.ms-total{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;font-weight:700;font-size:13px;border-top:2px solid var(--border2);}
.ms-total-earn{background:#e7f5ee;color:#14532d;}.ms-total-ded{background:#fee2e2;color:#991b1b;}
.ms-total-att{background:#eff6ff;color:#1d4ed8;}.ms-total-ben{background:#fffbeb;color:#78350f;}
.ms-total span:last-child{font-family:var(--mono);font-size:14px;}

/* EDIT OVERRIDES */
.modal-edit-section{padding:14px 26px;background:#fffbf2;border-top:1px solid #fed7aa;border-bottom:1px solid #fed7aa;}
.modal-edit-title{font-family:var(--display);font-size:12px;font-weight:800;color:#92400e;margin-bottom:10px;display:flex;align-items:center;gap:7px;text-transform:uppercase;letter-spacing:.4px;}
.modal-edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.modal-edit-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--st-orange);display:block;margin-bottom:4px;font-family:var(--display);}
.modal-edit-input{padding:9px 12px;border:1.5px solid #fdba74;border-radius:var(--r);font-size:13px;font-family:var(--mono);font-weight:600;background:#fff;color:#7c2d12;outline:none;width:100%;transition:border-color .15s,box-shadow .15s;}
.modal-edit-input:focus{border-color:var(--st-orange);box-shadow:0 0 0 3px rgba(192,64,0,.1);}

/* ADD ROWS */
.add-rows-section{padding:2px 0;}
.add-row-item{display:flex;align-items:center;gap:7px;padding:5px 14px;border-bottom:1px solid #fef3c7;}
.add-row-item:last-child{border-bottom:none;}
.add-row-desc{flex:1;padding:5px 9px;border:1.5px solid #fde68a;border-radius:4px;font-size:11.5px;font-family:var(--sans);background:#fff;color:var(--ink);outline:none;}
.add-row-desc:focus{border-color:var(--st-amber);}
.add-row-amount{width:105px;padding:5px 9px;border:1.5px solid #fde68a;border-radius:4px;font-size:12px;font-family:var(--mono);font-weight:700;background:#fff;color:#166534;outline:none;text-align:right;}
.add-row-amount.is-ded{color:#991b1b;}
.add-row-remove{width:24px;height:24px;border:none;border-radius:50%;background:#fee2e2;color:#dc2626;font-size:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.add-rows-footer{padding:7px 14px;display:flex;align-items:center;justify-content:space-between;background:#fefce8;border-top:1px solid #fde68a;}
.add-row-btn{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:var(--r);border:1.5px dashed #fde68a;background:transparent;color:var(--st-amber);font-size:10px;font-weight:700;cursor:pointer;font-family:var(--display);text-transform:uppercase;letter-spacing:.3px;}
.add-row-btn:hover{background:#fffde7;border-color:var(--st-amber);}
.add-row-total{font-family:var(--mono);font-size:11px;font-weight:700;color:#92400e;}

/* UPDATE BAR */
.modal-update-bar{padding:12px 26px;background:#fffbf2;border-top:1px solid #fed7aa;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-shrink:0;}
.modal-update-info{font-size:11.5px;color:var(--st-orange);font-weight:600;display:flex;align-items:center;gap:6px;}
.modal-update-status{font-size:11px;font-weight:700;display:flex;align-items:center;gap:4px;}
.upd-saved{color:#15803d;}.upd-error{color:#991b1b;}.upd-saving{color:var(--st-amber);}

/* CONFIRM MODALS */
.confirm-bg{display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.6);align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(4px);}
.confirm-bg.show{display:flex;}
.confirm-box{background:#fff;border-radius:14px;padding:30px;max-width:490px;width:100%;box-shadow:0 32px 80px rgba(0,0,0,.3);animation:modalIn .25s cubic-bezier(.22,.68,0,1.2);}
.confirm-box h3{font-family:var(--display);font-size:20px;font-weight:800;margin-bottom:8px;}
.confirm-box p{color:var(--ink2);font-size:13px;line-height:1.6;margin-bottom:18px;}
.confirm-actions{display:flex;gap:10px;justify-content:flex-end;}
.confirm-result-banner{border-radius:10px;padding:12px 16px;margin-top:14px;font-size:13px;}
.crb-success{background:#dcfce7;border:1px solid #86efac;color:#14532d;}
.crb-error{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}

@media(max-width:900px){
    .filter-grid{grid-template-columns:1fr 1fr;}
    .totals-grid{grid-template-columns:repeat(3,1fr);}
    .modal-sections{grid-template-columns:1fr;}
    .modal-edit-grid{grid-template-columns:1fr;}
    .net-hero-band{grid-template-columns:1fr;gap:7px;}
    .nh-div{display:none;}
    .nh-item{background:rgba(255,255,255,.05);border-radius:8px;padding:8px;}
    .incent-cards-row{grid-template-columns:1fr 1fr;}
}
</style>

<!-- ═══════════════════════════════════════════════════════════
     PAGE HEADER
═══════════════════════════════════════════════════════════ -->
<div class="st-page-hdr">
    <div style="position:relative;z-index:1;">
        <div class="st-hdr-eyebrow"><i class="fa-solid fa-warehouse"></i> Payroll Module</div>
        <div class="st-hdr-title"><i class="fa-solid fa-boxes-stacked"></i> Stores Staff Salary Sheet</div>
        <div class="st-hdr-sub">Store Keepers &middot; Inventory &amp; Warehouse Personnel &mdash; Tiered Attendance Allowance</div>
        <div class="st-cat-pill"><i class="fa-solid fa-tag"></i> ST Schema</div>
    </div>
    <div class="st-hdr-right">
        <form method="GET" id="periodForm" style="display:contents;">
            <?php if (!empty($all_periods)): ?>
            <div class="period-pill">
                <i class="fa-solid fa-calendar-check" style="color:var(--st-amber);"></i>
                <div>
                    <div class="period-pill-label">Payroll Period</div>
                    <select name="period_id" onchange="document.getElementById('periodForm').submit()">
                        <?php foreach ($all_periods as $pp): ?>
                        <option value="<?php echo $pp['id'];?>" <?php echo $sel_period_id==$pp['id']?'selected':'';?>>
                            <?php echo $month_names[$pp['month']].' '.$pp['year'];
                                  echo ($pp['status']!=='Open')?' ('.$pp['status'].')':''; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($active_period): ?>
                <span style="font-size:10px;padding:2px 8px;border-radius:10px;font-weight:700;font-family:var(--display);<?php echo ($active_period['status']??'Open')==='Open'?'background:#dcfce7;color:#166534;':'background:#f3f4f6;color:#6b7280;';?>">
                    <?php echo htmlspecialchars($active_period['status']??'Open'); ?>
                </span>
                <?php endif; ?>
            </div>
            <?php if ($filter_status):  ?><input type="hidden" name="filter_status"  value="<?php echo $filter_status;  ?>"><?php endif; ?>
            <?php if ($filter_company): ?><input type="hidden" name="filter_company" value="<?php echo $filter_company; ?>"><?php endif; ?>
            <?php if ($filter_branch):  ?><input type="hidden" name="filter_branch"  value="<?php echo $filter_branch;  ?>"><?php endif; ?>
            <?php endif; ?>
        </form>
        <a href="incentive_entry.php?period_id=<?php echo $sel_period_id;?>" class="btn btn-outline btn-sm"><i class="fa-solid fa-percent"></i> Incentive Entry</a>
        <a href="bulk_salary_update.php" class="btn btn-outline btn-sm"><i class="fa-solid fa-sliders"></i> Bulk Update</a>
        <button class="btn btn-white btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        <button class="btn btn-green btn-sm" onclick="exportCSV()"><i class="fa-solid fa-file-csv"></i> Export</button>
    </div>
</div>

<!-- SHEET STATUS BANNER -->
<?php if ($has_saved_sheet && $is_confirmed): ?>
<div class="sheet-banner sheet-banner-confirmed">
    <div class="sheet-banner-left">
        <i class="fa-solid fa-circle-check" style="font-size:18px;color:#16a34a;"></i>
        <div><strong>Salary sheet CONFIRMED</strong> — <?php echo $saved_sheet_count;?> employees &middot;
        <?php echo $active_period?$month_names[$active_period['month']].' '.$active_period['year']:'';?>
        <span style="font-size:11px;font-weight:400;margin-left:6px;opacity:.8;">Loan payments recorded. Sheet locked.</span></div>
    </div>
    <div class="sheet-banner-actions"><span class="status-pill status-confirmed"><i class="fa-solid fa-lock"></i> CONFIRMED</span></div>
</div>
<?php elseif ($has_saved_sheet): ?>
<div class="sheet-banner sheet-banner-saved">
    <div class="sheet-banner-left">
        <i class="fa-solid fa-floppy-disk" style="font-size:16px;color:var(--st-orange);"></i>
        <div><strong>Salary sheet saved</strong> — <?php echo $saved_sheet_count;?> employees &middot;
        <?php echo $active_period?$month_names[$active_period['month']].' '.$active_period['year']:'';?>
        <span class="status-pill status-pending" style="margin-left:8px;"><i class="fa-solid fa-clock"></i> PENDING</span>
        <span style="font-size:11px;font-weight:400;margin-left:6px;opacity:.75;">Review &amp; edit values, then confirm to lock &amp; record loan payments.</span></div>
    </div>
    <div class="sheet-banner-actions">
        <button class="btn btn-amber btn-sm" onclick="refreshSheet()"><i class="fa-solid fa-arrows-rotate"></i> Refresh</button>
        <button class="btn btn-red btn-sm" onclick="confirmDeleteSheet()"><i class="fa-solid fa-trash"></i> Delete</button>
        <button class="btn btn-orange btn-sm" onclick="confirmSheetModal()"><i class="fa-solid fa-check-double"></i> Confirm Sheet</button>
    </div>
</div>
<?php else: ?>
<div class="sheet-banner sheet-banner-unsaved">
    <div class="sheet-banner-left">
        <i class="fa-solid fa-triangle-exclamation" style="font-size:16px;color:var(--st-amber);"></i>
        <div><strong>No saved sheet</strong> for <?php echo $active_period?$month_names[$active_period['month']].' '.$active_period['year']:'this period';?>.
        <span style="font-size:11px;font-weight:400;margin-left:6px;opacity:.8;">Create a salary sheet to lock values &amp; confirm.</span></div>
    </div>
    <div class="sheet-banner-actions">
        <button class="btn btn-primary btn-sm" onclick="createSalarySheet()"><i class="fa-solid fa-floppy-disk"></i> Create Salary Sheet</button>
    </div>
</div>
<?php endif; ?>

<!-- FILTERS -->
<div class="filter-bar">
    <div class="filter-toggle" onclick="toggleFilter()">
        <div style="display:flex;align-items:center;gap:8px;"><i class="fa-solid fa-sliders"></i><span>Filters</span>
        <?php $active_f=array_filter([$filter_status,$filter_company,$filter_branch]); if(count($active_f)):?>
        <span style="background:var(--st-orange);color:#fff;border-radius:20px;padding:1px 7px;font-size:10px;font-weight:700;"><?php echo count($active_f);?></span>
        <?php endif;?></div>
        <i class="fa-solid fa-chevron-down" id="filterChevron" style="transition:transform .2s;"></i>
    </div>
    <div class="filter-body <?php echo count($active_f)?'open':'';?>" id="filterBody">
        <form method="GET">
            <?php if ($sel_period_id):?><input type="hidden" name="period_id" value="<?php echo $sel_period_id;?>"><?php endif;?>
            <div class="filter-grid">
                <div class="filter-group"><label>Company</label>
                    <select name="filter_company" class="filter-select" onchange="loadBranches(this.value)">
                        <option value="">All Companies</option>
                        <?php if ($companies_res){mysqli_data_seek($companies_res,0); while($c=mysqli_fetch_assoc($companies_res)):?>
                        <option value="<?php echo $c['id'];?>" <?php echo $filter_company==$c['id']?'selected':'';?>><?php echo htmlspecialchars($c['company_code'].' - '.$c['company_name']);?></option>
                        <?php endwhile;}?>
                    </select></div>
                <div class="filter-group"><label>Branch</label>
                    <select name="filter_branch" id="filter_branch" class="filter-select">
                        <option value="">All Branches</option>
                    </select></div>
                <div class="filter-group"><label>Status</label>
                    <select name="filter_status" class="filter-select">
                        <option value="">All Status</option>
                        <option value="Probation"  <?php echo $filter_status=='Probation' ?'selected':'';?>>Probation</option>
                        <option value="Permanent"  <?php echo $filter_status=='Permanent' ?'selected':'';?>>Permanent</option>
                        <option value="Resigned"   <?php echo $filter_status=='Resigned'  ?'selected':'';?>>Resigned</option>
                        <option value="Terminated" <?php echo $filter_status=='Terminated'?'selected':'';?>>Terminated</option>
                    </select></div>
                <div class="filter-group"><label>Search</label>
                    <input type="text" id="liveSearch" class="filter-select" placeholder="Name / Code…" oninput="liveFilter(this.value)" style="background:#fff;"></div>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Apply</button>
                <a href="st_salary.php<?php echo $sel_period_id?'?period_id='.$sel_period_id:'';?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- STATS ROW -->
<?php
$gt = ['earn'=>0,'ded'=>0,'net'=>0,'ben'=>0,'attallow'=>0,'damage'=>0,'rsqm'=>0,'punct'=>0,'lu'=>0,'ccfot'=>0,'meal'=>0,'loan_ded'=>0,'no_pay'=>0];
$total_loan_count = 0;
foreach ($rows as $eid_k => $r) {
    $gt['earn']     += $r['total_earnings'];  $gt['ded']       += $r['total_deductions'];
    $gt['net']      += $r['net_salary'];      $gt['ben']       += $r['total_benefit'];
    $gt['attallow'] += $r['att_allow'];       $gt['damage']    += $r['stores_damage_amt'];
    $gt['rsqm']     += $r['rsqm_amt'];        $gt['punct']     += $r['punctuality_amt'];
    $gt['lu']       += $r['loading_unloading_amt']; $gt['ccfot'] += $r['ccfot_amt'];
    $gt['meal']     += $r['meal'];            $gt['loan_ded']  += $r['loan_ded'];
    $gt['no_pay']   += $r['no_pay_amount'];
    $total_loan_count += count($r['emp_loans']);
}
$cnt_active = count(array_filter($employees, fn($e) => in_array($e['status'],['Probation','Permanent'])));
$total_incentives = $gt['damage']+$gt['rsqm']+$gt['punct']+$gt['lu']+$gt['ccfot'];
?>
<div class="stats-row">
    <div class="stat-chip"><span class="dot dot-orange"></span><span><strong><?php echo count($employees);?></strong> ST Employees</span></div>
    <div class="stat-chip"><span class="dot dot-green"></span><span><strong><?php echo $cnt_active;?></strong> Active</span></div>
    <div class="stat-chip"><span class="dot dot-teal"></span><span>Att. Allowance <strong>LKR <?php echo number_format($gt['attallow'],2);?></strong></span></div>
    <div class="stat-chip"><span class="dot dot-green"></span><span>Total Earnings <strong>LKR <?php echo number_format($gt['earn'],2);?></strong></span></div>
    <div class="stat-chip"><span class="dot dot-red"></span><span>Deductions <strong>LKR <?php echo number_format($gt['ded'],2);?></strong></span></div>
    <div class="stat-chip"><span class="dot dot-blue"></span><span>Net Salary <strong>LKR <?php echo number_format($gt['net'],2);?></strong></span></div>
    <div class="stat-chip"><span class="dot dot-amber"></span><span>Incentives <strong>LKR <?php echo number_format($total_incentives,2);?></strong></span></div>
</div>

<!-- SUMMARY CARDS -->
<div class="totals-section">
    <div class="totals-section-title"><i class="fa-solid fa-sigma"></i> Period Summary — <?php echo $active_period?$month_names[$active_period['month']].' '.$active_period['year']:'No Period';?></div>
    <div class="totals-grid">
        <div class="total-card tc-attallow"><div class="total-card-label"><i class="fa-solid fa-award"></i> Att. Allowance</div><div class="total-card-value">LKR <?php echo number_format($gt['attallow'],2);?></div></div>
        <div class="total-card tc-incent"><div class="total-card-label"><i class="fa-solid fa-coins"></i> Incentives</div><div class="total-card-value">LKR <?php echo number_format($total_incentives,2);?></div></div>
        <div class="total-card tc-earn"><div class="total-card-label"><i class="fa-solid fa-arrow-trend-up"></i> Total Earnings</div><div class="total-card-value">LKR <?php echo number_format($gt['earn'],2);?></div></div>
        <div class="total-card tc-ded"><div class="total-card-label"><i class="fa-solid fa-circle-minus"></i> Total Deductions</div><div class="total-card-value">LKR <?php echo number_format($gt['ded'],2);?></div></div>
        <div class="total-card tc-net"><div class="total-card-label"><i class="fa-solid fa-wallet"></i> Net Salary</div><div class="total-card-value">LKR <?php echo number_format($gt['net'],2);?></div></div>
        <div class="total-card tc-loan"><div class="total-card-label"><i class="fa-solid fa-hand-holding-dollar"></i> Loan Deductions</div><div class="total-card-value">LKR <?php echo number_format($gt['loan_ded'],2);?></div></div>
        <div class="total-card tc-nopay"><div class="total-card-label"><i class="fa-solid fa-ban"></i> No Pay</div><div class="total-card-value">LKR <?php echo number_format($gt['no_pay'],2);?></div></div>
    </div>
</div>

<!-- HIDDEN SAVE FORM -->
<form id="saveSheetForm" method="POST" action="st_salary.php?period_id=<?php echo $sel_period_id;?>">
    <input type="hidden" name="action"    value="save_sheet">
    <input type="hidden" name="period_id" value="<?php echo $sel_period_id;?>">
    <input type="hidden" name="rows_json" id="saveRowsJson" value="">
</form>

<!-- MAIN TABLE -->
<div class="salary-card">
    <div class="salary-card-header">
        <div class="salary-card-title">
            <i class="fa-solid fa-boxes-stacked"></i>
            <span style="font-family:var(--display);">ST SALARY SHEET</span>
            <span style="font-weight:400;color:var(--ink3);font-size:12px;font-family:var(--sans);"><?php echo count($employees);?> records</span>
            <?php if ($is_confirmed):?>
            <span style="background:#dcfce7;color:#166534;padding:2px 9px;border-radius:10px;font-size:10px;font-weight:800;font-family:var(--display);"><i class="fa-solid fa-lock"></i> CONFIRMED</span>
            <?php elseif ($has_saved_sheet):?>
            <span style="background:#fff7ed;color:var(--st-orange);padding:2px 9px;border-radius:10px;font-size:10px;font-weight:800;font-family:var(--display);"><i class="fa-solid fa-clock"></i> PENDING</span>
            <?php endif;?>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <?php if ($has_saved_sheet && !$is_confirmed):?>
            <span style="font-size:11px;color:var(--st-orange);font-weight:600;"><i class="fa-solid fa-pen-to-square" style="margin-right:4px;"></i>Click any row to edit &amp; view loans</span>
            <?php elseif ($is_confirmed):?>
            <span style="font-size:11px;color:#166534;font-weight:600;"><i class="fa-solid fa-lock" style="margin-right:4px;"></i>Sheet locked — loan payments recorded</span>
            <?php else:?>
            <span style="font-size:11px;color:var(--ink3);"><i class="fa-solid fa-hand-pointer" style="margin-right:4px;"></i>Click any row to view details</span>
            <?php endif;?>
        </div>
    </div>

    <div class="salary-scroll">
        <?php if (empty($employees)):?>
        <div style="text-align:center;padding:70px 20px;color:var(--ink3);">
            <i class="fa-solid fa-warehouse" style="font-size:44px;margin-bottom:14px;display:block;"></i>
            <p style="font-family:var(--display);font-size:18px;font-weight:800;">No Stores Staff employees found.</p>
            <p style="font-size:12px;margin-top:5px;">Ensure employees are assigned to an ST staff category or designation.</p>
        </div>
        <?php else:?>
        <table class="salary-table" id="salaryTable">
            <thead>
                <!-- GROUP ROW -->
                <tr>
                    <th colspan="5" class="th-group th-emp" style="text-align:left;">Employee</th>
                    <th colspan="2" class="th-group th-att" style="border-right:2px solid #153544;"><i class="fa-solid fa-calendar-day"></i> Attendance</th>
                    <th colspan="2" class="th-group th-attallow" style="border-right:2px solid #0a3020;"><i class="fa-solid fa-award"></i> Att. Allowance <span style="font-size:8px;opacity:.7;display:block;font-weight:400;">85% / 90% Tier</span></th>
                    <th colspan="2" class="th-group th-dam"   style="border-right:2px solid #5c1a1a;"><i class="fa-solid fa-triangle-exclamation"></i> Damage</th>
                    <th colspan="2" class="th-group th-rsqm"  style="border-right:2px solid #3d2a00;"><i class="fa-solid fa-chart-bar"></i> RSQM</th>
                    <th colspan="2" class="th-group th-punct" style="border-right:2px solid #2d1a5c;"><i class="fa-solid fa-clock"></i> Punctuality</th>
                    <th colspan="2" class="th-group th-lu"    style="border-right:2px solid #1a2e5c;"><i class="fa-solid fa-truck-loading"></i> L/Unload</th>
                    <th colspan="2" class="th-group th-ccfot" style="border-right:2px solid #153d1e;"><i class="fa-solid fa-percent"></i> CCFOT</th>
                    <th colspan="1" class="th-group th-reimb" style="border-right:2px solid #3d2500;"><i class="fa-solid fa-utensils"></i> Meal</th>
                    <th colspan="1" class="th-group th-earn"  style="border-right:2px solid #153d1e;"><i class="fa-solid fa-arrow-trend-up"></i> Earnings</th>
                    <th colspan="2" class="th-group th-ded"   style="border-right:2px solid #5c1a1a;"><i class="fa-solid fa-circle-minus"></i> Deductions</th>
                    <th colspan="1" class="th-group th-net"   style="border-right:2px solid #1a2e5c;"><i class="fa-solid fa-wallet"></i> Net</th>
                    <th colspan="1" class="th-group th-ben"   style="border-right:2px solid #3d2000;"><i class="fa-solid fa-star"></i> Benefit</th>
                    <th colspan="2" class="th-group th-loan"  style="border-right:2px solid #2d0f4a;"><i class="fa-solid fa-hand-holding-dollar"></i> Loans</th>
                    <th colspan="1" class="th-group th-emp"   style="width:44px;"></th>
                </tr>
                <!-- SUB ROW -->
                <tr>
                    <th class="col-s1 th-emp" style="text-align:left;">Emp. Code</th>
                    <th class="col-s2 th-emp" style="text-align:left;">EPF No.</th>
                    <th class="col-s3 th-emp" style="text-align:left;">Date of Join</th>
                    <th class="col-s4 th-emp" style="text-align:left;">Name &amp; Status</th>
                    <th class="col-s5 th-emp" style="text-align:left;">Category &amp; Designation</th>
                    <th class="th-att">Norm</th>
                    <th class="th-att" style="border-right:2px solid #153544;">Att. Days</th>
                    <th class="th-attallow">Tier</th>
                    <th class="th-attallow" style="border-right:2px solid #0a3020;">Amount</th>
                    <th class="th-dam">Ach%</th>
                    <th class="th-dam" style="border-right:2px solid #5c1a1a;">Amt</th>
                    <th class="th-rsqm">Ach%</th>
                    <th class="th-rsqm" style="border-right:2px solid #3d2a00;">Amt</th>
                    <th class="th-punct">Ach%</th>
                    <th class="th-punct" style="border-right:2px solid #2d1a5c;">Amt</th>
                    <th class="th-lu">Ach%</th>
                    <th class="th-lu" style="border-right:2px solid #1a2e5c;">Amt</th>
                    <th class="th-ccfot">Ach%</th>
                    <th class="th-ccfot" style="border-right:2px solid #153d1e;">Amt</th>
                    <th class="th-reimb" style="border-right:2px solid #3d2500;">Meal</th>
                    <th class="th-earn"  style="border-right:2px solid #153d1e;">Total Earnings</th>
                    <th class="th-ded">Loan Ded.</th>
                    <th class="th-ded"  style="border-right:2px solid #5c1a1a;">Total Ded.</th>
                    <th class="th-net"  style="border-right:2px solid #1a2e5c;">Net Salary</th>
                    <th class="th-ben"  style="border-right:2px solid #3d2000;">Benefit</th>
                    <th class="th-loan">Active</th>
                    <th class="th-loan" style="border-right:2px solid #2d0f4a;">Balance</th>
                    <th class="th-emp"  style="width:44px;"></th>
                </tr>
            </thead>
            <tbody id="salaryTableBody">
            <?php
            $gt_earn=$gt_ded=$gt_net=$gt_ben=$gt_attallow=$gt_damage=$gt_rsqm=0;
            $gt_punct=$gt_lu=$gt_ccfot=$gt_meal=$gt_loan_ded=$gt_no_pay=0;
            foreach ($employees as $emp):
                $eid = (int)$emp['id'];
                $r   = $rows[$eid];
                $att = $r['att'];
                $sc  = ['Probation'=>'s-probation','Permanent'=>'s-permanent',
                        'Resigned' =>'s-resigned', 'Terminated'=>'s-terminated'][$emp['status']] ?? '';
                $att_cls = $att['rate']>=90?'att-high':($att['rate']>=70?'att-mid':($att['rate']>0?'att-low':'att-zero'));
                $fv = fn($v) => $v>0 ? number_format($v,2) : '<span class="m-zero">—</span>';

                $gt_earn     += $r['total_earnings'];  $gt_ded       += $r['total_deductions'];
                $gt_net      += $r['net_salary'];      $gt_ben       += $r['total_benefit'];
                $gt_attallow += $r['att_allow'];       $gt_damage    += $r['stores_damage_amt'];
                $gt_rsqm     += $r['rsqm_amt'];        $gt_punct     += $r['punctuality_amt'];
                $gt_lu       += $r['loading_unloading_amt']; $gt_ccfot += $r['ccfot_amt'];
                $gt_meal     += $r['meal'];            $gt_loan_ded  += $r['loan_ded'];
                $gt_no_pay   += $r['no_pay_amount'];

                $emp_loan_count     = count($r['emp_loans']);
                $loan_total_balance = array_sum(array_column($r['emp_loans'], 'balance'));

                // RSQM tier badge
                $rsqm_tcls = $r['rsqm_ach']>=70?'tier-t2':($r['rsqm_ach']>=60?'tier-t1':'');
                $rsqm_ttxt = $r['rsqm_ach']>=70?'T2':($r['rsqm_ach']>=60?'T1':'');
                // Punct tier badge
                $punct_tcls = $r['punctuality_ach']>=85?'tier-t2':($r['punctuality_ach']>=75?'tier-t1':'');
                $punct_ttxt = $r['punctuality_ach']>=85?'T2':($r['punctuality_ach']>=75?'T1':'');
                // Att allow tier badge
                $allow_tcls = $r['att_allow_tier']==='90%'?'attallow-t90':($r['att_allow_tier']==='85%'?'attallow-t85':'attallow-none');
                $allow_ttxt = $r['att_allow_tier'] ?: '—';
            ?>
            <tr class="emp-row" id="row_<?php echo $eid;?>"
                onclick="openModal(<?php echo $eid;?>)" style="cursor:pointer;"
                data-search="<?php echo strtolower(htmlspecialchars(
                    ($emp['employee_id']??'').' '.($emp['employee_full_name']??'').' '.
                    ($emp['epf_number']??'').' '.($emp['designation_name']??'')));?>">

                <!-- STICKY EMPLOYEE COLUMNS -->
                <td class="col-s1 td-left">
                    <div class="emp-id-tag"><?php echo htmlspecialchars($emp['employee_id']);?></div>
                    <div class="emp-meta" style="margin-top:2px;"><?php echo htmlspecialchars(($emp['company_code']??'').($emp['branch_code']?' · '.$emp['branch_code']:''));?></div>
                </td>
                <td class="col-s2 td-left">
                    <span style="font-family:var(--mono);font-size:10px;color:var(--ink2);"><?php echo $emp['epf_number']?htmlspecialchars($emp['epf_number']):'<span class="m-zero">—</span>';?></span>
                </td>
                <td class="col-s3 td-left">
                    <span style="font-family:var(--mono);font-size:10px;color:var(--ink2);"><?php echo $emp['date_of_join']?date('d M Y',strtotime($emp['date_of_join'])):'—';?></span>
                </td>
                <td class="col-s4 td-left">
                    <div class="emp-name-cell"><?php echo htmlspecialchars($emp['employee_full_name']);?></div>
                    <span class="s-badge <?php echo $sc;?>" style="margin-top:2px;"><?php echo $emp['status'];?></span>
                </td>
                <td class="col-s5 td-left">
                    <span class="cat-chip">ST</span>
                    <div class="emp-meta" style="margin-top:3px;"><?php echo htmlspecialchars($emp['designation_name']??'');?></div>
                </td>

                <!-- ATTENDANCE -->
                <td class="td-att td-center"><span style="font-family:var(--mono);font-size:11px;font-weight:700;"><?php echo $att['norm'];?></span></td>
                <td class="td-att td-center" style="border-right:2px solid #dde8f8;">
                    <span class="att-pill <?php echo $att_cls;?>"><?php echo $att['att'];?> <span style="opacity:.55;">(<?php echo $att['rate'];?>%)</span></span>
                </td>

                <!-- ATTENDANCE ALLOWANCE TIER -->
                <td class="td-attallow td-center">
                    <span class="attallow-tier <?php echo $allow_tcls;?>"><?php echo $allow_ttxt;?></span>
                    <?php if ($r['cfg_att_allow_85']>0 || $r['cfg_att_allow_90']>0):?>
                    <div style="font-size:8px;color:#0f766e;margin-top:2px;line-height:1.3;">
                        <?php if ($r['cfg_att_allow_85']>0):?>85%→<?php echo number_format($r['cfg_att_allow_85'],0);?><?php endif;?>
                        <?php if ($r['cfg_att_allow_90']>0):?> / 90%→<?php echo number_format($r['cfg_att_allow_90'],0);?><?php endif;?>
                    </div>
                    <?php endif;?>
                </td>
                <td class="td-attallow" style="border-right:2px solid #86efac;">
                    <span class="m-teal"><?php echo $fv($r['att_allow']);?></span>
                </td>

                <!-- STORES DAMAGE -->
                <td class="td-dam td-center">
                    <?php if ($r['stores_damage_ach']>0):?><span style="font-family:var(--mono);font-size:10.5px;color:#9ca3af;"><?php echo number_format($r['stores_damage_ach'],1);?>%</span><?php else:?><span class="m-zero">—</span><?php endif;?>
                </td>
                <td class="td-dam" style="border-right:2px solid #fca5a5;"><span class="m-earn"><?php echo $fv($r['stores_damage_amt']);?></span></td>

                <!-- RSQM -->
                <td class="td-rsqm td-center">
                    <?php if ($r['rsqm_ach']>0):?>
                    <span style="font-family:var(--mono);font-size:10.5px;"><?php echo number_format($r['rsqm_ach'],1);?>%</span>
                    <?php if ($rsqm_ttxt):?><span class="tier-badge <?php echo $rsqm_tcls;?>"><?php echo $rsqm_ttxt;?></span><?php endif;?>
                    <?php else:?><span class="m-zero">—</span><?php endif;?>
                </td>
                <td class="td-rsqm" style="border-right:2px solid #fde68a;"><span class="m-incent"><?php echo $fv($r['rsqm_amt']);?></span></td>

                <!-- PUNCTUALITY -->
                <td class="td-punct td-center">
                    <?php if ($r['punctuality_ach']>0):?>
                    <span style="font-family:var(--mono);font-size:10.5px;"><?php echo number_format($r['punctuality_ach'],1);?>%</span>
                    <?php if ($punct_ttxt):?><span class="tier-badge <?php echo $punct_tcls;?>"><?php echo $punct_ttxt;?></span><?php endif;?>
                    <?php else:?><span class="m-zero">—</span><?php endif;?>
                </td>
                <td class="td-punct" style="border-right:2px solid #ddd6fe;"><span class="m-incent" style="color:#6d28d9;"><?php echo $fv($r['punctuality_amt']);?></span></td>

                <!-- LOADING/UNLOADING -->
                <td class="td-lu td-center">
                    <?php if ($r['loading_unloading_ach']>0):?>
                    <span style="font-family:var(--mono);font-size:10.5px;color:#1d4ed8;"><?php echo number_format($r['loading_unloading_ach'],1);?>%
                    <?php echo $r['loading_unloading_ach']>=90?'<span class="tier-badge tier-met">✓</span>':'<span class="tier-badge tier-miss">✗</span>';?></span>
                    <?php else:?><span class="m-zero">—</span><?php endif;?>
                </td>
                <td class="td-lu" style="border-right:2px solid #bfdbfe;"><span class="m-incent" style="color:#1d4ed8;"><?php echo $fv($r['loading_unloading_amt']);?></span></td>

                <!-- CCFOT -->
                <td class="td-ccfot td-center">
                    <?php if ($r['ccfot_ach']>0):?>
                    <span style="font-family:var(--mono);font-size:10.5px;color:#166534;"><?php echo number_format($r['ccfot_ach'],1);?>%
                    <?php echo $r['ccfot_ach']>=90?'<span class="tier-badge tier-met">✓</span>':'<span class="tier-badge tier-miss">✗</span>';?></span>
                    <?php else:?><span class="m-zero">—</span><?php endif;?>
                </td>
                <td class="td-ccfot" style="border-right:2px solid #86efac;"><span class="m-earn"><?php echo $fv($r['ccfot_amt']);?></span></td>

                <!-- MEAL REIMB -->
                <td class="td-reimb" style="border-right:2px solid #fed7aa;">
                    <span style="font-family:var(--mono);font-size:11px;color:#c2410c;"><?php echo $fv($r['meal']);?></span>
                </td>

                <!-- TOTAL EARNINGS -->
                <td class="td-earn" style="border-right:2px solid #166634;">
                    <span class="m-earn" style="font-size:12.5px;font-weight:800;" id="earn_<?php echo $eid;?>"><?php echo $fv($r['total_earnings']);?></span>
                </td>

                <!-- LOAN DED -->
                <td class="td-loan">
                    <?php if ($r['loan_ded']>0):?>
                    <span class="m-loan" id="lded_<?php echo $eid;?>"><?php echo number_format($r['loan_ded'],2);?></span>
                    <span class="src-badge src-loan"><?php echo $emp_loan_count;?>L</span>
                    <?php else:?><span class="m-zero">—</span><?php endif;?>
                </td>
                <!-- TOTAL DED -->
                <td class="td-ded" style="border-right:2px solid #fca5a5;">
                    <span class="m-ded" id="ded_<?php echo $eid;?>"><?php echo $fv($r['total_deductions']);?></span>
                </td>
                <!-- NET -->
                <td class="td-net" style="border-right:2px solid #93c5fd;">
                    <span class="m-net" id="net_<?php echo $eid;?>"><?php echo $fv($r['net_salary']);?></span>
                </td>
                <!-- BENEFIT -->
                <td class="td-ben" style="border-right:2px solid #fde68a;">
                    <span class="m-ben" id="ben_<?php echo $eid;?>"><?php echo $fv($r['total_benefit']);?></span>
                </td>

                <!-- LOANS -->
                <td class="td-loan td-center">
                    <?php if ($emp_loan_count>0):?><span class="loan-count-badge"><i class="fa-solid fa-hand-holding-dollar"></i> <?php echo $emp_loan_count;?></span>
                    <?php else:?><span class="loan-count-badge loan-count-zero">—</span><?php endif;?>
                </td>
                <td class="td-loan" style="border-right:2px solid #c4b5fd;">
                    <?php if ($loan_total_balance>0):?><div style="font-family:var(--mono);font-size:10px;color:#7c3aed;"><?php echo number_format($loan_total_balance,2);?></div>
                    <?php else:?><span class="m-zero">—</span><?php endif;?>
                </td>

                <!-- ACTION -->
                <td class="td-center" onclick="event.stopPropagation()">
                    <button class="row-btn" onclick="openModal(<?php echo $eid;?>)" title="View breakdown"><i class="fa-solid fa-expand"></i></button>
                </td>
            </tr>
            <?php endforeach;?>

            <!-- GRAND TOTAL -->
            <tr class="tr-total">
                <td class="col-s1 td-left" colspan="5">
                    <span style="font-family:var(--display);font-size:12px;font-weight:900;letter-spacing:.5px;">
                        <i class="fa-solid fa-sigma" style="margin-right:6px;color:var(--st-amber);"></i>GRAND TOTAL
                    </span>
                    <span style="font-size:10px;color:#9ca3af;margin-left:8px;"><?php echo count($employees);?> employees</span>
                </td>
                <td></td><td style="border-right:2px solid #153544;"></td>
                <!-- att allow -->
                <td></td><td style="color:#6ee7b7;font-weight:800;border-right:2px solid #0a3020;">LKR <?php echo number_format($gt_attallow,2);?></td>
                <!-- damage -->
                <td></td><td style="color:#fca5a5;border-right:2px solid #5c1a1a;">LKR <?php echo number_format($gt_damage,2);?></td>
                <!-- rsqm -->
                <td></td><td style="color:#fde047;border-right:2px solid #3d2a00;">LKR <?php echo number_format($gt_rsqm,2);?></td>
                <!-- punct -->
                <td></td><td style="color:#d8b4fe;border-right:2px solid #2d1a5c;">LKR <?php echo number_format($gt_punct,2);?></td>
                <!-- lu -->
                <td></td><td style="color:#93c5fd;border-right:2px solid #1a2e5c;">LKR <?php echo number_format($gt_lu,2);?></td>
                <!-- ccfot -->
                <td></td><td style="color:#86efac;border-right:2px solid #153d1e;">LKR <?php echo number_format($gt_ccfot,2);?></td>
                <!-- meal -->
                <td style="color:#fdba74;border-right:2px solid #3d2500;">LKR <?php echo number_format($gt_meal,2);?></td>
                <!-- earnings -->
                <td style="color:#6ee7b7;font-weight:800;border-right:2px solid #153d1e;">LKR <?php echo number_format($gt_earn,2);?></td>
                <!-- ded -->
                <td style="color:#d8b4fe;">LKR <?php echo number_format($gt_loan_ded,2);?></td>
                <td style="color:#fca5a5;border-right:2px solid #5c1a1a;">LKR <?php echo number_format($gt_ded,2);?></td>
                <!-- net -->
                <td style="color:#93c5fd;font-weight:800;border-right:2px solid #1a2e5c;">LKR <?php echo number_format($gt_net,2);?></td>
                <!-- ben -->
                <td style="color:#fde047;border-right:2px solid #3d2000;">LKR <?php echo number_format($gt_ben,2);?></td>
                <td></td><td></td><td></td>
            </tr>
            </tbody>
        </table>
        <?php endif;?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     DETAIL MODAL
═══════════════════════════════════════════════════════════ -->
<div class="modal-bg" id="detailModal" onclick="if(event.target===this)closeModal()">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <div class="modal-emp-name" id="m-name">—</div>
                <div class="modal-emp-meta">
                    <span><i class="fa-solid fa-id-badge" style="color:var(--st-orange);"></i> <span id="m-code">—</span></span>
                    <span><i class="fa-solid fa-briefcase" style="color:var(--ink3);"></i> <span id="m-desig">—</span></span>
                    <span id="m-status-wrap"></span>
                    <span><i class="fa-solid fa-calendar" style="color:var(--ink3);"></i> <span id="m-join">—</span></span>
                    <span><i class="fa-solid fa-hashtag" style="color:#059669;"></i> EPF <span id="m-epf">—</span></span>
                </div>
            </div>
            <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="modalBody"></div>
        <?php if ($has_saved_sheet && !$is_confirmed):?>
        <div class="modal-update-bar">
            <div class="modal-update-info"><i class="fa-solid fa-pen-to-square"></i> Edit Excess, Arrears &amp; add custom rows, then click Update.</div>
            <div style="display:flex;align-items:center;gap:10px;">
                <span class="modal-update-status" id="modalUpdateStatus"></span>
                <button class="btn btn-orange btn-sm" id="modalUpdateBtn" onclick="saveModalOverrides()">
                    <i class="fa-solid fa-floppy-disk"></i> Update &amp; Save
                </button>
            </div>
        </div>
        <?php endif;?>
        <div class="modal-footer">
            <div class="modal-footer-links" id="m-links"></div>
            <button class="btn btn-ghost btn-sm" onclick="closeModal()"><i class="fa-solid fa-xmark"></i> Close</button>
        </div>
    </div>
</div>

<!-- CONFIRM DELETE -->
<div class="confirm-bg" id="confirmModal">
    <div class="confirm-box">
        <h3><i class="fa-solid fa-trash" style="color:#dc2626;margin-right:8px;"></i>Delete Salary Sheet?</h3>
        <p>This will permanently delete the saved sheet for <strong><?php echo $active_period?$month_names[$active_period['month']].' '.$active_period['year']:'this period';?></strong>. All saved values will be lost.</p>
        <div class="confirm-actions">
            <button class="btn btn-ghost btn-sm" onclick="document.getElementById('confirmModal').classList.remove('show')">Cancel</button>
            <button class="btn btn-red btn-sm" onclick="deleteSheet()"><i class="fa-solid fa-trash"></i> Yes, Delete</button>
        </div>
    </div>
</div>

<!-- CONFIRM REFRESH -->
<div class="confirm-bg" id="confirmRefreshModal">
    <div class="confirm-box">
        <h3><i class="fa-solid fa-arrows-rotate" style="color:var(--st-amber);margin-right:8px;"></i>Refresh from Live Data?</h3>
        <p>This will <strong>overwrite</strong> the saved sheet with latest live data. All previously saved edits will be lost.</p>
        <div class="confirm-actions">
            <button class="btn btn-ghost btn-sm" onclick="document.getElementById('confirmRefreshModal').classList.remove('show')">Cancel</button>
            <button class="btn btn-amber btn-sm" onclick="doRefreshSheet()"><i class="fa-solid fa-arrows-rotate"></i> Yes, Refresh</button>
        </div>
    </div>
</div>

<!-- CONFIRM SHEET -->
<div class="confirm-bg" id="confirmSheetModal">
    <div class="confirm-box" style="max-width:510px;">
        <h3><i class="fa-solid fa-check-double" style="color:var(--st-orange);margin-right:8px;"></i>Confirm Salary Sheet?</h3>
        <p>This will <strong>lock</strong> the sheet for <strong><?php echo $active_period?$month_names[$active_period['month']].' '.$active_period['year']:'this period';?></strong> and record <strong>loan payment entries</strong> for all employees with active loans.</p>
        <div style="background:#fff7ed;border:1px solid #fdba74;border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:12.5px;color:#7c2d12;">
            <i class="fa-solid fa-circle-info" style="margin-right:5px;"></i>
            <ul style="margin:5px 0 0 18px;line-height:2;">
                <li>Sheet status → <strong>Confirmed</strong> (locked)</li>
                <li>Loan instalments recorded in <code>loan_payments</code></li>
                <li>Fully paid loans marked <strong>Completed</strong></li>
                <li>This action <strong>cannot be undone</strong></li>
            </ul>
        </div>
        <div id="confirmSheetResult"></div>
        <div class="confirm-actions" id="confirmSheetActions">
            <button class="btn btn-ghost btn-sm" onclick="document.getElementById('confirmSheetModal').classList.remove('show')">Cancel</button>
            <button class="btn btn-orange btn-sm" id="confirmSheetBtn" onclick="doConfirmSheet()">
                <i class="fa-solid fa-check-double"></i> Yes, Confirm &amp; Record Payments
            </button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     JAVASCRIPT DATA + LOGIC
═══════════════════════════════════════════════════════════ -->
<script>
const HAS_SAVED_SHEET = <?php echo $has_saved_sheet?'true':'false';?>;
const IS_CONFIRMED    = <?php echo $is_confirmed?'true':'false';?>;
const PERIOD_ID       = <?php echo (int)$sel_period_id;?>;

const ST_DATA = <?php
$js_data = [];
foreach ($employees as $emp) {
    $eid = (int)$emp['id'];
    $r   = $rows[$eid];
    $att = $r['att'];
    $js_data[$eid] = [
        'employee_db_id'   => $eid,
        'name'             => $emp['employee_full_name'],
        'code'             => $emp['employee_id'],
        'desig'            => $emp['designation_name'] ?? '',
        'cat_code'         => 'ST',
        'status'           => $emp['status'],
        'join'             => $emp['date_of_join'] ? date('d M Y', strtotime($emp['date_of_join'])) : '—',
        'join_raw'         => $emp['date_of_join'] ?? '',
        'epf'              => $emp['epf_number'] ?? '—',
        'company_code'     => $emp['company_code'] ?? '',
        'branch_code'      => $emp['branch_code']  ?? '',
        'att_count'        => $att['att'],
        'norm_days'        => $att['norm'],
        'holidays'         => $att['holidays'],
        'att_rate'         => $att['rate'],
        'per_day'          => $r['per_day'],
        'no_pay_days'      => $r['no_pay_days'],
        'no_pay_amount'    => $r['no_pay_amount'],
        'att_allow'        => $r['att_allow'],
        'att_allow_tier'   => $r['att_allow_tier'],
        'cfg_att_allow_85' => $r['cfg_att_allow_85'],
        'cfg_att_allow_90' => $r['cfg_att_allow_90'],
        'basic'            => $r['basic'],
        'stores_damage_ach'=> $r['stores_damage_ach'],
        'stores_damage_amt'=> $r['stores_damage_amt'],
        'rsqm_ach'         => $r['rsqm_ach'],
        'rsqm_amt'         => $r['rsqm_amt'],
        'rsqm_tier_label'  => $r['rsqm_tier_label'],
        'punctuality_ach'  => $r['punctuality_ach'],
        'punctuality_amt'  => $r['punctuality_amt'],
        'punct_tier_label' => $r['punct_tier_label'],
        'loading_unloading_ach' => $r['loading_unloading_ach'],
        'loading_unloading_amt' => $r['loading_unloading_amt'],
        'ccfot_ach'        => $r['ccfot_ach'],
        'ccfot_amt'        => $r['ccfot_amt'],
        'meal'             => $r['meal'],
        'cfg_meal'         => $r['cfg_meal'],
        'arrears'          => $r['arrears'],
        'insurance'        => $r['insurance'],
        'total_earnings'   => $r['total_earnings'],
        'epf_emp'          => $r['epf_emp'],
        'welfare_amt'      => $r['welfare_amt'],
        'w_loan'           => $r['w_loan'],
        'w_soc'            => $r['w_soc'],
        'donations'        => $r['donations'],
        'sal_adv'          => $r['sal_adv'],
        'advance_from_entry'=> $r['advance_from_entry'],
        'loan_ded'         => $r['loan_ded'],
        'credit_r'         => $r['credit_r'],
        'retention'        => $r['retention'],
        'excess_p'         => $r['excess_p'],
        'total_deductions' => $r['total_deductions'],
        'net_salary'       => $r['net_salary'],
        'epf_er'           => $r['epf_er'],
        'etf_er'           => $r['etf_er'],
        'bonus'            => $r['bonus'],
        'gratuity'         => $r['gratuity'],
        'total_benefit'    => $r['total_benefit'],
        'cost_bp'          => $r['cost_bp'],
        'emp_loans'        => $r['emp_loans'],
        'additional_earnings'   => $r['add_earn_saved']  ?: [],
        'additional_deductions' => $r['add_ded_saved']   ?: [],
    ];
}
echo json_encode($js_data);
?>;

let _currentEid=null, _addEarnRows=[], _addDedRows=[];

// ── Helpers ───────────────────────────────────────────────────────────────────
function lkr(v){v=parseFloat(v||0);if(v<=0)return '<span style="color:#d1d5db;">—</span>';return '<span><span style="font-size:9px;opacity:.55;margin-right:1px;">LKR</span>'+v.toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2})+'</span>';}
function lkrPlain(v){return 'LKR '+parseFloat(v||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});}
function fmt(v){return parseFloat(v||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});}
function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function advBadge(f){return f?'<span class="src-badge src-live">Period</span>':'<span class="src-badge src-emp">Profile</span>';}
function attAllowTierBadge(tier){
    if(tier==='90%') return '<span class="attallow-tier attallow-t90">90% Tier</span>';
    if(tier==='85%') return '<span class="attallow-tier attallow-t85">85% Tier</span>';
    return '<span class="attallow-tier attallow-none">No Tier</span>';
}

// ── SHEET ACTIONS ─────────────────────────────────────────────────────────────
function createSalarySheet(){
    document.getElementById('saveRowsJson').value=JSON.stringify(Object.values(ST_DATA));
    document.getElementById('saveSheetForm').submit();
}
function refreshSheet(){document.getElementById('confirmRefreshModal').classList.add('show');}
function doRefreshSheet(){
    document.getElementById('confirmRefreshModal').classList.remove('show');
    fetch('st_salary.php?ajax=delete_sheet&period_id='+PERIOD_ID).then(()=>{
        document.getElementById('saveRowsJson').value=JSON.stringify(Object.values(ST_DATA));
        document.getElementById('saveSheetForm').submit();
    });
}
function confirmDeleteSheet(){document.getElementById('confirmModal').classList.add('show');}
function deleteSheet(){
    document.getElementById('confirmModal').classList.remove('show');
    fetch('st_salary.php?ajax=delete_sheet&period_id='+PERIOD_ID).then(r=>r.json()).then(d=>{
        if(d.ok) window.location.href='st_salary.php?period_id='+PERIOD_ID;
        else alert(d.msg||'Cannot delete.');
    });
}
function confirmSheetModal(){
    document.getElementById('confirmSheetResult').innerHTML='';
    document.getElementById('confirmSheetActions').style.display='flex';
    const btn=document.getElementById('confirmSheetBtn');
    btn.disabled=false;
    btn.innerHTML='<i class="fa-solid fa-check-double"></i> Yes, Confirm &amp; Record Payments';
    document.getElementById('confirmSheetModal').classList.add('show');
}
function doConfirmSheet(){
    const btn=document.getElementById('confirmSheetBtn');
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Processing…';
    fetch('st_salary.php?ajax=confirm_sheet&period_id='+PERIOD_ID).then(r=>r.json()).then(d=>{
        const res=document.getElementById('confirmSheetResult');
        if(d.ok){
            res.innerHTML=`<div class="confirm-result-banner crb-success"><i class="fa-solid fa-circle-check"></i> <strong>Sheet confirmed!</strong> ${d.payments_created} loan payment${d.payments_created!==1?'s':''} recorded.</div>`;
            document.getElementById('confirmSheetActions').innerHTML='<button class="btn btn-primary btn-sm" onclick="window.location.reload()"><i class="fa-solid fa-rotate-right"></i> Reload Page</button>';
        } else {
            res.innerHTML=`<div class="confirm-result-banner crb-error"><i class="fa-solid fa-circle-exclamation"></i> ${d.msg||'Error confirming.'}</div>`;
            btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-check-double"></i> Yes, Confirm &amp; Record Payments';
        }
    });
}

// ── ADDITIONAL ROWS ───────────────────────────────────────────────────────────
function addEarnRow(d,a){_addEarnRows.push({desc:d||'',amount:parseFloat(a||0)});renderAddRows();}
function addDedRow(d,a){_addDedRows.push({desc:d||'',amount:parseFloat(a||0)});renderAddRows();}
function removeEarnRow(i){_addEarnRows.splice(i,1);renderAddRows();}
function removeDedRow(i){_addDedRows.splice(i,1);renderAddRows();}

function renderAddRows(){
    ['Earn','Ded'].forEach(type=>{
        const arr=type==='Earn'?_addEarnRows:_addDedRows;
        const container=document.getElementById('add'+type+'Rows');
        const totalEl  =document.getElementById('add'+type+'Total');
        if(!container) return;
        let sum=0; container.innerHTML='';
        arr.forEach((row,i)=>{
            sum+=parseFloat(row.amount||0);
            const div=document.createElement('div');
            div.className='add-row-item';
            div.innerHTML=`<input type="text" class="add-row-desc" placeholder="Description" value="${escHtml(row.desc)}" oninput="_add${type}Rows[${i}].desc=this.value">
                <input type="number" step="0.01" min="0" class="add-row-amount${type==='Ded'?' is-ded':''}" placeholder="0.00" value="${row.amount>0?row.amount.toFixed(2):''}" oninput="_add${type}Rows[${i}].amount=parseFloat(this.value)||0;updateAddTotals()">
                <button class="add-row-remove" onclick="remove${type}Row(${i})"><i class="fa-solid fa-xmark"></i></button>`;
            container.appendChild(div);
        });
        if(totalEl) totalEl.textContent='Total: LKR '+sum.toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
    });
}
function updateAddTotals(){
    const es=_addEarnRows.reduce((s,r)=>s+parseFloat(r.amount||0),0);
    const ds=_addDedRows.reduce((s,r)=>s+parseFloat(r.amount||0),0);
    const et=document.getElementById('addEarnTotal'),dt=document.getElementById('addDedTotal');
    if(et) et.textContent='Total: LKR '+es.toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
    if(dt) dt.textContent='Total: LKR '+ds.toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
}

// ── SAVE OVERRIDES ────────────────────────────────────────────────────────────
function saveModalOverrides(){
    const eid=_currentEid; if(!eid) return;
    const exEl=document.getElementById('m_excess'), arEl=document.getElementById('m_arrears');
    if(!exEl||!arEl) return;
    const excess  =parseFloat(exEl.value||0);
    const arrears =parseFloat(arEl.value||0);
    const addEarn =_addEarnRows.filter(r=>r.desc.trim()!==''||r.amount>0).map(r=>({desc:r.desc.trim(),amount:parseFloat(r.amount||0)}));
    const addDed  =_addDedRows.filter(r=>r.desc.trim()!==''||r.amount>0).map(r=>({desc:r.desc.trim(),amount:parseFloat(r.amount||0)}));
    const btn=document.getElementById('modalUpdateBtn'),status=document.getElementById('modalUpdateStatus');
    if(btn){btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';}
    if(status) status.innerHTML='<span class="upd-saving"><i class="fa-solid fa-circle-notch fa-spin"></i> Saving…</span>';
    fetch('st_salary.php?ajax=update_overrides',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({period_id:PERIOD_ID,employee_id:eid,excess,arrears,additional_earnings:addEarn,additional_deductions:addDed})})
    .then(r=>r.json()).then(d=>{
        if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Update &amp; Save';}
        if(d.ok){
            if(status) status.innerHTML='<span class="upd-saved"><i class="fa-solid fa-circle-check"></i> Saved!</span>';
            setTimeout(()=>{if(status)status.innerHTML='';},3000);
            const upd=v=>'<span><span style="font-size:9px;opacity:.55;margin-right:1px;">LKR</span>'+fmt(v)+'</span>';
            [{k:'earn',f:'total_earnings'},{k:'ded',f:'total_deductions'},{k:'net',f:'net_salary'},{k:'ben',f:'total_benefit'}]
                .forEach(o=>{const el=document.getElementById(o.k+'_'+eid);if(el)el.innerHTML=upd(d[o.f]);});
            ['nh_earn','nh_ded','nh_net','nh_ben'].forEach((id,i)=>{
                const el=document.getElementById(id);
                if(el) el.textContent=lkrPlain([d.total_earnings,d.total_deductions,d.net_salary,d.total_benefit][i]);
            });
            if(ST_DATA[eid]){
                ST_DATA[eid].excess_p=excess; ST_DATA[eid].arrears=arrears;
                ST_DATA[eid].total_earnings=d.total_earnings; ST_DATA[eid].total_deductions=d.total_deductions;
                ST_DATA[eid].net_salary=d.net_salary; ST_DATA[eid].total_benefit=d.total_benefit;
                ST_DATA[eid].additional_earnings=addEarn; ST_DATA[eid].additional_deductions=addDed;
            }
        } else { if(status) status.innerHTML='<span class="upd-error"><i class="fa-solid fa-circle-exclamation"></i> '+(d.msg||'Error')+'</span>'; }
    }).catch(()=>{
        if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Update &amp; Save';}
        if(status) status.innerHTML='<span class="upd-error">Network error</span>';
    });
}

// ── OPEN MODAL ────────────────────────────────────────────────────────────────
function openModal(eid){
    const d=ST_DATA[eid]; if(!d) return;
    _currentEid=eid;
    _addEarnRows=(d.additional_earnings||[]).map(r=>({desc:r.desc||'',amount:parseFloat(r.amount||0)}));
    _addDedRows =(d.additional_deductions||[]).map(r=>({desc:r.desc||'',amount:parseFloat(r.amount||0)}));

    document.getElementById('m-name').textContent=d.name;
    document.getElementById('m-code').textContent=d.code;
    document.getElementById('m-desig').textContent='ST · '+d.desig;
    document.getElementById('m-join').textContent=d.join;
    document.getElementById('m-epf').textContent=d.epf;
    const sc={'Probation':'background:#fff7ed;color:#92400e','Permanent':'background:#dcfce7;color:#15803d','Resigned':'background:#f3f4f6;color:#6b7280','Terminated':'background:#fee2e2;color:#991b1b'};
    document.getElementById('m-status-wrap').innerHTML=
        '<span style="display:inline-block;padding:2px 9px;border-radius:10px;font-size:10px;font-weight:700;font-family:var(--display);'+(sc[d.status]||'background:#f3f4f6;color:#888')+'">'+d.status+'</span>';
    document.getElementById('m-links').innerHTML=
        '<a href="view_employee.php?id='+eid+'" class="btn btn-ghost btn-sm"><i class="fa-solid fa-user"></i> Profile</a>'+
        '<a href="salary_advance.php?employee_id='+eid+'&payroll_period_id='+PERIOD_ID+'" class="btn btn-ghost btn-sm"><i class="fa-solid fa-hand-holding-dollar"></i> Advances</a>'+
        '<a href="loans.php?employee_id='+eid+'" class="btn btn-purple btn-sm"><i class="fa-solid fa-file-invoice-dollar"></i> Loans</a>'+
        '<a href="incentive_entry.php?period_id='+PERIOD_ID+'" class="btn btn-orange btn-sm"><i class="fa-solid fa-percent"></i> Incentive Entry</a>';

    let html='';

    // ── NET HERO BAND ──────────────────────────────────────────────────────────
    html+='<div class="net-hero-band">';
    html+='<div class="nh-item"><div class="nh-label">Total Earnings</div><div class="nh-value nh-earn" id="nh_earn">'+lkrPlain(d.total_earnings)+'</div></div>';
    html+='<div class="nh-div"></div>';
    html+='<div class="nh-item"><div class="nh-label">Total Deductions</div><div class="nh-value nh-ded" id="nh_ded">'+lkrPlain(d.total_deductions)+'</div></div>';
    html+='<div class="nh-div"></div>';
    html+='<div class="nh-item"><div class="nh-label">✦ Net Salary</div><div class="nh-value nh-net" id="nh_net">'+lkrPlain(d.net_salary)+'</div></div>';
    html+='<div class="nh-div"></div>';
    html+='<div class="nh-item"><div class="nh-label">Total Benefit</div><div class="nh-value nh-ben" id="nh_ben">'+lkrPlain(d.total_benefit)+'</div></div>';
    html+='<div class="nh-div"></div>';
    html+='<div class="nh-item"><div class="nh-label">Incentives + Allow.</div><div class="nh-value nh-incent">'+lkrPlain(d.stores_damage_amt+d.rsqm_amt+d.punctuality_amt+d.loading_unloading_amt+d.ccfot_amt+d.att_allow)+'</div></div>';
    html+='</div>';

    // ── ATT + ALLOWANCE BAND ──────────────────────────────────────────────────
    const rc=d.att_rate>=90?'#ccfbf1;color:#0f766e':d.att_rate>=85?'#dcfce7;color:#14532d':d.att_rate>=70?'#fef9c3;color:#78350f':'#fee2e2;color:#7f1d1d';
    html+='<div class="att-band">';
    html+='<div class="att-card"><div class="att-card-label">Norm Days</div><div class="att-card-value">'+d.norm_days+'</div><div class="att-card-sub">Month − '+d.holidays+' holidays</div></div>';
    html+='<div class="att-card"><div class="att-card-label">Attended</div><div class="att-card-value">'+d.att_count+'</div><div class="att-card-sub">days present</div></div>';
    html+='<div class="att-card"><div class="att-card-label">Att. Rate</div><div class="att-card-value" style="background:'+rc+';padding:3px 8px;border-radius:6px;font-size:15px;">'+d.att_rate+'%</div><div class="att-card-sub">att ÷ norm × 100</div></div>';
    html+='<div class="att-card"><div class="att-card-label">Per Day</div><div class="att-card-value" style="font-size:13px;">'+(d.per_day>0?'LKR '+parseFloat(d.per_day).toFixed(2):'—')+'</div><div class="att-card-sub">basic ÷ norm</div></div>';
    html+='<div class="att-card"><div class="att-card-label">No Pay Days</div><div class="att-card-value" style="color:'+(d.no_pay_days>0?'#991b1b':'#16a34a')+'">'+(d.no_pay_days>0?d.no_pay_days:'✓ 0')+'</div><div class="att-card-sub">unpaid absence</div></div>';
    html+='<div class="att-card"><div class="att-card-label">No Pay Ded.</div><div class="att-card-value" style="font-size:13px;color:'+(d.no_pay_amount>0?'#991b1b':'#16a34a')+'">'+(d.no_pay_amount>0?'LKR '+parseFloat(d.no_pay_amount).toFixed(2):'✓ None')+'</div><div class="att-card-sub">per_day × no_pay</div></div>';
    // Special teal card for attendance allowance
    html+='<div class="att-card card-attallow">';
    html+='<div class="att-card-label">Att. Allowance</div>';
    html+='<div class="att-card-value" style="font-size:15px;">'+(d.att_allow>0?'LKR '+parseFloat(d.att_allow).toFixed(2):'—')+'</div>';
    html+='<div class="att-card-sub">'+attAllowTierBadge(d.att_allow_tier);
    if(d.cfg_att_allow_85>0||d.cfg_att_allow_90>0){
        html+=' <span style="font-size:9px;color:#0f766e;opacity:.8;">';
        if(d.cfg_att_allow_85>0) html+='85%→'+parseFloat(d.cfg_att_allow_85).toFixed(0)+' ';
        if(d.cfg_att_allow_90>0) html+='90%→'+parseFloat(d.cfg_att_allow_90).toFixed(0);
        html+='</span>';
    }
    html+='</div></div>';
    html+='<div class="att-card"><div class="att-card-label">Meal Reimb.</div><div class="att-card-value" style="font-size:13px;color:#c2410c;">'+(d.meal>0?'LKR '+parseFloat(d.meal).toFixed(2):'—')+'</div><div class="att-card-sub">att% × cfg (Rs.'+(d.cfg_meal>0?parseFloat(d.cfg_meal).toFixed(2):'—')+')</div></div>';
    html+='</div>';

    // ── INCENTIVE CARDS ────────────────────────────────────────────────────────
    html+='<div class="incent-band">';
    html+='<div class="incent-band-title"><i class="fa-solid fa-coins"></i> ST Incentive Breakdown — Achievement &amp; Earned Amounts</div>';
    html+='<div class="incent-cards-row">';
    const mkIc=(cls,label,ach,amt,tier,met,thr)=>`<div class="ic-card ${cls}">
        <div class="ic-label">${label}</div>
        <div class="ic-ach">${ach>0?ach.toFixed(1)+'%':'—'} ${tier?`<span class="tier-badge ${ach>=(thr+10)?'tier-t2':'tier-t1'}">${tier}</span>`:''}</div>
        <div class="ic-amt">${amt>0?'LKR '+fmt(amt):'—'}</div>
        <div class="ic-tier">${thr>0?'Threshold: ≥'+thr+'%':''} ${ach>0?(met?'<span style="color:#166534;font-size:10px;font-weight:700;">✓ Met</span>':'<span style="color:#991b1b;font-size:10px;font-weight:700;">✗ Not Met</span>'):''}</div>
    </div>`;
    html+=mkIc('ic-damage','Stores Damage',d.stores_damage_ach,d.stores_damage_amt,'',null,0);
    html+=mkIc('ic-rsqm','RSQM',d.rsqm_ach,d.rsqm_amt,d.rsqm_tier_label,d.rsqm_amt>0,60);
    html+=mkIc('ic-punct','Punctuality',d.punctuality_ach,d.punctuality_amt,d.punct_tier_label,d.punctuality_amt>0,75);
    html+=mkIc('ic-lu','Loading/Unloading',d.loading_unloading_ach,d.loading_unloading_amt,d.loading_unloading_ach>=90?'≥90%':'',d.loading_unloading_ach>=90&&d.loading_unloading_amt>0,90);
    html+=mkIc('ic-ccfot','CCFOT',d.ccfot_ach,d.ccfot_amt,d.ccfot_ach>=90?'≥90%':'',d.ccfot_ach>=90&&d.ccfot_amt>0,90);
    html+='</div>';
    const totalIncent=d.stores_damage_amt+d.rsqm_amt+d.punctuality_amt+d.loading_unloading_amt+d.ccfot_amt;
    html+='<div style="margin-top:10px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;">';
    html+='<span style="font-size:12px;font-weight:700;color:#92400e;font-family:var(--display);text-transform:uppercase;letter-spacing:.3px;"><i class="fa-solid fa-sigma"></i> Total Incentives + Att. Allowance</span>';
    html+='<span style="font-family:var(--mono);font-size:16px;font-weight:800;color:#92400e;">LKR '+fmt(totalIncent+d.att_allow)+'</span>';
    html+='</div></div>';

    // ── LOAN BAND ──────────────────────────────────────────────────────────────
    html+='<div class="loan-band">';
    html+='<div class="loan-band-title"><i class="fa-solid fa-hand-holding-dollar"></i> Active Loan Deductions This Period</div>';
    const loans=d.emp_loans||[];
    if(!loans.length){
        html+='<div class="loan-no-loans"><i class="fa-solid fa-check-circle" style="color:#22c55e;"></i> No active loans for this employee.</div>';
    } else {
        html+='<div class="loan-cards-row">';
        loans.forEach(ln=>{
            const pct=ln.total_repayment>0?Math.min(100,ln.paid_so_far/ln.total_repayment*100):0;
            html+=`<div class="loan-detail-card">
                <div class="loan-detail-card-header">
                    <div class="loan-detail-card-type">🏢 ${escHtml(ln.loan_type)} <span style="font-size:9px;color:#9ca3af;">#${ln.loan_id}</span></div>
                    <span style="background:#dcfce7;color:#166534;padding:2px 7px;border-radius:3px;font-size:9px;font-weight:700;font-family:var(--display);">Approved</span>
                </div>
                <div class="loan-detail-row"><span class="loan-detail-label">Original Amount</span><span class="loan-detail-val">LKR ${fmt(ln.loan_amount)}</span></div>
                <div class="loan-detail-row"><span class="loan-detail-label">Total Repayment</span><span class="loan-detail-val">LKR ${fmt(ln.total_repayment)}</span></div>
                <div class="loan-detail-row"><span class="loan-detail-label">Paid So Far</span><span class="loan-detail-val" style="color:#166534;">LKR ${fmt(ln.paid_so_far)}</span></div>
                <div class="loan-detail-row"><span class="loan-detail-label">Remaining Balance</span><span class="loan-detail-val" style="color:#7c3aed;">LKR ${fmt(ln.balance)}</span></div>
                <div class="loan-detail-row" style="background:#fdf6ff;border-radius:4px;padding:5px 6px;">
                    <span class="loan-detail-label" style="font-weight:700;color:#7c3aed;"><i class="fa-solid fa-arrow-down"></i> This Period Deduction</span>
                    <span class="loan-detail-val" style="color:#991b1b;font-size:13px;">LKR ${fmt(ln.this_deduct)}</span>
                </div>
                <div class="loan-progress-bar"><div class="loan-progress-fill" style="width:${pct.toFixed(1)}%"></div></div>
                <div style="font-size:9px;color:#9ca3af;margin-top:3px;text-align:right;">${pct.toFixed(1)}% repaid · ${ln.no_of_instalments} instalments</div>
            </div>`;
        });
        html+='</div>';
        const tld=loans.reduce((s,l)=>s+l.this_deduct,0);
        html+='<div style="margin-top:10px;background:#f3e8ff;border:1px solid #c4b5fd;border-radius:8px;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;">';
        html+='<span style="font-size:12px;font-weight:700;color:#6d28d9;font-family:var(--display);text-transform:uppercase;letter-spacing:.3px;"><i class="fa-solid fa-sigma"></i> Total Loan Deduction This Period</span>';
        html+='<span style="font-family:var(--mono);font-size:16px;font-weight:800;color:#7c3aed;">LKR '+fmt(tld)+'</span>';
        html+='</div>';
    }
    html+='</div>';

    // ── EDIT OVERRIDES ─────────────────────────────────────────────────────────
    if(HAS_SAVED_SHEET&&!IS_CONFIRMED){
        html+='<div class="modal-edit-section">';
        html+='<div class="modal-edit-title"><i class="fa-solid fa-pen-to-square"></i> Adjustments &amp; Overrides</div>';
        html+='<div class="modal-edit-grid">';
        html+='<div><label class="modal-edit-label"><i class="fa-solid fa-circle-plus"></i> Arrears Salary (Earning)</label><input type="number" step="0.01" min="0" class="modal-edit-input" id="m_arrears" value="'+parseFloat(d.arrears||0).toFixed(2)+'" placeholder="0.00"></div>';
        html+='<div><label class="modal-edit-label"><i class="fa-solid fa-circle-minus"></i> Excess Payment (Deduction)</label><input type="number" step="0.01" min="0" class="modal-edit-input" id="m_excess" value="'+parseFloat(d.excess_p||0).toFixed(2)+'" placeholder="0.00"></div>';
        html+='</div></div>';
    } else if(IS_CONFIRMED){
        html+='<div style="padding:10px 26px;background:#f0fdf4;border-top:1px solid #86efac;font-size:12px;color:#14532d;display:flex;align-items:center;gap:8px;"><i class="fa-solid fa-lock"></i> <strong>Sheet is Confirmed.</strong> No further edits allowed.</div>';
    }

    // ── EARNINGS / DEDUCTIONS SECTIONS ─────────────────────────────────────────
    html+='<div class="modal-sections">';

    // EARNINGS
    html+='<div class="modal-section"><div class="ms-header ms-earn"><i class="fa-solid fa-arrow-trend-up"></i> Earnings Breakdown</div><div class="ms-body">';
    const earns=[
        ['Basic Salary',            d.basic,                 ''],
        ['Att. Allowance ('+( d.att_allow_tier||'—')+')', d.att_allow, 'ms-teal-val'],
        ['Stores Damage Incentive', d.stores_damage_amt,     ''],
        ['RSQM Incentive',          d.rsqm_amt,              ''],
        ['Punctuality Incentive',   d.punctuality_amt,       ''],
        ['Loading / Unloading',     d.loading_unloading_amt, ''],
        ['CCFOT Incentive',         d.ccfot_amt,             ''],
        ['Meal Reimbursement',      d.meal,                  ''],
        ['Arrears Salary',          d.arrears,               ''],
    ];
    earns.forEach(([label,val,extra])=>{
        const cls=val<=0?'ms-zero':(extra||'ms-earn-val');
        html+='<div class="ms-row '+cls+'"><span class="ms-row-label">'+label+'</span><span class="ms-row-value">'+lkr(val)+'</span></div>';
    });
    html+='</div>';
    // Additional earnings
    html+='<div style="border-top:2px dashed #86efac;background:#f0fdf4;">';
    html+='<div style="padding:7px 14px 0;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#166534;display:flex;align-items:center;gap:5px;font-family:var(--display);"><i class="fa-solid fa-plus-circle"></i> Additional Earnings</div>';
    html+='<div id="addEarnRows" class="add-rows-section"></div>';
    html+='<div class="add-rows-footer">';
    if(HAS_SAVED_SHEET&&!IS_CONFIRMED) html+='<button class="add-row-btn" onclick="addEarnRow(\'\',0)"><i class="fa-solid fa-plus"></i> Add Row</button>';
    else html+='<span style="font-size:10px;color:#9ca3af;">'+(IS_CONFIRMED?'Sheet locked':'Save sheet to add rows')+'</span>';
    html+='<span class="add-row-total" id="addEarnTotal" style="color:#166534;">Total: LKR 0.00</span>';
    html+='</div></div>';
    html+='<div class="ms-total ms-total-earn"><span>Total Earnings</span><span>'+lkrPlain(d.total_earnings)+'</span></div>';
    html+='</div>';

    // DEDUCTIONS
    html+='<div class="modal-section"><div class="ms-header ms-ded"><i class="fa-solid fa-circle-minus"></i> Deductions Breakdown</div><div class="ms-body">';
    const deds=[
        ['EPF Employee (8%)', d.epf_emp,       false],
        ['Welfare',           d.welfare_amt,   false],
        ['Welfare Loan Ded.', d.w_loan,        false],
        ['Welfare Society',   d.w_soc,         false],
        ['Donations',         d.donations,     false],
        ['Salary Advance',    d.sal_adv,       true ],
        ['Credit Recovery',   d.credit_r,      false],
        ['No Pay Deduction',  d.no_pay_amount, false],
        ['Retention',         d.retention,     false],
        ['Excess Payment',    d.excess_p,      false],
    ];
    deds.forEach(([label,val,showSrc])=>{
        const cls=val<=0?'ms-zero':'ms-ded-val';
        const badge=showSrc?advBadge(d.advance_from_entry):'';
        html+='<div class="ms-row '+cls+'"><span class="ms-row-label">'+label+badge+'</span><span class="ms-row-value">'+lkr(val)+'</span></div>';
    });
    if(d.loan_ded>0) html+='<div class="ms-row ms-loan-val" style="background:#faf5ff;"><span class="ms-row-label"><i class="fa-solid fa-hand-holding-dollar" style="color:#7c3aed;margin-right:4px;"></i>Loan Deduction <span class="src-badge src-loan">Active</span></span><span class="ms-row-value" style="color:#7c3aed;">LKR '+fmt(d.loan_ded)+'</span></div>';
    html+='</div>';
    html+='<div style="border-top:2px dashed #fca5a5;background:#fef2f2;">';
    html+='<div style="padding:7px 14px 0;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#991b1b;display:flex;align-items:center;gap:5px;font-family:var(--display);"><i class="fa-solid fa-plus-circle"></i> Additional Deductions</div>';
    html+='<div id="addDedRows" class="add-rows-section"></div>';
    html+='<div class="add-rows-footer" style="background:#fef2f2;">';
    if(HAS_SAVED_SHEET&&!IS_CONFIRMED) html+='<button class="add-row-btn" style="border-color:#fca5a5;color:#991b1b;" onclick="addDedRow(\'\',0)"><i class="fa-solid fa-plus"></i> Add Row</button>';
    else html+='<span style="font-size:10px;color:#9ca3af;">'+(IS_CONFIRMED?'Sheet locked':'Save sheet to add rows')+'</span>';
    html+='<span class="add-row-total" id="addDedTotal" style="color:#991b1b;">Total: LKR 0.00</span>';
    html+='</div></div>';
    html+='<div class="ms-total ms-total-ded"><span>Total Deductions</span><span>'+lkrPlain(d.total_deductions)+'</span></div>';
    html+='</div>';

    // NET SUMMARY
    html+='<div class="modal-section"><div class="ms-header ms-att"><i class="fa-solid fa-wallet"></i> Net Salary Summary</div><div class="ms-body">';
    html+='<div style="text-align:center;padding:20px 14px 14px;">';
    html+='<div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#6b7280;margin-bottom:5px;font-family:var(--display);">NET TAKE-HOME SALARY</div>';
    html+='<div style="font-family:var(--mono);font-size:27px;font-weight:800;color:#1d4ed8;">'+lkrPlain(d.net_salary)+'</div>';
    html+='</div>';
    html+='<div class="ms-row ms-earn-val"><span class="ms-row-label">Gross Earnings</span><span class="ms-row-value">'+lkr(d.total_earnings)+'</span></div>';
    html+='<div class="ms-row ms-ded-val"><span class="ms-row-label">Total Deductions</span><span class="ms-row-value" style="color:#991b1b;">− '+lkrPlain(d.total_deductions)+'</span></div>';
    html+='<div class="ms-row" style="border-top:2px solid var(--border2);font-weight:700;"><span class="ms-row-label">Net Salary</span><span class="ms-row-value" style="color:#1d4ed8;font-size:13px;">'+lkrPlain(d.net_salary)+'</span></div>';
    html+='</div><div class="ms-total ms-total-att"><span>Net Take-Home</span><span>'+lkrPlain(d.net_salary)+'</span></div></div>';

    // EMPLOYER COST
    html+='<div class="modal-section"><div class="ms-header ms-ben"><i class="fa-solid fa-star"></i> Total Benefit &amp; Employer Cost</div><div class="ms-body">';
    html+='<div class="ms-row" style="background:#fffbeb;"><span class="ms-row-label" style="font-weight:700;font-size:10px;color:#78350f;text-transform:uppercase;font-family:var(--display);"><i class="fa-solid fa-calculator"></i> Cost of BP</span><span class="ms-row-value" style="color:#78350f;">'+lkrPlain(d.cost_bp)+'</span></div>';
    [['  Basic',d.basic],['  EPF Employer (12%)',d.epf_er],['  ETF Employer (3%)',d.etf_er],['  Meal Reimb.',d.meal],['  Bonus (÷12)',d.bonus]].forEach(([l,v])=>{
        html+='<div class="ms-row '+(v<=0?'ms-zero':'ms-ben-val')+'" style="padding-left:22px;"><span class="ms-row-label" style="font-size:11px;">'+l+'</span><span class="ms-row-value" style="font-size:11px;">'+lkr(v)+'</span></div>';
    });
    html+='<div class="ms-row" style="background:#fffbeb;border-top:1px dashed #fde68a;margin-top:4px;"><span class="ms-row-label" style="font-weight:700;font-size:10px;color:#78350f;text-transform:uppercase;font-family:var(--display);"><i class="fa-solid fa-coins"></i> Provisions (B+G)</span><span class="ms-row-value" style="color:#78350f;">'+lkrPlain(d.bonus+d.gratuity)+'</span></div>';
    [['  Bonus (÷12)',d.bonus],['  Gratuity (÷24)',d.gratuity]].forEach(([l,v])=>{
        html+='<div class="ms-row '+(v<=0?'ms-zero':'ms-ben-val')+'" style="padding-left:22px;"><span class="ms-row-label" style="font-size:11px;">'+l+'</span><span class="ms-row-value" style="font-size:11px;">'+lkr(v)+'</span></div>';
    });
    [['Insurance',d.insurance],['EPF Employer (12%)',d.epf_er],['ETF Employer (3%)',d.etf_er]].forEach(([l,v])=>{
        html+='<div class="ms-row '+(v<=0?'ms-zero':'ms-ben-val')+'"><span class="ms-row-label">'+l+'</span><span class="ms-row-value">'+lkr(v)+'</span></div>';
    });
    html+='</div><div class="ms-total ms-total-ben"><span>Total Benefit</span><span>'+lkrPlain(d.total_benefit)+'</span></div></div>';

    html+='</div>'; // close modal-sections

    document.getElementById('modalBody').innerHTML=html;
    renderAddRows(); updateAddTotals();
    const su=document.getElementById('modalUpdateStatus'); if(su) su.innerHTML='';
    document.getElementById('detailModal').classList.add('show');
}

function closeModal(){document.getElementById('detailModal').classList.remove('show');_currentEid=null;}

// ── FILTER / SEARCH ────────────────────────────────────────────────────────────
function liveFilter(q){
    q=q.trim().toLowerCase();
    document.querySelectorAll('.emp-row').forEach(r=>{
        r.style.display=(q!==''&&!((r.getAttribute('data-search')||'').includes(q)))?'none':'';
    });
}
function toggleFilter(){
    const b=document.getElementById('filterBody'),c=document.getElementById('filterChevron');
    const o=b.classList.toggle('open'); c.style.transform=o?'rotate(180deg)':'';
}
function loadBranches(cid){
    const sel=document.getElementById('filter_branch'); if(!sel) return;
    sel.innerHTML='<option value="">All Branches</option>';
    if(!cid) return;
    fetch('get_branches.php?company_id='+cid).then(r=>r.json()).then(data=>data.forEach(b=>{
        sel.appendChild(new Option(b.branch_code+' - '+b.branch_name,b.id));
    }));
}
<?php if($filter_company):?>loadBranches(<?php echo $filter_company;?>);setTimeout(()=>{const s=document.getElementById('filter_branch');if(s)s.value='<?php echo $filter_branch;?>';},600);<?php endif;?>

// ── CSV EXPORT ────────────────────────────────────────────────────────────────
function exportCSV(){
    const rows=[['Employee Code','EPF Number','Date of Join','Name','Status','Designation',
        'Norm Days','Att Days','Att Rate%','Att Allow Tier','Att Allow Amt',
        'No Pay Days','No Pay Amount','Per Day','Basic',
        'Damage Ach%','Damage Amt','RSQM Ach%','RSQM Amt','RSQM Tier',
        'Punct Ach%','Punct Amt','Punct Tier','L/Unload Ach%','L/Unload Amt',
        'CCFOT Ach%','CCFOT Amt','Meal Reimb','Arrears','Total Earnings',
        'Sal Advance','Adv Source','Loan Ded','Active Loans','Total Deductions',
        'Net Salary','EPF Emp','EPF Er','ETF Er','Bonus','Gratuity','Insurance',
        'Total Benefit','Cost BP']];
    <?php foreach($employees as $emp):
        $eid=(int)$emp['id'];$r=$rows[$eid];$att=$r['att'];?>
    rows.push([
        <?php echo json_encode($emp['employee_id']);?>,<?php echo json_encode($emp['epf_number']??'');?>,
        <?php echo json_encode($emp['date_of_join']?date('d M Y',strtotime($emp['date_of_join'])):'');?>,
        <?php echo json_encode($emp['employee_full_name']);?>,<?php echo json_encode($emp['status']);?>,
        <?php echo json_encode($emp['designation_name']??'');?>,
        <?php echo $att['norm'];?>,<?php echo $att['att'];?>,<?php echo $att['rate'];?>,
        <?php echo json_encode($r['att_allow_tier']);?>,<?php echo $r['att_allow'];?>,
        <?php echo $r['no_pay_days'];?>,<?php echo $r['no_pay_amount'];?>,<?php echo $r['per_day'];?>,
        <?php echo $r['basic'];?>,
        <?php echo $r['stores_damage_ach'];?>,<?php echo $r['stores_damage_amt'];?>,
        <?php echo $r['rsqm_ach'];?>,<?php echo $r['rsqm_amt'];?>,<?php echo json_encode($r['rsqm_tier_label']);?>,
        <?php echo $r['punctuality_ach'];?>,<?php echo $r['punctuality_amt'];?>,<?php echo json_encode($r['punct_tier_label']);?>,
        <?php echo $r['loading_unloading_ach'];?>,<?php echo $r['loading_unloading_amt'];?>,
        <?php echo $r['ccfot_ach'];?>,<?php echo $r['ccfot_amt'];?>,
        <?php echo $r['meal'];?>,<?php echo $r['arrears'];?>,<?php echo $r['total_earnings'];?>,
        <?php echo $r['sal_adv'];?>,<?php echo json_encode($r['advance_from_entry']?'Period Advances':'Profile');?>,
        <?php echo $r['loan_ded'];?>,<?php echo count($r['emp_loans']);?>,<?php echo $r['total_deductions'];?>,
        <?php echo $r['net_salary'];?>,<?php echo $r['epf_emp'];?>,<?php echo $r['epf_er'];?>,
        <?php echo $r['etf_er'];?>,<?php echo $r['bonus'];?>,<?php echo $r['gratuity'];?>,
        <?php echo $r['insurance'];?>,<?php echo $r['total_benefit'];?>,<?php echo $r['cost_bp'];?>
    ]);
    <?php endforeach;?>
    const csv=rows.map(r=>r.map(c=>'"'+String(c).replace(/"/g,'""')+'"').join(',')).join('\n');
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));
    a.download='st_salary_<?php echo $active_period?$active_period['year'].'_'.$active_period['month']:'export';?>.csv';
    a.click();
}
</script>

<?php include 'footer.php'; ?>