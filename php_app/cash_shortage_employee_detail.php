<?php
/* ══════════════════════════════════════════════════════════════════════════
   AJAX HANDLERS — before any include/output
══════════════════════════════════════════════════════════════════════════ */
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
$df          = mysqli_real_escape_string($conn, $date_from);
$dt          = mysqli_real_escape_string($conn, $date_to);
$eid_esc     = intval($employee_id);

/* ─── Employee info ─── */
$emp_name = '';
$emp_code = '';
if ($eid_esc) {
    $er = mysqli_query($conn,
        "SELECT id,
                COALESCE(NULLIF(employee_id,''), CONCAT('EMP-',id))                         AS emp_code,
                COALESCE(NULLIF(name_with_initials,''), NULLIF(employee_full_name,''),
                         CONCAT('Employee #',id))                                            AS emp_name
         FROM employees WHERE id = $eid_esc LIMIT 1");
    if ($er && $row = mysqli_fetch_assoc($er)) {
        $emp_name = $row['emp_name'];
        $emp_code = $row['emp_code'];
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   MAIN DATA QUERY
   Step 1: get per-SR rows (raw bank_diff per SR per day)
   Step 2: fetch transfers per SR per day
   Step 3: apply formula:  new_short_excess = bank_diff - t_out + t_in
           then separate: if > 0  => cash_short
                          if < 0  => cash_excess
   Step 4: group by date in PHP — sum short and excess INDEPENDENTLY
══════════════════════════════════════════════════════════════════════════ */

/* Step 1 — per-SR cash collection vs deposit
   FIX: bank_diff must have whatever the COMPANY absorbed for that day/rep
   (entry_type='absorb', stored once per pay_date+sr_code) subtracted out,
   so an absorbed amount is never mixed into / counted against this
   employee's own shortage or excess. */
$sql_per_sr = "
    SELECT
        a.pay_date                                                    AS col_date,
        a.sr_code,
        COALESCE(coll.total_coll, 0)                                  AS total_collected,
        COALESCE(dep.banked, 0)                                       AS total_deposited,
        COALESCE(dep.handed, 0)                                       AS bo_handover,
        COALESCE(ab.absorb_total, 0)                                  AS absorb_total,
        COALESCE(coll.total_coll, 0) - COALESCE(dep.banked, 0)
                                     - COALESCE(dep.handed, 0)
                                     - COALESCE(ab.absorb_total, 0)   AS bank_diff
    FROM cash_summary_pay_allocations a

    LEFT JOIN (
        SELECT ip.payment_date AS pdate, fs.sr_code,
               COALESCE(SUM(ip.amount), 0) AS total_coll
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        INNER JOIN field_summary fs           ON fs.id  = fsd.field_summary_id
        WHERE ip.payment_method='cash' AND ip.is_reversed=0
          AND ip.payment_date BETWEEN '$df' AND '$dt'
        GROUP BY ip.payment_date, fs.sr_code
    ) coll ON coll.pdate    = a.pay_date
           AND coll.sr_code COLLATE utf8mb4_unicode_ci
                            = a.sr_code COLLATE utf8mb4_unicode_ci

    LEFT JOIN (
        SELECT d.delivery_date, r.rep_code,
               COALESCE(SUM(CASE WHEN d.handed_over_bo=0 THEN r.amount ELSE 0 END),0) AS banked,
               COALESCE(SUM(CASE WHEN d.handed_over_bo=1 THEN r.amount ELSE 0 END),0) AS handed
        FROM cc_cash_deposits d
        INNER JOIN cc_cash_deposit_reps r ON r.deposit_id = d.id
        WHERE d.delivery_date BETWEEN '$df' AND '$dt'
        GROUP BY d.delivery_date, r.rep_code
    ) dep ON dep.delivery_date = a.pay_date
          AND dep.rep_code COLLATE utf8mb4_unicode_ci
                           = a.sr_code COLLATE utf8mb4_unicode_ci

    LEFT JOIN (
        /* Company-absorbed amount for this day/rep — stored once per
           pay_date+sr_code (employee_id is NULL on 'absorb' rows). */
        SELECT pay_date,
               TRIM(sr_code) AS sr_code,
               COALESCE(SUM(amount), 0) AS absorb_total
        FROM cash_summary_pay_allocations
        WHERE entry_type = 'absorb'
          AND pay_date BETWEEN '$df' AND '$dt'
        GROUP BY pay_date, TRIM(sr_code)
    ) ab ON ab.pay_date = a.pay_date
         AND ab.sr_code COLLATE utf8mb4_unicode_ci
                        = a.sr_code COLLATE utf8mb4_unicode_ci

    WHERE a.entry_type = 'charge'
      AND a.employee_id = $eid_esc
      AND a.pay_date BETWEEN '$df' AND '$dt'

    GROUP BY a.pay_date, a.sr_code
    ORDER BY a.pay_date ASC
";

$per_sr_rows = [];
$qr = mysqli_query($conn, $sql_per_sr);
if ($qr) while ($r = mysqli_fetch_assoc($qr)) $per_sr_rows[] = $r;

/* Step 2 — fetch transfers for all SR codes that appear in this employee's rows */
$sr_dates_needed = [];
foreach ($per_sr_rows as $r) {
    $sr_dates_needed[$r['sr_code']] = true;
}
$transfer_map = []; // key: "date|sr_code" => ['t_out'=>0,'t_in'=>0]
if (!empty($sr_dates_needed)) {
    $sr_list = implode("','", array_map(
        fn($s) => mysqli_real_escape_string($conn, $s),
        array_keys($sr_dates_needed)
    ));
    $tr_qr = mysqli_query($conn, "
        SELECT from_sr_code, to_sr_code, delivery_date,
               SUM(amount) AS amount
        FROM cash_shortage_transfers
        WHERE delivery_date BETWEEN '$df' AND '$dt'
          AND (from_sr_code IN ('$sr_list') OR to_sr_code IN ('$sr_list'))
        GROUP BY from_sr_code, to_sr_code, delivery_date
    ");
    if ($tr_qr) while ($tr = mysqli_fetch_assoc($tr_qr)) {
        $amt = floatval($tr['amount']);
        // OUT side
        $kf = $tr['delivery_date'].'|'.$tr['from_sr_code'];
        if (!isset($transfer_map[$kf])) $transfer_map[$kf] = ['t_out'=>0,'t_in'=>0];
        $transfer_map[$kf]['t_out'] += $amt;
        // IN side
        $kt = $tr['delivery_date'].'|'.$tr['to_sr_code'];
        if (!isset($transfer_map[$kt])) $transfer_map[$kt] = ['t_out'=>0,'t_in'=>0];
        $transfer_map[$kt]['t_in'] += $amt;
    }
}

/* Step 3 & 4 — group by date, applying transfer formula, keeping short/excess separate */
$daily_by_date = [];
foreach ($per_sr_rows as $r) {
    $d         = $r['col_date'];
    $bank_diff = floatval($r['bank_diff']);
    $tk        = $d.'|'.$r['sr_code'];
    $t_out     = floatval($transfer_map[$tk]['t_out'] ?? 0);
    $t_in      = floatval($transfer_map[$tk]['t_in']  ?? 0);

    /* SAME formula as cash_collection.php */
    $new_diff = $bank_diff - $t_out + $t_in;

    if (!isset($daily_by_date[$d])) {
        $daily_by_date[$d] = [
            'col_date'        => $d,
            'total_collected' => 0,
            'total_deposited' => 0,
            'bo_handover'     => 0,
            'cash_short'      => 0,
            'cash_excess'     => 0,
            't_out'           => 0,
            't_in'            => 0,
        ];
    }
    $daily_by_date[$d]['total_collected'] += floatval($r['total_collected']);
    $daily_by_date[$d]['total_deposited'] += floatval($r['total_deposited']);
    $daily_by_date[$d]['bo_handover']     += floatval($r['bo_handover']);
    $daily_by_date[$d]['t_out']           += $t_out;
    $daily_by_date[$d]['t_in']            += $t_in;
    // Each SR's short/excess kept separate — never cancel across SRs
    if ($new_diff >  0.005) $daily_by_date[$d]['cash_short']  += $new_diff;
    if ($new_diff < -0.005) $daily_by_date[$d]['cash_excess'] += (-$new_diff);
}
$daily_rows = array_values($daily_by_date);

/* ─── Payroll deductions ─── */
$ded_rows = [];
$dqr = mysqli_query($conn,
    "SELECT id, charge_date, amount, description, payroll_year, payroll_month
     FROM payroll_payments_log
     WHERE employee_id = $eid_esc
       AND charge_date BETWEEN '$df' AND '$dt'
     ORDER BY charge_date ASC, id ASC");
if ($dqr) while ($r = mysqli_fetch_assoc($dqr)) $ded_rows[] = $r;

/* ─── Index deductions by date ─── */
$ded_by_date = [];
$MN = ['','January','February','March','April','May','June',
       'July','August','September','October','November','December'];
foreach ($ded_rows as $d) {
    $ded_by_date[$d['charge_date']][] = $d;
}

/* ─── Build merged events: one row per date ─── */
$all_dates = [];
foreach ($daily_rows as $d)  $all_dates[$d['col_date']] = true;
foreach ($ded_rows   as $d)  $all_dates[$d['charge_date']] = true;
ksort($all_dates);

$day_by_date = [];
foreach ($daily_rows as $d) $day_by_date[$d['col_date']] = $d;

/* ─── Running totals ─── */
$tot_collected  = 0;
$tot_deposited  = 0;
$tot_bo         = 0;
$tot_short      = 0;
$tot_excess     = 0;
$tot_deductions = 0;
$tot_t_out      = 0;
$tot_t_in       = 0;
$running_bal    = 0;

$merged_rows = [];

foreach ($all_dates as $date => $_) {
    $day  = $day_by_date[$date] ?? null;
    $deds = $ded_by_date[$date] ?? [];

    $total_collected = $day ? floatval($day['total_collected']) : 0;
    $total_deposited = $day ? floatval($day['total_deposited']) : 0;
    $bo_handover     = $day ? floatval($day['bo_handover'])     : 0;
    $cash_short      = $day ? floatval($day['cash_short'])      : 0;
    $cash_excess     = $day ? floatval($day['cash_excess'])     : 0;
    $day_t_out       = $day ? floatval($day['t_out'])           : 0;
    $day_t_in        = $day ? floatval($day['t_in'])            : 0;

    $day_ded_total = 0;
    $ded_labels    = [];
    foreach ($deds as $ded) {
        $day_ded_total += floatval($ded['amount']);
        $lbl = $ded['description'] ?: 'Salary Deduction';
        if ($ded['payroll_year'] && $ded['payroll_month']) {
            $lbl .= ' (' . $MN[$ded['payroll_month']] . ' ' . $ded['payroll_year'] . ')';
        }
        $ded_labels[] = $lbl;
    }

    $running_bal += $cash_short - $cash_excess - $day_ded_total;

    $merged_rows[] = [
        'date'            => $date,
        'total_collected' => $total_collected,
        'total_deposited' => $total_deposited,
        'bo_handover'     => $bo_handover,
        't_out'           => $day_t_out,
        't_in'            => $day_t_in,
        'cash_short'      => $cash_short,
        'cash_excess'     => $cash_excess,
        'deduction'       => $day_ded_total,
        'ded_labels'      => $ded_labels,
        'balance'         => $running_bal,
    ];

    $tot_collected  += $total_collected;
    $tot_deposited  += $total_deposited;
    $tot_bo         += $bo_handover;
    $tot_short      += $cash_short;
    $tot_excess     += $cash_excess;
    $tot_deductions += $day_ded_total;
    $tot_t_out      += $day_t_out;
    $tot_t_in       += $day_t_in;
}

$final_bal = $running_bal;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Employee Cash Detail — <?php echo htmlspecialchars($emp_name); ?></title>
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
.pg{padding:20px 18px 60px;max-width:1300px;margin:0 auto;}

/* ── Top bar ── */
.topbar{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;}
.pg-brand{display:flex;align-items:center;gap:12px;}
.pg-icon{width:44px;height:44px;background:linear-gradient(135deg,#7c3aed,#6d28d9);border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:19px;color:#fff;flex-shrink:0;box-shadow:0 4px 14px rgba(124,58,237,.28);}
.pg-h1{font-size:19px;font-weight:800;letter-spacing:-.02em;}
.pg-h1 em{color:var(--purple);font-style:normal;}
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
.p-cyn{background:var(--cyan-lt);color:var(--cyan);border:1px solid var(--cyan-md);}
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
.edt thead th.col-tr{border-top:3px solid var(--cyan);}

.edt tbody td{padding:9px 14px;border-bottom:1px solid var(--bdr2);text-align:right;white-space:nowrap;}
.edt tbody td.tl{text-align:left;}
.edt tbody tr:nth-child(even) td{background:#fafbfc;}
.edt tbody tr:hover td{background:#f0f4ff !important;}

.edt tbody tr.has-ded td{background:#fdf4ff !important;}
.edt tbody tr.has-ded:hover td{background:#ede9fe !important;}
.edt tbody tr.has-tr td{background:#f0f9ff !important;}
.edt tbody tr.has-tr:hover td{background:#e0f2fe !important;}
.edt tbody tr.has-tr.has-ded td{background:#f5f0ff !important;}

.ded-tag{display:inline-block;background:var(--purple-lt);color:var(--purple);border:1px solid var(--purple-md);border-radius:6px;font-size:9.5px;font-weight:700;padding:1px 6px;margin-top:2px;font-family:var(--fn);}
.tr-tag-out{display:inline-block;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:6px;font-size:9.5px;font-weight:700;padding:1px 6px;margin-top:2px;}
.tr-tag-in{display:inline-block;background:var(--green-lt);color:var(--green);border:1px solid var(--green-md);border-radius:6px;font-size:9.5px;font-weight:700;padding:1px 6px;margin-top:2px;}

/* Number classes */
.v-red{color:var(--red);font-family:var(--mn);font-weight:700;}
.v-grn{color:var(--green);font-family:var(--mn);font-weight:700;}
.v-amb{color:var(--amber);font-family:var(--mn);font-weight:700;}
.v-prp{color:var(--purple);font-family:var(--mn);font-weight:700;}
.v-cyn{color:var(--cyan);font-family:var(--mn);font-weight:700;}
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
    table.edt tbody tr.has-tr td{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    table.edt{font-size:10px;}
    table.edt thead th,table.edt tbody td,table.edt tfoot td{padding:5px 9px;}
    .sc-row{grid-template-columns:repeat(4,1fr);gap:7px;}
    .sc{box-shadow:none;border:1px solid #dde2ea;padding:8px 10px;}
    .sc-val{font-size:13px;}
}
.print-header{display:none;text-align:center;padding:10px 0 8px;border-bottom:2px solid #7c3aed;margin-bottom:12px;}
.print-header h2{font-size:16px;font-weight:800;}
.print-header p{font-size:11px;color:#4b5563;margin-top:3px;}
</style>
</head>
<body>

<div class="pg">

<!-- Print header (hidden on screen) -->
<div class="print-header">
    <h2>Cash Shortage Detail — <?php echo htmlspecialchars($emp_name); ?> (<?php echo htmlspecialchars($emp_code); ?>)</h2>
    <p><?php
        echo date('d M Y',strtotime($date_from)).' — '.date('d M Y',strtotime($date_to));
        echo ' · Printed: '.date('d M Y H:i');
    ?></p>
</div>

<!-- Top bar -->
<div class="topbar no-print">
    <div class="pg-brand">
        <div class="pg-icon"><i class="fa-solid fa-user-shield"></i></div>
        <div>
            <div class="pg-h1"><em><?php echo htmlspecialchars($emp_name); ?></em> — Cash Detail</div>
            <div class="pg-sub">
                <?php echo htmlspecialchars($emp_code); ?>
                &nbsp;·&nbsp;
                <?php echo date('d M Y',strtotime($date_from)); ?> – <?php echo date('d M Y',strtotime($date_to)); ?>
                &nbsp;·&nbsp; Daily Collection · Cross-Charge Transfers · Shortage · Excess · Salary Deductions · Running Balance
            </div>
        </div>
    </div>
    <div class="topbtns">
        <?php
        $back_url = 'cash_shortage_employee_report.php?date_from='.urlencode($date_from)
                  .'&date_to='.urlencode($date_to);
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
        <small><?php echo date('d M Y',strtotime($date_from)).' – '.date('d M Y',strtotime($date_to)); ?></small>
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
    <?php if ($tot_t_out > 0 || $tot_t_in > 0): ?>
    <div class="sc cyn">
        <div class="sc-lbl"><i class="fa-solid fa-right-left"></i> Cross-Charge</div>
        <div class="sc-val">
            <?php if ($tot_t_out > 0): ?><span style="color:#dc2626;font-size:12px;">▼<?php echo number_format($tot_t_out,2); ?></span><?php endif; ?>
            <?php if ($tot_t_out > 0 && $tot_t_in > 0): ?> / <?php endif; ?>
            <?php if ($tot_t_in > 0): ?><span style="color:#16a34a;font-size:12px;">▲<?php echo number_format($tot_t_in,2); ?></span><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <div class="sc red">
        <div class="sc-lbl"><i class="fa-solid fa-circle-arrow-down"></i> Cash Short (After Xfer)</div>
        <div class="sc-val">Rs. <?php echo number_format($tot_short, 2); ?></div>
    </div>
    <div class="sc grn">
        <div class="sc-lbl"><i class="fa-solid fa-circle-arrow-up"></i> Cash Excess (After Xfer)</div>
        <div class="sc-val">Rs. <?php echo number_format($tot_excess, 2); ?></div>
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
            <i class="fa-solid fa-table-list" style="color:var(--purple);"></i>
            Detail Ledger
            <span class="pill p-prp"><?php echo htmlspecialchars($emp_name); ?></span>
            <span class="pill p-blue"><?php echo date('d M Y',strtotime($date_from)); ?> – <?php echo date('d M Y',strtotime($date_to)); ?></span>
            <?php if ($tot_t_out > 0 || $tot_t_in > 0): ?>
            <span class="pill p-cyn"><i class="fa-solid fa-right-left" style="font-size:8px;"></i> Includes cross-charge transfers</span>
            <?php endif; ?>
        </div>
        <div style="font-size:11px;color:var(--txs);">
            Short/Excess = after transfer adjustment &nbsp;·&nbsp; Balance = cumulative running total
        </div>
    </div>

    <div class="tscroll">
    <table class="edt" id="edtMain">
      <thead>
        <tr>
            <th class="tl" style="min-width:110px;">Date</th>
            <th class="col-coll" style="min-width:130px;">Total Collected</th>
            <th class="col-dep"  style="min-width:130px;">Total Deposited</th>
            <th class="col-dep"  style="min-width:110px;">BO Handover</th>
            <th class="col-tr"   style="min-width:120px;" title="Cross-charge transfers OUT (−) / IN (+) for this SR on this date">Cross-Charge</th>
            <th class="col-sht"  style="min-width:120px;" title="Cash short after applying cross-charge transfers">Cash Short</th>
            <th class="col-exc"  style="min-width:120px;" title="Cash excess after applying cross-charge transfers">Cash Excess</th>
            <th class="col-ded"  style="min-width:130px;">Sal. Deduction</th>
            <th class="col-bal"  style="min-width:120px;">Balance</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($merged_rows as $row):
          $hasDed = $row['deduction'] > 0.005;
          $hasTr  = ($row['t_out'] > 0.005 || $row['t_in'] > 0.005);
          $rowCls = '';
          if ($hasDed && $hasTr) $rowCls = 'has-ded has-tr';
          elseif ($hasDed)       $rowCls = 'has-ded';
          elseif ($hasTr)        $rowCls = 'has-tr';
      ?>
        <tr class="<?php echo $rowCls; ?>">
            <td class="tl mn" style="font-size:11.5px;">
                <?php echo date('d M Y', strtotime($row['date'])); ?>
                <?php if ($hasTr): ?>
                <br>
                <?php if ($row['t_out'] > 0.005): ?>
                    <span class="tr-tag-out"><i class="fa-solid fa-right-left" style="font-size:7px;"></i> OUT <?php echo number_format($row['t_out'],2); ?></span>
                <?php endif; ?>
                <?php if ($row['t_in'] > 0.005): ?>
                    <span class="tr-tag-in"><i class="fa-solid fa-right-left" style="font-size:7px;"></i> IN <?php echo number_format($row['t_in'],2); ?></span>
                <?php endif; ?>
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
            <td>
                <?php
                $tout = $row['t_out']; $tin = $row['t_in'];
                if ($tout > 0.005 || $tin > 0.005):
                    $parts = [];
                    if ($tout > 0.005) $parts[] = '<span class="v-red">▼'.number_format($tout,2).'</span>';
                    if ($tin  > 0.005) $parts[] = '<span class="v-grn">▲'.number_format($tin,2).'</span>';
                    echo implode(' ', $parts);
                else: echo '<span class="dash">—</span>'; endif;
                ?>
            </td>
            <td><?php echo $row['cash_short']  > 0.005 ? '<span class="v-red">'.number_format($row['cash_short'],2).'</span>'  : '<span class="dash">—</span>'; ?></td>
            <td><?php echo $row['cash_excess'] > 0.005 ? '<span class="v-grn">'.number_format($row['cash_excess'],2).'</span>' : '<span class="dash">—</span>'; ?></td>
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
            <td class="f-cyn">
                <?php
                $parts = [];
                if ($tot_t_out > 0.005) $parts[] = '<span style="color:#dc2626;">▼'.number_format($tot_t_out,2).'</span>';
                if ($tot_t_in  > 0.005) $parts[] = '<span style="color:#16a34a;">▲'.number_format($tot_t_in,2).'</span>';
                echo $parts ? implode(' ', $parts) : '—';
                ?>
            </td>
            <td class="f-red"><?php echo $tot_short      > 0 ? number_format($tot_short,2)      : '—'; ?></td>
            <td class="f-grn"><?php echo $tot_excess     > 0 ? number_format($tot_excess,2)     : '—'; ?></td>
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
    $js_rows[] = [
        'date'            => $row['date'],
        'total_collected' => $row['total_collected'],
        'total_deposited' => $row['total_deposited'],
        'bo_handover'     => $row['bo_handover'],
        't_out'           => $row['t_out'],
        't_in'            => $row['t_in'],
        'cash_short'      => $row['cash_short'],
        'cash_excess'     => $row['cash_excess'],
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
        ['Cash Shortage Detail Report'],
        ['Employee:', EMP_NAME + ' (' + EMP_CODE + ')'],
        ['Period:', DATE_FROM + ' — ' + DATE_TO],
        ['Generated:', new Date().toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})],
        ['Note:', 'Cash Short/Excess figures are AFTER applying cross-charge transfers'],
        []
    ];

    var hdrs = ['Date','Total Collected','Total Deposited','BO Handover',
                'Cross-Charge OUT','Cross-Charge IN',
                'Cash Short','Cash Excess','Sal. Deduction','Deduction Notes','Balance'];

    var data = ROWS.map(function(r) {
        return [
            r.date,
            parseFloat(r.total_collected)||0,
            parseFloat(r.total_deposited)||0,
            parseFloat(r.bo_handover)||0,
            parseFloat(r.t_out)||0,
            parseFloat(r.t_in)||0,
            parseFloat(r.cash_short)||0,
            parseFloat(r.cash_excess)||0,
            parseFloat(r.deduction)||0,
            r.ded_label || '',
            parseFloat(r.balance)||0
        ];
    });

    var allRows = titleRows.concat([hdrs], data);
    var ws = XLSX.utils.aoa_to_sheet(allRows);
    ws['!cols'] = [12,16,16,14,15,14,13,13,15,35,13].map(function(w){ return {wch:w}; });
    ws['!merges'] = [{ s:{r:0,c:0}, e:{r:0,c:10} }];

    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Detail');

    var fname = 'CashDetail_'
        + EMP_CODE.replace(/[^a-zA-Z0-9]/g,'_')
        + '_' + DATE_FROM.replace(/ /g,'') + '_to_' + DATE_TO.replace(/ /g,'')
        + '.xlsx';
    XLSX.writeFile(wb, fname);
}
</script>
<?php include 'footer.php'; ?>
</body>
</html>