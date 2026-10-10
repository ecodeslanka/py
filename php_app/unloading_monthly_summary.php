<?php
/* ══════════════════════════════════════════════════════════════════
   unloading_monthly_summary.php
   Monthly Shortage / Excess Summary Report  —  YELO Logistics
   ══════════════════════════════════════════════════════════════════ */
error_reporting(0);
ini_set('display_errors', 0);

include 'config.php';
include 'header.php';

/* ── Payroll periods for filter ───────────────────────────────── */
$MN = ['','January','February','March','April','May','June','July',
       'August','September','October','November','December'];

$pp_res = mysqli_query($conn,
    "SELECT id, year, month, status FROM payroll_periods ORDER BY year DESC, month DESC");
$payroll_periods = [];
if ($pp_res) while ($pp = mysqli_fetch_assoc($pp_res)) $payroll_periods[] = $pp;

/* ── Active / selected payroll period ────────────────────────── */
$sel_pp_id = isset($_GET['pp_id']) ? intval($_GET['pp_id']) : 0;
$active_pp = null;

foreach ($payroll_periods as $pp) {
    if ($pp['status'] === 'Open' && !$active_pp) $active_pp = $pp;
}

if (!$sel_pp_id && $active_pp) $sel_pp_id = intval($active_pp['id']);

$sel_pp = null;
foreach ($payroll_periods as $pp) {
    if (intval($pp['id']) === $sel_pp_id) { $sel_pp = $pp; break; }
}

/* ── Date range from selected payroll period ──────────────────── */
$date_from = '';
$date_to   = '';
$month_label = '';

if ($sel_pp) {
    $year  = intval($sel_pp['year']);
    $month = intval($sel_pp['month']);
    $date_from = sprintf('%04d-%02d-01', $year, $month);
    $date_to   = date('Y-m-t', strtotime($date_from));
    $month_label = $MN[$month] . ' ' . $year;
}

/* ── Delivery person filter ───────────────────────────────────── */
$sel_person = isset($_GET['person']) ? trim($_GET['person']) : '';

/* ── Get all delivery persons ─────────────────────────────────── */
$persons_res = mysqli_query($conn,
    "SELECT DISTINCT delivery_person_name
     FROM unloading_data
     WHERE delivery_person_name != ''
     ORDER BY delivery_person_name ASC");
$all_persons = [];
if ($persons_res) while ($p = mysqli_fetch_assoc($persons_res)) $all_persons[] = $p['delivery_person_name'];

/* ════════════════════════════════════════════════════════════════
   MAIN DATA QUERY
   ════════════════════════════════════════════════════════════════ */
$data_rows = [];
$all_dates = [];

if ($sel_pp && $date_from && $date_to) {
    $df  = mysqli_real_escape_string($conn, $date_from);
    $dt  = mysqli_real_escape_string($conn, $date_to);

    $person_sql = '';
    if ($sel_person !== '') {
        $ps = mysqli_real_escape_string($conn, $sel_person);
        $person_sql = "AND ud.delivery_person_name = '$ps'";
    }

    $q = "
        SELECT ud.delivery_person_name,
               ud.sku_code,
               ud.sku_desc,
               ud.tur,
               ud.mrp,
               ud.delivery_date,
               ud.short_excess
        FROM   unloading_data ud
        WHERE  ud.short_excess IS NOT NULL
          AND  ud.short_excess != 0
          AND  ud.delivery_date BETWEEN '$df' AND '$dt'
          $person_sql
        ORDER BY ud.delivery_person_name ASC,
                 ud.sku_code ASC,
                 ud.delivery_date ASC";

    $res = mysqli_query($conn, $q);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $date = substr($row['delivery_date'], 0, 10);
            if ($date) $all_dates[$date] = true;
            $data_rows[] = $row;
        }
    }
    ksort($all_dates);
    $all_dates = array_keys($all_dates);
}

/* ════════════════════════════════════════════════════════════════
   BUILD PIVOT STRUCTURE
   ════════════════════════════════════════════════════════════════ */
$pivot = [];

foreach ($data_rows as $r) {
    $person   = $r['delivery_person_name'] ?? '—';
    $sku_key  = ($r['sku_code'] ?? '') . '||' . ($r['delivery_person_name'] ?? '');
    $date     = substr($r['delivery_date'], 0, 10);
    $se       = floatval($r['short_excess']);

    if (!isset($pivot[$person])) $pivot[$person] = [];
    if (!isset($pivot[$person][$sku_key])) {
        $pivot[$person][$sku_key] = [
            'sku_code' => $r['sku_code']  ?? '',
            'sku_desc' => $r['sku_desc']  ?? '',
            'tur'      => floatval($r['tur'] ?? 0),
            'mrp'      => floatval($r['mrp'] ?? 0),
            'dates'    => [],
        ];
    }

    if (!isset($pivot[$person][$sku_key]['dates'][$date])) {
        $pivot[$person][$sku_key]['dates'][$date] = ['short' => 0, 'excess' => 0];
    }

    if ($se < 0) $pivot[$person][$sku_key]['dates'][$date]['short']  += abs($se);
    if ($se > 0) $pivot[$person][$sku_key]['dates'][$date]['excess'] += $se;
}

/* ── helpers ──────────────────────────────────────────────────── */
function fn2($v) { return number_format(floatval($v), 2); }
function fn0($v) { $f = floatval($v); return $f != 0 ? number_format($f, 0) : ''; }

$print_date = date('d/m/Y H:i');
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
body{font-family:'Inter',sans-serif;}

/* ── Filter bar ────────────────────────────────────────────────── */
.filter-bar{background:#fff;border:1px solid #e5e5e5;border-radius:8px;
            padding:14px 18px;margin-bottom:16px;display:flex;align-items:flex-end;
            gap:14px;flex-wrap:wrap;}
