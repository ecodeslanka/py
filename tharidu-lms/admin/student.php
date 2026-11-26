<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php';
require_admin();
$s = row('SELECT * FROM users WHERE id=? AND role="student"', [(int)($_GET['id'] ?? 0)]);
if (!$s) { flash('Student not found.', 'err'); redirect('admin/students'); }
$sid = (int)$s['id'];
if (is_post()) {
    $act = post('act');
    try {
        if ($act === 'save') {
            $m = norm_mobile(post('mobile')); if (!$m) throw new RuntimeException('Invalid mobile number.');
            if (val('SELECT 1 FROM users WHERE mobile=? AND id<>?', [$m, $sid])) throw new RuntimeException('That mobile is used by another account.');
            if (post('name') === '') throw new RuntimeException('Name required.');
            q('UPDATE users SET name=?,mobile=?,nic=?,batch_id=?,class_type=?,active=? WHERE id=?', [post('name'), $m, post('nic') ?: null, (int)post('batch_id') ?: null, in_array(post('class_type'), ['physical', 'online']) ? post('class_type') : null, isset($_POST['active']) ? 1 : 0, $sid]);
            flash('Student details saved.');
        } elseif ($act === 'pw') {
            if (strlen((string)$_POST['pw']) < 6) throw new RuntimeException('Password must be at least 6 characters.');
            q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($_POST['pw'], PASSWORD_DEFAULT), $sid]); flash('Password changed.');
        } elseif ($act === 'delete') {
            foreach (rows('SELECT slip FROM payments WHERE user_id=?', [$sid]) as $p) del_upload('slips', $p['slip']);
            q('DELETE FROM users WHERE id=?', [$sid]); flash('Student deleted.'); redirect('admin/students');
        } elseif ($act === 'unenroll') { q('DELETE FROM enrollments WHERE user_id=? AND lesson_id=?', [$sid, (int)post('lesson_id')]); flash('Removed from class.'); }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('admin/student?id=' . $sid);
}
admin_head($s['name'], 'students');
$enr = rows('SELECT l.id,l.name,l.month_year FROM enrollments e JOIN lessons l ON l.id=e.lesson_id WHERE e.user_id=?', [$sid]);
$pays = rows('SELECT p.*,l.name lname FROM payments p JOIN lessons l ON l.id=p.lesson_id WHERE p.user_id=? ORDER BY p.id DESC', [$sid]);
$bo = ['' => '— none —'] + array_column(rows('SELECT id,name FROM batches ORDER BY sort_order'), 'name', 'id');
?>
<div class="card"><h3><i data-lucide="user"></i>Details</h3>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save">
<div class="two"><?php field('Name', 'name', $s['name'], ['req' => 1]); field('Mobile', 'mobile', show_mobile($s['mobile']), ['req' => 1]); field('NIC', 'nic', $s['nic'] ?? ''); field('Batch', 'batch_id', $s['batch_id'], ['type' => 'select', 'options' => $bo]);
field('Class type', 'class_type', $s['class_type'] ?? '', ['type' => 'select', 'options' => ['' => '—', 'physical' => 'Physical', 'online' => 'Online']]); ?></div>
<label class="chk"><input type="checkbox" name="active" <?= $s['active'] ? 'checked' : '' ?>>Account active (can log in)</label>
<button class="btn btn-r">Save details</button></form></div><br>
<div class="card" id="pw"><h3><i data-lucide="key-round"></i>Change password</h3>
<form method="post" class="two"><?= csrf_field() ?><input type="hidden" name="act" value="pw"><?php field('New password', 'pw', '', ['type' => 'password', 'req' => 1, 'attr' => 'minlength="6" autocomplete="new-password"']); ?>
<div style="align-self:end;margin-bottom:14px"><button class="btn btn-o">Set password</button></div></form></div><br>
<div class="card"><h3><i data-lucide="book-open"></i>Enrolled classes</h3>
<?php if (!$enr) echo '<p class="empty">Not enrolled in any class.</p>'; foreach ($enr as $l): ?>
<div class="matitem"><div class="grow"><b><?= e($l['name']) ?></b> <span class="muted small"><?= e(month_label($l['month_year'])) ?></span></div>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="unenroll"><input type="hidden" name="lesson_id" value="<?= $l['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Remove from this class?">Remove</button></form></div><?php endforeach; ?></div><br>
<div class="card" id="pay"><h3><i data-lucide="wallet"></i>Payments</h3>
<?php if (!$pays): echo '<p class="empty">No payments.</p>'; else: ?><div class="tablewrap"><table><tr><th>Date</th><th>Class</th><th>Amount</th><th>Slip</th><th>Status</th></tr>
<?php foreach ($pays as $p): ?><tr><td><?= e(date('d M Y', strtotime($p['created_at']))) ?></td><td><?= e($p['lname']) ?></td><td><?= e(money($p['amount'])) ?></td>
<td><?= $p['slip'] ? '<a href="' . url('file?slip=' . $p['id']) . '" target="_blank">View</a>' : e($p['reference'] ?: '—') ?></td>
<td><span class="pill <?= ['approved' => 'ok', 'pending' => 'warn', 'rejected' => 'err'][$p['status']] ?>"><?= e(ucfirst($p['status'])) ?></span></td></tr><?php endforeach; ?></table></div><?php endif; ?>
<br><a class="btn btn-o btn-sm" href="<?= url('admin/payments') ?>">Go to approvals</a></div><br>
<div class="card"><h3 style="color:#d93025"><i data-lucide="trash-2"></i>Delete student</h3><p class="muted small">Removes the account, enrolments and payment records permanently.</p><br>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><button class="btn btn-danger" data-confirm="Permanently delete this student?">Delete student</button></form></div>
<?php admin_foot();
