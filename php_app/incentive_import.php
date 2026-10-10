<?php
/**
 * incentive_import.php
 * ─────────────────────────────────────────────────────────────────────────
 * Tab-wise (per staff category) Excel bulk import for incentive_entries.
 *
 * For each staff category (SR / MR / CC / ACC / ST / OFF) this page shows:
 *   - a payroll month selector (shared across tabs)
 *   - a dropdown of the Incentive Types that apply to that category
 *   - a "Download Template" button -> generates a .csv with one row per
 *     employee in that category, columns matching that incentive type's
 *     schema, pre-filled with any values already saved for the selected
 *     payroll month
 *   - an "Import Excel" upload -> re-reads that same column layout and
 *     upserts rows into incentive_entries for the selected type + month
 *
 * No external dependencies (plain CSV, no Composer/PhpSpreadsheet required).
 * Excel/Google Sheets/LibreOffice all open and re-save CSV natively.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * IMPORT ROBUSTNESS (2026 update)
 * ─────────────────────────────────────────────────────────────────────────
 * Real-world re-saved files do NOT always come back comma-delimited.
 * Depending on the user's Excel/LibreOffice locale and "Save As" dialog
 * choices, a file named "*.csv" can actually be tab-delimited or
 * semicolon-delimited. Previously this importer always parsed with a plain
 * comma via fgetcsv(), so a tab-delimited file collapsed every row into a
 * SINGLE field — only the leading numeric EmpDBID survived (because
 * intval() on a big string just reads the leading digits), while every
 * other column (achievement %, override amounts, reimbursements) silently
 * came through as empty and got replaced by auto-calculated defaults
 * instead of the values actually typed in the sheet.
 *
 * Fixes in this version:
 *   1. detect_csv_delimiter() sniffs the header line and picks whichever
 *      of comma / semicolon / tab actually splits it into columns.
 *   2. Columns are matched by HEADER TEXT, not fixed position, so
 *      reordered / renamed / extra columns in the sheet still map to the
 *      right field. Position is only used as a fallback when a header
 *      can't be found.
 *   3. clean_numeric() strips stray currency symbols / thousands-separator
 *      commas / spaces before numbers are parsed.
 *   4. After import, a detailed "Imported Details" preview table is shown:
 *      per employee, what was matched and every field/value actually
 *      written, plus a clear list of unmatched/skipped rows.
 * ─────────────────────────────────────────────────────────────────────────
 */

ob_start();
include 'config.php';

/** Write a CSV file to the output buffer and stop execution. */
function output_csv_download($filename, array $rows) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment;filename="'.$filename.'"');
    header('Cache-Control: max-age=0');
    // BOM so Excel on Windows opens UTF-8 correctly instead of mangling accents.
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

/**
 * Sniff which delimiter an uploaded file actually uses by counting
 * candidate delimiter characters on the header line. Handles files saved
 * as comma-CSV, semicolon-CSV (common with European Excel locales), or
 * tab-delimited text saved with a .csv extension (common from LibreOffice's
 * "Text CSV" export dialog when Tab was left selected as the field
 * separator).
 */
function detect_csv_delimiter($tmp_path) {
    $fh = fopen($tmp_path, 'r');
    if (!$fh) return ',';
    $firstLine = fgets($fh);
    fclose($fh);
    if ($firstLine === false) return ',';
    if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") $firstLine = substr($firstLine, 3);

    $candidates = [',', ';', "\t"];
    $best = ','; $bestCount = -1;
    foreach ($candidates as $d) {
        $count = substr_count($firstLine, $d);
        if ($count > $bestCount) { $bestCount = $count; $best = $d; }
    }
    return $bestCount > 0 ? $best : ',';
}

/**
 * Parse an uploaded CSV/TSV file into an array of rows (each row = array
 * of cell strings). Auto-detects the delimiter unless one is passed in
 * explicitly. Returns [rows, delimiter_used].
 */
function parse_csv_upload($tmp_path, $delimiter = null) {
    if ($delimiter === null) $delimiter = detect_csv_delimiter($tmp_path);
    $rows = [];
    $fh = fopen($tmp_path, 'r');
    if (!$fh) return [$rows, $delimiter];
    // Strip a UTF-8 BOM if present so the first header cell matches cleanly.
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);
    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
        // fgetcsv() can return [null] for a genuinely blank line; skip it.
        if ($row === [null]) continue;
        $rows[] = $row;
    }
    fclose($fh);
    return [$rows, $delimiter];
}

/** Normalize a header string for tolerant matching (trim, collapse spaces, lowercase). */
function normalize_header($s) {
    $s = trim((string)$s);
    $s = preg_replace('/\s+/', ' ', $s);
    return mb_strtolower($s);
}

/**
 * Strip currency symbols, thousands-separator commas, and stray whitespace
 * from a numeric cell before it's parsed with floatval(). Leaves the
 * decimal point and a leading minus sign intact.
 */
function clean_numeric($v) {
    $v = trim((string)$v);
    if ($v === '') return '';
    $v = preg_replace('/[^0-9.\-]/', '', $v);
    if ($v === '' || $v === '-' || $v === '.') return '';
    return $v;
}

/** Safely fetch a raw cell value from a data row given a resolved source column index. */
function row_val(array $rowVals, $srcIdx) {
    if ($srcIdx === null || !array_key_exists($srcIdx, $rowVals)) return '';
    return trim((string)$rowVals[$srcIdx]);
}

// ── Ensure incentive_entries exists (same definition as incentive_entry.php) ─
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

