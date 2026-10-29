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

if (!defined('GEMINI_MODEL')) define('GEMINI_MODEL', 'gemini-2.5-flash');   // same model as the AI chatbot
function im_gemini_key() {
    global $conn;
    // 1) AI Settings page -> ai_settings table
    if (isset($conn) && $conn) {
        $q = @mysqli_query($conn, "SELECT `value` FROM ai_settings WHERE `key`='gemini_api_key' LIMIT 1");
        $r = $q ? mysqli_fetch_assoc($q) : null;
        if ($r && trim((string)$r['value']) !== '') return trim($r['value']);
    }
    if (defined('GEMINI_API_KEY') && GEMINI_API_KEY) return GEMINI_API_KEY;
    $k = getenv('GEMINI_API_KEY');
    return $k ? $k : '';
}

function im_table($conn, $t) {
    $q = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'");
    return $q && mysqli_num_rows($q) > 0;
}

/* ── name normalisation / scoring ── */
function im_norm($s) {
    $s = mb_strtolower((string)$s, 'UTF-8');
    $s = str_replace('&', ' and ', $s);
    // sizes: 1kg -> 1000g, 1.5l -> 1500ml, 500 gm -> 500g
    $s = preg_replace_callback('/(\d+(?:\.\d+)?)\s*(kg|kgs)\b/u', function ($m) { return ((float)$m[1] * 1000) . 'g'; }, $s);
    $s = preg_replace_callback('/(\d+(?:\.\d+)?)\s*(l|ltr|litre|liter|ltrs)\b/u', function ($m) { return ((float)$m[1] * 1000) . 'ml'; }, $s);
    $s = preg_replace('/(\d+(?:\.\d+)?)\s*(gms|gm|grams|gram|g)\b/u', '$1g', $s);
    $s = preg_replace('/(\d+(?:\.\d+)?)\s*(mls|ml)\b/u', '$1ml', $s);
    $s = preg_replace('/[^a-z0-9. ]+/u', ' ', $s);
    $s = preg_replace('/(?<!\d)\.|\.(?!\d)/u', ' ', $s);
    $stop = ['unilever', 'ltd', 'pvt', 'and', 'in', 'the', 'of', 'with', 'for', 'pack', 'pkt', 'sachet'];
    $tok = array_values(array_filter(preg_split('/\s+/', trim($s)), function ($t) use ($stop) { return $t !== '' && !in_array($t, $stop, true); }));
    return $tok;
}
function im_sizes($tok) {
    return array_values(array_filter($tok, function ($t) { return preg_match('/^\d+(\.\d+)?(g|ml)$/', $t); }));
}
function im_name_score($tokA, $tokB) {
    if (!$tokA || !$tokB) return 0.0;
    $a = array_unique($tokA); $b = array_unique($tokB);
    $inter = count(array_intersect($a, $b));
    $jac = $inter / max(1, count(array_unique(array_merge($a, $b))));
    similar_text(implode(' ', $tokA), implode(' ', $tokB), $pct);
    $score = (0.55 * $jac * 100) + (0.45 * $pct);
    $sa = im_sizes($tokA); $sb = im_sizes($tokB);
    if ($sa && $sb && !array_intersect($sa, $sb)) $score *= 0.55;      // different pack size = probably a different item
    return max(0.0, min(100.0, $score));
}
function im_price_score($excel, $prices) {
    $best = null;
    foreach ($prices as $p) {
        if ($p === null || $p <= 0 || $excel <= 0) continue;
        $ratio = min($excel, $p) / max($excel, $p);
        $sc = $ratio >= 0.99 ? 100.0 : max(0.0, 100.0 - (1 - $ratio) * 200.0);
        if ($best === null || $sc > $best) $best = $sc;
    }
    return $best;   // null = item has no price to compare
}
function im_overall($name, $price) {
    return $price === null ? $name : (0.6 * $name + 0.4 * $price);
}
function im_status($o) { return $o >= 85 ? 'Matched' : ($o >= 60 ? 'Review' : 'No Match'); }

