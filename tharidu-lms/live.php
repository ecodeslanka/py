<?php
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/layout.php'; require __DIR__ . '/inc/player.php';
$u = require_login();
$s = row('SELECT * FROM sessions_live WHERE id=?', [(int)($_GET['id'] ?? 0)]);
if (!$s || ($u['role'] !== 'admin' && !is_enrolled($u['id'], $s['lesson_id']))) { http_response_code(403); exit('Not allowed'); }
$link = dec($s['join_url_enc']);
if ($s['kind'] !== 'youtube_live') { if (!preg_match('~^https://~i', $link)) exit('Invalid link'); redirect($link); }
$vid = youtube_id($link);
if (!$vid) { if (preg_match('~^https://~i', $link)) redirect($link); exit('Invalid link'); }
head_html($s['title'], 'app'); student_bar($u, $s['title'], $u['role'] === 'admin' ? 'admin/sessions?lesson=' . $s['lesson_id'] : 'lesson?id=' . $s['lesson_id']); ?>
<main class="smain"><?php render_player($vid, $u['name'] . ' · ' . show_mobile($u['mobile']), true, $s['title']); ?>
<h1 class="stitle" style="font-size:clamp(20px,4vw,28px);margin-top:18px"><?= e($s['title']) ?></h1>
<p class="muted small">🔒 Protected by <b>ECODES PLAYER V 5.0</b></p></main><?php foot_html();
