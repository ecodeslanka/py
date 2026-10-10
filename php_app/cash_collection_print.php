<?php
include 'config.php'; // your existing DB connection

$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$f_sr      = trim($_GET['sr_code'] ?? '');
$submitted = isset($_GET['search']);

$df     = mysqli_real_escape_string($conn, $date_from);
$dt     = mysqli_real_escape_string($conn, $date_to);
$sr_esc = $f_sr ? mysqli_real_escape_string($conn, $f_sr) : '';

/* ══════════════════════════════════════════
   ENSURE TABLES EXIST
══════════════════════════════════════════ */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cash_summary_pay_allocations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pay_date DATE NOT NULL, sr_code VARCHAR(50) NOT NULL,
    entry_type VARCHAR(20) NOT NULL, employee_id INT NULL,
    employee_name VARCHAR(200) NULL, amount DECIMAL(12,2) DEFAULT 0.00,
    total_value DECIMAL(12,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date_sr (pay_date, sr_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cash_shortage_transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_sr_code VARCHAR(50) NOT NULL,
    to_sr_code   VARCHAR(50) NOT NULL,
    delivery_date DATE NOT NULL,
    transfer_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    note TEXT NULL,
    employee_id   INT NULL,
    employee_name VARCHAR(200) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_from (from_sr_code, delivery_date),
    INDEX idx_to   (to_sr_code,   delivery_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$col_check = mysqli_query($conn, "SHOW COLUMNS FROM cash_shortage_transfers LIKE 'employee_id'");
if ($col_check && mysqli_num_rows($col_check) === 0) {
    mysqli_query($conn, "ALTER TABLE cash_shortage_transfers
        ADD COLUMN employee_id   INT          NULL AFTER note,
        ADD COLUMN employee_name VARCHAR(200) NULL AFTER employee_id");
}

/* SR list for filter */
$sr_res = mysqli_query($conn, "SELECT DISTINCT sr_code FROM field_summary WHERE sr_code IS NOT NULL AND sr_code!='' ORDER BY sr_code");
$all_sr = [];
if ($sr_res) while ($r = mysqli_fetch_assoc($sr_res)) $all_sr[] = $r['sr_code'];

/* ══════════════════════════════════════════
   DATA
══════════════════════════════════════════ */
$rows   = [];
$totals = array_fill_keys([
    'sinv','sinv_bf','sinv_bp','sinv_adj',
    'cash_paid','cheque_paid','credit','pay_diff',
    'cc_daily_sale','cc_rcvd_credit','cc_rcvd_rtn_chq','cc_rcvd_rtn_chgs','cc_rcvd_sent_back','cc_total',
    'sr_daily_sale','sr_rcvd_credit','sr_rcvd_rtn_chq','sr_rcvd_rtn_chgs','sr_rcvd_sent_back','sr_total',
    'total_coll','banked','handed',
    'banked_sr','banked_cc','handed_sr','handed_cc',
    'variance_cc','variance_sr','short_cc','short_sr',
    'bank_diff','cash_short_excess',
    't_out','t_in','shortage_adj','new_short_excess',
    'p_charge','p_absorb'
], 0.0);

if ($submitted) {
    $srCnd    = $sr_esc ? "AND fs.sr_code='$sr_esc'" : '';
    $sid_w    = "sid.delivery_date BETWEEN '$df' AND '$dt' AND sid.status='imported'" . ($sr_esc ? " AND sid.sales_person_code='$sr_esc'" : '');
    $where_fs = "fs.delivery_date BETWEEN '$df' AND '$dt'" . ($sr_esc ? " AND fs.sr_code='$sr_esc'" : '');

    /* ── Master date|sr set ── */
    $master_set = [];
    foreach ([
        "SELECT DISTINCT delivery_date, sr_code FROM field_summary WHERE delivery_date BETWEEN '$df' AND '$dt' AND sr_code IS NOT NULL AND sr_code!=''" . ($sr_esc?" AND sr_code='$sr_esc'":''),
        "SELECT DISTINCT ip.payment_date, fs2.sr_code FROM invoice_payments ip INNER JOIN field_summary_details fsd2 ON fsd2.id=ip.field_summary_detail_id INNER JOIN field_summary fs2 ON fs2.id=fsd2.field_summary_id WHERE ip.payment_method='cash' AND ip.is_reversed=0 AND ip.payment_date BETWEEN '$df' AND '$dt' AND fs2.sr_code IS NOT NULL AND fs2.sr_code!=''" . ($sr_esc?" AND fs2.sr_code='$sr_esc'":''),
        "SELECT DISTINCT delivery_date, rep_code FROM cc_cash_deposit_reps WHERE delivery_date BETWEEN '$df' AND '$dt' AND delivery_date IS NOT NULL AND delivery_date!='0000-00-00' AND rep_code IS NOT NULL AND rep_code!=''" . ($sr_esc?" AND rep_code='$sr_esc'":''),
        "SELECT DISTINCT delivery_date, from_sr_code FROM cash_shortage_transfers WHERE delivery_date BETWEEN '$df' AND '$dt' AND from_sr_code IS NOT NULL AND from_sr_code!=''" . ($sr_esc?" AND from_sr_code='$sr_esc'":''),
        "SELECT DISTINCT delivery_date, to_sr_code FROM cash_shortage_transfers WHERE delivery_date BETWEEN '$df' AND '$dt' AND to_sr_code IS NOT NULL AND to_sr_code!=''" . ($sr_esc?" AND to_sr_code='$sr_esc'":''),
        "SELECT DISTINCT pay_date, sr_code FROM cash_summary_pay_allocations WHERE pay_date BETWEEN '$df' AND '$dt' AND sr_code IS NOT NULL AND sr_code!=''" . ($sr_esc?" AND sr_code='$sr_esc'":''),
    ] as $q) {
        $res = mysqli_query($conn, $q);
        if ($res) while ($row = mysqli_fetch_row($res)) $master_set[$row[0].'|'.$row[1]] = true;
    }
    $master = array_keys($master_set);
    usort($master, 'strcmp');

    /* ── Sec invoice ── */
    $sinv_map = [];
    $r = mysqli_query($conn,"SELECT sid.sales_person_code, sid.delivery_date, COALESCE(SUM(sid.final_bill_amount),0) AS sinv FROM secondary_invoice_import_details sid WHERE $sid_w GROUP BY sid.sales_person_code, sid.delivery_date");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $sinv_map[$row['delivery_date'].'|'.$row['sales_person_code']] = floatval($row['sinv']);

    /* ── TBD BF ── */
    $tbd_bf_map = [];
    $r = mysqli_query($conn,"SELECT fs.sr_code, fs.delivery_date, COALESCE(SUM(sid.final_bill_amount),0) AS bf_val FROM field_summary_details fsd INNER JOIN field_summary fs ON fs.id=fsd.field_summary_id LEFT JOIN secondary_invoice_import_details sid ON sid.bill_no=fsd.invoice_num AND sid.status='imported' WHERE fs.delivery_date BETWEEN '$df' AND '$dt' " . ($sr_esc?"AND fs.sr_code='$sr_esc'":'') . " AND fsd.to_be_delivery=1 AND fsd.to_be_delivery_date>fs.delivery_date GROUP BY fs.sr_code, fs.delivery_date");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $tbd_bf_map[$row['delivery_date'].'|'.$row['sr_code']] = floatval($row['bf_val']);

    /* ── TBD BP ── */
    $tbd_bp_map = [];
    $r = mysqli_query($conn,"SELECT fs.sr_code, fsd.to_be_delivery_date AS delivery_date, COALESCE(SUM(sid.final_bill_amount),0) AS bp_val FROM field_summary_details fsd INNER JOIN field_summary fs ON fs.id=fsd.field_summary_id LEFT JOIN secondary_invoice_import_details sid ON sid.bill_no=fsd.invoice_num AND sid.status='imported' WHERE fsd.to_be_delivery=1 AND fsd.to_be_delivery_date BETWEEN '$df' AND '$dt' AND fsd.to_be_delivery_date>fs.delivery_date " . ($sr_esc?"AND fs.sr_code='$sr_esc'":'') . " GROUP BY fs.sr_code, fsd.to_be_delivery_date");
    if ($r) while ($row = mysqli_fetch_assoc($r)) { $k=$row['delivery_date'].'|'.$row['sr_code']; $tbd_bp_map[$k]=($tbd_bp_map[$k]??0)+floatval($row['bp_val']); }

    /* ── Invoice payments ── */
    $invpay_map = [];
    $r = mysqli_query($conn,"SELECT fs.delivery_date, fs.sr_code, COALESCE(SUM(CASE WHEN ip.payment_method='cash' AND ip.payment_date=fs.delivery_date AND ip.is_reversed=0 THEN ip.amount ELSE 0 END),0) AS cash_paid, COALESCE(SUM(CASE WHEN ip.payment_method='cheque' AND ip.payment_date=fs.delivery_date AND ip.is_reversed=0 THEN ip.amount ELSE 0 END),0) AS cheque_paid FROM invoice_payments ip INNER JOIN field_summary fs ON fs.id=ip.field_summary_id WHERE $where_fs GROUP BY fs.delivery_date, fs.sr_code");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $invpay_map[$row['delivery_date'].'|'.$row['sr_code']] = $row;

    /* ── Credit ── */
    $paid_by_det = [];
    $r = mysqli_query($conn,"SELECT ip.field_summary_detail_id, ROUND(COALESCE(SUM(ip.amount),0),2) AS paid FROM invoice_payments ip INNER JOIN field_summary fs ON fs.id=ip.field_summary_id WHERE $where_fs AND ip.payment_date<=fs.delivery_date GROUP BY ip.field_summary_detail_id");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $paid_by_det[intval($row['field_summary_detail_id'])] = floatval($row['paid']);
    $credit_map = [];
    $r = mysqli_query($conn,"SELECT fs.sr_code, fs.delivery_date, fsd.id AS det_id, fsd.adjust_net_value AS row_adj FROM field_summary_details fsd INNER JOIN field_summary fs ON fs.id=fsd.field_summary_id WHERE $where_fs");
    if ($r) while ($row = mysqli_fetch_assoc($r)) { $k=$row['delivery_date'].'|'.$row['sr_code']; $rb=max(0.0,floatval($row['row_adj'])-($paid_by_det[intval($row['det_id'])]??0.0)); $credit_map[$k]=($credit_map[$k]??0)+$rb; }

    /* ── Cash collection ── */
    $cash_map = [];
    $r = mysqli_query($conn,"SELECT ip.payment_date, fs.sr_code,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc' AND (ip.payment_source IS NULL OR ip.payment_source='' OR LOWER(ip.payment_source)='invoice') THEN ip.amount ELSE 0 END),0) AS cc_daily_sale,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc' AND LOWER(ip.payment_source) LIKE '%credit%' THEN ip.amount ELSE 0 END),0) AS cc_rcvd_credit,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc' AND ip.payment_source='return_cheque_settlement' THEN ip.amount ELSE 0 END),0) AS cc_rcvd_rtn_chq,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc' AND ip.payment_source='return_charge_settlement' THEN ip.amount ELSE 0 END),0) AS cc_rcvd_rtn_chgs,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc' AND ip.payment_source='sentback_cheque_settlement' THEN ip.amount ELSE 0 END),0) AS cc_rcvd_sent_back,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc' THEN ip.amount ELSE 0 END),0) AS cc_total,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND (ip.payment_source IS NULL OR ip.payment_source='' OR LOWER(ip.payment_source)='invoice') THEN ip.amount ELSE 0 END),0) AS sr_daily_sale,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND LOWER(ip.payment_source) LIKE '%credit%' THEN ip.amount ELSE 0 END),0) AS sr_rcvd_credit,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND ip.payment_source='return_cheque_settlement' THEN ip.amount ELSE 0 END),0) AS sr_rcvd_rtn_chq,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND ip.payment_source='return_charge_settlement' THEN ip.amount ELSE 0 END),0) AS sr_rcvd_rtn_chgs,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND ip.payment_source='sentback_cheque_settlement' THEN ip.amount ELSE 0 END),0) AS sr_rcvd_sent_back,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' THEN ip.amount ELSE 0 END),0) AS sr_total,
        COALESCE(SUM(ip.amount),0) AS total_coll
        FROM invoice_payments ip INNER JOIN field_summary_details fsd ON fsd.id=ip.field_summary_detail_id INNER JOIN field_summary fs ON fs.id=fsd.field_summary_id
        WHERE ip.payment_method='cash' AND ip.is_reversed=0 AND ip.payment_date BETWEEN '$df' AND '$dt' $srCnd
        GROUP BY ip.payment_date, fs.sr_code");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $cash_map[$row['payment_date'].'|'.$row['sr_code']] = $row;

    /* ── Deposits ── */
    $dep_map = [];
    $dep_cnd = $sr_esc ? " AND r.rep_code='$sr_esc'" : '';
    $r = mysqli_query($conn,"SELECT r.delivery_date, r.rep_code,
        COALESCE(SUM(CASE WHEN d.handed_over_bo=0 AND LOWER(COALESCE(d.collected_by,''))='sr' THEN r.amount ELSE 0 END),0) AS banked_sr,
        COALESCE(SUM(CASE WHEN d.handed_over_bo=0 AND LOWER(COALESCE(d.collected_by,''))='cc' THEN r.amount ELSE 0 END),0) AS banked_cc,
        COALESCE(SUM(CASE WHEN d.handed_over_bo=1 AND LOWER(COALESCE(d.collected_by,''))='sr' THEN r.amount ELSE 0 END),0) AS handed_sr,
        COALESCE(SUM(CASE WHEN d.handed_over_bo=1 AND LOWER(COALESCE(d.collected_by,''))='cc' THEN r.amount ELSE 0 END),0) AS handed_cc
        FROM cc_cash_deposit_reps r INNER JOIN cc_cash_deposits d ON d.id=r.deposit_id
        WHERE r.delivery_date BETWEEN '$df' AND '$dt' AND r.delivery_date IS NOT NULL AND r.delivery_date!='0000-00-00' $dep_cnd
        GROUP BY r.delivery_date, r.rep_code");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $dep_map[$row['delivery_date'].'|'.$row['rep_code']] = $row;

    /* ── Pay allocations ── */
    $alloc_map = [];
    $r = mysqli_query($conn,"SELECT pay_date, sr_code, entry_type, employee_id, employee_name, amount FROM cash_summary_pay_allocations WHERE pay_date BETWEEN '$df' AND '$dt'" . ($sr_esc?" AND sr_code='$sr_esc'":'') . " ORDER BY pay_date, sr_code, entry_type");
    if ($r) while ($row = mysqli_fetch_assoc($r)) {
        $k = $row['pay_date'].'|'.$row['sr_code'];
        if (!isset($alloc_map[$k])) $alloc_map[$k] = ['p_charge'=>0,'p_absorb'=>0,'p_variance'=>null];
        if ($row['entry_type']==='charge')   $alloc_map[$k]['p_charge']  += floatval($row['amount']);
        if ($row['entry_type']==='absorb')   $alloc_map[$k]['p_absorb']   = floatval($row['amount']);
        if ($row['entry_type']==='variance') $alloc_map[$k]['p_variance'] = floatval($row['amount']);
    }

    /* ── Transfers ── */
    $transfer_out_map = [];
    $transfer_in_map  = [];
    $tr_cnd = "delivery_date BETWEEN '$df' AND '$dt'";
    if ($sr_esc) $tr_cnd .= " AND (from_sr_code='$sr_esc' OR to_sr_code='$sr_esc')";
    $r = mysqli_query($conn,"SELECT from_sr_code, to_sr_code, delivery_date, SUM(amount) AS amt FROM cash_shortage_transfers WHERE $tr_cnd GROUP BY from_sr_code, to_sr_code, delivery_date");
    if ($r) while ($row = mysqli_fetch_assoc($r)) {
        $kf = $row['delivery_date'].'|'.$row['from_sr_code'];
        $kt = $row['delivery_date'].'|'.$row['to_sr_code'];
        $transfer_out_map[$kf] = ($transfer_out_map[$kf]??0) + floatval($row['amt']);
        $transfer_in_map[$kt]  = ($transfer_in_map[$kt]??0)  + floatval($row['amt']);
    }

    /* ── Build rows ── */
    foreach ($master as $k) {
        [$del_date, $sr_code] = explode('|', $k, 2);
        $pm = $invpay_map[$k] ?? [];
        $dm = $dep_map[$k]    ?? [];
        $cm = $cash_map[$del_date.'|'.$sr_code] ?? [];

        $sinv        = floatval($sinv_map[$k] ?? 0);
        $sinv_bf     = floatval($tbd_bf_map[$k] ?? 0);
        $sinv_bp     = floatval($tbd_bp_map[$k] ?? 0);
        $sinv_adj    = $sinv - $sinv_bf + $sinv_bp;

        $cash_paid   = floatval($pm['cash_paid']   ?? 0);
        $cheque_paid = floatval($pm['cheque_paid'] ?? 0);
        $credit      = floatval($credit_map[$k]    ?? 0);
        $pay_diff    = $sinv_adj - ($cash_paid + $cheque_paid + $credit);

        $cc_daily_sale     = floatval($cm['cc_daily_sale']     ?? 0);
        $cc_rcvd_credit    = floatval($cm['cc_rcvd_credit']    ?? 0);
        $cc_rcvd_rtn_chq   = floatval($cm['cc_rcvd_rtn_chq']   ?? 0);
        $cc_rcvd_rtn_chgs  = floatval($cm['cc_rcvd_rtn_chgs']  ?? 0);
        $cc_rcvd_sent_back = floatval($cm['cc_rcvd_sent_back'] ?? 0);
        $cc_total          = floatval($cm['cc_total']           ?? 0);
        $sr_daily_sale     = floatval($cm['sr_daily_sale']     ?? 0);
        $sr_rcvd_credit    = floatval($cm['sr_rcvd_credit']    ?? 0);
        $sr_rcvd_rtn_chq   = floatval($cm['sr_rcvd_rtn_chq']   ?? 0);
        $sr_rcvd_rtn_chgs  = floatval($cm['sr_rcvd_rtn_chgs']  ?? 0);
        $sr_rcvd_sent_back = floatval($cm['sr_rcvd_sent_back'] ?? 0);
        $sr_total          = floatval($cm['sr_total']           ?? 0);
        $total_coll        = floatval($cm['total_coll']         ?? 0);

        $banked_cc = floatval($dm['banked_cc'] ?? 0);
        $banked_sr = floatval($dm['banked_sr'] ?? 0);
        $handed_cc = floatval($dm['handed_cc'] ?? 0);
        $handed_sr = floatval($dm['handed_sr'] ?? 0);
        $banked    = $banked_cc + $banked_sr;
        $handed    = $handed_cc + $handed_sr;

        $variance_cc = $cc_total - ($banked_cc + $handed_cc);
        $variance_sr = $sr_total - ($banked_sr + $handed_sr);
        $short_cc    = $variance_cc > 0 ? $variance_cc : 0;
        $short_sr    = $variance_sr > 0 ? $variance_sr : 0;

        $bank_diff        = $total_coll - ($banked + $handed);
        $cash_short_excess = $bank_diff;

        $t_out = floatval($transfer_out_map[$k] ?? 0);
        $t_in  = floatval($transfer_in_map[$k]  ?? 0);
        $new_short_excess = $bank_diff >= 0
            ? $bank_diff - $t_out + $t_in
            : $bank_diff + $t_out - $t_in;

        $pa         = $alloc_map[$k] ?? [];
        $p_charge   = floatval($pa['p_charge']  ?? 0);
        $p_absorb   = floatval($pa['p_absorb']  ?? 0);
        $p_variance = isset($pa['p_variance']) ? floatval($pa['p_variance']) : null;

        $rows[] = compact(
            'sr_code','del_date','sinv','sinv_bf','sinv_bp','sinv_adj',
            'cash_paid','cheque_paid','credit','pay_diff',
            'cc_daily_sale','cc_rcvd_credit','cc_rcvd_rtn_chq','cc_rcvd_rtn_chgs','cc_rcvd_sent_back','cc_total',
            'sr_daily_sale','sr_rcvd_credit','sr_rcvd_rtn_chq','sr_rcvd_rtn_chgs','sr_rcvd_sent_back','sr_total',
            'total_coll','banked','handed','banked_cc','banked_sr','handed_cc','handed_sr',
            'variance_cc','variance_sr','short_cc','short_sr',
            'bank_diff','cash_short_excess','t_out','t_in','new_short_excess',
            'p_charge','p_absorb','p_variance'
        );

        foreach (['sinv','sinv_bf','sinv_bp','sinv_adj','cash_paid','cheque_paid','credit','pay_diff',
                  'cc_daily_sale','cc_rcvd_credit','cc_rcvd_rtn_chq','cc_rcvd_rtn_chgs','cc_rcvd_sent_back','cc_total',
                  'sr_daily_sale','sr_rcvd_credit','sr_rcvd_rtn_chq','sr_rcvd_rtn_chgs','sr_rcvd_sent_back','sr_total',
                  'total_coll','banked','handed','banked_cc','banked_sr','handed_cc','handed_sr',
                  'variance_cc','variance_sr','short_cc','short_sr','bank_diff','cash_short_excess',
                  't_out','t_in','new_short_excess','p_charge','p_absorb'] as $fk) {
            $totals[$fk] += $$fk;
        }
    }
}

/* ══════════════════════════════════════════
   HELPERS
══════════════════════════════════════════ */
function pv($v)  { $n=floatval($v); return $n==0 ? '—' : number_format($n,2); }
function pse($v) {
    $n = floatval($v);
    if (abs($n)<0.005) return '<span class="d-ok">0.00 ✓</span>';
    if ($n<0) return '<span class="d-exc">▲ '.number_format(abs($n),2).'</span>';
    return '<span class="d-sht">▼ '.number_format($n,2).'</span>';
}
function pbf($v) { $n=floatval($v); return $n==0?'—':'<span class="tbd-bf">−'.number_format($n,2).'</span>'; }
function pbp($v) { $n=floatval($v); return $n==0?'—':'<span class="tbd-bp">+'.number_format($n,2).'</span>'; }
function pvar($v){
    if ($v===null) return '—';
    $v=floatval($v);
    if (abs($v)<0.005) return '<span class="d-ok">0.00</span>';
    if ($v<0) return '<span class="d-exc">'.number_format($v,2).'</span>';
    return '<span class="d-sht">+'.number_format($v,2).'</span>';
}
function ptr($out,$in){
    $o = floatval($out); $i = floatval($in);
    if ($o<=0&&$i<=0) return '—';
    $s='';
    if ($o>0) $s.='<span class="tr-out">▼'.number_format($o,2).'</span>';
    if ($o>0&&$i>0) $s.=' ';
    if ($i>0) $s.='<span class="tr-in">▲'.number_format($i,2).'</span>';
    return $s;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Daily Cash Collection Report</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}

@page{
    size:A4 landscape;
    margin:5mm 4mm 5mm 4mm;
}

body{
    font-family:Arial,Helvetica,sans-serif;
    font-size:7pt;
    color:#000;
    background:#fff;
    -webkit-print-color-adjust:exact;
    print-color-adjust:exact;
}

/* ── SCREEN PREVIEW ── */
@media screen{
    body{background:#c8c8c8;padding:16px;}
    .page-wrap{background:#fff;width:280mm;margin:0 auto;padding:5mm 4mm;box-shadow:0 4px 24px rgba(0,0,0,.3);}
}
@media print{
    .no-print{display:none!important;}
    .page-wrap{width:100%;}
}

/* ── TOOLBAR (screen only) ── */
.toolbar{
    width:280mm;
    margin:0 auto 10px;
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}
.toolbar form{display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap;}
.toolbar label{font-size:11px;font-weight:700;color:#444;display:block;margin-bottom:2px;}
.toolbar input[type=date],.toolbar select{
    padding:5px 8px;border:1.5px solid #bbb;border-radius:5px;
    font-size:12px;font-family:Arial,sans-serif;color:#111;background:#fff;
}
.toolbar input[type=date]:focus,.toolbar select:focus{outline:none;border-color:#333;}
.btn-go{
    display:inline-flex;align-items:center;gap:5px;
    padding:6px 14px;background:#1a1f2e;color:#fff;
    border:none;border-radius:5px;font-size:12px;font-weight:700;
    font-family:Arial,sans-serif;cursor:pointer;
}
.btn-go:hover{background:#333;}
.btn-print{
    display:inline-flex;align-items:center;gap:5px;
    padding:6px 14px;background:#166534;color:#fff;
    border:none;border-radius:5px;font-size:12px;font-weight:700;
    font-family:Arial,sans-serif;cursor:pointer;margin-left:auto;
}
.btn-print:hover{background:#15803d;}

/* ── REPORT HEADER ── */
.rpt-header{
    display:flex;justify-content:space-between;align-items:flex-start;
    border-bottom:2px solid #000;padding-bottom:4px;margin-bottom:4px;
}
.rpt-title{font-size:11pt;font-weight:900;letter-spacing:-.01em;}
.rpt-sub{font-size:6pt;color:#444;margin-top:1px;}
.rpt-meta{text-align:right;font-size:6.5pt;color:#333;line-height:1.7;}
.rpt-meta strong{color:#000;}

/* ── SUMMARY CARDS ── */
.sc-row{
    display:grid;grid-template-columns:repeat(5,1fr);
    gap:3px;margin-bottom:4px;
}
.sc{border:1px solid #999;border-left:3px solid #555;border-radius:2px;padding:3px 4px;}
.sc-lbl{font-size:5.5pt;font-weight:700;text-transform:uppercase;color:#555;margin-bottom:1px;}
.sc-val{font-size:8pt;font-weight:900;color:#000;}

/* ── COLLECTION SUMMARY BOX ── */
.coll-summary{
    border:1px solid #999;border-radius:3px;
    margin-bottom:4px;overflow:hidden;
}
.coll-summary-head{
    background:#1a1f2e;color:#fff;
    font-size:6.5pt;font-weight:800;text-transform:uppercase;
    padding:3px 6px;letter-spacing:.04em;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;
}
.coll-summary-body{
    display:grid;grid-template-columns:repeat(6,1fr);
    gap:0;
}
.csb-cell{
    padding:3px 5px;
    border-right:1px solid #ddd;
    border-bottom:1px solid #ddd;
}
.csb-cell:nth-child(6n){border-right:none;}
.csb-cell:nth-last-child(-n+6){border-bottom:none;}
.csb-lbl{font-size:5.5pt;color:#555;font-weight:700;text-transform:uppercase;margin-bottom:1px;}
.csb-val{font-size:7.5pt;font-weight:800;color:#000;font-family:'Courier New',monospace;}
.csb-val.green{color:#166534;}
.csb-val.red{color:#991b1b;}
.csb-val.blue{color:#1e40af;}
.csb-section-head{
    grid-column:span 6;
    background:#f0f0f0;
    font-size:5.5pt;font-weight:800;text-transform:uppercase;
    padding:2px 5px;color:#333;border-bottom:1px solid #ddd;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;
}

/* ── TABLE ── */
table.cct{
    width:100%;border-collapse:collapse;
    font-size:6pt;table-layout:auto;
}
table.cct thead tr.G th{
    background:#1a1f2e!important;color:#fff!important;
    font-size:5.5pt;font-weight:800;text-transform:uppercase;
    text-align:center;padding:2px 2px;border-right:1px solid #444;
    white-space:nowrap;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;
}
table.cct thead tr.G th.tl{text-align:left;}
table.cct thead tr.G th:last-child{border-right:none;}

table.cct thead tr.S th{
    background:#2d3748!important;color:#e2e8f0!important;
    font-size:5pt;font-weight:700;text-transform:uppercase;
    text-align:center;padding:2px 2px;border-right:1px solid #444;
    border-bottom:1.5px solid #888;white-space:nowrap;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;
}
table.cct thead tr.S th.tl{text-align:left;}
table.cct thead tr.S th:last-child{border-right:none;}

table.cct thead tr.TH td{
    background:#dce6f7!important;color:#0d2257;
    font-size:5.5pt;font-weight:900;
    padding:2px 2px;text-align:right;
    border-bottom:1.5px solid #6b87bf;
    border-top:1px solid #bbb;
    white-space:nowrap;font-family:'Courier New',monospace;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;
}
table.cct thead tr.TH td.tl{text-align:left;font-family:Arial,sans-serif;}

table.cct tbody td{
    padding:1.5px 2px;text-align:right;
    border-bottom:.4px solid #ddd;
    white-space:nowrap;vertical-align:middle;
}
table.cct tbody td.tl{text-align:left;}
table.cct tbody td.rc{font-weight:700;font-size:6pt;}
table.cct tbody td.dt{font-family:'Courier New',monospace;font-size:5.5pt;color:#333;}

table.cct tbody tr:nth-child(even) td{background:#f4f4f4!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
table.cct tbody tr:nth-child(odd)  td{background:#fff;}

/* Column tints */
table.cct .col-inv  {background:#eef3ff!important;}
table.cct .col-cc   {background:#eefaf4!important;}
table.cct .col-sr   {background:#eefafa!important;}
table.cct .col-dep  {background:#eeeeff!important;}
table.cct .col-var  {background:#fff6ee!important;}
table.cct .col-tr   {background:#eef6ff!important;}
table.cct .col-pay  {background:#fbf0ff!important;}
table.cct tbody tr:nth-child(even) .col-inv {background:#e5ecff!important;}
table.cct tbody tr:nth-child(even) .col-cc  {background:#e5f5ed!important;}
table.cct tbody tr:nth-child(even) .col-sr  {background:#e5f5f5!important;}
table.cct tbody tr:nth-child(even) .col-dep {background:#e5e5ff!important;}
table.cct tbody tr:nth-child(even) .col-var {background:#ffede5!important;}
table.cct tbody tr:nth-child(even) .col-tr  {background:#e5eeff!important;}
table.cct tbody tr:nth-child(even) .col-pay {background:#f5e5ff!important;}

table.cct tfoot td{
    background:#1a1f2e!important;color:#e2e8f0!important;
    padding:2px 2px;font-size:6pt;font-weight:900;
    text-align:right;border-top:1.5px solid #555;
    white-space:nowrap;font-family:'Courier New',monospace;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;
}
table.cct tfoot td.tl{text-align:left;font-family:Arial,sans-serif;color:#94a3b8!important;}

/* value classes */
.dash{color:#aaa;}
.num{font-family:'Courier New',monospace;}
.d-ok{color:#166534;font-weight:700;font-family:'Courier New',monospace;}
.d-exc{color:#166534;font-weight:700;font-family:'Courier New',monospace;}
.d-sht{color:#991b1b;font-weight:700;font-family:'Courier New',monospace;}
.tbd-bf{color:#991b1b;font-weight:700;font-family:'Courier New',monospace;}
.tbd-bp{color:#166534;font-weight:700;font-family:'Courier New',monospace;}
.tr-out{color:#991b1b;font-weight:800;font-family:'Courier New',monospace;}
.tr-in{color:#166534;font-weight:800;font-family:'Courier New',monospace;}
.charge-val{color:#991b1b;font-weight:700;}
.absorb-val{color:#1e40af;font-weight:700;}

/* ── LEGEND ── */
.legend{
    display:flex;gap:10px;flex-wrap:wrap;
    font-size:5.5pt;color:#444;
    border-top:1px solid #ccc;margin-top:4px;padding-top:3px;
}
.leg-sht{color:#991b1b;font-weight:700;}
.leg-exc{color:#166534;font-weight:700;}

table.cct{page-break-inside:auto;}
table.cct tbody tr{page-break-inside:avoid;}
</style>
</head>
<body>

<!-- ══ TOOLBAR (screen only) ══ -->
<div class="toolbar no-print">
    <form method="GET">
        <input type="hidden" name="search" value="1">
        <div>
            <label>Date From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from);?>">
        </div>
        <div>
            <label>Date To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to);?>">
        </div>
        <div>
            <label>Sales Rep</label>
            <select name="sr_code">
                <option value="">— All Reps —</option>
                <?php foreach($all_sr as $sr):?>
                <option value="<?php echo htmlspecialchars($sr);?>" <?php echo $f_sr===$sr?'selected':'';?>><?php echo htmlspecialchars($sr);?></option>
                <?php endforeach;?>
            </select>
        </div>
        <button type="submit" class="btn-go">&#128269; Generate</button>
    </form>
    <?php if($submitted && !empty($rows)):?>
    <button class="btn-print" onclick="window.print()">&#128438; Print / Save PDF</button>
    <?php endif;?>
</div>

<?php if(!$submitted):?>
<div style="text-align:center;padding:60px 20px;color:#666;font-size:14px;">
    Select a date range above and click <strong>Generate</strong>.
</div>
<?php elseif(empty($rows)):?>
<div style="text-align:center;padding:60px 20px;color:#666;font-size:14px;">
    No records found for the selected range.
</div>
<?php else:
$net_se      = $totals['new_short_excess'];
$is_short    = $net_se >  0.005;
$is_excess   = $net_se < -0.005;
$total_dep   = $totals['banked'] + $totals['handed'];
?>

<div class="page-wrap">

<!-- ══ REPORT HEADER ══ -->
<div class="rpt-header">
    <div>
        <div class="rpt-title">Daily Cash Collection Report</div>
        <div class="rpt-sub">Secondary Invoice &middot; Invoice Payments &middot; CC &amp; SR Collection &middot; Deposits &middot; Variance &amp; Shortage &middot; Transfers &middot; Pay Allocation</div>
    </div>
    <div class="rpt-meta">
        <strong>Period:</strong> <?php echo date('d M Y',strtotime($date_from)).' &ndash; '.date('d M Y',strtotime($date_to));?><br>
        <strong>Rep:</strong> <?php echo $f_sr ? htmlspecialchars($f_sr) : 'All Reps';?><br>
        <strong>Records:</strong> <?php echo count($rows);?> &nbsp;&middot;&nbsp;
        <strong>Generated:</strong> <?php echo date('d M Y H:i');?>
    </div>
</div>

<!-- ══ SUMMARY CARDS ══ -->
<div class="sc-row">
    <div class="sc">
        <div class="sc-lbl">Sec. Invoice (Net)</div>
        <div class="sc-val">Rs. <?php echo number_format($totals['sinv_adj'],2);?></div>
    </div>
    <div class="sc">
        <div class="sc-lbl">CC Collection</div>
        <div class="sc-val">Rs. <?php echo number_format($totals['cc_total'],2);?></div>
    </div>
    <div class="sc">
        <div class="sc-lbl">SR Collection</div>
        <div class="sc-val">Rs. <?php echo number_format($totals['sr_total'],2);?></div>
    </div>
    <div class="sc">
        <div class="sc-lbl">Total Deposited</div>
        <div class="sc-val">Rs. <?php echo number_format($total_dep,2);?></div>
    </div>
    <div class="sc">
        <div class="sc-lbl">Net Short / Excess</div>
        <div class="sc-val" style="color:<?php echo $is_short?'#991b1b':($is_excess?'#166534':'#000');?>;">
            Rs. <?php echo number_format(abs($net_se),2);?>
            <?php echo $is_short?' (Short)':($is_excess?' (Excess)':' (OK)');?>
        </div>
    </div>
</div>

<!-- ══ COLLECTION SUMMARY BOX ══ -->
<div class="coll-summary">
    <div class="coll-summary-head">&#9660; Collection Summary</div>
    <div class="coll-summary-body">

        <div class="csb-section-head" style="grid-column:span 6;">CC Collection Breakdown</div>

        <div class="csb-cell">
            <div class="csb-lbl">CC Daily Sales</div>
            <div class="csb-val blue">Rs. <?php echo number_format($totals['cc_daily_sale'],2);?></div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">CC Rcvd Credit</div>
            <div class="csb-val">Rs. <?php echo number_format($totals['cc_rcvd_credit'],2);?></div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">CC Rtn Cheque</div>
            <div class="csb-val">Rs. <?php echo number_format($totals['cc_rcvd_rtn_chq'],2);?></div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">CC Rtn Charges</div>
            <div class="csb-val">Rs. <?php echo number_format($totals['cc_rcvd_rtn_chgs'],2);?></div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">CC Sent Back</div>
            <div class="csb-val">Rs. <?php echo number_format($totals['cc_rcvd_sent_back'],2);?></div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">CC Total</div>
            <div class="csb-val green">Rs. <?php echo number_format($totals['cc_total'],2);?></div>
        </div>

        <div class="csb-section-head" style="grid-column:span 6;">SR Collection Breakdown</div>

        <div class="csb-cell">
            <div class="csb-lbl">SR Daily Sales</div>
            <div class="csb-val blue">Rs. <?php echo number_format($totals['sr_daily_sale'],2);?></div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">SR Rcvd Credit</div>
            <div class="csb-val">Rs. <?php echo number_format($totals['sr_rcvd_credit'],2);?></div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">SR Rtn Cheque</div>
            <div class="csb-val">Rs. <?php echo number_format($totals['sr_rcvd_rtn_chq'],2);?></div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">SR Rtn Charges</div>
            <div class="csb-val">Rs. <?php echo number_format($totals['sr_rcvd_rtn_chgs'],2);?></div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">SR Sent Back</div>
            <div class="csb-val">Rs. <?php echo number_format($totals['sr_rcvd_sent_back'],2);?></div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">SR Total</div>
            <div class="csb-val green">Rs. <?php echo number_format($totals['sr_total'],2);?></div>
        </div>

        <div class="csb-section-head" style="grid-column:span 6;">Deposit &amp; Variance Summary</div>

        <div class="csb-cell">
            <div class="csb-lbl">Grand Collection</div>
            <div class="csb-val blue">Rs. <?php echo number_format($totals['total_coll'],2);?></div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">Banked (CC)</div>
            <div class="csb-val">Rs. <?php echo number_format($totals['banked_cc'],2);?></div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">Banked (SR)</div>
            <div class="csb-val">Rs. <?php echo number_format($totals['banked_sr'],2);?></div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">Handed BO (CC)</div>
            <div class="csb-val">Rs. <?php echo number_format($totals['handed_cc'],2);?></div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">Handed BO (SR)</div>
            <div class="csb-val">Rs. <?php echo number_format($totals['handed_sr'],2);?></div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">Total Deposited</div>
            <div class="csb-val green">Rs. <?php echo number_format($total_dep,2);?></div>
        </div>

        <div class="csb-cell">
            <div class="csb-lbl">Short CC</div>
            <div class="csb-val <?php echo $totals['short_cc']>0?'red':'';?>">
                Rs. <?php echo number_format($totals['short_cc'],2);?>
            </div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">Short SR</div>
            <div class="csb-val <?php echo $totals['short_sr']>0?'red':'';?>">
                Rs. <?php echo number_format($totals['short_sr'],2);?>
            </div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">Short/Excess (Raw)</div>
            <div class="csb-val <?php echo abs($totals['bank_diff'])>0.005?($totals['bank_diff']>0?'red':'green'):'';?>">
                <?php echo pse($totals['bank_diff']);?>
            </div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">Transfer Out</div>
            <div class="csb-val <?php echo $totals['t_out']>0?'red':'';?>">
                Rs. <?php echo number_format($totals['t_out'],2);?>
            </div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">Transfer In</div>
            <div class="csb-val <?php echo $totals['t_in']>0?'green':'';?>">
                Rs. <?php echo number_format($totals['t_in'],2);?>
            </div>
        </div>
        <div class="csb-cell">
            <div class="csb-lbl">Final Short / Excess</div>
            <div class="csb-val <?php echo $is_short?'red':($is_excess?'green':'');?>">
                Rs. <?php echo number_format(abs($net_se),2);?>
                <?php echo $is_short?' (Short)':($is_excess?' (Excess)':' ✓');?>
            </div>
        </div>

    </div>
</div>

<!-- ══ MAIN TABLE ══ -->
<table class="cct">
<thead>
  <tr class="G">
    <th class="tl" rowspan="2" style="min-width:32px;">Rep</th>
    <th class="tl" rowspan="2" style="min-width:42px;">Date</th>
    <th colspan="4">Daily Invoice Details</th>
    <th colspan="4">Invoice Payments</th>
    <th colspan="6">CC Collection</th>
    <th colspan="6">SR Collection</th>
    <th colspan="1">Total</th>
    <th colspan="4">Cash Deposit</th>
    <th colspan="6">Variance &amp; Short/Excess</th>
    <th colspan="1">Transfer</th>
    <th colspan="1">Final S/E</th>
    <th colspan="3">Pay Allocation</th>
  </tr>
  <tr class="S">
    <th class="col-inv">Sec.Inv</th>
    <th class="col-inv">BF&minus;</th>
    <th class="col-inv">BP+</th>
    <th class="col-inv">Net Sec.</th>
    <th>Cash</th>
    <th>Cheque</th>
    <th>Credit</th>
    <th>Diff</th>
    <th class="col-cc">Daily</th>
    <th class="col-cc">Cr</th>
    <th class="col-cc">RC</th>
    <th class="col-cc">RCh</th>
    <th class="col-cc">SB</th>
    <th class="col-cc">CC Tot</th>
    <th class="col-sr">Daily</th>
    <th class="col-sr">Cr</th>
    <th class="col-sr">RC</th>
    <th class="col-sr">RCh</th>
    <th class="col-sr">SB</th>
    <th class="col-sr">SR Tot</th>
    <th>Grand</th>
    <th class="col-dep">Dep CC</th>
    <th class="col-dep">Dep SR</th>
    <th class="col-dep">BO CC</th>
    <th class="col-dep">BO SR</th>
    <th class="col-var">Var CC</th>
    <th class="col-var">Var SR</th>
    <th class="col-var">Sht CC</th>
    <th class="col-var">Sht SR</th>
    <th class="col-var">S/E</th>
    <th class="col-var">Old S/E</th>
    <th class="col-tr">Xfer</th>
    <th class="col-tr">Final</th>
    <th class="col-pay">Charge</th>
    <th class="col-pay">Absorb</th>
    <th class="col-pay">PVar</th>
  </tr>
  <tr class="TH">
    <td class="tl" colspan="2">&#931; Total &mdash; <?php echo count($rows);?> rows</td>
    <td class="col-inv"><?php echo pv($totals['sinv']);?></td>
    <td class="col-inv tbd-bf"><?php echo $totals['sinv_bf']>0?'&minus;'.number_format($totals['sinv_bf'],2):'&mdash;';?></td>
    <td class="col-inv tbd-bp"><?php echo $totals['sinv_bp']>0?'+'.number_format($totals['sinv_bp'],2):'&mdash;';?></td>
    <td class="col-inv" style="font-weight:900;"><?php echo pv($totals['sinv_adj']);?></td>
    <td><?php echo pv($totals['cash_paid']);?></td>
    <td><?php echo pv($totals['cheque_paid']);?></td>
    <td><?php echo pv($totals['credit']);?></td>
    <td><?php echo pse($totals['pay_diff']);?></td>
    <td class="col-cc"><?php echo pv($totals['cc_daily_sale']);?></td>
    <td class="col-cc"><?php echo pv($totals['cc_rcvd_credit']);?></td>
    <td class="col-cc"><?php echo pv($totals['cc_rcvd_rtn_chq']);?></td>
    <td class="col-cc"><?php echo pv($totals['cc_rcvd_rtn_chgs']);?></td>
    <td class="col-cc"><?php echo pv($totals['cc_rcvd_sent_back']);?></td>
    <td class="col-cc" style="font-weight:900;"><?php echo pv($totals['cc_total']);?></td>
    <td class="col-sr"><?php echo pv($totals['sr_daily_sale']);?></td>
    <td class="col-sr"><?php echo pv($totals['sr_rcvd_credit']);?></td>
    <td class="col-sr"><?php echo pv($totals['sr_rcvd_rtn_chq']);?></td>
    <td class="col-sr"><?php echo pv($totals['sr_rcvd_rtn_chgs']);?></td>
    <td class="col-sr"><?php echo pv($totals['sr_rcvd_sent_back']);?></td>
    <td class="col-sr" style="font-weight:900;"><?php echo pv($totals['sr_total']);?></td>
    <td style="font-weight:900;"><?php echo pv($totals['total_coll']);?></td>
    <td class="col-dep"><?php echo pv($totals['banked_cc']);?></td>
    <td class="col-dep"><?php echo pv($totals['banked_sr']);?></td>
    <td class="col-dep"><?php echo pv($totals['handed_cc']);?></td>
    <td class="col-dep"><?php echo pv($totals['handed_sr']);?></td>
    <td class="col-var"><?php echo pse($totals['variance_cc']);?></td>
    <td class="col-var"><?php echo pse($totals['variance_sr']);?></td>
    <td class="col-var"><?php echo $totals['short_cc']>0?'<span class="d-sht">'.number_format($totals['short_cc'],2).'</span>':'&mdash;';?></td>
    <td class="col-var"><?php echo $totals['short_sr']>0?'<span class="d-sht">'.number_format($totals['short_sr'],2).'</span>':'&mdash;';?></td>
    <td class="col-var"><?php echo pse($totals['bank_diff']);?></td>
    <td class="col-var"><?php echo pse($totals['cash_short_excess']);?></td>
    <td class="col-tr"><?php echo ptr($totals['t_out'],$totals['t_in']);?></td>
    <td class="col-tr"><?php echo pse($totals['new_short_excess']);?></td>
    <td class="col-pay"><?php echo pv($totals['p_charge']);?></td>
    <td class="col-pay"><?php echo pv($totals['p_absorb']);?></td>
    <td class="col-pay">&mdash;</td>
  </tr>
</thead>
<tbody>
<?php foreach($rows as $i=>$r): ?>
<tr>
  <td class="tl rc"><?php echo htmlspecialchars($r['sr_code']);?></td>
  <td class="tl dt"><?php echo date('d M y',strtotime($r['del_date']));?></td>
  <td class="col-inv num"><?php echo pv($r['sinv']);?></td>
  <td class="col-inv"><?php echo pbf($r['sinv_bf']);?></td>
  <td class="col-inv"><?php echo pbp($r['sinv_bp']);?></td>
  <td class="col-inv num" style="font-weight:800;"><?php echo pv($r['sinv_adj']);?></td>
  <td class="num"><?php echo $r['cash_paid']>0?number_format($r['cash_paid'],2):'<span class="dash">&mdash;</span>';?></td>
  <td class="num"><?php echo $r['cheque_paid']>0?number_format($r['cheque_paid'],2):'<span class="dash">&mdash;</span>';?></td>
  <td class="num"><?php echo $r['credit']>0?number_format($r['credit'],2):'<span class="dash">&mdash;</span>';?></td>
  <td><?php echo pse($r['pay_diff']);?></td>
  <td class="col-cc num"><?php echo $r['cc_daily_sale']>0?number_format($r['cc_daily_sale'],2):'<span class="dash">&mdash;</span>';?></td>
  <td class="col-cc num"><?php echo $r['cc_rcvd_credit']>0?number_format($r['cc_rcvd_credit'],2):'<span class="dash">&mdash;</span>';?></td>
  <td class="col-cc num"><?php echo $r['cc_rcvd_rtn_chq']>0?number_format($r['cc_rcvd_rtn_chq'],2):'<span class="dash">&mdash;</span>';?></td>
  <td class="col-cc num"><?php echo $r['cc_rcvd_rtn_chgs']>0?number_format($r['cc_rcvd_rtn_chgs'],2):'<span class="dash">&mdash;</span>';?></td>
  <td class="col-cc num"><?php echo $r['cc_rcvd_sent_back']>0?number_format($r['cc_rcvd_sent_back'],2):'<span class="dash">&mdash;</span>';?></td>
  <td class="col-cc num" style="font-weight:800;"><?php echo pv($r['cc_total']);?></td>
  <td class="col-sr num"><?php echo $r['sr_daily_sale']>0?number_format($r['sr_daily_sale'],2):'<span class="dash">&mdash;</span>';?></td>
  <td class="col-sr num"><?php echo $r['sr_rcvd_credit']>0?number_format($r['sr_rcvd_credit'],2):'<span class="dash">&mdash;</span>';?></td>
  <td class="col-sr num"><?php echo $r['sr_rcvd_rtn_chq']>0?number_format($r['sr_rcvd_rtn_chq'],2):'<span class="dash">&mdash;</span>';?></td>
  <td class="col-sr num"><?php echo $r['sr_rcvd_rtn_chgs']>0?number_format($r['sr_rcvd_rtn_chgs'],2):'<span class="dash">&mdash;</span>';?></td>
  <td class="col-sr num"><?php echo $r['sr_rcvd_sent_back']>0?number_format($r['sr_rcvd_sent_back'],2):'<span class="dash">&mdash;</span>';?></td>
  <td class="col-sr num" style="font-weight:800;"><?php echo pv($r['sr_total']);?></td>
  <td class="num" style="font-weight:900;"><?php echo pv($r['total_coll']);?></td>
  <td class="col-dep num"><?php echo $r['banked_cc']>0?number_format($r['banked_cc'],2):'<span class="dash">&mdash;</span>';?></td>
  <td class="col-dep num"><?php echo $r['banked_sr']>0?number_format($r['banked_sr'],2):'<span class="dash">&mdash;</span>';?></td>
  <td class="col-dep num"><?php echo $r['handed_cc']>0?number_format($r['handed_cc'],2):'<span class="dash">&mdash;</span>';?></td>
  <td class="col-dep num"><?php echo $r['handed_sr']>0?number_format($r['handed_sr'],2):'<span class="dash">&mdash;</span>';?></td>
  <td class="col-var"><?php echo pse($r['variance_cc']);?></td>
  <td class="col-var"><?php echo pse($r['variance_sr']);?></td>
  <td class="col-var"><?php echo $r['short_cc']>0?'<span class="d-sht">'.number_format($r['short_cc'],2).'</span>':'<span class="dash">&mdash;</span>';?></td>
  <td class="col-var"><?php echo $r['short_sr']>0?'<span class="d-sht">'.number_format($r['short_sr'],2).'</span>':'<span class="dash">&mdash;</span>';?></td>
  <td class="col-var"><?php echo pse($r['bank_diff']);?></td>
  <td class="col-var"><?php echo pse($r['cash_short_excess']);?></td>
  <td class="col-tr"><?php echo ptr($r['t_out'],$r['t_in']);?></td>
  <td class="col-tr"><?php echo pse($r['new_short_excess']);?></td>
  <td class="col-pay"><?php echo $r['p_charge']>0?'<span class="charge-val">'.number_format($r['p_charge'],2).'</span>':'<span class="dash">&mdash;</span>';?></td>
  <td class="col-pay"><?php echo $r['p_absorb']>0?'<span class="absorb-val">'.number_format($r['p_absorb'],2).'</span>':'<span class="dash">&mdash;</span>';?></td>
  <td class="col-pay"><?php echo pvar($r['p_variance']);?></td>
</tr>
<?php endforeach;?>
</tbody>
<tfoot>
  <tr>
    <td class="tl" colspan="2">TOTAL &mdash; <?php echo count($rows);?> records</td>
    <td class="col-inv"><?php echo pv($totals['sinv']);?></td>
    <td class="col-inv"><?php echo $totals['sinv_bf']>0?'&minus;'.number_format($totals['sinv_bf'],2):'&mdash;';?></td>
    <td class="col-inv"><?php echo $totals['sinv_bp']>0?'+'.number_format($totals['sinv_bp'],2):'&mdash;';?></td>
    <td class="col-inv"><?php echo pv($totals['sinv_adj']);?></td>
    <td><?php echo pv($totals['cash_paid']);?></td>
    <td><?php echo pv($totals['cheque_paid']);?></td>
    <td><?php echo pv($totals['credit']);?></td>
    <td><?php echo pse($totals['pay_diff']);?></td>
    <td class="col-cc"><?php echo pv($totals['cc_daily_sale']);?></td>
    <td class="col-cc"><?php echo pv($totals['cc_rcvd_credit']);?></td>
    <td class="col-cc"><?php echo pv($totals['cc_rcvd_rtn_chq']);?></td>
    <td class="col-cc"><?php echo pv($totals['cc_rcvd_rtn_chgs']);?></td>
    <td class="col-cc"><?php echo pv($totals['cc_rcvd_sent_back']);?></td>
    <td class="col-cc"><?php echo pv($totals['cc_total']);?></td>
    <td class="col-sr"><?php echo pv($totals['sr_daily_sale']);?></td>
    <td class="col-sr"><?php echo pv($totals['sr_rcvd_credit']);?></td>
    <td class="col-sr"><?php echo pv($totals['sr_rcvd_rtn_chq']);?></td>
    <td class="col-sr"><?php echo pv($totals['sr_rcvd_rtn_chgs']);?></td>
    <td class="col-sr"><?php echo pv($totals['sr_rcvd_sent_back']);?></td>
    <td class="col-sr"><?php echo pv($totals['sr_total']);?></td>
    <td><?php echo pv($totals['total_coll']);?></td>
    <td class="col-dep"><?php echo pv($totals['banked_cc']);?></td>
    <td class="col-dep"><?php echo pv($totals['banked_sr']);?></td>
    <td class="col-dep"><?php echo pv($totals['handed_cc']);?></td>
    <td class="col-dep"><?php echo pv($totals['handed_sr']);?></td>
    <td class="col-var"><?php echo pse($totals['variance_cc']);?></td>
    <td class="col-var"><?php echo pse($totals['variance_sr']);?></td>
    <td class="col-var"><?php echo $totals['short_cc']>0?number_format($totals['short_cc'],2):'&mdash;';?></td>
    <td class="col-var"><?php echo $totals['short_sr']>0?number_format($totals['short_sr'],2):'&mdash;';?></td>
    <td class="col-var"><?php echo pse($totals['bank_diff']);?></td>
    <td class="col-var"><?php echo pse($totals['cash_short_excess']);?></td>
    <td class="col-tr"><?php echo ptr($totals['t_out'],$totals['t_in']);?></td>
    <td class="col-tr"><?php echo pse($totals['new_short_excess']);?></td>
    <td class="col-pay"><?php echo pv($totals['p_charge']);?></td>
    <td class="col-pay"><?php echo pv($totals['p_absorb']);?></td>
    <td class="col-pay">&mdash;</td>
  </tr>
</tfoot>
</table>

<!-- ══ LEGEND ══ -->
<div class="legend">
    <span><span class="leg-exc">▲ = Excess</span></span>
    <span><span class="leg-sht">▼ = Short</span></span>
    <span>✓ = Balanced (zero)</span>
    <span><span class="tbd-bf">BF&minus;</span> = Invoice moved forward (subtracted)</span>
    <span><span class="tbd-bp">BP+</span> = Invoice brought back (added)</span>
    <span><strong>Xfer</strong> = Cross-charge transfer ▼Out / ▲In</span>
    <span><span style="color:#991b1b;font-weight:700;">Charge</span> = Employee charged &nbsp;|&nbsp; <span style="color:#1e40af;font-weight:700;">Absorb</span> = Company absorbed</span>
</div>

</div><!-- .page-wrap -->
<?php endif;?>
</body>
</html>