/* ── latest TUR (trade unit rate) per item from the Sampath sales data: secondary first, pre-secondary as fallback ── */
function im_load_tur($conn) {
    $tur = [];
    foreach (['primary_invoice_wise_sales_data', 'invoice_wise_sales_data'] as $t) {   // secondary loaded last so it wins
        if (!im_table($conn, $t)) continue;
        $q = mysqli_query($conn, "SELECT d.product_code, d.tur FROM $t d
            INNER JOIN (SELECT product_code, MAX(id) AS mid FROM $t WHERE product_code <> '' AND tur IS NOT NULL AND tur > 0 GROUP BY product_code) l ON l.mid = d.id");
        while ($q && $r = mysqli_fetch_assoc($q)) $tur[trim($r['product_code'])] = (float)$r['tur'];
    }
    return $tur;
}

/* ── item masters = the Sampath Item Stock Report item list; price = TUR ── */
function im_load_masters($conn) {
    $items = []; $seen = [];
    $tur = im_load_tur($conn);
    if (im_table($conn, 'unilever_purchase_items')) {
        $q = mysqli_query($conn, "SELECT p.item_code, p.item_name, p.upc, p.price_per_case, p.mrp
            FROM unilever_purchase_items p
            INNER JOIN (SELECT item_code, MAX(id) AS max_id FROM unilever_purchase_items WHERE item_code IS NOT NULL AND item_code <> '' GROUP BY item_code) l ON l.max_id = p.id");
        while ($q && $r = mysqli_fetch_assoc($q)) {
            $upc = (float)preg_replace('/[^0-9.]/', '', (string)$r['upc']);
            $case = (float)$r['price_per_case'];
            $code = trim($r['item_code']);
            $seen[$code] = true;
            $unit = $upc > 0 ? round($case / $upc, 2) : ($case ?: null);
            if (isset($tur[$code])) { $prices = [$tur[$code]]; $price = $tur[$code]; $psrc = 'TUR'; }
            else { $prices = [$upc > 0 ? $case / $upc : null, $case ?: null, (float)$r['mrp'] ?: null]; $price = $unit; $psrc = 'Unit price'; }
            $items[] = ['src' => 'Stock Report', 'code' => $code, 'name' => (string)$r['item_name'], 'tok' => im_norm($r['item_name']),
                        'prices' => $prices, 'price' => $price, 'psrc' => $psrc];
        }
    }
    /* items the stock report also lists because they have secondary sales / Dmg-Exp but no purchase row */
    if (im_table($conn, 'invoice_wise_sales_data')) {
        $q = mysqli_query($conn, "SELECT product_code, MAX(product_description) AS nm, MAX(mrp) AS mrp
            FROM invoice_wise_sales_data WHERE product_code IS NOT NULL AND product_code <> '' GROUP BY product_code");
        while ($q && $r = mysqli_fetch_assoc($q)) {
            $code = trim($r['product_code']);
            if (isset($seen[$code])) continue;
            $t = $tur[$code] ?? null; $mrp = (float)$r['mrp'];
            $items[] = ['src' => 'Stock Report', 'code' => $code, 'name' => (string)$r['nm'], 'tok' => im_norm($r['nm']),
                        'prices' => $t ? [$t] : [$mrp ?: null], 'price' => $t ?: ($mrp ?: null), 'psrc' => $t ? 'TUR' : 'MRP'];
        }
    }
    return $items;
}

/* ── Gemini: pick the best candidate per weak row (batched) ── */
function im_gemini_pick($key, $batch, &$err) {
    // $batch: list of ['ref'=>..,'name'=>..,'price'=>..,'cands'=>[['i'=>0,'name'=>..,'price'=>..],..]]
    $prompt = "You match product names from a supplier's Excel list to our inventory. For each row choose the candidate that is the SAME product "
        . "(same brand, variant/flavour and pack size; prices are a hint, not decisive). If none is the same product answer null. "
        . "Reply ONLY with a JSON array: [{\"ref\":<row ref>,\"candidate\":<index or null>,\"confidence\":<0-100 name-match confidence>}].\n"
        . json_encode($batch, JSON_UNESCAPED_UNICODE);
    $body = json_encode([
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0, 'responseMimeType' => 'application/json'],
    ]);
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(GEMINI_MODEL) . ':generateContent';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key]]);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($res === false) { $err = 'Gemini request failed: ' . curl_error($ch); curl_close($ch); return []; }
    curl_close($ch);
    $j = json_decode($res, true);
    if ($http >= 400) { $err = 'Gemini error ' . $http . ': ' . ($j['error']['message'] ?? 'unknown'); return []; }
    $txt = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $arr = json_decode(trim($txt), true);
    if (!is_array($arr)) { $err = 'Gemini returned an unreadable answer.'; return []; }
    $out = [];
    foreach ($arr as $a) if (isset($a['ref'])) $out[(string)$a['ref']] = ['cand' => isset($a['candidate']) && $a['candidate'] !== null ? (int)$a['candidate'] : null, 'conf' => max(0, min(100, (float)($a['confidence'] ?? 0)))];
    return $out;
}

