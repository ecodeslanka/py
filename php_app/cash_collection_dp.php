<?php
ob_start();
include 'config.php';
include 'header.php';

$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$f_dp      = trim($_GET['delivery_person'] ?? '');
$submitted = isset($_GET['search']);

$df     = mysqli_real_escape_string($conn, $date_from);
$dt     = mysqli_real_escape_string($conn, $date_to);
$dp_esc = $f_dp ? mysqli_real_escape_string($conn, $f_dp) : '';

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
        $cc_rcvd_rtn_chgs  = floatval($cbm['cc_rcvd_rtn_chgs']   ?? 0);
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

/* ── helpers ── */
function dp_v($v){
    $n = floatval($v);
    return $n == 0 ? '<span class="dash">—</span>' : '<span class="num">'.number_format($n,2).'</span>';
}
function dp_se($v){
    $n = floatval($v);
    if(abs($n) < 0.005) return '<span class="d-ok">0.00 ✓</span>';
    if($n < 0)          return '<span class="d-exc">▲ '.number_format(abs($n),2).'</span>';
    return '<span class="d-sht">▼ '.number_format($n,2).'</span>';
}
function dp_t($v){$n=floatval($v);return $n==0?'—':number_format($n,2);}
function dp_var_split($v){
    $n = floatval($v);
    if(abs($n) < 0.005) return '<span class="d-ok">0.00 ✓</span>';
    if($n < 0)          return '<span class="d-exc">▲ '.number_format(abs($n),2).'</span>';
    return '<span class="d-sht">▼ '.number_format($n,2).'</span>';
}
function dp_short_split($v){
    $n = floatval($v);
    if($n <= 0) return '<span class="dash">—</span>';
    return '<span class="sv">'.number_format($n,2).'</span>';
}
function dp_pvar($v){
    if($v===null||$v===undefined_val()) return '<span style="color:#d1d5db;">—</span>';
    $v=floatval($v);
    if(abs($v)<0.005) return '<span class="pv-ok">0.00 ✓</span>';
    if($v>0) return '<span class="pv-sht-pay">▼ '.number_format($v,2).'</span>';
    return '<span class="pv-exc-pay">▲ '.number_format(abs($v),2).'</span>';
}
function undefined_val(){ return PHP_INT_MIN; }

