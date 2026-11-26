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
    if ($act === 'scan_page') {                                                 // AJAX: one rendered page image of the paper (rendered in the admin's browser)
        header('Content-Type: application/json'); $tok = post('token'); $n = (int)post('n'); $f = post_file('page');
        if (!preg_match('/^[a-f0-9]{16,32}$/', $tok) || $n < 1 || $n > 40 || !$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 8 * 1048576) exit(json_encode(['ok' => false, 'error' => 'Bad page upload']));
        $info = @getimagesize($f['tmp_name']); if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) exit(json_encode(['ok' => false, 'error' => 'Not an image']));
        $dir = ROOT . '/uploads/quizimg/tmp/' . $tok; if (!is_dir($dir) && !mkdir($dir, 0755, true)) exit(json_encode(['ok' => false, 'error' => 'Cannot create temp folder']));
        foreach (glob(ROOT . '/uploads/quizimg/tmp/*', GLOB_ONLYDIR) ?: [] as $old) if (filemtime($old) < time() - 86400) { array_map('unlink', glob("$old/*") ?: []); @rmdir($old); }   // tidy old scans
        $dst = "$dir/p$n.jpg";
        if ($info['mime'] === 'image/jpeg' && $info[0] <= 2200) $ok = move_uploaded_file($f['tmp_name'], $dst);
        elseif (function_exists('imagecreatefromstring')) { $im = imagecreatefromstring(file_get_contents($f['tmp_name'])); $w = min(2000, $info[0]); $h = (int)round($info[1] * $w / $info[0]); $c = imagecreatetruecolor($w, $h); imagefill($c, 0, 0, imagecolorallocate($c, 255, 255, 255)); imagecopyresampled($c, $im, 0, 0, 0, 0, $w, $h, $info[0], $info[1]); $ok = imagejpeg($c, $dst, 85); }
        else $ok = false;
        exit(json_encode(['ok' => (bool)$ok]));
    }
    try {
        if ($act === 'save_q') {
            $id = (int)post('id'); $old = null; $created = [];
            if ($id) { $old = row('SELECT * FROM quiz_questions WHERE id=? AND quiz_id=?', [$id, $qid]); if (!$old) throw new RuntimeException('Question not found.'); }
            $oldQ = $old['q_image'] ?? null; $oldO = $old ? quiz_opt_images($old) : []; $trash = [];
            try {
                $qimg = $oldQ;
                if ($f = post_file('q_image')) { $qimg = quiz_img_save($f); $created[] = $qimg; if ($oldQ) $trash[] = $oldQ; } elseif (!empty($_POST['q_image_rm'])) { if ($oldQ) $trash[] = $oldQ; $qimg = null; }
                $opts = []; $imgs = []; $map = [];
                for ($i = 0; $i < 6; $i++) {
                    $t = trim((string)($_POST['opt'][$i] ?? '')); $cur = $oldO[$i] ?? null; $im = $cur;
                    if ($nf = post_file('opt_img', $i)) { $im = quiz_img_save($nf); $created[] = $im; if ($cur) $trash[] = $cur; } elseif (!empty($_POST['opt_img_rm'][$i])) { if ($cur) $trash[] = $cur; $im = null; }
                    if ($t !== '' || $im) { $map[$i] = count($opts); $opts[] = $t; $imgs[] = $im; }
                }
                if (post('question') === '' && !$qimg) throw new RuntimeException('Write the question or add a picture for it.');
                if (count($opts) < 2) throw new RuntimeException('Add at least two answer options (text or picture).');
                $c = (int)post('correct'); if (!isset($map[$c])) throw new RuntimeException('Select which option is correct.');
            } catch (Throwable $ex) { foreach ($created as $n) @unlink(quiz_img_path($n)); throw $ex; }
            $oj = array_filter($imgs) ? json_encode($imgs) : null;
            if ($id) q('UPDATE quiz_questions SET question=?,options=?,correct=?,explanation=?,q_image=?,opt_images=?,reviewed=1 WHERE id=? AND quiz_id=?', [post('question'), json_encode($opts, JSON_UNESCAPED_UNICODE), $map[$c], post('explanation') ?: null, $qimg, $oj, $id, $qid]);
            else q('INSERT INTO quiz_questions(quiz_id,sort_order,question,options,correct,explanation,q_image,opt_images,reviewed) VALUES(?,?,?,?,?,?,?,?,1)', [$qid, (int)val('SELECT COALESCE(MAX(sort_order),0)+1 FROM quiz_questions WHERE quiz_id=?', [$qid]), post('question'), json_encode($opts, JSON_UNESCAPED_UNICODE), $map[$c], post('explanation') ?: null, $qimg, $oj]);
            quiz_img_gc($trash);
            flash($id ? 'Question updated.' : 'Question added.');
        } elseif ($act === 'del_q') {
            $k = row('SELECT * FROM quiz_questions WHERE id=? AND quiz_id=?', [(int)post('id'), $qid]);
            if ($k) { q('DELETE FROM quiz_questions WHERE id=?', [$k['id']]); quiz_img_gc(quiz_question_images($k)); } flash('Question deleted.');
        } elseif ($act === 'confirm_all') { q('UPDATE quiz_questions SET reviewed=1 WHERE quiz_id=?', [$qid]); flash('All answers confirmed ✔');
        } elseif ($act === 'confirm_q') { q('UPDATE quiz_questions SET reviewed=1 WHERE id=? AND quiz_id=?', [(int)post('id'), $qid]);
        } elseif ($act === 'move') {
            $all = quiz_questions($qid); $ids = array_column($all, 'id'); $i = array_search((int)post('id'), $ids); $j = $i + (post('dir') === 'up' ? -1 : 1);
            if ($i !== false && isset($ids[$j])) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; foreach ($ids as $n => $kid) q('UPDATE quiz_questions SET sort_order=? WHERE id=?', [$n + 1, $kid]); }
        } elseif ($act === 'ai') {
            $files = []; $pages = []; $tmpDir = null; $tok = post('scan_token');
            try {
                if ($tok !== '' && preg_match('/^[a-f0-9]{16,32}$/', $tok)) {                      // pages rendered in the browser -> figures can be auto-cut
                    $tmpDir = ROOT . '/uploads/quizimg/tmp/' . $tok; $pages = glob($tmpDir . '/p*.jpg') ?: [];
                    usort($pages, fn($a, $b) => (int)substr(basename($a), 1) <=> (int)substr(basename($b), 1)); $pages = array_slice($pages, 0, 40);
                }
                foreach (['paper' => 'paper', 'scheme' => 'scheme'] as $field => $role) {
                    if ($role === 'paper' && $pages) continue;
                    if (!empty($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                        $n = $_FILES[$field]['name']; $ext = strtolower(pathinfo($n, PATHINFO_EXTENSION));
                        if (!in_array($ext, ['pdf', 'docx', 'doc', 'jpg', 'jpeg', 'png', 'webp'], true)) throw new RuntimeException('Use PDF, DOCX or image files.');
                        $files[] = ['path' => $_FILES[$field]['tmp_name'], 'name' => $n, 'role' => $role];
                    } elseif (!empty($_FILES[$field]) && !in_array($_FILES[$field]['error'], [UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK], true)) throw new RuntimeException('Upload failed – the file may be too large for the server (check upload_max_filesize).');
                }
                if ((int)post('from_paper')) { $p = row('SELECT * FROM papers WHERE id=?', [(int)post('from_paper')]);
                    if ($p) { if (!$pages) $files[] = ['path' => ROOT . '/uploads/papers/' . $p['paper_file'], 'name' => $p['paper_name'] ?: $p['paper_file'], 'role' => 'paper'];
                              if ($p['scheme_file'] && !array_filter($files, fn($f) => $f['role'] === 'scheme')) $files[] = ['path' => ROOT . '/uploads/papers/' . $p['scheme_file'], 'name' => $p['scheme_name'] ?: $p['scheme_file'], 'role' => 'scheme']; } }
                [$ok, $r] = quiz_ai_extract($files, post('paste'), max(1, min(120, (int)post('maxq') ?: 60)), $pages);
                if (!$ok) throw new RuntimeException($r);
                [$n, $figs] = quiz_add_extracted($qid, $r['questions'], $pages);
                $low = count(array_filter($r['questions'], fn($x) => $x['conf'] < 90));
                flash("✨ Gemini added $n questions" . ($figs ? " and auto-cut $figs picture" . ($figs > 1 ? 's' : '') : '') . ($low ? " – $low need a quick answer check (marked ⚠)." : ' – all answers are confident.') . ' Tap the right answer on any question to correct it, then publish.');
            } finally { if ($tmpDir && is_dir($tmpDir)) { array_map('unlink', glob("$tmpDir/*") ?: []); @rmdir($tmpDir); } }
        }
    } catch (Throwable $ex) { if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); exit(json_encode(['ok' => false, 'error' => $ex->getMessage()])); } flash($ex->getMessage(), 'err'); }
    if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); exit(json_encode(['ok' => true])); }
    redirect($self);
}
admin_head($z['title'], 'quizzes');
$qs = quiz_questions($qid); $need = count(array_filter($qs, fn($k) => !$k['reviewed']));
$papers = rows('SELECT id,year,name,paper_file,scheme_file FROM papers ORDER BY year DESC,id DESC');
$pmeta = []; foreach ($papers as $p) $pmeta[$p['id']] = ['ext' => strtolower(pathinfo($p['paper_file'], PATHINFO_EXTENSION))];
$hasKey = dec(setting('gemini_key')) !== ''; $scanPaper = (int)($_GET['scan_paper'] ?? 0);
?>
<a class="back" href="<?= url('admin/quizzes') ?>"><i data-lucide="chevron-left"></i>All quizzes</a>
<div class="ahero b"><div class="hi"><?= $z['mode'] === 'practice' ? 'Practice' : 'Exam' ?> · <?= e(quiz_audience_label($z)) ?></div><h1><?= e($z['title']) ?></h1>
  <div class="chips"><span><?= $z['status'] === 'published' ? '● Published' : '○ Draft' ?></span><?php if ($z['mode'] === 'exam'): ?><span><?= $z['open_at'] ? e(date('d M h:i A', strtotime($z['open_at']))) : 'Open now' ?><?= $z['close_at'] ? ' → ' . e(date('d M h:i A', strtotime($z['close_at']))) : '' ?></span><?php if ($z['duration_min']): ?><span><?= (int)$z['duration_min'] ?> min</span><?php endif; ?><?php endif; ?></div>
  <div class="astats"><div><b><?= count($qs) ?></b><small>Questions</small></div><div><b><?= $need ?></b><small>Need review</small></div><div><b><?= (int)val('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=? AND submitted_at IS NOT NULL', [$qid]) ?></b><small>Attempts</small></div></div></div>
