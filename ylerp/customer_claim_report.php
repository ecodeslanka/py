<?php
/**
 * customer_claim_report.php
 * "CC Report" — Customer Claim Certificate Report
 * Shows ALL imported claim_cert_items with date filters, search, customer/entity/status filters,
 * KPI summary, and Excel export.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
include_once 'config.php';
if (!function_exists('yms_q')) { function yms_q($conn, $sql) { try { return $conn ? mysqli_query($conn, $sql) : false; } catch (Throwable $e) { return false; } } }   // a failed query returns false (the server throws mysqli exceptions)

function ensure_claim_tables_r($conn) {
    yms_q($conn, "CREATE TABLE IF NOT EXISTS claim_cert_uploads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_name VARCHAR(255) NOT NULL,
        customer_code VARCHAR(50) DEFAULT NULL,
        customer_name VARCHAR(255) DEFAULT NULL,
        total_rows INT DEFAULT 0,
        imported INT DEFAULT 0,
        skipped_dup INT DEFAULT 0,
        upload_date DATE DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_customer(customer_code),
        INDEX idx_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    yms_q($conn, "CREATE TABLE IF NOT EXISTS claim_cert_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        upload_id INT NOT NULL,
        row_no INT DEFAULT NULL,
        customer_code VARCHAR(50) DEFAULT NULL,
        customer_name VARCHAR(255) DEFAULT NULL,
        ledger_type VARCHAR(100) DEFAULT NULL,
        status VARCHAR(100) DEFAULT NULL,
        claim_type VARCHAR(100) DEFAULT NULL,
        tax_invoice_no VARCHAR(100) DEFAULT NULL,
        invoice_date DATE DEFAULT NULL,
        banking_date DATE DEFAULT NULL,
        entity VARCHAR(50) DEFAULT NULL,
        claim_description TEXT DEFAULT NULL,
        actual_amount DECIMAL(18,4) DEFAULT 0,
        vat_amount DECIMAL(18,4) DEFAULT 0,
        total_amount DECIMAL(18,4) DEFAULT 0,
        imported_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        imported_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_upload(upload_id),
        INDEX idx_tax_inv(tax_invoice_no),
        INDEX idx_customer(customer_code),
        UNIQUE KEY uniq_tax_inv_entity(tax_invoice_no, entity)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* Build WHERE clause shared by report + export */
/**
 * Scheme no of a claim description, e.g.
 *   "41429551-B483131026-SR Advane - Sep 26"            → 41429551 (only the first part has a number)          → source F
 *   "41448039-B505221026-41444095Vim Dishwash Bar 3 Ban" → 41444095 (the middle number differs from the first)   → source M
 *   first and middle number the same                     → that number                                         → source M
 * The "middle" number = a 6+ digit number after the first "-" that is not part of a batch code (B483131026).
 * Returns [scheme_no, 'F'|'M'|''].
 */
function cc_scheme_of($desc) {
    $desc = (string)$desc; $first = ''; $mid = '';
    $dash = strpos($desc, '-');
    $head = $dash === false ? $desc : substr($desc, 0, $dash);
    if (preg_match('/^\s*(\d{6,})\s*$/', $head, $m)) $first = $m[1];
    elseif (preg_match('/^\s*(\d{6,})/', $head, $m)) $first = $m[1];
    $rest = $dash === false ? '' : substr($desc, $dash + 1);
    if ($rest !== '' && preg_match('/(?<![A-Za-z0-9])(\d{6,})/', $rest, $m)) $mid = $m[1];
    if ($first !== '' && $mid !== '') return [$mid, 'M'];          // different → the middle one; same → "normal" → M
    if ($first !== '') return [$first, 'F'];                       // only the first part has a number
    if ($mid !== '') return [$mid, 'M'];
    return ['', ''];
}

function cc_build_where($conn) {
    $w = [];

    $dateField = $_REQUEST['date_field'] ?? 'imported_at';
    $allowedFields = ['invoice_date', 'banking_date', 'imported_at', 'upload_date'];
    if (!in_array($dateField, $allowedFields, true)) $dateField = 'imported_at';
    $col = $dateField === 'upload_date' ? 'cu.upload_date' : 'ci.' . $dateField;

    $from = trim($_REQUEST['date_from'] ?? '');
    $to   = trim($_REQUEST['date_to']   ?? '');
    if ($from !== '') {
        $from_e = mysqli_real_escape_string($conn, $from);
        $w[] = "$col >= '$from_e" . ($dateField === 'imported_at' ? ' 00:00:00' : '') . "'";
    }
    if ($to !== '') {
        $to_e = mysqli_real_escape_string($conn, $to);
        $w[] = "$col <= '$to_e" . ($dateField === 'imported_at' ? ' 23:59:59' : '') . "'";
    }

    $search = trim($_REQUEST['search'] ?? '');
    if ($search !== '') {
        $s_e = mysqli_real_escape_string($conn, $search);
        $num = str_replace(',', '', $search);
        $amtOr = is_numeric($num) ? " OR ABS(ci.actual_amount - " . (float)$num . ") < 0.5 OR ABS(ci.vat_amount - " . (float)$num . ") < 0.5 OR ABS(ci.total_amount - " . (float)$num . ") < 0.5" : '';   // a number typed in Search also finds that amount
        $w[] = "(ci.tax_invoice_no LIKE '%$s_e%' OR ci.claim_description LIKE '%$s_e%' " .
               "OR ci.customer_name LIKE '%$s_e%' OR ci.customer_code LIKE '%$s_e%' " .
               "OR cu.file_name LIKE '%$s_e%'$amtOr)";
    }

    /* Amount filter: one amount ("12500", "12,500.00"), a range ("10000-50000") or "> 100000" / "< 5000" / ">=" / "<=".
       It matches the Actual, the VAT or the Total amount of the claim (amount_in = actual | vat | total | any). */
    $amtIn = $_REQUEST['amount_in'] ?? 'any'; $cols = ['actual' => ['ci.actual_amount'], 'vat' => ['ci.vat_amount'], 'total' => ['ci.total_amount']][$amtIn] ?? ['ci.actual_amount', 'ci.vat_amount', 'ci.total_amount'];
    $amt = str_replace([',', ' ', 'Rs.', 'rs.', 'Rs'], '', trim((string)($_REQUEST['amount'] ?? '')));
    if ($amt !== '') {
        $cond = null;
        if (preg_match('/^(-?\d+(?:\.\d+)?)-(-?\d+(?:\.\d+)?)$/', $amt, $m)) { $a = min((float)$m[1], (float)$m[2]); $b = max((float)$m[1], (float)$m[2]); $cond = fn($c) => "$c BETWEEN " . ($a - 0.005) . " AND " . ($b + 0.005); }
        elseif (preg_match('/^(>=|<=|>|<)(-?\d+(?:\.\d+)?)$/', $amt, $m)) { $op = $m[1]; $v = (float)$m[2]; $cond = fn($c) => "$c $op $v"; }
        elseif (is_numeric($amt)) { $v = (float)$amt; $cond = fn($c) => "ABS($c - $v) < 0.5"; }
        if ($cond) $w[] = '(' . implode(' OR ', array_map($cond, $cols)) . ')';
    }

    $cust = trim($_REQUEST['customer_code'] ?? '');
    if ($cust !== '') { $c_e = mysqli_real_escape_string($conn, $cust); $w[] = "ci.customer_code='$c_e'"; }

    $entity = trim($_REQUEST['entity'] ?? '');
    if ($entity !== '') { $e_e = mysqli_real_escape_string($conn, $entity); $w[] = "ci.entity='$e_e'"; }

    $status = trim($_REQUEST['status'] ?? '');
    if ($status !== '') { $st_e = mysqli_real_escape_string($conn, $status); $w[] = "ci.status='$st_e'"; }

    $claimTypes = array_values(array_filter(array_map('trim', explode('||', (string)($_REQUEST['claim_type'] ?? ''))), fn($v) => $v !== ''));
    if ($claimTypes) $w[] = "ci.claim_type IN ('" . implode("','", array_map(fn($v) => mysqli_real_escape_string($conn, $v), $claimTypes)) . "')";

    $uploadId = trim($_REQUEST['upload_id'] ?? '');
    if ($uploadId !== '' && ctype_digit($uploadId)) { $w[] = "ci.upload_id=" . intval($uploadId); }

    return $w ? ('WHERE ' . implode(' AND ', $w)) : '';
}

/* ══ SEND A CLAIM CERTIFICATE TO A CLAIM PAGE ══
   "Send to …" puts the claim certificate on that page's claim list (table claim_cert_send_list: one destination per claim);
   on that page it is then picked as the matched certificate when the payment is entered, as normal.
     sscl_vat → SSCL & VAT (sscl_vat.php) · muthupalasa → Muthupalasa BP Claims (debit_note.php) · promoters → Promoters Salary (promoters_salary_cl.php)
     sr_incentive → SR Incentive (sr_incentive_cc.php) · dal → Drivers & Loyalty (dal.php) · others → Others (others_cc.php)
   Matches already made: the settlement table of each page ($CC_SETTLE), Drivers & Loyalty from dl_confirmed_payments. */
$CC_DEST = ['sscl_vat' => ['SSCL & VAT', 'sscl_vat'], 'muthupalasa' => ['Muthupalasa BP Claims', 'debit_note'], 'promoters' => ['Promoters Salary', 'promoters_salary_cl'],
            'sr_incentive' => ['SR Incentive', 'sr_incentive_cc'], 'dal' => ['Drivers & Loyalty', 'dal'], 'others' => ['Others', 'others_cc']];
