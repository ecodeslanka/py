<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php'; require __DIR__ . '/../inc/quiz.php'; require __DIR__ . '/../inc/sms.php';
require_admin();
function dt(?string $v): ?string { return ($v !== null && trim($v) !== '' && strtotime($v)) ? date('Y-m-d H:i:s', strtotime($v)) : null; }
if (is_post()) {
    $act = post('act'); $id = (int)post('id');
    try {
        if ($act === 'save') {
            if (post('title') === '') throw new RuntimeException('Quiz title is required.');
            $mode = post('mode') === 'practice' ? 'practice' : 'exam'; $aud = in_array(post('audience'), ['batch', 'lesson']) ? post('audience') : 'all';
            $bid = $aud === 'batch' ? (int)post('batch_id') : null; $lid = $aud === 'lesson' ? (int)post('lesson_id') : null;
            if ($aud === 'batch' && !$bid) throw new RuntimeException('Choose the batch.'); if ($aud === 'lesson' && !$lid) throw new RuntimeException('Choose the lesson.');
            $open = $mode === 'exam' ? dt(post('open_at')) : null; $close = $mode === 'exam' ? dt(post('close_at')) : null;
            if ($open && $close && strtotime($close) <= strtotime($open)) throw new RuntimeException('Closing time must be after the opening time.');
            $d = [post('title'), post('description') ?: null, $mode, $aud, $bid, $lid, $open, $close, max(0, (int)post('duration_min')), $mode === 'practice' ? 0 : max(0, (int)post('attempts')),
                in_array(post('show_answers'), ['after_submit', 'after_close', 'never']) ? post('show_answers') : 'after_submit', isset($_POST['shuffle']) ? 1 : 0];
            if ($id) { q('UPDATE quizzes SET title=?,description=?,mode=?,audience=?,batch_id=?,lesson_id=?,open_at=?,close_at=?,duration_min=?,attempts=?,show_answers=?,shuffle=? WHERE id=?', [...$d, $id]); flash('Quiz updated.'); }
            else { q('INSERT INTO quizzes(title,description,mode,audience,batch_id,lesson_id,open_at,close_at,duration_min,attempts,show_answers,shuffle) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)', $d); flash('Quiz created – now add questions.'); redirect('admin/quiz?id=' . db()->lastInsertId()); }
        } elseif ($act === 'status') {
            $z = row('SELECT * FROM quizzes WHERE id=?', [$id]); if (!$z) throw new RuntimeException('Not found.');
            if ($z['status'] === 'draft') {
                if (!(int)val('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=?', [$id])) throw new RuntimeException('Add at least one question before publishing.');
                q("UPDATE quizzes SET status='published' WHERE id=?", [$id]); flash('Published – students can now see it.');
            } else { q("UPDATE quizzes SET status='draft' WHERE id=?", [$id]); flash('Moved back to draft (hidden from students).'); }
        } elseif ($act === 'delete') { q('DELETE FROM quizzes WHERE id=?', [$id]); flash('Quiz deleted.');
        } elseif ($act === 'duplicate') {
            $z = row('SELECT * FROM quizzes WHERE id=?', [$id]); if ($z) {
                q("INSERT INTO quizzes(title,description,mode,audience,batch_id,lesson_id,open_at,close_at,duration_min,attempts,show_answers,shuffle,status,source_paper_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'draft',?)",
                  ['Copy of ' . $z['title'], $z['description'], $z['mode'], $z['audience'], $z['batch_id'], $z['lesson_id'], $z['open_at'], $z['close_at'], $z['duration_min'], $z['attempts'], $z['show_answers'], $z['shuffle'], $z['source_paper_id']]);
                $nid = (int)db()->lastInsertId();
                foreach (quiz_questions($id) as $k) q('INSERT INTO quiz_questions(quiz_id,sort_order,question,options,correct,explanation,ai_conf,reviewed) VALUES(?,?,?,?,?,?,?,?)', [$nid, $k['sort_order'], $k['question'], $k['options'], $k['correct'], $k['explanation'], $k['ai_conf'], $k['reviewed']]);
                flash('Duplicated as a draft.'); redirect('admin/quiz?id=' . $nid);
            }
        } elseif ($act === 'notify') {
            $z = row('SELECT * FROM quizzes WHERE id=?', [$id]); if (!$z || $z['status'] !== 'published') throw new RuntimeException('Publish the quiz first.');
            @set_time_limit(300); $sent = 0; $fail = 0;
            $when = $z['mode'] === 'practice' ? 'Practice anytime' : ($z['open_at'] ? 'Opens ' . date('d M h:i A', strtotime($z['open_at'])) . ($z['close_at'] ? ', closes ' . date('d M h:i A', strtotime($z['close_at'])) : '') : 'Open now');
            foreach (array_slice(quiz_students($z), 0, 300) as $s) { [$ok] = send_sms($s['mobile'], setting('site_name', SITE_NAME) . ': new MCQ paper "' . $z['title'] . '". ' . $when . '. Log in to attempt.'); $ok ? $sent++ : $fail++; }
            flash("SMS sent to $sent students" . ($fail ? " ($fail failed – see Settings → SMS log)" : '') . '.', $fail && !$sent ? 'err' : 'ok');
        }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('admin/quizzes');
}
admin_head('MCQ quizzes', 'quizzes');
$batches = array_column(rows('SELECT id,name FROM batches ORDER BY sort_order,id'), 'name', 'id');
$lessons = array_column(rows('SELECT l.id,CONCAT(l.name," · ",b.name) n FROM lessons l JOIN batches b ON b.id=l.batch_id ORDER BY l.month_year DESC,l.name'), 'n', 'id');
$list = rows('SELECT q.*,(SELECT COUNT(*) FROM quiz_questions k WHERE k.quiz_id=q.id) nq,(SELECT COUNT(*) FROM quiz_questions k WHERE k.quiz_id=q.id AND k.reviewed=0) nr,(SELECT COUNT(*) FROM quiz_attempts a WHERE a.quiz_id=q.id AND a.submitted_at IS NOT NULL) na FROM quizzes q ORDER BY q.id DESC');
$gr = ['g1', 'g2', 'g3', 'g4'];
?>
<div class="toolbar"><span class="muted"><?= count($list) ?> quizzes</span><button class="btn" data-open="mQuiz" data-fill='null'><i data-lucide="plus"></i>New MCQ quiz</button></div>
<?php if (!$list): ?><div class="card empty">No quizzes yet. Create one, or scan a past paper with Gemini on the <a href="<?= url('admin/papers') ?>" style="color:var(--r)"><b>Past papers</b></a> page.</div><?php endif; ?>
<div class="boxes">
<?php foreach ($list as $i => $z): $j = e(json_encode($z, JSON_UNESCAPED_UNICODE)); $pub = $z['status'] === 'published'; ?>
  <div class="gcard <?= $gr[($z['mode'] === 'practice' ? 2 : $i % 2)] ?>"><div class="row"><span class="gi"><i data-lucide="<?= $z['mode'] === 'practice' ? 'dumbbell' : 'timer' ?>"></i></span><h3><?= e($z['title']) ?></h3></div>
    <div class="meta"><span><?= $pub ? '● Published' : '○ Draft' ?></span><span><?= $z['mode'] === 'practice' ? 'Practice' : 'Exam' ?></span><span><?= e(quiz_audience_label($z)) ?></span><span><?= (int)$z['nq'] ?> Qs</span></div>
    <div class="desc"><?php if ($z['mode'] === 'exam'): ?><?= $z['open_at'] ? '🕒 ' . e(date('d M h:i A', strtotime($z['open_at']))) . ($z['close_at'] ? ' → ' . e(date('d M h:i A', strtotime($z['close_at']))) : '') : 'Available any time' ?><?= $z['duration_min'] ? ' · ' . (int)$z['duration_min'] . ' min' : '' ?> · <?= $z['attempts'] ? (int)$z['attempts'] . ' attempt' . ($z['attempts'] > 1 ? 's' : '') : 'unlimited attempts' ?><?php else: ?>Instant answers · unlimited attempts<?php endif; ?>
      <?php if ($z['nr']): ?><br>⚠ <?= (int)$z['nr'] ?> answer<?= $z['nr'] > 1 ? 's' : '' ?> need review<?php endif; ?></div>
    <div class="gfoot" style="flex-wrap:wrap"><a class="gbtn" href="<?= url('admin/quiz?id=' . $z['id']) ?>"><i data-lucide="list-checks"></i>Questions</a><a class="gbtn in" href="<?= url('admin/quiz-results?id=' . $z['id']) ?>"><i data-lucide="bar-chart-3"></i><?= (int)$z['na'] ?></a>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="status"><input type="hidden" name="id" value="<?= $z['id'] ?>"><button class="gbtn in"><?= $pub ? 'Unpublish' : 'Publish' ?></button></form></div>
    <div class="acts" style="position:relative;z-index:1">
      <button class="gbtn in" data-open="mQuiz" data-fill="<?= $j ?>" style="min-height:34px;padding:0 14px"><i data-lucide="pencil"></i></button>
      <?php if ($pub): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="notify"><input type="hidden" name="id" value="<?= $z['id'] ?>"><button class="gbtn in" style="min-height:34px;padding:0 14px" data-confirm="Send an SMS about this quiz to all students who can see it (max 300)? SMS credits will be used."><i data-lucide="message-square"></i>SMS</button></form><?php endif; ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="duplicate"><input type="hidden" name="id" value="<?= $z['id'] ?>"><button class="gbtn in" style="min-height:34px;padding:0 14px" title="Duplicate"><i data-lucide="copy"></i></button></form>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $z['id'] ?>"><button class="gbtn in" style="min-height:34px;padding:0 14px" data-confirm="Delete this quiz with all questions and results?"><i data-lucide="trash-2"></i></button></form></div></div>
<?php endforeach; ?></div>

<dialog class="modal" id="mQuiz"><form method="post" class="mwrap" id="qf"><?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="0">
  <div class="mhead"><h3 id="qTitle">New MCQ quiz</h3><button class="x" type="button" data-close><i data-lucide="x"></i></button></div>
  <div class="mbody">
    <?php field('Quiz title', 'title', '', ['req' => 1, 'attr' => 'placeholder="Unit 5 – Organic chemistry"']); field('Instructions (optional)', 'description', '', ['type' => 'textarea', 'rows' => 2]); ?>
    <div class="f" style="margin-bottom:4px"><b>How will students take it?</b></div>
    <div class="seg"><label><input type="radio" name="mode" value="exam" data-toggle="qm" checked><i data-lucide="timer"></i>Exam</label><label><input type="radio" name="mode" value="practice" data-toggle="qm"><i data-lucide="dumbbell"></i>Practice</label></div>
    <div data-show-for="qm:exam"><div class="two"><?php field('Opens at (optional)', 'open_at', '', ['type' => 'datetime-local', 'hint' => 'Empty = open now']); field('Closes at (optional)', 'close_at', '', ['type' => 'datetime-local']); ?></div>
      <div class="two"><?php field('Time limit (minutes)', 'duration_min', 0, ['type' => 'number', 'attr' => 'min="0"', 'hint' => '0 = no time limit']); field('Attempts per student', 'attempts', 1, ['type' => 'number', 'attr' => 'min="0"', 'hint' => '0 = unlimited']); ?></div></div>
    <p class="muted small" data-show-for="qm:practice" hidden>Practice: open any time, unlimited attempts, the correct answer + explanation appear right after every question.</p>
    <?php field('Who can do it?', 'audience', 'all', ['type' => 'select', 'options' => ['all' => 'Everyone (common)', 'batch' => 'A specific batch', 'lesson' => 'Students of a specific lesson (class)'], 'attr' => 'data-toggle="qa"']); ?>
    <div data-show-for="qa:batch" hidden><?php field('Batch', 'batch_id', '', ['type' => 'select', 'options' => ['' => '— choose —'] + $batches]); ?></div>
    <div data-show-for="qa:lesson" hidden><?php field('Lesson', 'lesson_id', '', ['type' => 'select', 'options' => ['' => '— choose —'] + $lessons]); ?></div>
    <div data-show-for="qm:exam"><?php field('Show correct answers', 'show_answers', 'after_submit', ['type' => 'select', 'options' => ['after_submit' => 'Right after the student submits', 'after_close' => 'Only after the quiz closes', 'never' => 'Never (score only)']]); ?></div>
    <label class="chk"><input type="checkbox" name="shuffle">Shuffle the answer options for each student</label>
  </div>
  <div class="mfoot"><button class="btn btn-o" type="button" data-close>Cancel</button><button class="btn">Save quiz</button></div>
</form></dialog>
<script>
(function(){var d=document.getElementById('mQuiz'),f=document.getElementById('qf');
function loc(v){return v?v.replace(' ','T').slice(0,16):''}
d.addEventListener('fill',function(e){var z=e.detail;f.reset();f.elements.id.value=z?z.id:0;document.getElementById('qTitle').textContent=z?'Edit quiz':'New MCQ quiz';
 if(z){['title','description','duration_min','attempts','show_answers','audience','batch_id','lesson_id'].forEach(function(k){f.elements[k].value=z[k]==null?'':z[k]});
  f.elements.open_at.value=loc(z.open_at);f.elements.close_at.value=loc(z.close_at);f.elements.shuffle.checked=z.shuffle==1;
  [].forEach.call(f.querySelectorAll('input[name=mode]'),function(r){r.checked=r.value===z.mode});}
 f.querySelector('input[name=mode]:checked').dispatchEvent(new Event('change'));f.elements.audience.dispatchEvent(new Event('change'));});})();
</script>
<?php admin_foot();
