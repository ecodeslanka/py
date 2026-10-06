<?php
/**
 * admin/items.php — Items (list + create/edit, single page)
 * Fields: SKU, name, description, condition (new/old), warranty,
 * stock status (in stock / out of stock / pre-order) + qty,
 * cost / selling / special price, multiple images with one feature image,
 * and optional variations (each with its own image + pricing, falling
 * back to the base item's image/pricing when left blank).
 *
 * Self-contained: creates/migrates its own tables on first load.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Items';
$active    = 'items';

/* ============================================================
   AUTO-MIGRATE: create tables on first load if they don't exist
   ============================================================ */
db()->exec("
CREATE TABLE IF NOT EXISTS items (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku            VARCHAR(60)  NOT NULL,
  name           VARCHAR(200) NOT NULL,
  description    TEXT,
  condition_type ENUM('new','old') NOT NULL DEFAULT 'new',
  warranty       VARCHAR(150) DEFAULT NULL,
  stock_status   ENUM('in_stock','out_of_stock','pre_order') NOT NULL DEFAULT 'in_stock',
  stock_qty      INT NOT NULL DEFAULT 0,
  cost_price     DECIMAL(12,2) NOT NULL DEFAULT 0,
  selling_price  DECIMAL(12,2) NOT NULL DEFAULT 0,
  special_price  DECIMAL(12,2) DEFAULT NULL,
  has_variations TINYINT(1) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

db()->exec("
CREATE TABLE IF NOT EXISTS item_images (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id     INT UNSIGNED NOT NULL,
  image_path  VARCHAR(255) NOT NULL,
  is_feature  TINYINT(1) NOT NULL DEFAULT 0,
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_item_images_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

db()->exec("
CREATE TABLE IF NOT EXISTS item_variations (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id        INT UNSIGNED NOT NULL,
  variation_name VARCHAR(150) NOT NULL,
  image_path     VARCHAR(255) DEFAULT NULL,
  cost_price     DECIMAL(12,2) DEFAULT NULL,
  selling_price  DECIMAL(12,2) DEFAULT NULL,
  special_price  DECIMAL(12,2) DEFAULT NULL,
  stock_qty      INT NOT NULL DEFAULT 0,
  sort_order     INT NOT NULL DEFAULT 0,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_item_variations_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
ensure_variation_attr_columns(); // size_label / color_name / color_hex for clothing

/* brands table — also created here so Items works even if admin/brands.php hasn't been visited yet */
db()->exec("
CREATE TABLE IF NOT EXISTS brands (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  image      VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_brand_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ---- lightweight column-existence helper for upgrading older installs ---- */
function items_has_column(string $table, string $column): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}

if (!items_has_column('items', 'category_id')) {
    db()->exec('ALTER TABLE items ADD COLUMN category_id INT UNSIGNED DEFAULT NULL AFTER name');
}
if (!items_has_column('items', 'brand_id')) {
    db()->exec('ALTER TABLE items ADD COLUMN brand_id INT UNSIGNED DEFAULT NULL AFTER category_id');
}
if (!items_has_column('items', 'is_flash_sale')) {
    db()->exec('ALTER TABLE items ADD COLUMN is_flash_sale TINYINT(1) NOT NULL DEFAULT 0 AFTER has_variations');
}
if (!items_has_column('items', 'is_top_item')) {
    db()->exec('ALTER TABLE items ADD COLUMN is_top_item TINYINT(1) NOT NULL DEFAULT 0 AFTER is_flash_sale');
}
if (!items_has_column('items', 'koko_price')) {
    db()->exec('ALTER TABLE items ADD COLUMN koko_price DECIMAL(12,2) DEFAULT NULL AFTER special_price');
}

/* widen the item name column so longer product names fit (only runs once) */
$nameLenStmt = db()->prepare("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items' AND COLUMN_NAME = 'name'");
$nameLenStmt->execute();
if ((int)$nameLenStmt->fetchColumn() < 300) {
    db()->exec('ALTER TABLE items MODIFY COLUMN name VARCHAR(300) NOT NULL');
}

/* ============================================================
   Helpers
   ============================================================ */
function process_item_file_upload(array $file): array
{
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Image upload failed — please try again.'];
    }
    if ($file['size'] > 3 * 1024 * 1024) {
        return [null, 'Image is too large (max 3MB).'];
    }
    $allowedExt = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!isset($allowedExt[$ext])) {
        return [null, 'Images must be JPG, PNG, WEBP or GIF.'];
    }
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($file['tmp_name']);
        if ($mime && !in_array($mime, $allowedExt, true)) {
            return [null, 'That file does not look like a valid image.'];
        }
    }
    $dir = __DIR__ . '/../assets/uploads/items';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "php_flag engine off\n<FilesMatch \"\\.php$\">\nRequire all denied\n</FilesMatch>\n"); }

    $filename = 'item_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
        return [null, 'Could not save the uploaded image.'];
    }
    return ['/assets/uploads/items/' . $filename, null];
}

function delete_item_file(?string $path): void
{
    if (!$path) return;
    $f = __DIR__ . '/../' . ltrim($path, '/');
    if (is_file($f)) { @unlink($f); }
}

/* ============================================================
   POST actions
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_item') {
        $id            = (int)($_POST['id'] ?? 0);
        $sku           = trim((string)($_POST['sku'] ?? ''));
        $name          = trim((string)($_POST['name'] ?? ''));
        $categoryId    = (int)($_POST['category_id'] ?? 0) ?: null;
        $brandId       = (int)($_POST['brand_id'] ?? 0) ?: null;
        $description   = trim((string)($_POST['description'] ?? ''));
        $conditionType = ($_POST['condition_type'] ?? 'new') === 'old' ? 'old' : 'new';
        $warranty      = trim((string)($_POST['warranty'] ?? ''));
        $stockStatus   = in_array($_POST['stock_status'] ?? '', ['in_stock', 'out_of_stock', 'pre_order'], true) ? $_POST['stock_status'] : 'in_stock';
        $stockQty      = max(0, (int)($_POST['stock_qty'] ?? 0));
        $costPrice     = (float)($_POST['cost_price'] ?? 0);
        $sellingPrice  = (float)($_POST['selling_price'] ?? 0);
        $specialRaw    = trim((string)($_POST['special_price'] ?? ''));
        $specialPrice  = $specialRaw !== '' ? (float)$specialRaw : null;
        $kokoRaw       = trim((string)($_POST['koko_price'] ?? ''));
        $kokoPrice     = $kokoRaw !== '' ? (float)$kokoRaw : null;
        $hasVariations = isset($_POST['has_variations']) ? 1 : 0;
        $isFlashSale   = isset($_POST['is_flash_sale']) ? 1 : 0;
        $isTopItem     = isset($_POST['is_top_item']) ? 1 : 0;

        $err = '';
        if ($sku === '' || mb_strlen($sku) > 60) {
            $err = 'SKU code is required (max 60 characters).';
        } elseif ($name === '' || mb_strlen($name) > 300) {
            $err = 'Item name is required (max 300 characters).';
        } elseif ($costPrice < 0 || $sellingPrice < 0) {
            $err = 'Prices cannot be negative.';
        } elseif ($specialPrice !== null && $specialPrice > $sellingPrice) {
            $err = 'Special price cannot be higher than the selling price.';
        } elseif ($kokoPrice !== null && $kokoPrice < 0) {
            $err = 'KOKO price cannot be negative.';
        }

        if ($err === '') {
            $dup = db()->prepare('SELECT id FROM items WHERE sku = ? AND id != ?');
            $dup->execute([$sku, $id]);
            if ($dup->fetch()) { $err = 'That SKU code is already used by another item.'; }
        }

        if ($err !== '') {
            flash('err', $err);
            redirect($id ? '/admin/items?view=form&id=' . $id : '/admin/items?view=form');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE items SET sku=?,name=?,category_id=?,brand_id=?,description=?,condition_type=?,warranty=?,stock_status=?,stock_qty=?,cost_price=?,selling_price=?,special_price=?,koko_price=?,has_variations=?,is_flash_sale=?,is_top_item=? WHERE id=?')
                    ->execute([$sku, $name, $categoryId, $brandId, $description ?: null, $conditionType, $warranty ?: null, $stockStatus, $stockQty, $costPrice, $sellingPrice, $specialPrice, $kokoPrice, $hasVariations, $isFlashSale, $isTopItem, $id]);
                $itemId = $id;
            } else {
                $pdo->prepare('INSERT INTO items (sku,name,category_id,brand_id,description,condition_type,warranty,stock_status,stock_qty,cost_price,selling_price,special_price,koko_price,has_variations,is_flash_sale,is_top_item) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$sku, $name, $categoryId, $brandId, $description ?: null, $conditionType, $warranty ?: null, $stockStatus, $stockQty, $costPrice, $sellingPrice, $specialPrice, $kokoPrice, $hasVariations, $isFlashSale, $isTopItem]);
                $itemId = (int)$pdo->lastInsertId();
            }

            /* ---- images: remove flagged existing ones ---- */
            $featureChoice = (string)($_POST['feature_choice'] ?? '');
            $removeIds = array_map('intval', $_POST['remove_image'] ?? []);
            if ($removeIds) {
                $inQ  = implode(',', array_fill(0, count($removeIds), '?'));
                $stmt = $pdo->prepare("SELECT image_path FROM item_images WHERE item_id = ? AND id IN ($inQ)");
                $stmt->execute(array_merge([$itemId], $removeIds));
                foreach ($stmt->fetchAll() as $row) { delete_item_file($row['image_path']); }
                $pdo->prepare("DELETE FROM item_images WHERE item_id = ? AND id IN ($inQ)")->execute(array_merge([$itemId], $removeIds));
            }

            /* ---- feature flag: reset then apply choice ---- */
            $pdo->prepare('UPDATE item_images SET is_feature = 0 WHERE item_id = ?')->execute([$itemId]);
            if (strpos($featureChoice, 'existing:') === 0) {
                $pdo->prepare('UPDATE item_images SET is_feature = 1 WHERE item_id = ? AND id = ?')
                    ->execute([$itemId, (int)substr($featureChoice, 9)]);
            }

            /* ---- new images (associative by uid so no reindexing is needed) ---- */
            if (!empty($_FILES['new_images']['name']) && is_array($_FILES['new_images']['name'])) {
                $maxSortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM item_images WHERE item_id = ?');
                $maxSortStmt->execute([$itemId]);
                $maxSort = (int)$maxSortStmt->fetchColumn();

                foreach ($_FILES['new_images']['name'] as $uid => $fname) {
                    if ($_FILES['new_images']['error'][$uid] === UPLOAD_ERR_NO_FILE) { continue; }
                    [$path, $upErr] = process_item_file_upload([
                        'name' => $_FILES['new_images']['name'][$uid],
                        'type' => $_FILES['new_images']['type'][$uid],
                        'tmp_name' => $_FILES['new_images']['tmp_name'][$uid],
                        'error' => $_FILES['new_images']['error'][$uid],
                        'size' => $_FILES['new_images']['size'][$uid],
                    ]);
                    if ($upErr || !$path) { continue; }
                    $isFeat = ($featureChoice === 'new:' . $uid) ? 1 : 0;
                    if ($isFeat) { $pdo->prepare('UPDATE item_images SET is_feature = 0 WHERE item_id = ?')->execute([$itemId]); }
                    $pdo->prepare('INSERT INTO item_images (item_id, image_path, is_feature, sort_order) VALUES (?, ?, ?, ?)')
                        ->execute([$itemId, $path, $isFeat, ++$maxSort]);
                }
            }

            /* ---- guarantee a feature image exists if any images are present ---- */
            $featStmt = $pdo->prepare('SELECT COUNT(*) FROM item_images WHERE item_id = ? AND is_feature = 1');
            $featStmt->execute([$itemId]);
            if ((int)$featStmt->fetchColumn() === 0) {
                $firstStmt = $pdo->prepare('SELECT id FROM item_images WHERE item_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1');
                $firstStmt->execute([$itemId]);
                if ($firstId = $firstStmt->fetchColumn()) {
                    $pdo->prepare('UPDATE item_images SET is_feature = 1 WHERE id = ?')->execute([$firstId]);
                }
            }

            /* ---- variations (keyed by uid: real id for existing rows, "nN" for new rows) ---- */
            if (!empty($_POST['variations']) && is_array($_POST['variations'])) {
                foreach ($_POST['variations'] as $uid => $v) {
                    $vId    = (int)($v['id'] ?? 0);
                    $vSize  = mb_substr(trim((string)($v['size'] ?? '')), 0, 40);
                    $vColor = mb_substr(trim((string)($v['color'] ?? '')), 0, 60);
                    $vHex   = $vColor !== '' ? clean_color_hex($v['color_hex'] ?? '') : '';
                    $vName  = trim((string)($v['name'] ?? ''));
                    if ($vSize !== '' || $vColor !== '') { $vName = build_variation_name($vSize, $vColor); }
                    $vSort  = (int)($v['sort'] ?? 0);
                    $delete = isset($v['delete']);

                    if ($delete && $vId) {
                        $imgStmt = $pdo->prepare('SELECT image_path FROM item_variations WHERE id = ? AND item_id = ?');
                        $imgStmt->execute([$vId, $itemId]);
                        delete_item_file((string)$imgStmt->fetchColumn());
                        $pdo->prepare('DELETE FROM item_variations WHERE id = ? AND item_id = ?')->execute([$vId, $itemId]);
                        continue;
                    }
                    if ($vName === '') { continue; }

                    $vCost  = trim((string)($v['cost_price'] ?? ''))    !== '' ? (float)$v['cost_price']    : null;
                    $vSell  = trim((string)($v['selling_price'] ?? '')) !== '' ? (float)$v['selling_price'] : null;
                    $vSpec  = trim((string)($v['special_price'] ?? '')) !== '' ? (float)$v['special_price'] : null;
                    $vStock = max(0, (int)($v['stock_qty'] ?? 0));

                    $existingImg = null;
                    if ($vId) {
                        $exStmt = $pdo->prepare('SELECT image_path FROM item_variations WHERE id = ? AND item_id = ?');
                        $exStmt->execute([$vId, $itemId]);
                        $existingImg = $exStmt->fetchColumn() ?: null;
                    }
                    if (!empty($v['remove_image']) && $existingImg) {
                        delete_item_file($existingImg);
                        $existingImg = null;
                    }

                    $vImagePath = $existingImg;
                    if (isset($_FILES['variations']['name'][$uid]['image']) && $_FILES['variations']['error'][$uid]['image'] !== UPLOAD_ERR_NO_FILE) {
                        [$newPath, $vErr] = process_item_file_upload([
                            'name' => $_FILES['variations']['name'][$uid]['image'],
                            'type' => $_FILES['variations']['type'][$uid]['image'],
                            'tmp_name' => $_FILES['variations']['tmp_name'][$uid]['image'],
                            'error' => $_FILES['variations']['error'][$uid]['image'],
                            'size' => $_FILES['variations']['size'][$uid]['image'],
                        ]);
                        if (!$vErr && $newPath) {
                            delete_item_file($existingImg);
                            $vImagePath = $newPath;
                        }
                    }

                    if ($vId) {
                        $pdo->prepare('UPDATE item_variations SET variation_name=?,size_label=?,color_name=?,color_hex=?,image_path=?,cost_price=?,selling_price=?,special_price=?,stock_qty=?,sort_order=? WHERE id=? AND item_id=?')
                            ->execute([$vName, $vSize ?: null, $vColor ?: null, $vHex ?: null, $vImagePath, $vCost, $vSell, $vSpec, $vStock, $vSort, $vId, $itemId]);
                    } else {
                        $pdo->prepare('INSERT INTO item_variations (item_id,variation_name,size_label,color_name,color_hex,image_path,cost_price,selling_price,special_price,stock_qty,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                            ->execute([$itemId, $vName, $vSize ?: null, $vColor ?: null, $vHex ?: null, $vImagePath, $vCost, $vSell, $vSpec, $vStock, $vSort]);
                    }
                }
            }

            $pdo->commit();
            flash('ok', $id ? 'Item updated successfully.' : 'Item created successfully.');
        } catch (Throwable $ex) {
            $pdo->rollBack();
            flash('err', 'Something went wrong while saving the item. Please try again.');
            redirect($id ? '/admin/items?view=form&id=' . $id : '/admin/items?view=form');
        }
        redirect('/admin/items');
    }

    /* where to go back to after a delete (keeps the current search / brand filter) */
    $backTo = '/admin/items';
    $backQs = trim((string)($_POST['back_qs'] ?? ''));
    if ($backQs !== '' && preg_match('/^[A-Za-z0-9_=&%+.\-]*$/', $backQs)) { $backTo .= '?' . $backQs; }

    /* ----- delete ONE item ----- */
    if ($action === 'delete_item') {
        $id = (int)($_POST['id'] ?? 0);
        $n  = delete_items_by_ids([$id]);
        flash($n ? 'ok' : 'err', $n ? 'Item deleted.' : 'That item could not be found.');
        redirect($backTo);
    }

    /* ----- delete SELECTED items (checkboxes) ----- */
    if ($action === 'bulk_delete_items') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        if (!$ids) {
            flash('err', 'Please tick at least one item to delete.');
        } else {
            $n = delete_items_by_ids($ids);
            flash('ok', $n . ' item' . ($n === 1 ? '' : 's') . ' deleted.');
        }
        redirect($backTo);
    }

    /* ----- delete ALL items (one-time wipe, must type DELETE) ----- */
    if ($action === 'delete_all_items') {
        if (strtoupper(trim((string)($_POST['confirm_text'] ?? ''))) !== 'DELETE') {
            flash('err', 'Delete all was cancelled — you must type DELETE to confirm.');
            redirect('/admin/items');
        }
        $allIds = db()->query('SELECT id FROM items')->fetchAll(PDO::FETCH_COLUMN);
        $n = delete_items_by_ids($allIds);
        flash('ok', 'All items deleted (' . $n . ' removed).');
        redirect('/admin/items');
    }
}

