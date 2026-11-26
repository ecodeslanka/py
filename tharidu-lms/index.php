<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/home.php';
$H = home();
$u = me();
$batches = rows('SELECT b.*,(SELECT COUNT(*) FROM lessons l WHERE l.batch_id=b.id AND l.active=1) lc FROM batches b WHERE b.active=1 ORDER BY b.sort_order,b.id');
$classes = rows('SELECT name FROM classes WHERE active=1 ORDER BY sort_order,id');
$slides = rows('SELECT * FROM slides ORDER BY sort_order,id');
$groups = rows('SELECT * FROM wa_groups ORDER BY sort_order,id');
$wa = preg_replace('/\D/', '', setting('whatsapp', '94703957020'));
$phone = setting('phone', '070 395 70 20');
$socials = ['youtube' => ['youtube', '#ff0000'], 'facebook' => ['facebook', '#1877f2'], 'instagram' => ['instagram', '#e1306c'], 'tiktok' => ['music-2', '#111']];
?>
<!DOCTYPE html>
<html lang="si" data-mode="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#ff2a40">
<title><?= e(setting('site_name', SITE_NAME)) ?></title>
<meta name="description" content="<?= e($H['lead']) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Noto+Sans+Sinhala:wght@500;700;900&display=swap" rel="stylesheet">
<script src="<?= asset('js/lucide.min.js') ?>"></script>
<link rel="stylesheet" href="<?= asset('css/home.css') ?>">
</head>
<body>

<nav class="topnav">
  <div class="wrap">
    <a class="logo" href="#home"><b>TW</b><span>Tharidu Wijekoon<small>Genuine Chemistry</small></span></a>
    <div class="links">
      <a href="#home" class="on">Home</a><a href="#about">About</a><a href="#batches">Batches</a><a href="#classes">Classes</a><a href="#offer">What we offer</a><a href="#contact">Contact</a>
    </div>
    <a class="btn btn-o nav-login" href="<?= url($u ? ($u['role']==='admin'?'admin/':'dashboard') : 'login') ?>"><?= $u ? 'My account' : 'Login' ?></a>
    <button class="mode" id="mode" type="button" aria-label="Switch to dark mode"><i data-lucide="moon" class="sun"></i><i data-lucide="sun" class="moon"></i></button>
    <?php if ($u): ?><a class="btn btn-o nav-cta" href="<?= url($u['role']==='admin'?'admin/':'dashboard') ?>"><i data-lucide="layout-dashboard"></i><?= $u['role']==='admin'?'Admin':'My lessons' ?></a><a class="btn btn-r nav-cta" href="<?= url('logout') ?>"><i data-lucide="log-out"></i>Logout</a>
    <?php else: ?><a class="btn btn-o nav-cta" href="<?= url('login') ?>"><i data-lucide="log-in"></i>Login</a><a class="btn btn-r nav-cta" href="<?= url('register') ?>"><i data-lucide="user-plus"></i>Join now</a><?php endif; ?>
  </div>
</nav>

