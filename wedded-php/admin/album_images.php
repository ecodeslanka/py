<?php
require_once __DIR__ . '/includes/auth.php';
require_admin_login();
$pdo = db();

$albumId = (int)($_GET['album'] ?? 0);
$album = get_album($albumId);
if (!$album) { flash_set('Album not found.', 'error'); redirect('albums.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'reorder') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $body['csrf_token'] ?? '')) {
        http_response_code(400); echo json_encode(['ok' => false]); exit;
    }
    $stmt = $pdo->prepare("UPDATE album_images SET sort_order = :o WHERE id = :id AND album_id = :a");
    foreach (($body['ids'] ?? []) as $i => $imgId) $stmt->execute([':o' => $i, ':id' => (int)$imgId, ':a' => $albumId]);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        $files = $_FILES['images'] ?? null;
        if ($files && is_array($files['name'])) {
            $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),-1) FROM album_images WHERE album_id={$albumId}")->fetchColumn();
            $count = count($files['name']);
            $added = 0;
            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                $single = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
                $_FILES['__single'] = $single;
                try {
                    $path = handle_image_upload('__single', 'albums');
                    if ($path) {
                        $maxOrder++;
                        $pdo->prepare("INSERT INTO album_images (album_id,image,caption,sort_order) VALUES (:a,:i,'',:o)")
                            ->execute([':a' => $albumId, ':i' => $path, ':o' => $maxOrder]);
                        $added++;
                        // Auto-set album cover if it doesn't have one yet
                        if (!$album['cover_image']) {
                            $pdo->prepare("UPDATE albums SET cover_image=:i WHERE id=:a")->execute([':i' => $path, ':a' => $albumId]);
                            $album['cover_image'] = $path;
                        }
                    }
                } catch (Exception $e) {
                    flash_set('Some images could not be uploaded: ' . $e->getMessage(), 'error');
                }
            }
            if ($added) flash_set("{$added} image(s) added.");
        }
    } elseif ($action === 'update_caption') {
        $imgId = (int)($_POST['id'] ?? 0);
        $caption = trim($_POST['caption'] ?? '');
        $pdo->prepare("UPDATE album_images SET caption=:c WHERE id=:id AND album_id=:a")
            ->execute([':c' => $caption, ':id' => $imgId, ':a' => $albumId]);
        flash_set('Caption updated.');
    } elseif ($action === 'delete') {
        $imgId = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM album_images WHERE id=:id AND album_id=:a");
        $stmt->execute([':id' => $imgId, ':a' => $albumId]);
        $img = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($img) {
            delete_upload($img['image']);
            $pdo->prepare("DELETE FROM album_images WHERE id=:id")->execute([':id' => $imgId]);
            if ($album['cover_image'] === $img['image']) {
                $pdo->prepare("UPDATE albums SET cover_image='' WHERE id=:a")->execute([':a' => $albumId]);
            }
            flash_set('Image deleted.');
        }
    } elseif ($action === 'set_cover') {
        $imgId = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM album_images WHERE id=:id AND album_id=:a");
        $stmt->execute([':id' => $imgId, ':a' => $albumId]);
        $img = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($img) {
            $pdo->prepare("UPDATE albums SET cover_image=:i WHERE id=:a")->execute([':i' => $img['image'], ':a' => $albumId]);
            flash_set('Cover image updated.');
        }
    }
    redirect("album_images.php?album={$albumId}");
}

$images = get_album_images($albumId);

$admin_page_title = 'Images: ' . $album['title'];
include __DIR__ . '/includes/admin_header.php';
?>
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">

<div class="panel">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <div>
      <h2>Images in "<?= h($album['title']) ?>"</h2>
      <p class="panel-sub">Upload one or several photos at once. The first photo you add becomes the album cover automatically (change it any time below).</p>
    </div>
    <a class="btn secondary" href="album_edit.php?id=<?= (int)$album['id'] ?>">&larr; Edit Album Details</a>
  </div>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <div class="form-grid">
      <div class="full">
        <label>Add images</label>
        <input type="file" name="images[]" accept="image/*" multiple required>
        <p class="help-text">JPG, PNG or WEBP, up to 12MB each. You can select multiple files.</p>
      </div>
    </div>
    <div class="btn-row"><button class="btn" type="submit">Upload</button></div>
  </form>
</div>

<div class="panel">
  <h2>Album Photos (<?= count($images) ?>)</h2>
  <p class="panel-sub">Drag to reorder how images appear on the album page.</p>
  <?php if ($images): ?>
    <ul class="sortable-list" data-reorder-url="album_images.php?album=<?= (int)$albumId ?>&action=reorder">
      <?php foreach ($images as $img): ?>
        <li class="sortable-item" data-id="<?= (int)$img['id'] ?>">
          <span class="drag-handle">&#9776;</span>
          <img src="<?= h(image_url($img['image'])) ?>" alt="">
          <div class="item-body">
            <form method="post" style="display:flex;gap:8px;align-items:center">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update_caption">
              <input type="hidden" name="id" value="<?= (int)$img['id'] ?>">
              <input type="text" name="caption" value="<?= h($img['caption']) ?>" placeholder="Caption (optional)" style="max-width:260px">
              <button class="btn small secondary" type="submit">Save</button>
            </form>
          </div>
          <?php if ($album['cover_image'] === $img['image']): ?>
            <span class="badge on">Cover</span>
          <?php else: ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="set_cover"><input type="hidden" name="id" value="<?= (int)$img['id'] ?>">
              <button class="btn small secondary" type="submit">Make Cover</button>
            </form>
          <?php endif; ?>
          <form method="post" style="display:inline" data-confirm="Delete this image?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$img['id'] ?>">
            <button class="btn small danger" type="submit">Delete</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="help-text">No images yet — upload some above.</p>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
