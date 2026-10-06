<?php
/**
 * checkout.php — Checkout page.
 *   GET  /checkout.php            → checkout form (delivery details + order summary), from the session cart
 *   POST /checkout.php            → validates, creates the order, emails the confirmation, clears the cart
 *   GET  /checkout.php?order=XXX  → "Thank you" confirmation for an order just placed in this session
 */
require_once __DIR__ . '/config/config.php';
ensure_orders_table();
ensure_location_tables();

$customer = current_customer();

$prefillCity = '';
$prefillProvince = '';
$prefillCityId = '';
if ($customer && !empty($customer['city_id'])) {
    $cityRow = get_city((int)$customer['city_id']);
    if ($cityRow) {
        $prefillCity     = $cityRow['name_en'];
        $prefillProvince = $cityRow['province_name'];
        $prefillCityId   = (int)$cityRow['id'];
    }
}
$customerHasRealMobile = $customer && strpos((string)$customer['mobile'], 'g-') !== 0;

$errors   = [];
$old      = [
    'full_name' => $customer ? trim($customer['first_name'] . ' ' . $customer['last_name']) : '',
    'email'     => $customer['email'] ?? '',
    'phone'     => $customerHasRealMobile ? $customer['mobile'] : '',
    'address'   => $customer['address_line1'] ?? '',
    'province'  => $prefillProvince,
    'city'      => $prefillCity,
    'city_id'   => $prefillCityId,
    'notes'     => '',
    'payment_method' => 'cod',
];

/* ---------- POST: place the order ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    foreach (['full_name', 'email', 'phone', 'address', 'province', 'city', 'city_id', 'notes', 'payment_method'] as $f) {
        $old[$f] = trim((string)($_POST[$f] ?? ''));
    }
    if ($old['payment_method'] === '') { $old['payment_method'] = 'cod'; }

    $result = create_order($old);

    if ($result['ok']) {
        $order = get_order_by_no($result['order_no']);
        if ($order) {
            send_order_confirmation_email($order);
            send_admin_order_notification_email($order);
            send_new_order_sms($order);
        }
        cart_clear();
        $_SESSION['recent_orders'][] = $result['order_no'];

        /* Online payment (Genie Business Connect) — send the customer to Genie's
           hosted payment page instead of straight to the confirmation screen.
           The cart is already cleared and the order already exists as 'pending' /
           payment_status 'unpaid'; payment_webhook.php marks it paid once Genie
           confirms the transaction. */
        if ($old['payment_method'] === 'genie' && $order) {
            if (!genie_is_configured()) {
                // Don't pretend the payment is "pending" — say plainly why it never started.
                flash('err', 'Card payment could not be started because Genie Business is not fully configured (check Admin → Payment Settings: it must be enabled and have an API Key). Your order was still placed.');
            } else {
                $txn = genie_create_transaction($order);
                if ($txn['ok']) {
                    db()->prepare('UPDATE orders SET payment_status = \'pending\', payment_provider = \'genie\', payment_txn_id = ?, payment_url = ? WHERE id = ?')
                        ->execute([$txn['transaction_id'], $txn['url'], $order['id']]);
                    header('Location: ' . $txn['url']);
                    exit;
                }
                // Gateway rejected/unreachable — record it and show the real reason
                // rather than leaving the order stuck on "awaiting confirmation".
                db()->prepare('UPDATE orders SET payment_status = \'failed\', payment_provider = \'genie\' WHERE id = ?')
                    ->execute([$order['id']]);
                error_log('Genie checkout failed for order ' . $order['order_no'] . ': ' . ($txn['error'] ?? 'unknown'));
                flash('err', 'Could not start the online payment: ' . ($txn['error'] ?? 'unknown error') . ' Your order was still placed — you can arrange payment separately.');
            }
        }

        /* KOKO (Buy Now, Pay Later) — the order already exists as 'pending' /
           payment_status 'unpaid'; send the customer's browser straight to
           KOKO's hosted payment page via a signed auto-submitting form, since
           KOKO's API is a POST redirect rather than a JSON "create" call that
           returns a URL. koko_callback.php marks the order paid once KOKO
           confirms it (or sends the customer back here on failure/cancel). */
        if ($old['payment_method'] === 'koko' && $order) {
            if (!koko_is_configured()) {
                flash('err', 'KOKO (Buy Now, Pay Later) could not be started because it is not fully configured (check Admin → Payment Settings: enable it and fill in Merchant ID, API Key and Private Key). Your order was still placed.');
                redirect('/checkout.php?order=' . urlencode($result['order_no']));
            }
            $form = koko_build_redirect_form($order);
            if ($form['ok']) {
                db()->prepare("UPDATE orders SET payment_status = 'pending', payment_provider = 'koko' WHERE id = ?")
                    ->execute([$order['id']]);
                header('Content-Type: text/html; charset=UTF-8');
                echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
                    . '<title>Redirecting to KOKO…</title><style>body{font-family:Arial,Helvetica,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f7f7f7;color:#141414}'
                    . '.box{text-align:center}.spin{width:40px;height:40px;border:4px solid #e6e6e6;border-top-color:#141414;border-radius:50%;margin:0 auto 16px;animation:sp 0.8s linear infinite}@keyframes sp{to{transform:rotate(360deg)}}</style></head>'
                    . '<body><div class="box"><div class="spin"></div><p>Redirecting you to KOKO to complete your payment…</p>' . $form['html'] . '</div></body></html>';
                exit;
            }
            db()->prepare("UPDATE orders SET payment_status = 'failed', payment_provider = 'koko' WHERE id = ?")
                ->execute([$order['id']]);
            error_log('KOKO checkout failed for order ' . $order['order_no'] . ': ' . ($form['error'] ?? 'unknown'));
            flash('err', 'Could not start the KOKO payment: ' . ($form['error'] ?? 'unknown error') . ' Your order was still placed — you can arrange payment separately.');
        }

        redirect('/checkout.php?order=' . urlencode($result['order_no']));
    }

    $errors['form'] = $result['error'] ?? 'Something went wrong. Please try again.';
}

