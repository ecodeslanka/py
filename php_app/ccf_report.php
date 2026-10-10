<?php
/**
 * ccf_report.php
 * ─────────────────────────────────────────────────────────────────────
 * CCF REPORT — Delivery Person / SR wise, by delivery date
 * (replaces the need for ccf.php (SR) and ccf_dp.php (delivery person):
 *  ONE page, switch "View by" between Delivery Person, SR, or both.)
 *
 * Columns follow the Book4.xlsx layout:
 *      Delivery Person | Delivery Date | Route |
 *      Invoice Count  : Pre Secondary | Canceled | After Secondary | Rate (After sec / pre sec %) | Rate (Canceled / pre sec %)
 *      Invoice Values : Pre Secondary | Canceled | After Secondary | Rate (After sec / pre sec %) | Rate (Canceled / pre sec %)
 *
 * DEFINITIONS
 *   Pre Secondary   = bills loaded for delivery: loading_summary_import_details,
 *                     status 'imported' (bills of blacklisted customers, status
 *                     'cancelled', are left out — same as ccf.php).
 *   After Secondary = those bills that IKEA also has (secondary_invoice_import_details,
 *                     status 'imported', same delivery date + bill no) with a value above 0.
 *                     The value is IKEA's final bill amount.
 *   Canceled        = pre-secondary bills that IKEA has NO bill for, or where IKEA shows 0.
 *                     So   Pre Secondary count = Canceled count + After Secondary count.
 *                     Canceled value = the bills' pre-secondary value.
 *   Rate (After sec/pre sec %)  = After Secondary ÷ Pre Secondary × 100 (counts and values separately).
 *   Rate (Canceled/pre sec %)   = Canceled ÷ Pre Secondary × 100 (counts and values separately:
 *                                 canceled count ÷ pre count, canceled amount ÷ pre amount).
 *                                 A high number here is bad, so these columns are colored the
 *                                 other way round from the after-secondary rate.
 *
 * CORRECTIONS compared with ccf.php / ccf_dp.php
 *   • A bill imported more than once is counted once (the latest import wins);
 *     the old joins counted it, and its value, several times.
 *   • If the same bill is in IKEA's file twice, the LAST import wins (same as edit_field_summary.php).
 *   • Dates on which no IKEA bills were imported at all are left out and listed in a
 *     notice — otherwise every bill of that date would look canceled.
 *   • IKEA value 0 counts as canceled.
 *   • Speed: the old SQL joined the two big tables on unindexed columns (minutes on a
 *     year of data). Now the range is read with two indexed queries and compared in PHP;
 *     an index on delivery_date is added automatically the first time the page opens.
 *   • "Reset" goes back to this page (the old one pointed at loading_summary_report.php).
 *
 * TOTALS / AVERAGES
 *   Table end: TOTAL row (rate = total after ÷ total pre, total canceled ÷ total pre) and
 *   AVERAGE row (simple average of each column over the rows shown; the rate cells average the row rates).
 *   The summary table (one row per person / SR) has its own TOTAL and AVERAGE rows.
 *
 * SUMMARY TABLE — DATE RANGE
 *   Page: the first row inside the summary table is a band with the selected delivery dates
 *   ("Delivery dates: 26 Aug 2026 to 25 Sep 2026 (31 days selected)"), or the single date when
 *   from = to. Excel "Summary" sheet: a Date Range column.
 *
 * CANCELED COUNTS ARE CLICKABLE → dialog with the canceled bills' details.
 * EXPORT → real .xlsx (built on the server with ZipArchive): "Date wise" sheet in the Book4
 *          layout with formulas, a "Summary" sheet and a "Canceled Bills" sheet.
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');

/* config.php ends with stray whitespace after "?>" – swallow it so headers / downloads / JSON stay clean */
ob_start();
include_once 'config.php';
ob_end_clean();
include_once 'auth.php';

const CCF_MAX_DAYS      = 366;      /* longest date range */
const CCF_MAX_CANCELED  = 2000;     /* canceled bills shown in the dialog */
const CCF_MAX_XLS_ROWS  = 20000;    /* canceled bills written to the Excel sheet */

/* Excel cell-style ids (see ccf_xl_styles). Declared up here on purpose: top-level constants are only
   defined once PHP reaches them, and the export endpoint below runs before the rest of the file. */
const XS_TITLE = 1, XS_GROUP = 2, XS_HEAD = 3, XS_TEXT = 4, XS_DATE = 5, XS_INT = 6, XS_MONEY = 7, XS_PCT = 8,
      XS_TTEXT = 9, XS_TINT = 10, XS_TMONEY = 11, XS_TPCT = 12, XS_ATEXT = 13, XS_AINT = 14, XS_AMONEY = 15, XS_APCT = 16, XS_NOTE = 17;

/* ═══════════════════════════ HELPERS ═══════════════════════════ */

function ccf_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* PHP 8.1+ throws on a failed query, older PHP returns false — behave the same on both */
$ccf_db_error = '';
function ccf_q($conn, $sql, $mode = MYSQLI_STORE_RESULT) {
    global $ccf_db_error;
    try {
        $r = mysqli_query($conn, $sql, $mode);
        if ($r === false) $ccf_db_error = mysqli_error($conn);
        return $r;
    } catch (Throwable $e) {
        $ccf_db_error = $e->getMessage();
        return false;
    }
}

function ccf_json($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function ccf_valid_date($d) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}
function ccf_get($key, $default = '') { return (isset($_GET[$key]) && is_string($_GET[$key])) ? trim($_GET[$key]) : $default; }
function ccf_cents($c)  { return number_format($c / 100, 2); }
function ccf_int($n)    { return number_format((int)$n); }
function ccf_pct($p)    { return number_format($p, 1) . '%'; }
function ccf_date($d, $fmt = 'd M Y') { $t = strtotime((string)$d); return $t ? date($fmt, $t) : ''; }
function ccf_rate($num, $den) { return $den > 0 ? $num / $den * 100 : 0.0; }     /* percent */

/* the selected delivery dates as one label: "26 Aug 2026 - 25 Sep 2026", or just the date when from = to */
function ccf_range_label($f) {
    return $f['from'] === $f['to'] ? ccf_date($f['from']) : ccf_date($f['from']) . ' - ' . ccf_date($f['to']);
}

