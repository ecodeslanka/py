<?php
/**
 * print_daily_summary.php
 * ─────────────────────────────────────────────────────────────────────────
 * Daily Issue Summary Print Report — B&W Print Edition
 * CC: single table per issue + Return/Sent Back Cheque Settlements section
 * SR: tables split by SR Code — each SR-sub-table has its own 5 empty rows + signatures
 *     + Return/Sent Back Cheque Settlements section (same as CC)
 * No summary strip. No grand total footer bar.
 *
 * URL params:
 *   date   YYYY-MM-DD   (required)
 *   type   CC | SR      (initial default = CC)
 * ─────────────────────────────────────────────────────────────────────────
 */

include 'config.php';

// ── Load company name ──
$_co_res = mysqli_query($conn, "SELECT company_name FROM companies WHERE active = 1 ORDER BY id ASC LIMIT 1");
$_co_row = $_co_res ? mysqli_fetch_assoc($_co_res) : null;
$company_name = $_co_row ? $_co_row['company_name'] : 'YELO LOGISTICS';

$report_date = trim($_GET['date'] ?? date('Y-m-d'));
$init_type   = strtoupper(trim($_GET['type'] ?? 'CC'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
    die('Invalid date.');
}

$date_safe = mysqli_real_escape_string($conn, $report_date);

/* ── Load ALL issues for this date (both CC and SR) — JS filters ── */
$sql_issues = "
    SELECT
        bi.id                               AS issue_id,
        bi.issue_code,
        bi.issue_date,
        bi.person_type,
        bi.person_code,
        bi.person_name,
        bi.employee_id,
        COALESCE(emp.employee_full_name,'') AS emp_name,
        COALESCE(d.designation_name,'')     AS emp_desig
    FROM credit_bill_issues bi
    LEFT JOIN employees emp ON emp.id = bi.employee_id
    LEFT JOIN designations d ON d.id = emp.designation_id
    WHERE DATE(bi.issue_date) = '$date_safe'
    ORDER BY bi.person_type ASC, bi.person_code ASC, bi.id ASC
";
$res_issues = mysqli_query($conn, $sql_issues);
$issues = [];
if ($res_issues) {
    while ($r = mysqli_fetch_assoc($res_issues)) $issues[] = $r;
}

/* ── Empty state ── */
if (empty($issues)) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>No Data</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"></head>
    <body style="font-family:Arial;padding:50px 30px;text-align:center;background:#f8fafc;">
    <div style="max-width:420px;margin:0 auto;background:#fff;border-radius:14px;padding:44px;box-shadow:0 2px 24px rgba(0,0,0,.1);">
    <i class="fa-solid fa-inbox" style="font-size:44px;color:#9ca3af;display:block;margin-bottom:14px;"></i>
    <h3 style="color:#1f2937;font-size:17px;margin:0 0 8px;">No Issues Found</h3>
    <p style="color:#6b7280;font-size:13px;margin:0 0 22px;">No issues found for <strong>'.htmlspecialchars($report_date).'</strong></p>
    <a href="javascript:history.back()" style="display:inline-flex;align-items:center;gap:6px;padding:9px 20px;background:#374151;color:#fff;border-radius:8px;text-decoration:none;font-size:13px;font-weight:700;">
    <i class="fa-solid fa-arrow-left"></i> Go Back</a></div></body></html>';
    exit;
}

/* ── Load all bill items for found issues ── */
$issue_ids = array_column($issues, 'issue_id');
$ids_str   = implode(',', array_map('intval', $issue_ids));

$sql_items = "
    SELECT
        item.id,
        item.issue_id,
        item.invoice_num,
        item.customer_name,
        item.balance,
        fsd.t_code,
        fs.sr_code,
        fs.delivery_date
    FROM credit_bill_issue_items item
    LEFT JOIN field_summary_details fsd ON fsd.id = item.detail_id
    LEFT JOIN field_summary fs          ON fs.id  = fsd.field_summary_id
    WHERE item.issue_id IN ($ids_str)
    ORDER BY item.issue_id ASC, item.id ASC
";
$res_items      = mysqli_query($conn, $sql_items);
$items_by_issue = [];
if ($res_items) {
    while ($r = mysqli_fetch_assoc($res_items)) {
        $items_by_issue[$r['issue_id']][] = $r;
    }
}

/* ─────────────────────────────────────────────────────────────
   HELPER: build cheque map (RT or SB) for a given person_type
   ───────────────────────────────────────────────────────────── */
function buildReturnChequeMap($conn, $date_safe, $person_type) {
    $pt = mysqli_real_escape_string($conn, $person_type);
    $sql = "
        SELECT
            ci.person_code,
            ci.person_type,
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
        WHERE DATE(ci.issue_date) = '$date_safe'
          AND ci.person_type = '$pt'
          AND ii.status = 'issued'
        ORDER BY ci.person_code, ch.cheque_no ASC
    ";
    $result = mysqli_query($conn, $sql);
    $map = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $key = $row['person_code'] ?: 'UNKNOWN';
            $map[$key][] = [
                'cheque_type'   => 'RT',
                'sr_code'       => $row['sr_code']       ?? '',
                'cheque_no'     => $row['cheque_no']      ?? '',
                't_code'        => $row['t_code']         ?? '',
                'customer_name' => $row['customer_name']  ?? '',
                'balance'       => $row['balance']        ?? 0,
                'bank_code'     => $row['bank_code']      ?? '',
                'bank_name'     => $row['bank_name']      ?? '',
                'cheque_date'   => $row['cheque_date']    ?? '',
            ];
        }
    }
    return $map;
}

function buildSentBackChequeMap($conn, $date_safe, $person_type) {
    $pt = mysqli_real_escape_string($conn, $person_type);
    $sql = "
        SELECT
            si.person_code,
            si.person_type,
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
        WHERE DATE(si.issue_date) = '$date_safe'
          AND si.person_type = '$pt'
          AND sii.status = 'issued'
        ORDER BY si.person_code, ch.cheque_no ASC
    ";
    $result = mysqli_query($conn, $sql);
    $map = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $key = $row['person_code'] ?: 'UNKNOWN';
            $map[$key][] = [
                'cheque_type'   => 'SB',
                'sr_code'       => $row['sr_code']       ?? '',
                'cheque_no'     => $row['cheque_no']      ?? '',
                't_code'        => $row['t_code']         ?? '',
                'customer_name' => $row['customer_name']  ?? '',
                'balance'       => $row['balance']        ?? 0,
                'bank_code'     => $row['bank_code']      ?? '',
                'bank_name'     => $row['bank_name']      ?? '',
                'cheque_date'   => $row['cheque_date']    ?? '',
            ];
        }
    }
    return $map;
}