/* ── AJAX: run the match ── */
if (($_GET['ajax'] ?? '') === 'match' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(180);
    $in   = json_decode(file_get_contents('php://input'), true) ?: [];
    $rows = array_slice((array)($in['rows'] ?? []), 0, 1000);
    $use_ai = !empty($in['use_ai']);
    $key  = im_gemini_key();
    $masters = im_load_masters($conn);
    $by_code = [];
    foreach ($masters as $i => $m) $by_code[mb_strtoupper($m['code'])][] = $i;

    $res = []; $weak = [];
    foreach ($rows as $n => $r) {
        $code  = trim((string)($r['code'] ?? ''));
        $name  = trim((string)($r['name'] ?? ''));
        $price = (float)($r['price'] ?? 0);
        $tok   = im_norm($name);
        $cand  = [];   // [index => name score]
        foreach ($masters as $i => $m) $cand[$i] = im_name_score($tok, $m['tok']);
        arsort($cand);
        $top = array_slice($cand, 0, 8, true);
        $pick = null; $method = 'Name';
        // 1) exact code match wins
        if ($code !== '' && isset($by_code[mb_strtoupper($code)])) {
            $best = null;
            foreach ($by_code[mb_strtoupper($code)] as $i) { $o = im_overall($cand[$i], im_price_score($price, $masters[$i]['prices'])); if ($best === null || $o > $best[1]) $best = [$i, $o]; }
            $pick = $best[0]; $method = 'Code';
        } else {
            // 2) best overall (name + price) among the top name candidates
            $bo = -1;
            foreach (array_slice($top, 0, 5, true) as $i => $ns) {
                $o = im_overall($ns, im_price_score($price, $masters[$i]['prices']));
                if ($o > $bo) { $bo = $o; $pick = $i; }
            }
        }
        $ns = $pick !== null ? $cand[$pick] : 0.0;
        $ps = $pick !== null ? im_price_score($price, $masters[$pick]['prices']) : null;
        $res[$n] = ['pick' => $pick, 'name_pct' => $ns, 'price_pct' => $ps, 'method' => $method, 'top' => array_keys($top)];
        $overall = im_overall($ns, $ps);
        if ($use_ai && $key && $method !== 'Code' && $overall < 85) $weak[$n] = true;
    }

    /* Gemini scan of the weak rows, 12 per request */
    $ai_err = ''; $ai_used = 0;
    if ($use_ai && $key && $weak) {
        foreach (array_chunk(array_keys($weak), 12) as $chunk) {
            $batch = [];
            foreach ($chunk as $n) {
                $cs = [];
                foreach ($res[$n]['top'] as $k => $i) $cs[] = ['i' => $k, 'name' => $masters[$i]['name'], 'price' => $masters[$i]['price']];
                $batch[] = ['ref' => $n, 'name' => (string)($rows[$n]['name'] ?? ''), 'price' => (float)($rows[$n]['price'] ?? 0), 'cands' => $cs];
            }
            $e = '';
            $ans = im_gemini_pick($key, $batch, $e);
            if ($e) { $ai_err = $e; break; }
            foreach ($ans as $ref => $a) {
                if (!isset($res[$ref])) continue;
                $ai_used++;
                if ($a['cand'] !== null && isset($res[$ref]['top'][$a['cand']])) {
                    $i = $res[$ref]['top'][$a['cand']];
                    $price = (float)($rows[$ref]['price'] ?? 0);
                    $res[$ref]['pick'] = $i;
                    $res[$ref]['name_pct'] = $a['conf'];
                    $res[$ref]['price_pct'] = im_price_score($price, $masters[$i]['prices']);
                    $res[$ref]['method'] = 'AI';
                }
            }
        }
    }

    $out = [];
    $sum = ['rows' => 0, 'matched' => 0, 'review' => 0, 'nomatch' => 0, 'price_ok' => 0, 'price_known' => 0, 'overall_sum' => 0, 'name_sum' => 0];
    foreach ($rows as $n => $r) {
        $x = $res[$n];
        $m = $x['pick'] !== null ? $masters[$x['pick']] : null;
        $o = im_overall($x['name_pct'], $x['price_pct']);
        $st = im_status($o);
        $sum['rows']++; $sum['overall_sum'] += $o; $sum['name_sum'] += $x['name_pct'];
        if ($st === 'Matched') $sum['matched']++; elseif ($st === 'Review') $sum['review']++; else $sum['nomatch']++;
        if ($x['price_pct'] !== null) { $sum['price_known']++; if ($x['price_pct'] >= 95) $sum['price_ok']++; }
        $out[] = [
            'ref' => $r['ref'] ?? ($n + 1), 'code' => $r['code'] ?? '', 'name' => $r['name'] ?? '', 'price' => (float)($r['price'] ?? 0), 'qty' => $r['qty'] ?? '',
            'm_src' => $m['src'] ?? '', 'm_code' => $m['code'] ?? '', 'm_name' => $m['name'] ?? '', 'm_price' => $m['price'] ?? null, 'm_psrc' => $m['psrc'] ?? '',
            'name_pct' => round($x['name_pct'], 1), 'price_pct' => $x['price_pct'] === null ? null : round($x['price_pct'], 1),
            'overall' => round($o, 1), 'method' => $x['method'], 'status' => $st,
        ];
    }
    echo json_encode(['ok' => true, 'rows' => $out, 'summary' => $sum, 'masters' => count($masters), 'ai' => ['key' => $key !== '', 'asked' => count($weak), 'used' => $ai_used, 'error' => $ai_err]], JSON_UNESCAPED_UNICODE);
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

async function imRun(){
    const btn = document.getElementById('imRun'); btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Matching…';
    document.getElementById('imMsgs').innerHTML = '';
    try {
        const r = await fetch('sampath_item_match.php?ajax=match', {method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({rows: imRows, use_ai: document.getElementById('imAi').checked})});
        imResult = await r.json();
        if (!imResult.ok) throw new Error('Match failed.');
        imRender();
    } catch (err) {
        document.getElementById('imMsgs').innerHTML = '<div class="im-msg err">' + esc(err.message) + '</div>';
    }
    btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-play"></i> Run Match';
}

function imRender(){
    const d = imResult, s = d.summary, n = s.rows || 1;
    let msgs = '';
    if (!d.masters) msgs += '<div class="im-msg err">No stock report items found (Unilever Purchase Import / secondary sales data are empty), so nothing could be matched.</div>';
    if (d.ai.error) msgs += '<div class="im-msg err">Gemini AI scan stopped: ' + esc(d.ai.error) + ' (local match used for the remaining rows).</div>';
    else if (document.getElementById('imAi').checked && d.ai.key) msgs += '<div class="im-msg ok">Gemini AI scanned ' + d.ai.asked + ' weak row(s); ' + d.ai.used + ' answered.</div>';
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
    const s = imResult.summary, n = s.rows || 1;
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
