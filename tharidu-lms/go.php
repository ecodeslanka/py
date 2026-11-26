<?php
// Opens an encrypted link (Google Drive / assessment / Zoom recording) for enrolled students & admins
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$m = row("SELECT * FROM materials WHERE id=? AND type IN ('gdrive','assessment','zoom_rec')", [(int)($_GET['id'] ?? 0)]);
if (!$m || ($u['role'] !== 'admin' && !is_enrolled($u['id'], $m['lesson_id']))) { http_response_code(403); exit('Not allowed'); }
$link = dec($m['url_enc']);
if (!preg_match('~^https://~i', $link)) exit('Invalid link');
redirect($link);