<header class="band hero" id="home">
  <div class="wrap">
    <div class="hero-copy">
      <span class="tag"><i data-lucide="flask-conical"></i><?= e($H['tag']) ?></span>
      <div class="kicker"><?= e($H['kicker']) ?></div>
      <h1><span class="grad-r"><?= e($H['h1_red']) ?></span><br><?= e($H['h1_mid']) ?> <span class="grad-b"><?= e($H['h1_blue']) ?></span></h1>
      <div class="si-name si"><?= e($H['si_name']) ?></div>
      <span class="quali"><i data-lucide="graduation-cap"></i><?= e($H['quali']) ?></span>
      <p class="lead"><?= e($H['lead']) ?></p>
      <div class="hero-btns">
        <a class="btn btn-r" href="<?= url('register') ?>"><i data-lucide="user-plus"></i><?= e($H['btn1']) ?></a>
        <a class="btn btn-b" href="#batches"><i data-lucide="layers"></i><?= e($H['btn2']) ?></a>
      </div>
    </div>
    <div class="stage" aria-hidden="true">
      <div class="orb"></div>
      <div class="orbit"><svg viewBox="0 0 100 100">
        <g class="s1"><ellipse cx="50" cy="50" rx="49" ry="20" fill="none" stroke-width=".3" transform="rotate(-25 50 50)"/><circle cx="95" cy="30" r="1.6" fill="#ff5a1f"/></g>
        <g class="s2"><ellipse cx="50" cy="50" rx="49" ry="20" fill="none" stroke-width=".3" transform="rotate(35 50 50)"/><circle cx="8" cy="30" r="1.6" fill="#22d3ff"/></g></svg></div>
      <?php if ($slides): ?><div class="slides"><?php foreach ($slides as $i => $sl): ?><div class="sl<?= $i === 0 ? ' on' : '' ?>"><img src="<?= img($sl['image']) ?>" alt="<?= e($sl['caption']) ?>"><?php if ($sl['caption']): ?><div class="cap"><?= e($sl['caption']) ?></div><?php endif; ?></div><?php endforeach; ?></div>
      <?php else: ?><img src="<?= img(setting('hero_image') ?: null, 'assets/img/coat.webp') ?>" alt="" width="900" height="1100"><?php endif; ?>
      <?php foreach (array_slice($H['chips'], 0, 3) as $i => $c): ?>
      <div class="chip c<?= $i + 1 ?>"><span class="ib"><i data-lucide="<?= e($c['icon'] ?? 'star') ?>"></i></span><span><?= e($c['title'] ?? '') ?><small><?= e($c['sub'] ?? '') ?></small></span></div>
      <?php endforeach; ?>
    </div>
  </div>
</header>

<section class="facts"><div class="wrap">
  <?php foreach (array_slice($H['facts'], 0, 4) as $f): ?><div class="fact"><i data-lucide="<?= e($f['icon'] ?? 'star') ?>"></i><div><strong><?= e($f['title'] ?? '') ?></strong><span><?= e($f['sub'] ?? '') ?></span></div></div><?php endforeach; ?>
</div></section>

<section class="band" id="batches">
  <div class="wrap">
    <div class="head center"><span class="tag"><i data-lucide="sparkles"></i><?= e($H['b_tag']) ?></span><h2><?= e($H['b_t1']) ?> <span class="grad-r"><?= e($H['b_t2']) ?></span> <?= e($H['b_t3']) ?></h2><p class="lead"><?= e($H['b_lead']) ?></p></div>
    <div class="batches">
    <?php foreach ($batches as $b): $isimg = !empty($b['cover_image']); if (preg_match('/^(\d{4})\s*(.*)$/u', $b['name'], $mm)) { $yr = $mm[1]; $lv = $mm[2] ?: 'Advanced Level'; $sm = ''; } else { $yr = $b['name']; $lv = 'Advanced Level'; $sm = ' sm'; } ?>
      <div class="batch"><div class="top<?= $isimg ? ' hasimg' : '' ?>"<?= $isimg ? ' style="background-image:url(\'' . e(img($b['cover_image'])) . '\')"' : '' ?>><i data-lucide="flask-conical"></i><div class="yr<?= $sm ?>"><?= e($yr) ?></div><div class="lv"><?= e($lv) ?></div></div>
      <div class="lb"><b><?= e($b['name']) ?></b><?= (int)$b['lc'] ?> lessons · Physical · Online</div>
      <a href="<?= url('register?batch=' . $b['id']) ?>">Join now <i data-lucide="arrow-right"></i></a></div>
    <?php endforeach; if (!$batches) echo '<p class="empty-note">Batches coming soon.</p>'; ?>
    </div>
  </div>
</section>

