<?php
/**
 * admin/products.php — Product Manager
 * Products are assigned to a category (any level) and are the parent
 * records that Variations (admin/variations.php) attach to.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Products';
$active    = 'products';

/* ---------------- POST actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id         = (int)($_POST['id'] ?? 0);
        $name       = trim((string)($_POST['name'] ?? ''));
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $sku        = trim((string)($_POST['sku'] ?? ''));
        $price      = (float)($_POST['base_price'] ?? 0);
        $sort       = (int)($_POST['sort_order'] ?? 0);
        $desc       = trim((string)($_POST['description'] ?? ''));
        $isActive   = isset($_POST['is_active']) ? 1 : (($action === 'create') ? 1 : 0);

        $err = '';
        if ($name === '' || mb_strlen($name) > 150) {
            $err = 'Name is required (max 150 characters).';
        } elseif (mb_strlen($sku) > 60) {
            $err = 'SKU is too long (max 60 characters).';
        } elseif ($price < 0) {
            $err = 'Price cannot be negative.';
        } else {
            $cat = get_category($categoryId);
            if (!$cat) {
                $err = 'Please choose a valid category.';
            }
        }

        if ($err === '') {
            if ($action === 'create') {
                $slug = make_unique_slug($name);
                db()->prepare('INSERT INTO products (category_id, name, slug, sku, base_price, description, is_active, sort_order)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute([$categoryId, $name, $slug, $sku ?: null, $price, $desc ?: null, $isActive, $sort]);
                flash('ok', '"' . $name . '" created successfully.');
            } else {
                $existing = get_product($id);
                if (!$existing) {
                    flash('err', 'Product not found.');
                    redirect('/admin/products');
                }
                $slug = ($name === $existing['name']) ? $existing['slug'] : make_unique_slug($name, null);
                db()->prepare('UPDATE products
                               SET category_id = ?, name = ?, slug = ?, sku = ?, base_price = ?, description = ?, is_active = ?, sort_order = ?
                               WHERE id = ?')
                   ->execute([$categoryId, $name, $slug, $sku ?: null, $price, $desc ?: null, $isActive, $sort, $id]);
                flash('ok', '"' . $name . '" updated successfully.');
            }
        } else {
            flash('err', $err);
        }
        redirect('/admin/products');
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE products SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        flash('ok', 'Status updated.');
        redirect('/admin/products');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        flash('ok', 'Product deleted.');
        redirect('/admin/products');
    }
}

/* ---------------- Data ---------------- */
$products     = get_all_products();
$categoryTree = get_category_tree(false);
$categoryFlat = flatten_categories_for_select($categoryTree);

require __DIR__ . '/includes/header.php';
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success">✔ <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error">⚠ <?= e($m) ?></div><?php endif; ?>

<!-- ===== LIST ===== -->
<div class="panel">
  <div class="panel-head">
    <h3>All Products (<?= count($products) ?>)</h3>
    <button type="button" class="btn-add" onclick="openAdd()">+ Add Product</button>
  </div>
  <table>
    <thead>
      <tr><th>#</th><th>Name</th><th>Category</th><th>SKU</th><th>Price</th><th>Status</th><th style="width:230px">Actions</th></tr>
    </thead>
    <tbody>
      <?php foreach ($products as $p): ?>
      <tr>
        <td><?= (int)$p['id'] ?></td>
        <td><b><?= e($p['name']) ?></b></td>
        <td class="crumb"><?= e($p['category_name'] ?? '—') ?></td>
        <td class="crumb"><?= e($p['sku'] ?? '—') ?></td>
        <td>Rs <?= number_format((float)$p['base_price'], 2) ?></td>
        <td><span class="tag <?= $p['is_active'] ? 'tag-on' : 'tag-off' ?>"><?= $p['is_active'] ? 'Active' : 'Hidden' ?></span></td>
        <td>
          <div class="row-actions">
            <button type="button" class="mini-btn edit" onclick='openEdit(<?= json_encode([
              "id"=>(int)$p["id"],"name"=>$p["name"],"category_id"=>(int)$p["category_id"],
              "sku"=>$p["sku"],"base_price"=>$p["base_price"],"sort_order"=>(int)$p["sort_order"],
              "description"=>$p["description"],"is_active"=>(int)$p["is_active"]
            ]) ?>)'>Edit</button>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="mini-btn toggle" type="submit"><?= $p['is_active'] ? 'Hide' : 'Show' ?></button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete “<?= e($p['name']) ?>”?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="mini-btn del" type="submit">Delete</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$products): ?>
      <tr><td colspan="7" style="text-align:center;color:var(--muted)">No products yet — create one above.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- ===== ADD MODAL ===== -->
<div id="addOverlay" class="modal-overlay" onclick="if(event.target===this) closeAdd()">
  <div class="modal-box">
    <h3>Add Product</h3>
    <form method="post" class="form-grid" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">

      <label>Name
        <input type="text" name="name" required maxlength="150" placeholder="e.g. Galaxy S24">
      </label>
      <label>Category
        <select name="category_id" required>
          <option value="">— Select category —</option>
          <?php foreach ($categoryFlat as $row): ?>
            <option value="<?= (int)$row['id'] ?>"><?= str_repeat('— ', (int)$row['depth']) ?><?= e($row['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>SKU (optional)
        <input type="text" name="sku" maxlength="60" placeholder="e.g. SM-S921">
      </label>
      <label>Base Price
        <input type="number" name="base_price" step="0.01" min="0" value="0">
      </label>
      <label>Sort Order
        <input type="number" name="sort_order" value="0" min="0" max="9999">
      </label>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn-add">+ Add Product</button>
        <button type="button" class="mini-btn toggle" onclick="closeAdd()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== EDIT MODAL ===== -->
<div id="editOverlay" class="modal-overlay" onclick="if(event.target===this) closeEdit()">
  <div class="modal-box">
    <h3>Edit Product</h3>
    <form method="post" class="form-grid" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="edit_id">

      <label>Name
        <input type="text" name="name" id="edit_name" required maxlength="150">
      </label>
      <label>Category
        <select name="category_id" id="edit_category_id" required>
          <?php foreach ($categoryFlat as $row): ?>
            <option value="<?= (int)$row['id'] ?>"><?= str_repeat('— ', (int)$row['depth']) ?><?= e($row['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>SKU
        <input type="text" name="sku" id="edit_sku" maxlength="60">
      </label>
      <label>Base Price
        <input type="number" name="base_price" id="edit_base_price" step="0.01" min="0">
      </label>
      <label>Sort Order
        <input type="number" name="sort_order" id="edit_sort_order" min="0" max="9999">
      </label>
      <label style="display:flex;align-items:center;gap:8px;margin-top:6px">
        <input type="checkbox" name="is_active" id="edit_is_active" style="width:auto;margin:0">
        Active
      </label>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn-add">Save Changes</button>
        <button type="button" class="mini-btn toggle" onclick="closeEdit()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAdd(){ document.getElementById('addOverlay').classList.add('show'); }
function closeAdd(){ document.getElementById('addOverlay').classList.remove('show'); }
function openEdit(p){
  document.getElementById('edit_id').value = p.id;
  document.getElementById('edit_name').value = p.name;
  document.getElementById('edit_category_id').value = p.category_id;
  document.getElementById('edit_sku').value = p.sku || '';
  document.getElementById('edit_base_price').value = p.base_price;
  document.getElementById('edit_sort_order').value = p.sort_order;
  document.getElementById('edit_is_active').checked = !!p.is_active;
  document.getElementById('editOverlay').classList.add('show');
}
function closeEdit(){
  document.getElementById('editOverlay').classList.remove('show');
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
