<?php
ob_start();
include 'config.php';

$create_tables = [
    "CREATE TABLE IF NOT EXISTS sr_salary_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        designation_id INT NOT NULL,
        basic_salary            DECIMAL(12,2) NULL,
        discretionary_support   DECIMAL(12,2) NULL,
        insurance_amount        DECIMAL(12,2) NULL,
        welfare_amount          DECIMAL(12,2) NULL,
        eco_incentive           DECIMAL(12,2) NULL,
        bp_incentive            DECIMAL(12,2) NULL,
        total_assortment        DECIMAL(12,2) NULL,
        total_ps_compliance     DECIMAL(12,2) NULL,
        placement_compliance_70 DECIMAL(12,2) NULL,
        placement_compliance_80 DECIMAL(12,2) NULL,
        placement_compliance_90 DECIMAL(12,2) NULL,
        attendance_70           DECIMAL(12,2) NULL,
        attendance_80           DECIMAL(12,2) NULL,
        attendance_90           DECIMAL(12,2) NULL,
        ccfot_90  DECIMAL(12,2) NULL,
        ccfot_91  DECIMAL(12,2) NULL,
        ccfot_92  DECIMAL(12,2) NULL,
        ccfot_93  DECIMAL(12,2) NULL,
        ccfot_94  DECIMAL(12,2) NULL,
        ccfot_95  DECIMAL(12,2) NULL,
        credit_mgt_85       DECIMAL(12,2) NULL,
        credit_mgt_90       DECIMAL(12,2) NULL,
        credit_mgt_95       DECIMAL(12,2) NULL,
        payee_chq_80        DECIMAL(12,2) NULL,
        ssv_90              DECIMAL(12,2) NULL,
        ssv_95              DECIMAL(12,2) NULL,
        attendance_85       DECIMAL(12,2) NULL,
        locus_admin         DECIMAL(12,2) NULL,
        punctuality_70      DECIMAL(12,2) NULL,
        punctuality_75      DECIMAL(12,2) NULL,
        punctuality_80      DECIMAL(12,2) NULL,
        punctuality_85      DECIMAL(12,2) NULL,
        stores_damage_lo    DECIMAL(12,2) NULL,
        stores_damage_hi    DECIMAL(12,2) NULL,
        rsqm_60             DECIMAL(12,2) NULL,
        rsqm_70             DECIMAL(12,2) NULL,
        loading_unloading   DECIMAL(12,2) NULL,
        ccfot_st            DECIMAL(12,2) NULL,
        incentive_daily     DECIMAL(12,2) NULL,
        incentive_weekly    DECIMAL(12,2) NULL,
        incentive_monthly   DECIMAL(12,2) NULL,
        incentive_daily_90   DECIMAL(12,2) NULL,
        incentive_weekly_90  DECIMAL(12,2) NULL,
        incentive_monthly_90 DECIMAL(12,2) NULL,
        meal_reimbursement      DECIMAL(12,2) NULL,
        traveling_reimbursement DECIMAL(12,2) NULL,
        mobile_reimbursement    DECIMAL(12,2) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_designation (designation_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS sr_secondary_sales_rates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        designation_id INT NOT NULL,
        rate_label VARCHAR(20) NOT NULL,
        amount DECIMAL(12,2) NULL,
        sort_order INT DEFAULT 0,
        UNIQUE KEY uq_desig_rate (designation_id, rate_label)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS sr_incentive_rates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        designation_id INT NOT NULL,
        incent_key VARCHAR(60) NOT NULL,
        rate_value VARCHAR(20) NOT NULL DEFAULT '100%',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_desig_incent (designation_id, incent_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($create_tables as $sql) mysqli_query($conn, $sql);

$add_cols = [
    "insurance_amount","welfare_amount",
    "placement_compliance_70","placement_compliance_80","placement_compliance_90",
    "attendance_70","attendance_80","attendance_90",
    "ccfot_90","ccfot_91","ccfot_92","ccfot_93","ccfot_94","ccfot_95",
    "credit_mgt_85","credit_mgt_90","credit_mgt_95",
    "payee_chq_80","ssv_90","ssv_95","attendance_85","locus_admin",
    "punctuality_70","punctuality_75","punctuality_80","punctuality_85",
    "stores_damage_lo","stores_damage_hi","rsqm_60","rsqm_70",
    "loading_unloading","ccfot_st",
    "incentive_daily","incentive_weekly","incentive_monthly",
    "incentive_daily_90","incentive_weekly_90","incentive_monthly_90",
];
foreach ($add_cols as $col) {
    mysqli_query($conn, "ALTER TABLE sr_salary_config ADD COLUMN IF NOT EXISTS `$col` DECIMAL(12,2) NULL");
}

// ── One-time migration: copy old incentive_daily/weekly/monthly → _90 columns ──
// Runs on every page load but is a no-op once all rows are migrated (WHERE IS NULL guard).
mysqli_query($conn, "UPDATE sr_salary_config
    SET incentive_daily_90   = incentive_daily
    WHERE incentive_daily_90 IS NULL AND incentive_daily IS NOT NULL");
mysqli_query($conn, "UPDATE sr_salary_config
    SET incentive_weekly_90  = incentive_weekly
    WHERE incentive_weekly_90 IS NULL AND incentive_weekly IS NOT NULL");
mysqli_query($conn, "UPDATE sr_salary_config
    SET incentive_monthly_90 = incentive_monthly
    WHERE incentive_monthly_90 IS NULL AND incentive_monthly IS NOT NULL");

$alter_stmts = [
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS insurance_amount         DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS welfare_amount           DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS reimbursement_mobile     DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS attendance_allowance     DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS reimbursement_meal       DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS reimbursement_traveling  DECIMAL(12,2) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS reimbursement_other      DECIMAL(12,2) NULL",
];
foreach ($alter_stmts as $sql) mysqli_query($conn, $sql);

$category_schemas = [
    'Sales Representative' => [
        'code'  => 'SR',
        'color' => 'blue',
        'fixed' => [
            ['basic_salary',          'Basic Salary'],
            ['discretionary_support', 'Disc. Support'],
            ['insurance_amount',      'Insurance'],
            ['welfare_amount',        'Welfare'],
        ],
        'incentive_groups' => [
            ['label'=>'PS Compliance','color'=>'green','incent_key'=>'total_ps_compliance','cols'=>[
                ['total_ps_compliance','PS Compliance'],
            ]],
            ['label'=>'ECO','color'=>'green','incent_key'=>'eco_incentive','cols'=>[
                ['eco_incentive','ECO'],
            ]],
            ['label'=>'BP','color'=>'green','incent_key'=>'bp_incentive','cols'=>[
                ['bp_incentive','BP'],
            ]],
            ['label'=>'Assortment','color'=>'green','incent_key'=>'total_assortment','cols'=>[
                ['total_assortment','Assortment'],
            ]],
        ],
        'has_incent_rate_headers' => true,
        'ssv_rates' => ['90%','95%','96%','97%','98%','99%','100%','103%'],
        'ssv_position' => 'after_fixed',
        'reimb'     => [
            ['meal_reimbursement','Meal'],
            ['traveling_reimbursement','Traveling'],
            ['mobile_reimbursement','Mobile'],
        ],
        'has_rank' => true,
        'fields'   => ['basic_salary','discretionary_support','insurance_amount','welfare_amount','total_ps_compliance','eco_incentive','bp_incentive','total_assortment','meal_reimbursement','traveling_reimbursement','mobile_reimbursement'],
    ],
    'Merchandiser Representative' => [
        'code'  => 'MR',
        'color' => 'purple',
        'fixed' => [
            ['basic_salary',     'Basic Salary'],
            ['insurance_amount', 'Insurance'],
            ['welfare_amount',   'Welfare'],
        ],
        'incentive_groups' => [
            ['label'=>'Placement Compliance','color'=>'indigo','incent_key'=>null,'cols'=>[
                ['placement_compliance_70','70%'],
                ['placement_compliance_80','80%'],
                ['placement_compliance_90','90%'],
            ]],
            ['label'=>'Attendance','color'=>'teal','incent_key'=>null,'cols'=>[
                ['attendance_70','70%'],
                ['attendance_80','80%'],
                ['attendance_90','90%'],
            ]],
        ],
        'has_incent_rate_headers' => false,
        'ssv_rates'    => [],
        'ssv_position' => 'after_fixed',
        'reimb'        => [
            ['meal_reimbursement','Meal'],
            ['traveling_reimbursement','Traveling'],
        ],
        'has_rank' => false,
        'fields'   => ['basic_salary','insurance_amount','welfare_amount','placement_compliance_70','placement_compliance_80','placement_compliance_90','attendance_70','attendance_80','attendance_90','meal_reimbursement','traveling_reimbursement'],
    ],
    'Cash Collector' => [
        'code'  => 'CC',
        'color' => 'amber',
        'fixed' => [
            ['basic_salary',     'Basic Salary'],
            ['insurance_amount', 'Insurance'],
            ['welfare_amount',   'Welfare'],
        ],
        'incentive_groups' => [
            ['label'=>'CCFOT','color'=>'blue','incent_key'=>null,'cols'=>[
                ['ccfot_90','90%'],['ccfot_91','91%'],['ccfot_92','92%'],
                ['ccfot_93','93%'],['ccfot_94','94%'],['ccfot_95','95%'],
            ]],
            ['label'=>'Credit Mgt','color'=>'green','incent_key'=>null,'cols'=>[
                ['credit_mgt_85','85%'],['credit_mgt_90','90%'],['credit_mgt_95','95%'],
            ]],
            ['label'=>'Payee Chq','color'=>'orange','incent_key'=>null,'cols'=>[
                ['payee_chq_80','80%'],
            ]],
            ['label'=>'SSV','color'=>'cyan','incent_key'=>null,'cols'=>[
                ['ssv_90','90%'],['ssv_95','95%'],
            ]],
            ['label'=>'Attendance','color'=>'teal','incent_key'=>null,'cols'=>[
                ['attendance_85','85%'],['attendance_90','90%'],
            ]],
           // AFTER
['label'=>'Locus Admin','color'=>'slate','incent_key'=>null,'cols'=>[
    ['locus_admin','100%'],   // ✅ now shows 100% rate label
]],
        ],
        'has_incent_rate_headers' => false,
        'ssv_rates'    => [],
        'ssv_position' => 'after_fixed',
        'reimb'        => [['meal_reimbursement','Meal'],['mobile_reimbursement','Mobile']],
        'has_rank'     => true,
        'fields'       => ['basic_salary','insurance_amount','welfare_amount','ccfot_90','ccfot_91','ccfot_92','ccfot_93','ccfot_94','ccfot_95','credit_mgt_85','credit_mgt_90','credit_mgt_95','payee_chq_80','ssv_90','ssv_95','attendance_85','attendance_90','locus_admin','meal_reimbursement','mobile_reimbursement'],
    ],

    'Assistant Cash Collector' => [
        'code'  => 'ACC',
        'color' => 'orange',
        'fixed' => [
            ['basic_salary',     'Basic Salary'],
            ['insurance_amount', 'Insurance'],
            ['welfare_amount',   'Welfare'],
        ],
        'incentive_groups' => [
            [
                'label'      => 'CCFOT',
                'color'      => 'blue',
                'incent_key' => null,
                'cols'       => [
                    ['ccfot_90', '90%'],
                    ['ccfot_91', '91%'],
                    ['ccfot_92', '92%'],
                    ['ccfot_93', '93%'],
                    ['ccfot_94', '94%'],
                    ['ccfot_95', '95%'],
                ],
            ],
            [
                'label'      => 'Punctuality',
                'color'      => 'violet',
                'incent_key' => null,
                'cols'       => [
                    ['punctuality_70', '70%'],
                    ['punctuality_80', '80%'],
                ],
            ],
            [
                'label'      => 'Attendance',
                'color'      => 'teal',
                'incent_key' => null,
                'ssv_before' => true,
                'cols'       => [
                    ['attendance_85', '85%'],
                    ['attendance_90', '90%'],
                ],
            ],
        ],
        'has_incent_rate_headers' => false,
        'ssv_rates'    => ['95%'],
        'ssv_position' => 'before_attendance',
        'reimb'        => [
            ['meal_reimbursement', 'Meal'],
        ],
        'has_rank' => true,
        'fields'   => [
            'basic_salary',
            'insurance_amount', 'welfare_amount',
            'ccfot_90', 'ccfot_91', 'ccfot_92', 'ccfot_93', 'ccfot_94', 'ccfot_95',
            'punctuality_70', 'punctuality_80',
            'attendance_85',  'attendance_90',
            'meal_reimbursement',
        ],
    ],

    'Back Office Stores Team' => [
        'code'  => 'ST',
        'color' => 'rose',
        'fixed' => [
            ['basic_salary',     'Basic Salary'],
            ['insurance_amount', 'Insurance'],
            ['welfare_amount',   'Welfare'],
        ],
        'incentive_groups' => [
            ['label'=>'Stores Damage','color'=>'red','incent_key'=>null,'cols'=>[
                ['stores_damage_lo','0.007%'],['stores_damage_hi','0.020%'],
            ]],
            ['label'=>'RSQM Score','color'=>'blue','incent_key'=>null,'cols'=>[
                ['rsqm_60','60%'],['rsqm_70','70%'],
            ]],
            ['label'=>'Punctuality','color'=>'violet','incent_key'=>null,'cols'=>[
                ['punctuality_75','75%'],['punctuality_85','85%'],
            ]],
            ['label'=>'L/Unloading','color'=>'orange','incent_key'=>null,'cols'=>[
                ['loading_unloading','90%'],
            ]],
            ['label'=>'CCFOT','color'=>'green','incent_key'=>null,'cols'=>[
                ['ccfot_st','90%'],
            ]],
            ['label'=>'Attendance','color'=>'teal','incent_key'=>null,'cols'=>[
                ['attendance_85','85%'],['attendance_90','90%'],
            ]],
        ],
        'has_incent_rate_headers' => false,
        'ssv_rates'    => [],
        'ssv_position' => 'after_fixed',
        'reimb'        => [['meal_reimbursement','Meal']],
        'has_rank'     => false,
        'fields'       => ['basic_salary','insurance_amount','welfare_amount','stores_damage_lo','stores_damage_hi','rsqm_60','rsqm_70','punctuality_75','punctuality_85','loading_unloading','ccfot_st','attendance_85','attendance_90','meal_reimbursement'],
    ],

    // ── UPDATED: Back Office Team now has 90% threshold cols for each incentive ──
    'Back Office Team' => [
        'code'  => 'OFF',
        'color' => 'slate',
        'fixed' => [
            ['basic_salary',     'Basic Salary'],
            ['insurance_amount', 'Insurance'],
            ['welfare_amount',   'Welfare'],
        ],
        'incentive_groups' => [
            ['label'=>'Daily Incentive',   'color'=>'blue',   'incent_key'=>null,'cols'=>[
                ['incentive_daily_90',  '90%'],
            ]],
            ['label'=>'Weekly Incentive',  'color'=>'indigo', 'incent_key'=>null,'cols'=>[
                ['incentive_weekly_90', '90%'],
            ]],
            ['label'=>'Monthly Incentive', 'color'=>'violet', 'incent_key'=>null,'cols'=>[
                ['incentive_monthly_90','90%'],
            ]],
            ['label'=>'Punctuality',       'color'=>'amber',  'incent_key'=>null,'cols'=>[
                ['punctuality_75','75%'],
            ]],
            ['label'=>'Attendance',        'color'=>'teal',   'incent_key'=>null,'cols'=>[
                ['attendance_85','85%'],['attendance_90','90%'],
            ]],
        ],
        'has_incent_rate_headers' => false,
        'ssv_rates'    => [],
        'ssv_position' => 'after_fixed',
        'reimb'        => [['meal_reimbursement','Meal']],
        'has_rank'     => false,
        'fields'       => [
            'basic_salary','insurance_amount','welfare_amount',
            'incentive_daily_90','incentive_weekly_90','incentive_monthly_90',
            'punctuality_75','attendance_85','attendance_90',
            'meal_reimbursement',
        ],
    ],
];

$incent_colors = [
    'blue'   => ['bg'=>'#eff6ff','border'=>'#c7d9f8','text'=>'#1d4ed8','hdr_bg'=>'#f0f7ff','hdr_text'=>'#1d4ed8','hdr_border'=>'#c7d9f8'],
    'green'  => ['bg'=>'#f0fdf4','border'=>'#bbf7d0','text'=>'#15803d','hdr_bg'=>'#f5fff5','hdr_text'=>'#15803d','hdr_border'=>'#bbf7d0'],
    'teal'   => ['bg'=>'#f0fdfa','border'=>'#99f6e4','text'=>'#0f766e','hdr_bg'=>'#f0fdfa','hdr_text'=>'#0f766e','hdr_border'=>'#99f6e4'],
    'amber'  => ['bg'=>'#fffbeb','border'=>'#fde68a','text'=>'#b45309','hdr_bg'=>'#fffbeb','hdr_text'=>'#b45309','hdr_border'=>'#fde68a'],
    'orange' => ['bg'=>'#fff7ed','border'=>'#fed7aa','text'=>'#c2410c','hdr_bg'=>'#fff8f0','hdr_text'=>'#c2410c','hdr_border'=>'#fddcba'],
    'red'    => ['bg'=>'#fef2f2','border'=>'#fecaca','text'=>'#dc2626','hdr_bg'=>'#fef2f2','hdr_text'=>'#dc2626','hdr_border'=>'#fecaca'],
    'indigo' => ['bg'=>'#eef2ff','border'=>'#c7d2fe','text'=>'#4338ca','hdr_bg'=>'#eef2ff','hdr_text'=>'#4338ca','hdr_border'=>'#c7d2fe'],
    'violet' => ['bg'=>'#f5f3ff','border'=>'#ddd6fe','text'=>'#7c3aed','hdr_bg'=>'#f5f3ff','hdr_text'=>'#7c3aed','hdr_border'=>'#ddd6fe'],
    'cyan'   => ['bg'=>'#ecfeff','border'=>'#a5f3fc','text'=>'#0891b2','hdr_bg'=>'#ecfeff','hdr_text'=>'#0891b2','hdr_border'=>'#a5f3fc'],
    'rose'   => ['bg'=>'#fff1f2','border'=>'#fecdd3','text'=>'#e11d48','hdr_bg'=>'#fff1f2','hdr_text'=>'#e11d48','hdr_border'=>'#fecdd3'],
    'slate'  => ['bg'=>'#f8fafc','border'=>'#cbd5e1','text'=>'#475569','hdr_bg'=>'#f8fafc','hdr_text'=>'#475569','hdr_border'=>'#cbd5e1'],
    'purple' => ['bg'=>'#faf5ff','border'=>'#e9d5ff','text'=>'#7e22ce','hdr_bg'=>'#faf5ff','hdr_text'=>'#7e22ce','hdr_border'=>'#e9d5ff'],
];

$incent_defaults = [
    'total_ps_compliance' => '80%',
    'eco_incentive'       => '95%',
    'bp_incentive'        => '90%',
    'total_assortment'    => '100%',
];

function getAllDbFields(array $schemas): array {
    $fields = ['basic_salary','discretionary_support','insurance_amount','welfare_amount'];
    foreach ($schemas as $schema) {
        foreach ($schema['fixed'] as $f) $fields[] = $f[0];
        foreach ($schema['incentive_groups'] as $grp) {
            foreach ($grp['cols'] as $c) $fields[] = $c[0];
        }
        foreach ($schema['reimb'] as $r) $fields[] = $r[0];
    }
    return array_unique($fields);
}
$all_db_fields = getAllDbFields($category_schemas);

// ═══════════════════════════════════════════════════════════════════════════
// AJAX HANDLERS
// ═══════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_config') {
    // Discard any stray output (notices, warnings) before the JSON response
    ob_clean();
    header('Content-Type: application/json');

    $designation_id = intval($_POST['designation_id'] ?? 0);
    if (!$designation_id) { echo json_encode(['success'=>false,'message'=>'Invalid designation']); exit; }

    $setParts  = [];
    $insFields = ['designation_id'];
    $insVals   = [$designation_id];

    foreach ($all_db_fields as $f) {
        if (!array_key_exists($f, $_POST)) continue;
        $raw = $_POST[$f];
        $val = ($raw !== '') ? floatval($raw) : 'NULL';
        $setParts[]  = "`$f` = $val";
        $insFields[] = "`$f`";
        $insVals[]   = $val;
    }

    if (empty($setParts)) {
        $sql = "INSERT IGNORE INTO sr_salary_config (designation_id) VALUES ($designation_id)";
    } else {
        $sql = "INSERT INTO sr_salary_config (" . implode(',', $insFields) . ") VALUES (" . implode(',', $insVals) . ")
                ON DUPLICATE KEY UPDATE " . implode(',', $setParts);
    }

    if (!mysqli_query($conn, $sql)) {
        echo json_encode(['success'=>false,'message'=>mysqli_error($conn)]); exit;
    }

    // ── Secondary sales rates ──────────────────────────────────────────────
    $rates = $_POST['rates'] ?? [];
    $eq = mysqli_query($conn, "SELECT rate_label FROM sr_secondary_sales_rates WHERE designation_id=$designation_id");
    $existing_labels = [];
    while ($r = mysqli_fetch_assoc($eq)) $existing_labels[] = $r['rate_label'];
    $incoming_labels = array_column($rates, 'label');
    foreach ($existing_labels as $el) {
        if (!in_array($el, $incoming_labels)) {
            $el_e = mysqli_real_escape_string($conn, $el);
            mysqli_query($conn, "DELETE FROM sr_secondary_sales_rates WHERE designation_id=$designation_id AND rate_label='$el_e'");
        }
    }
    foreach ($rates as $idx => $rate) {
        $label  = mysqli_real_escape_string($conn, trim($rate['label'] ?? ''));
        $rawAmt = trim($rate['amount'] ?? '');
        if ($label === '') continue;
        if ($rawAmt === '') {
            mysqli_query($conn, "INSERT IGNORE INTO sr_secondary_sales_rates (designation_id,rate_label,amount,sort_order)
                                 VALUES ($designation_id,'$label',NULL,$idx)");
            mysqli_query($conn, "UPDATE sr_secondary_sales_rates SET sort_order=$idx
                                 WHERE designation_id=$designation_id AND rate_label='$label'");
        } else {
            $amount = floatval($rawAmt);
            mysqli_query($conn, "INSERT INTO sr_secondary_sales_rates (designation_id,rate_label,amount,sort_order)
                                 VALUES ($designation_id,'$label',$amount,$idx)
                                 ON DUPLICATE KEY UPDATE amount=$amount,sort_order=$idx");
        }
    }

    // ── Incentive rates ────────────────────────────────────────────────────
    $incent_rates = $_POST['incent_rates'] ?? [];
    $allowed_incent_keys = ['total_ps_compliance','eco_incentive','bp_incentive','total_assortment'];
    foreach ($incent_rates as $key => $val) {
        if (!in_array($key, $allowed_incent_keys)) continue;
        $ke = mysqli_real_escape_string($conn, $key);
        $ve = mysqli_real_escape_string($conn, trim($val));
        if ($ve === '') $ve = '100%';
        mysqli_query($conn, "INSERT INTO sr_incentive_rates (designation_id,incent_key,rate_value)
                             VALUES ($designation_id,'$ke','$ve')
                             ON DUPLICATE KEY UPDATE rate_value='$ve'");
    }

    // ── Push to employees table ────────────────────────────────────────────
    $push_map = [
        'basic_salary'            => 'basic_salary',
        'insurance_amount'        => 'insurance_amount',
        'welfare_amount'          => 'welfare_amount',
        'meal_reimbursement'      => 'reimbursement_meal',
        'traveling_reimbursement' => 'reimbursement_traveling',
        'mobile_reimbursement'    => 'reimbursement_mobile',
    ];

    $push_parts = [];
    foreach ($push_map as $src => $dst) {
        if (!array_key_exists($src, $_POST)) continue;
        $raw = $_POST[$src];
        if ($raw !== '' && is_numeric($raw)) {
            $push_parts[] = "`$dst` = " . floatval($raw);
        }
    }

    $pushed = 0;
    if (!empty($push_parts)) {
        $pSql = "UPDATE employees SET " . implode(',', $push_parts)
              . " WHERE designation_id = $designation_id";
        if (mysqli_query($conn, $pSql)) {
            $pushed = mysqli_affected_rows($conn);
        } else {
            echo json_encode([
                'success' => true,
                'pushed'  => 0,
                'message' => "Config saved. Employee push error: " . mysqli_error($conn)
            ]);
            exit;
        }
    }
    echo json_encode(['success'=>true,'pushed'=>$pushed,'message'=>"Config saved. $pushed employee(s) updated."]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_incent_rate') {
    ob_clean();
    header('Content-Type: application/json');
    $designation_id = intval($_POST['designation_id'] ?? 0);
    $incent_key     = trim($_POST['incent_key'] ?? '');
    $rate_value     = trim($_POST['rate_value'] ?? '');
    $allowed        = ['total_ps_compliance','eco_incentive','bp_incentive','total_assortment'];
    if (!$designation_id || !in_array($incent_key, $allowed) || $rate_value === '') {
        echo json_encode(['success'=>false,'message'=>'Invalid parameters']); exit;
    }
    $ke = mysqli_real_escape_string($conn, $incent_key);
    $ve = mysqli_real_escape_string($conn, $rate_value);
    $ok = mysqli_query($conn, "INSERT INTO sr_incentive_rates (designation_id,incent_key,rate_value)
                               VALUES ($designation_id,'$ke','$ve')
                               ON DUPLICATE KEY UPDATE rate_value='$ve'");
    echo json_encode(['success' => (bool)$ok, 'message' => $ok ? '' : mysqli_error($conn)]);
    exit;
}

if (isset($_GET['get_config']) && is_numeric($_GET['get_config'])) {
    ob_clean();
    header('Content-Type: application/json');
    $did = intval($_GET['get_config']);
    $cfg = [];
    $r   = mysqli_query($conn, "SELECT * FROM sr_salary_config WHERE designation_id=$did");
    if ($r && ($row = mysqli_fetch_assoc($r))) $cfg = $row;
    $rates = [];
    $rr    = mysqli_query($conn, "SELECT rate_label,amount FROM sr_secondary_sales_rates WHERE designation_id=$did ORDER BY sort_order,id");
    while ($row = mysqli_fetch_assoc($rr)) $rates[] = $row;
    $incent_rates = [];
    $ir = mysqli_query($conn, "SELECT incent_key,rate_value FROM sr_incentive_rates WHERE designation_id=$did");
    while ($row = mysqli_fetch_assoc($ir)) $incent_rates[$row['incent_key']] = $row['rate_value'];
    echo json_encode(['config'=>$cfg,'rates'=>$rates,'incent_rates'=>$incent_rates]);
    exit;
}

// ── Page data ─────────────────────────────────────────────────────────────────
$categories_sql = "SELECT DISTINCT sc.id, sc.category_name, sc.category_code
                   FROM staff_categories sc
                   INNER JOIN designations d ON d.staff_category_id = sc.id AND d.active = 1
                   WHERE sc.active = 1
                   ORDER BY sc.category_name";
$categories_result = mysqli_query($conn, $categories_sql);
$categories = [];
while ($row = mysqli_fetch_assoc($categories_result)) $categories[] = $row;

$field_list = implode(',', array_map(fn($f) => "cfg.`$f`", $all_db_fields));
$designations_sql = "SELECT d.id, d.designation_name, d.designation_code,
                            d.staff_category_id, d.rank_no,
                            sc.category_name, sc.category_code,
                            $field_list,
                            (SELECT COUNT(*) FROM employees e WHERE e.designation_id = d.id) AS emp_count
                     FROM designations d
                     LEFT JOIN staff_categories sc ON d.staff_category_id = sc.id
                     LEFT JOIN sr_salary_config cfg ON cfg.designation_id = d.id
                     WHERE d.active = 1
                     ORDER BY sc.category_name ASC,
                              CASE WHEN d.rank_no IS NULL THEN 1 ELSE 0 END ASC,
                              d.rank_no DESC,
                              d.designation_name ASC";
$des_result   = mysqli_query($conn, $designations_sql);
$designations = [];
while ($row = mysqli_fetch_assoc($des_result)) $designations[] = $row;

$grouped = [];
foreach ($designations as $d) {
    $cat = $d['category_name'] ?? 'Uncategorised';
    $grouped[$cat][] = $d;
}

$incentive_rates_map = [];
$all_desig_ids = array_column($designations, 'id');
if (!empty($all_desig_ids)) {
    $ids_str   = implode(',', array_map('intval', $all_desig_ids));
    $ir_result = mysqli_query($conn, "SELECT designation_id,incent_key,rate_value FROM sr_incentive_rates WHERE designation_id IN ($ids_str)");
    while ($row = mysqli_fetch_assoc($ir_result)) {
        $incentive_rates_map[$row['designation_id']][$row['incent_key']] = $row['rate_value'];
    }
}

$total_desig        = count($designations);
$configured         = count(array_filter($designations, fn($d) => $d['basic_salary'] !== null));
$total_emp_affected = array_sum(array_column($designations, 'emp_count'));
$categories_count   = count($grouped);

include 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:ital,wght@0,300;0,400;0,500&family=DM+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<title>Salary Configuration</title>
</head>
<style>
:root {
    --bg:#f0efe9;--surface:#fff;--surface2:#fafaf7;--border:#e2e0d8;--border2:#d0cec4;
    --ink:#111110;--ink2:#5c5b55;--ink3:#9a9890;
    --green:#0d6e3d;--green-bg:#e8f5ee;--green-lt:#d1ead8;
    --amber:#b45309;--amber-bg:#fef3c7;
    --blue:#1d4ed8;--blue-bg:#eff6ff;
    --orange:#c2410c;--orange-bg:#fff7ed;--red:#dc2626;
    --mono:'DM Mono',ui-monospace,'Cascadia Code','Source Code Pro',Menlo,Consolas,'DejaVu Sans Mono',monospace;
    --sans:'DM Sans',sans-serif;
    --r:8px;--r-lg:14px;
    --shadow:0 1px 3px rgba(0,0,0,.07),0 4px 14px rgba(0,0,0,.05);
    --shadow-lg:0 8px 32px rgba(0,0,0,.12);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--sans);background:var(--bg);color:var(--ink);min-height:100vh;}
.pg-header{background:var(--ink);padding:26px 32px 22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;border-radius:0 0 16px 16px;margin-bottom:28px;}
.pg-header-left{display:flex;flex-direction:column;gap:4px;}
.pg-title{font-size:21px;font-weight:800;color:#fff;letter-spacing:-.4px;display:flex;align-items:center;gap:10px;}
.pg-subtitle{font-size:12px;color:#888884;}
.pg-header-right{display:flex;gap:10px;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:var(--r);font-size:13px;font-weight:600;font-family:var(--sans);cursor:pointer;border:none;transition:all .18s;text-decoration:none;white-space:nowrap;}
.btn-outline{background:transparent;color:#fff;border:1.5px solid rgba(255,255,255,.25);}
.btn-outline:hover{background:rgba(255,255,255,.08);}
.btn-white{background:#fff;color:var(--ink);}
.btn-white:hover{background:#f0f0ea;}
.btn-green{background:#16a34a;color:#fff;}
.btn-green:hover{background:var(--green);}
.btn-blue{background:var(--blue);color:#fff;}
.btn-blue:hover{background:#1e40af;}
.btn-ghost{background:var(--surface);color:var(--ink2);border:1px solid var(--border);}
.btn-ghost:hover{background:var(--bg);}
.btn-sm{padding:7px 14px;font-size:12px;}
.btn-xs{padding:5px 10px;font-size:11px;border-radius:6px;}
.btn-red{background:#fef2f2;color:var(--red);border:1px solid #fecaca;}
.btn-red:hover{background:var(--red);color:#fff;}
.toolbar{margin:0 32px 20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.toolbar-left{display:flex;align-items:center;gap:10px;flex:1;flex-wrap:wrap;}
.search-wrap{position:relative;flex:1;max-width:320px;}
.search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--ink3);font-size:13px;pointer-events:none;}
.search-input{width:100%;padding:9px 12px 9px 36px;border:1px solid var(--border);border-radius:var(--r);font-size:13px;font-family:var(--sans);background:#fff;transition:border-color .15s;}
.search-input:focus{outline:none;border-color:var(--ink);box-shadow:0 0 0 3px rgba(0,0,0,.06);}
.filter-select{padding:9px 12px;border:1px solid var(--border);border-radius:var(--r);font-size:13px;font-family:var(--sans);background:#fff;color:var(--ink);cursor:pointer;}
.filter-select:focus{outline:none;border-color:var(--ink);}
.stats-row{margin:0 32px 22px;display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px 20px;box-shadow:var(--shadow);display:flex;flex-direction:column;gap:4px;}
.stat-card-value{font-size:26px;font-weight:800;color:var(--ink);letter-spacing:-.5px;}
.stat-card-label{font-size:11px;font-weight:600;color:var(--ink3);text-transform:uppercase;letter-spacing:.5px;}
.stat-card-sub{font-size:12px;color:var(--ink2);margin-top:2px;}
.stat-card.s-green{border-left:3px solid #22c55e;}
.stat-card.s-amber{border-left:3px solid #f59e0b;}
.stat-card.s-blue{border-left:3px solid var(--blue);}
.stat-card.s-ink{border-left:3px solid var(--ink);}
.cat-group{margin:0 32px 28px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);box-shadow:var(--shadow);overflow:hidden;}
.cat-group-header{background:#fafaf7;padding:13px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;cursor:pointer;user-select:none;}
.cat-group-header:hover{background:#f5f5f0;}
.cat-group-header-left{display:flex;align-items:center;gap:10px;}
.cat-badge{padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:var(--amber-bg);color:var(--amber);}
.cat-name{font-size:14px;font-weight:700;color:var(--ink);}
.cat-count{font-size:12px;color:var(--ink3);}
.cat-chevron{transition:transform .25s;font-size:11px;color:var(--ink3);}
.cat-group.collapsed .cat-chevron{transform:rotate(-90deg);}
.cat-group.collapsed .cat-group-body{display:none;}
.sr-table-wrap{overflow-x:auto;}
.sr-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.sr-table thead tr.group-row th{padding:7px 10px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid var(--border2);}
.sr-table thead tr.group-row th.g-info{background:#fafaf7;color:var(--ink3);text-align:left;}
.sr-table thead tr.group-row th.g-fixed{background:#f4f4f0;color:var(--ink2);border-left:2px solid var(--border2);}
.sr-table thead tr.group-row th.g-reimb{background:#fff8f0;color:var(--orange);border-left:2px solid #fddcba;}
.sr-table thead tr.sub-row th{padding:8px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--ink3);background:#fafaf7;border-bottom:2px solid var(--border);position:sticky;top:0;white-space:nowrap;text-align:left;}
.sr-table thead tr.sub-row th.g-fixed-cell{background:#f4f4f0;}
.sr-table thead tr.sub-row th.g-reimb-cell{background:#fff8f0;text-align:center;}
.sr-table tbody tr{border-bottom:1px solid #f0efea;transition:background .12s;}
.sr-table tbody tr:last-child{border-bottom:none;}
.sr-table tbody tr:hover{background:#f9f9f5;}
.sr-table tbody td{padding:8px 10px;vertical-align:middle;}
.td-desig{font-weight:700;white-space:nowrap;color:var(--ink);}
.td-code{font-family:var(--mono);font-size:11px;font-weight:600;color:var(--ink3);}
.rank-pill{display:inline-flex;align-items:center;justify-content:center;min-width:26px;height:22px;padding:0 6px;background:#f0f4ff;color:var(--blue);border:1px solid #bfdbfe;border-radius:5px;font-size:11px;font-weight:800;font-family:var(--mono);}
.rank-pill.no-rank{background:#f5f5f0;color:var(--ink3);border-color:var(--border);font-weight:500;font-size:10px;}
.emp-count-pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;background:var(--blue-bg);color:var(--blue);}
.m-wrap{display:flex;align-items:center;border:1.5px solid var(--border);border-radius:6px;background:#fff;overflow:hidden;transition:border-color .15s;min-width:90px;}
.m-wrap:focus-within{border-color:var(--ink);box-shadow:0 0 0 2px rgba(0,0,0,.06);}
.m-input{border:none;padding:0 9px;height:32px;font-family:'DM Mono',ui-monospace,'Cascadia Code','Source Code Pro',Menlo,Consolas,'DejaVu Sans Mono',monospace !important;font-size:13px;font-weight:500;font-variant-numeric:tabular-nums;letter-spacing:0.01em;color:var(--ink);width:100%;background:#fff;}
.m-input:focus{outline:none;}
.m-input::placeholder{color:#bbb;font-weight:400;}
.save-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;display:inline-block;margin-right:2px;}
.dot-saved{background:#22c55e;}
.dot-never{background:#e5e5e5;}
.incent-header-wrap{display:flex;flex-direction:column;align-items:center;gap:5px;}
.incent-header-name{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--green);text-align:center;white-space:nowrap;}
.incent-rate-wrap{display:flex;align-items:center;justify-content:center;background:#d1ead8;border:1.5px solid var(--green-lt);border-radius:5px;padding:0 2px;transition:border-color .15s,background .15s;cursor:text;}
.incent-rate-wrap:focus-within{background:#fff;border-color:var(--green);box-shadow:0 0 0 2px rgba(13,110,61,.12);}
.incent-rate-wrap.dirty{background:#fff8e1;border-color:var(--amber);box-shadow:0 0 0 2px rgba(180,83,9,.12);}
.incent-rate-input{border:none;background:transparent;width:44px;height:24px;font-size:11px;font-weight:800;font-family:var(--mono);color:var(--green);text-align:center;cursor:pointer;}
.incent-rate-input:focus{outline:none;cursor:text;}
.incent-rate-save-btn{display:none;align-items:center;justify-content:center;width:18px;height:18px;border-radius:4px;background:var(--green);color:#fff;font-size:9px;cursor:pointer;border:none;margin-left:2px;flex-shrink:0;transition:background .15s;}
.incent-rate-save-btn:hover{background:#15803d;}
.incent-rate-wrap.dirty .incent-rate-save-btn{display:flex;}
@keyframes savedFlash{0%{background:#d1ead8;border-color:var(--green);}40%{background:#bbf7d0;border-color:#16a34a;}100%{background:#d1ead8;border-color:var(--green-lt);}}
.incent-rate-wrap.saved-flash{animation:savedFlash .6s ease-out forwards;}
.bulk-panel{margin:0 32px 22px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);box-shadow:var(--shadow);overflow:hidden;}
.bulk-panel-header{background:#fafaf7;padding:13px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;cursor:pointer;}
.bulk-panel-header:hover{background:#f5f5f0;}
.bulk-panel-title{font-size:13px;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:8px;}
.bulk-panel-body{display:none;padding:16px 20px;}
.bulk-panel-body.open{display:block;}
.bulk-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:14px;}
.bulk-field{display:flex;flex-direction:column;gap:5px;}
.bulk-field label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--ink3);}
.bulk-input-wrap{display:flex;align-items:center;border:1.5px solid var(--border);border-radius:6px;overflow:hidden;background:#fff;}
.bulk-input-wrap:focus-within{border-color:var(--ink);}
.bulk-input{border:none;padding:0 9px;height:34px;font-family:'DM Mono',ui-monospace,'Cascadia Code','Source Code Pro',Menlo,Consolas,'DejaVu Sans Mono',monospace !important;font-size:13px;font-weight:500;font-variant-numeric:tabular-nums;color:var(--ink);width:100%;background:#fff;}
.bulk-input:focus{outline:none;}
.bulk-row-actions{display:flex;gap:8px;align-items:center;margin-top:4px;flex-wrap:wrap;}
.rate-manager-row td{background:#f5f8ff;border-top:none;padding:12px 20px;}
.rate-manager-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.rate-chip{display:inline-flex;align-items:center;border:1.5px solid #c7d9f8;border-radius:7px;overflow:hidden;background:#fff;}
.rate-chip-label{padding:0 8px;height:30px;display:flex;align-items:center;font-size:11px;font-weight:700;color:var(--blue);background:#eff6ff;border-right:1px solid #c7d9f8;white-space:nowrap;}
.rate-chip input.rate-chip-val{border:none;padding:0 7px;height:30px;font-size:12px;font-family:var(--mono);color:var(--ink);width:80px;background:#fff;}
.rate-chip input.rate-chip-val:focus{outline:none;}
.rate-del-btn{padding:0 7px;height:30px;display:flex;align-items:center;background:#fef2f2;color:var(--red);cursor:pointer;border:none;border-left:1px solid #fecaca;font-size:11px;transition:background .15s;}
.rate-del-btn:hover{background:var(--red);color:#fff;}
.rate-add-form{display:flex;align-items:center;gap:6px;background:#fffbf0;border:1px dashed #fcd34d;border-radius:7px;padding:4px 10px;}
.rate-add-form input{border:none;background:transparent;font-size:12px;font-family:var(--mono);width:52px;text-align:center;color:var(--ink);}
.rate-add-form input:focus{outline:none;}
.rate-add-label{font-size:10px;color:var(--amber);font-weight:700;}
.cat-table-footer{display:flex;align-items:center;justify-content:space-between;padding:11px 20px;background:#f5f5f0;border-top:1px solid var(--border);}
.cat-footer-info{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--ink2);font-weight:600;}
.cat-update-btn{background:#16a34a !important;color:#fff !important;padding:6px 14px !important;font-size:12px !important;}
.cat-update-btn:hover{background:#0d6e3d !important;}
.cat-update-btn.saving{background:#6b7280 !important;pointer-events:none;}
#toast{position:fixed;bottom:28px;right:28px;z-index:9999;background:var(--ink);color:#fff;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;font-family:var(--sans);display:flex;align-items:center;gap:9px;box-shadow:var(--shadow-lg);transform:translateY(80px);opacity:0;transition:all .3s cubic-bezier(.4,0,.2,1);pointer-events:none;}
#toast.show{transform:translateY(0);opacity:1;}
#toast.t-success{background:#15803d;}
#toast.t-error{background:var(--red);}
#toast.t-info{background:var(--blue);}
#savingOverlay{display:none;position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,.32);align-items:center;justify-content:center;}
#savingOverlay.show{display:flex;}
.saving-box{background:#fff;border-radius:14px;padding:30px 40px;text-align:center;box-shadow:var(--shadow-lg);}
.saving-spinner{width:38px;height:38px;border:3px solid #eee;border-top-color:var(--ink);border-radius:50%;animation:spin .7s linear infinite;margin:0 auto 12px;}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:900px){
    .pg-header,.toolbar,.stats-row,.bulk-panel,.cat-group{margin-left:16px;margin-right:16px;}
    .stats-row{grid-template-columns:1fr 1fr;}
    .bulk-grid{grid-template-columns:repeat(3,1fr);}
}
@media(max-width:600px){
    .stats-row{grid-template-columns:1fr;}
    .bulk-grid{grid-template-columns:1fr 1fr;}
}
</style>

<div class="pg-header">
    <div class="pg-header-left">
        <div class="pg-title"><i class="fa-solid fa-sliders"></i> Salary Configuration</div>
        <div class="pg-subtitle">All staff categories — changes auto-apply to linked employees on save</div>
    </div>
    <div class="pg-header-right">
        <a href="employees.php" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <button class="btn btn-white" onclick="saveAll()"><i class="fa-solid fa-floppy-disk"></i> Save All</button>
        <a href="bulk_salary_update.php" class="btn btn-green btn-sm"><i class="fa-solid fa-table"></i> Bulk Employee Update</a>
    </div>
</div>

<div class="stats-row">
    <div class="stat-card s-ink">
        <div class="stat-card-value"><?php echo $total_desig; ?></div>
        <div class="stat-card-label">Designations</div>
        <div class="stat-card-sub">across <?php echo $categories_count; ?> categories</div>
    </div>
    <div class="stat-card s-green">
        <div class="stat-card-value"><?php echo $configured; ?></div>
        <div class="stat-card-label">Configured</div>
        <div class="stat-card-sub"><?php echo $total_desig - $configured; ?> pending setup</div>
    </div>
    <div class="stat-card s-blue">
        <div class="stat-card-value"><?php echo $total_emp_affected; ?></div>
        <div class="stat-card-label">Employees</div>
        <div class="stat-card-sub">will be affected on save</div>
    </div>
    <div class="stat-card s-amber">
        <div class="stat-card-value"><?php echo $categories_count; ?></div>
        <div class="stat-card-label">Staff Categories</div>
        <div class="stat-card-sub">with active designations</div>
    </div>
</div>

<div class="toolbar">
    <div class="toolbar-left">
        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="search-input" id="searchInput" placeholder="Search designations…" oninput="filterRows()">
        </div>
        <select class="filter-select" id="catFilter" onchange="filterRows()">
            <option value="">All Categories</option>
            <?php foreach (array_keys($grouped) as $cat): ?>
            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="filter-select" id="cfgFilter" onchange="filterRows()">
            <option value="">All Status</option>
            <option value="configured">Configured</option>
            <option value="pending">Pending</option>
        </select>
    </div>
    <button class="btn btn-ghost btn-sm" onclick="toggleAllGroups(true)"><i class="fa-solid fa-expand"></i> Expand All</button>
    <button class="btn btn-ghost btn-sm" onclick="toggleAllGroups(false)"><i class="fa-solid fa-compress"></i> Collapse All</button>
</div>

<div class="bulk-panel">
    <div class="bulk-panel-header" onclick="toggleBulkPanel()">
        <div class="bulk-panel-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Bulk Fill — Apply common values to all visible designations</div>
        <i class="fa-solid fa-chevron-down" id="bulkChevron" style="color:var(--ink3);font-size:11px;transition:transform .25s;"></i>
    </div>
    <div class="bulk-panel-body" id="bulkPanelBody">
        <div class="bulk-grid">
            <div class="bulk-field"><label>Basic Salary</label><div class="bulk-input-wrap"><input type="number" class="bulk-input" id="bfill_basic" placeholder="0.00" step="0.01" min="0"></div></div>
            <div class="bulk-field"><label>Disc. Support</label><div class="bulk-input-wrap"><input type="number" class="bulk-input" id="bfill_disc" placeholder="0.00" step="0.01" min="0"></div></div>
            <div class="bulk-field"><label>Insurance</label><div class="bulk-input-wrap"><input type="number" class="bulk-input" id="bfill_insurance" placeholder="0.00" step="0.01" min="0"></div></div>
            <div class="bulk-field"><label>Welfare</label><div class="bulk-input-wrap"><input type="number" class="bulk-input" id="bfill_welfare" placeholder="0.00" step="0.01" min="0"></div></div>
            <div class="bulk-field"><label>ECO Incentive</label><div class="bulk-input-wrap"><input type="number" class="bulk-input" id="bfill_eco" placeholder="0.00" step="0.01" min="0"></div></div>
            <div class="bulk-field"><label>BP Incentive</label><div class="bulk-input-wrap"><input type="number" class="bulk-input" id="bfill_bp" placeholder="0.00" step="0.01" min="0"></div></div>
            <div class="bulk-field"><label>Assortment</label><div class="bulk-input-wrap"><input type="number" class="bulk-input" id="bfill_assort" placeholder="0.00" step="0.01" min="0"></div></div>
            <div class="bulk-field"><label>PS Compliance</label><div class="bulk-input-wrap"><input type="number" class="bulk-input" id="bfill_ps" placeholder="0.00" step="0.01" min="0"></div></div>
        </div>
        <div style="border-top:1px solid var(--border);padding-top:14px;">
            <div class="bulk-grid" style="grid-template-columns:repeat(3,1fr);">
                <div class="bulk-field"><label>Meal Reimbursement</label><div class="bulk-input-wrap"><input type="number" class="bulk-input" id="bfill_meal" placeholder="0.00" step="0.01" min="0"></div></div>
                <div class="bulk-field"><label>Traveling Reimbursement</label><div class="bulk-input-wrap"><input type="number" class="bulk-input" id="bfill_travel" placeholder="0.00" step="0.01" min="0"></div></div>
                <div class="bulk-field"><label>Mobile Reimbursement</label><div class="bulk-input-wrap"><input type="number" class="bulk-input" id="bfill_mobile" placeholder="0.00" step="0.01" min="0"></div></div>
            </div>
        </div>
        <div class="bulk-row-actions" style="margin-top:14px;">
            <button class="btn btn-blue btn-sm" onclick="applyBulkFill()"><i class="fa-solid fa-bolt"></i> Apply to All Visible</button>
            <button class="btn btn-ghost btn-sm" onclick="clearBulkFill()"><i class="fa-solid fa-xmark"></i> Clear</button>
            <span style="font-size:11px;color:var(--ink3);">Only non-empty fields will be applied. Does not auto-save — click Save All or per-row Save.</span>
        </div>
    </div>
</div>

<?php if (empty($grouped)): ?>
<div style="margin:0 32px;background:#fff;border:1px solid var(--border);border-radius:14px;padding:60px 32px;text-align:center;color:var(--ink3);">
    <i class="fa-solid fa-users-slash" style="font-size:36px;display:block;margin-bottom:14px;"></i>
    <p style="font-size:15px;font-weight:600;color:var(--ink2);">No active designations found</p>
</div>
<?php endif; ?>

<?php foreach ($grouped as $catName => $desigs):
    $cat_id       = preg_replace('/\W+/', '_', strtolower($catName));
    $cfg_count    = count(array_filter($desigs, fn($d) => $d['basic_salary'] !== null));
    $ranked_count = count(array_filter($desigs, fn($d) => $d['rank_no'] !== null));

    $schema = null;
    // 1) Exact match (case-insensitive) — always wins
    foreach ($category_schemas as $sCatName => $sDef) {
        if (strcasecmp($catName, $sCatName) === 0) { $schema = $sDef; break; }
    }
    // 2) Longest partial match — prevents "Cash Collector" stealing "Assistant Cash Collector"
    if (!$schema) {
        $bestLen = 0;
        foreach ($category_schemas as $sCatName => $sDef) {
            if (stripos($catName, $sCatName) !== false || stripos($sCatName, $catName) !== false) {
                $matchLen = strlen($sCatName);
                if ($matchLen > $bestLen) { $bestLen = $matchLen; $schema = $sDef; }
            }
        }
    }
    if (!$schema) {
        $schema = ['code'=>'?','color'=>'slate','fixed'=>[['basic_salary','Basic Salary'],['insurance_amount','Insurance'],['welfare_amount','Welfare']],'incentive_groups'=>[],
                   'has_incent_rate_headers'=>false,'ssv_rates'=>[],'ssv_position'=>'after_fixed',
                   'reimb'=>[['meal_reimbursement','Meal']],'has_rank'=>false,
                   'fields'=>['basic_salary','insurance_amount','welfare_amount','meal_reimbursement']];
    }

    $has_ssv      = !empty($schema['ssv_rates']);
    $has_rank     = $schema['has_rank'];
    $has_ir_hdrs  = $schema['has_incent_rate_headers'] ?? false;
    $ssv_position = $schema['ssv_position'] ?? 'after_fixed';
    $fixed_cols   = count($schema['fixed']);
    $reimb_cols   = count($schema['reimb']);
    $info_colspan = $has_rank ? 5 : 4;

    $incent_colspan = 0;
    foreach ($schema['incentive_groups'] as $grp) $incent_colspan += count($grp['cols']);
    if ($has_ssv) $incent_colspan += count($schema['ssv_rates']);

    $cat_fields_json = json_encode($schema['fields'] ?? []);

    $ssv_group_th = '';
    if ($has_ssv) {
        $ssv_group_th = '<th style="background:#f0f7ff;color:var(--blue);border-left:2px solid #c7d9f8;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;" colspan="'.count($schema['ssv_rates']).'">
            Secondary Sales Value
            <button class="btn btn-xs" style="background:#eff6ff;color:var(--blue);border:1px solid #c7d9f8;margin-left:6px;"
                onclick="event.stopPropagation();openRateManager(this,\''.htmlspecialchars($cat_id).'\')">
                <i class="fa-solid fa-pen-to-square"></i> Manage Rates
            </button>
        </th>';
    }

    $ssv_sub_ths = '';
    if ($has_ssv) {
        foreach ($schema['ssv_rates'] as $si => $rl) {
            $bdr = ($si === 0) ? 'border-left:2px solid #c7d9f8;' : '';
            $ssv_sub_ths .= '<th data-ssv-header="1" style="background:#f0f7ff;text-align:center;'.$bdr.'">'.htmlspecialchars($rl).'</th>';
        }
    }
?>
<div class="cat-group" id="grp_<?php echo $cat_id; ?>" data-cat="<?php echo htmlspecialchars($catName); ?>">
    <div class="cat-group-header" onclick="toggleGroup('grp_<?php echo $cat_id; ?>')">
        <div class="cat-group-header-left">
            <span class="cat-name"><?php echo htmlspecialchars($catName); ?></span>
            <span class="cat-badge"><?php echo htmlspecialchars($desigs[0]['category_code'] ?? $schema['code']); ?></span>
            <span class="cat-count">
                <?php echo count($desigs); ?> designation<?php echo count($desigs)!==1?'s':''; ?>
                &bull; <?php echo $cfg_count; ?>/<?php echo count($desigs); ?> configured
                <?php if ($has_rank): ?>&bull; <i class="fa-solid fa-arrow-up-1-9" style="font-size:10px;color:var(--blue);"></i> <?php echo $ranked_count; ?> ranked<?php endif; ?>
            </span>
        </div>
        <div style="display:flex;align-items:center;gap:8px;" onclick="event.stopPropagation()">
            <button class="btn btn-green btn-sm cat-update-btn" id="upd_<?php echo $cat_id; ?>"
                    onclick="saveTable('<?php echo $cat_id; ?>')">
                <i class="fa-solid fa-floppy-disk"></i> Update
            </button>
            <i class="fa-solid fa-chevron-down cat-chevron"></i>
        </div>
    </div>
    <div class="cat-group-body">
    <div class="sr-table-wrap">
    <table class="sr-table" id="tbl_<?php echo $cat_id; ?>">
        <thead>
            <tr class="group-row">
                <th class="g-info" colspan="<?php echo $info_colspan; ?>">Employee Info</th>
                <th class="g-fixed" colspan="<?php echo $fixed_cols; ?>" style="border-left:2px solid var(--border2);">Fixed</th>

                <?php if ($has_ssv && $ssv_position === 'after_fixed'): ?>
                    <?php echo $ssv_group_th; ?>
                <?php endif; ?>

                <?php
                $first_incent_grp = true;
                foreach ($schema['incentive_groups'] as $grp):
                    $pal = $incent_colors[$grp['color']] ?? $incent_colors['blue'];
                    if (!empty($grp['ssv_before']) && $has_ssv && $ssv_position === 'before_attendance'):
                        echo $ssv_group_th;
                        $first_incent_grp = false;
                    endif;
                    $bdr = $first_incent_grp ? "border-left:2px solid {$pal['hdr_border']};" : '';
                    $first_incent_grp = false;
                ?>
                <th style="background:<?php echo $pal['hdr_bg']; ?>;color:<?php echo $pal['hdr_text']; ?>;<?php echo $bdr; ?>font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;" colspan="<?php echo count($grp['cols']); ?>">
                    <?php echo htmlspecialchars($grp['label']); ?>
                </th>
                <?php endforeach; ?>

                <?php if ($reimb_cols): ?>
                <th class="g-reimb" colspan="<?php echo $reimb_cols; ?>" style="border-left:2px solid #fddcba;">Reimbursement</th>
                <?php endif; ?>
                <th style="background:#fafaf7;" colspan="1"></th>
            </tr>

            <tr class="sub-row" id="sub-<?php echo $cat_id; ?>">
                <th style="width:32px;"></th>
                <?php if ($has_rank): ?>
                <th style="width:46px;text-align:center;background:#f0f4ff;color:var(--blue);">
                    <i class="fa-solid fa-arrow-up-1-9" style="font-size:10px;"></i> Rank
                </th>
                <?php endif; ?>
                <th>Designation</th>
                <th>Code</th>
                <th>Emp.</th>

                <?php foreach ($schema['fixed'] as $fi => $fc): ?>
                <th class="g-fixed-cell" style="<?php echo $fi===0?'border-left:2px solid var(--border2);':''; ?>"><?php echo htmlspecialchars($fc[1]); ?></th>
                <?php endforeach; ?>

                <?php if ($has_ssv && $ssv_position === 'after_fixed'): echo $ssv_sub_ths; endif; ?>

                <?php
                $first_incent_col = true;
                foreach ($schema['incentive_groups'] as $grp):
                    $pal  = $incent_colors[$grp['color']] ?? $incent_colors['blue'];
                    $ikey = $grp['incent_key'] ?? null;

                    if (!empty($grp['ssv_before']) && $has_ssv && $ssv_position === 'before_attendance'):
                        foreach ($schema['ssv_rates'] as $si => $rl):
                            $sbdr = ($si === 0) ? 'border-left:2px solid #c7d9f8;' : '';
                            echo '<th data-ssv-header="1" style="background:#f0f7ff;text-align:center;'.$sbdr.'">'.htmlspecialchars($rl).'</th>';
                        endforeach;
                        $first_incent_col = false;
                    endif;

                    foreach ($grp['cols'] as $ci => $col):
                        $bdr = $first_incent_col ? "border-left:2px solid {$pal['hdr_border']};" : '';
                        $first_incent_col = false;
                        $show_pill = $has_ir_hdrs && $ikey !== null;
                ?>
                <th style="background:<?php echo $pal['hdr_bg']; ?>;<?php echo $bdr; ?>text-align:center;vertical-align:middle;">
                    <?php if ($show_pill):
                        $first_did  = $desigs[0]['id'];
                        $d_ir_hdr   = $incentive_rates_map[$first_did] ?? [];
                        $saved_rate = $d_ir_hdr[$ikey] ?? ($incent_defaults[$ikey] ?? '100%');
                    ?>
                    <div class="incent-header-wrap">
                        <span class="incent-header-name" style="color:<?php echo $pal['hdr_text']; ?>;"><?php echo htmlspecialchars($col[1]); ?></span>
                        <div class="incent-rate-wrap" title="Max rate — click to edit, then ✓ to save">
                            <input type="text" class="incent-rate-input"
                                   data-incent="<?php echo $ikey; ?>"
                                   data-catid="<?php echo $cat_id; ?>"
                                   data-original="<?php echo htmlspecialchars($saved_rate); ?>"
                                   value="<?php echo htmlspecialchars($saved_rate); ?>"
                                   placeholder="100%">
                            <button type="button" class="incent-rate-save-btn"
                                    title="Save this rate for all designations"
                                    onclick="saveIncentRateHeader(this)">
                                <i class="fa-solid fa-check"></i>
                            </button>
                        </div>
                    </div>
                    <?php else: ?>
                    <span style="color:<?php echo $pal['hdr_text']; ?>;font-size:10px;font-weight:700;"><?php echo htmlspecialchars($col[1]); ?></span>
                    <?php endif; ?>
                </th>
                <?php endforeach; endforeach; ?>

                <?php foreach ($schema['reimb'] as $ri => $rc): ?>
                <th class="g-reimb-cell" style="<?php echo $ri===0?'border-left:2px solid #fddcba;':''; ?>"><?php echo htmlspecialchars($rc[1]); ?></th>
                <?php endforeach; ?>

                <th style="text-align:center;background:#fafaf7;width:80px;">Save</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($desigs as $d):
            $fv     = fn($v) => ($v!==null && $v!=='') ? number_format((float)$v,2,'.',''): '';
            $incentive_fallbacks = ['incentive_daily_90'=>'incentive_daily','incentive_weekly_90'=>'incentive_weekly','incentive_monthly_90'=>'incentive_monthly'];
            $fv_col = function(string $col) use ($d, $fv, $incentive_fallbacks): string { $val = $d[$col] ?? null; if (($val === null || $val === '') && isset($incentive_fallbacks[$col])) { $val = $d[$incentive_fallbacks[$col]] ?? null; } return $fv($val); };
            $is_cfg = $d['basic_salary'] !== null;
            $did    = $d['id'];
            $rank   = $d['rank_no'];
            $d_ir   = $incentive_rates_map[$did] ?? [];
        ?>
        <tr class="desig-row"
            data-id="<?php echo $did; ?>"
            data-cat="<?php echo htmlspecialchars($catName); ?>"
            data-cfg="<?php echo $is_cfg?'configured':'pending'; ?>"
            data-fields="<?php echo htmlspecialchars($cat_fields_json); ?>"
            data-ssv-position="<?php echo htmlspecialchars($ssv_position); ?>">
            <td style="text-align:center;">
                <span class="save-dot <?php echo $is_cfg?'dot-saved':'dot-never'; ?>"
                      id="dot_<?php echo $did; ?>" title="<?php echo $is_cfg?'Configured':'Not configured'; ?>"></span>
            </td>
            <?php if ($has_rank): ?>
            <td style="text-align:center;background:#fafbff;">
                <?php if ($rank!==null && $rank!==''): ?>
                <span class="rank-pill"><?php echo intval($rank); ?></span>
                <?php else: ?>
                <span class="rank-pill no-rank">—</span>
                <?php endif; ?>
            </td>
            <?php endif; ?>
            <td class="td-desig"><?php echo htmlspecialchars($d['designation_name']); ?></td>
            <td class="td-code"><?php echo htmlspecialchars($d['designation_code']); ?></td>
            <td><span class="emp-count-pill"><?php echo $d['emp_count']; ?></span></td>

            <?php foreach ($schema['fixed'] as $fi => $fc): ?>
            <td style="<?php echo $fi===0?'border-left:2px solid var(--border2);':''; ?>">
                <div class="m-wrap">
                    <input type="number" class="m-input" data-field="<?php echo $fc[0]; ?>" data-id="<?php echo $did; ?>"
                           value="<?php echo $fv($d[$fc[0]]); ?>" placeholder="—" step="0.01" min="0">
                </div>
            </td>
            <?php endforeach; ?>

            <?php if ($has_ssv && $ssv_position === 'after_fixed'):
                foreach ($schema['ssv_rates'] as $si => $rl): ?>
            <td class="ssv-col-cell" data-rate-col="<?php echo htmlspecialchars($rl); ?>" data-desig="<?php echo $did; ?>"
                style="<?php echo $si===0?'border-left:2px solid #c7d9f8;':''; ?>text-align:center;">
                <div class="m-wrap" style="border-color:#c7d9f8;">
                    <input type="number" class="m-input ssv-input" data-field="ssv" data-rate="<?php echo htmlspecialchars($rl); ?>"
                           data-id="<?php echo $did; ?>" placeholder="—" step="0.01" min="0">
                </div>
            </td>
            <?php endforeach; endif; ?>

            <?php
            $first_icol = true;
            foreach ($schema['incentive_groups'] as $grp):
                $pal = $incent_colors[$grp['color']] ?? $incent_colors['blue'];

                if (!empty($grp['ssv_before']) && $has_ssv && $ssv_position === 'before_attendance'):
                    foreach ($schema['ssv_rates'] as $si => $rl):
                        $sbdr = ($si === 0) ? 'border-left:2px solid #c7d9f8;' : '';
            ?>
            <td class="ssv-col-cell" data-rate-col="<?php echo htmlspecialchars($rl); ?>" data-desig="<?php echo $did; ?>"
                style="<?php echo $sbdr; ?>text-align:center;">
                <div class="m-wrap" style="border-color:#c7d9f8;">
                    <input type="number" class="m-input ssv-input" data-field="ssv" data-rate="<?php echo htmlspecialchars($rl); ?>"
                           data-id="<?php echo $did; ?>" placeholder="—" step="0.01" min="0">
                </div>
            </td>
            <?php      endforeach;
                    $first_icol = false;
                endif;

                foreach ($grp['cols'] as $ci => $col):
                    $bdr = $first_icol ? "border-left:2px solid {$pal['hdr_border']};" : '';
                    $first_icol = false;
            ?>
            <td style="<?php echo $bdr; ?>text-align:center;">
                <div class="m-wrap" style="border-color:<?php echo $pal['border']; ?>;"
                     onfocusin="this.style.borderColor='<?php echo $pal['text']; ?>';this.style.boxShadow='0 0 0 2px <?php echo $pal['border']; ?>50';"
                     onfocusout="this.style.borderColor='<?php echo $pal['border']; ?>';this.style.boxShadow='';">
                    <input type="number" class="m-input" data-field="<?php echo $col[0]; ?>" data-id="<?php echo $did; ?>"
                           value="<?php echo $fv_col($col[0]); ?>" placeholder="—" step="0.01" min="0">
                </div>
            </td>
            <?php endforeach; endforeach; ?>

            <?php foreach ($schema['reimb'] as $ri => $rc): ?>
            <td style="<?php echo $ri===0?'border-left:2px solid #fddcba;':''; ?>">
                <div class="m-wrap" style="border-color:#fed7aa;"
                     onfocusin="this.style.borderColor='var(--orange)';this.style.boxShadow='0 0 0 2px #fed7aa50';"
                     onfocusout="this.style.borderColor='#fed7aa';this.style.boxShadow='';">
                    <input type="number" class="m-input" data-field="<?php echo $rc[0]; ?>" data-id="<?php echo $did; ?>"
                           value="<?php echo $fv($d[$rc[0]] ?? null); ?>" placeholder="—" step="0.01" min="0">
                </div>
            </td>
            <?php endforeach; ?>

            <td style="text-align:center;">
                <?php foreach ($incent_defaults as $ikey => $idef): ?>
                <input type="hidden" class="incent-rate-hidden" data-incent="<?php echo $ikey; ?>" data-did="<?php echo $did; ?>"
                       value="<?php echo htmlspecialchars($d_ir[$ikey] ?? $idef); ?>">
                <?php endforeach; ?>
                <button class="btn btn-ghost btn-xs" onclick="saveRow(<?php echo $did; ?>)">
                    <i class="fa-solid fa-floppy-disk"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="cat-table-footer" id="footer_<?php echo $cat_id; ?>">
        <span class="cat-footer-info">
            <span class="save-dot <?php echo $cfg_count > 0 ? 'dot-saved' : 'dot-never'; ?>"></span>
            <?php echo $cfg_count; ?>/<?php echo count($desigs); ?> configured
            &nbsp;&bull;&nbsp; <?php echo array_sum(array_column($desigs,'emp_count')); ?> employees
        </span>
        <button class="btn btn-green btn-sm" onclick="saveTable('<?php echo $cat_id; ?>')">
            <i class="fa-solid fa-floppy-disk"></i> Update <?php echo htmlspecialchars($catName); ?>
        </button>
    </div>
    </div>
</div>
<?php endforeach; ?>

<div id="toast"></div>
<div id="savingOverlay">
    <div class="saving-box">
        <div class="saving-spinner"></div>
        <div style="font-size:14px;font-weight:700;color:var(--ink);">Saving…</div>
        <div style="font-size:12px;color:var(--ink3);margin-top:4px;">Updating employee records</div>
    </div>
</div>

<script>
const SELF_URL = <?php echo json_encode(basename($_SERVER['PHP_SELF'])); ?>;
const ssvRates = {};

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.desig-row').forEach(row => {
        const id = row.getAttribute('data-id');
        if (!row.querySelector('.ssv-input')) return;
        fetch(SELF_URL + '?get_config=' + id)
            .then(r => r.json())
            .then(data => {
                ssvRates[id] = data.rates || [];
                populateSsvCells(id, data.rates || []);
                if (data.incent_rates) {
                    Object.entries(data.incent_rates).forEach(([key, val]) => {
                        const h = row.querySelector(`.incent-rate-hidden[data-incent="${key}"][data-did="${id}"]`);
                        if (h) h.value = val;
                    });
                }
            }).catch(() => {});
    });

    document.querySelectorAll('.incent-rate-input').forEach(inp => {
        inp.addEventListener('input', function () {
            const wrap = this.closest('.incent-rate-wrap');
            wrap.classList.toggle('dirty', this.value.trim() !== this.getAttribute('data-original'));
        });
    });
});

function populateSsvCells(desigId, rates) {
    const row = document.querySelector(`.desig-row[data-id="${desigId}"]`);
    if (!row) return;
    row.querySelectorAll('.ssv-input').forEach(inp => {
        const found = rates.find(r => r.rate_label === inp.getAttribute('data-rate'));
        if (found && found.amount !== null && found.amount !== '') {
            inp.value = parseFloat(found.amount).toFixed(2);
        }
    });
}

document.addEventListener('blur', function (e) {
    if (!e.target.classList.contains('incent-rate-input')) return;
    let v = e.target.value.trim().replace('%', '');
    if (v === '' || isNaN(v)) {
        e.target.value = e.target.getAttribute('data-original');
        e.target.closest('.incent-rate-wrap').classList.remove('dirty');
        return;
    }
    e.target.value = parseFloat(v) + '%';
}, true);

document.addEventListener('focus', function (e) {
    if (!e.target.classList.contains('incent-rate-input')) return;
    e.target.value = e.target.value.replace('%', '');
    e.target.select();
}, true);

async function saveIncentRateHeader(btn) {
    const wrap  = btn.closest('.incent-rate-wrap');
    const inp   = wrap.querySelector('.incent-rate-input');
    const key   = inp.getAttribute('data-incent');
    const catId = inp.getAttribute('data-catid');
    let   val   = inp.value.trim();
    if (!val.includes('%')) val += '%';

    const group = document.getElementById('grp_' + catId);
    if (!group) return;
    const desigRows = group.querySelectorAll('.desig-row');
    if (!desigRows.length) return;

    btn.disabled = true;
    let saved = 0, errs = [];

    for (const row of desigRows) {
        const did = row.getAttribute('data-id');
        const fd  = new FormData();
        fd.append('action', 'save_incent_rate');
        fd.append('designation_id', did);
        fd.append('incent_key', key);
        fd.append('rate_value', val);
        try {
            const res  = await fetch(SELF_URL, { method: 'POST', body: fd });
            const text = await res.text();
            let json;
            try { json = JSON.parse(text); }
            catch(pe) { errs.push('Bad response: ' + text.replace(/<[^>]+>/g,' ').substring(0,120)); continue; }
            if (json.success) {
                saved++;
                const h = row.querySelector(`.incent-rate-hidden[data-incent="${key}"][data-did="${did}"]`);
                if (h) h.value = val;
            } else errs.push(json.message);
        } catch (e) { errs.push('Network error'); }
    }

    btn.disabled = false;
    if (!errs.length) {
        inp.setAttribute('data-original', val);
        wrap.classList.remove('dirty');
        wrap.classList.add('saved-flash');
        setTimeout(() => wrap.classList.remove('saved-flash'), 700);
        showToast(`"${key.replace(/_/g, ' ')}" set to ${val} for ${saved} designation(s)`, 'success');
    } else {
        showToast('Error: ' + errs[0], 'error');
    }
}

function toggleGroup(id) {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('collapsed');
}
function toggleAllGroups(expand) {
    document.querySelectorAll('.cat-group').forEach(g => g.classList.toggle('collapsed', !expand));
}

function toggleBulkPanel() {
    const body = document.getElementById('bulkPanelBody');
    const ch   = document.getElementById('bulkChevron');
    const open = body.classList.toggle('open');
    ch.style.transform = open ? 'rotate(180deg)' : '';
}

function applyBulkFill() {
    const map = {
        basic_salary:            'bfill_basic',
        discretionary_support:   'bfill_disc',
        insurance_amount:        'bfill_insurance',
        welfare_amount:          'bfill_welfare',
        eco_incentive:           'bfill_eco',
        bp_incentive:            'bfill_bp',
        total_assortment:        'bfill_assort',
        total_ps_compliance:     'bfill_ps',
        meal_reimbursement:      'bfill_meal',
        traveling_reimbursement: 'bfill_travel',
        mobile_reimbursement:    'bfill_mobile',
    };
    let affected = 0;
    document.querySelectorAll('.desig-row').forEach(row => {
        if (row.style.display === 'none') return;
        Object.entries(map).forEach(([field, eid]) => {
            const val = (document.getElementById(eid) || {}).value?.trim();
            if (!val) return;
            const inp = row.querySelector(`input[data-field="${field}"]`);
            if (inp) { inp.value = parseFloat(val).toFixed(2); affected++; }
        });
    });
    const filled = Object.values(map).filter(id => document.getElementById(id)?.value?.trim()).length;
    showToast(`Applied ${filled} field(s) to ${filled ? Math.ceil(affected / filled) : 0} visible rows`, 'success');
}

function clearBulkFill() {
    ['bfill_basic','bfill_disc','bfill_insurance','bfill_welfare','bfill_eco','bfill_bp','bfill_assort','bfill_ps',
     'bfill_meal','bfill_travel','bfill_mobile'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
}

function filterRows() {
    const q   = document.getElementById('searchInput').value.trim().toLowerCase();
    const cat = document.getElementById('catFilter').value;
    const cfg = document.getElementById('cfgFilter').value;
    document.querySelectorAll('.desig-row').forEach(row => {
        const name = row.querySelector('.td-desig').textContent.toLowerCase();
        const code = row.querySelector('.td-code').textContent.toLowerCase();
        const ok   = ((!q || name.includes(q) || code.includes(q))
                   && (!cat || row.getAttribute('data-cat') === cat)
                   && (!cfg || row.getAttribute('data-cfg') === cfg));
        row.style.display = ok ? '' : 'none';
    });
}

function collectRowData(desigId) {
    const row = document.querySelector(`.desig-row[data-id="${desigId}"]`);
    if (!row) return null;

    let scopedFields = null;
    try {
        const raw = row.getAttribute('data-fields');
        if (raw) scopedFields = new Set(JSON.parse(raw));
    } catch(e) { scopedFields = null; }

    const data = { designation_id: desigId };

    row.querySelectorAll('input.m-input[data-field][data-id]').forEach(inp => {
        const f = inp.getAttribute('data-field');
        if (!f || f === 'ssv') return;
        if (scopedFields && !scopedFields.has(f)) return;
        data[f] = inp.value.trim();
    });

    const rates = [];
    row.querySelectorAll('.ssv-input').forEach(inp => {
        const amt = inp.value.trim();
        rates.push({ label: inp.getAttribute('data-rate'), amount: amt !== '' ? amt : '' });
    });
    (ssvRates[desigId] || []).forEach(r => {
        if (!rates.find(ro => ro.label === r.rate_label))
            rates.push({ label: r.rate_label, amount: (r.amount !== null && r.amount !== '') ? r.amount : '' });
    });
    data.rates = rates;

    const ir = {};
    row.querySelectorAll('input.incent-rate-hidden').forEach(h => {
        ir[h.getAttribute('data-incent')] = h.value.trim();
    });
    const tbl = row.closest('table');
    if (tbl) {
        tbl.querySelectorAll('.incent-rate-input').forEach(inp => {
            let v = inp.value.trim();
            if (v !== '' && !isNaN(v.replace('%', ''))) {
                if (!v.includes('%')) v += '%';
                ir[inp.getAttribute('data-incent')] = v;
            }
        });
    }
    data.incent_rates = ir;
    return data;
}

async function saveRow(desigId) {
    await doSave([collectRowData(desigId)]);
}

async function saveTable(catId) {
    const group = document.getElementById('grp_' + catId);
    if (!group) return;
    const rows = group.querySelectorAll('.desig-row');
    if (!rows.length) { showToast('No designations in this table', 'info'); return; }

    const btns = [
        document.getElementById('upd_' + catId),
        group.querySelector('.cat-table-footer .btn')
    ];
    btns.forEach(b => { if (b) { b.classList.add('saving'); b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…'; } });

    const all = [];
    rows.forEach(row => all.push(collectRowData(row.getAttribute('data-id'))));
    await doSave(all);

    btns.forEach(b => {
        if (!b) return;
        if (b.id && b.id.startsWith('upd_')) {
            b.classList.remove('saving');
            b.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Update';
        } else {
            b.classList.remove('saving');
            const catName = group.getAttribute('data-cat') || '';
            b.innerHTML = `<i class="fa-solid fa-floppy-disk"></i> Update ${catName}`;
        }
    });
}

async function saveAll() {
    const all = [];
    document.querySelectorAll('.desig-row').forEach(row => all.push(collectRowData(row.getAttribute('data-id'))));
    if (!confirm(`Save all ${all.length} designations across all categories? This will update all linked employees.`)) return;
    await doSave(all);
}

async function doSave(rowDataArr) {
    document.getElementById('savingOverlay').classList.add('show');
    let totalPushed = 0, errors = [];

    for (const data of rowDataArr) {
        if (!data) continue;
        const fd = new FormData();
        fd.append('action', 'save_config');

        Object.entries(data).forEach(([k, v]) => {
            if (k === 'rates' || k === 'incent_rates') return;
            fd.append(k, v ?? '');
        });

        (data.rates || []).forEach((r, i) => {
            fd.append(`rates[${i}][label]`,  r.label);
            fd.append(`rates[${i}][amount]`, r.amount);
        });
        if (data.incent_rates) {
            Object.entries(data.incent_rates).forEach(([k, v]) => fd.append(`incent_rates[${k}]`, v));
        }

        try {
            const res  = await fetch(SELF_URL, { method: 'POST', body: fd });

            if (!res.ok) {
                errors.push(`HTTP ${res.status} for designation ${data.designation_id}`);
                continue;
            }

            const text = await res.text();
            let json;
            try {
                const trimmed = text.replace(/^[\s\S]*?({)/, '$1');
                json = JSON.parse(trimmed);
            } catch (pe) {
                const plain = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                errors.push('Server error (desig ' + data.designation_id + '): ' + plain.substring(0, 300));
                console.error('Raw server response for desig', data.designation_id, ':', text);
                continue;
            }
            if (json.success) {
                totalPushed += json.pushed || 0;
                const dot = document.getElementById('dot_' + data.designation_id);
                if (dot) { dot.className = 'save-dot dot-saved'; dot.title = 'Configured'; }
                const row = document.querySelector(`.desig-row[data-id="${data.designation_id}"]`);
                if (row) row.setAttribute('data-cfg', 'configured');
                if (json.message && json.message.includes('push error')) {
                    console.warn('Push warning desig', data.designation_id, ':', json.message);
                }
            } else {
                errors.push('Desig ' + data.designation_id + ': ' + (json.message || 'Unknown error'));
            }
        } catch (e) {
            errors.push('Network error for designation ' + data.designation_id + ': ' + (e.message || e));
        }
    }

    document.getElementById('savingOverlay').classList.remove('show');
    if (errors.length) {
        console.error('Save errors:', errors);
        showToast('Save error: ' + errors[0], 'error');
    } else {
        showToast(`Saved. ${totalPushed} employee(s) updated.`, 'success');
    }
}

let activeRateManager = null;

function openRateManager(btn, catId) {
    const existing = document.getElementById('rate-manager-row');
    if (existing) existing.remove();
    activeRateManager = null;

    const group = btn.closest('.cat-group');
    const rows  = group.querySelectorAll('.desig-row');
    if (!rows.length) return;

    const tbody   = rows[0].closest('tbody');
    const colspan = rows[0].querySelectorAll('td').length;
    const mr      = document.createElement('tr');
    mr.id         = 'rate-manager-row';
    mr.className  = 'rate-manager-row';
    mr.innerHTML  = `<td colspan="${colspan}">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
            <strong style="font-size:12px;color:var(--blue)"><i class="fa-solid fa-percent"></i> &nbsp;Secondary Sales Rate Manager — applies to all rows in this category</strong>
            <button class="btn btn-ghost btn-xs" onclick="closeRateManager()"><i class="fa-solid fa-xmark"></i> Close</button>
        </div>
        <div class="rate-manager-inner" id="rateChipsArea"></div>
        <div style="margin-top:10px;display:flex;align-items:center;gap:8px;">
            <div class="rate-add-form">
                <span class="rate-add-label">Rate:</span>
                <input type="text" id="newRateLabel" placeholder="e.g. 105%" maxlength="8">
                <span class="rate-add-label">→</span>
                <button class="btn btn-blue btn-xs" onclick="addRateColumn('${catId}')"><i class="fa-solid fa-plus"></i> Add</button>
            </div>
            <span style="font-size:11px;color:var(--ink3);">Add percentage labels. Amounts are filled per-designation in the table.</span>
        </div>
    </td>`;
    tbody.insertBefore(mr, rows[0]);
    activeRateManager = { catId, rows };

    const subRow = group.querySelector('thead tr.sub-row');
    const existingHeaders = subRow ? [...subRow.querySelectorAll('th[data-ssv-header]')] : [];
    if (existingHeaders.length) {
        existingHeaders.forEach(th => renderRateChip(th.textContent.trim()));
    } else {
        ['95%'].forEach(r => renderRateChip(r));
    }
}

function renderRateChip(label) {
    const area = document.getElementById('rateChipsArea');
    if (!area || area.querySelector(`[data-chip="${label}"]`)) return;
    const chip = document.createElement('div');
    chip.className = 'rate-chip';
    chip.setAttribute('data-chip', label);
    chip.innerHTML = `<span class="rate-chip-label">${label}</span>
        <button type="button" class="rate-del-btn" onclick="removeRateColumn('${label}')">
            <i class="fa-solid fa-xmark"></i>
        </button>`;
    area.appendChild(chip);
}

function addRateColumn(catId) {
    const input = document.getElementById('newRateLabel');
    let label   = (input.value || '').trim();
    if (!label) { showToast('Enter a rate label', 'error'); return; }
    if (!label.includes('%')) label += '%';
    renderRateChip(label);
    input.value = '';
    addSsvColumn(label, catId);
    showToast(`Rate column "${label}" added`, 'success');
}

function removeRateColumn(label) {
    const area = document.getElementById('rateChipsArea');
    const chip = area ? area.querySelector(`[data-chip="${label}"]`) : null;
    if (chip) chip.remove();
    if (!activeRateManager) return;
    activeRateManager.rows.forEach(row => {
        const cell = row.querySelector(`td[data-rate-col="${label}"]`);
        if (cell) cell.remove();
    });
    const group = document.getElementById(`grp_${activeRateManager.catId}`);
    if (group) {
        const subRow = group.querySelector('thead tr.sub-row');
        if (subRow) {
            subRow.querySelectorAll('th[data-ssv-header]').forEach(th => {
                if (th.getAttribute('data-rate-header') === label || th.textContent.trim() === label) {
                    th.remove();
                }
            });
        }
    }
}

function addSsvColumn(label, catId) {
    if (!activeRateManager) return;
    const group  = document.getElementById(`grp_${catId}`);
    const subRow = group ? group.querySelector('thead tr.sub-row') : null;
    if (subRow) {
        const ssvHeaders = subRow.querySelectorAll('th[data-ssv-header]');
        const lastSsv    = ssvHeaders.length ? ssvHeaders[ssvHeaders.length - 1] : null;
        const newTh      = document.createElement('th');
        newTh.style.background  = '#f0f7ff';
        newTh.style.textAlign   = 'center';
        newTh.setAttribute('data-rate-header', label);
        newTh.setAttribute('data-ssv-header', '1');
        newTh.textContent = label;
        if (lastSsv) lastSsv.insertAdjacentElement('afterend', newTh);
        else subRow.appendChild(newTh);
    }
    activeRateManager.rows.forEach(row => {
        const id       = row.getAttribute('data-id');
        const ssvCells = row.querySelectorAll('td.ssv-col-cell');
        const lastCell = ssvCells.length ? ssvCells[ssvCells.length - 1] : null;
        const newTd    = document.createElement('td');
        newTd.className = 'ssv-col-cell';
        newTd.setAttribute('data-rate-col', label);
        newTd.setAttribute('data-desig', id);
        newTd.style.textAlign = 'center';
        newTd.innerHTML = `<div class="m-wrap" style="border-color:#c7d9f8;">
            <input type="number" class="m-input ssv-input" data-field="ssv" data-rate="${label}" data-id="${id}" placeholder="—" step="0.01" min="0">
        </div>`;
        if (lastCell) lastCell.insertAdjacentElement('afterend', newTd);
        else row.appendChild(newTd);
    });
}

function closeRateManager() {
    const el = document.getElementById('rate-manager-row');
    if (el) el.remove();
    activeRateManager = null;
}

let _toastTimer;
function showToast(msg, type = 'info') {
    const t = document.getElementById('toast');
    clearTimeout(_toastTimer);
    const icons = { success: 'circle-check', error: 'circle-exclamation', info: 'circle-info' };
    t.innerHTML = `<i class="fa-solid fa-${icons[type] || 'circle-info'}"></i> ${msg}`;
    t.className = `show t-${type}`;
    _toastTimer = setTimeout(() => { t.className = ''; }, 3800);
}

document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); saveAll(); }
});
</script>

<?php include 'footer.php'; ?>