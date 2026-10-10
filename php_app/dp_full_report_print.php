<?php
error_reporting(0);
ini_set('display_errors', 0);
include 'config.php';
mysqli_report(MYSQLI_REPORT_OFF);

function fmtN($v)  { $v=floatval($v); if($v==0) return '-'; return number_format(abs($v)); }
function fmtNeg($v){ $v=floatval($v); if($v==0) return '-'; return '('.number_format(abs($v)).')'; }
function fmtAuto($v){ $v=floatval($v); if($v==0) return '-'; if($v<0) return '('.number_format(abs($v)).')'; return number_format($v); }
function fmtPct($v,$tot){ $tot=floatval($tot); if($tot==0) return '-'; $p=floatval($v)/$tot*100; if($p==0) return '-'; return number_format($p,1).'%'; }

/* ── Short / Excess identification ──
   POSITIVE value  = SHORT   (cash collected but NOT yet deposited/settled)
   NEGATIVE value  = EXCESS  (deposited/settled MORE than was collected)   */
function fmtSE($v){
    $v = floatval($v);
    if (abs($v) < 0.5) return '<span class="se-ok">0</span>';
    if ($v > 0)        return '<span class="se-sh">'.number_format($v).' Sh</span>';
    return '<span class="se-ex">('.number_format(abs($v)).') Ex</span>';
}

/* Good/unloading side uses the opposite sign convention to the cash side. */
function fmtSEg($v){
    $v = floatval($v);
    if (abs($v) < 0.5) return '<span class="se-ok">0</span>';
    if ($v < 0)        return '<span class="se-sh">'.number_format(abs($v)).' Sh</span>';
    return '<span class="se-ex">('.number_format($v).') Ex</span>';
}

function dpNorm($s){ $s = preg_replace('/\s+/',' ', (string)($s ?? '')); return strtolower(trim($s)); }

function cleanDpName($dp) {
    $raw = trim((string)$dp);
    if ($raw === '') return $raw;
    preg_match_all('/[A-Za-z.]+(?:\s+[A-Za-z.]+)*/', $raw, $m);
    if (empty($m[0])) return $raw;
    $best = '';
    foreach ($m[0] as $chunk) {
        $chunk = trim($chunk, " .");
        if (strlen($chunk) > strlen($best)) $best = $chunk;
    }
    return $best !== '' ? $best : $raw;
}

function dpHeader($dp) {
    $dp = cleanDpName($dp);
    $words = preg_split('/\s+/', $dp);
    if (count($words) >= 2) {
        $mid   = (int)ceil(count($words)/2);
        $line1 = implode(' ', array_slice($words, 0, $mid));
        $line2 = implode(' ', array_slice($words, $mid));
        return htmlspecialchars($line1).'<br>'.htmlspecialchars($line2);
    }
    return htmlspecialchars($dp);
}

function buildRow($cls, $desc, $indent, $tot, $dps, $vals, $vcls='val') {
    $dc = $indent ? 'desc ind' : 'desc';
    echo "<tr class='$cls'>";
    echo "<td class='$dc'>".htmlspecialchars($desc)."</td>";
    echo "<td class='total'>$tot</td>";
    foreach ($dps as $dp) echo "<td class='$vcls'>".($vals[$dp] ?? '-')."</td>";
    echo "</tr>\n";
}
function buildBlank($n) {
    echo "<tr class='blank'><td colspan='".($n+2)."'></td></tr>\n";
}
function buildSection($label, $n) {
    echo "<tr class='sec'><td class='desc' colspan='".($n+2)."'>".htmlspecialchars($label)."</td></tr>\n";
}
function buildMap($dps, $data, $key, $fn='fmtN') {
    $out = [];
    foreach ($dps as $dp) $out[$dp] = $fn(floatval($data[$dp][$key] ?? 0));
    return $out;
}

$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
$submitted = $date_from !== '';
if ($submitted && empty($date_to)) $date_to = $date_from;
$df = $submitted ? mysqli_real_escape_string($conn, $date_from) : '';
$dt = $submitted ? mysqli_real_escape_string($conn, $date_to)   : '';

