<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php';
require_admin();
$filter = $_GET['s'] ?? 'pending'; if (!in_array($filter, ['pending', 'approved', 'rejected', 'all'], true)) $filter = 'pending';
if (is_post()) {
    $act = post('act'); $oid = post('oid');                       // oid = order_ref, or "p<ID>" for old single payments
    $ids = array_column(preg_match('/^p(\d+)$/', $oid, $mm) ? rows('SELECT id FROM payments WHERE id=?', [(int)$mm[1]]) : rows('SELECT id FROM payments WHERE order_ref=?', [$oid]), 'id');
    foreach ($ids as $pid) {
        $p = row('SELECT * FROM payments WHERE id=?', [$pid]); if (!$p) continue;
        if ($act === 'approve' && $p['status'] === 'pending') {
            q("UPDATE payments SET status='approved',decided_at=NOW(),admin_note=? WHERE id=?", [post('note') ?: null, $pid]);
            if (!is_enrolled((int)$p['user_id'], (int)$p['lesson_id'])) q('INSERT INTO enrollments(user_id,lesson_id) VALUES(?,?)', [$p['user_id'], $p['lesson_id']]);
        } elseif ($act === 'reject' && $p['status'] === 'pending') q("UPDATE payments SET status='rejected',decided_at=NOW(),admin_note=? WHERE id=?", [post('note') ?: 'Rejected', $pid]);
        elseif ($act === 'delete') { if ($p['slip'] && !val('SELECT 1 FROM payments WHERE slip=? AND id<>?', [$p['slip'], $pid])) del_upload('slips', $p['slip']); q('DELETE FROM payments WHERE id=?', [$pid]); }
    }
    flash(['approve' => 'Order approved – student enrolled in all lessons.', 'reject' => 'Order rejected.', 'delete' => 'Deleted.'][$act] ?? 'Done.');
    redirect('admin/payments?s=' . $filter);
}
admin_head('Payments', 'payments');
$w = $filter === 'all' ? '1' : 'p.status=' . db()->quote($filter);
$rowsAll = rows("SELECT p.*,u.name,u.mobile,l.name lname,l.month_year,l.student_limit,(SELECT COUNT(*) FROM enrollments e WHERE e.lesson_id=l.id) cnt FROM payments p JOIN users u ON u.id=p.user_id JOIN lessons l ON l.id=p.lesson_id WHERE $w ORDER BY p.id DESC LIMIT 400");
$orders = [];
foreach ($rowsAll as $r) { $k = $r['order_ref'] ?: 'p' . $r['id']; $orders[$k][] = $r; }
?>
<div class="tabs"><?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $t): ?><a class="<?= $filter === $k ? 'on' : '' ?>" href="?s=<?= $k ?>"><?= $t ?></a><?php endforeach; ?></div>
<div class="tile-list">
<?php if (!$orders) echo '<div class="card empty">Nothing here.</div>'; foreach ($orders as $oid => $its): $f = $its[0]; $total = array_sum(array_column($its, 'amount'));
  $sts = array_unique(array_column($its, 'status')); $st = count($sts) === 1 ? $sts[0] : 'pending'; $full = false; foreach ($its as $x) if ($x['student_limit'] && $x['cnt'] >= $x['student_limit'] && $x['status'] === 'pending') $full = true; ?>
<div class="card" style="padding:18px">
  <div class="pagehead" style="justify-content:space-between"><div><a href="<?= url('admin/student?id=' . $f['user_id']) ?>"><b><?= e($f['name']) ?></b></a> <span class="muted small"><?= e(show_mobile($f['mobile'])) ?></span><div class="small muted"><?= e(date('d M Y H:i', strtotime($f['created_at']))) ?> · <?= e($f['order_ref'] ?: 'Single payment') ?></div></div>
    <div style="text-align:right"><b style="font-size:20px"><?= e(money($total)) ?></b><br><span class="pill <?= ['approved' => 'ok', 'pending' => 'warn', 'rejected' => 'err'][$st] ?>"><?= e(ucfirst($st)) ?></span></div></div>
  <div style="margin:10px 0"><?php foreach ($its as $x): ?><span class="pill blue" style="margin:2px"><?= e($x['lname']) ?> · <?= e(money($x['amount'])) ?></span><?php endforeach; ?></div>
  <div class="acts"><?php if ($f['slip']): ?><a class="btn btn-o btn-sm" href="<?= url('file?slip=' . $f['id']) ?>" target="_blank"><i data-lucide="image"></i>View slip</a><?php endif; ?><?= $f['reference'] ? '<span class="small">Ref: ' . e($f['reference']) . '</span>' : '' ?>
  <?php if ($st === 'pending'): ?>
    <form method="post" class="acts" style="margin-left:auto"><?= csrf_field() ?><input type="hidden" name="oid" value="<?= e($oid) ?>"><input name="note" placeholder="Note (optional)" style="width:150px;margin:0;min-height:34px;padding:6px 10px">
      <button class="btn btn-ok btn-sm" name="act" value="approve" <?= $full ? 'data-confirm="A lesson in this order is FULL. Approve anyway?"' : '' ?>><i data-lucide="check"></i>Approve order</button>
      <button class="btn btn-danger btn-sm" name="act" value="reject"><i data-lucide="x"></i>Reject</button></form>
  <?php else: ?><form method="post" class="inline" style="margin-left:auto"><?= csrf_field() ?><input type="hidden" name="oid" value="<?= e($oid) ?>"><input type="hidden" name="act" value="delete"><button class="btn btn-o btn-sm" data-confirm="Delete this record?">Delete</button></form><?php endif; ?></div>
  <?php if ($f['admin_note']): ?><div class="small muted" style="margin-top:6px">Note: <?= e($f['admin_note']) ?></div><?php endif; ?>
</div>
<?php endforeach; ?></div>
<?php admin_foot();
