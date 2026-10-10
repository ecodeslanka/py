<?php
/**
 * risk_customer_bills_report.php
 * ─────────────────────────────────────────────────────────────────────
 * RISK CUSTOMER BILLS REPORT
 *
 * Lists the bills loaded for delivery (loading_summary_import_details, same source
 * the Import History and CCF report use) that belong to a customer already flagged
 * elsewhere in this app as risky:
 *   - Blacklisted        (customers.blacklisted, set from the Customers page)
 *   - Flagged on the Customer Risk report (customers.cheques_will_delay)
 * For each customer, the "Reason" column shows the Customer Risk report's own verdict:
 * risk band, score and the main reason (the signal adding the most points).
 *
 * BLACKLIST CATEGORY NAMES
 *   customers.blacklist_type stores a short key (permanent / temporary / no_cash_cod /
 *   no_cheque_cod). This page now shows the same full name and description the Customer
 *   Credit Risk report uses (e.g. "Block - Cash on Delivery" — "Cash no longer accepted
 *   at delivery.") in the table, the badge tooltip and the Excel export, instead of the key.
 *
 * Reached from Import History: each delivery-date row has a "Risk Bills" button that
 * opens this page for that one date. It can also be opened on its own with any date range.
 *
 * FEATURES
 *   - Filters: delivery date range, route, risk bill reason, search.
 *   - Risk bill reason per bill: a Select2 dropdown of the short codes kept on
 *     field_summary_risk_bill_reasons.php, saved as soon as it is changed.
 *   - A free-text Remarks column per bill, saved as you type (no separate save button).
 *   - Export Excel: the same list, with reason, remarks and a TOTAL row.
 *
 * SPEED
 *   - One SQL query returns only the bills of risk customers (the old version read every
 *     bill in the range twice).
 *   - The old version called the Customer Risk report over HTTP once PER CUSTOMER while the
 *     page was loading (up to 4 s each) — that was the main slowness. Now the page shows at
 *     once, and the browser asks for all customers' reasons in ONE call to that report,
 *     cached for 10 minutes (RCB_RISK_CACHE_TTL).
 *   - Index (delivery_date, bill_no) is added to loading_summary_import_details once.
 *   - Select2 is only switched on for a row when you click its reason box.
 *
 * A bill imported twice (same delivery date + bill no) is counted once: the later import wins,
 * the same rule the CCF report and Canceled Bills report use.
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');

ob_start();
include_once 'config.php';
ob_end_clean();
include_once 'auth.php';

const RCB_MAX_DAYS = 366;
const RCB_MAX_ROWS = 20000;   /* bills shown / exported in one go */

/* Default risk bill reasons (short code => reason) and the blacklist category each one is
   auto-selected for. A bill of a customer blacklisted under that category gets this reason
   saved automatically when it has no reason yet; every other bill is picked by hand. */
const RCB_AUTO_REASONS = ['CASH OD' => 'Cash on Delivery', 'CHEQUE OD' => 'Cheque on Delivery'];
const RCB_AUTO_BY_BLTYPE = ['no_cash_cod' => 'CASH OD', 'no_cheque_cod' => 'CHEQUE OD'];

/* Excel cell-style ids (see rcb_xl_styles). Declared before the export endpoint runs. */
const RX_TITLE = 1, RX_HEAD = 2, RX_TEXT = 3, RX_DATE = 4, RX_MONEY = 5, RX_TTEXT = 6, RX_TMONEY = 7;

/* ═══════════════════════════ SCHEMA ═══════════════════════════ */

