// Header solid-on-scroll
const header = document.getElementById('header');
if (header) {
  window.addEventListener('scroll', () => header.classList.toggle('scrolled', window.scrollY > 30), { passive: true });
}

// Mobile menu
const menuToggle = document.getElementById('menuToggle');
const mobileMenu = document.getElementById('mobileMenu');
function closeMenu() {
  if (!mobileMenu) return;
  mobileMenu.classList.remove('open');
  mobileMenu.setAttribute('aria-hidden', 'true');
  menuToggle && menuToggle.setAttribute('aria-expanded', 'false');
  document.body.classList.remove('menu-open');
}
if (menuToggle && mobileMenu) {
  menuToggle.addEventListener('click', () => {
    const open = !mobileMenu.classList.contains('open');
    mobileMenu.classList.toggle('open', open);
    mobileMenu.setAttribute('aria-hidden', String(!open));
    menuToggle.setAttribute('aria-expanded', String(open));
    document.body.classList.toggle('menu-open', open);
  });
  mobileMenu.querySelectorAll('a').forEach(a => a.addEventListener('click', closeMenu));
}

// Reveal on scroll
const observer = new IntersectionObserver(entries => {
  entries.forEach(entry => {
    if (entry.isIntersecting) { entry.target.classList.add('visible'); observer.unobserve(entry.target); }
  });
}, { threshold: .12 });
document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

// Lightbox (for gallery/album images with data-lightbox attribute)
const lightbox = document.getElementById('lightbox');
const lightboxImage = document.getElementById('lightboxImage');
function openLightbox(src, alt) {
  if (!lightbox) return;
  lightboxImage.src = src;
  lightboxImage.alt = alt || '';
  lightbox.classList.add('open');
  document.body.classList.add('menu-open');
}
function closeLightbox() {
  if (!lightbox) return;
  lightbox.classList.remove('open');
  lightboxImage.src = '';
  document.body.classList.remove('menu-open');
}
document.querySelectorAll('[data-lightbox]').forEach(el => {
  el.addEventListener('click', e => {
    e.preventDefault();
    openLightbox(el.getAttribute('data-lightbox'), el.getAttribute('data-alt') || '');
  });
});
const lightboxCloseBtn = document.getElementById('lightboxClose');
if (lightboxCloseBtn) lightboxCloseBtn.addEventListener('click', closeLightbox);
if (lightbox) lightbox.addEventListener('click', e => { if (e.target === lightbox) closeLightbox(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeLightbox(); closeMenu(); } });

// Hero slider (auto-rotate through .hero-slide elements)
(function heroSlider() {
  const slides = document.querySelectorAll('.hero-slide');
  const dotsWrap = document.getElementById('heroDots');
  if (slides.length < 2) return;
  let i = 0;
  const dots = [];
  if (dotsWrap) {
    slides.forEach((_, idx) => {
      const b = document.createElement('button');
      if (idx === 0) b.classList.add('active');
      b.addEventListener('click', () => show(idx));
      dotsWrap.appendChild(b);
      dots.push(b);
    });
  }
  function show(idx) {
    slides[i].style.opacity = 0;
    dots[i] && dots[i].classList.remove('active');
    i = idx;
    slides[i].style.opacity = 1;
    dots[i] && dots[i].classList.add('active');
  }
  slides.forEach((s, idx) => { s.style.transition = 'opacity 1.2s ease'; s.style.opacity = idx === 0 ? 1 : 0; s.style.position = 'absolute'; s.style.inset = '0'; });
  setInterval(() => show((i + 1) % slides.length), 6000);
})();
