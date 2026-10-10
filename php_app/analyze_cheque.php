<?php
/**
 * analyze_cheque.php
 * Sri Lankan cheque analyser — powered by Google Gemini 2.5 Flash
 *
 * ══════════════════════════════════════════════════
 *  SETUP:
 *    1. Get a free Gemini API key: https://aistudio.google.com/app/apikey
 *    2. Set your key below OR via environment variable:
 *         export GEMINI_API_KEY="YOUR_KEY_HERE"
 *
 *  TEST: open  analyze_cheque.php?test=1  in browser
 * ══════════════════════════════════════════════════
 */

/* ── Configuration ──────────────────────────────── */
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: 'AIzaSyBe1FCKJGO5mkGvjkEyfCLhzORSpZT3Z9w');
define('GEMINI_MODEL',   'gemini-2.5-flash');
define('GEMINI_ENDPOINT',
    'https://generativelanguage.googleapis.com/v1beta/models/'
    . GEMINI_MODEL . ':generateContent?key=' . GEMINI_API_KEY
);

/* ── Self-test endpoint ─────────────────────────── */
if (isset($_GET['test'])) {
    header('Content-Type: application/json');
    $key_set = GEMINI_API_KEY !== 'YOUR_GEMINI_API_KEY_HERE';

    $ping_ok  = false;
    $ping_msg = 'Not tested (API key not set)';
    if ($key_set) {
        $ping_payload = json_encode([
            'contents'         => [['parts' => [['text' => 'Say OK']]]],
            'generationConfig' => ['maxOutputTokens' => 5],
        ]);
        $ch = curl_init(GEMINI_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $ping_payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ping_ok  = ($http === 200);
        $ping_msg = $ping_ok ? 'API key valid ✓' : "HTTP {$http}: " . substr($resp, 0, 300);
    }

    echo json_encode([
        'php_version'     => PHP_VERSION,
        'curl_loaded'     => extension_loaded('curl'),
        'gd_loaded'       => extension_loaded('gd'),
        'gemini_model'    => GEMINI_MODEL,
        'api_key_set'     => $key_set,
        'api_key_preview' => $key_set ? substr(GEMINI_API_KEY, 0, 8) . '...' : 'NOT SET',
        'gemini_ping'     => $ping_msg,
        'upload_max'      => ini_get('upload_max_filesize'),
        'post_max'        => ini_get('post_max_size'),
        'tmp_writable'    => is_writable(sys_get_temp_dir()),
    ], JSON_PRETTY_PRINT);
    exit;
}

/* ── Main POST handler ──────────────────────────── */
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required. Visit ?test=1 to verify setup.']);
    exit;
}

if (!extension_loaded('curl')) {
    echo json_encode(['success' => false, 'error' => 'PHP cURL extension is required but not loaded.']);
    exit;
}

if (GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE') {
    echo json_encode(['success' => false, 'error' => 'Gemini API key not configured.']);
    exit;
}

/* ── Read uploaded image ────────────────────────── */
$image_data = '';
$image_mime = 'image/jpeg';

if (!empty($_FILES['cheque_front']['tmp_name']) && $_FILES['cheque_front']['error'] === UPLOAD_ERR_OK) {
    $image_data = file_get_contents($_FILES['cheque_front']['tmp_name']);
    $image_mime = mime_content_type($_FILES['cheque_front']['tmp_name']) ?: 'image/jpeg';
} else {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!empty($body['image'])) {
        $image_data = base64_decode($body['image']);
        $image_mime = $body['mime'] ?? 'image/jpeg';
    }
}

if (empty($image_data)) {
    $code = isset($_FILES['cheque_front']['error'])
        ? 'Upload error code: ' . $_FILES['cheque_front']['error']
        : 'No image received';
    echo json_encode(['success' => false, 'error' => $code]);
    exit;
}

$image_b64 = base64_encode($image_data);

