<?php
/**
 * analyze_cheque.php — Server-side proxy for Anthropic API
 * Receives a cheque image (base64), sends to Claude, returns extracted fields.
 * Keep your API key only on the server — never in frontend JS.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // tighten this to your domain in production

// ── YOUR API KEY ──────────────────────────────────────────
$api_key = 'sk-ant-YOUR-KEY-HERE'; // ← paste your key here
// ─────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

// Accept either a file upload or raw base64 JSON body
$image_b64  = '';
$mime_type  = 'image/jpeg';

if (!empty($_FILES['cheque_front']['tmp_name'])) {
    // File upload from FormData
    $tmp  = $_FILES['cheque_front']['tmp_name'];
    $mime_type = $_FILES['cheque_front']['type'] ?: 'image/jpeg';
    $image_b64 = base64_encode(file_get_contents($tmp));
} else {
    // JSON body with base64
    $body = json_decode(file_get_contents('php://input'), true);
    $image_b64 = $body['image'] ?? '';
    $mime_type  = $body['mime_type'] ?? 'image/jpeg';
}

if (!$image_b64) {
    echo json_encode(['success' => false, 'error' => 'No image provided']);
    exit;
}

// Build Anthropic API request
$payload = json_encode([
    'model'      => 'claude-sonnet-4-20250514',
    'max_tokens' => 400,
    'messages'   => [[
        'role'    => 'user',
        'content' => [
            [
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $mime_type,
                    'data'       => $image_b64,
                ]
            ],
            [
                'type' => 'text',
                'text' => 'This is a bank cheque image. Extract these fields and return ONLY a valid JSON object, no markdown, no explanation: {"cheque_no":"cheque number printed at bottom","bank_code":"bank code or identifier","branch_code":"branch code number","cheque_date":"date on cheque in YYYY-MM-DD format"}. Set to null if not readable.'
            ]
        ]
    ]]
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $api_key,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    echo json_encode(['success' => false, 'error' => 'cURL error: ' . $curl_error]);
    exit;
}

$data = json_decode($response, true);

if ($http_code !== 200) {
    $msg = $data['error']['message'] ?? $response;
    echo json_encode(['success' => false, 'error' => 'API error: ' . $msg]);
    exit;
}

// Extract text from response
$raw_text = '';
foreach ($data['content'] ?? [] as $block) {
    if ($block['type'] === 'text') $raw_text .= $block['text'];
}

// Parse the JSON Claude returned
$clean = preg_replace('/```json|```/i', '', trim($raw_text));
$parsed = json_decode(trim($clean), true);

if (!$parsed) {
    // Try to extract JSON substring
    preg_match('/\{.*\}/s', $clean, $matches);
    $parsed = $matches ? json_decode($matches[0], true) : [];
}

echo json_encode([
    'success'     => true,
    'cheque_no'   => $parsed['cheque_no']   ?? null,
    'bank_code'   => $parsed['bank_code']   ?? null,
    'branch_code' => $parsed['branch_code'] ?? null,
    'cheque_date' => $parsed['cheque_date'] ?? null,
]);