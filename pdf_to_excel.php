<?php
// ============================================================
//  pdf_to_excel.php
//  PDF TO EXCEL — convert any text PDF into an Excel workbook (like an online PDF-to-Excel converter)
//
//  The PDF is read in the browser (pdf.js): nothing is uploaded and nothing is changed - the text is simply placed into cells.
//  Every printed line is split into cells at wide gaps, so table rows land in their own columns; numbers become real numbers.
//  Options: one sheet per page or all pages in one sheet, column sensitivity, numbers as numbers.
//  Scanned PDFs (no selectable text) can be read by Gemini instead (key from AI Settings; the PDF is sent to Gemini for that).
// ============================================================
include 'config.php';
include_once 'auth.php';
requireLogin();
require_once 'item_match_lib.php';     // im_gemini_key(), GEMINI_MODEL

if (($_GET['ajax'] ?? '') === 'ocr' && $_SERVER['REQUEST_METHOD'] === 'POST') {     // Gemini fallback for scanned PDFs
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(220);
    $key = im_gemini_key();
    if ($key === '') { echo json_encode(['ok' => false, 'error' => 'No Gemini API key is saved in AI Settings.']); exit; }
    $f = $_FILES['pdf'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok' => false, 'error' => 'Upload failed (code ' . (int)($f['error'] ?? -1) . ').']); exit; }
    $bytes = file_get_contents($f['tmp_name']);
    if (substr($bytes, 0, 4) !== '%PDF' || $f['size'] > 12 * 1024 * 1024) { echo json_encode(['ok' => false, 'error' => 'Upload a PDF under 12 MB.']); exit; }
    $prompt = "Convert this PDF to spreadsheet rows. Reply with JSON only: {\"pages\": [{\"rows\": [[cell, cell, ...], ...]}, ...]} - one entry per PDF page, every printed line as a row, "
        . "table rows split into one cell per column (keep column order), numbers as plain numbers (no thousands commas), text exactly as printed. Do not skip, summarise or invent rows.";
    $body = json_encode(['contents' => [['parts' => [['inline_data' => ['mime_type' => 'application/pdf', 'data' => base64_encode($bytes)]], ['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 60000, 'responseMimeType' => 'application/json', 'thinkingConfig' => ['thinkingBudget' => 0]]]);
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(GEMINI_MODEL) . ':generateContent');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 200,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key]]);
    $res = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($res === false) { echo json_encode(['ok' => false, 'error' => 'Gemini request failed: ' . curl_error($ch)]); exit; }
    curl_close($ch);
    $j = json_decode($res, true);
    if ($http >= 400) { echo json_encode(['ok' => false, 'error' => 'Gemini error ' . $http . ': ' . ($j['error']['message'] ?? 'unknown')]); exit; }
    $txt = ''; foreach (($j['candidates'][0]['content']['parts'] ?? []) as $p) $txt .= $p['text'] ?? '';
    $d = json_decode(trim($txt), true);
    if (!is_array($d) || empty($d['pages'])) { echo json_encode(['ok' => false, 'error' => 'Gemini returned an unreadable or cut-off answer. Try again.']); exit; }
    echo json_encode(['ok' => true, 'pages' => $d['pages']], JSON_UNESCAPED_UNICODE);
    exit;
}
include 'header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
<style>
.p2x{padding:0 4px;}
.p2x h2{font-size:22px;font-weight:800;margin:0 0 4px;}
.p2x .sub{font-size:12px;color:#8892a4;margin-bottom:16px;}
.p2x-card{background:#fff;border:1.5px solid #e2e6f0;border-radius:14px;padding:18px 20px;margin-bottom:18px;}
.p2x-drop{border:2px dashed #c7cde0;border-radius:14px;background:#fafbff;padding:34px 18px;text-align:center;cursor:pointer;transition:.15s;}
.p2x-drop.over{background:#eef2ff;border-color:#6366f1;}
.p2x-drop i{font-size:34px;color:#475569;} .p2x-drop b{display:block;margin-top:8px;font-size:15px;} .p2x-drop span{font-size:12px;color:#8892a4;}
.p2x-opts{display:flex;gap:18px;flex-wrap:wrap;align-items:center;margin-top:14px;font-size:13px;}
.p2x-opts label{display:flex;align-items:center;gap:6px;font-weight:600;cursor:pointer;}
.p2x-b{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;background:#0a0c14;color:#fff;}
.p2x-b.g{background:#14532d;} .p2x-b.l{background:#f7f8fc;color:#475569;border:1.5px solid #e2e6f0;} .p2x-b[disabled]{opacity:.5;cursor:not-allowed;}
.p2x-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;}
.p2x-tab{border:1.5px solid #e2e6f0;background:#fff;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:700;cursor:pointer;} .p2x-tab.on{background:#0a0c14;color:#fff;border-color:#0a0c14;}
.p2x-wrap{overflow:auto;border:1.5px solid #e2e6f0;border-radius:12px;max-height:60vh;background:#fff;-webkit-overflow-scrolling:touch;}
table.p2x-t{border-collapse:collapse;font-size:12px;} table.p2x-t td{border:1px solid #eef0f6;padding:4px 8px;white-space:nowrap;} table.p2x-t td.num{text-align:right;font-variant-numeric:tabular-nums;}
table.p2x-t td.rn{background:#f3f4f6;color:#9ca3af;text-align:center;font-size:10px;position:sticky;left:0;}
.p2x-msg{border-radius:8px;padding:10px 16px;margin-bottom:12px;font-size:13px;} .p2x-msg.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;} .p2x-msg.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;} .p2x-msg.warn{background:#fffbeb;border:1px solid #fcd34d;color:#92400e;}
@media (max-width:640px){ .p2x-opts{flex-direction:column;align-items:flex-start;} .p2x-b{width:100%;justify-content:center;} }
</style>

<div class="p2x">
    <h2><i class="fa-solid fa-file-excel"></i> PDF to Excel</h2>
    <div class="sub">Convert a PDF into an Excel workbook — the text of every page is placed into cells, tables land in their own columns and numbers become real numbers. The PDF is read in your browser; it is <strong>not uploaded and not changed</strong>.</div>
    <div id="pMsgs"></div>

    <div class="p2x-card">
        <div class="p2x-drop" id="pDrop" onclick="document.getElementById('pFile').click()">
            <i class="fa-solid fa-file-pdf"></i><b id="pName">Drop a PDF here or click to choose</b><span>Text PDFs convert instantly. Scanned PDFs can use the Gemini option.</span>
            <input type="file" id="pFile" accept="application/pdf,.pdf" style="display:none">
        </div>
        <div class="p2x-opts">
            <label>Sheets:
                <select id="pSheets" style="border:1.5px solid #e2e6f0;border-radius:8px;padding:6px 10px;"><option value="page">One sheet per page</option><option value="all">All pages in one sheet</option></select></label>
            <label>Column gap <input type="range" id="pGap" min="2" max="14" value="5" style="width:120px"> <span id="pGapV">5</span> pt</label>
            <label><input type="checkbox" id="pNum" checked> Numbers as numbers</label>
            <button class="p2x-b" id="pGo" type="button" onclick="pConvert()" disabled><i class="fa-solid fa-arrows-rotate"></i> Convert</button>
        </div>
    </div>

    <div class="p2x-card" id="pOut" style="display:none">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px">
            <div><div style="font-size:15px;font-weight:800">Preview</div><div id="pInfo" style="font-size:12px;color:#8892a4"></div></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button class="p2x-b g" type="button" onclick="pDownload('xlsx')"><i class="fa-solid fa-file-excel"></i> Download Excel (.xlsx)</button>
                <button class="p2x-b l" type="button" onclick="pDownload('csv')"><i class="fa-solid fa-file-csv"></i> CSV</button>
            </div>
        </div>
        <div class="p2x-tabs" id="pTabs"></div>
        <div class="p2x-wrap"><table class="p2x-t" id="pTable"></table></div>
    </div>
</div>

<script>
/* Generic PDF -> table rows (like a PDF-to-Excel converter). Read-only: only extracts what is printed.
   opts.gap: gap in pt that starts a new cell (smaller = more columns). Returns [{rows:[[cell,...]]}] per page. */
async function pdfToTables(pdfjsLib, buf, opts) {
    opts = Object.assign({gap: 5}, opts || {});
    const pdf = await pdfjsLib.getDocument({data: buf}).promise, pages = [];
    for (let p = 1; p <= pdf.numPages; p++) {
        const page = await pdf.getPage(p), vp = page.getViewport({scale: 1});
        const content = await page.getTextContent();
        const items = content.items.filter(i => i.str && i.str.trim()).map(i => ({x: i.transform[4], xe: i.transform[4] + i.width, y: i.transform[5], s: i.str.trim()}));
        // rows by y (tolerance 3pt)
        items.sort((a, b) => b.y - a.y || a.x - b.x);
        const rowsRaw = [];
        items.forEach(it => { const r = rowsRaw[rowsRaw.length - 1]; if (r && Math.abs(r.y - it.y) <= 3) r.items.push(it); else rowsRaw.push({y: it.y, items: [it]}); });
        rowsRaw.forEach(r => r.items.sort((a, b) => a.x - b.x));
        // split every line into cells at wide gaps
        const lineCells = rowsRaw.map(r => { const cells = []; let cur = null;
            r.items.forEach(it => { if (cur && it.x - cur.xe <= opts.gap) { cur.s += (it.x - cur.xe > 0.8 ? ' ' : '') + it.s; cur.xe = it.xe; } else { cur = {s: it.s, x: it.x, xe: it.xe}; cells.push(cur); } });
            return cells; });
        const rows = lineCells.map(c => c.map(x => x.s));   // each line split into cells at wide gaps (columns line up because table rows share the same gaps)
        pages.push({rows: rows.filter(r => r.some(c => c !== ''))});
    }
    return pages;
}
/* numbers like 12,345.60 / (1,200.00) / -5 become real numbers in Excel; everything else stays text */
function pdfCellValue(s) {
    const t = String(s).trim();
    if (/^-?\(?[\d,]*\d(\.\d+)?\)?$/.test(t) && !/^0\d+$/.test(t.replace(/,/g, '')) && !/^\d{8,}$/.test(t) && t.replace(/[(),-]/g, '').length < 16) {   // long plain digit strings (TIN, phone, ids) stay text
        const neg = /^\(.*\)$/.test(t) || t.startsWith('-'); const n = parseFloat(t.replace(/[(),-]/g, '').replace(/,/g, ''));
        if (!isNaN(n)) return neg ? -n : n;
    }
    return t;
}

const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
let pFile = null, pPages = [], pCur = 0, pName = 'converted';
function msg(h, c){ document.getElementById('pMsgs').innerHTML = h ? `<div class="p2x-msg ${c || 'ok'}">${h}</div>` : ''; }
function pPick(f){
    if (!f) return;
    if (!/\.pdf$/i.test(f.name) && f.type !== 'application/pdf') { msg('Please choose a PDF file.', 'err'); return; }
    pFile = f; pName = f.name.replace(/\.pdf$/i, ''); document.getElementById('pName').textContent = f.name + ' (' + Math.round(f.size / 1024) + ' KB)';
    document.getElementById('pGo').disabled = false; document.getElementById('pOut').style.display = 'none'; msg('');
}
document.getElementById('pFile').addEventListener('change', e => pPick(e.target.files[0]));
const drop = document.getElementById('pDrop');
['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
drop.addEventListener('drop', e => pPick(e.dataTransfer.files[0]));
document.getElementById('pGap').addEventListener('input', e => document.getElementById('pGapV').textContent = e.target.value);

async function pConvert(){
    if (!pFile) return;
    const btn = document.getElementById('pGo'), orig = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Converting…'; msg('');
    try {
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        pPages = await Promise.race([pdfToTables(pdfjsLib, new Uint8Array(await pFile.arrayBuffer()), {gap: +document.getElementById('pGap').value}),
                                     new Promise((_, rej) => setTimeout(() => rej(new Error('The conversion took too long.')), 60000))]);
        const cells = pPages.reduce((a, p) => a + p.rows.reduce((b, r) => b + r.length, 0), 0);
        if (cells < 3) {
            msg('This PDF has no selectable text (it looks scanned). <button class="p2x-b" style="padding:6px 12px;margin-left:8px" type="button" onclick="pOcr()"><i class="fa-solid fa-wand-magic-sparkles"></i> Read it with Gemini</button>', 'warn');
            pPages = [];
        } else pShow();
    } catch (e) { msg(esc(e.message), 'err'); }
    btn.disabled = false; btn.innerHTML = orig;
}
async function pOcr(){
    msg('Gemini is reading the PDF… (can take a minute)', 'warn');
    try {
        const fd = new FormData(); fd.append('pdf', pFile);
        const r = await fetch('pdf_to_excel.php?ajax=ocr', {method:'POST', body: fd}), t = await r.text(); let d;
        try { d = JSON.parse(t); } catch (e) { throw new Error('Server answered with HTTP ' + r.status + ': ' + t.replace(/<[^>]+>/g, ' ').slice(0, 200)); }
        if (!d.ok) throw new Error(d.error);
        pPages = d.pages.map(p => ({rows: (p.rows || []).map(r => r.map(c => String(c ?? '')))})); msg(''); pShow();
    } catch (e) { msg(esc(e.message), 'err'); }
}
function pShow(){
    pCur = 0;
    const rows = pPages.reduce((a, p) => a + p.rows.length, 0);
    document.getElementById('pInfo').textContent = pPages.length + ' page(s) · ' + rows + ' rows';
    document.getElementById('pTabs').innerHTML = pPages.map((p, i) => `<button type="button" class="p2x-tab ${i === 0 ? 'on' : ''}" onclick="pTab(${i})">Page ${i + 1} (${p.rows.length})</button>`).join('');
    document.getElementById('pOut').style.display = ''; pTab(0);
}
function pTab(i){
    pCur = i; document.querySelectorAll('.p2x-tab').forEach((t, k) => t.classList.toggle('on', k === i));
    const rows = pPages[i].rows, show = rows.slice(0, 150), asNum = document.getElementById('pNum').checked;
    document.getElementById('pTable').innerHTML = show.map((r, n) => `<tr><td class="rn">${n + 1}</td>${r.map(c => { const v = asNum ? pdfCellValue(c) : c; return `<td class="${typeof v === 'number' ? 'num' : ''}">${esc(typeof v === 'number' ? v.toLocaleString('en-US', {maximumFractionDigits: 6}) : c)}</td>`; }).join('')}</tr>`).join('')
        + (rows.length > show.length ? `<tr><td class="rn">…</td><td colspan="9" style="color:#8892a4">showing the first ${show.length} of ${rows.length} rows — the download has all of them</td></tr>` : '');
}
async function pDownload(kind){
    const asNum = document.getElementById('pNum').checked, one = document.getElementById('pSheets').value === 'all', val = c => asNum ? pdfCellValue(c) : c;
    if (kind === 'csv') {
        const q = v => { const s = String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
        const out = []; pPages.forEach((p, i) => { if (pPages.length > 1) out.push('"--- Page ' + (i + 1) + ' ---"'); p.rows.forEach(r => out.push(r.map(c => q(val(c))).join(','))); });
        save(new Blob(['﻿' + out.join('\r\n')], {type: 'text/csv;charset=utf-8'}), pName + '.csv'); return;
    }
    const wb = new ExcelJS.Workbook(); let ws = null, pageNo = 0;
    const widths = (sheet, rows) => { const w = []; rows.forEach(r => r.forEach((c, i) => { w[i] = Math.min(60, Math.max(w[i] || 6, String(c).length + 2)); })); w.forEach((x, i) => sheet.getColumn(i + 1).width = x); };
    if (one) {
        ws = wb.addWorksheet('All pages'); const all = [];
        pPages.forEach((p, i) => { if (pPages.length > 1) { const r = ws.addRow(['Page ' + (i + 1)]); r.font = {bold: true}; r.getCell(1).fill = {type: 'pattern', pattern: 'solid', fgColor: {argb: 'FFE5E7EB'}}; }
            p.rows.forEach(r => { ws.addRow(r.map(val)); all.push(r); }); });
        widths(ws, all);
    } else pPages.forEach((p, i) => { const s = wb.addWorksheet('Page ' + (i + 1)); p.rows.forEach(r => s.addRow(r.map(val))); widths(s, p.rows); });
    const buf = await wb.xlsx.writeBuffer();
    save(new Blob([buf], {type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}), pName + '.xlsx');
}
function save(blob, name){ const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; a.click(); URL.revokeObjectURL(a.href); }
</script>
<?php include 'footer.php'; ?>