function ccf_table_exists($conn, $t) {
    $r = ccf_q($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'");
    return $r && mysqli_num_rows($r) > 0;
}

/* add a single-column index if no index starts with that column yet (online, harmless to readers/writers) */
function ccf_ensure_index($conn, $table, $name, $col) {
    $r = ccf_q($conn, "SHOW INDEX FROM `$table`");
    if (!$r) return;
    while ($i = mysqli_fetch_assoc($r)) {
        if ((int)$i['Seq_in_index'] === 1 && $i['Column_name'] === $col) return;
    }
    ccf_q($conn, "ALTER TABLE `$table` ADD INDEX `$name` (`$col`), ALGORITHM=INPLACE, LOCK=NONE");
}
function ccf_ensure_schema($conn) {
    if (ccf_table_exists($conn, 'loading_summary_import_details'))   ccf_ensure_index($conn, 'loading_summary_import_details',   'idx_ccf_delivery_date', 'delivery_date');
    if (ccf_table_exists($conn, 'secondary_invoice_import_details')) ccf_ensure_index($conn, 'secondary_invoice_import_details', 'idx_cbl_delivery_date', 'delivery_date');
}

/* Month N covers the 26th of the previous month → the 25th of month N (same as ccf.php) */
function ccf_month_range($ym) {
    list($y, $m) = array_map('intval', explode('-', $ym));
    $py = $m === 1 ? $y - 1 : $y;
    $pm = $m === 1 ? 12 : $m - 1;
    return [sprintf('%04d-%02d-26', $py, $pm), sprintf('%04d-%02d-25', $y, $m)];
}

/* ═══════════════════════════ FILTERS ═══════════════════════════ */

function ccf_filters() {
    $month = ccf_get('month');
    if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
    $df = ccf_get('date_from'); $dt = ccf_get('date_to');
    $df = ccf_valid_date($df) ? $df : ''; $dt = ccf_valid_date($dt) ? $dt : '';

    if ($df !== '' || $dt !== '') {                     /* an explicit range overrides the month */
        $from = $df !== '' ? $df : $dt;
        $to   = $dt !== '' ? $dt : $df;
        $using_month = false;
    } else {
        list($from, $to) = ccf_month_range($month);
        $using_month = true;
    }
    if ($from > $to) { $t = $from; $from = $to; $to = $t; }
    $limited = false;
    if ((new DateTime($from))->diff(new DateTime($to))->days >= CCF_MAX_DAYS) {
        $from = date('Y-m-d', strtotime($to . ' -' . (CCF_MAX_DAYS - 1) . ' days'));
        $limited = true;
    }

    $view = ccf_get('view', 'dp');
    if (!in_array($view, ['dp', 'sr', 'both'], true)) $view = 'dp';
    $cut = function ($s) { return strlen($s) > 100 ? substr($s, 0, 100) : $s; };

    return ['month' => $month, 'date_from' => $df, 'date_to' => $dt, 'from' => $from, 'to' => $to,
            'using_month' => $using_month, 'limited' => $limited, 'view' => $view,
            'route' => $cut(ccf_get('route')), 'sr' => $cut(ccf_get('sr')), 'dp' => $cut(ccf_get('dp'))];
}

function ccf_url($f, $over = []) {
    $p = array_merge(['month' => $f['month'], 'date_from' => $f['date_from'], 'date_to' => $f['date_to'], 'view' => $f['view'],
                      'route' => $f['route'], 'sr' => $f['sr'], 'dp' => $f['dp']], $over);
    foreach (['date_from', 'date_to', 'route', 'sr', 'dp'] as $k) if (($p[$k] ?? '') === '') unset($p[$k]);
    if (($p['view'] ?? 'dp') === 'dp') unset($p['view']);
    return basename(__FILE__) . '?' . http_build_query($p);
}

/* ═══════════════════════════ DATA ═══════════════════════════ */

function ccf_zero() { return ['pre_n' => 0, 'pre_v' => 0, 'can_n' => 0, 'can_v' => 0, 'aft_n' => 0, 'aft_v' => 0]; }

/*
 * Reads the range once and compares in PHP (no slow joins). Returns:
 *   rows      one per (date, route, person/SR) in date order
 *   summary   one per person / SR (/ both) with the days worked
 *   total     grand totals;  days = number of delivery dates with data
 *   canceled  the canceled bills (only when $collect)
 *   unchecked dates with bills but no IKEA import (left out)
 *   dp_options / sr_options  everything found in the range (for the filter drop-downs)
 */
function ccf_compute($conn, $f, $collect = false) {
    global $ccf_db_error;
    $t0  = microtime(true);
    $out = ['rows' => [], 'summary' => [], 'total' => ccf_zero(), 'days' => 0, 'unchecked' => [], 'canceled' => [],
            'canceled_truncated' => false, 'dp_options' => [], 'sr_options' => [], 'scanned' => 0, 'ms' => 0, 'error' => '', 'no_data' => false];

    if (!ccf_table_exists($conn, 'loading_summary_import_details') || !ccf_table_exists($conn, 'secondary_invoice_import_details')) {
        $out['no_data'] = true;
        return $out;
    }
    $from = $f['from'];     /* both validated YYYY-MM-DD */
    $to   = $f['to'];

    /* 1 ── IKEA bills of the range, read once ("last import wins" via ORDER BY id) */
    $ikea = [];             /* [date][bill_no] => IKEA value in cents */
    $r = ccf_q($conn, "SELECT delivery_date, bill_no, final_bill_amount FROM secondary_invoice_import_details
                       WHERE status = 'imported' AND delivery_date BETWEEN '$from' AND '$to' ORDER BY id", MYSQLI_USE_RESULT);
    if (!$r) { $out['error'] = $ccf_db_error; return $out; }
    while ($row = mysqli_fetch_row($r)) $ikea[$row[0]][trim((string)$row[1])] = (int)round(((float)$row[2]) * 100);
    mysqli_free_result($r);

    /* 2 ── route names */
    $routes = [];
    $rr = ccf_q($conn, "SELECT route_code, route_name FROM routes");
    if ($rr) while ($x = mysqli_fetch_row($rr)) $routes[(string)$x[0]] = (string)$x[1];

    /* 3 ── which import of each bill is the latest? (a bill imported twice counts once, the later row wins).
            Only the ids are kept here, so even a year of bills stays small in memory. */
    $last = [];
    $r = ccf_q($conn, "SELECT id, delivery_date, bill_no FROM loading_summary_import_details
                       WHERE status = 'imported' AND delivery_date BETWEEN '$from' AND '$to' ORDER BY id", MYSQLI_USE_RESULT);
    if (!$r) { $out['error'] = $ccf_db_error; return $out; }
    while ($x = mysqli_fetch_row($r)) {
        $bn = trim((string)$x[2]);
        $last[$x[1] . '|' . ($bn !== '' ? $bn : '#' . $x[0])] = (int)$x[0];       /* a bill with no number cannot be matched or de-duplicated */
    }
    mysqli_free_result($r);

    /* 4 ── read the bills again, keep only the latest import of each, compare with IKEA and add up (streamed, nothing stored per bill) */
    $r = ccf_q($conn, "SELECT id, delivery_date, route_code, route_name, sales_person_code, delivery_person, bill_no, final_bill_amount,
                              t_code, customer_name, party_name
                       FROM loading_summary_import_details
                       WHERE status = 'imported' AND delivery_date BETWEEN '$from' AND '$to'", MYSQLI_USE_RESULT);
    if (!$r) { $out['error'] = $ccf_db_error; return $out; }

    $view = $f['view'];
    $agg = []; $sum = []; $sumDates = []; $allDates = []; $canceled = [];
    while ($x = mysqli_fetch_row($r)) {
        $bn = trim((string)$x[6]);
        if ($last[$x[1] . '|' . ($bn !== '' ? $bn : '#' . $x[0])] !== (int)$x[0]) continue;      /* an older import of the same bill */

        $date  = $x[1];
        $route = trim((string)$x[2]);
        $sr    = trim((string)$x[4]);
        $dp    = trim((string)$x[5]) !== '' ? trim((string)$x[5]) : '(Unassigned)';

        /* filter options come from everything in the range, before the filters are applied */
        $out['dp_options'][$dp] = true;
        if ($sr !== '') $out['sr_options'][$sr] = true;

        if ($f['route'] !== '' && $route !== ($f['route'] === '(No route)' ? '' : $f['route'])) continue;
        if ($f['sr']    !== '' && $sr    !== ($f['sr']    === '(No SR)'    ? '' : $f['sr']))    continue;
        if ($f['dp']    !== '' && $dp    !== $f['dp'])    continue;
        $out['scanned']++;

        if (!isset($ikea[$date])) { $out['unchecked'][$date] = ($out['unchecked'][$date] ?? 0) + 1; continue; }   /* no IKEA import that day */

        $cents = (int)round(((float)$x[7]) * 100);
        $ic    = $bn !== '' ? ($ikea[$date][$bn] ?? null) : null;
        $can   = ($ic === null || $ic === 0);
        $sr_lbl = $sr !== '' ? $sr : '(No SR)';
        $route_lbl = $routes[$route] ?? (trim((string)$x[3]) !== '' ? trim((string)$x[3]) : ($route !== '' ? $route : '(No route)'));

        $rkey = $view === 'dp' ? $dp : ($view === 'sr' ? $sr_lbl : $dp . '|' . $sr_lbl);
        $k = $date . '|' . $route . '|' . $rkey;
        if (!isset($agg[$k])) $agg[$k] = ['date' => $date, 'route' => $route, 'route_name' => $route_lbl,
            'dp' => $view === 'sr' ? '' : $dp, 'sr' => $view === 'dp' ? '' : $sr_lbl] + ccf_zero();
        if (!isset($sum[$rkey])) { $sum[$rkey] = ['dp' => $view === 'sr' ? '' : $dp, 'sr' => $view === 'dp' ? '' : $sr_lbl] + ccf_zero(); $sumDates[$rkey] = []; }
        $sumDates[$rkey][$date] = true; $allDates[$date] = true;

        $add = function (&$t) use ($cents, $can, $ic) {
            $t['pre_n']++; $t['pre_v'] += $cents;
            if ($can) { $t['can_n']++; $t['can_v'] += $cents; }
            else      { $t['aft_n']++; $t['aft_v'] += $ic; }
        };
        $add($agg[$k]); $add($sum[$rkey]); $add($out['total']);

        if ($collect && $can) {
            if (count($canceled) < CCF_MAX_XLS_ROWS) {
                $cust = trim((string)$x[9]) !== '' ? trim((string)$x[9]) : trim((string)$x[10]);
                $canceled[] = ['date' => $date, 'bill' => $bn, 'cust' => $cust, 't_code' => trim((string)$x[8]), 'route' => $route_lbl,
                               'sr' => $sr, 'dp' => $dp, 'cents' => $cents, 'why' => $ic === null ? 'No IKEA bill' : 'IKEA value 0'];
            } else { $out['canceled_truncated'] = true; }
        }
    }
    mysqli_free_result($r);
    unset($last);
    ksort($out['unchecked']);

    /* 5 ── order: delivery date, then person / SR, then route */
    $lab = function ($a) { return strtolower($a['dp'] . '|' . $a['sr']); };
    $rows = array_values($agg);
    usort($rows, function ($a, $b) use ($lab) { return strcmp($a['date'], $b['date']) ?: strcmp($lab($a), $lab($b)) ?: strcasecmp($a['route_name'], $b['route_name']); });
    $summary = [];
    foreach ($sum as $k => $s) { $s['days'] = count($sumDates[$k]); $summary[] = $s; }
    usort($summary, function ($a, $b) use ($lab) { return strcmp($lab($a), $lab($b)); });
    usort($canceled, function ($a, $b) { return strcmp($a['date'], $b['date']) ?: strcasecmp($a['dp'], $b['dp']) ?: strcmp($a['bill'], $b['bill']); });

    $out['rows']     = $rows;
    $out['summary']  = $summary;
    $out['days']     = count($allDates);
    $out['canceled'] = $canceled;
    $out['ms']       = (int)round((microtime(true) - $t0) * 1000);
    return $out;
}

/* ═══════════════════════════ AJAX / EXPORT ENDPOINTS ═══════════════════════════ */

$ccf_ajax   = ccf_get('ajax');
$ccf_export = ccf_get('export');

if ($ccf_ajax !== '' || $ccf_export !== '') {
    if (!isLoggedIn() && !autoLoginFromCookie()) {
        if ($ccf_export !== '') { http_response_code(401); header('Content-Type: text/plain; charset=utf-8'); echo 'Your session has expired. Sign in again and retry the export.'; exit; }
        ccf_json(['success' => false, 'error' => 'Your session has expired. Reload the page and sign in again.'], 401);
    }
    ccf_ensure_schema($conn);
    $f = ccf_filters();

    /* ── the canceled bills behind one count ── */
    if ($ccf_ajax === 'canceled') {
        $d = ccf_compute($conn, $f, true);
        if ($d['error'] !== '') ccf_json(['success' => false, 'error' => 'The report could not be loaded: ' . $d['error']], 500);
        $bills = []; $tot = 0;
        foreach ($d['canceled'] as $c) $tot += $c['cents'];
        foreach (array_slice($d['canceled'], 0, CCF_MAX_CANCELED) as $c) {
            $bills[] = ['date' => ccf_date($c['date']), 'bill' => $c['bill'], 'customer' => $c['cust'], 't_code' => $c['t_code'], 'route' => $c['route'],
                        'sr' => $c['sr'], 'dp' => $c['dp'], 'value' => ccf_cents($c['cents']), 'why' => $c['why']];
        }
        ccf_json(['success' => true, 'count' => count($d['canceled']), 'shown' => count($bills), 'total' => ccf_cents($tot),
                  'truncated' => count($d['canceled']) > CCF_MAX_CANCELED, 'bills' => $bills]);
    }

    /* ── Excel download ── */
    if ($ccf_export === 'xlsx') {
        if (!class_exists('ZipArchive')) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); echo 'The PHP ZipArchive extension is needed to create Excel files.'; exit; }
        $d = ccf_compute($conn, $f, true);
        if ($d['error'] !== '' || $d['no_data']) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); echo 'The report could not be loaded. ' . $d['error']; exit; }
        $path = ccf_build_xlsx($f, $d);
        if ($path === '') { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); echo 'The Excel file could not be created.'; exit; }
        $name = 'CCF_Report_' . ['dp' => 'DeliveryPerson', 'sr' => 'SR', 'both' => 'DP_SR'][$f['view']] . '_' . $f['from'] . '_to_' . $f['to'] . '.xlsx';
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        @unlink($path);
        exit;
    }
    ccf_json(['success' => false, 'error' => 'Unknown request.'], 400);
}

