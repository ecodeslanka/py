<?php
include 'config.php';
include 'header.php';

/* ═══════════════════════════════════════════════════════
   AI Settings — Google Gemini API Key Management
   Table: ai_settings  (key VARCHAR, value TEXT, updated_at DATETIME)
   INSERT/UPDATE single row: key = 'gemini_api_key'
════════════════════════════════════════════════════════ */

$msg      = '';
$msg_type = '';

/* ── Ensure table exists ── */
mysqli_query($conn,"CREATE TABLE IF NOT EXISTS ai_settings (
    `key`        VARCHAR(100) PRIMARY KEY,
    `value`      TEXT,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

/* ── Handle Save ── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])){

    if($_POST['action']==='save_api_key'){
        $raw_key = trim($_POST['gemini_api_key'] ?? '');
        if($raw_key === ''){
            $msg = 'API key cannot be empty.'; $msg_type = 'error';
        } else {
            $esc = mysqli_real_escape_string($conn, $raw_key);
            $ok  = mysqli_query($conn,
                "INSERT INTO ai_settings (`key`,`value`) VALUES ('gemini_api_key','$esc')
                 ON DUPLICATE KEY UPDATE `value`='$esc', updated_at=NOW()");
            if($ok){ $msg = 'Gemini API key saved successfully.'; $msg_type = 'success'; }
            else    { $msg = 'DB error: '.mysqli_error($conn);    $msg_type = 'error';   }
        }
    }

    if($_POST['action']==='delete_api_key'){
        $ok = mysqli_query($conn,"DELETE FROM ai_settings WHERE `key`='gemini_api_key'");
        if($ok){ $msg = 'API key removed.'; $msg_type = 'success'; }
        else    { $msg = 'DB error: '.mysqli_error($conn); $msg_type = 'error'; }
    }
}

/* ── Fetch current key ── */
$res     = mysqli_query($conn,"SELECT `value`, updated_at FROM ai_settings WHERE `key`='gemini_api_key'");
$row_key = $res ? mysqli_fetch_assoc($res) : null;
$saved_key   = $row_key['value']      ?? '';
$updated_at  = $row_key['updated_at'] ?? '';
$key_exists  = $saved_key !== '';
/* Masked preview — show first 8 + stars */
$masked = $key_exists
    ? substr($saved_key,0,8).str_repeat('•', max(12, strlen($saved_key)-8))
    : '';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');
:root{
    --bg:#eef0f3;--surface:#fff;--bdr:#d2d6db;--bdrs:#e4e7ec;
    --tx:#1a1f2e;--txm:#58626e;--txs:#9aa3ad;
    --fn:'Inter',sans-serif;--mn:'JetBrains Mono',monospace;
    --r:8px;--sh:0 1px 3px rgba(0,0,0,.07),0 4px 14px rgba(0,0,0,.05);
    --red:#dc2626;--grn:#166534;--blu:#1e40af;
    --gemini-a:#1a73e8;--gemini-b:#4285f4;--gemini-c:#34a853;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:13px;}
.pg{padding:22px 18px 60px;max-width:860px;}

/* top bar */
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:22px;}
.pg-h1{font-size:22px;font-weight:800;letter-spacing:-.02em;}
.pg-h1 em{color:var(--gemini-a);font-style:normal;}
.pg-sub{font-size:11px;color:var(--txs);margin-top:3px;}

/* breadcrumb pill */
.bcrumb{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--txs);margin-bottom:16px;}
.bcrumb a{color:var(--txs);text-decoration:none;}.bcrumb a:hover{color:var(--tx);}
.bcrumb .sep{color:#d1d5db;}

/* alert banner */
.alert{display:flex;align-items:center;gap:10px;padding:11px 16px;border-radius:var(--r);font-size:13px;font-weight:600;margin-bottom:16px;border:1px solid;}
.alert.success{background:#f0fdf4;color:#166534;border-color:#86efac;}
.alert.error{background:#fef2f2;color:#991b1b;border-color:#fca5a5;}

/* card */
.card{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);box-shadow:var(--sh);overflow:hidden;margin-bottom:16px;}
.card-head{display:flex;align-items:center;gap:13px;padding:16px 20px;border-bottom:1px solid var(--bdrs);}
.ch-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.ch-icon.gemini{background:linear-gradient(135deg,#e8f0fe,#d2e3fc);color:var(--gemini-a);}
.ch-icon.key{background:linear-gradient(135deg,#fffbeb,#fef3c7);color:#d97706;}
.ch-icon.info{background:linear-gradient(135deg,#eff6ff,#dbeafe);color:#1d4ed8;}
.ch-title{font-size:14px;font-weight:700;color:var(--tx);}
.ch-sub{font-size:11px;color:var(--txs);margin-top:2px;}
.card-body{padding:20px;}

/* Gemini branding strip */
.gemini-strip{display:flex;align-items:center;gap:14px;padding:14px 18px;background:linear-gradient(135deg,#e8f0fe 0%,#f3e8fd 50%,#e6f4ea 100%);border-radius:8px;margin-bottom:18px;border:1px solid #c5d9f8;}
.gemini-logo{width:36px;height:36px;border-radius:8px;background:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 1px 4px rgba(0,0,0,.12);flex-shrink:0;}
.gemini-logo svg{width:22px;height:22px;}
.gemini-title{font-size:13px;font-weight:700;color:#1a1f2e;}
.gemini-desc{font-size:11px;color:var(--txm);margin-top:1px;}
.gemini-badge{margin-left:auto;background:#e8f0fe;color:var(--gemini-a);border:1px solid #c5d9f8;border-radius:12px;padding:3px 10px;font-size:10px;font-weight:700;white-space:nowrap;}

/* Form elements */
.fg{margin-bottom:14px;}
.fg label{display:block;font-size:10px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px;}
.fg label span{font-weight:400;color:#d1d5db;text-transform:none;margin-left:4px;}
.input-wrap{position:relative;}
.input-wrap input[type=text],
.input-wrap input[type=password]{
    width:100%;padding:9px 44px 9px 12px;
    border:1.5px solid var(--bdr);border-radius:7px;
    font-size:13px;font-family:var(--mn);color:var(--tx);background:#fff;
    transition:border .15s,box-shadow .15s;
}
.input-wrap input:focus{outline:none;border-color:var(--gemini-a);box-shadow:0 0 0 3px rgba(26,115,232,.1);}
.toggle-vis{position:absolute;right:10px;top:50%;transform:translateY(-50%);
    background:none;border:none;cursor:pointer;color:var(--txs);font-size:15px;padding:4px;border-radius:4px;}
.toggle-vis:hover{color:var(--tx);background:#f3f4f6;}
.key-hint{font-size:11px;color:var(--txs);margin-top:5px;display:flex;align-items:center;gap:5px;}

/* Current key status */
.key-status{background:#f8fafc;border:1px solid var(--bdrs);border-radius:8px;padding:13px 15px;margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.ks-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0;}
.ks-dot.active{background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.2);}
.ks-dot.inactive{background:#e5e7eb;}
.ks-info{flex:1;}
.ks-label{font-size:11px;font-weight:700;color:var(--txm);}
.ks-value{font-size:12px;font-family:var(--mn);color:var(--tx);margin-top:2px;word-break:break-all;}
.ks-time{font-size:10px;color:var(--txs);margin-top:2px;}
.ks-none{font-size:12px;color:var(--txs);font-style:italic;}

/* Buttons */
.btn-row{display:flex;gap:9px;flex-wrap:wrap;margin-top:4px;}
.btn-save{display:inline-flex;align-items:center;gap:7px;padding:9px 22px;background:linear-gradient(135deg,var(--gemini-a),#1558d6);color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;font-family:var(--fn);box-shadow:0 2px 8px rgba(26,115,232,.3);transition:all .15s;}
.btn-save:hover{background:linear-gradient(135deg,#1558d6,#0d47a1);box-shadow:0 4px 14px rgba(26,115,232,.35);}
.btn-save:disabled{opacity:.5;cursor:not-allowed;}
.btn-del{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;background:#fff;color:#dc2626;border:1.5px solid #fca5a5;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:var(--fn);transition:all .15s;}
.btn-del:hover{background:#fef2f2;border-color:#f87171;}
.btn-test{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;background:#f0fdf4;color:#166534;border:1.5px solid #86efac;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:var(--fn);transition:all .15s;}
.btn-test:hover{background:#dcfce7;}
.btn-rst{display:inline-flex;align-items:center;gap:5px;padding:9px 14px;background:#f0f0f0;color:var(--txm);border:1px solid var(--bdr);border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;font-family:var(--fn);text-decoration:none;}
.btn-rst:hover{background:#e5e7eb;}

/* Info card */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.info-item{background:#f8fafc;border:1px solid var(--bdrs);border-radius:7px;padding:11px 13px;}
.info-item .ii-lbl{font-size:10px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.info-item .ii-val{font-size:12px;font-weight:600;color:var(--tx);}
.info-item .ii-val a{color:var(--gemini-a);text-decoration:none;}
.info-item .ii-val a:hover{text-decoration:underline;}

/* Steps */
.steps{counter-reset:step;}
.step{display:flex;gap:12px;margin-bottom:13px;align-items:flex-start;}
.step-num{width:24px;height:24px;border-radius:50%;background:var(--gemini-a);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;}
.step-body{font-size:12px;color:var(--txm);line-height:1.7;flex:1;}
.step-body strong{color:var(--tx);}
.step-body code{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:4px;padding:1px 6px;font-family:var(--mn);font-size:11px;color:#1e40af;}

/* Test result box */
#testResult{display:none;margin-top:12px;padding:11px 15px;border-radius:7px;font-size:12px;font-weight:600;border:1px solid;}
#testResult.ok{background:#f0fdf4;color:#166534;border-color:#86efac;}
#testResult.err{background:#fef2f2;color:#991b1b;border-color:#fca5a5;}
#testResult.testing{background:#eff6ff;color:#1e40af;border-color:#bfdbfe;}

/* Section label */
.sec-label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:var(--txs);margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.sec-label::after{content:'';flex:1;height:1px;background:var(--bdrs);}

@media(max-width:600px){.info-grid{grid-template-columns:1fr;}}
</style>

<div class="pg">
    <!-- Breadcrumb -->
    <div class="bcrumb">
        <a href="dashboard.php"><i class="fa-solid fa-house"></i></a>
        <span class="sep">/</span>
        <span>Settings</span>
        <span class="sep">/</span>
        <span style="color:var(--tx);font-weight:600;">AI Settings</span>
    </div>

    <!-- Top bar -->
    <div class="topbar">
        <div>
            <div class="pg-h1">AI <em>Settings</em></div>
            <div class="pg-sub">Configure AI integrations · Google Gemini API</div>
        </div>
        <a href="dashboard.php" class="btn-rst"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>

    <!-- Alert -->
    <?php if($msg):?>
    <div class="alert <?php echo $msg_type;?>">
        <i class="fa-solid <?php echo $msg_type==='success'?'fa-circle-check':'fa-circle-exclamation';?>"></i>
        <?php echo htmlspecialchars($msg);?>
    </div>
    <?php endif;?>

    <!-- ── CARD 1: Gemini API Key ── -->
    <div class="card">
        <div class="card-head">
            <div class="ch-icon key"><i class="fa-solid fa-key"></i></div>
            <div>
                <div class="ch-title">Google Gemini API Key</div>
                <div class="ch-sub">Used for AI-powered features across the system</div>
            </div>
        </div>
        <div class="card-body">

            <!-- Gemini branding -->
            <div class="gemini-strip">
                <div class="gemini-logo">
                    <!-- Gemini star icon SVG -->
                    <svg viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M14 2C14 2 14.5 9 17.5 11.5C20.5 14 26 14 26 14C26 14 20.5 14 17.5 16.5C14.5 19 14 26 14 26C14 26 13.5 19 10.5 16.5C7.5 14 2 14 2 14C2 14 7.5 14 10.5 11.5C13.5 9 14 2 14 2Z" fill="url(#g1)"/>
                        <defs>
                            <linearGradient id="g1" x1="2" y1="2" x2="26" y2="26" gradientUnits="userSpaceOnUse">
                                <stop stop-color="#4285F4"/>
                                <stop offset="0.5" stop-color="#9B72CB"/>
                                <stop offset="1" stop-color="#34A853"/>
                            </linearGradient>
                        </defs>
                    </svg>
                </div>
                <div>
                    <div class="gemini-title">Google Gemini</div>
                    <div class="gemini-desc">Generative AI · gemini-pro · gemini-1.5-flash</div>
                </div>
                <div class="gemini-badge">
                    <?php if($key_exists):?>
                    <i class="fa-solid fa-circle" style="color:#22c55e;font-size:7px;vertical-align:middle;margin-right:3px;"></i> Connected
                    <?php else:?>
                    <i class="fa-solid fa-circle" style="color:#e5e7eb;font-size:7px;vertical-align:middle;margin-right:3px;"></i> Not Configured
                    <?php endif;?>
                </div>
            </div>

            <!-- Current key status -->
            <div class="sec-label"><i class="fa-solid fa-circle-info"></i> Current Status</div>
            <div class="key-status">
                <div class="ks-dot <?php echo $key_exists?'active':'inactive';?>"></div>
                <div class="ks-info">
                    <?php if($key_exists):?>
                    <div class="ks-label">API Key Configured</div>
                    <div class="ks-value" id="ks_masked"><?php echo htmlspecialchars($masked);?></div>
                    <?php if($updated_at):?>
                    <div class="ks-time"><i class="fa-solid fa-clock"></i> Last updated: <?php echo date('d M Y  H:i',strtotime($updated_at));?></div>
                    <?php endif;?>
                    <?php else:?>
                    <div class="ks-none">No API key configured. Add one below.</div>
                    <?php endif;?>
                </div>
                <?php if($key_exists):?>
                <button type="button" class="btn-test" id="testBtn" onclick="testApiKey()">
                    <i class="fa-solid fa-bolt"></i> Test Key
                </button>
                <?php endif;?>
            </div>

            <div id="testResult"></div>

            <!-- Update key form -->
            <div class="sec-label" style="margin-top:18px;"><i class="fa-solid fa-pen-to-square"></i> <?php echo $key_exists?'Update':'Add';?> API Key</div>
            <form method="POST" id="keyForm" autocomplete="off">
                <input type="hidden" name="action" value="save_api_key">
                <div class="fg">
                    <label>Gemini API Key <span>(paste your key below)</span></label>
                    <div class="input-wrap">
                        <input type="password" name="gemini_api_key" id="apiKeyInput"
                            placeholder="AIza••••••••••••••••••••••••••••••••••••"
                            autocomplete="new-password" spellcheck="false">
                        <button type="button" class="toggle-vis" onclick="toggleVis()" title="Show / Hide">
                            <i class="fa-solid fa-eye" id="visIcon"></i>
                        </button>
                    </div>
                    <div class="key-hint">
                        <i class="fa-solid fa-lock" style="color:#9ca3af;font-size:10px;"></i>
                        Stored securely in your database. Never shared externally.
                    </div>
                </div>
                <div class="btn-row">
                    <button type="submit" class="btn-save" id="saveBtn">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <?php echo $key_exists?'Update API Key':'Save API Key';?>
                    </button>
                    <?php if($key_exists):?>
                    <button type="button" class="btn-del" onclick="confirmRemove()">
                        <i class="fa-solid fa-trash"></i> Remove Key
                    </button>
                    <?php endif;?>
                </div>
            </form>

            <!-- Delete form (hidden, submitted by JS) -->
            <?php if($key_exists):?>
            <form method="POST" id="delForm" style="display:none;">
                <input type="hidden" name="action" value="delete_api_key">
            </form>
            <?php endif;?>
        </div>
    </div>

    <!-- ── CARD 2: How to get a key ── -->
    <div class="card">
        <div class="card-head">
            <div class="ch-icon info"><i class="fa-solid fa-circle-question"></i></div>
            <div>
                <div class="ch-title">How to Get a Gemini API Key</div>
                <div class="ch-sub">Follow these steps to generate your free API key</div>
            </div>
        </div>
        <div class="card-body">
            <div class="steps">
                <div class="step">
                    <div class="step-num">1</div>
                    <div class="step-body">Go to <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener" style="color:var(--gemini-a);font-weight:600;">Google AI Studio <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:10px;"></i></a> and sign in with your Google account.</div>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <div class="step-body">Click <strong>"Create API Key"</strong> and select or create a Google Cloud project.</div>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <div class="step-body">Copy the generated key — it starts with <code>AIza</code> followed by alphanumeric characters.</div>
                </div>
                <div class="step">
                    <div class="step-num">4</div>
                    <div class="step-body">Paste it in the <strong>API Key</strong> field above and click <strong>Save API Key</strong>.</div>
                </div>
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="ii-lbl"><i class="fa-solid fa-link"></i> API Console</div>
                    <div class="ii-val"><a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">aistudio.google.com</a></div>
                </div>
                <div class="info-item">
                    <div class="ii-lbl"><i class="fa-solid fa-book-open"></i> Documentation</div>
                    <div class="ii-val"><a href="https://ai.google.dev/gemini-api/docs" target="_blank" rel="noopener">ai.google.dev/gemini-api</a></div>
                </div>
                <div class="info-item">
                    <div class="ii-lbl"><i class="fa-solid fa-microchip-ai"></i> Default Model</div>
                    <div class="ii-val">gemini-1.5-flash</div>
                </div>
                <div class="info-item">
                    <div class="ii-lbl"><i class="fa-solid fa-circle-dollar-to-slot"></i> Free Tier</div>
                    <div class="ii-val">Available — 15 RPM, 1M TPM</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete confirm modal -->
<div class="modal-overlay" id="delConfirm" onclick="if(event.target===this)document.getElementById('delConfirm').classList.remove('open')" style="position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1050;display:none;align-items:center;justify-content:center;padding:12px;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:400px;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,.28);">
    <div style="padding:28px 24px 20px;text-align:center;">
      <div style="font-size:40px;color:#dc2626;margin-bottom:14px;"><i class="fa-solid fa-trash-can"></i></div>
      <div style="font-size:15px;font-weight:700;margin-bottom:8px;">Remove API Key?</div>
      <div style="font-size:13px;color:#6b7280;line-height:1.6;">The Gemini API key will be permanently deleted. AI features will stop working until a new key is added.</div>
    </div>
    <div style="padding:14px 22px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:10px;">
      <button onclick="document.getElementById('delConfirm').classList.remove('open')" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:7px;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">Cancel</button>
      <button onclick="document.getElementById('delForm').submit()" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:7px;background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border:none;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;"><i class="fa-solid fa-trash"></i> Remove</button>
    </div>
  </div>
</div>

<script>
function toggleVis(){
    const inp=document.getElementById('apiKeyInput'),icon=document.getElementById('visIcon');
    if(inp.type==='password'){inp.type='text';icon.className='fa-solid fa-eye-slash';}
    else{inp.type='password';icon.className='fa-solid fa-eye';}
}
function confirmRemove(){
    document.getElementById('delConfirm').style.display='flex';
    document.getElementById('delConfirm').classList.add('open');
}
document.getElementById('keyForm')?.addEventListener('submit',function(){
    const b=document.getElementById('saveBtn');
    const v=document.getElementById('apiKeyInput').value.trim();
    if(!v){return;}
    b.disabled=true;b.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
});

/* Test API key — calls Gemini list models endpoint */
function testApiKey(){
    const box=document.getElementById('testResult'),btn=document.getElementById('testBtn');
    if(!box||!btn) return;
    box.style.display='block';
    box.className='testing';
    box.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Testing connection to Gemini API…';
    btn.disabled=true;
    // Use a PHP endpoint to proxy the test (avoids CORS / key exposure)
    fetch('test_gemini_key.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=test'})
    .then(r=>r.json())
    .then(d=>{
        btn.disabled=false;
        if(d.success){
            box.className='ok';
            box.innerHTML='<i class="fa-solid fa-circle-check"></i> Connection successful! Model: <strong>'+d.model+'</strong>';
        } else {
            box.className='err';
            box.innerHTML='<i class="fa-solid fa-circle-exclamation"></i> Test failed: '+(d.message||'Unknown error');
        }
    })
    .catch(()=>{
        btn.disabled=false;
        box.className='err';
        box.innerHTML='<i class="fa-solid fa-circle-exclamation"></i> Network error. Check server connectivity.';
    });
}
/* style the delete modal overlay properly */
document.getElementById('delConfirm').addEventListener('click',function(e){if(e.target===this)this.style.display='none';});
</script>

<?php include 'footer.php'; ?>
