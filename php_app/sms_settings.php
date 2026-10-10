<?php
include 'config.php';
include 'header.php';

/* ═══════════════════════════════════════════════════════
   SMS Settings — Dialog eSMS API Integration
   Requires: sms_api_handler.php in same directory
   Table auto-created: sms_settings
════════════════════════════════════════════════════════ */

$msg = $msg_type = '';

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sms_settings (
    `key`        VARCHAR(100) PRIMARY KEY,
    `value`      TEXT,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

function getSetting($conn, $key){
    $k=mysqli_real_escape_string($conn,$key);
    $r=mysqli_query($conn,"SELECT `value` FROM sms_settings WHERE `key`='$k'");
    return ($r && $row=mysqli_fetch_assoc($r)) ? $row['value'] : '';
}
function setSetting($conn,$key,$value){
    $k=mysqli_real_escape_string($conn,$key);
    $v=mysqli_real_escape_string($conn,$value);
    mysqli_query($conn,"INSERT INTO sms_settings(`key`,`value`)VALUES('$k','$v')
        ON DUPLICATE KEY UPDATE `value`='$v',updated_at=NOW()");
}

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])){

    if($_POST['action']==='save_sms_settings'){
        $u=trim($_POST['sms_username']??'');
        $p=trim($_POST['sms_password']??'');
        $m=trim($_POST['sms_mask']??'');
        if(!$u||!$p){ $msg='Username and Password are required.'; $msg_type='error'; }
        else {
            setSetting($conn,'sms_username',$u);
            if($p!=='••••••••') {
                setSetting($conn,'sms_password',$p);
                setSetting($conn,'sms_token','');        // invalidate token
                setSetting($conn,'sms_token_expiry','0');
            }
            setSetting($conn,'sms_mask',$m);
            $msg='Settings saved. Click "Connect & Refresh Token" to verify.';
            $msg_type='success';
        }
    }

    if($_POST['action']==='delete_sms_settings'){
        mysqli_query($conn,"DELETE FROM sms_settings WHERE `key` IN(
            'sms_username','sms_password','sms_mask',
            'sms_token','sms_token_expiry','sms_wallet_balance')");
        $msg='SMS configuration removed.'; $msg_type='success';
    }
}

