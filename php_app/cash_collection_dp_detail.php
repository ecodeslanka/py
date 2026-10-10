<?php
/**
 * cash_collection_dp_detail.php
 * ───────────────────────────────────────────────────────────────
 * Drill-down for the "Daily Cash Collection — Delivery Person" page.
 * Opens in a new tab when an amount is clicked on cash_collection_dp.php.
 *
 * GET params:
 *   type            = which amount/column was clicked (see $TYPES below)
 *   delivery_person = delivery person name
 *   date            = the row date (delivery / payment date, Y-m-d)
 *
 * Shows the individual records that make up that single amount, filtered
 * to the clicked delivery person + date. Data sources mirror exactly the
 * queries used on cash_collection_dp.php so the totals reconcile.
 */
include 'config.php';
include 'header.php';

mysqli_report(MYSQLI_REPORT_OFF);

$type = trim($_GET['type'] ?? '');
$dp   = trim($_GET['delivery_person'] ?? '');
$date = trim($_GET['date'] ?? '');

$dp_esc   = mysqli_real_escape_string($conn, $dp);
$date_ok  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
$date_esc = mysqli_real_escape_string($conn, $date_ok);

/* ── label/colour metadata for each drill-down type ── */
$TYPES = [
    'sinv'              => ['Secondary Invoice Value',          '#1e40af', 'fa-file-invoice-dollar'],
    'cash_paid'         => ['Cash Paid (Invoice Payments)',     '#7a3f10', 'fa-money-bill-wave'],
    'cheque_paid'       => ['Cheque Paid (Invoice Payments)',   '#7a3f10', 'fa-money-check'],
    'credit'            => ['Credit (Unpaid Balance)',          '#7a3f10', 'fa-hourglass-half'],
    'pay_diff'          => ['Payment Difference',               '#7a3f10', 'fa-code-compare'],
    'cc_daily_sale'     => ['CC — Daily Sale',                  '#166534', 'fa-user-tie'],
    'cc_rcvd_credit'    => ['CC — Received Credit',             '#166534', 'fa-hand-holding-dollar'],
    'cc_rcvd_rtn_chq'   => ['CC — Received Return Cheque',      '#166534', 'fa-rotate-left'],
    'cc_rcvd_rtn_chgs'  => ['CC — Received Return Charges',     '#166534', 'fa-receipt'],
    'cc_rcvd_sent_back' => ['CC — Received Sent Back Cheque',   '#166534', 'fa-reply'],
    'cc_total'          => ['CC Collection — Total',            '#166534', 'fa-user-tie'],
    'total_coll'        => ['Grand Total Collection',           '#0f172a', 'fa-sigma'],
    'banked_cc'         => ['DP Cash Deposit — Banked (CC)',    '#1e4d8c', 'fa-building-columns'],
    'handed_cc'         => ['DP Cash Deposit — Handed to BO (CC)','#5b21b6','fa-handshake'],
    'banked'            => ['Total Deposited (CC)',             '#1e4d8c', 'fa-building-columns'],
    'variance_cc'       => ['Variance (CC)',                    '#7c2d12', 'fa-scale-unbalanced'],
    'short_cc'          => ['Short (CC)',                       '#7f1d1d', 'fa-triangle-exclamation'],
    'new_short_excess'  => ['Final Short / Excess',             '#312e81', 'fa-triangle-exclamation'],
    'p_charge'          => ['Pay Allocation — Charged to Employee', '#5b21b6', 'fa-user-minus'],
    'p_absorb'          => ['Pay Allocation — Absorbed by Company', '#1e40af', 'fa-building'],
];

$meta   = $TYPES[$type] ?? ['Amount Detail', '#2d3748', 'fa-table-list'];
$title  = $meta[0];
$accent = $meta[1];

