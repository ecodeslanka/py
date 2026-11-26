<?php
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/layout.php'; require __DIR__ . '/inc/quiz.php';
$u = require_login(); if ($u['role'] === 'admin') redirect('admin/quizzes');
$a = row('SELECT * FROM quiz_attempts WHERE id=? AND user_id=?', [(int)($_GET['a'] ?? 0), $u['id']]); if (!$a) redirect('papers');
$z = row("SELECT * FROM quizzes WHERE id=? AND status='published'", [$a['quiz_id']]); if (!$z) redirect('papers');
if ($a['submitted_at']) redirect('quiz_result?a=' . $a['id']);
$dl = quiz_deadline($z, $a); if ($dl !== null && time() > $dl + 8) { quiz_finish((int)$a['id']); redirect('quiz_result?a=' . $a['id']); }
$qs = quiz_questions((int)$z['id']); $saved = []; foreach (rows('SELECT question_id,chosen,is_correct FROM quiz_answers WHERE attempt_id=?', [$a['id']]) as $r) $saved[$r['question_id']] = $r;
$data = []; $fb = [];
foreach ($qs as $k) {
    $o = []; foreach (quiz_options($k) as $i => $t) $o[] = ['i' => $i, 't' => $t]; if ($z['shuffle']) shuffle($o);
    $data[] = ['id' => (int)$k['id'], 'q' => $k['question'], 'o' => $o];
    if ($z['mode'] === 'practice' && isset($saved[$k['id']])) $fb[$k['id']] = ['correct' => (int)$k['correct'], 'ex' => $k['explanation']];   // only for already-answered ones
}
$cfg = ['qs' => $data, 'saved' => array_map(fn($r) => (int)$r['chosen'], $saved), 'fb' => $fb, 'practice' => $z['mode'] === 'practice', 'a' => (int)$a['id'], 'csrf' => csrf(), 'api' => url('quiz_api'),
        'end' => $dl ? $dl * 1000 : null, 'now' => time() * 1000, 'back' => url('quiz?id=' . $z['id'])];
head_html($z['title'], 'app'); ?>
<header class="abar"><div class="abar-in"><a class="ib" href="<?= url('quiz?id=' . $z['id']) ?>" onclick="return confirm('Leave for now? Your answers are saved' + (document.getElementById('qtimer')&&document.getElementById('qtimer').style.display!=='none' ? ', but the timer keeps running.' : '.'))" aria-label="Close"><i data-lucide="x"></i></a>
  <div class="ttl"><?= e($z['title']) ?></div><span class="qtimer" id="qtimer" <?= $dl ? '' : 'style="display:none"' ?>>--:--</span><button class="ib" id="qpal" type="button" aria-label="Questions"><i data-lucide="layout-grid"></i></button></div></header>
<main class="smain qrun" id="qrun" data-cfg="<?= e(json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>">
  <div class="qprog"><div id="qbar"></div></div>
  <div class="qhead"><span id="qnum">1</span><small id="qof"></small></div>
  <div class="card qbody"><div class="qq" id="qq"></div><div class="qchoices" id="qch"></div><div class="qfb" id="qfb" hidden></div></div>
</main>
<div class="qnav"><button class="btn btn-o" id="qprev" type="button">← Back</button><button class="btn" id="qnext" type="button">Next →</button></div>
<dialog class="modal" id="mPal"><div class="mwrap"><div class="mhead b"><h3>Questions</h3><button class="x" type="button" data-close><i data-lucide="x"></i></button></div>
  <div class="mbody"><div class="pal" id="pal"></div><p class="muted small" style="margin-top:12px"><span class="pd on"></span> answered &nbsp; <span class="pd"></span> not yet</p></div>
  <div class="mfoot"><button class="btn" id="qfinish2" type="button">✓ Finish &amp; submit</button></div></div></dialog>
<script src="<?= asset('js/quiz.js') ?>" defer></script>
<?php foot_html();
