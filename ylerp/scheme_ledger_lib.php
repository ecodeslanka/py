<?php
/**
 * scheme_ledger_lib.php — the Scheme Ledger calculation, shared by scheme_ledger_report.php and the dashboard.
 *   Debit  = Claim Amount of the Current Scheme Status upload (DAMAGE schemes left out)
 *   Credit = Claim Certificate actual amount whose description starts with the scheme no, or has it anywhere in the line
 *   Balance = running Debit − Credit
 * Options: from, to (dates), moc, q (scheme no / description), years[], months[] (of the entry date).
 */
if (!function_exists('sl_compute')) {
function sl_norm($c) { return strtoupper(preg_replace('/\.0+$/', '', trim((string)$c))); }
/** scheme no of a claim certificate description: the part before the first "-" if it is a known scheme, else any word / number in the line that equals a scheme code ($set = array_flip of codes) */
function sl_cc_scheme($desc, array $set) {
    $desc = (string)$desc;
    $first = sl_norm(explode('-', $desc, 2)[0]);
    if ($first !== '' && isset($set[$first])) return $first;
    foreach (preg_split('/[^A-Za-z0-9]+/', $desc, -1, PREG_SPLIT_NO_EMPTY) as $t) { $t = sl_norm($t); if (isset($set[$t])) return $t; }
    return null;
}
function sl_tbl($conn, $t) { $r = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'"); return $r && mysqli_num_rows($r) > 0; }
function sl_compute($conn, $o) {
    $e = fn($v) => mysqli_real_escape_string($conn, (string)$v);
    $from = $o['from'] ?? ''; $to = $o['to'] ?? ''; $moc = trim($o['moc'] ?? ''); $q = trim($o['q'] ?? '');
    $period = !empty($o['period']);   // period = only the transactions inside the range (balance = debit − credit of the range)
    $years = array_values(array_filter(array_map('intval', $o['years'] ?? []))); $months = array_values(array_filter(array_map('intval', $o['months'] ?? [])));
    $has_items = sl_tbl($conn, 'scheme_status_items'); $has_cc = sl_tbl($conn, 'claim_cert_items');
    $schemes = [];
    if ($has_items) {
        $w = ["UPPER(TRIM(activity_type)) <> 'DAMAGE'"];
        if ($moc !== '') $w[] = "moc_ref = '{$e($moc)}'";
        if ($q !== '') { $s = $e($q); $w[] = "(activity_code LIKE '%$s%' OR activity_desc LIKE '%$s%')"; }
        $r = mysqli_query($conn, "SELECT * FROM scheme_status_items WHERE " . implode(' AND ', $w));
        while ($r && ($x = mysqli_fetch_assoc($r))) { $k = sl_norm($x['activity_code']); $schemes[$k]['rows'][] = $x; if (!isset($schemes[$k]['desc'])) $schemes[$k]['desc'] = $x['activity_desc']; }
    }
    /* schemes that exist only in the HUL / TOT Scheme Master (no claim yet) are part of the Scheme Discount Daily Report, so the certificates paid against them belong to this ledger too (credit, no debit) */
    if (sl_tbl($conn, 'scheme_master_items') && !$moc && $q === '') {
        $r = mysqli_query($conn, "SELECT activity_code, activity_desc FROM scheme_master_items ORDER BY id DESC");
        while ($r && ($x = mysqli_fetch_assoc($r))) { $k = sl_norm($x['activity_code']); if ($k === '' || isset($schemes[$k])) continue; $schemes[$k] = ['rows' => [], 'desc' => $x['activity_desc'], 'master_only' => 1]; }
    }
    $entries = [];
    foreach ($schemes as $k => $sc) foreach ($sc['rows'] as $x) {
        if ($x['claim_amount'] === null || (float)$x['claim_amount'] <= 0) continue;
        $date = $x['claim_date'] ?: ($x['end_date'] ?: $x['start_date']);
        $entries[] = ['date' => $date ?: '', 'scheme' => trim($x['activity_code']), 'desc' => $sc['desc'], 'type' => 'Claim', 'ref' => $x['claim_moc_ref'] !== '' && $x['claim_moc_ref'] !== null ? 'Claim MOC ' . $x['claim_moc_ref'] : 'MOC ' . $x['moc_ref'], 'debit' => (float)$x['claim_amount'], 'credit' => 0.0, 'o' => 1];
    }
    $ccsum = []; $ccvat = []; $ccdate = [];
    if ($has_cc && $schemes) {
        $set = array_flip(array_keys($schemes));
        // scheme whose code is not on the certificate: use the scheme no written in its description, e.g. "Ponds FC Value Bill [DC] Dis (National)41425974"
        $alt = [];
        foreach ($schemes as $k => $sc) { preg_match_all('/\d{6,}/', (string)($sc['desc'] ?? ''), $mm); foreach ($mm[0] as $a) { $a = sl_norm($a); if (!isset($set[$a]) && !isset($alt[$a])) $alt[$a] = $k; } }
        $set2 = $set + array_fill_keys(array_keys($alt), 1);
        $r = mysqli_query($conn, "SELECT tax_invoice_no, invoice_date, banking_date, imported_at, claim_description, actual_amount, vat_amount
                                  FROM claim_cert_items WHERE claim_description IS NOT NULL AND claim_description <> '' AND (COALESCE(actual_amount,0) <> 0 OR COALESCE(vat_amount,0) <> 0)");
        while ($r && ($x = mysqli_fetch_assoc($r))) {
            $sn = sl_cc_scheme($x['claim_description'], $set2);
            if ($sn === null) continue;
            if (isset($alt[$sn]) && !isset($set[$sn])) $sn = $alt[$sn];
            $date = $x['invoice_date'] ?: ($x['banking_date'] ?: substr((string)$x['imported_at'], 0, 10));
            $ccsum[$sn] = ($ccsum[$sn] ?? 0) + (float)$x['actual_amount']; $ccvat[$sn] = ($ccvat[$sn] ?? 0) + (float)$x['vat_amount']; if (($ccdate[$sn] ?? '') < (string)$date) $ccdate[$sn] = (string)$date;
            if ((float)$x['actual_amount'] == 0) continue;      // only its VAT counted (above)
            $entries[] = ['date' => $date ?: '', 'scheme' => $sn, 'desc' => $schemes[$sn]['desc'] ?? '', 'type' => 'Claim certificate', 'ref' => 'Tax inv ' . $x['tax_invoice_no'] . ' · ' . $x['claim_description'], 'debit' => 0.0, 'credit' => (float)$x['actual_amount'], 'o' => 2];
        }
    }
    /* VAT of the claim certificates: when what is still unpaid (claim − certificate amount) is FULLY the same as the VAT amount of the certificates, that VAT settles it (same rule as the Scheme Discount Daily Report) */
    foreach ($schemes as $k => $sc) {
        $claim = 0.0; foreach ($sc['rows'] as $x) if ($x['claim_amount'] !== null && (float)$x['claim_amount'] > 0) $claim += (float)$x['claim_amount'];
        $diff = round($claim - ($ccsum[$k] ?? 0), 2); $vat = round($ccvat[$k] ?? 0, 2);
        if ($claim > 0 && $vat > 0 && $diff > 0 && abs($diff - $vat) <= 0.01)
            $entries[] = ['date' => $ccdate[$k] ?? '', 'scheme' => trim($sc['rows'][0]['activity_code']), 'desc' => $sc['desc'] ?? '', 'type' => 'Claim certificate VAT', 'ref' => 'VAT of the claim certificates — the same as the unpaid difference', 'debit' => 0.0, 'credit' => $vat, 'o' => 3];
    }
    usort($entries, fn($a, $b) => strcmp($a['date'], $b['date']) ?: $a['o'] <=> $b['o'] ?: strcmp($a['scheme'], $b['scheme']));
    $open = 0.0; $stmt = [];
    foreach ($entries as $en) {
        $d = $en['date'];
        if ($from && $d !== '' && $d < $from) { if (!$period) $open += $en['debit'] - $en['credit']; continue; }     // period view: nothing is carried in
        if ($to && $d !== '' && $d > $to) continue;
        if ($years  && ($d === '' || !in_array((int)substr($d, 0, 4), $years, true)))  continue;
        if ($months && ($d === '' || !in_array((int)substr($d, 5, 2), $months, true))) continue;
        $stmt[] = $en;
    }
    $bal = $open; $tdr = 0.0; $tcr = 0.0;
    foreach ($stmt as &$en) { $bal += $en['debit'] - $en['credit']; $en['bal'] = round($bal, 2); $tdr += $en['debit']; $tcr += $en['credit']; } unset($en);
    return ['stmt' => $stmt, 'open' => $open, 'closing' => round($bal, 2), 'debit' => $tdr, 'credit' => $tcr, 'schemes' => count($schemes), 'has_items' => $has_items];
}

/** scheme no of a certificate description by the Customer Claim Report rule: middle number (6+ digits after the first "-", not a batch code) else first number */
function sl_scheme_fm($desc) {
    $desc = (string)$desc; $first = ''; $mid = '';
    $dash = strpos($desc, '-'); $head = $dash === false ? $desc : substr($desc, 0, $dash);
    if (preg_match('/^\s*(\d{6,})/', $head, $m)) $first = $m[1];
    $rest = $dash === false ? '' : substr($desc, $dash + 1);
    if ($rest !== '' && preg_match('/(?<![A-Za-z0-9])(\d{6,})/', $rest, $m)) $mid = $m[1];
    return $mid !== '' ? [$mid, 'M'] : ($first !== '' ? [$first, 'F'] : ['', '']);
}
/**
 * UNRECONCILED claim certificates (same as the "Unreconciled claim certificates" tab of the Scheme Discount Daily Report):
 * claim type "Weekly Activities (TTS)" whose scheme no is not a scheme no of that report (Current Scheme Status + HUL / TOT masters + the numbers in their descriptions).
 * Banking date is the date; $o = from / to / years / months / period like sl_compute. Returns n, actual, vat, total.
 */
function sl_unreconciled($conn, $o) {
    $out = ['n' => 0, 'actual' => 0.0, 'vat' => 0.0, 'total' => 0.0];
    if (!sl_tbl($conn, 'claim_cert_items')) return $out;
    $known = []; $add = function ($c, $d) use (&$known) { $known[sl_norm($c)] = 1; if (preg_match_all('/\d{6,}/', (string)$d, $m)) foreach ($m[0] as $a) $known[sl_norm($a)] = 1; };
    if (sl_tbl($conn, 'scheme_status_items')) { $r = mysqli_query($conn, "SELECT activity_code, activity_desc FROM scheme_status_items WHERE UPPER(TRIM(activity_type)) <> 'DAMAGE'"); while ($r && ($x = mysqli_fetch_row($r))) $add($x[0], $x[1]); }
    if (sl_tbl($conn, 'scheme_master_items'))  { $r = mysqli_query($conn, "SELECT activity_code, activity_desc FROM scheme_master_items"); while ($r && ($x = mysqli_fetch_row($r))) $add($x[0], $x[1]); }
    unset($known['']);
    $e = fn($v) => mysqli_real_escape_string($conn, (string)$v);
    $w = ["claim_description IS NOT NULL", "claim_description <> ''", "LOWER(claim_type) LIKE '%weekly activities%tts%'"];
    $from = $o['from'] ?? ''; $to = $o['to'] ?? ''; $period = !empty($o['period']);
    if ($to) $w[] = "(banking_date IS NULL OR banking_date <= '{$e($to)}')";
    if ($from && $period) $w[] = "banking_date >= '{$e($from)}'";
    $years = array_values(array_filter(array_map('intval', $o['years'] ?? []))); $months = array_values(array_filter(array_map('intval', $o['months'] ?? [])));
    if ($years)  $w[] = "YEAR(banking_date) IN (" . implode(',', $years) . ")";
    if ($months) $w[] = "MONTH(banking_date) IN (" . implode(',', $months) . ")";
    $r = mysqli_query($conn, "SELECT claim_description, actual_amount, vat_amount, total_amount FROM claim_cert_items WHERE " . implode(' AND ', $w));
    while ($r && ($x = mysqli_fetch_assoc($r))) {
        $d = (string)$x['claim_description']; [$no] = sl_scheme_fm($d); $no = sl_norm($no);
        if ($no === '' || substr(ltrim($d), 0, 1) === '-') continue;          // no scheme no = not a scheme certificate
        if (isset($known[$no])) continue;                                      // tallies with a scheme of the report
        $out['n']++; $out['actual'] += (float)$x['actual_amount']; $out['vat'] += (float)$x['vat_amount']; $out['total'] += (float)$x['total_amount'];
    }
    foreach (['actual', 'vat', 'total'] as $k) $out[$k] = round($out[$k], 2);
    return $out;
}

/**
 * TOTALS OF THE SCHEME DISCOUNT DAILY REPORT (the dashboard line "Scheme Discount & Free Issues" uses these, so it always equals the report's totals).
 * Same rows as the report: Current Scheme Status schemes (not DAMAGE) + the HUL / TOT Scheme Master codes that are not in the status; same date rule
 * (schemes running in the range: start <= to AND end >= from). Claim certificates are counted ONCE per scheme code.
 * Balance = total claim − total claim certificate amount − the VAT of those schemes whose unpaid difference is FULLY the same as the certificates' VAT.
 * Returns claim, cert, vat_same, balance, schemes.
 */
function sl_report_totals($conn, $from = '', $to = '') {
    $out = ['claim' => 0.0, 'cert' => 0.0, 'vat_same' => 0.0, 'balance' => 0.0, 'schemes' => 0];
    if (!sl_tbl($conn, 'scheme_status_items')) return $out;
    $e = fn($v) => mysqli_real_escape_string($conn, (string)$v);
    $dk = function ($d) { preg_match_all('/\d{6,}/', (string)$d, $m); return array_values(array_unique(array_map('sl_norm', $m[0]))); };
    $dw = function ($a, $b) use ($from, $to, $e) { $w = []; if ($to) $w[] = "($a IS NULL OR $a <= '{$e($to)}')"; if ($from) $w[] = "($b IS NULL OR $b >= '{$e($from)}')"; return $w; };
    $items = [];                      // rows: code, desc, claim
    $w = array_merge(["UPPER(TRIM(activity_type)) <> 'DAMAGE'"], $dw('start_date', 'end_date'));
    $r = mysqli_query($conn, "SELECT activity_code, activity_desc, claim_amount FROM scheme_status_items WHERE " . implode(' AND ', $w));
    while ($r && ($x = mysqli_fetch_assoc($r))) $items[] = ['code' => $x['activity_code'], 'desc' => $x['activity_desc'], 'claim' => $x['claim_amount'] === null ? 0.0 : (float)$x['claim_amount']];
    if (sl_tbl($conn, 'scheme_master_items')) {
        $sc = []; $r = mysqli_query($conn, "SELECT DISTINCT activity_code FROM scheme_status_items WHERE UPPER(TRIM(activity_type)) <> 'DAMAGE'"); while ($r && ($x = mysqli_fetch_row($r))) $sc[sl_norm($x[0])] = 1;
        $w = array_merge(['1=1'], $dw('date_from', 'date_to'));
        $r = mysqli_query($conn, "SELECT activity_code, activity_desc FROM scheme_master_items WHERE " . implode(' AND ', $w) . " ORDER BY date_from DESC, id DESC"); $seen = [];
        while ($r && ($x = mysqli_fetch_assoc($r))) { $k = sl_norm($x['activity_code']); if (isset($sc[$k]) || isset($seen[$k])) continue; $seen[$k] = 1; $items[] = ['code' => $x['activity_code'], 'desc' => $x['activity_desc'], 'claim' => 0.0]; }
    }
    if (!$items) return $out;
    $byCode = []; $set = [];
    foreach ($items as $it) { $k = sl_norm($it['code']); $byCode[$k][] = $it; $set[$k] = 1; foreach ($dk($it['desc']) as $a) $set[$a] = 1; }
    unset($set['']);
    $tally = [];
    if (sl_tbl($conn, 'claim_cert_items')) {
        $r = mysqli_query($conn, "SELECT claim_description, actual_amount, vat_amount FROM claim_cert_items WHERE claim_description IS NOT NULL AND claim_description <> ''", MYSQLI_USE_RESULT);
        while ($r && ($x = mysqli_fetch_assoc($r))) {
            $d = (string)$x['claim_description']; $hit = null; $first = sl_norm(explode('-', $d, 2)[0]);
            if ($first !== '' && isset($set[$first])) $hit = $first;
            else foreach (preg_split('/[^A-Za-z0-9]+/', $d, -1, PREG_SPLIT_NO_EMPTY) as $t) { $t = sl_norm($t); if (isset($set[$t])) { $hit = $t; break; } }
            if ($hit === null) continue;
            if (!isset($tally[$hit])) $tally[$hit] = ['act' => 0.0, 'vat' => 0.0];
            $tally[$hit]['act'] += (float)$x['actual_amount']; $tally[$hit]['vat'] += (float)$x['vat_amount'];
        }
        if ($r instanceof mysqli_result) mysqli_free_result($r);
    }
    foreach ($byCode as $k => $rows) {
        $claim = 0.0; foreach ($rows as $it) $claim += max(0, $it['claim']);
        $c = $tally[$k] ?? null; if (!$c) foreach ($dk($rows[0]['desc']) as $a) if (isset($tally[$a])) { $c = $tally[$a]; break; }     // scheme code not on the certificate → the number written in the scheme description
        $act = $c ? $c['act'] : 0.0; $vat = $c ? round($c['vat'], 2) : 0.0; $diff = round($claim - $act, 2);
        $out['claim'] += $claim; $out['cert'] += $act;
        if ($vat > 0 && $diff > 0 && abs($diff - $vat) <= 0.01) $out['vat_same'] += $vat;
        $out['schemes']++;
    }
    foreach (['claim', 'cert', 'vat_same'] as $kk) $out[$kk] = round($out[$kk], 2);
    $out['balance'] = round($out['claim'] - $out['cert'] - $out['vat_same'], 2);
    return $out;
}
}
