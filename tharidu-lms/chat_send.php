<?php
// AJAX endpoint: student question (+ optional image) -> Gemini -> answer
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/ai.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
function out(array $a, int $code = 200): never { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
$u = me();
if (!$u) out(['ok' => false, 'error' => 'Please log in again.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(csrf(), (string)($_POST['_csrf'] ?? ''))) out(['ok' => false, 'error' => 'Session expired. Reload the page.'], 419);
if (!bot_enabled()) out(['ok' => false, 'error' => 'The AI assistant is currently switched off.'], 503);
$uid = (int)$u['id']; $isAdmin = $u['role'] === 'admin'; $limit = bot_limit();
$used = bot_used_today($uid);
if (!$isAdmin && $used >= $limit) out(['ok' => false, 'limit' => true, 'left' => 0, 'error' => "ඔබගේ අද දින ප්‍රශ්න සීමාව ($limit) අවසන්. හෙට නැවත උත්සාහ කරන්න. / Daily limit of $limit questions reached. Try again tomorrow."], 429);
$last = val("SELECT created_at FROM chat_messages WHERE user_id=? AND role='user' ORDER BY id DESC LIMIT 1", [$uid]);
if ($last && time() - strtotime($last) < 3) out(['ok' => false, 'error' => 'Please wait a few seconds before the next question.'], 429);
$msg = trim((string)($_POST['message'] ?? '')); $lang = ($_POST['lang'] ?? 'si') === 'en' ? 'en' : 'si';
if (mb_strlen($msg) > 1500) out(['ok' => false, 'error' => 'Question is too long (max 1500 characters).'], 422);
$img = null;
try { if (setting('bot_images', '1') === '1') $img = chat_image_upload(); } catch (Throwable $ex) { out(['ok' => false, 'error' => $ex->getMessage()], 422); }
if ($msg === '' && !$img) out(['ok' => false, 'error' => 'Type a question or attach an image.'], 422);
$hist = array_reverse(rows("SELECT role,content FROM chat_messages WHERE user_id=? ORDER BY id DESC LIMIT " . max(0, min(12, (int)setting('bot_history', '6'))), [$uid]));
[$ok, $text] = gemini_ask($msg, $img, $hist, $lang);
if (!$ok) { if ($img) @unlink(ROOT . '/uploads/chat/' . $img['name']); out(['ok' => false, 'error' => $text], 502); }
q("INSERT INTO chat_messages(user_id,role,content,image,lang) VALUES(?,'user',?,?,?)", [$uid, $msg !== '' ? $msg : '(image)', $img['name'] ?? null, $lang]);
q("INSERT INTO chat_messages(user_id,role,content,lang) VALUES(?,'bot',?,?)", [$uid, $text, $lang]);
$mid = (int)db()->lastInsertId();
out(['ok' => true, 'html' => chat_format($text), 'left' => $isAdmin ? null : max(0, $limit - $used - 1), 'limit' => $limit]);