// ═════════════════════════════════════════════════════════════════════════
// SCHEMA DEFINITIONS (mirrors incentive_entry.php's $category_schemas)
// ═════════════════════════════════════════════════════════════════════════
$category_schemas = [
    'SR' => [
        'label' => 'Sales Rep (SR)',
        'incentive_groups' => [
            ['key'=>'total_ps_compliance', 'label'=>'Total PS Compliance', 'cfg_field'=>'total_ps_compliance', 'default_threshold'=>80],
            ['key'=>'eco_incentive',       'label'=>'ECO Incentive',        'cfg_field'=>'eco_incentive',       'default_threshold'=>95],
            ['key'=>'bp_incentive',        'label'=>'BP Incentive',         'cfg_field'=>'bp_incentive',        'default_threshold'=>90],
            ['key'=>'total_assortment',    'label'=>'Total Assortment',     'cfg_field'=>'total_assortment',    'default_threshold'=>100],
        ],
        'ssv_rates' => true,
        'has_other_incentive' => true,
        'reimb' => [['meal_reimbursement','Meal'],['traveling_reimbursement','Traveling'],['mobile_reimbursement','Mobile']],
        'reimb_direct' => false,
        'fields' => ['basic_salary','discretionary_support','total_ps_compliance','eco_incentive','bp_incentive','total_assortment','meal_reimbursement','traveling_reimbursement','mobile_reimbursement'],
    ],
    'MR' => [
        'label' => 'Merchandiser (MR)',
        'incentive_groups' => [
            ['key'=>'placement_compliance', 'label'=>'Placement Compliance', 'cfg_field'=>'placement_compliance_90', 'default_threshold'=>0],
        ],
        'mr_tier_groups' => [
            'placement_compliance' => [
                ['threshold'=>70,'cfg_field'=>'placement_compliance_70'],
                ['threshold'=>80,'cfg_field'=>'placement_compliance_80'],
                ['threshold'=>90,'cfg_field'=>'placement_compliance_90'],
            ],
        ],
        'ssv_rates' => false,
        'reimb' => [['meal_reimbursement','Meal'],['traveling_reimbursement','Traveling']],
        'reimb_direct' => false,
        'fields' => ['basic_salary','placement_compliance_70','placement_compliance_80','placement_compliance_90','meal_reimbursement','traveling_reimbursement'],
    ],
    'CC' => [
        'label' => 'Cash Collector (CC)',
        'incentive_groups' => [
            ['key'=>'ccfot',        'label'=>'CCFOT',       'cfg_field'=>'ccfot_90',      'default_threshold'=>90],
            ['key'=>'credit_mgt',   'label'=>'Credit Mgt',  'cfg_field'=>'credit_mgt_85', 'default_threshold'=>85],
            ['key'=>'payee_chq',    'label'=>'Payee Chq',   'cfg_field'=>'payee_chq_80',  'default_threshold'=>80],
            ['key'=>'ssv_cc',       'label'=>'SSV',         'cfg_field'=>'ssv_90',        'default_threshold'=>90],
            ['key'=>'attendance_cc','label'=>'Attendance',  'cfg_field'=>'attendance_85', 'default_threshold'=>85],
            ['key'=>'locus_admin',  'label'=>'Locus Admin', 'cfg_field'=>'locus_admin',   'default_threshold'=>100],
        ],
        'cc_tier_defs' => [
            'ccfot'         => [['threshold'=>90,'cfg_field'=>'ccfot_90'],['threshold'=>91,'cfg_field'=>'ccfot_91'],['threshold'=>92,'cfg_field'=>'ccfot_92'],['threshold'=>93,'cfg_field'=>'ccfot_93'],['threshold'=>94,'cfg_field'=>'ccfot_94'],['threshold'=>95,'cfg_field'=>'ccfot_95']],
            'credit_mgt'    => [['threshold'=>85,'cfg_field'=>'credit_mgt_85'],['threshold'=>90,'cfg_field'=>'credit_mgt_90'],['threshold'=>95,'cfg_field'=>'credit_mgt_95']],
            'payee_chq'     => [['threshold'=>80,'cfg_field'=>'payee_chq_80']],
            'ssv_cc'        => [['threshold'=>90,'cfg_field'=>'ssv_90'],['threshold'=>95,'cfg_field'=>'ssv_95']],
            'attendance_cc' => [['threshold'=>85,'cfg_field'=>'attendance_85'],['threshold'=>90,'cfg_field'=>'attendance_90']],
            'locus_admin'   => [['threshold'=>100,'cfg_field'=>'locus_admin']],
        ],
        'ssv_rates' => false,
        'reimb' => [['meal_reimbursement','Meal'],['mobile_reimbursement','Mobile']],
        'reimb_direct' => false,
        'fields' => ['basic_salary','ccfot_90','ccfot_91','ccfot_92','ccfot_93','ccfot_94','ccfot_95','credit_mgt_85','credit_mgt_90','credit_mgt_95','payee_chq_80','ssv_90','ssv_95','attendance_85','attendance_90','locus_admin','meal_reimbursement','mobile_reimbursement'],
    ],
    'ACC' => [
        'label' => 'Asst. Cash Collector (ACC)',
        'incentive_groups' => [
            ['key'=>'ccfot_acc',       'label'=>'CCFOT',       'cfg_field'=>'ccfot_90',       'default_threshold'=>90],
            ['key'=>'punctuality_acc', 'label'=>'Punctuality',  'cfg_field'=>'punctuality_70', 'default_threshold'=>70],
            ['key'=>'attendance_acc',  'label'=>'Attendance',   'cfg_field'=>'attendance_85',  'default_threshold'=>85],
        ],
        'acc_tier_defs' => [
            'ccfot_acc'       => [['threshold'=>90,'cfg_field'=>'ccfot_90'],['threshold'=>91,'cfg_field'=>'ccfot_91'],['threshold'=>92,'cfg_field'=>'ccfot_92'],['threshold'=>93,'cfg_field'=>'ccfot_93'],['threshold'=>94,'cfg_field'=>'ccfot_94'],['threshold'=>95,'cfg_field'=>'ccfot_95']],
            'punctuality_acc' => [['threshold'=>70,'cfg_field'=>'punctuality_70'],['threshold'=>80,'cfg_field'=>'punctuality_80']],
            'attendance_acc'  => [['threshold'=>85,'cfg_field'=>'attendance_85'],['threshold'=>90,'cfg_field'=>'attendance_90']],
        ],
        'ssv_rates' => true,
        'reimb' => [['meal_reimbursement','Meal']],
        'reimb_direct' => false,
        'fields' => ['basic_salary','ccfot_90','ccfot_91','ccfot_92','ccfot_93','ccfot_94','ccfot_95','punctuality_70','punctuality_80','attendance_85','attendance_90','meal_reimbursement'],
    ],
    'ST' => [
        'label' => 'Store Staff (ST)',
        'incentive_groups' => [
            ['key'=>'stores_damage',    'label'=>'Damage',          'cfg_field'=>'stores_damage_lo', 'default_threshold'=>0],
            ['key'=>'rsqm',             'label'=>'RSQM',            'cfg_field'=>'rsqm_60',          'default_threshold'=>60],
            ['key'=>'punctuality',      'label'=>'Punctuality',     'cfg_field'=>'punctuality_75',   'default_threshold'=>75],
            ['key'=>'loading_unloading','label'=>'L/Unloading 90%', 'cfg_field'=>'loading_unloading','default_threshold'=>90],
            ['key'=>'ccfot_st',         'label'=>'CCFOT 90%',       'cfg_field'=>'ccfot_st',         'default_threshold'=>90],
        ],
        'st_tier_groups' => [
            'rsqm'        => [['threshold'=>60,'cfg_field'=>'rsqm_60'],['threshold'=>70,'cfg_field'=>'rsqm_70']],
            'punctuality' => [['threshold'=>75,'cfg_field'=>'punctuality_75'],['threshold'=>85,'cfg_field'=>'punctuality_85']],
        ],
        'ssv_rates' => false,
        'reimb' => [['meal_reimbursement','Meal']],
        'reimb_direct' => false,
        'fields' => ['basic_salary','stores_damage','rsqm_60','rsqm_70','punctuality_75','punctuality_85','loading_unloading','ccfot_st','meal_reimbursement'],
    ],
    'OFF' => [
        'label' => 'Back Office (OFF)',
        'incentive_groups' => [
            ['key'=>'incentive_daily_90',  'label'=>'Daily Incentive',  'cfg_field'=>'incentive_daily_90',  'default_threshold'=>90],
            ['key'=>'incentive_weekly_90', 'label'=>'Weekly Incentive', 'cfg_field'=>'incentive_weekly_90', 'default_threshold'=>90],
            ['key'=>'incentive_monthly_90','label'=>'Monthly Incentive','cfg_field'=>'incentive_monthly_90','default_threshold'=>90],
            ['key'=>'punctuality_75',      'label'=>'Punct. 75%',       'cfg_field'=>'punctuality_75',      'default_threshold'=>75],
            ['key'=>'attendance_85',       'label'=>'Att. 85%',         'cfg_field'=>'attendance_85',       'default_threshold'=>85],
            ['key'=>'attendance_90',       'label'=>'Att. 90%',         'cfg_field'=>'attendance_90',       'default_threshold'=>90],
        ],
        'ssv_rates' => false,
        'reimb_direct' => true,
        'reimb' => [['meal_reimbursement','Meal']],
        'fields' => ['basic_salary','incentive_daily_90','incentive_weekly_90','incentive_monthly_90','punctuality_75','attendance_85','attendance_90','meal_reimbursement'],
    ],
];
$CAT_ORDER = ['SR','MR','CC','ACC','ST','OFF'];
$FIXED_REIMB_FIELDS = ['mobile_reimbursement'];

// ═════════════════════════════════════════════════════════════════════════
// HELPERS
// ═════════════════════════════════════════════════════════════════════════

