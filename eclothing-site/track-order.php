<?php
/**
 * track-order.php — Track Order  (URL: /track-order, mapped by .htaccess)
 * Lets a customer look up an order by its order number + the email used at
 * checkout, and shows the current status plus the full status/courier
 * timeline recorded by the admin (courier name, tracking number, dates).
 */
require_once __DIR__ . '/config/config.php';

ensure_orders_table();

$statusLabels    = order_status_labels();
$payStatusLabels = payment_status_labels();
$paymentLabels   = payment_method_labels();

$orderNoInput = trim((string)($_GET['order_no'] ?? ''));
$emailInput   = trim((string)($_GET['email'] ?? ''));
$searched     = $orderNoInput !== '' || $emailInput !== '';
$order        = null;
$history      = [];
$error        = '';

if ($searched) {
    if ($orderNoInput === '' || !filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter your order number and the email address used at checkout.';
    } else {
        $order = get_order_by_no($orderNoInput, $emailInput);
        if (!$order) {
            $error = "We couldn't find an order matching that order number and email. Please double-check and try again.";
        } else {
            $history = get_order_status_history((int)$order['id']);
        }
    }
}

$pageTitle = 'Track Order — ' . site_display_name();

require __DIR__ . '/includes/header.php';
?>

<nav class="item-breadcrumb wrap">
  <a href="<?= BASE_URL ?>/">Home</a> <span>/</span> <span class="current">Track Order</span>
</nav>

<section class="contact-hero">
  <div class="wrap">
    <h1>Track Your Order</h1>
    <p>Enter your order number and email to see its current status and courier tracking details.</p>
  </div>
</section>

<section class="wrap track-wrap">

  <div class="contact-form-card track-form-card">
    <h3>Find Your Order</h3>
    <p class="contact-form-sub">Both fields are required — the email must match the one used at checkout.</p>

    <?php if ($error): ?>
      <div class="track-alert track-alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($error) ?></div>
    <?php endif; ?>

    <form method="get" autocomplete="off" class="contact-form-grid" style="margin-bottom:0">
      <label>Order Number
        <input type="text" name="order_no" required maxlength="20" value="<?= e($orderNoInput) ?>" placeholder="e.g. ECL9F3A2B1C">
      </label>
      <label>Email Address
        <input type="email" name="email" required maxlength="150" value="<?= e($emailInput) ?>" placeholder="you@example.com">
      </label>
      <div style="grid-column:1/-1">
        <button type="submit" class="btn btn-green"><i class="fa-solid fa-magnifying-glass"></i> Track Order</button>
      </div>
    </form>
  </div>

  <?php if ($order): ?>
  <div class="contact-form-card track-result-card">
    <div class="track-result-head">
      <div>
        <h3>Order <?= e($order['order_no']) ?></h3>
        <p class="contact-form-sub">Placed on <?= e(date('M j, Y', strtotime((string)$order['created_at']))) ?></p>
      </div>
      <span class="track-status-pill track-st-<?= e($order['status']) ?>"><?= e($statusLabels[$order['status']] ?? $order['status']) ?></span>
    </div>

    <div class="track-pay-row">
      <span class="track-pay-pill track-pay-<?= e($order['payment_status'] ?: 'unpaid') ?>"><?= e($payStatusLabels[$order['payment_status']] ?? ucfirst((string)$order['payment_status'])) ?></span>
      <span class="crumb"><?= e($paymentLabels[$order['payment_method']] ?? $order['payment_method']) ?></span>
    </div>

    <?php if ($order['tracking_no']): ?>
    <div class="track-courier-box">
      <div>
        <span class="tk-label">Tracking Number</span>
        <div class="tk-no"><?= e($order['tracking_no']) ?></div>
      </div>
      <?php if ($order['courier_name']): ?>
      <div>
        <span class="tk-label">Courier</span>
        <div class="tk-courier"><?= e($order['courier_name']) ?></div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($history): ?>
    <div class="track-timeline">
      <?php $lastIdx = count($history) - 1; foreach ($history as $i => $h): ?>
        <div class="track-timeline-step <?= $i === $lastIdx ? 'current' : 'done' ?>">
          <div class="dot"></div>
          <div class="line"></div>
          <div class="content">
            <strong><?= e($statusLabels[$h['status']] ?? $h['status']) ?></strong>
            <span class="date"><?= e(date('M j, Y', strtotime((string)$h['status_date']))) ?></span>
            <?php if ($h['tracking_no']): ?>
              <span class="detail"><?= e($h['courier_name'] ?: 'Courier') ?> — <?= e($h['tracking_no']) ?></span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="track-items">
      <h4>Items</h4>
      <?php foreach ($order['items'] as $it): ?>
        <div class="track-item-row">
          <span><?= e($it['name']) ?> <span class="crumb-x">× <?= (int)$it['qty'] ?></span></span>
          <b>Rs. <?= number_format((float)$it['line_total'], 2) ?></b>
        </div>
      <?php endforeach; ?>
      <div class="track-item-row total">
        <span>Total</span>
        <b>Rs. <?= number_format((float)$order['subtotal'] + (float)($order['delivery_charge'] ?? 0), 2) ?></b>
      </div>
    </div>
  </div>
  <?php endif; ?>

</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
