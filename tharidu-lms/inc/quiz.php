<?php
// MCQ quizzes, past papers, Gemini paper scanner
require_once __DIR__ . '/ai.php';

const QUIZ_MODES = ['exam' => 'Exam (timed / scheduled)', 'practice' => 'Practice (instant answers, unlimited)'];
function quiz_options(array $q): array { $o = json_decode((string)$q['options'], true); return is_array($o) ? array_values($o) : []; }
function quiz_questions(int $qid): array { return rows('SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY sort_order,id', [$qid]); }

/* ---------------- pictures on questions / options ---------------- */
const QIMG_RE = '/^[a-f0-9]{24}\.(jpg|jpeg|png|webp|gif)$/';
function quiz_opt_images(array $q): array {     // aligned with quiz_options(); null = no picture
    $n = count(quiz_options($q)); $im = json_decode((string)($q['opt_images'] ?? ''), true); $im = is_array($im) ? array_values($im) : [];
    $out = []; for ($i = 0; $i < $n; $i++) $out[] = (isset($im[$i]) && is_string($im[$i]) && preg_match(QIMG_RE, $im[$i])) ? $im[$i] : null; return $out;
}
function quiz_img_url(?string $n): ?string { return $n ? url('qimg?f=' . $n) : null; }
function quiz_img_path(string $n): string { return ROOT . '/uploads/quizimg/' . basename($n); }
/** Normalises $_FILES['field'] or $_FILES['field'][idx] into a single file array (or null when nothing was chosen). */
function post_file(string $field, ?int $idx = null): ?array {
    if (empty($_FILES[$field])) return null; $f = $_FILES[$field];
    if ($idx !== null) { if (!is_array($f['name'] ?? null) || !isset($f['name'][$idx])) return null; $f = ['name' => $f['name'][$idx], 'tmp_name' => $f['tmp_name'][$idx], 'error' => $f['error'][$idx], 'size' => $f['size'][$idx]]; }
    return ($f['error'] === UPLOAD_ERR_NO_FILE || $f['name'] === '') ? null : $f;
}
/** Validates + stores a picture (shrunk to max 1600px). Returns the stored file name. */
function quiz_img_save(array $f, int $maxMb = 6): string {
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Picture upload failed (error ' . $f['error'] . ').');
    if ($f['size'] > $maxMb * 1048576) throw new RuntimeException("Picture too large (max {$maxMb}MB).");
    $info = @getimagesize($f['tmp_name']); $mime = $info['mime'] ?? '';
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null; if (!$ext) throw new RuntimeException('Pictures must be JPG, PNG, WEBP or GIF.');
    $dir = ROOT . '/uploads/quizimg'; if (!is_dir($dir)) mkdir($dir, 0755, true); $name = bin2hex(random_bytes(12)) . '.' . $ext; $dst = "$dir/$name";
    if ($info[0] > 1600 && function_exists('imagecreatefromstring') && $ext !== 'gif') {
        $im = @imagecreatefromstring(file_get_contents($f['tmp_name']));
        if ($im) { $nw = 1600; $nh = (int)round($info[1] * $nw / $info[0]); $d = imagecreatetruecolor($nw, $nh);
            if ($ext === 'png' || $ext === 'webp') { imagealphablending($d, false); imagesavealpha($d, true); }
            imagecopyresampled($d, $im, 0, 0, 0, 0, $nw, $nh, $info[0], $info[1]);
            $ok = $ext === 'png' ? imagepng($d, $dst, 6) : ($ext === 'webp' && function_exists('imagewebp') ? imagewebp($d, $dst, 85) : imagejpeg($d, $dst, 86));
            if ($ok) return $name; }
    }
    if (!move_uploaded_file($f['tmp_name'], $dst)) { if (!@copy($f['tmp_name'], $dst)) throw new RuntimeException('Could not save the picture.'); }
    return $name;
}
/** Deletes picture files that no question uses any more. */
function quiz_img_gc(array $names): void {
    foreach (array_unique(array_filter($names)) as $n) {
        if (!preg_match(QIMG_RE, (string)$n)) continue;
        if (!val('SELECT 1 FROM quiz_questions WHERE q_image=? OR opt_images LIKE ? LIMIT 1', [$n, '%"' . $n . '"%'])) @unlink(quiz_img_path($n));
    }
}
function quiz_question_images(array $k): array { return array_merge([$k['q_image'] ?? null], quiz_opt_images($k)); }
/** Crops a figure out of a page image. $box = [ymin,xmin,ymax,xmax] on a 0–1000 scale. Returns the stored file name or null. */
function quiz_crop(string $page, $box): ?string {
    if (!is_array($box) || count($box) !== 4 || !function_exists('imagecreatefromjpeg')) return null;
    [$y1, $x1, $y2, $x2] = array_map(fn($v) => max(0, min(1000, (float)$v)), array_values($box)); if ($y2 < $y1) [$y1, $y2] = [$y2, $y1]; if ($x2 < $x1) [$x1, $x2] = [$x2, $x1];
    if (($x2 - $x1) < 18 || ($y2 - $y1) < 14 || (($x2 - $x1) * ($y2 - $y1)) > 900000) return null;       // too small, or basically the whole page
    $im = @imagecreatefromjpeg($page); if (!$im) return null; $W = imagesx($im); $H = imagesy($im); $pad = 10;
    $px1 = (int)floor(max(0, $x1 - $pad) / 1000 * $W); $px2 = (int)ceil(min(1000, $x2 + $pad) / 1000 * $W); $py1 = (int)floor(max(0, $y1 - $pad) / 1000 * $H); $py2 = (int)ceil(min(1000, $y2 + $pad) / 1000 * $H);
    $w = $px2 - $px1; $h = $py2 - $py1; if ($w < 24 || $h < 20) return null;
    $c = imagecreatetruecolor($w, $h); imagecopy($c, $im, 0, 0, $px1, $py1, $w, $h);
    $dir = ROOT . '/uploads/quizimg'; if (!is_dir($dir)) mkdir($dir, 0755, true); $name = bin2hex(random_bytes(12)) . '.jpg';
    return imagejpeg($c, "$dir/$name", 88) ? $name : null;
}


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
/** $files = [['path'=>..,'name'=>..,'role'=>'paper|scheme'], ...], $text = pasted text, $pages = page-image paths (JPEG, 1..n) rendered in the browser.
 *  With $pages Gemini also returns a bounding box for every diagram / structure / graph so it can be cropped out. Returns [ok, ['title'=>..,'questions'=>[...]] | error]. */
