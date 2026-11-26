<?php
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/layout.php';
$u = student_head('Join & pay');
$l = row('SELECT l.*,b.name bname FROM lessons l JOIN batches b ON b.id=l.batch_id WHERE l.id=? AND l.active=1', [(int)($_GET['lesson'] ?? 0)]);
if (!$l) { flash('Class not found.', 'err'); redirect('dashboard'); }
if (is_enrolled($u['id'], $l['id'])) { flash('You are already enrolled in this class.'); redirect('class?id=' . $l['id']); }
$pending = row("SELECT * FROM payments WHERE user_id=? AND lesson_id=? AND status='pending'", [$u['id'], $l['id']]);
$full = $l['student_limit'] > 0 && lesson_students($l['id']) >= $l['student_limit'];
if (!$pending && !$full && is_post()) {
    try {
        $f = save_upload('slip', 'slips', ['jpg', 'jpeg', 'png', 'webp', 'pdf'], 5);
        if (!$f && post('reference') === '') throw new RuntimeException('Upload your payment slip or enter the bank reference number.');
        q('INSERT INTO payments(user_id,lesson_id,amount,method,reference,slip) VALUES(?,?,?,"offline",?,?)',
          [$u['id'], $l['id'], $l['fee'], post('reference') ?: null, $f['name'] ?? null]);
        flash('Payment submitted. The teacher will approve it and you will be added to the class.');
        redirect('dashboard');
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); redirect('pay?lesson=' . $l['id']); }
}
?>
<div class="authwrap">
<div class="card"><h3><i data-lucide="wallet"></i><?= e($l['name']) ?></h3>
  <div class="meta" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px"><span class="pill"><?= e($l['bname']) ?></span><span class="pill"><?= e(month_label($l['month_year'])) ?></span>
  <span class="pill"><?= $l['class_type'] === 'online' ? 'Online' : 'Physical' . ($l['venue'] ? ' · ' . e($l['venue']) : '') ?></span></div>
  <?php if ($l['description']): ?><p class="muted"><?= nl2br(e($l['description'])) ?></p><?php endif; ?>
  <div class="fee" style="font-size:30px;font-weight:800;margin-top:10px"><?= e(money($l['fee'])) ?></div>
  <?php if ($l['due_date']): ?><div class="small muted">Payment due date: <?= e(date('d M Y', strtotime($l['due_date']))) ?></div><?php endif; ?>
</div>
<?php if ($pending): ?><div class="card"><div class="alert ok">Your payment is waiting for admin approval. You will get access as soon as it is approved.</div><a class="btn btn-o" href="<?= url('dashboard') ?>">Back</a></div>
<?php elseif ($full): ?><div class="card"><div class="alert err">Sorry, this class is full.</div></div>
<?php else: ?>
<div class="card"><h3><i data-lucide="landmark"></i>Offline payment</h3>
  <?php if (setting('bank_details')): ?><div class="copybox" style="white-space:pre-line;margin-bottom:16px"><?= e(setting('bank_details')) ?></div><?php endif; ?>
  <p class="muted small">Pay at the bank / by transfer, then upload the slip. Your enrolment starts after the admin approves it.</p><br>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
    <label class="f">Payment slip<span class="hint">JPG, PNG or PDF, max 5MB</span><input type="file" name="slip" accept=".jpg,.jpeg,.png,.webp,.pdf"></label>
    <?php field('Bank reference / note (optional if slip uploaded)', 'reference', '', ['attr' => 'maxlength="150"']); ?>
    <button class="btn btn-r btn-block"><i data-lucide="send"></i>Submit payment for approval</button>
  </form></div>
<?php endif; ?></div>
<?php student_foot();
