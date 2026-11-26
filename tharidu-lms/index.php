<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$batches = rows('SELECT * FROM batches WHERE active=1 ORDER BY sort_order, id');
$slides = rows('SELECT * FROM slides ORDER BY sort_order, id');
$groups = rows('SELECT * FROM wa_groups ORDER BY sort_order, id');
$wa = preg_replace('/\D/', '', setting('whatsapp'));
$socials = ['youtube' => ['youtube', '#ff0000'], 'facebook' => ['facebook', '#1877f2'], 'instagram' => ['instagram', '#e1306c'], 'tiktok' => ['music-2', '#111']];
head_html('Home'); site_nav(); ?>
<header class="band hero" id="home"><div class="wrap">
  <div>
    <span class="tag"><i data-lucide="flask-conical"></i>G.C.E. Advanced Level Chemistry</span>
    <div class="kicker">GENUINE CHEMISTRY</div>
    <h1><span class="grad-r">Chemistry</span><br>made <span class="grad-b">clear.</span></h1>
    <div class="si-name si">තරිඳු කේ. විජේකෝන්</div>
    <span class="quali"><i data-lucide="graduation-cap"></i>Tharidu K. Wijekoon · BSc (Chemistry, Physics)</span>
    <p class="lead"><?= e(setting('tagline', 'Theory, paper classes and online revision.')) ?> Theory, paper and online classes for A/L students — in Kegalle, Kurunegala and online.</p>
    <div class="hero-btns">
      <a class="btn btn-r" href="<?= url('register') ?>"><i data-lucide="user-plus"></i>Create student account</a>
      <a class="btn btn-b" href="#batches"><i data-lucide="layers"></i>See batches</a>
    </div>
  </div>
  <div class="stage">
    <?php if ($slides): ?>
      <div class="slider"><?php foreach ($slides as $i => $s): ?>
        <div class="slide<?= $i === 0 ? ' on' : '' ?>"><img src="<?= img($s['image']) ?>" alt="<?= e($s['caption']) ?>"><?php if ($s['caption']): ?><div class="cap"><?= e($s['caption']) ?></div><?php endif; ?></div>
      <?php endforeach; ?></div>
    <?php else: ?><div class="orb"></div><img src="<?= url('assets/img/coat.webp') ?>" alt="Tharidu Wijekoon" width="900" height="1100"><?php endif; ?>
  </div>
</div></header>

<section class="facts"><div class="wrap">
  <div class="fact"><i data-lucide="graduation-cap"></i><div><strong>BSc</strong><span>Chemistry &amp; Physics</span></div></div>
  <div class="fact"><i data-lucide="map-pin"></i><div><strong>Physical classes</strong><span>Senara · Kegalle & more</span></div></div>
  <div class="fact"><i data-lucide="monitor-play"></i><div><strong>Online classes</strong><span>Zoom + recordings</span></div></div>
  <div class="fact"><i data-lucide="message-circle"></i><div><strong>Direct support</strong><span>On WhatsApp</span></div></div>
</div></section>

<section class="band" id="batches"><div class="wrap">
  <div class="head center"><span class="tag"><i data-lucide="sparkles"></i>Batches open</span>
    <h2>Discover <span class="grad-r">Chemistry's</span> Wonder</h2><p class="lead">Choose your A/L batch and reserve your seat.</p></div>
  <div class="batches">
  <?php foreach ($batches as $b): ?>
    <div class="batch"><div class="cv" style="background-image:url('<?= img($b['cover_image']) ?>')"><b><?= e($b['name']) ?></b></div>
      <div class="bb"><span><?= (int)val("SELECT COUNT(*) FROM lessons WHERE batch_id=? AND active=1", [$b['id']]) ?> classes</span>
      <a class="btn btn-r btn-sm" href="<?= url('register?batch=' . $b['id']) ?>">Join now <i data-lucide="arrow-right"></i></a></div></div>
  <?php endforeach; if (!$batches) echo '<p class="empty">Batches coming soon.</p>'; ?>
  </div>
</div></section>

<section class="band about" id="about" style="padding-top:0"><div class="wrap">
  <div class="ph"><img src="<?= img(setting('about_image') ?: null, 'assets/img/polo.webp') ?>" alt="About" width="900" height="985"></div>
  <div><span class="tag"><i data-lucide="user"></i>About us</span>
    <h2><?= e(setting('about_title', 'About us')) ?></h2><br>
    <div class="prose"><?= e(setting('about_text')) ?></div><br>
    <a class="btn btn-r" href="<?= url('register') ?>"><i data-lucide="rocket"></i>Enroll now</a></div>
</div></section>

<?php if ($groups): ?>
<section class="band" style="padding-top:0"><div class="wrap"><div class="joinbox">
  <div><h2>Join our WhatsApp groups</h2><p>Class updates, notices and announcements — straight to your phone.</p>
  <div class="btns"><?php foreach ($groups as $g): ?><a class="btn btn-w" href="<?= e($g['url']) ?>" target="_blank" rel="noopener"><i data-lucide="message-circle"></i><?= e($g['label']) ?></a><?php endforeach; ?></div></div>
  <img src="<?= url('assets/img/plaid.webp') ?>" alt="">
</div></div></section>
<?php endif; ?>

<section class="band" id="contact" style="padding-top:0"><div class="wrap">
  <div class="head center"><span class="tag"><i data-lucide="phone"></i>Contact</span><h2>Reserve your <span class="grad-r">seat</span></h2></div>
  <div class="cgrid">
    <?php if (setting('phone')): ?><a class="cinfo" href="tel:<?= e(setting('phone')) ?>"><span class="ib" style="background:var(--red)"><i data-lucide="phone"></i></span><span><small>Call</small><b><?= e(setting('phone')) ?></b></span></a><?php endif; ?>
    <?php if ($wa): ?><a class="cinfo" href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener"><span class="ib" style="background:var(--wa)"><i data-lucide="message-circle"></i></span><span><small>WhatsApp</small><b><?= e(setting('phone') ?: $wa) ?></b></span></a><?php endif; ?>
    <?php foreach ($socials as $k => [$ic, $col]): if (setting($k)): ?>
      <a class="cinfo" href="<?= e(setting($k)) ?>" target="_blank" rel="noopener"><span class="ib" style="background:<?= $col ?>"><i data-lucide="<?= $ic ?>"></i></span><span><small>Follow on</small><b><?= ucfirst($k) ?></b></span></a>
    <?php endif; endforeach; ?>
  </div>
</div></section>
<footer><div><?= e(setting('site_name', SITE_NAME)) ?> · <?= date('Y') ?></div>
<div style="margin-top:8px"><a href="<?= url('login') ?>">Student login</a><a href="<?= url('register') ?>">Register</a></div></footer>
<?php foot_html();
