<?php
include 'config.php';

$import_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$import_id) {
    header('Location: import_loading_summary.php');
    exit;
}

// Get import details
$import_query  = "SELECT * FROM loading_summary_imports WHERE id = $import_id";
$import_result = mysqli_query($conn, $import_query);

if (!$import_result || mysqli_num_rows($import_result) === 0) {
    header('Location: import_loading_summary.php');
    exit;
}

$import            = mysqli_fetch_assoc($import_result);
$delivery_date     = date('d/m/Y', strtotime($import['delivery_date']));
$delivery_date_sql = date('Y-m-d',  strtotime($import['delivery_date']));

// Get all detail records
// NOTE: joined to customers table (via t_code) to pull payment_mode + credit_days
// for the new "PD" (Payment mode/Days) column: CS = cash, CR{n} = credit w/ credit days, CH{n} = cheque w/ credit days
$details_query = "
    SELECT d.*, d.sales_person_code AS sr_full_name,
           c.payment_mode AS cust_payment_mode,
           c.credit_days  AS cust_credit_days
    FROM loading_summary_import_details d
    LEFT JOIN customers c ON c.t_code = d.t_code
    WHERE d.import_id = $import_id
    ORDER BY d.delivery_person, d.sales_person_code, d.id
";
$details_result = mysqli_query($conn, $details_query);

// ─────────────────────────────────────────────────────────────
// GROUP BY DELIVERY PERSON (CC) — each CC gets one page set
// Within each CC group, rows are further sub-grouped by SR code
// ─────────────────────────────────────────────────────────────
$cc_groups = [];   // cc_person => [ ...rows ]
while ($row = mysqli_fetch_assoc($details_result)) {
    $cc   = trim($row['delivery_person'] ?? '') ?: 'UNKNOWN';
    $cc_groups[$cc][] = $row;
}

// ─────────────────────────────────────────────────────────────
// HELPER — extract the trailing number from invoice codes like
// "26SEP_UK62_12600059" -> "12600059" (last underscore-separated part)
// ─────────────────────────────────────────────────────────────
function format_invoice_no($bill_no) {
    $bill_no = trim((string)($bill_no ?? ''));
    if ($bill_no === '') return '';
    if (strpos($bill_no, '_') === false) return $bill_no;
    $parts = explode('_', $bill_no);
    return end($parts);
}

// ─────────────────────────────────────────────────────────────
// HELPER — build the "PD" (Payment mode/Days) badge text
// Cash     -> CS
// Credit   -> CR{credit_days}   e.g. CR10
// Cheque   -> CH{credit_days}   e.g. CH10
// ─────────────────────────────────────────────────────────────
function build_pd_display($payment_mode, $credit_days) {
    $mode = strtolower(trim($payment_mode ?? ''));
    $days = trim((string)($credit_days ?? ''));

    if ($mode === 'cash') {
        return 'CS';
    } elseif ($mode === 'credit') {
        return 'CR' . $days;
    } elseif ($mode === 'cheque') {
        return 'CH' . $days;
    }
    return '';
}

// ─────────────────────────────────────────────────────────────
// PRE-LOAD CREDIT BILL DATA FOR THIS DATE  (keyed by CC person)
// ─────────────────────────────────────────────────────────────
$credit_bills_by_cc = [];
$esc_date = mysqli_real_escape_string($conn, $delivery_date_sql);

$cb_sql = "
    SELECT
        bi.person_type,
        bi.person_code,
        fsd.invoice_num,
        fsd.t_code,
        fs.sr_code,
        fs.delivery_date AS bill_date,
        fsd.customer_name,
        COALESCE(siid.final_bill_amount, fsd.adjust_net_value) AS net_value,
        (
            COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
            - COALESCE(pay.total_paid, 0)
            - COALESCE(cn.total_cn,   0)
        ) AS balance
    FROM credit_bill_issues bi
    JOIN credit_bill_issue_items item ON item.issue_id = bi.id
    JOIN field_summary_details fsd    ON fsd.id = item.detail_id
    JOIN field_summary fs              ON fs.id  = fsd.field_summary_id
    LEFT JOIN (
        SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
        FROM secondary_invoice_import_details
        GROUP BY bill_no
    ) siid ON siid.bill_no = fsd.invoice_num
    LEFT JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_paid
        FROM invoice_payments
        WHERE is_reversed = 0
        GROUP BY field_summary_detail_id
    ) pay ON pay.field_summary_detail_id = fsd.id
    LEFT JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_cn
        FROM credit_notes
        WHERE is_deleted = 0
        GROUP BY field_summary_detail_id
    ) cn ON cn.field_summary_detail_id = fsd.id
    WHERE bi.issue_date = '$esc_date'
      AND bi.person_type = 'CC'
      AND item.status   = 'issued'
    ORDER BY bi.person_code, fsd.invoice_num ASC
";

$cb_result = mysqli_query($conn, $cb_sql);
if ($cb_result) {
    while ($cb = mysqli_fetch_assoc($cb_result)) {
        $key = $cb['person_code'] ?: 'UNKNOWN';
        $credit_bills_by_cc[$key][] = [
            'invoice_num'   => $cb['invoice_num']   ?? '',
            't_code'        => $cb['t_code']         ?? '',
            'sr_code'       => $cb['sr_code']        ?? '',
            'customer_name' => $cb['customer_name']  ?? '',
            'balance'       => $cb['balance']        ?? 0,
            'bill_date'     => $cb['bill_date']      ? date('d/m/Y', strtotime($cb['bill_date'])) : '',
            'person_type'   => $cb['person_type']    ?? '',
            'person_code'   => $cb['person_code']    ?? '',
        ];
    }
}

// ─────────────────────────────────────────────────────────────
// PRE-LOAD RETURN CHEQUES  (RT)  keyed by CC person_code
// ─────────────────────────────────────────────────────────────
$return_cheques_by_cc = [];

$rc_sql = "
    SELECT
        ci.person_code,
        ci.person_type,
        ci.issue_date,
        ch.cheque_no,
        ch.t_code,
        ch.total_amount,
        ch.bank_code,
        ch.bank_name,
        ch.cheque_date,
        fs.sr_code,
        COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) AS customer_name,
        COALESCE(ch.settlement_amount, 0) AS settlement_amount,
        (ch.total_amount - COALESCE(ch.settlement_amount, 0)) AS balance
    FROM cheque_issues ci
    JOIN cheque_issue_items ii  ON ii.issue_id = ci.id
    JOIN cheques ch             ON ch.id = ii.cheque_id
    JOIN invoice_payments ip    ON ip.id = ch.invoice_payment_id
    JOIN field_summary fs       ON fs.id = ip.field_summary_id
    LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
    LEFT JOIN customers c       ON c.t_code = ch.t_code
    WHERE ci.issue_date = '$esc_date'
      AND ci.person_type = 'CC'
      AND ii.status = 'issued'
    ORDER BY ci.person_code, ch.cheque_no ASC
";

