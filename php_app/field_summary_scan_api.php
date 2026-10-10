<?php
/**
 * Field Summary Scan API
 *   GET  ?action=list&field_summary_id=ID
 *   POST action=upload, field_summary_id=ID, scans[]=files (images / PDF, multiple)
 *   POST action=delete, id=SCAN_ID
 * Always returns JSON — PHP warnings, DB errors and fatal errors are caught
 * and returned as {"success":false,"message":...} instead of breaking the JSON.
 */

// Capture anything config.php / PHP prints (BOM, whitespace, warnings) so it can't corrupt the JSON
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

function respond(array $data, int $code = 200) {
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

// Uncaught exceptions (incl. mysqli_sql_exception on PHP 8.1+) → JSON
set_exception_handler(function ($e) {
    error_log('field_summary_scan_api: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    respond(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
});
// Fatal errors (undefined function, memory, etc.) → JSON
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('field_summary_scan_api fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        respond(['success' => false, 'message' => 'Server error: ' . $err['message']], 500);
    }
});

// Make mysqli throw instead of returning false (so failures are reported, not silent)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include 'config.php';

// If your login check lives in header.php, repeat it here, e.g.:
// if (session_status() === PHP_SESSION_NONE) session_start();
// if (empty($_SESSION['user_id'])) respond(['success'=>false,'message'=>'Not logged in'], 401);

if (!isset($conn) || !($conn instanceof mysqli)) {
    respond(['success' => false, 'message' => 'Database connection ($conn) not available from config.php'], 500);
}

define('SCAN_DIR', __DIR__ . '/uploads/field_summary_scans/');
define('SCAN_URL', 'uploads/field_summary_scans/');
define('SCAN_MAX_SIZE', 10 * 1024 * 1024);   // 10 MB per file
define('SCAN_MAX_FILES', 20);                // per upload request

$ALLOWED = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'image/gif'       => 'gif',
    'application/pdf' => 'pdf',
];

/** Create the scans table / summary columns if they are missing */
function ensure_schema($conn): void {
    mysqli_query($conn,
        "CREATE TABLE IF NOT EXISTS field_summary_scans (
            id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            field_summary_id INT NOT NULL,
            file_name        VARCHAR(255) NOT NULL,
            original_name    VARCHAR(255) NOT NULL,
            file_type        VARCHAR(10)  NOT NULL DEFAULT 'image',
            mime_type        VARCHAR(100) NOT NULL,
            file_size        INT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_fs (field_summary_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $cols = [];
    $res = mysqli_query($conn, "SHOW COLUMNS FROM field_summary");
    while ($r = mysqli_fetch_assoc($res)) $cols[strtolower($r['Field'])] = true;
    if (!isset($cols['scan_count'])) {
        mysqli_query($conn, "ALTER TABLE field_summary ADD COLUMN scan_count INT NOT NULL DEFAULT 0");
    }
    if (!isset($cols['last_scan_at'])) {
        mysqli_query($conn, "ALTER TABLE field_summary ADD COLUMN last_scan_at DATETIME NULL");
    }
}

/** MIME detection with fallbacks when the fileinfo extension is not enabled */
function detect_mime(string $tmp, string $origName): string {
    if (class_exists('finfo')) {
        $m = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if ($m) return $m;
    }
    if (function_exists('mime_content_type')) {
        $m = @mime_content_type($tmp);
        if ($m) return $m;
    }
    $info = @getimagesize($tmp);
    if ($info && !empty($info['mime'])) return $info['mime'];
    $fh = @fopen($tmp, 'rb');
    $head = $fh ? fread($fh, 5) : '';
    if ($fh) fclose($fh);
    if ($head === '%PDF-') return 'application/pdf';
    return 'application/octet-stream';
}

function ensure_scan_dir() {
    if (!is_dir(SCAN_DIR) && !@mkdir(SCAN_DIR, 0755, true)) {
        respond(['success' => false, 'message' => 'Could not create the upload folder. Check folder permissions.'], 500);
    }
    // Block script execution & directory listing inside the upload folder (Apache)
    $ht = SCAN_DIR . '.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht,
            "<FilesMatch \"\\.(php|phtml|phar|php\\d|pl|py|cgi|sh)$\">\n    Require all denied\n</FilesMatch>\n"
        );
    }
}

