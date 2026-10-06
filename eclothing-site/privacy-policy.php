<?php
/**
 * privacy-policy.php — Privacy Policy (URL: /privacy-policy, mapped by .htaccess)
 * Standard, editable boilerplate. Company name, contact details, and address
 * are pulled live from Admin -> Site Configuration, so this page always
 * reflects whatever the store owner has set there.
 */
require_once __DIR__ . '/config/config.php';

$siteSettings = get_site_settings();
$companyName  = $siteSettings['company_name'] ?: SITE_NAME;
$siteEmails   = get_all_site_emails();
$sitePhones   = get_all_contact_numbers();
$contactEmail = $siteEmails[0]['email'] ?? null;
$contactPhone = $sitePhones[0]['phone'] ?? null;

$pageTitle = 'Privacy Policy — ' . site_display_name();
require __DIR__ . '/includes/header.php';
?>

<nav class="item-breadcrumb wrap">
  <a href="<?= BASE_URL ?>/">Home</a> <span>/</span> <span class="current">Privacy Policy</span>
</nav>

<section class="about-hero">
  <div class="wrap about-hero-inner no-banner">
    <h1>Privacy Policy</h1>
    <p>How <?= e($companyName) ?> collects, uses, and protects your information.</p>
  </div>
</section>

<section class="wrap about-content">
  <div class="about-content-inner">
    <p><em>Last updated: <?= date('F j, Y') ?></em></p>

    <p><?= e($companyName) ?> ("we", "us", or "our") operates this website. This Privacy Policy explains
    what information we collect when you visit or shop with us, how we use it, and the choices you have.
    By using this site, you agree to the collection and use of information as described here.</p>

    <h2>1. Information We Collect</h2>
    <p>We collect information you give us directly, such as your name, email address, phone number,
    delivery address, and order details when you place an order, create an account, or contact us.
    We also automatically collect certain technical information — like your IP address, browser type,
    and pages visited — to help us operate and improve the site.</p>

    <h2>2. How We Use Your Information</h2>
    <ul>
      <li>To process and deliver your orders, and to keep you updated on their status.</li>
      <li>To communicate with you about your account, orders, or customer support requests.</li>
      <li>To process payments securely through our payment partners.</li>
      <li>To improve our website, products, and customer experience.</li>
      <li>To send you promotional offers or updates, where you have opted in — you can unsubscribe at any time.</li>
      <li>To detect, prevent, and address fraud, abuse, or technical issues.</li>
    </ul>

    <h2>3. Sharing Your Information</h2>
    <p>We do not sell your personal information. We only share it with trusted third parties where
    necessary to run our business — for example, delivery/courier partners to fulfil your order, and
    payment gateways to process your payment securely. These partners are only given the information
    they need to perform their service.</p>

    <h2>4. Payment Information</h2>
    <p>Card and online payment details are processed directly by our payment gateway partners (such as
    Genie Business and KOKO) through their own secure, PCI-compliant systems. We do not store your full
    card details on our servers.</p>

    <h2>5. Cookies</h2>
    <p>We use cookies and similar technologies to keep you logged in, remember your cart, and understand
    how visitors use our site. You can control cookies through your browser settings, though disabling
    them may affect some site features (such as staying logged in or the shopping cart).</p>

    <h2>6. Data Security</h2>
    <p>We take reasonable technical and organisational measures to protect your personal information from
    unauthorised access, loss, or misuse. However, no method of transmission over the internet is 100%
    secure, and we cannot guarantee absolute security.</p>

    <h2>7. Your Rights</h2>
    <p>You can request access to, correction of, or deletion of your personal information at any time by
    contacting us using the details below. You may also opt out of marketing communications at any time.</p>

    <h2>8. Children's Privacy</h2>
    <p>Our services are not directed at children under 13, and we do not knowingly collect personal
    information from children.</p>

    <h2>9. Changes to This Policy</h2>
    <p>We may update this Privacy Policy from time to time. Changes will be posted on this page with an
    updated "Last updated" date.</p>

    <h2>10. Contact Us</h2>
    <p>If you have any questions about this Privacy Policy, please contact us:</p>
    <ul>
      <?php if ($contactEmail): ?><li>Email: <a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a></li><?php endif; ?>
      <?php if ($contactPhone): ?><li>Phone: <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $contactPhone)) ?>"><?= e($contactPhone) ?></a></li><?php endif; ?>
      <?php if (!empty($siteSettings['address'])): ?><li>Address: <?= nl2br(e($siteSettings['address'])) ?></li><?php endif; ?>
      <li>Contact form: <a href="<?= BASE_URL ?>/contact-us">Contact Us</a></li>
    </ul>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