/* settlement table of each page: matched claims are read from claim_cert_item_id (amount = total_amount, payment = the record id column) */
$CC_SETTLE = ['sscl_vat' => ['sscl_vat_settlements', 'sv_id'], 'muthupalasa' => ['dn_settlements', 'dn_id'], 'promoters' => ['pscl_settlements', 'pscl_id'], 'sr_incentive' => ['sricc_settlements', 'sricc_id']];
function cc_sv_has($conn, $t) { $r = yms_q($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'"); return $r && mysqli_num_rows($r) > 0; }
function cc_sv_tables($conn) {
    static $done = false; if ($done) return; $done = true;
    yms_q($conn, "CREATE TABLE IF NOT EXISTS claim_cert_send_list (claim_cert_item_id INT NOT NULL PRIMARY KEY, dest VARCHAR(20) NOT NULL, sent_by VARCHAR(100) NULL, sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY k_dest (dest))");
    if (cc_sv_has($conn, 'sscl_vat_claim_list'))   // the first version kept the SSCL & VAT list in its own table
        yms_q($conn, "INSERT IGNORE INTO claim_cert_send_list (claim_cert_item_id, dest, sent_by, sent_at) SELECT claim_cert_item_id, 'sscl_vat', sent_by, sent_at FROM sscl_vat_claim_list");
}
function cc_sv_can($conn, $dest) { global $CC_DEST; if (!function_exists('rbac_can')) @include_once __DIR__ . '/rbac.php'; $pg = $CC_DEST[$dest][1] ?? 'sscl_vat';
    return function_exists('rbac_can') ? (rbac_can('create', $pg) || rbac_can('edit', $pg)) : true; }
function cc_sv_is_tax($r) { $t = strtoupper(($r['claim_type'] ?? '') . ' ' . ($r['claim_description'] ?? '')); return strpos($t, 'SSCL') !== false || strpos($t, 'VAT') !== false; }
/* per claim: where it was sent, and what is already matched (SSCL & VAT payments / Drivers & Loyalty confirmed payments) */
function cc_sv_links($conn, array $ids) {
    global $CC_SETTLE; $out = []; $ids = array_values(array_filter(array_map('intval', $ids))); if (!$ids) return $out;
    cc_sv_tables($conn); $in = implode(',', $ids);
    foreach ($CC_SETTLE as $d => [$tb, $pk]) { if (!cc_sv_has($conn, $tb)) continue;
        $q = yms_q($conn, "SELECT claim_cert_item_id AS cid, $pk AS rid, SUM(COALESCE(total_amount, amount, 0)) AS amt, MAX(DATE(created_at)) AS pd FROM $tb WHERE claim_cert_item_id IN ($in) GROUP BY claim_cert_item_id, $pk");
        while ($q && ($x = mysqli_fetch_assoc($q))) { $c = (int)$x['cid']; $out[$c]['settled'] = ($out[$c]['settled'] ?? 0) + (float)$x['amt']; $out[$c]['pays'][] = ['d' => $d, 'id' => (int)$x['rid'], 'amt' => round((float)$x['amt'], 2), 'pd' => $x['pd']]; } }
    if (cc_sv_has($conn, 'dl_confirmed_payments')) {
        $q = yms_q($conn, "SELECT claim_item_id AS cid, MAX(COALESCE(dl_record_id, id)) AS rid, SUM(COALESCE(total_amount, 0)) AS amt, MAX(DATE(confirmed_at)) AS pd FROM dl_confirmed_payments WHERE claim_item_id IN ($in) GROUP BY claim_item_id");
        while ($q && ($x = mysqli_fetch_assoc($q))) { $c = (int)$x['cid']; $out[$c]['settled'] = ($out[$c]['settled'] ?? 0) + (float)$x['amt']; $out[$c]['pays'][] = ['d' => 'dal', 'id' => (int)$x['rid'], 'amt' => round((float)$x['amt'], 2), 'pd' => $x['pd']]; } }
    $q = yms_q($conn, "SELECT claim_cert_item_id AS cid, dest, sent_at FROM claim_cert_send_list WHERE claim_cert_item_id IN ($in)");
    while ($q && ($x = mysqli_fetch_assoc($q))) { $out[(int)$x['cid']]['dest'] = $x['dest']; $out[(int)$x['cid']]['listed'] = $x['sent_at']; }
    if (cc_sv_has($conn, 'dl_cheque_acknowledgments')) { $q = yms_q($conn, "SELECT claim_item_id AS cid, confirmed_payment_id AS sv FROM dl_cheque_acknowledgments WHERE claim_item_id IN ($in)");
        while ($q && ($x = mysqli_fetch_assoc($q))) $out[(int)$x['cid']]['ack'] = (int)$x['sv']; }
    return $out;
}
/* the button ticks on the role form (Users → Roles → this page → Buttons on this page) are checked here too */
function cc_sv_btn($key) { return !function_exists('rbac_btn_can') || rbac_btn_can($key, 'customer_claim_report'); }
function cc_sv_user() { return $_SESSION['username'] ?? ($_SESSION['user_name'] ?? ($_SESSION['full_name'] ?? 'system')); }
if (isset($_REQUEST['ajax_action']) && $_REQUEST['ajax_action'] === 'sv_list' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start(); header('Content-Type: application/json'); cc_sv_tables($conn);
    $fail = function ($m) { ob_end_clean(); echo json_encode(['success' => false, 'message' => $m]); exit; };
    $dest = (string)($_POST['dest'] ?? 'sscl_vat'); if (!isset($CC_DEST[$dest])) $fail('Unknown destination.'); $dn = $CC_DEST[$dest][0];
    if (!cc_sv_can($conn, $dest)) $fail('You do not have the right to add to the ' . $dn . ' page.');
    if (!cc_sv_btn(($_POST['via'] ?? '') === 'bulk' ? 'send_selected' : 'send_to_' . rbac_btn_slug_cc($dn))) $fail('Permission blocked: you are not allowed to use this button.');
    $ids = array_values(array_unique(array_filter(array_map('intval', json_decode($_POST['ids'] ?? '[]', true) ?: [])))); if (!$ids) $fail('Select at least one claim.');
    $L = cc_sv_links($conn, $ids); $u = mysqli_real_escape_string($conn, cc_sv_user()); $n = 0; $skip = 0;
    $q = mysqli_query($conn, "SELECT id, total_amount FROM claim_cert_items WHERE id IN (" . implode(',', $ids) . ")");
    while ($q && ($x = mysqli_fetch_assoc($q))) { $c = (int)$x['id'];
        if (!empty($L[$c]['dest']) || ((float)$x['total_amount'] - ($L[$c]['settled'] ?? 0)) <= 0.004) { $skip++; continue; }
        if (mysqli_query($conn, "INSERT IGNORE INTO claim_cert_send_list (claim_cert_item_id, dest, sent_by) VALUES ($c, '$dest', '$u')")) $n += mysqli_affected_rows($conn); }
    ob_end_clean(); echo json_encode(['success' => true, 'message' => $n . ' claim(s) sent to the ' . $dn . ' list' . ($skip ? ' (' . $skip . ' already sent somewhere or fully matched)' : '') . '. Pick them on the ' . $dn . ' page when you enter the payment.']); exit;
}
if (isset($_REQUEST['ajax_action']) && $_REQUEST['ajax_action'] === 'sv_unlist' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start(); header('Content-Type: application/json'); cc_sv_tables($conn);
    $fail = function ($m) { ob_end_clean(); echo json_encode(['success' => false, 'message' => $m]); exit; };
    $c = intval($_POST['id'] ?? 0); if (!$c) $fail('Nothing to reverse.'); $L = cc_sv_links($conn, [$c]);
    $dest = $L[$c]['dest'] ?? ''; if ($dest === '') $fail('This claim is not on any list.'); $dn = $CC_DEST[$dest][0] ?? $dest;
    if (!cc_sv_can($conn, $dest)) $fail('You do not have the right to change the ' . $dn . ' list.');
    if (!cc_sv_btn('reverse')) $fail('Permission blocked: you are not allowed to use the Reverse button.');
    if (!empty($L[$c]['pays'])) $fail('This claim is already matched on a ' . ($CC_DEST[$L[$c]['pays'][0]['d']][0] ?? '') . ' payment — remove that payment line on that page first.');
    if (!empty($L[$c]['ack'])) $fail('This claim was already sent to Acknowledgment — reverse it there first.');
    $ok = mysqli_query($conn, "DELETE FROM claim_cert_send_list WHERE claim_cert_item_id = $c");
    ob_end_clean(); echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Reversed — the claim is removed from the ' . $dn . ' list.' : mysqli_error($conn)]); exit;
}
function rbac_btn_slug_cc($t) { return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(html_entity_decode($t))), '_'); }

