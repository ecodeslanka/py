<?php
// Past paper / marking scheme download for logged-in users
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$p = row('SELECT * FROM papers WHERE id=?', [(int)($_GET['id'] ?? 0)]);
if (!$p || ($u['role'] !== 'admin' && $p['batch_id'] && $u['batch_id'] && (int)$p['batch_id'] !== (int)$u['batch_id'])) { http_response_code(403); exit('Not allowed'); }
$scheme = ($_GET['f'] ?? '') === 'scheme';
$file = $scheme ? $p['scheme_file'] : $p['paper_file']; $orig = $scheme ? $p['scheme_name'] : $p['paper_name'];
$path = ROOT . '/uploads/papers/' . basename((string)$file);
if (!$file || !is_file($path)) { http_response_code(404); exit('File missing'); }
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
$nice = preg_replace('/[^\w\-. ]+/u', '_', $p['year'] . ' ' . $p['name'] . ($scheme ? ' - Marking Scheme' : '')) . '.' . $ext;
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream')); header('Content-Length: ' . filesize($path));
header("Content-Disposition: attachment; filename=\"" . $nice . "\"; filename*=UTF-8''" . rawurlencode($nice)); readfile($path);
