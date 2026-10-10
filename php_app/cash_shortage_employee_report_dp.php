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
        $amount   = round(floatval($_POST['amount']    ?? 0), 2);
        $amt_sql  = number_format($amount, 2, '.', '');
        $cdate_in = $_POST['charge_date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cdate_in)) $cdate_in = date('Y-m-d');
        $cdate    = mysqli_real_escape_string($conn, $cdate_in);
        $desc_in  = trim($_POST['description'] ?? '');
        if ($desc_in === '') $desc_in = 'cash shortage';
        $desc     = mysqli_real_escape_string($conn, mb_substr($desc_in, 0, 500));
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
        if (!$cid || !$eid) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
        mysqli_query($conn, "DELETE FROM payroll_payments_log WHERE id=$cid AND employee_id=$eid");
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
$f_dp      = trim($_GET['delivery_person'] ?? '');
$df        = mysqli_real_escape_string($conn, $date_from);
$dt        = mysqli_real_escape_string($conn, $date_to);
$dp_esc    = $f_dp ? mysqli_real_escape_string($conn, $f_dp) : '';

/* ── distinct delivery persons (same sources as the DP collection page) ── */
$all_dp = [];
$dp_qr  = mysqli_query($conn, "
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
    ORDER BY delivery_person");
if ($dp_qr) while ($r2 = mysqli_fetch_assoc($dp_qr)) $all_dp[] = $r2['delivery_person'];

$dpWhere = $dp_esc ? "AND a.delivery_person COLLATE utf8mb4_unicode_ci = '$dp_esc'" : '';

/* ═══════════════════════════════════════════════════════════════════════
   MAIN DATA — allocation-driven, SAME sign rule as the DP collection page
   ─────────────────────────────────────────────────────────────────────
     + amount  →  SHORT  (▼ charged to employee)
     − amount  →  EXCESS (▲ credited back to employee)

   Every (pay_date, delivery_person) allocation is first normalised to the
   direction of that row's Short/Excess balance, exactly like the collection
   page does. The row's balance is rebuilt from the saved entries:
        short_excess = variance + Σ charges + absorb
   (this identity holds for every save, so old records that were saved with
   the wrong sign are corrected automatically).
   If a row has no 'variance' entry, stored signs are kept as-is.
   Short and excess are kept independent per (date, dp) — never cancelled.
══════════════════════════════════════════════════════════════════════════ */

$sql_alloc = "
    SELECT a.pay_date, a.delivery_person, a.entry_type,
           a.employee_id, a.employee_name, a.amount
    FROM cash_summary_pay_allocations_dp a
    WHERE a.entry_type IN ('charge','absorb','variance')
      AND a.pay_date BETWEEN '$df' AND '$dt'
      $dpWhere
    ORDER BY a.pay_date ASC, a.delivery_person ASC, a.id ASC
";

/* group all entries per (pay_date, dp) */
$groups = [];
$qr = mysqli_query($conn, $sql_alloc);
if ($qr) while ($r = mysqli_fetch_assoc($qr)) {
    $k = $r['pay_date'].'|'.$r['delivery_person'];
    if (!isset($groups[$k])) $groups[$k] = [
        'pay_date'=>$r['pay_date'], 'dp'=>$r['delivery_person'],
        'charges'=>[], 'absorb'=>0.0, 'variance'=>0.0, 'has_var'=>false,
    ];
    $amt = floatval($r['amount']);
    if ($r['entry_type'] === 'charge') {
        if (intval($r['employee_id']) > 0) {
            $groups[$k]['charges'][] = [
                'employee_id'   => intval($r['employee_id']),
                'employee_name' => $r['employee_name'],
                'amount'        => $amt,
            ];
        }
    } elseif ($r['entry_type'] === 'absorb') {
        $groups[$k]['absorb'] = $amt;
    } elseif ($r['entry_type'] === 'variance') {
        $groups[$k]['variance'] = $amt;
        $groups[$k]['has_var']  = true;
    }
}

/* normalise + aggregate per employee */
$emp_agg = [];
foreach ($groups as $g) {
    if (empty($g['charges'])) continue;

    $dir = 0;
    if ($g['has_var']) {
        $sum_ch = 0.0;
        foreach ($g['charges'] as $c) $sum_ch += $c['amount'];
        $se  = round($g['variance'] + $sum_ch + $g['absorb'], 2);
        $dir = $se < -0.005 ? -1 : 1;
    }

    /* per-employee charge inside this (date, dp) */
    $per_emp = [];
    foreach ($g['charges'] as $c) {
        $a = $dir !== 0 ? $dir * abs($c['amount']) : $c['amount'];
        $eid = $c['employee_id'];
        if (!isset($per_emp[$eid])) $per_emp[$eid] = ['name'=>$c['employee_name'], 'amt'=>0.0];
        $per_emp[$eid]['amt'] += $a;
    }

    foreach ($per_emp as $eid => $pe) {
        if (!isset($emp_agg[$eid])) {
            $emp_agg[$eid] = [
                'employee_id'   => $eid,
                'employee_name' => $pe['name'],
                'shortage'      => 0.0,
                'excess'        => 0.0,
                'date_set'      => [],
                'entries'       => 0,
            ];
        }
        $amt = round($pe['amt'], 2);
        if ($amt >  0.005) $emp_agg[$eid]['shortage'] += $amt;
        if ($amt < -0.005) $emp_agg[$eid]['excess']   += abs($amt);
        $emp_agg[$eid]['date_set'][$g['pay_date']] = true;
        $emp_agg[$eid]['entries']++;
    }
}

/* payroll charges per employee (all recorded deductions) */
$ppl_totals = [];
$ppl_qr = mysqli_query($conn,
    "SELECT employee_id, COALESCE(SUM(amount), 0) AS total_charged
     FROM payroll_payments_log
     GROUP BY employee_id");
if ($ppl_qr) while ($p = mysqli_fetch_assoc($ppl_qr)) {
    $ppl_totals[intval($p['employee_id'])] = floatval($p['total_charged']);
}

/* build final rows */
$rows = [];
foreach ($emp_agg as $eid => $agg) {
    $shortage = round($agg['shortage'], 2);
    $excess   = round($agg['excess'], 2);
    $net      = round($shortage - $excess, 2);     /* + short / − excess */
    $prl      = round($ppl_totals[$eid] ?? 0.0, 2);
    $balance  = round($net - $prl, 2);             /* + outstanding / − credit */
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
usort($rows, function($a, $b){ return strcasecmp($a['employee_name'], $b['employee_name']); });

/* grand totals — short/excess and outstanding/credit kept separate */
$totals = [
    'shortage_charged'=>0,'excess_charged'=>0,'net_charged'=>0,
    'net_short'=>0,'net_excess'=>0,
    'total_payroll_charged'=>0,'balance'=>0,
    'bal_outstanding'=>0,'bal_credit'=>0,
];
foreach ($rows as $t) {
    $totals['shortage_charged']      += $t['shortage_charged'];
    $totals['excess_charged']        += $t['excess_charged'];
    $totals['net_charged']           += $t['net_charged'];
    $totals['total_payroll_charged'] += $t['total_payroll_charged'];
    $totals['balance']               += $t['balance'];
    if ($t['net_charged'] > 0.005)  $totals['net_short']  += $t['net_charged'];
    if ($t['net_charged'] < -0.005) $totals['net_excess'] += abs($t['net_charged']);
    if ($t['balance'] > 0.005)      $totals['bal_outstanding'] += $t['balance'];
    if ($t['balance'] < -0.005)     $totals['bal_credit']      += abs($t['balance']);
}

$MN = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$payroll_periods_all = [];
$active_period       = null;
$pp_qr = mysqli_query($conn, "SELECT id,year,month,open_date,close_date,status FROM payroll_periods ORDER BY year DESC, month DESC");
if ($pp_qr) {
    while ($pp = mysqli_fetch_assoc($pp_qr)) {
        $pp['label'] = $pp['year'].' — '.($MN[intval($pp['month'])] ?? $pp['month']).' ['.$pp['status'].']';
        $payroll_periods_all[] = $pp;
        if (!$active_period && $pp['status'] === 'Open') $active_period = $pp;
    }
}

/* ── display helpers ── */
function fmtT($v) { $n=floatval($v); return abs($n)<0.005?'—':number_format(abs($n),2); }
/* + short ▼ red, − excess ▲ green */
function fmtNetTot($v){
    $n=floatval($v);
    if(abs($n)<0.005) return '—';
    return $n>0 ? '▼ '.number_format($n,2) : '▲ '.number_format(abs($n),2);
}
/* totals with short/excess split */
function fmtSplit($short,$excess,$sLbl='Short',$eLbl='Excess',$eCls='ts-grn'){
    $o=[];
    if($short>0.005)  $o[]='<span class="ts ts-red">▼ '.number_format($short,2).' '.$sLbl.'</span>';
    if($excess>0.005) $o[]='<span class="ts '.$eCls.'">▲ '.number_format($excess,2).' '.$eLbl.'</span>';
    return $o?implode('',$o):'—';
}
/* ONE total amount — short and excess netted together */
/* TOTAL: + total, − total, and = final net */
function fmtTot3($pos,$neg,$type='net'){
    $pos=round(floatval($pos),2); $neg=round(floatval($neg),2); $net=round($pos-$neg,2);
    $pl = $type==='bal' ? ' Due' : ''; $nl = $type==='bal' ? ' CR' : '';
    $nc = $type==='bal' ? 'ts-amb' : 'ts-grn';
    $o='';
    if($pos>0.004) $o.='<span class="ts ts-red">▼ '.number_format($pos,2).$pl.'</span>';
    if($neg>0.004) $o.='<span class="ts '.$nc.'">▲ '.number_format($neg,2).$nl.'</span>';
    {
        if(abs($net)<0.005) $n='<span class="ts-grn">0.00 ✓</span>';
        elseif($net>0)      $n='<span class="ts-red">▼ '.number_format($net,2).$pl.'</span>';
        else                $n='<span class="'.$nc.'">▲ '.number_format(abs($net),2).$nl.'</span>';
        $o.='<span class="ts ts-net">= '.$n.'</span>';
    }
    return $o;
}
function fmtOneTot($v,$type='net'){
    $n=round(floatval($v),2);
    if(abs($n)<0.005) return '<span class="ts ts-grn">0.00 ✓</span>';
    if($type==='bal'){
        return $n>0 ? '<span class="ts ts-red">'.number_format($n,2).' Due</span>'
                    : '<span class="ts ts-amb">'.number_format(abs($n),2).' CR</span>';
    }
    return $n>0 ? '<span class="ts ts-red">▼ '.number_format($n,2).'</span>'
                : '<span class="ts ts-grn">▲ '.number_format(abs($n),2).'</span>';
}
function fmtBalCell($v){
    $n=floatval($v);
    if(abs($n)<0.005) return '<span class="bal-zero dashed">0.00 ✓</span>';
    if($n>0)          return '<span class="bal-pos dashed">'.number_format($n,2).'</span>';
    return '<span class="bal-neg dashed">'.number_format(abs($n),2).' CR</span>';
}
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
.sc-val .v-sub{font-size:10px;font-weight:600;}

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
.cdr thead tr.TH td{background:#f8fafc;color:var(--txm);font-weight:700;font-size:11.5px;padding:8px 14px;text-align:right;border-bottom:2px solid var(--bdr);white-space:nowrap;font-family:var(--mn);vertical-align:top;}
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
.cdr tfoot td{padding:11px 14px;font-weight:700;font-size:11.5px;background:#f8fafc;color:var(--txm);text-align:right;white-space:nowrap;border-top:2px solid var(--bdr);font-family:var(--mn);vertical-align:top;}
.cdr tfoot td.tl{text-align:left;color:var(--txs);font-family:var(--fn);}
.cdr tfoot td.f-red{color:var(--red);}
.cdr tfoot td.f-grn{color:var(--green);}
.cdr tfoot td.f-amb{color:var(--amber);}
.cdr tfoot td.f-prp{color:var(--purple);}
.cdr tfoot td.f-cyn{color:var(--cyan);}
.ts{display:block;line-height:1.55;font-family:var(--mn);font-weight:700;}
.ts-red{color:var(--red);}
.ts-grn{color:var(--green);}
.ts-amb{color:var(--amber);}
.ts-net{border-top:1px solid var(--bdr);margin-top:3px;padding-top:3px;font-weight:800;}

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
.pm-split{display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid var(--bdr);flex-shrink:0;}
.pm-split .pm-card{padding:10px 16px;}
.pm-cards{display:grid;grid-template-columns:repeat(3,1fr);border-bottom:1px solid var(--bdr);flex-shrink:0;}
.pm-card{padding:14px 16px;text-align:center;border-right:1px solid var(--bdr);}
.pm-card:last-child{border-right:none;}
.pm-card-lbl{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--txm);margin-bottom:6px;}
.pm-card-val{font-family:var(--mn);font-size:15px;font-weight:800;}
.pm-card.sht .pm-card-val{color:var(--red);font-size:13px;}
.pm-card.exc .pm-card-val{color:var(--green);font-size:13px;}
.pm-card.net .pm-card-val{color:var(--red);}
.pm-card.net.is-exc .pm-card-val{color:var(--green);}
.pm-card.net.is-zero .pm-card-val{color:var(--txm);}
.pm-card.charged .pm-card-val{color:var(--purple);}
.pm-card.balance{background:var(--red-lt);}
.pm-card.balance .pm-card-val{color:var(--red);}
.pm-card.balance.settled{background:var(--green-lt);}
.pm-card.balance.settled .pm-card-val{color:var(--green);}
.pm-card.balance.credit{background:var(--amber-lt);}
.pm-card.balance.credit .pm-card-val{color:var(--amber);}
.pm-warn{display:none;margin:0 20px 0;padding:9px 12px;border-radius:8px;background:var(--amber-lt);border:1px solid var(--amber-md);color:#92400e;font-size:11.5px;font-weight:600;}
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

@media print{
    *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;}
    body{background:#fff !important;color:#000 !important;font-size:10pt;font-family:Arial,sans-serif;}
    header,.site-header,nav,.navbar,.nav,#header,#main-header,#site-header,#topnav,.topnav,
    .sidebar,#sidebar,.main-sidebar,.content-wrapper > .content-header,.main-header{display:none !important;}
    .no-print{display:none !important;}
    .sc-row,.fbar,.topbar,.tc-bar,.top-scroll-wrap,#payrollModalBg,#cdrToast{display:none !important;}
    .pg{padding:0 !important;max-width:100% !important;}
    .print-header{display:block !important;border-bottom:2px solid #000;padding-bottom:6px;margin-bottom:8px;}
    .print-header h2{font-size:13pt;font-weight:700;margin:0;}
    .print-header p{font-size:9pt;color:#333;margin:2px 0 0;}
    .tc{box-shadow:none !important;border:1px solid #aaa !important;border-radius:0 !important;}
    .tscroll{overflow:visible !important;}
    a,a:link,a:visited,a:hover,a:active{color:inherit !important;text-decoration:none !important;pointer-events:none !important;cursor:default !important;}
    a.net-link,a.val-link{display:inline !important;}
    .link-ico{display:none !important;}
    .val-link span.dashed,a span.dashed{border-bottom:none !important;}
    .net-badge{display:inline-flex !important;align-items:center !important;gap:3px !important;padding:2px 9px !important;border-radius:20px !important;font-size:9pt !important;font-weight:700 !important;font-family:Arial,sans-serif !important;}
    .net-pos{background:#fee2e2 !important;color:#dc2626 !important;border:1px solid #fca5a5 !important;}
    .net-neg{background:#dcfce7 !important;color:#16a34a !important;border:1px solid #86efac !important;}
    .net-zero{background:#e8ecf2 !important;color:#4b5563 !important;border:1px solid #dde2ea !important;}
    .prl-badge,.pill,.dpill{display:inline !important;background:none !important;border:none !important;padding:0 !important;border-radius:0 !important;font-size:10pt !important;font-weight:700 !important;box-shadow:none !important;}
    table.cdr{width:100% !important;border-collapse:collapse !important;font-size:9pt !important;}
    .cdr thead tr.GH th{background:#e0e0e0 !important;color:#000 !important;font-size:8pt !important;font-weight:700 !important;padding:4px 6px !important;border:1px solid #999 !important;text-align:center !important;letter-spacing:0 !important;}
    .cdr thead tr.GH th.tl{text-align:left !important;}
    .cdr thead tr.TH td{background:#f0f0f0 !important;color:#000 !important;font-size:8.5pt !important;font-weight:700 !important;padding:3px 6px !important;border:1px solid #999 !important;text-align:right !important;}
    .cdr thead tr.TH td.tl{text-align:left !important;}
    .cdr tbody tr td{padding:4px 6px !important;border:1px solid #ccc !important;background:#fff !important;color:#000 !important;text-align:right !important;white-space:normal !important;}
    .cdr tbody tr.stripe td{background:#f7f7f7 !important;}
    .cdr tbody td.tl{text-align:left !important;}
    .cdr tbody td.tc{text-align:center !important;}
    .cdr tbody td.emp-name{font-weight:700 !important;}
    .cdr tbody td.days-td{text-align:center !important;color:#000 !important;}
    .cdr tbody td.row-no{text-align:center !important;color:#555 !important;}
    .stk{position:static !important;box-shadow:none !important;}
    .v-red{color:#dc2626 !important;font-weight:700 !important;}
    .v-grn{color:#16a34a !important;font-weight:700 !important;}
    .bal-pos{color:#dc2626 !important;font-weight:700 !important;}
    .bal-neg{color:#d97706 !important;font-weight:700 !important;}
    .bal-zero{color:#16a34a !important;font-weight:700 !important;}
    .c-red,.f-red,.ts-red{color:#dc2626 !important;}
    .c-grn,.f-grn,.ts-grn{color:#16a34a !important;}
    .c-amb,.f-amb,.ts-amb{color:#d97706 !important;}
    .c-prp,.f-prp{color:#7c3aed !important;}
    .c-cyn,.f-cyn{color:#0891b2 !important;}
    .cdr tfoot td{background:#e0e0e0 !important;color:#000 !important;font-size:9pt !important;font-weight:700 !important;padding:4px 6px !important;border:1px solid #999 !important;text-align:right !important;}
    .cdr tfoot td.tl{text-align:left !important;}
    @page{margin:12mm 10mm;size:A4 landscape;}
}
.print-header{display:none;}
</style>

<!-- ══ PRINT HEADER (visible only when printing) ══ -->
<div class="print-header">
    <h2>DP Cash Shortage Deduction Report</h2>
    <p>
        Period: <?php echo date('d M Y',strtotime($date_from)).' &mdash; '.date('d M Y',strtotime($date_to)); ?>
        <?php if ($f_dp): ?> &nbsp;&middot;&nbsp; Delivery Person: <?php echo htmlspecialchars($f_dp); ?><?php endif; ?>
        &nbsp;&middot;&nbsp; Printed: <?php echo date('d M Y H:i'); ?>
        &nbsp;&middot;&nbsp; Total employees: <?php echo count($rows); ?>
        &nbsp;&middot;&nbsp; ▼ Short &nbsp; ▲ Excess
    </p>
</div>

<div class="pg">

<div class="topbar no-print">
    <div class="pg-brand">
        <div class="pg-icon"><i class="fa-solid fa-truck-fast"></i></div>
        <div>
            <div class="pg-h1">DP Cash Shortage <span>Deduction</span> Report</div>
            <div class="pg-sub">Delivery-Person based &nbsp;&middot;&nbsp; ▼ Short (Charged) &nbsp;&middot;&nbsp; ▲ Excess (Credited) &nbsp;&middot;&nbsp; Net &nbsp;&middot;&nbsp; Payroll Charged &nbsp;&middot;&nbsp; Balance</div>
        </div>
    </div>
    <div class="topbtns">
        <div class="dpill"><i class="fa-solid fa-calendar-range"></i>
            <?php echo date('d M Y',strtotime($date_from)).' &mdash; '.date('d M Y',strtotime($date_to)); ?>
        </div>
        <?php if (!empty($rows)): ?>
        <button onclick="exportToExcel()" class="btn-excel"><i class="fa-solid fa-file-excel"></i> Excel</button>
        <button onclick="window.print()" class="btn-print"><i class="fa-solid fa-print"></i> Print</button>
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
        <div class="fg" style="min-width:240px;">
            <label>Delivery Person Filter</label>
            <select name="delivery_person" id="sel_dp">
                <option value="">— All Delivery Persons —</option>
                <?php foreach ($all_dp as $si): ?>
                <option value="<?php echo htmlspecialchars($si); ?>" <?php echo ($f_dp===$si)?'selected':''; ?>>
                    <?php echo htmlspecialchars($si); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-go" id="cdrBtn"><i class="fa-solid fa-bolt"></i> Apply Filter</button>
        <a href="cash_shortage_employee_report_dp.php" class="btn-rst"><i class="fa-solid fa-rotate-left"></i></a>
    </form>
</div>

<?php if (empty($rows)): ?>
<div class="state-box">
    <div class="empty">
        <i class="fa-solid fa-inbox ico"></i>
        <p>No records found</p>
        <small><?php echo date('d M Y',strtotime($date_from)).' &ndash; '.date('d M Y',strtotime($date_to));
               echo $f_dp?' &nbsp;&middot;&nbsp; Delivery Person: <strong>'.htmlspecialchars($f_dp).'</strong>':''; ?></small>
    </div>
</div>

<?php else: ?>

<!-- Summary cards (screen only) -->
<div class="sc-row no-print">
    <div class="sc red">
        <div class="sc-icon"><i class="fa-solid fa-circle-arrow-down"></i></div>
        <div class="sc-lbl">▼ Charged (Shortage)</div>
        <div class="sc-val">Rs.&nbsp;<?php echo number_format($totals['shortage_charged'],2); ?></div>
        <div class="sc-sub">Allocated on DP pay page</div>
    </div>
    <div class="sc grn">
        <div class="sc-icon"><i class="fa-solid fa-circle-arrow-up"></i></div>
        <div class="sc-lbl">▲ Credited (Excess)</div>
        <div class="sc-val">Rs.&nbsp;<?php echo number_format($totals['excess_charged'],2); ?></div>
        <div class="sc-sub">Credited back to employee</div>
    </div>
    <div class="sc <?php echo $totals['net_charged'] < -0.005 ? 'grn' : 'amb'; ?>">
        <div class="sc-icon"><i class="fa-solid fa-scale-balanced"></i></div>
        <div class="sc-lbl">Net Charged</div>
        <div class="sc-val"><?php
            $tn = $totals['net_charged'];
            echo ($tn > 0.005 ? '▼ ' : ($tn < -0.005 ? '▲ ' : '')).'Rs.&nbsp;'.number_format(abs($tn),2);
        ?></div>
        <div class="sc-sub">Shortage &minus; Excess</div>
    </div>
    <div class="sc prp">
        <div class="sc-icon"><i class="fa-solid fa-wallet"></i></div>
        <div class="sc-lbl">Payroll Charged</div>
        <div class="sc-val" id="card_prl">Rs.&nbsp;<?php echo number_format($totals['total_payroll_charged'],2); ?></div>
        <div class="sc-sub">Total deducted via payroll</div>
    </div>
    <div class="sc blu">
        <div class="sc-icon"><i class="fa-solid fa-receipt"></i></div>
        <div class="sc-lbl">Outstanding Balance</div>
        <div class="sc-val" id="card_bal"><?php
            $tb = round($totals['balance'],2);
            echo 'Rs.&nbsp;'.number_format(abs($tb),2)
               .($tb > 0.005 ? ' <span class="v-sub">(Due)</span>' : ($tb < -0.005 ? ' <span class="v-sub">(CR)</span>' : ''));
        ?></div>
        <div class="sc-sub" id="card_bal_sub">Net Charged &minus; Payroll Charged</div>
    </div>
</div>

<div class="tc">
    <div class="tc-bar no-print">
        <div class="tc-ttl">
            <i class="fa-solid fa-users" style="color:var(--purple);"></i>
            Employee Summary
            <span class="pill p-red"><?php echo count($rows); ?> employees</span>
            <span class="pill p-blue"><?php echo date('d M Y',strtotime($date_from)); ?> &ndash; <?php echo date('d M Y',strtotime($date_to)); ?></span>
            <span class="pill p-green"><i class="fa-solid fa-truck" style="font-size:8px;"></i> Delivery-Person based</span>
            <?php if ($active_period): ?>
            <span class="pill p-green"><i class="fa-solid fa-circle" style="font-size:6px;animation:pulse 2s infinite;"></i>
                Active: <?php echo $MN[intval($active_period['month'])].' '.$active_period['year']; ?></span>
            <?php endif; ?>
        </div>
        <div class="tc-hint"><i class="fa-solid fa-circle-info"></i>&nbsp;
            <span style="color:var(--red);font-weight:700;">▼ Short</span> = charged &nbsp;·&nbsp;
            <span style="color:var(--green);font-weight:700;">▲ Excess</span> = credited &nbsp;·&nbsp;
            <span style="color:var(--amber);font-weight:700;">CR</span> = over-deducted
        </div>
    </div>

    <div class="top-scroll-wrap no-print" id="topScroll"><div class="top-scroll-inner" id="topScrollInner"></div></div>

    <div class="tscroll" id="mainScroll">
    <table class="cdr" id="cdrMain">
      <thead>
        <tr class="GH">
            <th style="min-width:36px;">#</th>
            <th class="tl stk" style="min-width:200px;">Employee Name</th>
            <th style="min-width:50px;">Days</th>
            <th class="col-sht" style="min-width:130px;">▼ Shortage</th>
            <th class="col-exc" style="min-width:130px;">▲ Excess</th>
            <th class="col-net" style="min-width:150px;">Net Charged</th>
            <th class="col-prl no-print" style="min-width:140px;">Payroll Charged</th>
            <th class="col-bal no-print" style="min-width:150px;">Balance</th>
            <th class="no-print" style="min-width:110px;text-align:center;">Action</th>
        </tr>
        <tr class="TH">
            <td class="tl stk" colspan="3">
                <i class="fa-solid fa-sigma" style="margin-right:5px;"></i>
                TOTALS &nbsp;&middot;&nbsp; <?php echo count($rows); ?> employees
            </td>
            <td class="c-red"><?php echo fmtT($totals['shortage_charged']); ?></td>
            <td class="c-grn"><?php echo fmtT($totals['excess_charged']); ?></td>
            <td><?php echo fmtTot3($totals['net_short'],$totals['net_excess'],'net'); ?></td>
            <td class="c-prp no-print" id="head_prl"><?php echo fmtT($totals['total_payroll_charged']); ?></td>
            <td class="no-print" id="head_bal"><?php echo fmtTot3($totals['bal_outstanding'],$totals['bal_credit'],'bal'); ?></td>
            <td class="no-print"></td>
        </tr>
      </thead>
      <tbody id="cdrBody">
      <?php
      $idx = 0;
      $rows_js = [];
      foreach ($rows as $row):
          $stripe  = ($idx % 2 !== 0) ? 'stripe' : '';
          $net_val = floatval($row['net_charged']);
          $prl_val = floatval($row['total_payroll_charged']);
          $bal_val = floatval($row['balance']);
          $netCls  = ($net_val >  0.005) ? 'net-pos' : (($net_val < -0.005) ? 'net-neg' : 'net-zero');
          if ($net_val > 0.005)      $netLbl = '&#9660;&nbsp;'.number_format($net_val,2);
          elseif ($net_val < -0.005) $netLbl = '&#9650;&nbsp;'.number_format(abs($net_val),2);
          else                       $netLbl = '0.00 &#10003;';
          $hasCharges = $prl_val > 0.005;
          $detail_url = 'cash_shortage_employee_detail_dp.php?employee_id='.intval($row['employee_id'])
              .'&date_from='.urlencode($date_from)
              .'&date_to='.urlencode($date_to)
              .($f_dp ? '&delivery_person='.urlencode($f_dp) : '');
          $rows_js[] = [
              'employee_id'          => intval($row['employee_id']),
              'employee_name'        => $row['employee_name'],
              'shortage_charged'     => floatval($row['shortage_charged']),
              'excess_charged'       => floatval($row['excess_charged']),
              'net_charged'          => $net_val,
              'total_payroll_charged'=> $prl_val,
              'balance'              => $bal_val,
              'date_count'           => intval($row['date_count']),
              'detail_url'           => $detail_url,
          ];
      ?>
      <tr class="<?php echo $stripe; ?>" id="row-<?php echo $idx; ?>">
          <td class="row-no"><?php echo $idx+1; ?></td>
          <td class="emp-name tl stk"><?php echo htmlspecialchars($row['employee_name']); ?></td>
          <td class="days-td"><?php echo intval($row['date_count']); ?></td>

          <td>
              <?php if (floatval($row['shortage_charged'])>0.005): ?>
                  <a href="<?php echo htmlspecialchars($detail_url); ?>" target="_blank" class="val-link" title="View shortage detail">
                      <span class="v-red dashed">▼ <?php echo number_format($row['shortage_charged'],2); ?></span>
                  </a>
              <?php else: ?><span style="color:var(--txs);">—</span><?php endif; ?>
          </td>

          <td>
              <?php if (floatval($row['excess_charged'])>0.005): ?>
                  <a href="<?php echo htmlspecialchars($detail_url); ?>" target="_blank" class="val-link" title="View excess detail">
                      <span class="v-grn dashed">▲ <?php echo number_format($row['excess_charged'],2); ?></span>
                  </a>
              <?php else: ?><span style="color:var(--txs);">—</span><?php endif; ?>
          </td>

          <td>
              <a href="<?php echo htmlspecialchars($detail_url); ?>" target="_blank" class="net-link" title="View full breakdown">
                  <span class="net-badge <?php echo $netCls; ?>">
                      <?php echo $netLbl; ?>
                      <i class="fa-solid fa-arrow-up-right-from-square link-ico"></i>
                  </span>
              </a>
          </td>

          <td class="no-print" id="prl_<?php echo $idx; ?>">
              <?php if ($hasCharges): ?>
                  <span class="prl-badge"><i class="fa-solid fa-wallet" style="font-size:9px;"></i>&nbsp;<?php echo number_format($prl_val,2); ?></span>
              <?php else: ?><span style="color:var(--txs);font-size:11px;">—</span><?php endif; ?>
          </td>

          <td class="no-print" id="bal_<?php echo $idx; ?>">
              <a href="<?php echo htmlspecialchars($detail_url); ?>" target="_blank" class="val-link" title="View detail breakdown">
                  <?php echo fmtBalCell($bal_val); ?>
              </a>
          </td>

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
            <td><?php echo fmtTot3($totals['net_short'],$totals['net_excess'],'net'); ?></td>
            <td class="f-prp no-print" id="foot_prl"><?php echo fmtT($totals['total_payroll_charged']); ?></td>
            <td class="no-print" id="foot_bal"><?php echo fmtTot3($totals['bal_outstanding'],$totals['bal_credit'],'bal'); ?></td>
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
        Active Period: &nbsp;<strong><?php echo $MN[intval($active_period['month'])].' '.$active_period['year']; ?></strong>
        &nbsp;&middot;&nbsp;
        <?php echo date('d M Y',strtotime($active_period['open_date'])); ?> &ndash;
        <?php echo date('d M Y',strtotime($active_period['close_date'])); ?>
    </div>
    <?php else: ?>
    <div class="pm-no-period"><i class="fa-solid fa-triangle-exclamation"></i> No open payroll period found — please generate one in Payroll Month Management.</div>
    <?php endif; ?>
    <div class="pm-split">
        <div class="pm-card sht"><div class="pm-card-lbl">▼ Shortage</div><div class="pm-card-val" id="pmShtVal">—</div></div>
        <div class="pm-card exc"><div class="pm-card-lbl">▲ Excess</div><div class="pm-card-val" id="pmExcVal">—</div></div>
    </div>
    <div class="pm-cards">
        <div class="pm-card net" id="pmNetCard"><div class="pm-card-lbl">Net (Short/Excess)</div><div class="pm-card-val" id="pmNetVal">—</div></div>
        <div class="pm-card charged"><div class="pm-card-lbl">Payroll Charged</div><div class="pm-card-val" id="pmChargedVal">—</div></div>
        <div class="pm-card balance" id="pmBalCard"><div class="pm-card-lbl">Remaining Balance</div><div class="pm-card-val" id="pmBalVal">—</div></div>
    </div>
    <div class="pm-warn" id="pmWarn" style="margin-top:12px;"></div>
    <div class="pm-form-sec">
        <div class="pm-sec-lbl"><i class="fa-plus-circle fa-solid" style="color:var(--purple);"></i> New Charge</div>
        <div class="pm-grid2">
            <div class="pm-fg">
                <label class="pm-lbl">Payroll Period</label>
                <select id="pmPeriodSel" style="width:100%;">
                    <option value="">— Select Period —</option>
                    <?php foreach ($payroll_periods_all as $pp): ?>
                    <option value="<?php echo intval($pp['id']); ?>"
                            data-year="<?php echo intval($pp['year']); ?>"
                            data-month="<?php echo intval($pp['month']); ?>"
                            data-status="<?php echo htmlspecialchars($pp['status']); ?>"
                            data-open="<?php echo htmlspecialchars($pp['open_date']); ?>"
                            data-close="<?php echo htmlspecialchars($pp['close_date']); ?>"
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
if (!isset($rows_js)) $rows_js = [];
$ap_js = $active_period ? [
    'id'         => intval($active_period['id']),
    'year'       => intval($active_period['year']),
    'month'      => intval($active_period['month']),
    'open_date'  => $active_period['open_date'],
    'close_date' => $active_period['close_date'],
] : null;
$js_meta = [
    'period_label' => date('d M Y',strtotime($date_from)).' — '.date('d M Y',strtotime($date_to)),
    'file_name'    => 'DP_Cash_Shortage_Deduction_'.date('d-M-Y',strtotime($date_from)).'_to_'.date('d-M-Y',strtotime($date_to)).'.xlsx',
    'dp'           => $f_dp,
];
?>
<script>
if (typeof jQuery==='undefined') {
    document.write('<scr'+'ipt src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"><\/scr'+'ipt>');
}
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script>
var ROWS          = <?php echo json_encode($rows_js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP); ?>;
var ACTIVE_PERIOD = <?php echo json_encode($ap_js); ?>;
var META          = <?php echo json_encode($js_meta, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP); ?>;
var MN            = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
var currentIdx    = -1;

function toNum(v)  { return parseFloat(v)||0; }
function r2(v)     { return Math.round(toNum(v)*100)/100; }
function fmtAmt(v) { return Math.abs(toNum(v)).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function escH(s)   { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

/* + short ▼ / − excess ▲ */
function netText(n){
    n=toNum(n);
    if(Math.abs(n)<0.005) return '0.00 ✓';
    return n>0 ? '▼ Rs. '+fmtAmt(n) : '▲ Rs. '+fmtAmt(n);
}
function balCellHtml(b){
    b=toNum(b);
    if(Math.abs(b)<0.005) return '<span class="bal-zero dashed">0.00 ✓</span>';
    if(b>0) return '<span class="bal-pos dashed">'+fmtAmt(b)+'</span>';
    return '<span class="bal-neg dashed">'+fmtAmt(b)+' CR</span>';
}
function tot3Html(pos,neg,type){
    pos=r2(pos); neg=r2(neg); var net=r2(pos-neg);
    var pl=type==='bal'?' Due':'', nl=type==='bal'?' CR':'', nc=type==='bal'?'ts-amb':'ts-grn', o='';
    if(pos>0.004) o+='<span class="ts ts-red">▼ '+fmtAmt(pos)+pl+'</span>';
    if(neg>0.004) o+='<span class="ts '+nc+'">▲ '+fmtAmt(neg)+nl+'</span>';
    {
        var n=Math.abs(net)<0.005?'<span class="ts-grn">0.00 ✓</span>'
             :(net>0?'<span class="ts-red">▼ '+fmtAmt(net)+pl+'</span>':'<span class="'+nc+'">▲ '+fmtAmt(net)+nl+'</span>');
        o+='<span class="ts ts-net">= '+n+'</span>';
    }
    return o;
}
function oneTotHtml(n,type){
    n=r2(n);
    if(Math.abs(n)<0.005) return '<span class="ts ts-grn">0.00 ✓</span>';
    if(type==='bal') return n>0 ? '<span class="ts ts-red">'+fmtAmt(n)+' Due</span>'
                                : '<span class="ts ts-amb">'+fmtAmt(n)+' CR</span>';
    return n>0 ? '<span class="ts ts-red">▼ '+fmtAmt(n)+'</span>'
               : '<span class="ts ts-grn">▲ '+fmtAmt(n)+'</span>';
}
function splitHtml(s,e,sLbl,eLbl,eCls){
    var o='';
    if(s>0.005) o+='<span class="ts ts-red">▼ '+fmtAmt(s)+' '+sLbl+'</span>';
    if(e>0.005) o+='<span class="ts '+eCls+'">▲ '+fmtAmt(e)+' '+eLbl+'</span>';
    return o||'—';
}

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
    document.getElementById('pmShtVal').textContent = r.shortage_charged>0.005 ? 'Rs. '+fmtAmt(r.shortage_charged) : '—';
    document.getElementById('pmExcVal').textContent = r.excess_charged>0.005   ? 'Rs. '+fmtAmt(r.excess_charged)   : '—';
    renderBalCards(r.net_charged, r.total_payroll_charged, r.balance);
    var suggest = r2(r.balance);
    document.getElementById('pmAmount').value = suggest > 0 ? suggest.toFixed(2) : '';
    if (ACTIVE_PERIOD && typeof $ !== 'undefined') {
        $('#pmPeriodSel').val(String(ACTIVE_PERIOD.id)).trigger('change');
        var di = document.getElementById('pmChargeDate');
        di.min = ACTIVE_PERIOD.open_date; di.max = ACTIVE_PERIOD.close_date; di.value = ACTIVE_PERIOD.close_date;
    }
    loadPayrollCharges(r.employee_id);
}
function closePayrollModal() { document.getElementById('payrollModalBg').style.display='none'; currentIdx=-1; }

function renderBalCards(net, charged, bal) {
    net=r2(net); charged=r2(charged); bal=r2(bal);
    var nc=document.getElementById('pmNetCard');
    nc.className='pm-card net'+(net<-0.005?' is-exc':(Math.abs(net)<0.005?' is-zero':''));
    document.getElementById('pmNetVal').textContent     = netText(net);
    document.getElementById('pmChargedVal').textContent = 'Rs. '+fmtAmt(charged);

    var bc = document.getElementById('pmBalCard');
    bc.classList.remove('settled','credit');
    if (Math.abs(bal)<0.005) { bc.classList.add('settled'); document.getElementById('pmBalVal').textContent='0.00 ✓'; }
    else if (bal<0)          { bc.classList.add('credit');  document.getElementById('pmBalVal').textContent='Rs. '+fmtAmt(bal)+' (CR)'; }
    else                     { document.getElementById('pmBalVal').textContent='Rs. '+fmtAmt(bal)+' (Due)'; }

    var w=document.getElementById('pmWarn');
    if (net < -0.005) {
        w.style.display='block';
        w.innerHTML='<i class="fa-solid fa-circle-info"></i> This employee has a net <b>EXCESS</b> (credited back). No payroll deduction is due.';
    } else if (bal < -0.005) {
        w.style.display='block';
        w.innerHTML='<i class="fa-solid fa-triangle-exclamation"></i> Payroll has already deducted Rs. '+fmtAmt(bal)+' <b>more</b> than the net shortage.';
    } else {
        w.style.display='none';
    }
}
function loadPayrollCharges(eid) {
    var list = document.getElementById('pmChargesList');
    list.innerHTML = '<div class="pm-spin"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    safeFetch('cash_shortage_employee_report_dp.php?action=get_payroll_charges&employee_id='+encodeURIComponent(eid))
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
        var cid = parseInt(c.id,10);
        var period = (c.payroll_year && c.payroll_month) ? (MN[parseInt(c.payroll_month,10)]||c.payroll_month)+' '+c.payroll_year : '— No Period —';
        html += '<div class="pm-ci" id="ci_'+cid+'">'
            +'<div class="pm-ci-left">'
            +'<div class="pm-ci-period"><i class="fa-solid fa-calendar-check" style="font-size:9px;"></i> '+escH(period)+'</div>'
            +'<div class="pm-ci-date">'+escH(c.charge_date||'')+'</div>'
            +'<div class="pm-ci-desc">'+escH(c.description||'cash shortage')+'</div>'
            +'</div>'
            +'<div class="pm-ci-amt">Rs. '+fmtAmt(c.amount)+'</div>'
            +'<button class="btn-del-ci" onclick="deleteCharge('+cid+','+parseInt(eid,10)+')" title="Delete"><i class="fa-solid fa-trash"></i></button>'
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
    var ppYear = (ppid && opt) ? (opt.getAttribute('data-year')  || 0) : 0;
    var ppMon  = (ppid && opt) ? (opt.getAttribute('data-month') || 0) : 0;
    var amount = r2(document.getElementById('pmAmount').value);
    var cdate  = document.getElementById('pmChargeDate').value;
    var desc   = (document.getElementById('pmDesc').value||'').trim() || 'cash shortage';
    if (!amount || amount <= 0) { showToast('Enter a valid amount.','err'); return; }
    if (!cdate) { showToast('Select a charge date.','err'); return; }

    var bal = r2(r.balance);
    if (bal <= 0.005) {
        if (!confirm('This employee has no outstanding shortage balance.\nSave a payroll charge of Rs. '+fmtAmt(amount)+' anyway?')) return;
    } else if (amount > bal + 0.005) {
        if (!confirm('Amount Rs. '+fmtAmt(amount)+' is more than the outstanding balance Rs. '+fmtAmt(bal)+'.\nContinue?')) return;
    }

    var btn = document.getElementById('pmSaveBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    var saveIdx = currentIdx;
    safeFetch('cash_shortage_employee_report_dp.php', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action:'save_payroll_charge', employee_id:r.employee_id, employee_name:r.employee_name,
            payroll_period_id:ppid, payroll_year:ppYear, payroll_month:ppMon,
            amount:amount.toFixed(2), charge_date:cdate, description:desc
        }).toString()
    }).then(function(res) {
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Charge';
        if (res.success) {
            updateRowData(saveIdx, res.new_total_charged);
            loadPayrollCharges(r.employee_id);
            showToast('Charge saved!','ok');
        } else showToast('Error: '+(res.message||'Unknown'),'err');
    }).catch(function(err) { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Charge'; showToast(err.message,'err'); });
}
function deleteCharge(cid, eid) {
    if (!confirm('Delete this payroll charge?')) return;
    var btn = document.querySelector('#ci_'+cid+' .btn-del-ci');
    if (btn) { btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>'; }
    var delIdx = currentIdx;
    safeFetch('cash_shortage_employee_report_dp.php', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'delete_payroll_charge', charge_id:cid, employee_id:eid}).toString()
    }).then(function(res) {
        if (res.success) {
            if (delIdx>=0) { updateRowData(delIdx, res.new_total_charged); loadPayrollCharges(eid); }
            showToast('Charge deleted.','ok');
        } else {
            showToast('Error: '+(res.message||'Unknown'),'err');
            if (btn) { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-trash"></i>'; }
        }
    }).catch(function(err) { showToast(err.message,'err'); if (btn) { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-trash"></i>'; } });
}
function updateRowData(idx, newTotalCharged) {
    var r = ROWS[idx];
    newTotalCharged = r2(newTotalCharged);
    var newBal = r2(toNum(r.net_charged) - newTotalCharged);
    ROWS[idx].total_payroll_charged = newTotalCharged;
    ROWS[idx].balance = newBal;

    var balCell = document.getElementById('bal_'+idx);
    if (balCell) balCell.innerHTML = '<a href="'+escH(r.detail_url)+'" target="_blank" class="val-link" title="View detail breakdown">'+balCellHtml(newBal)+'</a>';
    var prlCell = document.getElementById('prl_'+idx);
    if (prlCell) prlCell.innerHTML = newTotalCharged > 0.005
        ? '<span class="prl-badge"><i class="fa-solid fa-wallet" style="font-size:9px;"></i>&nbsp;'+fmtAmt(newTotalCharged)+'</span>'
        : '<span style="color:var(--txs);font-size:11px;">—</span>';
    var rowEl = document.getElementById('row-'+idx);
    if (rowEl) { var pb = rowEl.querySelector('.btn-payroll'); if (pb) {
        if (newTotalCharged > 0.005) { pb.classList.add('has-charges'); pb.innerHTML='<i class="fa-solid fa-check-circle"></i> Payroll'; }
        else { pb.classList.remove('has-charges'); pb.innerHTML='<i class="fa-solid fa-wallet"></i> Payroll'; }
    }}
    if (idx === currentIdx) {
        renderBalCards(r.net_charged, newTotalCharged, newBal);
        document.getElementById('pmAmount').value = newBal > 0.005 ? newBal.toFixed(2) : '';
    }
    refreshFooter();
}
function refreshFooter() {
    var tp=0, tb=0, due=0, cr=0;
    for (var i=0; i<ROWS.length; i++) {
        tp += toNum(ROWS[i].total_payroll_charged);
        var b = toNum(ROWS[i].balance);
        tb += b;
        if (b > 0.005) due += b; else if (b < -0.005) cr += Math.abs(b);
    }
    tb = r2(tb);
    ['foot_prl','head_prl'].forEach(function(id){ var el=document.getElementById(id); if(el) el.textContent=Math.abs(tp)<0.005?'—':fmtAmt(tp); });
    ['foot_bal','head_bal'].forEach(function(id){ var el=document.getElementById(id); if(el) el.innerHTML=tot3Html(due,cr,'bal'); });
    var cp=document.getElementById('card_prl'); if(cp) cp.innerHTML='Rs.&nbsp;'+fmtAmt(tp);
    var cb=document.getElementById('card_bal');
    if(cb) cb.innerHTML='Rs.&nbsp;'+fmtAmt(tb)+(tb>0.005?' <span class="v-sub">(Due)</span>':(tb<-0.005?' <span class="v-sub">(CR)</span>':''));
}
function exportToExcel() {
    if (!ROWS||!ROWS.length) { showToast('No data.','err'); return; }
    if (typeof XLSX === 'undefined') { showToast('Excel library not loaded.','err'); return; }
    var titleRows = [
        ['DP Cash Shortage Deduction Report'],
        ['Period:', META.period_label],
        ['Delivery Person:', META.dp || 'All'],
        ['Generated:', new Date().toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})],
        ['Basis:', 'Net > 0 = ▼, Net < 0 = ▲. Balance > 0 = Due, Balance < 0 = CR (over-deducted).'],
        []
    ];
    var hdrs = ['#','Employee Name','Days','Shortage','Excess','Net Charged','Net ▼/▲','Payroll Charged','Balance','Balance Status'];
    var s={sh:0,ex:0,net:0,prl:0,bal:0};
    var data = ROWS.map(function(r,i){
        s.sh+=toNum(r.shortage_charged); s.ex+=toNum(r.excess_charged); s.net+=toNum(r.net_charged);
        s.prl+=toNum(r.total_payroll_charged); s.bal+=toNum(r.balance);
        var n=toNum(r.net_charged), b=toNum(r.balance);
        return [i+1, r.employee_name, r.date_count,
                r2(r.shortage_charged), r2(r.excess_charged), r2(n),
                n>0.005?'▼':(n<-0.005?'▲':'✓'),
                r2(r.total_payroll_charged), r2(b),
                b>0.005?'Due':(b<-0.005?'CR':'Settled')];
    });
    var totRow = ['', 'GRAND TOTAL', '', r2(s.sh), r2(s.ex), r2(s.net), '', r2(s.prl), r2(s.bal), ''];
    var allRows = titleRows.concat([hdrs], data, [totRow]);
    var ws = XLSX.utils.aoa_to_sheet(allRows);
    ws['!cols'] = [5,32,7,16,16,16,12,18,16,14].map(function(w){return{wch:w};});
    ws['!merges'] = [{ s:{r:0,c:0}, e:{r:0,c:9} }];
    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Shortage Deduction');
    XLSX.writeFile(wb, META.file_name);
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
    if (typeof $ !== 'undefined' && $.fn.select2) {
        $('#sel_dp').select2({placeholder:'— All Delivery Persons —', allowClear:true, width:'240px'});
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