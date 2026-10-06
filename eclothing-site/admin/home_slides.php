<?php
/**
 * admin/home_slides.php — Home Slides
 * Manages the homepage hero slideshow (the big rotating banner at the top
 * of index.php). Each slide is a headline + description + up to two
 * buttons + a background image, shown/hidden and reordered from here.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Home Slides';
$active    = 'home_slides';

ensure_home_slides_table();

/** Handles the slide background image upload — same validation/storage pattern as admin/brands.php. */
function process_slide_image_upload(string $field, ?string $existingPath): array
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return [$existingPath, null];
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return [$existingPath, 'Slide image upload failed — please try again.'];
    }
    if ($_FILES[$field]['size'] > 4 * 1024 * 1024) {
        return [$existingPath, 'Slide image is too large (max 4MB).'];
    }
    $allowedExt = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $ext = strtolower(pathinfo((string)$_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!isset($allowedExt[$ext])) {
        return [$existingPath, 'Slide image must be a JPG, PNG or WEBP.'];
    }
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($_FILES[$field]['tmp_name']);
        if ($mime && !in_array($mime, $allowedExt, true)) {
            return [$existingPath, 'That file does not look like a valid image.'];
        }
    }
    $dir = __DIR__ . '/../assets/uploads/slides';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "php_flag engine off\n<FilesMatch \"\\.php$\">\nRequire all denied\n</FilesMatch>\n"); }

    $filename = 'slide_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $filename)) {
        return [$existingPath, 'Could not save the slide image.'];
    }
    if ($existingPath) {
        $old = __DIR__ . '/../' . ltrim($existingPath, '/');
        if (is_file($old)) { @unlink($old); }
    }
    return ['/assets/uploads/slides/' . $filename, null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_slide') {
        $id          = (int)($_POST['id'] ?? 0);
        $eyebrow     = trim((string)($_POST['eyebrow'] ?? ''));
        $heading     = trim((string)($_POST['heading'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $btnText     = trim((string)($_POST['button_text'] ?? ''));
        $btnLink     = trim((string)($_POST['button_link'] ?? ''));
        $btn2Text    = trim((string)($_POST['button2_text'] ?? ''));
        $btn2Link    = trim((string)($_POST['button2_link'] ?? ''));
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        $err = '';
        if ($heading === '' || mb_strlen($heading) > 300) {
            $err = 'Please enter a heading for the slide (max 300 characters).';
        }

        $existingImage = null;
        if ($id) {
            $st = db()->prepare('SELECT image FROM home_slides WHERE id = ?');
            $st->execute([$id]);
            $existingImage = $st->fetchColumn() ?: null;
        }

        $imagePath = $existingImage;
        if ($err === '') {
            [$imagePath, $upErr] = process_slide_image_upload('image', $existingImage);
            if ($upErr) { $err = $upErr; }
        }
        if ($err === '' && !$id && !$imagePath) {
            $err = 'Please upload a background image for the slide.';
        }

        if ($err === '') {
            if ($id) {
                db()->prepare('UPDATE home_slides SET eyebrow=?, heading=?, description=?, button_text=?, button_link=?, button2_text=?, button2_link=?, image=?, is_active=? WHERE id=?')
                    ->execute([$eyebrow ?: null, $heading, $description ?: null, $btnText ?: null, $btnLink ?: null, $btn2Text ?: null, $btn2Link ?: null, $imagePath, $isActive, $id]);
                flash('ok', 'Slide updated.');
            } else {
                $nextOrder = (int)db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM home_slides')->fetchColumn();
                db()->prepare('INSERT INTO home_slides (eyebrow, heading, description, button_text, button_link, button2_text, button2_link, image, is_active, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$eyebrow ?: null, $heading, $description ?: null, $btnText ?: null, $btnLink ?: null, $btn2Text ?: null, $btn2Link ?: null, $imagePath, $isActive, $nextOrder]);
                flash('ok', 'Slide added.');
            }
        } else {
            flash('err', $err);
        }
        redirect('/admin/home_slides');
    }

    if ($action === 'delete_slide') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT image FROM home_slides WHERE id = ?');
        $st->execute([$id]);
        if ($img = $st->fetchColumn()) {
            $f = __DIR__ . '/../' . ltrim($img, '/');
            if (is_file($f)) { @unlink($f); }
        }
        db()->prepare('DELETE FROM home_slides WHERE id = ?')->execute([$id]);
        flash('ok', 'Slide deleted.');
        redirect('/admin/home_slides');
    }

    if ($action === 'move_slide') {
        $id  = (int)($_POST['id'] ?? 0);
        $dir = $_POST['dir'] ?? '';
        $slides = db()->query('SELECT id, sort_order FROM home_slides ORDER BY sort_order ASC, id ASC')->fetchAll();
        $ids = array_column($slides, 'id');
        $pos = array_search($id, $ids, true);
        if ($pos !== false) {
            $swapWith = $dir === 'up' ? $pos - 1 : $pos + 1;
            if (isset($ids[$swapWith])) {
                $a = $slides[$pos]; $b = $slides[$swapWith];
                db()->prepare('UPDATE home_slides SET sort_order = ? WHERE id = ?')->execute([$b['sort_order'], $a['id']]);
                db()->prepare('UPDATE home_slides SET sort_order = ? WHERE id = ?')->execute([$a['sort_order'], $b['id']]);
            }
        }
        redirect('/admin/home_slides');
    }
}

$slides = get_home_slides(false);

require __DIR__ . '/includes/header.php';
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success">✔ <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error">⚠ <?= e($m) ?></div><?php endif; ?>

<div class="panel">
  <div class="panel-head">
    <h3>Home Slides (<?= count($slides) ?>)</h3>
    <button type="button" class="btn-add" onclick="openAddSlide()">+ Add Slide</button>
  </div>
  <div class="panel-body" style="padding:0">
    <table>
      <thead><tr><th></th><th>Heading</th><th>Status</th><th>Order</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($slides as $i => $s): ?>
        <tr>
          <td>
            <?php if ($s['image']): ?>
              <img class="items-thumb" src="<?= BASE_URL . e($s['image']) ?>" alt="">
            <?php else: ?>
              <span class="items-thumb" style="display:grid;place-items:center;font-size:16px">🖼️</span>
            <?php endif; ?>
          </td>
          <td>
            <b><?= e($s['heading']) ?></b>
            <?php if ($s['eyebrow']): ?><div class="hint"><?= e($s['eyebrow']) ?></div><?php endif; ?>
          </td>
          <td><?= $s['is_active'] ? '<span class="verified-badge">Active</span>' : '<span class="hint">Hidden</span>' ?></td>
          <td>
            <div class="row-actions">
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="move_slide">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <input type="hidden" name="dir" value="up">
                <button class="mini-btn toggle" type="submit" <?= $i === 0 ? 'disabled' : '' ?>><i class="fa-solid fa-arrow-up"></i></button>
              </form>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="move_slide">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <input type="hidden" name="dir" value="down">
                <button class="mini-btn toggle" type="submit" <?= $i === count($slides) - 1 ? 'disabled' : '' ?>><i class="fa-solid fa-arrow-down"></i></button>
              </form>
            </div>
          </td>
          <td>
            <div class="row-actions">
              <button type="button" class="mini-btn edit" onclick='openEditSlide(<?= htmlspecialchars(json_encode($s), ENT_QUOTES, "UTF-8") ?>)'>Edit</button>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this slide?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_slide">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button class="mini-btn del" type="submit">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$slides): ?>
        <tr><td colspan="5" style="color:var(--muted)">No slides yet — click "+ Add Slide" to create the first one. Until then, the homepage shows its built-in default slides.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== MODAL: Add/Edit Slide ===== -->
<div id="slideOverlay" class="modal-overlay" onclick="if(event.target===this) closeSlide()">
  <div class="modal-box" style="max-width:640px">
    <h3 id="slideTitle">Add Slide</h3>
    <form method="post" enctype="multipart/form-data" class="form-grid" id="slideForm" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="slide_action" value="save_slide">
      <input type="hidden" name="id" id="slide_id">

      <label style="grid-column:1/-1">Background Image <span class="hint" style="font-weight:400">(recommended ~1400×500px)</span>
        <div class="logo-preview" id="slide_preview" style="height:140px;cursor:pointer"
             onclick="document.getElementById('slide_image_input').click()"
             ondragover="event.preventDefault();event.stopPropagation();this.classList.add('drag-over')"
             ondragleave="this.classList.remove('drag-over')"
             ondrop="handleSlideImageDrop(event)">
          <span class="ph">🖼 No image uploaded</span>
        </div>
        <input type="file" name="image" id="slide_image_input" accept=".jpg,.jpeg,.png,.webp" style="display:none" onchange="previewSlideImageFromInput(this)">
        <p class="hint">Drag &amp; drop, paste with Ctrl+V, or click above to upload — JPG, PNG or WEBP, max 4MB.</p>
      </label>

      <label>Eyebrow Tag <span class="hint" style="font-weight:400">(optional)</span>
        <input type="text" name="eyebrow" id="slide_eyebrow" maxlength="150" placeholder="e.g. Mega Red Sale · Up to 45% Off">
      </label>
      <label>Heading
        <input type="text" name="heading" id="slide_heading" required maxlength="300" placeholder="e.g. Smart 4K TVs from Rs. 79,990">
      </label>
      <label style="grid-column:1/-1">Description <span class="hint" style="font-weight:400">(optional)</span>
        <textarea name="description" id="slide_description" rows="2" maxlength="500"></textarea>
      </label>
      <label>Button 1 Text <span class="hint" style="font-weight:400">(optional)</span>
        <input type="text" name="button_text" id="slide_button_text" maxlength="80" placeholder="e.g. Shop TV Deals">
      </label>
      <label>Button 1 Link
        <input type="text" name="button_link" id="slide_button_link" maxlength="255" placeholder="/products?category=tvs">
      </label>
      <label>Button 2 Text <span class="hint" style="font-weight:400">(optional)</span>
        <input type="text" name="button2_text" id="slide_button2_text" maxlength="80" placeholder="e.g. View All Offers">
      </label>
      <label>Button 2 Link
        <input type="text" name="button2_link" id="slide_button2_link" maxlength="255" placeholder="/products">
      </label>

      <div class="choice-group" style="grid-column:1/-1">
        <label class="choice-pill">
          <input type="checkbox" name="is_active" id="slide_is_active" checked>
          <span>Show this slide on the homepage</span>
        </label>
      </div>

      <div style="display:flex;gap:10px;grid-column:1/-1">
        <button type="submit" class="btn-add">Save</button>
        <button type="button" class="mini-btn toggle" onclick="closeSlide()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAddSlide(){
  document.getElementById('slideTitle').textContent = 'Add Slide';
  document.getElementById('slideForm').reset();
  document.getElementById('slide_id').value = '';
  document.getElementById('slide_is_active').checked = true;
  document.getElementById('slide_preview').innerHTML = '<span class="ph">🖼 No image uploaded</span>';
  document.getElementById('slideOverlay').classList.add('show');
}
function openEditSlide(s){
  document.getElementById('slideTitle').textContent = 'Edit Slide';
  document.getElementById('slide_id').value = s.id;
  document.getElementById('slide_eyebrow').value = s.eyebrow || '';
  document.getElementById('slide_heading').value = s.heading || '';
  document.getElementById('slide_description').value = s.description || '';
  document.getElementById('slide_button_text').value = s.button_text || '';
  document.getElementById('slide_button_link').value = s.button_link || '';
  document.getElementById('slide_button2_text').value = s.button2_text || '';
  document.getElementById('slide_button2_link').value = s.button2_link || '';
  document.getElementById('slide_is_active').checked = !!parseInt(s.is_active, 10);
  document.getElementById('slide_preview').innerHTML = s.image
    ? `<img src="<?= BASE_URL ?>${s.image}" alt="">`
    : '<span class="ph">🖼 No image uploaded</span>';
  document.getElementById('slideOverlay').classList.add('show');
}
function closeSlide(){ document.getElementById('slideOverlay').classList.remove('show'); }

function showSlidePreviewFile(file){
  const reader = new FileReader();
  reader.onload = e => { document.getElementById('slide_preview').innerHTML = `<img src="${e.target.result}" alt="">`; };
  reader.readAsDataURL(file);
}
function assignSlideFile(file){
  if (!file || !file.type || !file.type.startsWith('image/')) return;
  const input = document.getElementById('slide_image_input');
  const dt = new DataTransfer();
  dt.items.add(file);
  input.files = dt.files;
  showSlidePreviewFile(file);
}
function previewSlideImageFromInput(input){
  const file = input.files[0];
  if (file) showSlidePreviewFile(file);
}
function handleSlideImageDrop(e){
  e.preventDefault(); e.stopPropagation();
  document.getElementById('slide_preview').classList.remove('drag-over');
  const files = e.dataTransfer.files;
  if (files.length) assignSlideFile(files[0]);
}
document.addEventListener('paste', e => {
  const overlay = document.getElementById('slideOverlay');
  if (!overlay || !overlay.classList.contains('show')) return;
  const items = e.clipboardData ? e.clipboardData.items : [];
  for (let i = 0; i < items.length; i++) {
    if (items[i].type && items[i].type.startsWith('image/')) {
      assignSlideFile(items[i].getAsFile());
    }
  }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
