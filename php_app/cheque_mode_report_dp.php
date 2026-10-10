<?php
/**
 * cheque_mode_report_dp.php
 * Dynamic Cheque Mode Report — discovers all distinct modes from DB
 * Each mode gets its own Qty + % column. Total Cheque at end.
 * Rows are grouped by DELIVERY PERSON (falls back to SR Code when a
 * cheque's field_summary has no delivery person assigned).
 */
if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';
include 'header.php';

/* ══════════════════════════════════════════════════
   MODE NORMALISATION — mirrors modeBadge() in cheques.php
   Returns a clean display label + group colour key
══════════════════════════════════════════════════ */
function normaliseMode(string $raw): string {
    $m = strtolower(trim($raw));
    if (in_array($m, ['payee_only','payee only','payee','account_payee','a/c payee','ac payee','order','account payee']))
        return 'Payee Only';
    if (in_array($m, ['cash','bearer','open','bearer / cash']))
        return 'Cash / Bearer';
    if (in_array($m, ['third_party_cash','third party cash','3rd party','third_party','third party']))
        return '3rd Party';
    if ($raw === '' || $raw === null) return 'Unknown';
    return ucwords(str_replace('_',' ', $raw));   // keep any other value as-is
}

$mode_colors = [
    'Payee Only'    => ['hdr'=>'#0369a1','sub'=>'#bfdbfe','txt'=>'#1e40af','badge_bg'=>'#dbeafe','badge_txt'=>'#1e40af'],
    'Cash / Bearer' => ['hdr'=>'#16a34a','sub'=>'#bbf7d0','txt'=>'#166534','badge_bg'=>'#dcfce7','badge_txt'=>'#166534'],
    '3rd Party'     => ['hdr'=>'#d97706','sub'=>'#fde68a','txt'=>'#92400e','badge_bg'=>'#fef3c7','badge_txt'=>'#92400e'],
    'Unknown'       => ['hdr'=>'#6b7280','sub'=>'#e5e7eb','txt'=>'#374151','badge_bg'=>'#f3f4f6','badge_txt'=>'#374151'],
    '__other__'     => ['hdr'=>'#7c3aed','sub'=>'#ddd6fe','txt'=>'#5b21b6','badge_bg'=>'#ede9fe','badge_txt'=>'#5b21b6'],
];
function modeColor(string $label, array $map): array {
    return $map[$label] ?? $map['__other__'];
}

/* ══════════════════════════════════════════════════
   COMPANIES — fetch first (needed for default)
══════════════════════════════════════════════════ */
$comp_res  = mysqli_query($conn, "SELECT id, company_name FROM companies WHERE active=1 ORDER BY company_name ASC");
$companies = [];
if ($comp_res) while ($c = mysqli_fetch_assoc($comp_res)) $companies[] = $c;

/* ── filters ── */
$date_from  = trim($_GET['date_from'] ?? date('Y-m-01'));
$date_to    = trim($_GET['date_to']   ?? date('Y-m-d'));
$company_id = isset($_GET['company_id']) ? intval($_GET['company_id']) : (!empty($companies) ? intval($companies[0]['id']) : 0);

$df_esc = mysqli_real_escape_string($conn, $date_from);
$dt_esc = mysqli_real_escape_string($conn, $date_to);

$company_label = 'All Companies';
foreach ($companies as $c) {
    if ($company_id && $c['id'] == $company_id) { $company_label = $c['company_name']; break; }
}

/* ══════════════════════════════════════════════════
   DELIVERY PERSON NAME EXPRESSION
   Priority: employees.employee_full_name (via fs.employee_id)
             → fs.delivery_person_raw_name
             → fallback to "SR: <sr_code>" so old/legacy rows
               without a delivery person assigned still show up.
   Used identically in every query below.
══════════════════════════════════════════════════ */
const DP_NAME_EXPR = "COALESCE(NULLIF(TRIM(e.employee_full_name),''), NULLIF(TRIM(fs.delivery_person_raw_name),''), CONCAT('SR: ', fs.sr_code))";

