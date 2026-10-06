<?php
/**
 * item.php — Item preview / product detail page  (URL: /item.php?id=123)
 * Fashion layout: breadcrumb → gallery + buy box (colour swatches, size buttons with
 * live per-size/colour stock) → details / size guide tabs → related items.
 */
require_once __DIR__ . '/config/config.php';

$id   = (int)($_GET['id'] ?? 0);
$item = $id ? get_item_detail($id) : null;

if (!$item) {
    http_response_code(404);
    $pageTitle = 'Item Not Found — ' . site_display_name();
    require __DIR__ . '/includes/header.php';
    echo '<main class="wrap" style="padding:60px 20px;text-align:center">
            <h1 style="font-size:34px">404 — Item not found</h1>
            <p style="color:var(--muted);margin:14px 0 24px">This item may have been removed or is no longer available.</p>
            <a class="btn btn-green" href="' . BASE_URL . '/">← Back to Home</a>
          </main>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle  = $item['name'] . ' — ' . site_display_name();
$images     = get_item_images((int)$item['id']);
$breadcrumb = get_category_breadcrumb(isset($item['cat_id']) ? (int)$item['cat_id'] : null);
$related    = get_related_items(isset($item['cat_id']) ? (int)$item['cat_id'] : null, (int)$item['id'], 5);
$pi         = item_price_info($item);
$variations = !empty($item['has_variations']) ? get_item_variations((int)$item['id']) : [];
$matrix     = $variations ? item_variation_matrix($item, $variations) : null;
$isMatrix   = $matrix && $matrix['mode'] === 'matrix';
$allSoldOut = $variations && $matrix['total_stock'] <= 0;
$preSize    = trim((string)($_GET['size'] ?? ''));
$relatedSummary = get_items_variation_summary(array_column($related, 'id'));

$stockLabel = ['in_stock' => '✔ In Stock', 'pre_order' => '⏳ Pre-Order', 'out_of_stock' => '✕ Out of Stock'][$item['stock_status']] ?? $item['stock_status'];
$stockClass = ['in_stock' => 'stock-in', 'pre_order' => 'stock-pre', 'out_of_stock' => 'stock-out'][$item['stock_status']] ?? '';
$__siteSettingsForPlans = get_site_settings();

require __DIR__ . '/includes/header.php';
?>
<main class="wrap">

  <nav class="item-breadcrumb" aria-label="Breadcrumb">
    <a href="<?= BASE_URL ?>/">Home</a>
    <?php foreach ($breadcrumb as $bc): ?>
      <span>/</span><a href="<?= BASE_URL ?>/category/<?= e($bc['slug']) ?>"><?= e($bc['name']) ?></a>
    <?php endforeach; ?>
    <span>/</span><span class="current"><?= e($item['name']) ?></span>
  </nav>

  <section class="item-detail">
    <div class="item-gallery">
      <div class="item-gallery-main">
        <?php if ($images): ?>
          <img src="<?= BASE_URL . e($images[0]['image_path']) ?>" alt="<?= e($item['name']) ?>" id="itemMainImg">
        <?php else: ?>
          <span class="item-gallery-placeholder"><i class="fa-solid fa-shirt"></i></span>
        <?php endif; ?>
        <?php if ($pi['discount']): ?><span class="badge item-badge">-<?= (int)$pi['discount'] ?>%</span><?php endif; ?>
      </div>
      <?php if (count($images) > 1): ?>
      <div class="item-thumbs">
        <?php foreach ($images as $i => $img): ?>
        <button type="button" class="item-thumb <?= $i === 0 ? 'active' : '' ?>" onclick="switchItemImage('<?= BASE_URL . e($img['image_path']) ?>', this)" aria-label="View image <?= $i + 1 ?>">
          <img src="<?= BASE_URL . e($img['image_path']) ?>" alt="">
        </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="item-info">
      <?php if ($item['brand_name']): ?><a href="<?= BASE_URL ?>/" class="item-brand"><?= e($item['brand_name']) ?></a><?php endif; ?>
      <h1 class="item-title"><?= e($item['name']) ?></h1>
      <div class="item-meta">
        <span>SKU: <?= e($item['sku']) ?></span>
        <span class="dot">•</span>
        <?php if ($allSoldOut): ?><span class="stock-out">✕ Sold Out</span><?php else: ?><span class="<?= $stockClass ?>" id="itemStockLabel"><?= $stockLabel ?></span><?php endif; ?>
      </div>

      <div class="item-price-block" id="itemPriceBlock">
        <?php if ($pi['old_price']): ?><span class="item-old-price">Rs. <?= number_format($pi['old_price'], 2) ?></span><?php endif; ?>
        <div class="item-price">Rs. <?= number_format($pi['price'], 2) ?></div>
        <?php if ($pi['discount']): ?><span class="item-save">Save <?= (int)$pi['discount'] ?>% · Rs. <?= number_format($pi['save_amount']) ?></span><?php endif; ?>
        <?= payment_plan_html($pi['price'], 'detail', koko_item_teaser_price($item)) ?>
      </div>

      <?php if ($isMatrix): ?>
      <!-- ===== Clothing picker: size → colour, each combination with its own stock ===== -->
      <div class="item-variations ec-picker" id="itemVariations" data-mode="matrix">
        <?php if ($matrix['has_sizes']): ?>
        <div class="ec-opt-group">
          <div class="ec-opt-head">
            <span>Size: <b id="ecSizeLabel">Select a size</b></span>
            <button type="button" class="ec-size-guide-btn" onclick="document.getElementById('ecSizeGuide').showModal()"><i class="fa-solid fa-ruler"></i> Size guide</button>
          </div>
          <div class="ec-sizes" role="radiogroup" aria-label="Size">
            <?php foreach ($matrix['sizes'] as $sz): ?>
            <button type="button" class="ec-size<?= $sz['stock'] <= 0 ? ' is-out' : '' ?>" data-size="<?= e($sz['label']) ?>" role="radio" aria-checked="false" <?= $sz['stock'] <= 0 ? 'title="Sold out"' : '' ?>><?= e($sz['label']) ?></button>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($matrix['has_colors']): ?>
        <div class="ec-opt-group">
          <div class="ec-opt-head"><span>Colour: <b id="ecColorLabel">Select a colour</b></span></div>
          <div class="ec-colors" role="radiogroup" aria-label="Colour">
            <?php foreach ($matrix['colors'] as $c): ?>
            <button type="button" class="ec-color<?= $c['stock'] <= 0 ? ' is-out' : '' ?><?= $c['hex'] === '' ? ' no-hex' : '' ?>" data-color="<?= e($c['name']) ?>" role="radio" aria-checked="false" title="<?= e($c['name']) ?>" aria-label="<?= e($c['name']) ?>"<?= $c['hex'] !== '' ? ' style="--sw:' . e($c['hex']) . '"' : '' ?>>
              <span class="ec-color-dot"></span><?php if ($c['hex'] === ''): ?><span class="ec-color-txt"><?= e($c['name']) ?></span><?php endif; ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <p class="ec-stock-msg" id="ecStockMsg" aria-live="polite"></p>
        <input type="hidden" id="itemVariationId" value="">
      </div>
      <script type="application/json" id="ecVariants"><?= json_encode(array_merge(...array_map(fn($sz) => array_map(fn($o) => $o + ['size' => $sz['label']], $sz['options']), $matrix['sizes'] ?: [[ 'options' => [] ]])), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
      <script type="application/json" id="ecColorImages"><?= json_encode(array_column($matrix['colors'], 'image', 'name'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
      <?php elseif ($variations): ?>
      <div class="item-variations" id="itemVariations">
        <p class="item-variations-label">Choose an option:</p>
        <div class="item-variation-opts">
          <?php foreach ($variations as $v): $ve = variation_effective($item, $v); ?>
          <button type="button" class="var-opt<?= (int)$ve['stock_qty'] <= 0 ? ' is-out' : '' ?>"
                  data-variation-id="<?= (int)$v['id'] ?>"
                  data-price="<?= e($ve['price']) ?>"
                  data-old-price="<?= e($ve['old_price'] ?? '') ?>"
                  data-discount="<?= e($ve['discount'] ?? '') ?>"
                  data-save="<?= e($ve['save_amount'] ?? '') ?>"
                  data-stock="<?= (int)$ve['stock_qty'] ?>"
                  data-image="<?= $ve['image'] ? e(BASE_URL . $ve['image']) : '' ?>"
                  <?= (int)$ve['stock_qty'] <= 0 ? 'disabled title="Sold out"' : '' ?>>
            <?= e($v['variation_name']) ?>
          </button>
          <?php endforeach; ?>
        </div>
        <input type="hidden" id="itemVariationId" value="">
        <p class="item-variation-hint" id="itemVariationHint">Please select an option above.</p>
      </div>
      <?php endif; ?>

      <?php if ($item['warranty']): ?><p class="item-warranty"><i class="fa-solid fa-shield-halved"></i> <?= e($item['warranty']) ?> warranty</p><?php endif; ?>

      <?php $__outOfStock = ($item['stock_status'] ?? 'in_stock') === 'out_of_stock' || $allSoldOut; ?>
      <div class="item-actions">
        <?php if (!$__outOfStock): ?>
        <div class="qty-stepper">
          <button type="button" onclick="stepItemQty(-1)" aria-label="Decrease quantity">−</button>
          <input type="text" id="itemQty" value="1" inputmode="numeric" readonly aria-label="Quantity">
          <button type="button" onclick="stepItemQty(1)" aria-label="Increase quantity">+</button>
        </div>
        <button type="button" class="item-cta" id="itemAddToCart"><i class="fa-solid fa-bag-shopping"></i> <span>Add to Bag</span></button>
        <button type="button" class="item-cta ghost" id="itemBuyNow">Buy Now</button>
        <?php else: ?>
        <button type="button" class="item-cta item-cta-oos" disabled><i class="fa-solid fa-ban"></i> Sold Out</button>
        <?php endif; ?>
        <button type="button" class="item-wish-btn" id="itemWishBtn" aria-label="Add to wishlist"><i class="fa-regular fa-heart"></i></button>
        <?php $__itemPageWaLink = build_whatsapp_link("Hi, I'm interested in \"{$item['name']}\" — is it available?"); ?>
        <?php if ($__itemPageWaLink): ?>
        <a href="<?= e($__itemPageWaLink) ?>" target="_blank" rel="noopener" class="item-wish-btn item-wa-btn" aria-label="Ask about this item on WhatsApp" title="Ask on WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
        <?php endif; ?>
      </div>

      <div class="item-perks">
        <div><span class="ic"><i class="fa-solid fa-truck-fast"></i></span>Island-wide delivery in 2–4 days</div>
        <div><span class="ic"><i class="fa-solid fa-money-bill-wave"></i></span>Cash on delivery available</div>
        <div><span class="ic"><i class="fa-solid fa-arrows-rotate"></i></span>Easy size exchange within 7 days</div>
        <div><span class="ic"><i class="fa-solid fa-shirt"></i></span>Quality checked before dispatch</div>
      </div>
    </div>
  </section>

  <section class="item-tabs">
    <div class="item-tabs-nav">
      <button type="button" class="item-tab-btn active" data-tab="desc">Product Details</button>
      <button type="button" class="item-tab-btn" data-tab="spec">Specification</button>
      <button type="button" class="item-tab-btn" data-tab="size">Size Guide</button>
      <button type="button" class="item-tab-btn" data-tab="ship">Delivery &amp; Exchange</button>
    </div>
    <div class="item-tab-panel" id="tab-desc">
      <?php if ($item['description']): ?>
        <div class="item-desc-content"><?= $item['description'] ?></div>
      <?php else: ?>
        <p style="color:var(--muted)">No description provided for this item yet.</p>
      <?php endif; ?>
    </div>
    <div class="item-tab-panel" id="tab-spec" hidden>
      <table class="item-spec-table">
        <tr><th>SKU</th><td><?= e($item['sku']) ?></td></tr>
        <tr><th>Brand</th><td><?= e($item['brand_name'] ?: '—') ?></td></tr>
        <tr><th>Category</th><td><?= e($item['category_name'] ?: '—') ?></td></tr>
        <?php if ($isMatrix && $matrix['has_sizes']): ?><tr><th>Sizes</th><td><?= e(implode(', ', array_column($matrix['sizes'], 'label'))) ?></td></tr><?php endif; ?>
        <?php if ($isMatrix && $matrix['has_colors']): ?><tr><th>Colours</th><td><?= e(implode(', ', array_column($matrix['colors'], 'name'))) ?></td></tr><?php endif; ?>
        <?php if ($item['warranty']): ?><tr><th>Warranty</th><td><?= e($item['warranty']) ?></td></tr><?php endif; ?>
        <tr><th>Availability</th><td><?= $allSoldOut ? 'Sold Out' : ucwords(str_replace('_', ' ', $item['stock_status'])) ?></td></tr>
      </table>
    </div>
    <div class="item-tab-panel" id="tab-size" hidden>
      <?php require __DIR__ . '/includes/size_guide.php'; ?>
    </div>
    <div class="item-tab-panel" id="tab-ship" hidden>
      <ul class="ec-ship-list">
        <li><i class="fa-solid fa-truck-fast"></i> Island-wide courier delivery, usually within 2–4 working days.</li>
        <li><i class="fa-solid fa-money-bill-wave"></i> Pay by cash on delivery, bank transfer or card.</li>
        <li><i class="fa-solid fa-arrows-rotate"></i> Wrong size? Exchange within 7 days — item unworn, with tags attached.</li>
      </ul>
    </div>
  </section>

  <dialog class="ec-dialog" id="ecSizeGuide" aria-label="Size guide">
    <div class="ec-dialog-head"><h3>Size Guide</h3><button type="button" onclick="this.closest('dialog').close()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
    <?php require __DIR__ . '/includes/size_guide.php'; ?>
  </dialog>

  <?php if ($related): ?>
  <div class="sec-head"><h2>You May Also Like</h2></div>
  <section class="prod-grid ec-related">
    <?php foreach ($related as $ri) { render_product_card($ri, $relatedSummary[(int)$ri['id']] ?? null); } ?>
  </section>
  <?php endif; ?>

</main>

<script>
function switchItemImage(src, btn){
  const main = document.getElementById('itemMainImg');
  if (main) main.src = src;
  document.querySelectorAll('.item-thumb').forEach(t=>t.classList.remove('active'));
  btn.classList.add('active');
}
function stepItemQty(delta){
  const inp = document.getElementById('itemQty');
  let v = parseInt(inp.value, 10) || 1;
  const max = (typeof itemMaxQty !== 'undefined') ? itemMaxQty : 99;
  v = Math.min(max, Math.max(1, v + delta));
  inp.value = v;
  if (delta > 0 && v === max && max < 99 && typeof showToast === 'function') showToast('Only ' + max + ' available in this size / colour.');
}
document.querySelectorAll('.item-tab-btn').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    document.querySelectorAll('.item-tab-btn').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('.item-tab-panel').forEach(p=>p.hidden = true);
    btn.classList.add('active');
    document.getElementById('tab-' + btn.dataset.tab).hidden = false;
  });
});
const itemAddBtn = document.getElementById('itemAddToCart');
const itemBuyBtn = document.getElementById('itemBuyNow');
const itemPriceBlock = document.getElementById('itemPriceBlock');
const itemVariationId = document.getElementById('itemVariationId');
const itemVariationHint = document.getElementById('itemVariationHint');

