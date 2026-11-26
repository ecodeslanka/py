<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$u = me();
$batches = rows('SELECT b.*,(SELECT COUNT(*) FROM lessons l WHERE l.batch_id=b.id AND l.active=1) lc FROM batches b WHERE b.active=1 ORDER BY b.sort_order,b.id');
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
<meta name="description" content="Genuine Chemistry with Tharidu K. Wijekoon, BSc (Chem, Phy) — G.C.E. A/L Chemistry theory, paper and online revision classes in Kegalle and Kurunegala.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Noto+Sans+Sinhala:wght@500;700;900&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@0.460.0/dist/umd/lucide.min.js"></script>
<link rel="stylesheet" href="<?= url('assets/css/home.css') ?>?v=2">
</head>
<body>

<nav class="topnav">
  <div class="wrap">
    <a class="logo" href="#home"><b>TW</b><span>Tharidu Wijekoon<small>Genuine Chemistry</small></span></a>
    <div class="links">
      <a href="#home" class="on">Home</a><a href="#about">About</a><a href="#classes">Classes</a><a href="#offer">What we offer</a><a href="#contact">Contact</a>
    </div>
    <a class="btn btn-o nav-login" href="<?= url($u ? ($u['role']==='admin'?'admin/':'dashboard') : 'login') ?>"><?= $u ? 'My account' : 'Login' ?></a>
    <button class="mode" id="mode" type="button" aria-label="Switch to dark mode"><i data-lucide="moon" class="sun"></i><i data-lucide="sun" class="moon"></i></button>
    <?php if ($u): ?><a class="btn btn-o nav-cta" href="<?= url($u['role']==='admin'?'admin/':'dashboard') ?>"><i data-lucide="layout-dashboard"></i><?= $u['role']==='admin'?'Admin':'My classes' ?></a><a class="btn btn-r nav-cta" href="<?= url('logout') ?>"><i data-lucide="log-out"></i>Logout</a>
    <?php else: ?><a class="btn btn-o nav-cta" href="<?= url('login') ?>"><i data-lucide="log-in"></i>Login</a><a class="btn btn-r nav-cta" href="<?= url('register') ?>"><i data-lucide="user-plus"></i>Join now</a><?php endif; ?>
  </div>
</nav>

<header class="band hero" id="home">
  <div class="wrap">
    <div class="hero-copy">
      <span class="tag"><i data-lucide="flask-conical"></i>G.C.E. Advanced Level Chemistry</span>
      <div class="kicker">GENUINE CHEMISTRY</div>
      <h1><span class="grad-r">Chemistry</span><br>made <span class="grad-b">clear.</span></h1>
      <div class="si-name si">තරිඳු කේ. විජේකෝන්</div>
      <span class="quali"><i data-lucide="graduation-cap"></i>Tharidu K. Wijekoon · BSc (Chemistry, Physics)</span>
      <p class="lead"><?= e((setting('tagline') && setting('tagline') !== 'Chemistry made clear.') ? setting('tagline') : 'Theory, paper classes and online speed revision for A/L students — in Kegalle, Kurunegala and online. Understand the concepts, practise the papers, walk into the exam ready.') ?></p>
      <div class="hero-btns">
        <a class="btn btn-r" href="<?= url('register') ?>"><i data-lucide="user-plus"></i>Create student account</a>
        <a class="btn btn-b" href="#batches"><i data-lucide="layers"></i>See batches</a>
      </div>
    </div>
    <div class="stage" aria-hidden="true">
      <div class="orb"></div>
      <div class="orbit">
        <svg viewBox="0 0 100 100">
          <g class="s1"><ellipse cx="50" cy="50" rx="49" ry="20" fill="none" stroke-width=".3" transform="rotate(-25 50 50)"/><circle cx="95" cy="30" r="1.6" fill="#ff5a1f"/></g>
          <g class="s2"><ellipse cx="50" cy="50" rx="49" ry="20" fill="none" stroke-width=".3" transform="rotate(35 50 50)"/><circle cx="8" cy="30" r="1.6" fill="#22d3ff"/></g>
        </svg>
      </div>
      <?php if ($slides): ?><div class="slides"><?php foreach ($slides as $i=>$sl): ?><div class="sl<?= $i===0?' on':'' ?>"><img src="<?= img($sl['image']) ?>" alt="<?= e($sl['caption']) ?>"><?php if ($sl['caption']): ?><div class="cap"><?= e($sl['caption']) ?></div><?php endif; ?></div><?php endforeach; ?></div>
      <?php else: ?><img src="<?= url('assets/img/coat.webp') ?>" alt="" width="900" height="1100"><?php endif; ?>
      <div class="chip c1"><span class="ib"><i data-lucide="book-open"></i></span><span>Theory<small>Senara · Kegalle</small></span></div>
      <div class="chip c2"><span class="ib"><i data-lucide="file-text"></i></span><span>Paper classes<small>Kegalle &amp; Kurunegala</small></span></div>
      <div class="chip c3"><span class="ib"><i data-lucide="monitor-play"></i></span><span>Online revision<small>Learn from home</small></span></div>
    </div>
  </div>
