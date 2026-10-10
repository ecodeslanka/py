<?php
// Role list: entry point with Add / Edit / Delete links.
require_once __DIR__ . '/role_helpers.php';
roleRequire('access');

$roles = mysqli_query($conn, "SELECT r.*,
    (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
    (SELECT COUNT(*) FROM permissions p WHERE p.role_id = r.id AND p.can_access = 1) AS page_count
    FROM roles r ORDER BY r.role_name");
include __DIR__ . '/header.php';
?>
<style>
.rl{max-width:1100px;margin:20px auto;padding:0 16px}
.rl table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb}
.rl th,.rl td{padding:9px 12px;border-bottom:1px solid #f1f5f9;text-align:left;font-size:13px}
.rl .btn{padding:6px 12px;border:0;border-radius:6px;cursor:pointer;font-weight:600;text-decoration:none;font-size:12px;display:inline-block}
.rl .p{background:#2563eb;color:#fff}.rl .e{background:#f59e0b;color:#fff}.rl .d{background:#dc2626;color:#fff}
.rl .ok{background:#dcfce7;color:#166534;padding:10px;border-radius:6px;margin-bottom:10px}
.rl .bad{background:#fee2e2;color:#991b1b;padding:10px;border-radius:6px;margin-bottom:10px}
</style>
<div class="rl">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <h2>User Roles</h2>
    <?php if (roleCan('create')): ?><a class="btn p" href="role_add.php">+ Add Role</a><?php endif; ?>
  </div>
  <?php if (!empty($_GET['msg'])): ?><div class="ok"><?= h($_GET['msg']) ?></div><?php endif; ?>
  <?php if (!empty($_GET['err'])): ?><div class="bad"><?= h($_GET['err']) ?></div><?php endif; ?>
  <table>
    <tr><th>Role</th><th>Description</th><th>Pages</th><th>Users</th><th>Status</th><th>Actions</th></tr>
    <?php while ($r = mysqli_fetch_assoc($roles)): ?>
    <tr>
      <td><strong><?= h($r['role_name']) ?></strong></td>
      <td><?= h($r['description']) ?></td>
      <td><?= (int)$r['page_count'] ?></td>
      <td><?= (int)$r['user_count'] ?></td>
      <td><?= $r['active'] ? 'Active' : 'Inactive' ?></td>
      <td>
        <?php if (roleCan('edit')): ?><a class="btn e" href="role_edit.php?id=<?= (int)$r['id'] ?>">Edit</a><?php endif; ?>
        <?php if (roleCan('delete')): ?>
        <form method="post" action="role_delete.php" style="display:inline" onsubmit="return confirm('Delete role <?= h(addslashes($r['role_name'])) ?>?')">
          <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn d" type="submit">Delete</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endwhile; ?>
  </table>
</div>
