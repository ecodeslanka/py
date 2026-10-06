<?php
/**
 * contact-us.php — Contact Us  (URL: /contact-us, mapped by .htaccess)
 * Shows the company address, contact numbers, emails, branches and social
 * links managed in Admin -> Site Configuration, plus a message form that
 * saves to `contact_messages` and best-effort emails the admin.
 */
require_once __DIR__ . '/config/config.php';

ensure_contact_messages_table();

$siteSettings = get_site_settings();
$phones       = get_all_contact_numbers();
$emailsList   = get_all_site_emails();
$branches     = get_all_branches();

$formValues = ['name' => '', 'email' => '', 'phone' => '', 'subject' => '', 'message' => ''];
$formError  = '';
$formSent   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $formValues['name']    = trim((string)($_POST['name'] ?? ''));
    $formValues['email']   = trim((string)($_POST['email'] ?? ''));
    $formValues['phone']   = trim((string)($_POST['phone'] ?? ''));
    $formValues['subject'] = trim((string)($_POST['subject'] ?? ''));
    $formValues['message'] = trim((string)($_POST['message'] ?? ''));

    if ($formValues['name'] === '' || mb_strlen($formValues['name']) > 150) {
        $formError = 'Please enter your name.';
    } elseif ($formValues['email'] === '' || !filter_var($formValues['email'], FILTER_VALIDATE_EMAIL)) {
        $formError = 'Please enter a valid email address.';
    } elseif ($formValues['message'] === '') {
        $formError = 'Please enter your message.';
    }

    if ($formError === '') {
        db()->prepare('INSERT INTO contact_messages (name, email, phone, subject, message) VALUES (?, ?, ?, ?, ?)')
            ->execute([
                $formValues['name'], $formValues['email'], $formValues['phone'] ?: null,
                $formValues['subject'] ?: null, $formValues['message'],
            ]);
        send_contact_notification_email($formValues); // best-effort — a mail failure never blocks the visitor
        $formSent   = true;
        $formValues = ['name' => '', 'email' => '', 'phone' => '', 'subject' => '', 'message' => ''];
    }
}

$pageTitle = 'Contact Us — ' . site_display_name();

require __DIR__ . '/includes/header.php';
?>

<nav class="item-breadcrumb wrap">
  <a href="<?= BASE_URL ?>/">Home</a> <span>/</span> <span class="current">Contact Us</span>
</nav>

<section class="contact-hero">
  <div class="wrap">
    <h1>Contact Us</h1>
    <p>We'd love to hear from you — reach out any time.</p>
  </div>
</section>

