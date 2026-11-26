<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php';
admin_head('Classes', 'classes');
if (is_post()) {
    $act = post('act'); $id = (int)post('id');
    if ($act === 'save' && post('name') !== '') {
        if ($id) q('UPDATE classes SET name=? WHERE id=?', [post('name'), $id]);
        else q('INSERT INTO classes(name) VALUES(?)', [post('name')]);
        flash('Class saved.');
    } elseif ($act === 'delete') { q('DELETE FROM classes WHERE id=?', [$id]); flash('Class deleted.'); }
    redirect('admin/classes');
}
$edit = isset($_GET['edit']) ? row('SELECT * FROM classes WHERE id=?', [(int)$_GET['edit']]) : null;
$list = rows('SELECT * FROM classes ORDER BY sort_order,id');
?>
<div class="card"><h3><i data-lucide="plus-circle"></i><?= $edit ? 'Edit class' : 'Add class' ?></h3>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<?php field('Class name', 'name', $edit['name'] ?? '', ['req' => 1, 'attr' => 'placeholder="Senara – Kegalle"']); ?>
<button class="btn">Save</button> <?php if ($edit): ?><a class="btn btn-o" href="<?= url('admin/classes') ?>">Cancel</a><?php endif; ?></form></div><br>
<div class="tablewrap"><table><tr><th>Class name</th><th style="width:1%"></th></tr>
<?php foreach ($list as $c): ?><tr><td><b><?= e($c['name']) ?></b></td>
<td><div class="acts"><a class="btn btn-o btn-sm" href="?edit=<?= $c['id'] ?>">Edit</a>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Delete this class?">Delete</button></form></div></td></tr>
<?php endforeach; if (!$list) echo '<tr><td colspan="2" class="empty">No classes yet.</td></tr>'; ?></table></div>
<?php admin_foot();
