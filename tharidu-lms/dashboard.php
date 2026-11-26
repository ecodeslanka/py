<?php
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/layout.php';
$u = student_head('My classes');
if (is_post() && post('act') === 'pw') {
    if (!password_verify((string)$_POST['old'], $u['password_hash'])) flash('Current password is wrong.', 'err');
    elseif (strlen((string)$_POST['new']) < 6 || $_POST['new'] !== $_POST['new2']) flash('New password must be 6+ characters and match.', 'err');
    else { q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($_POST['new'], PASSWORD_DEFAULT), $u['id']]); flash('Password changed.'); }
    redirect('dashboard');
}
$mine = rows('SELECT l.*,b.name bname FROM enrollments e JOIN lessons l ON l.id=e.lesson_id JOIN batches b ON b.id=l.batch_id WHERE e.user_id=? ORDER BY l.month_year DESC', [$u['id']]);
$mineIds = array_column($mine, 'id');
$pay = [];
foreach (rows('SELECT lesson_id,status FROM payments WHERE user_id=? ORDER BY id', [$u['id']]) as $p) $pay[$p['lesson_id']] = $p['status'];
$avail = rows('SELECT l.*,g.name gname,(SELECT COUNT(*) FROM enrollments e WHERE e.lesson_id=l.id) cnt FROM lessons l LEFT JOIN grades g ON g.id=l.grade_id
   WHERE l.active=1 AND l.batch_id=? ORDER BY (l.class_type=?) DESC, l.month_year DESC, l.name', [(int)$u['batch_id'], $u['class_type']]);
$avail = array_filter($avail, fn($l) => !in_array($l['id'], $mineIds));
?>
<div class="head"><h2>Hello, <span class="grad-r"><?= e($u['name']) ?></span></h2><p class="lead">Your classes, payments and materials.</p></div>
<div class="card"><h3><i data-lucide="book-open"></i>My classes</h3>
<?php if (!$mine): ?><p class="empty">You have not joined any class yet. Pick one below and send your payment.</p><?php else: ?>
<div class="cgrid2"><?php foreach ($mine as $l): ?>
  <div class="lcard"><h3><?= e($l['name']) ?></h3>
    <div class="meta"><span class="pill"><?= e($l['bname']) ?></span><span class="pill"><?= e(month_label($l['month_year'])) ?></span><span class="pill"><?= $l['class_type'] === 'online' ? 'Online' : 'Physical' . ($l['venue'] ? ' · ' . e($l['venue']) : '') ?></span></div>
    <div class="foot"><span class="pill ok">Enrolled</span><a class="btn btn-b btn-sm" href="<?= url('class?id=' . $l['id']) ?>">Open <i data-lucide="arrow-right"></i></a></div></div>
<?php endforeach; ?></div><?php endif; ?></div>

<div class="card"><h3><i data-lucide="sparkles"></i>Classes you can join</h3>
<?php if (!$avail): ?><p class="empty">No more classes available for your batch right now.</p><?php else: ?>
<div class="cgrid2"><?php foreach ($avail as $l): $st = $pay[$l['id']] ?? null; $full = $l['student_limit'] > 0 && $l['cnt'] >= $l['student_limit']; ?>
  <div class="lcard"><h3><?= e($l['name']) ?></h3>
    <div class="meta"><span class="pill"><?= e(month_label($l['month_year'])) ?></span><?php if ($l['gname']): ?><span class="pill"><?= e($l['gname']) ?></span><?php endif; ?>
      <span class="pill"><?= $l['class_type'] === 'online' ? 'Online' : 'Physical' . ($l['venue'] ? ' · ' . e($l['venue']) : '') ?></span></div>
    <?php if ($l['description']): ?><p class="muted small"><?= e(mb_strimwidth($l['description'], 0, 140, '…')) ?></p><?php endif; ?>
    <div class="fee"><?= e(money($l['fee'])) ?></div>
    <?php if ($l['due_date']): ?><div class="small muted">Pay by <?= e(date('d M Y', strtotime($l['due_date']))) ?></div><?php endif; ?>
    <div class="foot">
      <?php if ($st === 'pending'): ?><span class="pill warn">Payment awaiting approval</span>
      <?php elseif ($full): ?><span class="pill err">Class full</span>
      <?php else: ?><?= $st === 'rejected' ? '<span class="pill err">Last payment rejected</span>' : '<span class="pill">' . ($l['student_limit'] ? ($l['student_limit'] - $l['cnt']) . ' seats left' : 'Open') . '</span>' ?>
        <a class="btn btn-r btn-sm" href="<?= url('pay?lesson=' . $l['id']) ?>"><i data-lucide="wallet"></i>Join &amp; pay</a><?php endif; ?>
    </div></div>
<?php endforeach; ?></div><?php endif; ?></div>

<?php $hist = rows('SELECT p.*,l.name lname FROM payments p JOIN lessons l ON l.id=p.lesson_id WHERE p.user_id=? ORDER BY p.id DESC LIMIT 20', [$u['id']]); if ($hist): ?>
<div class="card"><h3><i data-lucide="receipt"></i>My payments</h3><div class="tablewrap"><table><tr><th>Date</th><th>Class</th><th>Amount</th><th>Status</th><th>Note</th></tr>
<?php foreach ($hist as $p): ?><tr><td><?= e(date('d M Y', strtotime($p['created_at']))) ?></td><td><?= e($p['lname']) ?></td><td><?= e(money($p['amount'])) ?></td>
<td><span class="pill <?= ['approved' => 'ok', 'pending' => 'warn', 'rejected' => 'err'][$p['status']] ?>"><?= e(ucfirst($p['status'])) ?></span></td><td class="small muted"><?= e($p['admin_note']) ?></td></tr><?php endforeach; ?></table></div></div><?php endif; ?>

<div class="card"><h3><i data-lucide="key-round"></i>Change password</h3>
<form method="post" class="three"><?= csrf_field() ?><input type="hidden" name="act" value="pw">
<?php field('Current password', 'old', '', ['type' => 'password', 'req' => 1]); field('New password', 'new', '', ['type' => 'password', 'req' => 1, 'attr' => 'minlength="6"']); field('Repeat new password', 'new2', '', ['type' => 'password', 'req' => 1]); ?>
<div><button class="btn btn-o">Update password</button></div></form></div>
<?php student_foot();
