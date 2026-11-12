<?php
include 'config.php';
include 'auth.php';
requireLogin();
require_once __DIR__ . '/mail_lib.php';
require_once __DIR__ . '/activity_lib.php';
ml_ensure(); al_ensure();

$msg = ''; $err = '';
$S = ml_get_all();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? 'save';
    if (!rbac_csrf_check()) { $err = 'Session expired. Please try again.'; }
    elseif ($do === 'save' || $do === 'test') {
        $enc = in_array($_POST['smtp_encryption'] ?? '', ['ssl', 'tls', 'none'], true) ? $_POST['smtp_encryption'] : 'ssl';
        $fe  = trim($_POST['from_email'] ?? ''); $rt = trim($_POST['reply_to'] ?? '');
        if ($fe !== '' && !filter_var($fe, FILTER_VALIDATE_EMAIL)) $err = 'From e-mail is not a valid address.';
        elseif ($rt !== '' && !filter_var($rt, FILTER_VALIDATE_EMAIL)) $err = 'Reply-to e-mail is not a valid address.';
        else {
            $new = ['smtp_host' => trim($_POST['smtp_host'] ?? ''), 'smtp_port' => (string)max(1, (int)($_POST['smtp_port'] ?? 465)), 'smtp_encryption' => $enc,
                    'smtp_user' => trim($_POST['smtp_user'] ?? ''), 'from_email' => $fe, 'from_name' => trim($_POST['from_name'] ?? '') ?: 'YMS', 'reply_to' => $rt,
                    'reset_minutes' => (string)min(1440, max(10, (int)($_POST['reset_minutes'] ?? 60))), 'enabled' => isset($_POST['enabled']) ? '1' : '0'];
            if (($_POST['smtp_pass'] ?? '') !== '') $new['smtp_pass'] = $_POST['smtp_pass'];      // blank = keep the saved password
            foreach ($new as $k => $v) ml_set($k, $v);
            $S = ml_get_all();
            al_event('email_settings_saved', 'host ' . $S['smtp_host'] . ':' . $S['smtp_port']);
            $msg = 'E-mail settings saved.';
            if ($do === 'test') {
                $to = trim($_POST['test_to'] ?? '');
                $e2 = '';
                if (ml_send($to, 'Test e-mail from the system', ml_layout('E-mail is working', '<p>This is a test message. Your SMTP settings are correct and the <b>Forgot Password</b> e-mails will be delivered.</p><p style="color:#6b7280;font-size:12px">Sent ' . date('Y-m-d H:i') . '</p>'), $e2))
                    $msg .= ' Test e-mail sent to ' . htmlspecialchars($to) . ' — check the inbox (and spam).';
                else $err = 'Settings saved, but the test e-mail failed: ' . htmlspecialchars($e2);
            }
        }
    }
}
$users_no_mail = (int)(mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM users WHERE active = 1 AND (email IS NULL OR email = '')"))[0] ?? 0);
include 'header.php';
$csrf = rbac_csrf_token();
?>
<style>
.es{max-width:900px;margin:0 auto;padding:18px 16px;font-family:'Inter',system-ui,sans-serif}.es h2{margin:0 0 4px;font-size:22px}.es .sub{color:#6b7280;margin:0 0 18px;font-size:13.5px}
.es .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:16px}.es .card h3{margin:0 0 14px;font-size:15px;display:flex;gap:8px;align-items:center}
.es .g{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px}.es label{display:block;font-size:12.5px;font-weight:600;color:#374151;margin-bottom:5px}
.es input[type=text],.es input[type=email],.es input[type=number],.es input[type=password],.es select{width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:8px;font:inherit;font-size:14px}
.es .btn{padding:10px 18px;border:none;border-radius:8px;font:600 13.5px inherit;cursor:pointer;background:#4f46e5;color:#fff}.es .btn.o{background:#fff;color:#4f46e5;border:1.5px solid #c7d2fe}
.es .al{padding:11px 14px;border-radius:9px;margin-bottom:14px;font-size:13.5px}.es .ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}.es .er{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.es .wa{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.es .hint{font-size:12px;color:#6b7280;margin-top:4px}.es .pw{position:relative}.es .pw button{position:absolute;right:6px;top:50%;transform:translateY(-50%);border:none;background:none;cursor:pointer;color:#6b7280;padding:6px}
</style>
<div class="es">
  <h2><i class="fa-solid fa-envelope-circle-check" style="color:#4f46e5"></i> E-mail Setup</h2>
  <p class="sub">SMTP account the system uses to send e-mails — currently the <b>Forgot Password</b> reset link. Users need an e-mail address on the Users page.</p>
  <?php if ($msg): ?><div class="al ok"><i class="fa-solid fa-circle-check"></i> <?php echo $msg; ?></div><?php endif; ?>
  <?php if ($err): ?><div class="al er"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $err; ?></div><?php endif; ?>
  <?php if (!ml_configured()): ?><div class="al wa"><i class="fa-solid fa-triangle-exclamation"></i> E-mail is not fully set up (host, user and password are needed) — Forgot Password cannot send mail yet.</div><?php endif; ?>
  <?php if ($users_no_mail): ?><div class="al wa"><i class="fa-regular fa-envelope"></i> <?php echo $users_no_mail; ?> active user(s) have no e-mail address, so they cannot use Forgot Password. Add it on the <a href="users.php">Users</a> page.</div><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
    <div class="card"><h3><i class="fa-solid fa-server"></i> SMTP server</h3><div class="g">
      <div><label>SMTP host</label><input type="text" name="smtp_host" value="<?php echo htmlspecialchars($S['smtp_host']); ?>" placeholder="smtp.hostinger.com"></div>
      <div><label>Port</label><input type="number" name="smtp_port" value="<?php echo htmlspecialchars($S['smtp_port']); ?>"><div class="hint">465 = SSL · 587 = TLS</div></div>
      <div><label>Encryption</label><select name="smtp_encryption"><?php foreach (['ssl' => 'SSL (465)', 'tls' => 'TLS / STARTTLS (587)', 'none' => 'None (25)'] as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $S['smtp_encryption'] === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
      <div><label>SMTP user (login)</label><input type="text" name="smtp_user" value="<?php echo htmlspecialchars($S['smtp_user']); ?>" placeholder="noreply@yourcompany.com"></div>
      <div><label>SMTP password</label><div class="pw"><input type="password" name="smtp_pass" id="sp" placeholder="<?php echo $S['smtp_pass'] !== '' ? '•••••••• (saved — leave blank to keep)' : 'Enter password'; ?>"><button type="button" onclick="var e=document.getElementById('sp');e.type=e.type==='password'?'text':'password'"><i class="fa-solid fa-eye"></i></button></div></div>
    </div></div>
    <div class="card"><h3><i class="fa-solid fa-paper-plane"></i> Sender &amp; reset link</h3><div class="g">
      <div><label>From name</label><input type="text" name="from_name" value="<?php echo htmlspecialchars($S['from_name']); ?>"></div>
      <div><label>From e-mail</label><input type="email" name="from_email" value="<?php echo htmlspecialchars($S['from_email']); ?>" placeholder="same as SMTP user"></div>
      <div><label>Reply-to (optional)</label><input type="email" name="reply_to" value="<?php echo htmlspecialchars($S['reply_to']); ?>"></div>
      <div><label>Reset link valid for (minutes)</label><input type="number" name="reset_minutes" min="10" max="1440" value="<?php echo htmlspecialchars($S['reset_minutes']); ?>"></div>
    </div><label style="margin-top:14px;display:flex;gap:8px;align-items:center"><input type="checkbox" name="enabled" value="1" <?php echo $S['enabled'] === '1' ? 'checked' : ''; ?>> E-mail sending is ON</label></div>
    <div class="card"><h3><i class="fa-solid fa-vial"></i> Send a test e-mail</h3>
      <div class="g"><div><label>Send test to</label><input type="email" name="test_to" placeholder="you@company.com"></div></div>
      <div class="hint">Saves the settings above, then sends a test mail.</div></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn" name="do" value="save"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
      <button class="btn o" name="do" value="test" onclick="if(!this.form.test_to.value){alert('Enter an e-mail address to send the test to.');return false}"><i class="fa-solid fa-paper-plane"></i> Save &amp; send test</button>
    </div>
  </form>
</div>
<?php include 'footer.php'; ?>
