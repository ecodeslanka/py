<?php
// Serves a question / option picture (admins always; students only for published quizzes)
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/quiz.php';
$u = require_login(); $f = basename((string)($_GET['f'] ?? ''));
if (!preg_match(QIMG_RE, $f) || !is_file(quiz_img_path($f))) { http_response_code(404); exit; }
$r = row('SELECT z.status FROM quiz_questions k JOIN quizzes z ON z.id=k.quiz_id WHERE k.q_image=? OR k.opt_images LIKE ? LIMIT 1', [$f, '%"' . $f . '"%']);
if (!$r || ($u['role'] !== 'admin' && $r['status'] !== 'published')) { http_response_code(403); exit; }
$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
header('Content-Type: image/' . ($ext === 'jpg' ? 'jpeg' : $ext)); header('Cache-Control: private, max-age=86400'); header('X-Content-Type-Options: nosniff'); readfile(quiz_img_path($f));