/* ─────────────────────────────────────────────────────────────
   LOAD RT + SB cheques for BOTH CC and SR
   ───────────────────────────────────────────────────────────── */
$rt_cc = buildReturnChequeMap($conn, $date_safe, 'CC');
$sb_cc = buildSentBackChequeMap($conn, $date_safe, 'CC');

$rt_sr = buildReturnChequeMap($conn, $date_safe, 'SR');
$sb_sr = buildSentBackChequeMap($conn, $date_safe, 'SR');

/* ── Merge RT + SB per person_code for CC ── */
$combined_cheques_by_cc = [];
$all_cc_keys = array_unique(array_merge(array_keys($rt_cc), array_keys($sb_cc)));
foreach ($all_cc_keys as $k) {
    $combined_cheques_by_cc[$k] = array_merge($rt_cc[$k] ?? [], $sb_cc[$k] ?? []);
}

/* ── Merge RT + SB per person_code for SR ── */
$combined_cheques_by_sr = [];
$all_sr_keys = array_unique(array_merge(array_keys($rt_sr), array_keys($sb_sr)));
foreach ($all_sr_keys as $k) {
    $combined_cheques_by_sr[$k] = array_merge($rt_sr[$k] ?? [], $sb_sr[$k] ?? []);
}

/* ─────────────────────────────────────────────────────────────
   BUILD per-issue cheque lookup keyed by issue_id
   ───────────────────────────────────────────────────────────── */
$cheques_by_issue = [];
foreach ($issues as $iss) {
    $pcode = $iss['person_code'] ?: 'UNKNOWN';
    if ($iss['person_type'] === 'CC') {
        $cheques_by_issue[$iss['issue_id']] = $combined_cheques_by_cc[$pcode] ?? [];
    } elseif ($iss['person_type'] === 'SR') {
        $cheques_by_issue[$iss['issue_id']] = $combined_cheques_by_sr[$pcode] ?? [];
    }
}

$date_display = date('d/m/Y', strtotime($report_date));
$day_display  = date('l, d F Y', strtotime($report_date));

