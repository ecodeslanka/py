<?php
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/layout.php';
$u = require_login();
$s = row('SELECT * FROM sessions_live WHERE id=?', [(int)($_GET['id'] ?? 0)]);
if (!$s || ($u['role'] !== 'admin' && !is_enrolled($u['id'], $s['lesson_id']))) { http_response_code(403); exit('Not allowed'); }
$link = dec($s['join_url_enc']);
if ($s['kind'] !== 'youtube_live') { if (!preg_match('~^https://~i', $link)) exit('Invalid link'); redirect($link); }
$embed = youtube_embed($link);
if (!$embed) { if (preg_match('~^https://~i', $link)) redirect($link); exit('Invalid link'); }
head_html($s['title']); site_nav(); ?>
<main class="band appmain"><div class="wrap"><div class="head"><h2><?= e($s['title']) ?></h2></div>
<div class="video"><iframe src="<?= e($embed) ?>" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe></div></div></main><?php foot_html();