<div class="toolbar"><div class="acts"><button class="btn" data-open="mQ" data-fill='null'><i data-lucide="plus"></i>Add question</button><a class="btn btn-o" href="<?= url('admin/quiz-results?id=' . $qid) ?>"><i data-lucide="bar-chart-3"></i>Results</a>
  <form method="post" action="<?= url('admin/quizzes') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="status"><input type="hidden" name="id" value="<?= $qid ?>"><button class="btn <?= $z['status'] === 'published' ? 'btn-o' : 'btn-ok' ?>" <?= $need && $z['status'] !== 'published' ? 'data-confirm="' . $need . ' answers are still marked for review. Publish anyway?"' : '' ?>><?= $z['status'] === 'published' ? 'Unpublish' : '✓ Publish to students' ?></button></form></div>
  <?php if ($need): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="confirm_all"><button class="btn btn-ok"><i data-lucide="check-check"></i>Confirm all <?= $need ?> answers</button></form><?php endif; ?></div>

<details class="card sect" id="aiCard" <?= (!$qs || $scanPaper) ? 'open' : '' ?>><summary>✨ Scan a paper with Gemini (PDF · DOCX · photos · pasted text)</summary><div class="sbody"><br>
<?php if (!$hasKey): ?><div class="alert err">Add your Gemini API key first: <a href="<?= url('admin/settings?tab=ai') ?>"><u>Settings → AI Chatbot</u></a>.</div><?php endif; ?>
<p class="muted small">Gemini reads the paper, builds every MCQ with its options and the correct answer (from the marking scheme if you add one, otherwise it solves them and shows how sure it is). With <b>✂ auto-cut</b> it also finds every diagram, structure or graph and <b>cuts it out of the page</b> into the right question / option. You only tap the right answer where it is wrong.</p><br>
<form method="post" enctype="multipart/form-data" id="aiForm"><?= csrf_field() ?><input type="hidden" name="act" value="ai">
<div class="two"><label class="f">Question paper<span class="hint">One PDF, several page photos, or a DOCX · PDF ≤ 40 pages</span><input type="file" name="paper" id="aiPaper" multiple accept=".pdf,.docx,.doc,.jpg,.jpeg,.png,.webp,image/*"></label>
<label class="f">Marking scheme (optional – gives exact answers)<span class="hint">PDF, DOCX or photo</span><input type="file" name="scheme" accept=".pdf,.docx,.doc,.jpg,.jpeg,.png,.webp"></label></div>
<?php if ($papers): ?><label class="f">…or use a stored past paper<select name="from_paper" id="aiFrom"><option value="0">— none —</option><?php foreach ($papers as $p) echo '<option value="' . $p['id'] . '"' . ($p['id'] == $scanPaper ? ' selected' : '') . '>' . (int)$p['year'] . ' · ' . e($p['name']) . '</option>'; ?></select></label><?php endif; ?>
<?php field('…or paste the questions as text', 'paste', '', ['type' => 'textarea', 'rows' => 3, 'hint' => 'Any format, Sinhala or English']); ?>
<div class="two"><?php field('Max questions to import', 'maxq', 60, ['type' => 'number', 'attr' => 'min="1" max="120"']); ?><label class="chk" style="align-self:end;margin-bottom:22px"><input type="checkbox" id="aiCut" checked>✂ Auto-cut diagrams &amp; structures into the questions</label></div>
<button class="btn" <?= $hasKey ? '' : 'disabled' ?>><i data-lucide="sparkles"></i>Scan &amp; create questions</button></form></div></details>

