<?php
/**
 * zlink_proxy.php — Server-side proxy for ZKBio Zlink API
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, token');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Only allow POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Allowed paths ────────────────────────────────────────────────────────────
$allowedPaths = [
    'user/login'             => 'https://zlink.minervaiot.com/api/v1/user/login',
    'cloudacc/event/list'    => 'https://zlink.minervaiot.com/api/v1/cloudacc/event/list',
    'acc/api/v1/event/query' => 'https://zlink.minervaiot.com/acc/api/v1/event/query',
];

$path = $_GET['path'] ?? '';

if (!isset($allowedPaths[$path])) {
    http_response_code(403);
    echo json_encode(['error' => 'Path not allowed: ' . $path]);
    exit;
}

$targetUrl = $allowedPaths[$path];

// ── Forward request body ─────────────────────────────────────────────────────
$body = file_get_contents('php://input');

// ── Forward relevant headers ─────────────────────────────────────────────────
$forwardHeaders = ['Content-Type: application/json'];

if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $forwardHeaders[] = 'Authorization: ' . $_SERVER['HTTP_AUTHORIZATION'];
}
if (!empty($_SERVER['HTTP_TOKEN'])) {
    $forwardHeaders[] = 'token: ' . $_SERVER['HTTP_TOKEN'];
}

// ── cURL request to Zlink ────────────────────────────────────────────────────
$ch = curl_init($targetUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => $forwardHeaders,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,   // disable if host has SSL cert issues
    CURLOPT_FOLLOWLOCATION => true,    // follow redirects
    CURLOPT_MAXREDIRS      => 3,
]);

$response   = curl_exec($ch);
$httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$finalUrl   = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
$curlError  = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['error' => 'Proxy cURL error: ' . $curlError]);
    exit;
}

// ── If response is not JSON, wrap it so browser doesn't break ────────────────
$decoded = json_decode($response, true);
if ($decoded === null) {
    // Zlink returned non-JSON (HTML error page etc.) — return it wrapped
    http_response_code(502);
    echo json_encode([
        'error'      => 'Zlink returned non-JSON response',
        'httpCode'   => $httpCode,
        'finalUrl'   => $finalUrl,
        'rawPreview' => substr($response, 0, 300),
    ]);
    exit;
}

http_response_code($httpCode);
echo $response;