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
   Convention (matches the delivery-person daily collection report's
   "Final Short/Excess"):
       POSITIVE value  = SHORT   (cash collected but NOT yet deposited/settled)
       NEGATIVE value  = EXCESS  (deposited/settled MORE than was collected)
   Rendered as:
       Short   ->  1,234 Sh      (red)
       Excess  -> (1,234) Ex     (green)
       Zero    ->  0             (fully reconciled)                          */
function fmtSE($v){
    $v = floatval($v);
    if (abs($v) < 0.5) return '<span class="se-ok">0</span>';
    if ($v > 0)        return '<span class="se-sh">'.number_format($v).' Sh</span>';
    return '<span class="se-ex">('.number_format(abs($v)).') Ex</span>';
}

/* Good / unloading side uses the OPPOSITE sign convention to the cash side:
       NEGATIVE value = SHORT  (short_excess is stored negative for shortages)
       POSITIVE value = EXCESS
   Rendered the same way (Short -> "n Sh" red, Excess -> "(n) Ex" green) so a
   shortage reads as "Sh" in every section regardless of the raw sign. */
function fmtSEg($v){
    $v = floatval($v);
    if (abs($v) < 0.5) return '<span class="se-ok">0</span>';
    if ($v < 0)        return '<span class="se-sh">'.number_format(abs($v)).' Sh</span>';
    return '<span class="se-ex">('.number_format($v).') Ex</span>';
}

/* Normalize a delivery-person name so loading / secondary / deposit records
   that differ only by case or spacing link to the SAME delivery person. */
function dpNorm($s){ $s = preg_replace('/\s+/',' ', (string)($s ?? '')); return strtolower(trim($s)); }

/* Extract just the real name out of a delivery person's raw value, which may
   have an employee code glued onto the front, back, or mixed with digits
   anywhere in the string. Rule: the actual name is always pure alphabetic
   text (letters / spaces / '.' for initials) — codes always contain digits —
   so we pick the LONGEST run of alphabetic text in the string and use that.
      "2271600Saman803559"  -> "Saman"      (digits before & after)
      "DAA3119Rasanga"      -> "Rasanga"    (letter+digit code glued in front)
      "John Silva"          -> "John Silva" (already clean, whole name kept)
   NOTE: this is DISPLAY ONLY — matching/lookups elsewhere still use the
   original raw name so data joins are unaffected. */
function cleanDpName($dp) {
    $raw = trim((string)$dp);
    if ($raw === '') return $raw;
    preg_match_all('/[A-Za-z.]+(?:\s+[A-Za-z.]+)*/', $raw, $m);
    if (empty($m[0])) return $raw; // no alphabetic text at all — fall back to raw
    $best = '';
    foreach ($m[0] as $chunk) {
        $chunk = trim($chunk, " .");
        if (strlen($chunk) > strlen($best)) $best = $chunk;
    }
    return $best !== '' ? $best : $raw;
}

/* Split a delivery-person name onto two lines for a compact header */
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
$submitted = isset($_GET['search']) && $date_from !== '';
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
    'cc_daily_sale','cc_sent_back',                       /* NEW: CC-basis base components */
    'bank_deposited','handover_bo','cash_se',
    'cash_charged','cash_absorbed',
    'ikea_unload','actual_unload','good_se','good_se_yms','good_se_abs',
    'adj_good_val','adj_dmg_val','act_good_val','act_dmg_val',
    'gs_charged','gs_absorbed',
];
/* normalized name → canonical delivery person (so every source links to one row) */
$dp_lookup = [];
foreach ($all_dps as $dp) $dp_lookup[dpNorm($dp)] = $dp;

foreach ($all_dps as $dp) { $D[$dp] = array_fill_keys($keys, 0.0); }
foreach ($keys as $k) $T[$k] = 0.0;

