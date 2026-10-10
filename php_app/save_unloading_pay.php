<?php
/* ══════════════════════════════════════════════════════════════════
   save_unloading_pay.php  —  CLEAN REWRITE
   Saves pay allocation for one unloading_data row.

   Steps:
     1. Read + validate JSON input
     2. Auto-create / alter tables as needed
     3. Fetch SKU info for description
     4. Overwrite unloading_pay_transactions for this row
     5. Update unloading_data (charge, absorb, variance)
     6. Overwrite payroll_payments_log (keyed by employee_id, NOT month)
     7. Return JSON success
   ══════════════════════════════════════════════════════════════════ */

/* ── Kill ALL output buffering so no stray HTML leaks in ── */
while (ob_get_level() > 0) ob_end_clean();
error_reporting(0);
ini_set('display_errors', 0);

/* ── Load config cleanly ── */
ob_start();
require_once 'config.php';
ob_end_clean();

/* ── Always respond with JSON ── */
header('Content-Type: application/json; charset=utf-8');

/* ════════════════════════════════════════════════════════════════
   HELPER: safe json response and exit
   ════════════════════════════════════════════════════════════════ */
function respond($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ════════════════════════════════════════════════════════════════
   1. METHOD + INPUT
   ════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Invalid request method']);
}

$raw = file_get_contents('php://input');
if (empty($raw)) {
    respond(['success' => false, 'message' => 'No data received']);
}

$p = json_decode($raw, true);
if (!is_array($p)) {
    respond(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
}

/* ── Extract fields ── */
$detail_id = intval($p['import_detail_id'] ?? 0);
$import_id = intval($p['import_id']        ?? 0);

if ($detail_id <= 0 || $import_id <= 0) {
    respond(['success' => false, 'message' => "Invalid IDs (detail=$detail_id import=$import_id)"]);
}

$charges   = isset($p['charges']) && is_array($p['charges']) ? $p['charges'] : [];
$absorb    = floatval($p['absorb_amount'] ?? 0);
$se_value  = floatval($p['se_value']      ?? 0);
$variance  = floatval($p['variance']      ?? 0);

/* Optional global payroll period */
$pp_id    = !empty($p['payroll_period_id']) ? intval($p['payroll_period_id']) : null;
$pp_year  = !empty($p['payroll_year'])      ? intval($p['payroll_year'])      : null;
$pp_month = !empty($p['payroll_month'])     ? intval($p['payroll_month'])     : null;

/* Global charge date fallback */
$g_date   = !empty($p['charge_date']) ? $p['charge_date'] : date('Y-m-d');
$g_date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $g_date) ? $g_date : date('Y-m-d');

$today = date('Y-m-d');

/* ════════════════════════════════════════════════════════════════
   2. ENSURE TABLES EXIST / HAVE NEEDED COLUMNS
   ════════════════════════════════════════════════════════════════ */

