<?php
ob_start();
include 'config.php';

// ── Ensure tables exist ─────────────────────────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS incentive_entries (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        incentive_type_id INT NOT NULL,
        employee_id       INT NOT NULL,
        payroll_period_id INT NOT NULL,
        component_label   VARCHAR(255) NOT NULL,
        amount            DECIMAL(12,2) NOT NULL DEFAULT 0,
        notes             TEXT NULL,
        entered_by        INT NULL,
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_type_emp_period (incentive_type_id, employee_id, payroll_period_id),
        INDEX idx_period (payroll_period_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Params ───────────────────────────────────────────────────────────────────
$incentive_type_id = isset($_GET['type_id']) ? intval($_GET['type_id']) : 0;
if (!$incentive_type_id) { die('<p style="color:red;padding:30px;">Invalid incentive type.</p>'); }

// ── Load incentive type ───────────────────────────────────────────────────────
$type_res = mysqli_query($conn, "SELECT * FROM incentive_types WHERE id = $incentive_type_id");
if (!$type_res || mysqli_num_rows($type_res) === 0) { die('<p style="color:red;padding:30px;">Incentive type not found.</p>'); }
$inc_type         = mysqli_fetch_assoc($type_res);
$calc_rules       = json_decode($inc_type['calculation_rules'] ?? '[]', true) ?: [];
$cat_ids          = array_filter(array_map('intval', explode(',', $inc_type['allowed_category_ids'] ?? '')));
$is_discretionary = !empty($inc_type['discretionary_support']);

// ── Detect staff category code for this incentive type ───────────────────────
$page_cat_code = 'SR';
$page_cat_name = '';
if (!empty($cat_ids)) {
    $cid_in = implode(',', $cat_ids);
    $cat_det = mysqli_query($conn, "SELECT category_code, category_name FROM staff_categories WHERE id IN ($cid_in) LIMIT 1");
    if ($cat_det && ($crow = mysqli_fetch_assoc($cat_det))) {
        $page_cat_code = strtoupper(trim($crow['category_code'] ?? 'SR'));
        $page_cat_name = $crow['category_name'] ?? '';
    }
}

// ── Salary config schemas ─────────────────────────────────────────────────────
$category_schemas = [
    'SR' => [
        'fixed' => [
            ['basic_salary',          'Basic Salary'],
            ['discretionary_support', 'Disc. Support'],
        ],
        'incentive_groups' => [
            ['key'=>'total_ps_compliance', 'label'=>'Total PS Compliance', 'cfg_field'=>'total_ps_compliance', 'default_threshold'=>80,  'color'=>'green'],
            ['key'=>'eco_incentive',       'label'=>'ECO Incentive',        'cfg_field'=>'eco_incentive',       'default_threshold'=>95,  'color'=>'green'],
            ['key'=>'bp_incentive',        'label'=>'BP Incentive',         'cfg_field'=>'bp_incentive',        'default_threshold'=>90,  'color'=>'green'],
            ['key'=>'total_assortment',    'label'=>'Total Assortment',     'cfg_field'=>'total_assortment',    'default_threshold'=>100, 'color'=>'green'],
        ],
        'has_incent_rate_headers' => true,
        'ssv_rates'  => true,
        'ssv_position' => 'after_fixed',
        'has_other_incentive' => true,
        'reimb' => [
            ['meal_reimbursement',      'Meal'],
            ['traveling_reimbursement', 'Traveling'],
            ['mobile_reimbursement',    'Mobile'],
        ],
        'reimb_direct' => false,
        'hide_attrate' => false,
        'fields' => ['basic_salary','discretionary_support','total_ps_compliance','eco_incentive','bp_incentive','total_assortment','meal_reimbursement','traveling_reimbursement','mobile_reimbursement'],
    ],
    'MR' => [
        'fixed' => [
            ['basic_salary', 'Basic Salary'],
        ],
        'incentive_groups' => [
            ['key'=>'placement_compliance', 'label'=>'Placement Compliance', 'cfg_field'=>'placement_compliance_90', 'default_threshold'=>0, 'color'=>'indigo'],
        ],
        'has_incent_rate_headers' => false,
        'ssv_rates'   => false,
        'ssv_position'=> 'after_fixed',
        'reimb' => [
            ['meal_reimbursement',      'Meal'],
            ['traveling_reimbursement', 'Traveling'],
        ],
        'reimb_direct' => false,
        'hide_attrate' => false,
        'fields' => ['basic_salary','placement_compliance','meal_reimbursement','traveling_reimbursement'],
    ],
    // ── CC: Cash Collector — collapsed tier groups ────────────────────────────
    'CC' => [
        'fixed' => [
            ['basic_salary', 'Basic Salary'],
        ],
        'incentive_groups' => [
            ['key'=>'ccfot',        'label'=>'CCFOT',       'cfg_field'=>'ccfot_90',      'default_threshold'=>90, 'color'=>'blue'],
            ['key'=>'credit_mgt',   'label'=>'Credit Mgt',  'cfg_field'=>'credit_mgt_85', 'default_threshold'=>85, 'color'=>'green'],
            ['key'=>'payee_chq',    'label'=>'Payee Chq',   'cfg_field'=>'payee_chq_80',  'default_threshold'=>80, 'color'=>'orange'],
            ['key'=>'ssv_cc',       'label'=>'SSV',         'cfg_field'=>'ssv_90',        'default_threshold'=>90, 'color'=>'cyan'],
            ['key'=>'attendance_cc','label'=>'Attendance',  'cfg_field'=>'attendance_85', 'default_threshold'=>85, 'color'=>'teal'],
            ['key'=>'locus_admin',  'label'=>'Locus Admin', 'cfg_field'=>'locus_admin',   'default_threshold'=>100,'color'=>'slate'],
        ],
        'has_incent_rate_headers' => false,
        'ssv_rates'   => false,
        'ssv_position'=> 'after_fixed',
        'reimb' => [
            ['meal_reimbursement',   'Meal'],
            ['mobile_reimbursement', 'Mobile'],
        ],
        'reimb_direct' => false,
        'hide_attrate' => true,
        'cc_tier_defs' => [
            'ccfot'         => [
                ['threshold'=>90,'cfg_field'=>'ccfot_90'],
                ['threshold'=>91,'cfg_field'=>'ccfot_91'],
                ['threshold'=>92,'cfg_field'=>'ccfot_92'],
                ['threshold'=>93,'cfg_field'=>'ccfot_93'],
                ['threshold'=>94,'cfg_field'=>'ccfot_94'],
                ['threshold'=>95,'cfg_field'=>'ccfot_95'],
            ],
            'credit_mgt'    => [
                ['threshold'=>85,'cfg_field'=>'credit_mgt_85'],
                ['threshold'=>90,'cfg_field'=>'credit_mgt_90'],
                ['threshold'=>95,'cfg_field'=>'credit_mgt_95'],
            ],
            'payee_chq'     => [
                ['threshold'=>80,'cfg_field'=>'payee_chq_80'],
            ],
            'ssv_cc'        => [
                ['threshold'=>90,'cfg_field'=>'ssv_90'],
                ['threshold'=>95,'cfg_field'=>'ssv_95'],
            ],
            'attendance_cc' => [
                ['threshold'=>85,'cfg_field'=>'attendance_85'],
                ['threshold'=>90,'cfg_field'=>'attendance_90'],
            ],
            'locus_admin'   => [
                ['threshold'=>100,'cfg_field'=>'locus_admin'],
            ],
        ],
        'fields' => ['basic_salary','ccfot_90','ccfot_91','ccfot_92','ccfot_93','ccfot_94','ccfot_95','credit_mgt_85','credit_mgt_90','credit_mgt_95','payee_chq_80','ssv_90','ssv_95','attendance_85','attendance_90','locus_admin','meal_reimbursement','mobile_reimbursement'],
    ],
    // ── ACC: Assistant Cash Collector — collapsed tier groups ─────────────────
    'ACC' => [
        'fixed' => [
            ['basic_salary', 'Basic Salary'],
        ],
        // Collapsed: one row per metric, tiers resolved in JS via acc_tier_defs
        'incentive_groups' => [
            ['key'=>'ccfot_acc',       'label'=>'CCFOT',       'cfg_field'=>'ccfot_90',       'default_threshold'=>90, 'color'=>'blue'],
            ['key'=>'punctuality_acc', 'label'=>'Punctuality',  'cfg_field'=>'punctuality_70', 'default_threshold'=>70, 'color'=>'violet'],
            ['key'=>'attendance_acc',  'label'=>'Attendance',   'cfg_field'=>'attendance_85',  'default_threshold'=>85, 'color'=>'teal'],
        ],
        'has_incent_rate_headers' => false,
        'ssv_rates'   => true,
        'ssv_position'=> 'before_attendance',
        'reimb' => [
            ['meal_reimbursement', 'Meal'],
        ],
        'reimb_direct' => false,
        'hide_attrate' => true,
        // ACC tier definitions (key => array of [threshold, cfg_field])
        'acc_tier_defs' => [
            'ccfot_acc' => [
                ['threshold'=>90,'cfg_field'=>'ccfot_90'],
                ['threshold'=>91,'cfg_field'=>'ccfot_91'],
                ['threshold'=>92,'cfg_field'=>'ccfot_92'],
                ['threshold'=>93,'cfg_field'=>'ccfot_93'],
                ['threshold'=>94,'cfg_field'=>'ccfot_94'],
                ['threshold'=>95,'cfg_field'=>'ccfot_95'],
            ],
            'punctuality_acc' => [
                ['threshold'=>70,'cfg_field'=>'punctuality_70'],
                ['threshold'=>80,'cfg_field'=>'punctuality_80'],
            ],
            'attendance_acc' => [
                ['threshold'=>85,'cfg_field'=>'attendance_85'],
                ['threshold'=>90,'cfg_field'=>'attendance_90'],
            ],
        ],
        'fields' => ['basic_salary','ccfot_90','ccfot_91','ccfot_92','ccfot_93','ccfot_94','ccfot_95','punctuality_70','punctuality_80','attendance_85','attendance_90','meal_reimbursement'],
    ],
    'ST' => [
        'fixed' => [
            ['basic_salary', 'Basic Salary'],
        ],
        'incentive_groups' => [
            ['key'=>'stores_damage',    'label'=>'Damage',          'cfg_field'=>'stores_damage_lo', 'default_threshold'=>0,  'color'=>'red'],
            ['key'=>'rsqm',             'label'=>'RSQM',            'cfg_field'=>'rsqm_60',          'default_threshold'=>60, 'color'=>'blue'],
            ['key'=>'punctuality',      'label'=>'Punctuality',     'cfg_field'=>'punctuality_75',   'default_threshold'=>75, 'color'=>'violet'],
            ['key'=>'loading_unloading','label'=>'L/Unloading 90%', 'cfg_field'=>'loading_unloading','default_threshold'=>90, 'color'=>'orange'],
            ['key'=>'ccfot_st',         'label'=>'CCFOT 90%',       'cfg_field'=>'ccfot_st',         'default_threshold'=>90, 'color'=>'green'],
        ],
        'has_incent_rate_headers' => false,
        'ssv_rates'   => false,
        'ssv_position'=> 'after_fixed',
        'st_tier_groups' => [
            'rsqm'        => [['threshold'=>60,'cfg_field'=>'rsqm_60'],['threshold'=>70,'cfg_field'=>'rsqm_70']],
            'punctuality' => [['threshold'=>75,'cfg_field'=>'punctuality_75'],['threshold'=>85,'cfg_field'=>'punctuality_85']],
        ],
        'reimb' => [
            ['meal_reimbursement', 'Meal'],
        ],
        'reimb_direct' => false,
        'hide_attrate' => false,
        'fields' => ['basic_salary','stores_damage','rsqm','punctuality','loading_unloading','ccfot_st','meal_reimbursement'],
    ],
    // ── OFF: Back Office Team ─────────────────────────────────────────────────
    'OFF' => [
        'fixed' => [
            ['basic_salary', 'Basic Salary'],
        ],
        'incentive_groups' => [
            ['key'=>'incentive_daily_90',  'label'=>'Daily Incentive',  'cfg_field'=>'incentive_daily_90',  'default_threshold'=>90, 'color'=>'blue'],
            ['key'=>'incentive_weekly_90', 'label'=>'Weekly Incentive', 'cfg_field'=>'incentive_weekly_90', 'default_threshold'=>90, 'color'=>'indigo'],
            ['key'=>'incentive_monthly_90','label'=>'Monthly Incentive','cfg_field'=>'incentive_monthly_90','default_threshold'=>90, 'color'=>'violet'],
            ['key'=>'punctuality_75',      'label'=>'Punct. 75%',       'cfg_field'=>'punctuality_75',      'default_threshold'=>75, 'color'=>'amber'],
            ['key'=>'attendance_85',       'label'=>'Att. 85%',         'cfg_field'=>'attendance_85',       'default_threshold'=>85, 'color'=>'teal'],
            ['key'=>'attendance_90',       'label'=>'Att. 90%',         'cfg_field'=>'attendance_90',       'default_threshold'=>90, 'color'=>'teal'],
        ],
        'has_incent_rate_headers' => false,
        'ssv_rates'   => false,
        'ssv_position'=> 'after_fixed',
        'reimb_direct' => true,
        'reimb' => [
            ['meal_reimbursement', 'Meal'],
        ],
        'hide_attrate' => false,
        'fields' => ['basic_salary','incentive_daily_90','incentive_weekly_90','incentive_monthly_90','punctuality_75','attendance_85','attendance_90','meal_reimbursement'],
    ],
];

// Resolve schema for this page's category code
$page_schema        = $category_schemas[$page_cat_code] ?? $category_schemas['SR'];
$schema_incent_groups = $page_schema['incentive_groups'];
$schema_reimb         = $page_schema['reimb'];
$schema_has_ssv       = !empty($page_schema['ssv_rates']);
$schema_ssv_position  = $page_schema['ssv_position'] ?? 'after_fixed';
$schema_fields        = $page_schema['fields'];
$schema_has_ir_hdrs   = $page_schema['has_incent_rate_headers'] ?? false;
$schema_hide_attrate  = !empty($page_schema['hide_attrate']);
$schema_reimb_direct  = !empty($page_schema['reimb_direct']);
$schema_has_other_incentive = !empty($page_schema['has_other_incentive']);
$st_tier_defs         = $page_schema['st_tier_groups'] ?? [];
$cc_tier_defs         = $page_schema['cc_tier_defs']   ?? [];
$acc_tier_defs        = $page_schema['acc_tier_defs']  ?? [];
$page_is_cc           = ($page_cat_code === 'CC');
$page_is_acc          = ($page_cat_code === 'ACC');

// ── Reimbursement fields that are ALWAYS fixed (not scaled by attendance rate) ──
// Mobile reimbursement should never be reduced/scaled by the attendance rate,
// regardless of the schema's reimb_direct setting. Only meal/traveling remain
// attendance-rate-scaled (unless the schema itself is fully direct).
$fixed_reimb_fields = ['mobile_reimbursement'];

// Incent colour palette
$incent_color_map = [
    'blue'   => ['hdr_bg'=>'#061a35','hdr_text'=>'#93c5fd','border'=>'#1d4ed8'],
    'green'  => ['hdr_bg'=>'#061a10','hdr_text'=>'#6ee7b7','border'=>'#059669'],
    'teal'   => ['hdr_bg'=>'#04191e','hdr_text'=>'#5eead4','border'=>'#0d9488'],
    'indigo' => ['hdr_bg'=>'#0d0f28','hdr_text'=>'#a5b4fc','border'=>'#4338ca'],
    'violet' => ['hdr_bg'=>'#120d28','hdr_text'=>'#c4b5fd','border'=>'#7c3aed'],
    'amber'  => ['hdr_bg'=>'#1a1200','hdr_text'=>'#fcd34d','border'=>'#d97706'],
    'orange' => ['hdr_bg'=>'#1f1005','hdr_text'=>'#fdba74','border'=>'#c2410c'],
    'cyan'   => ['hdr_bg'=>'#04191e','hdr_text'=>'#67e8f9','border'=>'#0891b2'],
    'red'    => ['hdr_bg'=>'#1f0505','hdr_text'=>'#fca5a5','border'=>'#dc2626'],
    'slate'  => ['hdr_bg'=>'#0f1117','hdr_text'=>'#94a3b8','border'=>'#475569'],
];

// ── Payroll periods ───────────────────────────────────────────────────────────
$sel_period_id = isset($_GET['period_id']) ? intval($_GET['period_id']) : 0;
$active_period = null;
$all_periods   = [];

$pp_cols       = mysqli_query($conn, "SHOW COLUMNS FROM payroll_periods LIKE 'status'");
$pp_has_status = ($pp_cols && mysqli_num_rows($pp_cols) > 0);
$pp_sql        = $pp_has_status
    ? "SELECT id, year, month, status FROM payroll_periods ORDER BY year DESC, month DESC"
    : "SELECT id, year, month, 'Open' AS status FROM payroll_periods ORDER BY year DESC, month DESC";

$all_periods_res = mysqli_query($conn, $pp_sql);
if ($all_periods_res) { while ($p = mysqli_fetch_assoc($all_periods_res)) $all_periods[] = $p; }

if (!$sel_period_id) {
    foreach ($all_periods as $pp) { if ($pp['status'] === 'Open') { $sel_period_id = $pp['id']; break; } }
    if (!$sel_period_id && !empty($all_periods)) $sel_period_id = $all_periods[0]['id'];
}
foreach ($all_periods as $pp) { if ($pp['id'] == $sel_period_id) { $active_period = $pp; break; } }

// ── Employees ────────────────────────────────────────────────────────────────
// Resigned/Terminated employees should still appear for the payroll period
// covering their exit month (and any earlier period), based on exit_date,
// but must be excluded from any payroll period AFTER their exit month.
// Example: exit_date = 2026-08-20, payroll period = August 2026 (period
// start 2026-08-01, period end 2026-08-31) -> exit_date falls INSIDE this
// base month -> employee STILL shows. For September 2026 onward (period
// start 2026-09-01), exit_date is before the period start -> excluded.
//
// The payroll period's "base month" is identified by its name (e.g.
// "August 2026"), derived from the selected period's year/month columns.
$period_month_names = ['','January','February','March','April','May','June',
                        'July','August','September','October','November','December'];
$period_start_date  = null;
$period_end_date     = null;
$period_month_label  = '';
if ($active_period) {
    $py = (int)$active_period['year'];
    $pm = (int)$active_period['month'];
    $period_start_date  = sprintf('%04d-%02d-01', $py, $pm);
    $period_end_date    = date('Y-m-t', strtotime($period_start_date));
    $period_month_label = ($period_month_names[$pm] ?? '') . ' ' . $py; // e.g. "August 2026"
}

// Show the employee if:
//  - status is Probation/Permanent (always shown), OR
//  - status is Resigned/Terminated AND their exit_date falls inside this
//    payroll base month, OR any month BEFORE it (exit_date >= period start).
//  They are hidden once the selected payroll period moves past their exit
//  month (i.e. period start date is after their exit_date).
$emp_status_clause = $period_start_date
    ? "(e.status NOT IN ('Resigned','Terminated') OR (e.status IN ('Resigned','Terminated') AND e.exit_date IS NOT NULL AND e.exit_date >= '$period_start_date'))"
    : "e.status NOT IN ('Resigned','Terminated')";

$emp_where = "e.active = 1 AND $emp_status_clause";
if (!empty($cat_ids)) {
    $cat_in     = implode(',', $cat_ids);
    $emp_where .= " AND (e.staff_category_id IN ($cat_in) OR d.staff_category_id IN ($cat_in))";
}
$emp_result = mysqli_query($conn, "
    SELECT e.id, e.employee_id, e.employee_full_name, e.name_with_initials,
           e.telephone_mobile, e.date_of_join,
           e.designation_id,
           COALESCE(e.staff_category_id, d.staff_category_id) AS staff_category_id,
           d.designation_name, sc.category_name, sc.category_code,
           e.status, e.exit_date
    FROM employees e
    LEFT JOIN designations d      ON e.designation_id = d.id
    LEFT JOIN staff_categories sc ON (e.staff_category_id = sc.id OR d.staff_category_id = sc.id)
    WHERE $emp_where ORDER BY e.employee_full_name
");
$employees = []; $emp_ids = [];
if ($emp_result) {
    while ($e = mysqli_fetch_assoc($emp_result)) {
        if (!in_array($e['id'], $emp_ids)) { $employees[] = $e; $emp_ids[] = $e['id']; }
    }
}
if (empty($employees)) {
    $emp_result2 = mysqli_query($conn, "
        SELECT e.id, e.employee_id, e.employee_full_name, e.name_with_initials,
               e.telephone_mobile, e.date_of_join, e.designation_id,
               e.staff_category_id,
               d.designation_name,
               '' AS category_name, '' AS category_code,
               e.status, e.exit_date
        FROM employees e LEFT JOIN designations d ON e.designation_id = d.id
        WHERE e.active = 1 AND $emp_status_clause
        ORDER BY e.employee_full_name
    ");
    if ($emp_result2) { while ($e = mysqli_fetch_assoc($emp_result2)) $employees[] = $e; }
}
$emp_ids = array_column($employees, 'id');

// ── Load sr_salary_config + secondary rates + incent rates per designation ───
$all_desig_ids      = array_unique(array_filter(array_column($employees, 'designation_id')));
$desig_salary_cfg   = [];
$desig_ssv_rates    = [];
$desig_incent_rates = [];

if (!empty($all_desig_ids)) {
    $did_in = implode(',', array_map('intval', $all_desig_ids));

    // collect all cfg fields needed including ST tier fields, CC tier fields, ACC tier fields
    $extra_st_fields = [];
    foreach ($st_tier_defs as $tiers) {
        foreach ($tiers as $t) $extra_st_fields[] = $t['cfg_field'];
    }
    $extra_cc_fields = [];
    foreach ($cc_tier_defs as $tiers) {
        foreach ($tiers as $t) $extra_cc_fields[] = $t['cfg_field'];
    }
    $extra_acc_fields = [];
    foreach ($acc_tier_defs as $tiers) {
        foreach ($tiers as $t) $extra_acc_fields[] = $t['cfg_field'];
    }

    $cfg_select_fields = array_unique(array_merge(
        ['designation_id','basic_salary','discretionary_support','meal_reimbursement','traveling_reimbursement','mobile_reimbursement'],
        ['placement_compliance_70','placement_compliance_80','placement_compliance_90'],
        ['incentive_daily_90','incentive_weekly_90','incentive_monthly_90'],
        array_column($schema_incent_groups, 'cfg_field'),
        array_map(fn($r) => $r[0], $schema_reimb),
        $extra_st_fields,
        $extra_cc_fields,
        $extra_acc_fields
    ));
    $cfg_sel_str = implode(',', array_map(fn($f) => "`$f`", $cfg_select_fields));

    $cfg_res = mysqli_query($conn, "
        SELECT $cfg_sel_str
        FROM sr_salary_config
        WHERE designation_id IN ($did_in)
    ");
    if ($cfg_res) {
        while ($row = mysqli_fetch_assoc($cfg_res)) {
            $desig_salary_cfg[$row['designation_id']] = $row;
        }
    }

    if ($schema_has_ssv) {
        $ssv_res = mysqli_query($conn, "
            SELECT designation_id, rate_label, amount
            FROM sr_secondary_sales_rates
            WHERE designation_id IN ($did_in)
            ORDER BY sort_order, id
        ");
        if ($ssv_res) {
            while ($row = mysqli_fetch_assoc($ssv_res)) {
                $desig_ssv_rates[$row['designation_id']][] = [
                    'rate_label' => $row['rate_label'],
                    'amount'     => $row['amount'],
                ];
            }
        }
    }

    if ($schema_has_ir_hdrs) {
        $ir_res = mysqli_query($conn, "
            SELECT designation_id, incent_key, rate_value
            FROM sr_incentive_rates
            WHERE designation_id IN ($did_in)
        ");
        if ($ir_res) {
            while ($row = mysqli_fetch_assoc($ir_res)) {
                $desig_incent_rates[$row['designation_id']][$row['incent_key']] = $row['rate_value'];
            }
        }
    }
}

// ── Build per-employee lookups ────────────────────────────────────────────────
$emp_salary_cfg   = [];
$emp_ssv_rates    = [];
$emp_incent_rates = [];
$emp_ssv_lookup   = [];

foreach ($employees as $emp) {
    $did = $emp['designation_id'];
    $emp_salary_cfg[$emp['id']]   = $desig_salary_cfg[$did]   ?? [];
    $emp_ssv_rates[$emp['id']]    = $desig_ssv_rates[$did]    ?? [];
    $emp_incent_rates[$emp['id']] = $desig_incent_rates[$did] ?? [];
}
foreach ($employees as $emp) {
    $eid = $emp['id'];
    foreach ($emp_ssv_rates[$eid] as $r) {
        $emp_ssv_lookup[$eid][$r['rate_label']] = $r['amount'];
    }
}

// Incentive definitions for JS
$incent_defs_js = [];
foreach ($schema_incent_groups as $grp) {
    $incent_defs_js[] = [
        'key'               => $grp['key'],
        'label'             => $grp['label'],
        'cfg_field'         => $grp['cfg_field'],
        'default_threshold' => $grp['default_threshold'],
        'direct_amount'     => false,
    ];
}

$incent_defaults = [
    'total_ps_compliance' => '80%',
    'eco_incentive'       => '95%',
    'bp_incentive'        => '90%',
    'total_assortment'    => '100%',
];

// ── Batch fetch attendance rate ───────────────────────────────────────────────
$emp_att_rate_map = [];

if ($active_period && !empty($employees)) {
    $pp_year  = (int)$active_period['year'];
    $pp_month = (int)$active_period['month'];
    $from_date = sprintf('%04d-%02d-01', $pp_year, $pp_month);
    $to_date   = date('Y-m-t', strtotime($from_date));
    $month_total_days = (int)date('t', strtotime($from_date));

    $sh_res2 = mysqli_query($conn,
        "SELECT * FROM special_holidays WHERE active=1 AND date_from<='$to_date' AND date_to>='$from_date'"
    );
    $sh_rows2 = [];
    if ($sh_res2) while ($sh = mysqli_fetch_assoc($sh_res2)) $sh_rows2[] = $sh;

    $sh_date_map2 = [];
    foreach ($sh_rows2 as $sh) {
        $start = new DateTime(max($sh['date_from'], $from_date));
        $end   = new DateTime(min($sh['date_to'],   $to_date));
        $end->modify('+1 day');
        foreach (new DatePeriod($start, new DateInterval('P1D'), $end) as $dt) {
            $d = $dt->format('Y-m-d');
            if (!isset($sh_date_map2[$d])) $sh_date_map2[$d] = [];
            $sh_date_map2[$d][] = $sh;
        }
    }

    $emp_ids_str = implode(',', array_map('intval', $emp_ids)) ?: '0';

    $att_cnt_res = mysqli_query($conn,
        "SELECT employee_id, COUNT(*) AS att_count FROM attendance
         WHERE employee_id IN ($emp_ids_str)
           AND att_date BETWEEN '$from_date' AND '$to_date'
         GROUP BY employee_id"
    );
    $emp_att_count = [];
    if ($att_cnt_res) while ($row = mysqli_fetch_assoc($att_cnt_res)) {
        $emp_att_count[(int)$row['employee_id']] = (int)$row['att_count'];
    }

    foreach ($employees as $emp) {
        $eid = (int)$emp['id'];
        $att_count = $emp_att_count[$eid] ?? 0;

        $holiday_count = 0;
        foreach ($sh_date_map2 as $date => $shs) {
            foreach ($shs as $sh) {
                $applies = false;
                if ($sh['target_type'] === 'all') {
                    $applies = true;
                } elseif (!empty($sh['target_ids'])) {
                    $ids = json_decode($sh['target_ids'], true) ?? [];
                    if ($sh['target_type'] === 'employee'       && in_array($eid,                              $ids)) $applies = true;
                    if ($sh['target_type'] === 'staff_category' && in_array((int)($emp['staff_category_id']??0), $ids)) $applies = true;
                    if ($sh['target_type'] === 'designation'    && in_array((int)($emp['designation_id']??0),    $ids)) $applies = true;
                }
                if ($applies) { $holiday_count++; break; }
            }
        }

        $norm_days = $month_total_days - $holiday_count;
        $att_rate  = ($norm_days > 0) ? round(($att_count / $norm_days) * 100, 2) : 0.0;
        $emp_att_rate_map[$eid] = [
            'att_count'     => $att_count,
            'norm_days'     => $norm_days,
            'holiday_count' => $holiday_count,
            'att_rate'      => $att_rate,
        ];
    }
}

// ── Existing entries ──────────────────────────────────────────────────────────
$existing = [];
if ($sel_period_id) {
    $ex_res = mysqli_query($conn, "
        SELECT employee_id, component_label, amount, notes
        FROM incentive_entries
        WHERE incentive_type_id=$incentive_type_id AND payroll_period_id=$sel_period_id
    ");
    if ($ex_res) {
        while ($ex = mysqli_fetch_assoc($ex_res)) {
            $existing[$ex['employee_id']][$ex['component_label']] = $ex;
        }
    }
}

// ── SAVE ─────────────────────────────────────────────────────────────────────
$save_msg = ''; $save_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_entries'])) {
    $post_period = intval($_POST['period_id']);
    $entries     = $_POST['entry'] ?? [];
    $saved       = 0;

    foreach ($entries as $emp_id => $comps) {
        $emp_id = intval($emp_id);
        foreach ($comps as $comp_label => $sub) {

            if ($comp_label === '__disc') {
                if (!isset($sub['disc_amount'])) continue;
                $val       = floatval($sub['disc_amount']);
                $db_label  = '__disc_amount';
                $notes_esc = mysqli_real_escape_string($conn, $_POST['disc_notes'][$emp_id] ?? '');
                $chk = mysqli_query($conn, "SELECT id FROM incentive_entries WHERE incentive_type_id=$incentive_type_id AND employee_id=$emp_id AND payroll_period_id=$post_period AND component_label='$db_label'");
                if ($chk && mysqli_num_rows($chk) > 0) {
                    $chkr = mysqli_fetch_assoc($chk);
                    mysqli_query($conn, "UPDATE incentive_entries SET amount='$val', notes='$notes_esc', updated_at=NOW() WHERE id={$chkr['id']}");
                } else {
                    mysqli_query($conn, "INSERT INTO incentive_entries (incentive_type_id,employee_id,payroll_period_id,component_label,amount,notes) VALUES ($incentive_type_id,$emp_id,$post_period,'$db_label','$val','$notes_esc')");
                }
                $saved++;
                continue;
            }

            if ($comp_label === '__sec_ach') {
                $val      = floatval($sub['pct'] ?? 0);
                $db_label = '__sec_achievement';
                $chk = mysqli_query($conn, "SELECT id FROM incentive_entries WHERE incentive_type_id=$incentive_type_id AND employee_id=$emp_id AND payroll_period_id=$post_period AND component_label='$db_label'");
                if ($chk && mysqli_num_rows($chk) > 0) {
                    $chkr = mysqli_fetch_assoc($chk);
                    mysqli_query($conn, "UPDATE incentive_entries SET amount='$val', updated_at=NOW() WHERE id={$chkr['id']}");
                } else {
                    mysqli_query($conn, "INSERT INTO incentive_entries (incentive_type_id,employee_id,payroll_period_id,component_label,amount,notes) VALUES ($incentive_type_id,$emp_id,$post_period,'$db_label','$val','')");
                }
                $saved++;
                continue;
            }

            if ($comp_label === '__ssv_amt') {
                $val      = floatval($sub['amount'] ?? 0);
                $db_label = '__ssv_amount';
                $chk = mysqli_query($conn, "SELECT id FROM incentive_entries WHERE incentive_type_id=$incentive_type_id AND employee_id=$emp_id AND payroll_period_id=$post_period AND component_label='$db_label'");
                if ($chk && mysqli_num_rows($chk) > 0) {
                    $chkr = mysqli_fetch_assoc($chk);
                    mysqli_query($conn, "UPDATE incentive_entries SET amount='$val', updated_at=NOW() WHERE id={$chkr['id']}");
                } else {
                    mysqli_query($conn, "INSERT INTO incentive_entries (incentive_type_id,employee_id,payroll_period_id,component_label,amount,notes) VALUES ($incentive_type_id,$emp_id,$post_period,'$db_label','$val','')");
                }
                $saved++;
                continue;
            }

            if ($comp_label === '__other_incentive') {
                if (!isset($sub['amount'])) continue;
                $val      = floatval($sub['amount']);
                $db_label = '__other_incentive';
                $chk = mysqli_query($conn, "SELECT id FROM incentive_entries WHERE incentive_type_id=$incentive_type_id AND employee_id=$emp_id AND payroll_period_id=$post_period AND component_label='$db_label'");
                if ($chk && mysqli_num_rows($chk) > 0) {
                    $chkr = mysqli_fetch_assoc($chk);
                    mysqli_query($conn, "UPDATE incentive_entries SET amount='$val', updated_at=NOW() WHERE id={$chkr['id']}");
                } else {
                    mysqli_query($conn, "INSERT INTO incentive_entries (incentive_type_id,employee_id,payroll_period_id,component_label,amount,notes) VALUES ($incentive_type_id,$emp_id,$post_period,'$db_label','$val','')");
                }
                $saved++;
                continue;
            }

            if ($comp_label === '__iach') {
                foreach ($sub as $ikey => $ival) {
                    $ikey_esc = mysqli_real_escape_string($conn, $ikey);
                    $db_label = '__iach__' . $ikey_esc;
                    $val = floatval($ival);
                    $chk = mysqli_query($conn, "SELECT id FROM incentive_entries WHERE incentive_type_id=$incentive_type_id AND employee_id=$emp_id AND payroll_period_id=$post_period AND component_label='$db_label'");
                    if ($chk && mysqli_num_rows($chk) > 0) {
                        $chkr = mysqli_fetch_assoc($chk);
                        mysqli_query($conn, "UPDATE incentive_entries SET amount='$val', updated_at=NOW() WHERE id={$chkr['id']}");
                    } else {
                        mysqli_query($conn, "INSERT INTO incentive_entries (incentive_type_id,employee_id,payroll_period_id,component_label,amount,notes) VALUES ($incentive_type_id,$emp_id,$post_period,'$db_label','$val','')");
                    }
                    $saved++;
                }
                continue;
            }

            if ($comp_label === '__incent_amts') {
                foreach ($sub as $ikey => $ival) {
                    $ikey_esc = mysqli_real_escape_string($conn, $ikey);
                    $db_label = '__incent__' . $ikey_esc;
                    $val = floatval($ival);
                    $chk = mysqli_query($conn, "SELECT id FROM incentive_entries WHERE incentive_type_id=$incentive_type_id AND employee_id=$emp_id AND payroll_period_id=$post_period AND component_label='$db_label'");
                    if ($chk && mysqli_num_rows($chk) > 0) {
                        $chkr = mysqli_fetch_assoc($chk);
                        mysqli_query($conn, "UPDATE incentive_entries SET amount='$val', updated_at=NOW() WHERE id={$chkr['id']}");
                    } else {
                        mysqli_query($conn, "INSERT INTO incentive_entries (incentive_type_id,employee_id,payroll_period_id,component_label,amount,notes) VALUES ($incentive_type_id,$emp_id,$post_period,'$db_label','$val','')");
                    }
                    $saved++;
                }
                continue;
            }

            if ($comp_label === '__reimb') {
                foreach ($sub as $rkey => $rval) {
                    $rkey_esc = mysqli_real_escape_string($conn, $rkey);
                    $db_label = '__reimb__' . $rkey_esc;
                    $val = floatval($rval);
                    $chk = mysqli_query($conn, "SELECT id FROM incentive_entries WHERE incentive_type_id=$incentive_type_id AND employee_id=$emp_id AND payroll_period_id=$post_period AND component_label='$db_label'");
                    if ($chk && mysqli_num_rows($chk) > 0) {
                        $chkr = mysqli_fetch_assoc($chk);
                        mysqli_query($conn, "UPDATE incentive_entries SET amount='$val', updated_at=NOW() WHERE id={$chkr['id']}");
                    } else {
                        mysqli_query($conn, "INSERT INTO incentive_entries (incentive_type_id,employee_id,payroll_period_id,component_label,amount,notes) VALUES ($incentive_type_id,$emp_id,$post_period,'$db_label','$val','')");
                    }
                    $saved++;
                }
                continue;
            }
        }
    }

    $period_label = $active_period ? date('F Y', mktime(0,0,0,$active_period['month'],1,$active_period['year'])) : '';
    $save_msg  = "Saved $saved entries for $period_label.";
    $save_type = 'success';

    $existing = [];
    $ex_res2 = mysqli_query($conn, "
        SELECT employee_id, component_label, amount, notes
        FROM incentive_entries
        WHERE incentive_type_id=$incentive_type_id AND payroll_period_id=$sel_period_id
    ");
    if ($ex_res2) { while ($ex = mysqli_fetch_assoc($ex_res2)) $existing[$ex['employee_id']][$ex['component_label']] = $ex; }
}

$month_names = ['','January','February','March','April','May','June',
                'July','August','September','October','November','December'];

// Build per-employee placement rate tiers for MR
$placement_tiers_js = [];
if ($page_cat_code === 'MR') {
    foreach ($employees as $emp) {
        $eid = $emp['id'];
        $cfg = $emp_salary_cfg[$eid] ?? [];
        $tiers = [];
        foreach ([70 => 'placement_compliance_70', 80 => 'placement_compliance_80', 90 => 'placement_compliance_90'] as $pct => $field) {
            if (!empty($cfg[$field]) && floatval($cfg[$field]) > 0) {
                $tiers[] = ['threshold' => $pct, 'amount' => floatval($cfg[$field])];
            }
        }
        $placement_tiers_js[$eid] = $tiers;
    }
}

// Build per-employee ST tier data (rsqm, punctuality)
$st_tier_groups_js = [];
if ($page_cat_code === 'ST' && !empty($st_tier_defs)) {
    foreach ($employees as $emp) {
        $eid = $emp['id'];
        $cfg = $emp_salary_cfg[$eid] ?? [];
        $st_tier_groups_js[$eid] = [];
        foreach ($st_tier_defs as $ikey => $tiers) {
            $built = [];
            foreach ($tiers as $t) {
                $amt = floatval($cfg[$t['cfg_field']] ?? 0);
                if ($amt > 0) {
                    $built[] = ['threshold' => $t['threshold'], 'amount' => $amt];
                }
            }
            $st_tier_groups_js[$eid][$ikey] = $built;
        }
    }
}

// Build per-employee CC tier data
$cc_tier_groups_js = [];
if ($page_is_cc && !empty($cc_tier_defs)) {
    foreach ($employees as $emp) {
        $eid = $emp['id'];
        $cfg = $emp_salary_cfg[$eid] ?? [];
        $cc_tier_groups_js[$eid] = [];
        foreach ($cc_tier_defs as $ikey => $tiers) {
            $built = [];
            foreach ($tiers as $t) {
                $amt = floatval($cfg[$t['cfg_field']] ?? 0);
                if ($amt > 0) {
                    $built[] = ['threshold' => $t['threshold'], 'amount' => $amt];
                }
            }
            $cc_tier_groups_js[$eid][$ikey] = $built;
        }
    }
}

// Build per-employee ACC tier data
$acc_tier_groups_js = [];
if ($page_is_acc && !empty($acc_tier_defs)) {
    foreach ($employees as $emp) {
        $eid = $emp['id'];
        $cfg = $emp_salary_cfg[$eid] ?? [];
        $acc_tier_groups_js[$eid] = [];
        foreach ($acc_tier_defs as $ikey => $tiers) {
            $built = [];
            foreach ($tiers as $t) {
                $amt = floatval($cfg[$t['cfg_field']] ?? 0);
                if ($amt > 0) {
                    $built[] = ['threshold' => $t['threshold'], 'amount' => $amt];
                }
            }
            $acc_tier_groups_js[$eid][$ikey] = $built;
        }
    }
}

include 'header.php';
?>

<div class="ie-page-header">
    <div class="ie-header-left">
        <a href="incentive_types.php" class="ie-back-btn" title="Back">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div>
            <div class="ie-breadcrumb">
                <span>Incentive Types</span>
                <i class="fa-solid fa-chevron-right"></i>
                <span>Data Entry</span>
            </div>
            <h1 class="ie-page-title"><?php echo htmlspecialchars($inc_type['type_name']); ?></h1>
            <?php if ($inc_type['description']): ?>
            <p class="ie-page-sub"><?php echo htmlspecialchars($inc_type['description']); ?></p>
            <?php endif; ?>
            <?php if ($is_discretionary): ?>
            <span class="ie-disc-badge-header">
                <i class="fa-solid fa-hand-holding-heart"></i> Discretionary Support Enabled
            </span>
            <?php endif; ?>
            <?php if ($page_cat_code !== 'SR'): ?>
            <span class="ie-cat-badge-header ie-cat-<?php echo strtolower($page_cat_code); ?>">
                <i class="fa-solid fa-tag"></i> <?php echo htmlspecialchars($page_cat_name ?: $page_cat_code); ?> Schema
            </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="ie-header-right">
        <form method="GET" id="periodForm">
            <input type="hidden" name="type_id" value="<?php echo $incentive_type_id; ?>">
            <div class="ie-period-selector">
                <i class="fa-solid fa-calendar-check ie-period-icon"></i>
                <div class="ie-period-inner">
                    <span class="ie-period-label">Payroll Period</span>
                    <?php if (!empty($all_periods)): ?>
                    <select name="period_id" class="ie-period-select" onchange="this.form.submit()">
                        <?php foreach ($all_periods as $pp): ?>
                        <option value="<?php echo $pp['id']; ?>" <?php echo $sel_period_id==$pp['id']?'selected':''; ?>>
                            <?php echo $month_names[$pp['month']].' '.$pp['year'];
                                  echo ($pp['status']!=='Open')?' ('.htmlspecialchars($pp['status']).')':''; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <span class="ie-no-period">No periods found</span>
                    <?php endif; ?>
                </div>
                <?php if ($active_period): ?>
                <span class="ie-period-badge <?php echo strtolower($active_period['status']??'open'); ?>">
                    <?php if (($active_period['status']??'Open')==='Open'): ?>
                    <i class="fa-solid fa-lock-open"></i> Open
                    <?php else: ?>
                    <i class="fa-solid fa-lock"></i> <?php echo htmlspecialchars($active_period['status']); ?>
                    <?php endif; ?>
                </span>
                <?php endif; ?>
            </div>
        </form>
        <div class="ie-stats-strip">
            <div class="ie-stat">
                <span class="ie-stat-num"><?php echo count($employees); ?></span>
                <span class="ie-stat-lbl">Employees</span>
            </div>
            <div class="ie-stat">
                <span class="ie-stat-num" id="filledCount">0</span>
                <span class="ie-stat-lbl">Filled</span>
            </div>
        </div>
    </div>
</div>

<?php if ($save_msg): ?>
<div class="ie-alert ie-alert-<?php echo $save_type; ?>">
    <i class="fa-solid fa-circle-check"></i>
    <?php echo htmlspecialchars($save_msg); ?>
    <button onclick="this.parentElement.remove()" class="ie-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>

<?php if (empty($all_periods)): ?>
<div class="ie-alert ie-alert-warning">
    <i class="fa-solid fa-triangle-exclamation"></i>
    No payroll periods found. <a href="payroll_months.php" class="ie-link">Manage Periods →</a>
</div>
<?php endif; ?>

<div class="ie-hint-bar">
    <div class="ie-hint-items">
        <span class="ie-hint"><kbd>Enter</kbd> / <kbd>↓</kbd> Next row</span>
        <span class="ie-hint"><kbd>↑</kbd> Previous row</span>
        <span class="ie-hint"><kbd>Tab</kbd> Next field</span>
        <span class="ie-hint"><kbd>Ctrl+S</kbd> Save</span>
        <?php if ($schema_has_ssv): ?>
        <span class="ie-hint" style="color:#7c3aed;"><i class="fa-solid fa-percent"></i> Achievement % → SSV auto-fill (editable)</span>
        <?php endif; ?>
        <?php if ($schema_has_other_incentive): ?>
        <span class="ie-hint" style="color:#be185d;"><i class="fa-solid fa-gift"></i> Other Incentive: enter amount manually</span>
        <?php endif; ?>
        <?php if ($schema_has_ir_hdrs): ?>
        <span class="ie-hint" style="color:#7c3aed;"><i class="fa-solid fa-percent"></i> Each incentive has its own Achievement % → auto-calculates amount</span>
        <span class="ie-hint" style="color:#059669;"><i class="fa-solid fa-lock"></i> Amount clamped to max cfg value</span>
        <?php endif; ?>
        <?php if ($page_cat_code === 'OFF'): ?>
        <span class="ie-hint" style="color:#7c3aed;">
            <i class="fa-solid fa-percent"></i> Daily/Weekly/Monthly: Ach% &ge;90 → auto-fills cfg amount
        </span>
        <?php endif; ?>
        <?php if ($page_cat_code === 'ST'): ?>
        <span class="ie-hint" style="color:#3b82f6;">
            <i class="fa-solid fa-percent"></i> RSQM: &ge;60%→tier1, &ge;70%→tier2 | Punct: &ge;75%→tier1, &ge;85%→tier2
        </span>
        <?php endif; ?>
        <?php if ($page_is_cc): ?>
        <span class="ie-hint" style="color:#0891b2;">
            <i class="fa-solid fa-percent"></i> CCFOT/Credit/SSV/Att: Ach% auto-picks highest tier | Locus: 100% → full amount
        </span>
        <?php endif; ?>
        <?php if ($page_is_acc): ?>
        <span class="ie-hint" style="color:#1d4ed8;">
            <i class="fa-solid fa-percent"></i> CCFOT: &ge;90%→95% tiers | Punct: &ge;70%/80% | Att: &ge;85%/90% — auto-picks highest tier
        </span>
        <?php endif; ?>
        <?php if (!$schema_hide_attrate): ?>
        <span class="ie-hint" style="color:#0891b2;">
            <i class="fa-solid fa-chart-line"></i> Att. Rate = Att &divide; Norm &times; 100%
            <?php if (!$schema_reimb_direct): ?>
            | Reimb = Rate% &times; Cfg (Mobile is fixed)
            <?php else: ?>
            | Reimb = Cfg value (fixed)
            <?php endif; ?>
        </span>
        <?php else: ?>
        <span class="ie-hint" style="color:#0891b2;">
            <i class="fa-solid fa-receipt"></i> Reimb = Att.Rate% &times; Cfg (auto-computed, Mobile is fixed)
        </span>
        <?php endif; ?>
    </div>
    <?php if ($period_month_label): ?>
        <span class="ie-hint" style="color:#92400e;"><i class="fa-solid fa-user-clock"></i> Resigned/Terminated employees show through <strong><?php echo htmlspecialchars($period_month_label); ?></strong> (their exit month) only</span>
    <?php endif; ?>
    <div class="ie-hint-filter">
        <input type="text" id="empFilterInput" class="ie-filter-input"
               placeholder="Filter employees…"
               oninput="filterEmployees(this.value)">
        <button type="button" class="ie-filter-clear" id="filterClear" onclick="clearFilter()" style="display:none;">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
</div>

<?php if (!empty($employees) && !empty($all_periods)): ?>

<?php
$js_emp_data = [];
foreach ($employees as $emp) {
    $eid = $emp['id'];
    $cfg = $emp_salary_cfg[$eid] ?? [];
    $ssv = $emp_ssv_lookup[$eid] ?? [];
    $ir  = $emp_incent_rates[$eid] ?? [];
    $att = $emp_att_rate_map[$eid] ?? ['att_count'=>0,'norm_days'=>0,'holiday_count'=>0,'att_rate'=>0.0];
    $js_emp_data[$eid] = [
        'salaryCfg'   => $cfg,
        'ssvRates'    => $ssv,
        'incentRates' => $ir,
        'attRate'     => (float)$att['att_rate'],
        'attCount'    => (int)$att['att_count'],
        'normDays'    => (int)$att['norm_days'],
    ];
}
?>

<script>
const EMP_DATA            = <?php echo json_encode($js_emp_data); ?>;
const PAGE_CAT_CODE       = <?php echo json_encode($page_cat_code); ?>;
const SCHEMA_HAS_SSV      = <?php echo $schema_has_ssv ? 'true' : 'false'; ?>;
const SCHEMA_HAS_IR_HDR   = <?php echo $schema_has_ir_hdrs ? 'true' : 'false'; ?>;
const SCHEMA_HIDE_ATTRATE = <?php echo $schema_hide_attrate ? 'true' : 'false'; ?>;
const SCHEMA_REIMB_DIRECT = <?php echo $schema_reimb_direct ? 'true' : 'false'; ?>;
const SCHEMA_HAS_OTHER_INCENTIVE = <?php echo $schema_has_other_incentive ? 'true' : 'false'; ?>;
// Reimbursement fields that always stay fixed (not scaled by attendance rate)
const FIXED_REIMB_FIELDS = <?php echo json_encode($fixed_reimb_fields); ?>;

const PLACEMENT_TIERS = <?php echo json_encode($placement_tiers_js); ?>;
const PAGE_IS_MR      = <?php echo $page_cat_code === 'MR' ? 'true' : 'false'; ?>;
const PAGE_IS_OFF     = <?php echo $page_cat_code === 'OFF' ? 'true' : 'false'; ?>;
const PAGE_IS_ST      = <?php echo $page_cat_code === 'ST' ? 'true' : 'false'; ?>;
const PAGE_IS_CC      = <?php echo $page_is_cc ? 'true' : 'false'; ?>;
const PAGE_IS_ACC     = <?php echo $page_is_acc ? 'true' : 'false'; ?>;
const ST_TIER_GROUPS  = <?php echo json_encode($st_tier_groups_js); ?>;
const ST_TIER_KEYS    = <?php echo json_encode(array_keys($st_tier_defs)); ?>;
const CC_TIER_GROUPS  = <?php echo json_encode($cc_tier_groups_js); ?>;
const CC_TIER_KEYS    = <?php echo json_encode(array_keys($cc_tier_defs)); ?>;
const ACC_TIER_GROUPS = <?php echo json_encode($acc_tier_groups_js); ?>;
const ACC_TIER_KEYS   = <?php echo json_encode(array_keys($acc_tier_defs)); ?>;

const INCENT_DEFS = <?php echo json_encode(array_values(array_map(fn($g) => [
    'key'              => $g['key'],
    'label'            => $g['label'],
    'defaultThreshold' => $g['default_threshold'],
    'directAmount'     => false,
], $schema_incent_groups))); ?>;
</script>

<form method="POST" action="incentive_entry.php?type_id=<?php echo $incentive_type_id; ?>&period_id=<?php echo $sel_period_id; ?>" id="entryForm">
    <input type="hidden" name="save_entries" value="1">
    <input type="hidden" name="period_id"    value="<?php echo $sel_period_id; ?>">

    <div class="ie-table-wrap">
        <div class="ie-table-scroll">
            <table class="ie-table" id="ieTable">
                <thead>
                    <tr class="ie-thead-group">
                        <th class="ie-th ie-th-sticky ie-th-num" rowspan="2">#</th>
                        <th class="ie-th ie-th-sticky ie-th-emp" rowspan="2">
                            <div class="ie-th-inner"><i class="fa-solid fa-user"></i>
                                <?php
                                $emp_col_label = 'Employee';
                                if ($page_cat_code === 'MR')  $emp_col_label = 'MR Name';
                                elseif ($page_cat_code === 'CC')  $emp_col_label = 'CC Name';
                                elseif ($page_cat_code === 'ACC') $emp_col_label = 'ACC Name';
                                elseif ($page_cat_code === 'ST')  $emp_col_label = 'ST Name';
                                elseif ($page_cat_code === 'OFF') $emp_col_label = 'OFF Name';
                                echo $emp_col_label;
                                ?>
                            </div>
                        </th>

                        <?php if ($is_discretionary): ?>
                        <th class="ie-th ie-th-group ie-th-group-disc" rowspan="2">
                            <div class="ie-th-inner-disc">
                                <i class="fa-solid fa-hand-holding-heart"></i> Discretionary Amt
                            </div>
                        </th>
                        <?php endif; ?>

                        <?php if ($schema_has_ssv): ?>
                        <th class="ie-th ie-th-group ie-th-group-sec" colspan="2">
                            <div class="ie-th-inner-sec">
                                <i class="fa-solid fa-chart-line"></i> Secondary Sales
                            </div>
                        </th>
                        <?php endif; ?>

                        <?php if ($schema_has_other_incentive): ?>
                        <th class="ie-th ie-th-group ie-th-group-other" rowspan="2">
                            <div class="ie-th-inner-other">
                                <i class="fa-solid fa-gift"></i> Other Incentive Amt
                            </div>
                        </th>
                        <?php endif; ?>

                        <?php
                        // Build grouped header spans
                        $grouped_ihdrs = [];
                        $prev_color = null;
                        foreach ($schema_incent_groups as $grp) {
                            $grp_span = 2;
                            if ($grp['color'] === $prev_color) {
                                $grouped_ihdrs[count($grouped_ihdrs)-1]['span'] += $grp_span;
                            } else {
                                $glabel = $grp['label'];
                                // For CC/ACC collapsed groups, don't strip the label
                                if (!$page_is_cc && !$page_is_acc) {
                                    $glabel = preg_replace('/\s+\d+(\.\d+)?%$/', '', $glabel);
                                    $glabel = preg_replace('/\s+\d+(\.\d+)?$/', '', $glabel);
                                }
                                $grouped_ihdrs[] = ['label'=>$glabel,'color'=>$grp['color'],'span'=>$grp_span];
                                $prev_color = $grp['color'];
                            }
                        }
                        foreach ($grouped_ihdrs as $gh):
                            $pal = $incent_color_map[$gh['color']] ?? $incent_color_map['blue'];
                        ?>
                        <th class="ie-th ie-th-group" colspan="<?php echo $gh['span']; ?>"
                            style="background:<?php echo $pal['hdr_bg']; ?>;color:<?php echo $pal['hdr_text']; ?>;border-bottom:1px solid #3f3f46;border-right:2px solid <?php echo $pal['border']; ?>;padding:10px 8px;text-align:center;font-size:11px;">
                            <div style="display:flex;align-items:center;justify-content:center;gap:6px;">
                                <i class="fa-solid fa-coins"></i>
                                <?php echo htmlspecialchars($gh['label']); ?>
                                <?php if ($schema_has_ir_hdrs): ?>
                                <span class="ie-th-auto-badge"><i class="fa-solid fa-percent"></i> Ach% · Amt</span>
                                <?php endif; ?>
                            </div>
                        </th>
                        <?php endforeach; ?>

                        <?php if (!$schema_hide_attrate): ?>
                        <th class="ie-th ie-th-group ie-th-group-attrate" rowspan="2">
                            <div class="ie-th-inner-attrate">
                                <i class="fa-solid fa-percent"></i> Att. Rate
                                <span class="ie-th-auto-badge" style="background:rgba(8,145,178,.2);border-color:rgba(8,145,178,.4);color:#67e8f9;">Period</span>
                            </div>
                        </th>
                        <?php endif; ?>

                        <th class="ie-th ie-th-group ie-th-group-reimb" colspan="<?php echo count($schema_reimb); ?>">
                            <div class="ie-th-inner-reimb">
                                <i class="fa-solid fa-receipt"></i> Reimbursements
                                <?php if (!$schema_reimb_direct): ?>
                                <span class="ie-th-auto-badge" style="background:rgba(194,65,12,.2);border-color:rgba(194,65,12,.4);color:#fdba74;">Rate × Cfg</span>
                                <?php else: ?>
                                <span class="ie-th-auto-badge" style="background:rgba(100,116,139,.2);border-color:rgba(100,116,139,.4);color:#cbd5e1;">Cfg Fixed</span>
                                <?php endif; ?>
                            </div>
                        </th>

                        <th class="ie-th ie-th-actions" rowspan="2">
                            <div class="ie-th-inner">Hist.</div>
                        </th>
                    </tr>

                    <tr class="ie-thead-sub">
                        <?php if ($schema_has_ssv): ?>
                        <th class="ie-th ie-th-sub ie-th-sec-ach">
                            <div class="ie-th-inner"><i class="fa-solid fa-percent"></i> Achievement %</div>
                        </th>
                        <th class="ie-th ie-th-sub ie-th-sec-ssv">
                            <div class="ie-th-inner"><i class="fa-solid fa-circle-dollar-to-slot"></i> SSV Amount</div>
                        </th>
                        <?php endif; ?>

                        <?php foreach ($schema_incent_groups as $idef):
                            $pal = $incent_color_map[$idef['color']] ?? $incent_color_map['blue'];
                            // Build sub-header hint
                            $sub_hint = '';
                            if ($page_cat_code === 'ST' && isset($st_tier_defs[$idef['key']])) {
                                $hints = [];
                                foreach ($st_tier_defs[$idef['key']] as $t) {
                                    $hints[] = '≥'.$t['threshold'].'%';
                                }
                                $sub_hint = implode(' / ', $hints);
                            } elseif ($page_is_cc && isset($cc_tier_defs[$idef['key']])) {
                                $hints = [];
                                foreach ($cc_tier_defs[$idef['key']] as $t) {
                                    $hints[] = '≥'.$t['threshold'].'%';
                                }
                                $sub_hint = implode(' / ', $hints);
                            } elseif ($page_is_acc && isset($acc_tier_defs[$idef['key']])) {
                                $hints = [];
                                foreach ($acc_tier_defs[$idef['key']] as $t) {
                                    $hints[] = '≥'.$t['threshold'].'%';
                                }
                                $sub_hint = implode(' / ', $hints);
                            }
                        ?>
                        <th class="ie-th ie-th-sub ie-th-iach" style="background:<?php echo $pal['hdr_bg']; ?>;color:<?php echo $pal['hdr_text']; ?>;border-right:1px solid #2d2d30;">
                            <div class="ie-th-inner" style="justify-content:center;">
                                <?php echo htmlspecialchars($idef['label']); ?>
                                <?php if ($sub_hint): ?>
                                <span style="font-size:9px;opacity:.7;"><?php echo $sub_hint; ?></span>
                                <?php elseif ($idef['default_threshold'] > 0): ?>
                                <span style="font-size:9px;opacity:.7;">&ge;<?php echo $idef['default_threshold']; ?>%</span>
                                <?php endif; ?>
                            </div>
                        </th>
                        <th class="ie-th ie-th-sub ie-th-iamt" style="background:<?php echo $pal['hdr_bg']; ?>;color:<?php echo $pal['hdr_text']; ?>;border-right:2px solid <?php echo $pal['border']; ?>;">
                            <div class="ie-th-inner" style="justify-content:center;"><i class="fa-solid fa-sack-dollar"></i> Earned</div>
                        </th>
                        <?php endforeach; ?>

                        <?php foreach ($schema_reimb as $ri => $rc): ?>
                        <th class="ie-th ie-th-sub ie-th-reimb">
                            <div class="ie-th-inner">
                                <?php
                                $rimeta = ['meal_reimbursement'=>'fa-utensils','traveling_reimbursement'=>'fa-car','mobile_reimbursement'=>'fa-mobile'];
                                $riicon = $rimeta[$rc[0]] ?? 'fa-receipt';
                                ?>
                                <i class="fa-solid <?php echo $riicon; ?>"></i> <?php echo htmlspecialchars($rc[1]); ?>
                                <?php if (in_array($rc[0], $fixed_reimb_fields, true)): ?>
                                <span style="font-size:8px;opacity:.7;">(Fixed)</span>
                                <?php endif; ?>
                            </div>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>

                <tbody id="ieTableBody">
                <?php
                $row_num = 0;
                foreach ($employees as $emp):
                    $row_num++;
                    $eid     = $emp['id'];
                    $emp_cfg = $emp_salary_cfg[$eid] ?? [];
                    $emp_ir  = $emp_incent_rates[$eid] ?? [];
                    $emp_ssv = $emp_ssv_lookup[$eid]   ?? [];

                    $emp_att       = $emp_att_rate_map[$eid] ?? ['att_count'=>0,'norm_days'=>0,'holiday_count'=>0,'att_rate'=>0.0];
                    $att_rate_val  = (float)$emp_att['att_rate'];
                    $att_count_val = (int)$emp_att['att_count'];
                    $norm_days_val = (int)$emp_att['norm_days'];

                    if ($att_rate_val <= 0)        $att_rate_cls = 'rate-zero';
                    elseif ($att_rate_val >= 90)   $att_rate_cls = 'rate-high';
                    elseif ($att_rate_val >= 70)   $att_rate_cls = 'rate-mid';
                    else                           $att_rate_cls = 'rate-low';

                    $fv = fn($v) => $v !== null && $v !== '' ? number_format((float)$v, 2, '.', '') : '';

                    $cfg_disc   = floatval($emp_cfg['discretionary_support'] ?? 0);
                    $saved_disc = '';
                    if (!empty($existing[$eid]['__disc_amount'])) {
                        $saved_disc = $existing[$eid]['__disc_amount']['amount'];
                    } elseif ($cfg_disc > 0) {
                        $saved_disc = $cfg_disc;
                    }

                    $saved_sec_ach = $existing[$eid]['__sec_achievement']['amount'] ?? '';
                    $saved_ssv_amt = $existing[$eid]['__ssv_amount']['amount'] ?? '';
                    $saved_other_incentive = $existing[$eid]['__other_incentive']['amount'] ?? '';

                    $saved_iach   = [];
                    $saved_incent = [];
                    foreach ($schema_incent_groups as $idef) {
                        $ikey = $idef['key'];
                        $saved_iach[$ikey]   = $existing[$eid]['__iach__'.$ikey]['amount']   ?? '';
                        $saved_incent[$ikey] = $existing[$eid]['__incent__'.$ikey]['amount'] ?? '';
                    }

                    $reimb_saved     = [];
                    $reimb_cfg       = [];
                    $reimb_calc      = [];
                    $reimb_has_saved = [];
                    foreach ($schema_reimb as $rc) {
                        $rfield = $rc[0];
                        $rcfg   = isset($emp_cfg[$rfield]) && $emp_cfg[$rfield] !== '' ? (float)$emp_cfg[$rfield] : null;
                        // Fields in $fixed_reimb_fields (e.g. mobile) are ALWAYS
                        // fixed to the config value and never scaled by attendance
                        // rate, regardless of the schema's reimb_direct setting.
                        $r_is_fixed = in_array($rfield, $fixed_reimb_fields, true);
                        if ($schema_reimb_direct || $r_is_fixed) {
                            $rcalc = $rcfg;
                        } else {
                            $rcalc = ($rcfg !== null && $norm_days_val > 0) ? round(($att_rate_val / 100) * $rcfg, 2) : null;
                        }
                        // Whether an entry was actually saved to the DB for this
                        // employee/field/period (regardless of whether it's 0).
                        // This is used on the client to decide whether the
                        // auto-fill-on-load logic is allowed to touch the value.
                        $r_has_saved = isset($existing[$eid]['__reimb__'.$rfield]['amount']);
                        $rsaved = $r_has_saved
                            ? $existing[$eid]['__reimb__'.$rfield]['amount']
                            : ($rcalc !== null ? $rcalc : ($rcfg ?? ''));
                        $reimb_saved[$rfield]     = $rsaved;
                        $reimb_cfg[$rfield]       = $rcfg;
                        $reimb_calc[$rfield]      = $rcalc;
                        $reimb_has_saved[$rfield] = $r_has_saved;
                    }

                    $has_data = ($saved_disc !== '' && floatval($saved_disc) > 0)
                        || ($saved_sec_ach !== '' && floatval($saved_sec_ach) > 0)
                        || ($saved_other_incentive !== '' && floatval($saved_other_incentive) > 0)
                        || !empty(array_filter($saved_incent, fn($v) => $v !== '' && floatval($v) > 0));

                    $ssv_rates_attr = htmlspecialchars(json_encode($emp_ssv_lookup[$eid] ?? []), ENT_QUOTES);

                    $col_idx = 0;

                    // Exit info for badge (employee still shown this period per
                    // the exit-aware filtering above, but flag it visually).
                    $emp_exit_status = $emp['status'] ?? '';
                    $emp_is_exited   = in_array($emp_exit_status, ['Resigned','Terminated'], true);
                ?>
                <tr class="ie-tr <?php echo $has_data ? 'ie-tr-filled' : ''; ?> <?php echo $emp_is_exited ? 'ie-tr-exited' : ''; ?>"
                    id="empRow_<?php echo $eid; ?>"
                    data-emp-id="<?php echo $eid; ?>"
                    data-att-rate="<?php echo $att_rate_val; ?>"
                    data-norm-days="<?php echo $norm_days_val; ?>"
                    data-search="<?php echo strtolower(htmlspecialchars(
                        ($emp['employee_id']??'').' '.
                        ($emp['employee_full_name']??'').' '.
                        ($emp['name_with_initials']??'').' '.
                        ($emp['designation_name']??'')
                    )); ?>">

                    <td class="ie-td ie-td-num"><?php echo $row_num; ?></td>

                    <td class="ie-td ie-td-emp">
                        <div class="ie-emp-cell">
                            <div class="ie-emp-avatar"><?php echo strtoupper(substr($emp['employee_full_name']??'E',0,1)); ?></div>
                            <div class="ie-emp-info">
                                <div class="ie-emp-name"><?php echo htmlspecialchars($emp['employee_full_name']); ?></div>
                                <div class="ie-emp-meta">
                                    <span class="ie-emp-id"><?php echo htmlspecialchars($emp['employee_id']); ?></span>
                                    <?php if (!empty($emp['designation_name'])): ?>
                                    <span class="ie-emp-desig"><?php echo htmlspecialchars($emp['designation_name']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($emp['telephone_mobile'])): ?>
                                    <span class="ie-emp-mobile"><i class="fa-solid fa-mobile-screen-button"></i> <?php echo htmlspecialchars($emp['telephone_mobile']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($emp_is_exited): ?>
                                    <span class="ie-emp-exit-badge ie-emp-exit-<?php echo strtolower($emp_exit_status); ?>"
                                          title="This employee has left the company. Their exit date falls in or before the <?php echo htmlspecialchars($period_month_label); ?> base month, so they still show here — they will disappear from later payroll months.">
                                        <i class="fa-solid <?php echo $emp_exit_status==='Terminated' ? 'fa-ban' : 'fa-right-from-bracket'; ?>"></i>
                                        <?php echo htmlspecialchars($emp_exit_status); ?><?php echo !empty($emp['exit_date']) ? ' · '.date('d M Y', strtotime($emp['exit_date'])) : ''; ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>

                    <?php if ($is_discretionary): ?>
                    <td class="ie-td ie-td-disc-amt">
                        <div class="ie-disc-wrap">
                            <span class="ie-disc-pfx">Rs.</span>
                            <input type="number" id="disc_<?php echo $eid; ?>"
                                name="entry[<?php echo $eid; ?>][__disc][disc_amount]"
                                class="ie-disc-input ie-focusable"
                                value="<?php echo $saved_disc !== '' ? $fv($saved_disc) : ''; ?>"
                                placeholder="0.00" min="0" step="0.01"
                                data-row="<?php echo $eid; ?>" data-col="<?php echo $col_idx; ?>"
                                oninput="updateFilledCount();" onkeydown="handleKeyNav(event,this)"
                                onfocus="this.select();highlightRow(<?php echo $eid; ?>)">
                        </div>
                        <?php if ($cfg_disc > 0): ?>
                        <div class="ie-cfg-badge ie-cfg-badge-disc">
                            <i class="fa-solid fa-wand-magic-sparkles"></i> Cfg: Rs.<?php echo number_format($cfg_disc,2); ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <?php $col_idx++; endif; ?>

                    <?php if ($schema_has_ssv): ?>
                    <td class="ie-td ie-td-sec-ach">
                        <div class="ie-sec-ach-wrap">
                            <input type="number"
                                id="sec_ach_<?php echo $eid; ?>"
                                name="entry[<?php echo $eid; ?>][__sec_ach][pct]"
                                class="ie-sec-ach-input ie-focusable"
                                value="<?php echo $saved_sec_ach !== '' ? $fv($saved_sec_ach) : ''; ?>"
                                placeholder="0.00" min="0" max="200" step="0.01"
                                data-row="<?php echo $eid; ?>" data-col="<?php echo $col_idx; ?>"
                                data-empid="<?php echo $eid; ?>"
                                data-ssv-rates="<?php echo $ssv_rates_attr; ?>"
                                oninput="onSecAchievementInput(this)"
                                onkeydown="handleKeyNav(event,this)"
                                onfocus="this.select();highlightRow(<?php echo $eid; ?>)">
                        </div>
                        <div class="ie-sec-ach-hint">% → SSV auto-fill</div>
                    </td>
                    <?php $col_idx++; ?>

                    <td class="ie-td ie-td-ssv-result">
                        <div class="ie-ssv-result-wrap">
                            <span class="ie-ssv-result-pfx">Rs.</span>
                            <input type="number"
                                id="ssv_result_<?php echo $eid; ?>"
                                name="entry[<?php echo $eid; ?>][__ssv_amt][amount]"
                                class="ie-ssv-result-input ie-focusable"
                                value="<?php echo $saved_ssv_amt !== '' ? $fv($saved_ssv_amt) : ''; ?>"
                                placeholder="0.00" min="0" step="0.01"
                                data-row="<?php echo $eid; ?>" data-col="<?php echo $col_idx; ?>"
                                oninput="onSSVAmountManualInput(this)"
                                onkeydown="handleKeyNav(event,this)"
                                onfocus="this.select();highlightRow(<?php echo $eid; ?>)">
                        </div>
                        <div class="ie-ssv-matched-label" id="ssv_lbl_<?php echo $eid; ?>"></div>
                    </td>
                    <?php $col_idx++; endif; ?>

                    <?php if ($schema_has_other_incentive): ?>
                    <td class="ie-td ie-td-other-incent">
                        <div class="ie-other-wrap">
                            <span class="ie-other-pfx">Rs.</span>
                            <input type="number"
                                id="other_incent_<?php echo $eid; ?>"
                                name="entry[<?php echo $eid; ?>][__other_incentive][amount]"
                                class="ie-other-input ie-focusable"
                                value="<?php echo $saved_other_incentive !== '' ? $fv($saved_other_incentive) : ''; ?>"
                                placeholder="0.00" min="0" step="0.01"
                                data-row="<?php echo $eid; ?>" data-col="<?php echo $col_idx; ?>"
                                oninput="updateFilledCount();updateGrandTotals();" onkeydown="handleKeyNav(event,this)"
                                onfocus="this.select();highlightRow(<?php echo $eid; ?>)">
                        </div>
                        <div class="ie-other-hint">Manual entry</div>
                    </td>
                    <?php $col_idx++; endif; ?>

                    <?php foreach ($schema_incent_groups as $idef):
                        $ikey        = $idef['key'];
                        $cfg_field   = $idef['cfg_field'];
                        $cfg_iamt    = floatval($emp_cfg[$cfg_field] ?? 0);
                        $thr_val     = $idef['default_threshold'];

                        // ── CC tier: compute display max from all tiers for this key ──
                        $cc_tier_max = 0;
                        $cc_tier_hint_str = '';
                        if ($page_is_cc && isset($cc_tier_defs[$ikey])) {
                            foreach ($cc_tier_defs[$ikey] as $t) {
                                $ta = floatval($emp_cfg[$t['cfg_field']] ?? 0);
                                if ($ta > $cc_tier_max) $cc_tier_max = $ta;
                            }
                            $th = [];
                            foreach ($cc_tier_defs[$ikey] as $t) {
                                $ta = floatval($emp_cfg[$t['cfg_field']] ?? 0);
                                if ($ta > 0) $th[] = '≥'.$t['threshold'].'%→Rs.'.number_format($ta,2);
                            }
                            $cc_tier_hint_str = implode(' | ', $th);
                            if ($cc_tier_max > 0) $cfg_iamt = $cc_tier_max;
                        }

                        // ── ACC tier: compute display max from all tiers for this key ──
                        $acc_tier_max = 0;
                        $acc_tier_hint_str = '';
                        if ($page_is_acc && isset($acc_tier_defs[$ikey])) {
                            foreach ($acc_tier_defs[$ikey] as $t) {
                                $ta = floatval($emp_cfg[$t['cfg_field']] ?? 0);
                                if ($ta > $acc_tier_max) $acc_tier_max = $ta;
                            }
                            $th = [];
                            foreach ($acc_tier_defs[$ikey] as $t) {
                                $ta = floatval($emp_cfg[$t['cfg_field']] ?? 0);
                                if ($ta > 0) $th[] = '≥'.$t['threshold'].'%→Rs.'.number_format($ta,2);
                            }
                            $acc_tier_hint_str = implode(' | ', $th);
                            if ($acc_tier_max > 0) $cfg_iamt = $acc_tier_max;
                        }

                        // For ST tier groups, compute max possible amount for display
                        $st_tier_max = 0;
                        if ($page_cat_code === 'ST' && isset($st_tier_defs[$ikey])) {
                            foreach ($st_tier_defs[$ikey] as $t) {
                                $ta = floatval($emp_cfg[$t['cfg_field']] ?? 0);
                                if ($ta > $st_tier_max) $st_tier_max = $ta;
                            }
                        }

                        if ($schema_has_ir_hdrs) {
                            $ir_saved = $emp_ir[$ikey] ?? null;
                            if ($ir_saved !== null) {
                                $thr_val = floatval(str_replace('%', '', $ir_saved));
                            }
                        }

                        $s_ach = $saved_iach[$ikey]   ?? '';
                        $s_amt = $saved_incent[$ikey] ?? '';

                        // Determine if threshold met
                        $init_met = false;
                        if ($page_is_acc && isset($acc_tier_defs[$ikey])) {
                            // met if ach >= lowest tier threshold and at least one tier has an amount
                            $lowest_thr = PHP_INT_MAX;
                            foreach ($acc_tier_defs[$ikey] as $t) {
                                if ($t['threshold'] < $lowest_thr) $lowest_thr = $t['threshold'];
                            }
                            if ($lowest_thr === PHP_INT_MAX) $lowest_thr = 0;
                            $init_met = ($s_ach !== '' && floatval($s_ach) > 0 && floatval($s_ach) >= $lowest_thr && $acc_tier_max > 0);
                        } elseif ($page_is_cc && isset($cc_tier_defs[$ikey])) {
                            $lowest_thr = PHP_INT_MAX;
                            foreach ($cc_tier_defs[$ikey] as $t) {
                                if ($t['threshold'] < $lowest_thr) $lowest_thr = $t['threshold'];
                            }
                            if ($lowest_thr === PHP_INT_MAX) $lowest_thr = 0;
                            $init_met = ($s_ach !== '' && floatval($s_ach) > 0 && floatval($s_ach) >= $lowest_thr && $cc_tier_max > 0);
                        } elseif ($page_cat_code === 'ST' && isset($st_tier_defs[$ikey])) {
                            $init_met = ($s_ach !== '' && floatval($s_ach) >= $thr_val && $st_tier_max > 0);
                        } else {
                            $init_met = ($s_ach !== '' && floatval($s_ach) >= $thr_val && $cfg_iamt > 0);
                        }

                        // Display max
                        $display_max = 0;
                        if ($page_is_acc && isset($acc_tier_defs[$ikey])) {
                            $display_max = $acc_tier_max;
                        } elseif ($page_is_cc && isset($cc_tier_defs[$ikey])) {
                            $display_max = $cc_tier_max;
                        } elseif ($page_cat_code === 'ST' && isset($st_tier_defs[$ikey])) {
                            $display_max = $st_tier_max;
                        } else {
                            $display_max = $cfg_iamt;
                        }

                        $pal = $incent_color_map[$idef['color']] ?? $incent_color_map['blue'];
                    ?>

                    <td class="ie-td ie-td-iach" id="iach_cell_<?php echo $eid; ?>_<?php echo $ikey; ?>"
                        style="background:<?php echo $pal['hdr_bg'].'22'; ?>;">
                        <div class="ie-iach-wrap <?php echo ($display_max <= 0) ? 'iach-disabled' : ''; ?>"
                             style="border-color:<?php echo $pal['border']; ?>66;">
                            <input type="number"
                                id="iach_<?php echo $eid; ?>_<?php echo $ikey; ?>"
                                name="entry[<?php echo $eid; ?>][__iach][<?php echo htmlspecialchars($ikey); ?>]"
                                class="ie-iach-input ie-focusable <?php echo $s_ach !== '' && floatval($s_ach) > 0 ? ($init_met ? 'iach-met' : 'iach-miss') : ''; ?>"
                                value="<?php echo $s_ach !== '' ? $fv($s_ach) : ''; ?>"
                                placeholder="0.00" min="0" max="200" step="0.01"
                                data-row="<?php echo $eid; ?>" data-col="<?php echo $col_idx; ?>"
                                data-empid="<?php echo $eid; ?>"
                                data-ikey="<?php echo htmlspecialchars($ikey); ?>"
                                data-cfg="<?php echo htmlspecialchars($display_max); ?>"
                                data-threshold="<?php echo htmlspecialchars($thr_val); ?>"
                                <?php echo ($display_max <= 0) ? 'disabled' : ''; ?>
                                oninput="onIncentAchInput(this)"
                                onkeydown="handleKeyNav(event,this)"
                                onfocus="this.select();highlightRow(<?php echo $eid; ?>)">
                            <span class="ie-iach-sfx" style="background:<?php echo $pal['hdr_bg']; ?>;color:<?php echo $pal['hdr_text']; ?>;">%</span>
                        </div>
                        <?php if ($display_max > 0):
                            $tier_hint_str = '';
                            if ($page_cat_code === 'ST' && isset($st_tier_defs[$ikey])) {
                                $th = [];
                                foreach ($st_tier_defs[$ikey] as $t) {
                                    $ta = floatval($emp_cfg[$t['cfg_field']] ?? 0);
                                    if ($ta > 0) $th[] = '≥'.$t['threshold'].'%→Rs.'.number_format($ta,2);
                                }
                                $tier_hint_str = implode(' | ', $th);
                            }
                        ?>
                        <div class="ie-iach-meta">
                            <span class="ie-iach-thr" style="color:<?php echo $pal['hdr_text']; ?>;opacity:.8;">
                                <?php if ($page_is_acc && $acc_tier_hint_str): ?>
                                    <span class="ie-acc-tier-hint"><?php echo $acc_tier_hint_str; ?></span>
                                <?php elseif ($page_is_cc && $cc_tier_hint_str): ?>
                                    <span class="ie-cc-tier-hint"><?php echo $cc_tier_hint_str; ?></span>
                                <?php elseif ($tier_hint_str): ?>
                                    <?php echo $tier_hint_str; ?>
                                <?php else: ?>
                                    <?php echo $thr_val > 0 ? '≥'.$thr_val.'% · ' : ''; ?>Rs.<?php echo number_format($cfg_iamt,2); ?>
                                <?php endif; ?>
                            </span>
                            <span class="ie-iach-status <?php echo $init_met ? 'status-met' : ($s_ach !== '' && floatval($s_ach) > 0 ? 'status-miss' : 'status-idle'); ?>"
                                 id="iach_status_<?php echo $eid; ?>_<?php echo $ikey; ?>">
                                <?php if ($init_met): ?>
                                    <i class="fa-solid fa-circle-check"></i>
                                <?php elseif ($s_ach !== '' && floatval($s_ach) > 0): ?>
                                    <i class="fa-solid fa-circle-xmark"></i>
                                <?php else: ?>
                                    <i class="fa-solid fa-circle-minus"></i>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </td>
                    <?php $col_idx++; ?>

                    <td class="ie-td ie-td-iamt" id="iamt_cell_<?php echo $eid; ?>_<?php echo $ikey; ?>"
                        style="background:<?php echo $pal['hdr_bg'].'22'; ?>;border-right:2px solid <?php echo $pal['border']; ?>44;">
                        <div class="ie-iamt-wrap <?php echo $init_met ? 'iamt-active' : 'iamt-locked'; ?>"
                             id="iamt_wrap_<?php echo $eid; ?>_<?php echo $ikey; ?>"
                             style="border-color:<?php echo $init_met ? $pal['border'] : '#d1d5db'; ?>;">
                            <span class="ie-iamt-pfx" style="background:<?php echo $pal['hdr_bg']; ?>;color:<?php echo $pal['hdr_text']; ?>;border-right-color:<?php echo $pal['border']; ?>44;">Rs.</span>
                            <input type="number"
                                id="iamt_<?php echo $eid; ?>_<?php echo $ikey; ?>"
                                name="entry[<?php echo $eid; ?>][__incent_amts][<?php echo htmlspecialchars($ikey); ?>]"
                                class="ie-iamt-input ie-focusable <?php echo $s_amt !== '' && floatval($s_amt) > 0 ? 'iamt-earned' : ''; ?>"
                                value="<?php echo $s_amt !== '' ? $fv($s_amt) : ''; ?>"
                                placeholder="—" min="0"
                                max="<?php echo $display_max > 0 ? htmlspecialchars($display_max) : ''; ?>"
                                step="0.01"
                                data-row="<?php echo $eid; ?>" data-col="<?php echo $col_idx; ?>"
                                data-empid="<?php echo $eid; ?>"
                                data-ikey="<?php echo htmlspecialchars($ikey); ?>"
                                data-cfg="<?php echo htmlspecialchars($display_max); ?>"
                                data-direct="0"
                                <?php echo !$init_met ? 'readonly' : ''; ?>
                                oninput="onIncentAmtInput(this)"
                                onkeydown="handleKeyNav(event,this)"
                                onfocus="this.select();highlightRow(<?php echo $eid; ?>)">
                        </div>
                        <?php if ($display_max > 0): ?>
                        <div class="ie-iamt-hint <?php echo $init_met ? 'hint-met' : 'hint-locked'; ?>"
                             id="iamt_hint_<?php echo $eid; ?>_<?php echo $ikey; ?>">
                            <?php if ($init_met): ?>
                                <i class="fa-solid fa-circle-check"></i> max Rs.<?php echo number_format($display_max,2); ?>
                            <?php else: ?>
                                <i class="fa-solid fa-lock"></i> max Rs.<?php echo number_format($display_max,2); ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <?php $col_idx++; endforeach; ?>

                    <?php if (!$schema_hide_attrate): ?>
                    <td class="ie-td ie-td-attrate">
                        <div class="ie-attrate-pill-wrap">
                            <span class="ie-attrate-pill <?php echo $att_rate_cls; ?>"
                                  title="<?php echo $att_count_val; ?> days attended ÷ <?php echo $norm_days_val; ?> norm days × 100">
                                <?php if ($att_rate_val <= 0): ?>
                                    <i class="fa-solid fa-minus"></i> 0.00%
                                <?php elseif ($att_rate_val >= 90): ?>
                                    <i class="fa-solid fa-arrow-up"></i> <?php echo number_format($att_rate_val,2); ?>%
                                <?php elseif ($att_rate_val >= 70): ?>
                                    <i class="fa-solid fa-circle"></i> <?php echo number_format($att_rate_val,2); ?>%
                                <?php else: ?>
                                    <i class="fa-solid fa-arrow-down"></i> <?php echo number_format($att_rate_val,2); ?>%
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="ie-attrate-meta">
                            <span class="ie-attrate-sub"><?php echo $att_count_val; ?> / <?php echo $norm_days_val; ?> days</span>
                        </div>
                    </td>
                    <?php endif; ?>

                    <?php foreach ($schema_reimb as $ri => $rc):
                        $rfield   = $rc[0];
                        $rlabel   = $rc[1];
                        $rcfg_val = $reimb_cfg[$rfield];
                        $rcalc    = $reimb_calc[$rfield];
                        $rsaved   = $reimb_saved[$rfield];
                        $r_is_fixed = in_array($rfield, $fixed_reimb_fields, true);
                        $r_has_saved = $reimb_has_saved[$rfield] ?? false;
                    ?>
                    <td class="ie-td ie-td-reimb">
                        <div class="ie-reimb-wrap">
                            <span class="ie-reimb-pfx">Rs.</span>
                            <input type="number"
                                name="entry[<?php echo $eid; ?>][__reimb][<?php echo htmlspecialchars($rfield); ?>]"
                                class="ie-reimb-input ie-focusable"
                                value="<?php echo $fv($rsaved); ?>"
                                placeholder="0.00" min="0" step="0.01"
                                data-row="<?php echo $eid; ?>" data-col="<?php echo $col_idx + $ri; ?>"
                                data-field="<?php echo htmlspecialchars($rfield); ?>"
                                data-fixed="<?php echo $r_is_fixed ? '1' : '0'; ?>"
                                data-has-saved="<?php echo $r_has_saved ? '1' : '0'; ?>"
                                data-cfg="<?php echo htmlspecialchars($rcfg_val ?? ''); ?>"
                                data-att-rate="<?php echo $att_rate_val; ?>"
                                oninput="updateFilledCount();" onkeydown="handleKeyNav(event,this)"
                                onfocus="this.select();highlightRow(<?php echo $eid; ?>)">
                        </div>
                        <?php if ($rcfg_val !== null && $rcfg_val > 0): ?>
                        <div class="ie-reimb-hint">
                            <span class="ie-reimb-cfg-badge">
                                <i class="fa-solid fa-sliders"></i> Cfg: Rs.<?php echo number_format($rcfg_val,2); ?>
                            </span>
                            <?php if ($r_is_fixed || $schema_reimb_direct): ?>
                            <span class="ie-reimb-calc-badge" style="background:#f1f5f9;color:#475569;border-color:#cbd5e1;">
                                <i class="fa-solid fa-equals"></i> Fixed: Rs.<?php echo number_format($rcfg_val,2); ?>
                            </span>
                            <?php elseif ($rcalc !== null): ?>
                            <span class="ie-reimb-calc-badge">
                                <i class="fa-solid fa-calculator"></i>
                                <?php echo number_format($att_rate_val,1); ?>% × Rs.<?php echo number_format($rcfg_val,2); ?> = Rs.<?php echo number_format($rcalc,2); ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($r_has_saved): ?>
                            <span class="ie-reimb-calc-badge" style="background:#eef2ff;color:#4338ca;border-color:#c7d2fe;">
                                <i class="fa-solid fa-floppy-disk"></i> Saved
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <?php endforeach;
                    $col_idx += count($schema_reimb); ?>

                    <td class="ie-td ie-td-actions">
                        <button type="button" class="ie-hist-btn"
                                onclick="openHistory(<?php echo $eid; ?>,'<?php echo htmlspecialchars($emp['employee_full_name'],ENT_QUOTES); ?>')"
                                title="View history">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>

                <tfoot>
                    <tr class="ie-tfoot-row">
                        <td colspan="2" class="ie-tfoot-label"><i class="fa-solid fa-sigma"></i> Totals</td>
                        <?php if ($is_discretionary): ?>
                        <td class="ie-tfoot-disc" id="gt_disc">—</td>
                        <?php endif; ?>
                        <?php if ($schema_has_ssv): ?>
                        <td class="ie-tfoot-sec-ach">—</td>
                        <td class="ie-tfoot-ssv" id="gt_ssv">—</td>
                        <?php endif; ?>
                        <?php if ($schema_has_other_incentive): ?>
                        <td class="ie-tfoot-other" id="gt_other_incentive">—</td>
                        <?php endif; ?>
                        <?php foreach ($schema_incent_groups as $idef): ?>
                        <td class="ie-tfoot-iach">—</td>
                        <td class="ie-tfoot-incent" id="gt_incent_<?php echo $idef['key']; ?>">—</td>
                        <?php endforeach; ?>
                        <?php if (!$schema_hide_attrate): ?>
                        <td class="ie-tfoot-attrate" id="gt_attrate">—</td>
                        <?php endif; ?>
                        <?php foreach ($schema_reimb as $rc): ?>
                        <td class="ie-tfoot-reimb" id="gt_reimb_<?php echo $rc[0]; ?>">—</td>
                        <?php endforeach; ?>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="ie-save-bar">
            <div class="ie-save-bar-left">
                <div class="ie-save-info">
                    <i class="fa-solid fa-calendar-check"></i>
                    Saving to:
                    <strong><?php echo $active_period ? $month_names[$active_period['month']].' '.$active_period['year'] : 'No period selected'; ?></strong>
                </div>
                <div class="ie-save-count"><span id="saveCountText">0 values entered</span></div>
            </div>
            <div class="ie-save-bar-right">
                <button type="button" class="ie-btn ie-btn-ghost" onclick="clearAllRates()">
                    <i class="fa-solid fa-eraser"></i> Clear All
                </button>
                <button type="button" class="ie-btn ie-btn-reimb" onclick="autoFillAllReimbursements()">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-fill Reimbursements
                </button>
                <button type="submit" class="ie-btn ie-btn-save">
                    <i class="fa-solid fa-floppy-disk"></i> Save All Entries
                </button>
            </div>
        </div>
    </div>
</form>

<?php elseif (empty($employees)): ?>
<div class="ie-empty-state">
    <div class="ie-empty-icon"><i class="fa-solid fa-users-slash"></i></div>
    <h3>No eligible employees found</h3>
    <p>No active employees match the allowed categories for this incentive type.</p>
</div>
<?php endif; ?>

<!-- History Modal -->
<div class="ie-modal-bg" id="histModal" style="display:none;" onclick="if(event.target===this)closeHistory()">
    <div class="ie-modal">
        <div class="ie-modal-header">
            <div class="ie-modal-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <div class="ie-modal-title-wrap">
                <h3 class="ie-modal-title">Entry History</h3>
                <p class="ie-modal-sub" id="histModalSub">—</p>
            </div>
            <button class="ie-modal-close" onclick="closeHistory()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="ie-modal-body" id="histModalBody">
            <div class="ie-modal-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>
        </div>
    </div>
</div>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,500;0,9..40,700;1,9..40,300&family=JetBrains+Mono:wght@400;600;700&display=swap');

:root {
    --ie-white:    #ffffff;
    --ie-border:   #e2e5ea;
    --ie-text:     #111827;
    --ie-muted:    #6b7280;
    --ie-accent:   #18181b;
    --ie-blue:     #2563eb;
    --ie-green:    #16a34a;
    --ie-green-bg: #f0fdf4;
    --ie-amber:    #d97706;
    --ie-amber-bg: #fffbeb;
    --ie-red:      #dc2626;
    --ie-red-bg:   #fef2f2;
    --ie-teal:     #0d9488;
    --ie-teal-bg:  #f0fdfa;
    --ie-disc:     #0891b2;
    --ie-disc-bg:  #ecfeff;
    --ie-disc-border: #a5f3fc;
    --ie-sec:      #7c3aed;
    --ie-sec-bg:   #f5f3ff;
    --ie-sec-border:#c4b5fd;
    --ie-incent:   #059669;
    --ie-incent-bg:#ecfdf5;
    --ie-incent-border:#6ee7b7;
    --ie-orange:   #c2410c;
    --ie-orange-bg:#fff7ed;
    --ie-ssv:      #2563eb;
    --ie-ssv-bg:   #eff6ff;
    --ie-ssv-border:#bfdbfe;
    --ie-attrate:  #0891b2;
    --ie-attrate-bg:#ecfeff;
    --ie-attrate-border:#67e8f9;
    --ie-other:    #be185d;
    --ie-other-bg: #fdf2f8;
    --ie-other-border:#f9a8d4;
    --ie-mono:     'JetBrains Mono', monospace;
    --ie-sans:     'DM Sans', sans-serif;
}

.ie-page-header { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:14px; flex-wrap:wrap; }
.ie-header-left  { display:flex; align-items:flex-start; gap:14px; }
.ie-back-btn     { display:inline-flex; align-items:center; justify-content:center; width:38px; height:38px; border-radius:9px; background:var(--ie-white); border:1.5px solid var(--ie-border); color:var(--ie-text); text-decoration:none; font-size:13px; transition:all .2s; flex-shrink:0; margin-top:4px; }
.ie-back-btn:hover { background:var(--ie-accent); color:#fff; border-color:var(--ie-accent); }
.ie-breadcrumb   { display:flex; align-items:center; gap:5px; font-size:10px; color:var(--ie-muted); font-family:var(--ie-sans); margin-bottom:3px; text-transform:uppercase; letter-spacing:.5px; }
.ie-breadcrumb i { font-size:8px; }
.ie-page-title   { font-size:20px; font-weight:700; color:var(--ie-text); margin:0 0 3px; font-family:var(--ie-sans); letter-spacing:-.2px; }
.ie-page-sub     { font-size:12px; color:var(--ie-muted); margin:0 0 5px; font-family:var(--ie-sans); }
.ie-header-right { display:flex; flex-direction:column; gap:8px; align-items:flex-end; }
.ie-disc-badge-header { display:inline-flex; align-items:center; gap:6px; margin-top:4px; padding:4px 10px; background:var(--ie-disc-bg); border:1px solid var(--ie-disc-border); border-radius:20px; font-size:11px; font-weight:700; color:#0e7490; font-family:var(--ie-sans); }
.ie-cat-badge-header  { display:inline-flex; align-items:center; gap:6px; margin-top:4px; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; font-family:var(--ie-sans); }
.ie-cat-mr  { background:#faf5ff; border:1px solid #e9d5ff; color:#7e22ce; }
.ie-cat-cc  { background:#fffbeb; border:1px solid #fde68a; color:#b45309; }
.ie-cat-acc { background:#fff7ed; border:1px solid #fed7aa; color:#c2410c; }
.ie-cat-st  { background:#fff1f2; border:1px solid #fecdd3; color:#e11d48; }
.ie-cat-off { background:#f8fafc; border:1px solid #cbd5e1; color:#475569; }

.ie-period-selector { display:flex; align-items:center; gap:10px; background:var(--ie-white); border:1.5px solid var(--ie-border); border-radius:10px; padding:8px 14px; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.ie-period-icon  { color:var(--ie-blue); font-size:16px; flex-shrink:0; }
.ie-period-inner { display:flex; flex-direction:column; gap:1px; }
.ie-period-label { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--ie-muted); }
.ie-period-select { border:none; outline:none; font-size:13px; font-weight:700; color:var(--ie-text); font-family:var(--ie-sans); background:transparent; cursor:pointer; padding:0; }
.ie-no-period    { font-size:12px; color:var(--ie-red); font-weight:600; }
.ie-period-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:20px; font-size:10px; font-weight:700; }
.ie-period-badge.open   { background:#dcfce7; color:#166534; }
.ie-period-badge.closed,
.ie-period-badge.locked { background:#f3f4f6; color:#4b5563; }

.ie-stats-strip { display:flex; gap:5px; }
.ie-stat { background:var(--ie-white); border:1.5px solid var(--ie-border); border-radius:9px; padding:7px 14px; display:flex; flex-direction:column; align-items:center; gap:1px; min-width:66px; }
.ie-stat-num { font-size:17px; font-weight:700; color:var(--ie-text); font-family:var(--ie-mono); }
.ie-stat-lbl { font-size:9px; color:var(--ie-muted); text-transform:uppercase; letter-spacing:.4px; font-weight:600; }

.ie-alert { display:flex; align-items:center; gap:8px; padding:10px 14px; border-radius:8px; font-size:13px; font-weight:500; margin-bottom:10px; font-family:var(--ie-sans); }
.ie-alert-success { background:var(--ie-green-bg); border:1px solid #bbf7d0; color:#166534; }
.ie-alert-warning { background:var(--ie-amber-bg); border:1px solid #fde68a; color:#92400e; }
.ie-alert-danger  { background:var(--ie-red-bg);   border:1px solid #fecaca; color:var(--ie-red); }
.ie-alert-close   { margin-left:auto; background:none; border:none; cursor:pointer; color:inherit; opacity:.6; font-size:13px; padding:0 3px; }
.ie-alert-close:hover { opacity:1; }
.ie-link { color:var(--ie-blue); text-decoration:none; font-weight:600; margin-left:5px; }

.ie-hint-bar { display:flex; align-items:center; justify-content:space-between; gap:12px; background:#fafbfc; border:1px solid var(--ie-border); border-radius:8px; padding:7px 14px; margin-bottom:10px; flex-wrap:wrap; }
.ie-hint-items { display:flex; gap:12px; flex-wrap:wrap; }
.ie-hint { font-size:11px; color:var(--ie-muted); font-family:var(--ie-sans); display:flex; align-items:center; gap:4px; }
kbd { background:#fff; border:1px solid #d1d5db; border-radius:3px; padding:1px 4px; font-size:10px; font-family:var(--ie-mono); color:#374151; box-shadow:0 1px 0 #c1c7d0; }
.ie-hint-filter { display:flex; align-items:center; gap:5px; position:relative; }
.ie-filter-input { padding:6px 28px 6px 10px; border:1.5px solid var(--ie-border); border-radius:7px; font-size:12px; font-family:var(--ie-sans); width:190px; background:#fff; color:var(--ie-text); }
.ie-filter-input:focus { outline:none; border-color:var(--ie-blue); }
.ie-filter-clear { position:absolute; right:7px; background:none; border:none; color:var(--ie-muted); cursor:pointer; font-size:11px; padding:0; display:flex; align-items:center; }

.ie-table-wrap   { background:var(--ie-white); border:1.5px solid var(--ie-border); border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.05); margin-bottom:80px; }
.ie-table-scroll { overflow-x:auto; }
.ie-table        { width:100%; border-collapse:collapse; font-size:13px; font-family:var(--ie-sans); }

.ie-thead-group { background:#18181b; }
.ie-thead-sub   { background:#111114; }

.ie-th { padding:9px 10px; text-align:left; font-size:10px; font-weight:700; letter-spacing:.4px; text-transform:uppercase; color:#9ca3af; border-right:1px solid #2d2d30; white-space:nowrap; }
.ie-th:last-child { border-right:none; }
.ie-th-inner     { display:flex; align-items:center; gap:5px; }
.ie-th-sticky    { position:sticky; z-index:3; background:#18181b; }
.ie-th-num  { width:36px; text-align:center; left:0; }
.ie-th-emp  { min-width:220px; left:36px; border-right:2px solid #3f3f46; }
.ie-th-group { text-align:center; font-size:11px; border-bottom:1px solid #3f3f46; border-right:2px solid #3f3f46; padding:10px 8px; }
.ie-th-group-disc { color:#2dd4bf; background:#0f1f20; text-align:center; font-size:11px; border-bottom:1px solid #3f3f46; border-right:2px solid #3f3f46; padding:10px 8px; vertical-align:middle; }
.ie-th-inner-disc { display:flex; align-items:center; justify-content:center; gap:6px; }
.ie-th-group-sec { color:#c4b5fd; background:#1a1030; text-align:center; font-size:11px; border-bottom:1px solid #3f3f46; border-right:2px solid #6d28d9; padding:10px 8px; }
.ie-th-inner-sec { display:flex; align-items:center; justify-content:center; gap:6px; }
.ie-th-group-other { color:#f9a8d4; background:#2a0f1e; text-align:center; font-size:11px; border-bottom:1px solid #3f3f46; border-right:2px solid #be185d; padding:10px 8px; vertical-align:middle; }
.ie-th-inner-other { display:flex; align-items:center; justify-content:center; gap:6px; }
.ie-th-auto-badge { display:inline-flex; align-items:center; gap:3px; background:rgba(110,231,183,.15); border:1px solid rgba(110,231,183,.3); border-radius:10px; padding:2px 7px; font-size:9px; font-weight:700; color:#6ee7b7; }
.ie-th-group-attrate { color:#67e8f9; background:#04191e; text-align:center; font-size:11px; border-bottom:1px solid #3f3f46; border-right:2px solid #0891b2; padding:10px 8px; vertical-align:middle; }
.ie-th-inner-attrate { display:flex; align-items:center; justify-content:center; gap:6px; flex-wrap:wrap; }
.ie-th-group-reimb { color:#fdba74; background:#1f1005; text-align:center; font-size:11px; border-bottom:1px solid #3f3f46; border-right:2px solid #c2410c; padding:10px 8px; }
.ie-th-inner-reimb { display:flex; align-items:center; justify-content:center; gap:6px; flex-wrap:wrap; }
.ie-th-sub    { color:#9ca3af; font-size:9px; padding:6px 8px; }
.ie-th-sec-ach  { color:#c4b5fd; border-left:2px solid #6d28d9; border-right:1px solid #2d2d30; }
.ie-th-sec-ssv  { color:#93c5fd; border-right:2px solid #059669; }
.ie-th-iach  { color:#fbbf24; border-right:1px solid #2d2d30; }
.ie-th-iamt  { color:#6ee7b7; border-right:2px solid #059669; }
.ie-th-reimb  { color:#fdba74; border-right:1px solid #3f3f46; }
.ie-th-actions{ color:#9ca3af; }

.ie-tr { border-bottom:1px solid var(--ie-border); transition:background .12s; }
.ie-tr:last-child { border-bottom:none; }
.ie-tr:hover       { background:#f8faff; }
.ie-tr.ie-tr-filled{ background:#f0fdf4; }
.ie-tr.ie-tr-filled:hover { background:#dcfce7; }
.ie-tr.ie-tr-active{ background:#eff6ff !important; box-shadow:inset 3px 0 0 var(--ie-blue); }
.ie-tr.ie-row-hidden{ display:none; }
.ie-tr.ie-tr-exited { background:#fefce8; }
.ie-tr.ie-tr-exited:hover { background:#fef9c3; }

.ie-td { padding:7px 8px; vertical-align:middle; color:var(--ie-text); }
.ie-td-num { text-align:center; color:#d1d5db; font-size:11px; font-family:var(--ie-mono); position:sticky; left:0; background:inherit; z-index:1; }
.ie-tr:hover        .ie-td-num { background:#f8faff; }
.ie-tr.ie-tr-filled .ie-td-num { background:#f0fdf4; }
.ie-tr.ie-tr-active .ie-td-num { background:#eff6ff; }
.ie-td-emp { position:sticky; left:36px; background:var(--ie-white); z-index:1; border-right:2px solid var(--ie-border); }
.ie-tr:hover        .ie-td-emp { background:#f8faff; }
.ie-tr.ie-tr-filled .ie-td-emp { background:#f0fdf4; }
.ie-tr.ie-tr-active .ie-td-emp { background:#eff6ff; }
.ie-tr.ie-tr-exited .ie-td-emp,
.ie-tr.ie-tr-exited .ie-td-num { background:#fefce8; }
.ie-emp-cell   { display:flex; align-items:center; gap:9px; }
.ie-emp-avatar { width:32px; height:32px; border-radius:8px; background:#18181b; color:#fff; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700; flex-shrink:0; font-family:var(--ie-mono); }
.ie-emp-info   { min-width:0; }
.ie-emp-name   { font-size:13px; font-weight:700; color:var(--ie-text); white-space:nowrap; }
.ie-emp-meta   { display:flex; gap:6px; align-items:center; margin-top:2px; flex-wrap:wrap; }
.ie-emp-id     { font-size:10px; font-family:var(--ie-mono); font-weight:700; color:var(--ie-blue); background:#eff6ff; padding:1px 5px; border-radius:3px; }
.ie-emp-desig  { font-size:10px; color:#1e40af; background:#dbeafe; padding:1px 5px; border-radius:3px; font-weight:600; }
.ie-emp-mobile { font-size:10px; color:var(--ie-muted); display:flex; align-items:center; gap:3px; }
.ie-emp-exit-badge { font-size:9px; font-weight:700; display:inline-flex; align-items:center; gap:4px; padding:2px 7px; border-radius:10px; white-space:nowrap; }
.ie-emp-exit-resigned { background:#fef9c3; color:#92400e; border:1px solid #fde68a; }
.ie-emp-exit-terminated { background:#ffe4e6; color:#9f1239; border:1px solid #fecdd3; }

.ie-td-disc-amt { padding:5px 6px 3px; border-right:2px solid #a5f3fc; background:#f0fdfe; }
.ie-tr:hover .ie-td-disc-amt        { background:#e0f9fd; }
.ie-tr.ie-tr-active .ie-td-disc-amt { background:#cff6ff; }
.ie-disc-wrap { display:flex; align-items:stretch; border:1.5px solid #67e8f9; border-radius:6px; overflow:hidden; background:#ecfeff; transition:all .2s; width:130px; }
.ie-disc-wrap:focus-within { border-color:#0891b2; box-shadow:0 0 0 3px rgba(8,145,178,.15); background:#fff; }
.ie-disc-pfx { background:#cffafe; color:#0e7490; font-size:10px; font-weight:700; padding:0 6px; display:flex; align-items:center; border-right:1.5px solid #67e8f9; font-family:var(--ie-mono); white-space:nowrap; }
.ie-disc-input { flex:1; border:none; outline:none; padding:5px 4px; font-size:12px; font-family:var(--ie-mono); font-weight:600; color:#164e63; background:transparent; text-align:right; width:100%; -moz-appearance:textfield; }
.ie-disc-input::-webkit-outer-spin-button, .ie-disc-input::-webkit-inner-spin-button { -webkit-appearance:none; }
.ie-cfg-badge { margin-top:3px; font-size:9px; display:inline-flex; align-items:center; gap:3px; border-radius:4px; padding:1px 5px; font-family:var(--ie-mono); white-space:nowrap; }
.ie-cfg-badge-disc { background:#cffafe; color:#0e7490; border:1px solid #67e8f9; }

.ie-td-sec-ach { padding:5px 6px 3px; border-left:2px solid #6d28d9; border-right:1px solid #ddd6fe; background:#faf5ff; }
.ie-tr:hover .ie-td-sec-ach        { background:#f5f0ff; }
.ie-tr.ie-tr-active .ie-td-sec-ach { background:#ede9fe; }
.ie-sec-ach-wrap { display:flex; align-items:stretch; border:1.5px solid #c4b5fd; border-radius:6px; overflow:hidden; background:#f5f3ff; transition:all .2s; width:100px; }
.ie-sec-ach-wrap:focus-within { border-color:#7c3aed; box-shadow:0 0 0 3px rgba(124,58,237,.15); background:#fff; }
.ie-sec-ach-input { flex:1; border:none; outline:none; padding:5px 4px; font-size:12px; font-family:var(--ie-mono); font-weight:700; color:#4c1d95; background:transparent; text-align:right; -moz-appearance:textfield; }
.ie-sec-ach-input::-webkit-outer-spin-button, .ie-sec-ach-input::-webkit-inner-spin-button { -webkit-appearance:none; }
.ie-sec-ach-sfx { background:#ede9fe; color:#7c3aed; font-size:10px; font-weight:700; padding:0 6px; display:flex; align-items:center; border-left:1.5px solid #c4b5fd; font-family:var(--ie-mono); }
.ie-sec-ach-hint { margin-top:3px; font-size:9px; color:#7c3aed; white-space:nowrap; }

.ie-td-ssv-result { padding:5px 6px 3px; border-right:2px solid #059669; background:#f0f9ff; }
.ie-ssv-result-wrap { display:flex; align-items:stretch; border:1.5px solid #93c5fd; border-radius:6px; overflow:hidden; background:#eff6ff; width:130px; }
.ie-ssv-result-wrap:focus-within { border-color:#1d4ed8; box-shadow:0 0 0 3px rgba(29,78,216,.15); background:#fff; }
.ie-ssv-result-input { flex:1; border:none; outline:none; padding:5px 4px; font-size:12px; font-family:var(--ie-mono); font-weight:700; color:#1e3a8a; background:transparent; text-align:right; width:80px; -moz-appearance:textfield; cursor:text; }
.ie-ssv-result-input::-webkit-outer-spin-button,.ie-ssv-result-input::-webkit-inner-spin-button { -webkit-appearance:none; }
.ie-ssv-result-input.ssv-has-value { color:#1d4ed8; font-weight:800; }
.ie-ssv-result-pfx { background:#dbeafe; color:#1d4ed8; font-size:10px; font-weight:700; padding:0 6px; display:flex; align-items:center; border-right:1.5px solid #93c5fd; font-family:var(--ie-mono); }
.ie-ssv-matched-label { margin-top:3px; font-size:9px; color:#2563eb; font-family:var(--ie-mono); white-space:nowrap; background:#dbeafe; border-radius:3px; padding:1px 5px; display:none; }
.ie-ssv-matched-label.show { display:block; }

.ie-td-other-incent { padding:5px 6px 3px; border-right:2px solid #be185d; background:#fdf2f8; }
.ie-tr:hover .ie-td-other-incent        { background:#fce7f3; }
.ie-tr.ie-tr-active .ie-td-other-incent { background:#fbcfe8; }
.ie-other-wrap { display:flex; align-items:stretch; border:1.5px solid #f9a8d4; border-radius:6px; overflow:hidden; background:#fdf2f8; transition:all .2s; width:130px; }
.ie-other-wrap:focus-within { border-color:#be185d; box-shadow:0 0 0 3px rgba(190,24,93,.15); background:#fff; }
.ie-other-pfx { background:#fce7f3; color:#be185d; font-size:10px; font-weight:700; padding:0 6px; display:flex; align-items:center; border-right:1.5px solid #f9a8d4; font-family:var(--ie-mono); white-space:nowrap; }
.ie-other-input { flex:1; border:none; outline:none; padding:5px 4px; font-size:12px; font-family:var(--ie-mono); font-weight:700; color:#831843; background:transparent; text-align:right; width:100%; -moz-appearance:textfield; }
.ie-other-input::-webkit-outer-spin-button, .ie-other-input::-webkit-inner-spin-button { -webkit-appearance:none; }
.ie-other-hint { margin-top:3px; font-size:9px; color:#be185d; white-space:nowrap; }

.ie-td-iach { padding:5px 6px 3px; border-right:1px solid #e5e7eb; }
.ie-iach-wrap { display:flex; align-items:stretch; border:1.5px solid #e5e7eb; border-radius:6px; overflow:hidden; transition:all .2s; width:100px; }
.ie-iach-wrap:focus-within { box-shadow:0 0 0 3px rgba(0,0,0,.08); background:#fff; }
.ie-iach-wrap.iach-disabled { opacity:.4; pointer-events:none; }
.ie-iach-input { flex:1; border:none; outline:none; padding:5px 4px; font-size:12px; font-family:var(--ie-mono); font-weight:700; background:transparent; text-align:right; -moz-appearance:textfield; color:#1f2937; }
.ie-iach-input::-webkit-outer-spin-button, .ie-iach-input::-webkit-inner-spin-button { -webkit-appearance:none; }
.ie-iach-input.iach-met  { color:#047857; font-weight:800; }
.ie-iach-input.iach-miss { color:#dc2626; }
.ie-iach-sfx { font-size:10px; font-weight:700; padding:0 5px; display:flex; align-items:center; border-left:1.5px solid #e5e7eb; font-family:var(--ie-mono); }
.ie-iach-meta { margin-top:3px; display:flex; align-items:center; justify-content:space-between; gap:4px; }
.ie-iach-thr  { font-size:9px; font-family:var(--ie-mono); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100px; }
.ie-iach-status { font-size:11px; flex-shrink:0; }
.ie-iach-status.status-met  { color:#059669; }
.ie-iach-status.status-miss { color:#dc2626; }
.ie-iach-status.status-idle { color:#d1d5db; }

/* ACC/CC tier hint - compact scrollable in meta */
.ie-acc-tier-hint,
.ie-cc-tier-hint { font-size:8px; display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100px; }

.ie-td-iamt { padding:5px 6px 3px; }
.ie-iamt-wrap { display:flex; align-items:stretch; border:1.5px solid #e5e7eb; border-radius:6px; overflow:hidden; width:120px; transition:all .2s; }
.ie-iamt-wrap:focus-within { box-shadow:0 0 0 3px rgba(0,0,0,.08); background:#fff; }
.ie-iamt-wrap.iamt-active { border-color:#059669; background:#f0fdf4; }
.ie-iamt-wrap.iamt-locked { border-color:#d1d5db; background:#f9fafb; opacity:.65; }
.ie-iamt-wrap.iamt-locked .ie-iamt-input { color:#d1d5db; cursor:not-allowed; }
.ie-iamt-wrap.iamt-warn { border-color:#f59e0b; background:#fffbeb; box-shadow:0 0 0 3px rgba(245,158,11,.15); }
.ie-iamt-pfx { font-size:10px; font-weight:700; padding:0 6px; display:flex; align-items:center; border-right:1.5px solid #e5e7eb; font-family:var(--ie-mono); white-space:nowrap; }
.ie-iamt-input { flex:1; border:none; outline:none; padding:5px 4px; font-size:12px; font-family:var(--ie-mono); font-weight:700; color:#064e3b; background:transparent; text-align:right; -moz-appearance:textfield; }
.ie-iamt-input::-webkit-outer-spin-button,.ie-iamt-input::-webkit-inner-spin-button { -webkit-appearance:none; }
.ie-iamt-input.iamt-earned { color:#059669; font-weight:800; }
.ie-iamt-input.iamt-zero   { color:#d1d5db; }
.ie-iamt-input.iamt-warn   { color:#b45309; font-weight:800; }
.ie-iamt-hint { margin-top:3px; font-size:9px; font-family:var(--ie-mono); white-space:nowrap; border-radius:3px; padding:1px 5px; display:flex; align-items:center; gap:3px; }
.ie-iamt-hint.hint-met    { background:#d1fae5; color:#065f46; }
.ie-iamt-hint.hint-locked { background:#f3f4f6; color:#9ca3af; }
.ie-iamt-hint.hint-warn   { background:#fef3c7; color:#92400e; }

.ie-td-attrate { padding:5px 8px; border-left:2px solid #0891b2; border-right:2px solid #0891b2; background:#f0fdfe; text-align:center; }
.ie-tr:hover .ie-td-attrate        { background:#e0f9fd; }
.ie-tr.ie-tr-active .ie-td-attrate { background:#cff6ff; }
.ie-attrate-pill-wrap { display:flex; justify-content:center; }
.ie-attrate-pill { display:inline-flex; align-items:center; gap:4px; padding:4px 11px; border-radius:20px; font-size:12px; font-weight:800; font-family:var(--ie-mono); white-space:nowrap; letter-spacing:.2px; }
.ie-attrate-pill.rate-high  { background:#dcfce7; color:#14532d; border:1px solid #bbf7d0; }
.ie-attrate-pill.rate-mid   { background:#fef3c7; color:#78350f; border:1px solid #fde68a; }
.ie-attrate-pill.rate-low   { background:#fee2e2; color:#7f1d1d; border:1px solid #fecaca; }
.ie-attrate-pill.rate-zero  { background:#f3f4f6; color:#9ca3af; border:1px solid #e5e7eb; }
.ie-attrate-meta { margin-top:3px; }
.ie-attrate-sub  { font-size:9px; color:var(--ie-muted); font-family:var(--ie-mono); white-space:nowrap; }
.ie-tfoot-attrate { text-align:center; font-family:var(--ie-mono); font-size:11px; color:#67e8f9; border-left:2px solid #0891b2; border-right:2px solid #0891b2; }

.ie-td-reimb { padding:5px 6px; border-right:1px solid #fed7aa; background:#fff7ed; }
.ie-reimb-wrap { display:flex; align-items:stretch; border:1.5px solid #fdba74; border-radius:6px; overflow:hidden; background:#fff7ed; transition:all .2s; width:105px; }
.ie-reimb-wrap:focus-within { border-color:var(--ie-orange); box-shadow:0 0 0 3px rgba(194,65,12,.10); background:#fff; }
.ie-reimb-pfx { background:#ffedd5; color:#c2410c; font-size:10px; font-weight:700; padding:0 6px; display:flex; align-items:center; border-right:1.5px solid #fdba74; font-family:var(--ie-mono); white-space:nowrap; }
.ie-reimb-input { flex:1; border:none; outline:none; padding:5px 4px; font-size:12px; font-family:var(--ie-mono); font-weight:600; color:#7c2d12; background:transparent; text-align:right; width:100%; -moz-appearance:textfield; }
.ie-reimb-input::-webkit-outer-spin-button, .ie-reimb-input::-webkit-inner-spin-button { -webkit-appearance:none; }
.ie-reimb-input::placeholder { color:#fed7aa; font-weight:400; }
.ie-reimb-hint { margin-top:3px; display:flex; flex-direction:column; gap:2px; }
.ie-reimb-cfg-badge { font-size:9px; color:#92400e; background:#ffedd5; border:1px solid #fdba74; border-radius:3px; padding:1px 5px; display:inline-flex; align-items:center; gap:3px; font-family:var(--ie-mono); white-space:nowrap; }
.ie-reimb-calc-badge { font-size:9px; color:#065f46; background:#d1fae5; border:1px solid #6ee7b7; border-radius:3px; padding:1px 5px; display:inline-flex; align-items:center; gap:3px; font-family:var(--ie-mono); white-space:nowrap; }

.ie-td-actions { padding:7px 8px; }
.ie-hist-btn   { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:6px; border:1.5px solid var(--ie-border); background:var(--ie-white); color:var(--ie-muted); cursor:pointer; transition:all .2s; font-size:12px; }
.ie-hist-btn:hover { background:var(--ie-blue); border-color:var(--ie-blue); color:#fff; }

.ie-tfoot-row   { background:#18181b; }
.ie-tfoot-row td{ padding:9px 10px; }
.ie-tfoot-label { color:#9ca3af; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; font-family:var(--ie-sans); display:flex; align-items:center; gap:6px; }
.ie-tfoot-disc  { text-align:right; font-family:var(--ie-mono); font-weight:700; font-size:12px; color:#2dd4bf; border-right:2px solid #3f3f46; white-space:nowrap; }
.ie-tfoot-sec-ach { text-align:right; font-family:var(--ie-mono); font-size:12px; color:#c4b5fd; border-left:2px solid #6d28d9; }
.ie-tfoot-ssv   { text-align:right; font-family:var(--ie-mono); font-weight:700; font-size:12px; color:#93c5fd; border-right:2px solid #059669; white-space:nowrap; }
.ie-tfoot-other { text-align:right; font-family:var(--ie-mono); font-weight:700; font-size:12px; color:#f9a8d4; border-right:2px solid #be185d; white-space:nowrap; }
.ie-tfoot-iach  { text-align:right; font-family:var(--ie-mono); font-size:11px; color:#fbbf24; }
.ie-tfoot-incent{ text-align:right; font-family:var(--ie-mono); font-weight:700; font-size:12px; color:#6ee7b7; border-right:2px solid #059669; white-space:nowrap; }
.ie-tfoot-reimb { text-align:right; font-family:var(--ie-mono); font-weight:700; font-size:12px; color:#fdba74; border-right:1px solid #3f3f46; white-space:nowrap; }

.ie-save-bar       { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 18px; background:var(--ie-white); border-top:2px solid var(--ie-border); flex-wrap:wrap; }
.ie-save-bar-left  { display:flex; flex-direction:column; gap:1px; }
.ie-save-info      { display:flex; align-items:center; gap:7px; font-size:13px; color:var(--ie-muted); font-family:var(--ie-sans); }
.ie-save-info i    { color:var(--ie-blue); }
.ie-save-info strong { color:var(--ie-text); }
.ie-save-count     { font-size:11px; color:var(--ie-muted); }
.ie-save-bar-right { display:flex; gap:8px; align-items:center; }
.ie-btn            { display:inline-flex; align-items:center; gap:6px; padding:10px 20px; border:none; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; transition:all .2s; font-family:var(--ie-sans); text-decoration:none; white-space:nowrap; }
.ie-btn-save       { background:#18181b; color:#fff; box-shadow:0 2px 8px rgba(0,0,0,.15); }
.ie-btn-save:hover { background:#374151; transform:translateY(-1px); }
.ie-btn-ghost      { background:#f9fafb; color:var(--ie-muted); border:1.5px solid var(--ie-border); }
.ie-btn-ghost:hover{ background:#fee2e2; color:var(--ie-red); border-color:#fca5a5; }
.ie-btn-reimb      { background:#fff7ed; color:var(--ie-orange); border:1.5px solid #fdba74; }
.ie-btn-reimb:hover{ background:#ffedd5; }

.ie-empty-state    { text-align:center; padding:70px 20px; background:var(--ie-white); border:1.5px solid var(--ie-border); border-radius:12px; }
.ie-empty-icon     { width:58px; height:58px; background:#f3f4f6; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:24px; color:#d1d5db; margin:0 auto 14px; }
.ie-empty-state h3 { font-size:15px; font-weight:700; color:var(--ie-text); margin:0 0 6px; }
.ie-empty-state p  { font-size:13px; color:var(--ie-muted); margin:0; }

.ie-modal-bg    { position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9999; display:flex; align-items:center; justify-content:center; padding:20px; backdrop-filter:blur(3px); }
.ie-modal       { background:var(--ie-white); border-radius:14px; width:100%; max-width:700px; max-height:85vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 24px 80px rgba(0,0,0,.25); animation:ie-modal-in .28s cubic-bezier(.22,.68,0,1.2); }
@keyframes ie-modal-in { from { opacity:0; transform:translateY(30px) scale(.97); } to { opacity:1; transform:none; } }
.ie-modal-header{ display:flex; align-items:center; gap:12px; padding:18px 22px; border-bottom:1px solid var(--ie-border); background:#fafafa; flex-shrink:0; }
.ie-modal-icon  { width:40px; height:40px; background:#18181b; color:#fff; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
.ie-modal-title-wrap { flex:1; }
.ie-modal-title { font-size:15px; font-weight:700; margin:0 0 2px; color:var(--ie-text); font-family:var(--ie-sans); }
.ie-modal-sub   { font-size:11px; color:var(--ie-muted); margin:0; font-family:var(--ie-sans); }
.ie-modal-close { background:none; border:none; font-size:17px; cursor:pointer; color:var(--ie-muted); width:32px; height:32px; display:flex; align-items:center; justify-content:center; border-radius:6px; transition:all .2s; }
.ie-modal-close:hover { background:#f3f4f6; color:var(--ie-text); }
.ie-modal-body  { padding:18px 22px; overflow-y:auto; flex:1; }
.ie-modal-loading { display:flex; align-items:center; gap:10px; color:var(--ie-muted); font-size:13px; padding:16px; }
.ie-hist-table  { width:100%; border-collapse:collapse; font-size:12px; font-family:var(--ie-sans); }
.ie-hist-table thead { background:#f3f4f6; }
.ie-hist-table th { padding:7px 10px; text-align:left; font-weight:700; color:var(--ie-muted); font-size:10px; text-transform:uppercase; letter-spacing:.4px; border-bottom:1px solid var(--ie-border); }
.ie-hist-table td { padding:8px 10px; border-bottom:1px solid #f3f4f6; color:var(--ie-text); }
.ie-hist-table tbody tr:hover { background:#f8faff; }
.ie-hist-table tbody tr:last-child td { border-bottom:none; }
.ie-hist-badge  { border-radius:3px; padding:2px 6px; font-size:10px; font-weight:700; display:inline-block; }

@media(max-width:900px) {
    .ie-page-header { flex-direction:column; }
    .ie-header-right { align-items:flex-start; width:100%; }
    .ie-save-bar { flex-direction:column; align-items:flex-start; }
    .ie-save-bar-right { width:100%; }
    .ie-btn-save { flex:1; justify-content:center; }
}
</style>

<script>
const INCENTIVE_TYPE_ID = <?php echo $incentive_type_id; ?>;
const SEL_PERIOD_ID     = <?php echo $sel_period_id; ?>;
const MONTH_NAMES       = <?php echo json_encode($month_names); ?>;
const IS_DISCRETIONARY  = <?php echo $is_discretionary ? 'true' : 'false'; ?>;
const REIMB_FIELD_IDS   = <?php echo json_encode(array_map(fn($r) => $r[0], $schema_reimb)); ?>;

function onSecAchievementInput(inp) {
    const empId   = inp.getAttribute('data-empid');
    const ach     = parseFloat(inp.value);
    let ssvRates  = {};
    try { ssvRates = JSON.parse(inp.getAttribute('data-ssv-rates') || '{}'); } catch(e) {}

    let bestLabel = null, bestVal = -1, bestAmt = 0;
    if (!isNaN(ach) && ach > 0) {
        Object.entries(ssvRates).forEach(([label, amount]) => {
            const lv = parseFloat(String(label).replace('%',''));
            if (!isNaN(lv) && lv <= ach && lv > bestVal) {
                bestVal = lv; bestLabel = label; bestAmt = parseFloat(amount || 0);
            }
        });
    }

    // NOTE: This function only runs when the user actively types into the
    // Achievement % field (oninput). It intentionally overwrites the SSV
    // Amount field with the auto-calculated value, since that is the
    // expected "auto-fill from achievement %" behaviour. Any value the
    // user previously typed directly into the SSV Amount box (and saved)
    // is preserved on page load — see the DOMContentLoaded handler below,
    // which does NOT call this function, only restyles/labels the saved
    // value so it is not clobbered on reload.
    const ssvInp = document.getElementById('ssv_result_' + empId);
    const ssvLbl = document.getElementById('ssv_lbl_' + empId);
    if (ssvInp) {
        if (!isNaN(ach) && ach > 0 && bestLabel !== null && bestAmt > 0) {
            ssvInp.value = bestAmt.toFixed(2);
            ssvInp.classList.add('ssv-has-value');
            if (ssvLbl) { ssvLbl.textContent = 'Rate: ' + bestLabel; ssvLbl.classList.add('show'); }
        } else {
            ssvInp.value = '';
            ssvInp.classList.remove('ssv-has-value');
            if (ssvLbl) { ssvLbl.textContent = ''; ssvLbl.classList.remove('show'); }
        }
    }
    updateFilledCount(); updateGrandTotals();
}

// Manual edit of the SSV amount field (auto-filled by achievement %, but user can override)
// Whatever value sits in this input at the moment "Save All Entries" is
// clicked is exactly what gets POSTed as entry[emp][__ssv_amt][amount] and
// stored under component_label = '__ssv_amount' in incentive_entries — so a
// manual edit here is saved and reloaded correctly, the same as an
// auto-filled value.
function onSSVAmountManualInput(inp) {
    const val = parseFloat(inp.value);
    if (!isNaN(val) && val > 0) {
        inp.classList.add('ssv-has-value');
    } else {
        inp.classList.remove('ssv-has-value');
    }
    // Clear the "Rate: X%" auto-fill badge once the user has hand-edited the
    // amount, so it's clear the shown figure is a manual entry, not a rate
    // match. The stored value itself is unaffected either way.
    const empId = inp.id.replace('ssv_result_', '');
    const ssvLbl = document.getElementById('ssv_lbl_' + empId);
    if (ssvLbl) { ssvLbl.textContent = ''; ssvLbl.classList.remove('show'); }
    updateFilledCount(); updateGrandTotals();
}

// Used only on initial page load to show the matching rate label next to a
// previously saved SSV Amount, WITHOUT recalculating/overwriting the saved
// amount itself. This preserves manually-edited & saved SSV amounts across
// page reloads (previously, onSecAchievementInput was called on load and
// always recomputed/overwrote the field from the achievement % + rate
// table, silently discarding any manual override that had been saved).
function initSSVMatchLabel(achInp) {
    const empId  = achInp.getAttribute('data-empid');
    const ach    = parseFloat(achInp.value);
    let ssvRates = {};
    try { ssvRates = JSON.parse(achInp.getAttribute('data-ssv-rates') || '{}'); } catch(e) {}

    let bestLabel = null, bestVal = -1;
    if (!isNaN(ach) && ach > 0) {
        Object.entries(ssvRates).forEach(([label, amount]) => {
            const lv = parseFloat(String(label).replace('%',''));
            if (!isNaN(lv) && lv <= ach && lv > bestVal) {
                bestVal = lv; bestLabel = label;
            }
        });
    }
    const ssvLbl = document.getElementById('ssv_lbl_' + empId);
    if (ssvLbl && bestLabel !== null) {
        ssvLbl.textContent = 'Rate: ' + bestLabel;
        ssvLbl.classList.add('show');
    }
}

// ── CC tier amount resolver ─────────────────────────────────────────────────
function resolveCCTierAmount(empId, ikey, ach) {
    if (!PAGE_IS_CC || !CC_TIER_KEYS.includes(ikey)) return null;
    const tiers = ((CC_TIER_GROUPS[empId] || {})[ikey] || []).slice().sort((a, b) => b.threshold - a.threshold);
    for (const tier of tiers) {
        if (!isNaN(ach) && ach >= tier.threshold) return tier.amount;
    }
    return 0;
}

function getCCTierMax(empId, ikey) {
    if (!PAGE_IS_CC || !CC_TIER_KEYS.includes(ikey)) return 0;
    const tiers = (CC_TIER_GROUPS[empId] || {})[ikey] || [];
    return tiers.length ? Math.max(...tiers.map(t => t.amount)) : 0;
}

// ── ACC tier amount resolver ────────────────────────────────────────────────
function resolveACCTierAmount(empId, ikey, ach) {
    if (!PAGE_IS_ACC || !ACC_TIER_KEYS.includes(ikey)) return null;
    const tiers = ((ACC_TIER_GROUPS[empId] || {})[ikey] || []).slice().sort((a, b) => b.threshold - a.threshold);
    for (const tier of tiers) {
        if (!isNaN(ach) && ach >= tier.threshold) return tier.amount;
    }
    return 0;
}

function getACCTierMax(empId, ikey) {
    if (!PAGE_IS_ACC || !ACC_TIER_KEYS.includes(ikey)) return 0;
    const tiers = (ACC_TIER_GROUPS[empId] || {})[ikey] || [];
    return tiers.length ? Math.max(...tiers.map(t => t.amount)) : 0;
}

function onIncentAchInput(inp) {
    const empId    = inp.getAttribute('data-empid');
    const ikey     = inp.getAttribute('data-ikey');
    let   cfgAmt   = parseFloat(inp.getAttribute('data-cfg') || 0);
    const threshold= parseFloat(inp.getAttribute('data-threshold') || 0);
    const ach      = parseFloat(inp.value);

    // MR: pick amount from placement tiers
    let placementAmt = 0;
    if (PAGE_IS_MR && ikey === 'placement_compliance') {
        const tiers = (PLACEMENT_TIERS[empId] || []).slice().sort((a,b) => b.threshold - a.threshold);
        for (const tier of tiers) {
            if (!isNaN(ach) && ach >= tier.threshold) { placementAmt = tier.amount; break; }
        }
        cfgAmt = placementAmt;
        const amtInpRef = document.getElementById('iamt_' + empId + '_' + ikey);
        if (amtInpRef) amtInpRef.setAttribute('data-cfg', cfgAmt);
    }

    // ST: pick amount from rsqm/punctuality tiers
    if (PAGE_IS_ST && ST_TIER_KEYS.includes(ikey)) {
        const tiers = ((ST_TIER_GROUPS[empId] || {})[ikey] || []).slice().sort((a,b) => b.threshold - a.threshold);
        let tierAmt = 0;
        for (const tier of tiers) {
            if (!isNaN(ach) && ach >= tier.threshold) { tierAmt = tier.amount; break; }
        }
        cfgAmt = tierAmt;
        const amtInpRef = document.getElementById('iamt_' + empId + '_' + ikey);
        if (amtInpRef) amtInpRef.setAttribute('data-cfg', cfgAmt);
    }

    // CC: pick amount from cc tiers (highest tier met)
    if (PAGE_IS_CC && CC_TIER_KEYS.includes(ikey)) {
        const resolved = resolveCCTierAmount(empId, ikey, ach);
        cfgAmt = resolved !== null ? resolved : 0;
        const amtInpRef = document.getElementById('iamt_' + empId + '_' + ikey);
        if (amtInpRef) amtInpRef.setAttribute('data-cfg', cfgAmt);
    }

    // ACC: pick amount from acc tiers (highest tier met)
    if (PAGE_IS_ACC && ACC_TIER_KEYS.includes(ikey)) {
        const resolved = resolveACCTierAmount(empId, ikey, ach);
        cfgAmt = resolved !== null ? resolved : 0;
        const amtInpRef = document.getElementById('iamt_' + empId + '_' + ikey);
        if (amtInpRef) amtInpRef.setAttribute('data-cfg', cfgAmt);
    }

    const amtInp   = document.getElementById('iamt_' + empId + '_' + ikey);
    const wrap     = document.getElementById('iamt_wrap_' + empId + '_' + ikey);
    const hint     = document.getElementById('iamt_hint_' + empId + '_' + ikey);
    const statusEl = document.getElementById('iach_status_' + empId + '_' + ikey);

    // Determine if threshold met
    let met = false;
    if (PAGE_IS_MR && ikey === 'placement_compliance') {
        met = (!isNaN(ach) && ach > 0 && placementAmt > 0);
    } else if (PAGE_IS_ST && ST_TIER_KEYS.includes(ikey)) {
        met = (!isNaN(ach) && ach > 0 && cfgAmt > 0);
    } else if (PAGE_IS_CC && CC_TIER_KEYS.includes(ikey)) {
        met = (!isNaN(ach) && ach > 0 && cfgAmt > 0);
    } else if (PAGE_IS_ACC && ACC_TIER_KEYS.includes(ikey)) {
        met = (!isNaN(ach) && ach > 0 && cfgAmt > 0);
    } else {
        met = threshold === 0
            ? (!isNaN(ach) && cfgAmt > 0)
            : (!isNaN(ach) && ach > 0 && ach >= threshold && cfgAmt > 0);
    }

    inp.classList.remove('iach-met', 'iach-miss');
    if (!isNaN(ach) && ach > 0) inp.classList.add(met ? 'iach-met' : 'iach-miss');

    if (statusEl) {
        statusEl.classList.remove('status-met','status-miss','status-idle');
        if (!isNaN(ach) && ach > 0) {
            if (met) { statusEl.classList.add('status-met'); statusEl.innerHTML = '<i class="fa-solid fa-circle-check"></i>'; }
            else     { statusEl.classList.add('status-miss'); statusEl.innerHTML = '<i class="fa-solid fa-circle-xmark"></i>'; }
        } else {
            statusEl.classList.add('status-idle');
            statusEl.innerHTML = '<i class="fa-solid fa-circle-minus"></i>';
        }
    }

    if (!amtInp || !wrap) { updateFilledCount(); updateGrandTotals(); return; }

    // Build max label for hint
    let maxLbl = '—';
    if (PAGE_IS_MR && ikey === 'placement_compliance') {
        maxLbl = PLACEMENT_TIERS[empId]?.length
            ? 'up to Rs.' + Math.max(...(PLACEMENT_TIERS[empId]||[]).map(t=>t.amount)).toFixed(2)
            : '—';
    } else if (PAGE_IS_ST && ST_TIER_KEYS.includes(ikey)) {
        const allTiers = (ST_TIER_GROUPS[empId] || {})[ikey] || [];
        maxLbl = allTiers.length
            ? 'up to Rs.' + Math.max(...allTiers.map(t=>t.amount)).toFixed(2)
            : '—';
    } else if (PAGE_IS_CC && CC_TIER_KEYS.includes(ikey)) {
        const ccMax = getCCTierMax(empId, ikey);
        maxLbl = ccMax > 0 ? 'up to Rs.' + ccMax.toFixed(2) : '—';
    } else if (PAGE_IS_ACC && ACC_TIER_KEYS.includes(ikey)) {
        const accMax = getACCTierMax(empId, ikey);
        maxLbl = accMax > 0 ? 'up to Rs.' + accMax.toFixed(2) : '—';
    } else {
        maxLbl = cfgAmt > 0 ? 'Rs.' + cfgAmt.toFixed(2) : '—';
    }

    if (met) {
        amtInp.removeAttribute('readonly');
        amtInp.value = cfgAmt.toFixed(2);
        amtInp.max = cfgAmt;
        amtInp.classList.add('iamt-earned');
        amtInp.classList.remove('iamt-zero','iamt-warn');
        wrap.classList.remove('iamt-locked','iamt-warn');
        wrap.classList.add('iamt-active');
        if (hint) {
            hint.classList.remove('hint-locked','hint-warn');
            hint.classList.add('hint-met');
            const tierTag = (PAGE_IS_CC && CC_TIER_KEYS.includes(ikey)) || (PAGE_IS_ACC && ACC_TIER_KEYS.includes(ikey)) ? ' (tier)' : '';
            hint.innerHTML = '<i class="fa-solid fa-circle-check"></i> Rs.' + cfgAmt.toFixed(2) + tierTag;
        }
    } else {
        amtInp.setAttribute('readonly','readonly');
        amtInp.value = '';
        amtInp.classList.remove('iamt-earned','iamt-warn');
        amtInp.classList.add('iamt-zero');
        wrap.classList.remove('iamt-active','iamt-warn');
        wrap.classList.add('iamt-locked');
        if (hint) {
            hint.classList.remove('hint-met','hint-warn');
            hint.classList.add('hint-locked');
            hint.innerHTML = '<i class="fa-solid fa-lock"></i> ' + maxLbl;
        }
    }

    updateFilledCount(); updateGrandTotals();
}

function onIncentAmtInput(inp) {
    const empId   = inp.getAttribute('data-empid');
    const ikey    = inp.getAttribute('data-ikey');
    const cfgAmt  = parseFloat(inp.getAttribute('data-cfg') || 0);
    const entered = parseFloat(inp.value);
    const wrap    = document.getElementById('iamt_wrap_' + empId + '_' + ikey);
    const hint    = document.getElementById('iamt_hint_' + empId + '_' + ikey);

    if (inp.hasAttribute('readonly')) { inp.value = ''; return; }

    if (isNaN(entered) || entered <= 0) {
        inp.classList.remove('iamt-earned','iamt-warn'); inp.classList.add('iamt-zero');
        if (wrap) { wrap.classList.remove('iamt-warn'); wrap.classList.add('iamt-active'); }
        if (hint && cfgAmt > 0) {
            hint.classList.remove('hint-warn'); hint.classList.add('hint-met');
            hint.innerHTML = '<i class="fa-solid fa-circle-check"></i> max Rs.' + cfgAmt.toFixed(2);
        }
    } else if (cfgAmt > 0 && entered > cfgAmt) {
        inp.classList.add('iamt-warn'); inp.classList.remove('iamt-earned','iamt-zero');
        if (wrap) { wrap.classList.add('iamt-warn'); wrap.classList.remove('iamt-active'); }
        if (hint) { hint.classList.add('hint-warn'); hint.classList.remove('hint-met'); hint.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Exceeds max!'; }
        clearTimeout(inp._clampTimer);
        inp._clampTimer = setTimeout(() => {
            inp.value = cfgAmt.toFixed(2);
            inp.classList.remove('iamt-warn'); inp.classList.add('iamt-earned');
            if (wrap) { wrap.classList.remove('iamt-warn'); wrap.classList.add('iamt-active'); }
            if (hint) { hint.classList.remove('hint-warn'); hint.classList.add('hint-met'); hint.innerHTML = '<i class="fa-solid fa-circle-check"></i> max Rs.' + cfgAmt.toFixed(2); }
            updateGrandTotals();
        }, 900);
    } else {
        inp.classList.add('iamt-earned'); inp.classList.remove('iamt-zero','iamt-warn');
        if (wrap) { wrap.classList.remove('iamt-warn'); wrap.classList.add('iamt-active'); }
        if (hint && cfgAmt > 0) {
            hint.classList.remove('hint-warn'); hint.classList.add('hint-met');
            hint.innerHTML = '<i class="fa-solid fa-circle-check"></i> max Rs.' + cfgAmt.toFixed(2);
        }
    }
    updateFilledCount(); updateGrandTotals();
}

// Determine whether a given reimbursement input should stay fixed
// (i.e. always equal to its configured value, never scaled by attendance
// rate). This checks the input's own data-field/data-fixed attributes
// first (set server-side), falling back to the FIXED_REIMB_FIELDS list.
function isFixedReimbInput(inp) {
    if (inp.getAttribute('data-fixed') === '1') return true;
    const field = inp.getAttribute('data-field') || '';
    return FIXED_REIMB_FIELDS.includes(field);
}

// Whether this reimbursement input already has a saved DB entry for the
// current employee/period (regardless of whether the saved value is 0).
// Auto-fill-on-load logic must NEVER touch such an input — the whole point
// is that once you've explicitly saved a reimbursement value (including a
// deliberate 0.00), it should load back exactly as saved.
function hasSavedReimbValue(inp) {
    return inp.getAttribute('data-has-saved') === '1';
}

function autoFillAllReimbursements() {
    // NOTE: this is an explicit, user-triggered action (the "Auto-fill
    // Reimbursements" button). It intentionally overwrites every visible
    // reimbursement input — including ones with a previously saved value —
    // because the whole point of the button is to recompute/overwrite in
    // bulk. This is different from the passive page-load behaviour below,
    // which must never clobber a saved value.
    document.querySelectorAll('.ie-tr[data-emp-id]').forEach(row => {
        if (row.classList.contains('ie-row-hidden')) return;
        const attRate  = parseFloat(row.getAttribute('data-att-rate') || 0);
        const normDays = parseInt(row.getAttribute('data-norm-days') || 0);
        row.querySelectorAll('.ie-reimb-input').forEach(inp => {
            const cfg = parseFloat(inp.getAttribute('data-cfg') || 0);
            if (cfg > 0) {
                if (SCHEMA_REIMB_DIRECT || isFixedReimbInput(inp)) {
                    // Fixed reimbursements (e.g. Mobile) always use the cfg
                    // value as-is, regardless of attendance rate.
                    inp.value = cfg.toFixed(2);
                } else if (normDays > 0) {
                    const calc = parseFloat(((attRate / 100) * cfg).toFixed(2));
                    inp.value = calc > 0 ? calc.toFixed(2) : '0.00';
                } else {
                    if (inp.value === '' || parseFloat(inp.value) === 0) inp.value = cfg.toFixed(2);
                }
            }
        });
    });
    updateFilledCount(); updateGrandTotals();
}

function getAllInputs() {
    const rows = [...document.querySelectorAll('.ie-tr')].filter(r => !r.classList.contains('ie-row-hidden'));
    const all  = [];
    rows.forEach(row => {
        const inps = [...row.querySelectorAll('.ie-focusable:not([readonly]):not([disabled])')];
        inps.sort((a, b) => parseInt(a.dataset.col || 0) - parseInt(b.dataset.col || 0));
        inps.forEach(i => all.push(i));
    });
    return all;
}

function handleKeyNav(e, inp) {
    if (inp.hasAttribute('readonly') || inp.hasAttribute('disabled')) return;
    if (e.key === 'Enter' || e.key === 'ArrowDown') {
        e.preventDefault();
        const colVal  = inp.getAttribute('data-col');
        const rowEl   = inp.closest('tr');
        const allRows = [...document.querySelectorAll('.ie-tr[data-emp-id]')].filter(r => !r.classList.contains('ie-row-hidden'));
        const rowIdx  = allRows.indexOf(rowEl);
        for (let i = rowIdx + 1; i < allRows.length; i++) {
            const c = allRows[i].querySelector(`.ie-focusable[data-col="${colVal}"]:not([readonly]):not([disabled])`);
            if (c) { c.focus(); c.select(); return; }
        }
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        const colVal  = inp.getAttribute('data-col');
        const rowEl   = inp.closest('tr');
        const allRows = [...document.querySelectorAll('.ie-tr[data-emp-id]')].filter(r => !r.classList.contains('ie-row-hidden'));
        const rowIdx  = allRows.indexOf(rowEl);
        for (let i = rowIdx - 1; i >= 0; i--) {
            const c = allRows[i].querySelector(`.ie-focusable[data-col="${colVal}"]:not([readonly]):not([disabled])`);
            if (c) { c.focus(); c.select(); return; }
        }
    }
}

document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        const form = document.getElementById('entryForm');
        if (form) form.submit();
    }
});

function highlightRow(empId) {
    document.querySelectorAll('.ie-tr').forEach(r => r.classList.remove('ie-tr-active'));
    const row = document.getElementById('empRow_' + empId);
    if (row) row.classList.add('ie-tr-active');
}

function updateFilledCount() {
    let filled = 0;
    document.querySelectorAll('.ie-focusable:not([disabled])').forEach(inp => {
        if (parseFloat(inp.value || 0) > 0) filled++;
    });
    const el = document.getElementById('filledCount');
    if (el) el.textContent = filled;
    const sc = document.getElementById('saveCountText');
    if (sc) sc.textContent = filled + ' values entered';

    document.querySelectorAll('.ie-tr[data-emp-id]').forEach(row => {
        const hasAny = [...row.querySelectorAll('.ie-focusable')]
            .some(i => parseFloat(i.value || 0) > 0);
        row.classList.toggle('ie-tr-filled', hasAny);
    });
    updateGrandTotals();
}

function updateGrandTotals() {
    const vis = inp => !inp.closest('.ie-tr')?.classList.contains('ie-row-hidden');

    if (IS_DISCRETIONARY) {
        let t = 0;
        document.querySelectorAll('.ie-disc-input').forEach(inp => { if (vis(inp)) t += parseFloat(inp.value || 0); });
        const el = document.getElementById('gt_disc');
        if (el) el.textContent = t > 0 ? 'Rs. ' + t.toFixed(2) : '—';
    }

    if (SCHEMA_HAS_SSV) {
        let ssvT = 0;
        document.querySelectorAll('.ie-ssv-result-input').forEach(inp => { if (vis(inp)) ssvT += parseFloat(inp.value || 0); });
        const ssvEl = document.getElementById('gt_ssv');
        if (ssvEl) ssvEl.textContent = ssvT > 0 ? 'Rs. ' + ssvT.toFixed(2) : '—';
    }

    if (SCHEMA_HAS_OTHER_INCENTIVE) {
        let otherT = 0;
        document.querySelectorAll('.ie-other-input').forEach(inp => { if (vis(inp)) otherT += parseFloat(inp.value || 0); });
        const otherEl = document.getElementById('gt_other_incentive');
        if (otherEl) otherEl.textContent = otherT > 0 ? 'Rs. ' + otherT.toFixed(2) : '—';
    }

    INCENT_DEFS.forEach(def => {
        let t = 0;
        document.querySelectorAll('.ie-iamt-input[data-ikey="' + def.key + '"]').forEach(inp => {
            if (vis(inp)) t += parseFloat(inp.value || 0);
        });
        const el = document.getElementById('gt_incent_' + def.key);
        if (el) el.textContent = t > 0 ? 'Rs. ' + t.toFixed(2) : '—';
    });

    if (!SCHEMA_HIDE_ATTRATE) {
        const attRates = [];
        document.querySelectorAll('.ie-tr[data-emp-id]').forEach(row => {
            if (!row.classList.contains('ie-row-hidden')) {
                attRates.push(parseFloat(row.getAttribute('data-att-rate') || 0));
            }
        });
        const attEl = document.getElementById('gt_attrate');
        if (attEl) {
            if (attRates.length > 0) {
                const avg = attRates.reduce((a,b)=>a+b,0) / attRates.length;
                attEl.textContent = 'Avg ' + avg.toFixed(1) + '%';
            } else { attEl.textContent = '—'; }
        }
    }

    REIMB_FIELD_IDS.forEach(rf => {
        let t = 0;
        document.querySelectorAll('.ie-reimb-input').forEach(inp => {
            if ((inp.getAttribute('name') || '').includes(rf) && vis(inp)) t += parseFloat(inp.value || 0);
        });
        const el = document.getElementById('gt_reimb_' + rf);
        if (el) el.textContent = t > 0 ? 'Rs. ' + t.toFixed(2) : '—';
    });
}

function clearAllRates() {
    if (!confirm('Clear all entered values on this page?')) return;
    document.querySelectorAll('.ie-focusable:not([disabled])').forEach(inp => inp.value = '');
    document.querySelectorAll('.ie-ssv-result-input').forEach(inp => { inp.value = ''; inp.classList.remove('ssv-has-value'); });
    document.querySelectorAll('.ie-ssv-matched-label').forEach(el => { el.textContent=''; el.classList.remove('show'); });
    document.querySelectorAll('.ie-iamt-input').forEach(inp => {
        inp.value = '';
        inp.setAttribute('readonly','readonly');
        inp.classList.remove('iamt-earned','iamt-warn');
        inp.classList.add('iamt-zero');
    });
    document.querySelectorAll('.ie-iamt-wrap').forEach(w => {
        w.classList.remove('iamt-warn','iamt-active');
        w.classList.add('iamt-locked');
    });
    document.querySelectorAll('.ie-iamt-hint').forEach(h => {
        h.classList.remove('hint-warn','hint-met');
        h.classList.add('hint-locked');
    });
    document.querySelectorAll('.ie-iach-input').forEach(inp => {
        inp.value=''; inp.classList.remove('iach-met','iach-miss');
    });
    document.querySelectorAll('.ie-iach-status').forEach(el => {
        el.classList.remove('status-met','status-miss'); el.classList.add('status-idle');
        el.innerHTML = '<i class="fa-solid fa-circle-minus"></i>';
    });
    document.querySelectorAll('.ie-tr').forEach(r => r.classList.remove('ie-tr-filled','ie-tr-active'));
    updateGrandTotals(); updateFilledCount();
}

function filterEmployees(q) {
    q = q.trim().toLowerCase();
    document.getElementById('filterClear').style.display = q ? 'flex' : 'none';
    document.querySelectorAll('.ie-tr').forEach(row => {
        const s = row.getAttribute('data-search') || '';
        row.classList.toggle('ie-row-hidden', q !== '' && !s.includes(q));
    });
    updateGrandTotals();
}

function clearFilter() {
    const inp = document.getElementById('empFilterInput');
    inp.value=''; filterEmployees(''); inp.focus();
}

function openHistory(empId, empName) {
    document.getElementById('histModalSub').textContent = empName;
    document.getElementById('histModal').style.display = 'flex';
    const body = document.getElementById('histModalBody');
    body.innerHTML = '<div class="ie-modal-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    fetch(`incentive_entry_history.php?type_id=${INCENTIVE_TYPE_ID}&emp_id=${empId}`)
        .then(r => r.json())
        .then(data => {
            if (!data || data.length === 0) {
                body.innerHTML = '<p style="text-align:center;color:#aaa;padding:40px;font-size:13px;"><i class="fa-solid fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;color:#e5e7eb;"></i>No history found.</p>';
                return;
            }
            const periods = {};
            data.forEach(row => {
                const pkey = row.year + '-' + String(row.month).padStart(2,'0');
                if (!periods[pkey]) periods[pkey] = { year:row.year, month:row.month, disc:null, sec_ach:null, ssv:null, other:null, iach:{}, incent:{}, reimb:{} };
                const lbl = row.component_label;
                if (lbl === '__disc_amount')    { periods[pkey].disc    = row; return; }
                if (lbl === '__sec_achievement'){ periods[pkey].sec_ach = row; return; }
                if (lbl === '__ssv_amount')     { periods[pkey].ssv     = row; return; }
                if (lbl === '__other_incentive'){ periods[pkey].other   = row; return; }
                if (lbl.startsWith('__iach__'))  { periods[pkey].iach[lbl.replace('__iach__','')]   = row; return; }
                if (lbl.startsWith('__incent__')){ periods[pkey].incent[lbl.replace('__incent__','')] = row; return; }
                if (lbl.startsWith('__reimb__')) { periods[pkey].reimb[lbl.replace('__reimb__','')]  = row; return; }
            });

            const iLabels = {};
            INCENT_DEFS.forEach(d => { iLabels[d.key] = d.label; });
            const rLabels = { meal_reimbursement:'Meal Reimb.', traveling_reimbursement:'Travel Reimb.', mobile_reimbursement:'Mobile Reimb.' };

            let html = '';
            Object.keys(periods).sort().reverse().forEach(pkey => {
                const g = periods[pkey];
                const mname = MONTH_NAMES[parseInt(g.month)] || g.month;
                html += `<div style="margin-bottom:18px;">
                    <div style="font-size:13px;font-weight:700;color:#111;margin-bottom:6px;">${mname} ${g.year}</div>
                    <table class="ie-hist-table"><thead><tr>
                        <th>Component</th><th style="text-align:right;">Value</th><th>Updated</th>
                    </tr></thead><tbody>`;
                if (g.disc)    html += `<tr><td><span class="ie-hist-badge" style="background:#cffafe;color:#0e7490;"><i class="fa-solid fa-hand-holding-heart"></i> Discretionary</span></td><td style="text-align:right;font-family:var(--ie-mono);font-weight:700;color:#0891b2;">Rs. ${escHtml(parseFloat(g.disc.amount||0).toFixed(2))}</td><td style="color:#9ca3af;font-size:11px;">${escHtml(g.disc.updated_at||'—')}</td></tr>`;
                if (g.sec_ach) html += `<tr><td><span class="ie-hist-badge" style="background:#ede9fe;color:#5b21b6;"><i class="fa-solid fa-percent"></i> Secondary Ach.</span></td><td style="text-align:right;font-family:var(--ie-mono);font-weight:700;color:#7c3aed;">${escHtml(parseFloat(g.sec_ach.amount||0).toFixed(2))}%</td><td style="color:#9ca3af;font-size:11px;">${escHtml(g.sec_ach.updated_at||'—')}</td></tr>`;
                if (g.ssv)     html += `<tr><td><span class="ie-hist-badge" style="background:#dbeafe;color:#1e40af;"><i class="fa-solid fa-chart-line"></i> SSV Amount</span></td><td style="text-align:right;font-family:var(--ie-mono);font-weight:700;color:#1d4ed8;">Rs. ${escHtml(parseFloat(g.ssv.amount||0).toFixed(2))}</td><td style="color:#9ca3af;font-size:11px;">${escHtml(g.ssv.updated_at||'—')}</td></tr>`;
                if (g.other)   html += `<tr><td><span class="ie-hist-badge" style="background:#fce7f3;color:#9d174d;"><i class="fa-solid fa-gift"></i> Other Incentive</span></td><td style="text-align:right;font-family:var(--ie-mono);font-weight:700;color:#be185d;">Rs. ${escHtml(parseFloat(g.other.amount||0).toFixed(2))}</td><td style="color:#9ca3af;font-size:11px;">${escHtml(g.other.updated_at||'—')}</td></tr>`;
                INCENT_DEFS.forEach(def => {
                    const ia = g.iach[def.key];
                    const iv = g.incent[def.key];
                    if (ia) html += `<tr><td><span class="ie-hist-badge" style="background:#fef3c7;color:#92400e;"><i class="fa-solid fa-percent"></i> ${escHtml(iLabels[def.key]||def.key)} Ach.</span></td><td style="text-align:right;font-family:var(--ie-mono);font-weight:700;color:#d97706;">${escHtml(parseFloat(ia.amount||0).toFixed(2))}%</td><td style="color:#9ca3af;font-size:11px;">${escHtml(ia.updated_at||'—')}</td></tr>`;
                    if (iv) html += `<tr><td><span class="ie-hist-badge" style="background:#d1fae5;color:#065f46;"><i class="fa-solid fa-coins"></i> ${escHtml(iLabels[def.key]||def.key)} Amt.</span></td><td style="text-align:right;font-family:var(--ie-mono);font-weight:700;color:#059669;">Rs. ${escHtml(parseFloat(iv.amount||0).toFixed(2))}</td><td style="color:#9ca3af;font-size:11px;">${escHtml(iv.updated_at||'—')}</td></tr>`;
                });
                Object.entries(g.reimb).forEach(([key, row]) => {
                    html += `<tr><td><span class="ie-hist-badge" style="background:#ffedd5;color:#9a3412;">${escHtml(rLabels[key]||key)}</span></td><td style="text-align:right;font-family:var(--ie-mono);font-weight:700;color:#c2410c;">Rs. ${escHtml(parseFloat(row.amount||0).toFixed(2))}</td><td style="color:#9ca3af;font-size:11px;">${escHtml(row.updated_at||'—')}</td></tr>`;
                });
                html += `</tbody></table></div>`;
            });
            body.innerHTML = html;
        })
        .catch(() => { body.innerHTML = '<p style="color:#dc2626;padding:16px;font-size:13px;">Failed to load history.</p>'; });
}

function closeHistory() { document.getElementById('histModal').style.display = 'none'; }
function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.ie-iach-input').forEach(inp => {
        if (inp.value && parseFloat(inp.value) > 0) onIncentAchInput(inp);
    });

    document.querySelectorAll('.ie-tr[data-emp-id]').forEach(row => {
        const attRate  = parseFloat(row.getAttribute('data-att-rate') || 0);
        const normDays = parseInt(row.getAttribute('data-norm-days') || 0);
        row.querySelectorAll('.ie-reimb-input').forEach(inp => {
            // If this employee/field/period already has a saved reimbursement
            // entry — including a deliberately-saved 0.00 — never touch it on
            // load. Only inputs with no saved DB row at all get auto-computed.
            if (hasSavedReimbValue(inp)) return;
            if (inp.value === '' || parseFloat(inp.value) === 0) {
                const cfg = parseFloat(inp.getAttribute('data-cfg') || 0);
                if (cfg > 0) {
                    if (SCHEMA_REIMB_DIRECT || isFixedReimbInput(inp)) {
                        // Fixed reimbursements (e.g. Mobile) always use the
                        // cfg value as-is, never scaled by attendance rate.
                        inp.value = cfg.toFixed(2);
                    } else if (normDays > 0) {
                        const calc = parseFloat(((attRate / 100) * cfg).toFixed(2));
                        if (calc > 0) inp.value = calc.toFixed(2);
                    } else {
                        inp.value = cfg.toFixed(2);
                    }
                }
            }
        });
    });

    if (SCHEMA_HAS_SSV) {
        // IMPORTANT: use initSSVMatchLabel() here (not onSecAchievementInput()).
        // On page load the SSV Amount input already contains whatever was
        // saved to the DB (auto-filled OR manually edited). We must only show
        // the "Rate: X%" hint label — we must NOT recompute/overwrite the
        // amount, otherwise a manually-edited & saved value would be lost
        // every time the page reloads.
        document.querySelectorAll('.ie-sec-ach-input').forEach(inp => {
            if (inp.value && parseFloat(inp.value) > 0) initSSVMatchLabel(inp);
        });
        document.querySelectorAll('.ie-ssv-result-input').forEach(inp => {
            if (inp.value && parseFloat(inp.value) > 0) inp.classList.add('ssv-has-value');
        });
    }

    updateGrandTotals(); updateFilledCount();

    const inputs = getAllInputs();
    if (inputs.length > 0) { inputs[0].focus(); inputs[0].select(); }

    setTimeout(() => {
        const al = document.querySelector('.ie-alert');
        if (al) { al.style.transition = 'opacity .4s'; al.style.opacity='0'; setTimeout(()=>al.remove(),400); }
    }, 5000);
});
</script>

<?php include 'footer.php'; ?>