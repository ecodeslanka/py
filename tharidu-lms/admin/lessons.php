<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php';
admin_head('Lessons', 'lessons');
$batches = rows('SELECT id,name FROM batches ORDER BY sort_order,id');
$grades = rows('SELECT id,name FROM grades ORDER BY name');
$classes = rows('SELECT id,name FROM classes ORDER BY sort_order,id');
if (is_post()) {
    $act = post('act'); $id = (int)post('id');
    try {
        if ($act === 'save') {
            $my = post('month_year'); $err = null;
            if (post('name') === '') $err = 'Lesson name is required.';
            elseif (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $my)) $err = 'Choose the month and year.';
            elseif (!val('SELECT 1 FROM batches WHERE id=?', [(int)post('batch_id')])) $err = 'Select a batch.';
            $type = in_array(post('class_type'), ['physical', 'online']) ? post('class_type') : 'online';
            if (!$err && $type === 'physical' && !(int)post('class_id')) $err = 'Select the class (place) for a physical lesson.';
            if ($err) throw new RuntimeException($err);
            $d = [post('name'), (float)post('fee'), (int)post('batch_id'), (int)post('grade_id') ?: null, $type, $type === 'physical' ? (int)post('class_id') : null,
                $my, post('due_date') ?: null, post('description') ?: null, max(0, (int)post('student_limit')), isset($_POST['active']) ? 1 : 0];
            if ($id) { q('UPDATE lessons SET name=?,fee=?,batch_id=?,grade_id=?,class_type=?,class_id=?,month_year=?,due_date=?,description=?,student_limit=?,active=? WHERE id=?', [...$d, $id]); flash('Lesson updated.'); }
            else { q('INSERT INTO lessons(name,fee,batch_id,grade_id,class_type,class_id,month_year,due_date,description,student_limit,active) VALUES(?,?,?,?,?,?,?,?,?,?,?)', $d); $id = (int)db()->lastInsertId(); flash('Lesson created. Add materials, students and live sessions here.'); redirect('admin/lesson?id=' . $id); }
        } elseif ($act === 'delete') {
            foreach (rows("SELECT file_path FROM materials WHERE lesson_id=? AND type='document'", [$id]) as $m) del_upload('docs', $m['file_path']);
            q('DELETE FROM lessons WHERE id=?', [$id]); flash('Lesson deleted.');
        }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('admin/lessons');
}
$bo = array_column($batches, 'name', 'id'); $go = ['' => '— none —'] + array_column($grades, 'name', 'id'); $co = ['' => '— select class —'] + array_column($classes, 'name', 'id');
$list = rows('SELECT l.*,b.name bname,g.name gname,c.name cname,(SELECT COUNT(*) FROM enrollments e WHERE e.lesson_id=l.id) cnt FROM lessons l JOIN batches b ON b.id=l.batch_id LEFT JOIN grades g ON g.id=l.grade_id LEFT JOIN classes c ON c.id=l.class_id ORDER BY l.month_year DESC,l.id DESC');
$gr = ['g1', 'g2', 'g3', 'g4'];
?>
<div class="toolbar"><span class="muted"><?= count($list) ?> lessons</span><button class="btn" data-open="mForm"><i data-lucide="plus"></i>New lesson</button></div>
<?php if (!$batches): ?><div class="alert err">Create a batch first.</div><?php endif; ?>
<div class="boxes">
<?php foreach ($list as $i => $l): $j = e(json_encode($l, JSON_UNESCAPED_UNICODE)); ?>
  <div class="gcard <?= $gr[$i % 4] ?>" role="button" tabindex="0" data-open="mView" data-fill="<?= $j ?>" style="cursor:pointer">
    <div class="row"><span class="gi"><i data-lucide="<?= $l['class_type'] === 'online' ? 'monitor-play' : 'book-open' ?>"></i></span><h3><?= e($l['name']) ?></h3></div>
    <div class="meta"><span><?= e($l['bname']) ?></span><span><?= e(month_label($l['month_year'])) ?></span><span><?= $l['class_type'] === 'online' ? 'Online' : e($l['cname'] ?: 'Physical') ?></span><?php if (!$l['active']): ?><span>Hidden</span><?php endif; ?></div>
    <div class="gfoot"><div><div class="fee"><?= e(money($l['fee'])) ?></div><div class="sub"><?= (int)$l['cnt'] ?><?= $l['student_limit'] ? ' / ' . (int)$l['student_limit'] : '' ?> students</div></div><span class="gbtn">Open <i data-lucide="chevron-right"></i></span></div>
  </div>
<?php endforeach; if (!$list) echo '<p class="empty">No lessons yet. Tap “New lesson”.</p>'; ?>
</div>