<div class="sec-t">Questions <small><?= count($qs) ?></small></div>
<?php if (!$qs): ?><div class="card empty">No questions yet – scan a paper above or tap “Add question”.</div><?php endif; ?>
<div class="tile-list">
<?php foreach ($qs as $n => $k): $opts = quiz_options($k); $oi = quiz_opt_images($k);
  $j = e(json_encode(['id' => $k['id'], 'question' => $k['question'], 'options' => $opts, 'correct' => (int)$k['correct'], 'explanation' => $k['explanation'], 'q_image' => $k['q_image'], 'opt_images' => $oi], JSON_UNESCAPED_UNICODE)); ?>
  <div class="card qcard <?= $k['reviewed'] ? '' : 'review' ?>" data-id="<?= $k['id'] ?>" style="padding:16px">
    <div class="qtop"><b class="qn"><?= $n + 1 ?></b><div class="qtext"><?= $k['question'] !== '' ? nl2br(e($k['question'])) : '' ?><?php if ($k['q_image']): ?><img class="qpic" src="<?= quiz_img_url($k['q_image']) ?>" alt="" loading="lazy"><?php endif; ?></div>
      <div class="acts"><?php if ($k['ai_conf'] !== null): ?><span class="pill <?= $k['reviewed'] ? 'ok' : 'warn' ?>" title="Gemini's confidence">✨ <?= (int)$k['ai_conf'] ?>%</span><?php endif; ?></div></div>
    <div class="qopts"><?php foreach ($opts as $i => $o): ?><button type="button" class="qo <?= $i === (int)$k['correct'] ? 'ok' : '' ?>" data-i="<?= $i ?>"><span><?= chr(65 + $i) ?></span><div class="oc"><?php if ($oi[$i]): ?><img class="opic" src="<?= quiz_img_url($oi[$i]) ?>" alt="" loading="lazy"><?php endif; ?><?= $o !== '' ? e($o) : '' ?></div><?= $i === (int)$k['correct'] ? '<em>✓ correct</em>' : '' ?></button><?php endforeach; ?></div>
    <?php if ($k['explanation']): ?><div class="small muted" style="margin-top:8px">💡 <?= e($k['explanation']) ?></div><?php endif; ?>
    <div class="acts" style="margin-top:10px"><?php if (!$k['reviewed']): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="confirm_q"><input type="hidden" name="id" value="<?= $k['id'] ?>"><button class="btn btn-ok btn-sm">✓ Answer is right</button></form><?php endif; ?>
      <button class="btn btn-o btn-sm" data-open="mQ" data-fill="<?= $j ?>"><i data-lucide="pencil"></i>Edit</button>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="move"><input type="hidden" name="id" value="<?= $k['id'] ?>"><input type="hidden" name="dir" value="up"><button class="btn btn-o btn-sm" title="Move up">↑</button></form>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="move"><input type="hidden" name="id" value="<?= $k['id'] ?>"><input type="hidden" name="dir" value="down"><button class="btn btn-o btn-sm" title="Move down">↓</button></form>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="del_q"><input type="hidden" name="id" value="<?= $k['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Delete this question?"><i data-lucide="trash-2"></i></button></form></div></div>
