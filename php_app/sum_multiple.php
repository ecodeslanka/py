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
$raw_rows = [];
$totals = array_fill_keys([
    'sinv',
    'cash_paid','cheque_paid','credit','pay_diff',
    'cc_daily_sale','cc_rcvd_credit','cc_rcvd_rtn_chq','cc_rcvd_rtn_chgs','cc_rcvd_sent_back','cc_total',
    'sr_daily_sale','sr_rcvd_credit','sr_rcvd_rtn_chq','sr_rcvd_rtn_chgs','sr_rcvd_sent_back','sr_total',
    'total_coll',
    'banked','handed',
    'banked_sr','banked_cc',
    'handed_sr','handed_cc',
    'variance_cc','variance_sr',
    'short_cc','short_sr',
    'bank_diff','cash_short',
    'p_charge','p_absorb'
], 0.0);

if ($submitted) {
    $where_fs = "fs.delivery_date BETWEEN '$df' AND '$dt'" . ($sr_esc ? " AND fs.sr_code='$sr_esc'" : '');
    $sid_w    = "sid.delivery_date BETWEEN '$df' AND '$dt' AND sid.status='imported'" . ($sr_esc ? " AND sid.sales_person_code='$sr_esc'" : '');
    $srCnd    = $sr_esc ? "AND fs.sr_code='$sr_esc'" : '';

    $r1=mysqli_query($conn,"SELECT DISTINCT fs.sr_code, fs.delivery_date FROM field_summary fs WHERE $where_fs AND fs.sr_code IS NOT NULL AND fs.sr_code!='' ORDER BY fs.delivery_date, fs.sr_code");
    $master=[];
    if($r1) while($r=mysqli_fetch_assoc($r1)) $master[]=$r['delivery_date'].'|'.$r['sr_code'];

    $r3=mysqli_query($conn,"SELECT sid.sales_person_code AS sr_code, sid.delivery_date, COALESCE(SUM(sid.final_bill_amount),0) AS sinv FROM secondary_invoice_import_details sid WHERE $sid_w GROUP BY sid.sales_person_code, sid.delivery_date");
    $sinv_map=[];
    if($r3) while($r=mysqli_fetch_assoc($r3)) $sinv_map[$r['delivery_date'].'|'.$r['sr_code']]=floatval($r['sinv']);

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

    $dep_date_cnd = "d.delivery_date BETWEEN '$df' AND '$dt'";
    $dep_rep_cnd  = $sr_esc ? " AND r.rep_code='$sr_esc'" : '';

    $r7 = mysqli_query($conn, "
        SELECT d.delivery_date, r.rep_code,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=0 AND LOWER(COALESCE(d.collected_by,''))='sr' THEN r.amount ELSE 0 END),0) AS banked_sr,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=0 AND LOWER(COALESCE(d.collected_by,''))='cc' THEN r.amount ELSE 0 END),0) AS banked_cc,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=1 AND LOWER(COALESCE(d.collected_by,''))='sr' THEN r.amount ELSE 0 END),0) AS handed_sr,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=1 AND LOWER(COALESCE(d.collected_by,''))='cc' THEN r.amount ELSE 0 END),0) AS handed_cc
        FROM cc_cash_deposits d
        INNER JOIN cc_cash_deposit_reps r ON r.deposit_id = d.id
        WHERE $dep_date_cnd $dep_rep_cnd
        GROUP BY d.delivery_date, r.rep_code
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

    // Build per-date-sr raw data first
    $per_date_sr = [];
    foreach($master as $k){
        [$del_date,$sr_code]=explode('|',$k,2);
        $pm=$invpay_map[$k]??[];
        $dm=$dep_map[$k]??[]; $cm=$cash_map[$del_date.'|'.$sr_code]??[];

        $sinv=floatval($sinv_map[$k]??0);
        $cash_paid=floatval($pm['cash_paid']??0); $cheque_paid=floatval($pm['cheque_paid']??0);
        $credit=floatval($credit_map[$k]??0);
        $pay_diff=$sinv-($cash_paid+$cheque_paid+$credit);

        $cc_daily_sale=floatval($cm['cc_daily_sale']??0); $cc_rcvd_credit=floatval($cm['cc_rcvd_credit']??0);
        $cc_rcvd_rtn_chq=floatval($cm['cc_rcvd_rtn_chq']??0); $cc_rcvd_rtn_chgs=floatval($cm['cc_rcvd_rtn_chgs']??0);
        $cc_rcvd_sent_back=floatval($cm['cc_rcvd_sent_back']??0); $cc_total=floatval($cm['cc_total']??0);
        $sr_daily_sale=floatval($cm['sr_daily_sale']??0); $sr_rcvd_credit=floatval($cm['sr_rcvd_credit']??0);
        $sr_rcvd_rtn_chq=floatval($cm['sr_rcvd_rtn_chq']??0); $sr_rcvd_rtn_chgs=floatval($cm['sr_rcvd_rtn_chgs']??0);
        $sr_rcvd_sent_back=floatval($cm['sr_rcvd_sent_back']??0); $sr_total=floatval($cm['sr_total']??0);
        $total_coll=floatval($cm['total_coll']??0);

        $banked_cc=floatval($dm['banked_cc']??0); $banked_sr=floatval($dm['banked_sr']??0);
        $handed_cc=floatval($dm['handed_cc']??0); $handed_sr=floatval($dm['handed_sr']??0);
        $banked=$banked_cc+$banked_sr; $handed=$handed_cc+$handed_sr;

        $pa=$alloc_map[$k]??[];
        $p_charge=floatval($pa['p_charge']??0); $p_absorb=floatval($pa['p_absorb']??0);

        $per_date_sr[$sr_code][] = compact(
            'sinv','cash_paid','cheque_paid','credit','pay_diff',
            'cc_daily_sale','cc_rcvd_credit','cc_rcvd_rtn_chq','cc_rcvd_rtn_chgs','cc_rcvd_sent_back','cc_total',
            'sr_daily_sale','sr_rcvd_credit','sr_rcvd_rtn_chq','sr_rcvd_rtn_chgs','sr_rcvd_sent_back','sr_total',
            'total_coll','banked','handed',
            'banked_cc','banked_sr','handed_cc','handed_sr',
            'p_charge','p_absorb'
        );
    }

    // Group/aggregate by sr_code
    $grouped = [];
    foreach($per_date_sr as $sr_code => $days){
        $g = array_fill_keys([
            'sinv','cash_paid','cheque_paid','credit','pay_diff',
            'cc_daily_sale','cc_rcvd_credit','cc_rcvd_rtn_chq','cc_rcvd_rtn_chgs','cc_rcvd_sent_back','cc_total',
            'sr_daily_sale','sr_rcvd_credit','sr_rcvd_rtn_chq','sr_rcvd_rtn_chgs','sr_rcvd_sent_back','sr_total',
            'total_coll','banked','handed',
            'banked_cc','banked_sr','handed_cc','handed_sr',
            'p_charge','p_absorb'
        ], 0.0);
        foreach($days as $d){
            foreach(array_keys($g) as $fk) $g[$fk] += floatval($d[$fk]??0);
        }
        $variance_cc = $g['cc_total'] - ($g['banked_cc'] + $g['handed_cc']);
        $variance_sr = $g['sr_total'] - ($g['banked_sr'] + $g['handed_sr']);
        $short_cc    = $variance_cc > 0 ? $variance_cc : 0;
        $short_sr    = $variance_sr > 0 ? $variance_sr : 0;
        $bank_diff   = $g['total_coll'] - ($g['banked'] + $g['handed']);
        $cash_short  = $bank_diff > 0 ? $bank_diff : 0;

        // Aggregate alloc across all dates for this sr
        $p_charge_total = 0; $p_absorb_total = 0; $is_paid = false;
        foreach(array_keys($alloc_map) as $ak){
            if(strpos($ak, '|'.$sr_code) !== false){
                $p_charge_total += floatval($alloc_map[$ak]['p_charge']??0);
                $p_absorb_total += floatval($alloc_map[$ak]['p_absorb']??0);
                if(!empty($alloc_map[$ak]['is_paid'])) $is_paid = true;
            }
        }
        $p_variance = $cash_short > 0 ? ($cash_short - $p_charge_total - $p_absorb_total) : null;

        $grouped[] = array_merge($g, [
            'sr_code'    => $sr_code,
            'variance_cc'=> $variance_cc,
            'variance_sr'=> $variance_sr,
            'short_cc'   => $short_cc,
            'short_sr'   => $short_sr,
            'bank_diff'  => $bank_diff,
            'cash_short' => $cash_short,
            'p_charge'   => $p_charge_total,
            'p_absorb'   => $p_absorb_total,
            'p_variance' => $p_variance,
            'p_employees'=> [],
            'is_paid'    => $is_paid,
        ]);
    }

    $rows = $grouped;
    foreach(array_keys($totals) as $tk){
        foreach($rows as $r){ if(isset($r[$tk])) $totals[$tk]+=floatval($r[$tk]); }
    }
}

