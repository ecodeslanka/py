<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php';
$me = require_admin();
if (is_post()) {
    $act = post('act'); $id = (int)post('id');
    try {
        if ($act === 'add') {
            $m = norm_mobile(post('mobile')); if (!$m) throw new RuntimeException('Invalid mobile.');
            if (post('name') === '' || strlen((string)$_POST['password']) < 6) throw new RuntimeException('Name and a 6+ character password are required.');
            if (val('SELECT 1 FROM users WHERE mobile=?', [$m])) throw new RuntimeException('Mobile already used.');
            q('INSERT INTO users(role,name,mobile,password_hash) VALUES("admin",?,?,?)', [post('name'), $m, password_hash($_POST['password'], PASSWORD_DEFAULT)]); flash('Admin user created.');
        } elseif ($act === 'pw') {
            if (strlen((string)$_POST['password']) < 6) throw new RuntimeException('Password must be 6+ characters.');
            q('UPDATE users SET password_hash=? WHERE id=? AND role="admin"', [password_hash($_POST['password'], PASSWORD_DEFAULT), $id]); flash('Password updated.');
        } elseif ($act === 'delete') {
            if ($id === (int)$me['id']) throw new RuntimeException('You cannot delete yourself.');
            if ((int)val('SELECT COUNT(*) FROM users WHERE role="admin"') < 2) throw new RuntimeException('At least one admin must remain.');
            q('DELETE FROM users WHERE id=? AND role="admin"', [$id]); flash('Admin deleted.');
        }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('admin/users');
}
admin_head('Admin users', 'users');
if (password_verify('Admin@123', $me['password_hash'])) echo '<div class="alert err">You are still using the default password <b>Admin@123</b>. Change it below now!</div>';
?>
<div class="card"><h3><i data-lucide="user-plus"></i>New admin user</h3><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="add">
<div class="three"><?php field('Name', 'name', '', ['req' => 1]); field('Mobile (login)', 'mobile', '', ['req' => 1, 'type' => 'tel']); field('Password', 'password', '', ['req' => 1, 'type' => 'password', 'attr' => 'minlength="6"']); ?></div><button class="btn btn-r">Create admin</button></form></div><br>
<div class="tablewrap"><table><tr><th>Name</th><th>Mobile</th><th>New password</th><th></th></tr>
<?php foreach (rows('SELECT * FROM users WHERE role="admin" ORDER BY id') as $a): ?><tr><td><b><?= e($a['name']) ?></b><?= $a['id'] == $me['id'] ? ' <span class="pill ok">you</span>' : '' ?></td><td><?= e(show_mobile($a['mobile'])) ?></td>
<td><form method="post" class="acts"><?= csrf_field() ?><input type="hidden" name="act" value="pw"><input type="hidden" name="id" value="<?= $a['id'] ?>"><input type="password" name="password" minlength="6" placeholder="New password" required style="margin:0;width:170px;padding:8px 10px"><button class="btn btn-o btn-sm">Change</button></form></td>
<td><?php if ($a['id'] != $me['id']): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Delete this admin?">Delete</button></form><?php endif; ?></td></tr><?php endforeach; ?></table></div>
<?php admin_foot();
