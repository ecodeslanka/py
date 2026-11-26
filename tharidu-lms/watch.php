<?php
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/layout.php';
$u = require_login(); if ($u['role'] === 'admin') redirect('admin/');
$m = row("SELECT * FROM materials WHERE id=? AND type='youtube'", [(int)($_GET['id'] ?? 0)]);
if (!$m || !is_enrolled($u['id'], $m['lesson_id'])) { flash('Not available.', 'err'); redirect('dashboard'); }
$used = (int)val('SELECT views FROM material_views WHERE user_id=? AND material_id=?', [$u['id'], $m['id']]);
if ($m['view_limit'] > 0 && $used >= $m['view_limit']) { flash('You have used all allowed views of this recording.', 'err'); redirect('lesson?id=' . $m['lesson_id']); }
q('INSERT INTO material_views(user_id,material_id,views,last_view) VALUES(?,?,1,NOW()) ON DUPLICATE KEY UPDATE views=views+1,last_view=NOW()', [$u['id'], $m['id']]);
$embed = youtube_embed(dec($m['url_enc']));
student_head($m['title'], 'lesson?id=' . $m['lesson_id']);
?>
<?php if ($m['view_limit'] > 0): ?><p class="muted small">View <?= $used + 1 ?> of <?= (int)$m['view_limit'] ?>. Refreshing counts as a new view.</p><?php endif; ?>
<?php if ($embed): ?><div class="video"><iframe src="<?= e($embed) ?>" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>
<?php else: ?><div class="alert err">This video link is invalid. Please tell the teacher.</div><?php endif; ?>
<?php if ($m['description']): ?><div class="card" style="margin-top:18px"><div class="prose"><?= e($m['description']) ?></div></div><?php endif; ?>
<?php student_foot();
