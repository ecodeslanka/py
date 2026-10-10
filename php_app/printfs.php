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

// Get all detail records grouped by sales_person_code
$details_query = "
    SELECT d.*, d.sales_person_code AS sr_full_name
    FROM loading_summary_import_details d
    WHERE d.import_id = $import_id
    ORDER BY d.sales_person_code, d.id
";
$details_result = mysqli_query($conn, $details_query);

$sr_groups = [];
while ($row = mysqli_fetch_assoc($details_result)) {
    $code = $row['sales_person_code'] ?: 'UNKNOWN';
    $sr_groups[$code][] = $row;
}

// ─────────────────────────────────────────────────────────────
// PRE-LOAD DELIVERY PERSON (CC) per SR code from loading summary
// ─────────────────────────────────────────────────────────────
$delivery_person_by_sr = [];
$dp_query = "
    SELECT DISTINCT sales_person_code, delivery_person
    FROM loading_summary_import_details
    WHERE import_id = $import_id
      AND delivery_person IS NOT NULL
      AND delivery_person <> ''
    ORDER BY sales_person_code, id
";
$dp_result = mysqli_query($conn, $dp_query);
if ($dp_result) {
    while ($dp = mysqli_fetch_assoc($dp_result)) {
        $sr = $dp['sales_person_code'] ?: 'UNKNOWN';
        if (!isset($delivery_person_by_sr[$sr])) {
            $delivery_person_by_sr[$sr] = $dp['delivery_person'];
        }
    }
}

// ─────────────────────────────────────────────────────────────
// PRE-LOAD CREDIT BILL DATA FOR THIS DATE
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
            'customer_name' => $cb['customer_name']  ?? '',
            'balance'       => $cb['balance']        ?? 0,
            'bill_date'     => $cb['bill_date']      ? date('d/m/Y', strtotime($cb['bill_date'])) : '',
            'person_type'   => $cb['person_type']    ?? '',
            'person_code'   => $cb['person_code']    ?? '',
        ];
    }
}

// ─────────────────────────────────────────────────────────────
// PRE-LOAD RETURN CHEQUES (status = returned/bounced) issued to CC on this date
// type = 'RT'
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
// PRE-LOAD SENT BACK CHEQUES (status = sent_back) issued to CC on this date
// type = 'SB'
// Uses sentback_issues + sentback_issue_items tables
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

// ─────────────────────────────────────────────────────────────
// MERGE: combine return + sentback per CC person, RT rows first then SB
// ─────────────────────────────────────────────────────────────
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
// DEBUG — add ?debug=1 to URL
// ─────────────────────────────────────────────────────────────
if (isset($_GET['debug'])) {
    echo '<div style="font-family:monospace;font-size:12px;padding:20px;background:#f9fafb;">';
    echo '<h2>DEBUG — Import #' . $import_id . ' | Date: ' . $delivery_date_sql . '</h2>';
    echo '<h3>Import row:</h3><pre>' . print_r($import, true) . '</pre>';
    echo '<hr><h3>SR Groups (' . count($sr_groups) . '):</h3>';
    foreach ($sr_groups as $k => $v) { echo '<b>' . htmlspecialchars($k) . '</b>: ' . count($v) . ' rows<br>'; }
    echo '<hr><h3>Delivery Person by SR:</h3><pre>' . print_r($delivery_person_by_sr, true) . '</pre>';
    echo '<hr><h3>Credit Bills by CC:</h3>';
    if ($cb_result === false) {
        echo '<p style="color:red;font-weight:bold;">SQL ERROR: ' . mysqli_error($conn) . '</p>';
        echo '<pre>' . htmlspecialchars($cb_sql) . '</pre>';
    } else {
        echo '<pre>' . print_r($credit_bills_by_cc, true) . '</pre>';
    }
    echo '<hr><h3>Return Cheques (RT) by CC:</h3>';
    if ($rc_result === false) {
        echo '<p style="color:red;">SQL ERROR: ' . mysqli_error($conn) . '</p>';
        echo '<pre>' . htmlspecialchars($rc_sql) . '</pre>';
    } else {
        echo '<pre>' . print_r($return_cheques_by_cc, true) . '</pre>';
    }
    echo '<hr><h3>Sent Back Cheques (SB) by CC:</h3>';
    if ($sb_result === false) {
        echo '<p style="color:red;">SQL ERROR: ' . mysqli_error($conn) . '</p>';
        echo '<pre>' . htmlspecialchars($sb_sql) . '</pre>';
    } else {
        echo '<pre>' . print_r($sentback_cheques_by_cc, true) . '</pre>';
    }
    echo '<hr><h3>Combined Cheques by CC:</h3><pre>' . print_r($combined_cheques_by_cc, true) . '</pre>';
    echo '</div>';
    exit;
}
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