/* ══ SETTLEMENTS of one claim certificate on every claim page — list, and delete one (the same as that page's own delete) ══ */
$CC_STL = [   // page => [settlement table, payment-record column, payment-record table, acknowledgment source, page name, deletes a document file]
    'sscl_vat'     => ['sscl_vat_settlements', 'sv_id',    'sscl_vat',            'ssclvat', 'SSCL & VAT',            true],
    'muthupalasa'  => ['dn_settlements',       'dn_id',    'debit_note',          'dn',      'Muthupalasa BP Claims', false],
    'promoters'    => ['pscl_settlements',     'pscl_id',  'promoters_salary_cl', 'pscl',    'Promoters Salary',      false],
    'sr_incentive' => ['sricc_settlements',    'sricc_id', 'sr_incentive_cc',     'sricc',   'SR Incentive',          false],
];
$CC_PAGE = ['sscl_vat' => 'sscl_vat', 'muthupalasa' => 'debit_note', 'promoters' => 'promoters_salary_cl', 'sr_incentive' => 'sr_incentive_cc', 'dal' => 'dal'];
function cc_cols($conn, $t) { static $m = []; if (isset($m[$t])) return $m[$t]; $o = []; $r = yms_q($conn, "SHOW COLUMNS FROM `$t`"); while ($r && ($x = mysqli_fetch_assoc($r))) $o[$x['Field']] = 1; return $m[$t] = $o; }
if (isset($_REQUEST['ajax_action']) && $_REQUEST['ajax_action'] === 'sv_settlements') {
    ob_start(); header('Content-Type: application/json');
    $cid = intval($_REQUEST['id'] ?? 0); $out = []; $ackOk = cc_sv_has($conn, 'dl_cheque_acknowledgments');
    $claim = $cid ? mysqli_fetch_assoc(yms_q($conn, "SELECT id, tax_invoice_no, customer_code, customer_name, claim_type, claim_description, actual_amount, vat_amount, total_amount FROM claim_cert_items WHERE id = $cid")) : null;
    foreach ($CC_STL as $k => [$tb, $pk, $pt, $src, $pn, $doc]) {
        if (!cc_sv_has($conn, $tb)) continue; $C = cc_cols($conn, $tb); $P = cc_sv_has($conn, $pt) ? cc_cols($conn, $pt) : [];
        $pDate = isset($P['paid_date']) ? 'p.paid_date' : (isset($P['entry_date']) ? 'p.entry_date' : 'NULL');
        $pDesc = isset($P['description']) ? 'p.description' : (isset($P['tax_type']) ? 'p.tax_type' : (isset($P['category']) ? 'p.category' : (isset($P['entity']) ? 'p.entity' : "''")));
        $sel = "s.id, s.$pk AS rid, " . (isset($C['tax_invoice_no']) ? 's.tax_invoice_no' : "''") . " AS ti, " . (isset($C['banking_date']) ? 's.banking_date' : 'NULL') . " AS bd, " . (isset($C['bank_ref']) ? 's.bank_ref' : "''") . " AS ref, "
             . (isset($C['amount']) ? 'COALESCE(s.amount,0)' : '0') . " AS amt, " . (isset($C['vat_amount']) ? 'COALESCE(s.vat_amount,0)' : '0') . " AS vat, " . (isset($C['total_amount']) ? 'COALESCE(s.total_amount, s.amount, 0)' : 'COALESCE(s.amount,0)') . " AS tot, "
             . (isset($C['created_at']) ? 's.created_at' : 'NULL') . " AS at, " . ($P ? "$pDate AS pd, $pDesc AS pdesc" : "NULL AS pd, '' AS pdesc")
             . ($ackOk ? ", (SELECT COUNT(*) FROM dl_cheque_acknowledgments a WHERE a.source = '$src' AND a.source_settlement_id = s.id) AS ack" : ", 0 AS ack");
        $r = yms_q($conn, "SELECT $sel FROM `$tb` s " . ($P ? "LEFT JOIN `$pt` p ON p.id = s.$pk " : '') . "WHERE s.claim_cert_item_id = $cid ORDER BY s.id");
        while ($r && ($x = mysqli_fetch_assoc($r))) $out[] = ['page' => $k, 'pn' => $pn, 'url' => $pt . '.php', 'id' => (int)$x['id'], 'rid' => (int)$x['rid'], 'ti' => $x['ti'], 'bd' => $x['bd'], 'ref' => $x['ref'], 'amt' => round((float)$x['amt'], 2), 'vat' => round((float)$x['vat'], 2),
            'tot' => round((float)$x['tot'], 2), 'at' => $x['at'], 'pd' => $x['pd'], 'pdesc' => $x['pdesc'], 'ack' => (int)$x['ack'], 'can' => cc_sv_can($conn, $k) && cc_sv_btn('delete')]; }
    if (cc_sv_has($conn, 'dl_confirmed_payments')) {   // Drivers & Loyalty: shown, but changed on its own page
        $D = cc_cols($conn, 'dl_confirmed_payments'); $f = fn($c, $d) => isset($D[$c]) ? $c : $d;   // only the columns this version has
        $r = yms_q($conn, "SELECT id, " . $f('dl_record_id', 'id') . " AS rid, " . $f('tax_invoice_no', "''") . " AS ti, " . $f('banking_date', 'NULL') . " AS bd, COALESCE(" . $f('actual_amount', '0') . ",0) AS amt, COALESCE(" . $f('vat_amount', '0') . ",0) AS vat, COALESCE(" . $f('total_amount', '0') . ",0) AS tot, " . $f('confirmed_at', 'NULL') . " AS at FROM dl_confirmed_payments WHERE claim_item_id = $cid");
        while ($r && ($x = mysqli_fetch_assoc($r))) $out[] = ['page' => 'dal', 'pn' => 'Drivers & Loyalty', 'url' => 'dal.php', 'id' => (int)$x['id'], 'rid' => (int)$x['rid'], 'ti' => $x['ti'], 'bd' => $x['bd'], 'ref' => '', 'amt' => round((float)$x['amt'], 2), 'vat' => round((float)$x['vat'], 2), 'tot' => round((float)$x['tot'], 2), 'at' => $x['at'], 'pd' => null, 'pdesc' => '', 'ack' => 0, 'can' => false]; }
    ob_end_clean(); echo json_encode(['success' => true, 'claim' => $claim, 'rows' => $out]); exit;
}
if (isset($_REQUEST['ajax_action']) && $_REQUEST['ajax_action'] === 'sv_settle_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start(); header('Content-Type: application/json');
    $fail = function ($m) { ob_end_clean(); echo json_encode(['success' => false, 'message' => $m]); exit; };
    $k = (string)($_POST['page'] ?? ''); $sid = intval($_POST['sid'] ?? 0); if (!isset($CC_STL[$k]) || !$sid) $fail('Nothing to delete.');
    [$tb, $pk, $pt, $src, $pn, $doc] = $CC_STL[$k];
    if (!function_exists('rbac_can')) @include_once __DIR__ . '/rbac.php';
    if (function_exists('rbac_can') && !(rbac_can('delete', $pt) || rbac_can('edit', $pt))) $fail('You do not have the right to delete payments on the ' . $pn . ' page.');
    if (!cc_sv_btn('delete')) $fail('Permission blocked: you are not allowed to use the Delete button.');
    $C = cc_cols($conn, $tb); $row = mysqli_fetch_assoc(yms_q($conn, "SELECT * FROM `$tb` WHERE id = $sid")); if (!$row) $fail('That settlement no longer exists.');
    if ($doc && !empty($row['document_path']) && is_file($row['document_path'])) @unlink($row['document_path']);   // as the page itself does
    if (cc_sv_has($conn, 'dl_cheque_acknowledgments')) yms_q($conn, "DELETE FROM dl_cheque_acknowledgments WHERE source = '$src' AND source_settlement_id = $sid");
    $ok = yms_q($conn, "DELETE FROM `$tb` WHERE id = $sid AND $pk = " . (int)$row[$pk]);
    ob_end_clean(); echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Deleted: ' . number_format((float)($row['total_amount'] ?? $row['amount'] ?? 0), 2) . ' removed from ' . $pn . ' payment #' . (int)$row[$pk] . '.' : 'Could not delete.']); exit;
}

/* ── AJAX: get_report_data (paginated) ── */
if (isset($_REQUEST['ajax_action']) && $_REQUEST['ajax_action'] === 'get_report_data') {
    ob_start(); header('Content-Type: application/json');
    ensure_claim_tables_r($conn);

    $where = cc_build_where($conn);
    $page  = max(1, intval($_REQUEST['page'] ?? 1));
    $per   = max(1, min(2000, intval($_REQUEST['per_page'] ?? 100)));
    $off   = ($page - 1) * $per;

    $sortField = $_REQUEST['sort'] ?? 'imported_at';
    $allowedSort = ['imported_at','invoice_date','banking_date','tax_invoice_no','actual_amount','vat_amount','total_amount','customer_code'];
    if (!in_array($sortField, $allowedSort, true)) $sortField = 'imported_at';
    $sortDir = (strtoupper($_REQUEST['dir'] ?? 'DESC') === 'ASC') ? 'ASC' : 'DESC';

    $baseSql = "FROM claim_cert_items ci LEFT JOIN claim_cert_uploads cu ON ci.upload_id = cu.id $where";

    $cntRes = mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(SUM(ci.actual_amount),0) sa,
                                            COALESCE(SUM(ci.vat_amount),0) sv, COALESCE(SUM(ci.total_amount),0) st,
                                            COUNT(DISTINCT ci.customer_code) cust_n,
                                            COUNT(DISTINCT ci.upload_id) upload_n
                                     $baseSql");
    $summary = $cntRes ? mysqli_fetch_assoc($cntRes) : ['c'=>0,'sa'=>0,'sv'=>0,'st'=>0,'cust_n'=>0,'upload_n'=>0];

    $dataRes = mysqli_query($conn, "SELECT ci.*, cu.file_name, cu.upload_date
                                     $baseSql
                                     ORDER BY ci.$sortField $sortDir
                                     LIMIT $per OFFSET $off");
    $rows = [];
    if ($dataRes) while ($r = mysqli_fetch_assoc($dataRes)) { [$r['scheme_no'], $r['scheme_src']] = cc_scheme_of($r['claim_description']); $rows[] = $r; }
    $L = cc_sv_links($conn, array_column($rows, 'id'));
    foreach ($rows as &$r) { $c = (int)$r['id']; $r['sv_settled'] = round($L[$c]['settled'] ?? 0, 2); $r['sv_pays'] = $L[$c]['pays'] ?? []; $r['sv_ack'] = $L[$c]['ack'] ?? 0; $r['sv_listed'] = $L[$c]['listed'] ?? ''; $r['sv_dest'] = $L[$c]['dest'] ?? ''; $r['sv_tax'] = cc_sv_is_tax($r) ? 1 : 0; } unset($r);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'rows'    => $rows,
        'summary' => $summary,
        'page'    => $page,
        'per_page'=> $per,
        'total'   => intval($summary['c']),
        'pages'   => max(1, ceil(intval($summary['c']) / $per)),
    ]);
    exit;
}

