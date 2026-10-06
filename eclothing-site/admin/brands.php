<?php
/**
 * admin/brands.php — Brand Manager
 * Brand name + logo + home-page settings. Used by the Brand select on
 * admin/items.php AND by the storefront "Shop by Brand" section / menu
 * (includes/store_content.php → dewansa_home_brands()).
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Brands';
$active    = 'brands';

/* ============================================================
   AUTO-MIGRATE
   ============================================================ */
db()->exec("
CREATE TABLE IF NOT EXISTS brands (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  image      VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_brand_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
ensure_brands_table(); // adds show_on_home / sort_order / tagline + seeds default brands

/* ============================================================
   Helpers
   ============================================================ */
function process_brand_image_upload(string $field, ?string $existingPath): array
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return [$existingPath, null];
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return [$existingPath, 'Brand image upload failed — please try again.'];
    }
    if ($_FILES[$field]['size'] > 2 * 1024 * 1024) {
        return [$existingPath, 'Brand image is too large (max 2MB).'];
    }
    $allowedExt = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    $ext = strtolower(pathinfo((string)$_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!isset($allowedExt[$ext])) {
        return [$existingPath, 'Brand image must be a JPG, PNG, WEBP or GIF.'];
    }
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($_FILES[$field]['tmp_name']);
        if ($mime && !in_array($mime, $allowedExt, true)) {
            return [$existingPath, 'That file does not look like a valid image.'];
        }
    }
    $dir = __DIR__ . '/../assets/uploads/brands';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "php_flag engine off\n<FilesMatch \"\\.php$\">\nRequire all denied\n</FilesMatch>\n"); }

    $filename = 'brand_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $filename)) {
        return [$existingPath, 'Could not save the brand image.'];
    }
    if ($existingPath) {
        $old = __DIR__ . '/../' . ltrim($existingPath, '/');
        if (is_file($old)) { @unlink($old); }
    }
    return ['/assets/uploads/brands/' . $filename, null];
}