/* ============================================================
   View routing: list (default) or create/edit form
   ============================================================ */
$editId   = (int)($_GET['id'] ?? 0);
$showForm = ($_GET['view'] ?? '') === 'form';
$editItem = null;
$editImages = [];
$editVariations = [];

if ($editId) {
    $stmt = db()->prepare('SELECT * FROM items WHERE id = ?');
    $stmt->execute([$editId]);
    $editItem = $stmt->fetch();
    if (!$editItem) {
        flash('err', 'That item could not be found.');
        redirect('/admin/items');
    }
    $imgStmt = db()->prepare('SELECT * FROM item_images WHERE item_id = ? ORDER BY sort_order ASC, id ASC');
    $imgStmt->execute([$editId]);
    $editImages = $imgStmt->fetchAll();

    $varStmt = db()->prepare('SELECT * FROM item_variations WHERE item_id = ? ORDER BY sort_order ASC, id ASC');
    $varStmt->execute([$editId]);
    $editVariations = $varStmt->fetchAll();

    $showForm = true;
}

$searchQ = trim((string)($_GET['q'] ?? ''));
$brandFilter = (int)($_GET['brand'] ?? 0); // from Admin → Brands "Items" count link
$items = [];
if (!$showForm) {
    $baseSql = "SELECT i.*,
                (SELECT image_path FROM item_images WHERE item_id = i.id AND is_feature = 1 LIMIT 1) AS feature_image,
                (SELECT COUNT(*) FROM item_variations WHERE item_id = i.id) AS variation_count,
                (SELECT COALESCE(SUM(stock_qty),0) FROM item_variations WHERE item_id = i.id) AS variation_stock,
                (SELECT COUNT(*) FROM item_variations WHERE item_id = i.id AND stock_qty <= 0) AS variation_soldout,
                c.name AS category_name, b.name AS brand_name
            FROM items i
            LEFT JOIN categories c ON c.id = i.category_id
            LEFT JOIN brands b ON b.id = i.brand_id";
    $where = []; $params = [];
    if ($searchQ !== '') {
        $where[] = '(i.sku LIKE ? OR i.name LIKE ?)';
        $like = '%' . $searchQ . '%';
        array_push($params, $like, $like);
    }
    if ($brandFilter > 0) { $where[] = 'i.brand_id = ?'; $params[] = $brandFilter; }
    $stmt = db()->prepare($baseSql . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY i.created_at DESC');
    $stmt->execute($params);
    $items = $stmt->fetchAll();
}

$totalItemCount = (int)db()->query('SELECT COUNT(*) FROM items')->fetchColumn();
$backQs = http_build_query(array_filter(['q' => $searchQ, 'brand' => $brandFilter ?: null]));

$categoryFlat = flatten_categories_for_select(get_category_tree(false));
$brandList    = db()->query('SELECT id, name FROM brands ORDER BY name ASC')->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success">✔ <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error">⚠ <?= e($m) ?></div><?php endif; ?>

<?php if (!$showForm): ?>
<!-- ============================================================
     LIST VIEW
     ============================================================ -->
<div class="panel">
  <div class="panel-head">
    <h3>Items (<?= count($items) ?><?= count($items) !== $totalItemCount ? ' of ' . $totalItemCount : '' ?>)</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="<?= BASE_URL ?>/admin/items_import" class="mini-btn edit" style="padding:12px 16px"><i class="fa-solid fa-file-import"></i> Import Items</a>
      <a href="<?= BASE_URL ?>/admin/items?view=form" class="btn-add">+ Add New Item</a>
    </div>
  </div>
  <div class="panel-body" style="padding:14px 20px 0">
    <form method="get" class="search-row">
      <input type="text" name="q" value="<?= e($searchQ) ?>" placeholder="Search by SKU or item name…">
      <select name="brand" style="max-width:190px" onchange="this.form.submit()">
        <option value="0">All brands</option>
        <?php foreach ($brandList as $__bl): ?>
        <option value="<?= (int)$__bl['id'] ?>" <?= $brandFilter === (int)$__bl['id'] ? 'selected' : '' ?>><?= e($__bl['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="mini-btn toggle">Search</button>
      <?php if ($searchQ !== '' || $brandFilter > 0): ?><a href="<?= BASE_URL ?>/admin/items" class="mini-btn del">Clear</a><?php endif; ?>
    </form>
  </div>
  <!-- ===== Bulk actions bar ===== -->
  <form method="post" id="bulkForm" onsubmit="return confirmBulkDelete();">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_delete_items">
    <input type="hidden" name="back_qs" value="<?= e($backQs) ?>">
  </form>
  <form method="post" id="deleteAllForm" onsubmit="return confirmDeleteAll();">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_all_items">
    <input type="hidden" name="confirm_text" id="deleteAllConfirm" value="">
  </form>
  <div class="bulk-bar" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 20px;border-top:1px solid var(--line);border-bottom:1px solid var(--line);background:#fafbfd">
    <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:700;cursor:pointer">
      <input type="checkbox" id="selectAllTop" onchange="toggleAllItems(this.checked)" style="width:16px;height:16px"> Select all
    </label>
    <span id="selCount" style="font-size:12.5px;color:var(--muted)">0 selected</span>
    <button type="submit" form="bulkForm" class="mini-btn del" id="bulkDelBtn" disabled style="opacity:.5"><i class="fa-solid fa-trash"></i> Delete Selected</button>
    <?php if ($totalItemCount > 0): ?>
    <button type="submit" form="deleteAllForm" class="mini-btn del" style="margin-left:auto;background:#c62828;color:#fff"><i class="fa-solid fa-dumpster"></i> Delete ALL Items (<?= $totalItemCount ?>)</button>
    <?php endif; ?>
  </div>
  <div class="panel-body" style="padding:0">
    <table>
      <thead><tr><th style="width:34px"><input type="checkbox" id="selectAllHead" onchange="toggleAllItems(this.checked)" style="width:16px;height:16px"></th><th></th><th>SKU</th><th>Name</th><th>Category / Brand</th><th>Condition</th><th>Stock</th><th>Price</th><th>Variations</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($items as $it): ?>
        <tr>
          <td><input type="checkbox" class="item-cb" name="ids[]" value="<?= (int)$it['id'] ?>" form="bulkForm" onchange="updateSelCount()" style="width:16px;height:16px"></td>
          <td>
            <?php if ($it['feature_image']): ?>
              <img class="items-thumb" src="<?= BASE_URL . e($it['feature_image']) ?>" alt="">
            <?php else: ?>
              <span class="items-thumb" style="display:grid;place-items:center;font-size:16px">🖼</span>
            <?php endif; ?>
          </td>
          <td><b><?= e($it['sku']) ?></b></td>
          <td>
            <?= e($it['name']) ?>
            <?php if (!empty($it['is_flash_sale'])): ?><span class="tag tag-off" title="Shown in Flash Sale on the homepage">⚡ Flash</span><?php endif; ?>
            <?php if (!empty($it['is_top_item'])): ?><span class="tag tag-on" title="Shown in Top Selling on the homepage">⭐ Top</span><?php endif; ?>
          </td>
          <td style="font-size:12px;color:var(--muted)">
            <?= $it['category_name'] ? e($it['category_name']) : '<span class="crumb">No category</span>' ?><br>
            <?= $it['brand_name'] ? e($it['brand_name']) : '<span class="crumb">No brand</span>' ?>
          </td>
          <td><?= $it['condition_type'] === 'new' ? '<span class="tag tag-on">New</span>' : '<span class="tag tag-lvl">Old</span>' ?></td>
          <td>
            <?php if ($it['stock_status'] === 'in_stock'): ?>
              <?php $__q = $it['has_variations'] ? (int)$it['variation_stock'] : (int)$it['stock_qty']; ?>
              <?php if ($it['has_variations'] && $__q <= 0): ?><span class="tag tag-off">Sold out</span><?php else: ?><span class="tag tag-on">In Stock (<?= $__q ?>)</span><?php endif; ?>
            <?php elseif ($it['stock_status'] === 'pre_order'): ?>
              <span class="tag tag-pre">Pre-Order</span>
            <?php else: ?>
              <span class="tag tag-off">Out of Stock</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($it['special_price'] !== null): ?>
              <span class="price-old">Rs <?= number_format((float)$it['selling_price'], 2) ?></span>
              <span class="price-now">Rs <?= number_format((float)$it['special_price'], 2) ?></span>
            <?php else: ?>
              <span class="price-now">Rs <?= number_format((float)$it['selling_price'], 2) ?></span>
            <?php endif; ?>
            <?php if (!empty($it['koko_price'])): ?>
              <br><span class="tag tag-lvl" style="margin-top:4px;display:inline-block">KOKO Rs <?= number_format((float)$it['koko_price'], 2) ?></span>
            <?php endif; ?>
          </td>
          <td><?php if ($it['has_variations']): ?>
            <span class="tag tag-lvl"><?= (int)$it['variation_count'] ?> size/colour<?= (int)$it['variation_count'] === 1 ? '' : 's' ?></span>
            <div class="crumb" style="margin-top:4px"><?= (int)$it['variation_stock'] ?> pcs<?php if ((int)$it['variation_soldout']): ?> · <span style="color:#8f1116;font-weight:700"><?= (int)$it['variation_soldout'] ?> sold out</span><?php endif; ?></div>
          <?php else: ?><span class="crumb">—</span><?php endif; ?></td>
          <td>
            <div class="row-actions">
              <a class="mini-btn toggle" href="<?= BASE_URL ?>/item.php?id=<?= (int)$it['id'] ?>" target="_blank" rel="noopener" title="View on website"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
              <a class="mini-btn edit" href="<?= BASE_URL ?>/admin/items?view=form&id=<?= (int)$it['id'] ?>">Edit</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this item? This also removes its images and variations.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_item">
                <input type="hidden" name="back_qs" value="<?= e($backQs) ?>">
                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                <button class="mini-btn del" type="submit">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?>
        <tr><td colspan="10" style="color:var(--muted)">No items yet — click "+ Add New Item" to create one, or use "Import Items".</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function itemCbs(){ return Array.from(document.querySelectorAll('.item-cb')); }
function toggleAllItems(on){
  itemCbs().forEach(cb => cb.checked = on);
  document.getElementById('selectAllTop').checked = on;
  document.getElementById('selectAllHead').checked = on;
  updateSelCount();
}
function updateSelCount(){
  const all = itemCbs(), n = all.filter(cb => cb.checked).length;
  document.getElementById('selCount').textContent = n + ' selected';
  const btn = document.getElementById('bulkDelBtn');
  btn.disabled = n === 0; btn.style.opacity = n === 0 ? .5 : 1;
  const allOn = all.length > 0 && n === all.length;
  document.getElementById('selectAllTop').checked = allOn;
  document.getElementById('selectAllHead').checked = allOn;
}
function confirmBulkDelete(){
  const n = itemCbs().filter(cb => cb.checked).length;
  if (!n) { alert('Please tick at least one item.'); return false; }
  return confirm('Delete ' + n + ' selected item' + (n === 1 ? '' : 's') + '?\nTheir images and variations will also be removed. This cannot be undone.');
}
function confirmDeleteAll(){
  const t = prompt('This will permanently delete ALL <?= $totalItemCount ?> items, with their images and variations.\n\nType DELETE to confirm:');
  if (t === null) return false;
  if (t.trim().toUpperCase() !== 'DELETE') { alert('Cancelled — you must type DELETE.'); return false; }
  document.getElementById('deleteAllConfirm').value = 'DELETE';
  return true;
}
</script>

<?php else: ?>
<!-- ============================================================
     CREATE / EDIT FORM
     ============================================================ -->
<div class="item-form-page">
<div class="panel-head" style="padding:0 2px 14px">
  <h3 style="font-size:20px"><?= $editItem ? 'Edit Item' : 'Add New Item' ?></h3>
  <a href="<?= BASE_URL ?>/admin/items" class="mini-btn toggle">← Back to Items</a>
</div>

<form method="post" enctype="multipart/form-data" autocomplete="off" id="itemForm">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_item">
  <input type="hidden" name="id" value="<?= (int)($editItem['id'] ?? 0) ?>">

  <div class="panel">
    <div class="panel-head"><h3>Item Details</h3></div>
    <div class="panel-body">
      <div class="form-grid">
        <label>SKU Code
          <input type="text" name="sku" required maxlength="60" value="<?= e($editItem['sku'] ?? '') ?>" placeholder="e.g. ECL-TEE-01">
        </label>
        <label>Category (optional)
          <select name="category_id" id="categorySelect" class="select2-field">
            <option value="">— No category —</option>
            <?php foreach ($categoryFlat as $row): ?>
            <option value="<?= (int)$row['id'] ?>" <?= (int)($editItem['category_id'] ?? 0) === (int)$row['id'] ? 'selected' : '' ?>>
              <?= str_repeat('— ', (int)$row['depth']) ?><?= e($row['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Brand (optional)
          <select name="brand_id" id="brandSelect" class="select2-field">
            <option value="">— No brand —</option>
            <?php foreach ($brandList as $br): ?>
            <option value="<?= (int)$br['id'] ?>" <?= (int)($editItem['brand_id'] ?? 0) === (int)$br['id'] ? 'selected' : '' ?>><?= e($br['name']) ?></option>
            <?php endforeach; ?>
          </select>
        
        </label>
        <label style="grid-column:1/-1">Item Name
          <input type="text" name="name" required maxlength="300" value="<?= e($editItem['name'] ?? '') ?>" placeholder="e.g. 55&quot; 4K Smart QLED TV with Dolby Atmos Sound System">
        </label>
        <label style="grid-column:1/-1">Description
          <div class="rte-toolbar">
            <button type="button" title="Bold" onclick="rteCmd('bold')"><b>B</b></button>
            <button type="button" title="Italic" onclick="rteCmd('italic')"><i>I</i></button>
            <button type="button" title="Underline" onclick="rteCmd('underline')"><u>U</u></button>
            <label class="rte-color" title="Font colour">A<input type="color" onchange="rteCmd('foreColor', this.value)"></label>
            <label class="rte-color hl" title="Highlight">H<input type="color" onchange="rteCmd('hiliteColor', this.value)"></label>
            <button type="button" title="Bullet list" onclick="rteCmd('insertUnorderedList')">•≡</button>
            <button type="button" title="Clear formatting" onclick="rteCmd('removeFormat')">Clear</button>
          </div>
          <div id="descEditor" class="rte-editor" contenteditable="true"><?= $editItem['description'] ?? '' ?></div>
          <textarea name="description" id="descHidden" style="display:none"></textarea>
        </label>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Condition &amp; Warranty</h3></div>
    <div class="panel-body">
      <div class="choice-group">
        <label class="choice-pill">
          <input type="radio" name="condition_type" value="new" <?= (($editItem['condition_type'] ?? 'new') === 'new') ? 'checked' : '' ?>>
          <span>🆕 Brand New</span>
        </label>
        <label class="choice-pill">
          <input type="radio" name="condition_type" value="old" <?= (($editItem['condition_type'] ?? '') === 'old') ? 'checked' : '' ?>>
          <span>♻️ Old / Used</span>
        </label>
      </div>
      <div class="form-grid" style="margin-top:18px">
        <label style="grid-column:1/-1">Warranty Details (optional)
          <input type="text" name="warranty" maxlength="150" value="<?= e($editItem['warranty'] ?? '') ?>" placeholder="e.g. 1 Year Manufacturer Warranty">
        </label>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Stock</h3></div>
    <div class="panel-body">
      <div class="choice-group">
        <label class="choice-pill">
          <input type="radio" name="stock_status" value="in_stock" <?= (($editItem['stock_status'] ?? 'in_stock') === 'in_stock') ? 'checked' : '' ?>>
          <span>✅ In Stock</span>
        </label>
        <label class="choice-pill">
          <input type="radio" name="stock_status" value="pre_order" <?= (($editItem['stock_status'] ?? '') === 'pre_order') ? 'checked' : '' ?>>
          <span>⏳ Pre-Order</span>
        </label>
        <label class="choice-pill">
          <input type="radio" name="stock_status" value="out_of_stock" <?= (($editItem['stock_status'] ?? '') === 'out_of_stock') ? 'checked' : '' ?>>
          <span>🚫 Out of Stock</span>
        </label>
      </div>
      <div class="form-grid" style="margin-top:18px">
        <label>Stock Quantity
          <input type="number" name="stock_qty" min="0" value="<?= (int)($editItem['stock_qty'] ?? 0) ?>">
        </label>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Pricing</h3></div>
    <div class="panel-body">
      <div class="form-grid">
        <label>Cost Price (Rs)
          <input type="number" step="0.01" min="0" name="cost_price" required value="<?= e($editItem['cost_price'] ?? '') ?>">
        </label>
        <label>Selling Price (Rs)
          <input type="number" step="0.01" min="0" name="selling_price" required value="<?= e($editItem['selling_price'] ?? '') ?>">
        </label>
        <label>Special Price (Rs, optional)
          <input type="number" step="0.01" min="0" name="special_price" value="<?= e($editItem['special_price'] ?? '') ?>" placeholder="Promotional price">
        </label>
        <label>KOKO Price (Rs, optional)
          <input type="number" step="0.01" min="0" name="koko_price" value="<?= e($editItem['koko_price'] ?? '') ?>" placeholder="Leave blank to use the price above">
        </label>
      </div>
      <p class="hint" style="margin-top:10px">
        KOKO Price is an <b>additional/override price used only for KOKO "Buy Now, Pay Later"</b> —
        it's what the 3-monthly-instalment amount shown on the product page is calculated from, and
        (if the customer pays via KOKO at checkout) what they'll actually be charged in total. Leave
        blank to just use the Selling/Special Price above for KOKO too.
      </p>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Homepage Visibility</h3></div>
    <div class="panel-body">
      <p class="hint" style="margin-bottom:14px">Control which homepage sections this item appears in. Flash Sale items need a Special Price set above to show a discount.</p>
      <div class="choice-group">
        <label class="choice-pill">
          <input type="checkbox" name="is_flash_sale" <?= !empty($editItem['is_flash_sale']) ? 'checked' : '' ?>>
          <span>⚡ Show in Flash Sale</span>
        </label>
        <label class="choice-pill">
          <input type="checkbox" name="is_top_item" <?= !empty($editItem['is_top_item']) ? 'checked' : '' ?>>
          <span>⭐ Show in Top Selling</span>
        </label>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Images</h3></div>
    <div class="panel-body">
      <p class="hint" style="margin-bottom:14px">Upload as many images as you need, then pick one as the feature image (shown as the main thumbnail).</p>

      <div id="imgDropzone" class="dropzone">
        <p>📥 Drag &amp; drop images here, paste with Ctrl+V, or
          <button type="button" class="mini-btn edit" onclick="addImageRow()">+ Add Image</button>
        </p>
      </div>

      <?php if ($editImages): ?>
      <div class="existing-images">
        <?php foreach ($editImages as $img): ?>
        <div class="existing-img-card <?= $img['is_feature'] ? 'is-feature' : '' ?>">
          <img src="<?= BASE_URL . e($img['image_path']) ?>" alt="">
          <label><input type="radio" name="feature_choice" value="existing:<?= (int)$img['id'] ?>" <?= $img['is_feature'] ? 'checked' : '' ?>> Feature</label>
          <label><input type="checkbox" name="remove_image[]" value="<?= (int)$img['id'] ?>"> Remove</label>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div id="newImageRows"></div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head">
      <h3>Variations</h3>
      <label class="feature-pick" style="font-size:13px">
        <input type="checkbox" name="has_variations" id="hasVariations" onchange="toggleVariations()" <?= !empty($editItem['has_variations']) ? 'checked' : '' ?>>
        This item has variations
      </label>
    </div>
    <div class="panel-body" id="variationsBody" style="<?= empty($editItem['has_variations']) ? 'display:none' : '' ?>">
      <p class="hint" style="margin-bottom:14px"><b>Clothing:</b> every row is one <b>size + colour</b> with its own stock. Stock 0 = shown as <b>sold out</b> on the site.
        Leave Size or Colour empty if the item doesn't use it (e.g. caps = colour only). Prices / image left blank use the item's base price / images.
        Upload a photo on <b>one</b> row of a colour — the other sizes of that colour reuse it.</p>

      <!-- quick builder: sizes × colours → rows -->
      <div class="vb-builder">
        <div class="vb-col">
          <label class="vb-label">1. Sizes <small>(comma separated)</small></label>
          <input type="text" id="vbSizes" placeholder="e.g. S, M, L, XL">
          <div class="vb-presets">
            <button type="button" class="vb-chip" data-sizes="XS, S, M, L, XL, XXL">XS–XXL</button>
            <button type="button" class="vb-chip" data-sizes="S, M, L, XL">S–XL</button>
            <button type="button" class="vb-chip" data-sizes="28, 30, 32, 34, 36, 38">28–38 (waist)</button>
            <button type="button" class="vb-chip" data-sizes="2-3Y, 4-5Y, 6-7Y, 8-9Y, 10-11Y">Kids</button>
            <button type="button" class="vb-chip" data-sizes="Free Size">Free size</button>
            <button type="button" class="vb-chip" data-sizes="">No sizes</button>
          </div>
        </div>
        <div class="vb-col">
          <label class="vb-label">2. Colours</label>
          <div id="vbColors"></div>
          <button type="button" class="vb-chip" onclick="vbAddColor('', '#111111')">+ Add colour</button>
          <div class="vb-presets">
            <?php foreach ([['Black','#111111'],['White','#ffffff'],['Navy','#1f2a44'],['Grey','#9aa0a6'],['Maroon','#7b1e2b'],['Olive','#6b6b3a'],['Beige','#d9c7a7'],['Sky Blue','#7cc0ea']] as [$__cn, $__ch]): ?>
            <button type="button" class="vb-chip vb-color-chip" onclick="vbAddColor('<?= $__cn ?>','<?= $__ch ?>')"><span style="background:<?= $__ch ?>"></span><?= $__cn ?></button>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="vb-col vb-col-sm">
          <label class="vb-label">3. Stock each</label>
          <input type="number" id="vbStock" min="0" value="10">
          <button type="button" class="btn-add vb-generate" onclick="vbGenerate()">⚡ Create rows</button>
          <small class="vb-note" id="vbNote"></small>
        </div>
      </div>

      <div class="vb-head" aria-hidden="true">
        <span>Size</span><span>Colour</span><span>Stock</span><span>Selling</span><span>Special</span><span>Cost</span><span>Image</span><span></span>
      </div>
      <div id="existingVariationRows">
        <?php foreach ($editVariations as $vi => $v): $vid = (int)$v['id']; $isLegacy = trim((string)($v['size_label'] ?? '')) === '' && trim((string)($v['color_name'] ?? '')) === ''; ?>
        <div class="var-row vb-row" data-uid="<?= $vid ?>">
          <input type="hidden" name="variations[<?= $vid ?>][id]" value="<?= $vid ?>">
          <input type="hidden" name="variations[<?= $vid ?>][sort]" value="<?= $vi ?>" class="vb-sort">
          <?php if ($isLegacy): ?><input type="hidden" name="variations[<?= $vid ?>][name]" value="<?= e($v['variation_name']) ?>"><?php endif; ?>
          <div class="vb-grid">
            <label><span class="vb-m">Size</span><input type="text" name="variations[<?= $vid ?>][size]" value="<?= e($v['size_label'] ?? '') ?>" placeholder="<?= $isLegacy ? e($v['variation_name']) : '—' ?>" class="vb-size"></label>
            <label class="vb-colorcell"><span class="vb-m">Colour</span>
              <span class="vb-colorpair">
                <input type="color" name="variations[<?= $vid ?>][color_hex]" value="<?= e(clean_color_hex($v['color_hex'] ?? '') ?: '#111111') ?>">
                <input type="text" name="variations[<?= $vid ?>][color]" value="<?= e($v['color_name'] ?? '') ?>" placeholder="—" class="vb-color">
              </span>
            </label>
            <label><span class="vb-m">Stock</span><input type="number" min="0" name="variations[<?= $vid ?>][stock_qty]" value="<?= (int)$v['stock_qty'] ?>" class="vb-stock <?= (int)$v['stock_qty'] <= 0 ? 'is-zero' : '' ?>"></label>
            <label><span class="vb-m">Selling</span><input type="number" step="0.01" min="0" name="variations[<?= $vid ?>][selling_price]" value="<?= e($v['selling_price'] ?? '') ?>" placeholder="Base"></label>
            <label><span class="vb-m">Special</span><input type="number" step="0.01" min="0" name="variations[<?= $vid ?>][special_price]" value="<?= e($v['special_price'] ?? '') ?>" placeholder="—"></label>
            <label><span class="vb-m">Cost</span><input type="number" step="0.01" min="0" name="variations[<?= $vid ?>][cost_price]" value="<?= e($v['cost_price'] ?? '') ?>" placeholder="Base"></label>
            <label class="vb-imgcell"><span class="vb-m">Image</span>
              <span class="vb-img">
                <span class="img-row-preview small" id="varprev_<?= $vid ?>"><?php if ($v['image_path']): ?><img src="<?= BASE_URL . e($v['image_path']) ?>"><?php else: ?><span class="ph">🖼</span><?php endif; ?></span>
                <input type="file" name="variations[<?= $vid ?>][image]" accept=".jpg,.jpeg,.png,.webp,.gif" onchange="previewImage(this,'varprev_<?= $vid ?>')">
              </span>
              <?php if ($v['image_path']): ?><label class="feature-pick"><input type="checkbox" name="variations[<?= $vid ?>][remove_image]" value="1"> Remove image</label><?php endif; ?>
            </label>
            <label class="feature-pick del-pick vb-del" title="Delete this row">
              <input type="checkbox" name="variations[<?= $vid ?>][delete]" value="1" onchange="this.closest('.var-row').classList.toggle('marked-del',this.checked)"> 🗑
            </label>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div id="newVariationRows"></div>
      <div class="vb-foot">
        <button type="button" class="mini-btn edit" onclick="addVariationRow()">+ Add one row</button>
        <span class="vb-total" id="vbTotal"></span>
      </div>
    </div>
  </div>

  <div style="display:flex;gap:10px;margin-bottom:32px">
    <button type="submit" class="btn-add">💾 <?= $editItem ? 'Update Item' : 'Save Item' ?></button>
    <a href="<?= BASE_URL ?>/admin/items" class="mini-btn toggle">Cancel</a>
  </div>
</form>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>
<script>
let imgUidCounter = 0;
function addImageRow(file){
  const uid = 'n' + (++imgUidCounter);
  const wrap = document.createElement('div');
  wrap.className = 'img-row';
  wrap.innerHTML = `
    <div class="img-row-preview" id="prev_${uid}"><span class="ph">🖼</span></div>
    <div class="img-row-fields">
      <input type="file" name="new_images[${uid}]" accept=".jpg,.jpeg,.png,.webp,.gif" onchange="previewImage(this,'prev_${uid}')">
      <label class="feature-pick"><input type="radio" name="feature_choice" value="new:${uid}"> Set as feature image</label>
    </div>
    <button type="button" class="mini-btn del" onclick="this.closest('.img-row').remove()">Remove</button>`;
  document.getElementById('newImageRows').appendChild(wrap);

  if (file) {
    const input = wrap.querySelector('input[type=file]');
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    previewImage(input, 'prev_' + uid);
  }
}

/* ---- Drag & drop images straight onto the dropzone ---- */
const imgDropzone = document.getElementById('imgDropzone');
if (imgDropzone) {
  ['dragenter', 'dragover'].forEach(evt => imgDropzone.addEventListener(evt, e => {
    e.preventDefault(); e.stopPropagation(); imgDropzone.classList.add('drag-over');
  }));
  ['dragleave', 'drop'].forEach(evt => imgDropzone.addEventListener(evt, e => {
    e.preventDefault(); e.stopPropagation(); imgDropzone.classList.remove('drag-over');
  }));
  imgDropzone.addEventListener('drop', e => {
    const files = e.dataTransfer.files;
    for (let i = 0; i < files.length; i++) {
      if (files[i].type.startsWith('image/')) addImageRow(files[i]);
    }
  });
}
/* ---- Paste an image (Ctrl+V) anywhere on the page while editing ---- */
document.addEventListener('paste', e => {
  if (!document.getElementById('newImageRows')) return;
  const items = e.clipboardData ? e.clipboardData.items : [];
  for (let i = 0; i < items.length; i++) {
    if (items[i].type && items[i].type.startsWith('image/')) {
      addImageRow(items[i].getAsFile());
    }
  }
});

let varUidCounter = 0;
function vbEsc(t){ return String(t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function addVariationRow(size, color, hex, stock){
  const uid = 'n' + (++varUidCounter);
  const wrap = document.createElement('div');
  wrap.className = 'var-row vb-row is-new';
  wrap.innerHTML = `
    <input type="hidden" name="variations[${uid}][sort]" value="0" class="vb-sort">
    <div class="vb-grid">
      <label><span class="vb-m">Size</span><input type="text" name="variations[${uid}][size]" value="${vbEsc(size||'')}" placeholder="—" class="vb-size"></label>
      <label class="vb-colorcell"><span class="vb-m">Colour</span>
        <span class="vb-colorpair">
          <input type="color" name="variations[${uid}][color_hex]" value="${vbEsc(hex||'#111111')}">
          <input type="text" name="variations[${uid}][color]" value="${vbEsc(color||'')}" placeholder="—" class="vb-color">
        </span>
      </label>
      <label><span class="vb-m">Stock</span><input type="number" min="0" name="variations[${uid}][stock_qty]" value="${stock === undefined ? 0 : stock}" class="vb-stock"></label>
      <label><span class="vb-m">Selling</span><input type="number" step="0.01" min="0" name="variations[${uid}][selling_price]" placeholder="Base"></label>
      <label><span class="vb-m">Special</span><input type="number" step="0.01" min="0" name="variations[${uid}][special_price]" placeholder="—"></label>
      <label><span class="vb-m">Cost</span><input type="number" step="0.01" min="0" name="variations[${uid}][cost_price]" placeholder="Base"></label>
      <label class="vb-imgcell"><span class="vb-m">Image</span>
        <span class="vb-img">
          <span class="img-row-preview small" id="varprev_${uid}"><span class="ph">🖼</span></span>
          <input type="file" name="variations[${uid}][image]" accept=".jpg,.jpeg,.png,.webp,.gif" onchange="previewImage(this,'varprev_${uid}')">
        </span>
      </label>
      <button type="button" class="mini-btn del vb-del" onclick="this.closest('.var-row').remove();vbRefresh()" title="Remove row">✕</button>
    </div>`;
  document.getElementById('newVariationRows').appendChild(wrap);
  vbRefresh();
  return wrap;
}
/* colour list in the quick builder */
function vbAddColor(name, hex){
  const box = document.getElementById('vbColors');
  if (name && [...box.querySelectorAll('.vb-cname')].some(i => i.value.trim().toLowerCase() === name.toLowerCase())) return;
  const row = document.createElement('div');
  row.className = 'vb-crow';
  row.innerHTML = `<input type="color" class="vb-chex" value="${vbEsc(hex||'#111111')}"><input type="text" class="vb-cname" value="${vbEsc(name||'')}" placeholder="Colour name"><button type="button" class="mini-btn del" onclick="this.parentNode.remove()">✕</button>`;
  box.appendChild(row);
  if (!name) row.querySelector('.vb-cname').focus();
}
document.querySelectorAll('.vb-chip[data-sizes]').forEach(b => b.addEventListener('click', () => { document.getElementById('vbSizes').value = b.dataset.sizes; }));
/* sizes × colours → one row each (skips combinations that already exist) */
function vbGenerate(){
  const sizes  = document.getElementById('vbSizes').value.split(',').map(s => s.trim()).filter(Boolean);
  const colors = [...document.querySelectorAll('#vbColors .vb-crow')].map(r => ({name: r.querySelector('.vb-cname').value.trim(), hex: r.querySelector('.vb-chex').value})).filter(c => c.name);
  const stock  = Math.max(0, parseInt(document.getElementById('vbStock').value, 10) || 0);
  const note   = document.getElementById('vbNote');
  if (!sizes.length && !colors.length){ note.textContent = 'Add at least one size or colour.'; return; }
  const have = new Set([...document.querySelectorAll('.vb-row')].filter(r => !r.classList.contains('marked-del')).map(r => (r.querySelector('.vb-size').value.trim() + '|' + r.querySelector('.vb-color').value.trim()).toLowerCase()));
  const S = sizes.length ? sizes : [''], C = colors.length ? colors : [{name:'', hex:'#111111'}];
  let added = 0;
  C.forEach(c => S.forEach(sz => {
    const k = (sz + '|' + c.name).toLowerCase();
    if (have.has(k)) return;
    addVariationRow(sz, c.name, c.hex, stock); have.add(k); added++;
  }));
  document.getElementById('hasVariations').checked = true;
  note.textContent = added ? added + ' row' + (added > 1 ? 's' : '') + ' added — set each stock, then Save.' : 'All those combinations already exist.';
  vbRefresh();
}
/* keep sort order = on-screen order, show total stock, flag zero-stock rows */
function vbRefresh(){
  let total = 0, rows = 0;
  document.querySelectorAll('.vb-row').forEach((r, i) => {
    const so = r.querySelector('.vb-sort'); if (so) so.value = i;
    const st = r.querySelector('.vb-stock');
    if (st){ const n = parseInt(st.value, 10) || 0; st.classList.toggle('is-zero', n <= 0); if (!r.classList.contains('marked-del')) { total += n; rows++; } }
  });
  const t = document.getElementById('vbTotal');
  if (t) t.textContent = rows ? rows + ' size/colour row' + (rows > 1 ? 's' : '') + ' · ' + total + ' pcs in stock' : '';
}
document.addEventListener('input', e => { if (e.target.closest && e.target.closest('.vb-row')) vbRefresh(); });
document.addEventListener('change', e => { if (e.target.closest && e.target.closest('.vb-row')) vbRefresh(); });
vbRefresh();

function previewImage(input, targetId){
  const el = document.getElementById(targetId);
  const file = input.files[0];
  if (!file){ el.innerHTML = '<span class="ph">🖼</span>'; return; }
  const reader = new FileReader();
  reader.onload = e => { el.innerHTML = `<img src="${e.target.result}">`; };
  reader.readAsDataURL(file);
}

function toggleVariations(){
  const body = document.getElementById('variationsBody');
  body.style.display = document.getElementById('hasVariations').checked ? '' : 'none';
}

/* ---- Rich text description editor (bold / italic / underline / colour / highlight) ---- */
function rteCmd(cmd, val){
  document.getElementById('descEditor').focus();
  document.execCommand(cmd, false, val || null);
}
document.getElementById('itemForm').addEventListener('submit', function(){
  document.getElementById('descHidden').value = document.getElementById('descEditor').innerHTML;
});

/* ---- Select2 for Category / Brand ---- */
$(function(){
  $('.select2-field').select2({ width: '100%', allowClear: true, placeholder: 'Search…' });
});
</script>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>