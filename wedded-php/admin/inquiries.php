<?php
require_once __DIR__ . '/includes/auth.php';
require_admin_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'mark_read') {
        $pdo->prepare("UPDATE inquiries SET is_read=1 WHERE id=:id")->execute([':id' => $id]);
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM inquiries WHERE id=:id")->execute([':id' => $id]);
        flash_set('Inquiry deleted.');
    }
    redirect('inquiries.php');
}

$inquiries = $pdo->query("SELECT * FROM inquiries ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$admin_page_title = 'Inquiries';
include __DIR__ . '/includes/admin_header.php';
?>

<div class="panel">
  <h2>Contact Form Inquiries</h2>
  <p class="panel-sub">Every submission from your public Contact page lands here.</p>
  <?php if ($inquiries): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Name</th><th>Contact</th><th>Date / Location</th><th>Service</th><th>Message</th><th>Received</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($inquiries as $inq): ?>
          <tr>
            <td><?= h($inq['name']) ?><?= $inq['is_read'] ? '' : ' <span class="badge on">New</span>' ?></td>
            <td>
              <a href="mailto:<?= h($inq['email']) ?>"><?= h($inq['email']) ?></a>
              <?php if ($inq['phone']): ?><br><?= h($inq['phone']) ?><?php endif; ?>
            </td>
            <td><?= h($inq['wedding_date'] ?: '—') ?><?= $inq['location'] ? '<br>' . h($inq['location']) : '' ?></td>
            <td><?= h($inq['service'] ?: '—') ?></td>
            <td style="max-width:220px;white-space:normal"><?= h(mb_substr($inq['message'], 0, 140)) ?><?= mb_strlen($inq['message']) > 140 ? '…' : '' ?></td>
            <td><?= h(time_ago($inq['created_at'])) ?></td>
            <td>
              <div class="row-actions">
                <?php if (!$inq['is_read']): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mark_read"><input type="hidden" name="id" value="<?= (int)$inq['id'] ?>">
                  <button class="btn small secondary" type="submit">Mark read</button>
                </form>
                <?php endif; ?>
                <form method="post" data-confirm="Delete this inquiry?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$inq['id'] ?>">
                  <button class="btn small danger" type="submit">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="help-text">No inquiries submitted yet.</p>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
