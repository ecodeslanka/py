<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php';
$u = admin_head('Dashboard', 'index');
$st = [['users', 'Students', val("SELECT COUNT(*) FROM users WHERE role='student'")], ['book-open', 'Active lessons', val('SELECT COUNT(*) FROM lessons WHERE active=1')],
  ['user-check', 'Enrolments', val('SELECT COUNT(*) FROM enrollments')], ['clock', 'Pending payments', val("SELECT COUNT(*) FROM payments WHERE status='pending'")],
  ['wallet', 'Income this month', money(val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='approved' AND decided_at >= DATE_FORMAT(NOW(),'%Y-%m-01')"))]];
if (password_verify('Admin@123', $u['password_hash'])) echo '<div class="alert err">You are still using the default password. <a href="' . url('admin/users') . '"><u>Change it now</u></a>.</div>';
?>
<div class="stats"><?php foreach ($st as [$ic, $l, $v]): ?><div class="stat"><div class="si-i"><i data-lucide="<?= $ic ?>"></i></div><b><?= e($v) ?></b><span><?= e($l) ?></span></div><?php endforeach; ?></div>
<div class="quick">
  <a href="<?= url('admin/lessons') ?>"><i data-lucide="plus-circle"></i>New lesson</a><a href="<?= url('admin/classes') ?>"><i data-lucide="map-pin"></i>Classes</a>
  <a href="<?= url('admin/payments') ?>"><i data-lucide="wallet"></i>Approve payments</a><a href="<?= url('admin/students') ?>"><i data-lucide="users"></i>Students</a>
  <a href="<?= url('admin/home') ?>"><i data-lucide="pencil"></i>Edit home page</a><a href="<?= url('admin/settings') ?>"><i data-lucide="settings"></i>Settings</a>
</div>
<div class="sec-t">Waiting for approval <small><a href="<?= url('admin/payments') ?>" style="color:var(--blue)">See all</a></small></div>
<?php $pend = rows("SELECT p.*,u.name,u.mobile,l.name lname FROM payments p JOIN users u ON u.id=p.user_id JOIN lessons l ON l.id=p.lesson_id WHERE p.status='pending' ORDER BY p.id LIMIT 6");
if (!$pend): ?><div class="card empty">All caught up ✓</div><?php else: ?>
<div class="tablewrap"><table><tr><th>Student</th><th>Lesson</th><th>Amount</th><th>Sent</th></tr>
<?php foreach ($pend as $p): ?><tr><td><b><?= e($p['name']) ?></b> <span class="muted small"><?= e(show_mobile($p['mobile'])) ?></span></td><td><?= e($p['lname']) ?></td><td><?= e(money($p['amount'])) ?></td><td class="muted"><?= e(date('d M H:i', strtotime($p['created_at']))) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
<div class="sec-t">New students</div>
<?php $new = rows('SELECT u.*,b.name bname,c.name cname FROM users u LEFT JOIN batches b ON b.id=u.batch_id LEFT JOIN classes c ON c.id=u.class_id WHERE u.role="student" ORDER BY u.id DESC LIMIT 6');
if (!$new): ?><div class="card empty">No students yet.</div><?php else: ?>
<div class="tablewrap"><table><tr><th>Name</th><th>Mobile</th><th>Batch</th><th>Class</th></tr>
<?php foreach ($new as $s): ?><tr><td><a href="<?= url('admin/student?id=' . $s['id']) ?>"><b><?= e($s['name']) ?></b></a></td><td><?= e(show_mobile($s['mobile'])) ?></td><td><?= e($s['bname'] ?: '—') ?></td><td><?= e($s['class_type'] === 'online' ? 'Online' : ($s['cname'] ?: '—')) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
<?php admin_foot();
