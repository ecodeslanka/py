<?php
$__site_name = setting('site_name', 'The Wedded');
$__logo = setting('logo', '');
$__logo_link = setting('logo_link', '/') ?: '/';
$__phone = setting('phone', '');
$__whatsapp = setting('whatsapp', '');
$__facebook = setting('facebook_url', '');
$__instagram = setting('instagram_url', '');
$__tiktok = setting('tiktok_url', '');
$__email = setting('email', '');
$__address = setting('address', '');
?>
</main>

<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">
      <div class="footer-brand">
        <a class="footer-brand-logo" href="<?= h($__logo_link) ?>">
          <?php if ($__logo): ?>
            <img src="<?= h(image_url($__logo)) ?>" alt="<?= h($__site_name) ?>">
          <?php else: ?>
            <span class="logo-text serif"><?= h($__site_name) ?></span>
          <?php endif; ?>
        </a>
        <p><?= h(setting('footer_about', '')) ?></p>
      </div>
      <div class="footer-col">
        <h4>Explore</h4>
        <a href="<?= h(base_url()) ?>/index.php">Home</a>
        <a href="<?= h(base_url()) ?>/about.php">About</a>
        <a href="<?= h(base_url()) ?>/collection.php">Collection</a>
        <a href="<?= h(base_url()) ?>/contact.php">Contact</a>
      </div>
      <div class="footer-col">
        <h4>Connect</h4>
        <?php if ($__facebook): ?><a href="<?= h($__facebook) ?>" target="_blank" rel="noopener noreferrer">Facebook</a><?php endif; ?>
        <?php if ($__instagram): ?><a href="<?= h($__instagram) ?>" target="_blank" rel="noopener noreferrer">Instagram</a><?php endif; ?>
        <?php if ($__tiktok): ?><a href="<?= h($__tiktok) ?>" target="_blank" rel="noopener noreferrer">TikTok</a><?php endif; ?>
        <?php if ($__whatsapp): ?><a href="<?= h(whatsapp_link($__whatsapp)) ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a><?php endif; ?>
        <?php if ($__phone): ?><a href="tel:<?= h(preg_replace('/\s+/', '', $__phone)) ?>">Call: <?= h($__phone) ?></a><?php endif; ?>
        <?php if ($__email): ?><a href="mailto:<?= h($__email) ?>">Email: <?= h($__email) ?></a><?php endif; ?>
      </div>
      <div class="footer-col">
        <h4>Location</h4>
        <span style="font-size:10px;color:rgba(239,228,214,.65)"><?= h($__address) ?></span>
      </div>
    </div>
    <div class="footer-bottom">
      <span>&copy; <?= date('Y') ?> <?= h(strtoupper($__site_name)) ?>. ALL RIGHTS RESERVED.</span>
      <span>Wedding Photography · <?= h($__address) ?></span>
    </div>
  </div>
</footer>

<?php if ($__whatsapp): ?>
<a class="whatsapp-float" href="<?= h(whatsapp_link($__whatsapp, 'Hi! I would like to enquire about wedding photography.')) ?>" target="_blank" rel="noopener noreferrer" aria-label="Chat on WhatsApp">
  <svg viewBox="0 0 32 32"><path d="M16 3C9.4 3 4 8.4 4 15c0 2.4.7 4.7 2 6.6L4 29l7.6-2c1.9 1 4 1.6 6.4 1.6 6.6 0 12-5.4 12-12S22.6 3 16 3zm0 21.8c-2 0-3.9-.5-5.6-1.5l-.4-.2-4.2 1.1 1.1-4.1-.3-.4A9.7 9.7 0 0 1 5.3 15c0-5.9 4.8-10.7 10.7-10.7S26.7 9.1 26.7 15 21.9 24.8 16 24.8zm5.9-8c-.3-.2-1.9-.9-2.2-1s-.5-.2-.7.2-.8 1-1 1.2-.4.2-.7.1a8 8 0 0 1-2.4-1.5 9 9 0 0 1-1.6-2c-.2-.3 0-.5.1-.6l.4-.5.3-.4a.6.6 0 0 0 0-.5c-.1-.2-.7-1.7-1-2.3s-.5-.5-.7-.5h-.6a1.2 1.2 0 0 0-.8.4 3.6 3.6 0 0 0-1.1 2.7c0 1.6 1.1 3.1 1.3 3.3.2.3 2.2 3.4 5.4 4.6.7.3 1.3.5 1.8.6a4.3 4.3 0 0 0 2-.1c.6-.2 1.9-.8 2.2-1.5s.3-1.4.2-1.5-.3-.2-.6-.4z"/></svg>
</a>
<?php endif; ?>

<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Image preview">
  <button id="lightboxClose" aria-label="Close image preview">&times;</button>
  <img id="lightboxImage" src="" alt="">
</div>

<script src="<?= h(base_url()) ?>/assets/js/main.js"></script>
</body>
</html>
