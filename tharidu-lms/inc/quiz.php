<?php
// MCQ quizzes, past papers, Gemini paper scanner
require_once __DIR__ . '/ai.php';

const QUIZ_MODES = ['exam' => 'Exam (timed / scheduled)', 'practice' => 'Practice (instant answers, unlimited)'];
function quiz_options(array $q): array { $o = json_decode((string)$q['options'], true); return is_array($o) ? array_values($o) : []; }
function quiz_questions(int $qid): array { return rows('SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY sort_order,id', [$qid]); }

/** Is this student allowed to attempt now?  returns [bool, reason] */
function quiz_access(array $qz, array $u): array {
    if ($qz['status'] !== 'published') return [false, 'Not published'];
    $uid = (int)$u['id'];
    if ($qz['audience'] === 'batch' && (int)$u['batch_id'] !== (int)$qz['batch_id']) return [false, 'Not for your batch'];
    if ($qz['audience'] === 'lesson' && !is_enrolled($uid, (int)$qz['lesson_id'])) return [false, 'For students of a specific lesson'];
    if (!(int)val('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=?', [$qz['id']])) return [false, 'No questions yet'];
    if ($qz['mode'] === 'practice') return [true, 'Practice'];
    $now = time();
    if ($qz['open_at'] && strtotime($qz['open_at']) > $now) return [false, 'Opens ' . date('D d M, h:i A', strtotime($qz['open_at']))];
    if ($qz['close_at'] && strtotime($qz['close_at']) < $now) return [false, 'Closed ' . date('d M, h:i A', strtotime($qz['close_at']))];
    if ($qz['attempts'] > 0) {
        $done = (int)val('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=? AND user_id=? AND submitted_at IS NOT NULL', [$qz['id'], $uid]);
        if ($done >= $qz['attempts']) return [false, 'Attempt limit reached'];
    }
    return [true, 'Open'];
}
/** Quizzes visible to a student (audience filter in SQL). */
function quizzes_for(array $u): array {
    return rows("SELECT q.*,(SELECT COUNT(*) FROM quiz_questions k WHERE k.quiz_id=q.id) nq FROM quizzes q WHERE q.status='published'
        AND (q.audience='all' OR (q.audience='batch' AND q.batch_id=?) OR (q.audience='lesson' AND q.lesson_id IN (SELECT lesson_id FROM enrollments WHERE user_id=?)))
        ORDER BY COALESCE(q.open_at,q.created_at) DESC", [(int)$u['batch_id'], (int)$u['id']]);
}
function quiz_audience_label(array $q): string {
    if ($q['audience'] === 'batch') return 'Batch: ' . (val('SELECT name FROM batches WHERE id=?', [$q['batch_id']]) ?: '—');
    if ($q['audience'] === 'lesson') return 'Lesson: ' . (val('SELECT name FROM lessons WHERE id=?', [$q['lesson_id']]) ?: '—');
    return 'All students';
}
function quiz_students(array $q): array {   // students who can see the quiz
    if ($q['audience'] === 'batch') return rows("SELECT * FROM users WHERE role='student' AND active=1 AND batch_id=?", [$q['batch_id']]);
    if ($q['audience'] === 'lesson') return rows("SELECT u.* FROM users u JOIN enrollments e ON e.user_id=u.id WHERE u.active=1 AND e.lesson_id=?", [$q['lesson_id']]);
    return rows("SELECT * FROM users WHERE role='student' AND active=1");
}
function can_see_answers(array $qz, array $attempt): bool {
    if ($qz['mode'] === 'practice' || $qz['show_answers'] === 'after_submit') return true;
    if ($qz['show_answers'] === 'never') return false;
    return !$qz['close_at'] || strtotime($qz['close_at']) < time();
}
function quiz_deadline(array $qz, array $attempt): ?int {     // unix time when the attempt must end (null = none)
    $d = null;
    if ($qz['duration_min'] > 0) $d = strtotime($attempt['started_at']) + $qz['duration_min'] * 60;
    if ($qz['mode'] === 'exam' && $qz['close_at']) { $c = strtotime($qz['close_at']); $d = $d === null ? $c : min($d, $c); }
    return $d;
}
function quiz_finish(int $attemptId): void {
    $tot = (int)val('SELECT COUNT(*) FROM quiz_questions k JOIN quiz_attempts a ON a.quiz_id=k.quiz_id WHERE a.id=?', [$attemptId]);
    $sc = (int)val('SELECT COALESCE(SUM(is_correct),0) FROM quiz_answers WHERE attempt_id=?', [$attemptId]);
    q('UPDATE quiz_attempts SET submitted_at=COALESCE(submitted_at,NOW()),score=?,total=? WHERE id=? AND submitted_at IS NULL', [$sc, $tot, $attemptId]);
}

/* ---------------- Gemini: scan paper -> MCQs ---------------- */
/** Plain text of a .docx (paragraph per line). */
function docx_text(string $path): string {
    if (!class_exists('ZipArchive')) return '';
    $z = new ZipArchive(); if ($z->open($path) !== true) return '';
    $x = $z->getFromName('word/document.xml'); $z->close(); if (!$x) return '';
    $x = preg_replace('~</w:p>~', "\n", $x); $x = preg_replace('~<w:tab/>~', "\t", $x);
    return trim(html_entity_decode(strip_tags($x), ENT_QUOTES | ENT_XML1, 'UTF-8'));
}
/** Generic Gemini JSON call. $parts = Gemini parts. Returns [ok, array|string]. */
function gemini_json(array $parts, string $system, array $schema, int $maxTok = 8192): array {
    $key = dec(setting('gemini_key')); if ($key === '') return [false, 'Add your Gemini API key in Settings → AI Chatbot first.'];
    $model = preg_replace('/[^A-Za-z0-9._-]/', '', setting('gemini_model', 'gemini-2.5-flash')) ?: 'gemini-2.5-flash';
    $base = rtrim(setting('gemini_base', 'https://generativelanguage.googleapis.com'), '/');
    $body = ['system_instruction' => ['parts' => [['text' => $system]]], 'contents' => [['role' => 'user', 'parts' => $parts]],
        'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => $maxTok, 'responseMimeType' => 'application/json', 'responseSchema' => $schema]];
    @set_time_limit(280); @ignore_user_abort(true);
    $ch = curl_init("$base/v1beta/models/$model:generateContent");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 240, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key], CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    $res = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $j = json_decode((string)$res, true);
    if (!is_array($j)) return [false, 'Could not reach Gemini' . ($err ? " ($err)" : '') . '. Try a smaller file.'];
    if ($code >= 400) return [false, $code === 429 ? 'Gemini quota reached – try again in a minute.' : 'Gemini error: ' . mb_substr($j['error']['message'] ?? "HTTP $code", 0, 220)];
    $txt = ''; foreach ($j['candidates'][0]['content']['parts'] ?? [] as $p) $txt .= $p['text'] ?? '';
    $txt = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($txt)));
    $out = json_decode($txt, true);
    if (!is_array($out)) return [false, 'Gemini returned an unreadable answer (the paper may be too long – split it and try again).'];
    return [true, $out];
}
/** $files = [['path'=>..,'name'=>..,'role'=>'paper|scheme'], ...], $text = pasted text. Returns [ok, ['title'=>..,'questions'=>[...]] | error]. */
function quiz_ai_extract(array $files, string $text, int $maxQ = 60): array {
    $parts = []; $haveScheme = false; $n = 0;
    foreach ($files as $f) {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)); $label = ($f['role'] ?? 'paper') === 'scheme' ? 'MARKING SCHEME' : 'QUESTION PAPER';
        if ($label === 'MARKING SCHEME') $haveScheme = true;
        if ($ext === 'docx') { $t = docx_text($f['path']); if ($t === '') return [false, 'Could not read ' . $f['name'] . '. Save it as PDF and try again.']; $parts[] = ['text' => "[$label – text of {$f['name']}]\n" . mb_substr($t, 0, 60000)]; }
        elseif ($ext === 'doc') return [false, '.doc files cannot be read. Please save the file as .docx or PDF.'];
        else {
            $mime = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][$ext] ?? null; if (!$mime) return [false, 'Unsupported file type: ' . $ext];
            if (filesize($f['path']) > 18 * 1048576) return [false, $f['name'] . ' is too large (max 18MB).'];
            $parts[] = ['text' => "[$label: {$f['name']}]"]; $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode(file_get_contents($f['path']))]]; $n++;
        }
    }
    if (trim($text) !== '') $parts[] = ['text' => "[PASTED TEXT]\n" . mb_substr($text, 0, 60000)];
    if (!$parts) return [false, 'Upload a PDF / DOCX / image, or paste the questions.'];
    $parts[] = ['text' => "Extract up to $maxQ multiple-choice questions."];
    $sys = "You convert G.C.E. A/L chemistry (Sri Lanka) exam papers into digital MCQs.\n"
        . "- Read EVERY multiple-choice question in the provided material (Sinhala, English or mixed). Skip structured / essay questions.\n"
        . "- Keep the original language and wording. Write chemical formulas with Unicode subscripts/superscripts (H₂SO₄, Fe³⁺).\n"
        . "- `options`: the answer choices in order WITHOUT the labels (no \"1)\", \"A.\" prefixes); usually 4–5 options.\n"
        . "- `correct`: the 0-based index of the correct option. " . ($haveScheme ? "A MARKING SCHEME is supplied: take the answers from it (from_scheme=true, confidence=100). Only if a question is missing from the scheme, solve it yourself.\n" : "No marking scheme is supplied: solve each question carefully as an expert A/L chemistry teacher and give an honest `confidence` (0–100).\n")
        . "- `explanation`: one or two short sentences explaining why the answer is correct (in the paper's language, Sinhala if mixed).\n"
        . "- If a question depends on a diagram or table you cannot represent, describe it briefly in the question text in [brackets] and give confidence ≤ 40.\n"
        . "- `title`: a short title for the paper. Output ONLY JSON matching the schema.";
    $schema = ['type' => 'OBJECT', 'properties' => ['title' => ['type' => 'STRING'], 'questions' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
        'question' => ['type' => 'STRING'], 'options' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'correct' => ['type' => 'INTEGER'],
        'explanation' => ['type' => 'STRING'], 'confidence' => ['type' => 'INTEGER'], 'from_scheme' => ['type' => 'BOOLEAN']],
        'required' => ['question', 'options', 'correct']]]], 'required' => ['questions']];
    [$ok, $r] = gemini_json($parts, $sys, $schema, 16000);
    if (!$ok) return [false, $r];
    $out = [];
    foreach ($r['questions'] ?? [] as $q) {
        $opts = array_values(array_filter(array_map(fn($o) => trim((string)$o), (array)($q['options'] ?? [])), fn($o) => $o !== ''));
        $qt = trim((string)($q['question'] ?? '')); if ($qt === '' || count($opts) < 2) continue;
        $c = (int)($q['correct'] ?? -1); $conf = isset($q['confidence']) ? max(0, min(100, (int)$q['confidence'])) : 60;
        if ($c < 0 || $c >= count($opts)) { $c = 0; $conf = 0; }
        if (!empty($q['from_scheme'])) $conf = 100;
        $out[] = ['question' => $qt, 'options' => array_slice($opts, 0, 6), 'correct' => $c, 'explanation' => trim((string)($q['explanation'] ?? '')), 'conf' => $conf];
    }
    if (!$out) return [false, 'No multiple-choice questions were found in that file.'];
    return [true, ['title' => trim((string)($r['title'] ?? '')), 'questions' => $out]];
}
/** Appends extracted questions to a quiz. Questions the AI is not sure about are flagged for review. */
function quiz_add_extracted(int $quizId, array $questions): int {
    $so = (int)val('SELECT COALESCE(MAX(sort_order),0) FROM quiz_questions WHERE quiz_id=?', [$quizId]);
    foreach ($questions as $x) q('INSERT INTO quiz_questions(quiz_id,sort_order,question,options,correct,explanation,ai_conf,reviewed) VALUES(?,?,?,?,?,?,?,?)',
        [$quizId, ++$so, $x['question'], json_encode($x['options'], JSON_UNESCAPED_UNICODE), $x['correct'], $x['explanation'] ?: null, $x['conf'], $x['conf'] >= 90 ? 1 : 0]);
    return count($questions);
}