<section class="band about" id="about" style="padding-top:0">
  <div class="wrap">
    <div class="ph">
      <img src="<?= img(setting('about_image') ?: null, 'assets/img/polo.webp') ?>" alt="About" width="900" height="985">
      <div class="badge"><i data-lucide="award"></i><span><?= e($H['about_badge1']) ?><small><?= e($H['about_badge2']) ?></small></span></div>
    </div>
    <div>
      <span class="tag"><i data-lucide="user"></i><?= e($H['about_tag']) ?></span>
      <h2><?= e($H['about_title']) ?></h2>
      <p class="lead about-prose"><?= e($H['about_text']) ?></p>
      <div class="qual"><?php foreach ($H['qual'] as $q): ?><div><span class="ib"><i data-lucide="<?= e($q['icon'] ?? 'star') ?>"></i></span><span><b><?= e($q['title'] ?? '') ?></b><span><?= e($q['sub'] ?? '') ?></span></span></div><?php endforeach; ?></div>
      <a class="btn btn-b" href="#contact"><i data-lucide="phone"></i><?= e($H['about_btn']) ?></a>
    </div>
  </div>
</section>

<section class="band classes" id="classes">
  <div class="wrap">
    <div class="head center"><span class="tag"><i data-lucide="flask-conical"></i><?= e($H['c_tag']) ?></span><h2><?= e($H['c_t1']) ?> <span class="grad-b"><?= e($H['c_t2']) ?></span></h2><p class="lead"><?= e($H['c_lead']) ?></p></div>
    <div class="cards">
      <?php $glow = ['#ff2a40', '#2563ff', '#25d366']; $bg = ['var(--red)', 'var(--blue)', 'linear-gradient(135deg,#ff2a40,#2563ff)'];
      foreach ($H['cards'] as $i => $c): ?>
      <article class="card"><span class="glow" style="background:<?= $glow[$i % 3] ?>"></span><div class="ic" style="background:<?= $bg[$i % 3] ?>"><i data-lucide="<?= e($c['icon'] ?? 'star') ?>"></i></div><h3><?= e($c['title'] ?? '') ?></h3><div class="si"><?= e($c['si'] ?? '') ?></div><p><?= e($c['text'] ?? '') ?></p>
        <div class="loc"><?php foreach (array_filter(array_map('trim', explode(',', $c['places'] ?? ''))) as $pl): ?><span><i data-lucide="map-pin"></i><?= e($pl) ?></span><?php endforeach; ?></div></article>
      <?php endforeach; ?>
    </div>
    <div class="swipe"><i data-lucide="chevrons-left-right"></i>Swipe to see more</div>
  </div>
</section>

<section class="band" id="offer">
  <div class="wrap">
    <div class="head center"><span class="tag"><i data-lucide="star"></i><?= e($H['o_tag']) ?></span><h2><?= e($H['o_t1']) ?> <span class="grad-r"><?= e($H['o_t2']) ?></span></h2></div>
    <div class="tiles">
      <?php foreach ($H['tiles'] as $t): ?><div class="tile"><div class="ti"><i data-lucide="<?= e($t['icon'] ?? 'star') ?>"></i></div><b class="si"><?= e($t['si'] ?? '') ?></b><span><?= e($t['sub'] ?? '') ?></span></div><?php endforeach; ?>
    </div>
    <div class="swipe"><i data-lucide="chevrons-left-right"></i>Swipe to see more</div>
  </div>
</section>

<section class="band why">
  <div class="wrap">
    <div class="lab"><img src="<?= img(setting('why_image') ?: null, 'assets/img/lab-bg.webp') ?>" alt="" width="880" height="1100"><p><i data-lucide="flask-round"></i><?= e($H['w_caption']) ?></p></div>
    <div>
      <span class="tag"><i data-lucide="sparkles"></i><?= e($H['w_tag']) ?></span>
      <h2><?= e($H['w_t1']) ?> <span class="grad-b"><?= e($H['w_t2']) ?></span><?= e($H['w_t3']) ?></h2>
      <div class="feat"><?php foreach ($H['feat'] as $f): ?><div><span class="ib"><i data-lucide="<?= e($f['icon'] ?? 'star') ?>"></i></span><span><b><?= e($f['title'] ?? '') ?></b><span><?= e($f['text'] ?? '') ?></span></span></div><?php endforeach; ?></div>
      <a class="btn btn-r" href="<?= url('register') ?>"><i data-lucide="rocket"></i><?= e($H['w_btn']) ?></a>
    </div>
  </div>
