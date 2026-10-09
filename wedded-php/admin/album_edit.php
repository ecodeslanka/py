<?php
require_once __DIR__ . '/includes/auth.php';
require_admin_login();
$pdo = db();

$categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
if (!$categories) {
    flash_set('Create a category first before adding albums.', 'error');
    redirect('categories.php');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$album = null;
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM albums WHERE id=:id");
    $stmt->execute([':id' => $id]);
    $album = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$album) { flash_set('Album not found.', 'error'); redirect('albums.php'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title = trim($_POST['title'] ?? '');
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $featured = isset($_POST['featured']) ? 1 : 0;
    $showCollection = isset($_POST['show_in_collection']) ? 1 : 0;

    if ($title === '' || !$categoryId) {
        flash_set('Title and category are required.', 'error');
        redirect($id ? "album_edit.php?id={$id}" : 'album_edit.php');
    }

    try {
        $coverPath = handle_image_upload('cover_image', 'albums');
    } catch (Exception $e) {
        flash_set($e->getMessage(), 'error');
        redirect($id ? "album_edit.php?id={$id}" : 'album_edit.php');
    }

    if ($album) {
        if (!$coverPath) {
            $coverPath = $album['cover_image'];
        } else {
            delete_upload($album['cover_image']);
        }
        $slug = unique_slug('albums', $title, $id);
        $pdo->prepare("UPDATE albums SET category_id=:c,title=:t,slug=:s,description=:d,location=:l,cover_image=:img,featured=:f,show_in_collection=:sc WHERE id=:id")
            ->execute([':c' => $categoryId, ':t' => $title, ':s' => $slug, ':d' => $description, ':l' => $location, ':img' => $coverPath, ':f' => $featured, ':sc' => $showCollection, ':id' => $id]);
        flash_set('Album updated.');
        redirect("album_edit.php?id={$id}");
    } else {
        $slug = unique_slug('albums', $title);
        $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),-1) FROM albums")->fetchColumn();
        $pdo->prepare("INSERT INTO albums (category_id,title,slug,description,location,cover_image,featured,show_in_collection,sort_order) VALUES (:c,:t,:s,:d,:l,:img,:f,:sc,:o)")
            ->execute([':c' => $categoryId, ':t' => $title, ':s' => $slug, ':d' => $description, ':l' => $location, ':img' => $coverPath ?: '', ':f' => $featured, ':sc' => $showCollection, ':o' => $maxOrder + 1]);
        $newId = $pdo->lastInsertId();
        flash_set('Album created. Now add some images to it.');
        redirect("album_images.php?album={$newId}");
    }
}

$presetCategory = isset($_GET['category']) ? (int)$_GET['category'] : ($album['category_id'] ?? ($categories[0]['id'] ?? 0));

$admin_page_title = $album ? 'Edit Album' : 'New Album';
include __DIR__ . '/includes/admin_header.php';
?>

<div class="panel">
  <h2><?= $album ? 'Edit Album: ' . h($album['title']) : 'New Album' ?></h2>
  <p class="panel-sub">An album groups together the photos from one wedding, category, or shoot.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div><label>Album title</label><input type="text" name="title" required value="<?= h($album['title'] ?? '') ?>"></div>
      <div>
        <label>Category</label>
        <select name="category_id" required>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)$presetCategory === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Location (optional)</label><input type="text" name="location" placeholder="e.g. Colombo, Sri Lanka" value="<?= h($album['location'] ?? '') ?>"></div>
      <div>
        <label>Cover image <?= $album ? '(leave empty to keep current)' : '' ?></label>
        <input type="file" name="cover_image" accept="image/*" data-preview="#coverPreview">
        <?php if ($album && $album['cover_image']): ?>
          <img id="coverPreview" class="thumb" style="width:120px;height:80px;margin-top:10px" src="<?= h(image_url($album['cover_image'])) ?>">
        <?php else: ?>
          <img id="coverPreview" class="thumb" style="width:120px;height:80px;margin-top:10px;display:none">
        <?php endif; ?>
      </div>
      <div class="full"><label>Description</label><textarea name="description"><?= h($album['description'] ?? '') ?></textarea></div>
      <div class="checkbox-row"><input type="checkbox" id="featured" name="featured" <?= (!empty($album['featured'])) ? 'checked' : '' ?>><label for="featured" style="margin:0">Show in "Featured Stories" on the homepage</label></div>
      <div class="checkbox-row"><input type="checkbox" id="show_in_collection" name="show_in_collection" <?= ($album === null || !empty($album['show_in_collection'])) ? 'checked' : '' ?>><label for="show_in_collection" style="margin:0">Show in the public Collection page</label></div>
    </div>
    <div class="btn-row">
      <button class="btn" type="submit"><?= $album ? 'Save Changes' : 'Create Album' ?></button>
      <?php if ($album): ?><a class="btn secondary" href="album_images.php?album=<?= (int)$album['id'] ?>">Manage Images &rarr;</a><?php endif; ?>
      <a class="btn secondary" href="albums.php">Back to Albums</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
