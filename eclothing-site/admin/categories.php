<?php
/**
 * admin/categories.php — Unified Category Manager
 * One page for Categories + Sub Categories + Sub-Sub Categories.
 * Shown as a single tree. No image/icon field — name only.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Categories';
$active    = 'categories';

$MAX_LEVEL = 3;
$labels    = [1 => 'Category', 2 => 'Sub Category', 3 => 'Sub-Sub Category'];

/** Handles the optional category image upload — same pattern as admin/brands.php. */
function process_category_image_upload(string $field, ?string $existingPath): array
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return [$existingPath, null];
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return [$existingPath, 'Category image upload failed — please try again.'];
    }
    if ($_FILES[$field]['size'] > 2 * 1024 * 1024) {
        return [$existingPath, 'Category image is too large (max 2MB).'];
    }
    $allowedExt = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    $ext = strtolower(pathinfo((string)$_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!isset($allowedExt[$ext])) {
        return [$existingPath, 'Category image must be a JPG, PNG, WEBP or GIF.'];
    }
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($_FILES[$field]['tmp_name']);
        if ($mime && !in_array($mime, $allowedExt, true)) {
            return [$existingPath, 'That file does not look like a valid image.'];
        }
    }
    $dir = __DIR__ . '/../assets/uploads/categories';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "php_flag engine off\n<FilesMatch \"\\.php$\">\nRequire all denied\n</FilesMatch>\n"); }

    $filename = 'cat_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $filename)) {
        return [$existingPath, 'Could not save the category image.'];
    }
    if ($existingPath) {
        $old = __DIR__ . '/../' . ltrim($existingPath, '/');
        if (is_file($old)) { @unlink($old); }
    }
    return ['/assets/uploads/categories/' . $filename, null];
}

