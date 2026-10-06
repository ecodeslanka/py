<?php
/**
 * admin/about_us.php — About Us
 * Manages the public /about-us.php page: heading, subheading, a banner image,
 * the main rich-text content (same contenteditable editor used in Admin -> Items),
 * and an optional image gallery shown at the bottom of the page.
 * Self-contained: creates/migrates its own tables on first load.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'About Us';
$active    = 'about_us';

ensure_about_us_table();

/** Handles the About Us banner image upload — same validation/storage pattern as admin/site_configuration.php. */
function process_about_banner_upload(string $field, ?string $existingPath): array
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return [$existingPath, null];
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return [$existingPath, 'Banner image upload failed — please try again.'];
    }
    if ($_FILES[$field]['size'] > 4 * 1024 * 1024) {
        return [$existingPath, 'Banner image is too large (max 4MB).'];
    }
    $allowedExt = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $ext = strtolower(pathinfo((string)$_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!isset($allowedExt[$ext])) {
        return [$existingPath, 'Banner image must be a JPG, PNG or WEBP.'];
    }
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($_FILES[$field]['tmp_name']);
        if ($mime && !in_array($mime, $allowedExt, true)) {
            return [$existingPath, 'That file does not look like a valid image.'];
        }
    }
    $dir = __DIR__ . '/../assets/uploads/about';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "php_flag engine off\n<FilesMatch \"\\.php$\">\nRequire all denied\n</FilesMatch>\n"); }

    $filename = 'about_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $filename)) {
        return [$existingPath, 'Could not save the banner image.'];
    }
    if ($existingPath) {
        $old = __DIR__ . '/../' . ltrim($existingPath, '/');
        if (is_file($old)) { @unlink($old); }
    }
    return ['/assets/uploads/about/' . $filename, null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    /* ---------------- Save heading / subheading / banner / rich content ---------------- */
    if ($action === 'save_about') {
        $heading    = trim((string)($_POST['heading'] ?? ''));
        $subheading = trim((string)($_POST['subheading'] ?? ''));
        $content    = (string)($_POST['content'] ?? '');

        $err = '';
        if ($heading === '' || mb_strlen($heading) > 200) {
            $err = 'Please enter a heading (max 200 characters).';
        }

        $current = get_about_us();
        $bannerPath = $current['banner_image'];
        if ($err === '') {
            [$bannerPath, $upErr] = process_about_banner_upload('banner_image', $current['banner_image']);
            if ($upErr) { $err = $upErr; }
        }

        if ($err === '') {
            db()->prepare('UPDATE about_us SET heading = ?, subheading = ?, banner_image = ?, content = ? WHERE id = 1')
                ->execute([$heading, $subheading ?: null, $bannerPath, $content]);
            flash('ok', 'About Us page updated.');
        } else {
            flash('err', $err);
        }
        redirect('/admin/about_us');
    }

    /* ---------------- Gallery images ---------------- */
    if ($action === 'add_image') {
        if (empty($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
            flash('err', 'Please choose an image to upload.');
            redirect('/admin/about_us');
        }
        [$path, $upErr] = process_about_banner_upload('image', null);
        if ($upErr) {
            flash('err', $upErr);
        } else {
            $caption = trim((string)($_POST['caption'] ?? ''));
            $nextOrder = (int)db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM about_us_images')->fetchColumn();
            db()->prepare('INSERT INTO about_us_images (image, caption, sort_order) VALUES (?, ?, ?)')
                ->execute([$path, $caption ?: null, $nextOrder]);
            flash('ok', 'Image added to the About Us gallery.');
        }
        redirect('/admin/about_us');
    }

    if ($action === 'delete_image') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT image FROM about_us_images WHERE id = ?');
        $st->execute([$id]);
        if ($img = $st->fetchColumn()) {
            $f = __DIR__ . '/../' . ltrim((string)$img, '/');
            if (is_file($f)) { @unlink($f); }
        }
        db()->prepare('DELETE FROM about_us_images WHERE id = ?')->execute([$id]);
        flash('ok', 'Image removed.');
        redirect('/admin/about_us');
    }
}

$about       = get_about_us();
$aboutImages = get_about_us_images();

require __DIR__ . '/includes/header.php';
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success">✔ <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error">⚠ <?= e($m) ?></div><?php endif; ?>

