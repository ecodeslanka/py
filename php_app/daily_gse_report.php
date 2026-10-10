<?php
/* ══════════════════════════════════════════════════════════════════
   unloading_shortage_report.php
   Daily Good Shortage / Excess Report  —  YELO Logistics
   ══════════════════════════════════════════════════════════════════ */
error_reporting(0);
ini_set('display_errors', 0);

include 'config.php';
include 'header.php';

/* ── Date range defaults ──────────────────────────────────────── */
$today     = date('Y-m-d');
$date_from = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : $today;
$date_to   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? $_GET['date_to']   : $today;

$df = mysqli_real_escape_string($conn, $date_from);
$dt = mysqli_real_escape_string($conn, $date_to);

/* ── Ensure is_prev_day column exists ─────────────────────────── */
$_cpk = mysqli_query($conn, "SHOW COLUMNS FROM unloading_data LIKE 'is_prev_day'");
if (!$_cpk || mysqli_num_rows($_cpk) === 0) {
    mysqli_query($conn, "ALTER TABLE unloading_data
        ADD COLUMN `is_prev_day` TINYINT(1) NOT NULL DEFAULT 0 AFTER delivery_date");
}

/* ════════════════════════════════════════════════════════════════
   QUERIES
   ════════════════════════════════════════════════════════════════ */
$short_sql = "
    SELECT ud.*, (ud.adj_qty_good_units + ud.adj_qty_damage) AS total_qty
    FROM   unloading_data ud
    WHERE  ud.short_excess IS NOT NULL
      AND  ud.short_excess < 0
      AND  ud.delivery_date BETWEEN '$df' AND '$dt'
    ORDER BY ud.is_prev_day ASC, ud.delivery_person_name ASC, ud.sku_code ASC";

$excess_sql = "
    SELECT ud.*, (ud.adj_qty_good_units + ud.adj_qty_damage) AS total_qty
    FROM   unloading_data ud
    WHERE  ud.short_excess IS NOT NULL
      AND  ud.short_excess > 0
      AND  ud.delivery_date BETWEEN '$df' AND '$dt'
    ORDER BY ud.is_prev_day ASC, ud.delivery_person_name ASC, ud.sku_code ASC";

function fetch_rows($conn, $sql) {
    $r = mysqli_query($conn, $sql);
    $rows = [];
    if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    return $rows;
}

$all_short  = fetch_rows($conn, $short_sql);
$all_excess = fetch_rows($conn, $excess_sql);

$short_current  = array_values(array_filter($all_short,  fn($r) => empty($r['is_prev_day'])));
$short_prev     = array_values(array_filter($all_short,  fn($r) => !empty($r['is_prev_day'])));
$excess_current = array_values(array_filter($all_excess, fn($r) => empty($r['is_prev_day'])));
$excess_prev    = array_values(array_filter($all_excess, fn($r) => !empty($r['is_prev_day'])));

$prev_day_rows = array_merge($short_prev, $excess_prev);

$tot_short_value  = array_sum(array_map(fn($r) => abs(floatval($r['short_excess'])) * floatval($r['tur']), $short_current));
$tot_excess_value = array_sum(array_map(fn($r) => abs(floatval($r['short_excess'])) * floatval($r['tur']), $excess_current));
$tot_prev_value   = array_sum(array_map(fn($r) => abs(floatval($r['short_excess'])) * floatval($r['tur']), $prev_day_rows));

$imp_res = mysqli_query($conn,
    "SELECT id, filename, delivery_date FROM unloading_summary_imports ORDER BY id DESC LIMIT 100");
$imports = [];
if ($imp_res) while ($imp = mysqli_fetch_assoc($imp_res)) $imports[] = $imp;

function fn2($v) { return number_format(floatval($v), 2); }

$print_date  = date('d/m/Y H:i');
$range_label = $date_from === $date_to
    ? date('d M Y', strtotime($date_from))
    : date('d M Y', strtotime($date_from)) . ' — ' . date('d M Y', strtotime($date_to));
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
body{font-family:'Inter',sans-serif;}

/* ── Screen styles ── */
.filter-bar{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:16px 20px;margin-bottom:18px;display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;}
.fb-group{display:flex;flex-direction:column;gap:4px;}
.fb-label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;}
.fb-input{padding:8px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;color:#1f2937;outline:none;transition:border-color .2s;}
.fb-input:focus{border-color:#7c3aed;}
.btn-filter{padding:9px 20px;background:#7c3aed;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;white-space:nowrap;}
.btn-filter:hover{background:#6d28d9;}
.btn-print{padding:9px 20px;background:#1e40af;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;}
.btn-print:hover{background:#1d4ed8;}
.btn-pdf{padding:9px 20px;background:#dc2626;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;text-decoration:none;}
.btn-pdf:hover{background:#b91c1c;}
.btn-print-bw{padding:9px 20px;background:#374151;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;}
.btn-print-bw:hover{background:#1f2937;}

.summary-banner{display:flex;gap:0;border:1px solid #e5e5e5;border-radius:8px;overflow:hidden;margin-bottom:18px;}
.sb-cell{flex:1;padding:12px 16px;text-align:center;border-right:1px solid #e5e5e5;}
.sb-cell:last-child{border-right:none;}
.sb-label{font-size:10px;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;font-weight:600;}
.sb-value{font-size:16px;font-weight:800;}
.sb-short{background:#fff0f0;} .sb-short  .sb-value{color:#dc2626;}
.sb-excess{background:#f0fdf4;} .sb-excess .sb-value{color:#16a34a;}
.sb-prev{background:#fefce8;} .sb-prev   .sb-value{color:#d97706;}
.sb-total{background:#f5f3ff;} .sb-total  .sb-value{color:#7c3aed;}

.report-wrap{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:0;overflow:hidden;margin-bottom:30px;}
.rpt-header{padding:18px 24px 12px;border-bottom:2px solid #222;}
.rpt-title-red{color:#dc2626;font-size:11px;font-weight:700;letter-spacing:.5px;margin-bottom:2px;}
.rpt-company{font-size:15px;font-weight:700;color:#1f2937;margin-bottom:1px;}
.rpt-subtitle{font-size:13px;font-weight:600;color:#1f2937;margin-bottom:1px;}
.rpt-meta{font-size:12px;color:#374151;margin-bottom:1px;}
.rpt-header-grid{display:flex;justify-content:space-between;align-items:flex-end;}
.rpt-header-right{text-align:right;font-size:11px;color:#6b7280;}

.section-wrap{display:flex;}
.section-label-cell{width:22px;min-width:22px;display:flex;align-items:center;justify-content:center;background:#f9fafb;border-right:1px solid #e5e5e5;}
.section-label-text{writing-mode:vertical-rl;transform:rotate(180deg);font-size:9px;font-weight:700;color:#374151;letter-spacing:1px;text-transform:uppercase;white-space:nowrap;}
.section-content{flex:1;min-width:0;}
.section-heading{padding:7px 14px;border-bottom:1px solid #e5e5e5;border-top:2px solid #b45309;font-size:12px;font-weight:700;color:#92400e;letter-spacing:.3px;}
.section-heading.short-head{background:#fff0f0;border-top-color:#dc2626;color:#991b1b;}
.section-heading.excess-head{background:#f0fdf4;border-top-color:#16a34a;color:#166534;}
.section-heading.prevday-head{background:#fefce8;border-top-color:#d97706;color:#92400e;}

.rpt-table{width:100%;border-collapse:collapse;font-size:11px;}
.rpt-table thead th{padding:7px 6px;text-align:center;font-weight:700;font-size:10px;color:#374151;border:1px solid #d5d5d5;white-space:nowrap;background:#f0f0f0;}
.rpt-table thead th.left{text-align:left;}
.rpt-table tbody td{padding:5px 6px;border:1px solid #e5e5e5;color:#1f2937;font-size:11px;vertical-align:middle;}
.rpt-table tbody td.num{text-align:right;}
.rpt-table tbody td.ctr{text-align:center;}
.rpt-table tbody tr:nth-child(even) td{background:#fafafa;}
.rpt-table tbody tr:hover td{background:#f0f4ff;}
.rpt-table tbody tr.prevday-tr td{background:#fefce8 !important;}
.rpt-table tbody tr.prevday-tr:hover td{background:#fef9c3 !important;}
.rpt-table tfoot td{padding:7px 6px;border:1px solid #d5d5d5;font-weight:700;font-size:11px;color:#1f2937;background:#f0f0f0;}
.rpt-table tfoot td.num{text-align:right;}
.no-data-row td{text-align:center;color:#9ca3af;font-style:italic;padding:14px!important;}

.val-short{color:#dc2626;font-weight:700;}
.val-excess{color:#16a34a;font-weight:700;}
.val-charge{color:#dc2626;font-weight:600;}
.val-absorb{color:#0369a1;font-weight:600;}
.prevday-badge{font-size:9px;background:#fef08a;color:#854d0e;border:1px solid #fcd34d;
               border-radius:10px;padding:1px 5px;font-weight:700;display:inline-block;
               margin-left:4px;white-space:nowrap;}

.prevday-divider{padding:10px 14px 6px;font-size:14px;font-weight:800;color:#92400e;
                 border-bottom:1px solid #fde68a;border-top:2px solid #d97706;
                 background:#fefce8;display:flex;align-items:center;gap:8px;}

.sig-section{padding:20px 24px 14px;border-top:1px solid #e5e5e5;display:flex;justify-content:flex-end;gap:60px;align-items:flex-end;}
.sig-block{text-align:center;min-width:160px;}
.sig-line{border-top:1px solid #555;margin-bottom:4px;margin-top:40px;}
.sig-label{font-size:11px;color:#374151;font-weight:600;}
.page-sep{border:none;border-top:2px dashed #e5e5e5;margin:0;}

/* ══════════════════════════════════════════════════════════════
   BLACK & WHITE PRINT — A4 LANDSCAPE
   ══════════════════════════════════════════════════════════════ */
@media print {

    /* A4 landscape, 8mm margins */
    @page { size: A4 landscape; margin: 8mm; }

    /* ── 1. Hide EVERYTHING on the page except #reportDoc ── */
    body > *                    { display: none !important; }
    body > #reportDoc,
    body > * > #reportDoc       { display: block !important; }

    .page-wrapper > *:not(:has(#reportDoc)),
    .main-content > *:not(#reportDoc),
    .content-area > *:not(#reportDoc) { display: none !important; }

    /* Explicitly hide all UI elements */
    nav, aside, header, footer,
    .sidebar, .navbar, .topbar, .menu,
    .page-header, .filter-bar,
    .btn-print, .btn-print-bw, .btn-pdf, .btn-filter,
    .summary-banner, .section-label-cell,
    .page-sep,
    div[style*="height:30px"],
    div[style*="height:10px"],
    div[style*="height:6px"]    { display: none !important; }

    /* ── 2. Reset body & fonts ── */
    html, body {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        font-family: Arial, Helvetica, sans-serif !important;
        font-size: 8pt !important;
        color: #000 !important;
        width: 100% !important;
    }

    /* ── 3. Strip ALL colour and decoration from every element ── */
    * {
        color: #000 !important;
        background: #fff !important;
        background-color: #fff !important;
        background-image: none !important;
        box-shadow: none !important;
        text-shadow: none !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    /* ── 4. Report wrapper — full width, no decoration ── */
    #reportDoc {
        display: block !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        border-radius: 0 !important;
        overflow: visible !important;
        background: #fff !important;
    }

    /* ── 5. Report header ── */
    .rpt-header {
        display: block !important;
        width: 100% !important;
        padding: 0 0 2mm 0 !important;
        margin: 0 0 3mm 0 !important;
        border: none !important;
        border-bottom: 1.5pt solid #000 !important;
        background: #fff !important;
    }
    .rpt-header-grid {
        display: flex !important;
        justify-content: space-between !important;
        align-items: flex-end !important;
    }
    .rpt-title-red   { font-size: 7pt !important;  font-weight: bold !important; }
    .rpt-company     { font-size: 13pt !important; font-weight: bold !important; line-height: 1.1 !important; margin: 0 !important; }
    .rpt-subtitle    { font-size: 9pt !important;  font-weight: bold !important; margin: 1px 0 !important; }
    .rpt-meta        { font-size: 7.5pt !important; margin: 1px 0 !important; }
    .rpt-header-right{ font-size: 8pt !important; text-align: right !important; }

    /* ── 6. Section wrapper — block, no flex sidebar ── */
    .section-wrap {
        display: block !important;
        width: 100% !important;
        margin: 0 0 4mm 0 !important;
        padding: 0 !important;
        border: none !important;
        overflow: visible !important;
    }
    .section-content {
        display: block !important;
        width: 100% !important;
    }

    /* Section heading — bold text, top/bottom rule, no colour */
    .section-heading,
    .section-heading.short-head,
    .section-heading.excess-head,
    .section-heading.prevday-head {
        display: block !important;
        background: #fff !important;
        color: #000 !important;
        border: none !important;
        border-top: 1.5pt solid #000 !important;
        border-bottom: 0.5pt solid #000 !important;
        font-size: 7.5pt !important;
        font-weight: bold !important;
        padding: 1.5px 0 !important;
        margin: 0 !important;
    }

    /* ── 7. Overflow scroll wrapper — MUST be visible for print ── */
    .section-content > div[style*="overflow-x"] {
        overflow: visible !important;
        width: 100% !important;
    }

    /* ── 8. THE TABLE — pure B&W, fit to page width ── */
    .rpt-table {
        display: table !important;
        width: 100% !important;
        max-width: 100% !important;
        border-collapse: collapse !important;
        table-layout: fixed !important;
        font-size: 6.8pt !important;
        margin: 0 !important;
        page-break-inside: auto !important;
    }

    /* Column widths — tuned for A4 landscape 277mm usable */
    .rpt-table col,
    .rpt-table thead th:nth-child(1)  { width: 12% !important; }
    .rpt-table thead th:nth-child(2)  { width: 8%  !important; }
    .rpt-table thead th:nth-child(3)  { width: 16% !important; }
    .rpt-table thead th:nth-child(4)  { width: 5%  !important; }
    .rpt-table thead th:nth-child(5)  { width: 5%  !important; }
    .rpt-table thead th:nth-child(6)  { width: 5%  !important; }
    .rpt-table thead th:nth-child(7)  { width: 5%  !important; }
    .rpt-table thead th:nth-child(8)  { width: 5%  !important; }
    .rpt-table thead th:nth-child(9)  { width: 5%  !important; }
    .rpt-table thead th:nth-child(10) { width: 5%  !important; }
    .rpt-table thead th:nth-child(11) { width: 6%  !important; }
    .rpt-table thead th:nth-child(12) { width: 7%  !important; }
    .rpt-table thead th:nth-child(13) { width: 5%  !important; }
    .rpt-table thead th:nth-child(14) { width: 5%  !important; }
    .rpt-table thead th:nth-child(15) { width: 6%  !important; }

    .rpt-table thead {
        display: table-header-group !important;
    }
    .rpt-table thead th {
        display: table-cell !important;
        padding: 2px 2px !important;
        font-size: 6.2pt !important;
        font-weight: bold !important;
        background: #fff !important;
        border: 0.5pt solid #000 !important;
        text-align: center !important;
        white-space: normal !important;
        line-height: 1.2 !important;
        word-break: break-word !important;
    }

    .rpt-table tbody tr { page-break-inside: avoid !important; }
    .rpt-table tbody td {
        display: table-cell !important;
        padding: 1.5px 2px !important;
        font-size: 6.8pt !important;
        border: 0.4pt solid #000 !important;
        background: #fff !important;
        vertical-align: middle !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        max-width: 0 !important;
    }

    /* No row stripes, no colours */
    .rpt-table tbody tr:nth-child(even) td,
    .rpt-table tbody tr:nth-child(odd) td,
    .rpt-table tbody tr.prevday-tr td { background: #fff !important; }

    /* Totals footer */
    .rpt-table tfoot td {
        display: table-cell !important;
        padding: 2px !important;
        font-size: 6.8pt !important;
        font-weight: bold !important;
        background: #fff !important;
        border: 0.5pt solid #000 !important;
        border-top: 1pt solid #000 !important;
    }

    .rpt-table td.num,
    .rpt-table tfoot td.num { text-align: right !important; }

    .val-short, .val-excess, .val-charge, .val-absorb { font-weight: bold !important; }

    .prevday-badge {
        background: #fff !important;
        border: 0.4pt solid #000 !important;
        border-radius: 0 !important;
        font-size: 5.5pt !important;
        padding: 0 2px !important;
    }

    .no-data-row td { font-size: 7pt !important; padding: 3px !important; }

    /* ── 9. PAGE BREAK RULES ──
       Page 1: current-day sections  →  force new page AFTER
       Page 2: previous-day sections start fresh
    ── */
    .page1-content {
        page-break-after: always !important;
        break-after: page !important;
        display: block !important;
    }

    /* When no prev-day data: suppress the page-break-after so signature
       stays on same page as the tables */
    .page1-content.page1-no-break {
        page-break-after: avoid !important;
        break-after: avoid !important;
    }

    /* page2-break just ensures block display; page break already
       handled by page1-content's break-after */
    .page2-break {
        display: block !important;
        page-break-before: always !important;
        break-before: page !important;
    }

    /* Previous-day divider bar */
    .prevday-divider {
        display: block !important;
        background: #fff !important;
        color: #000 !important;
        border: none !important;
        border-top: 1pt solid #000 !important;
        border-bottom: 0.5pt solid #000 !important;
        font-size: 7pt !important;
        font-weight: bold !important;
        padding: 2px 0 !important;
        margin: 0 0 2mm 0 !important;
    }

    /* ── 10. Signatures ── */
    .sig-section {
        display: flex !important;
        justify-content: flex-end !important;
        gap: 30mm !important;
        padding: 8mm 0 0 0 !important;
        border: none !important;
        border-top: 0.5pt solid #000 !important;
        background: #fff !important;
    }
    .sig-block { min-width: 50mm !important; text-align: center !important; }
    .sig-line  { border: none !important; border-top: 0.8pt solid #000 !important; margin: 14mm 0 2px 0 !important; }
    .sig-label { font-size: 7.5pt !important; font-weight: bold !important; }

    /* Hide "No previous-day" notice completely in print */
    .no-prevday-notice { display: none !important; }

    /* Signature placed right after last table */
    .sig-after-table { margin-top: 6mm !important; }

    /* Spacer divs — collapse them */
    .print-spacer { display: none !important; height: 0 !important; }
}
</style>

<!-- ── Page Header ────────────────────────────────────────────── -->
<div class="page-header" style="margin-bottom:14px;">
    <h2 class="page-title" style="display:flex;align-items:center;gap:8px;">
        <i class="fa-solid fa-file-lines" style="color:#dc2626;"></i>
        Daily Good Shortage / Excess Report
    </h2>
    <p class="page-subtitle">YELO Logistics — Unloading Verification Report</p>
</div>

<!-- ── Filter Bar ─────────────────────────────────────────────── -->
<form method="GET" action="">
<div class="filter-bar">
    <div class="fb-group">
        <label class="fb-label">Delivery Date From</label>
        <input type="date" class="fb-input" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
    </div>
    <div class="fb-group">
        <label class="fb-label">Delivery Date To</label>
        <input type="date" class="fb-input" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
    </div>
    <button type="submit" class="btn-filter">
        <i class="fa-solid fa-filter"></i> Filter
    </button>
    <button type="button" class="btn-print-bw" onclick="printReport()">
        <i class="fa-solid fa-print"></i> Print B&amp;W (A4 Landscape)
    </button>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>" class="btn-pdf">
        <i class="fa-solid fa-file-pdf"></i> Export PDF
    </a>
</div>
</form>

<script>
function printReport() {
    var content = document.getElementById('reportDoc');
    if (!content) { alert('Report not found.'); return; }

    var pw = window.open('', '_blank', 'width=1200,height=800');
    pw.document.open();
    pw.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8">');
    pw.document.write('<title>YELO Logistics — Shortage/Excess Report</title>');
    pw.document.write('<style>');
    pw.document.write(`
        @page { size: A4 landscape; margin: 8mm; }

        * {
            margin: 0; padding: 0; box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8pt;
            color: #000;
            background: #fff;
        }

        /* ── Header ── */
        .rpt-header {
            padding: 0 0 2mm 0;
            margin-bottom: 3mm;
            border-bottom: 1.5pt solid #000;
        }
        .rpt-header-grid { display: flex; justify-content: space-between; align-items: flex-end; }
        .rpt-title-red   { font-size: 7pt; font-weight: bold; }
        .rpt-company     { font-size: 13pt; font-weight: bold; line-height: 1.1; }
        .rpt-subtitle    { font-size: 9pt; font-weight: bold; margin: 1px 0; }
        .rpt-meta        { font-size: 7.5pt; margin: 1px 0; }
        .rpt-header-right{ font-size: 8pt; text-align: right; }

        /* ── Section ── */
        .section-wrap    { display: block; width: 100%; margin: 0 0 4mm 0; }
        .section-label-cell { display: none; }
        .section-content { display: block; width: 100%; }
        .section-heading,
        .section-heading.short-head,
        .section-heading.excess-head,
        .section-heading.prevday-head {
            display: block;
            background: #fff;
            color: #000;
            border: none;
            border-top: 1.5pt solid #000;
            border-bottom: 0.5pt solid #000;
            font-size: 7.5pt;
            font-weight: bold;
            padding: 2px 0;
            margin: 0;
        }

        /* ── PAGE 1 CONTENT — forces page break AFTER ── */
        .page1-content {
            page-break-after: always;
            break-after: page;
            display: block;
        }

        /* Suppress break when no prev-day data */
        .page1-content.page1-no-break {
            page-break-after: avoid;
            break-after: avoid;
        }

        /* ── PAGE 2 — starts on a new page ── */
        .page2-break {
            page-break-before: always;
            break-before: page;
            display: block;
        }

        /* ── Table ── */
        .rpt-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 6.8pt;
        }
        .rpt-table thead th:nth-child(1)  { width: 12%; }
        .rpt-table thead th:nth-child(2)  { width: 8%; }
        .rpt-table thead th:nth-child(3)  { width: 16%; }
        .rpt-table thead th:nth-child(4)  { width: 5%; }
        .rpt-table thead th:nth-child(5)  { width: 5%; }
        .rpt-table thead th:nth-child(6)  { width: 5%; }
        .rpt-table thead th:nth-child(7)  { width: 5%; }
        .rpt-table thead th:nth-child(8)  { width: 5%; }
        .rpt-table thead th:nth-child(9)  { width: 5%; }
        .rpt-table thead th:nth-child(10) { width: 5%; }
        .rpt-table thead th:nth-child(11) { width: 6%; }
        .rpt-table thead th:nth-child(12) { width: 7%; }
        .rpt-table thead th:nth-child(13) { width: 5%; }
        .rpt-table thead th:nth-child(14) { width: 5%; }
        .rpt-table thead th:nth-child(15) { width: 6%; }

        .rpt-table thead th {
            padding: 2px 2px;
            font-size: 6.2pt;
            font-weight: bold;
            background: #fff;
            border: 0.5pt solid #000;
            text-align: center;
            white-space: normal;
            line-height: 1.2;
            word-break: break-word;
        }
        .rpt-table tbody td {
            padding: 1.5px 2px;
            font-size: 6.8pt;
            border: 0.4pt solid #000;
            background: #fff;
            vertical-align: middle;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .rpt-table tbody tr:nth-child(even) td,
        .rpt-table tbody tr:nth-child(odd) td,
        .rpt-table tbody tr.prevday-tr td { background: #fff; }

        .rpt-table tbody tr { page-break-inside: avoid; }
        .rpt-table tfoot td {
            padding: 2px;
            font-size: 6.8pt;
            font-weight: bold;
            background: #fff;
            border: 0.5pt solid #000;
            border-top: 1pt solid #000;
        }
        .rpt-table td.num,
        .rpt-table tfoot td.num { text-align: right; }

        .val-short, .val-excess, .val-charge, .val-absorb { font-weight: bold; }

        .prevday-badge {
            background: #fff;
            border: 0.4pt solid #000;
            border-radius: 0;
            font-size: 5.5pt;
            padding: 0 2px;
        }
        .no-data-row td { font-size: 7pt; padding: 3px; text-align: center; font-style: italic; }

        /* Hide no-prev-day notice in print window */
        .no-prevday-notice { display: none; }

        /* Spacer divs — hide in print */
        .print-spacer { display: none; height: 0; }
        .page-sep     { display: none; }

        /* ── Prev-day divider ── */
        .prevday-divider {
            display: block;
            background: #fff;
            color: #000;
            border-top: 1pt solid #000;
            border-bottom: 0.5pt solid #000;
            font-size: 7pt;
            font-weight: bold;
            padding: 2px 0;
            margin: 0 0 2mm 0;
        }
        .prevday-divider i { display: none; }

        /* ── Signatures ── */
        .sig-section {
            display: flex;
            justify-content: flex-end;
            gap: 30mm;
            padding: 8mm 0 0 0;
            border-top: 0.5pt solid #000;
        }
        .sig-block  { min-width: 50mm; text-align: center; }
        .sig-line   { border: none; border-top: 0.8pt solid #000; margin: 14mm 0 2px 0; }
        .sig-label  { font-size: 7.5pt; font-weight: bold; }

        /* Hide "No previous-day" notice in print window */
        .no-prevday-notice { display: none; }

        /* Signature after last table */
        .sig-after-table { margin-top: 6mm; }

        /* Hide summary banner, UI spacers */
        .summary-banner { display: none; }
        div[style*="height:10px"],
        div[style*="height:6px"] { height: 0 !important; margin: 0 !important; }
    `);
    pw.document.write('</style></head><body>');

    /* Clone the report HTML — strip overflow:auto from scroll wrappers */
    var clone = content.cloneNode(true);
    clone.querySelectorAll('[style*="overflow-x"]').forEach(function(el){
        el.style.overflowX = 'visible';
        el.style.width = '100%';
    });
    /* Remove any inline script tags from clone */
    clone.querySelectorAll('script').forEach(function(s){ s.remove(); });

    pw.document.write(clone.innerHTML);
    pw.document.write('</body></html>');
    pw.document.close();

    pw.onload = function(){ pw.focus(); pw.print(); };
    setTimeout(function(){ pw.focus(); pw.print(); }, 600);
}
</script>

<?php
/* ═══════════════════════════════════════════════════════════════
   PDF EXPORT
   ═══════════════════════════════════════════════════════════════ */
if (isset($_GET['export']) && $_GET['export'] === 'pdf'):
    ob_end_clean();
    $report_data = [
        'company'        => 'YELO Logistics',
        'title'          => 'Daily Good Shortage/Excess Report',
        'date_range'     => $range_label,
        'print_time'     => $print_date,
        'short_current'  => $short_current,
        'excess_current' => $excess_current,
        'short_prev'     => $short_prev,
        'excess_prev'    => $excess_prev,
    ];
    $tmp_json = tempnam(sys_get_temp_dir(), 'rpt_') . '.json';
    $tmp_pdf  = tempnam(sys_get_temp_dir(), 'rpt_') . '.pdf';
    file_put_contents($tmp_json, json_encode($report_data, JSON_UNESCAPED_UNICODE));

    $py_script = <<<'PYEOF'
import sys, json
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib import colors
from reportlab.lib.units import mm
from reportlab.platypus import (SimpleDocTemplate, Table, TableStyle,
                                  Paragraph, Spacer, HRFlowable, PageBreak)
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.enums import TA_LEFT, TA_CENTER, TA_RIGHT

json_path = sys.argv[1]
out_path  = sys.argv[2]

with open(json_path, encoding='utf-8') as f:
    d = json.load(f)

doc = SimpleDocTemplate(out_path,
    pagesize=landscape(A4),
    leftMargin=10*mm, rightMargin=10*mm,
    topMargin=10*mm, bottomMargin=10*mm)

RED    = colors.HexColor('#dc2626')
GREEN  = colors.HexColor('#16a34a')
AMBER  = colors.HexColor('#d97706')
LTGRAY = colors.HexColor('#f0f0f0')
DKGRAY = colors.HexColor('#374151')
LYEL   = colors.HexColor('#fefce8')

def sty(name, **kw):
    return ParagraphStyle(name, **kw)

hdr_sty  = sty('H', fontSize=8,   fontName='Helvetica-Bold', textColor=DKGRAY, alignment=TA_CENTER, leading=10)
cell_sty = sty('C', fontSize=7.5, fontName='Helvetica',      textColor=DKGRAY, alignment=TA_LEFT,   leading=9)
num_sty  = sty('N', fontSize=7.5, fontName='Helvetica',      textColor=DKGRAY, alignment=TA_RIGHT,  leading=9)
red_sty  = sty('R', fontSize=7.5, fontName='Helvetica-Bold', textColor=RED,    alignment=TA_RIGHT,  leading=9)
grn_sty  = sty('G', fontSize=7.5, fontName='Helvetica-Bold', textColor=GREEN,  alignment=TA_RIGHT,  leading=9)
tot_sty  = sty('T', fontSize=8,   fontName='Helvetica-Bold', textColor=DKGRAY, alignment=TA_RIGHT,  leading=10)

def n(v, dec=2):
    try:    return f"{float(v):,.{dec}f}"
    except: return '-'

COLS = ['Delivery Person','SKU Code','SKU Description','TUR','MRP',
        'Sys Qty\n(Good)','Sys Qty\n(Damage)','Total\nQty',
        'Act Qty\n(Good)','Act Qty\n(Damage)',
        'Short /\nExcess','Value',
        'Charge\nto Emp','Absorb\nby Co.','Variance']
COL_W = [c*mm for c in [30,22,52,16,16,16,18,14,16,18,16,18,18,18,16]]

def make_data_row(r):
    tur  = float(r.get('tur',0) or 0)
    se   = float(r.get('short_excess',0) or 0)
    val  = abs(se)*tur
    chg  = float(r.get('charge_to_employee',0) or 0)
    abso = float(r.get('absorb_by_company',0)  or 0)
    vari = float(r.get('pay_variance',0) or 0)
    tq   = float(r.get('adj_qty_good_units',0) or 0)+float(r.get('adj_qty_damage',0) or 0)
    aq   = float(r.get('actual_qty',   r.get('adj_qty_good_units',0)) or 0)
    adq  = float(r.get('actual_damage_qty', r.get('adj_qty_damage',0)) or 0)
    se_p = Paragraph(('+' if se>0 else '')+n(se), grn_sty if se>0 else red_sty)
    return [
        Paragraph(str(r.get('delivery_person_name','') or ''), cell_sty),
        Paragraph(str(r.get('sku_code','') or ''), cell_sty),
        Paragraph(str(r.get('sku_desc','') or '')[:42], cell_sty),
        Paragraph(n(tur), num_sty),
        Paragraph(n(r.get('mrp',0)), num_sty),
        Paragraph(n(r.get('adj_qty_good_units',0)), num_sty),
        Paragraph(n(r.get('adj_qty_damage',0)),     num_sty),
        Paragraph(n(tq),  num_sty),
        Paragraph(n(aq),  num_sty),
        Paragraph(n(adq), num_sty),
        se_p,
        Paragraph(n(val), num_sty),
        Paragraph(n(chg)  if chg  > 0 else '-', red_sty if chg>0 else num_sty),
        Paragraph(n(abso) if abso > 0 else '-', num_sty),
        Paragraph(n(vari) if vari != 0 else '-', num_sty),
    ]

def make_total_row(rows, label='Total'):
    tv = sum(abs(float(r.get('short_excess',0) or 0))*float(r.get('tur',0) or 0) for r in rows)
    tc = sum(float(r.get('charge_to_employee',0) or 0) for r in rows)
    ta = sum(float(r.get('absorb_by_company',0)  or 0) for r in rows)
    return [Paragraph(label, tot_sty)] + [Paragraph('', num_sty)]*9 + [
        Paragraph('', num_sty),
        Paragraph(n(tv), tot_sty),
        Paragraph(n(tc) if tc>0 else '-', tot_sty),
        Paragraph(n(ta) if ta>0 else '-', tot_sty),
        Paragraph('-', tot_sty),
    ]

def build_table(rows, label, row_bg=None):
    data = [[Paragraph(c, hdr_sty) for c in COLS]]
    if rows:
        for r in rows:
            data.append(make_data_row(r))
    else:
        ec = [Paragraph('— No items —', sty('e', fontSize=7.5, fontName='Helvetica',
              textColor=colors.HexColor('#9ca3af'), alignment=TA_CENTER))]
        ec += [Paragraph('', num_sty)]*14
        data.append(ec)
    data.append(make_total_row(rows, f'Total — {label}'))
    bg = row_bg if row_bg else [colors.white, colors.HexColor('#f9fafb')]
    style = TableStyle([
        ('GRID',           (0,0),  (-1,-1), 0.4, colors.HexColor('#d5d5d5')),
        ('BACKGROUND',     (0,0),  (-1,0),  LTGRAY),
        ('FONTNAME',       (0,0),  (-1,0),  'Helvetica-Bold'),
        ('ALIGN',          (0,0),  (-1,-1), 'CENTER'),
        ('VALIGN',         (0,0),  (-1,-1), 'MIDDLE'),
        ('ROWBACKGROUNDS', (0,1),  (-1,-2), bg),
        ('BACKGROUND',     (0,-1), (-1,-1), LTGRAY),
        ('FONTNAME',       (0,-1), (-1,-1), 'Helvetica-Bold'),
        ('TOPPADDING',     (0,0),  (-1,-1), 3),
        ('BOTTOMPADDING',  (0,0),  (-1,-1), 3),
        ('LEFTPADDING',    (0,0),  (-1,-1), 3),
        ('RIGHTPADDING',   (0,0),  (-1,-1), 3),
    ])
    tbl = Table(data, colWidths=COL_W, repeatRows=1)
    tbl.setStyle(style)
    return tbl

rpt_hdr_sty = ParagraphStyle('HH', fontSize=9, fontName='Helvetica', leading=14)
sec_red = ParagraphStyle('SR', fontSize=9, fontName='Helvetica-Bold', textColor=RED,
          backColor=colors.HexColor('#fff0f0'), leading=14, spaceBefore=6, spaceAfter=3)
sec_grn = ParagraphStyle('SG', fontSize=9, fontName='Helvetica-Bold', textColor=colors.HexColor('#166534'),
          backColor=colors.HexColor('#f0fdf4'), leading=14, spaceBefore=6, spaceAfter=3)
sec_amb = ParagraphStyle('SA', fontSize=9, fontName='Helvetica-Bold',
          textColor=colors.HexColor('#92400e'),
          backColor=colors.HexColor('#fefce8'), leading=14, spaceBefore=6, spaceAfter=3)

def company_header(page_no):
    return Paragraph(
        f'<font color="#dc2626" size="8"><b>PDF Report — Page {page_no}</b></font><br/>'
        f'<font size="13"><b>{d["company"]}</b></font><br/>'
        f'<font size="11"><b>{d["title"]}</b></font><br/>'
        f'<font size="9">Date: {d["date_range"]}</font>   '
        f'<font size="9" color="#6b7280">Printed: {d["print_time"]}</font>',
        rpt_hdr_sty)

story = []

story.append(company_header(1))
story.append(HRFlowable(width='100%', thickness=1.5, color=colors.black, spaceAfter=4))
story.append(Paragraph('▼  Short Items (Current Day Delivery)', sec_red))
story.append(build_table(d['short_current'], 'Short Items'))
story.append(Spacer(1, 6*mm))
story.append(Paragraph('▲  Excess Items (Current Day Delivery)', sec_grn))
story.append(build_table(d['excess_current'], 'Excess Items'))

story.append(PageBreak())
story.append(company_header(2))
story.append(HRFlowable(width='100%', thickness=1.5, color=colors.black, spaceAfter=4))
story.append(Paragraph('⏮  Previous Day Items (same delivery date range, added via Previous Day modal)', sec_amb))
story.append(Spacer(1, 2*mm))
story.append(Paragraph('▼  Short Items (Previous Day)', sec_red))
story.append(build_table(d['short_prev'],  'Previous Day Short',
             row_bg=[LYEL, colors.HexColor('#fef9c3')]))
story.append(Spacer(1, 5*mm))
story.append(Paragraph('▲  Excess Items (Previous Day)', sec_grn))
story.append(build_table(d['excess_prev'], 'Previous Day Excess',
             row_bg=[LYEL, colors.HexColor('#fef9c3')]))

story.append(Spacer(1, 12*mm))
sig_sty  = ParagraphStyle('SIG', fontSize=9, fontName='Helvetica',      alignment=TA_CENTER)
sig_bold = ParagraphStyle('SB',  fontSize=9, fontName='Helvetica-Bold', alignment=TA_CENTER)
sig_data = [
    [Paragraph('', sig_sty), Paragraph('......................', sig_sty),
     Paragraph('', sig_sty), Paragraph('......................', sig_sty)],
    [Paragraph('', sig_sty), Paragraph('<b>Checked By</b>',  sig_bold),
     Paragraph('', sig_sty), Paragraph('<b>Approved By</b>', sig_bold)],
]
sig_tbl = Table(sig_data, colWidths=[80*mm, 60*mm, 60*mm, 60*mm])
sig_tbl.setStyle(TableStyle([
    ('ALIGN',     (0,0), (-1,-1), 'CENTER'),
    ('VALIGN',    (0,0), (-1,-1), 'BOTTOM'),
    ('LINEABOVE', (1,0), (1,0),   0.8, colors.black),
    ('LINEABOVE', (3,0), (3,0),   0.8, colors.black),
]))
story.append(sig_tbl)
doc.build(story)
print('OK')
PYEOF;

    $py_file = tempnam(sys_get_temp_dir(), 'rpt_') . '.py';
    file_put_contents($py_file, $py_script);
    $cmd    = "python3 " . escapeshellarg($py_file) . " " . escapeshellarg($tmp_json) . " " . escapeshellarg($tmp_pdf) . " 2>&1";
    $output = shell_exec($cmd);

    if (file_exists($tmp_pdf) && filesize($tmp_pdf) > 0) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Shortage_Excess_Report_' . str_replace([' ', ' — '], '_', $range_label) . '.pdf"');
        header('Content-Length: ' . filesize($tmp_pdf));
        readfile($tmp_pdf);
    } else {
        echo "PDF generation failed.<br>Output: " . htmlspecialchars($output);
    }
    @unlink($tmp_json); @unlink($tmp_pdf); @unlink($py_file);
    exit;
endif;
?>

<!-- ── Summary Banner ─────────────────────────────────────────── -->
<div class="summary-banner">
    <div class="sb-cell sb-short">
        <div class="sb-label">Short (Current Day Delivery)</div>
        <div class="sb-value"><?php echo count($short_current); ?> rows</div>
        <div style="font-size:11px;color:#dc2626;font-weight:600;">Value: Rs. <?php echo fn2($tot_short_value); ?></div>
    </div>
    <div class="sb-cell sb-excess">
        <div class="sb-label">Excess (Current Day Delivery)</div>
        <div class="sb-value"><?php echo count($excess_current); ?> rows</div>
        <div style="font-size:11px;color:#16a34a;font-weight:600;">Value: Rs. <?php echo fn2($tot_excess_value); ?></div>
    </div>
    <div class="sb-cell sb-prev">
        <div class="sb-label">Previous Day Added</div>
        <div class="sb-value"><?php echo count($prev_day_rows); ?> rows</div>
        <div style="font-size:11px;color:#d97706;font-weight:600;">Value: Rs. <?php echo fn2($tot_prev_value); ?></div>
    </div>
    <div class="sb-cell sb-total">
        <div class="sb-label">Delivery Date Range</div>
        <div class="sb-value" style="font-size:14px;"><?php echo htmlspecialchars($range_label); ?></div>
    </div>
</div>

<?php
function render_section(array $rows, string $heading_text, string $section_type, string $total_label, bool $is_prev = false): void
{
    $sidebar_color = $section_type === 'short' ? '#dc2626' : '#16a34a';
    $heading_class = $section_type === 'short' ? 'short-head' : 'excess-head';
    $sidebar_text  = $section_type === 'short' ? 'Short Items' : 'Excess Items';

    echo '<div class="section-wrap">';
    echo '<div class="section-label-cell">';
    echo   '<div class="section-label-text" style="color:' . $sidebar_color . ';">' . $sidebar_text . '</div>';
    echo '</div>';
    echo '<div class="section-content">';
    echo   '<div class="section-heading ' . $heading_class . '">' . $heading_text . '</div>';
    echo   '<div style="overflow-x:auto;">';
    echo   '<table class="rpt-table">';
    echo   '<thead><tr>
               <th class="left">Delivery Person</th>
               <th class="left">SKU Code</th>
               <th class="left">SKU Description</th>
               <th>TUR</th><th>MRP</th>
               <th>Sys Qty<br>(Good)</th>
               <th>Sys Qty<br>(Damage)</th>
               <th>Total<br>Qty</th>
               <th>Act Qty<br>(Good)</th>
               <th>Act Qty<br>(Damage)</th>
               <th>Short /<br>Excess</th>
               <th>Value</th>
               <th>Charge to<br>Emp</th>
               <th>Absorb by<br>Company</th>
               <th>Variance</th>
           </tr></thead>';
    echo '<tbody>';

    $tot_val = 0; $tot_chg = 0; $tot_abs = 0;

    if (empty($rows)) {
        echo '<tr class="no-data-row"><td colspan="15">— No items for this period —</td></tr>';
    } else {
        foreach ($rows as $r) {
            $tur      = floatval($r['tur']                ?? 0);
            $mrp      = floatval($r['mrp']                ?? 0);
            $adj_good = floatval($r['adj_qty_good_units'] ?? 0);
            $adj_dmg  = floatval($r['adj_qty_damage']     ?? 0);
            $total_q  = $adj_good + $adj_dmg;
            $act_good = floatval($r['actual_qty']         ?? $adj_good);
            $act_dmg  = floatval($r['actual_damage_qty']  ?? $adj_dmg);
            $se       = floatval($r['short_excess']       ?? 0);
            $val      = abs($se) * $tur;
            $chg      = floatval($r['charge_to_employee'] ?? 0);
            $abso     = floatval($r['absorb_by_company']  ?? 0);
            $vari     = floatval($r['pay_variance']       ?? 0);

            $tot_val += $val; $tot_chg += $chg; $tot_abs += $abso;

            $se_class = $se < 0 ? 'val-short' : 'val-excess';
            $se_disp  = ($se > 0 ? '+' : '') . number_format($se, 2);

            $badge = '';
            if ($is_prev && !empty($r['delivery_date'])) {
                $badge = '<span class="prevday-badge">'
                       . date('d M Y', strtotime($r['delivery_date']))
                       . '</span>';
            }

            $tr_class = $is_prev ? ' class="prevday-tr"' : '';

            echo '<tr' . $tr_class . '>';
            echo '<td>' . htmlspecialchars($r['delivery_person_name'] ?? '') . $badge . '</td>';
            echo '<td><strong>' . htmlspecialchars($r['sku_code'] ?? '') . '</strong></td>';
            echo '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                      title="' . htmlspecialchars($r['sku_desc'] ?? '') . '">'
                 . htmlspecialchars($r['sku_desc'] ?? '') . '</td>';
            echo '<td class="num">' . number_format($tur,     2) . '</td>';
            echo '<td class="num">' . number_format($mrp,     2) . '</td>';
            echo '<td class="num">' . number_format($adj_good,2) . '</td>';
            echo '<td class="num">' . number_format($adj_dmg, 2) . '</td>';
            echo '<td class="num"><strong>' . number_format($total_q, 2) . '</strong></td>';
            echo '<td class="num">' . number_format($act_good,2) . '</td>';
            echo '<td class="num">' . number_format($act_dmg, 2) . '</td>';
            echo '<td class="num"><span class="' . $se_class . '">' . $se_disp . '</span></td>';
            echo '<td class="num"><strong>' . number_format($val,  2) . '</strong></td>';
            echo '<td class="num">' . ($chg  > 0 ? '<span class="val-charge">'.number_format($chg, 2).'</span>'  : '-') . '</td>';
            echo '<td class="num">' . ($abso > 0 ? '<span class="val-absorb">'.number_format($abso,2).'</span>' : '-') . '</td>';
            echo '<td class="num">' . ($vari != 0 ? number_format($vari, 2) : '-') . '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody>';
    echo '<tfoot><tr>';
    echo   '<td colspan="11" style="text-align:right;"><strong>' . $total_label . '</strong></td>';
    echo   '<td class="num"><strong>' . number_format($tot_val, 2) . '</strong></td>';
    echo   '<td class="num"><strong>' . ($tot_chg > 0 ? number_format($tot_chg,2) : '-') . '</strong></td>';
    echo   '<td class="num"><strong>' . ($tot_abs > 0 ? number_format($tot_abs,2) : '-') . '</strong></td>';
    echo   '<td class="num">-</td>';
    echo '</tr></tfoot>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}
?>

<!-- ══ REPORT DOCUMENT ══════════════════════════════════════════ -->
<div class="report-wrap" id="reportDoc">
<script>
/* Fix overflow scroll wrappers for print */
(function(){
    function fixForPrint(){
        document.querySelectorAll('#reportDoc [style*="overflow-x"]').forEach(function(el){
            el._origOverflow = el.style.overflowX;
            el.style.overflowX = 'visible';
            el.style.width = '100%';
        });
    }
    function restoreAfterPrint(){
        document.querySelectorAll('#reportDoc [style*="overflow-x"]').forEach(function(el){
            if(el._origOverflow !== undefined) el.style.overflowX = el._origOverflow;
        });
    }
    if(window.matchMedia){ var mql = window.matchMedia('print'); mql.addEventListener('change', function(e){ e.matches ? fixForPrint() : restoreAfterPrint(); }); }
    window.addEventListener('beforeprint', fixForPrint);
    window.addEventListener('afterprint',  restoreAfterPrint);
})();
</script>

    <!-- ══════════════════════════════════════════════════════
         PAGE 1 — Current Day sections
         .page1-content has page-break-after:always so the
         previous-day block always starts on a fresh new page
    ══════════════════════════════════════════════════════════ -->
    <div class="page1-content<?php echo empty($prev_day_rows) ? ' page1-no-break' : ''; ?>">

        <!-- Page 1 header -->
        <div class="rpt-header">
            <div class="rpt-header-grid">
                <div>
                    <div class="rpt-title-red">Report — Page 1</div>
                    <div class="rpt-company">YELO Logistics</div>
                    <div class="rpt-subtitle">Daily Good Shortage / Excess Report</div>
                    <div class="rpt-meta">Delivery Date: <?php echo htmlspecialchars($range_label); ?></div>
                    <div class="rpt-meta">Print Time: <?php echo $print_date; ?></div>
                </div>
                <div class="rpt-header-right">
                    <div style="font-size:14px;font-weight:700;color:#374151;">Page 1</div>
                    <div style="margin-top:4px;font-size:11px;">
                        Short: <strong style="color:#dc2626;"><?php echo count($short_current); ?></strong>
                        &nbsp;|&nbsp;
                        Excess: <strong style="color:#16a34a;"><?php echo count($excess_current); ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Current Day Short -->
        <?php render_section($short_current, '▼ Short Items — Current Day Delivery', 'short', 'Total of Short Items'); ?>

        <div class="print-spacer" style="height:10px;"></div>

        <!-- Current Day Excess -->
        <?php render_section($excess_current, '▲ Excess Items — Current Day Delivery', 'excess', 'Total of Excess Items'); ?>

        <?php if (empty($prev_day_rows)): ?>
        <!-- ═══════════════════════════════════════════════════════
             NO PREVIOUS DAY DATA:
             Signature is placed HERE, inside .page1-content,
             immediately after the last table.
             page-break-after on .page1-content is suppressed via
             .page1-no-break so the signature never gets pushed
             to a new page.
             The notice div is hidden in print via CSS.
        ══════════════════════════════════════════════════════════ -->

        <!-- Screen-only notice (hidden in print) -->
        <div class="no-prevday-notice" style="padding:14px 24px;font-size:13px;color:#9ca3af;
                    font-style:italic;border-top:1px solid #e5e5e5;text-align:center;background:#fafafa;">
            <i class="fa-solid fa-calendar-check" style="color:#d1d5db;margin-right:6px;"></i>
            No previous-day items found for this delivery date range.
        </div>

        <!-- Signature — same page, right after table -->
        <div class="sig-section">
            <div class="sig-block"><div class="sig-line"></div><div class="sig-label">Checked By</div></div>
            <div class="sig-block"><div class="sig-line"></div><div class="sig-label">Approved By</div></div>
        </div>

        <?php endif; ?>

    </div><!-- /page1-content -->


    <?php if (!empty($prev_day_rows)): ?>
    <!-- ══════════════════════════════════════════════════════
         PAGE 2 — Previous Day sections
         page-break-before:always on .page2-break + page1-content
         has page-break-after:always → always a clean new page
    ══════════════════════════════════════════════════════════ -->
    <div class="page2-break">

        <hr class="page-sep" style="margin:18px 0;">

        <!-- Page 2 header -->
        <div class="rpt-header" style="border-top:2px solid #222;">
            <div class="rpt-header-grid">
                <div>
                    <div class="rpt-title-red">Report — Page 2</div>
                    <div class="rpt-company">YELO Logistics</div>
                    <div class="rpt-subtitle">Daily Good Shortage / Excess Report — Previous Day Items</div>
                    <div class="rpt-meta">Delivery Date: <?php echo htmlspecialchars($range_label); ?></div>
                    <div class="rpt-meta">Print Time: <?php echo $print_date; ?></div>
                </div>
                <div class="rpt-header-right">
                    <div style="font-size:14px;font-weight:700;color:#374151;">Page 2</div>
                    <div style="margin-top:4px;font-size:11px;">
                        Prev Short: <strong style="color:#dc2626;"><?php echo count($short_prev); ?></strong>
                        &nbsp;|&nbsp;
                        Prev Excess: <strong style="color:#16a34a;"><?php echo count($excess_prev); ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="prevday-divider">
            <i class="fa-solid fa-calendar-minus"></i>
            Previous Day Items — same delivery date range, added via "Add Previous Day Item"
            <span style="font-size:11px;font-weight:400;color:#92400e;margin-left:4px;">
                (<?php echo count($prev_day_rows); ?> rows)
            </span>
        </div>

        <!-- Previous Day Short -->
        <?php render_section($short_prev, '▼ Short Items — Previous Day', 'short', 'Total of Previous Day Short', true); ?>

        <div class="print-spacer" style="height:10px;"></div>

        <!-- Previous Day Excess -->
        <?php render_section($excess_prev, '▲ Excess Items — Previous Day', 'excess', 'Total of Previous Day Excess', true); ?>

        <!-- Signatures after last prev-day table, same page -->
        <div class="sig-section">
            <div class="sig-block"><div class="sig-line"></div><div class="sig-label">Checked By</div></div>
            <div class="sig-block"><div class="sig-line"></div><div class="sig-label">Approved By</div></div>
        </div>

    </div><!-- /page2-break -->

    <?php endif; ?>

</div><!-- /report-wrap -->

<div style="height:30px;"></div>
<?php include 'footer.php'; ?>