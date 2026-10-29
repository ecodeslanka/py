<?php
// ============================================================
//  sampath_item_match.php
//  SAMPATH ITEM MATCH REPORT — upload an Excel list and check it against our inventory
//
//  Upload an Excel (columns: Sampath Code, Item Name, Price, Qty — Ref / Return Store optional).
//  Each row is matched to the items of the Sampath Item Stock Report (same item list and prices):
//    Item master  -> unilever_purchase_items (item code / SKU7, name, UPC, price per case -> unit price, MRP)
//    TUR (price)  -> invoice_wise_sales_data.tur (latest per item), else primary_invoice_wise_sales_data.tur
//    plus items that only appear in the secondary sales data -> invoice_wise_sales_data (code, description)
//  Scoring per row:
//    Name %    local fuzzy name match (size-aware) — and, for weak rows, a Gemini AI scan of the best candidates
//    Price %   how close the Excel price is to the item's TUR (latest TUR from the secondary / pre-secondary sales data;
//              items with no TUR fall back to unit price / case price / MRP)
//    Overall % = 60% Name + 40% Price (Name only when the item has no price)
//    Status    >= 85 Matched · 60-84 Review · < 60 No Match
//  The summary shows the tally percentages.
//
//  Gemini: the API key is read from the AI Settings page (ai_settings table, key 'gemini_api_key'), the same key the
//  AI chatbot uses; falls back to a GEMINI_API_KEY constant / environment variable.
// ============================================================
include 'config.php';
include_once 'auth.php';
requireLogin();

require_once 'item_match_lib.php';

/* ── AJAX: Gemini scan of one small batch of weak rows (called repeatedly by the page) ── */
if (($_GET['ajax'] ?? '') === 'ai' && $_SERVER['REQUEST_METHOD'] === 'POST') im_handle_ai();

/* ── AJAX: local match (name + price against the stock report items); returns the weak rows for the AI scan ── */
if (($_GET['ajax'] ?? '') === 'match' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(120);
    $in   = json_decode(file_get_contents('php://input'), true) ?: [];
    $rows = array_slice((array)($in['rows'] ?? []), 0, 1000);
    $want_ai = !empty($in['use_ai']) && im_gemini_key() !== '';
    $masters = im_load_masters($conn);
    $by_code = [];
    foreach ($masters as $i => $m) $by_code[mb_strtoupper($m['code'])][] = $i;

    $out = []; $weak = [];
    foreach ($rows as $n => $r) {
        $code  = trim((string)($r['code'] ?? ''));
        $name  = trim((string)($r['name'] ?? ''));
        $price = (float)($r['price'] ?? 0);
        $tok   = im_norm($name);
        $cand  = [];   // [master index => name score]
        foreach ($masters as $i => $m) $cand[$i] = im_name_score($tok, $m['tok']);
        arsort($cand);
        $top = array_slice($cand, 0, 6, true);
        $pick = null; $method = 'Name';
        if ($code !== '' && isset($by_code[mb_strtoupper($code)])) {          // 1) exact code match wins
            $best = null;
            foreach ($by_code[mb_strtoupper($code)] as $i) { $o = im_overall($cand[$i], im_price_score($price, $masters[$i]['prices'])); if ($best === null || $o > $best[1]) $best = [$i, $o]; }
            $pick = $best[0]; $method = 'Code';
        } else {                                                                // 2) best name + price among the top candidates
            $bo = -1;
            foreach (array_slice($top, 0, 5, true) as $i => $ns) {
                $o = im_overall($ns, im_price_score($price, $masters[$i]['prices']));
                if ($o > $bo) { $bo = $o; $pick = $i; }
            }
        }
        $ns = $pick !== null ? $cand[$pick] : 0.0;
        $ps = $pick !== null ? im_price_score($price, $masters[$pick]['prices']) : null;
        $m = $pick !== null ? $masters[$pick] : null;
        $o = im_overall($ns, $ps);
        $out[] = [
            'ref' => $r['ref'] ?? ($n + 1), 'code' => $r['code'] ?? '', 'name' => $r['name'] ?? '', 'price' => $price, 'qty' => $r['qty'] ?? '',
            'm_src' => $m['src'] ?? '', 'm_code' => $m['code'] ?? '', 'm_name' => $m['name'] ?? '', 'm_price' => $m['price'] ?? null, 'm_psrc' => $m['psrc'] ?? '',
            'name_pct' => round($ns, 1), 'price_pct' => $ps === null ? null : round($ps, 1), 'overall' => round($o, 1), 'method' => $method, 'status' => im_status($o),
        ];
        if ($want_ai && $method !== 'Code' && $o < 85) {
            $cs = [];
            foreach (array_keys($top) as $k => $i) {
                $mp = $masters[$i];
                $p = im_price_score($price, $mp['prices']);
                $cs[] = ['i' => $k, 'code' => $mp['code'], 'name' => $mp['name'], 'price' => $mp['price'], 'psrc' => $mp['psrc'] ?? '', 'src' => $mp['src'],
                         'price_pct' => $p === null ? null : round($p, 1)];
            }
            $weak[] = ['n' => $n, 'ref' => (string)$n, 'name' => $name, 'price' => $price, 'cands' => $cs];
        }
    }
    echo json_encode(['ok' => true, 'rows' => $out, 'masters' => count($masters), 'weak' => $weak, 'ai_key' => im_gemini_key() !== ''], JSON_UNESCAPED_UNICODE);
    exit;
}

