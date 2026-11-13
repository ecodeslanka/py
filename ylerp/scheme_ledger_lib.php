<?php
/**
 * scheme_ledger_lib.php — the Scheme Ledger calculation, shared by scheme_ledger_report.php and the dashboard.
 *   Debit  = Claim Amount of the Current Scheme Status upload (DAMAGE schemes left out)
 *   Credit = Claim Certificate actual amount whose description starts with the scheme no
 *   Balance = running Debit − Credit
 * Options: from, to (dates), moc, q (scheme no / description), years[], months[] (of the entry date).
 */
if (!function_exists('sl_compute')) {
function sl_norm($c) { return strtoupper(preg_replace('/\.0+$/', '', trim((string)$c))); }
function sl_tbl($conn, $t) { $r = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'"); return $r && mysqli_num_rows($r) > 0; }
function sl_compute($conn, $o) {
    $e = fn($v) => mysqli_real_escape_string($conn, (string)$v);
    $from = $o['from'] ?? ''; $to = $o['to'] ?? ''; $moc = trim($o['moc'] ?? ''); $q = trim($o['q'] ?? '');
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
    $entries = [];
    foreach ($schemes as $k => $sc) foreach ($sc['rows'] as $x) {
        if ($x['claim_amount'] === null || (float)$x['claim_amount'] <= 0) continue;
        $date = $x['claim_date'] ?: ($x['end_date'] ?: $x['start_date']);
        $entries[] = ['date' => $date ?: '', 'scheme' => trim($x['activity_code']), 'desc' => $sc['desc'], 'type' => 'Claim', 'ref' => $x['claim_moc_ref'] !== '' && $x['claim_moc_ref'] !== null ? 'Claim MOC ' . $x['claim_moc_ref'] : 'MOC ' . $x['moc_ref'], 'debit' => (float)$x['claim_amount'], 'credit' => 0.0, 'o' => 1];
    }
    if ($has_cc && $schemes) {
        $codes = array_map(fn($k) => "'" . $e($k) . "'", array_keys($schemes));
        $r = mysqli_query($conn, "SELECT tax_invoice_no, invoice_date, banking_date, imported_at, claim_description, actual_amount,
                                         UPPER(TRIM(TRAILING '.0' FROM TRIM(SUBSTRING_INDEX(claim_description, '-', 1)))) AS sn
                                  FROM claim_cert_items WHERE claim_description IS NOT NULL AND claim_description <> '' AND claim_description NOT LIKE '-%'
                                    AND UPPER(TRIM(TRAILING '.0' FROM TRIM(SUBSTRING_INDEX(claim_description, '-', 1)))) IN (" . implode(',', $codes) . ") AND COALESCE(actual_amount,0) <> 0");
        while ($r && ($x = mysqli_fetch_assoc($r))) {
            $sn = $x['sn']; $date = $x['invoice_date'] ?: ($x['banking_date'] ?: substr((string)$x['imported_at'], 0, 10));
            $entries[] = ['date' => $date ?: '', 'scheme' => $sn, 'desc' => $schemes[$sn]['desc'] ?? '', 'type' => 'Claim certificate', 'ref' => 'Tax inv ' . $x['tax_invoice_no'] . ' · ' . $x['claim_description'], 'debit' => 0.0, 'credit' => (float)$x['actual_amount'], 'o' => 2];
        }
    }
    usort($entries, fn($a, $b) => strcmp($a['date'], $b['date']) ?: $a['o'] <=> $b['o'] ?: strcmp($a['scheme'], $b['scheme']));
    $open = 0.0; $stmt = [];
    foreach ($entries as $en) {
        $d = $en['date'];
        if ($from && $d !== '' && $d < $from) { $open += $en['debit'] - $en['credit']; continue; }
        if ($to && $d !== '' && $d > $to) continue;
        if ($years  && ($d === '' || !in_array((int)substr($d, 0, 4), $years, true)))  continue;
        if ($months && ($d === '' || !in_array((int)substr($d, 5, 2), $months, true))) continue;
        $stmt[] = $en;
    }
    $bal = $open; $tdr = 0.0; $tcr = 0.0;
    foreach ($stmt as &$en) { $bal += $en['debit'] - $en['credit']; $en['bal'] = round($bal, 2); $tdr += $en['debit']; $tcr += $en['credit']; } unset($en);
    return ['stmt' => $stmt, 'open' => $open, 'closing' => round($bal, 2), 'debit' => $tdr, 'credit' => $tcr, 'schemes' => count($schemes), 'has_items' => $has_items];
}
}
