<?php
/* ============================================================================
   cc_report_data.php
   Shared data-building logic for the Cash Collection Report (Delivery Person
   Wise). Extracted out of cc_report.php so the exact same queries / totals /
   pivot-table structure can be reused by the new standalone print page
   (cc_report_print.php) without duplicating (and risking drift on) all the
   business logic.

   Anything that reads $_GET, builds $rows / $totals / $pivot_rows / $person_*
   / $sr_* lives here. Page-specific HTML (the on-screen report shell, or the
   print-only shell) stays in the including file.
   ============================================================================ */

if (!isset($conn) || !$conn) {
    include_once 'config.php';
}

$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$f_dp      = trim($_GET['delivery_person'] ?? '');
$f_sr      = trim($_GET['sr_code'] ?? '');
$submitted = isset($_GET['search']);

$df     = mysqli_real_escape_string($conn, $date_from);
$dt     = mysqli_real_escape_string($conn, $date_to);
$dp_esc = $f_dp ? mysqli_real_escape_string($conn, $f_dp) : '';
$sr_esc = $f_sr ? mysqli_real_escape_string($conn, $f_sr) : '';

/* ── ensure pay allocations table exists ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cash_summary_pay_allocations_dp (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    pay_date       DATE NOT NULL,
    delivery_person VARCHAR(200) NOT NULL,
    entry_type     VARCHAR(20) NOT NULL,
    employee_id    INT NULL,
    employee_name  VARCHAR(200) NULL,
    amount         DECIMAL(12,2) DEFAULT 0.00,
    total_value    DECIMAL(12,2) DEFAULT 0.00,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date_dp (pay_date, delivery_person)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── get distinct delivery persons ── */
