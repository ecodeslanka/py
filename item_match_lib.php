<?php
// ============================================================
//  item_match_lib.php — shared name / price matching + Gemini scan
//  Used by sampath_item_match.php (Excel check) and sampath_damage_match.php (damage entries).
//  Matches supplier items to the Sampath Inventory Report items by name (size-aware fuzzy), price (TUR) and,
//  for weak rows, a Gemini AI scan (key from AI Settings -> ai_settings table).
// ============================================================

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
/* TUR price changes made on Stock Sync (bulk_adjustment_import3.php). The newest row per item wins; deleting the sync deletes its rows,
   so the TUR goes back to the previous override or to the latest invoice TUR. */
if (isset($conn) && $conn) {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS item_tur_overrides (
        id INT AUTO_INCREMENT PRIMARY KEY, item_code VARCHAR(100) NOT NULL, item_name VARCHAR(300) NULL, tur DECIMAL(15,4) NOT NULL,
        old_tur DECIMAL(15,4) NULL, import_id INT NULL, created_by VARCHAR(100) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_item (item_code), INDEX idx_import (import_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function im_load_tur($conn) {
    $tur = [];
    foreach (['primary_invoice_wise_sales_data', 'invoice_wise_sales_data'] as $t) {   // secondary loaded last so it wins
        if (!im_table($conn, $t)) continue;
        $q = mysqli_query($conn, "SELECT d.product_code, d.tur FROM $t d
            INNER JOIN (SELECT product_code, MAX(id) AS mid FROM $t WHERE product_code <> '' AND tur IS NOT NULL AND tur > 0 GROUP BY product_code) l ON l.mid = d.id");
        while ($q && $r = mysqli_fetch_assoc($q)) $tur[trim($r['product_code'])] = (float)$r['tur'];
    }
    if (im_table($conn, 'item_tur_overrides')) {       // Stock Sync TUR updates win over the invoice TUR
        $q = mysqli_query($conn, "SELECT o.item_code, o.tur FROM item_tur_overrides o INNER JOIN (SELECT item_code, MAX(id) AS mid FROM item_tur_overrides GROUP BY item_code) l ON l.mid = o.id");
        while ($q && $r = mysqli_fetch_assoc($q)) $tur[trim($r['item_code'])] = (float)$r['tur'];
    }
    return $tur;
}

/* ── item masters = the Sampath Inventory Report item list; price = TUR ── */
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

/* ── create items that are not in the system yet ──
   The system has no separate item master: an item exists when it has a row in unilever_purchase_items (latest row per item_code
   gives its name / basepack / UPC / price) or sales data. A missing SKU is therefore created as a ZERO-quantity row in a batch
   called "AUTO-NEW-ITEMS" (no stock effect). When a real purchase for that item is imported later, its newer row takes over.
   $items = [sku => ['name' => .., 'basepack' => ..]]; returns how many items were created. */
function im_create_missing_items($conn, $items, $who = 'System') {
    if (!$items || !im_table($conn, 'unilever_purchase_items') || !im_table($conn, 'unilever_purchase_batches')) return 0;
    $bid = 0;
    $q = mysqli_query($conn, "SELECT id FROM unilever_purchase_batches WHERE batch_no = 'AUTO-NEW-ITEMS' LIMIT 1");
    if ($q && ($r = mysqli_fetch_assoc($q))) $bid = (int)$r['id'];
    if (!$bid) {
        $who_e = mysqli_real_escape_string($conn, (string)$who);
        mysqli_query($conn, "INSERT INTO unilever_purchase_batches (batch_no, filename, received_date, total_rows, imported_by, note)
            VALUES ('AUTO-NEW-ITEMS', 'Auto-created items (bulk imports)', CURDATE(), 0, '$who_e', 'Items created automatically from bulk adjustment / damage imports. Zero quantity - no stock effect.')");
        $bid = (int)mysqli_insert_id($conn);
    }
    if (!$bid) return 0;
    $n = 0;
    foreach ($items as $sku => $it) {
        $sku = trim((string)$sku);
        if ($sku === '') continue;
        $sk = mysqli_real_escape_string($conn, $sku);
        $x = mysqli_query($conn, "SELECT 1 FROM unilever_purchase_items WHERE item_code = '$sk' LIMIT 1");
        if ($x && mysqli_num_rows($x) > 0) continue;
        $nm = mysqli_real_escape_string($conn, mb_substr(trim((string)($it['name'] ?? '')) ?: $sku, 0, 300));
        $bp = mysqli_real_escape_string($conn, mb_substr(trim((string)($it['basepack'] ?? '')), 0, 30));
        if (mysqli_query($conn, "INSERT INTO unilever_purchase_items (batch_id, item_code, item_name, basepack_code, purchase_qty_cases, returned_qty)
            VALUES ($bid, '$sk', '$nm', " . ($bp === '' ? 'NULL' : "'$bp'") . ", 0, 0)")) $n++;
    }
    if ($n) mysqli_query($conn, "UPDATE unilever_purchase_batches SET total_rows = total_rows + $n WHERE id = $bid");
    return $n;
}

/* ── Gemini: pick the best candidate per weak row (small batches; thinking off for speed; one retry) ── */
function im_gemini_pick($key, $batch, &$err) {
    // $batch: list of ['ref'=>..,'name'=>..,'price'=>..,'cands'=>[['i'=>0,'name'=>..,'price'=>..],..]]
    $prompt = "Match each supplier product name to the SAME product in our candidate list (same brand, variant/flavour and pack size; "
        . "the price is only a hint). If no candidate is the same product use null. "
        . "Reply ONLY with a JSON array: [{\"ref\":<row ref>,\"candidate\":<candidate i or null>,\"confidence\":<0-100>}].\n"
        . json_encode($batch, JSON_UNESCAPED_UNICODE);
    $body = json_encode([
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 1024, 'responseMimeType' => 'application/json',
                               'thinkingConfig' => ['thinkingBudget' => 0]],
    ]);
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(GEMINI_MODEL) . ':generateContent';
    $res = false; $http = 0; $cerr = '';
    for ($try = 0; $try < 2 && $res === false; $try++) {          // one retry on a network failure / timeout
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 40,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key]]);
        $res = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res === false) $cerr = curl_error($ch);
        curl_close($ch);
    }
    if ($res === false) { $err = 'Gemini request failed: ' . $cerr; return []; }
    $j = json_decode($res, true);
    if ($http >= 400) { $err = 'Gemini error ' . $http . ': ' . ($j['error']['message'] ?? 'unknown'); return []; }
    $txt = '';
    foreach (($j['candidates'][0]['content']['parts'] ?? []) as $p) $txt .= $p['text'] ?? '';
    $arr = json_decode(trim($txt), true);
    if (!is_array($arr)) { $err = 'Gemini returned an unreadable answer.'; return []; }
    $out = [];
    foreach ($arr as $a) if (isset($a['ref'])) $out[(string)$a['ref']] = ['cand' => isset($a['candidate']) && $a['candidate'] !== null ? (int)$a['candidate'] : null, 'conf' => max(0, min(100, (float)($a['confidence'] ?? 0)))];
    return $out;
}


/* ── AJAX: Gemini scan of one small batch of weak rows (?ajax=ai, called repeatedly by the page) ── */
function im_handle_ai() {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(120);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $key = im_gemini_key();
    if ($key === '') { echo json_encode(['ok' => false, 'error' => 'No Gemini API key saved in AI Settings.']); exit; }
    $batch = array_slice((array)($in['batch'] ?? []), 0, 8);
    $err = '';
    $ans = im_gemini_pick($key, $batch, $err);
    echo json_encode(['ok' => $err === '', 'error' => $err, 'answers' => $ans], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ── Match one supplier item against the masters; returns the match row + the AI candidates when weak ──
   $learned: [sampath_code (upper) => system item code] confirmed earlier (wins as a saved mapping). */
function im_match_one($masters, $by_code, $code, $name, $price, $want_ai, $learned = []) {
    $tok = im_norm($name);
    $cand = [];
    foreach ($masters as $i => $m) $cand[$i] = im_name_score($tok, $m['tok']);
    arsort($cand);
    $top = array_slice($cand, 0, 6, true);
    $pick = null; $method = 'Name';
    $ucode = mb_strtoupper(trim($code));
    $lc = $ucode !== '' ? ($learned[$ucode] ?? null) : null;
    if ($lc !== null && isset($by_code[mb_strtoupper($lc)])) {                 // 0) a mapping confirmed earlier
        $pick = $by_code[mb_strtoupper($lc)][0]; $method = 'Mapped';
    } elseif ($ucode !== '' && isset($by_code[$ucode])) {                       // 1) exact code match
        $best = null;
        foreach ($by_code[$ucode] as $i) { $o = im_overall($cand[$i], im_price_score($price, $masters[$i]['prices'])); if ($best === null || $o > $best[1]) $best = [$i, $o]; }
        $pick = $best[0]; $method = 'Code';
    } else {                                                                    // 2) best name + price among the top candidates
        $bo = -1;
        foreach (array_slice($top, 0, 5, true) as $i => $ns) {
            $o = im_overall($ns, im_price_score($price, $masters[$i]['prices']));
            if ($o > $bo) { $bo = $o; $pick = $i; }
        }
    }
    $ns = $pick !== null ? $cand[$pick] : 0.0;
    $ps = $pick !== null ? im_price_score($price, $masters[$pick]['prices']) : null;
    if ($method === 'Mapped') $ns = 100.0;                                      // confirmed before = full name tally
    $m = $pick !== null ? $masters[$pick] : null;
    $o = im_overall($ns, $ps);
    $row = ['m_src' => $m['src'] ?? '', 'm_code' => $m['code'] ?? '', 'm_name' => $m['name'] ?? '', 'm_price' => $m['price'] ?? null, 'm_psrc' => $m['psrc'] ?? '',
            'name_pct' => round($ns, 1), 'price_pct' => $ps === null ? null : round($ps, 1), 'overall' => round($o, 1), 'method' => $method, 'status' => im_status($o)];
    $cands = null;
    if ($want_ai && $method !== 'Code' && $method !== 'Mapped' && $o < 85) {
        $cands = [];
        foreach (array_keys($top) as $k => $i) {
            $mp = $masters[$i];
            $p = im_price_score($price, $mp['prices']);
            $cands[] = ['i' => $k, 'code' => $mp['code'], 'name' => $mp['name'], 'price' => $mp['price'], 'psrc' => $mp['psrc'] ?? '', 'src' => $mp['src'],
                        'price_pct' => $p === null ? null : round($p, 1)];
        }
    }
    return [$row, $cands];
}
function im_code_index($masters) {
    $by = [];
    foreach ($masters as $i => $m) $by[mb_strtoupper($m['code'])][] = $i;
    return $by;
}