function rcb_q($conn, $sql, $mode = MYSQLI_STORE_RESULT) {
    try { return mysqli_query($conn, $sql, $mode); } catch (Throwable $e) { return false; }
}
function rcb_table_exists($conn, $t) {
    $r = rcb_q($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'");
    return $r && mysqli_num_rows($r) > 0;
}
function rcb_column_exists($conn, $t, $c) {
    $r = rcb_q($conn, "SHOW COLUMNS FROM `$t` LIKE '" . mysqli_real_escape_string($conn, $c) . "'");
    return $r && mysqli_num_rows($r) > 0;
}
function rcb_ensure_schema($conn) {
    rcb_q($conn, "CREATE TABLE IF NOT EXISTS risk_customer_bill_remarks (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        delivery_date DATE         NOT NULL,
        bill_no       VARCHAR(100) NOT NULL,
        remarks       VARCHAR(500) NOT NULL DEFAULT '',
        reason_id     INT          NULL,
        updated_by    VARCHAR(100) NULL,
        updated_at    DATETIME     NOT NULL,
        UNIQUE KEY uq_bill (delivery_date, bill_no),
        INDEX idx_reason (reason_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    /* older installs: table exists without reason_id */
    if (!rcb_column_exists($conn, 'risk_customer_bill_remarks', 'reason_id')) {
        rcb_q($conn, "ALTER TABLE risk_customer_bill_remarks ADD COLUMN reason_id INT NULL AFTER remarks, ADD INDEX idx_reason (reason_id)");
    }
    /* same definition as field_summary_risk_bill_reasons.php */
    rcb_q($conn, "CREATE TABLE IF NOT EXISTS field_summary_risk_bill_reasons (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        short_code VARCHAR(20)  NOT NULL,
        reason     VARCHAR(255) NOT NULL,
        active     TINYINT(1)   NOT NULL DEFAULT 1,
        created_by VARCHAR(100) NULL,
        updated_by VARCHAR(100) NULL,
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_short_code (short_code),
        INDEX idx_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    /* two default reasons, used to auto-select a reason for "Block - Cash on Delivery" /
       "Block - Cheque on Delivery" customers. INSERT IGNORE: never overwrites them once they
       exist, so they can still be renamed or switched off on field_summary_risk_bill_reasons.php */
    foreach (RCB_AUTO_REASONS as $code => $text) {
        $ce = mysqli_real_escape_string($conn, $code); $te = mysqli_real_escape_string($conn, $text);
        rcb_q($conn, "INSERT IGNORE INTO field_summary_risk_bill_reasons (short_code, reason, active, created_by)
                      VALUES ('$ce', '$te', 1, 'system')");
    }
    /* speed: range scan on delivery_date + "is there a later import of this bill" check */
    if (rcb_table_exists($conn, 'loading_summary_import_details')) {
        $r = rcb_q($conn, "SHOW INDEX FROM loading_summary_import_details WHERE Key_name = 'idx_rcb_date_bill'");
        if ($r && mysqli_num_rows($r) === 0) {
            rcb_q($conn, "ALTER TABLE loading_summary_import_details ADD INDEX idx_rcb_date_bill (delivery_date, bill_no)");
        }
    }
}

/* ═══════════════════════════ HELPERS ═══════════════════════════ */

function rcb_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function rcb_valid_date($d) { if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false; return checkdate((int)$m[2], (int)$m[3], (int)$m[1]); }
function rcb_get($key, $default = '') { return (isset($_GET[$key]) && is_string($_GET[$key])) ? trim($_GET[$key]) : $default; }
function rcb_cents($c) { return number_format($c / 100, 2); }
function rcb_date($d, $fmt = 'd M Y') { $t = strtotime((string)$d); return $t ? date($fmt, $t) : ''; }
function rcb_user() {
    if (function_exists('getCurrentUser')) { $u = getCurrentUser(); if ($u) return $u['username'] ?? ($u['name'] ?? null); }
    return $_SESSION['username'] ?? null;
}

/* all reasons (active + inactive) id => row, so a bill keeps showing an inactive reason it already has */
function rcb_reasons($conn) {
    $out = [];
    $r = rcb_q($conn, "SELECT id, short_code, reason, active FROM field_summary_risk_bill_reasons ORDER BY short_code ASC");
    if ($r) while ($x = mysqli_fetch_assoc($r)) $out[(int)$x['id']] = ['id' => (int)$x['id'], 'code' => $x['short_code'], 'text' => $x['reason'], 'active' => (int)$x['active']];
    return $out;
}
function rcb_reason_label($rs) { return $rs ? $rs['code'] . ' – ' . $rs['text'] : ''; }

/* blacklist_type => reason id, for the ACTIVE default reasons only (a switched-off reason is never auto-selected) */
function rcb_auto_reason_map($reasons) {
    $by_code = [];
    foreach ($reasons as $rs) if ($rs['active']) $by_code[strtoupper(trim($rs['code']))] = $rs['id'];
    $map = [];
    foreach (RCB_AUTO_BY_BLTYPE as $bltype => $code) if (isset($by_code[$code])) $map[$bltype] = $by_code[$code];
    return $map;
}

/* Blacklist category key -> the same full name / description used on the
   Customer Credit Risk report (BL_TYPE_META there). Unknown keys are tidied up
   ("some_key" -> "Some Key") rather than shown raw. */
function rcb_bl_meta($type) {
    static $m = [
        'permanent'     => ['Block - Permanent',          'Hard, indefinite stop on new credit transactions.'],
        'temporary'     => ['Block - Temporary',          'Short-term hold, expected to be lifted later.'],
        'no_cash_cod'   => ['Block - Cash on Delivery',   'Cash no longer accepted at delivery.'],
        'no_cheque_cod' => ['Block - Cheque on Delivery', 'Cheques no longer accepted at delivery.'],
    ];
    $type = trim((string)$type);
    if (isset($m[$type])) return $m[$type];
    return [$type !== '' ? ucwords(str_replace('_', ' ', $type)) : 'Blacklisted', 'Blocked from new credit transactions.'];
}
function rcb_bl_label($type) { return rcb_bl_meta($type)[0]; }
function rcb_bl_desc($type)  { return rcb_bl_meta($type)[1]; }

function rcb_filters() {
    $today = date('Y-m-d');
    $df = rcb_get('date_from'); $dt = rcb_get('date_to');
    $df = rcb_valid_date($df) ? $df : $today;
    $dt = rcb_valid_date($dt) ? $dt : $today;
    if ($df > $dt) { $t = $df; $df = $dt; $dt = $t; }
    $limited = false;
    if ((new DateTime($df))->diff(new DateTime($dt))->days >= RCB_MAX_DAYS) {
        $df = date('Y-m-d', strtotime($dt . ' -' . (RCB_MAX_DAYS - 1) . ' days'));
        $limited = true;
    }
    $reason = rcb_get('reason');
    if ($reason !== '' && $reason !== 'none' && !ctype_digit($reason)) $reason = '';
    $cut = function ($s) { return strlen($s) > 100 ? substr($s, 0, 100) : $s; };
    return ['from' => $df, 'to' => $dt, 'limited' => $limited,
            'route' => $cut(rcb_get('route')), 'q' => $cut(rcb_get('q')), 'reason' => $reason];
}
function rcb_url($f, $over = []) {
    $p = array_merge(['date_from' => $f['from'], 'date_to' => $f['to'], 'route' => $f['route'], 'reason' => $f['reason'], 'q' => $f['q']], $over);
    foreach (['route', 'reason', 'q'] as $k) if (($p[$k] ?? '') === '') unset($p[$k]);
    return basename(__FILE__) . '?' . http_build_query($p);
}

/* ═══════════════════════════ DATA ═══════════════════════════ */

function rcb_compute($conn, $f, $reasons) {
    $t0 = microtime(true);
    $out = ['rows' => [], 'total_n' => 0, 'total_v' => 0, 'with_reason' => 0, 'route_options' => [], 'no_data' => false, 'error' => '', 'scanned' => 0, 'ms' => 0, 'truncated' => false];

    if (!rcb_table_exists($conn, 'loading_summary_import_details') || !rcb_table_exists($conn, 'customers')) { $out['no_data'] = true; return $out; }
    $from = $f['from']; $to = $f['to'];

    /* risk customers: blacklisted, or flagged on the Customer Risk report */
    $risky = [];
    $has_cwd = rcb_column_exists($conn, 'customers', 'cheques_will_delay');
    $rc = rcb_q($conn, "SELECT t_code, COALESCE(blacklisted,0) AS bl, COALESCE(blacklist_type,'') AS bltype, COALESCE(blacklist_reason,'') AS blreason,
                               " . ($has_cwd ? "COALESCE(cheques_will_delay,0)" : "0") . " AS cwd
                        FROM customers WHERE COALESCE(blacklisted,0) = 1" . ($has_cwd ? " OR COALESCE(cheques_will_delay,0) = 1" : ""));
    if (!$rc) { $out['error'] = mysqli_error($conn); return $out; }
    while ($r = mysqli_fetch_assoc($rc)) { $tc = trim((string)$r['t_code']); if ($tc !== '') $risky[$tc] = $r; }
    if (!$risky) { $out['ms'] = (int)round((microtime(true) - $t0) * 1000); return $out; }

    $in = implode(',', array_map(function ($t) use ($conn) { return "'" . mysqli_real_escape_string($conn, $t) . "'"; }, array_keys($risky)));

    $routes = [];
    $rr = rcb_q($conn, "SELECT route_code, route_name FROM routes");
    if ($rr) while ($x = mysqli_fetch_row($rr)) $routes[trim((string)$x[0])] = (string)$x[1];

    /* saved reason + remark per bill, read separately (not joined in SQL) so tables with
       different collations (e.g. utf8mb4_0900_ai_ci vs utf8mb4_uca1400_ai_ci) never get compared */
    $saved = [];
    $rm = rcb_q($conn, "SELECT delivery_date, bill_no, remarks, reason_id FROM risk_customer_bill_remarks
                        WHERE delivery_date BETWEEN '$from' AND '$to'");
    if ($rm) while ($x = mysqli_fetch_assoc($rm)) $saved[$x['delivery_date'] . '|' . trim((string)$x['bill_no'])] = $x;

    /* One query: only risk customers' bills, skipping any bill that has a later import
       of the same bill no on the same delivery date. */
    $sql = "SELECT d.delivery_date, TRIM(d.bill_no) AS bill_no, TRIM(d.t_code) AS t_code, d.customer_name, d.party_name,
                   TRIM(d.route_code) AS route_code, d.route_name, d.sales_person_code, d.delivery_person, d.final_bill_amount
            FROM loading_summary_import_details d
            WHERE d.status = 'imported'
              AND d.delivery_date BETWEEN '$from' AND '$to'
              AND TRIM(d.t_code) IN ($in)
              AND NOT EXISTS (
                    SELECT 1 FROM loading_summary_import_details d2
                    WHERE d2.delivery_date = d.delivery_date
                      AND d2.bill_no = d.bill_no
                      AND d2.status = 'imported'
                      AND d2.id > d.id
                      AND TRIM(d.bill_no) <> '')";
    $r = rcb_q($conn, $sql, MYSQLI_USE_RESULT);
    if (!$r) { $out['error'] = mysqli_error($conn) ?: 'Query failed.'; return $out; }

    $rows = []; $route_opts = [];
    $needle = $f['q'] !== '' ? mb_strtolower($f['q']) : '';
    $auto_map  = rcb_auto_reason_map($reasons);
    $auto_save = [];   /* bills that get a default reason saved after the loop (the result set is still open here) */
    while ($x = mysqli_fetch_assoc($r)) {
        $out['scanned']++;
        $tc    = $x['t_code'];
        $risk  = $risky[$tc] ?? ['bl' => 0, 'cwd' => 0, 'bltype' => '', 'blreason' => ''];
        $route = (string)$x['route_code'];
        $route_lbl = $routes[$route] ?? (trim((string)$x['route_name']) !== '' ? trim((string)$x['route_name']) : ($route !== '' ? $route : '(No route)'));
        if ($route !== '') $route_opts[$route] = $route_lbl;

        if ($f['route'] !== '' && $route !== $f['route']) continue;

        $sv  = $saved[$x['delivery_date'] . '|' . (string)$x['bill_no']] ?? null;
        $x['remarks']   = $sv ? $sv['remarks'] : '';
        $x['reason_id'] = $sv ? $sv['reason_id'] : null;
        $rid = $x['reason_id'] !== null ? (int)$x['reason_id'] : 0;
        if ($rid && !isset($reasons[$rid])) $rid = 0;              /* reason was deleted */

        /* auto-select: Cash on Delivery / Cheque on Delivery blacklist -> CASH OD / CHEQUE OD,
           only when no reason has been picked for this bill yet (a hand-picked reason is kept) */
        $auto = false;
        $bltype = trim((string)$risk['bltype']);
        if (!$rid && (int)$risk['bl'] === 1 && isset($auto_map[$bltype])) {
            $rid  = $auto_map[$bltype];
            $auto = true;
            $auto_save[] = [$x['delivery_date'], (string)$x['bill_no'], $rid];
        }

        if ($f['reason'] === 'none' && $rid) continue;
        if ($f['reason'] !== '' && $f['reason'] !== 'none' && $rid !== (int)$f['reason']) continue;

        $bn   = (string)$x['bill_no'];
        $cust = trim((string)$x['customer_name']) !== '' ? trim((string)$x['customer_name']) : trim((string)$x['party_name']);
        if ($needle !== '' && strpos(mb_strtolower($bn . ' ' . $cust . ' ' . $tc), $needle) === false) continue;

        $cents = (int)round(((float)$x['final_bill_amount']) * 100);
        $rows[] = ['date' => $x['delivery_date'], 'bill' => $bn, 't_code' => $tc, 'cust' => $cust, 'route' => $route_lbl,
                   'sr' => trim((string)$x['sales_person_code']),
                   'dp' => trim((string)$x['delivery_person']) !== '' ? trim((string)$x['delivery_person']) : '(Unassigned)',
                   'cents' => $cents, 'bl' => (int)$risk['bl'], 'cwd' => (int)$risk['cwd'], 'bltype' => trim((string)$risk['bltype']), 'blreason' => trim((string)$risk['blreason']),
                   'reason_id' => $rid, 'remarks' => (string)($x['remarks'] ?? '')];
        $out['total_n']++; $out['total_v'] += $cents;
        if ($rid) $out['with_reason']++;
    }
    mysqli_free_result($r);

    usort($rows, function ($a, $b) { return strcmp($b['date'], $a['date']) ?: strcmp($a['cust'], $b['cust']) ?: strcmp($a['bill'], $b['bill']); });
    if (count($rows) > RCB_MAX_ROWS) { $rows = array_slice($rows, 0, RCB_MAX_ROWS); $out['truncated'] = true; }
    asort($route_opts, SORT_NATURAL | SORT_FLAG_CASE);

    $out['rows'] = $rows; $out['route_options'] = $route_opts; $out['ms'] = (int)round((microtime(true) - $t0) * 1000);
    return $out;
}

/* upsert one bill's reason / remark; removes the row when both are empty */
function rcb_save_bill($conn, $date, $bill, $fields) {
    $de = mysqli_real_escape_string($conn, $date); $be = mysqli_real_escape_string($conn, $bill);
    $user = rcb_user();
    $ue = $user !== null ? "'" . mysqli_real_escape_string($conn, (string)$user) . "'" : 'NULL';
    $rem = array_key_exists('remarks', $fields) ? "'" . mysqli_real_escape_string($conn, $fields['remarks']) . "'" : "''";
    $rid = array_key_exists('reason_id', $fields) ? ($fields['reason_id'] ? (int)$fields['reason_id'] : 'NULL') : 'NULL';
    $upd = [];
    if (array_key_exists('remarks', $fields))   $upd[] = 'remarks = VALUES(remarks)';
    if (array_key_exists('reason_id', $fields)) $upd[] = 'reason_id = VALUES(reason_id)';
    $upd[] = 'updated_by = VALUES(updated_by)'; $upd[] = 'updated_at = VALUES(updated_at)';
    $ok = rcb_q($conn, "INSERT INTO risk_customer_bill_remarks (delivery_date, bill_no, remarks, reason_id, updated_by, updated_at)
                        VALUES ('$de', '$be', $rem, $rid, $ue, NOW())
                        ON DUPLICATE KEY UPDATE " . implode(', ', $upd));
    if ($ok) rcb_q($conn, "DELETE FROM risk_customer_bill_remarks WHERE delivery_date = '$de' AND bill_no = '$be' AND remarks = '' AND reason_id IS NULL");
    return (bool)$ok;
}

/* ═══════════════════════ CUSTOMER RISK REPORT REASONS ═══════════════════════
   The Customer Risk report (customer_credit_risk_report.php) already works out, for every
   customer with money outstanding, the risk score / band and which single signal adds the
   most points, with a sentence explaining it ($reasons[$top_driver] there). We ask its own
   "risk_list" endpoint ONCE for all customers (not once per customer) and cache the small
   t_code => {score, band, reason} map in a temp file for RCB_RISK_CACHE_TTL seconds.
   A flagged customer with nothing outstanding is not scored there, so they are not in the map. */
const RCB_RISK_CACHE_TTL = 600;

function rcb_risk_cache_file($to) { return rtrim(sys_get_temp_dir(), '/\\') . '/rcb_risk_' . md5(__DIR__ . '|' . $to) . '.json'; }

function rcb_risk_map($to, $refresh = false) {
    $file = rcb_risk_cache_file($to);
    if (!$refresh && is_file($file) && (time() - filemtime($file)) < RCB_RISK_CACHE_TTL) {
        $j = json_decode((string)@file_get_contents($file), true);
        if (is_array($j) && isset($j['map'])) return $j;
    }
    if (!ini_get('allow_url_fopen') || session_id() === '') return ['map' => null, 'at' => 0, 'error' => 'The Customer Risk report could not be reached from the server (allow_url_fopen is off).'];
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/x.php')), '/');
    if ($host === '') return ['map' => null, 'at' => 0, 'error' => 'Unknown host.'];
    $url = $scheme . '://' . $host . $dir . '/customer_credit_risk_report.php?ajax=risk_list&to=' . urlencode($to);
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'header' => 'Cookie: ' . session_name() . '=' . session_id() . "\r\n", 'timeout' => 120, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],   /* calling our own site */
    ]);
    /* release the session lock first, or the risk report (same session) would wait for us forever */
    $had = session_status() === PHP_SESSION_ACTIVE;
    if ($had) session_write_close();
    @set_time_limit(180);
    $body = @file_get_contents($url, false, $ctx);
    if ($had && !headers_sent()) @session_start();

    $j = $body !== false ? json_decode($body, true) : null;
    if (!is_array($j) || !isset($j['rows']) || !is_array($j['rows'])) {
        $msg = is_array($j) && !empty($j['error']) ? $j['error'] : 'No answer from the Customer Risk report.';
        return ['map' => null, 'at' => 0, 'error' => $msg];
    }
    $map = [];
    foreach ($j['rows'] as $row) {
        $tc = trim((string)($row['t_code'] ?? '')); if ($tc === '') continue;
        $top = $row['top_driver'] ?? null;
        $map[$tc] = ['score'  => isset($row['risk_score']) ? (int)$row['risk_score'] : null,
                     'band'   => (string)($row['risk_band'] ?? ''),
                     'reason' => ($top && isset($row['reasons'][$top])) ? (string)$row['reasons'][$top] : ''];
    }
    $out = ['map' => $map, 'at' => time(), 'error' => ''];
    @file_put_contents($file, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
    return $out;
}

/* one line of text for Excel */
function rcb_reason_text($r, $cr) {
    if ($cr && $cr['reason'] !== '') {
        $tag = $cr['band'] !== '' ? ' [' . $cr['band'] . ' risk, score ' . $cr['score'] . '/100]' : '';
        return 'Main reason (Customer Risk report)' . $tag . ': ' . $cr['reason'];
    }
    $parts = [];
    if ($r['bl']) {
        /* full category name + its description (or the stored reason, if one exists) */
        $parts[] = rcb_bl_label($r['bltype']) . ': ' . ($r['blreason'] !== '' ? $r['blreason'] : rcb_bl_desc($r['bltype']));
    } elseif ($r['cwd']) {
        $parts[] = 'Flagged on the Customer Risk report';
    }
    if (!$parts) return '';
    return implode(' | ', $parts) . ' (not currently scored on the Customer Risk report -- no balance outstanding)';
}

/* ═══════════════════════════ AJAX / EXPORT ═══════════════════════════ */

$rcb_ajax = rcb_get('ajax'); $rcb_export = rcb_get('export');
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['rcb_csrf'])) $_SESSION['rcb_csrf'] = bin2hex(random_bytes(16));

if ($rcb_ajax !== '' || $rcb_export !== '') {
    if (!isLoggedIn() && !(function_exists('autoLoginFromCookie') && autoLoginFromCookie())) {
        if ($rcb_export !== '') { http_response_code(401); header('Content-Type: text/plain; charset=utf-8'); echo 'Your session has expired. Sign in again and retry the export.'; exit; }
        http_response_code(401); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success' => false, 'error' => 'Your session has expired. Reload the page and sign in again.']); exit;
    }
    rcb_ensure_schema($conn);

    if (($rcb_ajax === 'save_remark' || $rcb_ajax === 'save_reason') && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        if (!hash_equals((string)$_SESSION['rcb_csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'This page is out of date. Reload it and try again.']); exit; }
        $date = trim((string)($_POST['date'] ?? '')); $bill = trim((string)($_POST['bill'] ?? ''));
        if (!rcb_valid_date($date) || $bill === '') { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Missing or invalid bill.']); exit; }

        if ($rcb_ajax === 'save_remark') {
            $text = trim((string)($_POST['remarks'] ?? ''));
            if (mb_strlen($text) > 500) $text = mb_substr($text, 0, 500);
            $ok = rcb_save_bill($conn, $date, $bill, ['remarks' => $text]);
        } else {
            $rid = (int)($_POST['reason_id'] ?? 0);
            if ($rid > 0) {
                $chk = rcb_q($conn, "SELECT id FROM field_summary_risk_bill_reasons WHERE id = $rid LIMIT 1");
                if (!$chk || !mysqli_fetch_row($chk)) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'That reason no longer exists. Reload the page.']); exit; }
            }
            $ok = rcb_save_bill($conn, $date, $bill, ['reason_id' => $rid]);
        }
        echo json_encode($ok ? ['success' => true] : ['success' => false, 'error' => 'Could not save: ' . mysqli_error($conn)]);
        exit;
    }

    /* reasons for the customers on the page, from one (cached) call to the Customer Risk report */
    if ($rcb_ajax === 'risk_reasons' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        if (!hash_equals((string)$_SESSION['rcb_csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'This page is out of date. Reload it and try again.']); exit; }
        $to = trim((string)($_POST['to'] ?? '')); if (!rcb_valid_date($to)) $to = date('Y-m-d');
        $want = array_filter(array_map('trim', explode(',', (string)($_POST['t_codes'] ?? ''))), 'strlen');
        $res = rcb_risk_map($to, !empty($_POST['refresh']));
        if ($res['map'] === null) { echo json_encode(['success' => false, 'error' => $res['error']]); exit; }
        $sub = [];
        foreach ($want as $tc) if (isset($res['map'][$tc])) $sub[$tc] = $res['map'][$tc];
        echo json_encode(['success' => true, 'map' => (object)$sub, 'at' => $res['at'] ? date('d M Y H:i', $res['at']) : ''], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    if ($rcb_export === 'xlsx') {
        if (!class_exists('ZipArchive')) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); echo 'The PHP ZipArchive extension is needed to create Excel files.'; exit; }
        $f = rcb_filters(); $reasons = rcb_reasons($conn); $d = rcb_compute($conn, $f, $reasons);
        if ($d['error'] !== '') { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); echo 'The report could not be loaded. ' . $d['error']; exit; }
        $rmap = $d['rows'] ? (rcb_risk_map($f['to'])['map'] ?? []) : [];
        $path = rcb_build_xlsx($f, $d, $reasons, $rmap ?: []);
        if ($path === '') { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); echo 'The Excel file could not be created.'; exit; }
        $name = 'Risk_Customer_Bills_' . $f['from'] . '_to_' . $f['to'] . '.xlsx';
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path); @unlink($path); exit;
    }
    http_response_code(400); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success' => false, 'error' => 'Unknown request.']); exit;
}

/* ═══════════════════════════ EXCEL WRITER ═══════════════════════════ */

function rcb_xl_col($i) { $s = ''; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; }
function rcb_xl_esc($s) { $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$s); return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function rcb_xl_serial($d) { return (int)(strtotime($d . ' 00:00:00 UTC') / 86400) + 25569; }

function rcb_xl_styles() {
    $fonts = '<font><sz val="10"/><name val="Arial"/></font><font><b/><sz val="10"/><name val="Arial"/></font><font><b/><sz val="14"/><name val="Arial"/></font>';
    $fills = '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
           . '<fill><patternFill patternType="solid"><fgColor rgb="FFD9D9D9"/><bgColor indexed="64"/></patternFill></fill>'
           . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill>';
    $borders = '<border><left/><right/><top/><bottom/><diagonal/></border>'
             . '<border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right>'
             . '<top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/></border>';
    $numFmts = '<numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/>';
    $xf = function ($font, $fill, $border, $fmt, $h = '', $wrap = false) {
        $al = ($h !== '' || $wrap) ? '<alignment' . ($h !== '' ? ' horizontal="' . $h . '"' : '') . ' vertical="center"' . ($wrap ? ' wrapText="1"' : '') . '/>' : '';
        return '<xf numFmtId="' . $fmt . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1"' . ($al ? ' applyAlignment="1">' . $al . '</xf>' : '/>');
    };
    $xfs = $xf(0, 0, 0, 0) . $xf(2, 0, 0, 0) . $xf(1, 2, 1, 0, 'center', true) . $xf(0, 0, 1, 0)
         . $xf(0, 0, 1, 164, 'left') . $xf(0, 0, 1, 4) . $xf(1, 3, 1, 0) . $xf(1, 3, 1, 4);
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
         . '<numFmts count="1">' . $numFmts . '</numFmts><fonts count="3">' . $fonts . '</fonts><fills count="4">' . $fills . '</fills><borders count="2">' . $borders . '</borders>'
         . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="8">' . $xfs . '</cellXfs>'
         . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
}
function rcb_xl_row($rn, $row) {
    ksort($row); $x = '<row r="' . $rn . '">';
    foreach ($row as $cn => $c) {
        $ref = rcb_xl_col($cn) . $rn; $s = $c['s'] ?? 0;
        if (isset($c['f'])) $x .= '<c r="' . $ref . '" s="' . $s . '"><f>' . rcb_xl_esc($c['f']) . '</f><v>' . $c['v'] . '</v></c>';
        elseif (($c['t'] ?? 's') === 'n') $x .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $c['v'] . '</v></c>';
        elseif ($c['v'] === '' || $c['v'] === null) $x .= '<c r="' . $ref . '" s="' . $s . '"/>';
        else $x .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . rcb_xl_esc($c['v']) . '</t></is></c>';
    }
    return $x . '</row>';
}
function rcb_xl_sheet($rows, $widths, $freezeRow, $filterRef, $maxCol) {
    $maxRow = max(array_keys($rows));
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
       . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:' . rcb_xl_col($maxCol) . $maxRow . '"/>'
       . '<sheetViews><sheetView workbookViewId="0">' . ($freezeRow > 0 ? '<pane ySplit="' . $freezeRow . '" topLeftCell="A' . ($freezeRow + 1) . '" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft"/>' : '') . '</sheetView></sheetViews>'
       . '<sheetFormatPr defaultRowHeight="15"/><cols>';
    foreach ($widths as $i => $w) $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    $x .= '</cols><sheetData>'; ksort($rows);
    foreach ($rows as $rn => $row) $x .= is_string($row) ? $row : rcb_xl_row($rn, $row);
    $x .= '</sheetData>';
    if ($filterRef !== '') $x .= '<autoFilter ref="' . $filterRef . '"/>';
    $x .= '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
    return $x;
}
function rcb_build_xlsx($f, $d, $reasons, $rmap = []) {
    $S = function ($v, $s = RX_TEXT) { return ['t' => 's', 'v' => $v, 's' => $s]; };
    $N = function ($v, $s) { return ['t' => 'n', 'v' => $v, 's' => $s]; };
    $rlabel = '';
    if ($f['reason'] === 'none') $rlabel = ' | Reason: not selected';
    elseif ($f['reason'] !== '' && isset($reasons[(int)$f['reason']])) $rlabel = ' | Reason: ' . $reasons[(int)$f['reason']]['code'];
    $title = 'Risk Customer Bills | Delivery dates ' . rcb_date($f['from']) . ' to ' . rcb_date($f['to'])
           . ($f['route'] !== '' ? ' | Route: ' . $f['route'] : '') . $rlabel . ($f['q'] !== '' ? ' | Search: ' . $f['q'] : '');
    $head = ['Delivery Date', 'Bill No', 'T-Code', 'Customer', 'Route', 'SR', 'Delivery Person', 'Bill Value',
             'Risk Flag', 'Reason (why this customer is risky)', 'Reason Code', 'Risk Bill Reason', 'Remarks'];
    $nc = count($head);
    $c = []; $c[1][1] = $S($title, RX_TITLE);
    foreach ($head as $i => $h) $c[2][$i + 1] = $S($h, RX_HEAD);
    $n = count($d['rows']); $first = 3; $last = $first + $n - 1;
    foreach ($d['rows'] as $k => $r) {
        $rn = $first + $k; $rs = $r['reason_id'] ? ($reasons[$r['reason_id']] ?? null) : null;
        $c[$rn][1] = $N(rcb_xl_serial($r['date']), RX_DATE); $c[$rn][2] = $S($r['bill']); $c[$rn][3] = $S($r['t_code']); $c[$rn][4] = $S($r['cust']);
        $c[$rn][5] = $S($r['route']); $c[$rn][6] = $S($r['sr']); $c[$rn][7] = $S($r['dp']); $c[$rn][8] = $N($r['cents'] / 100, RX_MONEY);
        $c[$rn][9] = $S($r['bl'] ? rcb_bl_label($r['bltype']) : 'Risk flagged');
        $c[$rn][10] = $S(rcb_reason_text($r, $rmap[$r['t_code']] ?? null));
        $c[$rn][11] = $S($rs ? $rs['code'] : ''); $c[$rn][12] = $S($rs ? $rs['text'] : ''); $c[$rn][13] = $S($r['remarks']);
        $c[$rn] = rcb_xl_row($rn, $c[$rn]);
    }
    $tr = $n ? $last + 1 : $first;
    $c[$tr][1] = $S('TOTAL', RX_TTEXT); for ($i = 2; $i <= 7; $i++) $c[$tr][$i] = $S('', RX_TTEXT);
    $c[$tr][8] = $n ? ['f' => 'SUM(H' . $first . ':H' . $last . ')', 'v' => $d['total_v'] / 100, 's' => RX_TMONEY] : $N(0, RX_TMONEY);
    for ($i = 9; $i <= $nc; $i++) $c[$tr][$i] = $S('', RX_TTEXT);
    $sheet = rcb_xl_sheet($c, [14, 16, 14, 30, 22, 10, 22, 16, 26, 60, 12, 36, 34], 2, $n ? 'A2:' . rcb_xl_col($nc) . $last : '', $nc);

    $tmp = tempnam(sys_get_temp_dir(), 'rcb');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) return '';
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Risk Customer Bills" sheetId="1" r:id="rId1"/></sheets><calcPr fullCalcOnLoad="1"/></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', rcb_xl_styles());
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
    return $tmp;
}

/* ═══════════════════════════ NORMAL PAGE ═══════════════════════════ */

include 'header.php';   /* requires login */

rcb_ensure_schema($conn);
$f       = rcb_filters();
$reasons = rcb_reasons($conn);
$d       = rcb_compute($conn, $f, $reasons);

/* options offered in the dropdown: active reasons (inactive ones stay visible only on bills that already use them) */
$js_reasons = [];
foreach ($reasons as $rs) $js_reasons[] = ['id' => $rs['id'], 'text' => rcb_reason_label($rs), 'active' => $rs['active']];
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<style>
.rcb, .rcb *, .rcb *::before, .rcb *::after { box-sizing: border-box; }
.rcb { font-family: 'Inter', system-ui, sans-serif; font-size: 13px; line-height: 1.45; color: #111827; }
.rcb h1 { margin: 0 0 4px; font-size: 21px; font-weight: 700; }
.rcb .sub { margin: 0 0 16px; color: #6b7280; }
.rcb .sub a { color: #111827; font-weight: 600; }
.rcb .card { background: #fff; border: 1px solid #e5e5e5; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
.rcb form.flt { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; }
.rcb .fld { display: flex; flex-direction: column; gap: 4px; }
.rcb .fld label { font-size: 12px; font-weight: 600; color: #6b7280; }
.rcb input[type="date"], .rcb input[type="text"], .rcb form.flt select { height: 36px; padding: 0 10px; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.rcb form.flt select { min-width: 170px; }
.rcb .btn { display: inline-flex; align-items: center; height: 36px; padding: 0 16px; border: 1px solid transparent; border-radius: 6px; background: #000; color: #fff; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
.rcb .btn.light { background: #fff; color: #111827; border-color: #d1d5db; }
.rcb .btn.green { background: #157f3d; }
.rcb .note { margin: 0 0 12px; padding: 9px 12px; border-radius: 6px; background: #fef3c7; border: 1px solid #f5d68a; color: #92400e; }
.rcb .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px; }
.rcb .kpi { background: #fff; border: 1px solid #e5e5e5; border-radius: 8px; padding: 12px 16px; }
.rcb .kpi .l { font-size: 12px; font-weight: 600; color: #6b7280; }
.rcb .kpi .v { font-size: 22px; font-weight: 700; }
.rcb .wrap { overflow-x: auto; }
.rcb table { width: 100%; border-collapse: collapse; }
.rcb th, .rcb td { padding: 8px 10px; border: 1px solid #edeef1; white-space: nowrap; vertical-align: top; }
.rcb thead th { background: #f3f4f6; font-weight: 700; text-align: center; color: #374151; position: sticky; top: 0; z-index: 1; }
.rcb td.num { text-align: right; font-variant-numeric: tabular-nums; }
.rcb tbody tr:hover td { background: #fafafb; }
.rcb tfoot td { font-weight: 700; background: #f3f4f6; }
.rcb .risk-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; white-space: nowrap; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
/* blacklist category colours — same as the Customer Credit Risk report */
.rcb .risk-badge.bl-permanent     { background: #1e1b4b; color: #c7d2fe; border-color: #4338ca; }
.rcb .risk-badge.bl-temporary     { background: #fffbeb; color: #b45309; border-color: #fde68a; }
.rcb .risk-badge.bl-no_cash_cod   { background: #fff1f2; color: #be123c; border-color: #fecdd3; }
.rcb .risk-badge.bl-no_cheque_cod { background: #fff7ed; color: #c2410c; border-color: #fed7aa; }
.rcb td.blr { white-space: normal; min-width: 240px; max-width: 340px; }
.rcb .risk-score { background: #eef2ff; color: #3730a3; border: 1px solid #c7d2fe; }
.rcb .risk-score.CRITICAL { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
.rcb .risk-score.HIGH { background: #fff7ed; color: #9a3412; border-color: #fed7aa; }
.rcb .risk-score.MODERATE { background: #fefce8; color: #854d0e; border-color: #fde68a; }
.rcb .risk-score.LOW { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
.rcb .risk-flag { background: #f3f4f6; color: #374151; border-color: #e5e7eb; }
.rcb .rr-status { font-size: 12px; color: #6b7280; margin: 0 0 10px; }
.rcb .rr-status button { margin-left: 8px; border: 1px solid #d1d5db; background: #fff; border-radius: 5px; padding: 2px 10px; font: inherit; font-size: 12px; cursor: pointer; }
.rcb .reason-text { margin-top: 4px; font-size: 12px; color: #374151; }
.rcb .reason-text.muted { color: #9ca3af; font-style: italic; }
.rcb td.rsn { min-width: 250px; white-space: normal; }
.rcb .rsn-sel { width: 100%; height: 32px; padding: 0 8px; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; background: #fff; cursor: pointer; }
.rcb td.remarks { white-space: normal; min-width: 220px; }
.rcb .remarks-input { width: 100%; min-width: 200px; border: 1px solid #e5e7eb; border-radius: 5px; padding: 5px 7px; font: inherit; resize: vertical; background: #fff; }
.rcb .remarks-input:focus { outline: none; border-color: #111827; }
.rcb .rstat { display: block; font-size: 11px; min-height: 14px; margin-top: 2px; }
.rcb .rstat.saving { color: #92400e; } .rcb .rstat.saved { color: #166534; } .rcb .rstat.err { color: #991b1b; }
.rcb .empty { padding: 34px 16px; text-align: center; color: #6b7280; }
.rcb .alert { padding: 14px 16px; border-radius: 8px; background: #fdecec; border: 1px solid #f3c1c1; color: #7f1d1d; }
.rcb .perf { margin: 10px 0 0; font-size: 12px; color: #6b7280; }
/* Select2 look to match the page */
.rcb .select2-container .select2-selection--single { height: 32px; border-color: #d1d5db; border-radius: 6px; }
.rcb .select2-container .select2-selection--single .select2-selection__rendered { line-height: 30px; padding-left: 8px; font-size: 13px; }
.rcb .select2-container .select2-selection--single .select2-selection__arrow { height: 30px; }
.select2-container--open .select2-dropdown { border-color: #d1d5db; font-size: 13px; }
.select2-results__option--highlighted[aria-selected], .select2-container--default .select2-results__option--highlighted.select2-results__option--selectable { background: #111827 !important; color: #fff !important; }
@media (max-width: 900px) { .rcb .kpis { grid-template-columns: 1fr 1fr; } }
</style>

<div class="rcb" id="rcbApp" data-endpoint="<?php echo rcb_h(basename(__FILE__)); ?>" data-csrf="<?php echo rcb_h($_SESSION['rcb_csrf']); ?>">
    <h1>Risk customer bills</h1>
    <p class="sub">Bills loaded for delivery for customers flagged as risky (blacklisted or flagged on the Customer Risk report), with the Customer Risk report's main reason. Pick a risk bill reason and add a remark for each bill.
        Reasons are kept on <a href="field_summary_risk_bill_reasons.php">Field summary risk bill reasons</a>.</p>

    <section class="card">
        <form class="flt" method="get" action="<?php echo rcb_h(basename(__FILE__)); ?>">
            <div class="fld"><label for="rcbFrom">From date</label><input type="date" id="rcbFrom" name="date_from" value="<?php echo rcb_h($f['from']); ?>"></div>
            <div class="fld"><label for="rcbTo">To date</label><input type="date" id="rcbTo" name="date_to" value="<?php echo rcb_h($f['to']); ?>"></div>
            <div class="fld"><label for="rcbRoute">Route</label>
                <select id="rcbRoute" name="route"><option value="">All routes</option>
                    <?php foreach ($d['route_options'] as $code => $name): ?><option value="<?php echo rcb_h($code); ?>"<?php echo $f['route'] === (string)$code ? ' selected' : ''; ?>><?php echo rcb_h($name); ?></option><?php endforeach; ?>
                </select></div>
            <div class="fld"><label for="rcbReason">Risk bill reason</label>
                <select id="rcbReason" name="reason">
                    <option value="">All reasons</option>
                    <option value="none"<?php echo $f['reason'] === 'none' ? ' selected' : ''; ?>>Not selected yet</option>
                    <?php foreach ($reasons as $rs): ?><option value="<?php echo (int)$rs['id']; ?>"<?php echo $f['reason'] === (string)$rs['id'] ? ' selected' : ''; ?>><?php echo rcb_h(rcb_reason_label($rs)); ?><?php echo $rs['active'] ? '' : ' (inactive)'; ?></option><?php endforeach; ?>
                </select></div>
            <div class="fld"><label for="rcbQ">Search</label><input type="text" id="rcbQ" name="q" placeholder="Bill no, customer, T-code" value="<?php echo rcb_h($f['q']); ?>"></div>
            <button type="submit" class="btn">Apply filters</button>
            <a class="btn light" href="<?php echo rcb_h(basename(__FILE__)); ?>">Reset</a>
            <a class="btn green" href="<?php echo rcb_h(rcb_url($f, ['export' => 'xlsx'])); ?>">Export Excel</a>
        </form>
    </section>

    <?php if ($f['limited']): ?><p class="note">The date range is limited to <?php echo RCB_MAX_DAYS; ?> days. Showing <?php echo rcb_h(rcb_date($f['from'])); ?> to <?php echo rcb_h(rcb_date($f['to'])); ?>.</p><?php endif; ?>

    <?php if ($d['no_data']): ?>
        <div class="card empty"><strong>There is no data to report yet.</strong><p>This report needs the loading summary import and the Customers list.</p></div>
    <?php elseif ($d['error'] !== ''): ?>
        <div class="alert"><strong>The report could not be loaded.</strong> Database message: <?php echo rcb_h($d['error']); ?></div>
    <?php else: ?>
        <?php if ($d['truncated']): ?><p class="note">Showing the first <?php echo number_format(RCB_MAX_ROWS); ?> bills. Narrow the date range or filters to see the rest, or use Export Excel.</p><?php endif; ?>
        <?php if (!$reasons): ?><p class="note">No risk bill reasons have been added yet. <a href="field_summary_risk_bill_reasons.php">Add reasons</a> to pick them here.</p><?php endif; ?>

        <section class="kpis">
            <div class="kpi"><div class="l">Risk bills</div><div class="v"><?php echo number_format($d['total_n']); ?></div></div>
            <div class="kpi"><div class="l">Total value</div><div class="v">Rs. <?php echo rcb_cents($d['total_v']); ?></div></div>
            <div class="kpi"><div class="l">Reason selected</div><div class="v"><span id="rcbWithReason"><?php echo number_format($d['with_reason']); ?></span> / <?php echo number_format($d['total_n']); ?></div></div>
            <div class="kpi"><div class="l">Delivery dates</div><div class="v" style="font-size:16px"><?php echo rcb_h(rcb_date($f['from'])); ?> – <?php echo rcb_h(rcb_date($f['to'])); ?></div></div>
        </section>

        <?php if (!$d['rows']): ?>
            <div class="card empty"><strong>No risk-customer bills found.</strong><p>Either no bills matched the filters, or no risk customer has a bill in this range.</p></div>
        <?php else: ?>
        <section class="card">
            <p class="rr-status" id="rcbRRStatus" data-to="<?php echo rcb_h($f['to']); ?>">Loading reasons from the Customer Risk report…</p>
            <div class="wrap">
            <table>
                <thead><tr>
                    <th>Delivery Date</th><th>Bill No</th><th>T-Code</th><th>Customer</th><th>Route</th><th>SR</th><th>Delivery Person</th>
                    <th>Bill Value</th><th>Reason (why this customer is risky)</th><th>Risk Bill Reason</th><th>Remarks</th>
                </tr></thead>
                <tbody>
                <?php foreach ($d['rows'] as $r):
                    $rs = $r['reason_id'] ? ($reasons[$r['reason_id']] ?? null) : null;
                    /* text shown under the badge when the Customer Risk report has no reason for this customer */
                    $bl_fallback = $r['bl'] ? rcb_bl_label($r['bltype']) . ' — ' . ($r['blreason'] !== '' ? $r['blreason'] : rcb_bl_desc($r['bltype'])) : '';
                ?>
                    <tr>
                        <td><?php echo rcb_h(rcb_date($r['date'])); ?></td>
                        <td><?php echo rcb_h($r['bill']); ?></td>
                        <td><?php echo rcb_h($r['t_code']); ?></td>
                        <td><?php echo rcb_h($r['cust']); ?></td>
                        <td><?php echo rcb_h($r['route']); ?></td>
                        <td><?php echo rcb_h($r['sr']); ?></td>
                        <td><?php echo rcb_h($r['dp']); ?></td>
                        <td class="num">Rs. <?php echo rcb_cents($r['cents']); ?></td>
                        <td class="blr" data-tc="<?php echo rcb_h($r['t_code']); ?>" data-bl="<?php echo (int)$r['bl']; ?>" data-blreason="<?php echo rcb_h($bl_fallback); ?>">
                            <?php if ($r['bl']): ?><span class="risk-badge bl-<?php echo rcb_h($r['bltype']); ?>" title="<?php echo rcb_h(rcb_bl_desc($r['bltype'])); ?>"><i class="fa-solid fa-ban"></i> <?php echo rcb_h(rcb_bl_label($r['bltype'])); ?></span><?php endif; ?>
                            <span class="cr-badge"></span>
                            <div class="reason-text muted cr-text">Loading reason…</div>
                        </td>
                        <td class="rsn">
                            <select class="rsn-sel" data-date="<?php echo rcb_h($r['date']); ?>" data-bill="<?php echo rcb_h($r['bill']); ?>" data-val="<?php echo (int)$r['reason_id']; ?>" aria-label="Risk bill reason for bill <?php echo rcb_h($r['bill']); ?>">
                                <option value="">— Select reason —</option>
                                <?php if ($rs): ?><option value="<?php echo (int)$rs['id']; ?>" selected><?php echo rcb_h(rcb_reason_label($rs)); ?></option><?php endif; ?>
                            </select>
                            <span class="rstat"></span>
                        </td>
                        <td class="remarks">
                            <textarea class="remarks-input" rows="1" data-date="<?php echo rcb_h($r['date']); ?>" data-bill="<?php echo rcb_h($r['bill']); ?>" placeholder="Add a remark…"><?php echo rcb_h($r['remarks']); ?></textarea>
                            <span class="rstat"></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="7">TOTAL</td><td class="num">Rs. <?php echo rcb_cents($d['total_v']); ?></td><td colspan="3"></td></tr></tfoot>
            </table>
            </div>
        </section>
        <?php endif; ?>
        <p class="perf">Loaded <?php echo number_format($d['scanned']); ?> bill<?php echo $d['scanned'] === 1 ? '' : 's'; ?> of risk customers in <?php echo number_format($d['ms'] / 1000, 2); ?> s.</p>
    <?php endif; ?>
</div>

<script>window.jQuery || document.write('<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"><\/script>');</script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function () {
    'use strict';
    var app = document.getElementById('rcbApp'), ENDPOINT = app.dataset.endpoint, CSRF = app.dataset.csrf;
    var REASONS = <?php echo json_encode($js_reasons, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var withReasonEl = document.getElementById('rcbWithReason');

    function post(action, data, stat, done) {
        stat.textContent = 'Saving…'; stat.className = 'rstat saving';
        var fd = new FormData(); fd.append('csrf', CSRF);
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        fetch(ENDPOINT + '?ajax=' + action, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (res) {
                if (res && res.success) {
                    stat.textContent = 'Saved'; stat.className = 'rstat saved';
                    setTimeout(function () { if (stat.textContent === 'Saved') stat.textContent = ''; }, 2000);
                    if (done) done(true);
                } else { stat.textContent = (res && res.error) || 'Could not save'; stat.className = 'rstat err'; if (done) done(false); }
            })
            .catch(function () { stat.textContent = 'Could not reach the server'; stat.className = 'rstat err'; if (done) done(false); });
    }

    /* ── Risk bill reason (Select2, switched on per row only when used) ── */
    function fillOptions(sel) {
        var cur = sel.value;
        while (sel.options.length > 1) sel.remove(1);
        REASONS.forEach(function (r) {
            if (!r.active && String(r.id) !== cur) return;
            var o = new Option(r.text + (r.active ? '' : ' (inactive)'), r.id, false, String(r.id) === cur);
            sel.add(o);
        });
    }
    function activate(sel, open) {
        if (sel.dataset.ready) return;
        sel.dataset.ready = '1';
        fillOptions(sel);
        if (window.jQuery && jQuery.fn.select2) {
            var $s = jQuery(sel);
            $s.select2({ width: '100%', placeholder: '— Select reason —', allowClear: true, dropdownAutoWidth: true });
            $s.on('change', function () { saveReason(sel); });
            /* open after the current mousedown finishes, otherwise Select2's own "click outside" handler closes it at once */
            if (open) setTimeout(function () { $s.select2('open'); }, 0);
        } else {
            sel.addEventListener('change', function () { saveReason(sel); });   /* Select2 could not load: plain dropdown still works */
        }
    }
    function saveReason(sel) {
        var stat = sel.parentElement.querySelector('.rstat');
        var before = sel.dataset.val || '0', after = sel.value || '0';
        if (before === after) return;
        post('save_reason', { date: sel.dataset.date, bill: sel.dataset.bill, reason_id: after }, stat, function (ok) {
            if (!ok) return;
            sel.dataset.val = after;
            if (withReasonEl) {
                var n = parseInt(withReasonEl.textContent.replace(/,/g, ''), 10) || 0;
                if (before === '0' && after !== '0') n++; else if (before !== '0' && after === '0') n--;
                withReasonEl.textContent = n.toLocaleString();
            }
        });
    }
    app.addEventListener('mousedown', function (e) {
        var sel = e.target.closest && e.target.closest('select.rsn-sel');
        if (sel && !sel.dataset.ready) { e.preventDefault(); activate(sel, true); }
    });
    app.addEventListener('focusin', function (e) {
        if (e.target.matches && e.target.matches('select.rsn-sel') && !e.target.dataset.ready) activate(e.target, false);
    });

    /* ── Reason (why this customer is risky): one call for all customers on the page ── */
    var rrStatus = document.getElementById('rcbRRStatus');
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function fillReasons(map) {
        app.querySelectorAll('td.blr').forEach(function (td) {
            var cr = map ? map[td.dataset.tc] : null, badge = td.querySelector('.cr-badge'), txt = td.querySelector('.cr-text');
            if (cr && cr.reason) {
                badge.innerHTML = cr.band ? '<span class="risk-badge risk-score ' + esc(cr.band) + '" title="From the Customer Risk report">' + esc(cr.band) + ' · ' + esc(cr.score) + '/100</span>' : '';
                txt.className = 'reason-text cr-text'; txt.textContent = cr.reason;
            } else {
                badge.innerHTML = td.dataset.bl === '1' ? '' : '<span class="risk-badge risk-flag">Risk flagged</span>';
                if (td.dataset.bl === '1' && td.dataset.blreason) { txt.className = 'reason-text cr-text'; txt.textContent = td.dataset.blreason; }
                else { txt.className = 'reason-text muted cr-text'; txt.textContent = map ? 'Not currently scored on the Customer Risk report (no balance outstanding), and no reason recorded here.' : 'Reason not available.'; }
            }
        });
    }
    function loadReasons(refresh) {
        if (!rrStatus) return;
        var tcs = {}; app.querySelectorAll('td.blr').forEach(function (td) { tcs[td.dataset.tc] = 1; });
        rrStatus.textContent = 'Loading reasons from the Customer Risk report…';
        var fd = new FormData(); fd.append('csrf', CSRF); fd.append('to', rrStatus.dataset.to); fd.append('t_codes', Object.keys(tcs).join(','));
        if (refresh) fd.append('refresh', '1');
        fetch(ENDPOINT + '?ajax=risk_reasons', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (res) {
                if (res && res.success) {
                    fillReasons(res.map || {});
                    rrStatus.innerHTML = 'Reasons from the Customer Risk report' + (res.at ? ', as at ' + esc(res.at) : '') + '.<button type="button" id="rcbRRRefresh">Refresh reasons</button>';
                } else {
                    fillReasons(null);
                    rrStatus.innerHTML = 'Could not load reasons from the Customer Risk report: ' + esc((res && res.error) || 'no answer') + '<button type="button" id="rcbRRRefresh">Try again</button>';
                }
                var b = document.getElementById('rcbRRRefresh'); if (b) b.addEventListener('click', function () { loadReasons(true); });
            })
            .catch(function () { fillReasons(null); rrStatus.textContent = 'Could not reach the server to load reasons.'; });
    }
    loadReasons(false);

    /* ── Remarks: saved as you type ── */
    var timers = new WeakMap();
    function autoGrow(t) { t.style.height = 'auto'; t.style.height = t.scrollHeight + 'px'; }
    app.querySelectorAll('.remarks-input').forEach(function (t) { t.dataset.saved = t.value; if (t.value) autoGrow(t); });
    function saveRemark(t) {
        if (t.value === t.dataset.saved) return;
        var val = t.value, stat = t.parentElement.querySelector('.rstat');
        post('save_remark', { date: t.dataset.date, bill: t.dataset.bill, remarks: val }, stat, function (ok) { if (ok) t.dataset.saved = val; });
    }
    app.addEventListener('input', function (e) {
        var t = e.target;
        if (!t.classList.contains('remarks-input')) return;
        autoGrow(t);
        clearTimeout(timers.get(t));
        timers.set(t, setTimeout(function () { saveRemark(t); }, 700));
    });
    app.addEventListener('blur', function (e) {
        var t = e.target;
        if (!t.classList || !t.classList.contains('remarks-input')) return;
        clearTimeout(timers.get(t));
        saveRemark(t);
    }, true);
})();
</script>

<?php include 'footer.php'; ?>