/* ═══════════════════════════ EXCEL WRITER ═══════════════════════════ */

function ccf_xl_col($i) { $s = ''; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; }
function ccf_xl_esc($s) {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$s);
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function ccf_xl_serial($d) { return (int)(strtotime($d . ' 00:00:00 UTC') / 86400) + 25569; }

function ccf_xl_styles() {
    $fonts = '<font><sz val="10"/><name val="Arial"/></font>'
           . '<font><b/><sz val="10"/><name val="Arial"/></font>'
           . '<font><b/><sz val="14"/><name val="Arial"/></font>'
           . '<font><i/><sz val="9"/><color rgb="FF595959"/><name val="Arial"/></font>';
    $fills = '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
           . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFF00"/><bgColor indexed="64"/></patternFill></fill>'
           . '<fill><patternFill patternType="solid"><fgColor rgb="FFD9D9D9"/><bgColor indexed="64"/></patternFill></fill>'
           . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill>'
           . '<fill><patternFill patternType="solid"><fgColor rgb="FFDDEBF7"/><bgColor indexed="64"/></patternFill></fill>';
    $borders = '<border><left/><right/><top/><bottom/><diagonal/></border>'
             . '<border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right>'
             . '<top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/></border>';
    $numFmts = '<numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/><numFmt numFmtId="165" formatCode="0.0%"/><numFmt numFmtId="166" formatCode="#,##0.0"/>';
    /* font, fill, border, numFmt, horizontal, wrap */
    $xf = function ($font, $fill, $border, $fmt, $h = '', $wrap = false) {
        $al = ($h !== '' || $wrap) ? '<alignment' . ($h !== '' ? ' horizontal="' . $h . '"' : '') . ' vertical="center"' . ($wrap ? ' wrapText="1"' : '') . '/>' : '';
        return '<xf numFmtId="' . $fmt . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1"' . ($al ? ' applyAlignment="1">' . $al . '</xf>' : '/>');
    };
    $xfs = $xf(0, 0, 0, 0)                       /* 0  default */
         . $xf(2, 0, 0, 0)                       /* 1  title */
         . $xf(1, 2, 1, 0, 'center')             /* 2  group header (yellow) */
         . $xf(1, 3, 1, 0, 'center', true)       /* 3  column header (wraps) */
         . $xf(0, 0, 1, 0)                       /* 4  text */
         . $xf(0, 0, 1, 164, 'left')             /* 5  date */
         . $xf(0, 0, 1, 3)                       /* 6  integer */
         . $xf(0, 0, 1, 4)                       /* 7  money */
         . $xf(0, 0, 1, 165)                     /* 8  percent */
         . $xf(1, 4, 1, 0)                       /* 9  total text */
         . $xf(1, 4, 1, 3)                       /* 10 total int */
         . $xf(1, 4, 1, 4)                       /* 11 total money */
         . $xf(1, 4, 1, 165)                     /* 12 total percent */
         . $xf(1, 5, 1, 0)                       /* 13 average text */
         . $xf(1, 5, 1, 166)                     /* 14 average count (1 decimal) */
         . $xf(1, 5, 1, 4)                       /* 15 average money */
         . $xf(1, 5, 1, 165)                     /* 16 average percent */
         . $xf(3, 0, 0, 0);                      /* 17 note */
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
         . '<numFmts count="3">' . $numFmts . '</numFmts>'
         . '<fonts count="4">' . $fonts . '</fonts><fills count="6">' . $fills . '</fills><borders count="2">' . $borders . '</borders>'
         . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
         . '<cellXfs count="18">' . $xfs . '</cellXfs>'
         . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
}

/* one row of a worksheet: $row[col] = ['t' => 's'|'n', 'v' => value, 's' => style, 'f' => formula?]  (cols 1-based)
   $ht > 0 gives the row a fixed height (used for the wrapped header row) */
function ccf_xl_row($rn, $row, $ht = 0) {
    ksort($row);
    $x = '<row r="' . $rn . '"' . ($ht > 0 ? ' ht="' . $ht . '" customHeight="1"' : '') . '>';
    foreach ($row as $cn => $c) {
        $ref = ccf_xl_col($cn) . $rn; $s = $c['s'] ?? 0;
        if (isset($c['f'])) {
            $x .= '<c r="' . $ref . '" s="' . $s . '"><f>' . ccf_xl_esc($c['f']) . '</f><v>' . $c['v'] . '</v></c>';
        } elseif (($c['t'] ?? 's') === 'n') {
            $x .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $c['v'] . '</v></c>';
        } elseif ($c['v'] === '' || $c['v'] === null) {
            $x .= '<c r="' . $ref . '" s="' . $s . '"/>';
        } else {
            $x .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . ccf_xl_esc($c['v']) . '</t></is></c>';
        }
    }
    return $x . '</row>';
}

/* one worksheet. $rows[rowNumber] is either an array of cells or, for big tables, that row already turned into XML text
   (building a PHP array for every cell of a year of data would run out of memory). $heights[rowNumber] = row height. */
function ccf_xl_sheet($rows, $widths, $merges, $freezeRow, $filterRef, $maxCol, $heights = []) {
    $maxRow = max(array_keys($rows));
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
       . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
       . '<dimension ref="A1:' . ccf_xl_col($maxCol) . $maxRow . '"/>'
       . '<sheetViews><sheetView workbookViewId="0">' . ($freezeRow > 0 ? '<pane ySplit="' . $freezeRow . '" topLeftCell="A' . ($freezeRow + 1) . '" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft"/>' : '') . '</sheetView></sheetViews>'
       . '<sheetFormatPr defaultRowHeight="15"/><cols>';
    foreach ($widths as $i => $w) $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    $x .= '</cols><sheetData>';
    ksort($rows);
    foreach ($rows as $rn => $row) $x .= is_string($row) ? $row : ccf_xl_row($rn, $row, $heights[$rn] ?? 0);
    $x .= '</sheetData>';
    if ($filterRef !== '') $x .= '<autoFilter ref="' . $filterRef . '"/>';
    if ($merges) { $x .= '<mergeCells count="' . count($merges) . '">'; foreach ($merges as $m) $x .= '<mergeCell ref="' . $m . '"/>'; $x .= '</mergeCells>'; }
    $x .= '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
    return $x;
}

