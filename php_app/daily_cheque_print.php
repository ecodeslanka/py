<?php
/**
 * daily_cheque_print.php
 * Standalone A4 print report — Yelo Logistics Cheque Details
 * Print-optimised: no colour fills, compact spacing, bold borders, monochrome
 * Access: daily_cheque_print.php?id=REPORT_ID
 */

include 'config.php';

$rid = intval($_GET['id'] ?? 0);
if (!$rid) {
    http_response_code(400);
    echo '<p style="font-family:Arial;padding:40px;color:red;">Invalid report ID.</p>'; exit;
}

/* ── Ensure tables exist ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS daily_cheque_reports (
    id INT AUTO_INCREMENT PRIMARY KEY, report_date DATE NOT NULL, remark TEXT,
    total_cheques INT DEFAULT 0, total_amount DECIMAL(15,2) DEFAULT 0, filter_snapshot TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(100) DEFAULT 'system',
    INDEX idx_date (report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS daily_cheque_report_items (
    id INT AUTO_INCREMENT PRIMARY KEY, report_id INT NOT NULL, cheque_id INT NOT NULL,
    cheque_no VARCHAR(100), cheque_date DATE, t_code VARCHAR(50), customer_name VARCHAR(255),
    bank_code VARCHAR(50), bank_name VARCHAR(255), branch_code VARCHAR(50), branch_name VARCHAR(255),
    total_amount DECIMAL(15,2) DEFAULT 0, status VARCHAR(50), sr_code VARCHAR(50),
    delivery_date DATE, cheque_mode VARCHAR(50),
    INDEX idx_rid (report_id), INDEX idx_cid (cheque_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
    id INT AUTO_INCREMENT PRIMARY KEY, cheque_id INT NOT NULL,
    action VARCHAR(100) NOT NULL, old_value TEXT, new_value TEXT, note TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(100) DEFAULT 'system',
    INDEX idx_cid (cheque_id), INDEX idx_cat (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Load active company name ── */
$company_name = 'YELO LOGISTICS'; // fallback
$cr = mysqli_query($conn, "SELECT company_name FROM companies WHERE active = 1 ORDER BY id ASC LIMIT 1");
if ($cr && $crow = mysqli_fetch_assoc($cr)) {
    $company_name = strtoupper($crow['company_name']);
}

/* ── Load report ── */
$report = null;
$r = mysqli_query($conn, "SELECT * FROM daily_cheque_reports WHERE id=$rid LIMIT 1");
if ($r) $report = mysqli_fetch_assoc($r);
if (!$report) {
    echo '<p style="font-family:Arial;padding:40px;color:red;">Report not found.</p>'; exit;
}

/* ── Load snapshot items ── */
$snapshot_items = [];
$ir = mysqli_query($conn, "SELECT * FROM daily_cheque_report_items WHERE report_id=$rid ORDER BY cheque_no ASC");
if ($ir) while ($row = mysqli_fetch_assoc($ir)) $snapshot_items[] = $row;

if (!$snapshot_items) {
    echo '<p style="font-family:Arial;padding:40px;color:#888;">No cheques in this report.</p>'; exit;
}

/* ── Enrich with live data ── */
$cheque_ids = array_map(fn($r) => intval($r['cheque_id']), $snapshot_items);
$ids_sql    = implode(',', $cheque_ids);

