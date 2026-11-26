<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php';
admin_head('Batches', 'batches');
if (is_post()) {
    $act = post('act'); $id = (int)post('id');
    try {
        if ($act === 'save') {
            if (post('name') === '') throw new RuntimeException('Batch name is required.');
            $f = save_upload('cover', 'images', IMG_EXT, 5);
            $act_flag = isset($_POST['active']) ? 1 : 0;
            if ($id) {
                $old = row('SELECT cover_image FROM batches WHERE id=?', [$id]);
                q('UPDATE batches SET name=?,sort_order=?,active=? WHERE id=?', [post('name'), (int)post('sort_order'), $act_flag, $id]);
                if ($f) { q('UPDATE batches SET cover_image=? WHERE id=?', [$f['name'], $id]); del_upload('images', $old['cover_image'] ?? null); }
            } else q('INSERT INTO batches(name,cover_image,sort_order,active) VALUES(?,?,?,?)', [post('name'), $f['name'] ?? null, (int)post('sort_order'), $act_flag]);
            flash('Batch saved.');
        } elseif ($act === 'delete') {
            $old = row('SELECT cover_image FROM batches WHERE id=?', [$id]);
            q('DELETE FROM batches WHERE id=?', [$id]); del_upload('images', $old['cover_image'] ?? null); flash('Batch deleted (with its classes).');
        }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('admin/batches');
}
$edit = isset($_GET['edit']) ? row('SELECT * FROM batches WHERE id=?', [(int)$_GET['edit']]) : null;
$list = rows('SELECT b.*,(SELECT COUNT(*) FROM lessons l WHERE l.batch_id=b.id) lc FROM batches b ORDER BY sort_order,id');
?>
<div class="card"><h3><i data-lucide="plus-circle"></i><?= $edit ? 'Edit batch' : 'New batch' ?></h3>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<div class="three"><?php field('Batch name', 'name', $edit['name'] ?? '', ['req' => 1, 'attr' => 'placeholder="2027 A/L"']); field('Order', 'sort_order', $edit['sort_order'] ?? 0, ['type' => 'number']); ?>
<label class="f">Cover image<span class="hint">JPG/PNG/WEBP, max 5MB</span><input type="file" name="cover" accept="image/*"></label></div>
<?php if (!empty($edit['cover_image'])): ?><img class="thumb" src="<?= img($edit['cover_image']) ?>" alt=""><br><br><?php endif; ?>
<label class="chk"><input type="checkbox" name="active" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>>Active (visible on the website)</label>
<button class="btn btn-r">Save batch</button> <?php if ($edit): ?><a class="btn btn-o" href="<?= url('admin/batches') ?>">Cancel</a><?php endif; ?></form></div><br>
<div class="tablewrap"><table><tr><th>Cover</th><th>Name</th><th>Order</th><th>Classes</th><th>Status</th><th></th></tr>
<?php foreach ($list as $b): ?><tr><td><img class="thumb" src="<?= img($b['cover_image']) ?>" alt=""></td><td><b><?= e($b['name']) ?></b></td><td><?= (int)$b['sort_order'] ?></td><td><?= (int)$b['lc'] ?></td>
<td><span class="pill <?= $b['active'] ? 'ok' : '' ?>"><?= $b['active'] ? 'Active' : 'Hidden' ?></span></td>
<td><div class="acts"><a class="btn btn-o btn-sm" href="?edit=<?= $b['id'] ?>">Edit</a>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $b['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Delete this batch and ALL its classes?">Delete</button></form></div></td></tr>
<?php endforeach; if (!$list) echo '<tr><td colspan="6" class="empty">No batches yet.</td></tr>'; ?></table></div>
<?php admin_foot();
