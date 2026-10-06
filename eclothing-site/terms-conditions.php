<?php
/**
 * terms-conditions.php — Terms & Conditions (URL: /terms-conditions, mapped by .htaccess)
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

$pageTitle = 'Terms & Conditions — ' . site_display_name();
require __DIR__ . '/includes/header.php';
?>

<nav class="item-breadcrumb wrap">
  <a href="<?= BASE_URL ?>/">Home</a> <span>/</span> <span class="current">Terms &amp; Conditions</span>
</nav>

<section class="about-hero">
  <div class="wrap about-hero-inner no-banner">
    <h1>Terms &amp; Conditions</h1>
    <p>The rules for using <?= e($companyName) ?> and placing orders with us.</p>
  </div>
</section>

<section class="wrap about-content">
  <div class="about-content-inner">
    <p><em>Last updated: <?= date('F j, Y') ?></em></p>

    <p>These Terms &amp; Conditions ("Terms") govern your use of <?= e($companyName) ?>'s website and your
    purchase of any products through it. By accessing this site or placing an order, you agree to be
    bound by these Terms. Please read them carefully.</p>

    <h2>1. Orders &amp; Acceptance</h2>
    <p>Placing an order through our website is an offer to purchase. We reserve the right to accept or
    decline any order for any reason, including product availability, pricing errors, or suspected
    fraudulent activity. An order is only confirmed once you receive an order confirmation from us.</p>

    <h2>2. Pricing &amp; Availability</h2>
    <p>All prices are listed in Sri Lankan Rupees (LKR) unless otherwise stated and are subject to change
    without notice. While we make every effort to ensure pricing and stock information is accurate,
    errors may occasionally occur — if a listed price is incorrect, we will contact you before processing
    your order.</p>

    <h2>3. Payment</h2>
    <p>We accept Cash on Delivery, bank transfer, and online payment methods including card payments
    (via Genie Business) and Buy Now, Pay Later instalments (via KOKO), where enabled at checkout. Online
    payments are processed securely by our payment gateway partners; we do not store your full card
    details.</p>

    <h2>4. Delivery</h2>
    <p>We aim to deliver orders within the timeframes communicated at checkout or on the product page.
    Delivery times are estimates and not guaranteed — delays may occur due to courier, weather, or other
    circumstances outside our control. Delivery charges, where applicable, are shown at checkout before
    you confirm your order.</p>

    <h2>5. Buy Now, Pay Later (KOKO)</h2>
    <p>If you choose to pay via KOKO's instalment plan, your agreement for the instalment payments is
    directly with KOKO under their own terms and conditions, in addition to these Terms. Any issues with
    instalment billing should be raised with KOKO directly.</p>

    <h2>6. Cancellations &amp; Returns</h2>
    <p>For details on cancelling an order, returning a product, or requesting a refund, please see our
    <a href="<?= BASE_URL ?>/refund-policy">Refund &amp; Returns Policy</a>.</p>

    <h2>7. Product Descriptions</h2>
    <p>We try to describe and display our products as accurately as possible, but we do not warrant that
    product descriptions, images, colours, or other content are entirely error-free. Minor variations
    (e.g. colour differences due to screen display) may occur.</p>

    <h2>8. Account Responsibility</h2>
    <p>If you create an account with us, you are responsible for maintaining the confidentiality of your
    login details and for all activity under your account. Please notify us immediately of any
    unauthorised use.</p>

    <h2>9. Limitation of Liability</h2>
    <p>To the fullest extent permitted by law, <?= e($companyName) ?> shall not be liable for any indirect,
    incidental, or consequential damages arising from your use of this website or purchase of our
    products.</p>

    <h2>10. Governing Law</h2>
    <p>These Terms are governed by the laws of the Democratic Socialist Republic of Sri Lanka, without
    regard to its conflict of law principles.</p>

    <h2>11. Changes to These Terms</h2>
    <p>We may update these Terms from time to time. Continued use of the site after changes are posted
    constitutes acceptance of the revised Terms.</p>

    <h2>12. Contact Us</h2>
    <p>Questions about these Terms? Reach us at:</p>
    <ul>
      <?php if ($contactEmail): ?><li>Email: <a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a></li><?php endif; ?>
      <?php if ($contactPhone): ?><li>Phone: <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $contactPhone)) ?>"><?= e($contactPhone) ?></a></li><?php endif; ?>
      <li>Contact form: <a href="<?= BASE_URL ?>/contact-us">Contact Us</a></li>
    </ul>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
