<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'config.php';

// ── Ensure columns exist ───────────────────────────────────────────────────
$alter_cols = [
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS reimbursement_meal       DECIMAL(12,2) NULL",
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
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS cash_short               DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS good_short               DECIMAL(12,2) NULL",
];
foreach ($alter_cols as $s) @mysqli_query($conn, $s);

// ── salary_sheet_entries: add CC-specific columns ─────────────────────────
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
    meal                    DECIMAL(12,2) DEFAULT 0,
    travel                  DECIMAL(12,2) DEFAULT 0,
    fuel                    DECIMAL(12,2) DEFAULT 0,
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

// CC-specific extra columns
$cc_extra = [
    "ALTER TABLE salary_sheet_entries ADD COLUMN IF NOT EXISTS ccfot_amt       DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE salary_sheet_entries ADD COLUMN IF NOT EXISTS credit_mgt_amt  DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE salary_sheet_entries ADD COLUMN IF NOT EXISTS payee_chq_amt   DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE salary_sheet_entries ADD COLUMN IF NOT EXISTS ssv_cc_amt      DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE salary_sheet_entries ADD COLUMN IF NOT EXISTS att_inc_amt     DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE salary_sheet_entries ADD COLUMN IF NOT EXISTS locus_admin_amt DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE salary_sheet_entries ADD COLUMN IF NOT EXISTS cash_short      DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE salary_sheet_entries ADD COLUMN IF NOT EXISTS good_short      DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE salary_sheet_entries ADD COLUMN IF NOT EXISTS override_credit_r DECIMAL(12,2) DEFAULT NULL",
    "ALTER TABLE salary_sheet_entries ADD COLUMN IF NOT EXISTS override_cash_short DECIMAL(12,2) DEFAULT NULL",
    "ALTER TABLE salary_sheet_entries ADD COLUMN IF NOT EXISTS override_good_short DECIMAL(12,2) DEFAULT NULL",
];
foreach ($cc_extra as $s) @mysqli_query($conn, $s);

// ── Category codes handled by this sheet ──────────────────────────────────
// CC = Cash Collector, ACC = Assistant Cash Collector, SACC = Senior Assistant
// Cash Collector. All three share this sheet, so every place that used to
// filter on a hardcoded 'CC' string now uses this list instead.
$CC_SHEET_CATEGORY_CODES = ['CC', 'ACC', 'SACC'];
function cc_cat_in_sql($codes) {
    return "'" . implode("','", array_map('addslashes', $codes)) . "'";
}
$CC_CAT_SQL_IN = cc_cat_in_sql($CC_SHEET_CATEGORY_CODES);

