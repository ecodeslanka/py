<?php
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/layout.php';
$u = require_login();
if ($u['role'] === 'admin') redirect('admin/');
if (is_post() && post('act') === 'pw') {
    if (!password_verify((string)$_POST['old'], $u['password_hash'])) flash('Current password is wrong.', 'err');
    elseif (strlen((string)$_POST['new']) < 6 || $_POST['new'] !== $_POST['new2']) flash('New password must be 6+ characters and match.', 'err');
    else { q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($_POST['new'], PASSWORD_DEFAULT), $u['id']]); flash('Password changed.'); }
    redirect('dashboard#me');
}
student_head('Home');
$batchesAll = rows('SELECT id,name FROM batches WHERE active=1 ORDER BY sort_order,id');
$selB = (int)($_GET['b'] ?? $u['batch_id'] ?? 0); if (!in_array($selB, array_column($batchesAll, 'id'))) $selB = (int)($batchesAll[0]['id'] ?? 0);
$my = row('SELECT b.name bname,c.name cname FROM users u LEFT JOIN batches b ON b.id=u.batch_id LEFT JOIN classes c ON c.id=u.class_id WHERE u.id=?', [$u['id']]);
$mine = rows('SELECT l.*,b.name bname FROM enrollments e JOIN lessons l ON l.id=e.lesson_id JOIN batches b ON b.id=l.batch_id WHERE e.user_id=? ORDER BY l.month_year DESC', [$u['id']]);
$mineIds = array_column($mine, 'id');
$pay = [];
foreach (rows('SELECT lesson_id,status FROM payments WHERE user_id=? ORDER BY id', [$u['id']]) as $p) $pay[$p['lesson_id']] = $p['status'];
$inCart = array_column(rows('SELECT lesson_id FROM cart WHERE user_id=?', [$u['id']]), 'lesson_id');
$avail = array_values(array_filter(rows('SELECT l.*,g.name gname,(SELECT COUNT(*) FROM enrollments e WHERE e.lesson_id=l.id) cnt FROM lessons l LEFT JOIN grades g ON g.id=l.grade_id WHERE l.active=1 AND l.batch_id=? ORDER BY l.month_year DESC, l.name', [$selB]), fn($l) => !in_array($l['id'], $mineIds)));
$hist = rows('SELECT p.*,l.name lname FROM payments p JOIN lessons l ON l.id=p.lesson_id WHERE p.user_id=? ORDER BY p.id DESC LIMIT 30', [$u['id']]);
$pendingN = count(array_filter($hist, fn($p) => $p['status'] === 'pending'));
[$cartN, $cartTotal] = cart_summary((int)$u['id']);
$g = ['g1', 'g2', 'g3', 'g4'];
?>
<!-- HOME -->
<section class="screen on" data-screen="home">
  <div class="ahero"><div class="hi">Welcome back 👋</div><h1><?= e($u['name']) ?></h1>
    <div class="chips"><?php if ($my['bname']): ?><span><?= e($my['bname']) ?></span><?php endif; ?><?php if ($my['cname']): ?><span><?= e($my['cname']) ?></span><?php endif; ?></div>
    <div class="astats"><div><b><?= count($mine) ?></b><small>My lessons</small></div><div><b><?= count($avail) ?></b><small>Available</small></div><div><b><?= $pendingN ?></b><small>Pending</small></div></div></div>
  <div class="sh"><h2>My lessons</h2><small><?= count($mine) ?> joined</small></div>
  <?php if (!$mine): ?><div class="card empty">No lessons yet.<br><a class="btn btn-sm" style="margin-top:12px" href="#browse" data-goto="browse">Browse &amp; add to cart</a></div><?php else: ?>
  <div class="gstack"><?php foreach ($mine as $i => $l): ?>
    <a class="gcard <?= $g[$i % 4] ?>" href="<?= url('lesson?id=' . $l['id']) ?>"><div class="row"><span class="gi"><i data-lucide="book-open"></i></span><h3><?= e($l['name']) ?></h3></div>
      <div class="meta"><span><?= e($l['bname']) ?></span><span><?= e(month_label($l['month_year'])) ?></span></div>
      <div class="gfoot"><span class="status">✓ Enrolled</span><span class="gbtn">Open <i data-lucide="chevron-right"></i></span></div></a>
  <?php endforeach; ?></div><?php endif; ?>
</section>

