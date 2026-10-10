<?php
include 'config.php';

// ── Ensure columns exist ──────────────────────────────────────────────────────
$alter_cols = [
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS attendance_allowance     DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS reimbursement_meal       DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS reimbursement_traveling  DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS reimbursement_fuel       DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS reimbursement_mobile     DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS arrears_salary           DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS welfare_loan_deduction   DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS welfare_society          DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS donations                DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS salary_advance           DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS loan_deduction           DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS credit_recovery          DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS no_pay                   DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS retention                DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS excess_payment           DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS exit_date                DATE NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS exit_reason              TEXT NULL",
];
foreach ($alter_cols as $s) @mysqli_query($conn, $s);

// ── Create salary_sheet_entries table ────────────────────────────────────────
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS salary_sheet_entries (
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
    basic                   DECIMAL(12,2) DEFAULT 0,
    disc                    DECIMAL(12,2) DEFAULT 0,
    ssv                     DECIMAL(12,2) DEFAULT 0,
    ps_amt                  DECIMAL(12,2) DEFAULT 0,
    eco_amt                 DECIMAL(12,2) DEFAULT 0,
    bp_amt                  DECIMAL(12,2) DEFAULT 0,
    assort_amt              DECIMAL(12,2) DEFAULT 0,
    other_incentive         DECIMAL(12,2) DEFAULT 0,
    meal                    DECIMAL(12,2) DEFAULT 0,
    travel                  DECIMAL(12,2) DEFAULT 0,
    fuel                    DECIMAL(12,2) DEFAULT 0,
    fuel_from_entry         TINYINT(1) DEFAULT 0,
    mobile                  DECIMAL(12,2) DEFAULT 0,
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

// Upgrade existing table
@mysqli_query($conn, "ALTER TABLE salary_sheet_entries ADD COLUMN IF NOT EXISTS additional_earnings   TEXT DEFAULT NULL");
@mysqli_query($conn, "ALTER TABLE salary_sheet_entries ADD COLUMN IF NOT EXISTS additional_deductions TEXT DEFAULT NULL");
@mysqli_query($conn, "ALTER TABLE salary_sheet_entries ADD COLUMN IF NOT EXISTS sheet_status VARCHAR(20) DEFAULT 'Pending'");
@mysqli_query($conn, "ALTER TABLE salary_sheet_entries ADD COLUMN IF NOT EXISTS other_incentive DECIMAL(12,2) DEFAULT 0");

// ── Create loan_requests table (if not exists) ───────────────────────────────
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS loan_requests (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    employee_id         INT NOT NULL,
    request_date        DATE,
    payroll_period_id   INT,
    loan_type           VARCHAR(100),
    loan_amount         DECIMAL(12,2) DEFAULT 0,
    no_of_instalments   INT DEFAULT 1,
    monthly_instalment  DECIMAL(12,2) DEFAULT 0,
    interest_rate       DECIMAL(8,4) DEFAULT 0,
    interest_frequency  VARCHAR(20) DEFAULT 'Monthly',
    total_interest      DECIMAL(12,2) DEFAULT 0,
    total_repayment     DECIMAL(12,2) DEFAULT 0,
    reason              TEXT,
    status              VARCHAR(30) DEFAULT 'Pending',
    created_by          VARCHAR(100),
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_employee (employee_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ── Create loan_payments table ────────────────────────────────────────────────
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS loan_payments (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    loan_request_id     INT NOT NULL,
    employee_id         INT NOT NULL,
    payroll_period_id   INT NOT NULL,
    payment_date        DATE,
    instalment_number   INT DEFAULT 1,
    principal_paid      DECIMAL(12,2) DEFAULT 0,
    interest_paid       DECIMAL(12,2) DEFAULT 0,
    total_paid          DECIMAL(12,2) DEFAULT 0,
    balance_before      DECIMAL(12,2) DEFAULT 0,
    balance_after       DECIMAL(12,2) DEFAULT 0,
    notes               TEXT,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_loan (loan_request_id),
    INDEX idx_employee (employee_id),
    INDEX idx_period (payroll_period_id),
    UNIQUE KEY uq_loan_period (loan_request_id, payroll_period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ── Handle AJAX actions ───────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // ── DELETE saved sheet ────────────────────────────────────────────────────
    if ($_GET['ajax'] === 'delete_sheet' && isset($_GET['period_id'])) {
        $pid = intval($_GET['period_id']);
        // Only allow delete if not Confirmed
        $chk = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT sheet_status FROM salary_sheet_entries WHERE payroll_period_id=$pid AND category_code='SR' LIMIT 1"));
        if ($chk && $chk['sheet_status'] === 'Confirmed') {
            echo json_encode(['ok' => false, 'msg' => 'Cannot delete a Confirmed salary sheet.']);
            exit;
        }
        // IMPORTANT: category_code='SR' added — without it this deleted EVERY
        // category's rows (CC/ACC/MR too) for this payroll period, since
        // salary_sheet_entries is shared across all four categories.
        mysqli_query($conn, "DELETE FROM salary_sheet_entries WHERE payroll_period_id = $pid AND category_code='SR'");
        echo json_encode(['ok' => true, 'deleted' => mysqli_affected_rows($conn)]);
        exit;
    }

    // ── CONFIRM SALARY SHEET ─────────────────────────────────────────────────
    if ($_GET['ajax'] === 'confirm_sheet' && isset($_GET['period_id'])) {
        $pid = intval($_GET['period_id']);

        // Check already confirmed — scoped to SR only, so another category
        // being Confirmed for this period doesn't wrongly block (or a
        // Pending SR row doesn't wrongly get overlooked because some other
        // category's row was checked instead).
        $chk = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS cnt FROM salary_sheet_entries WHERE payroll_period_id=$pid AND category_code='SR' AND sheet_status='Confirmed'"));
        if (intval($chk['cnt']) > 0) {
            echo json_encode(['ok' => false, 'msg' => 'Sheet is already confirmed.']);
            exit;
        }

        // Get all employees in this sheet — scoped to SR only, so loan
        // payments below are only ever created for actual SR employees.
        $emp_res = mysqli_query($conn,
            "SELECT employee_id FROM salary_sheet_entries WHERE payroll_period_id=$pid AND category_code='SR'");
        $emp_ids = [];
        while ($er = mysqli_fetch_assoc($emp_res)) $emp_ids[] = intval($er['employee_id']);

        if (empty($emp_ids)) {
            echo json_encode(['ok' => false, 'msg' => 'No employees in sheet.']);
            exit;
        }

        $payment_date = date('Y-m-d');
        $payments_created = 0;
        $errors = [];

        foreach ($emp_ids as $eid) {
            // Get active/approved loans for this employee not fully paid
            $loan_res = mysqli_query($conn,
                "SELECT lr.*,
                    COALESCE((SELECT SUM(lp.total_paid) FROM loan_payments lp WHERE lp.loan_request_id=lr.id), 0) AS paid_so_far
                 FROM loan_requests lr
                 WHERE lr.employee_id=$eid AND lr.status='Approved'
                 ORDER BY lr.id ASC");

            while ($loan = mysqli_fetch_assoc($loan_res)) {
                $loan_id         = intval($loan['id']);
                $loan_amount     = floatval($loan['loan_amount']);
                $monthly_inst    = floatval($loan['monthly_instalment']);
                $total_repayment = floatval($loan['total_repayment']);
                $paid_so_far     = floatval($loan['paid_so_far']);
                $balance_before  = round($total_repayment - $paid_so_far, 2);

                if ($balance_before <= 0) continue; // Fully paid

                // Check not already paid for this period
                $already = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT id FROM loan_payments WHERE loan_request_id=$loan_id AND payroll_period_id=$pid"));
                if ($already) continue;

                // Payment = min(monthly_instalment, remaining balance)
                $this_payment   = min($monthly_inst, $balance_before);
                $balance_after  = round($balance_before - $this_payment, 2);

                // Calculate instalment number
                $inst_num = intval(mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT COUNT(*) AS cnt FROM loan_payments WHERE loan_request_id=$loan_id"))['cnt']) + 1;

                // Simple interest split (proportional)
                $interest_total = floatval($loan['total_interest']);
                $no_inst        = max(1, intval($loan['no_of_instalments']));
                $interest_per   = $no_inst > 0 ? round($interest_total / $no_inst, 2) : 0;
                $principal_paid = round($this_payment - $interest_per, 2);
                if ($principal_paid < 0) { $interest_per = $this_payment; $principal_paid = 0; }

                $ins = mysqli_query($conn, "INSERT INTO loan_payments
                    (loan_request_id, employee_id, payroll_period_id, payment_date,
                     instalment_number, principal_paid, interest_paid, total_paid,
                     balance_before, balance_after)
                    VALUES ($loan_id, $eid, $pid, '$payment_date',
                    $inst_num, $principal_paid, $interest_per, $this_payment,
                    $balance_before, $balance_after)");

                if ($ins) {
                    $payments_created++;
                    // Mark loan as completed if balance is 0
                    if ($balance_after <= 0) {
                        mysqli_query($conn, "UPDATE loan_requests SET status='Completed' WHERE id=$loan_id");
                    }
                } else {
                    $errors[] = "Loan #$loan_id for emp #$eid: " . mysqli_error($conn);
                }
            }
        }

        // Update sheet_status to Confirmed — category_code='SR' added; without
        // it this marked EVERY category's rows Confirmed for this period,
        // including CC/ACC/MR sheets that may still be Pending review.
        mysqli_query($conn, "UPDATE salary_sheet_entries SET sheet_status='Confirmed' WHERE payroll_period_id=$pid AND category_code='SR'");

        echo json_encode([
            'ok'               => true,
            'payments_created' => $payments_created,
            'errors'           => $errors,
        ]);
        exit;
    }

    // ── SAVE / UPDATE overrides from modal ───────────────────────────────────
    if ($_GET['ajax'] === 'update_overrides' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body    = json_decode(file_get_contents('php://input'), true);
        $pid     = intval($body['period_id'] ?? 0);
        $eid     = intval($body['employee_id'] ?? 0);
        $excess  = isset($body['excess'])  ? floatval($body['excess'])  : null;
        $arrears = isset($body['arrears']) ? floatval($body['arrears']) : null;
        $add_earn = isset($body['additional_earnings'])   ? $body['additional_earnings']   : null;
        $add_ded  = isset($body['additional_deductions']) ? $body['additional_deductions'] : null;

        if ($pid && $eid) {
            $row = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT * FROM salary_sheet_entries WHERE payroll_period_id=$pid AND employee_id=$eid"));
            if ($row) {
                if ($row['sheet_status'] === 'Confirmed') {
                    echo json_encode(['ok' => false, 'msg' => 'Sheet is confirmed. No further edits allowed.']);
                    exit;
                }

                $new_excess  = $excess  !== null ? $excess  : floatval($row['excess_p']);
                $new_arrears = $arrears !== null ? $arrears : floatval($row['arrears']);

                $add_earn_total = 0;
                $add_ded_total  = 0;
                if (is_array($add_earn)) foreach ($add_earn as $ae) $add_earn_total += floatval($ae['amount'] ?? 0);
                if (is_array($add_ded))  foreach ($add_ded as $ad)  $add_ded_total  += floatval($ad['amount'] ?? 0);

                $new_total_earnings = floatval($row['basic']) + floatval($row['disc']) + floatval($row['ssv'])
                    + floatval($row['ps_amt']) + floatval($row['eco_amt']) + floatval($row['bp_amt'])
                    + floatval($row['assort_amt']) + floatval($row['other_incentive'])
                    + floatval($row['meal']) + floatval($row['travel'])
                    + floatval($row['fuel']) + floatval($row['mobile']) + $new_arrears
                    + floatval($row['att_allow']) + $add_earn_total;

                $new_total_ded = floatval($row['epf_emp']) + floatval($row['welfare_amt'])
                    + floatval($row['w_loan']) + floatval($row['w_soc']) + floatval($row['donations'])
                    + floatval($row['sal_adv']) + floatval($row['loan_ded']) + floatval($row['credit_r'])
                    + floatval($row['no_pay_amount']) + floatval($row['retention'])
                    + $new_excess + $add_ded_total;

                $new_net = $new_total_earnings - $new_total_ded;

                $new_cost_bp = floatval($row['epf_er']) + floatval($row['etf_er'])
                    + floatval($row['meal']) + floatval($row['travel'])
                    + floatval($row['mobile']) + floatval($row['fuel'])
                    + floatval($row['bonus']) + floatval($row['basic']) + floatval($row['disc']);

                $new_total_benefit = floatval($row['basic']) + floatval($row['disc']) + floatval($row['ssv'])
                    + floatval($row['ps_amt']) + floatval($row['eco_amt']) + floatval($row['bp_amt'])
                    + floatval($row['assort_amt']) + floatval($row['other_incentive'])
                    + floatval($row['meal']) + floatval($row['travel'])
                    + floatval($row['fuel']) + floatval($row['mobile']) + floatval($row['insurance'])
                    + floatval($row['epf_er']) + floatval($row['etf_er'])
                    + floatval($row['bonus']) + floatval($row['gratuity']);

                $esc_add_earn = $add_earn !== null ? "'" . mysqli_real_escape_string($conn, json_encode($add_earn)) . "'" : "additional_earnings";
                $esc_add_ded  = $add_ded  !== null ? "'" . mysqli_real_escape_string($conn, json_encode($add_ded))  . "'" : "additional_deductions";
                $upd_excess   = $excess  !== null ? $new_excess  : floatval($row['excess_p']);
                $upd_arrears  = $arrears !== null ? $new_arrears : floatval($row['arrears']);

                mysqli_query($conn, "UPDATE salary_sheet_entries SET
                    arrears              = $upd_arrears,
                    excess_p             = $upd_excess,
                    total_earnings       = $new_total_earnings,
                    total_deductions     = $new_total_ded,
                    net_salary           = $new_net,
                    cost_bp              = $new_cost_bp,
                    total_benefit        = $new_total_benefit,
                    override_excess      = " . ($excess  !== null ? $upd_excess  : 'override_excess') . ",
                    override_arrears     = " . ($arrears !== null ? $upd_arrears : 'override_arrears') . ",
                    additional_earnings  = $esc_add_earn,
                    additional_deductions= $esc_add_ded
                    WHERE payroll_period_id=$pid AND employee_id=$eid");

                echo json_encode([
                    'ok'               => true,
                    'total_earnings'   => $new_total_earnings,
                    'total_deductions' => $new_total_ded,
                    'net_salary'       => $new_net,
                    'cost_bp'          => $new_cost_bp,
                    'total_benefit'    => $new_total_benefit,
                    'add_earn_total'   => $add_earn_total,
                    'add_ded_total'    => $add_ded_total,
                ]);
                exit;
            }
        }
        echo json_encode(['ok' => false, 'msg' => 'Row not found']);
        exit;
    }

    // ── CHECK saved sheet ─────────────────────────────────────────────────────
    if ($_GET['ajax'] === 'check_sheet' && isset($_GET['period_id'])) {
        $pid = intval($_GET['period_id']);
        // category_code='SR' added — without it this reported "exists=true"
        // for SR whenever CC/ACC/MR had saved data for this period, even
        // with zero actual SR rows.
        $cnt = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS cnt FROM salary_sheet_entries WHERE payroll_period_id=$pid AND category_code='SR'"));
        echo json_encode(['exists' => intval($cnt['cnt']) > 0, 'count' => intval($cnt['cnt'])]);
        exit;
    }

    // ── LOAD saved sheet ──────────────────────────────────────────────────────
    if ($_GET['ajax'] === 'load_sheet' && isset($_GET['period_id'])) {
        $pid  = intval($_GET['period_id']);
        // category_code='SR' added — without it this returned EVERY row for
        // the period across all four categories, mixing CC/ACC/MR employees
        // and figures into what should be the SR-only saved-sheet view.
        $res  = mysqli_query($conn, "SELECT * FROM salary_sheet_entries WHERE payroll_period_id=$pid AND category_code='SR' ORDER BY company_code, employee_code");
        $data = [];
        if ($res) while ($r = mysqli_fetch_assoc($res)) $data[] = $r;
        echo json_encode($data);
        exit;
    }

    exit;
}

// ── Handle POST: save salary sheet ───────────────────────────────────────────
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
                'category_code'        => $row['cat_code']     ?? '',
                'designation_name'     => $row['desig']        ?? '',
                'company_code'         => $row['company_code'] ?? '',
                'branch_code'          => $row['branch_code']  ?? '',
                'att_count'            => floatval($row['att_count']   ?? 0),
                'norm_days'            => floatval($row['norm_days']   ?? 0),
                'holidays'             => floatval($row['holidays']    ?? 0),
                'att_rate'             => floatval($row['att_rate']    ?? 0),
                'per_day'              => floatval($row['per_day']     ?? 0),
                'no_pay_days'          => floatval($row['no_pay_days'] ?? 0),
                'no_pay_amount'        => floatval($row['no_pay_amount']?? 0),
                'att_allow'            => floatval($row['att_allow']   ?? 0),
                'basic'                => floatval($row['basic']       ?? 0),
                'disc'                 => floatval($row['disc']        ?? 0),
                'ssv'                  => floatval($row['ssv']         ?? 0),
                'ps_amt'               => floatval($row['ps_amt']      ?? 0),
                'eco_amt'              => floatval($row['eco_amt']      ?? 0),
                'bp_amt'               => floatval($row['bp_amt']      ?? 0),
                'assort_amt'           => floatval($row['assort_amt']  ?? 0),
                'other_incentive'      => floatval($row['other_incentive'] ?? 0),
                'meal'                 => floatval($row['meal']        ?? 0),
                'travel'               => floatval($row['travel']      ?? 0),
                'fuel'                 => floatval($row['fuel']        ?? 0),
                'fuel_from_entry'      => intval($row['fuel_from_entry']  ?? 0),
                'mobile'               => floatval($row['mobile']      ?? 0),
                'arrears'              => floatval($row['arrears']     ?? 0),
                'total_earnings'       => floatval($row['total_earnings'] ?? 0),
                'epf_emp'              => floatval($row['epf_emp']     ?? 0),
                'welfare_amt'          => floatval($row['welfare_amt'] ?? 0),
                'w_loan'               => floatval($row['w_loan']      ?? 0),
                'w_soc'                => floatval($row['w_soc']       ?? 0),
                'donations'            => floatval($row['donations']   ?? 0),
                'sal_adv'              => floatval($row['sal_adv']     ?? 0),
                'advance_from_entry'   => intval($row['advance_from_entry'] ?? 0),
                'loan_ded'             => floatval($row['loan_ded']    ?? 0),
                'credit_r'             => floatval($row['credit_r']    ?? 0),
                'retention'            => floatval($row['retention']   ?? 0),
                'excess_p'             => floatval($row['excess_p']    ?? 0),
                'total_deductions'     => floatval($row['total_deductions'] ?? 0),
                'net_salary'           => floatval($row['net_salary']  ?? 0),
                'epf_er'               => floatval($row['epf_er']      ?? 0),
                'etf_er'               => floatval($row['etf_er']      ?? 0),
                'insurance'            => floatval($row['insurance']   ?? 0),
                'bonus'                => floatval($row['bonus']       ?? 0),
                'gratuity'             => floatval($row['gratuity']    ?? 0),
                'cost_bp'              => floatval($row['cost_bp']     ?? 0),
                'total_benefit'        => floatval($row['total_benefit'] ?? 0),
                'override_excess'      => null,
                'override_arrears'     => null,
                'additional_earnings'  => null,
                'additional_deductions'=> null,
                'sheet_status'         => 'Pending',
            ];
            $ins_keys  = [];
            $ins_vals  = [];
            $upd_parts = [];
            foreach ($map as $k => $v) {
                $ins_keys[]  = "`$k`";
                $esc         = ($v === null) ? 'NULL' : "'" . mysqli_real_escape_string($conn, (string)$v) . "'";
                $ins_vals[]  = $esc;
                if (!in_array($k, ['payroll_period_id','employee_id','sheet_status'])) {
                    $upd_parts[] = "`$k` = $esc";
                }
            }
            // On duplicate: don't reset sheet_status if already Confirmed
            $upd_parts[] = "`updated_at` = NOW()";
            $sql_ins = "INSERT INTO salary_sheet_entries (" . implode(',', $ins_keys) . ")
                        VALUES (" . implode(',', $ins_vals) . ")
                        ON DUPLICATE KEY UPDATE " . implode(',', $upd_parts);
            mysqli_query($conn, $sql_ins);
        }
        header("Location: sr_salary.php?period_id=$pid&sheet=saved");
        exit;
    }
}

// ── Payroll period selection ──────────────────────────────────────────────────
$sel_period_id = isset($_GET['period_id']) ? intval($_GET['period_id']) : 0;
$show_saved    = isset($_GET['sheet']) && $_GET['sheet'] === 'saved';
$all_periods   = [];
$pp_res        = mysqli_query($conn, "SELECT id, year, month, status FROM payroll_periods ORDER BY year DESC, month DESC");
if ($pp_res) while ($p = mysqli_fetch_assoc($pp_res)) $all_periods[] = $p;
if (!$sel_period_id) {
    foreach ($all_periods as $pp) { if (($pp['status'] ?? '') === 'Open') { $sel_period_id = $pp['id']; break; } }
    if (!$sel_period_id && !empty($all_periods)) $sel_period_id = $all_periods[0]['id'];
}
$active_period = null;
foreach ($all_periods as $pp) { if ($pp['id'] == $sel_period_id) { $active_period = $pp; break; } }

// ── Check saved sheet ─────────────────────────────────────────────────────────
$saved_sheet_count  = 0;
$saved_sheet_status = 'Pending';
if ($sel_period_id) {
    // category_code='SR' added — without it, this counted/checked ALL
    // categories' rows for this period (salary_sheet_entries is shared by
    // SR/CC/ACC/MR), so this page could show "sheet saved" / "confirmed"
    // based on CC, ACC, or MR's data even when SR itself had 0 rows saved.
    $sc = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) AS cnt, MAX(sheet_status) AS sstatus
         FROM salary_sheet_entries WHERE payroll_period_id=$sel_period_id AND category_code='SR'"));
    $saved_sheet_count  = intval($sc['cnt']);
    $saved_sheet_status = $sc['sstatus'] ?? 'Pending';
}
$has_saved_sheet  = $saved_sheet_count > 0;
$is_confirmed     = $saved_sheet_status === 'Confirmed';

// ── Attendance calc helpers ───────────────────────────────────────────────────
$emp_att_map = [];
if ($active_period) {
    $pp_year  = (int)$active_period['year'];
    $pp_month = (int)$active_period['month'];
    $from_date        = sprintf('%04d-%02d-01', $pp_year, $pp_month);
    $to_date          = date('Y-m-t', strtotime($from_date));
    $month_total_days = (int)date('t', strtotime($from_date));

    $sh_res  = mysqli_query($conn, "SELECT * FROM special_holidays WHERE active=1 AND date_from<='$to_date' AND date_to>='$from_date'");
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
    $att_res = mysqli_query($conn, "SELECT employee_id, COUNT(*) AS cnt FROM attendance WHERE att_date BETWEEN '$from_date' AND '$to_date' GROUP BY employee_id");
    $att_count_map = [];
    if ($att_res) while ($r = mysqli_fetch_assoc($att_res)) $att_count_map[(int)$r['employee_id']] = (int)$r['cnt'];
    $GLOBALS['_att_sh_date_map'] = $sh_date_map;
    $GLOBALS['_att_count_map']   = $att_count_map;
    $GLOBALS['_att_month_total'] = $month_total_days;
}

function calc_att($emp, $sh_date_map, $att_count_map, $month_total_days) {
    $eid       = (int)$emp['id'];
    $att_count = $att_count_map[$eid] ?? 0;
    $holidays  = 0;
    foreach ($sh_date_map as $date => $shs) {
        foreach ($shs as $sh) {
            $applies = false;
            if ($sh['target_type'] === 'all') { $applies = true; }
            elseif (!empty($sh['target_ids'])) {
                $ids = json_decode($sh['target_ids'], true) ?? [];
                if ($sh['target_type'] === 'employee'       && in_array($eid, $ids)) $applies = true;
                if ($sh['target_type'] === 'staff_category' && in_array((int)($emp['staff_category_id']??0), $ids)) $applies = true;
                if ($sh['target_type'] === 'designation'    && in_array((int)($emp['designation_id']??0),   $ids)) $applies = true;
            }
            if ($applies) { $holidays++; break; }
        }
    }
    $norm_days = max(1, $month_total_days - $holidays);
    $rate      = round(($att_count / $norm_days) * 100, 2);
    return ['att' => $att_count, 'norm' => $norm_days, 'holidays' => $holidays, 'rate' => $rate];
}

// ── EPF/ETF settings ──────────────────────────────────────────────────────────
$epf_settings      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM epf_etf_settings LIMIT 1"));
$epf_employer_rate = $epf_settings ? floatval($epf_settings['epf_employer']) : 12;
$epf_employee_rate = $epf_settings ? floatval($epf_settings['epf_employee']) : 8;
$etf_employer_rate = $epf_settings ? floatval($epf_settings['etf_employer']) : 3;

// ── Fetch SR category IDs ─────────────────────────────────────────────────────
$sr_cat_res = mysqli_query($conn, "SELECT id FROM staff_categories WHERE category_code = 'SR' AND active=1");
$sr_cat_ids = [];
if ($sr_cat_res) while ($r = mysqli_fetch_assoc($sr_cat_res)) $sr_cat_ids[] = (int)$r['id'];
$sr_desig_ids = [];
if (!empty($sr_cat_ids)) {
    $cat_in    = implode(',', $sr_cat_ids);
    $desig_res = mysqli_query($conn, "SELECT id FROM designations WHERE staff_category_id IN ($cat_in) AND active=1");
    if ($desig_res) while ($r = mysqli_fetch_assoc($desig_res)) $sr_desig_ids[] = (int)$r['id'];
}
$where_parts = [];
if (!empty($sr_cat_ids))   $where_parts[] = "e.staff_category_id IN (" . implode(',', $sr_cat_ids) . ")";
if (!empty($sr_desig_ids)) $where_parts[] = "e.designation_id IN ("    . implode(',', $sr_desig_ids) . ")";
if (empty($where_parts))   $where_parts[] = "EXISTS (SELECT 1 FROM staff_categories sc2 WHERE sc2.id = e.staff_category_id AND sc2.category_code = 'SR')";
$where_sql = "(" . implode(' OR ', $where_parts) . ") AND e.active=1";

// ── Filter params ─────────────────────────────────────────────────────────────
$filter_status  = !empty($_GET['filter_status'])  ? mysqli_real_escape_string($conn, $_GET['filter_status'])  : '';
$filter_company = !empty($_GET['filter_company']) ? intval($_GET['filter_company']) : '';
$filter_branch  = !empty($_GET['filter_branch'])  ? intval($_GET['filter_branch'])  : '';
if ($filter_status)  $where_sql .= " AND e.status = '$filter_status'";
if ($filter_company) $where_sql .= " AND e.company_id = $filter_company";
if ($filter_branch)  $where_sql .= " AND e.branch_id  = $filter_branch";

// ── Load SR employees ─────────────────────────────────────────────────────────
$sql = "SELECT e.*,
               c.company_name, c.company_code,
               b.branch_name, b.branch_code,
               d.designation_name, d.designation_code,
               sc.category_name, sc.category_code
        FROM employees e
        LEFT JOIN companies        c  ON e.company_id        = c.id
        LEFT JOIN branches         b  ON e.branch_id         = b.id
        LEFT JOIN designations     d  ON e.designation_id    = d.id
        LEFT JOIN staff_categories sc ON e.staff_category_id = sc.id
        WHERE $where_sql
        ORDER BY c.company_code, e.employee_id";
$result    = mysqli_query($conn, $sql);
$employees = [];
while ($row = mysqli_fetch_assoc($result)) $employees[] = $row;

// ── Load active loans for all SR employees ────────────────────────────────────
$loan_map = [];
if (!empty($employees)) {
    $emp_ids_all = implode(',', array_map(fn($e) => (int)$e['id'], $employees));
    $loan_sql = "SELECT lr.*,
                    COALESCE((SELECT SUM(lp.total_paid) FROM loan_payments lp WHERE lp.loan_request_id=lr.id), 0) AS paid_so_far
                 FROM loan_requests lr
                 WHERE lr.employee_id IN ($emp_ids_all) AND lr.status='Approved'
                 ORDER BY lr.employee_id, lr.id";
    $loan_res = mysqli_query($conn, $loan_sql);
    if ($loan_res) {
        while ($ln = mysqli_fetch_assoc($loan_res)) {
            $eid           = (int)$ln['employee_id'];
            $loan_amount   = floatval($ln['loan_amount']);
            $total_repay   = floatval($ln['total_repayment']) ?: $loan_amount;
            $paid_so_far   = floatval($ln['paid_so_far']);
            $balance       = round($total_repay - $paid_so_far, 2);
            if ($balance <= 0) continue;

            $monthly       = floatval($ln['monthly_instalment']);
            $this_deduct   = min($monthly, $balance);

            if (!isset($loan_map[$eid])) $loan_map[$eid] = [];
            $loan_map[$eid][] = [
                'loan_id'            => (int)$ln['id'],
                'loan_type'          => $ln['loan_type'],
                'loan_amount'        => $loan_amount,
                'monthly_instalment' => $monthly,
                'total_repayment'    => $total_repay,
                'paid_so_far'        => $paid_so_far,
                'balance'            => $balance,
                'this_deduct'        => $this_deduct,
                'no_of_instalments'  => intval($ln['no_of_instalments']),
                'total_interest'     => floatval($ln['total_interest']),
            ];
        }
    }
}

// ── Load incentive entries ────────────────────────────────────────────────────
$inc_entries = [];
if ($sel_period_id && !empty($employees)) {
    $emp_ids_str = implode(',', array_map(fn($e) => (int)$e['id'], $employees));
    $ie_res      = mysqli_query($conn, "SELECT * FROM incentive_entries WHERE payroll_period_id=$sel_period_id AND employee_id IN ($emp_ids_str)");
    if ($ie_res) {
        while ($ie = mysqli_fetch_assoc($ie_res)) {
            $eid = (int)$ie['employee_id'];
            $lbl = $ie['component_label'];
            $inc_entries[$eid][$lbl] = (float)$ie['amount'];
        }
    }
}

// ── Load SR fuel entries ──────────────────────────────────────────────────────
$fuel_period_map = [];
if ($sel_period_id && !empty($employees)) {
    $emp_ids_str = implode(',', array_map(fn($e) => (int)$e['id'], $employees));
    $fuel_res = mysqli_query($conn,
        "SELECT employee_id, (fuel_rate * fuel_liter) AS fuel_amount
         FROM sr_fuel_entries
         WHERE payroll_period_id = $sel_period_id AND employee_id IN ($emp_ids_str)");
    if ($fuel_res) {
        while ($fr = mysqli_fetch_assoc($fuel_res)) {
            $fuel_period_map[(int)$fr['employee_id']] = floatval($fr['fuel_amount']);
        }
    }
}

// ── Load approved salary advances ────────────────────────────────────────────
$advance_period_map = [];
if (!empty($employees) && $active_period) {
    $emp_ids_str = implode(',', array_map(fn($e) => (int)$e['id'], $employees));
    $adv_year    = (int)$active_period['year'];
    $adv_month   = (int)$active_period['month'];
    $adv_from    = sprintf('%04d-%02d-01', $adv_year, $adv_month);
    $adv_to      = date('Y-m-t', strtotime($adv_from));
    $adv_res = mysqli_query($conn,
        "SELECT employee_id, SUM(amount) AS total_advance
         FROM salary_advances
         WHERE status = 'Approved' AND employee_id IN ($emp_ids_str)
           AND request_date BETWEEN '$adv_from' AND '$adv_to'
         GROUP BY employee_id");
    if ($adv_res) {
        while ($ar = mysqli_fetch_assoc($adv_res)) {
            $advance_period_map[(int)$ar['employee_id']] = floatval($ar['total_advance']);
        }
    }
}

// ── Load saved overrides + additional rows ────────────────────────────────────
$saved_overrides = [];
if ($has_saved_sheet) {
    // category_code='SR' added for consistency with the other fixes above —
    // the table's UNIQUE KEY(payroll_period_id, employee_id) already means
    // this can't actually pull a wrong category's row for a given employee,
    // but scoping it explicitly keeps this query self-evidently SR-only too.
    $or_res = mysqli_query($conn,
        "SELECT employee_id, override_excess, override_arrears, additional_earnings, additional_deductions, sheet_status
         FROM salary_sheet_entries WHERE payroll_period_id=$sel_period_id AND category_code='SR'");
    if ($or_res) while ($or = mysqli_fetch_assoc($or_res)) {
        $saved_overrides[(int)$or['employee_id']] = $or;
    }
}

// ── Dropdown data ─────────────────────────────────────────────────────────────
$companies_res = mysqli_query($conn, "SELECT id, company_code, company_name FROM companies WHERE active=1 ORDER BY company_name");
$month_names   = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

function inc_val($inc_entries, $eid, $label_pattern) {
    $entries = $inc_entries[$eid] ?? [];
    foreach ($entries as $lbl => $val) {
        if (strpos($lbl, $label_pattern) !== false) return $val;
    }
    return 0;
}

// ── Build row data ────────────────────────────────────────────────────────────
$rows = [];
foreach ($employees as $emp) {
    $eid = (int)$emp['id'];
    $sh  = $GLOBALS['_att_sh_date_map'] ?? [];
    $ac  = $GLOBALS['_att_count_map']   ?? [];
    $mtd = $GLOBALS['_att_month_total'] ?? 30;
    $att = $active_period ? calc_att($emp, $sh, $ac, $mtd) : ['att'=>0,'norm'=>0,'holidays'=>0,'rate'=>0];

    $override_excess  = isset($saved_overrides[$eid]) && $saved_overrides[$eid]['override_excess']  !== null
        ? floatval($saved_overrides[$eid]['override_excess'])  : null;
    $override_arrears = isset($saved_overrides[$eid]) && $saved_overrides[$eid]['override_arrears'] !== null
        ? floatval($saved_overrides[$eid]['override_arrears']) : null;
    $add_earn_saved = isset($saved_overrides[$eid]) && !empty($saved_overrides[$eid]['additional_earnings'])
        ? json_decode($saved_overrides[$eid]['additional_earnings'], true) : [];
    $add_ded_saved  = isset($saved_overrides[$eid]) && !empty($saved_overrides[$eid]['additional_deductions'])
        ? json_decode($saved_overrides[$eid]['additional_deductions'], true) : [];

    $basic       = floatval($emp['basic_salary'] ?? 0);
    $disc        = (float)inc_val($inc_entries, $eid, '__disc_amount');
    if (!$disc)  $disc = floatval($emp['discretionary_support'] ?? 0);
    $ssv         = (float)inc_val($inc_entries, $eid, '__ssv_amount');
    $ps_amt      = (float)inc_val($inc_entries, $eid, '__incent__total_ps_compliance');
    $eco_amt     = (float)inc_val($inc_entries, $eid, '__incent__eco_incentive');
    $bp_amt      = (float)inc_val($inc_entries, $eid, '__incent__bp_incentive');
    $assort_amt  = (float)inc_val($inc_entries, $eid, '__incent__total_assortment');
    $other_incentive = (float)inc_val($inc_entries, $eid, '__other_incentive');
    // Meal, Traveling and Mobile reimbursements are sourced ONLY from the
    // incentive_entry.php entry data (incentive_entries table) — no fallback
    // to the employee profile columns anymore.
    $meal        = (float)inc_val($inc_entries, $eid, '__reimb__meal_reimbursement');
    $travel      = (float)inc_val($inc_entries, $eid, '__reimb__traveling_reimbursement');
    $mobile      = (float)inc_val($inc_entries, $eid, '__reimb__mobile_reimbursement');
    $fuel        = $fuel_period_map[$eid] ?? floatval($emp['reimbursement_fuel'] ?? 0);

    $arrears     = $override_arrears !== null ? $override_arrears : floatval($emp['arrears_salary'] ?? 0);
    $insurance   = floatval($emp['insurance_amount'] ?? 0);
    $welfare_amt = floatval($emp['welfare_amount'] ?? 0);
    $att_allow   = floatval($emp['attendance_allowance'] ?? 0);

    $norm_days    = max(1, $att['norm']);
    $per_day      = $basic > 0 ? round($basic / $norm_days, 4) : 0;
    $no_pay_days  = floatval($emp['no_pay'] ?? 0);
    $no_pay_amount = $per_day > 0 ? round($per_day * $no_pay_days, 2) : 0;

    // Loan deductions from loan_map (sum of all active loan instalments)
    $emp_loans     = $loan_map[$eid] ?? [];
    $loan_ded_auto = 0;
    foreach ($emp_loans as $ln) $loan_ded_auto += $ln['this_deduct'];
    $loan_ded = $loan_ded_auto > 0 ? $loan_ded_auto : floatval($emp['loan_deduction'] ?? 0);

    // Additional sums
    $add_earn_total = 0;
    $add_ded_total  = 0;
    if (is_array($add_earn_saved)) foreach ($add_earn_saved as $ae) $add_earn_total += floatval($ae['amount'] ?? 0);
    if (is_array($add_ded_saved))  foreach ($add_ded_saved  as $ad) $add_ded_total  += floatval($ad['amount'] ?? 0);

    $total_earnings = $basic + $disc + $ssv + $ps_amt + $eco_amt + $bp_amt + $assort_amt + $other_incentive
                    + $meal + $travel + $fuel + $mobile + $arrears + $att_allow + $add_earn_total;

    $epf_emp   = round($basic * $epf_employee_rate / 100, 2);
    $epf_er    = round($basic * $epf_employer_rate / 100, 2);
    $etf_er    = round($basic * $etf_employer_rate / 100, 2);
    $w_loan    = floatval($emp['welfare_loan_deduction'] ?? 0);
    $w_soc     = floatval($emp['welfare_society'] ?? 0);
    $donations = floatval($emp['donations'] ?? 0);
    $sal_adv   = $advance_period_map[$eid] ?? floatval($emp['salary_advance'] ?? 0);
    $credit_r  = floatval($emp['credit_recovery'] ?? 0);
    $retention = floatval($emp['retention'] ?? 0);
    $excess_p  = $override_excess !== null ? $override_excess : floatval($emp['excess_payment'] ?? 0);

    $total_deductions = $epf_emp + $welfare_amt + $w_loan + $w_soc + $donations
                      + $sal_adv + $loan_ded + $credit_r + $no_pay_amount + $retention
                      + $excess_p + $add_ded_total;

    $net_salary  = $total_earnings - $total_deductions;
    $bonus       = $basic > 0 ? round($basic / 12, 2) : 0;
    $gratuity    = $basic > 0 ? round($basic / 24, 2) : 0;
    $cost_bp     = $epf_er + $etf_er + $meal + $travel + $mobile + $fuel + $bonus + $basic + $disc;
    $provisions  = $bonus + $gratuity;
    $total_benefit = $basic + $disc + $ssv + $ps_amt + $eco_amt + $bp_amt + $assort_amt + $other_incentive
                   + $meal + $travel + $fuel + $mobile + $insurance
                   + $epf_er + $etf_er + $bonus + $gratuity;

    $fuel_from_entry    = isset($fuel_period_map[$eid]);
    $advance_from_entry = isset($advance_period_map[$eid]);

    $rows[$eid] = compact(
        'basic','disc','ssv','ps_amt','eco_amt','bp_amt','assort_amt','other_incentive',
        'meal','travel','fuel','mobile','arrears','insurance','att_allow',
        'no_pay_days','no_pay_amount','per_day',
        'total_earnings','epf_emp','welfare_amt','w_loan','w_soc',
        'donations','sal_adv','loan_ded','credit_r','retention','excess_p',
        'total_deductions','net_salary','total_benefit','epf_er','etf_er',
        'bonus','gratuity','cost_bp','provisions','att','norm_days',
        'fuel_from_entry','advance_from_entry',
        'add_earn_saved','add_ded_saved','add_earn_total','add_ded_total',
        'emp_loans'
    );
}

include 'header.php';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Space+Mono:wght@400;700&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
</head>
<style>
:root {
    --ink:       #0a0a0a;
    --ink2:      #444;
    --ink3:      #888;
    --bg:        #f2f1ee;
    --surface:   #ffffff;
    --border:    #dddbd4;
    --border2:   #c8c6bf;
    --accent:    #1a1a18;
    --mono:      'Space Mono', monospace;
    --sans:      'DM Sans', sans-serif;
    --display:   'Syne', sans-serif;
    --r:         6px;
    --r-lg:      12px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,.06),0 2px 8px rgba(0,0,0,.04);
    --shadow:    0 2px 8px rgba(0,0,0,.08),0 8px 24px rgba(0,0,0,.05);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--sans);background:var(--bg);color:var(--ink);}

/* PAGE HEADER */
.sr-page-hdr{background:var(--accent);color:#fff;padding:22px 28px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;border-radius:0 0 var(--r-lg) var(--r-lg);margin-bottom:20px;}
.sr-page-hdr-title{font-family:var(--display);font-size:22px;font-weight:800;letter-spacing:-.2px;display:flex;align-items:center;gap:10px;}
.sr-page-hdr-sub{font-size:12px;color:#a0a09a;}
.sr-page-hdr-right{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.period-pill{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:8px;padding:8px 14px;}
.period-pill-label{font-size:10px;color:#a0a09a;font-weight:700;text-transform:uppercase;letter-spacing:.5px;}
.period-pill select{background:transparent;border:none;outline:none;color:#fff;font-size:14px;font-weight:700;font-family:var(--sans);cursor:pointer;}
.period-pill select option{background:#1a1a18;color:#fff;}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:var(--r);font-size:13px;font-weight:600;font-family:var(--sans);cursor:pointer;transition:all .18s;border:none;text-decoration:none;}
.btn-white{background:#fff;color:var(--ink);}.btn-white:hover{background:#f0f0ea;}
.btn-outline{background:transparent;color:#fff;border:1.5px solid rgba(255,255,255,.25);}.btn-outline:hover{background:rgba(255,255,255,.08);}
.btn-green{background:#22c55e;color:#fff;}.btn-green:hover{background:#16a34a;}
.btn-ghost{background:var(--bg);color:var(--ink2);border:1px solid var(--border);}.btn-ghost:hover{background:var(--border);}
.btn-sm{padding:7px 13px;font-size:12px;}
.btn-primary{background:var(--ink);color:#fff;}.btn-primary:hover{background:#333;}
.btn-blue{background:#2563eb;color:#fff;}.btn-blue:hover{background:#1d4ed8;}
.btn-red{background:#dc2626;color:#fff;}.btn-red:hover{background:#b91c1c;}
.btn-amber{background:#d97706;color:#fff;}.btn-amber:hover{background:#b45309;}
.btn-purple{background:#7c3aed;color:#fff;}.btn-purple:hover{background:#6d28d9;}
.btn-teal{background:#0d9488;color:#fff;}.btn-teal:hover{background:#0f766e;}
.btn-indigo{background:#4338ca;color:#fff;}.btn-indigo:hover{background:#3730a3;}
.btn:disabled{opacity:.55;cursor:not-allowed;}

/* SHEET STATUS BANNER */
.sheet-banner{border-radius:var(--r-lg);padding:14px 20px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;font-size:13px;font-weight:600;}
.sheet-banner-saved{background:#dcfce7;border:1.5px solid #86efac;color:#14532d;}
.sheet-banner-confirmed{background:#dbeafe;border:1.5px solid #93c5fd;color:#1e3a8a;}
.sheet-banner-unsaved{background:#fef3c7;border:1.5px solid #fde68a;color:#78350f;}
.sheet-banner-left{display:flex;align-items:center;gap:10px;}
.sheet-banner-actions{display:flex;gap:8px;flex-wrap:wrap;}

/* STATUS PILL */
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:800;letter-spacing:.3px;}
.status-pending{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.status-confirmed{background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;}

/* FILTER BAR */
.filter-bar{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);margin:0 0 16px;box-shadow:var(--shadow-sm);overflow:hidden;}
.filter-toggle{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;cursor:pointer;user-select:none;font-size:13px;font-weight:600;color:var(--ink2);}
.filter-toggle:hover{background:var(--bg);}
.filter-body{display:none;padding:0 18px 16px;border-top:1px solid var(--border);}
.filter-body.open{display:block;}
.filter-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:14px;}
.filter-group label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--ink3);margin-bottom:5px;}
.filter-select{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:var(--r);font-size:13px;font-family:var(--sans);background:#fff;color:var(--ink);}
.filter-select:focus{outline:none;border-color:var(--ink);}
.filter-actions{display:flex;gap:8px;margin-top:12px;}

/* STATS ROW */
.stats-row{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
.stat-chip{background:var(--surface);border:1px solid var(--border);border-radius:30px;padding:6px 14px;display:flex;align-items:center;gap:7px;font-size:13px;font-weight:500;color:var(--ink2);box-shadow:var(--shadow-sm);}
.stat-chip strong{color:var(--ink);font-weight:800;}
.stat-chip .dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.dot-green{background:#22c55e;}.dot-blue{background:#3b82f6;}.dot-amber{background:#f59e0b;}.dot-red{background:#ef4444;}.dot-purple{background:#8b5cf6;}.dot-pink{background:#be185d;}

/* SUMMARY CARDS */
.totals-section{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:20px 24px;margin-bottom:16px;box-shadow:var(--shadow-sm);}
.totals-section-title{font-family:var(--display);font-size:13px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:8px;color:var(--ink2);}
.totals-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;}
.total-card{border-radius:var(--r-lg);padding:14px 16px;border:1px solid var(--border);}
.total-card-earn{background:#e8f5ee;border-color:#a7f3d0;}
.total-card-ded{background:#fdf0f0;border-color:#fca5a5;}
.total-card-net{background:#dcfce7;border-color:#86efac;}
.total-card-ben{background:#e8f0fb;border-color:#93c5fd;}
.total-card-bp{background:#fffbeb;border-color:#fde68a;}
.total-card-epf{background:#eff6ff;border-color:#bfdbfe;}
.total-card-oinc{background:#fdf2f8;border-color:#f9a8d4;}
.total-card-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.total-card-earn .total-card-label{color:#0f3d1e;}
.total-card-ded  .total-card-label{color:#3d0f0f;}
.total-card-net  .total-card-label{color:#065f46;}
.total-card-ben  .total-card-label{color:#1a2e4a;}
.total-card-bp   .total-card-label{color:#78350f;}
.total-card-epf  .total-card-label{color:#1e3a8a;}
.total-card-oinc .total-card-label{color:#9d174d;}
.total-card-value{font-family:var(--mono);font-size:15px;font-weight:700;}
.total-card-earn .total-card-value{color:#065f46;}
.total-card-ded  .total-card-value{color:#991b1b;}
.total-card-net  .total-card-value{color:#065f46;}
.total-card-ben  .total-card-value{color:#1e3a8a;}
.total-card-bp   .total-card-value{color:#78350f;}
.total-card-epf  .total-card-value{color:#1d4ed8;}
.total-card-oinc .total-card-value{color:#be185d;}

/* MAIN TABLE CARD */
.salary-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);box-shadow:var(--shadow);overflow:hidden;margin-bottom:24px;}
.salary-card-header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:#fafaf8;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:10px;}
.salary-card-title{font-family:var(--display);font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;}
.salary-card-actions{display:flex;gap:8px;align-items:center;}

/* MAIN TABLE */
.salary-scroll{overflow-x:auto;}
.salary-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1560px;}

/* STICKY COLS */
.col-s1{position:sticky;left:0;background:var(--surface);z-index:2;min-width:120px;}
.col-s2{position:sticky;left:120px;background:var(--surface);z-index:2;min-width:90px;}
.col-s3{position:sticky;left:210px;background:var(--surface);z-index:2;min-width:90px;}
.col-s4{position:sticky;left:300px;background:var(--surface);z-index:2;min-width:170px;}
.col-s5{position:sticky;left:470px;background:var(--surface);z-index:2;min-width:130px;border-right:2px solid var(--border2);}
.salary-table tbody tr:hover .col-s1,
.salary-table tbody tr:hover .col-s2,
.salary-table tbody tr:hover .col-s3,
.salary-table tbody tr:hover .col-s4,
.salary-table tbody tr:hover .col-s5{background:#f5f5f0;}

/* HEADER ROWS */
.salary-table thead th{padding:9px 12px;text-align:center;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;background:#18181b;color:#9ca3af;border-right:1px solid #2d2d30;white-space:nowrap;position:sticky;top:0;z-index:3;}
.salary-table thead th.th-group{font-size:11px;font-weight:800;letter-spacing:.3px;border-bottom:1px solid #3f3f46;padding:10px 12px;}
.salary-table thead th.th-emp{background:#111;color:#e5e7eb;text-align:left;border-right:none;}
.th-att{background:#0f2a4a !important;color:#93c5fd !important;}
.th-earn{background:#0f3d1e !important;color:#a7f3d0 !important;}
.th-ded{background:#3d0f0f !important;color:#fca5a5 !important;}
.th-net{background:#14401f !important;color:#6ee7b7 !important;}
.th-ben{background:#1a2e4a !important;color:#93c5fd !important;}
.th-bp{background:#2d1b00 !important;color:#fcd34d !important;}
.th-loan{background:#2d0f4a !important;color:#d8b4fe !important;}
.th-oinc{background:#2a0f1e !important;color:#f9a8d4 !important;}
.salary-table thead th.col-s1,.salary-table thead th.col-s2,.salary-table thead th.col-s3,
.salary-table thead th.col-s4,.salary-table thead th.col-s5{z-index:5;}

/* BODY */
.salary-table tbody tr{border-bottom:1px solid #f2f2ee;transition:background .1s;}
.salary-table tbody tr:hover{background:#f9f9f6;}
.salary-table tbody td{padding:9px 12px;vertical-align:middle;border-right:1px solid #f2f2ee;text-align:right;}
.salary-table tbody td.td-left{text-align:left;}
.salary-table tbody td.td-center{text-align:center;}

/* CELL COLORS */
.td-att{background:rgba(15,42,74,.025);}
.td-earn{background:rgba(15,61,30,.025);}
.td-ded{background:rgba(61,15,15,.025);}
.td-net{background:rgba(20,64,31,.04);}
.td-ben{background:rgba(26,46,74,.025);}
.td-bp{background:rgba(45,27,0,.025);}
.td-loan{background:rgba(45,15,74,.025);}
.td-oinc{background:rgba(42,15,30,.025);}

/* TOTAL ROW */
.salary-table tbody tr.tr-total{background:#18181b !important;font-weight:700;border-top:2px solid #3f3f46;}
.salary-table tbody tr.tr-total td{color:#e5e7eb;font-family:var(--mono);font-size:11px;padding:10px;border-right:1px solid #2d2d30;}
.salary-table tbody tr.tr-total .col-s1,.salary-table tbody tr.tr-total .col-s2,.salary-table tbody tr.tr-total .col-s3,.salary-table tbody tr.tr-total .col-s4,.salary-table tbody tr.tr-total .col-s5{background:#18181b;}

/* MONEY */
.money-earn{font-family:var(--mono);font-size:11.5px;font-weight:600;color:#0a6634;}
.money-ded{font-family:var(--mono);font-size:11.5px;font-weight:600;color:#991b1b;}
.money-net{font-family:var(--mono);font-size:12.5px;font-weight:700;color:#065f46;}
.money-ben{font-family:var(--mono);font-size:12px;font-weight:700;color:#1e3a8a;}
.money-bp{font-family:var(--mono);font-size:11.5px;font-weight:600;color:#78350f;}
.money-zero{color:#ccc;font-family:var(--mono);font-size:11px;}
.money-att{font-family:var(--mono);font-size:11.5px;font-weight:600;color:#1d4ed8;}
.money-loan{font-family:var(--mono);font-size:11.5px;font-weight:700;color:#7c3aed;}
.money-oinc{font-family:var(--mono);font-size:11.5px;font-weight:700;color:#be185d;}

/* EMPLOYEE CELL */
.emp-id-tag{font-family:var(--mono);font-size:10px;font-weight:700;color:#1d4ed8;background:#eff6ff;padding:1px 5px;border-radius:3px;}
.emp-name-cell{font-size:12px;font-weight:700;color:var(--ink);}
.emp-meta{font-size:10px;color:var(--ink3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;}
.s-badge{display:inline-block;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:600;}
.s-probation{background:#fef3c7;color:#92400e;}
.s-permanent{background:#dcfce7;color:#15803d;}
.s-resigned{background:#f3f4f6;color:#6b7280;}
.s-terminated{background:#fee2e2;color:#991b1b;}
.cat-chip{display:inline-block;padding:2px 7px;background:#f3e8ff;color:#6d28d9;border-radius:4px;font-size:10px;font-weight:700;}

/* LOAN BADGE IN TABLE */
.loan-count-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;background:#f3e8ff;color:#7c3aed;white-space:nowrap;}
.loan-count-zero{background:#f3f4f6;color:#9ca3af;}

/* SOURCE BADGE */
.src-badge{display:inline-block;font-size:9px;font-weight:700;padding:1px 5px;border-radius:3px;vertical-align:middle;margin-left:3px;line-height:1.4;}
.src-live{background:#dcfce7;color:#166534;}
.src-emp{background:#f3f4f6;color:#6b7280;}
.src-loan{background:#f3e8ff;color:#7c3aed;}

/* ATT PILL */
.att-pill{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap;font-family:var(--mono);}
.att-high{background:#dcfce7;color:#14532d;}
.att-mid{background:#fef3c7;color:#78350f;}
.att-low{background:#fee2e2;color:#7f1d1d;}
.att-zero{background:#f3f4f6;color:#9ca3af;}

/* VIEW BUTTON */
.row-view-btn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:var(--r);border:1px solid var(--border);background:var(--surface);color:var(--ink3);cursor:pointer;font-size:11px;transition:all .15s;text-decoration:none;}
.row-view-btn:hover{background:#2563eb;color:#fff;border-color:#2563eb;}

/* MODAL */
.modal-bg{display:none;position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,.6);align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(4px);}
.modal-bg.show{display:flex;}
.modal-box{background:var(--surface);border-radius:16px;width:100%;max-width:1200px;max-height:94vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 32px 100px rgba(0,0,0,.3);animation:modalIn .3s cubic-bezier(.22,.68,0,1.2);}
@keyframes modalIn{from{opacity:0;transform:translateY(24px) scale(.96)}to{opacity:1;transform:none}}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:20px 26px;border-bottom:1px solid var(--border);background:#fafaf8;flex-shrink:0;}
.modal-emp-info{display:flex;flex-direction:column;gap:4px;}
.modal-emp-name{font-family:var(--display);font-size:18px;font-weight:800;color:var(--ink);}
.modal-emp-meta{font-size:12px;color:var(--ink3);display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
.modal-emp-meta span{display:flex;align-items:center;gap:4px;}
.modal-close{background:none;border:none;cursor:pointer;color:var(--ink3);font-size:18px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;border-radius:var(--r);transition:all .15s;}
.modal-close:hover{background:#f3f4f6;color:var(--ink);}
.modal-body{padding:0;overflow-y:auto;flex:1;}
.modal-footer{padding:16px 26px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:10px;flex-shrink:0;background:#fafaf8;}
.modal-footer-links{display:flex;gap:8px;flex-wrap:wrap;}

/* NET BAND */
.net-hero-band{background:linear-gradient(135deg,#0f3d1e 0%,#14401f 50%,#0d3118 100%);padding:24px 26px;display:grid;grid-template-columns:1fr auto 1fr auto 1fr auto 1fr;gap:0;align-items:center;}
.net-hero-item{text-align:center;padding:4px 8px;}
.net-hero-divider{width:1px;background:rgba(255,255,255,.15);align-self:stretch;margin:0 4px;}
.net-hero-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:rgba(255,255,255,.5);margin-bottom:5px;}
.net-hero-value{font-family:var(--mono);font-size:18px;font-weight:800;color:#6ee7b7;}
.net-hero-value.v-earn{color:#86efac;}
.net-hero-value.v-ded{color:#fca5a5;}
.net-hero-value.v-net{color:#fff;font-size:24px;}
.net-hero-value.v-ben{color:#93c5fd;}
.net-hero-value.v-bp{color:#fcd34d;}

/* ATT BAND */
.att-info-band{padding:14px 26px;display:flex;gap:16px;background:#f8faff;border-bottom:1px solid var(--border);flex-wrap:wrap;}
.att-info-card{background:#fff;border:1px solid #dbeafe;border-radius:var(--r-lg);padding:12px 18px;display:flex;flex-direction:column;gap:3px;flex:1;min-width:120px;}
.att-info-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#93c5fd;}
.att-info-value{font-family:var(--mono);font-size:18px;font-weight:800;color:#1e3a8a;}
.att-info-sub{font-size:10px;color:var(--ink3);}

/* LOAN BAND */
.loan-band{padding:14px 26px;background:#faf5ff;border-bottom:1px solid #e9d5ff;}
.loan-band-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#7c3aed;margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.loan-cards-row{display:flex;gap:10px;flex-wrap:wrap;}
.loan-detail-card{background:#fff;border:1.5px solid #c4b5fd;border-radius:10px;padding:12px 16px;min-width:220px;flex:1;}
.loan-detail-card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;}
.loan-detail-card-type{font-size:11px;font-weight:800;color:#6d28d9;display:flex;align-items:center;gap:5px;}
.loan-detail-card-status{font-size:10px;padding:2px 7px;border-radius:8px;font-weight:700;}
.ldc-approved{background:#dcfce7;color:#166534;}
.loan-detail-row{display:flex;justify-content:space-between;font-size:11.5px;padding:3px 0;border-bottom:1px solid #f5f0ff;}
.loan-detail-row:last-child{border-bottom:none;}
.loan-detail-label{color:#9ca3af;}
.loan-detail-val{font-family:var(--mono);font-weight:700;color:var(--ink);}
.loan-detail-val.v-balance{color:#7c3aed;}
.loan-detail-val.v-deduct{color:#991b1b;}
.loan-detail-val.v-paid{color:#166534;}
.loan-no-loans{font-size:13px;color:#9ca3af;display:flex;align-items:center;gap:7px;padding:8px 0;}

/* MODAL SECTIONS */
.modal-sections{padding:20px 26px;display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.modal-section{border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden;}
.ms-header{display:flex;align-items:center;gap:8px;padding:11px 16px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);}
.ms-earn{background:#e8f5ee;color:#0f3d1e;}
.ms-ded{background:#fdf0f0;color:#3d0f0f;}
.ms-att{background:#eff6ff;color:#1e3a8a;}
.ms-ben{background:#fffbeb;color:#78350f;}
.ms-body{padding:4px 0;}
.ms-row{display:flex;justify-content:space-between;align-items:center;padding:7px 16px;border-bottom:1px solid #f8f8f6;font-size:12.5px;}
.ms-row:last-child{border-bottom:none;}
.ms-row-label{color:var(--ink2);}
.ms-row-value{font-family:var(--mono);font-weight:600;font-size:11.5px;}
.ms-row.ms-zero .ms-row-value{color:#d1d5db;}
.ms-row.ms-earn-val .ms-row-value{color:#0a6634;}
.ms-row.ms-ded-val  .ms-row-value{color:#991b1b;}
.ms-row.ms-att-val  .ms-row-value{color:#1d4ed8;}
.ms-row.ms-ben-val  .ms-row-value{color:#1e3a8a;}
.ms-row.ms-loan-val .ms-row-value{color:#7c3aed;}
.ms-row.ms-oinc-val .ms-row-value{color:#be185d;}
.ms-total{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;font-weight:700;font-size:13px;border-top:2px solid var(--border2);}
.ms-total-earn{background:#e8f5ee;color:#065f46;}
.ms-total-ded{background:#fee2e2;color:#991b1b;}
.ms-total-att{background:#eff6ff;color:#1d4ed8;}
.ms-total-ben{background:#fffbeb;color:#78350f;}
.ms-total span:last-child{font-family:var(--mono);font-size:14px;}

/* EDITABLE FIELD IN MODAL */
.modal-edit-section{padding:16px 26px;background:#faf5ff;border-top:1px solid #e9d5ff;border-bottom:1px solid #e9d5ff;}
.modal-edit-section-title{font-family:var(--display);font-size:12px;font-weight:800;color:#4c1d95;margin-bottom:12px;display:flex;align-items:center;gap:8px;text-transform:uppercase;letter-spacing:.4px;}
.modal-edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.modal-edit-field{display:flex;flex-direction:column;gap:5px;}
.modal-edit-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#7c3aed;}
.modal-edit-input{padding:9px 12px;border:1.5px solid #c4b5fd;border-radius:var(--r);font-size:13px;font-family:var(--mono);font-weight:600;background:#fff;color:#4c1d95;outline:none;transition:border-color .15s,box-shadow .15s;}
.modal-edit-input:focus{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.12);}
.modal-edit-input[readonly]{background:#f5f0ff;color:#9ca3af;cursor:not-allowed;}

/* ADDITIONAL ROWS SECTION */
.add-rows-section{padding:4px 0;}
.add-row-item{display:flex;align-items:center;gap:8px;padding:6px 16px;border-bottom:1px solid #f5f0ff;}
.add-row-item:last-child{border-bottom:none;}
.add-row-desc{flex:1;padding:6px 10px;border:1.5px solid #e9d5ff;border-radius:5px;font-size:12px;font-family:var(--sans);background:#fff;color:var(--ink);outline:none;transition:border-color .15s;}
.add-row-desc:focus{border-color:#7c3aed;}
.add-row-amount{width:110px;padding:6px 10px;border:1.5px solid #e9d5ff;border-radius:5px;font-size:12px;font-family:var(--mono);font-weight:600;background:#fff;color:#0a6634;outline:none;text-align:right;transition:border-color .15s;}
.add-row-amount:focus{border-color:#7c3aed;}
.add-row-amount.is-ded{color:#991b1b;}
.add-row-remove{width:26px;height:26px;border:none;border-radius:50%;background:#fee2e2;color:#dc2626;font-size:13px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .15s;}
.add-row-remove:hover{background:#fecaca;}
.add-rows-footer{padding:8px 16px;display:flex;align-items:center;justify-content:space-between;background:#faf5ff;border-top:1px solid #f0e8ff;}
.add-row-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border-radius:var(--r);border:1.5px dashed #c4b5fd;background:transparent;color:#7c3aed;font-size:11px;font-weight:700;cursor:pointer;transition:all .15s;font-family:var(--sans);}
.add-row-btn:hover{background:#f5f0ff;border-color:#7c3aed;}
.add-row-total{font-family:var(--mono);font-size:12px;font-weight:700;color:#4c1d95;}

/* MODAL UPDATE BANNER */
.modal-update-bar{padding:14px 26px;background:#f5f0ff;border-top:1px solid #e9d5ff;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-shrink:0;}
.modal-update-info{font-size:12px;color:#6d28d9;font-weight:600;display:flex;align-items:center;gap:7px;}
.modal-update-status{font-size:11px;font-weight:700;display:flex;align-items:center;gap:5px;}
.upd-saved{color:#15803d;}.upd-error{color:#991b1b;}.upd-saving{color:#d97706;}

/* CONFIRM MODAL */
.confirm-bg{display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.6);align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(4px);}
.confirm-bg.show{display:flex;}
.confirm-box{background:#fff;border-radius:16px;padding:32px;max-width:480px;width:100%;box-shadow:0 32px 80px rgba(0,0,0,.3);animation:modalIn .25s cubic-bezier(.22,.68,0,1.2);}
.confirm-box h3{font-family:var(--display);font-size:18px;font-weight:800;margin-bottom:8px;}
.confirm-box p{color:var(--ink2);font-size:13px;line-height:1.6;margin-bottom:20px;}
.confirm-actions{display:flex;gap:10px;justify-content:flex-end;}

/* CONFIRM SUCCESS BANNER */
.confirm-result-banner{border-radius:10px;padding:14px 18px;margin-top:16px;font-size:13px;}
.crb-success{background:#dcfce7;border:1px solid #86efac;color:#14532d;}
.crb-error{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}

/* Progress bar for loan */
.loan-progress-bar{height:6px;background:#e9d5ff;border-radius:4px;overflow:hidden;margin-top:6px;}
.loan-progress-fill{height:100%;background:linear-gradient(90deg,#7c3aed,#a78bfa);border-radius:4px;transition:width .5s;}

@media(max-width:900px){
    .filter-grid{grid-template-columns:1fr 1fr;}
    .totals-grid{grid-template-columns:repeat(3,1fr);}
    .modal-sections{grid-template-columns:1fr;}
    .modal-edit-grid{grid-template-columns:1fr;}
    .net-hero-band{grid-template-columns:1fr;gap:8px;}
    .net-hero-divider{display:none;}
    .net-hero-item{padding:8px;background:rgba(255,255,255,.05);border-radius:8px;}
    .loan-cards-row{flex-direction:column;}
}
@media(max-width:600px){
    .filter-grid{grid-template-columns:1fr;}
    .totals-grid{grid-template-columns:1fr 1fr;}
}
</style>

<!-- PAGE HEADER -->
<div class="sr-page-hdr">
    <div>
        <div class="sr-page-hdr-title"><i class="fa-solid fa-file-invoice-dollar"></i> SR Salary Sheet</div>
        <div class="sr-page-hdr-sub">Sales Representatives — Payroll Summary</div>
    </div>
    <div class="sr-page-hdr-right">
        <form method="GET" id="periodForm" style="display:contents;">
            <?php if (!empty($all_periods)): ?>
            <div class="period-pill">
                <i class="fa-solid fa-calendar-check" style="color:#a0a09a;"></i>
                <div>
                    <div class="period-pill-label">Payroll Period</div>
                    <select name="period_id" onchange="document.getElementById('periodForm').submit()">
                        <?php foreach ($all_periods as $pp): ?>
                        <option value="<?php echo $pp['id']; ?>" <?php echo $sel_period_id==$pp['id']?'selected':''; ?>>
                            <?php echo $month_names[$pp['month']].' '.$pp['year'];
                                  echo ($pp['status']!=='Open') ? ' ('.$pp['status'].')' : ''; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($active_period): ?>
                <span style="font-size:10px;padding:2px 8px;border-radius:10px;font-weight:700;<?php echo ($active_period['status']??'Open')==='Open'?'background:#dcfce7;color:#166534;':'background:#f3f4f6;color:#6b7280;'; ?>">
                    <?php echo htmlspecialchars($active_period['status']??'Open'); ?>
                </span>
                <?php endif; ?>
            </div>
            <?php if ($filter_status):  ?><input type="hidden" name="filter_status"  value="<?php echo $filter_status; ?>"><?php endif; ?>
            <?php if ($filter_company): ?><input type="hidden" name="filter_company" value="<?php echo $filter_company; ?>"><?php endif; ?>
            <?php if ($filter_branch):  ?><input type="hidden" name="filter_branch"  value="<?php echo $filter_branch; ?>"><?php endif; ?>
            <?php endif; ?>
        </form>
        <a href="bulk_salary_update.php" class="btn btn-outline btn-sm"><i class="fa-solid fa-sliders"></i> Bulk Update</a>
        <button class="btn btn-white btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
<button class="btn btn-green btn-sm" onclick="exportExcel()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>    </div>
</div>

<!-- SHEET STATUS BANNER -->
<?php if ($has_saved_sheet && $is_confirmed): ?>
<div class="sheet-banner sheet-banner-confirmed" id="sheetBanner">
    <div class="sheet-banner-left">
        <i class="fa-solid fa-circle-check" style="font-size:18px;"></i>
        <div>
            <strong>Salary sheet CONFIRMED</strong> —
            <?php echo $saved_sheet_count; ?> employees ·
            <?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : ''; ?>
            <span style="font-size:11px;font-weight:400;margin-left:6px;opacity:.8;">Loan payments have been recorded. Sheet is locked.</span>
        </div>
    </div>
    <div class="sheet-banner-actions">
        <span class="status-pill status-confirmed"><i class="fa-solid fa-lock"></i> CONFIRMED</span>
    </div>
</div>
<?php elseif ($has_saved_sheet): ?>
<div class="sheet-banner sheet-banner-saved" id="sheetBanner">
    <div class="sheet-banner-left">
        <i class="fa-solid fa-floppy-disk" style="font-size:16px;"></i>
        <div>
            <strong>Salary sheet saved</strong> —
            <?php echo $saved_sheet_count; ?> employees ·
            <?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : ''; ?>
            <span class="status-pill status-pending" style="margin-left:8px;"><i class="fa-solid fa-clock"></i> PENDING</span>
            <span style="font-size:11px;font-weight:400;margin-left:6px;opacity:.75;">Review loans &amp; edit values, then confirm to lock &amp; record payments.</span>
        </div>
    </div>
    <div class="sheet-banner-actions">
        <button class="btn btn-amber btn-sm" onclick="refreshSheet()">
            <i class="fa-solid fa-arrows-rotate"></i> Refresh
        </button>
        <button class="btn btn-red btn-sm" onclick="confirmDeleteSheet()">
            <i class="fa-solid fa-trash"></i> Delete
        </button>
        <button class="btn btn-indigo btn-sm" onclick="confirmSheetModal()">
            <i class="fa-solid fa-check-double"></i> Confirm Salary Sheet
        </button>
    </div>
</div>
<?php else: ?>
<div class="sheet-banner sheet-banner-unsaved" id="sheetBanner">
    <div class="sheet-banner-left">
        <i class="fa-solid fa-triangle-exclamation" style="font-size:16px;"></i>
        <div>
            <strong>No saved sheet</strong> for <?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : 'this period'; ?>.
            <span style="font-size:11px;font-weight:400;margin-left:6px;opacity:.8;">Create a salary sheet to lock values, review loans &amp; confirm.</span>
        </div>
    </div>
    <div class="sheet-banner-actions">
        <button class="btn btn-primary btn-sm" onclick="createSalarySheet()">
            <i class="fa-solid fa-floppy-disk"></i> Create Salary Sheet
        </button>
    </div>
</div>
<?php endif; ?>

<!-- FILTERS -->
<div class="filter-bar">
    <div class="filter-toggle" onclick="toggleFilter()">
        <div style="display:flex;align-items:center;gap:8px;"><i class="fa-solid fa-sliders"></i><span>Filters</span>
        <?php $active_f = array_filter([$filter_status,$filter_company,$filter_branch]); if(count($active_f)):?>
        <span style="background:var(--ink);color:#fff;border-radius:20px;padding:1px 7px;font-size:11px;font-weight:700;"><?php echo count($active_f);?></span>
        <?php endif; ?></div>
        <i class="fa-solid fa-chevron-down" id="filterChevron" style="transition:transform .2s;"></i>
    </div>
    <div class="filter-body <?php echo count($active_f)?'open':''; ?>" id="filterBody">
        <form method="GET" action="">
            <?php if ($sel_period_id): ?><input type="hidden" name="period_id" value="<?php echo $sel_period_id; ?>"><?php endif; ?>
            <div class="filter-grid">
                <div class="filter-group">
                    <label>Company</label>
                    <select name="filter_company" class="filter-select" onchange="loadBranches(this.value)">
                        <option value="">All Companies</option>
                        <?php if ($companies_res) { mysqli_data_seek($companies_res,0); while($c=mysqli_fetch_assoc($companies_res)): ?>
                        <option value="<?php echo $c['id'];?>" <?php echo $filter_company==$c['id']?'selected':'';?>><?php echo htmlspecialchars($c['company_code'].' - '.$c['company_name']);?></option>
                        <?php endwhile; } ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Branch</label>
                    <select name="filter_branch" id="filter_branch" class="filter-select">
                        <option value="">All Branches</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Status</label>
                    <select name="filter_status" class="filter-select">
                        <option value="">All Status</option>
                        <option value="Probation"  <?php echo $filter_status=='Probation'?'selected':'';?>>Probation</option>
                        <option value="Permanent"  <?php echo $filter_status=='Permanent'?'selected':'';?>>Permanent</option>
                        <option value="Resigned"   <?php echo $filter_status=='Resigned'?'selected':'';?>>Resigned</option>
                        <option value="Terminated" <?php echo $filter_status=='Terminated'?'selected':'';?>>Terminated</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Search Name / Code</label>
                    <input type="text" id="liveSearch" class="filter-select" placeholder="Type to search…" oninput="liveFilter(this.value)" style="background:#fff;">
                </div>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Apply</button>
                <a href="sr_salary.php<?php echo $sel_period_id?'?period_id='.$sel_period_id:'';?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- STATS ROW -->
<?php
$totals = ['earn'=>0,'ded'=>0,'net'=>0,'ben'=>0,'bp'=>0,'att_allow'=>0,'no_pay_amount'=>0,'epf_er'=>0,'etf_er'=>0,'fuel'=>0,'sal_adv'=>0,'prov'=>0,'loan_ded'=>0,'other_incentive'=>0];
$total_loan_count = 0;
foreach ($rows as $eid_k => $r) {
    $totals['earn']        += $r['total_earnings'];
    $totals['ded']         += $r['total_deductions'];
    $totals['net']         += $r['net_salary'];
    $totals['ben']         += $r['total_benefit'];
    $totals['bp']          += $r['cost_bp'];
    $totals['att_allow']   += $r['att_allow'];
    $totals['no_pay_amount']+= $r['no_pay_amount'];
    $totals['epf_er']      += $r['epf_er'];
    $totals['etf_er']      += $r['etf_er'];
    $totals['fuel']        += $r['fuel'];
    $totals['sal_adv']     += $r['sal_adv'];
    $totals['prov']        += $r['provisions'];
    $totals['loan_ded']    += $r['loan_ded'];
    $totals['other_incentive'] += $r['other_incentive'];
    $total_loan_count      += count($r['emp_loans']);
}
$cnt_active = count(array_filter($employees, fn($e) => in_array($e['status'],['Probation','Permanent'])));
?>
<div class="stats-row">
    <div class="stat-chip"><span class="dot dot-blue"></span><span><strong><?php echo count($employees); ?></strong> SR Employees</span></div>
    <div class="stat-chip"><span class="dot dot-green"></span><span><strong><?php echo $cnt_active; ?></strong> Active</span></div>
    <div class="stat-chip"><span class="dot dot-green"></span><span>Total Earnings <strong>LKR <?php echo number_format($totals['earn'],2); ?></strong></span></div>
    <div class="stat-chip"><span class="dot dot-red"></span><span>Deductions <strong>LKR <?php echo number_format($totals['ded'],2); ?></strong></span></div>
    <div class="stat-chip"><span class="dot dot-green"></span><span>Net Salary <strong>LKR <?php echo number_format($totals['net'],2); ?></strong></span></div>
    <div class="stat-chip"><span class="dot dot-purple"></span><span>Loan Deductions <strong>LKR <?php echo number_format($totals['loan_ded'],2); ?></strong> (<?php echo $total_loan_count; ?> loans)</span></div>
    <div class="stat-chip"><span class="dot dot-pink"></span><span>Other Incentive <strong>LKR <?php echo number_format($totals['other_incentive'],2); ?></strong></span></div>
    <div class="stat-chip"><span class="dot dot-amber"></span><span>No Pay <strong>LKR <?php echo number_format($totals['no_pay_amount'],2); ?></strong></span></div>
    <div class="stat-chip"><span class="dot dot-amber"></span><span>Fuel <strong>LKR <?php echo number_format($totals['fuel'],2); ?></strong></span></div>
</div>

<!-- SUMMARY CARDS -->
<div class="totals-section">
    <div class="totals-section-title"><i class="fa-solid fa-sigma"></i> Period Summary — <?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : 'No Period'; ?></div>
    <div class="totals-grid">
        <div class="total-card total-card-earn">
            <div class="total-card-label"><i class="fa-solid fa-arrow-trend-up"></i> Total Earnings</div>
            <div class="total-card-value">LKR <?php echo number_format($totals['earn'],2); ?></div>
        </div>
        <div class="total-card total-card-ded">
            <div class="total-card-label"><i class="fa-solid fa-circle-minus"></i> Total Deductions</div>
            <div class="total-card-value">LKR <?php echo number_format($totals['ded'],2); ?></div>
        </div>
        <div class="total-card total-card-net">
            <div class="total-card-label"><i class="fa-solid fa-wallet"></i> Net Salary (Take-Home)</div>
            <div class="total-card-value">LKR <?php echo number_format($totals['net'],2); ?></div>
        </div>
        <div class="total-card total-card-ben">
            <div class="total-card-label"><i class="fa-solid fa-star"></i> Total Benefit / Cost</div>
            <div class="total-card-value">LKR <?php echo number_format($totals['ben'],2); ?></div>
        </div>
        <div class="total-card" style="background:#f3e8ff;border-color:#c4b5fd;">
            <div class="total-card-label" style="color:#4c1d95;"><i class="fa-solid fa-hand-holding-dollar"></i> Loan Deductions</div>
            <div class="total-card-value" style="color:#7c3aed;">LKR <?php echo number_format($totals['loan_ded'],2); ?></div>
        </div>
        <div class="total-card total-card-oinc">
            <div class="total-card-label"><i class="fa-solid fa-gift"></i> Other Incentive</div>
            <div class="total-card-value">LKR <?php echo number_format($totals['other_incentive'],2); ?></div>
        </div>
        <div class="total-card total-card-epf">
            <div class="total-card-label"><i class="fa-solid fa-ban"></i> No Pay Deduction</div>
            <div class="total-card-value">LKR <?php echo number_format($totals['no_pay_amount'],2); ?></div>
        </div>
    </div>
</div>

<!-- HIDDEN FORM FOR SAVE SHEET -->
<form id="saveSheetForm" method="POST" action="sr_salary.php?period_id=<?php echo $sel_period_id; ?>">
    <input type="hidden" name="action" value="save_sheet">
    <input type="hidden" name="period_id" value="<?php echo $sel_period_id; ?>">
    <input type="hidden" name="rows_json" id="saveRowsJson" value="">
</form>

<!-- MAIN TABLE -->
<div class="salary-card">
    <div class="salary-card-header">
        <div class="salary-card-title">
            <i class="fa-solid fa-table-columns"></i>
            SR Salary Sheet
            <span style="font-weight:400;color:var(--ink3);font-size:12px;"><?php echo count($employees); ?> records</span>
            <?php if ($is_confirmed): ?>
            <span style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;"><i class="fa-solid fa-lock"></i> CONFIRMED</span>
            <?php elseif ($has_saved_sheet): ?>
            <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;"><i class="fa-solid fa-clock"></i> PENDING</span>
            <?php endif; ?>
        </div>
        <div class="salary-card-actions">
            <?php if ($has_saved_sheet && !$is_confirmed): ?>
            <span style="font-size:11px;color:#7c3aed;font-weight:600;"><i class="fa-solid fa-pen-to-square" style="margin-right:4px;"></i>Click any row to edit &amp; view loans</span>
            <?php elseif ($is_confirmed): ?>
            <span style="font-size:11px;color:#1d4ed8;font-weight:600;"><i class="fa-solid fa-lock" style="margin-right:4px;"></i>Sheet locked — loan payments recorded</span>
            <?php else: ?>
            <span style="font-size:11px;color:var(--ink3);"><i class="fa-solid fa-hand-pointer" style="margin-right:4px;"></i>Click any row to view full details</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="salary-scroll">
        <?php if (empty($employees)): ?>
        <div style="text-align:center;padding:60px 20px;color:var(--ink3);">
            <i class="fa-solid fa-users-slash" style="font-size:40px;margin-bottom:12px;display:block;"></i>
            <p>No SR employees found.</p>
        </div>
        <?php else: ?>
        <table class="salary-table" id="salaryTable">
            <thead>
                <tr>
                    <th colspan="5" class="th-group th-emp" style="text-align:left;">Employee</th>
                    <th colspan="2" class="th-group th-att" style="text-align:center;"><i class="fa-solid fa-calendar-day"></i> Attendance</th>
                    <th colspan="2" class="th-group th-att" style="text-align:center;border-right:2px solid #1a3a6e;"><i class="fa-solid fa-coins"></i> Allowances</th>
                    <th colspan="2" class="th-group th-earn" style="text-align:center;border-right:2px solid #1a5c30;"><i class="fa-solid fa-arrow-trend-up"></i> Earnings</th>
                    <th colspan="2" class="th-group th-ded" style="text-align:center;border-right:2px solid #5c1a1a;"><i class="fa-solid fa-circle-minus"></i> Deductions</th>
                    <th colspan="1" class="th-group th-net" style="text-align:center;border-right:2px solid #1a5c30;"><i class="fa-solid fa-wallet"></i> Net</th>
                    <th colspan="1" class="th-group th-ben" style="text-align:center;border-right:2px solid #1a3a6e;"><i class="fa-solid fa-star"></i> Benefit</th>
                    <th colspan="2" class="th-group th-bp" style="text-align:center;border-right:2px solid #6b3a00;"><i class="fa-solid fa-coins"></i> Provisions</th>
                    <th colspan="1" class="th-group th-loan" style="text-align:center;border-right:2px solid #3d0f6b;"><i class="fa-solid fa-hand-holding-dollar"></i> Active Loans</th>
                    <th colspan="1" class="th-group th-emp" style="width:44px;"></th>
                </tr>
                <tr>
                    <th class="col-s1 th-emp" style="text-align:left;">Employee Code</th>
                    <th class="col-s2 th-emp" style="text-align:left;">EPF No.</th>
                    <th class="col-s3 th-emp" style="text-align:left;">Date of Join</th>
                    <th class="col-s4 th-emp" style="text-align:left;">Name &amp; Status</th>
                    <th class="col-s5 th-emp" style="text-align:left;">Category &amp; Designation</th>
                    <th class="th-att">Norm Days</th>
                    <th class="th-att">Att Days</th>
                    <th class="th-att">Att. Allowance</th>
                    <th class="th-att" style="border-right:2px solid #1a3a6e;">No Pay Amount</th>
                    <th class="th-oinc">Other Incentive</th>
                    <th class="th-earn" style="border-right:2px solid #1a5c30;">Total Earnings</th>
                    <th class="th-ded">Loan Deduction</th>
                    <th class="th-ded" style="border-right:2px solid #5c1a1a;">Total Deductions</th>
                    <th class="th-net" style="border-right:2px solid #1a5c30;">Net Salary</th>
                    <th class="th-ben" style="border-right:2px solid #1a3a6e;">Total Benefit</th>
                    <th class="th-bp">Cost of BP</th>
                    <th class="th-bp" style="border-right:2px solid #6b3a00;">Provisions</th>
                    <th class="th-loan" style="border-right:2px solid #3d0f6b;">Loans / Balance</th>
                    <th class="th-emp" style="width:44px;"></th>
                </tr>
            </thead>
            <tbody id="salaryTableBody">
            <?php
            $gt_earn=$gt_ded=$gt_net=$gt_ben=$gt_bp=$gt_prov=$gt_att_allow=$gt_no_pay=$gt_loan_ded=$gt_oinc=0;
            foreach ($employees as $emp):
                $eid  = (int)$emp['id'];
                $r    = $rows[$eid];
                $att  = $r['att'];
                $sc   = ['Probation'=>'s-probation','Permanent'=>'s-permanent','Resigned'=>'s-resigned','Terminated'=>'s-terminated'][$emp['status']] ?? '';
                $att_cls = $att['rate'] >= 90 ? 'att-high' : ($att['rate'] >= 70 ? 'att-mid' : ($att['rate'] > 0 ? 'att-low' : 'att-zero'));
                $fv = fn($v) => $v > 0 ? number_format($v,2) : '<span class="money-zero">—</span>';
                $gt_earn     += $r['total_earnings']; $gt_ded  += $r['total_deductions'];
                $gt_net      += $r['net_salary'];     $gt_ben  += $r['total_benefit'];
                $gt_bp       += $r['cost_bp'];        $gt_prov += $r['provisions'];
                $gt_att_allow+= $r['att_allow'];      $gt_no_pay += $r['no_pay_amount'];
                $gt_loan_ded += $r['loan_ded'];        $gt_oinc += $r['other_incentive'];

                $emp_loan_count = count($r['emp_loans']);
                $loan_total_balance = array_sum(array_column($r['emp_loans'], 'balance'));
            ?>
            <tr class="emp-row" id="row_<?php echo $eid; ?>"
                onclick="openModal(<?php echo $eid; ?>)"
                data-search="<?php echo strtolower(htmlspecialchars(($emp['employee_id']??'').' '.($emp['employee_full_name']??'').' '.($emp['epf_number']??'').' '.($emp['designation_name']??''))); ?>"
                data-eid="<?php echo $eid; ?>"
                style="cursor:pointer;">

                <td class="col-s1 td-left">
                    <div class="emp-id-tag"><?php echo htmlspecialchars($emp['employee_id']); ?></div>
                    <div class="emp-meta" style="margin-top:2px;"><?php echo htmlspecialchars(($emp['company_code']??'').($emp['branch_code']?' · '.$emp['branch_code']:'')); ?></div>
                </td>
                <td class="col-s2 td-left">
                    <span style="font-family:var(--mono);font-size:11px;color:var(--ink2);"><?php echo $emp['epf_number'] ? htmlspecialchars($emp['epf_number']) : '<span class="money-zero">—</span>'; ?></span>
                </td>
                <td class="col-s3 td-left">
                    <span style="font-family:var(--mono);font-size:10px;color:var(--ink2);"><?php echo $emp['date_of_join'] ? date('d M Y', strtotime($emp['date_of_join'])) : '—'; ?></span>
                </td>
                <td class="col-s4 td-left">
                    <div class="emp-name-cell"><?php echo htmlspecialchars($emp['employee_full_name']); ?></div>
                    <span class="s-badge <?php echo $sc; ?>" style="margin-top:2px;"><?php echo $emp['status']; ?></span>
                </td>
                <td class="col-s5 td-left">
                    <?php if ($emp['category_code']): ?><span class="cat-chip"><?php echo htmlspecialchars($emp['category_code']); ?></span><?php endif; ?>
                    <div class="emp-meta" style="margin-top:3px;"><?php echo htmlspecialchars($emp['designation_name'] ?? ''); ?></div>
                </td>

                <td class="td-att td-center"><span style="font-family:var(--mono);font-size:12px;font-weight:700;"><?php echo $att['norm']; ?></span></td>
                <td class="td-att td-center">
                    <span class="att-pill <?php echo $att_cls; ?>"><?php echo $att['att']; ?> <span style="opacity:.6;">(<?php echo $att['rate']; ?>%)</span></span>
                </td>
                <td class="td-att"><span class="money-att"><?php echo $fv($r['att_allow']); ?></span></td>
                <td class="td-att" style="border-right:2px solid #e5e7eb;">
                    <?php if ($r['no_pay_amount'] > 0): ?>
                    <span style="font-family:var(--mono);font-size:11.5px;font-weight:600;color:#991b1b;"><?php echo number_format($r['no_pay_amount'],2); ?></span>
                    <span style="font-size:9px;color:#991b1b;display:block;"><?php echo number_format($r['no_pay_days'],1); ?> days</span>
                    <?php else: ?>
                    <span style="color:#22c55e;font-size:13px;">✓</span>
                    <?php endif; ?>
                </td>

                <td class="td-oinc">
                    <?php if ($r['other_incentive'] > 0): ?>
                    <span class="money-oinc" id="oinc_<?php echo $eid; ?>"><?php echo number_format($r['other_incentive'],2); ?></span>
                    <?php else: ?>
                    <span class="money-zero">—</span>
                    <?php endif; ?>
                </td>
                <td class="td-earn" style="border-right:2px solid #15803d;">
                    <span class="money-net" id="earn_<?php echo $eid; ?>"><?php echo $fv($r['total_earnings']); ?></span>
                </td>
                <td class="td-loan">
                    <?php if ($r['loan_ded'] > 0): ?>
                    <span class="money-loan" id="lded_<?php echo $eid; ?>"><?php echo number_format($r['loan_ded'],2); ?></span>
                    <span class="src-badge src-loan"><?php echo $emp_loan_count; ?> loan<?php echo $emp_loan_count>1?'s':''; ?></span>
                    <?php else: ?>
                    <span class="money-zero">—</span>
                    <?php endif; ?>
                </td>
                <td class="td-ded" style="border-right:2px solid #991b1b;">
                    <span class="money-ded" id="ded_<?php echo $eid; ?>"><?php echo $fv($r['total_deductions']); ?></span>
                </td>
                <td class="td-net" style="border-right:2px solid #15803d;">
                    <span class="money-net" style="font-size:13.5px;" id="net_<?php echo $eid; ?>"><?php echo $fv($r['net_salary']); ?></span>
                </td>
                <td class="td-ben" style="border-right:2px solid #1e3a8a;">
                    <span class="money-ben" id="ben_<?php echo $eid; ?>"><?php echo $fv($r['total_benefit']); ?></span>
                </td>
                <td class="td-bp">
                    <span class="money-bp" id="cbp_<?php echo $eid; ?>"><?php echo $fv($r['cost_bp']); ?></span>
                </td>
                <td class="td-bp" style="border-right:2px solid #b45309;">
                    <span class="money-bp"><?php echo $fv($r['provisions']); ?></span>
                </td>
                <td class="td-loan" style="border-right:2px solid #6b21a8;">
                    <?php if ($emp_loan_count > 0): ?>
                    <span class="loan-count-badge"><i class="fa-solid fa-hand-holding-dollar"></i> <?php echo $emp_loan_count; ?></span>
                    <div style="font-family:var(--mono);font-size:10px;color:#7c3aed;margin-top:3px;">Bal: <?php echo number_format($loan_total_balance,2); ?></div>
                    <?php else: ?>
                    <span class="loan-count-badge loan-count-zero">—</span>
                    <?php endif; ?>
                </td>

                <td class="td-center" onclick="event.stopPropagation()">
                    <button class="row-view-btn" onclick="openModal(<?php echo $eid; ?>)" title="View full breakdown">
                        <i class="fa-solid fa-expand"></i>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>

            <!-- GRAND TOTAL -->
            <tr class="tr-total">
                <td class="col-s1 td-left" colspan="5" style="color:#e5e7eb;">
                    <span style="font-family:var(--display);font-size:12px;font-weight:800;letter-spacing:.3px;">
                        <i class="fa-solid fa-sigma" style="margin-right:6px;"></i>GRAND TOTAL
                    </span>
                    <span style="font-size:10px;color:#9ca3af;margin-left:8px;"><?php echo count($employees); ?> employees</span>
                </td>
                <td></td><td></td>
                <td style="color:#93c5fd;font-family:var(--mono);font-size:11px;text-align:right;">LKR <?php echo number_format($gt_att_allow,2); ?></td>
                <td style="color:#fca5a5;font-family:var(--mono);font-size:11px;text-align:right;border-right:2px solid #3f3f46;">LKR <?php echo number_format($gt_no_pay,2); ?></td>
                <td style="color:#f9a8d4;font-family:var(--mono);font-size:11px;font-weight:700;text-align:right;">LKR <?php echo number_format($gt_oinc,2); ?></td>
                <td style="color:#86efac;font-family:var(--mono);font-size:12px;font-weight:800;text-align:right;border-right:2px solid #1a5c30;">LKR <?php echo number_format($gt_earn,2); ?></td>
                <td style="color:#d8b4fe;font-family:var(--mono);font-size:12px;font-weight:700;text-align:right;">LKR <?php echo number_format($gt_loan_ded,2); ?></td>
                <td style="color:#fca5a5;font-family:var(--mono);font-size:12px;font-weight:800;text-align:right;border-right:2px solid #5c1a1a;">LKR <?php echo number_format($gt_ded,2); ?></td>
                <td style="color:#6ee7b7;font-family:var(--mono);font-size:13px;font-weight:800;text-align:right;border-right:2px solid #1a5c30;">LKR <?php echo number_format($gt_net,2); ?></td>
                <td style="color:#93c5fd;font-family:var(--mono);font-size:12px;font-weight:800;text-align:right;border-right:2px solid #1a3a6e;">LKR <?php echo number_format($gt_ben,2); ?></td>
                <td style="color:#fbbf24;font-family:var(--mono);font-size:11px;font-weight:700;text-align:right;">LKR <?php echo number_format($gt_bp,2); ?></td>
                <td style="color:#fbbf24;font-family:var(--mono);font-size:11px;font-weight:700;text-align:right;border-right:2px solid #6b3a00;">LKR <?php echo number_format($gt_prov,2); ?></td>
                <td></td>
                <td></td>
            </tr>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- FULL DETAIL MODAL -->
<div class="modal-bg" id="detailModal" onclick="if(event.target===this)closeModal()">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-emp-info">
                <div class="modal-emp-name" id="m-name">—</div>
                <div class="modal-emp-meta">
                    <span><i class="fa-solid fa-id-badge" style="color:#3b82f6;"></i> <span id="m-code">—</span></span>
                    <span><i class="fa-solid fa-briefcase" style="color:#8b5cf6;"></i> <span id="m-desig">—</span></span>
                    <span id="m-status-wrap"></span>
                    <span><i class="fa-solid fa-calendar" style="color:#6b7280;"></i> <span id="m-join">—</span></span>
                    <span><i class="fa-solid fa-hashtag" style="color:#059669;"></i> EPF <span id="m-epf">—</span></span>
                </div>
            </div>
            <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="modalBody"></div>
        <!-- UPDATE BAR (only shown for pending saved sheet) -->
        <?php if ($has_saved_sheet && !$is_confirmed): ?>
        <div class="modal-update-bar">
            <div class="modal-update-info">
                <i class="fa-solid fa-pen-to-square"></i>
                Edit Excess, Arrears &amp; add custom rows, then click Update.
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
                <span class="modal-update-status" id="modalUpdateStatus"></span>
                <button class="btn btn-purple btn-sm" id="modalUpdateBtn" onclick="saveModalOverrides()">
                    <i class="fa-solid fa-floppy-disk"></i> Update &amp; Save
                </button>
            </div>
        </div>
        <?php endif; ?>
        <div class="modal-footer">
            <div class="modal-footer-links" id="m-links"></div>
            <button class="btn btn-ghost btn-sm" onclick="closeModal()"><i class="fa-solid fa-xmark"></i> Close</button>
        </div>
    </div>
</div>

<!-- CONFIRM DELETE MODAL -->
<div class="confirm-bg" id="confirmModal">
    <div class="confirm-box">
        <h3><i class="fa-solid fa-trash" style="color:#dc2626;margin-right:8px;"></i>Delete Salary Sheet?</h3>
        <p>This will permanently delete the saved salary sheet for <strong><?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : 'this period'; ?></strong>. All saved values including edits will be lost.</p>
        <div class="confirm-actions">
            <button class="btn btn-ghost btn-sm" onclick="closeConfirm()">Cancel</button>
            <button class="btn btn-red btn-sm" onclick="deleteSheet()"><i class="fa-solid fa-trash"></i> Yes, Delete</button>
        </div>
    </div>
</div>

<!-- CONFIRM REFRESH MODAL -->
<div class="confirm-bg" id="confirmRefreshModal">
    <div class="confirm-box">
        <h3><i class="fa-solid fa-arrows-rotate" style="color:#d97706;margin-right:8px;"></i>Refresh from Live Data?</h3>
        <p>This will <strong>overwrite</strong> the saved salary sheet with the latest live data. All previously saved edits (excess/arrears overrides, additional rows) will be lost.</p>
        <div class="confirm-actions">
            <button class="btn btn-ghost btn-sm" onclick="closeRefreshConfirm()">Cancel</button>
            <button class="btn btn-amber btn-sm" onclick="doRefreshSheet()"><i class="fa-solid fa-arrows-rotate"></i> Yes, Refresh</button>
        </div>
    </div>
</div>

<!-- CONFIRM SALARY SHEET MODAL -->
<div class="confirm-bg" id="confirmSheetModal">
    <div class="confirm-box" style="max-width:520px;">
        <h3><i class="fa-solid fa-check-double" style="color:#4338ca;margin-right:8px;"></i>Confirm Salary Sheet?</h3>
        <p>
            This will <strong>lock</strong> the salary sheet for
            <strong><?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : 'this period'; ?></strong>
            and <strong>record loan payment entries</strong> for all employees with active loans.
        </p>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:12.5px;color:#1e40af;">
            <i class="fa-solid fa-circle-info" style="margin-right:6px;"></i>
            <strong>What happens on confirmation:</strong>
            <ul style="margin:8px 0 0 18px;line-height:1.9;">
                <li>Sheet status changes to <strong>Confirmed</strong> (locked, no further edits)</li>
                <li>Each active loan instalment is recorded in <code>loan_payments</code></li>
                <li>Loans that are fully paid will be marked <strong>Completed</strong></li>
                <li>This action <strong>cannot be undone</strong></li>
            </ul>
        </div>
        <div id="confirmSheetResult"></div>
        <div class="confirm-actions" id="confirmSheetActions">
            <button class="btn btn-ghost btn-sm" onclick="closeConfirmSheet()">Cancel</button>
            <button class="btn btn-indigo btn-sm" id="confirmSheetBtn" onclick="doConfirmSheet()">
                <i class="fa-solid fa-check-double"></i> Yes, Confirm &amp; Record Payments
            </button>
        </div>
    </div>
</div>

<!-- JS DATA -->
<script>
const HAS_SAVED_SHEET = <?php echo $has_saved_sheet ? 'true' : 'false'; ?>;
const IS_CONFIRMED    = <?php echo $is_confirmed    ? 'true' : 'false'; ?>;
const PERIOD_ID       = <?php echo (int)$sel_period_id; ?>;

const SR_DATA = <?php
$js_data = [];
foreach ($employees as $emp) {
    $eid = (int)$emp['id'];
    $r   = $rows[$eid];
    $att = $r['att'];
    $js_data[$eid] = [
        'employee_db_id'  => $eid,
        'name'            => $emp['employee_full_name'],
        'code'            => $emp['employee_id'],
        'desig'           => $emp['designation_name'] ?? '',
        'cat'             => $emp['category_name'] ?? '',
        'cat_code'        => $emp['category_code'] ?? '',
        'status'          => $emp['status'],
        'join'            => $emp['date_of_join'] ? date('d M Y', strtotime($emp['date_of_join'])) : '—',
        'join_raw'        => $emp['date_of_join'] ?? '',
        'epf'             => $emp['epf_number'] ?? '—',
        'company_code'    => $emp['company_code'] ?? '',
        'branch_code'     => $emp['branch_code'] ?? '',
        'company'         => ($emp['company_code']??'').($emp['branch_code']?' · '.$emp['branch_code']:''),

        'att_count'    => $att['att'],
        'norm_days'    => $att['norm'],
        'holidays'     => $att['holidays'],
        'att_rate'     => $att['rate'],
        'per_day'      => $r['per_day'],
        'no_pay_days'  => $r['no_pay_days'],
        'no_pay_amount'=> $r['no_pay_amount'],
        'att_allow'    => $r['att_allow'],

        'basic'      => $r['basic'],
        'disc'       => $r['disc'],
        'ssv'        => $r['ssv'],
        'ps_amt'     => $r['ps_amt'],
        'eco_amt'    => $r['eco_amt'],
        'bp_amt'     => $r['bp_amt'],
        'assort_amt' => $r['assort_amt'],
        'other_incentive' => $r['other_incentive'],
        'meal'       => $r['meal'],
        'travel'     => $r['travel'],
        'fuel'       => $r['fuel'],
        'fuel_from_entry' => $r['fuel_from_entry'],
        'mobile'     => $r['mobile'],
        'arrears'    => $r['arrears'],
        'total_earnings' => $r['total_earnings'],

        'epf_emp'    => $r['epf_emp'],
        'welfare_amt'=> $r['welfare_amt'],
        'w_loan'     => $r['w_loan'],
        'w_soc'      => $r['w_soc'],
        'donations'  => $r['donations'],
        'sal_adv'    => $r['sal_adv'],
        'advance_from_entry' => $r['advance_from_entry'],
        'loan_ded'   => $r['loan_ded'],
        'credit_r'   => $r['credit_r'],
        'retention'  => $r['retention'],
        'excess_p'   => $r['excess_p'],
        'total_deductions' => $r['total_deductions'],

        'net_salary'    => $r['net_salary'],
        'epf_er'        => $r['epf_er'],
        'etf_er'        => $r['etf_er'],
        'bonus'         => $r['bonus'],
        'gratuity'      => $r['gratuity'],
        'insurance'     => $r['insurance'],
        'total_benefit' => $r['total_benefit'],
        'cost_bp'       => $r['cost_bp'],
        'provisions'    => $r['provisions'],

        'additional_earnings'   => $r['add_earn_saved']   ?: [],
        'additional_deductions' => $r['add_ded_saved']    ?: [],

        // Loan data
        'emp_loans'  => $r['emp_loans'],
    ];
}
echo json_encode($js_data);
?>;

// ── Current modal employee id ──────────────────────────────────────────────────
let _currentModalEid = null;

// ── Helpers ───────────────────────────────────────────────────────────────────
function lkr(v) {
    v = parseFloat(v||0);
    if (v <= 0) return '<span style="color:#d1d5db;">—</span>';
    return '<span><span style="font-size:9px;opacity:.6;margin-right:1px;">LKR</span>' + v.toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2}) + '</span>';
}
function lkrPlain(v) {
    return 'LKR ' + parseFloat(v||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
}
function fmt(v) { return parseFloat(v||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function srcBadge(fromEntry) {
    return fromEntry
        ? '<span class="src-badge src-live">Fuel Entry</span>'
        : '<span class="src-badge src-emp">Profile</span>';
}
function advBadge(fromEntry) {
    return fromEntry
        ? '<span class="src-badge src-live">Period</span>'
        : '<span class="src-badge src-emp">Profile</span>';
}

// ── CREATE SALARY SHEET ───────────────────────────────────────────────────────
function createSalarySheet() {
    const rows = Object.values(SR_DATA);
    document.getElementById('saveRowsJson').value = JSON.stringify(rows);
    document.getElementById('saveSheetForm').submit();
}

// ── REFRESH SHEET ─────────────────────────────────────────────────────────────
function refreshSheet() {
    document.getElementById('confirmRefreshModal').classList.add('show');
}
function closeRefreshConfirm() {
    document.getElementById('confirmRefreshModal').classList.remove('show');
}
function doRefreshSheet() {
    closeRefreshConfirm();
    fetch('sr_salary.php?ajax=delete_sheet&period_id=' + PERIOD_ID)
        .then(() => {
            const rows = Object.values(SR_DATA);
            document.getElementById('saveRowsJson').value = JSON.stringify(rows);
            document.getElementById('saveSheetForm').submit();
        });
}

// ── DELETE SHEET ──────────────────────────────────────────────────────────────
function confirmDeleteSheet() {
    document.getElementById('confirmModal').classList.add('show');
}
function closeConfirm() {
    document.getElementById('confirmModal').classList.remove('show');
}
function deleteSheet() {
    closeConfirm();
    fetch('sr_salary.php?ajax=delete_sheet&period_id=' + PERIOD_ID)
        .then(r => r.json())
        .then(d => {
            if (d.ok) window.location.href = 'sr_salary.php?period_id=' + PERIOD_ID;
            else alert(d.msg || 'Cannot delete.');
        });
}

// ── CONFIRM SALARY SHEET ──────────────────────────────────────────────────────
function confirmSheetModal() {
    document.getElementById('confirmSheetResult').innerHTML = '';
    document.getElementById('confirmSheetActions').style.display = 'flex';
    document.getElementById('confirmSheetBtn').disabled = false;
    document.getElementById('confirmSheetBtn').innerHTML = '<i class="fa-solid fa-check-double"></i> Yes, Confirm &amp; Record Payments';
    document.getElementById('confirmSheetModal').classList.add('show');
}
function closeConfirmSheet() {
    document.getElementById('confirmSheetModal').classList.remove('show');
}
function doConfirmSheet() {
    const btn = document.getElementById('confirmSheetBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';

    fetch('sr_salary.php?ajax=confirm_sheet&period_id=' + PERIOD_ID)
        .then(r => r.json())
        .then(d => {
            const res = document.getElementById('confirmSheetResult');
            if (d.ok) {
                res.innerHTML = `<div class="confirm-result-banner crb-success">
                    <i class="fa-solid fa-circle-check"></i>
                    <strong>Sheet confirmed!</strong> ${d.payments_created} loan payment${d.payments_created !== 1 ? 's' : ''} recorded.
                    ${d.errors && d.errors.length ? '<br><small>Some errors: ' + d.errors.join(', ') + '</small>' : ''}
                </div>`;
                document.getElementById('confirmSheetActions').innerHTML =
                    '<button class="btn btn-primary btn-sm" onclick="window.location.reload()"><i class="fa-solid fa-rotate-right"></i> Reload Page</button>';
            } else {
                res.innerHTML = `<div class="confirm-result-banner crb-error"><i class="fa-solid fa-circle-exclamation"></i> ${d.msg || 'Error confirming sheet.'}</div>`;
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check-double"></i> Yes, Confirm &amp; Record Payments';
            }
        })
        .catch(() => {
            document.getElementById('confirmSheetResult').innerHTML =
                '<div class="confirm-result-banner crb-error"><i class="fa-solid fa-circle-exclamation"></i> Network error. Please try again.</div>';
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-check-double"></i> Yes, Confirm &amp; Record Payments';
        });
}

// ── ADDITIONAL ROWS BUILDER ───────────────────────────────────────────────────
let _addEarnRows = [];
let _addDedRows  = [];

function addEarnRow(desc, amount) {
    _addEarnRows.push({ desc: desc || '', amount: parseFloat(amount || 0) });
    renderAddRows();
}
function addDedRow(desc, amount) {
    _addDedRows.push({ desc: desc || '', amount: parseFloat(amount || 0) });
    renderAddRows();
}
function removeEarnRow(idx) {
    _addEarnRows.splice(idx, 1);
    renderAddRows();
}
function removeDedRow(idx) {
    _addDedRows.splice(idx, 1);
    renderAddRows();
}

function renderAddRows() {
    const earnContainer = document.getElementById('addEarnRows');
    const earnTotal     = document.getElementById('addEarnTotal');
    if (!earnContainer) return;
    let earnSum = 0;
    earnContainer.innerHTML = '';
    _addEarnRows.forEach((row, i) => {
        earnSum += parseFloat(row.amount || 0);
        const div = document.createElement('div');
        div.className = 'add-row-item';
        div.innerHTML = `
            <input type="text" class="add-row-desc" placeholder="Description (e.g. Special Bonus)" value="${escHtml(row.desc)}"
                   oninput="_addEarnRows[${i}].desc=this.value">
            <input type="number" step="0.01" min="0" class="add-row-amount" placeholder="0.00" value="${row.amount > 0 ? row.amount.toFixed(2) : ''}"
                   oninput="_addEarnRows[${i}].amount=parseFloat(this.value)||0;updateAddTotals()">
            <button class="add-row-remove" onclick="removeEarnRow(${i})" title="Remove"><i class="fa-solid fa-xmark"></i></button>`;
        earnContainer.appendChild(div);
    });
    if (earnTotal) earnTotal.textContent = 'Total: LKR ' + earnSum.toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});

    const dedContainer = document.getElementById('addDedRows');
    const dedTotal     = document.getElementById('addDedTotal');
    if (!dedContainer) return;
    let dedSum = 0;
    dedContainer.innerHTML = '';
    _addDedRows.forEach((row, i) => {
        dedSum += parseFloat(row.amount || 0);
        const div = document.createElement('div');
        div.className = 'add-row-item';
        div.innerHTML = `
            <input type="text" class="add-row-desc" placeholder="Description (e.g. Salary Overpayment)" value="${escHtml(row.desc)}"
                   oninput="_addDedRows[${i}].desc=this.value">
            <input type="number" step="0.01" min="0" class="add-row-amount is-ded" placeholder="0.00" value="${row.amount > 0 ? row.amount.toFixed(2) : ''}"
                   oninput="_addDedRows[${i}].amount=parseFloat(this.value)||0;updateAddTotals()">
            <button class="add-row-remove" onclick="removeDedRow(${i})" title="Remove"><i class="fa-solid fa-xmark"></i></button>`;
        dedContainer.appendChild(div);
    });
    if (dedTotal) dedTotal.textContent = 'Total: LKR ' + dedSum.toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
}

function updateAddTotals() {
    const earnTotal = document.getElementById('addEarnTotal');
    const dedTotal  = document.getElementById('addDedTotal');
    let es = _addEarnRows.reduce((s, r) => s + parseFloat(r.amount||0), 0);
    let ds = _addDedRows.reduce((s, r) => s + parseFloat(r.amount||0), 0);
    if (earnTotal) earnTotal.textContent = 'Total: LKR ' + es.toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
    if (dedTotal)  dedTotal.textContent  = 'Total: LKR ' + ds.toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── SAVE MODAL OVERRIDES ──────────────────────────────────────────────────────
function saveModalOverrides() {
    const eid = _currentModalEid;
    if (!eid) return;

    const excessEl  = document.getElementById('m_excess');
    const arrearsEl = document.getElementById('m_arrears');
    if (!excessEl || !arrearsEl) return;

    const excess  = parseFloat(excessEl.value  || 0);
    const arrears = parseFloat(arrearsEl.value || 0);

    const addEarn = _addEarnRows.filter(r => r.desc.trim() !== '' || r.amount > 0)
                                .map(r => ({desc: r.desc.trim(), amount: parseFloat(r.amount||0)}));
    const addDed  = _addDedRows.filter(r => r.desc.trim() !== '' || r.amount > 0)
                               .map(r => ({desc: r.desc.trim(), amount: parseFloat(r.amount||0)}));

    const btn    = document.getElementById('modalUpdateBtn');
    const status = document.getElementById('modalUpdateStatus');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…'; }
    if (status) status.innerHTML = '<span class="upd-saving"><i class="fa-solid fa-circle-notch fa-spin"></i> Saving…</span>';

    fetch('sr_salary.php?ajax=update_overrides', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
            period_id: PERIOD_ID,
            employee_id: eid,
            excess, arrears,
            additional_earnings: addEarn,
            additional_deductions: addDed
        })
    })
    .then(r => r.json())
    .then(d => {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Update &amp; Save'; }
        if (d.ok) {
            if (status) status.innerHTML = '<span class="upd-saved"><i class="fa-solid fa-circle-check"></i> Saved!</span>';
            setTimeout(() => { if(status) status.innerHTML = ''; }, 3000);

            const fmtLkr = v => '<span><span style="font-size:9px;opacity:.6;margin-right:1px;">LKR</span>' + fmt(v) + '</span>';
            const earnEl = document.getElementById('earn_' + eid);
            const dedEl  = document.getElementById('ded_'  + eid);
            const netEl  = document.getElementById('net_'  + eid);
            const benEl  = document.getElementById('ben_'  + eid);
            const cbpEl  = document.getElementById('cbp_'  + eid);
            if (earnEl) earnEl.innerHTML = fmtLkr(d.total_earnings);
            if (dedEl)  dedEl.innerHTML  = fmtLkr(d.total_deductions);
            if (netEl)  netEl.innerHTML  = fmtLkr(d.net_salary);
            if (benEl)  benEl.innerHTML  = fmtLkr(d.total_benefit);
            if (cbpEl)  cbpEl.innerHTML  = fmtLkr(d.cost_bp);

            const nhEarn = document.getElementById('nh_earn');
            const nhDed  = document.getElementById('nh_ded');
            const nhNet  = document.getElementById('nh_net');
            const nhBen  = document.getElementById('nh_ben');
            if (nhEarn) nhEarn.textContent = lkrPlain(d.total_earnings);
            if (nhDed)  nhDed.textContent  = lkrPlain(d.total_deductions);
            if (nhNet)  nhNet.textContent  = lkrPlain(d.net_salary);
            if (nhBen)  nhBen.textContent  = lkrPlain(d.total_benefit);

            if (SR_DATA[eid]) {
                SR_DATA[eid].excess_p              = excess;
                SR_DATA[eid].arrears               = arrears;
                SR_DATA[eid].total_earnings        = d.total_earnings;
                SR_DATA[eid].total_deductions      = d.total_deductions;
                SR_DATA[eid].net_salary            = d.net_salary;
                SR_DATA[eid].total_benefit         = d.total_benefit;
                SR_DATA[eid].cost_bp               = d.cost_bp;
                SR_DATA[eid].additional_earnings   = addEarn;
                SR_DATA[eid].additional_deductions = addDed;
            }
        } else {
            if (status) status.innerHTML = '<span class="upd-error"><i class="fa-solid fa-circle-exclamation"></i> ' + (d.msg||'Error saving') + '</span>';
        }
    })
    .catch(() => {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Update &amp; Save'; }
        if (status) status.innerHTML = '<span class="upd-error"><i class="fa-solid fa-circle-exclamation"></i> Network error</span>';
    });
}

// ── MODAL ─────────────────────────────────────────────────────────────────────
function openModal(eid) {
    const d = SR_DATA[eid];
    if (!d) return;
    _currentModalEid = eid;

    _addEarnRows = (d.additional_earnings  || []).map(r => ({desc: r.desc||'', amount: parseFloat(r.amount||0)}));
    _addDedRows  = (d.additional_deductions || []).map(r => ({desc: r.desc||'', amount: parseFloat(r.amount||0)}));

    document.getElementById('m-name').textContent  = d.name;
    document.getElementById('m-code').textContent  = d.code;
    document.getElementById('m-desig').textContent = (d.cat_code ? d.cat_code+' · ' : '') + d.desig;
    document.getElementById('m-join').textContent  = d.join;
    document.getElementById('m-epf').textContent   = d.epf;
    const statusColors = {
        'Probation': 'background:#fef3c7;color:#92400e',
        'Permanent': 'background:#dcfce7;color:#15803d',
        'Resigned':  'background:#f3f4f6;color:#6b7280',
        'Terminated':'background:#fee2e2;color:#991b1b'
    };
    document.getElementById('m-status-wrap').innerHTML =
        '<span style="display:inline-block;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700;' +
        (statusColors[d.status]||'background:#f3f4f6;color:#888') + '">' + d.status + '</span>';

    document.getElementById('m-links').innerHTML =
        '<a href="view_employee.php?id='+eid+'" class="btn btn-ghost btn-sm"><i class="fa-solid fa-user"></i> Profile</a>' +
        '<a href="view_employee.php?id='+eid+'&tab=salary" class="btn btn-ghost btn-sm"><i class="fa-solid fa-coins"></i> Salary Details</a>' +
        '<a href="sr_fuel_entry.php?period_id='+PERIOD_ID+'" class="btn btn-ghost btn-sm"><i class="fa-solid fa-gas-pump"></i> Fuel Entry</a>' +
        '<a href="salary_advance.php?employee_id='+eid+'&payroll_period_id='+PERIOD_ID+'" class="btn btn-ghost btn-sm"><i class="fa-solid fa-hand-holding-dollar"></i> Advances</a>' +
        '<a href="loans.php?employee_id='+eid+'" class="btn btn-purple btn-sm"><i class="fa-solid fa-file-invoice-dollar"></i> Loans</a>' +
        '<a href="incentive_entry.php?type_id=1&period_id='+PERIOD_ID+'" class="btn btn-blue btn-sm"><i class="fa-solid fa-percent"></i> Incentive Entry</a>';

    let html = '';

    // NET BAND
    html += '<div class="net-hero-band">';
    html += '<div class="net-hero-item"><div class="net-hero-label">Total Earnings</div><div class="net-hero-value v-earn" id="nh_earn">' + lkrPlain(d.total_earnings) + '</div></div>';
    html += '<div class="net-hero-divider"></div>';
    html += '<div class="net-hero-item"><div class="net-hero-label">Total Deductions</div><div class="net-hero-value v-ded" id="nh_ded">' + lkrPlain(d.total_deductions) + '</div></div>';
    html += '<div class="net-hero-divider"></div>';
    html += '<div class="net-hero-item"><div class="net-hero-label">✦ Net Salary</div><div class="net-hero-value v-net" id="nh_net">' + lkrPlain(d.net_salary) + '</div></div>';
    html += '<div class="net-hero-divider"></div>';
    html += '<div class="net-hero-item"><div class="net-hero-label">Total Benefit</div><div class="net-hero-value v-ben" id="nh_ben">' + lkrPlain(d.total_benefit) + '</div></div>';
    html += '<div class="net-hero-divider"></div>';
    html += '<div class="net-hero-item"><div class="net-hero-label">Provisions (B+G)</div><div class="net-hero-value v-bp">' + lkrPlain(d.bonus + d.gratuity) + '</div></div>';
    html += '</div>';

    // ATTENDANCE BAND
    const rate = parseFloat(d.att_rate);
    const rateColor = rate >= 90 ? '#dcfce7;color:#14532d' : rate >= 70 ? '#fef3c7;color:#78350f' : '#fee2e2;color:#7f1d1d';
    html += '<div class="att-info-band">';
    html += '<div class="att-info-card"><div class="att-info-label">Norm Days</div><div class="att-info-value">' + d.norm_days + '</div><div class="att-info-sub">Month − ' + d.holidays + ' holidays</div></div>';
    html += '<div class="att-info-card"><div class="att-info-label">Attended</div><div class="att-info-value">' + d.att_count + '</div><div class="att-info-sub">days present</div></div>';
    html += '<div class="att-info-card"><div class="att-info-label">Att. Rate</div><div class="att-info-value" style="background:' + rateColor + ';padding:4px 8px;border-radius:6px;">' + d.att_rate + '%</div><div class="att-info-sub">att ÷ norm × 100</div></div>';
    html += '<div class="att-info-card"><div class="att-info-label">Per Day Rate</div><div class="att-info-value" style="font-size:14px;">' + (d.per_day > 0 ? 'LKR '+parseFloat(d.per_day).toFixed(2) : '—') + '</div><div class="att-info-sub">basic ÷ norm</div></div>';
    html += '<div class="att-info-card"><div class="att-info-label">No Pay Days</div><div class="att-info-value" style="color:' + (d.no_pay_days > 0 ? '#991b1b' : '#22c55e') + ';">' + (d.no_pay_days > 0 ? d.no_pay_days : '✓ 0') + '</div><div class="att-info-sub">unpaid absence</div></div>';
    html += '<div class="att-info-card"><div class="att-info-label">No Pay Deduction</div><div class="att-info-value" style="font-size:14px;color:' + (d.no_pay_amount > 0 ? '#991b1b' : '#22c55e') + ';">' + (d.no_pay_amount > 0 ? 'LKR '+parseFloat(d.no_pay_amount).toFixed(2) : '✓ None') + '</div><div class="att-info-sub">per day × no pay days</div></div>';
    html += '<div class="att-info-card"><div class="att-info-label">Att. Allowance</div><div class="att-info-value" style="font-size:14px;color:#1d4ed8;">' + (d.att_allow > 0 ? 'LKR '+parseFloat(d.att_allow).toFixed(2) : '—') + '</div><div class="att-info-sub">fixed allowance</div></div>';
    html += '</div>';

    // ── ACTIVE LOANS BAND ─────────────────────────────────────────────────────
    html += '<div class="loan-band">';
    html += '<div class="loan-band-title"><i class="fa-solid fa-hand-holding-dollar"></i> Active Loan Deductions This Period</div>';

    const loans = d.emp_loans || [];
    if (loans.length === 0) {
        html += '<div class="loan-no-loans"><i class="fa-solid fa-check-circle" style="color:#22c55e;"></i> No active loans for this employee.</div>';
    } else {
        html += '<div class="loan-cards-row">';
        loans.forEach(ln => {
            const paid_pct = ln.total_repayment > 0 ? Math.min(100, (ln.paid_so_far / ln.total_repayment * 100)) : 0;
            const balance_pct = ln.total_repayment > 0 ? Math.min(100, (ln.balance / ln.total_repayment * 100)) : 0;
            const loan_icon = ln.loan_type === 'Welfare Loan' ? '🤝' : '🏢';
            html += '<div class="loan-detail-card">';
            html += '<div class="loan-detail-card-header">';
            html += '<div class="loan-detail-card-type">' + loan_icon + ' ' + escHtml(ln.loan_type) + ' <span style="font-family:Space Mono,monospace;font-size:9px;color:#9ca3af;">#'+ln.loan_id+'</span></div>';
            html += '<span class="loan-detail-card-status ldc-approved"><i class="fa-solid fa-circle-check"></i> Approved</span>';
            html += '</div>';
            html += '<div class="loan-detail-row"><span class="loan-detail-label">Original Amount</span><span class="loan-detail-val">LKR ' + parseFloat(ln.loan_amount).toLocaleString('en-LK',{minimumFractionDigits:2}) + '</span></div>';
            html += '<div class="loan-detail-row"><span class="loan-detail-label">Total Repayment</span><span class="loan-detail-val">LKR ' + parseFloat(ln.total_repayment).toLocaleString('en-LK',{minimumFractionDigits:2}) + '</span></div>';
            html += '<div class="loan-detail-row"><span class="loan-detail-label">Paid So Far</span><span class="loan-detail-val v-paid">LKR ' + parseFloat(ln.paid_so_far).toLocaleString('en-LK',{minimumFractionDigits:2}) + '</span></div>';
            html += '<div class="loan-detail-row"><span class="loan-detail-label">Remaining Balance</span><span class="loan-detail-val v-balance">LKR ' + parseFloat(ln.balance).toLocaleString('en-LK',{minimumFractionDigits:2}) + '</span></div>';
            html += '<div class="loan-detail-row" style="background:#fff0f0;border-radius:6px;margin-top:4px;padding:6px 8px;"><span class="loan-detail-label" style="font-weight:700;color:#7c3aed;"><i class="fa-solid fa-arrow-down"></i> This Period Deduction</span><span class="loan-detail-val v-deduct" style="font-size:14px;">LKR ' + parseFloat(ln.this_deduct).toLocaleString('en-LK',{minimumFractionDigits:2}) + '</span></div>';
            html += '<div class="loan-progress-bar"><div class="loan-progress-fill" style="width:' + paid_pct.toFixed(1) + '%"></div></div>';
            html += '<div style="font-size:9px;color:#9ca3af;margin-top:3px;text-align:right;">' + paid_pct.toFixed(1) + '% repaid · ' + ln.no_of_instalments + ' instalments</div>';
            html += '</div>';
        });
        html += '</div>';

        // Total loan deduction summary
        const totalLoanDed = loans.reduce((s, ln) => s + parseFloat(ln.this_deduct), 0);
        html += '<div style="margin-top:10px;background:#ede9fe;border:1px solid #c4b5fd;border-radius:8px;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;">';
        html += '<span style="font-size:12px;font-weight:700;color:#6d28d9;"><i class="fa-solid fa-sigma"></i> Total Loan Deduction This Period</span>';
        html += '<span style="font-family:Space Mono,monospace;font-size:16px;font-weight:800;color:#7c3aed;">LKR ' + totalLoanDed.toLocaleString('en-LK',{minimumFractionDigits:2}) + '</span>';
        html += '</div>';
    }
    html += '</div>'; // end loan-band

    // ── EDITABLE OVERRIDES SECTION (only if pending saved sheet) ──────────────
    if (HAS_SAVED_SHEET && !IS_CONFIRMED) {
        html += '<div class="modal-edit-section">';
        html += '<div class="modal-edit-section-title"><i class="fa-solid fa-pen-to-square"></i> Adjustments &amp; Overrides</div>';
        html += '<div class="modal-edit-grid">';
        html += '<div class="modal-edit-field">';
        html += '<label class="modal-edit-label"><i class="fa-solid fa-circle-plus" style="margin-right:4px;"></i>Arrears Salary (Earning)</label>';
        html += '<input type="number" step="0.01" min="0" class="modal-edit-input" id="m_arrears" value="' + parseFloat(d.arrears||0).toFixed(2) + '" placeholder="0.00">';
        html += '</div>';
        html += '<div class="modal-edit-field">';
        html += '<label class="modal-edit-label"><i class="fa-solid fa-circle-minus" style="margin-right:4px;"></i>Excess Payment (Deduction)</label>';
        html += '<input type="number" step="0.01" min="0" class="modal-edit-input" id="m_excess" value="' + parseFloat(d.excess_p||0).toFixed(2) + '" placeholder="0.00">';
        html += '</div>';
        html += '</div>';
        html += '</div>';
    } else if (IS_CONFIRMED) {
        html += '<div style="padding:12px 26px;background:#eff6ff;border-top:1px solid #bfdbfe;font-size:12px;color:#1e3a8a;display:flex;align-items:center;gap:8px;">';
        html += '<i class="fa-solid fa-lock"></i> <strong>Sheet is Confirmed.</strong> Loan payments have been recorded. No further edits allowed.';
        html += '</div>';
    }

    html += '<div class="modal-sections">';

    // ── EARNINGS SECTION ──────────────────────────────────────────────────────
    html += '<div class="modal-section">';
    html += '<div class="ms-header ms-earn"><i class="fa-solid fa-arrow-trend-up"></i> Earnings Breakdown</div>';
    html += '<div class="ms-body">';
    const earns = [
        ['Basic Salary',          d.basic,      false, false],
        ['Discretionary Support', d.disc,       false, false],
        ['Secondary Sales Value', d.ssv,        false, false],
        ['PS Compliance',         d.ps_amt,     false, false],
        ['ECO Incentive',         d.eco_amt,    false, false],
        ['BP Incentive',          d.bp_amt,     false, false],
        ['Total Assortment',      d.assort_amt, false, false],
        ['Other Incentive',       d.other_incentive, false, false],
        ['Meal Reimbursement',    d.meal,       false, false],
        ['Travelling Reimb.',     d.travel,     false, false],
        ['Fuel Reimb.',           d.fuel,       true,  d.fuel_from_entry],
        ['Mobile Data Reimb.',    d.mobile,     false, false],
        ['Arrears Salary',        d.arrears,    false, false],
        ['Attendance Allowance',  d.att_allow,  false, false],
    ];
    earns.forEach(([label, val, showSrc, fromEntry]) => {
        const cls   = val <= 0 ? 'ms-zero' : (label === 'Other Incentive' ? 'ms-oinc-val' : 'ms-earn-val');
        const badge = showSrc ? srcBadge(fromEntry) : '';
        html += '<div class="ms-row ' + cls + '"><span class="ms-row-label">' + label + badge + '</span><span class="ms-row-value">' + lkr(val) + '</span></div>';
    });
    html += '</div>';

    // Additional earnings
    html += '<div style="border-top:2px dashed #a7f3d0;background:#f0fdf4;">';
    html += '<div style="padding:8px 16px 0;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#15803d;display:flex;align-items:center;gap:6px;"><i class="fa-solid fa-plus-circle"></i> Additional Earnings</div>';
    html += '<div id="addEarnRows" class="add-rows-section"></div>';
    html += '<div class="add-rows-footer">';
    if (HAS_SAVED_SHEET && !IS_CONFIRMED) {
        html += '<button class="add-row-btn" onclick="addEarnRow(\'\',0)"><i class="fa-solid fa-plus"></i> Add Row</button>';
    } else {
        html += '<span style="font-size:11px;color:#9ca3af;">' + (IS_CONFIRMED ? 'Sheet confirmed — locked' : 'Save sheet to add custom rows') + '</span>';
    }
    html += '<span class="add-row-total" id="addEarnTotal" style="color:#15803d;">Total: LKR 0.00</span>';
    html += '</div>';
    html += '</div>';
    html += '<div class="ms-total ms-total-earn"><span>Total Earnings</span><span id="nh_earn2">' + lkrPlain(d.total_earnings) + '</span></div>';
    html += '</div>';

    // ── DEDUCTIONS SECTION ────────────────────────────────────────────────────
    html += '<div class="modal-section">';
    html += '<div class="ms-header ms-ded"><i class="fa-solid fa-circle-minus"></i> Deductions Breakdown</div>';
    html += '<div class="ms-body">';
    const deds = [
        ['EPF Employee (8%)',    d.epf_emp,       false, false],
        ['Welfare',              d.welfare_amt,   false, false],
        ['Welfare Loan Ded.',    d.w_loan,        false, false],
        ['Welfare Society',      d.w_soc,         false, false],
        ['Donations',            d.donations,     false, false],
        ['Salary Advance',       d.sal_adv,       true,  d.advance_from_entry],
        ['Credit Recovery',      d.credit_r,      false, false],
        ['No Pay Deduction',     d.no_pay_amount, false, false],
        ['Retention',            d.retention,     false, false],
        ['Excess Payment',       d.excess_p,      false, false],
    ];
    deds.forEach(([label, val, showSrc, fromEntry]) => {
        const cls   = val <= 0 ? 'ms-zero' : 'ms-ded-val';
        const badge = showSrc ? advBadge(fromEntry) : '';
        html += '<div class="ms-row ' + cls + '"><span class="ms-row-label">' + label + badge + '</span><span class="ms-row-value">' + lkr(val) + '</span></div>';
    });
    // Loan deductions shown separately
    if (d.loan_ded > 0) {
        html += '<div class="ms-row ms-loan-val" style="background:#faf5ff;"><span class="ms-row-label"><i class="fa-solid fa-hand-holding-dollar" style="color:#7c3aed;margin-right:4px;"></i>Loan Deduction <span class="src-badge src-loan">Active Loans</span></span><span class="ms-row-value" style="color:#7c3aed;">LKR ' + fmt(d.loan_ded) + '</span></div>';
    }
    html += '</div>';

    // Additional deductions
    html += '<div style="border-top:2px dashed #fca5a5;background:#fff5f5;">';
    html += '<div style="padding:8px 16px 0;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#991b1b;display:flex;align-items:center;gap:6px;"><i class="fa-solid fa-plus-circle"></i> Additional Deductions</div>';
    html += '<div id="addDedRows" class="add-rows-section"></div>';
    html += '<div class="add-rows-footer" style="background:#fff5f5;">';
    if (HAS_SAVED_SHEET && !IS_CONFIRMED) {
        html += '<button class="add-row-btn" style="border-color:#fca5a5;color:#991b1b;" onclick="addDedRow(\'\',0)"><i class="fa-solid fa-plus"></i> Add Row</button>';
    } else {
        html += '<span style="font-size:11px;color:#9ca3af;">' + (IS_CONFIRMED ? 'Sheet confirmed — locked' : 'Save sheet to add custom rows') + '</span>';
    }
    html += '<span class="add-row-total" id="addDedTotal" style="color:#991b1b;">Total: LKR 0.00</span>';
    html += '</div>';
    html += '</div>';
    html += '<div class="ms-total ms-total-ded"><span>Total Deductions</span><span>' + lkrPlain(d.total_deductions) + '</span></div>';
    html += '</div>';

    // ── NET SUMMARY ───────────────────────────────────────────────────────────
    html += '<div class="modal-section">';
    html += '<div class="ms-header ms-att"><i class="fa-solid fa-wallet"></i> Net Salary Summary</div>';
    html += '<div class="ms-body">';
    html += '<div style="text-align:center;padding:20px 16px 16px;">';
    html += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:6px;">NET TAKE-HOME SALARY</div>';
    html += '<div style="font-family:Space Mono,monospace;font-size:28px;font-weight:800;color:#065f46;">' + lkrPlain(d.net_salary) + '</div>';
    html += '</div>';
    html += '<div class="ms-row ms-earn-val"><span class="ms-row-label">Gross Earnings</span><span class="ms-row-value">' + lkr(d.total_earnings) + '</span></div>';
    html += '<div class="ms-row ms-ded-val"><span class="ms-row-label">Total Deductions</span><span class="ms-row-value" style="color:#991b1b;">− ' + lkrPlain(d.total_deductions) + '</span></div>';
    html += '<div class="ms-row" style="border-top:2px solid var(--border2);padding-top:10px;font-weight:700;"><span class="ms-row-label">Net Salary</span><span class="ms-row-value" style="color:#065f46;font-size:14px;">' + lkrPlain(d.net_salary) + '</span></div>';
    html += '</div>';
    html += '<div class="ms-total ms-total-att"><span>Net Take-Home</span><span>' + lkrPlain(d.net_salary) + '</span></div>';
    html += '</div>';

    // ── EMPLOYER COST / BENEFIT ───────────────────────────────────────────────
    html += '<div class="modal-section">';
    html += '<div class="ms-header ms-ben"><i class="fa-solid fa-star"></i> Total Benefit &amp; Employer Cost</div>';
    html += '<div class="ms-body">';
    html += '<div class="ms-row" style="background:#fffbeb;"><span class="ms-row-label" style="font-weight:700;font-size:11px;color:#78350f;text-transform:uppercase;letter-spacing:.3px;"><i class="fa-solid fa-calculator"></i> Cost of BP</span><span class="ms-row-value" style="color:#78350f;">' + lkrPlain(d.cost_bp) + '</span></div>';
    const costBpItems = [
        ['  Basic',              d.basic],
        ['  Disc. Support',      d.disc],
        ['  EPF Employer (12%)', d.epf_er],
        ['  ETF Employer (3%)',  d.etf_er],
        ['  Meal Reimb.',        d.meal],
        ['  Travelling Reimb.',  d.travel],
        ['  Mobile Reimb.',      d.mobile],
        ['  Fuel Reimb.',        d.fuel],
        ['  Bonus (Basic÷12)',   d.bonus],
    ];
    costBpItems.forEach(([label, val]) => {
        const cls = val <= 0 ? 'ms-zero' : 'ms-ben-val';
        html += '<div class="ms-row ' + cls + '" style="padding-left:24px;"><span class="ms-row-label" style="font-size:11px;">' + label + '</span><span class="ms-row-value" style="font-size:11px;">' + lkr(val) + '</span></div>';
    });
    html += '<div class="ms-row" style="background:#fffbeb;border-top:1px dashed #fde68a;margin-top:4px;"><span class="ms-row-label" style="font-weight:700;font-size:11px;color:#78350f;text-transform:uppercase;letter-spacing:.3px;"><i class="fa-solid fa-coins"></i> Provisions</span><span class="ms-row-value" style="color:#78350f;">' + lkrPlain(d.bonus + d.gratuity) + '</span></div>';
    html += '<div class="ms-row ms-ben-val" style="padding-left:24px;"><span class="ms-row-label" style="font-size:11px;">  Bonus (÷12)</span><span class="ms-row-value" style="font-size:11px;">' + lkr(d.bonus) + '</span></div>';
    html += '<div class="ms-row ms-ben-val" style="padding-left:24px;"><span class="ms-row-label" style="font-size:11px;">  Gratuity (÷24)</span><span class="ms-row-value" style="font-size:11px;">' + lkr(d.gratuity) + '</span></div>';
    const bens = [
        ['Insurance',         d.insurance],
        ['EPF Employer (12%)',d.epf_er],
        ['ETF Employer (3%)', d.etf_er],
    ];
    bens.forEach(([label, val]) => {
        const cls = val <= 0 ? 'ms-zero' : 'ms-ben-val';
        html += '<div class="ms-row ' + cls + '"><span class="ms-row-label">' + label + '</span><span class="ms-row-value">' + lkr(val) + '</span></div>';
    });
    html += '</div>';
    html += '<div class="ms-total ms-total-ben"><span>Total Benefit</span><span>' + lkrPlain(d.total_benefit) + '</span></div>';
    html += '</div>';

    html += '</div>'; // close modal-sections

    document.getElementById('modalBody').innerHTML = html;

    renderAddRows();
    updateAddTotals();

    const statusEl = document.getElementById('modalUpdateStatus');
    if (statusEl) statusEl.innerHTML = '';

    document.getElementById('detailModal').classList.add('show');
}

function closeModal() {
    document.getElementById('detailModal').classList.remove('show');
    _currentModalEid = null;
}

// Live search
function liveFilter(q) {
    q = q.trim().toLowerCase();
    document.querySelectorAll('.emp-row').forEach(row => {
        const s = row.getAttribute('data-search') || '';
        row.style.display = (q !== '' && !s.includes(q)) ? 'none' : '';
    });
}

// Filter toggle
function toggleFilter() {
    const body    = document.getElementById('filterBody');
    const chevron = document.getElementById('filterChevron');
    const open    = body.classList.toggle('open');
    chevron.style.transform = open ? 'rotate(180deg)' : '';
}

// Branch loader
function loadBranches(companyId) {
    const sel = document.getElementById('filter_branch');
    if (!sel) return;
    sel.innerHTML = '<option value="">All Branches</option>';
    if (!companyId) return;
    fetch('get_branches.php?company_id=' + companyId)
        .then(r => r.json())
        .then(data => data.forEach(b => {
            const o = new Option(b.branch_code + ' - ' + b.branch_name, b.id);
            sel.appendChild(o);
        }));
}
<?php if ($filter_company): ?>
loadBranches(<?php echo $filter_company; ?>);
setTimeout(() => { const s=document.getElementById('filter_branch'); if(s) s.value='<?php echo $filter_branch; ?>'; }, 600);
<?php endif; ?>

// CSV Export
function exportCSV() {
    const rows = [['Employee Code','EPF Number','Date of Join','Name','Status','Category','Designation',
                   'Norm Days','Att Days','Att Rate%','Att Allowance','No Pay Days','No Pay Amount',
                   'Fuel Reimb','Fuel Source','Salary Advance','Advance Source',
                   'Arrears Salary','Excess Payment','Other Incentive','Loan Deduction','Active Loans',
                   'Total Earnings','Total Deductions','Net Salary','Total Benefit','Cost of BP','Provisions (B+G)']];
    <?php foreach ($employees as $emp):
        $eid = (int)$emp['id']; $r = $rows[$eid]; $att = $r['att'];
        $loan_count_js = count($r['emp_loans']);
    ?>
    rows.push([
        <?php echo json_encode($emp['employee_id']); ?>,
        <?php echo json_encode($emp['epf_number']??''); ?>,
        <?php echo json_encode($emp['date_of_join']?date('d M Y',strtotime($emp['date_of_join'])):''); ?>,
        <?php echo json_encode($emp['employee_full_name']); ?>,
        <?php echo json_encode($emp['status']); ?>,
        <?php echo json_encode($emp['category_code']??''); ?>,
        <?php echo json_encode($emp['designation_name']??''); ?>,
        <?php echo $att['norm']; ?>, <?php echo $att['att']; ?>, <?php echo $att['rate']; ?>,
        <?php echo $r['att_allow']; ?>, <?php echo $r['no_pay_days']; ?>, <?php echo $r['no_pay_amount']; ?>,
        <?php echo $r['fuel']; ?>,
        <?php echo json_encode($r['fuel_from_entry'] ? 'Fuel Entry' : 'Profile'); ?>,
        <?php echo $r['sal_adv']; ?>,
        <?php echo json_encode($r['advance_from_entry'] ? 'Period Advances' : 'Profile'); ?>,
        <?php echo $r['arrears']; ?>,
        <?php echo $r['excess_p']; ?>,
        <?php echo $r['other_incentive']; ?>,
        <?php echo $r['loan_ded']; ?>,
        <?php echo $loan_count_js; ?>,
        <?php echo $r['total_earnings']; ?>, <?php echo $r['total_deductions']; ?>,
        <?php echo $r['net_salary']; ?>, <?php echo $r['total_benefit']; ?>,
        <?php echo $r['cost_bp']; ?>, <?php echo $r['provisions']; ?>
    ]);
    <?php endforeach; ?>
    const csv = rows.map(r => r.map(c => '"'+String(c).replace(/"/g,'""')+'"').join(',')).join('\n');
    const blob = new Blob([csv], {type:'text/csv'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'sr_salary_<?php echo $active_period ? $active_period['year'].'_'.$active_period['month'] : 'export'; ?>.csv';
    a.click();
}


function exportExcel() {
    // Define columns as {header, get: fn(d)=>value, numeric: bool}
    const cols = [
        { h: 'Employee Code',        n:false, get: d => d.code },
        { h: 'EPF Number',           n:false, get: d => d.epf },
        { h: 'Date of Join',         n:false, get: d => d.join },
        { h: 'Name',                 n:false, get: d => d.name },
        { h: 'Status',               n:false, get: d => d.status },
        { h: 'Category',             n:false, get: d => d.cat_code },
        { h: 'Designation',          n:false, get: d => d.desig },
        { h: 'Company',              n:false, get: d => d.company_code },
        { h: 'Branch',               n:false, get: d => d.branch_code },

        { h: 'Norm Days',            n:true,  get: d => d.norm_days },
        { h: 'Att Days',             n:true,  get: d => d.att_count },
        { h: 'Att Rate %',           n:true,  get: d => parseFloat(d.att_rate) },
        { h: 'Per Day Rate',         n:true,  get: d => parseFloat(d.per_day) },
        { h: 'No Pay Days',          n:true,  get: d => parseFloat(d.no_pay_days) },
        { h: 'No Pay Amount',        n:true,  get: d => parseFloat(d.no_pay_amount) },
        { h: 'Attendance Allowance', n:true,  get: d => parseFloat(d.att_allow) },

        { h: 'Basic Salary',           n:true, get: d => parseFloat(d.basic) },
        { h: 'Discretionary Support',  n:true, get: d => parseFloat(d.disc) },
        { h: 'SSV',                    n:true, get: d => parseFloat(d.ssv) },
        { h: 'PS Compliance',          n:true, get: d => parseFloat(d.ps_amt) },
        { h: 'ECO Incentive',          n:true, get: d => parseFloat(d.eco_amt) },
        { h: 'BP Incentive',           n:true, get: d => parseFloat(d.bp_amt) },
        { h: 'Total Assortment',       n:true, get: d => parseFloat(d.assort_amt) },
        { h: 'Other Incentive',        n:true, get: d => parseFloat(d.other_incentive) },
        { h: 'Meal Reimb',             n:true, get: d => parseFloat(d.meal) },
        { h: 'Travelling Reimb',       n:true, get: d => parseFloat(d.travel) },
        { h: 'Fuel Reimb',             n:true, get: d => parseFloat(d.fuel) },
        { h: 'Fuel Source',            n:false,get: d => d.fuel_from_entry ? 'Fuel Entry' : 'Profile' },
        { h: 'Mobile Reimb',           n:true, get: d => parseFloat(d.mobile) },
        { h: 'Arrears Salary',         n:true, get: d => parseFloat(d.arrears) },

        // ── Additional earnings ──
        { h: 'Additional Earnings Detail', n:false, get: d => (d.additional_earnings||[])
              .filter(r => (r.desc && r.desc.trim()!=='') || parseFloat(r.amount||0)>0)
              .map(r => (r.desc||'Item') + ': ' + parseFloat(r.amount||0).toFixed(2)).join(' | ') },
        { h: 'Additional Earnings Total', n:true, get: d => (d.additional_earnings||[])
              .reduce((s,r) => s + (parseFloat(r.amount)||0), 0) },

        { h: 'Total Earnings',         n:true, get: d => parseFloat(d.total_earnings) },

        { h: 'EPF Employee (8%)',      n:true, get: d => parseFloat(d.epf_emp) },
        { h: 'Welfare',                n:true, get: d => parseFloat(d.welfare_amt) },
        { h: 'Welfare Loan Ded',       n:true, get: d => parseFloat(d.w_loan) },
        { h: 'Welfare Society',        n:true, get: d => parseFloat(d.w_soc) },
        { h: 'Donations',              n:true, get: d => parseFloat(d.donations) },
        { h: 'Salary Advance',         n:true, get: d => parseFloat(d.sal_adv) },
        { h: 'Advance Source',         n:false,get: d => d.advance_from_entry ? 'Period Advances' : 'Profile' },
        { h: 'Loan Deduction',         n:true, get: d => parseFloat(d.loan_ded) },
        { h: 'Active Loans',           n:true, get: d => (d.emp_loans||[]).length },
        { h: 'Credit Recovery',        n:true, get: d => parseFloat(d.credit_r) },
        { h: 'Retention',              n:true, get: d => parseFloat(d.retention) },
        { h: 'Excess Payment',         n:true, get: d => parseFloat(d.excess_p) },

        // ── Additional deductions ──
        { h: 'Additional Deductions Detail', n:false, get: d => (d.additional_deductions||[])
              .filter(r => (r.desc && r.desc.trim()!=='') || parseFloat(r.amount||0)>0)
              .map(r => (r.desc||'Item') + ': ' + parseFloat(r.amount||0).toFixed(2)).join(' | ') },
        { h: 'Additional Deductions Total', n:true, get: d => (d.additional_deductions||[])
              .reduce((s,r) => s + (parseFloat(r.amount)||0), 0) },

        { h: 'Total Deductions',       n:true, get: d => parseFloat(d.total_deductions) },

        { h: 'Net Salary',             n:true, get: d => parseFloat(d.net_salary) },

        { h: 'EPF Employer (12%)',     n:true, get: d => parseFloat(d.epf_er) },
        { h: 'ETF Employer (3%)',      n:true, get: d => parseFloat(d.etf_er) },
        { h: 'Insurance',              n:true, get: d => parseFloat(d.insurance) },
        { h: 'Bonus',                  n:true, get: d => parseFloat(d.bonus) },
        { h: 'Gratuity',               n:true, get: d => parseFloat(d.gratuity) },
        { h: 'Cost of BP',             n:true, get: d => parseFloat(d.cost_bp) },
        { h: 'Provisions (B+G)',       n:true, get: d => parseFloat(d.bonus) + parseFloat(d.gratuity) },
        { h: 'Total Benefit',          n:true, get: d => parseFloat(d.total_benefit) },
    ];

    const headers = cols.map(c => c.h);
    const rows = [headers];
    const totals = new Array(cols.length).fill(0);

    Object.values(SR_DATA).forEach(d => {
        const row = cols.map((c, i) => {
            const v = c.get(d);
            if (c.n) totals[i] += (parseFloat(v) || 0);
            return v;
        });
        rows.push(row);
    });

    // Grand total row
    const totalRow = cols.map((c, i) => {
        if (i === 3) return 'GRAND TOTAL';
        return c.n ? +totals[i].toFixed(2) : '';
    });
    rows.push(totalRow);

    const ws = XLSX.utils.aoa_to_sheet(rows);

    // Column widths
    ws['!cols'] = cols.map(c => ({
        wch: c.h.includes('Detail') ? 40 : (c.h === 'Name' ? 24 : Math.max(c.h.length + 3, 10))
    }));

    // Number format for numeric columns
    const range = XLSX.utils.decode_range(ws['!ref']);
    cols.forEach((c, ci) => {
        if (!c.n) return;
        for (let R = 1; R <= range.e.r; R++) {
            const addr = XLSX.utils.encode_cell({ r: R, c: ci });
            if (ws[addr] && typeof ws[addr].v === 'number') {
                ws[addr].z = '#,##0.00';
            }
        }
    });

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'SR Salary Sheet');

    const periodLabel = <?php echo json_encode($active_period ? $month_names[$active_period['month']].'_'.$active_period['year'] : 'export'); ?>;
    XLSX.writeFile(wb, 'sr_salary_' + periodLabel + '.xlsx');
}
</script>

<?php include 'footer.php'; ?>