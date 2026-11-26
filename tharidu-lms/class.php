<?php
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/layout.php';
$u = student_head('Class');
$l = row('SELECT l.*,b.name bname FROM lessons l JOIN batches b ON b.id=l.batch_id WHERE l.id=?', [(int)($_GET['id'] ?? 0)]);
if (!$l || !is_enrolled($u['id'], $l['id'])) { flash('You are not enrolled in that class.', 'err'); redirect('dashboard'); }
$tab = $_GET['tab'] ?? 'materials';
$mats = rows('SELECT m.*,COALESCE(v.views,0) views FROM materials m LEFT JOIN material_views v ON v.material_id=m.id AND v.user_id=? WHERE m.lesson_id=? ORDER BY m.id DESC', [$u['id'], $l['id']]);
$live = rows('SELECT * FROM sessions_live WHERE lesson_id=? ORDER BY COALESCE(start_at,created_at) DESC', [$l['id']]);
$pays = rows('SELECT * FROM payments WHERE user_id=? AND lesson_id=? ORDER BY id DESC', [$u['id'], $l['id']]);
?>
<a class="muted small" href="<?= url('dashboard') ?>">← My classes</a>
<div class="head" style="margin-top:8px"><h2><?= e($l['name']) ?></h2>
  <p class="lead"><?= e($l['bname']) ?> · <?= e(month_label($l['month_year'])) ?> · <?= $l['class_type'] === 'online' ? 'Online' : 'Physical' . ($l['venue'] ? ' · ' . e($l['venue']) : '') ?></p></div>
<?php if ($l['description']): ?><div class="card"><div class="prose"><?= e($l['description']) ?></div></div><br><?php endif; ?>
<div class="tabs">
  <a class="<?= $tab === 'materials' ? 'on' : '' ?>" href="?id=<?= $l['id'] ?>&tab=materials">Materials</a>
  <a class="<?= $tab === 'live' ? 'on' : '' ?>" href="?id=<?= $l['id'] ?>&tab=live">Live classes</a>
  <a class="<?= $tab === 'pay' ? 'on' : '' ?>" href="?id=<?= $l['id'] ?>&tab=pay">Payments</a>
</div>
<div class="card">
<?php if ($tab === 'live'): ?>
  <?php if (!$live) echo '<p class="empty">No live classes scheduled yet.</p>'; foreach ($live as $s): ?>
  <div class="matitem"><span class="ic <?= $s['kind'] === 'youtube_live' ? 'yt' : 'doc' ?>"><i data-lucide="<?= $s['kind'] === 'youtube_live' ? 'youtube' : 'video' ?>"></i></span>
    <div class="grow"><b><?= e($s['title']) ?></b><div class="small muted"><?= $s['start_at'] ? e(date('D, d M Y · h:i A', strtotime($s['start_at']))) : 'Anytime' ?> · <?= (int)$s['duration_min'] ?> min</div>
    <?php if ($s['passcode']): ?><div class="small">Passcode: <b><?= e($s['passcode']) ?></b></div><?php endif; ?></div>
    <a class="btn btn-r btn-sm" href="<?= url('live?id=' . $s['id']) ?>" target="_blank"><i data-lucide="play"></i>Join</a></div>
  <?php endforeach; ?>
<?php elseif ($tab === 'pay'): ?>
  <?php if (!$pays): echo '<p class="empty">No payments.</p>'; else: ?><div class="tablewrap"><table><tr><th>Date</th><th>Amount</th><th>Status</th><th>Note</th></tr>
  <?php foreach ($pays as $p): ?><tr><td><?= e(date('d M Y', strtotime($p['created_at']))) ?></td><td><?= e(money($p['amount'])) ?></td>
  <td><span class="pill <?= ['approved' => 'ok', 'pending' => 'warn', 'rejected' => 'err'][$p['status']] ?>"><?= e(ucfirst($p['status'])) ?></span></td><td class="small muted"><?= e($p['admin_note']) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
<?php else: ?>
  <?php if (!$mats) echo '<p class="empty">No materials uploaded yet.</p>'; foreach ($mats as $m):
    $left = $m['view_limit'] > 0 ? max(0, $m['view_limit'] - $m['views']) : null; ?>
  <div class="matitem"><span class="ic <?= $m['type'] === 'youtube' ? 'yt' : 'doc' ?>"><i data-lucide="<?= $m['type'] === 'youtube' ? 'play' : 'file-text' ?>"></i></span>
    <div class="grow"><b><?= e($m['title']) ?></b>
    <?php if ($m['description']): ?><div class="small muted"><?= e($m['description']) ?></div><?php endif; ?>
    <?php if ($m['type'] === 'youtube' && $left !== null): ?><div class="small muted"><?= $left ?> of <?= (int)$m['view_limit'] ?> views left</div><?php endif; ?></div>
    <?php if ($m['type'] === 'youtube'): ?>
      <?php if ($left === 0): ?><span class="pill err">View limit reached</span><?php else: ?><a class="btn btn-r btn-sm" href="<?= url('watch?id=' . $m['id']) ?>"><i data-lucide="play"></i>Watch</a><?php endif; ?>
    <?php else: ?><a class="btn btn-b btn-sm" href="<?= url('download?id=' . $m['id']) ?>"><i data-lucide="download"></i>Download</a><?php endif; ?>
  </div><?php endforeach; ?>
<?php endif; ?></div>
<?php student_foot();
