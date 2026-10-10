<?php
include 'config.php';
include 'header.php';

/* ═══════════════════════════════════════════════════════════
   FILTERS
═══════════════════════════════════════════════════════════ */
$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$f_sr      = trim($_GET['sr_code'] ?? '');
$submitted = isset($_GET['search']);

$df     = mysqli_real_escape_string($conn, $date_from);
$dt     = mysqli_real_escape_string($conn, $date_to);
$sr_esc = $f_sr ? mysqli_real_escape_string($conn, $f_sr) : '';

/* ── ensure pay-alloc table exists ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cash_summary_pay_allocations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pay_date DATE NOT NULL, sr_code VARCHAR(50) NOT NULL,
    entry_type VARCHAR(20) NOT NULL, employee_id INT NULL,
    employee_name VARCHAR(200) NULL, amount DECIMAL(12,2) DEFAULT 0.00,
    total_value DECIMAL(12,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date_sr (pay_date, sr_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── SR dropdown ── */
$sr_res = mysqli_query($conn, "SELECT DISTINCT sr_code FROM field_summary WHERE sr_code IS NOT NULL AND sr_code != '' ORDER BY sr_code");
$all_sr = [];
if ($sr_res) while ($r = mysqli_fetch_assoc($sr_res)) $all_sr[] = $r['sr_code'];

/* ── Rep names ── */
$nm_res = mysqli_query($conn, "SELECT employee_id, employee_full_name FROM employees WHERE active = 1");
$names = [];
if ($nm_res) while ($r = mysqli_fetch_assoc($nm_res)) $names[$r['employee_id']] = $r['employee_full_name'];