/* build col-empty flags — check all rows for zero in each optional column */
$col_empty = [
    'cash_paid'        => true,
    'cheque_paid'      => true,
    'credit'           => true,
    'pay_diff'         => true,
    'cc_rcvd_credit'   => true,
    'cc_rcvd_rtn_chq'  => true,
    'cc_rcvd_rtn_chgs' => true,
    'cc_rcvd_sent_back'=> true,
    'handed_cc'        => true,
    'short_cc'         => true,
];
if ($submitted) {
    foreach ($rows as $r) {
        foreach ($col_empty as $col => &$empty) {
            if (abs(floatval($r[$col])) > 0.004) $empty = false;
        }
        unset($empty);
    }
}
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');
@import url('https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
:root{
  --bg:#eef0f3;--surface:#fff;--bdr:#d2d6db;--bdrs:#e4e7ec;--tx:#1a1f2e;--txm:#58626e;--txs:#9aa3ad;
  --fn:'Inter',sans-serif;--mn:'JetBrains Mono',monospace;--r:8px;
  --sh:0 1px 3px rgba(0,0,0,.07),0 4px 14px rgba(0,0,0,.05);
  --g0:#2d3748;--g0s:#3a4455;--g1:#1e4d8c;--g1s:#16408a;--g2:#7a3f10;--g2s:#6a3510;
  --g3:#1a6640;--g3s:#155535;
  --total-bg:#0f172a;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:12px;}
.pg{padding:12px 12px 40px;}
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:10px;}
.pg-h1{font-size:19px;font-weight:800;letter-spacing:-.02em;}
.pg-h1 em{color:var(--g3);font-style:normal;}
.dpill{display:inline-flex;align-items:center;gap:5px;background:#f0fdf4;border:1px solid #86efac;border-radius:20px;padding:4px 11px;font-size:11px;font-weight:700;color:#166534;font-family:var(--mn);}
.fbar{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:8px 12px;margin-bottom:10px;display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;box-shadow:var(--sh);}
.fg{display:flex;flex-direction:column;gap:2px;}
.fg label{font-size:10px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.07em;}
.fg input,.fg select{padding:6px 9px;border:1.5px solid var(--bdr);border-radius:6px;font-size:12px;font-family:var(--fn);color:var(--tx);background:#fff;}
.fg input:focus,.fg select:focus{outline:none;border-color:var(--g3);}
.btn-go{display:inline-flex;align-items:center;gap:5px;padding:7px 16px;background:var(--g0);color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;font-family:var(--fn);cursor:pointer;}
.btn-go:hover{background:#3a4560;}
.btn-rst{display:inline-flex;align-items:center;gap:4px;padding:7px 11px;background:#f0f0f0;color:var(--txm);border:1px solid var(--bdr);border-radius:6px;font-size:12px;font-weight:600;font-family:var(--fn);cursor:pointer;text-decoration:none;}
.sc-row{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px;}
.sc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:8px 10px;box-shadow:var(--sh);border-left:3px solid #e5e7eb;}
.sc.blue{border-left-color:#3b82f6;}.sc.green{border-left-color:#22c55e;}.sc.red{border-left-color:#ef4444;}
.sc-lbl{font-size:9px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.sc-val{font-size:13px;font-weight:800;}
.tc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);overflow:hidden;box-shadow:var(--sh);}
.tc-bar{display:flex;justify-content:space-between;align-items:center;padding:7px 11px;border-bottom:1px solid var(--bdrs);flex-wrap:wrap;gap:6px;}
.tc-ttl{font-size:13px;font-weight:700;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.pill{padding:1px 7px;border-radius:12px;font-size:10px;font-weight:700;}
.p-green{background:#dcfce7;color:#166534;}.p-blue{background:#dbeafe;color:#1e40af;}.p-slate{background:#f1f5f9;color:#475569;}
.top-scroll-wrap{overflow-x:auto;overflow-y:hidden;height:10px;margin-bottom:1px;border-radius:4px;}
.top-scroll-inner{height:1px;}
.tscroll{overflow-x:auto;}
table.dpt{width:100%;border-collapse:collapse;font-size:11px;}
.dpt .G th{padding:4px 4px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#fff;text-align:center;white-space:nowrap;border-right:2px solid rgba(255,255,255,.18);}
.dpt .G th.tl{text-align:left;}.dpt .G th:last-child{border-right:none;}
.dpt .S th{padding:3px 4px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:rgba(255,255,255,.88);text-align:center;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);border-bottom:2px solid var(--bdr);}
.dpt .S th.tl{text-align:left;}.dpt .S th:last-child{border-right:none;}
.G .h0{background:var(--g0);}.G .h1{background:var(--g1);}.G .h2{background:var(--g2);}
.G .hcc{background:#1a6640;}.G .htc{background:#374151;}.G .hdep{background:#1e4d8c;}
.G .hvar{background:#7c2d12;}.G .hnew{background:#312e81;}.G .h6{background:#5b21b6;}
.S .s0{background:var(--g0s);}.S .s1{background:var(--g1s);}.S .s2{background:var(--g2s);}
.S .scc{background:#155535;}.S .stc{background:#2d3748;}
.S .sdep_cc{background:#14532d;}.S .sbo_cc{background:#5b21b6;}
.S .svar_cc{background:#7c2d12;}.S .ssht_cc{background:#7f1d1d;}
.S .snew{background:#272069;}.S .s6{background:#4c1d95;}
.dpt .TH td{background:#e0edff;color:#1e3a8a;font-weight:800;font-size:10.5px;padding:4px 4px;text-align:right;border-bottom:2px solid #93c5fd;white-space:nowrap;font-family:var(--mn);}
.dpt .TH td.tl{text-align:left;font-family:var(--fn);}
.dpt tbody tr td{background:#fff;}
.dpt tbody tr.stripe td{background:#fafbfc;}
.dpt tbody tr:hover td{background:#eff6ff!important;}
.dpt tbody td{padding:3px 4px;text-align:right;white-space:nowrap;}
.dpt tbody td.tl{text-align:left;}.dpt tbody td.tc{text-align:center;}
.dpt tbody td.rc{font-weight:700;font-size:11px;}
.dpt tbody td.dt{font-family:var(--mn);font-size:10px;color:var(--txm);}
.dpt tbody td.fb{font-weight:700;color:#1e40af;}
.dpt tbody td.tv{font-weight:700;color:#166534;}
.dpt tbody td.col-c{background:rgba(26,102,64,.06);}
.dpt tbody tr.stripe td.col-c{background:rgba(26,102,64,.10);}
.dpt tbody tr:hover td.col-c{background:#f0fdf4!important;}
.dpt tbody td.col-dep-cc{background:rgba(22,101,52,.07);}
.dpt tbody tr.stripe td.col-dep-cc{background:rgba(22,101,52,.12);}
.dpt tbody tr:hover td.col-dep-cc{background:#dcfce7!important;}
.dpt tbody td.col-bo-cc{background:rgba(109,40,217,.07);}
.dpt tbody tr.stripe td.col-bo-cc{background:rgba(109,40,217,.12);}
.dpt tbody tr:hover td.col-bo-cc{background:#f5f3ff!important;}
.dpt tbody td.col-var-cc{background:rgba(154,52,18,.06);}
.dpt tbody tr.stripe td.col-var-cc{background:rgba(154,52,18,.10);}
.dpt tbody tr:hover td.col-var-cc{background:#fff7ed!important;}
.dpt tbody td.col-sht-cc{background:rgba(153,27,27,.07);}
.dpt tbody tr.stripe td.col-sht-cc{background:rgba(153,27,27,.12);}
.dpt tbody tr:hover td.col-sht-cc{background:#fee2e2!important;}
.dpt tbody td.col-new{background:rgba(49,46,129,.07);}
.dpt tbody tr.stripe td.col-new{background:rgba(49,46,129,.12);}
.dpt tbody tr:hover td.col-new{background:#ede9fe!important;}
.dpt tbody td.col-p{background:#fdf4ff!important;}
.dpt tfoot td{padding:4px 4px;font-weight:800;font-size:11px;background:var(--total-bg);color:#e2e8f0;border-top:2px solid #334155;text-align:right;white-space:nowrap;font-family:var(--mn);}
.dpt tfoot td.tl{text-align:left;color:#94a3b8;font-family:var(--fn);}
.dpt td.stk,.dpt th.stk{position:sticky;left:0;z-index:2;box-shadow:3px 0 8px rgba(0,0,0,.10);}
.dpt thead th.stk{z-index:4;}
.dpt .G th.stk{background:var(--g0);}.dpt .S th.stk{background:var(--g0s);}
.dpt .TH td.stk{background:#e0edff;}.dpt tfoot td.stk{background:var(--total-bg);}
.dpt tbody tr td.stk{background:#fff;}
.dpt tbody tr.stripe td.stk{background:#fafbfc;}
.dpt tbody tr:hover td.stk{background:#eff6ff!important;}
.num{font-family:var(--mn);font-size:10.5px;}
.dash{color:#d1d5db;}
.d-ok{color:#16a34a;font-weight:700;font-family:var(--mn);font-size:10px;}
.d-exc{color:#16a34a;font-weight:700;font-family:var(--mn);font-size:10px;}
.d-sht{color:#dc2626;font-weight:700;font-family:var(--mn);font-size:10px;}
.sv{color:#dc2626;font-weight:700;font-family:var(--mn);font-size:10.5px;}
.val-charge{color:#dc2626;font-weight:600;}
.val-absorb{color:#0369a1;font-weight:600;}
.pv-ok{color:#16a34a;font-weight:700;font-family:var(--mn);}
.pv-exc-pay{color:#d97706;font-weight:700;font-family:var(--mn);}
.pv-sht-pay{color:#dc2626;font-weight:700;font-family:var(--mn);}
.btn-pay-alloc{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border:1px solid #ddd6fe;border-radius:5px;background:#f5f3ff;color:#7c3aed;font-size:10px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s;white-space:nowrap;}
.btn-pay-alloc:hover{background:#7c3aed;color:#fff;}.btn-pay-alloc.paid{background:#f0fdf4;color:#16a34a;border-color:#86efac;}
.empty{text-align:center;padding:50px 20px;color:var(--txs);}
.empty i{font-size:38px;display:block;margin-bottom:10px;opacity:.25;}
.col-hidden{display:none!important;}
@media(max-width:900px){.sc-row{grid-template-columns:1fr 1fr;}}

/* ═══ PAY MODAL ═══ */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1050;display:none;align-items:center;justify-content:center;padding:12px;}
.modal-overlay.open{display:flex;}
.pay-modal-box{background:#fff;border-radius:14px;width:100%;max-width:600px;max-height:96vh;box-shadow:0 32px 80px rgba(0,0,0,.28);display:flex;flex-direction:column;overflow:hidden;}
.pmo-header{padding:16px 20px 12px;border-bottom:1px solid #eff0f1;display:flex;justify-content:space-between;align-items:flex-start;flex-shrink:0;background:#fff;}
.pmo-header-left{display:flex;align-items:center;gap:10px;}
.pmo-icon{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#7c3aed,#6d28d9);display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;flex-shrink:0;}
.pmo-title{font-size:15px;font-weight:700;color:#111827;line-height:1.2;}
.pmo-sub{font-size:11px;color:#9ca3af;margin-top:2px;}
.pmo-close{background:none;border:none;cursor:pointer;color:#9ca3af;font-size:20px;line-height:1;padding:2px 4px;border-radius:6px;transition:all .15s;flex-shrink:0;}
.pmo-close:hover{color:#1f2937;background:#f3f4f6;}
.pmo-info{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px 20px;background:#f9fafb;border-bottom:1px solid #eff0f1;flex-shrink:0;}
.pmo-info-cell{background:#fff;border:1px solid #e5e7eb;border-radius:9px;padding:9px 12px;}
.pmo-info-cell.hi{background:linear-gradient(135deg,#fff5f5,#fff);border-color:#fca5a5;}
.pmo-info-cell.hi-new{background:linear-gradient(135deg,#f0f9ff,#fff);border-color:#7dd3fc;}
.pmo-info-cell.hi-exc{background:linear-gradient(135deg,#f0fdf4,#fff);border-color:#86efac;}
.pmo-info-cell.hi-sht{background:linear-gradient(135deg,#fff5f5,#fff);border-color:#fca5a5;}
.pmo-info-cell.hi-ok{background:linear-gradient(135deg,#f0fdf4,#fff);border-color:#86efac;}
.pmo-info-cell.hi-exc .pmo-info-val{color:#16a34a;font-size:18px;}
.pmo-info-cell.hi-sht .pmo-info-val{color:#dc2626;font-size:18px;}
.pmo-info-cell.hi-ok .pmo-info-val{color:#16a34a;font-size:18px;}
.pmo-info-lbl{font-size:9px;color:#9ca3af;text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:3px;}
.pmo-info-val{font-size:14px;font-weight:700;color:#1f2937;font-family:'JetBrains Mono',monospace;}
.pmo-info-cell.hi .pmo-info-val{color:#dc2626;font-size:18px;}
.pmo-info-cell.hi-new .pmo-info-val{color:#0369a1;font-size:18px;}
.pmo-body{padding:16px 20px;overflow-y:auto;flex:1;-webkit-overflow-scrolling:touch;}
.pmo-section{border:1px solid #e5e7eb;border-radius:10px;margin-bottom:12px;overflow:hidden;}
.pmo-sec-head{display:flex;justify-content:space-between;align-items:center;padding:9px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;}
.pmo-sec-head.red{background:#fef2f2;color:#991b1b;border-bottom:1px solid #fecaca;}
.pmo-sec-head.blue{background:#eff6ff;color:#1e40af;border-bottom:1px solid #bfdbfe;}
.pmo-sec-total{font-size:15px;font-weight:800;letter-spacing:0;font-family:'JetBrains Mono',monospace;}
.pmo-sec-body{padding:12px 14px;background:#fff;}
.emp-row{display:flex;align-items:center;gap:7px;margin-bottom:7px;flex-wrap:wrap;}
.emp-row .select2-container{flex:1;min-width:160px;}
.emp-row .select2-container .select2-selection--single{height:34px;border:1.5px solid #e5e7eb;border-radius:7px;font-size:12px;font-family:var(--fn);display:flex;align-items:center;}
.emp-row .select2-container .select2-selection--single .select2-selection__rendered{line-height:34px;padding-left:9px;font-size:12px;color:#1f2937;}
.emp-row .select2-container .select2-selection--single .select2-selection__arrow{height:32px;}
.emp-row .select2-container--open .select2-selection--single{border-color:#7c3aed;box-shadow:0 0 0 2px rgba(124,58,237,.15);}
.select2-dropdown{border:1.5px solid #7c3aed;border-radius:7px;font-size:12px;font-family:var(--fn);box-shadow:0 8px 24px rgba(0,0,0,.12);}
.select2-search--dropdown .select2-search__field{border:1.5px solid #e5e7eb;border-radius:5px;padding:5px 8px;font-size:12px;font-family:var(--fn);outline:none;}
.select2-search--dropdown .select2-search__field:focus{border-color:#7c3aed;}
.select2-results__option{padding:6px 10px;font-size:12px;}
.select2-results__option--highlighted{background:#7c3aed!important;color:#fff!important;}
.select2-results__option[aria-selected=true]{background:#f3f0ff;color:#5b21b6;}
.emp-row .emp-amt{width:115px;flex-shrink:0;border:1.5px solid #e5e7eb;border-radius:7px;padding:7px 9px;font-size:12px;text-align:right;outline:none;font-family:'JetBrains Mono',monospace;}
.emp-row .emp-amt:focus{border-color:#7c3aed;}
.emp-row .emp-del{background:none;border:none;cursor:pointer;color:#dc2626;font-size:17px;padding:3px 5px;border-radius:5px;line-height:1;flex-shrink:0;}
.emp-row .emp-del:hover{background:#fef2f2;}
.add-emp-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border:1.5px dashed #fca5a5;border-radius:7px;background:#fff;color:#dc2626;font-size:11px;font-weight:600;cursor:pointer;margin-top:5px;font-family:inherit;}
.add-emp-btn:hover{background:#fef2f2;border-color:#f87171;}
.absorb-row{display:flex;align-items:center;gap:9px;flex-wrap:wrap;}
.absorb-lbl{font-size:12px;color:#1e40af;font-weight:600;flex:1;min-width:130px;display:flex;align-items:center;gap:5px;}
.absorb-inp{min-width:115px;flex-shrink:0;border:1.5px solid #bfdbfe;border-radius:7px;padding:7px 11px;font-size:13px;text-align:right;outline:none;font-family:'JetBrains Mono',monospace;}
.absorb-inp:focus{border-color:#1e40af;}
.pmo-totals{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px;padding:12px 14px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;}
.ptb-cell{text-align:center;padding:4px;}
.ptb-lbl{font-size:9px;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;font-weight:600;margin-bottom:5px;}
.ptb-val{font-size:17px;font-weight:800;font-family:'JetBrains Mono',monospace;line-height:1;}
.pmo-ftr{padding:12px 20px;border-top:1px solid #eff0f1;display:flex;justify-content:flex-end;gap:8px;flex-shrink:0;background:#fff;}
.pmo-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:none;font-family:var(--fn);transition:all .15s;white-space:nowrap;}
.pmo-btn-cancel{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;}.pmo-btn-cancel:hover{background:#e2e8f0;}
.pmo-btn-save{background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;}.pmo-btn-save:hover{background:linear-gradient(135deg,#6d28d9,#5b21b6);transform:translateY(-1px);}
.pmo-btn-save:disabled{opacity:.55;cursor:not-allowed;transform:none;}
#dpToast{position:fixed;bottom:20px;right:20px;padding:9px 16px;border-radius:8px;font-size:12px;font-weight:600;z-index:9999;display:none;opacity:0;transition:opacity .3s;}
.toast-ok{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.toast-err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
@media(max-width:600px){
  .pmo-info{grid-template-columns:1fr 1fr;}.pmo-totals{grid-template-columns:1fr;gap:5px;}
  .absorb-row{flex-direction:column;align-items:stretch;}.absorb-inp{width:100%;}
  .emp-row .emp-amt{width:100%;}.pmo-ftr{flex-direction:column-reverse;}
  .pmo-btn{justify-content:center;}.pay-modal-box{border-radius:10px;}
  .pmo-header,.pmo-body,.pmo-ftr,.pmo-info{padding-left:12px;padding-right:12px;}
}
@media(max-width:400px){.pmo-info{grid-template-columns:1fr;}}
</style>

<div class="pg">
<div class="topbar">
    <div>
        <div class="pg-h1">Daily Cash Collection — <em>Delivery Person</em></div>
        <div style="font-size:10px;color:var(--txs);margin-top:2px;">Secondary Invoice · CC Collection · DP Deposits · Variance — grouped by Delivery Person</div>
    </div>
    <div style="display:flex;gap:7px;align-items:center;">
        <div class="dpill"><i class="fa-solid fa-calendar-days"></i> <?php echo date('d M Y',strtotime($date_from)).' — '.date('d M Y',strtotime($date_to));?></div>
        <?php if($submitted && !empty($rows)):?>
        <button onclick="window.print()" class="btn-rst"><i class="fa-solid fa-print"></i> Print</button>
        <?php endif;?>
    </div>
</div>

<div class="fbar">
    <form method="GET" id="dpf" style="display:contents;">
        <input type="hidden" name="search" value="1">
        <div class="fg"><label><i class="fa-solid fa-calendar-day"></i> Date From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from);?>"></div>
        <div class="fg"><label><i class="fa-solid fa-calendar-day"></i> Date To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to);?>"></div>
        <div class="fg" style="min-width:190px;"><label><i class="fa-solid fa-truck"></i> Delivery Person</label>
            <select name="delivery_person">
                <option value="">— All Delivery Persons —</option>
                <?php foreach($all_dp as $dp):?>
                <option value="<?php echo htmlspecialchars($dp);?>" <?php echo $f_dp===$dp?'selected':'';?>>
                    <?php echo htmlspecialchars($dp);?></option>
                <?php endforeach;?>
            </select>
        </div>
        <button type="submit" class="btn-go" id="gBtn"><i class="fa-solid fa-magnifying-glass"></i> Generate</button>
        <a href="cash_collection_dp.php" class="btn-rst" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
    </form>
</div>

<?php if(!$submitted):?>
<div class="tc"><div class="empty"><i class="fa-solid fa-truck"></i>
    <p style="font-size:14px;font-weight:600;margin-bottom:5px;">Daily Cash Collection — Delivery Person Wise</p>
    <p>Choose a date range and click <strong>Generate</strong>.</p>
</div></div>

<?php elseif(empty($rows)):?>
<div class="tc"><div class="empty"><i class="fa-solid fa-inbox"></i>
    <p style="font-size:14px;font-weight:600;margin-bottom:5px;">No records found</p>
    <p><?php echo date('d M Y',strtotime($date_from)).' – '.date('d M Y',strtotime($date_to));
       echo $f_dp?' &nbsp;·&nbsp; DP: <strong>'.htmlspecialchars($f_dp).'</strong>':'';?></p>
</div></div>

<?php else:
$tot_nse = $totals['new_short_excess'];

/* colspan helpers for merged header cells */
$inv_pay_span = 4
    - ($col_empty['cash_paid']   ? 1 : 0)
    - ($col_empty['cheque_paid'] ? 1 : 0)
    - ($col_empty['credit']      ? 1 : 0)
    - ($col_empty['pay_diff']    ? 1 : 0);
if($inv_pay_span < 1) $inv_pay_span = 1; // show at least sinv

$cc_span = 6
    - ($col_empty['cc_rcvd_credit']   ? 1 : 0)
    - ($col_empty['cc_rcvd_rtn_chq']  ? 1 : 0)
    - ($col_empty['cc_rcvd_rtn_chgs'] ? 1 : 0)
    - ($col_empty['cc_rcvd_sent_back']? 1 : 0);

$dep_span = 2 - ($col_empty['handed_cc'] ? 1 : 0);
$var_span = 2 - ($col_empty['short_cc']  ? 1 : 0);
?>
<div class="sc-row">
    <div class="sc blue">
        <div class="sc-lbl"><i class="fa-solid fa-file-invoice-dollar"></i> Sec. Invoice Value</div>
        <div class="sc-val" style="color:#1e40af;">Rs. <?php echo number_format($totals['sinv'],2);?></div>
    </div>
    <div class="sc green">
        <div class="sc-lbl"><i class="fa-solid fa-user-tie"></i> CC Total</div>
        <div class="sc-val" style="color:#166534;">Rs. <?php echo number_format($totals['cc_total'],2);?></div>
    </div>
    <div class="sc green">
        <div class="sc-lbl"><i class="fa-solid fa-building-columns"></i> Total Deposited (CC)</div>
        <div class="sc-val" style="color:#166534;">Rs. <?php echo number_format($totals['banked'],2);?></div>
    </div>
    <div class="sc <?php echo $tot_nse>0.005?'red':'green';?>">
        <div class="sc-lbl"><i class="fa-solid fa-triangle-exclamation"></i> Net Short/Excess</div>
        <div class="sc-val" style="color:<?php echo $tot_nse>0.005?'#991b1b':'#166534';?>;">
            Rs. <?php echo number_format(abs($tot_nse),2);?>
            <?php echo $tot_nse<-0.005?' <small style="font-size:10px;">(Excess)</small>':($tot_nse<0.005?' <small>(OK)</small>':'');?>
        </div>
    </div>
</div>

<div class="tc">
    <div class="tc-bar">
        <div class="tc-ttl">
            <i class="fa-solid fa-truck"></i> Cash Collection — Delivery Person Wise
            <span class="pill p-green"><?php echo count($rows);?> records</span>
            <span class="pill p-blue"><?php echo date('d M Y',strtotime($date_from)).' – '.date('d M Y',strtotime($date_to));?></span>
            <?php if($f_dp):?><span class="pill p-slate">DP: <?php echo htmlspecialchars($f_dp);?></span><?php endif;?>
            <span style="font-size:10px;color:var(--txs);font-weight:400;">·
                <span style="color:#16a34a;font-weight:600;">▲ Excess = Green</span> &nbsp;
                <span style="color:#dc2626;font-weight:600;">▼ Short = Red</span>
            </span>
        </div>
    </div>
    <div class="top-scroll-wrap" id="topScroll"><div class="top-scroll-inner" id="topScrollInner"></div></div>
    <div class="tscroll" id="mainScroll">
    <table class="dpt" id="dptMain">
      <thead>
        <tr class="G">
            <th class="h0 tl stk" rowspan="2" style="min-width:130px;">Delivery Person</th>
            <th class="h0 tl" colspan="1"></th>
            <th class="h1" colspan="1">Sec. Invoice</th>
            <?php if($inv_pay_span > 0): ?><th class="h2" colspan="<?php echo $inv_pay_span;?>">Invoice Payments</th><?php endif;?>
            <th class="hcc" colspan="<?php echo $cc_span;?>"><i class="fa-solid fa-user-tie"></i> CC Collection</th>
            <th class="htc" colspan="1">Total</th>
            <th class="hdep" colspan="<?php echo $dep_span;?>">DP Cash Deposit (CC)</th>
            <th class="hvar" colspan="<?php echo $var_span;?>">Variance &amp; Short</th>
            <th class="hnew" colspan="1">Short/Excess</th>
            <th class="h6" colspan="3">Pay Allocation</th>
            <th class="h6" colspan="1">Action</th>
        </tr>
        <tr class="S">
            <th class="s0 tl" style="min-width:80px;">Del. Date</th>
            <th class="s1"    style="min-width:95px;">Sec. Invoice</th>
            <?php if(!$col_empty['cash_paid']):?><th class="s2" style="min-width:88px;">Cash Paid</th><?php endif;?>
            <?php if(!$col_empty['cheque_paid']):?><th class="s2" style="min-width:88px;">Cheque Paid</th><?php endif;?>
            <?php if(!$col_empty['credit']):?><th class="s2" style="min-width:78px;">Credit</th><?php endif;?>
            <?php if(!$col_empty['pay_diff']):?><th class="s2" style="min-width:58px;">Diff</th><?php endif;?>
            <th class="scc" style="min-width:88px;">Daily Sale</th>
            <?php if(!$col_empty['cc_rcvd_credit']):?><th class="scc" style="min-width:88px;">Rcvd Credit</th><?php endif;?>
            <?php if(!$col_empty['cc_rcvd_rtn_chq']):?><th class="scc" style="min-width:80px;">Rtn Cheque</th><?php endif;?>
            <?php if(!$col_empty['cc_rcvd_rtn_chgs']):?><th class="scc" style="min-width:78px;">Rtn Chgs</th><?php endif;?>
            <?php if(!$col_empty['cc_rcvd_sent_back']):?><th class="scc" style="min-width:78px;">Sent Back</th><?php endif;?>
            <th class="scc" style="min-width:85px;">CC Total</th>
            <th class="stc" style="min-width:92px;">Grand Total</th>
            <th class="sdep_cc" style="min-width:90px;">Dep. CC</th>
            <?php if(!$col_empty['handed_cc']):?><th class="sbo_cc" style="min-width:90px;">BO CC</th><?php endif;?>
            <th class="svar_cc" style="min-width:80px;">Var. CC</th>
            <?php if(!$col_empty['short_cc']):?><th class="ssht_cc" style="min-width:78px;">Short CC</th><?php endif;?>
            <th class="snew" style="min-width:95px;">Final Short/Excess</th>
            <th class="s6" style="min-width:90px;">Charge</th>
            <th class="s6" style="min-width:90px;">Absorb</th>
            <th class="s6" style="min-width:80px;">Pay Var.</th>
            <th class="s6" style="min-width:62px;text-align:center;">Pay</th>
        </tr>
        <tr class="TH">
            <td class="tl stk" colspan="2"><i class="fa-solid fa-sigma"></i>&nbsp; Total (<?php echo count($rows);?> rows)</td>
            <td><?php echo $totals['sinv']>0?number_format($totals['sinv'],2):'—';?></td>
            <?php if(!$col_empty['cash_paid']):?><td><?php echo dp_t($totals['cash_paid']);?></td><?php endif;?>
            <?php if(!$col_empty['cheque_paid']):?><td><?php echo dp_t($totals['cheque_paid']);?></td><?php endif;?>
            <?php if(!$col_empty['credit']):?><td><?php echo dp_t($totals['credit']);?></td><?php endif;?>
            <?php if(!$col_empty['pay_diff']):?><td><?php echo dp_se($totals['pay_diff']);?></td><?php endif;?>
            <td><?php echo dp_t($totals['cc_daily_sale']);?></td>
            <?php if(!$col_empty['cc_rcvd_credit']):?><td><?php echo dp_t($totals['cc_rcvd_credit']);?></td><?php endif;?>
            <?php if(!$col_empty['cc_rcvd_rtn_chq']):?><td><?php echo dp_t($totals['cc_rcvd_rtn_chq']);?></td><?php endif;?>
            <?php if(!$col_empty['cc_rcvd_rtn_chgs']):?><td><?php echo dp_t($totals['cc_rcvd_rtn_chgs']);?></td><?php endif;?>
            <?php if(!$col_empty['cc_rcvd_sent_back']):?><td><?php echo dp_t($totals['cc_rcvd_sent_back']);?></td><?php endif;?>
            <td><?php echo dp_t($totals['cc_total']);?></td>
            <td><?php echo dp_t($totals['total_coll']);?></td>
            <td><?php echo dp_t($totals['banked_cc']);?></td>
            <?php if(!$col_empty['handed_cc']):?><td><?php echo dp_t($totals['handed_cc']);?></td><?php endif;?>
            <td><?php echo dp_se($totals['variance_cc']);?></td>
            <?php if(!$col_empty['short_cc']):?><td><?php $tsc=$totals['short_cc'];echo $tsc>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($tsc,2).'</span>':'—';?></td><?php endif;?>
            <td><?php echo dp_se($totals['new_short_excess']);?></td>
            <td id="th-charge"><?php echo dp_t($totals['p_charge']);?></td>
            <td id="th-absorb"><?php echo dp_t($totals['p_absorb']);?></td>
            <td></td>
            <td></td>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $i=>$r): $stripe=($i%2!==0)?'stripe':''; ?>
      <tr class="<?php echo $stripe;?>">
          <td class="tl rc stk"><?php echo htmlspecialchars($r['dp']);?></td>
          <td class="tl dt"><?php echo date('d M Y',strtotime($r['del_date']));?></td>
          <td class="fb"><?php echo dp_v($r['sinv']);?></td>
          <?php if(!$col_empty['cash_paid']):?><td><?php echo dp_v($r['cash_paid']);?></td><?php endif;?>
          <?php if(!$col_empty['cheque_paid']):?><td><?php echo dp_v($r['cheque_paid']);?></td><?php endif;?>
          <?php if(!$col_empty['credit']):?><td><?php echo dp_v($r['credit']);?></td><?php endif;?>
          <?php if(!$col_empty['pay_diff']):?><td><?php echo dp_se($r['pay_diff']);?></td><?php endif;?>
          <td class="col-c"><?php echo dp_v($r['cc_daily_sale']);?></td>
          <?php if(!$col_empty['cc_rcvd_credit']):?><td class="col-c"><?php echo dp_v($r['cc_rcvd_credit']);?></td><?php endif;?>
          <?php if(!$col_empty['cc_rcvd_rtn_chq']):?><td class="col-c"><?php echo dp_v($r['cc_rcvd_rtn_chq']);?></td><?php endif;?>
          <?php if(!$col_empty['cc_rcvd_rtn_chgs']):?><td class="col-c"><?php echo dp_v($r['cc_rcvd_rtn_chgs']);?></td><?php endif;?>
          <?php if(!$col_empty['cc_rcvd_sent_back']):?><td class="col-c"><?php echo dp_v($r['cc_rcvd_sent_back']);?></td><?php endif;?>
          <td class="col-c tv"><?php echo dp_v($r['cc_total']);?></td>
          <td style="font-weight:800;color:#0f172a;"><?php echo dp_v($r['total_coll']);?></td>
          <td class="col-dep-cc"><?php echo dp_v($r['banked_cc']);?></td>
          <?php if(!$col_empty['handed_cc']):?><td class="col-bo-cc"><?php echo dp_v($r['handed_cc']);?></td><?php endif;?>
          <td class="col-var-cc"><?php echo dp_var_split($r['variance_cc']);?></td>
          <?php if(!$col_empty['short_cc']):?><td class="col-sht-cc"><?php echo dp_short_split($r['short_cc']);?></td><?php endif;?>
          <td class="col-new"><?php echo dp_se($r['new_short_excess']);?></td>
          <td class="col-p" id="prow-charge-<?php echo $i;?>"><?php
              $pc=floatval($r['p_charge']);
              if(abs($pc)>0.004) echo $pc<0?'<span class="val-charge" style="color:#0369a1;">('.number_format(abs($pc),2).')</span>':'<span class="val-charge">'.number_format($pc,2).'</span>';
              else echo '<span class="dash">—</span>';
          ?></td>
          <td class="col-p" id="prow-absorb-<?php echo $i;?>"><?php
              $pa2=floatval($r['p_absorb']);
              if(abs($pa2)>0.004) echo $pa2<0?'<span class="val-absorb" style="color:#dc2626;">('.number_format(abs($pa2),2).')</span>':'<span class="val-absorb">'.number_format($pa2,2).'</span>';
              else echo '<span class="dash">—</span>';
          ?></td>
          <td id="prow-var-<?php echo $i;?>"><?php
              $pv=$r['p_variance'];
              if($pv===null) echo '<span style="color:#d1d5db;">—</span>';
              else { $pv=floatval($pv); if(abs($pv)<0.005) echo '<span class="pv-ok">0.00 ✓</span>'; elseif($pv>0) echo '<span class="pv-sht-pay">▼ '.number_format($pv,2).'</span>'; else echo '<span class="pv-exc-pay">▲ '.number_format(abs($pv),2).'</span>'; }
          ?></td>
          <td style="text-align:center;">
              <button class="btn-pay-alloc<?php echo $r['is_paid']?' paid':'';?>" id="payallocbtn-<?php echo $i;?>" onclick="openPayModal(<?php echo $i;?>)">
                  <i class="fa-solid fa-<?php echo $r['is_paid']?'check':'file-invoice-dollar';?>"></i><?php echo $r['is_paid']?'Paid':'Pay';?>
              </button>
          </td>
      </tr>
      <?php endforeach;?>
      </tbody>
      <tfoot>
        <tr>
            <td class="tl stk" colspan="2">TOTAL — <?php echo count($rows);?> records</td>
            <td><?php echo $totals['sinv']>0?number_format($totals['sinv'],2):'—';?></td>
            <?php if(!$col_empty['cash_paid']):?><td><?php echo dp_t($totals['cash_paid']);?></td><?php endif;?>
            <?php if(!$col_empty['cheque_paid']):?><td><?php echo dp_t($totals['cheque_paid']);?></td><?php endif;?>
            <?php if(!$col_empty['credit']):?><td><?php echo dp_t($totals['credit']);?></td><?php endif;?>
            <?php if(!$col_empty['pay_diff']):?><td><?php echo dp_se($totals['pay_diff']);?></td><?php endif;?>
            <td><?php echo dp_t($totals['cc_daily_sale']);?></td>
            <?php if(!$col_empty['cc_rcvd_credit']):?><td><?php echo dp_t($totals['cc_rcvd_credit']);?></td><?php endif;?>
            <?php if(!$col_empty['cc_rcvd_rtn_chq']):?><td><?php echo dp_t($totals['cc_rcvd_rtn_chq']);?></td><?php endif;?>
            <?php if(!$col_empty['cc_rcvd_rtn_chgs']):?><td><?php echo dp_t($totals['cc_rcvd_rtn_chgs']);?></td><?php endif;?>
            <?php if(!$col_empty['cc_rcvd_sent_back']):?><td><?php echo dp_t($totals['cc_rcvd_sent_back']);?></td><?php endif;?>
            <td><?php echo dp_t($totals['cc_total']);?></td>
            <td><?php echo dp_t($totals['total_coll']);?></td>
            <td><?php echo dp_t($totals['banked_cc']);?></td>
            <?php if(!$col_empty['handed_cc']):?><td><?php echo dp_t($totals['handed_cc']);?></td><?php endif;?>
            <td><?php echo dp_se($totals['variance_cc']);?></td>
            <?php if(!$col_empty['short_cc']):?><td><?php echo $totals['short_cc']>0?'<span style="color:#fca5a5;font-weight:800;">'.number_format($totals['short_cc'],2).'</span>':'—';?></td><?php endif;?>
            <td><?php echo dp_se($totals['new_short_excess']);?></td>
            <td id="tf-charge"><?php echo dp_t($totals['p_charge']);?></td>
            <td id="tf-absorb"><?php echo dp_t($totals['p_absorb']);?></td>
            <td></td>
            <td></td>
        </tr>
      </tfoot>
    </table>
    </div>
</div>
<?php endif;?>
</div>

<!-- ═══════════════════ PAY ALLOC MODAL ═══════════════════ -->
<div class="modal-overlay" id="payAllocModal" onclick="if(event.target===this)closePayModal()">
  <div class="pay-modal-box">
    <div class="pmo-header">
      <div class="pmo-header-left">
        <div class="pmo-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
        <div><div class="pmo-title">Pay Allocation</div><div class="pmo-sub" id="pm_sub">—</div></div>
      </div>
      <button class="pmo-close" onclick="closePayModal()">×</button>
    </div>
    <div class="pmo-info">
      <div class="pmo-info-cell"><div class="pmo-info-lbl">Delivery Person</div><div class="pmo-info-val" id="pm_dp">—</div></div>
      <div class="pmo-info-cell"><div class="pmo-info-lbl">Delivery Date</div><div class="pmo-info-val" id="pm_date">—</div></div>
      <div class="pmo-info-cell"><div class="pmo-info-lbl">Total Cash Collected</div><div class="pmo-info-val" id="pm_total">—</div></div>
      <div class="pmo-info-cell hi-new" id="pm_se_cell"><div class="pmo-info-lbl">Short/Excess Balance</div><div class="pmo-info-val" id="pm_seval">—</div></div>
    </div>
    <div class="pmo-body">
      <div id="pm_excess_notice" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:9px;padding:10px 14px;margin-bottom:12px;">
        <div style="display:flex;align-items:center;gap:8px;"><i class="fa-solid fa-circle-check" style="color:#16a34a;font-size:16px;"></i>
        <div><div style="font-size:12px;font-weight:700;color:#166534;">No shortage — Excess balance</div>
        <div style="font-size:11px;color:#15803d;margin-top:2px;">This delivery person has excess cash. No charge or absorption needed.</div></div></div>
      </div>
      <div class="pmo-section">
        <div class="pmo-sec-head red"><span><i class="fa-solid fa-user-minus"></i>&nbsp; Charge to Employee</span><span class="pmo-sec-total" id="pm_charge_total">0.00</span></div>
        <div class="pmo-sec-body"><div id="pm_chargeRows"></div><button class="add-emp-btn" onclick="addPayEmpRow()"><i class="fa-solid fa-plus"></i> Add Employee</button></div>
      </div>
      <div class="pmo-section">
        <div class="pmo-sec-head blue"><span><i class="fa-solid fa-building"></i>&nbsp; Absorb by Company</span><span class="pmo-sec-total" id="pm_absorb_total">0.00</span></div>
        <div class="pmo-sec-body">
          <div class="absorb-row">
            <label class="absorb-lbl"><i class="fa-solid fa-building" style="color:#1e40af;"></i> Company Absorption Amount</label>
            <input type="number" step="0.01" min="0" class="absorb-inp" id="pm_absorb_amt" placeholder="0.00" oninput="recalcPayAlloc()">
          </div>
        </div>
      </div>
      <div class="pmo-totals">
        <div class="ptb-cell"><div class="ptb-lbl">Balance (Short/Excess)</div><div class="ptb-val" id="pm_ptb_seval">0.00</div></div>
        <div class="ptb-cell"><div class="ptb-lbl">Charged + Absorbed</div><div class="ptb-val" style="color:#1d4ed8;" id="pm_ptb_alloc">0.00</div></div>
        <div class="ptb-cell"><div class="ptb-lbl">Variance</div><div class="ptb-val" style="color:#d97706;" id="pm_ptb_var">0.00</div></div>
      </div>
    </div>
    <div class="pmo-ftr">
      <button class="pmo-btn pmo-btn-cancel" onclick="closePayModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="pmo-btn pmo-btn-save" id="savePayBtn" onclick="savePayAlloc()"><i class="fa-solid fa-floppy-disk"></i> Save Allocation</button>
    </div>
  </div>
</div>

<div id="dpToast"></div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<?php
$rows_js = [];
foreach ($rows as $i => $r) {
    $rows_js[] = [
        'idx'             => $i,
        'dp'              => $r['dp'],
        'del_date'        => $r['del_date'],
        'pay_date'        => $r['del_date'],
        'total'           => floatval($r['total_coll']),
        'new_short_excess'=> floatval($r['new_short_excess']),
        'p_charge'        => floatval($r['p_charge']),
        'p_absorb'        => floatval($r['p_absorb']),
        'p_variance'      => $r['p_variance'],
        'employees'       => $r['p_employees'],
        'is_paid'         => $r['is_paid'],
    ];
}
?>
<script>
var ROWS_DATA=<?php echo json_encode($rows_js);?>;
var payEmpCount=0, currentPayIdx=null;
var EMP_OPTIONS=`<?php foreach($emp_list as $e):?><option value="<?php echo intval($e['id']);?>"><?php echo htmlspecialchars($e['emp_code'].' — '.$e['emp_name']);?></option><?php endforeach;?>`;

function fmtNum(n){return parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');}

function fmtCharge(n){
    n=parseFloat(n||0);
    if(Math.abs(n)<0.004) return '<span class="dash">—</span>';
    if(n<0) return '<span class="val-charge" style="color:#0369a1;">('+fmtNum(Math.abs(n))+')</span>';
    return '<span class="val-charge">'+fmtNum(n)+'</span>';
}
function fmtAbsorb(n){
    n=parseFloat(n||0);
    if(Math.abs(n)<0.004) return '<span class="dash">—</span>';
    if(n<0) return '<span class="val-absorb" style="color:#dc2626;">('+fmtNum(Math.abs(n))+')</span>';
    return '<span class="val-absorb">'+fmtNum(n)+'</span>';
}
function fmtPayVar(v){
    if(v===null||v===undefined) return '<span style="color:#d1d5db;">—</span>';
    v=parseFloat(v);
    if(Math.abs(v)<0.005) return '<span class="pv-ok">0.00 ✓</span>';
    if(v>0) return '<span class="pv-sht-pay">▼ '+fmtNum(v)+'</span>';
    return '<span class="pv-exc-pay">▲ '+fmtNum(Math.abs(v))+'</span>';
}

function openPayModal(idx){
    currentPayIdx=idx; payEmpCount=0;
    document.getElementById('pm_chargeRows').innerHTML='';
    document.getElementById('pm_absorb_amt').value='';

    var rd=ROWS_DATA[idx];
    var newSE=rd.new_short_excess;
    var isShort=newSE>0.005, isExcess=newSE<-0.005;

    document.getElementById('pm_sub').textContent=rd.dp+' · '+rd.pay_date;
    document.getElementById('pm_dp').textContent=rd.dp;
    document.getElementById('pm_date').textContent=rd.pay_date;
    document.getElementById('pm_total').textContent='Rs. '+fmtNum(rd.total);

    var seCell=document.getElementById('pm_se_cell');
    seCell.className='pmo-info-cell '+(isShort?'hi-sht':isExcess?'hi-exc':'hi-ok');
    document.getElementById('pm_seval').textContent=
        fmtNum(Math.abs(newSE))+(isShort?' (Short)':isExcess?' (Excess)':' (Balanced)');

    document.getElementById('pm_excess_notice').style.display=(isExcess||(!isShort&&!isExcess))?'':'none';

    var ptbSE=document.getElementById('pm_ptb_seval');
    if(isShort){  ptbSE.textContent='▼ '+fmtNum(newSE);             ptbSE.style.color='#dc2626'; }
    else if(isExcess){ ptbSE.textContent='▲ '+fmtNum(Math.abs(newSE)); ptbSE.style.color='#16a34a'; }
    else {         ptbSE.textContent='0.00 ✓';                       ptbSE.style.color='#16a34a'; }

    document.getElementById('pm_ptb_alloc').textContent='0.00';
    document.getElementById('pm_charge_total').textContent='0.00';
    document.getElementById('pm_absorb_total').textContent='0.00';
    recalcPayAllocVar(newSE,0);

    if(rd.employees&&rd.employees.length) rd.employees.forEach(function(e){ addPayEmpRow(e.employee_id,e.amount); });
    if(rd.p_absorb!=0){ document.getElementById('pm_absorb_amt').value=rd.p_absorb; recalcPayAlloc(); }

    document.getElementById('payAllocModal').classList.add('open');
}
function closePayModal(){ document.getElementById('payAllocModal').classList.remove('open'); currentPayIdx=null; }

function addPayEmpRow(empId,amt){
    var rid='er_'+(payEmpCount++);
    var div=document.createElement('div'); div.className='emp-row'; div.id=rid;
    div.innerHTML=`<select class="emp-sel"><option value="">— Select Employee —</option>${EMP_OPTIONS}</select>
        <input type="number" step="0.01" class="emp-amt" placeholder="0.00" oninput="recalcPayAlloc()">
        <button type="button" class="emp-del" onclick="document.getElementById('${rid}').remove();recalcPayAlloc();" title="Remove">✕</button>`;
    document.getElementById('pm_chargeRows').appendChild(div);
    $(div).find('.emp-sel').select2({
        dropdownParent:$('#payAllocModal'),
        placeholder:'— Select Employee —',
        allowClear:true,
        minimumResultsForSearch:0,
        width:'resolve'
    });
    if(empId) $(div).find('.emp-sel').val(empId).trigger('change');
    if(amt!=null&&amt!==undefined) div.querySelector('.emp-amt').value=parseFloat(amt).toFixed(2);
    recalcPayAlloc();
}

function recalcPayAlloc(){
    var tc=0;
    document.querySelectorAll('#pm_chargeRows .emp-row').forEach(function(row){
        tc+=parseFloat(row.querySelector('.emp-amt').value||0);
    });
    var absorb=parseFloat(document.getElementById('pm_absorb_amt').value||0);
    var newSE=currentPayIdx!==null?ROWS_DATA[currentPayIdx].new_short_excess:0;
    var alloc=tc+absorb;

    var chEl=document.getElementById('pm_charge_total');
    if(Math.abs(tc)<0.004){ chEl.textContent='0.00'; chEl.style.color=''; }
    else if(tc<0){ chEl.textContent='('+fmtNum(Math.abs(tc))+')'; chEl.style.color='#0369a1'; }
    else { chEl.textContent=fmtNum(tc); chEl.style.color=''; }

    var abEl=document.getElementById('pm_absorb_total');
    if(Math.abs(absorb)<0.004){ abEl.textContent='0.00'; abEl.style.color=''; }
    else if(absorb<0){ abEl.textContent='('+fmtNum(Math.abs(absorb))+')'; abEl.style.color='#dc2626'; }
    else { abEl.textContent=fmtNum(absorb); abEl.style.color=''; }

    var allocEl=document.getElementById('pm_ptb_alloc');
    if(Math.abs(alloc)<0.004){ allocEl.textContent='0.00'; allocEl.style.color='#1d4ed8'; }
    else if(alloc<0){ allocEl.textContent='('+fmtNum(Math.abs(alloc))+')'; allocEl.style.color='#d97706'; }
    else { allocEl.textContent=fmtNum(alloc); allocEl.style.color='#1d4ed8'; }

    recalcPayAllocVar(newSE,alloc);
}

function recalcPayAllocVar(newSE,alloc){
    var vari=newSE-alloc;
    var vEl=document.getElementById('pm_ptb_var');
    if(Math.abs(vari)<0.005){  vEl.textContent='0.00 ✓'; vEl.style.color='#16a34a'; }
    else if(vari>0){            vEl.textContent='▼ '+fmtNum(vari);          vEl.style.color='#dc2626'; }
    else {                      vEl.textContent='▲ '+fmtNum(Math.abs(vari)); vEl.style.color='#d97706'; }
}

function savePayAlloc(){
    if(currentPayIdx===null) return;
    var btn=document.getElementById('savePayBtn');
    var charges=[]; var valid=true;

    document.querySelectorAll('#pm_chargeRows .emp-row').forEach(function(row){
        var empId=$(row).find('.emp-sel').val();
        var empLbl=$(row).find('.emp-sel option:selected').text()||'';
        var amt=parseFloat(row.querySelector('.emp-amt').value||0);
        if(!empId){ showDPToast('Please select an employee.','err'); valid=false; return; }
        if(amt===0){ showDPToast('Charge amount cannot be zero.','err'); valid=false; return; }
        charges.push({employee_id:parseInt(empId),employee_name:empLbl,amount:amt});
    });
    if(!valid) return;

    var rd=ROWS_DATA[currentPayIdx];
    var absorb=parseFloat(document.getElementById('pm_absorb_amt').value||0)||0;
    var newSE=rd.new_short_excess;
    var tc=charges.reduce(function(s,r){ return s+r.amount; },0);
    var vari=newSE-(tc+absorb);

    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    fetch('save_cash_dp_pay.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
            pay_date:rd.pay_date, delivery_person:rd.dp,
            charges:charges, absorb_amount:absorb,
            se_value:newSE, variance:vari
        })
    })
    .then(function(r){ return r.json(); })
    .then(function(res){
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Allocation';
        if(res.success){
            var idx=currentPayIdx;
            ROWS_DATA[idx].p_charge=tc;
            ROWS_DATA[idx].p_absorb=absorb;
            ROWS_DATA[idx].p_variance=vari;
            ROWS_DATA[idx].employees=charges;
            ROWS_DATA[idx].is_paid=true;

            document.getElementById('prow-charge-'+idx).innerHTML=fmtCharge(tc);
            document.getElementById('prow-absorb-'+idx).innerHTML=fmtAbsorb(absorb);
            document.getElementById('prow-var-'+idx).innerHTML=fmtPayVar(vari);

            var pb=document.getElementById('payallocbtn-'+idx);
            if(pb){ pb.className='btn-pay-alloc paid'; pb.innerHTML='<i class="fa-solid fa-check"></i> Paid'; }

            refreshTotals();
            closePayModal();
            showDPToast('Pay allocation saved!','ok');
        } else {
            showDPToast('Error: '+(res.message||'Unknown'),'err');
        }
    })
    .catch(function(err){
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Allocation';
        showDPToast('Network error: '+err.message,'err');
    });
}

function refreshTotals(){
    var tc=0,ta=0;
    ROWS_DATA.forEach(function(r){ tc+=r.p_charge||0; ta+=r.p_absorb||0; });
    var ccT=function(n){ return n==0?'—':fmtNum(n); };
    var thC=document.getElementById('th-charge'),thA=document.getElementById('th-absorb');
    var tfC=document.getElementById('tf-charge'),tfA=document.getElementById('tf-absorb');
    if(thC) thC.innerHTML=ccT(tc); if(thA) thA.innerHTML=ccT(ta);
    if(tfC) tfC.innerHTML=ccT(tc); if(tfA) tfA.innerHTML=ccT(ta);
}

function showDPToast(msg,type){
    var t=document.getElementById('dpToast');
    t.className=type==='ok'?'toast-ok':'toast-err';
    t.textContent=msg; t.style.display='block'; t.style.opacity='1';
    clearTimeout(t._t);
    t._t=setTimeout(function(){ t.style.opacity='0'; setTimeout(function(){ t.style.display='none'; },300); },2800);
}

document.getElementById('dpf')?.addEventListener('submit',function(){
    var b=document.getElementById('gBtn'); b.disabled=true;
    b.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Generating…';
});

(function(){
    var top=document.getElementById('topScroll'),main=document.getElementById('mainScroll');
    if(!top||!main) return;
    var inner=document.getElementById('topScrollInner');
    function setW(){ var tbl=main.querySelector('table.dpt'); if(tbl) inner.style.width=tbl.scrollWidth+'px'; }
    setW(); window.addEventListener('resize',setW);
    var syncing=false;
    top.addEventListener('scroll',function(){ if(syncing)return; syncing=true; main.scrollLeft=top.scrollLeft; syncing=false; });
    main.addEventListener('scroll',function(){ if(syncing)return; syncing=true; top.scrollLeft=main.scrollLeft; syncing=false; });
})();
</script>
<?php include 'footer.php';?>
