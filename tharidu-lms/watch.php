<?php
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/layout.php'; require __DIR__ . '/inc/player.php';
$u = require_login();
$m = row("SELECT * FROM materials WHERE id=? AND type='youtube'", [(int)($_GET['id'] ?? 0)]);
$admin = $u['role'] === 'admin';
if (!$m || (!$admin && !is_enrolled($u['id'], $m['lesson_id']))) { flash('Not available.', 'err'); redirect($admin ? 'admin/lessons' : 'dashboard'); }
$used = (int)val('SELECT views FROM material_views WHERE user_id=? AND material_id=?', [$u['id'], $m['id']]);
if (!$admin) {
    if ($m['view_limit'] > 0 && $used >= $m['view_limit']) { flash('You have used all allowed views of this recording.', 'err'); redirect('lesson?id=' . $m['lesson_id']); }
    q('INSERT INTO material_views(user_id,material_id,views,last_view) VALUES(?,?,1,NOW()) ON DUPLICATE KEY UPDATE views=views+1,last_view=NOW()', [$u['id'], $m['id']]);
}
$vid = youtube_id(dec($m['url_enc']));
head_html($m['title'], 'app'); student_bar($u, $m['title'], $admin ? 'admin/materials?lesson=' . $m['lesson_id'] : 'lesson?id=' . $m['lesson_id']); ?>
<main class="smain">
<?php if ($vid): render_player($vid, $u, false, $m['title']); else: ?><div class="alert err">This video link is invalid. Please tell the teacher.</div><?php endif; ?>
<div class="card" id="a2hs" hidden style="margin-top:14px;font-size:14px">📲 <b>iPhone full screen:</b> tap <b>Share ⬆︎</b> → <b>Add to Home Screen</b>, then open the app from your home screen – no browser bars, true full screen.</div>
<script>if(/iP(hone|ad|od)/.test(navigator.userAgent)&&!navigator.standalone&&!window.matchMedia('(display-mode: standalone)').matches){document.getElementById('a2hs').hidden=false}</script>
<h1 class="stitle" style="font-size:clamp(20px,4vw,28px);margin-top:18px"><?= e($m['title']) ?></h1>
<?php if (!$admin && $m['view_limit'] > 0): ?><p class="muted small">View <?= $used + 1 ?> of <?= (int)$m['view_limit'] ?>. Reloading this page counts as a new view.</p><?php endif; ?>
<?php if ($m['description']): ?><div class="card" style="margin-top:12px"><div class="prose"><?= e($m['description']) ?></div></div><?php endif; ?>
<p class="muted small" style="margin-top:16px">🔒 Protected by <b>ECODES PLAYER V 5.0</b> – your name and number are shown on the video.</p>
</main><?php foot_html();
