<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php';
require_admin();
$filter = $_GET['s'] ?? 'pending'; if (!in_array($filter, ['pending', 'approved', 'rejected', 'all'], true)) $filter = 'pending';
if (is_post()) {
    $p = row('SELECT * FROM payments WHERE id=?', [(int)post('id')]); $act = post('act');
    if ($p && $act === 'approve') {
        q("UPDATE payments SET status='approved',decided_at=NOW(),admin_note=? WHERE id=?", [post('note') ?: null, $p['id']]);
        if (!is_enrolled((int)$p['user_id'], (int)$p['lesson_id'])) q('INSERT INTO enrollments(user_id,lesson_id) VALUES(?,?)', [$p['user_id'], $p['lesson_id']]);
        flash('Payment approved – student enrolled.');
    } elseif ($p && $act === 'reject') {
        q("UPDATE payments SET status='rejected',decided_at=NOW(),admin_note=? WHERE id=?", [post('note') ?: 'Rejected', $p['id']]); flash('Payment rejected.');
    } elseif ($p && $act === 'delete') { del_upload('slips', $p['slip']); q('DELETE FROM payments WHERE id=?', [$p['id']]); flash('Payment record deleted.'); }
    redirect('admin/payments?s=' . $filter);
}
admin_head('Payments', 'payments');
$w = $filter === 'all' ? '1' : 'p.status=' . db()->quote($filter);
$list = rows("SELECT p.*,u.name,u.mobile,l.name lname,l.month_year,(SELECT COUNT(*) FROM enrollments e WHERE e.lesson_id=l.id) cnt,l.student_limit FROM payments p JOIN users u ON u.id=p.user_id JOIN lessons l ON l.id=p.lesson_id WHERE $w ORDER BY p.id DESC LIMIT 300");
?>
<div class="tabs"><?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $t): ?><a class="<?= $filter === $k ? 'on' : '' ?>" href="?s=<?= $k ?>"><?= $t ?></a><?php endforeach; ?></div>
<div class="tablewrap"><table><tr><th>Date</th><th>Student</th><th>Class</th><th>Amount</th><th>Slip / Ref</th><th>Status</th><th>Action</th></tr>
<?php foreach ($list as $p): ?><tr>
<td><?= e(date('d M Y H:i', strtotime($p['created_at']))) ?></td>
<td><a href="<?= url('admin/student?id=' . $p['user_id']) ?>"><b><?= e($p['name']) ?></b></a><div class="small muted"><?= e(show_mobile($p['mobile'])) ?></div></td>
<td><?= e($p['lname']) ?><div class="small muted"><?= e(month_label($p['month_year'])) ?></div></td><td><?= e(money($p['amount'])) ?></td>
<td><?php if ($p['slip']): ?><a class="btn btn-o btn-sm" href="<?= url('file?slip=' . $p['id']) ?>" target="_blank">View slip</a><?php endif; ?><?= $p['reference'] ? '<div class="small">' . e($p['reference']) . '</div>' : '' ?></td>
<td><span class="pill <?= ['approved' => 'ok', 'pending' => 'warn', 'rejected' => 'err'][$p['status']] ?>"><?= e(ucfirst($p['status'])) ?></span><div class="small muted"><?= e($p['admin_note']) ?></div></td>
<td><?php if ($p['status'] === 'pending'): ?>
<form method="post" class="acts"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $p['id'] ?>"><input name="note" placeholder="Note (optional)" style="width:130px;margin:0;padding:7px 10px">
<button class="btn btn-ok btn-sm" name="act" value="approve" <?= ($p['student_limit'] && $p['cnt'] >= $p['student_limit']) ? 'data-confirm="This class is FULL. Approve anyway?"' : '' ?>>Approve</button>
<button class="btn btn-danger btn-sm" name="act" value="reject">Reject</button></form>
<?php else: ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $p['id'] ?>"><input type="hidden" name="act" value="delete"><button class="btn btn-o btn-sm" data-confirm="Delete this payment record?">Delete</button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$list) echo '<tr><td colspan="7" class="empty">Nothing here.</td></tr>'; ?></table></div>
<?php admin_foot();
