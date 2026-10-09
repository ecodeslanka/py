<?php
require_once __DIR__ . '/includes/auth.php';
require_admin_login();
$pdo = db();

// Reorder via AJAX (JSON body)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'reorder') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $body['csrf_token'] ?? '')) {
        http_response_code(400); echo json_encode(['ok' => false]); exit;
    }
    $stmt = $pdo->prepare("UPDATE hero_slides SET sort_order = :o WHERE id = :id");
    foreach (($body['ids'] ?? []) as $i => $id) {
        $stmt->execute([':o' => $i, ':id' => (int)$id]);
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $active = isset($_POST['active']) ? 1 : 0;

        try {
            $imagePath = handle_image_upload('image', 'hero');
        } catch (Exception $e) {
            flash_set($e->getMessage(), 'error');
            redirect('hero.php');
        }

        if ($action === 'create') {
            if (!$imagePath) {
                flash_set('Please choose a hero image.', 'error');
                redirect('hero.php');
            }
            $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),-1) FROM hero_slides")->fetchColumn();
            $pdo->prepare("INSERT INTO hero_slides (image,title,subtitle,sort_order,active) VALUES (:i,:t,:s,:o,:a)")
                ->execute([':i' => $imagePath, ':t' => $title, ':s' => $subtitle, ':o' => $maxOrder + 1, ':a' => $active]);
            flash_set('Hero slide added.');
        } else {
            $existing = $pdo->prepare("SELECT * FROM hero_slides WHERE id=:id");
            $existing->execute([':id' => $id]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if ($imagePath) {
                    delete_upload($row['image']);
                } else {
                    $imagePath = $row['image'];
                }
                $pdo->prepare("UPDATE hero_slides SET image=:i,title=:t,subtitle=:s,active=:a WHERE id=:id")
                    ->execute([':i' => $imagePath, ':t' => $title, ':s' => $subtitle, ':a' => $active, ':id' => $id]);
                flash_set('Hero slide updated.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM hero_slides WHERE id=:id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            delete_upload($row['image']);
            $pdo->prepare("DELETE FROM hero_slides WHERE id=:id")->execute([':id' => $id]);
            flash_set('Hero slide deleted.');
        }
    }
    redirect('hero.php');
}

$slides = $pdo->query("SELECT * FROM hero_slides ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$editSlide = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM hero_slides WHERE id=:id");
    $stmt->execute([':id' => (int)$_GET['edit']]);
    $editSlide = $stmt->fetch(PDO::FETCH_ASSOC);
}

$admin_page_title = 'Hero Slider';
include __DIR__ . '/includes/admin_header.php';
?>
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">

<div class="panel">
  <h2><?= $editSlide ? 'Edit Slide' : 'Add Hero Slide' ?></h2>
  <p class="panel-sub">This image (and headline) shows first on your homepage. Add more slides to create a rotating slider.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editSlide ? 'update' : 'create' ?>">
    <?php if ($editSlide): ?><input type="hidden" name="id" value="<?= (int)$editSlide['id'] ?>"><?php endif; ?>
    <div class="form-grid">
      <div class="full">
        <label>Slide Image <?= $editSlide ? '(leave empty to keep current)' : '' ?></label>
        <input type="file" name="image" accept="image/*" data-preview="#heroPreview" <?= $editSlide ? '' : 'required' ?>>
        <?php if ($editSlide && $editSlide['image']): ?>
          <img id="heroPreview" class="thumb" style="width:120px;height:80px;margin-top:10px" src="<?= h(image_url($editSlide['image'])) ?>">
        <?php else: ?>
          <img id="heroPreview" class="thumb" style="width:120px;height:80px;margin-top:10px;display:none">
        <?php endif; ?>
        <p class="help-text">Recommended: 2400×1600px landscape photo, JPG or WEBP.</p>
      </div>
      <div class="full">
        <label>Headline (HTML line breaks allowed via &lt;br&gt;)</label>
        <input type="text" name="title" value="<?= h($editSlide['title'] ?? 'Your story,<br>beautifully remembered.') ?>">
      </div>
      <div class="full">
        <label>Subtitle</label>
        <input type="text" name="subtitle" value="<?= h($editSlide['subtitle'] ?? '') ?>">
      </div>
      <div class="full checkbox-row">
        <input type="checkbox" id="active" name="active" <?= (!$editSlide || $editSlide['active']) ? 'checked' : '' ?>>
        <label for="active" style="margin:0">Active (visible on homepage)</label>
      </div>
    </div>
    <div class="btn-row">
      <button class="btn" type="submit"><?= $editSlide ? 'Save Changes' : 'Add Slide' ?></button>
      <?php if ($editSlide): ?><a class="btn secondary" href="hero.php">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="panel">
  <h2>Current Slides</h2>
  <p class="panel-sub">Drag to reorder — the top slide shows first.</p>
  <?php if ($slides): ?>
    <ul class="sortable-list" data-reorder-url="hero.php?action=reorder">
      <?php foreach ($slides as $s): ?>
        <li class="sortable-item" data-id="<?= (int)$s['id'] ?>">
          <span class="drag-handle">&#9776;</span>
          <img src="<?= h(image_url($s['image'], '')) ?>" alt="">
          <div class="item-body">
            <div class="item-title"><?= h(strip_tags($s['title']) ?: 'Untitled slide') ?> <?= $s['active'] ? '<span class="badge on">Active</span>' : '<span class="badge off">Hidden</span>' ?></div>
            <div class="item-meta"><?= h($s['subtitle']) ?></div>
          </div>
          <div class="row-actions">
            <a class="btn small secondary" href="?edit=<?= (int)$s['id'] ?>">Edit</a>
            <form method="post" style="display:inline" data-confirm="Delete this hero slide?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
              <button class="btn small danger" type="submit">Delete</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="help-text">No hero slides yet.</p>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
