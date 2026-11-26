<?php
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/layout.php';
$u = require_login(); if ($u['role'] === 'admin') redirect('admin/');
$uid = (int)$u['id'];
function lesson_blocker(int $uid, array $l): ?string {
    if (!$l['active']) return 'not available';
    if (is_enrolled($uid, (int)$l['id'])) return 'already joined';
    if (val("SELECT 1 FROM payments WHERE user_id=? AND lesson_id=? AND status='pending'", [$uid, $l['id']])) return 'payment already awaiting approval';
    if ($l['student_limit'] > 0 && lesson_students((int)$l['id']) >= $l['student_limit']) return 'class is full';
    return null;
}
if (is_post()) {
    $act = post('act'); $back = post('back'); $back = ($back !== '' && !preg_match('~^[a-z]+://|^//~i', $back)) ? ltrim($back, '/') : 'cart';
    if ($act === 'add') {
        $l = row('SELECT * FROM lessons WHERE id=?', [(int)post('lesson')]);
        if (!$l) flash('Lesson not found.', 'err');
        elseif ($why = lesson_blocker($uid, $l)) flash('Cannot add: ' . $why . '.', 'err');
        else { q('INSERT IGNORE INTO cart(user_id,lesson_id) VALUES(?,?)', [$uid, $l['id']]); flash('Added to cart 🛒'); }
        redirect($back);
    }
    if ($act === 'remove') { q('DELETE FROM cart WHERE user_id=? AND lesson_id=?', [$uid, (int)post('lesson')]); flash('Removed from cart.'); redirect($back); }
    if ($act === 'checkout') {
        try {
            $items = cart_items($uid); if (!$items) throw new RuntimeException('Your cart is empty.');
            foreach ($items as $l) if ($why = lesson_blocker($uid, $l)) throw new RuntimeException($l['name'] . ': ' . $why . '. Remove it from the cart and try again.');
            $f = save_upload('slip', 'slips', ['jpg', 'jpeg', 'png', 'webp', 'pdf'], 5);
            if (!$f && post('reference') === '') throw new RuntimeException('Upload your payment slip or enter the bank reference number.');
            $ref = 'O' . date('ymd') . strtoupper(bin2hex(random_bytes(2)));
            db()->beginTransaction();
            foreach ($items as $l) q('INSERT INTO payments(user_id,lesson_id,amount,method,reference,slip,order_ref) VALUES(?,?,?,"offline",?,?,?)', [$uid, $l['id'], $l['fee'], post('reference') ?: null, $f['name'] ?? null, $ref]);
            q('DELETE FROM cart WHERE user_id=?', [$uid]);
            db()->commit();
            flash('Order ' . $ref . ' sent for approval. You will get access to the lessons once the admin approves it.'); redirect('dashboard#pay');
        } catch (Throwable $ex) { if (db()->inTransaction()) db()->rollBack(); flash($ex->getMessage(), 'err'); redirect('cart'); }
    }
    redirect('cart');
}
student_head('My cart', 'dashboard#browse');
$items = cart_items($uid); $total = array_sum(array_column($items, 'fee'));
$g = ['g1', 'g2', 'g3', 'g4'];
?>
<?php if (!$items): ?>
  <div class="ahero b" style="text-align:center"><div style="font-size:42px">🛒</div><h1 style="margin:4px 0">Your cart is empty</h1><div class="hi">Pick lessons from your batch and add them here.</div></div>
  <a class="btn btn-block" href="<?= url('dashboard#browse') ?>"><i data-lucide="book-open"></i>Browse lessons</a>
<?php else: ?>
  <div class="ahero b"><div class="hi"><?= count($items) ?> lesson<?= count($items) > 1 ? 's' : '' ?> in your cart</div><h1><?= e(money($total)) ?></h1><div class="hi">Offline payment · access starts after admin approval</div></div>
  <div class="gstack">
  <?php foreach ($items as $i => $l): ?>
    <div class="gcard <?= $g[$i % 4] ?>"><div class="row"><span class="gi"><i data-lucide="book-open"></i></span><h3><?= e($l['name']) ?></h3></div>
      <div class="meta"><span><?= e($l['bname']) ?></span><span><?= e(month_label($l['month_year'])) ?></span><?php if ($l['due_date']): ?><span>Pay by <?= e(date('d M', strtotime($l['due_date']))) ?></span><?php endif; ?></div>
      <div class="gfoot"><div class="fee"><?= e(money($l['fee'])) ?></div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="remove"><input type="hidden" name="lesson" value="<?= $l['id'] ?>"><button class="gbtn in"><i data-lucide="trash-2"></i>Remove</button></form></div></div>
  <?php endforeach; ?></div>
  <div class="card" style="margin-top:18px"><h3><i data-lucide="landmark"></i>Pay offline</h3>
    <?php if (setting('bank_details')): ?><div class="copybox" style="white-space:pre-line;margin-bottom:14px"><?= e(setting('bank_details')) ?></div><?php endif; ?>
    <p class="muted small">Pay <b><?= e(money($total)) ?></b> at the bank / by transfer, then upload the slip. The admin will approve your order and unlock the lessons.</p><br>
    <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="act" value="checkout">
      <label class="f">Payment slip<span class="hint">JPG, PNG or PDF, max 5MB</span><input type="file" name="slip" accept=".jpg,.jpeg,.png,.webp,.pdf"></label>
      <?php field('Bank reference / note (optional if slip uploaded)', 'reference', '', ['attr' => 'maxlength="150"']); ?>
      <button class="btn btn-block"><i data-lucide="send"></i>Buy now · <?= e(money($total)) ?></button></form></div>
<?php endif; ?>
<?php student_foot();