function upsert_incentive_entry($conn, $type_id, $emp_id, $period_id, $label, $amount, $notes = '') {
    $label_esc  = mysqli_real_escape_string($conn, $label);
    $notes_esc  = mysqli_real_escape_string($conn, $notes);
    $amount     = floatval($amount);
    $type_id    = intval($type_id);
    $emp_id     = intval($emp_id);
    $period_id  = intval($period_id);

    $chk = mysqli_query($conn, "SELECT id FROM incentive_entries
        WHERE incentive_type_id=$type_id AND employee_id=$emp_id AND payroll_period_id=$period_id
          AND component_label='$label_esc'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        $r = mysqli_fetch_assoc($chk);
        mysqli_query($conn, "UPDATE incentive_entries SET amount='$amount', notes='$notes_esc', updated_at=NOW() WHERE id={$r['id']}");
    } else {
        mysqli_query($conn, "INSERT INTO incentive_entries
            (incentive_type_id,employee_id,payroll_period_id,component_label,amount,notes)
            VALUES ($type_id,$emp_id,$period_id,'$label_esc','$amount','$notes_esc')");
    }
    return $amount;
}

/** Resolve the earned amount for one incentive-group given an achievement %. */
function resolve_group_amount($cat_code, $ikey, $ach, $emp_cfg, $schema, $default_threshold, $cfg_field) {
    $ach = floatval($ach);
    if ($ach <= 0) return 0.0;

    if ($cat_code === 'MR' && isset($schema['mr_tier_groups'][$ikey])) {
        $best = 0.0; $best_thr = -1;
        foreach ($schema['mr_tier_groups'][$ikey] as $t) {
            $amt = floatval($emp_cfg[$t['cfg_field']] ?? 0);
            if ($ach >= $t['threshold'] && $t['threshold'] > $best_thr && $amt > 0) { $best = $amt; $best_thr = $t['threshold']; }
        }
        return $best;
    }
    if ($cat_code === 'ST' && isset($schema['st_tier_groups'][$ikey])) {
        $best = 0.0; $best_thr = -1;
        foreach ($schema['st_tier_groups'][$ikey] as $t) {
            $amt = floatval($emp_cfg[$t['cfg_field']] ?? 0);
            if ($ach >= $t['threshold'] && $t['threshold'] > $best_thr && $amt > 0) { $best = $amt; $best_thr = $t['threshold']; }
        }
        return $best;
    }
    if ($cat_code === 'CC' && isset($schema['cc_tier_defs'][$ikey])) {
        $best = 0.0; $best_thr = -1;
        foreach ($schema['cc_tier_defs'][$ikey] as $t) {
            $amt = floatval($emp_cfg[$t['cfg_field']] ?? 0);
            if ($ach >= $t['threshold'] && $t['threshold'] > $best_thr && $amt > 0) { $best = $amt; $best_thr = $t['threshold']; }
        }
        return $best;
    }
    if ($cat_code === 'ACC' && isset($schema['acc_tier_defs'][$ikey])) {
        $best = 0.0; $best_thr = -1;
        foreach ($schema['acc_tier_defs'][$ikey] as $t) {
            $amt = floatval($emp_cfg[$t['cfg_field']] ?? 0);
            if ($ach >= $t['threshold'] && $t['threshold'] > $best_thr && $amt > 0) { $best = $amt; $best_thr = $t['threshold']; }
        }
        return $best;
    }

    $cfg_amt = floatval($emp_cfg[$cfg_field] ?? 0);
    if ($default_threshold == 0) return $cfg_amt > 0 ? $cfg_amt : 0.0;
    return ($ach >= $default_threshold && $cfg_amt > 0) ? $cfg_amt : 0.0;
}

/** Attendance rate map for a set of employees within a payroll period (mirrors incentive_entry.php). */
function compute_attendance_rates($conn, $employees, $period) {
    $map = [];
    if (!$period || empty($employees)) return $map;

    $pp_year  = (int)$period['year'];
    $pp_month = (int)$period['month'];
    $from_date = sprintf('%04d-%02d-01', $pp_year, $pp_month);
    $to_date   = date('Y-m-t', strtotime($from_date));
    $month_total_days = (int)date('t', strtotime($from_date));

    $sh_res = mysqli_query($conn, "SELECT * FROM special_holidays WHERE active=1 AND date_from<='$to_date' AND date_to>='$from_date'");
    $sh_rows = [];
    if ($sh_res) while ($sh = mysqli_fetch_assoc($sh_res)) $sh_rows[] = $sh;

    $sh_date_map = [];
    foreach ($sh_rows as $sh) {
        $start = new DateTime(max($sh['date_from'], $from_date));
        $end   = new DateTime(min($sh['date_to'], $to_date));
        $end->modify('+1 day');
        foreach (new DatePeriod($start, new DateInterval('P1D'), $end) as $dt) {
            $d = $dt->format('Y-m-d');
            $sh_date_map[$d][] = $sh;
        }
    }

    $emp_ids = array_column($employees, 'id');
    $emp_ids_str = implode(',', array_map('intval', $emp_ids)) ?: '0';
    $att_res = mysqli_query($conn, "SELECT employee_id, COUNT(*) AS c FROM attendance
        WHERE employee_id IN ($emp_ids_str) AND att_date BETWEEN '$from_date' AND '$to_date' GROUP BY employee_id");
    $att_count = [];
    if ($att_res) while ($row = mysqli_fetch_assoc($att_res)) $att_count[(int)$row['employee_id']] = (int)$row['c'];

    foreach ($employees as $emp) {
        $eid = (int)$emp['id'];
        $holiday_count = 0;
        foreach ($sh_date_map as $date => $shs) {
            foreach ($shs as $sh) {
                $applies = false;
                if ($sh['target_type'] === 'all') $applies = true;
                elseif (!empty($sh['target_ids'])) {
                    $ids = json_decode($sh['target_ids'], true) ?? [];
                    if ($sh['target_type']==='employee' && in_array($eid,$ids)) $applies = true;
                    if ($sh['target_type']==='staff_category' && in_array((int)($emp['staff_category_id']??0),$ids)) $applies = true;
                    if ($sh['target_type']==='designation' && in_array((int)($emp['designation_id']??0),$ids)) $applies = true;
                }
                if ($applies) { $holiday_count++; break; }
            }
        }
        $norm_days = $month_total_days - $holiday_count;
        $rate = ($norm_days > 0) ? round((($att_count[$eid] ?? 0) / $norm_days) * 100, 2) : 0.0;
        $map[$eid] = ['rate' => $rate, 'norm_days' => $norm_days, 'att_count' => $att_count[$eid] ?? 0];
    }
    return $map;
}

function calc_reimb_amount($rfield, $rcfg, $att_rate, $reimb_direct, $fixed_fields) {
    if ($rcfg === null) return null;
    if ($reimb_direct || in_array($rfield, $fixed_fields, true)) return $rcfg;
    return round(($att_rate / 100) * $rcfg, 2);
}

function get_employees_for_category($conn, $cat_ids) {
    if (empty($cat_ids)) return [];
    $cat_in = implode(',', array_map('intval', $cat_ids));
    $sql = "SELECT e.id, e.employee_id, e.employee_full_name, e.telephone_mobile, e.designation_id,
                   COALESCE(e.staff_category_id, d.staff_category_id) AS staff_category_id,
                   d.designation_name
            FROM employees e
            LEFT JOIN designations d ON e.designation_id = d.id
            WHERE e.active = 1 AND e.status NOT IN ('Resigned','Terminated')
              AND (e.staff_category_id IN ($cat_in) OR d.staff_category_id IN ($cat_in))
            ORDER BY e.employee_full_name";
    $res = mysqli_query($conn, $sql);
    $out = [];
    if ($res) while ($row = mysqli_fetch_assoc($res)) $out[] = $row;
    return $out;
}

/**
 * Ordered column definitions for a given (category schema + incentive type).
 * Both the template writer and the import reader iterate this same list so
 * columns have a stable, well-known set of header labels. The IMPORT reader
 * additionally maps each of these back to whatever column position they
 * actually landed on in the uploaded file (see build_header_index_map()),
 * so column reordering in Excel no longer breaks the import.
 */
function build_columns($schema, $is_discretionary, $has_other_incentive) {
    $cols = [];
    $cols[] = ['type'=>'base', 'key'=>'emp_db_id',  'header'=>'EmpDBID (do not edit)'];
    $cols[] = ['type'=>'base', 'key'=>'emp_code',   'header'=>'Employee Code'];
    $cols[] = ['type'=>'base', 'key'=>'emp_name',   'header'=>'Employee Name'];
    $cols[] = ['type'=>'base', 'key'=>'designation','header'=>'Designation'];

    if ($is_discretionary) {
        $cols[] = ['type'=>'disc', 'key'=>'__disc', 'header'=>'Discretionary Amount'];
    }
    if (!empty($schema['ssv_rates'])) {
        $cols[] = ['type'=>'sec_ach', 'key'=>'__sec_ach', 'header'=>'Secondary Achievement %'];
        $cols[] = ['type'=>'ssv_amt', 'key'=>'__ssv_amt', 'header'=>'SSV Amount (override)'];
    }
    if ($has_other_incentive) {
        $cols[] = ['type'=>'other', 'key'=>'__other', 'header'=>'Other Incentive Amount'];
    }
    foreach ($schema['incentive_groups'] as $g) {
        $cols[] = ['type'=>'iach', 'key'=>$g['key'], 'cfg_field'=>$g['cfg_field'], 'threshold'=>$g['default_threshold'],
                   'header'=>$g['label'].' Achievement %'];
        $cols[] = ['type'=>'iamt', 'key'=>$g['key'], 'cfg_field'=>$g['cfg_field'], 'threshold'=>$g['default_threshold'],
                   'header'=>$g['label'].' Amount (override)'];
    }
    $cols[] = ['type'=>'attrate', 'key'=>'__attrate', 'header'=>'Attendance Rate % (reference only)'];
    foreach ($schema['reimb'] as $rc) {
        $cols[] = ['type'=>'reimb', 'key'=>$rc[0], 'header'=>$rc[1].' Reimbursement (blank = auto-calc)'];
    }
    return $cols;
}

/**
 * Build a map: index within $columns -> source column index in the
 * uploaded file's header row. Matches by normalized header text first
 * (so reordered / re-typed columns still resolve correctly); falls back
 * to the same positional index only when a header can't be found by name
 * at all, to stay compatible with older/hand-trimmed exports.
 *
 * Returns [ $colSrcIdx (array), $unmatchedHeaders (array of header labels
 * that had to fall back to position) ].
 */
function build_header_index_map(array $columns, array $headerRow) {
    $headerNorm = array_map('normalize_header', $headerRow);
    $colSrcIdx = [];
    $unmatched = [];

    foreach ($columns as $i => $c) {
        $wanted = normalize_header($c['header']);
        $foundAt = array_search($wanted, $headerNorm, true);
        if ($foundAt !== false) {
            $colSrcIdx[$i] = $foundAt;
        } elseif (array_key_exists($i, $headerRow)) {
            // Fallback: same position as in our expected column list.
            $colSrcIdx[$i] = $i;
            $unmatched[] = $c['header'];
        } else {
            $colSrcIdx[$i] = null;
            $unmatched[] = $c['header'];
        }
    }
    return [$colSrcIdx, $unmatched];
}

// ═════════════════════════════════════════════════════════════════════════
// SHARED LOOKUPS: staff_categories, incentive_types grouped by category code
// ═════════════════════════════════════════════════════════════════════════
$cat_id_to_code = [];
$code_to_cat_ids = [];
$sc_res = mysqli_query($conn, "SELECT id, category_code FROM staff_categories");
if ($sc_res) {
    while ($r = mysqli_fetch_assoc($sc_res)) {
        $code = strtoupper(trim($r['category_code'] ?? ''));
        if ($code === '') continue;
        $cat_id_to_code[$r['id']] = $code;
        $code_to_cat_ids[$code][] = $r['id'];
    }
}

$types_by_cat = []; // code => [ ['id'=>, 'type_name'=>, 'discretionary_support'=>, ...] ]
$it_res = mysqli_query($conn, "SELECT id, type_name, allowed_category_ids, discretionary_support FROM incentive_types");
if ($it_res) {
    while ($t = mysqli_fetch_assoc($it_res)) {
        $ids = array_filter(array_map('intval', explode(',', $t['allowed_category_ids'] ?? '')));
        $matched_codes = [];
        foreach ($ids as $cid) {
            if (isset($cat_id_to_code[$cid])) $matched_codes[$cat_id_to_code[$cid]] = true;
        }
        foreach (array_keys($matched_codes) as $code) {
            $types_by_cat[$code][] = $t;
        }
    }
}

// ── Payroll periods ─────────────────────────────────────────────────────────
$pp_cols = mysqli_query($conn, "SHOW COLUMNS FROM payroll_periods LIKE 'status'");
$pp_has_status = ($pp_cols && mysqli_num_rows($pp_cols) > 0);
$pp_sql = $pp_has_status
    ? "SELECT id, year, month, status FROM payroll_periods ORDER BY year DESC, month DESC"
    : "SELECT id, year, month, 'Open' AS status FROM payroll_periods ORDER BY year DESC, month DESC";
$all_periods = [];
$pp_res = mysqli_query($conn, $pp_sql);
if ($pp_res) while ($p = mysqli_fetch_assoc($pp_res)) $all_periods[] = $p;

$sel_period_id = isset($_GET['period_id']) ? intval($_GET['period_id']) : (isset($_POST['period_id']) ? intval($_POST['period_id']) : 0);
if (!$sel_period_id) {
    foreach ($all_periods as $pp) if ($pp['status'] === 'Open') { $sel_period_id = $pp['id']; break; }
    if (!$sel_period_id && !empty($all_periods)) $sel_period_id = $all_periods[0]['id'];
}
$active_period = null;
foreach ($all_periods as $pp) if ($pp['id'] == $sel_period_id) { $active_period = $pp; break; }

$month_names = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$active_cat  = isset($_GET['cat']) ? strtoupper($_GET['cat']) : (isset($_POST['cat']) ? strtoupper($_POST['cat']) : 'SR');
if (!in_array($active_cat, $CAT_ORDER)) $active_cat = 'SR';

// ═════════════════════════════════════════════════════════════════════════
// ACTION: DOWNLOAD TEMPLATE
// ═════════════════════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'template') {
    $type_id = intval($_GET['type_id'] ?? 0);
    $cat     = strtoupper($_GET['cat'] ?? '');
    $period_id = intval($_GET['period_id'] ?? $sel_period_id);

    if (!$type_id || !isset($category_schemas[$cat])) die('Invalid template request.');
    $schema = $category_schemas[$cat];

    $type_res = mysqli_query($conn, "SELECT * FROM incentive_types WHERE id=$type_id");
    $inc_type = $type_res ? mysqli_fetch_assoc($type_res) : null;
    if (!$inc_type) die('Incentive type not found.');
    $is_discretionary = !empty($inc_type['discretionary_support']);
    $has_other = !empty($schema['has_other_incentive']);

    $period_row = null;
    foreach ($all_periods as $pp) if ($pp['id'] == $period_id) { $period_row = $pp; break; }

    $employees = get_employees_for_category($conn, $code_to_cat_ids[$cat] ?? []);
    $desig_ids = array_unique(array_filter(array_column($employees, 'designation_id')));

    $emp_cfg_map = [];
    $emp_ssv_map = [];
    if (!empty($desig_ids)) {
        $did_in = implode(',', array_map('intval', $desig_ids));
        $fields = array_unique(array_merge(['designation_id','discretionary_support'], $schema['fields']));
        $sel = implode(',', array_map(fn($f) => "`$f`", $fields));
        $cfg_res = mysqli_query($conn, "SELECT $sel FROM sr_salary_config WHERE designation_id IN ($did_in)");
        $desig_cfg = [];
        if ($cfg_res) while ($row = mysqli_fetch_assoc($cfg_res)) $desig_cfg[$row['designation_id']] = $row;
        foreach ($employees as $e) $emp_cfg_map[$e['id']] = $desig_cfg[$e['designation_id']] ?? [];

        if (!empty($schema['ssv_rates'])) {
            $ssv_res = mysqli_query($conn, "SELECT designation_id, rate_label, amount FROM sr_secondary_sales_rates WHERE designation_id IN ($did_in) ORDER BY sort_order, id");
            $desig_ssv = [];
            if ($ssv_res) while ($row = mysqli_fetch_assoc($ssv_res)) $desig_ssv[$row['designation_id']][$row['rate_label']] = $row['amount'];
            foreach ($employees as $e) $emp_ssv_map[$e['id']] = $desig_ssv[$e['designation_id']] ?? [];
        }
    }

    $att_map = $period_row ? compute_attendance_rates($conn, $employees, $period_row) : [];

    $existing = [];
    if ($period_row) {
        $ex_res = mysqli_query($conn, "SELECT employee_id, component_label, amount FROM incentive_entries
            WHERE incentive_type_id=$type_id AND payroll_period_id={$period_row['id']}");
        if ($ex_res) while ($ex = mysqli_fetch_assoc($ex_res)) $existing[$ex['employee_id']][$ex['component_label']] = $ex['amount'];
    }

    $columns = build_columns($schema, $is_discretionary, $has_other);

    // Build CSV rows: header row, then one row per employee.
    $csv_rows = [];
    $csv_rows[] = array_map(fn($c) => $c['header'], $columns);

    foreach ($employees as $emp) {
        $eid = $emp['id'];
        $cfg = $emp_cfg_map[$eid] ?? [];
        $att = $att_map[$eid]['rate'] ?? 0.0;
        $line = [];
        foreach ($columns as $c) {
            $val = '';
            switch ($c['type']) {
                case 'base':
                    if ($c['key']==='emp_db_id')   $val = $eid;
                    if ($c['key']==='emp_code')    $val = $emp['employee_id'];
                    if ($c['key']==='emp_name')    $val = $emp['employee_full_name'];
                    if ($c['key']==='designation') $val = $emp['designation_name'] ?? '';
                    break;
                case 'disc':
                    $val = $existing[$eid]['__disc_amount'] ?? (floatval($cfg['discretionary_support'] ?? 0) ?: '');
                    break;
                case 'sec_ach':
                    $val = $existing[$eid]['__sec_achievement'] ?? '';
                    break;
                case 'ssv_amt':
                    $val = $existing[$eid]['__ssv_amount'] ?? '';
                    break;
                case 'other':
                    $val = $existing[$eid]['__other_incentive'] ?? '';
                    break;
                case 'iach':
                    $val = $existing[$eid]['__iach__'.$c['key']] ?? '';
                    break;
                case 'iamt':
                    $val = $existing[$eid]['__incent__'.$c['key']] ?? '';
                    break;
                case 'attrate':
                    $val = $att;
                    break;
                case 'reimb':
                    $rfield = $c['key'];
                    if (isset($existing[$eid]['__reimb__'.$rfield])) {
                        $val = $existing[$eid]['__reimb__'.$rfield];
                    } else {
                        $rcfg = isset($cfg[$rfield]) && $cfg[$rfield] !== '' ? (float)$cfg[$rfield] : null;
                        $calc = calc_reimb_amount($rfield, $rcfg, $att, !empty($schema['reimb_direct']), $GLOBALS['FIXED_REIMB_FIELDS']);
                        $val = $calc !== null ? $calc : '';
                    }
                    break;
            }
            $line[] = $val;
        }
        $csv_rows[] = $line;
    }

    $period_label = $period_row ? $month_names[$period_row['month']].'_'.$period_row['year'] : 'period';
    $type_name_safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $inc_type['type_name']);
    $filename = "incentive_{$cat}_{$type_name_safe}_{$period_label}.csv";

    output_csv_download($filename, $csv_rows);
}

// ═════════════════════════════════════════════════════════════════════════
// ACTION: IMPORT EXCEL (POST)
// ═════════════════════════════════════════════════════════════════════════
$import_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_import'])) {
    $type_id   = intval($_POST['type_id'] ?? 0);
    $cat       = strtoupper($_POST['cat'] ?? '');
    $period_id = intval($_POST['period_id'] ?? 0);

    if (!$type_id || !$period_id || !isset($category_schemas[$cat]) || empty($_FILES['import_file']['tmp_name'])) {
        $import_result = ['ok' => false, 'msg' => 'Missing incentive type, payroll period, category, or file.'];
    } else {
        $schema = $category_schemas[$cat];
        $type_res = mysqli_query($conn, "SELECT * FROM incentive_types WHERE id=$type_id");
        $inc_type = $type_res ? mysqli_fetch_assoc($type_res) : null;

        $period_row = null;
        foreach ($all_periods as $pp) if ($pp['id'] == $period_id) { $period_row = $pp; break; }

        if (!$inc_type || !$period_row) {
            $import_result = ['ok' => false, 'msg' => 'Incentive type or payroll period not found.'];
        } else {
            $is_discretionary = !empty($inc_type['discretionary_support']);
            $has_other = !empty($schema['has_other_incentive']);
            $columns = build_columns($schema, $is_discretionary, $has_other);
            $reimb_labels = [];
            foreach ($schema['reimb'] as $rc) $reimb_labels[$rc[0]] = $rc[1];

            $employees = get_employees_for_category($conn, $code_to_cat_ids[$cat] ?? []);
            $emp_by_id   = [];
            $emp_by_code = [];
            foreach ($employees as $e) { $emp_by_id[$e['id']] = $e; $emp_by_code[trim($e['employee_id'])] = $e; }

            $desig_ids = array_unique(array_filter(array_column($employees, 'designation_id')));
            $emp_cfg_map = [];
            if (!empty($desig_ids)) {
                $did_in = implode(',', array_map('intval', $desig_ids));
                $fields = array_unique(array_merge(['designation_id'], $schema['fields']));
                $sel = implode(',', array_map(fn($f) => "`$f`", $fields));
                $cfg_res = mysqli_query($conn, "SELECT $sel FROM sr_salary_config WHERE designation_id IN ($did_in)");
                $desig_cfg = [];
                if ($cfg_res) while ($row = mysqli_fetch_assoc($cfg_res)) $desig_cfg[$row['designation_id']] = $row;
                foreach ($employees as $e) $emp_cfg_map[$e['id']] = $desig_cfg[$e['designation_id']] ?? [];
            }

            $emp_ssv_map = [];
            if (!empty($schema['ssv_rates']) && !empty($desig_ids)) {
                $did_in = implode(',', array_map('intval', $desig_ids));
                $ssv_res = mysqli_query($conn, "SELECT designation_id, rate_label, amount FROM sr_secondary_sales_rates WHERE designation_id IN ($did_in)");
                $desig_ssv = [];
                if ($ssv_res) while ($row = mysqli_fetch_assoc($ssv_res)) $desig_ssv[$row['designation_id']][$row['rate_label']] = $row['amount'];
                foreach ($employees as $e) $emp_ssv_map[$e['id']] = $desig_ssv[$e['designation_id']] ?? [];
            }

            $att_map = compute_attendance_rates($conn, $employees, $period_row);

            try {
                list($all_rows, $used_delim) = parse_csv_upload($_FILES['import_file']['tmp_name']);
                if (empty($all_rows)) throw new \Exception('The uploaded file is empty or could not be read.');

                $header_row = $all_rows[0];
                $data_rows  = array_slice($all_rows, 1);

                // Map each expected column to wherever it actually landed in the
                // uploaded file — by header text first, position as fallback.
                list($colSrcIdx, $unmatchedHeaders) = build_header_index_map($columns, $header_row);

                // Locate the always-present identity columns via the map.
                $idxEmpDbId = null; $idxEmpCode = null;
                foreach ($columns as $i => $c) {
                    if ($c['key'] === 'emp_db_id') $idxEmpDbId = $colSrcIdx[$i];
                    if ($c['key'] === 'emp_code')   $idxEmpCode = $colSrcIdx[$i];
                }

                $saved = 0; $skipped = 0; $skipped_codes = [];
                $preview_rows = []; // for the "Imported Details" table

                foreach ($data_rows as $rowVals) {
                    // Skip fully blank rows.
                    $isBlank = true;
                    foreach ($rowVals as $cell) { if (trim((string)$cell) !== '') { $isBlank = false; break; } }
                    if ($isBlank) continue;

                    $eid_raw   = row_val($rowVals, $idxEmpDbId);
                    $emp_code  = row_val($rowVals, $idxEmpCode);
                    $eid = intval($eid_raw);
                    if (!$eid && $emp_code !== '' && isset($emp_by_code[$emp_code])) {
                        $eid = $emp_by_code[$emp_code]['id'];
                    }

                    if (!$eid || !isset($emp_by_id[$eid])) {
                        $skipped++;
                        if ($emp_code !== '') $skipped_codes[] = $emp_code;
                        $preview_rows[] = [
                            'matched' => false,
                            'code'    => $emp_code !== '' ? $emp_code : ($eid_raw !== '' ? $eid_raw : '—'),
                            'name'    => '(employee not matched)',
                            'fields'  => [],
                        ];
                        continue;
                    }

                    $emp = $emp_by_id[$eid];
                    $cfg = $emp_cfg_map[$eid] ?? [];
                    $att = $att_map[$eid]['rate'] ?? 0.0;
                    $norm_days = $att_map[$eid]['norm_days'] ?? 0;

                    $row_summary = []; // human-readable "Field: value" lines for the preview table
                    $recordField = function ($label, $val) use (&$row_summary) {
                        if ($val === null || $val === '') return;
                        $row_summary[] = $label . ': ' . (is_numeric($val) ? number_format((float)$val, 2) : $val);
                    };

                    $ci = 4; // first 4 columns are always the base identity columns

                    if ($is_discretionary) {
                        $v = clean_numeric(row_val($rowVals, $colSrcIdx[$ci] ?? null));
                        if ($v !== '') {
                            upsert_incentive_entry($conn, $type_id, $eid, $period_id, '__disc_amount', $v);
                            $recordField('Discretionary', $v);
                            $saved++;
                        }
                        $ci++;
                    }

                    if (!empty($schema['ssv_rates'])) {
                        $ach_v         = clean_numeric(row_val($rowVals, $colSrcIdx[$ci] ?? null)); $ci++;
                        $ssv_override  = clean_numeric(row_val($rowVals, $colSrcIdx[$ci] ?? null)); $ci++;

                        if ($ach_v !== '') {
                            upsert_incentive_entry($conn, $type_id, $eid, $period_id, '__sec_achievement', $ach_v);
                            $recordField('Secondary Achievement %', $ach_v . '%');
                            $saved++;
                        }
                        $ssv_final = null;
                        if ($ssv_override !== '') {
                            $ssv_final = floatval($ssv_override);
                        } elseif ($ach_v !== '') {
                            $rates = $emp_ssv_map[$eid] ?? [];
                            $ach = floatval($ach_v); $best = -1; $best_amt = 0;
                            foreach ($rates as $label => $amount) {
                                $lv = floatval(str_replace('%','',$label));
                                if ($lv <= $ach && $lv > $best) { $best = $lv; $best_amt = floatval($amount); }
                            }
                            if ($best >= 0) $ssv_final = $best_amt;
                        }
                        if ($ssv_final !== null) {
                            upsert_incentive_entry($conn, $type_id, $eid, $period_id, '__ssv_amount', $ssv_final);
                            $recordField('SSV Amount', $ssv_final);
                            $saved++;
                        }
                    }

                    if ($has_other) {
                        $v = clean_numeric(row_val($rowVals, $colSrcIdx[$ci] ?? null));
                        if ($v !== '') {
                            upsert_incentive_entry($conn, $type_id, $eid, $period_id, '__other_incentive', $v);
                            $recordField('Other Incentive', $v);
                            $saved++;
                        }
                        $ci++;
                    }

                    foreach ($schema['incentive_groups'] as $g) {
                        $ach_v        = clean_numeric(row_val($rowVals, $colSrcIdx[$ci] ?? null)); $ci++;
                        $amt_override = clean_numeric(row_val($rowVals, $colSrcIdx[$ci] ?? null)); $ci++;

                        if ($ach_v !== '') {
                            upsert_incentive_entry($conn, $type_id, $eid, $period_id, '__iach__'.$g['key'], $ach_v);
                            $recordField($g['label'].' Ach.%', $ach_v . '%');
                            $saved++;
                        }
                        $final_amt = null;
                        if ($amt_override !== '') {
                            $final_amt = floatval($amt_override);
                        } elseif ($ach_v !== '') {
                            $final_amt = resolve_group_amount($cat, $g['key'], $ach_v, $cfg, $schema, $g['default_threshold'], $g['cfg_field']);
                        }
                        if ($final_amt !== null) {
                            upsert_incentive_entry($conn, $type_id, $eid, $period_id, '__incent__'.$g['key'], $final_amt);
                            $recordField($g['label'].' Amount', $final_amt);
                            $saved++;
                        }
                    }

                    $ci++; // skip attendance-rate reference column

                    foreach ($schema['reimb'] as $rc) {
                        $rfield = $rc[0];
                        $v = clean_numeric(row_val($rowVals, $colSrcIdx[$ci] ?? null)); $ci++;
                        $final = null;
                        if ($v !== '') {
                            $final = floatval($v);
                        } else {
                            $rcfg = isset($cfg[$rfield]) && $cfg[$rfield] !== '' ? (float)$cfg[$rfield] : null;
                            $final = calc_reimb_amount($rfield, $rcfg, $att, !empty($schema['reimb_direct']), $FIXED_REIMB_FIELDS);
                        }
                        if ($final !== null) {
                            upsert_incentive_entry($conn, $type_id, $eid, $period_id, '__reimb__'.$rfield, $final);
                            $recordField(($reimb_labels[$rfield] ?? $rfield) . ' Reimb.', $final);
                            $saved++;
                        }
                    }

                    $preview_rows[] = [
                        'matched' => true,
                        'code'    => $emp['employee_id'],
                        'name'    => $emp['employee_full_name'],
                        'fields'  => $row_summary,
                    ];
                }

                $delim_label = $used_delim === "\t" ? 'Tab' : ($used_delim === ';' ? 'Semicolon' : 'Comma');
                $msg = "Import complete for {$month_names[$period_row['month']]} {$period_row['year']}: "
                     . "$saved values saved across " . count(array_filter($preview_rows, fn($r) => $r['matched'])) . " employee(s)"
                     . " (detected file format: $delim_label-delimited)."
                     . ($skipped ? " $skipped row(s) skipped — employee not matched: "
                        . htmlspecialchars(implode(', ', array_slice($skipped_codes, 0, 15))) . (count($skipped_codes) > 15 ? '…' : '') . "." : "");

                $import_result = [
                    'ok'      => true,
                    'msg'     => $msg,
                    'preview' => $preview_rows,
                    'unmatched_headers' => $unmatchedHeaders,
                ];
            } catch (\Throwable $e) {
                $import_result = ['ok' => false, 'msg' => 'Import failed: ' . htmlspecialchars($e->getMessage())];
            }
        }
    }
}