function money($n){ return number_format((float)$n, 2); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

/* ── per-type WHERE fragments reused below ── */
$cc_src = [
    'cc_rcvd_credit'    => "LOWER(ip.payment_source) LIKE '%credit%'",
    'cc_rcvd_rtn_chq'   => "ip.payment_source='return_cheque_settlement'",
    'cc_rcvd_sent_back' => "ip.payment_source='sentback_cheque_settlement'",
];

$cols       = [];   // header labels
$data       = [];   // rows: each row is an array of pre-rendered <td> html strings
$total      = 0.0;  // footer total
$components = [];    // for composite types: [label, amount, type]
$note       = '';
$ready      = ($dp_esc !== '' && $date_esc !== '');

/* helper: append a data row */
$addRow = function(array $cells) use (&$data){ $data[] = $cells; };

if ($ready) {
switch ($type) {

/* ════════ SECONDARY INVOICE ════════ */
case 'sinv':
    $cols = ['Bill No','Bill Date','T-Code','Customer','Route','Amount'];
    $q = "SELECT bill_no, bill_date, t_code,
                 COALESCE(NULLIF(customer_name,''),party_name) AS cust, route_name,
                 final_bill_amount AS amt
          FROM secondary_invoice_import_details
          WHERE status='imported' AND delivery_date='$date_esc' AND delivery_person='$dp_esc'
          ORDER BY bill_no";
    $r = mysqli_query($conn,$q);
    if ($r) while($x=mysqli_fetch_assoc($r)){
        $total += (float)$x['amt'];
        $addRow([
            '<span class="mono">'.h($x['bill_no']).'</span>',
            h($x['bill_date']),
            '<span class="mono">'.h($x['t_code']).'</span>',
            h($x['cust']),
            h($x['route_name']),
            '<span class="amt">'.money($x['amt']).'</span>',
        ]);
    }
    break;

/* ════════ CASH / CHEQUE PAID & CC DAILY SALE (joined via fsd+siid) ════════ */
case 'cash_paid':
case 'cheque_paid':
case 'cc_daily_sale':
    $cols = ['Invoice No','Pay Date','T-Code','Customer','Method','Source','Collected','Ref','Amount'];
    $method = ($type==='cheque_paid') ? 'cheque' : 'cash';
    $extra  = '';
    if ($type==='cc_daily_sale') {
        $extra = " AND LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
                   AND (ip.payment_source IS NULL OR ip.payment_source='' OR LOWER(ip.payment_source)='invoice')";
    } elseif ($type==='cash_paid' || $type==='cheque_paid') {
        $extra = " AND (ip.payment_source IS NULL OR ip.payment_source='' OR LOWER(ip.payment_source)='invoice')";
    }
    $q = "SELECT ip.invoice_num, ip.payment_date, ip.t_code, ip.amount,
                 ip.payment_method, ip.payment_source, ip.collected_by, ip.reference_no,
                 COALESCE(fsd.customer_name,'') AS cust
          FROM invoice_payments ip
          INNER JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
          INNER JOIN secondary_invoice_import_details siid
                 ON siid.bill_no = fsd.invoice_num
                AND siid.status = 'imported'
                AND siid.delivery_person IS NOT NULL AND siid.delivery_person != ''
          WHERE ip.payment_method='$method' AND ip.is_reversed=0
            AND siid.delivery_date='$date_esc' AND siid.delivery_person='$dp_esc'
            $extra
          ORDER BY ip.payment_date, ip.invoice_num";
    $r = mysqli_query($conn,$q);
    if ($r) while($x=mysqli_fetch_assoc($r)){
        $total += (float)$x['amount'];
        $addRow([
            '<span class="mono">'.h($x['invoice_num']).'</span>',
            h($x['payment_date']),
            '<span class="mono">'.h($x['t_code']).'</span>',
            h($x['cust']),
            '<span class="chip">'.h($x['payment_method']).'</span>',
            h($x['payment_source']!=='' ? $x['payment_source'] : 'invoice'),
            h(strtoupper($x['collected_by'])),
            h($x['reference_no']),
            '<span class="amt">'.money($x['amount']).'</span>',
        ]);
    }
    break;

/* ════════ CC RECEIVED (credit / rtn cheque / sent back) — by payment_date+dp ════════ */
case 'cc_rcvd_credit':
case 'cc_rcvd_rtn_chq':
case 'cc_rcvd_sent_back':
    $cols = ['Invoice No','Pay Date','T-Code','Source','Ref','Remarks','Amount'];
    $cond = $cc_src[$type];
    $q = "SELECT ip.invoice_num, ip.payment_date, ip.t_code, ip.payment_source,
                 ip.reference_no, ip.remarks, ip.amount
          FROM invoice_payments ip
          WHERE ip.payment_method='cash' AND ip.is_reversed=0
            AND LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
            AND $cond
            AND ip.payment_date='$date_esc' AND ip.delivery_person='$dp_esc'
          ORDER BY ip.payment_date, ip.invoice_num";
    $r = mysqli_query($conn,$q);
    if ($r) while($x=mysqli_fetch_assoc($r)){
        $total += (float)$x['amount'];
        $addRow([
            '<span class="mono">'.h($x['invoice_num']).'</span>',
            h($x['payment_date']),
            '<span class="mono">'.h($x['t_code']).'</span>',
            h($x['payment_source']),
            h($x['reference_no']),
            h($x['remarks']),
            '<span class="amt">'.money($x['amount']).'</span>',
        ]);
    }
    break;

/* ════════ CC RECEIVED RETURN CHARGES — from rc_settlement_payments ════════ */
case 'cc_rcvd_rtn_chgs':
    $cols = ['Pay Date','T-Code','Orig. Cheque','Method','Ref','Remarks','Amount'];
    $has  = false;
    $tc   = mysqli_query($conn,"SHOW TABLES LIKE 'rc_settlement_payments'");
    if ($tc && mysqli_num_rows($tc)>0) {
        $cc = mysqli_query($conn,"SHOW COLUMNS FROM rc_settlement_payments LIKE 'delivery_person'");
        if ($cc && mysqli_num_rows($cc)>0) $has = true;
    }
    if ($has) {
        $q = "SELECT p.payment_date, p.amount, p.payment_method, p.reference_no, p.remarks,
                     COALESCE(rc.t_code,'')    AS t_code,
                     COALESCE(rc.cheque_no,'') AS orig_cheque
              FROM rc_settlement_payments p
              LEFT JOIN cheque_return_charges rc ON rc.id = p.rc_id
              WHERE p.payment_method='cash'
                AND p.payment_date='$date_esc' AND p.delivery_person='$dp_esc'
              ORDER BY p.payment_date, p.id";
        $r = mysqli_query($conn,$q);
        if ($r) while($x=mysqli_fetch_assoc($r)){
            $total += (float)$x['amount'];
            $addRow([
                h($x['payment_date']),
                '<span class="mono">'.h($x['t_code']).'</span>',
                '<span class="mono">'.h($x['orig_cheque']).'</span>',
                '<span class="chip">'.h($x['payment_method']).'</span>',
                h($x['reference_no']),
                h($x['remarks']),
                '<span class="amt">'.money($x['amount']).'</span>',
            ]);
        }
    } else {
        $note = 'The return charges settlement table is not available yet.';
    }
    break;

/* ════════ CREDIT (unpaid balance per invoice) ════════
   Mirrors the Balance formula on the Field Summary edit page exactly:
       row_bal = max(0, adjust_net_value - row_paid)
   where row_paid there = SUM(amount) FROM invoice_payments
         WHERE field_summary_detail_id = <row>
           AND payment_date = <that row's own field_summary delivery_date>
   (no payment_method filter, no is_reversed filter). ════════ */
case 'credit':
    $cols = ['Invoice No','T-Code','Customer','Invoice Value','Paid','Balance'];
    $q = "SELECT fsd.id, fsd.invoice_num, fsd.t_code,
                 COALESCE(fsd.customer_name,'') AS cust,
                 fsd.adjust_net_value AS adj,
                 (SELECT COALESCE(SUM(amount),0)
                    FROM invoice_payments
                    WHERE field_summary_detail_id = fsd.id
                      AND payment_date = fs.delivery_date) AS paid
          FROM field_summary_details fsd
          INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
          INNER JOIN secondary_invoice_import_details siid
                 ON siid.bill_no = fsd.invoice_num
                AND siid.status = 'imported'
                AND siid.delivery_person IS NOT NULL AND siid.delivery_person != ''
          WHERE siid.delivery_date='$date_esc' AND siid.delivery_person='$dp_esc'
          ORDER BY fsd.invoice_num";
    $r = mysqli_query($conn,$q);
    if ($r) while($x=mysqli_fetch_assoc($r)){
        $bal = max(0.0, (float)$x['adj'] - (float)$x['paid']);
        if ($bal <= 0.004) continue;
        $total += $bal;
        $addRow([
            '<span class="mono">'.h($x['invoice_num']).'</span>',
            '<span class="mono">'.h($x['t_code']).'</span>',
            h($x['cust']),
            '<span class="amt" style="color:#1e40af;">'.money($x['adj']).'</span>',
            '<span class="amt" style="color:#166534;">'.money($x['paid']).'</span>',
            '<span class="amt" style="color:#dc2626;">'.money($bal).'</span>',
        ]);
    }
    break;

/* ════════ DEPOSITS — banked / handed-to-BO / both ════════ */
case 'banked_cc':
case 'handed_cc':
case 'banked':
    $hand = '';
    if ($type==='banked_cc') $hand = " AND d.handed_over_bo=0";
    if ($type==='handed_cc') $hand = " AND d.handed_over_bo=1";
    if ($type==='handed_cc') {
        $cols = ['Deposit ID','Handed Date','Cash Recv Date','Handler (BO)','Remark','Amount'];
        $note = 'Cash handed over to the Back Office (BO). These are the per–delivery-person amounts recorded as handed to BO.';
    } elseif ($type==='banked_cc') {
        $cols = ['Deposit ID','Deposit Date','Cash Recv Date','Bank Account','Handler','Remark','Amount'];
    } else {
        $cols = ['Deposit ID','Date','Cash Recv','Type','Bank / Handler','Remark','Amount'];
    }
    $q = "SELECT p.amount, p.deposit_id, d.deposit_date, d.cash_receive_date, d.handed_over_bo,
                 COALESCE(NULLIF(CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
                        COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,'')),' / '),'') AS bank_label,
                 COALESCE(NULLIF(e.name_with_initials,''),NULLIF(e.employee_full_name,''),CONCAT('EMP-',e.id),'') AS handler,
                 COALESCE(d.remark,'') AS remark
          FROM cc_cash_deposit_dp_persons p
          INNER JOIN cc_cash_deposit_dp d ON d.id = p.deposit_id
          LEFT JOIN company_bank_accounts cba ON cba.id = d.bank_account_id
          LEFT JOIN banks b ON b.bank_code = cba.bank_code
          LEFT JOIN bank_branches bb ON bb.bank_code = cba.bank_code AND bb.branch_code = cba.branch_code
          LEFT JOIN employees e ON e.id = d.employee_id
          WHERE p.delivery_date='$date_esc' AND p.delivery_person='$dp_esc'
            AND LOWER(COALESCE(d.collected_by,''))='cc'
            $hand
          ORDER BY d.deposit_date, p.deposit_id";
    $r = mysqli_query($conn,$q);

    /* Fallback: if the enriched query fails (a banks/branches/employees table or
       column is unavailable in this DB), still load the core deposit rows so the
       BO / deposit details by DP always render. */
    if (!$r) {
        $cols = ['Deposit ID','Deposit Date','Cash Recv Date','Type','Remark','Amount'];
        $qf = "SELECT p.amount, p.deposit_id, d.deposit_date, d.cash_receive_date, d.handed_over_bo,
                      COALESCE(d.remark,'') AS remark
               FROM cc_cash_deposit_dp_persons p
               INNER JOIN cc_cash_deposit_dp d ON d.id = p.deposit_id
               WHERE p.delivery_date='$date_esc' AND p.delivery_person='$dp_esc'
                 AND LOWER(COALESCE(d.collected_by,''))='cc'
                 $hand
               ORDER BY d.deposit_date, p.deposit_id";
        $rf = mysqli_query($conn,$qf);
        if ($rf) while($x=mysqli_fetch_assoc($rf)){
            $total += (float)$x['amount'];
            $isBO = ((int)$x['handed_over_bo']===1);
            $addRow([
                '<span class="mono">#'.h($x['deposit_id']).'</span>',
                h($x['deposit_date']!==''?$x['deposit_date']:'—'),
                h($x['cash_receive_date']!==''?$x['cash_receive_date']:'—'),
                '<span class="chip" style="background:'.($isBO?'#ede9fe;color:#5b21b6':'#dbeafe;color:#1e40af').';">'.($isBO?'Handed BO':'Banked').'</span>',
                h($x['remark']!==''?$x['remark']:'—'),
                '<span class="amt">'.money($x['amount']).'</span>',
            ]);
        }
        break;
    }
    if ($r) while($x=mysqli_fetch_assoc($r)){
        $total += (float)$x['amount'];
        $isBO    = ((int)$x['handed_over_bo']===1);
        $dep_id  = '<span class="mono">#'.h($x['deposit_id']).'</span>';
        $dt1     = h($x['deposit_date']!=='' ? $x['deposit_date'] : '—');
        $dt2     = h($x['cash_receive_date']!=='' ? $x['cash_receive_date'] : '—');
        $handler = h($x['handler']!=='' ? $x['handler'] : '—');
        $bank    = h($x['bank_label']!=='' ? $x['bank_label'] : '—');
        $rem     = h($x['remark']!=='' ? $x['remark'] : '—');
        $amt     = '<span class="amt">'.money($x['amount']).'</span>';

        if ($type==='handed_cc') {
            $addRow([$dep_id, $dt1, $dt2, $handler, $rem, $amt]);
        } elseif ($type==='banked_cc') {
            $addRow([$dep_id, $dt1, $dt2, $bank, $handler, $rem, $amt]);
        } else {
            $chip = '<span class="chip" style="background:'.($isBO?'#ede9fe;color:#5b21b6':'#dbeafe;color:#1e40af').';">'.($isBO?'Handed BO':'Banked').'</span>';
            $bh   = $isBO ? ($handler!=='—'?$handler:'BO') : $bank;
            $addRow([$dep_id, $dt1, $dt2, $chip, h($bh), $rem, $amt]);
        }
    }
    break;

/* ════════ PAY ALLOCATION — charge / absorb ════════ */
case 'p_charge':
case 'p_absorb':
    $entry = ($type==='p_charge') ? 'charge' : 'absorb';
    $cols  = ($entry==='charge')
           ? ['Employee','Amount','Recorded']
           : ['Entry','Amount','Recorded'];
    $q = "SELECT entry_type, employee_id, employee_name, amount, created_at
          FROM cash_summary_pay_allocations_dp
          WHERE pay_date='$date_esc' AND delivery_person='$dp_esc' AND entry_type='$entry'
          ORDER BY id";
    $r = mysqli_query($conn,$q);
    if ($r) while($x=mysqli_fetch_assoc($r)){
        $total += (float)$x['amount'];
        $label = ($entry==='charge')
               ? (h($x['employee_name']) . ($x['employee_id']?' <span class="mono">(#'.h($x['employee_id']).')</span>':''))
               : 'Company Absorption';
        $addRow([
            $label,
            '<span class="amt">'.money($x['amount']).'</span>',
            h($x['created_at']),
        ]);
    }
    break;

/* ════════ COMPOSITE: CC TOTAL / GRAND TOTAL ════════ */
case 'cc_total':
case 'total_coll':
    $note = 'This total is the sum of the CC collection components below. Click any component to see its records.';
    $comp_types = ['cc_daily_sale','cc_rcvd_credit','cc_rcvd_rtn_chq','cc_rcvd_rtn_chgs','cc_rcvd_sent_back'];
    foreach ($comp_types as $ct) {
        $amt = dp_detail_component_amount($conn, $ct, $dp_esc, $date_esc, $cc_src);
        $components[] = [$TYPES[$ct][0], $amt, $ct];
        $total += $amt;
    }
    break;

/* ════════ COMPOSITE: VARIANCE / SHORT / FINAL ════════ */
case 'variance_cc':
case 'short_cc':
case 'new_short_excess':
    $ccTot  = 0.0;
    foreach (['cc_daily_sale','cc_rcvd_credit','cc_rcvd_rtn_chq','cc_rcvd_rtn_chgs','cc_rcvd_sent_back'] as $ct)
        $ccTot += dp_detail_component_amount($conn, $ct, $dp_esc, $date_esc, $cc_src);
    $bankTot = dp_detail_component_amount($conn, 'banked', $dp_esc, $date_esc, $cc_src);
    $diff    = $ccTot - $bankTot;
    $components[] = ['CC Collection — Total', $ccTot,  'cc_total'];
    $components[] = ['Total Deposited (CC)',  $bankTot,'banked'];
    if ($type==='short_cc') {
        $total = $diff > 0 ? $diff : 0;
        $note  = 'Short = CC Total − Deposited, when positive. ' . ($diff<=0 ? 'No shortage for this day.' : '');
    } else {
        $total = $diff;
        $note  = 'Variance = CC Total − Deposited. Positive = short, negative = excess.';
    }
    break;

/* ════════ PAY DIFFERENCE ════════ */
case 'pay_diff':
    $sinv = dp_detail_component_amount($conn, 'sinv', $dp_esc, $date_esc, $cc_src);
    $cash = dp_detail_component_amount($conn, 'cash_paid', $dp_esc, $date_esc, $cc_src);
    $chq  = dp_detail_component_amount($conn, 'cheque_paid', $dp_esc, $date_esc, $cc_src);
    $cr   = dp_detail_component_amount($conn, 'credit', $dp_esc, $date_esc, $cc_src);
    $components[] = ['Secondary Invoice Value', $sinv, 'sinv'];
    $components[] = ['Cash Paid',   $cash, 'cash_paid'];
    $components[] = ['Cheque Paid', $chq,  'cheque_paid'];
    $components[] = ['Credit',      $cr,   'credit'];
    $total = $sinv - ($cash + $chq + $cr);
    $note  = 'Difference = Secondary Invoice − (Cash + Cheque + Credit).';
    break;

default:
    $note = 'Unknown amount type.';
}
}

