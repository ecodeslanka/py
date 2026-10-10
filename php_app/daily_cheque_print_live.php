<?php
/**
 * daily_cheque_print_live.php
 * Live Cheque Receipt Report — filtered by received date range
 * Sources: cheques table (Part 1) + cheque_sb_settlement_payments (Part 2)
 * No saved report ID needed — generates on-the-fly.
 *
 * Classification:
 *   DC            — normal cheque, no sent_back history
 *   SB            — currently sent_back, original receive date in range
 *   SB-New Cheque — was sent_back, re-activated via Pending flow (new recv date in range)
 *                   OR settlement payment cheque received in range
 *   RTN           — status = returned / bounced
 */

include 'config.php';

$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
$f_sr      = trim($_GET['sr_code']   ?? '');
$do_report = ($date_from !== '' && $date_to !== '');

/* ── Ensure settlement payments table exists ── */
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_sb_settlement_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cheque_id INT NOT NULL,
    payment_method VARCHAR(20) DEFAULT 'cash',
    cheque_no VARCHAR(100) DEFAULT NULL,
    cheque_date DATE NULL,
    payment_date DATE NULL,
    amount DECIMAL(12,2) DEFAULT 0.00,
    reference_no VARCHAR(100) DEFAULT NULL,
    remarks TEXT DEFAULT NULL,
    bank_name VARCHAR(100) DEFAULT NULL,
    bank_code VARCHAR(50) DEFAULT NULL,
    branch_name VARCHAR(100) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(100) DEFAULT 'system',
    INDEX idx_cid (cheque_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── SR list for filter dropdown ── */
$all_sr = [];
$sr_q = mysqli_query($conn,
    "SELECT DISTINCT fs.sr_code
     FROM cheques ch
     INNER JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id
     INNER JOIN field_summary fs    ON fs.id = ip.field_summary_id
     WHERE fs.sr_code IS NOT NULL AND fs.sr_code != ''
     ORDER BY fs.sr_code");
if ($sr_q) while ($r = mysqli_fetch_assoc($sr_q)) $all_sr[] = $r['sr_code'];

/* ── Helpers ── */
function h(mixed $s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES);
}
function fmt_d(?string $d): string {
    if (!$d || $d === '0000-00-00' || str_starts_with($d, '0000')) return '—';
    $ts = strtotime($d);
    return $ts ? date('d/m/Y', $ts) : '—';
}
function classify_live(int $id, string $status, array &$logs_map): string {
    $st = strtolower(trim($status));
    if (in_array($st, ['returned', 'bounced'])) return 'RTN';
    foreach ($logs_map[$id] ?? [] as $lg) {
        if ($lg['action'] === 'sent_back' || (string)($lg['new_value'] ?? '') === 'sent_back') {
            /* cheque had a sent_back event in its history */
            return ($st === 'sent_back') ? 'SB' : 'SB-New Cheque';
        }
    }
    return ($st === 'sent_back') ? 'SB' : 'DC';
}

/* ── Data containers ── */
$all_rows = [];
$grouped  = [];
$summary  = [
    'DC'            => ['count' => 0, 'amount' => 0.0],
    'SB-New Cheque' => ['count' => 0, 'amount' => 0.0],
    'SB'            => ['count' => 0, 'amount' => 0.0],
    'RTN'           => ['count' => 0, 'amount' => 0.0],
];
$type_order = ['DC' => 0, 'SB-New Cheque' => 1, 'SB' => 2, 'RTN' => 3];

if ($do_report) {
    $esc  = fn(string $v) => mysqli_real_escape_string($conn, $v);
    $fr   = $esc($date_from);
    $to   = $esc($date_to);
    $sc   = $f_sr ? "AND fs.sr_code = '{$esc($f_sr)}'" : '';

    /* ════════════════════════════════════════════
       PART 1 — cheques table, received_date in range
    ════════════════════════════════════════════ */
    $r1 = mysqli_query($conn,
        "SELECT ch.id, ch.cheque_no, ch.cheque_date, ch.total_amount,
                ch.bank_code, ch.bank_name, ch.branch_code, ch.branch_name,
                ch.status, ch.t_code, ch.cheque_mode,
                COALESCE(ch.received_date, ip.payment_date) AS received_date,
                fs.sr_code,
                COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) AS customer_name
         FROM cheques ch
         INNER JOIN invoice_payments ip     ON ip.id  = ch.invoice_payment_id
         INNER JOIN field_summary fs        ON fs.id  = ip.field_summary_id
         LEFT  JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
         LEFT  JOIN customers c             ON c.t_code = ch.t_code
         WHERE COALESCE(ch.received_date, ip.payment_date) BETWEEN '$fr' AND '$to'
         $sc
         ORDER BY COALESCE(ch.received_date, ip.payment_date) ASC, ch.cheque_no ASC");

    $main_rows = []; $main_ids = [];
    if ($r1) while ($row = mysqli_fetch_assoc($r1)) {
        $main_rows[] = $row;
        $main_ids[]  = (int)$row['id'];
    }

    /* Load cheque_logs for all Part-1 cheques */
    $logs_map = [];
    if ($main_ids) {
        $lr = mysqli_query($conn,
            "SELECT cheque_id, action, old_value, new_value
             FROM cheque_logs
             WHERE cheque_id IN (" . implode(',', $main_ids) . ")
             ORDER BY cheque_id, created_at ASC");
        if ($lr) while ($l = mysqli_fetch_assoc($lr))
            $logs_map[(int)$l['cheque_id']][] = $l;
    }

    foreach ($main_rows as $row) {
        $type = classify_live((int)$row['id'], $row['status'] ?? '', $logs_map);
        $all_rows[] = $row + [
            'cheque_type'    => $type,
            '_src'           => 'main',
            'orig_cheque_no' => '',
        ];
    }

    /* ════════════════════════════════════════════
       PART 2 — settlement payment cheques in range
       (new replacement cheques given by customers
        to settle their sent-back cheques)
    ════════════════════════════════════════════ */
    $r2 = mysqli_query($conn,
        "SELECT ssp.cheque_no,
                ssp.cheque_date,
                ssp.payment_date   AS received_date,
                ssp.amount         AS total_amount,
                ssp.bank_name,
                ssp.bank_code,
                ssp.branch_name,
                ch.t_code,
                ch.cheque_no       AS orig_cheque_no,
                fs.sr_code,
                COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) AS customer_name
         FROM cheque_sb_settlement_payments ssp
         INNER JOIN cheques ch       ON ch.id  = ssp.cheque_id
         INNER JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id
         INNER JOIN field_summary fs    ON fs.id = ip.field_summary_id
         LEFT  JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
         LEFT  JOIN customers c         ON c.t_code = ch.t_code
         WHERE ssp.payment_method = 'cheque'
           AND ssp.cheque_no IS NOT NULL
           AND ssp.cheque_no != ''
           AND ssp.payment_date BETWEEN '$fr' AND '$to'
           $sc
         ORDER BY ssp.payment_date ASC, ssp.cheque_no ASC");

    if ($r2) while ($row = mysqli_fetch_assoc($r2)) {
        $all_rows[] = [
            'id'             => 0,
            'cheque_no'      => $row['cheque_no']      ?? '',
            'orig_cheque_no' => $row['orig_cheque_no'] ?? '',
            'cheque_date'    => $row['cheque_date']    ?? '',
            'received_date'  => $row['received_date']  ?? '',
            'total_amount'   => $row['total_amount']   ?? 0,
            'bank_code'      => $row['bank_code']      ?? '',
            'bank_name'      => $row['bank_name']      ?? '',
            'branch_code'    => '',
            'branch_name'    => $row['branch_name']    ?? '',
            'status'         => 'settlement',
            'cheque_mode'    => '',
            't_code'         => $row['t_code']         ?? '',
            'sr_code'        => $row['sr_code']        ?? '',
            'customer_name'  => $row['customer_name']  ?? '',
            'cheque_type'    => 'SB-New Cheque',
            '_src'           => 'settlement',
        ];
    }

    /* ── Build summary + groups ── */
    foreach ($all_rows as $row) {
        $t = $row['cheque_type'];
        if (isset($summary[$t])) {
            $summary[$t]['count']++;
            $summary[$t]['amount'] += floatval($row['total_amount'] ?? 0);
        }
        $rd  = $row['received_date'] ?? '';
        $key = ($rd && $rd !== '0000-00-00' && !str_starts_with($rd, '0000'))
             ? date('Y-m-d', strtotime($rd)) : '0000-00-00';
        $grouped[$key][] = $row;
    }

    /* Sort groups chronologically (unknown date at end) */
    uksort($grouped, fn($a, $b) =>
        $a === '0000-00-00' ? 1 : ($b === '0000-00-00' ? -1 : strcmp($a, $b)));

    /* Sort rows within each group by type then cheque_no */
    foreach ($grouped as &$grp) {
        usort($grp, function ($a, $b) use ($type_order) {
            $ta = $type_order[$a['cheque_type']] ?? 9;
            $tb = $type_order[$b['cheque_type']] ?? 9;
            return $ta !== $tb ? $ta - $tb : strcmp($a['cheque_no'] ?? '', $b['cheque_no'] ?? '');
        });
    }
    unset($grp);
}

$grand_count  = array_sum(array_column($summary, 'count'));
$grand_amount = array_sum(array_column($summary, 'amount'));

$date_range_label = '';
if ($date_from && $date_to) {
    $date_range_label = ($date_from === $date_to)
        ? fmt_d($date_from)
        : fmt_d($date_from) . ' to ' . fmt_d($date_to);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cheque Receipt Report<?= $date_range_label ? ' — ' . $date_range_label : '' ?> — Yelo Logistics</title>
<style>
/* ── Reset ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: Tahoma, Geneva, sans-serif;
    font-size: 7.5pt;
    color: #000;
    background: #fff;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* ════════════════════════════
   SCREEN STYLES
════════════════════════════ */
@media screen {
    body { background: #c9c9c9; }

    /* ── Top toolbar ── */
    .screen-bar {
        position: fixed; top: 0; left: 0; right: 0; z-index: 999;
        background: #111; padding: 5px 14px;
        display: flex; align-items: center; justify-content: space-between;
        gap: 8px; flex-wrap: wrap;
        border-bottom: 2px solid #333;
    }
    .screen-bar-title {
        color: #ddd; font-size: 10px; font-weight: 700; letter-spacing: .03em;
    }
    .screen-bar a, .screen-bar button {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 4px 11px; border-radius: 3px; font-size: 9.5px; font-weight: 700;
        cursor: pointer; font-family: Tahoma, Geneva, sans-serif;
        text-decoration: none; border: 1.5px solid #555;
        background: #fff; color: #111; transition: background .15s; white-space: nowrap;
    }
    .screen-bar a:hover, .screen-bar button:hover { background: #e5e5e5; }

    /* ── Filter card ── */
    .filter-card {
        position: fixed; top: 36px; left: 0; right: 0; z-index: 998;
        background: #f8f8f8;
        border-bottom: 2px solid #ddd;
        padding: 10px 20px;
        display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap;
    }
    .filter-card-title {
        font-size: 10px; font-weight: 800; color: #333;
        text-transform: uppercase; letter-spacing: .05em;
        white-space: nowrap; margin-right: 4px;
    }
    .filter-group {
        display: flex; flex-direction: column; gap: 3px;
    }
    .filter-group label {
        font-size: 9px; font-weight: 700; color: #555;
        text-transform: uppercase; letter-spacing: .04em;
    }
    .filter-group select,
    .filter-group input[type=date] {
        border: 1.5px solid #ccc; border-radius: 4px;
        padding: 5px 8px; font-size: 10px; font-family: Tahoma, Geneva, sans-serif;
        color: #222; background: #fff; height: 28px; outline: none;
        transition: border-color .15s;
    }
    .filter-group select:focus,
    .filter-group input[type=date]:focus { border-color: #0369a1; }
    .btn-generate {
        padding: 6px 16px; border-radius: 4px; border: 1.5px solid #0369a1;
        background: #0369a1; color: #fff; font-size: 10px; font-weight: 700;
        cursor: pointer; font-family: Tahoma, Geneva, sans-serif;
        display: inline-flex; align-items: center; gap: 5px;
        white-space: nowrap; height: 28px; transition: background .15s;
    }
    .btn-generate:hover { background: #0284c7; }

    /* ── Page wrapper ── */
    .page-wrapper {
        margin-top: 90px;
        padding: 16px 10px 40px;
        display: flex; flex-direction: column; align-items: center;
    }
    .report-body {
        width: 210mm; background: #fff;
        box-shadow: 0 2px 18px rgba(0,0,0,.28);
        padding: 10mm 11mm;
    }

    /* ── No-report placeholder ── */
    .no-report {
        margin-top: 110px; text-align: center; padding: 60px 20px;
    }
    .no-report-icon { font-size: 48px; margin-bottom: 12px; opacity: .35; }
    .no-report-msg  { font-size: 13px; color: #666; font-family: Tahoma, Geneva, sans-serif; }

    .print-repeat-header { display: none; }
}

/* ════════════════════════════
   PRINT STYLES
════════════════════════════ */
@media print {
    @page { size: A4 portrait; margin: 9mm 10mm 9mm 10mm; }

    .no-print     { display: none !important; }
    .page-wrapper { margin: 0; padding: 0; }
    .report-body  { width: 100%; padding: 0; box-shadow: none; }

    #print-wrap         { width: 100%; border-collapse: collapse; }
    #print-wrap > thead { display: table-header-group; }
    #print-wrap > tbody { display: table-row-group; }
    #print-wrap > thead .print-repeat-header { display: table-row; }

    .group-block   { page-break-inside: auto; }
    .group-heading { page-break-after: avoid; }
    .data-table    { page-break-inside: auto; border-collapse: collapse; }
    .data-table tr { page-break-inside: avoid; page-break-after: auto; }
    .data-table thead { display: table-header-group; }

    .summary-section { page-break-inside: avoid; }
    .sig-strip       { page-break-inside: avoid; }
}

/* ════════════════════════════
   REPORT LAYOUT (shared)
════════════════════════════ */
/* ── Full header ── */
.rpt-header {
    border-bottom: 2pt solid #000;
    padding-bottom: 4pt; margin-bottom: 3pt;
    display: flex; align-items: flex-start;
    justify-content: space-between; gap: 6pt;
}
.rpt-company  { font-size: 12pt; font-weight: 900; letter-spacing: .1pt; line-height: 1.15; }
.rpt-subtitle { font-size: 7pt; color: #222; margin-top: 1pt; font-weight: 700;
                text-transform: uppercase; letter-spacing: .06em; }

/* ── Meta block (right) ── */
.rpt-meta        { text-align: right; font-size: 7pt; color: #222; line-height: 1.7; }
.rpt-meta .meta-row {
    display: flex; align-items: baseline;
    justify-content: flex-end; gap: 4pt;
}
.rpt-meta .meta-label {
    font-size: 6.5pt; font-weight: 700;
    text-transform: uppercase; letter-spacing: .05em;
    color: #555; white-space: nowrap;
}
.rpt-meta .meta-value { font-size: 8pt; font-weight: 900; white-space: nowrap; }
.rpt-meta .meta-divider { border-top: .75pt solid #aaa; margin: 2pt 0; }

/* ── Notice strip (date range) ── */
.rpt-notice {
    border: 1pt solid #000;
    padding: 2pt 5pt; margin-bottom: 3pt;
    font-size: 7pt; font-weight: 700;
    display: flex; align-items: center; gap: 8pt;
}
.rpt-notice .notice-lbl { color: #555; font-weight: 700; }
.rpt-notice .notice-val { font-weight: 900; }

/* ── Legend ── */
.legend-box {
    border: 1pt solid #000;
    padding: 2pt 5pt; margin-bottom: 4pt;
    font-size: 6.5pt;
    display: flex; gap: 10pt; flex-wrap: wrap; align-items: center;
}
.legend-box strong { font-size: 6.5pt; text-transform: uppercase; letter-spacing: .05em; }
.legend-item { display: inline-flex; align-items: center; gap: 3pt; }
.leg-type    { font-weight: 900; font-size: 7pt; }

/* ── Group heading ── */
.group-heading {
    border: 1.5pt solid #000; border-bottom: none;
    padding: 2pt 5pt; font-size: 7pt; font-weight: 900;
    text-transform: uppercase; letter-spacing: .06em;
    display: flex; align-items: center;
    justify-content: space-between; margin-top: 5pt;
}
.gh-right { font-size: 6.5pt; font-weight: 700; }

/* ── Data table ── */
.data-table { width: 100%; border-collapse: collapse; font-size: 7pt; }

.data-table thead th {
    padding: 2pt 4pt;
    text-align: left; font-weight: 900;
    font-size: 6.5pt; color: #000;
    border: 1pt solid #000;
    white-space: nowrap; text-transform: uppercase; letter-spacing: .03em;
}
.data-table thead th.tr { text-align: right; }
.data-table thead th.tc { text-align: center; }

.data-table tbody td {
    padding: 2pt 4pt; color: #000;
    border: .75pt solid #000; vertical-align: middle;
}
.data-table tbody td.tr { text-align: right; }
.data-table tbody td.tc { text-align: center; }

.data-table tbody tr:nth-child(even) td {
    border-top: .75pt solid #555;
    border-bottom: .75pt solid #555;
}

/* Subtotal */
.subtotal-row td {
    font-weight: 900; font-size: 7pt;
    border: 1pt solid #000;
    border-top: 1.5pt solid #000;
    padding: 2pt 4pt;
}
.subtotal-row td.tr { text-align: right; }

/* ── Column widths ── */
.col-type     { width: 62pt; min-width: 62pt; }
.col-type-cell{ white-space: nowrap; font-size: 6.5pt; }
.col-chqno    { min-width: 84pt; width: 84pt; }
.col-tcode    { width: 38pt; min-width: 38pt; }

/* ── Type label styles ── */
.t-dc      { font-weight: 400; }
.t-sb      { font-weight: 400; font-style: italic; }
.t-sb-new  { font-weight: 700; font-style: italic; }
.t-rtn     { font-weight: 900; }

.mono      { font-family: Tahoma, Geneva, sans-serif; font-weight: 400; }
.sr-code   { font-weight: 400; font-size: 7pt; }

/* ── SB-New annotation ── */
.orig-ref  { font-size: 5.5pt; color: #555; font-style: italic; margin-top: 1pt; }

/* ── Summary ── */
.summary-section { margin-top: 6pt; }
.summary-title {
    font-size: 7pt; font-weight: 900;
    text-transform: uppercase; letter-spacing: .07em;
    border-bottom: 1.5pt solid #000;
    padding-bottom: 2pt; margin-bottom: 3pt;
}
.summary-table { width: 60%; border-collapse: collapse; font-size: 7.5pt; }
.summary-table th {
    padding: 2.5pt 6pt; font-size: 7pt;
    text-transform: uppercase; letter-spacing: .05em;
    border: 1pt solid #000; font-weight: 900; text-align: left;
}
.summary-table th.tr { text-align: right; }
.summary-table th.tc { text-align: center; }
.summary-table td    { padding: 2.5pt 6pt; border: .75pt solid #000; font-weight: 700; }
.summary-table td.tc { text-align: center; font-weight: 800; }
.summary-table td.tr { text-align: right;  font-weight: 800; }
.summary-table .grand-row td {
    border: 2pt solid #000; font-weight: 900; font-size: 8.5pt;
    padding: 3pt 6pt; border-top: 2pt solid #000;
}
.summary-table .grand-row td.tc { text-align: center; }
.summary-table .grand-row td.tr { text-align: right;  }

/* ── Signatures ── */
.sig-strip { width: 100%; border-collapse: collapse; margin-top: 12pt; }
.sig-strip td { width: 33.33%; padding: 0 10pt; vertical-align: bottom; text-align: center; }
.sig-strip td:first-child { padding-left: 0; }
.sig-strip td:last-child  { padding-right: 0; }
.sig-spacer { height: 18pt; }
.sig-line {
    border-top: 1.5pt solid #000; padding-top: 3pt;
    font-size: 7pt; font-weight: 900;
    text-transform: uppercase; letter-spacing: .05em;
}

/* ── Footer ── */
.page-footer {
    border-top: 1pt solid #000; padding-top: 2pt; margin-top: 6pt;
    display: flex; justify-content: space-between;
    font-size: 6.5pt; color: #444;
}

/* ── No-data message inside report ── */
.no-data-box {
    text-align: center; padding: 20pt;
    font-size: 9pt; color: #777;
    border: 1pt dashed #ccc; border-radius: 3pt;
    margin: 8pt 0;
}
</style>
</head>
<body>

<!-- ══════════════════════════════════════════
     SCREEN TOOLBAR (hidden on print)
══════════════════════════════════════════ -->
<div class="screen-bar no-print">
    <span class="screen-bar-title">&#127974; Cheque Receipt Report &mdash; Yelo Logistics</span>
    <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <a href="cheques.php">&#8592; Register</a>
        <a href="daily_sent_cheques.php">&#9776; Saved Reports</a>
        <?php if ($do_report && $grand_count): ?>
        <button onclick="window.print()">&#128424; Print / Save PDF</button>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════
     FILTER FORM (hidden on print)
══════════════════════════════════════════ -->
<form method="GET" action="" class="filter-card no-print">
    <span class="filter-card-title">&#128203; Generate Report</span>

    <div class="filter-group">
        <label>SR Code (optional)</label>
        <select name="sr_code">
            <option value="">&#8212; All SR &#8212;</option>
            <?php foreach ($all_sr as $sr): ?>
            <option value="<?= h($sr) ?>" <?= $f_sr === $sr ? 'selected' : '' ?>><?= h($sr) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="filter-group">
        <label>Received Date From <span style="color:red;">*</span></label>
        <input type="date" name="date_from" value="<?= h($date_from) ?>" required>
    </div>

    <div class="filter-group">
        <label>Received Date To <span style="color:red;">*</span></label>
        <input type="date" name="date_to" value="<?= h($date_to) ?>" required>
    </div>

    <button type="submit" class="btn-generate">&#128269; Generate Report</button>

    <?php if ($do_report && $grand_count): ?>
    <div style="font-size:9px;color:#555;align-self:center;margin-left:4px;">
        <strong><?= $grand_count ?></strong> cheques &nbsp;|&nbsp;
        <strong>Rs. <?= number_format($grand_amount, 2) ?></strong> total
    </div>
    <?php endif; ?>
</form>

<!-- ══════════════════════════════════════════
     NO DATE ENTERED — placeholder
══════════════════════════════════════════ -->
<?php if (!$do_report): ?>
<div class="no-report no-print">
    <div class="no-report-icon">&#128196;</div>
    <div class="no-report-msg">Select a <strong>Received Date</strong> range above and click <strong>Generate Report</strong>.</div>
</div>

<?php else: ?>

<!-- ══════════════════════════════════════════
     REPORT OUTPUT
══════════════════════════════════════════ -->
<div class="page-wrapper">
<div class="report-body">

    <table id="print-wrap">
        <thead><tr><td style="padding:0;display:none;"></td></tr></thead>
        <tbody>
        <tr><td style="padding:0;">

            <!-- ── PAGE-1 FULL HEADER ── -->
            <div class="rpt-header">
                <div>
                    <div class="rpt-company">YELO LOGISTICS</div>
                    <div class="rpt-subtitle">Cheque Receipt Report</div>
                </div>
                <div class="rpt-meta">
                    <div class="meta-row">
                        <span class="meta-label">Period:</span>
                        <span class="meta-value"><?= h($date_range_label) ?></span>
                    </div>
                    <?php if ($f_sr): ?>
                    <div class="meta-row">
                        <span class="meta-label">SR&nbsp;Code:</span>
                        <span class="meta-value"><?= h($f_sr) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="meta-divider"></div>
                    <div class="meta-row">
                        <span class="meta-label">Total&nbsp;Cheques:</span>
                        <span class="meta-value"><?= $grand_count ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Total&nbsp;Amount:</span>
                        <span class="meta-value">Rs.&nbsp;<?= number_format($grand_amount, 2) ?></span>
                    </div>
                    <div class="meta-divider"></div>
                    <div class="meta-row">
                        <span class="meta-label">Printed:</span>
                        <span class="meta-value" style="font-size:7pt;font-weight:700;"><?= date('d/m/Y H:i') ?></span>
                    </div>
                </div>
            </div>

            <!-- ── LEGEND ── -->
            <div class="legend-box">
                <strong>Types:</strong>
                <span class="legend-item"><span class="leg-type t-dc">DC</span>&nbsp;Daily Cheque</span>
                <span class="legend-item"><span class="leg-type t-sb-new">SB-New Cheque</span>&nbsp;Replacement / Settlement for Sent Back</span>
                <span class="legend-item"><span class="leg-type t-sb">SB</span>&nbsp;Sent Back (pending settlement)</span>
                <span class="legend-item"><span class="leg-type t-rtn">RTN</span>&nbsp;Returned / Bounced</span>
            </div>

            <?php if (empty($grouped)): ?>
            <div class="no-data-box">No cheques found for the selected date range and filters.</div>

            <?php else: ?>

            <!-- ════════════════════════════
                 GROUPS BY RECEIVED DATE
            ════════════════════════════ -->
            <?php
            $g_serial = 0;
            foreach ($grouped as $date_key => $rows):
                if ($date_key === '0000-00-00') {
                    $date_label_grp = 'Unknown / No Received Date';
                } else {
                    $gdt = new DateTime($date_key);
                    $date_label_grp = $gdt->format('d/m/Y') . ' — ' . $gdt->format('l');
                }
                $grp_total = array_sum(array_column($rows, 'total_amount'));
            ?>
            <div class="group-block">
                <div class="group-heading">
                    <span>Received: <?= h($date_label_grp) ?></span>
                    <span class="gh-right">
                        <?= count($rows) ?> cheque<?= count($rows) !== 1 ? 's' : '' ?>
                        &nbsp;|&nbsp; Rs.&nbsp;<?= number_format($grp_total, 2) ?>
                    </span>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="tc" style="width:14pt;">#</th>
                            <th class="tc col-type">Type</th>
                            <th class="col-tcode">T-Code</th>
                            <th style="width:30pt;">SR</th>
                            <th class="tc" style="width:46pt;">Recv. Date</th>
                            <th class="tc" style="width:46pt;">Chq. Date</th>
                            <th class="col-chqno">Cheque No.</th>
                            <th style="min-width:60pt;">Bank</th>
                            <th class="tr" style="width:56pt;">Amount (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $item):
                        $g_serial++;
                        $amt        = floatval($item['total_amount'] ?? 0);
                        $chq_type   = $item['cheque_type'];
                        $rec_disp   = fmt_d($item['received_date'] ?? '');
                        $chq_disp   = fmt_d($item['cheque_date']   ?? '');
                        $bank_disp  = trim($item['bank_name'] ?? '')
                                    ?: (trim($item['bank_code'] ?? '') ?: '—');
                        $is_ssp     = ($item['_src'] === 'settlement');
                        $orig_no    = $item['orig_cheque_no'] ?? '';

                        $type_css = match ($chq_type) {
                            'SB-New Cheque' => 't-sb-new',
                            'SB'            => 't-sb',
                            'RTN'           => 't-rtn',
                            default         => 't-dc',
                        };
                    ?>
                        <tr>
                            <td class="tc" style="font-size:6pt;color:#555;"><?= $g_serial ?></td>
                            <td class="tc col-type">
                                <span class="col-type-cell <?= $type_css ?>"><?= h($chq_type ?: 'DC') ?></span>
                            </td>
                            <td class="col-tcode">
                                <span style="font-size:6.5pt;"><?= h($item['t_code'] ?? '—') ?></span>
                            </td>
                            <td>
                                <span class="sr-code"><?= h($item['sr_code'] ?? '—') ?></span>
                            </td>
                            <td class="tc mono" style="font-size:6.5pt;"><?= h($rec_disp) ?></td>
                            <td class="tc mono" style="font-size:6.5pt;"><?= h($chq_disp) ?></td>
                            <td class="col-chqno">
                                <span class="mono" style="font-size:7pt;"><?= h($item['cheque_no'] ?? '—') ?></span>
                                <?php if ($is_ssp && $orig_no): ?>
                                <div class="orig-ref">Replaces: <?= h($orig_no) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:7pt;"><?= h($bank_disp) ?></td>
                            <td class="tr"><span class="mono"><?= number_format($amt, 2) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="subtotal-row">
                            <td colspan="8" style="font-style:italic;">
                                Sub-Total &mdash; <?= count($rows) ?> cheque<?= count($rows) !== 1 ? 's' : '' ?>
                            </td>
                            <td class="tr"><span class="mono"><?= number_format($grp_total, 2) ?></span></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endforeach; ?>

            <!-- ════════════════════════════
                 SUMMARY TABLE
            ════════════════════════════ -->
            <div class="summary-section">
                <div class="summary-title">Summary</div>
                <?php
                $sum_labels = [
                    'DC'            => 'DC &mdash; Daily Cheques',
                    'SB-New Cheque' => 'SB-New &mdash; Replacement / Settlement Cheques',
                    'SB'            => 'SB &mdash; Sent Back (pending settlement)',
                    'RTN'           => 'RTN &mdash; Returned / Bounced',
                ];
                ?>
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th>Cheque Type</th>
                            <th class="tc">Count</th>
                            <th class="tr">Total Amount (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sum_labels as $key => $label):
                        if (!$summary[$key]['count']) continue;
                    ?>
                        <tr>
                            <td><?= $label ?></td>
                            <td class="tc"><?= $summary[$key]['count'] ?></td>
                            <td class="tr"><?= number_format($summary[$key]['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="grand-row">
                            <td><strong>Grand Total</strong></td>
                            <td class="tc"><strong><?= $grand_count ?></strong></td>
                            <td class="tr"><strong>Rs.&nbsp;<?= number_format($grand_amount, 2) ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php endif; /* end if grouped */ ?>

            <!-- ── SIGNATURES ── -->
            <table class="sig-strip">
                <tr>
                    <td>
                        <div class="sig-spacer"></div>
                        <div class="sig-line">Prepared By</div>
                    </td>
                    <td>
                        <div class="sig-spacer"></div>
                        <div class="sig-line">Checked By</div>
                    </td>
                    <td>
                        <div class="sig-spacer"></div>
                        <div class="sig-line">Authorized By</div>
                    </td>
                </tr>
            </table>

            <!-- ── FOOTER ── -->
            <div class="page-footer">
                <span>Yelo Logistics &mdash; Confidential</span>
                <span>
                    Period: <?= h($date_range_label) ?>
                    <?= $f_sr ? '&nbsp;|&nbsp; SR: ' . h($f_sr) : '' ?>
                    &nbsp;|&nbsp; Generated: <?= date('d/m/Y H:i') ?>
                </span>
            </div>

        </td></tr>
        </tbody>
    </table><!-- /#print-wrap -->

</div><!-- /.report-body -->
</div><!-- /.page-wrapper -->
<?php endif; ?>

<script>
(function () {
    /* Auto-print if ?print=1 in URL */
    const p = new URLSearchParams(location.search);
    if (p.get('print') === '1') {
        window.addEventListener('load', () => setTimeout(() => window.print(), 400));
    }
    /* Auto-set date_to = date_from if date_from changes and date_to is empty */
    const dFrom = document.querySelector('input[name="date_from"]');
    const dTo   = document.querySelector('input[name="date_to"]');
    if (dFrom && dTo) {
        dFrom.addEventListener('change', function () {
            if (!dTo.value) dTo.value = this.value;
        });
    }
})();
</script>
</body>
</html>
