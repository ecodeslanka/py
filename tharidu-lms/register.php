<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/sms.php';
if (me()) redirect('dashboard');

function create_student(array $d): int {
    q('INSERT INTO users(role,name,mobile,nic,password_hash,batch_id,class_type,class_id) VALUES("student",?,?,?,?,?,?,?)',
      [$d['name'], $d['mobile'], $d['nic'] ?: null, $d['hash'], $d['batch_id'], $d['class_type'], $d['class_id']]);
    return (int)db()->lastInsertId();
}
$batches = rows('SELECT * FROM batches WHERE active=1 ORDER BY sort_order,id');
$classes = rows('SELECT * FROM classes WHERE active=1 ORDER BY sort_order,id');
$stage = isset($_GET['verify']) && isset($_SESSION['pending_reg']) ? 'verify' : 'form';
$old = ['name' => '', 'mobile' => '', 'nic' => '', 'class_type' => 'physical', 'batch_id' => (int)($_GET['batch'] ?? ($batches[0]['id'] ?? 0)), 'class_id' => 0];

if (is_post()) {
    $act = post('act');
    if ($act === 'verify' && isset($_SESSION['pending_reg'])) {
        $p = $_SESSION['pending_reg'];
        if (!throttle_ok($p['mobile'])) { flash('Too many attempts. Try again later.', 'err'); }
        elseif (otp_check($p['mobile'], post('otp'))) {
            if (val('SELECT 1 FROM users WHERE mobile=?', [$p['mobile']])) { flash('This mobile is already registered.', 'err'); unset($_SESSION['pending_reg']); redirect('login'); }
            $id = create_student($p); unset($_SESSION['pending_reg']); login_user($id);
            flash('Account created. Choose your lesson below and send your payment.');
            redirect('dashboard');
        } else { fail_attempt($p['mobile']); flash('Wrong or expired code.', 'err'); }
        redirect('register?verify=1');
    }
    if ($act === 'resend' && isset($_SESSION['pending_reg'])) {
        [$ok, $m] = otp_send($_SESSION['pending_reg']['mobile']); flash($ok ? 'A new code was sent.' : $m, $ok ? 'ok' : 'err');
        redirect('register?verify=1');
    }
    if ($act === 'cancel') { unset($_SESSION['pending_reg']); redirect('register'); }

    // main form
    $old = ['name' => post('name'), 'mobile' => post('mobile'), 'nic' => post('nic'), 'class_type' => post('class_type'),
        'batch_id' => (int)post('batch_id'), 'class_id' => (int)post('class_id')];
    $mobile = norm_mobile($old['mobile']); $err = [];
    if (mb_strlen($old['name']) < 2) $err[] = 'Please enter your name.';
    if (!$mobile) $err[] = 'Enter a valid Sri Lankan mobile number (e.g. 0771234567).';
    elseif (val('SELECT 1 FROM users WHERE mobile=?', [$mobile])) $err[] = 'This mobile number is already registered. Please log in.';
    if ($old['nic'] !== '' && !preg_match('/^(\d{9}[vVxX]|\d{12})$/', $old['nic'])) $err[] = 'NIC looks invalid (old: 9 digits + V, new: 12 digits).';
    if (!in_array($old['class_type'], ['physical', 'online'], true)) $err[] = 'Choose a class type.';
    if (!val('SELECT 1 FROM batches WHERE id=? AND active=1', [$old['batch_id']])) $err[] = 'Choose a batch.';
    if ($old['class_type'] === 'physical' && !val('SELECT 1 FROM classes WHERE id=? AND active=1', [$old['class_id']])) $err[] = 'Please select your class.';
    if (strlen(post('password')) < 6) $err[] = 'Password must be at least 6 characters.';
    if (post('password') !== post('password2')) $err[] = 'Passwords do not match.';
    if ($err) { foreach ($err as $x) flash($x, 'err'); }
    else {
        $d = ['name' => $old['name'], 'mobile' => $mobile, 'nic' => strtoupper($old['nic']), 'hash' => password_hash(post('password'), PASSWORD_DEFAULT),
            'batch_id' => $old['batch_id'], 'class_type' => $old['class_type'], 'class_id' => $old['class_type'] === 'physical' ? $old['class_id'] : null];
        if (sms_enabled()) {
            [$ok, $m] = otp_send($mobile);
            if ($ok) { $_SESSION['pending_reg'] = $d; flash('We sent a 6-digit code to ' . show_mobile($mobile) . '.'); redirect('register?verify=1'); }
            flash($m, 'err');
        } else {
            $id = create_student($d); login_user($id); flash('Account created. Choose your lesson and send your payment.');
            redirect('dashboard');
        }
    }
}
head_html('Create account'); site_nav(); ?>
<main class="band appmain"><div class="authwrap">
<?php flash_html(); ?>
<?php if ($stage === 'verify'): $pm = $_SESSION['pending_reg']['mobile']; ?>
  <div class="card"><h3><i data-lucide="shield-check"></i>Verify your mobile</h3>
    <p class="muted">Enter the 6-digit code sent to <b><?= e(show_mobile($pm)) ?></b>. It is valid for 5 minutes.</p><br>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="verify">
      <label class="f">OTP code<input name="otp" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required autofocus style="font-size:24px;letter-spacing:.4em;text-align:center"></label>
      <button class="btn btn-r btn-block">Verify &amp; create account</button></form><br>
    <div class="two"><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="resend"><button class="btn btn-o btn-block btn-sm">Resend code</button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="cancel"><button class="btn btn-o btn-block btn-sm">Change number</button></form></div>
  </div>