if ($submitted && count($all_dps)) {

    /* ── Loading value — keyed by delivery_person via loading_summary_import_details ── */
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

    /* ── Secondary invoice: good returns, damage, final bill, discount ── */
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

    /* ── Free issues — primary (via loading_summary delivery_person) ── */
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

    /* ── Free issues — secondary ── */
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

    /* ── Invoice payments: cash paid, cheque paid — keyed by delivery_person on siid ── */
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

    /* ── Credit value ── */
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

    /* ═══════════════════════════════════════════════════════════════════════
       CC CASH COLLECTION BREAKDOWN (CC basis)
       These mirror the delivery-person daily collection report EXACTLY, so the
       Cash Shortage/Excess base and its Charged/Absorbed allocations reconcile.

         CC Total = CC Daily Sale + Cash for Invoices Credit
                  + Cash Rtn Cheque + Cash Rtn Charges + CC Sent Back
       (All CC figures are collected_by='cc'.)
       ═══════════════════════════════════════════════════════════════════════ */

    /* rc_settlement_payments availability — authoritative source for Rtn Charges */
    $rc_tbl_exists=false; $rc_has_dp=false;
    $_t=mysqli_query($conn,"SHOW TABLES LIKE 'rc_settlement_payments'");
    if($_t && mysqli_num_rows($_t)>0){
        $rc_tbl_exists=true;
        $_c=mysqli_query($conn,"SHOW COLUMNS FROM rc_settlement_payments LIKE 'delivery_person'");
        if($_c && mysqli_num_rows($_c)>0) $rc_has_dp=true;
    }

    /* ── CC Daily Sale — CC cash on invoice/cash-issued bills, joined via siid.
          collected_by='cc' AND payment_source is invoice/blank. ── */
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

    /* ── Cash paid for Invoices Credit — CC cash collection on CREDIT-issued bills.
          collected_by='cc' AND payment_source LIKE '%credit%', keyed directly on
          invoice_payments.delivery_person / payment_date. ── */
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

    /* ── CC Rtn Cheque + CC Sent Back — collected_by='cc', keyed on ip.delivery_person ── */
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

    /* ── CC Rtn Charges — authoritative from rc_settlement_payments (cash) ──
          (Replaces the invoice_payments return_charge_settlement value to avoid
           double counting, exactly as the daily collection report does.) */
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

    /* ── Deposits: bank + BO — cc_cash_deposit_dp_persons ──
       Filtered to collected_by='cc' on the header table (cc_cash_deposit_dp),
       so only deposits actually collected by CC count toward this delivery
       person's figures (CC basis, not SR). BOTH bank-deposited and handed-to-BO
       are netted off the Cash Shortage/Excess below — matching the daily
       collection report's "Final Short/Excess" base. */
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

    /* ── Cash Shortage / Excess — SAME base as the delivery-person daily
          collection report's "Final Short/Excess":

            Base = CC Total − (Bank Deposited + Handover to BO)
            CC Total = CC Daily Sale + Cash for Invoices Credit
                     + Cash Rtn Cheque + Cash Rtn Charges + CC Sent Back

          Because the Pay Allocation modal in the daily report charges/absorbs
          against this exact base, the Charged/Absorbed figures below
          (from cash_summary_pay_allocations_dp) reconcile and the Diff row
          nets to zero when fully settled. ── */
    foreach ($all_dps as $dp) {
        $cc_total = $D[$dp]['cc_daily_sale']
                  + $D[$dp]['cash_invoice']
                  + $D[$dp]['cash_rtn_chq']
                  + $D[$dp]['cash_rtn_chgs']
                  + $D[$dp]['cc_sent_back'];
        $D[$dp]['cash_se'] = $cc_total - $D[$dp]['bank_deposited'] - $D[$dp]['handover_bo'];
    }

    /* ── Cash charged / absorbed — cash_summary_pay_allocations_dp ──
          (Same table the daily report's Pay Allocation modal writes to.) */
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

    /* ── Unloading data — delivery_person_name matches loading_summary delivery_person ── */
    $q=mysqli_query($conn,"
        SELECT ud.id, ud.delivery_person_name, ud.delivery_person_code,
               ud.short_excess, ud.tur, ud.adj_qty_good_units, ud.adj_qty_damage,
               ud.actual_qty, ud.actual_damage_qty,
               ud.charge_to_employee, ud.absorb_by_company
        FROM unloading_data ud
        INNER JOIN unloading_summary_imports ui ON ui.id=ud.import_id
        WHERE ui.delivery_date BETWEEN '$df' AND '$dt' AND ui.status='completed'
    ");
    /* build name→dp map from loading_summary */
    $dp_name_map=[];
    $q2=mysqli_query($conn,"
        SELECT DISTINCT LOWER(TRIM(delivery_person)) AS dp_name, delivery_person AS dp
        FROM loading_summary_import_details lsd
        INNER JOIN loading_summary_imports ls ON ls.id=lsd.import_id
        WHERE ls.status='completed' AND delivery_person IS NOT NULL AND delivery_person != ''
    ");
    if ($q2) while ($r=mysqli_fetch_assoc($q2)) $dp_name_map[$r['dp_name']]=$r['dp'];

    /* employee_id → dp via loading summary */
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

    /* ── Charged / Absorbed — mirror shortage.php's per-row cell logic ──
       In the (fixed) shortage.php each row's cell shows:
         • CHARGE: the STORED charge_to_employee when > 0, otherwise a SUGGESTED
           value = tur × |short_excess| for a SHORT row (short_excess < 0).
         • ABSORB: ONLY the STORED absorb_by_company (or its explicit ledger
           allocation). There is NO excess suggestion — a value appears here only
           after it is actually saved through the Pay modal. Excess rows with
           nothing absorbed show a dash, exactly like the shortage screen.
       We reproduce the same rule per row here, then aggregate per delivery
       person. */
    $upt_charge=[]; $upt_absorb=[];  /* explicit ledger allocations, keyed by ud.id */
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
        /* fallback: the unloading name may directly match a report delivery person */
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
        $D[$dp]['adj_good_val']   +=$adj_good*$tur;   /* Adj Qty (Good)   × TUR */
        $D[$dp]['adj_dmg_val']    +=$adj_dmg*$tur;    /* Adj Qty (Damage) × TUR */
        $D[$dp]['act_good_val']   +=$act_good*$tur;   /* Good Value  = Actual Qty × TUR   */
        $D[$dp]['act_dmg_val']    +=$act_dmg*$tur;    /* Damage Value = Actual Dmg × TUR  */
        if($se!=0&&$tur>0){
            $D[$dp]['good_se']    +=$tur*$se;       /* signed: NEGATIVE = short (S/E Value = TUR × S/E) */
            $D[$dp]['good_se_abs']+=$tur*abs($se);  /* magnitude charges are made against */
        }

        /* stored allocation: column value, or ledger value if the column is empty */
        $sc = floatval($r['charge_to_employee'] ?? 0);
        $sa = floatval($r['absorb_by_company']  ?? 0);
        if ($sc <= 0 && isset($upt_charge[$rid])) $sc = $upt_charge[$rid];
        if ($sa <= 0 && isset($upt_absorb[$rid])) $sa = $upt_absorb[$rid];

        $se_amt = ($tur>0) ? $tur*abs($se) : 0;
        /* Charge cell — as shortage.php: stored charge if > 0,
           otherwise the suggested tur×|se| for a SHORT row (short_excess < 0). */
        if      ($sc > 0)             $row_charge = $sc;
        elseif  ($se < 0 && $tur > 0) $row_charge = $se_amt;
        else                          $row_charge = 0;
        /* Absorb cell (FIXED) — real saved absorb ONLY: the stored
           absorb_by_company column, or the explicit ledger allocation from
           unloading_pay_transactions. NO excess suggestion — nothing is
           "absorbed by company" until it is actually saved via the Pay modal. */
        $row_absorb = ($sa > 0) ? $sa : 0;
        $D[$dp]['gs_charged']  += $row_charge;
        $D[$dp]['gs_absorbed'] += $row_absorb;
    }

    foreach ($all_dps as $dp) {
        $D[$dp]['unloading_value']=abs($D[$dp]['unloading_value']);
        $D[$dp]['actual_unload']=abs($D[$dp]['actual_unload']);
    }

    /* ── Computed totals per DP ── */
    foreach ($all_dps as $dp) {
        $D[$dp]['total_loading']  =$D[$dp]['loading_value']+$D[$dp]['free_issues_primary'];
        $D[$dp]['gross_secondary']=$D[$dp]['loading_value']+$D[$dp]['free_issues_primary']-$D[$dp]['good_returns_value']-$D[$dp]['unloading_value'];
        $D[$dp]['variance']       =$D[$dp]['final_bill']-$D[$dp]['cash_paid']-$D[$dp]['cheque_paid']-$D[$dp]['credit_value'];
        $D[$dp]['ikea_unload']    =$D[$dp]['adj_good_val']+$D[$dp]['adj_dmg_val']; /* Adj Good + Adj Damage (× TUR) */
        $D[$dp]['good_se_yms']    =$D[$dp]['ikea_unload']-$D[$dp]['actual_unload'];
        foreach ($keys as $k) $T[$k]+=($D[$dp][$k]??0.0);
    }
    $T['total_loading']  =$T['loading_value']+$T['free_issues_primary'];
    $T['gross_secondary']=$T['loading_value']+$T['free_issues_primary']-$T['good_returns_value']-$T['unloading_value'];
    $T['variance']       =$T['final_bill']-$T['cash_paid']-$T['cheque_paid']-$T['credit_value'];
    $T['ikea_unload']    =$T['adj_good_val']+$T['adj_dmg_val'];
    $T['good_se_yms']    =$T['ikea_unload']-$T['actual_unload'];

    /* ── Hide delivery persons that have NO data at all (every figure is zero/empty) ──
       This is what produces a column full of "-" in the table — that DP simply has
       no loading / secondary / payment / deposit / unloading activity in this
       date range, so we drop it from $all_dps before rendering. */
    $active_dps = [];
    foreach ($all_dps as $dp) {
        $hasData = false;
        foreach ($keys as $k) {
            if (floatval($D[$dp][$k] ?? 0) != 0) { $hasData = true; break; }
        }
        if ($hasData) $active_dps[] = $dp;
    }
    $all_dps = $active_dps;

    /* keep totals consistent — re-zero totals and re-sum from the now-filtered list,
       then recompute the derived total figures the same way as above */
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

/* ── compact, two-line DP column headers ── */
table.ft thead th.dp{
    white-space:normal;line-height:1.2;font-size:9px;letter-spacing:.2px;
    min-width:56px;max-width:72px;width:64px;padding:4px 4px;word-break:break-word;}
table.ft td.val,table.ft td.valt,table.ft td.neg{
    min-width:56px;max-width:72px;font-size:10.5px;padding:3px 5px;}

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
.se-sh{color:#c0392b !important;font-weight:700;}   /* short  – red   */
.se-ex{color:#166534 !important;font-weight:700;}   /* excess – green */
.se-ok{color:#6b7280 !important;font-weight:700;}   /* balanced/0     */
tr.sec td{background:#e8ebf7;border-top:2px solid var(--acc);}
tr.sec td.desc{font-family:'Syne',sans-serif;font-weight:800;font-size:11px;
    text-transform:uppercase;letter-spacing:.6px;color:var(--acc);}
.es{text-align:center;padding:50px;color:var(--muted);}
.es span{font-size:36px;display:block;margin-bottom:10px;}
.es p{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;}

@media print {
    @page { size: A4 landscape; margin: 10mm 8mm 10mm 8mm; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .fc, .ph-btns, header, nav, .sidebar, footer { display: none !important; }
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
        white-space: normal !important; word-break: break-word !important;
        color: #000 !important; background: #fff !important; position: static !important; }
    table.ft td.desc, table.ft th.desc { width: 34mm !important; min-width: unset !important;
        font-size: 6.5pt !important; background: #fff !important; color: #000 !important; }
    table.ft td.desc.ind { padding-left: 5px !important; font-weight: normal !important; }
    table.ft td.total, table.ft th.total { width: 18mm !important; font-weight: bold !important;
        background: #e8e8e8 !important; }
    table.ft thead th { background: #1a1a1a !important; color: #fff !important; font-size: 6.5pt !important;
        font-weight: bold !important; position: static !important; text-align: center !important; }
    table.ft thead th.dp { width: auto !important; min-width: unset !important; max-width: unset !important;
        font-size: 6pt !important; }
    tr.sec td { background: #333 !important; color: #fff !important; font-size: 6.5pt !important; font-weight: bold !important; }
    tr.rs td { background: #ccc !important; color: #000 !important; font-weight: bold !important; }
    tr.rd td { background: #f0f0f0 !important; color: #000 !important; font-weight: bold !important; }
    tr.rc td { background: #e0e0e0 !important; color: #000 !important; font-weight: bold !important; }
    tr.rn td { background: #fff !important; }
    tr.ra td { background: #f7f7f7 !important; }
    tr.blank td { height: 2px !important; background: #ddd !important; border: none !important; }
    .se-sh, .se-ex, .se-ok { color: #000 !important; font-weight: bold !important; }
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

<?php if ($submitted && empty($all_dps)): ?>
<div class="es"><span>🔍</span><p>No data found for <?= htmlspecialchars($dfl) ?></p></div>

<?php elseif ($submitted && count($all_dps)): ?>
<?php $N = count($all_dps); ?>

<div class="print-header">
    <h1>Yelo Logistics — Full Report (Delivery Person Wise)</h1>
    <p>Date: <?= htmlspecialchars($dfl) ?> &nbsp;|&nbsp; Printed: <?= date('d/m/Y H:i') ?></p>
</div>

<div class="rw">
<div class="ph">
    <div class="ph-left">
        <div class="co">Yelo Logistics</div>
        <div class="me">
            <span><strong>Full Report — Delivery Person Wise</strong></span>
            <span>Date – <strong><?= htmlspecialchars($dfl) ?></strong></span>
            <span>Print Time – <strong><?= date('H:i') ?></strong></span>
            <span>DPs – <strong><?= $N ?></strong></span>
        </div>
    </div>
    <div class="ph-btns">
        <button class="ph-btn ph-btn-excel" onclick="exportExcel()">&#9651; Export Excel</button>
        <button class="ph-btn ph-btn-print" onclick="window.print()">&#9113; Print B&amp;W</button>
          <a class="ph-btn ph-btn-print"
       href="dp_full_report_print.php?date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>"
       target="_blank"
       style="text-decoration:none;">&#9113; Print Full A4</a>
    </div>
</div>

<div class="to">
<table class="ft" id="reportTable">
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

/* ── Loading Value % — each DP's share of total loading value ── */
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

/* ── CC cash collection — the components that build the Cash Shortage/Excess base,
      matching the delivery-person daily collection report's "Final Short/Excess". ── */
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

/* Diff = unreconciled remainder after charge & absorb are applied to the base.
   Base convention: SHORT is positive, EXCESS is negative.
     • A fully-settled shortage  -> 0
     • Leftover shortage         -> "n Sh"  (red)   still to be charged/absorbed
     • An excess (nothing to charge) -> "(n) Ex" (green) carried down unchanged */
$cdiff_t=$T['cash_se']-$T['cash_charged']-$T['cash_absorbed'];
$cdiff_v=[]; foreach ($all_dps as $dp) $cdiff_v[$dp]=fmtSE($D[$dp]['cash_se']-$D[$dp]['cash_charged']-$D[$dp]['cash_absorbed']);
buildRow('rd','Diff (Short / Excess)', false, fmtSE($cdiff_t), $all_dps, $cdiff_v);
buildBlank($N);

buildSection('Unloading / Good Shortage', $N);

/* IKEA Unloading Value = Adj Qty (Good) × TUR + Adj Qty (Damage) × TUR
   Actual Unloading Value = Actual Qty × TUR + Actual Dmg Qty × TUR
   (the Good Value / Damage Value breakdown is intentionally not shown.) */
buildRow('rs','IKEA Unloading Value',   false, fmtN($T['ikea_unload']),   $all_dps, buildMap($all_dps,$D,'ikea_unload'),   'valt');
buildRow('rs','Actual Unloading Value', false, fmtN($T['actual_unload']), $all_dps, buildMap($all_dps,$D,'actual_unload'), 'valt');

/* Good Short / Excess = IKEA Unloading Value − Actual Unloading Value.
   POSITIVE = Short (less unloaded than expected), NEGATIVE = Excess. */
$yms_vals=[]; foreach ($all_dps as $dp) $yms_vals[$dp]=fmtSE($D[$dp]['good_se_yms']);
buildRow('rn','Good Short / Excess', false, fmtSE($T['good_se_yms']), $all_dps, $yms_vals);

/* Good Short / Excess as YMS — ONE value per delivery person, straight from the
   shortage base: Σ (TUR × Short/Excess), signed so it reads that DP's net position:
       NEGATIVE  -> Short   (shown "n Sh", red)
       POSITIVE  -> Excess  (shown "(n) Ex", green)
       zero      -> "0" */
$gse_vals=[]; foreach ($all_dps as $dp) $gse_vals[$dp]=fmtSEg($D[$dp]['good_se']);
buildRow('rc','Good Short / Excess as YMS', false, fmtSEg($T['good_se']), $all_dps, $gse_vals, 'valt');

/* Charged — same per-row rule as the shortage page (stored, else suggested for shorts).
   Absorbed — real saved absorb only (stored column / ledger); no excess suggestion. */
buildRow('rn','Charged to Employee', true, fmtAuto($T['gs_charged']),  $all_dps, buildMap($all_dps,$D,'gs_charged','fmtAuto'),  'val');
buildRow('ra','Absorbed by Company', true, fmtAuto($T['gs_absorbed']), $all_dps, buildMap($all_dps,$D,'gs_absorbed','fmtAuto'), 'val');

/* Diff (Unsettled) = S/E magnitude (Σ TUR × |S/E|) − Charged − Absorbed. */
$gdiff_t=$T['good_se_abs']-$T['gs_charged']-$T['gs_absorbed'];
$gdiff_v=[]; foreach ($all_dps as $dp) $gdiff_v[$dp]=fmtAuto($D[$dp]['good_se_abs']-$D[$dp]['gs_charged']-$D[$dp]['gs_absorbed']);
buildRow('rd','Diff (Unsettled)', false, fmtAuto($gdiff_t), $all_dps, $gdiff_v);

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
    wsData.push(['Yelo Logistics — Full Report (Delivery Person Wise)']);
    wsData.push(['Date: <?= addslashes($dfl) ?>', '', 'Generated: <?= date('d/m/Y H:i') ?>']);
    wsData.push([]);
    var rows = tbl.querySelectorAll('tr');
    rows.forEach(function(tr) {
        if (tr.classList.contains('blank')) { wsData.push([]); return; }
        var rowData = [];
        tr.querySelectorAll('th,td').forEach(function(cell) {
            var raw = cell.textContent.trim();
            /* strip Short/Excess tags & tick marks so figures export as clean,
               correctly-signed numbers: "1,234 Sh" -> 1234, "(1,234) Ex" -> -1234 */
            var txt = raw.replace(/\s*(Sh|Ex)\s*$/i, '').replace(/[\u2713✓]/g, '').trim();
            if (/^\([\d,]+(?:\.\d+)?\)$/.test(txt)) {
                rowData.push(-parseFloat(txt.replace(/[(),]/g, '')));
            } else if (/^[\d,]+(?:\.\d+)?$/.test(txt) && txt !== '-') {
                rowData.push(parseFloat(txt.replace(/,/g, '')));
            } else {
                rowData.push((txt === '-' || txt === '') ? '' : raw);
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
    XLSX.utils.book_append_sheet(wb, ws, 'DP Full Report');
    var dateStr = '<?= $date_from ?>' === '<?= $date_to ?>'
        ? '<?= $date_from ?>'
        : '<?= $date_from ?>_to_<?= $date_to ?>';
    XLSX.writeFile(wb, 'Yelo_DP_FullReport_' + dateStr + '.xlsx');
}
</script>

<?php include 'footer.php'; ?>