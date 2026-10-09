<?php
require_once __DIR__ . '/includes/auth.php';
require_admin_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'reorder') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $body['csrf_token'] ?? '')) {
        http_response_code(400); echo json_encode(['ok' => false]); exit;
    }
    $stmt = $pdo->prepare("UPDATE categories SET sort_order = :o WHERE id = :id");
    foreach (($body['ids'] ?? []) as $i => $id) $stmt->execute([':o' => $i, ':id' => (int)$id]);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($action === 'create') {
        if ($name === '') { flash_set('Category name is required.', 'error'); redirect('categories.php'); }
        $slug = unique_slug('categories', $name);
        $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),-1) FROM categories")->fetchColumn();
        $pdo->prepare("INSERT INTO categories (name,slug,description,sort_order) VALUES (:n,:s,:d,:o)")
            ->execute([':n' => $name, ':s' => $slug, ':d' => $description, ':o' => $maxOrder + 1]);
        flash_set('Category added.');
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($name === '') { flash_set('Category name is required.', 'error'); redirect('categories.php'); }
        $current = $pdo->prepare("SELECT slug FROM categories WHERE id=:id"); $current->execute([':id' => $id]);
        $slug = unique_slug('categories', $name, $id);
        $pdo->prepare("UPDATE categories SET name=:n, slug=:s, description=:d WHERE id=:id")
            ->execute([':n' => $name, ':s' => $slug, ':d' => $description, ':id' => $id]);
        flash_set('Category updated.');
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $count = (int)$pdo->prepare("SELECT COUNT(*) FROM albums WHERE category_id=:id");
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM albums WHERE category_id=:id");
        $stmt->execute([':id' => $id]);
        if ((int)$stmt->fetchColumn() > 0) {
            flash_set('Cannot delete: this category still has albums in it. Move or delete those albums first.', 'error');
        } else {
            $pdo->prepare("DELETE FROM categories WHERE id=:id")->execute([':id' => $id]);
            flash_set('Category deleted.');
        }
    }
    redirect('categories.php');
}

$categories = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM albums a WHERE a.category_id=c.id) AS album_count
                            FROM categories c ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$editCat = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id=:id");
    $stmt->execute([':id' => (int)$_GET['edit']]);
    $editCat = $stmt->fetch(PDO::FETCH_ASSOC);
}

$admin_page_title = 'Categories';
include __DIR__ . '/includes/admin_header.php';
?>
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">

<div class="panel">
  <h2><?= $editCat ? 'Edit Category' : 'Add Category' ?></h2>
  <p class="panel-sub">Categories power "What We Capture" on the homepage and the filters on your Collection page (e.g. Weddings, Engagements).</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editCat ? 'update' : 'create' ?>">
    <?php if ($editCat): ?><input type="hidden" name="id" value="<?= (int)$editCat['id'] ?>"><?php endif; ?>
    <div class="form-grid">
      <div><label>Category name</label><input type="text" name="name" required value="<?= h($editCat['name'] ?? '') ?>"></div>
      <div class="full"><label>Description</label><textarea name="description"><?= h($editCat['description'] ?? '') ?></textarea></div>
    </div>
    <div class="btn-row">
      <button class="btn" type="submit"><?= $editCat ? 'Save Changes' : 'Add Category' ?></button>
      <?php if ($editCat): ?><a class="btn secondary" href="categories.php">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="panel">
  <h2>All Categories</h2>
  <p class="panel-sub">Drag to reorder. Click a category's album count to manage its albums.</p>
  <?php if ($categories): ?>
    <ul class="sortable-list" data-reorder-url="categories.php?action=reorder">
      <?php foreach ($categories as $c): ?>
        <li class="sortable-item" data-id="<?= (int)$c['id'] ?>">
          <span class="drag-handle">&#9776;</span>
          <div class="item-body">
            <div class="item-title"><?= h($c['name']) ?></div>
            <div class="item-meta"><?= h($c['description']) ?></div>
          </div>
          <a class="btn small secondary" href="albums.php?category=<?= (int)$c['id'] ?>"><?= (int)$c['album_count'] ?> album<?= $c['album_count'] == 1 ? '' : 's' ?></a>
          <div class="row-actions">
            <a class="btn small secondary" href="?edit=<?= (int)$c['id'] ?>">Edit</a>
            <form method="post" style="display:inline" data-confirm="Delete this category?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="btn small danger" type="submit">Delete</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="help-text">No categories yet.</p>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
