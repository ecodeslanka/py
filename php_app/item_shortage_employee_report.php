<?php
/* ══════════════════════════════════════════════════════════════════════════
   AJAX — must be FIRST before any output
══════════════════════════════════════════════════════════════════════════ */
$_ajax = $_POST['action'] ?? $_GET['action'] ?? '';
if ($_ajax !== '') {
    while (ob_get_level() > 0) ob_end_clean();
    error_reporting(0); ini_set('display_errors', 0);
    require_once 'config.php';

    /* auto-create log table */
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `emp_charge_payroll_log` (
        `id`                INT AUTO_INCREMENT PRIMARY KEY,
        `employee_name`     VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
        `payroll_period_id` INT DEFAULT NULL,
        `payroll_year`      INT DEFAULT NULL,
        `payroll_month`     INT DEFAULT NULL,
        `amount`            DECIMAL(12,2) NOT NULL,
        `charge_date`       DATE NOT NULL,
        `description`       VARCHAR(500) COLLATE utf8mb4_unicode_ci DEFAULT 'item shortage deduction',
        `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_emp` (`employee_name`(100)),
        INDEX `idx_period` (`payroll_period_id`)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    header('Content-Type: application/json; charset=utf-8');

    /* GET charges for employee */
    if ($_ajax === 'get_charges') {
        $ename = mysqli_real_escape_string($conn, trim($_GET['employee_name'] ?? ''));
        if (!$ename) { echo json_encode(['charges'=>[],'total'=>0]); exit; }
        $charges = [];
        $qr = mysqli_query($conn,
            "SELECT id,amount,charge_date,description,payroll_year,payroll_month,payroll_period_id
             FROM emp_charge_payroll_log
             WHERE employee_name='$ename'
             ORDER BY charge_date DESC, id DESC");
        if ($qr) while ($r = mysqli_fetch_assoc($qr)) $charges[] = $r;
        $tr    = mysqli_query($conn,"SELECT COALESCE(SUM(amount),0) AS t FROM emp_charge_payroll_log WHERE employee_name='$ename'");
        $total = $tr ? floatval(mysqli_fetch_assoc($tr)['t']) : 0;
        echo json_encode(['charges'=>$charges,'total'=>$total]);
        exit;
    }

    /* POST save charge */
    if ($_ajax === 'save_charge') {
        $ename    = mysqli_real_escape_string($conn, trim($_POST['employee_name']    ?? ''));
        $ppid     = intval($_POST['payroll_period_id'] ?? 0);
        $pp_year  = intval($_POST['payroll_year']      ?? 0);
        $pp_month = intval($_POST['payroll_month']     ?? 0);
        $amount   = floatval($_POST['amount']          ?? 0);
        $amt_sql  = number_format($amount,2,'.',  '');
        $cdate    = mysqli_real_escape_string($conn, $_POST['charge_date'] ?? date('Y-m-d'));
        $desc     = mysqli_real_escape_string($conn, trim($_POST['description'] ?? 'item shortage deduction') ?: 'item shortage deduction');
        if (!$ename || $amount <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid params']); exit; }
        $ppid_v=$ppid?$ppid:'NULL'; $yr_v=$pp_year?$pp_year:'NULL'; $mo_v=$pp_month?$pp_month:'NULL';
        $ok = mysqli_query($conn,
            "INSERT INTO emp_charge_payroll_log (employee_name,payroll_period_id,payroll_year,payroll_month,amount,charge_date,description)
             VALUES ('$ename',$ppid_v,$yr_v,$mo_v,$amt_sql,'$cdate','$desc')");
        if ($ok) {
            $nid = mysqli_insert_id($conn);
            $tr  = mysqli_query($conn,"SELECT COALESCE(SUM(amount),0) AS t FROM emp_charge_payroll_log WHERE employee_name='$ename'");
            $tot = $tr ? floatval(mysqli_fetch_assoc($tr)['t']) : 0;
            echo json_encode(['success'=>true,'charge_id'=>$nid,'new_total'=>$tot]);
        } else { echo json_encode(['success'=>false,'message'=>mysqli_error($conn)]); }
        exit;
    }

    /* POST delete charge */
    if ($_ajax === 'delete_charge') {
        $cid   = intval($_POST['charge_id']      ?? 0);
        $ename = mysqli_real_escape_string($conn, trim($_POST['employee_name'] ?? ''));
        if (!$cid) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
        mysqli_query($conn,"DELETE FROM emp_charge_payroll_log WHERE id=$cid");
        $tr  = mysqli_query($conn,"SELECT COALESCE(SUM(amount),0) AS t FROM emp_charge_payroll_log WHERE employee_name='$ename'");
        $tot = $tr ? floatval(mysqli_fetch_assoc($tr)['t']) : 0;
        echo json_encode(['success'=>true,'new_total'=>$tot]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']);
    exit;
}
/* ══════════════════════════════════════════════════════════════════════════
   PAGE
══════════════════════════════════════════════════════════════════════════ */
ob_start();
include 'config.php';

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `emp_charge_payroll_log` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `employee_name`     VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `payroll_period_id` INT DEFAULT NULL,
    `payroll_year`      INT DEFAULT NULL,
    `payroll_month`     INT DEFAULT NULL,
    `amount`            DECIMAL(12,2) NOT NULL,
    `charge_date`       DATE NOT NULL,
    `description`       VARCHAR(500) COLLATE utf8mb4_unicode_ci DEFAULT 'item shortage deduction',
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_emp` (`employee_name`(100)),
    INDEX `idx_period` (`payroll_period_id`)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

include 'header.php';

/* ── FILTERS ── */
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$f_emp     = trim($_GET['employee'] ?? '');
$df        = mysqli_real_escape_string($conn, $date_from);
$dt        = mysqli_real_escape_string($conn, $date_to);
$e_esc     = $f_emp ? mysqli_real_escape_string($conn, $f_emp) : '';

/*
  KEY CHANGE: Source is unloading_pay_transactions (entry_type='charge').
  employee_name in that table is the ACTUAL charged employee — not the delivery person.
  We join back to unloading_data via import_detail_id to get SKU/shortage details.
*/

/* employees dropdown — from pay transactions */
$all_emps = [];
$eq = mysqli_query($conn,
    "SELECT DISTINCT employee_name FROM unloading_pay_transactions
     WHERE entry_type='charge' AND employee_name IS NOT NULL AND employee_name!=''
     ORDER BY employee_name");
if ($eq) while ($r=mysqli_fetch_assoc($eq)) $all_emps[]=$r['employee_name'];

/* date filter on unloading_data */
$dWhere = "AND (
    (ud.delivery_date IS NOT NULL AND ud.delivery_date BETWEEN '$df' AND '$dt')
    OR (ud.record_date IS NOT NULL AND ud.record_date  BETWEEN '$df' AND '$dt')
    OR (ud.delivery_date IS NULL AND ud.record_date IS NULL)
)";
$eWhere = $e_esc ? "AND upt.employee_name COLLATE utf8mb4_unicode_ci = '$e_esc'" : '';

/* ── SUMMARY QUERY: grouped by charged employee ── */
$sql_sum = "
    SELECT
        upt.employee_name                                           AS emp_name,
        COUNT(DISTINCT ud.sku_code)                                 AS sku_count,
        COUNT(DISTINCT upt.import_detail_id)                        AS record_count,
        /* shortage from unloading_data short_excess */
        SUM(CASE WHEN COALESCE(ud.short_excess,0) < -0.005
                 THEN ABS(ud.short_excess) ELSE 0 END)              AS total_short_qty,
        SUM(CASE WHEN COALESCE(ud.short_excess,0) < -0.005
                 THEN ABS(ud.short_excess)*COALESCE(ud.tur,0) ELSE 0 END) AS total_short_val,
        /* excess */
        SUM(CASE WHEN COALESCE(ud.short_excess,0) > 0.005
                 THEN ud.short_excess ELSE 0 END)                   AS total_excess_qty,
        SUM(CASE WHEN COALESCE(ud.short_excess,0) > 0.005
                 THEN ud.short_excess*COALESCE(ud.tur,0) ELSE 0 END) AS total_excess_val,
        /* charged to this employee (from pay transactions) */
        SUM(CASE WHEN upt.entry_type='charge' THEN COALESCE(upt.amount,0) ELSE 0 END) AS total_charged,
        /* absorbed (separate rows with entry_type='absorb') */
        SUM(CASE WHEN upt2.entry_type='absorb' THEN COALESCE(upt2.amount,0) ELSE 0 END) AS total_absorbed,
        /* se_value stored in transaction */
        SUM(CASE WHEN upt.entry_type='charge' THEN COALESCE(upt.se_value,0) ELSE 0 END) AS total_se_value,
        /* net shortage value */
        SUM(CASE WHEN COALESCE(ud.short_excess,0) < -0.005
                 THEN ABS(ud.short_excess)*COALESCE(ud.tur,0) ELSE 0 END)
      - SUM(CASE WHEN COALESCE(ud.short_excess,0) > 0.005
                 THEN ud.short_excess*COALESCE(ud.tur,0) ELSE 0 END) AS net_shortage_val,
        /* payroll log */
        COALESCE(pl.payroll_total,0)                                AS payroll_total,
        /* outstanding = charged − payroll deducted */
        SUM(CASE WHEN upt.entry_type='charge' THEN COALESCE(upt.amount,0) ELSE 0 END)
      - COALESCE(pl.payroll_total,0)                                AS outstanding
    FROM unloading_pay_transactions upt
    INNER JOIN unloading_data ud
        ON ud.id = upt.import_detail_id
    LEFT JOIN unloading_pay_transactions upt2
        ON upt2.import_detail_id = upt.import_detail_id
       AND upt2.entry_type = 'absorb'
    LEFT JOIN (
        SELECT employee_name, SUM(amount) AS payroll_total
        FROM emp_charge_payroll_log
        GROUP BY employee_name
    ) pl ON pl.employee_name COLLATE utf8mb4_unicode_ci = upt.employee_name COLLATE utf8mb4_unicode_ci
    WHERE upt.entry_type = 'charge'
      $dWhere $eWhere
    GROUP BY upt.employee_name, pl.payroll_total
    HAVING (total_charged > 0.005 OR total_short_qty > 0.005)
    ORDER BY upt.employee_name
";

$sum_rows = [];
$sql_err  = '';
$sqr = mysqli_query($conn, $sql_sum);
if (!$sqr) { $sql_err = mysqli_error($conn); }
else { while ($r=mysqli_fetch_assoc($sqr)) $sum_rows[]=$r; }

/* ── DETAIL QUERY: per employee × SKU ── */
$sql_det = "
    SELECT
        upt.employee_name                                           AS emp_name,
        ud.sku_code,
        ud.sku_desc,
        COUNT(DISTINCT upt.import_detail_id)                        AS rec_count,
        MIN(COALESCE(ud.delivery_date, ud.record_date))             AS first_date,
        MAX(COALESCE(ud.delivery_date, ud.record_date))             AS last_date,
        AVG(COALESCE(ud.tur,0))                                     AS avg_tur,
        /* shortage/excess from unloading_data */
        SUM(CASE WHEN COALESCE(ud.short_excess,0)<-0.005 THEN ABS(ud.short_excess) ELSE 0 END)  AS short_qty,
        SUM(CASE WHEN COALESCE(ud.short_excess,0)>0.005  THEN ud.short_excess       ELSE 0 END) AS excess_qty,
        SUM(CASE WHEN COALESCE(ud.short_excess,0)<-0.005 THEN ABS(ud.short_excess)*COALESCE(ud.tur,0) ELSE 0 END) AS short_val,
        SUM(CASE WHEN COALESCE(ud.short_excess,0)>0.005  THEN ud.short_excess*COALESCE(ud.tur,0) ELSE 0 END)      AS excess_val,
        /* charge from pay transaction */
        SUM(CASE WHEN upt.entry_type='charge' THEN COALESCE(upt.amount,0) ELSE 0 END)         AS charged,
        /* absorb */
        SUM(CASE WHEN upt2.entry_type='absorb' THEN COALESCE(upt2.amount,0) ELSE 0 END)       AS absorbed,
        /* variance = se_value − (charge+absorb) */
        SUM(COALESCE(upt.se_value,0))
        - SUM(CASE WHEN upt.entry_type='charge' THEN COALESCE(upt.amount,0) ELSE 0 END)
        - SUM(CASE WHEN upt2.entry_type='absorb' THEN COALESCE(upt2.amount,0) ELSE 0 END)     AS variance,
        /* delivery person for reference */
        ud.delivery_person_name                                     AS delivery_person
    FROM unloading_pay_transactions upt
    INNER JOIN unloading_data ud
        ON ud.id = upt.import_detail_id
    LEFT JOIN unloading_pay_transactions upt2
        ON upt2.import_detail_id = upt.import_detail_id
       AND upt2.entry_type = 'absorb'
    WHERE upt.entry_type = 'charge'
      $dWhere $eWhere
    GROUP BY upt.employee_name, ud.sku_code, ud.sku_desc, ud.delivery_person_name
    HAVING (charged>0.005 OR short_qty>0.005)
    ORDER BY upt.employee_name, ud.sku_code
";
$det_rows = [];
$dqr = mysqli_query($conn, $sql_det);
if ($dqr) while ($r=mysqli_fetch_assoc($dqr)) $det_rows[]=$r;

/* group detail rows by employee */
$det_by_emp = [];
foreach ($det_rows as $dr) {
    $det_by_emp[$dr['emp_name']][] = $dr;
}

/* ── TOTALS ── */
$T = ['total_short_qty'=>0,'total_short_val'=>0,'total_excess_qty'=>0,'total_excess_val'=>0,
      'total_charged'=>0,'total_absorbed'=>0,'net_shortage_val'=>0,'payroll_total'=>0,'outstanding'=>0];
foreach ($sum_rows as $sr) foreach (array_keys($T) as $k) $T[$k]+=floatval($sr[$k]);

/* ── PAYROLL PERIODS ── */
$MN=['','January','February','March','April','May','June','July','August','September','October','November','December'];
$pp_all=[]; $active_pp=null;
$pqr=mysqli_query($conn,"SELECT id,year,month,open_date,close_date,status FROM payroll_periods ORDER BY year DESC,month DESC");
if($pqr) while($p=mysqli_fetch_assoc($pqr)){
    $p['label']=$p['year'].' — '.$MN[$p['month']].' ['.$p['status'].']';
    $pp_all[]=$p;
    if(!$active_pp && $p['status']==='Open') $active_pp=$p;
}

function f2($v){ return number_format(abs(floatval($v)),2); }
function fd($v){ return $v==0?'—':number_format(abs(floatval($v)),2); }

/* JS data */
$js_rows=[];
foreach($sum_rows as $i=>$sr){
    $js_rows[]=[
        'idx'           =>$i,
        'emp_name'      =>$sr['emp_name'],
        'total_charged' =>floatval($sr['total_charged']),
        'payroll_total' =>floatval($sr['payroll_total']),
        'outstanding'   =>floatval($sr['outstanding']),
        'net_shortage_val'=>floatval($sr['net_shortage_val']),
    ];
}
$ap_js=$active_pp?['id'=>intval($active_pp['id']),'year'=>intval($active_pp['year']),'month'=>intval($active_pp['month']),'open_date'=>$active_pp['open_date'],'close_date'=>$active_pp['close_date']]:null;
?>
<!– Select2 –>
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet">
<style>
@import url('https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Space+Mono:wght@400;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&display=swap');

:root{
    --bg:#f2f5fb; --surf:#fff; --surf2:#f0f4fa; --surf3:#e6eaf4;
    --bdr:#dde3ef; --bdr2:#eaeef7;
    --tx:#0d1526; --txm:#4a5568; --txs:#94a3b8;
    --fn:'DM Sans',sans-serif; --fh:'Syne',sans-serif; --mn:'Space Mono',monospace;
    --r:13px;
    --red:#e53e3e; --red-lt:#fff5f5; --red-md:#feb2b2; --red-dk:#c53030;
    --grn:#38a169; --grn-lt:#f0fff4; --grn-md:#9ae6b4;
    --amb:#c97f1a; --amb-lt:#fffbeb; --amb-md:#f6d860;
    --prp:#7c3aed; --prp-lt:#faf5ff; --prp-md:#c4b5fd;
    --blu:#2b6cb0; --blu-lt:#ebf8ff; --blu-md:#90cdf4;
    --teal:#0d9488; --teal-lt:#f0fdfa; --teal-md:#99f6e4;
    --ora:#c05621; --ora-lt:#fff7ed; --ora-md:#fed7aa;
    --sh-sm:0 1px 4px rgba(13,21,38,.06);
    --sh-md:0 4px 20px rgba(13,21,38,.09);
    --sh-lg:0 12px 48px rgba(13,21,38,.14);
    --sh-xl:0 24px 80px rgba(13,21,38,.22);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:13px;min-height:100vh;}

/* ── PAGE ── */
.pg{padding:24px 20px 100px;max-width:1600px;margin:0 auto;}

/* ── TOPBAR ── */
.topbar{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:22px;}
.brand{display:flex;align-items:center;gap:15px;}
.brand-ico{width:50px;height:50px;background:linear-gradient(135deg,#e53e3e,#7b1c1c);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:21px;color:#fff;flex-shrink:0;box-shadow:0 6px 20px rgba(229,62,62,.28);}
.brand-h1{font-family:var(--fh);font-size:21px;font-weight:800;letter-spacing:-.03em;}
.brand-h1 span{color:var(--red);}
.brand-sub{font-size:11.5px;color:var(--txm);margin-top:3px;font-weight:500;}
.dpill{display:inline-flex;align-items:center;gap:8px;background:var(--red-lt);border:1px solid var(--red-md);border-radius:24px;padding:8px 18px;font-size:11px;font-weight:700;color:var(--red);font-family:var(--mn);}
.topbtns{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}

/* ── FILTER BAR ── */
.fbar{background:var(--surf);border:1px solid var(--bdr);border-radius:var(--r);padding:14px 16px;margin-bottom:18px;display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;box-shadow:var(--sh-sm);}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:10px;font-weight:700;color:var(--txm);text-transform:uppercase;letter-spacing:.08em;}
.fg input,.fg select{padding:9px 12px;border:1.5px solid var(--bdr);border-radius:8px;font-size:12.5px;font-family:var(--fn);color:var(--tx);background:var(--surf2);transition:border-color .15s;}
.fg input:focus,.fg select:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(229,62,62,.1);}
.btn-go{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;background:linear-gradient(135deg,#e53e3e,#c53030);color:#fff;border:none;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;box-shadow:0 2px 10px rgba(229,62,62,.25);transition:all .15s;font-family:var(--fn);}
.btn-go:hover{transform:translateY(-1px);box-shadow:0 4px 18px rgba(229,62,62,.38);}
.btn-rst{display:inline-flex;align-items:center;gap:6px;padding:10px 14px;background:var(--surf2);color:var(--txm);border:1.5px solid var(--bdr);border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .15s;}
.btn-rst:hover{color:var(--tx);background:var(--surf3);}
.btn-xl{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;background:linear-gradient(135deg,#38a169,#276749);color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(56,161,105,.2);transition:all .15s;}
.btn-xl:hover{transform:translateY(-1px);}
.btn-pr{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;background:var(--surf2);color:var(--txm);border:1.5px solid var(--bdr);border-radius:8px;font-size:12px;cursor:pointer;transition:all .15s;}
.btn-pr:hover{color:var(--tx);background:var(--surf3);}

/* ── STAT CARDS ── */
.sc-row{display:grid;grid-template-columns:repeat(6,1fr);gap:13px;margin-bottom:20px;}
.sc{background:var(--surf);border:1px solid var(--bdr);border-radius:var(--r);padding:16px 18px;position:relative;overflow:hidden;box-shadow:var(--sh-sm);transition:box-shadow .2s,transform .2s;}
.sc:hover{box-shadow:var(--sh-md);transform:translateY(-2px);}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--r) var(--r) 0 0;}
.sc.r::before{background:linear-gradient(90deg,var(--red),#fc8181);}
.sc.g::before{background:linear-gradient(90deg,var(--grn),#68d391);}
.sc.a::before{background:linear-gradient(90deg,var(--amb),#f6c90e);}
.sc.p::before{background:linear-gradient(90deg,var(--prp),#a78bfa);}
.sc.b::before{background:linear-gradient(90deg,var(--blu),#63b3ed);}
.sc.t::before{background:linear-gradient(90deg,var(--teal),#2dd4bf);}
.sc-ico{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;margin-bottom:10px;}
.sc.r .sc-ico{background:var(--red-lt);color:var(--red);}
.sc.g .sc-ico{background:var(--grn-lt);color:var(--grn);}
.sc.a .sc-ico{background:var(--amb-lt);color:var(--amb);}
.sc.p .sc-ico{background:var(--prp-lt);color:var(--prp);}
.sc.b .sc-ico{background:var(--blu-lt);color:var(--blu);}
.sc.t .sc-ico{background:var(--teal-lt);color:var(--teal);}
.sc-lbl{font-size:9.5px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.1em;margin-bottom:5px;}
.sc-val{font-family:var(--mn);font-size:16px;font-weight:700;}
.sc.r .sc-val{color:var(--red);}
.sc.g .sc-val{color:var(--grn);}
.sc.a .sc-val{color:var(--amb);}
.sc.p .sc-val{color:var(--prp);}
.sc.b .sc-val{color:var(--blu);}
.sc.t .sc-val{color:var(--teal);}
.sc-sub{font-size:10px;color:var(--txs);margin-top:4px;}

/* ── MAIN CARD ── */
.mc{background:var(--surf);border:1px solid var(--bdr);border-radius:var(--r);overflow:hidden;box-shadow:var(--sh-sm);}
.mc-bar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid var(--bdr);flex-wrap:wrap;gap:10px;}
.mc-ttl{font-family:var(--fh);font-size:15px;font-weight:700;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.pill{padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;font-family:var(--mn);}
.pr{background:var(--red-lt);color:var(--red);border:1px solid var(--red-md);}
.pg2{background:var(--grn-lt);color:var(--grn);border:1px solid var(--grn-md);}
.pb{background:var(--blu-lt);color:var(--blu);border:1px solid var(--blu-md);}
.pp{background:var(--prp-lt);color:var(--prp);border:1px solid var(--prp-md);}

/* ── SEARCH BAR ── */
.sbar{display:flex;align-items:center;gap:10px;padding:11px 16px;border-bottom:1px solid var(--bdr2);background:var(--surf2);flex-wrap:wrap;}
.sw{position:relative;}
.sw i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--txs);font-size:12px;pointer-events:none;}
.si{padding:8px 12px 8px 32px;border:1.5px solid var(--bdr);border-radius:8px;font-size:12.5px;font-family:var(--fn);background:var(--surf);outline:none;min-width:220px;transition:border-color .15s;}
.si:focus{border-color:var(--red);}
#vis-c{font-size:11px;color:var(--txs);font-family:var(--mn);margin-left:auto;}

/* ── MAIN TABLE ── */
.tscroll{overflow-x:auto;}
table.mt{width:100%;border-collapse:collapse;font-size:12.5px;}
.mt thead{position:sticky;top:0;z-index:10;}
.mt thead th{background:var(--surf2);padding:10px 14px;font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--txm);white-space:nowrap;border-bottom:2px solid var(--bdr);text-align:right;}
.mt thead th.tl{text-align:left;}
.mt thead th.h-short{border-top:3px solid var(--red);}
.mt thead th.h-exc{border-top:3px solid var(--grn);}
.mt thead th.h-chg{border-top:3px solid var(--ora);}
.mt thead th.h-abs{border-top:3px solid var(--blu);}
.mt thead th.h-prl{border-top:3px solid var(--prp);}
.mt thead th.h-out{border-top:3px solid var(--teal);}

/* totals row */
.mt thead tr.TR td{background:#f8fafc;padding:8px 14px;font-size:11.5px;font-weight:700;font-family:var(--mn);text-align:right;border-bottom:2px solid var(--bdr);white-space:nowrap;}
.mt thead tr.TR td.tl{text-align:left;font-family:var(--fn);color:var(--txm);}
.cr{color:var(--red);} .cg{color:var(--grn);} .ca{color:var(--amb);}
.cp{color:var(--prp);} .cb{color:var(--blu);} .ct{color:var(--teal);}
.co{color:var(--ora);}

/* summary rows */
.mt tbody tr.sr{cursor:pointer;transition:background .12s;}
.mt tbody tr.sr td{padding:10px 14px;border-bottom:1px solid var(--bdr2);white-space:nowrap;text-align:right;vertical-align:middle;}
.mt tbody tr.sr:hover td{background:#eef3ff !important;}
.mt tbody tr.sr.open td{background:#f5f3ff !important;}
.mt tbody tr.sr.open td{border-bottom:none;}
.mt tbody tr.stripe td{background:#fafbfd;}
.mt tbody td.tl{text-align:left;}
.mt tbody td.emp-nm{font-family:var(--fh);font-size:13px;font-weight:700;}
.mt tbody td.rno{font-family:var(--mn);font-size:10px;color:var(--txs);text-align:center;}

/* expand icon */
.exp-ico{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:6px;background:var(--surf3);border:1px solid var(--bdr);font-size:10px;color:var(--txm);transition:all .2s;flex-shrink:0;margin-right:8px;}
.open .exp-ico{background:var(--prp-lt);border-color:var(--prp-md);color:var(--prp);transform:rotate(90deg);}

/* detail rows */
.mt tbody tr.dr{display:none;}
.mt tbody tr.dr td{padding:8px 14px;background:#f9f7ff;border-bottom:1px solid #ede9fe;text-align:right;white-space:nowrap;font-size:12px;vertical-align:middle;}
.mt tbody tr.dr td.tl{text-align:left;}
.mt tbody tr.dr:last-child td{border-bottom:2px solid var(--bdr);}
.mt tbody tr.dr.show{display:table-row;}
.mt tbody tr.dr td.sku-c{font-family:var(--mn);font-size:11px;font-weight:700;color:var(--prp);}
.mt tbody tr.dr td.desc-c{max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--txm);}
.det-hdr td{background:#ede9fe !important;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--prp) !important;padding:6px 14px !important;}

/* footer */
.mt tfoot td{padding:11px 14px;font-weight:700;font-size:11.5px;background:#f8fafc;text-align:right;white-space:nowrap;border-top:2px solid var(--bdr);font-family:var(--mn);}
.mt tfoot td.tl{text-align:left;font-family:var(--fn);color:var(--txs);}

/* value classes */
.v-r{color:var(--red);font-family:var(--mn);font-size:12px;font-weight:700;}
.v-g{color:var(--grn);font-family:var(--mn);font-size:12px;font-weight:700;}
.v-a{color:var(--amb);font-family:var(--mn);font-size:12px;font-weight:700;}
.v-p{color:var(--prp);font-family:var(--mn);font-size:12px;font-weight:700;}
.v-b{color:var(--blu);font-family:var(--mn);font-size:12px;font-weight:700;}
.v-t{color:var(--teal);font-family:var(--mn);font-size:12px;font-weight:700;}
.v-o{color:var(--ora);font-family:var(--mn);font-size:12px;font-weight:700;}
.v-s{color:var(--txs);font-size:11px;}
.bal-p{color:var(--teal);font-family:var(--mn);font-size:12px;font-weight:700;}
.bal-0{color:var(--grn);font-family:var(--mn);font-size:12px;font-weight:700;}
.bal-n{color:var(--amb);font-family:var(--mn);font-size:12px;font-weight:700;}

/* badges */
.bsh{display:inline-flex;align-items:center;gap:3px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;font-family:var(--mn);background:var(--red-lt);color:var(--red);border:1px solid var(--red-md);}
.bex{display:inline-flex;align-items:center;gap:3px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;font-family:var(--mn);background:var(--grn-lt);color:var(--grn);border:1px solid var(--grn-md);}
.bze{display:inline-flex;align-items:center;gap:3px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;font-family:var(--mn);background:var(--surf3);color:var(--txm);border:1px solid var(--bdr);}
.prl-b{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;font-size:10.5px;font-weight:700;font-family:var(--mn);background:var(--prp-lt);color:var(--prp);border:1px solid var(--prp-md);}

/* payroll btn */
.btn-py{display:inline-flex;align-items:center;gap:5px;padding:5px 13px;background:linear-gradient(135deg,var(--prp),#5b21b6);color:#fff;border:none;border-radius:7px;font-size:11px;font-weight:700;cursor:pointer;font-family:var(--fn);transition:all .15s;box-shadow:0 1px 4px rgba(124,58,237,.28);white-space:nowrap;}
.btn-py:hover{transform:translateY(-1px);box-shadow:0 3px 12px rgba(124,58,237,.38);}
.btn-py.done{background:linear-gradient(135deg,var(--grn),#276749);box-shadow:0 1px 4px rgba(56,161,105,.28);}
.btn-py.done:hover{box-shadow:0 3px 12px rgba(56,161,105,.38);}

/* empty */
.empty{text-align:center;padding:80px 20px;}
.e-ico{font-size:52px;display:block;margin-bottom:16px;opacity:.12;}
.empty p{font-family:var(--fh);font-size:16px;font-weight:700;margin-bottom:6px;}

/* ══ PAYROLL MODAL ══ */
#pmBg{position:fixed;inset:0;background:rgba(13,21,38,.6);z-index:10000;display:none;align-items:center;justify-content:center;padding:20px;}
.pm-box{background:#fff;border-radius:18px;width:100%;max-width:640px;max-height:92vh;overflow-y:auto;box-shadow:var(--sh-xl);display:flex;flex-direction:column;}
.pm-hd{display:flex;align-items:center;gap:14px;padding:20px 22px;flex-shrink:0;background:linear-gradient(135deg,#faf5ff,#ede9fe);border-bottom:1px solid var(--prp-md);border-radius:18px 18px 0 0;}
.pm-hd-ico{width:44px;height:44px;flex-shrink:0;background:linear-gradient(135deg,var(--prp),#5b21b6);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff;box-shadow:0 4px 14px rgba(124,58,237,.32);}
.pm-hd-info{flex:1;min-width:0;}
.pm-hd-name{font-family:var(--fh);font-size:15px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.pm-hd-sub{font-size:11px;color:var(--txm);margin-top:2px;}
.pm-cls{background:none;border:none;font-size:24px;color:var(--txs);cursor:pointer;padding:4px 8px;border-radius:8px;line-height:1;flex-shrink:0;transition:all .15s;}
.pm-cls:hover{background:var(--red-lt);color:var(--red);}
.pm-pb{padding:9px 22px;font-size:12px;font-weight:600;display:flex;align-items:center;gap:8px;flex-shrink:0;}
.pm-pb.ok{background:var(--grn-lt);color:var(--grn);border-bottom:1px solid var(--grn-md);}
.pm-pb.no{background:var(--amb-lt);color:var(--amb);border-bottom:1px solid var(--amb-md);}
.pm-cards{display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid var(--bdr);flex-shrink:0;}
.pm-card{padding:14px 16px;text-align:center;border-right:1px solid var(--bdr);}
.pm-card:last-child{border-right:none;}
.pm-cl{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--txs);margin-bottom:6px;}
.pm-cv{font-family:var(--mn);font-size:14px;font-weight:800;}
.pm-card.c1 .pm-cv{color:var(--red);}
.pm-card.c2 .pm-cv{color:var(--prp);}
.pm-card.c3 .pm-cv{color:var(--teal);}
.pm-card.c4{background:var(--amb-lt);}
.pm-card.c4 .pm-cv{color:var(--amb);}
.pm-card.c4.ok{background:var(--grn-lt);}
.pm-card.c4.ok .pm-cv{color:var(--grn);}
.pm-sec{padding:18px 22px;border-bottom:1px solid var(--bdr);}
.pm-sl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:var(--txm);margin-bottom:14px;display:flex;align-items:center;gap:7px;}
.pm-g2{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;}
.pm-fg{display:flex;flex-direction:column;gap:5px;}
.pm-lb{font-size:10px;font-weight:700;color:var(--txm);text-transform:uppercase;letter-spacing:.06em;}
.pm-in{padding:9px 11px;border:1.5px solid var(--bdr);border-radius:8px;font-size:12.5px;font-family:var(--fn);color:var(--tx);background:var(--surf2);width:100%;transition:border-color .15s;}
.pm-in:focus{outline:none;border-color:var(--prp);box-shadow:0 0 0 3px rgba(124,58,237,.1);}
.pm-in.mn{font-family:var(--mn);}
.btn-sv{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;background:linear-gradient(135deg,var(--prp),#5b21b6);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 2px 10px rgba(124,58,237,.28);transition:all .15s;font-family:var(--fn);}
.btn-sv:hover{transform:translateY(-1px);}
.btn-sv:disabled{opacity:.45;cursor:not-allowed;transform:none;}
.pm-hist{padding:18px 22px;}
.pm-hl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:var(--txm);margin-bottom:12px;display:flex;align-items:center;gap:7px;}
.pm-nh{text-align:center;padding:24px;color:var(--txs);font-size:12px;}
.pm-ci{display:flex;align-items:center;gap:10px;padding:10px 13px;border:1px solid var(--bdr);border-radius:10px;margin-bottom:8px;background:var(--surf2);transition:background .15s;}
.pm-ci:hover{background:var(--prp-lt);}
.pm-ci-l{flex:1;min-width:0;}
.pm-ci-p{font-size:10px;font-weight:700;color:var(--prp);font-family:var(--mn);}
.pm-ci-d{font-size:10px;color:var(--txs);margin-top:1px;}
.pm-ci-x{font-size:11px;color:var(--txm);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.pm-ci-a{font-family:var(--mn);font-size:14px;font-weight:800;color:var(--prp);flex-shrink:0;}
.btn-dc{background:var(--red-lt);border:1px solid var(--red-md);border-radius:6px;padding:5px 9px;font-size:11px;color:var(--red);cursor:pointer;flex-shrink:0;transition:all .15s;}
.btn-dc:hover{background:var(--red);color:#fff;}
.pm-spin{text-align:center;padding:22px;color:var(--txs);font-size:12px;}

/* Select2 overrides */
.select2-container{font-family:var(--fn);font-size:12.5px;}
.select2-container .select2-selection--single{height:40px;border:1.5px solid var(--bdr);border-radius:8px;background:var(--surf2);display:flex;align-items:center;}
.select2-container--default .select2-selection--single .select2-selection__rendered{color:var(--tx);line-height:40px;padding-left:11px;padding-right:30px;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:38px;right:7px;}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open  .select2-selection--single{border-color:var(--prp);box-shadow:0 0 0 3px rgba(124,58,237,.1);}
.select2-dropdown{border:1.5px solid var(--bdr);border-radius:12px;background:var(--surf);box-shadow:var(--sh-lg);font-size:12.5px;}
.select2-results__option{padding:8px 14px;color:var(--tx);}
.select2-container--default .select2-results__option--highlighted[aria-selected]{background:var(--prp-lt)!important;color:var(--prp)!important;}
.select2-search--dropdown .select2-search__field{background:var(--surf2);border:1.5px solid var(--bdr);border-radius:6px;padding:7px 11px;}
.fbar .select2-container--default.select2-container--focus .select2-selection--single,
.fbar .select2-container--default.select2-container--open  .select2-selection--single{border-color:var(--red);box-shadow:0 0 0 3px rgba(229,62,62,.1);}
.fbar .select2-container--default .select2-results__option--highlighted[aria-selected]{background:var(--red-lt)!important;color:var(--red)!important;}
.fbar .select2-container .select2-selection--single{background:var(--surf2);}

/* toast */
#ecst{position:fixed;bottom:26px;right:26px;padding:12px 22px;border-radius:10px;font-size:13px;font-weight:600;z-index:20000;display:none;opacity:0;transition:opacity .3s;font-family:var(--fn);box-shadow:var(--sh-lg);}
.tok{background:#f0fff4;color:var(--grn);border:1.5px solid var(--grn-md);}
.ter{background:#fff5f5;color:var(--red);border:1.5px solid var(--red-md);}

@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
@media(max-width:1000px){.sc-row{grid-template-columns:repeat(3,1fr);}}
@media(max-width:600px){.sc-row{grid-template-columns:1fr 1fr;}.pm-cards{grid-template-columns:1fr 1fr;}.pm-g2{grid-template-columns:1fr;}}
@media print{
    body{background:#fff;}.no-print{display:none!important;}
    .mt thead th{-webkit-print-color-adjust:exact;print-color-adjust:exact;background:#f1f5f9!important;}
    .mt tfoot td{-webkit-print-color-adjust:exact;print-color-adjust:exact;background:#f8fafc!important;}
    .mc{box-shadow:none;border:1px solid #e2e8f0;}
    .mt tbody tr.dr{display:table-row!important;}
}
</style>

<div class="pg">

<!-- TOPBAR -->
<div class="topbar no-print">
    <div class="brand">
        <div class="brand-ico"><i class="fa-solid fa-person-circle-exclamation"></i></div>
        <div>
            <div class="brand-h1">Employee <span>Charge Report</span></div>
            <div class="brand-sub">From <code>unloading_pay_transactions</code> &nbsp;·&nbsp; Actual Charged Employee &nbsp;·&nbsp; Short &amp; Excess &nbsp;·&nbsp; Payroll Deduction</div>
        </div>
    </div>
    <div class="topbtns">
        <div class="dpill"><i class="fa-solid fa-calendar-days"></i>
            <?php echo date('d M Y',strtotime($date_from)).' &mdash; '.date('d M Y',strtotime($date_to)); ?>
        </div>
        <?php if(!empty($sum_rows)): ?>
        <button onclick="exportXL()" class="btn-xl"><i class="fa-solid fa-file-excel"></i> Excel</button>
        <button onclick="window.print()" class="btn-pr"><i class="fa-solid fa-print"></i> Print</button>
        <?php endif; ?>
    </div>
</div>

<!-- FILTER BAR -->
<div class="fbar no-print">
    <form method="GET" id="ecForm" style="display:contents;">
        <div class="fg">
            <label>Date From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
        </div>
        <div class="fg">
            <label>Date To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
        </div>
        <div class="fg" style="min-width:210px;">
            <label>Employee</label>
            <select name="employee" id="sel_p">
                <option value="">— All Employees —</option>
                <?php foreach($all_emps as $e): ?>
                <option value="<?php echo htmlspecialchars($e); ?>" <?php echo ($f_emp===$e)?'selected':''; ?>>
                    <?php echo htmlspecialchars($e); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-go"><i class="fa-solid fa-bolt"></i> Apply</button>
        <a href="employee_charge_shortage_report.php" class="btn-rst"><i class="fa-solid fa-rotate-left"></i></a>
    </form>
</div>

<?php if(!empty($sql_err)): ?>
<div style="background:#fff5f5;border:1px solid #feb2b2;border-radius:12px;padding:16px 20px;margin-bottom:18px;font-size:13px;color:#c53030;">
    <strong><i class="fa-solid fa-triangle-exclamation"></i> SQL Error:</strong> <?php echo htmlspecialchars($sql_err); ?>
</div>
<?php endif; ?>

<?php if(empty($sum_rows)): ?>
<div class="mc">
    <div class="empty">
        <i class="fa-solid fa-person-circle-question e-ico"></i>
        <p>No charge records found</p>
        <small style="color:var(--txs);">
            <?php echo date('d M Y',strtotime($date_from)).' – '.date('d M Y',strtotime($date_to));
                  echo $f_person?' &nbsp;·&nbsp; <strong>'.htmlspecialchars($f_person).'</strong>':''; ?>
        </small>
        <div style="margin-top:14px;font-size:12px;color:var(--txs);">
            Data loads from <code>unloading_data</code> where <code>actual_qty</code> is saved and
            <code>charge_to_employee</code> or <code>short_excess</code> is non-zero.
        </div>
    </div>
</div>

<?php else: ?>

<!-- STAT CARDS -->
<div class="sc-row no-print">
    <div class="sc r">
        <div class="sc-ico"><i class="fa-solid fa-arrow-trend-down"></i></div>
        <div class="sc-lbl">Total Short Value</div>
        <div class="sc-val">Rs.&nbsp;<?php echo number_format($T['total_short_val'],2); ?></div>
        <div class="sc-sub"><?php echo number_format($T['total_short_qty'],2); ?> units short</div>
    </div>
    <div class="sc g">
        <div class="sc-ico"><i class="fa-solid fa-arrow-trend-up"></i></div>
        <div class="sc-lbl">Total Excess Value</div>
        <div class="sc-val">Rs.&nbsp;<?php echo number_format($T['total_excess_val'],2); ?></div>
        <div class="sc-sub"><?php echo number_format($T['total_excess_qty'],2); ?> units excess</div>
    </div>
    <div class="sc a">
        <div class="sc-ico"><i class="fa-solid fa-scale-balanced"></i></div>
        <div class="sc-lbl">Net Shortage Value</div>
        <div class="sc-val">Rs.&nbsp;<?php echo number_format($T['net_shortage_val'],2); ?></div>
        <div class="sc-sub">Short minus Excess</div>
    </div>
    <div class="sc" style="--c:var(--ora);">
        <div style="--bc:var(--ora);"></div>
        <div class="sc-ico" style="background:var(--ora-lt);color:var(--ora);"><i class="fa-solid fa-user-minus"></i></div>
        <div class="sc-lbl">Charged to Employee</div>
        <div class="sc-val" style="color:var(--ora);">Rs.&nbsp;<?php echo number_format($T['total_charged'],2); ?></div>
        <div class="sc-sub">From pay allocation</div>
    </div>
    <div class="sc b">
        <div class="sc-ico"><i class="fa-solid fa-building"></i></div>
        <div class="sc-lbl">Absorbed by Company</div>
        <div class="sc-val">Rs.&nbsp;<?php echo number_format($T['total_absorbed'],2); ?></div>
        <div class="sc-sub">Company coverage</div>
    </div>
    <div class="sc p">
        <div class="sc-ico"><i class="fa-solid fa-wallet"></i></div>
        <div class="sc-lbl">Payroll Deducted</div>
        <div class="sc-val">Rs.&nbsp;<?php echo number_format($T['payroll_total'],2); ?></div>
        <div class="sc-sub">Outstanding: Rs.&nbsp;<?php echo number_format($T['outstanding'],2); ?></div>
    </div>
</div>

<!-- ora card fix for ::before -->
<style>.sc:nth-child(4)::before{background:linear-gradient(90deg,var(--ora),#fb923c);}</style>

<!-- MAIN TABLE CARD -->
<div class="mc">
    <div class="mc-bar no-print">
        <div class="mc-ttl">
            <i class="fa-solid fa-users" style="color:var(--prp);"></i>
            Employee Charge Summary
            <span class="pill pr"><?php echo count($sum_rows); ?> employees</span>
            <span class="pill pb"><?php echo count($det_rows); ?> SKU lines</span>
            <?php if($active_pp): ?>
            <span class="pill pp">
                <i class="fa-solid fa-circle" style="font-size:6px;animation:pulse 2s infinite;"></i>&nbsp;
                Active: <?php echo $MN[$active_pp['month']].' '.$active_pp['year']; ?>
            </span>
            <?php endif; ?>
        </div>
        <small style="font-size:11px;color:var(--txs);" class="no-print">
            <i class="fa-solid fa-hand-pointer"></i> Click row to expand SKU detail
        </small>
    </div>

    <!-- search -->
    <div class="sbar no-print">
        <div class="sw">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="si" id="srch" placeholder="Search employee…" oninput="doSearch()">
        </div>
        <button class="btn-rst" onclick="expandAll()" style="font-size:11px;padding:7px 12px;">
            <i class="fa-solid fa-angles-down"></i> Expand All
        </button>
        <button class="btn-rst" onclick="collapseAll()" style="font-size:11px;padding:7px 12px;">
            <i class="fa-solid fa-angles-up"></i> Collapse All
        </button>
        <div id="vis-c"></div>
    </div>

    <div class="tscroll">
    <table class="mt" id="mainTbl">
      <thead>
        <tr>
            <th style="min-width:34px;" class="tl">#</th>
            <th class="tl" style="min-width:220px;">Employee</th>
            <th style="min-width:55px;">SKUs</th>
            <th style="min-width:55px;">Recs</th>
            <th class="h-short" style="min-width:110px;">Short Qty</th>
            <th class="h-short" style="min-width:120px;">Short Value</th>
            <th class="h-exc"   style="min-width:110px;">Excess Qty</th>
            <th class="h-exc"   style="min-width:120px;">Excess Value</th>
            <th class="h-chg"   style="min-width:130px;">Charge to Emp</th>
            <th class="h-abs"   style="min-width:130px;">Absorb by Co.</th>
            <th class="h-prl no-print" style="min-width:130px;">Payroll Deducted</th>
            <th class="h-out"   style="min-width:130px;">Outstanding</th>
            <th class="no-print" style="min-width:110px;text-align:center;">Action</th>
        </tr>
        <tr class="TR">
            <td class="tl" colspan="4">
                <i class="fa-solid fa-sigma" style="margin-right:5px;"></i>
                TOTALS &nbsp;·&nbsp; <?php echo count($sum_rows); ?> employees
            </td>
            <td class="cr"><?php echo fd($T['total_short_qty']); ?></td>
            <td class="cr"><?php echo fd($T['total_short_val']); ?></td>
            <td class="cg"><?php echo fd($T['total_excess_qty']); ?></td>
            <td class="cg"><?php echo fd($T['total_excess_val']); ?></td>
            <td class="co"><?php echo fd($T['total_charged']); ?></td>
            <td class="cb"><?php echo fd($T['total_absorbed']); ?></td>
            <td class="cp no-print" id="hd_prl"><?php echo fd($T['payroll_total']); ?></td>
            <td class="ct" id="hd_out"><?php echo fd($T['outstanding']); ?></td>
            <td class="no-print"></td>
        </tr>
      </thead>
      <tbody id="tBody">
      <?php
      $idx=0;
      foreach($sum_rows as $sr):
          $stripe = ($idx%2!==0)?'stripe':'';
          $emp    = $sr['emp_name'];
          $has_det= isset($det_by_emp[$emp]);
          $charged= floatval($sr['total_charged']);
          $prl    = floatval($sr['payroll_total']);
          $out    = floatval($sr['outstanding']);
          $outCls = $out<-0.005?'bal-n':($out>0.005?'bal-p':'bal-0');
          $hasPrl = $prl>0.005;
          $short_q= floatval($sr['total_short_qty']);
          $excess_q=floatval($sr['total_excess_qty']);
          $short_v= floatval($sr['total_short_val']);
          $excess_v=floatval($sr['total_excess_val']);
      ?>
      <!-- SUMMARY ROW -->
      <tr class="sr <?php echo $stripe; ?>" id="sr-<?php echo $idx; ?>"
          onclick="toggleDetail(<?php echo $idx; ?>)"
          data-emp="<?php echo strtolower(htmlspecialchars($emp)); ?>">
          <td class="rno"><?php echo $idx+1; ?></td>
          <td class="emp-nm tl">
              <span class="exp-ico"><i class="fa-solid fa-chevron-right"></i></span>
              <?php echo htmlspecialchars($emp); ?>
          </td>
          <td style="text-align:center;font-family:var(--mn);font-size:11px;color:var(--txm);">
              <?php echo intval($sr['sku_count']); ?>
          </td>
          <td style="text-align:center;font-family:var(--mn);font-size:11px;color:var(--txm);">
              <?php echo intval($sr['record_count']); ?>
          </td>
          <td><?php echo $short_q>0.005?'<span class="v-r">'.f2($short_q).'</span>':'<span class="v-s">—</span>'; ?></td>
          <td><?php echo $short_v>0.005?'<span class="v-r">'.f2($short_v).'</span>':'<span class="v-s">—</span>'; ?></td>
          <td><?php echo $excess_q>0.005?'<span class="v-g">'.f2($excess_q).'</span>':'<span class="v-s">—</span>'; ?></td>
          <td><?php echo $excess_v>0.005?'<span class="v-g">'.f2($excess_v).'</span>':'<span class="v-s">—</span>'; ?></td>
          <td><?php echo $charged>0.005?'<span class="v-o">'.f2($charged).'</span>':'<span class="v-s">—</span>'; ?></td>
          <td><?php $abs=floatval($sr['total_absorbed']); echo $abs>0.005?'<span class="v-b">'.f2($abs).'</span>':'<span class="v-s">—</span>'; ?></td>
          <td class="no-print" id="prl-<?php echo $idx; ?>">
              <?php if($hasPrl): ?>
                  <span class="prl-b"><i class="fa-solid fa-wallet" style="font-size:9px;"></i>&nbsp;<?php echo f2($prl); ?></span>
              <?php else: ?><span class="v-s">—</span><?php endif; ?>
          </td>
          <td id="out-<?php echo $idx; ?>">
              <span class="<?php echo $outCls; ?>"><?php echo ($out<0?'-':'').f2(abs($out)); ?></span>
          </td>
          <td class="no-print" onclick="event.stopPropagation()">
              <button class="btn-py <?php echo $hasPrl?'done':''; ?>"
                      onclick="openPM(<?php echo $idx; ?>)">
                  <i class="fa-solid fa-<?php echo $hasPrl?'check-circle':'wallet'; ?>"></i>
                  Payroll
              </button>
          </td>
      </tr>

      <!-- DETAIL HEADER ROW -->
      <?php if($has_det): ?>
      <tr class="dr det-hdr" id="dh-<?php echo $idx; ?>">
          <td></td>
          <td class="tl" style="padding-left:52px!important;">SKU Code</td>
          <td class="tl">Description</td>
          <td class="tl">Delivery Person</td>
          <td style="text-align:center;">Recs</td>
          <td>Short Qty</td>
          <td>Short Val</td>
          <td>Excess Qty</td>
          <td>Excess Val</td>
          <td>Charged</td>
          <td>Absorbed</td>
          <td class="no-print">Variance</td>
          <td>Date Range</td>
          <td class="no-print"></td>
      </tr>

      <!-- DETAIL DATA ROWS -->
      <?php foreach($det_by_emp[$emp] as $dr):
          $sq=floatval($dr['short_qty']); $sv=floatval($dr['short_val']);
          $eq=floatval($dr['excess_qty']);$ev=floatval($dr['excess_val']);
          $ch=floatval($dr['charged']);   $ab=floatval($dr['absorbed']);
          $va=floatval($dr['variance']);
          $fd1=!empty($dr['first_date'])?date('d M',strtotime($dr['first_date'])):'—';
          $fd2=!empty($dr['last_date']) ?date('d M Y',strtotime($dr['last_date'])):'—';
          $varCls= $va<-0.005?'v-r':($va>0.005?'v-g':'v-s');
      ?>
      <tr class="dr" id="dd-<?php echo $idx; ?>-<?php echo htmlspecialchars($dr['sku_code']); ?>">
          <td></td>
          <td class="sku-c tl" style="padding-left:52px!important;">
              <?php echo htmlspecialchars($dr['sku_code']); ?>
          </td>
          <td class="desc-c tl" title="<?php echo htmlspecialchars($dr['sku_desc']??''); ?>">
              <?php echo htmlspecialchars($dr['sku_desc']??'—'); ?>
          </td>
          <td class="tl" style="font-size:11px;color:var(--txm);white-space:nowrap;">
              <?php echo htmlspecialchars($dr['delivery_person']??'—'); ?>
          </td>
          <td style="text-align:center;font-family:var(--mn);font-size:11px;color:var(--txm);">
              <?php echo intval($dr['rec_count']); ?>
          </td>
          <td><?php echo $sq>0.005?'<span class="v-r">'.f2($sq).'</span>':'<span class="v-s">—</span>'; ?></td>
          <td><?php echo $sv>0.005?'<span class="v-r">'.f2($sv).'</span>':'<span class="v-s">—</span>'; ?></td>
          <td><?php echo $eq>0.005?'<span class="v-g">'.f2($eq).'</span>':'<span class="v-s">—</span>'; ?></td>
          <td><?php echo $ev>0.005?'<span class="v-g">'.f2($ev).'</span>':'<span class="v-s">—</span>'; ?></td>
          <td><?php echo $ch>0.005?'<span class="v-o">'.f2($ch).'</span>':'<span class="v-s">—</span>'; ?></td>
          <td><?php echo $ab>0.005?'<span class="v-b">'.f2($ab).'</span>':'<span class="v-s">—</span>'; ?></td>
          <td class="no-print">
              <span class="<?php echo $varCls; ?>"><?php echo ($va>0?'+':'').f2($va); ?></span>
          </td>
          <td style="font-size:10.5px;color:var(--txm);font-family:var(--mn);">
              <?php echo $fd1; ?> – <?php echo $fd2; ?>
          </td>
          <td class="no-print"></td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>

      <?php $idx++; endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
            <td class="tl" colspan="4">GRAND TOTAL — <?php echo count($sum_rows); ?> employees</td>
            <td class="cr"><?php echo fd($T['total_short_qty']); ?></td>
            <td class="cr"><?php echo fd($T['total_short_val']); ?></td>
            <td class="cg"><?php echo fd($T['total_excess_qty']); ?></td>
            <td class="cg"><?php echo fd($T['total_excess_val']); ?></td>
            <td class="co"><?php echo fd($T['total_charged']); ?></td>
            <td class="cb"><?php echo fd($T['total_absorbed']); ?></td>
            <td class="cp no-print" id="ft_prl"><?php echo fd($T['payroll_total']); ?></td>
            <td class="ct" id="ft_out"><?php echo fd($T['outstanding']); ?></td>
            <td class="no-print"></td>
        </tr>
      </tfoot>
    </table>
    </div>
</div>
<?php endif; ?>
</div><!-- /pg -->

<!-- ══════════════════════════════════════════════════════════
     PAYROLL MODAL
══════════════════════════════════════════════════════════ -->
<div id="pmBg" onclick="if(event.target===this)closePM()">
  <div class="pm-box">
    <div class="pm-hd">
        <div class="pm-hd-ico"><i class="fa-solid fa-wallet"></i></div>
        <div class="pm-hd-info">
            <div class="pm-hd-name" id="pmName">Employee</div>
            <div class="pm-hd-sub">Item Shortage &rarr; Payroll Deduction</div>
        </div>
        <button class="pm-cls" onclick="closePM()">&times;</button>
    </div>

    <?php if($active_pp): ?>
    <div class="pm-pb ok">
        <i class="fa-solid fa-circle-dot" style="font-size:8px;animation:pulse 2s infinite;"></i>
        Active Period: &nbsp;<strong><?php echo $MN[$active_pp['month']].' '.$active_pp['year']; ?></strong>
        &nbsp;&middot;&nbsp;
        <?php echo date('d M Y',strtotime($active_pp['open_date'])); ?> &ndash;
        <?php echo date('d M Y',strtotime($active_pp['close_date'])); ?>
    </div>
    <?php else: ?>
    <div class="pm-pb no">
        <i class="fa-solid fa-triangle-exclamation"></i> No open payroll period found.
    </div>
    <?php endif; ?>

    <div class="pm-cards">
        <div class="pm-card c1">
            <div class="pm-cl">Charge to Emp</div>
            <div class="pm-cv" id="pmCharged">—</div>
        </div>
        <div class="pm-card c2">
            <div class="pm-cl">Payroll Deducted</div>
            <div class="pm-cv" id="pmPrl">—</div>
        </div>
        <div class="pm-card c3">
            <div class="pm-cl">Net Shortage</div>
            <div class="pm-cv" id="pmNet">—</div>
        </div>
        <div class="pm-card c4" id="pmBalCard">
            <div class="pm-cl">Outstanding</div>
            <div class="pm-cv" id="pmOut">—</div>
        </div>
    </div>

    <div class="pm-sec">
        <div class="pm-sl">
            <i class="fa-solid fa-plus-circle" style="color:var(--prp);"></i>
            New Charge
        </div>
        <div class="pm-g2">
            <div class="pm-fg">
                <label class="pm-lb">Payroll Period</label>
                <select id="pmPeriod" style="width:100%;">
                    <option value="">— Select Period —</option>
                    <?php foreach($pp_all as $pp): ?>
                    <option value="<?php echo $pp['id']; ?>"
                            data-year="<?php echo $pp['year']; ?>"
                            data-month="<?php echo $pp['month']; ?>"
                            data-status="<?php echo $pp['status']; ?>"
                            data-open="<?php echo $pp['open_date']; ?>"
                            data-close="<?php echo $pp['close_date']; ?>"
                            <?php echo ($active_pp&&$pp['id']==$active_pp['id'])?'selected':''; ?>>
                        <?php echo htmlspecialchars($pp['label']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pm-fg">
                <label class="pm-lb">Charge Date</label>
                <input type="date" id="pmDate" class="pm-in mn" value="<?php echo date('Y-m-d'); ?>">
            </div>
        </div>
        <div class="pm-g2" style="margin-bottom:16px;">
            <div class="pm-fg">
                <label class="pm-lb">Amount (Rs.)</label>
                <input type="number" id="pmAmt" class="pm-in mn" step="0.01" min="0.01" placeholder="0.00">
            </div>
            <div class="pm-fg">
                <label class="pm-lb">Description</label>
                <input type="text" id="pmDesc" class="pm-in" value="item shortage deduction" maxlength="200">
            </div>
        </div>
        <button class="btn-sv" id="pmSaveBtn" onclick="saveCharge()">
            <i class="fa-solid fa-floppy-disk"></i> Save Charge
        </button>
    </div>

    <div class="pm-hist">
        <div class="pm-hl">
            <i class="fa-solid fa-clock-rotate-left" style="color:var(--txm);"></i>
            Charge History
        </div>
        <div id="pmList">
            <div class="pm-spin"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>
        </div>
    </div>
  </div>
</div>

<div id="ecst"></div>

<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script>
if(typeof jQuery==='undefined'){
    document.write('<scr'+'ipt src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"><\/scr'+'ipt>');
}
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script>
/* ── GLOBALS ── */
var ROWS = <?php echo json_encode($js_rows,JSON_UNESCAPED_UNICODE); ?>;
var AP   = <?php echo json_encode($ap_js); ?>;
var MN   = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
var curIdx = -1;

function n(v){ return parseFloat(v)||0; }
function fa(v){ return Math.abs(n(v)).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function safeFetch(url,opts){
    return fetch(url,opts).then(function(r){
        return r.text().then(function(t){
            try{ return JSON.parse(t); }
            catch(e){ throw new Error('Server: '+t.replace(/<[^>]+>/g,' ').trim().substring(0,300)); }
        });
    });
}

/* ── EXPAND / COLLAPSE ── */
function toggleDetail(idx){
    var sr  = document.getElementById('sr-'+idx);
    var dhs = document.querySelectorAll('[id^="dh-'+idx+'"]');
    var dds = document.querySelectorAll('[id^="dd-'+idx+'-"]');
    var open = sr.classList.contains('open');
    sr.classList.toggle('open',!open);
    dhs.forEach(function(el){ el.classList.toggle('show',!open); });
    dds.forEach(function(el){ el.classList.toggle('show',!open); });
}
function expandAll(){
    document.querySelectorAll('#tBody tr.sr').forEach(function(sr){
        var idx = sr.id.replace('sr-','');
        if(!sr.classList.contains('open')) toggleDetail(parseInt(idx));
    });
}
function collapseAll(){
    document.querySelectorAll('#tBody tr.sr').forEach(function(sr){
        var idx = sr.id.replace('sr-','');
        if(sr.classList.contains('open')) toggleDetail(parseInt(idx));
    });
}

/* ── SEARCH ── */
function doSearch(){
    var q = (document.getElementById('srch').value||'').toLowerCase().trim();
    var vis=0;
    document.querySelectorAll('#tBody tr.sr').forEach(function(sr){
        var emp = (sr.dataset.emp||'');
        var show = !q || emp.includes(q);
        sr.style.display = show?'':'none';
        var idx = sr.id.replace('sr-','');
        /* also hide/show detail rows for this employee */
        document.querySelectorAll('[id^="dh-'+idx+'"]').forEach(function(el){ el.style.display=show&&el.classList.contains('show')?'':'none'; });
        document.querySelectorAll('[id^="dd-'+idx+'-"]').forEach(function(el){ el.style.display=show&&el.classList.contains('show')?'table-row':'none'; });
        if(show) vis++;
    });
    var vc=document.getElementById('vis-c');
    if(vc) vc.textContent=vis+' / '+ROWS.length+' employees';
}

/* ── OPEN PAYROLL MODAL ── */
function openPM(idx){
    curIdx = idx;
    var r = ROWS[idx];
    document.getElementById('pmBg').style.display='flex';
    document.getElementById('pmName').textContent = r.emp_name;
    renderCards(r.total_charged, r.payroll_total, r.net_shortage_val, r.outstanding);
    var sug = n(r.outstanding);
    document.getElementById('pmAmt').value = sug>0?sug.toFixed(2):'';
    if(AP){
        $('#pmPeriod').val(AP.id).trigger('change');
        var di=document.getElementById('pmDate');
        di.min=AP.open_date; di.max=AP.close_date; di.value=AP.close_date;
    }
    loadCharges(r.emp_name);
}
function closePM(){
    document.getElementById('pmBg').style.display='none';
    curIdx=-1;
}
function renderCards(charged,prl,net,out){
    document.getElementById('pmCharged').textContent='Rs. '+fa(charged);
    document.getElementById('pmPrl').textContent    ='Rs. '+fa(prl);
    document.getElementById('pmNet').textContent    ='Rs. '+fa(net);
    document.getElementById('pmOut').textContent    ='Rs. '+fa(Math.abs(out))+(n(out)<0?' (CR)':'');
    var bc=document.getElementById('pmBalCard');
    if(n(out)<=0.005) bc.classList.add('ok'); else bc.classList.remove('ok');
}

/* ── LOAD HISTORY ── */
function loadCharges(ename){
    var list=document.getElementById('pmList');
    list.innerHTML='<div class="pm-spin"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    safeFetch('employee_charge_shortage_report.php?action=get_charges&employee_name='+encodeURIComponent(ename))
        .then(function(d){ renderCharges(d.charges,ename); })
        .catch(function(e){ list.innerHTML='<div class="pm-spin" style="color:var(--red);">'+esc(e.message)+'</div>'; });
}
function renderCharges(charges,ename){
    var list=document.getElementById('pmList');
    if(!charges||!charges.length){
        list.innerHTML='<div class="pm-nh"><i class="fa-solid fa-inbox" style="font-size:28px;display:block;margin-bottom:8px;opacity:.2;"></i>No charges recorded yet.</div>';
        return;
    }
    var html='';
    charges.forEach(function(c){
        var per=(c.payroll_year&&c.payroll_month)?MN[parseInt(c.payroll_month)]+' '+c.payroll_year:'— No Period —';
        html+='<div class="pm-ci" id="ci_'+c.id+'">'
            +'<div class="pm-ci-l">'
            +'<div class="pm-ci-p"><i class="fa-solid fa-calendar-check" style="font-size:9px;"></i> '+esc(per)+'</div>'
            +'<div class="pm-ci-d">'+esc(c.charge_date||'')+'</div>'
            +'<div class="pm-ci-x">'+esc(c.description||'item shortage deduction')+'</div>'
            +'</div>'
            +'<div class="pm-ci-a">Rs. '+fa(c.amount)+'</div>'
            +'<button class="btn-dc" onclick="delCharge('+c.id+',\''+esc(ename)+'\')" title="Delete">'
            +'<i class="fa-solid fa-trash"></i></button>'
            +'</div>';
    });
    list.innerHTML=html;
}

/* ── SAVE CHARGE ── */
function saveCharge(){
    if(curIdx<0) return;
    var r    = ROWS[curIdx];
    var pSel = document.getElementById('pmPeriod');
    var opt  = pSel.options[pSel.selectedIndex];
    var ppid = pSel.value||'';
    var ppY  = ppid?($(opt).data('year')||0):0;
    var ppM  = ppid?($(opt).data('month')||0):0;
    var amt  = n(document.getElementById('pmAmt').value);
    var cd   = document.getElementById('pmDate').value;
    var desc = (document.getElementById('pmDesc').value||'').trim()||'item shortage deduction';
    if(!amt||amt<=0){ toast('Enter a valid amount.','er'); return; }
    if(!cd)         { toast('Select a charge date.','er'); return; }
    var btn=document.getElementById('pmSaveBtn');
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    safeFetch('employee_charge_shortage_report.php',{
        method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({
            action:'save_charge', employee_name:r.emp_name,
            payroll_period_id:ppid, payroll_year:ppY, payroll_month:ppM,
            amount:amt.toFixed(2), charge_date:cd, description:desc
        }).toString()
    }).then(function(res){
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Charge';
        if(res.success){
            updateRow(curIdx,res.new_total);
            loadCharges(r.emp_name);
            document.getElementById('pmAmt').value='';
            toast('Charge saved!','ok');
        } else { toast('Error: '+(res.message||'Unknown'),'er'); }
    }).catch(function(e){ btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Charge'; toast(e.message,'er'); });
}

/* ── DELETE CHARGE ── */
function delCharge(cid,ename){
    if(!confirm('Delete this charge?')) return;
    var btn=document.querySelector('#ci_'+cid+' .btn-dc');
    if(btn){ btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>'; }
    safeFetch('employee_charge_shortage_report.php',{
        method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({action:'delete_charge',charge_id:cid,employee_name:ename}).toString()
    }).then(function(res){
        if(res.success){
            if(curIdx>=0){ updateRow(curIdx,res.new_total); loadCharges(ename); }
            toast('Charge deleted.','ok');
        } else { toast('Error: '+(res.message||''),'er'); if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-trash"></i>';} }
    }).catch(function(e){ toast(e.message,'er'); if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-trash"></i>';} });
}

/* ── UPDATE ROW DOM ── */
function updateRow(idx,newPrl){
    var r   = ROWS[idx];
    var newOut = n(r.total_charged) - n(newPrl);
    ROWS[idx].payroll_total = newPrl;
    ROWS[idx].outstanding   = newOut;

    var outCls = newOut<-0.005?'bal-n':(newOut>0.005?'bal-p':'bal-0');
    var outEl=document.getElementById('out-'+idx);
    if(outEl) outEl.innerHTML='<span class="'+outCls+'">'+(newOut<0?'-':'')+fa(Math.abs(newOut))+'</span>';

    var prlEl=document.getElementById('prl-'+idx);
    if(prlEl) prlEl.innerHTML=n(newPrl)>0.005
        ?'<span class="prl-b"><i class="fa-solid fa-wallet" style="font-size:9px;"></i>&nbsp;'+fa(newPrl)+'</span>'
        :'<span class="v-s">—</span>';

    var rowEl=document.getElementById('sr-'+idx);
    if(rowEl){ var pb=rowEl.querySelector('.btn-py');
        if(pb){ if(n(newPrl)>0.005){pb.classList.add('done');pb.innerHTML='<i class="fa-solid fa-check-circle"></i> Payroll';}
                else{pb.classList.remove('done');pb.innerHTML='<i class="fa-solid fa-wallet"></i> Payroll';} } }

    renderCards(r.total_charged,newPrl,r.net_shortage_val,newOut);
    document.getElementById('pmAmt').value=newOut>0?newOut.toFixed(2):'';
    refreshFoot();
}

/* ── FOOTER TOTALS ── */
function refreshFoot(){
    var tp=0,to=0;
    ROWS.forEach(function(r){ tp+=n(r.payroll_total); to+=n(r.outstanding); });
    ['ft_prl','hd_prl'].forEach(function(id){ var el=document.getElementById(id); if(el) el.textContent=tp===0?'—':fa(tp); });
    ['ft_out','hd_out'].forEach(function(id){ var el=document.getElementById(id); if(el) el.textContent=to===0?'—':fa(to); });
}

/* ── EXCEL EXPORT ── */
function exportXL(){
    var hdr=['#','Employee','SKUs','Records','Short Qty','Short Value',
             'Excess Qty','Excess Value','Charge to Emp','Absorb by Co.','Payroll Deducted','Outstanding'];
    var data=[];
    ROWS.forEach(function(r,i){
        data.push([i+1,r.emp_name,
            /* get from DOM */
            parseInt(document.querySelector('#sr-'+i+' td:nth-child(3)')?.textContent)||0,
            parseInt(document.querySelector('#sr-'+i+' td:nth-child(4)')?.textContent)||0,
            0,0,0,0,
            n(r.total_charged),0,n(r.payroll_total),n(r.outstanding)
        ]);
    });
    /* fill from PHP-rendered totals row values for short/excess */
    <?php
    $xl_js='var xlFull=[';
    foreach($sum_rows as $i=>$sr){
        $xl_js.='['.$i.','.floatval($sr['total_short_qty']).','.floatval($sr['total_short_val']).','.floatval($sr['total_excess_qty']).','.floatval($sr['total_excess_val']).','.floatval($sr['total_absorbed']).'],';
    }
    $xl_js.='];';
    echo $xl_js;
    ?>
    xlFull.forEach(function(x){ if(data[x[0]]){ data[x[0]][4]=x[1];data[x[0]][5]=x[2];data[x[0]][6]=x[3];data[x[0]][7]=x[4];data[x[0]][9]=x[5]; } });
    var ws=XLSX.utils.aoa_to_sheet([hdr].concat(data));
    ws['!cols']=[4,28,6,6,10,14,10,14,16,16,16,14].map(function(w){return{wch:w};});
    var wb=XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb,ws,'Emp Charge Report');
    XLSX.writeFile(wb,'Employee_Charge_Report_<?php echo date("d-M-Y",strtotime($date_from)).'_to_'.date("d-M-Y",strtotime($date_to)); ?>.xlsx');
    toast('Exported!','ok');
}

/* ── TOAST ── */
function toast(msg,type){
    var t=document.getElementById('ecst');
    t.className=type==='ok'?'tok':'ter';
    t.textContent=msg; t.style.display='block'; t.style.opacity='1';
    clearTimeout(t._t); t._t=setTimeout(function(){ t.style.opacity='0'; setTimeout(function(){t.style.display='none';},300); },3500);
}

/* ── INIT ── */
document.addEventListener('DOMContentLoaded',function(){
    if(typeof $!=='undefined'){
        $('#sel_p').select2({placeholder:'— All Persons —',allowClear:true,width:'210px'});
        $('#pmPeriod').select2({
            placeholder:'— Select Period —',allowClear:true,width:'100%',dropdownParent:$('#pmBg'),
            templateResult:function(o){ if(!o.id) return o.text;
                var s=$(o.element).data('status');
                return $('<span>').text(o.text).css({color:s==='Open'?'#38a169':'#718096',fontWeight:s==='Open'?'700':'400'});
            }
        });
        $('#pmPeriod').on('change',function(){
            var o=$(this).find(':selected');
            var od=o.data('open'),cd=o.data('close');
            var di=document.getElementById('pmDate');
            if(od&&cd){ di.min=od;di.max=cd;if(!di.value||di.value<od||di.value>cd) di.value=cd; }
            else{ di.min='';di.max=''; }
        });
    }
    var f=document.getElementById('ecForm');
    if(f) f.addEventListener('submit',function(){
        var b=document.querySelector('.btn-go');
        if(b){b.disabled=true;b.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Loading…';}
    });
    doSearch();
});
</script>
<?php include 'footer.php'; ?>