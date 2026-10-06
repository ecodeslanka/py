<?php
/* includes/footer.php — shared site footer (ECLOTHING theme)
   Functional overlays (toast, cart drawer, JS) still live here since every page includes
   this file, but the visible <footer> itself is now the plain one-line footer from the
   reference design instead of the old multi-column footer. */
$siteSettings   = $siteSettings   ?? get_site_settings();
$__customer     = $__customer     ?? current_customer();
$__footerCats   = $topCategories  ?? get_menu_categories();
$__footerPhones = get_all_contact_numbers();
$__footerEmails = get_all_site_emails();
$__footerSocials = [
  'facebook_url'  => ['fa-brands fa-facebook-f', 'Facebook'],
  'instagram_url' => ['fa-brands fa-instagram', 'Instagram'],
  'tiktok_url'    => ['fa-brands fa-tiktok', 'TikTok'],
  'youtube_url'   => ['fa-brands fa-youtube', 'YouTube'],
  'twitter_url'   => ['fa-brands fa-x-twitter', 'X (Twitter)'],
  'linkedin_url'  => ['fa-brands fa-linkedin-in', 'LinkedIn'],
];
?>
<footer class="site-footer">
  <div class="container footer-grid">

    <div class="footer-col footer-about">
      <a href="<?= BASE_URL ?>/" class="footer-logo">
        <img src="<?= e(dewansa_logo_url(true)) ?>" alt="<?= e($siteSettings['company_name'] ?: SITE_NAME) ?>">
      </a>
      <p class="footer-tagline"><?= e(footer_tagline_text()) ?></p>
      <div class="footer-models">
        <?php foreach (dewansa_home_brands() as $__m): ?><a href="<?= e($__m['url']) ?>" title="<?= e(brand_hover_text($__m)) ?>"><?= e($__m['name']) ?></a><?php endforeach; ?>
      </div>
      <div class="footer-social">
        <?php foreach ($__footerSocials as $__k => [$__ic, $__label]): if (empty($siteSettings[$__k])) continue; ?>
          <a href="<?= e($siteSettings[$__k]) ?>" target="_blank" rel="noopener" aria-label="<?= e($__label) ?>"><i class="<?= $__ic ?>"></i></a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="footer-col">
      <h4>Quick Links</h4>
      <ul>
        <li><a href="<?= BASE_URL ?>/">Home</a></li>
        <li><a href="<?= BASE_URL ?>/all-categories">All Categories</a></li>
        <li><a href="<?= BASE_URL ?>/about-us">About Us</a></li>
        <li><a href="<?= BASE_URL ?>/products">All Products</a></li>
        <li><a href="<?= BASE_URL ?>/#deals">Sale</a></li>
        <li><a href="<?= BASE_URL ?>/contact-us">Contact Us</a></li>
      </ul>
    </div>

    <div class="footer-col">
      <h4>Policies</h4>
      <ul>
        <li><a href="<?= BASE_URL ?>/privacy-policy">Privacy Policy</a></li>
        <li><a href="<?= BASE_URL ?>/terms-conditions">Terms &amp; Conditions</a></li>
        <li><a href="<?= BASE_URL ?>/refund-policy">Exchanges &amp; Returns</a></li>
        <li><a href="<?= BASE_URL ?>/track-order">Track Order</a></li>
      </ul>
    </div>

    <div class="footer-col">
      <h4>Collections</h4>
      <ul>
        <?php foreach (dewansa_parts_services() as [$__t, $__d, $__ic, $__q, $__f]): ?>
        <li><a href="<?= e($__q) ?>"><?= e($__t) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="footer-col footer-contact">
      <h4>Contact Us</h4>
      <ul class="footer-contact-list">
        <?php if (!empty($siteSettings['address'])): ?>
        <li><i class="fa-solid fa-location-dot"></i> <span><?= nl2br(e($siteSettings['address'])) ?></span></li>
        <?php endif; ?>
        <?php foreach ($__footerPhones as $__ph): ?>
        <li><i class="fa-solid fa-phone"></i> <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $__ph['phone'])) ?>"><?= e($__ph['phone']) ?></a><?php if ($__ph['description']): ?> <span class="footer-tag"><?= e($__ph['description']) ?></span><?php endif; ?></li>
        <?php endforeach; ?>
        <?php foreach ($__footerEmails as $__em): ?>
        <li><i class="fa-solid fa-envelope"></i> <a href="mailto:<?= e($__em['email']) ?>"><?= e($__em['email']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <div class="footer-bottom">
    <div class="container footer-bottom-inner">
      <p><?= e(footer_copyright_text()) ?></p>
      <div class="footer-bottom-links">
        <a href="<?= BASE_URL ?>/about-us">About Us</a>
        <a href="<?= BASE_URL ?>/contact-us">Contact Us</a>
        <a href="<?= BASE_URL ?>/privacy-policy">Privacy Policy</a>
        <a href="<?= BASE_URL ?>/terms-conditions">Terms &amp; Conditions</a>
        <a href="<?= BASE_URL ?>/refund-policy">Refund Policy</a>
      </div>
    </div>
    <?php $__credit = footer_credit_html(); if ($__credit !== ''): ?>
    <div class="container footer-credit"><p><?= $__credit ?></p></div>
    <?php endif; ?>
  </div>
</footer>

<div class="toast" id="toast"><i class="fa-solid fa-circle-check"></i> Added to bag</div>

<!-- ======= CART DRAWER ======= -->
<div class="cart-drawer-overlay" id="cartDrawerOverlay"></div>
<aside class="cart-drawer" id="cartDrawer" aria-hidden="true">
  <div class="cart-drawer-head">
    <h3><i class="fa-solid fa-bag-shopping"></i> Your Bag</h3>
    <button type="button" class="drawer-close" id="cartDrawerClose" aria-label="Close cart"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="cart-drawer-body" id="cartDrawerBody">
    <p class="cart-empty"><i class="fa-solid fa-bag-shopping"></i> Your bag is empty.</p>
  </div>
  <div class="cart-drawer-foot" id="cartDrawerFoot" hidden>
    <div class="cart-subtotal-row"><span>Subtotal</span><strong id="cartSubtotal">Rs. 0.00</strong></div>
    <button type="button" class="btn-checkout" id="cartCheckoutBtn">Proceed to Checkout</button>
  </div>
</aside>

<script>
/* ---- Hero slider (multiple admin-configured home slides, or the 3 fallback slides) ---- */
(function(){
  const slides=[...document.querySelectorAll('.hero-slide')];
  const dots=[...document.querySelectorAll('.hero-dots button')];
  const prevBtn=document.querySelector('.hero-arrow.left'),
        nextBtn=document.querySelector('.hero-arrow.right');
  if(!slides.length) return;
  let cur=0,timer;
  function go(i){
    slides[cur].classList.remove('active');
    if(dots[cur])dots[cur].classList.remove('active');
    cur=(i+slides.length)%slides.length;
    slides[cur].classList.add('active');
    if(dots[cur])dots[cur].classList.add('active');
  }
  function auto(){ if(slides.length>1){ timer=setInterval(()=>go(cur+1),5500); } }
  function restart(){ clearInterval(timer); auto(); }
  dots.forEach((d,i)=>d.addEventListener('click',()=>{go(i);restart();}));
  prevBtn&&prevBtn.addEventListener('click',()=>{go(cur-1);restart();});
  nextBtn&&nextBtn.addEventListener('click',()=>{go(cur+1);restart();});
  auto();
})();

/* ---- Cart (real, session-backed via cart.php) ---- */
const badge=document.getElementById('cartCount'),toast=document.getElementById('toast');
const cartDrawer=document.getElementById('cartDrawer'),
      cartDrawerOverlay=document.getElementById('cartDrawerOverlay'),
      cartDrawerClose=document.getElementById('cartDrawerClose'),
      cartDrawerBody=document.getElementById('cartDrawerBody'),
      cartDrawerFoot=document.getElementById('cartDrawerFoot'),
      cartSubtotalEl=document.getElementById('cartSubtotal'),
      cartCheckoutBtn=document.getElementById('cartCheckoutBtn');
let toastTimer;
function showToast(html){
  toast.innerHTML=html;toast.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer=setTimeout(()=>toast.classList.remove('show'),2200);
}
function money(n){
  return 'Rs. '+Number(n||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
}
function cartRequest(params){
  return fetch('<?= BASE_URL ?>/cart.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams(params).toString()
  }).then(r=>r.text()).then(text=>{
    try{
      return JSON.parse(text);
    }catch(e){
      // Server didn't return JSON — surface the real response instead of a
      // generic message, so the actual PHP/server error is visible.
      console.error('cart.php did not return valid JSON:', text);
      return {ok:false, error:'Server error (not JSON) — open the browser console for details.'};
    }
  });
}
function loadCart(){
  return fetch('<?= BASE_URL ?>/cart.php?action=list').then(r=>r.text()).then(text=>{
    try{ return JSON.parse(text); }
    catch(e){ console.error('cart.php (list) did not return valid JSON:', text); return null; }
  }).then(data=>{ if(data) renderCart(data); }).catch(()=>{});
}
function renderCart(data){
  const cnt=data.count||0;
  if(badge)badge.textContent=cnt;
  if(!cartDrawerBody)return;
  if(!data.items||!data.items.length){
    cartDrawerBody.innerHTML='<p class="cart-empty"><i class="fa-solid fa-bag-shopping"></i> Your bag is empty.</p>';
    if(cartDrawerFoot)cartDrawerFoot.hidden=true;
    return;
  }
  if(cartDrawerFoot)cartDrawerFoot.hidden=false;
  if(cartSubtotalEl)cartSubtotalEl.textContent=money(data.subtotal);
  cartDrawerBody.innerHTML=data.items.map(it=>(
    '<div class="cart-line" data-key="'+it.key+'">'+
      '<a href="'+it.url+'" class="cart-line-img">'+(it.image?('<img src="<?= BASE_URL ?>'+it.image+'" alt="">'):'<i class="fa-solid fa-image"></i>')+'</a>'+
      '<div class="cart-line-info">'+
        '<a href="'+it.url+'" class="cart-line-name">'+it.name+'</a>'+
        '<div class="cart-line-price">'+money(it.price)+'</div>'+
        '<div class="cart-line-qty">'+
          '<button type="button" class="cart-qty-btn" data-action="dec" aria-label="Decrease quantity">−</button>'+
          '<span>'+it.qty+'</span>'+
          '<button type="button" class="cart-qty-btn" data-action="inc" aria-label="Increase quantity">+</button>'+
        '</div>'+
      '</div>'+
      '<button type="button" class="cart-line-remove" aria-label="Remove item"><i class="fa-solid fa-trash"></i></button>'+
    '</div>'
  )).join('');
}
function openCartDrawer(){
  if(!cartDrawer)return;
  cartDrawer.classList.add('open');cartDrawerOverlay.classList.add('show');
  cartDrawer.setAttribute('aria-hidden','false');
  document.body.classList.add('no-scroll','drawer-lock');
  loadCart();
}
function closeCartDrawer(){
  if(!cartDrawer)return;
  cartDrawer.classList.remove('open');cartDrawerOverlay.classList.remove('show');
  cartDrawer.setAttribute('aria-hidden','true');
  document.body.classList.remove('no-scroll','drawer-lock');
}
cartDrawerClose&&cartDrawerClose.addEventListener('click',closeCartDrawer);
cartDrawerOverlay&&cartDrawerOverlay.addEventListener('click',closeCartDrawer);
document.querySelectorAll('.cart-open-trigger').forEach(el=>el.addEventListener('click',e=>{e.preventDefault();openCartDrawer();}));

cartDrawerBody&&cartDrawerBody.addEventListener('click',e=>{
  const line=e.target.closest('.cart-line');
  if(!line)return;
  const key=line.dataset.key;
  if(e.target.closest('.cart-line-remove')){
    cartRequest({action:'remove',key}).then(renderCart);
  }else if(e.target.closest('.cart-qty-btn')){
    const dir=e.target.closest('.cart-qty-btn').dataset.action;
    const qtyEl=line.querySelector('.cart-line-qty span');
    let qty=parseInt(qtyEl.textContent,10)||1;
    qty=dir==='inc'?qty+1:qty-1;
    cartRequest({action:'update',key,qty}).then(d=>{ if(d && d.items) renderCart(d); if(d && !d.ok && d.error) showToast('<i class="fa-solid fa-triangle-exclamation"></i> '+d.error); });
  }
});
cartCheckoutBtn&&cartCheckoutBtn.addEventListener('click',()=>{
  window.location.href='<?= BASE_URL ?>/checkout.php';
});

/* Quick "Add to Cart" buttons — old .card product listings (item.php / products.php) */
document.querySelectorAll('.add-btn').forEach(b=>b.addEventListener('click',()=>{
  const itemId=parseInt(b.dataset.itemId,10);
  if(!itemId)return;
  if(b.dataset.hasVariations==='1'){
    window.location.href='<?= BASE_URL ?>/item.php?id='+itemId;
    return;
  }
  b.disabled=true;
  cartRequest({action:'add',item_id:itemId,qty:1}).then(data=>{
    b.disabled=false;
    if(!data.ok){showToast('<i class="fa-solid fa-triangle-exclamation"></i> '+(data.error||'Could not add to cart.'));return;}
    renderCart(data);
    showToast('<i class="fa-solid fa-circle-check"></i> Added to bag — '+data.count+' item'+(data.count>1?'s':''));
  }).catch(()=>{b.disabled=false;showToast('<i class="fa-solid fa-triangle-exclamation"></i> Could not add to cart.');});
}));

/* Quick "Add to Cart" buttons — new homepage prod-card best-seller grids */
document.querySelectorAll('.prod-actions .cart, .ec-quick .cart').forEach(b=>b.addEventListener('click',()=>{
  const itemId=parseInt(b.dataset.itemId,10);
  if(!itemId)return;
  if(b.dataset.hasVariations==='1'){
    window.location.href='<?= BASE_URL ?>/item.php?id='+itemId;
    return;
  }
  b.disabled=true;
  cartRequest({action:'add',item_id:itemId,qty:1}).then(data=>{
    b.disabled=false;
    if(!data.ok){showToast('<i class="fa-solid fa-triangle-exclamation"></i> '+(data.error||'Could not add to cart.'));return;}
    renderCart(data);
    showToast('<i class="fa-solid fa-circle-check"></i> Added to bag — '+data.count+' item'+(data.count>1?'s':''));
  }).catch(()=>{b.disabled=false;showToast('<i class="fa-solid fa-triangle-exclamation"></i> Could not add to cart.');});
}));

document.querySelectorAll('.wish, .prod-wish').forEach(b=>b.addEventListener('click',e=>{
  e.preventDefault();
  const saved=b.classList.toggle('saved');
  b.innerHTML=saved?'<i class="fa-solid fa-heart"></i>':'<i class="fa-regular fa-heart"></i>';
  showToast(saved?'<i class="fa-solid fa-heart"></i> Saved to wishlist':'Removed from wishlist');
}));

loadCart(); // sync the cart badge count with the server on every page load
</script>

<!-- ======= APP-STYLE BOTTOM TAB BAR (mobile only) ======= -->
<nav class="app-bottom-nav" aria-label="Mobile app navigation">
  <a href="<?= BASE_URL ?>/" class="app-nav-item<?= (basename($_SERVER['PHP_SELF']) === 'index.php') ? ' active' : '' ?>">
    <i class="fa-solid fa-house"></i><span>Home</span>
  </a>
  <button type="button" id="appNavCatBtn" class="app-nav-item">
    <i class="fa-solid fa-grip"></i><span>Categories</span>
  </button>
  <a href="#" class="app-nav-item app-nav-main cart-open-trigger" aria-label="Open cart">
    <span class="app-nav-main-ic"><i class="fa-solid fa-bag-shopping"></i></span>
    <span class="app-nav-badge" id="appNavCartCount">0</span>
  </a>
  <a href="<?= BASE_URL ?>/products" class="app-nav-item">
    <i class="fa-solid fa-magnifying-glass"></i><span>Search</span>
  </a>
  <a href="<?= BASE_URL ?>/<?= $__customer ? 'dashboard' : 'login' ?>" class="app-nav-item">
    <i class="fa-regular fa-user"></i><span>Account</span>
  </a>
</nav>

<script>
/* Mirror the cart badge count onto the bottom app-nav pill too */
(function(){
  var appBadge = document.getElementById('appNavCartCount');
  if (!appBadge) return;
  var target = document.getElementById('cartCount');
  if (!target) return;
  var obs = new MutationObserver(function(){ appBadge.textContent = target.textContent; });
  obs.observe(target, {childList:true, characterData:true, subtree:true});
  appBadge.textContent = target.textContent;
})();
</script>
</body>
</html>
