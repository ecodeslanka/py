<?php
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/layout.php';
$u = require_login(); if ($u['role'] === 'admin') redirect('admin/');
$l = row('SELECT l.*,b.name bname,c.name cname FROM lessons l JOIN batches b ON b.id=l.batch_id LEFT JOIN classes c ON c.id=l.class_id WHERE l.id=?', [(int)($_GET['id'] ?? 0)]);
if (!$l || !is_enrolled($u['id'], $l['id'])) { flash('You are not enrolled in that lesson.', 'err'); redirect('dashboard'); }
student_head($l['name'], 'dashboard');
$tab = $_GET['tab'] ?? 'materials';
$mats = rows('SELECT m.*,COALESCE(v.views,0) views FROM materials m LEFT JOIN material_views v ON v.material_id=m.id AND v.user_id=? WHERE m.lesson_id=? ORDER BY m.id DESC', [$u['id'], $l['id']]);
$live = rows('SELECT * FROM sessions_live WHERE lesson_id=? ORDER BY COALESCE(start_at,created_at) DESC', [$l['id']]);
$pays = rows('SELECT * FROM payments WHERE user_id=? AND lesson_id=? ORDER BY id DESC', [$u['id'], $l['id']]);
?>
<div class="ahero b"><div class="hi"><?= e($l['bname']) ?> · <?= e(month_label($l['month_year'])) ?></div><h1><?= e($l['name']) ?></h1>
  <div class="chips"><span><?= $l['class_type'] === 'online' ? 'Online' : e($l['cname'] ?: 'Physical') ?></span><?php if ($l['due_date']): ?><span>Due <?= e(date('d M', strtotime($l['due_date']))) ?></span><?php endif; ?></div>
  <?php if ($l['description']): ?><div class="hi" style="white-space:pre-line"><?= e($l['description']) ?></div><?php endif; ?></div>
<div class="apptabs">
  <a class="<?= $tab === 'materials' ? 'on' : '' ?>" href="?id=<?= $l['id'] ?>&tab=materials">📚 Materials</a>
  <a class="<?= $tab === 'live' ? 'on' : '' ?>" href="?id=<?= $l['id'] ?>&tab=live">🎥 Live classes</a>
  <a class="<?= $tab === 'pay' ? 'on' : '' ?>" href="?id=<?= $l['id'] ?>&tab=pay">💳 Payments</a>
</div>
<div class="tile-list">
<?php if ($tab === 'live'): ?>
  <?php if (!$live) echo '<div class="card empty">No live classes scheduled yet.</div>'; foreach ($live as $s_): ?>
  <div class="litem"><span class="ic <?= $s_['kind'] === 'youtube_live' ? 'yt' : '' ?>"><i data-lucide="<?= $s_['kind'] === 'youtube_live' ? 'youtube' : 'video' ?>"></i></span>
    <div class="grow"><b><?= e($s_['title']) ?></b><span class="small muted"><?= $s_['start_at'] ? e(date('D, d M · h:i A', strtotime($s_['start_at']))) : 'Anytime' ?> · <?= (int)$s_['duration_min'] ?> min<?= $s_['passcode'] ? ' · Passcode ' . e($s_['passcode']) : '' ?></span></div>
    <a class="btn btn-sm" href="<?= url('live?id=' . $s_['id']) ?>" target="_blank">Join</a></div>
  <?php endforeach; ?>
<?php elseif ($tab === 'pay'): ?>
  <?php if (!$pays) echo '<div class="card empty">No payments.</div>'; foreach ($pays as $p): ?>
  <div class="litem"><span class="ic <?= $p['status'] === 'approved' ? 'ok' : ($p['status'] === 'rejected' ? 'yt' : '') ?>"><i data-lucide="<?= $p['status'] === 'approved' ? 'check' : ($p['status'] === 'rejected' ? 'x' : 'clock') ?>"></i></span>
    <div class="grow"><b><?= e(money($p['amount'])) ?></b><span class="small muted"><?= e(date('d M Y', strtotime($p['created_at']))) ?><?= $p['admin_note'] ? ' · ' . e($p['admin_note']) : '' ?></span></div>
    <span class="pill <?= ['approved' => 'ok', 'pending' => 'warn', 'rejected' => 'err'][$p['status']] ?>"><?= e(ucfirst($p['status'])) ?></span></div>
  <?php endforeach; ?>
<?php else: ?>
  <?php if (!$mats) echo '<div class="card empty">No materials uploaded yet.</div>'; foreach ($mats as $m):
    $left = $m['view_limit'] > 0 ? max(0, $m['view_limit'] - $m['views']) : null; ?>
  <div class="litem"><span class="ic <?= $m['type'] === 'youtube' ? 'yt' : '' ?>"><i data-lucide="<?= $m['type'] === 'youtube' ? 'play' : 'file-text' ?>"></i></span>
    <div class="grow"><b><?= e($m['title']) ?></b>
    <?php if ($m['description']): ?><span class="small muted"><?= e($m['description']) ?></span><?php endif; ?>
    <?php if ($m['type'] === 'youtube' && $left !== null): ?><span class="small muted"><?= $left ?> of <?= (int)$m['view_limit'] ?> views left</span><?php endif; ?></div>
    <?php if ($m['type'] === 'youtube'): ?>
      <?php if ($left === 0): ?><span class="pill err">Limit reached</span><?php else: ?><a class="btn btn-sm" href="<?= url('watch?id=' . $m['id']) ?>"><i data-lucide="play"></i>Watch</a><?php endif; ?>
    <?php else: ?><a class="btn btn-b btn-sm" href="<?= url('download?id=' . $m['id']) ?>"><i data-lucide="download"></i>Get</a><?php endif; ?>
  </div><?php endforeach; ?>
<?php endif; ?></div>
<?php student_foot(); ?>
