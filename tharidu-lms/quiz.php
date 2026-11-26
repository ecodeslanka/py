<?php
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/layout.php'; require __DIR__ . '/inc/quiz.php';
$u = require_login(); if ($u['role'] === 'admin') redirect('admin/quizzes');
$z = row("SELECT q.*,(SELECT COUNT(*) FROM quiz_questions k WHERE k.quiz_id=q.id) nq FROM quizzes q WHERE q.id=? AND q.status='published'", [(int)($_GET['id'] ?? $_POST['id'] ?? 0)]);
if (!$z) { flash('Paper not found.', 'err'); redirect('papers'); }
$uid = (int)$u['id']; [$ok, $why] = quiz_access($z, $u);
if (is_post() && post('act') === 'start') {
    if (!$ok) { flash('You cannot start this paper: ' . $why, 'err'); redirect('quiz?id=' . $z['id']); }
    $open = val('SELECT id FROM quiz_attempts WHERE quiz_id=? AND user_id=? AND submitted_at IS NULL ORDER BY id DESC LIMIT 1', [$z['id'], $uid]);
    if (!$open) { $no = (int)val('SELECT COALESCE(MAX(attempt_no),0)+1 FROM quiz_attempts WHERE quiz_id=? AND user_id=?', [$z['id'], $uid]); q('INSERT INTO quiz_attempts(quiz_id,user_id,attempt_no,started_at,total) VALUES(?,?,?,NOW(),?)', [$z['id'], $uid, $no, $z['nq']]); $open = db()->lastInsertId(); }
    redirect('quiz_run?a=' . $open);
}
student_head($z['title'], 'papers');
$done = rows('SELECT * FROM quiz_attempts WHERE quiz_id=? AND user_id=? AND submitted_at IS NOT NULL ORDER BY id DESC', [$z['id'], $uid]);
$prog = val('SELECT id FROM quiz_attempts WHERE quiz_id=? AND user_id=? AND submitted_at IS NULL', [$z['id'], $uid]);
?>
<div class="ahero <?= $z['mode'] === 'practice' ? 'b' : '' ?>"><div class="hi"><?= $z['mode'] === 'practice' ? '🏋️ Practice' : '📝 MCQ exam' ?></div><h1><?= e($z['title']) ?></h1>
  <div class="chips"><span><?= (int)$z['nq'] ?> questions</span><?php if ($z['mode'] === 'exam' && $z['duration_min']): ?><span><?= (int)$z['duration_min'] ?> minutes</span><?php endif; ?><span><?= $z['mode'] === 'practice' ? 'Unlimited tries' : ($z['attempts'] ? (int)$z['attempts'] . ' attempt' . ($z['attempts'] > 1 ? 's' : '') : 'Unlimited tries') ?></span></div>
  <?php if ($z['description']): ?><div class="hi" style="white-space:pre-line"><?= e($z['description']) ?></div><?php endif; ?></div>
<div class="card"><h3><i data-lucide="info"></i>Before you start</h3>
  <ul class="rules"><?php if ($z['mode'] === 'practice'): ?><li>After each answer you instantly see the correct answer and an explanation.</li><li>Practise as many times as you like.</li>
  <?php else: ?><?php if ($z['duration_min']): ?><li>You have <b><?= (int)$z['duration_min'] ?> minutes</b>. The timer keeps running even if you leave the page, and the paper is submitted automatically when time ends.</li><?php endif; ?>
  <?php if ($z['open_at'] || $z['close_at']): ?><li>Available: <b><?= $z['open_at'] ? e(date('D d M, h:i A', strtotime($z['open_at']))) : 'now' ?><?= $z['close_at'] ? ' → ' . e(date('D d M, h:i A', strtotime($z['close_at']))) : '' ?></b></li><?php endif; ?>
  <li>Your answers are saved as you tap them. <?= ['after_submit' => 'You see the correct answers right after you submit.', 'after_close' => 'Correct answers appear after the paper closes.', 'never' => 'You will see your score only.'][$z['show_answers']] ?></li><?php endif; ?></ul>
  <?php if ($ok): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="start"><input type="hidden" name="id" value="<?= $z['id'] ?>"><button class="btn btn-block"><?= $prog ? '▶ Continue my attempt' : ($z['mode'] === 'practice' ? '🏋️ Start practising' : '🚀 Start the paper') ?></button></form>
  <?php else: ?><div class="alert err" style="margin:0"><?= e($why) ?></div><?php endif; ?></div>
<?php if ($done): ?><div class="sh"><h2>My attempts</h2></div><div class="tile-list"><?php foreach ($done as $a): $p = $a['total'] ? round($a['score'] / $a['total'] * 100) : 0; ?>
  <a class="litem" href="<?= url('quiz_result?a=' . $a['id']) ?>"><span class="ic <?= $p >= 50 ? 'ok' : 'yt' ?>"><i data-lucide="<?= $p >= 50 ? 'trophy' : 'target' ?>"></i></span><div class="grow"><b><?= (int)$a['score'] ?>/<?= (int)$a['total'] ?> · <?= $p ?>%</b><span class="small muted">Attempt <?= (int)$a['attempt_no'] ?> · <?= e(date('d M Y, h:i A', strtotime($a['submitted_at']))) ?></span></div><span class="btn btn-o btn-sm">View</span></a>
<?php endforeach; ?></div><?php endif; ?>
<?php student_foot('papers');
