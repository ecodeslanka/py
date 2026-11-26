<?php
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/layout.php';
$u = require_login();
if ($u['role'] === 'admin') redirect('admin/');
if (is_post() && post('act') === 'pw') {
    if (!password_verify((string)$_POST['old'], $u['password_hash'])) flash('Current password is wrong.', 'err');
    elseif (strlen((string)$_POST['new']) < 6 || $_POST['new'] !== $_POST['new2']) flash('New password must be 6+ characters and match.', 'err');
    else { q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($_POST['new'], PASSWORD_DEFAULT), $u['id']]); flash('Password changed.'); }
    redirect('dashboard#profile');
}
student_head('My lessons');
$all = isset($_GET['all']);
$my = row('SELECT b.name bname,c.name cname FROM users u LEFT JOIN batches b ON b.id=u.batch_id LEFT JOIN classes c ON c.id=u.class_id WHERE u.id=?', [$u['id']]);
$mine = rows('SELECT l.*,b.name bname,c.name cname FROM enrollments e JOIN lessons l ON l.id=e.lesson_id JOIN batches b ON b.id=l.batch_id LEFT JOIN classes c ON c.id=l.class_id WHERE e.user_id=? ORDER BY l.month_year DESC', [$u['id']]);
$mineIds = array_column($mine, 'id');
$pay = [];
foreach (rows('SELECT lesson_id,status FROM payments WHERE user_id=? ORDER BY id', [$u['id']]) as $p) $pay[$p['lesson_id']] = $p['status'];
$sql = 'SELECT l.*,g.name gname,c.name cname,(SELECT COUNT(*) FROM enrollments e WHERE e.lesson_id=l.id) cnt FROM lessons l LEFT JOIN grades g ON g.id=l.grade_id LEFT JOIN classes c ON c.id=l.class_id WHERE l.active=1 AND l.batch_id=?'; $par = [(int)$u['batch_id']];
if (!$all) {
    if ($u['class_type'] === 'physical') { $sql .= ' AND l.class_type="physical" AND l.class_id=?'; $par[] = (int)$u['class_id']; }
    elseif ($u['class_type'] === 'online') $sql .= ' AND l.class_type="online"';
}
$avail = array_filter(rows($sql . ' ORDER BY l.month_year DESC, l.name', $par), fn($l) => !in_array($l['id'], $mineIds));
$hist = rows('SELECT p.*,l.name lname FROM payments p JOIN lessons l ON l.id=p.lesson_id WHERE p.user_id=? ORDER BY p.id DESC LIMIT 20', [$u['id']]);
$ico = ['', 'o', 'g', 'p'];
function lesson_meta(array $l): string {
    return '<span class="pill blue">' . e($l['bname'] ?? '') . '</span><span class="pill">' . e(month_label($l['month_year'])) . '</span><span class="pill">' . ($l['class_type'] === 'online' ? 'Online' : e($l['cname'] ?: 'Physical')) . '</span>';
}
?>
<div class="muted">Welcome back</div>
<h1 class="stitle"><?= e($u['name']) ?></h1>
<div class="meta" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px"><?php if ($my['bname']): ?><span class="pill blue"><?= e($my['bname']) ?></span><?php endif; ?><span class="pill"><?= $u['class_type'] === 'online' ? 'Online' : e($my['cname'] ?: 'Physical') ?></span><span class="pill"><?= e(show_mobile($u['mobile'])) ?></span></div>

<div class="sec-t">My lessons <small><?= count($mine) ?></small></div>
<?php if (!$mine): ?><div class="card empty">You haven't joined a lesson yet. Pick one below and send your payment.</div><?php else: ?>
<div class="boxes"><?php foreach ($mine as $i => $l): ?>
  <a class="lcard" href="<?= url('lesson?id=' . $l['id']) ?>"><div class="top"><span class="ico <?= $ico[$i % 4] ?>"><i data-lucide="<?= $l['class_type'] === 'online' ? 'monitor-play' : 'book-open' ?>"></i></span><h3><?= e($l['name']) ?></h3></div>
    <div class="meta"><?= lesson_meta($l) ?></div>
    <div class="foot"><span class="pill ok">Enrolled</span><span class="btn btn-sm">Open <i data-lucide="chevron-right"></i></span></div></a>
