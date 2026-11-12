<?php
/**
 * mail_lib.php — system e-mail (SMTP) for password reset, test mails and notices.
 * Pure PHP socket client (SSL 465 / STARTTLS 587 / none), no composer needed.
 * Settings are kept in table mail_settings and edited on email_settings.php.
 */
if (!function_exists('ml_ensure')) {

function ml_ensure() {
    global $conn;
    static $done = false; if ($done) return; $done = true;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mail_settings (`key` VARCHAR(60) PRIMARY KEY, `value` TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function ml_get_all() {
    global $conn; ml_ensure();
    $o = [];
    $r = @mysqli_query($conn, "SELECT `key`,`value` FROM mail_settings");
    while ($r && ($x = mysqli_fetch_assoc($r))) $o[$x['key']] = $x['value'];
    // first time: borrow the SMTP account already set up for DB backups
    if (!isset($o['smtp_host'])) {
        $b = @mysqli_query($conn, "SELECT `key`,`value` FROM backup_settings WHERE `key` IN ('smtp_host','smtp_port','smtp_encryption','smtp_user','smtp_pass','smtp_from_name')");
        while ($b && ($x = mysqli_fetch_assoc($b))) $o[$x['key']] = $x['value'];
    }
    return $o + ['smtp_host' => '', 'smtp_port' => '465', 'smtp_encryption' => 'ssl', 'smtp_user' => '', 'smtp_pass' => '',
                 'from_email' => '', 'from_name' => 'YMS', 'reply_to' => '', 'reset_minutes' => '60', 'enabled' => '1'];
}
function ml_set($k, $v) {
    global $conn; ml_ensure();
    $k = mysqli_real_escape_string($conn, $k); $v = mysqli_real_escape_string($conn, (string)$v);
    return mysqli_query($conn, "INSERT INTO mail_settings (`key`,`value`) VALUES ('$k','$v') ON DUPLICATE KEY UPDATE `value`='$v'");
}
function ml_configured() { $s = ml_get_all(); return $s['enabled'] === '1' && $s['smtp_host'] !== '' && $s['smtp_user'] !== '' && $s['smtp_pass'] !== ''; }

function ml_read($sock) {
    $d = '';
    while (($l = fgets($sock, 515)) !== false) { $d .= $l; if (preg_match('/^\d{3} /', $l)) break; }
    return $d;
}
function ml_cmd($sock, $cmd, $ok) {
    if ($cmd !== null) fwrite($sock, $cmd . "\r\n");
    $r = ml_read($sock);
    if (!in_array((int)substr($r, 0, 3), (array)$ok, true)) throw new Exception('SMTP: ' . trim(($cmd !== null && stripos($cmd, 'AUTH') === false && !preg_match('/^[A-Za-z0-9+\/=]{12,}$/', $cmd) ? $cmd . ' → ' : '') . $r));
    return $r;
}

/** Send one HTML mail. Returns true or false (and $err). $text is the plain-text alternative. */
function ml_send($to, $subject, $html, &$err = null, $text = '') {
    $s = ml_get_all();
    try {
        if ($s['smtp_host'] === '' || $s['smtp_user'] === '') throw new Exception('E-mail is not set up yet (SMTP host / user missing).');
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid recipient e-mail address.');
        $enc = strtolower($s['smtp_encryption']); $port = (int)$s['smtp_port'] ?: 465;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
        $sock = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : 'tcp://') . $s['smtp_host'] . ':' . $port, $en, $es, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) throw new Exception("Could not connect to {$s['smtp_host']}:$port — $es ($en)");
        stream_set_timeout($sock, 25);
        $me = preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost') ?: 'localhost';
        ml_cmd($sock, null, 220); ml_cmd($sock, "EHLO $me", 250);
        if ($enc === 'tls') {
            ml_cmd($sock, 'STARTTLS', 220);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new Exception('STARTTLS failed.');
            ml_cmd($sock, "EHLO $me", 250);
        }
        ml_cmd($sock, 'AUTH LOGIN', 334); ml_cmd($sock, base64_encode($s['smtp_user']), 334); ml_cmd($sock, base64_encode($s['smtp_pass']), 235);
        $from = filter_var($s['from_email'], FILTER_VALIDATE_EMAIL) ? $s['from_email'] : $s['smtp_user'];
        ml_cmd($sock, "MAIL FROM:<$from>", 250); ml_cmd($sock, "RCPT TO:<$to>", [250, 251]); ml_cmd($sock, 'DATA', 354);
        $b = 'ml_' . md5(uniqid('', true));
        if ($text === '') $text = trim(html_entity_decode(strip_tags(preg_replace('~<br\s*/?>|</p>|</div>|</tr>~i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $h  = 'From: =?UTF-8?B?' . base64_encode($s['from_name'] ?: 'YMS') . "?= <$from>\r\nTo: <$to>\r\n";
        if (filter_var($s['reply_to'], FILTER_VALIDATE_EMAIL)) $h .= 'Reply-To: <' . $s['reply_to'] . ">\r\n";
        $h .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\nDate: " . date('r') . "\r\nMessage-ID: <" . md5(uniqid('', true)) . "@$me>\r\nMIME-Version: 1.0\r\n"
            . "Content-Type: multipart/alternative; boundary=\"$b\"\r\n";
        $m  = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
            . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$b--\r\n";
        fwrite($sock, preg_replace('/\r\n\./', "\r\n..", $h . "\r\n" . $m) . "\r\n.\r\n");
        ml_cmd($sock, null, 250); @fwrite($sock, "QUIT\r\n"); @fclose($sock);
        return true;
    } catch (Throwable $e) { $err = $e->getMessage(); return false; }
}

/** wrap a body in a simple branded mail layout */
function ml_layout($title, $bodyHtml) {
    return '<div style="font-family:Segoe UI,Arial,sans-serif;background:#f3f4f6;padding:24px"><div style="max-width:520px;margin:auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb">'
        . '<div style="background:#111827;color:#fff;padding:16px 22px;font-size:17px;font-weight:700">' . htmlspecialchars($title) . '</div>'
        . '<div style="padding:22px;color:#374151;font-size:14px;line-height:1.6">' . $bodyHtml . '</div>'
        . '<div style="padding:12px 22px;background:#f9fafb;color:#9ca3af;font-size:12px">This is an automatic message from the system. Please do not reply.</div></div></div>';
}
function ml_base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}
}
