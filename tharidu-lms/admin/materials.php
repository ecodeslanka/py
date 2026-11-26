<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php'; require __DIR__ . '/../inc/player.php';
require_admin();
$l = lesson_ctx(); $lid = (int)$l['id'];
$tab = $_GET['tab'] ?? ''; $self = 'admin/materials?lesson=' . $lid . ($tab ? '&tab=' . urlencode($tab) : '');
if (is_post()) {
    $act = post('act');
    if ($act === 'reorder') {                                        // AJAX from drag & drop
        header('Content-Type: application/json'); $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
        if (!is_array($ids)) exit('{"ok":false}');
        $n = 1; foreach ($ids as $id) { q('UPDATE materials SET sort_order=? WHERE id=? AND lesson_id=?', [$n++, (int)$id, $lid]); }
        exit('{"ok":true}');
    }
    try {
        if ($act === 'mat_del') { $m = row('SELECT * FROM materials WHERE id=? AND lesson_id=?', [(int)post('id'), $lid]); if ($m) { del_upload('docs', $m['file_path']); q('DELETE FROM materials WHERE id=?', [$m['id']]); flash('Material deleted.'); } }
        if ($act === 'mat_resetviews') { q('DELETE FROM material_views WHERE material_id=?', [(int)post('id')]); flash('Views reset for all students.'); }
        if ($act === 'mat_update') {
            $m = row('SELECT * FROM materials WHERE id=? AND lesson_id=?', [(int)post('id'), $lid]); if (!$m) throw new RuntimeException('Not found.');
            if (post('title') === '') throw new RuntimeException('Title is required.');
            $enc = $m['url_enc']; $link = post('link');
            if ($link !== '') {
                if ($m['type'] === 'youtube') { $y = youtube_id($link); if (!$y) throw new RuntimeException('Invalid YouTube link.'); $enc = enc($y); }
                elseif (in_array($m['type'], ['gdrive', 'assessment', 'zoom_rec'], true)) { if (!preg_match('~^https://~i', $link)) throw new RuntimeException('Invalid link.'); $enc = enc($link); }
            }
            $fp = $m['file_path']; $fn = $m['file_name'];
            if (in_array($m['type'], ['document', 'notice'], true) && ($f = save_upload('doc', 'docs', $m['type'] === 'notice' ? ['jpg', 'jpeg', 'png', 'webp', 'gif'] : ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'zip'], 25))) { del_upload('docs', $fp); $fp = $f['name']; $fn = $f['orig']; }
            q('UPDATE materials SET title=?,description=?,url_enc=?,file_path=?,file_name=?,group_name=?,view_limit=?,passcode=? WHERE id=?',
              [post('title'), post('description') ?: null, $enc, $fp, $fn, post('group_name') ?: null, $m['type'] === 'youtube' ? max(0, (int)post('view_limit')) : 0, $m['type'] === 'zoom_rec' ? (post('passcode') ?: null) : null, $m['id']]);
            flash('Material updated.');
        }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect($self);
}
admin_head('Learning materials', 'lessons');
lesson_banner($l, 'Materials');
$all = rows('SELECT m.*,(SELECT COALESCE(SUM(views),0) FROM material_views v WHERE v.material_id=m.id) tv FROM materials m WHERE lesson_id=? ORDER BY sort_order,id', [$lid]);
$by = []; foreach ($all as $m) $by[$m['type']][] = $m;
if (!isset($by[$tab])) $tab = array_key_first($by) ?? '';
?>
<div class="toolbar"><span class="muted"><?= count($all) ?> materials · drag <i data-lucide="grip-vertical" style="width:1em"></i> to reorder</span><a class="btn" href="<?= url('admin/material-add?lesson=' . $lid) ?>"><i data-lucide="plus"></i>Add material</a></div>
<?php if (!$all): ?><div class="card empty">No materials yet. Tap “Add material”.</div><?php else: ?>
<div class="apptabs">
<?php foreach ($by as $t => $items): ?><a class="<?= $tab === $t ? 'on' : '' ?>" href="?lesson=<?= $lid ?>&tab=<?= $t ?>"><?= e(MATERIAL_TYPES[$t][0] ?? $t) ?> <span class="pill err" style="margin-left:4px"><?= count($items) ?></span></a><?php endforeach; ?>
</div>
<div class="tablewrap"><table><thead><tr><th style="width:44px"></th><th>Title</th><th>Group</th><?php if ($tab === 'youtube'): ?><th>Views</th><?php endif; ?><th style="text-align:right">Actions</th></tr></thead>
<tbody id="sortable">
<?php foreach ($by[$tab] as $m): $j = e(json_encode(['id' => $m['id'], 'title' => $m['title'], 'type' => $m['type'], 'description' => $m['description'], 'group_name' => $m['group_name'], 'view_limit' => $m['view_limit'], 'passcode' => $m['passcode']], JSON_UNESCAPED_UNICODE)); ?>
<tr data-id="<?= $m['id'] ?>"><td><span class="drag" title="Drag to reorder"><i data-lucide="grip-vertical"></i></span></td>
<td><b><?= e($m['title']) ?></b><?php if ($m['description']): ?><div class="small muted"><?= e(mb_strimwidth($m['description'], 0, 90, '…')) ?></div><?php endif; ?><?php if ($m['file_name']): ?><div class="small muted"><?= e($m['file_name']) ?></div><?php endif; ?></td>
<td><?= $m['group_name'] ? '<span class="pill blue">' . e($m['group_name']) . '</span>' : '<span class="muted">—</span>' ?></td>
<?php if ($tab === 'youtube'): ?><td><?= $m['view_limit'] ? (int)$m['view_limit'] . '/student' : '∞' ?> <span class="muted small">· <?= (int)$m['tv'] ?> total</span></td><?php endif; ?>
<td><div class="acts" style="justify-content:flex-end">
  <?php if ($m['type'] === 'youtube'): ?><a class="btn btn-sm" href="<?= url('watch?id=' . $m['id']) ?>" target="_blank" title="Preview in ECODES PLAYER"><i data-lucide="play"></i></a>
  <?php elseif (in_array($m['type'], ['document', 'notice'], true)): ?><a class="btn btn-o btn-sm" href="<?= url('download?id=' . $m['id']) ?>" target="_blank"><i data-lucide="eye"></i></a>
  <?php else: ?><a class="btn btn-o btn-sm" href="<?= url('go?id=' . $m['id']) ?>" target="_blank"><i data-lucide="external-link"></i></a><?php endif; ?>
  <button class="btn btn-o btn-sm" data-open="mEdit" data-fill="<?= $j ?>" title="Edit"><i data-lucide="pencil"></i></button>
  <?php if ($m['type'] === 'youtube' && $m['view_limit']): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="mat_resetviews"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="btn btn-o btn-sm" data-confirm="Reset view counts for all students?" title="Reset views"><i data-lucide="rotate-ccw"></i></button></form><?php endif; ?>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="mat_del"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Delete this material?" title="Delete"><i data-lucide="trash-2"></i></button></form>
