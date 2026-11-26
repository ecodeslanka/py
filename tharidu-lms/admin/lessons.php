<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php';
admin_head('Classes', 'lessons');
$batches = rows('SELECT id,name FROM batches ORDER BY sort_order,id');
$grades = rows('SELECT id,name FROM grades ORDER BY name');
if (is_post()) {
    $act = post('act'); $id = (int)post('id');
    try {
        if ($act === 'save') {
            $my = post('month_year'); $err = null;
            if (post('name') === '') $err = 'Class name is required.';
            elseif (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $my)) $err = 'Choose the month and year.';
            elseif (!val('SELECT 1 FROM batches WHERE id=?', [(int)post('batch_id')])) $err = 'Select a batch.';
            if ($err) throw new RuntimeException($err);
            $d = [post('name'), (float)post('fee'), (int)post('batch_id'), (int)post('grade_id') ?: null, in_array(post('class_type'), ['physical', 'online']) ? post('class_type') : 'online',
                post('venue') ?: null, $my, post('due_date') ?: null, post('description') ?: null, max(0, (int)post('student_limit')), isset($_POST['active']) ? 1 : 0];
            if ($id) { q('UPDATE lessons SET name=?,fee=?,batch_id=?,grade_id=?,class_type=?,venue=?,month_year=?,due_date=?,description=?,student_limit=?,active=? WHERE id=?', [...$d, $id]); flash('Class updated.'); }
            else { q('INSERT INTO lessons(name,fee,batch_id,grade_id,class_type,venue,month_year,due_date,description,student_limit,active) VALUES(?,?,?,?,?,?,?,?,?,?,?)', $d); $id = (int)db()->lastInsertId(); flash('Class created. Add materials, students and live sessions from the class page.'); redirect('admin/lesson?id=' . $id); }
        } elseif ($act === 'delete') {
            foreach (rows("SELECT file_path FROM materials WHERE lesson_id=? AND type='document'", [$id]) as $m) del_upload('docs', $m['file_path']);
            q('DELETE FROM lessons WHERE id=?', [$id]); flash('Class deleted.');
        }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); redirect('admin/lessons' . ($id ? '?edit=' . $id : '')); }
    redirect('admin/lessons');
}
$edit = isset($_GET['edit']) ? row('SELECT * FROM lessons WHERE id=?', [(int)$_GET['edit']]) : null;
$bo = array_column($batches, 'name', 'id'); $go = ['' => '— none —'] + array_column($grades, 'name', 'id');
$list = rows('SELECT l.*,b.name bname,g.name gname,(SELECT COUNT(*) FROM enrollments e WHERE e.lesson_id=l.id) cnt FROM lessons l JOIN batches b ON b.id=l.batch_id LEFT JOIN grades g ON g.id=l.grade_id ORDER BY l.month_year DESC,l.id DESC');
?>
<?php if (!$batches): ?><div class="alert err">Create a batch first.</div><?php endif; ?>
<div class="card"><h3><i data-lucide="plus-circle"></i><?= $edit ? 'Edit class' : 'Create class' ?></h3>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<div class="two"><?php field('Class name', 'name', $edit['name'] ?? '', ['req' => 1, 'attr' => 'placeholder="Senara – Kegalle (Theory)"']); field('Fee (Rs.)', 'fee', $edit['fee'] ?? '', ['req' => 1, 'type' => 'number', 'attr' => 'min="0" step="0.01"']); ?></div>
<div class="three"><?php field('Batch', 'batch_id', $edit['batch_id'] ?? '', ['type' => 'select', 'options' => $bo, 'req' => 1]);
field('Grade', 'grade_id', $edit['grade_id'] ?? '', ['type' => 'select', 'options' => $go, 'hint' => 'Manage under Grades']);
field('Month & year', 'month_year', $edit['month_year'] ?? date('Y-m'), ['type' => 'month', 'req' => 1]); ?></div>
<div class="three"><?php field('Class type', 'class_type', $edit['class_type'] ?? 'physical', ['type' => 'select', 'options' => ['physical' => 'Physical', 'online' => 'Online'], 'attr' => 'data-toggle="lt"']); ?>
<div data-show-for="lt:physical"><?php field('Venue / location', 'venue', $edit['venue'] ?? '', ['attr' => 'placeholder="Senara – Kegalle"']); ?></div>
<?php field('Payment due date', 'due_date', $edit['due_date'] ?? '', ['type' => 'date', 'hint' => 'Optional – monthly pay-by date']); ?></div>
<?php field('Student limit', 'student_limit', $edit['student_limit'] ?? 0, ['type' => 'number', 'attr' => 'min="0"', 'hint' => '0 = unlimited']); field('Description', 'description', $edit['description'] ?? '', ['type' => 'textarea']); ?>
<label class="chk"><input type="checkbox" name="active" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>>Active (students can join)</label>
<button class="btn btn-r">Save class</button> <?php if ($edit): ?><a class="btn btn-o" href="<?= url('admin/lessons') ?>">Cancel</a><?php endif; ?></form></div><br>
<div class="tablewrap"><table><tr><th>Class</th><th>Batch</th><th>Month</th><th>Fee</th><th>Students</th><th>Status</th><th></th></tr>
<?php foreach ($list as $l): ?><tr><td><b><?= e($l['name']) ?></b><div class="small muted"><?= $l['class_type'] === 'online' ? 'Online' : 'Physical' . ($l['venue'] ? ' · ' . e($l['venue']) : '') ?><?= $l['gname'] ? ' · ' . e($l['gname']) : '' ?></div></td>
<td><?= e($l['bname']) ?></td><td><?= e(month_label($l['month_year'])) ?></td><td><?= e(money($l['fee'])) ?></td>
<td><?= (int)$l['cnt'] ?><?= $l['student_limit'] ? ' / ' . (int)$l['student_limit'] : '' ?></td><td><span class="pill <?= $l['active'] ? 'ok' : '' ?>"><?= $l['active'] ? 'Active' : 'Hidden' ?></span></td>
<td><div class="acts"><a class="btn btn-b btn-sm" href="<?= url('admin/lesson?id=' . $l['id']) ?>">Manage</a><a class="btn btn-o btn-sm" href="?edit=<?= $l['id'] ?>">Edit</a>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $l['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Delete this class with all materials, sessions and enrolments?">Delete</button></form></div></td></tr>
<?php endforeach; if (!$list) echo '<tr><td colspan="7" class="empty">No classes yet.</td></tr>'; ?></table></div>
<?php admin_foot();