<section class="wrap contact-wrap">

  <div class="contact-info-col">

    <?php if (!empty($siteSettings['address'])): ?>
    <div class="contact-info-card">
      <div class="contact-info-ic"><i class="fa-solid fa-location-dot"></i></div>
      <div>
        <h4>Our Address</h4>
        <p><?= nl2br(e($siteSettings['address'])) ?></p>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($phones): ?>
    <div class="contact-info-card">
      <div class="contact-info-ic"><i class="fa-solid fa-phone"></i></div>
      <div>
        <h4>Call Us</h4>
        <?php foreach ($phones as $ph): ?>
          <p><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $ph['phone'])) ?>"><?= e($ph['phone']) ?></a><?php if ($ph['description']): ?> <span class="contact-tag"><?= e($ph['description']) ?></span><?php endif; ?></p>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($emailsList): ?>
    <div class="contact-info-card">
      <div class="contact-info-ic"><i class="fa-solid fa-envelope"></i></div>
      <div>
        <h4>Email Us</h4>
        <?php foreach ($emailsList as $em): ?>
          <p><a href="mailto:<?= e($em['email']) ?>"><?= e($em['email']) ?></a><?php if ($em['description']): ?> <span class="contact-tag"><?= e($em['description']) ?></span><?php endif; ?></p>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php
      $__socials = [
        'facebook_url'  => ['fa-brands fa-facebook-f', 'Facebook'],
        'instagram_url' => ['fa-brands fa-instagram', 'Instagram'],
        'tiktok_url'    => ['fa-brands fa-tiktok', 'TikTok'],
        'youtube_url'   => ['fa-brands fa-youtube', 'YouTube'],
        'twitter_url'   => ['fa-brands fa-x-twitter', 'X (Twitter)'],
        'linkedin_url'  => ['fa-brands fa-linkedin-in', 'LinkedIn'],
      ];
      $__hasSocial = false;
      foreach ($__socials as $__k => $__v) { if (!empty($siteSettings[$__k])) { $__hasSocial = true; break; } }
    ?>
    <?php if ($__hasSocial): ?>
    <div class="contact-info-card">
      <div class="contact-info-ic"><i class="fa-solid fa-share-nodes"></i></div>
      <div>
        <h4>Follow Us</h4>
        <div class="contact-social">
          <?php foreach ($__socials as $__k => [$__ic, $__label]): if (empty($siteSettings[$__k])) continue; ?>
            <a href="<?= e($siteSettings[$__k]) ?>" target="_blank" rel="noopener" aria-label="<?= e($__label) ?>"><i class="<?= $__ic ?>"></i></a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($branches): ?>
    <div class="contact-info-card">
      <div class="contact-info-ic"><i class="fa-solid fa-building"></i></div>
      <div>
        <h4>Our Branches</h4>
        <?php foreach ($branches as $b): ?>
          <div class="contact-branch">
            <strong><?= e($b['name']) ?><?= $b['is_main'] ? ' <span class="contact-tag main">Main</span>' : '' ?></strong>
            <p><?= nl2br(e($b['address'])) ?></p>
            <?php if ($b['details']): ?><p class="contact-branch-details"><?= e($b['details']) ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <div class="contact-form-col">
    <div class="contact-form-card">
      <h3>Send Us a Message</h3>
      <p class="contact-form-sub">Fill in the form and our team will get back to you shortly.</p>

      <?php if ($formSent): ?>
        <div class="alert alert-success" style="margin-bottom:18px"><i class="fa-solid fa-circle-check"></i> Thank you — your message has been sent. We'll be in touch soon.</div>
      <?php elseif ($formError): ?>
        <div class="alert alert-error" style="margin-bottom:18px"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($formError) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="contact-form-grid">
          <label>Your Name
            <input type="text" name="name" required maxlength="150" value="<?= e($formValues['name']) ?>" placeholder="e.g. Nimal Perera">
          </label>
          <label>Your Email
            <input type="email" name="email" required maxlength="150" value="<?= e($formValues['email']) ?>" placeholder="you@example.com">
          </label>
          <label>Phone (optional)
            <input type="text" name="phone" maxlength="40" value="<?= e($formValues['phone']) ?>" placeholder="+94 77 123 4567">
          </label>
          <label>Subject (optional)
            <input type="text" name="subject" maxlength="200" value="<?= e($formValues['subject']) ?>" placeholder="How can we help?">
          </label>
          <label style="grid-column:1/-1">Message
            <textarea name="message" required rows="6" placeholder="Write your message here..."><?= e($formValues['message']) ?></textarea>
          </label>
        </div>
        <button type="submit" class="btn btn-green contact-submit"><i class="fa-solid fa-paper-plane"></i> Send Message</button>
      </form>
    </div>

    <?php if (!empty($siteSettings['address'])): ?>
    <div class="contact-map">
      <iframe
        src="https://www.google.com/maps?q=<?= urlencode($siteSettings['address']) ?>&output=embed"
        width="100%" height="320" style="border:0" allowfullscreen loading="lazy"
        referrerpolicy="no-referrer-when-downgrade" title="Our location"></iframe>
    </div>
    <?php endif; ?>
  </div>

</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
