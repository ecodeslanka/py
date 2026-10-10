<?php
include 'config.php';
include 'header.php';

$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$f_sr      = trim($_GET['sr_code'] ?? '');
$submitted = isset($_GET['search']);

$df     = mysqli_real_escape_string($conn, $date_from);
$dt     = mysqli_real_escape_string($conn, $date_to);
$sr_esc = $f_sr ? mysqli_real_escape_string($conn, $f_sr) : '';

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

/* ── add employee columns to existing table if they were not there yet ── */
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM cash_shortage_transfers LIKE 'employee_id'");
if ($col_check && mysqli_num_rows($col_check) === 0) {
    mysqli_query($conn, "ALTER TABLE cash_shortage_transfers
        ADD COLUMN employee_id   INT          NULL AFTER note,
        ADD COLUMN employee_name VARCHAR(200) NULL AFTER employee_id");
}

$sr_res = mysqli_query($conn, "SELECT DISTINCT sr_code FROM field_summary WHERE sr_code IS NOT NULL AND sr_code!='' ORDER BY sr_code");
$all_sr = [];
if ($sr_res) while ($r = mysqli_fetch_assoc($sr_res)) $all_sr[] = $r['sr_code'];

$nm_res = mysqli_query($conn, "SELECT employee_id, employee_full_name FROM employees WHERE active=1");
$names  = [];
if ($nm_res) while ($r = mysqli_fetch_assoc($nm_res)) $names[$r['employee_id']] = $r['employee_full_name'];

$emp_list = [];
$er = mysqli_query($conn,
    "SELECT id, COALESCE(NULLIF(employee_id,''),CONCAT('EMP-',id)) AS emp_code,
     COALESCE(NULLIF(name_with_initials,''),NULLIF(employee_full_name,''),CONCAT('Employee #',id)) AS emp_name
     FROM employees
     WHERE COALESCE(status,'') NOT IN ('Resigned','Terminated','Inactive','inactive','resigned','terminated')
     ORDER BY emp_name ASC");
if (!$er || mysqli_num_rows($er)===0)
    $er = mysqli_query($conn,"SELECT id,
     COALESCE(NULLIF(employee_id,''),CONCAT('EMP-',id)) AS emp_code,
     COALESCE(NULLIF(name_with_initials,''),NULLIF(employee_full_name,''),CONCAT('Employee #',id)) AS emp_name
     FROM employees ORDER BY emp_name ASC LIMIT 500");
if ($er) while ($row = mysqli_fetch_assoc($er)) $emp_list[] = $row;

/* ═══════════ DATA ═══════════ */
$rows   = [];
$totals = array_fill_keys([
    'sinv',
    'sinv_bf','sinv_bp','sinv_adj',
    'cash_paid','cheque_paid','credit','pay_diff',
    'cc_daily_sale','cc_rcvd_credit','cc_rcvd_rtn_chq','cc_rcvd_rtn_chgs','cc_rcvd_sent_back','cc_total',
    'sr_daily_sale','sr_rcvd_credit','sr_rcvd_rtn_chq','sr_rcvd_rtn_chgs','sr_rcvd_sent_back','sr_total',
    'total_coll',
    'banked','handed',
    'banked_sr','banked_cc',
    'handed_sr','handed_cc',
    'variance_cc','variance_sr',
    'short_cc','short_sr',
    'bank_diff','cash_short_excess',
    't_out','t_in','shortage_adj',
    'new_short_excess',
    'p_charge','p_absorb'
], 0.0);

if ($submitted) {
    $where_fs = "fs.delivery_date BETWEEN '$df' AND '$dt'" . ($sr_esc ? " AND fs.sr_code='$sr_esc'" : '');
    $sid_w    = "sid.delivery_date BETWEEN '$df' AND '$dt' AND sid.status='imported'" . ($sr_esc ? " AND sid.sales_person_code='$sr_esc'" : '');
    $srCnd    = $sr_esc ? "AND fs.sr_code='$sr_esc'" : '';

    /* ── MASTER: build date|sr_code list via separate queries merged in PHP ──
       Each source runs independently so a table with zero rows or a missing
       table never silently kills the whole result.
    ─────────────────────────────────────────────────────────────────────── */
    $master_set = array();  // "YYYY-MM-DD|SR" => true  (auto-dedup)

    // 1. field_summary — normal invoiced days (original behaviour)
    $q1 = "SELECT DISTINCT delivery_date, sr_code FROM field_summary
            WHERE delivery_date BETWEEN '$df' AND '$dt'
              AND sr_code IS NOT NULL AND sr_code != ''";
    if ($sr_esc) $q1 .= " AND sr_code = '$sr_esc'";
    $res1 = mysqli_query($conn, $q1);
    if ($res1) while ($row = mysqli_fetch_row($res1))
        $master_set[$row[0].'|'.$row[1]] = true;

    // 2. cash-collection days — payment_date may differ from field_summary.delivery_date
    $q2 = "SELECT DISTINCT ip.payment_date, fs2.sr_code
            FROM invoice_payments ip
            INNER JOIN field_summary_details fsd2 ON fsd2.id = ip.field_summary_detail_id
            INNER JOIN field_summary fs2 ON fs2.id = fsd2.field_summary_id
            WHERE ip.payment_method = 'cash' AND ip.is_reversed = 0
              AND ip.payment_date BETWEEN '$df' AND '$dt'
              AND fs2.sr_code IS NOT NULL AND fs2.sr_code != ''";
    if ($sr_esc) $q2 .= " AND fs2.sr_code = '$sr_esc'";
    $res2 = mysqli_query($conn, $q2);
    if ($res2) while ($row = mysqli_fetch_row($res2))
        $master_set[$row[0].'|'.$row[1]] = true;

    // 3. deposit days
    $q3 = "SELECT DISTINCT delivery_date, rep_code FROM cc_cash_deposit_reps
            WHERE delivery_date BETWEEN '$df' AND '$dt'
              AND delivery_date IS NOT NULL AND delivery_date != '0000-00-00'
              AND rep_code IS NOT NULL AND rep_code != ''";
    if ($sr_esc) $q3 .= " AND rep_code = '$sr_esc'";
    $res3 = mysqli_query($conn, $q3);
    if ($res3) while ($row = mysqli_fetch_row($res3))
        $master_set[$row[0].'|'.$row[1]] = true;

    // 4. transfer-from
    $q4 = "SELECT DISTINCT delivery_date, from_sr_code FROM cash_shortage_transfers
            WHERE delivery_date BETWEEN '$df' AND '$dt'
              AND from_sr_code IS NOT NULL AND from_sr_code != ''";
    if ($sr_esc) $q4 .= " AND from_sr_code = '$sr_esc'";
    $res4 = mysqli_query($conn, $q4);
    if ($res4) while ($row = mysqli_fetch_row($res4))
        $master_set[$row[0].'|'.$row[1]] = true;

    // 5. transfer-to
    $q5 = "SELECT DISTINCT delivery_date, to_sr_code FROM cash_shortage_transfers
            WHERE delivery_date BETWEEN '$df' AND '$dt'
              AND to_sr_code IS NOT NULL AND to_sr_code != ''";
    if ($sr_esc) $q5 .= " AND to_sr_code = '$sr_esc'";
    $res5 = mysqli_query($conn, $q5);
    if ($res5) while ($row = mysqli_fetch_row($res5))
        $master_set[$row[0].'|'.$row[1]] = true;

    // 6. pay-allocation days
    $q6 = "SELECT DISTINCT pay_date, sr_code FROM cash_summary_pay_allocations
            WHERE pay_date BETWEEN '$df' AND '$dt'
              AND sr_code IS NOT NULL AND sr_code != ''";
    if ($sr_esc) $q6 .= " AND sr_code = '$sr_esc'";
    $res6 = mysqli_query($conn, $q6);
    if ($res6) while ($row = mysqli_fetch_row($res6))
        $master_set[$row[0].'|'.$row[1]] = true;

    $master = array_keys($master_set);
    usort($master, 'strcmp');  // sort by date then sr_code

    /* ── Secondary invoice totals (original, by delivery_date + sr) ── */
    $r3=mysqli_query($conn,"SELECT sid.sales_person_code AS sr_code, sid.delivery_date, COALESCE(SUM(sid.final_bill_amount),0) AS sinv FROM secondary_invoice_import_details sid WHERE $sid_w GROUP BY sid.sales_person_code, sid.delivery_date");
    $sinv_map=[];
    if($r3) while($r=mysqli_fetch_assoc($r3)) $sinv_map[$r['delivery_date'].'|'.$r['sr_code']]=floatval($r['sinv']);

    /* TBD BF */
    $tbd_bf_map = [];
    $tbd_bf_q = mysqli_query($conn, "
        SELECT fs.sr_code, fs.delivery_date,
               COALESCE(SUM(sid.final_bill_amount), 0) AS bf_val
        FROM field_summary_details fsd
        INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
        LEFT JOIN secondary_invoice_import_details sid
               ON sid.bill_no = fsd.invoice_num
              AND sid.status  = 'imported'
        WHERE fs.delivery_date BETWEEN '$df' AND '$dt'
          " . ($sr_esc ? "AND fs.sr_code='$sr_esc'" : '') . "
          AND fsd.to_be_delivery = 1
          AND fsd.to_be_delivery_date > fs.delivery_date
        GROUP BY fs.sr_code, fs.delivery_date
    ");
    if ($tbd_bf_q) {
        while ($r = mysqli_fetch_assoc($tbd_bf_q)) {
            $tbd_bf_map[$r['delivery_date'].'|'.$r['sr_code']] = floatval($r['bf_val']);
        }
    }

    /* TBD BP */
    $tbd_bp_map = [];
    $tbd_bp_q = mysqli_query($conn, "
        SELECT fs.sr_code,
               fsd.to_be_delivery_date AS delivery_date,
               COALESCE(SUM(sid.final_bill_amount), 0) AS bp_val
        FROM field_summary_details fsd
        INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
        LEFT JOIN secondary_invoice_import_details sid
               ON sid.bill_no = fsd.invoice_num
              AND sid.status  = 'imported'
        WHERE fsd.to_be_delivery = 1
          AND fsd.to_be_delivery_date BETWEEN '$df' AND '$dt'
          AND fsd.to_be_delivery_date > fs.delivery_date
          " . ($sr_esc ? "AND fs.sr_code='$sr_esc'" : '') . "
        GROUP BY fs.sr_code, fsd.to_be_delivery_date
    ");
    if ($tbd_bp_q) {
        while ($r = mysqli_fetch_assoc($tbd_bp_q)) {
            $k = $r['delivery_date'].'|'.$r['sr_code'];
            $tbd_bp_map[$k] = ($tbd_bp_map[$k] ?? 0) + floatval($r['bp_val']);
        }
    }

    $r4=mysqli_query($conn,"SELECT fs.delivery_date, fs.sr_code, COALESCE(SUM(CASE WHEN ip.payment_method='cash' AND ip.payment_date=fs.delivery_date AND ip.is_reversed=0 THEN ip.amount ELSE 0 END),0) AS cash_paid, COALESCE(SUM(CASE WHEN ip.payment_method='cheque' AND ip.payment_date=fs.delivery_date AND ip.is_reversed=0 THEN ip.amount ELSE 0 END),0) AS cheque_paid FROM invoice_payments ip INNER JOIN field_summary fs ON fs.id=ip.field_summary_id WHERE $where_fs GROUP BY fs.delivery_date, fs.sr_code");
    $invpay_map=[];
    if($r4) while($r=mysqli_fetch_assoc($r4)) $invpay_map[$r['delivery_date'].'|'.$r['sr_code']]=$r;

    $paid_by_det=[];
    $rpd=mysqli_query($conn,"SELECT ip.field_summary_detail_id, ROUND(COALESCE(SUM(ip.amount),0),2) AS paid FROM invoice_payments ip INNER JOIN field_summary fs ON fs.id=ip.field_summary_id WHERE $where_fs AND ip.payment_date <= fs.delivery_date GROUP BY ip.field_summary_detail_id");
    if($rpd) while($r=mysqli_fetch_assoc($rpd)) $paid_by_det[intval($r['field_summary_detail_id'])]=floatval($r['paid']);
    $r5=mysqli_query($conn,"SELECT fs.sr_code, fs.delivery_date, fsd.id AS det_id, fsd.adjust_net_value AS row_adj FROM field_summary_details fsd INNER JOIN field_summary fs ON fs.id=fsd.field_summary_id WHERE $where_fs");
    $credit_map=[];
    if($r5) while($r=mysqli_fetch_assoc($r5)){
        $k=$r['delivery_date'].'|'.$r['sr_code'];
        $row_bal=max(0.0,floatval($r['row_adj'])-($paid_by_det[intval($r['det_id'])]??0.0));
        if(!isset($credit_map[$k])) $credit_map[$k]=0.0;
        $credit_map[$k]+=$row_bal;
    }

    /* ── Cash collection map — keyed by payment_date | sr_code ── */
    $r6=mysqli_query($conn,"
        SELECT ip.payment_date, fs.sr_code,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc' AND (ip.payment_source IS NULL OR ip.payment_source='' OR LOWER(ip.payment_source)='invoice') THEN ip.amount ELSE 0 END),0) AS cc_daily_sale,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc' AND LOWER(ip.payment_source) LIKE '%credit%'                   THEN ip.amount ELSE 0 END),0) AS cc_rcvd_credit,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc' AND ip.payment_source='return_cheque_settlement'               THEN ip.amount ELSE 0 END),0) AS cc_rcvd_rtn_chq,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc' AND ip.payment_source='return_charge_settlement'               THEN ip.amount ELSE 0 END),0) AS cc_rcvd_rtn_chgs,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc' AND ip.payment_source='sentback_cheque_settlement'             THEN ip.amount ELSE 0 END),0) AS cc_rcvd_sent_back,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'                                                                THEN ip.amount ELSE 0 END),0) AS cc_total,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND (ip.payment_source IS NULL OR ip.payment_source='' OR LOWER(ip.payment_source)='invoice') THEN ip.amount ELSE 0 END),0) AS sr_daily_sale,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND LOWER(ip.payment_source) LIKE '%credit%'                  THEN ip.amount ELSE 0 END),0) AS sr_rcvd_credit,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND ip.payment_source='return_cheque_settlement'              THEN ip.amount ELSE 0 END),0) AS sr_rcvd_rtn_chq,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND ip.payment_source='return_charge_settlement'              THEN ip.amount ELSE 0 END),0) AS sr_rcvd_rtn_chgs,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND ip.payment_source='sentback_cheque_settlement'            THEN ip.amount ELSE 0 END),0) AS sr_rcvd_sent_back,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc'                                                               THEN ip.amount ELSE 0 END),0) AS sr_total,
            COALESCE(SUM(ip.amount),0) AS total_coll
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id=ip.field_summary_detail_id
        INNER JOIN field_summary fs ON fs.id=fsd.field_summary_id
        WHERE ip.payment_method='cash' AND ip.is_reversed=0
          AND ip.payment_date BETWEEN '$df' AND '$dt' $srCnd
        GROUP BY ip.payment_date, fs.sr_code");
    $cash_map=[];
    if($r6) while($r=mysqli_fetch_assoc($r6)) $cash_map[$r['payment_date'].'|'.$r['sr_code']]=$r;

    $dep_rep_cnd = $sr_esc ? " AND r.rep_code='$sr_esc'" : '';
    $r7 = mysqli_query($conn, "
        SELECT r.delivery_date, r.rep_code,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=0 AND LOWER(COALESCE(d.collected_by,''))='sr' THEN r.amount ELSE 0 END),0) AS banked_sr,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=0 AND LOWER(COALESCE(d.collected_by,''))='cc' THEN r.amount ELSE 0 END),0) AS banked_cc,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=1 AND LOWER(COALESCE(d.collected_by,''))='sr' THEN r.amount ELSE 0 END),0) AS handed_sr,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=1 AND LOWER(COALESCE(d.collected_by,''))='cc' THEN r.amount ELSE 0 END),0) AS handed_cc
        FROM cc_cash_deposit_reps r
        INNER JOIN cc_cash_deposits d ON d.id = r.deposit_id
        WHERE r.delivery_date BETWEEN '$df' AND '$dt'
          AND r.delivery_date IS NOT NULL
          AND r.delivery_date != '0000-00-00'
          $dep_rep_cnd
        GROUP BY r.delivery_date, r.rep_code
    ");
    $dep_map=[];
    if($r7) while($r=mysqli_fetch_assoc($r7)) $dep_map[$r['delivery_date'].'|'.$r['rep_code']]=$r;

    $r8=mysqli_query($conn,"SELECT pay_date, sr_code, entry_type, employee_id, employee_name, amount, total_value FROM cash_summary_pay_allocations WHERE pay_date BETWEEN '$df' AND '$dt'".($sr_esc?" AND sr_code='$sr_esc'":'')." ORDER BY pay_date, sr_code, entry_type");
    $alloc_map=[];
    if($r8) while($r=mysqli_fetch_assoc($r8)){
        $k=$r['pay_date'].'|'.$r['sr_code'];
        if(!isset($alloc_map[$k])) $alloc_map[$k]=['p_charge'=>0,'p_absorb'=>0,'p_variance'=>null,'employees'=>[],'is_paid'=>false];
        if($r['entry_type']==='charge'){ $alloc_map[$k]['p_charge']+=floatval($r['amount']); $alloc_map[$k]['employees'][]=['employee_id'=>$r['employee_id'],'employee_name'=>$r['employee_name'],'amount'=>floatval($r['amount'])]; $alloc_map[$k]['is_paid']=true; }
        if($r['entry_type']==='absorb') { $alloc_map[$k]['p_absorb']=floatval($r['amount']); $alloc_map[$k]['is_paid']=true; }
        if($r['entry_type']==='variance') $alloc_map[$k]['p_variance']=floatval($r['amount']);
    }

    $tr_date_cnd_all = "delivery_date BETWEEN '$df' AND '$dt'";
    if ($sr_esc) {
        $tr_date_cnd_all .= " AND (from_sr_code='$sr_esc' OR to_sr_code='$sr_esc')";
    }
    $r9 = mysqli_query($conn, "
        SELECT id, from_sr_code, to_sr_code, delivery_date, transfer_date, amount, note,
               employee_id, employee_name
        FROM cash_shortage_transfers
        WHERE $tr_date_cnd_all
        ORDER BY id
    ");
    $transfer_out_map  = [];
    $transfer_in_map   = [];
    $transfer_rows_map = [];
    if($r9) while($r=mysqli_fetch_assoc($r9)){
        $kf = $r['delivery_date'].'|'.$r['from_sr_code'];
        $kt = $r['delivery_date'].'|'.$r['to_sr_code'];
        $transfer_out_map[$kf] = ($transfer_out_map[$kf]??0) + floatval($r['amount']);
        $transfer_in_map[$kt]  = ($transfer_in_map[$kt]??0)  + floatval($r['amount']);
        if(!isset($transfer_rows_map[$kf])) $transfer_rows_map[$kf]=[];
        if(!isset($transfer_rows_map[$kt])) $transfer_rows_map[$kt]=[];
        $transfer_rows_map[$kf][] = [
            'id'            => intval($r['id']),
            'direction'     => 'out',
            'other_sr'      => $r['to_sr_code'],
            'delivery_date' => $r['delivery_date'],
            'transfer_date' => $r['transfer_date'],
            'amount'        => floatval($r['amount']),
            'note'          => $r['note'],
            'employee_id'   => $r['employee_id'] ? intval($r['employee_id']) : null,
            'employee_name' => $r['employee_name']
        ];
        $transfer_rows_map[$kt][] = [
            'id'            => intval($r['id']),
            'direction'     => 'in',
            'other_sr'      => $r['from_sr_code'],
            'delivery_date' => $r['delivery_date'],
            'transfer_date' => $r['transfer_date'],
            'amount'        => floatval($r['amount']),
            'note'          => $r['note'],
            'employee_id'   => $r['employee_id'] ? intval($r['employee_id']) : null,
            'employee_name' => $r['employee_name']
        ];
    }

    foreach($master as $k){
        [$del_date,$sr_code]=explode('|',$k,2);
        $pm=$invpay_map[$k]??[];
        $dm=$dep_map[$k]??[];
        $cm=$cash_map[$del_date.'|'.$sr_code]??[];

        $sinv       = floatval($sinv_map[$k]??0);
        $sinv_bf    = floatval($tbd_bf_map[$k]??0);
        $sinv_bp    = floatval($tbd_bp_map[$k]??0);
        $sinv_adj   = $sinv - $sinv_bf + $sinv_bp;

        $cash_paid  = floatval($pm['cash_paid']??0);
        $cheque_paid= floatval($pm['cheque_paid']??0);
        $credit     = floatval($credit_map[$k]??0);
        $pay_diff   = $sinv_adj - ($cash_paid + $cheque_paid + $credit);

        $cc_daily_sale     = floatval($cm['cc_daily_sale']??0);
        $cc_rcvd_credit    = floatval($cm['cc_rcvd_credit']??0);
        $cc_rcvd_rtn_chq   = floatval($cm['cc_rcvd_rtn_chq']??0);
        $cc_rcvd_rtn_chgs  = floatval($cm['cc_rcvd_rtn_chgs']??0);
        $cc_rcvd_sent_back = floatval($cm['cc_rcvd_sent_back']??0);
        $cc_total          = floatval($cm['cc_total']??0);
        $sr_daily_sale     = floatval($cm['sr_daily_sale']??0);
        $sr_rcvd_credit    = floatval($cm['sr_rcvd_credit']??0);
        $sr_rcvd_rtn_chq   = floatval($cm['sr_rcvd_rtn_chq']??0);
        $sr_rcvd_rtn_chgs  = floatval($cm['sr_rcvd_rtn_chgs']??0);
        $sr_rcvd_sent_back = floatval($cm['sr_rcvd_sent_back']??0);
        $sr_total          = floatval($cm['sr_total']??0);
        $total_coll        = floatval($cm['total_coll']??0);

        $banked_cc = floatval($dm['banked_cc']??0);
        $banked_sr = floatval($dm['banked_sr']??0);
        $handed_cc = floatval($dm['handed_cc']??0);
        $handed_sr = floatval($dm['handed_sr']??0);
        $banked    = $banked_cc + $banked_sr;
        $handed    = $handed_cc + $handed_sr;

        $variance_cc = $cc_total - ($banked_cc + $handed_cc);
        $variance_sr = $sr_total - ($banked_sr + $handed_sr);
        $short_cc    = $variance_cc > 0 ? $variance_cc : 0;
        $short_sr    = $variance_sr > 0 ? $variance_sr : 0;

        $bank_diff = $total_coll - ($banked + $handed);
        $cash_short_excess = $bank_diff;

        $t_out = floatval($transfer_out_map[$k]??0);
        $t_in  = floatval($transfer_in_map[$k]??0);
        $shortage_adj = $t_out - $t_in;

       $new_short_excess = $bank_diff - $t_out + $t_in;


        $t_rows = $transfer_rows_map[$k]??[];

        $pa         = $alloc_map[$k]??[];
        $p_charge   = floatval($pa['p_charge']??0);
        $p_absorb   = floatval($pa['p_absorb']??0);
        $p_variance = isset($pa['p_variance']) ? floatval($pa['p_variance']) : null;
        $p_employees= ($pa['employees']??[]);
        $is_paid    = (bool)($pa['is_paid']??false);

        $rows[] = compact(
            'sr_code','del_date','sinv','sinv_bf','sinv_bp','sinv_adj',
            'cash_paid','cheque_paid','credit','pay_diff',
            'cc_daily_sale','cc_rcvd_credit','cc_rcvd_rtn_chq','cc_rcvd_rtn_chgs','cc_rcvd_sent_back','cc_total',
            'sr_daily_sale','sr_rcvd_credit','sr_rcvd_rtn_chq','sr_rcvd_rtn_chgs','sr_rcvd_sent_back','sr_total',
            'total_coll','banked','handed',
            'banked_cc','banked_sr','handed_cc','handed_sr',
            'variance_cc','variance_sr','short_cc','short_sr',
            'bank_diff','cash_short_excess',
            't_out','t_in','shortage_adj','new_short_excess','t_rows',
            'p_charge','p_absorb','p_variance','p_employees','is_paid'
        );

        $totals['sinv']             += $sinv;
        $totals['sinv_bf']          += $sinv_bf;
        $totals['sinv_bp']          += $sinv_bp;
        $totals['sinv_adj']         += $sinv_adj;
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
        $totals['sr_daily_sale']    += $sr_daily_sale;
        $totals['sr_rcvd_credit']   += $sr_rcvd_credit;
        $totals['sr_rcvd_rtn_chq']  += $sr_rcvd_rtn_chq;
        $totals['sr_rcvd_rtn_chgs'] += $sr_rcvd_rtn_chgs;
        $totals['sr_rcvd_sent_back']+= $sr_rcvd_sent_back;
        $totals['sr_total']         += $sr_total;
        $totals['total_coll']       += $total_coll;
        $totals['banked']           += $banked;
        $totals['handed']           += $handed;
        $totals['banked_cc']        += $banked_cc;
        $totals['banked_sr']        += $banked_sr;
        $totals['handed_cc']        += $handed_cc;
        $totals['handed_sr']        += $handed_sr;
        $totals['variance_cc']      += $variance_cc;
        $totals['variance_sr']      += $variance_sr;
        $totals['short_cc']         += $short_cc;
        $totals['short_sr']         += $short_sr;
        $totals['bank_diff']        += $bank_diff;
        $totals['cash_short_excess']+= $cash_short_excess;
        $totals['t_out']            += $t_out;
        $totals['t_in']             += $t_in;
        $totals['shortage_adj']     += $shortage_adj;
        $totals['new_short_excess'] += $new_short_excess;
        $totals['p_charge']         += $p_charge;
        $totals['p_absorb']         += $p_absorb;
    }
}

