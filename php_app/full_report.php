<?php
error_reporting(0);
ini_set('display_errors', 0);
include 'config.php';
mysqli_report(MYSQLI_REPORT_OFF);

function fmtN($v)  { $v=floatval($v); if($v==0) return '-'; return number_format(abs($v)); }
function fmtNeg($v){ $v=floatval($v); if($v==0) return '-'; return '('.number_format(abs($v)).')'; }
function fmtAuto($v){ $v=floatval($v); if($v==0) return '-'; if($v<0) return '('.number_format(abs($v)).')'; return number_format($v); }

function buildRow($cls, $desc, $indent, $tot, $srs, $vals, $vcls='val') {
    $dc = $indent ? 'desc ind' : 'desc';
    echo "<tr class='$cls'>";
    echo "<td class='$dc'>".htmlspecialchars($desc)."</td>";
    echo "<td class='total'>$tot</td>";
    foreach ($srs as $sr) echo "<td class='$vcls'>".($vals[$sr] ?? '-')."</td>";
    echo "</tr>\n";
}
function buildBlank($n) {
    echo "<tr class='blank'><td colspan='".($n+2)."'></td></tr>\n";
}
function buildSection($label, $n) {
    echo "<tr class='sec'><td class='desc' colspan='".($n+2)."'>".htmlspecialchars($label)."</td></tr>\n";
}
function buildMap($srs, $data, $key, $fn='fmtN') {
    $out = [];
    foreach ($srs as $sr) $out[$sr] = $fn(floatval($data[$sr][$key] ?? 0));
    return $out;
}

$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
$submitted = isset($_GET['search']) && $date_from !== '';
if ($submitted && empty($date_to)) $date_to = $date_from;
$df = $submitted ? mysqli_real_escape_string($conn, $date_from) : '';
$dt = $submitted ? mysqli_real_escape_string($conn, $date_to)   : '';

