<?php
include 'config.php';

/* ═══════════════════════════════════════════════════════
   AJAX — DELETE allocation
   Called via fetch POST {action:'delete', pay_date, sr_code}
════════════════════════════════════════════════════════ */
if(isset($_POST['action']) && $_POST['action']==='delete'){
    header('Content-Type: application/json');
    $pay_date = mysqli_real_escape_string($conn, $_POST['pay_date'] ?? '');
    $sr_code  = mysqli_real_escape_string($conn, $_POST['sr_code']  ?? '');
    if(!$pay_date || !$sr_code){ echo json_encode(['success'=>false,'message'=>'Missing params']); exit; }
    $ok = mysqli_query($conn,
        "DELETE FROM cash_summary_pay_allocations WHERE pay_date='$pay_date' AND sr_code='$sr_code'");
    echo json_encode($ok
        ? ['success'=>true]
        : ['success'=>false,'message'=>mysqli_error($conn)]);
    exit;
}

include 'header.php';

/* ═══ FILTERS ═══ */
$date_from  = $_GET['date_from']   ?? date('Y-m-d', strtotime('-30 days'));
$date_to    = $_GET['date_to']     ?? date('Y-m-d');
$f_sr       = trim($_GET['sr_code']   ?? '');
$f_amt_min  = trim($_GET['amt_min']   ?? '');
$f_amt_max  = trim($_GET['amt_max']   ?? '');
$f_status   = $_GET['status']         ?? '';          // all | paid | unpaid
$submitted  = isset($_GET['search']);

$df      = mysqli_real_escape_string($conn, $date_from);
$dt      = mysqli_real_escape_string($conn, $date_to);
$sr_esc  = $f_sr ? mysqli_real_escape_string($conn, $f_sr) : '';

/* SR dropdown */
$sr_res = mysqli_query($conn,"SELECT DISTINCT sr_code FROM field_summary WHERE sr_code IS NOT NULL AND sr_code!='' ORDER BY sr_code");
$all_sr=[]; if($sr_res) while($r=mysqli_fetch_assoc($sr_res)) $all_sr[]=$r['sr_code'];