/* ---------- Confirmation view (?order=XXX, only for orders just placed in this session) ---------- */
$confirmedOrder = null;
if (!empty($_GET['order'])) {
    $orderNo = (string)$_GET['order'];
    if (!empty($_SESSION['recent_orders']) && in_array($orderNo, $_SESSION['recent_orders'], true)) {
        $confirmedOrder = get_order_by_no($orderNo);
    }
}

$cart = cart_summary();
$provinces = get_provinces();

$pageTitle = $confirmedOrder ? 'Order Confirmed — ' . site_display_name() : 'Checkout — ' . site_display_name();
require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/auth.css">

<main class="wrap">

<?php if ($m = flash('err')): ?>
  <div class="alert alert-error" style="margin:16px 0"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($m) ?></div>
<?php endif; ?>
<?php if ($m = flash('ok')): ?>
  <div class="alert alert-success" style="margin:16px 0"><i class="fa-solid fa-circle-check"></i> <?= e($m) ?></div>
<?php endif; ?>

<?php if ($confirmedOrder): ?>

  <section class="order-confirm">
    <div class="order-confirm-icon"><i class="fa-solid fa-circle-check"></i></div>
    <h1>Thank you, <?= e(explode(' ', $confirmedOrder['full_name'])[0]) ?>!</h1>
    <p class="order-confirm-sub">Your order has been placed. A confirmation email is on its way to <strong><?= e($confirmedOrder['email']) ?></strong>.</p>
    <div class="order-confirm-no">Order No. <strong><?= e($confirmedOrder['order_no']) ?></strong></div>

    <div class="order-summary-card">
      <?php foreach ($confirmedOrder['items'] as $it): ?>
      <div class="order-summary-line">
        <div class="order-summary-img">
          <?php if ($it['image_path']): ?><img src="<?= BASE_URL . e($it['image_path']) ?>" alt=""><?php else: ?><i class="fa-solid fa-image"></i><?php endif; ?>
        </div>
        <div class="order-summary-info">
          <span class="order-summary-name"><?= e($it['name']) ?></span>
          <span class="order-summary-qty">Qty: <?= (int)$it['qty'] ?></span>
        </div>
        <div class="order-summary-total">Rs. <?= number_format((float)$it['line_total'], 2) ?></div>
      </div>
      <?php endforeach; ?>
      <div class="order-summary-subtotal"><span>Subtotal</span><strong>Rs. <?= number_format((float)$confirmedOrder['subtotal'], 2) ?></strong></div>
      <div class="order-summary-subtotal"><span>Delivery Charge</span><strong><?= (float)($confirmedOrder['delivery_charge'] ?? 0) === 0.0 ? 'FREE' : 'Rs. ' . number_format((float)$confirmedOrder['delivery_charge'], 2) ?></strong></div>
      <div class="order-summary-subtotal order-summary-grand-total"><span>Total</span><strong>Rs. <?= number_format((float)$confirmedOrder['subtotal'] + (float)($confirmedOrder['delivery_charge'] ?? 0), 2) ?></strong></div>
    </div>

    <div class="order-confirm-details">
      <div><span>Delivery Address</span><p><?= nl2br(e($confirmedOrder['address'])) ?>, <?= e($confirmedOrder['city']) ?><?= !empty($confirmedOrder['province']) ? ', ' . e($confirmedOrder['province']) : '' ?></p></div>
      <div><span>Phone</span><p><?= e($confirmedOrder['phone']) ?></p></div>
      <div><span>Payment Method</span><p><?= e(['cod'=>'Cash on Delivery','bank_transfer'=>'Bank Transfer','koko'=>'KOKO — Buy Now, Pay Later','frimi'=>'FRIMI','card'=>'Card','genie'=>'Card (Genie Business)'][$confirmedOrder['payment_method']] ?? $confirmedOrder['payment_method']) ?></p></div>
      <?php if ($confirmedOrder['payment_method'] === 'genie'): ?>
      <div><span>Payment Status</span><p>
        <?php $ps = $confirmedOrder['payment_status'] ?? 'pending'; ?>
        <?= $ps === 'paid' ? '✅ Paid' : ($ps === 'failed' ? '⚠️ Payment not completed — please contact us or try again.' : '⏳ Awaiting payment confirmation from Genie Business.') ?>
      </p></div>
      <?php elseif ($confirmedOrder['payment_method'] === 'koko'): ?>
      <div><span>Payment Status</span><p>
        <?php $ps = $confirmedOrder['payment_status'] ?? 'pending'; ?>
        <?= $ps === 'paid' ? '✅ Paid via KOKO' : ($ps === 'failed' ? '⚠️ Payment not completed — please contact us or try again.' : '⏳ Awaiting payment confirmation from KOKO.') ?>
      </p></div>
      <?php endif; ?>
    </div>

    <div style="margin-top:24px;display:flex;gap:12px;flex-wrap:wrap">
      <a href="<?= BASE_URL ?>/" class="item-cta" style="display:inline-flex"><i class="fa-solid fa-arrow-left"></i> Continue Shopping</a>
      <a href="<?= BASE_URL ?>/track-order?order_no=<?= urlencode((string)$confirmedOrder['order_no']) ?>&email=<?= urlencode((string)$confirmedOrder['email']) ?>" class="btn btn-green" style="display:inline-flex"><i class="fa-solid fa-truck-fast"></i> Track Your Order</a>
    </div>
  </section>

