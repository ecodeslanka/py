<?php
/**
 * profile.php — Customer Profile
 * Lets a logged-in customer update their name, address (Province → City,
 * with select2 cascading dropdowns) and contact number. Customers who
 * signed up via Google start with a placeholder mobile number and no
 * address — this page is where they fill those in (with OTP verification
 * for the mobile number, same flow as Sign Up).
 */
require_once __DIR__ . '/config/config.php';
ensure_customer_tables();
ensure_location_tables();

$customer = current_customer();
if (!$customer) {
    redirect('/login');
}

$isGoogleMobilePlaceholder = strpos((string)$customer['mobile'], 'g-') === 0;

$errors = [];
$old = [
    'first_name'    => $customer['first_name'],
    'last_name'     => $customer['last_name'],
    'address_line1' => $customer['address_line1'] ?? '',
    'address_line2' => $customer['address_line2'] ?? '',
    'province_id'   => $customer['province_id'] ?? '',
    'city'          => '',
    'postal_code'   => $customer['postal_code'] ?? '',
];
if (!empty($customer['city_id'])) {
    $cityRow = get_city((int)$customer['city_id']);
    if ($cityRow) {
        $old['city']        = $cityRow['name_en'];
        $old['province_id'] = $cityRow['province_id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? 'update_profile';

    if ($action === 'update_profile') {
        $old['first_name']    = trim((string)($_POST['first_name'] ?? ''));
        $old['last_name']     = trim((string)($_POST['last_name'] ?? ''));
        $old['address_line1'] = trim((string)($_POST['address_line1'] ?? ''));
        $old['address_line2'] = trim((string)($_POST['address_line2'] ?? ''));
        $old['province_id']   = (int)($_POST['province_id'] ?? 0);
        $old['city']          = trim((string)($_POST['city'] ?? ''));
        $old['postal_code']   = trim((string)($_POST['postal_code'] ?? ''));

        if ($old['first_name'] === '' || mb_strlen($old['first_name']) > 80) {
            $errors['first_name'] = 'Please enter your first name.';
        }
        if ($old['last_name'] === '' || mb_strlen($old['last_name']) > 80) {
            $errors['last_name'] = 'Please enter your last name.';
        }
        if ($old['address_line1'] === '') {
            $errors['address_line1'] = 'Please enter your address.';
        }
        if ($old['province_id'] <= 0) {
            $errors['province_id'] = 'Please select your province.';
        }
        if ($old['city'] === '') {
            $errors['city'] = 'Please select your city.';
        }

        // The city is submitted as its name (from the select2 box) — resolve
        // it to an id scoped to the chosen province so a same-named city in
        // another province can't be picked by mistake.
        $cityId = null;
        if (empty($errors['province_id']) && empty($errors['city'])) {
            $cityMatch = null;
            foreach (get_cities_by_province($old['province_id']) as $c) {
                if (strcasecmp($c['name_en'], $old['city']) === 0) { $cityMatch = $c; break; }
            }
            if (!$cityMatch) {
                $errors['city'] = 'Please choose a city from the list for the selected province.';
            } else {
                $cityId = (int)$cityMatch['id'];
            }
        }

        if (empty($errors)) {
            db()->prepare('UPDATE customers SET
                first_name = ?, last_name = ?, address_line1 = ?, address_line2 = ?,
                province_id = ?, city_id = ?, postal_code = ?
                WHERE id = ?')
               ->execute([
                   $old['first_name'], $old['last_name'], $old['address_line1'], $old['address_line2'] ?: null,
                   $old['province_id'], $cityId, $old['postal_code'] ?: null,
                   $customer['id'],
               ]);
            $_SESSION['customer_name'] = $old['first_name'];
            flash('profile_success', 'Your profile has been updated.');
            redirect('/profile');
        }
    }

    if ($action === 'update_mobile') {
        $mobile = normalize_lk_mobile((string)($_POST['mobile'] ?? ''));
        $verifiedOtp = (string)($_POST['otp_verified_mobile'] ?? '') === $mobile && $mobile !== '';

        if (!$mobile) {
            flash('mobile_err', 'Enter a valid Sri Lankan mobile number, e.g. 07XXXXXXXX.');
        } elseif (!$verifiedOtp) {
            flash('mobile_err', 'Please verify this number with the OTP code before saving.');
        } else {
            $dupe = db()->prepare('SELECT id FROM customers WHERE mobile = ? AND id != ? LIMIT 1');
            $dupe->execute([$mobile, $customer['id']]);
            if ($dupe->fetch()) {
                flash('mobile_err', 'This mobile number is already used by another account.');
            } else {
                db()->prepare('UPDATE customers SET mobile = ?, mobile_verified = 1 WHERE id = ?')
                    ->execute([$mobile, $customer['id']]);
                flash('profile_success', 'Your mobile number has been added and verified.');
            }
        }
        redirect('/profile');
    }
}

$provinces = get_provinces();

$pageTitle = 'My Profile';
require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/auth.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css">

<div class="dash-wrap">
  <div class="wrap" style="max-width:720px">

    <?php if (!empty($_GET['welcome'])): ?>
      <div class="alert alert-success"><i class="fa-solid fa-hand-sparkles"></i> Welcome! You're signed in with Google — add your mobile number and address below so we can deliver your orders.</div>
    <?php endif; ?>
    <?php if ($m = flash('profile_success')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>

    <div class="dash-card" style="margin-bottom:20px">
      <h3><i class="fa-solid fa-user-pen"></i> Name &amp; Address</h3>

      <?php if (!empty($errors)): ?><div class="alert alert-error" style="margin-bottom:16px">⚠ Please fix the highlighted fields below.</div><?php endif; ?>

      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_profile">

        <div class="auth-grid">
          <div class="auth-field">
            <label for="first_name">First Name</label>
            <input type="text" id="first_name" name="first_name" maxlength="80" required value="<?= e($old['first_name']) ?>">
            <?php if (!empty($errors['first_name'])): ?><span class="error"><?= e($errors['first_name']) ?></span><?php endif; ?>
          </div>
          <div class="auth-field">
            <label for="last_name">Last Name</label>
            <input type="text" id="last_name" name="last_name" maxlength="80" required value="<?= e($old['last_name']) ?>">
            <?php if (!empty($errors['last_name'])): ?><span class="error"><?= e($errors['last_name']) ?></span><?php endif; ?>
          </div>
        </div>

        <div class="auth-field">
          <label for="email_display">Email Address</label>
          <input type="email" id="email_display" value="<?= e($customer['email']) ?>" disabled>
          <span class="hint">Contact support to change the email linked to your account.</span>
        </div>

        <div class="auth-field">
          <label for="address_line1">Address Line 1</label>
          <input type="text" id="address_line1" name="address_line1" maxlength="255" required value="<?= e($old['address_line1']) ?>" placeholder="House No, Street">
          <?php if (!empty($errors['address_line1'])): ?><span class="error"><?= e($errors['address_line1']) ?></span><?php endif; ?>
        </div>

        <div class="auth-field">
          <label for="address_line2">Address Line 2 <span class="hint" style="font-weight:400">(optional)</span></label>
          <input type="text" id="address_line2" name="address_line2" maxlength="255" value="<?= e($old['address_line2']) ?>">
        </div>

        <div class="auth-grid">
          <div class="auth-field">
            <label for="province_id">Province</label>
            <select id="province_id" name="province_id" required>
              <option value="">Select Province</option>
              <?php foreach ($provinces as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= (string)$old['province_id'] === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['name_en']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!empty($errors['province_id'])): ?><span class="error"><?= e($errors['province_id']) ?></span><?php endif; ?>
          </div>
          <div class="auth-field">
            <label for="city">City</label>
            <select id="city" name="city" required>
              <option value="">Select Province First</option>
              <?php if ($old['city'] !== ''): ?><option value="<?= e($old['city']) ?>" selected><?= e($old['city']) ?></option><?php endif; ?>
            </select>
            <?php if (!empty($errors['city'])): ?><span class="error"><?= e($errors['city']) ?></span><?php endif; ?>
          </div>
        </div>

        <div class="auth-field">
          <label for="postal_code">Postal Code <span class="hint" style="font-weight:400">(optional)</span></label>
          <input type="text" id="postal_code" name="postal_code" maxlength="10" value="<?= e($old['postal_code']) ?>">
        </div>

        <button type="submit" class="auth-submit">Save Profile</button>
      </form>
    </div>

    <div class="dash-card">
      <h3><i class="fa-solid fa-mobile-screen-button"></i> Contact Number</h3>

      <?php if ($m = flash('mobile_err')): ?><div class="alert alert-error" style="margin-bottom:16px"><?= e($m) ?></div><?php endif; ?>

      <?php if (!$isGoogleMobilePlaceholder): ?>
        <p style="margin-bottom:4px"><strong><?= e($customer['mobile']) ?></strong>
          <?php if (!empty($customer['mobile_verified'])): ?><span class="verified-badge"><i class="fa-solid fa-circle-check"></i> Verified</span><?php endif; ?>
        </p>
        <p class="hint">To change your number, contact support.</p>
      <?php else: ?>
        <p class="hint" style="margin-bottom:14px">Add your mobile number so we can verify you and reach you about your orders.</p>
        <form method="post" id="mobileForm" autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_mobile">
          <input type="hidden" name="otp_verified_mobile" id="otp_verified_mobile" value="">

          <div class="auth-field">
            <label for="mobile">Contact No. (Sri Lanka)</label>
            <div class="mobile-row">
              <input type="tel" id="mobile" name="mobile" placeholder="07XXXXXXXX" maxlength="15" required>
              <button type="button" class="btn-otp" id="sendOtpBtn">Send OTP</button>
            </div>
            <span class="hint">We'll text a 6-digit code to verify this number.</span>

            <div class="otp-box" id="otpBox">
              <label for="otp_code" style="font-size:13px;font-weight:700">Enter verification code</label>
              <input type="text" id="otp_code" inputmode="numeric" maxlength="6" placeholder="••••••">
              <button type="button" class="btn-otp" id="verifyOtpBtn" style="width:100%;margin-top:10px">Verify Code</button>
              <div class="otp-status" id="otpStatus"></div>
            </div>
          </div>

          <button type="submit" class="auth-submit" id="saveMobileBtn" disabled>Save Mobile Number</button>
        </form>
      <?php endif; ?>
    </div>

  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/location-select.js"></script>
<script>
$(function () {
  initProvinceCitySelect({
    provinceSelector: '#province_id',
    citySelector: '#city',
    baseUrl: '<?= BASE_URL ?>',
    selectedCityName: <?= json_encode($old['city']) ?>
  });
});
</script>

<?php if ($isGoogleMobilePlaceholder): ?>
<script>
(function(){
  const sendBtn    = document.getElementById('sendOtpBtn');
  const verifyBtn  = document.getElementById('verifyOtpBtn');
  const otpBox     = document.getElementById('otpBox');
  const otpStatus  = document.getElementById('otpStatus');
  const mobileInput= document.getElementById('mobile');
  const verifiedField = document.getElementById('otp_verified_mobile');
  const saveMobileBtn = document.getElementById('saveMobileBtn');

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
        setStatus('✓ Mobile number verified! Click "Save Mobile Number" below.', true);
        verifiedField.value = mobile;
        mobileInput.readOnly = true;
        saveMobileBtn.disabled = false;
      } else {
        setStatus(data.message || 'Invalid or expired code.', false);
      }
    }).catch(() => setStatus('Network error — please try again.', false));
  });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