$dp_res = mysqli_query($conn, "
    SELECT DISTINCT CONVERT(delivery_person USING utf8mb4) COLLATE utf8mb4_unicode_ci AS delivery_person
    FROM cc_cash_deposit_dp_persons
    WHERE delivery_person IS NOT NULL AND delivery_person != ''
    UNION
    SELECT DISTINCT CONVERT(delivery_person USING utf8mb4) COLLATE utf8mb4_unicode_ci
    FROM invoice_payments
    WHERE delivery_person IS NOT NULL AND delivery_person != ''
    UNION
    SELECT DISTINCT CONVERT(delivery_person USING utf8mb4) COLLATE utf8mb4_unicode_ci
    FROM secondary_invoice_import_details
    WHERE delivery_person IS NOT NULL AND delivery_person != '' AND status='imported'
    ORDER BY delivery_person
");
$all_dp = [];
if ($dp_res) while ($r = mysqli_fetch_assoc($dp_res)) $all_dp[] = $r['delivery_person'];

/* ── Display-only name cleanup: delivery_person values come through with
   employee/route codes glued onto the name — sometimes pure digits
   ("476929Rajitha803559"), sometimes a letter+digit code ("DAA3119Rasanga",
   "DAH1620Ravindu"). Pull out just the actual name in both cases: strip a
   trailing digit run first, then take the trailing run of Titlecase
   word(s) — that automatically eats any code prefix (whether it's pure
   digits or letters-then-digits) since a code like "DAA3119" never itself
   matches a Titlecase-word pattern once its trailing digits are gone. The
   raw value (with the code) is still used everywhere for filtering, links,
   and lookups — only the visible text changes. ── */
if (!function_exists('cc_clean_name')) {
    function cc_clean_name($raw) {
        $s = trim((string)$raw);
        if ($s === '') return $s;
        $s2 = preg_replace('/[0-9]+$/', '', $s);
        if (preg_match('/((?:[A-Z][a-z]+ ?)+)$/', $s2, $m)) {
            $candidate = trim($m[1]);
            if ($candidate !== '') return $candidate;
        }
        $stripped = preg_replace('/^[0-9]+/', '', $s2);
        $stripped = trim($stripped);
        return $stripped !== '' ? $stripped : $s;
    }
}

/* ── distinct SR codes, for the SR code filter dropdown — sourced from
   field_summary.sr_code, verified against sum.php (your real, working SR
   report). Not date-restricted, same as sum.php's own dropdown query. ── */
$sr_res = mysqli_query($conn, "
    SELECT DISTINCT sr_code FROM field_summary
    WHERE sr_code IS NOT NULL AND sr_code != ''
    ORDER BY sr_code
");
$all_sr = [];
if ($sr_res) while ($r = mysqli_fetch_assoc($sr_res)) $all_sr[] = $r['sr_code'];

/* ── employee list for pay modal ── */
$emp_list = [];
$er = mysqli_query($conn,
    "SELECT id, COALESCE(NULLIF(employee_id,''),CONCAT('EMP-',id)) AS emp_code,
     COALESCE(NULLIF(name_with_initials,''),NULLIF(employee_full_name,''),CONCAT('Employee #',id)) AS emp_name
     FROM employees
     WHERE COALESCE(status,'') NOT IN ('Resigned','Terminated','Inactive','inactive','resigned','terminated')
     ORDER BY emp_name ASC");
if (!$er || mysqli_num_rows($er) === 0)
    $er = mysqli_query($conn,"SELECT id,
     COALESCE(NULLIF(employee_id,''),CONCAT('EMP-',id)) AS emp_code,
     COALESCE(NULLIF(name_with_initials,''),NULLIF(employee_full_name,''),CONCAT('Employee #',id)) AS emp_name
     FROM employees ORDER BY emp_name ASC LIMIT 500");
if ($er) while ($row = mysqli_fetch_assoc($er)) $emp_list[] = $row;

/* ═══════════ DATA ═══════════ */
$rows   = [];
$slip_rows = [];
$slip_by_dp = [];
$bank_name_col = null;
$slip_no_col = null;
$bo_cats = ['cc_daily_sale'=>0.0,'cc_rcvd_credit'=>0.0,'cc_rcvd_rtn_chq'=>0.0,'cc_rcvd_rtn_chgs'=>0.0,'cc_rcvd_sent_back'=>0.0];
$bo_cc_total = 0.0;
$sr_cats = ['cc_rcvd_credit'=>0.0,'cc_rcvd_rtn_chq'=>0.0,'cc_rcvd_sent_back'=>0.0];
$sr_cc_total = 0.0;
$sr_handed_total = 0.0;
$sr_code_cats = [];
$sr_code_handed = [];
$sr_code_list = [];
$totals = array_fill_keys([
    'sinv','cash_paid','cheque_paid','credit','pay_diff',
    'cc_daily_sale','cc_rcvd_credit','cc_rcvd_rtn_chq','cc_rcvd_rtn_chgs','cc_rcvd_sent_back','cc_total',
    'total_coll',
    'banked_cc','handed_cc','banked',
    'variance_cc','short_cc',
    'bank_diff','new_short_excess',
    'p_charge','p_absorb',
], 0.0);

if ($submitted) {

    /* ── master set ── */
    $master_set = [];

    $q1 = "SELECT DISTINCT delivery_date, delivery_person
           FROM secondary_invoice_import_details
           WHERE delivery_date BETWEEN '$df' AND '$dt' AND status='imported'
             AND delivery_person IS NOT NULL AND delivery_person != ''";
    if ($dp_esc) $q1 .= " AND delivery_person='$dp_esc'";
    $res1 = mysqli_query($conn, $q1);
    if ($res1) while ($row = mysqli_fetch_row($res1))
        $master_set[$row[0].'|'.$row[1]] = true;

    $q2 = "SELECT DISTINCT p.delivery_date, p.delivery_person
           FROM cc_cash_deposit_dp_persons p
           INNER JOIN cc_cash_deposit_dp d ON d.id = p.deposit_id
           WHERE p.delivery_date BETWEEN '$df' AND '$dt'
             AND p.delivery_date IS NOT NULL AND p.delivery_date != '0000-00-00'
             AND p.delivery_person IS NOT NULL AND p.delivery_person != ''";
    if ($dp_esc) $q2 .= " AND p.delivery_person='$dp_esc'";
    $res2 = mysqli_query($conn, $q2);
    if ($res2) while ($row = mysqli_fetch_row($res2))
        $master_set[$row[0].'|'.$row[1]] = true;

    /* ── Return Charges settlement table availability (rc_settlement_payments) ── */
    $rc_tbl_exists = false; $rc_has_dp = false;
    $_rc_tchk = mysqli_query($conn, "SHOW TABLES LIKE 'rc_settlement_payments'");
    if ($_rc_tchk && mysqli_num_rows($_rc_tchk) > 0) {
        $rc_tbl_exists = true;
        $_rc_cchk = mysqli_query($conn, "SHOW COLUMNS FROM rc_settlement_payments LIKE 'delivery_person'");
        if ($_rc_cchk && mysqli_num_rows($_rc_cchk) > 0) $rc_has_dp = true;
    }

    /* include delivery persons that only have a return-charge settlement on a date */
    if ($rc_tbl_exists && $rc_has_dp) {
        $q3 = "SELECT DISTINCT payment_date, delivery_person
               FROM rc_settlement_payments
               WHERE payment_method='cash'
                 AND payment_date BETWEEN '$df' AND '$dt'
                 AND delivery_person IS NOT NULL AND delivery_person != ''";
        if ($dp_esc) $q3 .= " AND delivery_person='$dp_esc'";
        $res3 = mysqli_query($conn, $q3);
        if ($res3) while ($row = mysqli_fetch_row($res3))
            $master_set[$row[0].'|'.$row[1]] = true;
    }

    $master = array_keys($master_set);
    usort($master, 'strcmp');

    /* ── Sec. Invoice ── */
    $sinv_map = [];
    $r3 = mysqli_query($conn, "
        SELECT delivery_date, delivery_person,
               COALESCE(SUM(final_bill_amount),0) AS sinv
        FROM secondary_invoice_import_details
        WHERE delivery_date BETWEEN '$df' AND '$dt'
          AND status='imported'
          AND delivery_person IS NOT NULL AND delivery_person != ''
          " . ($dp_esc ? "AND delivery_person='$dp_esc'" : '') . "
        GROUP BY delivery_date, delivery_person
    ");
    if ($r3) while ($r = mysqli_fetch_assoc($r3))
        $sinv_map[$r['delivery_date'].'|'.$r['delivery_person']] = floatval($r['sinv']);

    /* ── Cash Paid & Cheque Paid ── */
    $invpay_map = [];
    $r4 = mysqli_query($conn, "
        SELECT siid.delivery_date, siid.delivery_person,
            COALESCE(SUM(CASE WHEN ip.payment_method='cash'   AND ip.is_reversed=0 THEN ip.amount ELSE 0 END),0) AS cash_paid,
            COALESCE(SUM(CASE WHEN ip.payment_method='cheque' AND ip.is_reversed=0 THEN ip.amount ELSE 0 END),0) AS cheque_paid
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        INNER JOIN secondary_invoice_import_details siid
               ON siid.bill_no = fsd.invoice_num
              AND siid.status = 'imported'
              AND siid.delivery_person IS NOT NULL AND siid.delivery_person != ''
        WHERE siid.delivery_date BETWEEN '$df' AND '$dt'
          " . ($dp_esc ? "AND siid.delivery_person='$dp_esc'" : '') . "
        GROUP BY siid.delivery_date, siid.delivery_person
    ");
    if ($r4) while ($r = mysqli_fetch_assoc($r4))
        $invpay_map[$r['delivery_date'].'|'.$r['delivery_person']] = $r;

    /* ── Credit (unpaid balance) ── */
    $paid_by_det = [];
    $rpd = mysqli_query($conn, "
        SELECT field_summary_detail_id,
               ROUND(COALESCE(SUM(CASE WHEN is_reversed=0 THEN amount ELSE 0 END),0),2) AS paid
        FROM invoice_payments
        GROUP BY field_summary_detail_id
    ");
    if ($rpd) while ($r = mysqli_fetch_assoc($rpd))
        $paid_by_det[intval($r['field_summary_detail_id'])] = floatval($r['paid']);

    $credit_map = [];
    $r5 = mysqli_query($conn, "
        SELECT siid.delivery_date, siid.delivery_person,
               fsd.id AS det_id,
               fsd.adjust_net_value AS row_adj
        FROM field_summary_details fsd
        INNER JOIN secondary_invoice_import_details siid
               ON siid.bill_no = fsd.invoice_num
              AND siid.status = 'imported'
              AND siid.delivery_person IS NOT NULL AND siid.delivery_person != ''
        WHERE siid.delivery_date BETWEEN '$df' AND '$dt'
          " . ($dp_esc ? "AND siid.delivery_person='$dp_esc'" : '') . "
    ");
    if ($r5) while ($r = mysqli_fetch_assoc($r5)) {
        $k       = $r['delivery_date'].'|'.$r['delivery_person'];
        $row_bal = max(0.0, floatval($r['row_adj']) - ($paid_by_det[intval($r['det_id'])] ?? 0.0));
        $credit_map[$k] = ($credit_map[$k] ?? 0.0) + $row_bal;
    }
    foreach ($credit_map as $k => $v) $credit_map[$k] = round($v, 2);

    /* ── CC Daily Sale ── */
    $cash_map = [];
    $r6 = mysqli_query($conn, "
        SELECT siid.delivery_date, siid.delivery_person,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
                              AND (ip.payment_source IS NULL OR ip.payment_source='' OR LOWER(ip.payment_source)='invoice')
                         THEN ip.amount ELSE 0 END),0) AS cc_daily_sale
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        INNER JOIN secondary_invoice_import_details siid
               ON siid.bill_no = fsd.invoice_num
              AND siid.status = 'imported'
              AND siid.delivery_person IS NOT NULL AND siid.delivery_person != ''
        WHERE ip.payment_method='cash' AND ip.is_reversed=0
          AND siid.delivery_date BETWEEN '$df' AND '$dt'
          " . ($dp_esc ? "AND siid.delivery_person='$dp_esc'" : '') . "
        GROUP BY siid.delivery_date, siid.delivery_person
    ");
    if ($r6) while ($r = mysqli_fetch_assoc($r6))
        $cash_map[$r['delivery_date'].'|'.$r['delivery_person']] = $r;

    /* ── CC breakdown ── */
    $cc_breakdown_map = [];
    $r6b = mysqli_query($conn, "
        SELECT ip.payment_date, ip.delivery_person,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
                              AND LOWER(ip.payment_source) LIKE '%credit%'
                         THEN ip.amount ELSE 0 END),0) AS cc_rcvd_credit,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
                              AND ip.payment_source='return_cheque_settlement'
                         THEN ip.amount ELSE 0 END),0) AS cc_rcvd_rtn_chq,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
                              AND ip.payment_source='return_charge_settlement'
                         THEN ip.amount ELSE 0 END),0) AS cc_rcvd_rtn_chgs,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
                              AND ip.payment_source='sentback_cheque_settlement'
                         THEN ip.amount ELSE 0 END),0) AS cc_rcvd_sent_back
        FROM invoice_payments ip
        WHERE ip.payment_method='cash' AND ip.is_reversed=0
          AND ip.payment_date BETWEEN '$df' AND '$dt'
          AND ip.delivery_person IS NOT NULL AND ip.delivery_person != ''
          " . ($dp_esc ? "AND ip.delivery_person='$dp_esc'" : '') . "
        GROUP BY ip.payment_date, ip.delivery_person
    ");
    if ($r6b) while ($r = mysqli_fetch_assoc($r6b))
        $cc_breakdown_map[$r['payment_date'].'|'.$r['delivery_person']] = $r;

    /* ── Return Charges received (from rc_settlement_payments — the return
          charges settlement table). Cash collected by a delivery person,
          keyed by payment_date|delivery_person. This is the authoritative
          source for the "Rtn Chgs" column (replaces the invoice_payments
          return_charge_settlement value to avoid double counting). ── */
    $rc_rtnchg_map = [];
    if ($rc_tbl_exists && $rc_has_dp) {
        $rc_q = "
            SELECT payment_date, delivery_person,
                   COALESCE(SUM(amount),0) AS rc_rtn_chgs
            FROM rc_settlement_payments
            WHERE payment_method='cash'
              AND payment_date BETWEEN '$df' AND '$dt'
              AND delivery_person IS NOT NULL AND delivery_person != ''
              " . ($dp_esc ? "AND delivery_person='$dp_esc'" : '') . "
            GROUP BY payment_date, delivery_person
        ";
        $rc_r = mysqli_query($conn, $rc_q);
        if ($rc_r) while ($r = mysqli_fetch_assoc($rc_r))
            $rc_rtnchg_map[$r['payment_date'].'|'.$r['delivery_person']] = floatval($r['rc_rtn_chgs']);
    }

    /* ── Deposits ── */
    $dep_map = [];
    $r7 = mysqli_query($conn, "
        SELECT p.delivery_date, p.delivery_person,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=0 AND LOWER(COALESCE(d.collected_by,''))='cc' THEN p.amount ELSE 0 END),0) AS banked_cc,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=1 AND LOWER(COALESCE(d.collected_by,''))='cc' THEN p.amount ELSE 0 END),0) AS handed_cc
        FROM cc_cash_deposit_dp_persons p
        INNER JOIN cc_cash_deposit_dp d ON d.id = p.deposit_id
        WHERE p.delivery_date BETWEEN '$df' AND '$dt'
          AND p.delivery_date IS NOT NULL AND p.delivery_date != '0000-00-00'
          AND p.delivery_person IS NOT NULL AND p.delivery_person != ''
          " . ($dp_esc ? "AND p.delivery_person='$dp_esc'" : '') . "
        GROUP BY p.delivery_date, p.delivery_person
    ");
    if ($r7) while ($r = mysqli_fetch_assoc($r7))
        $dep_map[$r['delivery_date'].'|'.$r['delivery_person']] = $r;

    /* ── Slip Wise Deposit Details — Delivery Person Wise ──────────────────
       Individual bank-deposit slip rows (not just the aggregated banked_cc/
       handed_cc totals above). Columns on cc_cash_deposit_dp vary by install,
       so detect what's actually there and degrade gracefully. ── */
    $cdd_cols = [];
    $cddc = mysqli_query($conn, "SHOW COLUMNS FROM cc_cash_deposit_dp");
    if ($cddc) while ($c = mysqli_fetch_assoc($cddc)) $cdd_cols[$c['Field']] = true;
    $slip_no_col   = $cdd_cols['slip_no']      ?? null ? 'slip_no'      :
                      ($cdd_cols['bank_slip_no'] ?? null ? 'bank_slip_no' :
                      ($cdd_cols['reference_no']  ?? null ? 'reference_no'  :
                      ($cdd_cols['deposit_ref']   ?? null ? 'deposit_ref'   : null)));
    $bank_name_col = ($cdd_cols['bank_name'] ?? null) ? 'bank_name' : (($cdd_cols['bank'] ?? null) ? 'bank' : null);
    $dep_date_col  = ($cdd_cols['deposit_date'] ?? null) ? 'deposit_date' : (($cdd_cols['deposited_date'] ?? null) ? 'deposited_date' : null);

    $slip_extra_sel = [];
    if ($slip_no_col)   $slip_extra_sel[] = "d.`$slip_no_col` AS slip_ref";
    if ($bank_name_col) $slip_extra_sel[] = "d.`$bank_name_col` AS bank_name";
    if ($dep_date_col)  $slip_extra_sel[] = "d.`$dep_date_col` AS deposit_date";
    $slip_extra_sql = $slip_extra_sel ? (', '.implode(', ', $slip_extra_sel)) : '';

    $slip_rows = [];
    $sq = "SELECT d.id AS deposit_id, p.delivery_date, p.delivery_person, p.amount,
                  d.handed_over_bo, d.collected_by $slip_extra_sql
           FROM cc_cash_deposit_dp_persons p
           INNER JOIN cc_cash_deposit_dp d ON d.id = p.deposit_id
           WHERE p.delivery_date BETWEEN '$df' AND '$dt'
             AND p.delivery_date IS NOT NULL AND p.delivery_date != '0000-00-00'
             AND p.delivery_person IS NOT NULL AND p.delivery_person != ''
             " . ($dp_esc ? "AND p.delivery_person='$dp_esc'" : '') . "
           ORDER BY p.delivery_person ASC, p.delivery_date ASC, d.id ASC";
    $sres = mysqli_query($conn, $sq);
    if ($sres) while ($r = mysqli_fetch_assoc($sres)) $slip_rows[] = $r;

    /* group by delivery person and auto-number slips (Slip-1, Slip-2 …) when
       there's no dedicated slip-number column to display instead. */
    $slip_by_dp = [];
    $slip_seq   = [];
    foreach ($slip_rows as $r) {
        $dpn = $r['delivery_person'];
        $slip_seq[$dpn] = ($slip_seq[$dpn] ?? 0) + 1;
        $r['slip_seq'] = $slip_seq[$dpn];
        $slip_by_dp[$dpn][] = $r;
    }

    /* ── Back Office's OWN collection (BO acting as its own collector,
       i.e. collected_by='bo' instead of 'cc') — mirrors the cash_map /
       cc_breakdown_map queries above exactly, just the other collected_by
       value. This is what "Back Office Collection" actually means: cash
       collected directly by BO, not cash merely handed over to them. ── */
    $bo_cats = ['cc_daily_sale'=>0.0,'cc_rcvd_credit'=>0.0,'cc_rcvd_rtn_chq'=>0.0,'cc_rcvd_rtn_chgs'=>0.0,'cc_rcvd_sent_back'=>0.0];

    $rbo1 = mysqli_query($conn, "
        SELECT COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='bo'
                              AND (ip.payment_source IS NULL OR ip.payment_source='' OR LOWER(ip.payment_source)='invoice')
                         THEN ip.amount ELSE 0 END),0) AS cc_daily_sale
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        INNER JOIN secondary_invoice_import_details siid
               ON siid.bill_no = fsd.invoice_num
              AND siid.status = 'imported'
        WHERE ip.payment_method='cash' AND ip.is_reversed=0
          AND siid.delivery_date BETWEEN '$df' AND '$dt'
    ");
    if ($rbo1 && ($r = mysqli_fetch_assoc($rbo1))) $bo_cats['cc_daily_sale'] = floatval($r['cc_daily_sale']);

    $rbo2 = mysqli_query($conn, "
        SELECT
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='bo' AND LOWER(ip.payment_source) LIKE '%credit%' THEN ip.amount ELSE 0 END),0) AS cc_rcvd_credit,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='bo' AND ip.payment_source='return_cheque_settlement' THEN ip.amount ELSE 0 END),0) AS cc_rcvd_rtn_chq,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='bo' AND ip.payment_source='return_charge_settlement' THEN ip.amount ELSE 0 END),0) AS cc_rcvd_rtn_chgs,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='bo' AND ip.payment_source='sentback_cheque_settlement' THEN ip.amount ELSE 0 END),0) AS cc_rcvd_sent_back
        FROM invoice_payments ip
        WHERE ip.payment_method='cash' AND ip.is_reversed=0
          AND ip.payment_date BETWEEN '$df' AND '$dt'
    ");
    if ($rbo2 && ($r = mysqli_fetch_assoc($rbo2))) {
        $bo_cats['cc_rcvd_credit']    = floatval($r['cc_rcvd_credit']);
        $bo_cats['cc_rcvd_rtn_chq']   = floatval($r['cc_rcvd_rtn_chq']);
        $bo_cats['cc_rcvd_rtn_chgs']  = floatval($r['cc_rcvd_rtn_chgs']);
        $bo_cats['cc_rcvd_sent_back'] = floatval($r['cc_rcvd_sent_back']);
    }
    $bo_cc_total = array_sum($bo_cats);

    /* ── SR (Sales Rep) collection — verified against sum.php, your real,
       working SR cash-summary report. Key corrections vs. my earlier guess:
         • SR is identified by collected_by != 'cc' (not collected_by='sr')
         • The real SR code lives on field_summary.sr_code, reached via
           invoice_payments -> field_summary_details -> field_summary —
           NOT via invoice_payments.delivery_person.
         • SR deposit/hand-over data comes from cc_cash_deposit_reps joined
           to cc_cash_deposits (rep_code / deposit_id) — a completely
           different pair of tables than the DP deposit tables used above. */
    $rsr = mysqli_query($conn, "
        SELECT
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND LOWER(ip.payment_source) LIKE '%credit%' THEN ip.amount ELSE 0 END),0) AS cc_rcvd_credit,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND ip.payment_source='return_cheque_settlement' THEN ip.amount ELSE 0 END),0) AS cc_rcvd_rtn_chq,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND ip.payment_source IN ('sentback_cheque_settlement','return_charge_settlement') THEN ip.amount ELSE 0 END),0) AS cc_rcvd_sent_back
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
        WHERE ip.payment_method='cash' AND ip.is_reversed=0
          AND ip.payment_date BETWEEN '$df' AND '$dt'
          " . ($sr_esc ? "AND fs.sr_code='$sr_esc'" : '') . "
    ");
    if ($rsr && ($r = mysqli_fetch_assoc($rsr))) {
        $sr_cats['cc_rcvd_credit']    = floatval($r['cc_rcvd_credit']);
        $sr_cats['cc_rcvd_rtn_chq']   = floatval($r['cc_rcvd_rtn_chq']);
        $sr_cats['cc_rcvd_sent_back'] = floatval($r['cc_rcvd_sent_back']);
    }
    $sr_cc_total = array_sum($sr_cats);

    /* SR's own hand-over-to-office total, from the real SR deposit tables. */
    $rsrh = mysqli_query($conn, "
        SELECT COALESCE(SUM(CASE WHEN d.handed_over_bo=1 THEN r.amount ELSE 0 END),0) AS sr_handed
        FROM cc_cash_deposit_reps r
        INNER JOIN cc_cash_deposits d ON d.id = r.deposit_id
        WHERE r.delivery_date BETWEEN '$df' AND '$dt'
          AND r.delivery_date IS NOT NULL AND r.delivery_date != '0000-00-00'
          " . ($sr_esc ? "AND r.rep_code='$sr_esc'" : '') . "
    ");
    if ($rsrh && ($r = mysqli_fetch_assoc($rsrh))) $sr_handed_total = floatval($r['sr_handed']);

    /* ── SR-CODE-WISE breakdown — grouped by the real fs.sr_code, kept for the
       Sales Rep detail table (see below), no longer expanded into individual
       columns of the main pivot matrix. ── */
    $sr_code_cats = [];   // [sr_code][cc_rcvd_credit|cc_rcvd_rtn_chq|cc_rcvd_sent_back] = amount
    $rsrc = mysqli_query($conn, "
        SELECT fs.sr_code,
            COALESCE(SUM(CASE WHEN LOWER(ip.payment_source) LIKE '%credit%' THEN ip.amount ELSE 0 END),0) AS cc_rcvd_credit,
            COALESCE(SUM(CASE WHEN ip.payment_source='return_cheque_settlement' THEN ip.amount ELSE 0 END),0) AS cc_rcvd_rtn_chq,
            COALESCE(SUM(CASE WHEN ip.payment_source IN ('sentback_cheque_settlement','return_charge_settlement') THEN ip.amount ELSE 0 END),0) AS cc_rcvd_sent_back
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
        WHERE ip.payment_method='cash' AND ip.is_reversed=0
          AND LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc'
          AND ip.payment_date BETWEEN '$df' AND '$dt'
          AND fs.sr_code IS NOT NULL AND fs.sr_code != ''
          " . ($sr_esc ? "AND fs.sr_code='$sr_esc'" : '') . "
        GROUP BY fs.sr_code
    ");
    if ($rsrc) while ($r = mysqli_fetch_assoc($rsrc)) {
        $sr_code_cats[$r['sr_code']] = [
            'cc_rcvd_credit'    => floatval($r['cc_rcvd_credit']),
            'cc_rcvd_rtn_chq'   => floatval($r['cc_rcvd_rtn_chq']),
            'cc_rcvd_sent_back' => floatval($r['cc_rcvd_sent_back']),
        ];
    }

    $sr_code_handed = [];   // [sr_code] = handed-over amount, from the real SR deposit tables
    $rsrch = mysqli_query($conn, "
        SELECT r.rep_code AS sr_code,
               COALESCE(SUM(CASE WHEN d.handed_over_bo=1 THEN r.amount ELSE 0 END),0) AS sr_handed
        FROM cc_cash_deposit_reps r
        INNER JOIN cc_cash_deposits d ON d.id = r.deposit_id
        WHERE r.delivery_date BETWEEN '$df' AND '$dt'
          AND r.delivery_date IS NOT NULL AND r.delivery_date != '0000-00-00'
          AND r.rep_code IS NOT NULL AND r.rep_code != ''
          " . ($sr_esc ? "AND r.rep_code='$sr_esc'" : '') . "
        GROUP BY r.rep_code
    ");
    if ($rsrch) while ($r = mysqli_fetch_assoc($rsrch)) $sr_code_handed[$r['sr_code']] = floatval($r['sr_handed']);

    /* SR code list: strictly the real sr_code values found in this range's
       collection data — rep_code (from the deposit table) IS the same
       identifier space as fs.sr_code here (unlike the DP tables, both
       genuinely refer to the same SR), so merging both sources is correct
       and won't pull in unrelated names. */
    $sr_code_list = array_values(array_unique(array_merge(array_keys($sr_code_cats), array_keys($sr_code_handed))));
    sort($sr_code_list, SORT_NATURAL | SORT_FLAG_CASE);

    /* ── Pay allocations ── */
    $alloc_map = [];
    $r8 = mysqli_query($conn, "
        SELECT pay_date, delivery_person, entry_type, employee_id, employee_name, amount, total_value
        FROM cash_summary_pay_allocations_dp
        WHERE pay_date BETWEEN '$df' AND '$dt'
          " . ($dp_esc ? "AND delivery_person='$dp_esc'" : '') . "
        ORDER BY pay_date, delivery_person, entry_type
    ");
    if ($r8) while ($r = mysqli_fetch_assoc($r8)) {
        $k = $r['pay_date'].'|'.$r['delivery_person'];
        if (!isset($alloc_map[$k])) $alloc_map[$k] = ['p_charge'=>0,'p_absorb'=>0,'p_variance'=>null,'employees'=>[],'is_paid'=>false];
        if ($r['entry_type'] === 'charge') {
            $alloc_map[$k]['p_charge']    += floatval($r['amount']);
            $alloc_map[$k]['employees'][]  = ['employee_id'=>$r['employee_id'],'employee_name'=>$r['employee_name'],'amount'=>floatval($r['amount'])];
            $alloc_map[$k]['is_paid']      = true;
        }
        if ($r['entry_type'] === 'absorb')   { $alloc_map[$k]['p_absorb']   = floatval($r['amount']); $alloc_map[$k]['is_paid'] = true; }
        if ($r['entry_type'] === 'variance')   $alloc_map[$k]['p_variance'] = floatval($r['amount']);
    }

    /* ── build rows ── */
    foreach ($master as $k) {
        [$del_date, $dp] = explode('|', $k, 2);

        $cm   = $cash_map[$k]         ?? [];
        $cbm  = $cc_breakdown_map[$k] ?? [];
        $dm   = $dep_map[$k]          ?? [];
        $invp = $invpay_map[$k]        ?? [];
        $pa   = $alloc_map[$k]         ?? [];

        $sinv        = floatval($sinv_map[$k] ?? 0);
        $cash_paid   = floatval($invp['cash_paid']   ?? 0);
        $cheque_paid = floatval($invp['cheque_paid'] ?? 0);
        $credit      = floatval($credit_map[$k]      ?? 0);
        $pay_diff    = $sinv - ($cash_paid + $cheque_paid + $credit);

        $cc_daily_sale     = floatval($cm['cc_daily_sale']       ?? 0);
        $cc_rcvd_credit    = floatval($cbm['cc_rcvd_credit']     ?? 0);
        $cc_rcvd_rtn_chq   = floatval($cbm['cc_rcvd_rtn_chq']    ?? 0);
        $cc_rcvd_rtn_chgs  = floatval($rc_rtnchg_map[$k] ?? 0);
        $cc_rcvd_sent_back = floatval($cbm['cc_rcvd_sent_back']  ?? 0);

        $cc_total   = $cc_daily_sale + $cc_rcvd_credit + $cc_rcvd_rtn_chq + $cc_rcvd_rtn_chgs + $cc_rcvd_sent_back;
        $total_coll = $cc_total;

        $banked_cc = floatval($dm['banked_cc'] ?? 0);
        $handed_cc = floatval($dm['handed_cc'] ?? 0);
        $banked    = $banked_cc + $handed_cc;

        $variance_cc      = $cc_total - ($banked_cc + $handed_cc);
        $short_cc         = $variance_cc > 0 ? $variance_cc : 0;
        $bank_diff        = $cc_total - $banked;
        $new_short_excess = $bank_diff;

        $p_charge   = floatval($pa['p_charge']   ?? 0);
        $p_absorb   = floatval($pa['p_absorb']   ?? 0);
        $p_variance = isset($pa['p_variance']) ? floatval($pa['p_variance']) : null;
        $p_employees= $pa['employees'] ?? [];
        $is_paid    = (bool)($pa['is_paid'] ?? false);

        /* ── hide fully-empty rows: skip when every value column is zero ── */
        $row_has_data = (
            abs($sinv)              > 0.004 || abs($cash_paid)        > 0.004 ||
            abs($cheque_paid)       > 0.004 || abs($credit)           > 0.004 ||
            abs($cc_daily_sale)     > 0.004 || abs($cc_rcvd_credit)   > 0.004 ||
            abs($cc_rcvd_rtn_chq)   > 0.004 || abs($cc_rcvd_rtn_chgs) > 0.004 ||
            abs($cc_rcvd_sent_back) > 0.004 || abs($banked_cc)        > 0.004 ||
            abs($handed_cc)         > 0.004 || abs($p_charge)         > 0.004 ||
            abs($p_absorb)          > 0.004
        );
        if (!$row_has_data) continue;

        $rows[] = compact(
            'dp','del_date','sinv',
            'cash_paid','cheque_paid','credit','pay_diff',
            'cc_daily_sale','cc_rcvd_credit','cc_rcvd_rtn_chq','cc_rcvd_rtn_chgs','cc_rcvd_sent_back','cc_total',
            'total_coll','banked_cc','handed_cc','banked',
            'variance_cc','short_cc',
            'bank_diff','new_short_excess',
            'p_charge','p_absorb','p_variance','p_employees','is_paid'
        );

        $totals['sinv']             += $sinv;
        $totals['cash_paid']        += $cash_paid;
        $totals['cheque_paid']      += $cheque_paid;
        $totals['credit']           += $credit;
        $totals['pay_diff']         += $pay_diff;
        $totals['cc_daily_sale']    += $cc_daily_sale;
        $totals['cc_rcvd_credit']   += $cc_rcvd_credit;
        $totals['cc_rcvd_rtn_chq']  += $cc_rcvd_rtn_chq;
        $totals['cc_rcvd_rtn_chgs'] += $cc_rcvd_rtn_chgs;
        $totals['cc_rcvd_sent_back']+= $cc_rcvd_sent_back;
        $totals['cc_total']         += $cc_total;
        $totals['total_coll']       += $total_coll;
        $totals['banked_cc']        += $banked_cc;
        $totals['handed_cc']        += $handed_cc;
        $totals['banked']           += $banked;
        $totals['variance_cc']      += $variance_cc;
        $totals['short_cc']         += $short_cc;
        $totals['bank_diff']        += $bank_diff;
        $totals['new_short_excess'] += $new_short_excess;
        $totals['p_charge']         += $p_charge;
        $totals['p_absorb']         += $p_absorb;
    }
}

/* ═══════════ PIVOT: aggregate the per-date rows into per-delivery-person
   totals across the whole selected range, for the single consolidated
   Excel-style summary table. Reuses $rows / $totals exactly as computed
   above — no separate/duplicate queries. ═══════════ */
$person_totals = [];
$person_list   = [];
foreach ($rows as $r) {
    $dp = $r['dp'];
    if (!isset($person_totals[$dp])) {
        $person_totals[$dp] = array_fill_keys(array_keys($totals), 0.0);
        $person_list[] = $dp;
    }
    foreach ($totals as $k => $_) $person_totals[$dp][$k] += floatval($r[$k] ?? 0);
}
sort($person_list, SORT_NATURAL | SORT_FLAG_CASE);

/* computed "Variance" = Final Short/(Excess) − (Charged to Employee + Charged to Company),
   should settle at ~0 once everything is allocated — same idea as the xlsx Variance row. */
$totals['pay_variance_calc'] = $totals['new_short_excess'] - ($totals['p_charge'] + $totals['p_absorb']);
foreach ($person_list as $dpn) {
    $pt = &$person_totals[$dpn];
    $pt['pay_variance_calc'] = $pt['new_short_excess'] - ($pt['p_charge'] + $pt['p_absorb']);
    unset($pt);
}

/* ── Global Slip Wise matrix, numbered per delivery person's OWN deposit
   sequence: a person's first deposit in the range lands in the "Bank
   Deposit Slip 1" row, their second deposit lands in "Bank Deposit Slip 2",
   and so on — independent of which actual date each one fell on, and
   independent of other people's deposit counts. $slip_rows is already
   ordered by delivery_person then delivery_date then id (see the query
   above), so a running per-person counter gives the correct ordinal
   directly. ── */
$slip_matrix = [];   // [ordinal][delivery_person] = amount
$slip_meta   = [];   // [ordinal] = ['slip_ref'=>?, 'bank_name'=>?] (informational, from first occurrence)
$person_ord  = [];   // delivery_person => running deposit count
foreach ($slip_rows as $r) {
    $dpn = $r['delivery_person'];
    $person_ord[$dpn] = ($person_ord[$dpn] ?? 0) + 1;
    $ord = $person_ord[$dpn];
    $slip_matrix[$ord][$dpn] = ($slip_matrix[$ord][$dpn] ?? 0) + floatval($r['amount']);
    if (!isset($slip_meta[$ord])) $slip_meta[$ord] = [
        'slip_ref'   => $r['slip_ref']   ?? null,
        'bank_name'  => $r['bank_name']  ?? null,
    ];
}
$max_slip_no = $slip_meta ? max(array_keys($slip_meta)) : 0;

/* ── Back Office Collection column = the total cash that actually arrived
   at Back Office (i.e. what delivery persons handed over to them). This is
   read straight off $totals['handed_cc'] — already computed & verified
   above — no new/unverified query against your schema. ── */
$bo_handed_total = $totals['handed_cc'];

/* ══════════════════════════════════════════════════════════════
   Back Office Float Opening / Carried-Forward Balance
   Mirrors the live-balance formula in bo_cash_deposit_dp.php:
     IN  = SUM(cc_cash_deposit_dp.amount) WHERE handed_over_bo = 1
     OUT = SUM(bo_bank_deposits_dp.amount)
   but restricted to transactions dated on/before $asAt (falling back to
   created_at when deposit_date is NULL), so the balance can be
   reconstructed as at any date rather than only live/today:
     Opening Balance = balance as at the day BEFORE date_from
                        (what the float stood at when this period began)
     C/F Balance      = balance as at date_to
                        (this period's closing / carried-forward figure)
   ══════════════════════════════════════════════════════════════ */
if (!function_exists('getBoDpBalanceAsAt')) {
    function getBoDpBalanceAsAt($conn, $asAt) {
        $asAt = mysqli_real_escape_string($conn, $asAt);

        $r1 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COALESCE(SUM(amount),0) AS t
             FROM cc_cash_deposit_dp
             WHERE handed_over_bo = 1
               AND (
                    (deposit_date IS NOT NULL AND deposit_date <= '$asAt')
                 OR (deposit_date IS NULL AND DATE(created_at) <= '$asAt')
               )"));

        $r2 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COALESCE(SUM(amount),0) AS t
             FROM bo_bank_deposits_dp
             WHERE (
                    (deposit_date IS NOT NULL AND deposit_date <= '$asAt')
                 OR (deposit_date IS NULL AND DATE(created_at) <= '$asAt')
               )"));

        return floatval($r1['t']) - floatval($r2['t']);
    }
}

$bo_opening_date    = date('Y-m-d', strtotime($date_from.' -1 day'));
$bo_opening_balance = getBoDpBalanceAsAt($conn, $bo_opening_date);
$bo_cf_balance      = getBoDpBalanceAsAt($conn, $date_to);

/* row definitions for the pivot table — same order as Cash_Collection_Report.xlsx:
   'metric'  → reads $totals[key] / $person_totals[dp][key] (as built above)
   'nodata'  → row exists for structural parity with the xlsx but this system
               doesn't track that figure anywhere yet, so it always shows —
   'slip'    → one row per real bank deposit slip, from the matrix above
   'divider' → section header row, no values
   'highlight' (extra flag, not a type) → row gets a strong highlight fill so
               it stands out from the rest of the table on screen and print;
               used on Total Cash Collection / Hand Over to Back Office /
               Total Deposit & BO Handover.                                     */
$pivot_rows = [
    ['label'=>'Back Office Float Opening Balance',   'type'=>'balance', 'balance_value'=>$bo_opening_balance],
    ['label'=>'CC Cash Collection - Daily Sales',    'key'=>'cc_daily_sale', 'bo_value'=>$bo_cats['cc_daily_sale']],
    ['label'=>'CC Cash Collection - Online Pmt',     'type'=>'nodata'],
    ['label'=>'CC Cash Collection - Credit Sales',   'key'=>'cc_rcvd_credit', 'bo_value'=>$bo_cats['cc_rcvd_credit']],
    ['label'=>'CC Cash Collection - Returned Chq',   'key'=>'cc_rcvd_rtn_chq', 'bo_value'=>$bo_cats['cc_rcvd_rtn_chq']],
    ['label'=>'CC Cash Collection - Rtnd Chq Sales', 'key'=>'cc_rcvd_sent_back', 'bo_value'=>$bo_cats['cc_rcvd_sent_back']],
    ['label'=>'CC Cash Collection - Return Charges', 'key'=>'cc_rcvd_rtn_chgs', 'bo_value'=>$bo_cats['cc_rcvd_rtn_chgs']],   // real category, not on the xlsx template
    ['label'=>'SR Cash Collection - Credit Sales',   'type'=>'sr', 'sr_value'=>$sr_cats['cc_rcvd_credit'], 'sr_key'=>'cc_rcvd_credit'],
    ['label'=>'SR Cash Collection - Returned Chq',   'type'=>'sr', 'sr_value'=>$sr_cats['cc_rcvd_rtn_chq'], 'sr_key'=>'cc_rcvd_rtn_chq'],
    ['label'=>'SR Cash Collection - Rtnd Chq Sales (+ Return Charges)', 'type'=>'sr', 'sr_value'=>$sr_cats['cc_rcvd_sent_back'], 'sr_key'=>'cc_rcvd_sent_back'],
    ['label'=>'Total Cash Collection',               'key'=>'cc_total', 'bold'=>true, 'highlight'=>true, 'bo_value'=>$bo_cc_total, 'sr_value'=>$sr_cc_total, 'sr_total_row'=>true],
    ['label'=>'Hand Over to Back Office',            'key'=>'handed_cc', 'bold'=>true, 'highlight'=>true, 'bo_value'=>$bo_handed_total, 'sr_value'=>$sr_handed_total, 'sr_handed_row'=>true],
];
/* NOTE: the old plain "Bank Deposits" section-divider row has been removed —
   the numbered "Bank Deposit Slip N" rows below now follow the Hand Over to
   Back Office row directly. */
for ($sn = 1; $sn <= $max_slip_no; $sn++) {
    $ref  = $slip_meta[$sn]['slip_ref']  ?? null;
    $bank = $slip_meta[$sn]['bank_name'] ?? null;
    $lbl  = 'Bank Deposit Slip '.$sn;
    if ($ref)  $lbl .= ' ['.$ref.']';
    if ($bank) $lbl .= ' — '.$bank;
    $pivot_rows[] = ['label'=>$lbl, 'type'=>'slip', 'seq'=>$sn];
}
$pivot_rows = array_merge($pivot_rows, [
    ['label'=>'Total Deposit & BO Handover',         'key'=>'banked', 'bold'=>true, 'highlight'=>true, 'bo_value'=>$bo_handed_total, 'sr_value'=>$sr_handed_total, 'sr_handed_row'=>true],
    ['label'=>'Back Office Float C/F Balance',       'type'=>'nodata'],
    ['label'=>'Cash Shortage/Excess',                'key'=>'new_short_excess', 'bold'=>true, 'variance'=>true],
    ['label'=>'Charged to Employee',                 'key'=>'p_charge'],
    ['label'=>'Charged to Company',                  'key'=>'p_absorb'],
    ['label'=>'Variance',                            'key'=>'pay_variance_calc', 'bold'=>true, 'variance'=>true],
]);