function ccf_build_xlsx($f, $d) {
    $view = $f['view'];
    $viewLabel = ['dp' => 'Delivery Person wise', 'sr' => 'SR wise', 'both' => 'Delivery Person and SR wise'][$view];
    $lead = $view === 'both' ? ['Delivery Person', 'SR'] : ($view === 'sr' ? ['SR'] : ['Delivery Person']);
    $L = count($lead);                                        /* number of leading label columns */
    $rangeLbl = ccf_range_label($f);                          /* selected dates, or the single date */
    $S = function ($v, $s = XS_TEXT) { return ['t' => 's', 'v' => $v, 's' => $s]; };
    $N = function ($v, $s) { return ['t' => 'n', 'v' => $v, 's' => $s]; };
    $F = function ($f_, $v, $s) { return ['f' => $f_, 'v' => $v, 's' => $s]; };
    $frac = function ($a, $b) { return $b > 0 ? $a / $b : 0; };
    $money = function ($c) { return $c / 100; };
    $col = function ($i) { return ccf_xl_col($i); };
    /* formula IF(den=0,0,num/den) on one row */
    $rateF = function ($numCol, $denCol, $rn) use ($col) { return 'IF(' . $col($denCol) . $rn . '=0,0,' . $col($numCol) . $rn . '/' . $col($denCol) . $rn . ')'; };
    /* simple average of a row rate over a list (as a fraction) */
    $avgR = function ($list, $num, $den) { $s = 0; foreach ($list as $r) $s += $r[$den] > 0 ? $r[$num] / $r[$den] : 0; return count($list) ? $s / count($list) : 0; };
    $title = 'CCF Report - ' . $viewLabel . ' | Delivery dates ' . ccf_date($f['from']) . ' to ' . ccf_date($f['to'])
           . ($f['route'] !== '' ? ' | Route: ' . $f['route'] : '') . ($f['sr'] !== '' ? ' | SR: ' . $f['sr'] : '') . ($f['dp'] !== '' ? ' | Delivery person: ' . $f['dp'] : '');

    $groupHeads = ['Pre Secondary', 'Canceled', 'After Secondary', 'Rate (After sec/pre sec %)', 'Rate (Canceled/pre sec %)'];
    $grpW = [10, 9, 10, 12, 12, 14, 13, 14, 12, 12];          /* widths of the 5 count + 5 value columns */

    /* ───────── sheet 1: Date wise (Book4 layout: title row 1, group headers row 2, headers row 3) ─────────
       Invoice Count  = c1 .. c1+4   (pre, canceled, after, rate after, rate canceled)
       Invoice Values = v1 .. v1+4   (pre, canceled, after, rate after, rate canceled)                      */
    $c = []; $first = 4; $n = count($d['rows']); $last = $first + $n - 1;
    $c1 = $L + 3;                                             /* first count column */
    $v1 = $c1 + 5;                                            /* first value column */
    $lastCol = $v1 + 4;
    $c[1][1] = $S($title, XS_TITLE);
    for ($i = 1; $i <= $L + 2; $i++) $c[2][$i] = $S('', XS_GROUP);
    $c[2][$c1] = $S('Invoice Count', XS_GROUP); $c[2][$v1] = $S('Invoice Values', XS_GROUP);
    for ($i = 1; $i < 5; $i++) { $c[2][$c1 + $i] = $S('', XS_GROUP); $c[2][$v1 + $i] = $S('', XS_GROUP); }
    foreach (array_merge($lead, ['Delivery Date', 'Route']) as $i => $h) $c[3][$i + 1] = $S($h, XS_HEAD);
    foreach ($groupHeads as $i => $h) { $c[3][$c1 + $i] = $S($h, XS_HEAD); $c[3][$v1 + $i] = $S($h, XS_HEAD); }

    foreach ($d['rows'] as $k => $r) {
        $rn = $first + $k; $x = 1;
        if ($view !== 'sr')  $c[$rn][$x++] = $S($r['dp']);
        if ($view !== 'dp')  $c[$rn][$x++] = $S($r['sr']);
        $c[$rn][$x++] = $N(ccf_xl_serial($r['date']), XS_DATE);
        $c[$rn][$x++] = $S($r['route_name']);
        foreach ([$r['pre_n'], $r['can_n'], $r['aft_n']] as $i => $v) $c[$rn][$c1 + $i] = $N($v, XS_INT);
        $c[$rn][$c1 + 3] = $F($rateF($c1 + 2, $c1, $rn), $frac($r['aft_n'], $r['pre_n']), XS_PCT);
        $c[$rn][$c1 + 4] = $F($rateF($c1 + 1, $c1, $rn), $frac($r['can_n'], $r['pre_n']), XS_PCT);
        foreach ([$r['pre_v'], $r['can_v'], $r['aft_v']] as $i => $v) $c[$rn][$v1 + $i] = $N($money($v), XS_MONEY);
        $c[$rn][$v1 + 3] = $F($rateF($v1 + 2, $v1, $rn), $frac($r['aft_v'], $r['pre_v']), XS_PCT);
        $c[$rn][$v1 + 4] = $F($rateF($v1 + 1, $v1, $rn), $frac($r['can_v'], $r['pre_v']), XS_PCT);
        $c[$rn] = ccf_xl_row($rn, $c[$rn]);                    /* keep memory small on big ranges */
    }
    $tr = $last + 1; $ar = $last + 2; $t = $d['total'];
    if ($n === 0) { $tr = $first; $ar = $first + 1; $last = $first - 1; }
    for ($i = 1; $i <= $L + 2; $i++) { $c[$tr][$i] = $S($i === 1 ? 'TOTAL' : '', XS_TTEXT); $c[$ar][$i] = $S($i === 1 ? 'AVERAGE (of the rows above)' : '', XS_ATEXT); }
    $rng = function ($i) use ($col, $first, $last) { return $col($i) . $first . ':' . $col($i) . $last; };
    $vals = [$c1 => [$t['pre_n'], XS_TINT, XS_AINT], $c1 + 1 => [$t['can_n'], XS_TINT, XS_AINT], $c1 + 2 => [$t['aft_n'], XS_TINT, XS_AINT],
             $v1 => [$t['pre_v'] / 100, XS_TMONEY, XS_AMONEY], $v1 + 1 => [$t['can_v'] / 100, XS_TMONEY, XS_AMONEY], $v1 + 2 => [$t['aft_v'] / 100, XS_TMONEY, XS_AMONEY]];
    foreach ($vals as $i => $spec) {
        $c[$tr][$i] = $n ? $F('SUM(' . $rng($i) . ')', $spec[0], $spec[1]) : $N(0, $spec[1]);
        $c[$ar][$i] = $n ? $F('AVERAGE(' . $rng($i) . ')', $spec[0] / max(1, $n), $spec[2]) : $N(0, $spec[2]);
    }
    /* rate column => [numerator col, denominator col, numerator key, denominator key] */
    $rateCols = [$c1 + 3 => [$c1 + 2, $c1, 'aft_n', 'pre_n'], $c1 + 4 => [$c1 + 1, $c1, 'can_n', 'pre_n'],
                 $v1 + 3 => [$v1 + 2, $v1, 'aft_v', 'pre_v'], $v1 + 4 => [$v1 + 1, $v1, 'can_v', 'pre_v']];
    foreach ($rateCols as $i => $rc) {
        $c[$tr][$i] = $F($rateF($rc[0], $rc[1], $tr), $frac($t[$rc[2]], $t[$rc[3]]), XS_TPCT);
        $c[$ar][$i] = $n ? $F('AVERAGE(' . $rng($i) . ')', $avgR($d['rows'], $rc[2], $rc[3]), XS_APCT) : $N(0, XS_APCT);
    }
    $nr = $ar + 2;
    $c[$nr][1]     = $S('Pre Secondary = bills loaded for delivery. After Secondary = those bills IKEA also has (value above 0). Canceled = no IKEA bill, or IKEA value 0. Rate (Canceled/pre sec %) = Canceled ÷ Pre Secondary (by count, and by amount).', XS_NOTE);
    $c[$nr + 1][1] = $S('TOTAL rate = total After / total Pre (or total Canceled / total Pre). AVERAGE row = simple average of each column over the rows above (rate cells average the row rates).', XS_NOTE);
    if ($d['unchecked']) $c[$nr + 2][1] = $S('Left out (no IKEA bills imported for these dates): ' . implode(', ', array_map(function ($dt, $cnt) { return ccf_date($dt) . ' (' . $cnt . ')'; }, array_keys($d['unchecked']), $d['unchecked'])), XS_NOTE);
    $w = array_merge(array_fill(0, $L, 20), [11, 20], $grpW);
    $sheet1 = ccf_xl_sheet($c, $w, [$col($c1) . '2:' . $col($c1 + 4) . '2', $col($v1) . '2:' . $col($v1 + 4) . '2'], 3,
                           $n ? 'A3:' . $col($lastCol) . $last : '', $lastCol, [3 => 40]);

    /* ───────── sheet 2: Summary per person / SR (same 5 + 5 column groups as sheet 1) ─────────
       label columns: lead (1 or 2) | Date Range | Delivery Days                                   */
    $c = []; $sn = count($d['summary']); $sf = 4; $sl = $sf + $sn - 1;
    $rc_ = $L + 1;                                            /* Date Range column */
    $dc  = $L + 2;                                            /* Delivery Days column */
    $sc  = $L + 3;                                            /* first count column */
    $sv  = $sc + 5;                                           /* first value column */
    $c[1][1] = $S('CCF Summary - ' . $viewLabel . ' | Delivery dates ' . ccf_date($f['from']) . ' to ' . ccf_date($f['to']), XS_TITLE);
    for ($i = 1; $i <= $dc; $i++) $c[2][$i] = $S('', XS_GROUP);
    $c[2][$sc] = $S('Invoice Count', XS_GROUP); $c[2][$sv] = $S('Invoice Values', XS_GROUP);
    for ($i = 1; $i < 5; $i++) { $c[2][$sc + $i] = $S('', XS_GROUP); $c[2][$sv + $i] = $S('', XS_GROUP); }
    foreach (array_merge($lead, [$f['from'] === $f['to'] ? 'Delivery Date' : 'Date Range', 'Delivery Days']) as $i => $h) $c[3][$i + 1] = $S($h, XS_HEAD);
    foreach ($groupHeads as $i => $h) { $c[3][$sc + $i] = $S($h, XS_HEAD); $c[3][$sv + $i] = $S($h, XS_HEAD); }
    foreach ($d['summary'] as $k => $s) {
        $rn = $sf + $k; $x = 1;
        if ($view !== 'sr') $c[$rn][$x++] = $S($s['dp']);
        if ($view !== 'dp') $c[$rn][$x++] = $S($s['sr']);
        $c[$rn][$rc_] = $S($rangeLbl);
        $c[$rn][$dc]  = $N($s['days'], XS_INT);
        foreach ([$s['pre_n'], $s['can_n'], $s['aft_n']] as $i => $v) $c[$rn][$sc + $i] = $N($v, XS_INT);
        $c[$rn][$sc + 3] = $F($rateF($sc + 2, $sc, $rn), $frac($s['aft_n'], $s['pre_n']), XS_PCT);
        $c[$rn][$sc + 4] = $F($rateF($sc + 1, $sc, $rn), $frac($s['can_n'], $s['pre_n']), XS_PCT);
        foreach ([$s['pre_v'], $s['can_v'], $s['aft_v']] as $i => $v) $c[$rn][$sv + $i] = $N($money($v), XS_MONEY);
        $c[$rn][$sv + 3] = $F($rateF($sv + 2, $sv, $rn), $frac($s['aft_v'], $s['pre_v']), XS_PCT);
        $c[$rn][$sv + 4] = $F($rateF($sv + 1, $sv, $rn), $frac($s['can_v'], $s['pre_v']), XS_PCT);
        $c[$rn] = ccf_xl_row($rn, $c[$rn]);
    }
    $ttr = $sl + 1; $aar = $sl + 2;
    if ($sn === 0) { $ttr = $sf; $aar = $sf + 1; $sl = $sf - 1; }
    for ($i = 1; $i <= $dc; $i++) { $c[$ttr][$i] = $S($i === 1 ? 'TOTAL' : '', XS_TTEXT); $c[$aar][$i] = $S($i === 1 ? 'AVERAGE (of the rows above)' : '', XS_ATEXT); }
    $c[$ttr][$rc_] = $S($rangeLbl, XS_TTEXT);
    $c[$ttr][$dc]  = $N($d['days'], XS_TINT);                  /* distinct delivery dates overall (not a sum of the rows) */
    $srg = function ($i) use ($col, $sf, $sl) { return $col($i) . $sf . ':' . $col($i) . $sl; };
    $tt = $d['total'];
    $svals = [$sc => [$tt['pre_n'], XS_TINT, XS_AINT], $sc + 1 => [$tt['can_n'], XS_TINT, XS_AINT], $sc + 2 => [$tt['aft_n'], XS_TINT, XS_AINT],
              $sv => [$tt['pre_v'] / 100, XS_TMONEY, XS_AMONEY], $sv + 1 => [$tt['can_v'] / 100, XS_TMONEY, XS_AMONEY], $sv + 2 => [$tt['aft_v'] / 100, XS_TMONEY, XS_AMONEY]];
    foreach ($svals as $i => $spec) {
        $c[$ttr][$i] = $sn ? $F('SUM(' . $srg($i) . ')', $spec[0], $spec[1]) : $N(0, $spec[1]);
        $c[$aar][$i] = $sn ? $F('AVERAGE(' . $srg($i) . ')', $spec[0] / max(1, $sn), $spec[2]) : $N(0, $spec[2]);
    }
    $c[$aar][$dc] = $sn ? $F('AVERAGE(' . $srg($dc) . ')', array_sum(array_column($d['summary'], 'days')) / max(1, $sn), XS_AINT) : $N(0, XS_AINT);
    $srates = [$sc + 3 => [$sc + 2, $sc, 'aft_n', 'pre_n'], $sc + 4 => [$sc + 1, $sc, 'can_n', 'pre_n'],
               $sv + 3 => [$sv + 2, $sv, 'aft_v', 'pre_v'], $sv + 4 => [$sv + 1, $sv, 'can_v', 'pre_v']];
    foreach ($srates as $i => $rc) {
        $c[$ttr][$i] = $F($rateF($rc[0], $rc[1], $ttr), $frac($tt[$rc[2]], $tt[$rc[3]]), XS_TPCT);
        $c[$aar][$i] = $sn ? $F('AVERAGE(' . $srg($i) . ')', $avgR($d['summary'], $rc[2], $rc[3]), XS_APCT) : $N(0, XS_APCT);
    }
    $c[$aar + 2][1] = $S('Date Range = the delivery dates selected for this report (one date when only one day is selected). Delivery Days = the number of delivery dates the person or SR had bills. TOTAL row: all delivery dates in the range. AVERAGE row = simple average of each column over the rows above.', XS_NOTE);
    $sheet2 = ccf_xl_sheet($c, array_merge(array_fill(0, $L, 20), [26, 9], $grpW),
                           [$col($sc) . '2:' . $col($sc + 4) . '2', $col($sv) . '2:' . $col($sv + 4) . '2'], 3, '', $sv + 4, [3 => 40]);

    /* ───────── sheet 3: the canceled bills ───────── */
    $c = []; $c[1][1] = $S('Canceled bills | Delivery dates ' . ccf_date($f['from']) . ' to ' . ccf_date($f['to']) . ' (' . count($d['canceled']) . ' bills' . ($d['canceled_truncated'] ? ', first ' . CCF_MAX_XLS_ROWS : '') . ')', XS_TITLE);
    foreach (['Delivery Date', 'Bill No', 'Customer', 'T-Code', 'Route', 'SR', 'Delivery Person', 'Pre Secondary Value', 'Why canceled'] as $i => $h) $c[3][$i + 1] = $S($h, XS_HEAD);
    foreach ($d['canceled'] as $k => $b) {
        $rn = 4 + $k;
        $c[$rn][1] = $N(ccf_xl_serial($b['date']), XS_DATE); $c[$rn][2] = $S($b['bill']); $c[$rn][3] = $S($b['cust']); $c[$rn][4] = $S($b['t_code']);
        $c[$rn][5] = $S($b['route']); $c[$rn][6] = $S($b['sr']); $c[$rn][7] = $S($b['dp']); $c[$rn][8] = $N($b['cents'] / 100, XS_MONEY); $c[$rn][9] = $S($b['why']);
        $c[$rn] = ccf_xl_row($rn, $c[$rn]);
    }
    $cn = count($d['canceled']); $cl = 3 + $cn;
    if ($cn) { $c[$cl + 1][1] = $S('TOTAL', XS_TTEXT); for ($i = 2; $i <= 7; $i++) $c[$cl + 1][$i] = $S('', XS_TTEXT);
               $c[$cl + 1][8] = $F('SUM(H4:H' . $cl . ')', array_sum(array_column($d['canceled'], 'cents')) / 100, XS_TMONEY); $c[$cl + 1][9] = $S('', XS_TTEXT); }
    $sheet3 = ccf_xl_sheet($c, [12, 14, 28, 9, 20, 9, 20, 14, 14], [], 3, $cn ? 'A3:I' . $cl : '', 9, [3 => 28]);

    /* ───────── package ───────── */
    $tmp = tempnam(sys_get_temp_dir(), 'ccf');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) return '';
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Date wise" sheetId="1" r:id="rId1"/><sheet name="Summary" sheetId="2" r:id="rId2"/><sheet name="Canceled Bills" sheetId="3" r:id="rId3"/></sheets><calcPr fullCalcOnLoad="1"/></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
        . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', ccf_xl_styles());
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1);
    $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2);
    $zip->addFromString('xl/worksheets/sheet3.xml', $sheet3);
    $zip->close();
    return $tmp;
}