</div></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>

<dialog class="modal" id="mEdit"><form method="post" enctype="multipart/form-data" class="mwrap" id="ef"><?= csrf_field() ?><input type="hidden" name="act" value="mat_update"><input type="hidden" name="id" value="0">
  <div class="mhead b"><h3>Edit material <span class="pill" id="eid" style="background:rgba(255,255,255,.25);color:#fff"></span></h3><button class="x" type="button" data-close><i data-lucide="x"></i></button></div>
  <div class="mbody">
    <?php field('Title', 'title', '', ['req' => 1]); ?>
    <div id="eLink"><?php field('New link / video code', 'link', '', ['hint' => 'Leave empty to keep the current one. Stored encrypted.']); ?></div>
    <?php field('Description', 'description', '', ['type' => 'textarea', 'rows' => 3]); ?>
    <div class="two"><?php field('Group name', 'group_name', ''); ?><div id="eView"><?php field('View count per student', 'view_limit', '', ['type' => 'number', 'attr' => 'min="0"', 'hint' => '0 = unlimited']); ?></div></div>
    <div id="ePass"><?php field('Passcode (optional)', 'passcode', ''); ?></div>
    <div id="eFile"><label class="f">Replace file<span class="hint">Optional</span><input type="file" name="doc"></label></div>
  </div>
  <div class="mfoot"><button class="btn btn-o" type="button" data-close>Cancel</button><button class="btn">Save changes</button></div>
</form></dialog>
<style>.drag{display:inline-grid;place-items:center;width:30px;height:30px;border-radius:9px;color:var(--mute);cursor:grab;touch-action:none}.drag:hover{background:var(--fill)}
#sortable tr.ghost td{background:var(--tint-b,#eaf1ff)!important;outline:2px dashed var(--b)}</style>
<script src="<?= asset('js/Sortable.min.js') ?>"></script>
<script>
(function(){
  var ef=document.getElementById('ef'),dlg=document.getElementById('mEdit'),$=function(i){return document.getElementById(i)};
  dlg.addEventListener('fill',function(e){var d=e.detail;if(!d)return;ef.reset();
    ['id','title','description','group_name','view_limit','passcode'].forEach(function(k){if(ef.elements[k])ef.elements[k].value=d[k]==null?'':d[k]});
    $('eid').textContent='#'+d.id;var t=d.type;
    $('eLink').hidden=['youtube','gdrive','assessment','zoom_rec'].indexOf(t)<0;$('eView').hidden=t!=='youtube';$('ePass').hidden=t!=='zoom_rec';$('eFile').hidden=['document','notice'].indexOf(t)<0;});
  var tb=document.getElementById('sortable');
  if(tb&&window.Sortable){Sortable.create(tb,{handle:'.drag',animation:160,ghostClass:'ghost',forceFallback:false,onEnd:function(){
    var ids=[].map.call(tb.querySelectorAll('tr[data-id]'),function(r){return r.dataset.id});var fd=new FormData();
    fd.append('act','reorder');fd.append('ids',JSON.stringify(ids));fd.append('_csrf','<?= e(csrf()) ?>');
    fetch(location.href,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).catch(function(){alert('Could not save the order.')});}});}
})();
</script>
<?php admin_foot();
