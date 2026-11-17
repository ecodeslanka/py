<?php
/**
 * ymsbot_settings.php — Master → YMS Bot Settings
 * Company-wide settings of the YMS Bot help assistant, saved in the database (table system_settings):
 *   ymsbot_enabled    1 / 0   show YMS Bot to every user, or switch it off for everyone
 *   ymsbot_user_hide  1 / 0   each user may hide it / make it small for themselves (profile menu → YMS Bot)
 *   ymsbot_default    full | mini   how it shows for a user who has not chosen
 *   ymsbot_ai         1 / 0   "Ask Yelo AI" button inside the chat
 *   ymsbot_hello      1 / 0   the greeting bubble once per session
 */
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/yms_bot_widget_lib.php';
if (!function_exists('yms_q')) { function yms_q($conn, $sql) { try { return $conn ? mysqli_query($conn, $sql) : false; } catch (Throwable $e) { return false; } } }   // a failed query returns false (the server throws mysqli exceptions)
ymsbot_settings_table($conn);
$canEdit = function_exists('rbac_can') ? rbac_can('edit') : true;
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!$canEdit) $err = 'Your role cannot change these settings (Edit right on this page is needed).';
    else {
        $u = $_SESSION['username'] ?? ($_SESSION['user_name'] ?? 'system');
        $vals = ['ymsbot_enabled' => empty($_POST['enabled']) ? '0' : '1', 'ymsbot_user_hide' => empty($_POST['user_hide']) ? '0' : '1',
                 'ymsbot_default' => ($_POST['default'] ?? '') === 'mini' ? 'mini' : 'full', 'ymsbot_ai' => empty($_POST['ai']) ? '0' : '1', 'ymsbot_hello' => empty($_POST['hello']) ? '0' : '1'];
        foreach ($vals as $k => $v) ymsbot_set($conn, $k, $v, $u);
        if (!empty($_POST['reset_users'])) yms_q($conn, "DELETE FROM user_ui_prefs WHERE pref_key = 'ymsbot_mode' AND user_id > 0");
        $msg = 'Saved. The change shows on the next page each user opens.' . (!empty($_POST['reset_users']) ? ' Every user\'s own choice was cleared — they now see the default.' : '');
    }
}
$S = ymsbot_settings($conn);
$nUsers = ['full' => 0, 'mini' => 0, 'off' => 0];
$r = yms_q($conn, "SELECT pref_value, COUNT(*) AS n FROM user_ui_prefs WHERE pref_key = 'ymsbot_mode' AND user_id > 0 GROUP BY pref_value");
while ($r && ($x = mysqli_fetch_assoc($r))) if (isset($nUsers[$x['pref_value']])) $nUsers[$x['pref_value']] = (int)$x['n'];
include 'header.php';
?>
<style>
.ybp{max-width:860px;font-family:'Inter',system-ui,sans-serif;color:#0f172a;padding:4px 2px 20px}
.ybp .hero{display:flex;gap:18px;align-items:center;border-radius:20px;padding:20px 24px;color:#fff;background:linear-gradient(120deg,#7f1d1d,#dc2626 55%,#ef4444);box-shadow:0 16px 36px -18px rgba(185,28,28,.8);margin-bottom:16px}
.ybp .hero img{width:84px;height:84px;border-radius:50%;background:#fff;box-shadow:0 0 0 4px rgba(255,255,255,.35);animation:ybpF 3s ease-in-out infinite}@keyframes ybpF{50%{transform:translateY(-6px)}}
.ybp .hero h2{margin:0;font-size:22px}.ybp .hero p{margin:5px 0 0;opacity:.9;font-size:13px}
.ybp .card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:6px 18px;box-shadow:0 6px 18px -12px rgba(15,23,42,.25);margin-bottom:14px}
.ybp .row{display:flex;gap:16px;align-items:center;padding:14px 0;border-bottom:1px solid #f1f5f9}.ybp .row:last-child{border-bottom:none}
.ybp .row .tx{flex:1}.ybp .row b{display:block;font-size:14px}.ybp .row small{display:block;color:#64748b;font-size:12.5px;margin-top:3px}
.ybp .sw{position:relative;width:50px;height:28px;flex:0 0 auto}.ybp .sw input{opacity:0;width:0;height:0}
.ybp .sw span{position:absolute;inset:0;border-radius:99px;background:#cbd5e1;cursor:pointer;transition:.2s}.ybp .sw span:before{content:'';position:absolute;left:3px;top:3px;width:22px;height:22px;border-radius:50%;background:#fff;box-shadow:0 2px 5px rgba(0,0,0,.2);transition:.2s}
.ybp .sw input:checked+span{background:#dc2626}.ybp .sw input:checked+span:before{transform:translateX(22px)}.ybp .sw input:disabled+span{opacity:.5;cursor:not-allowed}
.ybp .seg{display:flex;border:1.5px solid #e2e8f0;border-radius:11px;overflow:hidden}.ybp .seg label{padding:8px 14px;font-size:12.5px;font-weight:700;cursor:pointer;color:#475569}.ybp .seg input{display:none}.ybp .seg input:checked+label{background:#dc2626;color:#fff}
.ybp .btn{border:none;border-radius:11px;padding:11px 20px;font-weight:800;font-size:13.5px;color:#fff;background:linear-gradient(135deg,#ef4444,#b91c1c);cursor:pointer}.ybp .btn:disabled{opacity:.5;cursor:not-allowed}
.ybp .ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:12px;padding:10px 14px;margin-bottom:12px;font-size:13px;font-weight:600}
.ybp .no{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px;padding:10px 14px;margin-bottom:12px;font-size:13px;font-weight:600}
.ybp .st{display:flex;gap:10px;flex-wrap:wrap;font-size:12.5px;color:#475569}.ybp .st span{background:#f8fafc;border:1px solid #e2e8f0;border-radius:99px;padding:5px 11px}
</style>
<div class="ybp">
  <div class="hero"><img src="images/yms_bot_icon.png" alt="YMS Bot"><div><h2>YMS Bot Settings</h2><p>Common settings of the help assistant for the whole company — saved in the database.</p></div></div>
  <?php if ($msg): ?><div class="ok">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="no">⛔ <?= htmlspecialchars($err) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="action" value="save">
    <div class="card">
      <div class="row"><div class="tx"><b>Show YMS Bot to every user</b><small>Switch off to hide the assistant on every page for everyone.</small></div><label class="sw"><input type="checkbox" name="enabled" value="1" <?= $S['ymsbot_enabled'] === '1' ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>><span></span></label></div>
      <div class="row"><div class="tx"><b>Users may hide it for themselves</b><small>Each user can choose Show / Small icon / Hide in the profile menu → YMS Bot. Switch off to use the default below for everyone.</small></div><label class="sw"><input type="checkbox" name="user_hide" value="1" <?= $S['ymsbot_user_hide'] === '1' ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>><span></span></label></div>
      <div class="row"><div class="tx"><b>Default look</b><small>For users who have not chosen (and for everyone when users may not choose).</small></div>
        <div class="seg"><input type="radio" name="default" id="dFull" value="full" <?= $S['ymsbot_default'] !== 'mini' ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>><label for="dFull">🤖 Full bot</label><input type="radio" name="default" id="dMini" value="mini" <?= $S['ymsbot_default'] === 'mini' ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>><label for="dMini">🙈 Small icon</label></div></div>
      <div class="row"><div class="tx"><b>"Ask Yelo AI" button in the chat</b><small>Questions that are not in the quick guide can be sent to Yelo AI (uses the AI key in AI Settings).</small></div><label class="sw"><input type="checkbox" name="ai" value="1" <?= $S['ymsbot_ai'] === '1' ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>><span></span></label></div>
      <div class="row"><div class="tx"><b>Greeting bubble</b><small>"Hi! I'm YMS Bot" once per session when a user logs in.</small></div><label class="sw"><input type="checkbox" name="hello" value="1" <?= $S['ymsbot_hello'] === '1' ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>><span></span></label></div>
      <div class="row"><div class="tx"><b>Clear every user's own choice</b><small>Users who hid or shrank the bot go back to the default look. Now: <?= $nUsers['full'] ?> chose Show, <?= $nUsers['mini'] ?> Small icon, <?= $nUsers['off'] ?> Hidden.</small></div><label class="sw"><input type="checkbox" name="reset_users" value="1" <?= $canEdit ? '' : 'disabled' ?>><span></span></label></div>
    </div>
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap"><button class="btn" type="submit" <?= $canEdit ? '' : 'disabled' ?>>💾 Save settings</button>
      <div class="st"><span>Last change: <?= htmlspecialchars($S['_at'] ?: '—') ?><?= $S['_by'] ? ' by ' . htmlspecialchars($S['_by']) : '' ?></span></div></div>
  </form>
</div>
<?php include 'footer.php'; ?>
