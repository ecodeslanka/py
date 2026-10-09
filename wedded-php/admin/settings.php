<?php
require_once __DIR__ . '/includes/auth.php';
require_admin_login();
$pdo = db();

function set_setting($key, $value) {
    db()->prepare("INSERT INTO settings (skey, svalue) VALUES (:k,:v) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")
        ->execute([':k' => $key, ':v' => $value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form = $_POST['form'] ?? '';

    if ($form === 'general') {
        try {
            $logoPath = handle_image_upload('logo', 'site');
        } catch (Exception $e) {
            flash_set($e->getMessage(), 'error'); redirect('settings.php');
        }
        if ($logoPath) {
            $old = setting('logo');
            if ($old) delete_upload($old);
            set_setting('logo', $logoPath);
        }
        set_setting('site_name', trim($_POST['site_name'] ?? 'The Wedded'));
        set_setting('tagline', trim($_POST['tagline'] ?? ''));
        set_setting('logo_link', trim($_POST['logo_link'] ?? '/'));
        set_setting('meta_description', trim($_POST['meta_description'] ?? ''));
        flash_set('General settings saved.');
    }

    elseif ($form === 'contact') {
        set_setting('phone', trim($_POST['phone'] ?? ''));
        set_setting('whatsapp', preg_replace('/[^0-9]/', '', $_POST['whatsapp'] ?? ''));
        set_setting('email', trim($_POST['email'] ?? ''));
        set_setting('address', trim($_POST['address'] ?? ''));
        set_setting('facebook_url', trim($_POST['facebook_url'] ?? ''));
        set_setting('instagram_url', trim($_POST['instagram_url'] ?? ''));
        set_setting('tiktok_url', trim($_POST['tiktok_url'] ?? ''));
        flash_set('Contact details saved.');
    }

    elseif ($form === 'about') {
        try {
            $aboutImg = handle_image_upload('about_image', 'site');
        } catch (Exception $e) {
            flash_set($e->getMessage(), 'error'); redirect('settings.php');
        }
        if ($aboutImg) {
            $old = setting('about_image');
            if ($old) delete_upload($old);
            set_setting('about_image', $aboutImg);
        }
        set_setting('about_title', trim($_POST['about_title'] ?? ''));
        set_setting('about_body', trim($_POST['about_body'] ?? ''));
        set_setting('footer_about', trim($_POST['footer_about'] ?? ''));
        flash_set('About & footer content saved.');
    }

    elseif ($form === 'account') {
        $newUsername = trim($_POST['username'] ?? '');
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE id=:id");
        $stmt->execute([':id' => $_SESSION['admin_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            flash_set('Current password is incorrect.', 'error');
        } elseif ($newUsername === '') {
            flash_set('Username cannot be empty.', 'error');
        } elseif ($newPassword !== '' && $newPassword !== $confirmPassword) {
            flash_set('New password and confirmation do not match.', 'error');
        } elseif ($newPassword !== '' && strlen($newPassword) < 6) {
            flash_set('New password must be at least 6 characters.', 'error');
        } else {
            $hash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_DEFAULT) : $user['password_hash'];
            $pdo->prepare("UPDATE admin_users SET username=:u, password_hash=:p WHERE id=:id")
                ->execute([':u' => $newUsername, ':p' => $hash, ':id' => $user['id']]);
            $_SESSION['admin_username'] = $newUsername;
            flash_set('Account details updated.' . ($newPassword !== '' ? ' Password changed.' : ''));
        }
    }

    redirect('settings.php');
}

$admin_page_title = 'Settings';
include __DIR__ . '/includes/admin_header.php';
?>

<div class="panel">
  <h2>General</h2>
  <p class="panel-sub">Site name, logo (linked in the header and footer), and homepage tagline.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="form" value="general">
    <div class="form-grid">
      <div><label>Site name</label><input type="text" name="site_name" value="<?= h(setting('site_name')) ?>"></div>
      <div><label>Logo links to (URL/path)</label><input type="text" name="logo_link" value="<?= h(setting('logo_link', '/')) ?>"></div>
      <div class="full">
        <label>Logo image</label>
        <input type="file" name="logo" accept="image/*" data-preview="#logoPreview">
        <?php if (setting('logo')): ?>
          <img id="logoPreview" class="thumb" style="width:80px;height:80px;object-fit:contain;background:#111;margin-top:10px" src="<?= h(image_url(setting('logo'))) ?>">
        <?php else: ?>
          <img id="logoPreview" class="thumb" style="width:80px;height:80px;margin-top:10px;display:none">
        <?php endif; ?>
        <p class="help-text">Shown in the site header and footer. Leave empty to display the site name as text.</p>
      </div>
      <div class="full"><label>Homepage tagline (used if no hero slides are set)</label><input type="text" name="tagline" value="<?= h(setting('tagline')) ?>"></div>
      <div class="full"><label>SEO meta description</label><textarea name="meta_description"><?= h(setting('meta_description')) ?></textarea></div>
    </div>
    <div class="btn-row"><button class="btn" type="submit">Save General Settings</button></div>
  </form>
</div>

<div class="panel">
  <h2>Contact Details</h2>
  <p class="panel-sub">Shown in the footer, the floating WhatsApp button, and the Contact page.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="form" value="contact">
    <div class="form-grid">
      <div><label>Phone number</label><input type="text" name="phone" value="<?= h(setting('phone')) ?>" placeholder="+94 77 123 4567"></div>
      <div><label>WhatsApp number (digits only, with country code)</label><input type="text" name="whatsapp" value="<?= h(setting('whatsapp')) ?>" placeholder="94771234567"></div>
      <div><label>Email address</label><input type="email" name="email" value="<?= h(setting('email')) ?>"></div>
      <div><label>Address</label><input type="text" name="address" value="<?= h(setting('address')) ?>"></div>
      <div><label>Facebook URL</label><input type="text" name="facebook_url" value="<?= h(setting('facebook_url')) ?>"></div>
      <div><label>Instagram URL</label><input type="text" name="instagram_url" value="<?= h(setting('instagram_url')) ?>"></div>
      <div><label>TikTok URL</label><input type="text" name="tiktok_url" placeholder="https://www.tiktok.com/@yourhandle" value="<?= h(setting('tiktok_url')) ?>"></div>
    </div>
    <div class="btn-row"><button class="btn" type="submit">Save Contact Details</button></div>
  </form>
</div>

<div class="panel">
  <h2>About &amp; Footer Content</h2>
  <p class="panel-sub">Powers the About page, the homepage About teaser, and the footer blurb.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="form" value="about">
    <div class="form-grid">
      <div class="full"><label>About title</label><input type="text" name="about_title" value="<?= h(setting('about_title')) ?>"></div>
      <div class="full"><label>About body text</label><textarea name="about_body" style="min-height:160px"><?= h(setting('about_body')) ?></textarea></div>
      <div class="full">
        <label>About image</label>
        <input type="file" name="about_image" accept="image/*" data-preview="#aboutPreview">
        <?php if (setting('about_image')): ?>
          <img id="aboutPreview" class="thumb" style="width:120px;height:150px;margin-top:10px" src="<?= h(image_url(setting('about_image'))) ?>">
        <?php else: ?>
          <img id="aboutPreview" class="thumb" style="width:120px;height:150px;margin-top:10px;display:none">
        <?php endif; ?>
      </div>
      <div class="full"><label>Footer blurb</label><textarea name="footer_about"><?= h(setting('footer_about')) ?></textarea></div>
    </div>
    <div class="btn-row"><button class="btn" type="submit">Save About Content</button></div>
  </form>
</div>

<div class="panel">
  <h2>Admin Account</h2>
  <p class="panel-sub">Change your login username and/or password. Current password is always required.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="form" value="account">
    <div class="form-grid">
      <div><label>Username</label><input type="text" name="username" value="<?= h(admin_username()) ?>" required></div>
      <div><label>Current password</label><input type="password" name="current_password" required></div>
      <div><label>New password (leave empty to keep current)</label><input type="password" name="new_password"></div>
      <div><label>Confirm new password</label><input type="password" name="confirm_password"></div>
    </div>
    <div class="btn-row"><button class="btn" type="submit">Update Account</button></div>
  </form>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