/* ── component amount helper (reuses the page queries, sum only) ── */
function dp_detail_component_amount($conn, $type, $dp_esc, $date_esc, $cc_src){
    if ($dp_esc==='' || $date_esc==='') return 0.0;
    switch ($type) {
        case 'sinv':
            $q="SELECT COALESCE(SUM(final_bill_amount),0) s FROM secondary_invoice_import_details
                WHERE status='imported' AND delivery_date='$date_esc' AND delivery_person='$dp_esc'";
            break;
        case 'cash_paid':
        case 'cheque_paid':
        case 'cc_daily_sale':
            $m = ($type==='cheque_paid')?'cheque':'cash';
            $extra = ($type==='cc_daily_sale')
                ? " AND LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc'
                    AND (ip.payment_source IS NULL OR ip.payment_source='' OR LOWER(ip.payment_source)='invoice')" : '';
            $q="SELECT COALESCE(SUM(ip.amount),0) s
                FROM invoice_payments ip
                INNER JOIN field_summary_details fsd ON fsd.id=ip.field_summary_detail_id
                INNER JOIN secondary_invoice_import_details siid
                       ON siid.bill_no=fsd.invoice_num AND siid.status='imported'
                      AND siid.delivery_person IS NOT NULL AND siid.delivery_person!=''
                WHERE ip.payment_method='$m' AND ip.is_reversed=0
                  AND siid.delivery_date='$date_esc' AND siid.delivery_person='$dp_esc' $extra";
            break;
        case 'cc_rcvd_credit':
        case 'cc_rcvd_rtn_chq':
        case 'cc_rcvd_sent_back':
            $cond=$cc_src[$type];
            $q="SELECT COALESCE(SUM(ip.amount),0) s FROM invoice_payments ip
                WHERE ip.payment_method='cash' AND ip.is_reversed=0
                  AND LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc' AND $cond
                  AND ip.payment_date='$date_esc' AND ip.delivery_person='$dp_esc'";
            break;
        case 'cc_rcvd_rtn_chgs':
            $tc=mysqli_query($conn,"SHOW TABLES LIKE 'rc_settlement_payments'");
            if(!$tc||mysqli_num_rows($tc)===0) return 0.0;
            $cc=mysqli_query($conn,"SHOW COLUMNS FROM rc_settlement_payments LIKE 'delivery_person'");
            if(!$cc||mysqli_num_rows($cc)===0) return 0.0;
            $q="SELECT COALESCE(SUM(amount),0) s FROM rc_settlement_payments
                WHERE payment_method='cash' AND payment_date='$date_esc' AND delivery_person='$dp_esc'";
            break;
        /* Credit — matches the Field Summary edit page's Balance formula:
           row_bal = max(0, adjust_net_value - row_paid), where row_paid is
           SUM(amount) FROM invoice_payments for that detail, matched to
           that row's own field_summary delivery_date (no payment_method
           filter, no is_reversed filter — the edit page applies neither). */
        case 'credit':
            $q="SELECT COALESCE(SUM(GREATEST(0, fsd.adjust_net_value -
                  (SELECT COALESCE(SUM(amount),0)
                     FROM invoice_payments
                     WHERE field_summary_detail_id = fsd.id
                       AND payment_date = fs.delivery_date))),0) s
                FROM field_summary_details fsd
                INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
                INNER JOIN secondary_invoice_import_details siid
                       ON siid.bill_no=fsd.invoice_num AND siid.status='imported'
                      AND siid.delivery_person IS NOT NULL AND siid.delivery_person!=''
                WHERE siid.delivery_date='$date_esc' AND siid.delivery_person='$dp_esc'";
            break;
        case 'banked_cc':
        case 'handed_cc':
        case 'banked':
            $hand = $type==='banked_cc' ? " AND d.handed_over_bo=0" : ($type==='handed_cc' ? " AND d.handed_over_bo=1" : '');
            $q="SELECT COALESCE(SUM(p.amount),0) s
                FROM cc_cash_deposit_dp_persons p
                INNER JOIN cc_cash_deposit_dp d ON d.id=p.deposit_id
                WHERE p.delivery_date='$date_esc' AND p.delivery_person='$dp_esc'
                  AND LOWER(COALESCE(d.collected_by,''))='cc' $hand";
            break;
        default: return 0.0;
    }
    $r=mysqli_query($conn,$q);
    return $r ? (float)mysqli_fetch_assoc($r)['s'] : 0.0;
}

