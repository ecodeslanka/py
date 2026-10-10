<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Yelo AI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg0:#f5f2ee;--bg1:#ede9e3;--bg2:#e3ddd6;
  --ink0:#1a1714;--ink1:#4a4540;--ink2:#8c8580;
  --user-bg:#1a1714;--user-ink:#f5f2ee;
  --ai-bg:#ffffff;--ai-ink:#1a1714;
  --brand:#e8a020;--brand2:#f0c06a;
  --line:rgba(26,23,20,.1);--shadow:0 2px 20px rgba(26,23,20,.08);
  --r:18px;--fn:'DM Sans',sans-serif;--mono:'Space Mono',monospace;
  --safe-bot:env(safe-area-inset-bottom,0px);
}
[data-dark]{
  --bg0:#111010;--bg1:#191717;--bg2:#221f1f;
  --ink0:#f2ede8;--ink1:#b5ada6;--ink2:#6b6560;
  --user-bg:#e8a020;--user-ink:#ffffff;
  --ai-bg:#221f1f;--ai-ink:#f2ede8;
  --line:rgba(242,237,232,.08);--shadow:0 2px 20px rgba(0,0,0,.3);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
html,body{height:100%;overflow:hidden}
body{font-family:var(--fn);background:var(--bg0);color:var(--ink0);display:flex;flex-direction:column;height:100dvh;-webkit-font-smoothing:antialiased;transition:background .25s,color .25s}
.topbar{display:flex;align-items:center;justify-content:space-between;padding:12px 16px 10px;background:var(--bg0);border-bottom:1px solid var(--line);flex-shrink:0;gap:10px;z-index:10;transition:background .25s,border-color .25s}
.tb-left{display:flex;align-items:center;gap:10px}
.avatar{width:38px;height:38px;border-radius:12px;background:var(--ink0);display:flex;align-items:center;justify-content:center;flex-shrink:0;position:relative;transition:background .25s}
.avatar svg{width:20px;height:20px}
.avatar-dot{position:absolute;bottom:-2px;right:-2px;width:10px;height:10px;border-radius:50%;background:#22c55e;border:2px solid var(--bg0);transition:border-color .25s,background .2s}
.avatar-dot.busy{background:#f59e0b}
.avatar-dot.error{background:#ef4444}
.tb-name{font-size:16px;font-weight:700;color:var(--ink0);line-height:1.2;letter-spacing:-.02em}
.tb-name span{color:var(--brand)}
.tb-status{font-size:10px;color:var(--ink2);font-family:var(--mono);margin-top:1px}
.tb-actions{display:flex;gap:6px}
.icon-btn{width:36px;height:36px;border-radius:10px;border:1px solid var(--line);background:var(--bg1);color:var(--ink1);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s}
.icon-btn:hover,.icon-btn:active{background:var(--bg2);color:var(--ink0)}
.icon-btn svg{width:16px;height:16px}
.pills-bar{display:flex;gap:7px;padding:9px 14px;overflow-x:auto;flex-shrink:0;scrollbar-width:none;border-bottom:1px solid var(--line);background:var(--bg0);transition:background .25s,border-color .25s}
.pills-bar::-webkit-scrollbar{display:none}
.pill{display:flex;align-items:center;gap:5px;padding:6px 12px;border-radius:99px;font-size:12px;font-weight:500;color:var(--ink1);background:var(--bg1);border:1px solid var(--line);white-space:nowrap;cursor:pointer;transition:all .15s;flex-shrink:0}
.pill:hover,.pill:active{background:var(--ink0);color:var(--bg0);border-color:var(--ink0)}
.messages{flex:1;overflow-y:auto;padding:14px 14px 8px;display:flex;flex-direction:column;gap:10px;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;scroll-behavior:smooth}
.messages::-webkit-scrollbar{width:0}
@media(min-width:640px){.messages::-webkit-scrollbar{width:4px}.messages::-webkit-scrollbar-thumb{background:var(--bg2);border-radius:2px}}
.msg-row{display:flex;gap:8px;align-items:flex-end;animation:msgIn .22s cubic-bezier(.34,1.4,.64,1) both}
@keyframes msgIn{from{opacity:0;transform:translateY(8px) scale(.97)}to{opacity:1;transform:none}}
.msg-row.user{flex-direction:row-reverse}
.msg-row.sys-row{justify-content:center}
.msg-avatar-sm{width:28px;height:28px;border-radius:9px;background:var(--bg2);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px}
.bubble{max-width:min(78%,380px);padding:10px 13px;border-radius:var(--r);font-size:13.5px;line-height:1.65;word-break:break-word;position:relative}
.msg-row.ai .bubble{background:var(--ai-bg);color:var(--ai-ink);border-radius:var(--r) var(--r) var(--r) 4px;box-shadow:var(--shadow);transition:background .25s}
.msg-row.user .bubble{background:var(--user-bg);color:var(--user-ink);border-radius:var(--r) var(--r) 4px var(--r)}
.bubble.sys-bubble{background:var(--bg2);color:var(--ink2);font-size:11px;border-radius:8px;padding:6px 12px;max-width:80%;text-align:center;font-family:var(--mono)}
.msg-meta{font-size:10px;color:var(--ink2);font-family:var(--mono);margin-top:3px;padding:0 2px}
.msg-row.user .msg-meta{text-align:right}
.bubble table{width:100%;border-collapse:collapse;font-size:11px;font-family:var(--mono);margin:8px 0}
.bubble th{background:var(--ink0);color:var(--bg0);padding:5px 9px;font-size:9px;text-align:left;letter-spacing:.05em;text-transform:uppercase}
.bubble td{padding:4px 9px;border-bottom:1px solid var(--line);color:var(--ink1)}
.bubble tr:last-child td{border-bottom:none}
.bubble tr:nth-child(even) td{background:rgba(0,0,0,.02)}
.bubble code{background:var(--bg1);border-radius:4px;padding:1px 5px;font-family:var(--mono);font-size:11px;color:var(--brand)}
.bubble pre{background:var(--bg1);border-radius:9px;padding:10px 12px;overflow-x:auto;margin:7px 0;font-family:var(--mono);font-size:11px;color:var(--ink0);line-height:1.6}
.bubble pre code{background:none;padding:0;color:inherit}
.bubble strong{font-weight:600}
.bubble em{color:var(--ink1)}
.bubble ul,.bubble ol{padding-left:16px;margin:5px 0}
.bubble li{margin:2px 0}
.bubble p{margin:3px 0}
.bubble h1,.bubble h2,.bubble h3{font-size:14px;margin:7px 0 3px;font-weight:600}
.bubble a{color:var(--brand)}
.bubble blockquote{border-left:3px solid var(--brand);padding-left:9px;margin:7px 0;color:var(--ink1)}
.bubble hr{border:none;border-top:1px solid var(--line);margin:8px 0}
.typing-dots{display:flex;gap:4px;align-items:center;padding:4px 0}
.typing-dots span{width:6px;height:6px;border-radius:50%;background:var(--ink2);animation:dot 1.4s infinite}
.typing-dots span:nth-child(2){animation-delay:.2s}
.typing-dots span:nth-child(3){animation-delay:.4s}
@keyframes dot{0%,80%,100%{opacity:.3;transform:scale(.8)}40%{opacity:1;transform:scale(1.1)}}
.sql-strip{display:flex;align-items:center;gap:7px;background:var(--bg1);border-radius:8px;padding:6px 10px;margin-top:9px;overflow-x:auto;border:1px solid var(--line)}
.sql-label{font-size:9px;font-weight:700;color:var(--brand);text-transform:uppercase;letter-spacing:.1em;white-space:nowrap;font-family:var(--mono)}
.sql-strip code{font-size:10px;color:var(--ink1);white-space:nowrap;font-family:var(--mono)}
.mode-badge{display:inline-flex;align-items:center;gap:4px;font-size:9px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:2px 8px;border-radius:99px;margin-bottom:7px;font-family:var(--mono)}
.mode-badge.live{background:rgba(34,197,94,.1);color:#16a34a}
.mode-badge.code{background:rgba(99,102,241,.12);color:#6366f1}
.mode-badge.mixed{background:rgba(232,160,32,.12);color:var(--brand)}
.chart-block{background:var(--bg1);border-radius:10px;padding:10px;margin-top:9px;border:1px solid var(--line)}
.chart-tabs-sm{display:flex;gap:4px;margin-bottom:7px;flex-wrap:wrap}
.ct-btn{padding:3px 10px;border-radius:99px;font-size:10px;font-family:var(--mono);border:1px solid var(--line);background:var(--bg0);color:var(--ink2);cursor:pointer;transition:all .15s}
.ct-btn.active,.ct-btn:hover{background:var(--ink0);color:var(--bg0);border-color:var(--ink0)}
.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;flex:1;gap:12px;text-align:center;padding:28px 20px;animation:fadeIn .4s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.empty-icon{width:56px;height:56px;border-radius:18px;background:var(--ink0);display:flex;align-items:center;justify-content:center;margin-bottom:4px;transition:background .25s}
.empty-icon svg{width:28px;height:28px}
.empty-title{font-size:22px;font-weight:700;letter-spacing:-.02em;color:var(--ink0)}
.empty-title span{color:var(--brand)}
.empty-sub{font-size:13px;color:var(--ink2);line-height:1.6;max-width:270px}
.starter-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;width:100%;max-width:340px;margin-top:6px}
.starter-card{background:var(--ai-bg);border:1px solid var(--line);border-radius:13px;padding:11px 12px;cursor:pointer;transition:all .15s;text-align:left;box-shadow:var(--shadow)}
.starter-card:hover,.starter-card:active{border-color:var(--brand);box-shadow:0 0 0 3px rgba(232,160,32,.12);transform:translateY(-1px)}
.starter-emoji{font-size:17px;margin-bottom:5px}
.starter-label{font-size:11.5px;font-weight:500;color:var(--ink0);line-height:1.4}
.drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:99;opacity:0;pointer-events:none;transition:opacity .25s;backdrop-filter:blur(2px)}
.drawer-overlay.open{opacity:1;pointer-events:all}
.drawer{position:fixed;top:0;left:0;bottom:0;width:min(290px,82vw);background:var(--bg0);z-index:100;transform:translateX(-105%);transition:transform .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;box-shadow:4px 0 40px rgba(0,0,0,.15)}
.drawer.open{transform:translateX(0)}
.drawer-head{padding:18px 16px 12px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between}
.drawer-logo{font-size:17px;font-weight:700;color:var(--ink0)}
.drawer-logo span{color:var(--brand)}
.drawer-close{width:30px;height:30px;border-radius:8px;background:var(--bg1);border:1px solid var(--line);cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--ink1);font-size:15px}
.drawer-body{flex:1;overflow-y:auto;padding:12px}
.drawer-section{margin-bottom:18px}
.drawer-section-title{font-size:9px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--ink2);font-family:var(--mono);margin-bottom:6px;padding:0 2px}
.drawer-item{padding:9px 11px;border-radius:9px;font-size:12.5px;color:var(--ink1);cursor:pointer;transition:all .15s;margin-bottom:2px;line-height:1.4;border:1px solid transparent}
.drawer-item:hover,.drawer-item:active{background:var(--bg2);color:var(--ink0);border-color:var(--line)}
.input-area{flex-shrink:0;background:var(--bg0);border-top:1px solid var(--line);padding:10px 14px;padding-bottom:calc(10px + var(--safe-bot));transition:background .25s,border-color .25s}
.input-row{display:flex;align-items:flex-end;gap:7px;background:var(--ai-bg);border:1.5px solid var(--line);border-radius:15px;padding:7px 7px 7px 13px;box-shadow:var(--shadow);transition:border-color .2s,background .25s}
.input-row:focus-within{border-color:var(--brand)}
.chat-textarea{flex:1;background:none;border:none;outline:none;font-family:var(--fn);font-size:14px;color:var(--ink0);resize:none;line-height:1.5;max-height:100px;min-height:22px;overflow-y:auto;scrollbar-width:none}
.chat-textarea::placeholder{color:var(--ink2)}
.chat-textarea::-webkit-scrollbar{display:none}
.input-btns{display:flex;gap:5px;align-items:center;flex-shrink:0}
.mic-btn,.send-btn-main{width:35px;height:35px;border-radius:9px;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;flex-shrink:0}
.mic-btn{background:var(--bg1);color:var(--ink2)}
.mic-btn:hover{color:var(--ink0)}
.mic-btn.active{color:var(--brand);background:rgba(232,160,32,.1)}
.send-btn-main{background:var(--ink0);color:var(--bg0)}
.send-btn-main:hover,.send-btn-main:active{background:var(--brand);transform:scale(.95)}
.send-btn-main:disabled{opacity:.35;cursor:not-allowed;transform:none}
.mic-btn svg,.send-btn-main svg{width:16px;height:16px}
.disclaimer-sm{text-align:center;font-size:10px;color:var(--ink2);font-family:var(--mono);margin-top:6px}
</style>
</head>
<body>

<!-- DRAWER -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer" id="drawer">
  <div class="drawer-head">
    <span class="drawer-logo">Yelo<span>AI</span></span>
    <button class="drawer-close" onclick="closeDrawer()">✕</button>
  </div>
  <div class="drawer-body">
    <div class="drawer-section">
      <div class="drawer-section-title">📊 Sales Data</div>
      <div class="drawer-item" onclick="drawerSend(this)">Total imports today with status</div>
      <div class="drawer-item" onclick="drawerSend(this)">Top 5 routes by gross sales</div>
      <div class="drawer-item" onclick="drawerSend(this)">Top 10 customers by final bill</div>
      <div class="drawer-item" onclick="drawerSend(this)">Failed or invalid import records</div>
      <div class="drawer-item" onclick="drawerSend(this)">Gross sales and discounts summary</div>
      <div class="drawer-item" onclick="drawerSend(this)">Delivery person performance</div>
      <div class="drawer-item" onclick="drawerSend(this)">Sales trend last 7 days by date</div>
      <div class="drawer-item" onclick="drawerSend(this)">Average bill amount per route</div>
    </div>
    <div class="drawer-section">
      <div class="drawer-section-title">🖥️ System</div>
      <div class="drawer-item" onclick="drawerSend(this)">List all PHP pages in this project</div>
      <div class="drawer-item" onclick="drawerSend(this)">How does the invoice import work</div>
      <div class="drawer-item" onclick="drawerSend(this)">How does AI chatbot system work</div>
      <div class="drawer-item" onclick="drawerSend(this)">How is Gemini API key managed</div>
    </div>
    <div class="drawer-section">
      <div class="drawer-section-title">💡 Insights</div>
      <div class="drawer-item" onclick="drawerSend(this)">What insights can you give me today?</div>
      <div class="drawer-item" onclick="drawerSend(this)">Summarise today's business performance</div>
      <div class="drawer-item" onclick="drawerSend(this)">Which routes are underperforming?</div>
    </div>
  </div>
</div>

<!-- TOP BAR -->
<div class="topbar">
  <div class="tb-left">
    <button class="icon-btn" onclick="openDrawer()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="16" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
      </svg>
    </button>
    <div class="avatar">
      <svg viewBox="0 0 24 24" fill="none" stroke="#f5f2ee" stroke-width="1.8">
        <circle cx="12" cy="8" r="3.5"/>
        <path d="M5 20v-1a7 7 0 0114 0v1"/>
        <path d="M16 6.5a2.5 2.5 0 110 3" stroke="#e8a020" stroke-width="1.8"/>
      </svg>
      <div class="avatar-dot" id="avatarDot"></div>
    </div>
    <div>
      <div class="tb-name">Yelo<span>AI</span></div>
      <div class="tb-status" id="statusText">gemini-2.5-flash · live DB</div>
    </div>
  </div>
  <div class="tb-actions">
    <button class="icon-btn" onclick="clearChat()" title="Clear">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <polyline points="1 4 1 10 7 10"/>
        <path d="M3.51 15a9 9 0 102.13-9.36L1 10"/>
      </svg>
    </button>
    <button class="icon-btn" onclick="toggleTheme()" title="Theme">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <circle cx="12" cy="12" r="5"/>
        <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
        <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
      </svg>
    </button>
  </div>
</div>

<!-- PILLS -->
<div class="pills-bar">
  <div class="pill" onclick="pillSend('Sales today')">📈 Sales today</div>
  <div class="pill" onclick="pillSend('Top 5 routes by gross sales')">🏆 Top routes</div>
  <div class="pill" onclick="pillSend('Top 10 customers by final bill')">👥 Customers</div>
  <div class="pill" onclick="pillSend('Delivery person performance')">🚚 Deliveries</div>
  <div class="pill" onclick="pillSend('Gross sales and discounts summary')">💰 Discounts</div>
  <div class="pill" onclick="pillSend('Total imports today with status')">📦 Imports</div>
  <div class="pill" onclick="pillSend('Sales trend last 7 days by date')">📊 7-day trend</div>
</div>

<!-- MESSAGES -->
<div class="messages" id="messages">
  <div class="empty-state" id="emptyState">
    <div class="empty-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="#e8a020" stroke-width="1.8">
        <circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 3"/>
      </svg>
    </div>
    <div class="empty-title">Yelo<span>AI</span></div>
    <div class="empty-sub">Your live business analyst — powered by Gemini. Ask about sales, routes, customers, or your system.</div>
    <div class="starter-grid">
      <div class="starter-card" onclick="starterSend(this)">
        <div class="starter-emoji">📊</div>
        <div class="starter-label">Today's sales summary</div>
      </div>
      <div class="starter-card" onclick="starterSend(this)">
        <div class="starter-emoji">🏆</div>
        <div class="starter-label">Top 5 routes by revenue</div>
      </div>
      <div class="starter-card" onclick="starterSend(this)">
        <div class="starter-emoji">📈</div>
        <div class="starter-label">Sales trend last 7 days</div>
      </div>
      <div class="starter-card" onclick="starterSend(this)">
        <div class="starter-emoji">🖥️</div>
        <div class="starter-label">How does the AI chatbot work?</div>
      </div>
    </div>
  </div>
</div>

<!-- INPUT -->
<div class="input-area">
  <div class="input-row">
    <textarea id="userInput" class="chat-textarea"
      placeholder="Ask about your sales data or system…"
      rows="1" onkeydown="handleKey(event)" oninput="autoResize(this)"></textarea>
    <div class="input-btns">
      <button class="mic-btn" id="micBtn" onclick="toggleMic()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <rect x="9" y="2" width="6" height="11" rx="3"/>
          <path d="M5 10a7 7 0 0014 0"/>
          <line x1="12" y1="19" x2="12" y2="22"/>
          <line x1="8" y1="22" x2="16" y2="22"/>
        </svg>
      </button>
      <button class="send-btn-main" id="sendBtn" onclick="sendMessage()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="22" y1="2" x2="11" y2="13"/>
          <polygon points="22 2 15 22 11 13 2 9 22 2"/>
        </svg>
      </button>
    </div>
  </div>
  <div class="disclaimer-sm">Yelo AI · Gemini 2.5 Flash · Live DB · Verify critical figures</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script>
/* ── CONFIG ───────────────────────────────────────── */
const API_ENDPOINT = 'ai_chatbot_handler.php';

/* ── STATE ────────────────────────────────────────── */
let chatHistory = [];   // [{role:'user',text:''}, {role:'model',text:''}]
let loading = false;
let chartInstances = {};
let chartCounter = 0;
let micRecog = null;
let micActive = false;

/* ── THEME ────────────────────────────────────────── */
(function(){ if(localStorage.getItem('yelo-dark')==='1') document.documentElement.setAttribute('data-dark',''); })();
function toggleTheme(){
  const d=document.documentElement;
  if(d.hasAttribute('data-dark')){ d.removeAttribute('data-dark'); localStorage.removeItem('yelo-dark'); }
  else{ d.setAttribute('data-dark',''); localStorage.setItem('yelo-dark','1'); }
}

/* ── DRAWER ───────────────────────────────────────── */
function openDrawer(){ document.getElementById('drawer').classList.add('open'); document.getElementById('drawerOverlay').classList.add('open'); }
function closeDrawer(){ document.getElementById('drawer').classList.remove('open'); document.getElementById('drawerOverlay').classList.remove('open'); }

/* ── QUICK SENDERS ────────────────────────────────── */
function drawerSend(el){ setInput(el.textContent.trim()); closeDrawer(); sendMessage(); }
function pillSend(txt){ setInput(txt); sendMessage(); }
function starterSend(el){ setInput(el.querySelector('.starter-label').textContent.trim()); sendMessage(); }
function setInput(val){ const ta=document.getElementById('userInput'); ta.value=val; autoResize(ta); }

/* ── INPUT UTILS ──────────────────────────────────── */
function autoResize(el){ el.style.height='auto'; el.style.height=Math.min(el.scrollHeight,100)+'px'; }
function handleKey(e){ if(e.key==='Enter'&&!e.shiftKey&&!loading){ e.preventDefault(); sendMessage(); } }
function scrollBottom(){ const m=document.getElementById('messages'); m.scrollTo({top:m.scrollHeight,behavior:'smooth'}); }
function ts(){ return new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}); }
function renderMd(t){ try{ return marked.parse(t); }catch(e){ return t.replace(/\n/g,'<br>'); } }
function escHtml(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function removeEmptyState(){ const es=document.getElementById('emptyState'); if(es)es.remove(); }

/* ── DOT STATUS ───────────────────────────────────── */
function setDot(state){
  const d=document.getElementById('avatarDot');
  d.className='avatar-dot'+(state!=='online'?' '+state:'');
}

/* ── APPEND MESSAGES ──────────────────────────────── */
function appendUserMsg(text){
  removeEmptyState();
  const msgs=document.getElementById('messages');
  const row=document.createElement('div');
  row.className='msg-row user';
  row.innerHTML=`<div><div class="bubble">${escHtml(text)}</div><div class="msg-meta">${ts()}</div></div><div class="msg-avatar-sm">🧑</div>`;
  msgs.appendChild(row); scrollBottom();
}

function showTyping(){
  const msgs=document.getElementById('messages');
  const row=document.createElement('div');
  row.className='msg-row ai'; row.id='typingRow';
  row.innerHTML=`<div class="msg-avatar-sm">🤖</div><div class="bubble"><div class="typing-dots"><span></span><span></span><span></span></div></div>`;
  msgs.appendChild(row); scrollBottom();
}
function hideTyping(){ const el=document.getElementById('typingRow'); if(el)el.remove(); }

function appendAiMsg(data){
  /* Backend JSON: { success, answer, sql, explanation, row_count, code_context } */
  hideTyping();
  const msgs=document.getElementById('messages');

  // badge
  let badge='';
  if(data.sql && data.code_context)  badge='<span class="mode-badge mixed">⚡ Data + Code</span><br>';
  else if(data.code_context)         badge='<span class="mode-badge code">🖥 System</span><br>';
  else if(data.sql)                  badge='<span class="mode-badge live">📊 Live Data</span><br>';

  const bubble=document.createElement('div');
  bubble.className='bubble';
  bubble.innerHTML=badge+renderMd(data.answer||'');

  // SQL strip inside bubble
  if(data.sql){
    const strip=document.createElement('div');
    strip.className='sql-strip';
    strip.innerHTML=`<span class="sql-label">SQL</span><code>${escHtml(data.sql)}</code>`;
    bubble.appendChild(strip);
  }

  const meta=document.createElement('div');
  meta.className='msg-meta';
  meta.textContent=ts()+(data.row_count?' · '+data.row_count+' rows':'');

  const avatarSm=document.createElement('div');
  avatarSm.className='msg-avatar-sm';
  avatarSm.textContent='🤖';

  const inner=document.createElement('div');
  inner.appendChild(bubble); inner.appendChild(meta);

  const row=document.createElement('div');
  row.className='msg-row ai';
  row.appendChild(avatarSm); row.appendChild(inner);
  msgs.appendChild(row);

  // inject charts
  if(data.answer){ const tables=parseTables(data.answer); if(tables.length) injectCharts(bubble,tables); }

  scrollBottom();
}

function appendErrMsg(text){
  hideTyping();
  const msgs=document.getElementById('messages');
  const row=document.createElement('div');
  row.className='msg-row ai';
  row.innerHTML=`<div class="msg-avatar-sm">⚠️</div><div><div class="bubble" style="color:var(--ink1)">${escHtml(text)}</div><div class="msg-meta">${ts()}</div></div>`;
  msgs.appendChild(row); scrollBottom();
}

function appendSys(text){
  const msgs=document.getElementById('messages');
  const row=document.createElement('div');
  row.className='msg-row sys-row';
  row.innerHTML=`<div class="bubble sys-bubble">${escHtml(text)}</div>`;
  msgs.appendChild(row); scrollBottom();
}

/* ── CHARTS ───────────────────────────────────────── */
function parseTables(md){
  const tables=[]; const re=/(\|.+\|\n\|[-| :]+\|\n(?:\|.+\|\n?)+)/g; let m;
  while((m=re.exec(md))!==null){
    const lines=m[1].trim().split('\n').filter(l=>l.trim());
    if(lines.length<3) continue;
    const headers=lines[0].split('|').map(h=>h.trim()).filter(Boolean);
    const rows=lines.slice(2).map(l=>l.split('|').map(c=>c.trim()).filter(Boolean));
    tables.push({headers,rows});
  }
  return tables;
}
function getNumCols(t){
  return t.headers.reduce((a,_,i)=>{
    if(t.rows.some(r=>{ const v=(r[i]||'').replace(/[,\s$%LKR]/g,''); return v!==''&&!isNaN(parseFloat(v)); })) a.push(i);
    return a;
  },[]);
}
const COLORS=['rgba(232,160,32,.85)','rgba(34,197,94,.85)','rgba(99,102,241,.85)','rgba(59,130,246,.85)','rgba(236,72,153,.85)','rgba(20,184,166,.85)','rgba(245,101,101,.85)'];
const BCOLORS=COLORS.map(c=>c.replace('.85','1'));

function buildChart(cid,table,type='bar'){
  const old=chartInstances[cid]; if(old){old.destroy();delete chartInstances[cid];}
  const nc=getNumCols(table); if(!nc.length) return;
  const li=table.headers.findIndex((_,i)=>!nc.includes(i));
  const labels=table.rows.map(r=>{ let s=(li>=0?r[li]:'')||''; return s.length>14?s.slice(0,14)+'…':s; });
  const datasets=nc.map((ci,di)=>({
    label:table.headers[ci]||'Value',
    data:table.rows.map(r=>parseFloat((r[ci]||'').replace(/[^0-9.\-]/g,''))||0),
    backgroundColor:(type==='line'?COLORS[di%COLORS.length].replace('.85','.15'):(type==='pie'||type==='doughnut')?COLORS.slice(0,table.rows.length):COLORS[di%COLORS.length]),
    borderColor:(type==='line'?BCOLORS[di%BCOLORS.length]:(type==='pie'||type==='doughnut')?BCOLORS.slice(0,table.rows.length):BCOLORS[di%BCOLORS.length]),
    borderWidth:type==='line'?2:1,fill:type==='line',tension:.4,
    pointRadius:type==='line'?4:0,pointHoverRadius:6,
  }));
  const canvas=document.querySelector(`#${cid} canvas`); if(!canvas) return;
  const dark=document.documentElement.hasAttribute('data-dark');
  const tc=dark?'#6b6560':'#8c8580'; const gc=dark?'rgba(255,255,255,.05)':'rgba(0,0,0,.06)';
  chartInstances[cid]=new Chart(canvas.getContext('2d'),{
    type,data:{labels,datasets},
    options:{responsive:true,
      plugins:{
        legend:{labels:{color:tc,font:{size:11},boxWidth:10}},
        tooltip:{backgroundColor:dark?'#221f1f':'#fff',borderColor:dark?'rgba(255,255,255,.1)':'rgba(0,0,0,.1)',borderWidth:1,titleColor:dark?'#f2ede8':'#1a1714',bodyColor:tc,titleFont:{size:11},bodyFont:{size:11},callbacks:{label:ctx=>` ${ctx.dataset.label}: ${ctx.raw>=1000?ctx.raw.toLocaleString():ctx.raw}`}}
      },
      scales:(type==='pie'||type==='doughnut')?{}:{
        x:{ticks:{color:tc,font:{size:10},maxRotation:30},grid:{color:gc}},
        y:{ticks:{color:tc,font:{size:10},callback:v=>v>=1000?v.toLocaleString():v},grid:{color:gc}}
      },animation:{duration:500}}
  });
}
function switchChart(cid,btn,type){
  btn.closest('.chart-tabs-sm').querySelectorAll('.ct-btn').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  buildChart(cid,document.getElementById(cid)._table,type);
}
function injectCharts(bubble,tables){
  tables.forEach(table=>{
    if(!getNumCols(table).length) return;
    chartCounter++;
    const cid=`chart-${chartCounter}`;
    const block=document.createElement('div');
    block.className='chart-block'; block.id=cid;
    block.innerHTML=`
      <div class="chart-tabs-sm">
        <button class="ct-btn active" onclick="switchChart('${cid}',this,'bar')">Bar</button>
        <button class="ct-btn" onclick="switchChart('${cid}',this,'line')">Line</button>
        <button class="ct-btn" onclick="switchChart('${cid}',this,'pie')">Pie</button>
        <button class="ct-btn" onclick="switchChart('${cid}',this,'doughnut')">Ring</button>
      </div>
      <canvas height="200"></canvas>`;
    bubble.appendChild(block);
    block._table=table;
    setTimeout(()=>buildChart(cid,table,'bar'),60);
  });
}

/* ── VOICE INPUT ──────────────────────────────────── */
function toggleMic(){
  if(!('webkitSpeechRecognition' in window||'SpeechRecognition' in window)){ appendSys('Voice not supported in this browser.'); return; }
  if(micActive){ micRecog&&micRecog.stop(); return; }
  const SR=window.SpeechRecognition||window.webkitSpeechRecognition;
  micRecog=new SR(); micRecog.lang='en-US'; micRecog.interimResults=false;
  micRecog.onstart=()=>{ micActive=true; document.getElementById('micBtn').classList.add('active'); };
  micRecog.onresult=e=>{ const ta=document.getElementById('userInput'); ta.value=e.results[0][0].transcript; autoResize(ta); };
  micRecog.onend=()=>{ micActive=false; document.getElementById('micBtn').classList.remove('active'); };
  micRecog.start();
}

/* ── CLEAR ────────────────────────────────────────── */
function clearChat(){
  chatHistory=[];
  Object.values(chartInstances).forEach(c=>{try{c.destroy();}catch(e){}});
  chartInstances={};
  const msgs=document.getElementById('messages');
  msgs.innerHTML='';
  const es=document.createElement('div');
  es.id='emptyState'; es.className='empty-state';
  es.innerHTML=`
    <div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#e8a020" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 3"/></svg></div>
    <div class="empty-title">Yelo<span style="color:var(--brand)">AI</span></div>
    <div class="empty-sub">Ask about sales, routes, customers, or how your system works.</div>
    <div class="starter-grid">
      <div class="starter-card" onclick="starterSend(this)"><div class="starter-emoji">📊</div><div class="starter-label">Today's sales summary</div></div>
      <div class="starter-card" onclick="starterSend(this)"><div class="starter-emoji">🏆</div><div class="starter-label">Top 5 routes by revenue</div></div>
      <div class="starter-card" onclick="starterSend(this)"><div class="starter-emoji">📈</div><div class="starter-label">Sales trend last 7 days</div></div>
      <div class="starter-card" onclick="starterSend(this)"><div class="starter-emoji">🖥️</div><div class="starter-label">How does the AI chatbot work?</div></div>
    </div>`;
  msgs.appendChild(es);
  setDot('online');
  document.getElementById('statusText').textContent='gemini-2.5-flash · live DB';
}

/* ── SEND MESSAGE ─────────────────────────────────────────────────────────────
   Backend (ai_chatbot_handler.php) expects:
     POST JSON: { message: string, history: [{role:'user'|'model', text:string}] }
   Backend returns:
     { success:bool, answer:string, sql:string, explanation:string,
       row_count:int, code_context:bool, message?:string }
─────────────────────────────────────────────────────────────────────────────── */
async function sendMessage(){
  if(loading) return;
  const input=document.getElementById('userInput');
  const text=input.value.trim();
  if(!text) return;

  input.value=''; input.style.height='auto';
  appendUserMsg(text);

  // Add to history BEFORE sending; backend slices last MAX_HISTORY from it
  chatHistory.push({role:'user', text});

  loading=true;
  document.getElementById('sendBtn').disabled=true;
  document.getElementById('statusText').textContent='thinking…';
  setDot('busy');
  showTyping();

  try{
    const res=await fetch(API_ENDPOINT,{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({
        message: text,
        // Send history WITHOUT the current message (handler builds step1Messages itself)
        history: chatHistory.slice(0,-1)
      })
    });

    if(!res.ok) throw new Error('Server returned HTTP '+res.status);

    const data=await res.json();

    if(!data.success){
      appendErrMsg('⚠️ '+(data.message||'Unknown error from server.'));
      chatHistory.pop(); // remove failed msg
    } else {
      appendAiMsg(data);
      chatHistory.push({role:'model', text: data.answer||''});
    }

  } catch(err){
    appendErrMsg('⚠️ Network error: '+err.message);
    chatHistory.pop();
  }

  loading=false;
  document.getElementById('sendBtn').disabled=false;
  document.getElementById('statusText').textContent='gemini-2.5-flash · live DB';
  setDot('online');
}
</script>
</body>
</html>