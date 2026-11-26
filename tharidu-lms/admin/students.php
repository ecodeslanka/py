<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php';
admin_head('Students', 'students');
$qs = trim($_GET['q'] ?? ''); $bf = (int)($_GET['batch'] ?? 0);
$sql = 'SELECT u.*,b.name bname,(SELECT COUNT(*) FROM enrollments e WHERE e.user_id=u.id) ec FROM users u LEFT JOIN batches b ON b.id=u.batch_id WHERE u.role="student"'; $p = [];
if ($qs !== '') { $sql .= ' AND (u.name LIKE ? OR u.mobile LIKE ? OR u.nic LIKE ?)'; $like = '%' . preg_replace('/^0/', '', $qs) . '%'; $p = ['%' . $qs . '%', $like, '%' . $qs . '%']; }
if ($bf) { $sql .= ' AND u.batch_id=?'; $p[] = $bf; }
$list = rows($sql . ' ORDER BY u.id DESC LIMIT 500', $p);
?>
<form class="toolbar" method="get"><div style="display:flex;gap:8px;flex-wrap:wrap"><input name="q" value="<?= e($qs) ?>" placeholder="Search name / mobile / NIC" style="margin:0;width:260px">
<select name="batch" style="margin:0;width:180px"><option value="0">All batches</option><?php foreach (rows('SELECT id,name FROM batches ORDER BY sort_order') as $b) echo '<option value="' . $b['id'] . '"' . ($bf == $b['id'] ? ' selected' : '') . '>' . e($b['name']) . '</option>'; ?></select>
<button class="btn btn-o">Filter</button></div><span class="muted small"><?= count($list) ?> students</span></form>
<div class="tablewrap"><table><tr><th>Name</th><th>Mobile</th><th>NIC</th><th>Batch</th><th>Type</th><th>Classes</th><th>Joined</th><th></th></tr>
<?php foreach ($list as $s): ?><tr><td><b><?= e($s['name']) ?></b><?= $s['active'] ? '' : ' <span class="pill err">disabled</span>' ?></td><td><?= e(show_mobile($s['mobile'])) ?></td><td><?= e($s['nic'] ?: '—') ?></td><td><?= e($s['bname'] ?: '—') ?></td><td><?= e(ucfirst((string)$s['class_type'])) ?></td><td><?= (int)$s['ec'] ?></td><td><?= e(date('d M Y', strtotime($s['created_at']))) ?></td>
<td><a class="btn btn-b btn-sm" href="<?= url('admin/student?id=' . $s['id']) ?>">Open</a></td></tr>
<?php endforeach; if (!$list) echo '<tr><td colspan="8" class="empty">No students found.</td></tr>'; ?></table></div>
<?php admin_foot();
