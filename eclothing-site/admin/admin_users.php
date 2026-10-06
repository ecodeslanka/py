<?php
/**
 * admin/admin_users.php — Admin Users
 * Add/edit/delete control-panel accounts (username, email, full name,
 * password), plus two related security settings:
 *   - Login Security: require an emailed OTP code as a second login step
 *     (off by default).
 *   - Admin Panel URL: an additional custom URL the panel is also reachable
 *     at, alongside the default /admin (see update_admin_htaccess_alias()
 *     in includes/functions.php for exactly what this does and doesn't do).
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Admin Users';
$active    = 'admin_users';

$formError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_admin') {
        $id       = (int)($_POST['id'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $err = '';
        if ($username === '' || !preg_match('/^[a-zA-Z0-9_.\-]{3,50}$/', $username)) {
            $err = 'Username must be 3–50 characters (letters, numbers, . _ - only).';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Please enter a valid email address.';
        } elseif (!$id && mb_strlen($password) < 8) {
            $err = 'Password must be at least 8 characters.';
        } elseif ($password !== '' && mb_strlen($password) < 8) {
            $err = 'New password must be at least 8 characters (leave blank to keep the current one).';
        }

        if ($err === '') {
            $dupe = db()->prepare('SELECT id FROM admins WHERE (username = ? OR email = ?) AND id != ?');
            $dupe->execute([$username, $email, $id]);
            if ($dupe->fetch()) { $err = 'That username or email is already used by another admin account.'; }
        }

        // Never let the current admin deactivate or lock themselves out entirely.
        if ($err === '' && $id === (int)$_SESSION['admin_id'] && !$isActive) {
            $err = "You can't deactivate your own account while logged in as it.";
        }

        if ($err === '') {
            try {
                if ($id) {
                    if ($password !== '') {
                        db()->prepare('UPDATE admins SET username=?, email=?, full_name=?, password_hash=?, is_active=? WHERE id=?')
                            ->execute([$username, $email, $fullName ?: null, password_hash($password, PASSWORD_DEFAULT), $isActive, $id]);
                    } else {
                        db()->prepare('UPDATE admins SET username=?, email=?, full_name=?, is_active=? WHERE id=?')
                            ->execute([$username, $email, $fullName ?: null, $isActive, $id]);
                    }
                    flash('ok', 'Admin user updated.');
                } else {
                    db()->prepare('INSERT INTO admins (username, email, full_name, password_hash, is_active) VALUES (?,?,?,?,?)')
                        ->execute([$username, $email, $fullName ?: null, password_hash($password, PASSWORD_DEFAULT), $isActive]);
                    flash('ok', 'Admin user added.');
                }
                redirect('/admin/admin_users');
            } catch (\Throwable $e) {
                error_log('Saving admin user failed: ' . $e->getMessage());
                $err = 'Could not save this admin user — ' . $e->getMessage();
            }
        }
        if ($err !== '') { $formError = $err; }
    }

    if ($action === 'delete_admin') {
        $id = (int)($_POST['id'] ?? 0);
        $totalAdmins = (int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();

        if ($id === (int)$_SESSION['admin_id']) {
            flash('err', "You can't delete your own account while logged in as it.");
        } elseif ($totalAdmins <= 1) {
            flash('err', 'You must keep at least one admin account.');
        } else {
            db()->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]);
            flash('ok', 'Admin user deleted.');
        }
        redirect('/admin/admin_users');
    }

    if ($action === 'save_security') {
        $otpEnabled = isset($_POST['admin_otp_enabled']) ? 1 : 0;
        get_site_settings(); // ensure table + columns exist
        db()->prepare('UPDATE site_settings SET admin_otp_enabled = ? WHERE id = 1')->execute([$otpEnabled]);
        flash('ok', $otpEnabled ? 'Login OTP enabled — admins will now be emailed a code at login.' : 'Login OTP disabled.');
        redirect('/admin/admin_users');
    }

    if ($action === 'save_admin_url') {
        $customPath = trim((string)($_POST['admin_url_path'] ?? 'admin'));
        $result = update_admin_htaccess_alias($customPath);
        if ($result['ok']) {
            get_site_settings();
            db()->prepare('UPDATE site_settings SET admin_url_path = ? WHERE id = 1')
                ->execute([$customPath === '' ? 'admin' : strtolower(trim($customPath, '/'))]);
            flash('ok', $result['message']);
        } else {
            flash('err', $result['message']);
        }
        redirect('/admin/admin_users');
    }
}

$admins       = db()->query('SELECT * FROM admins ORDER BY username ASC')->fetchAll();
$siteSettings = get_site_settings();

require __DIR__ . '/includes/header.php';
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success">✔ <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error">⚠ <?= e($m) ?></div><?php endif; ?>

<div class="panel">
  <div class="panel-head">
    <h3>Admin Users (<?= count($admins) ?>)</h3>
    <button type="button" class="btn-add" onclick="openAddAdmin()">+ Add Admin</button>
  </div>
  <div class="panel-body" style="padding:0">
    <table>
      <thead><tr><th>Username</th><th>Email</th><th>Full Name</th><th>Status</th><th>Last Login</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($admins as $a): ?>
        <tr>
          <td><b><?= e($a['username']) ?></b><?= (int)$a['id'] === (int)$_SESSION['admin_id'] ? ' <span class="hint">(you)</span>' : '' ?></td>
          <td><?= e($a['email']) ?></td>
          <td><?= e($a['full_name'] ?: '—') ?></td>
          <td><?= $a['is_active'] ? '<span class="verified-badge">Active</span>' : '<span class="hint">Disabled</span>' ?></td>
          <td><?= $a['last_login'] ? e(date('d M Y, h:i A', strtotime($a['last_login']))) : '<span class="hint">Never</span>' ?></td>
          <td>
            <div class="row-actions">
              <button type="button" class="mini-btn edit" onclick='openEditAdmin(<?= htmlspecialchars(json_encode($a), ENT_QUOTES, "UTF-8") ?>)'>Edit</button>
              <?php if ((int)$a['id'] !== (int)$_SESSION['admin_id']): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this admin user?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_admin">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button class="mini-btn del" type="submit">Delete</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel" style="margin-bottom:20px">
  <div class="panel-head"><h3><i class="fa-solid fa-shield-halved"></i> Login Security</h3></div>
  <div class="panel-body">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_security">
      <div class="choice-group" style="margin-bottom:14px">
        <label class="choice-pill">
          <input type="checkbox" name="admin_otp_enabled" <?= !empty($siteSettings['admin_otp_enabled']) ? 'checked' : '' ?>>
          <span>Require an emailed 6-digit code at every admin login</span>
        </label>
      </div>
      <p class="hint" style="margin-bottom:14px">
        Off by default. When on, after entering the correct username/password each admin is emailed a one-time
        code (using the SMTP relay configured in Admin → Email Settings, or the server's mail() function if none
        is set) and must enter it to finish signing in. Requires each admin account to have a valid email address.
      </p>
      <button type="submit" class="btn-add">Save</button>
    </form>
  </div>
</div>

<div class="panel" style="margin-bottom:28px">
  <div class="panel-head"><h3><i class="fa-solid fa-link"></i> Admin Panel URL</h3></div>
  <div class="panel-body">
    <p class="hint" style="margin-bottom:14px">
      By default the control panel is at <code><?= e(BASE_URL) ?>/admin</code>. You can add a custom URL as well —
      the panel becomes reachable at both addresses. <strong>The original /admin URL is always kept working too</strong>,
      so a typo here can never lock you out.
    </p>
    <form method="post" class="form-grid" style="align-items:end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_admin_url">
      <label>Custom Path <span class="hint" style="font-weight:400">(lowercase letters, numbers, hyphens)</span>
        <div style="display:flex;align-items:center;gap:6px">
          <span class="hint"><?= e(BASE_URL) ?>/</span>
          <input type="text" name="admin_url_path" maxlength="50" placeholder="admin" value="<?= e($siteSettings['admin_url_path'] ?? 'admin') ?>">
        </div>
      </label>
      <button type="submit" class="btn-add">Save</button>
    </form>
  </div>
</div>

<!-- ===== MODAL: Add/Edit Admin ===== -->
<div id="adminOverlay" class="modal-overlay" onclick="if(event.target===this) closeAdmin()">
  <div class="modal-box">
    <h3 id="adminModalTitle">Add Admin</h3>
    <?php if ($formError): ?><div class="alert alert-error" style="margin-bottom:14px">⚠ <?= e($formError) ?></div><?php endif; ?>
    <form method="post" class="form-grid" id="adminForm" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_admin">
      <input type="hidden" name="id" id="admin_id" value="<?= (int)($_POST['id'] ?? 0) ?>">
      <label style="grid-column:1/-1">Username
        <input type="text" name="username" id="admin_username" required maxlength="50" placeholder="e.g. jane.doe" value="<?= e($_POST['username'] ?? '') ?>">
      </label>
      <label style="grid-column:1/-1">Email
        <input type="email" name="email" id="admin_email" required maxlength="120" placeholder="jane@example.com" value="<?= e($_POST['email'] ?? '') ?>">
      </label>
      <label style="grid-column:1/-1">Full Name <span class="hint" style="font-weight:400">(optional)</span>
        <input type="text" name="full_name" id="admin_full_name" maxlength="100" value="<?= e($_POST['full_name'] ?? '') ?>">
      </label>
      <label style="grid-column:1/-1">Password <span class="hint" style="font-weight:400" id="admin_password_hint"><?= !empty($_POST['id']) ? '(leave blank to keep the current password)' : '(min 8 characters)' ?></span>
        <input type="password" name="password" id="admin_password" minlength="8" autocomplete="new-password" <?= empty($_POST['id']) ? 'required' : '' ?>>
      </label>
      <div class="choice-group" style="grid-column:1/-1">
        <label class="choice-pill">
          <input type="checkbox" name="is_active" id="admin_is_active" <?= ($_SERVER['REQUEST_METHOD'] !== 'POST' || isset($_POST['is_active'])) ? 'checked' : '' ?>>
          <span>Active (can sign in)</span>
        </label>
      </div>
      <div style="display:flex;gap:10px;grid-column:1/-1">
        <button type="submit" class="btn-add">Save</button>
        <button type="button" class="mini-btn toggle" onclick="closeAdmin()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAddAdmin(){
  document.getElementById('adminModalTitle').textContent = 'Add Admin';
  document.getElementById('adminForm').reset();
  document.getElementById('admin_id').value = '';
  document.getElementById('admin_password').required = true;
  document.getElementById('admin_password_hint').textContent = '(min 8 characters)';
  document.getElementById('admin_is_active').checked = true;
  document.getElementById('adminOverlay').classList.add('show');
}
function openEditAdmin(a){
  document.getElementById('adminModalTitle').textContent = 'Edit Admin';
  document.getElementById('admin_id').value = a.id;
  document.getElementById('admin_username').value = a.username;
  document.getElementById('admin_email').value = a.email;
  document.getElementById('admin_full_name').value = a.full_name || '';
  document.getElementById('admin_password').value = '';
  document.getElementById('admin_password').required = false;
  document.getElementById('admin_password_hint').textContent = '(leave blank to keep the current password)';
  document.getElementById('admin_is_active').checked = !!parseInt(a.is_active, 10);
  document.getElementById('adminOverlay').classList.add('show');
}
function closeAdmin(){ document.getElementById('adminOverlay').classList.remove('show'); }
<?php if ($formError): ?>
document.getElementById('adminOverlay').classList.add('show');
<?php endif; ?>
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
