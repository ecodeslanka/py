<?php
include 'config.php';
include 'header.php';
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">

<style>
:root {
    --bg:       #080c10;
    --surface:  #0d1117;
    --panel:    #111820;
    --border:   #1e2d3d;
    --border2:  #243344;
    --tx:       #e2eaf3;
    --txm:      #7a94ad;
    --txs:      #3d5166;
    --accent:   #00d4ff;
    --accent2:  #7c5cfc;
    --green:    #00e676;
    --amber:    #ffab40;
    --red:      #ff5252;
    --fn:       'Syne', sans-serif;
    --mn:       'JetBrains Mono', monospace;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); font-family: var(--fn); color: var(--tx); }

/* ── Page header ── */
.ai-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 24px 14px;
    border-bottom: 1px solid var(--border);
    background: var(--surface);
    gap: 12px; flex-wrap: wrap;
}
.ai-title {
    display: flex; align-items: center; gap: 10px;
    font-size: 18px; font-weight: 800; letter-spacing: -.02em;
}
.ai-pulse {
    width: 10px; height: 10px; border-radius: 50%;
    background: var(--green);
    box-shadow: 0 0 10px var(--green);
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0%,100%{ box-shadow: 0 0 4px var(--green); }
    50%     { box-shadow: 0 0 16px var(--green); }
}
.ai-sub { font-size: 11px; color: var(--txm); margin-top: 2px; font-family: var(--mn); }
.export-bar { display: flex; gap: 8px; flex-wrap: wrap; }
.exp-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; border-radius: 6px; font-size: 12px; font-weight: 700;
    cursor: pointer; border: 1px solid; font-family: var(--fn);
    transition: all .15s; letter-spacing: .02em; white-space: nowrap;
}
.exp-btn:disabled { opacity: .35; cursor: not-allowed; }
.exp-pdf   { background: rgba(255,82,82,.1);   color: var(--red);   border-color: rgba(255,82,82,.3); }
.exp-excel { background: rgba(0,230,118,.1);   color: var(--green); border-color: rgba(0,230,118,.3); }
.exp-chart { background: rgba(0,212,255,.1);   color: var(--accent);border-color: rgba(0,212,255,.3); }
.exp-btn:not(:disabled):hover { filter: brightness(1.2); transform: translateY(-1px); }

/* ── Shell ── */
.chat-shell {
    display: grid;
    grid-template-columns: 220px 1fr;
    height: calc(100vh - 210px);
    min-height: 520px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-top: none;
}

/* ── Sidebar ── */
.chat-sidebar {
    background: var(--surface);
    border-right: 1px solid var(--border);
    padding: 14px 10px;
    overflow-y: auto;
    display: flex; flex-direction: column; gap: 0;
}
.sidebar-heading {
    font-size: 9px; font-weight: 700; letter-spacing: .12em;
    color: var(--txs); text-transform: uppercase;
    margin: 10px 0 6px; padding: 0 4px;
    font-family: var(--mn);
}
.quick-list { list-style: none; display: flex; flex-direction: column; gap: 2px; margin-bottom: 6px; }
.quick-list li {
    font-size: 11px; color: var(--txm);
    padding: 6px 10px; border-radius: 6px;
    cursor: pointer; transition: all .15s;
    border: 1px solid transparent; line-height: 1.45;
    font-family: var(--mn);
}
.quick-list li:hover { background: var(--panel); color: var(--tx); border-color: var(--border2); }
.sdivider { border-top: 1px solid var(--border); margin: 10px 0; }
.db-status { display: flex; align-items: center; gap: 6px; font-size: 10px; color: var(--txm); font-family: var(--mn); padding: 0 4px; }
.db-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--amber); flex-shrink: 0; }
.db-dot.online  { background: var(--green); box-shadow: 0 0 6px var(--green); }
.db-dot.offline { background: var(--red); }

/* ── Main ── */
.chat-main { display: flex; flex-direction: column; background: var(--bg); overflow: hidden; }
.chat-messages {
    flex: 1; overflow-y: auto; padding: 20px 22px;
    display: flex; flex-direction: column; gap: 14px;
    scroll-behavior: smooth;
}
.chat-messages::-webkit-scrollbar { width: 3px; }
.chat-messages::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 2px; }