/* Which "pay in installments" badges are on (Admin -> Site Configuration ->
   Payment Gateways) — mirrors payment_plan_html() in includes/functions.php
   so the lines survive a variation swap instead of disappearing when
   itemPriceBlock's innerHTML gets rebuilt below. */
const PAYMENT_PLANS = {
  koko:    <?= !empty($__siteSettingsForPlans['enable_koko']) ? 'true' : 'false' ?>,
  mintpay: <?= !empty($__siteSettingsForPlans['enable_mintpay']) ? 'true' : 'false' ?>,
  payzy:   <?= !empty($__siteSettingsForPlans['enable_payzy']) ? 'true' : 'false' ?>
};
const KOKO_LOGO_URL = '<?= e(koko_logo_url()) ?>';
/* Admin -> Items -> "KOKO Price (optional)" — a flat KOKO-only price for this item,
   independent of which variation is selected. 0 = the admin hasn't set one, in which
   case the KOKO instalment line is not shown at all (matches the PHP-rendered version). */
const ITEM_KOKO_PRICE = <?= (float)($item['koko_price'] ?? 0) ?>;

function buildPayPlanHtml(price){
  if (!price || (!PAYMENT_PLANS.koko && !PAYMENT_PLANS.mintpay && !PAYMENT_PLANS.payzy)) return '';
  let rows = '';
  if (PAYMENT_PLANS.koko && ITEM_KOKO_PRICE > 0){
    rows += '<p class="pp-line pp-line-koko">or 3 X ' + fmtMoney(ITEM_KOKO_PRICE / 3) + ' with '
      + '<img src="' + KOKO_LOGO_URL + '" alt="KOKO" class="pp-koko-logo">'
      + '<span class="pp-info" tabindex="0" role="button" aria-label="What is KOKO?" title="KOKO — Buy Now, Pay Later. Split this purchase into 3 interest-free instalments."><i class="fa-solid fa-circle-info"></i></span></p>';
  }
  if (PAYMENT_PLANS.mintpay){
    rows += '<p class="pp-line pp-line-mintpay">or 3 X ' + fmtMoney(price / 3) + ' with <b class="pp-mintpay">Mintpay</b></p>';
  }
  if (PAYMENT_PLANS.payzy){
    rows += '<p class="pp-line pp-line-payzy">or up to 4 x ' + fmtMoney(price / 4) + ' with <b class="pp-payzy">PayZy</b></p>';
  }
  return '<div class="pay-plan">' + rows + '</div>';
}

