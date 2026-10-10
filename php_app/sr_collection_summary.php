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

/* ── Ensure tables exist ── */
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_from (from_sr_code, delivery_date),
    INDEX idx_to   (to_sr_code,   delivery_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Ensure saved reports table exists ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cash_collection_saved_reports (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    report_name     VARCHAR(255)  NOT NULL,
    report_type     VARCHAR(50)   NOT NULL DEFAULT 'daily',
    date_from       DATE          NOT NULL,
    date_to         DATE          NOT NULL,
    sr_code         VARCHAR(50)   NULL,
    saved_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    saved_by        VARCHAR(100)  NULL,
    total_sinv      DECIMAL(14,2) DEFAULT 0.00,
    total_cc        DECIMAL(14,2) DEFAULT 0.00,
    total_sr        DECIMAL(14,2) DEFAULT 0.00,
    total_coll      DECIMAL(14,2) DEFAULT 0.00,
    total_banked    DECIMAL(14,2) DEFAULT 0.00,
    total_handed    DECIMAL(14,2) DEFAULT 0.00,
    total_short     DECIMAL(14,2) DEFAULT 0.00,
    row_count       INT           DEFAULT 0,
    report_json     LONGTEXT      NOT NULL,
    INDEX idx_dates (date_from, date_to),
    INDEX idx_saved (saved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$col = mysqli_query($conn, "SHOW COLUMNS FROM cash_collection_saved_reports LIKE 'report_type'");
if ($col && mysqli_num_rows($col) === 0) {
    mysqli_query($conn, "ALTER TABLE cash_collection_saved_reports ADD COLUMN report_type VARCHAR(50) NOT NULL DEFAULT 'daily' AFTER report_name");
}

/* ── Lookups ── */
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
if (!$er || mysqli_num_rows($er) === 0)
    $er = mysqli_query($conn, "SELECT id,
     COALESCE(NULLIF(employee_id,''),CONCAT('EMP-',id)) AS emp_code,
     COALESCE(NULLIF(name_with_initials,''),NULLIF(employee_full_name,''),CONCAT('Employee #',id)) AS emp_name
     FROM employees ORDER BY emp_name ASC LIMIT 500");
if ($er) while ($row = mysqli_fetch_assoc($er)) $emp_list[] = $row;

/* ═══════════ DATA ═══════════ */
$rows   = [];
$totals = array_fill_keys([
    'sinv',
    'cash_paid','cheque_paid','credit','pay_diff',
    'sr_daily_sale','sr_rcvd_credit','sr_rcvd_rtn_chq','sr_rcvd_rtn_chgs','sr_rcvd_sent_back','sr_total',
    'total_coll',
    'banked_sr','handed_sr',
    'cash_short','cash_excess',
    't_out','t_in','shortage_adj','new_short_excess',
    'p_charge','p_absorb'
], 0.0);

if ($submitted) {
    $where_fs = "fs.delivery_date BETWEEN '$df' AND '$dt'" . ($sr_esc ? " AND fs.sr_code='$sr_esc'" : '');
    $sid_w    = "sid.delivery_date BETWEEN '$df' AND '$dt' AND sid.status='imported'" . ($sr_esc ? " AND sid.sales_person_code='$sr_esc'" : '');
    $srCnd    = $sr_esc ? "AND fs.sr_code='$sr_esc'" : '';

    $r1 = mysqli_query($conn, "SELECT DISTINCT fs.sr_code FROM field_summary fs WHERE $where_fs AND fs.sr_code IS NOT NULL AND fs.sr_code!='' ORDER BY fs.sr_code");
    $master = [];
    if ($r1) while ($r = mysqli_fetch_assoc($r1)) $master[] = $r['sr_code'];

    $r3 = mysqli_query($conn, "SELECT sid.sales_person_code AS sr_code, COALESCE(SUM(sid.final_bill_amount),0) AS sinv FROM secondary_invoice_import_details sid WHERE $sid_w GROUP BY sid.sales_person_code");
    $sinv_map = [];
    if ($r3) while ($r = mysqli_fetch_assoc($r3)) $sinv_map[$r['sr_code']] = floatval($r['sinv']);

    $r4 = mysqli_query($conn, "SELECT fs.sr_code,
        COALESCE(SUM(CASE WHEN ip.payment_method='cash'   AND ip.is_reversed=0 THEN ip.amount ELSE 0 END),0) AS cash_paid,
        COALESCE(SUM(CASE WHEN ip.payment_method='cheque' AND ip.is_reversed=0 THEN ip.amount ELSE 0 END),0) AS cheque_paid
        FROM invoice_payments ip INNER JOIN field_summary fs ON fs.id=ip.field_summary_id
        WHERE $where_fs GROUP BY fs.sr_code");
    $invpay_map = [];
    if ($r4) while ($r = mysqli_fetch_assoc($r4)) $invpay_map[$r['sr_code']] = $r;

    $paid_by_det = [];
    $rpd = mysqli_query($conn, "SELECT ip.field_summary_detail_id, ROUND(COALESCE(SUM(ip.amount),0),2) AS paid
        FROM invoice_payments ip INNER JOIN field_summary fs ON fs.id=ip.field_summary_id
        WHERE $where_fs AND ip.payment_date <= fs.delivery_date GROUP BY ip.field_summary_detail_id");
    if ($rpd) while ($r = mysqli_fetch_assoc($rpd)) $paid_by_det[intval($r['field_summary_detail_id'])] = floatval($r['paid']);

    $r5 = mysqli_query($conn, "SELECT fs.sr_code, fsd.id AS det_id, fsd.adjust_net_value AS row_adj
        FROM field_summary_details fsd INNER JOIN field_summary fs ON fs.id=fsd.field_summary_id WHERE $where_fs");
    $credit_map = [];
    if ($r5) while ($r = mysqli_fetch_assoc($r5)) {
        $row_bal = max(0.0, floatval($r['row_adj']) - ($paid_by_det[intval($r['det_id'])] ?? 0.0));
        if (!isset($credit_map[$r['sr_code']])) $credit_map[$r['sr_code']] = 0.0;
        $credit_map[$r['sr_code']] += $row_bal;
    }

    $r6 = mysqli_query($conn, "
        SELECT fs.sr_code,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND (ip.payment_source IS NULL OR ip.payment_source='' OR LOWER(ip.payment_source)='invoice') THEN ip.amount ELSE 0 END),0) AS sr_daily_sale,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND LOWER(ip.payment_source) LIKE '%credit%'                   THEN ip.amount ELSE 0 END),0) AS sr_rcvd_credit,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND ip.payment_source='return_cheque_settlement'               THEN ip.amount ELSE 0 END),0) AS sr_rcvd_rtn_chq,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND ip.payment_source='return_charge_settlement'               THEN ip.amount ELSE 0 END),0) AS sr_rcvd_rtn_chgs,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND ip.payment_source='sentback_cheque_settlement'             THEN ip.amount ELSE 0 END),0) AS sr_rcvd_sent_back,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc'                                                                THEN ip.amount ELSE 0 END),0) AS sr_total
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id=ip.field_summary_detail_id
        INNER JOIN field_summary fs ON fs.id=fsd.field_summary_id
        WHERE ip.payment_method='cash' AND ip.is_reversed=0
          AND ip.payment_date BETWEEN '$df' AND '$dt' $srCnd
        GROUP BY fs.sr_code");
    $cash_map = [];
    if ($r6) while ($r = mysqli_fetch_assoc($r6)) $cash_map[$r['sr_code']] = $r;

    $dep_date_cnd = "r.delivery_date BETWEEN '$df' AND '$dt'";
    $dep_rep_cnd  = $sr_esc ? " AND r.rep_code='$sr_esc'" : '';
    $r7 = mysqli_query($conn, "
        SELECT r.rep_code,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=0 AND LOWER(COALESCE(d.collected_by,''))='sr' THEN r.amount ELSE 0 END),0) AS banked_sr,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=1 AND LOWER(COALESCE(d.collected_by,''))='sr' THEN r.amount ELSE 0 END),0) AS handed_sr
        FROM cc_cash_deposit_reps r
        INNER JOIN cc_cash_deposits d ON d.id = r.deposit_id
        WHERE $dep_date_cnd $dep_rep_cnd
        GROUP BY r.rep_code");
    $dep_map = [];
    if ($r7) while ($r = mysqli_fetch_assoc($r7)) $dep_map[$r['rep_code']] = $r;

    $r8 = mysqli_query($conn,
        "SELECT sr_code, entry_type, employee_id, employee_name, amount, total_value
         FROM cash_summary_pay_allocations
         WHERE pay_date BETWEEN '$df' AND '$dt'"
        . ($sr_esc ? " AND sr_code='$sr_esc'" : '')
        . " ORDER BY sr_code, entry_type, id");
    $alloc_map = [];
    if ($r8) while ($r = mysqli_fetch_assoc($r8)) {
        $k = $r['sr_code'];
        if (!isset($alloc_map[$k]))
            $alloc_map[$k] = ['p_charge'=>0,'p_absorb'=>0,'p_variance'=>null,'employees'=>[],'is_paid'=>false];
        if ($r['entry_type'] === 'charge') {
            $alloc_map[$k]['p_charge'] += floatval($r['amount']);
            $alloc_map[$k]['is_paid']   = true;
            $alloc_map[$k]['employees'][] = ['employee_id'=>intval($r['employee_id']),'employee_name'=>$r['employee_name'],'amount'=>floatval($r['amount'])];
        }
        if ($r['entry_type'] === 'absorb') { $alloc_map[$k]['p_absorb'] = floatval($r['amount']); $alloc_map[$k]['is_paid'] = true; }
        if ($r['entry_type'] === 'variance') $alloc_map[$k]['p_variance'] = floatval($r['amount']);
    }

    $tr_cnd = "delivery_date BETWEEN '$df' AND '$dt'";
    if ($sr_esc) $tr_cnd .= " AND (from_sr_code='$sr_esc' OR to_sr_code='$sr_esc')";
    $r9 = mysqli_query($conn, "SELECT id, from_sr_code, to_sr_code, delivery_date, transfer_date, amount, note FROM cash_shortage_transfers WHERE $tr_cnd ORDER BY id");
    $transfer_out_map = []; $transfer_in_map = []; $transfer_rows_map = [];
    if ($r9) while ($r = mysqli_fetch_assoc($r9)) {
        $kf = $r['from_sr_code']; $kt = $r['to_sr_code'];
        $transfer_out_map[$kf] = ($transfer_out_map[$kf] ?? 0) + floatval($r['amount']);
        $transfer_in_map[$kt]  = ($transfer_in_map[$kt]  ?? 0) + floatval($r['amount']);
        if (!isset($transfer_rows_map[$kf])) $transfer_rows_map[$kf] = [];
        if (!isset($transfer_rows_map[$kt])) $transfer_rows_map[$kt] = [];
        $transfer_rows_map[$kf][] = ['id'=>intval($r['id']),'direction'=>'out','other_sr'=>$r['to_sr_code'],'delivery_date'=>$r['delivery_date'],'transfer_date'=>$r['transfer_date'],'amount'=>floatval($r['amount']),'note'=>$r['note']];
        $transfer_rows_map[$kt][] = ['id'=>intval($r['id']),'direction'=>'in', 'other_sr'=>$r['from_sr_code'],'delivery_date'=>$r['delivery_date'],'transfer_date'=>$r['transfer_date'],'amount'=>floatval($r['amount']),'note'=>$r['note']];
    }

    foreach ($master as $sr_code) {
        $pm = $invpay_map[$sr_code] ?? [];
        $dm = $dep_map[$sr_code]    ?? [];
        $cm = $cash_map[$sr_code]   ?? [];

        $sinv        = floatval($sinv_map[$sr_code] ?? 0);
        $cash_paid   = floatval($pm['cash_paid']    ?? 0);
        $cheque_paid = floatval($pm['cheque_paid']  ?? 0);
        $credit      = floatval($credit_map[$sr_code] ?? 0);
        $pay_diff    = $sinv - ($cash_paid + $cheque_paid + $credit);

        $sr_daily_sale     = floatval($cm['sr_daily_sale']     ?? 0);
        $sr_rcvd_credit    = floatval($cm['sr_rcvd_credit']    ?? 0);
        $sr_rcvd_rtn_chq   = floatval($cm['sr_rcvd_rtn_chq']  ?? 0);
        $sr_rcvd_rtn_chgs  = floatval($cm['sr_rcvd_rtn_chgs'] ?? 0);
        $sr_rcvd_sent_back = floatval($cm['sr_rcvd_sent_back'] ?? 0);
        $sr_total          = floatval($cm['sr_total']          ?? 0);

        $banked_sr  = floatval($dm['banked_sr'] ?? 0);
        $handed_sr  = floatval($dm['handed_sr'] ?? 0);
        $total_coll = $sr_total;

        $bank_diff  = $sr_total - ($banked_sr + $handed_sr);
        $cash_short = $bank_diff > 0.005 ? $bank_diff : 0;
        $cash_excess = 0;
        $bank_diff_for_balance = $cash_short;

        $t_out = floatval($transfer_out_map[$sr_code] ?? 0);
        $t_in  = floatval($transfer_in_map[$sr_code]  ?? 0);
        $shortage_adj = $t_out - $t_in;

        $new_short_excess = max(0, $bank_diff_for_balance - $t_out + $t_in);

        $t_rows = $transfer_rows_map[$sr_code] ?? [];

        $pa          = $alloc_map[$sr_code] ?? [];
        $p_charge    = floatval($pa['p_charge']  ?? 0);
        $p_absorb    = floatval($pa['p_absorb']  ?? 0);
        $p_variance  = isset($pa['p_variance']) ? floatval($pa['p_variance']) : null;
        $p_employees = $pa['employees'] ?? [];
        $is_paid     = (bool)($pa['is_paid'] ?? false);
        $del_date    = $date_from . ' ~ ' . $date_to;

        $rows[] = compact(
            'sr_code','del_date','sinv',
            'cash_paid','cheque_paid','credit','pay_diff',
            'sr_daily_sale','sr_rcvd_credit','sr_rcvd_rtn_chq','sr_rcvd_rtn_chgs','sr_rcvd_sent_back','sr_total',
            'total_coll','banked_sr','handed_sr',
            'bank_diff','cash_short','cash_excess',
            't_out','t_in','shortage_adj','new_short_excess','t_rows',
            'p_charge','p_absorb','p_variance','p_employees','is_paid'
        );

        $totals['sinv']              += $sinv;
        $totals['cash_paid']         += $cash_paid;
        $totals['cheque_paid']       += $cheque_paid;
        $totals['credit']            += $credit;
        $totals['pay_diff']          += $pay_diff;
        $totals['sr_daily_sale']     += $sr_daily_sale;
        $totals['sr_rcvd_credit']    += $sr_rcvd_credit;
        $totals['sr_rcvd_rtn_chq']   += $sr_rcvd_rtn_chq;
        $totals['sr_rcvd_rtn_chgs']  += $sr_rcvd_rtn_chgs;
        $totals['sr_rcvd_sent_back'] += $sr_rcvd_sent_back;
        $totals['sr_total']          += $sr_total;
        $totals['total_coll']        += $total_coll;
        $totals['banked_sr']         += $banked_sr;
        $totals['handed_sr']         += $handed_sr;
        $totals['cash_short']        += $cash_short;
        $totals['cash_excess']       += $cash_excess;
        $totals['t_out']             += $t_out;
        $totals['t_in']              += $t_in;
        $totals['shortage_adj']      += $shortage_adj;
        $totals['new_short_excess']  += $new_short_excess;
        $totals['p_charge']          += $p_charge;
        $totals['p_absorb']          += $p_absorb;
    }
}

