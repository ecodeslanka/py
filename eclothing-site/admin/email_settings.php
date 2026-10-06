<?php
/**
 * admin/email_settings.php — Email & Notification Settings
 * Configure the SMTP relay used for outgoing mail, the branded "Welcome"
 * email sent after a customer registers, and the SMS/OTP gateway used for
 * mobile number verification during sign up.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Email Settings';
$active    = 'email_settings';

get_email_settings(); // self-migrates the email_settings table

$formError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_email') {
        $fromName    = trim((string)($_POST['from_name'] ?? ''));
        $fromEmail   = trim((string)($_POST['from_email'] ?? ''));
        $smtpHost    = trim((string)($_POST['smtp_host'] ?? ''));
        $smtpPort    = (int)($_POST['smtp_port'] ?? 587);
        $smtpUser    = trim((string)($_POST['smtp_username'] ?? ''));
        $smtpPass    = (string)($_POST['smtp_password'] ?? '');
        $smtpEnc     = in_array($_POST['smtp_encryption'] ?? 'tls', ['none', 'ssl', 'tls'], true) ? $_POST['smtp_encryption'] : 'tls';
        $sendWelcome = isset($_POST['send_welcome_email']) ? 1 : 0;
        $subject     = trim((string)($_POST['welcome_email_subject'] ?? ''));
        $body        = (string)($_POST['welcome_email_body'] ?? '');
        $adminEmail  = trim((string)($_POST['admin_notification_email'] ?? ''));
        $notifyAdmin = isset($_POST['notify_admin_on_order']) ? 1 : 0;

        $err = '';
        if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $err = 'Please enter a valid "From" email address.';
        } elseif ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $err = 'Please enter a valid admin notification email address.';
        } elseif ($smtpHost !== '' && $smtpPort < 1) {
            $err = 'Please enter a valid SMTP port (e.g. 587 for TLS, 465 for SSL).';
        } elseif ($smtpHost !== '' && $fromEmail === '') {
            $err = 'A "From" email address is required when an SMTP host is set.';
        }

        if ($err === '') {
            try {
                // Resolve the password in PHP (not SQL) to avoid a MySQL "illegal mix of
                // collations" error some hosts throw when comparing a literal '' against
                // a column whose collation differs from the connection's default.
                $currentSmtpPass = get_email_settings()['smtp_password'] ?? null;
                $finalSmtpPass   = $smtpPass !== '' ? $smtpPass : $currentSmtpPass;

                db()->prepare('UPDATE email_settings SET
                    from_name = ?, from_email = ?, smtp_host = ?, smtp_port = ?, smtp_username = ?,
                    smtp_password = ?, smtp_encryption = ?,
                    send_welcome_email = ?, welcome_email_subject = ?, welcome_email_body = ?,
                    admin_notification_email = ?, notify_admin_on_order = ?
                    WHERE id = 1')
                   ->execute([
                       $fromName ?: null, $fromEmail ?: null, $smtpHost ?: null, $smtpPort ?: 587, $smtpUser ?: null,
                       $finalSmtpPass, $smtpEnc, $sendWelcome, $subject ?: 'Welcome to {{company_name}}!', $body,
                       $adminEmail ?: null, $notifyAdmin,
                   ]);
                flash('ok', 'Email settings saved successfully.');
                redirect('/admin/email_settings');
            } catch (\Throwable $e) {
                error_log('Saving email settings failed: ' . $e->getMessage());
                // Don't redirect here — a redirect discards everything the admin just typed
                // (form fields would reset to the OLD saved values), which looks like the
                // save silently failed even though it's really just a validation error.
                $formError = 'Could not save Email settings — ' . $e->getMessage();
            }
        } else {
            // Don't redirect here — a redirect discards everything the admin just typed
            // (form fields would reset to the OLD saved values), which looks like the
            // save silently failed even though it's really just a validation error.
            $formError = $err;
        }
    }

    if ($action === 'send_test_email') {
        $testTo = trim((string)($_POST['test_email'] ?? ''));
        if (filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            $current = get_email_settings();
            if (empty($current['send_welcome_email'])) {
                flash('err', 'Turn on "Send a welcome email automatically after registration" below and save before sending a test.');
            } else {
                $sent = send_welcome_email([
                    'id' => 0, 'first_name' => 'Test', 'last_name' => 'Customer', 'email' => $testTo,
                ]);
                if ($sent) {
                    flash('ok', "Test welcome email sent to $testTo.");
                } else {
                    $detail = last_mail_error();
                    flash('err', 'Could not send the test email.' . ($detail ? ' Reason: ' . $detail : ' Check your server\'s mail configuration.'));
                }
            }
        } else {
            flash('err', 'Enter a valid email address to send the test to.');
        }
        redirect('/admin/email_settings');
    }
}

$settings = get_email_settings();
if ($formError !== null) {
    // Show what the admin just typed instead of the old saved values (see note above).
    $settings = array_merge($settings, [
        'from_name' => $fromName, 'from_email' => $fromEmail, 'smtp_host' => $smtpHost,
        'smtp_port' => $smtpPort, 'smtp_username' => $smtpUser, 'smtp_encryption' => $smtpEnc,
        'send_welcome_email' => $sendWelcome, 'welcome_email_subject' => $subject, 'welcome_email_body' => $body,
        'admin_notification_email' => $adminEmail, 'notify_admin_on_order' => $notifyAdmin,
    ]);
}
require __DIR__ . '/includes/header.php';
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($m) ?></div><?php endif; ?>
<?php if ($formError): ?><div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($formError) ?> Your other entries below have been kept — just fix this and save again.</div><?php endif; ?>

<!-- ===== OUTGOING EMAIL / WELCOME EMAIL ===== -->
<form method="post" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_email">

  <div class="panel">
    <div class="panel-head"><h3>Outgoing Email (SMTP)</h3></div>
    <div class="panel-body">
      <p class="hint" style="margin-bottom:14px">Optional: point outgoing mail at an external SMTP relay (e.g. Gmail, SendGrid, Mailgun). Leave blank to use the server's built-in mail() function.</p>
      <div class="form-grid">
        <label>From Name
          <input type="text" name="from_name" placeholder="ECLOTHING" value="<?= e($settings['from_name'] ?? '') ?>">
        </label>
        <label>From Email
          <input type="email" name="from_email" placeholder="no-reply@yourdomain.com" value="<?= e($settings['from_email'] ?? '') ?>">
        </label>
        <label>SMTP Host
          <input type="text" name="smtp_host" placeholder="smtp.yourprovider.com" value="<?= e($settings['smtp_host'] ?? '') ?>">
        </label>
        <label>SMTP Port
          <input type="number" name="smtp_port" value="<?= e((string)($settings['smtp_port'] ?? 587)) ?>">
        </label>
        <label>SMTP Username
          <input type="text" name="smtp_username" value="<?= e($settings['smtp_username'] ?? '') ?>">
        </label>
        <label>SMTP Password
          <input type="password" name="smtp_password" placeholder="Leave blank to keep current password" autocomplete="new-password">
        </label>
        <label>Encryption
          <select name="smtp_encryption">
            <?php foreach (['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'None'] as $val => $label): ?>
              <option value="<?= $val ?>" <?= ($settings['smtp_encryption'] ?? 'tls') === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Order Notifications</h3></div>
    <div class="panel-body">
      <p class="hint" style="margin-bottom:14px">When a customer places an order, send a copy of the order details to this address as well (in addition to the customer's own confirmation email).</p>
      <div class="choice-group" style="margin-bottom:16px">
        <label class="choice-pill">
          <input type="checkbox" name="notify_admin_on_order" <?= !empty($settings['notify_admin_on_order']) ? 'checked' : '' ?>>
          <span><i class="fa-solid fa-bell"></i> Email me when a new order comes in</span>
        </label>
      </div>
      <div class="form-grid">
        <label>Admin Notification Email
          <input type="email" name="admin_notification_email" placeholder="orders@yourdomain.com" value="<?= e($settings['admin_notification_email'] ?? '') ?>">
        </label>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Welcome Email</h3></div>
    <div class="panel-body">
      <div class="choice-group" style="margin-bottom:16px">
        <label class="choice-pill">
          <input type="checkbox" name="send_welcome_email" <?= !empty($settings['send_welcome_email']) ? 'checked' : '' ?>>
          <span><i class="fa-solid fa-envelope-open-text"></i> Send a welcome email automatically after registration</span>
        </label>
      </div>
      <p class="hint" style="margin-bottom:10px">Sent with your company name and logo (from Site Configuration) as the header. Use <code>{{first_name}}</code>, <code>{{last_name}}</code> and <code>{{company_name}}</code> as placeholders.</p>
      <div class="form-grid">
        <label style="grid-column:1/-1">Subject
          <input type="text" name="welcome_email_subject" value="<?= e($settings['welcome_email_subject'] ?? 'Welcome to {{company_name}}!') ?>">
        </label>
        <label style="grid-column:1/-1">Email Body (HTML)
          <textarea name="welcome_email_body" rows="8"><?= e($settings['welcome_email_body'] ?? '') ?></textarea>
        </label>
      </div>
    </div>
  </div>

  <button type="submit" class="btn-add" style="margin-bottom:16px"><i class="fa-solid fa-floppy-disk"></i> Save Email Settings</button>
</form>

<div class="panel" style="margin-bottom:28px">
  <div class="panel-head"><h3>Send a Test Welcome Email</h3></div>
  <div class="panel-body">
    <form method="post" class="form-grid" autocomplete="off" style="align-items:end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="send_test_email">
      <label>Send test to
        <input type="email" name="test_email" placeholder="you@example.com" required>
      </label>
      <button type="submit" class="btn-add"><i class="fa-solid fa-paper-plane"></i> Send Test Email</button>
    </form>
  </div>
</div>

<div class="panel" style="margin-bottom:28px">
  <div class="panel-head"><h3>SMS / Mobile OTP Gateway</h3></div>
  <div class="panel-body">
    <p class="hint">SMS (mobile OTP verification + the new-order text) is now configured on its own page — powered by
      <a href="https://smslenz.lk" target="_blank" rel="noopener">smslenz.lk</a>.</p>
    <a href="<?= BASE_URL ?>/admin/sms_settings" class="btn-add" style="display:inline-flex;margin-top:10px">
      <i class="fa-solid fa-comment-sms"></i> Go to SMS Settings
    </a>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