<?php endforeach; ?></div><?php endif; ?>

<div class="sec-t">Available lessons <small><?= $all ? '<a href="' . url('dashboard') . '" style="color:var(--blue)">Show mine only</a>' : '<a href="' . url('dashboard?all=1') . '" style="color:var(--blue)">Show all in my batch</a>' ?></small></div>
<?php if (!$avail): ?><div class="card empty">No lessons available for you right now.</div><?php else: ?>
<div class="boxes"><?php foreach ($avail as $i => $l): $st = $pay[$l['id']] ?? null; $full = $l['student_limit'] > 0 && $l['cnt'] >= $l['student_limit']; ?>
  <div class="lcard"><div class="top"><span class="ico <?= $ico[($i + 1) % 4] ?>"><i data-lucide="<?= $l['class_type'] === 'online' ? 'monitor-play' : 'book-open' ?>"></i></span><h3><?= e($l['name']) ?></h3></div>
    <div class="meta"><span class="pill"><?= e(month_label($l['month_year'])) ?></span><span class="pill"><?= $l['class_type'] === 'online' ? 'Online' : e($l['cname'] ?: 'Physical') ?></span><?php if ($l['gname']): ?><span class="pill"><?= e($l['gname']) ?></span><?php endif; ?></div>
    <?php if ($l['description']): ?><p class="desc"><?= e(mb_strimwidth($l['description'], 0, 130, '…')) ?></p><?php endif; ?>
    <div><div class="fee"><?= e(money($l['fee'])) ?></div><?php if ($l['due_date']): ?><div class="small muted">Pay by <?= e(date('d M Y', strtotime($l['due_date']))) ?></div><?php endif; ?></div>
    <div class="foot">
      <?php if ($st === 'pending'): ?><span class="pill warn">Awaiting approval</span>
      <?php elseif ($full): ?><span class="pill err">Full</span>
      <?php else: ?><?= $st === 'rejected' ? '<span class="pill err">Payment rejected</span>' : '<span class="pill">' . ($l['student_limit'] ? ($l['student_limit'] - $l['cnt']) . ' seats left' : 'Open') . '</span>' ?>
        <a class="btn btn-sm" href="<?= url('pay?lesson=' . $l['id']) ?>"><i data-lucide="wallet"></i>Join &amp; pay</a><?php endif; ?>
    </div></div>
<?php endforeach; ?></div><?php endif; ?>

<div class="sec-t" id="payments">Payments</div>
<?php if (!$hist): ?><div class="card empty">No payments yet.</div><?php else: ?>
<div class="tablewrap"><table><tr><th>Date</th><th>Lesson</th><th>Amount</th><th>Status</th><th>Note</th></tr>
<?php foreach ($hist as $p): ?><tr><td><?= e(date('d M Y', strtotime($p['created_at']))) ?></td><td><?= e($p['lname']) ?></td><td><?= e(money($p['amount'])) ?></td>
<td><span class="pill <?= ['approved' => 'ok', 'pending' => 'warn', 'rejected' => 'err'][$p['status']] ?>"><?= e(ucfirst($p['status'])) ?></span></td><td class="small muted"><?= e($p['admin_note']) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>

<div class="sec-t" id="profile">Profile</div>
<div class="card"><h3><i data-lucide="key-round"></i>Change password</h3>
<form method="post" class="three"><?= csrf_field() ?><input type="hidden" name="act" value="pw">
<?php field('Current password', 'old', '', ['type' => 'password', 'req' => 1]); field('New password', 'new', '', ['type' => 'password', 'req' => 1, 'attr' => 'minlength="6"']); field('Repeat new password', 'new2', '', ['type' => 'password', 'req' => 1]); ?>
<div><button class="btn btn-o">Update password</button></div></form></div>
<?php student_foot();