<?php elseif (empty($cart['items'])): ?>

  <section style="padding:70px 20px;text-align:center">
    <i class="fa-solid fa-cart-shopping" style="font-size:44px;color:var(--muted);margin-bottom:16px;display:block"></i>
    <h1 style="font-size:26px;margin-bottom:10px">Your cart is empty</h1>
    <p style="color:var(--muted);margin-bottom:24px">Add something to your cart before checking out.</p>
    <a class="btn btn-green" href="<?= BASE_URL ?>/">Continue Shopping</a>
  </section>

<?php else: ?>

  <nav class="item-breadcrumb" aria-label="Breadcrumb">
    <a href="<?= BASE_URL ?>/">Home</a><span>/</span><span class="current">Checkout</span>
  </nav>

  <?php if (!empty($errors['form'])): ?>
    <div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($errors['form']) ?></div>
  <?php endif; ?>

  <form method="post" action="" class="checkout-grid" novalidate>
    <?= csrf_field() ?>

    <div class="checkout-form">
      <h2 class="checkout-h2">Delivery Details</h2>

      <div class="auth-grid">
        <div class="auth-field">
          <label for="full_name">Full Name</label>
          <input type="text" id="full_name" name="full_name" required maxlength="150" value="<?= e($old['full_name']) ?>" autofocus>
        </div>
        <div class="auth-field">
          <label for="phone">Phone Number</label>
          <input type="tel" id="phone" name="phone" required maxlength="30" placeholder="07XXXXXXXX" value="<?= e($old['phone']) ?>">
        </div>
      </div>

      <div class="auth-field">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email" required maxlength="150" value="<?= e($old['email']) ?>">
        <span class="hint">Your order confirmation will be sent here.</span>
      </div>

      <div class="auth-field">
        <label for="address">Delivery Address</label>
        <textarea id="address" name="address" rows="3" required maxlength="500"><?= e($old['address']) ?></textarea>
      </div>

      <div class="auth-grid">
        <div class="auth-field">
          <label for="province">Province</label>
          <select id="province" name="province" required>
            <option value="">Select Province</option>
            <?php foreach ($provinces as $p): ?>
              <option value="<?= e($p['name_en']) ?>" data-id="<?= (int)$p['id'] ?>" <?= $old['province'] === $p['name_en'] ? 'selected' : '' ?>><?= e($p['name_en']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="auth-field">
          <label for="city">City</label>
          <select id="city" name="city" required>
            <option value="">Select Province First</option>
            <?php if ($old['city'] !== ''): ?><option value="<?= e($old['city']) ?>" data-id="<?= (int)$old['city_id'] ?>" selected><?= e($old['city']) ?></option><?php endif; ?>
          </select>
          <input type="hidden" id="city_id" name="city_id" value="<?= e((string)$old['city_id']) ?>">
          <span class="hint" id="deliveryChargeHint" style="display:none"></span>
        </div>
      </div>

      <div class="auth-field">
        <label for="notes">Order Notes <span class="hint" style="font-weight:400">(optional)</span></label>
        <textarea id="notes" name="notes" rows="2" maxlength="500" placeholder="E.g. delivery instructions"><?= e($old['notes']) ?></textarea>
      </div>

      <h2 class="checkout-h2">Payment Method</h2>
      <div class="pay-options">
        <?php
          $payOpts = ['cod' => ['Cash on Delivery', 'fa-money-bill-wave'], 'bank_transfer' => ['Bank Transfer', 'fa-building-columns'], 'frimi' => ['FRIMI', 'fa-wallet']];
          if (genie_is_configured()) { $payOpts['genie'] = ['Credit / Debit Card (Genie Business)', 'fa-credit-card']; }
          $payOpts['koko'] = koko_is_configured()
            ? ['KOKO — Buy Now, Pay in 3', 'fa-wallet']
            : ['KOKO — Buy Now, Pay in 3 (contact us to arrange)', 'fa-wallet'];
        ?>
        <?php foreach ($payOpts as $val => $opt): ?>
        <label class="pay-option">
          <input type="radio" name="payment_method" value="<?= e($val) ?>" <?= $old['payment_method'] === $val ? 'checked' : '' ?>>
          <i class="fa-solid <?= $opt[1] ?>"></i> <?= e($opt[0]) ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <aside class="checkout-summary">
      <h2 class="checkout-h2">Order Summary</h2>
      <div class="order-summary-card" id="orderSummaryCard">
        <?php foreach ($cart['items'] as $it): ?>
        <div class="order-summary-line" data-price="<?= e((string)$it['price']) ?>" data-koko-price="<?= e((string)$it['koko_price']) ?>" data-qty="<?= (int)$it['qty'] ?>">
          <div class="order-summary-img">
            <?php if ($it['image']): ?><img src="<?= BASE_URL . e($it['image']) ?>" alt=""><?php else: ?><i class="fa-solid fa-image"></i><?php endif; ?>
          </div>
          <div class="order-summary-info">
            <span class="order-summary-name"><?= e($it['name']) ?></span>
            <span class="order-summary-qty">Qty: <?= (int)$it['qty'] ?></span>
          </div>
          <div class="order-summary-total" data-line-total>Rs. <?= number_format((float)$it['line_total'], 2) ?></div>
        </div>
        <?php endforeach; ?>
        <div class="order-summary-subtotal" id="normalSubtotalRow"><span>Subtotal</span><strong id="subtotalValue">Rs. <?= number_format((float)$cart['subtotal'], 2) ?></strong></div>
        <div class="order-summary-subtotal" id="deliveryChargeRow"><span>Delivery Charge</span><strong id="deliveryChargeValue">Select a city</strong></div>
        <div class="order-summary-subtotal order-summary-grand-total" id="normalTotalRow"><span>Total</span><strong id="orderTotalValue">Rs. <?= number_format((float)$cart['subtotal'], 2) ?></strong></div>
        <div class="order-summary-subtotal order-summary-grand-total koko-plan-row" id="kokoPlanRow" hidden>
          <span>3 Monthly Instalments of</span><strong id="kokoInstalmentValue">Rs. 0.00</strong>
        </div>
      </div>
      <p class="hint koko-plan-note" id="kokoPlanNote" hidden><i class="fa-solid fa-circle-info"></i> You've selected KOKO — Buy Now, Pay Later. Prices above reflect KOKO pricing, split into 3 equal monthly instalments (no upfront total shown, same as KOKO's own checkout).</p>
      <button type="submit" class="item-cta" style="width:100%;justify-content:center;margin-top:16px"><i class="fa-solid fa-lock"></i> Place Order</button>
      <p class="hint" style="text-align:center;margin-top:10px">By placing your order you agree to our Terms &amp; Conditions.</p>
    </aside>
  </form>