/* ── Excel export: EVERY row of the table (all pages, current filters), built on the server — no row limit, no browser tool needed ── */
if (isset($_GET['export_xlsx'])) {
    ensure_claim_tables_r($conn);
    if (!class_exists('ZipArchive')) { while (ob_get_level()) ob_end_clean(); header('Content-Type: text/plain'); exit('The PHP zip extension is required for Excel export.'); }
    @set_time_limit(300); @ini_set('memory_limit', '1024M');
    require_once __DIR__ . '/rbac_xlsx.php';
    $where = cc_build_where($conn);
    $res = mysqli_query($conn, "SELECT ci.*, cu.file_name, cu.upload_date FROM claim_cert_items ci LEFT JOIN claim_cert_uploads cu ON ci.upload_id = cu.id $where ORDER BY ci.imported_at DESC, ci.id DESC", MYSQLI_USE_RESULT);
    $X = new MiniXlsx(); $sh = $X->sheet('CC Report');
    $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '312E81', 'h' => 'center', 'border' => 1, 'wrap' => 1];
    $heads = ['#', 'Customer Code', 'Customer Name', 'Upload File', 'Upload Date', 'Ledger Type', 'Status', 'Claim Type', 'Tax Invoice No', 'Invoice Date', 'Banking Date', 'Entity', 'Claim Description', 'Actual Amount', 'VAT Amount', 'Total Amount', 'Imported At', 'Imported By', 'Scheme No Source (F = first no · M = middle / normal)', 'Scheme No'];
    $sh->set(1, 1, 'Customer Claim Report — all rows of the table' . ((trim($_REQUEST['date_from'] ?? '') . trim($_REQUEST['date_to'] ?? '')) !== '' ? ' (' . trim($_REQUEST['date_from'] ?? '…') . ' to ' . trim($_REQUEST['date_to'] ?? '…') . ')' : ''), ['b' => 1, 'sz' => 14]);
    $fl = [];
    foreach (['claim_type' => 'Claim type', 'entity' => 'Entity', 'status' => 'Status', 'customer_code' => 'Customer', 'search' => 'Search', 'amount' => 'Amount'] as $k => $lab) { $v = trim((string)($_REQUEST[$k] ?? '')); if ($v !== '') $fl[] = $lab . ': ' . str_replace('||', ', ', $v); }
    $sh->set(2, 1, $fl ? 'Filters — ' . implode(' · ', $fl) : 'No filters — all rows', ['i' => 1, 'color' => '6B7280']);
    foreach ($heads as $i => $h) $sh->set(3, $i + 1, $h, $H);
    $r = 4; $n = 0; $ta = $tv = $tt = 0.0; $byType = [];
    if ($res) while ($x = mysqli_fetch_assoc($res)) {
        $n++; $desc = (string)$x['claim_description']; [$sn, $src] = cc_scheme_of($desc);
        $a = (float)$x['actual_amount']; $v = (float)$x['vat_amount']; $t = (float)$x['total_amount']; $ta += $a; $tv += $v; $tt += $t;
        $tk = trim((string)$x['claim_type']) ?: '(no type)'; if (!isset($byType[$tk])) $byType[$tk] = [0, 0.0, 0.0, 0.0]; $byType[$tk][0]++; $byType[$tk][1] += $a; $byType[$tk][2] += $v; $byType[$tk][3] += $t;
        $vals = [$n, $x['customer_code'], $x['customer_name'], $x['file_name'], $x['upload_date'], $x['ledger_type'], $x['status'], $x['claim_type'], $x['tax_invoice_no'], $x['invoice_date'], $x['banking_date'], $x['entity'], $desc, $a, $v, $t, $x['imported_at'], $x['imported_by'], $src, $sn];
        foreach ($vals as $i => $val) $sh->set($r, $i + 1, $val === null ? '' : $val, ['border' => 1] + (in_array($i, [13, 14, 15], true) ? ['h' => 'right', 'nf' => 4] : []) + ($i === 17 ? ['b' => 1, 'color' => '312E81'] : []) + ($i === 16 ? ['h' => 'center', 'b' => 1, 'color' => $src === 'F' ? 'B45309' : '15803D'] : []));
        $r++;
    }
    if ($res instanceof mysqli_result) mysqli_free_result($res);
    $tb = ['b' => 1, 'border' => 1, 'bg' => 'FDE68A', 'h' => 'right', 'nf' => 4];
    $sh->set($r, 13, 'TOTAL (' . number_format($n) . ' rows)', ['b' => 1, 'border' => 1, 'bg' => 'FDE68A']); $sh->set($r, 14, round($ta, 2), $tb); $sh->set($r, 15, round($tv, 2), $tb); $sh->set($r, 16, round($tt, 2), $tb);
    foreach ([5, 14, 24, 24, 12, 14, 12, 16, 18, 12, 12, 10, 44, 14, 12, 14, 18, 14, 18, 16] as $i => $w) $sh->widths[$i + 1] = $w;
    $sh->freeze = [3, 0]; $sh->filter = 'A3:' . MiniXlsx::col(count($heads)) . max(4, $r - 1);
    /* second sheet: totals per claim type (of the filtered rows) */
    $s2 = $X->sheet('By Claim Type'); $s2->set(1, 1, 'Totals by claim type' . ($fl ? ' — ' . implode(' · ', $fl) : ''), ['b' => 1, 'sz' => 14]);
    foreach (['Claim Type', 'Rows', 'Actual Amount', 'VAT Amount', 'Total Amount'] as $i => $h) $s2->set(3, $i + 1, $h, $H);
    ksort($byType); $r2 = 4; foreach ($byType as $tk => $v) { $s2->set($r2, 1, $tk, ['border' => 1]); $s2->set($r2, 2, $v[0], ['border' => 1, 'h' => 'right']); foreach ([1, 2, 3] as $q) $s2->set($r2, $q + 2, round($v[$q], 2), ['border' => 1, 'h' => 'right', 'nf' => 4]); $r2++; }
    $s2->set($r2, 1, 'TOTAL', ['b' => 1, 'border' => 1, 'bg' => 'FDE68A']); $s2->set($r2, 2, $n, ['b' => 1, 'border' => 1, 'bg' => 'FDE68A', 'h' => 'right']); foreach ([[3, $ta], [4, $tv], [5, $tt]] as [$q, $val]) $s2->set($r2, $q, round($val, 2), $tb);
    foreach ([30, 10, 18, 16, 18] as $i => $w) $s2->widths[$i + 1] = $w; $s2->freeze = [3, 0];
    $tmp = tempnam(sys_get_temp_dir(), 'cc'); $X->save($tmp);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="CC_Report_' . date('Ymd_His') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}

/* ── AJAX: export_report (no pagination — full filtered set) ── */
if (isset($_REQUEST['ajax_action']) && $_REQUEST['ajax_action'] === 'export_report') {
    ob_start(); header('Content-Type: application/json');
    ensure_claim_tables_r($conn);
    $where = cc_build_where($conn);
    $res = mysqli_query($conn, "SELECT ci.*, cu.file_name, cu.upload_date
                                 FROM claim_cert_items ci LEFT JOIN claim_cert_uploads cu ON ci.upload_id = cu.id
                                 $where ORDER BY ci.imported_at DESC LIMIT 50000");
    $rows = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) { [$r['scheme_no'], $r['scheme_src']] = cc_scheme_of($r['claim_description']); $rows[] = $r; }
    ob_end_clean();
    echo json_encode(['success'=>true,'rows'=>$rows]);
    exit;
}

/* ── AJAX: get_filter_options ── */
if (isset($_REQUEST['ajax_action']) && $_REQUEST['ajax_action'] === 'get_filter_options') {
    ob_start(); header('Content-Type: application/json');
    ensure_claim_tables_r($conn);
    $custRes   = mysqli_query($conn, "SELECT DISTINCT customer_code, customer_name FROM claim_cert_items WHERE customer_code<>'' ORDER BY customer_name ASC");
    $entRes    = mysqli_query($conn, "SELECT DISTINCT entity FROM claim_cert_items WHERE entity<>'' ORDER BY entity ASC");
    $statRes   = mysqli_query($conn, "SELECT DISTINCT status FROM claim_cert_items WHERE status<>'' ORDER BY status ASC");
    $typeRes   = mysqli_query($conn, "SELECT DISTINCT claim_type FROM claim_cert_items WHERE claim_type<>'' ORDER BY claim_type ASC");
    $customers = []; while ($r = mysqli_fetch_assoc($custRes)) $customers[] = $r;
    $entities  = []; while ($r = mysqli_fetch_assoc($entRes))  $entities[]  = $r['entity'];
    $statuses  = []; while ($r = mysqli_fetch_assoc($statRes)) $statuses[]  = $r['status'];
    $types     = []; while ($r = mysqli_fetch_assoc($typeRes)) $types[]     = $r['claim_type'];
    ob_end_clean();
    echo json_encode(['success'=>true,'customers'=>$customers,'entities'=>$entities,'statuses'=>$statuses,'claim_types'=>$types]);
    exit;
}

include 'header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
:root{
  --ink:#0f172a;--ink2:#1e293b;--ink3:#334155;--muted:#64748b;
  --lite:#f8fafc;--card:#ffffff;--bdr:#e2e8f0;
  --pri:#1e3a5f;--pri2:#2563eb;--teal:#0d9488;
  --green:#16a34a;--red:#dc2626;--amber:#d97706;--violet:#7c3aed;
  --shadow:0 1px 3px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.05);
}
.ccr-wrap{width:100%;margin:0;padding:20px 12px 80px;font-family:'Segoe UI',system-ui,sans-serif;}

