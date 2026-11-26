<?php
// Serves payment slips to the owner student or any admin
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$p = row('SELECT * FROM payments WHERE id=?', [(int)($_GET['slip'] ?? 0)]);
if (!$p || !$p['slip'] || ($u['role'] !== 'admin' && (int)$p['user_id'] !== (int)$u['id'])) { http_response_code(403); exit('Not allowed'); }
$path = ROOT . '/uploads/slips/' . basename($p['slip']);
if (!is_file($path)) { http_response_code(404); exit('Missing'); }
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . ($ext === 'pdf' ? 'application/pdf' : 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext)));
header('Content-Disposition: inline; filename="slip.' . $ext . '"');
readfile($path);