function fmtMoney(n){
  return 'Rs. ' + Number(n).toLocaleString('en-LK', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function renderPriceBlock(price, oldPrice, discount, save){
  if (!itemPriceBlock) return;
  let html = '';
  if (oldPrice) html += '<span class="item-old-price">' + fmtMoney(oldPrice) + '</span>';
  html += '<div class="item-price">' + fmtMoney(price) + '</div>';
  if (discount) html += '<span class="item-save">Save ' + discount + '% · ' + fmtMoney(save) + '</span>';
  html += buildPayPlanHtml(price);
  itemPriceBlock.innerHTML = html;
}
function setMainImage(src){ const main = document.getElementById('itemMainImg'); if (main && src) main.src = src; }
const itemQtyInput = document.getElementById('itemQty');
let itemMaxQty = 99;
function setBuyEnabled(on){
  if (itemAddBtn) itemAddBtn.disabled = !on;
  if (itemBuyBtn) itemBuyBtn.disabled = !on;
}

/* ---- Old-style flat option list ---- */
document.querySelectorAll('.var-opt').forEach(function(opt){
  opt.addEventListener('click', function(){
    if (opt.disabled) return;
    document.querySelectorAll('.var-opt').forEach(function(o){ o.classList.remove('active'); });
    opt.classList.add('active');
    if (itemVariationId) itemVariationId.value = opt.dataset.variationId;
    if (itemVariationHint) itemVariationHint.style.display = 'none';
    renderPriceBlock(parseFloat(opt.dataset.price), opt.dataset.oldPrice ? parseFloat(opt.dataset.oldPrice) : null, opt.dataset.discount, opt.dataset.save);
    setMainImage(opt.dataset.image);
    itemMaxQty = Math.max(1, parseInt(opt.dataset.stock, 10) || 1);
  });
});

/* ---- Clothing picker: Size → Colour with live stock per combination ---- */
(function(){
  const box = document.querySelector('.ec-picker');
  if (!box) return;
  const variants = JSON.parse(document.getElementById('ecVariants').textContent || '[]');
  const colorImages = JSON.parse(document.getElementById('ecColorImages').textContent || '{}');
  const sizeBtns  = [...box.querySelectorAll('.ec-size')];
  const colorBtns = [...box.querySelectorAll('.ec-color')];
  const hasSizes = sizeBtns.length > 0, hasColors = colorBtns.length > 0;
  const msg = document.getElementById('ecStockMsg');
  const sizeLabel = document.getElementById('ecSizeLabel'), colorLabel = document.getElementById('ecColorLabel');
  const norm = v => String(v || '').trim().toLowerCase();
  let selSize = '', selColor = '';

  function stockFor(size, color){
    return variants.filter(v => (!hasSizes || !size || norm(v.size) === norm(size)) && (!hasColors || !color || norm(v.color) === norm(color)))
                   .reduce((t, v) => t + (v.stock || 0), 0);
  }
  function current(){
    if (hasSizes && !selSize) return null;
    if (hasColors && !selColor) return null;
    return variants.find(v => (!hasSizes || norm(v.size) === norm(selSize)) && (!hasColors || norm(v.color) === norm(selColor))) || null;
  }
  function refresh(){
    /* cross out sizes sold out in the chosen colour, and colours sold out in the chosen size */
    sizeBtns.forEach(b => {
      const out = stockFor(b.dataset.size, selColor) <= 0;
      b.classList.toggle('is-out', out);
      b.classList.toggle('active', norm(b.dataset.size) === norm(selSize));
      b.setAttribute('aria-checked', norm(b.dataset.size) === norm(selSize) ? 'true' : 'false');
    });
    colorBtns.forEach(b => {
      const exists = !hasSizes || !selSize || variants.some(v => norm(v.size) === norm(selSize) && norm(v.color) === norm(b.dataset.color));
      b.hidden = !exists;
      b.classList.toggle('is-out', stockFor(selSize, b.dataset.color) <= 0);
      b.classList.toggle('active', norm(b.dataset.color) === norm(selColor));
      b.setAttribute('aria-checked', norm(b.dataset.color) === norm(selColor) ? 'true' : 'false');
    });
    if (sizeLabel) sizeLabel.textContent = selSize || 'Select a size';
    if (colorLabel) colorLabel.textContent = selColor || 'Select a colour';

    const v = current();
    if (itemVariationId) itemVariationId.value = v && v.stock > 0 ? v.id : '';
    if (v) {
      renderPriceBlock(v.price, v.old_price, v.discount, v.save);
      if (v.image) setMainImage(v.image); else if (selColor && colorImages[selColor]) setMainImage(colorImages[selColor]);
      itemMaxQty = Math.max(1, Math.min(99, v.stock));
      if (itemQtyInput && parseInt(itemQtyInput.value, 10) > itemMaxQty) itemQtyInput.value = itemMaxQty;
      if (v.stock <= 0) { msg.className = 'ec-stock-msg is-out'; msg.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> ' + [selSize, selColor].filter(Boolean).join(' / ') + ' is sold out — try another ' + (hasColors && hasSizes ? 'size or colour' : 'option') + '.'; }
      else if (v.stock <= 3) { msg.className = 'ec-stock-msg is-low'; msg.innerHTML = '<i class="fa-solid fa-fire"></i> Hurry — only ' + v.stock + ' left in stock!'; }
      else { msg.className = 'ec-stock-msg is-in'; msg.innerHTML = '<i class="fa-solid fa-circle-check"></i> In stock — ready to ship'; }
      setBuyEnabled(v.stock > 0);
    } else {
      if (selColor && colorImages[selColor]) setMainImage(colorImages[selColor]);
      msg.className = 'ec-stock-msg';
      msg.textContent = hasSizes && !selSize ? 'Please select a size.' : (hasColors && !selColor ? 'Please select a colour.' : '');
      setBuyEnabled(true); // clicking will prompt for the missing choice
    }
  }
  sizeBtns.forEach(b => b.addEventListener('click', () => {
    selSize = norm(selSize) === norm(b.dataset.size) ? '' : b.dataset.size;
    /* drop a colour that this size doesn't come in */
    if (selSize && selColor && !variants.some(v => norm(v.size) === norm(selSize) && norm(v.color) === norm(selColor))) selColor = '';
    /* auto-pick the colour when this size comes in only one */
    if (selSize && hasColors && !selColor) {
      const cs = variants.filter(v => norm(v.size) === norm(selSize));
      if (cs.length === 1) selColor = cs[0].color;
    }
    refresh();
  }));
  colorBtns.forEach(b => b.addEventListener('click', () => {
    selColor = norm(selColor) === norm(b.dataset.color) ? '' : b.dataset.color;
    refresh();
  }));

  /* preselect: ?size=M from a product card, or the only size / colour there is */
  const params = new URLSearchParams(location.search);
  const want = params.get('size');
  if (want && sizeBtns.some(b => norm(b.dataset.size) === norm(want))) selSize = sizeBtns.find(b => norm(b.dataset.size) === norm(want)).dataset.size;
  if (hasSizes && sizeBtns.length === 1) selSize = sizeBtns[0].dataset.size;
  if (hasColors && colorBtns.length === 1) selColor = colorBtns[0].dataset.color;
  if (selSize && hasColors && !selColor) {
    const cs = variants.filter(v => norm(v.size) === norm(selSize) && v.stock > 0);
    if (cs.length === 1) selColor = cs[0].color;
  }
  refresh();
  window.ecPickerMissing = function(){
    if (hasSizes && !selSize) return 'Please select your size.';
    if (hasColors && !selColor) return 'Please select a colour.';
    const v = current();
    if (v && v.stock <= 0) return 'That size / colour is sold out.';
    return '';
  };
})();

/* ---- Add to cart / Buy now (real, session-backed via cart.php) ---- */
const itemId = <?= (int)$item['id'] ?>;
function addCurrentItemToCart(){
  return new Promise(function(resolve){
    if (document.getElementById('itemVariations') && (!itemVariationId || !itemVariationId.value)) {
      const why = (typeof window.ecPickerMissing === 'function' && window.ecPickerMissing()) || 'Please select an option first.';
      if (typeof showToast === 'function') showToast('<i class="fa-solid fa-triangle-exclamation"></i> ' + why);
      const picker = document.getElementById('itemVariations');
      if (picker) { picker.classList.remove('ec-shake'); void picker.offsetWidth; picker.classList.add('ec-shake'); }
      resolve(null);
      return;
    }
    const qty = parseInt(document.getElementById('itemQty').value, 10) || 1;
    const params = { action: 'add', item_id: itemId, qty: qty };
    if (itemVariationId && itemVariationId.value) params.variation_id = itemVariationId.value;

    fetch('<?= BASE_URL ?>/cart.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(params).toString()
    }).then(function(r){ return r.json(); }).then(function(data){
      if (!data.ok) {
        if (typeof showToast === 'function') showToast('<i class="fa-solid fa-triangle-exclamation"></i> ' + (data.error || 'Could not add to cart.'));
        resolve(null);
        return;
      }
      const badge = document.getElementById('cartCount'), mbadge = document.getElementById('mnavCartCount');
      if (badge) badge.textContent = data.count;
      if (mbadge) mbadge.textContent = data.count;
      if (typeof renderCart === 'function') renderCart(data);
      if (typeof showToast === 'function') showToast('<i class="fa-solid fa-circle-check"></i> Added to bag — ' + data.count + ' item' + (data.count > 1 ? 's' : ''));
      resolve(data);
    }).catch(function(){
      if (typeof showToast === 'function') showToast('<i class="fa-solid fa-triangle-exclamation"></i> Could not add to cart.');
      resolve(null);
    });
  });
}
if (itemAddBtn) { itemAddBtn.addEventListener('click', function(){ addCurrentItemToCart(); }); }
if (itemBuyBtn) {
  itemBuyBtn.addEventListener('click', function(){
    addCurrentItemToCart().then(function(data){
      if (data && typeof openCartDrawer === 'function') openCartDrawer();
    });
  });
}
const itemWishBtn = document.getElementById('itemWishBtn');
if (itemWishBtn) {
  itemWishBtn.addEventListener('click', function(){
    const saved = itemWishBtn.classList.toggle('saved');
    itemWishBtn.innerHTML = saved ? '<i class="fa-solid fa-heart"></i>' : '<i class="fa-regular fa-heart"></i>';
    if (typeof showToast === 'function') showToast(saved ? '<i class="fa-solid fa-heart"></i> Saved to wishlist' : 'Removed from wishlist');
  });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>