include 'header.php';
?>
<div class="im-page-header">
    <div>
        <div class="im-breadcrumb"><span>Incentive Types</span><i class="fa-solid fa-chevron-right"></i><span>Bulk Excel Import</span></div>
        <h1 class="im-page-title">Incentive Bulk Excel Import</h1>
        <p class="im-page-sub">Download a per-category template, fill it in Excel, and re-upload it for a chosen payroll month.</p>
    </div>
    <form method="GET" id="periodForm" class="im-period-form">
        <input type="hidden" name="cat" id="periodFormCat" value="<?php echo htmlspecialchars($active_cat); ?>">
        <div class="im-period-selector">
            <i class="fa-solid fa-calendar-check"></i>
            <div>
                <span class="im-period-label">Payroll Period</span>
                <?php if (!empty($all_periods)): ?>
                <select name="period_id" class="im-period-select" onchange="this.form.submit()">
                    <?php foreach ($all_periods as $pp): ?>
                    <option value="<?php echo $pp['id']; ?>" <?php echo $sel_period_id==$pp['id']?'selected':''; ?>>
                        <?php echo $month_names[$pp['month']].' '.$pp['year']; echo ($pp['status']!=='Open')?' ('.htmlspecialchars($pp['status']).')':''; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <span class="im-no-period">No payroll periods found</span>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<?php if ($import_result): ?>
