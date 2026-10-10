<?php
/* ═══════════════════════════════════════════════════════
   backup_download.php — Generate DB backup and download
   as .tar.gz (no HTML output — direct file stream)
════════════════════════════════════════════════════════ */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
requireLogin();

require_once __DIR__ . '/backup_helper.php';

$user = $_SESSION['username'] ?? 'system';

try {
    $file = bk_createBackup($conn);
    $size = filesize($file);
    $name = basename($file);

    bk_logHistory($conn, $name, $size, 'download', null, 'success', null, $user);
    bk_cleanupOld(5);   // keep only the 5 newest local backup files

    /* Stream the file */
    if (ob_get_level()) { ob_end_clean(); }
    header('Content-Description: File Transfer');
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . $size);
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: public');

    $fh = fopen($file, 'rb');
    while (!feof($fh)) {
        echo fread($fh, 524288);
        flush();
    }
    fclose($fh);
    exit;

} catch (Exception $e) {
    bk_logHistory($conn, 'FAILED', 0, 'download', null, 'failed', $e->getMessage(), $user);
    header('Location: backup_settings.php?err=' . urlencode($e->getMessage()));
    exit;
}
?>
