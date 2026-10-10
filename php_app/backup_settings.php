<?php
include 'config.php';
include 'header.php';

/* ═══════════════════════════════════════════════════════
   Backup Settings — Manual DB Backup (.tar.gz download)
   + Google Drive Integration
   Requires: backup_helper.php, backup_download.php,
             google_drive_callback.php in same directory
════════════════════════════════════════════════════════ */

require_once __DIR__ . '/backup_helper.php';

$msg = $msg_type = '';
$drive_info = null;
$cur_user_name = $_SESSION['username'] ?? 'system';

/* Messages from redirects (download / oauth callback) */
if (isset($_GET['ok']))  { $msg = $_GET['ok'];  $msg_type = 'success'; }
if (isset($_GET['err'])) { $msg = $_GET['err']; $msg_type = 'error'; }

/* ── POST actions ───────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    /* Save Google Drive settings */
    if ($_POST['action'] === 'save_gdrive') {
        $cid    = trim($_POST['gdrive_client_id'] ?? '');
        $secret = trim($_POST['gdrive_client_secret'] ?? '');
        $folder = trim($_POST['gdrive_folder_id'] ?? '');
        if (!$cid || !$secret) {
            $msg = 'Client ID and Client Secret are required.'; $msg_type = 'error';
        } else {
            bk_setSetting($conn, 'gdrive_client_id', $cid);
            if ($secret !== '••••••••') bk_setSetting($conn, 'gdrive_client_secret', $secret);
            bk_setSetting($conn, 'gdrive_folder_id', $folder);
            $msg = 'Google Drive settings saved. Now click "Connect Google Drive" to authorize.';
            $msg_type = 'success';
        }
    }

    /* Disconnect Google Drive */
    if ($_POST['action'] === 'disconnect_gdrive') {
        mysqli_query($conn, "DELETE FROM backup_settings WHERE `key` IN(
            'gdrive_client_id','gdrive_client_secret','gdrive_folder_id',
            'gdrive_access_token','gdrive_refresh_token','gdrive_token_expiry')");
        $msg = 'Google Drive disconnected and credentials removed.'; $msg_type = 'success';
    }

    /* Test Drive connection */
    if ($_POST['action'] === 'test_gdrive') {
        try {
            $drive_info = bk_gdriveTest($conn);
            $msg = 'Connected as ' . ($drive_info['user']['emailAddress'] ?? 'unknown') . ' — Google Drive is working!';
            $msg_type = 'success';
        } catch (Exception $e) {
            $msg = $e->getMessage(); $msg_type = 'error';
        }
    }

    /* Backup to Google Drive */
    if ($_POST['action'] === 'backup_to_drive') {
        @set_time_limit(0);
        try {
            $file   = bk_createBackup($conn);
            $size   = filesize($file);
            $fileId = bk_gdriveUpload($conn, $file);
            bk_logHistory($conn, basename($file), $size, 'gdrive', $fileId, 'success', null, $cur_user_name);
            bk_cleanupOld(5);
            $msg = 'Backup uploaded to Google Drive successfully! (' . bk_formatBytes($size) . ')';
            $msg_type = 'success';
        } catch (Exception $e) {
            bk_logHistory($conn, 'FAILED', 0, 'gdrive', null, 'failed', $e->getMessage(), $cur_user_name);
            $msg = 'Google Drive backup failed: ' . $e->getMessage();
            $msg_type = 'error';
        }
    }

    /* Clear history */
    if ($_POST['action'] === 'clear_history') {
        mysqli_query($conn, "DELETE FROM backup_history");
        $msg = 'Backup history cleared.'; $msg_type = 'success';
    }
}

/* ── Current state ──────────────────────────────────── */
$cid_cur     = bk_getSetting($conn, 'gdrive_client_id');
$secret_cur  = bk_getSetting($conn, 'gdrive_client_secret');
$folder_cur  = bk_getSetting($conn, 'gdrive_folder_id');
$refresh_cur = bk_getSetting($conn, 'gdrive_refresh_token');
$configured  = $cid_cur !== '' && $secret_cur !== '';
$connected   = $configured && $refresh_cur !== '';
$authUrl     = $configured ? bk_gdriveAuthUrl($conn) : '';
$redirectUri = bk_gdriveRedirectUri();