<div class="im-alert im-alert-<?php echo $import_result['ok'] ? 'success' : 'danger'; ?>">
    <i class="fa-solid <?php echo $import_result['ok'] ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
    <?php echo $import_result['msg']; ?>
    <button onclick="this.parentElement.remove()" class="im-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>

<?php if ($import_result['ok'] && !empty($import_result['unmatched_headers'])): ?>
<div class="im-alert im-alert-warning">
    <i class="fa-solid fa-circle-info"></i>
    Note: <?php echo count($import_result['unmatched_headers']); ?> column header(s) in your file didn't exactly
    match the template and were matched by position instead:
    <b><?php echo htmlspecialchars(implode(', ', $import_result['unmatched_headers'])); ?></b>.
    If your saved file has a different column order than the downloaded template, double-check the details below.
    <button onclick="this.parentElement.remove()" class="im-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>

<?php if ($import_result['ok'] && !empty($import_result['preview'])): ?>
<div class="im-preview-wrap">
    <div class="im-preview-header">
        <i class="fa-solid fa-list-check"></i> Imported Details
        <span class="im-preview-sub">Exactly what was read from your file and saved for this payroll period — verify it matches what you typed.</span>
    </div>
    <div class="im-preview-scroll">
        <table class="im-preview-table">
            <thead><tr><th>#</th><th>Status</th><th>Employee Code</th><th>Employee Name</th><th>Saved Values</th></tr></thead>
            <tbody>
                <?php $pn = 0; foreach ($import_result['preview'] as $pr): $pn++; ?>
                <tr class="<?php echo $pr['matched'] ? 'im-row-ok' : 'im-row-skip'; ?>">
                    <td><?php echo $pn; ?></td>
                    <td>
                        <?php if ($pr['matched']): ?>
                        <span class="im-badge im-badge-ok"><i class="fa-solid fa-check"></i> Saved</span>
                        <?php else: ?>
                        <span class="im-badge im-badge-skip"><i class="fa-solid fa-xmark"></i> Skipped</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($pr['code']); ?></td>
                    <td><?php echo htmlspecialchars($pr['name']); ?></td>
                    <td>
                        <?php if (!empty($pr['fields'])): ?>
                        <div class="im-field-chips">
                            <?php foreach ($pr['fields'] as $f): ?>
                            <span class="im-field-chip"><?php echo htmlspecialchars($f); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <span class="im-empty-cell">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="im-tabs">
    <?php foreach ($CAT_ORDER as $code): $sch = $category_schemas[$code]; ?>
    <button type="button" class="im-tab <?php echo $active_cat === $code ? 'active' : ''; ?>" onclick="switchTab('<?php echo $code; ?>')">
        <?php echo htmlspecialchars($sch['label']); ?>
    </button>
    <?php endforeach; ?>
