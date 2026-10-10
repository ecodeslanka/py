<?php
/**
 * emergency_credit_bill_upload_report.php
 * ─────────────────────────────────────────────────────────────────────────
 * EMERGENCY CREDIT BILL UPLOAD REPORT
 *
 * One report that lists every invoice which was marked as "Emergency Credit"
 * when payments were added in the Field Summary (edit_field_summary.php →
 * save_emergency_credit.php), and shows whether its credit bill document has
 * been uploaded.
 *
 *   • Filter by delivery date (field_summary.delivery_date), document status
 *     (All / Not attached / Attached) and free-text search.
 *   • Summary totals for the current filter (count + credit amount).
 *   • The credit bill no entered in edit_field_summary.php is shown for every
 *     row and can be added / edited here (saved to credit_requests.credit_bill_no).
 *   • Customers flagged "Cheques Will Delay" (Add / Edit Customer form →
 *     customers.cheques_will_delay — the same flag customer_credit_risk_report.php
 *     uses) do not need an emergency credit bill: their rows are highlighted,
 *     labelled, remarked "Emergency credit bill not needed" and are NOT counted
 *     as "Not attached" (own "Not needed" tab instead).
 *   • Customer-wise view ("By customer"): how many emergency credits each
 *     customer got in the selected period, attached / not attached / not
 *     needed, credit amount, and the all-time emergency credit count.
 *     Every bill row also shows the customer's count (this period + all time).
 *   • Customer payment mode (customers.payment_mode — Cash / Credit / Cheque,
 *     set on the Add / Edit Customer form) is shown on every row and can be
 *     used as a filter; a payment mode breakdown sits under the summary.
 *   • Bills with no document can be uploaded straight from this page.
 *     Files are stored exactly like the payment-modal / bulk uploads:
 *         uploads/credit_docs/cr_<id>_<time>_<rand>.<ext>  →  credit_documents
 *     so every other screen that reads credit_documents shows them too.
 *
 * WHICH credit_requests ROWS COUNT AS "EMERGENCY CREDIT"?
 *   credit_requests is shared by three writers:
 *     1. save_emergency_credit.php   reason = one of emergency_credit_reasons
 *     2. process_credit_bills.php    reason = 'Credit Bill Import'
 *     3. crc_helper.php              reason = 'Cheque returned — CRC …', fs_id = 0
 *   Only (1) is an emergency credit, so (2) and (3) are excluded in
 *   ECUR_EMG_WHERE below. If another writer is ever added, exclude it there.
 * ─────────────────────────────────────────────────────────────────────────
 */

date_default_timezone_set('Asia/Colombo');

/* config.php ends with stray whitespace after "?>" – swallow it so that
   headers / JSON responses stay clean (same trick as save_emergency_credit.php) */
ob_start();
include_once 'config.php';
ob_end_clean();
include_once 'auth.php';

const ECUR_UPLOAD_DIR = 'uploads/credit_docs/';   /* shared with pay-modal + bulk uploads */
const ECUR_MAX_BYTES  = 10 * 1024 * 1024;         /* 10 MB per file (same as pay modal)   */
const ECUR_MAX_FILES  = 20;                       /* per upload request                   */
const ECUR_PER_PAGE   = 50;

const ECUR_EMG_WHERE = "cr.field_summary_id > 0
    AND cr.field_summary_detail_id > 0
    AND COALESCE(cr.reason,'') <> 'Credit Bill Import'
    AND COALESCE(cr.reason,'') NOT LIKE 'Cheque returned%'";

