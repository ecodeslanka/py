<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php';
require_admin();
$l = lesson_ctx(); $lid = (int)$l['id'];
admin_head($l['name'], 'lessons');
$n = ['m' => (int)val('SELECT COUNT(*) FROM materials WHERE lesson_id=?', [$lid]), 's' => (int)val('SELECT COUNT(*) FROM enrollments WHERE lesson_id=?', [$lid]), 'l' => (int)val('SELECT COUNT(*) FROM sessions_live WHERE lesson_id=?', [$lid]),
  'p' => (int)val("SELECT COUNT(*) FROM payments WHERE lesson_id=? AND status='pending'", [$lid])];
?>
<a class="back" href="<?= url('admin/lessons') ?>"><i data-lucide="chevron-left"></i>All lessons</a>
<div class="ahero"><div class="hi"><?= e($l['bname']) ?> · <?= e(month_label($l['month_year'])) ?></div><h1><?= e($l['name']) ?></h1>
  <div class="chips"><span><?= $l['class_type'] === 'online' ? 'Online' : e($l['cname'] ?: 'Physical') ?></span><span><?= e(money($l['fee'])) ?></span><?php if ($l['due_date']): ?><span>Due <?= e(date('d M', strtotime($l['due_date']))) ?></span><?php endif; ?><?php if (!$l['active']): ?><span>Hidden</span><?php endif; ?></div>
  <div class="astats"><div><b><?= $n['s'] ?><?= $l['student_limit'] ? '/' . (int)$l['student_limit'] : '' ?></b><small>Students</small></div><div><b><?= $n['m'] ?></b><small>Materials</small></div><div><b><?= $n['p'] ?></b><small>Pending pay</small></div></div></div>
<div class="hub">
  <a class="hubtile r" href="<?= url('admin/materials?lesson=' . $lid) ?>"><span class="hi"><i data-lucide="library"></i></span><b>Materials</b><span>YouTube recordings &amp; documents · <?= $n['m'] ?></span></a>
  <a class="hubtile b" href="<?= url('admin/enrolled?lesson=' . $lid) ?>"><span class="hi"><i data-lucide="users"></i></span><b>Enrolled students</b><span>View, edit, passwords, payments · <?= $n['s'] ?></span></a>
  <a class="hubtile g" href="<?= url('admin/sessions?lesson=' . $lid) ?>"><span class="hi"><i data-lucide="video"></i></span><b>Zoom &amp; live</b><span>Zoom meetings &amp; YouTube live · <?= $n['l'] ?></span></a>
</div>
<?php admin_foot();
