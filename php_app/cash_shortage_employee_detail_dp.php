<?php
/* ══════════════════════════════════════════════════════════════════════════
   cash_shortage_employee_detail_dp.php
   ──────────────────────────────────────────────────────────────────────────
   DELIVERY-PERSON based employee cash-shortage detail report.

   This is the DP twin of cash_shortage_employee_detail.php.

   • Source of charges      : cash_summary_pay_allocations_dp  (delivery_person)
   • Source of cash figures : same computation as
                              cash_collection_by_delivery_person.php
                              (cc_total vs banked → new_short_excess)
   • No cross-charge transfers (DP model has none — short/excess = bank_diff)
   • Adds a "Charged to Employee" column (the actual pay allocation amount,
     which may be negative = excess credited back to the employee).

   Balance (running) =  Σ(Charged to Employee)  −  Σ(Salary Deductions)
   i.e. how much this employee still owes for DP shortages.
══════════════════════════════════════════════════════════════════════════ */

/* ── AJAX guard (kept for parity with the SR report) ── */
$_ajax_action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($_ajax_action !== '') {
    while (ob_get_level() > 0) ob_end_clean();
    error_reporting(0);
    ini_set('display_errors', 0);
    require_once 'config.php';
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

ob_start();
include 'config.php';
include 'header.php';

/* ═══ PARAMS ═══ */
$employee_id = intval($_GET['employee_id'] ?? 0);
$date_from   = $_GET['date_from'] ?? date('Y-m-01');
$date_to     = $_GET['date_to']   ?? date('Y-m-d');
$f_dp        = trim($_GET['delivery_person'] ?? '');
$df          = mysqli_real_escape_string($conn, $date_from);
$dt          = mysqli_real_escape_string($conn, $date_to);
$eid_esc     = intval($employee_id);
$dp_esc      = $f_dp ? mysqli_real_escape_string($conn, $f_dp) : '';

/* ─── Employee info ─── */
$emp_name = '';
$emp_code = '';
if ($eid_esc) {
    $er = mysqli_query($conn,
        "SELECT id,
                COALESCE(NULLIF(employee_id,''), CONCAT('EMP-',id))                          AS emp_code,
                COALESCE(NULLIF(name_with_initials,''), NULLIF(employee_full_name,''),
                         CONCAT('Employee #',id))                                            AS emp_name
         FROM employees WHERE id = $eid_esc LIMIT 1");
    if ($er && $row = mysqli_fetch_assoc($er)) {
        $emp_name = $row['emp_name'];
        $emp_code = $row['emp_code'];
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   STEP 1 — which (date, delivery_person) pairs was this employee charged on,
            and how much (signed; negative = credited back)?
══════════════════════════════════════════════════════════════════════════ */
$charge_rows = [];           // each: ['pay_date','delivery_person','charged']
$dp_set      = [];           // distinct delivery persons involved
if ($eid_esc) {
    $cq = mysqli_query($conn, "
        SELECT pay_date, delivery_person, COALESCE(SUM(amount),0) AS charged
        FROM cash_summary_pay_allocations_dp
        WHERE entry_type = 'charge'
          AND employee_id = $eid_esc
          AND pay_date BETWEEN '$df' AND '$dt'
          " . ($dp_esc ? "AND delivery_person = '$dp_esc'" : '') . "
        GROUP BY pay_date, delivery_person
        ORDER BY pay_date ASC, delivery_person ASC
    ");
    if ($cq) while ($r = mysqli_fetch_assoc($cq)) {
        $charge_rows[] = $r;
        $dp_set[$r['delivery_person']] = true;
    }
}

/* Build an escaped IN()-list of the delivery persons we care about */
$dp_in = '';
if (!empty($dp_set)) {
    $dp_in = implode("','", array_map(
        fn($s) => mysqli_real_escape_string($conn, $s),
        array_keys($dp_set)
    ));
}
$dp_filter = $dp_in !== '' ? " AND %COL% IN ('$dp_in') " : " AND 1=0 ";

/* ═══════════════════════════════════════════════════════════════════════
   STEP 2 — replicate cash_collection_by_delivery_person.php cash position
            for those delivery persons over the date range.
══════════════════════════════════════════════════════════════════════════ */

/* 2a — CC Daily Sale (cash invoice collection by CC) */
$cc_daily_map = [];
if ($dp_in !== '') {
    $f = str_replace('%COL%', 'siid.delivery_person', $dp_filter);
    $q = mysqli_query($conn, "
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
          $f
        GROUP BY siid.delivery_date, siid.delivery_person
    ");
    if ($q) while ($r = mysqli_fetch_assoc($q))
        $cc_daily_map[$r['delivery_date'].'|'.$r['delivery_person']] = floatval($r['cc_daily_sale']);
}

/* 2b — CC breakdown (credit / return cheque / sent-back) by payment_date|dp */
$cc_bd_map = [];
if ($dp_in !== '') {
    $f = str_replace('%COL%', 'ip.delivery_person', $dp_filter);
    $q = mysqli_query($conn, "
        SELECT ip.payment_date, ip.delivery_person,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
                              AND LOWER(ip.payment_source) LIKE '%credit%'
                         THEN ip.amount ELSE 0 END),0) AS cc_rcvd_credit,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
                              AND ip.payment_source='return_cheque_settlement'
                         THEN ip.amount ELSE 0 END),0) AS cc_rcvd_rtn_chq,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
                              AND ip.payment_source='sentback_cheque_settlement'
                         THEN ip.amount ELSE 0 END),0) AS cc_rcvd_sent_back
        FROM invoice_payments ip
        WHERE ip.payment_method='cash' AND ip.is_reversed=0
          AND ip.payment_date BETWEEN '$df' AND '$dt'
          AND ip.delivery_person IS NOT NULL AND ip.delivery_person != ''
          $f
        GROUP BY ip.payment_date, ip.delivery_person
    ");
    if ($q) while ($r = mysqli_fetch_assoc($q))
        $cc_bd_map[$r['payment_date'].'|'.$r['delivery_person']] = $r;
}

/* 2c — Return Charges received (authoritative source: rc_settlement_payments) */
$rc_rtnchg_map = [];
$rc_tbl_exists = false; $rc_has_dp = false;
$_rc_tchk = mysqli_query($conn, "SHOW TABLES LIKE 'rc_settlement_payments'");
if ($_rc_tchk && mysqli_num_rows($_rc_tchk) > 0) {
    $rc_tbl_exists = true;
    $_rc_cchk = mysqli_query($conn, "SHOW COLUMNS FROM rc_settlement_payments LIKE 'delivery_person'");
    if ($_rc_cchk && mysqli_num_rows($_rc_cchk) > 0) $rc_has_dp = true;
}
if ($dp_in !== '' && $rc_tbl_exists && $rc_has_dp) {
    $f = str_replace('%COL%', 'delivery_person', $dp_filter);
    $q = mysqli_query($conn, "
        SELECT payment_date, delivery_person, COALESCE(SUM(amount),0) AS rc_rtn_chgs
        FROM rc_settlement_payments
        WHERE payment_method='cash'
          AND payment_date BETWEEN '$df' AND '$dt'
          AND delivery_person IS NOT NULL AND delivery_person != ''
          $f
        GROUP BY payment_date, delivery_person
    ");
    if ($q) while ($r = mysqli_fetch_assoc($q))
        $rc_rtnchg_map[$r['payment_date'].'|'.$r['delivery_person']] = floatval($r['rc_rtn_chgs']);
}

/* 2d — Deposits (banked / handed-over) by delivery_date|dp */
$dep_map = [];
if ($dp_in !== '') {
    $f = str_replace('%COL%', 'p.delivery_person', $dp_filter);
    $q = mysqli_query($conn, "
        SELECT p.delivery_date, p.delivery_person,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=0 AND LOWER(COALESCE(d.collected_by,''))='cc' THEN p.amount ELSE 0 END),0) AS banked_cc,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=1 AND LOWER(COALESCE(d.collected_by,''))='cc' THEN p.amount ELSE 0 END),0) AS handed_cc
        FROM cc_cash_deposit_dp_persons p
        INNER JOIN cc_cash_deposit_dp d ON d.id = p.deposit_id
        WHERE p.delivery_date BETWEEN '$df' AND '$dt'
          AND p.delivery_date IS NOT NULL AND p.delivery_date != '0000-00-00'
          AND p.delivery_person IS NOT NULL AND p.delivery_person != ''
          $f
        GROUP BY p.delivery_date, p.delivery_person
    ");
    if ($q) while ($r = mysqli_fetch_assoc($q))
        $dep_map[$r['delivery_date'].'|'.$r['delivery_person']] = $r;
}

