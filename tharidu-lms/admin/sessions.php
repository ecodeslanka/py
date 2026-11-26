<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php';
require_admin();
$l = lesson_ctx(); $lid = (int)$l['id']; $self = 'admin/sessions?lesson=' . $lid;
if (is_post() && post('act') === 'live_del') {
    q('DELETE FROM sessions_live WHERE id=? AND lesson_id=?', [(int)post('id'), $lid]); flash('Session deleted.'); redirect($self);
}
admin_head('Zoom & live', 'lessons');
lesson_banner($l, 'Zoom & live');
$live = rows('SELECT * FROM sessions_live WHERE lesson_id=? ORDER BY COALESCE(start_at,created_at) DESC', [$lid]);
?>
<div class="toolbar"><span class="muted"><?= count($live) ?> sessions</span><a class="btn" href="<?= url('admin/session-add?lesson=' . $lid) ?>"><i data-lucide="plus"></i>Add session</a></div>
<div class="tile-list">
<?php if (!$live) echo '<div class="card empty">No live sessions yet.</div>'; foreach ($live as $s): ?>
<div class="litem"><span class="ic <?= $s['kind'] === 'youtube_live' ? 'yt' : '' ?>"><i data-lucide="<?= $s['kind'] === 'youtube_live' ? 'youtube' : 'video' ?>"></i></span>
<div class="grow"><b><?= e($s['title']) ?></b><span class="small muted"><?= ['zoom_api' => 'Zoom (auto-created)', 'zoom_link' => 'Zoom link', 'youtube_live' => 'YouTube live'][$s['kind']] ?> · <?= $s['start_at'] ? e(date('d M Y h:i A', strtotime($s['start_at']))) : 'no time' ?> · <?= (int)$s['duration_min'] ?> min<?= $s['passcode'] ? ' · passcode ' . e($s['passcode']) : '' ?></span></div>
<div class="acts"><?php if ($s['kind'] === 'zoom_api' && $s['start_url_enc']): ?><a class="btn btn-b btn-sm" href="<?= url('admin/zoomstart?id=' . $s['id']) ?>" target="_blank">Start as host</a><?php endif; ?>
<a class="btn btn-o btn-sm" href="<?= url('live?id=' . $s['id']) ?>" target="_blank">Join link</a>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="live_del"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Delete this session?">Delete</button></form></div></div>
<?php endforeach; ?></div>
<?php admin_foot();