</div>

<?php foreach ($CAT_ORDER as $code):
    $schema = $category_schemas[$code];
    $cat_ids = $code_to_cat_ids[$code] ?? [];
    $employees = get_employees_for_category($conn, $cat_ids);
    $types = $types_by_cat[$code] ?? [];
    $is_visible = ($active_cat === $code);
?>
<div class="im-tab-panel <?php echo $is_visible ? 'visible' : ''; ?>" id="panel_<?php echo $code; ?>">

    <?php if (empty($types)): ?>
    <div class="im-empty-state">
        <i class="fa-solid fa-triangle-exclamation"></i>
        No Incentive Types are configured for <?php echo htmlspecialchars($schema['label']); ?> yet.
        <a href="incentive_types.php" class="im-link">Create one →</a>
    </div>
    <?php else: ?>

    <div class="im-cat-toolbar">
        <div class="im-cat-stat"><span class="im-cat-stat-num"><?php echo count($employees); ?></span><span class="im-cat-stat-lbl">Employees</span></div>

        <div class="im-cat-type-select">
            <label>Incentive Type</label>
            <select id="typeSelect_<?php echo $code; ?>">
                <?php foreach ($types as $t): ?>
                <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['type_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <a class="im-btn im-btn-download" id="downloadBtn_<?php echo $code; ?>" href="#" onclick="return downloadTemplate('<?php echo $code; ?>')">
            <i class="fa-solid fa-file-arrow-down"></i> Download Template
        </a>

        <form method="POST" enctype="multipart/form-data" class="im-import-form" onsubmit="return prepImportForm('<?php echo $code; ?>');">
            <input type="hidden" name="do_import" value="1">
            <input type="hidden" name="cat" value="<?php echo $code; ?>">
            <input type="hidden" name="period_id" value="<?php echo $sel_period_id; ?>">
            <input type="hidden" name="type_id" id="typeIdHidden_<?php echo $code; ?>" value="">
            <label class="im-file-btn">
                <i class="fa-solid fa-file-csv"></i> Choose CSV File
                <input type="file" name="import_file" accept=".csv,text/csv,.txt,text/plain" required onchange="this.closest('form').querySelector('.im-file-name').textContent=this.files[0]?.name || '';">
            </label>
            <span class="im-file-name"></span>
            <button type="submit" class="im-btn im-btn-import"><i class="fa-solid fa-upload"></i> Import</button>
        </form>
    </div>

    <div class="im-hint-bar">
        <i class="fa-solid fa-circle-info"></i>
        Achievement % columns auto-calculate the earned amount using each employee's configured rates/tiers.
        Leave an "Amount (override)" or reimbursement cell blank to use the auto-calculated value, or type a number to force it.
        The <b>EmpDBID</b> column must not be edited — it's how rows are matched back to employees.
        Comma, semicolon, and tab-delimited CSV files are all accepted automatically.
    </div>

    <div class="im-emp-preview-wrap">
        <table class="im-emp-preview">
            <thead><tr><th>#</th><th>Employee Code</th><th>Employee Name</th><th>Designation</th><th>Mobile</th></tr></thead>
            <tbody>
                <?php $n=0; foreach ($employees as $e): $n++; ?>
                <tr>
                    <td><?php echo $n; ?></td>
                    <td><?php echo htmlspecialchars($e['employee_id']); ?></td>
                    <td><?php echo htmlspecialchars($e['employee_full_name']); ?></td>
                    <td><?php echo htmlspecialchars($e['designation_name'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($e['telephone_mobile'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($employees)): ?>
                <tr><td colspan="5" class="im-empty-row">No active employees in this category.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php endif; ?>
</div>
<?php endforeach; ?>

<style>
:root{
  --im-white:#fff; --im-border:#e2e5ea; --im-text:#111827; --im-muted:#6b7280;
  --im-accent:#18181b; --im-blue:#2563eb; --im-green:#16a34a; --im-orange:#c2410c;
  --im-sans:'DM Sans',sans-serif; --im-mono:'JetBrains Mono',monospace;
}
.im-page-header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:14px;flex-wrap:wrap;}
.im-breadcrumb{display:flex;align-items:center;gap:5px;font-size:10px;color:var(--im-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;}
.im-breadcrumb i{font-size:8px;}
.im-page-title{font-size:20px;font-weight:700;color:var(--im-text);margin:0 0 3px;font-family:var(--im-sans);}
.im-page-sub{font-size:12px;color:var(--im-muted);margin:0;font-family:var(--im-sans);}
.im-period-selector{display:flex;align-items:center;gap:10px;background:var(--im-white);border:1.5px solid var(--im-border);border-radius:10px;padding:8px 14px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.im-period-selector i{color:var(--im-blue);font-size:16px;}
.im-period-label{display:block;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--im-muted);}
.im-period-select{border:none;outline:none;font-size:13px;font-weight:700;color:var(--im-text);background:transparent;cursor:pointer;font-family:var(--im-sans);}
.im-no-period{font-size:12px;color:#dc2626;font-weight:600;}

.im-alert{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:8px;font-size:13px;font-weight:500;margin-bottom:12px;}
.im-alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;}
.im-alert-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
.im-alert-warning{background:#fffbeb;border:1px solid #fde68a;color:#92400e;}
.im-alert-close{margin-left:auto;background:none;border:none;cursor:pointer;color:inherit;opacity:.6;}
.im-link{color:var(--im-blue);font-weight:600;text-decoration:none;margin-left:5px;}

.im-preview-wrap{background:var(--im-white);border:1.5px solid var(--im-border);border-radius:12px;overflow:hidden;margin-bottom:16px;}
.im-preview-header{display:flex;flex-direction:column;gap:2px;padding:12px 16px;background:#18181b;color:#fff;font-size:13px;font-weight:700;font-family:var(--im-sans);}
.im-preview-header i{margin-right:6px;color:#6ee7b7;}
.im-preview-sub{font-size:11px;font-weight:500;color:#9ca3af;margin-left:20px;}
.im-preview-scroll{max-height:460px;overflow-y:auto;}
.im-preview-table{width:100%;border-collapse:collapse;font-size:12px;font-family:var(--im-sans);}
.im-preview-table thead{background:#f3f4f6;position:sticky;top:0;}
.im-preview-table th{padding:8px 10px;text-align:left;color:var(--im-muted);font-size:10px;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid var(--im-border);}
.im-preview-table td{padding:8px 10px;border-bottom:1px solid #f3f4f6;color:var(--im-text);vertical-align:top;}
.im-preview-table tbody tr:hover{background:#f8faff;}
.im-row-skip{background:#fff7f7;}
.im-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}
.im-badge-ok{background:#dcfce7;color:#166534;}
.im-badge-skip{background:#fee2e2;color:#991b1b;}
.im-field-chips{display:flex;flex-wrap:wrap;gap:4px;}
.im-field-chip{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;padding:2px 7px;font-size:10.5px;font-family:var(--im-mono);white-space:nowrap;}
.im-empty-cell{color:#d1d5db;}

.im-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;border-bottom:1.5px solid var(--im-border);padding-bottom:0;}
.im-tab{padding:9px 18px;border:none;background:transparent;font-size:13px;font-weight:700;color:var(--im-muted);cursor:pointer;border-bottom:3px solid transparent;font-family:var(--im-sans);transition:all .15s;}
.im-tab:hover{color:var(--im-text);}
.im-tab.active{color:var(--im-accent);border-bottom-color:var(--im-accent);}

.im-tab-panel{display:none;}
.im-tab-panel.visible{display:block;}

.im-empty-state{background:#fff7ed;border:1px solid #fdba74;border-radius:10px;padding:20px;font-size:13px;color:#92400e;display:flex;align-items:center;gap:8px;}

.im-cat-toolbar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:var(--im-white);border:1.5px solid var(--im-border);border-radius:12px;padding:14px 16px;margin-bottom:12px;}
.im-cat-stat{display:flex;flex-direction:column;align-items:center;background:#f9fafb;border:1px solid var(--im-border);border-radius:8px;padding:6px 14px;min-width:70px;}
.im-cat-stat-num{font-size:17px;font-weight:700;font-family:var(--im-mono);}
.im-cat-stat-lbl{font-size:9px;color:var(--im-muted);text-transform:uppercase;letter-spacing:.4px;font-weight:600;}
.im-cat-type-select{display:flex;flex-direction:column;gap:2px;}
.im-cat-type-select label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--im-muted);}
.im-cat-type-select select{border:1.5px solid var(--im-border);border-radius:7px;padding:7px 10px;font-size:13px;font-family:var(--im-sans);min-width:220px;color:var(--im-text);}

.im-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:var(--im-sans);text-decoration:none;white-space:nowrap;transition:all .15s;}
.im-btn-download{background:#eff6ff;color:var(--im-blue);border:1.5px solid #bfdbfe;}
.im-btn-download:hover{background:#dbeafe;}
.im-btn-import{background:var(--im-accent);color:#fff;}
.im-btn-import:hover{background:#374151;}

.im-import-form{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.im-file-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border:1.5px dashed #fdba74;background:#fff7ed;color:var(--im-orange);border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;}
.im-file-btn input{display:none;}
.im-file-name{font-size:11px;color:var(--im-muted);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

.im-hint-bar{display:flex;align-items:center;gap:8px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:9px 14px;font-size:12px;color:#0369a1;margin-bottom:12px;}

.im-emp-preview-wrap{background:var(--im-white);border:1.5px solid var(--im-border);border-radius:12px;overflow:hidden;max-height:420px;overflow-y:auto;}
.im-emp-preview{width:100%;border-collapse:collapse;font-size:12px;font-family:var(--im-sans);}
.im-emp-preview thead{background:#18181b;position:sticky;top:0;}
.im-emp-preview th{padding:8px 10px;text-align:left;color:#9ca3af;font-size:10px;text-transform:uppercase;letter-spacing:.4px;}
.im-emp-preview td{padding:7px 10px;border-bottom:1px solid #f3f4f6;color:var(--im-text);}
.im-emp-preview tbody tr:hover{background:#f8faff;}
.im-empty-row{text-align:center;color:var(--im-muted);padding:20px;}
</style>

<script>
const ACTIVE_PERIOD_ID = <?php echo $sel_period_id; ?>;

function switchTab(code) {
    document.querySelectorAll('.im-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.im-tab-panel').forEach(p => p.classList.remove('visible'));
    document.querySelectorAll('.im-tab').forEach(t => { if (t.textContent.includes(code)) {} });
    const panel = document.getElementById('panel_' + code);
    if (panel) panel.classList.add('visible');
    [...document.querySelectorAll('.im-tab')].forEach(t => {
        if (t.getAttribute('onclick') === "switchTab('" + code + "')") t.classList.add('active');
    });
    document.getElementById('periodFormCat').value = code;
    const url = new URL(window.location);
    url.searchParams.set('cat', code);
    window.history.replaceState({}, '', url);
}

function downloadTemplate(code) {
    const sel = document.getElementById('typeSelect_' + code);
    if (!sel || !sel.value) { alert('No Incentive Type selected for this category.'); return false; }
    const url = 'incentive_import.php?action=template&cat=' + encodeURIComponent(code)
        + '&type_id=' + encodeURIComponent(sel.value)
        + '&period_id=' + encodeURIComponent(ACTIVE_PERIOD_ID);
    window.location.href = url;
    return false;
}

function prepImportForm(code) {
    const sel = document.getElementById('typeSelect_' + code);
    const hidden = document.getElementById('typeIdHidden_' + code);
    if (!sel || !sel.value) { alert('Select an Incentive Type before importing.'); return false; }
    hidden.value = sel.value;
    return confirm('Import this file into "' + sel.options[sel.selectedIndex].text + '" for the selected payroll period? Existing saved values for matching rows will be overwritten.');
}
</script>

<?php include 'footer.php'; ?>