/* ---------------- POST actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    /* ----- CREATE ----- */
    if ($action === 'create') {
        $name      = trim((string)($_POST['name'] ?? ''));
        $sort      = (int)($_POST['sort_order'] ?? 0);
        $icon      = trim((string)($_POST['icon'] ?? ''));
        $parentRaw = trim((string)($_POST['parent_id'] ?? ''));
        $parentId  = $parentRaw === '' ? null : (int)$parentRaw;
        $showInMenu = isset($_POST['show_in_menu']) ? 1 : 0;
        $showOnHome = isset($_POST['show_on_home']) ? 1 : 0;
        $showInDrawer = isset($_POST['show_in_drawer']) ? 1 : 0;

        $err   = '';
        $level = 1;

        if ($name === '' || mb_strlen($name) > 100) {
            $err = 'Name is required (max 100 characters).';
        } elseif (mb_strlen($icon) > 16) {
            $err = 'Icon must be 16 characters or fewer (a single emoji is usually 1–4).';
        } elseif ($parentId !== null) {
            $parent = get_category($parentId);
            if (!$parent) {
                $err = 'Please choose a valid parent category.';
            } elseif ((int)$parent['level'] >= $MAX_LEVEL) {
                $err = 'That parent is already at the deepest level — a maximum of ' . $MAX_LEVEL . ' levels is allowed.';
            } else {
                $level = (int)$parent['level'] + 1;
            }
        }

        $imagePath = null;
        if ($err === '') {
            [$imagePath, $upErr] = process_category_image_upload('image', null);
            if ($upErr) { $err = $upErr; }
        }

        if ($err === '') {
            $slug = make_unique_slug($name);
            db()->prepare('INSERT INTO categories (parent_id, name, slug, icon, image, level, sort_order, show_in_menu, show_on_home, show_in_drawer)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute([$parentId, $name, $slug, $icon ?: null, $imagePath, $level, $sort, $showInMenu, $showOnHome, $showInDrawer]);
            flash('ok', '"' . $name . '" created successfully.');
        } else {
            flash('err', $err);
        }
        redirect('/admin/categories');
    }

    /* ----- UPDATE ----- */
    if ($action === 'update') {
        $id        = (int)($_POST['id'] ?? 0);
        $name      = trim((string)($_POST['name'] ?? ''));
        $sort      = (int)($_POST['sort_order'] ?? 0);
        $icon      = trim((string)($_POST['icon'] ?? ''));
        $isActive  = isset($_POST['is_active']) ? 1 : 0;
        $showInMenu = isset($_POST['show_in_menu']) ? 1 : 0;
        $showOnHome = isset($_POST['show_on_home']) ? 1 : 0;
        $showInDrawer = isset($_POST['show_in_drawer']) ? 1 : 0;
        $removeImg = isset($_POST['remove_image']);
        $parentRaw = trim((string)($_POST['parent_id'] ?? ''));
        $parentId  = $parentRaw === '' ? null : (int)$parentRaw;

        $existing = get_category($id);
        $err = '';

        if (!$existing) {
            $err = 'Category not found.';
        } elseif ($name === '' || mb_strlen($name) > 100) {
            $err = 'Name is required (max 100 characters).';
        } elseif (mb_strlen($icon) > 16) {
            $err = 'Icon must be 16 characters or fewer (a single emoji is usually 1–4).';
        } elseif ($parentId !== null && $parentId === $id) {
            $err = 'A category cannot be its own parent.';
        }

        $newLevel = 1;
        if ($err === '' && $parentId !== null) {
            $parent = get_category($parentId);
            if (!$parent) {
                $err = 'Please choose a valid parent category.';
            } elseif (in_array($parentId, get_category_descendant_ids($id), true)) {
                $err = 'Cannot move a category under one of its own sub-categories.';
            } else {
                $newLevel = (int)$parent['level'] + 1;
            }
        }

        if ($err === '') {
            $depth = get_category_max_relative_depth($id); // how many levels of children it currently has
            if ($newLevel + $depth > $MAX_LEVEL) {
                $err = 'Moving this here would exceed the maximum of ' . $MAX_LEVEL . ' levels (it has sub-items of its own).';
            }
        }

        $imagePath = $existing['image'] ?? null;
        if ($err === '') {
            if ($removeImg && !empty($imagePath)) {
                $old = __DIR__ . '/../' . ltrim($imagePath, '/');
                if (is_file($old)) { @unlink($old); }
                $imagePath = null;
            }
            [$imagePath, $upErr] = process_category_image_upload('image', $imagePath);
            if ($upErr) { $err = $upErr; }
        }

        if ($err === '') {
            $slug = ($name === $existing['name']) ? $existing['slug'] : make_unique_slug($name, $id);

            db()->prepare('UPDATE categories
                           SET name = ?, slug = ?, parent_id = ?, sort_order = ?, is_active = ?, icon = ?, image = ?, show_in_menu = ?, show_on_home = ?, show_in_drawer = ?
                           WHERE id = ?')
               ->execute([$name, $slug, $parentId, $sort, $isActive, $icon ?: null, $imagePath, $showInMenu, $showOnHome, $showInDrawer, $id]);

            if ($newLevel !== (int)$existing['level']) {
                recompute_category_subtree_levels($id, $newLevel);
            }

            flash('ok', '"' . $name . '" updated successfully.');
        } else {
            flash('err', $err);
        }
        redirect('/admin/categories');
    }

    /* ----- TOGGLE ACTIVE ----- */
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE categories SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        flash('ok', 'Status updated.');
        redirect('/admin/categories');
    }

    /* ----- DELETE ONE (plus all of its sub-categories) ----- */
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $n  = delete_categories_by_ids([$id]);
        flash('ok', 'Deleted ' . $n . ' categor' . ($n === 1 ? 'y' : 'ies') . ' (including sub-categories). Items in them were kept and set to "No category".');
        redirect('/admin/categories');
    }

    /* ----- DELETE SELECTED (checkboxes) ----- */
    if ($action === 'bulk_delete') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        if (!$ids) {
            flash('err', 'Please tick at least one category to delete.');
        } else {
            $n = delete_categories_by_ids($ids);
            flash('ok', 'Deleted ' . $n . ' categor' . ($n === 1 ? 'y' : 'ies') . ' (including sub-categories). Items in them were kept and set to "No category".');
        }
        redirect('/admin/categories');
    }

    /* ----- DELETE ALL (one-time wipe, must type DELETE) ----- */
    if ($action === 'delete_all') {
        if (strtoupper(trim((string)($_POST['confirm_text'] ?? ''))) !== 'DELETE') {
            flash('err', 'Delete all was cancelled — you must type DELETE to confirm.');
            redirect('/admin/categories');
        }
        $allIds = db()->query('SELECT id FROM categories')->fetchAll(PDO::FETCH_COLUMN);
        $n = delete_categories_by_ids($allIds);
        flash('ok', 'All categories deleted (' . $n . ' removed). Items were kept and set to "No category".');
        redirect('/admin/categories');
    }
}

