<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php'; require __DIR__ . '/../inc/quiz.php';
require_admin();
$z = row('SELECT * FROM quizzes WHERE id=?', [(int)($_GET['id'] ?? 0)]); if (!$z) { flash('Quiz not found.', 'err'); redirect('admin/quizzes'); }
if (is_post() && post('act') === 'del_attempt') { q('DELETE FROM quiz_attempts WHERE id=? AND quiz_id=?', [(int)post('aid'), $z['id']]); flash('Attempt removed – the student can try again.'); redirect('admin/quiz-results?id=' . $z['id']); }
admin_head('Results', 'quizzes');
$att = rows('SELECT a.*,u.name,u.mobile FROM quiz_attempts a JOIN users u ON u.id=a.user_id WHERE a.quiz_id=? AND a.submitted_at IS NOT NULL ORDER BY a.score DESC, TIMESTAMPDIFF(SECOND,a.started_at,a.submitted_at) ASC', [$z['id']]);
$n = count($att); $avg = $n ? round(array_sum(array_map(fn($a) => $a['total'] ? $a['score'] / $a['total'] * 100 : 0, $att)) / $n) : 0;
$inprog = (int)val('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=? AND submitted_at IS NULL', [$z['id']]);
$hard = rows('SELECT k.id,k.question,SUM(an.is_correct) ok,COUNT(an.question_id) n FROM quiz_questions k LEFT JOIN quiz_answers an ON an.question_id=k.id LEFT JOIN quiz_attempts a ON a.id=an.attempt_id AND a.submitted_at IS NOT NULL WHERE k.quiz_id=? GROUP BY k.id HAVING n>0 ORDER BY (SUM(an.is_correct)/COUNT(an.question_id)) ASC LIMIT 5', [$z['id']]);
?>
<a class="back" href="<?= url('admin/quizzes') ?>"><i data-lucide="chevron-left"></i>All quizzes</a>
<div class="pagehead"><b style="font-size:20px"><?= e($z['title']) ?></b><span class="pill"><?= $z['mode'] === 'practice' ? 'Practice' : 'Exam' ?></span></div>
<div class="stats"><div class="stat"><div class="si-i"><i data-lucide="users"></i></div><b><?= $n ?></b><span>Submitted</span></div><div class="stat"><div class="si-i"><i data-lucide="percent"></i></div><b><?= $avg ?>%</b><span>Average</span></div>
<div class="stat"><div class="si-i"><i data-lucide="trophy"></i></div><b><?= $n ? e($att[0]['score'] . '/' . $att[0]['total']) : '—' ?></b><span>Top score</span></div><div class="stat"><div class="si-i"><i data-lucide="clock"></i></div><b><?= $inprog ?></b><span>In progress</span></div></div>
<?php if ($hard): ?><div class="card" style="margin-bottom:16px"><h3><i data-lucide="flame"></i>Hardest questions</h3><?php foreach ($hard as $h): $p = $h['n'] ? round($h['ok'] / $h['n'] * 100) : 0; ?>
<div style="display:flex;gap:12px;align-items:center;margin-bottom:8px"><span class="pill <?= $p < 40 ? 'err' : ($p < 70 ? 'warn' : 'ok') ?>" style="min-width:52px;justify-content:center"><?= $p ?>%</span><span class="small"><?= e(mb_strimwidth($h['question'], 0, 120, '…')) ?></span></div><?php endforeach; ?></div><?php endif; ?>
<div class="tablewrap"><table><tr><th>#</th><th>Student</th><th>Attempt</th><th>Score</th><th>%</th><th>Time</th><th>Submitted</th><th></th></tr>
<?php foreach ($att as $i => $a): $p = $a['total'] ? round($a['score'] / $a['total'] * 100) : 0; $sec = strtotime($a['submitted_at']) - strtotime($a['started_at']); ?>
<tr><td><?= $i < 3 ? ['🥇', '🥈', '🥉'][$i] : $i + 1 ?></td><td><a href="<?= url('admin/student?id=' . $a['user_id']) ?>"><b><?= e($a['name']) ?></b></a><div class="small muted"><?= e(show_mobile($a['mobile'])) ?></div></td><td>#<?= (int)$a['attempt_no'] ?></td>
<td><b><?= (int)$a['score'] ?>/<?= (int)$a['total'] ?></b></td><td><span class="pill <?= $p >= 75 ? 'ok' : ($p >= 40 ? 'warn' : 'err') ?>"><?= $p ?>%</span></td><td class="small"><?= floor($sec / 60) ?>m <?= $sec % 60 ?>s</td><td class="small muted"><?= e(date('d M H:i', strtotime($a['submitted_at']))) ?></td>
<td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="del_attempt"><input type="hidden" name="aid" value="<?= $a['id'] ?>"><button class="btn btn-o btn-sm" data-confirm="Remove this attempt so the student can retry?">Reset</button></form></td></tr>
<?php endforeach; if (!$att) echo '<tr><td colspan="8" class="empty">No submissions yet.</td></tr>'; ?></table></div>
<?php admin_foot();
