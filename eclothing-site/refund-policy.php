<?php
/**
 * refund-policy.php — Refund & Returns Policy (URL: /refund-policy, mapped by .htaccess)
 * Standard, editable boilerplate. Company name and contact details are
 * pulled live from Admin -> Site Configuration.
 */
require_once __DIR__ . '/config/config.php';

$siteSettings = get_site_settings();
$companyName  = $siteSettings['company_name'] ?: SITE_NAME;
$siteEmails   = get_all_site_emails();
$sitePhones   = get_all_contact_numbers();
$contactEmail = $siteEmails[0]['email'] ?? null;
$contactPhone = $sitePhones[0]['phone'] ?? null;

$pageTitle = 'Refund & Returns Policy — ' . site_display_name();
require __DIR__ . '/includes/header.php';
?>

<nav class="item-breadcrumb wrap">
  <a href="<?= BASE_URL ?>/">Home</a> <span>/</span> <span class="current">Refund &amp; Returns Policy</span>
</nav>

<section class="about-hero">
  <div class="wrap about-hero-inner no-banner">
    <h1>Refund &amp; Returns Policy</h1>
    <p>How cancellations, returns, and refunds work at <?= e($companyName) ?>.</p>
  </div>
</section>

<section class="wrap about-content">
  <div class="about-content-inner">
    <p><em>Last updated: <?= date('F j, Y') ?></em></p>

    <p>We want you to be happy with your purchase. This policy explains how order cancellations, product
    returns, and refunds are handled at <?= e($companyName) ?>.</p>

    <h2>1. Order Cancellations</h2>
    <p>You may request to cancel an order before it has been dispatched by contacting us as soon as
    possible with your order number. Once an order has been dispatched or is out for delivery, it can no
    longer be cancelled — you may instead choose to return the item after delivery, subject to the terms
    below.</p>

    <h2>2. Eligibility for Returns</h2>
    <p>To be eligible for a return, an item must generally be:</p>
    <ul>
      <li>Reported to us within <strong>7 days</strong> of delivery.</li>
      <li>Unused, in its original condition, and in the original packaging.</li>
      <li>Accompanied by proof of purchase (order number, invoice, or confirmation email).</li>
    </ul>
    <p>Some items may not be eligible for return — for example, perishable goods, personal-care items, or
    products marked as final sale on the product page. Where an item is excluded from returns, this will
    be noted on its product page.</p>

    <h2>3. Damaged or Incorrect Items</h2>
    <p>If you receive a damaged, defective, or incorrect item, please contact us within 48 hours of
    delivery with photos of the item and packaging. We will arrange a replacement, exchange, or refund at
    no extra cost to you.</p>

    <h2>4. How to Start a Return</h2>
    <p>To start a return, contact us with your order number and the reason for the return. We'll confirm
    whether the item is eligible and provide instructions for returning it (including pickup or drop-off
    arrangements, where applicable).</p>

    <h2>5. Refunds</h2>
    <p>Once we receive and inspect your returned item, we'll notify you of the approval status of your
    refund. If approved:</p>
    <ul>
      <li><strong>Cash on Delivery orders</strong> are refunded via bank transfer to an account you provide.</li>
      <li><strong>Card payments (Dialog Pay)</strong> are refunded to the original card, and may take
        several business days to appear on your statement depending on your bank.</li>
      <li><strong>KOKO Buy Now, Pay Later orders</strong> are refunded through KOKO according to their own
        refund process for instalment plans — any remaining instalments will be adjusted or cancelled by
        KOKO once the refund is confirmed.</li>
    </ul>

    <h2>6. Delivery Charges</h2>
    <p>Original delivery charges are non-refundable unless the return is due to our error (e.g. a wrong
    or damaged item was sent). Where you are responsible for returning an item that is simply unwanted,
    return shipping costs are borne by the customer unless otherwise agreed.</p>

    <h2>7. Exchanges</h2>
    <p>If you'd prefer an exchange (e.g. a different size or colour) rather than a refund, let us know
    when you contact us — we'll arrange this where the replacement item is in stock.</p>

    <h2>8. Contact Us</h2>
    <p>For any cancellation, return, or refund request, please reach out to us:</p>
    <ul>
      <?php if ($contactEmail): ?><li>Email: <a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a></li><?php endif; ?>
      <?php if ($contactPhone): ?><li>Phone: <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $contactPhone)) ?>"><?= e($contactPhone) ?></a></li><?php endif; ?>
      <li>Contact form: <a href="<?= BASE_URL ?>/contact-us">Contact Us</a></li>
      <li>Or <a href="<?= BASE_URL ?>/track-order">track your order</a> for its current status.</li>
    </ul>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
