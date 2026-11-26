<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php'; require __DIR__ . '/../inc/quiz.php';
require_admin();
const PAPER_EXT = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
/** Create a draft quiz from a stored paper (+ its marking scheme) with Gemini, then go to the review screen. */
function paper_to_quiz(array $p): never {
    $files = [['path' => ROOT . '/uploads/papers/' . $p['paper_file'], 'name' => $p['paper_name'] ?: $p['paper_file'], 'role' => 'paper']];
    if ($p['scheme_file']) $files[] = ['path' => ROOT . '/uploads/papers/' . $p['scheme_file'], 'name' => $p['scheme_name'] ?: $p['scheme_file'], 'role' => 'scheme'];
    [$ok, $r] = quiz_ai_extract($files, '', 80);
    if (!$ok) { flash($r, 'err'); redirect('admin/papers'); }
    q("INSERT INTO quizzes(title,description,mode,audience,batch_id,status,source_paper_id) VALUES(?,?,'exam','all',NULL,'draft',?)", [$p['year'] . ' ' . $p['name'], 'Made from the ' . $p['year'] . ' past paper', $p['id']]);
    $qid = (int)db()->lastInsertId(); $n = quiz_add_extracted($qid, $r['questions']);
    flash("AI created $n questions. Check the answers marked “needs review”, then publish."); redirect('admin/quiz?id=' . $qid);
}
if (is_post()) {
    $act = post('act'); $id = (int)post('id');
    try {
        if ($act === 'save') {
            $year = (int)post('year'); if ($year < 1990 || $year > (int)date('Y') + 2) throw new RuntimeException('Enter a valid year.');
            if (post('name') === '') throw new RuntimeException('Paper name is required.');
            $pf = save_upload('paper', 'papers', PAPER_EXT, 25); $sf = save_upload('scheme', 'papers', PAPER_EXT, 25);
            if ($id) {
                $old = row('SELECT * FROM papers WHERE id=?', [$id]); if (!$old) throw new RuntimeException('Not found.');
                q('UPDATE papers SET year=?,name=?,description=?,batch_id=? WHERE id=?', [$year, post('name'), post('description') ?: null, (int)post('batch_id') ?: null, $id]);
                if ($pf) { del_upload('papers', $old['paper_file']); q('UPDATE papers SET paper_file=?,paper_name=? WHERE id=?', [$pf['name'], $pf['orig'], $id]); }
                if ($sf) { del_upload('papers', $old['scheme_file']); q('UPDATE papers SET scheme_file=?,scheme_name=? WHERE id=?', [$sf['name'], $sf['orig'], $id]); }
                flash('Paper updated.');
            } else {
                if (!$pf) throw new RuntimeException('Upload the paper file.');
                q('INSERT INTO papers(year,name,description,batch_id,paper_file,paper_name,scheme_file,scheme_name) VALUES(?,?,?,?,?,?,?,?)',
                  [$year, post('name'), post('description') ?: null, (int)post('batch_id') ?: null, $pf['name'], $pf['orig'], $sf['name'] ?? null, $sf['orig'] ?? null]);
                $nid = (int)db()->lastInsertId(); flash('Past paper added.');
                if (isset($_POST['make_quiz'])) paper_to_quiz(row('SELECT * FROM papers WHERE id=?', [$nid]));
            }
        } elseif ($act === 'delete') {
            $p = row('SELECT * FROM papers WHERE id=?', [$id]); if ($p) { del_upload('papers', $p['paper_file']); del_upload('papers', $p['scheme_file']); q('DELETE FROM papers WHERE id=?', [$id]); flash('Paper deleted.'); }
        } elseif ($act === 'remove_scheme') {
            $p = row('SELECT * FROM papers WHERE id=?', [$id]); if ($p) { del_upload('papers', $p['scheme_file']); q('UPDATE papers SET scheme_file=NULL,scheme_name=NULL WHERE id=?', [$id]); flash('Marking scheme removed.'); }
        } elseif ($act === 'make_quiz') { $p = row('SELECT * FROM papers WHERE id=?', [$id]); if ($p) paper_to_quiz($p); }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('admin/papers');
}
admin_head('Past papers', 'papers');
$batches = rows('SELECT id,name FROM batches ORDER BY sort_order,id'); $bo = ['' => 'All batches'] + array_column($batches, 'name', 'id');
$list = rows('SELECT p.*,b.name bname,(SELECT COUNT(*) FROM quizzes q WHERE q.source_paper_id=p.id) qn FROM papers p LEFT JOIN batches b ON b.id=p.batch_id ORDER BY p.year DESC,p.id DESC');
$years = []; foreach ($list as $p) $years[$p['year']][] = $p;
$ai = bot_enabled() || dec(setting('gemini_key')) !== '';
?>
<div class="toolbar"><span class="muted"><?= count($list) ?> papers · students can download the paper and the marking scheme</span><button class="btn" data-open="mPaper" data-fill='null'><i data-lucide="plus"></i>Add past paper</button></div>
<?php if (!$list): ?><div class="card empty">No past papers yet. Tap “Add past paper”.</div><?php endif; ?>
<?php foreach ($years as $y => $items): ?>
<div class="sec-t"><?= (int)$y ?> <small><?= count($items) ?> paper<?= count($items) > 1 ? 's' : '' ?></small></div>
<div class="tile-list">
<?php foreach ($items as $p): $j = e(json_encode($p, JSON_UNESCAPED_UNICODE)); ?>
  <div class="litem"><span class="ic yt"><i data-lucide="file-text"></i></span>
    <div class="grow"><b><?= e($p['name']) ?></b><span class="small muted"><?= $p['bname'] ? e($p['bname']) : 'All batches' ?><?= $p['description'] ? ' · ' . e($p['description']) : '' ?></span>
      <span class="small"><a href="<?= url('paper_dl?id=' . $p['id'] . '&f=paper') ?>" target="_blank" class="pill blue">📄 Paper</a>
      <?php if ($p['scheme_file']): ?><a href="<?= url('paper_dl?id=' . $p['id'] . '&f=scheme') ?>" target="_blank" class="pill ok">✅ Marking scheme</a><?php else: ?><span class="pill">No marking scheme</span><?php endif; ?>
      <?php if ($p['qn']): ?><span class="pill warn"><?= (int)$p['qn'] ?> MCQ quiz</span><?php endif; ?></span></div>
    <div class="acts">
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="make_quiz"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-sm" data-confirm="Scan this paper with Gemini and create an MCQ quiz? This can take up to a minute." <?= $ai ? '' : 'disabled title="Add a Gemini key in Settings → AI Chatbot"' ?>><i data-lucide="sparkles"></i>Make MCQ with AI</button></form>
      <button class="btn btn-o btn-sm" data-open="mPaper" data-fill="<?= $j ?>"><i data-lucide="pencil"></i></button>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Delete this paper and its files?"><i data-lucide="trash-2"></i></button></form></div></div>
<?php endforeach; ?></div>
<?php endforeach; ?>

<dialog class="modal" id="mPaper"><form method="post" enctype="multipart/form-data" class="mwrap" id="pf"><?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="0">
  <div class="mhead"><h3 id="pTitle">Add past paper</h3><button class="x" type="button" data-close><i data-lucide="x"></i></button></div>
  <div class="mbody">
    <div class="two"><?php field('Year', 'year', date('Y'), ['type' => 'number', 'req' => 1, 'attr' => 'min="1990" max="' . (date('Y') + 2) . '"']); field('Batch (optional)', 'batch_id', '', ['type' => 'select', 'options' => $bo]); ?></div>
    <?php field('Paper name', 'name', '', ['req' => 1, 'attr' => 'placeholder="A/L Chemistry Paper I (MCQ)"']); field('Short note (optional)', 'description', ''); ?>
    <label class="f">Paper file <i class="rq" id="pReq">*</i><span class="hint">PDF, Word or image · max 25MB</span><input type="file" name="paper" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"></label>
    <label class="f">Marking scheme (optional)<span class="hint" id="pSchHint">PDF, Word or image · students can download it. Gemini uses it for the correct answers.</span><input type="file" name="scheme" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"></label>
    <label class="chk" id="pMake"><input type="checkbox" name="make_quiz" <?= $ai ? '' : 'disabled' ?>>✨ Also scan with Gemini and create an MCQ quiz now</label>
  </div>
  <div class="mfoot"><button class="btn btn-o" type="button" data-close>Cancel</button><button class="btn">Save paper</button></div>
</form></dialog>
<script>
(function(){var d=document.getElementById('mPaper'),f=document.getElementById('pf');
d.addEventListener('fill',function(e){var p=e.detail;f.reset();f.elements.id.value=p?p.id:0;document.getElementById('pTitle').textContent=p?'Edit past paper':'Add past paper';
 document.getElementById('pMake').style.display=p?'none':'flex';document.getElementById('pReq').style.display=p?'none':'inline';
 if(p){['year','name','description'].forEach(function(k){f.elements[k].value=p[k]||''});f.elements.batch_id.value=p.batch_id||'';document.getElementById('pSchHint').textContent=(p.scheme_file?'A marking scheme is stored – choose a file to replace it.':'PDF, Word or image.');}
 else f.elements.year.value=new Date().getFullYear();});})();
</script>
<?php admin_foot();