function cc_v($v){$n=floatval($v);return $n==0?'<span class="dash">—</span>':'<span class="num">'.number_format($n,2).'</span>';}
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
    if(abs($v)<0.005) return '<span class="pv-ok">0.00</span>';
    if($v<0) return '<span class="pv-exc-pay">'.number_format($v,2).'</span>';
    return '<span class="pv-sht-pay">+'.number_format($v,2).'</span>';
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
// Grouped report: links point to date-range for this SR
function cc_pay_link_g($v,$sr,$df,$dt,$method){
    $n=floatval($v);
    if($n==0)return '<span class="dash">—</span>';
    $url='payment_details.php?sr_code='.urlencode($sr).'&date_from='.urlencode($df).'&date_to='.urlencode($dt).'&method='.urlencode($method).'&source=invoice';
    $cls=$method==='cheque'?'pay-link cheque':'pay-link cash';
    return '<a href="'.$url.'" target="_blank" class="'.$cls.'"><span class="num">'.number_format($n,2).'</span> <i class="fa-solid fa-arrow-up-right-from-square pay-link-ico"></i></a>';
}
function cc_clink_g($v,$sr,$df,$dt,$coll,$src=''){
    $n=floatval($v);
    if($n==0)return '<span class="dash">—</span>';
    $p=['sr_code'=>$sr,'date_from'=>$df,'date_to'=>$dt,'method'=>'cash','collected_by'=>$coll];
    if($src!=='')$p['source']=$src;
    $url='payment_details.php?'.http_build_query($p);
    $cls=$coll==='cc'?'clink cc':'clink sr';
    return '<a href="'.$url.'" target="_blank" class="'.$cls.'"><span class="num">'.number_format($n,2).'</span><i class="fa-solid fa-arrow-up-right-from-square clink-ico"></i></a>';
}
function cc_dep_link_g($v,$sr,$df,$dt,$type,$collected_by=''){
    $n=floatval($v);
    if($n==0)return '<span class="dash">—</span>';
    $params=['sr_code'=>$sr,'date_from'=>$df,'date_to'=>$dt,'type'=>$type];
    if($collected_by!=='')$params['collected_by']=$collected_by;
    $url='cc_deposit_history.php?'.http_build_query($params);
    $cls=$type==='bank'?'dep-link bank':'dep-link bo';
    return '<a href="'.$url.'" target="_blank" class="'.$cls.'"><span class="num">'.number_format($n,2).'</span><i class="fa-solid fa-clock-rotate-left dep-link-ico"></i></a>';
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

/* ═══════ TABLE ═══════ */
table.cct{width:100%;border-collapse:collapse;font-size:11px;}
.cct .G th{padding:4px 4px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#fff;text-align:center;white-space:nowrap;border-right:2px solid rgba(255,255,255,.18);}
.cct .G th.tl{text-align:left;}.cct .G th:last-child{border-right:none;}
.cct .S th{padding:3px 4px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:rgba(255,255,255,.88);text-align:center;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);border-bottom:2px solid var(--bdr);}
.cct .S th.tl{text-align:left;}.cct .S th:last-child{border-right:none;}

.G .h0{background:var(--g0);}.G .h1{background:var(--g1);}.G .h2{background:var(--g2);}.G .h6{background:#5b21b6;}
.G .hcc{background:#1a6640;}.G .hsr{background:#0f766e;}.G .htc{background:#374151;}
.G .hdep{background:#1e4d8c;}.G .hvar{background:#7c2d12;}
.S .s0{background:var(--g0s);}.S .s1{background:var(--g1s);}.S .s2{background:var(--g2s);}.S .s6{background:#4c1d95;}
.S .scc{background:#155535;}.S .ssr{background:#0d6462;}.S .stc{background:#2d3748;}
.S .sdep_cc{background:#14532d;}.S .sdep_sr{background:#1a5218;}
.S .sbo_cc{background:#5b21b6;}.S .sbo_sr{background:#3b1578;}
.S .svar_cc{background:#7c2d12;}.S .svar_sr{background:#6b2508;}
.S .ssht_cc{background:#7f1d1d;}.S .ssht_sr{background:#6f1a1a;}
.S .stvar{background:#2d3748;}.S .stsht{background:#1c1917;}

.cct .TH td{background:#e0edff;color:#1e3a8a;font-weight:800;font-size:10.5px;padding:4px 4px;text-align:right;border-bottom:2px solid #93c5fd;white-space:nowrap;font-family:var(--mn);}
.cct .TH td.tl{text-align:left;font-family:var(--fn);}
.cct tbody tr td{background:#fff;}
.cct tbody tr.stripe td{background:#fafbfc;}
.cct tbody tr.row-hover td{background:#eff6ff!important;}
.cct tbody td{padding:3px 4px;text-align:right;white-space:nowrap;}
.cct tbody td.tl{text-align:left;}.cct tbody td.tc{text-align:center;}
.cct tbody td.rc{font-weight:700;font-size:11px;}
.cct tbody td.fb{font-weight:700;color:#1e40af;}.cct tbody td.tv{font-weight:700;color:#166534;}
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
.cct tfoot td{padding:4px 4px;font-weight:800;font-size:11px;background:var(--total-bg);color:#e2e8f0;border-top:2px solid #334155;text-align:right;white-space:nowrap;font-family:var(--mn);}
.cct tfoot td.tl{text-align:left;color:#94a3b8;font-family:var(--fn);}

.num{font-family:var(--mn);font-size:10.5px;}.dash{color:#d1d5db;}
.d-ok{color:#16a34a;font-weight:700;font-family:var(--mn);font-size:10px;}
.d-exc{color:#16a34a;font-weight:700;font-family:var(--mn);font-size:10px;}
.d-sht{color:#dc2626;font-weight:700;font-family:var(--mn);font-size:10px;}
.sv{color:#dc2626;font-weight:700;font-family:var(--mn);font-size:10.5px;}
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
.btn-pay-alloc{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border:1px solid #ddd6fe;border-radius:5px;background:#f5f3ff;color:#7c3aed;font-size:10px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s;white-space:nowrap;}
.btn-pay-alloc:hover{background:#7c3aed;color:#fff;}.btn-pay-alloc.paid{background:#f0fdf4;color:#16a34a;border-color:#86efac;}
.cct td.stk,.cct th.stk{position:sticky;left:0;z-index:2;box-shadow:3px 0 8px rgba(0,0,0,.10);}
.cct thead th.stk{z-index:4;}
.cct .G th.stk{background:var(--g0);}.cct .S th.stk{background:var(--g0s);}
.cct .TH td.stk{background:#e0edff;}.cct tfoot td.stk{background:var(--total-bg);}
.cct tbody tr td.stk{background:#fff;}.cct tbody tr.stripe td.stk{background:#fafbfc;}
.cct tbody tr.row-hover td.stk{background:#eff6ff!important;}

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
  .cct tbody td.col-c,.cct tbody td.col-s,.cct tbody td.col-dep-cc,.cct tbody td.col-dep-sr,
  .cct tbody td.col-bo-cc,.cct tbody td.col-bo-sr,.cct tbody td.col-var-cc,.cct tbody td.col-var-sr,
  .cct tbody td.col-sht-cc,.cct tbody td.col-sht-sr,.cct tbody td.col-tvar,.cct tbody td.col-tsht,
  .cct tbody td.col-p,.cct tbody tr.stripe td{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .sc-row{grid-template-columns:repeat(5,1fr);gap:4px;margin-bottom:6px;}
  .sc{padding:5px 7px;box-shadow:none;}
  a.pay-link,a.clink,a.dep-link{text-decoration:none;color:inherit;}
  a.pay-link .pay-link-ico,a.clink .clink-ico,a.dep-link .dep-link-ico{display:none;}
  .btn-pay-alloc{display:none;}
  .cct td.stk,.cct th.stk{position:static;box-shadow:none;}
  .top-scroll-wrap{display:none;}
}

/* Modal */
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
.pmo-info-lbl{font-size:9px;color:#9ca3af;text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:3px;}
.pmo-info-val{font-size:14px;font-weight:700;color:#1f2937;font-family:'JetBrains Mono',monospace;}
.pmo-info-cell.hi .pmo-info-val{color:#dc2626;font-size:18px;}
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
#ccToast{position:fixed;bottom:20px;right:20px;padding:9px 16px;border-radius:8px;font-size:12px;font-weight:600;z-index:9999;display:none;opacity:0;transition:opacity .3s;}
.toast-ok{background:#dcfce7;color:#166534;border:1px solid #86efac;}.toast-err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.empty{text-align:center;padding:50px 20px;color:var(--txs);}.empty i{font-size:38px;display:block;margin-bottom:10px;opacity:.25;}
@media(max-width:900px){.sc-row{grid-template-columns:1fr 1fr;}}
@media(max-width:600px){.pmo-info{grid-template-columns:1fr 1fr;}.pmo-totals{grid-template-columns:1fr;gap:5px;}.absorb-row{flex-direction:column;align-items:stretch;}.absorb-inp{width:100%;}.emp-row .emp-amt{width:100%;}.pmo-ftr{flex-direction:column-reverse;}.pmo-btn{justify-content:center;}.pay-modal-box{border-radius:10px;}.pmo-header,.pmo-body,.pmo-ftr,.pmo-info{padding-left:12px;padding-right:12px;}}
@media(max-width:400px){.pmo-info{grid-template-columns:1fr;}}
</style>

<div class="pg">

<div class="print-header">
    <h2>Collection Report (Multiple)</h2>
    <p><?php echo date('d M Y',strtotime($date_from)).' — '.date('d M Y',strtotime($date_to));
       echo $f_sr ? ' &nbsp;·&nbsp; Rep: '.htmlspecialchars($f_sr) : ' &nbsp;·&nbsp; All Reps';
       echo ' &nbsp;·&nbsp; Generated: '.date('d M Y H:i');?></p>
</div>

<div class="topbar no-print">
    <div>
        <div class="pg-h1">Collection Report <em>(Multiple)</em></div>
        <div class="pg-sub">Grouped by Rep Code · Sec. Invoice · Invoice Payments · Day Cash Collection (CC &amp; SR) · Deposits · Variance &amp; Shortage</div>
    </div>
    <div style="display:flex;gap:7px;align-items:center;">
        <div class="dpill"><i class="fa-solid fa-calendar-days"></i> <?php echo date('d M Y',strtotime($date_from)).' — '.date('d M Y',strtotime($date_to));?></div>
        <?php if($submitted&&!empty($rows)):?>
        <button onclick="exportToExcel()" class="btn-excel"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
        <button onclick="window.print()" class="btn-rst"><i class="fa-solid fa-print"></i> Print</button>
        <?php endif;?>
    </div>
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
        <a href="cash_collection_multiple.php" class="btn-rst" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
    </form>
</div>

<?php if(!$submitted):?>
<div class="tc"><div class="empty"><i class="fa-solid fa-money-bill-wave"></i>
    <p style="font-size:14px;font-weight:600;margin-bottom:5px;">Collection Report (Multiple)</p>
    <p>Choose a date range and click <strong>Generate</strong>. Results will be grouped by Rep Code.</p>
</div></div>
<?php elseif(empty($rows)):?>
<div class="tc"><div class="empty"><i class="fa-solid fa-inbox"></i>
    <p style="font-size:14px;font-weight:600;margin-bottom:5px;">No records found</p>
    <p><?php echo date('d M Y',strtotime($date_from)).' – '.date('d M Y',strtotime($date_to)); echo $f_sr?' &nbsp;·&nbsp; Rep: <strong>'.htmlspecialchars($f_sr).'</strong>':'';?></p>
</div></div>
<?php else:?>
<div class="sc-row no-print">
    <div class="sc blue"><div class="sc-lbl"><i class="fa-solid fa-file-invoice-dollar"></i> Sec. Invoice Value</div><div class="sc-val" style="color:#1e40af;">Rs. <?php echo number_format($totals['sinv'],2);?></div></div>
    <div class="sc green"><div class="sc-lbl"><i class="fa-solid fa-user-tie"></i> CC Collection</div><div class="sc-val" style="color:#166534;">Rs. <?php echo number_format($totals['cc_total'],2);?></div></div>
    <div class="sc teal"><div class="sc-lbl"><i class="fa-solid fa-person-walking"></i> SR Collection</div><div class="sc-val" style="color:#0f766e;">Rs. <?php echo number_format($totals['sr_total'],2);?></div></div>
    <div class="sc green"><div class="sc-lbl"><i class="fa-solid fa-building-columns"></i> Total Deposited</div><div class="sc-val" style="color:#166534;">Rs. <?php echo number_format($totals['banked']+$totals['handed'],2);?></div></div>
    <?php $tot_sh=$totals['cash_short'];?>
    <div class="sc <?php echo $tot_sh>0?'red':'green';?>"><div class="sc-lbl"><i class="fa-solid fa-triangle-exclamation"></i> Total Shortage</div><div class="sc-val" style="color:<?php echo $tot_sh>0?'#991b1b':'#166534';?>;">Rs. <?php echo number_format($tot_sh,2);?></div></div>
</div>

<div class="tc">
    <div class="tc-bar no-print">
        <div class="tc-ttl"><i class="fa-solid fa-table-cells-large"></i> Collection Report (Multiple) — Grouped by Rep
            <span class="pill p-green"><?php echo count($rows);?> reps</span>
            <span class="pill p-blue"><?php echo date('d M Y',strtotime($date_from)).' – '.date('d M Y',strtotime($date_to));?></span>
            <?php if($f_sr):?><span class="pill p-slate">Rep: <?php echo htmlspecialchars($f_sr);?></span><?php endif;?>
            <span style="font-size:10px;font-weight:400;color:var(--txs);">·
                <span style="color:#16a34a;font-weight:600;">▲ Excess = Green</span> &nbsp;
                <span style="color:#dc2626;font-weight:600;">▼ Short = Red</span>
            </span>
        </div>
    </div>
    <div class="top-scroll-wrap no-print" id="topScroll"><div class="top-scroll-inner" id="topScrollInner"></div></div>
    <div class="tscroll" id="mainScroll">
    <table class="cct" id="cctMain">
      <thead>
        <tr class="G">
            <th class="h0 tl stk" rowspan="2" style="min-width:100px;">Rep Code</th>
            <th class="h1"       colspan="1">Daily Invoice Details</th>
            <th class="h2"       colspan="4">Invoice Payments</th>
            <th class="hcc"      colspan="6"><i class="fa-solid fa-user-tie"></i> CC Collection</th>
            <th class="hsr"      colspan="6"><i class="fa-solid fa-person-walking"></i> SR Collection</th>
            <th class="htc"      colspan="1">Total</th>
            <th class="hdep"     colspan="4">Cash Deposit</th>
            <th class="hvar"     colspan="6">Variance &amp; Shortage</th>
            <th class="h6"       colspan="3">Pay Allocation</th>
            <th class="h6"       colspan="1">Action</th>
        </tr>
        <tr class="S">
            <th class="s1"      style="min-width:105px;">Sec. Invoice</th>
            <th class="s2"      style="min-width:88px;">Cash Paid</th>
            <th class="s2"      style="min-width:88px;">Cheque Paid</th>
            <th class="s2"      style="min-width:78px;">Credit</th>
            <th class="s2"      style="min-width:58px;">Diff</th>
            <th class="scc"     style="min-width:88px;">Daily Sale</th>
            <th class="scc"     style="min-width:88px;">Rcvd Credit</th>
            <th class="scc"     style="min-width:80px;">Rtn Cheque</th>
            <th class="scc"     style="min-width:78px;">Rtn Chgs</th>
            <th class="scc"     style="min-width:78px;">Sent Back</th>
            <th class="scc"     style="min-width:85px;">CC Total</th>
            <th class="ssr"     style="min-width:88px;">Daily Sale</th>
            <th class="ssr"     style="min-width:88px;">Rcvd Credit</th>
            <th class="ssr"     style="min-width:80px;">Rtn Cheque</th>
            <th class="ssr"     style="min-width:78px;">Rtn Chgs</th>
            <th class="ssr"     style="min-width:78px;">Sent Back</th>
            <th class="ssr"     style="min-width:85px;">SR Total</th>
            <th class="stc"     style="min-width:92px;">Grand Total</th>
            <th class="sdep_cc" style="min-width:92px;" title="Bank deposit — collected by CC">Dep. CC</th>
            <th class="sdep_sr" style="min-width:92px;" title="Bank deposit — collected by SR">Dep. SR</th>
            <th class="sbo_cc"  style="min-width:92px;" title="Handed to BO — collected by CC">BO CC</th>
            <th class="sbo_sr"  style="min-width:92px;" title="Handed to BO — collected by SR">BO SR</th>
            <th class="svar_cc" style="min-width:80px;" title="CC total − (Dep CC + BO CC)">Var. CC</th>
            <th class="svar_sr" style="min-width:80px;" title="SR total − (Dep SR + BO SR)">Var. SR</th>
            <th class="ssht_cc" style="min-width:78px;">Short CC</th>
            <th class="ssht_sr" style="min-width:78px;">Short SR</th>
            <th class="stvar"   style="min-width:78px;">Total Var.</th>
            <th class="stsht"   style="min-width:78px;">Total Short</th>
            <th class="s6"      style="min-width:90px;">Charge to Emp</th>
            <th class="s6"      style="min-width:90px;">Absorb by Co.</th>
            <th class="s6"      style="min-width:80px;">Final Variance</th>
            <th class="s6"      style="min-width:62px;text-align:center;">Action</th>
        </tr>
        <tr class="TH">
            <td class="tl stk"><i class="fa-solid fa-sigma"></i>&nbsp; Total (<?php echo count($rows);?> reps)</td>
            <td><?php echo cc_t($totals['sinv']);?></td>
            <td><?php echo cc_t($totals['cash_paid']);?></td>
            <td><?php echo cc_t($totals['cheque_paid']);?></td>
            <td><?php echo cc_t($totals['credit']);?></td>
            <td><?php echo cc_d($totals['pay_diff']);?></td>
            <td style="color:#86efac;"><?php echo cc_t($totals['cc_daily_sale']);?></td>
            <td style="color:#86efac;"><?php echo cc_t($totals['cc_rcvd_credit']);?></td>
            <td style="color:#86efac;"><?php echo cc_t($totals['cc_rcvd_rtn_chq']);?></td>
            <td style="color:#86efac;"><?php echo cc_t($totals['cc_rcvd_rtn_chgs']);?></td>
            <td style="color:#86efac;"><?php echo cc_t($totals['cc_rcvd_sent_back']);?></td>
            <td style="color:#86efac;"><?php echo cc_t($totals['cc_total']);?></td>
            <td style="color:#5eead4;"><?php echo cc_t($totals['sr_daily_sale']);?></td>
            <td style="color:#5eead4;"><?php echo cc_t($totals['sr_rcvd_credit']);?></td>
            <td style="color:#5eead4;"><?php echo cc_t($totals['sr_rcvd_rtn_chq']);?></td>
            <td style="color:#5eead4;"><?php echo cc_t($totals['sr_rcvd_rtn_chgs']);?></td>
            <td style="color:#5eead4;"><?php echo cc_t($totals['sr_rcvd_sent_back']);?></td>
            <td style="color:#5eead4;"><?php echo cc_t($totals['sr_total']);?></td>
            <td><?php echo cc_t($totals['total_coll']);?></td>
            <td><?php echo cc_t($totals['banked_cc']);?></td>
            <td><?php echo cc_t($totals['banked_sr']);?></td>
            <td><?php echo cc_t($totals['handed_cc']);?></td>
            <td><?php echo cc_t($totals['handed_sr']);?></td>
            <td><?php echo cc_d($totals['variance_cc']);?></td>
            <td><?php echo cc_d($totals['variance_sr']);?></td>
            <td><?php $tsc=$totals['short_cc'];echo $tsc>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($tsc,2).'</span>':'—';?></td>
            <td><?php $tss=$totals['short_sr'];echo $tss>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($tss,2).'</span>':'—';?></td>
            <?php $tv_tot=$totals['total_coll']-$totals['banked']-$totals['handed'];?>
            <td><?php echo cc_d($tv_tot);?></td>
            <td><?php echo $totals['cash_short']>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($totals['cash_short'],2).'</span>':'—';?></td>
            <td id="ft-charge"><?php echo cc_t($totals['p_charge']);?></td>
            <td id="ft-absorb"><?php echo cc_t($totals['p_absorb']);?></td>
            <td></td><td></td>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $i=>$r):$stripe=($i%2!==0)?'stripe':'';?>
      <tr class="<?php echo $stripe;?>">
          <td class="tl rc stk"><?php echo htmlspecialchars($r['sr_code']);?></td>
          <td class="fb"><?php echo cc_v($r['sinv']);?></td>
          <td><?php echo cc_pay_link_g($r['cash_paid'],$r['sr_code'],$df,$dt,'cash');?></td>
          <td><?php echo cc_pay_link_g($r['cheque_paid'],$r['sr_code'],$df,$dt,'cheque');?></td>
          <td><?php echo cc_v($r['credit']);?></td>
          <td><?php echo cc_d($r['pay_diff']);?></td>
          <td class="col-c"><?php echo cc_clink_g($r['cc_daily_sale'],    $r['sr_code'],$df,$dt,'cc','invoice');?></td>
          <td class="col-c"><?php echo cc_clink_g($r['cc_rcvd_credit'],   $r['sr_code'],$df,$dt,'cc','credit');?></td>
          <td class="col-c"><?php echo cc_clink_g($r['cc_rcvd_rtn_chq'],  $r['sr_code'],$df,$dt,'cc','rtn_chq');?></td>
          <td class="col-c"><?php echo cc_clink_g($r['cc_rcvd_rtn_chgs'], $r['sr_code'],$df,$dt,'cc','rtn_chgs');?></td>
          <td class="col-c"><?php echo cc_clink_g($r['cc_rcvd_sent_back'],$r['sr_code'],$df,$dt,'cc','sent_back');?></td>
          <td class="col-c tv"><?php echo cc_clink_g($r['cc_total'],$r['sr_code'],$df,$dt,'cc');?></td>
          <td class="col-s"><?php echo cc_clink_g($r['sr_daily_sale'],    $r['sr_code'],$df,$dt,'sr','invoice');?></td>
          <td class="col-s"><?php echo cc_clink_g($r['sr_rcvd_credit'],   $r['sr_code'],$df,$dt,'sr','credit');?></td>
          <td class="col-s"><?php echo cc_clink_g($r['sr_rcvd_rtn_chq'],  $r['sr_code'],$df,$dt,'sr','rtn_chq');?></td>
          <td class="col-s"><?php echo cc_clink_g($r['sr_rcvd_rtn_chgs'], $r['sr_code'],$df,$dt,'sr','rtn_chgs');?></td>
          <td class="col-s"><?php echo cc_clink_g($r['sr_rcvd_sent_back'],$r['sr_code'],$df,$dt,'sr','sent_back');?></td>
          <td class="col-s tv"><?php echo cc_clink_g($r['sr_total'],$r['sr_code'],$df,$dt,'sr');?></td>
          <td style="font-weight:800;color:#0f172a;"><?php echo cc_v($r['total_coll']);?></td>
          <td class="col-dep-cc"><?php echo cc_dep_link_g($r['banked_cc'],$r['sr_code'],$df,$dt,'bank','cc');?></td>
          <td class="col-dep-sr"><?php echo cc_dep_link_g($r['banked_sr'],$r['sr_code'],$df,$dt,'bank','sr');?></td>
          <td class="col-bo-cc"><?php echo cc_dep_link_g($r['handed_cc'],$r['sr_code'],$df,$dt,'bo','cc');?></td>
          <td class="col-bo-sr"><?php echo cc_dep_link_g($r['handed_sr'],$r['sr_code'],$df,$dt,'bo','sr');?></td>
          <td class="col-var-cc"><?php echo cc_var_split($r['variance_cc']);?></td>
          <td class="col-var-sr"><?php echo cc_var_split($r['variance_sr']);?></td>
          <td class="col-sht-cc"><?php echo cc_short_split($r['short_cc']);?></td>
          <td class="col-sht-sr"><?php echo cc_short_split($r['short_sr']);?></td>
          <td class="col-tvar"><?php echo cc_d($r['bank_diff']);?></td>
          <td class="col-tsht"><?php echo $r['cash_short']>0?'<span class="sv">'.number_format($r['cash_short'],2).'</span>':'<span class="dash">—</span>';?></td>
          <td class="col-p" id="prow-charge-<?php echo $i;?>"><?php if($r['p_charge']>0):?><span class="val-charge"><?php echo number_format($r['p_charge'],2);?></span><?php else:?><span class="dash">—</span><?php endif;?></td>
          <td class="col-p" id="prow-absorb-<?php echo $i;?>"><?php if($r['p_absorb']>0):?><span class="val-absorb"><?php echo number_format($r['p_absorb'],2);?></span><?php else:?><span class="dash">—</span><?php endif;?></td>
          <td id="prow-var-<?php echo $i;?>"><?php echo cc_pvar($r['p_variance']);?></td>
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
            <td class="tl stk">TOTAL — <?php echo count($rows);?> reps</td>
            <td><?php echo cc_t($totals['sinv']);?></td>
            <td><?php echo cc_t($totals['cash_paid']);?></td>
            <td><?php echo cc_t($totals['cheque_paid']);?></td>
            <td><?php echo cc_t($totals['credit']);?></td>
            <td><?php echo cc_d($totals['pay_diff']);?></td>
            <td><?php echo cc_t($totals['cc_daily_sale']);?></td>
            <td><?php echo cc_t($totals['cc_rcvd_credit']);?></td>
            <td><?php echo cc_t($totals['cc_rcvd_rtn_chq']);?></td>
            <td><?php echo cc_t($totals['cc_rcvd_rtn_chgs']);?></td>
            <td><?php echo cc_t($totals['cc_rcvd_sent_back']);?></td>
            <td><?php echo cc_t($totals['cc_total']);?></td>
            <td><?php echo cc_t($totals['sr_daily_sale']);?></td>
            <td><?php echo cc_t($totals['sr_rcvd_credit']);?></td>
            <td><?php echo cc_t($totals['sr_rcvd_rtn_chq']);?></td>
            <td><?php echo cc_t($totals['sr_rcvd_rtn_chgs']);?></td>
            <td><?php echo cc_t($totals['sr_rcvd_sent_back']);?></td>
            <td><?php echo cc_t($totals['sr_total']);?></td>
            <td><?php echo cc_t($totals['total_coll']);?></td>
            <td><?php echo cc_t($totals['banked_cc']);?></td>
            <td><?php echo cc_t($totals['banked_sr']);?></td>
            <td><?php echo cc_t($totals['handed_cc']);?></td>
            <td><?php echo cc_t($totals['handed_sr']);?></td>
            <td><?php echo cc_d($totals['variance_cc']);?></td>
            <td><?php echo cc_d($totals['variance_sr']);?></td>
            <td><?php echo $totals['short_cc']>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($totals['short_cc'],2).'</span>':'—';?></td>
            <td><?php echo $totals['short_sr']>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($totals['short_sr'],2).'</span>':'—';?></td>
            <?php $fv=$totals['total_coll']-$totals['banked']-$totals['handed'];?>
            <td><?php echo cc_d($fv);?></td>
            <td><?php echo $totals['cash_short']>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($totals['cash_short'],2).'</span>':'—';?></td>
            <td><?php echo cc_t($totals['p_charge']);?></td>
            <td><?php echo cc_t($totals['p_absorb']);?></td>
            <td></td><td></td>
        </tr>
      </tfoot>
    </table>
    </div>
</div>
<?php endif;?>
</div>

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
      <div class="pmo-info-cell"><div class="pmo-info-lbl">Period</div><div class="pmo-info-val" id="pm_date">—</div></div>
      <div class="pmo-info-cell"><div class="pmo-info-lbl">Total Cash Collected</div><div class="pmo-info-val" id="pm_total">—</div></div>
      <div class="pmo-info-cell hi"><div class="pmo-info-lbl">Cash Short / Excess</div><div class="pmo-info-val" id="pm_seval">—</div></div>
    </div>
    <div class="pmo-body">
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
        <div class="ptb-cell"><div class="ptb-lbl">Short / Excess</div><div class="ptb-val" style="color:#dc2626;" id="pm_ptb_seval">0.00</div></div>
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

<div id="ccToast"></div>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<?php
$rows_js=[];
foreach($rows as $i=>$r){
    $rows_js[]=[
        'idx'=>$i,'sr_code'=>$r['sr_code'],
        'rep_name'=>$names[$r['sr_code']]??'',
        'pay_date'=>$date_from.' to '.$date_to,
        'total'=>floatval($r['total_coll']),'se_val'=>floatval($r['bank_diff']),
        'sinv'=>floatval($r['sinv']),
        'cash_paid'=>floatval($r['cash_paid']),'cheque_paid'=>floatval($r['cheque_paid']),
        'credit'=>floatval($r['credit']),'pay_diff'=>floatval($r['pay_diff']),
        'cc_daily_sale'=>floatval($r['cc_daily_sale']),'cc_rcvd_credit'=>floatval($r['cc_rcvd_credit']),
        'cc_rcvd_rtn_chq'=>floatval($r['cc_rcvd_rtn_chq']),'cc_rcvd_rtn_chgs'=>floatval($r['cc_rcvd_rtn_chgs']),
        'cc_rcvd_sent_back'=>floatval($r['cc_rcvd_sent_back']),'cc_total'=>floatval($r['cc_total']),
        'sr_daily_sale'=>floatval($r['sr_daily_sale']),'sr_rcvd_credit'=>floatval($r['sr_rcvd_credit']),
        'sr_rcvd_rtn_chq'=>floatval($r['sr_rcvd_rtn_chq']),'sr_rcvd_rtn_chgs'=>floatval($r['sr_rcvd_rtn_chgs']),
        'sr_rcvd_sent_back'=>floatval($r['sr_rcvd_sent_back']),'sr_total'=>floatval($r['sr_total']),
        'total_coll'=>floatval($r['total_coll']),
        'banked_cc'=>floatval($r['banked_cc']),'banked_sr'=>floatval($r['banked_sr']),
        'handed_cc'=>floatval($r['handed_cc']),'handed_sr'=>floatval($r['handed_sr']),
        'banked'=>floatval($r['banked']),'handed'=>floatval($r['handed']),
        'variance_cc'=>floatval($r['variance_cc']),'variance_sr'=>floatval($r['variance_sr']),
        'short_cc'=>floatval($r['short_cc']),'short_sr'=>floatval($r['short_sr']),
        'bank_diff'=>floatval($r['bank_diff']),'cash_short'=>floatval($r['cash_short']),
        'p_charge'=>floatval($r['p_charge']),'p_absorb'=>floatval($r['p_absorb']),
        'p_variance'=>$r['p_variance'],'employees'=>$r['p_employees'],'is_paid'=>$r['is_paid']
    ];
}
?>
<script>
var ROWS_DATA=<?php echo json_encode($rows_js);?>;
var payEmpCount=0,currentPayIdx=null;
var EMP_OPTIONS=`<?php foreach($emp_list as $e):?><option value="<?php echo intval($e['id']);?>"><?php echo htmlspecialchars($e['emp_code'].' — '.$e['emp_name']);?></option><?php endforeach;?>`;
var DATE_FROM='<?php echo $date_from;?>',DATE_TO='<?php echo $date_to;?>';

function fmtNum(n){return Math.abs(parseFloat(n||0)).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');}
function fmtPayVar(v){
    if(v===null||v===undefined)return '<span style="color:#d1d5db;">—</span>';
    v=parseFloat(v);
    if(Math.abs(v)<0.005)return '<span class="pv-ok">0.00</span>';
    if(v<0)return '<span class="pv-exc-pay">'+v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',')+'</span>';
    return '<span class="pv-sht-pay">+'+fmtNum(v)+'</span>';
}
function exportToExcel(){
    if(!ROWS_DATA||!ROWS_DATA.length){showCCToast('No data.','err');return;}
    var hdrs=['Rep Code','Rep Name','Sec. Invoice',
              'Cash Paid','Cheque Paid','Credit','Pay Diff',
              'CC Daily Sale','CC Rcvd Credit','CC Rtn Cheque','CC Rtn Chgs','CC Sent Back','CC Total',
              'SR Daily Sale','SR Rcvd Credit','SR Rtn Cheque','SR Rtn Chgs','SR Sent Back','SR Total',
              'Grand Total',
              'Dep. CC','Dep. SR','BO CC','BO SR',
              'Var. CC','Var. SR','Short CC','Short SR','Total Var.','Total Short',
              'Charge','Absorb','Pay Variance'];
    var data=[];
    ROWS_DATA.forEach(function(r){
        data.push([r.sr_code,r.rep_name,r.sinv,
                   r.cash_paid,r.cheque_paid,r.credit,r.pay_diff,
                   r.cc_daily_sale,r.cc_rcvd_credit,r.cc_rcvd_rtn_chq,r.cc_rcvd_rtn_chgs,r.cc_rcvd_sent_back,r.cc_total,
                   r.sr_daily_sale,r.sr_rcvd_credit,r.sr_rcvd_rtn_chq,r.sr_rcvd_rtn_chgs,r.sr_rcvd_sent_back,r.sr_total,
                   r.total_coll,
                   r.banked_cc,r.banked_sr,r.handed_cc,r.handed_sr,
                   r.variance_cc,r.variance_sr,r.short_cc,r.short_sr,r.bank_diff,r.cash_short,
                   r.p_charge,r.p_absorb,r.p_variance??'']);
    });
    var ws=XLSX.utils.aoa_to_sheet([hdrs].concat(data));
    var wb=XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb,ws,'Collection Report (Multiple)');
    var dfrom='<?php echo date("d-M-Y",strtotime($date_from));?>',dto='<?php echo date("d-M-Y",strtotime($date_to));?>';
    XLSX.writeFile(wb,'Collection_Report_Multiple_'+dfrom+'_to_'+dto+'.xlsx');
    showCCToast('Excel exported!','ok');
}
function openPayModal(idx){
    currentPayIdx=idx;payEmpCount=0;
    document.getElementById('pm_chargeRows').innerHTML='';
    document.getElementById('pm_absorb_amt').value='';
    const rd=ROWS_DATA[idx],seAbs=Math.abs(rd.se_val);
    document.getElementById('pm_sub').textContent=rd.sr_code+' · '+DATE_FROM+' to '+DATE_TO;
    document.getElementById('pm_sr').textContent=rd.sr_code;
    document.getElementById('pm_date').textContent=DATE_FROM+' → '+DATE_TO;
    document.getElementById('pm_total').textContent='Rs. '+fmtNum(rd.total);
    document.getElementById('pm_seval').textContent=fmtNum(seAbs)+(rd.se_val<0?' (Excess)':rd.se_val>0?' (Short)':' (Balanced)');
    document.getElementById('pm_ptb_seval').textContent=fmtNum(seAbs);
    document.getElementById('pm_ptb_alloc').textContent='0.00';
    document.getElementById('pm_charge_total').textContent='0.00';
    document.getElementById('pm_absorb_total').textContent='0.00';
    document.getElementById('pm_ptb_var').textContent=fmtNum(seAbs);
    document.getElementById('pm_ptb_var').style.color='#dc2626';
    if(rd.employees&&rd.employees.length)rd.employees.forEach(e=>addPayEmpRow(e.employee_id,e.amount));
    if(rd.p_absorb>0){document.getElementById('pm_absorb_amt').value=rd.p_absorb;recalcPayAlloc();}
    document.getElementById('payAllocModal').classList.add('open');
}
function closePayModal(){document.getElementById('payAllocModal').classList.remove('open');currentPayIdx=null;}
function addPayEmpRow(empId,amt){
    const rid='er_'+(payEmpCount++);
    const div=document.createElement('div');div.className='emp-row';div.id=rid;
    div.innerHTML=`<select class="emp-sel"><option value="">— Select Employee —</option>${EMP_OPTIONS}</select>
        <input type="number" step="0.01" min="0" class="emp-amt" placeholder="0.00" oninput="recalcPayAlloc()">
        <button type="button" class="emp-del" onclick="document.getElementById('${rid}').remove();recalcPayAlloc();" title="Remove">✕</button>`;
    document.getElementById('pm_chargeRows').appendChild(div);
    $(div).find('.emp-sel').select2({
        dropdownParent: $('#payAllocModal'),
        placeholder: '— Select Employee —',
        allowClear: true,
        minimumResultsForSearch: 0,
        width: 'resolve'
    });
    if(empId) $(div).find('.emp-sel').val(empId).trigger('change');
    if(amt)div.querySelector('.emp-amt').value=parseFloat(amt).toFixed(2);
    recalcPayAlloc();
}
function recalcPayAlloc(){
    let tc=0;
    document.querySelectorAll('#pm_chargeRows .emp-row').forEach(row=>{tc+=parseFloat(row.querySelector('.emp-amt').value||0);});
    const absorb=parseFloat(document.getElementById('pm_absorb_amt').value||0);
    const seAbs=currentPayIdx!==null?Math.abs(ROWS_DATA[currentPayIdx].se_val):0;
    const alloc=tc+absorb,vari=seAbs-alloc;
    document.getElementById('pm_charge_total').textContent=fmtNum(tc);
    document.getElementById('pm_absorb_total').textContent=fmtNum(absorb);
    document.getElementById('pm_ptb_alloc').textContent=fmtNum(alloc);
    const vEl=document.getElementById('pm_ptb_var');
    vEl.textContent=(vari>0?'+':'')+parseFloat(vari).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
    vEl.style.color=Math.abs(vari)<0.005?'#16a34a':vari<0?'#d97706':'#dc2626';
}
function savePayAlloc(){
    if(currentPayIdx===null)return;
    const btn=document.getElementById('savePayBtn');
    const charges=[];let valid=true;
    document.querySelectorAll('#pm_chargeRows .emp-row').forEach(row=>{
        const empId=$(row).find('.emp-sel').val();
        const empLbl=$(row).find('.emp-sel option:selected').text()||'';
        const amt=parseFloat(row.querySelector('.emp-amt').value||0);
        if(!empId){showCCToast('Please select an employee.','err');valid=false;return;}
        if(!(amt>0)){showCCToast('Amount must be > 0.','err');valid=false;return;}
        charges.push({employee_id:parseInt(empId),employee_name:empLbl,amount:amt});
    });
    if(!valid)return;
    const rd=ROWS_DATA[currentPayIdx];
    const absorb=parseFloat(document.getElementById('pm_absorb_amt').value||0)||0;
    const seAbs=Math.abs(rd.se_val),tc=charges.reduce((s,r)=>s+r.amount,0),vari=seAbs-(tc+absorb);
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    fetch('save_cash_summary_pay.php',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({pay_date:DATE_FROM,sr_code:rd.sr_code,charges,absorb_amount:absorb,se_value:seAbs,variance:vari})
    }).then(r=>r.json()).then(res=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Allocation';
        if(res.success){
            const idx=currentPayIdx;
            ROWS_DATA[idx].p_charge=tc;ROWS_DATA[idx].p_absorb=absorb;
            ROWS_DATA[idx].p_variance=vari;ROWS_DATA[idx].employees=charges;ROWS_DATA[idx].is_paid=true;
            document.getElementById('prow-charge-'+idx).innerHTML=tc>0?`<span class="val-charge">${fmtNum(tc)}</span>`:`<span class="dash">—</span>`;
            document.getElementById('prow-absorb-'+idx).innerHTML=absorb>0?`<span class="val-absorb">${fmtNum(absorb)}</span>`:`<span class="dash">—</span>`;
            document.getElementById('prow-var-'+idx).innerHTML=fmtPayVar(vari);
            const pb=document.getElementById('payallocbtn-'+idx);
            if(pb){pb.className='btn-pay-alloc paid';pb.innerHTML='<i class="fa-solid fa-check"></i> Paid';}
            let totC=0,totA=0;ROWS_DATA.forEach(r=>{totC+=r.p_charge||0;totA+=r.p_absorb||0;});
            document.getElementById('ft-charge').textContent=fmtNum(totC);
            document.getElementById('ft-absorb').textContent=fmtNum(totA);
            closePayModal();showCCToast('Pay allocation saved!','ok');
        } else showCCToast('Error: '+(res.message||'Unknown'),'err');
    }).catch(err=>{btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Allocation';showCCToast('Network error: '+err.message,'err');});
}
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
</script>
<?php include 'footer.php';?>