<?php endforeach; ?></div>

<dialog class="modal" id="mQ"><form method="post" enctype="multipart/form-data" class="mwrap" id="qform"><?= csrf_field() ?><input type="hidden" name="act" value="save_q"><input type="hidden" name="id" value="0">
  <div class="mhead"><h3 id="qmTitle">Add question</h3><button class="x" type="button" data-close><i data-lucide="x"></i></button></div>
  <div class="mbody"><div class="tip">💡 <b>Easy pictures:</b> drag a picture onto any box, click it to browse, or copy a picture / screenshot and press <b>Ctrl + V</b> — it goes into the option you are typing in (or the question box).</div>
    <?php field('Question', 'question', '', ['type' => 'textarea', 'rows' => 3]); ?>
    <div class="dz" data-name="q_image" tabindex="0"><div class="dzc"><b>🖼 Question picture</b><span>drop · paste · click</span></div><div class="dzp"></div></div>
    <div class="f" style="margin-top:14px"><b>Answer options</b><span class="hint">Tick the circle of the correct one. Each option can have text, a picture, or both. Leave unused options empty.</span></div>
    <?php for ($i = 0; $i < 6; $i++): ?><div class="optrow"><input type="radio" name="correct" value="<?= $i ?>" <?= $i === 0 ? 'checked' : '' ?>><span class="ol"><?= chr(65 + $i) ?></span><input name="opt[<?= $i ?>]" placeholder="Option <?= chr(65 + $i) ?>"><div class="dz sm" data-name="opt_img" data-idx="<?= $i ?>" tabindex="0" title="Drop, paste or click to add a picture"><div class="dzc"><b>🖼</b></div><div class="dzp"></div></div></div><?php endfor; ?>
    <?php field('Explanation (optional)', 'explanation', '', ['type' => 'textarea', 'rows' => 2, 'hint' => 'Shown to students with the answer']); ?></div>
  <div class="mfoot"><button class="btn btn-o" type="button" data-close>Cancel</button><button class="btn">Save question</button></div>
