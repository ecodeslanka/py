<?php
include 'config.php';
include 'header.php';

/* ═══════════════════════════════════════════════════════════
   PARAMS
═══════════════════════════════════════════════════════════ */
$employee_id = intval($_GET['employee_id'] ?? 0);
$date_from   = trim($_GET['date_from'] ?? date('Y-m-01'));
$date_to     = trim($_GET['date_to']   ?? date('Y-m-d'));
$f_sr        = trim($_GET['sr_code']   ?? '');

if (!$employee_id) {
    echo '<div style="padding:40px;text-align:center;color:#9ca3af;font-family:Inter,sans-serif;">
        <i class="fa-solid fa-triangle-exclamation" style="font-size:36px;display:block;margin-bottom:10px;opacity:.3;"></i>
        <p style="font-size:14px;font-weight:600;">Missing employee ID.</p>
        <p style="font-size:12px;">Required: employee_id, date_from, date_to</p></div>';
    include 'footer.php'; exit;
}

$df     = mysqli_real_escape_string($conn, $date_from);
$dt     = mysqli_real_escape_string($conn, $date_to);
$sr_esc = $f_sr ? mysqli_real_escape_string($conn, $f_sr) : '';

$emp_res = mysqli_query($conn, "SELECT id, employee_id AS emp_code,
    COALESCE(NULLIF(name_with_initials,''), employee_full_name, CONCAT('Employee #',id)) AS emp_name
    FROM employees WHERE id = $employee_id LIMIT 1");
$emp = $emp_res ? mysqli_fetch_assoc($emp_res) : null;
$emp_name = $emp['emp_name'] ?? 'Employee #'.$employee_id;
$emp_code = $emp['emp_code'] ?? 'EMP-'.$employee_id;

/* ═══ CHARGE ALLOCATIONS ═══ */
$srWhere = $sr_esc ? "AND a.sr_code COLLATE utf8mb4_unicode_ci = '$sr_esc'" : '';

$alloc_rows = [];
$alloc_res  = mysqli_query($conn, "
    SELECT a.pay_date, a.sr_code, a.amount AS charge_amount
    FROM cash_summary_pay_allocations a
    WHERE a.employee_id = $employee_id AND a.entry_type = 'charge'
      AND a.pay_date BETWEEN '$df' AND '$dt' $srWhere
    ORDER BY a.pay_date ASC, a.sr_code ASC");
if ($alloc_res) while ($r = mysqli_fetch_assoc($alloc_res)) $alloc_rows[] = $r;

$absorb_map = [];
$abs_res = mysqli_query($conn, "SELECT pay_date, sr_code, SUM(amount) AS absorb_amount
    FROM cash_summary_pay_allocations WHERE entry_type = 'absorb'
    AND pay_date BETWEEN '$df' AND '$dt' $srWhere GROUP BY pay_date, sr_code");
if ($abs_res) while ($r = mysqli_fetch_assoc($abs_res))
    $absorb_map[$r['pay_date'].'|'.$r['sr_code']] = floatval($r['absorb_amount']);

$all_charges_map = [];
$ac_res = mysqli_query($conn, "SELECT pay_date, sr_code, employee_id, employee_name, SUM(amount) AS amount
    FROM cash_summary_pay_allocations WHERE entry_type = 'charge'
    AND pay_date BETWEEN '$df' AND '$dt' $srWhere
    GROUP BY pay_date, sr_code, employee_id, employee_name ORDER BY pay_date, sr_code, employee_name");
if ($ac_res) while ($r = mysqli_fetch_assoc($ac_res)) {
    $k = $r['pay_date'].'|'.$r['sr_code'];
    if (!isset($all_charges_map[$k])) $all_charges_map[$k] = [];
    $all_charges_map[$k][] = $r;
}

/* ═══ UNIQUE KEYS ═══ */
$unique_keys = [];
foreach ($alloc_rows as $ar)
    $unique_keys[$ar['pay_date'].'|'.$ar['sr_code']] = ['date'=>$ar['pay_date'],'sr_code'=>$ar['sr_code']];

$date_list = array_unique(array_column(array_values($unique_keys), 'date'));
$date_in   = !empty($date_list) ? implode("','", array_map(fn($d) => mysqli_real_escape_string($conn, $d), $date_list)) : '';

/* ═══ PAYMENT RECORDS (LINE ITEMS) ═══ */
$payments_map = [];
if ($date_in !== '') {
    $sr_cond = $sr_esc ? "AND fs.sr_code='$sr_esc'" : '';
    $pay_res = mysqli_query($conn, "
        SELECT ip.id AS payment_id, ip.invoice_num, ip.t_code, fsd.customer_name, fsd.route,
               fs.delivery_date, fs.sr_code, fsd.adjust_net_value AS invoice_value,
               ip.amount, ip.payment_date, ip.payment_source, ip.collected_by,
               ip.reference_no, ip.remarks, ip.created_at
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
        WHERE ip.payment_method = 'cash' AND ip.is_reversed = 0
          AND ip.payment_date IN ('$date_in') $sr_cond
        ORDER BY ip.payment_date, fs.sr_code, LOWER(TRIM(COALESCE(ip.collected_by,''))), ip.invoice_num");
    if ($pay_res) while ($r = mysqli_fetch_assoc($pay_res)) {
        $k = $r['payment_date'].'|'.$r['sr_code'];
        if (!isset($payments_map[$k])) $payments_map[$k] = [];
        $payments_map[$k][] = $r;
    }
}

/* ═══ DEPOSIT RECORDS (LINE ITEMS) ═══ */
$deposits_map = [];
if ($date_in !== '') {
    $sr_dep_cond = $sr_esc ? "AND r.rep_code='$sr_esc'" : '';
    $dep_res = mysqli_query($conn, "
        SELECT d.id AS deposit_id, d.delivery_date, d.deposit_date, d.cash_receive_date,
               d.amount AS deposit_amount, d.handed_over_bo, d.collected_by AS dep_collected_by,
               d.remark, r.rep_code, r.amount AS rep_amount,
               COALESCE(NULLIF(e.name_with_initials,''), e.employee_full_name,'—') AS deposited_by,
               CONCAT(COALESCE(NULLIF(b.bank_name,''), cba.bank_code, ''), ' / ',
                      COALESCE(NULLIF(bb.branch_name,''), cba.branch_code, ''),
                      ' (', COALESCE(cba.account_no,''), ')') AS bank_label
        FROM cc_cash_deposits d
        INNER JOIN cc_cash_deposit_reps r ON r.deposit_id = d.id
        LEFT JOIN employees e ON e.id = d.employee_id
        LEFT JOIN company_bank_accounts cba ON cba.id = d.bank_account_id
        LEFT JOIN banks b ON b.bank_code = cba.bank_code
        LEFT JOIN bank_branches bb ON bb.bank_code = cba.bank_code AND bb.branch_code = cba.branch_code
        WHERE d.delivery_date IN ('$date_in') $sr_dep_cond
        ORDER BY d.delivery_date, r.rep_code, d.id");
    if ($dep_res) while ($r = mysqli_fetch_assoc($dep_res)) {
        $k = $r['delivery_date'].'|'.$r['rep_code'];
        if (!isset($deposits_map[$k])) $deposits_map[$k] = [];
        $deposits_map[$k][] = $r;
    }
}

/* ═══ BUILD DETAIL ROWS ═══ */
$details = []; $tot_short = 0; $tot_excess = 0; $tot_charge = 0;

foreach ($alloc_rows as $ar) {
    $k = $ar['pay_date'].'|'.$ar['sr_code'];
    $all_payments = $payments_map[$k] ?? [];
    $cc_payments = []; $sr_payments = []; $cc_total = 0; $sr_total = 0; $total_coll = 0;
    foreach ($all_payments as $p) {
        $cb = strtolower(trim($p['collected_by'] ?? ''));
        if ($cb === 'cc') { $cc_payments[] = $p; $cc_total += floatval($p['amount']); }
        else { $sr_payments[] = $p; $sr_total += floatval($p['amount']); }
        $total_coll += floatval($p['amount']);
    }
    $all_deposits = $deposits_map[$k] ?? [];
    $bank_deposits = []; $bo_deposits = []; $total_dep = 0;
    $banked_cc=0; $banked_sr=0; $handed_cc=0; $handed_sr=0;
    foreach ($all_deposits as $dp) {
        $amt = floatval($dp['rep_amount']); $total_dep += $amt;
        $dcb = strtolower(trim($dp['dep_collected_by'] ?? ''));
        if (intval($dp['handed_over_bo']) === 1) {
            $bo_deposits[] = $dp;
            if ($dcb === 'cc') $handed_cc += $amt; else $handed_sr += $amt;
        } else {
            $bank_deposits[] = $dp;
            if ($dcb === 'cc') $banked_cc += $amt; else $banked_sr += $amt;
        }
    }
    $variance = $total_coll - $total_dep;
    $absorb = $absorb_map[$k] ?? 0;
    $all_emp_charges = $all_charges_map[$k] ?? [];
    $total_emp_charge = array_sum(array_column($all_emp_charges, 'amount'));
    $this_charge = floatval($ar['charge_amount']);
    if ($variance > 0.005) $tot_short += $this_charge;
    if ($variance < -0.005) $tot_excess += $this_charge;
    $tot_charge += $this_charge;
    $details[] = compact('cc_payments','sr_payments','cc_total','sr_total','total_coll',
        'bank_deposits','bo_deposits','banked_cc','banked_sr','handed_cc','handed_sr','total_dep',
        'variance','absorb','all_emp_charges','total_emp_charge','this_charge')
        + ['pay_date'=>$ar['pay_date'],'sr_code'=>$ar['sr_code']];
}

$net_charged = $tot_short - $tot_excess;
$prl_res = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS t FROM payroll_payments_log WHERE employee_id=$employee_id");
$total_payroll = $prl_res ? floatval(mysqli_fetch_assoc($prl_res)['t']) : 0;
$balance = $net_charged - $total_payroll;
$date_display = date('d M Y', strtotime($date_from)).' – '.date('d M Y', strtotime($date_to));

function sd_source($s) {
    $map = ['invoice'=>['Invoice','#1e40af','#dbeafe'],'credit_sales'=>['Credit Sale','#7c3aed','#ede9fe'],
        'credit_sale'=>['Credit Sale','#7c3aed','#ede9fe'],'return_cheque_settlement'=>['Rtn Cheque','#b45309','#fef3c7'],
        'return_charge_settlement'=>['Rtn Charges','#c2410c','#ffedd5'],'sentback_cheque_settlement'=>['Sent Back','#be185d','#fce7f3']];
    $s = $s ?: 'invoice'; $m = $map[$s] ?? [$s,'#475569','#f1f5f9'];
    return '<span style="display:inline-block;padding:1px 7px;border-radius:10px;font-size:9px;font-weight:700;background:'.$m[2].';color:'.$m[1].';">'.$m[0].'</span>';
}
function sd_coll($cb) {
    $cb = strtolower(trim($cb ?? ''));
    if ($cb === 'cc') return '<span style="display:inline-block;padding:1px 7px;border-radius:10px;font-size:9px;font-weight:800;background:#dcfce7;color:#166534;">CC</span>';
    if ($cb === 'sr') return '<span style="display:inline-block;padding:1px 7px;border-radius:10px;font-size:9px;font-weight:800;background:#ccfbf1;color:#0f766e;">SR</span>';
    return '<span style="display:inline-block;padding:1px 7px;border-radius:10px;font-size:9px;font-weight:700;background:#f1f5f9;color:#475569;">'.strtoupper($cb ?: '—').'</span>';
}
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');
:root{--bg:#eef0f3;--surface:#fff;--bdr:#d2d6db;--bdrs:#e4e7ec;--tx:#1a1f2e;--txm:#58626e;--txs:#9aa3af;
--fn:'Inter',sans-serif;--mn:'JetBrains Mono',monospace;--r:10px;--sh:0 1px 3px rgba(0,0,0,.07),0 4px 14px rgba(0,0,0,.05);
--red:#dc2626;--red-lt:#fee2e2;--red-md:#fca5a5;--green:#16a34a;--green-lt:#dcfce7;--green-md:#86efac;
--amber:#d97706;--amber-lt:#fef3c7;--amber-md:#fcd34d;--purple:#7c3aed;--purple-lt:#ede9fe;--purple-md:#c4b5fd;
--blue:#2563eb;--blue-lt:#dbeafe;--blue-md:#93c5fd;--cyan:#0891b2;--cyan-lt:#cffafe;--cyan-md:#67e8f9;
--teal:#0f766e;--teal-lt:#ccfbf1;--teal-md:#5eead4;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:13px;}
.pg{padding:22px 18px 60px;max-width:1500px;margin:0 auto;}
.topbar{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;}
.pg-brand{display:flex;align-items:center;gap:14px;}
.pg-icon{width:46px;height:46px;background:linear-gradient(135deg,var(--red),#991b1b);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0;box-shadow:0 4px 16px rgba(220,38,38,.25);}
.pg-h1{font-size:20px;font-weight:800;letter-spacing:-.02em;}.pg-h1 em{font-style:normal;color:var(--red);}
.pg-sub{font-size:11px;color:var(--txs);margin-top:3px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.chip{display:inline-flex;align-items:center;gap:5px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:3px 10px;font-size:11px;font-weight:700;color:#334155;}
.chip.red{background:var(--red-lt);border-color:var(--red-md);color:var(--red);}
.topbtns{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.btn-back{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#fff;color:var(--txm);border:1px solid var(--bdr);border-radius:7px;font-size:12px;font-weight:600;text-decoration:none;transition:all .15s;}
.btn-back:hover{background:#f8fafc;color:var(--tx);}
.btn-print{display:inline-flex;align-items:center;gap:5px;padding:8px 14px;background:#f0f0f0;color:var(--txm);border:1px solid var(--bdr);border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;}
.print-header{display:none;}
.emp-banner{display:flex;align-items:center;gap:14px;padding:14px 20px;background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);margin-bottom:16px;box-shadow:var(--sh);border-left:4px solid var(--red);}
.emp-avatar{width:42px;height:42px;background:linear-gradient(135deg,var(--red-lt),#fff);border:2px solid var(--red-md);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;color:var(--red);flex-shrink:0;}
.emp-info{flex:1;min-width:0;}.emp-info-name{font-size:16px;font-weight:800;}.emp-info-code{font-size:11px;color:var(--txm);font-family:var(--mn);margin-top:2px;}
.stat-row{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:18px;}
.stat{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:14px 16px;box-shadow:var(--sh);position:relative;overflow:hidden;}
.stat::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.stat.red::before{background:var(--red);}.stat.grn::before{background:var(--green);}.stat.amb::before{background:var(--amber);}
.stat.prp::before{background:var(--purple);}.stat.blu::before{background:var(--blue);}.stat.cyn::before{background:var(--cyan);}
.stat-lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--txm);margin-bottom:6px;}
.stat-val{font-family:var(--mn);font-size:16px;font-weight:800;}
.stat.red .stat-val{color:var(--red);}.stat.grn .stat-val{color:var(--green);}.stat.amb .stat-val{color:var(--amber);}
.stat.prp .stat-val{color:var(--purple);}.stat.blu .stat-val{color:var(--blue);}.stat.cyn .stat-val{color:var(--cyan);}
.stat-sub{font-size:10px;color:var(--txs);margin-top:3px;}
.calc-box{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;margin-bottom:18px;font-size:12px;color:#78350f;}
.calc-box .formula{font-family:var(--mn);font-size:11px;font-weight:600;margin-top:6px;color:#92400e;}
.detail-card{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);box-shadow:var(--sh);margin-bottom:20px;overflow:hidden;}
.dc-header{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;border-bottom:1px solid var(--bdrs);background:#f8fafc;flex-wrap:wrap;gap:8px;}
.dc-header-left{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.dc-idx{background:#e2e8f0;border-radius:5px;padding:2px 8px;font-size:11px;font-weight:700;color:var(--txm);font-family:var(--mn);}
.dc-date{font-family:var(--mn);font-size:12px;font-weight:700;color:var(--tx);background:var(--green-lt);border:1px solid var(--green-md);border-radius:7px;padding:3px 10px;}
.dc-sr{font-family:var(--mn);font-size:12px;font-weight:700;color:var(--blue);background:var(--blue-lt);border:1px solid var(--blue-md);border-radius:7px;padding:3px 10px;}
.dc-charge-val{font-family:var(--mn);font-size:16px;font-weight:800;color:var(--red);}
.dc-body{padding:18px 20px;}
.var-box{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:8px;margin-bottom:16px;}
.var-box.short{background:var(--red-lt);border:1px solid var(--red-md);}
.var-box.excess{background:var(--green-lt);border:1px solid var(--green-md);}
.var-box.balanced{background:#f1f5f9;border:1px solid #e2e8f0;}
.var-box-icon{font-size:20px;}.var-box.short .var-box-icon{color:var(--red);}.var-box.excess .var-box-icon{color:var(--green);}.var-box.balanced .var-box-icon{color:var(--txs);}
.var-box-info{flex:1;}.var-box-title{font-size:12px;font-weight:700;}
.var-box.short .var-box-title{color:var(--red);}.var-box.excess .var-box-title{color:var(--green);}.var-box.balanced .var-box-title{color:var(--txm);}
.var-box-detail{font-size:11px;color:var(--txm);margin-top:2px;}
.var-box-val{font-family:var(--mn);font-size:18px;font-weight:800;}
.var-box.short .var-box-val{color:var(--red);}.var-box.excess .var-box-val{color:var(--green);}.var-box.balanced .var-box-val{color:var(--txm);}
.totals-strip{display:grid;grid-template-columns:1fr 1fr 1fr;gap:0;border:1px solid var(--bdr);border-radius:8px;overflow:hidden;margin-bottom:14px;}
.ts-cell{padding:10px 14px;text-align:center;}.ts-cell.coll{background:var(--green-lt);border-right:1px solid var(--bdr);}
.ts-cell.dep{background:var(--blue-lt);border-right:1px solid var(--bdr);}.ts-cell.diff{background:var(--amber-lt);}
.ts-cell.diff.short-bg{background:var(--red-lt);}.ts-cell.diff.excess-bg{background:var(--green-lt);}
.ts-lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;}
.ts-cell.coll .ts-lbl{color:var(--green);}.ts-cell.dep .ts-lbl{color:var(--blue);}
.ts-cell.diff .ts-lbl{color:var(--amber);}.ts-cell.diff.short-bg .ts-lbl{color:var(--red);}.ts-cell.diff.excess-bg .ts-lbl{color:var(--green);}
.ts-val{font-family:var(--mn);font-size:15px;font-weight:800;}
.ts-cell.coll .ts-val{color:var(--green);}.ts-cell.dep .ts-val{color:var(--blue);}
.ts-cell.diff .ts-val{color:var(--amber);}.ts-cell.diff.short-bg .ts-val{color:var(--red);}.ts-cell.diff.excess-bg .ts-val{color:var(--green);}
.sec-panel{border:1px solid var(--bdrs);border-radius:8px;overflow:hidden;margin-bottom:14px;}
.sec-panel-hd{display:flex;justify-content:space-between;align-items:center;padding:8px 14px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid;}
.sec-panel-hd.cc{background:var(--green-lt);color:#166534;border-color:var(--green-md);}
.sec-panel-hd.sr{background:var(--teal-lt);color:var(--teal);border-color:var(--teal-md);}
.sec-panel-hd.bank{background:var(--blue-lt);color:#1e40af;border-color:var(--blue-md);}
.sec-panel-hd.bo{background:var(--purple-lt);color:var(--purple);border-color:var(--purple-md);}
.sec-panel-hd.alloc{background:#faf5ff;color:var(--purple);border-color:var(--purple-md);}
.sec-total{font-family:var(--mn);font-size:13px;font-weight:800;letter-spacing:0;text-transform:none;}
.sec-count{background:rgba(255,255,255,.6);padding:1px 7px;border-radius:10px;font-size:9px;font-weight:700;margin-left:6px;}
.sec-empty{padding:14px;text-align:center;font-size:11px;color:var(--txs);font-style:italic;}
table.ptbl,table.dtbl{width:100%;border-collapse:collapse;font-size:11px;}
.ptbl thead th,.dtbl thead th{padding:5px 8px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;text-align:left;white-space:nowrap;border-bottom:1px solid var(--bdrs);background:#fafbfc;color:var(--txm);}
.ptbl thead th.ar,.dtbl thead th.ar{text-align:right;}.ptbl thead th.ac{text-align:center;}
.ptbl tbody td,.dtbl tbody td{padding:5px 8px;white-space:nowrap;vertical-align:middle;border-bottom:1px solid #f3f4f6;}
.ptbl tbody tr:last-child td,.dtbl tbody tr:last-child td{border-bottom:none;}
.ptbl tbody tr:nth-child(even) td,.dtbl tbody tr:nth-child(even) td{background:#fafcff;}
.ptbl tbody td.ar,.dtbl tbody td.ar{text-align:right;}.ptbl tbody td.ac{text-align:center;}
.ptbl tbody td.mn,.dtbl tbody td.mn{font-family:var(--mn);font-size:10px;}
.ptbl tbody td.fw,.dtbl tbody td.fw{font-weight:700;}
.ptbl tbody td.muted,.dtbl tbody td.muted{color:var(--txm);font-size:10px;}
.ptbl tfoot td,.dtbl tfoot td{padding:5px 8px;font-weight:800;font-size:11px;border-top:2px solid var(--bdr);white-space:nowrap;font-family:var(--mn);}
.ptbl tfoot td.ar,.dtbl tfoot td.ar{text-align:right;}
.ptbl tfoot td.tl,.dtbl tfoot td.tl{text-align:left;color:var(--txm);font-family:var(--fn);font-size:10px;}
.alloc-row{display:flex;align-items:center;justify-content:space-between;padding:6px 14px;border-bottom:1px solid #f3f4f6;}
.alloc-row:last-child{border-bottom:none;}.alloc-row.highlight{background:var(--red-lt);}
.alloc-row .emp-lbl{font-size:12px;font-weight:600;}.alloc-row.highlight .emp-lbl{color:var(--red);font-weight:700;}
.alloc-row .emp-amt{font-family:var(--mn);font-size:12px;font-weight:700;color:var(--purple);}
.alloc-row.highlight .emp-amt{color:var(--red);}
.absorb-row{display:flex;align-items:center;justify-content:space-between;padding:6px 14px;background:var(--blue-lt);border-top:1px solid var(--blue-md);}
.absorb-row .lbl{font-size:11px;font-weight:600;color:var(--blue);}.absorb-row .val{font-family:var(--mn);font-size:12px;font-weight:700;color:var(--blue);}
.link-bar{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;padding-top:14px;border-top:1px solid var(--bdrs);}
.detail-link{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:7px;font-size:11px;font-weight:600;text-decoration:none;transition:all .15s;border:1px solid;}
.detail-link.cash{background:#f0fdf4;color:#166534;border-color:#86efac;}.detail-link.cash:hover{background:#dcfce7;}
.detail-link.teal{background:var(--teal-lt);color:var(--teal);border-color:var(--teal-md);}.detail-link.teal:hover{background:#b2f5ea;}
.detail-link.deposit{background:var(--blue-lt);color:#1e40af;border-color:var(--blue-md);}.detail-link.deposit:hover{background:#c7d8fe;}
.detail-link.collection{background:var(--purple-lt);color:var(--purple);border-color:var(--purple-md);}.detail-link.collection:hover{background:#e0d5fe;}
.detail-link i{font-size:10px;}
.summary-bar{background:#0f172a;border-radius:var(--r);padding:16px 22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-top:10px;}
.sum-info{color:#94a3b8;font-size:12px;font-weight:600;display:flex;align-items:center;gap:7px;flex-wrap:wrap;}
.sum-cols{display:flex;gap:20px;flex-wrap:wrap;align-items:center;}
.sum-col{text-align:right;}.sum-col-lbl{font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px;}
.sum-col-val{font-size:15px;font-weight:800;font-family:var(--mn);}
.sum-col.red .sum-col-lbl{color:#fca5a5;}.sum-col.red .sum-col-val{color:#fca5a5;}
.sum-col.green .sum-col-lbl{color:#86efac;}.sum-col.green .sum-col-val{color:#86efac;}
.sum-col.amber .sum-col-lbl{color:#fcd34d;}.sum-col.amber .sum-col-val{color:#fcd34d;}
.sum-col.purple .sum-col-lbl{color:#c4b5fd;}.sum-col.purple .sum-col-val{color:#c4b5fd;}
.sum-col.total{padding-left:18px;border-left:1px solid #334155;}
.sum-col.total .sum-col-lbl{color:#67e8f9;}.sum-col.total .sum-col-val{font-size:18px;color:#67e8f9;}
.empty{text-align:center;padding:60px 20px;color:var(--txs);}.empty i{font-size:44px;display:block;margin-bottom:12px;opacity:.2;}
@media(max-width:900px){.stat-row{grid-template-columns:repeat(3,1fr);}.totals-strip{grid-template-columns:1fr;}}
@media(max-width:600px){.stat-row{grid-template-columns:1fr 1fr;}}
@media print{
    body{background:#fff;font-size:10px;}.no-print{display:none!important;}
    .print-header{display:block;text-align:center;padding:10px 0 8px;border-bottom:2px solid var(--red);margin-bottom:12px;}
    .print-header h2{font-size:16px;font-weight:800;margin-bottom:3px;}.print-header p{font-size:11px;color:#555;}
    .pg{padding:8px 8px 20px;}.detail-card,.stat,.emp-banner{box-shadow:none;break-inside:avoid;}
    .link-bar{display:none!important;}.summary-bar{display:none;}
    .ptbl,.dtbl{font-size:9px;}.ptbl thead th,.dtbl thead th{padding:3px 5px;font-size:8px;}
    .sec-panel-hd,.var-box,.totals-strip .ts-cell,.alloc-row.highlight{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}
</style>

<div class="pg">
<div class="print-header">
    <h2>Cash Shortage Detail — <?php echo htmlspecialchars($emp_name); ?></h2>
    <p><?php echo $date_display; ?><?php if($f_sr):?> · Rep: <?php echo htmlspecialchars($f_sr);?><?php endif;?> · Generated: <?php echo date('d M Y H:i');?></p>
</div>

<div class="topbar no-print">
    <div class="pg-brand">
        <div class="pg-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
        <div>
            <div class="pg-h1">Cash Shortage <em>Detail</em></div>
            <div class="pg-sub">
                <span class="chip red"><i class="fa-solid fa-user"></i> <?php echo htmlspecialchars($emp_name); ?></span>
                <span class="chip"><i class="fa-solid fa-calendar-days"></i> <?php echo $date_display; ?></span>
                <?php if ($f_sr): ?><span class="chip"><i class="fa-solid fa-id-badge"></i> <?php echo htmlspecialchars($f_sr); ?></span><?php endif; ?>
                <span class="chip"><?php echo count($details); ?> record<?php echo count($details)!=1?'s':''; ?></span>
            </div>
        </div>
    </div>
    <div class="topbtns">
        <?php $back_params = http_build_query(array_filter(['date_from'=>$date_from,'date_to'=>$date_to,'sr_code'=>$f_sr])); ?>
        <a href="cash_shortage_employee_report.php?<?php echo $back_params; ?>" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Report</a>
        <?php if (!empty($details)): ?>
        <button onclick="window.print()" class="btn-print"><i class="fa-solid fa-print"></i> Print</button>
        <?php endif; ?>
    </div>
</div>

<div class="emp-banner">
    <div class="emp-avatar"><i class="fa-solid fa-user"></i></div>
    <div class="emp-info">
        <div class="emp-info-name"><?php echo htmlspecialchars($emp_name); ?></div>
        <div class="emp-info-code"><?php echo htmlspecialchars($emp_code); ?> · ID: <?php echo $employee_id; ?></div>
    </div>
    <div style="text-align:right;">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--txs);letter-spacing:.06em;margin-bottom:4px;">Net Charged</div>
        <div style="font-family:var(--mn);font-size:22px;font-weight:800;color:<?php echo $net_charged>0?'var(--red)':($net_charged<0?'var(--green)':'var(--txm)');?>;">
            Rs. <?php echo number_format(abs($net_charged),2); ?>
            <?php if($net_charged>0):?><span style="font-size:11px;font-weight:600;"> (Short)</span>
            <?php elseif($net_charged<0):?><span style="font-size:11px;font-weight:600;"> (Excess)</span>
            <?php else:?><span style="font-size:11px;font-weight:600;"> (Balanced)</span><?php endif;?>
        </div>
    </div>
</div>

<?php if (empty($details)): ?>
<div class="detail-card"><div class="empty"><i class="fa-solid fa-inbox"></i>
    <p style="font-size:14px;font-weight:600;margin-bottom:6px;">No charge records found</p>
    <p><?php echo htmlspecialchars($emp_name).' · '.$date_display; ?></p>
</div></div>
<?php else: ?>

<div class="stat-row no-print">
    <div class="stat red"><div class="stat-lbl"><i class="fa-solid fa-circle-arrow-down"></i> Shortage Charged</div><div class="stat-val">Rs. <?php echo number_format($tot_short,2); ?></div><div class="stat-sub">Days with shortage</div></div>
    <div class="stat grn"><div class="stat-lbl"><i class="fa-solid fa-circle-arrow-up"></i> Excess Charged</div><div class="stat-val">Rs. <?php echo number_format($tot_excess,2); ?></div><div class="stat-sub">Days with excess</div></div>
    <div class="stat amb"><div class="stat-lbl"><i class="fa-solid fa-scale-balanced"></i> Net Charged</div><div class="stat-val">Rs. <?php echo number_format(abs($net_charged),2); ?></div><div class="stat-sub">Shortage − Excess</div></div>
    <div class="stat prp"><div class="stat-lbl"><i class="fa-solid fa-wallet"></i> Payroll Charged</div><div class="stat-val">Rs. <?php echo number_format($total_payroll,2); ?></div><div class="stat-sub">Deducted via payroll</div></div>
    <div class="stat cyn"><div class="stat-lbl"><i class="fa-solid fa-receipt"></i> Balance</div><div class="stat-val">Rs. <?php echo number_format(abs($balance),2); ?></div><div class="stat-sub"><?php echo $balance<=0?'Fully settled':'Outstanding';?></div></div>
    <div class="stat blu"><div class="stat-lbl"><i class="fa-solid fa-calendar-check"></i> Charge Days</div><div class="stat-val"><?php echo count($details); ?></div><div class="stat-sub"><?php echo $date_display; ?></div></div>
</div>

<div class="calc-box">
    <strong><i class="fa-solid fa-calculator"></i> How Net Charged is calculated:</strong>
    <div class="formula">Net = Shortage (<?php echo number_format($tot_short,2); ?>) − Excess (<?php echo number_format($tot_excess,2); ?>) = <strong>Rs. <?php echo number_format(abs($net_charged),2); ?></strong><?php echo $net_charged>0?' (Short)':($net_charged<0?' (Excess)':' (Balanced)');?></div>
    <div class="formula" style="margin-top:4px;">Balance = Net (<?php echo number_format(abs($net_charged),2); ?>) − Payroll (<?php echo number_format($total_payroll,2); ?>) = <strong>Rs. <?php echo number_format(abs($balance),2); ?></strong><?php echo $balance<=0.005?' ✓ Settled':' ⚠ Outstanding';?></div>
</div>

<?php foreach ($details as $idx => $d):
    $var_class = ($d['variance'] > 0.005) ? 'short' : (($d['variance'] < -0.005) ? 'excess' : 'balanced');
    $var_label = ($d['variance'] > 0.005) ? 'Cash Shortage' : (($d['variance'] < -0.005) ? 'Cash Excess' : 'Balanced');
    $var_icon  = ($d['variance'] > 0.005) ? 'fa-arrow-trend-down' : (($d['variance'] < -0.005) ? 'fa-arrow-trend-up' : 'fa-check-circle');
    $diff_cls  = ($d['variance'] > 0.005) ? 'short-bg' : (($d['variance'] < -0.005) ? 'excess-bg' : '');
?>

<div class="detail-card">
    <div class="dc-header">
        <div class="dc-header-left">
            <span class="dc-idx"># <?php echo $idx+1; ?></span>
            <span class="dc-date"><i class="fa-solid fa-calendar-day"></i> <?php echo date('d M Y', strtotime($d['pay_date'])); ?></span>
            <span class="dc-sr"><i class="fa-solid fa-id-badge"></i> <?php echo htmlspecialchars($d['sr_code']); ?></span>
        </div>
        <div class="dc-charge-val"><i class="fa-solid fa-user-minus" style="font-size:12px;"></i> Charged: Rs. <?php echo number_format($d['this_charge'],2); ?></div>
    </div>
    <div class="dc-body">

        <div class="var-box <?php echo $var_class; ?>">
            <div class="var-box-icon"><i class="fa-solid <?php echo $var_icon; ?>"></i></div>
            <div class="var-box-info">
                <div class="var-box-title"><?php echo $var_label; ?></div>
                <div class="var-box-detail">Collected: Rs. <?php echo number_format($d['total_coll'],2); ?> − Deposited: Rs. <?php echo number_format($d['total_dep'],2); ?></div>
            </div>
            <div class="var-box-val">Rs. <?php echo number_format(abs($d['variance']),2); ?></div>
        </div>

        <div class="totals-strip">
            <div class="ts-cell coll"><div class="ts-lbl"><i class="fa-solid fa-coins"></i> Total Collected</div><div class="ts-val">Rs. <?php echo number_format($d['total_coll'],2); ?></div></div>
            <div class="ts-cell dep"><div class="ts-lbl"><i class="fa-solid fa-building-columns"></i> Total Deposited</div><div class="ts-val">Rs. <?php echo number_format($d['total_dep'],2); ?></div></div>
            <div class="ts-cell diff <?php echo $diff_cls; ?>"><div class="ts-lbl"><i class="fa-solid fa-triangle-exclamation"></i> Variance</div><div class="ts-val"><?php echo ($d['variance']>0?'▼ ':'▲ '); ?>Rs. <?php echo number_format(abs($d['variance']),2); ?></div></div>
        </div>

        <!-- CC COLLECTION -->
        <div class="sec-panel">
            <div class="sec-panel-hd cc">
                <span><i class="fa-solid fa-user-tie"></i> CC Cash Collection <span class="sec-count"><?php echo count($d['cc_payments']); ?></span></span>
                <span class="sec-total">Rs. <?php echo number_format($d['cc_total'],2); ?></span>
            </div>
            <div class="sec-panel-body">
                <?php if (empty($d['cc_payments'])): ?><div class="sec-empty">No CC cash payments for this date.</div>
                <?php else: ?><div style="overflow-x:auto;"><table class="ptbl"><thead><tr>
                    <th style="width:28px;">#</th><th>Invoice</th><th>T-Code</th><th>Customer</th><th>Route</th>
                    <th class="ar">Inv. Value</th><th class="ar" style="min-width:90px;">Paid Amount</th>
                    <th class="ac">Source</th><th>Reference</th><th>Remarks</th><th>Time</th>
                </tr></thead><tbody>
                <?php foreach ($d['cc_payments'] as $pi => $p): ?>
                <tr>
                    <td class="muted"><?php echo $pi+1; ?></td>
                    <td class="mn fw"><?php echo htmlspecialchars($p['invoice_num'] ?? '—'); ?></td>
                    <td class="mn"><?php echo htmlspecialchars($p['t_code'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($p['customer_name'] ?? '—'); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($p['route'] ?? '—'); ?></td>
                    <td class="ar mn"><?php $iv=floatval($p['invoice_value']??0); echo $iv?number_format($iv,2):'—'; ?></td>
                    <td class="ar mn fw" style="color:#166534;"><?php echo number_format(floatval($p['amount']),2); ?></td>
                    <td class="ac"><?php echo sd_source($p['payment_source']); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($p['reference_no'] ?? '') ?: '—'; ?></td>
                    <td class="muted"><?php echo htmlspecialchars($p['remarks'] ?? '') ?: '—'; ?></td>
                    <td class="muted mn" style="font-size:9px;"><?php echo $p['created_at']?date('H:i',strtotime($p['created_at'])):'—'; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody><tfoot><tr>
                    <td class="tl" colspan="6">Total — <?php echo count($d['cc_payments']); ?> payments (CC)</td>
                    <td class="ar" style="color:#166534;">Rs. <?php echo number_format($d['cc_total'],2); ?></td>
                    <td colspan="4"></td>
                </tr></tfoot></table></div><?php endif; ?>
            </div>
        </div>

        <!-- SR COLLECTION -->
        <div class="sec-panel">
            <div class="sec-panel-hd sr">
                <span><i class="fa-solid fa-person-walking"></i> SR Cash Collection <span class="sec-count"><?php echo count($d['sr_payments']); ?></span></span>
                <span class="sec-total">Rs. <?php echo number_format($d['sr_total'],2); ?></span>
            </div>
            <div class="sec-panel-body">
                <?php if (empty($d['sr_payments'])): ?><div class="sec-empty">No SR cash payments for this date.</div>
                <?php else: ?><div style="overflow-x:auto;"><table class="ptbl"><thead><tr>
                    <th style="width:28px;">#</th><th>Invoice</th><th>T-Code</th><th>Customer</th><th>Route</th>
                    <th class="ar">Inv. Value</th><th class="ar" style="min-width:90px;">Paid Amount</th>
                    <th class="ac">Source</th><th>Reference</th><th>Remarks</th><th>Time</th>
                </tr></thead><tbody>
                <?php foreach ($d['sr_payments'] as $pi => $p): ?>
                <tr>
                    <td class="muted"><?php echo $pi+1; ?></td>
                    <td class="mn fw"><?php echo htmlspecialchars($p['invoice_num'] ?? '—'); ?></td>
                    <td class="mn"><?php echo htmlspecialchars($p['t_code'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($p['customer_name'] ?? '—'); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($p['route'] ?? '—'); ?></td>
                    <td class="ar mn"><?php $iv=floatval($p['invoice_value']??0); echo $iv?number_format($iv,2):'—'; ?></td>
                    <td class="ar mn fw" style="color:var(--teal);"><?php echo number_format(floatval($p['amount']),2); ?></td>
                    <td class="ac"><?php echo sd_source($p['payment_source']); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($p['reference_no'] ?? '') ?: '—'; ?></td>
                    <td class="muted"><?php echo htmlspecialchars($p['remarks'] ?? '') ?: '—'; ?></td>
                    <td class="muted mn" style="font-size:9px;"><?php echo $p['created_at']?date('H:i',strtotime($p['created_at'])):'—'; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody><tfoot><tr>
                    <td class="tl" colspan="6">Total — <?php echo count($d['sr_payments']); ?> payments (SR)</td>
                    <td class="ar" style="color:var(--teal);">Rs. <?php echo number_format($d['sr_total'],2); ?></td>
                    <td colspan="4"></td>
                </tr></tfoot></table></div><?php endif; ?>
            </div>
        </div>

        <!-- BANK DEPOSITS -->
        <div class="sec-panel">
            <div class="sec-panel-hd bank">
                <span><i class="fa-solid fa-building-columns"></i> Bank Deposits <span class="sec-count"><?php echo count($d['bank_deposits']); ?></span></span>
                <span class="sec-total">Rs. <?php echo number_format($d['banked_cc']+$d['banked_sr'],2); ?></span>
            </div>
            <div class="sec-panel-body">
                <?php if (empty($d['bank_deposits'])): ?><div class="sec-empty">No bank deposits for this date.</div>
                <?php else: ?><div style="overflow-x:auto;"><table class="dtbl"><thead><tr>
                    <th style="width:28px;">#</th><th>Dep. ID</th><th>Deposit Date</th><th>Collected By</th>
                    <th>Bank / Account</th><th class="ar" style="min-width:100px;">Amount</th><th>Remark</th>
                </tr></thead><tbody>
                <?php foreach ($d['bank_deposits'] as $di => $dp): ?>
                <tr>
                    <td class="muted"><?php echo $di+1; ?></td>
                    <td class="mn fw">#<?php echo intval($dp['deposit_id']); ?></td>
                    <td class="mn"><?php echo $dp['deposit_date']?date('d M Y',strtotime($dp['deposit_date'])):'—'; ?></td>
                    <td><?php echo sd_coll($dp['dep_collected_by']); ?></td>
                    <td class="muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars(trim($dp['bank_label']??'',"\t\n\r/ "))?:'—'; ?></td>
                    <td class="ar mn fw" style="color:#1e40af;"><?php echo number_format(floatval($dp['rep_amount']),2); ?></td>
                    <td class="muted" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($dp['remark']??'')?:'—'; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody><tfoot><tr>
                    <td class="tl" colspan="5">Total — <?php echo count($d['bank_deposits']); ?> bank deposits</td>
                    <td class="ar" style="color:#1e40af;">Rs. <?php echo number_format($d['banked_cc']+$d['banked_sr'],2); ?></td>
                    <td></td>
                </tr></tfoot></table></div><?php endif; ?>
            </div>
        </div>

        <!-- HANDED TO BO -->
        <div class="sec-panel">
            <div class="sec-panel-hd bo">
                <span><i class="fa-solid fa-hand-holding-dollar"></i> Handed to BO <span class="sec-count"><?php echo count($d['bo_deposits']); ?></span></span>
                <span class="sec-total">Rs. <?php echo number_format($d['handed_cc']+$d['handed_sr'],2); ?></span>
            </div>
            <div class="sec-panel-body">
                <?php if (empty($d['bo_deposits'])): ?><div class="sec-empty">No BO handovers for this date.</div>
                <?php else: ?><div style="overflow-x:auto;"><table class="dtbl"><thead><tr>
                    <th style="width:28px;">#</th><th>Dep. ID</th><th>Cash Recv. Date</th><th>Collected By</th>
                    <th>Handed To</th><th class="ar" style="min-width:100px;">Amount</th><th>Remark</th>
                </tr></thead><tbody>
                <?php foreach ($d['bo_deposits'] as $di => $dp): ?>
                <tr>
                    <td class="muted"><?php echo $di+1; ?></td>
                    <td class="mn fw">#<?php echo intval($dp['deposit_id']); ?></td>
                    <td class="mn"><?php echo $dp['cash_receive_date']?date('d M Y',strtotime($dp['cash_receive_date'])):'—'; ?></td>
                    <td><?php echo sd_coll($dp['dep_collected_by']); ?></td>
                    <td><?php echo htmlspecialchars($dp['deposited_by']??'—'); ?></td>
                    <td class="ar mn fw" style="color:var(--purple);"><?php echo number_format(floatval($dp['rep_amount']),2); ?></td>
                    <td class="muted" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($dp['remark']??'')?:'—'; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody><tfoot><tr>
                    <td class="tl" colspan="5">Total — <?php echo count($d['bo_deposits']); ?> BO handovers</td>
                    <td class="ar" style="color:var(--purple);">Rs. <?php echo number_format($d['handed_cc']+$d['handed_sr'],2); ?></td>
                    <td></td>
                </tr></tfoot></table></div><?php endif; ?>
            </div>
        </div>

        <!-- PAY ALLOCATION -->
        <div class="sec-panel">
            <div class="sec-panel-hd alloc">
                <span><i class="fa-solid fa-users"></i> Pay Allocation — Employees Charged</span>
                <span class="sec-total">Rs. <?php echo number_format($d['total_emp_charge'],2); ?></span>
            </div>
            <div class="sec-panel-body" style="padding:0;">
                <?php if (!empty($d['all_charges'])):
                    foreach ($d['all_charges'] as $ch):
                        $is_this = (intval($ch['employee_id']) === $employee_id); ?>
                <div class="alloc-row <?php echo $is_this?'highlight':''; ?>">
                    <span class="emp-lbl"><?php if($is_this):?><i class="fa-solid fa-arrow-right" style="font-size:9px;"></i> <?php endif;?><?php echo htmlspecialchars($ch['employee_name']); ?><?php if($is_this):?><span style="font-size:9px;font-weight:800;"> (THIS EMPLOYEE)</span><?php endif;?></span>
                    <span class="emp-amt">Rs. <?php echo number_format(floatval($ch['amount']),2); ?></span>
                </div>
                <?php endforeach; endif; ?>
                <?php if (($d['absorb']??0) > 0): ?>
                <div class="absorb-row">
                    <span class="lbl"><i class="fa-solid fa-building"></i> Company Absorbed</span>
                    <span class="val">Rs. <?php echo number_format($d['absorb'],2); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="link-bar no-print">
            <a href="payment_details.php?sr_code=<?php echo urlencode($d['sr_code']); ?>&date=<?php echo urlencode($d['pay_date']); ?>&method=cash&collected_by=cc" target="_blank" class="detail-link cash"><i class="fa-solid fa-user-tie"></i> CC Cash Details <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
            <a href="payment_details.php?sr_code=<?php echo urlencode($d['sr_code']); ?>&date=<?php echo urlencode($d['pay_date']); ?>&method=cash&collected_by=sr" target="_blank" class="detail-link teal"><i class="fa-solid fa-person-walking"></i> SR Cash Details <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
            <a href="cc_deposit_history.php?sr_code=<?php echo urlencode($d['sr_code']); ?>&date=<?php echo urlencode($d['pay_date']); ?>" target="_blank" class="detail-link deposit"><i class="fa-solid fa-building-columns"></i> Deposit History <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
            <a href="cash_collection.php?search=1&date_from=<?php echo urlencode($d['pay_date']); ?>&date_to=<?php echo urlencode($d['pay_date']); ?>&sr_code=<?php echo urlencode($d['sr_code']); ?>" target="_blank" class="detail-link collection"><i class="fa-solid fa-table-cells-large"></i> Full Cash Collection <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="summary-bar no-print">
    <div class="sum-info">
        <i class="fa-solid fa-sigma" style="color:#67e8f9;"></i>
        <strong style="color:#e0e7ff;"><?php echo htmlspecialchars($emp_name); ?></strong>
        · <?php echo count($details); ?> charge day<?php echo count($details)!=1?'s':''; ?>
        · <span style="font-family:var(--mn);color:#e0e7ff;"><?php echo $date_display; ?></span>
    </div>
    <div class="sum-cols">
        <div class="sum-col red"><div class="sum-col-lbl"><i class="fa-solid fa-arrow-down"></i> Shortage</div><div class="sum-col-val">Rs. <?php echo number_format($tot_short,2); ?></div></div>
        <div class="sum-col green"><div class="sum-col-lbl"><i class="fa-solid fa-arrow-up"></i> Excess</div><div class="sum-col-val">Rs. <?php echo number_format($tot_excess,2); ?></div></div>
        <div class="sum-col amber"><div class="sum-col-lbl"><i class="fa-solid fa-scale-balanced"></i> Net</div><div class="sum-col-val">Rs. <?php echo number_format(abs($net_charged),2); ?></div></div>
        <div class="sum-col purple"><div class="sum-col-lbl"><i class="fa-solid fa-wallet"></i> Payroll</div><div class="sum-col-val">Rs. <?php echo number_format($total_payroll,2); ?></div></div>
        <div class="sum-col total"><div class="sum-col-lbl"><i class="fa-solid fa-receipt"></i> Balance</div><div class="sum-col-val">Rs. <?php echo number_format(abs($balance),2); ?><?php echo $balance<=0?' ✓':''; ?></div></div>
    </div>
</div>
<?php endif; ?>
</div>
<?php include 'footer.php'; ?>