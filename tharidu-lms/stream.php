<?php
// One-time token -> obfuscated video id for ECODES PLAYER (POST only, login required)
require __DIR__ . '/inc/bootstrap.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !me()) { http_response_code(403); exit('{"ok":false}'); }
$t = (string)($_POST['t'] ?? '');
$e = $_SESSION['pt'][$t] ?? null; unset($_SESSION['pt'][$t]);           // single use
if (!$e || $e['exp'] < time()) { http_response_code(410); exit('{"ok":false}'); }
$v = $e['v']; $out = '';
for ($i = 0; $i < strlen($v); $i++) $out .= chr(ord($v[$i]) ^ ord($t[$i % strlen($t)]));
echo json_encode(['ok' => true, 'd' => base64_encode($out)]);