$js_issues         = json_encode($issues,          JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$js_items          = json_encode($items_by_issue,  JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$js_cheques        = json_encode($cheques_by_issue,JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$js_company_name   = json_encode($company_name,    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Daily Issue Summary — <?php echo htmlspecialchars($report_date); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ══════════════════════════
   RESET & BASE
══════════════════════════ */
*{box-sizing:border-box;}
body{
    font-family:'Courier New',Courier,monospace;
    font-size:12px;
    color:#000;
    margin:0;
    padding:10px 14px;
    background:#d1d5db;
}
h2,h3,h4,p{margin:0;padding:0;}

/* ══════════════════════════
   SCREEN TOOLBAR (screen only)
══════════════════════════ */
.screen-toolbar{
    display:flex;align-items:center;justify-content:space-between;
    gap:10px;flex-wrap:wrap;
    padding:10px 16px;
    background:#111827;
    border-radius:8px;
    margin-bottom:14px;
}
.tb-left{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.tb-right{display:flex;align-items:center;gap:8px;}
.tb-title{color:#f9fafb;font-size:14px;font-weight:800;display:flex;align-items:center;gap:7px;font-family:Arial,sans-serif;}
.tb-date{background:rgba(255,255,255,.1);color:#d1d5db;padding:3px 12px;border-radius:6px;font-size:11px;font-weight:700;font-family:monospace;}

.type-toggle{display:flex;border-radius:6px;overflow:hidden;border:1.5px solid rgba(255,255,255,.2);}
.type-btn{padding:7px 18px;font-size:12px;font-weight:800;cursor:pointer;border:none;background:transparent;color:rgba(255,255,255,.45);transition:all .15s;display:flex;align-items:center;gap:5px;font-family:Arial,sans-serif;}
.type-btn:hover:not(.active-type){background:rgba(255,255,255,.08);color:#fff;}
.type-btn.active-type{background:#fff;color:#111827;}

.btn-print{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;background:#fff;color:#111;border:none;border-radius:6px;font-size:13px;font-weight:800;cursor:pointer;font-family:Arial,sans-serif;}
.btn-back{display:inline-flex;align-items:center;gap:5px;padding:8px 13px;background:transparent;color:#9ca3af;border:1px solid rgba(156,163,175,.3);border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;font-family:Arial,sans-serif;}
.btn-back:hover{background:rgba(255,255,255,.07);color:#fff;}

/* ══════════════════════════
   REPORT WRAPPER
══════════════════════════ */
.report-wrap{
    background:#fff;
    border-radius:8px;
    padding:0;
    box-shadow:0 4px 24px rgba(0,0,0,.18);
    overflow:hidden;
}

/* ══════════════════════════
   PRINT RULES
══════════════════════════ */
@media print {
    .screen-toolbar { display:none !important; }

    body {
        padding:0;
        background:#fff;
        font-size:11px;
        color:#000;
    }

    @page {
        size: A4 portrait;
        margin: 10mm 8mm;
    }

    .report-wrap {
        box-shadow:none !important;
        border-radius:0 !important;
    }

    .date-body   { display:block !important; }
    .issue-body  { display:block !important; }
    .sr-sub-body { display:block !important; }
    .rc-body     { display:block !important; }
    .collapse-arrow, .ih-arrow, .sr-arrow, .rc-arrow { display:none !important; }

    .issue-block {
        page-break-after: auto !important;
        page-break-inside: auto !important;
        break-after: auto !important;
        break-inside: auto !important;
    }

    .sr-sub-block {
        page-break-after: auto !important;
        page-break-inside: auto !important;
        break-after: auto !important;
        break-inside: auto !important;
    }

    .rc-section {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }

    .date-section {
        margin: 0 !important;
        padding: 0 !important;
    }
    .date-body {
        border: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .date-hdr {
        margin: 0 !important;
    }

    * {
        background: #fff !important;
        color: #000 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .bills-tbl thead th {
        border: 1px solid #000 !important;
        border-bottom: 2px solid #000 !important;
        font-weight: 900 !important;
    }
    .bills-tbl tbody td {
        border: 1px solid #555 !important;
    }
    .bills-tbl tfoot td {
        border: 1.5px solid #000 !important;
        border-top: 2px solid #000 !important;
        font-weight: 900 !important;
    }

    /* cheque table print borders */
    .chq-tbl thead th {
        border: 1px solid #000 !important;
        border-bottom: 2px solid #000 !important;
    }
    .chq-tbl tbody td {
        border: 1px solid #555 !important;
    }
    .chq-tbl tfoot td {
        border: 1.5px solid #000 !important;
        border-top: 2px solid #000 !important;
        font-weight: 900 !important;
    }

    .report-header {
        border-bottom: 2px solid #000 !important;
    }
    .info-bar {
        border-bottom: 1px solid #000 !important;
        border-top: none !important;
        border-left: none !important;
        border-right: none !important;
    }
    .ib-cell {
        border-right: 1px solid #999 !important;
    }
    .sig-block {
        border-top: 1.5px solid #000 !important;
    }
    .date-hdr {
        border: none !important;
        border-bottom: 2px solid #000 !important;
        padding: 5px 0 !important;
    }
    .date-body {
        border: none !important;
    }
    .issue-hdr {
        border: none !important;
        border-bottom: 1.5px solid #000 !important;
        background: #fff !important;
    }
    .issue-block + .issue-block {
        border-top: 2px solid #000 !important;
    }

    /* SR sub-block separator */
    .sr-sub-block + .sr-sub-block {
        border-top: 1.5px dashed #000 !important;
    }

    .sr-sub-hdr {
        border: none !important;
        border-bottom: 1px solid #000 !important;
        background: #fff !important;
    }

    .badge-cc, .badge-sr {
        border: 1px solid #000 !important;
        padding: 1px 5px !important;
    }
    .dh-pill, .ih-bcnt, .ih-total, .dh-total, .sr-bcnt, .sr-total {
        border: 1px solid #000 !important;
        padding: 1px 5px !important;
    }

    /* RC section print */
    .rc-hdr {
        border-bottom: 1px solid #000 !important;
        background: #fff !important;
    }
    .rc-pill, .rc-total-badge {
        border: 1px solid #000 !important;
    }
    .chq-type-rt, .chq-type-sb {
        border: 1px solid #000 !important;
        background: #fff !important;
        color: #000 !important;
    }
}

/* ══════════════════════════
   REPORT HEADER
══════════════════════════ */
.report-header{
    text-align:center;
    border-bottom:2.5px solid #000;
    padding:10px 14px 8px;
    margin-bottom:0;
}
.rh-company{font-size:15px;font-weight:900;letter-spacing:.8px;text-transform:uppercase;font-family:Arial,sans-serif;}
.rh-sub{font-size:12px;font-weight:700;margin-top:2px;font-family:Arial,sans-serif;}
.rh-meta{
    display:flex;justify-content:space-between;align-items:center;
    margin-top:5px;font-size:10px;color:#444;flex-wrap:wrap;gap:3px;
    font-family:Arial,sans-serif;
}

/* ══════════════════════════
   DATE SECTION
══════════════════════════ */
.date-section{margin:0 14px 12px;}
.date-hdr{
    display:flex;align-items:center;justify-content:space-between;
    border-bottom:2px solid #000;
    padding:5px 0;
    cursor:pointer;user-select:none;
}
.dh-left{display:flex;align-items:center;gap:7px;flex-wrap:wrap;}
.dh-right{display:flex;align-items:center;gap:6px;}
.dh-date{font-size:12px;font-weight:900;font-family:Arial,sans-serif;}
.dh-pill{
    background:#f3f4f6;border:1px solid #9ca3af;
    padding:1px 7px;border-radius:8px;font-size:9.5px;font-weight:700;
    font-family:Arial,sans-serif;
}
.dh-total{
    background:#f3f4f6;border:1px solid #555;
    padding:1px 8px;border-radius:8px;font-size:10px;font-weight:900;
    font-family:Arial,sans-serif;
}
.collapse-arrow{color:#6b7280;font-size:10px;transition:transform .2s;}
.date-hdr.collapsed .collapse-arrow{transform:rotate(-90deg);}

.date-body{border:1px solid #d1d5db;border-top:none;}
.date-body.hidden{display:none;}

/* ══════════════════════════
   ISSUE BLOCK
══════════════════════════ */
.issue-block+.issue-block{border-top:2px solid #000;}

.issue-hdr{
    display:flex;align-items:center;justify-content:space-between;
    padding:6px 8px;
    background:#f9fafb;
    border-bottom:1px solid #d1d5db;
    cursor:pointer;user-select:none;
}
.issue-hdr:hover{background:#f3f4f6;}
.ih-left{display:flex;align-items:center;gap:7px;flex-wrap:wrap;}
.ih-right{display:flex;align-items:center;gap:6px;}
.ih-code{font-family:'Courier New',monospace;font-size:12px;font-weight:900;}
.badge-cc{border:1px solid #555;padding:1px 6px;border-radius:6px;font-size:9.5px;font-weight:900;font-family:Arial,sans-serif;}
.badge-sr{border:1px solid #555;padding:1px 6px;border-radius:6px;font-size:9.5px;font-weight:900;font-family:Arial,sans-serif;}
.ih-person{font-size:11px;font-weight:800;font-family:Arial,sans-serif;}
.ih-name{font-size:10.5px;color:#555;font-family:Arial,sans-serif;}
.ih-bcnt{
    background:#f3f4f6;border:1px solid #9ca3af;
    padding:1px 6px;border-radius:6px;font-size:9.5px;font-weight:700;
    font-family:Arial,sans-serif;
}
.ih-total{
    background:#f3f4f6;border:1px solid #555;
    padding:1px 7px;border-radius:6px;font-size:10px;font-weight:900;
    font-family:Arial,sans-serif;
}
.ih-arrow{color:#9ca3af;font-size:10px;transition:transform .2s;}
.issue-hdr.collapsed .ih-arrow{transform:rotate(-90deg);}

.issue-body{padding:0;}
.issue-body.hidden{display:none;}

/* ── Info bar ── */
.info-bar{
    display:flex;flex-wrap:wrap;
    border-bottom:1px solid #d1d5db;
    background:#fafafa;
}
.ib-cell{
    padding:4px 8px;
    border-right:1px solid #e5e7eb;
    display:flex;flex-direction:column;gap:1px;
    min-width:80px;
}
.ib-cell:last-child{border-right:none;}
.ib-lbl{font-size:8px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;font-family:Arial,sans-serif;}
.ib-val{font-weight:900;font-size:11px;font-family:'Courier New',monospace;}

/* ══════════════════════════
   SR SUB-BLOCK (per SR Code)
══════════════════════════ */
.sr-sub-block {
    border: none;
}
.sr-sub-block + .sr-sub-block {
    border-top: 1.5px dashed #6b7280;
}

.sr-sub-hdr {
    display:flex;align-items:center;justify-content:space-between;
    padding:5px 8px;
    background:#f0f4ff;
    border-bottom:1px solid #c7d2fe;
    cursor:pointer;user-select:none;
}
.sr-sub-hdr:hover{background:#e8eeff;}
.sr-sh-left{display:flex;align-items:center;gap:7px;flex-wrap:wrap;}
.sr-sh-right{display:flex;align-items:center;gap:6px;}
.sr-sh-label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#4b5563;font-family:Arial,sans-serif;}
.sr-sh-code{font-family:'Courier New',monospace;font-size:12px;font-weight:900;}
.sr-bcnt{
    background:#e8eeff;border:1px solid #a5b4fc;
    padding:1px 6px;border-radius:6px;font-size:9.5px;font-weight:700;
    font-family:Arial,sans-serif;
}
.sr-total{
    background:#e8eeff;border:1px solid #6366f1;
    padding:1px 7px;border-radius:6px;font-size:10px;font-weight:900;
    font-family:Arial,sans-serif;
}
.sr-arrow{color:#9ca3af;font-size:10px;transition:transform .2s;}
.sr-sub-hdr.collapsed .sr-arrow{transform:rotate(-90deg);}

.sr-sub-body{padding:0;}
.sr-sub-body.hidden{display:none;}

/* ══════════════════════════
   BILLS TABLE
══════════════════════════ */
.bills-tbl{width:100%;border-collapse:collapse;font-size:11px;table-layout:fixed;}
.bills-tbl thead th{
    border:1px solid #555;
    padding:4px 4px;font-size:10px;font-weight:900;
    text-transform:uppercase;letter-spacing:.03em;
    text-align:center;
    font-family:Arial,sans-serif;
    background:#f3f4f6;
}
.bills-tbl thead th.tl{text-align:left;padding-left:5px;}
.bills-tbl thead th.tr{text-align:right;padding-right:5px;}
.bills-tbl tbody td{
    border:1px solid #d1d5db;
    padding:4px 4px;
    text-align:center;
    vertical-align:middle;
    height:21px;overflow:hidden;
    font-family:'Courier New',monospace;
}
.bills-tbl tbody td.tl{text-align:left;padding-left:5px;font-family:Arial,sans-serif;}
.bills-tbl tbody td.tr{text-align:right;padding-right:5px;}
.bills-tbl tbody tr.er td{background:#fff;height:22px;border-color:#e5e7eb;}
.bills-tbl tfoot td{
    border:1.5px solid #333;
    padding:4px 5px;font-weight:900;font-size:11px;
    background:#f3f4f6;
    font-family:Arial,sans-serif;
}
.bills-tbl tfoot td.tr{text-align:right;padding-right:5px;font-family:'Courier New',monospace;}
.bills-tbl tfoot td.tl{text-align:left;padding-left:5px;}
.bills-tbl tfoot td.tc{text-align:center;}

/* column widths — portrait A4 */
.cn{width:5%;} .ci{width:16%;} .cs{width:9%;} .cc{width:9%;} .ck{width:37%;} .cd{width:13%;} .cv{width:11%;}

/* ── Signature block ── */
.sig-block{
    display:flex;justify-content:space-around;
    padding:16px 10px 12px;
    border-top:1.5px solid #333;
}
.sig-item{text-align:center;width:130px;}
.sig-line{border-bottom:1px solid #333;width:110px;height:26px;margin:0 auto 5px;}
.sig-label{font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;font-family:Arial,sans-serif;}

/* ══════════════════════════
   RETURN / SENT BACK CHEQUES
══════════════════════════ */

/* Section wrapper */
.rc-section {
    border-top: 2px dashed #6b7280;
    margin: 0;
}

/* Collapsible header */
.rc-hdr {
    display:flex;align-items:center;justify-content:space-between;
    padding:5px 8px;
    background:#fdf4ff;
    border-bottom:1px solid #e9d5ff;
    cursor:pointer;user-select:none;
}
.rc-hdr:hover { background:#f5e8ff; }
.rc-hdr-left  { display:flex;align-items:center;gap:7px;flex-wrap:wrap; }
.rc-hdr-right { display:flex;align-items:center;gap:6px; }

.rc-hdr-title {
    font-size:10.5px;font-weight:900;text-transform:uppercase;
    letter-spacing:.05em;color:#6b21a8;font-family:Arial,sans-serif;
}
.rc-pill {
    background:#f3e8ff;border:1px solid #c084fc;
    padding:1px 6px;border-radius:6px;font-size:9.5px;font-weight:700;
    font-family:Arial,sans-serif;color:#7e22ce;
}
.rc-total-badge {
    background:#f3e8ff;border:1px solid #9333ea;
    padding:1px 7px;border-radius:6px;font-size:10px;font-weight:900;
    font-family:Arial,sans-serif;color:#6b21a8;
}
/* legend */
.rc-legend {
    display:flex;gap:12px;align-items:center;
    padding:4px 8px;font-size:9px;font-family:Arial,sans-serif;
    background:#fafafa;border-bottom:1px solid #e5e7eb;
}

.rc-body { padding:0; }
.rc-body.hidden { display:none; }
.rc-arrow { color:#9ca3af;font-size:10px;transition:transform .2s; }
.rc-hdr.collapsed .rc-arrow { transform:rotate(-90deg); }

/* Cheque type inline badges */
.chq-type-rt {
    display:inline-block;font-size:8px;font-weight:800;
    background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;
    border-radius:3px;padding:0 3px;margin-left:3px;
    vertical-align:middle;line-height:12px;
}
.chq-type-sb {
    display:inline-block;font-size:8px;font-weight:800;
    background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;
    border-radius:3px;padding:0 3px;margin-left:3px;
    vertical-align:middle;line-height:12px;
}

/* Cheque table */
.chq-tbl { width:100%;border-collapse:collapse;font-size:11px;table-layout:fixed; }
.chq-tbl thead th {
    border:1px solid #555;
    padding:4px 4px;font-size:10px;font-weight:900;
    text-transform:uppercase;letter-spacing:.03em;
    text-align:center;
    font-family:Arial,sans-serif;
    background:#f5f3ff;
}
.chq-tbl thead th.tl { text-align:left;padding-left:5px; }
.chq-tbl thead th.tr { text-align:right;padding-right:5px; }
.chq-tbl tbody td {
    border:1px solid #d1d5db;
    padding:4px 4px;
    text-align:center;
    vertical-align:middle;
    height:21px;overflow:hidden;
    font-family:'Courier New',monospace;
}
.chq-tbl tbody td.tl { text-align:left;padding-left:5px;font-family:Arial,sans-serif; }
.chq-tbl tbody td.tr { text-align:right;padding-right:5px; }
.chq-tbl tbody tr.rt-row td { background:#fff8f8; }
.chq-tbl tbody tr.sb-row td { background:#faf7ff; }
.chq-tbl tfoot td {
    border:1.5px solid #333;
    padding:4px 5px;font-weight:900;font-size:11px;
    background:#f5f3ff;
    font-family:Arial,sans-serif;
}
.chq-tbl tfoot td.tr { text-align:right;padding-right:5px;font-family:'Courier New',monospace; }
.chq-tbl tfoot td.tl { text-align:left;padding-left:5px; }
.chq-tbl tfoot td.tc { text-align:center; }

/* Cheque sig block */
.rc-sig-block {
    display:flex;justify-content:space-around;
    padding:14px 10px 10px;
    border-top:1.5px solid #333;
}

/* ── Empty state ── */
.empty-state{text-align:center;padding:32px 20px;color:#6b7280;font-size:12px;font-family:Arial,sans-serif;}
</style>
</head>
<body>

<!-- ══ SCREEN TOOLBAR ══ -->
<div class="screen-toolbar">
    <div class="tb-left">
        <div class="tb-title">
            <i class="fa-solid fa-file-chart-column"></i>
            Daily Issue Summary
        </div>
        <div class="tb-date">
            <i class="fa-solid fa-calendar-day" style="font-size:10px;margin-right:3px;"></i>
            <?php echo $day_display; ?>
        </div>
        <div class="type-toggle" id="typeToggle">
            <button class="type-btn" id="btnCC" onclick="setType('CC')">
                <i class="fa-solid fa-wallet"></i> CC Summary
            </button>
            <button class="type-btn" id="btnSR" onclick="setType('SR')">
                <i class="fa-solid fa-id-badge"></i> SR Summary
            </button>
        </div>
    </div>
    <div class="tb-right">
        <a href="javascript:history.back()" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Back
        </a>
        <button class="btn-print" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Print
        </button>
    </div>
</div>

<!-- ══ REPORT ══ -->
<div class="report-wrap" id="reportWrap">
    <div class="report-header" id="rptHeader"></div>
    <div id="rptBody"></div>
</div>

<script>
/* ═══════════════════════════════════════════════════
   DATA (from PHP)
═══════════════════════════════════════════════════ */
const ALL_ISSUES       = <?php echo $js_issues; ?>;
const ITEMS_BY_ISSUE   = <?php echo $js_items; ?>;
const CHEQUES_BY_ISSUE = <?php echo $js_cheques; ?>;
const DATE_DISPLAY     = <?php echo json_encode($date_display); ?>;
const DAY_DISPLAY      = <?php echo json_encode($day_display); ?>;
const COMPANY_NAME     = <?php echo $js_company_name; ?>;
let   ACTIVE_TYPE      = <?php echo json_encode($init_type); ?>;

/* ═══════════════════════════════════════════════════
   HELPERS
═══════════════════════════════════════════════════ */
function esc(s){
    const d=document.createElement('div');
    d.textContent=String(s==null?'':s);
    return d.innerHTML;
}
function fmtAmt(v){
    return parseFloat(v||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
}
function fmtDate(str){
    if(!str) return '';
    const d=new Date(str.substring(0,10)+'T00:00:00');
    if(isNaN(d)) return str;
    return String(d.getDate()).padStart(2,'0')+'/'+
           String(d.getMonth()+1).padStart(2,'0')+'/'+
           d.getFullYear();
}
function abbrev(name){
    name=(name||'').trim();
    if(!name) return '';
    const p=name.split(/\s+/);
    if(p.length===1) return name;
    return p[0][0].toUpperCase()+'.'+p.slice(1).join(' ');
}
function tCodeLast4(tc){
    return tc ? String(tc).slice(-4) : '';
}

/* ═══════════════════════════════════════════════════
   TOGGLE TYPE
═══════════════════════════════════════════════════ */
function setType(t){
    ACTIVE_TYPE=t;
    render();
}

/* ═══════════════════════════════════════════════════
   COLLAPSE / EXPAND
═══════════════════════════════════════════════════ */
function toggleDate(id){
    const hdr =document.getElementById('dhdr-'+id);
    const body=document.getElementById('dbdy-'+id);
    if(!hdr||!body) return;
    const c=body.classList.toggle('hidden');
    hdr.classList.toggle('collapsed',c);
}
function toggleIssue(id){
    const hdr =document.getElementById('ihdr-'+id);
    const body=document.getElementById('ibdy-'+id);
    if(!hdr||!body) return;
    const c=body.classList.toggle('hidden');
    hdr.classList.toggle('collapsed',c);
}
function toggleSrSub(uid){
    const hdr =document.getElementById('srhdr-'+uid);
    const body=document.getElementById('srbdy-'+uid);
    if(!hdr||!body) return;
    const c=body.classList.toggle('hidden');
    hdr.classList.toggle('collapsed',c);
}
function toggleRcSection(uid){
    const hdr =document.getElementById('rchdr-'+uid);
    const body=document.getElementById('rcbdy-'+uid);
    if(!hdr||!body) return;
    const c=body.classList.toggle('hidden');
    hdr.classList.toggle('collapsed',c);
}

/* ═══════════════════════════════════════════════════
   BUILD BILLS TABLE (shared by CC + each SR sub-table)
═══════════════════════════════════════════════════ */
function buildBillsTable(items, startRow, footerLabel, sigLabel){
    const total = items.reduce((s,it)=>s+parseFloat(it.balance||0),0);

    let rows = '';
    items.forEach((it,i)=>{
        const tc=tCodeLast4(it.t_code);
        rows+=`
          <tr>
            <td>${startRow+i}</td>
            <td style="font-weight:700;">${esc(it.invoice_num||'')}</td>
            <td style="font-weight:700;">${esc(it.sr_code||'')}</td>
            <td style="font-weight:700;">${esc(tc)}</td>
            <td class="tl">${esc(it.customer_name||'')}</td>
            <td>${esc(fmtDate(it.delivery_date))}</td>
            <td class="tr" style="font-weight:700;">${fmtAmt(it.balance)}</td>
          </tr>`;
    });

    /* 5 empty rows */
    for(let e=0;e<5;e++){
        rows+=`<tr class="er">
          <td style="color:#d1d5db;font-size:9px;">${startRow+items.length+e}</td>
          <td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>`;
    }

    const sigBlock = `
      <div class="sig-block">
        <div class="sig-item">
          <div class="sig-line"></div>
          <div class="sig-label">${esc(sigLabel)}</div>
        </div>
        <div class="sig-item">
          <div class="sig-line"></div>
          <div class="sig-label">Cashier</div>
        </div>
        <div class="sig-item">
          <div class="sig-line"></div>
          <div class="sig-label">OM</div>
        </div>
      </div>`;

    return `
      <table class="bills-tbl">
        <colgroup>
          <col class="cn"><col class="ci"><col class="cs">
          <col class="cc"><col class="ck"><col class="cd"><col class="cv">
        </colgroup>
        <thead>
          <tr>
            <th>No.</th>
            <th>Invoice No.</th>
            <th>SR Code</th>
            <th>Code</th>
            <th class="tl">Customer Name</th>
            <th>Inv. Date</th>
            <th class="tr">Value (Rs.)</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
        <tfoot>
          <tr>
            <td colspan="5" class="tl" style="font-size:10.5px;">${esc(footerLabel)}</td>
            <td class="tc">TOTAL</td>
            <td class="tr">Rs. ${fmtAmt(total)}</td>
          </tr>
        </tfoot>
      </table>
      ${sigBlock}`;
}

/* ═══════════════════════════════════════════════════
   BUILD RETURN / SENT BACK CHEQUE SECTION
   Works for both CC and SR issues
═══════════════════════════════════════════════════ */
function buildRcSection(uid, cheques, issueCode, sigLabel){
    if(!cheques || !cheques.length) return '';

    const total  = cheques.reduce((s,c)=>s+parseFloat(c.balance||0),0);
    const rtCnt  = cheques.filter(c=>c.cheque_type==='RT').length;
    const sbCnt  = cheques.filter(c=>c.cheque_type==='SB').length;

    const parts = [];
    if(rtCnt) parts.push(rtCnt+' RT');
    if(sbCnt) parts.push(sbCnt+' SB');
    const pillLabel = parts.join(' · ') || (cheques.length+' cheque'+(cheques.length!==1?'s':''));

    let rows = '';
    cheques.forEach((ch,i)=>{
        const isRT   = ch.cheque_type === 'RT';
        const rowCls = isRT ? 'rt-row' : 'sb-row';
        const badge  = isRT
            ? '<span class="chq-type-rt">RT</span>'
            : '<span class="chq-type-sb">SB</span>';
        const tc = tCodeLast4(ch.t_code);
        rows += `
          <tr class="${rowCls}">
            <td>${i+1}</td>
            <td style="font-weight:700;">${esc(ch.sr_code||'')}</td>
            <td style="font-weight:700;">${esc(tc)}</td>
            <td class="tl">${esc(ch.customer_name||'')}</td>
            <td style="font-weight:700;">${esc(ch.cheque_no||'')}${badge}</td>
            <td>${esc(ch.bank_code||'')}</td>
            <td>${esc(ch.cheque_date ? fmtDate(ch.cheque_date) : '')}</td>
            <td class="tr" style="font-weight:700;">${fmtAmt(ch.balance)}</td>
            <td></td>
            <td></td>
          </tr>`;
    });

    /* 3 empty rows */
    for(let e=0;e<3;e++){
        rows += `<tr class="er">
          <td style="color:#d1d5db;font-size:9px;">${cheques.length+e+1}</td>
          <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>`;
    }

    const sigBlock = `
      <div class="rc-sig-block">
        <div class="sig-item">
          <div class="sig-line"></div>
          <div class="sig-label">${esc(sigLabel)}</div>
        </div>
        <div class="sig-item">
          <div class="sig-line"></div>
          <div class="sig-label">Cashier</div>
        </div>
        <div class="sig-item">
          <div class="sig-line"></div>
          <div class="sig-label">OM</div>
        </div>
      </div>`;

    return `
      <div class="rc-section">
        <div class="rc-hdr" id="rchdr-${uid}" onclick="toggleRcSection('${uid}')">
          <div class="rc-hdr-left">
            <i class="fa-solid fa-rotate-left" style="font-size:10px;color:#9333ea;"></i>
            <span class="rc-hdr-title">Return / Sent Back Cheque Settlements</span>
            <span class="rc-pill">${esc(pillLabel)}</span>
          </div>
          <div class="rc-hdr-right">
            <span class="rc-total-badge">Rs. ${fmtAmt(total)}</span>
            <i class="fa-solid fa-chevron-down rc-arrow"></i>
          </div>
        </div>
        <div class="rc-legend">
          <span style="display:inline-flex;align-items:center;gap:3px;">
            <span class="chq-type-rt">RT</span> Return Cheque
          </span>
          <span style="display:inline-flex;align-items:center;gap:3px;">
            <span class="chq-type-sb">SB</span> Sent Back Cheque
          </span>
        </div>
        <div class="rc-body" id="rcbdy-${uid}">
          <table class="chq-tbl">
            <colgroup>
              <col style="width:4%;">
              <col style="width:9%;">
              <col style="width:7%;">
              <col style="width:22%;">
              <col style="width:16%;">
              <col style="width:7%;">
              <col style="width:10%;">
              <col style="width:10%;">
              <col style="width:8%;">
              <col style="width:7%;">
            </colgroup>
            <thead>
              <tr>
                <th>No.</th>
                <th>SR</th>
                <th>Code</th>
                <th class="tl">Customer Name</th>
                <th>Cheque No.</th>
                <th>Bank</th>
                <th>Chq Date</th>
                <th class="tr">Value (Rs.)</th>
                <th>Cash Paid</th>
                <th>Chq Paid</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
            <tfoot>
              <tr>
                <td colspan="6" class="tl" style="font-size:10.5px;">${esc(issueCode)} — Return / Sent Back Settlements</td>
                <td class="tc">TOTAL</td>
                <td class="tr">Rs. ${fmtAmt(total)}</td>
                <td></td>
                <td></td>
              </tr>
            </tfoot>
          </table>
          ${sigBlock}
        </div>
      </div>`;
}

/* ═══════════════════════════════════════════════════
   BUILD CC ISSUE
═══════════════════════════════════════════════════ */
function buildCCIssue(iss){
    const its    = ITEMS_BY_ISSUE[iss.issue_id]||[];
    const cheqs  = CHEQUES_BY_ISSUE[iss.issue_id]||[];
    const issVal = its.reduce((s,it)=>s+parseFloat(it.balance||0),0);
    const issCnt = its.length;
    const empName= abbrev(iss.emp_name||iss.person_name||'');
    const pCode  = iss.person_code||'—';
    const nameDisp= empName||iss.person_name||'';
    const iid    = iss.issue_id;

    const infoBar=`
      <div class="info-bar">
        <div class="ib-cell"><span class="ib-lbl">Issue Code</span><span class="ib-val">${esc(iss.issue_code)}</span></div>
        <div class="ib-cell"><span class="ib-lbl">Issue Date</span><span class="ib-val">${esc(fmtDate(iss.issue_date))}</span></div>
        <div class="ib-cell"><span class="ib-lbl">CC Code</span><span class="ib-val">${esc(pCode)}</span></div>
        <div class="ib-cell"><span class="ib-lbl">Name</span><span class="ib-val">${esc(nameDisp||'—')}</span></div>
        ${iss.emp_desig?`<div class="ib-cell"><span class="ib-lbl">Designation</span><span class="ib-val">${esc(iss.emp_desig)}</span></div>`:''}
        <div class="ib-cell"><span class="ib-lbl">Bills</span><span class="ib-val">${issCnt}</span></div>
        <div class="ib-cell"><span class="ib-lbl">Total Value</span><span class="ib-val">Rs. ${fmtAmt(issVal)}</span></div>
      </div>`;

    const table     = buildBillsTable(its, 1, `${iss.issue_code} — ${issCnt} bill${issCnt!==1?'s':''}`, 'CC');
    const rcSection = buildRcSection(String(iid), cheqs, iss.issue_code, 'CC');

    return `
      <div class="issue-block">
        <div class="issue-hdr" id="ihdr-${iid}" onclick="toggleIssue(${iid})">
          <div class="ih-left">
            <span class="badge-cc">CC</span>
            <span class="ih-code">${esc(iss.issue_code)}</span>
            <span class="ih-person">${esc(pCode)}</span>
            ${nameDisp?`<span class="ih-name">${esc(nameDisp)}</span>`:''}
            <span class="ih-bcnt">${issCnt} bill${issCnt!==1?'s':''}</span>
            ${cheqs.length?`<span class="rc-pill" style="font-size:9px;">${cheqs.length} cheque${cheqs.length!==1?'s':''}</span>`:''}
          </div>
          <div class="ih-right">
            <span class="ih-total">Rs. ${fmtAmt(issVal)}</span>
            <i class="fa-solid fa-chevron-down ih-arrow"></i>
          </div>
        </div>
        <div class="issue-body" id="ibdy-${iid}">
          ${infoBar}
          ${table}
          ${rcSection}
        </div>
      </div>`;
}

/* ═══════════════════════════════════════════════════
   BUILD SR ISSUE — grouped by SR Code + RC section
═══════════════════════════════════════════════════ */
function buildSRIssue(iss){
    const its    = ITEMS_BY_ISSUE[iss.issue_id]||[];
    const cheqs  = CHEQUES_BY_ISSUE[iss.issue_id]||[];
    const issVal = its.reduce((s,it)=>s+parseFloat(it.balance||0),0);
    const issCnt = its.length;
    const empName= abbrev(iss.emp_name||iss.person_name||'');
    const pCode  = iss.person_code||'—';
    const nameDisp= empName||iss.person_name||'';
    const iid    = iss.issue_id;

    /* ── Group items by sr_code ── */
    const srGroups = {};
    const srOrder  = [];
    its.forEach(it=>{
        const srKey = it.sr_code||'(No SR Code)';
        if(!srGroups[srKey]){
            srGroups[srKey]=[];
            srOrder.push(srKey);
        }
        srGroups[srKey].push(it);
    });

    /* ── Info bar ── */
    const infoBar=`
      <div class="info-bar">
        <div class="ib-cell"><span class="ib-lbl">Issue Code</span><span class="ib-val">${esc(iss.issue_code)}</span></div>
        <div class="ib-cell"><span class="ib-lbl">Issue Date</span><span class="ib-val">${esc(fmtDate(iss.issue_date))}</span></div>
        <div class="ib-cell"><span class="ib-lbl">SR Code</span><span class="ib-val">${esc(pCode)}</span></div>
        <div class="ib-cell"><span class="ib-lbl">Name</span><span class="ib-val">${esc(nameDisp||'—')}</span></div>
        ${iss.emp_desig?`<div class="ib-cell"><span class="ib-lbl">Designation</span><span class="ib-val">${esc(iss.emp_desig)}</span></div>`:''}
        <div class="ib-cell"><span class="ib-lbl">Total Bills</span><span class="ib-val">${issCnt}</span></div>
        <div class="ib-cell"><span class="ib-lbl">Total Value</span><span class="ib-val">Rs. ${fmtAmt(issVal)}</span></div>
      </div>`;

    /* ── SR sub-blocks ── */
    let subHtml = '';
    srOrder.forEach((srCode, idx)=>{
        const srItems = srGroups[srCode];
        const srVal   = srItems.reduce((s,it)=>s+parseFloat(it.balance||0),0);
        const srCnt   = srItems.length;
        const uid     = `${iid}_${idx}_${srCode.replace(/[^a-zA-Z0-9]/g,'_')}`;

        const table = buildBillsTable(srItems, 1,
            `${esc(iss.issue_code)} · SR: ${esc(srCode)} — ${srCnt} bill${srCnt!==1?'s':''}`,
            'SR');

        subHtml+=`
        <div class="sr-sub-block">
          <div class="sr-sub-hdr" id="srhdr-${uid}" onclick="toggleSrSub('${uid}')">
            <div class="sr-sh-left">
              <i class="fa-solid fa-id-badge" style="font-size:10px;color:#6366f1;"></i>
              <span class="sr-sh-label">SR Code:</span>
              <span class="sr-sh-code">${esc(srCode)}</span>
              <span class="sr-bcnt">${srCnt} bill${srCnt!==1?'s':''}</span>
            </div>
            <div class="sr-sh-right">
              <span class="sr-total">Rs. ${fmtAmt(srVal)}</span>
              <i class="fa-solid fa-chevron-down sr-arrow"></i>
            </div>
          </div>
          <div class="sr-sub-body" id="srbdy-${uid}">
            ${table}
          </div>
        </div>`;
    });

    /* ── RC section — appears after all SR sub-blocks ── */
    const rcSection = buildRcSection(String(iid), cheqs, iss.issue_code, 'SR');

    return `
      <div class="issue-block">
        <div class="issue-hdr" id="ihdr-${iid}" onclick="toggleIssue(${iid})">
          <div class="ih-left">
            <span class="badge-sr">SR</span>
            <span class="ih-code">${esc(iss.issue_code)}</span>
            <span class="ih-person">${esc(pCode)}</span>
            ${nameDisp?`<span class="ih-name">${esc(nameDisp)}</span>`:''}
            <span class="ih-bcnt">${issCnt} bill${issCnt!==1?'s':''}</span>
            <span class="ih-bcnt" style="background:#e8eeff;border-color:#a5b4fc;">${srOrder.length} SR${srOrder.length!==1?'s':''}</span>
            ${cheqs.length?`<span class="rc-pill" style="font-size:9px;">${cheqs.length} cheque${cheqs.length!==1?'s':''}</span>`:''}
          </div>
          <div class="ih-right">
            <span class="ih-total">Rs. ${fmtAmt(issVal)}</span>
            <i class="fa-solid fa-chevron-down ih-arrow"></i>
          </div>
        </div>
        <div class="issue-body" id="ibdy-${iid}">
          ${infoBar}
          ${subHtml}
          ${rcSection}
        </div>
      </div>`;
}

/* ═══════════════════════════════════════════════════
   RENDER
═══════════════════════════════════════════════════ */
function render(){
    const type   = ACTIVE_TYPE;
    const issues = ALL_ISSUES.filter(i=>i.person_type===type);

    /* toggle button states */
    document.getElementById('btnCC').className='type-btn'+(type==='CC'?' active-type':'');
    document.getElementById('btnSR').className='type-btn'+(type==='SR'?' active-type':'');

    /* ── Report header ── */
    const compName = type==='CC'
        ? COMPANY_NAME + ' — CC CREDIT BILL SUMMARY'
        : COMPANY_NAME + ' — SR CREDIT BILL SUMMARY';
    const subTitle = type==='CC'
        ? 'Daily Issue Summary · Cash Collectors (CC)'
        : 'Daily Issue Summary · Sales Representatives (SR)';

    document.getElementById('rptHeader').innerHTML=`
        <div class="rh-company">${esc(compName)}</div>
        <div class="rh-sub">${esc(subTitle)}</div>
        <div class="rh-meta">
            <span><strong>Date:</strong> ${esc(DAY_DISPLAY)}</span>
            <span><strong>Printed:</strong> ${new Date().toLocaleString('en-LK')}</span>
        </div>`;

    /* ── Empty state ── */
    if(!issues.length){
        document.getElementById('rptBody').innerHTML=`
            <div class="empty-state">
                <i class="fa-solid fa-inbox" style="font-size:34px;display:block;margin-bottom:12px;opacity:.35;"></i>
                <p>No <strong>${esc(type)}</strong> issues found for this date.</p>
            </div>`;
        return;
    }

    /* ── Group by date ── */
    const byDate={};
    issues.forEach(iss=>{
        const d=(iss.issue_date||'').substring(0,10);
        (byDate[d]||(byDate[d]=[])).push(iss);
    });

    let html='';
    const dateKeys=Object.keys(byDate).sort();

    dateKeys.forEach(date=>{
        const dayIss  = byDate[date];
        const dayBills= dayIss.reduce((s,i)=>s+(ITEMS_BY_ISSUE[i.issue_id]||[]).length,0);
        const dayVal  = dayIss.reduce((s,i)=>s+(ITEMS_BY_ISSUE[i.issue_id]||[])
                        .reduce((ss,it)=>ss+parseFloat(it.balance||0),0),0);

        const dsId=date.replace(/-/g,'');
        const prettyDate=new Date(date+'T00:00:00').toLocaleDateString('en-GB',
            {weekday:'long',day:'2-digit',month:'long',year:'numeric'});

        html+=`
        <div class="date-section">
          <div class="date-hdr" id="dhdr-${dsId}" onclick="toggleDate('${dsId}')">
            <div class="dh-left">
              <i class="fa-solid fa-calendar-day" style="font-size:10px;"></i>
              <span class="dh-date">${esc(prettyDate)}</span>
              <span class="dh-pill">${dayIss.length} issue${dayIss.length!==1?'s':''}</span>
              <span class="dh-pill">${dayBills} bill${dayBills!==1?'s':''}</span>
            </div>
            <div class="dh-right">
              <span class="dh-total">Rs. ${fmtAmt(dayVal)}</span>
              <i class="fa-solid fa-chevron-down collapse-arrow"></i>
            </div>
          </div>
          <div class="date-body" id="dbdy-${dsId}">`;

        dayIss.forEach(iss=>{
            html += (type==='SR') ? buildSRIssue(iss) : buildCCIssue(iss);
        });

        html+=`
          </div><!-- /.date-body -->
        </div><!-- /.date-section -->`;
    });

    document.getElementById('rptBody').innerHTML=html;
}

/* ── boot ── */
render();
</script>
</body>
</html>