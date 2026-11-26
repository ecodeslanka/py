<?php
require __DIR__ . '/../inc/bootstrap.php';
require_admin();
$s = row("SELECT * FROM sessions_live WHERE id=? AND kind='zoom_api'", [(int)($_GET['id'] ?? 0)]);
$link = $s ? dec($s['start_url_enc']) : '';
if (!preg_match('~^https://~i', $link)) exit('Link unavailable');
redirect($link);
