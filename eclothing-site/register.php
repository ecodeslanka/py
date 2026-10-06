<?php
/**
 * register.php — Customer "Sign Up" page
 * Fields: first name, last name, email, Sri Lankan mobile number (OTP verified),
 * password, confirm password, "I agree to terms" + "I agree to SMS/Email marketing"
 * checkboxes. Google sign-in lives on the Sign In page only — see login.php.
 */
require_once __DIR__ . '/config/config.php';
ensure_customer_tables();

if (!empty($_SESSION['customer_id'])) {
    redirect('/');
}

$siteSettings  = get_site_settings();
$errors        = [];
$old           = ['first_name' => '', 'last_name' => '', 'email' => '', 'mobile' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $old['first_name'] = trim((string)($_POST['first_name'] ?? ''));
    $old['last_name']  = trim((string)($_POST['last_name'] ?? ''));
    $old['email']      = trim((string)($_POST['email'] ?? ''));
    $old['mobile']     = trim((string)($_POST['mobile'] ?? ''));
    $password          = (string)($_POST['password'] ?? '');
    $confirmPassword   = (string)($_POST['confirm_password'] ?? '');
    $agreeTerms        = isset($_POST['agree_terms']);
    $agreeMarketing    = isset($_POST['agree_marketing']);

    $mobileNormalized  = $old['mobile'] !== '' ? normalize_lk_mobile($old['mobile']) : null;

    if ($old['first_name'] === '' || mb_strlen($old['first_name']) > 80) {
        $errors['first_name'] = 'Please enter your first name.';
    }
    if ($old['last_name'] === '' || mb_strlen($old['last_name']) > 80) {
        $errors['last_name'] = 'Please enter your last name.';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }
    if (!$mobileNormalized) {
        $errors['mobile'] = 'Enter a valid Sri Lankan mobile number, e.g. 07XXXXXXXX.';
    }
    if (mb_strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }
    if (!$agreeTerms) {
        $errors['agree_terms'] = 'You must agree to the Terms and Conditions to continue.';
    }

    $emailSettings = get_email_settings();
    if (empty($errors['mobile']) && !empty($emailSettings['require_mobile_verify']) && !mobile_recently_verified($mobileNormalized)) {
        $errors['mobile'] = 'Please verify your mobile number with the OTP code before continuing.';
    }

    if (empty($errors)) {
        $dupe = db()->prepare('SELECT id FROM customers WHERE email = ? OR mobile = ? LIMIT 1');
        $dupe->execute([$old['email'], $mobileNormalized]);
        if ($dupe->fetch()) {
            $errors['email'] = 'An account with this email or mobile number already exists.';
        }
    }

    if (empty($errors)) {
        $st = db()->prepare('INSERT INTO customers
            (first_name, last_name, email, mobile, password_hash, mobile_verified, agreed_terms, marketing_opt_in)
            VALUES (?, ?, ?, ?, ?, 1, 1, ?)');
        $st->execute([
            $old['first_name'], $old['last_name'], $old['email'], $mobileNormalized,
            password_hash($password, PASSWORD_DEFAULT), $agreeMarketing ? 1 : 0,
        ]);
        $customerId = (int)db()->lastInsertId();

        $customer = db()->prepare('SELECT * FROM customers WHERE id = ?');
        $customer->execute([$customerId]);
        $customer = $customer->fetch();

        send_welcome_email($customer);

        session_regenerate_id(true);
        $_SESSION['customer_id']   = $customerId;
        $_SESSION['customer_name'] = $old['first_name'];

        flash('customer_success', 'Welcome, ' . $old['first_name'] . '! Your account has been created.');
        redirect('/dashboard');
    }
}

$pageTitle = 'Create an Account';
require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/auth.css">

<div class="auth-wrap">
  <div class="auth-card">
    <h1>Create your account</h1>
    <p class="auth-sub">Sign up to buy items, track orders and check out faster.</p>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-error">⚠ Please fix the highlighted fields below.</div>
    <?php endif; ?>

    <form method="post" action="" id="registerForm" autocomplete="off" novalidate>
      <?= csrf_field() ?>

      <div class="auth-grid">
        <div class="auth-field">
          <label for="first_name">First Name</label>
          <input type="text" id="first_name" name="first_name" maxlength="80" required
                 value="<?= e($old['first_name']) ?>" autofocus>
          <?php if (!empty($errors['first_name'])): ?><span class="error"><?= e($errors['first_name']) ?></span><?php endif; ?>
        </div>
        <div class="auth-field">
          <label for="last_name">Last Name</label>
          <input type="text" id="last_name" name="last_name" maxlength="80" required
                 value="<?= e($old['last_name']) ?>">
          <?php if (!empty($errors['last_name'])): ?><span class="error"><?= e($errors['last_name']) ?></span><?php endif; ?>
        </div>
      </div>

      <div class="auth-field">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email" maxlength="150" required
               value="<?= e($old['email']) ?>" autocomplete="email">
        <?php if (!empty($errors['email'])): ?><span class="error"><?= e($errors['email']) ?></span><?php endif; ?>
      </div>

      <div class="auth-field">
        <label for="mobile">Contact No. (Sri Lanka)</label>
        <div class="mobile-row">
          <input type="tel" id="mobile" name="mobile" placeholder="07XXXXXXXX" maxlength="15"
                 value="<?= e($old['mobile']) ?>" required>
          <button type="button" class="btn-otp" id="sendOtpBtn">Send OTP</button>
        </div>
        <span class="hint">We'll text a 6-digit code to verify this number.</span>
        <?php if (!empty($errors['mobile'])): ?><span class="error"><?= e($errors['mobile']) ?></span><?php endif; ?>

        <div class="otp-box" id="otpBox">
          <label for="otp_code" style="font-size:13px;font-weight:700">Enter verification code</label>
          <input type="text" id="otp_code" inputmode="numeric" maxlength="6" placeholder="••••••">
          <button type="button" class="btn-otp" id="verifyOtpBtn" style="width:100%;margin-top:10px">Verify Code</button>
          <div class="otp-status" id="otpStatus"></div>
        </div>
      </div>

      <div class="auth-field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
        <span class="hint">At least 8 characters.</span>
        <?php if (!empty($errors['password'])): ?><span class="error"><?= e($errors['password']) ?></span><?php endif; ?>
      </div>

      <div class="auth-field">
        <label for="confirm_password">Confirm Password</label>
        <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
        <?php if (!empty($errors['confirm_password'])): ?><span class="error"><?= e($errors['confirm_password']) ?></span><?php endif; ?>
      </div>

      <label class="check-row">
        <input type="checkbox" name="agree_terms" value="1" <?= isset($_POST['agree_terms']) ? 'checked' : '' ?>>
        <span>I agree to the <a href="<?= BASE_URL ?>/terms-conditions" target="_blank">Terms and Conditions</a> and Privacy Policy.</span>
      </label>
      <?php if (!empty($errors['agree_terms'])): ?><div class="error" style="margin:-10px 0 14px 26px"><?= e($errors['agree_terms']) ?></div><?php endif; ?>

      <label class="check-row">
        <input type="checkbox" name="agree_marketing" value="1" <?= isset($_POST['agree_marketing']) ? 'checked' : '' ?>>
        <span>I agree to receive SMS &amp; Email marketing promotions from <?= e($siteSettings['company_name'] ?: SITE_NAME) ?>.</span>
      </label>

      <input type="hidden" name="mobile_verified_flag" id="mobile_verified_flag" value="0">
      <button type="submit" class="auth-submit">Create Account</button>
    </form>

    <p class="auth-foot">Already have an account? <a href="<?= BASE_URL ?>/login">Sign in</a></p>
  </div>
</div>

<script>
(function(){
  const sendBtn   = document.getElementById('sendOtpBtn');
  const verifyBtn = document.getElementById('verifyOtpBtn');
  const otpBox     = document.getElementById('otpBox');
  const otpStatus  = document.getElementById('otpStatus');
  const mobileInput= document.getElementById('mobile');
  const verifiedFlag = document.getElementById('mobile_verified_flag');
  let cooldown = 0;

  function setStatus(msg, ok){
    otpStatus.textContent = msg;
    otpStatus.className = 'otp-status ' + (ok ? 'ok' : 'err');
  }

  sendBtn.addEventListener('click', function(){
    const mobile = mobileInput.value.trim();
    if (!mobile) { setStatus('Enter your mobile number first.', false); otpBox.classList.remove('show'); return; }

    sendBtn.disabled = true;
    fetch('<?= BASE_URL ?>/send-otp', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'mobile=' + encodeURIComponent(mobile) + '&csrf_token=<?= e(csrf_token()) ?>'
    }).then(r => r.json()).then(data => {
      if (data.success) {
        otpBox.classList.add('show');
        setStatus(data.message || 'Code sent! Check your SMS inbox.', true);
        let secs = 30;
        const timer = setInterval(function(){
          secs--; sendBtn.textContent = 'Resend (' + secs + 's)';
          if (secs <= 0) { clearInterval(timer); sendBtn.disabled = false; sendBtn.textContent = 'Send OTP'; }
        }, 1000);
      } else {
        setStatus(data.message || 'Could not send code.', false);
        sendBtn.disabled = false;
      }
    }).catch(() => { setStatus('Network error — please try again.', false); sendBtn.disabled = false; });
  });

  verifyBtn.addEventListener('click', function(){
    const code = document.getElementById('otp_code').value.trim();
    const mobile = mobileInput.value.trim();
    if (code.length !== 6) { setStatus('Enter the 6-digit code.', false); return; }

    fetch('<?= BASE_URL ?>/verify-otp', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'mobile=' + encodeURIComponent(mobile) + '&code=' + encodeURIComponent(code) + '&csrf_token=<?= e(csrf_token()) ?>'
    }).then(r => r.json()).then(data => {
      if (data.success) {
        setStatus('✓ Mobile number verified!', true);
        verifiedFlag.value = '1';
        mobileInput.readOnly = true;
      } else {
        setStatus(data.message || 'Invalid or expired code.', false);
      }
    }).catch(() => setStatus('Network error — please try again.', false));
  });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
