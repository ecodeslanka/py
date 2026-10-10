<?php
/* ═══════════════════════════════════════════════════════
   backup_helper.php — Database Backup Core Library
   - Pure-PHP mysqldump (works on shared hosting, no exec needed)
   - .tar.gz packaging via PharData
   - Google Drive OAuth2 + resumable upload (no composer needed)
   - Settings + history tables auto-created
   Requires: config.php ($conn) already included
════════════════════════════════════════════════════════ */

if (!isset($conn)) { require_once __DIR__ . '/config.php'; }

define('BACKUP_DIR', __DIR__ . '/backups');

/* ── Auto-create tables ─────────────────────────────── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS backup_settings (
    `key`        VARCHAR(100) PRIMARY KEY,
    `value`      TEXT,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS backup_history (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    filename       VARCHAR(255),
    filesize       BIGINT DEFAULT 0,
    backup_type    ENUM('download','gdrive') DEFAULT 'download',
    gdrive_file_id VARCHAR(255) DEFAULT NULL,
    status         ENUM('success','failed') DEFAULT 'success',
    error_msg      TEXT,
    created_by     VARCHAR(100),
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

/* ── Settings helpers ───────────────────────────────── */
function bk_getSetting($conn, $key){
    $k = mysqli_real_escape_string($conn, $key);
    $r = mysqli_query($conn, "SELECT `value` FROM backup_settings WHERE `key`='$k'");
    return ($r && $row = mysqli_fetch_assoc($r)) ? $row['value'] : '';
}
function bk_setSetting($conn, $key, $value){
    $k = mysqli_real_escape_string($conn, $key);
    $v = mysqli_real_escape_string($conn, $value);
    mysqli_query($conn, "INSERT INTO backup_settings(`key`,`value`) VALUES('$k','$v')
        ON DUPLICATE KEY UPDATE `value`='$v', updated_at=NOW()");
}

/* ── Backup dir (protected) ─────────────────────────── */
function bk_ensureDir(){
    if (!is_dir(BACKUP_DIR)) { @mkdir(BACKUP_DIR, 0755, true); }
    $ht = BACKUP_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
    }
    $ix = BACKUP_DIR . '/index.php';
    if (!file_exists($ix)) { @file_put_contents($ix, "<?php http_response_code(403);"); }
}

