<?php
require_once __DIR__ . '/includes/auth.php';
require_admin_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'reorder') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $body['csrf_token'] ?? '')) {
        http_response_code(400); echo json_encode(['ok' => false]); exit;
    }
    $stmt = $pdo->prepare("UPDATE albums SET sort_order = :o WHERE id = :id");
    foreach (($body['ids'] ?? []) as $i => $id) $stmt->execute([':o' => $i, ':id' => (int)$id]);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM albums WHERE id=:id");
        $stmt->execute([':id' => $id]);
        $album = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($album) {
            delete_upload($album['cover_image']);
            $imgs = $pdo->prepare("SELECT image FROM album_images WHERE album_id=:id");
            $imgs->execute([':id' => $id]);
            foreach ($imgs->fetchAll(PDO::FETCH_COLUMN) as $img) delete_upload($img);
            $pdo->prepare("DELETE FROM albums WHERE id=:id")->execute([':id' => $id]);
            flash_set('Album and its images deleted.');
        }
    } elseif ($action === 'toggle_featured') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE albums SET featured = 1 - featured WHERE id=:id")->execute([':id' => $id]);
    } elseif ($action === 'toggle_collection') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE albums SET show_in_collection = 1 - show_in_collection WHERE id=:id")->execute([':id' => $id]);
    }
    redirect('albums.php' . (isset($_GET['category']) ? '?category=' . (int)$_GET['category'] : ''));
}

$categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
$catFilter = isset($_GET['category']) ? (int)$_GET['category'] : 0;

$sql = "SELECT a.*, c.name AS category_name FROM albums a JOIN categories c ON c.id=a.category_id";
$params = [];
if ($catFilter) { $sql .= " WHERE a.category_id = :c"; $params[':c'] = $catFilter; }
$sql .= " ORDER BY a.sort_order ASC, a.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$albums = $stmt->fetchAll(PDO::FETCH_ASSOC);

$admin_page_title = 'Albums';
include __DIR__ . '/includes/admin_header.php';
?>
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">

<div class="panel">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h2>Albums</h2>
      <p class="panel-sub">Albums live inside a category. Click an album to manage the images inside it.</p>
    </div>
    <a class="btn" href="album_edit.php<?= $catFilter ? '?category=' . $catFilter : '' ?>">+ New Album</a>
  </div>

  <div class="filter" style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 20px">
    <a class="btn small <?= !$catFilter ? '' : 'secondary' ?>" href="albums.php">All</a>
    <?php foreach ($categories as $c): ?>
      <a class="btn small <?= $catFilter === (int)$c['id'] ? '' : 'secondary' ?>" href="albums.php?category=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($albums): ?>
    <ul class="sortable-list" data-reorder-url="albums.php?action=reorder<?= $catFilter ? '&category=' . $catFilter : '' ?>">
      <?php foreach ($albums as $a): ?>
        <li class="sortable-item" data-id="<?= (int)$a['id'] ?>">
          <span class="drag-handle">&#9776;</span>
          <?php if ($a['cover_image']): ?>
            <img src="<?= h(image_url($a['cover_image'])) ?>" alt="">
          <?php else: ?>
            <img src="" style="background:#E6D9C8">
          <?php endif; ?>
          <div class="item-body">
            <div class="item-title"><?= h($a['title']) ?></div>
            <div class="item-meta"><?= h($a['category_name']) ?><?= $a['location'] ? ' · ' . h($a['location']) : '' ?> · <?= count_album_images($a['id']) ?> photos</div>
          </div>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="toggle_featured"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <button class="badge <?= $a['featured'] ? 'on' : 'off' ?>" type="submit" style="border:0;cursor:pointer">Featured: <?= $a['featured'] ? 'Yes' : 'No' ?></button>
          </form>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="toggle_collection"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <button class="badge <?= $a['show_in_collection'] ? 'on' : 'off' ?>" type="submit" style="border:0;cursor:pointer">Collection: <?= $a['show_in_collection'] ? 'Shown' : 'Hidden' ?></button>
          </form>
          <div class="row-actions">
            <a class="btn small secondary" href="album_images.php?album=<?= (int)$a['id'] ?>">Images</a>
            <a class="btn small secondary" href="album_edit.php?id=<?= (int)$a['id'] ?>">Edit</a>
            <form method="post" style="display:inline" data-confirm="Delete this album and all its images?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
              <button class="btn small danger" type="submit">Delete</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="help-text">No albums yet — create one to get started.</p>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