/* ---------------- Data for the page ---------------- */
$tree        = get_category_tree(false);          // include hidden, full nested tree
$flatForEdit = flatten_categories_for_select($tree); // every row, with depth, for parent <select>
$counts      = [1 => 0, 2 => 0, 3 => 0];
foreach ($flatForEdit as $row) {
    $counts[(int)$row['level']] = ($counts[(int)$row['level']] ?? 0) + 1;
}

require __DIR__ . '/includes/header.php';

/* Recursive renderer for the tree list */
function render_category_node(array $node, array $labels, int $maxLevel): void
{
    $hasChildren = !empty($node['children']);
    $level       = (int)$node['level'];
    ?>
    <li class="cat-node" data-id="<?= (int)$node['id'] ?>">
      <div class="cat-row">
        <button type="button" class="cat-toggle <?= $hasChildren ? '' : 'leaf' ?>" onclick="toggleNode(this)">
          <?= $hasChildren ? '▾' : '•' ?>
        </button>
        <input type="checkbox" class="cat-cb" name="ids[]" value="<?= (int)$node['id'] ?>" form="catBulkForm" onchange="catCbChanged(this)" style="width:16px;height:16px;margin:0;flex-shrink:0" title="Select">
        <span class="tag tag-lvl">L<?= $level ?></span>
        <?php if (!empty($node['image'])): ?>
          <img src="<?= BASE_URL . e($node['image']) ?>" alt="" style="width:26px;height:26px;border-radius:6px;object-fit:cover;flex-shrink:0">
        <?php elseif (!empty($node['icon'])): ?>
          <span style="font-size:18px;flex-shrink:0"><?= e($node['icon']) ?></span>
        <?php endif; ?>
        <b class="cat-name"><?= e($node['name']) ?></b>
        <span class="crumb">/category/<?= e($node['slug']) ?></span>
        <span class="tag <?= $node['is_active'] ? 'tag-on' : 'tag-off' ?>"><?= $node['is_active'] ? 'Active' : 'Hidden' ?></span>
        <?php if ($level === 1): ?>
          <span class="tag <?= !empty($node['show_in_menu']) ? 'tag-on' : 'tag-off' ?>" title="Shows in the top menu nav bar"><?= !empty($node['show_in_menu']) ? 'In Top Menu' : 'Not in Top Menu' ?></span>
          <span class="tag <?= !empty($node['show_on_home']) ? 'tag-on' : 'tag-off' ?>" title="Shows in the homepage category grid"><?= !empty($node['show_on_home']) ? 'On Homepage' : 'Not on Homepage' ?></span>
          <span class="tag <?= !empty($node['show_in_drawer']) ? 'tag-on' : 'tag-off' ?>" title="Shows in the Browse Categories mobile drawer"><?= !empty($node['show_in_drawer']) ? 'In Drawer' : 'Not in Drawer' ?></span>
        <?php endif; ?>
        <span class="crumb">sort: <?= (int)$node['sort_order'] ?></span>

        <div class="row-actions cat-actions">
          <a class="mini-btn toggle" href="<?= BASE_URL ?>/category/<?= e($node['slug']) ?>" target="_blank" rel="noopener" title="View on website"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
          <button type="button" class="mini-btn edit" onclick="openEdit(<?= (int)$node['id'] ?>)">Edit</button>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$node['id'] ?>">
            <button class="mini-btn toggle" type="submit"><?= $node['is_active'] ? 'Hide' : 'Show' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm(<?= e(json_encode('Delete “' . $node['name'] . '” and ALL of its sub-categories?' . "\n" . 'Items inside are kept (set to No category).', JSON_UNESCAPED_UNICODE)) ?>);">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$node['id'] ?>">
            <button class="mini-btn del" type="submit">Delete</button>
          </form>
        </div>
      </div>

      <?php if ($hasChildren): ?>
      <ul class="cat-children">
        <?php foreach ($node['children'] as $child): ?>
          <?php render_category_node($child, $labels, $maxLevel); ?>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </li>
    <?php
}
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success">✔ <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error">⚠ <?= e($m) ?></div><?php endif; ?>

