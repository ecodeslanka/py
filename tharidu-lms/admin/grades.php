<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php';
admin_head('Grades', 'grades');
if (is_post()) {
    $act = post('act'); $id = (int)post('id');
    if ($act === 'save' && post('name') !== '') {
        if ($id) q('UPDATE grades SET name=? WHERE id=?', [post('name'), $id]); else q('INSERT INTO grades(name) VALUES(?)', [post('name')]);
        flash('Grade saved.');
    } elseif ($act === 'delete') { q('DELETE FROM grades WHERE id=?', [$id]); flash('Grade deleted.'); }
    redirect('admin/grades');
}
$edit = isset($_GET['edit']) ? row('SELECT * FROM grades WHERE id=?', [(int)$_GET['edit']]) : null;
?>
<div class="card"><h3><i data-lucide="plus-circle"></i><?= $edit ? 'Edit grade' : 'New grade' ?></h3>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<?php field('Grade name', 'name', $edit['name'] ?? '', ['req' => 1, 'attr' => 'placeholder="e.g. A/L Theory"']); ?><button class="btn btn-r">Save</button></form></div><br>
<div class="tablewrap"><table><tr><th>Grade</th><th>Classes</th><th></th></tr>
<?php foreach (rows('SELECT g.*,(SELECT COUNT(*) FROM lessons l WHERE l.grade_id=g.id) c FROM grades g ORDER BY name') as $g): ?>
<tr><td><b><?= e($g['name']) ?></b></td><td><?= (int)$g['c'] ?></td><td><div class="acts"><a class="btn btn-o btn-sm" href="?edit=<?= $g['id'] ?>">Edit</a>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $g['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Delete this grade?">Delete</button></form></div></td></tr><?php endforeach; ?></table></div>
<?php admin_foot();