<!-- LESSONS TO JOIN -->
<section class="screen" data-screen="browse">
  <div class="sh" style="margin-top:6px"><h2>Lessons by batch</h2><small><?= count($avail) ?> available</small></div>
  <div class="chipsrow"><?php foreach ($batchesAll as $b): ?><a class="<?= $b['id'] == $selB ? 'on' : '' ?>" href="<?= url('dashboard?b=' . $b['id'] . '#browse') ?>"><?= e($b['name']) ?></a><?php endforeach; ?></div>
  <?php if (!$avail): ?><div class="card empty">No more lessons in this batch right now.</div><?php else: ?>
  <div class="gstack"><?php foreach ($avail as $i => $l): $st = $pay[$l['id']] ?? null; $full = $l['student_limit'] > 0 && $l['cnt'] >= $l['student_limit']; $inc = in_array($l['id'], $inCart); ?>
    <div class="gcard <?= $g[($i + 1) % 4] ?>"><div class="row"><span class="gi"><i data-lucide="book-open"></i></span><h3><?= e($l['name']) ?></h3></div>
      <div class="meta"><span><?= e(month_label($l['month_year'])) ?></span><?php if ($l['gname']): ?><span><?= e($l['gname']) ?></span><?php endif; ?></div>
      <?php if ($l['description']): ?><p class="desc"><?= e(mb_strimwidth($l['description'], 0, 120, '…')) ?></p><?php endif; ?>
      <div class="gfoot"><div><div class="fee"><?= e(money($l['fee'])) ?></div><div class="sub"><?= $l['due_date'] ? 'Pay by ' . e(date('d M Y', strtotime($l['due_date']))) : ($l['student_limit'] ? ($l['student_limit'] - $l['cnt']) . ' seats left' : 'Open') ?></div></div>
        <?php if ($st === 'pending'): ?><span class="status">⏳ Awaiting approval</span>
        <?php elseif ($full): ?><span class="status">Full</span>
        <?php else: ?><form method="post" action="<?= url('cart') ?>"><?= csrf_field() ?><input type="hidden" name="lesson" value="<?= $l['id'] ?>"><input type="hidden" name="back" value="dashboard?b=<?= $selB ?>#browse">
          <?php if ($inc): ?><input type="hidden" name="act" value="remove"><button class="gbtn in"><i data-lucide="check"></i>In cart · Remove</button>
          <?php else: ?><input type="hidden" name="act" value="add"><button class="gbtn"><i data-lucide="shopping-cart"></i>Add to cart</button><?php endif; ?></form><?php endif; ?></div></div>
  <?php endforeach; ?></div><?php endif; ?>
  <?php if ($cartN): ?><a class="cartbar" href="<?= url('cart') ?>"><i data-lucide="shopping-cart"></i><b><?= $cartN ?> lesson<?= $cartN > 1 ? 's' : '' ?> · <?= e(money($cartTotal)) ?><small>Tap to checkout</small></b><span class="btn btn-sm">Checkout</span></a><?php endif; ?>
</section>

<!-- PAYMENTS -->
<section class="screen" data-screen="pay">
  <div class="sh" style="margin-top:6px"><h2>Payments</h2><small><?= count($hist) ?></small></div>
  <?php if (!$hist): ?><div class="card empty">No payments yet.</div><?php else: ?><div class="tile-list">
  <?php foreach ($hist as $p): $cls = ['approved' => 'ok', 'pending' => 'warn', 'rejected' => 'err'][$p['status']]; ?>
    <div class="litem"><span class="ic <?= $p['status'] === 'approved' ? 'ok' : ($p['status'] === 'rejected' ? 'yt' : '') ?>"><i data-lucide="<?= $p['status'] === 'approved' ? 'check' : ($p['status'] === 'rejected' ? 'x' : 'clock') ?>"></i></span>
      <div class="grow"><b><?= e($p['lname']) ?></b><span class="small muted"><?= e(date('d M Y', strtotime($p['created_at']))) ?><?= $p['order_ref'] ? ' · ' . e($p['order_ref']) : '' ?><?= $p['admin_note'] ? ' · ' . e($p['admin_note']) : '' ?></span></div>
      <div style="text-align:right"><b><?= e(money($p['amount'])) ?></b><span class="pill <?= $cls ?>"><?= e(ucfirst($p['status'])) ?></span></div></div>
  <?php endforeach; ?></div><?php endif; ?>
</section>

<!-- PROFILE -->
<section class="screen" data-screen="me">
  <div class="ahero b" style="text-align:center"><div class="avatar" style="width:72px;height:72px;font-size:30px;margin:0 auto 10px;background:rgba(255,255,255,.25)"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></div>
    <h1 style="margin:0"><?= e($u['name']) ?></h1><div class="hi"><?= e(show_mobile($u['mobile'])) ?><?= $u['nic'] ? ' · ' . e($u['nic']) : '' ?></div></div>
  <div class="card"><h3><i data-lucide="key-round"></i>Change password</h3>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="pw">
    <?php field('Current password', 'old', '', ['type' => 'password', 'req' => 1]); field('New password', 'new', '', ['type' => 'password', 'req' => 1, 'attr' => 'minlength="6"']); field('Repeat new password', 'new2', '', ['type' => 'password', 'req' => 1]); ?>
    <button class="btn btn-block">Update password</button></form></div>
  <br><a class="btn btn-o btn-block" href="<?= url() ?>"><i data-lucide="globe"></i>Visit website</a><br>
  <a class="btn btn-danger btn-block" href="<?= url('logout') ?>"><i data-lucide="log-out"></i>Logout</a>
</section>
<?php student_foot();