<div class="stats">
  <div class="stat"><div class="ic">🗂️</div><div><b><?= (int)$counts[1] ?></b><span>Categories</span></div></div>
  <div class="stat"><div class="ic">📁</div><div><b><?= (int)$counts[2] ?></b><span>Sub Categories</span></div></div>
  <div class="stat"><div class="ic">📄</div><div><b><?= (int)$counts[3] ?></b><span>Sub-Sub Categories</span></div></div>
</div>

<div class="panel">
  <div class="panel-head">
    <h3>Category Visibility (Top Menu, Homepage &amp; Browse Categories Drawer)</h3>
  </div>
  <div class="panel-body" style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
    <p class="hint" style="margin:0">Choosing which categories (and sub-categories) show in the header Top Menu, the homepage boxes, and the mobile "Browse Categories" drawer is managed on its own page.</p>
    <a href="<?= BASE_URL ?>/admin/menu_categories" class="btn-add"><i class="fa-solid fa-bars"></i> Open Menu Categories</a>
  </div>
</div>

<!-- ===== TREE ===== -->
<div class="panel">
  <div class="panel-head">
    <h3>Category Tree (<?= count($flatForEdit) ?>)</h3>
    <div style="display:flex;gap:8px">
      <button type="button" class="btn-add" onclick="openAdd()">+ Add Category</button>
      <button type="button" class="mini-btn toggle" onclick="expandAll()">Expand all</button>
      <button type="button" class="mini-btn toggle" onclick="collapseAll()">Collapse all</button>
    </div>
  </div>
  <form method="post" id="catBulkForm" onsubmit="return confirmCatBulkDelete();">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_delete">
  </form>
  <form method="post" id="catDeleteAllForm" onsubmit="return confirmCatDeleteAll();">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_all">
    <input type="hidden" name="confirm_text" id="catDeleteAllConfirm" value="">
  </form>
  <?php if ($flatForEdit): ?>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 20px;border-bottom:1px solid var(--line);background:#fafbfd">
    <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:700;cursor:pointer">
      <input type="checkbox" id="catSelectAll" onchange="toggleAllCats(this.checked)" style="width:16px;height:16px"> Select all
    </label>
    <span id="catSelCount" style="font-size:12.5px;color:var(--muted)">0 selected</span>
    <button type="submit" form="catBulkForm" class="mini-btn del" id="catBulkDelBtn" disabled style="opacity:.5"><i class="fa-solid fa-trash"></i> Delete Selected</button>
    <span class="hint" style="margin:0">Ticking a category also ticks its sub-categories. Items are never deleted — they become "No category".</span>
    <button type="submit" form="catDeleteAllForm" class="mini-btn del" style="margin-left:auto;background:#c62828;color:#fff"><i class="fa-solid fa-dumpster"></i> Delete ALL Categories (<?= count($flatForEdit) ?>)</button>
  </div>
  <?php endif; ?>
  <div class="panel-body">
    <ul class="cat-tree">
      <?php foreach ($tree as $node): ?>
        <?php render_category_node($node, $labels, $MAX_LEVEL); ?>
      <?php endforeach; ?>
      <?php if (!$tree): ?>
        <li style="color:var(--muted)">Nothing here yet — create a category above.</li>
      <?php endif; ?>
    </ul>
  </div>
