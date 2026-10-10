<?php
/* ═══════════════════════════════════════════════════════
   backup_mailer.php — Email the sql.gz Backup
   - Pure-PHP SMTP client over a raw socket (SSL/TLS, AUTH LOGIN)
   - No PHPMailer / composer required (shared-hosting friendly)
   - Attaches the backup if small enough, otherwise just notifies
   Requires: backup_helper.php already included ($conn, bk_* helpers)
════════════════════════════════════════════════════════ */

if (!isset($conn)) { require_once __DIR__ . '/config.php'; }
require_once __DIR__ . '/backup_helper.php';

/* Max attachment size we'll actually attach (bytes). Above this we
   still send an email, but only as a notification (most SMTP
   providers, Hostinger included, reject/bounce very large messages). */
define('BK_MAIL_MAX_ATTACH', 18 * 1024 * 1024); // 18 MB

/* ── Read current mail settings (falls back to seeded defaults) ── */
function bk_mailSettings($conn){
    return [
        'host'       => bk_getSetting($conn, 'smtp_host')       ?: 'smtp.hostinger.com',
        'port'       => intval(bk_getSetting($conn, 'smtp_port') ?: 465),
        'encryption' => bk_getSetting($conn, 'smtp_encryption') ?: 'ssl', // 'ssl' (465) or 'tls' (587)
        'user'       => bk_getSetting($conn, 'smtp_user')       ?: 'noreply@yelogroup.net',
        'pass'       => bk_getSetting($conn, 'smtp_pass')       ?: '',
        'from_name'  => bk_getSetting($conn, 'smtp_from_name')  ?: 'Yelo Group Backups',
        'recipient'  => bk_getSetting($conn, 'email_recipient') ?: '',
    ];
}

/* ── Read one SMTP response block (handles multi-line "250-" replies) ── */
function bk_smtpRead($sock){
    $data = '';
    while (($line = fgets($sock, 515)) !== false) {
        $data .= $line;
        // last line of a response has a space (not '-') after the 3-digit code
        if (preg_match('/^\d{3} /', $line)) break;
    }
    return $data;
}
function bk_smtpExpect($sock, $cmd, $expectCodes){
    if ($cmd !== null) { fwrite($sock, $cmd . "\r\n"); }
    $resp = bk_smtpRead($sock);
    $code = intval(substr($resp, 0, 3));
    if (!in_array($code, (array)$expectCodes, true)) {
        throw new Exception('SMTP error on "' . trim((string)$cmd) . '": ' . trim($resp));
    }
    return $resp;
}

/* ── Low-level SMTP send with optional single attachment ── */
function bk_smtpSend($settings, $to, $subject, $bodyText, $attachmentPath = null){
    $host = $settings['host'];
    $port = $settings['port'];
    $enc  = strtolower($settings['encryption']);

    $context = stream_context_create(['ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
    ]]);

    $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $sock = @stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    if (!$sock) { throw new Exception("Could not connect to $host:$port — $errstr ($errno)"); }
    stream_set_timeout($sock, 30);

    $ehloHost = $_SERVER['HTTP_HOST'] ?? 'localhost';

    bk_smtpExpect($sock, null, 220);
    bk_smtpExpect($sock, "EHLO $ehloHost", 250);

    if ($enc === 'tls') {
        bk_smtpExpect($sock, "STARTTLS", 220);
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new Exception('STARTTLS negotiation failed.');
        }
        bk_smtpExpect($sock, "EHLO $ehloHost", 250);
    }

    bk_smtpExpect($sock, "AUTH LOGIN", 334);
    bk_smtpExpect($sock, base64_encode($settings['user']), 334);
    bk_smtpExpect($sock, base64_encode($settings['pass']), 235);

    bk_smtpExpect($sock, "MAIL FROM:<{$settings['user']}>", 250);
    foreach (array_map('trim', explode(',', $to)) as $rcpt) {
        if ($rcpt === '') continue;
        bk_smtpExpect($sock, "RCPT TO:<{$rcpt}>", [250, 251]);
    }
    bk_smtpExpect($sock, "DATA", 354);

    $boundary = 'bk_' . md5(uniqid('', true));
    $fromHeader = '=?UTF-8?B?' . base64_encode($settings['from_name']) . '?= <' . $settings['user'] . '>';

    $headers  = "From: $fromHeader\r\n";
    $headers .= "To: $to\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

    $msg  = "--$boundary\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $msg .= $bodyText . "\r\n\r\n";

    if ($attachmentPath && file_exists($attachmentPath)) {
        $fname = basename($attachmentPath);
        $b64   = chunk_split(base64_encode(file_get_contents($attachmentPath)));
        $msg  .= "--$boundary\r\n";
        $msg  .= "Content-Type: application/gzip; name=\"$fname\"\r\n";
        $msg  .= "Content-Transfer-Encoding: base64\r\n";
        $msg  .= "Content-Disposition: attachment; filename=\"$fname\"\r\n\r\n";
        $msg  .= $b64 . "\r\n";
    }
    $msg .= "--$boundary--\r\n";

    $full = $headers . "\r\n" . $msg;
    /* Dot-stuff lines starting with '.' per RFC 5321 DATA rules */
    $full = preg_replace('/\r\n\./', "\r\n..", $full);

    fwrite($sock, $full . "\r\n.\r\n");
    bk_smtpExpect($sock, null, 250);

    fwrite($sock, "QUIT\r\n");
    fclose($sock);
    return true;
}

/* ── High-level: create a .sql.gz backup and email it, logs history ── */
function bk_runEmailBackup($conn, $user = 'system'){
    try {
        $file = bk_createSqlGzBackup($conn);
        $size = filesize($file);
        $name = basename($file);

        $settings = bk_mailSettings($conn);
        $to = trim($settings['recipient']);
        if ($to === '') {
            throw new Exception('No recipient email set. Add one under Email Backup Settings first.');
        }
        if (!$settings['pass']) {
            throw new Exception('SMTP password is not configured.');
        }

        $sizeTxt = bk_formatBytes($size);
        if ($size <= BK_MAIL_MAX_ATTACH) {
            $subject = 'DB Backup — ' . DB_NAME . ' — ' . date('Y-m-d H:i');
            $body    = "A new database backup was generated for " . DB_NAME . ".\n\n"
                     . "File: $name\nSize: $sizeTxt\nGenerated: " . date('Y-m-d H:i:s') . "\n\n"
                     . "The compressed .sql.gz dump is attached to this email.";
            bk_smtpSend($settings, $to, $subject, $body, $file);
        } else {
            $subject = 'DB Backup — ' . DB_NAME . ' — TOO LARGE TO ATTACH';
            $body    = "A new database backup was generated for " . DB_NAME . ".\n\n"
                     . "File: $name\nSize: $sizeTxt\nGenerated: " . date('Y-m-d H:i:s') . "\n\n"
                     . "This file exceeds the " . bk_formatBytes(BK_MAIL_MAX_ATTACH) . " email attachment limit, "
                     . "so it was NOT attached. It is saved on the server in the protected /backups folder — "
                     . "download it from Backup Settings instead.";
            bk_smtpSend($settings, $to, $subject, $body, null);
        }

        bk_logHistory($conn, $name, $size, 'email', null, 'success', null, $user);
        bk_cleanupOld(5);
        return ['file' => $name, 'size' => $size, 'to' => $to];

    } catch (Exception $e) {
        bk_logHistory($conn, 'FAILED', 0, 'email', null, 'failed', $e->getMessage(), $user);
        throw $e;
    }
}
?>