</header>

<section class="facts">
  <div class="wrap">
    <div class="fact"><i data-lucide="graduation-cap"></i><div><strong>BSc</strong><span>Chemistry &amp; Physics</span></div></div>
    <div class="fact"><i data-lucide="map-pin"></i><div><strong>2 centres</strong><span>Kegalle · Kurunegala</span></div></div>
    <div class="fact"><i data-lucide="layers"></i><div><strong>3 class types</strong><span>Theory · Paper · Online</span></div></div>
    <div class="fact"><i data-lucide="message-circle"></i><div><strong>Direct support</strong><span>On WhatsApp</span></div></div>
  </div>
</section>

<section class="band" id="batches">
  <div class="wrap">
    <div class="head center"><span class="tag"><i data-lucide="sparkles"></i>Batches open</span><h2>Discover <span class="grad-r">Chemistry's</span> Wonder</h2><p class="lead">Choose your A/L batch and reserve your seat.</p></div>
    <div class="batches">
    <?php foreach ($batches as $b): $isimg=!empty($b['cover_image']); if (preg_match('/^(\d{4})\s*(.*)$/u',$b['name'],$mm)) { $yr=$mm[1]; $lv=$mm[2]?:'Advanced Level'; $sm=''; } else { $yr=$b['name']; $lv='Advanced Level'; $sm=' sm'; } ?>
      <div class="batch"><div class="top<?= $isimg?' hasimg':'' ?>"<?= $isimg?' style="background-image:url(\''.e(img($b['cover_image'])).'\')"':'' ?>><i data-lucide="flask-conical"></i><div class="yr<?= $sm ?>"><?= e($yr) ?></div><div class="lv"><?= e($lv) ?></div></div>
      <div class="lb"><b><?= e($b['name']) ?></b><?= (int)$b['lc'] ?> classes · Physical · Online</div>
      <a href="<?= url('register?batch='.$b['id']) ?>">Join now <i data-lucide="arrow-right"></i></a></div>
    <?php endforeach; if (!$batches) echo '<p class="empty-note">Batches coming soon.</p>'; ?>
    </div>
  </div>
</section>

<section class="band about" id="about" style="padding-top:0">
  <div class="wrap">
    <div class="ph">
      <img src="<?= img(setting('about_image') ?: null, 'assets/img/polo.webp') ?>" alt="About" width="900" height="985">
      <div class="badge"><i data-lucide="award"></i><span>Tharidu K. Wijekoon<small>BSc (Chem, Phy)</small></span></div>
    </div>
    <div>
      <span class="tag"><i data-lucide="user"></i>About your teacher</span>
      <h2><?= e(setting('about_title', 'Learn chemistry the genuine way')) ?></h2>
      <p class="lead about-prose"><?= e(setting('about_text')) ?></p>
      <div class="qual">
        <div><span class="ib"><i data-lucide="graduation-cap"></i></span><span><b>BSc (Chem, Phy)</b><span>Chemistry &amp; Physics graduate</span></span></div>
        <div><span class="ib"><i data-lucide="atom"></i></span><span><b>Full A/L syllabus</b><span>Physical, inorganic &amp; organic</span></span></div>
        <div><span class="ib"><i data-lucide="clipboard-check"></i></span><span><b>Paper practice</b><span>MCQ, structured &amp; essay</span></span></div>
        <div><span class="ib"><i data-lucide="monitor-play"></i></span><span><b>Online revision</b><span>Learn from anywhere</span></span></div>
      </div>
      <a class="btn btn-b" href="#contact"><i data-lucide="phone"></i>Contact Sir</a>
    </div>
  </div>
</section>

