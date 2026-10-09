<?php
require_once __DIR__ . '/includes/bootstrap.php';

$formStatus = null; // ['type' => 'success'|'error', 'message' => '...']

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $date = trim($_POST['date'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $service = trim($_POST['service'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $formStatus = ['type' => 'error', 'message' => 'Please enter your name and a valid email address.'];
    } else {
        $stmt = db()->prepare("INSERT INTO inquiries (name,email,phone,wedding_date,location,service,message)
                                VALUES (:n,:e,:p,:d,:l,:s,:m)");
        $stmt->execute([
            ':n' => $name, ':e' => $email, ':p' => $phone, ':d' => $date,
            ':l' => $location, ':s' => $service, ':m' => $message,
        ]);
        $formStatus = ['type' => 'success', 'message' => 'Thank you! Your inquiry has been received — we will be in touch soon.'];
    }
}

$page_title = 'Contact Us';
$solid_header = true;
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="container">
    <div class="eyebrow reveal">Get in touch</div>
    <h1 class="reveal">Contact Us</h1>
  </div>
</section>

<section class="section contact">
  <div class="container contact-grid">
    <div class="contact-copy reveal">
      <div class="eyebrow">Start your story</div>
      <h2>Let's create something timeless.</h2>
      <p>Planning your wedding? We'd love to hear your story, learn about your plans and see how we can be part of your day.</p>
      <div class="contact-meta">
        <span>Based in <?= h(setting('address')) ?></span>
        <?php if (setting('phone')): ?><a href="tel:<?= h(preg_replace('/\s+/', '', setting('phone'))) ?>">Call: <?= h(setting('phone')) ?></a><?php endif; ?>
        <?php if (setting('whatsapp')): ?><a href="<?= h(whatsapp_link(setting('whatsapp'))) ?>" target="_blank" rel="noopener noreferrer">WhatsApp: <?= h(setting('whatsapp')) ?></a><?php endif; ?>
        <?php if (setting('email')): ?><a href="mailto:<?= h(setting('email')) ?>">Email: <?= h(setting('email')) ?></a><?php endif; ?>
        <?php if (setting('facebook_url')): ?><a href="<?= h(setting('facebook_url')) ?>" target="_blank" rel="noopener noreferrer">Facebook</a><?php endif; ?>
        <?php if (setting('instagram_url')): ?><a href="<?= h(setting('instagram_url')) ?>" target="_blank" rel="noopener noreferrer">Instagram</a><?php endif; ?>
        <?php if (setting('tiktok_url')): ?><a href="<?= h(setting('tiktok_url')) ?>" target="_blank" rel="noopener noreferrer">TikTok</a><?php endif; ?>
      </div>
    </div>
    <form class="reveal" id="inquiryForm" method="post" action="<?= h(base_url()) ?>/contact.php">
      <?= csrf_field() ?>
      <div class="field"><label for="name">Name</label><input id="name" name="name" required autocomplete="name" value="<?= h($_POST['name'] ?? '') ?>"></div>
      <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required autocomplete="email" value="<?= h($_POST['email'] ?? '') ?>"></div>
      <div class="field"><label for="phone">Phone / WhatsApp</label><input id="phone" name="phone" autocomplete="tel" value="<?= h($_POST['phone'] ?? '') ?>"></div>
      <div class="field"><label for="date">Wedding date</label><input id="date" name="date" type="date" value="<?= h($_POST['date'] ?? '') ?>"></div>
      <div class="field"><label for="location">Wedding location</label><input id="location" name="location" value="<?= h($_POST['location'] ?? '') ?>"></div>
      <div class="field"><label for="service">Service required</label>
        <select id="service" name="service">
          <option value="">Select a service</option>
          <?php foreach (get_categories() as $cat): ?>
            <option <?= (($_POST['service'] ?? '') === $cat['name']) ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field full"><label for="message">Tell us about your plans</label><textarea id="message" name="message"><?= h($_POST['message'] ?? '') ?></textarea></div>
      <div class="form-actions">
        <button class="btn light" type="submit">Send Inquiry</button>
        <?php if (setting('whatsapp')): ?>
          <a class="btn light" href="<?= h(whatsapp_link(setting('whatsapp'))) ?>" target="_blank" rel="noopener noreferrer">WhatsApp Us</a>
        <?php endif; ?>
      </div>
      <?php if ($formStatus): ?>
        <p class="form-note <?= h($formStatus['type']) ?>"><?= h($formStatus['message']) ?></p>
      <?php else: ?>
        <p class="form-note">We typically respond within 24 hours.</p>
      <?php endif; ?>
    </form>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
