<?php
/**
 * dashboard.php — Customer Dashboard
 * Shown right after Sign Up (email/password or Google) and available any
 * time via the "Hi, {name}" link in the header. Shows the account profile
 * and order history for the logged-in customer.
 */
require_once __DIR__ . '/config/config.php';
ensure_customer_tables();
ensure_orders_table();
ensure_location_tables();

$customer = current_customer();
if (!$customer) {
    redirect('/login');
}

$needsProfile = strpos((string)$customer['mobile'], 'g-') === 0 || empty($customer['address_line1']) || empty($customer['city_id']);

$orders = get_customer_orders((int)$customer['id']);

$statusMeta = [
    'pending'     => ['label' => 'Pending',     'class' => 'st-pending'],
    'confirmed'   => ['label' => 'Confirmed',   'class' => 'st-confirmed'],
    'processing'  => ['label' => 'Processing',  'class' => 'st-processing'],
    'shipped'     => ['label' => 'Shipped',     'class' => 'st-shipped'],
    'delivered'   => ['label' => 'Delivered',   'class' => 'st-delivered'],
    'cancelled'   => ['label' => 'Cancelled',   'class' => 'st-cancelled'],
];

$pageTitle = 'My Dashboard';
require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/auth.css">

<div class="dash-wrap">
  <div class="wrap">

    <?php if ($m = flash('customer_success')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>

    <?php if ($needsProfile): ?>
      <div class="alert alert-error" style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
        <span><i class="fa-solid fa-circle-info"></i> Add your mobile number and delivery address so we can process your orders faster.</span>
        <a href="<?= BASE_URL ?>/profile" class="mini-btn edit" style="white-space:nowrap">Complete Profile</a>
      </div>
    <?php endif; ?>

    <div class="dash-head">
      <div class="dash-avatar"><?= e(mb_strtoupper(mb_substr($customer['first_name'], 0, 1))) ?></div>
      <div>
        <h1>Hi, <?= e($customer['first_name']) ?> <?= e($customer['last_name']) ?></h1>
        <p class="dash-sub">Welcome to your dashboard — track orders and manage your account here.</p>
      </div>
      <a href="<?= BASE_URL ?>/logout" class="dash-logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>

    <div class="dash-grid">

      <!-- ===== Profile card ===== -->
      <div class="dash-card">
        <h3><i class="fa-solid fa-user"></i> My Profile</h3>
        <ul class="dash-profile-list">
          <li><span>Name</span><strong><?= e($customer['first_name'] . ' ' . $customer['last_name']) ?></strong></li>
          <li><span>Email</span><strong><?= e($customer['email']) ?></strong></li>
          <li>
            <span>Mobile</span>
            <strong>
              <?= (strpos((string)$customer['mobile'], 'g-') === 0) ? '<em style="color:var(--muted);font-weight:600">Not added yet</em>' : e($customer['mobile']) ?>
              <?php if (!empty($customer['mobile_verified'])): ?>
                <span class="verified-badge"><i class="fa-solid fa-circle-check"></i> Verified</span>
              <?php endif; ?>
            </strong>
          </li>
          <li>
            <span>Delivery Address</span>
            <strong>
              <?php if (!empty($customer['address_line1'])): ?>
                <?= e($customer['address_line1']) ?><?= !empty($customer['address_line2']) ? ', ' . e($customer['address_line2']) : '' ?><?php $__cc = !empty($customer['city_id']) ? get_city((int)$customer['city_id']) : null; ?><?= $__cc ? ', ' . e($__cc['name_en']) . ', ' . e($__cc['province_name']) : '' ?>
              <?php else: ?>
                <em style="color:var(--muted);font-weight:600">Not added yet</em>
              <?php endif; ?>
            </strong>
          </li>
          <li><span>Member Since</span><strong><?= e(date('d M Y', strtotime((string)$customer['created_at']))) ?></strong></li>
          <?php if (!empty($customer['google_id'])): ?>
            <li><span>Signed in with</span><strong><i class="fa-brands fa-google"></i> Google</strong></li>
          <?php endif; ?>
        </ul>
        <a href="<?= BASE_URL ?>/profile" class="mini-btn edit" style="display:inline-flex;margin-top:16px"><i class="fa-solid fa-pen"></i> Edit Profile</a>
      </div>

      <!-- ===== Order history ===== -->
      <div class="dash-card dash-card-wide">
        <h3><i class="fa-solid fa-receipt"></i> My Orders</h3>

        <?php if (empty($orders)): ?>
          <div class="dash-empty">
            <i class="fa-solid fa-box-open"></i>
            <p>You haven't placed any orders yet.</p>
            <a href="<?= BASE_URL ?>/" class="auth-submit" style="display:inline-block;width:auto;padding:12px 26px">Start Shopping</a>
          </div>
        <?php else: ?>
          <div class="dash-orders">
            <?php foreach ($orders as $o): $meta = $statusMeta[$o['status']] ?? ['label' => ucfirst($o['status']), 'class' => 'st-pending']; ?>
              <div class="dash-order-row">
                <div class="dash-order-main">
                  <strong><?= e($o['order_no']) ?></strong>
                  <span class="dash-order-date"><?= e(date('d M Y, h:i A', strtotime((string)$o['created_at']))) ?></span>
                </div>
                <div class="dash-order-total">Rs. <?= number_format((float)$o['subtotal'], 2) ?></div>
                <div class="dash-order-status <?= $meta['class'] ?>"><?= e($meta['label']) ?></div>
                <a href="<?= BASE_URL ?>/track-order?order_no=<?= urlencode($o['order_no']) ?>&email=<?= urlencode($customer['email']) ?>" class="dash-order-view">View <i class="fa-solid fa-chevron-right"></i></a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