/* ═══════════════════════════ NORMAL PAGE ═══════════════════════════ */

include 'header.php';            /* requires login */

ccf_ensure_schema($conn);
$f    = ccf_filters();
$d    = ccf_compute($conn, $f, false);
$view = $f['view'];
$t    = $d['total'];
$L    = $view === 'both' ? 2 : 1;                     /* leading label columns */

/* selected delivery dates, shown in the band at the top of the summary table */
$range_one  = $f['from'] === $f['to'];
$range_lbl  = ccf_range_label($f);
$range_days = (new DateTime($f['from']))->diff(new DateTime($f['to']))->days + 1;

$route_opts = [];
$rr = ccf_q($conn, "SELECT route_code, route_name FROM routes ORDER BY route_name");
if ($rr) while ($x = mysqli_fetch_row($rr)) $route_opts[$x[0]] = $x[1];

/* keep a chosen value in its drop-down even when the range has no bills for it */
$dp_opts = array_keys($d['dp_options']); if ($f['dp'] !== '' && !in_array($f['dp'], $dp_opts, true)) $dp_opts[] = $f['dp'];
$sr_opts = array_keys($d['sr_options']); if ($f['sr'] !== '' && !in_array($f['sr'], $sr_opts, true)) $sr_opts[] = $f['sr'];

$view_label = ['dp' => 'delivery person', 'sr' => 'SR', 'both' => 'delivery person and SR'][$view];
$head_lead  = $view === 'both' ? ['Delivery Person', 'SR'] : ($view === 'sr' ? ['SR'] : ['Delivery Person']);

/* button that opens the canceled bills behind a count */
function ccf_cancel_btn($f, $n, $label, $over) {
    if ((int)$n === 0) return '0';
    $q = http_build_query(array_merge(['date_from' => $f['from'], 'date_to' => $f['to'], 'route' => $f['route'], 'sr' => $f['sr'], 'dp' => $f['dp']], $over));
    return '<button type="button" class="ccf-link" data-cancel data-q="' . ccf_h($q) . '" data-label="' . ccf_h($label) . '" title="Show the canceled bills">' . ccf_int($n) . '</button>';
}
/* $invert = true for rates where a HIGH number is bad (Canceled/pre sec %) — colors run the other way round */
function ccf_rate_cls($p, $invert = false) {
    if ($invert) return $p <= 10 ? 'good' : ($p <= 30 ? 'mid' : 'low');
    return $p >= 90 ? 'good' : ($p >= 70 ? 'mid' : 'low');
}
function ccf_rate_cell($p, $cls = '', $invert = false) { return '<td class="num ' . $cls . '"><span class="rt ' . ccf_rate_cls($p, $invert) . '">' . ccf_pct($p) . '</span></td>'; }

/* the two rate cells of the count group and of the value group (after ÷ pre, canceled ÷ pre) */
function ccf_rates_count($r) { return ccf_rate_cell(ccf_rate($r['aft_n'], $r['pre_n'])) . ccf_rate_cell(ccf_rate($r['can_n'], $r['pre_n']), '', true); }
function ccf_rates_value($r) { return ccf_rate_cell(ccf_rate($r['aft_v'], $r['pre_v'])) . ccf_rate_cell(ccf_rate($r['can_v'], $r['pre_v']), '', true); }

/* average over the rows shown */
function ccf_avg($rows, $key) { return count($rows) ? array_sum(array_column($rows, $key)) / count($rows) : 0; }
function ccf_avg_rate($rows, $num, $den) { $s = 0; foreach ($rows as $r) $s += ccf_rate($r[$num], $r[$den]); return count($rows) ? $s / count($rows) : 0; }

/* column widths for a report table, as % of the table width, weighted by what each column holds.
   $leadCols = the label columns before the number groups: 'name' (delivery person), 'code' (SR),
   'date', 'range' (selected date range), 'route', 'days'. The table also gets a min-width so on small
   screens it scrolls instead of squashing. */
function ccf_colgroup($leadCols) {
    $W = ['name' => 2.0, 'code' => 1.1, 'date' => 1.05, 'range' => 1.5, 'route' => 1.6, 'days' => 0.9,
          'n' => 1.0, 'm' => 1.25, 'r' => 1.0];                            /* n = count, m = money, r = rate */
    $cols = array_merge($leadCols, ['n', 'n', 'n', 'r', 'r', 'm', 'm', 'm', 'r', 'r']);
    $sum = 0; foreach ($cols as $c) $sum += $W[$c];
    $x = '<colgroup>';
    foreach ($cols as $c) $x .= '<col style="width:' . round($W[$c] / $sum * 100, 3) . '%">';
    return ['html' => $x . '</colgroup>', 'min' => (int)round($sum * 72)];  /* ~72px per weight unit */
}
function ccf_lead_cols($view, $extra) {
    $l = $view === 'both' ? ['name', 'code'] : ($view === 'sr' ? ['code'] : ['name']);
    return array_merge($l, $extra);
}
/* rate headers: a short 2-line label ("After % / after ÷ pre") instead of the long name, which wrapped
   onto 4 lines and spilled out of the cell. The full name stays in the tooltip and the Excel export. */
