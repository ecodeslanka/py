<?php
/**
 * admin/index.php — Control Panel Dashboard
 */
require_once __DIR__ . '/includes/auth.php';

$counts = db()->query(
    "SELECT
        SUM(level = 1) AS main_cats,
        SUM(level = 2) AS sub_cats,
        SUM(level = 3) AS sub_sub,
        SUM(is_active = 0) AS hidden
     FROM categories"
)->fetch();

$prodCounts = db()->query(
    "SELECT COUNT(*) AS total, SUM(is_active = 0) AS hidden FROM products"
)->fetch();
$varCounts = db()->query(
    "SELECT COUNT(*) AS total FROM variation_option_values"
)->fetch();

$recent = db()->query(
    'SELECT c.*, p.name AS parent_name
     FROM categories c
     LEFT JOIN categories p ON p.id = c.parent_id
     ORDER BY c.created_at DESC, c.id DESC LIMIT 8'
)->fetchAll();

$pageTitle = 'Dashboard';
$active = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<div class="stats">
  <div class="stat"><div class="ic">🗂️</div><div><b><?= (int)$counts['main_cats'] ?></b><span>Main Categories</span></div></div>
  <div class="stat"><div class="ic">📁</div><div><b><?= (int)$counts['sub_cats'] ?></b><span>Sub Categories</span></div></div>
  <div class="stat"><div class="ic">📄</div><div><b><?= (int)$counts['sub_sub'] ?></b><span>Sub-Sub Categories</span></div></div>
  <div class="stat"><div class="ic">🙈</div><div><b><?= (int)$counts['hidden'] ?></b><span>Hidden Items</span></div></div>
  <div class="stat"><div class="ic">📦</div><div><b><?= (int)$prodCounts['total'] ?></b><span>Products</span></div></div>
  <div class="stat"><div class="ic">🎛️</div><div><b><?= (int)$varCounts['total'] ?></b><span>Variation Values</span></div></div>
</div>

<div class="panel">
  <div class="panel-head">
    <h3>Quick Actions</h3>
  </div>
  <div class="panel-body" style="display:flex;gap:12px;flex-wrap:wrap">
    <a class="btn-add" href="<?= BASE_URL ?>/admin/categories">+ Manage Categories</a>
    <a class="btn-add" href="<?= BASE_URL ?>/admin/products">+ New Product</a>
    <a class="btn-add" href="<?= BASE_URL ?>/admin/variations">+ New Variation Option</a>
    <a class="btn-add" style="background:var(--red-950)" href="<?= BASE_URL ?>/" target="_blank">🛍️ View Store</a>
  </div>
</div>

<div class="panel">
  <div class="panel-head"><h3>Recently Added</h3></div>
  <table>
    <thead>
      <tr><th>#</th><th>Name</th><th>Level</th><th>Parent</th><th>Status</th><th>Created</th></tr>
    </thead>
    <tbody>
      <?php foreach ($recent as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= $r['icon'] ? e($r['icon']) . ' ' : '' ?><b><?= e($r['name']) ?></b></td>
        <td><span class="tag tag-lvl">Level <?= (int)$r['level'] ?></span></td>
        <td class="crumb"><?= $r['parent_name'] ? e($r['parent_name']) : '—' ?></td>
        <td><span class="tag <?= $r['is_active'] ? 'tag-on' : 'tag-off' ?>"><?= $r['is_active'] ? 'Active' : 'Hidden' ?></span></td>
        <td class="crumb"><?= e($r['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$recent): ?>
      <tr><td colspan="6" style="text-align:center;color:var(--muted)">No categories yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