/* ── Build Gemini prompt ────────────────────────── */
$prompt = 'You are an expert at reading Sri Lankan bank cheques. '
    . 'Extract every visible field from this cheque image. '
    . 'Use null for fields you cannot read. '
    . 'Return a JSON object with exactly these keys: '
    . 'bank_name, branch_name, account_holder, cheque_date (YYYY-MM-DD), '
    . 'payee, amount (numeric string e.g. "25354.60"), amount_in_words, '
    . 'cheque_no (6 digits), bank_code (4 digits), branch_code (4 digits), account_no.';

/* ── Call Gemini API with JSON mode enabled ─────── */
// responseMimeType forces Gemini to output ONLY valid JSON — no prose, no fences
$payload = json_encode([
    'contents' => [[
        'parts' => [
            [
                'inline_data' => [
                    'mime_type' => $image_mime,
                    'data'      => $image_b64,
                ],
            ],
            ['text' => $prompt],
        ],
    ]],
    'generationConfig' => [
        'temperature'      => 0.0,
        'maxOutputTokens'  => 2048,          // enough for all fields
        'responseMimeType' => 'application/json',  // ← JSON mode: guarantees pure JSON output
    ],
], JSON_UNESCAPED_UNICODE);

$ch = curl_init(GEMINI_ENDPOINT);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
]);

$raw      = curl_exec($ch);
$http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

/* ── Handle transport errors ────────────────────── */
if ($curl_err) {
    echo json_encode(['success' => false, 'error' => 'cURL error: ' . $curl_err]);
    exit;
}
if ($http !== 200) {
    $detail = json_decode($raw, true);
    $msg    = $detail['error']['message'] ?? substr($raw, 0, 400);
    echo json_encode(['success' => false, 'error' => "Gemini API HTTP {$http}: {$msg}"]);
    exit;
}

/* ── Parse Gemini response ──────────────────────── */
$api_resp = json_decode($raw, true);

// Collect text across all parts (thinking model streams multiple parts)
$gem_text = '';
foreach (($api_resp['candidates'][0]['content']['parts'] ?? []) as $part) {
    if (isset($part['text'])) {
        $gem_text .= $part['text'];
    }
}
$gem_text = trim($gem_text);

// With JSON mode the text should already be clean JSON.
// Still do a safety strip of any accidental fences.
$clean = preg_replace('/^```(?:json)?\s*/i', '', $gem_text);
$clean = preg_replace('/\s*```\s*$/m', '', $clean);
$clean = trim($clean);

$fields = json_decode($clean, true);

// Last-resort: grab the first {...} block in case something leaked
if (!is_array($fields)) {
    if (preg_match('/\{[\s\S]+\}/U', $clean, $m)) {
        $fields = json_decode($m[0], true);
    }
}

if (!is_array($fields)) {
    echo json_encode([
        'success' => false,
        'error'   => 'Gemini did not return parseable JSON. See _raw for details.',
        '_raw'    => substr($gem_text, 0, 2000),
    ]);
    exit;
}

if (!empty($fields['error'])) {
    echo json_encode(['success' => false, 'error' => $fields['error']]);
    exit;
}

/* ── Normalise & return ─────────────────────────── */
$v = function ($k) use ($fields) {
    $val = $fields[$k] ?? null;
    if ($val === null || $val === '' || strtolower((string)$val) === 'null') return null;
    return $val;
};

echo json_encode([
    'success'         => true,
    'bank_name'       => $v('bank_name'),
    'branch_name'     => $v('branch_name'),
    'account_holder'  => $v('account_holder'),
    'cheque_date'     => $v('cheque_date'),
    'payee'           => $v('payee'),
    'amount'          => $v('amount'),
    'amount_in_words' => $v('amount_in_words'),
    'cheque_no'       => $v('cheque_no'),
    'bank_code'       => $v('bank_code'),
    'branch_code'     => $v('branch_code'),
    'account_no'      => $v('account_no'),
    // '_debug_raw'   => $gem_text,   // uncomment only if you need to debug
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);