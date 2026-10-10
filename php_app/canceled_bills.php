<?php
/**
 * canceled_bills.php
 * ─────────────────────────────────────────────────────────────────────
 * CANCELED BILLS — delivery date wise
 * (menu: Daily Transaction Management → Canceled Bills)
 *
 * Lists every bill whose NET INVOICE VALUE is greater than its IKEA VALUE
 * by at least Rs. 10 (that covers both IKEA = 0 and IKEA lower than the
 * net invoice value). A bill with no net invoice value (0) is never listed
 * — so a bill where the net invoice value and the IKEA value are both 0 is
 * left out. Bills whose difference is under Rs. 10 are treated as noise
 * (rounding / negligible variance) and are left out too. A cancel reason
 * can be picked for each bill from the list kept in bill_cancel_reasons.php;
 * the choice is saved immediately.
 *
 * WHERE THE NUMBERS COME FROM (same rules as fs_se.php / edit_field_summary.php,
 * except this report compares against the NET INVOICE VALUE, not the Final B.V)
 *   Net invoice value = field_summary_details.net_value          ("Net Inv.Amt")
 *   IKEA value        = secondary_invoice_import_details.final_bill_amount,
 *                       looked up by (delivery date, bill no), status 'imported';
 *                       if the same bill was imported twice the LAST import wins.
 *                       No IKEA record for the bill counts as 0.
 *   Delivery date     = the bill's To-Be-Delivery date if it has one, otherwise
 *                       its field summary's delivery date.
 *
 * MINIMUM DIFFERENCE
 *   Only bills where (net invoice value − IKEA value) is Rs. 10 or more are
 *   shown. Anything below that is not counted as a canceled bill at all —
 *   it is not listed, and it is not included in any of the summary boxes,
 *   tab counts, or per-date totals.
 *
 * DATES WITHOUT IKEA DATA ARE SKIPPED
 *   If no IKEA bills were imported at all for a date, every bill on that date
 *   would look canceled. Those dates are left out and listed in a notice.
 *
 * HOW IT STAYS FAST
 *   The first version joined the IKEA table inside SQL through TRIM()/IF()
 *   expressions, which stops MySQL using any index and made the time grow
 *   far faster than the amount of data (3 weeks of bills took over a minute).
 *   Now:  1) the IKEA bills of the date range are read once into memory,
 *         2) the range's bills are read with two index-friendly queries,
 *         3) they are compared in PHP (exactly like fs_se.php does),
 *         4) only the rows of the page being viewed are looked up further.
 *   Two small indexes are added automatically the first time the page opens
 *   (secondary_invoice_import_details.delivery_date and
 *   field_summary_details.to_be_delivery_date) — they only speed up reads.
 *
 * The chosen reason is stored in canceled_bill_reasons (one row per
 * field_summary_details row); nothing in the existing tables is changed
 * apart from the two indexes above.
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');

/* config.php ends with stray whitespace after "?>" – swallow it so headers / JSON stay clean */
ob_start();
include_once 'config.php';
ob_end_clean();
include_once 'auth.php';

const CBL_PER_PAGE = 100;    /* bills per page */
const CBL_MAX_DAYS = 366;    /* longest date range */
const CBL_MIN_DIFF_CENTS = 1000;   /* Rs. 10 — bills below this difference are not treated as canceled */

/* ═══════════════════════════ HELPERS ═══════════════════════════ */

function cbl_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* PHP 8.1+ throws on a failed query, older PHP returns false — behave the same on both */
$cbl_db_error = '';
function cbl_q($conn, $sql, $mode = MYSQLI_STORE_RESULT) {
    global $cbl_db_error;
    try {
        $r = mysqli_query($conn, $sql, $mode);
        if ($r === false) $cbl_db_error = mysqli_error($conn);
        return $r;
    } catch (Throwable $e) {
        $cbl_db_error = $e->getMessage();
        return false;
    }
}

function cbl_json($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function cbl_valid_date($d) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}
function cbl_get($key, $default = '') { return (isset($_GET[$key]) && is_string($_GET[$key])) ? trim($_GET[$key]) : $default; }
function cbl_money($n) { return number_format((float)$n, 2); }
function cbl_cents($c) { return number_format($c / 100, 2); }          /* integer cents → 1,234.50 */
function cbl_date($d, $fmt = 'd M Y') { $t = strtotime((string)$d); return $t ? date($fmt, $t) : ''; }