<section class="band classes" id="classes">
  <div class="wrap">
    <div class="head center"><span class="tag"><i data-lucide="flask-conical"></i>Our classes</span><h2>Three ways to master <span class="grad-b">A/L Chemistry</span></h2><p class="lead">Combine theory with paper classes and online revision for complete preparation.</p></div>
    <div class="cards">
      <article class="card"><span class="glow" style="background:#ff2a40"></span><div class="ic" style="background:var(--red)"><i data-lucide="book-open"></i></div><h3>Theory</h3><div class="si">න්‍යාය පන්ති</div><p>Complete syllabus coverage with clear concepts, reactions and calculations explained step by step.</p><div class="loc"><span><i data-lucide="map-pin"></i>Senara – Kegalle</span></div></article>
      <article class="card"><span class="glow" style="background:#2563ff"></span><div class="ic" style="background:var(--blue)"><i data-lucide="file-text"></i></div><h3>Paper class</h3><div class="si">ප්‍රශ්න පත්‍ර පන්ති</div><p>Full-day paper sessions with discussion, so you learn timing, marking and answer technique.</p><div class="loc"><span><i data-lucide="map-pin"></i>Senara – Kegalle</span><span><i data-lucide="map-pin"></i>Sithumina – Kurunegala</span></div></article>
      <article class="card"><span class="glow" style="background:#25d366"></span><div class="ic" style="background:linear-gradient(135deg,#ff2a40,#2563ff)"><i data-lucide="laptop"></i></div><h3>Online speed revision</h3><div class="si">ඔන්ලයින් වේගවත් පුනරීක්ෂණ</div><p>Fast-paced revision of the whole syllabus from home — ideal for the final months before the exam.</p><div class="loc"><span><i data-lucide="wifi"></i>Online</span></div></article>
    </div>
    <div class="swipe"><i data-lucide="chevrons-left-right"></i>Swipe to see more</div>
  </div>
</section>

<section class="band" id="offer">
  <div class="wrap">
    <div class="head center"><span class="tag"><i data-lucide="star"></i>What we offer</span><h2>Everything you need to <span class="grad-r">score</span></h2></div>
    <div class="tiles">
      <div class="tile"><div class="ti"><i data-lucide="lightbulb"></i></div><b class="si">සංකල්ප මූලිකව ඉගැන්වීම</b><span>Concept-first teaching</span></div>
      <div class="tile"><div class="ti"><i data-lucide="clipboard-list"></i></div><b class="si">නිතිපතා ප්‍රශ්න පත්‍ර</b><span>Regular paper classes</span></div>
      <div class="tile"><div class="ti"><i data-lucide="monitor-play"></i></div><b class="si">ඔන්ලයින් පුනරීක්ෂණ</b><span>Online speed revision</span></div>
      <div class="tile"><div class="ti"><i data-lucide="headset"></i></div><b class="si">සෘජු සහාය</b><span>Direct WhatsApp support</span></div>
    </div>
    <div class="swipe"><i data-lucide="chevrons-left-right"></i>Swipe to see more</div>
  </div>
</section>

<section class="band why">
  <div class="wrap">
    <div class="lab"><img src="<?= url('assets/img/') ?>lab-bg.webp" alt="Tharidu Wijekoon in the chemistry lab" width="880" height="1100"><p><i data-lucide="flask-round"></i>From the lab bench to the answer sheet.</p></div>
    <div>
      <span class="tag"><i data-lucide="sparkles"></i>Why Genuine Chemistry</span>
      <h2>Built for <span class="grad-b">results</span>, not memorising</h2>
      <div class="feat">
        <div><span class="ib"><i data-lucide="brain"></i></span><span><b>Concepts first</b><span>Understand why reactions happen, so you can answer questions you've never seen.</span></span></div>
        <div><span class="ib"><i data-lucide="timer"></i></span><span><b>Regular paper practice</b><span>Full-day paper classes train speed, accuracy and marking-scheme thinking.</span></span></div>
        <div><span class="ib"><i data-lucide="house"></i></span><span><b>Revision from home</b><span>Evening online sessions cover the syllabus quickly when exam time is close.</span></span></div>
        <div><span class="ib"><i data-lucide="message-circle"></i></span><span><b>Direct support</b><span>Ask questions and get class updates directly on WhatsApp.</span></span></div>
      </div>
      <a class="btn btn-r" href="#batches"><i data-lucide="rocket"></i>Enroll now</a>
    </div>
  </div>
</section>

