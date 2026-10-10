<?php
/* ══════════════════════════════════════════════════════════════════════════
   AJAX HANDLERS — must be FIRST, before ANY include or output
══════════════════════════════════════════════════════════════════════════ */
$_ajax_action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($_ajax_action !== '') {
    while (ob_get_level() > 0) ob_end_clean();
    error_reporting(0);
    ini_set('display_errors', 0);

    require_once 'config.php';

    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS `payroll_payments_log` (
            `id`                INT AUTO_INCREMENT PRIMARY KEY,
            `employee_id`       INT NOT NULL,
            `employee_name`     VARCHAR(200) COLLATE utf8mb4_unicode_ci NOT NULL,
            `payroll_period_id` INT DEFAULT NULL,
            `payroll_year`      INT DEFAULT NULL,
            `payroll_month`     INT DEFAULT NULL,
            `amount`            DECIMAL(12,2) NOT NULL,
            `charge_date`       DATE NOT NULL,
            `description`       VARCHAR(500) COLLATE utf8mb4_unicode_ci DEFAULT 'cash shortage',
            `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_emp` (`employee_id`),
            INDEX `idx_period` (`payroll_period_id`)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");

    header('Content-Type: application/json; charset=utf-8');

    if ($_ajax_action === 'get_payroll_charges') {
        $eid = intval($_GET['employee_id'] ?? 0);
        if (!$eid) { echo json_encode(['charges'=>[],'total'=>0]); exit; }
        $charges = [];
        $qr = mysqli_query($conn,
            "SELECT id, amount, charge_date, description,
                    payroll_year, payroll_month, payroll_period_id
             FROM payroll_payments_log
             WHERE employee_id = $eid
             ORDER BY charge_date DESC, id DESC");
        if ($qr) while ($r = mysqli_fetch_assoc($qr)) $charges[] = $r;
        $tr    = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS t FROM payroll_payments_log WHERE employee_id=$eid");
        $total = $tr ? floatval(mysqli_fetch_assoc($tr)['t']) : 0;
        echo json_encode(['charges' => $charges, 'total' => $total]);
        exit;
    }

    if ($_ajax_action === 'save_payroll_charge') {
        $eid      = intval($_POST['employee_id']       ?? 0);
        $ename    = mysqli_real_escape_string($conn, trim($_POST['employee_name']    ?? ''));
        $ppid     = intval($_POST['payroll_period_id'] ?? 0);
        $pp_year  = intval($_POST['payroll_year']      ?? 0);
        $pp_month = intval($_POST['payroll_month']     ?? 0);
        $amount   = floatval($_POST['amount']          ?? 0);
        $amt_sql  = number_format($amount, 2, '.', '');
        $cdate    = mysqli_real_escape_string($conn, $_POST['charge_date'] ?? date('Y-m-d'));
        $desc     = mysqli_real_escape_string($conn, trim($_POST['description'] ?? 'cash shortage') ?: 'cash shortage');
        if (!$eid || $amount <= 0) {
            echo json_encode(['success'=>false,'message'=>'Invalid parameters (eid='.$eid.' amt='.$amount.')']);
            exit;
        }
        $ppid_v  = $ppid     ? $ppid     : 'NULL';
        $year_v  = $pp_year  ? $pp_year  : 'NULL';
        $month_v = $pp_month ? $pp_month : 'NULL';
        $ok = mysqli_query($conn,
            "INSERT INTO payroll_payments_log
                 (employee_id, employee_name, payroll_period_id, payroll_year, payroll_month, amount, charge_date, description)
             VALUES ($eid, '$ename', $ppid_v, $year_v, $month_v, $amt_sql, '$cdate', '$desc')");
        if ($ok) {
            $new_id = mysqli_insert_id($conn);
            $tr     = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS t FROM payroll_payments_log WHERE employee_id=$eid");
            $total  = $tr ? floatval(mysqli_fetch_assoc($tr)['t']) : 0;
            echo json_encode(['success'=>true, 'charge_id'=>$new_id, 'new_total_charged'=>$total]);
        } else {
            echo json_encode(['success'=>false, 'message'=>mysqli_error($conn)]);
        }
        exit;
    }

    if ($_ajax_action === 'delete_payroll_charge') {
        $cid = intval($_POST['charge_id']   ?? 0);
        $eid = intval($_POST['employee_id'] ?? 0);
        if (!$cid) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
        mysqli_query($conn, "DELETE FROM payroll_payments_log WHERE id=$cid");
        $tr    = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS t FROM payroll_payments_log WHERE employee_id=$eid");
        $total = $tr ? floatval(mysqli_fetch_assoc($tr)['t']) : 0;
        echo json_encode(['success'=>true, 'new_total_charged'=>$total]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action: '.$_ajax_action]);
    exit;
}
/* ══════════════════════════════════════════════════════════════════════════
   END AJAX
══════════════════════════════════════════════════════════════════════════ */

ob_start();
include 'config.php';

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `payroll_payments_log` (
        `id`                INT AUTO_INCREMENT PRIMARY KEY,
        `employee_id`       INT NOT NULL,
        `employee_name`     VARCHAR(200) COLLATE utf8mb4_unicode_ci NOT NULL,
        `payroll_period_id` INT DEFAULT NULL,
        `payroll_year`      INT DEFAULT NULL,
        `payroll_month`     INT DEFAULT NULL,
        `amount`            DECIMAL(12,2) NOT NULL,
        `charge_date`       DATE NOT NULL,
        `description`       VARCHAR(500) COLLATE utf8mb4_unicode_ci DEFAULT 'cash shortage',
        `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_emp` (`employee_id`),
        INDEX `idx_period` (`payroll_period_id`)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
");
if (mysqli_errno($conn)) error_log('payroll_payments_log CREATE error: ' . mysqli_error($conn));

include 'header.php';

/* ═══ FILTERS ═══ */
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$f_sr      = trim($_GET['sr_code'] ?? '');
$df        = mysqli_real_escape_string($conn, $date_from);
$dt        = mysqli_real_escape_string($conn, $date_to);
$sr_esc    = $f_sr ? mysqli_real_escape_string($conn, $f_sr) : '';

$all_sr = [];
$sr_qr  = mysqli_query($conn, "SELECT DISTINCT sr_code COLLATE utf8mb4_unicode_ci AS sr_code
    FROM field_summary WHERE sr_code IS NOT NULL AND sr_code <> '' ORDER BY sr_code");
if ($sr_qr) while ($r2 = mysqli_fetch_assoc($sr_qr)) $all_sr[] = $r2['sr_code'];

$srWhere = $sr_esc ? "AND a.sr_code COLLATE utf8mb4_unicode_ci = '$sr_esc'" : '';

/* ═══════════════════════════════════════════════════════════════════════
   MAIN DATA QUERY — mirrors cash_shortage_employee_detail.php exactly
   ─────────────────────────────────────────────────────────────────────
   Step 1 : per-SR per-day raw_diff  (collected − deposited − handed − absorbed_by_company)
   Step 2 : fetch transfers per SR per day
   Step 3 : apply formula  new_diff = raw_diff − t_out + t_in
   Step 4 : in PHP sum short & excess INDEPENDENTLY per employee
             (never cancel across SRs on different days — matches detail page)
══════════════════════════════════════════════════════════════════════════ */

/* Step 1 — per-SR-per-day rows
   FIX: the employee's shortage/excess must be based on the REAL day diff
   (collected − deposited − handed) so that excess still shows correctly
   for every day, exactly as before. The only correction needed is to
   remove whatever amount the COMPANY chose to absorb for that day/rep
   (entry_type='absorb', stored once per pay_date+sr_code) so it no
   longer gets mixed into / counted against the employee's own total. */
$sql_per_sr = "
    SELECT
        a.employee_id,
        a.employee_name,
        a.pay_date,
        a.sr_code,
        /* MAX() (not SUM) is used on the joined sub-query columns:
           each (employee, day, sr) group joins the SAME single coll/dep/
           absorb row, so MAX returns that one correct value. This makes
           the query safe under ONLY_FULL_GROUP_BY and stops MySQL from
           silently keeping one arbitrary row and dropping the rest. */
        MAX(COALESCE(coll.total_coll, 0))                          AS total_collected,
        MAX(COALESCE(dep.banked, 0))                               AS total_deposited,
        MAX(COALESCE(dep.handed, 0))                               AS bo_handover,
        MAX(COALESCE(ab.absorb_total, 0))                          AS absorb_total,
        MAX(
              COALESCE(coll.total_coll, 0)
            - COALESCE(dep.banked, 0)
            - COALESCE(dep.handed, 0)
            - COALESCE(ab.absorb_total, 0)
        )                                                          AS raw_diff
    FROM cash_summary_pay_allocations a

    LEFT JOIN (
        SELECT ip.payment_date AS pdate,
               TRIM(fs.sr_code) AS sr_code,
               COALESCE(SUM(ip.amount), 0) AS total_coll
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        INNER JOIN field_summary fs           ON fs.id  = fsd.field_summary_id
        WHERE ip.payment_method = 'cash' AND ip.is_reversed = 0
          AND ip.payment_date BETWEEN '$df' AND '$dt'
        GROUP BY ip.payment_date, TRIM(fs.sr_code)
    ) coll ON coll.pdate = a.pay_date
           AND coll.sr_code COLLATE utf8mb4_unicode_ci
                            = TRIM(a.sr_code) COLLATE utf8mb4_unicode_ci

    LEFT JOIN (
        /* Deposit figures — mirrors sum.php (the authoritative summary page):
             - keyed on the REP-LINE date  r.delivery_date  (NOT d.delivery_date)
             - NULL / '0000-00-00' rep dates excluded
             - split by handed_over_bo AND collected_by, counting ONLY rows
               whose collected_by is 'sr' or 'cc' (any other value is ignored,
               exactly like sum.php's banked_sr+banked_cc / handed_sr+handed_cc)
           banked = deposits sent to bank (handed_over_bo=0),
           handed = cash handed over to BO (handed_over_bo=1).
           TRIM/collation keep a rep's multiple deposits from splitting. */
        SELECT r.delivery_date AS delivery_date,
               TRIM(r.rep_code) AS rep_code,
               COALESCE(SUM(CASE WHEN d.handed_over_bo = 0
                                  AND LOWER(COALESCE(d.collected_by,'')) IN ('sr','cc')
                                 THEN r.amount ELSE 0 END), 0) AS banked,
               COALESCE(SUM(CASE WHEN d.handed_over_bo = 1
                                  AND LOWER(COALESCE(d.collected_by,'')) IN ('sr','cc')
                                 THEN r.amount ELSE 0 END), 0) AS handed
        FROM cc_cash_deposit_reps r
        INNER JOIN cc_cash_deposits d ON d.id = r.deposit_id
        WHERE r.delivery_date BETWEEN '$df' AND '$dt'
          AND r.delivery_date IS NOT NULL
          AND r.delivery_date <> '0000-00-00'
        GROUP BY r.delivery_date, TRIM(r.rep_code)
    ) dep ON dep.delivery_date = a.pay_date
          AND dep.rep_code COLLATE utf8mb4_unicode_ci
                           = TRIM(a.sr_code) COLLATE utf8mb4_unicode_ci

    LEFT JOIN (
        /* Company-absorbed amount for this day/rep — stored once per
           pay_date+sr_code (employee_id is NULL on 'absorb' rows), so
           this must be removed from the diff before it reaches the
           employee, instead of being counted as part of their shortage. */
        SELECT pay_date,
               TRIM(sr_code) AS sr_code,
               COALESCE(SUM(amount), 0) AS absorb_total
        FROM cash_summary_pay_allocations
        WHERE entry_type = 'absorb'
          AND pay_date BETWEEN '$df' AND '$dt'
        GROUP BY pay_date, TRIM(sr_code)
    ) ab ON ab.pay_date = a.pay_date
         AND ab.sr_code COLLATE utf8mb4_unicode_ci
                        = TRIM(a.sr_code) COLLATE utf8mb4_unicode_ci

    WHERE a.entry_type = 'charge'
      AND a.pay_date BETWEEN '$df' AND '$dt'
      $srWhere

    GROUP BY a.employee_id, a.employee_name, a.pay_date, a.sr_code
    ORDER BY a.employee_id, a.pay_date ASC
";

$per_sr_raw = [];
$qr = mysqli_query($conn, $sql_per_sr);
if ($qr) while ($r = mysqli_fetch_assoc($qr)) $per_sr_raw[] = $r;

/* Step 2 — fetch transfers for all SR codes that appear */
$sr_dates_needed = [];
foreach ($per_sr_raw as $r) $sr_dates_needed[$r['sr_code']] = true;

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
        $kf  = $tr['delivery_date'].'|'.$tr['from_sr_code'];
        if (!isset($transfer_map[$kf])) $transfer_map[$kf] = ['t_out'=>0,'t_in'=>0];
        $transfer_map[$kf]['t_out'] += $amt;
        $kt = $tr['delivery_date'].'|'.$tr['to_sr_code'];
        if (!isset($transfer_map[$kt])) $transfer_map[$kt] = ['t_out'=>0,'t_in'=>0];
        $transfer_map[$kt]['t_in'] += $amt;
    }
}

/* Step 3 & 4 — apply transfer formula, aggregate per employee */
$emp_agg = [];
foreach ($per_sr_raw as $r) {
    $eid      = intval($r['employee_id']);
    $raw_diff = floatval($r['raw_diff']);
    $tk       = $r['pay_date'].'|'.$r['sr_code'];
    $t_out    = floatval($transfer_map[$tk]['t_out'] ?? 0);
    $t_in     = floatval($transfer_map[$tk]['t_in']  ?? 0);

    /* Same formula as detail page */
    $new_diff = $raw_diff - $t_out + $t_in;

    if (!isset($emp_agg[$eid])) {
        $emp_agg[$eid] = [
            'employee_id'   => $eid,
            'employee_name' => $r['employee_name'],
            'shortage'      => 0.0,
            'excess'        => 0.0,
            'date_set'      => [],
        ];
    }
    /* Each SR's short/excess kept separate — never cancel across SRs */
    if ($new_diff >  0.005) $emp_agg[$eid]['shortage'] += $new_diff;
    if ($new_diff < -0.005) $emp_agg[$eid]['excess']   += (-$new_diff);
    $emp_agg[$eid]['date_set'][$r['pay_date']] = true;
}

/* Step 5 — fetch payroll charges per employee */
$ppl_totals = [];
$ppl_qr = mysqli_query($conn,
    "SELECT employee_id, COALESCE(SUM(amount), 0) AS total_charged
     FROM payroll_payments_log
     GROUP BY employee_id");
if ($ppl_qr) while ($p = mysqli_fetch_assoc($ppl_qr)) {
    $ppl_totals[intval($p['employee_id'])] = floatval($p['total_charged']);
}

/* Step 6 — build final rows */
$rows = [];
foreach ($emp_agg as $eid => $agg) {
    $shortage = $agg['shortage'];
    $excess   = $agg['excess'];
    $net      = $shortage - $excess;
    $prl      = $ppl_totals[$eid] ?? 0.0;
    $balance  = $net - $prl;
    $rows[]   = [
        'employee_id'           => $eid,
        'employee_name'         => $agg['employee_name'],
        'shortage_charged'      => $shortage,
        'excess_charged'        => $excess,
        'net_charged'           => $net,
        'total_payroll_charged' => $prl,
        'balance'               => $balance,
        'date_count'            => count($agg['date_set']),
    ];
}
usort($rows, fn($a, $b) => strcmp($a['employee_name'], $b['employee_name']));

/* Grand totals */
$totals = ['shortage_charged'=>0,'excess_charged'=>0,'net_charged'=>0,'total_payroll_charged'=>0,'balance'=>0];
foreach ($rows as $trow) foreach (array_keys($totals) as $k) $totals[$k] += floatval($trow[$k]);

$MN = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$payroll_periods_all = [];
$active_period       = null;
$pp_qr = mysqli_query($conn, "SELECT id,year,month,open_date,close_date,status FROM payroll_periods ORDER BY year DESC, month DESC");
if ($pp_qr) {
    while ($pp = mysqli_fetch_assoc($pp_qr)) {
        $pp['label'] = $pp['year'].' — '.$MN[$pp['month']].' ['.$pp['status'].']';
        $payroll_periods_all[] = $pp;
        if (!$active_period && $pp['status'] === 'Open') $active_period = $pp;
    }
}

function fmtT($v) { $n=floatval($v); return $n==0?'—':number_format(abs($n),2); }

$_pqs = '?date_from='.urlencode($date_from).'&date_to='.urlencode($date_to).($f_sr?'&sr_code='.urlencode($f_sr):'');
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap');
@import url('https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css');

:root{
    --bg:#f5f7fa;--surface:#fff;--surface2:#f0f3f7;--surface3:#e8ecf2;
    --bdr:#dde2ea;--bdr2:#eaecf0;--tx:#111827;--txm:#4b5563;--txs:#9ca3af;
    --fn:'Plus Jakarta Sans',sans-serif;--mn:'JetBrains Mono',monospace;--r:12px;
    --red:#dc2626;--red-lt:#fee2e2;--red-md:#fca5a5;
    --green:#16a34a;--green-lt:#dcfce7;--green-md:#86efac;
    --amber:#d97706;--amber-lt:#fef3c7;--amber-md:#fcd34d;
    --blue:#2563eb;--blue-lt:#dbeafe;--blue-md:#93c5fd;
    --purple:#7c3aed;--purple-lt:#ede9fe;--purple-md:#c4b5fd;
    --cyan:#0891b2;--cyan-lt:#cffafe;
    --shadow-sm:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);
    --shadow-md:0 4px 16px rgba(0,0,0,.08),0 2px 4px rgba(0,0,0,.04);
    --shadow-lg:0 8px 32px rgba(0,0,0,.12),0 4px 8px rgba(0,0,0,.06);
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:13px;min-height:100vh;}
.pg{padding:24px 20px 80px;max-width:1440px;margin:0 auto;}

.topbar{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:22px;}
.pg-brand{display:flex;align-items:center;gap:14px;}
.pg-icon{width:46px;height:46px;background:linear-gradient(135deg,#dc2626,#9b1c1c);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0;box-shadow:0 4px 16px rgba(220,38,38,.25);}
.pg-h1{font-size:21px;font-weight:800;letter-spacing:-.02em;color:var(--tx);}
.pg-h1 span{color:var(--red);}
.pg-sub{font-size:11.5px;color:var(--txm);margin-top:3px;font-weight:500;}
.dpill{display:inline-flex;align-items:center;gap:7px;background:var(--red-lt);border:1px solid var(--red-md);border-radius:20px;padding:7px 16px;font-size:11px;font-weight:700;color:var(--red);font-family:var(--mn);}
.topbtns{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}

.fbar{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:14px 16px;margin-bottom:18px;display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;box-shadow:var(--shadow-sm);}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:10px;font-weight:700;color:var(--txm);text-transform:uppercase;letter-spacing:.08em;}
.fg input,.fg select{padding:9px 12px;border:1.5px solid var(--bdr);border-radius:8px;font-size:12.5px;font-family:var(--fn);color:var(--tx);background:var(--surface2);min-width:0;transition:border-color .15s,box-shadow .15s;}
.fg input:focus,.fg select:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(220,38,38,.12);}
.btn-go{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border:none;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(220,38,38,.25);transition:all .15s;}
.btn-go:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(220,38,38,.35);}
.btn-rst{display:inline-flex;align-items:center;gap:6px;padding:10px 14px;background:var(--surface2);color:var(--txm);border:1.5px solid var(--bdr);border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .15s;}
.btn-rst:hover{color:var(--tx);border-color:#c0c8d4;background:var(--surface3);}
.btn-excel{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;box-shadow:0 2px 6px rgba(22,163,74,.2);transition:all .15s;}
.btn-excel:hover{transform:translateY(-1px);}
.btn-print{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;background:var(--surface2);color:var(--txm);border:1.5px solid var(--bdr);border-radius:8px;font-size:12px;cursor:pointer;transition:all .15s;font-weight:600;white-space:nowrap;}
.btn-print:hover{color:var(--tx);background:var(--surface3);}
.btn-rpt-daily{display:inline-flex;align-items:center;gap:6px;padding:9px 15px;background:var(--surface);color:var(--blue);border:1.5px solid var(--blue-md);border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;white-space:nowrap;font-family:var(--fn);}
.btn-rpt-daily:hover{background:var(--blue-lt);border-color:var(--blue);transform:translateY(-1px);}
.btn-rpt-summary{display:inline-flex;align-items:center;gap:6px;padding:9px 15px;background:var(--surface);color:var(--purple);border:1.5px solid var(--purple-md);border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;white-space:nowrap;font-family:var(--fn);}
.btn-rpt-summary:hover{background:var(--purple-lt);border-color:var(--purple);transform:translateY(-1px);}

.sc-row{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:18px;}
.sc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:16px 18px;position:relative;overflow:hidden;box-shadow:var(--shadow-sm);transition:box-shadow .2s,transform .2s;}
.sc:hover{box-shadow:var(--shadow-md);transform:translateY(-2px);}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--r) var(--r) 0 0;}
.sc.red::before{background:linear-gradient(90deg,var(--red),#ef4444);}
.sc.grn::before{background:linear-gradient(90deg,var(--green),#22c55e);}
.sc.amb::before{background:linear-gradient(90deg,var(--amber),#f59e0b);}
.sc.prp::before{background:linear-gradient(90deg,var(--purple),#8b5cf6);}
.sc.blu::before{background:linear-gradient(90deg,var(--blue),#3b82f6);}
.sc-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;margin-bottom:10px;}
.sc.red .sc-icon{background:var(--red-lt);color:var(--red);}
.sc.grn .sc-icon{background:var(--green-lt);color:var(--green);}
.sc.amb .sc-icon{background:var(--amber-lt);color:var(--amber);}
.sc.prp .sc-icon{background:var(--purple-lt);color:var(--purple);}
.sc.blu .sc-icon{background:var(--blue-lt);color:var(--blue);}
.sc-lbl{font-size:10px;font-weight:700;color:var(--txm);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;}
.sc-val{font-family:var(--mn);font-size:17px;font-weight:700;letter-spacing:-.02em;}
.sc.red .sc-val{color:var(--red);}
.sc.grn .sc-val{color:var(--green);}
.sc.amb .sc-val{color:var(--amber);}
.sc.prp .sc-val{color:var(--purple);}
.sc.blu .sc-val{color:var(--blue);}
.sc-sub{font-size:10px;color:var(--txs);margin-top:4px;}

.tc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);overflow:hidden;box-shadow:var(--shadow-sm);}
.tc-bar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid var(--bdr);flex-wrap:wrap;gap:8px;}
.tc-ttl{font-size:15px;font-weight:800;display:flex;align-items:center;gap:10px;flex-wrap:wrap;color:var(--tx);}
.pill{padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;font-family:var(--mn);}
.p-red{background:var(--red-lt);color:var(--red);border:1px solid var(--red-md);}
.p-blue{background:var(--blue-lt);color:var(--blue);border:1px solid var(--blue-md);}
.p-green{background:var(--green-lt);color:var(--green);border:1px solid var(--green-md);}
.tc-hint{font-size:11px;color:var(--txs);}

.top-scroll-wrap{overflow-x:auto;overflow-y:hidden;height:10px;background:var(--surface3);}
.top-scroll-inner{height:1px;}
.tscroll{overflow-x:auto;}

table.cdr{width:100%;border-collapse:collapse;font-size:12.5px;}
.cdr thead tr.GH th{padding:11px 14px;font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--txm);text-align:center;white-space:nowrap;background:var(--surface2);border-bottom:2px solid var(--bdr);}
.cdr thead tr.GH th.tl{text-align:left;}
.cdr thead tr.GH th.col-sht{border-top:3px solid var(--red);}
.cdr thead tr.GH th.col-exc{border-top:3px solid var(--green);}
.cdr thead tr.GH th.col-net{border-top:3px solid var(--amber);}
.cdr thead tr.GH th.col-prl{border-top:3px solid var(--purple);}
.cdr thead tr.GH th.col-bal{border-top:3px solid var(--cyan);}
.cdr thead tr.TH td{background:#f8fafc;color:var(--txm);font-weight:700;font-size:11.5px;padding:8px 14px;text-align:right;border-bottom:2px solid var(--bdr);white-space:nowrap;font-family:var(--mn);}
.cdr thead tr.TH td.tl{text-align:left;font-family:var(--fn);}
.cdr thead tr.TH td.c-red{color:var(--red);}
.cdr thead tr.TH td.c-grn{color:var(--green);}
.cdr thead tr.TH td.c-amb{color:var(--amber);}
.cdr thead tr.TH td.c-prp{color:var(--purple);}
.cdr thead tr.TH td.c-cyn{color:var(--cyan);}
.cdr tbody tr td{background:var(--surface);padding:9px 14px;text-align:right;white-space:nowrap;border-bottom:1px solid var(--bdr2);color:var(--tx);}
.cdr tbody tr.stripe td{background:#fafbfc;}
.cdr tbody tr:hover td{background:#f0f4ff !important;}
.cdr tbody td.tl{text-align:left;}
.cdr tbody td.tc{text-align:center;}
.cdr tbody td.emp-name{font-weight:600;color:var(--tx);}
.cdr tbody td.days-td{font-family:var(--mn);font-size:11px;color:var(--txm);text-align:center;}
.cdr tbody td.row-no{font-family:var(--mn);font-size:10px;color:var(--txs);text-align:center;}
.stk{position:sticky;left:0;z-index:2;box-shadow:3px 0 8px rgba(0,0,0,.06);}
.cdr thead tr.GH th.stk{z-index:4;background:var(--surface2) !important;}
.cdr thead tr.TH td.stk{background:#f8fafc !important;}
.cdr tbody tr td.stk{background:var(--surface) !important;}
.cdr tbody tr.stripe td.stk{background:#fafbfc !important;}
.cdr tbody tr:hover td.stk{background:#f0f4ff !important;}
.cdr tfoot td.stk{background:#f8fafc !important;}
.cdr tfoot td{padding:11px 14px;font-weight:700;font-size:11.5px;background:#f8fafc;color:var(--txm);text-align:right;white-space:nowrap;border-top:2px solid var(--bdr);font-family:var(--mn);}
.cdr tfoot td.tl{text-align:left;color:var(--txs);font-family:var(--fn);}
.cdr tfoot td.f-red{color:var(--red);}
.cdr tfoot td.f-grn{color:var(--green);}
.cdr tfoot td.f-amb{color:var(--amber);}
.cdr tfoot td.f-prp{color:var(--purple);}
.cdr tfoot td.f-cyn{color:var(--cyan);}

.v-red{color:var(--red);font-family:var(--mn);font-size:12px;font-weight:700;}
.v-grn{color:var(--green);font-family:var(--mn);font-size:12px;font-weight:700;}
.bal-pos{color:var(--red);font-weight:700;font-family:var(--mn);font-size:12px;}
.bal-zero{color:var(--green);font-weight:700;font-family:var(--mn);font-size:12px;}
.bal-neg{color:var(--amber);font-weight:700;font-family:var(--mn);font-size:12px;}
.net-badge{display:inline-flex;align-items:center;gap:3px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;font-family:var(--mn);}
.net-pos{background:var(--red-lt);color:var(--red);border:1px solid var(--red-md);}
.net-neg{background:var(--green-lt);color:var(--green);border:1px solid var(--green-md);}
.net-zero{background:var(--surface3);color:var(--txm);border:1px solid var(--bdr);}
.prl-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;font-size:10.5px;font-weight:700;font-family:var(--mn);background:var(--purple-lt);color:var(--purple);border:1px solid var(--purple-md);}
.net-link{text-decoration:none;display:inline-flex;align-items:center;gap:3px;transition:all .15s;border-radius:20px;}
.net-link:hover{box-shadow:0 0 0 3px rgba(220,38,38,.12);transform:scale(1.05);}
.net-link .link-ico{font-size:8px;opacity:0;transition:opacity .15s;color:inherit;}
.net-link:hover .link-ico{opacity:1;}
.val-link{text-decoration:none;transition:all .15s;}
.val-link:hover span{filter:brightness(0.85);}
.val-link span.dashed{border-bottom:1px dashed currentColor;cursor:pointer;}

.btn-payroll{display:inline-flex;align-items:center;gap:5px;padding:5px 13px;background:linear-gradient(135deg,var(--purple),#6d28d9);color:#fff;border:none;border-radius:7px;font-size:11px;font-weight:700;cursor:pointer;font-family:var(--fn);transition:all .15s;box-shadow:0 1px 4px rgba(124,58,237,.3);white-space:nowrap;}
.btn-payroll:hover{transform:translateY(-1px);box-shadow:0 3px 10px rgba(124,58,237,.4);}
.btn-payroll.has-charges{background:linear-gradient(135deg,#16a34a,#15803d);box-shadow:0 1px 4px rgba(22,163,74,.3);}
.btn-payroll.has-charges:hover{box-shadow:0 3px 10px rgba(22,163,74,.4);}

.state-box{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);box-shadow:var(--shadow-sm);}
.empty{text-align:center;padding:80px 20px;color:var(--txm);}
.empty .ico{font-size:52px;display:block;margin-bottom:18px;opacity:.15;}
.empty p{font-size:15px;font-weight:700;margin-bottom:6px;color:var(--tx);}

#cdrToast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;z-index:10001;display:none;opacity:0;transition:opacity .3s;font-family:var(--fn);box-shadow:var(--shadow-lg);}
.toast-ok{background:#f0fdf4;color:var(--green);border:1.5px solid var(--green-md);}
.toast-err{background:#fef2f2;color:var(--red);border:1.5px solid var(--red-md);}

/* ════ PAYROLL MODAL ════ */
#payrollModalBg{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:10000;display:none;align-items:center;justify-content:center;padding:20px;}
.pm-box{background:#fff;border-radius:16px;width:100%;max-width:620px;max-height:90vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.25);display:flex;flex-direction:column;}
.pm-hd{display:flex;align-items:center;gap:12px;padding:18px 20px;background:linear-gradient(135deg,#f5f3ff,#ede9fe);border-bottom:1px solid #ddd6fe;border-radius:16px 16px 0 0;flex-shrink:0;}
.pm-hd-ico{width:42px;height:42px;background:linear-gradient(135deg,var(--purple),#6d28d9);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff;flex-shrink:0;}
.pm-hd-info{flex:1;min-width:0;}
.pm-hd-name{font-size:15px;font-weight:800;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.pm-hd-sub{font-size:11px;color:var(--txm);margin-top:2px;}
.pm-close{background:none;border:none;font-size:22px;color:#9ca3af;cursor:pointer;line-height:1;padding:4px 7px;border-radius:6px;transition:all .15s;flex-shrink:0;}
.pm-close:hover{background:var(--red-lt);color:var(--red);}
.pm-active-bar{display:flex;align-items:center;gap:8px;padding:9px 20px;background:var(--green-lt);border-bottom:1px solid var(--green-md);font-size:12px;font-weight:600;color:var(--green);flex-shrink:0;}
.pm-no-period{padding:9px 20px;background:var(--amber-lt);border-bottom:1px solid var(--amber-md);font-size:12px;font-weight:600;color:var(--amber);flex-shrink:0;}
.pm-cards{display:grid;grid-template-columns:repeat(3,1fr);border-bottom:1px solid var(--bdr);flex-shrink:0;}
.pm-card{padding:14px 16px;text-align:center;border-right:1px solid var(--bdr);}
.pm-card:last-child{border-right:none;}
.pm-card-lbl{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--txm);margin-bottom:6px;}
.pm-card-val{font-family:var(--mn);font-size:15px;font-weight:800;}
.pm-card.net .pm-card-val{color:var(--red);}
.pm-card.charged .pm-card-val{color:var(--purple);}
.pm-card.balance{background:var(--amber-lt);}
.pm-card.balance .pm-card-val{color:var(--amber);}
.pm-card.balance.settled{background:var(--green-lt);}
.pm-card.balance.settled .pm-card-val{color:var(--green);}
.pm-form-sec{padding:16px 20px;border-bottom:1px solid var(--bdr);}
.pm-sec-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:var(--txm);margin-bottom:12px;display:flex;align-items:center;gap:6px;}
.pm-grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;}
.pm-fg{display:flex;flex-direction:column;gap:4px;}
.pm-lbl{font-size:10px;font-weight:700;color:var(--txm);text-transform:uppercase;letter-spacing:.06em;}
.pm-inp{padding:8px 10px;border:1.5px solid var(--bdr);border-radius:8px;font-size:12.5px;font-family:var(--fn);color:var(--tx);background:var(--surface2);width:100%;transition:border-color .15s,box-shadow .15s;}
.pm-inp:focus{outline:none;border-color:var(--purple);box-shadow:0 0 0 3px rgba(124,58,237,.1);}
.pm-inp.mono{font-family:var(--mn);}
.btn-pm-save{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;background:linear-gradient(135deg,var(--purple),#6d28d9);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(124,58,237,.3);transition:all .15s;font-family:var(--fn);}
.btn-pm-save:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(124,58,237,.4);}
.btn-pm-save:disabled{opacity:.45;cursor:not-allowed;transform:none;}
.pm-hist-sec{padding:16px 20px;}
.pm-no-hist{text-align:center;padding:22px 10px;color:var(--txs);font-size:12px;}
.pm-ci{display:flex;align-items:center;gap:10px;padding:9px 12px;border:1px solid var(--bdr);border-radius:9px;margin-bottom:7px;background:var(--surface2);transition:background .15s;}
.pm-ci:hover{background:var(--purple-lt);}
.pm-ci-left{flex:1;min-width:0;}
.pm-ci-period{font-size:10px;font-weight:700;color:var(--purple);font-family:var(--mn);}
.pm-ci-date{font-size:10px;color:var(--txs);margin-top:1px;}
.pm-ci-desc{font-size:11px;color:var(--txm);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.pm-ci-amt{font-family:var(--mn);font-size:14px;font-weight:800;color:var(--purple);flex-shrink:0;}
.btn-del-ci{background:var(--red-lt);border:1px solid var(--red-md);border-radius:6px;padding:5px 9px;font-size:11px;color:var(--red);cursor:pointer;flex-shrink:0;transition:all .15s;}
.btn-del-ci:hover{background:var(--red);color:#fff;border-color:var(--red);}
.pm-spin{text-align:center;padding:20px;color:var(--txs);font-size:12px;}

.select2-container{font-family:var(--fn);font-size:12.5px;}
.select2-container .select2-selection--single{height:38px;border:1.5px solid var(--bdr);border-radius:8px;background:var(--surface2);display:flex;align-items:center;}
.select2-container--default .select2-selection--single .select2-selection__rendered{color:var(--tx);line-height:38px;padding-left:11px;padding-right:28px;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:36px;right:7px;}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open .select2-selection--single{border-color:var(--purple);box-shadow:0 0 0 3px rgba(124,58,237,.12);}
.select2-dropdown{border:1.5px solid var(--bdr);border-radius:10px;background:var(--surface);box-shadow:var(--shadow-lg);font-size:12.5px;}
.select2-results__option{padding:8px 13px;color:var(--tx);}
.select2-container--default .select2-results__option--highlighted[aria-selected]{background:var(--purple-lt) !important;color:var(--purple) !important;}
.select2-search--dropdown .select2-search__field{background:var(--surface2);border:1.5px solid var(--bdr);color:var(--tx);border-radius:6px;padding:6px 10px;}
.fbar .select2-container--default.select2-container--focus .select2-selection--single,
.fbar .select2-container--default.select2-container--open .select2-selection--single{border-color:var(--red);box-shadow:0 0 0 3px rgba(220,38,38,.1);}
.fbar .select2-container--default .select2-results__option--highlighted[aria-selected]{background:var(--red-lt) !important;color:var(--red) !important;}

@media(max-width:900px){.sc-row{grid-template-columns:repeat(3,1fr);}.pm-grid2{grid-template-columns:1fr;}.pm-cards{grid-template-columns:1fr;}}
@media(max-width:600px){.sc-row{grid-template-columns:1fr 1fr;}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}

/* ════════════════════════════════════════════════════
   PRINT STYLES
   - Hides site header/nav, filter bar, action columns
   - Preserves net-badge colours (red/green/amber)
════════════════════════════════════════════════════ */
@media print{
    *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;}
    body{background:#fff !important;color:#000 !important;font-size:10pt;font-family:Arial,sans-serif;}

    /* ── hide site header / nav rendered by header.php ── */
    header,
    .site-header,
    nav,
    .navbar,
    .nav,
    #header,
    #main-header,
    #site-header,
    #topnav,
    .topnav,
    .sidebar,
    #sidebar,
    .main-sidebar,
    .content-wrapper > .content-header,
    .main-header { display: none !important; }

    /* ── hide everything tagged no-print (Payroll Charged, Balance, Action cols) ── */
    .no-print{display:none !important;}

    /* hide interactive / decorative UI */
    .sc-row,.fbar,.topbar,.tc-bar,.top-scroll-wrap,
    #payrollModalBg,#cdrToast{display:none !important;}

    .pg{padding:0 !important;max-width:100% !important;}

    /* print header block */
    .print-header{display:block !important;border-bottom:2px solid #000;padding-bottom:6px;margin-bottom:8px;}
    .print-header h2{font-size:13pt;font-weight:700;margin:0;}
    .print-header p{font-size:9pt;color:#333;margin:2px 0 0;}

    .tc{box-shadow:none !important;border:1px solid #aaa !important;border-radius:0 !important;}
    .tscroll{overflow:visible !important;}

    /* ── strip ALL hyperlinks — render as plain text only ── */
    a, a:link, a:visited, a:hover, a:active {
        color:inherit !important;
        text-decoration:none !important;
        pointer-events:none !important;
        cursor:default !important;
    }
    a.net-link, a.val-link { display:inline !important; }
    .link-ico{display:none !important;}
    .val-link span.dashed, a span.dashed { border-bottom:none !important; }

    /* ── net-badge: keep full colour styling on print ── */
    .net-badge {
        display:inline-flex !important;
        align-items:center !important;
        gap:3px !important;
        padding:2px 9px !important;
        border-radius:20px !important;
        font-size:9pt !important;
        font-weight:700 !important;
        font-family:Arial,sans-serif !important;
    }
    .net-pos {
        background:#fee2e2 !important;
        color:#dc2626 !important;
        border:1px solid #fca5a5 !important;
    }
    .net-neg {
        background:#dcfce7 !important;
        color:#16a34a !important;
        border:1px solid #86efac !important;
    }
    .net-zero {
        background:#e8ecf2 !important;
        color:#4b5563 !important;
        border:1px solid #dde2ea !important;
    }

    /* flatten other badge/pill styles */
    .prl-badge,.pill,.dpill{
        display:inline !important;
        background:none !important;
        border:none !important;
        padding:0 !important;
        border-radius:0 !important;
        font-size:10pt !important;
        font-weight:700 !important;
        box-shadow:none !important;
    }

    /* table reset for print */
    table.cdr{width:100% !important;border-collapse:collapse !important;font-size:9pt !important;}

    .cdr thead tr.GH th{
        background:#e0e0e0 !important;
        color:#000 !important;
        font-size:8pt !important;
        font-weight:700 !important;
        padding:4px 6px !important;
        border:1px solid #999 !important;
        text-align:center !important;
        letter-spacing:0 !important;
    }
    .cdr thead tr.GH th.tl{text-align:left !important;}

    .cdr thead tr.TH td{
        background:#f0f0f0 !important;
        color:#000 !important;
        font-size:8.5pt !important;
        font-weight:700 !important;
        padding:3px 6px !important;
        border:1px solid #999 !important;
        text-align:right !important;
    }
    .cdr thead tr.TH td.tl{text-align:left !important;}

    .cdr tbody tr td{
        padding:4px 6px !important;
        border:1px solid #ccc !important;
        background:#fff !important;
        color:#000 !important;
        text-align:right !important;
        white-space:normal !important;
    }
    .cdr tbody tr.stripe td{background:#f7f7f7 !important;}
    .cdr tbody td.tl{text-align:left !important;}
    .cdr tbody td.tc{text-align:center !important;}
    .cdr tbody td.emp-name{font-weight:700 !important;}
    .cdr tbody td.days-td{text-align:center !important;color:#000 !important;}
    .cdr tbody td.row-no{text-align:center !important;color:#555 !important;}

    .stk{position:static !important;box-shadow:none !important;}

    /* keep shortage/excess/balance colours on print */
    .v-red  { color:#dc2626 !important; font-weight:700 !important; }
    .v-grn  { color:#16a34a !important; font-weight:700 !important; }
    .bal-pos{ color:#dc2626 !important; font-weight:700 !important; }
    .bal-neg{ color:#d97706 !important; font-weight:700 !important; }
    .bal-zero{color:#16a34a !important; font-weight:700 !important; }

    /* totals row colour classes */
    .c-red,.f-red{ color:#dc2626 !important; }
    .c-grn,.f-grn{ color:#16a34a !important; }
    .c-amb,.f-amb{ color:#d97706 !important; }
    .c-prp,.f-prp{ color:#7c3aed !important; }
    .c-cyn,.f-cyn{ color:#0891b2 !important; }

    .cdr tfoot td{
        background:#e0e0e0 !important;
        color:#000 !important;
        font-size:9pt !important;
        font-weight:700 !important;
        padding:4px 6px !important;
        border:1px solid #999 !important;
        text-align:right !important;
    }
    .cdr tfoot td.tl{text-align:left !important;}

    @page{margin:12mm 10mm;size:A4 landscape;}
}

/* print header hidden on screen */
.print-header{display:none;}
</style>

<!-- ══ PRINT HEADER (visible only when printing) ══ -->
<div class="print-header">
    <h2>Cash Shortage Deduction Report</h2>
    <p>
        Period: <?php echo date('d M Y',strtotime($date_from)).' &mdash; '.date('d M Y',strtotime($date_to)); ?>
        <?php if ($f_sr): ?> &nbsp;&middot;&nbsp; SR: <?php echo htmlspecialchars($f_sr); ?><?php endif; ?>
        &nbsp;&middot;&nbsp; Printed: <?php echo date('d M Y H:i'); ?>
        &nbsp;&middot;&nbsp; Total employees: <?php echo count($rows); ?>
    </p>
</div>

<div class="pg">

<div class="topbar no-print">
    <div class="pg-brand">
        <div class="pg-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
        <div>
            <div class="pg-h1">Cash Shortage <span>Deduction</span> Report</div>
            <div class="pg-sub">Employee-wise &nbsp;&middot;&nbsp; Shortage &nbsp;&middot;&nbsp; Excess &nbsp;&middot;&nbsp; Net &nbsp;&middot;&nbsp; Payroll Charged &nbsp;&middot;&nbsp; Balance &nbsp;&middot;&nbsp; Transfer-adjusted</div>
        </div>
    </div>
    <div class="topbtns">
        <div class="dpill"><i class="fa-solid fa-calendar-range"></i>
            <?php echo date('d M Y',strtotime($date_from)).' &mdash; '.date('d M Y',strtotime($date_to)); ?>
        </div>
        <?php if (!empty($rows)): ?>
        <button onclick="exportToExcel()" class="btn-excel"><i class="fa-solid fa-file-excel"></i> Excel</button>
        <button onclick="window.print()" class="btn-print"><i class="fa-solid fa-print"></i> Print</button>
        <button onclick="openDailyReport()" class="btn-rpt-daily" title="Daily Cash Shortage/Excess — B&W detailed printout">
            <i class="fa-solid fa-file-lines"></i> Daily Report
        </button>
        <button onclick="openSummaryReport()" class="btn-rpt-summary" title="Cash Shortage/Excess Summary — one row per employee">
            <i class="fa-solid fa-table-list"></i> Summary Report
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="fbar no-print">
    <form method="GET" id="cdrForm" style="display:contents;">
        <div class="fg">
            <label>Date From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
        </div>
        <div class="fg">
            <label>Date To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
        </div>
        <div class="fg" style="min-width:210px;">
            <label>Sales Rep Filter</label>
            <select name="sr_code" id="sel_sr">
                <option value="">— All Reps —</option>
                <?php foreach ($all_sr as $si): ?>
                <option value="<?php echo htmlspecialchars($si); ?>" <?php echo ($f_sr===$si)?'selected':''; ?>>
                    <?php echo htmlspecialchars($si); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-go" id="cdrBtn"><i class="fa-solid fa-bolt"></i> Apply Filter</button>
        <a href="cash_shortage_employee_report.php" class="btn-rst"><i class="fa-solid fa-rotate-left"></i></a>
    </form>
</div>

<?php if (empty($rows)): ?>
<div class="state-box">
    <div class="empty">
        <i class="fa-solid fa-inbox ico"></i>
        <p>No records found</p>
        <small><?php echo date('d M Y',strtotime($date_from)).' &ndash; '.date('d M Y',strtotime($date_to));
               echo $f_sr?' &nbsp;&middot;&nbsp; Rep: <strong>'.htmlspecialchars($f_sr).'</strong>':''; ?></small>
    </div>
</div>

<?php else: ?>

<!-- Summary cards (screen only) -->
<div class="sc-row no-print">
    <div class="sc red">
        <div class="sc-icon"><i class="fa-solid fa-circle-arrow-down"></i></div>
        <div class="sc-lbl">Shortage Charged</div>
        <div class="sc-val">Rs.&nbsp;<?php echo number_format($totals['shortage_charged'],2); ?></div>
        <div class="sc-sub">After transfer adjustment</div>
    </div>
    <div class="sc grn">
        <div class="sc-icon"><i class="fa-solid fa-circle-arrow-up"></i></div>
        <div class="sc-lbl">Excess Charged</div>
        <div class="sc-val">Rs.&nbsp;<?php echo number_format($totals['excess_charged'],2); ?></div>
        <div class="sc-sub">After transfer adjustment</div>
    </div>
    <div class="sc amb">
        <div class="sc-icon"><i class="fa-solid fa-scale-balanced"></i></div>
        <div class="sc-lbl">Net Charged</div>
        <div class="sc-val">Rs.&nbsp;<?php echo number_format($totals['net_charged'],2); ?></div>
        <div class="sc-sub">Shortage &minus; Excess</div>
    </div>
    <div class="sc prp">
        <div class="sc-icon"><i class="fa-solid fa-wallet"></i></div>
        <div class="sc-lbl">Payroll Charged</div>
        <div class="sc-val">Rs.&nbsp;<?php echo number_format($totals['total_payroll_charged'],2); ?></div>
        <div class="sc-sub">Total deducted via payroll</div>
    </div>
    <div class="sc blu">
        <div class="sc-icon"><i class="fa-solid fa-receipt"></i></div>
        <div class="sc-lbl">Outstanding Balance</div>
        <div class="sc-val">Rs.&nbsp;<?php echo number_format($totals['balance'],2); ?></div>
        <div class="sc-sub">Net &minus; Payroll Charged</div>
    </div>
</div>

<div class="tc">
    <div class="tc-bar no-print">
        <div class="tc-ttl">
            <i class="fa-solid fa-users" style="color:var(--purple);"></i>
            Employee Summary
            <span class="pill p-red"><?php echo count($rows); ?> employees</span>
            <span class="pill p-blue"><?php echo date('d M Y',strtotime($date_from)); ?> &ndash; <?php echo date('d M Y',strtotime($date_to)); ?></span>
            <?php if ($active_period): ?>
            <span class="pill p-green"><i class="fa-solid fa-circle" style="font-size:6px;animation:pulse 2s infinite;"></i>
                Active: <?php echo $MN[$active_period['month']].' '.$active_period['year']; ?></span>
            <?php endif; ?>
        </div>
        <div class="tc-hint"><i class="fa-solid fa-circle-info"></i>&nbsp; Click <strong>Payroll</strong> to record deduction &nbsp;&middot;&nbsp; Short/Excess figures are after cross-charge transfer adjustment</div>
    </div>

    <div class="top-scroll-wrap no-print" id="topScroll"><div class="top-scroll-inner" id="topScrollInner"></div></div>

    <div class="tscroll" id="mainScroll">
    <table class="cdr" id="cdrMain">
      <thead>
        <tr class="GH">
            <th style="min-width:36px;">#</th>
            <th class="tl stk" style="min-width:200px;">Employee Name</th>
            <th style="min-width:50px;">Days</th>
            <th class="col-sht" style="min-width:130px;">Shortage</th>
            <th class="col-exc" style="min-width:130px;">Excess</th>
            <th class="col-net" style="min-width:140px;">Net Charged</th>
            <th class="col-prl no-print" style="min-width:140px;">Payroll Charged</th>
            <th class="col-bal no-print" style="min-width:130px;">Balance</th>
            <th class="no-print" style="min-width:110px;text-align:center;">Action</th>
        </tr>
        <tr class="TH">
            <td class="tl stk" colspan="3">
                <i class="fa-solid fa-sigma" style="margin-right:5px;"></i>
                TOTALS &nbsp;&middot;&nbsp; <?php echo count($rows); ?> employees
            </td>
            <td class="c-red"><?php echo fmtT($totals['shortage_charged']); ?></td>
            <td class="c-grn"><?php echo fmtT($totals['excess_charged']); ?></td>
            <td class="c-amb"><?php echo fmtT($totals['net_charged']); ?></td>
            <td class="c-prp no-print" id="head_prl"><?php echo fmtT($totals['total_payroll_charged']); ?></td>
            <td class="c-cyn no-print" id="head_bal"><?php echo fmtT($totals['balance']); ?></td>
            <td class="no-print"></td>
        </tr>
      </thead>
      <tbody id="cdrBody">
      <?php
      $idx = 0;
      foreach ($rows as $row):
          $stripe  = ($idx % 2 !== 0) ? 'stripe' : '';
          $net_val = floatval($row['net_charged']);
          $prl_val = floatval($row['total_payroll_charged']);
          $bal_val = floatval($row['balance']);
          $balCls  = ($bal_val < -0.005) ? 'bal-neg' : (($bal_val > 0.005) ? 'bal-pos' : 'bal-zero');
          $netCls  = ($net_val >  0.005) ? 'net-pos' : (($net_val < -0.005) ? 'net-neg' : 'net-zero');
          if ($net_val > 0.005)      $netLbl = '&#9650;&nbsp;'.number_format($net_val,2);
          elseif ($net_val < -0.005) $netLbl = '&#9660;&nbsp;'.number_format(abs($net_val),2);
          else                       $netLbl = '&#8212;&nbsp;0.00';
          $balSign    = ($bal_val < 0) ? '-' : '';
          $hasCharges = $prl_val > 0.005;
          $detail_url = 'cash_shortage_employee_detail.php?employee_id='.intval($row['employee_id'])
              .'&date_from='.urlencode($date_from)
              .'&date_to='.urlencode($date_to)
              .($f_sr ? '&sr_code='.urlencode($f_sr) : '');
      ?>
      <tr class="<?php echo $stripe; ?>" id="row-<?php echo $idx; ?>">
          <td class="row-no"><?php echo $idx+1; ?></td>
          <td class="emp-name tl stk"><?php echo htmlspecialchars($row['employee_name']); ?></td>
          <td class="days-td"><?php echo intval($row['date_count']); ?></td>

          <!-- Shortage -->
          <td>
              <?php if (floatval($row['shortage_charged'])>0.005): ?>
                  <a href="<?php echo $detail_url; ?>" target="_blank" class="val-link" title="View shortage detail">
                      <span class="v-red dashed"><?php echo number_format($row['shortage_charged'],2); ?></span>
                  </a>
              <?php else: ?><span style="color:var(--txs);">—</span><?php endif; ?>
          </td>

          <!-- Excess -->
          <td>
              <?php if (floatval($row['excess_charged'])>0.005): ?>
                  <a href="<?php echo $detail_url; ?>" target="_blank" class="val-link" title="View excess detail">
                      <span class="v-grn dashed"><?php echo number_format($row['excess_charged'],2); ?></span>
                  </a>
              <?php else: ?><span style="color:var(--txs);">—</span><?php endif; ?>
          </td>

          <!-- Net charged — coloured badge on screen AND print -->
          <td>
              <a href="<?php echo $detail_url; ?>" target="_blank" class="net-link" title="View full breakdown">
                  <span class="net-badge <?php echo $netCls; ?>">
                      <?php echo $netLbl; ?>
                      <i class="fa-solid fa-arrow-up-right-from-square link-ico"></i>
                  </span>
              </a>
          </td>

          <!-- Payroll charged (screen only) -->
          <td class="no-print" id="prl_<?php echo $idx; ?>">
              <?php if ($hasCharges): ?>
                  <span class="prl-badge"><i class="fa-solid fa-wallet" style="font-size:9px;"></i>&nbsp;<?php echo number_format($prl_val,2); ?></span>
              <?php else: ?><span style="color:var(--txs);font-size:11px;">—</span><?php endif; ?>
          </td>

          <!-- Balance (screen only) -->
          <td class="no-print" id="bal_<?php echo $idx; ?>">
              <a href="<?php echo $detail_url; ?>" target="_blank" class="val-link" title="View detail breakdown">
                  <span class="<?php echo $balCls; ?> dashed"><?php echo $balSign.number_format(abs($bal_val),2); ?></span>
              </a>
          </td>

          <!-- Action (screen only) -->
          <td class="tc no-print">
              <button class="btn-payroll <?php echo $hasCharges?'has-charges':''; ?>"
                      onclick="openPayrollModal(<?php echo $idx; ?>)">
                  <i class="fa-solid fa-<?php echo $hasCharges?'check-circle':'wallet'; ?>"></i>
                  Payroll
              </button>
          </td>
      </tr>
      <?php $idx++; endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
            <td class="tl stk" colspan="3">GRAND TOTAL &mdash; <?php echo count($rows); ?> employees</td>
            <td class="f-red"><?php echo fmtT($totals['shortage_charged']); ?></td>
            <td class="f-grn"><?php echo fmtT($totals['excess_charged']); ?></td>
            <td class="f-amb"><?php echo fmtT($totals['net_charged']); ?></td>
            <td class="f-prp no-print" id="foot_prl"><?php echo fmtT($totals['total_payroll_charged']); ?></td>
            <td class="f-cyn no-print" id="foot_bal"><?php echo fmtT($totals['balance']); ?></td>
            <td class="no-print"></td>
        </tr>
      </tfoot>
    </table>
    </div>
</div>
<?php endif; ?>
</div><!-- /pg -->

<!-- PAYROLL MODAL -->
<div id="payrollModalBg" onclick="if(event.target===this)closePayrollModal()">
  <div class="pm-box">
    <div class="pm-hd">
        <div class="pm-hd-ico"><i class="fa-solid fa-wallet"></i></div>
        <div class="pm-hd-info">
            <div class="pm-hd-name" id="pmEmpName">Employee</div>
            <div class="pm-hd-sub">Cash Shortage &rarr; Payroll Deduction</div>
        </div>
        <button class="pm-close" onclick="closePayrollModal()">&times;</button>
    </div>
    <?php if ($active_period): ?>
    <div class="pm-active-bar">
        <i class="fa-solid fa-circle-dot" style="font-size:8px;animation:pulse 2s infinite;"></i>
        Active Period: &nbsp;<strong><?php echo $MN[$active_period['month']].' '.$active_period['year']; ?></strong>
        &nbsp;&middot;&nbsp;
        <?php echo date('d M Y',strtotime($active_period['open_date'])); ?> &ndash;
        <?php echo date('d M Y',strtotime($active_period['close_date'])); ?>
    </div>
    <?php else: ?>
    <div class="pm-no-period"><i class="fa-solid fa-triangle-exclamation"></i> No open payroll period found — please generate one in Payroll Month Management.</div>
    <?php endif; ?>
    <div class="pm-cards">
        <div class="pm-card net"><div class="pm-card-lbl">Net Shortage</div><div class="pm-card-val" id="pmNetVal">—</div></div>
        <div class="pm-card charged"><div class="pm-card-lbl">Payroll Charged</div><div class="pm-card-val" id="pmChargedVal">—</div></div>
        <div class="pm-card balance" id="pmBalCard"><div class="pm-card-lbl">Remaining Balance</div><div class="pm-card-val" id="pmBalVal">—</div></div>
    </div>
    <div class="pm-form-sec">
        <div class="pm-sec-lbl"><i class="fa-plus-circle fa-solid" style="color:var(--purple);"></i> New Charge</div>
        <div class="pm-grid2">
            <div class="pm-fg">
                <label class="pm-lbl">Payroll Period</label>
                <select id="pmPeriodSel" style="width:100%;">
                    <option value="">— Select Period —</option>
                    <?php foreach ($payroll_periods_all as $pp): ?>
                    <option value="<?php echo $pp['id']; ?>"
                            data-year="<?php echo $pp['year']; ?>"
                            data-month="<?php echo $pp['month']; ?>"
                            data-status="<?php echo $pp['status']; ?>"
                            data-open="<?php echo $pp['open_date']; ?>"
                            data-close="<?php echo $pp['close_date']; ?>"
                            <?php echo ($active_period && $pp['id']==$active_period['id'])?'selected':''; ?>>
                        <?php echo htmlspecialchars($pp['label']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pm-fg">
                <label class="pm-lbl">Charge Date</label>
                <input type="date" id="pmChargeDate" class="pm-inp mono" value="<?php echo date('Y-m-d'); ?>">
            </div>
        </div>
        <div class="pm-grid2" style="margin-bottom:14px;">
            <div class="pm-fg">
                <label class="pm-lbl">Amount (Rs.)</label>
                <input type="number" id="pmAmount" class="pm-inp mono" step="0.01" min="0.01" placeholder="0.00">
            </div>
            <div class="pm-fg">
                <label class="pm-lbl">Description</label>
                <input type="text" id="pmDesc" class="pm-inp" value="cash shortage" maxlength="200">
            </div>
        </div>
        <button class="btn-pm-save" id="pmSaveBtn" onclick="savePayrollCharge()">
            <i class="fa-solid fa-floppy-disk"></i> Save Charge
        </button>
    </div>
    <div class="pm-hist-sec">
        <div class="pm-sec-lbl" style="margin-bottom:10px;"><i class="fa-solid fa-clock-rotate-left" style="color:var(--txm);"></i> Charge History</div>
        <div id="pmChargesList"><div class="pm-spin"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div></div>
    </div>
  </div>
</div>

<div id="cdrToast"></div>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>

<?php
$rows_js = [];
foreach ($rows as $jr) {
    $rows_js[] = [
        'employee_id'          => intval($jr['employee_id']),
        'employee_name'        => $jr['employee_name'],
        'shortage_charged'     => floatval($jr['shortage_charged']),
        'excess_charged'       => floatval($jr['excess_charged']),
        'net_charged'          => floatval($jr['net_charged']),
        'total_payroll_charged'=> floatval($jr['total_payroll_charged']),
        'balance'              => floatval($jr['balance']),
        'date_count'           => intval($jr['date_count']),
    ];
}
$ap_js = $active_period ? [
    'id'         => intval($active_period['id']),
    'year'       => intval($active_period['year']),
    'month'      => intval($active_period['month']),
    'open_date'  => $active_period['open_date'],
    'close_date' => $active_period['close_date'],
] : null;
?>
<script>
if (typeof jQuery==='undefined') {
    document.write('<scr'+'ipt src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"><\/scr'+'ipt>');
}
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script>
var ROWS          = <?php echo json_encode($rows_js, JSON_UNESCAPED_UNICODE); ?>;
var ACTIVE_PERIOD = <?php echo json_encode($ap_js); ?>;
var MN            = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
var currentIdx    = -1;

var PRINT_QS = '?date_from=<?php echo urlencode($date_from); ?>'
             + '&date_to=<?php echo urlencode($date_to); ?>'
             + '<?php echo $f_sr ? "&sr_code=".urlencode($f_sr) : ""; ?>';

function openDailyReport()   { window.open('cash_shortage_print.php'         + PRINT_QS, '_blank', 'width=960,height=900,scrollbars=yes'); }
function openSummaryReport() { window.open('cash_shortage_summary_print.php' + PRINT_QS, '_blank', 'width=960,height=900,scrollbars=yes'); }

function toNum(v)  { return parseFloat(v)||0; }
function fmtAmt(v) { return Math.abs(toNum(v)).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function escH(s)   { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function safeFetch(url, options) {
    return fetch(url, options).then(function(res) {
        return res.text().then(function(txt) {
            try { return JSON.parse(txt); }
            catch(e) {
                var clean = txt.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().substring(0,400);
                throw new Error('Server error: ' + clean);
            }
        });
    });
}

function openPayrollModal(idx) {
    currentIdx = idx;
    var r = ROWS[idx];
    document.getElementById('payrollModalBg').style.display = 'flex';
    document.getElementById('pmEmpName').textContent = r.employee_name;
    renderBalCards(r.net_charged, r.total_payroll_charged, r.balance);
    var suggest = toNum(r.balance);
    document.getElementById('pmAmount').value = suggest > 0 ? suggest.toFixed(2) : '';
    if (ACTIVE_PERIOD) {
        $('#pmPeriodSel').val(ACTIVE_PERIOD.id).trigger('change');
        var di = document.getElementById('pmChargeDate');
        di.min = ACTIVE_PERIOD.open_date; di.max = ACTIVE_PERIOD.close_date; di.value = ACTIVE_PERIOD.close_date;
    }
    loadPayrollCharges(r.employee_id);
}
function closePayrollModal() { document.getElementById('payrollModalBg').style.display='none'; currentIdx=-1; }
function renderBalCards(net, charged, bal) {
    document.getElementById('pmNetVal').textContent     = 'Rs. '+fmtAmt(net);
    document.getElementById('pmChargedVal').textContent = 'Rs. '+fmtAmt(charged);
    document.getElementById('pmBalVal').textContent     = 'Rs. '+fmtAmt(Math.abs(bal))+(bal<0?' (CR)':'');
    var bc = document.getElementById('pmBalCard');
    if (bal<=0.005) bc.classList.add('settled'); else bc.classList.remove('settled');
}
function loadPayrollCharges(eid) {
    var list = document.getElementById('pmChargesList');
    list.innerHTML = '<div class="pm-spin"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    safeFetch('cash_shortage_employee_report.php?action=get_payroll_charges&employee_id='+eid)
        .then(function(d){ renderCharges(d.charges, eid); })
        .catch(function(e){ list.innerHTML = '<div class="pm-spin" style="color:var(--red);">'+escH(e.message)+'</div>'; });
}
function renderCharges(charges, eid) {
    var list = document.getElementById('pmChargesList');
    if (!charges || !charges.length) {
        list.innerHTML = '<div class="pm-no-hist"><i class="fa-solid fa-inbox" style="display:block;font-size:28px;margin-bottom:8px;opacity:.2;"></i>No payroll charges recorded yet.</div>';
        return;
    }
    var html = '';
    charges.forEach(function(c) {
        var period = (c.payroll_year && c.payroll_month) ? MN[parseInt(c.payroll_month)]+' '+c.payroll_year : '— No Period —';
        html += '<div class="pm-ci" id="ci_'+c.id+'">'
            +'<div class="pm-ci-left">'
            +'<div class="pm-ci-period"><i class="fa-solid fa-calendar-check" style="font-size:9px;"></i> '+escH(period)+'</div>'
            +'<div class="pm-ci-date">'+escH(c.charge_date||'')+'</div>'
            +'<div class="pm-ci-desc">'+escH(c.description||'cash shortage')+'</div>'
            +'</div>'
            +'<div class="pm-ci-amt">Rs. '+fmtAmt(c.amount)+'</div>'
            +'<button class="btn-del-ci" onclick="deleteCharge('+c.id+','+eid+')" title="Delete"><i class="fa-solid fa-trash"></i></button>'
            +'</div>';
    });
    list.innerHTML = html;
}
function savePayrollCharge() {
    if (currentIdx < 0) return;
    var r = ROWS[currentIdx];
    var pSel = document.getElementById('pmPeriodSel');
    var opt  = pSel.options[pSel.selectedIndex];
    var ppid = pSel.value || '';
    var ppYear = ppid ? ($(opt).data('year') || 0) : 0;
    var ppMon  = ppid ? ($(opt).data('month') || 0) : 0;
    var amount = toNum(document.getElementById('pmAmount').value);
    var cdate  = document.getElementById('pmChargeDate').value;
    var desc   = (document.getElementById('pmDesc').value||'').trim() || 'cash shortage';
    if (!amount || amount <= 0) { showToast('Enter a valid amount.','err'); return; }
    if (!cdate) { showToast('Select a charge date.','err'); return; }
    var btn = document.getElementById('pmSaveBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    safeFetch('cash_shortage_employee_report.php', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action:'save_payroll_charge', employee_id:r.employee_id, employee_name:r.employee_name,
            payroll_period_id:ppid, payroll_year:ppYear, payroll_month:ppMon,
            amount:amount.toFixed(2), charge_date:cdate, description:desc
        }).toString()
    }).then(function(res) {
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Charge';
        if (res.success) { updateRowData(currentIdx, res.new_total_charged); loadPayrollCharges(r.employee_id); document.getElementById('pmAmount').value=''; showToast('Charge saved!','ok'); }
        else showToast('Error: '+(res.message||'Unknown'),'err');
    }).catch(function(err) { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Charge'; showToast(err.message,'err'); });
}
function deleteCharge(cid, eid) {
    if (!confirm('Delete this payroll charge?')) return;
    var btn = document.querySelector('#ci_'+cid+' .btn-del-ci');
    if (btn) { btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>'; }
    safeFetch('cash_shortage_employee_report.php', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'delete_payroll_charge', charge_id:cid, employee_id:eid}).toString()
    }).then(function(res) {
        if (res.success) { if (currentIdx>=0) { updateRowData(currentIdx, res.new_total_charged); loadPayrollCharges(eid); } showToast('Charge deleted.','ok'); }
        else { showToast('Error: '+(res.message||'Unknown'),'err'); if (btn) { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-trash"></i>'; } }
    }).catch(function(err) { showToast(err.message,'err'); if (btn) { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-trash"></i>'; } });
}
function updateRowData(idx, newTotalCharged) {
    var r = ROWS[idx];
    var newBal = toNum(r.net_charged) - toNum(newTotalCharged);
    ROWS[idx].total_payroll_charged = newTotalCharged;
    ROWS[idx].balance = newBal;
    var balCls = newBal < -0.005 ? 'bal-neg' : (newBal > 0.005 ? 'bal-pos' : 'bal-zero');
    var balSgn = newBal < 0 ? '-' : '';
    var balCell = document.getElementById('bal_'+idx);
    if (balCell) balCell.innerHTML = '<span class="'+balCls+'">'+balSgn+fmtAmt(Math.abs(newBal))+'</span>';
    var prlCell = document.getElementById('prl_'+idx);
    if (prlCell) prlCell.innerHTML = newTotalCharged > 0.005
        ? '<span class="prl-badge"><i class="fa-solid fa-wallet" style="font-size:9px;"></i>&nbsp;'+fmtAmt(newTotalCharged)+'</span>'
        : '<span style="color:var(--txs);font-size:11px;">—</span>';
    var rowEl = document.getElementById('row-'+idx);
    if (rowEl) { var pb = rowEl.querySelector('.btn-payroll'); if (pb) {
        if (newTotalCharged > 0.005) { pb.classList.add('has-charges'); pb.innerHTML='<i class="fa-solid fa-check-circle"></i> Payroll'; }
        else { pb.classList.remove('has-charges'); pb.innerHTML='<i class="fa-solid fa-wallet"></i> Payroll'; }
    }}
    renderBalCards(r.net_charged, newTotalCharged, newBal);
    document.getElementById('pmAmount').value = newBal > 0 ? newBal.toFixed(2) : '';
    refreshFooter();
}
function refreshFooter() {
    var tp=0, tb=0;
    for (var i=0; i<ROWS.length; i++) { tp+=toNum(ROWS[i].total_payroll_charged); tb+=toNum(ROWS[i].balance); }
    ['foot_prl','head_prl'].forEach(function(id){ var el=document.getElementById(id); if(el) el.textContent=tp===0?'—':fmtAmt(tp); });
    ['foot_bal','head_bal'].forEach(function(id){ var el=document.getElementById(id); if(el) el.textContent=tb===0?'—':fmtAmt(tb); });
}
function exportToExcel() {
    if (!ROWS||!ROWS.length) { showToast('No data.','err'); return; }
    var titleRows = [
        ['Cash Shortage Deduction Report'],
        ['Period:', '<?php echo date("d M Y",strtotime($date_from)); ?> — <?php echo date("d M Y",strtotime($date_to)); ?>'],
        ['Generated:', new Date().toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})],
        ['Note:', 'Shortage/Excess figures are AFTER applying cross-charge transfers'],
        []
    ];
    var hdrs = ['#','Employee Name','Days','Shortage (After Xfer)','Excess (After Xfer)','Net Charged','Payroll Charged','Balance'];
    var data = ROWS.map(function(r,i){
        return [i+1, r.employee_name, r.date_count,
                r.shortage_charged, r.excess_charged, r.net_charged,
                r.total_payroll_charged, r.balance];
    });
    var allRows = titleRows.concat([hdrs], data);
    var ws = XLSX.utils.aoa_to_sheet(allRows);
    ws['!cols'] = [5,32,7,20,20,18,18,16].map(function(w){return{wch:w};});
    ws['!merges'] = [{ s:{r:0,c:0}, e:{r:0,c:7} }];
    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Shortage Deduction');
    XLSX.writeFile(wb, 'Cash_Shortage_Deduction_<?php echo date("d-M-Y",strtotime($date_from)); ?>_to_<?php echo date("d-M-Y",strtotime($date_to)); ?>.xlsx');
    showToast('Excel exported!','ok');
}
function showToast(msg, type) {
    var t = document.getElementById('cdrToast');
    t.className = type==='ok' ? 'toast-ok' : 'toast-err';
    t.textContent = msg; t.style.display='block'; t.style.opacity='1';
    clearTimeout(t._timer);
    t._timer = setTimeout(function(){ t.style.opacity='0'; setTimeout(function(){ t.style.display='none'; },300); }, 3500);
}
document.addEventListener('DOMContentLoaded', function() {
    if (typeof $ !== 'undefined') {
        $('#sel_sr').select2({placeholder:'— All Reps —', allowClear:true, width:'210px'});
        $('#pmPeriodSel').select2({
            placeholder:'— Select Payroll Period —', allowClear:true, width:'100%',
            dropdownParent:$('#payrollModalBg'),
            templateResult:function(opt){ if(!opt.id) return opt.text; var s=$(opt.element).data('status'); return $('<span>').text(opt.text).css({color:s==='Open'?'#16a34a':'#6b7280',fontWeight:s==='Open'?'700':'400'}); }
        });
        $('#pmPeriodSel').on('change', function() {
            var opt=$(this).find(':selected'), od=opt.data('open'), cd=opt.data('close');
            var di=document.getElementById('pmChargeDate');
            if(od&&cd){ di.min=od; di.max=cd; if(!di.value||di.value<od||di.value>cd) di.value=cd; } else { di.min=''; di.max=''; }
        });
    }
    var f=document.getElementById('cdrForm');
    if(f) f.addEventListener('submit',function(){ var b=document.getElementById('cdrBtn'); if(b){b.disabled=true;b.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Loading…';} });
    (function(){
        var top=document.getElementById('topScroll'),main=document.getElementById('mainScroll'),inner=document.getElementById('topScrollInner');
        if(!top||!main||!inner) return;
        function sw(){ var tbl=main.querySelector('table.cdr'); if(tbl) inner.style.width=tbl.scrollWidth+'px'; }
        sw(); window.addEventListener('resize',sw);
        var busy=false;
        top.addEventListener('scroll',function(){ if(busy)return; busy=true; main.scrollLeft=top.scrollLeft; busy=false; });
        main.addEventListener('scroll',function(){ if(busy)return; busy=true; top.scrollLeft=main.scrollLeft; busy=false; });
    })();
});
</script>
<?php include 'footer.php'; ?>