$back_url = 'cash_collection_dp.php?search=1&date_from='.urlencode($date_ok).'&date_to='.urlencode($date_ok).'&delivery_person='.urlencode($dp);
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');
:root{--fn:'Inter',sans-serif;--mn:'JetBrains Mono',monospace;}
.dd-pg{font-family:var(--fn);padding:14px 14px 50px;background:#eef0f3;color:#1a1f2e;font-size:12.5px;}
.dd-top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px;}
.dd-back{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;background:#fff;border:1px solid #d2d6db;border-radius:7px;font-size:12px;font-weight:600;color:#475569;text-decoration:none;}
.dd-back:hover{background:#f1f5f9;}
.dd-card{background:#fff;border:1px solid #d2d6db;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.07),0 4px 14px rgba(0,0,0,.05);overflow:hidden;}
.dd-head{padding:14px 18px;border-bottom:1px solid #e4e7ec;color:#fff;}
.dd-head h1{font-size:17px;font-weight:800;letter-spacing:-.01em;display:flex;align-items:center;gap:9px;}
.dd-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:9px;}
.dd-pill{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);border-radius:18px;padding:3px 11px;font-size:11px;font-weight:700;color:#fff;}
.dd-pill .mono{font-family:var(--mn);}
.dd-note{padding:10px 18px;background:#f8fafc;border-bottom:1px solid #e4e7ec;font-size:11.5px;color:#475569;}
.dd-body{overflow-x:auto;}
table.dd-t{width:100%;border-collapse:collapse;font-size:12px;}
.dd-t thead th{background:#f1f5f9;color:#475569;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;padding:8px 12px;text-align:left;white-space:nowrap;border-bottom:2px solid #e2e8f0;}
.dd-t thead th:last-child{text-align:right;}
.dd-t tbody td{padding:7px 12px;border-bottom:1px solid #f1f5f9;vertical-align:top;}
.dd-t tbody td:last-child{text-align:right;}
.dd-t tbody tr:nth-child(even) td{background:#fafbfc;}
.dd-t tbody tr:hover td{background:#eff6ff;}
.dd-t .mono{font-family:var(--mn);font-size:11px;}
.dd-t .amt{font-family:var(--mn);font-weight:700;color:#166534;white-space:nowrap;}
.dd-t .chip{display:inline-block;background:#e2e8f0;color:#334155;border-radius:5px;padding:1px 7px;font-size:10px;font-weight:700;text-transform:capitalize;}
.dd-t tfoot td{padding:9px 12px;background:#0f172a;color:#e2e8f0;font-weight:800;font-family:var(--mn);border-top:2px solid #334155;}
.dd-t tfoot td:last-child{text-align:right;font-size:14px;}
.dd-comp{width:100%;border-collapse:collapse;font-size:12.5px;}
.dd-comp td{padding:10px 18px;border-bottom:1px solid #f1f5f9;}
.dd-comp td:last-child{text-align:right;font-family:var(--mn);font-weight:700;}
.dd-comp a{color:#1d4ed8;text-decoration:none;font-weight:600;}
.dd-comp a:hover{text-decoration:underline;}
.dd-comp tr:hover td{background:#f8fafc;}
.dd-comp tfoot td{background:#0f172a;color:#e2e8f0;font-weight:800;font-size:14px;border-top:2px solid #334155;}
.dd-empty{padding:40px 20px;text-align:center;color:#9aa3ad;}
.dd-empty i{font-size:34px;display:block;margin-bottom:10px;opacity:.3;}
</style>

<div class="dd-pg">
  <div class="dd-top">
    <a class="dd-back" href="<?php echo h($back_url);?>"><i class="fa-solid fa-arrow-left"></i> Back to Cash Collection</a>
    <div style="font-size:11px;color:#9aa3ad;">Drill-down · how this amount is made up</div>
  </div>

  <div class="dd-card">
    <div class="dd-head" style="background:<?php echo h($accent);?>;">
      <h1><i class="fa-solid <?php echo h($meta[2]);?>"></i> <?php echo h($title);?></h1>
      <div class="dd-meta">
        <span class="dd-pill"><i class="fa-solid fa-truck"></i> <?php echo h($dp!==''?$dp:'—');?></span>
        <span class="dd-pill"><i class="fa-solid fa-calendar-day"></i> <span class="mono"><?php echo h($date_ok!==''?date('d M Y',strtotime($date_ok)):'—');?></span></span>
        <span class="dd-pill"><i class="fa-solid fa-coins"></i> Total: Rs. <?php echo money($total);?></span>
      </div>
    </div>

    <?php if($note!==''): ?><div class="dd-note"><i class="fa-solid fa-circle-info"></i> <?php echo h($note);?></div><?php endif; ?>

    <?php if(!$ready): ?>
      <div class="dd-empty"><i class="fa-solid fa-triangle-exclamation"></i>Missing delivery person or date.</div>

    <?php elseif(!empty($components)): /* composite breakdown view */ ?>
      <table class="dd-comp">
        <tbody>
        <?php foreach($components as $c): [$lbl,$amt,$ct]=$c;
            $lnk='cash_collection_dp_detail.php?type='.urlencode($ct).'&delivery_person='.urlencode($dp).'&date='.urlencode($date_ok); ?>
          <tr>
            <td><a href="<?php echo h($lnk);?>"><i class="fa-solid fa-arrow-up-right-from-square" style="font-size:10px;opacity:.6;"></i> <?php echo h($lbl);?></a></td>
            <td style="color:<?php echo $amt<0?'#16a34a':'#0f172a';?>;"><?php echo money($amt);?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>TOTAL</td><td>Rs. <?php echo money($total);?></td></tr></tfoot>
      </table>

    <?php elseif(empty($data)): ?>
      <div class="dd-empty"><i class="fa-solid fa-inbox"></i>No records found for this amount.</div>

    <?php else: /* detail rows view */ ?>
      <div class="dd-body">
        <table class="dd-t">
          <thead><tr><?php foreach($cols as $c) echo '<th>'.h($c).'</th>'; ?></tr></thead>
          <tbody>
            <?php foreach($data as $row): ?>
              <tr><?php foreach($row as $cell) echo '<td>'.$cell.'</td>'; ?></tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="<?php echo max(1,count($cols)-1);?>">TOTAL — <?php echo count($data);?> record(s)</td>
              <td>Rs. <?php echo money($total);?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include 'footer.php'; ?>