<?php endif; ?>

</main>

<?php if (!$confirmedOrder && !empty($cart['items'])): ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/location-select.js"></script>
<script>
$(function () {
  var subtotal = <?= (float)$cart['subtotal'] ?>;
  var kokoSubtotal = <?= (float)$cart['koko_subtotal'] ?>;
  var deliveryCharge = 0;

  function moneyFmt(n){ return 'Rs. ' + Number(n).toLocaleString('en-LK', {minimumFractionDigits:2, maximumFractionDigits:2}); }

  function isKokoSelected(){
    var el = document.querySelector('input[name="payment_method"]:checked');
    return !!el && el.value === 'koko';
  }

  /* Switches every line item + the summary between normal pricing and KOKO pricing
     (Admin -> Items -> "KOKO Price"), and swaps the grand Total for a "3 monthly
     instalments of Rs. X" line — no upfront total is shown when paying via KOKO,
     matching how KOKO's own checkout presents it. */
  function refreshSummaryDisplay(){
    var koko = isKokoSelected();

    document.querySelectorAll('#orderSummaryCard .order-summary-line').forEach(function(line){
      var price = parseFloat(line.dataset.price) || 0;
      var kokoPrice = parseFloat(line.dataset.kokoPrice) || price;
      var qty = parseInt(line.dataset.qty, 10) || 1;
      var total = (koko ? kokoPrice : price) * qty;
      var totalEl = line.querySelector('[data-line-total]');
      if (totalEl) totalEl.textContent = moneyFmt(total);
    });

    var effectiveSubtotal = koko ? kokoSubtotal : subtotal;
    document.getElementById('subtotalValue').textContent = moneyFmt(effectiveSubtotal);
    document.getElementById('orderTotalValue').textContent = moneyFmt(effectiveSubtotal + deliveryCharge);

    document.getElementById('normalTotalRow').hidden = koko;
    document.getElementById('kokoPlanRow').hidden = !koko;
    document.getElementById('kokoPlanNote').hidden = !koko;
    if (koko) {
      document.getElementById('kokoInstalmentValue').textContent = moneyFmt((effectiveSubtotal + deliveryCharge) / 3);
    }
  }

  function updateDeliveryDisplay(){
    var $selected = $('#city').find(':selected');
    var cityId = $selected.data('id');
    var charge = $selected.data('charge');

    document.getElementById('city_id').value = cityId || '';

    if (!cityId || charge === undefined || charge === '') {
      document.getElementById('deliveryChargeValue').textContent = 'Select a city';
      deliveryCharge = 0;
      refreshSummaryDisplay();
      return;
    }
    deliveryCharge = parseFloat(charge) || 0;
    document.getElementById('deliveryChargeValue').textContent = deliveryCharge === 0 ? 'FREE' : moneyFmt(deliveryCharge);
    refreshSummaryDisplay();
  }

  initProvinceCitySelect({
    provinceSelector: '#province',
    citySelector: '#city',
    baseUrl: '<?= BASE_URL ?>',
    selectedCityName: <?= json_encode($old['city']) ?>,
    withDeliveryCharge: true,
    onCitiesLoaded: updateDeliveryDisplay
  });

  $('#city').on('change', updateDeliveryDisplay);
  $(document).on('change', 'input[name="payment_method"]', refreshSummaryDisplay);
  updateDeliveryDisplay();
  refreshSummaryDisplay();
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