/* ══════════════════════════════════════════════════
   STEP 1 — discover all distinct normalised modes
   within the filtered date + company scope
══════════════════════════════════════════════════ */
$cj = $company_id ? "INNER JOIN company_bank_accounts cba ON cba.id = ch.deposited_account_id" : '';
$cw = $company_id ? "AND cba.company_id = $company_id" : '';

$raw_modes_q = mysqli_query($conn, "
    SELECT DISTINCT LOWER(TRIM(COALESCE(ch.cheque_mode,''))) AS raw_mode
    FROM cheques ch
    INNER JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id
    INNER JOIN field_summary fs    ON fs.id  = ip.field_summary_id
    LEFT JOIN employees e          ON e.id   = fs.employee_id
    $cj
    WHERE COALESCE(ch.received_date, ip.payment_date) >= '$df_esc'
      AND COALESCE(ch.received_date, ip.payment_date) <= '$dt_esc'
      $cw
    ORDER BY raw_mode ASC
");

/* Build ordered unique normalised mode list */
$mode_labels = [];   /* ordered unique display labels */
$raw_to_norm = [];   /* raw_value => normalised label */
if ($raw_modes_q) {
    while ($rm = mysqli_fetch_assoc($raw_modes_q)) {
        $norm = normaliseMode($rm['raw_mode']);
        $raw_to_norm[$rm['raw_mode']] = $norm;
        if (!in_array($norm, $mode_labels)) $mode_labels[] = $norm;
    }
}
/* Sort: known first, unknowns last */
$known_order = ['Payee Only','Cash / Bearer','3rd Party'];
usort($mode_labels, function($a, $b) use ($known_order) {
    $ai = array_search($a, $known_order); $bi = array_search($b, $known_order);
    $ai = ($ai === false) ? 99 : $ai; $bi = ($bi === false) ? 99 : $bi;
    return $ai !== $bi ? $ai - $bi : strcmp($a, $b);
});

/* ══════════════════════════════════════════════════
   STEP 2 — build dynamic GROUP-BY query
   One COUNT per normalised mode using CASE WHEN,
   grouped by delivery person (see DP_NAME_EXPR above).
══════════════════════════════════════════════════ */
function buildDynamicQuery($conn, $df, $dt, $company_id, $mode_labels, $raw_to_norm) {
    $cj = $company_id ? "INNER JOIN company_bank_accounts cba ON cba.id = ch.deposited_account_id" : '';
    $cw = $company_id ? "AND cba.company_id = $company_id" : '';
    $dp_expr = DP_NAME_EXPR;

    /* Build a CASE per normalised label */
    $cases = '';
    foreach ($mode_labels as $lbl) {
        $matching_raws = array_keys($raw_to_norm, $lbl);
        if (empty($matching_raws)) { $cases .= ", 0 AS `mode_".md5($lbl)."`"; continue; }
        $in_list = implode(',', array_map(fn($v) => "'".mysqli_real_escape_string($conn,$v)."'", $matching_raws));
        $col     = 'mode_'.md5($lbl);
        $cases  .= ",\n            SUM(CASE WHEN LOWER(TRIM(COALESCE(ch.cheque_mode,''))) IN ($in_list) THEN 1 ELSE 0 END) AS `$col`";
    }

    return "
        SELECT
            $dp_expr AS delivery_person,
            COUNT(*) AS total_cheque
            $cases
        FROM cheques ch
        INNER JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id
        INNER JOIN field_summary fs    ON fs.id  = ip.field_summary_id
        LEFT JOIN employees e           ON e.id   = fs.employee_id
        $cj
        WHERE COALESCE(ch.received_date, ip.payment_date) >= '$df'
          AND COALESCE(ch.received_date, ip.payment_date) <= '$dt'
          $cw
        GROUP BY delivery_person
        ORDER BY delivery_person ASC";
}

/* col key helper */
function modeCol(string $lbl): string { return 'mode_'.md5($lbl); }

/* ══════════════════════════════════════════════════
   EXCEL EXPORT
══════════════════════════════════════════════════ */
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $sql = buildDynamicQuery($conn, $df_esc, $dt_esc, $company_id, $mode_labels, $raw_to_norm);
    $res = mysqli_query($conn, $sql);
    $rows = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;

    /* totals */
    $col_totals = array_fill_keys($mode_labels, 0);
    $grand_total = 0;
    foreach ($rows as $r) {
        $grand_total += intval($r['total_cheque']);
        foreach ($mode_labels as $lbl) $col_totals[$lbl] += intval($r[modeCol($lbl)] ?? 0);
    }

    $total_cols = 1 + count($mode_labels) * 2 + 1;
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="cheque_mode_report_dp_' . date('Ymd') . '.xls"');
    echo '<html><head><meta charset="UTF-8"><style>
    th,td{border:1px solid #000;font-family:Arial;font-size:11pt;}
    .h1{background:#1E1B4B;color:#fff;font-weight:bold;text-align:center;}
    .ht{background:#0369A1;color:#fff;font-weight:bold;text-align:center;}
    .hq{background:#FCD34D;font-weight:bold;text-align:center;}
    .tf{background:#1E293B;color:#fff;font-weight:bold;}
    .ev{background:#F8F9FA;}.od{background:#fff;}
    </style></head><body><table>';
    echo '<tr><td colspan="'.$total_cols.'" class="h1" style="font-size:13pt;">Yelo Distributors Pvt Ltd — '.htmlspecialchars($company_label).'</td></tr>';
    echo '<tr><td colspan="'.$total_cols.'" class="h1" style="font-size:12pt;">Cheque Mode Report (by Received Date) — Delivery Person Wise</td></tr>';
    echo '<tr><td colspan="'.$total_cols.'" class="h1">From '.htmlspecialchars($date_from).' to '.htmlspecialchars($date_to).'</td></tr>';
    /* header row 1 */
    echo '<tr><td class="h1" rowspan="2">Delivery Person</td>';
    foreach ($mode_labels as $lbl) echo '<td colspan="2" class="h1" style="background:#0369a1;">'.htmlspecialchars($lbl).'</td>';
    echo '<td class="ht" rowspan="2">Total</td></tr>';
    /* header row 2 */
    echo '<tr>';
    foreach ($mode_labels as $lbl) echo '<td class="hq">Qty</td><td class="hq">%</td>';
    echo '</tr>';
    foreach ($rows as $i => $r) {
        $cls = ($i % 2 === 0) ? 'ev' : 'od';
        $t   = intval($r['total_cheque']);
        echo '<tr class="'.$cls.'"><td>'.htmlspecialchars($r['delivery_person']).'</td>';
        foreach ($mode_labels as $lbl) {
            $q = intval($r[modeCol($lbl)] ?? 0);
            $p = $t ? round($q/$t*100).'%' : '0%';
            echo '<td align="center">'.$q.'</td><td align="center">'.$p.'</td>';
        }
        echo '<td align="center">'.$t.'</td></tr>';
    }
    /* total row */
    echo '<tr class="tf"><td>Total</td>';
    foreach ($mode_labels as $lbl) {
        $q = $col_totals[$lbl];
        $p = $grand_total ? round($q/$grand_total*100).'%' : '0%';
        echo '<td align="center">'.$q.'</td><td align="center">'.$p.'</td>';
    }
    echo '<td align="center">'.$grand_total.'</td></tr>';
    echo '</table></body></html>';
    exit;
}

/* ══════════════════════════════════════════════════
   FETCH DATA for page
══════════════════════════════════════════════════ */
$sql  = buildDynamicQuery($conn, $df_esc, $dt_esc, $company_id, $mode_labels, $raw_to_norm);
$res  = mysqli_query($conn, $sql);
$rows = [];
if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;

/* totals per mode col */
$col_totals  = array_fill_keys($mode_labels, 0);
$grand_total = 0;
foreach ($rows as $r) {
    $grand_total += intval($r['total_cheque']);
    foreach ($mode_labels as $lbl) $col_totals[$lbl] += intval($r[modeCol($lbl)] ?? 0);
}

$total_colspan = 1 + count($mode_labels) * 2 + 1;
?>
<style>
*,*::before,*::after{box-sizing:border-box}
.cmr-wrap{max-width:100%;margin:0 auto;padding:0 0 48px;}
.cmr-page-title{font-size:22px;font-weight:800;color:#1e1b4b;margin:0 0 4px;}
.cmr-page-sub{font-size:13px;color:#6b7280;margin:0 0 20px;}

/* filter */
.cmr-filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:12px;padding:16px 20px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.cmr-filter-row{display:grid;grid-template-columns:1.4fr 1fr 1fr auto auto auto;gap:12px;align-items:end;}
.cmr-fg{display:flex;flex-direction:column;gap:5px;}
.cmr-fg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;}
.cmr-fg input,.cmr-fg select{border:1.5px solid #e0e7ff;border-radius:8px;padding:9px 12px;font-size:13px;font-family:inherit;color:#1f2937;outline:none;transition:border .2s;background:#fff;width:100%;}
.cmr-fg input:focus,.cmr-fg select:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1);}
.cmr-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .2s;text-decoration:none;white-space:nowrap;}
.cmr-btn-primary{background:#6366f1;color:#fff;}.cmr-btn-primary:hover{background:#4f46e5;}
.cmr-btn-excel{background:#16a34a;color:#fff;}.cmr-btn-excel:hover{background:#15803d;}
.cmr-btn-print{background:#374151;color:#fff;}.cmr-btn-print:hover{background:#1f2937;}

/* report */
.cmr-report{background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.09);border:1px solid #e5e5e5;}

/* company header */
.cmr-co-hdr{background:linear-gradient(135deg,#1e1b4b 0%,#312e81 100%);padding:18px 24px 15px;}
.cmr-co-name{font-size:17px;font-weight:800;color:#fff;}
.cmr-co-report-lbl{font-size:13px;font-weight:600;color:#a5b4fc;margin-top:3px;}
.cmr-co-meta{display:flex;gap:18px;margin-top:6px;flex-wrap:wrap;}
.cmr-co-meta-item{display:flex;align-items:center;gap:6px;font-size:12px;color:#c7d2fe;}
.cmr-co-meta-item strong{color:#e0e7ff;}
.cmr-co-badge{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.15);border-radius:20px;padding:3px 12px;font-size:12px;font-weight:700;color:#fff;margin-left:8px;}

/* table */
.cmr-tbl-wrap{overflow-x:auto;}
table.cmr-tbl{width:100%;border-collapse:collapse;font-family:inherit;font-size:13px;}

/* sticky header */
table.cmr-tbl thead th{position:sticky;top:0;z-index:10;}

/* row 1: mode group headers */
table.cmr-tbl thead tr.r-top th{
    padding:10px 14px;text-align:center;font-weight:700;font-size:11.5px;
    border:1px solid rgba(255,255,255,.15);
}
table.cmr-tbl thead tr.r-top th.th-sr{
    text-align:left;background:#1e1b4b;color:#e0e7ff;
    min-width:150px;position:sticky;top:0;z-index:11;
}
table.cmr-tbl thead tr.r-top th.th-total{background:#0369a1;color:#fff;}

/* row 2: Qty/% sub-headers */
table.cmr-tbl thead tr.r-qty th{
    background:#fcd34d;color:#1f2937;font-weight:800;font-size:11px;
    padding:7px 14px;text-align:center;border:1px solid #e8b800;
}
table.cmr-tbl thead tr.r-qty th.th-sr-ph{background:#1e1b4b;border:1px solid rgba(255,255,255,.08);}
table.cmr-tbl thead tr.r-qty th.th-tot-ph{background:#0369a1;border:1px solid rgba(0,0,0,.12);}

/* body */
table.cmr-tbl tbody tr.ev td{background:#f8f9ff;}
table.cmr-tbl tbody tr.od td{background:#fff;}
table.cmr-tbl tbody tr:hover td{background:#ede9fe!important;transition:background .1s;}
table.cmr-tbl tbody td{padding:9px 13px;border:1px solid #ececec;color:#374151;vertical-align:middle;}
table.cmr-tbl tbody td.td-sr{font-weight:700;color:#1e1b4b;text-align:left;white-space:nowrap;}
table.cmr-tbl tbody td.td-qty{text-align:center;font-weight:700;font-family:'Courier New',monospace;}
table.cmr-tbl tbody td.td-pct{text-align:center;}
table.cmr-tbl tbody td.td-total{text-align:center;font-weight:800;color:#0369a1;font-family:'Courier New',monospace;}
table.cmr-tbl tbody td.td-zero{opacity:.3;}

/* badge */
.pct-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;}

/* tfoot */
table.cmr-tbl tfoot tr td{
    background:#1e293b;color:#fff;font-weight:800;font-size:13px;
    padding:11px 13px;border:1px solid #334155;
    position:sticky;bottom:0;
}
table.cmr-tbl tfoot tr td.tf-sr{text-align:left;}
table.cmr-tbl tfoot tr td.tf-qty{text-align:center;font-family:'Courier New',monospace;}
table.cmr-tbl tfoot tr td.tf-pct{text-align:center;}
table.cmr-tbl tfoot tr td.tf-total{text-align:center;color:#7dd3fc;font-family:'Courier New',monospace;}

/* legend / pills */
.cmr-legend{display:flex;gap:8px;padding:12px 18px;background:#f8faff;border-bottom:1px solid #e0e7ff;flex-wrap:wrap;align-items:center;}
.cmr-legend-lbl{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-right:4px;}
.cmr-legend-item{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:700;border:1.5px solid transparent;}

.cmr-pills{display:flex;gap:10px;padding:14px 18px;background:#f8faff;border-top:1px solid #e0e7ff;flex-wrap:wrap;}
.cmr-pill{background:#fff;border:1.5px solid #e0e7ff;border-radius:10px;padding:10px 14px;display:flex;flex-direction:column;gap:2px;min-width:110px;}
.cmr-pill-lbl{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;}
.cmr-pill-val{font-size:17px;font-weight:800;}

/* empty */
.cmr-empty{text-align:center;padding:60px 20px;color:#9ca3af;}
.cmr-empty i{font-size:42px;display:block;margin-bottom:12px;opacity:.3;}

@media(max-width:800px){.cmr-filter-row{grid-template-columns:1fr 1fr;}}
@media(max-width:500px){.cmr-filter-row{grid-template-columns:1fr;}}
@media print{
    .cmr-filter-card,.no-print{display:none!important;}
    .cmr-wrap{max-width:100%;}
    .cmr-report{box-shadow:none;border:none;}
    table.cmr-tbl thead th,table.cmr-tbl tfoot td,
    table.cmr-tbl tbody tr.ev td,.pct-badge,
    .cmr-co-hdr,.cmr-legend,.cmr-pills
    {-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}
</style>

<div class="cmr-wrap">
  <h2 class="cmr-page-title no-print"><i class="fa-solid fa-money-check" style="color:#6366f1;margin-right:8px;"></i>Cheque Mode Report — Delivery Person Wise</h2>
  <p class="cmr-page-sub no-print">Dynamic mode breakdown by Delivery Person — filtered by Cheque Received Date</p>

  <!-- Filter -->
  <div class="cmr-filter-card no-print">
    <div class="cmr-filter-row">
      <div class="cmr-fg">
        <label><i class="fa-solid fa-building"></i> Company</label>
        <select id="f_company">
          <option value="0">— All Companies —</option>
          <?php foreach ($companies as $c): ?>
          <option value="<?=$c['id']?>" <?=$company_id==$c['id']?'selected':''?>><?=htmlspecialchars($c['company_name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cmr-fg">
        <label><i class="fa-solid fa-calendar-check"></i> Received From</label>
        <input type="date" id="f_from" value="<?=htmlspecialchars($date_from)?>">
      </div>
      <div class="cmr-fg">
        <label><i class="fa-solid fa-calendar-check"></i> Received To</label>
        <input type="date" id="f_to" value="<?=htmlspecialchars($date_to)?>">
      </div>
      <button class="cmr-btn cmr-btn-primary" onclick="applyFilter()">
        <i class="fa-solid fa-magnifying-glass"></i> Generate
      </button>
      <button class="cmr-btn cmr-btn-excel" onclick="exportExcel()">
        <i class="fa-solid fa-file-excel"></i> Excel
      </button>
      <button class="cmr-btn cmr-btn-print" onclick="window.print()">
        <i class="fa-solid fa-print"></i> Print
      </button>
    </div>
  </div>

  <!-- Report -->
  <div class="cmr-report">

    <!-- Company header -->
    <div class="cmr-co-hdr">
      <div class="cmr-co-name">
        <i class="fa-solid fa-building" style="color:#a5b4fc;margin-right:8px;"></i>
        Yelo Distributors Pvt Ltd
        <?php if ($company_id): ?>
        <span class="cmr-co-badge"><i class="fa-solid fa-filter"></i> <?=htmlspecialchars($company_label)?></span>
        <?php endif; ?>
      </div>
      <div class="cmr-co-report-lbl"><i class="fa-solid fa-money-check" style="margin-right:5px;"></i>Cheque Mode Report — Delivery Person Wise</div>
      <div class="cmr-co-meta">
        <div class="cmr-co-meta-item"><i class="fa-solid fa-calendar-check"></i> Received: <strong><?=htmlspecialchars($date_from)?></strong> → <strong><?=htmlspecialchars($date_to)?></strong></div>
        <div class="cmr-co-meta-item"><i class="fa-solid fa-building"></i> <strong><?=htmlspecialchars($company_label)?></strong></div>
        <?php if (!empty($mode_labels)): ?>
        <div class="cmr-co-meta-item"><i class="fa-solid fa-layer-group"></i> <strong><?=count($mode_labels)?> mode(s) found</strong></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($mode_labels)): ?>
    <!-- Mode legend -->
    <div class="cmr-legend">
      <span class="cmr-legend-lbl"><i class="fa-solid fa-circle-info"></i> Modes:</span>
      <?php foreach ($mode_labels as $lbl):
          $clr = modeColor($lbl, $mode_colors);
      ?>
      <span class="cmr-legend-item" style="background:<?=$clr['badge_bg']?>;color:<?=$clr['badge_txt']?>;border-color:<?=$clr['hdr']?>40;">
        <i class="fa-solid fa-circle" style="font-size:7px;"></i>
        <?=htmlspecialchars($lbl)?>
        <span style="font-weight:500;opacity:.75;">(<?=$col_totals[$lbl]?>)</span>
      </span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Table -->
    <div class="cmr-tbl-wrap">
      <table class="cmr-tbl">
        <thead>
          <!-- Row 1: Delivery Person | [mode group headers] | Total -->
          <tr class="r-top">
            <th class="th-sr" rowspan="2">Delivery Person</th>
            <?php foreach ($mode_labels as $lbl):
                $clr = modeColor($lbl, $mode_colors);
            ?>
            <th colspan="2" style="background:<?=$clr['hdr']?>;color:#fff;border:1px solid rgba(255,255,255,.2);">
              <?=htmlspecialchars($lbl)?>
            </th>
            <?php endforeach; ?>
            <th class="th-total" rowspan="2">Total<br>Cheque</th>
          </tr>
          <!-- Row 2: Qty / % per mode -->
          <tr class="r-qty">
            <th class="th-sr-ph" style="display:none;"></th>
            <?php foreach ($mode_labels as $lbl):
                $clr = modeColor($lbl, $mode_colors);
            ?>
            <th style="background:<?=$clr['sub']?>;color:<?=$clr['txt']?>;border:1px solid <?=$clr['hdr']?>40;">Qty</th>
            <th style="background:<?=$clr['sub']?>;color:<?=$clr['txt']?>;border:1px solid <?=$clr['hdr']?>40;">%</th>
            <?php endforeach; ?>
            <th class="th-tot-ph" style="display:none;"></th>
          </tr>
        </thead>

        <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="<?=$total_colspan?>" class="cmr-empty">
            <i class="fa-solid fa-inbox"></i>
            <p style="font-size:14px;font-weight:600;margin:0 0 6px;">No cheques found.</p>
            <small>Try adjusting the company or date range.</small>
          </td></tr>
        <?php else: ?>
        <?php foreach ($rows as $i => $row):
            $t   = intval($row['total_cheque']);
            $cls = ($i % 2 === 0) ? 'ev' : 'od';
        ?>
          <tr class="<?=$cls?>">
            <td class="td-sr"><?=htmlspecialchars($row['delivery_person'] ?? '—')?></td>
            <?php foreach ($mode_labels as $lbl):
                $clr = modeColor($lbl, $mode_colors);
                $q   = intval($row[modeCol($lbl)] ?? 0);
                $p   = $t ? round($q / $t * 100) : 0;
                $zero= ($q === 0) ? ' td-zero' : '';
            ?>
            <td class="td-qty<?=$zero?>"><?=$q ?: '—'?></td>
            <td class="td-pct<?=$zero?>">
              <?php if ($q > 0): ?>
              <span class="pct-badge" style="background:<?=$clr['badge_bg']?>;color:<?=$clr['badge_txt']?>;"><?=$p?>%</span>
              <?php else: ?>
              <span style="color:#d1d5db;font-size:11px;">—</span>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
            <td class="td-total"><?=$t?></td>
          </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>

        <?php if (!empty($rows)): ?>
        <tfoot>
          <tr>
            <td class="tf-sr"><i class="fa-solid fa-sigma" style="margin-right:6px;opacity:.7;"></i>Total</td>
            <?php foreach ($mode_labels as $lbl):
                $clr = modeColor($lbl, $mode_colors);
                $q   = $col_totals[$lbl];
                $p   = $grand_total ? round($q / $grand_total * 100) : 0;
            ?>
            <td class="tf-qty"><?=$q?></td>
            <td class="tf-pct" style="color:<?=$clr['sub']?>;"><?=$p?>%</td>
            <?php endforeach; ?>
            <td class="tf-total"><?=$grand_total?></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>

    <!-- Summary pills -->
    <?php if (!empty($rows)): ?>
    <div class="cmr-pills">
      <div class="cmr-pill">
        <span class="cmr-pill-lbl"><i class="fa-solid fa-id-badge"></i> Delivery Persons</span>
        <span class="cmr-pill-val" style="color:#1e1b4b;"><?=count($rows)?></span>
      </div>
      <?php foreach ($mode_labels as $lbl):
          $clr = modeColor($lbl, $mode_colors);
          $q   = $col_totals[$lbl];
          $p   = $grand_total ? round($q / $grand_total * 100) : 0;
      ?>
      <div class="cmr-pill" style="border-color:<?=$clr['hdr']?>40;">
        <span class="cmr-pill-lbl" style="color:<?=$clr['txt']?>;"><?=htmlspecialchars($lbl)?></span>
        <span class="cmr-pill-val" style="color:<?=$clr['hdr']?>;"><?=$q?> <span style="font-size:12px;font-weight:600;">(<?=$p?>%)</span></span>
      </div>
      <?php endforeach; ?>
      <div class="cmr-pill" style="border-color:#0369a140;">
        <span class="cmr-pill-lbl" style="color:#0369a1;"><i class="fa-solid fa-hashtag"></i> Total</span>
        <span class="cmr-pill-val" style="color:#0369a1;"><?=$grand_total?></span>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /cmr-report -->
</div><!-- /cmr-wrap -->

<script>
function applyFilter() {
    const from = document.getElementById('f_from').value;
    const to   = document.getElementById('f_to').value;
    const co   = document.getElementById('f_company').value;
    if (!from || !to) { alert('Please select both dates.'); return; }
    window.location.href = 'cheque_mode_report_dp.php?date_from=' + encodeURIComponent(from)
        + '&date_to=' + encodeURIComponent(to)
        + '&company_id=' + encodeURIComponent(co);
}
function exportExcel() {
    const from = document.getElementById('f_from').value;
    const to   = document.getElementById('f_to').value;
    const co   = document.getElementById('f_company').value;
    window.location.href = 'cheque_mode_report_dp.php?date_from=' + encodeURIComponent(from)
        + '&date_to=' + encodeURIComponent(to)
        + '&company_id=' + encodeURIComponent(co)
        + '&export=excel';
}
['f_from','f_to'].forEach(id =>
    document.getElementById(id)?.addEventListener('keydown', e => { if(e.key==='Enter') applyFilter(); })
);
</script>

<?php include 'footer.php'; ?>