$all_srs = [];
if ($submitted) {
    $q = mysqli_query($conn, "
        SELECT DISTINCT s AS sr FROM (
            SELECT sid.sales_person_code AS s
            FROM secondary_invoice_import_details sid
            INNER JOIN secondary_invoice_imports si ON si.id = sid.import_id
            WHERE sid.delivery_date BETWEEN '$df' AND '$dt'
              AND sid.status='imported' AND si.status='completed'
              AND sid.sales_person_code IS NOT NULL AND sid.sales_person_code != ''
            UNION
            SELECT sr_code AS s FROM field_summary
            WHERE delivery_date BETWEEN '$df' AND '$dt'
              AND sr_code IS NOT NULL AND sr_code != ''
        ) x ORDER BY sr
    ");
    if ($q) while ($r = mysqli_fetch_assoc($q)) $all_srs[] = $r['sr'];
}

$D = [];
$T = [];
$keys = [
    'loading_value','free_issues_primary','free_issues_secondary','total_loading',
    'good_returns_value','dmg_expiry','unloading_value','gross_secondary',
    'total_discount','final_bill',
    'cash_paid','cheque_paid','credit_value','variance',
    'cash_invoice','cash_rtn_chq','cash_rtn_chgs',
    'bank_deposited','handover_bo','cash_se',
    'cash_charged','cash_absorbed',
    'ikea_unload','actual_unload','good_se','good_se_yms',
    'gs_charged','gs_absorbed',
];
foreach ($all_srs as $sr) { $D[$sr] = array_fill_keys($keys, 0.0); }
foreach ($keys as $k) $T[$k] = 0.0;

if ($submitted && count($all_srs)) {

    /* ── Loading value ── */
    $q = mysqli_query($conn, "
        SELECT lsd.sales_person_code AS sr, COALESCE(SUM(lsd.gross_sales),0) AS lv
        FROM loading_summary_import_details lsd
        INNER JOIN loading_summary_imports ls ON ls.id=lsd.import_id
        WHERE lsd.delivery_date BETWEEN '$df' AND '$dt'
          AND lsd.status='imported' AND ls.status='completed'
          AND lsd.sales_person_code IS NOT NULL AND lsd.sales_person_code != ''
        GROUP BY lsd.sales_person_code
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) { if(isset($D[$r['sr']])) $D[$r['sr']]['loading_value']=floatval($r['lv']); }

    /* ── Secondary invoice: good returns, damage, final bill, discount ── */
    $q = mysqli_query($conn, "
        SELECT sid.sales_person_code AS sr,
            COALESCE(SUM(sid.good_returns_value),0) AS gr,
            COALESCE(SUM(sid.damage_expiry_shortage_value),0) AS dm,
            COALESCE(SUM(sid.final_bill_amount),0) AS fb,
            COALESCE(SUM(sid.total_discount),0) AS td
        FROM secondary_invoice_import_details sid
        INNER JOIN secondary_invoice_imports si ON si.id=sid.import_id
        WHERE sid.delivery_date BETWEEN '$df' AND '$dt'
          AND sid.status='imported' AND si.status='completed' AND sid.sales_person_code IS NOT NULL
        GROUP BY sid.sales_person_code
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $sr=$r['sr']; if(!isset($D[$sr])) continue;
        $D[$sr]['good_returns_value']=floatval($r['gr']); $D[$sr]['dmg_expiry']=floatval($r['dm']);
        $D[$sr]['final_bill']=floatval($r['fb']); $D[$sr]['total_discount']=floatval($r['td']);
    }

    /* ── Free issues — primary ── */
    $q = mysqli_query($conn, "
        SELECT piws.salesperson_code AS sr, COALESCE(SUM(piws.free_qty*piws.tur),0) AS fi
        FROM primary_invoice_wise_sales_data piws
        INNER JOIN primary_invoice_wise_sales_uploads pu ON pu.id=piws.upload_id
        WHERE pu.delivery_date BETWEEN '$df' AND '$dt'
          AND piws.free_qty>0 AND piws.tur>0 AND piws.salesperson_code IS NOT NULL AND piws.salesperson_code != ''
        GROUP BY piws.salesperson_code
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) { if(isset($D[$r['sr']])) $D[$r['sr']]['free_issues_primary']=floatval($r['fi']); }

    /* ── Free issues — secondary ── */
    $q = mysqli_query($conn, "
        SELECT iws.salesperson_code AS sr, COALESCE(SUM(iws.free_qty*iws.tur),0) AS fi
        FROM invoice_wise_sales_data iws
        INNER JOIN invoice_wise_sales_uploads u ON u.id=iws.upload_id
        WHERE u.delivery_date BETWEEN '$df' AND '$dt'
          AND iws.free_qty>0 AND iws.tur>0 AND iws.salesperson_code IS NOT NULL AND iws.salesperson_code != ''
        GROUP BY iws.salesperson_code
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) { if(isset($D[$r['sr']])) $D[$r['sr']]['free_issues_secondary']=floatval($r['fi']); }

    /* ── Invoice payments: cash paid, cheque paid ── */
    $q = mysqli_query($conn, "
        SELECT fs.sr_code AS sr,
            COALESCE(SUM(CASE WHEN ip.payment_method='cash'   AND ip.payment_date=fs.delivery_date AND ip.is_reversed=0 THEN ip.amount ELSE 0 END),0) AS cp,
            COALESCE(SUM(CASE WHEN ip.payment_method='cheque' AND ip.payment_date=fs.delivery_date AND ip.is_reversed=0 THEN ip.amount ELSE 0 END),0) AS chq
        FROM invoice_payments ip INNER JOIN field_summary fs ON fs.id=ip.field_summary_id
        WHERE fs.delivery_date BETWEEN '$df' AND '$dt' GROUP BY fs.sr_code
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $sr=$r['sr']; if(!isset($D[$sr])) continue;
        $D[$sr]['cash_paid']=floatval($r['cp']); $D[$sr]['cheque_paid']=floatval($r['chq']);
    }

    /* ── Credit value ── */
    $pm=[];
    $q = mysqli_query($conn, "
        SELECT ip.field_summary_detail_id, fs.sr_code AS sr, ROUND(COALESCE(SUM(ip.amount),0),2) AS paid
        FROM invoice_payments ip INNER JOIN field_summary fs ON fs.id=ip.field_summary_id
        WHERE fs.delivery_date BETWEEN '$df' AND '$dt' AND ip.payment_date<=fs.delivery_date AND ip.is_reversed=0
        GROUP BY ip.field_summary_detail_id, fs.sr_code
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) $pm[$r['sr']][intval($r['field_summary_detail_id'])]=floatval($r['paid']);

    $q = mysqli_query($conn, "
        SELECT fsd.id, fsd.adjust_net_value, fs.sr_code AS sr
        FROM field_summary_details fsd INNER JOIN field_summary fs ON fs.id=fsd.field_summary_id
        WHERE fs.delivery_date BETWEEN '$df' AND '$dt'
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $sr=$r['sr']; if(!isset($D[$sr])) continue;
        $owed=floatval($r['adjust_net_value'])-($pm[$sr][intval($r['id'])]??0.0);
        if($owed>0) $D[$sr]['credit_value']+=$owed;
    }

    /* ── Cash breakdown by source ── */
    $q = mysqli_query($conn, "
        SELECT fs.sr_code AS sr,
            COALESCE(SUM(CASE WHEN ip.payment_source='invoice' OR ip.payment_source IS NULL THEN ip.amount ELSE 0 END),0) AS ci,
            COALESCE(SUM(CASE WHEN ip.payment_source='return_cheque_settlement' THEN ip.amount ELSE 0 END),0) AS rc,
            COALESCE(SUM(CASE WHEN ip.payment_source='return_charge_settlement' THEN ip.amount ELSE 0 END),0) AS rg
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id=ip.field_summary_detail_id
        INNER JOIN field_summary fs ON fs.id=fsd.field_summary_id
        WHERE ip.payment_method='cash' AND ip.is_reversed=0 AND ip.payment_date BETWEEN '$df' AND '$dt'
        GROUP BY fs.sr_code
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $sr=$r['sr']; if(!isset($D[$sr])) continue;
        $D[$sr]['cash_invoice']=floatval($r['ci']); $D[$sr]['cash_rtn_chq']=floatval($r['rc']); $D[$sr]['cash_rtn_chgs']=floatval($r['rg']);
    }

    /* ── Deposits: bank + BO ── */
    $q = mysqli_query($conn, "
        SELECT r.rep_code AS sr,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=0 THEN r.amount ELSE 0 END),0) AS bk,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=1 THEN r.amount ELSE 0 END),0) AS hd
        FROM cc_cash_deposit_reps r INNER JOIN cc_cash_deposits d ON d.id=r.deposit_id
        WHERE r.delivery_date BETWEEN '$df' AND '$dt' AND r.delivery_date IS NOT NULL AND r.delivery_date!='0000-00-00'
        GROUP BY r.rep_code
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $sr=$r['sr']; if(!isset($D[$sr])) continue;
        $D[$sr]['bank_deposited']=floatval($r['bk']); $D[$sr]['handover_bo']=floatval($r['hd']);
    }

    /* ── Cash short/excess per row (same logic as cash_collection.php) ── */
    $dep_k=[];$col_k=[];$tout=[];$tin=[];
    $q = mysqli_query($conn, "
        SELECT r.rep_code, r.delivery_date,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=0 THEN r.amount ELSE 0 END),0) AS bk,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=1 THEN r.amount ELSE 0 END),0) AS hd
        FROM cc_cash_deposit_reps r INNER JOIN cc_cash_deposits d ON d.id=r.deposit_id
        WHERE r.delivery_date BETWEEN '$df' AND '$dt' AND r.delivery_date IS NOT NULL AND r.delivery_date!='0000-00-00'
        GROUP BY r.rep_code, r.delivery_date
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) $dep_k[$r['delivery_date'].'|'.$r['rep_code']]=$r;

    $q = mysqli_query($conn, "
        SELECT ip.payment_date, fs.sr_code, COALESCE(SUM(ip.amount),0) AS tc
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id=ip.field_summary_detail_id
        INNER JOIN field_summary fs ON fs.id=fsd.field_summary_id
        WHERE ip.payment_method='cash' AND ip.is_reversed=0 AND ip.payment_date BETWEEN '$df' AND '$dt'
        GROUP BY ip.payment_date, fs.sr_code
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) $col_k[$r['payment_date'].'|'.$r['sr_code']]=floatval($r['tc']);

    $q = mysqli_query($conn, "SELECT from_sr_code,to_sr_code,delivery_date,amount FROM cash_shortage_transfers WHERE delivery_date BETWEEN '$df' AND '$dt'");
    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $kf=$r['delivery_date'].'|'.$r['from_sr_code']; $kt=$r['delivery_date'].'|'.$r['to_sr_code'];
        $tout[$kf]=($tout[$kf]??0)+floatval($r['amount']); $tin[$kt]=($tin[$kt]??0)+floatval($r['amount']);
    }
    $master=array_fill_keys(array_merge(array_keys($dep_k),array_keys($col_k),array_keys($tout),array_keys($tin)),true);
    foreach (array_keys($master) as $k) {
        [,$sr]=explode('|',$k,2); if(!isset($D[$sr])) continue;
        $rc=$col_k[$k]??0.0; $dd=$dep_k[$k]??[]; $rb=floatval($dd['bk']??0); $rh=floatval($dd['hd']??0);
        $diff=$rc-($rb+$rh); $to=$tout[$k]??0.0; $ti=$tin[$k]??0.0;
        $D[$sr]['cash_se']+=($diff>=0)?$diff-$to+$ti:$diff+$to-$ti;
    }

    /* ══════════════════════════════════════════════════════════
       CASH CHARGED / ABSORBED
       Source: cash_summary_pay_allocations
       Keyed by pay_date (= delivery_date) + sr_code
       entry_type = 'charge'  → cash_charged
       entry_type = 'absorb'  → cash_absorbed
    ══════════════════════════════════════════════════════════ */
    $q = mysqli_query($conn, "
        SELECT sr_code AS sr, entry_type,
               COALESCE(SUM(amount), 0) AS total_amount
        FROM cash_summary_pay_allocations
        WHERE pay_date BETWEEN '$df' AND '$dt'
          AND sr_code IS NOT NULL AND sr_code != ''
        GROUP BY sr_code, entry_type
    ");
    if ($q) while ($r = mysqli_fetch_assoc($q)) {
        $sr = $r['sr'];
        if (!isset($D[$sr])) continue;
        if ($r['entry_type'] === 'charge') $D[$sr]['cash_charged'] = floatval($r['total_amount']);
        if ($r['entry_type'] === 'absorb') $D[$sr]['cash_absorbed'] = floatval($r['total_amount']);
    }

    /* ── Unloading resolution maps ── */
    $dp_rep_map=[];
    $q=mysqli_query($conn,"
        SELECT DISTINCT LOWER(TRIM(lsd.delivery_person)) AS dp_name, lsd.sales_person_code AS sr
        FROM loading_summary_import_details lsd INNER JOIN loading_summary_imports ls ON ls.id=lsd.import_id
        WHERE ls.status='completed' AND lsd.delivery_person IS NOT NULL AND lsd.delivery_person!=''
          AND lsd.sales_person_code IS NOT NULL AND lsd.sales_person_code!=''
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) $dp_rep_map[$r['dp_name']]=$r['sr'];

    $emp_sr_map=[];
    $q=mysqli_query($conn,"SELECT LOWER(TRIM(employee_id)) AS eid, custom_code AS sr FROM employees WHERE custom_code IS NOT NULL AND custom_code!='' AND employee_id IS NOT NULL AND employee_id!=''");
    if ($q) while ($r=mysqli_fetch_assoc($q)) $emp_sr_map[$r['eid']]=$r['sr'];

    /* ── Unloading data loop — goods fields + gs_charged + gs_absorbed only ── */
    $q=mysqli_query($conn,"
        SELECT ud.delivery_person_name, ud.delivery_person_code,
               ud.short_excess, ud.tur, ud.adj_qty_good_units,
               ud.actual_qty, ud.actual_damage_qty,
               ud.charge_to_employee, ud.absorb_by_company
        FROM unloading_data ud
        INNER JOIN unloading_summary_imports ui ON ui.id=ud.import_id
        WHERE ui.delivery_date BETWEEN '$df' AND '$dt' AND ui.status='completed'
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $dp_name=strtolower(trim($r['delivery_person_name']??''));
        $dp_code=strtolower(trim($r['delivery_person_code']??''));
        $sr=$dp_rep_map[$dp_name]??($emp_sr_map[$dp_code]??null);
        if(!$sr||!isset($D[$sr])) continue;
        $tur=floatval($r['tur']??0); $se=floatval($r['short_excess']??0);
        $adj_good=floatval($r['adj_qty_good_units']??0);
        $act_good=floatval($r['actual_qty']??0); $act_dmg=floatval($r['actual_damage_qty']??0);
        $chg=floatval($r['charge_to_employee']??0); $abs=floatval($r['absorb_by_company']??0);
        $D[$sr]['unloading_value']+=$adj_good*$tur;
        $D[$sr]['actual_unload']+=($act_good+$act_dmg)*$tur;
        if($se!=0&&$tur>0) $D[$sr]['good_se']+=$tur*$se;
        $D[$sr]['gs_charged']+=$chg;
        $D[$sr]['gs_absorbed']+=$abs;
        /* NOTE: cash_absorbed is NO LONGER accumulated here —
           it comes from cash_summary_pay_allocations above */
    }

    foreach ($all_srs as $sr) {
        $D[$sr]['unloading_value']=abs($D[$sr]['unloading_value']);
        $D[$sr]['actual_unload']=abs($D[$sr]['actual_unload']);
    }

    /* ── Computed totals per SR ── */
    foreach ($all_srs as $sr) {
        $D[$sr]['total_loading']  =$D[$sr]['loading_value']+$D[$sr]['free_issues_primary'];
        $D[$sr]['gross_secondary']=$D[$sr]['loading_value']+$D[$sr]['free_issues_primary']-$D[$sr]['good_returns_value']-$D[$sr]['unloading_value'];
        $D[$sr]['variance']       =$D[$sr]['final_bill']-$D[$sr]['cash_paid']-$D[$sr]['cheque_paid']-$D[$sr]['credit_value'];
        $D[$sr]['ikea_unload']    =$D[$sr]['unloading_value']+$D[$sr]['good_returns_value'];
        $D[$sr]['good_se_yms']    =$D[$sr]['ikea_unload']-$D[$sr]['actual_unload'];
        foreach ($keys as $k) $T[$k]+=($D[$sr][$k]??0.0);
    }
    $T['total_loading']  =$T['loading_value']+$T['free_issues_primary'];
    $T['gross_secondary']=$T['loading_value']+$T['free_issues_primary']-$T['good_returns_value']-$T['unloading_value'];
    $T['variance']       =$T['final_bill']-$T['cash_paid']-$T['cheque_paid']-$T['credit_value'];
    $T['ikea_unload']    =$T['unloading_value']+$T['good_returns_value'];
    $T['good_se_yms']    =$T['ikea_unload']-$T['actual_unload'];
}

