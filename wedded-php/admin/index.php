<?php
require_once __DIR__ . '/includes/auth.php';
require_admin_login();

$pdo = db();
$stats = [
    'albums'     => (int)$pdo->query("SELECT COUNT(*) FROM albums")->fetchColumn(),
    'images'     => (int)$pdo->query("SELECT COUNT(*) FROM album_images")->fetchColumn(),
    'categories' => (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn(),
    'inquiries'  => (int)$pdo->query("SELECT COUNT(*) FROM inquiries")->fetchColumn(),
];
$recentInquiries = $pdo->query("SELECT * FROM inquiries ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$unread = (int)$pdo->query("SELECT COUNT(*) FROM inquiries WHERE is_read = 0")->fetchColumn();

$admin_page_title = 'Dashboard';
include __DIR__ . '/includes/admin_header.php';
?>

<div class="stat-grid">
  <div class="stat-card"><div class="num"><?= $stats['albums'] ?></div><div class="label">Albums</div></div>
  <div class="stat-card"><div class="num"><?= $stats['images'] ?></div><div class="label">Images</div></div>
  <div class="stat-card"><div class="num"><?= $stats['categories'] ?></div><div class="label">Categories</div></div>
  <div class="stat-card"><div class="num"><?= $stats['inquiries'] ?> <?= $unread ? "<span style='font-size:13px;color:#b3413a'>({$unread} new)</span>" : '' ?></div><div class="label">Inquiries</div></div>
</div>

<div class="panel">
  <h2>Recent Inquiries</h2>
  <p class="panel-sub">The latest messages submitted through your Contact page.</p>
  <?php if ($recentInquiries): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Name</th><th>Contact</th><th>Wedding date</th><th>Service</th><th>Received</th></tr></thead>
        <tbody>
        <?php foreach ($recentInquiries as $inq): ?>
          <tr>
            <td><?= h($inq['name']) ?><?= $inq['is_read'] ? '' : ' <span class="badge on">New</span>' ?></td>
            <td><?= h($inq['email']) ?><?= $inq['phone'] ? '<br>' . h($inq['phone']) : '' ?></td>
            <td><?= h($inq['wedding_date'] ?: '—') ?></td>
            <td><?= h($inq['service'] ?: '—') ?></td>
            <td><?= h(time_ago($inq['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="btn-row"><a class="btn secondary" href="inquiries.php">View all inquiries</a></div>
  <?php else: ?>
    <p class="help-text">No inquiries yet — they'll show up here once someone submits your Contact form.</p>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Quick Links</h2>
  <div class="btn-row">
    <a class="btn secondary" href="hero.php">Manage Hero Slider</a>
    <a class="btn secondary" href="categories.php">Manage Categories</a>
    <a class="btn secondary" href="albums.php">Manage Albums</a>
    <a class="btn secondary" href="settings.php">Site Settings</a>
  </div>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
