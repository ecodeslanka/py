<?php
include 'config.php';
include 'header.php';

/* ═══════════════════════════════════════════════════════
   Awurudu Wish Creator — AI-powered greeting card page
   Reads Gemini API key from ai_settings table
════════════════════════════════════════════════════════ */

/* ── Fetch Gemini API key ── */
$res_key   = mysqli_query($conn, "SELECT `value` FROM ai_settings WHERE `key`='gemini_api_key' LIMIT 1");
$row_key   = $res_key ? mysqli_fetch_assoc($res_key) : null;
$gemini_key = $row_key['value'] ?? '';
$has_key    = $gemini_key !== '';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Sinhala:wght@400;600&family=Noto+Sans+Tamil:wght@400;600&display=swap');
:root{
    --bg:#f4f1ee;--surface:#fff;--bdr:#ddd6ce;--bdrs:#ede9e4;
    --tx:#1c1612;--txm:#5c4f44;--txs:#9c8e84;
    --fn:'Inter',sans-serif;
    --r:10px;--sh:0 1px 4px rgba(0,0,0,.06),0 6px 20px rgba(0,0,0,.05);
    --pink:#d4537e;--pink-d:#993556;--pink-l:#fce4ec;
    --gold:#d97706;--gold-l:#fef3c7;
    --green:#1D9E75;--green-l:#e8f5e9;
    --purple:#534AB7;--purple-l:#ede9fe;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:13px;}
.pg{padding:22px 18px 80px;max-width:960px;}

/* breadcrumb */
.bcrumb{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--txs);margin-bottom:16px;}
.bcrumb a{color:var(--txs);text-decoration:none;}.bcrumb a:hover{color:var(--tx);}
.bcrumb .sep{color:#d1d5db;}

/* hero banner */
.hero{position:relative;overflow:hidden;background:linear-gradient(135deg,#fff8e1 0%,#fce4ec 50%,#e8f5e9 100%);border:1px solid #f0c8d4;border-radius:16px;padding:28px 28px 22px;margin-bottom:22px;text-align:center;}
.hero-deco{position:absolute;font-size:56px;opacity:.12;line-height:1;}
.hero-deco.tl{top:-10px;left:-4px;}
.hero-deco.tr{top:-10px;right:-4px;}
.hero-deco.bl{bottom:-10px;left:-4px;}
.hero-deco.br{bottom:-10px;right:-4px;}
.hero-emoji{font-size:44px;margin-bottom:8px;}
.hero h1{font-size:24px;font-weight:800;color:var(--pink-d);letter-spacing:-.02em;}
.hero p{font-size:12px;color:var(--txm);margin-top:5px;}
.hero-sub{display:inline-flex;align-items:center;gap:6px;margin-top:10px;background:rgba(255,255,255,.7);border:1px solid #f0c8d4;border-radius:20px;padding:4px 14px;font-size:11px;color:var(--pink-d);font-weight:600;}

/* no-key warning */
.no-key{background:#fef2f2;border:1px solid #fca5a5;border-radius:var(--r);padding:14px 18px;margin-bottom:18px;display:flex;gap:10px;align-items:flex-start;}
.no-key i{color:#dc2626;margin-top:1px;}
.no-key-text{font-size:13px;color:#991b1b;}
.no-key-text a{color:#dc2626;font-weight:600;}

/* layout */
.layout{display:grid;grid-template-columns:1fr 420px;gap:18px;align-items:start;}
@media(max-width:820px){.layout{grid-template-columns:1fr;}}

/* card */
.card{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);box-shadow:var(--sh);overflow:hidden;margin-bottom:16px;}
.card-head{display:flex;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid var(--bdrs);}
.ch-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.ch-icon.pink{background:var(--pink-l);}
.ch-icon.gold{background:var(--gold-l);}
.ch-icon.green{background:var(--green-l);}
.ch-title{font-size:14px;font-weight:700;color:var(--tx);}
.ch-sub{font-size:11px;color:var(--txs);}
.card-body{padding:18px;}

/* form */
.fg{margin-bottom:13px;}
.fg label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--txs);margin-bottom:5px;}
.fg input[type=text],.fg select,.fg textarea{
    width:100%;padding:9px 11px;border:1.5px solid var(--bdr);border-radius:7px;
    font-size:13px;font-family:var(--fn);color:var(--tx);background:#fff;
    transition:border .15s,box-shadow .15s;
}
.fg input:focus,.fg select:focus,.fg textarea:focus{outline:none;border-color:var(--pink);box-shadow:0 0 0 3px rgba(212,83,126,.1);}
.fg textarea{resize:vertical;min-height:75px;line-height:1.6;}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
@media(max-width:500px){.grid2{grid-template-columns:1fr;}}

/* chip selectors */
.chips{display:flex;flex-wrap:wrap;gap:6px;}
.chip{padding:5px 12px;border-radius:20px;font-size:12px;font-weight:500;cursor:pointer;border:1.5px solid var(--bdr);background:#f9f7f5;color:var(--txm);transition:all .15s;user-select:none;}
.chip:hover{border-color:var(--pink);color:var(--pink-d);}
.chip.active{background:var(--pink-l);border-color:#ED93B1;color:var(--pink-d);}
.chip.lang-chip.active{background:var(--purple-l);border-color:#AFA9EC;color:var(--purple);}

/* generate button */
.btn-gen{width:100%;padding:12px;background:linear-gradient(135deg,var(--pink),var(--pink-d));color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;font-family:var(--fn);box-shadow:0 3px 10px rgba(212,83,126,.3);transition:all .15s;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:4px;}
.btn-gen:hover{transform:translateY(-1px);box-shadow:0 5px 16px rgba(212,83,126,.4);}
.btn-gen:disabled{opacity:.55;cursor:not-allowed;transform:none;}

/* result panel */
.result-empty{text-align:center;padding:40px 20px;color:var(--txs);}
.result-empty .re-icon{font-size:36px;margin-bottom:10px;}
.result-empty p{font-size:12px;line-height:1.7;}

.wish-box{background:linear-gradient(135deg,#fffdf7,#fff5f8);border:1px solid #f0c8d4;border-radius:9px;padding:18px;font-size:14px;line-height:1.9;color:var(--tx);min-height:120px;word-break:break-word;}
.wish-box.sinhala{font-family:'Noto Sans Sinhala',sans-serif;font-size:15px;}
.wish-box.tamil{font-family:'Noto Sans Tamil',sans-serif;font-size:15px;}

.wish-meta{display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;}
.lang-badge{padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;background:var(--purple-l);color:var(--purple);}
.tone-badge{padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;background:var(--pink-l);color:var(--pink-d);}

.action-row{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;}
.btn-act{flex:1;min-width:90px;padding:9px 10px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;border:1.5px solid var(--bdr);background:#fff;color:var(--txm);font-family:var(--fn);transition:all .15s;display:flex;align-items:center;justify-content:center;gap:5px;}
.btn-act:hover{background:#f5f3f0;border-color:#c0b8b0;}
.btn-dl{background:var(--green);color:#fff;border-color:var(--green);}
.btn-dl:hover{background:#0F6E56;border-color:#0F6E56;}
.btn-regen{background:var(--purple-l);color:var(--purple);border-color:#AFA9EC;}
.btn-regen:hover{background:#ede9fe;}
.btn-wa{background:#25D366;color:#fff;border-color:#128C7E;}
.btn-wa:hover{background:#128C7E;}

/* loading spinner */
.spinner{display:inline-block;width:17px;height:17px;border:2.5px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .65s linear infinite;}
@keyframes spin{to{transform:rotate(360deg);}}

/* preview card (canvas placeholder) */
.card-preview{border-radius:9px;overflow:hidden;margin-top:14px;}
.card-preview canvas{display:block;width:100%;border-radius:9px;border:1px solid var(--bdr);}

/* section label */
.sec-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:var(--txs);margin-bottom:9px;display:flex;align-items:center;gap:7px;}
.sec-lbl::after{content:'';flex:1;height:1px;background:var(--bdrs);}

/* alert */
.alert{display:flex;align-items:center;gap:10px;padding:11px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:14px;border:1px solid;}
.alert.success{background:#f0fdf4;color:#166534;border-color:#86efac;}
.alert.error{background:#fef2f2;color:#991b1b;border-color:#fca5a5;}
</style>

<div class="pg">
    <!-- Breadcrumb -->
    <div class="bcrumb">
        <a href="dashboard.php"><i class="fa-solid fa-house"></i></a>
        <span class="sep">/</span>
        <a href="#">AI Tools</a>
        <span class="sep">/</span>
        <span style="color:var(--tx);font-weight:600;">Awurudu Wish Creator</span>
    </div>

    <!-- Hero -->
    <div class="hero">
        <div class="hero-deco tl">🌸</div>
        <div class="hero-deco tr">🏮</div>
        <div class="hero-deco bl">🎊</div>
        <div class="hero-deco br">🌺</div>
        <div class="hero-emoji">🌸</div>
        <h1>Awurudu Wish Creator</h1>
        <p>Generate beautiful, personalized Sinhala &amp; Tamil New Year greetings using AI</p>
        <div class="hero-sub">
            <i class="fa-solid fa-sparkles" style="font-size:10px;"></i>
            Powered by Google Gemini AI &nbsp;·&nbsp; Sinhala · Tamil · English
        </div>
    </div>

    <?php if(!$has_key):?>
    <div class="no-key">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div class="no-key-text">
            No Gemini API key configured. Please go to
            <a href="ai_settings.php">AI Settings</a> to add your key before using this feature.
        </div>
    </div>
    <?php endif;?>

    <div id="alertBox" style="display:none;"></div>

    <div class="layout">
        <!-- LEFT: Form -->
        <div>
            <div class="card">
                <div class="card-head">
                    <div class="ch-icon pink">🎉</div>
                    <div>
                        <div class="ch-title">Wish Details</div>
                        <div class="ch-sub">Fill in the details to personalize your greeting</div>
                    </div>
                </div>
                <div class="card-body">

                    <div class="sec-lbl"><i class="fa-solid fa-user"></i> People</div>
                    <div class="grid2">
                        <div class="fg">
                            <label>Your Name</label>
                            <input type="text" id="senderName" placeholder="e.g. Kasun">
                        </div>
                        <div class="fg">
                            <label>Recipient Name</label>
                            <input type="text" id="recipientName" placeholder="e.g. Malini Aunty">
                        </div>
                    </div>

                    <div class="fg">
                        <label>Relationship</label>
                        <select id="relation">
                            <option value="family member">Family member</option>
                            <option value="close friend">Close friend</option>
                            <option value="colleague or workmate">Colleague / workmate</option>
                            <option value="teacher or guru">Teacher / guru</option>
                            <option value="neighbour">Neighbour</option>
                            <option value="boss or superior">Boss / superior</option>
                            <option value="everyone in general">General / broadcast</option>
                        </select>
                    </div>

                    <div class="sec-lbl" style="margin-top:16px;"><i class="fa-solid fa-palette"></i> Style</div>
                    <div class="fg">
                        <label>Tone</label>
                        <div class="chips" id="toneChips">
                            <div class="chip active" data-v="warm and traditional">🪔 Traditional</div>
                            <div class="chip" data-v="fun and playful">😄 Fun</div>
                            <div class="chip" data-v="heartfelt and emotional">❤️ Heartfelt</div>
                            <div class="chip" data-v="formal and respectful">🙏 Formal</div>
                            <div class="chip" data-v="poetic and lyrical">🌺 Poetic</div>
                        </div>
                    </div>

                    <div class="fg">
                        <label>Language</label>
                        <div class="chips" id="langChips">
                            <div class="chip lang-chip active" data-v="Sinhala">🇱🇰 Sinhala</div>
                            <div class="chip lang-chip" data-v="Tamil">🌴 Tamil</div>
                            <div class="chip lang-chip" data-v="English">🌐 English</div>
                            <div class="chip lang-chip" data-v="Sinhala and English mixed">Mixed</div>
                        </div>
                    </div>

                    <div class="sec-lbl" style="margin-top:16px;"><i class="fa-solid fa-star"></i> Personalise</div>
                    <div class="fg">
                        <label>Extra Blessings <span style="text-transform:none;font-weight:400;color:#bbb;">(optional)</span></label>
                        <textarea id="extra" placeholder="e.g. wish them good health, new job success, happy marriage, long life…"></textarea>
                    </div>

                    <button class="btn-gen" id="genBtn" onclick="generateWish()" <?php echo !$has_key?'disabled':''?>>
                        <span id="genIcon"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
                        <span id="genLabel">Generate Wish</span>
                    </button>

                </div>
            </div>
        </div>

        <!-- RIGHT: Result -->
        <div>
            <div class="card">
                <div class="card-head">
                    <div class="ch-icon gold">📜</div>
                    <div>
                        <div class="ch-title">Your Greeting</div>
                        <div class="ch-sub">AI-generated personalised wish</div>
                    </div>
                </div>
                <div class="card-body">
                    <div id="resultEmpty" class="result-empty">
                        <div class="re-icon">✨</div>
                        <p>Fill in the details on the left<br>and click <strong>Generate Wish</strong> to create<br>your personalised Awurudu greeting.</p>
                    </div>

                    <div id="resultPanel" style="display:none;">
                        <div class="wish-meta">
                            <span class="lang-badge" id="langBadge">Sinhala</span>
                            <span class="tone-badge" id="toneBadge">Traditional</span>
                        </div>
                        <div class="wish-box" id="wishBox"></div>

                        <div class="action-row">
                            <button class="btn-act" onclick="copyWish()"><i class="fa-solid fa-copy"></i> Copy</button>
                            <button class="btn-act btn-regen" onclick="generateWish()"><i class="fa-solid fa-rotate"></i> Regenerate</button>
                            <button class="btn-act btn-wa" onclick="shareWhatsApp()"><i class="fa-brands fa-whatsapp"></i> WhatsApp</button>
                            <button class="btn-act btn-dl" onclick="downloadCard()"><i class="fa-solid fa-download"></i> Download Card</button>
                        </div>

                        <div class="card-preview" id="cardPreview"></div>
                    </div>
                </div>
            </div>

            <!-- Tips card -->
            <div class="card">
                <div class="card-head">
                    <div class="ch-icon green">💡</div>
                    <div>
                        <div class="ch-title">Tips</div>
                        <div class="ch-sub">Get the best results</div>
                    </div>
                </div>
                <div class="card-body" style="padding:14px 18px;">
                    <ul style="list-style:none;display:flex;flex-direction:column;gap:9px;">
                        <li style="font-size:12px;color:var(--txm);display:flex;gap:8px;"><span>🌸</span><span>Add <strong>recipient's name</strong> for a more personal touch.</span></li>
                        <li style="font-size:12px;color:var(--txm);display:flex;gap:8px;"><span>🙏</span><span>Use <strong>Formal</strong> tone for elders and superiors.</span></li>
                        <li style="font-size:12px;color:var(--txm);display:flex;gap:8px;"><span>📝</span><span>Mention specific blessings in the <strong>Extra</strong> field.</span></li>
                        <li style="font-size:12px;color:var(--txm);display:flex;gap:8px;"><span>🔄</span><span>Hit <strong>Regenerate</strong> for a fresh variation anytime.</span></li>
                        <li style="font-size:12px;color:var(--txm);display:flex;gap:8px;"><span>⬇️</span><span>Download as a <strong>PNG card</strong> to share on social media.</span></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Hidden canvas for card generation -->
<canvas id="cardCanvas" style="display:none;"></canvas>

<script>
const GEMINI_KEY = <?php echo json_encode($gemini_key); ?>;

let selTone = 'warm and traditional';
let selLang = 'Sinhala';
let currentWish = '';

/* Chip selectors */
document.querySelectorAll('#toneChips .chip').forEach(c=>{
    c.onclick=()=>{
        document.querySelectorAll('#toneChips .chip').forEach(x=>x.classList.remove('active'));
        c.classList.add('active');
        selTone = c.dataset.v;
    };
});
document.querySelectorAll('#langChips .chip').forEach(c=>{
    c.onclick=()=>{
        document.querySelectorAll('#langChips .chip').forEach(x=>x.classList.remove('active'));
        c.classList.add('active');
        selLang = c.dataset.v;
    };
});

function showAlert(msg, type){
    const b = document.getElementById('alertBox');
    const icon = type==='success' ? 'fa-circle-check' : 'fa-circle-exclamation';
    b.innerHTML = `<div class="alert ${type}"><i class="fa-solid ${icon}"></i>${msg}</div>`;
    b.style.display='block';
    setTimeout(()=>b.style.display='none', 4000);
}

async function generateWish(){
    if(!GEMINI_KEY){ showAlert('No Gemini API key set. Go to AI Settings first.','error'); return; }

    const sender    = document.getElementById('senderName').value.trim();
    const recipient = document.getElementById('recipientName').value.trim();
    const relation  = document.getElementById('relation').value;
    const extra     = document.getElementById('extra').value.trim();
    const btn       = document.getElementById('genBtn');

    btn.disabled = true;
    document.getElementById('genIcon').innerHTML = '<span class="spinner"></span>';
    document.getElementById('genLabel').textContent = 'Generating…';

    const prompt = `Write a beautiful Sinhala New Year (Awurudu / Aluth Avuruddak) greeting message.

Language: ${selLang}
Tone: ${selTone}
From: ${sender || 'the sender'}
To: ${recipient || 'the recipient'}
Relationship: ${relation}
${extra ? 'Include these blessings or wishes: ' + extra : ''}

Instructions:
- Write ONLY the greeting message, 3 to 6 sentences.
- Make it feel genuine, warm, and festive for Sinhala/Tamil New Year.
- Include traditional Awurudu wishes about prosperity, happiness, health, and good fortune.
- If Sinhala script is requested, write the full message in Sinhala unicode script.
- If Tamil script is requested, write the full message in Tamil unicode script.
- If English, write in English with references to Awurudu.
- If mixed, alternate naturally.
- End with a warm sign-off${sender ? ' from ' + sender : ''}.
- Do NOT add any titles, headers, or explanations — just the message.`;

    try{
        const res = await fetch(
            `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=${GEMINI_KEY}`,
            {
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body: JSON.stringify({
                    contents:[{parts:[{text:prompt}]}],
                    generationConfig:{temperature:0.9, maxOutputTokens:512}
                })
            }
        );
        const data = await res.json();
        if(data.error){ throw new Error(data.error.message); }
        const text = data.candidates?.[0]?.content?.parts?.[0]?.text || 'Could not generate wish.';
        currentWish = text.trim();
        showResult();
    } catch(e){
        showAlert('Error: ' + e.message, 'error');
    }

    btn.disabled = false;
    document.getElementById('genIcon').innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i>';
    document.getElementById('genLabel').textContent = 'Generate Again';
}

function showResult(){
    document.getElementById('resultEmpty').style.display = 'none';
    document.getElementById('resultPanel').style.display = 'block';

    const box = document.getElementById('wishBox');
    box.textContent = currentWish;
    box.className = 'wish-box';
    if(selLang==='Sinhala') box.classList.add('sinhala');
    else if(selLang==='Tamil') box.classList.add('tamil');

    document.getElementById('langBadge').textContent = selLang;
    document.getElementById('toneBadge').textContent = selTone.charAt(0).toUpperCase()+selTone.slice(1);

    renderCardPreview();
}

function copyWish(){
    if(!currentWish) return;
    navigator.clipboard.writeText(currentWish).then(()=>{
        showAlert('Wish copied to clipboard!','success');
    });
}

function shareWhatsApp(){
    if(!currentWish) return;
    const text = encodeURIComponent("🌸 Subha Aluth Avuruddak Wewa! 🌸\n\n" + currentWish);
    window.open('https://wa.me/?text=' + text, '_blank');
}

function renderCardPreview(){
    const canvas = document.getElementById('cardCanvas');
    drawCard(canvas, 900, 540);
    const preview = document.getElementById('cardPreview');
    const img = document.createElement('img');
    img.src = canvas.toDataURL('image/png');
    img.style.cssText = 'width:100%;border-radius:9px;border:1px solid #e0d8d0;display:block;';
    preview.innerHTML = '';
    preview.appendChild(img);
}

function drawCard(canvas, W, H){
    const ctx = canvas.getContext('2d');
    canvas.width = W; canvas.height = H;

    /* Background gradient */
    const bg = ctx.createLinearGradient(0,0,W,H);
    bg.addColorStop(0,'#fff8e1');
    bg.addColorStop(0.45,'#fce4ec');
    bg.addColorStop(1,'#e8f5e9');
    ctx.fillStyle = bg; ctx.fillRect(0,0,W,H);

    /* Decorative circles */
    ctx.fillStyle = 'rgba(255,200,80,0.13)';
    ctx.beginPath();ctx.arc(80,80,110,0,Math.PI*2);ctx.fill();
    ctx.beginPath();ctx.arc(W-80,H-80,90,0,Math.PI*2);ctx.fill();
    ctx.fillStyle = 'rgba(212,83,126,0.09)';
    ctx.beginPath();ctx.arc(W-70,60,70,0,Math.PI*2);ctx.fill();
    ctx.beginPath();ctx.arc(60,H-60,60,0,Math.PI*2);ctx.fill();

    /* Border */
    ctx.strokeStyle='rgba(212,83,126,0.25)';ctx.lineWidth=3;
    roundRect(ctx,18,18,W-36,H-36,14,false,true);
    ctx.strokeStyle='rgba(212,83,126,0.1)';ctx.lineWidth=1;
    roundRect(ctx,28,28,W-56,H-56,10,false,true);

    /* Title */
    ctx.fillStyle='#993556';
    ctx.font='bold 26px Arial, sans-serif';
    ctx.textAlign='center';
    ctx.fillText('🌸  Subha Aluth Avuruddak Wewa!  🌸',W/2,68);

    /* Divider */
    ctx.strokeStyle='rgba(212,83,126,0.3)';ctx.lineWidth=1;
    ctx.beginPath();ctx.moveTo(80,85);ctx.lineTo(W-80,85);ctx.stroke();

    /* Wish text — word wrap */
    ctx.fillStyle='#2d1b12';
    ctx.font='19px Arial, sans-serif';
    ctx.textAlign='center';
    const maxW = W - 130;
    const lines = wrapText(ctx, currentWish, maxW);
    let y = 122;
    lines.forEach(l=>{ ctx.fillText(l,W/2,y); y+=34; });

    /* Bottom decorations */
    ctx.fillStyle='rgba(153,53,86,0.35)';
    ctx.font='26px Arial, sans-serif';
    ctx.fillText('🏮  🌺  🎊  🌺  🏮',W/2,H-48);

    ctx.fillStyle='rgba(153,53,86,0.45)';
    ctx.font='13px Arial, sans-serif';
    ctx.fillText('Awurudu ' + new Date().getFullYear(), W/2, H-26);
}

function wrapText(ctx, text, maxWidth){
    const words=text.split(' ');
    const lines=[];
    let line='';
    words.forEach(w=>{
        const test=line+w+' ';
        if(ctx.measureText(test).width>maxWidth && line){
            lines.push(line.trim());line=w+' ';
        } else line=test;
    });
    if(line) lines.push(line.trim());
    return lines.slice(0,11);
}

function roundRect(ctx,x,y,w,h,r,fill,stroke){
    ctx.beginPath();
    ctx.moveTo(x+r,y);ctx.lineTo(x+w-r,y);ctx.arcTo(x+w,y,x+w,y+r,r);
    ctx.lineTo(x+w,y+h-r);ctx.arcTo(x+w,y+h,x+w-r,y+h,r);
    ctx.lineTo(x+r,y+h);ctx.arcTo(x,y+h,x,y+h-r,r);
    ctx.lineTo(x,y+r);ctx.arcTo(x,y,x+r,y,r);ctx.closePath();
    if(fill) ctx.fill();if(stroke) ctx.stroke();
}

function downloadCard(){
    if(!currentWish){ showAlert('Generate a wish first!','error'); return; }
    const canvas = document.getElementById('cardCanvas');
    drawCard(canvas, 1200, 720);
    const link = document.createElement('a');
    link.download = 'awurudu_wish_' + Date.now() + '.png';
    link.href = canvas.toDataURL('image/png');
    link.click();
    showAlert('Card downloaded!','success');
}
</script>

<?php include 'footer.php'; ?>