/* ── Distinct delivery persons for the date range ── */
$all_dps = [];
if ($submitted) {
    $q = mysqli_query($conn, "
        SELECT DISTINCT dp FROM (
            SELECT CONVERT(TRIM(delivery_person) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS dp
            FROM secondary_invoice_import_details
            WHERE delivery_date BETWEEN '$df' AND '$dt'
              AND status='imported'
              AND delivery_person IS NOT NULL AND delivery_person != ''
            UNION
            SELECT CONVERT(TRIM(p.delivery_person) USING utf8mb4) COLLATE utf8mb4_unicode_ci
            FROM cc_cash_deposit_dp_persons p
            INNER JOIN cc_cash_deposit_dp d ON d.id = p.deposit_id
            WHERE p.delivery_date BETWEEN '$df' AND '$dt'
              AND p.delivery_date IS NOT NULL AND p.delivery_date != '0000-00-00'
              AND p.delivery_person IS NOT NULL AND p.delivery_person != ''
            UNION
            SELECT CONVERT(TRIM(lsd.delivery_person) USING utf8mb4) COLLATE utf8mb4_unicode_ci
            FROM loading_summary_import_details lsd
            INNER JOIN loading_summary_imports ls ON ls.id=lsd.import_id
            WHERE lsd.delivery_date BETWEEN '$df' AND '$dt'
              AND lsd.status='imported' AND ls.status='completed'
              AND lsd.delivery_person IS NOT NULL AND lsd.delivery_person != ''
        ) x ORDER BY dp
    ");
    if ($q) while ($r = mysqli_fetch_assoc($q)) $all_dps[] = $r['dp'];
}

$D = [];
$T = [];
$keys = [
    'loading_value','free_issues_primary','free_issues_secondary','total_loading',
    'good_returns_value','dmg_expiry','unloading_value','gross_secondary',
    'total_discount','final_bill',
    'cash_paid','cheque_paid','credit_value','variance',
    'cash_invoice','cash_rtn_chq','cash_rtn_chgs',
    'cc_daily_sale','cc_sent_back',
    'bank_deposited','handover_bo','cash_se',
    'cash_charged','cash_absorbed',
    'ikea_unload','actual_unload','good_se','good_se_yms','good_se_abs',
    'adj_good_val','adj_dmg_val','act_good_val','act_dmg_val',
    'gs_charged','gs_absorbed',
];
$dp_lookup = [];
foreach ($all_dps as $dp) $dp_lookup[dpNorm($dp)] = $dp;

foreach ($all_dps as $dp) { $D[$dp] = array_fill_keys($keys, 0.0); }
foreach ($keys as $k) $T[$k] = 0.0;

if ($submitted && count($all_dps)) {

    $q = mysqli_query($conn, "
        SELECT lsd.delivery_person AS dp, COALESCE(SUM(lsd.gross_sales),0) AS lv
        FROM loading_summary_import_details lsd
        INNER JOIN loading_summary_imports ls ON ls.id=lsd.import_id
        WHERE lsd.delivery_date BETWEEN '$df' AND '$dt'
          AND lsd.status='imported' AND ls.status='completed'
          AND lsd.delivery_person IS NOT NULL AND lsd.delivery_person != ''
        GROUP BY lsd.delivery_person
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $dp = $dp_lookup[dpNorm($r['dp'])] ?? null;
        if($dp !== null) $D[$dp]['loading_value']=floatval($r['lv']);
    }

    $q = mysqli_query($conn, "
        SELECT delivery_person AS dp,
            COALESCE(SUM(good_returns_value),0) AS gr,
            COALESCE(SUM(damage_expiry_shortage_value),0) AS dm,
            COALESCE(SUM(final_bill_amount),0) AS fb,
            COALESCE(SUM(total_discount),0) AS td
        FROM secondary_invoice_import_details
        WHERE delivery_date BETWEEN '$df' AND '$dt'
          AND status='imported'
          AND delivery_person IS NOT NULL AND delivery_person != ''
        GROUP BY delivery_person
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $dp=$dp_lookup[dpNorm($r['dp'])]??null; if($dp===null) continue;
        $D[$dp]['good_returns_value']=floatval($r['gr']); $D[$dp]['dmg_expiry']=floatval($r['dm']);
        $D[$dp]['final_bill']=floatval($r['fb']); $D[$dp]['total_discount']=floatval($r['td']);
    }

    $q = mysqli_query($conn, "
        SELECT lsd.delivery_person AS dp, COALESCE(SUM(piws.free_qty*piws.tur),0) AS fi
        FROM primary_invoice_wise_sales_data piws
        INNER JOIN primary_invoice_wise_sales_uploads pu ON pu.id=piws.upload_id
        INNER JOIN loading_summary_import_details lsd
               ON lsd.sales_person_code=piws.salesperson_code
              AND lsd.delivery_date=pu.delivery_date
        INNER JOIN loading_summary_imports ls ON ls.id=lsd.import_id
        WHERE pu.delivery_date BETWEEN '$df' AND '$dt'
          AND piws.free_qty>0 AND piws.tur>0
          AND lsd.delivery_person IS NOT NULL AND lsd.delivery_person != ''
          AND ls.status='completed' AND lsd.status='imported'
        GROUP BY lsd.delivery_person
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $dp=$dp_lookup[dpNorm($r['dp'])]??null;
        if($dp!==null) $D[$dp]['free_issues_primary']=floatval($r['fi']);
    }

    $q = mysqli_query($conn, "
        SELECT siid.delivery_person AS dp, COALESCE(SUM(iws.free_qty*iws.tur),0) AS fi
        FROM invoice_wise_sales_data iws
        INNER JOIN invoice_wise_sales_uploads u ON u.id=iws.upload_id
        INNER JOIN secondary_invoice_import_details siid
               ON siid.bill_no=iws.invoice_no
              AND siid.status='imported'
              AND siid.delivery_person IS NOT NULL AND siid.delivery_person != ''
        WHERE u.delivery_date BETWEEN '$df' AND '$dt'
          AND iws.free_qty>0 AND iws.tur>0
        GROUP BY siid.delivery_person
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $dp=$dp_lookup[dpNorm($r['dp'])]??null;
        if($dp!==null) $D[$dp]['free_issues_secondary']=floatval($r['fi']);
    }

    $q = mysqli_query($conn, "
        SELECT siid.delivery_person AS dp,
            COALESCE(SUM(CASE WHEN ip.payment_method='cash'   AND ip.is_reversed=0 THEN ip.amount ELSE 0 END),0) AS cp,
            COALESCE(SUM(CASE WHEN ip.payment_method='cheque' AND ip.is_reversed=0 THEN ip.amount ELSE 0 END),0) AS chq
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id=ip.field_summary_detail_id
        INNER JOIN secondary_invoice_import_details siid
               ON siid.bill_no=fsd.invoice_num AND siid.status='imported'
              AND siid.delivery_person IS NOT NULL AND siid.delivery_person != ''
        WHERE siid.delivery_date BETWEEN '$df' AND '$dt'
        GROUP BY siid.delivery_person
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $dp=$dp_lookup[dpNorm($r['dp'])]??null; if($dp===null) continue;
        $D[$dp]['cash_paid']=floatval($r['cp']); $D[$dp]['cheque_paid']=floatval($r['chq']);
    }

    $paid_by_det=[];
    $q = mysqli_query($conn, "
        SELECT field_summary_detail_id,
               ROUND(COALESCE(SUM(CASE WHEN is_reversed=0 THEN amount ELSE 0 END),0),2) AS paid
        FROM invoice_payments
        GROUP BY field_summary_detail_id
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) $paid_by_det[intval($r['field_summary_detail_id'])]=floatval($r['paid']);

    $q = mysqli_query($conn, "
        SELECT siid.delivery_person AS dp, fsd.id AS det_id, fsd.adjust_net_value AS row_adj
        FROM field_summary_details fsd
        INNER JOIN secondary_invoice_import_details siid
               ON siid.bill_no=fsd.invoice_num AND siid.status='imported'
              AND siid.delivery_person IS NOT NULL AND siid.delivery_person != ''
        WHERE siid.delivery_date BETWEEN '$df' AND '$dt'
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $dp=$dp_lookup[dpNorm($r['dp'])]??null; if($dp===null) continue;
        $owed=max(0.0, floatval($r['row_adj'])-($paid_by_det[intval($r['det_id'])]??0.0));
        $D[$dp]['credit_value']+=$owed;
    }

    $rc_tbl_exists=false; $rc_has_dp=false;
    $_t=mysqli_query($conn,"SHOW TABLES LIKE 'rc_settlement_payments'");
    if($_t && mysqli_num_rows($_t)>0){
        $rc_tbl_exists=true;
        $_c=mysqli_query($conn,"SHOW COLUMNS FROM rc_settlement_payments LIKE 'delivery_person'");
        if($_c && mysqli_num_rows($_c)>0) $rc_has_dp=true;
    }

    $q = mysqli_query($conn, "
        SELECT siid.delivery_person AS dp, COALESCE(SUM(ip.amount),0) AS v
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id=ip.field_summary_detail_id
        INNER JOIN secondary_invoice_import_details siid
               ON siid.bill_no=fsd.invoice_num AND siid.status='imported'
              AND siid.delivery_person IS NOT NULL AND siid.delivery_person != ''
        WHERE ip.payment_method='cash' AND ip.is_reversed=0
          AND LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
          AND (ip.payment_source IS NULL OR ip.payment_source='' OR LOWER(ip.payment_source)='invoice')
          AND siid.delivery_date BETWEEN '$df' AND '$dt'
        GROUP BY siid.delivery_person
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $dp=$dp_lookup[dpNorm($r['dp'])]??null; if($dp===null) continue;
        $D[$dp]['cc_daily_sale']=floatval($r['v']);
    }

    $q = mysqli_query($conn, "
        SELECT ip.delivery_person AS dp, COALESCE(SUM(ip.amount),0) AS ci
        FROM invoice_payments ip
        WHERE ip.payment_method='cash' AND ip.is_reversed=0
          AND LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
          AND LOWER(ip.payment_source) LIKE '%credit%'
          AND ip.payment_date BETWEEN '$df' AND '$dt'
          AND ip.delivery_person IS NOT NULL AND ip.delivery_person != ''
        GROUP BY ip.delivery_person
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $dp=$dp_lookup[dpNorm($r['dp'])]??null; if($dp===null) continue;
        $D[$dp]['cash_invoice']=floatval($r['ci']);
    }

    $q = mysqli_query($conn, "
        SELECT ip.delivery_person AS dp,
            COALESCE(SUM(CASE WHEN ip.payment_source='return_cheque_settlement'   THEN ip.amount ELSE 0 END),0) AS rtnchq,
            COALESCE(SUM(CASE WHEN ip.payment_source='sentback_cheque_settlement' THEN ip.amount ELSE 0 END),0) AS sback
        FROM invoice_payments ip
        WHERE ip.payment_method='cash' AND ip.is_reversed=0
          AND LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
          AND ip.payment_date BETWEEN '$df' AND '$dt'
          AND ip.delivery_person IS NOT NULL AND ip.delivery_person != ''
        GROUP BY ip.delivery_person
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $dp=$dp_lookup[dpNorm($r['dp'])]??null; if($dp===null) continue;
        $D[$dp]['cash_rtn_chq']=floatval($r['rtnchq']);
        $D[$dp]['cc_sent_back']=floatval($r['sback']);
    }

    if ($rc_tbl_exists && $rc_has_dp) {
        $q = mysqli_query($conn, "
            SELECT delivery_person AS dp, COALESCE(SUM(amount),0) AS v
            FROM rc_settlement_payments
            WHERE payment_method='cash'
              AND payment_date BETWEEN '$df' AND '$dt'
              AND delivery_person IS NOT NULL AND delivery_person != ''
            GROUP BY delivery_person
        ");
        if ($q) while ($r=mysqli_fetch_assoc($q)) {
            $dp=$dp_lookup[dpNorm($r['dp'])]??null; if($dp===null) continue;
            $D[$dp]['cash_rtn_chgs']=floatval($r['v']);
        }
    }

    $q = mysqli_query($conn, "
        SELECT p.delivery_person AS dp,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=0 THEN p.amount ELSE 0 END),0) AS bk,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=1 THEN p.amount ELSE 0 END),0) AS hd
        FROM cc_cash_deposit_dp_persons p
        INNER JOIN cc_cash_deposit_dp d ON d.id=p.deposit_id
        WHERE p.delivery_date BETWEEN '$df' AND '$dt'
          AND p.delivery_date IS NOT NULL AND p.delivery_date!='0000-00-00'
          AND p.delivery_person IS NOT NULL AND p.delivery_person != ''
          AND LOWER(TRIM(COALESCE(d.collected_by,'')))='cc'
        GROUP BY p.delivery_person
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $dp=$dp_lookup[dpNorm($r['dp'])]??null; if($dp===null) continue;
        $D[$dp]['bank_deposited']=floatval($r['bk']); $D[$dp]['handover_bo']=floatval($r['hd']);
    }

    foreach ($all_dps as $dp) {
        $cc_total = $D[$dp]['cc_daily_sale']
                  + $D[$dp]['cash_invoice']
                  + $D[$dp]['cash_rtn_chq']
                  + $D[$dp]['cash_rtn_chgs']
                  + $D[$dp]['cc_sent_back'];
        $D[$dp]['cash_se'] = $cc_total - $D[$dp]['bank_deposited'] - $D[$dp]['handover_bo'];
    }

    $q = mysqli_query($conn, "
        SELECT delivery_person AS dp, entry_type,
               COALESCE(SUM(amount),0) AS total_amount
        FROM cash_summary_pay_allocations_dp
        WHERE pay_date BETWEEN '$df' AND '$dt'
          AND delivery_person IS NOT NULL AND delivery_person != ''
        GROUP BY delivery_person, entry_type
    ");
    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $dp=$dp_lookup[dpNorm($r['dp'])]??null; if($dp===null) continue;
        if($r['entry_type']==='charge') $D[$dp]['cash_charged']=floatval($r['total_amount']);
        if($r['entry_type']==='absorb') $D[$dp]['cash_absorbed']=floatval($r['total_amount']);
    }

    $q=mysqli_query($conn,"
        SELECT ud.id, ud.delivery_person_name, ud.delivery_person_code,
               ud.short_excess, ud.tur, ud.adj_qty_good_units, ud.adj_qty_damage,
               ud.actual_qty, ud.actual_damage_qty,
               ud.charge_to_employee, ud.absorb_by_company
        FROM unloading_data ud
        INNER JOIN unloading_summary_imports ui ON ui.id=ud.import_id
        WHERE ui.delivery_date BETWEEN '$df' AND '$dt' AND ui.status='completed'
    ");
    $dp_name_map=[];
    $q2=mysqli_query($conn,"
        SELECT DISTINCT LOWER(TRIM(delivery_person)) AS dp_name, delivery_person AS dp
        FROM loading_summary_import_details lsd
        INNER JOIN loading_summary_imports ls ON ls.id=lsd.import_id
        WHERE ls.status='completed' AND delivery_person IS NOT NULL AND delivery_person != ''
    ");
    if ($q2) while ($r=mysqli_fetch_assoc($q2)) $dp_name_map[$r['dp_name']]=$r['dp'];

    $emp_dp_map=[];
    $q3=mysqli_query($conn,"
        SELECT LOWER(TRIM(e.employee_id)) AS eid, lsd.delivery_person AS dp
        FROM employees e
        INNER JOIN loading_summary_import_details lsd ON LOWER(TRIM(lsd.delivery_person))=LOWER(TRIM(e.name_with_initials))
        INNER JOIN loading_summary_imports ls ON ls.id=lsd.import_id
        WHERE ls.status='completed' AND lsd.delivery_person IS NOT NULL AND lsd.delivery_person!=''
          AND e.employee_id IS NOT NULL AND e.employee_id!=''
        GROUP BY e.employee_id, lsd.delivery_person
    ");
    if ($q3) while ($r=mysqli_fetch_assoc($q3)) $emp_dp_map[$r['eid']]=$r['dp'];

    $upt_charge=[]; $upt_absorb=[];
    $_ut=mysqli_query($conn,"SHOW TABLES LIKE 'unloading_pay_transactions'");
    if($_ut && mysqli_num_rows($_ut)>0) {
        $utr=mysqli_query($conn,"
            SELECT upt.import_detail_id AS did,
                   COALESCE(SUM(CASE WHEN LOWER(TRIM(entry_type))='charge' THEN amount ELSE 0 END),0) AS c,
                   COALESCE(SUM(CASE WHEN LOWER(TRIM(entry_type))='absorb' THEN amount ELSE 0 END),0) AS a
            FROM unloading_pay_transactions upt
            GROUP BY upt.import_detail_id
        ");
        if($utr) while($ur=mysqli_fetch_assoc($utr)){
            $utp_charge_id=intval($ur['did']);
            $upt_charge[$utp_charge_id]=floatval($ur['c']);
            $upt_absorb[$utp_charge_id]=floatval($ur['a']);
        }
    }

    if ($q) while ($r=mysqli_fetch_assoc($q)) {
        $dp_name=strtolower(trim($r['delivery_person_name']??''));
        $dp_code=strtolower(trim($r['delivery_person_code']??''));
        $dp_raw=$dp_name_map[$dp_name]??($emp_dp_map[$dp_code]??null);
        $dp=($dp_raw!==null)?($dp_lookup[dpNorm($dp_raw)]??null):null;
        if($dp===null) $dp=$dp_lookup[dpNorm($r['delivery_person_name']??'')]??null;
        if($dp===null) continue;

        $rid      = intval($r['id']??0);
        $tur      = floatval($r['tur']??0);
        $se       = floatval($r['short_excess']??0);
        $adj_good = floatval($r['adj_qty_good_units']??0);
        $adj_dmg  = floatval($r['adj_qty_damage']??0);
        $act_good = floatval($r['actual_qty']??0); $act_dmg = floatval($r['actual_damage_qty']??0);

        $D[$dp]['unloading_value']+=$adj_good*$tur;
        $D[$dp]['actual_unload']  +=($act_good+$act_dmg)*$tur;
        $D[$dp]['adj_good_val']   +=$adj_good*$tur;
        $D[$dp]['adj_dmg_val']    +=$adj_dmg*$tur;
        $D[$dp]['act_good_val']   +=$act_good*$tur;
        $D[$dp]['act_dmg_val']    +=$act_dmg*$tur;
        if($se!=0&&$tur>0){
            $D[$dp]['good_se']    +=$tur*$se;
            $D[$dp]['good_se_abs']+=$tur*abs($se);
        }

        $sc = floatval($r['charge_to_employee'] ?? 0);
        $sa = floatval($r['absorb_by_company']  ?? 0);
        if ($sc <= 0 && isset($upt_charge[$rid])) $sc = $upt_charge[$rid];
        if ($sa <= 0 && isset($upt_absorb[$rid])) $sa = $upt_absorb[$rid];

        $se_amt = ($tur>0) ? $tur*abs($se) : 0;
        if      ($sc > 0)             $row_charge = $sc;
        elseif  ($se < 0 && $tur > 0) $row_charge = $se_amt;
        else                          $row_charge = 0;
        $row_absorb = ($sa > 0) ? $sa : 0;
        $D[$dp]['gs_charged']  += $row_charge;
        $D[$dp]['gs_absorbed'] += $row_absorb;
    }

    foreach ($all_dps as $dp) {
        $D[$dp]['unloading_value']=abs($D[$dp]['unloading_value']);
        $D[$dp]['actual_unload']=abs($D[$dp]['actual_unload']);
    }

    foreach ($all_dps as $dp) {
        $D[$dp]['total_loading']  =$D[$dp]['loading_value']+$D[$dp]['free_issues_primary'];
        $D[$dp]['gross_secondary']=$D[$dp]['loading_value']+$D[$dp]['free_issues_primary']-$D[$dp]['good_returns_value']-$D[$dp]['unloading_value'];
        $D[$dp]['variance']       =$D[$dp]['final_bill']-$D[$dp]['cash_paid']-$D[$dp]['cheque_paid']-$D[$dp]['credit_value'];
        $D[$dp]['ikea_unload']    =$D[$dp]['adj_good_val']+$D[$dp]['adj_dmg_val'];
        $D[$dp]['good_se_yms']    =$D[$dp]['ikea_unload']-$D[$dp]['actual_unload'];
        foreach ($keys as $k) $T[$k]+=($D[$dp][$k]??0.0);
    }
    $T['total_loading']  =$T['loading_value']+$T['free_issues_primary'];
    $T['gross_secondary']=$T['loading_value']+$T['free_issues_primary']-$T['good_returns_value']-$T['unloading_value'];
    $T['variance']       =$T['final_bill']-$T['cash_paid']-$T['cheque_paid']-$T['credit_value'];
    $T['ikea_unload']    =$T['adj_good_val']+$T['adj_dmg_val'];
    $T['good_se_yms']    =$T['ikea_unload']-$T['actual_unload'];

    $active_dps = [];
    foreach ($all_dps as $dp) {
        $hasData = false;
        foreach ($keys as $k) {
            if (floatval($D[$dp][$k] ?? 0) != 0) { $hasData = true; break; }
        }
        if ($hasData) $active_dps[] = $dp;
    }
    $all_dps = $active_dps;

    foreach ($keys as $k) $T[$k] = 0.0;
    foreach ($all_dps as $dp) {
        foreach ($keys as $k) $T[$k] += ($D[$dp][$k] ?? 0.0);
    }
    $T['total_loading']  =$T['loading_value']+$T['free_issues_primary'];
    $T['gross_secondary']=$T['loading_value']+$T['free_issues_primary']-$T['good_returns_value']-$T['unloading_value'];
    $T['variance']       =$T['final_bill']-$T['cash_paid']-$T['cheque_paid']-$T['credit_value'];
    $T['ikea_unload']    =$T['adj_good_val']+$T['adj_dmg_val'];
    $T['good_se_yms']    =$T['ikea_unload']-$T['actual_unload'];
}

$dfl='';
if ($submitted) {
    $dfl=($date_from===$date_to)
        ? date('d/m/Y',strtotime($date_from))
        : date('d/m/Y',strtotime($date_from)).' – '.date('d/m/Y',strtotime($date_to));
}

$N = count($all_dps);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>DP Full Report - <?= htmlspecialchars($dfl) ?></title>
<style>
@page { size: A4 landscape; margin: 6mm; }
*{box-sizing:border-box;margin:0;padding:0;}
html,body{width:100%;height:100%;}
body{
    font-family: Arial, Helvetica, sans-serif;
    background:#fff; color:#000;
    -webkit-print-color-adjust:exact; print-color-adjust:exact;
}
.print-header{ text-align:center; border-bottom:1.5px solid #000; padding-bottom:2mm; margin-bottom:2.5mm; }
.print-header h1{ font-size:12pt; font-weight:900; letter-spacing:.5px; text-transform:uppercase; }
.print-header p{ font-size:8pt; color:#222; margin-top:1mm; }

table.ft{
    width:100%; border-collapse:collapse; table-layout:fixed;
    font-size:6.6pt; font-family:Arial, Helvetica, sans-serif;
}
table.ft th, table.ft td{
    border:0.5px solid #000; padding:1px 2.5px; white-space:normal;
    word-break:break-word; overflow:hidden; color:#000; background:#fff;
}
table.ft thead th{
    background:#1a1a1a !important; color:#fff !important; font-weight:bold;
    text-align:center; font-size:6.6pt;
}
table.ft thead th.desc{ text-align:left; width:60mm; background:#111 !important; }
table.ft thead th.total{ width:20mm; background:#000 !important; }
table.ft thead th.dp{ font-size:6.2pt; line-height:1.05; }

table.ft td.desc{ font-weight:bold; width:60mm; }
table.ft td.desc.ind{ padding-left:5px; font-weight:normal; }
table.ft td.total{ text-align:right; font-weight:bold; width:20mm; background:#eaeaea; }
table.ft td.val{ text-align:right; }
table.ft td.valt{ text-align:right; font-weight:bold; }
table.ft td.neg{ text-align:right; }

tr.rn td{ background:#fff; }
tr.ra td{ background:#f6f6f6; }
tr.rs td{ background:#dcdcdc; font-weight:bold; }
tr.rd td{ background:#efefef; font-weight:bold; }
tr.rc td{ background:#e2e2e2; font-weight:bold; }
tr.blank td{ height:1.2mm; border:none; background:#fff; padding:0; }
tr.sec td{ background:#333 !important; color:#fff !important; font-weight:bold; font-size:6.8pt; text-transform:uppercase; letter-spacing:.4px; }

.se-sh,.se-ex,.se-ok{ color:#000 !important; font-weight:bold; }

tr{ page-break-inside:avoid; }
thead{ display:table-header-group; }

.noprint{ text-align:center; padding:10px; }
.noprint button{
    font-family:Arial,sans-serif; font-size:13px; font-weight:700;
    padding:8px 20px; border:none; border-radius:6px; background:#1a1a2e; color:#fff; cursor:pointer;
}
@media print{ .noprint{ display:none !important; } }

.es{ text-align:center; padding:60px; font-family:Arial,sans-serif; color:#555; }

.sig-block{
    display:flex; justify-content:space-between; margin-top:8mm;
    padding-top:2mm; page-break-inside:avoid;
}
.sig-box{ width:45%; font-family:Arial, Helvetica, sans-serif; }
.sig-line{
    border-bottom:1px solid #000; height:10mm; margin-bottom:1.5mm;
}
.sig-label{ font-size:8pt; font-weight:bold; text-transform:uppercase; letter-spacing:.3px; }
.sig-sub{ font-size:7pt; color:#333; margin-top:0.5mm; }
@media print{
    .sig-block{ page-break-inside:avoid; }
}
</style>
</head>
<body>

<?php if (!$submitted): ?>
    <div class="es"><p>No date range supplied. Add ?date_from=YYYY-MM-DD&amp;date_to=YYYY-MM-DD to the URL.</p></div>
<?php elseif (empty($all_dps)): ?>
    <div class="es"><p>No data found for <?= htmlspecialchars($dfl) ?></p></div>
<?php else: ?>

<div class="noprint"><button onclick="window.print()">Print</button></div>

<div class="print-header">
    <h1>Yelo Logistics — Full Report (Delivery Person Wise)</h1>
    <p>Date: <?= htmlspecialchars($dfl) ?> &nbsp;|&nbsp; Printed: <?= date('d/m/Y H:i') ?> &nbsp;|&nbsp; DPs: <?= $N ?></p>
</div>

<table class="ft">
<thead>
<tr>
    <th class="desc">Description</th>
    <th class="total">Total</th>
    <?php foreach ($all_dps as $dp): ?><th class="dp"><?= dpHeader($dp) ?></th><?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php

buildSection('Loading', $N);
buildRow('rn','Loading Value', false, fmtN($T['loading_value']), $all_dps, buildMap($all_dps,$D,'loading_value'));

$lv_pct=[]; foreach ($all_dps as $dp) $lv_pct[$dp]=fmtPct($D[$dp]['loading_value'],$T['loading_value']);
buildRow('ra','Loading Value %', true, ($T['loading_value']>0?'100%':'-'), $all_dps, $lv_pct);

buildRow('rn','Free Issues Value',   false, fmtN($T['free_issues_primary']), $all_dps, buildMap($all_dps,$D,'free_issues_primary'));
buildRow('rs','Total Loading Value', false, fmtN($T['total_loading']),       $all_dps, buildMap($all_dps,$D,'total_loading'), 'valt');
buildBlank($N);

buildRow('rn','Good Returns Value',                 false, fmtN($T['good_returns_value']),  $all_dps, buildMap($all_dps,$D,'good_returns_value'));
buildRow('ra','Unloading Value',                    false, fmtNeg($T['unloading_value']),   $all_dps, buildMap($all_dps,$D,'unloading_value','fmtNeg'), 'neg');
buildRow('rs','Gross Sale as per Secondary Report', false, fmtN($T['gross_secondary']),     $all_dps, buildMap($all_dps,$D,'gross_secondary'), 'valt');
buildRow('rd','Diff',                               false, '-', $all_dps, array_fill_keys($all_dps,'-'));
buildBlank($N);

buildRow('rn','Free Issues Value',            true,  fmtNeg($T['free_issues_secondary']), $all_dps, buildMap($all_dps,$D,'free_issues_secondary','fmtNeg'), 'neg');
buildRow('ra','Total Discount',               true,  fmtNeg($T['total_discount']),        $all_dps, buildMap($all_dps,$D,'total_discount','fmtNeg'),        'neg');
buildRow('rn','Good Returns Value',           true,  fmtNeg($T['good_returns_value']),    $all_dps, buildMap($all_dps,$D,'good_returns_value','fmtNeg'),     'neg');
buildRow('ra','Damage-Expiry Shortage Value', true,  fmtNeg($T['dmg_expiry']),            $all_dps, buildMap($all_dps,$D,'dmg_expiry','fmtNeg'),            'neg');
buildRow('rs','Final Bill Amount to be collected', false, fmtN($T['final_bill']),         $all_dps, buildMap($all_dps,$D,'final_bill'),                     'valt');
buildBlank($N);

buildRow('rn','Cash Paid',    false, fmtNeg($T['cash_paid']),    $all_dps, buildMap($all_dps,$D,'cash_paid','fmtNeg'),    'neg');
buildRow('ra','Cheque Paid',  false, fmtNeg($T['cheque_paid']),  $all_dps, buildMap($all_dps,$D,'cheque_paid','fmtNeg'), 'neg');
buildRow('rn','Credit Value', false, fmtNeg($T['credit_value']), $all_dps, buildMap($all_dps,$D,'credit_value','fmtNeg'),'neg');
buildRow('rs','Variance with Secondary Sale', false, fmtAuto($T['variance']), $all_dps, buildMap($all_dps,$D,'variance','fmtAuto'), 'valt');
buildBlank($N);

buildRow('rn','CC Cash Collection (Daily Sale)', false, fmtN($T['cc_daily_sale']), $all_dps, buildMap($all_dps,$D,'cc_daily_sale'));
buildRow('ra','Cash paid for Invoices Credit',   false, fmtN($T['cash_invoice']),  $all_dps, buildMap($all_dps,$D,'cash_invoice'));
buildRow('rn','Cash paid RTN Cheques',           false, fmtN($T['cash_rtn_chq']),  $all_dps, buildMap($all_dps,$D,'cash_rtn_chq'));
buildRow('ra','Cash paid RTN Chq Chgs',          false, fmtN($T['cash_rtn_chgs']), $all_dps, buildMap($all_dps,$D,'cash_rtn_chgs'));
buildRow('rn','CC Sent Back Cheque',             false, fmtN($T['cc_sent_back']),  $all_dps, buildMap($all_dps,$D,'cc_sent_back'));
buildBlank($N);
buildRow('ra','Bank Deposited', false, fmtNeg($T['bank_deposited']), $all_dps, buildMap($all_dps,$D,'bank_deposited','fmtNeg'), 'neg');
buildRow('rn','Handover to BO', false, fmtNeg($T['handover_bo']),    $all_dps, buildMap($all_dps,$D,'handover_bo','fmtNeg'),    'neg');

$cse_vals=[]; foreach ($all_dps as $dp) $cse_vals[$dp]=fmtSE($D[$dp]['cash_se']);
buildRow('rc','Cash Shortage / Excess', false, fmtSE($T['cash_se']), $all_dps, $cse_vals, 'valt');
buildRow('rn','Charged to Employee', true, fmtAuto($T['cash_charged']),  $all_dps, buildMap($all_dps,$D,'cash_charged','fmtAuto'),  'val');
buildRow('ra','Absorbed by Company', true, fmtAuto($T['cash_absorbed']), $all_dps, buildMap($all_dps,$D,'cash_absorbed','fmtAuto'), 'val');

$cdiff_t=$T['cash_se']-$T['cash_charged']-$T['cash_absorbed'];
$cdiff_v=[]; foreach ($all_dps as $dp) $cdiff_v[$dp]=fmtSE($D[$dp]['cash_se']-$D[$dp]['cash_charged']-$D[$dp]['cash_absorbed']);
buildRow('rd','Diff (Short / Excess)', false, fmtSE($cdiff_t), $all_dps, $cdiff_v);
buildBlank($N);

buildSection('Unloading / Good Shortage', $N);

buildRow('rs','IKEA Unloading Value',   false, fmtN($T['ikea_unload']),   $all_dps, buildMap($all_dps,$D,'ikea_unload'),   'valt');
buildRow('rs','Actual Unloading Value', false, fmtN($T['actual_unload']), $all_dps, buildMap($all_dps,$D,'actual_unload'), 'valt');

$yms_vals=[]; foreach ($all_dps as $dp) $yms_vals[$dp]=fmtSE($D[$dp]['good_se_yms']);
buildRow('rn','Good Short / Excess', false, fmtSE($T['good_se_yms']), $all_dps, $yms_vals);

$gse_vals=[]; foreach ($all_dps as $dp) $gse_vals[$dp]=fmtSEg($D[$dp]['good_se']);
buildRow('rc','Good Short / Excess as YMS', false, fmtSEg($T['good_se']), $all_dps, $gse_vals, 'valt');

buildRow('rn','Charged to Employee', true, fmtAuto($T['gs_charged']),  $all_dps, buildMap($all_dps,$D,'gs_charged','fmtAuto'),  'val');
buildRow('ra','Absorbed by Company', true, fmtAuto($T['gs_absorbed']), $all_dps, buildMap($all_dps,$D,'gs_absorbed','fmtAuto'), 'val');

$gdiff_t=$T['good_se_abs']-$T['gs_charged']-$T['gs_absorbed'];
$gdiff_v=[]; foreach ($all_dps as $dp) $gdiff_v[$dp]=fmtAuto($D[$dp]['good_se_abs']-$D[$dp]['gs_charged']-$D[$dp]['gs_absorbed']);
buildRow('rd','Diff (Unsettled)', false, fmtAuto($gdiff_t), $all_dps, $gdiff_v);

?>
</tbody>
</table>

<div class="sig-block">
    <div class="sig-box">
        <div class="sig-line"></div>
        <div class="sig-label">Checked By</div>
        <div class="sig-sub">Signature &amp; Date</div>
    </div>
    <div class="sig-box">
        <div class="sig-line"></div>
        <div class="sig-label">Authorized By</div>
        <div class="sig-sub">Operation Manager &nbsp;|&nbsp; Signature &amp; Date</div>
    </div>
</div>

<?php endif; ?>

<script>
window.onload = function(){
    setTimeout(function(){ window.print(); }, 300);
};
</script>

</body>
</html>