/* ── Helper renderers ── */
function cv($v){$n=floatval($v);return $n==0?'<span class="dash">—</span>':'<span class="num">'.number_format($n,2).'</span>';}
function ct($v){$n=floatval($v);return $n==0?'—':number_format($n,2);}
function c_short($v){$n=floatval($v);return $n<=0?'<span class="dash">—</span>':'<span class="sv">'.number_format($n,2).'</span>';}
function c_excess($v){$n=floatval($v);return $n<=0?'<span class="dash">—</span>':'<span class="ev">'.number_format($n,2).'</span>';}
function cc_se($v){$n=floatval($v);if(abs($n)<0.005)return '<span class="d-ok">0.00 ✓</span>';if($n<0)return '<span class="d-exc">▲ '.number_format(abs($n),2).'</span>';return '<span class="d-sht">▼ '.number_format($n,2).'</span>';}
function cpvar($v){if($v===null)return '<span style="color:#d1d5db;">—</span>';$v=floatval($v);if(abs($v)<0.005)return '<span class="pv-ok">0.00</span>';if($v<0)return '<span class="pv-exc-pay">'.number_format($v,2).'</span>';return '<span class="pv-sht-pay">+'.number_format($v,2).'</span>';}
function srlink($v,$sr,$df,$dt,$src=''){$n=floatval($v);if($n==0)return '<span class="dash">—</span>';$p=['sr_code'=>$sr,'date_from'=>$df,'date_to'=>$dt,'method'=>'cash','collected_by'=>'sr'];if($src!=='')$p['source']=$src;return '<a href="payment_details.php?'.http_build_query($p).'" target="_blank" class="clink sr"><span class="num">'.number_format($n,2).'</span><i class="fa-solid fa-arrow-up-right-from-square clink-ico"></i></a>';}
function sdep_link($v,$sr,$df,$dt,$type){$n=floatval($v);if($n==0)return '<span class="dash">—</span>';$params=['sr_code'=>$sr,'date_from'=>$df,'date_to'=>$dt,'type'=>$type,'collected_by'=>'sr'];$cls=$type==='bank'?'dep-link bank':'dep-link bo';return '<a href="cc_deposit_history.php?'.http_build_query($params).'" target="_blank" class="'.$cls.'"><span class="num">'.number_format($n,2).'</span><i class="fa-solid fa-clock-rotate-left dep-link-ico"></i></a>';}
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap');
@import url('https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
:root{
  --bg:#f0f4f8;--surface:#ffffff;--bdr:#d0d7e2;--bdrs:#e8edf4;
  --tx:#111827;--txm:#4b5563;--txs:#9ca3af;
  --fn:'Sora',sans-serif;--mn:'JetBrains Mono',monospace;--r:10px;
  --sh:0 1px 3px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.04);
  --hsr:#0f766e;--hsrS:#0d6462;
  --hdep:#065f46;--hdepS:#054f38;
  --hshort:#991b1b;--hshortS:#7f1d1d;
  --hrec:#5b21b6;--hrecS:#4c1d95;
  --hvar:#374151;--hvarS:#1f2937;
  --htotal:#0f172a;
  --sinv:#1e4d8c;--sinvS:#16408a;
  --h0:#1e293b;--h0S:#334155;
  --tr-col:#0c4a6e;--new-col:#312e81;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:12px;}
.pg{padding:14px 14px 44px;}
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px;}
.pg-h1{font-size:20px;font-weight:800;letter-spacing:-.025em;color:var(--tx);}
.pg-h1 em{color:var(--hsr);font-style:normal;}
.pg-sub{font-size:10px;color:var(--txs);margin-top:2px;}
.badge-sr{display:inline-flex;align-items:center;gap:5px;background:#ccfbf1;border:1px solid #5eead4;border-radius:20px;padding:3px 11px;font-size:11px;font-weight:700;color:#0f766e;font-family:var(--mn);}
.dpill{display:inline-flex;align-items:center;gap:5px;background:#f0fdf4;border:1px solid #86efac;border-radius:20px;padding:4px 11px;font-size:11px;font-weight:700;color:#166534;font-family:var(--mn);}
.fbar{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:9px 14px;margin-bottom:12px;display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;box-shadow:var(--sh);}
.fg{display:flex;flex-direction:column;gap:3px;}
.fg label{font-size:10px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.07em;}
.fg input,.fg select{padding:6px 10px;border:1.5px solid var(--bdr);border-radius:7px;font-size:12px;font-family:var(--fn);color:var(--tx);background:#fff;transition:border .15s;}
.fg input:focus,.fg select:focus{outline:none;border-color:var(--hsr);}
.btn-go{display:inline-flex;align-items:center;gap:5px;padding:8px 18px;background:var(--hsr);color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:700;font-family:var(--fn);cursor:pointer;}
.btn-go:hover{background:#0d6462;}
.btn-rst{display:inline-flex;align-items:center;gap:4px;padding:8px 12px;background:#f1f5f9;color:var(--txm);border:1px solid var(--bdr);border-radius:7px;font-size:12px;font-weight:600;font-family:var(--fn);cursor:pointer;text-decoration:none;}
.btn-excel{display:inline-flex;align-items:center;gap:5px;padding:8px 14px;background:linear-gradient(135deg,#166534,#15803d);color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:700;font-family:var(--fn);cursor:pointer;box-shadow:0 2px 8px rgba(22,101,52,.28);transition:all .2s;}
.btn-excel:hover{transform:translateY(-1px);}
.btn-save-rep{display:inline-flex;align-items:center;gap:5px;padding:8px 14px;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:700;font-family:var(--fn);cursor:pointer;box-shadow:0 2px 6px rgba(124,58,237,.3);transition:all .2s;}
.btn-save-rep:hover{background:linear-gradient(135deg,#6d28d9,#5b21b6);transform:translateY(-1px);}
.btn-saved-list{display:inline-flex;align-items:center;gap:5px;padding:8px 13px;background:#f5f3ff;color:#5b21b6;border:1px solid #ddd6fe;border-radius:7px;font-size:12px;font-weight:700;font-family:var(--fn);cursor:pointer;text-decoration:none;transition:all .2s;}
.btn-saved-list:hover{background:#ede9fe;}
.sc-row{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;margin-bottom:12px;}
.sc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:9px 12px;box-shadow:var(--sh);border-top:3px solid #e5e7eb;transition:transform .15s;}
.sc:hover{transform:translateY(-2px);}
.sc.teal{border-top-color:#14b8a6;}.sc.green{border-top-color:#22c55e;}.sc.blue{border-top-color:#3b82f6;}.sc.red{border-top-color:#ef4444;}.sc.purple{border-top-color:#a855f7;}.sc.indigo{border-top-color:#6366f1;}
.sc-lbl{font-size:9px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.sc-val{font-size:13px;font-weight:800;font-family:var(--mn);}
.tc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);overflow:hidden;box-shadow:var(--sh);}
.tc-bar{display:flex;justify-content:space-between;align-items:center;padding:8px 14px;border-bottom:1px solid var(--bdrs);flex-wrap:wrap;gap:6px;}
.tc-ttl{font-size:13px;font-weight:700;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.pill{padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;}
.p-teal{background:#ccfbf1;color:#0f766e;}.p-blue{background:#dbeafe;color:#1e40af;}.p-slate{background:#f1f5f9;color:#475569;}
.top-scroll-wrap{overflow-x:auto;overflow-y:hidden;height:10px;margin-bottom:1px;}
.top-scroll-inner{height:1px;}
.tscroll{overflow-x:auto;}

table.cct{width:100%;border-collapse:collapse;font-size:11px;}
.cct .G th{padding:5px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#fff;text-align:center;white-space:nowrap;border-right:2px solid rgba(255,255,255,.18);}
.cct .G th.tl{text-align:left;}.cct .G th:last-child{border-right:none;}
.cct .S th{padding:4px 5px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:rgba(255,255,255,.9);text-align:center;white-space:nowrap;border-right:1px solid rgba(255,255,255,.12);border-bottom:2px solid var(--bdr);}
.cct .S th.tl{text-align:left;}.cct .S th:last-child{border-right:none;}

.G .h0    {background:var(--h0);}.G .hsinv{background:var(--sinv);}
.G .hsr   {background:#0f766e;}.G .hdep {background:var(--hdep);}
.G .hshort{background:var(--hshort);}.G .hrec{background:var(--hrec);}
.G .hvar  {background:var(--hvar);}.G .htr {background:var(--tr-col);}.G .hnew{background:var(--new-col);}
.S .s0   {background:var(--h0S);}.S .ssinv{background:var(--sinvS);}
.S .ssr  {background:#0d6462;}.S .sdep_bank{background:var(--hdepS);}.S .sdep_bo{background:#064e3b;}
.S .sshort{background:var(--hshortS);}
.S .srec_emp{background:#5b21b6;}.S .srec_co{background:#6d28d9;}.S .srec_var{background:#4c1d95;}
.S .svar {background:var(--hvarS);}.S .str{background:#075985;}.S .snew{background:#272069;}

.cct .TH td{background:#e0f2fe;color:#075985;font-weight:800;font-size:10.5px;padding:4px 5px;text-align:right;border-bottom:2px solid #7dd3fc;white-space:nowrap;font-family:var(--mn);}
.cct .TH td.tl{text-align:left;font-family:var(--fn);color:#0c4a6e;}
.cct tbody tr td{background:#fff;}.cct tbody tr.stripe td{background:#f8fbff;}
.cct tbody tr:hover td{background:#ecfdf5!important;}
.cct tbody td{padding:4px 5px;text-align:right;white-space:nowrap;}
.cct tbody td.tl{text-align:left;}.cct tbody td.tc{text-align:center;}
.cct tbody td.rc{font-weight:700;font-size:11px;}.cct tbody td.dt{font-family:var(--mn);font-size:10px;color:var(--txm);}
.cct tbody td.fb{font-weight:700;color:#1e40af;}.cct tbody td.tv{font-weight:700;color:#0f766e;}
.cct tbody td.col-sr       {background:rgba(15,118,110,.05);}
.cct tbody tr.stripe td.col-sr  {background:rgba(15,118,110,.09);}
.cct tbody tr:hover td.col-sr   {background:#ccfbf1!important;}
.cct tbody td.col-dep-bank {background:rgba(6,95,70,.05);}
.cct tbody tr.stripe td.col-dep-bank{background:rgba(6,95,70,.09);}
.cct tbody tr:hover td.col-dep-bank{background:#d1fae5!important;}
.cct tbody td.col-dep-bo   {background:rgba(6,78,59,.05);}
.cct tbody tr.stripe td.col-dep-bo{background:rgba(6,78,59,.09);}
.cct tbody tr:hover td.col-dep-bo{background:#a7f3d0!important;}
.cct tbody td.col-short    {background:rgba(153,27,27,.05);}
.cct tbody tr.stripe td.col-short{background:rgba(153,27,27,.09);}
.cct tbody tr:hover td.col-short{background:#fee2e2!important;}
.cct tbody td.col-tr   {background:rgba(12,74,110,.07);}
.cct tbody tr.stripe td.col-tr{background:rgba(12,74,110,.12);}
.cct tbody tr:hover td.col-tr{background:#e0f2fe!important;}
.cct tbody td.col-new  {background:rgba(49,46,129,.07);}
.cct tbody tr.stripe td.col-new{background:rgba(49,46,129,.12);}
.cct tbody tr:hover td.col-new{background:#ede9fe!important;}
.cct tbody td.col-rec      {background:rgba(91,33,182,.05);}
.cct tbody tr.stripe td.col-rec  {background:rgba(91,33,182,.09);}
.cct tbody tr:hover td.col-rec   {background:#ede9fe!important;}
.cct tbody td.col-var      {background:rgba(55,65,81,.04);}
.cct tbody tr.stripe td.col-var  {background:rgba(55,65,81,.08);}
.cct tbody tr:hover td.col-var   {background:#f1f5f9!important;}
.cct tfoot td{padding:4px 5px;font-weight:800;font-size:11px;background:var(--htotal);color:#e2e8f0;border-top:2px solid #334155;text-align:right;white-space:nowrap;font-family:var(--mn);}
.cct tfoot td.tl{text-align:left;color:#94a3b8;font-family:var(--fn);}
.cct td.stk,.cct th.stk{position:sticky;left:0;z-index:2;box-shadow:3px 0 8px rgba(0,0,0,.09);}
.cct thead th.stk{z-index:4;}
.cct .G th.stk{background:var(--h0);}.cct .S th.stk{background:var(--h0S);}
.cct .TH td.stk{background:#e0f2fe;}.cct tfoot td.stk{background:var(--htotal);}
.cct tbody tr td.stk{background:#fff;}.cct tbody tr.stripe td.stk{background:#f8fbff;}
.cct tbody tr:hover td.stk{background:#ecfdf5!important;}

.num{font-family:var(--mn);font-size:10.5px;}.dash{color:#d1d5db;}
.d-ok{color:#16a34a;font-weight:700;font-family:var(--mn);font-size:10px;}
.d-exc{color:#16a34a;font-weight:700;font-family:var(--mn);font-size:10px;}
.d-sht{color:#dc2626;font-weight:700;font-family:var(--mn);font-size:10px;}
.sv{color:#dc2626;font-weight:700;font-family:var(--mn);}
.ev{color:#1d4ed8;font-weight:700;font-family:var(--mn);}
.adj-in{color:#059669;font-weight:700;font-family:var(--mn);font-size:10.5px;}
.adj-out{color:#d97706;font-weight:700;font-family:var(--mn);font-size:10.5px;}
.pv-ok{color:#16a34a;font-weight:700;font-family:var(--mn);}
.pv-exc-pay{color:#d97706;font-weight:700;font-family:var(--mn);}
.pv-sht-pay{color:#dc2626;font-weight:700;font-family:var(--mn);}
.clink{display:inline-flex;align-items:center;gap:2px;text-decoration:none;padding:1px 3px;border-radius:4px;transition:all .15s;font-weight:600;}
.clink .clink-ico{font-size:7px;opacity:0;transition:opacity .15s;}.clink:hover .clink-ico{opacity:1;}
.clink .num{border-bottom:1px dashed currentColor;}
.clink.sr{color:#0f766e;}.clink.sr:hover{background:#ccfbf1;box-shadow:0 0 0 1px #5eead4;}
.dep-link{display:inline-flex;align-items:center;gap:3px;text-decoration:none;padding:1px 4px;border-radius:4px;transition:all .15s;font-weight:600;}
.dep-link .dep-link-ico{font-size:7px;opacity:0;transition:opacity .15s;}.dep-link:hover .dep-link-ico{opacity:1;}
.dep-link .num{border-bottom:1px dashed currentColor;}
.dep-link.bank{color:#065f46;}.dep-link.bank:hover{background:#d1fae5;box-shadow:0 0 0 1px #6ee7b7;}
.dep-link.bo{color:#064e3b;}.dep-link.bo:hover{background:#a7f3d0;box-shadow:0 0 0 1px #34d399;}
.btn-transfer{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border:1px solid #bae6fd;border-radius:5px;background:#e0f2fe;color:#0369a1;font-size:10px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s;white-space:nowrap;}
.btn-transfer:hover{background:#0369a1;color:#fff;}
.btn-transfer.has-tr{background:#f0f9ff;color:#0c4a6e;border-color:#7dd3fc;}
.btn-pay-alloc{display:inline-flex;align-items:center;gap:3px;padding:3px 9px;border:1px solid #ddd6fe;border-radius:6px;background:#f5f3ff;color:#7c3aed;font-size:10px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s;white-space:nowrap;}
.btn-pay-alloc:hover{background:#7c3aed;color:#fff;}
.btn-pay-alloc.paid{background:#f0fdf4;color:#16a34a;border-color:#86efac;}
.val-charge{color:#dc2626;font-weight:600;font-family:var(--mn);}
.val-absorb{color:#0369a1;font-weight:600;font-family:var(--mn);}
.empty{text-align:center;padding:56px 20px;color:var(--txs);}
.empty i{font-size:42px;display:block;margin-bottom:10px;opacity:.2;}

/* ══ MODALS ══ */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1050;display:none;align-items:center;justify-content:center;padding:12px;}
.modal-overlay.open{display:flex;}
.pay-modal-box{background:#fff;border-radius:14px;width:100%;max-width:600px;max-height:96vh;box-shadow:0 32px 80px rgba(0,0,0,.28);display:flex;flex-direction:column;overflow:hidden;}
.pmo-header{padding:16px 20px 12px;border-bottom:1px solid #eff0f1;display:flex;justify-content:space-between;align-items:flex-start;flex-shrink:0;}
.pmo-header-left{display:flex;align-items:center;gap:10px;}
.pmo-icon{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#7c3aed,#6d28d9);display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;flex-shrink:0;}
.pmo-title{font-size:15px;font-weight:700;color:#111827;line-height:1.2;}.pmo-sub{font-size:11px;color:#9ca3af;margin-top:2px;}
.pmo-close{background:none;border:none;cursor:pointer;color:#9ca3af;font-size:20px;line-height:1;padding:2px 4px;border-radius:6px;transition:all .15s;}
.pmo-close:hover{color:#1f2937;background:#f3f4f6;}
.pmo-info{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px 20px;background:#f9fafb;border-bottom:1px solid #eff0f1;flex-shrink:0;}
.pmo-info-cell{background:#fff;border:1px solid #e5e7eb;border-radius:9px;padding:9px 12px;}
.pmo-info-cell.hi{background:linear-gradient(135deg,#fff5f5,#fff);border-color:#fca5a5;}
.pmo-info-cell.hi-exc{background:linear-gradient(135deg,#f0fdf4,#fff);border-color:#86efac;}
.pmo-info-cell.hi-sht{background:linear-gradient(135deg,#fff5f5,#fff);border-color:#fca5a5;}
.pmo-info-cell.hi-ok{background:linear-gradient(135deg,#f0fdf4,#fff);border-color:#86efac;}
.pmo-info-cell.hi-exc .pmo-info-val,.pmo-info-cell.hi-ok .pmo-info-val{color:#16a34a;font-size:18px;}
.pmo-info-cell.hi-sht .pmo-info-val{color:#dc2626;font-size:18px;}
.pmo-info-lbl{font-size:9px;color:#9ca3af;text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:3px;}
.pmo-info-val{font-size:14px;font-weight:700;color:#1f2937;font-family:var(--mn);}
.pmo-info-cell.hi .pmo-info-val{color:#dc2626;font-size:18px;}
.pmo-body{padding:16px 20px;overflow-y:auto;flex:1;}
.pmo-section{border:1px solid #e5e7eb;border-radius:10px;margin-bottom:12px;overflow:hidden;}
.pmo-sec-head{display:flex;justify-content:space-between;align-items:center;padding:9px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;}
.pmo-sec-head.red{background:#fef2f2;color:#991b1b;border-bottom:1px solid #fecaca;}
.pmo-sec-head.blue{background:#eff6ff;color:#1e40af;border-bottom:1px solid #bfdbfe;}
.pmo-sec-total{font-size:15px;font-weight:800;letter-spacing:0;font-family:var(--mn);}
.pmo-sec-body{padding:12px 14px;background:#fff;}
.emp-row{display:flex;align-items:center;gap:7px;margin-bottom:7px;flex-wrap:wrap;}
.emp-row .select2-container{flex:1;min-width:160px;}
.emp-row .select2-container .select2-selection--single{height:34px;border:1.5px solid #e5e7eb;border-radius:7px;font-size:12px;display:flex;align-items:center;}
.emp-row .select2-container .select2-selection--single .select2-selection__rendered{line-height:34px;padding-left:9px;font-size:12px;color:#1f2937;}
.emp-row .select2-container .select2-selection--single .select2-selection__arrow{height:32px;}
.select2-dropdown{border:1.5px solid #7c3aed;border-radius:7px;font-size:12px;font-family:var(--fn);box-shadow:0 8px 24px rgba(0,0,0,.12);}
.select2-search--dropdown .select2-search__field{border:1.5px solid #e5e7eb;border-radius:5px;padding:5px 8px;font-size:12px;outline:none;}
.select2-search--dropdown .select2-search__field:focus{border-color:#7c3aed;}
.select2-results__option{padding:6px 10px;font-size:12px;}
.select2-results__option--highlighted{background:#7c3aed!important;color:#fff!important;}
.select2-results__option[aria-selected=true]{background:#f3f0ff;color:#5b21b6;}
.emp-row .emp-amt{width:115px;flex-shrink:0;border:1.5px solid #e5e7eb;border-radius:7px;padding:7px 9px;font-size:12px;text-align:right;outline:none;font-family:var(--mn);}
.emp-row .emp-amt:focus{border-color:#7c3aed;}
.emp-row .emp-del{background:none;border:none;cursor:pointer;color:#dc2626;font-size:17px;padding:3px 5px;border-radius:5px;line-height:1;}
.emp-row .emp-del:hover{background:#fef2f2;}
.add-emp-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border:1.5px dashed #fca5a5;border-radius:7px;background:#fff;color:#dc2626;font-size:11px;font-weight:600;cursor:pointer;margin-top:5px;font-family:inherit;}
.add-emp-btn:hover{background:#fef2f2;border-color:#f87171;}
.absorb-row{display:flex;align-items:center;gap:9px;flex-wrap:wrap;}
.absorb-lbl{font-size:12px;color:#1e40af;font-weight:600;flex:1;min-width:130px;display:flex;align-items:center;gap:5px;}
.absorb-inp{min-width:115px;flex-shrink:0;border:1.5px solid #bfdbfe;border-radius:7px;padding:7px 11px;font-size:13px;text-align:right;outline:none;font-family:var(--mn);}
.absorb-inp:focus{border-color:#1e40af;}
.pmo-totals{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px;padding:12px 14px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;}
.ptb-cell{text-align:center;padding:4px;}
.ptb-lbl{font-size:9px;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;font-weight:600;margin-bottom:5px;}
.ptb-val{font-size:17px;font-weight:800;font-family:var(--mn);line-height:1;}
.pmo-ftr{padding:12px 20px;border-top:1px solid #eff0f1;display:flex;justify-content:flex-end;gap:8px;flex-shrink:0;}
.pmo-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:none;font-family:var(--fn);transition:all .15s;white-space:nowrap;}
.pmo-btn-cancel{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;}
.pmo-btn-save{background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;}
.pmo-btn-save:hover{background:linear-gradient(135deg,#6d28d9,#5b21b6);transform:translateY(-1px);}
.pmo-btn-save:disabled{opacity:.55;cursor:not-allowed;transform:none;}
.pmo-btn-save-tr{background:linear-gradient(135deg,#0369a1,#0284c7);color:#fff;}
.pmo-btn-save-tr:hover{background:linear-gradient(135deg,#0284c7,#0ea5e9);}
/* Transfer Modal */
.tr-modal-box{background:#fff;border-radius:14px;width:100%;max-width:580px;max-height:96vh;box-shadow:0 32px 80px rgba(0,0,0,.28);display:flex;flex-direction:column;overflow:hidden;}
.tr-modal-icon{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#0369a1,#0284c7);display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;flex-shrink:0;}
.tr-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;}
.tr-form-field{display:flex;flex-direction:column;gap:4px;}
.tr-form-field label{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;}
.tr-form-field input,.tr-form-field select{border:1.5px solid #e5e7eb;border-radius:7px;padding:7px 10px;font-size:12px;font-family:var(--fn);color:#1f2937;outline:none;width:100%;}
.tr-form-field input:focus,.tr-form-field select:focus{border-color:#0369a1;}
.tr-form-field .select2-container{width:100%!important;}
.tr-form-field .select2-container .select2-selection--single{height:36px;border:1.5px solid #e5e7eb;border-radius:7px;display:flex;align-items:center;}
.tr-form-field .select2-container .select2-selection--single .select2-selection__rendered{line-height:36px;padding-left:10px;font-size:12px;}
.tr-form-field .select2-container .select2-selection--single .select2-selection__arrow{height:34px;}
.tr-form-field .select2-container--open .select2-selection--single{border-color:#0369a1!important;box-shadow:0 0 0 2px rgba(3,105,161,.15);}
.tr-info-banner{background:#f0f9ff;border:1px solid #bae6fd;border-radius:9px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;gap:10px;}
.tr-info-banner i{color:#0369a1;font-size:14px;flex-shrink:0;}
.tr-info-banner span{font-size:11px;color:#0c4a6e;font-weight:500;}
.tr-info-banner strong{color:#0369a1;}
.tr-hist-list{list-style:none;padding:0;margin:0;}
.tr-hist-item{display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:6px;background:#fafafa;}
.tr-hist-item:last-child{margin-bottom:0;}
.tr-hist-dir{width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;}
.tr-hist-dir.out{background:#fee2e2;color:#991b1b;}.tr-hist-dir.in{background:#dcfce7;color:#166534;}
.tr-hist-info{flex:1;min-width:0;}
.tr-hist-sr{font-size:11px;font-weight:700;color:#1f2937;}
.tr-hist-date{font-size:10px;color:#9ca3af;font-family:var(--mn);}
.tr-hist-amt{font-size:13px;font-weight:800;font-family:var(--mn);white-space:nowrap;}
.tr-hist-amt.out{color:#dc2626;}.tr-hist-amt.in{color:#16a34a;}
.tr-hist-actions{display:flex;gap:4px;flex-shrink:0;}
.tr-hist-edit-btn,.tr-hist-del-btn{border:none;border-radius:5px;padding:4px 8px;font-size:10px;font-weight:600;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:3px;transition:all .15s;}
.tr-hist-edit-btn{background:#dbeafe;color:#1e40af;}.tr-hist-edit-btn:hover{background:#1e40af;color:#fff;}
.tr-hist-del-btn{background:#fee2e2;color:#991b1b;}.tr-hist-del-btn:hover{background:#991b1b;color:#fff;}
.tr-hist-note{font-size:10px;color:#6b7280;margin-top:2px;font-style:italic;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:220px;}
/* Save Report Modal */
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

#srToast{position:fixed;bottom:20px;right:20px;padding:9px 16px;border-radius:8px;font-size:12px;font-weight:600;z-index:9999;display:none;opacity:0;transition:opacity .3s;}
.toast-ok{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.toast-err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.print-header{display:none;}
@media print{
  body{background:#fff;font-size:10px;}.no-print{display:none!important;}
  .pg{padding:4px 4px 10px;}
  .print-header{display:block;text-align:center;padding:8px 0 6px;border-bottom:2px solid #0f766e;margin-bottom:8px;}
  .print-header h2{font-size:15px;font-weight:800;color:#111827;}
  .print-header p{font-size:10px;color:#4b5563;}
  table.cct{font-size:9px;}.cct .G th{padding:3px;font-size:8px;}.cct .S th{padding:2px 3px;font-size:8px;}
  .cct .TH td,.cct tbody td,.cct tfoot td{padding:2px 3px;}
  th,tfoot td,.cct .G th,.cct .S th{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .cct td.stk,.cct th.stk{position:static;box-shadow:none;}
  a{text-decoration:none;color:inherit;}.btn-pay-alloc,.btn-transfer{display:none;}
  .top-scroll-wrap{display:none;}.sc-row{grid-template-columns:repeat(3,1fr);gap:4px;}.sc{padding:5px 7px;box-shadow:none;}
}
@media(max-width:1100px){.sc-row{grid-template-columns:repeat(3,1fr);}}
@media(max-width:700px){.sc-row{grid-template-columns:1fr 1fr;}}
@media(max-width:600px){
  .pmo-info{grid-template-columns:1fr;}.pmo-totals{grid-template-columns:1fr;}.pmo-ftr{flex-direction:column-reverse;}.pmo-btn{justify-content:center;}
  .pay-modal-box,.tr-modal-box{border-radius:10px;}.tr-form-grid{grid-template-columns:1fr;}
}
</style>

<div class="pg">

<div class="print-header">
  <h2>SR Collection Summary Report</h2>
  <p><?php echo date('d M Y',strtotime($date_from)).' — '.date('d M Y',strtotime($date_to));
     echo $f_sr?' &nbsp;·&nbsp; Rep: '.htmlspecialchars($f_sr):'  &nbsp;·&nbsp; All Reps';
     echo ' &nbsp;·&nbsp; Generated: '.date('d M Y H:i');?></p>
</div>

<div class="topbar no-print">
  <div>
    <div class="pg-h1"><em>SR</em> Collection Summary</div>
    <div class="pg-sub">Secondary Invoice · SR Cash Collection · Deposits · Shortage · Cross Charge · Shortage Recovery</div>
  </div>
  <div style="display:flex;gap:7px;align-items:center;flex-wrap:wrap;">
    <span class="badge-sr"><i class="fa-solid fa-person-walking"></i> SR Only</span>
    <div class="dpill"><i class="fa-solid fa-calendar-days"></i> <?php echo date('d M Y',strtotime($date_from)).' — '.date('d M Y',strtotime($date_to));?></div>
    <?php if($submitted && !empty($rows)):?>
    <button onclick="exportToExcel()" class="btn-excel no-print"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
    <button onclick="window.print()" class="btn-rst no-print"><i class="fa-solid fa-print"></i> Print</button>
    <button onclick="openSaveReportModal()" class="btn-save-rep no-print"><i class="fa-solid fa-floppy-disk"></i> Save Report</button>
    <a href="view_cash_reports.php" target="_blank" class="btn-saved-list no-print"><i class="fa-solid fa-folder-open"></i> Saved Reports</a>
    <?php endif;?>
  </div>
</div>

<div class="fbar no-print">
  <form method="GET" id="srf" style="display:contents;">
    <input type="hidden" name="search" value="1">
    <div class="fg"><label><i class="fa-solid fa-calendar-day"></i> Date From</label><input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from);?>"></div>
    <div class="fg"><label><i class="fa-solid fa-calendar-day"></i> Date To</label><input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to);?>"></div>
    <div class="fg" style="min-width:150px;"><label><i class="fa-solid fa-id-badge"></i> Sales Rep (SR)</label>
      <select name="sr_code"><option value="">— All Reps —</option>
        <?php foreach($all_sr as $sr):?><option value="<?php echo htmlspecialchars($sr);?>" <?php echo $f_sr===$sr?'selected':'';?>><?php echo htmlspecialchars($sr);?></option><?php endforeach;?>
      </select></div>
    <button type="submit" class="btn-go" id="gBtn"><i class="fa-solid fa-magnifying-glass"></i> Generate</button>
    <a href="sr_collection_summary.php" class="btn-rst" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
  </form>
</div>

<?php if(!$submitted):?>
<div class="tc"><div class="empty"><i class="fa-solid fa-person-walking"></i>
  <p style="font-size:14px;font-weight:600;margin-bottom:6px;">SR Collection Summary</p>
  <p>Choose a date range and click <strong>Generate</strong> to view SR-only collection data.</p>
</div></div>
<?php elseif(empty($rows)):?>
<div class="tc"><div class="empty"><i class="fa-solid fa-inbox"></i>
  <p style="font-size:14px;font-weight:600;margin-bottom:5px;">No records found</p>
  <p><?php echo date('d M Y',strtotime($date_from)).' – '.date('d M Y',strtotime($date_to));
     echo $f_sr?' &nbsp;·&nbsp; Rep: <strong>'.htmlspecialchars($f_sr).'</strong>':'';?></p>
</div></div>
<?php else:
$tot_new_se = $totals['new_short_excess'];
$tot_sh = $tot_new_se > 0 ? $tot_new_se : 0;
?>

<div class="sc-row no-print">
  <div class="sc teal"><div class="sc-lbl"><i class="fa-solid fa-file-invoice-dollar"></i> Sec. Invoice</div><div class="sc-val" style="color:#0f766e;">Rs. <?php echo number_format($totals['sinv'],2);?></div></div>
  <div class="sc teal"><div class="sc-lbl"><i class="fa-solid fa-person-walking"></i> SR Total Collected</div><div class="sc-val" style="color:#0f766e;">Rs. <?php echo number_format($totals['sr_total'],2);?></div></div>
  <div class="sc green"><div class="sc-lbl"><i class="fa-solid fa-building-columns"></i> Banked (SR)</div><div class="sc-val" style="color:#166534;">Rs. <?php echo number_format($totals['banked_sr'],2);?></div></div>
  <div class="sc blue"><div class="sc-lbl"><i class="fa-solid fa-building"></i> Handed to BO (SR)</div><div class="sc-val" style="color:#1e40af;">Rs. <?php echo number_format($totals['handed_sr'],2);?></div></div>
  <div class="sc indigo"><div class="sc-lbl"><i class="fa-solid fa-right-left"></i> Net Cross Charge</div><div class="sc-val" style="color:#4338ca;">Rs. <?php echo number_format(abs($totals['shortage_adj']),2);?></div></div>
  <div class="sc <?php echo $tot_sh>0?'red':'green';?>" id="sc-netse">
    <div class="sc-lbl"><i class="fa-solid fa-triangle-exclamation"></i> Net Shortage</div>
    <div class="sc-val" id="sc-netse-val" style="color:<?php echo $tot_sh>0?'#991b1b':'#166534';?>;">
      Rs. <?php echo number_format($tot_new_se,2);?>
      <?php echo $tot_new_se==0?' <small>(OK)</small>':'';?>
    </div>
  </div>
</div>

<div class="tc">
  <div class="tc-bar no-print">
    <div class="tc-ttl"><i class="fa-solid fa-person-walking"></i> SR Collection Summary
      <span class="pill p-teal"><?php echo count($rows);?> rep(s)</span>
      <span class="pill p-blue"><?php echo date('d M Y',strtotime($date_from)).' – '.date('d M Y',strtotime($date_to));?></span>
      <?php if($f_sr):?><span class="pill p-slate">Rep: <?php echo htmlspecialchars($f_sr);?></span><?php endif;?>
      <span style="font-size:10px;font-weight:400;color:var(--txs);">· <span style="color:#dc2626;font-weight:600;">▼ Short</span></span>
    </div>
  </div>

  <div class="top-scroll-wrap no-print" id="topScroll"><div class="top-scroll-inner" id="topScrollInner"></div></div>
  <div class="tscroll" id="mainScroll">
  <table class="cct" id="cctMain">
    <thead>
      <tr class="G">
        <th class="h0 tl stk" rowspan="2" style="min-width:78px;">Rep</th>
        <th class="h0 tl"     rowspan="2" style="min-width:150px;">Date Range</th>
        <th class="hsinv"     colspan="1">Secondary Invoice</th>
        <th class="hsr"       colspan="6"><i class="fa-solid fa-person-walking"></i> SR Collection</th>
        <th class="hdep"      colspan="2">SR Deposits</th>
        <th class="hshort"    colspan="1">Shortage</th>
        <th class="hvar"      colspan="1">Short/Excess</th>
        <th class="htr"       colspan="1">Cross Charge</th>
        <th class="hnew"      colspan="1">Final Shortage</th>
        <th class="hrec"      colspan="3">Shortage Recovery</th>
        <th class="hrec"      colspan="2">Action</th>
      </tr>
      <tr class="S">
        <th class="ssinv"     style="min-width:108px;">Sec. Invoice Amt</th>
        <th class="ssr"       style="min-width:90px;">Daily Sale</th>
        <th class="ssr"       style="min-width:90px;">Credit Rcvd</th>
        <th class="ssr"       style="min-width:82px;">Rtn Cheque</th>
        <th class="ssr"       style="min-width:78px;">Rtn Chgs</th>
        <th class="ssr"       style="min-width:78px;">Sent Back</th>
        <th class="ssr"       style="min-width:90px;">SR Total</th>
        <th class="sdep_bank" style="min-width:96px;">Bank</th>
        <th class="sdep_bo"   style="min-width:96px;">Back Office</th>
        <th class="sshort"    style="min-width:82px;">Short Amt</th>
        <th class="svar"      style="min-width:82px;">Short/Excess</th>
        <th class="str"       style="min-width:110px;" title="Transfer OUT (red) / IN (green)">Cross Charge</th>
        <th class="snew"      style="min-width:92px;">Final Shortage</th>
        <th class="srec_emp"  style="min-width:90px;">Charge</th>
        <th class="srec_co"   style="min-width:90px;">Absorb</th>
        <th class="srec_var"  style="min-width:80px;">Pay Var.</th>
        <th class="str"       style="min-width:72px;text-align:center;">Cross Charge</th>
        <th class="srec_emp"  style="min-width:62px;text-align:center;">Pay</th>
      </tr>
      <tr class="TH">
        <td class="tl stk" colspan="2"><i class="fa-solid fa-sigma"></i>&nbsp; Total (<?php echo count($rows);?> rep(s))</td>
        <td><?php echo ct($totals['sinv']);?></td>
        <td><?php echo ct($totals['sr_daily_sale']);?></td>
        <td><?php echo ct($totals['sr_rcvd_credit']);?></td>
        <td><?php echo ct($totals['sr_rcvd_rtn_chq']);?></td>
        <td><?php echo ct($totals['sr_rcvd_rtn_chgs']);?></td>
        <td><?php echo ct($totals['sr_rcvd_sent_back']);?></td>
        <td style="font-weight:800;color:#0f766e;"><?php echo ct($totals['sr_total']);?></td>
        <td><?php echo ct($totals['banked_sr']);?></td>
        <td><?php echo ct($totals['handed_sr']);?></td>
        <td><?php $ts=$totals['cash_short'];echo $ts>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($ts,2).'</span>':'—';?></td>
        <?php $tv=$totals['sr_total']-$totals['banked_sr']-$totals['handed_sr'];?>
        <td><?php echo cc_se($tv);?></td>
        <td id="th-tr-combined">
          <?php $to=$totals['t_out'];$ti=$totals['t_in'];?>
          <?php if($to>0||$ti>0):?>
            <?php if($to>0):?><span style="color:#f87171;font-family:var(--mn);font-size:10px;font-weight:700;">▼<?php echo number_format($to,2);?></span><?php endif;?>
            <?php if($to>0&&$ti>0):?> · <?php endif;?>
            <?php if($ti>0):?><span style="color:#34d399;font-family:var(--mn);font-size:10px;font-weight:700;">▲<?php echo number_format($ti,2);?></span><?php endif;?>
          <?php else:?>—<?php endif;?>
        </td>
        <td id="th-newse"><?php echo cc_se($totals['new_short_excess']);?></td>
        <td id="th-charge"><?php echo ct($totals['p_charge']);?></td>
        <td id="th-absorb"><?php echo ct($totals['p_absorb']);?></td>
        <td></td><td></td><td></td>
      </tr>
    </thead>
    <tbody>
    <?php foreach($rows as $i=>$r):$stripe=($i%2!==0)?'stripe':'';?>
    <tr class="<?php echo $stripe;?>">
      <td class="tl rc stk"><?php echo htmlspecialchars($r['sr_code']);?></td>
      <td class="tl dt"><?php echo date('d M Y',strtotime($date_from)).' ~ '.date('d M Y',strtotime($date_to));?></td>
      <td class="fb"><?php echo cv($r['sinv']);?></td>
      <td class="col-sr"><?php echo srlink($r['sr_daily_sale'],    $r['sr_code'],$date_from,$date_to,'invoice');?></td>
      <td class="col-sr"><?php echo srlink($r['sr_rcvd_credit'],   $r['sr_code'],$date_from,$date_to,'credit');?></td>
      <td class="col-sr"><?php echo srlink($r['sr_rcvd_rtn_chq'],  $r['sr_code'],$date_from,$date_to,'rtn_chq');?></td>
      <td class="col-sr"><?php echo srlink($r['sr_rcvd_rtn_chgs'], $r['sr_code'],$date_from,$date_to,'rtn_chgs');?></td>
      <td class="col-sr"><?php echo srlink($r['sr_rcvd_sent_back'],$r['sr_code'],$date_from,$date_to,'sent_back');?></td>
      <td class="col-sr tv"><?php echo srlink($r['sr_total'],      $r['sr_code'],$date_from,$date_to);?></td>
      <td class="col-dep-bank"><?php echo sdep_link($r['banked_sr'],$r['sr_code'],$date_from,$date_to,'bank');?></td>
      <td class="col-dep-bo">  <?php echo sdep_link($r['handed_sr'],$r['sr_code'],$date_from,$date_to,'bo');?></td>
      <td class="col-short"><?php echo c_short($r['cash_short']);?></td>
      <td class="col-var"><?php echo cc_se($r['bank_diff']);?></td>
      <!-- Cross Charge combined -->
      <td class="col-tr" id="td-tr-<?php echo $i;?>">
        <?php if($r['t_out']>0||$r['t_in']>0):?>
          <?php if($r['t_out']>0):?><span style="color:#ef4444;font-family:var(--mn);font-size:10.5px;font-weight:700;">▼<?php echo number_format($r['t_out'],2);?></span><?php endif;?>
          <?php if($r['t_out']>0&&$r['t_in']>0):?><br><?php endif;?>
          <?php if($r['t_in']>0):?><span style="color:#22c55e;font-family:var(--mn);font-size:10.5px;font-weight:700;">▲<?php echo number_format($r['t_in'],2);?></span><?php endif;?>
        <?php else:?><span class="dash">—</span><?php endif;?>
      </td>
      <td class="col-new" id="td-new-<?php echo $i;?>"><?php echo cc_se($r['new_short_excess']);?></td>
      <!-- Shortage Recovery -->
      <td class="col-rec" id="prow-charge-<?php echo $i;?>"><?php if($r['p_charge']>0):?><span class="val-charge"><?php echo number_format($r['p_charge'],2);?></span><?php else:?><span class="dash">—</span><?php endif;?></td>
      <td class="col-rec" id="prow-absorb-<?php echo $i;?>"><?php if($r['p_absorb']>0):?><span class="val-absorb"><?php echo number_format($r['p_absorb'],2);?></span><?php else:?><span class="dash">—</span><?php endif;?></td>
      <td id="prow-var-<?php echo $i;?>"><?php echo cpvar($r['p_variance']);?></td>
      <td style="text-align:center;">
        <button class="btn-transfer<?php echo !empty($r['t_rows'])?' has-tr':'';?>" id="trbtn-<?php echo $i;?>" onclick="openTransferModal(<?php echo $i;?>)" title="Cross Charge">
          <i class="fa-solid fa-right-left"></i><?php echo !empty($r['t_rows'])?count($r['t_rows']):'';?>
        </button>
      </td>
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
        <td class="tl stk" colspan="2">TOTAL — <?php echo count($rows);?> rep(s)</td>
        <td><?php echo ct($totals['sinv']);?></td>
        <td><?php echo ct($totals['sr_daily_sale']);?></td>
        <td><?php echo ct($totals['sr_rcvd_credit']);?></td>
        <td><?php echo ct($totals['sr_rcvd_rtn_chq']);?></td>
        <td><?php echo ct($totals['sr_rcvd_rtn_chgs']);?></td>
        <td><?php echo ct($totals['sr_rcvd_sent_back']);?></td>
        <td><?php echo ct($totals['sr_total']);?></td>
        <td><?php echo ct($totals['banked_sr']);?></td>
        <td><?php echo ct($totals['handed_sr']);?></td>
        <td><?php echo $totals['cash_short']>0?'<span style="color:#dc2626;font-weight:800;">'.number_format($totals['cash_short'],2).'</span>':'—';?></td>
        <?php $fv=$totals['sr_total']-$totals['banked_sr']-$totals['handed_sr'];?>
        <td><?php echo cc_se($fv);?></td>
        <td id="tf-tr-combined">
          <?php $to=$totals['t_out'];$ti=$totals['t_in'];?>
          <?php if($to>0||$ti>0):?>
            <?php if($to>0):?><span style="color:#f87171;font-family:var(--mn);font-size:10px;font-weight:700;">▼<?php echo number_format($to,2);?></span><?php endif;?>
            <?php if($to>0&&$ti>0):?> · <?php endif;?>
            <?php if($ti>0):?><span style="color:#34d399;font-family:var(--mn);font-size:10px;font-weight:700;">▲<?php echo number_format($ti,2);?></span><?php endif;?>
          <?php else:?>—<?php endif;?>
        </td>
        <td id="tf-newse"><?php echo cc_se($totals['new_short_excess']);?></td>
        <td id="tf-charge"><?php echo ct($totals['p_charge']);?></td>
        <td id="tf-absorb"><?php echo ct($totals['p_absorb']);?></td>
        <td></td><td></td><td></td>
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
        <div><div class="pmo-title">Shortage Recovery Allocation</div><div class="pmo-sub" id="pm_sub">—</div></div>
      </div>
      <button class="pmo-close" onclick="closePayModal()">×</button>
    </div>
    <div class="pmo-info">
      <div class="pmo-info-cell"><div class="pmo-info-lbl">Sales Rep (SR)</div><div class="pmo-info-val" id="pm_sr">—</div></div>
      <div class="pmo-info-cell"><div class="pmo-info-lbl">Date Range</div><div class="pmo-info-val" id="pm_date">—</div></div>
      <div class="pmo-info-cell"><div class="pmo-info-lbl">SR Total Collected</div><div class="pmo-info-val" id="pm_total">—</div></div>
      <div class="pmo-info-cell hi" id="pm_se_cell"><div class="pmo-info-lbl">Balance (After Cross Charge)</div><div class="pmo-info-val" id="pm_seval">—</div></div>
    </div>
    <div class="pmo-body">
      <div id="pm_excess_notice" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:9px;padding:10px 14px;margin-bottom:12px;">
        <div style="display:flex;align-items:center;gap:8px;"><i class="fa-solid fa-circle-check" style="color:#16a34a;font-size:16px;"></i>
        <div><div style="font-size:12px;font-weight:700;color:#166534;">No shortage</div>
        <div style="font-size:11px;color:#15803d;margin-top:2px;">This rep has no shortage. No charge or absorption needed.</div></div></div>
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
        <div class="ptb-cell"><div class="ptb-lbl">Balance (Shortage)</div><div class="ptb-val" id="pm_ptb_seval">0.00</div></div>
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
        <div><div class="pmo-title">Cross Charge Transfer</div><div class="pmo-sub" id="tr_sub">—</div></div>
      </div>
      <button class="pmo-close" onclick="closeTransferModal()">×</button>
    </div>
    <div class="pmo-info" style="grid-template-columns:1fr 1fr 1fr;">
      <div class="pmo-info-cell"><div class="pmo-info-lbl">Sales Rep</div><div class="pmo-info-val" id="tr_sr">—</div></div>
      <div class="pmo-info-cell"><div class="pmo-info-lbl">Date Range</div><div class="pmo-info-val" id="tr_deldate">—</div></div>
      <div class="pmo-info-cell hi" id="tr_se_cell"><div class="pmo-info-lbl">Current Shortage</div><div class="pmo-info-val" id="tr_se_val">—</div></div>
    </div>
    <div class="pmo-body">
      <div id="trHistSection" style="display:none;margin-bottom:16px;">
        <div style="font-size:11px;font-weight:700;color:#374151;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
          <i class="fa-solid fa-clock-rotate-left" style="color:#0369a1;"></i> Existing Cross Charges
        </div>
        <ul class="tr-hist-list" id="trHistList"></ul>
      </div>
      <div id="trFormSection">
        <div style="font-size:11px;font-weight:700;color:#374151;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
          <i class="fa-solid fa-plus-circle" style="color:#0369a1;"></i> <span id="trFormTitle">New Cross Charge</span>
        </div>
        <div class="tr-info-banner">
          <i class="fa-solid fa-circle-info"></i>
          <span>Transfer shortage to another rep. This reduces <strong>your</strong> balance and adds to <strong>theirs</strong>.</span>
        </div>
        <div class="tr-form-grid">
          <div class="tr-form-field">
            <label><i class="fa-solid fa-calendar-day"></i> Delivery / Reference Date</label>
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
          <div class="tr-form-field">
            <label><i class="fa-solid fa-coins"></i> Amount</label>
            <input type="number" step="0.01" min="0.01" id="tr_form_amount" placeholder="0.00">
          </div>
          <div class="tr-form-field">
            <label><i class="fa-solid fa-note-sticky"></i> Note (optional)</label>
            <input type="text" id="tr_form_note" placeholder="Reason…">
          </div>
        </div>
      </div>
    </div>
    <div class="pmo-ftr">
      <button class="pmo-btn pmo-btn-cancel" onclick="closeTransferModal()"><i class="fa-solid fa-xmark"></i> Close</button>
      <button class="pmo-btn pmo-btn-save pmo-btn-save-tr" id="saveTrBtn" onclick="saveTransfer()"><i class="fa-solid fa-paper-plane"></i> Save</button>
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
        <span>Saves a <strong>snapshot</strong> of all currently loaded data (rows, totals, cross charges) to the database. View it any time from <strong>Saved Reports</strong>.</span>
      </div>
      <div class="srm-field">
        <label><i class="fa-solid fa-tag"></i> Report Name</label>
        <input type="text" id="srm_name" placeholder="e.g. SR Summary – Apr 2026">
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

<div id="srToast"></div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<?php
$rows_js = [];
foreach ($rows as $i => $r) {
    $rows_js[] = [
        'idx'               => $i,
        'sr_code'           => $r['sr_code'],
        'del_date'          => $r['del_date'],
        'rep_name'          => $names[$r['sr_code']] ?? '',
        'pay_date_from'     => $date_from,
        'pay_date_to'       => $date_to,
        'total'             => floatval($r['sr_total']),
        'se_val'            => floatval($r['new_short_excess']),
        'sinv'              => floatval($r['sinv']),
        'sr_daily_sale'     => floatval($r['sr_daily_sale']),
        'sr_rcvd_credit'    => floatval($r['sr_rcvd_credit']),
        'sr_rcvd_rtn_chq'   => floatval($r['sr_rcvd_rtn_chq']),
        'sr_rcvd_rtn_chgs'  => floatval($r['sr_rcvd_rtn_chgs']),
        'sr_rcvd_sent_back' => floatval($r['sr_rcvd_sent_back']),
        'sr_total'          => floatval($r['sr_total']),
        'banked_sr'         => floatval($r['banked_sr']),
        'handed_sr'         => floatval($r['handed_sr']),
        'bank_diff'         => floatval($r['bank_diff']),
        'cash_short'        => floatval($r['cash_short']),
        'cash_excess'       => floatval($r['cash_excess']),
        't_out'             => floatval($r['t_out']),
        't_in'              => floatval($r['t_in']),
        'shortage_adj'      => floatval($r['shortage_adj']),
        'new_short_excess'  => floatval($r['new_short_excess']),
        't_rows'            => $r['t_rows'],
        'p_charge'          => floatval($r['p_charge']),
        'p_absorb'          => floatval($r['p_absorb']),
        'p_variance'        => $r['p_variance'],
        'employees'         => $r['p_employees'],
        'is_paid'           => $r['is_paid'],
    ];
}
?>
<script>
var ROWS_DATA=<?php echo json_encode($rows_js);?>;
var payEmpCount=0,currentPayIdx=null;
var currentTrIdx=null,editingTrId=null;
var EMP_OPTIONS=`<?php foreach($emp_list as $e):?><option value="<?php echo intval($e['id']);?>"><?php echo htmlspecialchars($e['emp_code'].' — '.$e['emp_name']);?></option><?php endforeach;?>`;

function fmtNum(n){return parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');}
function fmtSE(v){
    v=parseFloat(v||0);
    if(Math.abs(v)<0.005) return '<span class="d-ok">0.00 ✓</span>';
    if(v<0) return '<span class="d-exc">▲ '+fmtNum(Math.abs(v))+'</span>';
    return '<span class="d-sht">▼ '+fmtNum(v)+'</span>';
}
function fmtPayVar(v){
    if(v===null||v===undefined) return '<span style="color:#d1d5db;">—</span>';
    v=parseFloat(v);
    if(Math.abs(v)<0.005) return '<span class="pv-ok">0.00</span>';
    if(v<0) return '<span class="pv-exc-pay">'+v.toFixed(2)+'</span>';
    return '<span class="pv-sht-pay">+'+fmtNum(v)+'</span>';
}

function recalcRowTransferFields(rd){
    rd.shortage_adj = rd.t_out - rd.t_in;
    var shortOnly = rd.bank_diff > 0.005 ? rd.bank_diff : 0;
    rd.new_short_excess = Math.max(0, shortOnly - rd.t_out + rd.t_in);
    rd.se_val = rd.new_short_excess;
}

function applyTransferDataToRow(idx, serverRowData){
    var rd = ROWS_DATA[idx]; if(!rd) return;
    if(serverRowData){
        rd.t_rows           = serverRowData.t_rows  || [];
        rd.t_out            = parseFloat(serverRowData.t_out           || 0);
        rd.t_in             = parseFloat(serverRowData.t_in            || 0);
        rd.shortage_adj     = parseFloat(serverRowData.shortage_adj    || 0);
        rd.new_short_excess = parseFloat(serverRowData.new_short_excess|| 0);
    } else {
        var tout=0,tin=0;
        (rd.t_rows||[]).forEach(function(tr){
            if(tr.direction==='out') tout+=parseFloat(tr.amount||0);
            else                     tin +=parseFloat(tr.amount||0);
        });
        rd.t_out=tout; rd.t_in=tin;
        recalcRowTransferFields(rd);
    }
    rd.se_val = rd.new_short_excess;

    (function(){
        var el=document.getElementById('td-tr-'+idx); if(!el) return;
        var out=rd.t_out>0?'<span style="color:#ef4444;font-family:var(--mn);font-size:10.5px;font-weight:700;">▼'+fmtNum(rd.t_out)+'</span>':'';
        var inn=rd.t_in >0?'<span style="color:#22c55e;font-family:var(--mn);font-size:10.5px;font-weight:700;">▲'+fmtNum(rd.t_in)+'</span>':'';
        if(out&&inn) el.innerHTML=out+'<br>'+inn;
        else if(out) el.innerHTML=out;
        else if(inn) el.innerHTML=inn;
        else         el.innerHTML='<span class="dash">—</span>';
    })();
    document.getElementById('td-new-'+idx).innerHTML=fmtSE(rd.new_short_excess);

    var trBtn=document.getElementById('trbtn-'+idx);
    if(trBtn){
        trBtn.className='btn-transfer'+((rd.t_rows||[]).length?' has-tr':'');
        trBtn.innerHTML='<i class="fa-solid fa-right-left"></i>'+((rd.t_rows||[]).length?(rd.t_rows||[]).length:'');
    }
}

function findRowIdx(srCode){for(var i=0;i<ROWS_DATA.length;i++) if(ROWS_DATA[i].sr_code===srCode) return i; return -1;}

function recomputeTotalsAndApply(idx){
    var rd=ROWS_DATA[idx]; if(!rd) return;
    var tout=0,tin=0;
    (rd.t_rows||[]).forEach(function(x){if(x.direction==='out')tout+=parseFloat(x.amount||0);else tin+=parseFloat(x.amount||0);});
    rd.t_out=tout; rd.t_in=tin;
    recalcRowTransferFields(rd);
    applyTransferDataToRow(idx,null);
}

/* ── Excel Export ── */
function exportToExcel(){
    if(!ROWS_DATA||!ROWS_DATA.length){showSRToast('No data to export.','err');return;}
    var hdrs=['Rep Code','Rep Name','Date Range','Sec. Invoice',
              'SR Daily Sale','SR Credit Rcvd','SR Rtn Cheque','SR Rtn Chgs','SR Sent Back','SR Total',
              'Dep. Bank (SR)','Dep. Back Office (SR)',
              'Cash Shortage','Short/Excess',
              'Cross Charge OUT','Cross Charge IN','Final Shortage',
              'Charge','Absorb','Pay Var.'];
    var data=ROWS_DATA.map(function(r){return [
        r.sr_code,r.rep_name,r.del_date,r.sinv,
        r.sr_daily_sale,r.sr_rcvd_credit,r.sr_rcvd_rtn_chq,r.sr_rcvd_rtn_chgs,r.sr_rcvd_sent_back,r.sr_total,
        r.banked_sr,r.handed_sr,
        r.cash_short,r.bank_diff,
        r.t_out,r.t_in,r.new_short_excess,
        r.p_charge,r.p_absorb,r.p_variance!==null?r.p_variance:''
    ];});
    var ws=XLSX.utils.aoa_to_sheet([hdrs].concat(data));
    var wb=XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb,ws,'SR Collection Summary');
    var dfrom='<?php echo date("d-M-Y",strtotime($date_from));?>',dto='<?php echo date("d-M-Y",strtotime($date_to));?>';
    XLSX.writeFile(wb,'SR_Collection_Summary_'+dfrom+'_to_'+dto+'.xlsx');
    showSRToast('Excel exported!','ok');
}

/* ══ PAY ALLOC MODAL ══ */
function openPayModal(idx){
    currentPayIdx=idx; payEmpCount=0;
    document.getElementById('pm_chargeRows').innerHTML='';
    document.getElementById('pm_absorb_amt').value='';
    var rd=ROWS_DATA[idx];
    var newSE=rd.new_short_excess;
    var isShort=newSE>0.005;
    document.getElementById('pm_sub').textContent=rd.sr_code+' · '+rd.del_date;
    document.getElementById('pm_sr').textContent=rd.sr_code;
    document.getElementById('pm_date').textContent=rd.del_date;
    document.getElementById('pm_total').textContent='Rs. '+fmtNum(rd.total);
    var seCell=document.getElementById('pm_se_cell');
    seCell.className='pmo-info-cell '+(isShort?'hi-sht':'hi-ok');
    document.getElementById('pm_seval').textContent=fmtNum(newSE)+(isShort?' (Short)':' (Balanced)');
    document.getElementById('pm_excess_notice').style.display=isShort?'none':'';
    var ptbSE=document.getElementById('pm_ptb_seval');
    if(isShort){ptbSE.textContent='▼ '+fmtNum(newSE);ptbSE.style.color='#dc2626';}
    else{ptbSE.textContent='0.00 ✓';ptbSE.style.color='#16a34a';}
    document.getElementById('pm_ptb_alloc').textContent='0.00';
    document.getElementById('pm_charge_total').textContent='0.00';
    document.getElementById('pm_absorb_total').textContent='0.00';
    recalcPayAllocVar(newSE,0);
    if(rd.employees&&rd.employees.length) rd.employees.forEach(function(e){addPayEmpRow(e.employee_id,e.amount);});
    if(rd.p_absorb>0){document.getElementById('pm_absorb_amt').value=rd.p_absorb;recalcPayAlloc();}
    document.getElementById('payAllocModal').classList.add('open');
}
function closePayModal(){document.getElementById('payAllocModal').classList.remove('open');currentPayIdx=null;}

function addPayEmpRow(empId,amt){
    var rid='er_'+(payEmpCount++);
    var div=document.createElement('div');div.className='emp-row';div.id=rid;
    div.innerHTML='<select class="emp-sel"><option value="">— Select Employee —</option>'+EMP_OPTIONS+'</select>'+
        '<input type="number" step="0.01" min="0" class="emp-amt" placeholder="0.00" oninput="recalcPayAlloc()">'+
        '<button type="button" class="emp-del" onclick="document.getElementById(\''+rid+'\').remove();recalcPayAlloc();" title="Remove">✕</button>';
    document.getElementById('pm_chargeRows').appendChild(div);
    var $sel=$(div).find('.emp-sel');
    $sel.select2({dropdownParent:$('#payAllocModal'),placeholder:'— Select Employee —',allowClear:true,width:'resolve'});
    if(empId) setTimeout(function(){$sel.val(String(empId)).trigger('change');},0);
    if(amt) div.querySelector('.emp-amt').value=parseFloat(amt).toFixed(2);
    recalcPayAlloc();
}
function recalcPayAlloc(){
    var tc=0;
    document.querySelectorAll('#pm_chargeRows .emp-row').forEach(function(row){tc+=parseFloat(row.querySelector('.emp-amt').value||0);});
    var absorb=parseFloat(document.getElementById('pm_absorb_amt').value||0);
    var newSE=currentPayIdx!==null?ROWS_DATA[currentPayIdx].new_short_excess:0;
    var alloc=tc+absorb;
    document.getElementById('pm_charge_total').textContent=fmtNum(tc);
    document.getElementById('pm_absorb_total').textContent=fmtNum(absorb);
    document.getElementById('pm_ptb_alloc').textContent=fmtNum(alloc);
    recalcPayAllocVar(newSE,alloc);
}
function recalcPayAllocVar(newSE,alloc){
    var vari=newSE-alloc;
    var vEl=document.getElementById('pm_ptb_var');
    if(Math.abs(vari)<0.005){vEl.textContent='0.00 ✓';vEl.style.color='#16a34a';}
    else if(vari>0){vEl.textContent='▼ '+fmtNum(vari);vEl.style.color='#dc2626';}
    else{vEl.textContent='▲ '+fmtNum(Math.abs(vari));vEl.style.color='#d97706';}
}
function savePayAlloc(){
    if(currentPayIdx===null) return;
    var btn=document.getElementById('savePayBtn');
    var charges=[];var valid=true;
    document.querySelectorAll('#pm_chargeRows .emp-row').forEach(function(row){
        var empId=$(row).find('.emp-sel').val();
        var empLbl=$(row).find('.emp-sel option:selected').text()||'';
        var amt=parseFloat(row.querySelector('.emp-amt').value||0);
        if(!empId){showSRToast('Please select an employee.','err');valid=false;return;}
        if(!(amt>0)){showSRToast('Amount must be > 0.','err');valid=false;return;}
        charges.push({employee_id:parseInt(empId),employee_name:empLbl,amount:amt});
    });
    if(!valid) return;
    var rd=ROWS_DATA[currentPayIdx];
    var absorb=parseFloat(document.getElementById('pm_absorb_amt').value||0)||0;
    var newSE=rd.new_short_excess;
    var tc=charges.reduce(function(s,r){return s+r.amount;},0);
    var vari=newSE-(tc+absorb);
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    fetch('save_cash_summary_pay.php',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({pay_date_from:rd.pay_date_from,pay_date_to:rd.pay_date_to,sr_code:rd.sr_code,charges,absorb_amount:absorb,se_value:newSE,variance:vari})
    }).then(function(r){return r.json();}).then(function(res){
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Allocation';
        if(res.success){
            var idx=currentPayIdx;
            ROWS_DATA[idx].p_charge=tc;ROWS_DATA[idx].p_absorb=absorb;ROWS_DATA[idx].p_variance=vari;ROWS_DATA[idx].employees=charges;ROWS_DATA[idx].is_paid=true;
            document.getElementById('prow-charge-'+idx).innerHTML=tc>0?'<span class="val-charge">'+fmtNum(tc)+'</span>':'<span class="dash">—</span>';
            document.getElementById('prow-absorb-'+idx).innerHTML=absorb>0?'<span class="val-absorb">'+fmtNum(absorb)+'</span>':'<span class="dash">—</span>';
            document.getElementById('prow-var-'+idx).innerHTML=fmtPayVar(vari);
            var pb=document.getElementById('payallocbtn-'+idx);
            if(pb){pb.className='btn-pay-alloc paid';pb.innerHTML='<i class="fa-solid fa-check"></i> Paid';}
            refreshAllTotals();closePayModal();showSRToast('Allocation saved!','ok');
        } else showSRToast('Error: '+(res.message||'Unknown'),'err');
    }).catch(function(err){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Allocation';showSRToast('Network error: '+err.message,'err');});
}

/* ══ TRANSFER MODAL ══ */
function openTransferModal(idx){
    currentTrIdx=idx;editingTrId=null;
    var rd=ROWS_DATA[idx];
    document.getElementById('tr_sub').textContent=rd.sr_code+' · '+rd.del_date;
    document.getElementById('tr_sr').textContent=rd.sr_code;
    document.getElementById('tr_deldate').textContent=rd.del_date;
    document.getElementById('tr_se_val').innerHTML=fmtSE(rd.bank_diff);
    var seCell=document.getElementById('tr_se_cell');
    seCell.className='pmo-info-cell '+(rd.bank_diff>0.005?'hi-sht':'hi-ok');
    document.getElementById('tr_form_deldate').value=rd.pay_date_from;
    document.getElementById('tr_form_trdate').value='<?php echo date('Y-m-d');?>';
    document.getElementById('tr_form_amount').value=rd.bank_diff>0.004?rd.bank_diff.toFixed(2):'';
    document.getElementById('tr_form_note').value='';
    $('#tr_form_to_sr').val('').trigger('change');
    document.getElementById('trFormTitle').textContent='New Cross Charge';
    document.getElementById('saveTrBtn').innerHTML='<i class="fa-solid fa-paper-plane"></i> Save';
    renderTransferHistory(rd.t_rows||[]);
    document.getElementById('transferModal').classList.add('open');
}
function closeTransferModal(){document.getElementById('transferModal').classList.remove('open');currentTrIdx=null;editingTrId=null;}

function renderTransferHistory(rows){
    var sec=document.getElementById('trHistSection'),list=document.getElementById('trHistList');
    if(!rows||rows.length===0){sec.style.display='none';return;}
    sec.style.display='';list.innerHTML='';
    rows.forEach(function(tr){
        var li=document.createElement('li');li.className='tr-hist-item';
        var dirClass=tr.direction==='out'?'out':'in';
        var otherLabel=tr.direction==='out'?('→ '+tr.other_sr):('← '+tr.other_sr);
        li.innerHTML='<div class="tr-hist-dir '+dirClass+'">'+(tr.direction==='out'?'OUT':'IN')+'</div>'+
          '<div class="tr-hist-info"><div class="tr-hist-sr">'+otherLabel+'</div>'+
          '<div class="tr-hist-date">Del: '+tr.delivery_date+' · Tr: '+tr.transfer_date+'</div>'+
          (tr.note?'<div class="tr-hist-note">'+escHtml(tr.note)+'</div>':'')+
          '</div><div class="tr-hist-amt '+dirClass+'">'+(dirClass==='out'?'−':'+')+fmtNum(tr.amount)+'</div>'+
          '<div class="tr-hist-actions">'+(tr.direction==='out'?
            '<button class="tr-hist-edit-btn" onclick="editTransfer('+tr.id+','+JSON.stringify(tr).replace(/"/g,'&quot;')+')"><i class="fa-solid fa-pen"></i> Edit</button>'+
            '<button class="tr-hist-del-btn" onclick="deleteTransfer('+tr.id+')"><i class="fa-solid fa-trash"></i></button>':
            '<span style="font-size:10px;color:#9ca3af;">Received</span>')+'</div>';
        list.appendChild(li);
    });
}
function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

function editTransfer(trId,trData){
    editingTrId=trId;
    document.getElementById('tr_form_deldate').value=trData.delivery_date;
    document.getElementById('tr_form_trdate').value=trData.transfer_date;
    document.getElementById('tr_form_amount').value=trData.amount;
    document.getElementById('tr_form_note').value=trData.note||'';
    $('#tr_form_to_sr').val(trData.other_sr).trigger('change');
    document.getElementById('trFormTitle').textContent='Edit Cross Charge #'+trId;
    document.getElementById('saveTrBtn').innerHTML='<i class="fa-solid fa-floppy-disk"></i> Update';
}

function saveTransfer(){
    if(currentTrIdx===null) return;
    var rd=ROWS_DATA[currentTrIdx];
    var toSr=document.getElementById('tr_form_to_sr').value;
    var delDate=document.getElementById('tr_form_deldate').value;
    var trDate=document.getElementById('tr_form_trdate').value;
    var amount=parseFloat(document.getElementById('tr_form_amount').value||0);
    var note=document.getElementById('tr_form_note').value.trim();
    if(!toSr){showSRToast('Select a target rep.','err');return;}
    if(toSr===rd.sr_code){showSRToast('Cannot transfer to same rep.','err');return;}
    if(!delDate){showSRToast('Enter a reference date.','err');return;}
    if(!trDate){showSRToast('Enter transfer date.','err');return;}
    if(!(amount>0)){showSRToast('Amount must be > 0.','err');return;}
    var oldToSr=null;
    if(editingTrId){var oldEntry=(rd.t_rows||[]).find(function(x){return x.id===editingTrId;});if(oldEntry)oldToSr=oldEntry.other_sr;}
    var btn=document.getElementById('saveTrBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    var payload={action:editingTrId?'update':'create',id:editingTrId||null,from_sr_code:rd.sr_code,to_sr_code:toSr,delivery_date:delDate,transfer_date:trDate,amount:amount,note:note};
    fetch('save_cash_transfer.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
    .then(function(r){return r.json();}).then(function(res){
        btn.disabled=false;btn.innerHTML=editingTrId?'<i class="fa-solid fa-floppy-disk"></i> Update':'<i class="fa-solid fa-paper-plane"></i> Save';
        if(res.success){
            showSRToast(editingTrId?'Updated!':'Saved!','ok');
            var newTrId=res.transfer_id||editingTrId||0;
            if(editingTrId&&oldToSr&&oldToSr!==toSr){
                var oldToIdx=findRowIdx(oldToSr);
                if(oldToIdx>=0){ROWS_DATA[oldToIdx].t_rows=(ROWS_DATA[oldToIdx].t_rows||[]).filter(function(x){return x.id!==editingTrId;});recomputeTotalsAndApply(oldToIdx);}
            }
            if(res.from_row_data||res.row_data){applyTransferDataToRow(currentTrIdx,res.from_row_data||res.row_data);}
            else{if(editingTrId)rd.t_rows=(rd.t_rows||[]).filter(function(x){return x.id!==editingTrId;});rd.t_rows=(rd.t_rows||[]).concat([{id:newTrId,direction:'out',other_sr:toSr,delivery_date:delDate,transfer_date:trDate,amount:amount,note:note}]);recomputeTotalsAndApply(currentTrIdx);}
            var toIdx=findRowIdx(toSr);
            if(toIdx>=0){
                if(res.to_row_data){applyTransferDataToRow(toIdx,res.to_row_data);}
                else{var toRd=ROWS_DATA[toIdx];if(editingTrId)toRd.t_rows=(toRd.t_rows||[]).filter(function(x){return x.id!==editingTrId;});toRd.t_rows=(toRd.t_rows||[]).concat([{id:newTrId,direction:'in',other_sr:rd.sr_code,delivery_date:delDate,transfer_date:trDate,amount:amount,note:note}]);recomputeTotalsAndApply(toIdx);}
            }
            editingTrId=null;
            document.getElementById('tr_form_amount').value='';document.getElementById('tr_form_note').value='';
            $('#tr_form_to_sr').val('').trigger('change');
            document.getElementById('trFormTitle').textContent='New Cross Charge';
            btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Save';
            document.getElementById('tr_se_val').innerHTML=fmtSE(rd.bank_diff);
            renderTransferHistory(ROWS_DATA[currentTrIdx].t_rows||[]);refreshAllTotals();
        } else showSRToast('Error: '+(res.message||'Unknown'),'err');
    }).catch(function(err){btn.disabled=false;btn.innerHTML=editingTrId?'<i class="fa-solid fa-floppy-disk"></i> Update':'<i class="fa-solid fa-paper-plane"></i> Save';showSRToast('Network error: '+err.message,'err');});
}

function deleteTransfer(trId){
    if(!confirm('Delete this cross charge?')) return;
    var fromIdx=currentTrIdx,fromRd=ROWS_DATA[fromIdx];
    var trEntry=(fromRd.t_rows||[]).find(function(x){return x.id===trId;});
    fetch('save_cash_transfer.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id:trId})})
    .then(function(r){return r.json();}).then(function(res){
        if(res.success){
            showSRToast('Deleted.','ok');
            if(res.from_row_data||res.row_data){applyTransferDataToRow(fromIdx,res.from_row_data||res.row_data);}
            else{fromRd.t_rows=(fromRd.t_rows||[]).filter(function(x){return x.id!==trId;});recomputeTotalsAndApply(fromIdx);}
            if(trEntry){var toIdx2=findRowIdx(trEntry.other_sr);if(toIdx2>=0){if(res.to_row_data){applyTransferDataToRow(toIdx2,res.to_row_data);}else{ROWS_DATA[toIdx2].t_rows=(ROWS_DATA[toIdx2].t_rows||[]).filter(function(x){return x.id!==trId;});recomputeTotalsAndApply(toIdx2);}}}
            document.getElementById('tr_se_val').innerHTML=fmtSE(fromRd.bank_diff);
            renderTransferHistory(ROWS_DATA[currentTrIdx].t_rows||[]);refreshAllTotals();
        } else showSRToast('Error: '+(res.message||'Unknown'),'err');
    }).catch(function(err){showSRToast('Network error: '+err.message,'err');});
}

function refreshAllTotals(){
    var t={t_out:0,t_in:0,shortage_adj:0,new_short_excess:0,p_charge:0,p_absorb:0};
    ROWS_DATA.forEach(function(r){t.t_out+=(r.t_out||0);t.t_in+=(r.t_in||0);t.shortage_adj+=(r.shortage_adj||0);t.new_short_excess+=(r.new_short_excess||0);t.p_charge+=(r.p_charge||0);t.p_absorb+=(r.p_absorb||0);});
    function ccT(n){return n==0?'—':fmtNum(n);}
    function fmtTrCombined(tout,tin){
        var out=tout>0?'<span style="color:#f87171;font-family:var(--mn);font-size:10px;font-weight:700;">▼'+fmtNum(tout)+'</span>':'';
        var inn=tin >0?'<span style="color:#34d399;font-family:var(--mn);font-size:10px;font-weight:700;">▲'+fmtNum(tin) +'</span>':'';
        if(out&&inn) return out+' · '+inn; return out||inn||'—';
    }
    ['th','tf'].forEach(function(p){
        var tr=document.getElementById(p+'-tr-combined'),nw=document.getElementById(p+'-newse');
        var ch=document.getElementById(p+'-charge'),ab=document.getElementById(p+'-absorb');
        if(tr)tr.innerHTML=fmtTrCombined(t.t_out,t.t_in);
        if(nw)nw.innerHTML=fmtSE(t.new_short_excess);
        if(ch)ch.innerHTML=ccT(t.p_charge);
        if(ab)ab.innerHTML=ccT(t.p_absorb);
    });
    var scCard=document.getElementById('sc-netse'),scVal=document.getElementById('sc-netse-val');
    if(scCard&&scVal){
        var nse=t.new_short_excess,isShort=nse>0.005;
        scCard.className='sc '+(isShort?'red':'green');
        scVal.style.color=isShort?'#991b1b':'#166534';
        scVal.innerHTML='Rs. '+fmtNum(nse)+(Math.abs(nse)<0.005?' <small>(OK)</small>':'');
    }
}

/* ══ SAVE REPORT ══ */
function openSaveReportModal(){
    var df='<?php echo date("d M Y", strtotime($date_from));?>';
    var dt='<?php echo date("d M Y", strtotime($date_to));?>';
    var sr='<?php echo addslashes($f_sr);?>';
    document.getElementById('srm_name').value='SR Summary '+df+(df!==dt?' – '+dt:'')+(sr?' ('+sr+')':'');
    document.getElementById('srm_by').value='';
    document.getElementById('srm_sub').textContent=df+(df!==dt?' – '+dt:'')+' · '+ROWS_DATA.length+' rep(s)';
    document.getElementById('saveReportModal').classList.add('open');
}
function closeSaveReportModal(){document.getElementById('saveReportModal').classList.remove('open');}
function doSaveReport(){
    var name=document.getElementById('srm_name').value.trim();
    if(!name){showSRToast('Please enter a report name.','err');return;}
    var btn=document.getElementById('srmSaveBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    var t={sinv:0,sr_total:0,sr_total:0,total_coll:0,banked_sr:0,handed_sr:0,t_out:0,t_in:0,shortage_adj:0,new_short_excess:0,p_charge:0,p_absorb:0,cash_short:0};
    ROWS_DATA.forEach(function(r){Object.keys(t).forEach(function(k){if(r[k]!==undefined)t[k]+=parseFloat(r[k]||0);});});
    var payload={
        report_type: 'sr_summary',
        report_name: name,
        saved_by:    document.getElementById('srm_by').value.trim()||null,
        date_from:   '<?php echo $date_from;?>',
        date_to:     '<?php echo $date_to;?>',
        sr_code:     '<?php echo addslashes($f_sr);?>',
        totals:      t,
        rows:        ROWS_DATA
    };
    fetch('save_cash_report.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
    .then(function(r){return r.json();}).then(function(res){
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Report';
        if(res.success){closeSaveReportModal();showSRToast('Report saved! ID #'+res.report_id,'ok');}
        else showSRToast('Error: '+(res.message||'Unknown'),'err');
    }).catch(function(err){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Report';showSRToast('Network error: '+err.message,'err');});
}

/* ── Toast ── */
function showSRToast(msg,type){
    var t=document.getElementById('srToast');
    t.className=type==='ok'?'toast-ok':'toast-err';
    t.textContent=msg;t.style.display='block';t.style.opacity='1';
    clearTimeout(t._t);
    t._t=setTimeout(function(){t.style.opacity='0';setTimeout(function(){t.style.display='none';},300);},2800);
}
document.getElementById('srf')?.addEventListener('submit',function(){
    var b=document.getElementById('gBtn');b.disabled=true;b.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Generating…';
});
(function(){
    var top=document.getElementById('topScroll'),main=document.getElementById('mainScroll');
    if(!top||!main)return;
    var inner=document.getElementById('topScrollInner');
    function setW(){var tbl=main.querySelector('table.cct');if(tbl)inner.style.width=tbl.scrollWidth+'px';}
    setW();window.addEventListener('resize',setW);
    var syncing=false;
    top.addEventListener('scroll',function(){if(syncing)return;syncing=true;main.scrollLeft=top.scrollLeft;syncing=false;});
    main.addEventListener('scroll',function(){if(syncing)return;syncing=true;top.scrollLeft=main.scrollLeft;syncing=false;});
})();
$(document).ready(function(){
    $('#tr_form_to_sr').select2({dropdownParent:$('#transferModal'),placeholder:'— Select Target Rep —',allowClear:true,width:'resolve'});
});
</script>
<?php include 'footer.php';?>