</section>

<section class="band">
  <div class="wrap">
    <div class="joinbox">
      <div class="txt">
        <h2><?= e($H['j_title']) ?></h2>
        <div class="si"><?= e($H['j_si']) ?></div>
        <p><?= e($H['j_text']) ?></p>
        <div class="btns">
          <?php foreach ($groups as $g): ?><a class="btn btn-w" href="<?= e($g['url']) ?>" target="_blank" rel="noopener"><i data-lucide="message-circle"></i><?= e($g['label']) ?></a><?php endforeach; ?>
          <?php if (!$groups): ?><a class="btn btn-w" href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener"><i data-lucide="message-circle"></i>Chat on WhatsApp</a><?php endif; ?>
        </div>
      </div>
      <img src="<?= img(setting('community_image') ?: null, 'assets/img/plaid.webp') ?>" alt="" width="880" height="1100">
    </div>
  </div>
</section>

<section class="band contact" id="contact" style="padding-top:0">
  <div class="wrap">
    <div class="head center"><span class="tag"><i data-lucide="phone"></i><?= e($H['k_tag']) ?></span><h2><?= e($H['k_t1']) ?> <span class="grad-r"><?= e($H['k_t2']) ?></span></h2><p class="lead si"><?= e($H['k_lead']) ?></p></div>
    <div class="grid">
      <div class="cinfo">
        <a href="tel:<?= e($phone) ?>"><span class="ib" style="background:var(--red)"><i data-lucide="phone"></i></span><span><small>Call</small><b><?= e($phone) ?></b></span></a>
        <a href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener"><span class="ib" style="background:var(--wa)"><i data-lucide="message-circle"></i></span><span><small>WhatsApp</small><b><?= e($phone) ?></b></span></a>
        <?php foreach ($socials as $k => [$ic, $col]): if (setting($k)): ?><a href="<?= e(setting($k)) ?>" target="_blank" rel="noopener"><span class="ib" style="background:<?= $col ?>"><i data-lucide="<?= $ic ?>"></i></span><span><small>Follow on</small><b><?= ucfirst($k) ?></b></span></a><?php endif; endforeach; ?>
        <?php foreach ($H['places'] as $pl): $href = $pl['url'] ?? ''; ?><<?= $href ? 'a href="' . e($href) . '" target="_blank" rel="noopener"' : 'a' ?>><span class="ib" style="background:var(--blue)"><i data-lucide="map-pin"></i></span><span><small><?= e($pl['title'] ?? '') ?></small><b class="si"><?= e($pl['text'] ?? '') ?></b></span></a><?php endforeach; ?>
      </div>
      <form id="inq" novalidate>
        <h3><i data-lucide="send"></i>Send an inquiry</h3>
        <div class="two">
          <label>Your name<input id="fName" required autocomplete="name"></label>
          <label>Phone<input id="fPhone" inputmode="tel" autocomplete="tel"></label>
        </div>
        <div class="two">
          <label>A/L batch<select id="fYear"><?php foreach ($batches as $b) echo '<option>' . e($b['name']) . '</option>'; ?><option>Other</option></select></label>
          <label>Class<select id="fClass"><?php foreach ($classes as $c) echo '<option>' . e($c['name']) . '</option>'; ?><option>Online</option></select></label>
        </div>
        <label>Message<textarea id="fMsg" placeholder="Questions about fees, start dates or seats…"></textarea></label>
        <button class="btn btn-r" type="submit"><i data-lucide="message-circle"></i>Send via WhatsApp</button>
        <div id="waOut" role="status"></div>
      </form>
    </div>
  </div>
</section>

