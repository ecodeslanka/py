<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php'; require __DIR__ . '/../inc/quiz.php';
require_admin();
$z = row('SELECT * FROM quizzes WHERE id=?', [(int)($_GET['id'] ?? $_POST['qid'] ?? 0)]);
if (!$z) { flash('Quiz not found.', 'err'); redirect('admin/quizzes'); }
$qid = (int)$z['id']; $self = 'admin/quiz?id=' . $qid;
if (is_post()) {
    $act = post('act');
    if ($act === 'set_correct') {                                              // AJAX: tap an option to make it the correct answer
        header('Content-Type: application/json'); $k = row('SELECT * FROM quiz_questions WHERE id=? AND quiz_id=?', [(int)post('id'), $qid]); $c = (int)post('correct');
        if (!$k || $c < 0 || $c >= count(quiz_options($k))) exit('{"ok":false}');
        q('UPDATE quiz_questions SET correct=?,reviewed=1 WHERE id=?', [$c, $k['id']]); exit('{"ok":true}');
    }
    try {
        if ($act === 'save_q') {
            $opts = []; $map = [];
            for ($i = 0; $i < 6; $i++) { $t = trim((string)($_POST['opt'][$i] ?? '')); if ($t !== '') { $map[$i] = count($opts); $opts[] = $t; } }
            if (post('question') === '') throw new RuntimeException('Write the question.'); if (count($opts) < 2) throw new RuntimeException('Add at least two answer options.');
            $c = (int)post('correct'); if (!isset($map[$c])) throw new RuntimeException('Select which option is correct.');
            $id = (int)post('id');
            if ($id) q('UPDATE quiz_questions SET question=?,options=?,correct=?,explanation=?,reviewed=1 WHERE id=? AND quiz_id=?', [post('question'), json_encode($opts, JSON_UNESCAPED_UNICODE), $map[$c], post('explanation') ?: null, $id, $qid]);
            else q('INSERT INTO quiz_questions(quiz_id,sort_order,question,options,correct,explanation,reviewed) VALUES(?,?,?,?,?,?,1)', [$qid, (int)val('SELECT COALESCE(MAX(sort_order),0)+1 FROM quiz_questions WHERE quiz_id=?', [$qid]), post('question'), json_encode($opts, JSON_UNESCAPED_UNICODE), $map[$c], post('explanation') ?: null]);
            flash($id ? 'Question updated.' : 'Question added.');
        } elseif ($act === 'del_q') { q('DELETE FROM quiz_questions WHERE id=? AND quiz_id=?', [(int)post('id'), $qid]); flash('Question deleted.');
        } elseif ($act === 'confirm_all') { q('UPDATE quiz_questions SET reviewed=1 WHERE quiz_id=?', [$qid]); flash('All answers confirmed ✔');
        } elseif ($act === 'confirm_q') { q('UPDATE quiz_questions SET reviewed=1 WHERE id=? AND quiz_id=?', [(int)post('id'), $qid]);
        } elseif ($act === 'move') {
            $all = quiz_questions($qid); $ids = array_column($all, 'id'); $i = array_search((int)post('id'), $ids); $j = $i + (post('dir') === 'up' ? -1 : 1);
            if ($i !== false && isset($ids[$j])) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; foreach ($ids as $n => $kid) q('UPDATE quiz_questions SET sort_order=? WHERE id=?', [$n + 1, $kid]); }
        } elseif ($act === 'ai') {
            $files = []; $tmp = [];
            foreach (['paper' => 'paper', 'scheme' => 'scheme'] as $field => $role) {
                if (!empty($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                    $n = $_FILES[$field]['name']; $ext = strtolower(pathinfo($n, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['pdf', 'docx', 'doc', 'jpg', 'jpeg', 'png', 'webp'], true)) throw new RuntimeException('Use PDF, DOCX or image files.');
                    $files[] = ['path' => $_FILES[$field]['tmp_name'], 'name' => $n, 'role' => $role];
                } elseif (!empty($_FILES[$field]) && !in_array($_FILES[$field]['error'], [UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK], true)) throw new RuntimeException('Upload failed – the file may be too large for the server (check upload_max_filesize).');
            }
            if ((int)post('from_paper')) { $p = row('SELECT * FROM papers WHERE id=?', [(int)post('from_paper')]); if ($p) { $files[] = ['path' => ROOT . '/uploads/papers/' . $p['paper_file'], 'name' => $p['paper_name'] ?: $p['paper_file'], 'role' => 'paper']; if ($p['scheme_file']) $files[] = ['path' => ROOT . '/uploads/papers/' . $p['scheme_file'], 'name' => $p['scheme_name'] ?: $p['scheme_file'], 'role' => 'scheme']; } }
            [$ok, $r] = quiz_ai_extract($files, post('paste'), max(1, min(120, (int)post('maxq') ?: 60)));
            if (!$ok) throw new RuntimeException($r);
            $n = quiz_add_extracted($qid, $r['questions']);
            if ($z['title'] === '' && $r['title']) q('UPDATE quizzes SET title=? WHERE id=?', [$r['title'], $qid]);
            $low = count(array_filter($r['questions'], fn($x) => $x['conf'] < 90));
            flash("✨ Gemini added $n questions" . ($low ? " – $low need a quick answer check (marked ⚠)." : ' – all answers are confident.') . ' Tap the right answer on any question to correct it, then publish.');
        }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect($self);
}
admin_head($z['title'], 'quizzes');
$qs = quiz_questions($qid); $need = count(array_filter($qs, fn($k) => !$k['reviewed']));
$papers = rows('SELECT id,year,name FROM papers ORDER BY year DESC,id DESC');
$hasKey = dec(setting('gemini_key')) !== '';
?>
<a class="back" href="<?= url('admin/quizzes') ?>"><i data-lucide="chevron-left"></i>All quizzes</a>
<div class="ahero b"><div class="hi"><?= $z['mode'] === 'practice' ? 'Practice' : 'Exam' ?> · <?= e(quiz_audience_label($z)) ?></div><h1><?= e($z['title']) ?></h1>
  <div class="chips"><span><?= $z['status'] === 'published' ? '● Published' : '○ Draft' ?></span><?php if ($z['mode'] === 'exam'): ?><span><?= $z['open_at'] ? e(date('d M h:i A', strtotime($z['open_at']))) : 'Open now' ?><?= $z['close_at'] ? ' → ' . e(date('d M h:i A', strtotime($z['close_at']))) : '' ?></span><?php if ($z['duration_min']): ?><span><?= (int)$z['duration_min'] ?> min</span><?php endif; ?><?php endif; ?></div>
  <div class="astats"><div><b><?= count($qs) ?></b><small>Questions</small></div><div><b><?= $need ?></b><small>Need review</small></div><div><b><?= (int)val('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=? AND submitted_at IS NOT NULL', [$qid]) ?></b><small>Attempts</small></div></div></div>
<div class="toolbar"><div class="acts"><button class="btn" data-open="mQ" data-fill='null'><i data-lucide="plus"></i>Add question</button><a class="btn btn-o" href="<?= url('admin/quiz-results?id=' . $qid) ?>"><i data-lucide="bar-chart-3"></i>Results</a>
  <form method="post" action="<?= url('admin/quizzes') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="status"><input type="hidden" name="id" value="<?= $qid ?>"><button class="btn <?= $z['status'] === 'published' ? 'btn-o' : 'btn-ok' ?>" <?= $need && $z['status'] !== 'published' ? 'data-confirm="' . $need . ' answers are still marked for review. Publish anyway?"' : '' ?>><?= $z['status'] === 'published' ? 'Unpublish' : '✓ Publish to students' ?></button></form></div>
  <?php if ($need): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="confirm_all"><button class="btn btn-ok"><i data-lucide="check-check"></i>Confirm all <?= $need ?> answers</button></form><?php endif; ?></div>

<details class="card sect" <?= !$qs ? 'open' : '' ?>><summary>✨ Scan a paper with Gemini (PDF · DOCX · image · pasted text)</summary><div class="sbody"><br>
<?php if (!$hasKey): ?><div class="alert err">Add your Gemini API key first: <a href="<?= url('admin/settings?tab=ai') ?>"><u>Settings → AI Chatbot</u></a>.</div><?php endif; ?>
<p class="muted small">Gemini reads the paper, builds every MCQ with its options and finds the correct answer (from the marking scheme if you add one, otherwise it solves them and tells you how sure it is). Then you only tap the right answer where it is wrong. Large files can take up to a minute.</p><br>
<form method="post" enctype="multipart/form-data" id="aiForm"><?= csrf_field() ?><input type="hidden" name="act" value="ai">
<div class="two"><label class="f">Question paper<span class="hint">PDF, DOCX or photo · max 18MB</span><input type="file" name="paper" accept=".pdf,.docx,.doc,.jpg,.jpeg,.png,.webp"></label>
<label class="f">Marking scheme (optional – gives exact answers)<span class="hint">PDF, DOCX or photo</span><input type="file" name="scheme" accept=".pdf,.docx,.doc,.jpg,.jpeg,.png,.webp"></label></div>
<?php if ($papers): ?><label class="f">…or use a stored past paper<select name="from_paper"><option value="0">— none —</option><?php foreach ($papers as $p) echo '<option value="' . $p['id'] . '">' . (int)$p['year'] . ' · ' . e($p['name']) . '</option>'; ?></select></label><?php endif; ?>
<?php field('…or paste the questions as text', 'paste', '', ['type' => 'textarea', 'rows' => 4, 'hint' => 'Any format, Sinhala or English']); field('Max questions to import', 'maxq', 60, ['type' => 'number', 'attr' => 'min="1" max="120"']); ?>
<button class="btn" <?= $hasKey ? '' : 'disabled' ?>><i data-lucide="sparkles"></i>Scan &amp; create questions</button></form></div></details>

<div class="sec-t">Questions <small><?= count($qs) ?></small></div>
<?php if (!$qs): ?><div class="card empty">No questions yet – scan a paper above or tap “Add question”.</div><?php endif; ?>
<div class="tile-list">
<?php foreach ($qs as $n => $k): $opts = quiz_options($k); $j = e(json_encode(['id' => $k['id'], 'question' => $k['question'], 'options' => $opts, 'correct' => (int)$k['correct'], 'explanation' => $k['explanation']], JSON_UNESCAPED_UNICODE)); ?>
  <div class="card qcard <?= $k['reviewed'] ? '' : 'review' ?>" data-id="<?= $k['id'] ?>" style="padding:16px">
    <div class="qtop"><b class="qn"><?= $n + 1 ?></b><div class="qtext"><?= nl2br(e($k['question'])) ?></div>
      <div class="acts"><?php if ($k['ai_conf'] !== null): ?><span class="pill <?= $k['reviewed'] ? 'ok' : 'warn' ?>" title="Gemini's confidence">✨ <?= (int)$k['ai_conf'] ?>%</span><?php endif; ?></div></div>
    <div class="qopts"><?php foreach ($opts as $i => $o): ?><button type="button" class="qo <?= $i === (int)$k['correct'] ? 'ok' : '' ?>" data-i="<?= $i ?>"><span><?= chr(65 + $i) ?></span><?= e($o) ?><?= $i === (int)$k['correct'] ? '<em>✓ correct</em>' : '' ?></button><?php endforeach; ?></div>
    <?php if ($k['explanation']): ?><div class="small muted" style="margin-top:8px">💡 <?= e($k['explanation']) ?></div><?php endif; ?>
    <div class="acts" style="margin-top:10px"><?php if (!$k['reviewed']): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="confirm_q"><input type="hidden" name="id" value="<?= $k['id'] ?>"><button class="btn btn-ok btn-sm">✓ Answer is right</button></form><?php endif; ?>
      <button class="btn btn-o btn-sm" data-open="mQ" data-fill="<?= $j ?>"><i data-lucide="pencil"></i>Edit</button>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="move"><input type="hidden" name="id" value="<?= $k['id'] ?>"><input type="hidden" name="dir" value="up"><button class="btn btn-o btn-sm" title="Move up">↑</button></form>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="move"><input type="hidden" name="id" value="<?= $k['id'] ?>"><input type="hidden" name="dir" value="down"><button class="btn btn-o btn-sm" title="Move down">↓</button></form>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="del_q"><input type="hidden" name="id" value="<?= $k['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Delete this question?"><i data-lucide="trash-2"></i></button></form></div></div>
<?php endforeach; ?></div>

<dialog class="modal" id="mQ"><form method="post" class="mwrap" id="qform"><?= csrf_field() ?><input type="hidden" name="act" value="save_q"><input type="hidden" name="id" value="0">
  <div class="mhead"><h3 id="qmTitle">Add question</h3><button class="x" type="button" data-close><i data-lucide="x"></i></button></div>
  <div class="mbody"><?php field('Question', 'question', '', ['type' => 'textarea', 'rows' => 3, 'req' => 1]); ?>
    <div class="f"><b>Answer options</b><span class="hint">Tick the circle of the correct one. Leave extra options empty.</span></div>
    <?php for ($i = 0; $i < 6; $i++): ?><div class="optrow"><input type="radio" name="correct" value="<?= $i ?>" <?= $i === 0 ? 'checked' : '' ?>><span class="ol"><?= chr(65 + $i) ?></span><input name="opt[<?= $i ?>]" placeholder="Option <?= chr(65 + $i) ?>"></div><?php endfor; ?>
    <?php field('Explanation (optional)', 'explanation', '', ['type' => 'textarea', 'rows' => 2, 'hint' => 'Shown to students with the answer']); ?></div>
  <div class="mfoot"><button class="btn btn-o" type="button" data-close>Cancel</button><button class="btn">Save question</button></div>
</form></dialog>
<style>.qcard.review{outline:2px solid var(--orange);outline-offset:-2px}.qtop{display:flex;gap:12px;align-items:flex-start}.qn{width:30px;height:30px;border-radius:10px;background:var(--g-b);color:#fff;display:grid;place-items:center;font-size:13px;flex:none}.qtext{flex:1;font-weight:600;line-height:1.5}
.qopts{display:grid;gap:6px;margin-top:10px}.qo{display:flex;gap:10px;align-items:center;text-align:left;border:1px solid var(--sep);background:var(--field);color:var(--ink);border-radius:14px;padding:9px 12px;font:inherit;font-size:14.5px;cursor:pointer}.qo span{width:24px;height:24px;border-radius:50%;background:var(--fill);display:grid;place-items:center;font-size:12px;font-weight:700;flex:none}
.qo.ok{border-color:var(--green);background:var(--green-t)}.qo.ok span{background:var(--green);color:#fff}.qo em{margin-left:auto;font-style:normal;font-size:12px;font-weight:700;color:var(--green)}.qo:hover{border-color:var(--b)}
.optrow{display:flex;align-items:center;gap:8px;margin-bottom:8px}.optrow input[type=radio]{width:20px;height:20px;margin:0}.optrow .ol{width:26px;font-weight:700;color:var(--mute)}.optrow input[name^=opt]{margin:0;flex:1}
.aibusy{position:fixed;inset:0;z-index:99999;background:rgba(6,11,36,.75);backdrop-filter:blur(6px);display:none;place-items:center;color:#fff;text-align:center}.aibusy div{max-width:300px}.aibusy .sp{width:54px;height:54px;border-radius:50%;border:5px solid rgba(255,255,255,.2);border-top-color:#ff9a1f;margin:0 auto 14px;animation:ecpspin 1s linear infinite}</style>
<div class="aibusy" id="aibusy"><div><div class="sp"></div><b>Gemini is reading the paper…</b><p style="opacity:.8;font-size:13px">This can take up to a minute. Please keep this page open.</p></div></div>
<script>
(function(){var $=function(i){return document.getElementById(i)},f=$('qform'),d=$('mQ');
d.addEventListener('fill',function(e){var k=e.detail;f.reset();f.elements.id.value=k?k.id:0;$('qmTitle').textContent=k?'Edit question':'Add question';
 if(k){f.elements.question.value=k.question;f.elements.explanation.value=k.explanation||'';for(var i=0;i<6;i++){f.elements['opt['+i+']'].value=k.options[i]||''}f.querySelector('input[name=correct][value="'+k.correct+'"]').checked=true}});
var ai=$('aiForm');if(ai)ai.addEventListener('submit',function(){$('aibusy').style.display='grid'});
document.querySelectorAll('.qcard .qo').forEach(function(b){b.addEventListener('click',function(){var c=b.closest('.qcard'),fd=new FormData();fd.append('act','set_correct');fd.append('qid','<?= $qid ?>');fd.append('id',c.dataset.id);fd.append('correct',b.dataset.i);fd.append('_csrf','<?= e(csrf()) ?>');
 fetch(location.href,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){if(!j.ok)throw 0;
  c.querySelectorAll('.qo').forEach(function(o){o.classList.remove('ok');var em=o.querySelector('em');if(em)em.remove()});b.classList.add('ok');b.insertAdjacentHTML('beforeend','<em>✓ correct</em>');c.classList.remove('review');
  var cf=c.querySelector('form input[value=confirm_q]');if(cf)cf.closest('form').remove();}).catch(function(){alert('Could not save – reload and try again.')})})});})();
</script>
<?php admin_foot();