/* ============================================================
   POST actions
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_brand') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $tagline    = trim((string)($_POST['tagline'] ?? ''));
        $sortOrder  = (int)($_POST['sort_order'] ?? 0);
        $showOnHome = isset($_POST['show_on_home']) ? 1 : 0;
        $err  = '';
        if (mb_strlen($tagline) > 160) { $tagline = mb_substr($tagline, 0, 160); }

        if ($name === '' || mb_strlen($name) > 120) {
            $err = 'Brand name is required (max 120 characters).';
        } else {
            $dup = db()->prepare('SELECT id FROM brands WHERE name = ? AND id != ?');
            $dup->execute([$name, $id]);
            if ($dup->fetch()) { $err = 'A brand with that name already exists.'; }
        }

        $existingImage = null;
        if ($id) {
            $st = db()->prepare('SELECT image FROM brands WHERE id = ?');
            $st->execute([$id]);
            $existingImage = $st->fetchColumn() ?: null;
        }

        $imagePath = $existingImage;
        if ($err === '') {
            [$imagePath, $upErr] = process_brand_image_upload('image', $existingImage);
            if ($upErr) { $err = $upErr; }
        }

        if ($err === '') {
            if ($id) {
                db()->prepare('UPDATE brands SET name = ?, image = ?, tagline = ?, sort_order = ?, show_on_home = ? WHERE id = ?')->execute([$name, $imagePath, $tagline ?: null, $sortOrder, $showOnHome, $id]);
                flash('ok', 'Brand updated.');
            } else {
                db()->prepare('INSERT INTO brands (name, image, tagline, sort_order, show_on_home) VALUES (?, ?, ?, ?, ?)')->execute([$name, $imagePath, $tagline ?: null, $sortOrder, $showOnHome]);
                flash('ok', 'Brand added.');
            }
        } else {
            flash('err', $err);
        }
        redirect('/admin/brands');
    }

    if ($action === 'toggle_home') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE brands SET show_on_home = 1 - show_on_home WHERE id = ?')->execute([$id]);
        flash('ok', 'Shop by Brand visibility updated.');
        redirect('/admin/brands');
    }

    if ($action === 'delete_brand') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT image FROM brands WHERE id = ?');
        $st->execute([$id]);
        if ($img = $st->fetchColumn()) {
            $f = __DIR__ . '/../' . ltrim($img, '/');
            if (is_file($f)) { @unlink($f); }
        }
        db()->prepare('DELETE FROM brands WHERE id = ?')->execute([$id]);
        db()->prepare('UPDATE items SET brand_id = NULL WHERE brand_id = ?')->execute([$id]);
        flash('ok', 'Brand deleted.');
        redirect('/admin/brands');
    }
}

$brands = db()->query('SELECT b.*, (SELECT COUNT(*) FROM items i WHERE i.brand_id = b.id) AS item_count
                        FROM brands b ORDER BY b.show_on_home DESC, b.sort_order ASC, b.name ASC')->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success">✔ <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error">⚠ <?= e($m) ?></div><?php endif; ?>

<div class="panel">
  <div class="panel-head">
    <h3>Brands (<?= count($brands) ?>)</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="mini-btn toggle" href="<?= BASE_URL ?>/#brands" target="_blank" rel="noopener"><i class="fa-solid fa-store"></i> See Shop by Brand</a>
      <button type="button" class="btn-add" onclick="openAddBrand()">+ Add Brand</button>
    </div>
  </div>
  <p class="hint" style="padding:12px 20px 0;margin:0">Brands marked <b>Shown</b> appear in the home page "Shop by Brand" section, the menu and the footer. Upload a logo to replace the letter tile. <b>Hover text</b> is what customers see when they move the mouse over the tile. Lower <b>Order</b> numbers show first.</p>
  <div class="panel-body" style="padding:0">
    <table>
      <thead><tr><th></th><th>Brand</th><th>Hover text</th><th>Order</th><th>Items</th><th>Shop by Brand</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($brands as $b): ?>
        <tr>
          <td>
            <?php if ($b['image']): ?>
              <img class="items-thumb" src="<?= BASE_URL . e($b['image']) ?>" alt="" style="object-fit:contain;background:#fff">
            <?php else: ?>
              <span class="items-thumb" style="display:grid;place-items:center;font-size:16px;font-weight:800;color:#141414;background:#eef5ff"><?= e(mb_strtoupper(mb_substr($b['name'], 0, 1))) ?></span>
            <?php endif; ?>
          </td>
          <td><b><?= e($b['name']) ?></b><?php if (!$b['image']): ?><br><small style="color:var(--muted)">No logo yet — tile shows the first letter</small><?php endif; ?></td>
          <td style="color:var(--muted);font-size:13px"><?= $b['tagline'] ? e($b['tagline']) : '<i>Shop ' . e($b['name']) . ' parts</i>' ?></td>
          <td><?= (int)$b['sort_order'] ?></td>
          <td>
            <a href="<?= BASE_URL ?>/admin/items?brand=<?= (int)$b['id'] ?>" title="Show this brand's items in the admin panel"><?= (int)$b['item_count'] ?></a>
          </td>
          <td>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_home">
              <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
              <button type="submit" class="tag <?= $b['show_on_home'] ? 'tag-on' : 'tag-off' ?>" style="border:none;cursor:pointer" title="Click to <?= $b['show_on_home'] ? 'hide from' : 'show in' ?> Shop by Brand">
                <?= $b['show_on_home'] ? 'Shown' : 'Hidden' ?>
              </button>
            </form>
          </td>
          <td>
            <div class="row-actions">
              <a class="mini-btn toggle" href="<?= e(brand_shop_url((int)$b['id'])) ?>" target="_blank" rel="noopener" title="Open this brand's page on the website"><i class="fa-solid fa-arrow-up-right-from-square"></i> View on site</a>
              <button type="button" class="mini-btn edit" onclick='openEditBrand(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>Edit</button>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this brand? Items using it will just show no brand.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_brand">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button class="mini-btn del" type="submit">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$brands): ?>
        <tr><td colspan="7" style="color:var(--muted)">No brands yet — click "+ Add Brand" to create one.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== MODAL: Add/Edit Brand ===== -->
<div id="brandOverlay" class="modal-overlay" onclick="if(event.target===this) closeBrand()">
  <div class="modal-box">
    <h3 id="brandTitle">Add Brand</h3>
    <form method="post" enctype="multipart/form-data" class="form-grid" id="brandForm" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="brand_action" value="save_brand">
      <input type="hidden" name="id" id="brand_id">
      <label style="grid-column:1/-1">Brand Name
        <input type="text" name="name" id="brand_name" required maxlength="120" placeholder="e.g. Maruti">
      </label>
      <label style="grid-column:1/-1">Hover text (shown on mouse-over, optional)
        <input type="text" name="tagline" id="brand_tagline" maxlength="160" placeholder="e.g. Swift, Wagon R, Alto 800 parts">
      </label>
      <label>Display order
        <input type="number" name="sort_order" id="brand_sort" value="0" step="1">
      </label>
      <label style="display:flex;align-items:center;gap:8px;align-self:end;padding-bottom:10px">
        <input type="checkbox" name="show_on_home" id="brand_show" value="1" checked style="width:auto"> Show in "Shop by Brand"
      </label>
      <label style="grid-column:1/-1">Brand Image (optional)
        <div class="logo-preview" id="brand_preview" style="height:90px;cursor:pointer"
             onclick="document.getElementById('brand_image_input').click()"
             ondragover="event.preventDefault();event.stopPropagation();this.classList.add('drag-over')"
             ondragleave="this.classList.remove('drag-over')"
             ondrop="handleBrandImageDrop(event)">
          <span class="ph">🖼 No image uploaded</span>
        </div>
        <input type="file" name="image" id="brand_image_input" accept=".jpg,.jpeg,.png,.webp,.gif" style="display:none" onchange="previewBrandImageFromInput(this)">
        <p class="hint">Drag &amp; drop, paste with Ctrl+V, or click above to upload — JPG, PNG, WEBP or GIF, max 2MB.</p>
      </label>
      <div style="display:flex;gap:10px;grid-column:1/-1">
        <button type="submit" class="btn-add">Save</button>
        <button type="button" class="mini-btn toggle" onclick="closeBrand()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAddBrand(){
  document.getElementById('brandTitle').textContent = 'Add Brand';
  document.getElementById('brand_action').value = 'save_brand';
  document.getElementById('brandForm').reset();
  document.getElementById('brand_id').value = '';
  document.getElementById('brand_show').checked = true;
  document.getElementById('brand_sort').value = 0;
  document.getElementById('brand_preview').innerHTML = '<span class="ph">🖼 No image uploaded</span>';
  document.getElementById('brandOverlay').classList.add('show');
}
function openEditBrand(b){
  document.getElementById('brandTitle').textContent = 'Edit Brand';
  document.getElementById('brand_action').value = 'save_brand';
  document.getElementById('brand_id').value = b.id;
  document.getElementById('brand_name').value = b.name;
  document.getElementById('brand_tagline').value = b.tagline || '';
  document.getElementById('brand_sort').value = b.sort_order || 0;
  document.getElementById('brand_show').checked = String(b.show_on_home) === '1';
  document.getElementById('brand_preview').innerHTML = b.image
    ? `<img src="<?= BASE_URL ?>${b.image}" alt="">`
    : '<span class="ph">🖼 No image uploaded</span>';
  document.getElementById('brandOverlay').classList.add('show');
}
function closeBrand(){ document.getElementById('brandOverlay').classList.remove('show'); }

/* ---- Brand image: drag & drop, paste (Ctrl+V), or click to upload ---- */
function showBrandPreviewFile(file){
  const reader = new FileReader();
  reader.onload = e => {
    document.getElementById('brand_preview').innerHTML = `<img src="${e.target.result}" alt="">`;
  };
  reader.readAsDataURL(file);
}
function assignBrandFile(file){
  if (!file || !file.type || !file.type.startsWith('image/')) return;
  const input = document.getElementById('brand_image_input');
  const dt = new DataTransfer();
  dt.items.add(file);
  input.files = dt.files;
  showBrandPreviewFile(file);
}
function previewBrandImageFromInput(input){
  const file = input.files[0];
  if (file) showBrandPreviewFile(file);
}
function handleBrandImageDrop(e){
  e.preventDefault(); e.stopPropagation();
  document.getElementById('brand_preview').classList.remove('drag-over');
  const files = e.dataTransfer.files;
  if (files.length) assignBrandFile(files[0]);
}
document.addEventListener('paste', e => {
  const overlay = document.getElementById('brandOverlay');
  if (!overlay || !overlay.classList.contains('show')) return;
  const items = e.clipboardData ? e.clipboardData.items : [];
  for (let i = 0; i < items.length; i++) {
    if (items[i].type && items[i].type.startsWith('image/')) {
      assignBrandFile(items[i].getAsFile());
    }
  }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>