function quiz_ai_extract(array $files, string $text, int $maxQ = 60, array $pages = []): array {
    $parts = []; $haveScheme = false; $nPages = count($pages);
    foreach ($pages as $i => $pg) { $parts[] = ['text' => '[QUESTION PAPER – PAGE ' . ($i + 1) . ']']; $parts[] = ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64_encode(file_get_contents($pg))]]; }
    foreach ($files as $f) {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)); $label = ($f['role'] ?? 'paper') === 'scheme' ? 'MARKING SCHEME' : 'QUESTION PAPER';
        if ($label === 'MARKING SCHEME') $haveScheme = true;
        if ($ext === 'docx') { $t = docx_text($f['path']); if ($t === '') return [false, 'Could not read ' . $f['name'] . '. Save it as PDF and try again.']; $parts[] = ['text' => "[$label – text of {$f['name']}]\n" . mb_substr($t, 0, 60000)]; }
        elseif ($ext === 'doc') return [false, '.doc files cannot be read. Please save the file as .docx or PDF.'];
        else {
            $mime = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][$ext] ?? null; if (!$mime) return [false, 'Unsupported file type: ' . $ext];
            if (filesize($f['path']) > 18 * 1048576) return [false, $f['name'] . ' is too large (max 18MB).'];
            $parts[] = ['text' => "[$label: {$f['name']}]"]; $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode(file_get_contents($f['path']))]];
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
        . "- `explanation`: one or two short sentences explaining why the answer is correct (in the paper's language, Sinhala if mixed).\n";
    if ($nPages) $sys .= "- FIGURES: the paper is given as page images (PAGE 1..$nPages). For every question (or answer option) that contains a diagram, chemical structure, graph, apparatus drawing, circuit or table-as-a-picture that is needed to answer it, return the bounding box of ONLY that picture (not the question text): `page` (1-based) and `box` = [ymin, xmin, ymax, xmax] normalised to 0–1000 of that page. `q_figure` = the picture that belongs to the question stem (page 0 and box [0,0,0,0] when there is none). `option_figures` = one entry per option, same order as `options` (page 0 and box [0,0,0,0] when that option has no picture). If an option is ONLY a picture/structure, set its text to \"[figure]\". Plain-text questions must have no figures. Do not describe the figure in the question text.\n"
        . "- If a question depends on a picture you cannot box, mention [diagram] in the question text and give confidence ≤ 40.\n"
        . "- `title`: a short title for the paper. Output ONLY JSON matching the schema.";
    else $sys .= "- If a question depends on a diagram or table you cannot represent, describe it briefly in the question text in [brackets] and give confidence ≤ 40.\n- `title`: a short title for the paper. Output ONLY JSON matching the schema.";
    $figSchema = ['type' => 'OBJECT', 'properties' => ['page' => ['type' => 'INTEGER'], 'box' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']]]];
    $qProps = ['question' => ['type' => 'STRING'], 'options' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'correct' => ['type' => 'INTEGER'],
        'explanation' => ['type' => 'STRING'], 'confidence' => ['type' => 'INTEGER'], 'from_scheme' => ['type' => 'BOOLEAN']];
    if ($nPages) $qProps += ['q_figure' => $figSchema, 'option_figures' => ['type' => 'ARRAY', 'items' => $figSchema]];
    $schema = ['type' => 'OBJECT', 'properties' => ['title' => ['type' => 'STRING'], 'questions' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => $qProps, 'required' => ['question', 'options', 'correct']]]], 'required' => ['questions']];
    [$ok, $r] = gemini_json($parts, $sys, $schema, 24000);
    if (!$ok) return [false, $r];
    $fig = function ($f) use ($nPages) { if (!is_array($f)) return null; $p = (int)($f['page'] ?? 0); $b = $f['box'] ?? null; if ($p < 1 || $p > $nPages || !is_array($b) || count($b) !== 4 || !array_sum(array_map('abs', $b))) return null; return ['page' => $p, 'box' => array_values($b)]; };
    $out = [];
    foreach ($r['questions'] ?? [] as $q) {
        $qt = trim((string)($q['question'] ?? '')); $rawOpts = array_values((array)($q['options'] ?? [])); $ofRaw = array_values((array)($q['option_figures'] ?? []));
        $opts = []; $ofigs = []; $map = [];
        foreach ($rawOpts as $i => $o) { $t = trim((string)$o); $f = $nPages ? $fig($ofRaw[$i] ?? null) : null; if ($t === '' && $f) $t = '[figure]'; if ($t === '') continue; $map[$i] = count($opts); $opts[] = $t; $ofigs[] = $f; }
        if (($qt === '' && !($nPages && $fig($q['q_figure'] ?? null))) || count($opts) < 2) continue;
        if ($qt === '') $qt = '(see the figure)';
        $c = (int)($q['correct'] ?? -1); $conf = isset($q['confidence']) ? max(0, min(100, (int)$q['confidence'])) : 60;
        if (!isset($map[$c])) { $c = 0; $conf = 0; } else $c = $map[$c];
        if (!empty($q['from_scheme'])) $conf = 100;
        $keep = array_slice($opts, 0, 6);
        $out[] = ['question' => $qt, 'options' => $keep, 'correct' => min($c, count($keep) - 1), 'explanation' => trim((string)($q['explanation'] ?? '')), 'conf' => $conf,
                  'qfig' => $nPages ? $fig($q['q_figure'] ?? null) : null, 'ofigs' => array_slice($ofigs, 0, 6)];
    }
    if (!$out) return [false, 'No multiple-choice questions were found in that file.'];
    return [true, ['title' => trim((string)($r['title'] ?? '')), 'questions' => $out]];
}
/** Appends extracted questions to a quiz (cropping figures from the page images). Questions the AI is not sure about are flagged for review. Returns [count, figuresCropped]. */
function quiz_add_extracted(int $quizId, array $questions, array $pages = []): array {
    $so = (int)val('SELECT COALESCE(MAX(sort_order),0) FROM quiz_questions WHERE quiz_id=?', [$quizId]); $figs = 0;
    foreach ($questions as $x) {
        $qimg = null; $oimg = []; $opts = $x['options'];
        if (!empty($x['qfig']) && isset($pages[$x['qfig']['page'] - 1])) { $qimg = quiz_crop($pages[$x['qfig']['page'] - 1], $x['qfig']['box']); if ($qimg) $figs++; }
        foreach ($opts as $i => $t) {
            $f = $x['ofigs'][$i] ?? null; $im = ($f && isset($pages[$f['page'] - 1])) ? quiz_crop($pages[$f['page'] - 1], $f['box']) : null;
            if ($im) { $figs++; if ($t === '[figure]') $opts[$i] = ''; }
            $oimg[] = $im;
        }
        if ($qimg === null && $x['question'] === '(see the figure)') $x['conf'] = min($x['conf'], 30);
        $hasImg = array_filter($oimg);
        q('INSERT INTO quiz_questions(quiz_id,sort_order,question,options,correct,explanation,q_image,opt_images,ai_conf,reviewed) VALUES(?,?,?,?,?,?,?,?,?,?)',
          [$quizId, ++$so, $x['question'], json_encode($opts, JSON_UNESCAPED_UNICODE), $x['correct'], $x['explanation'] ?: null, $qimg, $hasImg ? json_encode($oimg) : null, $x['conf'], $x['conf'] >= 90 ? 1 : 0]);
    }
    return [count($questions), $figs];
}