$rc_result = mysqli_query($conn, $rc_sql);
if ($rc_result) {
    while ($rc = mysqli_fetch_assoc($rc_result)) {
        $key = $rc['person_code'] ?: 'UNKNOWN';
        $return_cheques_by_cc[$key][] = [
            'cheque_type'   => 'RT',
            'sr_code'       => $rc['sr_code']       ?? '',
            'cheque_no'     => $rc['cheque_no']      ?? '',
            't_code'        => $rc['t_code']         ?? '',
            'customer_name' => $rc['customer_name']  ?? '',
            'balance'       => $rc['balance']        ?? 0,
            'bank_code'     => $rc['bank_code']      ?? '',
            'cheque_date'   => $rc['cheque_date']    ?? '',
        ];
    }
}

// ─────────────────────────────────────────────────────────────
// PRE-LOAD SENT BACK CHEQUES (SB)  keyed by CC person_code
// ─────────────────────────────────────────────────────────────
$sentback_cheques_by_cc = [];

$sb_sql = "
    SELECT
        si.person_code,
        si.person_type,
        si.issue_date,
        ch.cheque_no,
        ch.t_code,
        ch.total_amount,
        ch.bank_code,
        ch.bank_name,
        ch.cheque_date,
        fs.sr_code,
        COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) AS customer_name,
        COALESCE(ch.sb_settlement_amount, 0) AS settlement_amount,
        (ch.total_amount - COALESCE(ch.sb_settlement_amount, 0)) AS balance
    FROM sentback_issues si
    JOIN sentback_issue_items sii ON sii.issue_id = si.id
    JOIN cheques ch               ON ch.id = sii.cheque_id
    JOIN invoice_payments ip      ON ip.id = ch.invoice_payment_id
    JOIN field_summary fs         ON fs.id = ip.field_summary_id
    LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
    LEFT JOIN customers c         ON c.t_code = ch.t_code
    WHERE si.issue_date = '$esc_date'
      AND si.person_type = 'CC'
      AND sii.status = 'issued'
    ORDER BY si.person_code, ch.cheque_no ASC
";

$sb_result = mysqli_query($conn, $sb_sql);
if ($sb_result) {
    while ($sb = mysqli_fetch_assoc($sb_result)) {
        $key = $sb['person_code'] ?: 'UNKNOWN';
        $sentback_cheques_by_cc[$key][] = [
            'cheque_type'   => 'SB',
            'sr_code'       => $sb['sr_code']       ?? '',
            'cheque_no'     => $sb['cheque_no']      ?? '',
            't_code'        => $sb['t_code']         ?? '',
            'customer_name' => $sb['customer_name']  ?? '',
            'balance'       => $sb['balance']        ?? 0,
            'bank_code'     => $sb['bank_code']      ?? '',
            'cheque_date'   => $sb['cheque_date']    ?? '',
        ];
    }
}

// MERGE: RT first, then SB
$combined_cheques_by_cc = [];
$all_cc_keys = array_unique(array_merge(
    array_keys($return_cheques_by_cc),
    array_keys($sentback_cheques_by_cc)
));
foreach ($all_cc_keys as $k) {
    $rt = $return_cheques_by_cc[$k]  ?? [];
    $sb = $sentback_cheques_by_cc[$k] ?? [];
    $combined_cheques_by_cc[$k] = array_merge($rt, $sb);
}

// ─────────────────────────────────────────────────────────────
// PRE-LOAD OUTSTANDING CREDIT BALANCE PER CUSTOMER (t_code)
// This is the same balance logic used in credit_bill_summary2.php:
// balance = COALESCE(final_bill_amount, adjust_net_value) - paid - credit_notes
// Only invoices with a positive outstanding balance are counted.
// Used to (a) star the customer name and (b) show the Rs. amount
// in the new "Credit Bal" column on the loading-summary print.
// ─────────────────────────────────────────────────────────────
$credit_info_by_tcode = [];

$credit_bal_sql = "
    SELECT
        fsd.t_code,
        SUM(
            COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
            - COALESCE(pay.total_paid, 0)
            - COALESCE(cn.total_cn,   0)
        ) AS total_balance,
        COUNT(*) AS inv_count
    FROM field_summary_details fsd
    INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
    LEFT JOIN (
        SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
        FROM secondary_invoice_import_details
        GROUP BY bill_no
    ) siid ON siid.bill_no = fsd.invoice_num
    LEFT JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_paid
        FROM invoice_payments
        WHERE is_reversed = 0
        GROUP BY field_summary_detail_id
    ) pay ON pay.field_summary_detail_id = fsd.id
    LEFT JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_cn
        FROM credit_notes
        WHERE is_deleted = 0
        GROUP BY field_summary_detail_id
    ) cn ON cn.field_summary_detail_id = fsd.id
    WHERE fsd.updated = 1
    GROUP BY fsd.t_code
    HAVING total_balance > 0
";

$credit_bal_result = mysqli_query($conn, $credit_bal_sql);
if ($credit_bal_result) {
    while ($cbal = mysqli_fetch_assoc($credit_bal_result)) {
        $credit_info_by_tcode[$cbal['t_code']] = [
            'total_balance' => floatval($cbal['total_balance']),
            'inv_count'     => intval($cbal['inv_count']),
        ];
    }
}

// ─────────────────────────────────────────────────────────────
// PRE-LOAD RISK BILL REASONS (from risk_customer_bills_report.php)
// If a reason was picked for a bill on the Risk Customer Bills report
// (risk_customer_bill_remarks.reason_id, same delivery date + bill no),
// its SHORT CODE (field_summary_risk_bill_reasons.short_code) is printed
// in the Cancel column as plain bold text.
// Read with two simple queries (no SQL join) so tables with different
// collations are never compared.
// ─────────────────────────────────────────────────────────────
$risk_code_by_bill = [];   // trimmed bill_no => short_code

