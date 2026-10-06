<?php
/**
 * admin/happy_customers.php — Happy Customers
 * Manages the "Happy Customers" strip on the homepage (index.php):
 * a row of customer photos shown 4-per-line; clicking a photo pops
 * up that customer's comment. Each entry = photo + customer name +
 * comment, shown/hidden and reordered from here — same pattern as
 * admin/home_slides.php.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Happy Customers';
$active    = 'happy_customers';

ensure_happy_customers_table();

/** Handles the customer photo upload — same validation/storage pattern as admin/home_slides.php. */
function process_happy_customer_image_upload(string $field, ?string $existingPath): array
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return [$existingPath, null];
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return [$existingPath, 'Photo upload failed — please try again.'];
    }
    if ($_FILES[$field]['size'] > 4 * 1024 * 1024) {
        return [$existingPath, 'Photo is too large (max 4MB).'];
    }
    $allowedExt = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $ext = strtolower(pathinfo((string)$_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!isset($allowedExt[$ext])) {
        return [$existingPath, 'Photo must be a JPG, PNG or WEBP.'];
    }
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($_FILES[$field]['tmp_name']);
        if ($mime && !in_array($mime, $allowedExt, true)) {
            return [$existingPath, 'That file does not look like a valid image.'];
        }
    }
    $dir = __DIR__ . '/../assets/uploads/happy_customers';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "php_flag engine off\n<FilesMatch \"\\.php$\">\nRequire all denied\n</FilesMatch>\n"); }

    $filename = 'happycust_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $filename)) {
        return [$existingPath, 'Could not save the photo.'];
    }
    if ($existingPath) {
        $old = __DIR__ . '/../' . ltrim($existingPath, '/');
        if (is_file($old)) { @unlink($old); }
    }
    return ['/assets/uploads/happy_customers/' . $filename, null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_customer') {
        $id            = (int)($_POST['id'] ?? 0);
        $customerName  = trim((string)($_POST['customer_name'] ?? ''));
        $comment       = trim((string)($_POST['comment'] ?? ''));
        $isActive      = isset($_POST['is_active']) ? 1 : 0;

        $err = '';
        if ($customerName === '' || mb_strlen($customerName) > 150) {
            $err = 'Please enter the customer\'s name (max 150 characters).';
        }
        if ($err === '' && ($comment === '' || mb_strlen($comment) > 1000)) {
            $err = 'Please enter their comment (max 1000 characters).';
        }

        $existingImage = null;
        if ($id) {
            $st = db()->prepare('SELECT image FROM happy_customers WHERE id = ?');
            $st->execute([$id]);
            $existingImage = $st->fetchColumn() ?: null;
        }

        $imagePath = $existingImage;
        if ($err === '') {
            [$imagePath, $upErr] = process_happy_customer_image_upload('image', $existingImage);
            if ($upErr) { $err = $upErr; }
        }
        if ($err === '' && !$id && !$imagePath) {
            $err = 'Please upload a photo of the customer.';
        }

        if ($err === '') {
            if ($id) {
                db()->prepare('UPDATE happy_customers SET customer_name=?, comment=?, image=?, is_active=? WHERE id=?')
                    ->execute([$customerName, $comment, $imagePath, $isActive, $id]);
                flash('ok', 'Happy customer updated.');
            } else {
                $nextOrder = (int)db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM happy_customers')->fetchColumn();
                db()->prepare('INSERT INTO happy_customers (customer_name, comment, image, is_active, sort_order) VALUES (?,?,?,?,?)')
                    ->execute([$customerName, $comment, $imagePath, $isActive, $nextOrder]);
                flash('ok', 'Happy customer added.');
            }
        } else {
            flash('err', $err);
        }
        redirect('/admin/happy_customers');
    }

    if ($action === 'delete_customer') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT image FROM happy_customers WHERE id = ?');
        $st->execute([$id]);
        if ($img = $st->fetchColumn()) {
            $f = __DIR__ . '/../' . ltrim($img, '/');
            if (is_file($f)) { @unlink($f); }
        }
        db()->prepare('DELETE FROM happy_customers WHERE id = ?')->execute([$id]);
        flash('ok', 'Happy customer deleted.');
        redirect('/admin/happy_customers');
    }

    if ($action === 'move_customer') {
        $id  = (int)($_POST['id'] ?? 0);
        $dir = $_POST['dir'] ?? '';
        $rows = db()->query('SELECT id, sort_order FROM happy_customers ORDER BY sort_order ASC, id ASC')->fetchAll();
        $ids = array_column($rows, 'id');
        $pos = array_search($id, $ids, true);
        if ($pos !== false) {
            $swapWith = $dir === 'up' ? $pos - 1 : $pos + 1;
            if (isset($ids[$swapWith])) {
                $a = $rows[$pos]; $b = $rows[$swapWith];
                db()->prepare('UPDATE happy_customers SET sort_order = ? WHERE id = ?')->execute([$b['sort_order'], $a['id']]);
                db()->prepare('UPDATE happy_customers SET sort_order = ? WHERE id = ?')->execute([$a['sort_order'], $b['id']]);
            }
        }
        redirect('/admin/happy_customers');
    }
}