function ccf_rate_head($kind) {
    return $kind === 'after'
        ? '<th class="rh" title="Rate (After sec/pre sec %)"><span class="hm">After %</span><span class="hs">after ÷ pre</span></th>'
        : '<th class="rh" title="Rate (Canceled/pre sec %)"><span class="hm">Canceled %</span><span class="hs">canceled ÷ pre</span></th>';
}
/* second header row: 5 count columns + 5 value columns */
function ccf_group_heads() {
    return '<th class="nh">Pre Secondary</th><th class="nh">Canceled</th><th class="nh">After Secondary</th>'
         . ccf_rate_head('after') . ccf_rate_head('canceled')
         . '<th class="mh vs">Pre Secondary</th><th class="mh">Canceled</th><th class="mh">After Secondary</th>'
         . ccf_rate_head('after') . ccf_rate_head('canceled');
}
?>
<style>
.ccf, .ccf *, .ccf *::before, .ccf *::after { box-sizing: border-box; }
.ccf { --ink: #111827; --muted: #6b7280; --line: #e5e7eb; --red: #c81e1e; --yellow: #fde047;
       font-family: 'Inter', system-ui, sans-serif; font-size: 13px; line-height: 1.45; color: var(--ink); background: #f4f5f8; padding: 22px 26px 48px; min-height: 100%; }
.ccf h1 { margin: 0; font-size: 21px; font-weight: 700; }
.ccf h2 { margin: 0 0 10px; font-size: 15px; font-weight: 700; }
.ccf .sub { margin: 4px 0 16px; color: var(--muted); }
.ccf .muted { color: var(--muted); font-size: 12px; }
.ccf .card { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 14px 16px; margin-bottom: 14px; }
.ccf form.flt { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; }
.ccf .fld { display: flex; flex-direction: column; gap: 4px; }
.ccf .fld > label, .ccf .fld > span.l { font-size: 12px; font-weight: 600; color: var(--muted); }
.ccf input[type="date"], .ccf input[type="month"], .ccf select { height: 36px; padding: 0 10px; border: 1px solid #d1d5db; border-radius: 7px; font: inherit; color: var(--ink); background: #fff; }
.ccf select { min-width: 170px; max-width: 240px; }
.ccf .seg { display: inline-flex; border: 1px solid #d1d5db; border-radius: 7px; overflow: hidden; height: 36px; }
.ccf .seg label { display: flex; align-items: center; padding: 0 12px; cursor: pointer; font-weight: 600; color: var(--muted); background: #fff; border-right: 1px solid #d1d5db; }
.ccf .seg label:last-child { border-right: 0; }
.ccf .seg input { position: absolute; opacity: 0; pointer-events: none; }
.ccf .seg input:checked + span { color: #fff; }
.ccf .seg label:has(input:checked) { background: #111827; color: #fff; }
.ccf .seg label:has(input:focus-visible) { outline: 2px solid var(--red); outline-offset: -2px; }
.ccf .btn { display: inline-flex; align-items: center; height: 36px; padding: 0 16px; border: 1px solid transparent; border-radius: 7px; background: #111827; color: #fff; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
.ccf .btn.light { background: #fff; color: var(--ink); border-color: #d1d5db; }
.ccf .btn.green { background: #157f3d; }
.ccf .btn:focus-visible, .ccf select:focus-visible, .ccf input:focus-visible, .ccf a:focus-visible, .ccf .ccf-link:focus-visible { outline: 2px solid var(--red); outline-offset: 2px; }
.ccf .note { margin: 0 0 12px; padding: 9px 12px; border-radius: 8px; background: #fef3c7; border: 1px solid #f5d68a; color: #92400e; }
.ccf .info { margin: 12px 0 0; padding: 8px 12px; border-radius: 8px; background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; font-size: 12.5px; }
.ccf .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 14px; }
.ccf .kpi { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 12px 16px; }
.ccf .kpi .l { font-size: 12px; font-weight: 600; color: var(--muted); }
.ccf .kpi .v { font-size: 26px; font-weight: 700; line-height: 1.2; margin-top: 2px; font-variant-numeric: tabular-nums; }
.ccf .kpi.can { border-color: #f3c1c1; background: #fdecec; } .ccf .kpi.can .l { color: #a51717; } .ccf .kpi.can .v { color: var(--red); }
.ccf .wrap { overflow-x: auto; position: relative; }

/* ── tables ── */
.ccf table { width: 100%; border-collapse: collapse; }
.ccf th, .ccf td { padding: 5px 7px; border: 1px solid #edeef1; white-space: nowrap; }
.ccf thead th { background: #f3f4f6; font-weight: 700; text-align: center; color: #374151; }
/* report tables: both fill the page width. Every column gets a share of the width that matches what it
   holds (see ccf_colgroup), so names, codes, counts, money and rates are each sized to their content and the
   whole table grows / shrinks with the screen. Below the table's min-width it scrolls sideways inside .wrap. */
.ccf table.rep { width: 100%; table-layout: fixed; font-size: 12.5px; }
.ccf table.rep th, .ccf table.rep td { padding: 5px 6px; overflow: hidden; text-overflow: ellipsis; }
.ccf table.rep thead th { white-space: normal; line-height: 1.2; font-size: 11.5px; vertical-align: middle; }
.ccf table.rep thead th.lh { text-align: left; }
.ccf table.rep td.lc { white-space: normal; overflow-wrap: anywhere; line-height: 1.3; }   /* long names / codes wrap, never widen the column */
.ccf table.rep thead th { text-overflow: clip; overflow-wrap: break-word; hyphens: auto; }  /* headers wrap instead of showing "…" */
/* date range band: first row inside the summary table */
.ccf table.rep thead th.rng { text-align: left; padding: 8px 10px; background: #111827; color: #fff; font-size: 12.5px; font-weight: 500; }
.ccf table.rep thead th.rng strong { font-weight: 700; }
.ccf table.rep thead th.rng .rd { margin-left: 8px; color: #d1d5db; font-size: 11.5px; }
.ccf table.rep thead th.rh { white-space: nowrap; background: #eef2f7; }  /* rates */
.ccf table.rep thead th.rh .hm { display: block; font-size: 12px; font-weight: 700; color: #111827; }
.ccf table.rep thead th.rh .hs { display: block; margin-top: 1px; font-size: 10.5px; font-weight: 500; color: var(--muted); }
.ccf table.rep td.num:has(.rt) { text-align: center; }                    /* rate pills sit centred under their header */
.ccf table.rep tfoot td:first-child { white-space: normal; }
.ccf table.rep tbody tr:nth-child(even) td { background: #fbfbfc; }
.ccf table.rep tbody tr:hover td { background: #f3f4f6; }
@media (min-width: 1400px) {                                              /* wide screens: a little more breathing room */
    .ccf table.rep th, .ccf table.rep td { padding: 6px 8px; }
    .ccf table.rep { font-size: 13px; }
}
.ccf thead th.gc, .ccf thead th.gv { background: var(--yellow); color: #111827; }
.ccf table.rep .vs, .ccf table.rep thead th.gv { border-left: 2px solid #d4b106; }   /* divider between count and value groups */
.ccf td.num { text-align: right; font-variant-numeric: tabular-nums; }
.ccf tbody tr:hover td { background: #fafafb; }
.ccf tfoot td { font-weight: 700; background: #f3f4f6; }
.ccf tfoot tr.avg td { background: #e8f0fb; }
.ccf .rt { display: inline-block; padding: 0 6px; border-radius: 999px; font-weight: 700; font-size: 11.5px; }
.ccf .rt.good { background: #dcfce7; color: #166534; } .ccf .rt.mid { background: #fef9c3; color: #92400e; } .ccf .rt.low { background: #fee2e2; color: #991b1b; }
.ccf .ccf-link { padding: 0 2px; border: 0; background: none; font: inherit; font-weight: 700; color: var(--red); text-decoration: underline; cursor: pointer; }
.ccf .empty { padding: 34px 16px; text-align: center; color: var(--muted); }
.ccf .alert { padding: 14px 16px; border-radius: 10px; background: #fdecec; border: 1px solid #f3c1c1; color: #7f1d1d; word-break: break-word; }
.ccf .perf { margin: 10px 0 0; font-size: 12px; color: var(--muted); }
.ccf .modal { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(17,24,39,.55); }
.ccf .modal[hidden] { display: none; }
.ccf .dlg { width: 100%; max-width: 980px; max-height: calc(100vh - 32px); display: flex; flex-direction: column; background: #fff; border-radius: 12px; box-shadow: 0 20px 50px rgba(0,0,0,.28); overflow: hidden; }
.ccf .dlg-h { display: flex; justify-content: space-between; gap: 12px; padding: 14px 18px; border-bottom: 1px solid var(--line); }
.ccf .dlg-h h3 { margin: 0; font-size: 16px; } .ccf .dlg-h p { margin: 3px 0 0; color: var(--muted); }
.ccf .x { flex: none; width: 30px; height: 30px; border: 0; background: none; border-radius: 6px; font-size: 20px; cursor: pointer; color: var(--muted); }
.ccf .x:hover { background: #f3f4f6; }
.ccf .dlg-b { overflow: auto; }
.ccf .dlg-b table th { position: sticky; top: 0; }
.ccf .dlg-f { display: flex; justify-content: space-between; align-items: center; padding: 10px 18px; border-top: 1px solid var(--line); background: #fafafb; }
@media (max-width: 900px) { .ccf .kpis { grid-template-columns: 1fr 1fr; } }
@media (max-width: 560px) { .ccf { padding: 16px 12px 40px; } .ccf .fld { flex: 1 1 140px; } .ccf select { min-width: 0; width: 100%; } }
</style>

<div class="ccf" id="ccfApp" data-endpoint="<?php echo ccf_h(basename(__FILE__)); ?>">
    <h1>CCF report</h1>
    <p class="sub">Pre secondary bills against the bills IKEA has (after secondary), by delivery date, for each <?php echo ccf_h($view_label); ?>.</p>

    <section class="card" aria-label="Filters">
        <form class="flt" method="get" action="<?php echo ccf_h(basename(__FILE__)); ?>">
            <div class="fld"><label for="ccfMonth">Month</label><input type="month" id="ccfMonth" name="month" value="<?php echo ccf_h($f['month']); ?>"></div>
            <div class="fld"><label for="ccfFrom">From date</label><input type="date" id="ccfFrom" name="date_from" value="<?php echo ccf_h($f['date_from']); ?>"></div>
            <div class="fld"><label for="ccfTo">To date</label><input type="date" id="ccfTo" name="date_to" value="<?php echo ccf_h($f['date_to']); ?>"></div>
            <div class="fld" role="radiogroup" aria-label="View by">
                <span class="l">View by</span>
                <span class="seg">
                    <?php foreach (['dp' => 'Delivery person', 'sr' => 'SR', 'both' => 'Both'] as $k => $lbl): ?>
                        <label><input type="radio" name="view" value="<?php echo $k; ?>"<?php echo $view === $k ? ' checked' : ''; ?>><span><?php echo $lbl; ?></span></label>
                    <?php endforeach; ?>
                </span>
            </div>
            <div class="fld"><label for="ccfDp">Delivery person</label>
                <select id="ccfDp" name="dp"><option value="">All delivery persons</option>
                    <?php foreach ($dp_opts as $o): ?><option value="<?php echo ccf_h($o); ?>"<?php echo $f['dp'] === $o ? ' selected' : ''; ?>><?php echo ccf_h($o); ?></option><?php endforeach; ?>
                </select></div>
            <div class="fld"><label for="ccfSr">SR</label>
                <select id="ccfSr" name="sr"><option value="">All SRs</option>
                    <?php foreach ($sr_opts as $o): ?><option value="<?php echo ccf_h($o); ?>"<?php echo $f['sr'] === $o ? ' selected' : ''; ?>><?php echo ccf_h($o); ?></option><?php endforeach; ?>
                </select></div>
            <div class="fld"><label for="ccfRoute">Route</label>
                <select id="ccfRoute" name="route"><option value="">All routes</option>
                    <?php foreach ($route_opts as $code => $name): ?><option value="<?php echo ccf_h($code); ?>"<?php echo $f['route'] === (string)$code ? ' selected' : ''; ?>><?php echo ccf_h($name); ?></option><?php endforeach; ?>
                </select></div>
            <button type="submit" class="btn">Apply filters</button>
            <a class="btn light" href="<?php echo ccf_h(basename(__FILE__)); ?>">Reset</a>
            <a class="btn green" href="<?php echo ccf_h(ccf_url($f, ['export' => 'xlsx'])); ?>">Export Excel</a>
        </form>
        <p class="info">
            <?php if ($f['using_month']): ?>Month <strong><?php echo ccf_h(date('F Y', strtotime($f['month'] . '-01'))); ?></strong> covers <strong><?php echo ccf_h(ccf_date($f['from'])); ?> to <?php echo ccf_h(ccf_date($f['to'])); ?></strong> (26th of the previous month to the 25th).
            <?php else: ?>Delivery dates <strong><?php echo ccf_h(ccf_date($f['from'])); ?> to <?php echo ccf_h(ccf_date($f['to'])); ?></strong>. The month box is ignored while dates are filled in.<?php endif; ?>
        </p>
    </section>

    <?php if ($f['limited']): ?><p class="note">The date range is limited to <?php echo CCF_MAX_DAYS; ?> days. Showing <?php echo ccf_h(ccf_date($f['from'])); ?> to <?php echo ccf_h(ccf_date($f['to'])); ?>.</p><?php endif; ?>

    <?php if ($d['no_data']): ?>
        <div class="card empty"><strong>There is no data to report yet.</strong><p>This report needs the loading summary and IKEA (secondary invoice) imports.</p></div>
    <?php elseif ($d['error'] !== ''): ?>
        <div class="alert" role="alert"><strong>The report could not be loaded.</strong> Database message: <?php echo ccf_h($d['error']); ?></div>
    <?php else: ?>

        <?php if ($d['unchecked']):
            $parts = []; foreach (array_slice($d['unchecked'], 0, 8, true) as $dt => $n) $parts[] = ccf_h(ccf_date($dt)) . ' (' . (int)$n . ' bill' . ((int)$n === 1 ? '' : 's') . ')';
            $more = count($d['unchecked']) - 8; ?>
            <p class="note"><strong>Not included:</strong> no IKEA bills have been imported for <?php echo implode(', ', $parts); ?><?php echo $more > 0 ? ' and ' . $more . ' more date' . ($more === 1 ? '' : 's') : ''; ?>. Those dates are left out, because every bill on them would look canceled.</p>
        <?php endif; ?>

        <section class="kpis" aria-label="Totals for the selected filters">
            <div class="kpi"><div class="l">Pre secondary</div><div class="v"><?php echo ccf_int($t['pre_n']); ?></div><div class="muted">Rs. <?php echo ccf_cents($t['pre_v']); ?></div></div>
            <div class="kpi can"><div class="l">Canceled</div><div class="v"><?php echo ccf_cancel_btn($f, $t['can_n'], 'All canceled bills for these filters', []); ?></div><div class="muted" style="color:#7f1d1d">Rs. <?php echo ccf_cents($t['can_v']); ?> · <?php echo ccf_pct(ccf_rate($t['can_v'], $t['pre_v'])); ?> of pre value</div></div>
            <div class="kpi"><div class="l">After secondary</div><div class="v"><?php echo ccf_int($t['aft_n']); ?></div><div class="muted">Rs. <?php echo ccf_cents($t['aft_v']); ?></div></div>
            <div class="kpi"><div class="l">Rate (after ÷ pre)</div><div class="v"><?php echo ccf_pct(ccf_rate($t['aft_n'], $t['pre_n'])); ?></div><div class="muted">Canceled rate <?php echo ccf_pct(ccf_rate($t['can_n'], $t['pre_n'])); ?> · Value rate <?php echo ccf_pct(ccf_rate($t['aft_v'], $t['pre_v'])); ?> · Canceled value rate <?php echo ccf_pct(ccf_rate($t['can_v'], $t['pre_v'])); ?></div></div>
        </section>

        <?php if (!$d['rows']): ?>
            <div class="card empty"><strong>No bills found.</strong><p>Try other dates, or clear the delivery person, SR or route filter.</p></div>
        <?php else: ?>


        <!-- ── summary by delivery person / SR ── -->
        <section class="card" aria-label="Summary">
            <h2>Summary by <?php echo ccf_h($view_label); ?></h2>
            <div class="wrap">
            <?php $cg = ccf_colgroup(ccf_lead_cols($view, ['days'])); ?>
            <table class="rep" style="min-width:<?php echo $cg['min']; ?>px"><?php echo $cg['html']; ?>
                <thead>
                    <tr>
                        <th colspan="<?php echo $L + 11; ?>" class="rng">
                            <?php echo $range_one ? 'Delivery date' : 'Delivery dates'; ?>: <strong><?php echo ccf_h($range_one ? $range_lbl : ccf_date($f['from']) . ' to ' . ccf_date($f['to'])); ?></strong>
                            <span class="rd">(<?php echo $range_days; ?> day<?php echo $range_days === 1 ? '' : 's'; ?> selected)</span>
                        </th>
                    </tr>
                    <tr>
                        <?php foreach ($head_lead as $hl): ?><th rowspan="2" class="lh"><?php echo ccf_h($hl); ?></th><?php endforeach; ?>
                        <th rowspan="2" class="dh">Delivery Days</th>
                        <th colspan="5" class="gc">Invoice Count</th><th colspan="5" class="gv">Invoice Values</th>
                    </tr>
                    <tr><?php echo ccf_group_heads(); ?></tr>
                </thead>
                <tbody>
                <?php foreach ($d['summary'] as $s):
                    $who  = implode(', ', array_filter([$s['dp'], $s['sr'] !== '' ? 'SR ' . $s['sr'] : '']));
                    $over = ['dp' => $view === 'sr' ? $f['dp'] : $s['dp'], 'sr' => $view === 'dp' ? $f['sr'] : ($s['sr'] === '(No SR)' ? '(No SR)' : $s['sr'])]; ?>
                    <tr>
                        <?php if ($view !== 'sr'): ?><td class="lc" title="<?php echo ccf_h($s['dp']); ?>"><?php echo ccf_h($s['dp']); ?></td><?php endif; ?>
                        <?php if ($view !== 'dp'): ?><td class="lc" title="<?php echo ccf_h($s['sr']); ?>"><?php echo ccf_h($s['sr']); ?></td><?php endif; ?>
                        <td class="num"><?php echo (int)$s['days']; ?></td>
                        <td class="num"><?php echo ccf_int($s['pre_n']); ?></td>
                        <td class="num"><?php echo ccf_cancel_btn($f, $s['can_n'], $who . ', ' . $range_lbl, $over); ?></td>
                        <td class="num"><?php echo ccf_int($s['aft_n']); ?></td>
                        <?php echo ccf_rates_count($s); ?>
                        <td class="num vs"><?php echo ccf_cents($s['pre_v']); ?></td><td class="num"><?php echo ccf_cents($s['can_v']); ?></td><td class="num"><?php echo ccf_cents($s['aft_v']); ?></td>
                        <?php echo ccf_rates_value($s); ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <?php $sn = $d['summary']; ?>
                    <tr><td colspan="<?php echo $L; ?>">TOTAL</td><td class="num"><?php echo (int)$d['days']; ?></td>
                        <td class="num"><?php echo ccf_int($t['pre_n']); ?></td><td class="num"><?php echo ccf_int($t['can_n']); ?></td><td class="num"><?php echo ccf_int($t['aft_n']); ?></td><?php echo ccf_rates_count($t); ?>
                        <td class="num vs"><?php echo ccf_cents($t['pre_v']); ?></td><td class="num"><?php echo ccf_cents($t['can_v']); ?></td><td class="num"><?php echo ccf_cents($t['aft_v']); ?></td><?php echo ccf_rates_value($t); ?></tr>
                    <tr class="avg"><td colspan="<?php echo $L; ?>">AVERAGE</td><td class="num"><?php echo number_format(ccf_avg($sn, 'days'), 1); ?></td>
                        <td class="num"><?php echo number_format(ccf_avg($sn, 'pre_n'), 1); ?></td><td class="num"><?php echo number_format(ccf_avg($sn, 'can_n'), 1); ?></td><td class="num"><?php echo number_format(ccf_avg($sn, 'aft_n'), 1); ?></td>
                        <?php echo ccf_rate_cell(ccf_avg_rate($sn, 'aft_n', 'pre_n')); ?><?php echo ccf_rate_cell(ccf_avg_rate($sn, 'can_n', 'pre_n'), '', true); ?>
                        <td class="num vs"><?php echo ccf_cents(ccf_avg($sn, 'pre_v')); ?></td><td class="num"><?php echo ccf_cents(ccf_avg($sn, 'can_v')); ?></td><td class="num"><?php echo ccf_cents(ccf_avg($sn, 'aft_v')); ?></td>
                        <?php echo ccf_rate_cell(ccf_avg_rate($sn, 'aft_v', 'pre_v')); ?><?php echo ccf_rate_cell(ccf_avg_rate($sn, 'can_v', 'pre_v'), '', true); ?>
                    </tr>
                </tfoot>
            </table>
            </div>
        </section>

        <!-- ── date wise detail (the Book4 layout) ── -->
        <section class="card" aria-label="Delivery date wise report">
            <h2>Delivery date wise <span class="muted">(<?php echo count($d['rows']); ?> row<?php echo count($d['rows']) === 1 ? '' : 's'; ?>)</span></h2>
            <div class="wrap">
            <?php $cg = ccf_colgroup(ccf_lead_cols($view, ['date', 'route'])); ?>
            <table class="rep" style="min-width:<?php echo $cg['min']; ?>px"><?php echo $cg['html']; ?>
                <thead>
                    <tr>
                        <?php foreach ($head_lead as $hl): ?><th rowspan="2" class="lh"><?php echo ccf_h($hl); ?></th><?php endforeach; ?><th rowspan="2" class="dh">Delivery Date</th><th rowspan="2" class="lh">Route</th>
                        <th colspan="5" class="gc">Invoice Count</th><th colspan="5" class="gv">Invoice Values</th>
                    </tr>
                    <tr><?php echo ccf_group_heads(); ?></tr>
                </thead>
                <tbody>
                <?php foreach ($d['rows'] as $r):
                    $who  = implode(', ', array_filter([$r['dp'], $r['sr'] !== '' ? 'SR ' . $r['sr'] : '']));
                    $over = ['date_from' => $r['date'], 'date_to' => $r['date'], 'route' => $r['route'] !== '' ? $r['route'] : '(No route)',
                             'dp' => $view === 'sr' ? $f['dp'] : $r['dp'], 'sr' => $view === 'dp' ? $f['sr'] : $r['sr']]; ?>
                    <tr>
                        <?php if ($view !== 'sr'): ?><td class="lc" title="<?php echo ccf_h($r['dp']); ?>"><?php echo ccf_h($r['dp']); ?></td><?php endif; ?>
                        <?php if ($view !== 'dp'): ?><td class="lc" title="<?php echo ccf_h($r['sr']); ?>"><?php echo ccf_h($r['sr']); ?></td><?php endif; ?>
                        <td><?php echo ccf_h(ccf_date($r['date'])); ?></td><td class="lc" title="<?php echo ccf_h($r['route_name']); ?>"><?php echo ccf_h($r['route_name']); ?></td>
                        <td class="num"><?php echo ccf_int($r['pre_n']); ?></td>
                        <td class="num"><?php echo ccf_cancel_btn($f, $r['can_n'], $who . ', ' . ccf_date($r['date']) . ', ' . $r['route_name'], $over); ?></td>
                        <td class="num"><?php echo ccf_int($r['aft_n']); ?></td>
                        <?php echo ccf_rates_count($r); ?>
                        <td class="num vs"><?php echo ccf_cents($r['pre_v']); ?></td><td class="num"><?php echo ccf_cents($r['can_v']); ?></td><td class="num"><?php echo ccf_cents($r['aft_v']); ?></td>
                        <?php echo ccf_rates_value($r); ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <?php $rows = $d['rows']; ?>
                    <tr><td colspan="<?php echo $L + 2; ?>">TOTAL</td>
                        <td class="num"><?php echo ccf_int($t['pre_n']); ?></td><td class="num"><?php echo ccf_cancel_btn($f, $t['can_n'], 'All canceled bills for these filters', []); ?></td><td class="num"><?php echo ccf_int($t['aft_n']); ?></td><?php echo ccf_rates_count($t); ?>
                        <td class="num vs"><?php echo ccf_cents($t['pre_v']); ?></td><td class="num"><?php echo ccf_cents($t['can_v']); ?></td><td class="num"><?php echo ccf_cents($t['aft_v']); ?></td><?php echo ccf_rates_value($t); ?></tr>
                    <tr class="avg"><td colspan="<?php echo $L + 2; ?>">AVERAGE (of the rows above)</td>
                        <td class="num"><?php echo number_format(ccf_avg($rows, 'pre_n'), 1); ?></td><td class="num"><?php echo number_format(ccf_avg($rows, 'can_n'), 1); ?></td><td class="num"><?php echo number_format(ccf_avg($rows, 'aft_n'), 1); ?></td>
                        <?php echo ccf_rate_cell(ccf_avg_rate($rows, 'aft_n', 'pre_n')); ?><?php echo ccf_rate_cell(ccf_avg_rate($rows, 'can_n', 'pre_n'), '', true); ?>
                        <td class="num vs"><?php echo ccf_cents(ccf_avg($rows, 'pre_v')); ?></td><td class="num"><?php echo ccf_cents(ccf_avg($rows, 'can_v')); ?></td><td class="num"><?php echo ccf_cents(ccf_avg($rows, 'aft_v')); ?></td>
                        <?php echo ccf_rate_cell(ccf_avg_rate($rows, 'aft_v', 'pre_v')); ?><?php echo ccf_rate_cell(ccf_avg_rate($rows, 'can_v', 'pre_v'), '', true); ?></tr>
                </tfoot>
            </table>
            </div>
            <p class="muted" style="margin:10px 0 0">Canceled = no IKEA bill, or IKEA value 0. After secondary = the bills IKEA also has. After % = Rate (After sec/pre sec %) = After Secondary ÷ Pre Secondary. Canceled % = Rate (Canceled/pre sec %) = Canceled ÷ Pre Secondary. Both are by count under Invoice Count and by amount under Invoice Values. A high canceled rate is bad, so it is colored the other way round from the after-secondary rate. TOTAL rate = total after (or canceled) ÷ total pre. AVERAGE = simple average of each column over the rows above (rate cells average the row rates). Click a red canceled count to see those bills.</p>
        </section>
        <?php endif; ?>

        <p class="perf">Checked <?php echo ccf_int($d['scanned']); ?> bills in <?php echo number_format($d['ms'] / 1000, 2); ?> s.</p>
    <?php endif; ?>

    <!-- canceled bills dialog -->
    <div class="modal" id="ccfModal" hidden>
        <div class="dlg" role="dialog" aria-modal="true" aria-labelledby="ccfDlgTitle">
            <div class="dlg-h"><div><h3 id="ccfDlgTitle">Canceled bills</h3><p id="ccfDlgSub"></p></div><button type="button" class="x" id="ccfClose" aria-label="Close">&times;</button></div>
            <div class="dlg-b" id="ccfDlgBody"></div>
            <div class="dlg-f"><span id="ccfDlgSum" class="muted"></span><button type="button" class="btn light" id="ccfClose2">Close</button></div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    var app = document.getElementById('ccfApp'), ENDPOINT = app.dataset.endpoint;
    var modal = document.getElementById('ccfModal'), body = document.getElementById('ccfDlgBody'), lastBtn = null;

    function el(tag, text, cls) { var e = document.createElement(tag); if (text !== undefined) e.textContent = text; if (cls) e.className = cls; return e; }
    function close() { modal.hidden = true; if (lastBtn && document.contains(lastBtn)) lastBtn.focus(); }

    app.addEventListener('click', function (e) {
        var b = e.target.closest('[data-cancel]');
        if (!b) return;
        lastBtn = b;
        document.getElementById('ccfDlgSub').textContent = b.dataset.label || '';
        document.getElementById('ccfDlgSum').textContent = '';
        body.innerHTML = ''; body.appendChild(el('p', 'Loading…', 'muted')); body.firstChild.style.padding = '16px';
        modal.hidden = false; document.getElementById('ccfClose').focus();

        fetch(ENDPOINT + '?ajax=canceled&' + b.dataset.q, { credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (res) {
                body.innerHTML = '';
                if (!res) { body.appendChild(el('p', 'The server sent an unexpected reply. Reload the page and try again.')); body.firstChild.style.padding = '16px'; return; }
                if (!res.success) { body.appendChild(el('p', res.error || 'Could not load the canceled bills.')); body.firstChild.style.padding = '16px'; return; }
                if (!res.bills.length) { body.appendChild(el('p', 'No canceled bills.')); body.firstChild.style.padding = '16px'; return; }
                var t = el('table'), h = el('thead'), hr = el('tr');
                ['Delivery date', 'Bill no', 'Customer', 'Route', 'SR', 'Delivery person', 'Pre secondary value', 'Why canceled'].forEach(function (x, i) { var th = el('th', x); if (i === 6) th.style.textAlign = 'right'; hr.appendChild(th); });
                h.appendChild(hr); t.appendChild(h);
                var tb = el('tbody');
                res.bills.forEach(function (x) {
                    var tr = el('tr');
                    [x.date, x.bill, x.customer + (x.t_code ? ' (' + x.t_code + ')' : ''), x.route, x.sr, x.dp, x.value, x.why].forEach(function (v, i) {
                        var td = el('td', v); if (i === 6) td.className = 'num'; tr.appendChild(td);
                    });
                    tb.appendChild(tr);
                });
                t.appendChild(tb); body.appendChild(t);
                document.getElementById('ccfDlgSum').textContent = res.count + ' bill' + (res.count === 1 ? '' : 's') + ', Rs. ' + res.total +
                    (res.truncated ? ' (showing the first ' + res.shown + ')' : '');
            })
            .catch(function () { body.innerHTML = ''; body.appendChild(el('p', 'The request did not reach the server. Check the connection and try again.')); body.firstChild.style.padding = '16px'; });
    });
    document.getElementById('ccfClose').addEventListener('click', close);
    document.getElementById('ccfClose2').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
})();
</script>

<?php include 'footer.php'; ?>