.fb-group{display:flex;flex-direction:column;gap:4px;}
.fb-label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;}
.fb-input{padding:7px 11px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;
          font-family:'Inter',sans-serif;color:#1f2937;outline:none;min-width:180px;}
.fb-input:focus{border-color:#7c3aed;}
.btn-filter{padding:8px 18px;background:#7c3aed;color:#fff;border:none;border-radius:6px;
            font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;}
.btn-filter:hover{background:#6d28d9;}
.btn-print{padding:8px 18px;background:#1e40af;color:#fff;border:none;border-radius:6px;
           font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;
           display:inline-flex;align-items:center;gap:6px;}
.btn-print:hover{background:#1d4ed8;}
.btn-print-bw{padding:8px 18px;background:#374151;color:#fff;border:none;border-radius:6px;
              font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;
              display:inline-flex;align-items:center;gap:6px;}
.btn-print-bw:hover{background:#1f2937;}
.btn-excel{padding:8px 18px;background:#166534;color:#fff;border:none;border-radius:6px;
           font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;
           display:inline-flex;align-items:center;gap:6px;}
.btn-excel:hover{background:#15803d;}
.btn-pdf{padding:8px 18px;background:#dc2626;color:#fff;border:none;border-radius:6px;
         font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;
         display:inline-flex;align-items:center;gap:6px;text-decoration:none;}
.btn-pdf:hover{background:#b91c1c;}

/* ── Report wrapper ────────────────────────────────────────────── */
.report-wrap{background:#fff;border:1px solid #e5e5e5;border-radius:8px;
             overflow:hidden;margin-bottom:28px;}
.rpt-header{padding:14px 20px 10px;border-bottom:2px solid #222;
            display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:10px;}
.rpt-company{font-size:14px;font-weight:700;color:#1f2937;}
.rpt-subtitle{font-size:12px;font-weight:600;color:#374151;margin-top:2px;}
.rpt-meta{font-size:11px;color:#6b7280;margin-top:2px;}
.rpt-month-badge{background:#f5f3ff;border:1px solid #ddd6fe;color:#5b21b6;
                 padding:6px 16px;border-radius:6px;font-size:13px;font-weight:700;}

/* ── Filter legend inside report ──────────────────────────────── */
.rpt-filter-row{padding:8px 20px;background:#fafafa;border-bottom:1px solid #e5e5e5;
                display:flex;gap:20px;flex-wrap:wrap;font-size:11px;color:#374151;}
.rfl-item{display:flex;align-items:center;gap:6px;}
.rfl-label{font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;}
.rfl-val{background:#e0e7ff;color:#3730a3;padding:2px 8px;border-radius:4px;font-weight:600;}

/* ── Scrollable table ──────────────────────────────────────────── */
.table-scroll{overflow-x:auto;max-height:75vh;overflow-y:auto;}

/* ── Main table ────────────────────────────────────────────────── */
.ms-table{border-collapse:collapse;font-size:11px;width:100%;min-width:900px;}

.ms-table thead tr{position:sticky;z-index:10;}
.ms-table thead tr:first-child{top:0;}
.ms-table thead tr:nth-child(2){top:34px;}

.ms-table thead th{
    padding:5px 4px;
    border:1px solid #d0d0d0;
    font-size:10px;
    font-weight:700;
    color:#1f2937;
    background:#f0f0f0;
    white-space:nowrap;
    text-align:center;
}
.ms-table thead th.left{text-align:left;padding-left:8px;}
.ms-table thead th.date-head{
    background:#fdf4ff;
    border-top:2px solid #7c3aed;
    font-size:9px;
    writing-mode:vertical-rl;
    transform:rotate(180deg);
    height:52px;
    padding:3px 2px;
    letter-spacing:.5px;
}
.ms-table thead th.s-head{
    background:#fff0f0;color:#991b1b;
    border-top:2px solid #dc2626;
    font-size:9px;font-weight:800;
    padding:3px 4px;
}
.ms-table thead th.e-head{
    background:#f0fdf4;color:#166534;
    border-top:2px solid #16a34a;
    font-size:9px;font-weight:800;
    padding:3px 4px;
}
.ms-table thead th.total-head{
    background:#f5f3ff;border-top:2px solid #7c3aed;
    font-size:10px;font-weight:800;color:#5b21b6;
    padding:5px 6px;
}

.ms-table tbody td{
    padding:4px 5px;
    border:1px solid #e5e5e5;
    color:#1f2937;
    font-size:11px;
    vertical-align:middle;
    white-space:nowrap;
}
.ms-table tbody td.num{text-align:right;}
.ms-table tbody td.ctr{text-align:center;}

.ms-table tbody td.s-cell{
    text-align:center;
    background:#fff8f8;
    color:#dc2626;
    font-weight:700;
    font-size:11px;
}
.ms-table tbody td.e-cell{
    text-align:center;
    background:#f8fff8;
    color:#166534;
    font-weight:700;
    font-size:11px;
}
.ms-table tbody td.empty-cell{
    text-align:center;
    color:#d1d5db;
    font-size:10px;
}

.ms-table tbody tr.data-row:nth-child(even) td{background-color:#fafafa;}
.ms-table tbody tr.data-row:nth-child(even) td.s-cell{background:#fff0f0;}
.ms-table tbody tr.data-row:nth-child(even) td.e-cell{background:#f0fdf4;}
.ms-table tbody tr.data-row:hover td{background:#f0f4ff!important;}
.ms-table tbody tr.data-row:hover td.s-cell{background:#fce8e8!important;}
.ms-table tbody tr.data-row:hover td.e-cell{background:#e8f8ee!important;}

.ms-table tbody tr.person-row td{
    background:#1f2937!important;
    color:#fff!important;
    font-weight:700;
    font-size:11px;
    padding:5px 8px;
}

.ms-table tbody tr.subtotal-row td{
    background:#f5f3ff!important;
    font-weight:700;
    font-size:11px;
    padding:5px 6px;
    border-top:2px solid #7c3aed;
}
.ms-table tbody tr.subtotal-row td.s-cell{background:#fde8e8!important;color:#b91c1c;}
.ms-table tbody tr.subtotal-row td.e-cell{background:#dcfce7!important;color:#15803d;}

.ms-table tfoot tr td{
    background:#1f2937!important;
    color:#fff!important;
    font-weight:800;
    font-size:12px;
    padding:7px 6px;
    border:1px solid #374151;
}
.ms-table tfoot tr td.s-cell{background:#7f1d1d!important;color:#fca5a5;}
.ms-table tfoot tr td.e-cell{background:#14532d!important;color:#86efac;}
.ms-table tfoot tr td.total-val{background:#4c1d95!important;color:#e9d5ff;font-size:13px;}

.ms-table td.total-qty{background:#f5f3ff;color:#5b21b6;font-weight:700;text-align:right;}
.ms-table td.total-val-cell{background:#f0fdf4;color:#166534;font-weight:700;text-align:right;}

/* ── Signature ─────────────────────────────────────────────────── */
.sig-row{display:flex;justify-content:flex-end;gap:60px;padding:18px 24px 14px;border-top:1px solid #e5e5e5;}
.sig-block{text-align:center;min-width:140px;}
.sig-line{border-top:1px solid #555;margin-bottom:4px;margin-top:36px;}
.sig-label{font-size:11px;color:#374151;font-weight:600;}

/* ── No data ────────────────────────────────────────────────────── */
.no-data{text-align:center;padding:40px;color:#9ca3af;font-style:italic;font-size:13px;}

/* ══════════════════════════════════════════════════════════════
   BROWSER @media print  (colour, basic layout)
   ══════════════════════════════════════════════════════════════ */
@media print {
    @page { size: A4 landscape; margin: 8mm; }

    .filter-bar, .btn-print, .btn-print-bw, .btn-excel, .btn-pdf,
    .page-header, nav, .sidebar, header, footer, .btn-filter,
    .rpt-filter-row { display: none !important; }

    body { margin: 0; font-size: 9pt; }
    .report-wrap { border: none; border-radius: 0; }
    .ms-table { font-size: 8pt; }
    .ms-table thead th { font-size: 7.5pt; padding: 3px; }
    .ms-table tbody td { padding: 2px 3px; font-size: 8pt; }
    .table-scroll { max-height: none !important; overflow: visible !important; }

    /* Hide empty date column pairs in browser print */
    .col-empty { display: none !important; }
}
</style>

<!-- ── Page Header ────────────────────────────────────────────── -->
<div class="page-header" style="margin-bottom:14px;">
    <h2 class="page-title" style="display:flex;align-items:center;gap:8px;">
        <i class="fa-solid fa-calendar-days" style="color:#7c3aed;"></i>
        Monthly Shortage / Excess Summary
    </h2>
    <p class="page-subtitle">YELO Logistics — Monthly Unloading Variance Report</p>
</div>

<!-- ── Filter Bar ─────────────────────────────────────────────── -->
<form method="GET" action="" id="filterForm">
<div class="filter-bar">
    <div class="fb-group">
        <label class="fb-label">Payroll Month</label>
        <select class="fb-input" name="pp_id" onchange="this.form.submit()">
            <option value="">— Select Month —</option>
            <?php foreach ($payroll_periods as $pp): ?>
                <option value="<?php echo $pp['id']; ?>"
                    <?php echo (intval($pp['id']) === $sel_pp_id) ? 'selected' : ''; ?>>
                    <?php echo $MN[$pp['month']] . ' ' . $pp['year']
                             . ' [' . ucfirst($pp['status']) . ']'; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="fb-group">
        <label class="fb-label">Delivery Person</label>
        <select class="fb-input" name="person" onchange="this.form.submit()">
            <option value="">All Delivery Persons</option>
            <?php foreach ($all_persons as $dp): ?>
                <option value="<?php echo htmlspecialchars($dp); ?>"
                    <?php echo ($sel_person === $dp) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dp); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn-filter">
        <i class="fa-solid fa-filter"></i> Filter
    </button>
    <button type="button" class="btn-print" onclick="window.print()">
        <i class="fa-solid fa-print"></i> Print
    </button>
    <button type="button" class="btn-print-bw" onclick="printReportBW()">
        <i class="fa-solid fa-print"></i> Print B&amp;W (A4 Landscape)
    </button>
    <button type="button" class="btn-excel" onclick="exportExcel()">
        <i class="fa-solid fa-file-excel"></i> Export Excel
    </button>
    <a href="?pp_id=<?php echo $sel_pp_id; ?>&person=<?php echo urlencode($sel_person); ?>&export=pdf"
       class="btn-pdf">
        <i class="fa-solid fa-file-pdf"></i> Export PDF
    </a>
</div>
</form>

<!-- ══════════════════════════════════════════════════════════════
     B&W PRINT FUNCTION
     Opens a new window, strips colours, hides empty date columns,
     then triggers print — same pattern as the shortage report.
══════════════════════════════════════════════════════════════════ -->
<script>
function printReportBW() {
    var doc = document.getElementById('reportDoc');
    if (!doc) { alert('Report not found.'); return; }

    /* ── 1. Detect which date-column index pairs are fully empty ── */
    /* Each date occupies two <th> in header row 2 (S and E) and
       two <td> per data/subtotal row, identified by data-date-idx.
       We check every td with that index; if ALL are empty → hide it. */
    var table = document.getElementById('mainTable');
    var emptyDateIdxs = {};
    if (table) {
        /* collect all data-date-idx cells */
        var dateCells = table.querySelectorAll('[data-date-idx]');
        var idxData   = {};
        dateCells.forEach(function(td) {
            var idx = td.getAttribute('data-date-idx');
            if (!idxData[idx]) idxData[idx] = { hasData: false };
            var txt = td.textContent.replace(/\s/g,'');
            if (txt !== '' && txt !== '-') idxData[idx].hasData = true;
        });
        Object.keys(idxData).forEach(function(idx) {
            if (!idxData[idx].hasData) emptyDateIdxs[idx] = true;
        });
    }

    /* ── 2. Clone the report ── */
    var clone = doc.cloneNode(true);

    /* Remove inline script tags from clone */
    clone.querySelectorAll('script').forEach(function(s){ s.remove(); });

    /* Fix overflow wrappers */
    clone.querySelectorAll('[style*="overflow-x"]').forEach(function(el){
        el.style.overflowX = 'visible';
        el.style.width     = '100%';
    });
    clone.querySelectorAll('[style*="max-height"]').forEach(function(el){
        el.style.maxHeight = 'none';
        el.style.overflow  = 'visible';
    });

    /* ── 3. Hide empty date columns in the clone ── */
    Object.keys(emptyDateIdxs).forEach(function(idx) {
        /* header th elements */
        clone.querySelectorAll('[data-date-idx="' + idx + '"]').forEach(function(el){
            el.style.display = 'none';
        });
    });

    /* ── 4. Open print window ── */
    var pw = window.open('', '_blank', 'width=1200,height=800');
    pw.document.open();
    pw.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8">');
    pw.document.write('<title>YELO Logistics — Monthly S/E Summary</title>');
    pw.document.write('<style>');
    pw.document.write(`
        @page { size: A4 landscape; margin: 8mm; }

        *, *::before, *::after {
            margin: 0; padding: 0; box-sizing: border-box;
            color: #000 !important;
            background: #fff !important;
            background-color: #fff !important;
            background-image: none !important;
            box-shadow: none !important;
            text-shadow: none !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8pt;
            color: #000;
            background: #fff;
        }

        /* ── Report header ── */
        .rpt-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            padding: 0 0 2mm 0;
            margin-bottom: 3mm;
            border-bottom: 1.5pt solid #000;
        }
        .rpt-company  { font-size: 13pt; font-weight: bold; line-height: 1.1; }
        .rpt-subtitle { font-size: 9pt;  font-weight: bold; margin: 1px 0; }
        .rpt-meta     { font-size: 7.5pt; margin: 1px 0; }
        .rpt-month-badge {
            font-size: 9pt; font-weight: bold;
            border: 1pt solid #000; padding: 3px 10px;
        }

        /* ── Hide filter row, badges, scroll wrapper ── */
        .rpt-filter-row,
        .filter-bar,
        .btn-print, .btn-print-bw, .btn-excel, .btn-pdf, .btn-filter,
        .page-header, nav, header, footer, .sidebar { display: none !important; }

        /* ── Scroll wrapper — must be visible ── */
        .table-scroll {
            overflow: visible !important;
            max-height: none !important;
            width: 100% !important;
        }

        /* ── Table ── */
        .ms-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
            font-size: 6.5pt;
        }
        .ms-table thead th {
            padding: 2px 3px;
            border: 0.5pt solid #000;
            font-size: 6pt;
            font-weight: bold;
            background: #fff;
            text-align: center;
            white-space: normal;
            word-break: break-word;
            line-height: 1.2;
        }
        .ms-table thead th.left { text-align: left; }
        .ms-table thead th.date-head {
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            height: 48px;
            font-size: 5.5pt;
            padding: 2px 1px;
        }
        .ms-table thead th.s-head,
        .ms-table thead th.e-head,
        .ms-table thead th.total-head { font-size: 6pt; padding: 2px 2px; }

        .ms-table tbody td {
            padding: 1.5px 2px;
            border: 0.4pt solid #000;
            font-size: 6.5pt;
            vertical-align: middle;
            white-space: nowrap;
        }
        .ms-table tbody td.num { text-align: right; }

        /* strip all colour from body cells */
        .ms-table tbody tr.data-row:nth-child(even) td,
        .ms-table tbody tr.data-row:nth-child(odd) td { background: #fff; }

        .ms-table tbody td.s-cell,
        .ms-table tbody td.e-cell,
        .ms-table tbody td.empty-cell,
        .ms-table td.total-qty,
        .ms-table td.total-val-cell { background: #fff; color: #000; }

        .ms-table tbody td.s-cell { font-weight: bold; text-align: center; }
        .ms-table tbody td.e-cell { font-weight: bold; text-align: center; }

        /* person header row */
        .ms-table tbody tr.person-row td {
            background: #fff !important;
            color: #000 !important;
            font-weight: bold;
            border-top: 1pt solid #000;
            border-bottom: 0.5pt solid #000;
            font-size: 7pt;
            padding: 2px 4px;
        }

        /* subtotal row */
        .ms-table tbody tr.subtotal-row td {
            background: #fff !important;
            font-weight: bold;
            border-top: 1pt solid #000;
            font-size: 6.5pt;
        }
        .ms-table tbody tr.subtotal-row td.s-cell,
        .ms-table tbody tr.subtotal-row td.e-cell {
            background: #fff !important;
            color: #000 !important;
        }

        /* grand total footer */
        .ms-table tfoot tr td {
            background: #fff !important;
            color: #000 !important;
            font-weight: bold;
            font-size: 7pt;
            border: 0.5pt solid #000;
            border-top: 1.5pt solid #000;
        }
        .ms-table tfoot tr td.s-cell,
        .ms-table tfoot tr td.e-cell,
        .ms-table tfoot tr td.total-val {
            background: #fff !important;
            color: #000 !important;
        }
        .ms-table tfoot tr td[style] { background: #fff !important; color: #000 !important; }

        .ms-table tbody tr { page-break-inside: avoid; }

        /* ── Signature ── */
        .sig-row {
            display: flex;
            justify-content: flex-end;
            gap: 30mm;
            padding: 8mm 0 0 0;
            border: none;
            border-top: 0.5pt solid #000;
            margin-top: 6mm;
        }
        .sig-block  { min-width: 50mm; text-align: center; }
        .sig-line   { border: none; border-top: 0.8pt solid #000; margin: 14mm 0 2px 0; }
        .sig-label  { font-size: 7.5pt; font-weight: bold; }

        /* icons — hide in print */
        .fa-solid, i.fa-solid { display: none !important; }

        /* no-data notice */
        .no-data { font-size: 8pt; padding: 10px; }
    `);
    pw.document.write('</style></head><body>');
    pw.document.write(clone.innerHTML);
    pw.document.write('</body></html>');
    pw.document.close();

    pw.onload = function(){ pw.focus(); pw.print(); };
    setTimeout(function(){ pw.focus(); pw.print(); }, 600);
}
</script>

<?php
/* ═══════════════════════════════════════════════════════════════
   PDF EXPORT  (unchanged)
   ═══════════════════════════════════════════════════════════════ */
if (isset($_GET['export']) && $_GET['export'] === 'pdf' && $sel_pp):
    ob_end_clean();

    $pdf_data = [];
    foreach ($pivot as $person => $skus) {
        $person_rows = [];
        foreach ($skus as $sku_key => $info) {
            $row_dates = [];
            foreach ($all_dates as $d) {
                $row_dates[$d] = $info['dates'][$d] ?? ['short'=>0,'excess'=>0];
            }
            $tot_short  = array_sum(array_column(array_values($info['dates']), 'short'));
            $tot_excess = array_sum(array_column(array_values($info['dates']), 'excess'));
            $net_qty    = $tot_excess - $tot_short;
            $net_val    = $net_qty * $info['tur'];
            $person_rows[] = [
                'sku_code'   => $info['sku_code'],
                'sku_desc'   => $info['sku_desc'],
                'tur'        => $info['tur'],
                'mrp'        => $info['mrp'],
                'dates'      => $row_dates,
                'tot_short'  => $tot_short,
                'tot_excess' => $tot_excess,
                'net_qty'    => $net_qty,
                'net_val'    => $net_val,
            ];
        }
        $pdf_data[$person] = $person_rows;
    }

    $report_data = [
        'company'       => 'YELO Logistics',
        'month_label'   => $month_label,
        'print_time'    => $print_date,
        'person_filter' => $sel_person ?: 'All',
        'dates'         => $all_dates,
        'data'          => $pdf_data,
    ];

    $tmp_json = tempnam(sys_get_temp_dir(), 'ms_') . '.json';
    $tmp_pdf  = tempnam(sys_get_temp_dir(), 'ms_') . '.pdf';
    file_put_contents($tmp_json, json_encode($report_data, JSON_UNESCAPED_UNICODE));

    $py_script = <<<'PYEOF'
import sys, json
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib import colors
from reportlab.lib.units import mm
from reportlab.platypus import (SimpleDocTemplate, Table, TableStyle,
                                  Paragraph, Spacer, HRFlowable)
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.enums import TA_LEFT, TA_CENTER, TA_RIGHT
from datetime import datetime

json_path = sys.argv[1]
out_path  = sys.argv[2]

with open(json_path, encoding='utf-8') as f:
    d = json.load(f)

doc = SimpleDocTemplate(out_path,
    pagesize=landscape(A4),
    leftMargin=8*mm, rightMargin=8*mm,
    topMargin=8*mm, bottomMargin=8*mm)

RED    = colors.HexColor('#dc2626')
GREEN  = colors.HexColor('#16a34a')
PURPLE = colors.HexColor('#7c3aed')
DKGRAY = colors.HexColor('#374151')
LTGRAY = colors.HexColor('#f0f0f0')
DARK   = colors.HexColor('#1f2937')
RED_BG = colors.HexColor('#fff0f0')
GRN_BG = colors.HexColor('#f0fdf4')
PUR_BG = colors.HexColor('#f5f3ff')
LYEL   = colors.HexColor('#fefce8')

def sty(name, **kw): return ParagraphStyle(name, **kw)
hdr = sty('H', fontSize=7, fontName='Helvetica-Bold', textColor=DKGRAY, alignment=TA_CENTER, leading=8)
cel = sty('C', fontSize=7, fontName='Helvetica',      textColor=DKGRAY, alignment=TA_LEFT,   leading=8)
num = sty('N', fontSize=7, fontName='Helvetica',      textColor=DKGRAY, alignment=TA_RIGHT,  leading=8)
red = sty('R', fontSize=7, fontName='Helvetica-Bold', textColor=RED,    alignment=TA_CENTER, leading=8)
grn = sty('G', fontSize=7, fontName='Helvetica-Bold', textColor=GREEN,  alignment=TA_CENTER, leading=8)
tot = sty('T', fontSize=7.5, fontName='Helvetica-Bold', textColor=PURPLE, alignment=TA_RIGHT, leading=8)
wht = sty('W', fontSize=7.5, fontName='Helvetica-Bold', textColor=colors.white, alignment=TA_RIGHT, leading=8)

def n(v, dec=2):
    try:    return f"{float(v):,.{dec}f}" if float(v) != 0 else ''
    except: return ''

def fmt_date_short(ds):
    try:
        dt = datetime.strptime(str(ds)[:10], '%Y-%m-%d')
        return dt.strftime('%d-%b-%y')
    except:
        return str(ds)[:10]

dates = d['dates']
nd = len(dates)

# Detect empty dates for PDF (no data across all persons/skus)
empty_date_idxs = set()
for i, ds in enumerate(dates):
    has_data = False
    for rows in d['data'].values():
        for row in rows:
            dv = row['dates'].get(ds, {'short':0,'excess':0})
            if float(dv.get('short',0)) > 0 or float(dv.get('excess',0)) > 0:
                has_data = True
                break
        if has_data: break
    if not has_data:
        empty_date_idxs.add(i)

active_dates = [ds for i, ds in enumerate(dates) if i not in empty_date_idxs]
nd_active = len(active_dates)

FW = [28, 16, 42, 14, 14]
date_col_w = max(5, min(12, int(180 / max(nd_active,1))))
for _ in active_dates: FW.extend([date_col_w, date_col_w])
FW.extend([16, 20])
col_w = [x*mm for x in FW]

h1 = [
    Paragraph('Delivery Person', hdr),
    Paragraph('SKU Code', hdr),
    Paragraph('Item Name', hdr),
    Paragraph('TUR', hdr),
    Paragraph('MRP', hdr),
]
for ds in active_dates:
    h1.append(Paragraph(fmt_date_short(ds), sty('DH', fontSize=6, fontName='Helvetica-Bold',
              textColor=colors.HexColor('#5b21b6'), alignment=TA_CENTER, leading=7)))
    h1.append(Paragraph('', hdr))
h1.append(Paragraph('Total\nQty', sty('TH', fontSize=7, fontName='Helvetica-Bold',
           textColor=colors.HexColor('#5b21b6'), alignment=TA_CENTER, leading=8)))
h1.append(Paragraph('Total\nValue', sty('TH2', fontSize=7, fontName='Helvetica-Bold',
           textColor=colors.HexColor('#166534'), alignment=TA_CENTER, leading=8)))

h2 = [Paragraph('', hdr)]*5
for _ in active_dates:
    h2.append(Paragraph('S', sty('SH', fontSize=7, fontName='Helvetica-Bold', textColor=RED,   alignment=TA_CENTER)))
    h2.append(Paragraph('E', sty('EH', fontSize=7, fontName='Helvetica-Bold', textColor=GREEN, alignment=TA_CENTER)))
h2.append(Paragraph('', hdr))
h2.append(Paragraph('', hdr))

table_data = [h1, h2]
style_cmds = [
    ('GRID',       (0,0),  (-1,-1), 0.3, colors.HexColor('#d0d0d0')),
    ('BACKGROUND', (0,0),  (-1,1),  LTGRAY),
    ('VALIGN',     (0,0),  (-1,-1), 'MIDDLE'),
    ('ALIGN',      (0,0),  (-1,-1), 'CENTER'),
    ('TOPPADDING', (0,0),  (-1,-1), 2),
    ('BOTTOMPADDING',(0,0),(-1,-1), 2),
    ('LEFTPADDING',(0,0),  (-1,-1), 2),
    ('RIGHTPADDING',(0,0), (-1,-1), 2),
    ('LINEABOVE', (5,0), (-3,0), 1.5, PURPLE),
    ('LINEABOVE', (-2,0), (-1,0), 1.5, PURPLE),
]
for i, _ in enumerate(active_dates):
    sc = 5 + i*2
    ec = 6 + i*2
    style_cmds.append(('BACKGROUND', (sc,1), (sc,1), RED_BG))
    style_cmds.append(('BACKGROUND', (ec,1), (ec,1), GRN_BG))

row_idx = 2
grand_s = grand_e = grand_qty = grand_val = 0

for person, rows in d['data'].items():
    table_data.append(
        [Paragraph(person, sty('PH', fontSize=8, fontName='Helvetica-Bold',
                   textColor=colors.white, alignment=TA_LEFT, leading=9))]
        + [Paragraph('', hdr)] * (len(FW)-1)
    )
    style_cmds.append(('BACKGROUND', (0, row_idx), (-1, row_idx), DARK))
    style_cmds.append(('SPAN',       (0, row_idx), (4,  row_idx)))
    row_idx += 1

    p_short = p_excess = p_qty = p_val = 0

    for row in rows:
        dr = [
            Paragraph('', cel),
            Paragraph(str(row['sku_code']), cel),
            Paragraph(str(row['sku_desc'])[:30], cel),
            Paragraph(n(row['tur']), num),
            Paragraph(n(row['mrp']), num),
        ]
        r_short = r_excess = 0
        for ds in active_dates:
            dvals = row['dates'].get(ds, {'short':0,'excess':0})
            sv = float(dvals.get('short', 0))
            ev = float(dvals.get('excess', 0))
            r_short  += sv
            r_excess += ev
            dr.append(Paragraph(f'({n(sv,0)})' if sv > 0 else '', red) if sv > 0 else Paragraph('', hdr))
            dr.append(Paragraph(n(ev,0) if ev > 0 else '', grn) if ev > 0 else Paragraph('', hdr))

        net = r_excess - r_short
        val = net * float(row['tur'])
        p_short  += r_short
        p_excess += r_excess
        p_qty    += net
        p_val    += val

        dr.append(Paragraph(n(abs(net),0) if net != 0 else '', num))
        dr.append(Paragraph(n(abs(val)) if val != 0 else '', num))
        table_data.append(dr)

        for i, ds in enumerate(active_dates):
            sc = 5 + i*2
            ec = 6 + i*2
            dvals = row['dates'].get(ds, {'short':0,'excess':0})
            if float(dvals.get('short',0)) > 0:
                style_cmds.append(('BACKGROUND', (sc, row_idx), (sc, row_idx), RED_BG))
                style_cmds.append(('TEXTCOLOR',  (sc, row_idx), (sc, row_idx), RED))
            if float(dvals.get('excess',0)) > 0:
                style_cmds.append(('BACKGROUND', (ec, row_idx), (ec, row_idx), GRN_BG))
                style_cmds.append(('TEXTCOLOR',  (ec, row_idx), (ec, row_idx), GREEN))
        row_idx += 1

    grand_s   += p_short
    grand_e   += p_excess
    grand_qty += p_qty
    grand_val += p_val

    st = [Paragraph('Total', sty('ST', fontSize=7.5, fontName='Helvetica-Bold',
           textColor=PURPLE, alignment=TA_RIGHT))] + [Paragraph('', hdr)]*3 + [Paragraph('', hdr)]
    for ds in active_dates:
        ds_short  = sum(float(r['dates'].get(ds,{}).get('short',0))  for r in rows)
        ds_excess = sum(float(r['dates'].get(ds,{}).get('excess',0)) for r in rows)
        st.append(Paragraph(f'({n(ds_short,0)})'  if ds_short  > 0 else '', red))
        st.append(Paragraph(n(ds_excess,0)         if ds_excess > 0 else '', grn))
    st.append(Paragraph(n(abs(p_qty),0) if p_qty != 0 else '-', tot))
    st.append(Paragraph(n(abs(p_val))   if p_val != 0 else '-', sty('TV', fontSize=7.5,
               fontName='Helvetica-Bold', textColor=GREEN, alignment=TA_RIGHT)))
    table_data.append(st)
    style_cmds.append(('BACKGROUND',  (0, row_idx), (-1, row_idx), PUR_BG))
    style_cmds.append(('LINEABOVE',   (0, row_idx), (-1, row_idx), 1.5, PURPLE))
    style_cmds.append(('LINEBELOW',   (0, row_idx), (-1, row_idx), 1.5, PURPLE))
    row_idx += 1

gt = [Paragraph('Grand Total', sty('GT', fontSize=8, fontName='Helvetica-Bold',
       textColor=colors.white, alignment=TA_LEFT))] + [Paragraph('', hdr)]*3 + [Paragraph('', hdr)]
for ds in active_dates:
    all_s = sum(float(r['dates'].get(ds,{}).get('short',0))  for rows in d['data'].values() for r in rows)
    all_e = sum(float(r['dates'].get(ds,{}).get('excess',0)) for rows in d['data'].values() for r in rows)
    gt.append(Paragraph(f'({n(all_s,0)})' if all_s > 0 else '', sty('GS', fontSize=7,
               fontName='Helvetica-Bold', textColor=colors.HexColor('#fca5a5'), alignment=TA_CENTER)))
    gt.append(Paragraph(n(all_e,0) if all_e > 0 else '', sty('GE', fontSize=7,
               fontName='Helvetica-Bold', textColor=colors.HexColor('#86efac'), alignment=TA_CENTER)))
gt.append(Paragraph(n(abs(grand_qty),0) if grand_qty != 0 else '-',
           sty('GQ', fontSize=8, fontName='Helvetica-Bold', textColor=colors.HexColor('#e9d5ff'), alignment=TA_RIGHT)))
gt.append(Paragraph(n(abs(grand_val)) if grand_val != 0 else '-',
           sty('GV', fontSize=8, fontName='Helvetica-Bold', textColor=colors.HexColor('#86efac'), alignment=TA_RIGHT)))
table_data.append(gt)
style_cmds.append(('BACKGROUND', (0, row_idx), (-1, row_idx), DARK))

tbl = Table(table_data, colWidths=col_w, repeatRows=2)
tbl.setStyle(TableStyle(style_cmds))

story = []
rh_sty = ParagraphStyle('RH', fontSize=9, fontName='Helvetica', leading=13)
story.append(Paragraph(
    f'<font size="13"><b>YELO Logistics</b></font><br/>'
    f'<font size="10"><b>Monthly Shortage / Excess Summary</b></font><br/>'
    f'<font size="9" color="#5b21b6"><b>Payroll Month: {d["month_label"]}</b></font>   '
    f'<font size="8" color="#6b7280">Delivery Person: {d["person_filter"]}   Printed: {d["print_time"]}</font>',
    rh_sty))
story.append(HRFlowable(width='100%', thickness=1.5, color=colors.black, spaceAfter=5))
story.append(tbl)

story.append(Spacer(1, 10*mm))
sig_sty  = ParagraphStyle('SIG', fontSize=8, fontName='Helvetica',      alignment=TA_CENTER)
sig_bold = ParagraphStyle('SB',  fontSize=8, fontName='Helvetica-Bold', alignment=TA_CENTER)
sig_data = [
    [Paragraph('', sig_sty), Paragraph('......................', sig_sty),
     Paragraph('', sig_sty), Paragraph('......................', sig_sty)],
    [Paragraph('', sig_sty), Paragraph('<b>Checked By</b>',  sig_bold),
     Paragraph('', sig_sty), Paragraph('<b>Approved By</b>', sig_bold)],
]
from reportlab.platypus import Table as RLTable
from reportlab.platypus import TableStyle as RLTS
sig_t = RLTable(sig_data, colWidths=[70*mm, 50*mm, 60*mm, 50*mm])
sig_t.setStyle(RLTS([
    ('ALIGN',     (0,0), (-1,-1), 'CENTER'),
    ('VALIGN',    (0,0), (-1,-1), 'BOTTOM'),
    ('LINEABOVE', (1,0), (1,0),   0.7, colors.black),
    ('LINEABOVE', (3,0), (3,0),   0.7, colors.black),
]))
story.append(sig_t)

doc.build(story)
print('OK')
PYEOF;

    $py_file = tempnam(sys_get_temp_dir(), 'ms_') . '.py';
    file_put_contents($py_file, $py_script);
    $cmd    = "python3 " . escapeshellarg($py_file)
            . " " . escapeshellarg($tmp_json)
            . " " . escapeshellarg($tmp_pdf) . " 2>&1";
    $output = shell_exec($cmd);

    if (file_exists($tmp_pdf) && filesize($tmp_pdf) > 0) {
        header('Content-Type: application/pdf');
        $fn = 'Monthly_SE_Summary_' . str_replace(' ','_',$month_label) . '.pdf';
        header('Content-Disposition: attachment; filename="' . $fn . '"');
        header('Content-Length: ' . filesize($tmp_pdf));
        readfile($tmp_pdf);
    } else {
        echo "PDF generation failed.<br><pre>" . htmlspecialchars($output) . "</pre>";
    }
    @unlink($tmp_json); @unlink($tmp_pdf); @unlink($py_file);
    exit;
endif;
?>

<!-- ══ REPORT ══════════════════════════════════════════════════ -->
<div class="report-wrap" id="reportDoc">

    <!-- Report header -->
    <div class="rpt-header">
        <div>
            <div class="rpt-company">YELO Logistics</div>
            <div class="rpt-subtitle">Monthly Shortage / Excess Summary Report</div>
            <div class="rpt-meta">Print Time: <?php echo $print_date; ?></div>
        </div>
        <?php if ($sel_pp): ?>
        <div class="rpt-month-badge">
            <i class="fa-solid fa-calendar-days"></i>
            Payroll Month: <?php echo htmlspecialchars($month_label); ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Filter legend -->
    <div class="rpt-filter-row">
        <div class="rfl-item">
            <span class="rfl-label">Payroll Month</span>
            <span class="rfl-val"><?php echo $sel_pp ? htmlspecialchars($month_label) : '— Not selected —'; ?></span>
        </div>
        <div class="rfl-item">
            <span class="rfl-label">Delivery Person</span>
            <span class="rfl-val"><?php echo $sel_person ? htmlspecialchars($sel_person) : 'All'; ?></span>
        </div>
        <?php if ($date_from): ?>
        <div class="rfl-item">
            <span class="rfl-label">Date Range</span>
            <span class="rfl-val">
                <?php echo date('d M Y', strtotime($date_from)); ?>
                &nbsp;—&nbsp;
                <?php echo date('d M Y', strtotime($date_to)); ?>
            </span>
        </div>
        <?php endif; ?>
        <div class="rfl-item" style="margin-left:auto;font-size:11px;color:#6b7280;">
            <i class="fa-solid fa-circle" style="color:#dc2626;font-size:8px;"></i> Short (S) shown as (qty)
            &nbsp;&nbsp;
            <i class="fa-solid fa-circle" style="color:#16a34a;font-size:8px;"></i> Excess (E) shown as qty
        </div>
    </div>

    <?php if (empty($pivot)): ?>
    <div class="no-data">
        <i class="fa-solid fa-inbox" style="font-size:36px;display:block;margin-bottom:10px;color:#d1d5db;"></i>
        <?php echo $sel_pp ? 'No shortage or excess items found for this period.' : 'Please select a Payroll Month to view the report.'; ?>
    </div>

    <?php else: ?>

    <!-- Table -->
    <div class="table-scroll" id="reportTable">
    <table class="ms-table" id="mainTable">
        <thead>
            <!-- Row 1: fixed headers + date group headers + totals -->
            <tr>
                <th class="left" rowspan="2" style="min-width:110px;">Delivery Person</th>
                <th class="left" rowspan="2" style="min-width:70px;">SKU Code</th>
                <th class="left" rowspan="2" style="min-width:140px;">Item Name</th>
                <th rowspan="2" style="min-width:50px;">TUR</th>
                <th rowspan="2" style="min-width:50px;">MRP</th>
                <?php foreach ($all_dates as $di => $d):
                    /* Check if this date has any data at all */
                    $date_has_data = false;
                    foreach ($pivot as $person => $skus) {
                        foreach ($skus as $sku_key => $info) {
                            if (!empty($info['dates'][$d]['short']) || !empty($info['dates'][$d]['excess'])) {
                                $date_has_data = true;
                                break 2;
                            }
                        }
                    }
                    $empty_cls = $date_has_data ? '' : ' col-empty';
                ?>
                <th colspan="2"
                    class="date-head<?php echo $empty_cls; ?>"
                    data-date-idx="<?php echo $di; ?>"
                    style="writing-mode:horizontal-tb;transform:none;height:auto;padding:4px 3px;font-size:9px;">
                    <?php echo date('d-M-y', strtotime($d)); ?>
                </th>
                <?php endforeach; ?>
                <th class="total-head" rowspan="2" style="min-width:55px;">Total<br>Qty</th>
                <th class="total-head" rowspan="2" style="min-width:65px;background:#f0fdf4;color:#166534;border-top-color:#16a34a;">Total<br>Value</th>
            </tr>
            <!-- Row 2: S / E for each date -->
            <tr>
                <?php foreach ($all_dates as $di => $d):
                    $date_has_data = false;
                    foreach ($pivot as $person => $skus) {
                        foreach ($skus as $sku_key => $info) {
                            if (!empty($info['dates'][$d]['short']) || !empty($info['dates'][$d]['excess'])) {
                                $date_has_data = true;
                                break 2;
                            }
                        }
                    }
                    $empty_cls = $date_has_data ? '' : ' col-empty';
                ?>
                <th class="s-head<?php echo $empty_cls; ?>" data-date-idx="<?php echo $di; ?>">S</th>
                <th class="e-head<?php echo $empty_cls; ?>" data-date-idx="<?php echo $di; ?>">E</th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php
        $grand_short   = 0;
        $grand_excess  = 0;
        $grand_qty     = 0;
        $grand_val     = 0;
        $grand_by_date = array_fill_keys($all_dates, ['short'=>0,'excess'=>0]);

        /* Pre-compute which dates are empty (for cell class assignment) */
        $date_empty = [];
        foreach ($all_dates as $di => $d) {
            $has = false;
            foreach ($pivot as $person => $skus) {
                foreach ($skus as $sku_key => $info) {
                    if (!empty($info['dates'][$d]['short']) || !empty($info['dates'][$d]['excess'])) {
                        $has = true; break 2;
                    }
                }
            }
            $date_empty[$di] = !$has;
        }

        foreach ($pivot as $person => $skus):
            $p_short   = 0;
            $p_excess  = 0;
            $p_qty     = 0;
            $p_val     = 0;
            $p_by_date = array_fill_keys($all_dates, ['short'=>0,'excess'=>0]);
        ?>
            <!-- Person header row -->
            <tr class="person-row">
                <td colspan="5"><i class="fa-solid fa-user"></i>&nbsp; <?php echo htmlspecialchars($person); ?></td>
                <?php foreach ($all_dates as $di => $d):
                    $empty_cls = $date_empty[$di] ? ' col-empty' : '';
                ?>
                <td colspan="2" data-date-idx="<?php echo $di; ?>" class="<?php echo trim($empty_cls); ?>"></td>
                <?php endforeach; ?>
                <td></td><td></td>
            </tr>

            <?php foreach ($skus as $sku_key => $info):
                $row_short  = 0;
                $row_excess = 0;
                foreach ($info['dates'] as $sv) {
                    $row_short  += $sv['short'];
                    $row_excess += $sv['excess'];
                }
                $net_qty = $row_excess - $row_short;
                $net_val = $net_qty * $info['tur'];
                $p_short  += $row_short;
                $p_excess += $row_excess;
                $p_qty    += $net_qty;
                $p_val    += $net_val;
            ?>
            <tr class="data-row">
                <td></td>
                <td><strong><?php echo htmlspecialchars($info['sku_code']); ?></strong></td>
                <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;"
                    title="<?php echo htmlspecialchars($info['sku_desc']); ?>">
                    <?php echo htmlspecialchars($info['sku_desc']); ?>
                </td>
                <td class="num"><?php echo number_format($info['tur'], 2); ?></td>
                <td class="num"><?php echo number_format($info['mrp'], 2); ?></td>

                <?php foreach ($all_dates as $di => $d):
                    $dv  = $info['dates'][$d] ?? ['short'=>0,'excess'=>0];
                    $sv  = floatval($dv['short']);
                    $ev  = floatval($dv['excess']);
                    $p_by_date[$d]['short']       += $sv;
                    $p_by_date[$d]['excess']       += $ev;
                    $grand_by_date[$d]['short']   += $sv;
                    $grand_by_date[$d]['excess']  += $ev;
                    $empty_cls = $date_empty[$di] ? ' col-empty' : '';
                ?>
                <td class="<?php echo ($sv > 0 ? 's-cell' : 'empty-cell') . $empty_cls; ?>"
                    data-date-idx="<?php echo $di; ?>">
                    <?php echo $sv > 0 ? '(' . number_format($sv, 0) . ')' : ''; ?>
                </td>
                <td class="<?php echo ($ev > 0 ? 'e-cell' : 'empty-cell') . $empty_cls; ?>"
                    data-date-idx="<?php echo $di; ?>">
                    <?php echo $ev > 0 ? number_format($ev, 0) : ''; ?>
                </td>
                <?php endforeach; ?>

                <td class="total-qty"><?php echo $net_qty != 0 ? number_format(abs($net_qty), 0) : '-'; ?></td>
                <td class="total-val-cell"><?php echo $net_val != 0 ? number_format(abs($net_val), 2) : '-'; ?></td>
            </tr>
            <?php endforeach; // skus

            $grand_short  += $p_short;
            $grand_excess += $p_excess;
            $grand_qty    += $p_qty;
            $grand_val    += $p_val;
            ?>

            <!-- Person subtotal row -->
            <tr class="subtotal-row">
                <td colspan="5" style="text-align:right;color:#5b21b6;">
                    <strong>Total — <?php echo htmlspecialchars($person); ?></strong>
                </td>
                <?php foreach ($all_dates as $di => $d):
                    $sv = $p_by_date[$d]['short'];
                    $ev = $p_by_date[$d]['excess'];
                    $empty_cls = $date_empty[$di] ? ' col-empty' : '';
                ?>
                <td class="<?php echo ($sv > 0 ? 's-cell' : 'empty-cell') . $empty_cls; ?>"
                    data-date-idx="<?php echo $di; ?>">
                    <?php echo $sv > 0 ? '(' . number_format($sv, 0) . ')' : ''; ?>
                </td>
                <td class="<?php echo ($ev > 0 ? 'e-cell' : 'empty-cell') . $empty_cls; ?>"
                    data-date-idx="<?php echo $di; ?>">
                    <?php echo $ev > 0 ? number_format($ev, 0) : ''; ?>
                </td>
                <?php endforeach; ?>
                <td class="total-qty" style="font-size:12px;">
                    <?php echo $p_qty != 0 ? number_format(abs($p_qty), 0) : '-'; ?>
                </td>
                <td class="total-val-cell" style="font-size:12px;">
                    <?php echo $p_val != 0 ? number_format(abs($p_val), 2) : '-'; ?>
                </td>
            </tr>

        <?php endforeach; // persons ?>
        </tbody>

        <!-- Grand Total -->
        <tfoot>
            <tr>
                <td colspan="5" style="text-align:left;">
                    <i class="fa-solid fa-sigma" style="margin-right:6px;"></i> Grand Total
                </td>
                <?php foreach ($all_dates as $di => $d):
                    $sv = $grand_by_date[$d]['short'];
                    $ev = $grand_by_date[$d]['excess'];
                    $empty_cls = $date_empty[$di] ? ' col-empty' : '';
                ?>
                <td class="<?php echo ($sv > 0 ? 's-cell' : '') . $empty_cls; ?>"
                    data-date-idx="<?php echo $di; ?>"
                    style="<?php echo $sv > 0 ? 'background:#7f1d1d;color:#fca5a5;' : 'background:#374151;'; ?>">
                    <?php echo $sv > 0 ? '(' . number_format($sv, 0) . ')' : ''; ?>
                </td>
                <td class="<?php echo ($ev > 0 ? 'e-cell' : '') . $empty_cls; ?>"
                    data-date-idx="<?php echo $di; ?>"
                    style="<?php echo $ev > 0 ? 'background:#14532d;color:#86efac;' : 'background:#374151;'; ?>">
                    <?php echo $ev > 0 ? number_format($ev, 0) : ''; ?>
                </td>
                <?php endforeach; ?>
                <td class="total-val" style="text-align:right;font-size:13px;">
                    <?php echo $grand_qty != 0 ? number_format(abs($grand_qty), 0) : '-'; ?>
                </td>
                <td class="total-val" style="text-align:right;font-size:13px;background:#14532d;color:#86efac;">
                    <?php echo $grand_val != 0 ? number_format(abs($grand_val), 2) : '-'; ?>
                </td>
            </tr>
        </tfoot>
    </table>
    </div>

    <?php endif; // empty check ?>

    <!-- Signature -->
    <div class="sig-row">
        <div class="sig-block"><div class="sig-line"></div><div class="sig-label">Checked By</div></div>
        <div class="sig-block"><div class="sig-line"></div><div class="sig-label">Approved By</div></div>
    </div>

</div><!-- /report-wrap -->

<div style="height:30px;"></div>

<!-- SheetJS for Excel export (unchanged) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
function exportExcel() {
    const tbl = document.getElementById('mainTable');
    if (!tbl) { alert('No data to export.'); return; }
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.table_to_sheet(tbl);
    XLSX.utils.book_append_sheet(wb, ws, 'Monthly S-E Summary');
    const month  = '<?php echo addslashes($month_label); ?>';
    const person = '<?php echo addslashes($sel_person ?: 'All'); ?>';
    XLSX.writeFile(wb, `Monthly_SE_Summary_${month}_${person}.xlsx`);
}
</script>

<?php include 'footer.php'; ?>