<?php else: ?>
  <div class="card"><h3><i data-lucide="user-plus"></i>Create student account</h3>
  <form method="post" autocomplete="on"><?= csrf_field() ?>
    <?php field('Full name', 'name', $old['name'], ['req' => 1, 'attr' => 'autocomplete="name"']); ?>
    <div class="two">
      <?php field('Mobile number', 'mobile', $old['mobile'], ['req' => 1, 'type' => 'tel', 'attr' => 'inputmode="tel" placeholder="07XXXXXXXX" autocomplete="tel"', 'hint' => sms_enabled() ? 'We will SMS a verification code' : '']); ?>
      <?php field('NIC (optional)', 'nic', $old['nic'], ['attr' => 'maxlength="12"']); ?>
    </div>
    <div class="f" style="margin-bottom:0">Class type</div>
    <div class="seg">
      <label><input type="radio" name="class_type" value="physical" data-toggle="ct" <?= $old['class_type'] === 'physical' ? 'checked' : '' ?>><i data-lucide="building-2"></i>Physical class</label>
      <label><input type="radio" name="class_type" value="online" data-toggle="ct" <?= $old['class_type'] === 'online' ? 'checked' : '' ?>><i data-lucide="monitor-play"></i>Online class</label>
    </div>
    <label class="f">Batch<select name="batch_id" required>
      <?php foreach ($batches as $b) echo '<option value="' . $b['id'] . '"' . ($b['id'] == $old['batch_id'] ? ' selected' : '') . '>' . e($b['name']) . '</option>'; ?></select></label>
    <div data-show-for="ct:physical"><label class="f">Class<span class="hint">Select where you attend (e.g. Senara – Kegalle)</span>
      <select name="class_id"><option value="0">— Select your class —</option>
      <?php foreach ($classes as $c) echo '<option value="' . $c['id'] . '"' . ($c['id'] == $old['class_id'] ? ' selected' : '') . '>' . e($c['name']) . '</option>'; ?></select></label></div>
    <div class="two">
      <?php field('Password', 'password', '', ['type' => 'password', 'req' => 1, 'attr' => 'minlength="6" autocomplete="new-password"']); ?>
      <?php field('Confirm password', 'password2', '', ['type' => 'password', 'req' => 1, 'attr' => 'minlength="6" autocomplete="new-password"']); ?>
    </div>
    <button class="btn btn-r btn-block"><i data-lucide="arrow-right"></i><?= sms_enabled() ? 'Send verification code' : 'Create account' ?></button>
    <p class="muted small" style="text-align:center;margin-top:14px">Already registered? <a href="<?= url('login') ?>" style="color:var(--r);font-weight:700">Log in</a></p>
  </form></div>
<?php endif; ?>
</div></main><?php foot_html();
