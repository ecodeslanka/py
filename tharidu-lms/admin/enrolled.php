<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php';
require_admin();
$l = lesson_ctx(); $lid = (int)$l['id']; $self = 'admin/enrolled?lesson=' . $lid;
if (is_post()) {
    if (post('act') === 'enroll_add') { $sid = (int)post('student_id'); if ($sid && !is_enrolled($sid, $lid)) { q('INSERT INTO enrollments(user_id,lesson_id) VALUES(?,?)', [$sid, $lid]); flash('Student added to lesson.'); } }
    if (post('act') === 'enroll_del') { q('DELETE FROM enrollments WHERE user_id=? AND lesson_id=?', [(int)post('student_id'), $lid]); flash('Student removed from this lesson.'); }
    redirect($self);
}
admin_head('Enrolled students', 'lessons');
lesson_banner($l, 'Students');
$sts = rows('SELECT u.*,e.created_at joined,(SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.user_id=u.id AND p.lesson_id=? AND p.status="approved") paid FROM enrollments e JOIN users u ON u.id=e.user_id WHERE e.lesson_id=? ORDER BY u.name', [$lid, $lid]);
$others = rows('SELECT id,name,mobile FROM users WHERE role="student" AND id NOT IN (SELECT user_id FROM enrollments WHERE lesson_id=?) ORDER BY name', [$lid]);
?>
<div class="card"><h3><i data-lucide="user-plus"></i>Add a student manually</h3>
<form method="post" class="two"><?= csrf_field() ?><input type="hidden" name="act" value="enroll_add">
<label class="f">Student<select name="student_id" required><option value="">— select —</option><?php foreach ($others as $o) echo '<option value="' . $o['id'] . '">' . e($o['name']) . ' (' . e(show_mobile($o['mobile'])) . ')</option>'; ?></select></label>
<div style="align-self:end;margin-bottom:14px"><button class="btn">Add to lesson</button></div></form></div>
<div class="sec-t"><?= count($sts) ?> enrolled</div>
<div class="tile-list">
<?php if (!$sts) echo '<div class="card empty">No students enrolled yet.</div>'; foreach ($sts as $s): ?>
<div class="litem"><span class="ic"><?= e(mb_strtoupper(mb_substr($s['name'], 0, 1))) ?></span>
<div class="grow"><b><?= e($s['name']) ?><?= $s['active'] ? '' : ' <span class="pill err">disabled</span>' ?></b><span class="small muted"><?= e(show_mobile($s['mobile'])) ?><?= $s['nic'] ? ' · ' . e($s['nic']) : '' ?> · Paid <?= e(money($s['paid'])) ?> · Joined <?= e(date('d M Y', strtotime($s['joined']))) ?></span></div>
<div class="acts"><a class="btn btn-b btn-sm" href="<?= url('admin/student?id=' . $s['id']) ?>">View / edit</a><a class="btn btn-o btn-sm" href="<?= url('admin/student?id=' . $s['id'] . '#pw') ?>">Password</a><a class="btn btn-o btn-sm" href="<?= url('admin/student?id=' . $s['id'] . '#pay') ?>">Payments</a>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="enroll_del"><input type="hidden" name="student_id" value="<?= $s['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Remove this student from the lesson?">Remove</button></form></div></div>
<?php endforeach; ?></div>
<?php admin_foot();