$cur_user    = getSetting($conn,'sms_username');
$cur_mask    = getSetting($conn,'sms_mask');
$cur_token   = getSetting($conn,'sms_token');
$cur_expiry  = getSetting($conn,'sms_token_expiry');
$cur_balance = getSetting($conn,'sms_wallet_balance');
$configured  = $cur_user !== '';
$tok_valid   = $cur_token && intval($cur_expiry)>time();
$tok_expired = $cur_token && intval($cur_expiry)<=time();
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
    --dr:#e01e26;--do:#f7941d;--dp:#9b2d8e;
    --dgrad:linear-gradient(135deg,#e01e26 0%,#f7941d 55%,#9b2d8e 100%);
    --dbg:linear-gradient(135deg,#fff1f1 0%,#fff7ed 55%,#fdf4ff 100%);
    --green:#16a34a;--gbg:#f0fdf4;--gbdr:#86efac;
    --red:#dc2626;  --rbg:#fef2f2;--rbdr:#fca5a5;
    --blue:#1d4ed8; --bbg:#eff6ff;--bbdr:#bfdbfe;
    --amb:#d97706;  --abg:#fffbeb;--abdr:#fde68a;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:13.5px;line-height:1.55;}
.pg{padding:24px 20px 80px;max-width:900px;}
.bcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--txs);margin-bottom:18px;}
.bcrumb a{color:var(--txs);text-decoration:none;}.bcrumb a:hover{color:var(--tx);}
.bcrumb .sep{color:#d1d5db;}
.topbar{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.pg-title{font-size:26px;font-weight:700;letter-spacing:-.03em;line-height:1.1;}
.pg-title em{background:var(--dgrad);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;font-style:normal;}
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
.ch-icon.sms{background:var(--dbg);}
.ch-icon.sms i{background:var(--dgrad);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.ch-icon.info{background:var(--bbg);color:var(--blue);}
.ch-icon.test{background:var(--gbg);color:var(--green);}
.ch-icon.bal{background:var(--abg);color:var(--amb);}
.card-body{padding:20px 22px;}
.ch-title{font-size:14px;font-weight:700;}
.ch-sub{font-size:11.5px;color:var(--txs);margin-top:2px;}
.dstrip{display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:10px;margin-bottom:20px;background:var(--dbg);border:1px solid #f5c9c9;}
.dlogo{width:44px;height:44px;border-radius:10px;background:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,.1);flex-shrink:0;}
.dlogo-d{font-size:24px;font-weight:900;background:var(--dgrad);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;line-height:1;}
.ds-name{font-size:14px;font-weight:700;}
.ds-sub{font-size:11.5px;color:var(--txm);margin-top:2px;}
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:10.5px;font-weight:700;border:1px solid;white-space:nowrap;}
.badge .dot{width:7px;height:7px;border-radius:50%;background:currentColor;}
.badge.green{background:var(--gbg);color:#166534;border-color:var(--gbdr);}
.badge.grey{background:#f1f5f9;color:var(--txs);border-color:#e2e8f0;}
.badge.amber{background:var(--abg);color:#92400e;border-color:var(--abdr);}
.sdot{width:9px;height:9px;border-radius:50%;flex-shrink:0;display:inline-block;}
.sdot.green{background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.18);}
.sdot.amber{background:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.18);}
.sdot.grey{background:#d1d5db;}
.bal-card{background:linear-gradient(135deg,#111827 0%,#1e293b 100%);border-radius:12px;padding:20px 22px;color:#fff;display:flex;align-items:flex-start;justify-content:space-between;position:relative;overflow:hidden;margin-bottom:18px;}
.bal-card::before{content:'';position:absolute;top:-40px;right:-20px;width:130px;height:130px;border-radius:50%;background:rgba(247,148,29,.12);}
.bal-card::after{content:'';position:absolute;bottom:-30px;left:30px;width:90px;height:90px;border-radius:50%;background:rgba(224,30,38,.10);}
.bc-l{position:relative;z-index:1;}
.bc-lbl{font-size:10.5px;font-weight:600;color:rgba(255,255,255,.45);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;}
.bc-amt{font-size:34px;font-weight:700;letter-spacing:-.02em;line-height:1;}
.bc-cur{font-size:15px;font-weight:500;color:rgba(255,255,255,.5);margin-right:4px;}
.bc-note{font-size:11px;color:rgba(255,255,255,.35);margin-top:6px;}
.bc-r{position:relative;z-index:1;text-align:right;}
.bc-user{font-size:13px;font-weight:600;color:rgba(255,255,255,.8);}
.bc-mask{font-size:11.5px;color:rgba(255,255,255,.35);margin-top:3px;}
.bc-tok{margin-top:10px;}
.sgrid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px;}
.si{background:var(--surf2);border:1px solid var(--bdrs);border-radius:9px;padding:12px 14px;}
.si-lbl{font-size:10px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;display:flex;align-items:center;gap:5px;}
.si-val{font-size:12.5px;font-weight:600;color:var(--tx);font-family:var(--mono);}
.si-val.n{font-family:var(--fn);font-size:13px;}
.sec-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.09em;color:var(--txs);margin-bottom:12px;display:flex;align-items:center;gap:8px;}
.sec-lbl::after{content:'';flex:1;height:1px;background:var(--bdrs);}
.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.fg{margin-bottom:14px;}
.fg label{display:block;font-size:10.5px;font-weight:700;color:var(--txm);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;}
.fg label .opt{font-weight:400;color:#c0c8d2;text-transform:none;letter-spacing:0;}
.iw{position:relative;}
.iw input,.iw textarea{width:100%;padding:9px 38px 9px 12px;border:1.5px solid var(--bdr);border-radius:var(--rsm);font-size:13px;color:var(--tx);background:#fff;font-family:var(--fn);transition:border .15s,box-shadow .15s;}
.iw textarea{resize:vertical;min-height:80px;padding:10px 12px;}
.iw input:focus,.iw textarea:focus{outline:none;border-color:var(--dr);box-shadow:0 0 0 3px rgba(224,30,38,.09);}
.iw input.nopad{padding-right:12px;}
.tpw{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--txs);font-size:14px;padding:4px;border-radius:4px;}
.tpw:hover{color:var(--tx);background:#f3f4f6;}
.hint{font-size:11px;color:var(--txs);margin-top:4px;}
.brow{display:flex;gap:9px;flex-wrap:wrap;}
.bp{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;background:var(--dgrad);color:#fff;border:none;border-radius:var(--rsm);font-size:13px;font-weight:700;cursor:pointer;font-family:var(--fn);box-shadow:0 2px 10px rgba(224,30,38,.22);transition:all .15s;white-space:nowrap;}
.bp:hover{opacity:.88;box-shadow:0 4px 18px rgba(224,30,38,.28);transform:translateY(-1px);}
.bp:disabled{opacity:.5;cursor:not-allowed;transform:none;}
.bs{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;background:#fff;color:var(--txm);border:1.5px solid var(--bdr);border-radius:var(--rsm);font-size:13px;font-weight:600;cursor:pointer;font-family:var(--fn);transition:all .15s;white-space:nowrap;}
.bs:hover{background:var(--surf2);}
.bd{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;background:#fff;color:var(--red);border:1.5px solid var(--rbdr);border-radius:var(--rsm);font-size:13px;font-weight:600;cursor:pointer;font-family:var(--fn);transition:all .15s;}
.bd:hover{background:var(--rbg);}
.rbox{display:none;margin-top:14px;padding:13px 16px;border-radius:var(--rsm);font-size:13px;font-weight:500;border:1px solid;line-height:1.55;}
.rbox.ok{background:var(--gbg);color:#166534;border-color:var(--gbdr);}
.rbox.err{background:var(--rbg);color:#991b1b;border-color:var(--rbdr);}
.rbox.load{background:var(--bbg);color:#1e40af;border-color:var(--bbdr);}
.rbox.warn{background:var(--abg);color:#92400e;border-color:var(--abdr);}
.crow{display:flex;justify-content:space-between;align-items:center;margin-top:5px;}
.cct{font-size:11px;color:var(--txs);font-family:var(--mono);}
.cct.warn{color:var(--amb);}.cct.over{color:var(--red);}
.spts{display:flex;gap:3px;margin-top:4px;}
.spt{height:3px;width:18px;border-radius:2px;background:var(--bdrs);transition:background .15s;}
.spt.on{background:var(--dr);}
.divider{height:1px;background:var(--bdrs);margin:18px 0;}
.modal-ov{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1050;display:none;align-items:center;justify-content:center;padding:16px;}
.modal-bx{background:#fff;border-radius:16px;width:100%;max-width:420px;box-shadow:0 32px 80px rgba(0,0,0,.28);overflow:hidden;}
.mbody{padding:28px 24px 20px;text-align:center;}
.micon{font-size:42px;margin-bottom:14px;}
.mtitle{font-size:16px;font-weight:700;margin-bottom:8px;}
.mtext{font-size:13px;color:#6b7280;line-height:1.6;}
.mfoot{padding:14px 22px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:10px;}
@media(max-width:620px){.fgrid,.sgrid{grid-template-columns:1fr;}.bal-card{flex-direction:column;gap:14px;}.bc-r{text-align:left;}}
</style>

<div class="pg">
<div class="bcrumb">
    <a href="dashboard.php"><i class="fa-solid fa-house"></i></a>
    <span class="sep">/</span><span>Settings</span>
    <span class="sep">/</span>
    <span style="color:var(--tx);font-weight:600;">SMS Settings</span>
</div>

<div class="topbar">
    <div>
        <div class="pg-title">SMS <em>Settings</em></div>
        <div class="pg-sub">Dialog Axiata eSMS · Smart Messenger · e-sms.dialog.lk</div>
    </div>
    <a href="dashboard.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back</a>
</div>

<?php if($msg): ?>
<div class="alert <?=$msg_type?>">
    <i class="fa-solid <?=$msg_type==='success'?'fa-circle-check':'fa-circle-exclamation'?>"></i>
    <span><?=htmlspecialchars($msg)?></span>
</div>
<?php endif; ?>

<!-- ── CARD 1: Overview ── -->
<div class="card">
    <div class="card-head">
        <div class="ch-icon bal"><i class="fa-solid fa-wallet"></i></div>
        <div><div class="ch-title">Account Overview</div><div class="ch-sub">Wallet balance &amp; token status</div></div>
    </div>
    <div class="card-body">
        <div class="bal-card">
            <div class="bc-l">
                <div class="bc-lbl">Wallet Balance</div>
                <div class="bc-amt">
                    <span class="bc-cur">LKR</span>
                    <span id="bc-val"><?=$cur_balance?number_format(floatval($cur_balance),2):'—'?></span>
                </div>
                <div class="bc-note" id="bc-note">
                    <?=$cur_balance?'Cached balance · Click Refresh to update':'Click "Connect &amp; Refresh Token" to load balance'?>
                </div>
            </div>
            <div class="bc-r">
                <div class="bc-user"><?=htmlspecialchars($cur_user?:'—')?></div>
                <div class="bc-mask">Mask: <?=htmlspecialchars($cur_mask?:'Account default')?></div>
                <div class="bc-tok" id="tok-badge">
                    <?php if($tok_valid): ?>
                        <span class="badge green"><span class="dot"></span> Token Active</span>
                    <?php elseif($tok_expired): ?>
                        <span class="badge amber"><span class="dot"></span> Token Expired</span>
                    <?php else: ?>
                        <span class="badge grey"><span class="dot"></span> No Token</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="sgrid">
            <div class="si">
                <div class="si-lbl"><span class="sdot <?=$configured?'green':'grey'?>"></span> Status</div>
                <div class="si-val n"><?=$configured?'Credentials saved':'Not configured'?></div>
            </div>
            <div class="si">
                <div class="si-lbl"><span class="sdot <?=$tok_valid?'green':($tok_expired?'amber':'grey')?>"></span> Token</div>
                <div class="si-val">
                    <?php if($tok_valid): ?>Expires <?=date('d M H:i',intval($cur_expiry))?>
                    <?php elseif($tok_expired): ?>Expired — needs refresh
                    <?php else: ?>Not generated<?php endif; ?>
                </div>
            </div>
            <div class="si">
                <div class="si-lbl"><i class="fa-solid fa-user" style="font-size:9px;"></i> Username</div>
                <div class="si-val"><?=htmlspecialchars($cur_user?:'—')?></div>
            </div>
            <div class="si">
                <div class="si-lbl"><i class="fa-solid fa-tag" style="font-size:9px;"></i> Sender Mask</div>
                <div class="si-val"><?=htmlspecialchars($cur_mask?:'Account default')?></div>
            </div>
        </div>

        <?php if($configured): ?>
        <div class="brow">
            <button class="bp" id="btnConn" onclick="fetchToken()">
                <i class="fa-solid fa-plug"></i> Connect &amp; Refresh Token
            </button>
        </div>
        <div class="rbox" id="connRes"></div>
        <?php else: ?>
        <div class="rbox warn" style="display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-triangle-exclamation" style="flex-shrink:0;"></i>
            <span>Save your credentials below first, then click Connect.</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── CARD 2: Credentials ── -->
<div class="card">
    <div class="card-head">
        <div class="ch-icon sms"><i class="fa-solid fa-key"></i></div>
        <div><div class="ch-title">Dialog eSMS Credentials</div><div class="ch-sub">Smart Messenger username, password &amp; sender mask</div></div>
    </div>
    <div class="card-body">
        <div class="dstrip">
            <div class="dlogo"><span class="dlogo-d">D</span></div>
            <div>
                <div class="ds-name">Dialog Axiata · eSMS</div>
                <div class="ds-sub">Smart Messenger · https://e-sms.dialog.lk · Sri Lanka</div>
            </div>
            <div style="margin-left:auto;">
                <span class="badge <?=$configured?'green':'grey'?>">
                    <span class="dot"></span><?=$configured?'Configured':'Not Set'?>
                </span>
            </div>
        </div>

        <div class="sec-lbl"><i class="fa-solid fa-pen-to-square"></i> <?=$configured?'Update':'Add'?> Credentials</div>

        <form method="POST" id="credForm" autocomplete="off">
            <input type="hidden" name="action" value="save_sms_settings">
            <div class="fgrid">
                <div class="fg">
                    <label>Username <span class="opt">(Smart Messenger login)</span></label>
                    <div class="iw">
                        <input type="text" name="sms_username" class="nopad"
                            value="<?=htmlspecialchars($cur_user)?>"
                            placeholder="94xxxxxxxxx" autocomplete="off" spellcheck="false">
                    </div>
                    <div class="hint">Your registered mobile number (e.g. 94771234567)</div>
                </div>
                <div class="fg">
                    <label>Password</label>
                    <div class="iw">
                        <input type="password" name="sms_password" id="inpPw"
                            value="SupunKadu@123"
                            placeholder="eSMS account password"
                            autocomplete="new-password" spellcheck="false">
                        <button type="button" class="tpw" onclick="togglePw()">
                            <i class="fa-solid fa-eye" id="pwIco"></i>
                        </button>
                    </div>
                    <div class="hint">Leave as-is to keep existing password</div>
                </div>
            </div>
            <div class="fg" style="max-width:calc(50% - 7px);">
                <label>Sender Mask <span class="opt">(optional, max 11 chars)</span></label>
                <div class="iw">
                    <input type="text" name="sms_mask" class="nopad"
                        value="<?=htmlspecialchars($cur_mask)?>"
                        placeholder="e.g. MyBrand" maxlength="11">
                </div>
                <div class="hint">Visible as sender on recipient's phone. Leave blank for account default.</div>
            </div>
            <div class="brow">
                <button type="submit" class="bp" id="savBtn">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <?=$configured?'Update Credentials':'Save Credentials'?>
                </button>
                <?php if($configured): ?>
                <button type="button" class="bd" onclick="openDel()">
                    <i class="fa-solid fa-trash"></i> Remove All
                </button>
                <?php endif; ?>
            </div>
        </form>
        <?php if($configured): ?>
        <form method="POST" id="delForm" style="display:none;">
            <input type="hidden" name="action" value="delete_sms_settings">
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- ── CARD 3: Test SMS ── -->
<?php if($configured): ?>
<div class="card">
    <div class="card-head">
        <div class="ch-icon test"><i class="fa-solid fa-paper-plane"></i></div>
        <div><div class="ch-title">Send Test SMS</div><div class="ch-sub">Verify configuration with a live SMS</div></div>
    </div>
    <div class="card-body">
        <?php if(!$tok_valid): ?>
        <div class="rbox warn" style="display:flex;align-items:flex-start;gap:9px;margin-bottom:18px;">
            <i class="fa-solid fa-triangle-exclamation" style="flex-shrink:0;margin-top:2px;"></i>
            <span><?=$tok_expired?'Token <strong>expired</strong>.':'No token found.'?>
            Click <strong>"Connect &amp; Refresh Token"</strong> above, or the Send button will auto-refresh it.</span>
        </div>
        <?php endif; ?>

        <div class="fgrid">
            <div class="fg">
                <label>Recipient Number</label>
                <div class="iw">
                    <input type="tel" id="tstNum" class="nopad" placeholder="07XXXXXXXX or 94XXXXXXXXX">
                </div>
                <div class="hint">Formats: 07X... · 94X... · 9-digit (e.g. 714551682)</div>
            </div>
            <div class="fg">
                <label>Sender Mask <span class="opt">(override)</span></label>
                <div class="iw">
                    <input type="text" id="tstMask" class="nopad"
                        value="<?=htmlspecialchars($cur_mask)?>"
                        placeholder="Leave blank for default" maxlength="11">
                </div>
            </div>
        </div>

        <div class="fg">
            <label>Message</label>
            <div class="iw">
                <textarea id="tstMsg" maxlength="459" oninput="upChars(this)"
                    placeholder="Enter your test message…">Test SMS via Dialog eSMS. <?=date('d/m/Y H:i')?></textarea>
            </div>
            <div class="crow">
                <div class="hint">160 chars = 1 SMS · Unicode reduces limit to 70</div>
                <div class="cct" id="chCt">0 / 160 · 1 SMS</div>
            </div>
            <div class="spts" id="sPts">
                <div class="spt"></div><div class="spt"></div><div class="spt"></div>
            </div>
        </div>

        <div class="brow">
            <button class="bp" id="sndBtn" onclick="sendSms()">
                <i class="fa-solid fa-paper-plane"></i> Send Test SMS
            </button>
            <button class="bs" onclick="clearMsg()">
                <i class="fa-solid fa-eraser"></i> Clear
            </button>
        </div>

        <div class="rbox" id="sndRes"></div>

        <div id="receipt" style="display:none;">
            <div class="divider"></div>
            <div class="sec-lbl"><i class="fa-solid fa-receipt"></i> Delivery Receipt</div>
            <div class="sgrid">
                <div class="si">
                    <div class="si-lbl"><i class="fa-solid fa-hashtag" style="font-size:9px;"></i> Campaign ID</div>
                    <div class="si-val" id="rc-cid">—</div>
                </div>
                <div class="si">
                    <div class="si-lbl"><i class="fa-solid fa-coins" style="font-size:9px;"></i> Cost (LKR)</div>
                    <div class="si-val" id="rc-cost">—</div>
                </div>
                <div class="si" style="grid-column:1/-1;">
                    <div class="si-lbl"><i class="fa-solid fa-wallet" style="font-size:9px;"></i> Remaining Balance</div>
                    <div class="si-val n" id="rc-bal" style="font-size:18px;">—</div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── CARD 4: API Reference ── -->
<div class="card">
    <div class="card-head">
        <div class="ch-icon info"><i class="fa-solid fa-book-open"></i></div>
        <div><div class="ch-title">API Quick Reference</div><div class="ch-sub">Dialog eSMS API v1.7</div></div>
    </div>
    <div class="card-body">
        <div class="sec-lbl"><i class="fa-solid fa-link"></i> Endpoints</div>
        <div class="sgrid" style="margin-bottom:18px;">
            <div class="si"><div class="si-lbl">Login / Token</div><div class="si-val" style="font-size:11px;">POST /api/v1/login</div></div>
            <div class="si"><div class="si-lbl">Send SMS</div><div class="si-val" style="font-size:11px;">POST /api/v1/sms</div></div>
            <div class="si"><div class="si-lbl">Check Transaction</div><div class="si-val" style="font-size:11px;">/api/v1/sms/check-transaction</div></div>
            <div class="si"><div class="si-lbl">Token Validity</div><div class="si-val n">12 hours</div></div>
            <div class="si"><div class="si-lbl">Portal</div><div class="si-val n"><a href="https://e-sms.dialog.lk" target="_blank" rel="noopener" style="color:var(--dr);">e-sms.dialog.lk ↗</a></div></div>
            <div class="si"><div class="si-lbl">Support</div><div class="si-val n">0777 03 62 62</div></div>
        </div>
        <div class="sec-lbl"><i class="fa-solid fa-triangle-exclamation"></i> Error Codes</div>
        <div class="sgrid">
            <div class="si"><div class="si-lbl">100</div><div class="si-val n">Invalid Token</div></div>
            <div class="si"><div class="si-lbl">101</div><div class="si-val n">Invalid Request Parameters</div></div>
            <div class="si"><div class="si-lbl">102</div><div class="si-val n">Account not found / invalid</div></div>
            <div class="si"><div class="si-lbl">103</div><div class="si-val n">Logical Error</div></div>
            <div class="si"><div class="si-lbl">104</div><div class="si-val n">Transaction ID already used</div></div>
            <div class="si"><div class="si-lbl">999</div><div class="si-val n">Internal Server Error</div></div>
        </div>
    </div>
</div>

</div><!-- /.pg -->

<!-- Delete modal -->
<div class="modal-ov" id="delMod" onclick="if(event.target===this)closeDel()">
    <div class="modal-bx">
        <div class="mbody">
            <div class="micon">🗑️</div>
            <div class="mtitle">Remove SMS Configuration?</div>
            <div class="mtext">All saved credentials, tokens and settings will be permanently deleted.</div>
        </div>
        <div class="mfoot">
            <button class="bs" onclick="closeDel()">Cancel</button>
            <button style="display:inline-flex;align-items:center;gap:6px;padding:10px 18px;background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;"
                onclick="document.getElementById('delForm').submit()">
                <i class="fa-solid fa-trash"></i> Yes, Remove
            </button>
        </div>
    </div>
</div>

<script>
const HANDLER='sms_api_handler.php';

function togglePw(){
    const i=document.getElementById('inpPw'),ic=document.getElementById('pwIco');
    i.type=i.type==='password'?'text':'password';
    ic.className='fa-solid '+(i.type==='text'?'fa-eye-slash':'fa-eye');
}

function upChars(el){
    const v=el.value,len=v.length,uni=/[^\x00-\x7F]/.test(v);
    const lim=uni?70:160,mli=uni?67:153;
    const pts=len<=lim?1:Math.ceil(len/mli);
    const c=document.getElementById('chCt');
    c.textContent=`${len} / ${lim} · ${pts} SMS`;
    c.className='cct'+(len>lim*1.8?' over':len>lim?' warn':'');
    document.querySelectorAll('.spt').forEach((p,i)=>p.classList.toggle('on',i<pts));
}
(function(){const t=document.getElementById('tstMsg');if(t)upChars(t);})();

function apiPost(body,cb){
    fetch(HANDLER,{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams(body).toString()
    })
    .then(r=>{
        if(!r.ok) throw new Error('HTTP '+r.status+' from server');
        return r.json();
    })
    .then(d=>cb(null,d))
    .catch(e=>cb(e.message,null));
}

function showBox(id,cls,html){
    const b=document.getElementById(id);
    b.style.display='block';b.className='rbox '+cls;b.innerHTML=html;
}

function fetchToken(){
    const btn=document.getElementById('btnConn');
    btn.disabled=true;
    btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Connecting…';
    showBox('connRes','load','<i class="fa-solid fa-spinner fa-spin"></i> Authenticating with <strong>e-sms.dialog.lk</strong>…');

    apiPost({action:'fetch_token'},function(err,d){
        btn.disabled=false;
        btn.innerHTML='<i class="fa-solid fa-plug"></i> Connect &amp; Refresh Token';
        if(err){
            showBox('connRes','err','<i class="fa-solid fa-circle-exclamation"></i> <strong>Request error:</strong> '+err);
            return;
        }
        if(d.success){
            showBox('connRes','ok',
                '<i class="fa-solid fa-circle-check"></i> Connected! Token valid until <strong>'+d.expiry+'</strong>'+
                (d.wallet!==undefined?' &nbsp;·&nbsp; Balance: <strong>LKR '+parseFloat(d.wallet).toFixed(2)+'</strong>':'')
            );
            const bv=document.getElementById('bc-val');
            if(bv&&d.wallet!==undefined) bv.textContent=parseFloat(d.wallet).toFixed(2);
            const bn=document.getElementById('bc-note');
            if(bn) bn.textContent='Live balance · Refreshed '+new Date().toLocaleTimeString();
            const tb=document.getElementById('tok-badge');
            if(tb) tb.innerHTML='<span class="badge green"><span class="dot"></span> Token Active</span>';
        } else {
            showBox('connRes','err','<i class="fa-solid fa-circle-exclamation"></i> <strong>Login failed:</strong> '+(d.message||'Unknown error'));
        }
    });
}

function sendSms(){
    const num=document.getElementById('tstNum').value.trim();
    const msg=document.getElementById('tstMsg').value.trim();
    const msk=document.getElementById('tstMask').value.trim();
    const btn=document.getElementById('sndBtn');

    if(!num){showBox('sndRes','err','<i class="fa-solid fa-circle-exclamation"></i> Enter a recipient number.');return;}
    if(!msg){showBox('sndRes','err','<i class="fa-solid fa-circle-exclamation"></i> Enter a message.');return;}

    btn.disabled=true;
    btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Sending…';
    showBox('sndRes','load','<i class="fa-solid fa-spinner fa-spin"></i> Sending SMS via Dialog eSMS API…');
    document.getElementById('receipt').style.display='none';

    apiPost({action:'send_test_sms',test_number:num,test_message:msg,test_mask:msk},function(err,d){
        btn.disabled=false;
        btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Send Test SMS';
        if(err){showBox('sndRes','err','<i class="fa-solid fa-circle-exclamation"></i> <strong>Error:</strong> '+err);return;}
        if(d.success){
            showBox('sndRes','ok','<i class="fa-solid fa-circle-check"></i> SMS sent! Campaign ID: <strong>'+d.campaign_id+'</strong>');
            document.getElementById('receipt').style.display='block';
            document.getElementById('rc-cid').textContent=d.campaign_id||'—';
            document.getElementById('rc-cost').textContent=parseFloat(d.cost||0).toFixed(2);
            document.getElementById('rc-bal').textContent='LKR '+parseFloat(d.balance||0).toFixed(2);
            const bv=document.getElementById('bc-val');
            if(bv&&d.balance) bv.textContent=parseFloat(d.balance).toFixed(2);
        } else {
            showBox('sndRes','err','<i class="fa-solid fa-circle-exclamation"></i> <strong>Failed:</strong> '+(d.message||'Unknown error'));
        }
    });
}

function clearMsg(){const t=document.getElementById('tstMsg');t.value='';upChars(t);}
function openDel(){document.getElementById('delMod').style.display='flex';}
function closeDel(){document.getElementById('delMod').style.display='none';}

document.getElementById('credForm')?.addEventListener('submit',function(){
    const b=document.getElementById('savBtn');
    b.disabled=true;b.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
});
</script>

<?php include 'footer.php'; ?>