/* ── A4 page ── */
.page {
    width:210mm; min-height:297mm;
    background:#fff; margin:8mm auto;
    padding:6mm 7mm;
    box-shadow:0 2px 12px rgba(0,0,0,.18);
    overflow:hidden; position:relative;
}
.page + .page { page-break-before:always; margin-top:0; }

@media print {
    body { background:#fff; padding-top:0; }
    .print-bar { display:none !important; }
    .page {
        margin:0; padding:6mm 7mm; box-shadow:none;
        width:210mm; min-height:297mm; overflow:hidden;
    }
    .page + .page { page-break-before:always; }
    .cbc-table tr.cb-filled td { background:#fff !important; }
    .rcc-table tr.rc-filled td  { background:#fff !important; }
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

/* ── Return / Sent Back Cheque section ── */
.section-divider  { margin:6px 0 5px; }
.section-title    { text-align:center; font-size:12px; letter-spacing:3px; margin-bottom:5px; font-style:italic; }
.rcc-table th     { font-weight:normal; font-style:italic; letter-spacing:.5px; text-align:center; font-size:11px; height:22px; }
.rcc-table td     { height:20px; font-size:11px; }
.rcc-table .num-col { width:18px; text-align:center; }
/* RT rows = light red, SB rows = light violet */
.rcc-table tr.rc-filled-rt td { background:#fef2f2; }
.rcc-table tr.rc-filled-sb td { background:#faf5ff; }
/* type badge inside cheque no cell */
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
        SR Groups: <span><?php echo count($sr_groups); ?></span>
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
        <button class="btn-print" onclick="window.print()">&#128438; Print All</button>
    </div>
</div>

<?php
/* ══════════════════════════════════════════════════════════════
   RENDER EACH SR GROUP — 2 pages per SR
   ══════════════════════════════════════════════════════════════ */
$page_idx = 0;
foreach ($sr_groups as $sr_code => $records):
    $total_unit   = 0;
    $total_cancel = 0;
    foreach ($records as $r) {
        $total_unit   += floatval($r['final_bill_amount']);
        $total_cancel += floatval($r['cancel_amount'] ?? 0);
    }
    $count         = count($records);
    $blank_rows    = max(0, 28 - $count);
    $date_part     = date('Ymd', strtotime($import['delivery_date']));
    $unilever_code = 'UNILEVER-' . $date_part . $sr_code;
    $page_idx++;

    // CC person for this SR
    $cc_person = $delivery_person_by_sr[$sr_code] ?? '';

    // Credit bills
    $sr_credit_bills = array_values($credit_bills_by_cc[$cc_person] ?? []);
    $sr_bills_json   = json_encode($sr_credit_bills, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

    // Combined return + sentback cheques for this CC person
    $sr_combined_cheques      = array_values($combined_cheques_by_cc[$cc_person] ?? []);
    $sr_combined_cheques_json = json_encode($sr_combined_cheques, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
?>

<!-- ═══ PAGE 1 — SR: <?php echo htmlspecialchars($sr_code); ?> ═══ -->
<div class="page">
    <div class="header-title">YELO DISTRIBUTORS</div>

    <div class="header-grid">
        <!-- LEFT -->
        <div class="left-block">
            <table>
                <tr><td class="lbl">CC</td><td class="val"><?php echo htmlspecialchars($cc_person); ?></td></tr>
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
        <!-- MIDDLE -->
        <div class="mid-block">
            <table>
                <tr><td class="lbl">Total Bill</td><td class="val"><?php echo $count; ?></td></tr>
                <tr><td class="lbl">Delivered Bill</td><td class="val"></td></tr>
                <tr><td class="lbl">Cancle Bill</td><td class="val"></td></tr>
                <tr><td class="lbl">Route</td><td class="val"></td></tr>
                <tr><td class="lbl">SR Code</td><td class="val"><?php echo htmlspecialchars($sr_code); ?></td></tr>
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

    <table class="main-table">
        <thead>
            <tr>
                <th class="num-col">No</th>
                <th style="width:30px;">Invoice</th>
                <th style="width:36px;">T.Code</th>
                <th style="width:120px;">Customer Name</th>
                <th style="width:25px;">unit value</th>
                <th style="width:46px;">Cancel</th>
                <th style="width:46px;">Cash</th>
                <th style="width:46px;">Credit</th>
                <th style="width:4px;">Cheque</th>
                <th style="width:52px;">Chq No</th>
                <th style="width:34px;">Bank</th>
                <th style="width:34px;">Date</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $row_num = 1;
        foreach ($records as $r):
            $invoice_display = $r['bill_no'];
            $tcode_display   = substr($r['t_code'], -5);
            $customer        = htmlspecialchars($r['customer_name'] ?: ($r['party_name'] ?? ''));
            $unit_val        = number_format($r['final_bill_amount'], 2);
            $cancel_val      = isset($r['cancel_amount']) && $r['cancel_amount'] ? number_format($r['cancel_amount'], 2) : '';
        ?>
        <tr>
            <td class="num-col"><?php echo $row_num++; ?></td>
            <td><?php echo htmlspecialchars($invoice_display); ?></td>
            <td><?php echo htmlspecialchars($tcode_display); ?></td>
            <td class="cust-name"><?php echo $customer; ?></td>
            <td style="text-align:right;"><?php echo $unit_val; ?></td>
            <td style="text-align:right;"><?php echo $cancel_val; ?></td>
            <td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
        <?php endforeach; ?>

        <?php for ($b = 0; $b < $blank_rows; $b++): ?>
        <tr>
            <td class="num-col"><?php echo $row_num++; ?></td>
            <td></td><td></td><td class="cust-name"></td>
            <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
        <?php endfor; ?>

        <tr class="total-row">
            <td colspan="4" style="padding-left:4px;">Total</td>
            <td style="text-align:right;"><?php echo number_format($total_unit, 2); ?></td>
            <td style="text-align:right;"><?php echo $total_cancel ? number_format($total_cancel, 2) : ''; ?></td>
            <td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
        </tbody>
    </table>
</div><!-- /PAGE 1 -->


<!-- ═══ PAGE 2 — SR: <?php echo htmlspecialchars($sr_code); ?> ═══ -->
<div class="page" id="page2-<?php echo $page_idx; ?>">

    <div class="cbc-title">Credit Bill Collections</div>

    <table class="cbc-table">
        <thead>
            <tr>
                <th class="num-col">No</th>
                <th style="width:55px;">Invoice</th>
                <th style="width:38px;">Code</th>
                <th style="width:160px;">Customer Name</th>
                <th style="width:44px;">Invoice Date</th>
                <th style="width:55px;text-align:right;">Value</th>
                <th style="width:55px;">Value Paid</th>
                <th style="width:55px;">Cash</th>
                <th style="width:55px;">Cheque</th>
                <th style="width:46px;">Chq No</th>
                <th style="width:30px;">Bank</th>
                <th style="width:30px;">Date</th>
            </tr>
        </thead>
        <tbody id="cbc-body-<?php echo $page_idx; ?>">
        <?php for ($cb = 1; $cb <= 25; $cb++): ?>
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

    <table class="rcc-table" id="rcc-table-<?php echo $page_idx; ?>">
        <thead>
            <tr>
                <th class="num-col">No</th>
                <th style="width:52px;">Sales Rep</th>
                <th style="width:40px;">Code</th>
                <th>Customer Name</th>
                <th style="width:68px;">Cheque No</th>
                <th style="width:48px;">Value</th>
                <th style="width:50px;">Value Paid</th>
                <th style="width:52px;">Cash</th>
                <th style="width:52px;">Cheque</th>
                <th style="width:40px;">Chq No</th>
                <th style="width:32px;">Bank</th>
                <th style="width:32px;">Date</th>
            </tr>
        </thead>
        <tbody id="rcc-body-<?php echo $page_idx; ?>">
            <tr><td class="num-col">1</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
            <tr><td class="num-col">2</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
            <tr class="total-row">
                <td colspan="2" style="padding-left:8px;">Total</td>
                <td></td><td></td><td></td>
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

</div><!-- /PAGE 2 -->

<!-- ── Data injected safely via <script> tags ── -->
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

/* ── FORMAT: comma-separated thousands, 2 decimal places ── */
function fmtAmt(v) {
    var n = parseFloat(v || 0);
    if (n <= 0) return '';
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function fmtTotal(n) {
    if (n <= 0) return '';
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/* ── Credit Bill fill ── */
function fillPage(idx, fill) {
    var tbody   = document.getElementById('cbc-body-'  + idx);
    var totalTd = document.getElementById('cbc-total-' + idx);
    var bills   = (window.__cbData && window.__cbData[idx]) ? window.__cbData[idx] : [];

    if (!tbody) return;

    var rows = tbody.querySelectorAll('tr:not(.total-row)');

    for (var i = 0; i < rows.length; i++) {
        var cells = rows[i].querySelectorAll('td');
        rows[i].classList.remove('cb-filled');
        if (cells[0]) cells[0].textContent = i + 1;
        if (cells[1]) cells[1].textContent = '';
        if (cells[2]) cells[2].textContent = '';
        if (cells[3]) cells[3].textContent = '';
        if (cells[4]) cells[4].textContent = '';
        if (cells[5]) cells[5].textContent = '';
    }

    if (totalTd) totalTd.textContent = '';
    if (!fill)   return;

    var total = 0;
    for (var j = 0; j < bills.length && j < rows.length; j++) {
        var b     = bills[j];
        var cells = rows[j].querySelectorAll('td');
        var bal   = parseFloat(b.balance || 0);

        rows[j].classList.add('cb-filled');
        if (cells[0]) cells[0].textContent = j + 1;
        if (cells[1]) cells[1].textContent = b.invoice_num   || '';
        if (cells[2]) cells[2].textContent = tCodeLast5(b.t_code);
        if (cells[3]) cells[3].textContent = b.customer_name || '';
        if (cells[4]) cells[4].textContent = b.bill_date     || '';
        if (cells[5]) { cells[5].textContent = fmtAmt(bal); cells[5].style.textAlign = 'right'; }
        total += bal > 0 ? bal : 0;
    }

    if (totalTd) totalTd.textContent = fmtTotal(total);
}

function toggleCreditBillFill(checked) {
    for (var i = 1; i <= TOTAL_PAGES; i++) {
        fillPage(i, checked);
    }
}

/* ── Return / Sent Back Cheque fill ── */
function fillReturnChequePage(idx, fill) {
    var tbody   = document.getElementById('rcc-body-'  + idx);
    var totalTd = document.getElementById('rcc-total-' + idx);
    if (!tbody) return;

    var bills = (window.__rcData && window.__rcData[idx]) ? window.__rcData[idx] : [];

    /* collect only non-total data rows */
    var dataRows = Array.from(tbody.querySelectorAll('tr')).filter(function(tr) {
        return !tr.classList.contains('total-row');
    });

    /* always reset first */
    dataRows.forEach(function(tr, i) {
        var cells = tr.querySelectorAll('td');
        tr.classList.remove('rc-filled-rt', 'rc-filled-sb');
        if (cells[0]) cells[0].textContent = i + 1;
        for (var c = 1; c < cells.length; c++) {
            /* clear both text and any injected HTML */
            cells[c].textContent = '';
        }
    });
    if (totalTd) totalTd.textContent = '';

    if (!fill || !bills.length) return;

    /* if more cheques than existing rows, insert rows before total row */
    var totalRow = tbody.querySelector('tr.total-row');
    while (dataRows.length < bills.length) {
        var newTr = document.createElement('tr');
        var colCount = dataRows[0] ? dataRows[0].querySelectorAll('td').length : 12;
        for (var ci = 0; ci < colCount; ci++) {
            var td = document.createElement('td');
            if (ci === 0) td.className = 'num-col';
            newTr.appendChild(td);
        }
        tbody.insertBefore(newTr, totalRow);
        dataRows.push(newTr);
    }

    var total = 0;
    bills.forEach(function(b, j) {
        if (j >= dataRows.length) return;
        var tr    = dataRows[j];
        var cells = tr.querySelectorAll('td');
        var bal   = parseFloat(b.balance || 0);
        var isRT  = (b.cheque_type === 'RT');

        /* row background class */
        tr.classList.add(isRT ? 'rc-filled-rt' : 'rc-filled-sb');

        /* No */
        if (cells[0]) cells[0].textContent = j + 1;

        /* Sales Rep */
        if (cells[1]) cells[1].textContent = b.sr_code || '';

        /* last 5 of t_code */
        if (cells[2]) cells[2].textContent = tCodeLast5(b.t_code);

        /* Customer Name */
        if (cells[3]) cells[3].textContent = b.customer_name || '';

        /* Cheque No  + (RT)/(SB) badge */
        if (cells[4]) {
            var badgeHtml = isRT
                ? '<span class="chq-type-rt">RT</span>'
                : '<span class="chq-type-sb">SB</span>';
            cells[4].innerHTML = (b.cheque_no || '') + badgeHtml;
        }

        /* Value (balance) — formatted with commas */
        if (cells[5]) {
            cells[5].textContent     = fmtAmt(bal);
            cells[5].style.textAlign = 'right';
        }

        /* cells[6..11] left blank: Value Paid, Cash, Cheque, ChqNo, Bank, Date */

        total += bal > 0 ? bal : 0;
    });

    if (totalTd) totalTd.textContent = fmtTotal(total);
}

function toggleReturnChequeFill(checked) {
    for (var i = 1; i <= TOTAL_PAGES; i++) {
        fillReturnChequePage(i, checked);
    }
}
</script>

</body>
</html>