/* History */
$history = mysqli_query($conn, "SELECT * FROM backup_history ORDER BY id DESC LIMIT 30");

/* DB size */
$dbsize = 0;
$sz = mysqli_query($conn, "SELECT SUM(data_length + index_length) s FROM information_schema.TABLES WHERE table_schema = '" . DB_NAME . "'");
if ($sz && ($r = mysqli_fetch_assoc($sz))) $dbsize = intval($r['s']);
$tblcount = 0;
$tc = mysqli_query($conn, "SELECT COUNT(*) c FROM information_schema.TABLES WHERE table_schema = '" . DB_NAME . "'");
if ($tc && ($r = mysqli_fetch_assoc($tc))) $tblcount = intval($r['c']);
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=JetBrains+Mono:wght@400;500&display=swap');
:root{
    --bg:#f0f2f5;--surf:#fff;--surf2:#f7f8fb;
    --bdr:#e1e5ec;--bdrs:#eceef4;
    --tx:#0f1623;--txm:#4a5568;--txs:#8896a7;
    --fn:'DM Sans',sans-serif;--mono:'JetBrains Mono',monospace;
    --r:12px;--rsm:8px;
    --sh:0 1px 3px rgba(0,0,0,.05),0 4px 16px rgba(0,0,0,.06);
    --gd1:#4285f4;--gd2:#34a853;--gd3:#fbbc05;--gd4:#ea4335;
    --ggrad:linear-gradient(135deg,#4285f4 0%,#34a853 40%,#fbbc05 70%,#ea4335 100%);
    --gbgl:linear-gradient(135deg,#eff6ff 0%,#f0fdf4 55%,#fffbeb 100%);
    --green:#16a34a;--gbg:#f0fdf4;--gbdr:#86efac;
    --red:#dc2626;  --rbg:#fef2f2;--rbdr:#fca5a5;
    --blue:#1d4ed8; --bbg:#eff6ff;--bbdr:#bfdbfe;
    --amb:#d97706;  --abg:#fffbeb;--abdr:#fde68a;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:13.5px;line-height:1.55;}
.pg{padding:24px 20px 80px;max-width:980px;}
.bcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--txs);margin-bottom:18px;}
.bcrumb a{color:var(--txs);text-decoration:none;}.bcrumb a:hover{color:var(--tx);}
.bcrumb .sep{color:#d1d5db;}
.topbar{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.pg-title{font-size:26px;font-weight:700;letter-spacing:-.03em;line-height:1.1;}
.pg-title em{background:var(--ggrad);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;font-style:normal;}
.pg-sub{font-size:12px;color:var(--txs);margin-top:5px;}
.btn-back{display:inline-flex;align-items:center;gap:6px;padding:9px 15px;background:var(--surf);color:var(--txm);border:1px solid var(--bdr);border-radius:var(--rsm);font-size:12.5px;font-weight:600;text-decoration:none;cursor:pointer;font-family:var(--fn);transition:all .15s;}
.btn-back:hover{background:var(--bdrs);}
.alert{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:var(--rsm);font-size:13px;font-weight:500;margin-bottom:18px;border:1px solid;line-height:1.5;}
.alert.success{background:var(--gbg);color:#166534;border-color:var(--gbdr);}
.alert.error{background:var(--rbg);color:#991b1b;border-color:var(--rbdr);}
.alert i{margin-top:2px;flex-shrink:0;}
.card{background:var(--surf);border:1px solid var(--bdr);border-radius:var(--r);box-shadow:var(--sh);overflow:hidden;margin-bottom:18px;}
.card-head{display:flex;align-items:center;gap:13px;padding:16px 20px;border-bottom:1px solid var(--bdrs);}
.ch-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.ch-icon.bk{background:var(--bbg);color:var(--blue);}
.ch-icon.gd{background:var(--gbgl);}
.ch-icon.gd i{background:var(--ggrad);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.ch-icon.hist{background:var(--abg);color:var(--amb);}
.ch-icon.info{background:var(--gbg);color:var(--green);}
.card-body{padding:20px 22px;}
.ch-title{font-size:14px;font-weight:700;}
.ch-sub{font-size:11.5px;color:var(--txs);margin-top:2px;}
.card-head .right{margin-left:auto;}
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:10.5px;font-weight:700;border:1px solid;white-space:nowrap;}
.badge .dot{width:7px;height:7px;border-radius:50%;background:currentColor;}
.badge.green{background:var(--gbg);color:#166534;border-color:var(--gbdr);}
.badge.grey{background:#f1f5f9;color:var(--txs);border-color:#e2e8f0;}
.badge.amber{background:var(--abg);color:#92400e;border-color:var(--abdr);}
.stat-row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px;}
.stat{flex:1;min-width:150px;background:var(--surf2);border:1px solid var(--bdrs);border-radius:10px;padding:13px 16px;}
.stat .sv{font-size:19px;font-weight:700;letter-spacing:-.02em;}
.stat .sl{font-size:11px;color:var(--txs);margin-top:2px;}
.btn-row{display:flex;gap:10px;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:var(--rsm);font-size:13px;font-weight:600;border:1px solid transparent;cursor:pointer;font-family:var(--fn);text-decoration:none;transition:all .15s;}
.btn:disabled{opacity:.5;cursor:not-allowed;}
.btn-primary{background:var(--blue);color:#fff;}
.btn-primary:hover{background:#1e40af;}
.btn-gdrive{background:var(--surf);color:var(--tx);border-color:var(--bdr);box-shadow:var(--sh);}
.btn-gdrive:hover{background:var(--surf2);}
.btn-green{background:var(--green);color:#fff;}
.btn-green:hover{background:#15803d;}
.btn-outline{background:var(--surf);color:var(--txm);border-color:var(--bdr);}
.btn-outline:hover{background:var(--bdrs);}
.btn-danger{background:var(--surf);color:var(--red);border-color:var(--rbdr);}
.btn-danger:hover{background:var(--rbg);}
.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg.full{grid-column:1/-1;}
.fg label{font-size:11.5px;font-weight:700;color:var(--txm);}
.fg label .opt{font-weight:400;color:var(--txs);}
.fg input{padding:10px 13px;border:1px solid var(--bdr);border-radius:var(--rsm);font-size:13px;font-family:var(--fn);background:var(--surf);color:var(--tx);outline:none;transition:border-color .15s;}
.fg input:focus{border-color:var(--gd1);box-shadow:0 0 0 3px rgba(66,133,244,.12);}
.fg .hint{font-size:11px;color:var(--txs);}
.copybox{display:flex;align-items:center;gap:8px;background:var(--surf2);border:1px solid var(--bdrs);border-radius:var(--rsm);padding:9px 12px;font-family:var(--mono);font-size:11.5px;word-break:break-all;}
.copybox button{margin-left:auto;flex-shrink:0;background:var(--surf);border:1px solid var(--bdr);border-radius:6px;padding:4px 9px;font-size:11px;font-weight:600;cursor:pointer;color:var(--txm);font-family:var(--fn);}
.copybox button:hover{background:var(--bdrs);}
.steps{counter-reset:st;display:flex;flex-direction:column;gap:11px;}
.step{display:flex;gap:12px;font-size:12.5px;color:var(--txm);line-height:1.55;}
.step::before{counter-increment:st;content:counter(st);width:22px;height:22px;flex-shrink:0;border-radius:50%;background:var(--bbg);color:var(--blue);border:1px solid var(--bbdr);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;}
.step b{color:var(--tx);}
.step code{background:var(--surf2);border:1px solid var(--bdrs);padding:1px 6px;border-radius:5px;font-family:var(--mono);font-size:11px;}
table.hist{width:100%;border-collapse:collapse;font-size:12.5px;}
table.hist th{text-align:left;padding:9px 12px;background:var(--surf2);border-bottom:1px solid var(--bdrs);font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--txs);font-weight:700;}
table.hist td{padding:10px 12px;border-bottom:1px solid var(--bdrs);vertical-align:top;}
table.hist tr:last-child td{border-bottom:none;}
.fname{font-family:var(--mono);font-size:11px;word-break:break-all;}
.tchip{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:14px;font-size:10.5px;font-weight:700;border:1px solid;}
.tchip.dl{background:var(--bbg);color:var(--blue);border-color:var(--bbdr);}
.tchip.gd{background:var(--gbg);color:#166534;border-color:var(--gbdr);}
.tchip.fail{background:var(--rbg);color:var(--red);border-color:var(--rbdr);}
.errline{font-size:11px;color:var(--red);margin-top:3px;}
.empty{text-align:center;color:var(--txs);padding:26px;font-size:12.5px;}
.spinner{display:none;width:15px;height:15px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;}
@keyframes spin{to{transform:rotate(360deg);}}
.busy .spinner{display:inline-block;}
.busy{pointer-events:none;opacity:.75;}
@media(max-width:720px){.fgrid{grid-template-columns:1fr;}}
</style>

<div class="pg">
    <div class="bcrumb">
        <a href="dashboard.php">Home</a><span class="sep">/</span>
        <span>Settings</span><span class="sep">/</span>
        <span style="color:var(--tx);font-weight:600;">Database Backup</span>
    </div>

    <div class="topbar">
        <div>
            <div class="pg-title">Database <em>Backup</em></div>
            <div class="pg-sub">Download manual backups as .tar.gz or upload them straight to Google Drive</div>
        </div>
        <a href="dashboard.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>

    <?php if ($msg): ?>
    <div class="alert <?= $msg_type ?>">
        <i class="fa-solid <?= $msg_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
        <div><?= htmlspecialchars($msg) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($drive_info): ?>
    <div class="alert success">
        <i class="fa-brands fa-google-drive"></i>
        <div>
            <b>Drive account:</b> <?= htmlspecialchars($drive_info['user']['emailAddress'] ?? '-') ?><br>
            <b>Storage used:</b> <?= bk_formatBytes(intval($drive_info['storageQuota']['usage'] ?? 0)) ?>
            of <?= bk_formatBytes(intval($drive_info['storageQuota']['limit'] ?? 0)) ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══════════ MANUAL BACKUP ══════════ -->
    <div class="card">
        <div class="card-head">
            <div class="ch-icon bk"><i class="fa-solid fa-database"></i></div>
            <div>
                <div class="ch-title">Manual Backup</div>
                <div class="ch-sub">Creates a full SQL dump of <b><?= htmlspecialchars(DB_NAME) ?></b> — tables, data, views &amp; triggers</div>
            </div>
        </div>
        <div class="card-body">
            <div class="stat-row">
                <div class="stat"><div class="sv"><?= bk_formatBytes($dbsize) ?></div><div class="sl">Database size (uncompressed)</div></div>
                <div class="stat"><div class="sv"><?= $tblcount ?></div><div class="sl">Tables &amp; views</div></div>
                <div class="stat"><div class="sv"><?= htmlspecialchars(DB_NAME) ?></div><div class="sl">Database name</div></div>
            </div>
            <div class="btn-row">
                <a href="backup_download.php" class="btn btn-primary" onclick="busy(this,'Preparing backup…')">
                    <span class="spinner"></span><i class="fa-solid fa-download"></i> Download Backup (.tar.gz)
                </a>
                <form method="POST" style="display:inline;" onsubmit="return driveBackup(this);">
                    <input type="hidden" name="action" value="backup_to_drive">
                    <button type="submit" class="btn btn-green" <?= $connected ? '' : 'disabled title="Connect Google Drive first"' ?>>
                        <span class="spinner"></span><i class="fa-brands fa-google-drive"></i> Backup to Google Drive
                    </button>
                </form>
            </div>
            <div style="font-size:11px;color:var(--txs);margin-top:12px;">
                <i class="fa-solid fa-circle-info"></i>
                Large databases may take a minute or two — please don't close the page while the backup runs.
                The last 5 backups are also kept on the server in the protected <code style="font-family:var(--mono);">/backups</code> folder.
            </div>
        </div>
    </div>

    <!-- ══════════ GOOGLE DRIVE SETTINGS ══════════ -->
    <div class="card">
        <div class="card-head">
            <div class="ch-icon gd"><i class="fa-brands fa-google-drive"></i></div>
            <div>
                <div class="ch-title">Google Drive Settings</div>
                <div class="ch-sub">OAuth 2.0 credentials from Google Cloud Console</div>
            </div>
            <div class="right">
                <?php if ($connected): ?>
                    <span class="badge green"><span class="dot"></span> Connected</span>
                <?php elseif ($configured): ?>
                    <span class="badge amber"><span class="dot"></span> Authorization needed</span>
                <?php else: ?>
                    <span class="badge grey"><span class="dot"></span> Not configured</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="save_gdrive">
                <div class="fgrid">
                    <div class="fg full">
                        <label>Client ID <span style="color:var(--red);">*</span></label>
                        <input type="text" name="gdrive_client_id"
                               value="<?= htmlspecialchars($cid_cur) ?>"
                               placeholder="xxxxxxxx.apps.googleusercontent.com" required>
                    </div>
                    <div class="fg">
                        <label>Client Secret <span style="color:var(--red);">*</span></label>
                        <input type="password" name="gdrive_client_secret"
                               value="<?= $secret_cur !== '' ? '••••••••' : '' ?>"
                               placeholder="GOCSPX-…" required>
                        <div class="hint">Leave the dots unchanged to keep the saved secret.</div>
                    </div>
                    <div class="fg">
                        <label>Drive Folder ID <span class="opt">(optional)</span></label>
                        <input type="text" name="gdrive_folder_id"
                               value="<?= htmlspecialchars($folder_cur) ?>"
                               placeholder="e.g. 1AbCdEfGh… (from folder URL)">
                        <div class="hint">Empty = backups go to "My Drive" root.</div>
                    </div>
                    <div class="fg full">
                        <label>Authorized Redirect URI <span class="opt">(add this in Google Cloud Console)</span></label>
                        <div class="copybox">
                            <span id="ruri"><?= htmlspecialchars($redirectUri) ?></span>
                            <button type="button" onclick="copyUri()"><i class="fa-regular fa-copy"></i> Copy</button>
                        </div>
                    </div>
                </div>
                <div class="btn-row" style="margin-top:18px;">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Settings</button>

                    <?php if ($configured): ?>
                        <a href="<?= htmlspecialchars($authUrl) ?>" class="btn btn-gdrive">
                            <i class="fa-brands fa-google"></i>
                            <?= $connected ? 'Reconnect Google Drive' : 'Connect Google Drive' ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($connected): ?>
                        <button type="submit" form="testForm" class="btn btn-outline"><i class="fa-solid fa-plug-circle-check"></i> Test Connection</button>
                        <button type="submit" form="discForm" class="btn btn-danger"
                                onclick="return confirm('Disconnect Google Drive and delete the saved credentials?');">
                            <i class="fa-solid fa-link-slash"></i> Disconnect
                        </button>
                    <?php endif; ?>
                </div>
            </form>
            <form id="testForm" method="POST"><input type="hidden" name="action" value="test_gdrive"></form>
            <form id="discForm" method="POST"><input type="hidden" name="action" value="disconnect_gdrive"></form>
        </div>
    </div>

    <!-- ══════════ SETUP GUIDE ══════════ -->
    <div class="card">
        <div class="card-head">
            <div class="ch-icon info"><i class="fa-solid fa-circle-question"></i></div>
            <div>
                <div class="ch-title">How to get Google Drive credentials</div>
                <div class="ch-sub">One-time setup in Google Cloud Console (free)</div>
            </div>
        </div>
        <div class="card-body">
            <div class="steps">
                <div class="step"><div>Go to <b>console.cloud.google.com</b> → create (or select) a project.</div></div>
                <div class="step"><div>Open <b>APIs &amp; Services → Library</b>, search for <b>Google Drive API</b> and click <b>Enable</b>.</div></div>
                <div class="step"><div>Open <b>APIs &amp; Services → OAuth consent screen</b> → choose <b>External</b>, fill in the app name and your email, then add your Google account under <b>Test users</b>.</div></div>
                <div class="step"><div>Open <b>APIs &amp; Services → Credentials → Create Credentials → OAuth client ID</b> → type <b>Web application</b>.</div></div>
                <div class="step"><div>Under <b>Authorized redirect URIs</b>, add exactly: <code><?= htmlspecialchars($redirectUri) ?></code></div></div>
                <div class="step"><div>Copy the <b>Client ID</b> and <b>Client Secret</b> into the form above, click <b>Save Settings</b>, then <b>Connect Google Drive</b> and approve access.</div></div>
            </div>
        </div>
    </div>

    <!-- ══════════ HISTORY ══════════ -->
    <div class="card">
        <div class="card-head">
            <div class="ch-icon hist"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <div>
                <div class="ch-title">Backup History</div>
                <div class="ch-sub">Last 30 backup operations</div>
            </div>
            <div class="right">
                <form method="POST" onsubmit="return confirm('Clear all backup history records?');">
                    <input type="hidden" name="action" value="clear_history">
                    <button type="submit" class="btn btn-outline" style="padding:7px 12px;font-size:11.5px;">
                        <i class="fa-solid fa-broom"></i> Clear
                    </button>
                </form>
            </div>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if ($history && mysqli_num_rows($history) > 0): ?>
            <table class="hist">
                <thead>
                    <tr><th>#</th><th>File</th><th>Size</th><th>Type</th><th>By</th><th>Date</th></tr>
                </thead>
                <tbody>
                <?php $i = 0; while ($h = mysqli_fetch_assoc($history)): $i++; ?>
                    <tr>
                        <td><?= $i ?></td>
                        <td>
                            <div class="fname"><?= htmlspecialchars($h['filename']) ?></div>
                            <?php if ($h['status'] === 'failed' && $h['error_msg']): ?>
                                <div class="errline"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars(mb_strimwidth($h['error_msg'], 0, 160, '…')) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= $h['filesize'] > 0 ? bk_formatBytes($h['filesize']) : '—' ?></td>
                        <td>
                            <?php if ($h['status'] === 'failed'): ?>
                                <span class="tchip fail"><i class="fa-solid fa-xmark"></i> Failed</span>
                            <?php elseif ($h['backup_type'] === 'gdrive'): ?>
                                <span class="tchip gd"><i class="fa-brands fa-google-drive"></i> Drive</span>
                            <?php else: ?>
                                <span class="tchip dl"><i class="fa-solid fa-download"></i> Download</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($h['created_by'] ?? '-') ?></td>
                        <td style="white-space:nowrap;"><?= date('Y-m-d h:i A', strtotime($h['created_at'])) ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty"><i class="fa-regular fa-folder-open"></i> &nbsp;No backups yet — create your first one above.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function copyUri(){
    const t = document.getElementById('ruri').textContent.trim();
    navigator.clipboard.writeText(t).then(() => {
        alert('Redirect URI copied!');
    });
}
function busy(el, txt){
    el.classList.add('busy');
    /* Reset after 90 s so the button becomes usable again once the download starts */
    setTimeout(() => el.classList.remove('busy'), 90000);
    return true;
}
function driveBackup(form){
    if (!confirm('Create a new backup and upload it to Google Drive now?')) return false;
    const btn = form.querySelector('button');
    btn.classList.add('busy');
    return true;
}
</script>