// ── AJAX handlers ─────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    ob_clean();
    header('Content-Type: application/json');

    if ($_GET['ajax'] === 'delete_sheet' && isset($_GET['period_id'])) {
        $pid = intval($_GET['period_id']);
        $chk = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT sheet_status FROM salary_sheet_entries WHERE payroll_period_id=$pid AND category_code IN ($CC_CAT_SQL_IN) LIMIT 1"));
        if ($chk && $chk['sheet_status'] === 'Confirmed') {
            echo json_encode(['ok'=>false,'msg'=>'Cannot delete a Confirmed salary sheet.']); exit;
        }
        mysqli_query($conn, "DELETE FROM salary_sheet_entries WHERE payroll_period_id=$pid AND category_code IN ($CC_CAT_SQL_IN)");
        echo json_encode(['ok'=>true,'deleted'=>mysqli_affected_rows($conn)]);
        exit;
    }

    if ($_GET['ajax'] === 'confirm_sheet' && isset($_GET['period_id'])) {
        $pid = intval($_GET['period_id']);
        $chk = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS cnt FROM salary_sheet_entries WHERE payroll_period_id=$pid AND category_code IN ($CC_CAT_SQL_IN) AND sheet_status='Confirmed'"));
        if (intval($chk['cnt']) > 0) { echo json_encode(['ok'=>false,'msg'=>'Sheet is already confirmed.']); exit; }
        $emp_res  = mysqli_query($conn, "SELECT employee_id FROM salary_sheet_entries WHERE payroll_period_id=$pid AND category_code IN ($CC_CAT_SQL_IN)");
        $emp_ids  = [];
        while ($er = mysqli_fetch_assoc($emp_res)) $emp_ids[] = intval($er['employee_id']);
        if (empty($emp_ids)) { echo json_encode(['ok'=>false,'msg'=>'No employees in sheet.']); exit; }
        $payment_date = date('Y-m-d'); $payments_created = 0; $errors = [];
        $pp_info  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT year, month FROM payroll_periods WHERE id=$pid LIMIT 1"));
        $pp_year  = intval($pp_info['year']  ?? 0);
        $pp_month = intval($pp_info['month'] ?? 0);
        foreach ($emp_ids as $eid) {
            $loan_res = mysqli_query($conn,
                "SELECT lr.*, COALESCE((SELECT SUM(lp.total_paid) FROM loan_payments lp WHERE lp.loan_request_id=lr.id),0) AS paid_so_far
                 FROM loan_requests lr WHERE lr.employee_id=$eid AND lr.status='Approved' ORDER BY lr.id ASC");
            while ($loan = mysqli_fetch_assoc($loan_res)) {
                $loan_id = intval($loan['id']);
                $req_date_raw = !empty($loan['approved_date']) ? $loan['approved_date'] : (!empty($loan['request_date']) ? $loan['request_date'] : '');
                if ($req_date_raw) {
                    $req_yyyymm = intval(date('Y', strtotime($req_date_raw))) * 100 + intval(date('n', strtotime($req_date_raw)));
                    $pp_yyyymm  = $pp_year * 100 + $pp_month;
                    if ($pp_yyyymm <= $req_yyyymm) continue;
                }
                $total_repay    = floatval($loan['total_repayment']) ?: floatval($loan['loan_amount']);
                $paid_so_far    = floatval($loan['paid_so_far']);
                $balance_before = round($total_repay - $paid_so_far, 2);
                if ($balance_before <= 0) continue;
                $already = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT id FROM loan_payments WHERE loan_request_id=$loan_id AND payroll_period_id=$pid"));
                if ($already) continue;
                $monthly       = floatval($loan['monthly_instalment']);
                $this_payment  = min($monthly, $balance_before);
                $balance_after = round($balance_before - $this_payment, 2);
                $inst_num      = intval(mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT COUNT(*) AS cnt FROM loan_payments WHERE loan_request_id=$loan_id"))['cnt']) + 1;
                $no_inst       = max(1, intval($loan['no_of_instalments']));
                $interest_per  = round(floatval($loan['total_interest']) / $no_inst, 2);
                $principal_paid= round($this_payment - $interest_per, 2);
                if ($principal_paid < 0) { $interest_per = $this_payment; $principal_paid = 0; }
                $ins = mysqli_query($conn,
                    "INSERT INTO loan_payments (loan_request_id,employee_id,payroll_period_id,payment_date,instalment_number,principal_paid,interest_paid,total_paid,balance_before,balance_after)
                     VALUES ($loan_id,$eid,$pid,'$payment_date',$inst_num,$principal_paid,$interest_per,$this_payment,$balance_before,$balance_after)");
                if ($ins) {
                    $payments_created++;
                    if ($balance_after <= 0) mysqli_query($conn, "UPDATE loan_requests SET status='Completed' WHERE id=$loan_id");
                } else { $errors[] = "Loan #$loan_id emp #$eid: ".mysqli_error($conn); }
            }
        }
        mysqli_query($conn, "UPDATE salary_sheet_entries SET sheet_status='Confirmed' WHERE payroll_period_id=$pid AND category_code IN ($CC_CAT_SQL_IN)");
        echo json_encode(['ok'=>true,'payments_created'=>$payments_created,'errors'=>$errors]);
        exit;
    }

    if ($_GET['ajax'] === 'update_overrides' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body       = json_decode(file_get_contents('php://input'), true);
        $pid        = intval($body['period_id']  ?? 0);
        $eid        = intval($body['employee_id']?? 0);
        $excess     = isset($body['excess'])     ? floatval($body['excess'])     : null;
        $arrears    = isset($body['arrears'])     ? floatval($body['arrears'])    : null;
        $credit_r   = isset($body['credit_r'])   ? floatval($body['credit_r'])   : null;
        $cash_short = isset($body['cash_short']) ? floatval($body['cash_short']) : null;
        $good_short = isset($body['good_short']) ? floatval($body['good_short']) : null;
        $add_earn   = $body['additional_earnings']   ?? null;
        $add_ded    = $body['additional_deductions'] ?? null;
        if ($pid && $eid) {
            $row = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT * FROM salary_sheet_entries WHERE payroll_period_id=$pid AND employee_id=$eid AND category_code IN ($CC_CAT_SQL_IN)"));

            // ── AUTO-HEAL: row missing (e.g. employee was excluded from the
            // original "Create Sheet" save because a filter was active at the
            // time, or the employee was added/changed category afterwards).
            // Instead of failing with "Row not found", build the row now from
            // live data and insert it, then continue with the normal update. ─
            if (!$row) {
                $emp2 = mysqli_fetch_assoc(mysqli_query($conn, "
                    SELECT e.*, c.company_code, b.branch_code, d.designation_name, sc.category_code,
                           cfg.ccfot_90, cfg.ccfot_91, cfg.ccfot_92, cfg.ccfot_93, cfg.ccfot_94, cfg.ccfot_95,
                           cfg.credit_mgt_85, cfg.credit_mgt_90, cfg.credit_mgt_95,
                           cfg.payee_chq_80,
                           cfg.ssv_90, cfg.ssv_95,
                           cfg.attendance_85, cfg.attendance_90,
                           cfg.locus_admin,
                           cfg.meal_reimbursement, cfg.mobile_reimbursement,
                           cfg.insurance_amount, cfg.welfare_amount
                    FROM employees e
                    LEFT JOIN companies        c   ON e.company_id        = c.id
                    LEFT JOIN branches         b   ON e.branch_id         = b.id
                    LEFT JOIN designations     d   ON e.designation_id    = d.id
                    LEFT JOIN staff_categories sc  ON e.staff_category_id = sc.id
                    LEFT JOIN sr_salary_config cfg ON cfg.designation_id  = d.id
                    WHERE e.id=$eid LIMIT 1
                "));

                if (!$emp2) { echo json_encode(['ok'=>false,'msg'=>'Row not found']); exit; }

                $pp_info2 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT year, month FROM payroll_periods WHERE id=$pid LIMIT 1"));
                $py2 = intval($pp_info2['year'] ?? date('Y'));
                $pm2 = intval($pp_info2['month'] ?? date('n'));

                $ef2_from = sprintf('%04d-%02d-01', $py2, $pm2);
                $ef2_to   = date('Y-m-t', strtotime($ef2_from));
                $ef2_mtd  = (int)date('t', strtotime($ef2_from));

                $ef2_holidays = 0;
                $sh_res2 = mysqli_query($conn, "SELECT * FROM special_holidays WHERE active=1 AND date_from<='$ef2_to' AND date_to>='$ef2_from'");
                if ($sh_res2) {
                    $seen_days = [];
                    while ($sh2 = mysqli_fetch_assoc($sh_res2)) {
                        $applies = false;
                        if ($sh2['target_type']==='all') { $applies=true; }
                        elseif (!empty($sh2['target_ids'])) {
                            $ids2 = json_decode($sh2['target_ids'], true) ?? [];
                            if ($sh2['target_type']==='employee'       && in_array($eid,$ids2)) $applies=true;
                            if ($sh2['target_type']==='staff_category' && in_array((int)($emp2['staff_category_id']??0),$ids2)) $applies=true;
                            if ($sh2['target_type']==='designation'    && in_array((int)($emp2['designation_id']??0),$ids2))    $applies=true;
                        }
                        if ($applies) {
                            $start2 = new DateTime(max($sh2['date_from'],$ef2_from));
                            $end2   = new DateTime(min($sh2['date_to'],$ef2_to)); $end2->modify('+1 day');
                            foreach (new DatePeriod($start2,new DateInterval('P1D'),$end2) as $dt2) {
                                $dstr = $dt2->format('Y-m-d');
                                if (!isset($seen_days[$dstr])) { $seen_days[$dstr]=true; $ef2_holidays++; }
                            }
                        }
                    }
                }

                $ac2 = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT COUNT(*) AS cnt FROM attendance WHERE employee_id=$eid AND att_date BETWEEN '$ef2_from' AND '$ef2_to'"));
                $att2_count = intval($ac2['cnt'] ?? 0);

                $lv2 = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT SUM(days_count) AS total_leave FROM leave_applications
                     WHERE status='Approved' AND employee_id=$eid AND
                           (start_date BETWEEN '$ef2_from' AND '$ef2_to'
                            OR end_date BETWEEN '$ef2_from' AND '$ef2_to'
                            OR (start_date<='$ef2_from' AND end_date>='$ef2_to'))"));
                $approved_leave2 = floatval($lv2['total_leave'] ?? 0);

                $norm2 = max(1, $ef2_mtd - $ef2_holidays);
                $rate2 = round(($att2_count / $norm2) * 100, 2);
                $no_pay_days2 = max(0, $norm2 - $att2_count - $approved_leave2);

                $epf_s2 = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM epf_etf_settings LIMIT 1"));
                $epf_er_rate2 = $epf_s2 ? floatval($epf_s2['epf_employer']) : 12;
                $epf_ee_rate2 = $epf_s2 ? floatval($epf_s2['epf_employee']) : 8;
                $etf_er_rate2 = $epf_s2 ? floatval($epf_s2['etf_employer']) : 3;

                $ie2 = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT * FROM incentive_entries WHERE payroll_period_id=$pid AND employee_id=$eid LIMIT 1"));
                // Auto-created rows use config-tier resolution only when a manual incentive_entries row exists;
                // otherwise default incentive add-ons to 0 (they can be edited afterwards via Incentive Entry).
                $ccfot2 = $credit_mgt2 = $payee_chq2 = $ssv2 = $att_inc2 = $locus2 = 0;

                $basic2       = floatval($emp2['basic_salary']         ?? 0);
                $insurance2   = floatval($emp2['insurance_amount']     ?? 0);
                $welfare2     = floatval($emp2['welfare_amount']       ?? 0);
                $meal_cfg2    = floatval($emp2['meal_reimbursement']   ?? $emp2['reimbursement_meal']   ?? 0);
                $mobile2      = floatval($emp2['mobile_reimbursement'] ?? $emp2['reimbursement_mobile'] ?? 0);
                $arrears2     = floatval($emp2['arrears_salary']       ?? 0);

                $per_day2   = $basic2 > 0 ? round($basic2 / $norm2, 4) : 0;
                $no_pay_amt2= $per_day2 > 0 ? round($per_day2 * $no_pay_days2, 2) : 0;
                $meal_calc2 = ($meal_cfg2 > 0 && $norm2 > 0) ? round(($att2_count / $norm2) * $meal_cfg2, 2) : $meal_cfg2;

                $total_earnings2 = $basic2 + $ccfot2 + $credit_mgt2 + $payee_chq2 + $ssv2 + $att_inc2 + $locus2
                                 + $meal_calc2 + $mobile2 + $arrears2;

                $epf_emp2 = round($basic2 * $epf_ee_rate2 / 100, 2);
                $epf_er2  = round($basic2 * $epf_er_rate2 / 100, 2);
                $etf_er2  = round($basic2 * $etf_er_rate2 / 100, 2);
                $w_loan2  = floatval($emp2['welfare_loan_deduction'] ?? 0);
                $w_soc2   = floatval($emp2['welfare_society']        ?? 0);
                $donations2 = floatval($emp2['donations']            ?? 0);

                $adv2 = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT SUM(amount) AS total_advance FROM salary_advances
                     WHERE status='Approved' AND employee_id=$eid AND request_date BETWEEN '$ef2_from' AND '$ef2_to'"));
                $sal_adv2 = $adv2 && $adv2['total_advance'] !== null ? floatval($adv2['total_advance']) : floatval($emp2['salary_advance'] ?? 0);

                $retention2 = floatval($emp2['retention'] ?? 0);

                $loan_ded2 = 0;
                $loan_res2 = mysqli_query($conn,
                    "SELECT lr.*, COALESCE((SELECT SUM(lp.total_paid) FROM loan_payments lp WHERE lp.loan_request_id=lr.id),0) AS paid_so_far
                     FROM loan_requests lr WHERE lr.employee_id=$eid AND lr.status='Approved'");
                if ($loan_res2) while ($ln2 = mysqli_fetch_assoc($loan_res2)) {
                    $tot2  = floatval($ln2['total_repayment']) ?: floatval($ln2['loan_amount']);
                    $paid2 = floatval($ln2['paid_so_far']);
                    $bal2  = round($tot2 - $paid2, 2);
                    if ($bal2 <= 0) continue;
                    $loan_ded2 += min(floatval($ln2['monthly_instalment']), $bal2);
                }
                if ($loan_ded2 <= 0) $loan_ded2 = floatval($emp2['loan_deduction'] ?? 0);

                $total_deductions2 = $epf_emp2 + $welfare2 + $w_loan2 + $w_soc2 + $donations2
                                    + $sal_adv2 + $loan_ded2 + $retention2 + $no_pay_amt2;
                $net2        = $total_earnings2 - $total_deductions2;
                $bonus2      = $basic2 > 0 ? round($basic2 / 12, 2) : 0;
                $gratuity2   = $basic2 > 0 ? round($basic2 / 24, 2) : 0;
                $cost_bp2    = $epf_er2 + $etf_er2 + $meal_calc2 + $mobile2 + $bonus2 + $basic2;
                $total_ben2  = $basic2 + $ccfot2 + $credit_mgt2 + $payee_chq2 + $ssv2 + $att_inc2 + $locus2
                             + $meal_calc2 + $mobile2 + $insurance2 + $epf_er2 + $etf_er2 + $bonus2 + $gratuity2;

                // Preserve the employee's ACTUAL category (CC / ACC / SACC) instead
                // of hardcoding 'CC' -- otherwise SACC/ACC auto-healed rows would be
                // mis-tagged and then excluded again by category-filtered queries.
                $emp2_cat_code = strtoupper(trim($emp2['category_code'] ?? ''));
                if (!in_array($emp2_cat_code, $GLOBALS['CC_SHEET_CATEGORY_CODES'], true)) {
                    $emp2_cat_code = 'CC';
                }

                $ins_map2 = [
                    'payroll_period_id'  => $pid,
                    'employee_id'        => $eid,
                    'employee_code'      => $emp2['employee_id']       ?? '',
                    'employee_name'      => $emp2['employee_full_name']?? '',
                    'epf_number'         => $emp2['epf_number']        ?? '',
                    'date_of_join'       => $emp2['date_of_join']      ?? null,
                    'status'             => $emp2['status']            ?? '',
                    'category_code'      => $emp2_cat_code,
                    'designation_name'   => $emp2['designation_name']  ?? '',
                    'company_code'       => $emp2['company_code']      ?? '',
                    'branch_code'        => $emp2['branch_code']       ?? '',
                    'att_count'          => $att2_count,
                    'norm_days'          => $norm2,
                    'holidays'           => $ef2_holidays,
                    'att_rate'           => $rate2,
                    'per_day'            => $per_day2,
                    'no_pay_days'        => $no_pay_days2,
                    'no_pay_amount'      => $no_pay_amt2,
                    'basic'              => $basic2,
                    'ccfot_amt'          => $ccfot2,
                    'credit_mgt_amt'     => $credit_mgt2,
                    'payee_chq_amt'      => $payee_chq2,
                    'ssv_cc_amt'         => $ssv2,
                    'att_inc_amt'        => $att_inc2,
                    'locus_admin_amt'    => $locus2,
                    'meal'               => $meal_calc2,
                    'mobile'             => $mobile2,
                    'arrears'            => $arrears2,
                    'total_earnings'     => $total_earnings2,
                    'epf_emp'            => $epf_emp2,
                    'welfare_amt'        => $welfare2,
                    'w_loan'             => $w_loan2,
                    'w_soc'              => $w_soc2,
                    'donations'          => $donations2,
                    'sal_adv'            => $sal_adv2,
                    'advance_from_entry' => ($adv2 && $adv2['total_advance'] !== null) ? 1 : 0,
                    'loan_ded'           => $loan_ded2,
                    'credit_r'           => 0,
                    'cash_short'         => 0,
                    'good_short'         => 0,
                    'retention'          => $retention2,
                    'excess_p'           => 0,
                    'total_deductions'   => $total_deductions2,
                    'net_salary'         => $net2,
                    'epf_er'             => $epf_er2,
                    'etf_er'             => $etf_er2,
                    'insurance'          => $insurance2,
                    'bonus'              => $bonus2,
                    'gratuity'           => $gratuity2,
                    'cost_bp'            => $cost_bp2,
                    'total_benefit'      => $total_ben2,
                    'sheet_status'       => 'Pending',
                ];
                $ins_keys2=[]; $ins_vals2=[];
                foreach ($ins_map2 as $k2 => $v2) {
                    $ins_keys2[] = "`$k2`";
                    $ins_vals2[] = ($v2===null) ? 'NULL' : "'".mysqli_real_escape_string($conn,(string)$v2)."'";
                }
                mysqli_query($conn,"INSERT INTO salary_sheet_entries (".implode(',',$ins_keys2).") VALUES (".implode(',',$ins_vals2).")");

                $row = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT * FROM salary_sheet_entries WHERE payroll_period_id=$pid AND employee_id=$eid AND category_code IN ($CC_CAT_SQL_IN)"));
                if (!$row) { echo json_encode(['ok'=>false,'msg'=>'Row not found']); exit; }
            }

            if ($row) {
                if ($row['sheet_status'] === 'Confirmed') {
                    echo json_encode(['ok'=>false,'msg'=>'Sheet is confirmed. No further edits allowed.']); exit;
                }
                $new_excess     = $excess     !== null ? $excess     : floatval($row['excess_p']);
                $new_arrears    = $arrears    !== null ? $arrears    : floatval($row['arrears']);
                $new_credit_r   = $credit_r   !== null ? $credit_r   : floatval($row['credit_r']);
                $new_cash_short = $cash_short !== null ? $cash_short : floatval($row['cash_short'] ?? 0);
                $new_good_short = $good_short !== null ? $good_short : floatval($row['good_short'] ?? 0);
                $add_earn_total = 0; $add_ded_total = 0;
                if (is_array($add_earn)) foreach ($add_earn as $ae) $add_earn_total += floatval($ae['amount'] ?? 0);
                if (is_array($add_ded))  foreach ($add_ded  as $ad) $add_ded_total  += floatval($ad['amount'] ?? 0);
                $new_total_earnings =
                    floatval($row['basic']) +
                    floatval($row['ccfot_amt'] ?? 0) +
                    floatval($row['credit_mgt_amt'] ?? 0) +
                    floatval($row['payee_chq_amt'] ?? 0) +
                    floatval($row['ssv_cc_amt'] ?? 0) +
                    floatval($row['att_inc_amt'] ?? 0) +
                    floatval($row['locus_admin_amt'] ?? 0) +
                    floatval($row['meal']) +
                    floatval($row['mobile']) +
                    $new_arrears + $add_earn_total;
                $new_total_ded =
                    floatval($row['epf_emp']) + floatval($row['welfare_amt']) + floatval($row['w_loan']) +
                    floatval($row['w_soc']) + floatval($row['donations']) + floatval($row['sal_adv']) +
                    floatval($row['loan_ded']) + $new_credit_r + floatval($row['no_pay_amount']) +
                    floatval($row['retention']) + $new_excess +
                    $new_cash_short + $new_good_short + $add_ded_total;
                $new_net = $new_total_earnings - $new_total_ded;
                $new_cost_bp = floatval($row['epf_er']) + floatval($row['etf_er']) +
                               floatval($row['meal']) + floatval($row['mobile']) +
                               floatval($row['basic']) + floatval($row['bonus']);
                $new_total_benefit = $new_total_earnings + floatval($row['insurance']) +
                                     floatval($row['epf_er']) + floatval($row['etf_er']) +
                                     floatval($row['bonus']) + floatval($row['gratuity']);
                $esc_earn = $add_earn !== null ? "'".mysqli_real_escape_string($conn, json_encode($add_earn))."'" : "additional_earnings";
                $esc_ded  = $add_ded  !== null ? "'".mysqli_real_escape_string($conn, json_encode($add_ded))."'"  : "additional_deductions";
                mysqli_query($conn, "UPDATE salary_sheet_entries SET
                    arrears=$new_arrears, excess_p=$new_excess, credit_r=$new_credit_r,
                    cash_short=$new_cash_short, good_short=$new_good_short,
                    total_earnings=$new_total_earnings, total_deductions=$new_total_ded, net_salary=$new_net,
                    cost_bp=$new_cost_bp, total_benefit=$new_total_benefit,
                    override_excess=".($excess!==null?$new_excess:'override_excess').",
                    override_arrears=".($arrears!==null?$new_arrears:'override_arrears').",
                    override_credit_r=".($credit_r!==null?$new_credit_r:'override_credit_r').",
                    override_cash_short=".($cash_short!==null?$new_cash_short:'override_cash_short').",
                    override_good_short=".($good_short!==null?$new_good_short:'override_good_short').",
                    additional_earnings=$esc_earn, additional_deductions=$esc_ded
                    WHERE payroll_period_id=$pid AND employee_id=$eid");
                echo json_encode(['ok'=>true,'total_earnings'=>$new_total_earnings,'total_deductions'=>$new_total_ded,'net_salary'=>$new_net,'cost_bp'=>$new_cost_bp,'total_benefit'=>$new_total_benefit]);
                exit;
            }
        }
        echo json_encode(['ok'=>false,'msg'=>'Row not found']); exit;
    }

    exit;
}

// ── Handle POST: save salary sheet ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_sheet') {
    $pid     = intval($_POST['period_id'] ?? 0);
    $payload = json_decode($_POST['rows_json'] ?? '[]', true);
    if ($pid && !empty($payload)) {
        foreach ($payload as $row) {
            $eid = intval($row['employee_db_id']);
            // Use the employee's actual category (CC / ACC / SACC) coming from
            // CC_DATA's 'cat' field, instead of hardcoding 'CC' for every row.
            $row_cat_code = strtoupper(trim($row['cat'] ?? ''));
            if (!in_array($row_cat_code, $CC_SHEET_CATEGORY_CODES, true)) {
                $row_cat_code = 'CC';
            }
            $map = [
                'payroll_period_id'  => $pid,
                'employee_id'        => $eid,
                'employee_code'      => $row['code']       ?? '',
                'employee_name'      => $row['name']       ?? '',
                'epf_number'         => $row['epf']        ?? '',
                'date_of_join'       => $row['join_raw']   ?? null,
                'status'             => $row['status']     ?? '',
                'category_code'      => $row_cat_code,
                'designation_name'   => $row['desig']      ?? '',
                'company_code'       => $row['company_code'] ?? '',
                'branch_code'        => $row['branch_code']  ?? '',
                'att_count'          => floatval($row['att_count']  ?? 0),
                'norm_days'          => floatval($row['norm_days']  ?? 0),
                'holidays'           => floatval($row['holidays']   ?? 0),
                'att_rate'           => floatval($row['att_rate']   ?? 0),
                'per_day'            => floatval($row['per_day']    ?? 0),
                'no_pay_days'        => floatval($row['no_pay_days']   ?? 0),
                'no_pay_amount'      => floatval($row['no_pay_amount'] ?? 0),
                'basic'              => floatval($row['basic']      ?? 0),
                'ccfot_amt'          => floatval($row['ccfot_amt']  ?? 0),
                'credit_mgt_amt'     => floatval($row['credit_mgt_amt'] ?? 0),
                'payee_chq_amt'      => floatval($row['payee_chq_amt']  ?? 0),
                'ssv_cc_amt'         => floatval($row['ssv_cc_amt'] ?? 0),
                'att_inc_amt'        => floatval($row['att_inc_amt'] ?? 0),
                'locus_admin_amt'    => floatval($row['locus_admin_amt'] ?? 0),
                'meal'               => floatval($row['meal']       ?? 0),
                'mobile'             => floatval($row['mobile']     ?? 0),
                'arrears'            => floatval($row['arrears']    ?? 0),
                'total_earnings'     => floatval($row['total_earnings']  ?? 0),
                'epf_emp'            => floatval($row['epf_emp']    ?? 0),
                'welfare_amt'        => floatval($row['welfare_amt']?? 0),
                'w_loan'             => floatval($row['w_loan']     ?? 0),
                'w_soc'              => floatval($row['w_soc']      ?? 0),
                'donations'          => floatval($row['donations']  ?? 0),
                'sal_adv'            => floatval($row['sal_adv']    ?? 0),
                'advance_from_entry' => intval($row['advance_from_entry'] ?? 0),
                'loan_ded'           => floatval($row['loan_ded']   ?? 0),
                'credit_r'           => floatval($row['credit_r']   ?? 0),
                'cash_short'         => floatval($row['cash_short'] ?? 0),
                'good_short'         => floatval($row['good_short'] ?? 0),
                'retention'          => floatval($row['retention']  ?? 0),
                'excess_p'           => floatval($row['excess_p']   ?? 0),
                'total_deductions'   => floatval($row['total_deductions']   ?? 0),
                'net_salary'         => floatval($row['net_salary']         ?? 0),
                'epf_er'             => floatval($row['epf_er']     ?? 0),
                'etf_er'             => floatval($row['etf_er']     ?? 0),
                'insurance'          => floatval($row['insurance']  ?? 0),
                'bonus'              => floatval($row['bonus']      ?? 0),
                'gratuity'           => floatval($row['gratuity']   ?? 0),
                'cost_bp'            => floatval($row['cost_bp']    ?? 0),
                'total_benefit'      => floatval($row['total_benefit'] ?? 0),
                'override_excess'    => null,
                'override_arrears'   => null,
                'override_credit_r'  => null,
                'override_cash_short'=> null,
                'override_good_short'=> null,
                'additional_earnings'    => null,
                'additional_deductions'  => null,
                'sheet_status'       => 'Pending',
            ];
            $ins_keys=[]; $ins_vals=[]; $upd_parts=[];
            foreach ($map as $k => $v) {
                $ins_keys[] = "`$k`";
                $esc = ($v===null) ? 'NULL' : "'".mysqli_real_escape_string($conn,(string)$v)."'";
                $ins_vals[] = $esc;
                if (!in_array($k,['payroll_period_id','employee_id','sheet_status'])) $upd_parts[] = "`$k`=$esc";
            }
            $upd_parts[] = "`updated_at`=NOW()";
            mysqli_query($conn,"INSERT INTO salary_sheet_entries (".implode(',',$ins_keys).") VALUES (".implode(',',$ins_vals).")
                ON DUPLICATE KEY UPDATE ".implode(',',$upd_parts));
        }
        header("Location: cc_salary.php?period_id=$pid&sheet=saved"); exit;
    }
}

// ── Payroll period ────────────────────────────────────────────────────────
$sel_period_id = isset($_GET['period_id']) ? intval($_GET['period_id']) : 0;
$all_periods   = [];
$pp_res        = mysqli_query($conn, "SELECT id, year, month, status FROM payroll_periods ORDER BY year DESC, month DESC");
if ($pp_res) while ($p = mysqli_fetch_assoc($pp_res)) $all_periods[] = $p;
if (!$sel_period_id) {
    foreach ($all_periods as $pp) { if (($pp['status']??'')==='Open') { $sel_period_id=$pp['id']; break; } }
    if (!$sel_period_id && !empty($all_periods)) $sel_period_id = $all_periods[0]['id'];
}
$active_period = null;
foreach ($all_periods as $pp) { if ($pp['id']==$sel_period_id) { $active_period=$pp; break; } }

// ── Saved sheet status ────────────────────────────────────────────────────
$saved_sheet_count = 0; $saved_sheet_status = 'Pending';
if ($sel_period_id) {
    $sc = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) AS cnt, MAX(sheet_status) AS sstatus FROM salary_sheet_entries WHERE payroll_period_id=$sel_period_id AND category_code IN ($CC_CAT_SQL_IN)"));
    $saved_sheet_count  = intval($sc['cnt']);
    $saved_sheet_status = $sc['sstatus'] ?? 'Pending';
}
$has_saved_sheet = $saved_sheet_count > 0;
$is_confirmed    = $saved_sheet_status === 'Confirmed';

// ── Attendance helpers ────────────────────────────────────────────────────
if ($active_period) {
    $pp_year=$active_period['year']; $pp_month=$active_period['month'];
    $from_date        = sprintf('%04d-%02d-01',$pp_year,$pp_month);
    $to_date          = date('Y-m-t',strtotime($from_date));
    $month_total_days = (int)date('t',strtotime($from_date));
    $sh_res           = mysqli_query($conn,"SELECT * FROM special_holidays WHERE active=1 AND date_from<='$to_date' AND date_to>='$from_date'");
    $sh_date_map      = [];
    if ($sh_res) while ($sh=mysqli_fetch_assoc($sh_res)) {
        $start=new DateTime(max($sh['date_from'],$from_date));
        $end=new DateTime(min($sh['date_to'],$to_date)); $end->modify('+1 day');
        foreach (new DatePeriod($start,new DateInterval('P1D'),$end) as $dt) {
            $d=$dt->format('Y-m-d');
            if (!isset($sh_date_map[$d])) $sh_date_map[$d]=[];
            $sh_date_map[$d][]=$sh;
        }
    }
    $att_res = mysqli_query($conn,
        "SELECT employee_id, COUNT(*) AS cnt FROM attendance WHERE att_date BETWEEN '$from_date' AND '$to_date' GROUP BY employee_id");
    $att_count_map = [];
    if ($att_res) while ($r=mysqli_fetch_assoc($att_res)) $att_count_map[(int)$r['employee_id']]=(int)$r['cnt'];
    $leave_map = [];
    $leave_res = mysqli_query($conn,
        "SELECT employee_id, SUM(days_count) AS total_leave FROM leave_applications
         WHERE status='Approved' AND (start_date BETWEEN '$from_date' AND '$to_date'
               OR end_date BETWEEN '$from_date' AND '$to_date'
               OR (start_date<='$from_date' AND end_date>='$to_date'))
         GROUP BY employee_id");
    if ($leave_res) while ($lv=mysqli_fetch_assoc($leave_res)) $leave_map[(int)$lv['employee_id']]=(float)$lv['total_leave'];
    $GLOBALS['_att_sh_date_map'] = $sh_date_map;
    $GLOBALS['_att_count_map']   = $att_count_map;
    $GLOBALS['_att_month_total'] = $month_total_days;
    $GLOBALS['_leave_map']       = $leave_map;
}

function calc_att_cc($emp, $sh_date_map, $att_count_map, $month_total_days, $leave_map) {
    $eid       = (int)$emp['id'];
    $att_count = $att_count_map[$eid] ?? 0;
    $holidays  = 0;
    foreach ($sh_date_map as $date => $shs) {
        foreach ($shs as $sh) {
            $applies = false;
            if ($sh['target_type']==='all') { $applies=true; }
            elseif (!empty($sh['target_ids'])) {
                $ids=json_decode($sh['target_ids'],true)??[];
                if ($sh['target_type']==='employee'       && in_array($eid,$ids)) $applies=true;
                if ($sh['target_type']==='staff_category' && in_array((int)($emp['staff_category_id']??0),$ids)) $applies=true;
                if ($sh['target_type']==='designation'    && in_array((int)($emp['designation_id']??0),$ids))    $applies=true;
            }
            if ($applies) { $holidays++; break; }
        }
    }
    $norm_days      = max(1, $month_total_days - $holidays);
    $rate           = round(($att_count / $norm_days) * 100, 2);
    $approved_leave = $leave_map[$eid] ?? 0;
    $no_pay_days    = max(0, $norm_days - $att_count - $approved_leave);
    return ['att'=>$att_count,'norm'=>$norm_days,'holidays'=>$holidays,'rate'=>$rate,'no_pay_days'=>$no_pay_days];
}

// ── EPF/ETF settings ──────────────────────────────────────────────────────
$epf_settings      = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM epf_etf_settings LIMIT 1"));
$epf_employer_rate = $epf_settings ? floatval($epf_settings['epf_employer']) : 12;
$epf_employee_rate = $epf_settings ? floatval($epf_settings['epf_employee']) : 8;
$etf_employer_rate = $epf_settings ? floatval($epf_settings['etf_employer']) : 3;

// ── Fetch CC/ACC/SACC employees ────────────────────────────────────────────
$cc_cat_res = mysqli_query($conn,"SELECT id FROM staff_categories WHERE category_code IN ($CC_CAT_SQL_IN) AND active=1");
$cc_cat_ids = [];
if ($cc_cat_res) while ($r=mysqli_fetch_assoc($cc_cat_res)) $cc_cat_ids[]=(int)$r['id'];
$cc_desig_ids = [];
if (!empty($cc_cat_ids)) {
    $cat_in    = implode(',',$cc_cat_ids);
    $desig_res = mysqli_query($conn,"SELECT id FROM designations WHERE staff_category_id IN ($cat_in) AND active=1");
    if ($desig_res) while ($r=mysqli_fetch_assoc($desig_res)) $cc_desig_ids[]=(int)$r['id'];
}
$where_parts = [];
if (!empty($cc_cat_ids))   $where_parts[] = "e.staff_category_id IN (".implode(',',$cc_cat_ids).")";
if (!empty($cc_desig_ids)) $where_parts[] = "e.designation_id IN (".implode(',',$cc_desig_ids).")";
if (empty($where_parts))   $where_parts[] = "1=0";
$where_sql = "(".implode(' OR ',$where_parts).") AND e.active=1";

$filter_status  = !empty($_GET['filter_status'])  ? mysqli_real_escape_string($conn,$_GET['filter_status'])  : '';
$filter_company = !empty($_GET['filter_company']) ? intval($_GET['filter_company']) : '';
$filter_catcode = !empty($_GET['filter_catcode']) ? mysqli_real_escape_string($conn,$_GET['filter_catcode']) : '';
if ($filter_status)  $where_sql .= " AND e.status='$filter_status'";
if ($filter_company) $where_sql .= " AND e.company_id=$filter_company";
if ($filter_catcode) $where_sql .= " AND sc.category_code='$filter_catcode'";

$sql = "SELECT e.*,
               c.company_name, c.company_code,
               b.branch_name, b.branch_code,
               d.designation_name, d.designation_code,
               sc.category_name, sc.category_code,
               cfg.ccfot_90, cfg.ccfot_91, cfg.ccfot_92, cfg.ccfot_93, cfg.ccfot_94, cfg.ccfot_95,
               cfg.credit_mgt_85, cfg.credit_mgt_90, cfg.credit_mgt_95,
               cfg.payee_chq_80,
               cfg.ssv_90, cfg.ssv_95,
               cfg.attendance_85, cfg.attendance_90,
               cfg.locus_admin,
               cfg.meal_reimbursement, cfg.mobile_reimbursement,
               cfg.insurance_amount, cfg.welfare_amount,
               cfg.punctuality_70, cfg.punctuality_80
        FROM employees e
        LEFT JOIN companies        c   ON e.company_id        = c.id
        LEFT JOIN branches         b   ON e.branch_id         = b.id
        LEFT JOIN designations     d   ON e.designation_id    = d.id
        LEFT JOIN staff_categories sc  ON e.staff_category_id = sc.id
        LEFT JOIN sr_salary_config cfg ON cfg.designation_id  = d.id
        WHERE $where_sql
        ORDER BY sc.category_code, c.company_code, e.employee_id";
$result    = mysqli_query($conn, $sql);
$employees = [];
while ($row = mysqli_fetch_assoc($result)) $employees[] = $row;

// ── Incentive entries ─────────────────────────────────────────────────────
$inc_entries = [];
if ($sel_period_id && !empty($employees)) {
    $emp_ids_str = implode(',',array_map(fn($e)=>(int)$e['id'],$employees));
    $ie_res      = mysqli_query($conn,
        "SELECT * FROM incentive_entries WHERE payroll_period_id=$sel_period_id AND employee_id IN ($emp_ids_str)");
    if ($ie_res) while ($ie=mysqli_fetch_assoc($ie_res))
        $inc_entries[(int)$ie['employee_id']][$ie['component_label']]=(float)$ie['amount'];
}

// ── Loan map ──────────────────────────────────────────────────────────────
$loan_map = [];
if (!empty($employees) && $active_period) {
    $emp_ids_all  = implode(',',array_map(fn($e)=>(int)$e['id'],$employees));
    $pp_first_day = sprintf('%04d-%02d-01',$active_period['year'],$active_period['month']);
    $loan_res     = mysqli_query($conn,
        "SELECT lr.*,
                COALESCE((SELECT SUM(lp.total_paid) FROM loan_payments lp WHERE lp.loan_request_id=lr.id),0) AS paid_so_far,
                COALESCE(lr.approved_at, lr.request_date) AS eff_date
         FROM loan_requests lr
         WHERE lr.employee_id IN ($emp_ids_all)
           AND lr.status='Approved'
           AND DATE_FORMAT(COALESCE(lr.approved_at, lr.request_date),'%Y-%m')
               < DATE_FORMAT('$pp_first_day','%Y-%m')
         ORDER BY lr.employee_id, lr.id");
    if ($loan_res) while ($ln=mysqli_fetch_assoc($loan_res)) {
        $eid         = (int)$ln['employee_id'];
        $total_repay = floatval($ln['total_repayment']) ?: floatval($ln['loan_amount']);
        $paid_so_far = floatval($ln['paid_so_far']);
        $balance     = round($total_repay - $paid_so_far, 2);
        if ($balance <= 0) continue;
        $monthly     = floatval($ln['monthly_instalment']);
        $this_deduct = min($monthly, $balance);
        if (!isset($loan_map[$eid])) $loan_map[$eid]=[];
        $loan_map[$eid][] = [
            'loan_id'=>(int)$ln['id'],'loan_type'=>$ln['loan_type'],
            'loan_amount'=>floatval($ln['loan_amount']),'monthly_instalment'=>$monthly,
            'total_repayment'=>$total_repay,'paid_so_far'=>$paid_so_far,'balance'=>$balance,
            'this_deduct'=>$this_deduct,'no_of_instalments'=>intval($ln['no_of_instalments']),
            'total_interest'=>floatval($ln['total_interest']),'eff_date'=>$ln['eff_date'],
        ];
    }
}

// ── Salary advances ───────────────────────────────────────────────────────
$advance_period_map = [];
if (!empty($employees) && $active_period) {
    $emp_ids_str = implode(',',array_map(fn($e)=>(int)$e['id'],$employees));
    $adv_from    = sprintf('%04d-%02d-01',$active_period['year'],$active_period['month']);
    $adv_to      = date('Y-m-t',strtotime($adv_from));
    $adv_res     = mysqli_query($conn,
        "SELECT employee_id, SUM(amount) AS total_advance FROM salary_advances
         WHERE status='Approved' AND employee_id IN ($emp_ids_str) AND request_date BETWEEN '$adv_from' AND '$adv_to'
         GROUP BY employee_id");
    if ($adv_res) while ($ar=mysqli_fetch_assoc($adv_res))
        $advance_period_map[(int)$ar['employee_id']]=floatval($ar['total_advance']);
}

// ── Saved overrides ───────────────────────────────────────────────────────
$saved_overrides = [];
if ($has_saved_sheet) {
    $or_res = mysqli_query($conn,
        "SELECT employee_id, override_excess, override_arrears, override_credit_r,
                override_cash_short, override_good_short,
                additional_earnings, additional_deductions, sheet_status
         FROM salary_sheet_entries WHERE payroll_period_id=$sel_period_id AND category_code IN ($CC_CAT_SQL_IN)");
    if ($or_res) while ($or=mysqli_fetch_assoc($or_res))
        $saved_overrides[(int)$or['employee_id']]=$or;
}

// ── CC incentive resolver helpers ─────────────────────────────────────────
function cc_resolve_ccfot($inc, $eid, $cfg) {
    $earned = $inc[$eid]['__incent__ccfot'] ?? 0;
    if ($earned > 0) return floatval($earned);
    $ach = $inc[$eid]['__iach__ccfot'] ?? 0;
    if ($ach <= 0) return 0;
    foreach ([[95,'ccfot_95'],[94,'ccfot_94'],[93,'ccfot_93'],[92,'ccfot_92'],[91,'ccfot_91'],[90,'ccfot_90']] as [$t,$f]) {
        if ($ach >= $t && floatval($cfg[$f]??0) > 0) return floatval($cfg[$f]);
    }
    return 0;
}
function cc_resolve_credit_mgt($inc, $eid, $cfg) {
    $earned = $inc[$eid]['__incent__credit_mgt'] ?? 0;
    if ($earned > 0) return floatval($earned);
    $ach = $inc[$eid]['__iach__credit_mgt'] ?? 0;
    if ($ach <= 0) return 0;
    foreach ([[95,'credit_mgt_95'],[90,'credit_mgt_90'],[85,'credit_mgt_85']] as [$t,$f]) {
        if ($ach >= $t && floatval($cfg[$f]??0) > 0) return floatval($cfg[$f]);
    }
    return 0;
}
function cc_resolve_payee_chq($inc, $eid, $cfg) {
    $earned = $inc[$eid]['__incent__payee_chq'] ?? 0;
    if ($earned > 0) return floatval($earned);
    $ach = $inc[$eid]['__iach__payee_chq'] ?? 0;
    return ($ach >= 80 && floatval($cfg['payee_chq_80']??0) > 0) ? floatval($cfg['payee_chq_80']) : 0;
}
function cc_resolve_ssv($inc, $eid, $cfg) {
    $earned = $inc[$eid]['__incent__ssv_cc'] ?? 0;
    if ($earned > 0) return floatval($earned);
    $ach = $inc[$eid]['__iach__ssv_cc'] ?? 0;
    if ($ach <= 0) return 0;
    foreach ([[95,'ssv_95'],[90,'ssv_90']] as [$t,$f]) {
        if ($ach >= $t && floatval($cfg[$f]??0) > 0) return floatval($cfg[$f]);
    }
    return 0;
}
function cc_resolve_attendance($inc, $eid, $cfg, $att_rate = 0) {
    // Manual override from incentive_entries takes priority if an amount was entered
    $earned = $inc[$eid]['__incent__attendance_cc'] ?? 0;
    if ($earned > 0) return floatval($earned);
    // AUTOMATIC: resolve from the CC employee's actual computed attendance rate
    // (calc_att_cc's 'rate') against the salary config's base attendance
    // thresholds (attendance_85 / attendance_90) — no manual entry required.
    $rate = floatval($att_rate);
    if ($rate <= 0) {
        // fallback: manually entered achievement % (legacy path)
        $rate = floatval($inc[$eid]['__iach__attendance_cc'] ?? 0);
    }
    if ($rate <= 0) return 0;
    foreach ([[90,'attendance_90'],[85,'attendance_85']] as [$t,$f]) {
        if ($rate >= $t && floatval($cfg[$f]??0) > 0) return floatval($cfg[$f]);
    }
    return 0;
}
function cc_resolve_locus($inc, $eid, $cfg) {
    $earned = $inc[$eid]['__incent__locus_admin'] ?? 0;
    if ($earned > 0) return floatval($earned);
    $ach = $inc[$eid]['__iach__locus_admin'] ?? 0;
    return ($ach >= 100 && floatval($cfg['locus_admin']??0) > 0) ? floatval($cfg['locus_admin']) : 0;
}

// ── Build row data ────────────────────────────────────────────────────────
$rows = [];
foreach ($employees as $emp) {
    $eid = (int)$emp['id'];
    $sh  = $GLOBALS['_att_sh_date_map'] ?? [];
    $ac  = $GLOBALS['_att_count_map']   ?? [];
    $mtd = $GLOBALS['_att_month_total'] ?? 30;
    $lm  = $GLOBALS['_leave_map']       ?? [];
    $att = $active_period ? calc_att_cc($emp,$sh,$ac,$mtd,$lm) : ['att'=>0,'norm'=>0,'holidays'=>0,'rate'=>0,'no_pay_days'=>0];

    $override_excess     = isset($saved_overrides[$eid]) && $saved_overrides[$eid]['override_excess']      !== null ? floatval($saved_overrides[$eid]['override_excess'])      : null;
    $override_arrears    = isset($saved_overrides[$eid]) && $saved_overrides[$eid]['override_arrears']     !== null ? floatval($saved_overrides[$eid]['override_arrears'])     : null;
    $override_credit_r   = isset($saved_overrides[$eid]) && $saved_overrides[$eid]['override_credit_r']   !== null ? floatval($saved_overrides[$eid]['override_credit_r'])    : null;
    $override_cash_short = isset($saved_overrides[$eid]) && $saved_overrides[$eid]['override_cash_short'] !== null ? floatval($saved_overrides[$eid]['override_cash_short'])  : null;
    $override_good_short = isset($saved_overrides[$eid]) && $saved_overrides[$eid]['override_good_short'] !== null ? floatval($saved_overrides[$eid]['override_good_short'])  : null;
    $add_earn_saved      = !empty($saved_overrides[$eid]['additional_earnings'])   ? json_decode($saved_overrides[$eid]['additional_earnings'],  true) : [];
    $add_ded_saved       = !empty($saved_overrides[$eid]['additional_deductions']) ? json_decode($saved_overrides[$eid]['additional_deductions'],true) : [];

    $basic       = floatval($emp['basic_salary']           ?? 0);
    $insurance   = floatval($emp['insurance_amount']       ?? 0);
    $welfare_amt = floatval($emp['welfare_amount']         ?? 0);
    $meal        = floatval($emp['meal_reimbursement']     ?? $emp['reimbursement_meal']    ?? 0);
    $mobile      = floatval($emp['mobile_reimbursement']   ?? $emp['reimbursement_mobile']  ?? 0);
    $arrears     = $override_arrears !== null ? $override_arrears : floatval($emp['arrears_salary'] ?? 0);

    // Incentives from incentive_entry or salary config tiers
    $ccfot_amt      = cc_resolve_ccfot($inc_entries,$eid,$emp);
    $credit_mgt_amt = cc_resolve_credit_mgt($inc_entries,$eid,$emp);
    $payee_chq_amt  = cc_resolve_payee_chq($inc_entries,$eid,$emp);
    $ssv_cc_amt     = cc_resolve_ssv($inc_entries,$eid,$emp);
    $att_inc_amt    = cc_resolve_attendance($inc_entries,$eid,$emp,$att['rate']);
    $locus_admin_amt= cc_resolve_locus($inc_entries,$eid,$emp);

    // Attendance rate-based meal reimbursement (meal × att_rate)
    $norm_days   = max(1, $att['norm']);
    $per_day     = $basic > 0 ? round($basic / $norm_days, 4) : 0;
    $no_pay_days = $att['no_pay_days'];
    $no_pay_amt  = $per_day > 0 ? round($per_day * $no_pay_days, 2) : 0;
    // Meal = cfg_meal × (att/norm)
    $meal_calc   = ($meal > 0 && $norm_days > 0) ? round(($att['att'] / $norm_days) * $meal, 2) : $meal;

    $add_earn_total = 0; $add_ded_total = 0;
    if (is_array($add_earn_saved)) foreach ($add_earn_saved as $ae) $add_earn_total += floatval($ae['amount']??0);
    if (is_array($add_ded_saved))  foreach ($add_ded_saved  as $ad) $add_ded_total  += floatval($ad['amount']??0);

    $total_earnings = $basic + $ccfot_amt + $credit_mgt_amt + $payee_chq_amt
                    + $ssv_cc_amt + $att_inc_amt + $locus_admin_amt
                    + $meal_calc + $mobile + $arrears + $add_earn_total;

    $epf_emp   = round($basic * $epf_employee_rate / 100, 2);
    $epf_er    = round($basic * $epf_employer_rate / 100, 2);
    $etf_er    = round($basic * $etf_employer_rate / 100, 2);
    $w_loan    = floatval($emp['welfare_loan_deduction'] ?? 0);
    $w_soc     = floatval($emp['welfare_society']        ?? 0);
    $donations = floatval($emp['donations']              ?? 0);
    $sal_adv   = $advance_period_map[$eid] ?? floatval($emp['salary_advance'] ?? 0);
    $advance_from_entry = isset($advance_period_map[$eid]);
    $credit_r   = $override_credit_r   !== null ? $override_credit_r   : floatval($emp['credit_recovery'] ?? 0);
    $cash_short = $override_cash_short !== null ? $override_cash_short : floatval($emp['cash_short']      ?? 0);
    $good_short = $override_good_short !== null ? $override_good_short : floatval($emp['good_short']      ?? 0);
    $retention  = floatval($emp['retention']     ?? 0);
    $excess_p   = $override_excess !== null ? $override_excess : floatval($emp['excess_payment'] ?? 0);
    $emp_loans  = $loan_map[$eid] ?? [];
    $loan_ded   = array_sum(array_column($emp_loans,'this_deduct')) ?: floatval($emp['loan_deduction'] ?? 0);

    $total_deductions = $epf_emp + $welfare_amt + $w_loan + $w_soc + $donations
                      + $sal_adv + $loan_ded + $credit_r + $cash_short + $good_short
                      + $no_pay_amt + $retention + $excess_p + $add_ded_total;
    $net_salary    = $total_earnings - $total_deductions;
    $bonus         = $basic > 0 ? round($basic / 12, 2) : 0;
    $gratuity      = $basic > 0 ? round($basic / 24, 2) : 0;
    $cost_bp       = $epf_er + $etf_er + $meal_calc + $mobile + $bonus + $basic;
    $total_benefit = $basic + $ccfot_amt + $credit_mgt_amt + $payee_chq_amt
                   + $ssv_cc_amt + $att_inc_amt + $locus_admin_amt + $meal_calc + $mobile
                   + $insurance + $epf_er + $etf_er + $bonus + $gratuity;

    $rows[$eid] = compact(
        'basic','insurance','welfare_amt','meal_calc','mobile','arrears',
        'ccfot_amt','credit_mgt_amt','payee_chq_amt','ssv_cc_amt','att_inc_amt','locus_admin_amt',
        'no_pay_days','no_pay_amt','per_day',
        'total_earnings','epf_emp','w_loan','w_soc','donations',
        'sal_adv','loan_ded','credit_r','cash_short','good_short','retention','excess_p',
        'total_deductions','net_salary','total_benefit','epf_er','etf_er',
        'bonus','gratuity','cost_bp','att','norm_days',
        'advance_from_entry','add_earn_saved','add_ded_saved','add_earn_total','add_ded_total',
        'emp_loans','meal'
    );
}

// ── Grand Totals ──────────────────────────────────────────────────────────
$totals = ['earn'=>0,'ded'=>0,'net'=>0,'ben'=>0,'no_pay'=>0,'loan_ded'=>0,'cash_short'=>0,'good_short'=>0];
$total_loan_count = 0;
foreach ($rows as $r) {
    $totals['earn']       += $r['total_earnings'];
    $totals['ded']        += $r['total_deductions'];
    $totals['net']        += $r['net_salary'];
    $totals['ben']        += $r['total_benefit'];
    $totals['no_pay']     += $r['no_pay_amt'];
    $totals['loan_ded']   += $r['loan_ded'];
    $totals['cash_short'] += $r['cash_short'];
    $totals['good_short'] += $r['good_short'];
    $total_loan_count += count($r['emp_loans']);
}
$cnt_active = count(array_filter($employees, fn($e)=>in_array($e['status'],['Probation','Permanent'])));

$companies_res = mysqli_query($conn,"SELECT id, company_code, company_name FROM companies WHERE active=1 ORDER BY company_name");
$month_names   = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

include 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Space+Mono:wght@400;700&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<style>
:root{
    --ink:#0a0a0a;--ink2:#444;--ink3:#888;
    --bg:#f2f1ee;--surface:#fff;--border:#dddbd4;--border2:#c8c6bf;
    --acc:#0c4a6e;
    --mono:'Space Mono',monospace;--sans:'DM Sans',sans-serif;--display:'Syne',sans-serif;
    --r:6px;--r-lg:12px;
    --shadow-sm:0 1px 3px rgba(0,0,0,.06),0 2px 8px rgba(0,0,0,.04);
    --shadow:0 2px 8px rgba(0,0,0,.08),0 8px 24px rgba(0,0,0,.05);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--sans);background:var(--bg);color:var(--ink);}

/* HEADER */
.cc-page-hdr{background:var(--acc);color:#fff;padding:22px 28px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;border-radius:0 0 var(--r-lg) var(--r-lg);margin-bottom:20px;}
.cc-page-hdr-title{font-family:var(--display);font-size:22px;font-weight:800;letter-spacing:-.2px;display:flex;align-items:center;gap:10px;}
.cc-page-hdr-sub{font-size:12px;color:#7dd3fc;margin-top:3px;}
.cc-page-hdr-right{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.period-pill{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:8px;padding:8px 14px;}
.period-pill-label{font-size:10px;color:#7dd3fc;font-weight:700;text-transform:uppercase;letter-spacing:.5px;}
.period-pill select{background:transparent;border:none;outline:none;color:#fff;font-size:14px;font-weight:700;font-family:var(--sans);cursor:pointer;}
.period-pill select option{background:#0c4a6e;color:#fff;}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:var(--r);font-size:13px;font-weight:600;font-family:var(--sans);cursor:pointer;transition:all .18s;border:none;text-decoration:none;white-space:nowrap;}
.btn-white{background:#fff;color:var(--ink);}.btn-white:hover{background:#f0f0ea;}
.btn-outline{background:transparent;color:#fff;border:1.5px solid rgba(255,255,255,.25);}.btn-outline:hover{background:rgba(255,255,255,.08);}
.btn-green{background:#22c55e;color:#fff;}.btn-green:hover{background:#16a34a;}
.btn-ghost{background:var(--bg);color:var(--ink2);border:1px solid var(--border);}.btn-ghost:hover{background:var(--border);}
.btn-sm{padding:7px 13px;font-size:12px;}
.btn-primary{background:var(--ink);color:#fff;}.btn-primary:hover{background:#333;}
.btn-blue{background:#2563eb;color:#fff;}.btn-blue:hover{background:#1d4ed8;}
.btn-red{background:#dc2626;color:#fff;}.btn-red:hover{background:#b91c1c;}
.btn-amber{background:#d97706;color:#fff;}.btn-amber:hover{background:#b45309;}
.btn-indigo{background:#4338ca;color:#fff;}.btn-indigo:hover{background:#3730a3;}
.btn-cyan{background:#0891b2;color:#fff;}.btn-cyan:hover{background:#0e7490;}
.btn:disabled{opacity:.55;cursor:not-allowed;}

/* BANNERS */
.sheet-banner{border-radius:var(--r-lg);padding:14px 20px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;font-size:13px;font-weight:600;}
.sheet-banner-saved{background:#e0f2fe;border:1.5px solid #7dd3fc;color:#075985;}
.sheet-banner-confirmed{background:#dbeafe;border:1.5px solid #93c5fd;color:#1e3a8a;}
.sheet-banner-unsaved{background:#fef3c7;border:1.5px solid #fde68a;color:#78350f;}
.sheet-banner-left{display:flex;align-items:center;gap:10px;}
.sheet-banner-actions{display:flex;gap:8px;flex-wrap:wrap;}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:800;}
.status-pending{background:#e0f2fe;color:#0369a1;border:1px solid #7dd3fc;}
.status-confirmed{background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;}

/* FILTERS */
.filter-bar{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);margin-bottom:16px;box-shadow:var(--shadow-sm);overflow:hidden;}
.filter-toggle{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;cursor:pointer;user-select:none;font-size:13px;font-weight:600;color:var(--ink2);}
.filter-toggle:hover{background:var(--bg);}
.filter-body{display:none;padding:0 18px 16px;border-top:1px solid var(--border);}
.filter-body.open{display:block;}
.filter-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:14px;}
.filter-group label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--ink3);margin-bottom:5px;}
.filter-select{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:var(--r);font-size:13px;font-family:var(--sans);background:#fff;color:var(--ink);}
.filter-select:focus{outline:none;border-color:var(--ink);}

/* STATS */
.stats-row{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
.stat-chip{background:var(--surface);border:1px solid var(--border);border-radius:30px;padding:6px 14px;display:flex;align-items:center;gap:7px;font-size:13px;font-weight:500;color:var(--ink2);box-shadow:var(--shadow-sm);}
.stat-chip strong{color:var(--ink);font-weight:800;}
.stat-chip .dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.dot-green{background:#22c55e;}.dot-blue{background:#3b82f6;}.dot-amber{background:#f59e0b;}
.dot-red{background:#ef4444;}.dot-cyan{background:#0891b2;}.dot-orange{background:#f97316;}

/* TOTALS SECTION */
.totals-section{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:20px 24px;margin-bottom:16px;box-shadow:var(--shadow-sm);}
.totals-section-title{font-family:var(--display);font-size:13px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:8px;color:var(--ink2);}
.totals-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:12px;}
.total-card{border-radius:var(--r-lg);padding:14px 16px;border:1px solid var(--border);}
.total-card-earn{background:#e0f2fe;border-color:#7dd3fc;}
.total-card-ded{background:#fdf0f0;border-color:#fca5a5;}
.total-card-net{background:#dcfce7;border-color:#86efac;}
.total-card-ben{background:#e0f2fe;border-color:#7dd3fc;}
.total-card-nopay{background:#fff7ed;border-color:#fed7aa;}
.total-card-short{background:#fff1f2;border-color:#fecdd3;}
.total-card-loan{background:#faf5ff;border-color:#c4b5fd;}
.total-card-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.total-card-earn .total-card-label{color:#075985;}
.total-card-ded  .total-card-label{color:#3d0f0f;}
.total-card-net  .total-card-label{color:#065f46;}
.total-card-ben  .total-card-label{color:#075985;}
.total-card-nopay .total-card-label{color:#9a3412;}
.total-card-short .total-card-label{color:#9f1239;}
.total-card-loan  .total-card-label{color:#4c1d95;}
.total-card-value{font-family:var(--mono);font-size:14px;font-weight:700;}
.total-card-earn .total-card-value{color:#0c4a6e;}
.total-card-ded  .total-card-value{color:#991b1b;}
.total-card-net  .total-card-value{color:#065f46;}
.total-card-ben  .total-card-value{color:#075985;}
.total-card-nopay .total-card-value{color:#ea580c;}
.total-card-short .total-card-value{color:#be123c;}
.total-card-loan  .total-card-value{color:#7c3aed;}

/* MAIN TABLE CARD */
.salary-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);box-shadow:var(--shadow);overflow:hidden;margin-bottom:24px;}
.salary-card-header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:#f0f9ff;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:10px;}
.salary-card-title{font-family:var(--display);font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;}
.salary-scroll{overflow-x:auto;}
.salary-table{width:100%;border-collapse:collapse;font-size:12px;min-width:2800px;}

/* STICKY COLS */
.col-s1{position:sticky;left:0;background:var(--surface);z-index:2;min-width:120px;}
.col-s2{position:sticky;left:120px;background:var(--surface);z-index:2;min-width:90px;}
.col-s3{position:sticky;left:210px;background:var(--surface);z-index:2;min-width:90px;}
.col-s4{position:sticky;left:300px;background:var(--surface);z-index:2;min-width:170px;}
.col-s5{position:sticky;left:470px;background:var(--surface);z-index:2;min-width:130px;border-right:2px solid var(--border2);}
.salary-table tbody tr:hover .col-s1,.salary-table tbody tr:hover .col-s2,
.salary-table tbody tr:hover .col-s3,.salary-table tbody tr:hover .col-s4,
.salary-table tbody tr:hover .col-s5{background:#f0f9ff;}

/* TABLE HEADERS */
.salary-table thead th{padding:9px 12px;text-align:center;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;background:#18181b;color:#9ca3af;border-right:1px solid #2d2d30;white-space:nowrap;position:sticky;top:0;z-index:3;}
.salary-table thead th.th-group{font-size:11px;font-weight:800;border-bottom:1px solid #3f3f46;padding:10px 12px;}
.salary-table thead th.th-emp{background:#111;color:#e5e7eb;text-align:left;border-right:none;}
.th-att{background:#0f2a4a !important;color:#93c5fd !important;}
.th-earn{background:#042f2e !important;color:#5eead4 !important;}
.th-inc{background:#1e0a30 !important;color:#d8b4fe !important;}
.th-ded{background:#3d0f0f !important;color:#fca5a5 !important;}
.th-short{background:#3d0a1a !important;color:#fda4af !important;}
.th-net{background:#14401f !important;color:#6ee7b7 !important;}
.th-ben{background:#0c2442 !important;color:#93c5fd !important;}
.salary-table thead th.col-s1,.salary-table thead th.col-s2,.salary-table thead th.col-s3,
.salary-table thead th.col-s4,.salary-table thead th.col-s5{z-index:5;}

/* TABLE ROWS */
.salary-table tbody tr{border-bottom:1px solid #f2f2ee;transition:background .1s;cursor:pointer;}
.salary-table tbody tr:hover{background:#f0f9ff;}
.salary-table tbody td{padding:9px 12px;vertical-align:middle;border-right:1px solid #f2f2ee;text-align:right;}
.salary-table tbody td.td-left{text-align:left;}
.salary-table tbody td.td-center{text-align:center;}
.td-att{background:rgba(15,42,74,.025);}
.td-earn{background:rgba(4,47,46,.025);}
.td-inc{background:rgba(30,10,48,.025);}
.td-ded{background:rgba(61,15,15,.025);}
.td-short{background:rgba(239,68,68,.05);}
.td-net{background:rgba(20,64,31,.04);}
.td-ben{background:rgba(12,36,66,.025);}

/* TOTALS ROW */
.salary-table tbody tr.tr-total{background:#18181b !important;font-weight:700;border-top:2px solid #3f3f46;}
.salary-table tbody tr.tr-total td{color:#e5e7eb;font-family:var(--mono);font-size:11px;padding:10px;border-right:1px solid #2d2d30;}
.salary-table tbody tr.tr-total .col-s1,.salary-table tbody tr.tr-total .col-s2,
.salary-table tbody tr.tr-total .col-s3,.salary-table tbody tr.tr-total .col-s4,
.salary-table tbody tr.tr-total .col-s5{background:#18181b;}

/* MONEY */
.money-earn{font-family:var(--mono);font-size:11.5px;font-weight:600;color:#0c4a6e;}
.money-inc{font-family:var(--mono);font-size:11.5px;font-weight:600;color:#7c3aed;}
.money-ded{font-family:var(--mono);font-size:11.5px;font-weight:600;color:#991b1b;}
.money-short{font-family:var(--mono);font-size:11.5px;font-weight:700;color:#be123c;}
.money-net{font-family:var(--mono);font-size:12.5px;font-weight:700;color:#065f46;}
.money-ben{font-family:var(--mono);font-size:12px;font-weight:700;color:#0c4a6e;}
.money-zero{color:#ccc;font-family:var(--mono);font-size:11px;}
.money-nopay{font-family:var(--mono);font-size:11.5px;font-weight:700;color:#ea580c;}
.money-loan{font-family:var(--mono);font-size:11.5px;font-weight:700;color:#7c3aed;}

/* EMP CELL */
.emp-id-tag{font-family:var(--mono);font-size:10px;font-weight:700;color:#0369a1;background:#e0f2fe;padding:1px 5px;border-radius:3px;}
.emp-name-cell{font-size:12px;font-weight:700;color:var(--ink);}
.emp-meta{font-size:10px;color:var(--ink3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;}
.s-badge{display:inline-block;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:600;}
.s-probation{background:#fef3c7;color:#92400e;}
.s-permanent{background:#dcfce7;color:#15803d;}
.s-resigned{background:#f3f4f6;color:#6b7280;}
.s-terminated{background:#fee2e2;color:#991b1b;}
.cat-chip{display:inline-block;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;}
.cat-cc{background:#e0f2fe;color:#0369a1;}
.cat-acc{background:#fef3c7;color:#92400e;}
.cat-sacc{background:#fae8ff;color:#86198f;}
.att-pill{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap;font-family:var(--mono);}
.att-high{background:#dcfce7;color:#14532d;}
.att-mid{background:#fef3c7;color:#78350f;}
.att-low{background:#fee2e2;color:#7f1d1d;}
.att-zero{background:#f3f4f6;color:#9ca3af;}
.short-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;background:#fff1f2;color:#be123c;border:1px solid #fecdd3;white-space:nowrap;}
.short-badge-nil{background:#f3f4f6;color:#9ca3af;border-color:#e5e7eb;}
.row-view-btn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:var(--r);border:1px solid var(--border);background:var(--surface);color:var(--ink3);cursor:pointer;font-size:11px;transition:all .15s;text-decoration:none;}
.row-view-btn:hover{background:#0891b2;color:#fff;border-color:#0891b2;}
.expand-toggle-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:var(--r);border:1px solid var(--border);background:var(--surface);color:var(--ink2);cursor:pointer;font-size:11px;font-weight:600;transition:all .15s;font-family:var(--sans);}
.expand-toggle-btn:hover,.expand-toggle-btn.active{background:#0891b2;color:#fff;border-color:#0891b2;}
.col-expanded{display:none;}
.salary-table.expanded-view .col-expanded{display:table-cell;}

/* MODAL */
.modal-bg{display:none;position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,.6);align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(4px);}
.modal-bg.show{display:flex;}
.modal-box{background:var(--surface);border-radius:16px;width:100%;max-width:1200px;max-height:94vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 32px 100px rgba(0,0,0,.3);animation:modalIn .3s cubic-bezier(.22,.68,0,1.2);}
@keyframes modalIn{from{opacity:0;transform:translateY(24px) scale(.96)}to{opacity:1;transform:none}}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:20px 26px;border-bottom:1px solid var(--border);background:#f0f9ff;flex-shrink:0;}
.modal-emp-name{font-family:var(--display);font-size:18px;font-weight:800;}
.modal-emp-meta{font-size:12px;color:var(--ink3);display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:4px;}
.modal-close{background:none;border:none;cursor:pointer;color:var(--ink3);font-size:18px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;border-radius:var(--r);}
.modal-close:hover{background:#f3f4f6;color:var(--ink);}
.modal-body{overflow-y:auto;flex:1;}
.modal-footer{padding:16px 26px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:10px;flex-shrink:0;background:#f0f9ff;}

/* MODAL BANDS */
.net-hero-band{background:linear-gradient(135deg,#0c4a6e 0%,#0e7490 50%,#0c4a6e 100%);padding:24px 26px;display:grid;grid-template-columns:1fr auto 1fr auto 1fr auto 1fr;gap:0;align-items:center;}
.net-hero-item{text-align:center;padding:4px 8px;}
.net-hero-divider{width:1px;background:rgba(255,255,255,.2);align-self:stretch;margin:0 4px;}
.net-hero-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:rgba(255,255,255,.5);margin-bottom:5px;}
.net-hero-value{font-family:var(--mono);font-size:18px;font-weight:800;color:#a5f3fc;}
.net-hero-value.v-earn{color:#a5f3fc;}.net-hero-value.v-ded{color:#fca5a5;}
.net-hero-value.v-net{color:#fff;font-size:24px;}.net-hero-value.v-ben{color:#bae6fd;}.net-hero-value.v-bp{color:#fcd34d;}
.att-info-band{padding:14px 26px;display:flex;gap:16px;background:#f0f9ff;border-bottom:1px solid #bae6fd;flex-wrap:wrap;}
.att-info-card{background:#fff;border:1px solid #7dd3fc;border-radius:var(--r-lg);padding:12px 18px;display:flex;flex-direction:column;gap:3px;flex:1;min-width:120px;}
.att-info-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#9ca3af;}
.att-info-value{font-family:var(--mono);font-size:18px;font-weight:800;color:#0c4a6e;}
.att-info-sub{font-size:10px;color:var(--ink3);}

/* CC INCENTIVE BAND */
.cc-inc-band{padding:14px 26px;background:#faf5ff;border-bottom:1px solid #e9d5ff;}
.cc-inc-band-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#7c3aed;margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.cc-inc-cards{display:flex;gap:10px;flex-wrap:wrap;}
.cc-inc-card{background:#fff;border:1.5px solid #c4b5fd;border-radius:10px;padding:12px 16px;flex:1;min-width:160px;}
.cc-inc-card-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;margin-bottom:6px;}
.cc-inc-tier{display:flex;justify-content:space-between;font-size:11px;padding:4px 8px;border-radius:5px;}
.cc-inc-tier.active{background:#ede9fe;font-weight:700;}
.cc-inc-tier.active .tl{color:#7c3aed;}.cc-inc-tier.active .tv{color:#6d28d9;}
.cc-inc-tier .tl{color:#9ca3af;}.cc-inc-tier .tv{font-family:var(--mono);color:#6b7280;}
.cc-earned{margin-top:8px;padding:6px 10px;background:#ede9fe;border-radius:6px;display:flex;justify-content:space-between;align-items:center;}
.cc-earned-label{font-size:11px;font-weight:700;color:#7c3aed;}
.cc-earned-val{font-family:var(--mono);font-size:13px;font-weight:800;color:#4c1d95;}

/* SHORT BAND */
.short-band{padding:16px 26px;background:#fff1f2;border-bottom:1px solid #fecdd3;}
.short-band-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#be123c;margin-bottom:12px;display:flex;align-items:center;gap:7px;}
.short-input-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.short-input-field{display:flex;flex-direction:column;gap:5px;}
.short-input-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;}
.short-input-label.cash{color:#dc2626;}.short-input-label.good{color:#c2410c;}
.short-input-wrap{display:flex;align-items:stretch;border:1.5px solid #fecdd3;border-radius:var(--r);overflow:hidden;background:#fff;transition:all .2s;}
.short-input-wrap:focus-within{border-color:#dc2626;box-shadow:0 0 0 3px rgba(220,38,38,.1);}
.short-input-pfx{background:#fff1f2;color:#be123c;font-size:10px;font-weight:700;padding:0 8px;display:flex;align-items:center;border-right:1.5px solid #fecdd3;font-family:var(--mono);white-space:nowrap;}
.short-input-pfx.good{background:#fff7ed;color:#c2410c;border-right-color:#fed7aa;}
.short-input-wrap.good{border-color:#fed7aa;}.short-input-wrap.good:focus-within{border-color:#c2410c;box-shadow:0 0 0 3px rgba(194,65,12,.1);}
.short-inp{border:none;outline:none;padding:9px 10px;font-size:13px;font-family:var(--mono);font-weight:700;color:#be123c;background:transparent;width:100%;-moz-appearance:textfield;}
.short-inp.good{color:#c2410c;}
.short-inp::-webkit-outer-spin-button,.short-inp::-webkit-inner-spin-button{-webkit-appearance:none;}
.short-info{margin-top:4px;font-size:10px;color:#9f1239;}

/* LOAN BAND */
.loan-band{padding:14px 26px;background:#faf5ff;border-bottom:1px solid #e9d5ff;}
.loan-band-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#7c3aed;margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.loan-cards-row{display:flex;gap:10px;flex-wrap:wrap;}
.loan-detail-card{background:#fff;border:1.5px solid #c4b5fd;border-radius:10px;padding:12px 16px;min-width:220px;flex:1;}
.loan-detail-card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;}
.loan-detail-card-type{font-size:11px;font-weight:800;color:#6d28d9;}
.loan-detail-row{display:flex;justify-content:space-between;font-size:11.5px;padding:3px 0;border-bottom:1px solid #f5f0ff;}
.loan-detail-row:last-child{border-bottom:none;}
.loan-detail-label{color:#9ca3af;}
.loan-detail-val{font-family:var(--mono);font-weight:700;color:var(--ink);}
.loan-detail-val.v-balance{color:#7c3aed;}.loan-detail-val.v-deduct{color:#991b1b;}.loan-detail-val.v-paid{color:#166534;}
.loan-progress-bar{height:6px;background:#e9d5ff;border-radius:4px;overflow:hidden;margin-top:6px;}
.loan-progress-fill{height:100%;background:linear-gradient(90deg,#7c3aed,#a78bfa);border-radius:4px;}
.loan-no-loans{font-size:13px;color:#9ca3af;display:flex;align-items:center;gap:7px;padding:8px 0;}

/* OVERRIDES */
.modal-edit-section{padding:16px 26px;background:#fdf4ff;border-top:1px solid #e879f9;border-bottom:1px solid #e879f9;}
.modal-edit-section-title{font-family:var(--display);font-size:12px;font-weight:800;color:#86198f;margin-bottom:12px;display:flex;align-items:center;gap:8px;text-transform:uppercase;letter-spacing:.4px;}
.modal-edit-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}
.modal-edit-field{display:flex;flex-direction:column;gap:5px;}
.modal-edit-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#86198f;}
.modal-edit-input{padding:9px 12px;border:1.5px solid #f0abfc;border-radius:var(--r);font-size:13px;font-family:var(--mono);font-weight:600;background:#fff;color:#4c1d95;outline:none;transition:border-color .15s,box-shadow .15s;}
.modal-edit-input:focus{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.12);}

/* MODAL SECTIONS */
.modal-sections{padding:20px 26px;display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.modal-section{border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden;}
.ms-header{display:flex;align-items:center;gap:8px;padding:11px 16px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);}
.ms-earn{background:#e0f2fe;color:#075985;}.ms-ded{background:#fdf0f0;color:#3d0f0f;}
.ms-att{background:#f0f9ff;color:#0369a1;}.ms-ben{background:#fffbeb;color:#78350f;}
.ms-body{padding:4px 0;}
.ms-row{display:flex;justify-content:space-between;align-items:center;padding:7px 16px;border-bottom:1px solid #f8f8f6;font-size:12.5px;}
.ms-row:last-child{border-bottom:none;}
.ms-row-label{color:var(--ink2);}
.ms-row-value{font-family:var(--mono);font-weight:600;font-size:11.5px;}
.ms-row.ms-zero .ms-row-value{color:#d1d5db;}
.ms-row.ms-earn-val .ms-row-value{color:#0c4a6e;}
.ms-row.ms-inc-val  .ms-row-value{color:#7c3aed;}
.ms-row.ms-ded-val  .ms-row-value{color:#991b1b;}
.ms-row.ms-short-val .ms-row-value{color:#be123c;font-weight:700;}
.ms-row.ms-short-val{background:#fff1f2;}
.ms-row.ms-cr-val .ms-row-value{color:#86198f;}
.ms-row.ms-loan-val .ms-row-value{color:#7c3aed;}
.ms-total{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;font-weight:700;font-size:13px;border-top:2px solid var(--border2);}
.ms-total-earn{background:#e0f2fe;color:#075985;}.ms-total-ded{background:#fee2e2;color:#991b1b;}
.ms-total-att{background:#f0f9ff;color:#0369a1;}.ms-total-ben{background:#fffbeb;color:#78350f;}
.ms-total span:last-child{font-family:var(--mono);font-size:14px;}

/* ADD ROWS */
.add-rows-section{padding:4px 0;}
.add-row-item{display:flex;align-items:center;gap:8px;padding:6px 16px;border-bottom:1px solid #f0e8ff;}
.add-row-item:last-child{border-bottom:none;}
.add-row-desc{flex:1;padding:6px 10px;border:1.5px solid #e9d5ff;border-radius:5px;font-size:12px;font-family:var(--sans);background:#fff;color:var(--ink);outline:none;}
.add-row-desc:focus{border-color:#7c3aed;}
.add-row-amount{width:110px;padding:6px 10px;border:1.5px solid #e9d5ff;border-radius:5px;font-size:12px;font-family:var(--mono);font-weight:600;background:#fff;color:#6d28d9;outline:none;text-align:right;}
.add-row-amount:focus{border-color:#7c3aed;}
.add-row-amount.is-ded{color:#991b1b;}
.add-row-remove{width:26px;height:26px;border:none;border-radius:50%;background:#fee2e2;color:#dc2626;font-size:13px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.add-rows-footer{padding:8px 16px;display:flex;align-items:center;justify-content:space-between;background:#faf5ff;border-top:1px solid #f0e8ff;}
.add-row-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border-radius:var(--r);border:1.5px dashed #c4b5fd;background:transparent;color:#7c3aed;font-size:11px;font-weight:700;cursor:pointer;font-family:var(--sans);}
.add-row-btn:hover{background:#f5f0ff;border-color:#7c3aed;}
.add-row-total{font-family:var(--mono);font-size:12px;font-weight:700;color:#4c1d95;}
.modal-update-bar{padding:14px 26px;background:#fdf4ff;border-top:1px solid #e879f9;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-shrink:0;}
.modal-update-info{font-size:12px;color:#86198f;font-weight:600;display:flex;align-items:center;gap:7px;}
.modal-update-status{font-size:11px;font-weight:700;display:flex;align-items:center;gap:5px;}
.upd-saved{color:#15803d;}.upd-error{color:#991b1b;}.upd-saving{color:#d97706;}

/* CONFIRM MODALS */
.confirm-bg{display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.6);align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(4px);}
.confirm-bg.show{display:flex;}
.confirm-box{background:#fff;border-radius:16px;padding:32px;max-width:520px;width:100%;box-shadow:0 32px 80px rgba(0,0,0,.3);animation:modalIn .25s cubic-bezier(.22,.68,0,1.2);}
.confirm-box h3{font-family:var(--display);font-size:18px;font-weight:800;margin-bottom:8px;}
.confirm-box p{color:var(--ink2);font-size:13px;line-height:1.6;margin-bottom:20px;}
.confirm-actions{display:flex;gap:10px;justify-content:flex-end;}
.confirm-result-banner{border-radius:10px;padding:14px 18px;margin-top:16px;font-size:13px;}
.crb-success{background:#dcfce7;border:1px solid #86efac;color:#14532d;}
.crb-error{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}

@media(max-width:900px){
    .filter-grid{grid-template-columns:1fr 1fr;}
    .totals-grid{grid-template-columns:repeat(3,1fr);}
    .modal-sections{grid-template-columns:1fr;}
    .modal-edit-grid{grid-template-columns:1fr 1fr;}
    .net-hero-band{grid-template-columns:1fr;gap:8px;}
    .net-hero-divider{display:none;}
}
</style>

<!-- PAGE HEADER -->
<div class="cc-page-hdr">
    <div>
        <div class="cc-page-hdr-title"><i class="fa-solid fa-money-bill-transfer"></i> CC / ACC / SACC Salary Sheet</div>
        <div class="cc-page-hdr-sub">Cash Collectors, Assistant &amp; Senior Assistant Cash Collectors — Payroll Summary</div>
    </div>
    <div class="cc-page-hdr-right">
        <form method="GET" id="periodForm" style="display:contents;">
            <?php if (!empty($all_periods)): ?>
            <div class="period-pill">
                <i class="fa-solid fa-calendar-check" style="color:#7dd3fc;"></i>
                <div>
                    <div class="period-pill-label">Payroll Period</div>
                    <select name="period_id" onchange="document.getElementById('periodForm').submit()">
                        <?php foreach ($all_periods as $pp): ?>
                        <option value="<?php echo $pp['id']; ?>" <?php echo $sel_period_id==$pp['id']?'selected':''; ?>>
                            <?php echo $month_names[$pp['month']].' '.$pp['year'];
                            echo ($pp['status']!=='Open')?' ('.$pp['status'].')':''; ?>
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
            <?php if ($filter_catcode): ?><input type="hidden" name="filter_catcode" value="<?php echo $filter_catcode; ?>"><?php endif; ?>
            <?php endif; ?>
        </form>
        <a href="mr_salary.php" class="btn btn-outline btn-sm"><i class="fa-solid fa-users"></i> MR Sheet</a>
        <a href="sr_salary.php" class="btn btn-outline btn-sm"><i class="fa-solid fa-chart-line"></i> SR Sheet</a>
        <button class="btn btn-white btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        <button class="btn btn-green btn-sm" onclick="exportCSV()"><i class="fa-solid fa-file-csv"></i> Export</button>
    </div>
</div>

<!-- SHEET STATUS BANNER -->
<?php if ($has_saved_sheet && $is_confirmed): ?>
<div class="sheet-banner sheet-banner-confirmed">
    <div class="sheet-banner-left">
        <i class="fa-solid fa-circle-check" style="font-size:18px;"></i>
        <div><strong>CC/ACC/SACC Salary sheet CONFIRMED</strong> — <?php echo $saved_sheet_count; ?> employees
        · <?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : ''; ?>
        <span style="font-size:11px;font-weight:400;margin-left:6px;opacity:.8;">Sheet is locked. Loan payments recorded.</span></div>
    </div>
    <div class="sheet-banner-actions"><span class="status-pill status-confirmed"><i class="fa-solid fa-lock"></i> CONFIRMED</span></div>
</div>
<?php elseif ($has_saved_sheet): ?>
<div class="sheet-banner sheet-banner-saved">
    <div class="sheet-banner-left">
        <i class="fa-solid fa-floppy-disk" style="font-size:16px;"></i>
        <div><strong>CC/ACC/SACC Salary sheet saved</strong> — <?php echo $saved_sheet_count; ?> employees
        · <?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : ''; ?>
        <span class="status-pill status-pending" style="margin-left:8px;"><i class="fa-solid fa-clock"></i> PENDING</span>
        <span style="font-size:11px;font-weight:400;margin-left:6px;opacity:.75;">Enter Cash Short / Good Short in row modal, then confirm.</span></div>
    </div>
    <div class="sheet-banner-actions">
        <button class="btn btn-amber btn-sm" onclick="refreshSheet()"><i class="fa-solid fa-arrows-rotate"></i> Refresh</button>
        <button class="btn btn-red btn-sm" onclick="confirmDeleteSheet()"><i class="fa-solid fa-trash"></i> Delete</button>
        <button class="btn btn-indigo btn-sm" onclick="confirmSheetModal()"><i class="fa-solid fa-check-double"></i> Confirm Salary Sheet</button>
    </div>
</div>
<?php else: ?>
<div class="sheet-banner sheet-banner-unsaved">
    <div class="sheet-banner-left">
        <i class="fa-solid fa-triangle-exclamation" style="font-size:16px;"></i>
        <div><strong>No saved CC/ACC/SACC sheet</strong> for <?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : 'this period'; ?>.
        <span style="font-size:11px;font-weight:400;margin-left:6px;opacity:.8;">Create a salary sheet to review, enter Cash Short / Good Short, and confirm.</span></div>
    </div>
    <div class="sheet-banner-actions">
        <button class="btn btn-primary btn-sm" onclick="createSalarySheet()"><i class="fa-solid fa-floppy-disk"></i> Create CC/ACC/SACC Salary Sheet</button>
    </div>
</div>
<?php endif; ?>

<!-- FILTERS -->
<div class="filter-bar">
    <div class="filter-toggle" onclick="toggleFilter()">
        <div style="display:flex;align-items:center;gap:8px;"><i class="fa-solid fa-sliders"></i><span>Filters</span>
        <?php $active_f=array_filter([$filter_status,$filter_company,$filter_catcode]); if(count($active_f)):?>
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
                    <select name="filter_company" class="filter-select">
                        <option value="">All Companies</option>
                        <?php if ($companies_res) { mysqli_data_seek($companies_res,0); while($c=mysqli_fetch_assoc($companies_res)): ?>
                        <option value="<?php echo $c['id'];?>" <?php echo $filter_company==$c['id']?'selected':'';?>><?php echo htmlspecialchars($c['company_code'].' - '.$c['company_name']);?></option>
                        <?php endwhile; } ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Category</label>
                    <select name="filter_catcode" class="filter-select">
                        <option value="">CC, ACC &amp; SACC</option>
                        <option value="CC"   <?php echo $filter_catcode==='CC'?'selected':'';?>>CC Only</option>
                        <option value="ACC"  <?php echo $filter_catcode==='ACC'?'selected':'';?>>ACC Only</option>
                        <option value="SACC" <?php echo $filter_catcode==='SACC'?'selected':'';?>>SACC Only</option>
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
                    <label>Search</label>
                    <input type="text" id="liveSearch" class="filter-select" placeholder="Name / Code…" oninput="liveFilter(this.value)" style="background:#fff;">
                </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:12px;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Apply</button>
                <a href="cc_salary.php<?php echo $sel_period_id?'?period_id='.$sel_period_id:'';?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- STATS ROW -->
<div class="stats-row">
    <div class="stat-chip"><span class="dot dot-cyan"></span><span><strong><?php echo count($employees); ?></strong> CC/ACC/SACC Employees</span></div>
    <div class="stat-chip"><span class="dot dot-green"></span><span><strong><?php echo $cnt_active; ?></strong> Active</span></div>
    <div class="stat-chip"><span class="dot dot-blue"></span><span>Total Earnings <strong>LKR <?php echo number_format($totals['earn'],2); ?></strong></span></div>
    <div class="stat-chip"><span class="dot dot-red"></span><span>Deductions <strong>LKR <?php echo number_format($totals['ded'],2); ?></strong></span></div>
    <div class="stat-chip"><span class="dot dot-green"></span><span>Net Salary <strong>LKR <?php echo number_format($totals['net'],2); ?></strong></span></div>
    <div class="stat-chip"><span class="dot dot-orange"></span><span>No Pay <strong>LKR <?php echo number_format($totals['no_pay'],2); ?></strong></span></div>
    <?php if ($totals['cash_short'] + $totals['good_short'] > 0): ?>
    <div class="stat-chip"><span class="dot" style="background:#be123c;"></span><span>Cash/Good Short <strong>LKR <?php echo number_format($totals['cash_short']+$totals['good_short'],2); ?></strong></span></div>
    <?php endif; ?>
    <div class="stat-chip"><span class="dot" style="background:#7c3aed;"></span><span>Loans <strong><?php echo $total_loan_count; ?></strong></span></div>
</div>

<!-- SUMMARY CARDS -->
<div class="totals-section">
    <div class="totals-section-title"><i class="fa-solid fa-sigma"></i> Period Summary — <?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : 'No Period'; ?></div>
    <div class="totals-grid">
        <div class="total-card total-card-earn"><div class="total-card-label"><i class="fa-solid fa-arrow-trend-up"></i> Total Earnings</div><div class="total-card-value">LKR <?php echo number_format($totals['earn'],2); ?></div></div>
        <div class="total-card total-card-ded"><div class="total-card-label"><i class="fa-solid fa-circle-minus"></i> Deductions</div><div class="total-card-value">LKR <?php echo number_format($totals['ded'],2); ?></div></div>
        <div class="total-card total-card-net"><div class="total-card-label"><i class="fa-solid fa-wallet"></i> Net Salary</div><div class="total-card-value">LKR <?php echo number_format($totals['net'],2); ?></div></div>
        <div class="total-card total-card-ben"><div class="total-card-label"><i class="fa-solid fa-star"></i> Total Benefit</div><div class="total-card-value">LKR <?php echo number_format($totals['ben'],2); ?></div></div>
        <div class="total-card total-card-nopay"><div class="total-card-label"><i class="fa-solid fa-ban"></i> No Pay</div><div class="total-card-value">LKR <?php echo number_format($totals['no_pay'],2); ?></div></div>
        <div class="total-card total-card-short"><div class="total-card-label"><i class="fa-solid fa-scale-unbalanced"></i> Cash+Good Short</div><div class="total-card-value">LKR <?php echo number_format($totals['cash_short']+$totals['good_short'],2); ?></div></div>
        <div class="total-card total-card-loan"><div class="total-card-label"><i class="fa-solid fa-hand-holding-dollar"></i> Loan Deductions</div><div class="total-card-value">LKR <?php echo number_format($totals['loan_ded'],2); ?></div></div>
    </div>
</div>

<!-- HIDDEN SAVE FORM -->
<form id="saveSheetForm" method="POST" action="cc_salary.php?period_id=<?php echo $sel_period_id; ?>">
    <input type="hidden" name="action"    value="save_sheet">
    <input type="hidden" name="period_id" value="<?php echo $sel_period_id; ?>">
    <input type="hidden" name="rows_json" id="saveRowsJson" value="">
</form>

<!-- MAIN TABLE -->
<div class="salary-card">
    <div class="salary-card-header">
        <div class="salary-card-title">
            <i class="fa-solid fa-table-columns"></i> CC/ACC/SACC Salary Sheet
            <span style="font-weight:400;color:var(--ink3);font-size:12px;"><?php echo count($employees); ?> records</span>
            <?php if ($is_confirmed): ?>
            <span style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;"><i class="fa-solid fa-lock"></i> CONFIRMED</span>
            <?php elseif ($has_saved_sheet): ?>
            <span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;"><i class="fa-solid fa-clock"></i> PENDING</span>
            <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <button class="expand-toggle-btn" id="expandBtn" onclick="toggleExpand()">
                <i class="fa-solid fa-table-cells-large"></i> <span id="expandBtnText">Expand All Columns</span>
            </button>
            <?php if ($has_saved_sheet && !$is_confirmed): ?>
            <span style="font-size:11px;color:#0891b2;font-weight:600;"><i class="fa-solid fa-pen-to-square" style="margin-right:4px;"></i>Click row to enter Cash/Good Short</span>
            <?php else: ?>
            <span style="font-size:11px;color:var(--ink3);"><i class="fa-solid fa-hand-pointer" style="margin-right:4px;"></i>Click row for details</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="salary-scroll">
    <?php if (empty($employees)): ?>
    <div style="text-align:center;padding:60px 20px;color:var(--ink3);">
        <i class="fa-solid fa-users-slash" style="font-size:40px;margin-bottom:12px;display:block;"></i>
        <p>No CC/ACC/SACC employees found.</p>
    </div>
    <?php else: ?>
    <table class="salary-table compact-view" id="salaryTable">
        <thead>
            <tr>
                <th colspan="5" class="th-group th-emp" style="text-align:left;">Employee Info</th>
                <th colspan="3" class="th-group th-att" style="text-align:center;"><i class="fa-solid fa-calendar-day"></i> Attendance</th>
                <th colspan="6" class="th-group th-inc col-expanded" style="text-align:center;"><i class="fa-solid fa-coins"></i> Incentives</th>
                <th colspan="2" class="th-group th-earn col-expanded" style="text-align:center;"><i class="fa-solid fa-receipt"></i> Reimbursements</th>
                <th colspan="1" class="th-group th-earn" style="text-align:center;border-right:2px solid #0f766e;"><i class="fa-solid fa-sigma"></i> Earnings</th>
                <th colspan="5" class="th-group th-ded col-expanded" style="text-align:center;"><i class="fa-solid fa-circle-minus"></i> Deductions</th>
                <th colspan="2" class="th-group th-short" style="text-align:center;"><i class="fa-solid fa-scale-unbalanced"></i> Short</th>
                <th colspan="1" class="th-group th-ded" style="text-align:center;border-right:2px solid #5c1a1a;"><i class="fa-solid fa-circle-minus"></i> Total Ded.</th>
                <th colspan="1" class="th-group th-net" style="text-align:center;"><i class="fa-solid fa-wallet"></i> Net</th>
                <th colspan="4" class="th-group th-ben col-expanded" style="text-align:center;"><i class="fa-solid fa-star"></i> Employer</th>
                <th colspan="1" class="th-group th-inc" style="text-align:center;border-right:2px solid #3d0f6b;"><i class="fa-solid fa-hand-holding-dollar"></i> Loans</th>
                <th colspan="1" class="th-group th-emp" style="width:44px;"></th>
            </tr>
            <tr>
                <th class="col-s1 th-emp" style="text-align:left;">Employee Code</th>
                <th class="col-s2 th-emp" style="text-align:left;">EPF No.</th>
                <th class="col-s3 th-emp" style="text-align:left;">Date of Join</th>
                <th class="col-s4 th-emp" style="text-align:left;">Name &amp; Status</th>
                <th class="col-s5 th-emp" style="text-align:left;">Designation</th>
                <th class="th-att">Norm Days</th>
                <th class="th-att">Att Days &amp; Rate</th>
                <th class="th-att" style="border-right:2px solid #1a3a6e;">No Pay Days/Amt</th>
                <!-- Incentives (expanded) -->
                <th class="th-inc col-expanded">CCFOT</th>
                <th class="th-inc col-expanded">Credit Mgt</th>
                <th class="th-inc col-expanded">Payee Chq</th>
                <th class="th-inc col-expanded">SSV</th>
                <th class="th-inc col-expanded">Attendance</th>
                <th class="th-inc col-expanded" style="border-right:2px solid #3d0a60;">Locus Admin</th>
                <!-- Reimb (expanded) -->
                <th class="th-earn col-expanded">Meal</th>
                <th class="th-earn col-expanded" style="border-right:2px solid #0f766e;">Mobile</th>
                <!-- Earnings total -->
                <th class="th-earn" style="border-right:2px solid #0f766e;">Total Earnings</th>
                <!-- Ded expanded -->
                <th class="th-ded col-expanded">EPF 8%</th>
                <th class="th-ded col-expanded">Welfare</th>
                <th class="th-ded col-expanded">Sal. Adv.</th>
                <th class="th-ded col-expanded">Loan Ded.</th>
                <th class="th-ded col-expanded" style="border-right:2px solid #5c1a1a;">No Pay Amt</th>
                <!-- Short (always visible) -->
                <th class="th-short" style="border-left:2px solid #9f1239;">Cash Short</th>
                <th class="th-short" style="border-right:2px solid #9f1239;">Good Short</th>
                <!-- Total ded / net -->
                <th class="th-ded" style="border-right:2px solid #5c1a1a;">Total Ded.</th>
                <th class="th-net" style="border-right:2px solid #1a5c30;">Net Salary</th>
                <!-- Benefit expanded -->
                <th class="th-ben col-expanded">EPF 12%</th>
                <th class="th-ben col-expanded">ETF 3%</th>
                <th class="th-ben col-expanded">Bonus</th>
                <th class="th-ben col-expanded" style="border-right:2px solid #0c2442;">Total Benefit</th>
                <!-- Loans -->
                <th class="th-inc" style="border-right:2px solid #6b21a8;">Loans / Bal.</th>
                <th class="th-emp" style="width:44px;"></th>
            </tr>
        </thead>
        <tbody id="salaryTableBody">
        <?php
        $gt_earn=$gt_ded=$gt_net=$gt_ben=$gt_no_pay=$gt_loan=$gt_cash_short=$gt_good_short=0;
        foreach ($employees as $emp):
            $eid    = (int)$emp['id'];
            $r      = $rows[$eid];
            $att    = $r['att'];
            $sc_cls = ['Probation'=>'s-probation','Permanent'=>'s-permanent','Resigned'=>'s-resigned','Terminated'=>'s-terminated'][$emp['status']] ?? '';
            $att_cls= $att['rate']>=90?'att-high':($att['rate']>=70?'att-mid':($att['rate']>0?'att-low':'att-zero'));
            $cat_code_lc = strtolower($emp['category_code']??'cc');
            $cat_cls = $cat_code_lc==='sacc' ? 'cat-sacc' : ($cat_code_lc==='acc' ? 'cat-acc' : 'cat-cc');
            $fv     = fn($v) => $v>0 ? number_format($v,2) : '<span class="money-zero">—</span>';
            $gt_earn       += $r['total_earnings'];
            $gt_ded        += $r['total_deductions'];
            $gt_net        += $r['net_salary'];
            $gt_ben        += $r['total_benefit'];
            $gt_no_pay     += $r['no_pay_amt'];
            $gt_loan       += $r['loan_ded'];
            $gt_cash_short += $r['cash_short'];
            $gt_good_short += $r['good_short'];
            $emp_loan_count     = count($r['emp_loans']);
            $loan_total_balance = array_sum(array_column($r['emp_loans'],'balance'));
        ?>
        <tr class="emp-row"
            id="row_<?php echo $eid; ?>"
            onclick="openModal(<?php echo $eid; ?>)"
            data-eid="<?php echo $eid; ?>"
            data-search="<?php echo strtolower(htmlspecialchars(($emp['employee_id']??'').' '.($emp['employee_full_name']??'').' '.($emp['epf_number']??'').' '.($emp['designation_name']??''))); ?>">

            <td class="col-s1 td-left">
                <div class="emp-id-tag"><?php echo htmlspecialchars($emp['employee_id']); ?></div>
                <div class="emp-meta" style="margin-top:2px;"><?php echo htmlspecialchars(($emp['company_code']??'').($emp['branch_code']?' · '.$emp['branch_code']:'')); ?></div>
            </td>
            <td class="col-s2 td-left"><span style="font-family:var(--mono);font-size:11px;color:var(--ink2);"><?php echo $emp['epf_number'] ? htmlspecialchars($emp['epf_number']) : '<span class="money-zero">—</span>'; ?></span></td>
            <td class="col-s3 td-left"><span style="font-family:var(--mono);font-size:10px;color:var(--ink2);"><?php echo $emp['date_of_join'] ? date('d M Y',strtotime($emp['date_of_join'])) : '—'; ?></span></td>
            <td class="col-s4 td-left">
                <div class="emp-name-cell"><?php echo htmlspecialchars($emp['employee_full_name']); ?></div>
                <span class="s-badge <?php echo $sc_cls; ?>" style="margin-top:2px;"><?php echo $emp['status']; ?></span>
            </td>
            <td class="col-s5 td-left">
                <span class="cat-chip <?php echo $cat_cls; ?>"><?php echo htmlspecialchars($emp['category_code']??'CC'); ?></span>
                <div class="emp-meta" style="margin-top:3px;"><?php echo htmlspecialchars($emp['designation_name']??''); ?></div>
            </td>
            <!-- ATT -->
            <td class="td-att td-center">
                <span style="font-family:var(--mono);font-size:12px;font-weight:700;"><?php echo $att['norm']; ?></span>
                <div style="font-size:9px;color:#9ca3af;margin-top:1px;"><?php echo $att['holidays']>0?$att['holidays'].' hols':''; ?></div>
            </td>
            <td class="td-att td-center">
                <span class="att-pill <?php echo $att_cls; ?>"><?php echo $att['att']; ?></span>
                <div style="font-size:9px;color:#9ca3af;margin-top:1px;"><?php echo $att['rate']; ?>%</div>
            </td>
            <td class="td-att" style="border-right:2px solid #e5e7eb;text-align:center;">
                <?php if ($r['no_pay_days'] > 0): ?>
                <span class="money-nopay" style="font-size:12px;font-weight:800;"><?php echo number_format($r['no_pay_days'],1); ?>d</span>
                <div style="font-size:9px;color:#ea580c;font-family:var(--mono);margin-top:1px;"><?php echo number_format($r['no_pay_amt'],2); ?></div>
                <?php else: ?>
                <span style="color:#22c55e;font-size:15px;">✓</span>
                <?php endif; ?>
            </td>
            <!-- INCENTIVES (expanded) -->
            <td class="td-inc col-expanded"><span class="money-inc"><?php echo $fv($r['ccfot_amt']); ?></span></td>
            <td class="td-inc col-expanded"><span class="money-inc"><?php echo $fv($r['credit_mgt_amt']); ?></span></td>
            <td class="td-inc col-expanded"><span class="money-inc"><?php echo $fv($r['payee_chq_amt']); ?></span></td>
            <td class="td-inc col-expanded"><span class="money-inc"><?php echo $fv($r['ssv_cc_amt']); ?></span></td>
            <td class="td-inc col-expanded"><span class="money-inc"><?php echo $fv($r['att_inc_amt']); ?></span></td>
            <td class="td-inc col-expanded" style="border-right:2px solid #7c3aed;"><span class="money-inc"><?php echo $fv($r['locus_admin_amt']); ?></span></td>
            <!-- REIMB (expanded) -->
            <td class="td-earn col-expanded"><span class="money-earn"><?php echo $fv($r['meal_calc']); ?></span></td>
            <td class="td-earn col-expanded" style="border-right:2px solid #0f766e;"><span class="money-earn"><?php echo $fv($r['mobile']); ?></span></td>
            <!-- TOTAL EARNINGS -->
            <td class="td-earn" style="border-right:2px solid #0f766e;">
                <span class="money-earn" style="font-size:12.5px;font-weight:700;" id="earn_<?php echo $eid; ?>"><?php echo $fv($r['total_earnings']); ?></span>
            </td>
            <!-- DED expanded -->
            <td class="td-ded col-expanded"><span class="money-ded"><?php echo $fv($r['epf_emp']); ?></span></td>
            <td class="td-ded col-expanded"><span class="money-ded"><?php echo $fv($r['welfare_amt']); ?></span></td>
            <td class="td-ded col-expanded">
                <span class="money-ded"><?php echo $fv($r['sal_adv']); ?></span>
                <?php if ($r['advance_from_entry']): ?><div style="font-size:9px;color:#9ca3af;">Period</div><?php endif; ?>
            </td>
            <td class="td-ded col-expanded"><span class="money-loan"><?php echo $fv($r['loan_ded']); ?></span></td>
            <td class="td-ded col-expanded" style="border-right:2px solid #991b1b;"><span class="money-nopay"><?php echo $fv($r['no_pay_amt']); ?></span></td>
            <!-- SHORT (always visible) -->
            <td class="td-short" style="border-left:2px solid #9f1239;text-align:center;">
                <?php if ($r['cash_short'] > 0): ?>
                <span class="short-badge"><i class="fa-solid fa-coins"></i><?php echo number_format($r['cash_short'],2); ?></span>
                <?php else: ?>
                <span class="short-badge short-badge-nil">—</span>
                <?php endif; ?>
            </td>
            <td class="td-short" style="border-right:2px solid #9f1239;text-align:center;">
                <?php if ($r['good_short'] > 0): ?>
                <span class="short-badge" style="background:#fff7ed;color:#c2410c;border-color:#fed7aa;"><i class="fa-solid fa-box"></i><?php echo number_format($r['good_short'],2); ?></span>
                <?php else: ?>
                <span class="short-badge short-badge-nil">—</span>
                <?php endif; ?>
            </td>
            <!-- TOTAL DED -->
            <td class="td-ded" style="border-right:2px solid #991b1b;">
                <span class="money-ded" id="ded_<?php echo $eid; ?>"><?php echo $fv($r['total_deductions']); ?></span>
            </td>
            <!-- NET -->
            <td class="td-net" style="border-right:2px solid #15803d;">
                <span class="money-net" style="font-size:13.5px;" id="net_<?php echo $eid; ?>"><?php echo $fv($r['net_salary']); ?></span>
            </td>
            <!-- BEN expanded -->
            <td class="td-ben col-expanded"><span class="money-ben" style="font-size:11px;"><?php echo $fv($r['epf_er']); ?></span></td>
            <td class="td-ben col-expanded"><span class="money-ben" style="font-size:11px;"><?php echo $fv($r['etf_er']); ?></span></td>
            <td class="td-ben col-expanded"><span class="money-ben" style="font-size:11px;"><?php echo $fv($r['bonus']); ?></span></td>
            <td class="td-ben col-expanded" style="border-right:2px solid #0c2442;">
                <span class="money-ben" id="ben_<?php echo $eid; ?>"><?php echo $fv($r['total_benefit']); ?></span>
            </td>
            <!-- LOANS -->
            <td class="td-inc" style="border-right:2px solid #6b21a8;text-align:center;">
                <?php if ($emp_loan_count > 0): ?>
                <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;background:#f3e8ff;color:#7c3aed;white-space:nowrap;">
                    <i class="fa-solid fa-hand-holding-dollar"></i> <?php echo $emp_loan_count; ?>
                </span>
                <div style="font-family:var(--mono);font-size:10px;color:#7c3aed;margin-top:3px;">Bal: <?php echo number_format($loan_total_balance,2); ?></div>
                <?php else: ?>
                <span style="background:#f3f4f6;color:#9ca3af;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;">—</span>
                <?php endif; ?>
            </td>
            <td class="td-center" onclick="event.stopPropagation()">
                <button class="row-view-btn" onclick="openModal(<?php echo $eid; ?>)" title="View full breakdown"><i class="fa-solid fa-expand"></i></button>
            </td>
        </tr>
        <?php endforeach; ?>

        <!-- GRAND TOTAL ROW -->
        <tr class="tr-total">
            <td class="col-s1 td-left" colspan="5">
                <span style="font-family:var(--display);font-size:12px;font-weight:800;">
                    <i class="fa-solid fa-sigma" style="margin-right:6px;"></i>GRAND TOTAL
                </span>
                <span style="font-size:10px;color:#9ca3af;margin-left:8px;"><?php echo count($employees); ?> employees</span>
            </td>
            <td></td><td></td>
            <td style="color:#fed7aa;font-family:var(--mono);font-size:11px;text-align:center;border-right:2px solid #3f3f46;">LKR <?php echo number_format($gt_no_pay,2); ?></td>
            <td class="col-expanded" colspan="6" style="color:#d8b4fe;font-family:var(--mono);font-size:11px;text-align:center;border-right:2px solid #7c3aed;"></td>
            <td class="col-expanded" colspan="2" style="border-right:2px solid #0f766e;"></td>
            <td style="color:#a5f3fc;font-family:var(--mono);font-size:12px;font-weight:800;text-align:right;border-right:2px solid #0f766e;">LKR <?php echo number_format($gt_earn,2); ?></td>
            <td class="col-expanded" colspan="4" style="border-right:2px solid #991b1b;"></td>
            <td class="col-expanded" style="color:#fed7aa;font-family:var(--mono);font-size:11px;text-align:right;border-right:2px solid #991b1b;">LKR <?php echo number_format($gt_no_pay,2); ?></td>
            <td style="color:#fda4af;font-family:var(--mono);font-size:12px;font-weight:800;text-align:right;border-left:2px solid #9f1239;">LKR <?php echo number_format($gt_cash_short,2); ?></td>
            <td style="color:#fda4af;font-family:var(--mono);font-size:12px;font-weight:800;text-align:right;border-right:2px solid #9f1239;">LKR <?php echo number_format($gt_good_short,2); ?></td>
            <td style="color:#fca5a5;font-family:var(--mono);font-size:12px;font-weight:800;text-align:right;border-right:2px solid #5c1a1a;">LKR <?php echo number_format($gt_ded,2); ?></td>
            <td style="color:#6ee7b7;font-family:var(--mono);font-size:13px;font-weight:800;text-align:right;border-right:2px solid #1a5c30;">LKR <?php echo number_format($gt_net,2); ?></td>
            <td class="col-expanded" colspan="4" style="border-right:2px solid #0c2442;"></td>
            <td></td><td></td>
        </tr>
        </tbody>
    </table>
    <?php endif; ?>
    </div>
</div></div>


<!-- DETAIL MODAL -->
<div class="modal-bg" id="detailModal" onclick="if(event.target===this)closeModal()">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <div class="modal-emp-name" id="m-name">—</div>
                <div class="modal-emp-meta">
                    <span><i class="fa-solid fa-id-badge" style="color:#0891b2;"></i> <span id="m-code">—</span></span>
                    <span><i class="fa-solid fa-briefcase" style="color:#0891b2;"></i> <span id="m-desig">—</span></span>
                    <span id="m-status-wrap"></span>
                    <span><i class="fa-solid fa-calendar" style="color:#6b7280;"></i> <span id="m-join">—</span></span>
                    <span><i class="fa-solid fa-hashtag" style="color:#059669;"></i> EPF <span id="m-epf">—</span></span>
                </div>
            </div>
            <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="modalBody"></div>
        <?php if ($has_saved_sheet && !$is_confirmed): ?>
        <div class="modal-update-bar">
            <div class="modal-update-info"><i class="fa-solid fa-pen-to-square"></i> Edit Cash Short, Good Short, Arrears, Excess &amp; Credit Recovery, then click Update.</div>
            <div style="display:flex;align-items:center;gap:10px;">
                <span class="modal-update-status" id="modalUpdateStatus"></span>
                <button class="btn btn-cyan btn-sm" id="modalUpdateBtn" onclick="saveModalOverrides()">
                    <i class="fa-solid fa-floppy-disk"></i> Update &amp; Save
                </button>
            </div>
        </div>
        <?php endif; ?>
        <div class="modal-footer">
            <div id="m-links"></div>
            <button class="btn btn-ghost btn-sm" onclick="closeModal()"><i class="fa-solid fa-xmark"></i> Close</button>
        </div>
    </div>
</div>

<!-- CONFIRM MODALS -->
<div class="confirm-bg" id="confirmModal">
    <div class="confirm-box">
        <h3><i class="fa-solid fa-trash" style="color:#dc2626;margin-right:8px;"></i>Delete CC/ACC/SACC Salary Sheet?</h3>
        <p>This will permanently delete the saved CC/ACC/SACC salary sheet for <strong><?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : 'this period'; ?></strong>.</p>
        <div class="confirm-actions">
            <button class="btn btn-ghost btn-sm" onclick="closeConfirm()">Cancel</button>
            <button class="btn btn-red btn-sm" onclick="deleteSheet()"><i class="fa-solid fa-trash"></i> Yes, Delete</button>
        </div>
    </div>
</div>
<div class="confirm-bg" id="confirmRefreshModal">
    <div class="confirm-box">
        <h3><i class="fa-solid fa-arrows-rotate" style="color:#d97706;margin-right:8px;"></i>Refresh from Live Data?</h3>
        <p>This will overwrite the saved sheet with the latest live data. All previously saved edits will be lost.</p>
        <div class="confirm-actions">
            <button class="btn btn-ghost btn-sm" onclick="closeRefreshConfirm()">Cancel</button>
            <button class="btn btn-amber btn-sm" onclick="doRefreshSheet()"><i class="fa-solid fa-arrows-rotate"></i> Yes, Refresh</button>
        </div>
    </div>
</div>
<div class="confirm-bg" id="confirmSheetModal">
    <div class="confirm-box">
        <h3><i class="fa-solid fa-check-double" style="color:#4338ca;margin-right:8px;"></i>Confirm CC/ACC/SACC Salary Sheet?</h3>
        <p>This will <strong>lock</strong> the CC/ACC/SACC salary sheet for <strong><?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : 'this period'; ?></strong> and record loan payments.</p>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:12.5px;color:#1e40af;">
            <i class="fa-solid fa-circle-info" style="margin-right:6px;"></i>
            <strong>On confirmation:</strong>
            <ul style="margin:8px 0 0 18px;line-height:1.9;">
                <li>Sheet status → <strong>Confirmed</strong> (locked)</li>
                <li>Active loan instalments recorded in <code>loan_payments</code></li>
                <li>Fully paid loans marked <strong>Completed</strong></li>
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
const HAS_SAVED_SHEET = <?php echo $has_saved_sheet?'true':'false'; ?>;
const IS_CONFIRMED    = <?php echo $is_confirmed?'true':'false'; ?>;
const PERIOD_ID       = <?php echo (int)$sel_period_id; ?>;
const SELF_URL        = 'cc_salary.php';

const CC_DATA = <?php
$js = [];
foreach ($employees as $emp) {
    $eid = (int)$emp['id'];
    $r   = $rows[$eid];
    $att = $r['att'];
    $js[$eid] = [
        'employee_db_id'=>$eid,'name'=>$emp['employee_full_name'],'code'=>$emp['employee_id'],
        'desig'=>$emp['designation_name']??'','status'=>$emp['status'],'cat'=>$emp['category_code']??'CC',
        'join'=>$emp['date_of_join']?date('d M Y',strtotime($emp['date_of_join'])):'—',
        'join_raw'=>$emp['date_of_join']??'','epf'=>$emp['epf_number']??'—',
        'company_code'=>$emp['company_code']??'','branch_code'=>$emp['branch_code']??'',
        'att_count'=>$att['att'],'norm_days'=>$att['norm'],'holidays'=>$att['holidays'],
        'att_rate'=>$att['rate'],'per_day'=>$r['per_day'],
        'no_pay_days'=>$r['no_pay_days'],'no_pay_amount'=>$r['no_pay_amt'],
        'basic'=>$r['basic'],'insurance'=>$r['insurance'],
        'welfare_amt'=>$r['welfare_amt'],
        'ccfot_amt'=>$r['ccfot_amt'],'credit_mgt_amt'=>$r['credit_mgt_amt'],
        'payee_chq_amt'=>$r['payee_chq_amt'],'ssv_cc_amt'=>$r['ssv_cc_amt'],
        'att_inc_amt'=>$r['att_inc_amt'],'locus_admin_amt'=>$r['locus_admin_amt'],
        'meal'=>$r['meal_calc'],'mobile'=>$r['mobile'],'arrears'=>$r['arrears'],
        'total_earnings'=>$r['total_earnings'],
        'epf_emp'=>$r['epf_emp'],'w_loan'=>$r['w_loan'],'w_soc'=>$r['w_soc'],
        'donations'=>$r['donations'],'sal_adv'=>$r['sal_adv'],
        'advance_from_entry'=>$r['advance_from_entry'],
        'loan_ded'=>$r['loan_ded'],'credit_r'=>$r['credit_r'],
        'cash_short'=>$r['cash_short'],'good_short'=>$r['good_short'],
        'retention'=>$r['retention'],'excess_p'=>$r['excess_p'],
        'total_deductions'=>$r['total_deductions'],'net_salary'=>$r['net_salary'],
        'epf_er'=>$r['epf_er'],'etf_er'=>$r['etf_er'],'bonus'=>$r['bonus'],'gratuity'=>$r['gratuity'],
        'total_benefit'=>$r['total_benefit'],'cost_bp'=>$r['cost_bp'],
        'additional_earnings'=>$r['add_earn_saved']?:[],'additional_deductions'=>$r['add_ded_saved']?:[],
        'emp_loans'=>$r['emp_loans'],
    ];
}
echo json_encode($js);
?>;

// ── HELPERS ───────────────────────────────────────────────────────────────
function lkr(v){v=parseFloat(v||0);if(v<=0)return '<span style="color:#d1d5db;">—</span>';return'<span><span style="font-size:9px;opacity:.6;margin-right:1px;">LKR</span>'+v.toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2})+'</span>';}
function lkrPlain(v){return'LKR '+parseFloat(v||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});}
function fmt(v){return parseFloat(v||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});}
function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

let _currentModalEid=null,_addEarnRows=[],_addDedRows=[],_tableExpanded=false;

// ── EXPAND ────────────────────────────────────────────────────────────────
function toggleExpand(){
    _tableExpanded=!_tableExpanded;
    const tbl=document.getElementById('salaryTable');
    const btn=document.getElementById('expandBtn');
    const txt=document.getElementById('expandBtnText');
    if(_tableExpanded){tbl.classList.remove('compact-view');tbl.classList.add('expanded-view');btn.classList.add('active');txt.textContent='Collapse Columns';}
    else{tbl.classList.remove('expanded-view');tbl.classList.add('compact-view');btn.classList.remove('active');txt.textContent='Expand All Columns';}
}

// ── SHEET ACTIONS ─────────────────────────────────────────────────────────
function createSalarySheet(){document.getElementById('saveRowsJson').value=JSON.stringify(Object.values(CC_DATA));document.getElementById('saveSheetForm').submit();}
function refreshSheet(){document.getElementById('confirmRefreshModal').classList.add('show');}
function closeRefreshConfirm(){document.getElementById('confirmRefreshModal').classList.remove('show');}
function doRefreshSheet(){closeRefreshConfirm();fetch(SELF_URL+'?ajax=delete_sheet&period_id='+PERIOD_ID).then(()=>{document.getElementById('saveRowsJson').value=JSON.stringify(Object.values(CC_DATA));document.getElementById('saveSheetForm').submit();});}
function confirmDeleteSheet(){document.getElementById('confirmModal').classList.add('show');}
function closeConfirm(){document.getElementById('confirmModal').classList.remove('show');}
function deleteSheet(){closeConfirm();fetch(SELF_URL+'?ajax=delete_sheet&period_id='+PERIOD_ID).then(r=>r.json()).then(d=>{if(d.ok)window.location.href=SELF_URL+'?period_id='+PERIOD_ID;else alert(d.msg||'Cannot delete.');});}
function confirmSheetModal(){document.getElementById('confirmSheetResult').innerHTML='';document.getElementById('confirmSheetActions').style.display='flex';const btn=document.getElementById('confirmSheetBtn');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-check-double"></i> Yes, Confirm &amp; Record Payments';document.getElementById('confirmSheetModal').classList.add('show');}
function closeConfirmSheet(){document.getElementById('confirmSheetModal').classList.remove('show');}
function doConfirmSheet(){
    const btn=document.getElementById('confirmSheetBtn');btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Processing…';
    fetch(SELF_URL+'?ajax=confirm_sheet&period_id='+PERIOD_ID).then(r=>r.json()).then(d=>{
        const res=document.getElementById('confirmSheetResult');
        if(d.ok){res.innerHTML=`<div class="confirm-result-banner crb-success"><i class="fa-solid fa-circle-check"></i> <strong>Sheet confirmed!</strong> ${d.payments_created} loan payment${d.payments_created!==1?'s':''} recorded.</div>`;
            document.getElementById('confirmSheetActions').innerHTML='<button class="btn btn-primary btn-sm" onclick="window.location.reload()"><i class="fa-solid fa-rotate-right"></i> Reload Page</button>';}
        else{res.innerHTML=`<div class="confirm-result-banner crb-error"><i class="fa-solid fa-circle-exclamation"></i> ${d.msg||'Error confirming.'}</div>`;btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-check-double"></i> Yes, Confirm &amp; Record Payments';}
    }).catch(()=>{document.getElementById('confirmSheetResult').innerHTML='<div class="confirm-result-banner crb-error">Network error.</div>';btn.disabled=false;});
}

// ── ADD ROWS ──────────────────────────────────────────────────────────────
function addEarnRow(d,a){_addEarnRows.push({desc:d||'',amount:parseFloat(a||0)});renderAddRows();}
function addDedRow(d,a){_addDedRows.push({desc:d||'',amount:parseFloat(a||0)});renderAddRows();}
function removeEarnRow(i){_addEarnRows.splice(i,1);renderAddRows();}
function removeDedRow(i){_addDedRows.splice(i,1);renderAddRows();}
function renderAddRows(){
    const ec=document.getElementById('addEarnRows'),et=document.getElementById('addEarnTotal');
    if(!ec)return;
    let es=0;ec.innerHTML='';
    _addEarnRows.forEach((row,i)=>{es+=parseFloat(row.amount||0);const d=document.createElement('div');d.className='add-row-item';d.innerHTML=`<input type="text" class="add-row-desc" placeholder="Description" value="${escHtml(row.desc)}" oninput="_addEarnRows[${i}].desc=this.value"><input type="number" step="0.01" min="0" class="add-row-amount" placeholder="0.00" value="${row.amount>0?row.amount.toFixed(2):''}" oninput="_addEarnRows[${i}].amount=parseFloat(this.value)||0;updateAddTotals()"><button class="add-row-remove" onclick="removeEarnRow(${i})"><i class="fa-solid fa-xmark"></i></button>`;ec.appendChild(d);});
    if(et)et.textContent='Total: LKR '+es.toLocaleString('en-LK',{minimumFractionDigits:2});
    const dc=document.getElementById('addDedRows'),dt=document.getElementById('addDedTotal');
    if(!dc)return;let ds=0;dc.innerHTML='';
    _addDedRows.forEach((row,i)=>{ds+=parseFloat(row.amount||0);const d=document.createElement('div');d.className='add-row-item';d.innerHTML=`<input type="text" class="add-row-desc" placeholder="Description" value="${escHtml(row.desc)}" oninput="_addDedRows[${i}].desc=this.value"><input type="number" step="0.01" min="0" class="add-row-amount is-ded" placeholder="0.00" value="${row.amount>0?row.amount.toFixed(2):''}" oninput="_addDedRows[${i}].amount=parseFloat(this.value)||0;updateAddTotals()"><button class="add-row-remove" onclick="removeDedRow(${i})"><i class="fa-solid fa-xmark"></i></button>`;dc.appendChild(d);});
    if(dt)dt.textContent='Total: LKR '+ds.toLocaleString('en-LK',{minimumFractionDigits:2});
}
function updateAddTotals(){
    const et=document.getElementById('addEarnTotal'),dt=document.getElementById('addDedTotal');
    const es=_addEarnRows.reduce((s,r)=>s+parseFloat(r.amount||0),0);
    const ds=_addDedRows.reduce((s,r)=>s+parseFloat(r.amount||0),0);
    if(et)et.textContent='Total: LKR '+es.toLocaleString('en-LK',{minimumFractionDigits:2});
    if(dt)dt.textContent='Total: LKR '+ds.toLocaleString('en-LK',{minimumFractionDigits:2});
}

// ── SAVE OVERRIDES ────────────────────────────────────────────────────────
function saveModalOverrides(){
    const eid=_currentModalEid;if(!eid)return;
    const excess    = parseFloat(document.getElementById('m_excess')?.value||0);
    const arrears   = parseFloat(document.getElementById('m_arrears')?.value||0);
    const credit_r  = parseFloat(document.getElementById('m_credit_r')?.value||0);
    const cash_short= parseFloat(document.getElementById('m_cash_short')?.value||0);
    const good_short= parseFloat(document.getElementById('m_good_short')?.value||0);
    const addEarn   =_addEarnRows.filter(r=>r.desc.trim()!==''||r.amount>0).map(r=>({desc:r.desc.trim(),amount:parseFloat(r.amount||0)}));
    const addDed    =_addDedRows.filter(r=>r.desc.trim()!==''||r.amount>0).map(r=>({desc:r.desc.trim(),amount:parseFloat(r.amount||0)}));
    const btn=document.getElementById('modalUpdateBtn');
    const status=document.getElementById('modalUpdateStatus');
    if(btn){btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';}
    if(status)status.innerHTML='<span class="upd-saving"><i class="fa-solid fa-circle-notch fa-spin"></i> Saving…</span>';
    fetch(SELF_URL+'?ajax=update_overrides',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({period_id:PERIOD_ID,employee_id:eid,excess,arrears,credit_r,cash_short,good_short,additional_earnings:addEarn,additional_deductions:addDed})})
    .then(r=>r.json()).then(d=>{
        if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Update &amp; Save';}
        if(d.ok){
            if(status)status.innerHTML='<span class="upd-saved"><i class="fa-solid fa-circle-check"></i> Saved!</span>';
            setTimeout(()=>{if(status)status.innerHTML='';},3000);
            const fl=v=>'<span><span style="font-size:9px;opacity:.6;margin-right:1px;">LKR</span>'+fmt(v)+'</span>';
            const upd=(id,val)=>{const el=document.getElementById(id);if(el)el.innerHTML=fl(val);};
            upd('earn_'+eid,d.total_earnings);upd('ded_'+eid,d.total_deductions);
            upd('net_'+eid,d.net_salary);upd('ben_'+eid,d.total_benefit);
            ['nh_earn','nh_ded','nh_net','nh_ben'].forEach((id,i)=>{
                const el=document.getElementById(id);
                if(el)el.textContent=lkrPlain([d.total_earnings,d.total_deductions,d.net_salary,d.total_benefit][i]);
            });
            if(CC_DATA[eid]){Object.assign(CC_DATA[eid],{excess_p:excess,arrears,credit_r,cash_short,good_short,total_earnings:d.total_earnings,total_deductions:d.total_deductions,net_salary:d.net_salary,total_benefit:d.total_benefit,additional_earnings:addEarn,additional_deductions:addDed});}
        }else{if(status)status.innerHTML='<span class="upd-error"><i class="fa-solid fa-circle-exclamation"></i> '+(d.msg||'Error')+'</span>';}
    }).catch(()=>{if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Update &amp; Save';}if(status)status.innerHTML='<span class="upd-error">Network error</span>';});
}

// ── MODAL ─────────────────────────────────────────────────────────────────
function openModal(eid){
    const d=CC_DATA[eid];if(!d)return;
    _currentModalEid=eid;
    _addEarnRows=(d.additional_earnings||[]).map(r=>({desc:r.desc||'',amount:parseFloat(r.amount||0)}));
    _addDedRows=(d.additional_deductions||[]).map(r=>({desc:r.desc||'',amount:parseFloat(r.amount||0)}));
    document.getElementById('m-name').textContent=d.name;
    document.getElementById('m-code').textContent=d.code;
    document.getElementById('m-desig').textContent=(d.cat||'CC')+' · '+d.desig;
    document.getElementById('m-join').textContent=d.join;
    document.getElementById('m-epf').textContent=d.epf;
    const sc={'Probation':'background:#fef3c7;color:#92400e','Permanent':'background:#dcfce7;color:#15803d','Resigned':'background:#f3f4f6;color:#6b7280','Terminated':'background:#fee2e2;color:#991b1b'};
    document.getElementById('m-status-wrap').innerHTML='<span style="display:inline-block;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700;'+(sc[d.status]||'background:#f3f4f6;color:#888')+'">'+d.status+'</span>';
    document.getElementById('m-links').innerHTML=
        '<a href="view_employee.php?id='+eid+'" class="btn btn-ghost btn-sm"><i class="fa-solid fa-user"></i> Profile</a>'+
        '<a href="salary_advance.php?employee_id='+eid+'&payroll_period_id='+PERIOD_ID+'" class="btn btn-ghost btn-sm"><i class="fa-solid fa-hand-holding-dollar"></i> Advances</a>'+
        '<a href="loans.php?employee_id='+eid+'" class="btn btn-cyan btn-sm"><i class="fa-solid fa-file-invoice-dollar"></i> Loans</a>'+
        '<a href="incentive_entry.php?type_id=3&period_id='+PERIOD_ID+'" class="btn btn-blue btn-sm"><i class="fa-solid fa-percent"></i> Incentive Entry</a>';

    let html='';

    // NET HERO BAND
    html+='<div class="net-hero-band">';
    html+='<div class="net-hero-item"><div class="net-hero-label">Total Earnings</div><div class="net-hero-value v-earn" id="nh_earn">'+lkrPlain(d.total_earnings)+'</div></div>';
    html+='<div class="net-hero-divider"></div>';
    html+='<div class="net-hero-item"><div class="net-hero-label">Total Deductions</div><div class="net-hero-value v-ded" id="nh_ded">'+lkrPlain(d.total_deductions)+'</div></div>';
    html+='<div class="net-hero-divider"></div>';
    html+='<div class="net-hero-item"><div class="net-hero-label">✦ Net Salary</div><div class="net-hero-value v-net" id="nh_net">'+lkrPlain(d.net_salary)+'</div></div>';
    html+='<div class="net-hero-divider"></div>';
    html+='<div class="net-hero-item"><div class="net-hero-label">Total Benefit</div><div class="net-hero-value v-ben" id="nh_ben">'+lkrPlain(d.total_benefit)+'</div></div>';
    html+='<div class="net-hero-divider"></div>';
    html+='<div class="net-hero-item"><div class="net-hero-label">Provisions (B+G)</div><div class="net-hero-value v-bp">'+lkrPlain(d.bonus+d.gratuity)+'</div></div>';
    html+='</div>';

    // ATT BAND
    const rate=parseFloat(d.att_rate);
    const rc=rate>=90?'#dcfce7;color:#14532d':rate>=70?'#fef3c7;color:#78350f':'#fee2e2;color:#7f1d1d';
  //  html+='<div class="att-info-band">';
   // ['Norm Days:'+d.norm_days,'Attended:'+d.att_count,'Rate:'+(rate>0?`<span style="background:${rc};padding:4px 8px;border-radius:6px;font-size:16px;">${d.att_rate}%</span>`:'0%'),'Per Day:'+(d.per_day>0?'LKR '+parseFloat(d.per_day).toFixed(2):'—'),'No Pay Days:'+(d.no_pay_days>0?`<span style="color:#991b1b;font-size:18px;">${parseFloat(d.no_pay_days).toFixed(1)}</span>`:'<span style="color:#22c55e;font-size:18px;">✓ 0</span>'),'No Pay Amt:'+(d.no_pay_amount>0?`<span style="color:#991b1b;font-size:14px;">LKR ${fmt(d.no_pay_amount)}</span>`:'<span style="color:#22c55e;font-size:14px;">✓ None</span>')].forEach((item,i)=>{
     //   const [lbl,val]=item.split(':',2);
  //      html+=`<div class="att-info-card"><div class="att-info-label">${lbl}</div><div class="att-info-value">${val}</div></div>`;
//    });
    html+='</div>';

    // CC INCENTIVES BAND
    html+='<div class="cc-inc-band">';
    html+='<div class="cc-inc-band-title"><i class="fa-solid fa-coins"></i> CC Incentive Breakdown</div>';
    html+='<div class="cc-inc-cards">';
    const incDefs=[
        {key:'ccfot_amt',label:'CCFOT',tiers:[{t:'≥90%',v:d.ccfot_amt}]},
        {key:'credit_mgt_amt',label:'Credit Management',tiers:[{t:'≥85%',v:d.credit_mgt_amt}]},
        {key:'payee_chq_amt',label:'Payee Chq Collection',tiers:[{t:'≥80%',v:d.payee_chq_amt}]},
        {key:'ssv_cc_amt',label:'Secondary Sales Value',tiers:[{t:'≥90%',v:d.ssv_cc_amt}]},
        {key:'att_inc_amt',label:'Attendance',tiers:[{t:'≥85%',v:d.att_inc_amt}]},
        {key:'locus_admin_amt',label:'Locus Admin',tiers:[{t:'100%',v:d.locus_admin_amt}]},
    ];
    incDefs.forEach(inc=>{
        html+=`<div class="cc-inc-card"><div class="cc-inc-card-label">${inc.label}</div>`;
        inc.tiers.forEach(tier=>{
            html+=`<div class="cc-inc-tier ${tier.v>0?'active':''}"><span class="tl">${tier.t}</span><span class="tv">${lkrPlain(tier.v)}</span></div>`;
        });
        if(parseFloat(d[inc.key])>0)html+=`<div class="cc-earned"><span class="cc-earned-label"><i class="fa-solid fa-circle-check"></i> Earned</span><span class="cc-earned-val">${lkrPlain(d[inc.key])}</span></div>`;
        html+=`</div>`;
    });
    html+='</div></div>';

    // CASH SHORT / GOOD SHORT INPUT BAND
    html+='<div class="short-band">';
    html+='<div class="short-band-title"><i class="fa-solid fa-scale-unbalanced"></i> Cash Short &amp; Good Short (Deductions)</div>';
    if(HAS_SAVED_SHEET&&!IS_CONFIRMED){
        html+='<div class="short-input-grid">';
        html+=`<div class="short-input-field">
            <label class="short-input-label cash"><i class="fa-solid fa-coins"></i> Cash Short</label>
            <div class="short-input-wrap">
                <span class="short-input-pfx">Rs.</span>
                <input type="number" step="0.01" min="0" class="short-inp" id="m_cash_short" value="${parseFloat(d.cash_short||0).toFixed(2)}" placeholder="0.00">
            </div>
            <div class="short-info">Amount the CC is short in cash collections for this period</div>
        </div>`;
        html+=`<div class="short-input-field">
            <label class="short-input-label good"><i class="fa-solid fa-box"></i> Good Short</label>
            <div class="short-input-wrap good">
                <span class="short-input-pfx good">Rs.</span>
                <input type="number" step="0.01" min="0" class="short-inp good" id="m_good_short" value="${parseFloat(d.good_short||0).toFixed(2)}" placeholder="0.00">
            </div>
            <div class="short-info" style="color:#9a3412;">Amount short in goods / inventory for this period</div>
        </div>`;
        html+='</div>';
    } else {
        html+=`<div style="display:flex;gap:20px;flex-wrap:wrap;">
            <div class="att-info-card" style="border-color:#fecdd3;background:#fff1f2;flex:none;min-width:160px;">
                <div class="att-info-label" style="color:#be123c;">Cash Short</div>
                <div style="font-family:var(--mono);font-size:20px;font-weight:800;color:${d.cash_short>0?'#be123c':'#22c55e'}">${d.cash_short>0?'LKR '+fmt(d.cash_short):'✓ None'}</div>
            </div>
            <div class="att-info-card" style="border-color:#fed7aa;background:#fff7ed;flex:none;min-width:160px;">
                <div class="att-info-label" style="color:#c2410c;">Good Short</div>
                <div style="font-family:var(--mono);font-size:20px;font-weight:800;color:${d.good_short>0?'#c2410c':'#22c55e'}">${d.good_short>0?'LKR '+fmt(d.good_short):'✓ None'}</div>
            </div>
        </div>`;
        if(IS_CONFIRMED)html+='<div style="margin-top:10px;font-size:12px;color:#0369a1;display:flex;align-items:center;gap:6px;"><i class="fa-solid fa-lock"></i> Sheet is confirmed — values are locked.</div>';
    }
    html+='</div>';

    // LOAN BAND
    html+='<div class="loan-band">';
    html+='<div class="loan-band-title"><i class="fa-solid fa-hand-holding-dollar"></i> Active Loan Deductions This Period</div>';
    const loans=d.emp_loans||[];
    if(!loans.length){
        html+='<div class="loan-no-loans"><i class="fa-solid fa-check-circle" style="color:#22c55e;"></i> No active loans for this employee.</div>';
    } else {
        html+='<div class="loan-cards-row">';
        loans.forEach(ln=>{
            const pct=ln.total_repayment>0?Math.min(100,ln.paid_so_far/ln.total_repayment*100):0;
            html+=`<div class="loan-detail-card"><div class="loan-detail-card-header"><div class="loan-detail-card-type">${escHtml(ln.loan_type)} <span style="font-size:9px;color:#9ca3af;">#${ln.loan_id}</span></div><span style="font-size:10px;padding:2px 7px;border-radius:8px;font-weight:700;background:#dcfce7;color:#166534;">Approved</span></div>`;
            html+=`<div class="loan-detail-row"><span class="loan-detail-label">Original</span><span class="loan-detail-val">LKR ${fmt(ln.loan_amount)}</span></div>`;
            html+=`<div class="loan-detail-row"><span class="loan-detail-label">Paid So Far</span><span class="loan-detail-val v-paid">LKR ${fmt(ln.paid_so_far)}</span></div>`;
            html+=`<div class="loan-detail-row"><span class="loan-detail-label">Balance</span><span class="loan-detail-val v-balance">LKR ${fmt(ln.balance)}</span></div>`;
            html+=`<div class="loan-detail-row" style="background:#fff0f0;border-radius:6px;margin-top:4px;padding:6px 8px;"><span class="loan-detail-label" style="font-weight:700;color:#7c3aed;"><i class="fa-solid fa-arrow-down"></i> This Period</span><span class="loan-detail-val v-deduct" style="font-size:14px;">LKR ${fmt(ln.this_deduct)}</span></div>`;
            html+=`<div class="loan-progress-bar"><div class="loan-progress-fill" style="width:${pct.toFixed(1)}%"></div></div>`;
            html+=`<div style="font-size:9px;color:#9ca3af;margin-top:3px;text-align:right;">${pct.toFixed(1)}% repaid</div></div>`;
        });
        html+='</div>';
        const totalLd=loans.reduce((s,ln)=>s+parseFloat(ln.this_deduct),0);
        html+=`<div style="margin-top:10px;background:#ede9fe;border:1px solid #c4b5fd;border-radius:8px;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;"><span style="font-size:12px;font-weight:700;color:#6d28d9;"><i class="fa-solid fa-sigma"></i> Total Loan Deduction</span><span style="font-family:Space Mono,monospace;font-size:16px;font-weight:800;color:#7c3aed;">LKR ${fmt(totalLd)}</span></div></div>`;
    }
    html+='</div>';

    // OVERRIDES
    if(HAS_SAVED_SHEET&&!IS_CONFIRMED){
        html+='<div class="modal-edit-section">';
        html+='<div class="modal-edit-section-title"><i class="fa-solid fa-pen-to-square"></i> Other Adjustments &amp; Overrides</div>';
        html+='<div class="modal-edit-grid">';
        html+=`<div class="modal-edit-field"><label class="modal-edit-label"><i class="fa-solid fa-circle-plus"></i> Arrears Salary (Earning)</label><input type="number" step="0.01" min="0" class="modal-edit-input" id="m_arrears" value="${parseFloat(d.arrears||0).toFixed(2)}" placeholder="0.00"></div>`;
        html+=`<div class="modal-edit-field"><label class="modal-edit-label"><i class="fa-solid fa-circle-minus"></i> Excess Payment (Deduction)</label><input type="number" step="0.01" min="0" class="modal-edit-input" id="m_excess" value="${parseFloat(d.excess_p||0).toFixed(2)}" placeholder="0.00"></div>`;
        html+=`<div class="modal-edit-field"><label class="modal-edit-label" style="color:#a21caf;"><i class="fa-solid fa-rotate-left"></i> Credit Recovery (Deduction)</label><input type="number" step="0.01" min="0" class="modal-edit-input" id="m_credit_r" value="${parseFloat(d.credit_r||0).toFixed(2)}" placeholder="0.00" style="border-color:#f0abfc;background:#fdf4ff;color:#4c1d95;"></div>`;
        html+='</div></div>';
    } else if(IS_CONFIRMED){
        html+='<div style="padding:12px 26px;background:#eff6ff;border-top:1px solid #bfdbfe;font-size:12px;color:#1e3a8a;display:flex;align-items:center;gap:8px;"><i class="fa-solid fa-lock"></i> <strong>Sheet is Confirmed.</strong> No further edits allowed.</div>';
    }

    // MODAL SECTIONS: Earnings + Deductions + Net + Benefit
    html+='<div class="modal-sections">';

    // EARNINGS
    html+='<div class="modal-section"><div class="ms-header ms-earn"><i class="fa-solid fa-arrow-trend-up"></i> Earnings Breakdown</div><div class="ms-body">';
    [['Basic Salary',d.basic,false],['CCFOT Incentive',d.ccfot_amt,true],['Credit Mgt Incentive',d.credit_mgt_amt,true],['Payee Chq Incentive',d.payee_chq_amt,true],['SSV Incentive',d.ssv_cc_amt,true],['Attendance Incentive',d.att_inc_amt,true],['Locus Admin',d.locus_admin_amt,true],['Meal Reimbursement',d.meal,false],['Mobile Reimbursement',d.mobile,false],['Arrears Salary',d.arrears,false]].forEach(([lbl,val,isInc])=>{
        html+=`<div class="ms-row ${val<=0?'ms-zero':isInc?'ms-inc-val':'ms-earn-val'}"><span class="ms-row-label">${lbl}</span><span class="ms-row-value">${lkr(val)}</span></div>`;
    });
    html+='</div>';
    html+='<div style="border-top:2px dashed #7dd3fc;background:#f0f9ff;">';
    html+='<div style="padding:8px 16px 0;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#0369a1;display:flex;align-items:center;gap:6px;"><i class="fa-solid fa-plus-circle"></i> Additional Earnings</div>';
    html+='<div id="addEarnRows" class="add-rows-section"></div>';
    html+='<div class="add-rows-footer" style="background:#f0f9ff;">';
    if(HAS_SAVED_SHEET&&!IS_CONFIRMED)html+='<button class="add-row-btn" style="border-color:#7dd3fc;color:#0369a1;" onclick="addEarnRow(\'\',0)"><i class="fa-solid fa-plus"></i> Add Row</button>';
    else html+='<span style="font-size:11px;color:#9ca3af;">'+(IS_CONFIRMED?'Confirmed — locked':'Save sheet first')+'</span>';
    html+='<span class="add-row-total" id="addEarnTotal" style="color:#0369a1;">Total: LKR 0.00</span></div></div>';
    html+='<div class="ms-total ms-total-earn"><span>Total Earnings</span><span>'+lkrPlain(d.total_earnings)+'</span></div></div>';

    // DEDUCTIONS
    html+='<div class="modal-section"><div class="ms-header ms-ded"><i class="fa-solid fa-circle-minus"></i> Deductions Breakdown</div><div class="ms-body">';
    [['EPF Employee (8%)',d.epf_emp],['Welfare',d.welfare_amt],['Welfare Loan Ded.',d.w_loan],['Welfare Society',d.w_soc],['Donations',d.donations],['Salary Advance',d.sal_adv],['Loan Deduction',d.loan_ded],['No Pay Deduction',d.no_pay_amount],['Retention',d.retention],['Excess Payment',d.excess_p]].forEach(([lbl,val])=>{
        html+=`<div class="ms-row ${val<=0?'ms-zero':'ms-ded-val'}"><span class="ms-row-label">${lbl}</span><span class="ms-row-value">${lkr(val)}</span></div>`;
    });
    html+=`<div class="ms-row ms-cr-val" style="background:#fdf4ff;"><span class="ms-row-label"><i class="fa-solid fa-rotate-left" style="color:#86198f;margin-right:4px;"></i>Credit Recovery</span><span class="ms-row-value" style="color:#86198f;">${d.credit_r>0?'LKR '+fmt(d.credit_r):'<span style="color:#d1d5db;">—</span>'}</span></div>`;
    html+=`<div class="ms-row ms-short-val"><span class="ms-row-label"><i class="fa-solid fa-coins" style="margin-right:4px;"></i>Cash Short</span><span class="ms-row-value">${d.cash_short>0?'LKR '+fmt(d.cash_short):'<span style="color:#d1d5db;">—</span>'}</span></div>`;
    html+=`<div class="ms-row ms-short-val" style="background:#fff7ed;"><span class="ms-row-label"><i class="fa-solid fa-box" style="margin-right:4px;color:#c2410c;"></i>Good Short</span><span class="ms-row-value" style="color:#c2410c;">${d.good_short>0?'LKR '+fmt(d.good_short):'<span style="color:#d1d5db;">—</span>'}</span></div>`;
    html+='</div>';
    html+='<div style="border-top:2px dashed #fca5a5;background:#fff5f5;">';
    html+='<div style="padding:8px 16px 0;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#991b1b;display:flex;align-items:center;gap:6px;"><i class="fa-solid fa-plus-circle"></i> Additional Deductions</div>';
    html+='<div id="addDedRows" class="add-rows-section"></div>';
    html+='<div class="add-rows-footer" style="background:#fff5f5;">';
    if(HAS_SAVED_SHEET&&!IS_CONFIRMED)html+='<button class="add-row-btn" style="border-color:#fca5a5;color:#991b1b;" onclick="addDedRow(\'\',0)"><i class="fa-solid fa-plus"></i> Add Row</button>';
    else html+='<span style="font-size:11px;color:#9ca3af;">'+(IS_CONFIRMED?'Confirmed — locked':'Save sheet first')+'</span>';
    html+='<span class="add-row-total" id="addDedTotal" style="color:#991b1b;">Total: LKR 0.00</span></div></div>';
    html+='<div class="ms-total ms-total-ded"><span>Total Deductions</span><span>'+lkrPlain(d.total_deductions)+'</span></div></div>';

    // NET SUMMARY
    html+='<div class="modal-section"><div class="ms-header ms-att"><i class="fa-solid fa-wallet"></i> Net Salary Summary</div><div class="ms-body">';
    html+=`<div style="text-align:center;padding:20px 16px 16px;"><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:6px;">NET TAKE-HOME SALARY</div><div style="font-family:Space Mono,monospace;font-size:28px;font-weight:800;color:#065f46;">${lkrPlain(d.net_salary)}</div></div>`;
    html+=`<div class="ms-row ms-earn-val"><span class="ms-row-label">Gross Earnings</span><span class="ms-row-value">${lkr(d.total_earnings)}</span></div>`;
    html+=`<div class="ms-row ms-ded-val"><span class="ms-row-label">Total Deductions</span><span class="ms-row-value" style="color:#991b1b;">− ${lkrPlain(d.total_deductions)}</span></div>`;
    if(d.cash_short>0||d.good_short>0)html+=`<div class="ms-row ms-short-val"><span class="ms-row-label">  incl. Cash/Good Short</span><span class="ms-row-value">LKR ${fmt(d.cash_short+d.good_short)}</span></div>`;
    html+=`<div class="ms-row" style="border-top:2px solid var(--border2);padding-top:10px;font-weight:700;"><span class="ms-row-label">Net Salary</span><span class="ms-row-value" style="color:#065f46;font-size:14px;">${lkrPlain(d.net_salary)}</span></div>`;
    html+='</div><div class="ms-total ms-total-att"><span>Net Take-Home</span><span>'+lkrPlain(d.net_salary)+'</span></div></div>';

    // BENEFIT
    html+='<div class="modal-section"><div class="ms-header ms-ben"><i class="fa-solid fa-star"></i> Employer Cost &amp; Benefit</div><div class="ms-body">';
    html+=`<div class="ms-row" style="background:#fffbeb;"><span class="ms-row-label" style="font-weight:700;font-size:11px;color:#78350f;text-transform:uppercase;"><i class="fa-solid fa-calculator"></i> Cost of BP</span><span class="ms-row-value" style="color:#78350f;">${lkrPlain(d.cost_bp)}</span></div>`;
    [['Basic',d.basic],['Meal',d.meal],['Mobile',d.mobile],['EPF Employer (12%)',d.epf_er],['ETF Employer (3%)',d.etf_er],['Bonus (÷12)',d.bonus]].forEach(([l,v])=>{html+=`<div class="ms-row ${v<=0?'ms-zero':''}"><span class="ms-row-label" style="font-size:11px;">&nbsp;&nbsp;${l}</span><span class="ms-row-value" style="font-size:11px;">${lkr(v)}</span></div>`;});
    html+=`<div class="ms-row" style="background:#fffbeb;border-top:1px dashed #fde68a;"><span class="ms-row-label" style="font-weight:700;font-size:11px;color:#78350f;text-transform:uppercase;"><i class="fa-solid fa-coins"></i> Provisions (Bonus+Gratuity)</span><span class="ms-row-value" style="color:#78350f;">${lkrPlain(d.bonus+d.gratuity)}</span></div>`;
    [['Bonus (÷12)',d.bonus],['Gratuity (÷24)',d.gratuity],['Insurance',d.insurance],['EPF Employer',d.epf_er],['ETF Employer',d.etf_er]].forEach(([l,v])=>{html+=`<div class="ms-row ${v<=0?'ms-zero':''}"><span class="ms-row-label" style="font-size:11px;">&nbsp;&nbsp;${l}</span><span class="ms-row-value" style="font-size:11px;">${lkr(v)}</span></div>`;});
    html+='</div><div class="ms-total ms-total-ben"><span>Total Benefit</span><span>'+lkrPlain(d.total_benefit)+'</span></div></div>';

    html+='</div>'; // end modal-sections

    document.getElementById('modalBody').innerHTML=html;
    renderAddRows();updateAddTotals();
    const statusEl=document.getElementById('modalUpdateStatus');if(statusEl)statusEl.innerHTML='';
    document.getElementById('detailModal').classList.add('show');
}
function closeModal(){document.getElementById('detailModal').classList.remove('show');_currentModalEid=null;}

function liveFilter(q){
    q=q.trim().toLowerCase();
    document.querySelectorAll('.emp-row').forEach(row=>{
        const s=row.getAttribute('data-search')||'';
        row.style.display=(q!==''&&!s.includes(q))?'none':'';
    });
}
function toggleFilter(){
    const body=document.getElementById('filterBody');const ch=document.getElementById('filterChevron');
    const open=body.classList.toggle('open');ch.style.transform=open?'rotate(180deg)':'';
}

function exportCSV(){
    const rows=[['Employee Code','EPF Number','Date of Join','Name','Cat','Status','Designation',
        'Norm Days','Att Days','Att Rate%','No Pay Days','No Pay Amt',
        'Basic','CCFOT','Credit Mgt','Payee Chq','SSV','Att Incentive','Locus Admin','Meal','Mobile','Arrears',
        'Total Earnings','EPF 8%','Welfare','Sal.Advance','Loan Ded.','Cash Short','Good Short','Credit Recovery','No Pay Amt','Retention','Excess',
        'Total Deductions','Net Salary','EPF 12%','ETF 3%','Bonus','Gratuity','Total Benefit']];
    <?php foreach ($employees as $emp): $eid=(int)$emp['id']; $r=$rows[$eid]; $att=$r['att']; ?>
    rows.push([
        <?php echo json_encode($emp['employee_id']); ?>,
        <?php echo json_encode($emp['epf_number']??''); ?>,
        <?php echo json_encode($emp['date_of_join']?date('d M Y',strtotime($emp['date_of_join'])):''); ?>,
        <?php echo json_encode($emp['employee_full_name']); ?>,
        <?php echo json_encode($emp['category_code']??'CC'); ?>,
        <?php echo json_encode($emp['status']); ?>,
        <?php echo json_encode($emp['designation_name']??''); ?>,
        <?php echo $att['norm']; ?>,<?php echo $att['att']; ?>,<?php echo $att['rate']; ?>,
        <?php echo number_format($r['no_pay_days'],2,'.',''); ?>,<?php echo $r['no_pay_amt']; ?>,
        <?php echo $r['basic']; ?>,<?php echo $r['ccfot_amt']; ?>,<?php echo $r['credit_mgt_amt']; ?>,
        <?php echo $r['payee_chq_amt']; ?>,<?php echo $r['ssv_cc_amt']; ?>,<?php echo $r['att_inc_amt']; ?>,
        <?php echo $r['locus_admin_amt']; ?>,<?php echo $r['meal_calc']; ?>,<?php echo $r['mobile']; ?>,<?php echo $r['arrears']; ?>,
        <?php echo $r['total_earnings']; ?>,<?php echo $r['epf_emp']; ?>,<?php echo $r['welfare_amt']; ?>,
        <?php echo $r['sal_adv']; ?>,<?php echo $r['loan_ded']; ?>,<?php echo $r['cash_short']; ?>,<?php echo $r['good_short']; ?>,
        <?php echo $r['credit_r']; ?>,<?php echo $r['no_pay_amt']; ?>,<?php echo $r['retention']; ?>,<?php echo $r['excess_p']; ?>,
        <?php echo $r['total_deductions']; ?>,<?php echo $r['net_salary']; ?>,
        <?php echo $r['epf_er']; ?>,<?php echo $r['etf_er']; ?>,<?php echo $r['bonus']; ?>,<?php echo $r['gratuity']; ?>,
        <?php echo $r['total_benefit']; ?>
    ]);
    <?php endforeach; ?>
    const csv=rows.map(r=>r.map(c=>'"'+String(c).replace(/"/g,'""')+'"').join(',')).join('\n');
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));
    a.download='cc_salary_<?php echo $active_period?$active_period['year'].'_'.$active_period['month']:'export'; ?>.csv';
    a.click();
}
</script>

<?php include 'footer.php'; ?>