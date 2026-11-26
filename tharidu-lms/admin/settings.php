<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php'; require __DIR__ . '/../inc/sms.php'; require __DIR__ . '/../inc/zoom.php'; require __DIR__ . '/../inc/ai.php';
require_admin();
function keep_secret(string $key, string $post): void { if ($post !== '') set_setting($key, enc($post)); }
if (is_post()) {
    $act = post('act');
    try {
        if ($act === 'general') {
            set_setting('site_name', post('site_name'));
            flash('Saved.');
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
        } elseif ($act === 'zoom_test') { zoom_token(); flash('Zoom connection OK ✔');
        } elseif ($act === 'ai') {
            set_setting('bot_enabled', isset($_POST['bot_enabled']) ? '1' : '0'); set_setting('bot_images', isset($_POST['bot_images']) ? '1' : '0');
            set_setting('bot_name', post('bot_name') ?: 'ECODES AI'); set_setting('bot_icon', mb_substr(post('bot_icon'), 0, 8) ?: '🧪'); set_setting('bot_greeting', post('bot_greeting'));
            set_setting('gemini_model', preg_replace('/[^A-Za-z0-9._-]/', '', post('gemini_model')) ?: 'gemini-2.5-flash');
            set_setting('bot_daily_limit', (string)max(1, min(500, (int)post('bot_daily_limit')))); set_setting('bot_max_img_mb', (string)max(1, min(10, (int)post('bot_max_img_mb'))));
            set_setting('bot_history', (string)max(0, min(12, (int)post('bot_history')))); set_setting('bot_extra_prompt', post('bot_extra_prompt'));
            keep_secret('gemini_key', (string)$_POST['gemini_key']);
            if (!empty($_POST['reset_bot_icon_img'])) { del_upload('images', setting('bot_icon_img')); set_setting('bot_icon_img', ''); }
            if ($f = save_upload('bot_icon_img', 'images', IMG_EXT, 2)) { del_upload('images', setting('bot_icon_img')); set_setting('bot_icon_img', $f['name']); }
            flash('AI chatbot settings saved.');
        } elseif ($act === 'ai_test') {
            [$ok, $txt] = gemini_ask('Say hello to the student in one short Sinhala sentence, then state the molar mass of H2O.', null, [], 'si');
            flash($ok ? 'Gemini replied ✔  ' . mb_substr(strip_tags($txt), 0, 220) : 'Gemini failed: ' . $txt, $ok ? 'ok' : 'err');
        } elseif ($act === 'player') {
            set_setting('wm_enabled', isset($_POST['wm_enabled']) ? '1' : '0');
            set_setting('wm_text', post('wm_text') ?: '{name} · {mobile}');
            set_setting('wm_mode', in_array(post('wm_mode'), ['updown', 'random', 'scroll', 'static']) ? post('wm_mode') : 'updown');
            set_setting('wm_pos', in_array(post('wm_pos'), ['left', 'center', 'right']) ? post('wm_pos') : 'right');
            set_setting('wm_speed', (string)max(3, min(120, (int)post('wm_speed')))); set_setting('wm_opacity', (string)min(1, max(.1, (float)post('wm_opacity'))));
            set_setting('wm_size', (string)max(9, min(40, (int)post('wm_size')))); set_setting('wm_color', preg_match('/^#[0-9a-fA-F]{6}$/', post('wm_color')) ? post('wm_color') : '#ffffff');
            foreach (['player_autofs', 'player_devtools', 'player_brand', 'player_speedctl'] as $k) set_setting($k, isset($_POST[$k]) ? '1' : '0');
            flash('Player settings saved.');
        }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    $tabOf = ['general' => 'general', 'contact' => 'general', 'slide_add' => 'home', 'slide_del' => 'home', 'wa_add' => 'home', 'wa_del' => 'home', 'payment' => 'payment', 'sms' => 'sms', 'sms_test' => 'sms', 'zoom' => 'zoom', 'zoom_test' => 'zoom', 'ai' => 'ai', 'ai_test' => 'ai', 'player' => 'player'];
    redirect('admin/settings?tab=' . ($tabOf[$act] ?? 'general'));
}
admin_head('Settings', 'settings');
$tab = $_GET['tab'] ?? 'general';
$slides = rows('SELECT * FROM slides ORDER BY sort_order,id'); $groups = rows('SELECT * FROM wa_groups ORDER BY sort_order,id');
?>
<div class="apptabs"><?php foreach ([['general','General'],['home','Slides & WhatsApp'],['payment','Payment'],['sms','SMS'],['zoom','Zoom'],['ai','AI Chatbot'],['player','Player']] as [$k, $l]): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="?tab=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>
<?php if ($tab === 'general'): ?>
<div class="card"><h3><i data-lucide="info"></i>Site name</h3>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="general"><?php field('Site name', 'site_name', setting('site_name', SITE_NAME)); ?>
<p class="muted small">The about text, hero, sections and photos are edited under <a href="<?= url('admin/home') ?>" style="color:var(--blue)"><b>Home page</b></a>.</p><br><button class="btn">Save</button></form></div><br>
<br>
<div class="card"><h3><i data-lucide="phone"></i>Contact & social links</h3>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="contact">
<div class="two"><?php field('Contact phone', 'phone', setting('phone'), ['type' => 'tel']); field('WhatsApp number', 'whatsapp', setting('whatsapp'), ['hint' => 'International format, e.g. 94703957020']);
field('YouTube link', 'youtube', setting('youtube'), ['type' => 'url']); field('Facebook link', 'facebook', setting('facebook'), ['type' => 'url']);
field('Instagram link', 'instagram', setting('instagram'), ['type' => 'url']); field('TikTok link', 'tiktok', setting('tiktok'), ['type' => 'url']); ?></div>
<button class="btn">Save</button></form></div><br>
<?php endif; ?>
<?php if ($tab === 'home'): ?>
<div class="card"><h3><i data-lucide="images"></i>Home page slides</h3>
<form method="post" enctype="multipart/form-data" class="three"><?= csrf_field() ?><input type="hidden" name="act" value="slide_add">
<label class="f">Slide image<input type="file" name="slide" accept="image/*" required></label><?php field('Caption (optional)', 'caption', ''); field('Order', 'sort_order', 0, ['type' => 'number']); ?><div><button class="btn">Upload slide</button></div></form><br>
<?php if ($slides): ?><div class="tablewrap"><table><tr><th>Image</th><th>Caption</th><th>Order</th><th></th></tr><?php foreach ($slides as $s): ?>
<tr><td><img class="thumb" src="<?= img($s['image']) ?>" alt=""></td><td><?= e($s['caption']) ?></td><td><?= (int)$s['sort_order'] ?></td>
<td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="slide_del"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Delete slide?">Delete</button></form></td></tr><?php endforeach; ?></table></div>
<?php else: ?><p class="muted small">No slides – the default teacher photo is shown on the home page.</p><?php endif; ?></div><br>
<br>
<div class="card"><h3><i data-lucide="message-circle"></i>WhatsApp groups (home page)</h3>
<form method="post" class="three"><?= csrf_field() ?><input type="hidden" name="act" value="wa_add"><?php field('Button label', 'label', '', ['req' => 1, 'attr' => 'placeholder="2027 A/L group"']); field('Group invite link', 'url', '', ['req' => 1, 'type' => 'url', 'attr' => 'placeholder="https://chat.whatsapp.com/..."']); field('Order', 'sort_order', 0, ['type' => 'number']); ?><div><button class="btn">Add group</button></div></form><br>
<?php foreach ($groups as $g): ?><div class="matitem"><div class="grow"><b><?= e($g['label']) ?></b><div class="small muted"><?= e($g['url']) ?></div></div>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="wa_del"><input type="hidden" name="id" value="<?= $g['id'] ?>"><button class="btn btn-danger btn-sm">Remove</button></form></div><?php endforeach; ?></div><br>
<?php endif; ?>
<?php if ($tab === 'payment'): ?>
<div class="card"><h3><i data-lucide="landmark"></i>Offline payment details</h3>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="payment"><?php field('Bank / payment instructions shown to students', 'bank_details', setting('bank_details'), ['type' => 'textarea', 'rows' => 5, 'hint' => 'Bank, account name, number, branch…']); ?><button class="btn">Save</button></form></div><br>
<?php endif; ?>
<?php if ($tab === 'sms'): ?>
<div class="card" id="sms"><h3><i data-lucide="message-square"></i>SMS settings (Dialog eSMS)</h3>
<p class="muted small">Register at <a href="https://e-sms.dialog.lk" target="_blank" rel="noopener"><u>e-sms.dialog.lk</u></a>. OTP codes are sent to students when they create an account.</p><br>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="sms">
<label class="chk"><input type="checkbox" name="sms_otp_enabled" <?= sms_enabled() ? 'checked' : '' ?>>Enable OTP verification for student registration</label>
<label class="f">Sending method<select name="sms_mode" data-toggle="sm"><option value="api" <?= setting('sms_mode', 'api') === 'api' ? 'selected' : '' ?>>API (username + password, recommended)</option><option value="get" <?= setting('sms_mode') === 'get' ? 'selected' : '' ?>>GET URL key (esmsqk)</option></select></label>
<div data-show-for="sm:api" class="two"><?php field('eSMS username', 'sms_username', setting('sms_username')); field('eSMS password', 'sms_password', '', ['type' => 'password', 'hint' => setting('sms_password') ? 'Saved (leave empty to keep)' : 'Not set', 'attr' => 'autocomplete="new-password"']); ?></div>
<div data-show-for="sm:get" hidden><?php field('URL message key (esmsqk)', 'sms_key', '', ['type' => 'password', 'hint' => setting('sms_key') ? 'Saved (leave empty to keep)' : 'Generate in the eSMS portal → URL Message Key', 'attr' => 'autocomplete="new-password"']); ?></div>
<?php field('Sender mask / source address', 'sms_mask', setting('sms_mask'), ['attr' => 'maxlength="11"', 'hint' => 'Max 11 characters. Empty = your default mask.']); ?>
<button class="btn">Save SMS settings</button></form><br>
<form method="post" class="two"><?= csrf_field() ?><input type="hidden" name="act" value="sms_test"><?php field('Send a test SMS to', 'test_mobile', '', ['type' => 'tel', 'attr' => 'placeholder="07XXXXXXXX"']); ?><div style="align-self:end;margin-bottom:14px"><button class="btn btn-o">Send test</button></div></form>
<?php $log = rows('SELECT * FROM sms_log ORDER BY id DESC LIMIT 8'); if ($log): ?><div class="tablewrap"><table><tr><th>Time</th><th>To</th><th>Result</th></tr><?php foreach ($log as $x): ?>
<tr><td class="small"><?= e(date('d M H:i', strtotime($x['created_at']))) ?></td><td><?= e(show_mobile($x['mobile'])) ?></td><td><span class="pill <?= $x['ok'] ? 'ok' : 'err' ?>"><?= $x['ok'] ? 'sent' : 'failed' ?></span> <span class="small muted"><?= e($x['response']) ?></span></td></tr><?php endforeach; ?></table></div><?php endif; ?></div><br>
<?php endif; ?>
<?php if ($tab === 'zoom'): ?>
<div class="card" id="zoom"><h3><i data-lucide="video"></i>Zoom settings</h3>
<p class="muted small">Zoom Marketplace → Develop → Build App → <b>Server-to-Server OAuth</b>. Add the scope <code>meeting:write:meeting:admin</code>, activate the app, then paste the three values.</p><br>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="zoom"><div class="three"><?php field('Account ID', 'zoom_account_id', setting('zoom_account_id')); field('Client ID', 'zoom_client_id', setting('zoom_client_id'));
field('Client secret', 'zoom_client_secret', '', ['type' => 'password', 'hint' => setting('zoom_client_secret') ? 'Saved (leave empty to keep)' : 'Not set', 'attr' => 'autocomplete="new-password"']); ?></div>
<button class="btn">Save Zoom settings</button></form><br>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="zoom_test"><button class="btn btn-o">Test Zoom connection</button></form></div>
<?php endif; ?>
<?php if ($tab === 'ai'): ?>
<div class="card"><h3><i data-lucide="sparkles"></i>AI chatbot (Google Gemini)</h3>
<p class="muted small">Get a free key at <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener"><u>aistudio.google.com/apikey</u></a>. Students ask A/L chemistry questions (Sinhala is the default language, English also works) and can attach a photo. The bot refuses non-chemistry questions.</p><br>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="act" value="ai">
<label class="chk"><input type="checkbox" name="bot_enabled" <?= setting('bot_enabled', '1') === '1' ? 'checked' : '' ?>>Enable the chatbot for students</label>
<div class="two"><?php field('Gemini API key', 'gemini_key', '', ['type' => 'password', 'hint' => setting('gemini_key') ? 'Saved (leave empty to keep)' : 'Not set – the bot stays hidden until a key is saved', 'attr' => 'autocomplete="new-password"']);
field('Gemini model', 'gemini_model', setting('gemini_model', 'gemini-2.5-flash'), ['attr' => 'list="gm"', 'hint' => 'e.g. gemini-2.5-flash (fast) · gemini-2.5-pro (smarter)']); ?></div>
<datalist id="gm"><option value="gemini-2.5-flash"><option value="gemini-2.5-flash-lite"><option value="gemini-2.5-pro"><option value="gemini-2.0-flash"></datalist>
<div class="three"><?php field('Questions per student per day', 'bot_daily_limit', setting('bot_daily_limit', '10'), ['type' => 'number', 'attr' => 'min="1" max="500"', 'hint' => 'Resets at midnight (Sri Lanka time)']);
field('Max image size (MB)', 'bot_max_img_mb', setting('bot_max_img_mb', '4'), ['type' => 'number', 'attr' => 'min="1" max="10"']);
field('Conversation memory (messages)', 'bot_history', setting('bot_history', '6'), ['type' => 'number', 'attr' => 'min="0" max="12"', 'hint' => '0 = every question independent']); ?></div>
<label class="chk"><input type="checkbox" name="bot_images" <?= setting('bot_images', '1') === '1' ? 'checked' : '' ?>>Allow students to upload an image with the question</label>
<div class="sec-t" style="margin-top:8px">Bot identity</div>
<div class="three"><?php field('Bot name', 'bot_name', setting('bot_name', 'ECODES AI')); field('Icon (emoji)', 'bot_icon', setting('bot_icon', '🧪'), ['attr' => 'maxlength="8"', 'hint' => 'Used when no icon image is uploaded']); ?>
<label class="f">Icon image (optional)<span class="hint">Square PNG/JPG, max 2MB</span><input type="file" name="bot_icon_img" accept="image/*"></label></div>
<?php if (setting('bot_icon_img')): ?><div style="margin-bottom:12px"><?= bot_icon_html() ?> <label class="chk" style="display:inline-flex;margin-left:10px"><input type="checkbox" name="reset_bot_icon_img">Remove icon image</label></div><?php endif; ?>
<?php field('Welcome message', 'bot_greeting', setting('bot_greeting', bot_greeting()), ['type' => 'textarea', 'rows' => 3, 'hint' => 'Use {name} for the bot name. Sinhala or English.']);
field('Extra instructions for the bot (optional)', 'bot_extra_prompt', setting('bot_extra_prompt'), ['type' => 'textarea', 'rows' => 4, 'hint' => 'e.g. “Follow the Sri Lankan A/L syllabus and use the notation of past papers.”']); ?>
<button class="btn">Save chatbot settings</button></form><br>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="ai_test"><button class="btn btn-o"><i data-lucide="zap"></i>Test Gemini connection</button></form></div>
<?php
$today = (int)val("SELECT COUNT(*) FROM chat_messages WHERE role='user' AND DATE(created_at)=CURDATE()");
$top = rows("SELECT u.name,u.mobile,COUNT(*) c FROM chat_messages m JOIN users u ON u.id=m.user_id WHERE m.role='user' AND DATE(m.created_at)=CURDATE() GROUP BY u.id ORDER BY c DESC LIMIT 5");
$recent = rows("SELECT m.*,u.name FROM chat_messages m JOIN users u ON u.id=m.user_id WHERE m.role='user' ORDER BY m.id DESC LIMIT 12"); ?>
<br><div class="card"><h3><i data-lucide="bar-chart-3"></i>Usage</h3>
<div class="stats" style="margin-bottom:10px"><div class="stat"><div class="si-i"><i data-lucide="message-circle"></i></div><b><?= $today ?></b><span>Questions today</span></div>
<?php foreach ($top as $t): ?><div class="stat"><b><?= (int)$t['c'] ?>/<?= bot_limit() ?></b><span><?= e($t['name']) ?></span></div><?php endforeach; ?></div>
<?php if ($recent): ?><div class="tablewrap"><table><tr><th>When</th><th>Student</th><th>Question</th></tr><?php foreach ($recent as $r): ?>
<tr><td class="small muted"><?= e(date('d M H:i', strtotime($r['created_at']))) ?></td><td><?= e($r['name']) ?></td><td><?= e(mb_strimwidth($r['content'], 0, 90, '…')) ?><?php if ($r['image']): ?> <a href="<?= url('chat_img?id=' . $r['id']) ?>" target="_blank" class="pill blue">📷 image</a><?php endif; ?></td></tr><?php endforeach; ?></table></div>
<?php else: ?><p class="muted small">No questions yet.</p><?php endif; ?></div>
<?php endif; ?>
<?php if ($tab === 'player'): ?>
<div class="card"><h3><i data-lucide="play"></i>ECODES PLAYER V 5.0 settings</h3>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="player">
<div class="sec-t" style="margin-top:0">Watermark</div>
<label class="chk"><input type="checkbox" name="wm_enabled" <?= setting('wm_enabled', '1') === '1' ? 'checked' : '' ?>>Show a watermark on every video</label>
<?php field('Watermark text', 'wm_text', setting('wm_text', '{name} · {mobile}'), ['hint' => 'Tokens: {name}  {mobile}  {date}']); ?>
<div class="three"><?php field('Movement', 'wm_mode', setting('wm_mode', 'updown'), ['type' => 'select', 'options' => ['updown' => 'Up ⇅ down (smooth)', 'random' => 'Jump to random places', 'scroll' => 'Scroll right → left', 'static' => 'Fixed (bottom corner)']]);
field('Side (for up/down & fixed)', 'wm_pos', setting('wm_pos', 'right'), ['type' => 'select', 'options' => ['left' => 'Left', 'center' => 'Center', 'right' => 'Right']]);
field('Speed – seconds per sweep', 'wm_speed', setting('wm_speed', '12'), ['type' => 'number', 'attr' => 'min="3" max="120"', 'hint' => 'Lower = faster']); ?></div>
<div class="three"><?php field('Opacity (0.1 – 1)', 'wm_opacity', setting('wm_opacity', '0.45'), ['type' => 'number', 'attr' => 'min="0.1" max="1" step="0.05"']); field('Text size (px)', 'wm_size', setting('wm_size', '13'), ['type' => 'number', 'attr' => 'min="9" max="40"']);
field('Colour', 'wm_color', setting('wm_color', '#ffffff'), ['type' => 'color']); ?></div>
<div class="sec-t">Behaviour</div>
<label class="chk"><input type="checkbox" name="player_autofs" <?= setting('player_autofs', '1') === '1' ? 'checked' : '' ?>>Tap play → open full screen (Android, iPhone, iPad, desktop)</label>
<label class="chk"><input type="checkbox" name="player_brand" <?= setting('player_brand', '1') === '1' ? 'checked' : '' ?>>Show the “ECODES PLAYER V 5.0” badge</label>
<label class="chk"><input type="checkbox" name="player_speedctl" <?= setting('player_speedctl', '1') === '1' ? 'checked' : '' ?>>Allow playback-speed button</label>
<label class="chk"><input type="checkbox" name="player_devtools" <?= setting('player_devtools', '1') === '1' ? 'checked' : '' ?>>Pause when browser developer tools are open (desktop only)</label>
<button class="btn">Save player settings</button></form></div>
<?php endif; ?>
<?php admin_foot();
