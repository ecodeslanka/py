<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php';
require_admin();
$l = lesson_ctx(); $lid = (int)$l['id']; $self = 'admin/materials?lesson=' . $lid;
if (is_post()) {
    $act = post('act');
    if ($act === 'mat_del') { $m = row('SELECT * FROM materials WHERE id=? AND lesson_id=?', [(int)post('id'), $lid]); if ($m) { if ($m['type'] === 'document') del_upload('docs', $m['file_path']); q('DELETE FROM materials WHERE id=?', [$m['id']]); flash('Material deleted.'); } }
    if ($act === 'mat_resetviews') { q('DELETE FROM material_views WHERE material_id=?', [(int)post('id')]); flash('Views reset for all students.'); }
    redirect($self);
}
admin_head('Materials', 'lessons');
lesson_banner($l, 'Materials');
$mats = rows('SELECT m.*,(SELECT COALESCE(SUM(views),0) FROM material_views v WHERE v.material_id=m.id) tv FROM materials m WHERE lesson_id=? ORDER BY id DESC', [$lid]);
?>
<div class="toolbar"><span class="muted"><?= count($mats) ?> items</span><a class="btn" href="<?= url('admin/material-add?lesson=' . $lid) ?>"><i data-lucide="plus"></i>Add material</a></div>
<div class="tile-list">
<?php if (!$mats) echo '<div class="card empty">No materials yet.</div>'; foreach ($mats as $m): ?>
<div class="litem"><span class="ic <?= $m['type'] === 'youtube' ? 'yt' : '' ?>"><i data-lucide="<?= $m['type'] === 'youtube' ? 'play' : 'file-text' ?>"></i></span>
<div class="grow"><b><?= e($m['title']) ?></b><span class="small muted"><?= $m['type'] === 'youtube' ? 'YouTube · encrypted · ' . ($m['view_limit'] ? (int)$m['view_limit'] . ' views/student' : 'unlimited views') . ' · ' . (int)$m['tv'] . ' total views' : e($m['file_name']) ?></span>
<?php if ($m['description']): ?><span class="small muted"><?= e($m['description']) ?></span><?php endif; ?></div>
<div class="acts"><?php if ($m['type'] === 'document'): ?><a class="btn btn-o btn-sm" href="<?= url('download?id=' . $m['id']) ?>">Open</a><?php endif; ?>
<?php if ($m['type'] === 'youtube' && $m['view_limit']): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="mat_resetviews"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="btn btn-o btn-sm" data-confirm="Reset view counts for all students?">Reset views</button></form><?php endif; ?>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="mat_del"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Delete this material?">Delete</button></form></div></div>
<?php endforeach; ?></div>
<?php admin_foot();
