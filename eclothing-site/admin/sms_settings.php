<?php
/**
 * admin/sms_settings.php — SMS Settings (SMSLenz.lk)
 * Configure the SMSLenz.lk gateway (https://smslenz.lk) used to text
 * customers: mobile OTP verification during Sign Up, and an automatic
 * "Thank you for your order" SMS every time a new order comes in.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'SMS Settings';
$active    = 'sms_settings';

get_email_settings(); // self-migrates the email_settings table (adds SMSLenz columns if missing)

$formError    = null;
$accountStatus = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_sms') {
        $smsEnabled     = isset($_POST['sms_enabled']) ? 1 : 0;
        $userId         = trim((string)($_POST['sms_user_id'] ?? ''));
        $apiKey         = trim((string)($_POST['sms_gateway_api_key'] ?? ''));
        $senderId       = trim((string)($_POST['sms_sender_id'] ?? ''));
        $orderSms       = isset($_POST['order_sms_enabled']) ? 1 : 0;
        $orderTemplate  = trim((string)($_POST['order_sms_template'] ?? ''));
        $requireVer     = isset($_POST['require_mobile_verify']) ? 1 : 0;

        $err = '';
        if ($smsEnabled && ($userId === '' || $senderId === '')) {
            $err = 'Enter your SMSLenz User ID and Sender ID before enabling SMS (and an API Key, if you haven\'t saved one yet).';
        }

        if ($err === '') {
            try {
                // Resolve the API key in PHP (not SQL) to avoid a MySQL "illegal mix of
                // collations" error some hosts throw when comparing a literal '' against
                // a column whose collation differs from the connection's default.
                $currentApiKey = get_email_settings()['sms_gateway_api_key'] ?? null;
                $finalApiKey   = $apiKey !== '' ? $apiKey : $currentApiKey;

                db()->prepare('UPDATE email_settings SET
                    sms_enabled = ?, sms_user_id = ?, sms_gateway_url = ?,
                    sms_gateway_api_key = ?,
                    sms_sender_id = ?, order_sms_enabled = ?, order_sms_template = ?,
                    require_mobile_verify = ?
                    WHERE id = 1')
                   ->execute([
                       $smsEnabled, $userId ?: null, SMSLENZ_API_URL,
                       $finalApiKey,
                       $senderId ?: null, $orderSms, $orderTemplate ?: default_order_sms_template(),
                       $requireVer,
                   ]);
                flash('ok', 'SMS settings saved successfully.');
                redirect('/admin/sms_settings');
            } catch (\Throwable $e) {
                error_log('Saving SMS settings failed: ' . $e->getMessage());
                $formError = 'Could not save SMS settings — ' . $e->getMessage();
            }
        } else {
            $formError = $err;
        }
    }

    if ($action === 'send_test_sms') {
        $testMobile = trim((string)($_POST['test_mobile'] ?? ''));
        $normalized = $testMobile !== '' ? normalize_lk_mobile($testMobile) : null;
        if (!$normalized) {
            flash('err', 'Enter a valid Sri Lankan mobile number, e.g. 07XXXXXXXX.');
        } else {
            $current = get_email_settings();
            if (empty($current['sms_enabled'])) {
                flash('err', 'Turn on "Enable SMS" below and save before sending a test.');
            } else {
                $sent = send_sms($normalized, 'This is a test SMS from ' . (get_site_settings()['company_name'] ?: SITE_NAME) . ' via SMSLenz.lk.');
                if ($sent) {
                    flash('ok', "Test SMS sent to $normalized.");
                } else {
                    flash('err', 'Could not send the test SMS. Check your User ID / API Key / Sender ID and the server error log for details.');
                }
            }
        }
        redirect('/admin/sms_settings');
    }

    if ($action === 'check_balance') {
        $accountStatus = sms_account_status();
        if ($accountStatus === null) {
            flash('err', 'Could not retrieve account status. Check your User ID and API Key.');
            redirect('/admin/sms_settings');
        }
    }
}

$settings = get_email_settings();
if ($formError !== null) {
    // Keep what the admin just typed instead of the old saved values.
    $settings = array_merge($settings, [
        'sms_enabled' => $smsEnabled, 'sms_user_id' => $userId, 'sms_sender_id' => $senderId,
        'order_sms_enabled' => $orderSms, 'order_sms_template' => $orderTemplate,
        'require_mobile_verify' => $requireVer,
    ]);
}
require __DIR__ . '/includes/header.php';
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($m) ?></div><?php endif; ?>
<?php if ($formError): ?><div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($formError) ?> Your other entries below have been kept — just fix this and save again.</div><?php endif; ?>

<form method="post" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_sms">

  <div class="panel">
    <div class="panel-head"><h3><i class="fa-solid fa-comment-sms"></i> SMSLenz.lk Gateway</h3></div>
    <div class="panel-body">
      <p class="hint" style="margin-bottom:14px">
        Connects to <a href="https://smslenz.lk" target="_blank" rel="noopener">smslenz.lk</a> — used to send the mobile
        OTP code during Sign Up, and (optionally) an automatic SMS to the customer for every new order.
        Get your User ID and API Key from your <a href="https://smslenz.lk/account/api-key" target="_blank" rel="noopener">SMSLenz API Settings page</a>.
      </p>

      <div class="choice-group" style="margin-bottom:16px">
        <label class="choice-pill">
          <input type="checkbox" name="sms_enabled" <?= !empty($settings['sms_enabled']) ? 'checked' : '' ?>>
          <span><i class="fa-solid fa-toggle-on"></i> Enable SMS (master switch — turns all outgoing SMS on/off)</span>
        </label>
      </div>

      <div class="form-grid">
        <label>SMSLenz User ID
          <input type="text" name="sms_user_id" placeholder="Your User ID" value="<?= e($settings['sms_user_id'] ?? '') ?>">
        </label>
        <label>SMSLenz API Key
          <input type="password" name="sms_gateway_api_key" placeholder="Leave blank to keep current key" autocomplete="new-password">
        </label>
        <label>Sender ID
          <input type="text" name="sms_sender_id" placeholder="e.g. ECLOTHING (use SMSlenzDEMO for testing)" value="<?= e($settings['sms_sender_id'] ?? '') ?>">
        </label>
      </div>

      <?php if (empty($settings['sms_enabled']) || empty($settings['sms_user_id']) || empty($settings['sms_gateway_api_key']) || empty($settings['sms_sender_id'])): ?>
        <p class="hint" style="margin-top:10px;color:var(--red-600, #c0181c)">
          <i class="fa-solid fa-triangle-exclamation"></i>
          SMS is currently <?= empty($settings['sms_enabled']) ? '<strong>disabled</strong>' : '<strong>enabled but not fully configured</strong>' ?> —
          messages (including OTP codes) are only written to the server error log right now, not sent as real SMS.
        </p>
      <?php endif; ?>

      <div class="choice-group" style="margin:18px 0 0">
        <label class="choice-pill">
          <input type="checkbox" name="require_mobile_verify" <?= !empty($settings['require_mobile_verify']) ? 'checked' : '' ?>>
          <span><i class="fa-solid fa-mobile-screen-button"></i> Require mobile OTP verification before Sign Up completes</span>
        </label>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3><i class="fa-solid fa-cart-shopping"></i> New Order SMS</h3></div>
    <div class="panel-body">
      <div class="choice-group" style="margin-bottom:16px">
        <label class="choice-pill">
          <input type="checkbox" name="order_sms_enabled" <?= !empty($settings['order_sms_enabled']) ? 'checked' : '' ?>>
          <span><i class="fa-solid fa-bell"></i> Send an SMS to the customer for every new order</span>
        </label>
      </div>
      <p class="hint" style="margin-bottom:10px">
        Sent to the phone number entered at checkout. Use <code>{{first_name}}</code>, <code>{{order_no}}</code>,
        <code>{{subtotal}}</code> and <code>{{company_name}}</code> as placeholders.
      </p>
      <div class="form-grid">
        <label style="grid-column:1/-1">Message
          <textarea name="order_sms_template" rows="3" maxlength="1500"><?= e($settings['order_sms_template'] ?? default_order_sms_template()) ?></textarea>
        </label>
      </div>
    </div>
  </div>

  <button type="submit" class="btn-add" style="margin-bottom:16px"><i class="fa-solid fa-floppy-disk"></i> Save SMS Settings</button>
</form>

<div class="panel" style="margin-bottom:20px">
  <div class="panel-head"><h3><i class="fa-solid fa-vial"></i> Send a Test SMS</h3></div>
  <div class="panel-body">
    <form method="post" class="form-grid" autocomplete="off" style="align-items:end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="send_test_sms">
      <label>Send test to
        <input type="tel" name="test_mobile" placeholder="07XXXXXXXX" required>
      </label>
      <button type="submit" class="btn-add"><i class="fa-solid fa-paper-plane"></i> Send Test SMS</button>
    </form>
  </div>
</div>

<div class="panel" style="margin-bottom:28px">
  <div class="panel-head"><h3><i class="fa-solid fa-wallet"></i> Account Balance</h3></div>
  <div class="panel-body">
    <form method="post" style="margin-bottom:<?= $accountStatus ? '16px' : '0' ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="check_balance">
      <button type="submit" class="btn-add"><i class="fa-solid fa-rotate"></i> Check Balance</button>
    </form>
    <?php if ($accountStatus): ?>
      <div class="form-grid" style="margin-top:6px">
        <div><strong>Status:</strong> <?= e((string)($accountStatus['status'] ?? '—')) ?></div>
        <div><strong>Credit Balance:</strong> <?= e((string)($accountStatus['sms_credit_balance'] ?? '—')) ?></div>
        <div><strong>Active Plan:</strong> <?= e((string)($accountStatus['active_plan'] ?? '—')) ?></div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