$customers = get_happy_customers(false);

require __DIR__ . '/includes/header.php';
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success">✔ <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error">⚠ <?= e($m) ?></div><?php endif; ?>

<div class="panel">
  <div class="panel-head">
    <h3>Happy Customers (<?= count($customers) ?>)</h3>
    <button type="button" class="btn-add" onclick="openAddHC()">+ Add Customer</button>
  </div>
  <div class="panel-body" style="padding:0">
    <p class="hint" style="padding:14px 16px 0">Shown on the homepage as a row of photos, 4 per line. Clicking a photo pops up that customer's comment. Turn the whole section on/off from Site Configuration → Homepage Sections.</p>
    <table>
      <thead><tr><th></th><th>Customer</th><th>Comment</th><th>Status</th><th>Order</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($customers as $i => $c): ?>
        <tr>
          <td>
            <?php if ($c['image']): ?>
              <img class="items-thumb" src="<?= BASE_URL . e($c['image']) ?>" alt="">
            <?php else: ?>
              <span class="items-thumb" style="display:grid;place-items:center;font-size:16px">🖼️</span>
            <?php endif; ?>
          </td>
          <td><b><?= e($c['customer_name']) ?></b></td>
          <td><span class="hint"><?= e(mb_strimwidth($c['comment'], 0, 70, '…')) ?></span></td>
          <td><?= $c['is_active'] ? '<span class="verified-badge">Active</span>' : '<span class="hint">Hidden</span>' ?></td>
          <td>
            <div class="row-actions">
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="move_customer">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <input type="hidden" name="dir" value="up">
                <button class="mini-btn toggle" type="submit" <?= $i === 0 ? 'disabled' : '' ?>><i class="fa-solid fa-arrow-up"></i></button>
              </form>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="move_customer">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <input type="hidden" name="dir" value="down">
                <button class="mini-btn toggle" type="submit" <?= $i === count($customers) - 1 ? 'disabled' : '' ?>><i class="fa-solid fa-arrow-down"></i></button>
              </form>
            </div>
          </td>
          <td>
            <div class="row-actions">
              <button type="button" class="mini-btn edit" onclick='openEditHC(<?= htmlspecialchars(json_encode($c), ENT_QUOTES, "UTF-8") ?>)'>Edit</button>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this happy customer entry?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_customer">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="mini-btn del" type="submit">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$customers): ?>
        <tr><td colspan="6" style="color:var(--muted)">No happy customers yet — click "+ Add Customer" to feature the first one.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== MODAL: Add/Edit Happy Customer ===== -->