$has_key = im_gemini_key() !== '';
include 'header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
<style>
.im{padding:0 4px;}
.im h2{font-size:22px;font-weight:800;margin:0 0 4px;}
.im .sub{font-size:12px;color:#8892a4;margin-bottom:16px;}
.im-card{background:#fff;border:1.5px solid #e2e6f0;border-radius:14px;padding:16px 20px;margin-bottom:18px;}
.im-f{display:flex;gap:14px;flex-wrap:wrap;align-items:center;}
.im-f input[type=file]{border:1.5px dashed #c7cde0;border-radius:10px;padding:12px;background:#fafbff;min-width:280px;}
.im-b{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;background:#0a0c14;color:#fff;}
.im-b.g{background:#14532d;} .im-b[disabled]{opacity:.5;cursor:not-allowed;}
.im-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:18px;}
.im-kpi{background:#fff;border:1.5px solid #e2e6f0;border-radius:12px;padding:12px 16px;}
.im-kpi .l{font-size:10px;font-weight:700;color:#8892a4;text-transform:uppercase;} .im-kpi .v{font-size:22px;font-weight:800;margin-top:2px;}
.im-bar{height:8px;border-radius:6px;background:#eef0f6;overflow:hidden;margin-top:6px;} .im-bar i{display:block;height:100%;}
.im-wrap{overflow:auto;border:1.5px solid #e2e6f0;border-radius:12px;background:#fff;}
table.im-t{width:100%;border-collapse:collapse;font-size:12px;}
table.im-t th{background:#0a0c14;color:#fff;padding:10px;text-align:left;font-size:10.5px;text-transform:uppercase;white-space:nowrap;}
table.im-t td{padding:8px 10px;border-bottom:1px solid #eef0f6;white-space:nowrap;vertical-align:top;}
table.im-t .n{text-align:right;font-family:monospace;}
.pill{display:inline-block;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700;}
.pill.Matched{background:#dcfce7;color:#166534;} .pill.Review{background:#fef3c7;color:#92400e;} .pill.NoMatch{background:#fee2e2;color:#991b1b;}
.pct{font-family:monospace;font-weight:700;}
.im-msg{border-radius:8px;padding:10px 16px;margin-bottom:14px;font-size:13px;}
.im-msg.warn{background:#fffbeb;border:1px solid #fcd34d;color:#92400e;} .im-msg.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;} .im-msg.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
</style>

<div class="im">
    <h2><i class="fa-solid fa-magnifying-glass-chart"></i> Sampath Item Match Report</h2>
    <div class="sub">Upload an Excel list (Sampath Code, Item Name, Price, Qty) and see how well each item tallies with the items of the <strong>Sampath Item Stock Report</strong> by <strong>name</strong> and <strong>price</strong>, with a Gemini AI scan for weak matches.</div>

    <?php if (!$has_key): ?><div class="im-msg warn">No Gemini API key was found, so only the local name/price match will run. Save the key on the <strong>AI Settings</strong> page (<code>ai_settings.php</code>) to enable the AI scan.</div><?php endif; ?>

    <div class="im-card">
        <div class="im-f">
            <input type="file" id="imFile" accept=".xlsx,.xls,.csv">
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;">
                <input type="checkbox" id="imAi" <?php echo $has_key ? 'checked' : 'disabled'; ?>> Use Gemini AI scan for weak matches</label>
            <button class="im-b" id="imRun" type="button" onclick="imRun()" disabled><i class="fa-solid fa-play"></i> Run Match</button>
            <button class="im-b g" id="imExport" type="button" onclick="imExport()" disabled><i class="fa-solid fa-file-excel"></i> Export Excel</button>
        </div>
        <div id="imInfo" style="font-size:12px;color:#6b7280;margin-top:10px;"></div>
    </div>

    <div id="imMsgs"></div>
    <div class="im-kpis" id="imKpis" style="display:none"></div>
    <div class="im-wrap" id="imResultWrap" style="display:none">
        <table class="im-t">
            <thead><tr><th>Ref</th><th>Sampath Code</th><th>Excel Item Name</th><th class="n">Excel Price</th><th class="n">Qty</th>
                <th>Matched Stock Report Item</th><th class="n">Our TUR Price</th><th class="n">Name %</th><th class="n">Price %</th><th class="n">Overall %</th><th>Method</th><th>Status</th></tr></thead>
            <tbody id="imBody"></tbody>
        </table>
    </div>
</div>

<script>
let imRows = [], imResult = null;
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fm = (v, d=2) => v === null || v === undefined || v === '' ? '—' : Number(v).toLocaleString('en-US', {minimumFractionDigits:d, maximumFractionDigits:d});

document.getElementById('imFile').addEventListener('change', async e => {
    const f = e.target.files[0]; imRows = []; imResult = null;
    document.getElementById('imRun').disabled = true; document.getElementById('imExport').disabled = true;
    document.getElementById('imKpis').style.display = 'none'; document.getElementById('imResultWrap').style.display = 'none';
    document.getElementById('imMsgs').innerHTML = '';
    if (!f) return;
    try {
        const wb = XLSX.read(await f.arrayBuffer(), {type:'array'});
        const rows = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], {header:1, defval:''});
        // find the header row and map columns by name
        let h = rows.findIndex(r => r.some(c => /item\s*name|^name$/i.test(String(c))));
        if (h < 0) h = 0;
        const head = rows[h].map(c => String(c).trim().toLowerCase());
        const col = re => head.findIndex(c => re.test(c));
        const cCode = col(/code|cod$/), cName = col(/item\s*name|^name$|description/), cPrice = col(/price|rate|value/), cQty = col(/^qty|quantity/), cRef = col(/^ref|^no$|^#$/);
        if (cName < 0) throw new Error('Could not find an "Item Name" column.');
        for (let i = h + 1; i < rows.length; i++) {
            const r = rows[i]; const name = String(r[cName] ?? '').trim();
            if (!name) continue;
            imRows.push({ref: cRef >= 0 ? r[cRef] : imRows.length + 1, code: cCode >= 0 ? String(r[cCode]).trim() : '', name,
                         price: cPrice >= 0 ? parseFloat(String(r[cPrice]).replace(/,/g,'')) || 0 : 0, qty: cQty >= 0 ? r[cQty] : ''});
        }
        document.getElementById('imInfo').textContent = imRows.length + ' item row(s) read from "' + wb.SheetNames[0] + '" (' + f.name + ').';
        document.getElementById('imRun').disabled = imRows.length === 0;
    } catch (err) {
        document.getElementById('imMsgs').innerHTML = '<div class="im-msg err">' + esc(err.message) + '</div>';
    }
});

function imStatus(o){ return o >= 85 ? 'Matched' : (o >= 60 ? 'Review' : 'No Match'); }
function imOverall(name, price){ return price === null || price === undefined ? name : (0.6 * name + 0.4 * price); }
function imSummarize(rows){
    const s = {rows: rows.length, matched: 0, review: 0, nomatch: 0, price_ok: 0, price_known: 0, overall_sum: 0, name_sum: 0};
    rows.forEach(r => {
        s.overall_sum += r.overall; s.name_sum += r.name_pct;
        if (r.status === 'Matched') s.matched++; else if (r.status === 'Review') s.review++; else s.nomatch++;
        if (r.price_pct !== null && r.price_pct !== undefined) { s.price_known++; if (r.price_pct >= 95) s.price_ok++; }
    });
    return s;
}

async function imRun(){
    const btn = document.getElementById('imRun'); btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Matching…';
    const msgs = document.getElementById('imMsgs'); msgs.innerHTML = '';
    try {
        const r = await fetch('sampath_item_match.php?ajax=match', {method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({rows: imRows, use_ai: document.getElementById('imAi').checked})});
        imResult = await r.json();
        if (!imResult.ok) throw new Error('Match failed.');
        imResult.ai = {asked: imResult.weak.length, used: 0, failed: 0, error: ''};
        imRender();
        // Gemini scan of the weak rows, a few at a time so no single request runs long
        if (imResult.weak.length) await imAiScan(btn);
    } catch (err) {
        msgs.innerHTML = '<div class="im-msg err">' + esc(err.message) + '</div>';
    }
    btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-play"></i> Run Match';
}

async function imAiScan(btn){
    const weak = imResult.weak, chunk = 5; let fails = 0;
    for (let i = 0; i < weak.length; i += chunk) {
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Gemini scan ' + Math.min(i + chunk, weak.length) + '/' + weak.length + '…';
        const part = weak.slice(i, i + chunk);
        let res;
        try {
            const r = await fetch('sampath_item_match.php?ajax=ai', {method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify({batch: part.map(w => ({ref: w.ref, name: w.name, price: w.price, cands: w.cands.map(c => ({i: c.i, name: c.name, price: c.price}))}))})});
            res = await r.json();
        } catch (e) { res = {ok: false, error: 'Gemini request failed: ' + e.message}; }
        if (!res.ok) {
            imResult.ai.failed += part.length; imResult.ai.error = res.error; fails++;
            if (fails >= 3) break;               // give up after 3 failed batches; remaining rows keep their local match
            continue;
        }
        part.forEach(w => {
            const a = res.answers[w.ref]; if (!a) return;
            imResult.ai.used++;
            const row = imResult.rows[w.n];
            const c = a.cand !== null ? w.cands[a.cand] : null;
            if (c) {
                Object.assign(row, {m_src: c.src, m_code: c.code, m_name: c.name, m_price: c.price, m_psrc: c.psrc,
                                    name_pct: a.conf, price_pct: c.price_pct, method: 'AI'});
            } else {
                row.name_pct = Math.min(row.name_pct, 40); row.method = 'AI: no match';
            }
            row.overall = Math.round(imOverall(row.name_pct, row.price_pct) * 10) / 10;
            row.status = imStatus(row.overall);
        });
        imRender();
    }
    imRender();
}

function imRender(){
    const d = imResult, s = imSummarize(d.rows), n = s.rows || 1;
    let msgs = '';
    if (!d.masters) msgs += '<div class="im-msg err">No stock report items found (Unilever Purchase Import / secondary sales data are empty), so nothing could be matched.</div>';
    if (d.ai.error) msgs += '<div class="im-msg err">Gemini AI scan problem (' + d.ai.failed + ' row(s) kept their local match): ' + esc(d.ai.error) + '</div>';
    if (document.getElementById('imAi').checked && d.ai_key && d.ai.asked) msgs += '<div class="im-msg ok">Gemini AI scanned ' + d.ai.asked + ' weak row(s); ' + d.ai.used + ' answered.</div>';
    document.getElementById('imMsgs').innerHTML = msgs;
    const pct = v => (v / n * 100).toFixed(1) + '%';
    const kpi = (l, v, sub, color) => `<div class="im-kpi"><div class="l">${l}</div><div class="v">${v}</div>${sub ? `<div class="im-bar"><i style="width:${sub};background:${color}"></i></div>` : ''}</div>`;
    const avg = (s.overall_sum / n).toFixed(1);
    document.getElementById('imKpis').innerHTML =
        kpi('Items in file', s.rows) +
        kpi('Overall tally', avg + '%', avg + '%', '#2563eb') +
        kpi('Matched (≥ 85%)', s.matched + ' · ' + pct(s.matched), pct(s.matched), '#16a34a') +
        kpi('Review (60–84%)', s.review + ' · ' + pct(s.review), pct(s.review), '#d97706') +
        kpi('No match (< 60%)', s.nomatch + ' · ' + pct(s.nomatch), pct(s.nomatch), '#dc2626') +
        kpi('Avg name match', (s.name_sum / n).toFixed(1) + '%', (s.name_sum / n).toFixed(1) + '%', '#7c3aed') +
        kpi('Price tally (≤ 5% off)', s.price_known ? (s.price_ok / s.price_known * 100).toFixed(1) + '%' : 'n/a', s.price_known ? (s.price_ok / s.price_known * 100).toFixed(1) + '%' : '0%', '#0891b2');
    document.getElementById('imKpis').style.display = '';
    const col = v => v >= 85 ? '#166534' : v >= 60 ? '#92400e' : '#991b1b';
    document.getElementById('imBody').innerHTML = d.rows.map(r => `<tr>
        <td>${esc(r.ref)}</td><td>${esc(r.code)}</td><td style="max-width:300px;white-space:normal">${esc(r.name)}</td><td class="n">${fm(r.price)}</td><td class="n">${esc(r.qty)}</td>
        <td style="max-width:320px;white-space:normal">${r.m_code ? '<strong>' + esc(r.m_code) + '</strong> - ' + esc(r.m_name) + ' <span style="color:#8892a4">(' + esc(r.m_src) + ')</span>' : '—'}</td>
        <td class="n">${fm(r.m_price)}${r.m_price !== null && r.m_psrc && r.m_psrc !== 'TUR' ? ' <span style="color:#b45309;font-size:10px" title="No TUR on record - ' + esc(r.m_psrc) + ' used">(' + esc(r.m_psrc) + ')</span>' : ''}</td>
        <td class="n pct">${fm(r.name_pct, 1)}%</td><td class="n pct">${r.price_pct === null ? 'n/a' : fm(r.price_pct, 1) + '%'}</td>
        <td class="n pct" style="color:${col(r.overall)}">${fm(r.overall, 1)}%</td><td>${esc(r.method)}</td>
        <td><span class="pill ${r.status.replace(' ', '')}">${esc(r.status)}</span></td></tr>`).join('');
    document.getElementById('imResultWrap').style.display = '';
    document.getElementById('imExport').disabled = false;
}

async function imExport(){
    if (!imResult) return;
    const wb = new ExcelJS.Workbook(), ws = wb.addWorksheet('Item Match');
    const h = ws.addRow(['Ref','Sampath Code','Excel Item Name','Excel Price','Qty','Matched Code','Matched Item','Source','Our TUR Price','Name %','Price %','Overall %','Method','Status']);
    h.eachCell(c => { c.font = {bold:true}; c.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FFFBE5D6'}}; });
    imResult.rows.forEach(r => ws.addRow([r.ref, r.code, r.name, r.price, r.qty, r.m_code, r.m_name, r.m_src, r.m_price, r.name_pct, r.price_pct, r.overall, r.method, r.status]));
    const s = imSummarize(imResult.rows), n = s.rows || 1;
    ws.addRow([]);
    ws.addRow(['Summary']).font = {bold:true};
    ws.addRow(['Items', s.rows]); ws.addRow(['Overall tally %', +(s.overall_sum / n).toFixed(1)]);
    ws.addRow(['Matched (>=85%)', s.matched, +(s.matched / n * 100).toFixed(1) + '%']);
    ws.addRow(['Review (60-84%)', s.review, +(s.review / n * 100).toFixed(1) + '%']);
    ws.addRow(['No match (<60%)', s.nomatch, +(s.nomatch / n * 100).toFixed(1) + '%']);
    [3,7].forEach(c => ws.getColumn(c).width = 44); ws.getColumn(2).width = 14;
    const buf = await wb.xlsx.writeBuffer();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([buf], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));
    a.download = 'sampath_item_match_' + new Date().toISOString().slice(0,10) + '.xlsx'; a.click(); URL.revokeObjectURL(a.href);
}
</script>
<?php include 'footer.php'; ?>