/* ── unloading_pay_transactions ── */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `unloading_pay_transactions` (
        `id`                  INT AUTO_INCREMENT PRIMARY KEY,
        `import_detail_id`    INT          NOT NULL,
        `import_id`           INT          NOT NULL,
        `transaction_date`    DATE         NOT NULL,
        `entry_type`          VARCHAR(20)  NOT NULL,
        `employee_id`         INT          NULL,
        `employee_name`       VARCHAR(200) NULL,
        `amount`              DECIMAL(12,2) DEFAULT 0,
        `se_value`            DECIMAL(12,2) DEFAULT 0,
        `qty`                 DECIMAL(12,4) DEFAULT NULL,
        `price`               DECIMAL(12,2) DEFAULT NULL,
        `payroll_month_label` VARCHAR(100)  DEFAULT NULL,
        `pay_date`            DATE          DEFAULT NULL,
        `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_d` (`import_detail_id`),
        INDEX `idx_i` (`import_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

/* Add missing columns to existing unloading_pay_transactions */
$upt_existing = [];
$r = mysqli_query($conn, "SHOW COLUMNS FROM `unloading_pay_transactions`");
if ($r) while ($row = mysqli_fetch_assoc($r)) $upt_existing[] = $row['Field'];

$upt_add = [];
if (!in_array('qty',                 $upt_existing)) $upt_add[] = "ADD COLUMN `qty`                 DECIMAL(12,4) DEFAULT NULL";
if (!in_array('price',               $upt_existing)) $upt_add[] = "ADD COLUMN `price`               DECIMAL(12,2) DEFAULT NULL";
if (!in_array('payroll_month_label', $upt_existing)) $upt_add[] = "ADD COLUMN `payroll_month_label` VARCHAR(100)  DEFAULT NULL";
if (!in_array('pay_date',            $upt_existing)) $upt_add[] = "ADD COLUMN `pay_date`            DATE          DEFAULT NULL";
if (!empty($upt_add)) {
    mysqli_query($conn, "ALTER TABLE `unloading_pay_transactions` " . implode(', ', $upt_add));
}

/* ── payroll_payments_log ── */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `payroll_payments_log` (
        `id`                INT AUTO_INCREMENT PRIMARY KEY,
        `employee_id`       INT           NOT NULL,
        `employee_name`     VARCHAR(200)  NOT NULL,
        `payroll_period_id` INT           DEFAULT NULL,
        `payroll_year`      INT           DEFAULT NULL,
        `payroll_month`     INT           DEFAULT NULL,
        `amount`            DECIMAL(12,2) NOT NULL,
        `charge_date`       DATE          NOT NULL,
        `description`       VARCHAR(500)  DEFAULT 'cash shortage',
        `reference_id`      INT           DEFAULT NULL,
        `reference_type`    VARCHAR(50)   DEFAULT NULL,
        `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_emp`    (`employee_id`),
        INDEX `idx_period` (`payroll_period_id`),
        INDEX `idx_ref`    (`reference_id`, `reference_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

/* Add missing reference columns to existing payroll_payments_log */
$ppl_existing = [];
$r2 = mysqli_query($conn, "SHOW COLUMNS FROM `payroll_payments_log`");
if ($r2) while ($row = mysqli_fetch_assoc($r2)) $ppl_existing[] = $row['Field'];

$ppl_add = [];
if (!in_array('reference_id',   $ppl_existing)) $ppl_add[] = "ADD COLUMN `reference_id`   INT         DEFAULT NULL";
if (!in_array('reference_type', $ppl_existing)) $ppl_add[] = "ADD COLUMN `reference_type` VARCHAR(50) DEFAULT NULL";
if (!empty($ppl_add)) {
    mysqli_query($conn, "ALTER TABLE `payroll_payments_log` " . implode(', ', $ppl_add));
    mysqli_query($conn, "ALTER TABLE `payroll_payments_log` ADD INDEX `idx_ref` (`reference_id`, `reference_type`)");
}

/* ════════════════════════════════════════════════════════════════
   3. FETCH SKU INFO FOR DESCRIPTION
   ════════════════════════════════════════════════════════════════ */
$sku_code = '';
$sku_desc = '';

$sq = mysqli_query($conn, "SELECT sku_code, sku_desc FROM `unloading_data` WHERE id = $detail_id LIMIT 1");
if ($sq && $ud = mysqli_fetch_assoc($sq)) {
    $sku_code = trim($ud['sku_code'] ?? '');
    $sku_desc = trim($ud['sku_desc'] ?? '');
}
if (empty($sku_code)) {
    $sq2 = mysqli_query($conn, "SELECT sku_code, sku_desc FROM `unloading_summary_import_details` WHERE id = $detail_id LIMIT 1");
    if ($sq2 && $ud2 = mysqli_fetch_assoc($sq2)) {
        $sku_code = trim($ud2['sku_code'] ?? '');
        $sku_desc = trim($ud2['sku_desc'] ?? '');
    }
}

$desc_parts = ['unloading shortage'];
if ($sku_code !== '') $desc_parts[] = $sku_code;
if ($sku_desc !== '') $desc_parts[] = $sku_desc;
$base_desc = implode(' - ', $desc_parts);

/* ════════════════════════════════════════════════════════════════
   4. OVERWRITE unloading_pay_transactions
   ════════════════════════════════════════════════════════════════ */
mysqli_query($conn, "DELETE FROM `unloading_pay_transactions` WHERE import_detail_id = $detail_id");

if (mysqli_errno($conn)) {
    respond(['success' => false, 'message' => 'Delete failed: ' . mysqli_error($conn)]);
}

$total_charge = 0;

foreach ($charges as $c) {
    $eid   = intval($c['employee_id']   ?? 0);
    $ename = mysqli_real_escape_string($conn, trim($c['employee_name'] ?? ''));
    $amt   = round(floatval($c['amount'] ?? 0), 2);
    if ($eid <= 0 || $amt <= 0) continue;

    $total_charge += $amt;

    $c_qty   = isset($c['qty'])   && $c['qty']   !== '' ? round(floatval($c['qty']),   4) : null;
    $c_price = isset($c['price']) && $c['price'] !== '' ? round(floatval($c['price']), 2) : null;
    $c_label = mysqli_real_escape_string($conn, trim($c['payroll_month_label'] ?? ''));
    $c_pdate = !empty($c['pay_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $c['pay_date'])
               ? $c['pay_date'] : $g_date;

    $qty_sql   = $c_qty   !== null ? $c_qty   : 'NULL';
    $price_sql = $c_price !== null ? $c_price : 'NULL';
    $label_sql = $c_label !== ''   ? "'$c_label'" : 'NULL';

    $ok = mysqli_query($conn, "
        INSERT INTO `unloading_pay_transactions`
            (import_detail_id, import_id, transaction_date, entry_type,
             employee_id, employee_name, amount, se_value,
             qty, price, payroll_month_label, pay_date)
        VALUES
            ($detail_id, $import_id, '$today', 'charge',
             $eid, '$ename', $amt, $se_value,
             $qty_sql, $price_sql, $label_sql, '$c_pdate')
    ");
    if (!$ok) {
        respond(['success' => false, 'message' => 'Insert charge failed: ' . mysqli_error($conn)]);
    }
}

if ($absorb > 0) {
    mysqli_query($conn, "
        INSERT INTO `unloading_pay_transactions`
            (import_detail_id, import_id, transaction_date, entry_type,
             employee_id, employee_name, amount, se_value)
        VALUES
            ($detail_id, $import_id, '$today', 'absorb',
             NULL, 'COMPANY', $absorb, $se_value)
    ");
}

if (abs($variance) > 0.001) {
    $var_sql = round($variance, 2);
    mysqli_query($conn, "
        INSERT INTO `unloading_pay_transactions`
            (import_detail_id, import_id, transaction_date, entry_type,
             employee_id, employee_name, amount, se_value)
        VALUES
            ($detail_id, $import_id, '$today', 'variance',
             NULL, 'VARIANCE', $var_sql, $se_value)
    ");
}

/* ════════════════════════════════════════════════════════════════
   5. UPDATE unloading_data + unloading_summary_import_details
   ════════════════════════════════════════════════════════════════ */
$tc_sql  = round($total_charge, 2);
$ab_sql  = round($absorb,       2);
$var_sql = round($variance,     2);

mysqli_query($conn, "
    UPDATE `unloading_data`
    SET charge_to_employee = $tc_sql,
        absorb_by_company  = $ab_sql,
        pay_variance       = $var_sql
    WHERE id = $detail_id
");

/* Silently try the other table too */
mysqli_query($conn, "
    UPDATE `unloading_summary_import_details`
    SET charge_to_employee = $tc_sql,
        absorb_by_company  = $ab_sql,
        pay_variance       = $var_sql
    WHERE id = $detail_id
");

/* ════════════════════════════════════════════════════════════════
   6. OVERWRITE payroll_payments_log (one row per employee charge)
   ════════════════════════════════════════════════════════════════ */
mysqli_query($conn, "
    DELETE FROM `payroll_payments_log`
    WHERE reference_id   = $detail_id
      AND reference_type = 'unloading_shortage'
");

foreach ($charges as $c) {
    $eid   = intval($c['employee_id']   ?? 0);
    $ename = mysqli_real_escape_string($conn, trim($c['employee_name'] ?? ''));
    $amt   = round(floatval($c['amount'] ?? 0), 2);
    if ($eid <= 0 || $amt <= 0) continue;

    /* Per-row payroll period — resolve year/month from DB */
    $row_pp_id    = !empty($c['payroll_period_id']) ? intval($c['payroll_period_id']) : $pp_id;
    $row_pp_year  = $pp_year;
    $row_pp_month = $pp_month;

    if ($row_pp_id) {
        $ppr = mysqli_query($conn, "SELECT year, month FROM `payroll_periods` WHERE id = $row_pp_id LIMIT 1");
        if ($ppr && $pprow = mysqli_fetch_assoc($ppr)) {
            $row_pp_year  = intval($pprow['year']);
            $row_pp_month = intval($pprow['month']);
        }
    }

    $use_ppid_sql  = $row_pp_id    !== null ? $row_pp_id    : 'NULL';
    $use_year_sql  = $row_pp_year  !== null ? $row_pp_year  : 'NULL';
    $use_month_sql = $row_pp_month !== null ? $row_pp_month : 'NULL';

    /* Per-row pay date */
    $row_pdate = !empty($c['pay_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $c['pay_date'])
                 ? $c['pay_date'] : $g_date;
    $row_pdate = mysqli_real_escape_string($conn, $row_pdate);

    /* Rich description */
    $row_label = trim($c['payroll_month_label'] ?? '');
    $row_qty   = isset($c['qty'])   && $c['qty']   !== '' ? floatval($c['qty'])   : 0;
    $row_price = isset($c['price']) && $c['price'] !== '' ? floatval($c['price']) : 0;

    $rich_desc = $base_desc;
    if ($row_qty > 0 && $row_price > 0)
        $rich_desc .= ' | Qty: ' . $row_qty . ' x Rs.' . number_format($row_price, 2)
                    . ' = Rs.' . number_format($row_qty * $row_price, 2);
    if ($row_label !== '') $rich_desc .= ' | ' . $row_label;
    if (mb_strlen($rich_desc) > 490) $rich_desc = mb_substr($rich_desc, 0, 490);
    $rich_desc_sql = mysqli_real_escape_string($conn, $rich_desc);
    $amt_sql = number_format($amt, 2, '.', '');

    $ok2 = mysqli_query($conn, "
        INSERT INTO `payroll_payments_log`
            (employee_id, employee_name,
             payroll_period_id, payroll_year, payroll_month,
             amount, charge_date, description,
             reference_id, reference_type)
        VALUES
            ($eid, '$ename',
             $use_ppid_sql, $use_year_sql, $use_month_sql,
             $amt_sql, '$row_pdate', '$rich_desc_sql',
             $detail_id, 'unloading_shortage')
    ");

    if (!$ok2) {
        respond(['success' => false, 'message' => 'payroll_payments_log insert failed: ' . mysqli_error($conn)]);
    }
}

/* ════════════════════════════════════════════════════════════════
   7. SUCCESS RESPONSE
   ════════════════════════════════════════════════════════════════ */
respond([
    'success'      => true,
    'total_charge' => $total_charge,
    'absorb'       => $absorb,
    'variance'     => $variance,
    'description'  => $base_desc,
    'reference_id' => $detail_id,
]);