/* Employee list for edit modal */
$emp_list=[];
$er=mysqli_query($conn,"SELECT id,
    COALESCE(NULLIF(employee_id,''),CONCAT('EMP-',id)) AS emp_code,
    COALESCE(NULLIF(name_with_initials,''),NULLIF(employee_full_name,''),CONCAT('Employee #',id)) AS emp_name
    FROM employees
    WHERE COALESCE(status,'') NOT IN ('Resigned','Terminated','Inactive','inactive','resigned','terminated')
    ORDER BY emp_name ASC");
if(!$er||mysqli_num_rows($er)===0)
    $er=mysqli_query($conn,"SELECT id,
        COALESCE(NULLIF(employee_id,''),CONCAT('EMP-',id)) AS emp_code,
        COALESCE(NULLIF(name_with_initials,''),NULLIF(employee_full_name,''),CONCAT('Employee #',id)) AS emp_name
        FROM employees ORDER BY emp_name ASC LIMIT 500");
if($er) while($row=mysqli_fetch_assoc($er)) $emp_list[]=$row;

/* ═══ DATA ═══ */
$rows=[];
$totals=['total_coll'=>0,'banked'=>0,'handed'=>0,'bank_diff'=>0,'cash_short'=>0,'p_charge'=>0,'p_absorb'=>0];

if($submitted){
    $where_fs = "fs.delivery_date BETWEEN '$df' AND '$dt'" . ($sr_esc?" AND fs.sr_code='$sr_esc'":'');
    $srCnd    = $sr_esc ? "AND fs.sr_code='$sr_esc'" : '';

    /* master list */
    $r1=mysqli_query($conn,"SELECT DISTINCT fs.sr_code, fs.delivery_date
        FROM field_summary fs WHERE $where_fs
        AND fs.sr_code IS NOT NULL AND fs.sr_code!=''
        ORDER BY fs.delivery_date, fs.sr_code");
    $master=[];
    if($r1) while($r=mysqli_fetch_assoc($r1)) $master[]=['date'=>$r['delivery_date'],'sr'=>$r['sr_code']];

    /* cash collected (total_coll) */
    $r2=mysqli_query($conn,"
        SELECT ip.payment_date, fs.sr_code, COALESCE(SUM(ip.amount),0) AS total_coll
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id=ip.field_summary_detail_id
        INNER JOIN field_summary fs ON fs.id=fsd.field_summary_id
        WHERE ip.payment_method='cash' AND ip.is_reversed=0
          AND ip.payment_date BETWEEN '$df' AND '$dt' $srCnd
        GROUP BY ip.payment_date, fs.sr_code");
    $coll_map=[];
    if($r2) while($r=mysqli_fetch_assoc($r2)) $coll_map[$r['payment_date'].'|'.$r['sr_code']]=floatval($r['total_coll']);

    /* banked / handed */
    $dep_cnd = "d.delivery_date BETWEEN '$df' AND '$dt'" . ($sr_esc?" AND r.rep_code='$sr_esc'":'');
    $r3=mysqli_query($conn,"
        SELECT d.delivery_date, r.rep_code,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=0 THEN r.amount ELSE 0 END),0) AS banked,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=1 THEN r.amount ELSE 0 END),0) AS handed
        FROM cc_cash_deposits d
        INNER JOIN cc_cash_deposit_reps r ON r.deposit_id=d.id
        WHERE $dep_cnd
        GROUP BY d.delivery_date, r.rep_code");
    $dep_map=[];
    if($r3) while($r=mysqli_fetch_assoc($r3)) $dep_map[$r['delivery_date'].'|'.$r['rep_code']]=$r;

    /* pay allocations */
    $r4=mysqli_query($conn,"
        SELECT pay_date, sr_code, entry_type, employee_id, employee_name, amount
        FROM cash_summary_pay_allocations
        WHERE pay_date BETWEEN '$df' AND '$dt'" . ($sr_esc?" AND sr_code='$sr_esc'":'') . "
        ORDER BY pay_date, sr_code, entry_type");
    $alloc_map=[];
    if($r4) while($r=mysqli_fetch_assoc($r4)){
        $k=$r['pay_date'].'|'.$r['sr_code'];
        if(!isset($alloc_map[$k])) $alloc_map[$k]=['p_charge'=>0,'p_absorb'=>0,'p_variance'=>null,'employees'=>[],'is_paid'=>false];
        if($r['entry_type']==='charge'){ $alloc_map[$k]['p_charge']+=floatval($r['amount']); $alloc_map[$k]['employees'][]=$r; $alloc_map[$k]['is_paid']=true; }
        if($r['entry_type']==='absorb'){ $alloc_map[$k]['p_absorb']=floatval($r['amount']); $alloc_map[$k]['is_paid']=true; }
        if($r['entry_type']==='variance') $alloc_map[$k]['p_variance']=floatval($r['amount']);
    }

    foreach($master as $m){
        $k=$m['date'].'|'.$m['sr'];
        $total_coll=floatval($coll_map[$k]??0);
        $dm=$dep_map[$k]??[];
        $banked=floatval($dm['banked']??0); $handed=floatval($dm['handed']??0);
        /* bank_diff: positive = short, negative = excess (matches cash_collection.php logic) */
        $bank_diff  = $total_coll - $banked - $handed;
        $cash_short = $bank_diff > 0 ? $bank_diff : 0;
        $cash_excess= $bank_diff < 0 ? abs($bank_diff) : 0;
        /* row_type: 'short' | 'excess' | 'ok' */
        $row_type = $bank_diff > 0.005 ? 'short' : ($bank_diff < -0.005 ? 'excess' : 'ok');

        $pa=$alloc_map[$k]??[];
        $p_charge=floatval($pa['p_charge']??0); $p_absorb=floatval($pa['p_absorb']??0);
        $p_variance=isset($pa['p_variance'])?floatval($pa['p_variance']):null;
        $employees=$pa['employees']??[]; $is_paid=(bool)($pa['is_paid']??false);

        /* only rows with a charge-to-employee entry */
        if($p_charge <= 0) continue;
        /* amount filter (on p_charge) */
        if($f_amt_min!=='' && $p_charge < floatval($f_amt_min)) continue;
        if($f_amt_max!=='' && $p_charge > floatval($f_amt_max)) continue;
        /* status filter */
        if($f_status==='paid'   && !$is_paid)  continue;
        if($f_status==='unpaid' && $is_paid)   continue;

        $rows[]=array_merge(compact(
            'total_coll','banked','handed','bank_diff','cash_short','cash_excess','row_type',
            'p_charge','p_absorb','p_variance','employees','is_paid'
        ),['del_date'=>$m['date'],'sr_code'=>$m['sr']]);

        foreach(array_keys($totals) as $tk){ if(isset($$tk)) $totals[$tk]+=floatval($$tk??0); }
    }
}

function fmt($v,$dash=true){
    $n=floatval($v);
    if($n==0&&$dash) return '<span class="dash">—</span>';
    return '<span class="num">'.number_format(abs($n),2).'</span>';
}
function fmtT($v){$n=floatval($v);return $n==0?'—':number_format($n,2);}

/* Render the Type+Amount badge for table and modal */
function render_type_badge($row_type, $bank_diff){
    $val = number_format(abs($bank_diff),2);
    if($row_type==='short')
        return '<span class="type-badge type-short"><i class="fa-solid fa-arrow-down"></i> Short &nbsp;<span class="tb-val">'.$val.'</span></span>';
    if($row_type==='excess')
        return '<span class="type-badge type-excess"><i class="fa-solid fa-arrow-up"></i> Excess &nbsp;<span class="tb-val">'.$val.'</span></span>';
    return '<span class="type-badge type-ok"><i class="fa-solid fa-check"></i> OK</span>';
}
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');
@import url('https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css');
:root{
    --bg:#eef0f3;--surface:#fff;--bdr:#d2d6db;--bdrs:#e4e7ec;
    --tx:#1a1f2e;--txm:#58626e;--txs:#9aa3ad;
    --fn:'Inter',sans-serif;--mn:'JetBrains Mono',monospace;
    --r:8px;--sh:0 1px 3px rgba(0,0,0,.07),0 4px 14px rgba(0,0,0,.05);
    --red:#dc2626;--grn:#166534;--blu:#1e40af;--amb:#92400e;
    --total-bg:#0f172a;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:13px;}
.pg{padding:22px 18px 60px;}
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;}
.pg-h1{font-size:22px;font-weight:800;letter-spacing:-.02em;}
.pg-h1 em{color:#dc2626;font-style:normal;}
.pg-sub{font-size:11px;color:var(--txs);margin-top:3px;}
.dpill{display:inline-flex;align-items:center;gap:6px;background:#fef2f2;border:1px solid #fca5a5;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;color:#991b1b;font-family:var(--mn);}

/* Filter bar */
.fbar{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:12px 15px;margin-bottom:14px;display:flex;align-items:flex-end;gap:11px;flex-wrap:wrap;box-shadow:var(--sh);}
.fg{display:flex;flex-direction:column;gap:3px;}
.fg label{font-size:10px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.07em;}
.fg input,.fg select{padding:7px 10px;border:1.5px solid var(--bdr);border-radius:6px;font-size:13px;font-family:var(--fn);color:var(--tx);background:#fff;min-width:0;}
.fg input:focus,.fg select:focus{outline:none;border-color:#dc2626;}
.amt-pair{display:flex;gap:6px;align-items:flex-end;}
.amt-pair .fg input{width:110px;}
.btn-go{display:inline-flex;align-items:center;gap:6px;padding:8px 20px;background:#1e293b;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;}
.btn-go:hover{background:#334155;}
.btn-rst{display:inline-flex;align-items:center;gap:5px;padding:8px 12px;background:#f0f0f0;color:var(--txm);border:1px solid var(--bdr);border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;}
.btn-excel{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:linear-gradient(135deg,#166534,#15803d);color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;}

/* Summary cards */
.sc-row{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:16px;}
.sc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:12px 14px;box-shadow:var(--sh);border-left:3px solid #e5e7eb;}
.sc.blue{border-left-color:#3b82f6;} .sc.green{border-left-color:#22c55e;}
.sc.purple{border-left-color:#a855f7;} .sc.red{border-left-color:#ef4444;}
.sc.amber{border-left-color:#f59e0b;} .sc.teal{border-left-color:#14b8a6;}
.sc-lbl{font-size:10px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;}
.sc-val{font-size:15px;font-weight:800;}

/* Table container */
.tc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);overflow:hidden;box-shadow:var(--sh);}
.tc-bar{display:flex;justify-content:space-between;align-items:center;padding:11px 15px;border-bottom:1px solid var(--bdrs);flex-wrap:wrap;gap:8px;}
.tc-ttl{font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.pill{padding:2px 9px;border-radius:12px;font-size:10px;font-weight:700;}
.p-red{background:#fee2e2;color:#991b1b;} .p-green{background:#dcfce7;color:#166534;}
.p-blue{background:#dbeafe;color:#1e40af;} .p-slate{background:#f1f5f9;color:#475569;}
.top-scroll-wrap{overflow-x:auto;overflow-y:hidden;height:12px;margin-bottom:2px;}
.top-scroll-inner{height:1px;}
.tscroll{overflow-x:auto;}

/* Main table */
table.csr{width:100%;border-collapse:collapse;font-size:12px;}
.csr thead tr.G th{padding:8px 10px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#fff;text-align:center;white-space:nowrap;background:#1e293b;}
.csr thead tr.G th.tl{text-align:left;}
.csr thead tr.S th{padding:5px 10px;font-size:9px;font-weight:700;color:rgba(255,255,255,.85);text-align:right;white-space:nowrap;background:#2d3748;border-bottom:2px solid var(--bdr);}
.csr thead tr.S th.tl{text-align:left;} .csr thead tr.S th.tc{text-align:center;}
.csr thead tr.TH td{background:#fef2f2;color:#991b1b;font-weight:800;font-size:11px;padding:7px 10px;text-align:right;border-bottom:2px solid #fca5a5;white-space:nowrap;}
.csr thead tr.TH td.tl{text-align:left;}
.csr tbody tr td{background:#fff;padding:7px 10px;text-align:right;white-space:nowrap;border-bottom:1px solid #f3f4f6;}
.csr tbody tr.stripe td{background:#fafbfc;}
.csr tbody tr:hover td{background:#fff5f5!important;}
.csr tbody td.tl{text-align:left;} .csr tbody td.tc{text-align:center;}
.csr tbody td.rc{font-weight:700;font-size:12px;}
.csr tbody td.dt{font-family:var(--mn);font-size:10px;color:var(--txm);}
.csr tfoot td{padding:8px 10px;font-weight:800;font-size:12px;background:var(--total-bg);color:#e2e8f0;text-align:right;white-space:nowrap;}
.csr tfoot td.tl{text-align:left;color:#94a3b8;}
.num{font-family:var(--mn);font-size:11px;}
.dash{color:#d1d5db;}
.short-val{color:#dc2626;font-weight:700;font-family:var(--mn);font-size:12px;}
.rec-val{color:#166534;font-weight:700;font-family:var(--mn);font-size:12px;}
.stk{position:sticky;left:0;z-index:2;box-shadow:3px 0 8px rgba(0,0,0,.08);}
.csr thead th.stk{z-index:4;background:#1e293b!important;}
.csr thead .S th.stk{background:#2d3748!important;}
.csr thead .TH td.stk{background:#fef2f2!important;}
.csr tfoot td.stk{background:var(--total-bg)!important;}
.csr tbody tr td.stk{background:#fff!important;}
.csr tbody tr.stripe td.stk{background:#fafbfc!important;}
.csr tbody tr:hover td.stk{background:#fff5f5!important;}

/* ── Type badge ── */
.type-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:10.5px;font-weight:700;white-space:nowrap;}
.type-badge .tb-val{font-family:var(--mn);font-size:10.5px;}
.type-short{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.type-excess{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.type-ok{background:#f0fdf4;color:#166534;border:1px solid #86efac;}

/* Status badge */
.badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:12px;font-size:10px;font-weight:700;white-space:nowrap;}
.badge-paid{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.badge-unpaid{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}

/* Action buttons */
.act-btns{display:flex;gap:5px;justify-content:center;align-items:center;}
.btn-act{display:inline-flex;align-items:center;gap:3px;padding:4px 9px;border-radius:5px;font-size:11px;font-weight:600;cursor:pointer;border:none;font-family:inherit;transition:all .15s;white-space:nowrap;}
.btn-view{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;}
.btn-view:hover{background:#dbeafe;}
.btn-edit{background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;}
.btn-edit:hover{background:#ede9fe;}
.btn-del{background:#fff5f5;color:#dc2626;border:1px solid #fecaca;}
.btn-del:hover{background:#fee2e2;}

.empty{text-align:center;padding:60px 20px;color:var(--txs);}
.empty i{font-size:42px;display:block;margin-bottom:12px;opacity:.25;}

/* ═══ MODALS ═══ */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1050;display:none;align-items:center;justify-content:center;padding:12px;}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;border-radius:14px;width:100%;max-width:580px;max-height:96vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,.28);}
.modal-box.wide{max-width:620px;}
.mo-head{padding:18px 22px 14px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:flex-start;flex-shrink:0;}
.mo-icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;flex-shrink:0;}
.mo-icon.red{background:linear-gradient(135deg,#dc2626,#b91c1c);}
.mo-icon.purple{background:linear-gradient(135deg,#7c3aed,#6d28d9);}
.mo-icon.blue{background:linear-gradient(135deg,#1e40af,#1d4ed8);}
.mo-title{font-size:15px;font-weight:700;color:#111827;}
.mo-sub{font-size:11px;color:#9ca3af;margin-top:2px;}
.mo-close{background:none;border:none;cursor:pointer;color:#9ca3af;font-size:22px;line-height:1;padding:4px;border-radius:6px;}
.mo-close:hover{color:#1f2937;background:#f3f4f6;}

/* Info grid — 4 cells on wide modal, 2×2 on narrow */
.mo-info{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px 22px;background:#f9fafb;border-bottom:1px solid #f0f0f0;flex-shrink:0;}
.mo-info.wide4{grid-template-columns:1fr 1fr 1fr 1fr;}
.mo-cell{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:9px 13px;}
.mo-cell.hi-red{background:linear-gradient(135deg,#fff5f5,#fff);border-color:#fca5a5;}
.mo-cell.hi-green{background:linear-gradient(135deg,#f0fdf4,#fff);border-color:#86efac;}
.mo-lbl{font-size:9.5px;color:#9ca3af;text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:3px;}
.mo-val{font-size:13px;font-weight:700;color:#1f2937;font-family:var(--mn);}
.mo-cell.hi-red .mo-val{color:#dc2626;font-size:17px;}
.mo-cell.hi-green .mo-val{color:#166534;font-size:17px;}
/* type pill inside modal info cell */
.mo-type-pill{display:inline-flex;align-items:center;gap:5px;margin-top:4px;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:800;font-family:var(--mn);}
.mtp-short{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.mtp-excess{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.mtp-ok{background:#f0fdf4;color:#166534;border:1px solid #86efac;}

.mo-body{padding:18px 22px;overflow-y:auto;flex:1;}
.mo-foot{padding:14px 22px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0;}
.mo-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:inherit;transition:all .15s;}
.mo-btn-cancel{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;}
.mo-btn-cancel:hover{background:#e2e8f0;}
.mo-btn-save{background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;box-shadow:0 2px 10px rgba(124,58,237,.3);}
.mo-btn-save:hover{background:linear-gradient(135deg,#6d28d9,#5b21b6);}
.mo-btn-save:disabled{opacity:.5;cursor:not-allowed;}
.mo-btn-danger{background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;}
.mo-btn-danger:hover{background:linear-gradient(135deg,#b91c1c,#991b1b);}

/* View modal specifics */
.alloc-section{border:1px solid #e5e7eb;border-radius:9px;overflow:hidden;margin-bottom:12px;}
.alloc-head{display:flex;justify-content:space-between;align-items:center;padding:9px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;}
.alloc-head.red{background:#fef2f2;color:#991b1b;border-bottom:1px solid #fecaca;}
.alloc-head.blue{background:#eff6ff;color:#1e40af;border-bottom:1px solid #bfdbfe;}
.alloc-head.amber{background:#fffbeb;color:#92400e;border-bottom:1px solid #fde68a;}
.alloc-head .ah-val{font-size:14px;font-weight:800;font-family:var(--mn);letter-spacing:0;}
.alloc-body{padding:12px 14px;background:#fff;}
.emp-item{display:flex;justify-content:space-between;align-items:center;padding:7px 10px;background:#fafafa;border:1px solid #f0f0f0;border-radius:6px;margin-bottom:6px;font-size:12px;}
.emp-item:last-child{margin-bottom:0;}
.emp-item .ei-name{font-weight:600;color:#374151;}
.emp-item .ei-amt{font-weight:700;color:#dc2626;font-family:var(--mn);}
.no-alloc{font-size:12px;color:#9ca3af;text-align:center;padding:10px;font-style:italic;}
.var-row{display:flex;justify-content:space-between;align-items:center;padding:7px 10px;font-size:12px;}
.var-row .vr-lbl{font-weight:600;color:#92400e;}
.var-row .vr-val{font-weight:700;font-family:var(--mn);}

/* Edit modal — pay alloc sections */
.pmo-section{border:1px solid #e5e7eb;border-radius:10px;margin-bottom:14px;overflow:hidden;}
.pmo-sec-head{display:flex;justify-content:space-between;align-items:center;padding:10px 15px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;}
.pmo-sec-head.red{background:#fef2f2;color:#991b1b;border-bottom:1px solid #fecaca;}
.pmo-sec-head.blue{background:#eff6ff;color:#1e40af;border-bottom:1px solid #bfdbfe;}
.pmo-sec-total{font-size:15px;font-weight:800;font-family:var(--mn);letter-spacing:0;}
.pmo-sec-body{padding:14px 15px;}
.emp-row{display:flex;align-items:center;gap:7px;margin-bottom:7px;flex-wrap:wrap;}
.emp-row .emp-sel{flex:1;min-width:150px;border:1.5px solid #e5e7eb;border-radius:7px;padding:7px 9px;font-size:12px;font-family:var(--fn);color:#1f2937;background:#fff;outline:none;}
.emp-row .emp-sel:focus{border-color:#7c3aed;}
.emp-row .emp-amt{width:110px;flex-shrink:0;border:1.5px solid #e5e7eb;border-radius:7px;padding:7px 9px;font-size:13px;text-align:right;outline:none;font-family:var(--mn);}
.emp-row .emp-amt:focus{border-color:#7c3aed;}
.emp-row .emp-del{background:none;border:none;cursor:pointer;color:#dc2626;font-size:17px;padding:3px 5px;border-radius:5px;line-height:1;flex-shrink:0;}
.emp-row .emp-del:hover{background:#fef2f2;}
.add-emp-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border:1.5px dashed #fca5a5;border-radius:7px;background:#fff;color:#dc2626;font-size:11px;font-weight:600;cursor:pointer;margin-top:4px;font-family:inherit;}
.add-emp-btn:hover{background:#fef2f2;}
.absorb-row{display:flex;align-items:center;gap:10px;}
.absorb-lbl{font-size:12px;color:#1e40af;font-weight:600;flex:1;}
.absorb-inp{width:120px;flex-shrink:0;border:1.5px solid #bfdbfe;border-radius:7px;padding:7px 10px;font-size:13px;text-align:right;outline:none;font-family:var(--mn);}
.absorb-inp:focus{border-color:#1e40af;}

/* Summary totals strip — 4 cells matching cash_collection.php pay modal */
.pmo-totals{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:14px;padding:12px 14px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:9px;}
.ptb-cell{text-align:center;}
.ptb-lbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;font-weight:600;margin-bottom:5px;}
.ptb-val{font-size:16px;font-weight:800;font-family:var(--mn);}

/* Confirm delete */
.confirm-body{padding:22px 22px 0;text-align:center;}
.confirm-icon{font-size:42px;color:#dc2626;margin-bottom:12px;}
.confirm-title{font-size:15px;font-weight:700;margin-bottom:6px;}
.confirm-msg{font-size:13px;color:#6b7280;line-height:1.6;}

/* Toast */
#csrToast{position:fixed;bottom:24px;right:24px;padding:11px 18px;border-radius:8px;font-size:13px;font-weight:600;z-index:9999;display:none;opacity:0;transition:opacity .3s;}
.toast-ok{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.toast-err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}

@media(max-width:900px){.sc-row{grid-template-columns:repeat(3,1fr);}.mo-info.wide4{grid-template-columns:1fr 1fr;}.pmo-totals{grid-template-columns:1fr 1fr;}}
@media(max-width:600px){.sc-row{grid-template-columns:1fr 1fr;}.mo-info{grid-template-columns:1fr;}.pmo-totals{grid-template-columns:1fr 1fr;}}
@media print{.no-print{display:none!important;}thead th,tfoot td{-webkit-print-color-adjust:exact;print-color-adjust:exact;}}

/* ── Select2 overrides ── */
.select2-container{font-family:var(--fn);font-size:13px;}
.select2-container .select2-selection--single{height:34px;border:1.5px solid var(--bdr);border-radius:6px;background:#fff;display:flex;align-items:center;}
.select2-container--default .select2-selection--single .select2-selection__rendered{color:var(--tx);line-height:34px;padding-left:10px;padding-right:28px;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:32px;right:6px;}
.select2-container--default .select2-selection--single .select2-selection__arrow b{border-color:var(--txs) transparent transparent transparent;}
.select2-container--default.select2-container--open .select2-selection--single .select2-selection__arrow b{border-color:transparent transparent #dc2626 transparent;}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open .select2-selection--single{border-color:#dc2626;outline:none;box-shadow:0 0 0 3px rgba(220,38,38,.1);}
.select2-dropdown{border:1.5px solid var(--bdr);border-radius:7px;box-shadow:0 8px 24px rgba(0,0,0,.12);overflow:hidden;font-size:13px;}
.select2-search--dropdown .select2-search__field{border:1.5px solid var(--bdr);border-radius:5px;padding:5px 9px;font-size:12px;font-family:var(--fn);outline:none;}
.select2-search--dropdown .select2-search__field:focus{border-color:#dc2626;}
.select2-results__option{padding:7px 12px;color:var(--tx);}
.select2-container--default .select2-results__option--highlighted[aria-selected]{background:#fee2e2;color:#991b1b;}
.select2-container--default .select2-results__option[aria-selected=true]{background:#fef2f2;color:#dc2626;font-weight:600;}
/* modal employee selects — purple accent */
.modal-overlay .select2-container--default.select2-container--focus .select2-selection--single,
.modal-overlay .select2-container--default.select2-container--open .select2-selection--single{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.1);}
.modal-overlay .select2-container--default.select2-container--open .select2-selection--single .select2-selection__arrow b{border-color:transparent transparent #7c3aed transparent;}
.modal-overlay .select2-search--dropdown .select2-search__field:focus{border-color:#7c3aed;}
.modal-overlay .select2-container--default .select2-results__option--highlighted[aria-selected]{background:#ede9fe;color:#6d28d9;}
.modal-overlay .select2-container--default .select2-results__option[aria-selected=true]{background:#f5f3ff;color:#7c3aed;font-weight:600;}
/* emp-row select2 sizing */
.emp-row .select2-container{flex:1;min-width:150px;}
.emp-row .select2-container .select2-selection--single{height:32px;}
.emp-row .select2-container .select2-selection--single .select2-selection__rendered{line-height:30px;font-size:12px;}
.emp-row .select2-container .select2-selection--single .select2-selection__arrow{height:30px;}
</style>

<div class="pg">
<div class="topbar no-print">
    <div>
        <div class="pg-h1">Cash Shortage <em>Recovery</em></div>
        <div class="pg-sub">Daily Cash Short · Bank Deposit · Handed to Office · Recovered Amounts · Allocations</div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <div class="dpill"><i class="fa-solid fa-triangle-exclamation"></i>
            <?php echo date('d M Y',strtotime($date_from)).' — '.date('d M Y',strtotime($date_to));?>
        </div>
        <?php if($submitted && !empty($rows)):?>
        <button onclick="exportToExcel()" class="btn-excel"><i class="fa-solid fa-file-excel"></i> Export</button>
        <button onclick="window.print()" class="btn-rst"><i class="fa-solid fa-print"></i> Print</button>
        <?php endif;?>
    </div>
</div>

<!-- FILTER BAR -->
<div class="fbar no-print">
    <form method="GET" id="csrForm" style="display:contents;">
        <input type="hidden" name="search" value="1">
        <div class="fg">
            <label><i class="fa-solid fa-calendar-day"></i> Date From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from);?>">
        </div>
        <div class="fg">
            <label><i class="fa-solid fa-calendar-day"></i> Date To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to);?>">
        </div>
        <div class="fg" style="min-width:150px;">
            <label><i class="fa-solid fa-id-badge"></i> Sales Rep</label>
            <select name="sr_code" id="sel_sr">
                <option value="">— All Reps —</option>
                <?php foreach($all_sr as $sr):?>
                <option value="<?php echo htmlspecialchars($sr);?>" <?php echo $f_sr===$sr?'selected':'';?>>
                    <?php echo htmlspecialchars($sr);?></option>
                <?php endforeach;?>
            </select>
        </div>
        <div class="fg">
            <label><i class="fa-solid fa-triangle-exclamation"></i> Shortage (Min–Max)</label>
            <div class="amt-pair">
                <div class="fg"><input type="number" name="amt_min" placeholder="Min" step="0.01" min="0" value="<?php echo htmlspecialchars($f_amt_min);?>" style="width:100px;"></div>
                <div class="fg"><input type="number" name="amt_max" placeholder="Max" step="0.01" min="0" value="<?php echo htmlspecialchars($f_amt_max);?>" style="width:100px;"></div>
            </div>
        </div>
        <div class="fg" style="min-width:120px;">
            <label><i class="fa-solid fa-circle-half-stroke"></i> Status</label>
            <select name="status" id="sel_status">
                <option value=""   <?php echo $f_status===''?'selected':'';?>>— All —</option>
                <option value="paid"   <?php echo $f_status==='paid'?'selected':'';?>>Recovered</option>
                <option value="unpaid" <?php echo $f_status==='unpaid'?'selected':'';?>>Pending</option>
            </select>
        </div>
        <button type="submit" class="btn-go" id="csrBtn"><i class="fa-solid fa-magnifying-glass"></i> Generate</button>
        <a href="cash_shortage_recovery.php" class="btn-rst" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
    </form>
</div>

<?php if(!$submitted):?>
<div class="tc"><div class="empty">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <p style="font-size:14px;font-weight:600;margin-bottom:6px;">Cash Shortage Recovery Report</p>
    <p>Set date range and click <strong>Generate</strong> to view shortage records.</p>
</div></div>

<?php elseif(empty($rows)):?>
<div class="tc"><div class="empty">
    <i class="fa-solid fa-inbox"></i>
    <p style="font-size:14px;font-weight:600;margin-bottom:6px;">No records found</p>
    <p><?php echo date('d M Y',strtotime($date_from)).' – '.date('d M Y',strtotime($date_to));
        echo $f_sr?' &nbsp;·&nbsp; Rep: <strong>'.htmlspecialchars($f_sr).'</strong>':'';?></p>
</div></div>

<?php else:
    $cnt_paid   = count(array_filter($rows, fn($r)=>$r['is_paid']));
    $cnt_unpaid = count($rows)-$cnt_paid;
?>
<!-- SUMMARY CARDS -->
<div class="sc-row no-print">
    <div class="sc blue">
        <div class="sc-lbl"><i class="fa-solid fa-money-bill-wave"></i> Total Collected</div>
        <div class="sc-val" style="color:#1e40af;">Rs. <?php echo number_format($totals['total_coll'],2);?></div>
    </div>
    <div class="sc green">
        <div class="sc-lbl"><i class="fa-solid fa-building-columns"></i> Bank Deposit</div>
        <div class="sc-val" style="color:#166534;">Rs. <?php echo number_format($totals['banked'],2);?></div>
    </div>
    <div class="sc teal">
        <div class="sc-lbl"><i class="fa-solid fa-building-user"></i> Handed to Office</div>
        <div class="sc-val" style="color:#0f766e;">Rs. <?php echo number_format($totals['handed'],2);?></div>
    </div>
    <div class="sc red">
        <div class="sc-lbl"><i class="fa-solid fa-triangle-exclamation"></i> Cash Shortage</div>
        <div class="sc-val" style="color:#991b1b;">Rs. <?php echo number_format($totals['cash_short'],2);?></div>
    </div>
    <div class="sc purple">
        <div class="sc-lbl"><i class="fa-solid fa-user-minus"></i> Charged to Emp</div>
        <div class="sc-val" style="color:#7c3aed;">Rs. <?php echo number_format($totals['p_charge'],2);?></div>
    </div>
    <div class="sc amber">
        <div class="sc-lbl"><i class="fa-solid fa-check-circle"></i> Recovered Status</div>
        <div class="sc-val" style="font-size:13px;display:flex;gap:8px;flex-wrap:wrap;margin-top:2px;">
            <span style="color:#166534;"><?php echo $cnt_paid;?> Paid</span>
            <span style="color:#991b1b;"><?php echo $cnt_unpaid;?> Pending</span>
        </div>
    </div>
</div>

<div class="tc">
    <div class="tc-bar no-print">
        <div class="tc-ttl">
            <i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i>
            Cash Shortage Recovery
            <span class="pill p-red"><?php echo count($rows);?> records</span>
            <span class="pill p-green"><?php echo $cnt_paid;?> Recovered</span>
            <span class="pill p-slate"><?php echo $cnt_unpaid;?> Pending</span>
        </div>
    </div>
    <div class="top-scroll-wrap no-print" id="topScroll"><div class="top-scroll-inner" id="topScrollInner"></div></div>
    <div class="tscroll" id="mainScroll">
    <table class="csr" id="csrMain">
      <thead>
        <tr class="G">
            <th class="tl stk" rowspan="2" style="min-width:90px;">Rep Code</th>
            <th class="tl"     rowspan="2" style="min-width:100px;">Date</th>
            <th colspan="3">Cash Flow</th>
            <th colspan="1" style="background:#065f46;">Type</th>
            <th colspan="1" style="background:#991b1b;">Shortage</th>
            <th colspan="2" style="background:#6d28d9;">Recovery</th>
            <th rowspan="2" style="min-width:80px;">Status</th>
            <th rowspan="2" style="min-width:120px;text-align:center;" class="no-print">Actions</th>
        </tr>
        <tr class="S">
            <th style="min-width:130px;">Total Collected</th>
            <th style="min-width:130px;">Bank Deposit</th>
            <th style="min-width:130px;">HO Office</th>
            <th style="min-width:140px;background:#064e3b;text-align:center;">Short / Excess</th>
            <th style="min-width:120px;background:#7f1d1d;">Cash Short</th>
            <th style="min-width:120px;background:#4c1d95;">Charged to Emp</th>
            <th style="min-width:120px;background:#4c1d95;">Absorbed by Co.</th>
        </tr>
        <tr class="TH">
            <td class="tl stk" colspan="2"><i class="fa-solid fa-sigma"></i>&nbsp; Total (<?php echo count($rows);?> rows)</td>
            <td><?php echo fmtT($totals['total_coll']);?></td>
            <td><?php echo fmtT($totals['banked']);?></td>
            <td><?php echo fmtT($totals['handed']);?></td>
            <td>—</td>
            <td style="color:#dc2626;font-weight:800;"><?php echo fmtT($totals['cash_short']);?></td>
            <td style="color:#7c3aed;font-weight:800;"><?php echo fmtT($totals['p_charge']);?></td>
            <td><?php echo fmtT($totals['p_absorb']);?></td>
            <td></td><td class="no-print"></td>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $i=>$r):
          $stripe=$i%2!==0?'stripe':'';
      ?>
      <tr class="<?php echo $stripe;?>" id="row-<?php echo $i;?>">
          <td class="tl rc stk"><?php echo htmlspecialchars($r['sr_code']);?></td>
          <td class="tl dt"><?php echo date('d M Y',strtotime($r['del_date']));?></td>
          <td><?php echo fmt($r['total_coll']);?></td>
          <td><?php echo fmt($r['banked']);?></td>
          <td><?php echo fmt($r['handed']);?></td>
          <!-- TYPE column -->
          <td class="tc" id="tp-<?php echo $i;?>"><?php echo render_type_badge($r['row_type'],$r['bank_diff']);?></td>
          <td id="cs-<?php echo $i;?>">
            <?php if($r['cash_short']>0):?>
              <span class="short-val"><?php echo number_format($r['cash_short'],2);?></span>
            <?php else:?><span class="dash">—</span><?php endif;?>
          </td>
          <td id="ch-<?php echo $i;?>">
            <?php if($r['p_charge']>0):?>
              <span class="rec-val"><?php echo number_format($r['p_charge'],2);?></span>
            <?php else:?><span class="dash">—</span><?php endif;?>
          </td>
          <td id="ab-<?php echo $i;?>">
            <?php if($r['p_absorb']>0):?>
              <span style="color:#1e40af;font-weight:700;font-family:var(--mn);"><?php echo number_format($r['p_absorb'],2);?></span>
            <?php else:?><span class="dash">—</span><?php endif;?>
          </td>
          <td class="tc" id="st-<?php echo $i;?>">
            <?php if($r['is_paid']):?>
              <span class="badge badge-paid"><i class="fa-solid fa-check"></i> Recovered</span>
            <?php else:?>
              <span class="badge badge-unpaid"><i class="fa-solid fa-clock"></i> Pending</span>
            <?php endif;?>
          </td>
          <td class="tc no-print">
            <div class="act-btns">
                <button class="btn-act btn-view" onclick="openView(<?php echo $i;?>)" title="View Details">
                    <i class="fa-solid fa-eye"></i> View
                </button>
                <button class="btn-act btn-edit" onclick="openEdit(<?php echo $i;?>)" title="Edit Allocation">
                    <i class="fa-solid fa-pen"></i> Edit
                </button>
                <button class="btn-act btn-del" onclick="confirmDelete(<?php echo $i;?>)" title="Delete Allocation">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
          </td>
      </tr>
      <?php endforeach;?>
      </tbody>
      <tfoot>
        <tr>
            <td class="tl stk" colspan="2">TOTAL — <?php echo count($rows);?> records</td>
            <td><?php echo fmtT($totals['total_coll']);?></td>
            <td><?php echo fmtT($totals['banked']);?></td>
            <td><?php echo fmtT($totals['handed']);?></td>
            <td>—</td>
            <td style="color:#fca5a5;"><?php echo fmtT($totals['cash_short']);?></td>
            <td style="color:#c4b5fd;"><?php echo fmtT($totals['p_charge']);?></td>
            <td><?php echo fmtT($totals['p_absorb']);?></td>
            <td></td><td class="no-print"></td>
        </tr>
      </tfoot>
    </table>
    </div>
</div>
<?php endif;?>
</div>

<!-- ═══════ VIEW MODAL ═══════ -->
<div class="modal-overlay" id="viewModal" onclick="if(event.target===this)closeModal('viewModal')">
  <div class="modal-box">
    <div class="mo-head">
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="mo-icon blue"><i class="fa-solid fa-eye"></i></div>
        <div>
          <div class="mo-title">Shortage Recovery Detail</div>
          <div class="mo-sub" id="v_sub">—</div>
        </div>
      </div>
      <button class="mo-close" onclick="closeModal('viewModal')">×</button>
    </div>
    <div class="mo-info">
      <div class="mo-cell"><div class="mo-lbl">Sales Rep</div><div class="mo-val" id="v_sr">—</div></div>
      <div class="mo-cell"><div class="mo-lbl">Delivery Date</div><div class="mo-val" id="v_date">—</div></div>
      <div class="mo-cell"><div class="mo-lbl">Total Collected</div><div class="mo-val" id="v_total">—</div></div>
      <div class="mo-cell hi-red" id="v_type_cell"><div class="mo-lbl" id="v_type_lbl">Cash Shortage</div><div class="mo-val" id="v_short">—</div></div>
    </div>
    <div class="mo-body">
      <div class="alloc-section">
        <div class="alloc-head red">
          <span><i class="fa-solid fa-user-minus"></i> Charged to Employee</span>
          <span class="ah-val" id="v_charge_total">0.00</span>
        </div>
        <div class="alloc-body" id="v_empList">
          <div class="no-alloc">No employee charges recorded.</div>
        </div>
      </div>
      <div class="alloc-section">
        <div class="alloc-head blue">
          <span><i class="fa-solid fa-building"></i> Absorbed by Company</span>
          <span class="ah-val" id="v_absorb">0.00</span>
        </div>
        <div class="alloc-body">
          <div class="emp-item">
            <span class="ei-name">Company Absorption</span>
            <span class="ei-amt" id="v_absorb_val" style="color:#1e40af;">—</span>
          </div>
        </div>
      </div>
      <div class="alloc-section">
        <div class="alloc-head amber">
          <span><i class="fa-solid fa-scale-balanced"></i> Variance</span>
        </div>
        <div class="alloc-body">
          <div class="var-row">
            <span class="vr-lbl">Unallocated Variance</span>
            <span class="vr-val" id="v_var">—</span>
          </div>
        </div>
      </div>
    </div>
    <div class="mo-foot">
      <button class="mo-btn mo-btn-cancel" onclick="closeModal('viewModal')">Close</button>
      <button class="mo-btn mo-btn-save" onclick="switchToEdit()"><i class="fa-solid fa-pen"></i> Edit</button>
    </div>
  </div>
</div>

<!-- ═══════ EDIT MODAL ═══════ -->
<div class="modal-overlay" id="editModal" onclick="if(event.target===this)closeModal('editModal')">
  <div class="modal-box wide">
    <div class="mo-head">
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="mo-icon purple"><i class="fa-solid fa-file-invoice-dollar"></i></div>
        <div>
          <div class="mo-title">Edit Pay Allocation</div>
          <div class="mo-sub" id="e_sub">—</div>
        </div>
      </div>
      <button class="mo-close" onclick="closeModal('editModal')">×</button>
    </div>

    <!-- 4-cell info grid identical in feel to cash_collection.php pay modal -->
    <div class="mo-info wide4">
      <div class="mo-cell"><div class="mo-lbl">Sales Rep</div><div class="mo-val" id="e_sr">—</div></div>
      <div class="mo-cell"><div class="mo-lbl">Delivery Date</div><div class="mo-val" id="e_date">—</div></div>
      <div class="mo-cell"><div class="mo-lbl">Total Collected</div><div class="mo-val" id="e_total">—</div></div>
      <!-- Dynamic: red for Short, green for Excess -->
      <div class="mo-cell hi-red" id="e_type_cell">
        <div class="mo-lbl" id="e_type_lbl">Short / Excess</div>
        <div class="mo-val" id="e_type_val">—</div>
        <div id="e_type_pill" style="margin-top:5px;"></div>
      </div>
    </div>

    <div class="mo-body">
      <div class="pmo-section">
        <div class="pmo-sec-head red">
          <span><i class="fa-solid fa-user-minus"></i> Charge to Employee</span>
          <span class="pmo-sec-total" id="e_charge_total">0.00</span>
        </div>
        <div class="pmo-sec-body">
          <div id="e_chargeRows"></div>
          <button type="button" class="add-emp-btn" onclick="addEmpRow()"><i class="fa-solid fa-plus"></i> Add Employee</button>
        </div>
      </div>
      <div class="pmo-section">
        <div class="pmo-sec-head blue">
          <span><i class="fa-solid fa-building"></i> Absorb by Company</span>
          <span class="pmo-sec-total" id="e_absorb_total">0.00</span>
        </div>
        <div class="pmo-sec-body">
          <div class="absorb-row">
            <label class="absorb-lbl"><i class="fa-solid fa-building" style="color:#1e40af;"></i> Company Absorption Amount</label>
            <input type="number" step="0.01" min="0" class="absorb-inp" id="e_absorb_amt" placeholder="0.00" oninput="recalcEdit()">
          </div>
        </div>
      </div>

      <!-- 4-cell summary strip: Short/Excess | Charged | Absorbed | Variance -->
      <div class="pmo-totals">
        <div class="ptb-cell">
          <div class="ptb-lbl" id="e_ptb_se_lbl">Short / Excess</div>
          <div class="ptb-val" id="e_ptb_seval" style="color:#dc2626;">0.00</div>
        </div>
        <div class="ptb-cell">
          <div class="ptb-lbl">Charged to Emp</div>
          <div class="ptb-val" style="color:#7c3aed;" id="e_ptb_charge">0.00</div>
        </div>
        <div class="ptb-cell">
          <div class="ptb-lbl">Absorbed by Co.</div>
          <div class="ptb-val" style="color:#1d4ed8;" id="e_ptb_alloc">0.00</div>
        </div>
        <div class="ptb-cell">
          <div class="ptb-lbl">Variance</div>
          <div class="ptb-val" id="e_ptb_var">0.00</div>
        </div>
      </div>
    </div>

    <div class="mo-foot">
      <button class="mo-btn mo-btn-cancel" onclick="closeModal('editModal')"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="mo-btn mo-btn-save" id="saveEditBtn" onclick="saveEdit()"><i class="fa-solid fa-floppy-disk"></i> Save</button>
    </div>
  </div>
</div>

<!-- ═══════ DELETE CONFIRM MODAL ═══════ -->
<div class="modal-overlay" id="delModal" onclick="if(event.target===this)closeModal('delModal')">
  <div class="modal-box" style="max-width:440px;">
    <div class="mo-head" style="border:none;">
      <div></div>
      <button class="mo-close" onclick="closeModal('delModal')">×</button>
    </div>
    <div class="confirm-body">
      <div class="confirm-icon"><i class="fa-solid fa-trash-can"></i></div>
      <div class="confirm-title">Delete Allocation?</div>
      <div class="confirm-msg" id="del_msg">This will permanently remove the pay allocation for this record.</div>
    </div>
    <div class="mo-foot" style="margin-top:18px;">
      <button class="mo-btn mo-btn-cancel" onclick="closeModal('delModal')">Cancel</button>
      <button class="mo-btn mo-btn-danger" id="confirmDelBtn" onclick="doDelete()"><i class="fa-solid fa-trash"></i> Delete</button>
    </div>
  </div>
</div>

<div id="csrToast"></div>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>

<?php
$rows_js=[];
foreach($rows as $i=>$r){
    $rows_js[]=[
        'idx'        =>$i,
        'sr_code'    =>$r['sr_code'],
        'del_date'   =>$r['del_date'],
        'total_coll' =>floatval($r['total_coll']),
        'banked'     =>floatval($r['banked']),
        'handed'     =>floatval($r['handed']),
        'bank_diff'  =>floatval($r['bank_diff']),   // positive=short, negative=excess
        'cash_short' =>floatval($r['cash_short']),
        'cash_excess'=>floatval($r['cash_excess']),
        'row_type'   =>$r['row_type'],               // 'short'|'excess'|'ok'
        'p_charge'   =>floatval($r['p_charge']),
        'p_absorb'   =>floatval($r['p_absorb']),
        'p_variance' =>$r['p_variance'],
        'employees'  =>$r['employees'],
        'is_paid'    =>$r['is_paid']
    ];
}
?>
<script>if(typeof jQuery==='undefined'){document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"><\/script>');}</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script>
var ROWS = <?php echo json_encode($rows_js); ?>;
var EMP_OPTIONS = `<?php foreach($emp_list as $e):?><option value="<?php echo intval($e['id']);?>"><?php echo htmlspecialchars($e['emp_code'].' — '.$e['emp_name']);?></option><?php endforeach;?>`;
var currentIdx = null, delIdx = null, empCount = 0;

function n(v){return parseFloat(v||0);}
function fmt2(v){return Math.abs(n(v)).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');}
function fmtVar(v){
    if(v===null||v===undefined) return '<span style="color:#9ca3af;">—</span>';
    v=n(v);
    if(Math.abs(v)<0.005) return '<span style="color:#16a34a;font-weight:700;">0.00 ✓</span>';
    if(v<0) return '<span style="color:#d97706;font-weight:700;">'+v.toFixed(2)+'</span>';
    return '<span style="color:#dc2626;font-weight:700;">+'+v.toFixed(2)+'</span>';
}

/* Build type badge HTML (mirrors PHP render_type_badge) */
function typeBadge(row_type, bank_diff){
    const val = fmt2(Math.abs(bank_diff));
    if(row_type==='short')
        return `<span class="type-badge type-short"><i class="fa-solid fa-arrow-down"></i> Short &nbsp;<span class="tb-val">${val}</span></span>`;
    if(row_type==='excess')
        return `<span class="type-badge type-excess"><i class="fa-solid fa-arrow-up"></i> Excess &nbsp;<span class="tb-val">${val}</span></span>`;
    return `<span class="type-badge type-ok"><i class="fa-solid fa-check"></i> OK</span>`;
}

/* Populate the "Short/Excess" info cell in modals */
function populateTypeCell(cellId, lblId, valId, pillId, row_type, bank_diff){
    const cell = document.getElementById(cellId);
    const lbl  = document.getElementById(lblId);
    const val  = document.getElementById(valId);
    // reset classes
    cell.classList.remove('hi-red','hi-green');
    if(row_type==='short'){
        cell.classList.add('hi-red');
        lbl.textContent  = 'Cash Shortage';
        val.textContent  = fmt2(Math.abs(bank_diff));
        val.style.color  = '#dc2626';
        if(pillId) document.getElementById(pillId).innerHTML =
            `<span class="mo-type-pill mtp-short"><i class="fa-solid fa-arrow-down"></i> Short</span>`;
    } else if(row_type==='excess'){
        cell.classList.add('hi-green');
        lbl.textContent  = 'Cash Excess';
        val.textContent  = fmt2(Math.abs(bank_diff));
        val.style.color  = '#166534';
        if(pillId) document.getElementById(pillId).innerHTML =
            `<span class="mo-type-pill mtp-excess"><i class="fa-solid fa-arrow-up"></i> Excess</span>`;
    } else {
        cell.classList.add('hi-green');
        lbl.textContent  = 'Balanced';
        val.textContent  = '0.00 ✓';
        val.style.color  = '#166534';
        if(pillId) document.getElementById(pillId).innerHTML =
            `<span class="mo-type-pill mtp-ok"><i class="fa-solid fa-check"></i> OK</span>`;
    }
}

/* ── OPEN VIEW ── */
function openView(idx){
    currentIdx=idx;
    const r=ROWS[idx];
    document.getElementById('v_sub').textContent   = r.sr_code+' · '+r.del_date;
    document.getElementById('v_sr').textContent    = r.sr_code;
    document.getElementById('v_date').textContent  = r.del_date;
    document.getElementById('v_total').textContent = 'Rs. '+fmt2(r.total_coll);
    populateTypeCell('v_type_cell','v_type_lbl','v_short',null, r.row_type, r.bank_diff);
    document.getElementById('v_charge_total').textContent = fmt2(r.p_charge);
    document.getElementById('v_absorb').textContent       = fmt2(r.p_absorb);
    document.getElementById('v_absorb_val').textContent   = r.p_absorb>0?'Rs. '+fmt2(r.p_absorb):'—';
    document.getElementById('v_absorb_val').style.color   = r.p_absorb>0?'#1e40af':'#9ca3af';
    document.getElementById('v_var').innerHTML = fmtVar(r.p_variance);
    const el=document.getElementById('v_empList');
    if(r.employees&&r.employees.length){
        el.innerHTML=r.employees.map(e=>`
            <div class="emp-item">
                <span class="ei-name">${e.employee_name||'Employee #'+e.employee_id}</span>
                <span class="ei-amt">Rs. ${fmt2(e.amount)}</span>
            </div>`).join('');
    } else {
        el.innerHTML='<div class="no-alloc">No employee charges recorded.</div>';
    }
    openModal('viewModal');
}

function switchToEdit(){closeModal('viewModal');setTimeout(()=>openEdit(currentIdx),50);}

/* ── OPEN EDIT ── */
function openEdit(idx){
    currentIdx=idx; empCount=0;
    document.getElementById('e_chargeRows').innerHTML='';
    document.getElementById('e_absorb_amt').value='';
    const r=ROWS[idx];

    document.getElementById('e_sub').textContent   = r.sr_code+' · '+r.del_date;
    document.getElementById('e_sr').textContent    = r.sr_code;
    document.getElementById('e_date').textContent  = r.del_date;
    document.getElementById('e_total').textContent = 'Rs. '+fmt2(r.total_coll);

    /* populate Short/Excess info cell */
    populateTypeCell('e_type_cell','e_type_lbl','e_type_val','e_type_pill', r.row_type, r.bank_diff);

    /* summary strip */
    const seAbs = Math.abs(r.bank_diff);
    const seLbl = r.row_type==='excess' ? 'Cash Excess' : 'Cash Short';
    const seClr = r.row_type==='excess' ? '#166534'     : '#dc2626';
    document.getElementById('e_ptb_se_lbl').textContent = seLbl;
    document.getElementById('e_ptb_seval').textContent  = fmt2(seAbs);
    document.getElementById('e_ptb_seval').style.color  = seClr;

    openModal('editModal');
    if(r.employees&&r.employees.length) r.employees.forEach(e=>addEmpRow(e.employee_id,e.amount,e.employee_name));
    if(r.p_absorb>0){ document.getElementById('e_absorb_amt').value=r.p_absorb.toFixed(2); }
    recalcEdit();
}

/* ── ADD EMP ROW ── */
function addEmpRow(empId,amt,empName){
    const rid='er_'+(empCount++);
    const div=document.createElement('div');
    div.className='emp-row'; div.id=rid;
    div.innerHTML=`<select class="emp-sel"><option value="">— Select Employee —</option>${EMP_OPTIONS}</select>
        <input type="number" step="0.01" min="0" class="emp-amt" placeholder="0.00" oninput="recalcEdit()">
        <button type="button" class="emp-del" onclick="document.getElementById('${rid}').remove();recalcEdit();">✕</button>`;
    document.getElementById('e_chargeRows').appendChild(div);
    const $sel=$(div).find('.emp-sel').select2({
        placeholder:'— Select Employee —',
        allowClear:true,
        width:'100%',
        dropdownParent:$('#editModal')
    }).on('change',()=>recalcEdit());
    if(empId){
        setTimeout(()=>{ $sel.val(String(empId)).trigger('change'); },0);
    }
    if(amt) div.querySelector('.emp-amt').value=parseFloat(amt).toFixed(2);
    recalcEdit();
}

/* ── RECALC ── */
function recalcEdit(){
    let tc=0;
    document.querySelectorAll('#e_chargeRows .emp-row').forEach(row=>{
        tc+=n(row.querySelector('.emp-amt').value);
    });
    const absorb = n(document.getElementById('e_absorb_amt').value);
    const seAbs  = currentIdx!==null ? Math.abs(n(ROWS[currentIdx].bank_diff)) : 0;
    const vari   = seAbs - (tc + absorb);

    document.getElementById('e_charge_total').textContent = fmt2(tc);
    document.getElementById('e_absorb_total').textContent = fmt2(absorb);
    document.getElementById('e_ptb_charge').textContent   = fmt2(tc);
    document.getElementById('e_ptb_alloc').textContent    = fmt2(absorb);

    const vEl = document.getElementById('e_ptb_var');
    const sign = vari>0?'+':'';
    vEl.textContent = sign+vari.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
    vEl.style.color = Math.abs(vari)<0.005?'#16a34a':vari<0?'#d97706':'#dc2626';
}

/* ── SAVE EDIT ── */
function saveEdit(){
    if(currentIdx===null) return;
    const charges=[]; let valid=true;
    document.querySelectorAll('#e_chargeRows .emp-row').forEach(row=>{
        const sel=row.querySelector('.emp-sel');
        const empId=sel.value;
        const empLbl=sel.options[sel.selectedIndex]?.text||'';
        const amt=n(row.querySelector('.emp-amt').value);
        if(!empId){showToast('Please select an employee.','err');valid=false;return;}
        if(!(amt>0)){showToast('Amount must be > 0.','err');valid=false;return;}
        charges.push({employee_id:parseInt(empId),employee_name:empLbl,amount:amt});
    });
    if(!valid) return;
    const r=ROWS[currentIdx];
    const absorb=n(document.getElementById('e_absorb_amt').value)||0;
    const seAbs=Math.abs(n(r.bank_diff));
    const vari=seAbs-(charges.reduce((s,c)=>s+c.amount,0)+absorb);
    const btn=document.getElementById('saveEditBtn');
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    fetch('save_cash_summary_pay.php',{
        method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({pay_date:r.del_date,sr_code:r.sr_code,charges,absorb_amount:absorb,se_value:seAbs,variance:vari})
    }).then(res=>res.json()).then(res=>{
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save';
        if(res.success){
            const tc=charges.reduce((s,c)=>s+c.amount,0);
            ROWS[currentIdx].p_charge=tc; ROWS[currentIdx].p_absorb=absorb;
            ROWS[currentIdx].p_variance=vari; ROWS[currentIdx].employees=charges; ROWS[currentIdx].is_paid=true;
            const i=currentIdx;
            document.getElementById('ch-'+i).innerHTML=tc>0?`<span class="rec-val">${fmt2(tc)}</span>`:'<span class="dash">—</span>';
            document.getElementById('ab-'+i).innerHTML=absorb>0?`<span style="color:#1e40af;font-weight:700;font-family:var(--mn);">${fmt2(absorb)}</span>`:'<span class="dash">—</span>';
            document.getElementById('st-'+i).innerHTML='<span class="badge badge-paid"><i class="fa-solid fa-check"></i> Recovered</span>';
            closeModal('editModal'); showToast('Allocation saved!','ok');
        } else showToast('Error: '+(res.message||'Unknown'),'err');
    }).catch(err=>{
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save';
        showToast('Network error: '+err.message,'err');
    });
}

/* ── DELETE ── */
function confirmDelete(idx){
    delIdx=idx;
    const r=ROWS[idx];
    document.getElementById('del_msg').textContent=
        'Remove pay allocation for Rep '+r.sr_code+' on '+r.del_date+'? This cannot be undone.';
    openModal('delModal');
}
function doDelete(){
    if(delIdx===null) return;
    const btn=document.getElementById('confirmDelBtn');
    const r=ROWS[delIdx];
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';
    fetch('cash_shortage_recovery.php',{
        method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=delete&pay_date='+encodeURIComponent(r.del_date)+'&sr_code='+encodeURIComponent(r.sr_code)
    }).then(res=>res.json()).then(res=>{
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-trash"></i> Delete';
        if(res.success){
            ROWS[delIdx].p_charge=0; ROWS[delIdx].p_absorb=0;
            ROWS[delIdx].p_variance=null; ROWS[delIdx].employees=[]; ROWS[delIdx].is_paid=false;
            const i=delIdx;
            document.getElementById('ch-'+i).innerHTML='<span class="dash">—</span>';
            document.getElementById('ab-'+i).innerHTML='<span class="dash">—</span>';
            document.getElementById('st-'+i).innerHTML='<span class="badge badge-unpaid"><i class="fa-solid fa-clock"></i> Pending</span>';
            closeModal('delModal'); showToast('Allocation deleted.','ok');
        } else showToast('Error: '+(res.message||'Unknown'),'err');
    }).catch(err=>{
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-trash"></i> Delete';
        showToast('Network error.','err');
    });
}

/* ── EXPORT ── */
function exportToExcel(){
    if(!ROWS||!ROWS.length){showToast('No data.','err');return;}
    const hdrs=['Rep Code','Date','Total Collected','Bank Deposit','HO Office','Type','Cash Short','Excess','Charged to Emp','Absorbed by Co','Variance','Status'];
    const data=ROWS.map(r=>[
        r.sr_code,r.del_date,r.total_coll,r.banked,r.handed,
        r.row_type==='short'?'Short':r.row_type==='excess'?'Excess':'OK',
        r.cash_short,r.cash_excess,
        r.p_charge,r.p_absorb,r.p_variance??'',r.is_paid?'Recovered':'Pending'
    ]);
    const ws=XLSX.utils.aoa_to_sheet([hdrs,...data]);
    const wb=XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb,ws,'Cash Shortage Recovery');
    XLSX.writeFile(wb,'Cash_Shortage_Recovery_<?php echo date("d-M-Y",strtotime($date_from));?>_to_<?php echo date("d-M-Y",strtotime($date_to));?>.xlsx');
    showToast('Excel exported!','ok');
}

/* ── HELPERS ── */
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){
    document.getElementById(id).classList.remove('open');
    if(id==='editModal'||id==='viewModal') currentIdx=null;
    if(id==='delModal') delIdx=null;
}
function showToast(msg,type){
    const t=document.getElementById('csrToast');
    t.className=type==='ok'?'toast-ok':'toast-err';
    t.textContent=msg; t.style.display='block'; t.style.opacity='1';
    clearTimeout(t._t);
    t._t=setTimeout(()=>{t.style.opacity='0';setTimeout(()=>t.style.display='none',300);},2800);
}

document.getElementById('csrForm')?.addEventListener('submit',()=>{
    const b=document.getElementById('csrBtn');
    b.disabled=true; b.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Generating…';
});

/* Select2 init */
$(document).ready(function(){
    $('#sel_sr').select2({placeholder:'— All Reps —',allowClear:true,width:'200px'});
    $('#sel_status').select2({placeholder:'— All —',allowClear:false,width:'150px',minimumResultsForSearch:Infinity});
});

/* Top scroll sync */
(function(){
    const top=document.getElementById('topScroll'),main=document.getElementById('mainScroll');
    if(!top||!main) return;
    const inner=document.getElementById('topScrollInner');
    function setW(){const t=main.querySelector('table.csr');if(t)inner.style.width=t.scrollWidth+'px';}
    setW(); window.addEventListener('resize',setW);
    let s=false;
    top.addEventListener('scroll',()=>{if(s)return;s=true;main.scrollLeft=top.scrollLeft;s=false;});
    main.addEventListener('scroll',()=>{if(s)return;s=true;top.scrollLeft=main.scrollLeft;s=false;});
})();
</script>
<?php include 'footer.php'; ?>