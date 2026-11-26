<?php
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/layout.php'; require __DIR__ . '/inc/quiz.php';
$u = require_login();
$a = row('SELECT * FROM quiz_attempts WHERE id=?', [(int)($_GET['a'] ?? 0)]);
if (!$a || ($u['role'] !== 'admin' && (int)$a['user_id'] !== (int)$u['id'])) redirect('papers');
$z = row('SELECT * FROM quizzes WHERE id=?', [$a['quiz_id']]); if (!$a['submitted_at']) redirect('quiz_run?a=' . $a['id']);
student_head('Result', $u['role'] === 'admin' ? 'admin/quiz-results?id=' . $z['id'] : 'quiz?id=' . $z['id']);
$p = $a['total'] ? round($a['score'] / $a['total'] * 100) : 0; $sec = strtotime($a['submitted_at']) - strtotime($a['started_at']);
$show = can_see_answers($z, $a);
$rows = $show ? rows('SELECT k.*,an.chosen,an.is_correct FROM quiz_questions k LEFT JOIN quiz_answers an ON an.question_id=k.id AND an.attempt_id=? WHERE k.quiz_id=? ORDER BY k.sort_order,k.id', [$a['id'], $z['id']]) : [];
$msg = $p >= 85 ? '🎉 Excellent work!' : ($p >= 60 ? '👏 Good job – keep going!' : ($p >= 40 ? '💪 Getting there – review the answers below.' : '📚 Keep practising – you will improve!'));
[$ok] = $u['role'] === 'admin' ? [false] : quiz_access($z, $u);
?>
<div class="ahero <?= $p >= 50 ? '' : 'b' ?>" style="text-align:center"><div class="hi"><?= e($z['title']) ?> · Attempt <?= (int)$a['attempt_no'] ?></div>
  <div class="ring" style="--p:<?= $p ?>"><div><b><?= $p ?>%</b><small><?= (int)$a['score'] ?> / <?= (int)$a['total'] ?></small></div></div>
  <h1 style="font-size:20px"><?= $msg ?></h1><div class="hi">⏱ <?= floor($sec / 60) ?>m <?= $sec % 60 ?>s</div></div>
<div class="acts" style="margin-bottom:16px"><a class="btn btn-o" href="<?= url($u['role'] === 'admin' ? 'admin/quiz-results?id=' . $z['id'] : 'papers') ?>"><i data-lucide="arrow-left"></i>Back</a><?php if ($ok && $u['role'] !== 'admin'): ?><a class="btn" href="<?= url('quiz?id=' . $z['id']) ?>"><i data-lucide="rotate-ccw"></i><?= $z['mode'] === 'practice' ? 'Practise again' : 'Try again' ?></a><?php endif; ?></div>
<?php if (!$show): ?><div class="card empty">🔒 <?= $z['show_answers'] === 'never' ? 'Correct answers are not shown for this paper.' : 'Correct answers will appear after the paper closes' . ($z['close_at'] ? ' (' . e(date('d M, h:i A', strtotime($z['close_at']))) . ')' : '') . '.' ?></div>
<?php else: ?><div class="sh"><h2>Review</h2><small><?= (int)$a['score'] ?> correct</small></div><div class="tile-list">
<?php foreach ($rows as $n => $k): $opts = quiz_options($k); $mine = $k['chosen'] === null ? null : (int)$k['chosen']; ?>
  <div class="card rv <?= $k['is_correct'] ? 'good' : 'bad' ?>" style="padding:16px"><div class="qtop"><b class="qn" style="background:<?= $k['is_correct'] ? 'var(--g-g)' : 'var(--g-r)' ?>"><?= $n + 1 ?></b><div class="qtext"><?= nl2br(e($k['question'])) ?><?php if ($k['q_image']): ?><img class="qpic zoomable" src="<?= quiz_img_url($k['q_image']) ?>" alt=""><?php endif; ?></div></div>
    <div class="qopts"><?php $oi = quiz_opt_images($k); foreach ($opts as $i => $o): $cl = $i === (int)$k['correct'] ? 'ok' : ($i === $mine ? 'no' : ''); ?><div class="qo <?= $cl ?>"><span><?= chr(65 + $i) ?></span><div class="oc"><?php if ($oi[$i]): ?><img class="opic" src="<?= quiz_img_url($oi[$i]) ?>" alt=""><?php endif; ?><?= e($o) ?></div><?= $i === (int)$k['correct'] ? '<em>✓ correct</em>' : ($i === $mine ? '<em style="color:var(--red)">your answer</em>' : '') ?></div><?php endforeach; ?></div>
    <?php if ($mine === null): ?><div class="small muted" style="margin-top:6px">Not answered</div><?php endif; ?>
    <?php if ($k['explanation']): ?><div class="small" style="margin-top:8px;color:var(--sub)">💡 <?= e($k['explanation']) ?></div><?php endif; ?></div>
<?php endforeach; ?></div><?php endif; ?>
<script>document.addEventListener('click',function(e){var im=e.target.closest('img.zoomable');if(!im)return;var z=document.createElement('div');z.className='lightbox';z.innerHTML='<img src="'+im.src+'" alt=""><button type="button">✕</button>';z.addEventListener('click',function(){z.remove()});document.body.appendChild(z)})</script>
<?php student_foot('papers');