<!-- ===== PAGE CONTENT ===== -->
<form method="post" enctype="multipart/form-data" autocomplete="off" id="aboutForm">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_about">

  <div class="panel">
    <div class="panel-head"><h3>Page Heading &amp; Banner</h3></div>
    <div class="panel-body">
      <div class="form-grid">
        <label style="grid-column:1/-1">Heading
          <input type="text" name="heading" required maxlength="200" value="<?= e($about['heading'] ?? '') ?>" placeholder="e.g. About Us">
        </label>
        <label style="grid-column:1/-1">Subheading (optional)
          <input type="text" name="subheading" maxlength="300" value="<?= e($about['subheading'] ?? '') ?>" placeholder="e.g. Designed For Your Comfort">
        </label>
        <label style="grid-column:1/-1">Banner Image (optional — shown at the top of the About Us page)
          <input type="file" name="banner_image" accept=".jpg,.jpeg,.png,.webp">
        </label>
      </div>
      <?php if (!empty($about['banner_image'])): ?>
      <div style="margin-top:14px">
        <img src="<?= BASE_URL . e($about['banner_image']) ?>" alt="" style="max-width:320px;border-radius:10px;border:1px solid var(--line)">
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Page Content</h3></div>
    <div class="panel-body">
      <label>Main Content
        <div class="rte-toolbar">
          <button type="button" title="Bold" onclick="aboutRteCmd('bold')"><b>B</b></button>
          <button type="button" title="Italic" onclick="aboutRteCmd('italic')"><i>I</i></button>
          <button type="button" title="Underline" onclick="aboutRteCmd('underline')"><u>U</u></button>
          <label class="rte-color" title="Font colour">A<input type="color" onchange="aboutRteCmd('foreColor', this.value)"></label>
          <label class="rte-color hl" title="Highlight">H<input type="color" onchange="aboutRteCmd('hiliteColor', this.value)"></label>
          <button type="button" title="Bullet list" onclick="aboutRteCmd('insertUnorderedList')">•≡</button>
          <button type="button" title="Numbered list" onclick="aboutRteCmd('insertOrderedList')">1.≡</button>
          <button type="button" title="Heading" onclick="aboutRteFormatBlock('H3')">H3</button>
          <button type="button" title="Paragraph" onclick="aboutRteFormatBlock('P')">¶</button>
          <button type="button" title="Clear formatting" onclick="aboutRteCmd('removeFormat')">Clear</button>
        </div>
        <div id="aboutEditor" class="rte-editor" contenteditable="true" style="min-height:260px"><?= $about['content'] ?? '' ?></div>
        <textarea name="content" id="aboutHidden" style="display:none"></textarea>
      </label>
    </div>
  </div>

  <div style="display:flex;justify-content:flex-end;margin-bottom:26px">
    <button type="submit" class="btn-add">Save About Us Page</button>
  </div>
</form>

<!-- ===== GALLERY IMAGES ===== -->
<div class="panel">
  <div class="panel-head">
    <h3>Gallery Images (<?= count($aboutImages) ?>)</h3>
  </div>
  <div class="panel-body">
    <form method="post" enctype="multipart/form-data" class="form-grid" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_image">
      <label>Image
        <input type="file" name="image" required accept=".jpg,.jpeg,.png,.webp">
      </label>
      <label>Caption (optional)
        <input type="text" name="caption" maxlength="150" placeholder="e.g. Our showroom in Colombo">
      </label>
      <button type="submit" class="btn-add">+ Add Image</button>
    </form>

    <?php if ($aboutImages): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px;margin-top:22px">
      <?php foreach ($aboutImages as $img): ?>
      <div style="border:1px solid var(--line);border-radius:10px;overflow:hidden">
        <img src="<?= BASE_URL . e($img['image']) ?>" alt="" style="width:100%;height:110px;object-fit:cover;display:block">
        <div style="padding:8px 10px">
          <?php if ($img['caption']): ?><div style="font-size:12px;color:var(--muted);margin-bottom:8px"><?= e($img['caption']) ?></div><?php endif; ?>
          <form method="post" onsubmit="return confirm('Remove this image?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_image">
            <input type="hidden" name="id" value="<?= (int)$img['id'] ?>">
            <button class="mini-btn del" type="submit" style="width:100%">Delete</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p style="color:var(--muted);margin-top:16px">No gallery images yet — add one above. These appear at the bottom of the About Us page.</p>
    <?php endif; ?>
  </div>
</div>

<script>
function aboutRteCmd(cmd, val){
  document.getElementById('aboutEditor').focus();
  document.execCommand(cmd, false, val || null);
}
function aboutRteFormatBlock(tag){
  document.getElementById('aboutEditor').focus();
  document.execCommand('formatBlock', false, tag);
}
document.getElementById('aboutForm').addEventListener('submit', function(){
  document.getElementById('aboutHidden').value = document.getElementById('aboutEditor').innerHTML;
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
