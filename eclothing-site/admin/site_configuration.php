<?php
/**
 * admin/site_configuration.php — Site Configuration
 * One page to manage: site/footer logo, company name & address,
 * contact numbers (many), emails (many), social links, branches (many),
 * and payment gateway toggles (Koko / Mintpay / Payzy).
 *
 * Self-contained: creates/migrates its own tables on first load.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Site Configuration';
$active    = 'site_configuration';

/* ============================================================
   AUTO-MIGRATE: create tables on first load if they don't exist
   ============================================================ */
db()->exec("
CREATE TABLE IF NOT EXISTS site_settings (
  id             TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  site_logo      VARCHAR(255) DEFAULT NULL,
  footer_logo    VARCHAR(255) DEFAULT NULL,
  company_name   VARCHAR(150) NOT NULL DEFAULT '',
  address        TEXT,
  facebook_url   VARCHAR(255) DEFAULT NULL,
  linkedin_url   VARCHAR(255) DEFAULT NULL,
  youtube_url    VARCHAR(255) DEFAULT NULL,
  twitter_url    VARCHAR(255) DEFAULT NULL,
  instagram_url  VARCHAR(255) DEFAULT NULL,
  tiktok_url     VARCHAR(255) DEFAULT NULL,
  whatsapp_number VARCHAR(40) DEFAULT NULL,
  enable_koko    TINYINT(1) NOT NULL DEFAULT 0,
  enable_mintpay TINYINT(1) NOT NULL DEFAULT 0,
  enable_payzy   TINYINT(1) NOT NULL DEFAULT 0,
  show_categories  TINYINT(1) NOT NULL DEFAULT 1,
  show_flash_sale  TINYINT(1) NOT NULL DEFAULT 1,
  show_top_selling TINYINT(1) NOT NULL DEFAULT 1,
  show_brands      TINYINT(1) NOT NULL DEFAULT 1,
  show_happy_customers TINYINT(1) NOT NULL DEFAULT 1,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
db()->exec("INSERT IGNORE INTO site_settings (id, company_name) VALUES (1, 'ECLOTHING')");
foreach (['show_categories', 'show_flash_sale', 'show_top_selling', 'show_brands', 'show_happy_customers'] as $__col) {
    $__exists = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $__exists->execute(['site_settings', $__col]);
    if ((int)$__exists->fetchColumn() === 0) {
        db()->exec("ALTER TABLE site_settings ADD COLUMN $__col TINYINT(1) NOT NULL DEFAULT 1");
    }
}

db()->exec("
CREATE TABLE IF NOT EXISTS site_contact_numbers (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone       VARCHAR(40)  NOT NULL,
  description VARCHAR(150) DEFAULT NULL,
  is_main     TINYINT(1) NOT NULL DEFAULT 0,
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$__mainColExists = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
$__mainColExists->execute(['site_contact_numbers', 'is_main']);
if ((int)$__mainColExists->fetchColumn() === 0) {
    db()->exec('ALTER TABLE site_contact_numbers ADD COLUMN is_main TINYINT(1) NOT NULL DEFAULT 0 AFTER description');
}

db()->exec("
CREATE TABLE IF NOT EXISTS site_emails (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email       VARCHAR(150) NOT NULL,
  description VARCHAR(150) DEFAULT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

db()->exec("
CREATE TABLE IF NOT EXISTS site_branches (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  address     TEXT NOT NULL,
  details     VARCHAR(255) DEFAULT NULL,
  is_main     TINYINT(1) NOT NULL DEFAULT 0,
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ============================================================
   Helpers
   ============================================================ */
/* get_site_settings() now lives in includes/functions.php (shared with the public site). */

/** Handles one <input type=file>; returns [newPathOrOld, errorOrNull]. Deletes the old file on success. */
function process_logo_upload(string $field, ?string $existingPath): array
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return [$existingPath, null];
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return [$existingPath, 'Logo upload failed — please try again.'];
    }
    if ($_FILES[$field]['size'] > 2 * 1024 * 1024) {
        return [$existingPath, 'Logo file is too large (max 2MB).'];
    }
    $allowedExt  = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    $ext = strtolower(pathinfo((string)$_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!isset($allowedExt[$ext])) {
        return [$existingPath, 'Logo must be a JPG, PNG, WEBP or GIF image.'];
    }
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($_FILES[$field]['tmp_name']);
        if ($mime && !in_array($mime, $allowedExt, true)) {
            return [$existingPath, 'That file does not look like a valid image.'];
        }
    }

    $dir = __DIR__ . '/../assets/uploads/site';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "php_flag engine off\n<FilesMatch \"\\.php$\">\nRequire all denied\n</FilesMatch>\n"); }

    $filename = $field . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $filename)) {
        return [$existingPath, 'Could not save the uploaded logo.'];
    }

    if ($existingPath) {
        $old = __DIR__ . '/../' . ltrim($existingPath, '/');
        if (is_file($old)) { @unlink($old); }
    }
    return ['/assets/uploads/site/' . $filename, null];
}

/* ============================================================
   POST actions
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    /* ---------------- General / branding / social / payment gateways ---------------- */
    if ($action === 'save_general') {
        $companyName = trim((string)($_POST['company_name'] ?? ''));
        $address     = trim((string)($_POST['address'] ?? ''));
        $err = '';

        if ($companyName === '' || mb_strlen($companyName) > 150) {
            $err = 'Company name is required (max 150 characters).';
        } elseif ($address === '') {
            $err = 'Address is required.';
        }

        $socialFields = [
            'facebook_url' => 'Facebook', 'linkedin_url' => 'LinkedIn', 'youtube_url' => 'YouTube',
            'twitter_url' => 'X / Twitter', 'instagram_url' => 'Instagram', 'tiktok_url' => 'TikTok',
        ];
        $social = [];
        foreach ($socialFields as $field => $label) {
            $val = trim((string)($_POST[$field] ?? ''));
            if ($val !== '' && !filter_var($val, FILTER_VALIDATE_URL)) {
                $err = $err ?: "Please enter a valid $label URL (or leave it blank).";
            }
            $social[$field] = $val !== '' ? $val : null;
        }

        $whatsappNumber = trim((string)($_POST['whatsapp_number'] ?? ''));
        if ($whatsappNumber !== '' && !preg_match('/^[\d\s+\-()]{6,40}$/', $whatsappNumber)) {
            $err = $err ?: 'Please enter a valid WhatsApp number (digits, spaces, + and - only).';
        }

        $enableKoko    = isset($_POST['enable_koko'])    ? 1 : 0;
        $enableMintpay = isset($_POST['enable_mintpay']) ? 1 : 0;
        $enablePayzy   = isset($_POST['enable_payzy'])   ? 1 : 0;

        $showCategories  = isset($_POST['show_categories'])  ? 1 : 0;
        $showFlashSale   = isset($_POST['show_flash_sale'])  ? 1 : 0;
        $showTopSelling  = isset($_POST['show_top_selling']) ? 1 : 0;
        $showBrands      = isset($_POST['show_brands'])      ? 1 : 0;
        $showHappyCustomers = isset($_POST['show_happy_customers']) ? 1 : 0;

        $homeCategoriesCount = max(1, min(48, (int)($_POST['home_categories_count'] ?? 12)));
        $homeFlashSaleCount  = max(1, min(48, (int)($_POST['home_flash_sale_count'] ?? 5)));
        $homeTopSellingCount = max(1, min(48, (int)($_POST['home_top_selling_count'] ?? 5)));

        $enableGoogleSignin = isset($_POST['enable_google_signin']) ? 1 : 0;
        $googleClientId     = trim((string)($_POST['google_client_id'] ?? ''));
        $googleClientSecret = trim((string)($_POST['google_client_secret'] ?? ''));
        if ($enableGoogleSignin && $googleClientId === '') {
            $err = $err ?: 'Enter your Google Client ID to enable Google Sign-In.';
        }

        $headerLogoWidth = (int)($_POST['header_logo_width'] ?? 0);
        if ($headerLogoWidth < 0) { $headerLogoWidth = 0; }
        if ($headerLogoWidth > 600) { $headerLogoWidth = 600; }

        $siteTitle = trim((string)($_POST['site_title'] ?? ''));
        if (mb_strlen($siteTitle) > 255) { $err = $err ?: 'Website Title must be 255 characters or fewer.'; }

        $footerTagline = trim((string)($_POST['footer_tagline'] ?? ''));
        $footerCopyright = trim((string)($_POST['footer_copyright_text'] ?? ''));
        $footerCreditText = trim((string)($_POST['footer_credit_text'] ?? ''));
        $footerCreditUrl  = trim((string)($_POST['footer_credit_url'] ?? ''));
        if ($footerCreditUrl !== '' && !filter_var($footerCreditUrl, FILTER_VALIDATE_URL)) {
            $err = $err ?: 'Please enter a valid Footer Credit URL (or leave it blank).';
        }
        $removeFavicon = isset($_POST['remove_favicon']) ? 1 : 0;

        $current        = get_site_settings();
        $logoPath       = $current['site_logo'];
        $footerLogoPath = $current['footer_logo'];
        $faviconPath    = $current['favicon'];

        if ($err === '') {
            [$logoPath, $logoErr] = process_logo_upload('site_logo', $current['site_logo']);
            if ($logoErr) $err = $logoErr;
        }
        if ($err === '') {
            [$footerLogoPath, $footErr] = process_logo_upload('footer_logo', $current['footer_logo']);
            if ($footErr) $err = $footErr;
        }
        if ($err === '' && $removeFavicon && empty($_FILES['favicon']['name'])) {
            if ($faviconPath) {
                $old = __DIR__ . '/../' . ltrim($faviconPath, '/');
                if (is_file($old)) { @unlink($old); }
            }
            $faviconPath = null;
        }
        if ($err === '') {
            [$faviconPath, $favErr] = process_logo_upload('favicon', $faviconPath);
            if ($favErr) $err = $favErr;
        }

        if ($err === '') {
            db()->prepare('UPDATE site_settings SET
                site_logo = ?, footer_logo = ?, favicon = ?, company_name = ?, address = ?,
                site_title = ?,
                footer_tagline = ?, footer_copyright_text = ?, footer_credit_text = ?, footer_credit_url = ?,
                facebook_url = ?, linkedin_url = ?, youtube_url = ?, twitter_url = ?, instagram_url = ?, tiktok_url = ?,
                whatsapp_number = ?,
                enable_koko = ?, enable_mintpay = ?, enable_payzy = ?,
                show_categories = ?, show_flash_sale = ?, show_top_selling = ?, show_brands = ?, show_happy_customers = ?,
                home_categories_count = ?, home_flash_sale_count = ?, home_top_selling_count = ?,
                enable_google_signin = ?, google_client_id = ?, google_client_secret = ?,
                header_logo_width = ?
                WHERE id = 1')
               ->execute([
                   $logoPath, $footerLogoPath, $faviconPath, $companyName, $address,
                   $siteTitle ?: null,
                   $footerTagline ?: null, $footerCopyright ?: null,
                   $footerCreditText !== '' ? $footerCreditText : 'Powered by ECODES IT SOLUTIONS',
                   $footerCreditUrl !== '' ? $footerCreditUrl : ($footerCreditText !== '' ? '' : 'https://ecodes.lk'),
                   $social['facebook_url'], $social['linkedin_url'], $social['youtube_url'],
                   $social['twitter_url'], $social['instagram_url'], $social['tiktok_url'],
                   $whatsappNumber ?: null,
                   $enableKoko, $enableMintpay, $enablePayzy,
                   $showCategories, $showFlashSale, $showTopSelling, $showBrands, $showHappyCustomers,
                   $homeCategoriesCount, $homeFlashSaleCount, $homeTopSellingCount,
                   $enableGoogleSignin, $googleClientId ?: null, $googleClientSecret ?: null,
                   $headerLogoWidth,
               ]);
            flash('ok', 'Site configuration saved successfully.');
        } else {
            flash('err', $err);
        }
        redirect('/admin/site_configuration');
    }

    /* ---------------- Contact numbers ---------------- */
    if ($action === 'add_contact' || $action === 'update_contact') {
        $id     = (int)($_POST['id'] ?? 0);
        $phone  = trim((string)($_POST['phone'] ?? ''));
        $desc   = trim((string)($_POST['description'] ?? ''));
        $isMain = isset($_POST['is_main']) ? 1 : 0;
        $sort   = (int)($_POST['sort_order'] ?? 0);

        if ($phone === '' || mb_strlen($phone) > 40) {
            flash('err', 'A valid phone number is required (max 40 characters).');
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            if ($isMain) {
                $pdo->exec('UPDATE site_contact_numbers SET is_main = 0');
            }
            if ($action === 'add_contact') {
                $pdo->prepare('INSERT INTO site_contact_numbers (phone, description, is_main, sort_order) VALUES (?, ?, ?, ?)')
                    ->execute([$phone, $desc !== '' ? $desc : null, $isMain, $sort]);
                $msg = 'Contact number added.';
            } else {
                $pdo->prepare('UPDATE site_contact_numbers SET phone = ?, description = ?, is_main = ?, sort_order = ? WHERE id = ?')
                    ->execute([$phone, $desc !== '' ? $desc : null, $isMain, $sort, $id]);
                $msg = 'Contact number updated.';
            }
            $pdo->commit();
            flash('ok', $msg);
        }
        redirect('/admin/site_configuration');
    }

    if ($action === 'delete_contact') {
        db()->prepare('DELETE FROM site_contact_numbers WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('ok', 'Contact number removed.');
        redirect('/admin/site_configuration');
    }

    /* ---------------- Emails ---------------- */
    if ($action === 'add_email' || $action === 'update_email') {
        $id    = (int)($_POST['id'] ?? 0);
        $email = trim((string)($_POST['email'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? ''));
        $sort  = (int)($_POST['sort_order'] ?? 0);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('err', 'A valid email address is required.');
        } elseif ($action === 'add_email') {
            db()->prepare('INSERT INTO site_emails (email, description, sort_order) VALUES (?, ?, ?)')
               ->execute([$email, $desc !== '' ? $desc : null, $sort]);
            flash('ok', 'Email address added.');
        } else {
            db()->prepare('UPDATE site_emails SET email = ?, description = ?, sort_order = ? WHERE id = ?')
               ->execute([$email, $desc !== '' ? $desc : null, $sort, $id]);
            flash('ok', 'Email address updated.');
        }
        redirect('/admin/site_configuration');
    }

    if ($action === 'delete_email') {
        db()->prepare('DELETE FROM site_emails WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('ok', 'Email address removed.');
        redirect('/admin/site_configuration');
    }

    /* ---------------- Branches ---------------- */
    if ($action === 'add_branch' || $action === 'update_branch') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim((string)($_POST['name'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $details = trim((string)($_POST['details'] ?? ''));
        $isMain  = isset($_POST['is_main']) ? 1 : 0;
        $sort    = (int)($_POST['sort_order'] ?? 0);

        if ($name === '' || mb_strlen($name) > 150) {
            flash('err', 'Branch / location name is required (max 150 characters).');
        } elseif ($address === '') {
            flash('err', 'Branch address is required.');
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            if ($isMain) {
                $pdo->exec('UPDATE site_branches SET is_main = 0');
            }
            if ($action === 'add_branch') {
                $pdo->prepare('INSERT INTO site_branches (name, address, details, is_main, sort_order) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$name, $address, $details !== '' ? $details : null, $isMain, $sort]);
                $msg = 'Branch added.';
            } else {
                $pdo->prepare('UPDATE site_branches SET name = ?, address = ?, details = ?, is_main = ?, sort_order = ? WHERE id = ?')
                    ->execute([$name, $address, $details !== '' ? $details : null, $isMain, $sort, $id]);
                $msg = 'Branch updated.';
            }
            $pdo->commit();
            flash('ok', $msg);
        }
        redirect('/admin/site_configuration');
    }

    if ($action === 'delete_branch') {
        db()->prepare('DELETE FROM site_branches WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('ok', 'Branch removed.');
        redirect('/admin/site_configuration');
    }
}

/* ============================================================
   Data for the page
   ============================================================ */
$settings = get_site_settings();
$contacts = db()->query('SELECT * FROM site_contact_numbers ORDER BY is_main DESC, sort_order ASC, id ASC')->fetchAll();
$emails   = db()->query('SELECT * FROM site_emails ORDER BY sort_order ASC, id ASC')->fetchAll();
$branches = db()->query('SELECT * FROM site_branches ORDER BY is_main DESC, sort_order ASC, id ASC')->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success">✔ <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error">⚠ <?= e($m) ?></div><?php endif; ?>

<!-- ===== GENERAL / BRANDING / SOCIAL / PAYMENT — ONE FORM ===== -->
<form method="post" enctype="multipart/form-data" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_general">

  <div class="panel">
    <div class="panel-head"><h3>Branding &amp; Company Info</h3></div>
    <div class="panel-body">
      <div class="logo-grid">
        <div class="logo-upload">
          <label class="lu-label">Site Logo (header)</label>
          <div class="logo-preview">
            <?php if (!empty($settings['site_logo'])): ?>
              <img src="<?= BASE_URL . e($settings['site_logo']) ?>" alt="Site logo">
            <?php else: ?>
              <span class="ph">🖼 No logo uploaded</span>
            <?php endif; ?>
          </div>
          <input type="file" name="site_logo" accept=".jpg,.jpeg,.png,.webp,.gif">
          <p class="hint">JPG, PNG, WEBP or GIF — max 2MB. Leave blank to keep the current logo.</p>
          <label class="lu-label" style="margin-top:14px">Header Logo Width
            <input type="number" name="header_logo_width" min="0" max="600" step="1"
                   value="<?= (int)($settings['header_logo_width'] ?? 0) ?>"
                   placeholder="e.g. 180" style="max-width:140px">
          </label>
          <p class="hint">Width in pixels for the logo shown in the site header. Leave at 0 to use the theme's default size.</p>
        </div>
        <div class="logo-upload">
          <label class="lu-label">Footer Logo</label>
          <div class="logo-preview dark">
            <?php if (!empty($settings['footer_logo'])): ?>
              <img src="<?= BASE_URL . e($settings['footer_logo']) ?>" alt="Footer logo">
            <?php else: ?>
              <span class="ph">🖼 No logo uploaded</span>
            <?php endif; ?>
          </div>
          <input type="file" name="footer_logo" accept=".jpg,.jpeg,.png,.webp,.gif">
          <p class="hint">Shown on the dark footer background — max 2MB.</p>
        </div>
        <div class="logo-upload">
          <label class="lu-label">Favicon (browser tab icon)</label>
          <div class="logo-preview">
            <?php if (!empty($settings['favicon'])): ?>
              <img src="<?= BASE_URL . e($settings['favicon']) ?>" alt="Favicon">
            <?php elseif (!empty($settings['site_logo'])): ?>
              <img src="<?= BASE_URL . e($settings['site_logo']) ?>" alt="Using site logo as favicon">
            <?php else: ?>
              <span class="ph">🖼 No favicon uploaded</span>
            <?php endif; ?>
          </div>
          <input type="file" name="favicon" accept=".jpg,.jpeg,.png,.webp,.gif,.ico">
          <?php if (!empty($settings['favicon'])): ?>
          <label style="display:flex;align-items:center;gap:8px;margin-top:8px;font-weight:400">
            <input type="checkbox" name="remove_favicon" style="width:auto;margin:0"> Remove custom favicon
          </label>
          <?php endif; ?>
          <p class="hint">Small square image (ideally 32×32 or 512×512px) — max 2MB. <?php if (empty($settings['favicon'])): ?>Not set — the <b>Site Logo</b> above is being used as the favicon by default.<?php endif; ?></p>
        </div>
      </div>

      <div class="form-grid" style="margin-top:22px">
        <label style="grid-column:1/-1">Company Name
          <input type="text" name="company_name" required maxlength="150" value="<?= e($settings['company_name']) ?>">
        </label>
        <label style="grid-column:1/-1">Address
          <textarea name="address" rows="3" required placeholder="No. 250 Main Street, Colombo 03, Sri Lanka."><?= e($settings['address']) ?></textarea>
        </label>
        <label style="grid-column:1/-1">Website Title (browser tab / SEO title)
          <input type="text" name="site_title" maxlength="255" value="<?= e($settings['site_title'] ?? '') ?>" placeholder="<?= e(site_title_text()) ?>">
        </label>
        <p class="hint" style="grid-column:1/-1;margin-top:-8px">Shown in the browser tab and search results. Leave blank to use the default shown as the placeholder above.</p>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Footer Text</h3></div>
    <div class="panel-body">
      <div class="form-grid">
        <label style="grid-column:1/-1">Footer Tagline <span style="font-weight:400;color:var(--muted)">(line under the footer logo)</span>
          <input type="text" name="footer_tagline" maxlength="255" value="<?= e($settings['footer_tagline'] ?? '') ?>" placeholder="<?= e(footer_tagline_text()) ?>">
        </label>
        <label style="grid-column:1/-1">Footer Copyright Text <span style="font-weight:400;color:var(--muted)">(bottom bar — use {year} and {company} as placeholders)</span>
          <input type="text" name="footer_copyright_text" maxlength="255" value="<?= e($settings['footer_copyright_text'] ?? '') ?>" placeholder="© {year} {company} — Designed For Your Comfort. All rights reserved.">
        </label>
        <label>Footer Credit Text <span style="font-weight:400;color:var(--muted)">(small line at the very bottom)</span>
          <input type="text" name="footer_credit_text" maxlength="255" value="<?= e($settings['footer_credit_text'] ?? 'Powered by ECODES IT SOLUTIONS') ?>">
        </label>
        <label>Footer Credit Link
          <input type="url" name="footer_credit_url" maxlength="255" value="<?= e($settings['footer_credit_url'] ?? 'https://ecodes.lk') ?>" placeholder="https://ecodes.lk">
        </label>
        <p class="hint" style="grid-column:1/-1">Clear the Footer Credit Text and save to remove this line from the footer entirely.</p>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Social Media Links</h3></div>
    <div class="panel-body">
      <div class="form-grid">
        <label>📘 Facebook
          <input type="url" name="facebook_url" placeholder="https://facebook.com/yourpage" value="<?= e($settings['facebook_url'] ?? '') ?>">
        </label>
        <label>💼 LinkedIn
          <input type="url" name="linkedin_url" placeholder="https://linkedin.com/company/yourco" value="<?= e($settings['linkedin_url'] ?? '') ?>">
        </label>
        <label>▶️ YouTube
          <input type="url" name="youtube_url" placeholder="https://youtube.com/@yourchannel" value="<?= e($settings['youtube_url'] ?? '') ?>">
        </label>
        <label>✕ X (Twitter)
          <input type="url" name="twitter_url" placeholder="https://x.com/yourhandle" value="<?= e($settings['twitter_url'] ?? '') ?>">
        </label>
        <label>📷 Instagram
          <input type="url" name="instagram_url" placeholder="https://instagram.com/yourhandle" value="<?= e($settings['instagram_url'] ?? '') ?>">
        </label>
        <label>🎵 TikTok
          <input type="url" name="tiktok_url" placeholder="https://tiktok.com/@yourhandle" value="<?= e($settings['tiktok_url'] ?? '') ?>">
        </label>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>WhatsApp Contact</h3></div>
    <div class="panel-body">
      <div class="form-grid">
        <label>💬 WhatsApp Number
          <input type="text" name="whatsapp_number" maxlength="40" placeholder="e.g. 0771234567 or +94771234567" value="<?= e($settings['whatsapp_number'] ?? '') ?>">
        </label>
      </div>
      <p class="hint" style="margin-top:10px">Used for the "Chat on WhatsApp" button in the site header and the per-item WhatsApp buttons on the homepage category boxes. Leave blank to use the Main Contact Number instead.</p>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Payment Gateways</h3></div>
    <div class="panel-body">
      <div class="gateway-grid">
        <label class="gateway-card <?= $settings['enable_koko'] ? 'on' : '' ?>">
          <input type="checkbox" name="enable_koko" onchange="this.closest('.gateway-card').classList.toggle('on',this.checked)" <?= $settings['enable_koko'] ? 'checked' : '' ?>>
          <span class="gw-ic">🅺</span>
          <span class="gw-name">Koko</span>
          <span class="gw-state"><?= $settings['enable_koko'] ? 'Enabled' : 'Disabled' ?></span>
        </label>
        <label class="gateway-card <?= $settings['enable_mintpay'] ? 'on' : '' ?>">
          <input type="checkbox" name="enable_mintpay" onchange="this.closest('.gateway-card').classList.toggle('on',this.checked)" <?= $settings['enable_mintpay'] ? 'checked' : '' ?>>
          <span class="gw-ic">🌿</span>
          <span class="gw-name">Mintpay</span>
          <span class="gw-state"><?= $settings['enable_mintpay'] ? 'Enabled' : 'Disabled' ?></span>
        </label>
        <label class="gateway-card <?= $settings['enable_payzy'] ? 'on' : '' ?>">
          <input type="checkbox" name="enable_payzy" onchange="this.closest('.gateway-card').classList.toggle('on',this.checked)" <?= $settings['enable_payzy'] ? 'checked' : '' ?>>
          <span class="gw-ic">💳</span>
          <span class="gw-name">Payzy</span>
          <span class="gw-state"><?= $settings['enable_payzy'] ? 'Enabled' : 'Disabled' ?></span>
        </label>
      </div>
      <p class="hint" style="margin-top:12px">These badges just control which payment-method logos are shown on product pages. To actually accept KOKO payments at checkout (API keys, signing, callbacks), configure it under <a href="<?= BASE_URL ?>/admin/payment_settings">Admin → Payment Settings</a>.</p>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Google Sign-In</h3></div>
    <div class="panel-body">
      <p class="hint" style="margin-bottom:14px">Lets customers sign up / sign in on the Sign Up and Login pages using their Google account. Create an OAuth Client ID at <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console → Credentials</a> (type: Web application) and add this site's URL to "Authorized JavaScript origins".</p>
      <div class="choice-group" style="margin-bottom:16px">
        <label class="choice-pill">
          <input type="checkbox" name="enable_google_signin" <?= !empty($settings['enable_google_signin']) ? 'checked' : '' ?>>
          <span>🔵 Enable "Sign in with Google"</span>
        </label>
      </div>
      <div class="form-grid">
        <label>Google Client ID
          <input type="text" name="google_client_id" placeholder="xxxxxxxxxx-xxxxxxxxxxxxxxxx.apps.googleusercontent.com" value="<?= e($settings['google_client_id'] ?? '') ?>">
        </label>
        <label>Google Client Secret
          <input type="text" name="google_client_secret" placeholder="Only needed for server-side flows" value="<?= e($settings['google_client_secret'] ?? '') ?>">
        </label>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Homepage Sections</h3></div>
    <div class="panel-body">
      <p class="hint" style="margin-bottom:14px">Turn homepage sections on or off, and control how many items/tiles each one shows. Categories, Flash Sale, Top Selling and Brands all pull live data — this just controls whether (and how much of) each section is shown.</p>
      <div class="choice-group">
        <label class="choice-pill">
          <input type="checkbox" name="show_categories" <?= $settings['show_categories'] ? 'checked' : '' ?>>
          <span>🗂️ Shop by Category</span>
        </label>
        <label class="choice-pill">
          <input type="checkbox" name="show_flash_sale" <?= $settings['show_flash_sale'] ? 'checked' : '' ?>>
          <span>⚡ Flash Sale</span>
        </label>
        <label class="choice-pill">
          <input type="checkbox" name="show_top_selling" <?= $settings['show_top_selling'] ? 'checked' : '' ?>>
          <span>⭐ Top Selling</span>
        </label>
        <label class="choice-pill">
          <input type="checkbox" name="show_brands" <?= $settings['show_brands'] ? 'checked' : '' ?>>
          <span>🏷️ Featured Brands</span>
        </label>
        <label class="choice-pill">
          <input type="checkbox" name="show_happy_customers" <?= $settings['show_happy_customers'] ? 'checked' : '' ?>>
          <span>😊 Happy Customers</span>
        </label>
      </div>

      <div class="form-grid" style="margin-top:20px">
        <label>🗂️ Categories to show
          <input type="number" name="home_categories_count" min="1" max="48" step="1" value="<?= (int)($settings['home_categories_count'] ?? 12) ?>" style="max-width:140px">
        </label>
        <label>⚡ Flash Sale items to show
          <input type="number" name="home_flash_sale_count" min="1" max="48" step="1" value="<?= (int)($settings['home_flash_sale_count'] ?? 5) ?>" style="max-width:140px">
        </label>
        <label>⭐ Top Selling items to show
          <input type="number" name="home_top_selling_count" min="1" max="48" step="1" value="<?= (int)($settings['home_top_selling_count'] ?? 5) ?>" style="max-width:140px">
        </label>
      </div>
      <p class="hint" style="margin-top:10px">These only control how many tiles/products appear on the <b>homepage</b> — the full catalogue is always still browsable on the All Products / category pages.</p>
    </div>
  </div>

  <button type="submit" class="btn-add" style="margin-bottom:28px">💾 Save Site Configuration</button>
</form>

<!-- ===== CONTACT NUMBERS ===== -->
<div class="panel">
  <div class="panel-head">
    <h3>Contact Numbers (<?= count($contacts) ?>)</h3>
    <button type="button" class="btn-add" onclick="openAddContact()">+ Add Number</button>
  </div>
  <div class="panel-body" style="padding:0">
    <table>
      <thead><tr><th>Phone</th><th>Description</th><th>Status</th><th>Sort</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($contacts as $c): ?>
        <tr>
          <td><b><?= e($c['phone']) ?></b></td>
          <td><?= $c['description'] ? e($c['description']) : '<span class="crumb">—</span>' ?></td>
          <td><?= $c['is_main'] ? '<span class="tag tag-on">Main · shown in header &amp; footer</span>' : '<span class="tag tag-lvl">—</span>' ?></td>
          <td><?= (int)$c['sort_order'] ?></td>
          <td>
            <div class="row-actions">
              <button type="button" class="mini-btn edit" onclick='openEditContact(<?= htmlspecialchars(json_encode($c), ENT_QUOTES, "UTF-8") ?>)'>Edit</button>
              <form method="post" style="display:inline" onsubmit="return confirm('Remove this contact number?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_contact">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="mini-btn del" type="submit">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$contacts): ?>
        <tr><td colspan="5" style="color:var(--muted)">No contact numbers yet — add one above.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== EMAILS ===== -->
<div class="panel">
  <div class="panel-head">
    <h3>Email Addresses (<?= count($emails) ?>)</h3>
    <button type="button" class="btn-add" onclick="openAddEmail()">+ Add Email</button>
  </div>
  <div class="panel-body" style="padding:0">
    <table>
      <thead><tr><th>Email</th><th>Description</th><th>Sort</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($emails as $em): ?>
        <tr>
          <td><b><?= e($em['email']) ?></b></td>
          <td><?= $em['description'] ? e($em['description']) : '<span class="crumb">—</span>' ?></td>
          <td><?= (int)$em['sort_order'] ?></td>
          <td>
            <div class="row-actions">
              <button type="button" class="mini-btn edit" onclick='openEditEmail(<?= htmlspecialchars(json_encode($em), ENT_QUOTES, "UTF-8") ?>)'>Edit</button>
              <form method="post" style="display:inline" onsubmit="return confirm('Remove this email address?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_email">
                <input type="hidden" name="id" value="<?= (int)$em['id'] ?>">
                <button class="mini-btn del" type="submit">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$emails): ?>
        <tr><td colspan="4" style="color:var(--muted)">No email addresses yet — add one above.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== BRANCHES ===== -->
<div class="panel">
  <div class="panel-head">
    <h3>Branches (<?= count($branches) ?>)</h3>
    <button type="button" class="btn-add" onclick="openAddBranch()">+ Add Branch</button>
  </div>
  <div class="panel-body" style="padding:0">
    <table>
      <thead><tr><th>Location</th><th>Address</th><th>Details</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($branches as $b): ?>
        <tr>
          <td><b><?= e($b['name']) ?></b></td>
          <td><?= nl2br(e($b['address'])) ?></td>
          <td><?= $b['details'] ? e($b['details']) : '<span class="crumb">—</span>' ?></td>
          <td><?= $b['is_main'] ? '<span class="tag tag-on">Main Location</span>' : '<span class="tag tag-lvl">Branch</span>' ?></td>
          <td>
            <div class="row-actions">
              <button type="button" class="mini-btn edit" onclick='openEditBranch(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>Edit</button>
              <form method="post" style="display:inline" onsubmit="return confirm('Remove this branch?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_branch">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button class="mini-btn del" type="submit">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$branches): ?>
        <tr><td colspan="5" style="color:var(--muted)">No branches yet — add one above.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== MODAL: Add/Edit Contact ===== -->
<div id="contactOverlay" class="modal-overlay" onclick="if(event.target===this) closeContact()">
  <div class="modal-box">
    <h3 id="contactTitle">Add Contact Number</h3>
    <form method="post" class="form-grid" id="contactForm" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="contact_action" value="add_contact">
      <input type="hidden" name="id" id="contact_id">
      <label>Phone Number
        <input type="text" name="phone" id="contact_phone" required maxlength="40" placeholder="+94 112 345 678">
      </label>
      <label>Description (optional)
        <input type="text" name="description" id="contact_description" maxlength="150" placeholder="e.g. Hotline, Sales, WhatsApp">
      </label>
      <label style="display:flex;align-items:center;gap:8px;grid-column:1/-1">
        <input type="checkbox" name="is_main" id="contact_is_main" style="width:auto;margin:0">
        Set as Main Contact Number
      </label>
      <label>Sort Order
        <input type="number" name="sort_order" id="contact_sort_order" value="0" min="0" max="9999">
      </label>
      <div style="display:flex;gap:10px;grid-column:1/-1">
        <button type="submit" class="btn-add">Save</button>
        <button type="button" class="mini-btn toggle" onclick="closeContact()">Cancel</button>
      </div>
    </form>
    <p style="margin-top:14px;color:var(--muted);font-size:13px">The Main Contact Number is shown as the hotline in the site header and next to the address in the footer. Only one number can be main — setting this one will unset any other.</p>
  </div>
</div>

<!-- ===== MODAL: Add/Edit Email ===== -->
<div id="emailOverlay" class="modal-overlay" onclick="if(event.target===this) closeEmail()">
  <div class="modal-box">
    <h3 id="emailTitle">Add Email Address</h3>
    <form method="post" class="form-grid" id="emailForm" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="email_action" value="add_email">
      <input type="hidden" name="id" id="email_id">
      <label>Email Address
        <input type="email" name="email" id="email_email" required maxlength="150" placeholder="info@example.com">
      </label>
      <label>Description (optional)
        <input type="text" name="description" id="email_description" maxlength="150" placeholder="e.g. Support, Sales, HR">
      </label>
      <label>Sort Order
        <input type="number" name="sort_order" id="email_sort_order" value="0" min="0" max="9999">
      </label>
      <div style="display:flex;gap:10px;grid-column:1/-1">
        <button type="submit" class="btn-add">Save</button>
        <button type="button" class="mini-btn toggle" onclick="closeEmail()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== MODAL: Add/Edit Branch ===== -->
<div id="branchOverlay" class="modal-overlay" onclick="if(event.target===this) closeBranch()">
  <div class="modal-box">
    <h3 id="branchTitle">Add Branch</h3>
    <form method="post" class="form-grid" id="branchForm" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="branch_action" value="add_branch">
      <input type="hidden" name="id" id="branch_id">
      <label style="grid-column:1/-1">Location Name
        <input type="text" name="name" id="branch_name" required maxlength="150" placeholder="e.g. Colombo Main Branch">
      </label>
      <label style="grid-column:1/-1">Address
        <textarea name="address" id="branch_address" rows="3" required placeholder="Full branch address"></textarea>
      </label>
      <label style="grid-column:1/-1">Details (optional)
        <input type="text" name="details" id="branch_details" maxlength="255" placeholder="e.g. opening hours, phone, landmark">
      </label>
      <label style="display:flex;align-items:center;gap:8px;grid-column:1/-1">
        <input type="checkbox" name="is_main" id="branch_is_main" style="width:auto;margin:0">
        Set as Main Location
      </label>
      <label>Sort Order
        <input type="number" name="sort_order" id="branch_sort_order" value="0" min="0" max="9999">
      </label>
      <div style="display:flex;gap:10px;grid-column:1/-1">
        <button type="submit" class="btn-add">Save</button>
        <button type="button" class="mini-btn toggle" onclick="closeBranch()">Cancel</button>
      </div>
    </form>
    <p style="margin-top:14px;color:var(--muted);font-size:13px">Only one branch can be the Main Location — setting this one will unset any other.</p>
  </div>
</div>

<script>
/* ---- Contact modal ---- */
function openAddContact(){
  document.getElementById('contactTitle').textContent='Add Contact Number';
  document.getElementById('contact_action').value='add_contact';
  document.getElementById('contactForm').reset();
  document.getElementById('contact_id').value='';
  document.getElementById('contactOverlay').classList.add('show');
}
function openEditContact(c){
  document.getElementById('contactTitle').textContent='Edit Contact Number';
  document.getElementById('contact_action').value='update_contact';
  document.getElementById('contact_id').value=c.id;
  document.getElementById('contact_phone').value=c.phone;
  document.getElementById('contact_description').value=c.description||'';
  document.getElementById('contact_is_main').checked = !!parseInt(c.is_main,10);
  document.getElementById('contact_sort_order').value=c.sort_order;
  document.getElementById('contactOverlay').classList.add('show');
}
function closeContact(){ document.getElementById('contactOverlay').classList.remove('show'); }

/* ---- Email modal ---- */
function openAddEmail(){
  document.getElementById('emailTitle').textContent='Add Email Address';
  document.getElementById('email_action').value='add_email';
  document.getElementById('emailForm').reset();
  document.getElementById('email_id').value='';
  document.getElementById('emailOverlay').classList.add('show');
}
function openEditEmail(em){
  document.getElementById('emailTitle').textContent='Edit Email Address';
  document.getElementById('email_action').value='update_email';
  document.getElementById('email_id').value=em.id;
  document.getElementById('email_email').value=em.email;
  document.getElementById('email_description').value=em.description||'';
  document.getElementById('email_sort_order').value=em.sort_order;
  document.getElementById('emailOverlay').classList.add('show');
}
function closeEmail(){ document.getElementById('emailOverlay').classList.remove('show'); }

/* ---- Branch modal ---- */
function openAddBranch(){
  document.getElementById('branchTitle').textContent='Add Branch';
  document.getElementById('branch_action').value='add_branch';
  document.getElementById('branchForm').reset();
  document.getElementById('branch_id').value='';
  document.getElementById('branchOverlay').classList.add('show');
}
function openEditBranch(b){
  document.getElementById('branchTitle').textContent='Edit Branch';
  document.getElementById('branch_action').value='update_branch';
  document.getElementById('branch_id').value=b.id;
  document.getElementById('branch_name').value=b.name;
  document.getElementById('branch_address').value=b.address;
  document.getElementById('branch_details').value=b.details||'';
  document.getElementById('branch_is_main').checked = !!parseInt(b.is_main,10);
  document.getElementById('branch_sort_order').value=b.sort_order;
  document.getElementById('branchOverlay').classList.add('show');
}
function closeBranch(){ document.getElementById('branchOverlay').classList.remove('show'); }
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>