function cbl_table_exists($conn, $t) {
    $r = cbl_q($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'");
    return $r && mysqli_num_rows($r) > 0;
}

/* add a single-column index if no index starts with that column yet.
   ALGORITHM=INPLACE, LOCK=NONE keeps the table readable/writable while it builds;
   if the server cannot do that the statement simply fails and the page still works (just slower). */
function cbl_ensure_index($conn, $table, $name, $col) {
    $r = cbl_q($conn, "SHOW INDEX FROM `$table`");
    if (!$r) return;
    while ($i = mysqli_fetch_assoc($r)) {
        if ((int)$i['Seq_in_index'] === 1 && $i['Column_name'] === $col) return;
    }
    cbl_q($conn, "ALTER TABLE `$table` ADD INDEX `$name` (`$col`), ALGORITHM=INPLACE, LOCK=NONE");
}

/* same table definitions bill_cancel_reasons.php uses + the two To-Be-Delivery
   columns fs_se.php / edit_field_summary.php add when they are missing */
function cbl_ensure_schema($conn) {
    cbl_q($conn, "CREATE TABLE IF NOT EXISTS bill_cancel_reasons (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        reason     VARCHAR(255) NOT NULL,
        active     TINYINT(1)   NOT NULL DEFAULT 1,
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    cbl_q($conn, "CREATE TABLE IF NOT EXISTS canceled_bill_reasons (
        id                      INT AUTO_INCREMENT PRIMARY KEY,
        field_summary_detail_id INT          NOT NULL,
        invoice_num             VARCHAR(100) NOT NULL,
        reason_id               INT          NOT NULL,
        reason_text             VARCHAR(255) NOT NULL,
        updated_by              VARCHAR(100) NULL,
        updated_at              DATETIME     NOT NULL,
        UNIQUE KEY uq_detail (field_summary_detail_id),
        INDEX idx_reason (reason_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (cbl_table_exists($conn, 'field_summary_details')) {
        $cols = [
            'to_be_delivery'      => "ALTER TABLE field_summary_details ADD COLUMN to_be_delivery TINYINT(1) NOT NULL DEFAULT 0",
            'to_be_delivery_date' => "ALTER TABLE field_summary_details ADD COLUMN to_be_delivery_date DATE NULL DEFAULT NULL",
        ];
        foreach ($cols as $col => $alter) {
            $c = cbl_q($conn, "SHOW COLUMNS FROM field_summary_details LIKE '$col'");
            if ($c && mysqli_num_rows($c) === 0) cbl_q($conn, $alter);
        }
        cbl_ensure_index($conn, 'field_summary_details', 'idx_cbl_tbd_date', 'to_be_delivery_date');
    }
    if (cbl_table_exists($conn, 'secondary_invoice_import_details')) {
        cbl_ensure_index($conn, 'secondary_invoice_import_details', 'idx_cbl_delivery_date', 'delivery_date');
    }
}

/* ═══════════════════════════ FILTERS ═══════════════════════════ */

function cbl_filters() {
    $today = date('Y-m-d');
    $from  = array_key_exists('from', $_GET) ? cbl_get('from') : $today;
    $to    = array_key_exists('to',   $_GET) ? cbl_get('to')   : $today;
    if (!cbl_valid_date($from)) $from = $today;      /* same behaviour as fs_se.php */
    if (!cbl_valid_date($to))   $to   = $today;
    if ($from > $to) { $t = $from; $from = $to; $to = $t; }

    $limited = false;
    if ((new DateTime($from))->diff(new DateTime($to))->days >= CBL_MAX_DAYS) {
        $from    = date('Y-m-d', strtotime($to . ' -' . (CBL_MAX_DAYS - 1) . ' days'));
        $limited = true;
    }

    $reason = cbl_get('reason', 'all');
    if (!in_array($reason, ['all', 'none', 'given'], true)) $reason = 'all';
    $type = cbl_get('type', 'all');
    if (!in_array($type, ['all', 'zero', 'below'], true)) $type = 'all';
    $q = cbl_get('q');
    if (strlen($q) > 100) $q = substr($q, 0, 100);

    return ['from' => $from, 'to' => $to, 'reason' => $reason, 'type' => $type, 'q' => $q,
            'page' => max(1, (int)cbl_get('page', '1')), 'limited' => $limited];
}

function cbl_url($f, $over = []) {
    $p = array_merge(['from' => $f['from'], 'to' => $f['to'], 'reason' => $f['reason'], 'type' => $f['type'], 'q' => $f['q'], 'page' => $f['page']], $over);
    if ($p['reason'] === 'all') unset($p['reason']);
    if ($p['type'] === 'all')   unset($p['type']);
    if ($p['q'] === '')         unset($p['q']);
    if ((int)$p['page'] <= 1)   unset($p['page']);
    return basename(__FILE__) . '?' . http_build_query($p);
}

/* ═══════════════════════════ DATA ═══════════════════════════ */

function cbl_contains($hay, $needle) {
    return function_exists('mb_stripos') ? mb_stripos((string)$hay, $needle) !== false : stripos((string)$hay, $needle) !== false;
}

function cbl_load($conn, $f) {
    global $cbl_db_error;
    $t0  = microtime(true);
    $out = ['rows' => [], 'sum' => ['total' => 0, 'final' => 0, 'diff' => 0, 'given' => 0, 'none' => 0],
            'types' => ['all' => 0, 'zero' => 0, 'below' => 0], 'by_date' => [],
            'unchecked' => [], 'checked' => 0, 'dates' => 0, 'page' => 1, 'pages' => 1, 'count' => 0,
            'ms' => 0, 'error' => '', 'no_data' => false];

    if (!cbl_table_exists($conn, 'field_summary_details') || !cbl_table_exists($conn, 'secondary_invoice_import_details')) {
        $out['no_data'] = true;
        return $out;
    }
    $from = $f['from'];     /* both validated YYYY-MM-DD */
    $to   = $f['to'];

    /* 1 ── IKEA bills for the range, read once. Later imports overwrite earlier ones (ORDER BY id) = "last wins". */
    $ikea = [];             /* [date][bill_no] => IKEA value in cents */
    $r = cbl_q($conn, "SELECT delivery_date, bill_no, final_bill_amount
                       FROM secondary_invoice_import_details
                       WHERE status = 'imported' AND delivery_date BETWEEN '$from' AND '$to'
                       ORDER BY id", MYSQLI_USE_RESULT);
    if (!$r) { $out['error'] = $cbl_db_error; return $out; }
    while ($row = mysqli_fetch_row($r)) {
        $ikea[$row[0]][trim((string)$row[1])] = (int)round(((float)$row[2]) * 100);
    }
    mysqli_free_result($r);

    /* 2 ── the range's bills. Two parts so each can use an index:
            normal bills by their field summary's date, moved (To-Be-Delivery) bills by their own date.
            d.net_value is the "Net Inv.Amt" column — the value this report compares against IKEA
            (NOT d.adjust_net_value / "Final B.V"). */
    $r = cbl_q($conn, "(SELECT d.id, d.invoice_num, d.net_value, fs.delivery_date, fs.field_summary_code, d.t_code, d.customer_name
                        FROM field_summary fs
                        INNER JOIN field_summary_details d ON d.field_summary_id = fs.id
                        WHERE fs.delivery_date BETWEEN '$from' AND '$to'
                          AND NOT (d.to_be_delivery = 1 AND d.to_be_delivery_date IS NOT NULL))
                       UNION ALL
                       (SELECT d.id, d.invoice_num, d.net_value, d.to_be_delivery_date, fs.field_summary_code, d.t_code, d.customer_name
                        FROM field_summary_details d
                        INNER JOIN field_summary fs ON fs.id = d.field_summary_id
                        WHERE d.to_be_delivery = 1 AND d.to_be_delivery_date BETWEEN '$from' AND '$to')", MYSQLI_USE_RESULT);
    if (!$r) { $out['error'] = $cbl_db_error; return $out; }

    /* 3 ── compare. Money is compared in whole cents so rounding never creates a false difference.
            Only kept when the net invoice value is above the IKEA value by at least
            CBL_MIN_DIFF_CENTS (Rs. 10) — smaller gaps are not treated as canceled bills. */
    $cands = [];            /* id, date, fs code, invoice, net_invoice¢, ikea¢, ikea found?, t_code, customer_name */
    $unchecked = [];
    $seen_dates = [];
    while ($row = mysqli_fetch_row($r)) {
        $out['checked']++;
        $eff = $row[3];
        $seen_dates[$eff] = true;

        $final = (int)round(((float)$row[2]) * 100);      /* net invoice value, in cents */
        if ($final <= 0) continue;                       /* no net invoice value → nothing to compare (also drops "net invoice 0 and IKEA 0" bills) */
        if (!isset($ikea[$eff])) { $unchecked[$eff] = ($unchecked[$eff] ?? 0) + 1; continue; }   /* no IKEA import that day → cannot judge */

        $ic    = $ikea[$eff][trim((string)$row[1])] ?? null;
        $iv    = $ic === null ? 0 : $ic;
        if ($final > $iv) {                              /* net invoice value above the IKEA value (this includes IKEA = 0) */
            $diff = $final - $iv;
            if ($diff < CBL_MIN_DIFF_CENTS) continue;    /* difference under Rs. 10 — not counted as canceled */
            $cands[] = [(int)$row[0], $eff, (string)$row[4], (string)$row[1], $final, $iv, $ic !== null, (string)$row[5], (string)$row[6]];
        }
    }
    mysqli_free_result($r);
    ksort($unchecked);
    $out['unchecked'] = $unchecked;
    $out['dates']     = count($seen_dates);

    /* 4 ── reasons already chosen for these bills */
    $reason = [];           /* detail id => [reason_id, text, updated_by, updated_at] */
    $ids = array_column($cands, 0);
    foreach (array_chunk($ids, 1000) as $chunk) {
        $rs = cbl_q($conn, "SELECT cbr.field_summary_detail_id, cbr.invoice_num, cbr.reason_id, COALESCE(r.reason, cbr.reason_text), cbr.updated_by, cbr.updated_at
                            FROM canceled_bill_reasons cbr LEFT JOIN bill_cancel_reasons r ON r.id = cbr.reason_id
                            WHERE cbr.field_summary_detail_id IN (" . implode(',', $chunk) . ")");
        if ($rs) while ($m = mysqli_fetch_row($rs)) $reason[(int)$m[0]] = [(string)$m[1], (int)$m[2], (string)$m[3], (string)$m[4], (string)$m[5]];
    }

    /* 5 ── search (needs the customer's shop name, so only looked up when a search is typed) */
    if ($f['q'] !== '') {
        $shop = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rs = cbl_q($conn, "SELECT d.id, c.shop_name FROM field_summary_details d
                                LEFT JOIN customers c ON c.t_code = d.t_code WHERE d.id IN (" . implode(',', $chunk) . ")");
            if ($rs) while ($m = mysqli_fetch_row($rs)) $shop[(int)$m[0]] = (string)$m[1];
        }
        $needle = $f['q'];
        $cands = array_values(array_filter($cands, function ($c) use ($needle, $shop) {
            return cbl_contains($c[3], $needle) || cbl_contains($c[7], $needle) || cbl_contains($c[8], $needle)
                || cbl_contains($c[2], $needle) || cbl_contains($shop[$c[0]] ?? '', $needle);
        }));
    }

    /* 6 ── counts by type, then the type filter */
    foreach ($cands as $c) { $out['types']['all']++; $out['types'][$c[5] === 0 ? 'zero' : 'below']++; }
    if ($f['type'] !== 'all') {
        $want = $f['type'];
        $cands = array_values(array_filter($cands, function ($c) use ($want) { return ($c[5] === 0 ? 'zero' : 'below') === $want; }));
    }

    /* summary boxes: every bill left after search + type (the reason tabs only change what is listed) */
    foreach ($cands as $c) {
        $out['sum']['total']++;
        $out['sum']['final'] += $c[4];
        $out['sum']['diff']  += $c[4] - $c[5];
        $has = isset($reason[$c[0]]) && $reason[$c[0]][0] === $c[3];      /* a saved reason only counts for the same invoice no */
        $out['sum'][$has ? 'given' : 'none']++;
    }

    /* 7 ── reason tab, order, per-date totals, page */
    if ($f['reason'] !== 'all') {
        $wantGiven = $f['reason'] === 'given';
        $cands = array_values(array_filter($cands, function ($c) use ($reason, $wantGiven) {
            $has = isset($reason[$c[0]]) && $reason[$c[0]][0] === $c[3];
            return $has === $wantGiven;
        }));
    }
    usort($cands, function ($a, $b) {
        return strcmp($a[1], $b[1]) ?: strcasecmp($a[2], $b[2]) ?: strcasecmp($a[3], $b[3]);
    });
    foreach ($cands as $c) {
        if (!isset($out['by_date'][$c[1]])) $out['by_date'][$c[1]] = ['n' => 0, 'diff' => 0];
        $out['by_date'][$c[1]]['n']++;
        $out['by_date'][$c[1]]['diff'] += $c[4] - $c[5];
    }
    $out['count'] = count($cands);
    $out['pages'] = max(1, (int)ceil($out['count'] / CBL_PER_PAGE));
    $out['page']  = min($f['page'], $out['pages']);
    $slice = array_slice($cands, ($out['page'] - 1) * CBL_PER_PAGE, CBL_PER_PAGE);

    /* 8 ── details for just the bills on this page (route, SR, customer name) */
    $info = [];
    if ($slice) {
        $idlist = implode(',', array_column($slice, 0));
        foreach ([true, false] as $joins) {      /* second attempt without the name/route lookups, if those joins are not possible */
            $rs = cbl_q($conn, $joins
                ? "SELECT d.id, d.field_summary_id, fs.sr_code, COALESCE(rt.route_name, fs.route),
                          COALESCE(NULLIF(d.customer_name,''), c.shop_name, d.t_code), d.to_be_delivery
                   FROM field_summary_details d
                   INNER JOIN field_summary fs ON fs.id = d.field_summary_id
                   LEFT JOIN customers c ON c.t_code = d.t_code
                   LEFT JOIN routes rt   ON rt.route_code = fs.route
                   WHERE d.id IN ($idlist)"
                : "SELECT d.id, d.field_summary_id, fs.sr_code, fs.route, COALESCE(NULLIF(d.customer_name,''), d.t_code), d.to_be_delivery
                   FROM field_summary_details d INNER JOIN field_summary fs ON fs.id = d.field_summary_id
                   WHERE d.id IN ($idlist)");
            if ($rs) { while ($m = mysqli_fetch_row($rs)) $info[(int)$m[0]] = $m; break; }
        }
    }
    foreach ($slice as $c) {
        $m   = $info[$c[0]] ?? [0, 0, '', '', $c[7], 0];
        $rsn = (isset($reason[$c[0]]) && $reason[$c[0]][0] === $c[3]) ? $reason[$c[0]] : null;
        $out['rows'][] = [
            'detail_id' => $c[0], 'eff_date' => $c[1], 'field_summary_code' => $c[2], 'invoice_num' => $c[3],
            'final_cents' => $c[4], 'ikea_cents' => $c[5], 'ikea_found' => $c[6], 't_code' => $c[7],
            'type' => $c[5] === 0 ? 'zero' : 'below',
            'field_summary_id' => (int)$m[1], 'sr_code' => $m[2], 'route_name' => $m[3], 'customer_name' => $m[4], 'to_be_delivery' => (int)$m[5],
            'reason_id' => $rsn ? $rsn[1] : 0, 'reason_text' => $rsn ? $rsn[2] : '', 'updated_by' => $rsn ? $rsn[3] : '', 'updated_at' => $rsn ? $rsn[4] : '',
        ];
    }

    $out['ms'] = (int)round((microtime(true) - $t0) * 1000);
    return $out;
}

function cbl_active_reasons($conn) {
    $list = [];
    $r = cbl_q($conn, "SELECT id, reason FROM bill_cancel_reasons WHERE active = 1 ORDER BY reason ASC");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $list[] = $row;
    return $list;
}

/* ═══════════════════════════ AJAX: save one reason ═══════════════════════════ */

$cbl_ajax = cbl_get('ajax');
if ($cbl_ajax !== '') {
    if (!isLoggedIn() && !autoLoginFromCookie()) {
        cbl_json(['success' => false, 'error' => 'Your session has expired. Reload the page and sign in again.'], 401);
    }
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['cbl_csrf'])) $_SESSION['cbl_csrf'] = bin2hex(random_bytes(16));
    cbl_ensure_schema($conn);

    if ($cbl_ajax === 'save_reason') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') cbl_json(['success' => false, 'error' => 'POST only.'], 405);
        if (!hash_equals((string)$_SESSION['cbl_csrf'], (string)($_POST['csrf'] ?? ''))) {
            cbl_json(['success' => false, 'error' => 'Security check failed. Reload the page and try again.'], 403);
        }

        /* ids must be plain digits (reason_id may be empty = clear the reason) */
        $detail_raw = trim((string)($_POST['detail_id'] ?? ''));
        $reason_raw = trim((string)($_POST['reason_id'] ?? ''));
        if (!ctype_digit($detail_raw) || ($reason_raw !== '' && !ctype_digit($reason_raw))) {
            cbl_json(['success' => false, 'error' => 'The request was not valid. Reload the page and try again.'], 422);
        }
        $detail_id = (int)$detail_raw;
        $reason_id = (int)$reason_raw;                        /* 0 = clear the reason */

        $d = $detail_id > 0 ? cbl_q($conn, "SELECT id, invoice_num FROM field_summary_details WHERE id = $detail_id LIMIT 1") : false;
        $detail = $d ? mysqli_fetch_assoc($d) : null;
        if (!$detail) cbl_json(['success' => false, 'error' => 'This bill was not found. It may have been deleted. Reload the page.'], 404);

        $by = (string)($_SESSION['username'] ?? 'system');

        /* clear */
        if ($reason_id === 0) {
            cbl_q($conn, "DELETE FROM canceled_bill_reasons WHERE field_summary_detail_id = $detail_id");
            cbl_json(['success' => true, 'reason_id' => 0, 'reason_text' => '', 'updated_by' => $by, 'updated_at' => '']);
        }

        $r = cbl_q($conn, "SELECT id, reason, active FROM bill_cancel_reasons WHERE id = $reason_id LIMIT 1");
        $reason = $r ? mysqli_fetch_assoc($r) : null;
        if (!$reason) cbl_json(['success' => false, 'error' => 'That reason no longer exists. Reload the page.'], 404);

        if ((int)$reason['active'] !== 1) {          /* inactive reasons can stay where they are, not be newly picked */
            $cur = cbl_q($conn, "SELECT reason_id FROM canceled_bill_reasons WHERE field_summary_detail_id = $detail_id LIMIT 1");
            $curRow = $cur ? mysqli_fetch_assoc($cur) : null;
            if (!$curRow || (int)$curRow['reason_id'] !== $reason_id) {
                cbl_json(['success' => false, 'error' => 'That reason is inactive. Pick an active reason.'], 422);
            }
        }

        try {
            $st = mysqli_prepare($conn, "INSERT INTO canceled_bill_reasons
                        (field_summary_detail_id, invoice_num, reason_id, reason_text, updated_by, updated_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE invoice_num = VALUES(invoice_num), reason_id = VALUES(reason_id),
                                                reason_text = VALUES(reason_text), updated_by = VALUES(updated_by), updated_at = NOW()");
            $inv = (string)$detail['invoice_num']; $txt = (string)$reason['reason'];
            mysqli_stmt_bind_param($st, 'isiss', $detail_id, $inv, $reason_id, $txt, $by);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        } catch (Throwable $e) {
            cbl_json(['success' => false, 'error' => 'The reason could not be saved. Try again.'], 500);
        }
        /* return the stored time so it matches what the page shows after a reload */
        $ts = cbl_q($conn, "SELECT updated_at FROM canceled_bill_reasons WHERE field_summary_detail_id = $detail_id LIMIT 1");
        $tsRow = $ts ? mysqli_fetch_assoc($ts) : null;
        cbl_json(['success' => true, 'reason_id' => $reason_id, 'reason_text' => $txt, 'updated_by' => $by,
                  'updated_at' => $tsRow ? cbl_date($tsRow['updated_at'], 'd M Y, H:i') : '']);
    }

    cbl_json(['success' => false, 'error' => 'Unknown request.'], 400);
}


/* ═══════════════════════════ NORMAL PAGE ═══════════════════════════ */

include 'header.php';            /* requires login */

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['cbl_csrf'])) $_SESSION['cbl_csrf'] = bin2hex(random_bytes(16));

cbl_ensure_schema($conn);
$f       = cbl_filters();
$data    = cbl_load($conn, $f);
$reasons = cbl_active_reasons($conn);
$s       = $data['sum'];
$total   = (int)$s['total'];

$today = date('Y-m-d');
$quick = [
    'Today'       => [$today, $today],
    'Yesterday'   => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    'Last 7 days' => [date('Y-m-d', strtotime('-6 days')), $today],
    'This month'  => [date('Y-m-01'), $today],
    'Last month'  => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
];
?>
<style>
.cbl, .cbl *, .cbl *::before, .cbl *::after { box-sizing: border-box; }
.cbl { --ink: #111827; --muted: #6b7280; --line: #e5e7eb; --red: #c81e1e; --red-soft: #fdecec; --amber: #92400e; --amber-soft: #fef3c7; --green: #157f3d;
       font-family: 'Inter', system-ui, sans-serif; font-size: 13px; line-height: 1.45; color: var(--ink); background: #f4f5f8; padding: 22px 26px 48px; min-height: 100%; }
.cbl h1 { margin: 0; font-size: 21px; font-weight: 700; }
.cbl .sub { margin: 4px 0 16px; color: var(--muted); max-width: 70ch; }
.cbl .muted { color: var(--muted); font-size: 12px; }
.cbl .card { background: #fff; border: 1px solid var(--line); border-radius: 10px; }
.cbl .filter { padding: 14px 16px; margin-bottom: 14px; }
.cbl .filter form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; }
.cbl .fld { display: flex; flex-direction: column; gap: 4px; }
.cbl .fld label { font-size: 12px; font-weight: 600; color: var(--muted); }
.cbl input[type="date"], .cbl input[type="search"] { height: 36px; padding: 0 10px; border: 1px solid #d1d5db; border-radius: 7px; font: inherit; color: var(--ink); background: #fff; }
.cbl input[type="search"] { width: 300px; max-width: 100%; }
.cbl .btn { display: inline-flex; align-items: center; height: 36px; padding: 0 16px; border: 1px solid transparent; border-radius: 7px; background: #111827; color: #fff; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
.cbl .chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--line); }
.cbl .chips a { padding: 4px 11px; border: 1px solid var(--line); border-radius: 999px; font-size: 12px; text-decoration: none; color: var(--muted); background: #fff; }
.cbl .chips a.on { background: #111827; border-color: #111827; color: #fff; }
.cbl a:focus-visible, .cbl .btn:focus-visible, .cbl select:focus-visible, .cbl input:focus-visible { outline: 2px solid var(--red); outline-offset: 2px; }
.cbl .note { margin: 0 0 12px; padding: 9px 12px; border-radius: 8px; background: var(--amber-soft); border: 1px solid #f5d68a; color: var(--amber); }
.cbl .note a { color: inherit; font-weight: 600; }
.cbl .kpis { display: grid; grid-template-columns: 1.4fr 1fr 1fr; gap: 12px; margin-bottom: 16px; }
.cbl .kpi { padding: 14px 16px; }
.cbl .kpi.lead { border-color: #f3c1c1; background: var(--red-soft); }
.cbl .kpi .l { font-size: 12px; font-weight: 600; color: var(--muted); }
.cbl .kpi.lead .l { color: #a51717; }
.cbl .kpi .v { font-size: 26px; font-weight: 700; line-height: 1.2; margin-top: 3px; font-variant-numeric: tabular-nums; }
.cbl .kpi.lead .v { font-size: 36px; color: var(--red); }
.cbl .tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--line); margin-bottom: 12px; }
.cbl .tabs a { padding: 9px 14px; font-weight: 600; color: var(--muted); text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.cbl .tabs a span { margin-left: 4px; padding: 1px 7px; border-radius: 999px; background: #e5e7eb; color: #374151; font-size: 11.5px; font-variant-numeric: tabular-nums; }
.cbl .tabs a.on { color: var(--ink); border-bottom-color: var(--ink); }
.cbl .wrap { overflow-x: auto; }
.cbl table { width: 100%; min-width: 900px; border-collapse: collapse; }
.cbl th { padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 600; color: var(--muted); background: #f9fafb; border-bottom: 1px solid var(--line); white-space: nowrap; }
.cbl td { padding: 10px 12px; border-bottom: 1px solid #f0f1f4; vertical-align: top; }
.cbl .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.cbl tr.dt td { padding: 9px 12px; background: #f3f4f6; border-top: 1px solid var(--line); font-weight: 700; }
.cbl tr.dt td span { font-weight: 500; color: var(--muted); margin-left: 8px; }
.cbl tr.row.none td:first-child { box-shadow: inset 3px 0 0 var(--red); }
.cbl tr.row:hover td { background: #fafafb; }
.cbl .strong { font-weight: 600; }
.cbl td a.strong { color: inherit; text-decoration: none; white-space: nowrap; }
.cbl td a.strong:hover { text-decoration: underline; }
.cbl .tag { display: inline-block; margin-top: 3px; padding: 1px 8px; border-radius: 999px; background: var(--amber-soft); color: var(--amber); border: 1px solid #f5d68a; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
.cbl select.rs { width: 100%; min-width: 210px; max-width: 280px; height: 34px; padding: 0 8px; border: 1px solid #d1d5db; border-radius: 7px; font: inherit; background: #fff; color: var(--ink); }
.cbl select.rs:disabled { opacity: .6; }
.cbl .st { display: block; min-height: 16px; margin-top: 3px; font-size: 12px; }
.cbl .st.ok { color: var(--green); }
.cbl .st.err { color: var(--red); font-weight: 600; }
.cbl .empty { padding: 34px 16px; text-align: center; }
.cbl .empty p { margin: 6px 0 0; color: var(--muted); }
.cbl .alert { padding: 14px 16px; border-radius: 10px; background: var(--red-soft); border: 1px solid #f3c1c1; color: #7f1d1d; word-break: break-word; }
.cbl .toast { position: fixed; left: 50%; bottom: 28px; transform: translateX(-50%); z-index: 2200; max-width: min(90vw, 520px); padding: 10px 16px; border-radius: 8px; background: var(--red); color: #fff; font-weight: 500; box-shadow: 0 8px 24px rgba(0,0,0,.25); }
.cbl .toast[hidden] { display: none; }
@media (max-width: 800px) { .cbl .kpis { grid-template-columns: 1fr 1fr; } .cbl .kpi.lead { grid-column: 1 / -1; } }
@media (max-width: 560px) { .cbl { padding: 16px 12px 40px; } .cbl input[type="search"] { width: 100%; } .cbl .fld { flex: 1 1 140px; } }
.cbl .fld select { height: 36px; padding: 0 8px; border: 1px solid #d1d5db; border-radius: 7px; font: inherit; color: var(--ink); background: #fff; max-width: 260px; }
.cbl .pager { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 12px; color: var(--muted); }
.cbl .pager .nav { display: inline-flex; align-items: center; gap: 10px; }
.cbl .pager a { display: inline-flex; align-items: center; height: 30px; padding: 0 12px; border: 1px solid #d1d5db; border-radius: 7px; background: #fff; color: var(--ink); font-weight: 600; text-decoration: none; }
.cbl .perf { margin: 10px 0 0; font-size: 12px; color: var(--muted); }
.cbl .kpi .muted + .muted { margin-top: 1px; }
</style>

<div class="cbl" id="cblApp" data-endpoint="<?php echo cbl_h(basename(__FILE__)); ?>" data-csrf="<?php echo cbl_h($_SESSION['cbl_csrf']); ?>">
    <h1>Canceled bills</h1>
    <p class="sub">Bills whose net invoice value is above the IKEA value by at least Rs. 10, including bills with an IKEA value of 0, by delivery date. Pick a cancel reason for each bill; it saves as soon as you choose.</p>

    <section class="card filter" aria-label="Filters">
        <form method="get" action="<?php echo cbl_h(basename(__FILE__)); ?>">
            <input type="hidden" name="reason" value="<?php echo cbl_h($f['reason']); ?>">
            <div class="fld"><label for="cblFrom">Delivery date from</label><input type="date" id="cblFrom" name="from" value="<?php echo cbl_h($f['from']); ?>" required></div>
            <div class="fld"><label for="cblTo">Delivery date to</label><input type="date" id="cblTo" name="to" value="<?php echo cbl_h($f['to']); ?>" required></div>
            <div class="fld">
                <label for="cblType">Show</label>
                <select id="cblType" name="type">
                    <option value="all"<?php echo $f['type'] === 'all' ? ' selected' : ''; ?>>All bills (<?php echo number_format($data['types']['all']); ?>)</option>
                    <option value="zero"<?php echo $f['type'] === 'zero' ? ' selected' : ''; ?>>IKEA value is 0 (<?php echo number_format($data['types']['zero']); ?>)</option>
                    <option value="below"<?php echo $f['type'] === 'below' ? ' selected' : ''; ?>>IKEA lower than net invoice value, not 0 (<?php echo number_format($data['types']['below']); ?>)</option>
                </select>
            </div>
            <div class="fld"><label for="cblQ">Search</label><input type="search" id="cblQ" name="q" value="<?php echo cbl_h($f['q']); ?>" placeholder="Invoice, customer, T-code or FS code" maxlength="100"></div>
            <button type="submit" class="btn">Apply filters</button>
        </form>
        <div class="chips" aria-label="Quick delivery date ranges">
            <?php foreach ($quick as $label => $range): ?>
                <a href="<?php echo cbl_h(cbl_url($f, ['from' => $range[0], 'to' => $range[1], 'page' => 1])); ?>" class="<?php echo ($f['from'] === $range[0] && $f['to'] === $range[1]) ? 'on' : ''; ?>"><?php echo cbl_h($label); ?></a>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($f['limited']): ?>
        <p class="note">The date range is limited to <?php echo CBL_MAX_DAYS; ?> days. Showing <?php echo cbl_h(cbl_date($f['from'])); ?> to <?php echo cbl_h(cbl_date($f['to'])); ?>.</p>
    <?php endif; ?>

    <?php if ($data['no_data']): ?>
        <div class="card empty"><strong>There is no data to check yet.</strong><p>Canceled bills need field summaries and imported IKEA (secondary invoice) bills.</p></div>
    <?php elseif ($data['error'] !== ''): ?>
        <div class="alert" role="alert"><strong>The report could not be loaded.</strong> Database message: <?php echo cbl_h($data['error']); ?></div>
    <?php else: ?>

        <?php if ($data['unchecked']):
            $shown = array_slice($data['unchecked'], 0, 8, true);
            $parts = [];
            foreach ($shown as $d => $n) $parts[] = cbl_h(cbl_date($d)) . ' (' . (int)$n . ' bill' . ((int)$n === 1 ? '' : 's') . ')';
            $more  = count($data['unchecked']) - count($shown); ?>
            <p class="note"><strong>Not checked:</strong> no IKEA bills have been imported for <?php echo implode(', ', $parts); ?><?php echo $more > 0 ? ' and ' . $more . ' more date' . ($more === 1 ? '' : 's') : ''; ?>. Bills on those dates are left out until the IKEA bills are imported.</p>
        <?php endif; ?>

        <?php if (!$reasons): ?>
            <p class="note">There are no cancel reasons to choose from yet. <a href="bill_cancel_reasons.php">Add reasons in Bill Cancel Reasons</a>.</p>
        <?php endif; ?>

        <section class="kpis" aria-label="Summary for the selected filters">
            <div class="card kpi lead">
                <div class="l">Bills listed</div>
                <div class="v"><span data-k="total"><?php echo number_format($total); ?></span></div>
                <div class="muted" style="color:#7f1d1d">Rs. <?php echo cbl_cents($s['diff']); ?> difference</div>
                <div class="muted" style="color:#7f1d1d">Net Invoice Rs. <?php echo cbl_cents($s['final']); ?></div>
            </div>
            <div class="card kpi"><div class="l">Reason given</div><div class="v" data-k="given"><?php echo number_format((int)$s['given']); ?></div></div>
            <div class="card kpi"><div class="l">No reason yet</div><div class="v" data-k="none"><?php echo number_format((int)$s['none']); ?></div></div>
        </section>

        <nav class="tabs" aria-label="Reason status">
            <?php foreach (['all' => ['All', 'total'], 'none' => ['No reason yet', 'none'], 'given' => ['Reason given', 'given']] as $key => $t): ?>
                <a href="<?php echo cbl_h(cbl_url($f, ['reason' => $key, 'page' => 1])); ?>" class="<?php echo $f['reason'] === $key ? 'on' : ''; ?>"<?php echo $f['reason'] === $key ? ' aria-current="page"' : ''; ?>><?php echo cbl_h($t[0]); ?> <span data-k="<?php echo $t[1]; ?>"><?php echo number_format((int)$s[$t[1]]); ?></span></a>
            <?php endforeach; ?>
        </nav>

        <?php if (!$data['rows']): ?>
            <div class="card empty">
                <strong>No bills found.</strong>
                <p><?php echo $f['reason'] === 'none' ? 'Every bill in this period has a reason.' : 'No bill in this period has a net invoice value at least Rs. 10 above its IKEA value.'; ?></p>
            </div>
        <?php else:
            $active_ids = [];
            foreach ($reasons as $x) $active_ids[(int)$x['id']] = true;
            $last_date = null; ?>
        <div class="card wrap">
            <table>
                <thead>
                    <tr>
                        <th>Field summary</th><th>Invoice</th><th>Customer</th>
                        <th class="num">Net Invoice</th><th class="num">IKEA value</th><th class="num">Difference</th>
                        <th>Cancel reason</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($data['rows'] as $r):
                    if ($r['eff_date'] !== $last_date):
                        $last_date = $r['eff_date']; $g = $data['by_date'][$last_date]; ?>
                    <tr class="dt"><td colspan="7"><?php echo cbl_h(cbl_date($last_date, 'D, d M Y')); ?><span><?php echo (int)$g['n']; ?> bill<?php echo $g['n'] === 1 ? '' : 's'; ?>, difference Rs. <?php echo cbl_cents($g['diff']); ?></span></td></tr>
                    <?php endif;
                    $rid = (int)$r['reason_id']; ?>
                    <tr class="row <?php echo $rid ? 'given' : 'none'; ?>">
                        <td>
                            <a class="strong" href="edit_field_summary.php?id=<?php echo (int)$r['field_summary_id']; ?>" target="_blank" rel="noopener" title="Open this field summary"><?php echo cbl_h($r['field_summary_code']); ?></a>
                            <div class="muted"><?php echo cbl_h($r['route_name']); ?></div>
                            <div class="muted">SR <?php echo cbl_h($r['sr_code']); ?></div>
                        </td>
                        <td>
                            <div class="strong"><?php echo cbl_h($r['invoice_num']); ?></div>
                            <?php if ($r['to_be_delivery'] === 1): ?><span class="tag">To be delivery</span><?php endif; ?>
                        </td>
                        <td><div class="strong"><?php echo cbl_h($r['customer_name']); ?></div><div class="muted"><?php echo cbl_h($r['t_code']); ?></div></td>
                        <td class="num strong"><?php echo cbl_cents($r['final_cents']); ?></td>
                        <td class="num"><?php echo cbl_cents($r['ikea_cents']); ?><div class="muted"><?php echo $r['type'] === 'below' ? 'Lower than net invoice value' : ($r['ikea_found'] ? 'IKEA shows 0' : 'No IKEA record'); ?></div></td>
                        <td class="num strong"><?php echo cbl_cents($r['final_cents'] - $r['ikea_cents']); ?></td>
                        <td>
                            <select class="rs" data-detail="<?php echo (int)$r['detail_id']; ?>" data-prev="<?php echo $rid ?: ''; ?>" aria-label="Cancel reason for invoice <?php echo cbl_h($r['invoice_num']); ?>"
                                    <?php echo !$reasons && !$rid ? 'disabled' : ''; ?>
                                    <?php echo ($rid && $r['updated_by']) ? 'title="Updated by ' . cbl_h($r['updated_by']) . ' on ' . cbl_h(cbl_date($r['updated_at'], 'd M Y, H:i')) . '"' : ''; ?>>
                                <option value="">— Select reason —</option>
                                <?php foreach ($reasons as $x): ?>
                                    <option value="<?php echo (int)$x['id']; ?>"<?php echo (int)$x['id'] === $rid ? ' selected' : ''; ?>><?php echo cbl_h($x['reason']); ?></option>
                                <?php endforeach; ?>
                                <?php if ($rid && !isset($active_ids[$rid])): ?>
                                    <option value="<?php echo $rid; ?>" selected><?php echo cbl_h($r['reason_text']); ?> (inactive)</option>
                                <?php endif; ?>
                            </select>
                            <span class="st" aria-live="polite"></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php $first = ($data['page'] - 1) * CBL_PER_PAGE + 1; $last = $first + count($data['rows']) - 1; ?>
        <div class="pager">
            <span>Showing <?php echo number_format($first); ?>–<?php echo number_format($last); ?> of <?php echo number_format($data['count']); ?></span>
            <?php if ($data['pages'] > 1): ?>
            <span class="nav">
                <?php if ($data['page'] > 1): ?><a href="<?php echo cbl_h(cbl_url($f, ['page' => $data['page'] - 1])); ?>">Previous</a><?php endif; ?>
                <span>Page <?php echo $data['page']; ?> of <?php echo $data['pages']; ?></span>
                <?php if ($data['page'] < $data['pages']): ?><a href="<?php echo cbl_h(cbl_url($f, ['page' => $data['page'] + 1])); ?>">Next</a><?php endif; ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <p class="perf">Checked <?php echo number_format($data['checked']); ?> bills on <?php echo (int)$data['dates']; ?> delivery date<?php echo (int)$data['dates'] === 1 ? '' : 's'; ?> in <?php echo number_format($data['ms'] / 1000, 2); ?> s.</p>
    <?php endif; ?>

    <div class="toast" id="cblToast" role="alert" hidden></div>
</div>

<script>
(function () {
    'use strict';
    var app = document.getElementById('cblApp');
    var ENDPOINT = app.dataset.endpoint, CSRF = app.dataset.csrf;
    var toastEl = document.getElementById('cblToast');

    function toast(msg) {
        toastEl.textContent = msg; toastEl.hidden = false;
        clearTimeout(toast._t); toast._t = setTimeout(function () { toastEl.hidden = true; }, 6000);
    }
    /* keep the summary boxes and tab counts in step without re-drawing the table */
    function bump(key, delta) {
        app.querySelectorAll('[data-k="' + key + '"]').forEach(function (el) {
            var n = parseInt(el.textContent.replace(/,/g, ''), 10) || 0;
            el.textContent = Math.max(0, n + delta).toLocaleString('en-US');
        });
    }
    function state(sel, cls, text) {
        var st = sel.parentNode.querySelector('.st');
        st.className = 'st ' + cls; st.textContent = text;
        if (cls === 'ok') { clearTimeout(st._t); st._t = setTimeout(function () { if (st.className === 'st ok') st.textContent = ''; }, 2500); }
    }

    app.addEventListener('change', function (e) {
        var sel = e.target.closest('select[data-detail]');
        if (!sel) return;
        var prev = sel.dataset.prev || '', val = sel.value;
        if (val === prev) return;

        sel.disabled = true; state(sel, '', 'Saving…');
        var fd = new FormData();
        fd.append('csrf', CSRF); fd.append('detail_id', sel.dataset.detail); fd.append('reason_id', val);

        function fail(msg) { sel.disabled = false; sel.value = prev; state(sel, 'err', msg); toast(msg); }

        fetch(ENDPOINT + '?ajax=save_reason', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (res) {
                if (!res) { fail('The server sent an unexpected reply. Your session may have expired — reload the page.'); return; }
                if (!res.success) { fail(res.error || 'The reason could not be saved.'); return; }
                sel.disabled = false;
                sel.dataset.prev = val;
                var row = sel.closest('tr');
                row.classList.toggle('none', !val); row.classList.toggle('given', !!val);
                sel.title = val && res.updated_by ? 'Updated by ' + res.updated_by + (res.updated_at ? ' on ' + res.updated_at : '') : '';
                if (!prev && val) { bump('none', -1); bump('given', 1); }
                if (prev && !val) { bump('none', 1);  bump('given', -1); }
                state(sel, 'ok', val ? 'Saved' : 'Reason cleared');
            })
            .catch(function () { fail('The change did not reach the server. Check the connection and try again.'); });
    });
})();
</script>

<?php include 'footer.php'; ?>