<footer>
  <div class="wrap">
    <div class="fg">
      <div>
        <a class="logo" href="#home"><b>TW</b><span><?= e(setting('site_name', SITE_NAME)) ?><small><?= e($H['f_sub']) ?></small></span></a>
        <p class="si"><?= e($H['f_si']) ?></p>
      </div>
      <div><h4>Links</h4><ul><li><a href="#about">About</a></li><li><a href="#classes">Classes</a></li><li><a href="#offer">What we offer</a></li><li><a href="#contact">Contact</a></li><li><a href="<?= url('login') ?>">Student login</a></li><li><a href="<?= url('register') ?>">Register</a></li></ul></div>
      <div><h4>Contact</h4><ul><li><a href="tel:<?= e($phone) ?>"><i data-lucide="phone"></i><?= e($phone) ?></a></li><li><a href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener"><i data-lucide="message-circle"></i>WhatsApp</a></li><li><a href="#contact"><i data-lucide="map-pin"></i><?= e(implode(' · ', array_column($H['places'], 'title'))) ?></a></li></ul></div>
    </div>
    <div class="copy"><span>© <span id="yr">2026</span> <?= e(setting('site_name', SITE_NAME)) ?></span><span><?= e($H['f_rights']) ?></span></div>
  </div>
</footer>

<a class="fab" href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="Chat on WhatsApp"><i data-lucide="message-circle"></i></a>

<nav class="tabbar" aria-label="Quick navigation">
  <a href="#home" class="on"><i data-lucide="house"></i>Home</a>
  <a href="#about"><i data-lucide="user"></i>About</a>
  <a href="#classes"><i data-lucide="flask-conical"></i>Classes</a>
  <a href="#offer"><i data-lucide="star"></i>Offer</a>
  <a href="#contact"><i data-lucide="phone"></i>Contact</a>
</nav>

<script>
{
  try { lucide.createIcons(); } catch (e) {}
  const root = document.documentElement, mb = document.getElementById('mode');
  const setMode = m => { root.dataset.mode = m; mb.setAttribute('aria-label', m === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'); };
  let saved = null; try { saved = localStorage.getItem('mode'); } catch (e) {}
  setMode(saved === 'dark' ? 'dark' : 'light');
  mb.addEventListener('click', () => { const m = root.dataset.mode === 'dark' ? 'light' : 'dark'; setMode(m); try { localStorage.setItem('mode', m); } catch (e) {} });
  document.getElementById('yr').textContent = new Date().getFullYear();
  const links = [...document.querySelectorAll('.links a, .tabbar a')];
  const spy = new IntersectionObserver(es => es.forEach(en => {
    if (en.isIntersecting) links.forEach(a => a.classList.toggle('on', a.getAttribute('href') === '#' + en.target.id));
  }), { rootMargin: '-45% 0px -50% 0px' });
  ['home', 'about', 'classes', 'offer', 'contact'].forEach(id => { const el = document.getElementById(id); if (el) spy.observe(el); });
  const sl=document.querySelectorAll('.slides .sl');if(sl.length>1){let i=0;setInterval(()=>{sl[i].classList.remove('on');i=(i+1)%sl.length;sl[i].classList.add('on')},4500)}
  const f = document.getElementById('inq'), out = document.getElementById('waOut');
  f.addEventListener('submit', e => {
    e.preventDefault();
    const v = id => document.getElementById(id).value.trim();
    if (!v('fName')) { document.getElementById('fName').focus(); return; }
    const msg = `Hi Sir, I'm ${v('fName')}${v('fPhone') ? ' (' + v('fPhone') + ')' : ''}.\nBatch: ${v('fYear')}\nClass: ${v('fClass')}\n${v('fMsg')}`;
    out.textContent = 'Your message is ready. ';
    const a = document.createElement('a');
    a.href = 'https://wa.me/<?= e($wa) ?>?text=' + encodeURIComponent(msg); a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'Open WhatsApp to send it →';
    out.appendChild(a); out.style.display = 'block'; a.focus();
  });
}
</script>
</body>
</html>