/* ═══════════ HELPERS for separate TBD columns ═══════════ */
function cc_sinv_orig($v){
    $n=floatval($v);
    return $n==0?'<span class="dash">—</span>':'<span class="num" style="font-weight:700;color:#1e40af;">'.number_format($n,2).'</span>';
}
function cc_sinv_bf($v){
    $n=floatval($v);
    return $n==0?'<span class="dash">—</span>':'<span class="tbd-bf">−'.number_format($n,2).'</span>';
}
function cc_sinv_bp($v){
    $n=floatval($v);
    return $n==0?'<span class="dash">—</span>':'<span class="tbd-bp">+'.number_format($n,2).'</span>';
}
function cc_sinv_net($v){
    $n=floatval($v);
    return $n==0?'<span class="dash">—</span>':'<span class="num" style="font-weight:800;color:#0f172a;">'.number_format($n,2).'</span>';
}

function cc_v($v){$n=floatval($v);return $n==0?'<span class="dash">—</span>':'<span class="num">'.number_format($n,2).'</span>';}
function cc_se($v){
    $n=floatval($v);
    if(abs($n)<0.005) return '<span class="d-ok">0.00 ✓</span>';
    if($n<0) return '<span class="d-exc">▲ '.number_format(abs($n),2).'</span>';
    return '<span class="d-sht">▼ '.number_format($n,2).'</span>';
}
function cc_d($v){
    $n=floatval($v);
    if(abs($n)<0.005) return '<span class="d-ok">0.00 ✓</span>';
    if($n<0) return '<span class="d-exc">▲ '.number_format(abs($n),2).'</span>';
    return '<span class="d-sht">▼ '.number_format($n,2).'</span>';
}
function cc_t($v){$n=floatval($v);return $n==0?'—':number_format($n,2);}
function cc_pvar($v){
    if($v===null) return '<span style="color:#d1d5db;">—</span>';
    $v=floatval($v);
    if(abs($v)<0.005) return '<span class="pv-ok">0.00 ✓</span>';
    if($v>0) return '<span class="pv-sht-pay">▼ '.number_format($v,2).'</span>';
    return '<span class="pv-exc-pay">▲ '.number_format(abs($v),2).'</span>';
}
function cc_var_split($v){
    $n=floatval($v);
    if(abs($n)<0.005) return '<span class="d-ok">0.00 ✓</span>';
    if($n<0) return '<span class="d-exc">▲ '.number_format(abs($n),2).'</span>';
    return '<span class="d-sht">▼ '.number_format($n,2).'</span>';
}
function cc_short_split($v){
    $n=floatval($v);
    if($n<=0) return '<span class="dash">—</span>';
    return '<span class="sv">'.number_format($n,2).'</span>';
}
function cc_pay_link($v,$sr,$date,$method){
    $n=floatval($v);
    if($n==0)return '<span class="dash">—</span>';
    $url='payment_details.php?sr_code='.urlencode($sr).'&date='.urlencode($date).'&method='.urlencode($method);
    $cls=$method==='cheque'?'pay-link cheque':'pay-link cash';
    return '<a href="'.$url.'" target="_blank" class="'.$cls.'"><span class="num">'.number_format($n,2).'</span> <i class="fa-solid fa-arrow-up-right-from-square pay-link-ico"></i></a>';
}
function cc_clink($v,$sr,$date,$coll,$src=''){
    $n=floatval($v);
    if($n==0)return '<span class="dash">—</span>';
    $p=['sr_code'=>$sr,'date'=>$date,'method'=>'cash','collected_by'=>$coll];
    if($src!=='')$p['source']=$src;
    $url='payment_details.php?'.http_build_query($p);
    $cls=$coll==='cc'?'clink cc':'clink sr';
    return '<a href="'.$url.'" target="_blank" class="'.$cls.'"><span class="num">'.number_format($n,2).'</span><i class="fa-solid fa-arrow-up-right-from-square clink-ico"></i></a>';
}
function cc_dep_link($v,$sr,$date,$type,$collected_by=''){
    $n=floatval($v);
    if($n==0)return '<span class="dash">—</span>';
    $params=['sr_code'=>$sr,'date'=>$date,'type'=>$type];
    if($collected_by!=='')$params['collected_by']=$collected_by;
    $url='cc_deposit_history.php?'.http_build_query($params);
    $cls=$type==='bank'?'dep-link bank':'dep-link bo';
    return '<a href="'.$url.'" target="_blank" class="'.$cls.'"><span class="num">'.number_format($n,2).'</span><i class="fa-solid fa-clock-rotate-left dep-link-ico"></i></a>';
}
function cc_adj($v){
    $n=floatval($v);
    if(abs($n)<0.005) return '<span class="dash">—</span>';
    if($n>0) return '<span class="adj-in">+'.number_format($n,2).'</span>';
    return '<span class="adj-out">'.number_format($n,2).'</span>';
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
  --g3:#1a6640;--g3s:#155535;--g4:#2d5a1a;--g4s:#254e14;--g5:#6b2040;--g5s:#5a1a35;
  --total-bg:#0f172a;
  --tr-col:#0c4a6e;
  --adj-col:#064e3b;
  --new-col:#312e81;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:12px;}
.pg{padding:12px 12px 40px;}
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:10px;}
.pg-h1{font-size:19px;font-weight:800;letter-spacing:-.02em;}
.pg-h1 em{color:var(--g3);font-style:normal;}
.pg-sub{font-size:10px;color:var(--txs);margin-top:2px;}
.dpill{display:inline-flex;align-items:center;gap:5px;background:#f0fdf4;border:1px solid #86efac;border-radius:20px;padding:4px 11px;font-size:11px;font-weight:700;color:#166534;font-family:var(--mn);}
.fbar{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:8px 12px;margin-bottom:10px;display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;box-shadow:var(--sh);}
.fg{display:flex;flex-direction:column;gap:2px;}
.fg label{font-size:10px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.07em;}
.fg input,.fg select{padding:6px 9px;border:1.5px solid var(--bdr);border-radius:6px;font-size:12px;font-family:var(--fn);color:var(--tx);background:#fff;}
.fg input:focus,.fg select:focus{outline:none;border-color:var(--g3);}
.btn-go{display:inline-flex;align-items:center;gap:5px;padding:7px 16px;background:var(--g0);color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;font-family:var(--fn);cursor:pointer;}
.btn-go:hover{background:#3a4560;}
.btn-rst{display:inline-flex;align-items:center;gap:4px;padding:7px 11px;background:#f0f0f0;color:var(--txm);border:1px solid var(--bdr);border-radius:6px;font-size:12px;font-weight:600;font-family:var(--fn);cursor:pointer;text-decoration:none;}
.btn-excel{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;background:linear-gradient(135deg,#166534,#15803d);color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;font-family:var(--fn);cursor:pointer;box-shadow:0 2px 6px rgba(22,101,52,.3);transition:all .2s;}
.btn-excel:hover{background:linear-gradient(135deg,#14532d,#166534);transform:translateY(-1px);}
.sc-row{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:10px;}
.sc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:8px 10px;box-shadow:var(--sh);border-left:3px solid #e5e7eb;}
.sc.blue{border-left-color:#3b82f6;}.sc.green{border-left-color:#22c55e;}.sc.teal{border-left-color:#14b8a6;}.sc.red{border-left-color:#ef4444;}
.sc-lbl{font-size:9px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.sc-val{font-size:13px;font-weight:800;}
.tc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);overflow:hidden;box-shadow:var(--sh);}
.tc-bar{display:flex;justify-content:space-between;align-items:center;padding:7px 11px;border-bottom:1px solid var(--bdrs);flex-wrap:wrap;gap:6px;}
.tc-ttl{font-size:13px;font-weight:700;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.pill{padding:1px 7px;border-radius:12px;font-size:10px;font-weight:700;}
.p-green{background:#dcfce7;color:#166534;}.p-blue{background:#dbeafe;color:#1e40af;}.p-slate{background:#f1f5f9;color:#475569;}
.top-scroll-wrap{overflow-x:auto;overflow-y:hidden;height:10px;margin-bottom:1px;border-radius:4px;}
.top-scroll-inner{height:1px;}
.tscroll{overflow-x:auto;position:relative;}

table.cct{width:100%;border-collapse:collapse;font-size:11px;}
.cct .G th{padding:4px 4px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#fff;text-align:center;white-space:nowrap;border-right:2px solid rgba(255,255,255,.18);}
.cct .G th.tl{text-align:left;}.cct .G th:last-child{border-right:none;}
.cct .S th{padding:3px 4px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:rgba(255,255,255,.88);text-align:center;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);border-bottom:2px solid var(--bdr);}
.cct .S th.tl{text-align:left;}.cct .S th:last-child{border-right:none;}

.G .h0{background:var(--g0);}.G .h1{background:var(--g1);}.G .h2{background:var(--g2);}.G .h6{background:#5b21b6;}
.G .hcc{background:#1a6640;}.G .hsr{background:#0f766e;}.G .htc{background:#374151;}
.G .hdep{background:#1e4d8c;}.G .hvar{background:#7c2d12;}
.G .htr{background:var(--tr-col);}
.G .hadj{background:var(--adj-col);}
.G .hnew{background:var(--new-col);}
.S .s0{background:var(--g0s);}.S .s1{background:var(--g1s);}.S .s2{background:var(--g2s);}.S .s6{background:#4c1d95;}
.S .scc{background:#155535;}.S .ssr{background:#0d6462;}.S .stc{background:#2d3748;}
.S .sdep_cc{background:#14532d;}.S .sdep_sr{background:#1a5218;}
.S .sbo_cc{background:#5b21b6;}.S .sbo_sr{background:#3b1578;}
.S .svar_cc{background:#7c2d12;}.S .svar_sr{background:#6b2508;}
.S .ssht_cc{background:#7f1d1d;}.S .ssht_sr{background:#6f1a1a;}
.S .stvar{background:#2d3748;}.S .stsht{background:#1c1917;}
.S .str{background:#075985;}
.S .sadj{background:#065f46;}
.S .snew{background:#272069;}
/* TBD sub-header backgrounds */
.S .stbd_bf{background:#7f1d1d;}
.S .stbd_bp{background:#14532d;}
.S .snet_sinv{background:#1e3a5f;}

.cct .TH td{background:#e0edff;color:#1e3a8a;font-weight:800;font-size:10.5px;padding:4px 4px;text-align:right;border-bottom:2px solid #93c5fd;white-space:nowrap;font-family:var(--mn);}
.cct .TH td.tl{text-align:left;font-family:var(--fn);}
.cct tbody tr td{background:#fff;}
.cct tbody tr.stripe td{background:#fafbfc;}
.cct tbody tr.row-hover td{background:#eff6ff!important;}
.cct tbody td{padding:3px 4px;text-align:right;white-space:nowrap;}
.cct tbody td.tl{text-align:left;}.cct tbody td.tc{text-align:center;}
.cct tbody td.rc{font-weight:700;font-size:11px;}
.cct tbody td.dt{font-family:var(--mn);font-size:10px;color:var(--txm);}
.cct tbody td.fb{font-weight:700;color:#1e40af;}.cct tbody td.tv{font-weight:700;color:#166534;}

/* TBD column backgrounds */
.tbd-bf{color:#dc2626;font-weight:700;font-family:var(--mn);font-size:10.5px;}
.tbd-bp{color:#16a34a;font-weight:700;font-family:var(--mn);font-size:10.5px;}
.col-tbd-bf{background:rgba(220,38,38,.05);}
.cct tbody tr.stripe td.col-tbd-bf{background:rgba(220,38,38,.09);}
.cct tbody tr.row-hover td.col-tbd-bf{background:#fff1f2!important;}
.col-tbd-bp{background:rgba(22,163,74,.05);}
.cct tbody tr.stripe td.col-tbd-bp{background:rgba(22,163,74,.09);}
.cct tbody tr.row-hover td.col-tbd-bp{background:#f0fdf4!important;}
.col-net-sinv{background:rgba(30,64,175,.05);}
.cct tbody tr.stripe td.col-net-sinv{background:rgba(30,64,175,.09);}
.cct tbody tr.row-hover td.col-net-sinv{background:#eff6ff!important;}

.cct tbody td.col-c{background:rgba(26,102,64,.06);}
.cct tbody tr.stripe td.col-c{background:rgba(26,102,64,.1);}
.cct tbody tr.row-hover td.col-c{background:#f0fdf4!important;}
.cct tbody td.col-s{background:rgba(15,118,110,.06);}
.cct tbody tr.stripe td.col-s{background:rgba(15,118,110,.1);}
.cct tbody tr.row-hover td.col-s{background:#f0fdfa!important;}
.cct tbody td.col-dep-cc{background:rgba(22,101,52,.07);}
.cct tbody tr.stripe td.col-dep-cc{background:rgba(22,101,52,.12);}
.cct tbody tr.row-hover td.col-dep-cc{background:#dcfce7!important;}
.cct tbody td.col-dep-sr{background:rgba(26,92,26,.06);}
.cct tbody tr.stripe td.col-dep-sr{background:rgba(26,92,26,.10);}
.cct tbody tr.row-hover td.col-dep-sr{background:#f0fdf0!important;}
.cct tbody td.col-bo-cc{background:rgba(109,40,217,.07);}
.cct tbody tr.stripe td.col-bo-cc{background:rgba(109,40,217,.12);}
.cct tbody tr.row-hover td.col-bo-cc{background:#f5f3ff!important;}
.cct tbody td.col-bo-sr{background:rgba(76,29,149,.06);}
.cct tbody tr.stripe td.col-bo-sr{background:rgba(76,29,149,.10);}
.cct tbody tr.row-hover td.col-bo-sr{background:#ede9fe!important;}
.cct tbody td.col-var-cc{background:rgba(154,52,18,.06);}
.cct tbody tr.stripe td.col-var-cc{background:rgba(154,52,18,.10);}
.cct tbody tr.row-hover td.col-var-cc{background:#fff7ed!important;}
.cct tbody td.col-var-sr{background:rgba(124,45,18,.06);}
.cct tbody tr.stripe td.col-var-sr{background:rgba(124,45,18,.10);}
.cct tbody tr.row-hover td.col-var-sr{background:#fef3c7!important;}
.cct tbody td.col-sht-cc{background:rgba(153,27,27,.07);}
.cct tbody tr.stripe td.col-sht-cc{background:rgba(153,27,27,.12);}
.cct tbody tr.row-hover td.col-sht-cc{background:#fee2e2!important;}
.cct tbody td.col-sht-sr{background:rgba(127,29,29,.06);}
.cct tbody tr.stripe td.col-sht-sr{background:rgba(127,29,29,.10);}
.cct tbody tr.row-hover td.col-sht-sr{background:#fecaca!important;}
.cct tbody td.col-tvar{background:rgba(55,65,81,.05);}
.cct tbody tr.stripe td.col-tvar{background:rgba(55,65,81,.09);}
.cct tbody tr.row-hover td.col-tvar{background:#f1f5f9!important;}
.cct tbody td.col-tsht{background:rgba(28,25,23,.07);}
.cct tbody tr.stripe td.col-tsht{background:rgba(28,25,23,.12);}
.cct tbody tr.row-hover td.col-tsht{background:#e7e5e4!important;}
.cct tbody td.col-tr{background:rgba(12,74,110,.07);}
.cct tbody tr.stripe td.col-tr{background:rgba(12,74,110,.12);}
.cct tbody tr.row-hover td.col-tr{background:#e0f2fe!important;}
.cct tbody td.col-adj{background:rgba(6,95,70,.07);}
.cct tbody tr.stripe td.col-adj{background:rgba(6,95,70,.12);}
.cct tbody tr.row-hover td.col-adj{background:#d1fae5!important;}
.cct tbody td.col-new{background:rgba(49,46,129,.07);}
.cct tbody tr.stripe td.col-new{background:rgba(49,46,129,.12);}
.cct tbody tr.row-hover td.col-new{background:#ede9fe!important;}
.cct tfoot td{padding:4px 4px;font-weight:800;font-size:11px;background:var(--total-bg);color:#e2e8f0;border-top:2px solid #334155;text-align:right;white-space:nowrap;font-family:var(--mn);}
.cct tfoot td.tl{text-align:left;color:#94a3b8;font-family:var(--fn);}

.num{font-family:var(--mn);font-size:10.5px;}.dash{color:#d1d5db;}
.d-ok{color:#16a34a;font-weight:700;font-family:var(--mn);font-size:10px;}
.d-exc{color:#16a34a;font-weight:700;font-family:var(--mn);font-size:10px;}
.d-sht{color:#dc2626;font-weight:700;font-family:var(--mn);font-size:10px;}
.sv{color:#dc2626;font-weight:700;font-family:var(--mn);font-size:10.5px;}
.adj-in{color:#059669;font-weight:700;font-family:var(--mn);font-size:10.5px;}
.adj-out{color:#d97706;font-weight:700;font-family:var(--mn);font-size:10.5px;}
.col-p{background:#fdf4ff!important;}.val-charge{color:#dc2626;font-weight:600;}.val-absorb{color:#0369a1;font-weight:600;}
.pv-ok{color:#16a34a;font-weight:700;font-family:var(--mn);}
.pv-exc-pay{color:#d97706;font-weight:700;font-family:var(--mn);}
.pv-sht-pay{color:#dc2626;font-weight:700;font-family:var(--mn);}
.pay-link{display:inline-flex;align-items:center;gap:3px;text-decoration:none;padding:1px 4px;border-radius:4px;transition:all .15s;}
.pay-link .pay-link-ico{font-size:7px;opacity:0;transition:opacity .15s;}.pay-link:hover .pay-link-ico{opacity:1;}
.pay-link .num{border-bottom:1px dashed currentColor;}
.pay-link.cash{color:#166534;}.pay-link.cash:hover{background:#f0fdf4;box-shadow:0 0 0 1px #86efac;}
.pay-link.cheque{color:#5b21b6;}.pay-link.cheque:hover{background:#f5f3ff;box-shadow:0 0 0 1px #c4b5fd;}
.clink{display:inline-flex;align-items:center;gap:2px;text-decoration:none;padding:1px 3px;border-radius:4px;transition:all .15s;font-weight:600;}
.clink .clink-ico{font-size:7px;opacity:0;transition:opacity .15s;}.clink:hover .clink-ico{opacity:1;}
.clink .num{border-bottom:1px dashed currentColor;}
.clink.cc{color:#166534;}.clink.cc:hover{background:#dcfce7;box-shadow:0 0 0 1px #86efac;}
.clink.sr{color:#0f766e;}.clink.sr:hover{background:#ccfbf1;box-shadow:0 0 0 1px #5eead4;}
.dep-link{display:inline-flex;align-items:center;gap:3px;text-decoration:none;padding:1px 4px;border-radius:4px;transition:all .15s;font-weight:600;}
.dep-link .dep-link-ico{font-size:7px;opacity:0;transition:opacity .15s;}.dep-link:hover .dep-link-ico{opacity:1;}
.dep-link .num{border-bottom:1px dashed currentColor;}
.dep-link.bank{color:#166534;}.dep-link.bank:hover{background:#dcfce7;box-shadow:0 0 0 1px #86efac;}
.dep-link.bo{color:#5b21b6;}.dep-link.bo:hover{background:#ede9fe;box-shadow:0 0 0 1px #c4b5fd;}
.btn-transfer{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border:1px solid #bae6fd;border-radius:5px;background:#e0f2fe;color:#0369a1;font-size:10px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s;white-space:nowrap;}
.btn-transfer:hover{background:#0369a1;color:#fff;}
.btn-transfer.has-tr{background:#f0f9ff;color:#0c4a6e;border-color:#7dd3fc;}
.btn-pay-alloc{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border:1px solid #ddd6fe;border-radius:5px;background:#f5f3ff;color:#7c3aed;font-size:10px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s;white-space:nowrap;}
.btn-pay-alloc:hover{background:#7c3aed;color:#fff;}.btn-pay-alloc.paid{background:#f0fdf4;color:#16a34a;border-color:#86efac;}
.cct td.stk,.cct th.stk{position:sticky;left:0;z-index:2;box-shadow:3px 0 8px rgba(0,0,0,.10);}
.cct thead th.stk{z-index:4;}
.cct .G th.stk{background:var(--g0);}.cct .S th.stk{background:var(--g0s);}
.cct .TH td.stk{background:#e0edff;}.cct tfoot td.stk{background:var(--total-bg);}
.cct tbody tr td.stk{background:#fff;}.cct tbody tr.stripe td.stk{background:#fafbfc;}
.cct tbody tr.row-hover td.stk{background:#eff6ff!important;}

/* Hidden column utility */
.col-hidden{display:none!important;}

.print-header{display:none;}
@media print{
  body{background:#fff;font-size:10px;}
  .no-print{display:none!important;}
  .pg{padding:4px 4px 10px;}
  .print-header{display:block;text-align:center;padding:8px 0 6px;border-bottom:2px solid #1a6640;margin-bottom:8px;}
  .print-header h2{font-size:15px;font-weight:800;color:#1a1f2e;margin-bottom:2px;}
  .print-header p{font-size:10px;color:#58626e;}
  .tc{border:none;box-shadow:none;}.tscroll{overflow:visible;}
  table.cct{font-size:9px;}
  .cct .G th{padding:3px 3px;font-size:8px;}.cct .S th{padding:2px 3px;font-size:8px;}
  .cct .TH td,.cct tbody td,.cct tfoot td{padding:2px 3px;}
  .cct tfoot td{font-size:9px;}
  th,tfoot td,.cct .G th,.cct .S th{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  a.pay-link,a.clink,a.dep-link{text-decoration:none;color:inherit;}
  a.pay-link .pay-link-ico,a.clink .clink-ico,a.dep-link .dep-link-ico{display:none;}
  .btn-pay-alloc,.btn-transfer{display:none;}
  .cct td.stk,.cct th.stk{position:static;box-shadow:none;}
  .top-scroll-wrap{display:none;}
}

/* ═══════ PAY MODAL ═══════ */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1050;display:none;align-items:center;justify-content:center;padding:12px;}
.modal-overlay.open{display:flex;}
.pay-modal-box{background:#fff;border-radius:14px;width:100%;max-width:600px;max-height:96vh;box-shadow:0 32px 80px rgba(0,0,0,.28);display:flex;flex-direction:column;overflow:hidden;}
.pmo-header{padding:16px 20px 12px;border-bottom:1px solid #eff0f1;display:flex;justify-content:space-between;align-items:flex-start;flex-shrink:0;background:#fff;}
.pmo-header-left{display:flex;align-items:center;gap:10px;}
.pmo-icon{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#7c3aed,#6d28d9);display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;flex-shrink:0;}
.pmo-title{font-size:15px;font-weight:700;color:#111827;line-height:1.2;}.pmo-sub{font-size:11px;color:#9ca3af;margin-top:2px;}
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

/* ═══════ TRANSFER MODAL ═══════ */
.tr-modal-box{background:#fff;border-radius:14px;width:100%;max-width:580px;max-height:96vh;box-shadow:0 32px 80px rgba(0,0,0,.28);display:flex;flex-direction:column;overflow:hidden;}
.tr-modal-icon{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#0369a1,#0284c7);display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;flex-shrink:0;}
.tr-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;}
.tr-form-field{display:flex;flex-direction:column;gap:4px;}
.tr-form-field label{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;}
.tr-form-field input,.tr-form-field select,.tr-form-field .select2-container .select2-selection--single{
  border:1.5px solid #e5e7eb;border-radius:7px;padding:7px 10px;font-size:12px;font-family:var(--fn);color:#1f2937;outline:none;width:100%;}
.tr-form-field input:focus,.tr-form-field select:focus{border-color:#0369a1;}
.tr-form-field .select2-container{width:100%!important;}
.tr-form-field .select2-container .select2-selection--single{height:36px;display:flex;align-items:center;}
.tr-form-field .select2-container .select2-selection--single .select2-selection__rendered{line-height:36px;padding-left:10px;font-size:12px;}
.tr-form-field .select2-container .select2-selection--single .select2-selection__arrow{height:34px;}
.tr-form-field .select2-container--open .select2-selection--single{border-color:#0369a1!important;box-shadow:0 0 0 2px rgba(3,105,161,.15);}
.tr-note-area{border:1.5px solid #e5e7eb;border-radius:7px;padding:8px 10px;font-size:12px;font-family:var(--fn);resize:vertical;min-height:60px;width:100%;outline:none;}
.tr-note-area:focus{border-color:#0369a1;}
.tr-info-banner{background:#f0f9ff;border:1px solid #bae6fd;border-radius:9px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;gap:10px;}
.tr-info-banner i{color:#0369a1;font-size:14px;flex-shrink:0;}
.tr-info-banner span{font-size:11px;color:#0c4a6e;font-weight:500;}
.tr-info-banner strong{color:#0369a1;}
.tr-hist-list{list-style:none;padding:0;margin:0;}
.tr-hist-item{display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:6px;background:#fafafa;}
.tr-hist-item:last-child{margin-bottom:0;}
.tr-hist-dir{width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;}
.tr-hist-dir.out{background:#fee2e2;color:#991b1b;}
.tr-hist-dir.in{background:#dcfce7;color:#166534;}
.tr-hist-info{flex:1;min-width:0;}
.tr-hist-sr{font-size:11px;font-weight:700;color:#1f2937;}
.tr-hist-date{font-size:10px;color:#9ca3af;font-family:var(--mn);}
.tr-hist-amt{font-size:13px;font-weight:800;font-family:var(--mn);white-space:nowrap;}
.tr-hist-amt.out{color:#dc2626;}
.tr-hist-amt.in{color:#16a34a;}
.tr-hist-actions{display:flex;gap:4px;flex-shrink:0;}
.tr-hist-edit-btn,.tr-hist-del-btn{border:none;border-radius:5px;padding:4px 8px;font-size:10px;font-weight:600;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:3px;transition:all .15s;}
.tr-hist-edit-btn{background:#dbeafe;color:#1e40af;}.tr-hist-edit-btn:hover{background:#1e40af;color:#fff;}
.tr-hist-del-btn{background:#fee2e2;color:#991b1b;}.tr-hist-del-btn:hover{background:#991b1b;color:#fff;}
.tr-hist-note{font-size:10px;color:#6b7280;margin-top:2px;font-style:italic;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:220px;}
.tr-hist-emp{display:inline-flex;align-items:center;gap:3px;font-size:10px;color:#7c3aed;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:4px;padding:1px 5px;margin-top:2px;}
.pmo-btn-save-tr{background:linear-gradient(135deg,#0369a1,#0284c7);color:#fff;}
.pmo-btn-save-tr:hover{background:linear-gradient(135deg,#0284c7,#0ea5e9);}
#transferModal .select2-dropdown{z-index:2000;}

#ccToast{position:fixed;bottom:20px;right:20px;padding:9px 16px;border-radius:8px;font-size:12px;font-weight:600;z-index:9999;display:none;opacity:0;transition:opacity .3s;}
.toast-ok{background:#dcfce7;color:#166534;border:1px solid #86efac;}.toast-err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.empty{text-align:center;padding:50px 20px;color:var(--txs);}.empty i{font-size:38px;display:block;margin-bottom:10px;opacity:.25;}
@media(max-width:900px){.sc-row{grid-template-columns:1fr 1fr;}}
@media(max-width:600px){
  .pmo-info{grid-template-columns:1fr 1fr;}.pmo-totals{grid-template-columns:1fr;gap:5px;}
  .absorb-row{flex-direction:column;align-items:stretch;}.absorb-inp{width:100%;}
  .emp-row .emp-amt{width:100%;}.pmo-ftr{flex-direction:column-reverse;}
  .pmo-btn{justify-content:center;}.pay-modal-box,.tr-modal-box{border-radius:10px;}
  .pmo-header,.pmo-body,.pmo-ftr,.pmo-info{padding-left:12px;padding-right:12px;}
  .tr-form-grid{grid-template-columns:1fr;}
}
@media(max-width:400px){.pmo-info{grid-template-columns:1fr;}}

.btn-save-rep{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;font-family:var(--fn);cursor:pointer;box-shadow:0 2px 6px rgba(124,58,237,.3);transition:all .2s;}
.btn-save-rep:hover{background:linear-gradient(135deg,#6d28d9,#5b21b6);transform:translateY(-1px);}
.btn-saved-list{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;background:#f5f3ff;color:#5b21b6;border:1px solid #ddd6fe;border-radius:6px;font-size:12px;font-weight:700;font-family:var(--fn);cursor:pointer;text-decoration:none;transition:all .2s;}
.btn-saved-list:hover{background:#ede9fe;}
.srm-box{background:#fff;border-radius:14px;width:100%;max-width:460px;box-shadow:0 32px 80px rgba(0,0,0,.28);display:flex;flex-direction:column;overflow:hidden;}
.srm-header{padding:16px 20px 12px;border-bottom:1px solid #eff0f1;display:flex;justify-content:space-between;align-items:center;}
.srm-icon{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#7c3aed,#6d28d9);display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;flex-shrink:0;}
.srm-body{padding:16px 20px;}
.srm-field{display:flex;flex-direction:column;gap:4px;margin-bottom:12px;}
.srm-field label{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;}
.srm-field input{border:1.5px solid #e5e7eb;border-radius:7px;padding:8px 11px;font-size:13px;font-family:var(--fn);outline:none;color:#1f2937;}
.srm-field input:focus{border-color:#7c3aed;}
.srm-info{background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;padding:10px 14px;font-size:11px;color:#5b21b6;display:flex;gap:8px;align-items:flex-start;margin-bottom:4px;}
.srm-ftr{padding:12px 20px;border-top:1px solid #eff0f1;display:flex;justify-content:flex-end;gap:8px;}
.pmo-btn-savereport{background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;}
.pmo-btn-savereport:hover{background:linear-gradient(135deg,#6d28d9,#5b21b6);}
.pmo-btn-savereport:disabled{opacity:.55;cursor:not-allowed;}
</style>

<div class="pg">

<div class="print-header">
    <h2>Daily Cash Collection Report</h2>
    <p><?php echo date('d M Y',strtotime($date_from)).' — '.date('d M Y',strtotime($date_to));
       echo $f_sr ? ' &nbsp;·&nbsp; Rep: '.htmlspecialchars($f_sr) : ' &nbsp;·&nbsp; All Reps';
       echo ' &nbsp;·&nbsp; Generated: '.date('d M Y H:i');?></p>
</div>

<div class="topbar no-print">
    <div>
        <div class="pg-h1">Daily Cash <em>Collection</em></div>
        <div class="pg-sub">Sec. Invoice · Invoice Payments · Day Cash Collection (CC &amp; SR) · Deposits · Variance &amp; Shortage</div>
    </div>
    <div style="display:flex;gap:7px;align-items:center;">
        <div class="dpill"><i class="fa-solid fa-calendar-days"></i> <?php echo date('d M Y',strtotime($date_from)).' — '.date('d M Y',strtotime($date_to));?></div>
<?php if($submitted&&!empty($rows)):?>
<button onclick="exportToExcel()" class="btn-excel"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
<button onclick="window.print()" class="btn-rst"><i class="fa-solid fa-print"></i> Print</button>
<a href="cash_collection_print.php?search=1&date_from=<?php echo urlencode($date_from);?>&date_to=<?php echo urlencode($date_to);?>&sr_code=<?php echo urlencode($f_sr);?>" target="_blank" class="btn-print-rep"><i class="fa-solid fa-file-lines"></i> Print Report</a>
<button onclick="openSaveReportModal()" class="btn-save-rep"><i class="fa-solid fa-floppy-disk"></i> Save Report</button>
<a href="view_cash_reports.php" target="_blank" class="btn-saved-list"><i class="fa-solid fa-folder-open"></i> Saved Reports</a>
<?php endif;?>    </div>
</div>

<div class="fbar no-print">
    <form method="GET" id="ccf" style="display:contents;">
        <input type="hidden" name="search" value="1">
        <div class="fg"><label><i class="fa-solid fa-calendar-day"></i> Date From</label><input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from);?>"></div>
        <div class="fg"><label><i class="fa-solid fa-calendar-day"></i> Date To</label><input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to);?>"></div>
        <div class="fg" style="min-width:150px;"><label><i class="fa-solid fa-id-badge"></i> Sales Rep</label>
            <select name="sr_code"><option value="">— All Reps —</option>
                <?php foreach($all_sr as $sr):?><option value="<?php echo htmlspecialchars($sr);?>" <?php echo $f_sr===$sr?'selected':'';?>><?php echo htmlspecialchars($sr);?></option><?php endforeach;?>
            </select></div>
        <button type="submit" class="btn-go" id="gBtn"><i class="fa-solid fa-magnifying-glass"></i> Generate</button>
        <a href="cash_collection.php" class="btn-rst" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
    </form>
</div>

<?php if(!$submitted):?>
<div class="tc"><div class="empty"><i class="fa-solid fa-money-bill-wave"></i>
    <p style="font-size:14px;font-weight:600;margin-bottom:5px;">Daily Cash Collection Report</p>
    <p>Choose a date range and click <strong>Generate</strong>.</p>
</div></div>
<?php elseif(empty($rows)):?>
<div class="tc"><div class="empty"><i class="fa-solid fa-inbox"></i>
    <p style="font-size:14px;font-weight:600;margin-bottom:5px;">No records found</p>
    <p><?php echo date('d M Y',strtotime($date_from)).' – '.date('d M Y',strtotime($date_to)); echo $f_sr?' &nbsp;·&nbsp; Rep: <strong>'.htmlspecialchars($f_sr).'</strong>':'';?></p>
</div></div>
<?php else:?>
<div class="sc-row no-print">
    <div class="sc blue"><div class="sc-lbl"><i class="fa-solid fa-file-invoice-dollar"></i> Sec. Invoice Value</div><div class="sc-val" style="color:#1e40af;">Rs. <?php echo number_format($totals['sinv_adj'],2);?></div></div>
    <div class="sc green"><div class="sc-lbl"><i class="fa-solid fa-user-tie"></i> CC Collection</div><div class="sc-val" style="color:#166534;">Rs. <?php echo number_format($totals['cc_total'],2);?></div></div>
    <div class="sc teal"><div class="sc-lbl"><i class="fa-solid fa-person-walking"></i> SR Collection</div><div class="sc-val" style="color:#0f766e;">Rs. <?php echo number_format($totals['sr_total'],2);?></div></div>
    <div class="sc green"><div class="sc-lbl"><i class="fa-solid fa-building-columns"></i> Total Deposited</div><div class="sc-val" style="color:#166534;">Rs. <?php echo number_format($totals['banked']+$totals['handed'],2);?></div></div>
    <?php $tot_new_se=$totals['new_short_excess']; $tot_sh=$tot_new_se>0?$tot_new_se:0;?>
    <div class="sc <?php echo $tot_sh>0?'red':'green';?>" id="sc-netse"><div class="sc-lbl"><i class="fa-solid fa-triangle-exclamation"></i> Net Short/Excess</div><div class="sc-val" id="sc-netse-val" style="color:<?php echo $tot_sh>0?'#991b1b':'#166534';?>;">Rs. <?php echo number_format(abs($tot_new_se),2);?> <?php echo $tot_new_se<0?'<small style="font-size:10px;font-weight:600;">(Excess)</small>':($tot_new_se==0?'<small>(OK)</small>':'');?></div></div>
</div>

<div class="tc">
    <div class="tc-bar no-print">
        <div class="tc-ttl"><i class="fa-solid fa-table-cells-large"></i> Cash Collection Detail
            <span class="pill p-green"><?php echo count($rows);?> records</span>
            <span class="pill p-blue"><?php echo date('d M Y',strtotime($date_from)).' – '.date('d M Y',strtotime($date_to));?></span>
            <?php if($f_sr):?><span class="pill p-slate">Rep: <?php echo htmlspecialchars($f_sr);?></span><?php endif;?>
            <span style="font-size:10px;font-weight:400;color:var(--txs);">·
                <span style="color:#16a34a;font-weight:600;">▲ Excess = Green</span> &nbsp;
                <span style="color:#dc2626;font-weight:600;">▼ Short = Red</span> &nbsp;·&nbsp;
                <span style="color:#dc2626;font-weight:600;">TBD(BF)</span> = invoices moved to a future date (subtracted) &nbsp;·&nbsp;
                <span style="color:#16a34a;font-weight:600;">TBD(BP)</span> = invoices brought from a past date (added) &nbsp;·&nbsp;
                <strong>Net Sec.</strong> = adjusted secondary invoice value
            </span>
        </div>
    </div>
    <div class="top-scroll-wrap no-print" id="topScroll"><div class="top-scroll-inner" id="topScrollInner"></div></div>
    <div class="tscroll" id="mainScroll">
    <table class="cct" id="cctMain">
      <thead>
        <tr class="G">
            <th class="h0 tl stk" rowspan="2" style="min-width:78px;">Rep Code</th>
            <th class="h0 tl"    colspan="1"></th>
            <th class="h1"       colspan="4">Daily Invoice Details</th>
            <th class="h2"       colspan="4">Invoice Payments</th>
            <th class="hcc"      colspan="6"><i class="fa-solid fa-user-tie"></i> CC Collection</th>
            <th class="hsr"      colspan="6"><i class="fa-solid fa-person-walking"></i> SR Collection</th>
            <th class="htc"      colspan="1">Total</th>
            <th class="hdep"     colspan="4">Cash Deposit</th>
            <th class="hvar"     colspan="6">Variance &amp; Short/Excess</th>
            <th class="htr"      colspan="1">Transfer</th>
            <th class="hnew"     colspan="1">Final Short/Excess</th>
            <th class="h6"       colspan="3">Pay Allocation</th>
            <th class="h6"       colspan="2">Action</th>
        </tr>
        <tr class="S">
            <th class="s0 tl"       style="min-width:80px;"  data-col="del_date">Del. Date</th>
            <th class="s1"          style="min-width:90px;"  data-col="sinv">Sec. Invoice</th>
            <th class="stbd_bf"     style="min-width:82px;"  data-col="sinv_bf"  title="Invoices moved to a future delivery date — subtracted from today">TBD (BF) −</th>
            <th class="stbd_bp"     style="min-width:82px;"  data-col="sinv_bp"  title="Invoices from a past date now delivered today — added to today">TBD (BP) +</th>
            <th class="snet_sinv"   style="min-width:95px;"  data-col="sinv_adj" title="Net Secondary = Sec. Invoice − TBD(BF) + TBD(BP)">Net Sec.</th>
            <th class="s2"          style="min-width:88px;"  data-col="cash_paid">Cash Paid</th>
            <th class="s2"          style="min-width:88px;"  data-col="cheque_paid">Cheque Paid</th>
            <th class="s2"          style="min-width:78px;"  data-col="credit">Credit</th>
            <th class="s2"          style="min-width:58px;"  data-col="pay_diff">Diff</th>
            <th class="scc"         style="min-width:88px;"  data-col="cc_daily_sale">Daily Sale</th>
            <th class="scc"         style="min-width:88px;"  data-col="cc_rcvd_credit">Rcvd Credit</th>
            <th class="scc"         style="min-width:80px;"  data-col="cc_rcvd_rtn_chq">Rtn Cheque</th>
            <th class="scc"         style="min-width:78px;"  data-col="cc_rcvd_rtn_chgs">Rtn Chgs</th>
            <th class="scc"         style="min-width:78px;"  data-col="cc_rcvd_sent_back">Sent Back</th>
            <th class="scc"         style="min-width:85px;"  data-col="cc_total">CC Total</th>
            <th class="ssr"         style="min-width:88px;"  data-col="sr_daily_sale">Daily Sale</th>
            <th class="ssr"         style="min-width:88px;"  data-col="sr_rcvd_credit">Rcvd Credit</th>
            <th class="ssr"         style="min-width:80px;"  data-col="sr_rcvd_rtn_chq">Rtn Cheque</th>
            <th class="ssr"         style="min-width:78px;"  data-col="sr_rcvd_rtn_chgs">Rtn Chgs</th>
            <th class="ssr"         style="min-width:78px;"  data-col="sr_rcvd_sent_back">Sent Back</th>
            <th class="ssr"         style="min-width:85px;"  data-col="sr_total">SR Total</th>
            <th class="stc"         style="min-width:92px;"  data-col="total_coll">Grand Total</th>
            <th class="sdep_cc"     style="min-width:92px;"  data-col="banked_cc"  title="Bank deposit — collected by CC">Dep. CC</th>
            <th class="sdep_sr"     style="min-width:92px;"  data-col="banked_sr"  title="Bank deposit — collected by SR">Dep. SR</th>
            <th class="sbo_cc"      style="min-width:92px;"  data-col="handed_cc"  title="Handed to BO — collected by CC">BO CC</th>
            <th class="sbo_sr"      style="min-width:92px;"  data-col="handed_sr"  title="Handed to BO — collected by SR">BO SR</th>
            <th class="svar_cc"     style="min-width:80px;"  data-col="variance_cc" title="CC total − (Dep CC + BO CC)">Var. CC</th>
            <th class="svar_sr"     style="min-width:80px;"  data-col="variance_sr" title="SR total − (Dep SR + BO SR)">Var. SR</th>
            <th class="ssht_cc"     style="min-width:78px;"  data-col="short_cc">Short CC</th>
            <th class="ssht_sr"     style="min-width:78px;"  data-col="short_sr">Short SR</th>
            <th class="stvar"       style="min-width:90px;"  data-col="bank_diff">Short/Excess</th>
            <th class="stsht"       style="min-width:85px;"  data-col="cash_short_excess">Old S/E</th>
            <th class="str"         style="min-width:110px;" data-col="transfer"  title="Transfer OUT (red) / IN (green)">Cross Charge</th>
            <th class="snew"        style="min-width:95px;"  data-col="new_short_excess" title="Balance after transfer adjustment">Final Short/Excess</th>
            <th class="s6"          style="min-width:90px;"  data-col="p_charge">Charge</th>
            <th class="s6"          style="min-width:90px;"  data-col="p_absorb">Absorb</th>
            <th class="s6"          style="min-width:80px;"  data-col="p_variance">Pay Var.</th>
            <th class="str"         style="min-width:72px;text-align:center;" data-col="action_transfer">Cross Charge</th>
            <th class="s6"          style="min-width:62px;text-align:center;" data-col="action_pay">Pay</th>
        </tr>
        <tr class="TH">
            <td class="tl stk" colspan="2"><i class="fa-solid fa-sigma"></i>&nbsp; Total (<?php echo count($rows);?> rows)</td>
            <td data-col="sinv"><?php echo $totals['sinv']>0?number_format($totals['sinv'],2):'—';?></td>
            <td data-col="sinv_bf" style="color:#fca5a5;font-family:var(--mn);"><?php echo $totals['sinv_bf']>0?'−'.number_format($totals['sinv_bf'],2):'—';?></td>
            <td data-col="sinv_bp" style="color:#86efac;font-family:var(--mn);"><?php echo $totals['sinv_bp']>0?'+'.number_format($totals['sinv_bp'],2):'—';?></td>
            <td data-col="sinv_adj" style="font-weight:800;"><?php echo $totals['sinv_adj']>0?number_format($totals['sinv_adj'],2):'—';?></td>
            <td data-col="cash_paid"><?php echo cc_t($totals['cash_paid']);?></td>
            <td data-col="cheque_paid"><?php echo cc_t($totals['cheque_paid']);?></td>
            <td data-col="credit"><?php echo cc_t($totals['credit']);?></td>
            <td data-col="pay_diff"><?php echo cc_d($totals['pay_diff']);?></td>
            <td data-col="cc_daily_sale"    style="color:#86efac;"><?php echo cc_t($totals['cc_daily_sale']);?></td>
            <td data-col="cc_rcvd_credit"   style="color:#86efac;"><?php echo cc_t($totals['cc_rcvd_credit']);?></td>
            <td data-col="cc_rcvd_rtn_chq"  style="color:#86efac;"><?php echo cc_t($totals['cc_rcvd_rtn_chq']);?></td>
            <td data-col="cc_rcvd_rtn_chgs" style="color:#86efac;"><?php echo cc_t($totals['cc_rcvd_rtn_chgs']);?></td>
            <td data-col="cc_rcvd_sent_back" style="color:#86efac;"><?php echo cc_t($totals['cc_rcvd_sent_back']);?></td>
            <td data-col="cc_total"         style="color:#86efac;"><?php echo cc_t($totals['cc_total']);?></td>
            <td data-col="sr_daily_sale"    style="color:#5eead4;"><?php echo cc_t($totals['sr_daily_sale']);?></td>
            <td data-col="sr_rcvd_credit"   style="color:#5eead4;"><?php echo cc_t($totals['sr_rcvd_credit']);?></td>
            <td data-col="sr_rcvd_rtn_chq"  style="color:#5eead4;"><?php echo cc_t($totals['sr_rcvd_rtn_chq']);?></td>
            <td data-col="sr_rcvd_rtn_chgs" style="color:#5eead4;"><?php echo cc_t($totals['sr_rcvd_rtn_chgs']);?></td>
            <td data-col="sr_rcvd_sent_back" style="color:#5eead4;"><?php echo cc_t($totals['sr_rcvd_sent_back']);?></td>
            <td data-col="sr_total"         style="color:#5eead4;"><?php echo cc_t($totals['sr_total']);?></td>
            <td data-col="total_coll"><?php echo cc_t($totals['total_coll']);?></td>
            <td data-col="banked_cc"><?php echo cc_t($totals['banked_cc']);?></td>
            <td data-col="banked_sr"><?php echo cc_t($totals['banked_sr']);?></td>
            <td data-col="handed_cc"><?php echo cc_t($totals['handed_cc']);?></td>
            <td data-col="handed_sr"><?php echo cc_t($totals['handed_sr']);?></td>
            <td data-col="variance_cc"><?php echo cc_d($totals['variance_cc']);?></td>
            <td data-col="variance_sr"><?php echo cc_d($totals['variance_sr']);?></td>
            <td data-col="short_cc"><?php $tsc=$totals['short_cc'];echo $tsc>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($tsc,2).'</span>':'—';?></td>
            <td data-col="short_sr"><?php $tss=$totals['short_sr'];echo $tss>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($tss,2).'</span>':'—';?></td>
            <?php $tv_tot=$totals['total_coll']-$totals['banked']-$totals['handed'];?>
            <td data-col="bank_diff"           id="th-se"><?php echo cc_se($tv_tot);?></td>
            <td data-col="cash_short_excess"   id="th-oldse"><?php echo cc_se($totals['cash_short_excess']);?></td>
            <td data-col="transfer" id="th-tr-combined">
              <?php $to=$totals['t_out'];$ti=$totals['t_in'];?>
              <?php if($to>0||$ti>0):?>
                <?php if($to>0):?><span style="color:#f87171;font-family:var(--mn);font-size:10px;font-weight:700;">▼<?php echo number_format($to,2);?></span><?php endif;?>
                <?php if($to>0&&$ti>0):?> · <?php endif;?>
                <?php if($ti>0):?><span style="color:#34d399;font-family:var(--mn);font-size:10px;font-weight:700;">▲<?php echo number_format($ti,2);?></span><?php endif;?>
              <?php else:?>—<?php endif;?>
            </td>
            <td data-col="new_short_excess" id="th-newse"><?php echo cc_se($totals['new_short_excess']);?></td>
            <td data-col="p_charge" id="th-charge"><?php echo cc_t($totals['p_charge']);?></td>
            <td data-col="p_absorb" id="th-absorb"><?php echo cc_t($totals['p_absorb']);?></td>
            <td data-col="p_variance"></td>
            <td data-col="action_transfer"></td>
            <td data-col="action_pay"></td>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $i=>$r):$stripe=($i%2!==0)?'stripe':'';?>
      <tr class="<?php echo $stripe;?>">
          <td class="tl rc stk"><?php echo htmlspecialchars($r['sr_code']);?></td>
          <td class="tl dt"     data-col="del_date"><?php echo date('d M Y',strtotime($r['del_date']));?></td>
          <td class="fb"        data-col="sinv"><?php echo cc_sinv_orig($r['sinv']);?></td>
          <td class="col-tbd-bf" data-col="sinv_bf"><?php echo cc_sinv_bf($r['sinv_bf']);?></td>
          <td class="col-tbd-bp" data-col="sinv_bp"><?php echo cc_sinv_bp($r['sinv_bp']);?></td>
          <td class="col-net-sinv" data-col="sinv_adj"><?php echo cc_sinv_net($r['sinv_adj']);?></td>
          <td data-col="cash_paid"><?php echo cc_pay_link($r['cash_paid'],$r['sr_code'],$r['del_date'],'cash');?></td>
          <td data-col="cheque_paid"><?php echo cc_pay_link($r['cheque_paid'],$r['sr_code'],$r['del_date'],'cheque');?></td>
          <td data-col="credit"><?php echo cc_v($r['credit']);?></td>
          <td data-col="pay_diff"><?php echo cc_d($r['pay_diff']);?></td>
          <td class="col-c" data-col="cc_daily_sale"><?php echo cc_clink($r['cc_daily_sale'],$r['sr_code'],$r['del_date'],'cc','invoice');?></td>
          <td class="col-c" data-col="cc_rcvd_credit"><?php echo cc_clink($r['cc_rcvd_credit'],$r['sr_code'],$r['del_date'],'cc','credit');?></td>
          <td class="col-c" data-col="cc_rcvd_rtn_chq"><?php echo cc_clink($r['cc_rcvd_rtn_chq'],$r['sr_code'],$r['del_date'],'cc','rtn_chq');?></td>
          <td class="col-c" data-col="cc_rcvd_rtn_chgs"><?php echo cc_clink($r['cc_rcvd_rtn_chgs'],$r['sr_code'],$r['del_date'],'cc','rtn_chgs');?></td>
          <td class="col-c" data-col="cc_rcvd_sent_back"><?php echo cc_clink($r['cc_rcvd_sent_back'],$r['sr_code'],$r['del_date'],'cc','sent_back');?></td>
          <td class="col-c tv" data-col="cc_total"><?php echo cc_clink($r['cc_total'],$r['sr_code'],$r['del_date'],'cc');?></td>
          <td class="col-s" data-col="sr_daily_sale"><?php echo cc_clink($r['sr_daily_sale'],$r['sr_code'],$r['del_date'],'sr','invoice');?></td>
          <td class="col-s" data-col="sr_rcvd_credit"><?php echo cc_clink($r['sr_rcvd_credit'],$r['sr_code'],$r['del_date'],'sr','credit');?></td>
          <td class="col-s" data-col="sr_rcvd_rtn_chq"><?php echo cc_clink($r['sr_rcvd_rtn_chq'],$r['sr_code'],$r['del_date'],'sr','rtn_chq');?></td>
          <td class="col-s" data-col="sr_rcvd_rtn_chgs"><?php echo cc_clink($r['sr_rcvd_rtn_chgs'],$r['sr_code'],$r['del_date'],'sr','rtn_chgs');?></td>
          <td class="col-s" data-col="sr_rcvd_sent_back"><?php echo cc_clink($r['sr_rcvd_sent_back'],$r['sr_code'],$r['del_date'],'sr','sent_back');?></td>
          <td class="col-s tv" data-col="sr_total"><?php echo cc_clink($r['sr_total'],$r['sr_code'],$r['del_date'],'sr');?></td>
          <td style="font-weight:800;color:#0f172a;" data-col="total_coll"><?php echo cc_v($r['total_coll']);?></td>
          <td class="col-dep-cc" data-col="banked_cc"><?php echo cc_dep_link($r['banked_cc'],$r['sr_code'],$r['del_date'],'bank','cc');?></td>
          <td class="col-dep-sr" data-col="banked_sr"><?php echo cc_dep_link($r['banked_sr'],$r['sr_code'],$r['del_date'],'bank','sr');?></td>
          <td class="col-bo-cc"  data-col="handed_cc"><?php echo cc_dep_link($r['handed_cc'],$r['sr_code'],$r['del_date'],'bo','cc');?></td>
          <td class="col-bo-sr"  data-col="handed_sr"><?php echo cc_dep_link($r['handed_sr'],$r['sr_code'],$r['del_date'],'bo','sr');?></td>
          <td class="col-var-cc" data-col="variance_cc"><?php echo cc_var_split($r['variance_cc']);?></td>
          <td class="col-var-sr" data-col="variance_sr"><?php echo cc_var_split($r['variance_sr']);?></td>
          <td class="col-sht-cc" data-col="short_cc"><?php echo cc_short_split($r['short_cc']);?></td>
          <td class="col-sht-sr" data-col="short_sr"><?php echo cc_short_split($r['short_sr']);?></td>
          <td class="col-tvar"   data-col="bank_diff"><?php echo cc_se($r['bank_diff']);?></td>
          <td class="col-tsht"   data-col="cash_short_excess"><?php echo cc_se($r['cash_short_excess']);?></td>
          <td class="col-tr" data-col="transfer" id="td-tr-<?php echo $i;?>">
            <?php if($r['t_out']>0||$r['t_in']>0):?>
              <?php if($r['t_out']>0):?><span style="color:#ef4444;font-family:var(--mn);font-size:10.5px;font-weight:700;">▼<?php echo number_format($r['t_out'],2);?></span><?php endif;?>
              <?php if($r['t_out']>0&&$r['t_in']>0):?><br><?php endif;?>
              <?php if($r['t_in']>0):?><span style="color:#22c55e;font-family:var(--mn);font-size:10.5px;font-weight:700;">▲<?php echo number_format($r['t_in'],2);?></span><?php endif;?>
            <?php else:?><span class="dash">—</span><?php endif;?>
          </td>
          <td class="col-new" data-col="new_short_excess" id="td-new-<?php echo $i;?>"><?php echo cc_se($r['new_short_excess']);?></td>
<td class="col-p" data-col="p_charge" id="prow-charge-<?php echo $i;?>"><?php $pc=floatval($r['p_charge']); if(abs($pc)>0.004): echo $pc<0 ? '<span class="val-charge" style="color:#0369a1;">('. number_format(abs($pc),2) .')</span>' : '<span class="val-charge">'. number_format($pc,2) .'</span>'; else: echo '<span class="dash">—</span>'; endif;?></td>      
<td class="col-p" data-col="p_absorb" id="prow-absorb-<?php echo $i;?>"><?php $pa=floatval($r['p_absorb']); if(abs($pa)>0.004): echo $pa<0 ? '<span class="val-absorb" style="color:#dc2626;">('. number_format(abs($pa),2) .')</span>' : '<span class="val-absorb">'. number_format($pa,2) .'</span>'; else: echo '<span class="dash">—</span>'; endif;?></td>
          <td data-col="p_variance" id="prow-var-<?php echo $i;?>"><?php echo cc_pvar($r['p_variance']);?></td>
          <td style="text-align:center;" data-col="action_transfer">
            <button class="btn-transfer<?php echo !empty($r['t_rows'])?' has-tr':'';?>" id="trbtn-<?php echo $i;?>" onclick="openTransferModal(<?php echo $i;?>)" title="Transfer Short/Excess">
              <i class="fa-solid fa-right-left"></i><?php echo !empty($r['t_rows'])?count($r['t_rows']):'';?>
            </button>
          </td>
          <td style="text-align:center;" data-col="action_pay">
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
            <td data-col="sinv"><?php echo $totals['sinv']>0?number_format($totals['sinv'],2):'—';?></td>
            <td data-col="sinv_bf" style="color:#fca5a5;font-family:var(--mn);"><?php echo $totals['sinv_bf']>0?'−'.number_format($totals['sinv_bf'],2):'—';?></td>
            <td data-col="sinv_bp" style="color:#86efac;font-family:var(--mn);"><?php echo $totals['sinv_bp']>0?'+'.number_format($totals['sinv_bp'],2):'—';?></td>
            <td data-col="sinv_adj"><?php echo $totals['sinv_adj']>0?number_format($totals['sinv_adj'],2):'—';?></td>
            <td data-col="cash_paid"><?php echo cc_t($totals['cash_paid']);?></td>
            <td data-col="cheque_paid"><?php echo cc_t($totals['cheque_paid']);?></td>
            <td data-col="credit"><?php echo cc_t($totals['credit']);?></td>
            <td data-col="pay_diff"><?php echo cc_d($totals['pay_diff']);?></td>
            <td data-col="cc_daily_sale"><?php echo cc_t($totals['cc_daily_sale']);?></td>
            <td data-col="cc_rcvd_credit"><?php echo cc_t($totals['cc_rcvd_credit']);?></td>
            <td data-col="cc_rcvd_rtn_chq"><?php echo cc_t($totals['cc_rcvd_rtn_chq']);?></td>
            <td data-col="cc_rcvd_rtn_chgs"><?php echo cc_t($totals['cc_rcvd_rtn_chgs']);?></td>
            <td data-col="cc_rcvd_sent_back"><?php echo cc_t($totals['cc_rcvd_sent_back']);?></td>
            <td data-col="cc_total"><?php echo cc_t($totals['cc_total']);?></td>
            <td data-col="sr_daily_sale"><?php echo cc_t($totals['sr_daily_sale']);?></td>
            <td data-col="sr_rcvd_credit"><?php echo cc_t($totals['sr_rcvd_credit']);?></td>
            <td data-col="sr_rcvd_rtn_chq"><?php echo cc_t($totals['sr_rcvd_rtn_chq']);?></td>
            <td data-col="sr_rcvd_rtn_chgs"><?php echo cc_t($totals['sr_rcvd_rtn_chgs']);?></td>
            <td data-col="sr_rcvd_sent_back"><?php echo cc_t($totals['sr_rcvd_sent_back']);?></td>
            <td data-col="sr_total"><?php echo cc_t($totals['sr_total']);?></td>
            <td data-col="total_coll"><?php echo cc_t($totals['total_coll']);?></td>
            <td data-col="banked_cc"><?php echo cc_t($totals['banked_cc']);?></td>
            <td data-col="banked_sr"><?php echo cc_t($totals['banked_sr']);?></td>
            <td data-col="handed_cc"><?php echo cc_t($totals['handed_cc']);?></td>
            <td data-col="handed_sr"><?php echo cc_t($totals['handed_sr']);?></td>
            <td data-col="variance_cc"><?php echo cc_d($totals['variance_cc']);?></td>
            <td data-col="variance_sr"><?php echo cc_d($totals['variance_sr']);?></td>
            <td data-col="short_cc"><?php echo $totals['short_cc']>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($totals['short_cc'],2).'</span>':'—';?></td>
            <td data-col="short_sr"><?php echo $totals['short_sr']>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($totals['short_sr'],2).'</span>':'—';?></td>
            <?php $fv=$totals['total_coll']-$totals['banked']-$totals['handed'];?>
            <td data-col="bank_diff"         id="tf-se"><?php echo cc_se($fv);?></td>
            <td data-col="cash_short_excess" id="tf-oldse"><?php echo cc_se($totals['cash_short_excess']);?></td>
            <td data-col="transfer" id="tf-tr-combined">
              <?php $to=$totals['t_out'];$ti=$totals['t_in'];?>
              <?php if($to>0||$ti>0):?>
                <?php if($to>0):?><span style="color:#f87171;font-family:var(--mn);font-size:10px;font-weight:700;">▼<?php echo number_format($to,2);?></span><?php endif;?>
                <?php if($to>0&&$ti>0):?> · <?php endif;?>
                <?php if($ti>0):?><span style="color:#34d399;font-family:var(--mn);font-size:10px;font-weight:700;">▲<?php echo number_format($ti,2);?></span><?php endif;?>
              <?php else:?>—<?php endif;?>
            </td>
            <td data-col="new_short_excess" id="tf-newse"><?php echo cc_se($totals['new_short_excess']);?></td>
            <td data-col="p_charge" id="tf-charge"><?php echo cc_t($totals['p_charge']);?></td>
            <td data-col="p_absorb" id="tf-absorb"><?php echo cc_t($totals['p_absorb']);?></td>
            <td data-col="p_variance"></td>
            <td data-col="action_transfer"></td>
            <td data-col="action_pay"></td>
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
      <div class="pmo-info-cell"><div class="pmo-info-lbl">Sales Rep</div><div class="pmo-info-val" id="pm_sr">—</div></div>
      <div class="pmo-info-cell"><div class="pmo-info-lbl">Delivery Date</div><div class="pmo-info-val" id="pm_date">—</div></div>
      <div class="pmo-info-cell"><div class="pmo-info-lbl">Total Cash Collected</div><div class="pmo-info-val" id="pm_total">—</div></div>
      <div class="pmo-info-cell hi-new" id="pm_se_cell"><div class="pmo-info-lbl">Balance (After Transfer)</div><div class="pmo-info-val" id="pm_seval">—</div></div>
    </div>
    <div class="pmo-body">
      <div id="pm_excess_notice" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:9px;padding:10px 14px;margin-bottom:12px;">
        <div style="display:flex;align-items:center;gap:8px;"><i class="fa-solid fa-circle-check" style="color:#16a34a;font-size:16px;"></i>
        <div><div style="font-size:12px;font-weight:700;color:#166534;">No shortage — Excess balance</div>
        <div style="font-size:11px;color:#15803d;margin-top:2px;">This rep has excess cash. No charge or absorption needed.</div></div></div>
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

<!-- ═══════════════════ TRANSFER MODAL ═══════════════════ -->
<div class="modal-overlay" id="transferModal" onclick="if(event.target===this)closeTransferModal()">
  <div class="tr-modal-box">
    <div class="pmo-header">
      <div class="pmo-header-left">
        <div class="tr-modal-icon"><i class="fa-solid fa-right-left"></i></div>
        <div><div class="pmo-title">Short/Excess Transfer</div><div class="pmo-sub" id="tr_sub">—</div></div>
      </div>
      <button class="pmo-close" onclick="closeTransferModal()">×</button>
    </div>
    <div class="pmo-info" style="grid-template-columns:1fr 1fr 1fr;">
      <div class="pmo-info-cell"><div class="pmo-info-lbl">Sales Rep</div><div class="pmo-info-val" id="tr_sr">—</div></div>
      <div class="pmo-info-cell"><div class="pmo-info-lbl">Delivery Date</div><div class="pmo-info-val" id="tr_deldate">—</div></div>
      <div class="pmo-info-cell hi" id="tr_se_cell"><div class="pmo-info-lbl">Current Short/Excess</div><div class="pmo-info-val" id="tr_se_val">—</div></div>
    </div>
    <div class="pmo-body">
      <div id="trHistSection" style="display:none;margin-bottom:16px;">
        <div style="font-size:11px;font-weight:700;color:#374151;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
          <i class="fa-solid fa-clock-rotate-left" style="color:#0369a1;"></i> Existing Transfers
        </div>
        <ul class="tr-hist-list" id="trHistList"></ul>
      </div>
      <div id="trFormSection">
        <div style="font-size:11px;font-weight:700;color:#374151;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
          <i class="fa-solid fa-plus-circle" style="color:#0369a1;"></i> <span id="trFormTitle">New Transfer</span>
        </div>
        <div class="tr-info-banner" id="trInfoBanner">
          <i class="fa-solid fa-circle-info"></i>
          <span id="trInfoText">Transfer shortage/excess to another rep. The transfer reduces <strong>your</strong> balance and adds to <strong>theirs</strong>.</span>
        </div>
        <div class="tr-form-grid">
          <div class="tr-form-field">
            <label><i class="fa-solid fa-calendar-day"></i> Delivery Date</label>
            <input type="date" id="tr_form_deldate">
          </div>
          <div class="tr-form-field">
            <label><i class="fa-solid fa-calendar-check"></i> Transfer Date</label>
            <input type="date" id="tr_form_trdate" value="<?php echo date('Y-m-d');?>">
          </div>
          <div class="tr-form-field" style="grid-column:span 2;">
            <label><i class="fa-solid fa-id-badge"></i> Transfer To (Sales Rep)</label>
            <select id="tr_form_to_sr" style="width:100%;">
              <option value="">— Select Target Rep —</option>
              <?php foreach($all_sr as $sr):?><option value="<?php echo htmlspecialchars($sr);?>"><?php echo htmlspecialchars($sr);?></option><?php endforeach;?>
            </select>
          </div>
          <div class="tr-form-field" style="grid-column:span 2;">
            <label><i class="fa-solid fa-user"></i> Responsible Employee <span style="font-weight:400;color:#9ca3af;text-transform:none;letter-spacing:0;">(optional)</span></label>
            <select id="tr_form_employee" style="width:100%;">
              <option value="">— Select Employee —</option>
              <?php foreach($emp_list as $e):?><option value="<?php echo intval($e['id']);?>"><?php echo htmlspecialchars($e['emp_code'].' — '.$e['emp_name']);?></option><?php endforeach;?>
            </select>
          </div>
          <div class="tr-form-field">
            <label><i class="fa-solid fa-coins"></i> Amount</label>
            <input type="number" step="0.01" min="0.01" id="tr_form_amount" placeholder="0.00">
          </div>
          <div class="tr-form-field">
            <label><i class="fa-solid fa-note-sticky"></i> Note (optional)</label>
            <input type="text" id="tr_form_note" placeholder="Reason for transfer…">
          </div>
        </div>
      </div>
    </div>
    <div class="pmo-ftr">
      <button class="pmo-btn pmo-btn-cancel" onclick="closeTransferModal()"><i class="fa-solid fa-xmark"></i> Close</button>
      <button class="pmo-btn pmo-btn-save pmo-btn-save-tr" id="saveTrBtn" onclick="saveTransfer()"><i class="fa-solid fa-paper-plane"></i> Save Transfer</button>
    </div>
  </div>
</div>

<!-- ═══════════════════ SAVE REPORT MODAL ═══════════════════ -->
<div class="modal-overlay" id="saveReportModal" onclick="if(event.target===this)closeSaveReportModal()">
  <div class="srm-box">
    <div class="srm-header">
      <div style="display:flex;align-items:center;gap:10px;">
        <div class="srm-icon"><i class="fa-solid fa-floppy-disk"></i></div>
        <div><div class="pmo-title">Save Report Snapshot</div><div class="pmo-sub" id="srm_sub">—</div></div>
      </div>
      <button class="pmo-close" onclick="closeSaveReportModal()">×</button>
    </div>
    <div class="srm-body">
      <div class="srm-info">
        <i class="fa-solid fa-circle-info" style="font-size:13px;margin-top:1px;flex-shrink:0;"></i>
        <span>Saves a <strong>snapshot</strong> of all currently loaded data (rows, totals, transfers) to the database. You can view it any time from <strong>Saved Reports</strong>.</span>
      </div>
      <div class="srm-field">
        <label><i class="fa-solid fa-tag"></i> Report Name</label>
        <input type="text" id="srm_name" placeholder="e.g. Daily Cash – 09 Apr 2026">
      </div>
      <div class="srm-field">
        <label><i class="fa-solid fa-user"></i> Saved By (optional)</label>
        <input type="text" id="srm_by" placeholder="Your name…">
      </div>
    </div>
    <div class="srm-ftr">
      <button class="pmo-btn pmo-btn-cancel" onclick="closeSaveReportModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="pmo-btn pmo-btn-savereport" id="srmSaveBtn" onclick="doSaveReport()"><i class="fa-solid fa-floppy-disk"></i> Save Report</button>
    </div>
  </div>
</div>

<div id="ccToast"></div>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<?php
$rows_js=[];
foreach($rows as $i=>$r){
    $rows_js[]=[
        'idx'          => $i,
        'sr_code'      => $r['sr_code'],
        'del_date'     => $r['del_date'],
        'rep_name'     => $names[$r['sr_code']]??'',
        'pay_date'     => $r['del_date'],
        'total'        => floatval($r['total_coll']),
        'se_val'       => floatval($r['new_short_excess']),
        'orig_se'      => floatval($r['cash_short_excess']),
        'new_se'       => floatval($r['new_short_excess']),
        'sinv'         => floatval($r['sinv']),
        'sinv_bf'      => floatval($r['sinv_bf']),
        'sinv_bp'      => floatval($r['sinv_bp']),
        'sinv_adj'     => floatval($r['sinv_adj']),
        'cash_paid'    => floatval($r['cash_paid']),
        'cheque_paid'  => floatval($r['cheque_paid']),
        'credit'       => floatval($r['credit']),
        'pay_diff'     => floatval($r['pay_diff']),
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
        'total_coll'        => floatval($r['total_coll']),
        'banked_cc'    => floatval($r['banked_cc']),
        'banked_sr'    => floatval($r['banked_sr']),
        'handed_cc'    => floatval($r['handed_cc']),
        'handed_sr'    => floatval($r['handed_sr']),
        'banked'       => floatval($r['banked']),
        'handed'       => floatval($r['handed']),
        'variance_cc'  => floatval($r['variance_cc']),
        'variance_sr'  => floatval($r['variance_sr']),
        'short_cc'     => floatval($r['short_cc']),
        'short_sr'     => floatval($r['short_sr']),
        'bank_diff'          => floatval($r['bank_diff']),
        'cash_short_excess'  => floatval($r['cash_short_excess']),
        't_out'              => floatval($r['t_out']),
        't_in'               => floatval($r['t_in']),
        'shortage_adj'       => floatval($r['shortage_adj']),
        'new_short_excess'   => floatval($r['new_short_excess']),
        't_rows'       => $r['t_rows'],
        'p_charge'     => floatval($r['p_charge']),
        'p_absorb'     => floatval($r['p_absorb']),
        'p_variance'   => $r['p_variance'],
        'employees'    => $r['p_employees'],
        'is_paid'      => $r['is_paid']
    ];
}
?>
<script>
var ROWS_DATA=<?php echo json_encode($rows_js);?>;
var payEmpCount=0,currentPayIdx=null;
var currentTrIdx=null, editingTrId=null;
var EMP_OPTIONS=`<?php foreach($emp_list as $e):?><option value="<?php echo intval($e['id']);?>"><?php echo htmlspecialchars($e['emp_code'].' — '.$e['emp_name']);?></option><?php endforeach;?>`;

function fmtNum(n){return parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');}
function fmtSE(v){
    v=parseFloat(v||0);
    if(Math.abs(v)<0.005) return '<span class="d-ok">0.00 ✓</span>';
    if(v<0) return '<span class="d-exc">▲ '+fmtNum(Math.abs(v))+'</span>';
    return '<span class="d-sht">▼ '+fmtNum(v)+'</span>';
}
/* ── display helpers ── */
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
 
/* ── PAY ALLOC MODAL ── */
function openPayModal(idx){
    currentPayIdx=idx; payEmpCount=0;
    document.getElementById('pm_chargeRows').innerHTML='';
    document.getElementById('pm_absorb_amt').value='';
 
    var rd=ROWS_DATA[idx];
    var newSE=rd.new_short_excess;
    var isShort=newSE>0.005, isExcess=newSE<-0.005, isBalanced=!isShort&&!isExcess;
 
    document.getElementById('pm_sub').textContent=rd.sr_code+' · '+rd.pay_date;
    document.getElementById('pm_sr').textContent=rd.sr_code;
    document.getElementById('pm_date').textContent=rd.pay_date;
    document.getElementById('pm_total').textContent='Rs. '+fmtNum(rd.total);
 
    var seCell=document.getElementById('pm_se_cell');
    seCell.className='pmo-info-cell '+(isShort?'hi-sht':isExcess?'hi-exc':'hi-ok');
    document.getElementById('pm_seval').textContent=
        fmtNum(Math.abs(newSE))+(isShort?' (Short)':isExcess?' (Excess)':' (Balanced)');
 
    document.getElementById('pm_excess_notice').style.display=(isExcess||isBalanced)?'':'none';
 
    var ptbSE=document.getElementById('pm_ptb_seval');
    if(isShort){  ptbSE.textContent='▼ '+fmtNum(newSE);        ptbSE.style.color='#dc2626'; }
    else if(isExcess){ ptbSE.textContent='▲ '+fmtNum(Math.abs(newSE)); ptbSE.style.color='#16a34a'; }
    else {         ptbSE.textContent='0.00 ✓';                 ptbSE.style.color='#16a34a'; }
 
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
 
    /* charge total label */
    var chEl=document.getElementById('pm_charge_total');
    if(Math.abs(tc)<0.004){ chEl.textContent='0.00'; chEl.style.color=''; }
    else if(tc<0){ chEl.textContent='('+fmtNum(Math.abs(tc))+')'; chEl.style.color='#0369a1'; }
    else { chEl.textContent=fmtNum(tc); chEl.style.color=''; }
 
    /* absorb total label */
    var abEl=document.getElementById('pm_absorb_total');
    if(Math.abs(absorb)<0.004){ abEl.textContent='0.00'; abEl.style.color=''; }
    else if(absorb<0){ abEl.textContent='('+fmtNum(Math.abs(absorb))+')'; abEl.style.color='#dc2626'; }
    else { abEl.textContent=fmtNum(absorb); abEl.style.color=''; }
 
    /* total alloc in bottom bar */
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
    else if(vari>0){            vEl.textContent='▼ '+fmtNum(vari);         vEl.style.color='#dc2626'; }
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
        if(!empId){ showCCToast('Please select an employee.','err'); valid=false; return; }
        if(amt===0){ showCCToast('Charge amount cannot be zero.','err'); valid=false; return; }
        charges.push({employee_id:parseInt(empId),employee_name:empLbl,amount:amt});
    });
    if(!valid) return;
 
    var rd=ROWS_DATA[currentPayIdx];
    var absorb=parseFloat(document.getElementById('pm_absorb_amt').value||0)||0;
    var newSE=rd.new_short_excess;
    var tc=charges.reduce(function(s,r){ return s+r.amount; },0);
    var vari=newSE-(tc+absorb);
 
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
 
    fetch('save_cash_summary_pay.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
            pay_date:rd.pay_date, sr_code:rd.sr_code,
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
 
            /* ── update the THREE pay columns in the table row ── */
            document.getElementById('prow-charge-'+idx).innerHTML=fmtCharge(tc);
            document.getElementById('prow-absorb-'+idx).innerHTML=fmtAbsorb(absorb);
            document.getElementById('prow-var-'+idx).innerHTML=fmtPayVar(vari);
 
            var pb=document.getElementById('payallocbtn-'+idx);
            if(pb){ pb.className='btn-pay-alloc paid'; pb.innerHTML='<i class="fa-solid fa-check"></i> Paid'; }
 
            refreshAllTotals();
            closePayModal();
            showCCToast('Pay allocation saved!','ok');
        } else {
            showCCToast('Error: '+(res.message||'Unknown'),'err');
        }
    })
    .catch(function(err){
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Allocation';
        showCCToast('Network error: '+err.message,'err');
    });
}
/* ══════ TRANSFER MODAL ══════ */
function openTransferModal(idx){
    currentTrIdx=idx;
    editingTrId=null;
    const rd=ROWS_DATA[idx];
    document.getElementById('tr_sub').textContent=rd.sr_code+' · '+rd.del_date;
    document.getElementById('tr_sr').textContent=rd.sr_code;
    document.getElementById('tr_deldate').textContent=rd.del_date;
    document.getElementById('tr_se_val').innerHTML=fmtSE(rd.bank_diff);
    var seCell=document.getElementById('tr_se_cell');
    if(rd.bank_diff > 0.005) seCell.className='pmo-info-cell hi-sht';
    else if(rd.bank_diff < -0.005) seCell.className='pmo-info-cell hi-exc';
    else seCell.className='pmo-info-cell hi-ok';
    document.getElementById('tr_form_deldate').value=rd.del_date;
    document.getElementById('tr_form_trdate').value='<?php echo date('Y-m-d');?>';
    document.getElementById('tr_form_amount').value=Math.abs(rd.bank_diff)>0.004?Math.abs(rd.bank_diff).toFixed(2):'';
    document.getElementById('tr_form_note').value='';
    $('#tr_form_to_sr').val('').trigger('change');
    $('#tr_form_employee').val('').trigger('change');
    document.getElementById('trFormTitle').textContent='New Transfer';
    document.getElementById('saveTrBtn').innerHTML='<i class="fa-solid fa-paper-plane"></i> Save Transfer';
    renderTransferHistory(rd.t_rows||[]);
    document.getElementById('transferModal').classList.add('open');
}
function closeTransferModal(){
    document.getElementById('transferModal').classList.remove('open');
    currentTrIdx=null;editingTrId=null;
}
function renderTransferHistory(rows){
    const sec=document.getElementById('trHistSection');
    const list=document.getElementById('trHistList');
    if(!rows||rows.length===0){sec.style.display='none';return;}
    sec.style.display='';
    list.innerHTML='';
    rows.forEach(function(tr){
        const li=document.createElement('li');li.className='tr-hist-item';
        const dirClass=tr.direction==='out'?'out':'in';
        const dirLabel=tr.direction==='out'?'OUT':'IN';
        const otherLabel=tr.direction==='out'?('→ '+tr.other_sr):('← '+tr.other_sr);
        const empBadge=tr.employee_name
            ? `<div class="tr-hist-emp"><i class="fa-solid fa-user" style="font-size:8px;"></i> ${escHtml(tr.employee_name)}</div>`
            : '';
        li.innerHTML=`
          <div class="tr-hist-dir ${dirClass}">${dirLabel}</div>
          <div class="tr-hist-info">
            <div class="tr-hist-sr">${otherLabel}</div>
            <div class="tr-hist-date">Del: ${tr.delivery_date} &nbsp;·&nbsp; Tr: ${tr.transfer_date}</div>
            ${tr.note?`<div class="tr-hist-note">${escHtml(tr.note)}</div>`:''}
            ${empBadge}
          </div>
          <div class="tr-hist-amt ${dirClass}">${dirClass==='out'?'−':'+'}${fmtNum(tr.amount)}</div>
          <div class="tr-hist-actions">
            ${tr.direction==='out'?`
              <button class="tr-hist-edit-btn" onclick="editTransfer(${tr.id},${JSON.stringify(tr).replace(/"/g,'&quot;')})"><i class="fa-solid fa-pen"></i> Edit</button>
              <button class="tr-hist-del-btn" onclick="deleteTransfer(${tr.id})"><i class="fa-solid fa-trash"></i></button>
            `:'<span style="font-size:10px;color:#9ca3af;">Received</span>'}
          </div>`;
        list.appendChild(li);
    });
}
function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

function editTransfer(trId, trData){
    editingTrId=trId;
    document.getElementById('tr_form_deldate').value=trData.delivery_date;
    document.getElementById('tr_form_trdate').value=trData.transfer_date;
    document.getElementById('tr_form_amount').value=trData.amount;
    document.getElementById('tr_form_note').value=trData.note||'';
    $('#tr_form_to_sr').val(trData.other_sr).trigger('change');
    if(trData.employee_id) $('#tr_form_employee').val(trData.employee_id).trigger('change');
    else $('#tr_form_employee').val('').trigger('change');
    document.getElementById('trFormTitle').textContent='Edit Transfer #'+trId;
    document.getElementById('saveTrBtn').innerHTML='<i class="fa-solid fa-floppy-disk"></i> Update Transfer';
}

function saveTransfer(){
    if(currentTrIdx===null)return;
    const rd=ROWS_DATA[currentTrIdx];
    const toSr=document.getElementById('tr_form_to_sr').value;
    const delDate=document.getElementById('tr_form_deldate').value;
    const trDate=document.getElementById('tr_form_trdate').value;
    const amount=parseFloat(document.getElementById('tr_form_amount').value||0);
    const note=document.getElementById('tr_form_note').value.trim();
    const employeeId=parseInt($('#tr_form_employee').val()||0)||null;
    const employeeName=$('#tr_form_employee option:selected').text()||null;
    if(!toSr){showCCToast('Select a target rep.','err');return;}
    if(toSr===rd.sr_code){showCCToast('Cannot transfer to same rep.','err');return;}
    if(!delDate){showCCToast('Enter delivery date.','err');return;}
    if(!trDate){showCCToast('Enter transfer date.','err');return;}
    if(!(amount>0)){showCCToast('Amount must be > 0.','err');return;}
    var oldToSr = null;
    if(editingTrId){
        var oldEntry = (rd.t_rows||[]).find(function(x){ return x.id===editingTrId && x.direction==='out'; });
        if(oldEntry) oldToSr = oldEntry.other_sr;
    }
    const btn=document.getElementById('saveTrBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    const payload={
        action: editingTrId?'update':'create',
        id: editingTrId||null,
        from_sr_code:rd.sr_code, to_sr_code:toSr,
        delivery_date:delDate, transfer_date:trDate,
        amount:amount, note:note,
        employee_id: employeeId,
        employee_name: (employeeId && employeeName && employeeName!=='— Select Employee —') ? employeeName : null
    };
    fetch('save_cash_transfer.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
    .then(r=>r.json()).then(res=>{
        btn.disabled=false;
        btn.innerHTML=editingTrId?'<i class="fa-solid fa-floppy-disk"></i> Update Transfer':'<i class="fa-solid fa-paper-plane"></i> Save Transfer';
        if(res.success){
            showCCToast(editingTrId?'Transfer updated!':'Transfer saved!','ok');
            var newTrId = res.transfer_id || editingTrId || 0;
            if(editingTrId && oldToSr && oldToSr !== toSr){
                var oldToIdx = findRowIdx(delDate, oldToSr);
                if(oldToIdx >= 0){
                    var oldToRd = ROWS_DATA[oldToIdx];
                    oldToRd.t_rows = (oldToRd.t_rows||[]).filter(function(x){ return x.id !== editingTrId; });
                    recomputeTotalsAndApply(oldToIdx);
                }
            }
            if(res.from_row_data || res.row_data){
                applyTransferDataToRow(currentTrIdx, res.from_row_data || res.row_data);
            } else {
                if(editingTrId){
                    rd.t_rows = (rd.t_rows||[]).filter(function(x){ return x.id !== editingTrId; });
                }
                rd.t_rows = (rd.t_rows||[]).concat([{
                    id: newTrId, direction: 'out', other_sr: toSr,
                    delivery_date: delDate, transfer_date: trDate,
                    amount: amount, note: note,
                    employee_id: employeeId,
                    employee_name: payload.employee_name
                }]);
                recomputeTotalsAndApply(currentTrIdx);
            }
            var toIdx = findRowIdx(delDate, toSr);
            if(toIdx >= 0){
                if(res.to_row_data){
                    applyTransferDataToRow(toIdx, res.to_row_data);
                } else {
                    var toRd = ROWS_DATA[toIdx];
                    if(editingTrId){
                        toRd.t_rows = (toRd.t_rows||[]).filter(function(x){ return x.id !== editingTrId; });
                    }
                    toRd.t_rows = (toRd.t_rows||[]).concat([{
                        id: newTrId, direction: 'in', other_sr: rd.sr_code,
                        delivery_date: delDate, transfer_date: trDate,
                        amount: amount, note: note,
                        employee_id: employeeId,
                        employee_name: payload.employee_name
                    }]);
                    recomputeTotalsAndApply(toIdx);
                }
            }
            editingTrId=null;
            document.getElementById('tr_form_amount').value='';
            document.getElementById('tr_form_note').value='';
            $('#tr_form_to_sr').val('').trigger('change');
            $('#tr_form_employee').val('').trigger('change');
            document.getElementById('trFormTitle').textContent='New Transfer';
            btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Save Transfer';
            document.getElementById('tr_se_val').innerHTML=fmtSE(rd.bank_diff);
            renderTransferHistory(ROWS_DATA[currentTrIdx].t_rows||[]);
            refreshAllTotals();
        } else showCCToast('Error: '+(res.message||'Unknown'),'err');
    }).catch(err=>{
        btn.disabled=false;
        btn.innerHTML=editingTrId?'<i class="fa-solid fa-floppy-disk"></i> Update Transfer':'<i class="fa-solid fa-paper-plane"></i> Save Transfer';
        showCCToast('Network error: '+err.message,'err');
    });
}

function deleteTransfer(trId){
    if(!confirm('Delete this transfer?')) return;
    var fromIdx = currentTrIdx;
    var fromRd  = ROWS_DATA[fromIdx];
    var trEntry = (fromRd.t_rows||[]).find(function(x){ return x.id===trId; });
    fetch('save_cash_transfer.php',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'delete',id:trId})
    }).then(r=>r.json()).then(res=>{
        if(res.success){
            showCCToast('Transfer deleted.','ok');
            if(res.from_row_data || res.row_data){
                applyTransferDataToRow(fromIdx, res.from_row_data || res.row_data);
            } else {
                fromRd.t_rows = (fromRd.t_rows||[]).filter(function(x){ return x.id !== trId; });
                recomputeTotalsAndApply(fromIdx);
            }
            if(trEntry){
                var otherSr = trEntry.other_sr;
                var otherDelDate = trEntry.delivery_date;
                var toIdx2 = findRowIdx(otherDelDate, otherSr);
                if(toIdx2 >= 0){
                    if(res.to_row_data){
                        applyTransferDataToRow(toIdx2, res.to_row_data);
                    } else {
                        var toRd2 = ROWS_DATA[toIdx2];
                        toRd2.t_rows = (toRd2.t_rows||[]).filter(function(x){ return x.id !== trId; });
                        recomputeTotalsAndApply(toIdx2);
                    }
                }
            }
            document.getElementById('tr_se_val').innerHTML=fmtSE(fromRd.bank_diff);
            renderTransferHistory(ROWS_DATA[currentTrIdx].t_rows||[]);
            refreshAllTotals();
        } else showCCToast('Error: '+(res.message||'Unknown'),'err');
    }).catch(err=>showCCToast('Network error: '+err.message,'err'));
}

function recomputeTotalsAndApply(idx){
    var rd = ROWS_DATA[idx];
    if(!rd) return;
    var tout=0, tin=0;
    (rd.t_rows||[]).forEach(function(x){
        if(x.direction==='out') tout += parseFloat(x.amount||0);
        else                    tin  += parseFloat(x.amount||0);
    });
    rd.t_out = tout;
    rd.t_in  = tin;
    recalcRowTransferFields(rd);
    applyTransferDataToRow(idx, null);
}

function refreshAllTotals(){
    var t = {
        t_out:0, t_in:0, shortage_adj:0, new_short_excess:0,
        bank_diff:0, cash_short_excess:0,
        p_charge:0, p_absorb:0,
        total_coll:0, banked:0, handed:0
    };
    ROWS_DATA.forEach(function(r){
        t.t_out            += r.t_out||0;
        t.t_in             += r.t_in||0;
        t.shortage_adj     += r.shortage_adj||0;
        t.new_short_excess += r.new_short_excess||0;
        t.bank_diff        += r.bank_diff||0;
        t.cash_short_excess+= r.cash_short_excess||0;
        t.p_charge         += r.p_charge||0;
        t.p_absorb         += r.p_absorb||0;
        t.total_coll       += r.total_coll||0;
        t.banked           += r.banked||0;
        t.handed           += r.handed||0;
    });
    var ccT = function(n){ return n==0 ? '—' : fmtNum(n); };
    function fmtTrCombined(tout, tin, small){
        var fs = small ? '10px' : '10.5px';
        var out = tout > 0 ? '<span style="color:#ef4444;font-family:var(--mn);font-size:'+fs+';font-weight:700;">▼'+fmtNum(tout)+'</span>' : '';
        var inn = tin  > 0 ? '<span style="color:#22c55e;font-family:var(--mn);font-size:'+fs+';font-weight:700;">▲'+fmtNum(tin)+'</span>' : '';
        if(out && inn) return out + (small ? ' · ' : '<br>') + inn;
        return out || inn || '—';
    }
    var thTr=document.getElementById('th-tr-combined');
    var thNew=document.getElementById('th-newse');
    var thChrg=document.getElementById('th-charge'),thAbsb=document.getElementById('th-absorb');
    if(thTr)   thTr.innerHTML  =fmtTrCombined(t.t_out, t.t_in, true);
    if(thNew)  thNew.innerHTML =fmtSE(t.new_short_excess);
    if(thChrg) thChrg.innerHTML=ccT(t.p_charge);
    if(thAbsb) thAbsb.innerHTML=ccT(t.p_absorb);
    var tfTr=document.getElementById('tf-tr-combined');
    var tfNew=document.getElementById('tf-newse');
    var tfChrg=document.getElementById('tf-charge'),tfAbsb=document.getElementById('tf-absorb');
    if(tfTr)   tfTr.innerHTML  =fmtTrCombined(t.t_out, t.t_in, true);
    if(tfNew)  tfNew.innerHTML =fmtSE(t.new_short_excess);
    if(tfChrg) tfChrg.innerHTML=ccT(t.p_charge);
    if(tfAbsb) tfAbsb.innerHTML=ccT(t.p_absorb);
    var scCard=document.getElementById('sc-netse'),scVal=document.getElementById('sc-netse-val');
    if(scCard&&scVal){
        var nse=t.new_short_excess;
        var isShort=nse>0.005,isExcess=nse<-0.005;
        scCard.className='sc '+(isShort?'red':'green');
        scVal.style.color=isShort?'#991b1b':'#166534';
        var label=isExcess?' <small style="font-size:10px;font-weight:600;">(Excess)</small>'
                 :(Math.abs(nse)<0.005?' <small>(OK)</small>':'');
        scVal.innerHTML='Rs. '+fmtNum(Math.abs(nse))+label;
    }
}

/* ══════ TOAST ══════ */
function showCCToast(msg,type){
    const t=document.getElementById('ccToast');
    t.className=type==='ok'?'toast-ok':'toast-err';
    t.textContent=msg;t.style.display='block';t.style.opacity='1';
    clearTimeout(t._t);
    t._t=setTimeout(()=>{t.style.opacity='0';setTimeout(()=>t.style.display='none',300);},2800);
}

document.getElementById('ccf')?.addEventListener('submit',()=>{
    const b=document.getElementById('gBtn');b.disabled=true;b.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Generating…';
});

/* ══════ SYNC SCROLLBARS ══════ */
(function(){
    const top=document.getElementById('topScroll'),main=document.getElementById('mainScroll');
    if(!top||!main)return;
    const inner=document.getElementById('topScrollInner');
    function setW(){const tbl=main.querySelector('table.cct');if(tbl)inner.style.width=tbl.scrollWidth+'px';}
    setW();window.addEventListener('resize',setW);
    let syncing=false;
    top.addEventListener('scroll',()=>{if(syncing)return;syncing=true;main.scrollLeft=top.scrollLeft;syncing=false;});
    main.addEventListener('scroll',()=>{if(syncing)return;syncing=true;top.scrollLeft=main.scrollLeft;syncing=false;});
})();

$(document).ready(function(){
    $('#tr_form_to_sr').select2({
        dropdownParent: $('#transferModal'),
        placeholder: '— Select Target Rep —',
        allowClear: true,
        width: 'resolve'
    });
    $('#tr_form_employee').select2({
        dropdownParent: $('#transferModal'),
        placeholder: '— Select Employee —',
        allowClear: true,
        minimumResultsForSearch: 0,
        width: 'resolve'
    });
    hideEmptyColumns();
});

/* ══════ SAVE REPORT ══════ */
function openSaveReportModal(){
    var df='<?php echo date("d M Y", strtotime($date_from));?>';
    var dt='<?php echo date("d M Y", strtotime($date_to));?>';
    var sr='<?php echo addslashes($f_sr);?>';
    document.getElementById('srm_name').value='Cash Report '+df+(df!==dt?' – '+dt:'')+(sr?' ('+sr+')':'');
    document.getElementById('srm_by').value='';
    document.getElementById('srm_sub').textContent=df+(df!==dt?' – '+dt:'')+' · '+ROWS_DATA.length+' rows';
    document.getElementById('saveReportModal').classList.add('open');
}
function closeSaveReportModal(){
    document.getElementById('saveReportModal').classList.remove('open');
}
function doSaveReport(){
    var name=document.getElementById('srm_name').value.trim();
    if(!name){showCCToast('Please enter a report name.','err');return;}
    var btn=document.getElementById('srmSaveBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    var t={sinv:0,sinv_bf:0,sinv_bp:0,sinv_adj:0,
           cash_paid:0,cheque_paid:0,credit:0,pay_diff:0,
           cc_daily_sale:0,cc_rcvd_credit:0,cc_rcvd_rtn_chq:0,cc_rcvd_rtn_chgs:0,cc_rcvd_sent_back:0,cc_total:0,
           sr_daily_sale:0,sr_rcvd_credit:0,sr_rcvd_rtn_chq:0,sr_rcvd_rtn_chgs:0,sr_rcvd_sent_back:0,sr_total:0,
           total_coll:0,banked:0,handed:0,banked_cc:0,banked_sr:0,handed_cc:0,handed_sr:0,
           variance_cc:0,variance_sr:0,short_cc:0,short_sr:0,
           bank_diff:0,cash_short_excess:0,t_out:0,t_in:0,shortage_adj:0,new_short_excess:0,
           p_charge:0,p_absorb:0};
    ROWS_DATA.forEach(function(r){
        Object.keys(t).forEach(function(k){ if(r[k]!==undefined) t[k]+=parseFloat(r[k]||0); });
    });
    var payload={
        report_name: name,
        saved_by: document.getElementById('srm_by').value.trim()||null,
        date_from: '<?php echo $date_from;?>',
        date_to:   '<?php echo $date_to;?>',
        sr_code:   '<?php echo addslashes($f_sr);?>',
        totals: t,
        rows:   ROWS_DATA
    };
    fetch('save_cash_report.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify(payload)
    }).then(function(r){return r.json();}).then(function(res){
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Report';
        if(res.success){
            closeSaveReportModal();
            showCCToast('Report saved! ID #'+res.report_id,'ok');
        } else {
            showCCToast('Error: '+(res.message||'Unknown'),'err');
        }
    }).catch(function(err){
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Report';
        showCCToast('Network error: '+err.message,'err');
    });
}







/*
 * COMPLETE TRANSFER FIX for cash_collection.php
 *
 * ROOT CAUSE: PHP formula was branching on bank_diff sign, causing receivers
 * with a SHORT balance to get WORSE when receiving a transfer.
 *
 * UNIVERSAL CORRECT FORMULA (no branching):
 *   new_short_excess = bank_diff + t_out - t_in
 *
 * Sign convention (matches cc_se() PHP helper):
 *   positive = SHORT  (red ▼)
 *   negative = EXCESS (green ▲)
 *
 * Verified cases:
 *   Sender SHORT  +100, sends   30 → +130 (more short — they sent money they didn't have) ✓
 *   Sender EXCESS -100, sends   30 →  -70 (less excess — gave some away)                 ✓
 *   Recvr SHORT   +100, recvs   30 →  +70 (less short — received cash reduces shortage)  ✓
 *   Recvr EXCESS  -100, recvs   30 → -130 (more excess — received even more cash)        ✓
 *   Sender EXCESS  -5.56, sends 5.56→   0 (balanced)                                    ✓
 *   Recvr SHORT +2758.56, recvs 5.56→+2753 (reduced by received amount)                 ✓
 *
 * PHP FIX (in cash_collection.php, find the two-branch formula and replace):
 * ─────────────────────────────────────────────────────────────────────────
 * REMOVE:
 *   if ($bank_diff >= 0) {
 *       $new_short_excess = $bank_diff - $t_out + $t_in;
 *   } else {
 *       $new_short_excess = $bank_diff + $t_out - $t_in;
 *   }
 *
 * REPLACE WITH:
 *   $new_short_excess = $bank_diff + $t_out - $t_in;
 * ─────────────────────────────────────────────────────────────────────────
 *
 * JS FIX: replace all transfer-related functions below.
 * findRowIdx, recalcRowTransferFields, applyTransferDataToRow,
 * recomputeTotalsAndApply, openTransferModal, closeTransferModal,
 * renderTransferHistory, editTransfer, saveTransfer, deleteTransfer
 */

/* ─────────────────────────────────────────────────────────
 * findRowIdx  — locate a row in ROWS_DATA by date + sr_code
 * ───────────────────────────────────────────────────────── */
function findRowIdx(delDate, srCode) {
    for (var i = 0; i < ROWS_DATA.length; i++) {
        if (ROWS_DATA[i].del_date === delDate && ROWS_DATA[i].sr_code === srCode) return i;
    }
    return -1;
}

/* ─────────────────────────────────────────────────────────
 * recalcRowTransferFields
 * Recomputes t_out, t_in, shortage_adj, new_short_excess
 * from the row's t_rows array.
 *
 * UNIVERSAL FORMULA:  new_short_excess = bank_diff + t_out - t_in
 * ───────────────────────────────────────────────────────── */
function recalcRowTransferFields(rd) {
    var tout = 0, tin = 0;
    (rd.t_rows || []).forEach(function (x) {
        if (x.direction === 'out') tout += parseFloat(x.amount || 0);
        else                       tin  += parseFloat(x.amount || 0);
    });
    rd.t_out            = tout;
    rd.t_in             = tin;
    rd.shortage_adj     = tout - tin;
    rd.new_short_excess = rd.bank_diff - tout + tin;  // ← FIXED (was + tout - tin)
}
 

/* ─────────────────────────────────────────────────────────
 * applyTransferDataToRow
 * Merges optional server response data into ROWS_DATA[idx]
 * then refreshes the three DOM cells for that row:
 *   • Transfer column  (td-tr-{idx})
 *   • Final S/E column (td-new-{idx})
 *   • Action button    (trbtn-{idx})
 * ───────────────────────────────────────────────────────── */
function applyTransferDataToRow(idx, serverData) {
    var rd = ROWS_DATA[idx];
    if (!rd) return;

    if (serverData) {
        if (serverData.t_rows           !== undefined) rd.t_rows           = serverData.t_rows;
        if (serverData.t_out            !== undefined) rd.t_out            = parseFloat(serverData.t_out);
        if (serverData.t_in             !== undefined) rd.t_in             = parseFloat(serverData.t_in);
        if (serverData.shortage_adj     !== undefined) rd.shortage_adj     = parseFloat(serverData.shortage_adj);
        if (serverData.new_short_excess !== undefined) rd.new_short_excess = parseFloat(serverData.new_short_excess);
        // Always re-derive from t_rows so formula is consistent
        if (serverData.t_rows !== undefined) recalcRowTransferFields(rd);
    } else {
        recalcRowTransferFields(rd);
    }

    /* Transfer column */
    var trTd = document.getElementById('td-tr-' + idx);
    if (trTd) {
        var tout = rd.t_out, tin = rd.t_in;
        if (tout > 0 || tin > 0) {
            var parts = [];
            if (tout > 0) parts.push('<span style="color:#ef4444;font-family:var(--mn);font-size:10.5px;font-weight:700;">▼' + fmtNum(tout) + '</span>');
            if (tin  > 0) parts.push('<span style="color:#22c55e;font-family:var(--mn);font-size:10.5px;font-weight:700;">▲' + fmtNum(tin)  + '</span>');
            trTd.innerHTML = parts.join('<br>');
        } else {
            trTd.innerHTML = '<span class="dash">—</span>';
        }
    }

    /* Final Short/Excess column */
    var newTd = document.getElementById('td-new-' + idx);
    if (newTd) newTd.innerHTML = fmtSE(rd.new_short_excess);

    /* Action button badge */
    var trBtn = document.getElementById('trbtn-' + idx);
    if (trBtn) {
        var count = (rd.t_rows || []).length;
        trBtn.className = 'btn-transfer' + (count > 0 ? ' has-tr' : '');
        trBtn.innerHTML = '<i class="fa-solid fa-right-left"></i>' + (count > 0 ? count : '');
    }
}

/* ─────────────────────────────────────────────────────────
 * recomputeTotalsAndApply  — recalc + refresh DOM for one row
 * ───────────────────────────────────────────────────────── */
function recomputeTotalsAndApply(idx) {
    if (!ROWS_DATA[idx]) return;
    recalcRowTransferFields(ROWS_DATA[idx]);
    applyTransferDataToRow(idx, null);
}

/* ══════════════════════════════════════════════════════════
 * TRANSFER MODAL
 * ══════════════════════════════════════════════════════════ */
function openTransferModal(idx) {
    currentTrIdx = idx;
    editingTrId  = null;
    var rd = ROWS_DATA[idx];

    document.getElementById('tr_sub').textContent     = rd.sr_code + ' · ' + rd.del_date;
    document.getElementById('tr_sr').textContent      = rd.sr_code;
    document.getElementById('tr_deldate').textContent = rd.del_date;
    document.getElementById('tr_se_val').innerHTML    = fmtSE(rd.bank_diff);

    var seCell = document.getElementById('tr_se_cell');
    seCell.className = rd.bank_diff > 0.005 ? 'pmo-info-cell hi-sht'
                     : rd.bank_diff < -0.005 ? 'pmo-info-cell hi-exc'
                     : 'pmo-info-cell hi-ok';

    document.getElementById('tr_form_deldate').value = rd.del_date;
    document.getElementById('tr_form_trdate').value  = '<?php echo date("Y-m-d"); ?>';
    document.getElementById('tr_form_amount').value  = Math.abs(rd.bank_diff) > 0.004 ? Math.abs(rd.bank_diff).toFixed(2) : '';
    document.getElementById('tr_form_note').value    = '';

    $('#tr_form_to_sr').val('').trigger('change');
    $('#tr_form_employee').val('').trigger('change');

    document.getElementById('trFormTitle').textContent = 'New Transfer';
    document.getElementById('saveTrBtn').innerHTML     = '<i class="fa-solid fa-paper-plane"></i> Save Transfer';

    renderTransferHistory(rd.t_rows || []);
    document.getElementById('transferModal').classList.add('open');
}

function closeTransferModal() {
    document.getElementById('transferModal').classList.remove('open');
    currentTrIdx = null;
    editingTrId  = null;
}

function renderTransferHistory(rows) {
    var sec  = document.getElementById('trHistSection');
    var list = document.getElementById('trHistList');
    if (!rows || rows.length === 0) { sec.style.display = 'none'; return; }
    sec.style.display = '';
    list.innerHTML = '';
    rows.forEach(function (tr) {
        var li       = document.createElement('li');
        li.className = 'tr-hist-item';
        var dirClass = tr.direction === 'out' ? 'out' : 'in';
        var dirLabel = tr.direction === 'out' ? 'OUT' : 'IN';
        var peer     = tr.direction === 'out' ? ('→ ' + tr.other_sr) : ('← ' + tr.other_sr);
        var empBadge = tr.employee_name
            ? '<div class="tr-hist-emp"><i class="fa-solid fa-user" style="font-size:8px;"></i> ' + escHtml(tr.employee_name) + '</div>'
            : '';
        li.innerHTML =
            '<div class="tr-hist-dir ' + dirClass + '">' + dirLabel + '</div>' +
            '<div class="tr-hist-info">' +
              '<div class="tr-hist-sr">'   + peer + '</div>' +
              '<div class="tr-hist-date">Del: ' + tr.delivery_date + ' &nbsp;·&nbsp; Tr: ' + tr.transfer_date + '</div>' +
              (tr.note ? '<div class="tr-hist-note">' + escHtml(tr.note) + '</div>' : '') +
              empBadge +
            '</div>' +
            '<div class="tr-hist-amt ' + dirClass + '">' + (dirClass === 'out' ? '−' : '+') + fmtNum(tr.amount) + '</div>' +
            '<div class="tr-hist-actions">' +
              (tr.direction === 'out'
                ? '<button class="tr-hist-edit-btn" onclick="editTransfer(' + tr.id + ',' + JSON.stringify(tr).replace(/"/g, '&quot;') + ')"><i class="fa-solid fa-pen"></i> Edit</button>' +
                  '<button class="tr-hist-del-btn" onclick="deleteTransfer(' + tr.id + ')"><i class="fa-solid fa-trash"></i></button>'
                : '<span style="font-size:10px;color:#9ca3af;">Received</span>') +
            '</div>';
        list.appendChild(li);
    });
}

function editTransfer(trId, trData) {
    editingTrId = trId;
    document.getElementById('tr_form_deldate').value = trData.delivery_date;
    document.getElementById('tr_form_trdate').value  = trData.transfer_date;
    document.getElementById('tr_form_amount').value  = trData.amount;
    document.getElementById('tr_form_note').value    = trData.note || '';
    $('#tr_form_to_sr').val(trData.other_sr).trigger('change');
    if (trData.employee_id) $('#tr_form_employee').val(trData.employee_id).trigger('change');
    else                    $('#tr_form_employee').val('').trigger('change');
    document.getElementById('trFormTitle').textContent = 'Edit Transfer #' + trId;
    document.getElementById('saveTrBtn').innerHTML     = '<i class="fa-solid fa-floppy-disk"></i> Update Transfer';
}

function saveTransfer() {
    if (currentTrIdx === null) return;
    var rd           = ROWS_DATA[currentTrIdx];
    var toSr         = document.getElementById('tr_form_to_sr').value;
    var delDate      = document.getElementById('tr_form_deldate').value;
    var trDate       = document.getElementById('tr_form_trdate').value;
    var amount       = parseFloat(document.getElementById('tr_form_amount').value || 0);
    var note         = document.getElementById('tr_form_note').value.trim();
    var employeeId   = parseInt($('#tr_form_employee').val() || 0) || null;
    var empText      = $('#tr_form_employee option:selected').text() || '';
    var employeeName = (employeeId && empText && empText !== '— Select Employee —') ? empText : null;

    if (!toSr)               { showCCToast('Select a target rep.', 'err');          return; }
    if (toSr === rd.sr_code) { showCCToast('Cannot transfer to same rep.', 'err');  return; }
    if (!delDate)            { showCCToast('Enter delivery date.', 'err');           return; }
    if (!trDate)             { showCCToast('Enter transfer date.', 'err');           return; }
    if (!(amount > 0))       { showCCToast('Amount must be > 0.', 'err');            return; }

    /* Remember old target SR for edit case */
    var oldToSr = null;
    if (editingTrId) {
        var oldEntry = (rd.t_rows || []).find(function (x) { return x.id === editingTrId && x.direction === 'out'; });
        if (oldEntry) oldToSr = oldEntry.other_sr;
    }

    var btn = document.getElementById('saveTrBtn');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    fetch('save_cash_transfer.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
            action:        editingTrId ? 'update' : 'create',
            id:            editingTrId || null,
            from_sr_code:  rd.sr_code,
            to_sr_code:    toSr,
            delivery_date: delDate,
            transfer_date: trDate,
            amount:        amount,
            note:          note,
            employee_id:   employeeId,
            employee_name: employeeName
        })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        btn.disabled  = false;
        btn.innerHTML = editingTrId
            ? '<i class="fa-solid fa-floppy-disk"></i> Update Transfer'
            : '<i class="fa-solid fa-paper-plane"></i> Save Transfer';

        if (!res.success) { showCCToast('Error: ' + (res.message || 'Unknown'), 'err'); return; }

        showCCToast(editingTrId ? 'Transfer updated!' : 'Transfer saved!', 'ok');
        var newTrId = res.transfer_id || editingTrId || Date.now();

        /* 1. If target SR changed during edit, remove old "in" entry from old target */
        if (editingTrId && oldToSr && oldToSr !== toSr) {
            var oldToIdx = findRowIdx(delDate, oldToSr);
            if (oldToIdx >= 0) {
                ROWS_DATA[oldToIdx].t_rows = (ROWS_DATA[oldToIdx].t_rows || []).filter(function (x) { return x.id !== editingTrId; });
                recomputeTotalsAndApply(oldToIdx);
            }
        }

        /* 2. Update SENDER */
        if (res.from_row_data || res.row_data) {
            applyTransferDataToRow(currentTrIdx, res.from_row_data || res.row_data);
        } else {
            if (editingTrId) rd.t_rows = (rd.t_rows || []).filter(function (x) { return x.id !== editingTrId; });
            rd.t_rows = (rd.t_rows || []).concat([{
                id: newTrId, direction: 'out', other_sr: toSr,
                delivery_date: delDate, transfer_date: trDate,
                amount: amount, note: note,
                employee_id: employeeId, employee_name: employeeName
            }]);
            recomputeTotalsAndApply(currentTrIdx);
        }

        /* 3. Update RECEIVER */
        var toIdx = findRowIdx(delDate, toSr);
        if (toIdx >= 0) {
            if (res.to_row_data) {
                applyTransferDataToRow(toIdx, res.to_row_data);
            } else {
                var toRd = ROWS_DATA[toIdx];
                if (editingTrId) toRd.t_rows = (toRd.t_rows || []).filter(function (x) { return x.id !== editingTrId; });
                toRd.t_rows = (toRd.t_rows || []).concat([{
                    id: newTrId, direction: 'in', other_sr: rd.sr_code,
                    delivery_date: delDate, transfer_date: trDate,
                    amount: amount, note: note,
                    employee_id: employeeId, employee_name: employeeName
                }]);
                recomputeTotalsAndApply(toIdx);
            }
        }

        /* 4. Reset form */
        editingTrId = null;
        document.getElementById('tr_form_amount').value = '';
        document.getElementById('tr_form_note').value   = '';
        $('#tr_form_to_sr').val('').trigger('change');
        $('#tr_form_employee').val('').trigger('change');
        document.getElementById('trFormTitle').textContent = 'New Transfer';
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Save Transfer';

        /* Refresh modal header & history */
        document.getElementById('tr_se_val').innerHTML = fmtSE(rd.bank_diff);
        renderTransferHistory(ROWS_DATA[currentTrIdx].t_rows || []);
        refreshAllTotals();
    })
    .catch(function (err) {
        btn.disabled  = false;
        btn.innerHTML = editingTrId
            ? '<i class="fa-solid fa-floppy-disk"></i> Update Transfer'
            : '<i class="fa-solid fa-paper-plane"></i> Save Transfer';
        showCCToast('Network error: ' + err.message, 'err');
    });
}

function deleteTransfer(trId) {
    if (!confirm('Delete this transfer?')) return;
    var fromIdx = currentTrIdx;
    var fromRd  = ROWS_DATA[fromIdx];
    var trEntry = (fromRd.t_rows || []).find(function (x) { return x.id === trId; });

    fetch('save_cash_transfer.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ action: 'delete', id: trId })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (!res.success) { showCCToast('Error: ' + (res.message || 'Unknown'), 'err'); return; }
        showCCToast('Transfer deleted.', 'ok');

        /* Update sender */
        if (res.from_row_data || res.row_data) {
            applyTransferDataToRow(fromIdx, res.from_row_data || res.row_data);
        } else {
            fromRd.t_rows = (fromRd.t_rows || []).filter(function (x) { return x.id !== trId; });
            recomputeTotalsAndApply(fromIdx);
        }

        /* Update receiver */
        if (trEntry) {
            var toIdx2 = findRowIdx(trEntry.delivery_date, trEntry.other_sr);
            if (toIdx2 >= 0) {
                if (res.to_row_data) {
                    applyTransferDataToRow(toIdx2, res.to_row_data);
                } else {
                    ROWS_DATA[toIdx2].t_rows = (ROWS_DATA[toIdx2].t_rows || []).filter(function (x) { return x.id !== trId; });
                    recomputeTotalsAndApply(toIdx2);
                }
            }
        }

        document.getElementById('tr_se_val').innerHTML = fmtSE(fromRd.bank_diff);
        renderTransferHistory(ROWS_DATA[currentTrIdx].t_rows || []);
        refreshAllTotals();
    })
    .catch(function (err) { showCCToast('Network error: ' + err.message, 'err'); });
}
</script>
<?php include 'footer.php';?>