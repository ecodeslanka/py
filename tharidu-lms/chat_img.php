<?php
// Serves a chat image to its owner or an admin
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$m = row("SELECT * FROM chat_messages WHERE id=? AND image IS NOT NULL", [(int)($_GET['id'] ?? 0)]);
if (!$m || ($u['role'] !== 'admin' && (int)$m['user_id'] !== (int)$u['id'])) { http_response_code(403); exit; }
$p = ROOT . '/uploads/chat/' . basename($m['image']); if (!is_file($p)) { http_response_code(404); exit; }
$ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
header('Content-Type: image/' . ($ext === 'jpg' ? 'jpeg' : $ext)); header('Cache-Control: private, max-age=86400'); readfile($p);