/* ═══════════════════════════════════════════════════════
   DATABASE DUMP  (pure PHP — streams to file, low memory)
════════════════════════════════════════════════════════ */
function bk_dumpDatabase($conn, $sqlFile){
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    $fh = fopen($sqlFile, 'w');
    if (!$fh) { throw new Exception('Cannot create dump file: ' . $sqlFile); }

    fwrite($fh, "-- ══════════════════════════════════════════\n");
    fwrite($fh, "-- Database Backup: " . DB_NAME . "\n");
    fwrite($fh, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
    fwrite($fh, "-- ══════════════════════════════════════════\n\n");
    fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSTART TRANSACTION;\n\n");

    /* Separate base tables and views */
    $tables = []; $views = [];
    $res = mysqli_query($conn, "SHOW FULL TABLES");
    while ($row = mysqli_fetch_row($res)) {
        if (strtoupper($row[1]) === 'VIEW') $views[] = $row[0];
        else $tables[] = $row[0];
    }

    /* ── Base tables: structure + data ── */
    foreach ($tables as $table) {
        $tEsc = str_replace('`', '``', $table);

        fwrite($fh, "-- ----------------------------\n-- Table: `$tEsc`\n-- ----------------------------\n");
        fwrite($fh, "DROP TABLE IF EXISTS `$tEsc`;\n");

        $cr = mysqli_query($conn, "SHOW CREATE TABLE `$tEsc`");
        if ($cr && ($crow = mysqli_fetch_assoc($cr))) {
            $createSql = $crow['Create Table'] ?? array_values($crow)[1];
            fwrite($fh, $createSql . ";\n\n");
        }

        /* Column types — detect binary columns for HEX-safe export */
        $binCols = [];
        $colRes = mysqli_query($conn, "SHOW COLUMNS FROM `$tEsc`");
        $cols = [];
        while ($c = mysqli_fetch_assoc($colRes)) {
            $cols[] = $c['Field'];
            if (preg_match('/blob|binary|varbinary/i', $c['Type'])) $binCols[$c['Field']] = true;
        }
        $colList = '`' . implode('`,`', array_map(fn($c)=>str_replace('`','``',$c), $cols)) . '`';

        /* Stream rows (unbuffered) in INSERT batches */
        $data = mysqli_query($conn, "SELECT * FROM `$tEsc`", MYSQLI_USE_RESULT);
        if ($data) {
            $batch = []; $batchBytes = 0;
            while ($row = mysqli_fetch_assoc($data)) {
                $vals = [];
                foreach ($row as $col => $v) {
                    if ($v === null)               $vals[] = 'NULL';
                    elseif (isset($binCols[$col]) && $v !== '') $vals[] = '0x' . bin2hex($v);
                    else                           $vals[] = "'" . mysqli_real_escape_string($conn, $v) . "'";
                }
                $line = '(' . implode(',', $vals) . ')';
                $batch[] = $line;
                $batchBytes += strlen($line);

                if (count($batch) >= 400 || $batchBytes > 900000) {
                    fwrite($fh, "INSERT INTO `$tEsc` ($colList) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = []; $batchBytes = 0;
                }
            }
            if ($batch) {
                fwrite($fh, "INSERT INTO `$tEsc` ($colList) VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            mysqli_free_result($data);
        }
        fwrite($fh, "\n");
    }

    /* ── Views ── */
    foreach ($views as $view) {
        $vEsc = str_replace('`', '``', $view);
        fwrite($fh, "-- ----------------------------\n-- View: `$vEsc`\n-- ----------------------------\n");
        fwrite($fh, "DROP VIEW IF EXISTS `$vEsc`;\n");
        $cr = mysqli_query($conn, "SHOW CREATE VIEW `$vEsc`");
        if ($cr && ($crow = mysqli_fetch_assoc($cr))) {
            fwrite($fh, ($crow['Create View'] ?? array_values($crow)[1]) . ";\n\n");
        }
    }

    /* ── Triggers ── */
    $tr = mysqli_query($conn, "SHOW TRIGGERS");
    if ($tr && mysqli_num_rows($tr) > 0) {
        fwrite($fh, "-- ----------------------------\n-- Triggers\n-- ----------------------------\nDELIMITER ;;\n");
        while ($t = mysqli_fetch_assoc($tr)) {
            $name = str_replace('`','``',$t['Trigger']);
            fwrite($fh, "DROP TRIGGER IF EXISTS `$name`;;\n");
            fwrite($fh, "CREATE TRIGGER `$name` {$t['Timing']} {$t['Event']} ON `" .
                str_replace('`','``',$t['Table']) . "` FOR EACH ROW {$t['Statement']};;\n");
        }
        fwrite($fh, "DELIMITER ;\n\n");
    }

    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\nCOMMIT;\n");
    fclose($fh);
    return true;
}

/* ═══════════════════════════════════════════════════════
   CREATE FULL BACKUP  →  returns path to .tar.gz
════════════════════════════════════════════════════════ */
function bk_createBackup($conn){
    bk_ensureDir();
    @set_time_limit(0);

    $stamp    = date('Y-m-d_His');
    $base     = 'db_backup_' . DB_NAME . '_' . $stamp;
    $sqlFile  = BACKUP_DIR . '/' . $base . '.sql';
    $tarFile  = BACKUP_DIR . '/' . $base . '.tar';
    $tgzFile  = $tarFile . '.gz';

    bk_dumpDatabase($conn, $sqlFile);

    /* Package as tar.gz */
    try {
        if (!class_exists('PharData')) { throw new Exception('PharData unavailable'); }
        if (file_exists($tarFile)) @unlink($tarFile);
        if (file_exists($tgzFile)) @unlink($tgzFile);
        $phar = new PharData($tarFile);
        $phar->addFile($sqlFile, $base . '.sql');
        $phar->compress(Phar::GZ);
        unset($phar);
        @unlink($tarFile);
        @unlink($sqlFile);
    } catch (Exception $e) {
        /* Fallback: gzip the sql directly, still name it .tar.gz-compatible .sql.gz */
        $tgzFile = BACKUP_DIR . '/' . $base . '.sql.gz';
        $in  = fopen($sqlFile, 'rb');
        $out = gzopen($tgzFile, 'wb9');
        while (!feof($in)) { gzwrite($out, fread($in, 524288)); }
        fclose($in); gzclose($out);
        @unlink($sqlFile);
        @unlink($tarFile);
    }

    if (!file_exists($tgzFile)) { throw new Exception('Backup archive was not created.'); }
    return $tgzFile;
}

/* ── History logging + retention ────────────────────── */
function bk_logHistory($conn, $filename, $filesize, $type, $gdriveId, $status, $error, $user){
    $stmt = mysqli_prepare($conn, "INSERT INTO backup_history
        (filename, filesize, backup_type, gdrive_file_id, status, error_msg, created_by)
        VALUES (?,?,?,?,?,?,?)");
    mysqli_stmt_bind_param($stmt, 'sisssss', $filename, $filesize, $type, $gdriveId, $status, $error, $user);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function bk_cleanupOld($keep = 5){
    bk_ensureDir();
    $files = glob(BACKUP_DIR . '/db_backup_*');
    if (!$files) return;
    usort($files, fn($a,$b) => filemtime($b) - filemtime($a));
    foreach (array_slice($files, $keep) as $old) { @unlink($old); }
}

function bk_formatBytes($b){
    if ($b >= 1073741824) return number_format($b/1073741824, 2) . ' GB';
    if ($b >= 1048576)    return number_format($b/1048576, 2) . ' MB';
    if ($b >= 1024)       return number_format($b/1024, 1) . ' KB';
    return $b . ' B';
}

/* ═══════════════════════════════════════════════════════
   GOOGLE DRIVE  (OAuth2 + resumable upload, no SDK)
════════════════════════════════════════════════════════ */
function bk_gdriveRedirectUri(){
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    return $scheme . '://' . $host . $dir . '/google_drive_callback.php';
}

function bk_gdriveAuthUrl($conn){
    $clientId = bk_getSetting($conn, 'gdrive_client_id');
    if (!$clientId) return '';
    $params = http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => bk_gdriveRedirectUri(),
        'response_type' => 'code',
        'scope'         => 'https://www.googleapis.com/auth/drive.file',
        'access_type'   => 'offline',
        'prompt'        => 'consent',
    ]);
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
}

function bk_httpPost($url, $fields, $headers = [], $rawBody = null){
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $rawBody !== null ? $rawBody : http_build_query($fields),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HEADER         => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsz  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [
        'code'    => $code,
        'headers' => $resp !== false ? substr($resp, 0, $hsz) : '',
        'body'    => $resp !== false ? substr($resp, $hsz) : '',
        'error'   => $err,
    ];
}

/* Exchange OAuth code → tokens (used by callback page) */
function bk_gdriveExchangeCode($conn, $code){
    $r = bk_httpPost('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => bk_getSetting($conn, 'gdrive_client_id'),
        'client_secret' => bk_getSetting($conn, 'gdrive_client_secret'),
        'redirect_uri'  => bk_gdriveRedirectUri(),
        'grant_type'    => 'authorization_code',
    ]);
    $j = json_decode($r['body'], true);
    if (empty($j['access_token'])) {
        throw new Exception('Token exchange failed: ' . ($j['error_description'] ?? $j['error'] ?? $r['error'] ?? 'unknown'));
    }
    bk_setSetting($conn, 'gdrive_access_token', $j['access_token']);
    bk_setSetting($conn, 'gdrive_token_expiry', time() + intval($j['expires_in'] ?? 3600) - 60);
    if (!empty($j['refresh_token'])) {
        bk_setSetting($conn, 'gdrive_refresh_token', $j['refresh_token']);
    }
    return true;
}

/* Get a valid access token (auto-refresh) */
function bk_gdriveAccessToken($conn){
    $tok = bk_getSetting($conn, 'gdrive_access_token');
    $exp = intval(bk_getSetting($conn, 'gdrive_token_expiry'));
    if ($tok && $exp > time()) return $tok;

    $refresh = bk_getSetting($conn, 'gdrive_refresh_token');
    if (!$refresh) throw new Exception('Google Drive is not connected. Open Backup Settings and click "Connect Google Drive".');

    $r = bk_httpPost('https://oauth2.googleapis.com/token', [
        'client_id'     => bk_getSetting($conn, 'gdrive_client_id'),
        'client_secret' => bk_getSetting($conn, 'gdrive_client_secret'),
        'refresh_token' => $refresh,
        'grant_type'    => 'refresh_token',
    ]);
    $j = json_decode($r['body'], true);
    if (empty($j['access_token'])) {
        throw new Exception('Token refresh failed: ' . ($j['error_description'] ?? $j['error'] ?? 'unknown') . '. Try reconnecting Google Drive.');
    }
    bk_setSetting($conn, 'gdrive_access_token', $j['access_token']);
    bk_setSetting($conn, 'gdrive_token_expiry', time() + intval($j['expires_in'] ?? 3600) - 60);
    return $j['access_token'];
}

/* Resumable upload — memory-safe for large backups. Returns Drive file ID */
function bk_gdriveUpload($conn, $filePath){
    $token    = bk_gdriveAccessToken($conn);
    $folderId = trim(bk_getSetting($conn, 'gdrive_folder_id'));
    $size     = filesize($filePath);
    $name     = basename($filePath);

    $meta = ['name' => $name];
    if ($folderId !== '') $meta['parents'] = [$folderId];

    /* 1. Initiate resumable session */
    $init = bk_httpPost(
        'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable',
        [],
        [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Type: application/gzip',
            'X-Upload-Content-Length: ' . $size,
        ],
        json_encode($meta)
    );
    if ($init['code'] !== 200 || !preg_match('/^location:\s*(.+)$/im', $init['headers'], $m)) {
        $j = json_decode($init['body'], true);
        throw new Exception('Drive upload init failed (HTTP ' . $init['code'] . '): ' .
            ($j['error']['message'] ?? $init['error'] ?? 'unknown'));
    }
    $sessionUrl = trim($m[1]);

    /* 2. PUT the file content (streamed from disk) */
    $fh = fopen($filePath, 'rb');
    $ch = curl_init($sessionUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_UPLOAD         => true,
        CURLOPT_INFILE         => $fh,
        CURLOPT_INFILESIZE     => $size,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/gzip'],
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    $j = json_decode($body, true);
    if (($code !== 200 && $code !== 201) || empty($j['id'])) {
        throw new Exception('Drive upload failed (HTTP ' . $code . '): ' .
            ($j['error']['message'] ?? $err ?? 'unknown'));
    }
    return $j['id'];
}

/* Simple connection test — fetches Drive account info */
function bk_gdriveTest($conn){
    $token = bk_gdriveAccessToken($conn);
    $ch = curl_init('https://www.googleapis.com/drive/v3/about?fields=user(emailAddress),storageQuota(limit,usage)');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($body, true);
    if ($code !== 200) {
        throw new Exception('Drive test failed: ' . ($j['error']['message'] ?? 'HTTP ' . $code));
    }
    return $j;
}
?>
