<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php';
admin_head('Lessons', 'lessons');
$batches = rows('SELECT id,name FROM batches ORDER BY sort_order,id');
$grades = rows('SELECT id,name FROM grades ORDER BY name');
$classes = rows('SELECT id,name FROM classes WHERE active=1 ORDER BY sort_order,id');
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
            else { q('INSERT INTO lessons(name,fee,batch_id,grade_id,class_type,class_id,month_year,due_date,description,student_limit,active) VALUES(?,?,?,?,?,?,?,?,?,?,?)', $d); $id = (int)db()->lastInsertId(); flash('Lesson created. Add materials, students and live sessions below.'); redirect('admin/lesson?id=' . $id); }
        } elseif ($act === 'delete') {
            foreach (rows("SELECT file_path FROM materials WHERE lesson_id=? AND type='document'", [$id]) as $m) del_upload('docs', $m['file_path']);
            q('DELETE FROM lessons WHERE id=?', [$id]); flash('Lesson deleted.');
        }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); redirect('admin/lessons' . ($id ? '?edit=' . $id : '')); }
    redirect('admin/lessons');
}
$edit = isset($_GET['edit']) ? row('SELECT * FROM lessons WHERE id=?', [(int)$_GET['edit']]) : null;
$bo = array_column($batches, 'name', 'id'); $go = ['' => '— none —'] + array_column($grades, 'name', 'id'); $co = ['' => '— select class —'] + array_column($classes, 'name', 'id');
$list = rows('SELECT l.*,b.name bname,g.name gname,c.name cname,(SELECT COUNT(*) FROM enrollments e WHERE e.lesson_id=l.id) cnt FROM lessons l JOIN batches b ON b.id=l.batch_id LEFT JOIN grades g ON g.id=l.grade_id LEFT JOIN classes c ON c.id=l.class_id ORDER BY l.month_year DESC,l.id DESC');
$icons = ['', 'o', 'g', 'p'];
?>
<?php if (!$batches): ?><div class="alert err">Create a batch first.</div><?php endif; ?>
<details class="card sect" <?= $edit || !$list ? 'open' : '' ?>><summary><?= $edit ? 'Edit lesson' : 'Create lesson' ?></summary><div class="sbody"><br>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<div class="two"><?php field('Lesson name', 'name', $edit['name'] ?? '', ['req' => 1, 'attr' => 'placeholder="December Theory"']); field('Fee (Rs.)', 'fee', $edit['fee'] ?? '', ['req' => 1, 'type' => 'number', 'attr' => 'min="0" step="0.01"']); ?></div>
<div class="three"><?php field('Batch', 'batch_id', $edit['batch_id'] ?? '', ['type' => 'select', 'options' => $bo, 'req' => 1]);
field('Grade', 'grade_id', $edit['grade_id'] ?? '', ['type' => 'select', 'options' => $go, 'hint' => 'Manage under Grades']);
field('Month & year', 'month_year', $edit['month_year'] ?? date('Y-m'), ['type' => 'month', 'req' => 1]); ?></div>
<div class="three"><?php field('Lesson type', 'class_type', $edit['class_type'] ?? 'physical', ['type' => 'select', 'options' => ['physical' => 'Physical (at a class)', 'online' => 'Online'], 'attr' => 'data-toggle="lt"']); ?>
<div data-show-for="lt:physical"><?php field('Class (place)', 'class_id', $edit['class_id'] ?? '', ['type' => 'select', 'options' => $co, 'hint' => 'Create classes under Classes']); ?></div>
<?php field('Payment due date', 'due_date', $edit['due_date'] ?? '', ['type' => 'date', 'hint' => 'Optional – monthly pay-by date']); ?></div>
<?php field('Student limit', 'student_limit', $edit['student_limit'] ?? 0, ['type' => 'number', 'attr' => 'min="0"', 'hint' => '0 = unlimited']); field('Description', 'description', $edit['description'] ?? '', ['type' => 'textarea']); ?>
<label class="chk"><input type="checkbox" name="active" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>>Active (students can join)</label>
<button class="btn">Save lesson</button> <?php if ($edit): ?><a class="btn btn-o" href="<?= url('admin/lessons') ?>">Cancel</a><?php endif; ?></form></div></details><br>
<div class="boxes">
<?php foreach ($list as $i => $l): ?>
  <div class="lcard"><div class="top"><span class="ico <?= $icons[$i % 4] ?>"><i data-lucide="<?= $l['class_type'] === 'online' ? 'monitor-play' : 'book-open' ?>"></i></span><h3><?= e($l['name']) ?></h3></div>
    <div class="meta"><span class="pill blue"><?= e($l['bname']) ?></span><span class="pill"><?= e(month_label($l['month_year'])) ?></span><span class="pill"><?= $l['class_type'] === 'online' ? 'Online' : e($l['cname'] ?: 'Physical') ?></span><?php if ($l['gname']): ?><span class="pill"><?= e($l['gname']) ?></span><?php endif; ?><?php if (!$l['active']): ?><span class="pill err">Hidden</span><?php endif; ?></div>
    <div class="fee"><?= e(money($l['fee'])) ?></div>
    <div class="small muted"><?= (int)$l['cnt'] ?><?= $l['student_limit'] ? ' / ' . (int)$l['student_limit'] : '' ?> students<?= $l['due_date'] ? ' · due ' . e(date('d M', strtotime($l['due_date']))) : '' ?></div>
    <div class="foot"><a class="btn btn-sm" href="<?= url('admin/lesson?id=' . $l['id']) ?>"><i data-lucide="settings-2"></i>Manage</a><a class="btn btn-o btn-sm" href="?edit=<?= $l['id'] ?>">Edit</a>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $l['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Delete this lesson with all materials, sessions and enrolments?">Delete</button></form></div></div>
<?php endforeach; if (!$list) echo '<p class="empty">No lessons yet.</p>'; ?>
</div>
<?php admin_foot();
