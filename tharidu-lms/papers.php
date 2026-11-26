<?php
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/layout.php'; require __DIR__ . '/inc/quiz.php';
$u = require_login(); if ($u['role'] === 'admin') redirect('admin/papers');
student_head('Papers');
$tab = ($_GET['tab'] ?? 'quiz') === 'past' ? 'past' : 'quiz'; $uid = (int)$u['id'];
$qs = quizzes_for($u);
$my = []; foreach (rows('SELECT quiz_id,COUNT(CASE WHEN submitted_at IS NOT NULL THEN 1 END) done,MAX(CASE WHEN submitted_at IS NOT NULL THEN score END) best,MAX(total) tot,MAX(CASE WHEN submitted_at IS NULL THEN id END) prog FROM quiz_attempts WHERE user_id=? GROUP BY quiz_id', [$uid]) as $r) $my[$r['quiz_id']] = $r;
$groups = ['prog' => [], 'open' => [], 'practice' => [], 'soon' => [], 'done' => []];
foreach ($qs as $z) {
    $a = $my[$z['id']] ?? null; [$ok, $why] = quiz_access($z, $u);
    if ($a && $a['prog'] && $ok) $groups['prog'][] = [$z, $a, $ok, $why];
    elseif ($z['mode'] === 'practice') $groups['practice'][] = [$z, $a, $ok, $why];
    elseif ($ok) $groups['open'][] = [$z, $a, $ok, $why];
    elseif ($z['open_at'] && strtotime($z['open_at']) > time()) $groups['soon'][] = [$z, $a, $ok, $why];
    else $groups['done'][] = [$z, $a, $ok, $why];
}
$titles = ['prog' => '⏳ Continue', 'open' => '🟢 Open now', 'practice' => '🏋️ Practice', 'soon' => '🕒 Coming soon', 'done' => '✅ Finished / closed'];
$papers = rows('SELECT p.* FROM papers p WHERE (p.batch_id IS NULL OR p.batch_id=? OR ?=0) ORDER BY p.year DESC,p.id DESC', [(int)$u['batch_id'], (int)$u['batch_id']]);
$years = []; foreach ($papers as $p) $years[$p['year']][] = $p;
$g = ['g1', 'g2', 'g3', 'g4']; $i = 0;
?>
<div class="apptabs"><a class="<?= $tab === 'quiz' ? 'on' : '' ?>" href="?tab=quiz">📝 MCQ quizzes <?= $qs ? '<span class="pill err" style="margin-left:4px">' . count($qs) . '</span>' : '' ?></a><a class="<?= $tab === 'past' ? 'on' : '' ?>" href="?tab=past">📄 Past papers <?= $papers ? '<span class="pill blue" style="margin-left:4px">' . count($papers) . '</span>' : '' ?></a></div>
<?php if ($tab === 'quiz'): ?>
  <?php if (!$qs): ?><div class="card empty">No MCQ papers for you yet. Your teacher will add some soon.</div><?php endif; ?>
  <?php foreach ($groups as $k => $items): if (!$items) continue; ?>
    <div class="sh"><h2><?= $titles[$k] ?></h2><small><?= count($items) ?></small></div>
    <div class="gstack"><?php foreach ($items as [$z, $a, $ok, $why]): $cls = $g[$i++ % 4]; ?>
      <a class="gcard <?= $cls ?>" href="<?= url('quiz?id=' . $z['id']) ?>"><div class="row"><span class="gi"><i data-lucide="<?= $z['mode'] === 'practice' ? 'dumbbell' : 'timer' ?>"></i></span><h3><?= e($z['title']) ?></h3></div>
        <div class="meta"><span><?= (int)$z['nq'] ?> questions</span><?php if ($z['mode'] === 'exam' && $z['duration_min']): ?><span><?= (int)$z['duration_min'] ?> min</span><?php endif; ?><span><?= $z['mode'] === 'practice' ? 'Instant answers' : ($z['attempts'] ? (int)$z['attempts'] . ' attempt' . ($z['attempts'] > 1 ? 's' : '') : 'Unlimited tries') ?></span></div>
        <?php if ($z['mode'] === 'exam' && ($z['open_at'] || $z['close_at'])): ?><p class="desc">🕒 <?= $z['open_at'] ? e(date('D d M, h:i A', strtotime($z['open_at']))) : 'Now' ?><?= $z['close_at'] ? ' → ' . e(date('D d M, h:i A', strtotime($z['close_at']))) : '' ?></p><?php endif; ?>
        <div class="gfoot"><span class="status"><?= $a && $a['done'] ? 'Best ' . (int)$a['best'] . '/' . (int)$a['tot'] : e($ok ? ($k === 'prog' ? 'In progress' : 'Ready') : $why) ?></span>
          <span class="gbtn"><?= $k === 'prog' ? 'Continue' : ($k === 'practice' ? 'Practise' : ($ok ? 'Start' : ($a && $a['done'] ? 'Result' : 'Details'))) ?> <i data-lucide="chevron-right"></i></span></div></a>
    <?php endforeach; ?></div>
  <?php endforeach; ?>
<?php else: ?>
  <input id="psearch" class="psearch" placeholder="🔍 Search paper or year…" autocomplete="off">
  <?php if (!$papers): ?><div class="card empty">No past papers uploaded yet.</div><?php endif; ?>
  <?php foreach ($years as $y => $items): ?>
  <details class="card ypap" open style="padding:0;margin-bottom:12px"><summary class="ysum"><b><?= (int)$y ?></b><span class="pill"><?= count($items) ?></span></summary>
    <div class="tile-list" style="padding:0 14px 14px"><?php foreach ($items as $p): ?>
      <div class="litem pitem" data-s="<?= e(mb_strtolower($y . ' ' . $p['name'] . ' ' . $p['description'])) ?>"><span class="ic yt"><i data-lucide="file-text"></i></span>
        <div class="grow"><b><?= e($p['name']) ?></b><?php if ($p['description']): ?><span class="small muted"><?= e($p['description']) ?></span><?php endif; ?></div>
        <div class="acts"><a class="btn btn-sm" href="<?= url('paper_dl?id=' . $p['id'] . '&f=paper') ?>"><i data-lucide="download"></i>Paper</a>
          <?php if ($p['scheme_file']): ?><a class="btn btn-ok btn-sm" href="<?= url('paper_dl?id=' . $p['id'] . '&f=scheme') ?>"><i data-lucide="check-circle"></i>Answers</a><?php endif; ?></div></div>
    <?php endforeach; ?></div></details>
  <?php endforeach; ?>
  <script>document.getElementById('psearch')&&document.getElementById('psearch').addEventListener('input',function(){var v=this.value.toLowerCase().trim();document.querySelectorAll('.pitem').forEach(function(e){e.style.display=(!v||e.dataset.s.indexOf(v)>-1)?'':'none'});document.querySelectorAll('.ypap').forEach(function(d){d.style.display=[].some.call(d.querySelectorAll('.pitem'),function(e){return e.style.display!=='none'})?'':'none'})});</script>
<?php endif; ?>
<?php student_foot('papers');
