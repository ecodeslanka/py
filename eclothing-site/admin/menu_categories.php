<?php
/**
 * admin/menu_categories.php — Menu Categories
 * One combined table controlling all three of the site's category pickers at once:
 * the header Top Menu (+ its hover dropdown, so sub-categories are listed too), the
 * homepage category boxes, and the mobile "Browse Categories" off-canvas drawer.
 * Each column is independent — a category can be shown in one, some, or all three —
 * and each column header has its own "select all" checkbox for quick bulk changes.
 * Saved together with a single Save button.
 * Admin -> Categories itself only handles the category tree (add/edit/delete).
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Menu Categories';
$active    = 'menu_categories';

ensure_categories_table();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_categories_visibility') {
        $menuIds   = array_map('intval', $_POST['show_in_menu'] ?? []);
        $homeIds   = array_map('intval', $_POST['show_on_home'] ?? []);
        $drawerIds = array_map('intval', $_POST['show_in_drawer'] ?? []);

        // All three columns apply to top-level categories AND their sub-categories.
        $eligibleIds = array_map('intval', db()->query('SELECT id FROM categories WHERE level <= 2')->fetchAll(PDO::FETCH_COLUMN));

        $updMenu   = db()->prepare('UPDATE categories SET show_in_menu = ? WHERE id = ?');
        $updHome   = db()->prepare('UPDATE categories SET show_on_home = ? WHERE id = ?');
        $updDrawer = db()->prepare('UPDATE categories SET show_in_drawer = ? WHERE id = ?');

        foreach ($eligibleIds as $cid) {
            $updMenu->execute([in_array($cid, $menuIds, true) ? 1 : 0, $cid]);
            $updHome->execute([in_array($cid, $homeIds, true) ? 1 : 0, $cid]);
            $updDrawer->execute([in_array($cid, $drawerIds, true) ? 1 : 0, $cid]);
        }

        flash('ok', 'Category visibility updated.');
        redirect('/admin/menu_categories');
    }
}

$tree = get_category_tree(false); // include hidden/inactive too, so admin can see the full picture

require __DIR__ . '/includes/header.php';
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success">✔ <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error">⚠ <?= e($m) ?></div><?php endif; ?>

<div class="panel">
  <div class="panel-head">
    <h3>Category Visibility</h3>
  </div>
  <div class="panel-body">
    <p class="hint" style="margin-bottom:14px">
      One table for all three category pickers — <b>Top Menu</b>, <b>Homepage</b>, and <b>Browse Categories Drawer</b> — for both top-level categories and their sub-categories. Tick the checkbox in a column header to select/deselect everything in that column at once, then save.
    </p>
    <form method="post" id="visForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_categories_visibility">
      <table>
        <thead>
          <tr>
            <th>Category</th>
            <th style="width:80px">Level</th>
            <th style="width:100px">Status</th>
            <th style="width:130px">
              <label style="display:flex;align-items:center;gap:6px;font-weight:700;cursor:pointer">
                <input type="checkbox" class="col-select-all" data-col="show_in_menu" style="width:16px;height:16px">
                Top Menu
              </label>
            </th>
            <th style="width:130px">
              <label style="display:flex;align-items:center;gap:6px;font-weight:700;cursor:pointer">
                <input type="checkbox" class="col-select-all" data-col="show_on_home" style="width:16px;height:16px">
                Homepage
              </label>
            </th>
            <th style="width:150px">
              <label style="display:flex;align-items:center;gap:6px;font-weight:700;cursor:pointer">
                <input type="checkbox" class="col-select-all" data-col="show_in_drawer" style="width:16px;height:16px">
                Browse Categories
              </label>
            </th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$tree): ?>
          <tr><td colspan="6" style="color:var(--muted)">No categories yet — add some in Admin &rarr; Categories first.</td></tr>
          <?php endif; ?>
          <?php foreach ($tree as $topCat): ?>
          <tr>
            <td>
              <?php if (!empty($topCat['image'])): ?>
                <img src="<?= BASE_URL . e($topCat['image']) ?>" alt="" style="width:24px;height:24px;border-radius:6px;object-fit:cover;vertical-align:middle;margin-right:8px">
              <?php elseif (!empty($topCat['icon'])): ?>
                <span style="margin-right:8px"><?= e($topCat['icon']) ?></span>
              <?php endif; ?>
              <b><?= e($topCat['name']) ?></b>
            </td>
            <td><span class="tag tag-lvl">L1</span></td>
            <td><?= $topCat['is_active'] ? '<span class="tag tag-on">Active</span>' : '<span class="tag tag-off">Hidden</span>' ?></td>
            <td><input type="checkbox" name="show_in_menu[]" value="<?= (int)$topCat['id'] ?>" <?= !empty($topCat['show_in_menu']) ? 'checked' : '' ?> style="width:18px;height:18px" class="col-cb" data-col="show_in_menu"></td>
            <td><input type="checkbox" name="show_on_home[]" value="<?= (int)$topCat['id'] ?>" <?= !empty($topCat['show_on_home']) ? 'checked' : '' ?> style="width:18px;height:18px" class="col-cb" data-col="show_on_home"></td>
            <td><input type="checkbox" name="show_in_drawer[]" value="<?= (int)$topCat['id'] ?>" <?= !empty($topCat['show_in_drawer']) ? 'checked' : '' ?> style="width:18px;height:18px" class="col-cb" data-col="show_in_drawer"></td>
          </tr>
          <?php foreach ($topCat['children'] ?? [] as $subCat): ?>
          <tr>
            <td style="padding-left:32px;color:var(--muted)">
              <?php if (!empty($subCat['image'])): ?>
                <img src="<?= BASE_URL . e($subCat['image']) ?>" alt="" style="width:22px;height:22px;border-radius:6px;object-fit:cover;vertical-align:middle;margin-right:8px">
              <?php elseif (!empty($subCat['icon'])): ?>
                <span style="margin-right:8px"><?= e($subCat['icon']) ?></span>
              <?php endif; ?>
              — <?= e($subCat['name']) ?>
            </td>
            <td><span class="tag tag-lvl">L2</span></td>
            <td><?= $subCat['is_active'] ? '<span class="tag tag-on">Active</span>' : '<span class="tag tag-off">Hidden</span>' ?></td>
            <td><input type="checkbox" name="show_in_menu[]" value="<?= (int)$subCat['id'] ?>" <?= !empty($subCat['show_in_menu']) ? 'checked' : '' ?> style="width:18px;height:18px" class="col-cb" data-col="show_in_menu"></td>
            <td><input type="checkbox" name="show_on_home[]" value="<?= (int)$subCat['id'] ?>" <?= !empty($subCat['show_on_home']) ? 'checked' : '' ?> style="width:18px;height:18px" class="col-cb" data-col="show_on_home"></td>
            <td><input type="checkbox" name="show_in_drawer[]" value="<?= (int)$subCat['id'] ?>" <?= !empty($subCat['show_in_drawer']) ? 'checked' : '' ?> style="width:18px;height:18px" class="col-cb" data-col="show_in_drawer"></td>
          </tr>
          <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
      <button type="submit" class="btn-add" style="margin-top:14px">Save Category Visibility</button>
    </form>
  </div>
</div>

<script>
/* Each column header checkbox selects/deselects every checkbox in that column (data-col match). */
document.querySelectorAll('.col-select-all').forEach(function(headerCb){
  headerCb.addEventListener('change', function(){
    var col = headerCb.dataset.col;
    document.querySelectorAll('#visForm .col-cb[data-col="' + col + '"]').forEach(function(cb){
      cb.checked = headerCb.checked;
    });
  });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