/* ═══════════════════════════════════════════════════════════════════════
   STEP 3 — per (date, dp): compute cc_total, banked, new_short_excess,
            then group by DATE (summing across the DPs this emp was charged on).
            Short/excess kept INDEPENDENT per DP (never cancel across DPs).
══════════════════════════════════════════════════════════════════════════ */
$daily_by_date = [];
foreach ($charge_rows as $cr) {
    $d   = $cr['pay_date'];
    $dp  = $cr['delivery_person'];
    $k   = $d.'|'.$dp;
    $charged = floatval($cr['charged']);

    $cc_daily      = $cc_daily_map[$k]              ?? 0;
    $bd            = $cc_bd_map[$k]                 ?? [];
    $cc_credit     = floatval($bd['cc_rcvd_credit']    ?? 0);
    $cc_rtn_chq    = floatval($bd['cc_rcvd_rtn_chq']   ?? 0);
    $cc_sent_back  = floatval($bd['cc_rcvd_sent_back'] ?? 0);
    $cc_rtn_chgs   = floatval($rc_rtnchg_map[$k]    ?? 0);

    $cc_total = $cc_daily + $cc_credit + $cc_rtn_chq + $cc_rtn_chgs + $cc_sent_back;

    $dm        = $dep_map[$k] ?? [];
    $banked_cc = floatval($dm['banked_cc'] ?? 0);
    $handed_cc = floatval($dm['handed_cc'] ?? 0);
    $banked    = $banked_cc + $handed_cc;

    $new_diff  = $cc_total - $banked;     // new_short_excess (matches DP page)

    if (!isset($daily_by_date[$d])) {
        $daily_by_date[$d] = [
            'col_date'        => $d,
            'total_collected' => 0,
            'total_deposited' => 0,
            'bo_handover'     => 0,
            'cash_short'      => 0,
            'cash_excess'     => 0,
            'charged'         => 0,
            'dp_tags'         => [],   // [ ['dp'=>, 'charged'=>, 'nse'=>], ... ]
        ];
    }
    $daily_by_date[$d]['total_collected'] += $cc_total;
    $daily_by_date[$d]['total_deposited'] += $banked_cc;
    $daily_by_date[$d]['bo_handover']     += $handed_cc;
    $daily_by_date[$d]['charged']         += $charged;
    if ($new_diff >  0.005) $daily_by_date[$d]['cash_short']  += $new_diff;
    if ($new_diff < -0.005) $daily_by_date[$d]['cash_excess'] += (-$new_diff);
    $daily_by_date[$d]['dp_tags'][] = ['dp'=>$dp, 'charged'=>$charged, 'nse'=>$new_diff];
}
$daily_rows = array_values($daily_by_date);

