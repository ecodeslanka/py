<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$m = row("SELECT * FROM materials WHERE id=? AND type='document'", [(int)($_GET['id'] ?? 0)]);
if (!$m || ($u['role'] !== 'admin' && !is_enrolled($u['id'], $m['lesson_id']))) { http_response_code(403); exit('Not allowed'); }
$path = ROOT . '/uploads/docs/' . basename((string)$m['file_path']);
if (!is_file($path)) { http_response_code(404); exit('File missing'); }
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . rawurlencode($m['file_name'] ?: ('material.' . $ext)) . '"');
readfile($path);