/* ── Employee list for pay modal ── */
$emp_list = [];
$er = mysqli_query($conn,
    "SELECT id, COALESCE(NULLIF(employee_id,''),CONCAT('EMP-',id)) AS emp_code,
     COALESCE(NULLIF(name_with_initials,''),NULLIF(employee_full_name,''),CONCAT('Employee #',id)) AS emp_name
     FROM employees
     WHERE COALESCE(status,'') NOT IN ('Resigned','Terminated','Inactive','inactive','resigned','terminated')
     ORDER BY emp_name ASC");
if (!$er || mysqli_num_rows($er)===0)
    $er = mysqli_query($conn, "SELECT id,
     COALESCE(NULLIF(employee_id,''),CONCAT('EMP-',id)) AS emp_code,
     COALESCE(NULLIF(name_with_initials,''),NULLIF(employee_full_name,''),CONCAT('Employee #',id)) AS emp_name
     FROM employees ORDER BY emp_name ASC LIMIT 500");
if ($er) while ($row = mysqli_fetch_assoc($er)) $emp_list[] = $row;

/* ═══════════════════════════════════════════════════════════
   DATA
═══════════════════════════════════════════════════════════ */
$rows   = [];
$totals = array_fill_keys([
    'loading','total_adj','final_bill','sinv','canceled_value','inv_diff',
    'cash_paid','cheque_paid','credit','pay_diff',
    /* CC split */
    'cc_daily_sale','cc_rcvd_credit','cc_rcvd_rtn_chq','cc_rcvd_rtn_chgs','cc_rcvd_sent_back','cc_total',
    /* SR split */
    'sr_daily_sale','sr_rcvd_credit','sr_rcvd_rtn_chq','sr_rcvd_rtn_chgs','sr_rcvd_sent_back','sr_total',
    /* Grand total */
    'total_coll',
    'banked','handed','bank_diff',
    'cash_short','good_short','p_charge','p_absorb'
], 0.0);

if ($submitted) {

    $where_fs = "fs.delivery_date BETWEEN '$df' AND '$dt'"
              . ($sr_esc ? " AND fs.sr_code='$sr_esc'" : '');
    $lsi_w    = "lsi.delivery_date BETWEEN '$df' AND '$dt' AND lsi.status='imported'"
              . ($sr_esc ? " AND lsi.sales_person_code='$sr_esc'" : '');
    $sid_w    = "sid.delivery_date BETWEEN '$df' AND '$dt' AND sid.status='imported'"
              . ($sr_esc ? " AND sid.sales_person_code='$sr_esc'" : '');
    $srCnd    = $sr_esc ? "AND fs.sr_code='$sr_esc'" : '';
    $dep_w    = "delivery_date BETWEEN '$df' AND '$dt'"
              . ($sr_esc ? " AND rep_code='$sr_esc'" : '');

    /* ── Q1: master list ── */
    $r1 = mysqli_query($conn, "
        SELECT DISTINCT fs.sr_code, fs.delivery_date
        FROM field_summary fs
        WHERE $where_fs AND fs.sr_code IS NOT NULL AND fs.sr_code!=''
        ORDER BY fs.delivery_date, fs.sr_code");
    $master = [];
    if ($r1) while ($r = mysqli_fetch_assoc($r1))
        $master[] = $r['delivery_date'].'|'.$r['sr_code'];

    /* ── Q2: loading ── */
    $r2 = mysqli_query($conn, "
        SELECT lsi.sales_person_code AS sr_code, lsi.delivery_date,
            COALESCE(SUM(lsi.final_bill_amount),0) AS loading
        FROM loading_summary_import_details lsi
        WHERE $lsi_w
        GROUP BY lsi.sales_person_code, lsi.delivery_date");
    $load_map = [];
    if ($r2) while ($r = mysqli_fetch_assoc($r2))
        $load_map[$r['delivery_date'].'|'.$r['sr_code']] = $r;

    /* ── Q2b: total_adjustment ── */
    $r2b = mysqli_query($conn, "
        SELECT lsi.sales_person_code AS sr_code, lsi.delivery_date,
            COALESCE(SUM(
                COALESCE(lsi.scheme_disc,0)+COALESCE(lsi.rs_discount,0)
               +COALESCE(lsi.tot_disc,0)+COALESCE(lsi.total_discount,0)
               +COALESCE(lsi.good_returns_value,0)
               +COALESCE(lsi.damage_expiry_shortage_value,0)
            ),0) AS total_adj
        FROM loading_summary_import_details lsi
        WHERE $lsi_w
        GROUP BY lsi.sales_person_code, lsi.delivery_date");
    $adj_map = [];
    if ($r2b) while ($r = mysqli_fetch_assoc($r2b))
        $adj_map[$r['delivery_date'].'|'.$r['sr_code']] = $r;

    /* ── Q2c: final_bill from fsd ── */
    $r2c = mysqli_query($conn, "
        SELECT fs.sr_code, fs.delivery_date,
            COALESCE(SUM(fsd.adjust_net_value),0) AS final_bill
        FROM field_summary_details fsd
        INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
        WHERE $where_fs
        GROUP BY fs.sr_code, fs.delivery_date");
    $bill_map = [];
    if ($r2c) while ($r = mysqli_fetch_assoc($r2c))
        $bill_map[$r['delivery_date'].'|'.$r['sr_code']] = floatval($r['final_bill']);

    /* ── Q3: secondary invoice ── */
    $r3 = mysqli_query($conn, "
        SELECT sid.sales_person_code AS sr_code, sid.delivery_date,
            COALESCE(SUM(sid.final_bill_amount),0) AS sinv
        FROM secondary_invoice_import_details sid
        WHERE $sid_w
        GROUP BY sid.sales_person_code, sid.delivery_date");
    $sinv_map = [];
    if ($r3) while ($r = mysqli_fetch_assoc($r3))
        $sinv_map[$r['delivery_date'].'|'.$r['sr_code']] = floatval($r['sinv']);

    /* ── Q3b: canceled value ── */
    $r3b = mysqli_query($conn, "
        SELECT lsi.sales_person_code AS sr_code, lsi.delivery_date,
            COALESCE(SUM(lsi.final_bill_amount),0) AS canceled_value
        FROM loading_summary_import_details lsi
        WHERE $lsi_w
          AND lsi.bill_no NOT IN (
              SELECT sid.bill_no
              FROM secondary_invoice_import_details sid
              WHERE sid.delivery_date = lsi.delivery_date
                AND sid.sales_person_code = lsi.sales_person_code
                AND sid.status = 'imported'
          )
        GROUP BY lsi.sales_person_code, lsi.delivery_date");
    $cancel_map = [];
    if ($r3b) while ($r = mysqli_fetch_assoc($r3b))
        $cancel_map[$r['delivery_date'].'|'.$r['sr_code']] = floatval($r['canceled_value']);

    /* ── Q4: same-day cash + cheque ── */
    $r4 = mysqli_query($conn, "
        SELECT fs.delivery_date, fs.sr_code,
            COALESCE(SUM(CASE WHEN ip.payment_method='cash'
                               AND ip.payment_date=fs.delivery_date
                               AND ip.is_reversed=0
                          THEN ip.amount ELSE 0 END),0) AS cash_paid,
            COALESCE(SUM(CASE WHEN ip.payment_method='cheque'
                               AND ip.payment_date=fs.delivery_date
                               AND ip.is_reversed=0
                          THEN ip.amount ELSE 0 END),0) AS cheque_paid
        FROM invoice_payments ip
        INNER JOIN field_summary fs ON fs.id = ip.field_summary_id
        WHERE $where_fs
        GROUP BY fs.delivery_date, fs.sr_code");
    $invpay_map = [];
    if ($r4) while ($r = mysqli_fetch_assoc($r4))
        $invpay_map[$r['delivery_date'].'|'.$r['sr_code']] = $r;

    /* ── Q5: credit ── */
    $paid_by_det = [];
    $rpd = mysqli_query($conn, "
        SELECT ip.field_summary_detail_id,
               ROUND(COALESCE(SUM(ip.amount),0),2) AS paid
        FROM invoice_payments ip
        INNER JOIN field_summary fs ON fs.id = ip.field_summary_id
        WHERE $where_fs
        GROUP BY ip.field_summary_detail_id");
    if ($rpd) while ($r = mysqli_fetch_assoc($rpd))
        $paid_by_det[intval($r['field_summary_detail_id'])] = floatval($r['paid']);

    $r5 = mysqli_query($conn, "
        SELECT fs.sr_code, fs.delivery_date,
               fsd.id AS det_id, fsd.adjust_net_value AS row_adj
        FROM field_summary_details fsd
        INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
        WHERE $where_fs");
    $credit_map = [];
    if ($r5) while ($r = mysqli_fetch_assoc($r5)) {
        $k       = $r['delivery_date'].'|'.$r['sr_code'];
        $row_bal = max(0.0, floatval($r['row_adj']) - ($paid_by_det[intval($r['det_id'])] ?? 0.0));
        if (!isset($credit_map[$k])) $credit_map[$k] = 0.0;
        $credit_map[$k] += $row_bal;
    }

    /* ── Q6: Day Cash Collection — split by collected_by (CC vs SR) ── */
    $r6 = mysqli_query($conn, "
        SELECT ip.payment_date, fs.sr_code,
            /* ── CC collection (collected_by = 'cc') ── */
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
                               AND COALESCE(ip.payment_source,'invoice')='invoice'
                          THEN ip.amount ELSE 0 END),0) AS cc_daily_sale,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
                               AND ip.payment_source IN ('credit_sales','credit_sale')
                          THEN ip.amount ELSE 0 END),0) AS cc_rcvd_credit,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
                               AND ip.payment_source='return_cheque_settlement'
                          THEN ip.amount ELSE 0 END),0) AS cc_rcvd_rtn_chq,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
                               AND ip.payment_source='return_charge_settlement'
                          THEN ip.amount ELSE 0 END),0) AS cc_rcvd_rtn_chgs,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
                               AND ip.payment_source='sentback_cheque_settlement'
                          THEN ip.amount ELSE 0 END),0) AS cc_rcvd_sent_back,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
                          THEN ip.amount ELSE 0 END),0) AS cc_total,
            /* ── SR collection (collected_by != 'cc') ── */
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc'
                               AND COALESCE(ip.payment_source,'invoice')='invoice'
                          THEN ip.amount ELSE 0 END),0) AS sr_daily_sale,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc'
                               AND ip.payment_source IN ('credit_sales','credit_sale')
                          THEN ip.amount ELSE 0 END),0) AS sr_rcvd_credit,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc'
                               AND ip.payment_source='return_cheque_settlement'
                          THEN ip.amount ELSE 0 END),0) AS sr_rcvd_rtn_chq,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc'
                               AND ip.payment_source='return_charge_settlement'
                          THEN ip.amount ELSE 0 END),0) AS sr_rcvd_rtn_chgs,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc'
                               AND ip.payment_source='sentback_cheque_settlement'
                          THEN ip.amount ELSE 0 END),0) AS sr_rcvd_sent_back,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc'
                          THEN ip.amount ELSE 0 END),0) AS sr_total,
            /* ── Grand total ── */
            COALESCE(SUM(ip.amount),0) AS total_coll
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        INNER JOIN field_summary fs           ON fs.id  = fsd.field_summary_id
        WHERE ip.payment_method = 'cash'
          AND ip.is_reversed    = 0
          AND ip.payment_date BETWEEN '$df' AND '$dt'
          $srCnd
        GROUP BY ip.payment_date, fs.sr_code");
    $cash_map = [];
    if ($r6) while ($r = mysqli_fetch_assoc($r6))
        $cash_map[$r['payment_date'].'|'.$r['sr_code']] = $r;

    /* ── Q7: bank deposits ── */
    $r7 = mysqli_query($conn, "
        SELECT delivery_date, rep_code,
            COALESCE(SUM(CASE WHEN handed_over_bo=0 THEN amount ELSE 0 END),0) AS banked,
            COALESCE(SUM(CASE WHEN handed_over_bo=1 THEN amount ELSE 0 END),0) AS handed
        FROM cc_cash_deposits
        WHERE $dep_w
        GROUP BY delivery_date, rep_code");
    $dep_map = [];
    if ($r7) while ($r = mysqli_fetch_assoc($r7))
        $dep_map[$r['delivery_date'].'|'.$r['rep_code']] = $r;

    /* ── Q8: pay allocations ── */
    $r8 = mysqli_query($conn, "
        SELECT pay_date, sr_code, entry_type, employee_id, employee_name, amount, total_value
        FROM cash_summary_pay_allocations
        WHERE pay_date BETWEEN '$df' AND '$dt'"
        .($sr_esc ? " AND sr_code='$sr_esc'" : '')
        ." ORDER BY pay_date, sr_code, entry_type");
    $alloc_map = [];
    if ($r8) while ($r = mysqli_fetch_assoc($r8)) {
        $k = $r['pay_date'].'|'.$r['sr_code'];
        if (!isset($alloc_map[$k])) $alloc_map[$k] = [
            'p_charge'=>0,'p_absorb'=>0,'p_variance'=>null,'employees'=>[],'is_paid'=>false
        ];
        if ($r['entry_type']==='charge') {
            $alloc_map[$k]['p_charge'] += floatval($r['amount']);
            $alloc_map[$k]['employees'][] = [
                'employee_id'  => $r['employee_id'],
                'employee_name'=> $r['employee_name'],
                'amount'       => floatval($r['amount'])
            ];
            $alloc_map[$k]['is_paid'] = true;
        }
        if ($r['entry_type']==='absorb')  { $alloc_map[$k]['p_absorb']  = floatval($r['amount']); $alloc_map[$k]['is_paid']=true; }
        if ($r['entry_type']==='variance')  $alloc_map[$k]['p_variance'] = floatval($r['amount']);
    }

    /* ── Q9: good shortage ── */
    $r9 = mysqli_query($conn, "
        SELECT i.delivery_date,
            COALESCE(SUM(CASE WHEN d.short_excess<0
                              THEN d.tur*ABS(d.short_excess) ELSE 0 END),0) AS good_short
        FROM unloading_summary_imports i
        INNER JOIN unloading_summary_import_details d ON d.import_id=i.id AND d.status='imported'
        WHERE i.delivery_date BETWEEN '$df' AND '$dt' AND i.status='completed'
        GROUP BY i.delivery_date");
    $unl_map = [];
    if ($r9) while ($r = mysqli_fetch_assoc($r9))
        $unl_map[$r['delivery_date']] = floatval($r['good_short']);

    /* ── Build rows ── */
    foreach ($master as $k) {
        [$del_date, $sr_code] = explode('|', $k, 2);

        $lm  = $load_map[$k]   ?? [];
        $am2 = $adj_map[$k]    ?? [];
        $pm  = $invpay_map[$k] ?? [];
        $dm  = $dep_map[$k]    ?? [];
        $cm  = $cash_map[$del_date.'|'.$sr_code] ?? [];

        $loading        = floatval($lm['loading']    ?? 0);
        $total_adj      = floatval($am2['total_adj'] ?? 0);
        $final_bill     = floatval($bill_map[$k]     ?? 0);
        $sinv           = floatval($sinv_map[$k]     ?? 0);
        $canceled_value = floatval($cancel_map[$k]   ?? 0);
        $inv_diff       = $sinv - $final_bill;

        $cash_paid   = floatval($pm['cash_paid']   ?? 0);
        $cheque_paid = floatval($pm['cheque_paid'] ?? 0);
        $credit      = floatval($credit_map[$k]   ?? 0);
        $pay_diff    = $final_bill - ($cash_paid + $cheque_paid + $credit);

        /* CC split */
        $cc_daily_sale    = floatval($cm['cc_daily_sale']    ?? 0);
        $cc_rcvd_credit   = floatval($cm['cc_rcvd_credit']   ?? 0);
        $cc_rcvd_rtn_chq  = floatval($cm['cc_rcvd_rtn_chq']  ?? 0);
        $cc_rcvd_rtn_chgs = floatval($cm['cc_rcvd_rtn_chgs'] ?? 0);
        $cc_rcvd_sent_back= floatval($cm['cc_rcvd_sent_back'] ?? 0);
        $cc_total         = floatval($cm['cc_total']          ?? 0);

        /* SR split */
        $sr_daily_sale    = floatval($cm['sr_daily_sale']    ?? 0);
        $sr_rcvd_credit   = floatval($cm['sr_rcvd_credit']   ?? 0);
        $sr_rcvd_rtn_chq  = floatval($cm['sr_rcvd_rtn_chq']  ?? 0);
        $sr_rcvd_rtn_chgs = floatval($cm['sr_rcvd_rtn_chgs'] ?? 0);
        $sr_rcvd_sent_back= floatval($cm['sr_rcvd_sent_back'] ?? 0);
        $sr_total         = floatval($cm['sr_total']         ?? 0);

        $total_coll  = floatval($cm['total_coll'] ?? 0);

        $banked    = floatval($dm['banked'] ?? 0);
        $handed    = floatval($dm['handed'] ?? 0);
        $bank_diff = $total_coll - ($banked + $handed);
        $cash_short= $bank_diff > 0 ? $bank_diff : 0;
        $good_short= floatval($unl_map[$del_date] ?? 0);

        $pa         = $alloc_map[$k] ?? [];
        $p_charge   = floatval($pa['p_charge']   ?? 0);
        $p_absorb   = floatval($pa['p_absorb']   ?? 0);
        $p_variance = isset($pa['p_variance']) ? floatval($pa['p_variance']) : null;
        $p_employees= ($pa['employees'] ?? []);
        $is_paid    = (bool)($pa['is_paid'] ?? false);

        $rows[] = compact(
            'sr_code','del_date',
            'loading','total_adj','final_bill','sinv','canceled_value','inv_diff',
            'cash_paid','cheque_paid','credit','pay_diff',
            'cc_daily_sale','cc_rcvd_credit','cc_rcvd_rtn_chq','cc_rcvd_rtn_chgs','cc_rcvd_sent_back','cc_total',
            'sr_daily_sale','sr_rcvd_credit','sr_rcvd_rtn_chq','sr_rcvd_rtn_chgs','sr_rcvd_sent_back','sr_total',
            'total_coll',
            'banked','handed','bank_diff',
            'cash_short','good_short',
            'p_charge','p_absorb','p_variance','p_employees','is_paid'
        );

        foreach (array_keys($totals) as $tk)
            if (isset($$tk)) $totals[$tk] += floatval($$tk);
    }
}

/* ── Helpers ── */
function cc_v($v) {
    $n = floatval($v);
    return $n==0 ? '<span class="dash">—</span>'
                 : '<span class="num">'.number_format($n,2).'</span>';
}
function cc_coll($v, $sr, $date, $coll, $src, $label) {
    $n = floatval($v);
    if ($n == 0) return '<span class="dash">—</span>';
    $sr_e  = htmlspecialchars($sr,  ENT_QUOTES);
    $lbl_e = htmlspecialchars($label, ENT_QUOTES);
    return '<span class="coll-amt" '
        . 'data-sr="'.$sr_e.'" data-date="'.htmlspecialchars($date,ENT_QUOTES).'" '
        . 'data-coll="'.$coll.'" data-src="'.$src.'" data-label="'.$lbl_e.'" '
        . 'onclick="openCollModal(this)">'
        . '<span class="num">'.number_format($n,2).'</span>'
        . '<i class="fa-solid fa-magnifying-glass coll-ico"></i>'
        . '</span>';
}
function cc_pay_link($v, $sr, $date, $method) {
    $n = floatval($v);
    if ($n == 0) return '<span class="dash">—</span>';
    $url = 'payment_details.php?sr_code='.urlencode($sr).'&date='.urlencode($date).'&method='.urlencode($method);
    $cls = $method === 'cheque' ? 'pay-link cheque' : 'pay-link cash';
    return '<a href="'.$url.'" target="_blank" class="'.$cls.'"><span class="num">'.number_format($n,2).'</span> <i class="fa-solid fa-arrow-up-right-from-square pay-link-ico"></i></a>';
}
function cc_d($v) {
    $n = floatval($v);
    if (abs($n)<0.005) return '<span class="d-ok">0.00 ✓</span>';
    if ($n<0)          return '<span class="d-sur">▲ '.number_format(abs($n),2).'</span>';
    return                    '<span class="d-def">▼ '.number_format($n,2).'</span>';
}
function cc_t($v) { $n=floatval($v); return $n==0?'—':number_format($n,2); }
function cc_pvar($v) {
    if ($v===null) return '<span style="color:#d1d5db;">—</span>';
    $v=floatval($v);
    if (abs($v)<0.005) return '<span class="pv-ok">0.00 ✓</span>';
    if ($v<0) return '<span class="pv-exc">▲ EXCESS '.number_format(abs($v),2).'</span>';
    return '<span class="pv-sht">▼ SHORT '.number_format($v,2).'</span>';
}
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');
:root{
    --bg:#eef0f3;--surface:#fff;--bdr:#d2d6db;--bdrs:#e4e7ec;
    --tx:#1a1f2e;--txm:#58626e;--txs:#9aa3ad;
    --fn:'Inter',sans-serif;--mn:'JetBrains Mono',monospace;
    --r:8px;--sh:0 1px 3px rgba(0,0,0,.07),0 4px 14px rgba(0,0,0,.05);
    --g0:#2d3748;--g0s:#3a4455;--g1:#1e4d8c;--g1s:#16408a;
    --g2:#7a3f10;--g2s:#6a3510;--g3:#1a6640;--g3s:#155535;
    --g4:#2d5a1a;--g4s:#254e14;--g5:#6b2040;--g5s:#5a1a35;
    /* CC = green (g3), SR = teal */
    --gcc:#1a6640;--gccs:#155535;
    --gsr:#0f766e;--gsrs:#0d6462;
    /* Total coll = slate */
    --gtc:#374151;--gtcs:#2d3748;
    --total-bg:#0f172a;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:13px;}
.pg{padding:22px 18px 60px;}
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;}
.pg-h1{font-size:22px;font-weight:800;letter-spacing:-.02em;}
.pg-h1 em{color:var(--g3);font-style:normal;}
.pg-sub{font-size:11px;color:var(--txs);margin-top:3px;}
.dpill{display:inline-flex;align-items:center;gap:6px;background:#f0fdf4;border:1px solid #86efac;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;color:#166534;font-family:var(--mn);}
.fbar{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:12px 15px;margin-bottom:14px;display:flex;align-items:flex-end;gap:11px;flex-wrap:wrap;box-shadow:var(--sh);}
.fg{display:flex;flex-direction:column;gap:3px;}
.fg label{font-size:10px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.07em;}
.fg input,.fg select{padding:7px 10px;border:1.5px solid var(--bdr);border-radius:6px;font-size:13px;font-family:var(--fn);color:var(--tx);background:#fff;}
.fg input:focus,.fg select:focus{outline:none;border-color:var(--g3);}
.btn-go{display:inline-flex;align-items:center;gap:6px;padding:8px 20px;background:var(--g0);color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;font-family:var(--fn);cursor:pointer;}
.btn-go:hover{background:#3a4560;}
.btn-rst{display:inline-flex;align-items:center;gap:5px;padding:8px 12px;background:#f0f0f0;color:var(--txm);border:1px solid var(--bdr);border-radius:6px;font-size:12px;font-weight:600;font-family:var(--fn);cursor:pointer;text-decoration:none;}
.btn-excel{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:linear-gradient(135deg,#166534 0%,#15803d 100%);color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;font-family:var(--fn);cursor:pointer;box-shadow:0 2px 6px rgba(22,101,52,.3);transition:all .2s;}
.btn-excel:hover{background:linear-gradient(135deg,#14532d 0%,#166534 100%);transform:translateY(-1px);}
.sc-row{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:16px;}
.sc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:12px 14px;box-shadow:var(--sh);border-left:3px solid #e5e7eb;}
.sc.blue{border-left-color:#3b82f6;}.sc.green{border-left-color:#22c55e;}
.sc.amber{border-left-color:#f59e0b;}.sc.red{border-left-color:#ef4444;}
.sc-lbl{font-size:10px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;}
.sc-val{font-size:15px;font-weight:800;}
.tc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);overflow:hidden;box-shadow:var(--sh);}
.tc-bar{display:flex;justify-content:space-between;align-items:center;padding:11px 15px;border-bottom:1px solid var(--bdrs);flex-wrap:wrap;gap:8px;}
.tc-ttl{font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;}
.pill{padding:2px 9px;border-radius:12px;font-size:10px;font-weight:700;}
.p-green{background:#dcfce7;color:#166534;}.p-blue{background:#dbeafe;color:#1e40af;}.p-slate{background:#f1f5f9;color:#475569;}

/* Top scrollbar */
.top-scroll-wrap{overflow-x:auto;overflow-y:hidden;height:12px;margin-bottom:2px;border-radius:4px;}
.top-scroll-inner{height:1px;}
.tscroll{overflow-x:auto;position:relative;}

table.cct{width:100%;border-collapse:collapse;font-size:11.5px;font-family:var(--fn);}
.cct .G th{padding:8px 8px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#fff;text-align:center;white-space:nowrap;border-right:2px solid rgba(255,255,255,.18);}
.cct .G th:last-child{border-right:none;}.cct .G th.tl{text-align:left;}
.G .h0{background:var(--g0);}.G .h1{background:var(--g1);}.G .h2{background:var(--g2);}
.G .h3{background:var(--g3);}.G .h4{background:var(--g4);}.G .h5{background:var(--g5);}
.G .hcc{background:var(--gcc);} /* CC Collection */
.G .hsr{background:var(--gsr);} /* SR Collection */
.G .htc{background:var(--gtc);} /* Total Coll.   */
.cct .S th{padding:5px 8px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:rgba(255,255,255,.88);text-align:right;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);border-bottom:2px solid var(--bdr);}
.cct .S th.tl{text-align:left;}.cct .S th:last-child{border-right:none;}
.S .s0{background:var(--g0s);}.S .s1{background:var(--g1s);}.S .s2{background:var(--g2s);}
.S .s3{background:var(--g3s);}.S .s4{background:var(--g4s);}.S .s5{background:var(--g5s);}
.S .scc{background:var(--gccs);}
.S .ssr{background:var(--gsrs);}
.S .stc{background:var(--gtcs);}
.cct .TH td{background:#e0edff;color:#1e3a8a;font-weight:800;font-size:11px;padding:7px 8px;text-align:right;border-bottom:2px solid #93c5fd;white-space:nowrap;}
.cct .TH td.tl{text-align:left;}
.cct tbody tr{border-bottom:1px solid #f0f4f8;}
.cct tbody tr:nth-child(even) td{background:#fafbfc;}
.cct tbody tr:hover td{background:#eff6ff!important;}
.cct tbody td{padding:6px 8px;text-align:right;white-space:nowrap;}
.cct tbody td.tl{text-align:left;}
.cct tbody td.rc{font-weight:700;font-size:11px;}
.cct tbody td.nm{font-size:10.5px;color:var(--txm);}
.cct tbody td.dt{font-family:var(--mn);font-size:10px;color:var(--txm);}
.cct tbody td.fb{font-weight:700;color:#1e40af;}
.cct tbody td.tv{font-weight:700;color:#166534;}
/* CC / SR column background tints */
.cct tbody td.col-cc{background:rgba(26,102,64,.04);}
.cct tbody tr:nth-child(even) td.col-cc{background:rgba(26,102,64,.07);}
.cct tbody tr:hover td.col-cc{background:#f0fdf4!important;}
.cct tbody td.col-sr{background:rgba(15,118,110,.04);}
.cct tbody tr:nth-child(even) td.col-sr{background:rgba(15,118,110,.07);}
.cct tbody tr:hover td.col-sr{background:#f0fdfa!important;}
.cct tbody td.col-tc{font-weight:700;color:#0f766e;}
.cct tfoot td{padding:8px 8px;font-weight:800;font-size:12px;background:var(--total-bg);color:#e2e8f0;border-top:2px solid #334155;text-align:right;white-space:nowrap;}
.cct tfoot td.tl{text-align:left;color:#94a3b8;}
.num{font-family:var(--mn);font-size:11px;}.dash{color:#d1d5db;}
.d-ok{color:#16a34a;font-weight:700;font-family:var(--mn);font-size:10.5px;}
.d-sur{color:#d97706;font-weight:700;font-family:var(--mn);font-size:10.5px;}
.d-def{color:#dc2626;font-weight:700;font-family:var(--mn);font-size:10.5px;}
.sv{color:#dc2626;font-weight:700;font-family:var(--mn);font-size:11px;}
.cv{color:#e65100;font-weight:700;font-family:var(--mn);font-size:11px;}
.h6{background:#5b21b6;}.s6{background:#ede9fe;color:#5b21b6;}
.col-p{background:#fdf4ff;}
.val-charge{color:#dc2626;font-weight:600;}
.val-absorb{color:#0369a1;font-weight:600;}

/* ── Clickable collection amounts ── */
.coll-amt{display:inline-flex;align-items:center;gap:4px;cursor:pointer;padding:2px 5px;border-radius:4px;transition:all .15s;}
.coll-amt .coll-ico{font-size:8px;opacity:0;transition:opacity .15s;}
.coll-amt:hover{background:rgba(15,118,110,.10);box-shadow:0 0 0 1px rgba(15,118,110,.3);}
.coll-amt:hover .coll-ico{opacity:1;}
.coll-amt .num{border-bottom:1px dashed currentColor;}
.col-cc .coll-amt:hover{background:rgba(26,102,64,.12);box-shadow:0 0 0 1px rgba(26,102,64,.3);}
.col-sr .coll-amt:hover{background:rgba(15,118,110,.12);box-shadow:0 0 0 1px rgba(15,118,110,.3);}

/* ── Pay links ── */
.pay-link{display:inline-flex;align-items:center;gap:4px;text-decoration:none;padding:2px 6px;border-radius:4px;transition:all .15s;}
.pay-link .pay-link-ico{font-size:8px;opacity:0;transition:opacity .15s;}
.pay-link:hover .pay-link-ico{opacity:1;}
.pay-link.cash{color:#166534;}
.pay-link.cash:hover{background:#f0fdf4;box-shadow:0 0 0 1px #86efac;}
.pay-link.cheque{color:#5b21b6;}
.pay-link.cheque:hover{background:#f5f3ff;box-shadow:0 0 0 1px #c4b5fd;}
.pay-link .num{border-bottom:1px dashed currentColor;}
.pv-ok{color:#16a34a;font-weight:700;}
.pv-exc{color:#d97706;font-weight:700;}
.pv-sht{color:#dc2626;font-weight:700;}
.btn-pay-alloc{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border:1px solid #ddd6fe;border-radius:6px;background:#f5f3ff;color:#7c3aed;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s;white-space:nowrap;}
.btn-pay-alloc:hover{background:#7c3aed;color:#fff;}
.btn-pay-alloc.paid{background:#f0fdf4;color:#16a34a;border-color:#86efac;}

/* ── Sticky first column ── */
.cct thead tr.G th:nth-child(1),
.cct thead tr.S th:nth-child(1),
.cct thead tr.TH td:nth-child(1),
.cct tbody tr td:nth-child(1),
.cct tfoot tr td:nth-child(1){position:sticky;left:0;z-index:2;}
.cct thead tr.G th:nth-child(1),
.cct thead tr.S th:nth-child(1),
.cct thead tr.TH td:nth-child(1){z-index:4;}
.cct thead tr.G th:nth-child(1){background:var(--g0);}
.cct thead tr.S th:nth-child(1){background:var(--g0s);}
.cct thead tr.TH td:nth-child(1){background:#e0edff;}
.cct tbody tr td:nth-child(1){background:#fff;}
.cct tbody tr:nth-child(even) td:nth-child(1){background:#fafbfc;}
.cct tbody tr:hover td:nth-child(1){background:#eff6ff!important;}
.cct tfoot tr td:nth-child(1){background:var(--total-bg);}
.cct thead tr.G th:nth-child(1),
.cct thead tr.S th:nth-child(1),
.cct thead tr.TH td:nth-child(1),
.cct tbody tr td:nth-child(1),
.cct tfoot tr td:nth-child(1){box-shadow:3px 0 8px rgba(0,0,0,.10);}

/* ══════════════════════════════════
   MODALS (shared base)
══════════════════════════════════ */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:none;align-items:center;justify-content:center;padding:16px;}
.modal-overlay.open{display:flex;}
.pay-modal-box{background:#fff;border-radius:12px;width:94%;max-width:580px;max-height:92vh;box-shadow:0 24px 64px rgba(0,0,0,.22);display:flex;flex-direction:column;overflow:hidden;}
.coll-modal-box{background:#fff;border-radius:12px;width:96%;max-width:900px;max-height:90vh;box-shadow:0 24px 64px rgba(0,0,0,.22);display:flex;flex-direction:column;overflow:hidden;}
.pmo-header{padding:18px 24px 14px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:flex-start;flex-shrink:0;}
.pmo-title{font-size:17px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;}
.pmo-close{background:none;border:none;cursor:pointer;color:#9ca3af;font-size:24px;line-height:1;padding:0;}
.pmo-close:hover{color:#1f2937;}
.pmo-info{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:14px 24px;background:#f9fafb;border-bottom:1px solid #f0f0f0;flex-shrink:0;}
.pmo-info-cell{background:#fff;border:1px solid #e5e5e5;border-radius:7px;padding:10px 12px;}
.pmo-info-cell.hi{background:#fef2f2;border-color:#fca5a5;}
.pmo-info-lbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;}
.pmo-info-val{font-size:14px;font-weight:700;color:#1f2937;}
.pmo-info-cell.hi .pmo-info-val{color:#dc2626;font-size:18px;}
.pmo-body{padding:20px 24px;overflow-y:auto;flex:1;}
.pmo-section{border:1px solid #e5e5e5;border-radius:8px;margin-bottom:14px;overflow:hidden;}
.pmo-sec-head{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;}
.pmo-sec-head.red{background:#fef2f2;color:#991b1b;border-bottom:1px solid #fecaca;}
.pmo-sec-head.blue{background:#eff6ff;color:#1e40af;border-bottom:1px solid #bfdbfe;}
.pmo-sec-total{font-size:15px;font-weight:800;letter-spacing:0;}
.pmo-sec-body{padding:12px 14px;background:#fff;}
.pmo-totals{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:14px;padding:14px;background:#f9fafb;border:1px solid #e5e5e5;border-radius:8px;}
.ptb-cell{text-align:center;}
.ptb-lbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.ptb-val{font-size:17px;font-weight:800;}
.absorb-row{display:flex;align-items:center;gap:10px;}
.absorb-lbl{font-size:13px;color:#1e40af;font-weight:600;flex:1;display:flex;align-items:center;gap:6px;}
.absorb-inp{width:130px;flex-shrink:0;border:1px solid #bfdbfe;border-radius:6px;padding:7px 10px;font-size:13px;text-align:right;outline:none;}
.absorb-inp:focus{border-color:#1e40af;}
.add-emp-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 13px;border:1px dashed #fca5a5;border-radius:6px;background:#fff;color:#dc2626;font-size:12px;font-weight:600;cursor:pointer;margin-top:8px;}
.add-emp-btn:hover{background:#fef2f2;}
.pmo-ftr{padding:14px 24px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0;}

/* ── Collection details modal table ── */
.cm-header-bar{padding:10px 18px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;background:#f9fafb;}
.cm-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 12px;border-radius:14px;font-size:11px;font-weight:700;}
.cm-badge.cc{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.cm-badge.sr{background:#ccfbf1;color:#0f766e;border:1px solid #99f6e4;}
.cm-badge.all{background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;}
.cm-body{overflow:auto;flex:1;}
table.cm-tbl{width:100%;border-collapse:collapse;font-size:11.5px;}
.cm-tbl thead th{padding:8px 10px;font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#fff;text-align:left;white-space:nowrap;border-right:1px solid rgba(255,255,255,.15);}
.cm-tbl thead th.ar{text-align:right;}
.cm-tbl thead th:last-child{border-right:none;}
.cm-tbl tbody tr{border-bottom:1px solid #f0f4f8;}
.cm-tbl tbody tr:nth-child(even) td{background:#fafbfc;}
.cm-tbl tbody tr:hover td{background:#eff6ff!important;}
.cm-tbl tbody td{padding:6px 10px;white-space:nowrap;vertical-align:middle;}
.cm-tbl tbody td.ar{text-align:right;}
.cm-tbl tfoot td{padding:8px 10px;font-weight:800;font-size:12px;background:var(--total-bg);color:#e2e8f0;border-top:2px solid #334155;}
.cm-tbl tfoot td.ar{text-align:right;}
.cm-empty{text-align:center;padding:40px;color:#9ca3af;}
.src-badge{display:inline-block;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;}
.coll-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;}

#ccToast{position:fixed;bottom:24px;right:24px;padding:11px 18px;border-radius:8px;font-size:13px;font-weight:600;z-index:9999;display:none;opacity:0;transition:opacity .3s;}
.toast-ok{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.toast-err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.empty{text-align:center;padding:60px 20px;color:var(--txs);}
.empty i{font-size:42px;display:block;margin-bottom:12px;opacity:.25;}
@media(max-width:900px){.sc-row{grid-template-columns:1fr 1fr;}}
@media print{.no-print{display:none!important;}th{-webkit-print-color-adjust:exact;print-color-adjust:exact;}.cct tfoot td{-webkit-print-color-adjust:exact;print-color-adjust:exact;}}
</style>

<div class="pg">
<div class="topbar no-print">
    <div>
        <div class="pg-h1">Daily Cash <em>Collection</em></div>
        <div class="pg-sub">Invoice Details · Invoice Payments · Day Cash Collection (CC &amp; SR) · Bank Deposit · Cash &amp; Good Shortage — per Rep per Date</div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        <div class="dpill"><i class="fa-solid fa-calendar-days"></i>
            <?php echo date('d M Y',strtotime($date_from)).' — '.date('d M Y',strtotime($date_to)); ?>
        </div>
        <?php if($submitted&&!empty($rows)): ?>
        <button onclick="exportToExcel()" class="btn-excel"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
        <button onclick="window.print()" class="btn-rst"><i class="fa-solid fa-print"></i> Print</button>
        <?php endif; ?>
    </div>
</div>
<div class="fbar no-print">
    <form method="GET" id="ccf" style="display:contents;">
        <input type="hidden" name="search" value="1">
        <div class="fg"><label><i class="fa-solid fa-calendar-day"></i> Date From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"></div>
        <div class="fg"><label><i class="fa-solid fa-calendar-day"></i> Date To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"></div>
        <div class="fg" style="min-width:160px;"><label><i class="fa-solid fa-id-badge"></i> Sales Rep</label>
            <select name="sr_code">
                <option value="">— All Reps —</option>
                <?php foreach($all_sr as $sr): ?>
                <option value="<?php echo htmlspecialchars($sr); ?>" <?php echo $f_sr===$sr?'selected':''; ?>>
                    <?php echo htmlspecialchars($sr); ?></option>
                <?php endforeach; ?>
            </select></div>
        <button type="submit" class="btn-go" id="gBtn"><i class="fa-solid fa-magnifying-glass"></i> Generate</button>
        <a href="cash_collection.php" class="btn-rst" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
    </form>
</div>

<?php if(!$submitted): ?>
<div class="tc"><div class="empty">
    <i class="fa-solid fa-money-bill-wave"></i>
    <p style="font-size:14px;font-weight:600;margin-bottom:6px;">Daily Cash Collection Report</p>
    <p>Choose a date range and click <strong>Generate</strong> to view the report.</p>
</div></div>
<?php elseif(empty($rows)): ?>
<div class="tc"><div class="empty">
    <i class="fa-solid fa-inbox"></i>
    <p style="font-size:14px;font-weight:600;margin-bottom:6px;">No records found</p>
    <p><?php echo date('d M Y',strtotime($date_from)).' – '.date('d M Y',strtotime($date_to));
          echo $f_sr?' &nbsp;·&nbsp; Rep: <strong>'.htmlspecialchars($f_sr).'</strong>':''; ?></p>
</div></div>
<?php else: ?>
<div class="sc-row no-print">
    <div class="sc blue">
        <div class="sc-lbl"><i class="fa-solid fa-file-invoice-dollar"></i> Final Bill Value</div>
        <div class="sc-val" style="color:#1e40af;">Rs. <?php echo number_format($totals['final_bill'],2); ?></div>
    </div>
    <div class="sc green">
        <div class="sc-lbl"><i class="fa-solid fa-coins"></i> CC Collection</div>
        <div class="sc-val" style="color:#166534;">Rs. <?php echo number_format($totals['cc_total'],2); ?></div>
    </div>
    <div class="sc green">
        <div class="sc-lbl"><i class="fa-solid fa-coins"></i> SR Collection</div>
        <div class="sc-val" style="color:#0f766e;">Rs. <?php echo number_format($totals['sr_total'],2); ?></div>
    </div>
    <div class="sc green">
        <div class="sc-lbl"><i class="fa-solid fa-building-columns"></i> CC Bank Deposit</div>
        <div class="sc-val" style="color:#166534;">Rs. <?php echo number_format($totals['banked'],2); ?></div>
    </div>
    <?php $tot_sh=$totals['cash_short']+$totals['good_short']; ?>
    <div class="sc <?php echo $tot_sh>0?'red':'green'; ?>">
        <div class="sc-lbl"><i class="fa-solid fa-triangle-exclamation"></i> Total Shortages</div>
        <div class="sc-val" style="color:<?php echo $tot_sh>0?'#991b1b':'#166534'; ?>;">
            Rs. <?php echo number_format($tot_sh,2); ?></div>
    </div>
</div>
<div class="tc">
    <div class="tc-bar no-print">
        <div class="tc-ttl">
            <i class="fa-solid fa-table-cells-large"></i> Cash Collection Detail
            <span class="pill p-green"><?php echo count($rows); ?> records</span>
            <span class="pill p-blue"><?php echo date('d M Y',strtotime($date_from)).' – '.date('d M Y',strtotime($date_to)); ?></span>
            <?php if($f_sr): ?><span class="pill p-slate">Rep: <?php echo htmlspecialchars($f_sr); ?></span><?php endif; ?>
        </div>
    </div>

    <div class="top-scroll-wrap no-print" id="topScroll">
        <div class="top-scroll-inner" id="topScrollInner"></div>
    </div>

    <div class="tscroll" id="mainScroll">
    <table class="cct">
      <thead>
        <!-- ── Group row ── -->
        <tr class="G">
            <th class="h0 tl" colspan="3"></th>
            <th class="h1" colspan="6">Daily Invoice Details</th>
            <th class="h2" colspan="4">Invoice Payments</th>
            <th class="hcc" colspan="6">CC Collection</th>
            <th class="hsr" colspan="6">SR Collection</th>
            <th class="htc" colspan="1">Total Coll.</th>
            <th class="h4" colspan="3">Bank Deposit</th>
            <th class="h5" colspan="2">Shortage</th>
            <th class="h6" colspan="3">Pay Allocation</th>
            <th class="h6" colspan="1">Action</th>
        </tr>
        <!-- ── Sub-column row ── -->
        <tr class="S">
            <th class="s0 tl" style="min-width:82px;">Rep Code</th>
            <th class="s0 tl" style="min-width:130px;">Rep Name</th>
            <th class="s0 tl" style="min-width:82px;">Del. Date</th>
            <th class="s1" style="min-width:110px;">Loading Value</th>
            <th class="s1" style="min-width:110px;">Total Adjustment</th>
            <th class="s1" style="min-width:115px;">Final Bill Value</th>
            <th class="s1" style="min-width:120px;">Secondary Invoice</th>
            <th class="s1" style="min-width:120px;">Canceled Value</th>
            <th class="s1" style="min-width:72px;">Diff</th>
            <th class="s2" style="min-width:105px;">Cash Paid</th>
            <th class="s2" style="min-width:105px;">Cheque Paid</th>
            <th class="s2" style="min-width:92px;">Credit</th>
            <th class="s2" style="min-width:72px;">Diff</th>
            <!-- CC -->
            <th class="scc" style="min-width:105px;">Daily Sale</th>
            <th class="scc" style="min-width:110px;">Rcvd Credit</th>
            <th class="scc" style="min-width:105px;">Rtn Cheque</th>
            <th class="scc" style="min-width:105px;">Rtn Chgs</th>
            <th class="scc" style="min-width:105px;">Sent Back</th>
            <th class="scc" style="min-width:110px;">CC Total</th>
            <!-- SR -->
            <th class="ssr" style="min-width:105px;">Daily Sale</th>
            <th class="ssr" style="min-width:110px;">Rcvd Credit</th>
            <th class="ssr" style="min-width:105px;">Rtn Cheque</th>
            <th class="ssr" style="min-width:105px;">Rtn Chgs</th>
            <th class="ssr" style="min-width:105px;">Sent Back</th>
            <th class="ssr" style="min-width:110px;">SR Total</th>
            <!-- Grand total -->
            <th class="stc" style="min-width:120px;">Total Cash Coll.</th>
            <th class="s4" style="min-width:135px;">CC Deposit Bank</th>
            <th class="s4" style="min-width:135px;">Handed to Office</th>
            <th class="s4" style="min-width:72px;">Variance</th>
            <th class="s5" style="min-width:100px;">Cash Short</th>
            <th class="s5" style="min-width:100px;">Good Short</th>
            <th class="s6" style="min-width:110px;">Charge to Emp</th>
            <th class="s6" style="min-width:110px;">Absorb by Co.</th>
            <th class="s6" style="min-width:100px;">Pay Variance</th>
            <th class="s6" style="min-width:75px;text-align:center;">Action</th>
        </tr>
        <!-- ── Column totals ── -->
        <tr class="TH">
            <td class="tl" colspan="3"><i class="fa-solid fa-sigma"></i>&nbsp; Total (<?php echo count($rows); ?> rows)</td>
            <td><?php echo cc_t($totals['loading']); ?></td>
            <td><?php echo cc_t($totals['total_adj']); ?></td>
            <td><?php echo cc_t($totals['final_bill']); ?></td>
            <td><?php echo cc_t($totals['sinv']); ?></td>
            <td><?php echo cc_t($totals['canceled_value']); ?></td>
            <td><?php echo cc_d($totals['inv_diff']); ?></td>
            <td><?php echo cc_t($totals['cash_paid']); ?></td>
            <td><?php echo cc_t($totals['cheque_paid']); ?></td>
            <td><?php echo cc_t($totals['credit']); ?></td>
            <td><?php echo cc_d($totals['pay_diff']); ?></td>
            <!-- CC totals -->
            <td><?php echo cc_t($totals['cc_daily_sale']); ?></td>
            <td><?php echo cc_t($totals['cc_rcvd_credit']); ?></td>
            <td><?php echo cc_t($totals['cc_rcvd_rtn_chq']); ?></td>
            <td><?php echo cc_t($totals['cc_rcvd_rtn_chgs']); ?></td>
            <td><?php echo cc_t($totals['cc_rcvd_sent_back']); ?></td>
            <td><?php echo cc_t($totals['cc_total']); ?></td>
            <!-- SR totals -->
            <td><?php echo cc_t($totals['sr_daily_sale']); ?></td>
            <td><?php echo cc_t($totals['sr_rcvd_credit']); ?></td>
            <td><?php echo cc_t($totals['sr_rcvd_rtn_chq']); ?></td>
            <td><?php echo cc_t($totals['sr_rcvd_rtn_chgs']); ?></td>
            <td><?php echo cc_t($totals['sr_rcvd_sent_back']); ?></td>
            <td><?php echo cc_t($totals['sr_total']); ?></td>
            <!-- grand total -->
            <td><?php echo cc_t($totals['total_coll']); ?></td>
            <td><?php echo cc_t($totals['banked']); ?></td>
            <td><?php echo cc_t($totals['handed']); ?></td>
            <?php $tv=$totals['total_coll']-$totals['banked']-$totals['handed']; ?>
            <td><?php echo cc_d($tv); ?></td>
            <td><?php echo cc_t($totals['cash_short']); ?></td>
            <td><?php echo cc_t($totals['good_short']); ?></td>
            <td id="ft-charge"><?php echo cc_t($totals['p_charge']); ?></td>
            <td id="ft-absorb"><?php echo cc_t($totals['p_absorb']); ?></td>
            <td></td><td></td>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $i=>$r):
        $var0 = $r['bank_diff'];
      ?>
      <tr data-i="<?php echo $i; ?>"
          data-sr="<?php echo htmlspecialchars($r['sr_code']); ?>"
          data-date="<?php echo htmlspecialchars($r['del_date']); ?>"
          data-seval="<?php echo $var0; ?>"
          data-total="<?php echo $r['total_coll']; ?>">
          <td class="tl rc"><?php echo htmlspecialchars($r['sr_code']); ?></td>
          <td class="tl nm"><?php echo htmlspecialchars($names[$r['sr_code']]??''); ?></td>
          <td class="tl dt"><?php echo date('d M Y',strtotime($r['del_date'])); ?></td>
          <td><?php echo cc_v($r['loading']); ?></td>
          <td><?php echo cc_v($r['total_adj']); ?></td>
          <td class="fb"><?php echo cc_v($r['final_bill']); ?></td>
          <td><?php echo cc_v($r['sinv']); ?></td>
          <td><?php echo $r['canceled_value']>0?'<span class="cv">'.number_format($r['canceled_value'],2).'</span>':'<span class="dash">—</span>'; ?></td>
          <td><?php echo cc_d($r['inv_diff']); ?></td>
          <td><?php echo cc_pay_link($r['cash_paid'],   $r['sr_code'], $r['del_date'], 'cash'); ?></td>
          <td><?php echo cc_pay_link($r['cheque_paid'], $r['sr_code'], $r['del_date'], 'cheque'); ?></td>
          <td><?php echo cc_v($r['credit']); ?></td>
          <td><?php echo cc_d($r['pay_diff']); ?></td>
          <!-- CC columns -->
          <td class="col-cc"><?php echo cc_coll($r['cc_daily_sale'],    $r['sr_code'], $r['del_date'], 'cc', 'invoice',   'CC · Daily Sale'); ?></td>
          <td class="col-cc"><?php echo cc_coll($r['cc_rcvd_credit'],   $r['sr_code'], $r['del_date'], 'cc', 'credit',    'CC · Rcvd Credit'); ?></td>
          <td class="col-cc"><?php echo cc_coll($r['cc_rcvd_rtn_chq'],  $r['sr_code'], $r['del_date'], 'cc', 'rtn_chq',  'CC · Rtn Cheque'); ?></td>
          <td class="col-cc"><?php echo cc_coll($r['cc_rcvd_rtn_chgs'], $r['sr_code'], $r['del_date'], 'cc', 'rtn_chgs', 'CC · Rtn Charges'); ?></td>
          <td class="col-cc"><?php echo cc_coll($r['cc_rcvd_sent_back'],$r['sr_code'], $r['del_date'], 'cc', 'sent_back','CC · Sent Back'); ?></td>
          <td class="col-cc tv"><?php echo cc_coll($r['cc_total'],       $r['sr_code'], $r['del_date'], 'cc', '',         'CC · Total'); ?></td>
          <!-- SR columns -->
          <td class="col-sr"><?php echo cc_coll($r['sr_daily_sale'],    $r['sr_code'], $r['del_date'], 'sr', 'invoice',   'SR · Daily Sale'); ?></td>
          <td class="col-sr"><?php echo cc_coll($r['sr_rcvd_credit'],   $r['sr_code'], $r['del_date'], 'sr', 'credit',    'SR · Rcvd Credit'); ?></td>
          <td class="col-sr"><?php echo cc_coll($r['sr_rcvd_rtn_chq'],  $r['sr_code'], $r['del_date'], 'sr', 'rtn_chq',  'SR · Rtn Cheque'); ?></td>
          <td class="col-sr"><?php echo cc_coll($r['sr_rcvd_rtn_chgs'], $r['sr_code'], $r['del_date'], 'sr', 'rtn_chgs', 'SR · Rtn Charges'); ?></td>
          <td class="col-sr"><?php echo cc_coll($r['sr_rcvd_sent_back'],$r['sr_code'], $r['del_date'], 'sr', 'sent_back','SR · Sent Back'); ?></td>
          <td class="col-sr tv"><?php echo cc_coll($r['sr_total'],       $r['sr_code'], $r['del_date'], 'sr', '',         'SR · Total'); ?></td>
          <!-- Grand total -->
          <td class="col-tc"><?php echo cc_coll($r['total_coll'], $r['sr_code'], $r['del_date'], '', '', 'Total Cash Collected'); ?></td>
          <td><?php echo cc_v($r['banked']); ?></td>
          <td><?php echo cc_v($r['handed']); ?></td>
          <td id="var-<?php echo $i; ?>"><?php echo cc_d($var0); ?></td>
          <td><?php echo $r['cash_short']>0?'<span class="sv">'.number_format($r['cash_short'],2).'</span>':'<span class="dash">—</span>'; ?></td>
          <td><?php echo $r['good_short']>0?'<span class="sv">'.number_format($r['good_short'],2).'</span>':'<span class="dash">—</span>'; ?></td>
          <td class="col-p" id="prow-charge-<?php echo $i; ?>">
            <?php if($r['p_charge']>0): ?><span class="val-charge"><?php echo number_format($r['p_charge'],2); ?></span>
            <?php else: ?><span class="dash">—</span><?php endif; ?>
          </td>
          <td class="col-p" id="prow-absorb-<?php echo $i; ?>">
            <?php if($r['p_absorb']>0): ?><span class="val-absorb"><?php echo number_format($r['p_absorb'],2); ?></span>
            <?php else: ?><span class="dash">—</span><?php endif; ?>
          </td>
          <td id="prow-var-<?php echo $i; ?>"><?php echo cc_pvar($r['p_variance']); ?></td>
          <td style="text-align:center;">
            <button class="btn-pay-alloc<?php echo $r['is_paid'] ? ' paid' : ''; ?>"
                    id="payallocbtn-<?php echo $i; ?>"
                    onclick="openPayModal(<?php echo $i; ?>)">
              <i class="fa-solid fa-<?php echo $r['is_paid'] ? 'check' : 'file-invoice-dollar'; ?>"></i>
              <?php echo $r['is_paid'] ? 'Paid' : 'Pay'; ?>
            </button>
          </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
            <td class="tl" colspan="3">TOTAL — <?php echo count($rows); ?> records</td>
            <td><?php echo cc_t($totals['loading']); ?></td>
            <td><?php echo cc_t($totals['total_adj']); ?></td>
            <td><?php echo cc_t($totals['final_bill']); ?></td>
            <td><?php echo cc_t($totals['sinv']); ?></td>
            <td><?php echo cc_t($totals['canceled_value']); ?></td>
            <td><?php echo cc_d($totals['inv_diff']); ?></td>
            <td><?php echo cc_t($totals['cash_paid']); ?></td>
            <td><?php echo cc_t($totals['cheque_paid']); ?></td>
            <td><?php echo cc_t($totals['credit']); ?></td>
            <td><?php echo cc_d($totals['pay_diff']); ?></td>
            <td><?php echo cc_t($totals['cc_daily_sale']); ?></td>
            <td><?php echo cc_t($totals['cc_rcvd_credit']); ?></td>
            <td><?php echo cc_t($totals['cc_rcvd_rtn_chq']); ?></td>
            <td><?php echo cc_t($totals['cc_rcvd_rtn_chgs']); ?></td>
            <td><?php echo cc_t($totals['cc_rcvd_sent_back']); ?></td>
            <td><?php echo cc_t($totals['cc_total']); ?></td>
            <td><?php echo cc_t($totals['sr_daily_sale']); ?></td>
            <td><?php echo cc_t($totals['sr_rcvd_credit']); ?></td>
            <td><?php echo cc_t($totals['sr_rcvd_rtn_chq']); ?></td>
            <td><?php echo cc_t($totals['sr_rcvd_rtn_chgs']); ?></td>
            <td><?php echo cc_t($totals['sr_rcvd_sent_back']); ?></td>
            <td><?php echo cc_t($totals['sr_total']); ?></td>
            <td><?php echo cc_t($totals['total_coll']); ?></td>
            <td><?php echo cc_t($totals['banked']); ?></td>
            <td><?php echo cc_t($totals['handed']); ?></td>
            <?php $fv=$totals['total_coll']-$totals['banked']-$totals['handed']; ?>
            <td><?php echo cc_d($fv); ?></td>
            <td><?php echo cc_t($totals['cash_short']); ?></td>
            <td><?php echo cc_t($totals['good_short']); ?></td>
            <td><?php echo cc_t($totals['p_charge']); ?></td>
            <td><?php echo cc_t($totals['p_absorb']); ?></td>
            <td></td><td></td>
        </tr>
      </tfoot>
    </table>
    </div><!-- /mainScroll -->
</div>
<?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════
     COLLECTION PAYMENT DETAILS MODAL
══════════════════════════════════════════════════ -->
<div class="modal-overlay" id="collPayModal" onclick="if(event.target===this)closeCollModal()">
  <div class="coll-modal-box">
    <div class="pmo-header" style="padding:14px 18px;">
      <div style="flex:1;min-width:0;">
        <div class="pmo-title" id="cm_title" style="font-size:15px;">Payment Details</div>
        <div style="display:flex;align-items:center;gap:8px;margin-top:5px;flex-wrap:wrap;">
          <span id="cm_badge" class="cm-badge all"></span>
          <span style="font-size:11px;color:#9ca3af;" id="cm_subtitle">—</span>
        </div>
      </div>
      <button class="pmo-close" onclick="closeCollModal()">×</button>
    </div>
    <div class="cm-header-bar">
      <div style="font-size:12px;color:#6b7280;">
        <span id="cm_count">0</span> payments
      </div>
      <div style="display:flex;align-items:center;gap:6px;">
        <span style="font-size:11px;color:#9ca3af;">Total:</span>
        <span style="font-size:16px;font-weight:800;color:#0f766e;font-family:'JetBrains Mono',monospace;" id="cm_total">—</span>
      </div>
    </div>
    <div class="cm-body">
      <table class="cm-tbl">
        <thead>
          <tr id="cm_thead_row">
            <th style="width:32px;">#</th>
            <th>Invoice No</th>
            <th>T-Code</th>
            <th>Customer</th>
            <th class="ar">Inv. Value</th>
            <th class="ar">Amount</th>
            <th>Source</th>
            <th>Collected By</th>
            <th>Remarks</th>
            <th>Time</th>
          </tr>
        </thead>
        <tbody id="cm_tbody">
          <tr><td colspan="10" class="cm-empty">—</td></tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="5" style="color:#94a3b8;" id="cm_tfoot_lbl">TOTAL</td>
            <td class="ar" id="cm_tfoot_val" style="font-family:'JetBrains Mono',monospace;">—</td>
            <td colspan="4"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════
     PAY ALLOCATION MODAL
══════════════════════════════════════════════════ -->
<div class="modal-overlay" id="payAllocModal" onclick="if(event.target===this)closePayModal()">
  <div class="pay-modal-box">
    <div class="pmo-header">
      <div>
        <div class="pmo-title">
          <i class="fa-solid fa-file-invoice-dollar" style="color:#7c3aed;"></i> Pay Allocation
        </div>
        <div style="font-size:12px;color:#9ca3af;margin-top:3px;" id="pm_sub">—</div>
      </div>
      <button class="pmo-close" onclick="closePayModal()">×</button>
    </div>
    <div class="pmo-info">
      <div class="pmo-info-cell">
        <div class="pmo-info-lbl">Sales Rep</div>
        <div class="pmo-info-val" id="pm_sr">—</div>
      </div>
      <div class="pmo-info-cell">
        <div class="pmo-info-lbl">Date</div>
        <div class="pmo-info-val" id="pm_date">—</div>
      </div>
      <div class="pmo-info-cell">
        <div class="pmo-info-lbl">Total Cash Collected</div>
        <div class="pmo-info-val" id="pm_total">—</div>
      </div>
      <div class="pmo-info-cell hi">
        <div class="pmo-info-lbl">Short / Excess</div>
        <div class="pmo-info-val" id="pm_seval">—</div>
      </div>
    </div>
    <div class="pmo-body">
      <div class="pmo-section">
        <div class="pmo-sec-head red">
          <span><i class="fa-solid fa-user-minus"></i>&nbsp; Charge to Employee</span>
          <span class="pmo-sec-total" id="pm_charge_total">0.00</span>
        </div>
        <div class="pmo-sec-body">
          <div id="pm_chargeRows"></div>
          <button class="add-emp-btn" onclick="addPayEmpRow()">
            <i class="fa-solid fa-plus"></i> Add Employee
          </button>
        </div>
      </div>
      <div class="pmo-section">
        <div class="pmo-sec-head blue">
          <span><i class="fa-solid fa-building"></i>&nbsp; Absorb by Company</span>
          <span class="pmo-sec-total" id="pm_absorb_total">0.00</span>
        </div>
        <div class="pmo-sec-body">
          <div class="absorb-row">
            <label class="absorb-lbl"><i class="fa-solid fa-building" style="color:#1e40af;"></i> Company Absorption Amount</label>
            <input type="number" step="0.01" min="0" class="absorb-inp"
                   id="pm_absorb_amt" placeholder="0.00" oninput="recalcPayAlloc()">
          </div>
        </div>
      </div>
      <div class="pmo-totals">
        <div class="ptb-cell">
          <div class="ptb-lbl">Short / Excess</div>
          <div class="ptb-val" style="color:#dc2626;" id="pm_ptb_seval">0.00</div>
        </div>
        <div class="ptb-cell">
          <div class="ptb-lbl">Charged + Absorbed</div>
          <div class="ptb-val" style="color:#1d4ed8;" id="pm_ptb_alloc">0.00</div>
        </div>
        <div class="ptb-cell">
          <div class="ptb-lbl">Variance</div>
          <div class="ptb-val" style="color:#d97706;" id="pm_ptb_var">0.00</div>
        </div>
      </div>
    </div>
    <div class="pmo-ftr">
      <button class="btn btn-secondary btn-sm" onclick="closePayModal()">Cancel</button>
      <button class="btn btn-primary btn-sm" id="savePayBtn" onclick="savePayAlloc()">
        <i class="fa-solid fa-floppy-disk"></i> Save Pay Allocation
      </button>
    </div>
  </div>
</div>

<div id="ccToast"></div>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>

<?php
$rows_js = [];
foreach ($rows as $i => $r) {
    $rows_js[] = [
        'idx'       => $i,
        'sr_code'   => $r['sr_code'],
        'del_date'  => $r['del_date'],
        'rep_name'  => $names[$r['sr_code']] ?? '',
        'pay_date'  => $r['del_date'],
        'total'     => floatval($r['total_coll']),
        'se_val'    => floatval($r['bank_diff']),
        'loading'        => floatval($r['loading']),
        'total_adj'      => floatval($r['total_adj']),
        'final_bill'     => floatval($r['final_bill']),
        'sinv'           => floatval($r['sinv']),
        'canceled_value' => floatval($r['canceled_value']),
        'inv_diff'       => floatval($r['inv_diff']),
        'cash_paid'      => floatval($r['cash_paid']),
        'cheque_paid'    => floatval($r['cheque_paid']),
        'credit'         => floatval($r['credit']),
        'pay_diff'       => floatval($r['pay_diff']),
        'cc_daily_sale'     => floatval($r['cc_daily_sale']),
        'cc_rcvd_credit'    => floatval($r['cc_rcvd_credit']),
        'cc_rcvd_rtn_chq'   => floatval($r['cc_rcvd_rtn_chq']),
        'cc_rcvd_rtn_chgs'  => floatval($r['cc_rcvd_rtn_chgs']),
        'cc_rcvd_sent_back' => floatval($r['cc_rcvd_sent_back']),
        'cc_total'          => floatval($r['cc_total']),
        'sr_daily_sale'     => floatval($r['sr_daily_sale']),
        'sr_rcvd_credit'    => floatval($r['sr_rcvd_credit']),
        'sr_rcvd_rtn_chq'   => floatval($r['sr_rcvd_rtn_chq']),
        'sr_rcvd_rtn_chgs'  => floatval($r['sr_rcvd_rtn_chgs']),
        'sr_rcvd_sent_back' => floatval($r['sr_rcvd_sent_back']),
        'sr_total'          => floatval($r['sr_total']),
        'total_coll'     => floatval($r['total_coll']),
        'banked'         => floatval($r['banked']),
        'handed'         => floatval($r['handed']),
        'bank_diff'      => floatval($r['bank_diff']),
        'cash_short'     => floatval($r['cash_short']),
        'good_short'     => floatval($r['good_short']),
        'p_charge'   => floatval($r['p_charge']),
        'p_absorb'   => floatval($r['p_absorb']),
        'p_variance' => $r['p_variance'],
        'employees'  => $r['p_employees'],
        'is_paid'    => $r['is_paid'],
    ];
}
?>
<script>
var ROWS_DATA    = <?php echo json_encode($rows_js); ?>;
var payEmpCount  = 0;
var currentPayIdx= null;

var EMP_OPTIONS = `<?php foreach($emp_list as $e): ?>
  <option value="<?php echo intval($e['id']); ?>"><?php echo htmlspecialchars($e['emp_code'].' — '.$e['emp_name']); ?></option>
<?php endforeach; ?>`;

/* ── number helpers ── */
function fmtNum(n){ return parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,','); }

function fmtPayVar(v){
    if(v===null||v===undefined) return '<span style="color:#d1d5db;">—</span>';
    v=parseFloat(v);
    if(Math.abs(v)<0.005) return '<span class="pv-ok">0.00 ✓</span>';
    if(v<0) return '<span class="pv-exc">▲ EXCESS '+fmtNum(Math.abs(v))+'</span>';
    return '<span class="pv-sht">▼ SHORT '+fmtNum(v)+'</span>';
}

/* ══════════════════════════════════════════
   COLLECTION PAYMENT DETAILS MODAL
══════════════════════════════════════════ */
const SRC_MAP = {
    'invoice'                  : ['Invoice',    '#1e40af','#dbeafe'],
    'credit_sales'             : ['Credit Sale','#7c3aed','#ede9fe'],
    'credit_sale'              : ['Credit Sale','#7c3aed','#ede9fe'],
    'return_cheque_settlement' : ['Rtn Cheque', '#b45309','#fef3c7'],
    'return_charge_settlement' : ['Rtn Charges','#c2410c','#ffedd5'],
    'sentback_cheque_settlement': ['Sent Back', '#be185d','#fce7f3'],
};

function srcBadge(s){
    const m = SRC_MAP[s||'invoice'] || [s||'—','#475569','#f1f5f9'];
    return `<span class="src-badge" style="background:${m[2]};color:${m[1]};">${m[0]}</span>`;
}
function collBadge(cb){
    const isCC = (cb||'').toLowerCase().trim()==='cc';
    const isSR = (cb||'').toLowerCase().trim()==='sr';
    const bg   = isCC?'#dcfce7':isSR?'#ccfbf1':'#f1f5f9';
    const col  = isCC?'#166534':isSR?'#0f766e':'#475569';
    const bdr  = isCC?'#86efac':isSR?'#99f6e4':'#cbd5e1';
    return `<span class="coll-badge" style="background:${bg};color:${col};border:1px solid ${bdr};">${(cb||'—').toUpperCase()}</span>`;
}

function openCollModal(el){
    const sr     = el.dataset.sr;
    const date   = el.dataset.date;
    const coll   = el.dataset.coll;   // cc | sr | ''
    const src    = el.dataset.src;    // invoice|credit|... | ''
    const label  = el.dataset.label;

    /* badge */
    const badge = document.getElementById('cm_badge');
    if(coll==='cc'){badge.textContent='CC Collection';badge.className='cm-badge cc';}
    else if(coll==='sr'){badge.textContent='SR Collection';badge.className='cm-badge sr';}
    else{badge.textContent='All';badge.className='cm-badge all';}

    document.getElementById('cm_title').textContent    = label;
    document.getElementById('cm_subtitle').textContent = sr+' · '+date;
    document.getElementById('cm_count').textContent    = '…';
    document.getElementById('cm_total').textContent    = '…';
    document.getElementById('cm_tfoot_val').textContent= '…';
    document.getElementById('cm_tbody').innerHTML =
        '<tr><td colspan="10" class="cm-empty"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</td></tr>';

    /* set thead color */
    const hdrColor = (coll==='cc') ? '#1a6640' : (coll==='sr') ? '#0f766e' : '#374151';
    document.getElementById('cm_thead_row').style.background = hdrColor;

    document.getElementById('collPayModal').classList.add('open');

    fetch(`get_coll_payments.php?sr_code=${encodeURIComponent(sr)}&date=${encodeURIComponent(date)}&collected_by=${encodeURIComponent(coll)}&source=${encodeURIComponent(src)}`)
        .then(r=>r.json())
        .then(res=>{
            if(!res.success){
                document.getElementById('cm_tbody').innerHTML=
                    '<tr><td colspan="10" class="cm-empty" style="color:#dc2626;">Error: '+(res.message||'Unknown')+'</td></tr>';
                return;
            }
            renderCollPayments(res.payments, res.total, res.count);
        })
        .catch(err=>{
            document.getElementById('cm_tbody').innerHTML=
                '<tr><td colspan="10" class="cm-empty" style="color:#dc2626;">Network error: '+err.message+'</td></tr>';
        });
}

function renderCollPayments(payments, total, count){
    document.getElementById('cm_count').textContent = count;
    document.getElementById('cm_total').textContent = 'Rs. '+fmtNum(total);
    document.getElementById('cm_tfoot_lbl').textContent = 'TOTAL — '+count+' payments';
    document.getElementById('cm_tfoot_val').textContent = 'Rs. '+fmtNum(total);

    if(!payments.length){
        document.getElementById('cm_tbody').innerHTML=
            '<tr><td colspan="10" class="cm-empty">No records found</td></tr>';
        return;
    }

    let html='';
    payments.forEach((p,i)=>{
        const invVal = p.invoice_value ? 'Rs. '+fmtNum(p.invoice_value) : '—';
        const time   = p.created_at ? p.created_at.substring(11,16) : '—';
        html += `<tr>
            <td style="color:#9ca3af;font-size:11px;">${i+1}</td>
            <td style="font-family:'JetBrains Mono',monospace;font-weight:700;font-size:11px;">${p.invoice_num||'—'}</td>
            <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#58626e;">${p.t_code||'—'}</td>
            <td style="font-size:11.5px;">${p.customer_name||'—'}</td>
            <td class="ar" style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#58626e;">${invVal}</td>
            <td class="ar" style="font-family:'JetBrains Mono',monospace;font-weight:700;font-size:12px;color:#166534;">Rs. ${fmtNum(p.amount)}</td>
            <td>${srcBadge(p.payment_source)}</td>
            <td>${collBadge(p.collected_by)}</td>
            <td style="color:#9ca3af;font-size:11px;">${p.remarks||'—'}</td>
            <td style="color:#9ca3af;font-size:10px;font-family:'JetBrains Mono',monospace;">${time}</td>
        </tr>`;
    });
    document.getElementById('cm_tbody').innerHTML = html;
}

function closeCollModal(){
    document.getElementById('collPayModal').classList.remove('open');
}

/* ══════════════════════════════════════════
   EXPORT TO EXCEL (SheetJS)
══════════════════════════════════════════ */
function exportToExcel(){
    if(!ROWS_DATA||!ROWS_DATA.length){ showCCToast('No data to export.','err'); return; }

    var group = [
        '','','',
        'Daily Invoice Details','','','','','',
        'Invoice Payments','','','',
        'CC Collection','','','','','',
        'SR Collection','','','','','',
        'Total','Bank Deposit','','',
        'Shortage','',
        'Pay Allocation','',''
    ];
    var headers = [
        'Rep Code','Rep Name','Del. Date',
        'Loading Value','Total Adjustment','Final Bill Value','Secondary Invoice','Canceled Value','Inv Diff',
        'Cash Paid','Cheque Paid','Credit','Pay Diff',
        'CC Daily Sale','CC Rcvd Credit','CC Rtn Cheque','CC Rtn Chgs','CC Sent Back','CC Total',
        'SR Daily Sale','SR Rcvd Credit','SR Rtn Cheque','SR Rtn Chgs','SR Sent Back','SR Total',
        'Total Cash Coll.',
        'CC Deposit Bank','Handed to Office','Bank Variance',
        'Cash Short','Good Short',
        'Charge to Emp','Absorb by Co.','Pay Variance'
    ];

    var data=[];
    ROWS_DATA.forEach(function(r){
        data.push([
            r.sr_code, r.rep_name, r.del_date,
            r.loading, r.total_adj, r.final_bill, r.sinv, r.canceled_value, r.inv_diff,
            r.cash_paid, r.cheque_paid, r.credit, r.pay_diff,
            r.cc_daily_sale, r.cc_rcvd_credit, r.cc_rcvd_rtn_chq, r.cc_rcvd_rtn_chgs, r.cc_rcvd_sent_back, r.cc_total,
            r.sr_daily_sale, r.sr_rcvd_credit, r.sr_rcvd_rtn_chq, r.sr_rcvd_rtn_chgs, r.sr_rcvd_sent_back, r.sr_total,
            r.total_coll,
            r.banked, r.handed, r.bank_diff,
            r.cash_short, r.good_short,
            r.p_charge, r.p_absorb, r.p_variance!==null?r.p_variance:''
        ]);
    });

    var tKeys=['loading','total_adj','final_bill','sinv','canceled_value','inv_diff',
               'cash_paid','cheque_paid','credit','pay_diff',
               'cc_daily_sale','cc_rcvd_credit','cc_rcvd_rtn_chq','cc_rcvd_rtn_chgs','cc_rcvd_sent_back','cc_total',
               'sr_daily_sale','sr_rcvd_credit','sr_rcvd_rtn_chq','sr_rcvd_rtn_chgs','sr_rcvd_sent_back','sr_total',
               'total_coll','banked','handed','bank_diff','cash_short','good_short','p_charge','p_absorb'];
    var sums={};
    tKeys.forEach(function(k){sums[k]=0;});
    ROWS_DATA.forEach(function(r){tKeys.forEach(function(k){sums[k]+=parseFloat(r[k])||0;});});
    var totRow=['TOTAL','('+ROWS_DATA.length+' records)',''];
    tKeys.forEach(function(k){totRow.push(sums[k]);});
    totRow.push('');
    data.push(totRow);

    var aoa=[group, headers].concat(data);
    var ws=XLSX.utils.aoa_to_sheet(aoa);
    ws['!merges']=[
        {s:{r:0,c:0},e:{r:0,c:2}},
        {s:{r:0,c:3},e:{r:0,c:8}},
        {s:{r:0,c:9},e:{r:0,c:12}},
        {s:{r:0,c:13},e:{r:0,c:18}},
        {s:{r:0,c:19},e:{r:0,c:24}},
        {s:{r:0,c:25},e:{r:0,c:25}},
        {s:{r:0,c:26},e:{r:0,c:28}},
        {s:{r:0,c:29},e:{r:0,c:30}},
        {s:{r:0,c:31},e:{r:0,c:33}},
    ];
    var numFmt='#,##0.00';
    for(var R=2;R<aoa.length;R++){
        for(var C=3;C<34;C++){
            var addr=XLSX.utils.encode_cell({r:R,c:C});
            if(ws[addr]&&typeof ws[addr].v==='number'){ws[addr].t='n';ws[addr].z=numFmt;}
        }
    }
    var wb=XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb,ws,'Cash Collection');
    var dfrom='<?php echo date("d-M-Y",strtotime($date_from)); ?>';
    var dto='<?php echo date("d-M-Y",strtotime($date_to)); ?>';
    var srTag='<?php echo $f_sr?"_".htmlspecialchars($f_sr):""; ?>';
    XLSX.writeFile(wb,'Cash_Collection_'+dfrom+'_to_'+dto+srTag+'.xlsx');
    showCCToast('Excel exported!','ok');
}

/* ══════════════════════════════════════════
   PAY ALLOCATION MODAL
══════════════════════════════════════════ */
function openPayModal(idx){
    currentPayIdx=idx; payEmpCount=0;
    document.getElementById('pm_chargeRows').innerHTML='';
    document.getElementById('pm_absorb_amt').value='';
    const rd=ROWS_DATA[idx];
    const seAbs=Math.abs(rd.se_val);
    document.getElementById('pm_sub').textContent   =rd.sr_code+' · '+rd.pay_date;
    document.getElementById('pm_sr').textContent    =rd.sr_code;
    document.getElementById('pm_date').textContent  =rd.pay_date;
    document.getElementById('pm_total').textContent ='Rs. '+fmtNum(rd.total);
    document.getElementById('pm_seval').textContent =fmtNum(seAbs)+(rd.se_val<0?' (EXCESS)':rd.se_val>0?' (SHORT)':' (Balanced)');
    document.getElementById('pm_ptb_seval').textContent=fmtNum(seAbs);
    document.getElementById('pm_ptb_alloc').textContent='0.00';
    document.getElementById('pm_charge_total').textContent='0.00';
    document.getElementById('pm_absorb_total').textContent='0.00';
    document.getElementById('pm_ptb_var').textContent=fmtNum(seAbs);
    if(rd.employees&&rd.employees.length) rd.employees.forEach(e=>addPayEmpRow(e.employee_id,e.amount));
    if(rd.p_absorb>0){ document.getElementById('pm_absorb_amt').value=rd.p_absorb; recalcPayAlloc(); }
    document.getElementById('payAllocModal').classList.add('open');
}
function closePayModal(){ document.getElementById('payAllocModal').classList.remove('open'); currentPayIdx=null; }

function addPayEmpRow(empId,amt){
    const rid='er_'+(payEmpCount++);
    const div=document.createElement('div');
    div.className='emp-row'; div.id=rid;
    div.style.cssText='display:flex;align-items:center;gap:8px;margin-bottom:8px;';
    div.innerHTML=`
      <select class="emp-sel" style="flex:1;border:1px solid #e5e5e5;border-radius:6px;padding:7px 10px;font-size:13px;">
        <option value="">— Select Employee —</option>${EMP_OPTIONS}
      </select>
      <input type="number" step="0.01" min="0" class="emp-amt" placeholder="0.00"
             style="width:110px;border:1px solid #e5e5e5;border-radius:6px;padding:7px 10px;font-size:13px;text-align:right;"
             oninput="recalcPayAlloc()">
      <button type="button" onclick="document.getElementById('${rid}').remove();recalcPayAlloc();"
              style="background:none;border:none;cursor:pointer;color:#dc2626;font-size:16px;padding:4px;">✕</button>`;
    document.getElementById('pm_chargeRows').appendChild(div);
    if(empId) div.querySelector('.emp-sel').value=empId;
    if(amt)   div.querySelector('.emp-amt').value=parseFloat(amt).toFixed(2);
    recalcPayAlloc();
}

function recalcPayAlloc(){
    let tc=0;
    document.querySelectorAll('#pm_chargeRows .emp-row').forEach(row=>{tc+=parseFloat(row.querySelector('.emp-amt').value||0);});
    const absorb=parseFloat(document.getElementById('pm_absorb_amt').value||0);
    const seAbs=currentPayIdx!==null?Math.abs(ROWS_DATA[currentPayIdx].se_val):0;
    const alloc=tc+absorb; const vari=seAbs-alloc;
    document.getElementById('pm_charge_total').textContent=fmtNum(tc);
    document.getElementById('pm_absorb_total').textContent=fmtNum(absorb);
    document.getElementById('pm_ptb_alloc').textContent=fmtNum(alloc);
    const vEl=document.getElementById('pm_ptb_var');
    vEl.textContent=(vari>0?'+':'')+fmtNum(vari);
    vEl.style.color=Math.abs(vari)<0.005?'#16a34a':vari<0?'#d97706':'#dc2626';
}

function savePayAlloc(){
    if(currentPayIdx===null) return;
    const btn=document.getElementById('savePayBtn');
    const charges=[]; let valid=true;
    document.querySelectorAll('#pm_chargeRows .emp-row').forEach(row=>{
        const empId=row.querySelector('.emp-sel').value;
        const empLbl=row.querySelector('.emp-sel').selectedOptions[0]?.text||'';
        const amt=parseFloat(row.querySelector('.emp-amt').value||0);
        if(!empId){showCCToast('Please select an employee.','err');valid=false;return;}
        if(!(amt>0)){showCCToast('Amount must be > 0.','err');valid=false;return;}
        charges.push({employee_id:parseInt(empId),employee_name:empLbl,amount:amt});
    });
    if(!valid) return;
    const rd=ROWS_DATA[currentPayIdx];
    const absorb=parseFloat(document.getElementById('pm_absorb_amt').value||0)||0;
    const seAbs=Math.abs(rd.se_val);
    const tc=charges.reduce((s,r)=>s+r.amount,0);
    const vari=seAbs-(tc+absorb);
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    fetch('save_cash_summary_pay.php',{
        method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({pay_date:rd.pay_date,sr_code:rd.sr_code,charges,absorb_amount:absorb,se_value:seAbs,variance:vari})
    })
    .then(r=>r.json())
    .then(res=>{
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Pay Allocation';
        if(res.success){
            const idx=currentPayIdx;
            ROWS_DATA[idx].p_charge=tc; ROWS_DATA[idx].p_absorb=absorb;
            ROWS_DATA[idx].p_variance=vari; ROWS_DATA[idx].employees=charges; ROWS_DATA[idx].is_paid=true;
            document.getElementById('prow-charge-'+idx).innerHTML=tc>0?`<span class="val-charge">${fmtNum(tc)}</span>`:`<span class="dash">—</span>`;
            document.getElementById('prow-absorb-'+idx).innerHTML=absorb>0?`<span class="val-absorb">${fmtNum(absorb)}</span>`:`<span class="dash">—</span>`;
            document.getElementById('prow-var-'+idx).innerHTML=fmtPayVar(vari);
            const pb=document.getElementById('payallocbtn-'+idx);
            if(pb){pb.className='btn-pay-alloc paid';pb.innerHTML='<i class="fa-solid fa-check"></i> Paid';}
            let totC=0,totA=0;
            ROWS_DATA.forEach(r=>{totC+=r.p_charge||0;totA+=r.p_absorb||0;});
            document.getElementById('ft-charge').textContent=fmtNum(totC);
            document.getElementById('ft-absorb').textContent=fmtNum(totA);
            closePayModal(); showCCToast('Pay allocation saved!','ok');
        } else { showCCToast('Error: '+(res.message||'Unknown'),'err'); }
    })
    .catch(err=>{btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Pay Allocation';showCCToast('Network error: '+err.message,'err');});
}

function showCCToast(msg,type){
    const t=document.getElementById('ccToast');
    t.className=type==='ok'?'toast-ok':'toast-err';
    t.textContent=msg; t.style.display='block'; t.style.opacity='1';
    clearTimeout(t._t);
    t._t=setTimeout(()=>{t.style.opacity='0';setTimeout(()=>t.style.display='none',300);},2800);
}

document.getElementById('ccf')?.addEventListener('submit',()=>{
    const b=document.getElementById('gBtn');
    b.disabled=true; b.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Generating…';
});

/* ── Top scrollbar sync ── */
(function(){
    const top=document.getElementById('topScroll');
    const main=document.getElementById('mainScroll');
    if(!top||!main) return;
    const inner=document.getElementById('topScrollInner');
    function setW(){ const tbl=main.querySelector('table.cct'); if(tbl) inner.style.width=tbl.scrollWidth+'px'; }
    setW(); window.addEventListener('resize',setW);
    let syncing=false;
    top.addEventListener('scroll',()=>{ if(syncing)return; syncing=true; main.scrollLeft=top.scrollLeft; syncing=false; });
    main.addEventListener('scroll',()=>{ if(syncing)return; syncing=true; top.scrollLeft=main.scrollLeft; syncing=false; });
})();
</script>
<?php include 'footer.php'; ?>