/* extension → MIME types we accept for it (checked against the real file content) */
function ecur_allowed_types() {
    return [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/x-cfb', 'application/CDFV2', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    ];
}

/* ═══════════════════════════ HELPERS ═══════════════════════════ */

function ecur_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* PHP 8.1+ makes mysqli throw on a failed query; older PHP returns false.
   This wrapper behaves the same on both and remembers the last error. */
$ecur_db_error = '';
function ecur_q($conn, $sql) {
    global $ecur_db_error;
    try {
        $r = mysqli_query($conn, $sql);
        if ($r === false) $ecur_db_error = mysqli_error($conn);
        return $r;
    } catch (Throwable $e) {
        $ecur_db_error = $e->getMessage();
        return false;
    }
}

function ecur_json($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function ecur_valid_date($d) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

function ecur_get($key, $default = '') {
    return (isset($_GET[$key]) && is_string($_GET[$key])) ? trim($_GET[$key]) : $default;
}

function ecur_money($n) { return number_format((float)$n, 2); }

function ecur_size($bytes) {
    $b = (float)$bytes;
    if ($b >= 1048576) return number_format($b / 1048576, 1) . ' MB';
    if ($b >= 1024)    return number_format($b / 1024, 0) . ' KB';
    return (int)$b . ' B';
}

function ecur_date($d)     { $t = strtotime((string)$d); return $t ? date('d M Y', $t) : ''; }
function ecur_datetime($d) { $t = strtotime((string)$d); return $t ? date('d M Y, H:i', $t) : ''; }

function ecur_table_exists($conn, $table) {
    $r = ecur_q($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    return $r && mysqli_num_rows($r) > 0;
}

function ecur_has_col($conn, $table, $col) {
    static $cache = [];
    $k = $table . '.' . $col;
    if (!isset($cache[$k])) {
        $r = ecur_q($conn, "SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "` LIKE '" . mysqli_real_escape_string($conn, $col) . "'");
        $cache[$k] = $r && mysqli_num_rows($r) > 0;
    }
    return $cache[$k];
}

function ecur_ensure_schema($conn) {
    ecur_q($conn, "CREATE TABLE IF NOT EXISTS credit_documents (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        credit_request_id INT           NOT NULL,
        original_name     VARCHAR(255)  NOT NULL,
        stored_name       VARCHAR(255)  NOT NULL,
        file_path         VARCHAR(500)  NOT NULL,
        file_type         VARCHAR(100)  NULL,
        file_size         INT           NULL,
        uploaded_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_crid (credit_request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $c = ecur_q($conn, "SHOW COLUMNS FROM credit_requests LIKE 'credit_bill_no'");
    if ($c && mysqli_num_rows($c) === 0) {
        ecur_q($conn, "ALTER TABLE credit_requests ADD COLUMN credit_bill_no VARCHAR(100) NOT NULL DEFAULT '' AFTER reason");
    }

    /* same auto-migration customer_credit_risk_report.php / add_customer.php use */
    if (ecur_table_exists($conn, 'customers')) {
        $c = ecur_q($conn, "SHOW COLUMNS FROM customers LIKE 'cheques_will_delay'");
        if ($c && mysqli_num_rows($c) === 0) {
            ecur_q($conn, "ALTER TABLE customers ADD COLUMN cheques_will_delay TINYINT(1) NOT NULL DEFAULT 0");
        }
    }
}

/* Stored file_path → a safe relative URL, or '' if it looks wrong */
function ecur_doc_url($path) {
    $p = str_replace('\\', '/', (string)$path);
    if (!preg_match('#^/?uploads/[^?\#]+$#', $p) || strpos($p, '..') !== false) return '';
    $parts = explode('/', $p);
    return implode('/', array_map('rawurlencode', $parts));
}

function ecur_is_image($type, $name) {
    if (strpos((string)$type, 'image/') === 0) return true;
    return in_array(strtolower(pathinfo((string)$name, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

function ecur_detect_mime($tmp) {
    if (!class_exists('finfo')) return '';
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $m  = $fi->file($tmp);
    return is_string($m) ? $m : '';
}

function ecur_upload_err($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'is larger than the server allows';
        case UPLOAD_ERR_PARTIAL:   return 'was only partly uploaded — try again';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE: return 'could not be saved on the server';
        default:                   return 'could not be uploaded';
    }
}

/* ═══════════════════════════ FILTERS ═══════════════════════════ */

function ecur_filters() {
    $from = array_key_exists('from', $_GET) ? ecur_get('from') : date('Y-m-01');   /* default: this month */
    $to   = array_key_exists('to',   $_GET) ? ecur_get('to')   : date('Y-m-d');
    if (!ecur_valid_date($from)) $from = '';
    if (!ecur_valid_date($to))   $to   = '';
    if ($from !== '' && $to !== '' && $from > $to) { $t = $from; $from = $to; $to = $t; }

    $status = ecur_get('status', 'all');
    if (!in_array($status, ['all', 'missing', 'attached', 'notneeded'], true)) $status = 'all';

    $q = ecur_get('q');
    if (strlen($q) > 100) $q = substr($q, 0, 100);

    /* customer payment mode: cash / credit / cheque / none (= not set on the customer) */
    $mode = strtolower(ecur_get('mode'));
    if (!array_key_exists($mode, ecur_modes())) $mode = '';

    /* bills = one row per emergency credit, customers = grouped per customer */
    $view = ecur_get('view', 'bills');
    if (!in_array($view, ['bills', 'customers'], true)) $view = 'bills';

    return [
        'from'   => $from,
        'to'     => $to,
        'status' => $status,
        'q'      => $q,
        'mode'   => $mode,
        'view'   => $view,
        'page'   => max(1, (int)ecur_get('page', '1')),
    ];
}

/* payment modes used on the Add / Edit Customer form (customers.payment_mode) */
function ecur_modes() {
    return ['cash' => 'Cash', 'credit' => 'Credit', 'cheque' => 'Cheque', 'none' => 'Not set'];
}

function ecur_mode_badge($mode) {
    $m = strtolower(trim((string)$mode));
    $labels = ecur_modes();
    if ($m === '' || !isset($labels[$m])) {
        return $m === '' ? '<span class="ecur-mode ecur-mode--none" title="Payment mode is not set on the customer">Mode not set</span>'
                         : '<span class="ecur-mode ecur-mode--none">' . ecur_h(ucfirst($m)) . '</span>';
    }
    return '<span class="ecur-mode ecur-mode--' . $m . '" title="Customer payment mode">' . $labels[$m] . '</span>';
}

function ecur_url($f, $over = []) {
    $p = array_merge(['from' => $f['from'], 'to' => $f['to'], 'status' => $f['status'], 'q' => $f['q'],
                      'mode' => $f['mode'], 'view' => $f['view'], 'page' => $f['page']], $over);
    if ($p['status'] === 'all') unset($p['status']);
    if ($p['mode'] === '')      unset($p['mode']);
    if ($p['view'] === 'bills') unset($p['view']);
    if ($p['q'] === '')         unset($p['q']);
    if ((int)$p['page'] <= 1)   unset($p['page']);
    return basename(__FILE__) . '?' . http_build_query($p);
}

/* ═══════════════════════════ DATA ═══════════════════════════ */

function ecur_base_sql($conn, $f, $with_routes) {
    $where = [ECUR_EMG_WHERE];
    if ($f['from'] !== '') $where[] = "fs.delivery_date >= '" . $f['from'] . "'";   /* validated YYYY-MM-DD */
    if ($f['to']   !== '') $where[] = "fs.delivery_date <= '" . $f['to']   . "'";
    if ($f['q'] !== '') {
        $qe   = addcslashes(mysqli_real_escape_string($conn, $f['q']), '%_');
        $like = "'%$qe%'";
        $where[] = "(cr.invoice_num LIKE $like OR cr.credit_bill_no LIKE $like OR cr.t_code LIKE $like
                     OR fsd.customer_name LIKE $like OR c.shop_name LIKE $like OR fs.field_summary_code LIKE $like)";
    }

    $has_mode  = ecur_has_col($conn, 'customers', 'payment_mode');
    $mode_expr = $has_mode ? "LOWER(TRIM(COALESCE(c.payment_mode, '')))" : "''";
    if ($has_mode && $f['mode'] !== '') {
        $where[] = $f['mode'] === 'none'
            ? "$mode_expr NOT IN ('cash','credit','cheque')"
            : "$mode_expr = '" . mysqli_real_escape_string($conn, $f['mode']) . "'";   /* whitelisted in ecur_filters() */
    }

    $delay_expr = ecur_has_col($conn, 'customers', 'cheques_will_delay') ? "COALESCE(c.cheques_will_delay, 0)" : "0";
    $route_expr = $with_routes ? "COALESCE(rt.route_name, fs.route)" : "fs.route";
    $route_join = $with_routes ? "LEFT JOIN routes rt ON rt.route_code = fs.route" : "";

    return "SELECT cr.id                      AS cr_id,
                   cr.field_summary_id,
                   cr.t_code,
                   cr.invoice_num,
                   cr.credit_bill_no,
                   cr.credit_amount,
                   cr.reason,
                   cr.created_at              AS marked_at,
                   fs.delivery_date,
                   fs.field_summary_code,
                   fs.sr_code,
                   $route_expr                AS route_name,
                   COALESCE(NULLIF(TRIM(e.employee_full_name),''), NULLIF(TRIM(fs.delivery_person_raw_name),''), CONCAT('SR: ', fs.sr_code)) AS delivery_person,
                   COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, cr.t_code) AS customer_name,
                   fsd.adjust_net_value       AS invoice_amount,
                   $delay_expr                AS cheque_delay,
                   $mode_expr                 AS payment_mode,
                   (SELECT COUNT(*) FROM credit_documents d WHERE d.credit_request_id = cr.id) AS doc_count
            FROM credit_requests cr
            INNER JOIN field_summary fs          ON fs.id  = cr.field_summary_id
            LEFT  JOIN field_summary_details fsd ON fsd.id = cr.field_summary_detail_id
            LEFT  JOIN customers c               ON c.t_code = cr.t_code
            LEFT  JOIN employees e               ON e.id = fs.employee_id
            $route_join
            WHERE " . implode(' AND ', $where);
}

function ecur_load($conn, $f) {
    global $ecur_db_error;
    $out = [
        'summary' => ['total' => 0, 'attached' => 0, 'missing' => 0, 'notneeded' => 0, 'customers' => 0, 'total_amt' => 0, 'attached_amt' => 0, 'missing_amt' => 0, 'notneeded_amt' => 0],
        'modes'   => [],       /* payment mode breakdown (ignores the mode filter) */
        'rows'    => [], 'total' => 0, 'pages' => 1, 'page' => 1, 'error' => '', 'no_table' => false,
    ];

    if (!ecur_table_exists($conn, 'credit_requests')) { $out['no_table'] = true; return $out; }

    /* try with the routes join first; fall back without it if that join is not possible */
    $sum = false; $base = '';
    foreach ([true, false] as $with_routes) {
        $base = ecur_base_sql($conn, $f, $with_routes);
        $sum  = ecur_q($conn, "SELECT COUNT(*)                                               AS total,
                                      COUNT(DISTINCT t.t_code)                               AS customers,
                                      COALESCE(SUM(t.doc_count > 0), 0)                      AS attached,
                                      COALESCE(SUM(t.doc_count = 0 AND t.cheque_delay = 0), 0) AS missing,
                                      COALESCE(SUM(t.doc_count = 0 AND t.cheque_delay <> 0), 0) AS notneeded,
                                      COALESCE(SUM(t.credit_amount), 0)                      AS total_amt,
                                      COALESCE(SUM(CASE WHEN t.doc_count > 0 THEN t.credit_amount ELSE 0 END), 0) AS attached_amt,
                                      COALESCE(SUM(CASE WHEN t.doc_count = 0 AND t.cheque_delay = 0 THEN t.credit_amount ELSE 0 END), 0) AS missing_amt,
                                      COALESCE(SUM(CASE WHEN t.doc_count = 0 AND t.cheque_delay <> 0 THEN t.credit_amount ELSE 0 END), 0) AS notneeded_amt
                               FROM ($base) t");
        if ($sum) break;
    }
    if (!$sum) { $out['error'] = $ecur_db_error; return $out; }
    $out['summary'] = mysqli_fetch_assoc($sum);

    /* payment mode breakdown for the same filters, but across every mode (so the chips can switch mode) */
    $mode_base = ecur_base_sql($conn, array_merge($f, ['mode' => '']), $with_routes);
    $mr = ecur_q($conn, "SELECT CASE WHEN t.payment_mode IN ('cash','credit','cheque') THEN t.payment_mode ELSE 'none' END AS m,
                                COUNT(*) AS n, COUNT(DISTINCT t.t_code) AS cust, COALESCE(SUM(t.credit_amount), 0) AS amt
                         FROM ($mode_base) t GROUP BY m");
    if ($mr) while ($m = mysqli_fetch_assoc($mr)) $out['modes'][$m['m']] = $m;

    $status_sqls = [
        'all'       => '',
        'missing'   => 'WHERE t.doc_count = 0 AND t.cheque_delay = 0',
        'attached'  => 'WHERE t.doc_count > 0',
        'notneeded' => 'WHERE t.doc_count = 0 AND t.cheque_delay <> 0',
    ];
    $status_sql = $status_sqls[$f['status']];

    $s = $out['summary'];
    if ($f['view'] === 'customers') {
        /* one row per customer */
        $c = ecur_q($conn, "SELECT COUNT(DISTINCT t.t_code) AS n FROM ($base) t $status_sql");
        if (!$c) { $out['error'] = $ecur_db_error; return $out; }
        $total = (int)mysqli_fetch_assoc($c)['n'];
    } else {
        $by_status = ['all' => (int)$s['total'], 'missing' => (int)$s['missing'], 'attached' => (int)$s['attached'], 'notneeded' => (int)$s['notneeded']];
        $total = $by_status[$f['status']];
    }
    $pages  = max(1, (int)ceil($total / ECUR_PER_PAGE));
    $page   = min($f['page'], $pages);
    $offset = ($page - 1) * ECUR_PER_PAGE;

    if ($f['view'] === 'customers') {
        $r = ecur_q($conn, "SELECT t.t_code,
                                   MAX(t.customer_name)                                     AS customer_name,
                                   MAX(t.payment_mode)                                      AS payment_mode,
                                   MAX(t.cheque_delay)                                      AS cheque_delay,
                                   COUNT(*)                                                 AS emg_count,
                                   COUNT(DISTINCT t.invoice_num)                            AS invoice_count,
                                   COALESCE(SUM(t.doc_count > 0), 0)                        AS attached,
                                   COALESCE(SUM(t.doc_count = 0 AND t.cheque_delay = 0), 0) AS missing,
                                   COALESCE(SUM(t.doc_count = 0 AND t.cheque_delay <> 0), 0) AS notneeded,
                                   COALESCE(SUM(t.credit_amount), 0)                        AS credit_amt,
                                   MIN(t.delivery_date)                                     AS first_date,
                                   MAX(t.delivery_date)                                     AS last_date
                            FROM ($base) t $status_sql
                            GROUP BY t.t_code
                            ORDER BY emg_count DESC, credit_amt DESC, customer_name ASC
                            LIMIT " . ECUR_PER_PAGE . " OFFSET $offset");
    } else {
        $r = ecur_q($conn, "SELECT t.* FROM ($base) t $status_sql
                            ORDER BY t.delivery_date DESC, t.cr_id DESC
                            LIMIT " . ECUR_PER_PAGE . " OFFSET $offset");
    }
    if (!$r) { $out['error'] = $ecur_db_error; return $out; }
    while ($row = mysqli_fetch_assoc($r)) $out['rows'][] = $row;

    /* emergency credit count per customer for the customers on this page:
         period_count = in the selected dates / search / mode (any document status)
         all_count    = all time, every date                                      */
    $codes = [];
    foreach ($out['rows'] as $row) $codes[(string)$row['t_code']] = true;
    $period = []; $alltime = [];
    if ($codes) {
        $in = implode(',', array_map(function ($c) use ($conn) {
            return "'" . mysqli_real_escape_string($conn, $c) . "'";
        }, array_keys($codes)));

        $pr = ecur_q($conn, "SELECT t.t_code, COUNT(*) AS n FROM ($base) t WHERE t.t_code IN ($in) GROUP BY t.t_code");
        if ($pr) while ($x = mysqli_fetch_assoc($pr)) $period[(string)$x['t_code']] = (int)$x['n'];

        $ar = ecur_q($conn, "SELECT cr.t_code, COUNT(*) AS n, COALESCE(SUM(cr.credit_amount), 0) AS amt
                             FROM credit_requests cr
                             WHERE " . ECUR_EMG_WHERE . " AND cr.t_code IN ($in)
                             GROUP BY cr.t_code");
        if ($ar) while ($x = mysqli_fetch_assoc($ar)) $alltime[(string)$x['t_code']] = $x;
    }
    foreach ($out['rows'] as &$row) {
        $k = (string)$row['t_code'];
        $row['period_count'] = $period[$k] ?? (int)($row['emg_count'] ?? 0);
        $row['all_count']    = isset($alltime[$k]) ? (int)$alltime[$k]['n'] : $row['period_count'];
        $row['all_amt']      = isset($alltime[$k]) ? (float)$alltime[$k]['amt'] : 0;
    }
    unset($row);

    $out['total'] = $total;
    $out['pages'] = $pages;
    $out['page']  = $page;
    return $out;
}

/* ═══════════════════════════ AJAX ═══════════════════════════ */

$ecur_ajax = ecur_get('ajax');
if ($ecur_ajax !== '') {
    if (!isLoggedIn() && !autoLoginFromCookie()) {
        ecur_json(['success' => false, 'error' => 'Your session has expired. Reload the page and sign in again.'], 401);
    }
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['ecur_csrf'])) $_SESSION['ecur_csrf'] = bin2hex(random_bytes(16));
    ecur_ensure_schema($conn);

    /* ── fragment: summary + tabs + table (used to refresh after an upload) ── */
    if ($ecur_ajax === 'fragment') {
        header('Content-Type: text/html; charset=utf-8');
        $f = ecur_filters();
        ecur_render($f, ecur_load($conn, $f));
        exit;
    }

    /* ── documents already attached to one credit request ── */
    if ($ecur_ajax === 'docs') {
        $id = (int)ecur_get('id', '0');
        $r  = ecur_q($conn, "SELECT id, original_name, file_path, file_type, file_size, uploaded_at
                             FROM credit_documents WHERE credit_request_id = $id ORDER BY id ASC");
        if (!$r) ecur_json(['success' => false, 'error' => 'Could not read the documents.'], 500);
        $docs = [];
        while ($d = mysqli_fetch_assoc($r)) {
            $docs[] = [
                'id'       => (int)$d['id'],
                'name'     => $d['original_name'],
                'url'      => ecur_doc_url($d['file_path']),
                'is_image' => ecur_is_image($d['file_type'], $d['original_name']),
                'size'     => ecur_size($d['file_size']),
                'uploaded' => ecur_datetime($d['uploaded_at']),
            ];
        }
        ecur_json(['success' => true, 'docs' => $docs]);
    }

    /* ── upload documents to one credit request ── */
    if ($ecur_ajax === 'upload') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') ecur_json(['success' => false, 'error' => 'POST only.'], 405);

        /* post_max_size exceeded → PHP drops both $_POST and $_FILES */
        if (empty($_FILES) && empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
            ecur_json(['success' => false, 'error' => 'The files are larger than the server allows in one upload. Upload fewer or smaller files.'], 413);
        }
        if (!hash_equals((string)$_SESSION['ecur_csrf'], (string)($_POST['csrf'] ?? ''))) {
            ecur_json(['success' => false, 'error' => 'Security check failed. Reload the page and try again.'], 403);
        }

        $id  = (int)($_POST['credit_request_id'] ?? 0);
        $chk = $id > 0 ? ecur_q($conn, "SELECT cr.id FROM credit_requests cr WHERE cr.id = $id AND " . ECUR_EMG_WHERE . " LIMIT 1") : false;
        if (!$chk || !mysqli_fetch_assoc($chk)) {
            ecur_json(['success' => false, 'error' => 'This emergency credit record was not found. It may have been deleted.'], 404);
        }

        $up = $_FILES['documents'] ?? null;
        if (!$up || !is_array($up['name'])) {
            ecur_json(['success' => false, 'error' => 'No files were received. Choose at least one file.'], 400);
        }
        if (count($up['name']) > ECUR_MAX_FILES) {
            ecur_json(['success' => false, 'error' => 'Upload at most ' . ECUR_MAX_FILES . ' files at a time.'], 400);
        }

        $dir = __DIR__ . '/' . ECUR_UPLOAD_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            ecur_json(['success' => false, 'error' => 'The upload folder (' . ECUR_UPLOAD_DIR . ') could not be created. Check folder permissions.'], 500);
        }
        if (!is_writable($dir)) {
            ecur_json(['success' => false, 'error' => 'The upload folder (' . ECUR_UPLOAD_DIR . ') is not writable. Check folder permissions.'], 500);
        }

        $allowed = ecur_allowed_types();
        $saved   = 0;
        $errors  = [];
        $stmt    = null;
        try {
            $stmt = mysqli_prepare($conn, "INSERT INTO credit_documents
                        (credit_request_id, original_name, stored_name, file_path, file_type, file_size)
                        VALUES (?, ?, ?, ?, ?, ?)");
        } catch (Throwable $e) { $stmt = null; }
        if (!$stmt) ecur_json(['success' => false, 'error' => 'Database error while preparing the upload.'], 500);

        foreach ($up['name'] as $i => $rawName) {
            $err = (int)$up['error'][$i];
            if ($err === UPLOAD_ERR_NO_FILE) continue;

            $orig = basename(str_replace('\\', '/', (string)$rawName));
            if (!preg_match('//u', $orig)) $orig = 'document';                    /* not valid UTF-8 */
            if (preg_match('/^.{0,190}/us', $orig, $mm)) $orig = $mm[0];           /* fits VARCHAR(255) */
            $label = $orig !== '' ? $orig : 'A file';

            if ($err !== UPLOAD_ERR_OK) { $errors[] = "$label " . ecur_upload_err($err) . '.'; continue; }

            $size = (int)$up['size'][$i];
            $tmp  = (string)$up['tmp_name'][$i];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

            if ($size <= 0)                  { $errors[] = "$label is empty."; continue; }
            if ($size > ECUR_MAX_BYTES)      { $errors[] = "$label is larger than 10 MB."; continue; }
            if (!isset($allowed[$ext]))      { $errors[] = "$label is not an allowed type. Use JPG, PNG, GIF, WEBP, PDF, DOC or DOCX."; continue; }
            if (!is_uploaded_file($tmp))     { $errors[] = "$label could not be verified as an upload."; continue; }

            $mime = ecur_detect_mime($tmp);
            if ($mime !== '' && !in_array($mime, $allowed[$ext], true)) {
                $errors[] = "$label does not look like a real ." . $ext . " file.";
                continue;
            }

            $stored = 'cr_' . $id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest   = $dir . $stored;
            if (!move_uploaded_file($tmp, $dest)) { $errors[] = "$label could not be saved."; continue; }
            @chmod($dest, 0644);

            $path  = ECUR_UPLOAD_DIR . $stored;
            $ftype = $mime !== '' ? $mime : $allowed[$ext][0];
            try {
                mysqli_stmt_bind_param($stmt, 'issssi', $id, $orig, $stored, $path, $ftype, $size);
                mysqli_stmt_execute($stmt);
                $saved++;
            } catch (Throwable $e) {
                @unlink($dest);
                $errors[] = "$label could not be recorded in the database.";
            }
        }
        mysqli_stmt_close($stmt);

        if ($saved === 0) {
            ecur_json(['success' => false, 'error' => $errors ? implode(' ', $errors) : 'No files were received. Choose at least one file.'], 422);
        }
        $cnt = ecur_q($conn, "SELECT COUNT(*) AS n FROM credit_documents WHERE credit_request_id = $id");
        $n   = $cnt ? (int)mysqli_fetch_assoc($cnt)['n'] : $saved;
        ecur_json(['success' => true, 'saved' => $saved, 'doc_count' => $n, 'warnings' => $errors]);
    }

    /* ── add / edit the credit bill no of one credit request ── */
    if ($ecur_ajax === 'save_bill_no') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') ecur_json(['success' => false, 'error' => 'POST only.'], 405);
        if (!hash_equals((string)$_SESSION['ecur_csrf'], (string)($_POST['csrf'] ?? ''))) {
            ecur_json(['success' => false, 'error' => 'Security check failed. Reload the page and try again.'], 403);
        }

        $id  = (int)($_POST['credit_request_id'] ?? 0);
        $chk = $id > 0 ? ecur_q($conn, "SELECT cr.id, cr.field_summary_detail_id FROM credit_requests cr WHERE cr.id = $id AND " . ECUR_EMG_WHERE . " LIMIT 1") : false;
        $row = $chk ? mysqli_fetch_assoc($chk) : null;
        if (!$row) ecur_json(['success' => false, 'error' => 'This emergency credit record was not found. It may have been deleted.'], 404);

        /* same rule as the entry form in edit_field_summary.php: required, trimmed; column is VARCHAR(100) */
        $bill = (string)($_POST['credit_bill_no'] ?? '');
        if (!preg_match('//u', $bill)) ecur_json(['success' => false, 'error' => 'The credit bill no contains characters that cannot be saved.'], 422);
        $bill = trim(preg_replace('/\s+/u', ' ', $bill));
        if ($bill === '')                          ecur_json(['success' => false, 'error' => 'Enter the credit bill no.'], 422);
        if (!preg_match('/^.{1,100}$/us', $bill))  ecur_json(['success' => false, 'error' => 'The credit bill no must be 100 characters or fewer.'], 422);
        if (preg_match('/[\x00-\x1F\x7F]/', $bill)) ecur_json(['success' => false, 'error' => 'The credit bill no contains characters that cannot be saved.'], 422);

        $detail = (int)$row['field_summary_detail_id'];
        try {
            $st = mysqli_prepare($conn, "UPDATE credit_requests SET credit_bill_no = ? WHERE id = ?");
            mysqli_stmt_bind_param($st, 'si', $bill, $id);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        } catch (Throwable $e) {
            ecur_json(['success' => false, 'error' => 'The credit bill no could not be saved. Try again.'], 500);
        }

        /* the AI bulk upload matches documents by credit bill no, so tell the user if another invoice already uses it */
        $dups = [];
        try {
            $st = mysqli_prepare($conn, "SELECT DISTINCT invoice_num FROM credit_requests WHERE credit_bill_no = ? AND field_summary_detail_id <> ? LIMIT 5");
            mysqli_stmt_bind_param($st, 'si', $bill, $detail);
            mysqli_stmt_execute($st);
            $rs = mysqli_stmt_get_result($st);
            while ($rs && ($d = mysqli_fetch_assoc($rs))) $dups[] = (string)$d['invoice_num'];
            mysqli_stmt_close($st);
        } catch (Throwable $e) { /* the save already succeeded; the duplicate hint is optional */ }

        ecur_json(['success' => true, 'credit_bill_no' => $bill, 'duplicates' => $dups]);
    }

    ecur_json(['success' => false, 'error' => 'Unknown request.'], 400);
}

/* ═══════════════════════════ RENDER (fragment) ═══════════════════════════ */

function ecur_render($f, $d) {
    $s = $d['summary'];
    $total     = (int)$s['total'];
    $attached  = (int)$s['attached'];
    $missing   = (int)$s['missing'];
    $notneeded = (int)$s['notneeded'];
    $required  = $attached + $missing;     /* bills that actually need a document */
    $rate      = $required > 0 ? ($attached === $required ? 100 : min(99, (int)floor($attached * 100 / $required))) : 0;
    echo "<!--ecur-fragment-->\n";

    if ($d['no_table']) {
        echo '<div class="ecur-empty"><strong>No emergency credit has been recorded yet.</strong>
              <p>Bills appear here after someone marks an invoice as Emergency Credit while adding payments in a field summary.</p></div>';
        return;
    }
    if ($d['error'] !== '') {
        echo '<div class="ecur-alert" role="alert"><strong>The report could not be loaded.</strong> Database message: '
           . ecur_h($d['error']) . '</div>';
        return;
    }
    $tab = function ($key, $label, $count, $cls) use ($f) {
        $on = $f['status'] === $key;
        return '<a href="' . ecur_h(ecur_url($f, ['status' => $key, 'page' => 1])) . '" class="' . $cls . ($on ? ' is-active' : '') . '"'
             . ($on ? ' aria-current="page"' : '') . '>' . $label . ' <span>' . number_format($count) . '</span></a>';
    };
    ?>
    <section class="ecur-kpis" aria-label="Summary for the selected filters">
        <div class="ecur-kpi ecur-kpi--lead<?php echo ($missing === 0 && $total > 0) ? ' is-clear' : ''; ?>">
            <div class="ecur-kpi-label">Not attached</div>
            <div class="ecur-kpi-big"><?php echo number_format($missing); ?> <small><?php echo $missing === 1 ? 'bill' : 'bills'; ?></small></div>
            <div class="ecur-kpi-note"><?php echo $missing === 0
                ? ($total > 0 ? 'No bill is waiting for a document' : 'No emergency credits for these filters')
                : 'Rs. ' . ecur_money($s['missing_amt']) . ' on credit with no document'; ?></div>
        </div>
        <div class="ecur-kpi">
            <div class="ecur-kpi-label">Attached</div>
            <div class="ecur-kpi-mid"><?php echo number_format($attached); ?></div>
            <div class="ecur-kpi-note">Rs. <?php echo ecur_money($s['attached_amt']); ?></div>
        </div>
        <div class="ecur-kpi">
            <div class="ecur-kpi-label">All emergency credits</div>
            <div class="ecur-kpi-mid"><?php echo number_format($total); ?></div>
            <div class="ecur-kpi-note">Rs. <?php echo ecur_money($s['total_amt']); ?><?php echo $notneeded > 0 ? ', ' . number_format($notneeded) . ' not needed' : ''; ?></div>
            <div class="ecur-kpi-note">From <?php echo number_format((int)$s['customers']); ?> <?php echo (int)$s['customers'] === 1 ? 'customer' : 'customers'; ?></div>
        </div>
        <div class="ecur-kpi">
            <div class="ecur-kpi-label">Documents uploaded</div>
            <div class="ecur-kpi-mid"><?php echo $required > 0 ? $rate . '%' : '—'; ?></div>
            <div class="ecur-meter" role="img" aria-label="<?php echo $rate; ?> percent of bills that need a document have one"><span style="width:<?php echo $rate; ?>%"></span></div>
            <div class="ecur-kpi-note">of <?php echo number_format($required); ?> that <?php echo $required === 1 ? 'needs' : 'need'; ?> one</div>
        </div>
    </section>

    <?php /* ── emergency credits by customer payment mode (click to filter) ── */
    $mode_total = 0;
    foreach ($d['modes'] as $m) $mode_total += (int)$m['n'];
    if ($mode_total > 0): ?>
    <section class="ecur-modes" aria-label="Emergency credits by customer payment mode">
        <span class="ecur-modes-label">Customer payment mode</span>
        <a href="<?php echo ecur_h(ecur_url($f, ['mode' => '', 'page' => 1])); ?>" class="ecur-modechip<?php echo $f['mode'] === '' ? ' is-active' : ''; ?>"<?php echo $f['mode'] === '' ? ' aria-current="true"' : ''; ?>>
            All modes <b><?php echo number_format($mode_total); ?></b>
        </a>
        <?php foreach (ecur_modes() as $mk => $ml):
            if (!isset($d['modes'][$mk])) continue;
            $m  = $d['modes'][$mk];
            $on = $f['mode'] === $mk; ?>
            <a href="<?php echo ecur_h(ecur_url($f, ['mode' => $on ? '' : $mk, 'page' => 1])); ?>"
               class="ecur-modechip ecur-modechip--<?php echo $mk; ?><?php echo $on ? ' is-active' : ''; ?>"<?php echo $on ? ' aria-current="true"' : ''; ?>
               title="<?php echo number_format((int)$m['cust']); ?> customers, Rs. <?php echo ecur_money($m['amt']); ?>">
                <?php echo $ml; ?> <b><?php echo number_format((int)$m['n']); ?></b>
                <small><?php echo number_format((int)$m['cust']); ?> cust.</small>
            </a>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <div class="ecur-tabbar">
        <nav class="ecur-tabs" aria-label="Document status">
            <?php echo $tab('all', 'All', $total, ''); ?>
            <?php echo $tab('missing', 'Not attached', $missing, 'ecur-tab-missing'); ?>
            <?php echo $tab('attached', 'Attached', $attached, 'ecur-tab-ok'); ?>
            <?php echo $tab('notneeded', 'Not needed', $notneeded, 'ecur-tab-delay'); ?>
        </nav>
        <div class="ecur-viewswitch" role="group" aria-label="Show">
            <a href="<?php echo ecur_h(ecur_url($f, ['view' => 'bills', 'page' => 1])); ?>" class="<?php echo $f['view'] === 'bills' ? 'is-active' : ''; ?>"<?php echo $f['view'] === 'bills' ? ' aria-current="true"' : ''; ?>>Bills</a>
            <a href="<?php echo ecur_h(ecur_url($f, ['view' => 'customers', 'page' => 1])); ?>" class="<?php echo $f['view'] === 'customers' ? 'is-active' : ''; ?>"<?php echo $f['view'] === 'customers' ? ' aria-current="true"' : ''; ?>>By customer <span><?php echo number_format((int)$s['customers']); ?></span></a>
        </div>
    </div>

    <?php if ($f['status'] === 'notneeded'): ?>
        <p class="ecur-note">These customers are marked <strong>Cheques Will Delay</strong> on the customer form, so an emergency credit bill is not needed. You can still upload one if you have it.</p>
    <?php endif; ?>

    <?php if (!$d['rows']): ?>
        <div class="ecur-empty">
            <strong>No emergency credit bills match these filters.</strong>
            <p><?php echo $f['status'] === 'missing'
                    ? 'Nothing is waiting for a document in this period.'
                    : ($f['status'] === 'notneeded' ? 'No cheque delay customers in this period.' : 'Try a wider delivery date range or clear the search.'); ?></p>
            <a class="ecur-btn ecur-btn--ghost" href="<?php echo ecur_h(ecur_url($f, ['from' => '', 'to' => '', 'q' => '', 'mode' => '', 'status' => 'all', 'page' => 1])); ?>">Show all dates</a>
        </div>
    <?php elseif ($f['view'] === 'customers'): ?>
    <div class="ecur-tablewrap">
        <table class="ecur-table ecur-table--cust">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Payment mode</th>
                    <th class="num" title="Emergency credits for this customer in the selected dates and filters">Emergency credits</th>
                    <th class="num">Not attached</th>
                    <th class="num">Attached</th>
                    <th class="num">Not needed</th>
                    <th class="num">Credit amount</th>
                    <th>Delivery dates</th>
                    <th class="num" title="Every emergency credit ever recorded for this customer">All time</th>
                    <th class="ecur-pin"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($d['rows'] as $r):
                $delay = (int)$r['cheque_delay'] !== 0;
                $cnt   = (int)$r['emg_count'];
                $miss  = (int)$r['missing'];
                $cls   = trim(($miss > 0 ? 'is-missing ' : '') . ($delay ? 'is-delay' : '')); ?>
                <tr class="<?php echo $cls; ?>">
                    <td class="ecur-cust">
                        <div class="ecur-strong"><?php echo ecur_h($r['customer_name']); ?></div>
                        <div class="ecur-sub"><?php echo ecur_h($r['t_code']); ?></div>
                        <?php if ($delay): ?>
                            <div class="ecur-tag" title="This customer is marked Cheques Will Delay on the customer form"><i class="fa-solid fa-clock" aria-hidden="true"></i> Cheques will delay</div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo ecur_mode_badge($r['payment_mode']); ?></td>
                    <td class="num">
                        <span class="ecur-count<?php echo $cnt >= 3 ? ' is-high' : ''; ?>"><?php echo number_format($cnt); ?></span>
                        <?php if ((int)$r['invoice_count'] !== $cnt): ?><div class="ecur-sub"><?php echo number_format((int)$r['invoice_count']); ?> invoices</div><?php endif; ?>
                    </td>
                    <td class="num"><?php echo $miss > 0 ? '<span class="ecur-strong ecur-red">' . number_format($miss) . '</span>' : '<span class="ecur-sub">0</span>'; ?></td>
                    <td class="num"><?php echo (int)$r['attached'] > 0 ? '<span class="ecur-green">' . number_format((int)$r['attached']) . '</span>' : '<span class="ecur-sub">0</span>'; ?></td>
                    <td class="num"><?php echo (int)$r['notneeded'] > 0 ? '<span class="ecur-amber">' . number_format((int)$r['notneeded']) . '</span>' : '<span class="ecur-sub">0</span>'; ?></td>
                    <td class="num ecur-strong"><?php echo ecur_money($r['credit_amt']); ?></td>
                    <td class="ecur-nowrap">
                        <div><?php echo ecur_h(ecur_date($r['last_date'])); ?></div>
                        <?php if ($r['first_date'] !== $r['last_date']): ?><div class="ecur-sub">first <?php echo ecur_h(ecur_date($r['first_date'])); ?></div><?php endif; ?>
                    </td>
                    <td class="num">
                        <div class="ecur-strong"><?php echo number_format((int)$r['all_count']); ?></div>
                        <div class="ecur-sub">Rs. <?php echo ecur_money($r['all_amt']); ?></div>
                    </td>
                    <td class="ecur-pin">
                        <a class="ecur-btn ecur-btn--ghost ecur-btn--sm" href="<?php echo ecur_h(ecur_url($f, ['view' => 'bills', 'q' => (string)$r['t_code'], 'page' => 1])); ?>">View bills</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="ecur-tablewrap">
        <table class="ecur-table">
            <thead>
                <tr>
                    <th>Delivery date</th>
                    <th class="ecur-wraphead">Route and delivery person</th>
                    <th>Customer</th>
                    <th>Invoice</th>
                    <th>Credit bill no</th>
                    <th>Reason</th>
                    <th class="num">Credit amount</th>
                    <th class="ecur-pin">Document</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($d['rows'] as $r):
                $has   = (int)$r['doc_count'] > 0;
                $delay = (int)$r['cheque_delay'] !== 0;
                $cls   = trim((!$has && !$delay ? 'is-missing ' : '') . ($delay ? 'is-delay' : ''));
                $data  = 'data-id="' . (int)$r['cr_id'] . '"'
                       . ' data-invoice="'  . ecur_h($r['invoice_num']) . '"'
                       . ' data-customer="' . ecur_h($r['customer_name']) . '"'
                       . ' data-bill="'     . ecur_h($r['credit_bill_no']) . '"'
                       . ' data-docs="'     . (int)$r['doc_count'] . '"'; ?>
                <tr class="<?php echo $cls; ?>">
                    <td>
                        <div class="ecur-strong ecur-nowrap"><?php echo ecur_h(ecur_date($r['delivery_date'])); ?></div>
                        <a class="ecur-sub" href="edit_field_summary.php?id=<?php echo (int)$r['field_summary_id']; ?>" target="_blank" rel="noopener" title="Open this field summary"><?php echo ecur_h($r['field_summary_code']); ?></a>
                    </td>
                    <td>
                        <div><?php echo ecur_h($r['route_name']); ?></div>
                        <div class="ecur-sub"><?php echo ecur_h($r['delivery_person']); ?></div>
                        <div class="ecur-sub">SR <?php echo ecur_h($r['sr_code']); ?></div>
                    </td>
                    <td class="ecur-cust">
                        <div class="ecur-strong"><?php echo ecur_h($r['customer_name']); ?></div>
                        <div class="ecur-sub"><?php echo ecur_h($r['t_code']); ?></div>
                        <div class="ecur-custmeta">
                            <?php echo ecur_mode_badge($r['payment_mode']); ?>
                            <span class="ecur-emgcount<?php echo (int)$r['all_count'] >= 3 ? ' is-high' : ''; ?>"
                                  title="All emergency credits ever recorded for this customer">
                                <?php echo number_format((int)$r['all_count']); ?> emergency <?php echo (int)$r['all_count'] === 1 ? 'credit' : 'credits'; ?>
                            </span>
                        </div>
                        <?php if ($delay): ?>
                            <div class="ecur-tag" title="This customer is marked Cheques Will Delay on the customer form"><i class="fa-solid fa-clock" aria-hidden="true"></i> Cheques will delay</div>
                            <?php if (!$has): ?><div class="ecur-remark">Emergency credit bill not needed</div><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="ecur-nowrap ecur-strong"><?php echo ecur_h($r['invoice_num']); ?></td>
                    <td class="ecur-bill">
                        <?php if ($r['credit_bill_no'] !== ''): ?>
                            <div class="ecur-strong ecur-billno"><?php echo ecur_h($r['credit_bill_no']); ?></div>
                            <button type="button" class="ecur-link" data-action="bill" <?php echo $data; ?>>Edit</button>
                        <?php else: ?>
                            <button type="button" class="ecur-btn ecur-btn--ghost ecur-btn--sm" data-action="bill" <?php echo $data; ?>>Add bill no</button>
                        <?php endif; ?>
                    </td>
                    <td class="ecur-reason">
                        <div><?php echo ecur_h($r['reason']); ?></div>
                        <div class="ecur-sub">Marked <?php echo ecur_h(ecur_datetime($r['marked_at'])); ?></div>
                    </td>
                    <td class="num">
                        <div class="ecur-strong"><?php echo ecur_money($r['credit_amount']); ?></div>
                        <div class="ecur-sub"><?php echo $r['invoice_amount'] !== null ? 'Invoice ' . ecur_money($r['invoice_amount']) : ''; ?></div>
                    </td>
                    <td class="ecur-pin">
                        <div class="ecur-doccell">
                            <?php if ($has): ?>
                                <button type="button" class="ecur-pill ecur-pill--ok" data-action="view" <?php echo $data; ?> title="View <?php echo (int)$r['doc_count']; ?> attached file<?php echo (int)$r['doc_count'] === 1 ? '' : 's'; ?>">Attached (<?php echo (int)$r['doc_count']; ?>)</button>
                            <?php elseif ($delay): ?>
                                <span class="ecur-pill ecur-pill--delay">Not needed</span>
                            <?php else: ?>
                                <span class="ecur-pill ecur-pill--missing">Not attached</span>
                            <?php endif; ?>
                            <button type="button" class="ecur-btn <?php echo (!$has && !$delay) ? 'ecur-btn--primary' : 'ecur-btn--ghost'; ?> ecur-btn--sm" data-action="upload" <?php echo $data; ?>><?php echo $has ? 'Add more' : 'Upload'; ?></button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php
        $from_n = ($d['page'] - 1) * ECUR_PER_PAGE + 1;
        $to_n   = $from_n + count($d['rows']) - 1;
    ?>
    <div class="ecur-pager">
        <span>Showing <?php echo number_format($from_n); ?>–<?php echo number_format($to_n); ?> of <?php echo number_format($d['total']); ?><?php echo $f['view'] === 'customers' ? ' customers' : ' bills'; ?></span>
        <?php if ($d['pages'] > 1): ?>
        <span class="ecur-pager-nav">
            <?php if ($d['page'] > 1): ?><a class="ecur-btn ecur-btn--ghost ecur-btn--sm" href="<?php echo ecur_h(ecur_url($f, ['page' => $d['page'] - 1])); ?>">Previous</a><?php endif; ?>
            <span>Page <?php echo $d['page']; ?> of <?php echo $d['pages']; ?></span>
            <?php if ($d['page'] < $d['pages']): ?><a class="ecur-btn ecur-btn--ghost ecur-btn--sm" href="<?php echo ecur_h(ecur_url($f, ['page' => $d['page'] + 1])); ?>">Next</a><?php endif; ?>
        </span>
        <?php endif; ?>
    </div>
    <?php endif;
}

/* ═══════════════════════════ NORMAL PAGE ═══════════════════════════ */

include 'header.php';            /* requires login */

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['ecur_csrf'])) $_SESSION['ecur_csrf'] = bin2hex(random_bytes(16));

ecur_ensure_schema($conn);
$f    = ecur_filters();
$data = ecur_load($conn, $f);

/* quick date ranges */
$ecur_today = date('Y-m-d');
$ecur_quick = [
    'Today'        => [$ecur_today, $ecur_today],
    'Yesterday'    => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    'Last 7 days'  => [date('Y-m-d', strtotime('-6 days')), $ecur_today],
    'This month'   => [date('Y-m-01'), $ecur_today],
    'Last month'   => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
    'All dates'    => ['', ''],
];
?>
<style>
.ecur, .ecur *, .ecur *::before, .ecur *::after { box-sizing: border-box; }
.ecur {
    --bg: #f4f5f8; --surface: #fff; --ink: #111827; --muted: #6b7280; --line: #e5e7eb;
    --red: #c81e1e; --red-dark: #a51717; --red-soft: #fdecec;
    --green: #157f3d; --green-soft: #e7f6ec;
    --amber: #d97706; --amber-dark: #92400e; --amber-soft: #fef3c7; --amber-row: #fffbeb;
    font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
    font-size: 13px; line-height: 1.45; color: var(--ink); background: var(--bg);
    padding: 22px 26px 48px; min-height: 100%;
}
.ecur a { color: inherit; }
.ecur-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

.ecur-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
.ecur-head h1 { margin: 0; font-size: 21px; font-weight: 700; letter-spacing: -.01em; }
.ecur-head p  { margin: 4px 0 0; color: var(--muted); max-width: 62ch; }
.ecur-head-links a { color: var(--muted); font-size: 12.5px; text-decoration: none; border-bottom: 1px solid var(--line); }
.ecur-head-links a:hover { color: var(--ink); border-color: var(--ink); }

.ecur-filter { background: var(--surface); border: 1px solid var(--line); border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; }
.ecur-filter form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; }
.ecur-field { display: flex; flex-direction: column; gap: 4px; }
.ecur-field label { font-size: 12px; font-weight: 600; color: var(--muted); }
.ecur-field input, .ecur-field select { height: 36px; padding: 0 10px; border: 1px solid #d1d5db; border-radius: 7px; font: inherit; color: var(--ink); background: #fff; }
.ecur-field input[type="search"] { width: 320px; max-width: 100%; }
.ecur-field select { padding-right: 28px; min-width: 150px; }
.ecur-field input:focus-visible, .ecur-field select:focus-visible { outline: 2px solid var(--red); outline-offset: 1px; border-color: var(--red); }
.ecur-quick { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--line); }
.ecur-quick a { padding: 4px 11px; border: 1px solid var(--line); border-radius: 999px; font-size: 12px; text-decoration: none; color: var(--muted); background: #fff; }
.ecur-quick a:hover { border-color: #9ca3af; color: var(--ink); }
.ecur-quick a.is-active { background: var(--ink); border-color: var(--ink); color: #fff; }

.ecur-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 36px; padding: 0 16px; border-radius: 7px; border: 1px solid transparent; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap; }
.ecur-btn--sm { height: 30px; padding: 0 12px; font-size: 12.5px; }
.ecur-btn--primary { background: var(--red); color: #fff; }
.ecur-btn--primary:hover { background: var(--red-dark); }
.ecur-btn--ghost { background: #fff; border-color: #d1d5db; color: var(--ink); }
.ecur-btn--ghost:hover { border-color: #6b7280; }
.ecur-btn--dark { background: var(--ink); color: #fff; }
.ecur-btn--dark:hover { background: #000; }
.ecur-btn:disabled { opacity: .55; cursor: not-allowed; }
.ecur-btn:focus-visible, .ecur-pill:focus-visible, .ecur-tabs a:focus-visible, .ecur-quick a:focus-visible, .ecur a:focus-visible { outline: 2px solid var(--red); outline-offset: 2px; }

.ecur-kpis { display: grid; grid-template-columns: 1.5fr 1fr 1fr 1fr; gap: 12px; margin-bottom: 18px; }
.ecur-kpi { background: var(--surface); border: 1px solid var(--line); border-radius: 10px; padding: 14px 16px; }
.ecur-kpi--lead { border-color: #f3c1c1; background: var(--red-soft); }
.ecur-kpi--lead.is-clear { border-color: #b7e3c6; background: var(--green-soft); }
.ecur-kpi--lead.is-clear .ecur-kpi-label, .ecur-kpi--lead.is-clear .ecur-kpi-big, .ecur-kpi--lead.is-clear .ecur-kpi-note { color: var(--green); }
.ecur-kpi-label { font-size: 12px; font-weight: 600; color: var(--muted); }
.ecur-kpi--lead .ecur-kpi-label { color: var(--red-dark); }
.ecur-kpi-big { font-size: 38px; line-height: 1.1; font-weight: 700; letter-spacing: -.02em; color: var(--red); margin-top: 4px; font-variant-numeric: tabular-nums; }
.ecur-kpi-big small { font-size: 14px; font-weight: 600; letter-spacing: 0; }
.ecur-kpi-mid { font-size: 24px; line-height: 1.2; font-weight: 700; margin-top: 4px; font-variant-numeric: tabular-nums; }
.ecur-kpi-note { margin-top: 4px; font-size: 12px; color: var(--muted); font-variant-numeric: tabular-nums; }
.ecur-kpi--lead .ecur-kpi-note { color: #7f1d1d; }
.ecur-meter { height: 6px; border-radius: 3px; background: #e5e7eb; margin-top: 12px; overflow: hidden; }
.ecur-meter span { display: block; height: 100%; background: var(--green); border-radius: 3px; }

.ecur-tabbar { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; flex-wrap: wrap; border-bottom: 1px solid var(--line); margin-bottom: 12px; }
.ecur-tabs { display: flex; gap: 4px; flex-wrap: wrap; }
.ecur-viewswitch { display: inline-flex; margin-bottom: 6px; border: 1px solid #d1d5db; border-radius: 8px; overflow: hidden; background: #fff; }
.ecur-viewswitch a { padding: 6px 14px; font-weight: 600; font-size: 12.5px; color: var(--muted); text-decoration: none; }
.ecur-viewswitch a + a { border-left: 1px solid #d1d5db; }
.ecur-viewswitch a:hover { color: var(--ink); }
.ecur-viewswitch a.is-active { background: var(--ink); color: #fff; }
.ecur-viewswitch a span { margin-left: 4px; padding: 0 6px; border-radius: 999px; background: #e5e7eb; color: #374151; font-size: 11px; }
.ecur-viewswitch a.is-active span { background: rgba(255,255,255,.2); color: #fff; }

/* payment mode chips + badges */
.ecur-modes { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: -6px 0 16px; }
.ecur-modes-label { font-size: 12px; font-weight: 600; color: var(--muted); margin-right: 4px; }
.ecur-modechip { display: inline-flex; align-items: baseline; gap: 5px; padding: 4px 11px; border: 1px solid var(--line); border-radius: 999px; background: #fff; font-size: 12px; text-decoration: none; color: var(--ink); }
.ecur-modechip b { font-variant-numeric: tabular-nums; }
.ecur-modechip small { color: var(--muted); font-size: 11px; }
.ecur-modechip:hover { border-color: #9ca3af; }
.ecur-modechip.is-active { background: var(--ink); border-color: var(--ink); color: #fff; }
.ecur-modechip.is-active small { color: #d1d5db; }
.ecur-mode { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 600; white-space: nowrap; border: 1px solid transparent; }
.ecur-mode--cash   { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
.ecur-mode--credit { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
.ecur-mode--cheque { background: #f5f3ff; color: #6d28d9; border-color: #ddd6fe; }
.ecur-mode--none   { background: #f3f4f6; color: #6b7280; border-color: #e5e7eb; }
.ecur-custmeta { display: flex; flex-wrap: wrap; align-items: center; gap: 5px; margin-top: 4px; }
.ecur-emgcount { font-size: 11.5px; font-weight: 600; color: #374151; text-decoration: none; padding: 1px 8px; border-radius: 999px; background: #f3f4f6; border: 1px solid #e5e7eb; white-space: nowrap; }
.ecur-emgcount.is-high, .ecur-count.is-high { background: var(--red-soft); color: var(--red-dark); border-color: #f3c1c1; }
.ecur-count { display: inline-block; min-width: 30px; padding: 2px 9px; border-radius: 999px; background: #f3f4f6; border: 1px solid #e5e7eb; font-weight: 700; text-align: center; }
.ecur-red { color: var(--red-dark); } .ecur-green { color: var(--green); font-weight: 600; } .ecur-amber { color: var(--amber-dark); font-weight: 600; }
.ecur-tabs a { display: inline-block; padding: 9px 14px; font-weight: 600; color: var(--muted); text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.ecur-tabs a span { margin-left: 4px; padding: 1px 7px; border-radius: 999px; background: #e5e7eb; font-size: 11.5px; color: #374151; font-variant-numeric: tabular-nums; }
.ecur-tabs a:hover { color: var(--ink); }
.ecur-tabs a.is-active { color: var(--ink); border-bottom-color: var(--ink); }
.ecur-tabs a.ecur-tab-missing.is-active { border-bottom-color: var(--red); }
.ecur-tabs a.ecur-tab-missing span { background: var(--red-soft); color: var(--red-dark); }
.ecur-tabs a.ecur-tab-ok.is-active { border-bottom-color: var(--green); }
.ecur-tabs a.ecur-tab-ok span { background: var(--green-soft); color: var(--green); }
.ecur-tabs a.ecur-tab-delay.is-active { border-bottom-color: var(--amber); }
.ecur-tabs a.ecur-tab-delay span { background: var(--amber-soft); color: var(--amber-dark); }

.ecur-tablewrap { position: relative; background: var(--surface); border: 1px solid var(--line); border-radius: 10px; overflow-x: auto; }
.ecur-table { width: 100%; min-width: 860px; border-collapse: collapse; }
.ecur-table th { text-align: left; padding: 10px 12px; font-size: 12px; font-weight: 600; color: var(--muted); background: #f9fafb; border-bottom: 1px solid var(--line); white-space: nowrap; }
.ecur-table td { padding: 10px 12px; border-bottom: 1px solid #f0f1f4; vertical-align: top; }
.ecur-table tbody tr:last-child td { border-bottom: 0; }
.ecur-table tbody tr:hover td { background: #fafafb; }
.ecur-table .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.ecur-table tr.is-missing td:first-child { box-shadow: inset 3px 0 0 var(--red); }
.ecur-strong { font-weight: 600; }
.ecur-sub { color: var(--muted); font-size: 12px; text-decoration: none; }
a.ecur-sub:hover { color: var(--ink); text-decoration: underline; }
.ecur-nowrap { white-space: nowrap; }
.ecur-reason { min-width: 150px; max-width: 220px; }
.ecur-cust { min-width: 130px; }
.ecur-table th.ecur-wraphead { white-space: normal; min-width: 120px; }
.ecur-doccell { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; }
.ecur-table th.ecur-pin, .ecur-table td.ecur-pin { position: sticky; right: 0; background: #fff; box-shadow: -1px 0 0 var(--line); }
.ecur-table th.ecur-pin { background: #f9fafb; }
.ecur-table tbody tr:hover td.ecur-pin { background: #fafafb; }

.ecur-pill { display: inline-block; white-space: nowrap; padding: 3px 10px; border-radius: 999px; font: inherit; font-size: 12px; font-weight: 600; border: 1px solid transparent; }
.ecur-pill--missing { background: var(--red-soft); color: var(--red-dark); border-color: #f3c1c1; }
.ecur-pill--ok { background: var(--green-soft); color: var(--green); border-color: #b7e3c6; cursor: pointer; }
.ecur-pill--ok:hover { border-color: var(--green); }
.ecur-pill--delay { background: var(--amber-soft); color: var(--amber-dark); border-color: #f5d68a; }

/* customers marked "Cheques Will Delay": amber row, label, remark */
.ecur-table tr.is-delay td { background: var(--amber-row); }
.ecur-table tr.is-delay td:first-child { box-shadow: inset 3px 0 0 var(--amber); }
.ecur-table tbody tr.is-delay:hover td, .ecur-table tbody tr.is-delay:hover td.ecur-pin { background: var(--amber-soft); }
.ecur-tag { display: inline-block; margin-top: 4px; padding: 2px 8px; border-radius: 999px; background: var(--amber-soft); color: var(--amber-dark); border: 1px solid #f5d68a; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
.ecur-tag i { margin-right: 3px; }
.ecur-remark { margin-top: 3px; font-size: 12px; font-weight: 600; color: var(--amber-dark); }
.ecur-note { margin: 0 0 12px; padding: 9px 12px; border-radius: 8px; background: var(--amber-soft); border: 1px solid #f5d68a; color: var(--amber-dark); }

/* credit bill no cell */
.ecur-bill { min-width: 110px; }
.ecur-billno { max-width: 170px; word-break: break-all; }
.ecur-link { padding: 0; margin-top: 2px; border: 0; background: none; font: inherit; font-size: 12px; font-weight: 600; color: var(--muted); text-decoration: underline; cursor: pointer; }
.ecur-link:hover { color: var(--ink); }
.ecur-link:focus-visible { outline: 2px solid var(--red); outline-offset: 2px; }
.ecur-billfield label { display: block; margin-bottom: 6px; font-size: 12px; font-weight: 600; color: var(--muted); }
.ecur-billfield input { width: 100%; height: 40px; padding: 0 12px; border: 1px solid #d1d5db; border-radius: 7px; font: inherit; font-size: 14px; color: var(--ink); }
.ecur-billfield input:focus-visible { outline: 2px solid var(--red); outline-offset: 1px; border-color: var(--red); }

.ecur-pager { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 12px; color: var(--muted); }
.ecur-pager-nav { display: inline-flex; align-items: center; gap: 10px; }

.ecur-empty { background: var(--surface); border: 1px dashed #cbd5e1; border-radius: 10px; padding: 36px 20px; text-align: center; }
.ecur-empty p { margin: 6px 0 14px; color: var(--muted); }
.ecur-alert { background: var(--red-soft); border: 1px solid #f3c1c1; color: #7f1d1d; border-radius: 10px; padding: 14px 16px; word-break: break-word; }

/* modals */
.ecur-modal { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(17,24,39,.55); }
.ecur-modal[hidden] { display: none; }
.ecur-dialog { width: 100%; max-width: 560px; max-height: calc(100vh - 32px); display: flex; flex-direction: column; background: #fff; border-radius: 12px; box-shadow: 0 20px 50px rgba(0,0,0,.28); overflow: hidden; }
.ecur-dialog--wide { max-width: 760px; }
.ecur-dialog-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 16px 18px 12px; border-bottom: 1px solid var(--line); }
.ecur-dialog-head h2 { margin: 0; font-size: 16px; font-weight: 700; }
.ecur-dialog-head p { margin: 3px 0 0; color: var(--muted); font-size: 12.5px; word-break: break-word; }
.ecur-x { flex: none; width: 30px; height: 30px; border: 0; background: transparent; border-radius: 6px; font-size: 20px; line-height: 1; cursor: pointer; color: var(--muted); }
.ecur-x:hover { background: #f3f4f6; color: var(--ink); }
.ecur-x:focus-visible { outline: 2px solid var(--red); }
.ecur-dialog-body { padding: 16px 18px; overflow-y: auto; }
.ecur-dialog-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 18px; border-top: 1px solid var(--line); background: #fafafb; }

.ecur-drop { border: 2px dashed #cbd5e1; border-radius: 10px; padding: 26px 16px; text-align: center; background: #fafafb; transition: border-color .15s, background .15s; }
.ecur-drop.is-over { border-color: var(--red); background: var(--red-soft); }
.ecur-drop p { margin: 0 0 4px; font-weight: 600; }
.ecur-drop small { display: block; margin-bottom: 12px; color: var(--muted); }
.ecur-files { list-style: none; margin: 12px 0 0; padding: 0; display: grid; gap: 6px; }
.ecur-files li { display: flex; align-items: center; gap: 10px; padding: 7px 10px; border: 1px solid var(--line); border-radius: 8px; }
.ecur-files .nm { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500; }
.ecur-files .sz { color: var(--muted); font-size: 12px; white-space: nowrap; }
.ecur-files button { border: 0; background: transparent; color: var(--muted); cursor: pointer; font-size: 12px; text-decoration: underline; }
.ecur-files button:hover { color: var(--red); }
.ecur-msg { margin-top: 12px; padding: 9px 12px; border-radius: 8px; font-size: 12.5px; background: var(--red-soft); color: #7f1d1d; border: 1px solid #f3c1c1; }
.ecur-msg[hidden] { display: none; }
.ecur-progress { height: 6px; border-radius: 3px; background: #e5e7eb; margin-top: 12px; overflow: hidden; }
.ecur-progress[hidden] { display: none; }
.ecur-progress span { display: block; height: 100%; width: 0; background: var(--red); transition: width .15s linear; }

.ecur-docgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
.ecur-doc { border: 1px solid var(--line); border-radius: 10px; overflow: hidden; display: flex; flex-direction: column; background: #fff; }
.ecur-doc-thumb { height: 110px; display: flex; align-items: center; justify-content: center; background: #f3f4f6; border: 0; padding: 0; cursor: pointer; width: 100%; text-decoration: none; color: var(--muted); font-weight: 700; font-size: 15px; }
.ecur-doc-thumb img { width: 100%; height: 100%; object-fit: cover; }
.ecur-doc-thumb:focus-visible { outline: 2px solid var(--red); outline-offset: -2px; }
.ecur-doc-meta { padding: 8px 10px; }
.ecur-doc-meta .nm { font-weight: 600; word-break: break-all; font-size: 12.5px; }
.ecur-doc-meta .ds { color: var(--muted); font-size: 11.5px; }

.ecur-lightbox { position: fixed; inset: 0; z-index: 2100; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,.86); padding: 24px; }
.ecur-lightbox[hidden] { display: none; }
.ecur-lightbox img { max-width: 100%; max-height: 100%; border-radius: 6px; background: #fff; }
.ecur-lightbox .ecur-x { position: absolute; top: 14px; right: 14px; color: #fff; font-size: 26px; width: 38px; height: 38px; }
.ecur-lightbox .ecur-x:hover { background: rgba(255,255,255,.15); color: #fff; }

.ecur-toast { position: fixed; left: 50%; bottom: 28px; transform: translateX(-50%); z-index: 2200; max-width: min(90vw, 520px); padding: 10px 16px; border-radius: 8px; background: #111827; color: #fff; font-weight: 500; box-shadow: 0 8px 24px rgba(0,0,0,.25); }
.ecur-toast[hidden] { display: none; }
.ecur-toast.is-error { background: var(--red); }

@media (max-width: 900px) { .ecur-kpis { grid-template-columns: 1fr 1fr; } .ecur-kpi--lead { grid-column: 1 / -1; } }
@media (max-width: 900px) { .ecur-table th.ecur-pin, .ecur-table td.ecur-pin { position: static; box-shadow: none; } }
@media (max-width: 560px) { .ecur { padding: 16px 12px 40px; } .ecur-field { flex: 1 1 140px; } .ecur-field input[type="search"] { width: 100%; } }
@media (prefers-reduced-motion: reduce) { .ecur-drop, .ecur-progress span { transition: none; } }
</style>

<div class="ecur" id="ecurApp" data-endpoint="<?php echo ecur_h(basename(__FILE__)); ?>" data-csrf="<?php echo ecur_h($_SESSION['ecur_csrf']); ?>">

    <header class="ecur-head">
        <div>
            <h1>Emergency credit bill upload report</h1>
            <p>Invoices marked as Emergency Credit when payments were added in a field summary, and whether the credit bill document has been uploaded.</p>
        </div>
        <div class="ecur-head-links">
            <a href="emergency_credit_bill_upload.php">Bulk upload with AI scan</a>
        </div>
    </header>

    <section class="ecur-filter" aria-label="Filters">
        <form method="get" action="<?php echo ecur_h(basename(__FILE__)); ?>">
            <input type="hidden" name="status" value="<?php echo ecur_h($f['status']); ?>">
            <?php if ($f['view'] !== 'bills'): ?><input type="hidden" name="view" value="<?php echo ecur_h($f['view']); ?>"><?php endif; ?>
            <div class="ecur-field">
                <label for="ecurFrom">Delivery date from</label>
                <input type="date" id="ecurFrom" name="from" value="<?php echo ecur_h($f['from']); ?>">
            </div>
            <div class="ecur-field">
                <label for="ecurTo">Delivery date to</label>
                <input type="date" id="ecurTo" name="to" value="<?php echo ecur_h($f['to']); ?>">
            </div>
            <div class="ecur-field">
                <label for="ecurQ">Search</label>
                <input type="search" id="ecurQ" name="q" value="<?php echo ecur_h($f['q']); ?>" placeholder="Invoice, credit bill no, customer or T-code" maxlength="100">
            </div>
            <div class="ecur-field">
                <label for="ecurMode">Customer payment mode</label>
                <select id="ecurMode" name="mode">
                    <option value="">All modes</option>
                    <?php foreach (ecur_modes() as $mk => $ml): ?>
                        <option value="<?php echo $mk; ?>"<?php echo $f['mode'] === $mk ? ' selected' : ''; ?>><?php echo $ml; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="ecur-btn ecur-btn--dark">Apply filters</button>
        </form>
        <div class="ecur-quick" aria-label="Quick delivery date ranges">
            <?php foreach ($ecur_quick as $label => $range):
                $active = ($f['from'] === $range[0] && $f['to'] === $range[1]); ?>
                <a href="<?php echo ecur_h(ecur_url($f, ['from' => $range[0], 'to' => $range[1], 'page' => 1])); ?>" class="<?php echo $active ? 'is-active' : ''; ?>"><?php echo ecur_h($label); ?></a>
            <?php endforeach; ?>
        </div>
    </section>

    <div id="ecurReport"><?php ecur_render($f, $data); ?></div>

    <!-- Upload dialog -->
    <div class="ecur-modal" id="ecurUploadModal" hidden>
        <div class="ecur-dialog" role="dialog" aria-modal="true" aria-labelledby="ecurUpTitle">
            <div class="ecur-dialog-head">
                <div>
                    <h2 id="ecurUpTitle">Upload credit bill documents</h2>
                    <p id="ecurUpSub"></p>
                </div>
                <button type="button" class="ecur-x" data-close="upload" aria-label="Close">&times;</button>
            </div>
            <div class="ecur-dialog-body">
                <div class="ecur-drop" id="ecurDrop">
                    <p>Drop files here</p>
                    <small>JPG, PNG, GIF, WEBP, PDF, DOC or DOCX. Up to 10 MB each.</small>
                    <button type="button" class="ecur-btn ecur-btn--ghost" id="ecurPick">Choose files</button>
                    <input type="file" id="ecurFileInput" multiple accept="image/*,.pdf,.doc,.docx" hidden>
                </div>
                <ul class="ecur-files" id="ecurFileList"></ul>
                <div class="ecur-msg" id="ecurUpMsg" role="alert" hidden></div>
                <div class="ecur-progress" id="ecurProgress" hidden><span></span></div>
            </div>
            <div class="ecur-dialog-foot">
                <button type="button" class="ecur-btn ecur-btn--ghost" data-close="upload">Cancel</button>
                <button type="button" class="ecur-btn ecur-btn--primary" id="ecurSubmit" disabled>Upload</button>
            </div>
        </div>
    </div>

    <!-- Existing documents dialog -->
    <div class="ecur-modal" id="ecurDocsModal" hidden>
        <div class="ecur-dialog ecur-dialog--wide" role="dialog" aria-modal="true" aria-labelledby="ecurDocTitle">
            <div class="ecur-dialog-head">
                <div>
                    <h2 id="ecurDocTitle">Documents</h2>
                    <p id="ecurDocSub"></p>
                </div>
                <button type="button" class="ecur-x" data-close="docs" aria-label="Close">&times;</button>
            </div>
            <div class="ecur-dialog-body"><div id="ecurDocBody" class="ecur-docgrid"></div></div>
            <div class="ecur-dialog-foot">
                <button type="button" class="ecur-btn ecur-btn--ghost" data-close="docs">Close</button>
                <button type="button" class="ecur-btn ecur-btn--primary" id="ecurDocAdd">Add more documents</button>
            </div>
        </div>
    </div>

    <!-- Add / edit credit bill no dialog -->
    <div class="ecur-modal" id="ecurBillModal" hidden>
        <div class="ecur-dialog" role="dialog" aria-modal="true" aria-labelledby="ecurBillTitle">
            <div class="ecur-dialog-head">
                <div>
                    <h2 id="ecurBillTitle">Add credit bill no</h2>
                    <p id="ecurBillSub"></p>
                </div>
                <button type="button" class="ecur-x" data-close="bill" aria-label="Close">&times;</button>
            </div>
            <div class="ecur-dialog-body">
                <div class="ecur-billfield">
                    <label for="ecurBillInput">Credit bill no</label>
                    <input type="text" id="ecurBillInput" maxlength="100" autocomplete="off" placeholder="e.g. CB-2025-001">
                </div>
                <div class="ecur-msg" id="ecurBillMsg" role="alert" hidden></div>
            </div>
            <div class="ecur-dialog-foot">
                <button type="button" class="ecur-btn ecur-btn--ghost" data-close="bill">Cancel</button>
                <button type="button" class="ecur-btn ecur-btn--primary" id="ecurBillSave">Save</button>
            </div>
        </div>
    </div>

    <div class="ecur-lightbox" id="ecurLightbox" hidden>
        <button type="button" class="ecur-x" data-close="lightbox" aria-label="Close image">&times;</button>
        <img alt="Document preview" id="ecurLightboxImg">
    </div>

    <div class="ecur-toast" id="ecurToast" role="status" aria-live="polite" hidden></div>
</div>

<script>
(function () {
    'use strict';

    var app      = document.getElementById('ecurApp');
    var ENDPOINT = app.dataset.endpoint;
    var CSRF     = app.dataset.csrf;
    var MAX_BYTES = 10 * 1024 * 1024;
    var MAX_FILES = 20;
    var EXT_OK   = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx'];

    var $ = function (id) { return document.getElementById(id); };
    var upModal = $('ecurUploadModal'), docsModal = $('ecurDocsModal'), lightbox = $('ecurLightbox'), billModal = $('ecurBillModal');
    var billInput = $('ecurBillInput'), billMsg = $('ecurBillMsg'), billSave = $('ecurBillSave'), billBusy = false;
    var fileInput = $('ecurFileInput'), fileList = $('ecurFileList'), upMsg = $('ecurUpMsg');
    var submitBtn = $('ecurSubmit'), progress = $('ecurProgress'), toastEl = $('ecurToast');

    var current = null;      // {id, invoice, customer, bill, docs}
    var files = [];          // File[] chosen for the open upload dialog
    var busy = false;
    var lastTrigger = null;

    /* ---------- helpers ---------- */
    function toast(msg, isError) {
        toastEl.textContent = msg;
        toastEl.classList.toggle('is-error', !!isError);
        toastEl.hidden = false;
        clearTimeout(toast._t);
        toast._t = setTimeout(function () { toastEl.hidden = true; }, isError ? 6000 : 3500);
    }
    function fmtSize(b) {
        if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
        if (b >= 1024) return Math.round(b / 1024) + ' KB';
        return b + ' B';
    }
    function ext(name) { var i = name.lastIndexOf('.'); return i < 0 ? '' : name.slice(i + 1).toLowerCase(); }
    function describe(row) {
        var parts = ['Invoice ' + row.invoice];
        if (row.customer) parts.push(row.customer);
        if (row.bill) parts.push('credit bill ' + row.bill);
        return parts.join(', ');
    }
    function showMsg(text) { upMsg.textContent = text; upMsg.hidden = !text; }
    function open(modal) {
        modal.hidden = false;
        var f = modal.querySelector('button, input, a');
        if (f) f.focus();
    }
    function close(modal) {
        modal.hidden = true;
        if (lastTrigger && document.contains(lastTrigger)) lastTrigger.focus();
    }

    /* ---------- upload dialog ---------- */
    function renderFiles() {
        fileList.innerHTML = '';
        files.forEach(function (f, i) {
            var li = document.createElement('li');
            var nm = document.createElement('span'); nm.className = 'nm'; nm.textContent = f.name; nm.title = f.name;
            var sz = document.createElement('span'); sz.className = 'sz'; sz.textContent = fmtSize(f.size);
            var rm = document.createElement('button'); rm.type = 'button'; rm.textContent = 'Remove';
            rm.setAttribute('aria-label', 'Remove ' + f.name);
            rm.addEventListener('click', function () { if (!busy) { files.splice(i, 1); renderFiles(); } });
            li.appendChild(nm); li.appendChild(sz); li.appendChild(rm);
            fileList.appendChild(li);
        });
        submitBtn.disabled = busy || files.length === 0;
        submitBtn.textContent = files.length > 1 ? 'Upload ' + files.length + ' files' : 'Upload';
    }
    function addFiles(list) {
        var problems = [];
        Array.prototype.forEach.call(list, function (f) {
            if (EXT_OK.indexOf(ext(f.name)) < 0) { problems.push(f.name + ' is not an allowed type.'); return; }
            if (f.size > MAX_BYTES) { problems.push(f.name + ' is larger than 10 MB.'); return; }
            if (f.size === 0) { problems.push(f.name + ' is empty.'); return; }
            if (files.length >= MAX_FILES) { problems.push('Only ' + MAX_FILES + ' files can be uploaded at once.'); return; }
            files.push(f);
        });
        showMsg(problems.join(' '));
        renderFiles();
    }
    function openUpload(row) {
        current = row; files = []; busy = false;
        fileInput.value = '';
        showMsg(''); progress.hidden = true; progress.firstElementChild.style.width = '0';
        $('ecurUpTitle').textContent = row.docs > 0 ? 'Add more documents' : 'Upload credit bill documents';
        $('ecurUpSub').textContent = describe(row);
        renderFiles();
        open(upModal);
    }
    function refreshReport() {
        var params = new URLSearchParams(window.location.search);
        params.set('ajax', 'fragment');
        return fetch(ENDPOINT + '?' + params.toString(), { credentials: 'same-origin' })
            .then(function (res) { return res.ok ? res.text() : Promise.reject(); })
            .then(function (html) {
                if (html.indexOf('<!--ecur-fragment-->') !== 0) return Promise.reject();
                $('ecurReport').innerHTML = html;
            })
            .catch(function () { window.location.reload(); });
    }
    function submitUpload() {
        if (busy || !current || !files.length) return;
        busy = true; showMsg(''); renderFiles();
        submitBtn.textContent = 'Uploading…';
        progress.hidden = false;

        var fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('credit_request_id', current.id);
        files.forEach(function (f) { fd.append('documents[]', f, f.name); });

        var xhr = new XMLHttpRequest();
        xhr.open('POST', ENDPOINT + '?ajax=upload');
        xhr.upload.onprogress = function (e) {
            if (e.lengthComputable) progress.firstElementChild.style.width = Math.round(e.loaded / e.total * 100) + '%';
        };
        function fail(text) { busy = false; progress.hidden = true; showMsg(text); renderFiles(); }
        xhr.onerror = function () { fail('The upload did not reach the server. Check the connection and try again.'); };
        xhr.onload = function () {
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (e) {}
            if (!res) { fail('The server sent an unexpected reply. Your session may have expired — reload the page and try again.'); return; }
            if (!res.success) { fail(res.error || 'The upload failed.'); return; }
            var label = current.invoice;
            busy = false;
            close(upModal);
            var msg = 'Uploaded ' + res.saved + (res.saved === 1 ? ' file' : ' files') + ' to invoice ' + label + '.';
            if (res.warnings && res.warnings.length) msg += ' Skipped: ' + res.warnings.join(' ');
            toast(msg, res.warnings && res.warnings.length);
            refreshReport();
        };
        xhr.send(fd);
    }

    /* ---------- documents dialog ---------- */
    function openDocs(row) {
        current = row;
        $('ecurDocTitle').textContent = 'Documents';
        $('ecurDocSub').textContent = describe(row);
        var body = $('ecurDocBody');
        body.innerHTML = '<p class="ecur-sub">Loading…</p>';
        open(docsModal);
        fetch(ENDPOINT + '?ajax=docs&id=' + encodeURIComponent(row.id), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                body.innerHTML = '';
                if (!res.success) { body.textContent = res.error || 'Could not load the documents.'; return; }
                if (!res.docs.length) { body.innerHTML = '<p class="ecur-sub">No documents yet.</p>'; return; }
                res.docs.forEach(function (d) {
                    var card = document.createElement('div'); card.className = 'ecur-doc';
                    var thumb;
                    if (d.url && d.is_image) {
                        thumb = document.createElement('button'); thumb.type = 'button'; thumb.className = 'ecur-doc-thumb';
                        thumb.setAttribute('aria-label', 'View ' + d.name);
                        var img = document.createElement('img'); img.loading = 'lazy'; img.alt = d.name; img.src = d.url;
                        thumb.appendChild(img);
                        thumb.addEventListener('click', function () { $('ecurLightboxImg').src = d.url; lightbox.hidden = false; });
                    } else {
                        thumb = document.createElement(d.url ? 'a' : 'div'); thumb.className = 'ecur-doc-thumb';
                        thumb.textContent = ext(d.name).toUpperCase() || 'FILE';
                        if (d.url) { thumb.href = d.url; thumb.target = '_blank'; thumb.rel = 'noopener'; thumb.setAttribute('aria-label', 'Open ' + d.name); }
                    }
                    var meta = document.createElement('div'); meta.className = 'ecur-doc-meta';
                    var nm = document.createElement('div'); nm.className = 'nm'; nm.textContent = d.name;
                    var ds = document.createElement('div'); ds.className = 'ds'; ds.textContent = d.size + (d.uploaded ? ', ' + d.uploaded : '');
                    meta.appendChild(nm); meta.appendChild(ds);
                    card.appendChild(thumb); card.appendChild(meta);
                    body.appendChild(card);
                });
            })
            .catch(function () { body.textContent = 'Could not load the documents. Reload the page and try again.'; });
    }

    /* ---------- add / edit credit bill no ---------- */
    function showBillMsg(text) { billMsg.textContent = text; billMsg.hidden = !text; }
    function openBill(row) {
        current = row; billBusy = false;
        $('ecurBillTitle').textContent = row.bill ? 'Edit credit bill no' : 'Add credit bill no';
        $('ecurBillSub').textContent = 'Invoice ' + row.invoice + (row.customer ? ', ' + row.customer : '');
        billInput.value = row.bill || '';
        showBillMsg('');
        billSave.disabled = false; billSave.textContent = 'Save';
        open(billModal);
        billInput.focus(); billInput.select();
    }
    function saveBill() {
        if (billBusy || !current) return;
        var val = billInput.value.trim();
        if (!val) { showBillMsg('Enter the credit bill no.'); billInput.focus(); return; }
        if (val.length > 100) { showBillMsg('The credit bill no must be 100 characters or fewer.'); billInput.focus(); return; }
        billBusy = true; showBillMsg(''); billSave.disabled = true; billSave.textContent = 'Saving…';

        var fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('credit_request_id', current.id);
        fd.append('credit_bill_no', val);
        function fail(text) { billBusy = false; billSave.disabled = false; billSave.textContent = 'Save'; showBillMsg(text); }
        fetch(ENDPOINT + '?ajax=save_bill_no', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (res) {
                if (!res) { fail('The server sent an unexpected reply. Your session may have expired — reload the page and try again.'); return; }
                if (!res.success) { fail(res.error || 'The credit bill no could not be saved.'); return; }
                var inv = current.invoice, dup = res.duplicates && res.duplicates.length;
                billBusy = false;
                close(billModal);
                toast('Credit bill no ' + res.credit_bill_no + ' saved for invoice ' + inv + '.' +
                      (dup ? ' Note: it is also used on invoice ' + res.duplicates.join(', ') + '.' : ''), dup);
                refreshReport();
            })
            .catch(function () { fail('The change did not reach the server. Check the connection and try again.'); });
    }

    /* ---------- events ---------- */
    function rowFrom(btn) {
        return { id: btn.dataset.id, invoice: btn.dataset.invoice, customer: btn.dataset.customer,
                 bill: btn.dataset.bill, docs: parseInt(btn.dataset.docs, 10) || 0 };
    }
    app.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-action]');
        if (btn) {
            lastTrigger = btn;
            if (btn.dataset.action === 'upload') openUpload(rowFrom(btn));
            else if (btn.dataset.action === 'view') openDocs(rowFrom(btn));
            else if (btn.dataset.action === 'bill') openBill(rowFrom(btn));
            return;
        }
        var closer = e.target.closest('[data-close]');
        if (closer) {
            var k = closer.dataset.close;
            if (k === 'upload') { if (!busy) close(upModal); }
            else if (k === 'docs') close(docsModal);
            else if (k === 'bill') { if (!billBusy) close(billModal); }
            else if (k === 'lightbox') { lightbox.hidden = true; $('ecurLightboxImg').src = ''; }
            return;
        }
        if (e.target === upModal && !busy) close(upModal);
        if (e.target === docsModal) close(docsModal);
        if (e.target === billModal && !billBusy) close(billModal);
        if (e.target === lightbox) { lightbox.hidden = true; $('ecurLightboxImg').src = ''; }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (!lightbox.hidden) { lightbox.hidden = true; $('ecurLightboxImg').src = ''; }
        else if (!upModal.hidden) { if (!busy) close(upModal); }
        else if (!docsModal.hidden) close(docsModal);
        else if (!billModal.hidden) { if (!billBusy) close(billModal); }
    });

    $('ecurPick').addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () { addFiles(fileInput.files); fileInput.value = ''; });
    submitBtn.addEventListener('click', submitUpload);
    billSave.addEventListener('click', saveBill);
    billInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); saveBill(); } });
    $('ecurDocAdd').addEventListener('click', function () { var r = current; close(docsModal); openUpload(r); });

    var drop = $('ecurDrop');
    ['dragenter', 'dragover'].forEach(function (t) {
        drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
    });
    ['dragleave', 'drop'].forEach(function (t) {
        drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
    });
    drop.addEventListener('drop', function (e) { if (!busy && e.dataTransfer) addFiles(e.dataTransfer.files); });
})();
</script>

<?php include 'footer.php'; ?>