</form></dialog>
<style>.qcard.review{outline:2px solid var(--orange);outline-offset:-2px}.qtext{flex:1;font-weight:600;line-height:1.5}
.qo{cursor:pointer}.qo:hover{border-color:var(--b)}.qo .oc{flex:1;min-width:0}
.qpic{display:block;max-width:100%;max-height:260px;border-radius:12px;margin-top:8px;background:#fff;border:1px solid var(--sep)}.opic{display:block;max-width:100%;max-height:150px;border-radius:10px;background:#fff;margin:2px 0 4px;border:1px solid var(--sep)}
.optrow{display:flex;align-items:center;gap:8px;margin-bottom:8px}.optrow input[type=radio]{width:20px;height:20px;margin:0}.optrow .ol{width:26px;font-weight:700;color:var(--mute)}.optrow input[name^=opt]{margin:0;flex:1;min-width:0}
.tip{background:var(--blue-t);color:var(--sub);border-radius:14px;padding:10px 14px;font-size:13px;margin-bottom:14px;line-height:1.5}
.dz{position:relative;border:2px dashed var(--sep);border-radius:16px;background:var(--field);display:grid;place-items:center;cursor:pointer;min-height:72px;transition:border-color .15s,background .15s;overflow:hidden;outline:none}
.dz:hover,.dz:focus-visible,.dz.over{border-color:var(--b);background:var(--blue-t)}.dz.paste{border-color:var(--r);box-shadow:0 0 0 4px color-mix(in srgb,var(--r) 20%,transparent)}
.dz .dzc{display:grid;gap:2px;text-align:center;color:var(--mute);font-size:12px;padding:8px}.dz .dzc b{font-size:14px;color:var(--sub)}
.dz .dzp{display:none;position:relative;width:100%}.dz.has .dzc{display:none}.dz.has .dzp{display:block}
.dz .dzp img{display:block;max-width:100%;max-height:200px;margin:0 auto;background:#fff}
.dz .dzp button{position:absolute;top:6px;right:6px;width:28px;height:28px;border-radius:50%;border:0;background:var(--red);color:#fff;cursor:pointer;font-size:13px;box-shadow:0 2px 8px rgba(0,0,0,.3)}
.dz.sm{width:64px;height:48px;min-height:48px;flex:none;border-radius:12px}.dz.sm .dzc{padding:2px}.dz.sm .dzc b{font-size:18px}.dz.sm .dzp img{max-height:44px;object-fit:contain}.dz.sm .dzp button{top:1px;right:1px;width:20px;height:20px;font-size:10px}
.aibusy{position:fixed;inset:0;z-index:99999;background:rgba(6,11,36,.78);backdrop-filter:blur(6px);display:none;place-items:center;color:#fff;text-align:center}.aibusy div{max-width:320px;padding:0 16px}.aibusy .sp{width:54px;height:54px;border-radius:50%;border:5px solid rgba(255,255,255,.2);border-top-color:#ff9a1f;margin:0 auto 14px;animation:ecpspin 1s linear infinite}
.aibusy .pbar{height:8px;border-radius:99px;background:rgba(255,255,255,.2);overflow:hidden;margin:12px 0}.aibusy .pbar i{display:block;height:100%;width:0;background:linear-gradient(90deg,#ff1f3d,#ff9a1f);transition:width .25s}</style>
<div class="aibusy" id="aibusy"><div><div class="sp"></div><b id="aiStep">Preparing…</b><div class="pbar"><i id="aiBar"></i></div><p style="opacity:.8;font-size:13px" id="aiNote">Please keep this page open.</p></div></div>
<script src="<?= asset('js/pdfjs/pdf.min.js') ?>"></script>
<script>window.QUIZ={id:<?= $qid ?>,csrf:'<?= e(csrf()) ?>',img:'<?= e(url('qimg?f=')) ?>',pdfWorker:'<?= e(asset('js/pdfjs/pdf.worker.min.js')) ?>',paperUrl:'<?= e(url('paper_dl?f=paper&id=')) ?>',papers:<?= json_encode((object)$pmeta) ?>,autoScan:<?= $scanPaper ?: 0 ?>};</script>
<script src="<?= asset('js/quiz-admin.js') ?>"></script>
<?php admin_foot();