function summary_exists($conn, int $fid): bool {
    $st = mysqli_prepare($conn, "SELECT id FROM field_summary WHERE id = ?");
    mysqli_stmt_bind_param($st, 'i', $fid);
    mysqli_stmt_execute($st);
    mysqli_stmt_store_result($st);
    $ok = mysqli_stmt_num_rows($st) > 0;
    mysqli_stmt_close($st);
    return $ok;
}

function get_scans($conn, int $fid): array {
    $st = mysqli_prepare($conn,
        "SELECT id, file_name, original_name, file_type, mime_type, file_size, uploaded_at
         FROM field_summary_scans
         WHERE field_summary_id = ?
         ORDER BY id ASC");
    mysqli_stmt_bind_param($st, 'i', $fid);
    mysqli_stmt_execute($st);
    mysqli_stmt_bind_result($st, $id, $file_name, $original_name, $file_type, $mime_type, $file_size, $uploaded_at);
    $rows = [];
    while (mysqli_stmt_fetch($st)) {
        $rows[] = [
            'id'            => (int)$id,
            'url'           => SCAN_URL . rawurlencode($file_name),
            'original_name' => $original_name,
            'file_type'     => $file_type,
            'mime_type'     => $mime_type,
            'file_size'     => (int)$file_size,
            'uploaded_at'   => date('M d, Y h:i A', strtotime($uploaded_at)),
        ];
    }
    mysqli_stmt_close($st);
    return $rows;
}

/** Keep field_summary.scan_count / last_scan_at in sync with the scans table */
function sync_scan_count($conn, int $fid): void {
    $st = mysqli_prepare($conn,
        "UPDATE field_summary
            SET scan_count   = (SELECT COUNT(*)         FROM field_summary_scans WHERE field_summary_id = ?),
                last_scan_at = (SELECT MAX(uploaded_at) FROM field_summary_scans WHERE field_summary_id = ?)
          WHERE id = ?");
    mysqli_stmt_bind_param($st, 'iii', $fid, $fid, $fid);
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
}

function upload_err_msg(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:  return 'File is larger than the server allows';
        case UPLOAD_ERR_PARTIAL:    return 'File was only partly uploaded';
        case UPLOAD_ERR_NO_FILE:    return 'No file received';
        case UPLOAD_ERR_NO_TMP_DIR: return 'Server temp folder missing';
        case UPLOAD_ERR_CANT_WRITE: return 'Server could not write the file';
        default:                    return 'Upload failed';
    }
}

ensure_schema($conn);

$action = $_REQUEST['action'] ?? '';

/* ---------------- LIST ---------------- */
if ($action === 'list') {
    $fid = intval($_GET['field_summary_id'] ?? 0);
    if ($fid <= 0 || !summary_exists($conn, $fid)) {
        respond(['success' => false, 'message' => 'Field summary not found'], 404);
    }
    respond(['success' => true, 'scans' => get_scans($conn, $fid)]);
}

/* ---------------- UPLOAD ---------------- */
if ($action === 'upload' || ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES))) {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['success' => false, 'message' => 'POST required'], 405);
    }
    // Whole request bigger than post_max_size → PHP drops $_POST and $_FILES
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        respond(['success' => false, 'message' => 'Upload is too large for the server (post_max_size = ' . ini_get('post_max_size') . '). Upload fewer files at a time.'], 413);
    }

    $fid = intval($_POST['field_summary_id'] ?? 0);
    if ($fid <= 0 || !summary_exists($conn, $fid)) {
        respond(['success' => false, 'message' => 'Field summary not found'], 404);
    }
    if (empty($_FILES['scans']) || !is_array($_FILES['scans']['name'])) {
        respond(['success' => false, 'message' => 'No files selected'], 400);
    }

    $f     = $_FILES['scans'];
    $count = count($f['name']);
    if ($count > SCAN_MAX_FILES) {
        respond(['success' => false, 'message' => 'You can upload up to ' . SCAN_MAX_FILES . ' files at a time'], 400);
    }

    ensure_scan_dir();
    $errors = [];
    $saved  = 0;

    $ins = mysqli_prepare($conn,
        "INSERT INTO field_summary_scans (field_summary_id, file_name, original_name, file_type, mime_type, file_size)
         VALUES (?, ?, ?, ?, ?, ?)");

    for ($i = 0; $i < $count; $i++) {
        $orig = basename((string)$f['name'][$i]);
        $orig = preg_replace('/[\x00-\x1F\x7F]/u', '', $orig);
        $orig = substr($orig !== '' ? $orig : 'scan', 0, 255);

        if ($f['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = "$orig: " . upload_err_msg((int)$f['error'][$i]);
            continue;
        }
        if ($f['size'][$i] > SCAN_MAX_SIZE) {
            $errors[] = "$orig: larger than 10 MB";
            continue;
        }
        $tmp = $f['tmp_name'][$i];
        if (!is_uploaded_file($tmp)) {
            $errors[] = "$orig: invalid upload";
            continue;
        }

        $mime = detect_mime($tmp, $orig);
        if (!isset($ALLOWED[$mime])) {
            $errors[] = "$orig: only JPG, PNG, WEBP, GIF or PDF allowed";
            continue;
        }
        $type = ($mime === 'application/pdf') ? 'pdf' : 'image';
        if ($type === 'image' && @getimagesize($tmp) === false) {
            $errors[] = "$orig: not a valid image";
            continue;
        }

        $stored = sprintf('fs%d_%s_%s.%s', $fid, date('Ymd_His'), bin2hex(random_bytes(6)), $ALLOWED[$mime]);
        if (!move_uploaded_file($tmp, SCAN_DIR . $stored)) {
            $errors[] = "$orig: could not be saved on the server";
            continue;
        }

        $size = (int)$f['size'][$i];
        mysqli_stmt_bind_param($ins, 'issssi', $fid, $stored, $orig, $type, $mime, $size);
        try {
            mysqli_stmt_execute($ins);
        } catch (Throwable $e) {
            @unlink(SCAN_DIR . $stored);
            $errors[] = "$orig: database error (" . $e->getMessage() . ")";
            continue;
        }
        $saved++;
    }
    mysqli_stmt_close($ins);

    if ($saved > 0) sync_scan_count($conn, $fid);

    respond([
        'success'  => $saved > 0,
        'uploaded' => $saved,
        'errors'   => $errors,
        'message'  => $saved > 0 ? "$saved file(s) uploaded" : 'No files were uploaded',
        'scans'    => get_scans($conn, $fid),
    ], $saved > 0 ? 200 : 422);
}

/* ---------------- DELETE ---------------- */
if ($action === 'delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['success' => false, 'message' => 'POST required'], 405);
    }
    $id = intval($_POST['id'] ?? 0);

    $st = mysqli_prepare($conn, "SELECT field_summary_id, file_name FROM field_summary_scans WHERE id = ?");
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    mysqli_stmt_bind_result($st, $fid, $file_name);
    $found = mysqli_stmt_fetch($st);
    mysqli_stmt_close($st);

    if (!$found) {
        respond(['success' => false, 'message' => 'Scan not found'], 404);
    }

    $del = mysqli_prepare($conn, "DELETE FROM field_summary_scans WHERE id = ?");
    mysqli_stmt_bind_param($del, 'i', $id);
    mysqli_stmt_execute($del);
    mysqli_stmt_close($del);

    $path = SCAN_DIR . basename($file_name);
    if (is_file($path)) @unlink($path);

    sync_scan_count($conn, (int)$fid);

    respond(['success' => true, 'message' => 'Scan deleted', 'scans' => get_scans($conn, (int)$fid)]);
}

respond(['success' => false, 'message' => 'Unknown action'], 400);