/* ── Messages ── */
.msg { display: flex; gap: 10px; align-items: flex-start; animation: fadeUp .2s ease; }
@keyframes fadeUp { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:none} }
.msg.user { flex-direction: row-reverse; }
.msg-avatar {
    width: 30px; height: 30px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; flex-shrink: 0;
}
.msg.assistant .msg-avatar { background: linear-gradient(135deg, #1e3a8a, #1e40af); color: var(--accent); }
.msg.user      .msg-avatar { background: linear-gradient(135deg, #064e3b, #065f46); color: var(--green); }
.msg-bubble {
    max-width: 78%; padding: 12px 16px; border-radius: 10px;
    font-size: 13px; line-height: 1.7; word-break: break-word;
}
.msg.assistant .msg-bubble {
    background: var(--panel); border: 1px solid var(--border2);
    color: var(--tx); border-top-left-radius: 2px;
}
.msg.user .msg-bubble {
    background: rgba(0,212,255,.08); border: 1px solid rgba(0,212,255,.2);
    color: var(--tx); border-top-right-radius: 2px; text-align: right;
}
.msg.intro-msg .msg-bubble { max-width: 92%; }

/* Markdown inside bubbles */
.msg-bubble table { border-collapse: collapse; width: 100%; margin: 10px 0; font-size: 12px; font-family: var(--mn); }
.msg-bubble th { background: var(--border); padding: 6px 10px; text-align: left; border: 1px solid var(--border2); color: var(--accent); font-size: 10px; text-transform: uppercase; letter-spacing: .05em; }
.msg-bubble td { padding: 5px 10px; border: 1px solid var(--border); color: var(--txm); }
.msg-bubble tr:nth-child(even) td { background: rgba(255,255,255,.02); }
.msg-bubble strong { color: var(--amber); font-weight: 700; }
.msg-bubble em { color: var(--accent2); font-style: normal; }
.msg-bubble code { background: rgba(0,212,255,.08); border: 1px solid var(--border2); border-radius: 4px; padding: 1px 6px; font-family: var(--mn); font-size: 11px; color: var(--accent); }
.msg-bubble h1,.msg-bubble h2,.msg-bubble h3 { color: var(--tx); margin: 8px 0 4px; font-size: 14px; }
.msg-bubble ul,.msg-bubble ol { padding-left: 18px; margin: 6px 0; }
.msg-bubble li { margin: 3px 0; }
.msg-bubble p { margin: 4px 0; }
.msg-bubble hr { border: none; border-top: 1px solid var(--border); margin: 10px 0; }
.msg-bubble a { color: var(--accent); }

/* Mode badge */
.mode-badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 9px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
    padding: 2px 8px; border-radius: 8px; margin-bottom: 8px;
    font-family: var(--mn);
}
.mode-badge.data { background: rgba(0,212,255,.1); color: var(--accent); border: 1px solid rgba(0,212,255,.25); }
.mode-badge.code { background: rgba(0,230,118,.1); color: var(--green); border: 1px solid rgba(0,230,118,.25); }
.mode-badge.mixed{ background: rgba(124,92,252,.1); color: var(--accent2); border: 1px solid rgba(124,92,252,.25); }

/* Typing */
.typing-indicator { display:flex; gap:5px; padding: 12px 16px; align-items: center; }
.typing-indicator span { width:6px;height:6px;border-radius:50%;background:var(--accent);animation:bounce 1.2s infinite; }
.typing-indicator span:nth-child(2){animation-delay:.2s}
.typing-indicator span:nth-child(3){animation-delay:.4s}
@keyframes bounce{0%,80%,100%{transform:translateY(0)}40%{transform:translateY(-5px)}}

/* SQL peek */
.sql-peek {
    background: var(--surface); border-top: 1px solid var(--border);
    padding: 7px 16px; display: flex; align-items: center; gap: 8px; overflow-x: auto;
}
.sql-label { font-size: 9px; font-weight: 700; color: var(--green); text-transform: uppercase; letter-spacing: .1em; white-space: nowrap; font-family: var(--mn); }
.sql-peek code { font-size: 11px; color: var(--accent); white-space: nowrap; font-family: var(--mn); }

/* Chart container inside bubble */
.chart-wrap {
    background: var(--surface); border: 1px solid var(--border2);
    border-radius: 8px; padding: 14px; margin-top: 12px;
    position: relative; min-height: 260px;
}
.chart-wrap canvas { max-width: 100%; }
.chart-actions { display: flex; gap: 6px; margin-top: 8px; justify-content: flex-end; }
.chart-type-btn {
    font-size: 10px; padding: 4px 10px; border-radius: 5px;
    background: var(--panel); color: var(--txm); border: 1px solid var(--border2);
    cursor: pointer; font-family: var(--mn); transition: all .15s;
}
.chart-type-btn.active, .chart-type-btn:hover { background: rgba(0,212,255,.1); color: var(--accent); border-color: rgba(0,212,255,.3); }

/* Input row */
.chat-input-row {
    display: flex; gap: 8px; padding: 12px 16px;
    border-top: 1px solid var(--border); background: var(--surface); align-items: flex-end;
}
.chat-input {
    flex: 1; background: var(--bg); border: 1px solid var(--border2);
    border-radius: 8px; padding: 10px 14px; font-size: 13px;
    font-family: var(--mn); color: var(--tx); resize: none;
    line-height: 1.5; max-height: 120px; overflow-y: auto; transition: border .2s;
}
.chat-input::placeholder { color: var(--txs); }
.chat-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 2px rgba(0,212,255,.1); }
.send-btn {
    width: 38px; height: 38px; background: linear-gradient(135deg,#1e40af,#1e3a8a);
    border: none; border-radius: 8px; color: var(--accent); font-size: 14px;
    cursor: pointer; transition: all .15s; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
}
.send-btn:hover { background: linear-gradient(135deg,#2563eb,#1d4ed8); }
.send-btn:disabled { opacity: .4; cursor: not-allowed; }
.clear-btn {
    width: 38px; height: 38px; background: var(--panel); border: 1px solid var(--border2);
    border-radius: 8px; color: var(--txm); font-size: 13px; cursor: pointer;
    transition: all .15s; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
}
.clear-btn:hover { color: var(--red); border-color: rgba(255,82,82,.4); }
.disclaimer { text-align: center; font-size: 10px; color: var(--txs); padding: 5px; font-family: var(--mn); }

/* Chart toggle tabs */
.chart-tabs { display: flex; gap: 4px; margin-bottom: 10px; }

/* PDF/Excel hidden print area */
#printArea { display: none; }

@media(max-width:680px){
    .chat-shell { grid-template-columns: 1fr; }
    .chat-sidebar { display: none; }
    .export-bar { display: none; }
}
</style>

<!-- Page header -->
<div class="ai-header">
    <div>
        <div class="ai-title">
            <span class="ai-pulse"></span>
            <i class="fa-solid fa-robot"></i> AI Business Analyst
        </div>
        <div class="ai-sub">gemini-2.5-flash · live DB · code analysis · charts · export</div>
    </div>
    <div class="export-bar">
        <button class="exp-btn exp-chart" id="btnChartLast" onclick="toggleLastChart()" disabled>
            <i class="fa-solid fa-chart-bar"></i> Chart
        </button>
        <button class="exp-btn exp-excel" id="btnExcel" onclick="exportExcel()" disabled>
            <i class="fa-solid fa-file-excel"></i> Excel
        </button>
        <button class="exp-btn exp-pdf" id="btnPdf" onclick="exportPDF()" disabled>
            <i class="fa-solid fa-file-pdf"></i> PDF
        </button>
    </div>
</div>

<div class="chat-shell">
    <!-- Sidebar -->
    <aside class="chat-sidebar">
        <p class="sidebar-heading">📊 Sales Data</p>
        <ul class="quick-list">
            <li onclick="quickAsk(this)">Total imports today with status</li>
            <li onclick="quickAsk(this)">Top 5 routes by gross sales</li>
            <li onclick="quickAsk(this)">Top 10 customers by final bill</li>
            <li onclick="quickAsk(this)">Failed or invalid import records</li>
            <li onclick="quickAsk(this)">Gross sales and discounts summary</li>
            <li onclick="quickAsk(this)">Delivery person performance</li>
            <li onclick="quickAsk(this)">Sales trend last 7 days by date</li>
            <li onclick="quickAsk(this)">Average bill amount per route</li>
        </ul>
        <p class="sidebar-heading">🖥️ System</p>
        <ul class="quick-list">
            <li onclick="quickAsk(this)">List all PHP pages in this project</li>
            <li onclick="quickAsk(this)">How does the invoice import work</li>
            <li onclick="quickAsk(this)">How does AI chatbot system work</li>
            <li onclick="quickAsk(this)">How is Gemini API key managed</li>
        </ul>
        <div class="sdivider"></div>
        <p class="sidebar-heading">DB Status</p>
        <div id="dbStatus" class="db-status"><span class="db-dot"></span> Connecting…</div>
    </aside>

    <!-- Main chat -->
    <main class="chat-main">
        <div class="chat-messages" id="chatMessages">
            <div class="msg assistant intro-msg">
                <div class="msg-avatar"><i class="fa-solid fa-robot"></i></div>
                <div class="msg-bubble">
                    <p>👋 Hello! I'm your AI Business Analyst with live database access.</p>
                    <p style="margin-top:8px">I can now:</p>
                    <p>📊 <strong>Query your live data</strong> and show results<br>
                    📈 <strong>Generate charts</strong> (bar, line, pie, doughnut) from any data<br>
                    📥 <strong>Export to Excel</strong> (.xlsx) with formatted tables<br>
                    🖨️ <strong>Export to PDF</strong> — full report with charts<br>
                    🖥️ <strong>Analyze PHP source code</strong> and explain system features</p>
                    <p style="margin-top:8px;opacity:.6;font-size:11px">Click a quick report on the left or type your question below.</p>
                </div>
            </div>
        </div>

        <div class="sql-peek" id="sqlPeek" style="display:none">
            <span class="sql-label">SQL</span>
            <code id="sqlCode"></code>
        </div>

        <div class="chat-input-row">
            <textarea id="userInput" class="chat-input"
                placeholder="Ask about your sales data or system code…"
                rows="1" onkeydown="handleKey(event)" oninput="autoResize(this)"></textarea>
            <button class="send-btn" id="sendBtn" onclick="sendMessage()">
                <i class="fa-solid fa-paper-plane"></i>
            </button>
            <button class="clear-btn" onclick="clearChat()" title="Clear">
                <i class="fa-solid fa-rotate-left"></i>
            </button>
        </div>
        <p class="disclaimer">AI-generated from live database. Verify critical figures. · Charts auto-generated from response data.</p>
    </main>
</div>

<!-- Hidden print/export area -->
<div id="printArea"></div>

<!-- Libraries -->
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>

<script>
// ── State ────────────────────────────────────────────────────────────────────
let chatHistory   = [];
let isLoading     = false;
let lastTableData = null;   // { headers:[], rows:[[]] }
let lastAnswer    = '';
let lastQuestion  = '';
let lastSql       = '';
let chartInstances = {};    // keyed by chart container id
let chartCounter  = 0;

// ── DB ping ──────────────────────────────────────────────────────────────────
(function checkDB(){
    fetch('ai_chatbot_handler.php',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({message:'ping test',history:[]})})
    .then(r=>r.json())
    .then(()=>{
        const el=document.getElementById('dbStatus');
        el.innerHTML='<span class="db-dot online"></span> Connected';
    }).catch(()=>{
        document.getElementById('dbStatus').innerHTML='<span class="db-dot offline"></span> Offline';
    });
})();

// ── Helpers ──────────────────────────────────────────────────────────────────
function quickAsk(el){
    document.getElementById('userInput').value = el.textContent.trim();
    sendMessage();
}
function autoResize(el){
    el.style.height='auto';
    el.style.height=Math.min(el.scrollHeight,120)+'px';
}
function handleKey(e){
    if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMessage();}
}
function renderMarkdown(text){
    try{return marked.parse(text);}catch(e){return text.replace(/\n/g,'<br>');}
}

// ── Parse tables from markdown ────────────────────────────────────────────────
function parseMarkdownTables(markdown){
    const tables=[];
    const tableRegex=/(\|.+\|\n\|[-| :]+\|\n(?:\|.+\|\n?)+)/g;
    let match;
    while((match=tableRegex.exec(markdown))!==null){
        const lines=match[1].trim().split('\n').filter(l=>l.trim());
        if(lines.length<3) continue;
        const headers=lines[0].split('|').map(h=>h.trim()).filter(Boolean);
        const rows=lines.slice(2).map(l=>l.split('|').map(c=>c.trim()).filter(Boolean));
        tables.push({headers,rows});
    }
    return tables;
}

// ── Detect numeric columns ────────────────────────────────────────────────────
function detectNumericCols(table){
    const numeric=[];
    table.headers.forEach((h,i)=>{
        let isNum=table.rows.some(r=>{
            const v=(r[i]||'').replace(/[,LKR%\s]/g,'');
            return v!==''&&!isNaN(parseFloat(v));
        });
        if(isNum) numeric.push(i);
    });
    return numeric;
}

// ── Color palette ─────────────────────────────────────────────────────────────
const CHART_COLORS=[
    'rgba(0,212,255,.85)','rgba(0,230,118,.85)','rgba(255,171,64,.85)',
    'rgba(124,92,252,.85)','rgba(255,82,82,.85)','rgba(64,196,255,.85)',
    'rgba(105,240,174,.85)','rgba(255,213,79,.85)','rgba(179,136,255,.85)',
    'rgba(255,138,128,.85)'
];
const CHART_BORDERS=CHART_COLORS.map(c=>c.replace('.85','.95'));

// ── Create chart from table ───────────────────────────────────────────────────
function buildChart(containerId, table, chartType='bar'){
    const existing=chartInstances[containerId];
    if(existing){existing.destroy();delete chartInstances[containerId];}

    const numCols=detectNumericCols(table);
    if(!numCols.length) return;

    // Use first non-numeric col as labels, or row index
    const labelColIdx=table.headers.findIndex((_,i)=>!numCols.includes(i));
    const labels=table.rows.map(r=>(labelColIdx>=0?(r[labelColIdx]||''):'')||'');
    const maxLabel=16;
    const shortLabels=labels.map(l=>l.length>maxLabel?l.substring(0,maxLabel)+'…':l);

    const datasets=numCols.map((ci,di)=>({
        label: table.headers[ci]||'Value',
        data: table.rows.map(r=>{
            const v=(r[ci]||'').replace(/[,\s]/g,'').replace(/[^0-9.\-]/g,'');
            return parseFloat(v)||0;
        }),
        backgroundColor: chartType==='line'
            ? CHART_COLORS[di%CHART_COLORS.length].replace('.85','.15')
            : CHART_COLORS.slice(0,table.rows.length),
        borderColor: chartType==='line'
            ? CHART_BORDERS[di%CHART_BORDERS.length]
            : CHART_BORDERS.slice(0,table.rows.length),
        borderWidth: chartType==='line'?2:1,
        fill: chartType==='line',
        tension: .4,
        pointRadius: chartType==='line'?4:0,
        pointHoverRadius: 6,
    }));

    const canvas=document.querySelector(`#${containerId} canvas`);
    if(!canvas) return;

    const ctx=canvas.getContext('2d');
    chartInstances[containerId]=new Chart(ctx,{
        type: chartType,
        data:{ labels: (chartType==='pie'||chartType==='doughnut')?shortLabels:shortLabels, datasets },
        options:{
            responsive:true,
            plugins:{
                legend:{
                    labels:{ color:'#7a94ad', font:{family:'JetBrains Mono',size:11}, boxWidth:12 }
                },
                tooltip:{
                    backgroundColor:'#111820', borderColor:'#1e2d3d', borderWidth:1,
                    titleColor:'#e2eaf3', bodyColor:'#7a94ad',
                    titleFont:{family:'JetBrains Mono',size:11},
                    bodyFont:{family:'JetBrains Mono',size:11},
                    callbacks:{
                        label:function(ctx){
                            let val=ctx.raw;
                            if(val>=1000) val=val.toLocaleString();
                            return ` ${ctx.dataset.label}: ${val}`;
                        }
                    }
                }
            },
            scales: (chartType==='pie'||chartType==='doughnut')?{}:{
                x:{
                    ticks:{ color:'#3d5166', font:{family:'JetBrains Mono',size:10}, maxRotation:35 },
                    grid:{ color:'rgba(255,255,255,.04)' }
                },
                y:{
                    ticks:{
                        color:'#3d5166', font:{family:'JetBrains Mono',size:10},
                        callback:v=>v>=1000?v.toLocaleString():v
                    },
                    grid:{ color:'rgba(255,255,255,.06)' }
                }
            },
            animation:{ duration:600, easing:'easeInOutQuart' }
        }
    });
}

// ── Inject chart widget after bubble ─────────────────────────────────────────
function injectCharts(bubble, tables){
    tables.forEach((table, ti)=>{
        const numCols=detectNumericCols(table);
        if(!numCols.length) return;

        chartCounter++;
        const cid=`chart-${chartCounter}`;

        const wrap=document.createElement('div');
        wrap.className='chart-wrap';
        wrap.id=cid;
        wrap.innerHTML=`
            <div class="chart-tabs">
                <button class="chart-type-btn active" onclick="switchChart('${cid}',this,'bar')">Bar</button>
                <button class="chart-type-btn" onclick="switchChart('${cid}',this,'line')">Line</button>
                <button class="chart-type-btn" onclick="switchChart('${cid}',this,'pie')">Pie</button>
                <button class="chart-type-btn" onclick="switchChart('${cid}',this,'doughnut')">Donut</button>
                <span style="margin-left:auto;font-size:9px;color:var(--txs);font-family:var(--mn)">${table.headers.join(' · ')}</span>
            </div>
            <canvas height="240"></canvas>
        `;
        bubble.appendChild(wrap);

        setTimeout(()=>buildChart(cid,table,'bar'),50);
        wrap._table=table;
    });
}

function switchChart(cid, btn, type){
    btn.closest('.chart-tabs').querySelectorAll('.chart-type-btn')
       .forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    const wrap=document.getElementById(cid);
    buildChart(cid, wrap._table, type);
}

// ── Toggle last chart ─────────────────────────────────────────────────────────
function toggleLastChart(){
    const last=document.querySelector('.chat-messages .msg.assistant:last-child .chart-wrap');
    if(!last) return;
    last.style.display=last.style.display==='none'?'':'none';
}

// ── EXCEL EXPORT ──────────────────────────────────────────────────────────────
function exportExcel(){
    if(!lastTableData) return;
    const wb=XLSX.utils.book_new();
    const wsData=[lastTableData.headers,...lastTableData.rows];
    const ws=XLSX.utils.aoa_to_sheet(wsData);

    // Column widths
    const colWidths=lastTableData.headers.map((h,i)=>{
        const maxLen=Math.max(h.length,...lastTableData.rows.map(r=>(r[i]||'').length));
        return{wch:Math.min(maxLen+4,40)};
    });
    ws['!cols']=colWidths;

    // Style header row (bold)
    lastTableData.headers.forEach((_,i)=>{
        const cell=XLSX.utils.encode_cell({r:0,c:i});
        if(ws[cell]) ws[cell].s={font:{bold:true},fill:{fgColor:{rgb:'1E40AF'}},font:{color:{rgb:'FFFFFF'},bold:true}};
    });

    XLSX.utils.book_append_sheet(wb,ws,'Report');

    // Add metadata sheet
    const metaData=[
        ['Report Generated','=NOW()'],
        ['Question', lastQuestion],
        ['SQL Query', lastSql||'N/A'],
        ['Total Rows', lastTableData.rows.length],
    ];
    const wsMeta=XLSX.utils.aoa_to_sheet(metaData);
    wsMeta['!cols']=[{wch:20},{wch:60}];
    XLSX.utils.book_append_sheet(wb,wsMeta,'Info');

    const fname=`AI_Report_${new Date().toISOString().slice(0,10)}.xlsx`;
    XLSX.writeFile(wb,fname);
}

// ── PDF EXPORT ────────────────────────────────────────────────────────────────
function exportPDF(){
    if(!lastAnswer) return;
    const {jsPDF}=window.jspdf;
    const doc=new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});

    const margin=15;
    const pageW=doc.internal.pageSize.getWidth();
    const pageH=doc.internal.pageSize.getHeight();
    let y=margin;

    // Header bar
    doc.setFillColor(30,64,175);
    doc.rect(0,0,pageW,22,'F');
    doc.setTextColor(255,255,255);
    doc.setFont('helvetica','bold');
    doc.setFontSize(14);
    doc.text('AI Business Analyst — Report', margin, 14);
    doc.setFontSize(8);
    doc.setFont('helvetica','normal');
    doc.text(new Date().toLocaleString(), pageW-margin, 14, {align:'right'});

    y=30;
    // Question
    doc.setTextColor(100,100,100);
    doc.setFontSize(8);
    doc.setFont('helvetica','bold');
    doc.text('QUESTION', margin, y);
    y+=5;
    doc.setTextColor(30,30,30);
    doc.setFont('helvetica','normal');
    doc.setFontSize(10);
    const qLines=doc.splitTextToSize(lastQuestion||'', pageW-2*margin);
    doc.text(qLines, margin, y);
    y+=qLines.length*5+4;

    // SQL
    if(lastSql){
        doc.setTextColor(100,100,100);
        doc.setFontSize(8);
        doc.setFont('helvetica','bold');
        doc.text('SQL QUERY', margin, y);
        y+=5;
        doc.setFillColor(245,247,250);
        const sqlLines=doc.splitTextToSize(lastSql, pageW-2*margin-4);
        doc.rect(margin, y-4, pageW-2*margin, sqlLines.length*5+4, 'F');
        doc.setTextColor(30,100,200);
        doc.setFont('courier','normal');
        doc.setFontSize(8);
        doc.text(sqlLines, margin+2, y);
        y+=sqlLines.length*5+8;
        doc.setFont('helvetica','normal');
    }

    // Tables
    if(lastTableData){
        doc.setTextColor(100,100,100);
        doc.setFontSize(8);
        doc.setFont('helvetica','bold');
        doc.text('DATA TABLE', margin, y);
        y+=4;
        doc.autoTable({
            head:[lastTableData.headers],
            body:lastTableData.rows,
            startY:y,
            margin:{left:margin,right:margin},
            styles:{fontSize:8,cellPadding:3,font:'helvetica',textColor:[30,30,30]},
            headStyles:{fillColor:[30,64,175],textColor:255,fontStyle:'bold',fontSize:8},
            alternateRowStyles:{fillColor:[245,247,252]},
            tableLineColor:[200,200,210],tableLineWidth:.2,
        });
        y=doc.lastAutoTable.finalY+10;
    }

    // Chart image
    const chartWrap=document.querySelector('.chart-wrap canvas');
    if(chartWrap&&y<pageH-50){
        try{
            const imgData=chartWrap.toDataURL('image/png',0.92);
            const imgW=pageW-2*margin;
            const imgH=imgW*0.55;
            if(y+imgH>pageH-margin){doc.addPage();y=margin;}
            doc.setTextColor(100,100,100);
            doc.setFontSize(8);
            doc.setFont('helvetica','bold');
            doc.text('CHART', margin, y);
            y+=5;
            doc.addImage(imgData,'PNG',margin,y,imgW,imgH);
            y+=imgH+8;
        }catch(e){}
    }

    // Answer text
    if(y<pageH-30){
        doc.setTextColor(100,100,100);
        doc.setFontSize(8);
        doc.setFont('helvetica','bold');
        doc.text('AI ANALYSIS', margin, y);
        y+=5;
        // Strip markdown
        const plain=lastAnswer
            .replace(/[*_~`#>\[\]!]/g,'')
            .replace(/\|.+\|\n/g,'')
            .replace(/\n{2,}/g,'\n')
            .trim();
        const aLines=doc.splitTextToSize(plain, pageW-2*margin);
        doc.setTextColor(40,40,40);
        doc.setFont('helvetica','normal');
        doc.setFontSize(9);
        aLines.forEach(line=>{
            if(y>pageH-margin){doc.addPage();y=margin;}
            doc.text(line,margin,y);
            y+=4.5;
        });
    }

    // Footer on all pages
    const totalPages=doc.internal.getNumberOfPages();
    for(let i=1;i<=totalPages;i++){
        doc.setPage(i);
        doc.setFillColor(30,64,175);
        doc.rect(0,pageH-8,pageW,8,'F');
        doc.setTextColor(255,255,255);
        doc.setFontSize(7);
        doc.setFont('helvetica','normal');
        doc.text('Generated by AI Business Analyst · Confidential',margin,pageH-3);
        doc.text(`Page ${i}/${totalPages}`,pageW-margin,pageH-3,{align:'right'});
    }

    doc.save(`AI_Report_${new Date().toISOString().slice(0,10)}.pdf`);
}

// ── Append message ─────────────────────────────────────────────────────────────
function appendMessage(role, html, isMarkdown=false){
    const wrap=document.getElementById('chatMessages');
    const div=document.createElement('div');
    div.className=`msg ${role}`;
    const icon=role==='user'
        ?'<i class="fa-solid fa-user"></i>'
        :'<i class="fa-solid fa-robot"></i>';
    const content=isMarkdown?renderMarkdown(html):html;
    div.innerHTML=`<div class="msg-avatar">${icon}</div><div class="msg-bubble">${content}</div>`;
    wrap.appendChild(div);
    wrap.scrollTop=wrap.scrollHeight;
    return div;
}

function showTyping(){
    const wrap=document.getElementById('chatMessages');
    const div=document.createElement('div');
    div.className='msg assistant';div.id='typingIndicator';
    div.innerHTML=`<div class="msg-avatar"><i class="fa-solid fa-robot"></i></div>
        <div class="msg-bubble typing-indicator"><span></span><span></span><span></span></div>`;
    wrap.appendChild(div);wrap.scrollTop=wrap.scrollHeight;
}
function hideTyping(){ const el=document.getElementById('typingIndicator');if(el)el.remove(); }

// ── Send message ───────────────────────────────────────────────────────────────
function sendMessage(){
    if(isLoading)return;
    const input=document.getElementById('userInput');
    const text=input.value.trim();
    if(!text)return;
    lastQuestion=text;
    input.value='';input.style.height='auto';

    appendMessage('user',text);
    chatHistory.push({role:'user',text});
    isLoading=true;
    document.getElementById('sendBtn').disabled=true;
    document.getElementById('sqlPeek').style.display='none';
    showTyping();

    fetch('ai_chatbot_handler.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({message:text,history:chatHistory})
    })
    .then(r=>r.json())
    .then(data=>{
        hideTyping();
        isLoading=false;
        document.getElementById('sendBtn').disabled=false;

        if(!data.success){
            appendMessage('assistant',`⚠️ Error: ${data.message||'Unknown error'}`);
            return;
        }

        lastAnswer=data.answer||'';
        lastSql=data.sql||'';

        // SQL peek
        if(data.sql){
            document.getElementById('sqlCode').textContent=data.sql;
            document.getElementById('sqlPeek').style.display='flex';
        }

        // Mode badge
        let badge='';
        if(data.sql&&data.code_context)      badge='<span class="mode-badge mixed">⚡ Data + Code</span><br>';
        else if(data.code_context)            badge='<span class="mode-badge code">🖥 System Analysis</span><br>';
        else if(data.sql)                     badge='<span class="mode-badge data">📊 Live Data</span><br>';

        // Build bubble
        const wrap=document.getElementById('chatMessages');
        const div=document.createElement('div');
        div.className='msg assistant';
        const bubble=document.createElement('div');
        bubble.className='msg-bubble';
        bubble.innerHTML=`<div class="msg-avatar" style="display:none"></div>${badge}${renderMarkdown(data.answer)}`;

        const msgDiv=document.createElement('div');
        msgDiv.className='msg assistant';
        const avatar=document.createElement('div');
        avatar.className='msg-avatar';
        avatar.innerHTML='<i class="fa-solid fa-robot"></i>';
        msgDiv.appendChild(avatar);
        msgDiv.appendChild(bubble);
        wrap.appendChild(msgDiv);
        wrap.scrollTop=wrap.scrollHeight;

        // Parse & inject charts
        const tables=parseMarkdownTables(data.answer);
        if(tables.length){
            lastTableData={headers:tables[0].headers, rows:tables[0].rows};
            injectCharts(bubble, tables);
            document.getElementById('btnChartLast').disabled=false;
            document.getElementById('btnExcel').disabled=false;
            document.getElementById('btnPdf').disabled=false;
        } else {
            lastTableData=null;
            document.getElementById('btnChartLast').disabled=true;
            document.getElementById('btnExcel').disabled=true;
            document.getElementById('btnPdf').disabled=!lastAnswer;
        }

        chatHistory.push({role:'model',text:data.answer});
    })
    .catch(err=>{
        hideTyping();isLoading=false;
        document.getElementById('sendBtn').disabled=false;
        appendMessage('assistant',`⚠️ Network error: ${err.message}`);
    });
}

function clearChat(){
    chatHistory=[];lastTableData=null;lastAnswer='';lastSql='';lastQuestion='';
    const wrap=document.getElementById('chatMessages');
    while(wrap.children.length>1)wrap.removeChild(wrap.lastChild);
    document.getElementById('sqlPeek').style.display='none';
    document.getElementById('btnPdf').disabled=true;
    document.getElementById('btnExcel').disabled=true;
    document.getElementById('btnChartLast').disabled=true;
    Object.values(chartInstances).forEach(c=>{try{c.destroy();}catch(e){}});
    chartInstances={};
}
</script>

<?php include 'footer.php'; ?>