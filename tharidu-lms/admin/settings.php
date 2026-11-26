<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php'; require __DIR__ . '/../inc/sms.php'; require __DIR__ . '/../inc/zoom.php';
require_admin();
function keep_secret(string $key, string $post): void { if ($post !== '') set_setting($key, enc($post)); }
if (is_post()) {
    $act = post('act');
    try {
        if ($act === 'general') {
            foreach (['site_name', 'tagline', 'about_title', 'about_text'] as $k) set_setting($k, post($k));
            if ($f = save_upload('about_image', 'images', IMG_EXT, 5)) { del_upload('images', setting('about_image')); set_setting('about_image', $f['name']); }
            flash('General settings saved.');
        } elseif ($act === 'contact') {
            foreach (['phone', 'whatsapp', 'youtube', 'facebook', 'instagram', 'tiktok'] as $k) {
                $v = post($k);
                if (in_array($k, ['youtube', 'facebook', 'instagram', 'tiktok']) && $v !== '' && !preg_match('~^https?://~i', $v)) throw new RuntimeException(ucfirst($k) . ' link must start with https://');
                set_setting($k, $v);
            }
            flash('Contact & social links saved.');
        } elseif ($act === 'slide_add') {
            $f = save_upload('slide', 'images', IMG_EXT, 8); if (!$f) throw new RuntimeException('Choose an image.');
            q('INSERT INTO slides(image,caption,sort_order) VALUES(?,?,?)', [$f['name'], post('caption') ?: null, (int)post('sort_order')]); flash('Slide added.');
        } elseif ($act === 'slide_del') { $s = row('SELECT * FROM slides WHERE id=?', [(int)post('id')]); if ($s) { del_upload('images', $s['image']); q('DELETE FROM slides WHERE id=?', [$s['id']]); } flash('Slide deleted.');
        } elseif ($act === 'wa_add') {
            if (post('label') === '' || !preg_match('~^https://(chat\.whatsapp\.com|wa\.me|whatsapp\.com)/~i', post('url'))) throw new RuntimeException('Enter a label and a WhatsApp group/invite link (https://chat.whatsapp.com/...).');
            q('INSERT INTO wa_groups(label,url,sort_order) VALUES(?,?,?)', [post('label'), post('url'), (int)post('sort_order')]); flash('WhatsApp group added.');
        } elseif ($act === 'wa_del') { q('DELETE FROM wa_groups WHERE id=?', [(int)post('id')]); flash('Group removed.');
        } elseif ($act === 'payment') { set_setting('bank_details', post('bank_details')); flash('Payment details saved.');
        } elseif ($act === 'sms') {
            set_setting('sms_mode', in_array(post('sms_mode'), ['api', 'get']) ? post('sms_mode') : 'api');
            set_setting('sms_username', post('sms_username')); set_setting('sms_mask', mb_substr(post('sms_mask'), 0, 11));
            set_setting('sms_otp_enabled', isset($_POST['sms_otp_enabled']) ? '1' : '0');
            keep_secret('sms_password', (string)$_POST['sms_password']); keep_secret('sms_key', (string)$_POST['sms_key']);
            set_setting('sms_token', ''); set_setting('sms_token_exp', '0');
            flash('SMS settings saved.');
        } elseif ($act === 'sms_test') {
            $m = norm_mobile(post('test_mobile')); if (!$m) throw new RuntimeException('Enter a valid test mobile number.');
            [$ok, $info] = send_sms($m, 'Test SMS from ' . SITE_NAME . '. SMS settings are working.');
            flash($ok ? 'Test SMS sent ✔' : 'SMS failed: ' . $info, $ok ? 'ok' : 'err');
        } elseif ($act === 'zoom') {
            set_setting('zoom_account_id', post('zoom_account_id')); set_setting('zoom_client_id', post('zoom_client_id'));
            keep_secret('zoom_client_secret', (string)$_POST['zoom_client_secret']); set_setting('zoom_token', ''); set_setting('zoom_token_exp', '0'); flash('Zoom settings saved.');
        } elseif ($act === 'zoom_test') { zoom_token(); flash('Zoom connection OK ✔'); }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('admin/settings');
}
admin_head('Site settings', 'settings');
$slides = rows('SELECT * FROM slides ORDER BY sort_order,id'); $groups = rows('SELECT * FROM wa_groups ORDER BY sort_order,id');
?>
<div class="card"><h3><i data-lucide="info"></i>About us & site details</h3>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="act" value="general">
<div class="two"><?php field('Site name', 'site_name', setting('site_name', SITE_NAME)); field('Hero description (optional)', 'tagline', setting('tagline') === 'Chemistry made clear.' ? '' : setting('tagline')); ?></div>
<?php field('About title', 'about_title', setting('about_title')); field('About us text', 'about_text', setting('about_text'), ['type' => 'textarea', 'rows' => 8, 'hint' => 'Blank line = new paragraph']); ?>
<label class="f">About image<span class="hint">Shown beside the About text on the home page</span><input type="file" name="about_image" accept="image/*"></label>
<?php if (setting('about_image')): ?><img class="thumb" style="width:120px;height:80px" src="<?= img(setting('about_image')) ?>" alt=""><br><br><?php endif; ?>
<button class="btn btn-r">Save</button></form></div><br>

<div class="card"><h3><i data-lucide="images"></i>Home page slides</h3>
<form method="post" enctype="multipart/form-data" class="three"><?= csrf_field() ?><input type="hidden" name="act" value="slide_add">
<label class="f">Slide image<input type="file" name="slide" accept="image/*" required></label><?php field('Caption (optional)', 'caption', ''); field('Order', 'sort_order', 0, ['type' => 'number']); ?><div><button class="btn btn-b">Upload slide</button></div></form><br>
<?php if ($slides): ?><div class="tablewrap"><table><tr><th>Image</th><th>Caption</th><th>Order</th><th></th></tr><?php foreach ($slides as $s): ?>
<tr><td><img class="thumb" src="<?= img($s['image']) ?>" alt=""></td><td><?= e($s['caption']) ?></td><td><?= (int)$s['sort_order'] ?></td>
<td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="slide_del"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Delete slide?">Delete</button></form></td></tr><?php endforeach; ?></table></div>
<?php else: ?><p class="muted small">No slides – the default teacher photo is shown on the home page.</p><?php endif; ?></div><br>

<div class="card"><h3><i data-lucide="message-circle"></i>WhatsApp groups (home page)</h3>
<form method="post" class="three"><?= csrf_field() ?><input type="hidden" name="act" value="wa_add"><?php field('Button label', 'label', '', ['req' => 1, 'attr' => 'placeholder="2027 A/L group"']); field('Group invite link', 'url', '', ['req' => 1, 'type' => 'url', 'attr' => 'placeholder="https://chat.whatsapp.com/..."']); field('Order', 'sort_order', 0, ['type' => 'number']); ?><div><button class="btn btn-b">Add group</button></div></form><br>
<?php foreach ($groups as $g): ?><div class="matitem"><div class="grow"><b><?= e($g['label']) ?></b><div class="small muted"><?= e($g['url']) ?></div></div>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="wa_del"><input type="hidden" name="id" value="<?= $g['id'] ?>"><button class="btn btn-danger btn-sm">Remove</button></form></div><?php endforeach; ?></div><br>

<div class="card"><h3><i data-lucide="phone"></i>Contact & social links</h3>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="contact">
<div class="two"><?php field('Contact phone', 'phone', setting('phone'), ['type' => 'tel']); field('WhatsApp number', 'whatsapp', setting('whatsapp'), ['hint' => 'International format, e.g. 94703957020']);
field('YouTube link', 'youtube', setting('youtube'), ['type' => 'url']); field('Facebook link', 'facebook', setting('facebook'), ['type' => 'url']);
field('Instagram link', 'instagram', setting('instagram'), ['type' => 'url']); field('TikTok link', 'tiktok', setting('tiktok'), ['type' => 'url']); ?></div>
<button class="btn btn-r">Save</button></form></div><br>

<div class="card"><h3><i data-lucide="landmark"></i>Offline payment details</h3>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="payment"><?php field('Bank / payment instructions shown to students', 'bank_details', setting('bank_details'), ['type' => 'textarea', 'rows' => 5, 'hint' => 'Bank, account name, number, branch…']); ?><button class="btn btn-r">Save</button></form></div><br>

<div class="card" id="sms"><h3><i data-lucide="message-square"></i>SMS settings (Dialog eSMS)</h3>
<p class="muted small">Register at <a href="https://e-sms.dialog.lk" target="_blank" rel="noopener"><u>e-sms.dialog.lk</u></a>. OTP codes are sent to students when they create an account.</p><br>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="sms">
<label class="chk"><input type="checkbox" name="sms_otp_enabled" <?= sms_enabled() ? 'checked' : '' ?>>Enable OTP verification for student registration</label>
<label class="f">Sending method<select name="sms_mode" data-toggle="sm"><option value="api" <?= setting('sms_mode', 'api') === 'api' ? 'selected' : '' ?>>API (username + password, recommended)</option><option value="get" <?= setting('sms_mode') === 'get' ? 'selected' : '' ?>>GET URL key (esmsqk)</option></select></label>
<div data-show-for="sm:api" class="two"><?php field('eSMS username', 'sms_username', setting('sms_username')); field('eSMS password', 'sms_password', '', ['type' => 'password', 'hint' => setting('sms_password') ? 'Saved (leave empty to keep)' : 'Not set', 'attr' => 'autocomplete="new-password"']); ?></div>
<div data-show-for="sm:get" hidden><?php field('URL message key (esmsqk)', 'sms_key', '', ['type' => 'password', 'hint' => setting('sms_key') ? 'Saved (leave empty to keep)' : 'Generate in the eSMS portal → URL Message Key', 'attr' => 'autocomplete="new-password"']); ?></div>
<?php field('Sender mask / source address', 'sms_mask', setting('sms_mask'), ['attr' => 'maxlength="11"', 'hint' => 'Max 11 characters. Empty = your default mask.']); ?>
<button class="btn btn-r">Save SMS settings</button></form><br>
<form method="post" class="two"><?= csrf_field() ?><input type="hidden" name="act" value="sms_test"><?php field('Send a test SMS to', 'test_mobile', '', ['type' => 'tel', 'attr' => 'placeholder="07XXXXXXXX"']); ?><div style="align-self:end;margin-bottom:14px"><button class="btn btn-o">Send test</button></div></form>
<?php $log = rows('SELECT * FROM sms_log ORDER BY id DESC LIMIT 8'); if ($log): ?><div class="tablewrap"><table><tr><th>Time</th><th>To</th><th>Result</th></tr><?php foreach ($log as $x): ?>
<tr><td class="small"><?= e(date('d M H:i', strtotime($x['created_at']))) ?></td><td><?= e(show_mobile($x['mobile'])) ?></td><td><span class="pill <?= $x['ok'] ? 'ok' : 'err' ?>"><?= $x['ok'] ? 'sent' : 'failed' ?></span> <span class="small muted"><?= e($x['response']) ?></span></td></tr><?php endforeach; ?></table></div><?php endif; ?></div><br>

<div class="card" id="zoom"><h3><i data-lucide="video"></i>Zoom settings</h3>
<p class="muted small">Zoom Marketplace → Develop → Build App → <b>Server-to-Server OAuth</b>. Add the scope <code>meeting:write:meeting:admin</code>, activate the app, then paste the three values.</p><br>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="zoom"><div class="three"><?php field('Account ID', 'zoom_account_id', setting('zoom_account_id')); field('Client ID', 'zoom_client_id', setting('zoom_client_id'));
field('Client secret', 'zoom_client_secret', '', ['type' => 'password', 'hint' => setting('zoom_client_secret') ? 'Saved (leave empty to keep)' : 'Not set', 'attr' => 'autocomplete="new-password"']); ?></div>
<button class="btn btn-r">Save Zoom settings</button></form><br>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="zoom_test"><button class="btn btn-o">Test Zoom connection</button></form></div>
<?php admin_foot();