/* ─── Payroll deductions (employee-level, identical to SR report) ─── */
$ded_rows = [];
$dqr = mysqli_query($conn,
    "SELECT id, charge_date, amount, description, payroll_year, payroll_month
     FROM payroll_payments_log
     WHERE employee_id = $eid_esc
       AND charge_date BETWEEN '$df' AND '$dt'
     ORDER BY charge_date ASC, id ASC");
if ($dqr) while ($r = mysqli_fetch_assoc($dqr)) $ded_rows[] = $r;

$ded_by_date = [];
$MN = ['','January','February','March','April','May','June',
       'July','August','September','October','November','December'];
foreach ($ded_rows as $d) $ded_by_date[$d['charge_date']][] = $d;

/* ─── Merge dates (collection ∪ deductions) ─── */
$all_dates = [];
foreach ($daily_rows as $d) $all_dates[$d['col_date']]   = true;
foreach ($ded_rows   as $d) $all_dates[$d['charge_date']] = true;
ksort($all_dates);

$day_by_date = [];
foreach ($daily_rows as $d) $day_by_date[$d['col_date']] = $d;

/* ─── Running totals ─── */
$tot_collected  = 0; $tot_deposited = 0; $tot_bo      = 0;
$tot_short      = 0; $tot_excess    = 0; $tot_charged = 0;
$tot_deductions = 0; $running_bal   = 0;

$merged_rows = [];
foreach ($all_dates as $date => $_) {
    $day  = $day_by_date[$date] ?? null;
    $deds = $ded_by_date[$date] ?? [];

    $total_collected = $day ? floatval($day['total_collected']) : 0;
    $total_deposited = $day ? floatval($day['total_deposited']) : 0;
    $bo_handover     = $day ? floatval($day['bo_handover'])     : 0;
    $cash_short      = $day ? floatval($day['cash_short'])      : 0;
    $cash_excess     = $day ? floatval($day['cash_excess'])     : 0;
    $charged         = $day ? floatval($day['charged'])         : 0;
    $dp_tags         = $day ? $day['dp_tags']                   : [];

    $day_ded_total = 0;
    $ded_labels    = [];
    foreach ($deds as $ded) {
        $day_ded_total += floatval($ded['amount']);
        $lbl = $ded['description'] ?: 'Salary Deduction';
        if ($ded['payroll_year'] && $ded['payroll_month'])
            $lbl .= ' (' . $MN[$ded['payroll_month']] . ' ' . $ded['payroll_year'] . ')';
        $ded_labels[] = $lbl;
    }

    /* outstanding owed = charged − deductions recovered */
    $running_bal += $charged - $day_ded_total;

    $merged_rows[] = [
        'date'            => $date,
        'total_collected' => $total_collected,
        'total_deposited' => $total_deposited,
        'bo_handover'     => $bo_handover,
        'cash_short'      => $cash_short,
        'cash_excess'     => $cash_excess,
        'charged'         => $charged,
        'deduction'       => $day_ded_total,
        'ded_labels'      => $ded_labels,
        'dp_tags'         => $dp_tags,
        'balance'         => $running_bal,
    ];

    $tot_collected  += $total_collected;
    $tot_deposited  += $total_deposited;
    $tot_bo         += $bo_handover;
    $tot_short      += $cash_short;
    $tot_excess     += $cash_excess;
    $tot_charged    += $charged;
    $tot_deductions += $day_ded_total;
}
$final_bal = $running_bal;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Employee DP Cash Detail — <?php echo htmlspecialchars($emp_name); ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap');
:root{
    --bg:#f5f7fa;--surface:#fff;--surface2:#f0f3f7;--surface3:#e8ecf2;
    --bdr:#dde2ea;--bdr2:#eaecf0;--tx:#111827;--txm:#4b5563;--txs:#9ca3af;
    --fn:'Plus Jakarta Sans',sans-serif;--mn:'JetBrains Mono',monospace;--r:12px;
    --red:#dc2626;--red-lt:#fee2e2;--red-md:#fca5a5;
    --green:#16a34a;--green-lt:#dcfce7;--green-md:#86efac;
    --amber:#d97706;--amber-lt:#fef3c7;--amber-md:#fcd34d;
    --blue:#2563eb;--blue-lt:#dbeafe;--blue-md:#93c5fd;
    --purple:#7c3aed;--purple-lt:#ede9fe;--purple-md:#c4b5fd;
    --cyan:#0891b2;--cyan-lt:#cffafe;--cyan-md:#a5f3fc;
    --shadow-sm:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);
    --shadow-md:0 4px 16px rgba(0,0,0,.08),0 2px 4px rgba(0,0,0,.04);
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:13px;}
.pg{padding:20px 18px 60px;max-width:1340px;margin:0 auto;}