$rsn_codes = [];
$rsn_res = @mysqli_query($conn, "SHOW TABLES LIKE 'field_summary_risk_bill_reasons'");
if ($rsn_res && mysqli_num_rows($rsn_res) > 0) {
    $rsn_q = mysqli_query($conn, "SELECT id, short_code FROM field_summary_risk_bill_reasons");
    if ($rsn_q) while ($x = mysqli_fetch_assoc($rsn_q)) $rsn_codes[(int)$x['id']] = $x['short_code'];
}
$rcbr_res = @mysqli_query($conn, "SHOW TABLES LIKE 'risk_customer_bill_remarks'");
$rcbr_col = ($rcbr_res && mysqli_num_rows($rcbr_res) > 0) ? @mysqli_query($conn, "SHOW COLUMNS FROM risk_customer_bill_remarks LIKE 'reason_id'") : false;
if ($rsn_codes && $rcbr_col && mysqli_num_rows($rcbr_col) > 0) {
    $rcbr_q = mysqli_query($conn, "SELECT bill_no, reason_id FROM risk_customer_bill_remarks
                                   WHERE delivery_date = '$esc_date' AND reason_id IS NOT NULL");
    if ($rcbr_q) {
        while ($x = mysqli_fetch_assoc($rcbr_q)) {
            $rid = (int)$x['reason_id'];
            if (isset($rsn_codes[$rid])) $risk_code_by_bill[trim((string)$x['bill_no'])] = $rsn_codes[$rid];
        }
    }
}

// ─────────────────────────────────────────────────────────────
// DEBUG — add ?debug=1 to URL
// ─────────────────────────────────────────────────────────────
if (isset($_GET['debug'])) {
    echo '<div style="font-family:monospace;font-size:12px;padding:20px;background:#f9fafb;">';
    echo '<h2>DEBUG — Import #' . $import_id . ' | Date: ' . $delivery_date_sql . '</h2>';
    echo '<h3>Import row:</h3><pre>' . print_r($import, true) . '</pre>';
    echo '<hr><h3>CC Groups (' . count($cc_groups) . '):</h3>';
    foreach ($cc_groups as $k => $v) { echo '<b>' . htmlspecialchars($k) . '</b>: ' . count($v) . ' rows<br>'; }
    echo '<hr><h3>Credit Bills by CC:</h3><pre>' . print_r($credit_bills_by_cc, true) . '</pre>';
    echo '<hr><h3>Return Cheques (RT) by CC:</h3><pre>' . print_r($return_cheques_by_cc, true) . '</pre>';
    echo '<hr><h3>Sent Back Cheques (SB) by CC:</h3><pre>' . print_r($sentback_cheques_by_cc, true) . '</pre>';
    echo '<hr><h3>Combined Cheques by CC:</h3><pre>' . print_r($combined_cheques_by_cc, true) . '</pre>';
    echo '<hr><h3>Credit Balance by T Code:</h3><pre>' . print_r($credit_info_by_tcode, true) . '</pre>';
    echo '<hr><h3>Risk Bill Reason codes by Bill No:</h3><pre>' . print_r($risk_code_by_bill, true) . '</pre>';
    echo '</div>';
    exit;
}

// Number of empty rows printed in the Credit Bill Collections table (page 2)
$CREDIT_BILL_ROWS = 20;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Yelo Logistics – Print | Import #<?php echo $import_id; ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:Arial,sans-serif; font-size:10px; background:#e0e0e0; padding-top:50px; }

/* ── Print bar ── */
.print-bar {
    position:fixed; top:0; left:0; right:0;
    background:#1f2937; color:#fff;
    padding:8px 20px;
    display:flex; align-items:center; justify-content:space-between;
    z-index:9999; font-size:13px; font-family:Arial,sans-serif;
    box-shadow:0 2px 8px rgba(0,0,0,.3);
}
.print-bar .info { color:#9ca3af; font-size:12px; }
.print-bar .info span { color:#fff; font-weight:700; }
.btn-print {
    background:#22c55e; color:#fff; border:none;
    padding:8px 20px; border-radius:6px;
    font-size:13px; font-weight:700; cursor:pointer;
    display:flex; align-items:center; gap:6px;
}
.btn-back {
    background:#4b5563; color:#fff; border:none;
    padding:8px 16px; border-radius:6px;
    font-size:13px; font-weight:600; cursor:pointer;
    text-decoration:none; display:flex; align-items:center; gap:6px;
}
.btn-print:hover { background:#16a34a; }
.btn-back:hover  { background:#374151; }
.bar-actions { display:flex; gap:8px; align-items:center; }

/* ── Toggle checkboxes ── */
.cb-toggle-wrap {
    display:flex; align-items:center; gap:8px;
    background:rgba(255,255,255,.10);
    border:1px solid rgba(255,255,255,.22);
    border-radius:7px; padding:5px 13px;
    font-size:12px; color:#e5e7eb;
    cursor:pointer; user-select:none;
    transition:background .2s; line-height:1.4;
}
.cb-toggle-wrap:hover { background:rgba(255,255,255,.18); }
.cb-toggle-wrap input[type=checkbox] {
    width:15px; height:15px; accent-color:#22c55e; cursor:pointer;
}
.cb-toggle-wrap b  { font-size:12px; display:block; }
.cb-toggle-wrap em { font-size:10px; color:#9ca3af; font-style:normal; display:block; }

/* ── A4 page ──
   FIXED height (not min-height) so a page can never grow into an extra sheet.
   Content that is too tall is shrunk by fitPages() (see JS) to fit inside. ── */
@page { size:A4; margin:0; }

.page {
    width:210mm; height:296mm;
    background:#fff; margin:8mm auto;
    padding:6mm 7mm;
    box-shadow:0 2px 12px rgba(0,0,0,.18);
    overflow:hidden; position:relative;
}
.page-inner { width:100%; transform-origin:top left; }
.page + .page { page-break-before:always; margin-top:0; }

@media print {
    html, body { background:#fff; padding-top:0; }
    .print-bar { display:none !important; }
    .page {
        margin:0; padding:6mm 7mm; box-shadow:none;
        width:210mm; height:296mm; overflow:hidden;
        page-break-inside:avoid; break-inside:avoid;
    }
    .page + .page { page-break-before:always; break-before:page; }
    .cbc-table tr.cb-filled td { background:#fff !important; }
    .rcc-table tr.rc-filled-rt td,
    .rcc-table tr.rc-filled-sb td { background:#fff !important; }
}

/* ── All tables ── */
table { border-collapse:collapse; width:100%; }
td, th { border:1px solid #000; padding:2px 3px; text-align:left; white-space:nowrap; }

/* ── Header ── */
.header-title {
    text-align:center; font-weight:bold;
    font-size:14px; margin-bottom:4px; letter-spacing:.5px;
}
.header-grid {
    display:grid; grid-template-columns:1fr 1fr 1fr;
    gap:6px; align-items:stretch; margin-bottom:0;
}
.left-block td  { border:1px solid #000; padding:2px 4px; font-size:10px; height:18px; }
.left-block td.lbl { font-weight:bold; width:80px; }
.mid-block  td  { border:1px solid #000; padding:2px 5px; font-size:10px; height:18px; }
.mid-block  td.lbl { width:80px; }
.mid-block  td.val { font-weight:bold; }
.right-block {
    border:2px solid #000; padding:4px 6px; font-size:10px; line-height:1.8;
}
.right-block .rb-title { font-weight:bold; font-size:10px; margin-bottom:2px; text-decoration:underline; }
.date-right { text-align:right; font-size:10px; font-weight:bold; padding:2px 0 3px; }

/* ── SR code divider row inside main table ── */
.sr-divider-row td {
    background:#f1f5f9 !important;
    font-size:8px; font-weight:bold;
    color:#334155; letter-spacing:.5px;
    text-transform:uppercase;
    padding:1px 4px; height:14px;
    border-top:1.5px solid #94a3b8;
    border-bottom:1px solid #cbd5e1;
}
/* SR code badge inside divider row */
.sr-code-badge {
    display:inline-block;
    background:#1e3a5f; color:#fff;
    font-size:7.5px; font-weight:800;
    border-radius:3px; padding:0 4px;
    letter-spacing:.4px; line-height:13px;
    margin-left:4px; vertical-align:middle;
}

/* ── Main table (page 1) ── */
.main-table th {
    font-weight:normal; text-align:center; font-style:italic;
    font-size:10px; height:22px; vertical-align:bottom; padding:2px 3px;
}
.main-table td { height:20px; padding:2px 3px; font-size:11px; }
.main-table td.cust-name {
    font-size:9px; white-space:normal; word-break:break-word;
    width:90px; max-width:90px; line-height:1.2;
}
.main-table .num-col { width:20px; text-align:center; }

/* ── PD (Payment mode/Days) column ── */
.main-table td.pd-col {
    text-align:center; font-size:9px; font-weight:700; letter-spacing:.2px;
}
.main-table td.pd-col.pd-cash   { color:#166534; }
.main-table td.pd-col.pd-credit { color:#b45309; }
.main-table td.pd-col.pd-cheque { color:#5b21b6; }

/* ── Credit Bal column ── */
.main-table td.credit-bal-col {
    text-align:right; font-size:9px; font-weight:700; color:#c2410c;
}

/* ── Star mark after customer name for customers with outstanding credit ── */
.credit-star {
    color:#c2410c; font-size:10px; font-weight:800;
    margin-left:2px; vertical-align:middle;
}

/* ── Cancel column: kept narrow so Cash / Credit / Cheque / Chq No / Bank / Date get the space.
   The risk bill reason short code (from the Risk Customer Bills report) is printed small and bold inside it. ── */
.main-table th.cancel-col,
.main-table td.cancel-col {
    width:26px; max-width:26px;
    padding:1px 1px; text-align:center;
    font-size:8px; line-height:1.1;
    overflow:hidden; white-space:normal; word-break:break-all;
}
.main-table th.cancel-col { font-size:8.5px; }
.main-table td.cancel-col .risk-code { font-weight:bold; font-size:8px; letter-spacing:.2px; }
.main-table td.cancel-col .cancel-amt { display:block; font-size:7.5px; }
.main-table td.cancel-total { text-align:center; font-size:8px; padding:1px; }

.total-row td { font-weight:bold; border-top:2px solid #000; height:20px; }

/* ── Credit Bill Collections table (page 2) ── */
.cbc-title {
    text-align:center; font-weight:bold;
    font-size:13px; margin-bottom:4px; letter-spacing:.5px;
}
.cbc-table th {
    font-weight:normal; text-align:center; font-style:italic;
    font-size:11px; height:24px; vertical-align:bottom; padding:2px 3px;
}
.cbc-table td { height:22px; padding:2px 3px; font-size:11px; }
.cbc-table td.cust-name {
    font-size:10px; white-space:nowrap; overflow:hidden;
    text-overflow:ellipsis; max-width:160px; width:160px; line-height:1;
}
.cbc-table .num-col { width:18px; text-align:center; }
.cbc-table .total-row td { font-weight:bold; border-top:2px solid #000; height:22px; }
.cbc-table tr.cb-filled td { background:#f0fdf4; }

/* ── SR divider row inside cbc-table ── */
.cbc-sr-divider td {
    background:#f1f5f9 !important;
    font-size:8px; font-weight:bold;
    color:#334155; letter-spacing:.5px;
    text-transform:uppercase;
    padding:1px 4px; height:13px;
    border-top:1.5px solid #94a3b8;
    border-bottom:1px solid #cbd5e1;
}

/* ── Return / Sent Back Cheque section ── */
.section-divider  { margin:6px 0 5px; }
.section-title    { text-align:center; font-size:12px; letter-spacing:3px; margin-bottom:5px; font-style:italic; }
.rcc-table th     { font-weight:normal; font-style:italic; letter-spacing:.5px; text-align:center; font-size:11px; height:22px; }
.rcc-table td     { height:20px; font-size:11px; }
.rcc-table .num-col { width:18px; text-align:center; }
.rcc-table tr.rc-filled-rt td { background:#fef2f2; }
.rcc-table tr.rc-filled-sb td { background:#faf5ff; }

/* ── SR divider row inside rcc-table ── */
.rcc-sr-divider td {
    background:#f1f5f9 !important;
    font-size:8px; font-weight:bold;
    color:#334155; letter-spacing:.5px;
    text-transform:uppercase;
    padding:1px 4px; height:13px;
    border-top:1.5px solid #94a3b8;
    border-bottom:1px solid #cbd5e1;
}

.chq-type-rt {
    display:inline-block; font-size:8px; font-weight:800;
    background:#fee2e2; color:#991b1b; border:1px solid #fca5a5;
    border-radius:3px; padding:0 3px; margin-left:3px; vertical-align:middle; line-height:12px;
}
.chq-type-sb {
    display:inline-block; font-size:8px; font-weight:800;
    background:#ede9fe; color:#5b21b6; border:1px solid #c4b5fd;
    border-radius:3px; padding:0 3px; margin-left:3px; vertical-align:middle; line-height:12px;
}
.cc-ofz-row { display:flex; justify-content:space-around; margin:5px 0 4px; font-size:12px; letter-spacing:1px; font-style:italic; }
.summary-table { border:2px solid #000; margin-bottom:5px; }
.summary-table td { font-style:italic; letter-spacing:.3px; font-size:11px; height:18px; }
.cash-snex-wrap { display:flex; gap:0; margin-bottom:4px; }
.cash-snex-wrap table { width:55%; }
.cash-snex-wrap td { font-style:italic; letter-spacing:.3px; font-size:11px; }
.overcharge-wrap { display:flex; justify-content:space-between; margin-bottom:10px; }
.overcharge-wrap table { width:auto; }
.overcharge-wrap td { font-style:italic; letter-spacing:.3px; font-size:11px; }
.signature-section { display:grid; grid-template-columns:1fr 1fr 1fr; margin-top:12px; gap:10px; }
.sig-block  { font-size:11px; line-height:1.8; }
.sig-dots   { letter-spacing:1px; font-size:12px; margin-bottom:1px; }
</style>
</head>
<body>

<!-- ── PRINT BAR ── -->
<div class="print-bar">
    <div class="info">
        Import <span>#<?php echo $import_id; ?></span> &nbsp;|&nbsp;
        Delivery Date: <span><?php echo $delivery_date; ?></span> &nbsp;|&nbsp;
        CC Groups: <span><?php echo count($cc_groups); ?></span>
    </div>
    <div class="bar-actions">

        <!-- CREDIT BILL FILL TOGGLE -->
        <label class="cb-toggle-wrap" title="Auto-fill Credit Bill Collections from issued bills on this date">
            <input type="checkbox" id="fillCreditBillsCb" onchange="toggleCreditBillFill(this.checked)">
            <span>
                <b>&#9632; Fill Credit Bills</b>
                <em>from <?php echo $delivery_date; ?> issues</em>
            </span>
        </label>

        <!-- RETURN / SENT BACK CHEQUE FILL TOGGLE -->
        <label class="cb-toggle-wrap" title="Auto-fill Return/Sent Back Cheque Collections from cheques issued to CC on this date">
            <input type="checkbox" id="fillReturnChequesCb" onchange="toggleReturnChequeFill(this.checked)">
            <span>
                <b>&#9632; Fill Return/SB Cheques</b>
                <em>RT + SB from <?php echo $delivery_date; ?></em>
            </span>
        </label>

        <a href="view_import_details.php?id=<?php echo $import_id; ?>" class="btn-back">&#8592; Back</a>
        <button class="btn-print" onclick="fitPages(); window.print()">&#128438; Print All</button>
    </div>
</div>

<?php
/* ══════════════════════════════════════════════════════════════
   RENDER EACH CC (DELIVERY PERSON) GROUP — EXACTLY 2 pages per CC
   Page 1: All invoice rows for this CC (grouped by SR with divider),
           SR code shown in divider header (no separate SR column)
   Page 2: Credit Bill Collections + Return/SB Cheques for this CC
   Each page has a fixed A4 height; oversized content is auto-scaled
   down by fitPages() so it never spills onto an extra sheet.
   ══════════════════════════════════════════════════════════════ */
$page_idx = 0;
foreach ($cc_groups as $cc_person => $records):

    // ── Aggregate totals across all records for this CC
    $total_unit   = 0;
    $total_cancel = 0;
    foreach ($records as $r) {
        $total_unit   += floatval($r['final_bill_amount']);
        $total_cancel += floatval($r['cancel_amount'] ?? 0);
    }

    $total_count   = count($records);
    $date_part     = date('Ymd', strtotime($import['delivery_date']));
    $unilever_code = 'UNILEVER-' . $date_part . '-' . ($cc_person ?: 'CC');

    // Collect distinct SR codes for this CC (for the middle block display)
    $sr_codes_in_cc = array_unique(array_column($records, 'sales_person_code'));
    sort($sr_codes_in_cc);

    // Group records by SR code for page-1 SR dividers
    $records_by_sr = [];
    foreach ($records as $r) {
        $sc = $r['sales_person_code'] ?: 'UNKNOWN';
        $records_by_sr[$sc][] = $r;
    }

    // ── Blank rows to pad page 1 to a fixed height.
    //    SR divider rows take space too, so they are subtracted from the 28-row target.
    $sr_divider_count = count($records_by_sr);
    $blank_rows = max(0, 28 - $total_count - $sr_divider_count);

    $page_idx++;

    // Credit bills & combined cheques for this CC
    $sr_credit_bills          = array_values($credit_bills_by_cc[$cc_person] ?? []);
    $sr_bills_json            = json_encode($sr_credit_bills,     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    $sr_combined_cheques      = array_values($combined_cheques_by_cc[$cc_person] ?? []);
    $sr_combined_cheques_json = json_encode($sr_combined_cheques, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
?>

<!-- ═══ PAGE 1 — CC: <?php echo htmlspecialchars($cc_person); ?> ═══ -->
<div class="page">
<div class="page-inner">
    <div class="header-title">YELO DISTRIBUTORS</div>

    <div class="header-grid">
        <!-- LEFT — CC info -->
        <div class="left-block">
            <table>
                <tr><td class="lbl">CC</td><td class="val"></td></tr>
                <tr><td class="lbl">ACC</td><td class="val"></td></tr>
                <tr><td class="lbl">Driver</td><td class="val"></td></tr>
                <tr><td class="lbl">Vehicle Number</td><td class="val"></td></tr>
                <tr>
                    <td class="lbl">In/Out Time</td>
                    <td class="val" style="padding:0;">
                        <table style="width:100%;height:100%;border-collapse:collapse;border:none;">
                            <tr>
                                <td style="border:none;border-right:1px solid #000;width:50%;height:18px;"></td>
                                <td style="border:none;width:50%;height:18px;"></td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </div>
        <!-- MIDDLE — Bill counts & SR list -->
        <div class="mid-block">
            <table>
                <tr><td class="lbl">Total Bill</td><td class="val"><?php echo $total_count; ?></td></tr>
                <tr><td class="lbl">Delivered Bill</td><td class="val"></td></tr>
                <tr><td class="lbl">Cancle Bill</td><td class="val"></td></tr>
                <tr><td class="lbl">SR Code(s)</td><td class="val" style="font-size:9px;"><?php echo htmlspecialchars(implode(', ', $sr_codes_in_cc)); ?></td></tr>
                <tr><td class="lbl">Route</td><td class="val"></td></tr>
            </table>
        </div>
        <!-- RIGHT -->
        <div class="right-block">
            <div class="rb-title"><?php echo htmlspecialchars($unilever_code); ?></div>
            <div class="rb-line">Received Cash :</div>
            <div class="rb-line">Cash Excess/Short :</div>
            <div class="rb-line">Note :</div>
        </div>
    </div>

    <div class="date-right">Date: <?php echo $delivery_date; ?></div>

    <!-- Main invoice table — SR code shown in group divider header, NO separate SR column -->
    <!-- PD column added right after Customer Name: CS=Cash, CR{days}=Credit, CH{days}=Cheque -->
    <!-- Credit Bal column added right after PD: shows outstanding credit balance for this customer -->
    <table class="main-table">
        <thead>
            <tr>
                <th class="num-col">No</th>
                <th style="width:30px;">Invoice</th>
                <th style="width:36px;">T.Code</th>
                <th style="width:120px;">Customer Name</th>
                <th style="width:22px;">PD</th>
                <th style="width:34px;">Credit Bal</th>
                <th style="width:25px;">unit value</th>
                <th class="cancel-col">Cancel</th>
                <th style="width:52px;">Cash</th>
                <th style="width:52px;">Credit</th>
                <th style="width:52px;">Cheque</th>
                <th style="width:54px;">Chq No</th>
                <th style="width:36px;">Bank</th>
                <th style="width:36px;">Date</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $row_num = 1;
        foreach ($records_by_sr as $sr_code => $sr_rows):
        ?>
            <!-- SR divider row — SR code shown as badge inside the header -->
            <tr class="sr-divider-row">
                <td colspan="14">
                    SR: <span class="sr-code-badge"><?php echo htmlspecialchars($sr_code); ?></span>
                    &nbsp;&nbsp;(<?php echo count($sr_rows); ?> bills)
                </td>
            </tr>
        <?php
            foreach ($sr_rows as $r):
                // Risk bill reason short code picked on the Risk Customer Bills report (if any)
                $risk_code = $risk_code_by_bill[trim((string)($r['bill_no'] ?? ''))] ?? '';

                $invoice_display = format_invoice_no($r['bill_no']);
                $tcode_display   = substr($r['t_code'], -5);
                $customer        = htmlspecialchars($r['customer_name'] ?: ($r['party_name'] ?? ''));
                $unit_val        = number_format($r['final_bill_amount'], 2);
                $cancel_val      = isset($r['cancel_amount']) && $r['cancel_amount'] ? number_format($r['cancel_amount'], 2) : '';

                $pd_mode    = strtolower(trim($r['cust_payment_mode'] ?? ''));
                $pd_display = build_pd_display($r['cust_payment_mode'] ?? '', $r['cust_credit_days'] ?? '');
                $pd_class   = $pd_mode === 'cash' ? 'pd-cash' : ($pd_mode === 'credit' ? 'pd-credit' : ($pd_mode === 'cheque' ? 'pd-cheque' : ''));

                // ── Outstanding credit balance lookup for this customer ──
                $has_credit  = isset($credit_info_by_tcode[$r['t_code']]);
                $credit_bal  = $has_credit ? $credit_info_by_tcode[$r['t_code']]['total_balance'] : 0;
                $credit_cnt  = $has_credit ? $credit_info_by_tcode[$r['t_code']]['inv_count']     : 0;
                $credit_bal_display = $has_credit ? number_format($credit_bal, 2) : '';
                if ($has_credit) {
                    $customer .= ' <span class="credit-star" title="Outstanding credit: Rs. '
                               . number_format($credit_bal, 2) . ' across ' . $credit_cnt . ' invoice'
                               . ($credit_cnt != 1 ? 's' : '') . '">&#9733;</span>';
                }
        ?>
            <?php /* Every bill prints as a normal row (blacklisted customers are no longer highlighted).
                     If a risk bill reason was picked for this bill, its short code shows in the Cancel column in bold. */ ?>
            <tr>
                <td class="num-col"><?php echo $row_num++; ?></td>
                <td><?php echo htmlspecialchars($invoice_display); ?></td>
                <td><?php echo htmlspecialchars($tcode_display); ?></td>
                <td class="cust-name"><?php echo $customer; ?></td>
                <td class="pd-col <?php echo $pd_class; ?>"><?php echo htmlspecialchars($pd_display); ?></td>
                <td class="credit-bal-col"><?php echo $credit_bal_display; ?></td>
                <td style="text-align:right;"><?php echo $unit_val; ?></td>
                <td class="cancel-col"><?php
                    if ($risk_code !== '') echo '<span class="risk-code">' . htmlspecialchars($risk_code) . '</span>';
                    if ($cancel_val !== '') echo '<span class="cancel-amt">' . $cancel_val . '</span>';
                ?></td>
                <td></td><td></td><td></td><td></td><td></td><td></td>
            </tr>
        <?php
            endforeach;
        endforeach;
        ?>

        <?php for ($b = 0; $b < $blank_rows; $b++): ?>
        <tr>
            <td class="num-col"><?php echo $row_num++; ?></td>
            <td></td><td></td><td class="cust-name"></td>
            <td class="pd-col"></td>
            <td class="credit-bal-col"></td>
            <td></td><td class="cancel-col"></td><td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
        <?php endfor; ?>

        <tr class="total-row">
            <td colspan="6" style="padding-left:4px;">Total</td>
            <td style="text-align:right;"><?php echo number_format($total_unit, 2); ?></td>
            <td class="cancel-total"><?php echo $total_cancel ? number_format($total_cancel, 2) : ''; ?></td>
            <td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
        </tbody>
    </table>
</div><!-- /page-inner -->
</div><!-- /PAGE 1 -->


<!-- ═══ PAGE 2 — CC: <?php echo htmlspecialchars($cc_person); ?> ═══ -->
<div class="page" id="page2-<?php echo $page_idx; ?>">
<div class="page-inner">

    <div class="cbc-title">Credit Bill Collections</div>
    <!-- CC name sub-header -->
    <div style="text-align:center;font-size:11px;color:#374151;margin-bottom:4px;font-style:italic;">
        CC: <strong><?php echo htmlspecialchars($cc_person); ?></strong>
        &nbsp;|&nbsp; Date: <?php echo $delivery_date; ?>
    </div>

    <!-- Credit Bill table — NO separate SR column; SR shown in group divider via JS -->
    <!-- Prints <?php echo $CREDIT_BILL_ROWS; ?> empty rows; extra rows are added only when filled bills exceed that -->
    <table class="cbc-table">
        <thead>
            <tr>
                <th class="num-col">No</th>
                <th style="width:52px;">Invoice</th>
                <th style="width:36px;">Code</th>
                <th style="width:160px;">Customer Name</th>
                <th style="width:42px;">Inv Date</th>
                <th style="width:52px;text-align:right;">Value</th>
                <th style="width:52px;">Val Paid</th>
                <th style="width:52px;">Cash</th>
                <th style="width:52px;">Cheque</th>
                <th style="width:42px;">Chq No</th>
                <th style="width:28px;">Bank</th>
                <th style="width:28px;">Date</th>
            </tr>
        </thead>
        <tbody id="cbc-body-<?php echo $page_idx; ?>">
        <?php for ($cb = 1; $cb <= $CREDIT_BILL_ROWS; $cb++): ?>
        <tr>
            <td class="num-col"><?php echo $cb; ?></td>
            <td></td><td></td>
            <td class="cust-name"></td>
            <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
        <?php endfor; ?>
        <tr class="total-row">
            <td class="num-col"></td>
            <td colspan="4" style="padding-left:4px;">Total</td>
            <td id="cbc-total-<?php echo $page_idx; ?>" style="text-align:right;"></td>
            <td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
        </tbody>
    </table>

    <!-- RETURN / SENT BACK CHEQUE COLLECTIONS -->
    <div class="section-divider"></div>
    <div class="section-title">Return / Sent Back Cheque Collections</div>

    <!-- Legend -->
    <div style="font-size:9px;margin-bottom:4px;display:flex;gap:12px;align-items:center;">
        <span style="display:inline-flex;align-items:center;gap:3px;">
            <span style="display:inline-block;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:3px;padding:0 4px;font-size:8px;font-weight:800;line-height:14px;">RT</span>
            Return Cheque
        </span>
        <span style="display:inline-flex;align-items:center;gap:3px;">
            <span style="display:inline-block;background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;border-radius:3px;padding:0 4px;font-size:8px;font-weight:800;line-height:14px;">SB</span>
            Sent Back Cheque
        </span>
    </div>

    <!-- Return/SB Cheque table — NO separate SR column; SR shown in group divider via JS -->
    <table class="rcc-table" id="rcc-table-<?php echo $page_idx; ?>">
        <thead>
            <tr>
                <th class="num-col">No</th>
                <th style="width:38px;">Code</th>
                <th>Customer Name</th>
                <th style="width:65px;">Cheque No</th>
                <th style="width:46px;">Value</th>
                <th style="width:48px;">Val Paid</th>
                <th style="width:50px;">Cash</th>
                <th style="width:50px;">Cheque</th>
                <th style="width:38px;">Chq No</th>
                <th style="width:30px;">Bank</th>
                <th style="width:30px;">Date</th>
            </tr>
        </thead>
        <tbody id="rcc-body-<?php echo $page_idx; ?>">
            <tr><td class="num-col">1</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
            <tr><td class="num-col">2</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
            <tr class="total-row">
                <td colspan="2" style="padding-left:8px;">Total</td>
                <td></td><td></td>
                <td id="rcc-total-<?php echo $page_idx; ?>" style="text-align:right;"></td>
                <td></td><td></td><td></td><td></td><td></td><td></td>
            </tr>
        </tbody>
    </table>

    <div class="cc-ofz-row">
        <span style="margin-left:50px;">CC</span>
        <span>CC</span>
        <span style="margin-right:60px;">Ofz</span>
    </div>

    <table class="summary-table">
        <colgroup>
            <col style="width:110px;"><col style="width:65px;">
            <col style="width:110px;"><col style="width:65px;"><col style="width:65px;">
            <col style="width:125px;"><col style="width:65px;"><col style="width:65px;">
        </colgroup>
        <tr>
            <td>Bank Deposited Va.</td><td></td>
            <td>Day Sale Cash.</td><td></td><td></td>
            <td>Day Sale Cheque</td><td></td><td></td>
        </tr>
        <tr>
            <td>Slip Value Total</td><td></td>
            <td>Credit Bills Cash</td><td></td><td></td>
            <td>Credit Bills Cheque</td><td></td><td></td>
        </tr>
        <tr>
            <td>Online Payment Va.</td><td></td>
            <td>Re/Cheque Cash</td><td></td><td></td>
            <td>Re/Cheque Paid Cheque</td><td></td><td></td>
        </tr>
        <tr>
            <td style="border-top:2px solid #000;">Actual Total Cash</td>
            <td style="border-top:2px solid #000;"></td>
            <td style="border-top:2px solid #000;">Total Cash &nbsp; Rs.</td>
            <td style="border-top:2px solid #000;"></td>
            <td style="border-top:2px solid #000;"></td>
            <td style="border-top:2px solid #000;">Total Cheques &nbsp; Rs.</td>
            <td style="border-top:2px solid #000;"></td>
            <td style="border-top:2px solid #000;"></td>
        </tr>
    </table>

    <div class="cash-snex-wrap">
        <table>
            <tr>
                <td style="width:95px;"></td>
                <td style="width:110px;text-align:center;">CC</td>
                <td style="width:160px;text-align:center;">Ofz</td>
            </tr>
            <tr>
                <td>Cash Short/Exess</td>
                <td style="border:1px solid #000;"></td>
                <td style="border:1px solid #000;"></td>
            </tr>
        </table>
    </div>

    <div class="overcharge-wrap">
        <table style="width:auto;">
            <tr>
                <td style="width:115px;">Over Charge Paid Rs.</td>
                <td style="width:130px;"></td>
            </tr>
        </table>
        <table style="width:auto;">
            <tr>
                <td style="width:130px;">Over Charge Cheque Rs.</td>
                <td style="width:130px;"></td>
            </tr>
        </table>
    </div>

    <br><br><br>

    <div class="signature-section">
        <div class="sig-block">
            <div class="sig-dots">…………………………..</div>
            <div>Name &amp; Signature</div>
            <div>Cash Collector</div>
        </div>
        <div class="sig-block">
            <div class="sig-dots">…………………………..</div>
            <div>Name &amp; Signature</div>
            <div>Computer Operator-02</div>
        </div>
        <div class="sig-block">
            <div class="sig-dots">…………………………..</div>
            <div>Name &amp; Signature</div>
            <div>Operation Manager</div>
        </div>
    </div>

</div><!-- /page-inner -->
</div><!-- /PAGE 2 -->

<!-- ── Per-CC data injected safely via script tags ── -->
<script>
window.__cbData  = window.__cbData  || {};
window.__rcData  = window.__rcData  || {};
window.__cbData[<?php echo (int)$page_idx; ?>] = <?php echo $sr_bills_json; ?>;
window.__rcData[<?php echo (int)$page_idx; ?>] = <?php echo $sr_combined_cheques_json; ?>;
</script>

<?php endforeach; ?>

<!-- ════════════════════════════════════════════════════════════
     JAVASCRIPT
     ════════════════════════════════════════════════════════════ -->
<script>
var TOTAL_PAGES = <?php echo (int)$page_idx; ?>;

function tCodeLast5(tc) {
    return tc ? String(tc).slice(-5) : '';
}

/* ── Extract trailing invoice number from codes like
   "26SEP_UK62_12600059" -> "12600059" (last underscore-separated part) ── */
function formatInvoiceNo(inv) {
    if (!inv) return '';
    var s = String(inv);
    if (s.indexOf('_') === -1) return s;
    var parts = s.split('_');
    return parts[parts.length - 1];
}

/* ── Format: comma-separated thousands, 2 decimal places ── */
function fmtAmt(v) {
    var n = parseFloat(v || 0);
    if (n <= 0) return '';
    return n.toLocaleString('en-US', { minimumFractionDigits:2, maximumFractionDigits:2 });
}
function fmtTotal(n) {
    if (n <= 0) return '';
    return n.toLocaleString('en-US', { minimumFractionDigits:2, maximumFractionDigits:2 });
}

/* ══════════════════════════════════════════════════════════════
   Credit Bill fill — groups rows by SR code, inserts divider row
   Column order in cbc-table (NO SR column):
   0=No, 1=Invoice, 2=Code, 3=CustName, 4=InvDate, 5=Value,
   6=ValPaid, 7=Cash, 8=Cheque, 9=ChqNo, 10=Bank, 11=Date
   The table prints 20 empty rows. If a CC has more than 20 bills,
   extra rows (class "cb-extra") are added on fill and removed on reset.
   ══════════════════════════════════════════════════════════════ */
function fillPage(idx, fill) {
    var tbody   = document.getElementById('cbc-body-'  + idx);
    var totalTd = document.getElementById('cbc-total-' + idx);
    var bills   = (window.__cbData && window.__cbData[idx]) ? window.__cbData[idx] : [];

    if (!tbody) return;

    // Remove any previously injected SR divider rows and extra rows
    tbody.querySelectorAll('tr.cbc-sr-divider, tr.cb-extra').forEach(function(tr) {
        tr.parentNode.removeChild(tr);
    });

    var rows = Array.from(tbody.querySelectorAll('tr:not(.total-row):not(.cbc-sr-divider)'));

    /* reset all data rows */
    rows.forEach(function(tr, i) {
        var cells = tr.querySelectorAll('td');
        tr.classList.remove('cb-filled');
        if (cells[0]) cells[0].textContent = i + 1;
        for (var c = 1; c < cells.length; c++) cells[c].textContent = '';
    });
    if (totalTd) totalTd.textContent = '';
    if (!fill) return;

    var totalRow = tbody.querySelector('tr.total-row');

    /* grow rows if more bills than the 20 printed rows */
    while (rows.length < bills.length) {
        var newTr = document.createElement('tr');
        newTr.className = 'cb-extra';
        for (var ci = 0; ci < 12; ci++) {
            var td = document.createElement('td');
            if (ci === 0) td.className = 'num-col';
            if (ci === 3) td.className = 'cust-name';
            newTr.appendChild(td);
        }
        tbody.insertBefore(newTr, totalRow);
        rows.push(newTr);
    }

    /* Group bills by SR code, preserving order */
    var srOrder = [];
    var srGroups = {};
    bills.forEach(function(b) {
        var sr = b.sr_code || 'UNKNOWN';
        if (!srGroups[sr]) { srGroups[sr] = []; srOrder.push(sr); }
        srGroups[sr].push(b);
    });

    var total    = 0;
    var rowIdx   = 0;      // index into blank rows
    var billNum  = 1;

    srOrder.forEach(function(sr) {
        var grp = srGroups[sr];

        /* Insert SR divider row before this group's first data row */
        var divTr = document.createElement('tr');
        divTr.className = 'cbc-sr-divider';
        var divTd = document.createElement('td');
        divTd.colSpan = 12;
        divTd.innerHTML = 'SR: <span style="display:inline-block;background:#1e3a5f;color:#fff;font-size:7px;font-weight:800;border-radius:3px;padding:0 4px;letter-spacing:.4px;line-height:12px;margin-left:3px;vertical-align:middle;">'
            + sr.replace(/</g,'&lt;').replace(/>/g,'&gt;')
            + '</span>&nbsp;&nbsp;(' + grp.length + ' bills)';
        divTr.appendChild(divTd);

        /* Insert before the next available blank row */
        if (rowIdx < rows.length) {
            tbody.insertBefore(divTr, rows[rowIdx]);
        } else {
            tbody.insertBefore(divTr, totalRow);
        }

        grp.forEach(function(b) {
            if (rowIdx >= rows.length) return;
            var tr    = rows[rowIdx++];
            var cells = tr.querySelectorAll('td');
            var bal   = parseFloat(b.balance || 0);

            tr.classList.add('cb-filled');
            if (cells[0]) cells[0].textContent = billNum++;
            if (cells[1]) cells[1].textContent = formatInvoiceNo(b.invoice_num);
            if (cells[2]) cells[2].textContent = tCodeLast5(b.t_code);
            if (cells[3]) cells[3].textContent = b.customer_name || '';
            if (cells[4]) cells[4].textContent = b.bill_date     || '';
            if (cells[5]) { cells[5].textContent = fmtAmt(bal); cells[5].style.textAlign = 'right'; }
            total += bal > 0 ? bal : 0;
        });
    });

    /* Re-number remaining blank rows */
    for (var i = rowIdx; i < rows.length; i++) {
        var cells = rows[i].querySelectorAll('td');
        if (cells[0]) cells[0].textContent = billNum++;
    }

    if (totalTd) totalTd.textContent = fmtTotal(total);
}

function toggleCreditBillFill(checked) {
    for (var i = 1; i <= TOTAL_PAGES; i++) fillPage(i, checked);
    fitPages();
}

/* ══════════════════════════════════════════════════════════════
   Return / Sent Back Cheque fill — groups rows by SR code,
   inserts divider row per SR group.
   Column order in rcc-table (NO SR column):
   0=No, 1=Code, 2=CustName, 3=ChequeNo,
   4=Value, 5=ValPaid, 6=Cash, 7=Cheque, 8=ChqNo, 9=Bank, 10=Date
   ══════════════════════════════════════════════════════════════ */
function fillReturnChequePage(idx, fill) {
    var tbody   = document.getElementById('rcc-body-'  + idx);
    var totalTd = document.getElementById('rcc-total-' + idx);
    if (!tbody) return;

    var bills = (window.__rcData && window.__rcData[idx]) ? window.__rcData[idx] : [];

    // Remove previously injected SR divider rows
    var prevDividers = tbody.querySelectorAll('tr.rcc-sr-divider');
    prevDividers.forEach(function(tr) { tr.parentNode.removeChild(tr); });

    var dataRows = Array.from(tbody.querySelectorAll('tr')).filter(function(tr) {
        return !tr.classList.contains('total-row') && !tr.classList.contains('rcc-sr-divider');
    });

    /* reset */
    dataRows.forEach(function(tr, i) {
        var cells = tr.querySelectorAll('td');
        tr.classList.remove('rc-filled-rt', 'rc-filled-sb');
        if (cells[0]) cells[0].textContent = i + 1;
        for (var c = 1; c < cells.length; c++) cells[c].innerHTML = '';
    });
    if (totalTd) totalTd.textContent = '';
    if (!fill || !bills.length) return;

    /* grow rows if needed */
    var totalRow = tbody.querySelector('tr.total-row');
    var colCount = dataRows[0] ? dataRows[0].querySelectorAll('td').length : 11;
    while (dataRows.length < bills.length) {
        var newTr = document.createElement('tr');
        for (var ci = 0; ci < colCount; ci++) {
            var td = document.createElement('td');
            if (ci === 0) td.className = 'num-col';
            newTr.appendChild(td);
        }
        tbody.insertBefore(newTr, totalRow);
        dataRows.push(newTr);
    }

    /* Group by SR code, preserving insertion order */
    var srOrder  = [];
    var srGroups = {};
    bills.forEach(function(b) {
        var sr = b.sr_code || 'UNKNOWN';
        if (!srGroups[sr]) { srGroups[sr] = []; srOrder.push(sr); }
        srGroups[sr].push(b);
    });

    var total   = 0;
    var rowIdx  = 0;
    var billNum = 1;

    srOrder.forEach(function(sr) {
        var grp = srGroups[sr];

        /* Insert SR divider row */
        var divTr = document.createElement('tr');
        divTr.className = 'rcc-sr-divider';
        var divTd = document.createElement('td');
        divTd.colSpan = colCount;
        divTd.innerHTML = 'SR: <span style="display:inline-block;background:#1e3a5f;color:#fff;font-size:7px;font-weight:800;border-radius:3px;padding:0 4px;letter-spacing:.4px;line-height:12px;margin-left:3px;vertical-align:middle;">'
            + sr.replace(/</g,'&lt;').replace(/>/g,'&gt;')
            + '</span>&nbsp;&nbsp;(' + grp.length + ' cheques)';
        divTr.appendChild(divTd);

        if (rowIdx < dataRows.length) {
            tbody.insertBefore(divTr, dataRows[rowIdx]);
        } else {
            tbody.insertBefore(divTr, totalRow);
        }

        grp.forEach(function(b) {
            if (rowIdx >= dataRows.length) return;
            var tr    = dataRows[rowIdx++];
            var cells = tr.querySelectorAll('td');
            var bal   = parseFloat(b.balance || 0);
            var isRT  = (b.cheque_type === 'RT');

            tr.classList.add(isRT ? 'rc-filled-rt' : 'rc-filled-sb');

            if (cells[0]) cells[0].textContent = billNum++;
            if (cells[1]) cells[1].textContent = tCodeLast5(b.t_code);
            if (cells[2]) cells[2].textContent = b.customer_name || '';
            if (cells[3]) {
                var badgeHtml = isRT
                    ? '<span class="chq-type-rt">RT</span>'
                    : '<span class="chq-type-sb">SB</span>';
                cells[3].innerHTML = (b.cheque_no || '') + badgeHtml;
            }
            if (cells[4]) { cells[4].textContent = fmtAmt(bal); cells[4].style.textAlign = 'right'; }

            total += bal > 0 ? bal : 0;
        });
    });

    if (totalTd) totalTd.textContent = fmtTotal(total);
}

function toggleReturnChequeFill(checked) {
    for (var i = 1; i <= TOTAL_PAGES; i++) fillReturnChequePage(i, checked);
    fitPages();
}

/* ══════════════════════════════════════════════════════════════
   AUTO-FIT — shrink any page whose content is taller than the A4
   sheet, so a CC always prints as exactly 2 pages (no 3rd page,
   no page "jumping"). Pages that already fit are left untouched.
   ══════════════════════════════════════════════════════════════ */
function fitPages() {
    document.querySelectorAll('.page').forEach(function (p) {
        var inner = p.querySelector('.page-inner');
        if (!inner) return;

        // reset previous scaling before measuring
        inner.style.transform = '';
        inner.style.width     = '';

        var cs    = getComputedStyle(p);
        var avail = p.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
        var h     = inner.scrollHeight;

        if (h > avail) {
            var s = (avail / h) * 0.985;           // small safety margin
            inner.style.transformOrigin = 'top left';
            inner.style.width     = (100 / s) + '%';
            inner.style.transform = 'scale(' + s + ')';
        }
    });
}

window.addEventListener('load',        fitPages);
window.addEventListener('beforeprint', fitPages);
window.addEventListener('resize',      fitPages);
</script>

</body>
</html>