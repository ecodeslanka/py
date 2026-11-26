<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php';
admin_head('Dashboard', 'index');
$st = [['Students', val("SELECT COUNT(*) FROM users WHERE role='student'")], ['Classes', val('SELECT COUNT(*) FROM lessons WHERE active=1')],
  ['Enrolments', val('SELECT COUNT(*) FROM enrollments')], ['Pending payments', val("SELECT COUNT(*) FROM payments WHERE status='pending'")],
  ['Income this month', money(val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='approved' AND decided_at >= DATE_FORMAT(NOW(),'%Y-%m-01')"))]];
?>
<div class="stats"><?php foreach ($st as [$l, $v]): ?><div class="stat"><b><?= e($v) ?></b><span><?= e($l) ?></span></div><?php endforeach; ?></div>
<div class="card"><h3><i data-lucide="clock"></i>Pending payments</h3>
<?php $pend = rows("SELECT p.*,u.name,u.mobile,l.name lname FROM payments p JOIN users u ON u.id=p.user_id JOIN lessons l ON l.id=p.lesson_id WHERE p.status='pending' ORDER BY p.id LIMIT 8");
if (!$pend): echo '<p class="empty">Nothing waiting. 🎉</p>'; else: ?>
<div class="tablewrap"><table><tr><th>Student</th><th>Class</th><th>Amount</th><th>Date</th></tr>
<?php foreach ($pend as $p): ?><tr><td><?= e($p['name']) ?> <span class="muted small"><?= e(show_mobile($p['mobile'])) ?></span></td><td><?= e($p['lname']) ?></td><td><?= e(money($p['amount'])) ?></td><td><?= e(date('d M H:i', strtotime($p['created_at']))) ?></td></tr><?php endforeach; ?></table></div>
<br><a class="btn btn-r btn-sm" href="<?= url('admin/payments') ?>">Review payments</a><?php endif; ?></div>
<?php admin_foot();