</div>

<!-- ===== ADD MODAL ===== -->
<div id="addOverlay" class="modal-overlay" onclick="if(event.target===this) closeAdd()">
  <div class="modal-box">
    <h3>Add Category</h3>
    <form method="post" class="form-grid" autocomplete="off" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">

      <label>Name
        <input type="text" name="name" required maxlength="100" placeholder="e.g. Televisions">
      </label>

      <label>Parent (leave blank for a top-level Category)
        <select name="parent_id">
          <option value="">— None (top level) —</option>
          <?php foreach ($flatForEdit as $row): ?>
            <?php if ((int)$row['level'] >= $MAX_LEVEL) continue; // can't be a parent, would exceed max depth ?>
            <option value="<?= (int)$row['id'] ?>">
              <?= str_repeat('— ', (int)$row['depth']) ?><?= e($row['name']) ?> (<?= e($labels[(int)$row['level']]) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>Sort Order
        <input type="number" name="sort_order" value="0" min="0" max="9999">
      </label>

      <label>Icon <span class="hint" style="font-weight:400">(optional — a single emoji, e.g. 📺)</span>
        <input type="text" name="icon" maxlength="16" placeholder="📺">
      </label>

      <label style="grid-column:1/-1">Image <span class="hint" style="font-weight:400">(optional)</span>
        <div class="logo-preview" id="add_cat_image_preview" style="height:80px;cursor:pointer"
             onclick="document.getElementById('add_cat_image_input').click()"
             ondragover="event.preventDefault();event.stopPropagation();this.classList.add('drag-over')"
             ondragleave="this.classList.remove('drag-over')"
             ondrop="handleAddCatImageDrop(event)">
          <span class="ph">🖼 No image uploaded</span>
        </div>
        <input type="file" name="image" id="add_cat_image_input" accept=".jpg,.jpeg,.png,.webp,.gif" style="display:none" onchange="previewAddCatImageFromInput(this)">
        <p class="hint">Drag &amp; drop, paste with Ctrl+V, or click above to upload — JPG, PNG, WEBP or GIF, max 2MB.</p>
      </label>

      <label style="display:flex;align-items:center;gap:8px;grid-column:1/-1">
        <input type="checkbox" name="show_in_menu" checked style="width:auto;margin:0">
        Show in Top Menu <span class="hint" style="font-weight:400">(controls the header top menu — including its dropdown if this is a sub-category)</span>
      </label>

      <label style="display:flex;align-items:center;gap:8px;grid-column:1/-1">
        <input type="checkbox" name="show_on_home" checked style="width:auto;margin:0">
        Show on Homepage <span class="hint" style="font-weight:400">(top-level categories only — controls the homepage category grid)</span>
      </label>

      <label style="display:flex;align-items:center;gap:8px;grid-column:1/-1">
        <input type="checkbox" name="show_in_drawer" checked style="width:auto;margin:0">
        Show in Browse Categories Drawer <span class="hint" style="font-weight:400">(top-level categories only — controls the mobile "BROWSE CATEGORIES" menu)</span>
      </label>

      <div style="display:flex;gap:10px">
        <button type="submit" class="btn-add">+ Add Category</button>
        <button type="button" class="mini-btn toggle" onclick="closeAdd()">Cancel</button>
      </div>
    </form>
    <p style="margin-top:14px;color:var(--muted);font-size:13px">
      Pick a parent to create a Sub Category or Sub-Sub Category — up to <?= $MAX_LEVEL ?> levels deep. Icon and image are both optional.
    </p>
  </div>
</div>

<!-- ===== EDIT MODAL ===== -->
<div id="editOverlay" class="modal-overlay" onclick="if(event.target===this) closeEdit()">
  <div class="modal-box">
    <h3>Edit Category</h3>
    <form method="post" class="form-grid" id="editForm" autocomplete="off" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="edit_id">

      <label>Name
        <input type="text" name="name" id="edit_name" required maxlength="100">
      </label>

      <label>Parent
        <select name="parent_id" id="edit_parent_id">
          <option value="">— None (top level) —</option>
          <?php foreach ($flatForEdit as $row): ?>
            <option value="<?= (int)$row['id'] ?>" data-level="<?= (int)$row['level'] ?>" data-self="<?= (int)$row['id'] ?>">
              <?= str_repeat('— ', (int)$row['depth']) ?><?= e($row['name']) ?> (<?= e($labels[(int)$row['level']]) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>Sort Order
        <input type="number" name="sort_order" id="edit_sort_order" min="0" max="9999">
      </label>

      <label>Icon <span class="hint" style="font-weight:400">(optional — a single emoji, e.g. 📺)</span>
        <input type="text" name="icon" id="edit_icon" maxlength="16" placeholder="📺">
      </label>

      <label style="grid-column:1/-1">Image <span class="hint" style="font-weight:400">(optional)</span>
        <div class="logo-preview" id="edit_cat_image_preview" style="height:80px;cursor:pointer"
             onclick="document.getElementById('edit_cat_image_input').click()"
             ondragover="event.preventDefault();event.stopPropagation();this.classList.add('drag-over')"
             ondragleave="this.classList.remove('drag-over')"
             ondrop="handleEditCatImageDrop(event)">
          <span class="ph">🖼 No image uploaded</span>
        </div>
        <input type="file" name="image" id="edit_cat_image_input" accept=".jpg,.jpeg,.png,.webp,.gif" style="display:none" onchange="previewEditCatImageFromInput(this)">
        <label class="choice-pill" id="edit_remove_image_wrap" style="display:none;margin-top:8px">
          <input type="checkbox" name="remove_image" id="edit_remove_image">
          <span>Remove current image</span>
        </label>
        <p class="hint">Drag &amp; drop, paste with Ctrl+V, or click above to upload a new image — JPG, PNG, WEBP or GIF, max 2MB.</p>
      </label>

      <label style="display:flex;align-items:center;gap:8px;margin-top:6px">
        <input type="checkbox" name="is_active" id="edit_is_active" style="width:auto;margin:0">
        Active (visible on the store)
      </label>

      <label style="display:flex;align-items:center;gap:8px;grid-column:1/-1">
        <input type="checkbox" name="show_in_menu" id="edit_show_in_menu" style="width:auto;margin:0">
        Show in Top Menu <span class="hint" style="font-weight:400">(controls the top menu — for sub-categories, this controls the dropdown)</span>
      </label>

      <label style="display:flex;align-items:center;gap:8px;grid-column:1/-1">
        <input type="checkbox" name="show_on_home" id="edit_show_on_home" style="width:auto;margin:0">
        Show on Homepage <span class="hint" style="font-weight:400">(top-level categories only)</span>
      </label>

      <label style="display:flex;align-items:center;gap:8px;grid-column:1/-1">
        <input type="checkbox" name="show_in_drawer" id="edit_show_in_drawer" style="width:auto;margin:0">
        Show in Browse Categories Drawer <span class="hint" style="font-weight:400">(top-level categories only)</span>
      </label>

      <div style="display:flex;gap:10px">
        <button type="submit" class="btn-add">Save Changes</button>
        <button type="button" class="mini-btn toggle" onclick="closeEdit()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
const CATEGORIES = <?= json_encode(array_map(function($r){
    return ['id'=>(int)$r['id'],'name'=>$r['name'],'parent_id'=>$r['parent_id']!==null?(int)$r['parent_id']:null,
            'sort_order'=>(int)$r['sort_order'],'is_active'=>(int)$r['is_active'],'level'=>(int)$r['level'],
            'icon'=>$r['icon'] ?? '', 'image'=>$r['image'] ?? null,
            'show_in_menu'=>(int)($r['show_in_menu'] ?? 1),'show_on_home'=>(int)($r['show_on_home'] ?? 1),
            'show_in_drawer'=>(int)($r['show_in_drawer'] ?? 1)];
}, $flatForEdit)) ?>;

function toggleNode(btn){
  if (btn.classList.contains('leaf')) return;
  const li = btn.closest('.cat-node');
  const kids = li.querySelector(':scope > .cat-children');
  if (!kids) return;
  const collapsed = kids.style.display === 'none';
  kids.style.display = collapsed ? '' : 'none';
  btn.textContent = collapsed ? '▾' : '▸';
}
function expandAll(){
  document.querySelectorAll('.cat-children').forEach(el => el.style.display = '');
  document.querySelectorAll('.cat-toggle:not(.leaf)').forEach(el => el.textContent = '▾');
}
function collapseAll(){
  document.querySelectorAll('.cat-tree > .cat-node > .cat-children').forEach(el => el.style.display = 'none');
  document.querySelectorAll('.cat-tree > .cat-node > .cat-row > .cat-toggle:not(.leaf)').forEach(el => el.textContent = '▸');
}
/* ---- Bulk selection / delete ---- */
function catCbs(){ return Array.from(document.querySelectorAll('.cat-cb')); }
function catCbChanged(cb){
  // ticking/unticking a parent applies to all of its sub-categories
  const li = cb.closest('.cat-node');
  li.querySelectorAll('.cat-children .cat-cb').forEach(c => c.checked = cb.checked);
  updateCatSelCount();
}
function toggleAllCats(on){ catCbs().forEach(cb => cb.checked = on); updateCatSelCount(); }
function updateCatSelCount(){
  const all = catCbs(), n = all.filter(cb => cb.checked).length;
  document.getElementById('catSelCount').textContent = n + ' selected';
  const btn = document.getElementById('catBulkDelBtn');
  btn.disabled = n === 0; btn.style.opacity = n === 0 ? .5 : 1;
  document.getElementById('catSelectAll').checked = all.length > 0 && n === all.length;
}
function confirmCatBulkDelete(){
  const n = catCbs().filter(cb => cb.checked).length;
  if (!n) { alert('Please tick at least one category.'); return false; }
  return confirm('Delete ' + n + ' selected categor' + (n === 1 ? 'y' : 'ies') + ' and ALL of their sub-categories?\nItems inside them are kept (set to "No category"). This cannot be undone.');
}
function confirmCatDeleteAll(){
  const t = prompt('This will permanently delete ALL <?= count($flatForEdit) ?> categories and sub-categories.\nItems are kept (set to "No category").\n\nType DELETE to confirm:');
  if (t === null) return false;
  if (t.trim().toUpperCase() !== 'DELETE') { alert('Cancelled — you must type DELETE.'); return false; }
  document.getElementById('catDeleteAllConfirm').value = 'DELETE';
  return true;
}

function openAdd(){
  document.getElementById('addOverlay').classList.add('show');
}
function closeAdd(){
  document.getElementById('addOverlay').classList.remove('show');
  document.querySelector('#addOverlay form').reset();
  document.getElementById('add_cat_image_preview').innerHTML = '<span class="ph">🖼 No image uploaded</span>';
}
function openEdit(id){
  const cat = CATEGORIES.find(c => c.id === id);
  if (!cat) return;
  document.getElementById('edit_id').value = cat.id;
  document.getElementById('edit_name').value = cat.name;
  document.getElementById('edit_sort_order').value = cat.sort_order;
  document.getElementById('edit_icon').value = cat.icon || '';
  document.getElementById('edit_is_active').checked = !!cat.is_active;
  document.getElementById('edit_show_in_menu').checked = !!cat.show_in_menu;
  document.getElementById('edit_show_on_home').checked = !!cat.show_on_home;
  document.getElementById('edit_show_in_drawer').checked = !!cat.show_in_drawer;
  document.getElementById('edit_remove_image').checked = false;

  const preview = document.getElementById('edit_cat_image_preview');
  const removeWrap = document.getElementById('edit_remove_image_wrap');
  if (cat.image) {
    preview.innerHTML = `<img src="<?= BASE_URL ?>${cat.image}" alt="">`;
    removeWrap.style.display = '';
  } else {
    preview.innerHTML = '<span class="ph">🖼 No image uploaded</span>';
    removeWrap.style.display = 'none';
  }

  const sel = document.getElementById('edit_parent_id');
  // Disallow choosing itself or anything below max depth as parent
  Array.from(sel.options).forEach(opt => {
    if (opt.value === '') { opt.disabled = false; return; }
    const optId = parseInt(opt.dataset.self, 10);
    const optLevel = parseInt(opt.dataset.level, 10);
    opt.disabled = (optId === id) || (optLevel >= 3);
  });
  sel.value = cat.parent_id !== null ? String(cat.parent_id) : '';

  document.getElementById('editOverlay').classList.add('show');
}
function closeEdit(){
  document.getElementById('editOverlay').classList.remove('show');
}

/* ---- Category image: drag & drop, paste (Ctrl+V), or click to upload (Add + Edit modals) ---- */
function showCatPreviewFile(previewId, file){
  const reader = new FileReader();
  reader.onload = e => { document.getElementById(previewId).innerHTML = `<img src="${e.target.result}" alt="">`; };
  reader.readAsDataURL(file);
}
function assignCatFile(inputId, previewId, file){
  if (!file || !file.type || !file.type.startsWith('image/')) return;
  const input = document.getElementById(inputId);
  const dt = new DataTransfer();
  dt.items.add(file);
  input.files = dt.files;
  showCatPreviewFile(previewId, file);
  if (inputId === 'edit_cat_image_input') { document.getElementById('edit_remove_image').checked = false; }
}
function previewAddCatImageFromInput(input){ const f = input.files[0]; if (f) showCatPreviewFile('add_cat_image_preview', f); }
function previewEditCatImageFromInput(input){
  const f = input.files[0];
  if (f) { showCatPreviewFile('edit_cat_image_preview', f); document.getElementById('edit_remove_image').checked = false; }
}
function handleAddCatImageDrop(e){
  e.preventDefault(); e.stopPropagation();
  document.getElementById('add_cat_image_preview').classList.remove('drag-over');
  const files = e.dataTransfer.files;
  if (files.length) assignCatFile('add_cat_image_input', 'add_cat_image_preview', files[0]);
}
function handleEditCatImageDrop(e){
  e.preventDefault(); e.stopPropagation();
  document.getElementById('edit_cat_image_preview').classList.remove('drag-over');
  const files = e.dataTransfer.files;
  if (files.length) assignCatFile('edit_cat_image_input', 'edit_cat_image_preview', files[0]);
}
document.addEventListener('paste', e => {
  const addOpen = document.getElementById('addOverlay').classList.contains('show');
  const editOpen = document.getElementById('editOverlay').classList.contains('show');
  if (!addOpen && !editOpen) return;
  const items = e.clipboardData ? e.clipboardData.items : [];
  for (let i = 0; i < items.length; i++) {
    if (items[i].type && items[i].type.startsWith('image/')) {
      const file = items[i].getAsFile();
      if (addOpen) assignCatFile('add_cat_image_input', 'add_cat_image_preview', file);
      else assignCatFile('edit_cat_image_input', 'edit_cat_image_preview', file);
    }
  }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