.ccr-hero{background:linear-gradient(135deg,#0f3460 0%,#16213e 50%,#0a1628 100%);border-radius:16px;padding:20px 26px;display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin-bottom:20px;position:relative;overflow:hidden;}
.ccr-hero::before{content:'';position:absolute;top:-40px;right:-60px;width:240px;height:240px;border-radius:50%;background:rgba(99,102,241,.15);pointer-events:none;}
.hero-icon{width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;flex-shrink:0;border:1px solid rgba(255,255,255,.18);}
.hero-title{color:#fff;font-size:20px;font-weight:800;line-height:1.2;}
.hero-sub{color:rgba(255,255,255,.65);font-size:12px;margin-top:3px;}
.hero-right{margin-left:auto;display:flex;align-items:center;gap:10px;flex-wrap:wrap;position:relative;z-index:1;}

.kpi-strip{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:16px;}
@media(max-width:980px){.kpi-strip{grid-template-columns:repeat(3,1fr);}}
@media(max-width:520px){.kpi-strip{grid-template-columns:repeat(2,1fr);}}
.kpi-mini{background:var(--card);border:1.5px solid var(--bdr);border-radius:12px;padding:12px 14px;box-shadow:var(--shadow);}
.kpi-mini .km-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:4px;}
.kpi-mini .km-val{font-size:19px;font-weight:900;color:var(--ink);}
.kpi-mini .km-val.green{color:var(--green);}
.kpi-mini .km-val.amber{color:var(--amber);}
.kpi-mini .km-val.violet{color:var(--violet);}
.kpi-mini .km-val.sky{color:#0284c7;}

.rc-card{background:var(--card);border:1.5px solid var(--bdr);border-radius:14px;box-shadow:var(--shadow);overflow:hidden;}
.rc-card-hdr{padding:14px 20px;border-bottom:1.5px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:linear-gradient(to right,#fafbff,#fff);}
.rc-card-title{font-size:14px;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:8px;}
.rc-card-body{padding:16px 18px;}

.filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:12px;}
.f-field{display:flex;flex-direction:column;gap:4px;}
.f-field label{font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;}
.f-field input,.f-field select{border:1.5px solid var(--bdr);border-radius:8px;padding:8px 10px;font-size:12.5px;font-family:inherit;outline:none;color:var(--ink);background:#fff;}
.f-field input:focus,.f-field select:focus{border-color:var(--pri2);}
.ms{position:relative}.ms-btn{width:100%;text-align:left;border:1.5px solid var(--bdr);border-radius:8px;padding:8px 10px;font-size:12.5px;font-family:inherit;background:#fff;color:var(--ink);cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ms-panel{display:none;position:fixed;z-index:2147483000;max-height:300px;overflow:auto;background:#fff;border:1.5px solid var(--bdr);border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,.15);padding:6px;margin-top:3px}.ms.open .ms-panel{display:block}
.ms-panel label{display:flex;gap:7px;align-items:center;padding:5px 7px;border-radius:6px;font-size:12.5px;text-transform:none;letter-spacing:0;font-weight:500;color:var(--ink);cursor:pointer;white-space:nowrap}.ms-panel label:hover{background:#eef2ff}.ms-panel .ms-clear{font-size:11px;color:#6366f1;cursor:pointer;padding:3px 7px;display:block}
.filter-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}

.btn-primary{display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,#4f46e5,#6366f1);color:#fff;border:none;border-radius:9px;padding:9px 18px;font-size:12.5px;font-weight:800;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(99,102,241,.3);}
.btn-primary:hover{filter:brightness(1.08);}
.btn-secondary{background:var(--lite);border:1.5px solid var(--bdr);border-radius:9px;padding:8px 16px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit;color:var(--ink3);}
.btn-secondary:hover{background:#e2e8f0;}
.btn-success{display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,#15803d,var(--green));color:#fff;border:none;border-radius:9px;padding:9px 18px;font-size:12.5px;font-weight:800;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(22,163,74,.3);}
.btn-success:hover{filter:brightness(1.08);}

.prev-outer{border:1.5px solid var(--bdr);border-radius:10px;overflow:hidden;}
.prev-scroll{overflow-x:auto;max-height:600px;overflow-y:auto;}
.prev-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1300px;}
.prev-table thead th{padding:9px 11px;background:#0f172a;color:#e2e8f0;font-size:10.5px;font-weight:700;text-align:left;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);position:sticky;top:0;z-index:5;cursor:pointer;user-select:none;}
.prev-table thead th.tr{text-align:right;}
.prev-table thead th:hover{background:#1e293b;}
.prev-table thead th .sort-ic{opacity:.5;font-size:9px;margin-left:3px;}
.prev-table tbody tr{border-bottom:1px solid #f1f5f9;}
.prev-table tbody tr:hover td{background:#f8faff !important;}
.prev-table td{padding:8px 11px;vertical-align:middle;background:#fff;}
.tr{text-align:right;}
.tc{text-align:center;}

.pill{display:inline-flex;align-items:center;gap:3px;padding:2px 9px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}
.p-blue{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;}
.p-green{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.p-red{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
.p-violet{background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe;}
.p-gray{background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;}
.p-teal{background:#ccfbf1;color:#065f46;border:1px solid #5eead4;}
.p-amber{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}

.pager{display:flex;align-items:center;gap:10px;justify-content:flex-end;padding:12px 16px;flex-wrap:wrap;}
.pager select{border:1.5px solid var(--bdr);border-radius:7px;padding:6px 10px;font-size:12px;font-family:inherit;}
.pager button{background:#fff;border:1.5px solid var(--bdr);border-radius:7px;padding:6px 12px;font-size:12px;font-weight:700;cursor:pointer;color:var(--ink3);}
.pager button:disabled{opacity:.4;cursor:not-allowed;}
.pager button:hover:not(:disabled){background:#f1f5f9;}
.pager .pg-info{font-size:12px;color:var(--muted);font-weight:600;}

#toast{position:fixed;bottom:30px;right:26px;background:#166534;color:#fff;padding:12px 22px;border-radius:12px;font-size:13px;font-weight:700;z-index:9999;opacity:0;pointer-events:none;transition:opacity .3s;max-width:360px;}
#toast.show{opacity:1;}
#toast.err{background:var(--red);}
</style>

<div class="ccr-wrap">
  <div class="ccr-hero">
    <div class="hero-icon"><i class="fa-solid fa-chart-line"></i></div>
    <div style="position:relative;z-index:1;">
      <div class="hero-title">Customer Claim Report <span style="font-weight:500;opacity:.7;">(CC Report)</span></div>
      <div class="hero-sub">All imported claim certificate data · Date filters · Search · Export to Excel</div>
    </div>
    <div class="hero-right">
      <button class="btn-success" onclick="exportReport()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
    </div>
  </div>

  <div class="kpi-strip">
    <div class="kpi-mini"><div class="km-lbl">Records</div><div class="km-val sky" id="kpiCount">—</div></div>
    <div class="kpi-mini"><div class="km-lbl">Customers</div><div class="km-val violet" id="kpiCust">—</div></div>
    <div class="kpi-mini"><div class="km-lbl">Uploads</div><div class="km-val" id="kpiUploads">—</div></div>
    <div class="kpi-mini"><div class="km-lbl">Actual Amt</div><div class="km-val green" id="kpiActual">—</div></div>
    <div class="kpi-mini"><div class="km-lbl">VAT Amt</div><div class="km-val amber" id="kpiVat">—</div></div>
    <div class="kpi-mini"><div class="km-lbl">Total Amt</div><div class="km-val" id="kpiTotal">—</div></div>
  </div>

  <div class="rc-card" style="margin-bottom:16px;">
    <div class="rc-card-hdr">
      <div class="rc-card-title"><i class="fa-solid fa-filter"></i> Filters</div>
      <div class="filter-actions">
        <button class="btn-secondary" onclick="clearFilters()"><i class="fa-solid fa-rotate-left"></i> Clear</button>
        <button class="btn-primary" onclick="loadReport(1)"><i class="fa-solid fa-magnifying-glass"></i> Apply</button>
      </div>
    </div>
    <div class="rc-card-body">
      <div class="filter-grid">
        <div class="f-field">
          <label>Date Field</label>
          <select id="fDateField">
            <option value="imported_at">Import Date</option>
            <option value="invoice_date">Invoice Date</option>
            <option value="banking_date">Banking Date</option>
            <option value="upload_date">Upload Date</option>
          </select>
        </div>
        <div class="f-field"><label>From</label><input type="date" id="fFrom"></div>
        <div class="f-field"><label>To</label><input type="date" id="fTo"></div>
        <div class="f-field" style="grid-column:span 2;">
          <label>Search</label>
          <input type="text" id="fSearch" placeholder="Tax invoice, description, customer, file name…">
        </div>
        <div class="f-field">
          <label>Amount</label>
          <div style="display:flex;gap:6px"><input type="text" id="fAmount" inputmode="decimal" placeholder="12500 · 1000-5000 · >100000" title="One amount (±0.50), a range 10000-50000, or >100000 / <5000" style="flex:1;min-width:0">
            <select id="fAmountIn" title="Which amount" style="width:92px"><option value="any">Any</option><option value="actual">Actual</option><option value="vat">VAT</option><option value="total">Total</option></select></div>
        </div>
        <div class="f-field">
          <label>Customer</label>
          <select id="fCustomer"><option value="">All Customers</option></select>
        </div>
        <div class="f-field">
          <label>Entity</label>
          <select id="fEntity"><option value="">All Entities</option></select>
        </div>
        <div class="f-field">
          <label>Status</label>
          <select id="fStatus"><option value="">All Statuses</option></select>
        </div>
        <div class="f-field">
          <label>Claim Type</label>
          <div class="ms" id="fClaimType"><button type="button" class="ms-btn" id="ctBtn">All types ▾</button><div class="ms-panel" id="ctPanel"></div></div>
        </div>
      </div>
    </div>
  </div>

  <div class="rc-card">
    <div class="rc-card-hdr">
      <div class="rc-card-title"><i class="fa-solid fa-table-list"></i> Claim Records</div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><select id="svDest" title="Where to send the selected claims" style="border:1.5px solid #5eead4;border-radius:9px;padding:8px 10px;font-size:12.5px;font-weight:700;color:#134e4a"><option value="sscl_vat">SSCL &amp; VAT</option><option value="muthupalasa">Muthupalasa BP Claims</option><option value="promoters">Promoters Salary</option><option value="sr_incentive">SR Incentive</option><option value="dal">Drivers &amp; Loyalty</option><option value="others">Others</option></select><button class="btn-primary" id="svBulk" type="button" data-perm-btn="Send selected" onclick="svList(svPicked(),'bulk',document.getElementById('svDest').value)" disabled style="background:linear-gradient(135deg,#0f766e,#0d9488)"><i class="fa-solid fa-paper-plane"></i> Send selected <span id="svBulkN"></span></button>
      <div style="font-size:11.5px;color:var(--muted);" id="resultInfo"></div></div>
    </div>
    <div class="prev-outer">
      <div class="prev-scroll">
        <table class="prev-table">
          <thead>
            <tr>
              <th style="cursor:default" title="Select claims to send"><input type="checkbox" id="svAll"></th>
              <th>#</th>
              <th onclick="setSort('customer_code')">Customer <span class="sort-ic" id="si-customer_code"></span></th>
              <th>File / Upload</th>
              <th>Ledger Type</th><th>Status</th><th>Claim Type</th>
              <th onclick="setSort('tax_invoice_no')">Tax Invoice No. <span class="sort-ic" id="si-tax_invoice_no"></span></th>
              <th onclick="setSort('invoice_date')">Invoice Date <span class="sort-ic" id="si-invoice_date"></span></th>
              <th onclick="setSort('banking_date')">Banking Date <span class="sort-ic" id="si-banking_date"></span></th>
              <th>Entity</th>
              <th>Claim Description</th>
              <th class="tr" onclick="setSort('actual_amount')">Actual Amt <span class="sort-ic" id="si-actual_amount"></span></th>
              <th class="tr" onclick="setSort('vat_amount')">VAT Amt <span class="sort-ic" id="si-vat_amount"></span></th>
              <th class="tr" onclick="setSort('total_amount')">Total <span class="sort-ic" id="si-total_amount"></span></th>
              <th onclick="setSort('imported_at')">Imported At <span class="sort-ic" id="si-imported_at"></span></th>
              <th title="F = only the first number of the description · M = middle number / normal" style="text-align:center">F / M</th>
              <th>Scheme No</th>
              <th style="cursor:default">Send to claim page</th>
            </tr>
          </thead>
          <tbody id="repBody">
            <tr><td colspan="18" style="padding:40px;text-align:center;color:var(--muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:8px;"></i>Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="pager">
      <span class="pg-info" id="pgInfo"></span>
      <select id="perPageSel" onchange="loadReport(1)">
        <option value="50">50 / page</option>
        <option value="100" selected>100 / page</option>
        <option value="250">250 / page</option>
        <option value="500">500 / page</option>
      </select>
      <button id="pgFirst" onclick="loadReport(1)"><i class="fa-solid fa-angles-left"></i></button>
      <button id="pgPrev" onclick="loadReport(_curPage-1)"><i class="fa-solid fa-angle-left"></i></button>
      <span class="pg-info" id="pgPageLbl">Page 1</span>
      <button id="pgNext" onclick="loadReport(_curPage+1)"><i class="fa-solid fa-angle-right"></i></button>
      <button id="pgLast" onclick="loadReport(_totalPages)"><i class="fa-solid fa-angles-right"></i></button>
    </div>
  </div>
</div>

<div id="toast"></div>
<div id="stlM" class="stl-bg" onclick="if(event.target===this)this.classList.remove('open')"><div class="stl-box"><div class="stl-hd"><div><b id="stlT">Settlements</b><div id="stlS" style="font-size:12px;opacity:.85"></div></div><button type="button" onclick="document.getElementById('stlM').classList.remove('open')">&times;</button></div><div class="stl-bd" id="stlB"></div></div></div>

<style>
/*m*/.svm{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;z-index:9000;padding:14px}.svm.open{display:flex}
.svm-box{font-family:'Segoe UI',system-ui,sans-serif;background:#fff;border-radius:16px;width:min(1180px,100%);max-height:94vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.35)}
.svm-hd{background:linear-gradient(120deg,#134e4a,#0f766e 55%,#14b8a6);color:#fff;padding:14px 18px;display:flex;gap:12px;align-items:flex-start;justify-content:space-between}.svm-hd button{background:rgba(255,255,255,.18);border:none;color:#fff;font-size:22px;width:36px;height:36px;border-radius:10px;cursor:pointer}
.svm-bd{padding:14px 18px;overflow:auto;display:flex;flex-direction:column;gap:14px}.svm-t{font-weight:800;font-size:13px;color:#134e4a;margin-bottom:6px}
.svt{width:100%;border-collapse:collapse;font-size:12px}.svt th{background:#0f172a;color:#e2e8f0;padding:7px 9px;text-align:left;font-size:10.5px;position:sticky;top:0}.svt th.tr,.svt td.tr{text-align:right}.svt td{padding:6px 9px;border-bottom:1px solid #f1f5f9}
.svt tr.pk td{background:#ccfbf1!important}.svt tbody tr.pay{cursor:pointer}.svt tbody tr.pay:hover td{background:#f0fdfa}.svt input.amt{width:120px;text-align:right;border:1.5px solid #5eead4;border-radius:7px;padding:5px 7px;font:inherit;font-weight:700}
.prev-table input[type=checkbox],#svAll{appearance:auto!important;-webkit-appearance:checkbox!important;display:inline-block!important;opacity:1!important;visibility:visible!important;position:static!important;width:16px!important;height:16px!important;margin:0!important;cursor:pointer;accent-color:#0d9488}
.sv-menu{position:fixed;z-index:9500;background:#fff;border:1.5px solid #99f6e4;border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.18);padding:5px;display:flex;flex-direction:column;min-width:220px}
.sv-menu button{display:flex;gap:8px;align-items:center;border:none;background:none;text-align:left;padding:8px 10px;border-radius:7px;font:700 12.5px 'Segoe UI',system-ui,sans-serif;color:#134e4a;cursor:pointer}.sv-menu button:hover{background:#f0fdfa}.sv-menu small{font-weight:500;color:#94a3b8}
.stl-bg{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;z-index:9000;padding:14px}.stl-bg.open{display:flex}
.stl-box{background:#fff;border-radius:16px;width:min(1100px,100%);max-height:90vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.35);font-family:'Segoe UI',system-ui,sans-serif}
.stl-hd{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:14px 18px;color:#fff;background:linear-gradient(120deg,#1e3a5f,#2563eb)}.stl-hd b{font-size:16px}.stl-hd button{background:rgba(255,255,255,.18);border:none;color:#fff;font-size:22px;width:36px;height:36px;border-radius:10px;cursor:pointer}
.stl-bd{padding:14px 18px;overflow:auto}.stl-k{display:flex;gap:16px;flex-wrap:wrap;font-size:13px;margin-bottom:10px}.stl-k span{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:7px 11px}
.stl-t{width:100%;border-collapse:collapse;font-size:12.5px}.stl-t th{background:#0f172a;color:#e2e8f0;padding:8px 9px;text-align:left;font-size:11px;white-space:nowrap}.stl-t td{padding:8px 9px;border-bottom:1px solid #f1f5f9;vertical-align:top}.stl-t .tr{text-align:right}
.stl-del{border:1.5px solid #fca5a5;background:#fff;color:#b91c1c;border-radius:8px;padding:4px 10px;font-weight:800;font-size:11.5px;cursor:pointer;white-space:nowrap}.stl-del:hover{background:#fef2f2}
.sv-stl{display:inline-flex;gap:5px;align-items:center;border:1.5px solid #bfdbfe;background:#eff6ff;color:#1e40af;border-radius:8px;padding:3px 9px;font-size:11px;font-weight:800;cursor:pointer;white-space:nowrap}.sv-stl:hover{background:#dbeafe}
#repBody tr.cc-row{cursor:pointer}
.sv-rev{display:inline-flex;gap:4px;align-items:center;border:1.5px solid #fca5a5;background:#fff;color:#b91c1c;border-radius:7px;padding:2px 8px;font-size:10.5px;font-weight:800;cursor:pointer}.sv-rev:hover{background:#fef2f2}
.sv-btn.o{background:linear-gradient(135deg,#64748b,#94a3b8)}
.sv-btn{display:inline-flex;gap:5px;align-items:center;border:none;border-radius:8px;padding:5px 10px;font-size:11px;font-weight:800;cursor:pointer;background:linear-gradient(135deg,#0f766e,#0d9488);color:#fff;white-space:nowrap}.sv-btn:hover{filter:brightness(1.1)}
</style>
<script>
let SVROWS = {};
const SVD = {sscl_vat:'SSCL & VAT', muthupalasa:'Muthupalasa BP Claims', promoters:'Promoters Salary', sr_incentive:'SR Incentive', dal:'Drivers & Loyalty', others:'Others'};
const SVI = {sscl_vat:'fa-receipt', muthupalasa:'fa-file-invoice', promoters:'fa-user-tie', sr_incentive:'fa-trophy', dal:'fa-truck', others:'fa-layer-group'};
function svFull(r){ return parseFloat(r.sv_settled||0)>0 && parseFloat(r.total_amount||0)-parseFloat(r.sv_settled||0)<=0.004; }
function svCanSend(r){ return !r.sv_dest && !svFull(r); }
function svCell(r){
  let h='';
  if((r.sv_pays||[]).length) h+='<button type="button" class="sv-stl" onclick="event.stopPropagation();svStl('+r.id+')" title="Show the payments this claim is settled on — delete one here"><i class="fa-solid fa-list"></i> Settlements ('+(r.sv_pays||[]).length+')</button> ';
  (r.sv_pays||[]).forEach(p=>{ const pg=esc(SVD[p.d]||p.d); h+='<span class="pill '+(svFull(r)?'p-green':'p-amber')+'" title="Matched on '+pg+' payment #'+p.id+' — to undo, remove that payment line on the '+pg+' page">'+(svFull(r)?'<i class="fa-solid fa-circle-check"></i> Matched':'Partly matched')+' · '+pg+' #'+p.id+(p.pd?' ('+esc(p.pd)+')':'')+' · '+fmtN(p.amt)+'</span> '; });
  if(r.sv_ack>0) h+='<span class="pill p-blue">Ack #'+r.sv_ack+'</span> ';
  if(r.sv_dest && !svFull(r)) h+='<span class="pill p-teal" title="Sent '+esc(r.sv_listed)+' — pick it on the '+esc(SVD[r.sv_dest]||r.sv_dest)+' page when entering the payment"><i class="fa-solid fa-list-check"></i> In '+esc(SVD[r.sv_dest]||r.sv_dest)+' list</span> '
     +((r.sv_pays||[]).length||r.sv_ack>0?'':'<button type="button" class="sv-rev" onclick="svUnlist('+r.id+',\''+esc(r.tax_invoice_no||'')+'\')" title="Take this claim off the list"><i class="fa-solid fa-rotate-left"></i> Reverse</button>');
  if(svCanSend(r)) h+='<span class="sv-dd"><button type="button" class="sv-btn" onclick="svMenu(this,'+r.id+')"><i class="fa-solid fa-paper-plane"></i> Send to ▾</button></span>';
  return h;
}
/* the three choices are real buttons, so each one can be ticked / unticked on the role form */
function svMenu(btn,id){ document.querySelectorAll('.sv-menu').forEach(m=>m.remove()); const r=SVROWS[id], m=document.createElement('div'); m.className='sv-menu';
  m.innerHTML=Object.keys(SVD).map(d=>'<button type="button" data-perm-btn="Send to '+esc(SVD[d])+'" onclick="svList(['+id+'],\'row\',\''+d+'\')"><i class="fa-solid '+SVI[d]+'"></i> Send to '+esc(SVD[d])+(d==='sscl_vat'&&r&&r.sv_tax!=1?' <small>(not an SSCL / VAT type)</small>':'')+'</button>').join('');
  /* fixed labels for the role form's button list: data-perm-btn="Send to SSCL & VAT" data-perm-btn="Send to Muthupalasa BP Claims" data-perm-btn="Send to Promoters Salary" data-perm-btn="Send to SR Incentive" data-perm-btn="Send to Drivers & Loyalty" data-perm-btn="Send to Others" */
  document.body.appendChild(m); const b=btn.getBoundingClientRect(); m.style.top=(b.bottom+4)+'px'; m.style.left=Math.max(8,Math.min(b.left,window.innerWidth-m.offsetWidth-8))+'px'; }
document.addEventListener('click',e=>{ if(!e.target.closest('.sv-menu,.sv-dd')) document.querySelectorAll('.sv-menu').forEach(m=>m.remove()); });
window.addEventListener('scroll',()=>document.querySelectorAll('.sv-menu').forEach(m=>m.remove()),true);
function svPicked(){ return [].slice.call(document.querySelectorAll('.svChk:checked')).map(c=>+c.value); }
function svSync(){ const n=svPicked().length; document.getElementById('svBulk').disabled=!n; document.getElementById('svBulkN').textContent=n?'('+n+')':''; const all=document.querySelectorAll('.svChk'); document.getElementById('svAll').checked=all.length>0&&n===all.length; }
document.getElementById('svAll').addEventListener('change',e=>{ document.querySelectorAll('.svChk').forEach(c=>c.checked=e.target.checked); svSync(); });
document.getElementById('repBody').addEventListener('change',e=>{ if(e.target.classList.contains('svChk')) svSync(); });
async function svPost(data){ const fd=new FormData(); Object.keys(data).forEach(k=>fd.append(k,data[k])); const d=await (await fetch('customer_claim_report.php',{method:'POST',body:fd})).json(); if(!d.success) throw new Error(d.message); return d; }
async function svList(ids,via,dest){
  document.querySelectorAll('.sv-menu').forEach(m=>m.remove()); if(!ids.length) return; dest=dest||'sscl_vat';
  const other=dest==='sscl_vat'?ids.filter(id=>SVROWS[id]&&SVROWS[id].sv_tax!=1).length:0;
  if(!confirm('Send '+ids.length+' claim(s) to the '+SVD[dest]+' list?'+(other?'\n\n'+other+' of them are not SSCL / VAT claim types.':'')+'\n\nThey will appear on the '+SVD[dest]+' page to pick when you enter the payment.')) return;
  try{ const d=await svPost({ajax_action:'sv_list',ids:JSON.stringify(ids),via:via||'row',dest:dest}); showToast(d.message); loadReport(_curPage); }catch(e){ showToast(e.message,'err'); } }
/* settlements window */
function svStl(id){ const r=SVROWS[id]||{}; const m=document.getElementById('stlM'); m.classList.add('open');
  document.getElementById('stlT').textContent='Settlements — '+(r.tax_invoice_no||('#'+id)); document.getElementById('stlS').textContent=(r.customer_code||'')+' '+(r.customer_name||'')+' · '+(r.claim_type||'');
  document.getElementById('stlB').innerHTML='<div style="padding:24px;text-align:center;color:#64748b"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
  fetch('customer_claim_report.php?ajax_action=sv_settlements&id='+id,{credentials:'same-origin'}).then(x=>x.json()).then(d=>{ if(!d.success) throw new Error(d.message||'Load failed'); stlDraw(id,d); }).catch(e=>{ document.getElementById('stlB').innerHTML='<div style="padding:18px;color:#dc2626">'+esc(e.message)+'</div>'; }); }
function stlDraw(id,d){ const c=d.claim||{}, L=d.rows||[], tot=L.reduce((a,x)=>a+x.tot,0), ct=parseFloat(c.total_amount||0);
  let h='<div class="stl-k"><span>Claim total <b>'+fmtN(ct)+'</b></span><span>Actual '+fmtN(c.actual_amount)+' · VAT '+fmtN(c.vat_amount)+'</span><span>Settled <b style="color:#15803d">'+fmtN(tot)+'</b></span><span>Balance <b style="color:'+(ct-tot>0.004?'#b91c1c':'#15803d')+'">'+fmtN(ct-tot)+'</b></span></div>';
  if(c.claim_description) h+='<div style="font-size:12px;color:#475569;margin:-4px 0 10px">'+esc(c.claim_description)+'</div>';
  h+=L.length?'<div style="overflow:auto"><table class="stl-t"><thead><tr><th>Page</th><th>Payment</th><th>Tax invoice</th><th>Banking date</th><th>Bank ref</th><th class="tr">Amount</th><th class="tr">VAT</th><th class="tr">Total</th><th>Added</th><th></th></tr></thead><tbody>'
    +L.map(x=>'<tr><td><b>'+esc(x.pn)+'</b></td><td><a href="'+esc(x.url)+'" target="_blank">#'+x.rid+'</a>'+(x.pd?' <small>'+esc(x.pd)+'</small>':'')+(x.pdesc?'<div style="font-size:11px;color:#64748b;max-width:220px;white-space:normal">'+esc(x.pdesc)+'</div>':'')+'</td><td>'+esc(x.ti||'—')+'</td><td>'+esc(x.bd||'—')+'</td><td>'+esc(x.ref||'—')+'</td><td class="tr">'+fmtN(x.amt)+'</td><td class="tr">'+fmtN(x.vat)+'</td><td class="tr"><b>'+fmtN(x.tot)+'</b></td><td style="font-size:11px;color:#64748b">'+esc(x.at||'')+'</td><td>'
      +(x.can?'<button type="button" class="stl-del" data-perm-btn="Delete" onclick="stlDel('+id+',\''+x.page+'\','+x.id+','+x.ack+','+x.tot+',\''+esc(x.pn).replace(/'/g,'')+'\')"><i class="fa-solid fa-trash"></i> Delete</button>':(x.page==='dal'?'<small style="color:#94a3b8">change on its page</small>':'<small style="color:#94a3b8">no right</small>'))+(x.ack?' <span class="pill p-blue" title="Also in Acknowledgment">Ack</span>':'')+'</td></tr>').join('')+'</tbody></table></div>'
    :'<div style="padding:22px;text-align:center;color:#64748b">This claim is not settled on any payment yet.</div>';
  document.getElementById('stlB').innerHTML=h; }
async function stlDel(id,page,sid,ack,tot,pn){
  if(!confirm('Delete this settlement?\n\n'+fmtN(tot)+' on '+pn+' will be removed'+(ack?' — and its Acknowledgment entry too':'')+'.\nThe claim becomes unsettled for this amount.')) return;
  try{ const d=await svPost({ajax_action:'sv_settle_delete',page:page,sid:sid}); showToast(d.message); svStl(id); loadReport(_curPage); }catch(e){ showToast(e.message,'err'); } }
document.getElementById('repBody').addEventListener('click',e=>{ if(e.target.closest('button,a,input,label,select,.sv-menu')) return; const tr=e.target.closest('tr.cc-row'); if(tr) svStl(+tr.dataset.id); });
async function svUnlist(id,ti){
  const r=SVROWS[id]; if(!confirm('Reverse — take claim '+ti+' off the '+(SVD[r&&r.sv_dest]||'')+' list?')) return;
  try{ const d=await svPost({ajax_action:'sv_unlist',id:id}); showToast(d.message); loadReport(_curPage); }catch(e){ showToast(e.message,'err'); } }
</script>

<script>
let _curPage = 1, _totalPages = 1, _sortField = 'imported_at', _sortDir = 'DESC';
let _searchDebounce = null;

function esc(s){ if(s==null) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
function fmtN(v){ return parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtMoney(v){ return 'Rs. ' + fmtN(v); }
function showToast(msg,type){
  const t=document.getElementById('toast');
  t.className = type==='err' ? 'err' : '';
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(t._t);
  t._t=setTimeout(()=>t.classList.remove('show'),3500);
}

/* ── Load filter dropdown options ── */
async function loadFilterOptions(){
  try{
    const res = await fetch('customer_claim_report.php?ajax_action=get_filter_options');
    const d = await res.json();
    if(!d.success) return;
    const custSel = document.getElementById('fCustomer');
    d.customers.forEach(c=>{
      const opt = document.createElement('option');
      opt.value = c.customer_code;
      opt.textContent = c.customer_code + (c.customer_name ? ' — '+c.customer_name : '');
      custSel.appendChild(opt);
    });
    const entSel = document.getElementById('fEntity');
    d.entities.forEach(e=>{
      const opt = document.createElement('option'); opt.value = e; opt.textContent = e; entSel.appendChild(opt);
    });
    const stSel = document.getElementById('fStatus');
    d.statuses.forEach(s=>{
      const opt = document.createElement('option'); opt.value = s; opt.textContent = s; stSel.appendChild(opt);
    });
    document.getElementById('ctPanel').innerHTML = '<a class="ms-clear" id="ctClear">Clear selection</a>' + d.claim_types.map(t=>'<label><input type="checkbox" class="ctChk" value="'+esc(t)+'"> '+esc(t)+'</label>').join('');
    document.getElementById('ctClear').addEventListener('click', ()=>{ document.querySelectorAll('.ctChk').forEach(c=>c.checked=false); ctUpdate(); loadReport(1); });
    document.querySelectorAll('.ctChk').forEach(c=>c.addEventListener('change', ()=>{ ctUpdate(); loadReport(1); }));
  }catch(e){ /* silent */ }
}

function ctSelected(){ return [].slice.call(document.querySelectorAll('.ctChk:checked')).map(c=>c.value); }
function ctUpdate(){ const s=ctSelected(); document.getElementById('ctBtn').textContent = !s.length ? 'All types ▾' : (s.length<=2 ? s.join(', ') : s.length+' types selected')+' ▾'; }
function ctPlace(){ const b=document.getElementById('ctBtn').getBoundingClientRect(), p=document.getElementById('ctPanel');   // fixed → never clipped by the filter card
  p.style.left=Math.max(8,Math.min(b.left,window.innerWidth-Math.max(b.width,240)-8))+'px'; p.style.minWidth=Math.max(b.width,240)+'px';
  const below=window.innerHeight-b.bottom-12; if(below<180 && b.top>below){ p.style.top='auto'; p.style.bottom=(window.innerHeight-b.top+4)+'px'; p.style.maxHeight=Math.min(300,b.top-12)+'px'; } else { p.style.bottom='auto'; p.style.top=(b.bottom+4)+'px'; p.style.maxHeight=Math.min(300,below)+'px'; } }
document.getElementById('ctBtn').addEventListener('click', e=>{ e.stopPropagation(); const w=document.getElementById('fClaimType'); w.classList.toggle('open'); if(w.classList.contains('open')) ctPlace(); });
window.addEventListener('resize', ()=>document.getElementById('fClaimType').classList.remove('open'));
window.addEventListener('scroll', e=>{ if(e.target && e.target.nodeType===1 && e.target.closest('#ctPanel')) return; document.getElementById('fClaimType').classList.remove('open'); }, true);
document.addEventListener('click', e=>{ if(!e.target.closest('#fClaimType')) document.getElementById('fClaimType').classList.remove('open'); });
function getFilterParams(){
  return {
    date_field: document.getElementById('fDateField').value,
    date_from:  document.getElementById('fFrom').value,
    date_to:    document.getElementById('fTo').value,
    search:     document.getElementById('fSearch').value.trim(),
    amount:     document.getElementById('fAmount').value.trim(),
    amount_in:  document.getElementById('fAmountIn').value,
    customer_code: document.getElementById('fCustomer').value,
    entity:     document.getElementById('fEntity').value,
    status:     document.getElementById('fStatus').value,
    claim_type: ctSelected().join('||'),
  };
}

function clearFilters(){
  document.getElementById('fDateField').value = 'imported_at';
  document.getElementById('fFrom').value = '';
  document.getElementById('fTo').value = '';
  document.getElementById('fSearch').value = '';
  document.getElementById('fAmount').value = ''; document.getElementById('fAmountIn').value = 'any';
  document.getElementById('fCustomer').value = '';
  document.getElementById('fEntity').value = '';
  document.getElementById('fStatus').value = '';
  document.querySelectorAll('.ctChk').forEach(c=>c.checked=false); ctUpdate();
  loadReport(1);
}

function setSort(field){
  if(_sortField === field){ _sortDir = _sortDir === 'ASC' ? 'DESC' : 'ASC'; }
  else { _sortField = field; _sortDir = 'DESC'; }
  document.querySelectorAll('.sort-ic').forEach(el=>el.innerHTML='');
  const ic = document.getElementById('si-'+field);
  if(ic) ic.innerHTML = _sortDir === 'ASC' ? '<i class="fa-solid fa-arrow-up"></i>' : '<i class="fa-solid fa-arrow-down"></i>';
  loadReport(1);
}

const statusBadge = s => {
  if (!s) return '<span style="color:var(--muted)">—</span>';
  if (s === 'Completed')  return '<span class="pill p-green">' + esc(s) + '</span>';
  if (s === 'Pending' || s === 'In Progress') return '<span class="pill p-amber">' + esc(s) + '</span>';
  return '<span class="pill p-gray">' + esc(s) + '</span>';
};
const typePill = t => {
  if (!t) return '—';
  if (t.includes('Damages')) return '<span class="pill p-red">' + esc(t) + '</span>';
  if (t.includes('Drive') || t.includes('Loyalty')) return '<span class="pill p-violet">' + esc(t) + '</span>';
  return '<span class="pill p-teal">' + esc(t) + '</span>';
};

async function loadReport(page){
  page = Math.max(1, page || 1);
  _curPage = page;
  document.getElementById('repBody').innerHTML =
    '<tr><td colspan="18" style="padding:40px;text-align:center;color:var(--muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:8px;"></i>Loading…</td></tr>';

  const params = getFilterParams();
  const per = document.getElementById('perPageSel').value;
  const qs = new URLSearchParams({
    ajax_action: 'get_report_data',
    page: page, per_page: per, sort: _sortField, dir: _sortDir,
    ...params
  });

  try{
    const res = await fetch('customer_claim_report.php?' + qs.toString());
    const d = await res.json();
    if(!d.success) throw new Error('Load failed');

    _totalPages = d.pages;
    document.getElementById('kpiCount').textContent   = d.summary.c;
    document.getElementById('kpiCust').textContent    = d.summary.cust_n;
    document.getElementById('kpiUploads').textContent = d.summary.upload_n;
    document.getElementById('kpiActual').textContent  = fmtMoney(d.summary.sa);
    document.getElementById('kpiVat').textContent      = fmtMoney(d.summary.sv);
    document.getElementById('kpiTotal').textContent    = fmtMoney(d.summary.st);

    document.getElementById('resultInfo').textContent = d.total + ' record(s) found';
    document.getElementById('pgInfo').textContent = 'Showing ' + ((page-1)*per + (d.rows.length?1:0)) + '–' + ((page-1)*per + d.rows.length) + ' of ' + d.total;
    document.getElementById('pgPageLbl').textContent = 'Page ' + page + ' of ' + _totalPages;
    document.getElementById('pgFirst').disabled = page <= 1;
    document.getElementById('pgPrev').disabled  = page <= 1;
    document.getElementById('pgNext').disabled  = page >= _totalPages;
    document.getElementById('pgLast').disabled  = page >= _totalPages;

    if(!d.rows.length){
      document.getElementById('repBody').innerHTML =
        '<tr><td colspan="18" style="padding:34px;text-align:center;color:var(--muted);">No records match the current filters.</td></tr>';
      return;
    }

    let h = '';
    d.rows.forEach((r, i) => {
      SVROWS[r.id] = r;
      h += '<tr data-id="' + r.id + '" class="cc-row" title="Click to see the settlements of this claim">' +
        '<td style="text-align:center">' + (svCanSend(r) ? '<input type="checkbox" class="svChk" value="' + r.id + '" title="Select to send to SSCL &amp; VAT">' : '<i class="fa-solid fa-circle-check" style="color:#16a34a" title="Already matched on SSCL &amp; VAT"></i>') + '</td>' +
        '<td style="font-family:monospace;font-size:11px;color:var(--muted);">' + ((( _curPage-1)*per)+i+1) + '</td>' +
        '<td><span class="pill p-violet">' + esc(r.customer_code||'—') + '</span> <span style="font-size:11px;color:var(--muted);">' + esc(r.customer_name||'') + '</span></td>' +
        '<td style="font-size:11px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(r.file_name||'') + '">' + esc(r.file_name||'—') + '<br><span style="color:var(--muted);">' + esc(r.upload_date||'') + '</span></td>' +
        '<td><span class="pill p-blue">' + esc(r.ledger_type||'—') + '</span></td>' +
        '<td>' + statusBadge(r.status) + '</td>' +
        '<td>' + typePill(r.claim_type) + '</td>' +
        '<td style="font-family:monospace;font-size:12px;font-weight:700;color:#312e81;">' + esc(r.tax_invoice_no) + '</td>' +
        '<td style="font-size:11.5px;">' + esc(r.invoice_date||'—') + '</td>' +
        '<td style="font-size:11.5px;">' + esc(r.banking_date||'—') + '</td>' +
        '<td><span class="pill p-gray">' + esc(r.entity||'—') + '</span></td>' +
        '<td style="font-size:11.5px;min-width:240px;max-width:340px;white-space:normal;word-break:break-word;line-height:1.4;">' + esc(r.claim_description||'') + '</td>' +
        '<td class="tr" style="font-weight:700;">' + fmtN(r.actual_amount) + '</td>' +
        '<td class="tr" style="color:var(--muted);">' + fmtN(r.vat_amount) + '</td>' +
        '<td class="tr" style="font-weight:800;color:#312e81;">' + fmtN(r.total_amount) + '</td>' +
        '<td style="font-size:10.5px;color:var(--muted);">' + esc(r.imported_at||'—') + '</td>' +
        '<td style="text-align:center;">' + (r.scheme_src ? '<span class="pill ' + (r.scheme_src==='F' ? 'p-amber' : 'p-green') + '" title="' + (r.scheme_src==='F' ? 'Only the first number of the description' : 'Middle number / normal') + '">' + esc(r.scheme_src) + '</span>' : '<span style="color:#d1d5db">—</span>') + '</td>' +
        '<td style="font-family:monospace;font-weight:800;color:#312e81;">' + esc(r.scheme_no||'—') + '</td>' +
        '<td>' + svCell(r) + '</td>' +
        '</tr>';
    });
    document.getElementById('repBody').innerHTML = h; svSync();
  }catch(e){
    document.getElementById('repBody').innerHTML =
      '<tr><td colspan="18" style="padding:34px;text-align:center;color:#dc2626;">Failed to load report data.</td></tr>';
  }
}

/* debounce search */
document.getElementById('fSearch').addEventListener('input', function(){
  clearTimeout(_searchDebounce);
  _searchDebounce = setTimeout(()=>loadReport(1), 450);
});
document.getElementById('fAmount').addEventListener('input', function(){ clearTimeout(_searchDebounce); _searchDebounce = setTimeout(()=>loadReport(1), 450); });
['fCustomer','fEntity','fStatus','fDateField','fAmountIn'].forEach(id=>{
  document.getElementById(id).addEventListener('change', ()=>loadReport(1));
});

/* ── Export to Excel (full filtered set, no pagination) ── */
function exportReport(){
  /* every row of the table (all pages) with the current filters — built on the server */
  showToast('Preparing the Excel with all rows…');
  const qs = new URLSearchParams({ export_xlsx: '1', ...getFilterParams() });
  window.location.href = 'customer_claim_report.php?' + qs.toString();
}

/* init */
loadFilterOptions();
loadReport(1);
</script>
<?php include 'footer.php'; ?>