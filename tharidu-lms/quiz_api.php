<?php
// AJAX: save an answer / submit an attempt
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/quiz.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
function out(array $a, int $c = 200): never { http_response_code($c); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
$u = me(); if (!$u) out(['ok' => false, 'error' => 'login'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(csrf(), (string)($_POST['_csrf'] ?? ''))) out(['ok' => false, 'error' => 'Session expired'], 419);
$a = row('SELECT * FROM quiz_attempts WHERE id=? AND user_id=?', [(int)($_POST['a'] ?? 0), $u['id']]); if (!$a) out(['ok' => false, 'error' => 'Not found'], 404);
$z = row('SELECT * FROM quizzes WHERE id=?', [$a['quiz_id']]);
if ($a['submitted_at']) out(['ok' => false, 'done' => true, 'url' => url('quiz_result?a=' . $a['id'])]);
$dl = quiz_deadline($z, $a); $late = $dl !== null && time() > $dl + 8;                  // 8 s grace for slow phones
$act = $_POST['act'] ?? '';
if ($act === 'submit') { quiz_finish((int)$a['id']); out(['ok' => true, 'url' => url('quiz_result?a=' . $a['id'])]); }
if ($act === 'answer') {
    if ($late) { quiz_finish((int)$a['id']); out(['ok' => false, 'expired' => true, 'url' => url('quiz_result?a=' . $a['id'])]); }
    $k = row('SELECT * FROM quiz_questions WHERE id=? AND quiz_id=?', [(int)($_POST['q'] ?? 0), $z['id']]); $c = (int)($_POST['chosen'] ?? -1);
    if (!$k || $c < 0 || $c >= count(quiz_options($k))) out(['ok' => false, 'error' => 'Bad answer'], 422);
    if ($z['mode'] === 'practice' && val('SELECT 1 FROM quiz_answers WHERE attempt_id=? AND question_id=?', [$a['id'], $k['id']])) out(['ok' => false, 'error' => 'Already answered'], 409);   // practice: first answer counts
    $ok = $c === (int)$k['correct'] ? 1 : 0;
    q('INSERT INTO quiz_answers(attempt_id,question_id,chosen,is_correct) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE chosen=VALUES(chosen),is_correct=VALUES(is_correct)', [$a['id'], $k['id'], $c, $ok]);
    $res = ['ok' => true];
    if ($z['mode'] === 'practice') $res += ['correct' => (int)$k['correct'], 'is_correct' => $ok, 'explanation' => $k['explanation']];
    out($res);
}
out(['ok' => false, 'error' => 'Bad request'], 400);