$dfl='';
if ($submitted) {
    $dfl=($date_from===$date_to)
        ? date('d/m/Y',strtotime($date_from))
        : date('d/m/Y',strtotime($date_from)).' – '.date('d/m/Y',strtotime($date_to));
}

include 'header.php';
?>
<!-- SheetJS for Excel export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=IBM+Plex+Mono:wght@400;600&display=swap');
:root{
    --ink:#0d0d14;--muted:#6b7280;--bdr:#d1d5db;--bg:#f0f1f5;
    --sur:#fff;--acc:#0f3460;--acc2:#16213e;
    --red:#c0392b;--grn:#166534;--ylw:#fbbf24;
    --alt:#f8f8fb;--sub:#eef0f7;--dif:#fff5f5;--csh:#f0fdf4;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'IBM Plex Mono',monospace;background:var(--bg);}
.shell{padding:16px;}
.fc{background:var(--sur);border:1px solid var(--bdr);border-radius:10px;
    padding:14px 18px;margin-bottom:14px;display:flex;gap:12px;
    align-items:flex-end;flex-wrap:wrap;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.fg{display:flex;flex-direction:column;gap:4px;}
.fg label{font-family:'Syne',sans-serif;font-size:10px;font-weight:700;
    text-transform:uppercase;letter-spacing:.8px;color:var(--muted);}
.fg input{padding:8px 10px;border:1.5px solid var(--bdr);border-radius:7px;
    font-size:13px;font-family:'IBM Plex Mono',monospace;outline:none;background:var(--sur);}
.fg input:focus{border-color:var(--acc);}
.btn-go{background:var(--acc);color:#fff;border:none;border-radius:7px;
    padding:9px 22px;font-family:'Syne',sans-serif;font-size:13px;font-weight:700;cursor:pointer;}
.btn-go:hover{background:var(--acc2);}
.btn-pr,.btn-xl{border:none;border-radius:7px;padding:9px 16px;
    font-family:'Syne',sans-serif;font-size:13px;font-weight:600;cursor:pointer;}
.btn-pr{background:var(--bg);color:var(--ink);border:1.5px solid var(--bdr);}
.btn-pr:hover{background:#e2e3e8;}
.btn-xl{background:#1a7340;color:#fff;}
.btn-xl:hover{background:#145a31;}
.rw{background:var(--sur);border:1px solid var(--bdr);border-radius:10px;
    overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.07);}
.ph{padding:12px 18px;border-bottom:2px solid var(--acc);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;}
.ph-left .co{font-family:'Syne',sans-serif;font-size:15px;font-weight:800;color:var(--acc);}
.ph-left .me{font-size:11px;color:var(--muted);margin-top:3px;display:flex;gap:16px;flex-wrap:wrap;}
.ph-left .me strong{color:var(--ink);}
.ph-btns{display:flex;gap:8px;flex-shrink:0;}
.ph-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:none;border-radius:6px;
    font-family:'Syne',sans-serif;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;}
.ph-btn-print{background:#1a1a2e;color:#fff;}
.ph-btn-print:hover{background:#0f0f1e;}
.ph-btn-excel{background:#1a7340;color:#fff;}
.ph-btn-excel:hover{background:#145a31;}
.to{overflow-x:auto;}
table.ft{width:max-content;min-width:100%;border-collapse:collapse;
    font-size:11.5px;font-family:'IBM Plex Mono',monospace;}
table.ft th,table.ft td{border:1px solid #e2e2e8;padding:3px 8px;white-space:nowrap;vertical-align:middle;}
table.ft thead th{background:var(--acc);color:#fff;font-family:'Syne',sans-serif;
    font-size:10.5px;font-weight:700;text-align:center;letter-spacing:.4px;
    position:sticky;top:0;z-index:10;border-color:#1a4a80;}
table.ft thead th.desc{background:var(--acc2);text-align:left;min-width:230px;
    position:sticky;left:0;z-index:11;}
table.ft thead th.total{background:#08203a;min-width:88px;border-left:2px solid #0a2a50;}
table.ft td.desc{font-family:'Syne',sans-serif;font-size:11.5px;font-weight:700;
    color:#1e293b;position:sticky;left:0;background:var(--sur);z-index:5;
    min-width:230px;border-right:2px solid var(--bdr);}
table.ft td.desc.ind{font-weight:400;font-size:11px;padding-left:22px;color:#374151;}
table.ft td.val {text-align:right;color:var(--ink);}
table.ft td.valt{text-align:right;font-weight:700;color:var(--ink);}
table.ft td.neg {text-align:right;color:var(--red);}
table.ft td.total{text-align:right;font-weight:700;background:#f0f2fa;border-left:2px solid #c5cfe8;}
tr.rn  td{background:var(--sur);}
tr.ra  td{background:var(--alt);}
tr.rs  td{background:var(--sub);}
tr.rs  td.desc{background:var(--sub);font-weight:700;}
tr.rs  td.total{background:#e2e6f5;}
tr.rd  td{background:var(--dif);}
tr.rd  td.desc{background:var(--dif);color:var(--red);font-weight:700;}
tr.rd  td.val,tr.rd td.valt,tr.rd td.total{color:var(--red);font-weight:700;}
tr.rc  td{background:var(--csh);}
tr.rc  td.desc{background:var(--csh);color:var(--grn);font-weight:700;}
tr.rc  td.total{background:#dcfce7;}
tr.blank td{height:7px;background:var(--bg);border-left:none;border-right:none;}
tr.sec td{background:#e8ebf7;border-top:2px solid var(--acc);}
tr.sec td.desc{font-family:'Syne',sans-serif;font-weight:800;font-size:11px;
    text-transform:uppercase;letter-spacing:.6px;color:var(--acc);}
.es{text-align:center;padding:50px;color:var(--muted);}
.es span{font-size:36px;display:block;margin-bottom:10px;}
.es p{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;}

@media print {
    @page { size: A4 landscape; margin: 10mm 8mm 10mm 8mm; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .fc, .ph-btns, .btn-pr, .btn-xl, header, nav, .sidebar, footer { display: none !important; }
    body { background: #fff !important; font-family: Arial, sans-serif; }
    .shell { padding: 0 !important; }
    .print-header { display: block !important; text-align: center; border-bottom: 2px solid #000;
        padding-bottom: 4mm; margin-bottom: 4mm; }
    .print-header h1 { font-size: 14pt; font-weight: 900; font-family: Arial, sans-serif;
        letter-spacing: 1px; text-transform: uppercase; margin-bottom: 2px; }
    .print-header p { font-size: 9pt; color: #333; }
    .rw { box-shadow: none !important; border: none !important; border-radius: 0 !important; }
    .ph { border-bottom: 1px solid #000 !important; padding: 0 0 3mm 0 !important;
          background: #fff !important; }
    .ph-left .co { color: #000 !important; font-size: 11pt !important; }
    .ph-left .me { color: #333 !important; }
    .ph-left .me strong { color: #000 !important; }
    .to { overflow: visible !important; }
    table.ft { width: 100% !important; min-width: unset !important;
        border-collapse: collapse !important; font-size: 7pt !important;
        font-family: Arial, sans-serif !important; table-layout: fixed !important;
        page-break-inside: auto; }
    table.ft th, table.ft td { border: 0.5px solid #000 !important; padding: 1.5px 3px !important;
        white-space: normal !important; word-break: break-word !important; overflow: hidden !important;
        color: #000 !important; background: #fff !important; position: static !important; }
    table.ft td.desc, table.ft th.desc { width: 34mm !important; min-width: unset !important;
        font-size: 6.5pt !important; background: #fff !important; color: #000 !important;
        border-right: 1px solid #000 !important; }
    table.ft td.desc.ind { padding-left: 5px !important; font-weight: normal !important; }
    table.ft td.total, table.ft th.total { width: 18mm !important; min-width: unset !important;
        font-weight: bold !important; background: #e8e8e8 !important; border-left: 1px solid #000 !important; }
    table.ft th:not(.desc):not(.total), table.ft td:not(.desc):not(.total) { text-align: right !important; }
    table.ft thead th { background: #1a1a1a !important; color: #fff !important; font-size: 6.5pt !important;
        font-weight: bold !important; position: static !important; border: 0.5px solid #000 !important;
        text-align: center !important; }
    tr.sec td { background: #333 !important; color: #fff !important; font-size: 6.5pt !important; font-weight: bold !important; }
    tr.rs td { background: #ccc !important; color: #000 !important; font-weight: bold !important; }
    tr.rs td.desc { background: #ccc !important; }
    tr.rs td.total { background: #bbb !important; }
    tr.rd td { background: #f0f0f0 !important; color: #000 !important; font-weight: bold !important; }
    tr.rd td.desc { background: #f0f0f0 !important; color: #000 !important; }
    tr.rc td { background: #e0e0e0 !important; color: #000 !important; font-weight: bold !important; }
    tr.rc td.desc { background: #e0e0e0 !important; color: #000 !important; }
    tr.rc td.total { background: #d0d0d0 !important; }
    tr.rn td { background: #fff !important; }
    tr.ra td { background: #f7f7f7 !important; }
    tr.blank td { height: 2px !important; background: #ddd !important; border: none !important; }
    table.ft td.neg { color: #000 !important; font-weight: bold !important; }
    table.ft td.valt { font-weight: bold !important; }
    tr { page-break-inside: avoid; }
    thead { display: table-header-group; }
}
.print-header { display: none; }
</style>

<div class="shell">

<div class="fc">
    <form method="GET" style="display:contents;">
        <div class="fg">
            <label>Date From</label>
            <input type="date" name="date_from" id="fr_df" value="<?= htmlspecialchars($date_from) ?>" required>
        </div>
        <div class="fg">
            <label>Date To</label>
            <input type="date" name="date_to" id="fr_dt" value="<?= htmlspecialchars($date_to) ?>">
        </div>
        <button type="submit" name="search" class="btn-go">Generate Report</button>
    </form>
</div>

<?php if ($submitted && empty($all_srs)): ?>
<div class="es"><span>🔍</span><p>No data found for <?= htmlspecialchars($dfl) ?></p></div>

<?php elseif ($submitted && count($all_srs)): ?>
<?php $N = count($all_srs); ?>

<div class="print-header">
    <h1>Yelo Logistics — Full Report</h1>
    <p>Date: <?= htmlspecialchars($dfl) ?> &nbsp;|&nbsp; Printed: <?= date('d/m/Y H:i') ?></p>
</div>

<div class="rw">
<div class="ph">
    <div class="ph-left">
        <div class="co">Yelo Logistics</div>
        <div class="me">
            <span><strong>Full Report</strong></span>
            <span>Date – <strong><?= htmlspecialchars($dfl) ?></strong></span>
            <span>Print Time – <strong><?= date('H:i') ?></strong></span>
        </div>
    </div>
    <div class="ph-btns">
        <button class="ph-btn ph-btn-excel" onclick="exportExcel()">&#9651; Export Excel</button>
        <button class="ph-btn ph-btn-print" onclick="window.print()">&#9113; Print B&amp;W</button>
    </div>
</div>

<div class="to">
<table class="ft" id="reportTable">
<thead>
<tr>
    <th class="desc">Description</th>
    <th class="total">Total</th>
    <?php foreach ($all_srs as $sr): ?><th><?= htmlspecialchars($sr) ?></th><?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php

buildSection('Loading', $N);
buildRow('rn','Loading Value',       false, fmtN($T['loading_value']),       $all_srs, buildMap($all_srs,$D,'loading_value'));
buildRow('ra','Free Issues Value',   false, fmtN($T['free_issues_primary']), $all_srs, buildMap($all_srs,$D,'free_issues_primary'));
buildRow('rs','Total Loading Value', false, fmtN($T['total_loading']),       $all_srs, buildMap($all_srs,$D,'total_loading'), 'valt');
buildBlank($N);

buildRow('rn','Good Returns Value',                 false, fmtN($T['good_returns_value']),  $all_srs, buildMap($all_srs,$D,'good_returns_value'));
buildRow('ra','Unloading Value',                    false, fmtNeg($T['unloading_value']),   $all_srs, buildMap($all_srs,$D,'unloading_value','fmtNeg'), 'neg');
buildRow('rs','Gross Sale as per Secondary Report', false, fmtN($T['gross_secondary']),     $all_srs, buildMap($all_srs,$D,'gross_secondary'), 'valt');
buildRow('rd','Diff',                               false, '-', $all_srs, array_fill_keys($all_srs,'-'));
buildBlank($N);

buildRow('rn','Free Issues Value',            true,  fmtNeg($T['free_issues_secondary']), $all_srs, buildMap($all_srs,$D,'free_issues_secondary','fmtNeg'), 'neg');
buildRow('ra','Total Discount',               true,  fmtNeg($T['total_discount']),        $all_srs, buildMap($all_srs,$D,'total_discount','fmtNeg'),        'neg');
buildRow('rn','Good Returns Value',           true,  fmtNeg($T['good_returns_value']),    $all_srs, buildMap($all_srs,$D,'good_returns_value','fmtNeg'),     'neg');
buildRow('ra','Damage-Expiry Shortage Value', true,  fmtNeg($T['dmg_expiry']),            $all_srs, buildMap($all_srs,$D,'dmg_expiry','fmtNeg'),            'neg');
buildRow('rs','Final Bill Amount to be collected', false, fmtN($T['final_bill']),         $all_srs, buildMap($all_srs,$D,'final_bill'),                     'valt');
buildBlank($N);

buildRow('rn','Cash Paid',    false, fmtNeg($T['cash_paid']),    $all_srs, buildMap($all_srs,$D,'cash_paid','fmtNeg'),    'neg');
buildRow('ra','Cheque Paid',  false, fmtNeg($T['cheque_paid']),  $all_srs, buildMap($all_srs,$D,'cheque_paid','fmtNeg'), 'neg');
buildRow('rn','Credit Value', false, fmtNeg($T['credit_value']), $all_srs, buildMap($all_srs,$D,'credit_value','fmtNeg'),'neg');
buildRow('rs','Variance with Secondary Sale', false, fmtAuto($T['variance']), $all_srs, buildMap($all_srs,$D,'variance','fmtAuto'), 'valt');
buildBlank($N);

buildRow('rn','Cash paid for Cheques',   false, fmtN($T['cash_invoice']),  $all_srs, buildMap($all_srs,$D,'cash_invoice'));
buildRow('ra','Cash paid RTN Cheques',   false, fmtN($T['cash_rtn_chq']),  $all_srs, buildMap($all_srs,$D,'cash_rtn_chq'));
buildRow('rn','Cash paid RTN Chq Chgs', false, fmtN($T['cash_rtn_chgs']), $all_srs, buildMap($all_srs,$D,'cash_rtn_chgs'));
buildBlank($N);
buildRow('ra','Bank Deposited', false, fmtNeg($T['bank_deposited']), $all_srs, buildMap($all_srs,$D,'bank_deposited','fmtNeg'), 'neg');
buildRow('rn','Handover to BO', false, fmtNeg($T['handover_bo']),    $all_srs, buildMap($all_srs,$D,'handover_bo','fmtNeg'),    'neg');

$cse_vals=[]; foreach ($all_srs as $sr) $cse_vals[$sr]=fmtAuto($D[$sr]['cash_se']);
buildRow('rc','Cash Shortage / Excess', false, fmtAuto($T['cash_se']), $all_srs, $cse_vals, 'valt');
buildRow('rn','Charged to Employee', true, fmtNeg($T['cash_charged']),  $all_srs, buildMap($all_srs,$D,'cash_charged','fmtNeg'),  'neg');
buildRow('ra','Absorbed by Company', true, fmtNeg($T['cash_absorbed']), $all_srs, buildMap($all_srs,$D,'cash_absorbed','fmtNeg'), 'neg');

$cdiff_t=$T['cash_se']-$T['cash_charged']-$T['cash_absorbed'];
$cdiff_v=[]; foreach ($all_srs as $sr) $cdiff_v[$sr]=fmtAuto($D[$sr]['cash_se']-$D[$sr]['cash_charged']-$D[$sr]['cash_absorbed']);
buildRow('rd','Diff', false, fmtAuto($cdiff_t), $all_srs, $cdiff_v);
buildBlank($N);

buildSection('Unloading / Good Shortage', $N);
buildRow('rn','IKEA Unloading Value',   false, fmtN($T['ikea_unload']),   $all_srs, buildMap($all_srs,$D,'ikea_unload'));
buildRow('ra','Actual Unloading Value', false, fmtN($T['actual_unload']), $all_srs, buildMap($all_srs,$D,'actual_unload'));

$yms_vals=[]; foreach ($all_srs as $sr) $yms_vals[$sr]=fmtNeg($D[$sr]['good_se_yms']);
buildRow('rn','Good Short / Excess', false, fmtNeg($T['good_se_yms']), $all_srs, $yms_vals, 'neg');

$gse_vals=[]; foreach ($all_srs as $sr) $gse_vals[$sr]=fmtAuto($D[$sr]['good_se']);
buildRow('rs','Good Short / Excess as YMS ', false, fmtAuto($T['good_se']), $all_srs, $gse_vals, 'valt');
buildRow('ra','Charged to Employee', true, fmtNeg($T['gs_charged']),  $all_srs, buildMap($all_srs,$D,'gs_charged','fmtNeg'),  'neg');
buildRow('rn','Absorbed by Company', true, fmtNeg($T['gs_absorbed']), $all_srs, buildMap($all_srs,$D,'gs_absorbed','fmtNeg'), 'neg');

$gdiff_t=-$T['good_se_yms']-$T['gs_charged']-$T['gs_absorbed'];
$gdiff_v=[]; foreach ($all_srs as $sr) $gdiff_v[$sr]=fmtAuto(-$D[$sr]['good_se_yms']-$D[$sr]['gs_charged']-$D[$sr]['gs_absorbed']);
buildRow('rd','Diff', false, fmtAuto($gdiff_t), $all_srs, $gdiff_v);

?>
</tbody>
</table>
</div>
</div>
<?php endif; ?>
</div>

<script>
(function(){
    var a=document.getElementById('fr_df'),b=document.getElementById('fr_dt');
    if(a&&b) a.addEventListener('change',function(){ if(!b.value) b.value=a.value; });
})();

function exportExcel() {
    var tbl = document.getElementById('reportTable');
    if (!tbl) return;
    var wb = XLSX.utils.book_new();
    var wsData = [];
    wsData.push(['Yelo Logistics — Full Report']);
    wsData.push(['Date: <?= addslashes($dfl) ?>', '', 'Generated: <?= date('d/m/Y H:i') ?>']);
    wsData.push([]);
    var rows = tbl.querySelectorAll('tr');
    rows.forEach(function(tr) {
        if (tr.classList.contains('blank')) { wsData.push([]); return; }
        var rowData = [];
        tr.querySelectorAll('th,td').forEach(function(cell) {
            var txt = cell.textContent.trim();
            if (/^\([\d,]+\)$/.test(txt)) {
                rowData.push(-parseFloat(txt.replace(/[(),]/g,'')));
            } else if (/^[\d,]+$/.test(txt) && txt !== '-') {
                rowData.push(parseFloat(txt.replace(/,/g,'')));
            } else {
                rowData.push(txt === '-' ? '' : txt);
            }
        });
        wsData.push(rowData);
    });
    var ws = XLSX.utils.aoa_to_sheet(wsData);
    var numCols = wsData[3] ? wsData[3].length : 10;
    var wscols = [{wch:36},{wch:16}];
    for (var i=2;i<numCols;i++) wscols.push({wch:14});
    ws['!cols'] = wscols;
    ws['!freeze'] = {xSplit:1, ySplit:4, topLeftCell:'B5'};
    XLSX.utils.book_append_sheet(wb, ws, 'Full Report');
    var dateStr = '<?= $date_from ?>' === '<?= $date_to ?>'
        ? '<?= $date_from ?>'
        : '<?= $date_from ?>_to_<?= $date_to ?>';
    XLSX.writeFile(wb, 'Yelo_FullReport_' + dateStr + '.xlsx');
}
</script>

<?php include 'footer.php'; ?>