$live_data = [];
if ($ids_sql) {
    $lr = mysqli_query($conn,
        "SELECT ch.id, COALESCE(ch.received_date, ip.payment_date) AS received_date, ch.status
         FROM cheques ch
         LEFT JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id
         WHERE ch.id IN ($ids_sql)");
    if ($lr) while ($row = mysqli_fetch_assoc($lr)) $live_data[$row['id']] = $row;
}

/* ── Fetch logs ── */
$logs_by_cheque = [];
if ($ids_sql) {
    $logr = mysqli_query($conn,
        "SELECT cheque_id, action, old_value, new_value, created_at
         FROM cheque_logs WHERE cheque_id IN ($ids_sql)
         ORDER BY cheque_id, created_at ASC");
    if ($logr) while ($row = mysqli_fetch_assoc($logr)) $logs_by_cheque[$row['cheque_id']][] = $row;
}

/* ── Cheque type logic ── */
function get_cheque_type($cheque_id, $current_status, $logs) {
    $cheque_logs = $logs[$cheque_id] ?? [];
    $st = strtolower(trim($current_status));
    if (in_array($st, ['returned', 'bounced'])) return 'RTN';
    $had_sent_back = false; $sent_back_time = null;
    foreach ($cheque_logs as $log) {
        if ($log['action'] === 'sent_back' || $log['new_value'] === 'sent_back') {
            $had_sent_back = true; $sent_back_time = $log['created_at'];
        }
    }
    if ($st === 'sent_back') return 'SB';
    if ($had_sent_back && $sent_back_time) {
        $received_date_updated = false;
        foreach ($cheque_logs as $log) {
            if ($log['created_at'] > $sent_back_time && $log['action'] === 'received_date_update') {
                $received_date_updated = true; break;
            }
        }
        return $received_date_updated ? 'SB-New Cheque' : 'SB-Old Cheque';
    }
    return 'DC';
}

/* ── Build grouped rows ── */
$grouped = [];
foreach ($snapshot_items as $item) {
    $cid         = intval($item['cheque_id']);
    $live        = $live_data[$cid] ?? null;
    $rec_date    = $live['received_date'] ?? $item['delivery_date'] ?? '';
    $curr_status = $live['status'] ?? $item['status'] ?? '';
    $chq_type    = get_cheque_type($cid, $curr_status, $logs_by_cheque);
    $key = ($rec_date && $rec_date !== '0000-00-00' && $rec_date !== '0000-00-00 00:00:00')
         ? date('Y-m-d', strtotime($rec_date)) : '0000-00-00';
    $grouped[$key][] = array_merge($item, [
        'received_date_live' => $rec_date,
        'status_live'        => $curr_status,
        'cheque_type'        => $chq_type,
    ]);
}
uksort($grouped, function($a, $b) {
    if ($a === '0000-00-00') return 1;
    if ($b === '0000-00-00') return -1;
    return strcmp($b, $a);
});
$type_order = ['DC'=>0,'SB-New Cheque'=>1,'SB-Old Cheque'=>2,'SB'=>3,'RTN'=>4];
foreach ($grouped as &$grp) {
    usort($grp, function($a, $b) use ($type_order) {
        $ta = $type_order[$a['cheque_type']] ?? 0;
        $tb = $type_order[$b['cheque_type']] ?? 0;
        return $ta !== $tb ? $ta - $tb : strcmp($a['cheque_no']??'', $b['cheque_no']??'');
    });
}
unset($grp);

/* ── Helpers ── */
function fmt_date($d) {
    if (!$d || $d === '0000-00-00' || str_starts_with($d,'0000')) return '—';
    return date('d/m/Y', strtotime($d));
}
function h($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES); }

$report_date = new DateTime($report['report_date']);
$total_items = count($snapshot_items);

/* ── Summary accumulators ── */
$summary = [
    'DC'           => ['count'=>0,'amount'=>0],
    'SB'           => ['count'=>0,'amount'=>0],
    'SB-New Cheque'=> ['count'=>0,'amount'=>0],
    'SB-Old Cheque'=> ['count'=>0,'amount'=>0],
    'RTN'          => ['count'=>0,'amount'=>0],
];
foreach ($grouped as $rows) {
    foreach ($rows as $item) {
        $sk = isset($summary[$item['cheque_type']]) ? $item['cheque_type'] : 'DC';
        $summary[$sk]['count']++;
        $summary[$sk]['amount'] += floatval($item['total_amount']);
    }
}
$sum_grand_count  = array_sum(array_column($summary, 'count'));
$sum_grand_amount = array_sum(array_column($summary, 'amount'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Cheque Report #<?= $rid ?> — <?= h($company_name) ?></title>
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

    .screen-bar {
        position: fixed; top: 0; left: 0; right: 0; z-index: 999;
        background: #111; padding: 5px 14px;
        display: flex; align-items: center; justify-content: space-between;
        gap: 8px; flex-wrap: wrap;
        border-bottom: 2px solid #333;
    }
    .screen-bar-title { color: #ddd; font-size: 10px; font-weight: 700; letter-spacing: .03em; }
    .screen-bar a, .screen-bar button {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 4px 11px; border-radius: 3px; font-size: 9.5px; font-weight: 700;
        cursor: pointer; font-family: Tahoma, Geneva, sans-serif; text-decoration: none; border: 1.5px solid #555;
        background: #fff; color: #111; transition: background .15s;
        white-space: nowrap;
    }
    .screen-bar a:hover, .screen-bar button:hover { background: #e5e5e5; }
    .btn-print { border-color: #111 !important; }

    .page-wrapper {
        margin-top: 44px; padding: 16px 10px 40px;
        display: flex; flex-direction: column; align-items: center;
    }
    .report-body {
        width: 210mm; background: #fff;
        box-shadow: 0 2px 18px rgba(0,0,0,.28);
        padding: 10mm 11mm;
    }
    .print-repeat-header { display: none; }
}

/* ════════════════════════════
   PRINT STYLES
════════════════════════════ */
@media print {
    @page { size: A4 portrait; margin: 9mm 10mm 9mm 10mm; }

    .screen-bar   { display: none !important; }
    .page-wrapper { margin: 0; padding: 0; }
    .report-body  { width: 100%; padding: 0; box-shadow: none; }

    #print-wrap         { width: 100%; border-collapse: collapse; }
    #print-wrap > thead { display: table-header-group; }
    #print-wrap > tbody { display: table-row-group; }

    #print-wrap > thead .print-repeat-header { display: table-row; }

    .group-block      { page-break-inside: auto; }
    .group-heading    { page-break-after: avoid; }
    .data-table       { page-break-inside: auto; border-collapse: collapse; }
    .data-table tr    { page-break-inside: avoid; page-break-after: auto; }
    .data-table thead { display: table-header-group; }

    .summary-section  { page-break-inside: avoid; }
    .sig-strip        { page-break-inside: avoid; }
}

/* ════════════════════════════
   FULL HEADER — page 1
════════════════════════════ */
.rpt-header {
    border-bottom: 2pt solid #000;
    padding-bottom: 4pt;
    margin-bottom: 3pt;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 6pt;
}
.rpt-company  { font-size: 12pt; font-weight: 900; letter-spacing: .1pt; line-height: 1.15; }
.rpt-subtitle { font-size: 7pt; color: #222; margin-top: 1pt; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }

/* ── Meta block (right side of header) ── */
.rpt-meta {
    text-align: right;
    font-size: 7pt;
    color: #222;
    line-height: 1.7;
}
.rpt-meta .meta-row {
    display: flex;
    align-items: baseline;
    justify-content: flex-end;
    gap: 4pt;
}
.rpt-meta .meta-label {
    font-size: 6.5pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #555;
    white-space: nowrap;
}
.rpt-meta .meta-value {
    font-size: 8pt;
    font-weight: 900;
    white-space: nowrap;
}
.rpt-meta .meta-divider {
    border-top: .75pt solid #aaa;
    margin: 2pt 0;
}

/* ── Remark ── */
.rpt-remark {
    border: 1pt solid #000;
    padding: 2pt 5pt;
    margin-bottom: 3pt;
    font-size: 7pt;
    font-weight: 700;
}

/* ── Legend ── */
.legend-box {
    border: 1pt solid #000;
    padding: 2pt 5pt;
    margin-bottom: 4pt;
    font-size: 6.5pt;
    display: flex;
    gap: 10pt;
    flex-wrap: wrap;
    align-items: center;
}
.legend-box strong { font-size: 6.5pt; text-transform: uppercase; letter-spacing: .05em; }
.legend-item { display: inline-flex; align-items: center; gap: 3pt; }
.leg-type    { font-weight: 900; font-size: 7pt; }

/* ── Group heading ── */
.group-heading {
    border: 1.5pt solid #000;
    border-bottom: none;
    padding: 2pt 5pt;
    font-size: 7pt;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .06em;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 5pt;
}
.gh-right { font-size: 6.5pt; font-weight: 700; }

/* ── Data table ── */
.data-table { width: 100%; border-collapse: collapse; font-size: 7pt; }

.data-table thead th {
    padding: 2pt 4pt;
    text-align: left;
    font-weight: 900;
    font-size: 6.5pt;
    color: #000;
    border: 1pt solid #000;
    white-space: nowrap;
    text-transform: uppercase;
    letter-spacing: .03em;
}
.data-table thead th.tr { text-align: right; }
.data-table thead th.tc { text-align: center; }

.data-table tbody td {
    padding: 2pt 4pt;
    color: #000;
    border: .75pt solid #000;
    vertical-align: middle;
}
.data-table tbody td.tr { text-align: right; }
.data-table tbody td.tc { text-align: center; }

/* Alternating rows — light underline only, no fill */
.data-table tbody tr:nth-child(even) td {
    border-top: .75pt solid #555;
    border-bottom: .75pt solid #555;
}

/* Subtotal */
.subtotal-row td {
    font-weight: 900;
    font-size: 7pt;
    border: 1pt solid #000;
    border-top: 1.5pt solid #000;
    padding: 2pt 4pt;
}
.subtotal-row td.tr { text-align: right; }

/* ── Type column — fixed width, no wrap, compact font ── */
.col-type {
    width: 58pt;       /* wide enough for "SB-Old Cheque" */
    min-width: 58pt;
    white-space: nowrap;
}
.col-type-cell {
    white-space: nowrap;
    font-size: 6.5pt;  /* slightly smaller so long labels fit cleanly */
    letter-spacing: 0;
}

/* ── Cheque No column — wider ── */
.col-chqno {
    min-width: 80pt;
    width: 80pt;
}

/* Type labels — no underline, no bold */
.t-dc     { font-weight: 400; text-decoration: none; }
.t-sb     { font-weight: 400; text-decoration: none; }
.t-sb-new { font-weight: 400; text-decoration: none; font-style: italic; }
.t-sb-old { font-weight: 400; text-decoration: none; font-style: italic; }
.t-rtn    { font-weight: 400; text-decoration: none; }

.mono { font-family: Tahoma, Geneva, sans-serif; font-weight: 400; }

/* SR code — normal weight */
.sr-code { font-weight: 400; font-size: 7pt; }

/* ── Summary ── */
.summary-section { margin-top: 6pt; }
.summary-title {
    font-size: 7pt;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .07em;
    border-bottom: 1.5pt solid #000;
    padding-bottom: 2pt;
    margin-bottom: 3pt;
}
.summary-table { width: 54%; border-collapse: collapse; font-size: 7.5pt; }
.summary-table th {
    padding: 2.5pt 6pt;
    font-size: 7pt;
    text-transform: uppercase;
    letter-spacing: .05em;
    border: 1pt solid #000;
    font-weight: 900;
    text-align: left;
}
.summary-table th.tr { text-align: right; }
.summary-table th.tc { text-align: center; }
.summary-table td   { padding: 2.5pt 6pt; border: .75pt solid #000; font-weight: 700; }
.summary-table td.tc { text-align: center; font-family: Tahoma, Geneva, sans-serif; font-weight: 800; }
.summary-table td.tr { text-align: right;  font-family: Tahoma, Geneva, sans-serif; font-weight: 800; }
.summary-table .grand-row td {
    border: 2pt solid #000;
    font-weight: 900;
    font-size: 8.5pt;
    padding: 3pt 6pt;
    border-top: 2pt solid #000;
}
.summary-table .grand-row td.tc { text-align: center; font-family: Tahoma, Geneva, sans-serif; }
.summary-table .grand-row td.tr { text-align: right;  font-family: Tahoma, Geneva, sans-serif; }

/* ── Signatures ── */
.sig-strip { width: 100%; border-collapse: collapse; margin-top: 12pt; }
.sig-strip td { width: 33.33%; padding: 0 10pt; vertical-align: bottom; text-align: center; }
.sig-strip td:first-child { padding-left: 0; }
.sig-strip td:last-child  { padding-right: 0; }
.sig-spacer { height: 18pt; }
.sig-line {
    border-top: 1.5pt solid #000;
    padding-top: 3pt;
    font-size: 7pt;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .05em;
}

/* ── Footer ── */
.page-footer {
    border-top: 1pt solid #000;
    padding-top: 2pt;
    margin-top: 6pt;
    display: flex;
    justify-content: space-between;
    font-size: 6.5pt;
    color: #444;
}
</style>
</head>
<body>

<!-- ── SCREEN TOOLBAR ── -->
<div class="screen-bar">
    <span class="screen-bar-title">&#128196; Cheque Report #<?= $rid ?> &mdash; <?= h($company_name) ?></span>
    <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <a href="daily_sent_cheques_view.php?id=<?= $rid ?>">&#8592; Detail View</a>
        <a href="daily_sent_cheques.php">&#9776; All Reports</a>
        <button class="btn-print" onclick="window.print()">&#128424; Print / Save PDF</button>
    </div>
</div>

<div class="page-wrapper">
<div class="report-body">

    <table id="print-wrap">

        <thead><tr><td style="padding:0;display:none;"></td></tr></thead>

        <tbody>
        <tr><td style="padding:0;">

            <!-- ── FULL HEADER — page 1 ── -->
            <div class="rpt-header">
                <div>
                    <div class="rpt-company"><?= h($company_name) ?></div>
                    <div class="rpt-subtitle">Cheque Details Report</div>
                </div>
                <!-- RIGHT META: Sent Date + Received Date groups + Printed -->
                <div class="rpt-meta">
                    <div class="meta-row">
                        <span class="meta-label">Sent Date:</span>
                        <span class="meta-value"><?= h($report_date->format('d/m/Y')) ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Report&nbsp;#:</span>
                        <span class="meta-value"><?= $rid ?></span>
                    </div>
                    <div class="meta-divider"></div>
                    <div class="meta-row">
                        <span class="meta-label">Total Cheques:</span>
                        <span class="meta-value"><?= $total_items ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Total Amount:</span>
                        <span class="meta-value">Rs.&nbsp;<?= number_format($sum_grand_amount, 2) ?></span>
                    </div>
                    <div class="meta-divider"></div>
                    <div class="meta-row">
                        <span class="meta-label">Printed:</span>
                        <span class="meta-value" style="font-size:7pt;font-weight:700;"><?= date('d/m/Y H:i') ?></span>
                    </div>
                </div>
            </div>

            <?php if (trim($report['remark'] ?? '')): ?>
            <div class="rpt-remark"><strong>Remark:</strong> <?= h($report['remark']) ?></div>
            <?php endif; ?>

            <!-- ── LEGEND ── -->
            <div class="legend-box">
                <strong>Types:</strong>
                <span class="legend-item"><span class="leg-type t-dc">DC</span> Daily Cheque</span>
                <span class="legend-item"><span class="leg-type t-sb">SB</span> Sent Back (unsettled)</span>
                <span class="legend-item"><span class="leg-type t-sb-new">SB-New Cheque</span> Settlement (new cheque)</span>
                <span class="legend-item"><span class="leg-type t-sb-old">SB-Old Cheque</span> Settlement (same cheque)</span>
                <span class="legend-item"><span class="leg-type t-rtn">RTN</span> Returned Cheque</span>
            </div>

            <!-- ── GROUPS ── -->
            <?php
            $g_serial = 0;
            foreach ($grouped as $rec_date_key => $rows):
                if ($rec_date_key === '0000-00-00') {
                    $date_label = 'Unknown Received Date';
                } else {
                    $gdt = new DateTime($rec_date_key);
                    $date_label = $gdt->format('d/m/Y') . ' — ' . $gdt->format('l');
                }
                $group_total = array_sum(array_column($rows, 'total_amount'));
            ?>
            <div class="group-block">
                <div class="group-heading">
                    <span>Received: <?= h($date_label) ?></span>
                    <span class="gh-right"><?= count($rows) ?> cheque<?= count($rows)!==1?'s':'' ?> &nbsp;|&nbsp; Rs.&nbsp;<?= number_format($group_total,2) ?></span>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="tc" style="width:14pt;">#</th>
                            <th class="tc col-type">Type</th>
                            <th style="width:34pt;">SR</th>
                            <th class="tc" style="width:46pt;">Recv. Date</th>
                            <th class="tc" style="width:46pt;">Chq. Date</th>
                            <th class="col-chqno">Cheque No.</th>
                            <th style="min-width:70pt;">Bank</th>
                            <th class="tr" style="width:58pt;">Amount (Rs.)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $ri => $item):
                        $g_serial++;
                        $amt      = floatval($item['total_amount']);
                        $chq_type = $item['cheque_type'];
                        $rec_disp = fmt_date($item['received_date_live'] ?? '');
                        $chq_disp = fmt_date($item['cheque_date'] ?? '');
                        $bank_display = trim($item['bank_name'] ?? '') ?: (trim($item['bank_code'] ?? '') ?: '—');
                        $type_class = match($chq_type) {
                            'SB'            => 't-sb',
                            'SB-New Cheque' => 't-sb-new',
                            'SB-Old Cheque' => 't-sb-old',
                            'RTN'           => 't-rtn',
                            default         => 't-dc',
                        };
                    ?>
                        <tr>
                            <td class="tc" style="font-size:6pt;color:#555;"><?= $g_serial ?></td>
                            <td class="tc col-type"><span class="col-type-cell <?= $type_class ?>"><?= h($chq_type ?: 'DC') ?></span></td>
                            <td><span class="sr-code"><?= h($item['sr_code'] ?? '—') ?></span></td>
                            <td class="tc mono" style="font-size:6.5pt;"><?= h($rec_disp) ?></td>
                            <td class="tc mono" style="font-size:6.5pt;"><?= h($chq_disp) ?></td>
                            <td class="col-chqno"><span class="mono" style="font-size:7pt;"><?= h($item['cheque_no'] ?? '—') ?></span></td>
                            <td style="font-size:7pt;font-weight:400;"><?= h($bank_display) ?></td>
                            <td class="tr"><span class="mono"><?= number_format($amt,2) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="subtotal-row">
                            <td colspan="7" style="font-style:italic;">Sub-Total — <?= count($rows) ?> cheque<?= count($rows)!==1?'s':'' ?></td>
                            <td class="tr"><span class="mono"><?= number_format($group_total,2) ?></span></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endforeach; ?>

            <!-- ── SUMMARY ── -->
            <div class="summary-section">
                <div class="summary-title">Summary</div>
                <?php
                $sum_labels = [
                    'DC'            => 'DC — Daily Cheques',
                    'SB'            => 'SB — Sent Back',
                    'SB-New Cheque' => 'SB — New Cheques',
                    'SB-Old Cheque' => 'SB — Old Cheques',
                    'RTN'           => 'RTN — Returned',
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
                        $cnt = $summary[$key]['count'];
                        $amt = $summary[$key]['amount'];
                        if (!$cnt) continue;
                    ?>
                        <tr>
                            <td><?= $label ?></td>
                            <td class="tc"><?= $cnt ?></td>
                            <td class="tr"><?= number_format($amt, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="grand-row">
                            <td><strong>Grand Total</strong></td>
                            <td class="tc"><strong><?= $sum_grand_count ?></strong></td>
                            <td class="tr"><strong>Rs.&nbsp;<?= number_format($sum_grand_amount, 2) ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

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
                <span><?= h($company_name) ?> &mdash; Confidential</span>
                <span>Report #<?= $rid ?> &nbsp;|&nbsp; Sent: <?= h($report_date->format('d/m/Y')) ?> &nbsp;|&nbsp; Generated: <?= date('d/m/Y H:i') ?></span>
            </div>

        </td></tr>
        </tbody>

    </table><!-- /#print-wrap -->

</div><!-- /.report-body -->
</div><!-- /.page-wrapper -->

<script>
(function () {
    const params = new URLSearchParams(location.search);
    if (params.get('print') === '1') {
        window.addEventListener('load', () => setTimeout(() => window.print(), 400));
    }
})();
</script>
</body>
</html>