<!-- VIEW modal -->
<dialog class="modal" id="mView"><div class="mwrap">
  <div class="mhead b"><h3 id="vName">Lesson</h3><button class="x" data-close aria-label="Close"><i data-lucide="x"></i></button></div>
  <div class="mbody">
    <div class="kv"><div><small>Fee</small><b id="vFee"></b></div><div><small>Month</small><b id="vMonth"></b></div><div><small>Batch</small><b id="vBatch"></b></div>
      <div><small>Where</small><b id="vWhere"></b></div><div><small>Grade</small><b id="vGrade"></b></div><div><small>Students</small><b id="vStu"></b></div><div><small>Pay by</small><b id="vDue"></b></div><div><small>Status</small><b id="vAct"></b></div></div>
    <div class="prose" id="vDesc"></div>
    <div class="hub" style="margin-top:18px">
      <a class="hubtile r" id="vMat" style="min-height:110px;padding:16px"><span class="hi" style="width:40px;height:40px;font-size:20px"><i data-lucide="library"></i></span><b style="font-size:17px">Materials</b></a>
      <a class="hubtile b" id="vStud" style="min-height:110px;padding:16px"><span class="hi" style="width:40px;height:40px;font-size:20px"><i data-lucide="users"></i></span><b style="font-size:17px">Students</b></a>
      <a class="hubtile g" id="vLive" style="min-height:110px;padding:16px"><span class="hi" style="width:40px;height:40px;font-size:20px"><i data-lucide="video"></i></span><b style="font-size:17px">Zoom &amp; live</b></a>
    </div>
  </div>
  <div class="mfoot"><form method="post" id="vDel" style="margin-right:auto"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" id="vDelId"><button class="btn btn-danger" data-confirm="Delete this lesson with all materials, sessions and enrolments?"><i data-lucide="trash-2"></i>Delete</button></form>
    <button class="btn btn-o" id="vEdit" type="button"><i data-lucide="pencil"></i>Edit</button></div>
</div></dialog>

<!-- CREATE / EDIT modal -->
<dialog class="modal" id="mForm"><form method="post" class="mwrap" id="lf"><?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="0">
  <div class="mhead"><h3 id="fTitle">New lesson</h3><button class="x" type="button" data-close aria-label="Close"><i data-lucide="x"></i></button></div>
  <div class="mbody">
    <?php field('Lesson name', 'name', '', ['req' => 1, 'attr' => 'placeholder="December Theory"']); ?>
    <div class="two"><?php field('Fee (Rs.)', 'fee', '', ['req' => 1, 'type' => 'number', 'attr' => 'min="0" step="0.01"']); field('Month & year', 'month_year', date('Y-m'), ['type' => 'month', 'req' => 1]); ?></div>
    <div class="two"><?php field('Batch', 'batch_id', '', ['type' => 'select', 'options' => $bo, 'req' => 1]); field('Grade', 'grade_id', '', ['type' => 'select', 'options' => $go, 'hint' => 'Manage under Grades']); ?></div>
    <div class="two"><?php field('Lesson type', 'class_type', 'physical', ['type' => 'select', 'options' => ['physical' => 'Physical (at a class)', 'online' => 'Online'], 'attr' => 'data-toggle="lt"']); ?>
      <div data-show-for="lt:physical"><?php field('Class (place)', 'class_id', '', ['type' => 'select', 'options' => $co, 'hint' => 'Create classes under Classes']); ?></div></div>
    <div class="two"><?php field('Payment due date', 'due_date', '', ['type' => 'date', 'hint' => 'Optional – monthly pay-by date']); field('Student limit', 'student_limit', 0, ['type' => 'number', 'attr' => 'min="0"', 'hint' => '0 = unlimited']); ?></div>
    <?php field('Description', 'description', '', ['type' => 'textarea']); ?>
    <label class="chk"><input type="checkbox" name="active" checked>Active (students can join)</label>
  </div>
  <div class="mfoot"><button class="btn btn-o" type="button" data-close>Cancel</button><button class="btn">Save lesson</button></div>
</form></dialog>
<script>
(function(){
  var f=document.getElementById('lf'),mf=document.getElementById('mForm'),mv=document.getElementById('mView'),cur=null;
  var $=function(i){return document.getElementById(i)},money=function(n){return 'Rs. '+Number(n).toLocaleString('en-US',{minimumFractionDigits:2})};
  var mnames=['January','February','March','April','May','June','July','August','September','October','November','December'];
  function fill(d){
    f.reset(); f.elements.id.value=d?d.id:0; $('fTitle').textContent=d?'Edit lesson':'New lesson';
    if(d){['name','fee','month_year','batch_id','grade_id','class_type','class_id','due_date','student_limit','description'].forEach(function(k){if(f.elements[k])f.elements[k].value=d[k]==null?'':d[k]});
      f.elements.active.checked=d.active==1;}
    else{f.elements.month_year.value=new Date().toISOString().slice(0,7);f.elements.student_limit.value=0;f.elements.active.checked=true}
    f.elements.class_type.dispatchEvent(new Event('change'));
  }
  mf.addEventListener('fill',function(e){fill(e.detail)});
  mv.addEventListener('fill',function(e){var d=e.detail;cur=d;if(!d)return;
    $('vName').textContent=d.name;$('vFee').textContent=money(d.fee);var p=d.month_year.split('-');$('vMonth').textContent=mnames[+p[1]-1]+' '+p[0];
    $('vBatch').textContent=d.bname;$('vWhere').textContent=d.class_type=='online'?'Online':(d.cname||'Physical');$('vGrade').textContent=d.gname||'—';
    $('vStu').textContent=d.cnt+(d.student_limit>0?' / '+d.student_limit:'');$('vDue').textContent=d.due_date||'—';$('vAct').textContent=d.active==1?'Active':'Hidden';
    $('vDesc').textContent=d.description||'';$('vDelId').value=d.id;
    $('vMat').href='<?= url('admin/materials') ?>?lesson='+d.id;$('vStud').href='<?= url('admin/enrolled') ?>?lesson='+d.id;$('vLive').href='<?= url('admin/sessions') ?>?lesson='+d.id;});
  $('vEdit').addEventListener('click',function(){mv.close();fill(cur);mf.showModal();});
})();
</script>
<?php admin_foot();