<div id="hcOverlay" class="modal-overlay" onclick="if(event.target===this) closeHC()">
  <div class="modal-box" style="max-width:560px">
    <h3 id="hcTitle">Add Customer</h3>
    <form method="post" enctype="multipart/form-data" class="form-grid" id="hcForm" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="hc_action" value="save_customer">
      <input type="hidden" name="id" id="hc_id">

      <label style="grid-column:1/-1">Customer Photo <span class="hint" style="font-weight:400">(square photo works best)</span>
        <div class="logo-preview" id="hc_preview" style="height:140px;cursor:pointer"
             onclick="document.getElementById('hc_image_input').click()"
             ondragover="event.preventDefault();event.stopPropagation();this.classList.add('drag-over')"
             ondragleave="this.classList.remove('drag-over')"
             ondrop="handleHCImageDrop(event)">
          <span class="ph">🖼 No photo uploaded</span>
        </div>
        <input type="file" name="image" id="hc_image_input" accept=".jpg,.jpeg,.png,.webp" style="display:none" onchange="previewHCImageFromInput(this)">
        <p class="hint">Drag &amp; drop, paste with Ctrl+V, or click above to upload — JPG, PNG or WEBP, max 4MB.</p>
      </label>

      <label style="grid-column:1/-1">Customer Name
        <input type="text" name="customer_name" id="hc_customer_name" required maxlength="150" placeholder="e.g. Nimal Perera">
      </label>
      <label style="grid-column:1/-1">Comment
        <textarea name="comment" id="hc_comment" rows="4" required maxlength="1000" placeholder="What the customer said about us"></textarea>
      </label>

      <div class="choice-group" style="grid-column:1/-1">
        <label class="choice-pill">
          <input type="checkbox" name="is_active" id="hc_is_active" checked>
          <span>Show on the homepage</span>
        </label>
      </div>

      <div style="display:flex;gap:10px;grid-column:1/-1">
        <button type="submit" class="btn-add">Save</button>
        <button type="button" class="mini-btn toggle" onclick="closeHC()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAddHC(){
  document.getElementById('hcTitle').textContent = 'Add Customer';
  document.getElementById('hcForm').reset();
  document.getElementById('hc_id').value = '';
  document.getElementById('hc_is_active').checked = true;
  document.getElementById('hc_preview').innerHTML = '<span class="ph">🖼 No photo uploaded</span>';
  document.getElementById('hcOverlay').classList.add('show');
}
function openEditHC(c){
  document.getElementById('hcTitle').textContent = 'Edit Customer';
  document.getElementById('hc_id').value = c.id;
  document.getElementById('hc_customer_name').value = c.customer_name || '';
  document.getElementById('hc_comment').value = c.comment || '';
  document.getElementById('hc_is_active').checked = !!parseInt(c.is_active, 10);
  document.getElementById('hc_preview').innerHTML = c.image
    ? `<img src="<?= BASE_URL ?>${c.image}" alt="">`
    : '<span class="ph">🖼 No photo uploaded</span>';
  document.getElementById('hcOverlay').classList.add('show');
}
function closeHC(){ document.getElementById('hcOverlay').classList.remove('show'); }

function showHCPreviewFile(file){
  const reader = new FileReader();
  reader.onload = e => { document.getElementById('hc_preview').innerHTML = `<img src="${e.target.result}" alt="">`; };
  reader.readAsDataURL(file);
}
function assignHCFile(file){
  if (!file || !file.type || !file.type.startsWith('image/')) return;
  const input = document.getElementById('hc_image_input');
  const dt = new DataTransfer();
  dt.items.add(file);
  input.files = dt.files;
  showHCPreviewFile(file);
}
function previewHCImageFromInput(input){
  const file = input.files[0];
  if (file) showHCPreviewFile(file);
}
function handleHCImageDrop(e){
  e.preventDefault(); e.stopPropagation();
  document.getElementById('hc_preview').classList.remove('drag-over');
  const files = e.dataTransfer.files;
  if (files.length) assignHCFile(files[0]);
}
document.addEventListener('paste', e => {
  const overlay = document.getElementById('hcOverlay');
  if (!overlay || !overlay.classList.contains('show')) return;
  const items = e.clipboardData ? e.clipboardData.items : [];
  for (let i = 0; i < items.length; i++) {
    if (items[i].type && items[i].type.startsWith('image/')) {
      assignHCFile(items[i].getAsFile());
    }
  }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
