<?php
/**
 * unilever_receivable_lib.php — "Unilever Receivable Summary" (dashboard).
 * One line per Customer Ledger / Vendor Ledger source page, using the SAME figures those pages show.
 *   Receivable = what Unilever has to give us · Paid = what we paid out first and claim back (Drives & Loyalty, Promoters Salary, SR Incentive, Others)
 *   Received   = what Unilever has already given / settled          Balance = Receivable − Received  (Scheme line: the Scheme Ledger closing balance)
 * Filters ($F): as_at (date), from / to (date range), years[], months[]  — applied to the date of each record
 * (invoice / proposal / paid / entry date, or the month of the entry); settlements count up to the as-at date.
 */
/* the Scheme Ledger calculation lives in its own file; if that file was not uploaded the summary still works without the Scheme line */
if (is_file(__DIR__ . '/scheme_ledger_lib.php')) require_once __DIR__ . '/scheme_ledger_lib.php';
function ur_has_table($conn, $t) { $r = @mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'"); return $r && mysqli_num_rows($r) > 0; }
function ur_has_col($conn, $t, $c) { $r = @mysqli_query($conn, "SHOW COLUMNS FROM `$t` LIKE '" . mysqli_real_escape_string($conn, $c) . "'"); return $r && mysqli_num_rows($r) > 0; }
function ur_val($conn, $sql) { $r = @mysqli_query($conn, $sql); if (!$r) return 0.0; $x = mysqli_fetch_row($r); return (float)($x[0] ?? 0); }
function ur_ok($d) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d); }
/** clean filter set from a request array */
function ur_filters($src) {
    $ints = fn($v, $lo, $hi) => array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : preg_split('/[,\s]+/', (string)$v, -1, PREG_SPLIT_NO_EMPTY)), fn($n) => $n >= $lo && $n <= $hi)));
    $F = ['as_at' => ur_ok($src['as_at'] ?? '') ? $src['as_at'] : date('Y-m-d'), 'from' => ur_ok($src['from'] ?? '') ? $src['from'] : '', 'to' => ur_ok($src['to'] ?? '') ? $src['to'] : '',
          'years' => $ints($src['years'] ?? '', 2000, 2100), 'months' => $ints($src['months'] ?? '', 1, 12)];
    if ($F['from'] && $F['to'] && $F['to'] < $F['from']) [$F['from'], $F['to']] = [$F['to'], $F['from']];
    /* two modes that exclude each other: AS AT a date (everything up to it)  ·  DATE RANGE (only the entries inside it, with the opening balance before it) */
    $F['mode'] = (($src['mode'] ?? '') === 'range' && ($F['from'] || $F['to'])) ? 'range' : 'asat';
    if ($F['mode'] === 'range') { if (!$F['to']) $F['to'] = date('Y-m-d'); }
    else { $F['from'] = ''; $F['to'] = ''; }
    return $F;
}
/** SQL condition on a DATE expression */
function ur_dp($conn, $expr, $F) {
    $e = fn($v) => mysqli_real_escape_string($conn, $v);
    $c = ["($expr IS NULL OR $expr <= '{$e($F['as_at'])}')"];
    if ($F['from']) $c[] = "$expr >= '{$e($F['from'])}'";
    if ($F['to'])   $c[] = "$expr <= '{$e($F['to'])}'";
    if ($F['years'])  $c[] = "YEAR($expr) IN (" . implode(',', $F['years']) . ")";
    if ($F['months']) $c[] = "MONTH($expr) IN (" . implode(',', $F['months']) . ")";
    return implode(' AND ', $c);
}
function ur_summary($conn, $F = null) {
    $F = $F ?: ur_filters([]);
    $rows = []; $debit_notes = 0.0; $warn = [];
    if (is_file(__DIR__ . '/unilever_ledger_lib.php')) require_once __DIR__ . '/unilever_ledger_lib.php';
    $to = $F['mode'] === 'range' ? $F['to'] : $F['as_at'];
    $opt = ['period' => $F['mode'] === 'range', 'from' => $F['from'], 'to' => $to, 'years' => $F['years'], 'months' => $F['months']];
    $lp = ['date_to' => $to]; if ($F['mode'] === 'range') $lp['period'] = 1; if ($F['from']) $lp['date_from'] = $F['from']; if ($F['years']) $lp['years'] = implode(',', $F['years']); if ($F['months']) $lp['months'] = implode(',', $F['months']);
    $add = function ($S, $L, $link) use (&$rows) {
        $paid = $S['group'] === 'paid';
        $rows[] = ['source' => $S['source'], 'label' => $S['label'], 'page' => $S['page'], 'icon' => $S['icon'], 'group' => $S['group'], 'ledger' => $link,
                   'open' => round($L['open'], 2), 'receivable' => $paid ? 0.0 : round($L['debit'], 2), 'received' => round($L['credit'], 2), 'paid' => $paid ? round($L['debit'], 2) : null, 'balance' => round($L['closing'], 2)];
    };
    /* every line = the closing figures of its own LEDGER (debit / credit / closing balance), and the line opens that ledger */
    if (!function_exists('ul_compute')) $warn[] = 'unilever_ledger_lib.php is not uploaded on the server — only the Scheme line can be shown until it is.';
    $SRC = function_exists('ul_sources') ? ul_sources() : [];
    $line = function ($key) use ($conn, $SRC, $opt, $lp, $add, &$debit_notes) {
        if (!isset($SRC[$key]) || !ul_tbl($conn, $SRC[$key]['tbl'])) return;
        $L = ul_compute($conn, $key, $opt);
        if ($key === 'others' && !$L['stmt'] && !$L['open']) return;
        if ($key === 'dn') $debit_notes = $L['debit'];
        $add($SRC[$key], $L, 'unilever_ledger_report.php?' . http_build_query(['src' => $key] + $lp));
    };
    $line('prr'); $line('damaged');
    /* Scheme Discount & Free Issues = the SCHEME LEDGER */
    if (!function_exists('sl_compute')) $warn[] = 'scheme_ledger_lib.php is not uploaded on the server — the Scheme Discount & Free Issues line is left out until it is.';
    elseif (ur_has_table($conn, 'scheme_status_items')) {
        $L = sl_compute($conn, $opt);
        $add(['source' => 'Customer Certificate', 'label' => 'Scheme Discount & Free Issues', 'page' => 'scheme_ledger_report', 'icon' => 'fa-tags', 'group' => 'rec'], $L, 'scheme_ledger_report.php?' . http_build_query($lp));
    }
    $line('dn'); $line('sscl'); $line('dal'); $line('pscl'); $line('sricc'); $line('others');
    $tot = ['open' => 0.0, 'receivable' => 0.0, 'received' => 0.0, 'paid' => 0.0, 'balance' => 0.0];
    foreach ($rows as $r) foreach ($tot as $k => $_) $tot[$k] += (float)($r[$k] ?? 0);
    foreach ($tot as $k => $v) $tot[$k] = round($v, 2);
    return ['rows' => $rows, 'total' => $tot, 'debit_notes' => round($debit_notes, 2), 'as_at' => $F['as_at'], 'mode' => $F['mode'], 'from' => $F['from'], 'to' => $F['to'], 'warn' => $warn];
}
