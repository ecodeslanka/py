<?php
/* ═══════════════════════════════════════════════════════
   google_drive_callback.php — OAuth2 redirect handler
   Set this exact URL as an "Authorized redirect URI"
   in your Google Cloud Console OAuth client.
════════════════════════════════════════════════════════ */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
requireLogin();

require_once __DIR__ . '/backup_helper.php';

if (isset($_GET['error'])) {
    header('Location: backup_settings.php?err=' . urlencode('Google authorization denied: ' . $_GET['error']));
    exit;
}

if (empty($_GET['code'])) {
    header('Location: backup_settings.php?err=' . urlencode('No authorization code received from Google.'));
    exit;
}

try {
    bk_gdriveExchangeCode($conn, $_GET['code']);
    header('Location: backup_settings.php?ok=' . urlencode('Google Drive connected successfully!'));
} catch (Exception $e) {
    header('Location: backup_settings.php?err=' . urlencode($e->getMessage()));
}
exit;
?>