/* ── Top bar ── */
.topbar{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;}
.pg-brand{display:flex;align-items:center;gap:12px;}
.pg-icon{width:44px;height:44px;background:linear-gradient(135deg,#1a6640,#155535);border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:19px;color:#fff;flex-shrink:0;box-shadow:0 4px 14px rgba(26,102,64,.28);}
.pg-h1{font-size:19px;font-weight:800;letter-spacing:-.02em;}
.pg-h1 em{color:var(--green);font-style:normal;}
.pg-sub{font-size:11px;color:var(--txm);margin-top:3px;}
.topbtns{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}

.btn-back{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;background:var(--surface2);color:var(--txm);border:1.5px solid var(--bdr);border-radius:8px;font-size:12px;font-weight:600;text-decoration:none;transition:all .15s;}
.btn-back:hover{background:var(--surface3);color:var(--tx);}
.btn-excel{display:inline-flex;align-items:center;gap:6px;padding:9px 15px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;box-shadow:0 2px 6px rgba(22,163,74,.22);}
.btn-excel:hover{transform:translateY(-1px);}
.btn-print{display:inline-flex;align-items:center;gap:6px;padding:9px 13px;background:var(--surface2);color:var(--txm);border:1.5px solid var(--bdr);border-radius:8px;font-size:12px;cursor:pointer;}
.btn-print:hover{background:var(--surface3);color:var(--tx);}

/* ── Summary cards ── */
.sc-row{display:grid;grid-template-columns:repeat(7,1fr);gap:12px;margin-bottom:16px;}
.sc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:14px 16px;box-shadow:var(--shadow-sm);position:relative;overflow:hidden;}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.sc.red::before{background:linear-gradient(90deg,var(--red),#ef4444);}
.sc.grn::before{background:linear-gradient(90deg,var(--green),#22c55e);}
.sc.amb::before{background:linear-gradient(90deg,var(--amber),#f59e0b);}
.sc.prp::before{background:linear-gradient(90deg,var(--purple),#8b5cf6);}
.sc.blu::before{background:linear-gradient(90deg,var(--blue),#3b82f6);}
.sc.cyn::before{background:linear-gradient(90deg,var(--cyan),#06b6d4);}
.sc-lbl{font-size:9.5px;font-weight:700;color:var(--txm);text-transform:uppercase;letter-spacing:.08em;margin-bottom:5px;}
.sc-val{font-family:var(--mn);font-size:15px;font-weight:700;}
.sc.red .sc-val{color:var(--red);}
.sc.grn .sc-val{color:var(--green);}
.sc.amb .sc-val{color:var(--amber);}
.sc.prp .sc-val{color:var(--purple);}
.sc.blu .sc-val{color:var(--blue);}
.sc.cyn .sc-val{color:var(--cyan);}

/* ── Table ── */
.tc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);overflow:hidden;box-shadow:var(--shadow-sm);}
.tc-bar{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;border-bottom:1px solid var(--bdr);flex-wrap:wrap;gap:8px;}
.tc-ttl{font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.pill{padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;}
.p-prp{background:var(--purple-lt);color:var(--purple);border:1px solid var(--purple-md);}
.p-blue{background:var(--blue-lt);color:var(--blue);border:1px solid var(--blue-md);}
.p-grn{background:var(--green-lt);color:var(--green);border:1px solid var(--green-md);}
.tscroll{overflow-x:auto;}

table.edt{width:100%;border-collapse:collapse;font-size:12.5px;}
.edt thead th{padding:10px 14px;font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--txm);background:var(--surface2);border-bottom:2px solid var(--bdr);white-space:nowrap;text-align:right;}
.edt thead th.tl{text-align:left;}
.edt thead th.col-sht{border-top:3px solid var(--red);}
.edt thead th.col-exc{border-top:3px solid var(--green);}
.edt thead th.col-bal{border-top:3px solid var(--purple);}
.edt thead th.col-coll{border-top:3px solid var(--blue);}
.edt thead th.col-dep{border-top:3px solid var(--amber);}
.edt thead th.col-ded{border-top:3px solid var(--purple);}
.edt thead th.col-chg{border-top:3px solid var(--cyan);}

.edt tbody td{padding:9px 14px;border-bottom:1px solid var(--bdr2);text-align:right;white-space:nowrap;}
.edt tbody td.tl{text-align:left;}
.edt tbody tr:nth-child(even) td{background:#fafbfc;}
.edt tbody tr:hover td{background:#f0f4ff !important;}

.edt tbody tr.has-ded td{background:#fdf4ff !important;}
.edt tbody tr.has-ded:hover td{background:#ede9fe !important;}
.edt tbody tr.has-chg td{background:#f0fdf6 !important;}
.edt tbody tr.has-chg:hover td{background:#dcfce7 !important;}
.edt tbody tr.has-chg.has-ded td{background:#f3f8f4 !important;}

.ded-tag{display:inline-block;background:var(--purple-lt);color:var(--purple);border:1px solid var(--purple-md);border-radius:6px;font-size:9.5px;font-weight:700;padding:1px 6px;margin-top:2px;font-family:var(--fn);}
.dp-tag{display:inline-block;background:var(--green-lt);color:#166534;border:1px solid var(--green-md);border-radius:6px;font-size:9.5px;font-weight:700;padding:1px 6px;margin-top:2px;font-family:var(--fn);}
.dp-tag .nse-sht{color:#dc2626;}
.dp-tag .nse-exc{color:#0369a1;}

/* Number classes */
.v-red{color:var(--red);font-family:var(--mn);font-weight:700;}
.v-grn{color:var(--green);font-family:var(--mn);font-weight:700;}
.v-amb{color:var(--amber);font-family:var(--mn);font-weight:700;}
.v-prp{color:var(--purple);font-family:var(--mn);font-weight:700;}
.v-cyn{color:var(--cyan);font-family:var(--mn);font-weight:700;}
.v-blu2{color:#0369a1;font-family:var(--mn);font-weight:700;}
.v-prp-zero{color:var(--green);font-family:var(--mn);font-weight:700;}
.mn{font-family:var(--mn);}
.dash{color:var(--txs);}

/* Footer / totals row */
.edt tfoot td{padding:10px 14px;font-weight:700;font-size:12px;background:#f8fafc;color:var(--txm);text-align:right;border-top:2px solid var(--bdr);white-space:nowrap;font-family:var(--mn);}
.edt tfoot td.tl{text-align:left;font-family:var(--fn);font-size:11.5px;}
.edt tfoot td.f-red{color:var(--red);}
.edt tfoot td.f-grn{color:var(--green);}
.edt tfoot td.f-prp{color:var(--purple);}
.edt tfoot td.f-blu{color:var(--blue);}
.edt tfoot td.f-amb{color:var(--amber);}
.edt tfoot td.f-cyn{color:var(--cyan);}

.empty{text-align:center;padding:70px 20px;color:var(--txm);}
.empty .ico{font-size:48px;display:block;margin-bottom:14px;opacity:.15;}
.empty p{font-size:14px;font-weight:700;color:var(--tx);margin-bottom:4px;}

/* ── Print ── */
@media print{
    body{background:#fff;font-size:11px;}
    .no-print{display:none !important;}
    .pg{padding:6px 8px 20px;}
    .print-header{display:block !important;}
    .tc{box-shadow:none;border:1px solid #ccc;}
    .tscroll{overflow:visible;}
    table.edt thead th,table.edt tfoot td{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    table.edt tbody tr.has-ded td,
    table.edt tbody tr.has-chg td{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    table.edt{font-size:10px;}
    table.edt thead th,table.edt tbody td,table.edt tfoot td{padding:5px 9px;}
    .sc-row{grid-template-columns:repeat(4,1fr);gap:7px;}
    .sc{box-shadow:none;border:1px solid #dde2ea;padding:8px 10px;}
    .sc-val{font-size:13px;}
}
.print-header{display:none;text-align:center;padding:10px 0 8px;border-bottom:2px solid #1a6640;margin-bottom:12px;}
.print-header h2{font-size:16px;font-weight:800;}
.print-header p{font-size:11px;color:#4b5563;margin-top:3px;}
</style>
</head>
<body>

<div class="pg">

<!-- Print header (hidden on screen) -->
<div class="print-header">
    <h2>DP Cash Shortage Detail — <?php echo htmlspecialchars($emp_name); ?> (<?php echo htmlspecialchars($emp_code); ?>)</h2>
    <p><?php
        echo date('d M Y',strtotime($date_from)).' — '.date('d M Y',strtotime($date_to));
        echo ' · Printed: '.date('d M Y H:i');
    ?></p>
</div>

<!-- Top bar -->
<div class="topbar no-print">
    <div class="pg-brand">
        <div class="pg-icon"><i class="fa-solid fa-truck-fast"></i></div>
        <div>
            <div class="pg-h1"><em><?php echo htmlspecialchars($emp_name); ?></em> — DP Cash Detail</div>
            <div class="pg-sub">
                <?php echo htmlspecialchars($emp_code); ?>
                &nbsp;·&nbsp;
                <?php echo date('d M Y',strtotime($date_from)); ?> – <?php echo date('d M Y',strtotime($date_to)); ?>
                &nbsp;·&nbsp; Delivery-Person Collection · DP Short / Excess · Charged to Employee · Salary Deductions · Running Balance
            </div>
        </div>
    </div>
    <div class="topbtns">
        <?php
        $back_url = 'cash_collection_by_delivery_person.php?search=1'
                  . '&date_from='.urlencode($date_from)
                  . '&date_to='.urlencode($date_to)
                  . ($f_dp ? '&delivery_person='.urlencode($f_dp) : '');
        ?>
        <a href="<?php echo $back_url; ?>" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Back
        </a>
        <?php if (!empty($merged_rows)): ?>
        <button onclick="exportToExcel()" class="btn-excel"><i class="fa-solid fa-file-excel"></i> Excel</button>
        <button onclick="window.print()"  class="btn-print"><i class="fa-solid fa-print"></i> Print</button>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($merged_rows)): ?>
<div class="tc">
    <div class="empty">
        <i class="fa-solid fa-inbox ico"></i>
        <p>No records found</p>
        <small>
            <?php if (!$eid_esc): ?>No employee selected ·<?php endif; ?>
            <?php echo date('d M Y',strtotime($date_from)).' – '.date('d M Y',strtotime($date_to)); ?>
        </small>
    </div>
</div>
<?php else: ?>

<!-- Summary cards -->
<div class="sc-row no-print">
    <div class="sc blu">
        <div class="sc-lbl"><i class="fa-solid fa-money-bill-wave"></i> Total Collected</div>
        <div class="sc-val">Rs. <?php echo number_format($tot_collected, 2); ?></div>
    </div>
    <div class="sc amb">
        <div class="sc-lbl"><i class="fa-solid fa-building-columns"></i> Total Deposited</div>
        <div class="sc-val">Rs. <?php echo number_format($tot_deposited, 2); ?></div>
    </div>
    <div class="sc grn">
        <div class="sc-lbl"><i class="fa-solid fa-handshake"></i> BO Handover</div>
        <div class="sc-val">Rs. <?php echo number_format($tot_bo, 2); ?></div>
    </div>
    <div class="sc red">
        <div class="sc-lbl"><i class="fa-solid fa-circle-arrow-down"></i> DP Cash Short</div>
        <div class="sc-val">Rs. <?php echo number_format($tot_short, 2); ?></div>
    </div>
    <div class="sc grn">
        <div class="sc-lbl"><i class="fa-solid fa-circle-arrow-up"></i> DP Cash Excess</div>
        <div class="sc-val">Rs. <?php echo number_format($tot_excess, 2); ?></div>
    </div>
    <div class="sc cyn">
        <div class="sc-lbl"><i class="fa-solid fa-user-minus"></i> Charged to Emp</div>
        <div class="sc-val">Rs. <?php echo number_format($tot_charged, 2); ?></div>
    </div>
    <div class="sc <?php echo $final_bal > 0.005 ? 'red' : ($final_bal < -0.005 ? 'amb' : 'grn'); ?>">
        <div class="sc-lbl"><i class="fa-solid fa-scale-balanced"></i> Final Balance</div>
        <div class="sc-val">Rs. <?php echo number_format($final_bal, 2); ?></div>
    </div>
</div>

<!-- Table -->
<div class="tc">
    <div class="tc-bar no-print">
        <div class="tc-ttl">
            <i class="fa-solid fa-table-list" style="color:var(--green);"></i>
            DP Detail Ledger
            <span class="pill p-grn"><?php echo htmlspecialchars($emp_name); ?></span>
            <span class="pill p-blue"><?php echo date('d M Y',strtotime($date_from)); ?> – <?php echo date('d M Y',strtotime($date_to)); ?></span>
            <span class="pill p-prp"><i class="fa-solid fa-truck" style="font-size:8px;"></i> Delivery-Person based</span>
        </div>
        <div style="font-size:11px;color:var(--txs);">
            DP Short/Excess = collection − deposit &nbsp;·&nbsp; Balance = Σ(Charged − Salary Deductions)
        </div>
    </div>

    <div class="tscroll">
    <table class="edt" id="edtMain">
      <thead>
        <tr>
            <th class="tl" style="min-width:120px;">Date</th>
            <th class="col-coll" style="min-width:130px;">Total Collected</th>
            <th class="col-dep"  style="min-width:130px;">Total Deposited</th>
            <th class="col-dep"  style="min-width:110px;">BO Handover</th>
            <th class="col-sht"  style="min-width:115px;" title="Delivery person's cash short for this date">DP Short</th>
            <th class="col-exc"  style="min-width:115px;" title="Delivery person's cash excess for this date">DP Excess</th>
            <th class="col-chg"  style="min-width:125px;" title="Amount charged to this employee (negative = credited back)">Charged to Emp</th>
            <th class="col-ded"  style="min-width:130px;">Sal. Deduction</th>
            <th class="col-bal"  style="min-width:120px;">Balance</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($merged_rows as $row):
          $hasDed = $row['deduction'] > 0.005;
          $hasChg = abs($row['charged']) > 0.005;
          $rowCls = '';
          if ($hasDed && $hasChg) $rowCls = 'has-ded has-chg';
          elseif ($hasDed)        $rowCls = 'has-ded';
          elseif ($hasChg)        $rowCls = 'has-chg';
      ?>
        <tr class="<?php echo $rowCls; ?>">
            <td class="tl mn" style="font-size:11.5px;">
                <?php echo date('d M Y', strtotime($row['date'])); ?>
                <?php if (!empty($row['dp_tags'])): ?>
                <br>
                <?php foreach ($row['dp_tags'] as $t):
                    $nse = floatval($t['nse']);
                    if ($nse > 0.005)      $nseTxt = '<span class="nse-sht">▼'.number_format($nse,2).'</span>';
                    elseif ($nse < -0.005) $nseTxt = '<span class="nse-exc">▲'.number_format(abs($nse),2).'</span>';
                    else                   $nseTxt = '0.00';
                ?>
                    <span class="dp-tag"><i class="fa-solid fa-truck" style="font-size:8px;margin-right:3px;"></i><?php
                        echo htmlspecialchars($t['dp']).' · '.$nseTxt; ?></span>
                <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($hasDed): ?>
                <br><?php foreach ($row['ded_labels'] as $lbl):
                    echo '<span class="ded-tag"><i class="fa-solid fa-wallet" style="font-size:8px;margin-right:3px;"></i>'.htmlspecialchars($lbl).'</span> ';
                endforeach; ?>
                <?php endif; ?>
            </td>
            <td class="mn"><?php echo $row['total_collected'] > 0 ? number_format($row['total_collected'],2) : '<span class="dash">—</span>'; ?></td>
            <td class="mn"><?php echo $row['total_deposited'] > 0 ? number_format($row['total_deposited'],2) : '<span class="dash">—</span>'; ?></td>
            <td class="mn"><?php echo $row['bo_handover']     > 0 ? number_format($row['bo_handover'],2)    : '<span class="dash">—</span>'; ?></td>
            <td><?php echo $row['cash_short']  > 0.005 ? '<span class="v-red">'.number_format($row['cash_short'],2).'</span>'  : '<span class="dash">—</span>'; ?></td>
            <td><?php echo $row['cash_excess'] > 0.005 ? '<span class="v-grn">'.number_format($row['cash_excess'],2).'</span>' : '<span class="dash">—</span>'; ?></td>
            <td><?php
                $c = $row['charged'];
                if ($c > 0.005)       echo '<span class="v-cyn">'.number_format($c,2).'</span>';
                elseif ($c < -0.005)  echo '<span class="v-blu2">('.number_format(abs($c),2).')</span>';
                else                  echo '<span class="dash">—</span>';
            ?></td>
            <td><?php echo $hasDed ? '<span class="v-prp">'.number_format($row['deduction'],2).'</span>' : '<span class="dash">—</span>'; ?></td>
            <td><?php
                $b = $row['balance'];
                if ($b > 0.005)       echo '<span class="v-red">'.number_format($b,2).'</span>';
                elseif ($b < -0.005)  echo '<span class="v-amb">'.number_format($b,2).'</span>';
                else                  echo '<span class="v-prp-zero">0.00 ✓</span>';
            ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
            <td class="tl">TOTAL</td>
            <td class="f-blu"><?php echo $tot_collected  > 0 ? number_format($tot_collected,2)  : '—'; ?></td>
            <td class="f-amb"><?php echo $tot_deposited  > 0 ? number_format($tot_deposited,2)  : '—'; ?></td>
            <td class="f-amb"><?php echo $tot_bo         > 0 ? number_format($tot_bo,2)         : '—'; ?></td>
            <td class="f-red"><?php echo $tot_short      > 0 ? number_format($tot_short,2)      : '—'; ?></td>
            <td class="f-grn"><?php echo $tot_excess     > 0 ? number_format($tot_excess,2)     : '—'; ?></td>
            <td class="f-cyn"><?php
                if (abs($tot_charged) < 0.005) echo '—';
                elseif ($tot_charged < 0)      echo '<span style="color:#0369a1;">('.number_format(abs($tot_charged),2).')</span>';
                else                           echo number_format($tot_charged,2);
            ?></td>
            <td class="f-prp"><?php echo $tot_deductions > 0 ? number_format($tot_deductions,2) : '—'; ?></td>
            <td class="f-prp"><?php
                if ($final_bal > 0.005)       echo '<span class="f-red">'.number_format($final_bal,2).'</span>';
                elseif ($final_bal < -0.005)  echo '<span class="f-amb">'.number_format($final_bal,2).'</span>';
                else                          echo '<span class="f-grn">0.00 ✓</span>';
            ?></td>
        </tr>
      </tfoot>
    </table>
    </div>
</div>
<?php endif; ?>
</div><!-- /pg -->

<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<?php
$js_rows = [];
foreach ($merged_rows as $row) {
    $ded_str = implode('; ', $row['ded_labels']);
    $dp_str  = implode('; ', array_map(function($t){
        $nse = floatval($t['nse']);
        $tag = $nse > 0.005 ? 'SHORT ' : ($nse < -0.005 ? 'EXCESS ' : '');
        return $t['dp'].' ('.$tag.number_format(abs($nse),2).')';
    }, $row['dp_tags']));
    $js_rows[] = [
        'date'            => $row['date'],
        'dp_list'         => $dp_str,
        'total_collected' => $row['total_collected'],
        'total_deposited' => $row['total_deposited'],
        'bo_handover'     => $row['bo_handover'],
        'cash_short'      => $row['cash_short'],
        'cash_excess'     => $row['cash_excess'],
        'charged'         => $row['charged'],
        'deduction'       => $row['deduction'],
        'ded_label'       => $ded_str,
        'balance'         => $row['balance'],
    ];
}
?>
<script>
var ROWS     = <?php echo json_encode($js_rows, JSON_UNESCAPED_UNICODE); ?>;
var EMP_NAME = <?php echo json_encode($emp_name); ?>;
var EMP_CODE = <?php echo json_encode($emp_code); ?>;
var DATE_FROM= <?php echo json_encode(date('d M Y',strtotime($date_from))); ?>;
var DATE_TO  = <?php echo json_encode(date('d M Y',strtotime($date_to))); ?>;

function exportToExcel() {
    if (!ROWS || !ROWS.length) { alert('No data to export.'); return; }

    var titleRows = [
        ['DP Cash Shortage Detail Report'],
        ['Employee:', EMP_NAME + ' (' + EMP_CODE + ')'],
        ['Period:', DATE_FROM + ' — ' + DATE_TO],
        ['Generated:', new Date().toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})],
        ['Basis:', 'Delivery-Person collection vs deposit. Charged = pay allocation to this employee.'],
        ['Note:', 'Balance = cumulative (Charged to Employee − Salary Deductions).'],
        []
    ];

    var hdrs = ['Date','Delivery Person(s)','Total Collected','Total Deposited','BO Handover',
                'DP Short','DP Excess','Charged to Emp','Sal. Deduction','Deduction Notes','Balance'];

    var data = ROWS.map(function(r) {
        return [
            r.date,
            r.dp_list || '',
            parseFloat(r.total_collected)||0,
            parseFloat(r.total_deposited)||0,
            parseFloat(r.bo_handover)||0,
            parseFloat(r.cash_short)||0,
            parseFloat(r.cash_excess)||0,
            parseFloat(r.charged)||0,
            parseFloat(r.deduction)||0,
            r.ded_label || '',
            parseFloat(r.balance)||0
        ];
    });

    var allRows = titleRows.concat([hdrs], data);
    var ws = XLSX.utils.aoa_to_sheet(allRows);
    ws['!cols'] = [12,28,16,16,14,13,13,15,15,35,13].map(function(w){ return {wch:w}; });
    ws['!merges'] = [{ s:{r:0,c:0}, e:{r:0,c:10} }];

    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'DP Detail');

    var fname = 'DPCashDetail_'
        + EMP_CODE.replace(/[^a-zA-Z0-9]/g,'_')
        + '_' + DATE_FROM.replace(/ /g,'') + '_to_' + DATE_TO.replace(/ /g,'')
        + '.xlsx';
    XLSX.writeFile(wb, fname);
}
</script>
<?php include 'footer.php'; ?>
</body>
</html>
