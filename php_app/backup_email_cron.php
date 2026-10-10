<?php
/* ═══════════════════════════════════════════════════════
   backup_email_cron.php — Automated .sql.gz backup + email
   Run this from a Hostinger Cron Job, either:
     A) CLI:  php /path/to/backup_email_cron.php
     B) URL:  https://yourdomain.com/path/backup_email_cron.php?token=YOUR_SECRET
   (URL mode requires cron_secret to be set — see below — so
    randoms on the internet can't trigger backups on demand.)
════════════════════════════════════════════════════════ */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backup_helper.php';
require_once __DIR__ . '/backup_mailer.php';

header('Content-Type: text/plain');

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    /* Web/cron-URL access must present the secret token set in backup_settings.
       Set it once (e.g. from a DB tool or temporarily from backup_settings.php)
       with: bk_setSetting($conn, 'cron_secret', 'something-long-and-random'); */
    $secret = bk_getSetting($conn, 'cron_secret');
    if ($secret === '' || !hash_equals($secret, $_GET['token'] ?? '')) {
        http_response_code(403);
        echo "Forbidden.\n";
        exit;
    }
}

try {
    $result = bk_runEmailBackup($conn, 'cron');
    echo "OK — backup created and emailed: {$result['file']} (" . bk_formatBytes($result['size']) . ") to {$result['to']}\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "FAILED — " . $e->getMessage() . "\n";
}
?>