<section class="band">
  <div class="wrap">
    <div class="joinbox">
      <div class="txt">
        <h2>Join the WhatsApp community</h2>
        <div class="si">පන්ති තොරතුරු මුලින්ම ඔබට</div>
        <p>Class updates, paper notices and announcements — straight to your phone.</p>
        <div class="btns">
          <?php foreach ($groups as $g): ?><a class="btn btn-w" href="<?= e($g['url']) ?>" target="_blank" rel="noopener"><i data-lucide="message-circle"></i><?= e($g['label']) ?></a><?php endforeach; ?>
          <?php if (!$groups): ?><a class="btn btn-w" href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener"><i data-lucide="message-circle"></i>Chat on WhatsApp</a><?php endif; ?>
        </div>
      </div>
      <img src="<?= url('assets/img/') ?>plaid.webp" alt="Tharidu Wijekoon smiling" width="880" height="1100">
    </div>
  </div>
</section>

<section class="band contact" id="contact" style="padding-top:0">
  <div class="wrap">
    <div class="head center"><span class="tag"><i data-lucide="phone"></i>Contact</span><h2>Reserve your <span class="grad-r">seat</span></h2><p class="lead si">සියලුම විමසීම් සඳහා අමතන්න හෝ WhatsApp කරන්න.</p></div>
    <div class="grid">
      <div class="cinfo">
        <a href="tel:<?= e($phone) ?>"><span class="ib" style="background:var(--red)"><i data-lucide="phone"></i></span><span><small>Call</small><b><?= e($phone) ?></b></span></a>
        <a href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener"><span class="ib" style="background:var(--wa)"><i data-lucide="message-circle"></i></span><span><small>WhatsApp</small><b><?= e($phone) ?></b></span></a>
        <?php foreach ($socials as $k=>[$ic,$col]): if (setting($k)): ?><a href="<?= e(setting($k)) ?>" target="_blank" rel="noopener"><span class="ib" style="background:<?= $col ?>"><i data-lucide="<?= $ic ?>"></i></span><span><small>Follow on</small><b><?= ucfirst($k) ?></b></span></a><?php endif; endforeach; ?>
        <a href="https://www.google.com/maps/search/Senara+Institute+Kegalle" target="_blank" rel="noopener"><span class="ib" style="background:var(--blue)"><i data-lucide="map-pin"></i></span><span><small>Kegalle</small><b class="si">සෙනාරා – කෑගල්ල</b></span></a>
        <a href="https://www.google.com/maps/search/Sithumina+Institute+Kurunegala" target="_blank" rel="noopener"><span class="ib" style="background:var(--blue)"><i data-lucide="map-pin"></i></span><span><small>Kurunegala</small><b class="si">සිතුමිණ – කුරුණෑගල</b></span></a>
      </div>
      <form id="inq" novalidate>
        <h3><i data-lucide="send"></i>Send an inquiry</h3>
        <div class="two">
          <label>Your name<input id="fName" required autocomplete="name"></label>
          <label>Phone<input id="fPhone" inputmode="tel" autocomplete="tel"></label>
        </div>
        <div class="two">
          <label>A/L batch<select id="fYear"><?php foreach ($batches as $b) echo '<option>'.e($b['name']).'</option>'; ?><option>Other</option></select></label>
          <label>Class<select id="fClass"><option>Theory – Kegalle</option><option>Paper class – Kegalle</option><option>Paper class – Kurunegala</option><option>Online speed revision</option></select></label>
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
        <a class="logo" href="#home"><b>TW</b><span>Tharidu Wijekoon<small>Genuine Chemistry · BSc (Chem, Phy)</small></span></a>
        <p class="si">රසායන විද්‍යාව — නියම විදිහට. G.C.E. A/L Chemistry with Tharidu K. Wijekoon.</p>
      </div>
      <div><h4>Links</h4><ul><li><a href="#about">About</a></li><li><a href="#classes">Classes</a></li><li><a href="#offer">What we offer</a></li><li><a href="#contact">Contact</a></li><li><a href="<?= url('login') ?>">Student login</a></li><li><a href="<?= url('register') ?>">Register</a></li></ul></div>
      <div><h4>Contact</h4><ul><li><a href="tel:<?= e($phone) ?>"><i data-lucide="phone"></i><?= e($phone) ?></a></li><li><a href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener"><i data-lucide="message-circle"></i>WhatsApp</a></li><li><a href="#contact"><i data-lucide="map-pin"></i>Kegalle · Kurunegala</a></li></ul></div>
    </div>
    <div class="copy"><span>© <span id="yr">2026</span> <?= e(setting('site_name', SITE_NAME)) ?></span><span>All rights reserved</span></div>
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
  ['home', 'about', 'classes', 'offer', 'contact'].forEach(id => spy.observe(document.getElementById(id)));

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
