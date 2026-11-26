<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php'; require __DIR__ . '/../inc/zoom.php';
$u = require_admin();
$l = row('SELECT l.*,b.name bname,c.name cname FROM lessons l JOIN batches b ON b.id=l.batch_id LEFT JOIN classes c ON c.id=l.class_id WHERE l.id=?', [(int)($_GET['id'] ?? 0)]);
if (!$l) { flash('Lesson not found.', 'err'); redirect('admin/lessons'); }
$lid = (int)$l['id']; $tab = $_GET['tab'] ?? 'materials';
$back = 'admin/lesson?id=' . $lid . '&tab=';

if (is_post()) {
    $act = post('act');
    try {
        if ($act === 'mat_add') {
            $type = post('mtype'); $title = post('title'); if ($title === '') throw new RuntimeException('Title is required.');
            if ($type === 'youtube') {
                $link = post('yt'); if (!youtube_embed($link)) throw new RuntimeException('Enter a valid YouTube link.');
                q('INSERT INTO materials(lesson_id,type,title,description,url_enc,view_limit) VALUES(?,?,?,?,?,?)', [$lid, 'youtube', $title, post('description') ?: null, enc($link), max(0, (int)post('view_limit'))]);
            } elseif ($type === 'document') {
                $f = save_upload('doc', 'docs', ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'zip'], 25);
                if (!$f) throw new RuntimeException('Choose a document to upload.');
                q('INSERT INTO materials(lesson_id,type,title,description,file_path,file_name) VALUES(?,?,?,?,?,?)', [$lid, 'document', $title, post('description') ?: null, $f['name'], $f['orig']]);
            } else throw new RuntimeException('Choose a material type.');
            flash('Material added.'); redirect($back . 'materials');
        }
        if ($act === 'mat_del') {
            $m = row('SELECT * FROM materials WHERE id=? AND lesson_id=?', [(int)post('id'), $lid]);
            if ($m) { if ($m['type'] === 'document') del_upload('docs', $m['file_path']); q('DELETE FROM materials WHERE id=?', [$m['id']]); flash('Material deleted.'); }
            redirect($back . 'materials');
        }
        if ($act === 'mat_resetviews') { q('DELETE FROM material_views WHERE material_id=?', [(int)post('id')]); flash('Views reset for all students.'); redirect($back . 'materials'); }
        if ($act === 'enroll_add') {
            $sid = (int)post('student_id');
            if ($sid && !is_enrolled($sid, $lid)) { q('INSERT INTO enrollments(user_id,lesson_id) VALUES(?,?)', [$sid, $lid]); flash('Student added to lesson.'); }
            redirect($back . 'students');
        }
        if ($act === 'enroll_del') { q('DELETE FROM enrollments WHERE user_id=? AND lesson_id=?', [(int)post('student_id'), $lid]); flash('Student removed from this lesson.'); redirect($back . 'students'); }
        if ($act === 'live_add') {
            $kind = post('kind'); $title = post('title'); $start = post('start_at') ? date('Y-m-d H:i:s', strtotime(post('start_at'))) : null; $dur = max(5, (int)post('duration') ?: 60);
            if ($title === '') throw new RuntimeException('Title is required.');
            if ($kind === 'zoom_api') {
                $z = zoom_create_meeting($title, $start, $dur);
                q('INSERT INTO sessions_live(lesson_id,kind,title,start_at,duration_min,join_url_enc,start_url_enc,passcode) VALUES(?,?,?,?,?,?,?,?)',
                  [$lid, 'zoom_api', $title, $start, $dur, enc($z['join_url']), enc($z['start_url']), $z['password'] ?? null]);
                flash('Zoom meeting created.');
            } elseif ($kind === 'zoom_link' || $kind === 'youtube_live') {
                $link = post('link');
                if (!preg_match('~^https://~i', $link)) throw new RuntimeException('Enter a valid https:// link.');
                if ($kind === 'youtube_live' && !youtube_embed($link)) throw new RuntimeException('Enter a valid YouTube live link.');
                q('INSERT INTO sessions_live(lesson_id,kind,title,start_at,duration_min,join_url_enc,passcode) VALUES(?,?,?,?,?,?,?)', [$lid, $kind, $title, $start, $dur, enc($link), post('passcode') ?: null]);
                flash('Live session added.');
            } else throw new RuntimeException('Choose a session type.');
            redirect($back . 'live');
        }
        if ($act === 'live_del') {
            $s = row('SELECT * FROM sessions_live WHERE id=? AND lesson_id=?', [(int)post('id'), $lid]);
            if ($s) q('DELETE FROM sessions_live WHERE id=?', [$s['id']]); flash('Session deleted.'); redirect($back . 'live');
        }
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); redirect($back . $tab); }
}

admin_head($l['name'], 'lessons');
echo '<a class="muted small" href="' . url('admin/lessons') . '">← All lessons</a><br><br>';
$tabs = ['materials' => 'Materials', 'students' => 'Enrolled students', 'live' => 'Zoom & live'];
?>
<div class="toolbar"><div><span class="pill"><?= e($l['bname']) ?></span> <span class="pill"><?= e(month_label($l['month_year'])) ?></span> <span class="pill"><?= $l['class_type'] === 'online' ? 'Online' : e($l['cname'] ?: 'Physical') ?></span> <span class="pill"><?= e(money($l['fee'])) ?></span></div>
<a class="btn btn-o btn-sm" href="<?= url('admin/lessons?edit=' . $lid) ?>"><i data-lucide="pencil"></i>Edit lesson</a></div>
<div class="tabs"><?php foreach ($tabs as $k => $t): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="?id=<?= $lid ?>&tab=<?= $k ?>"><?= $t ?></a><?php endforeach; ?></div>

<?php if ($tab === 'materials'): $mats = rows('SELECT m.*,(SELECT COALESCE(SUM(views),0) FROM material_views v WHERE v.material_id=m.id) tv FROM materials m WHERE lesson_id=? ORDER BY id DESC', [$lid]); ?>
<div class="card"><h3><i data-lucide="plus-circle"></i>Add material</h3>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="act" value="mat_add">
<div class="seg"><label><input type="radio" name="mtype" value="youtube" data-toggle="mt" checked><i data-lucide="youtube"></i>YouTube recording</label>
<label><input type="radio" name="mtype" value="document" data-toggle="mt"><i data-lucide="file-text"></i>Document</label></div>
<?php field('Title', 'title', '', ['req' => 1]); ?>
<div data-show-for="mt:youtube"><div class="two"><?php field('YouTube link', 'yt', '', ['type' => 'url', 'req' => 1, 'hint' => 'Stored encrypted. Students never see the raw link.', 'attr' => 'placeholder="https://www.youtube.com/watch?v=..."']);
field('View count per student (optional)', 'view_limit', '', ['type' => 'number', 'attr' => 'min="0" placeholder="Unlimited"', 'hint' => 'Leave empty/0 for unlimited views']); ?></div></div>
<div data-show-for="mt:document" hidden><label class="f">Document file<span class="hint">PDF, Word, PowerPoint, Excel, image, ZIP – max 25MB</span><input type="file" name="doc"></label></div>
<?php field('Description (optional)', 'description', '', ['type' => 'textarea', 'rows' => 3]); ?>
<button class="btn btn-r">Add material</button></form></div><br>
<div class="card"><h3><i data-lucide="library"></i>Materials (<?= count($mats) ?>)</h3>
<?php if (!$mats) echo '<p class="empty">No materials yet.</p>'; foreach ($mats as $m): ?>
<div class="matitem"><span class="ic <?= $m['type'] === 'youtube' ? 'yt' : 'doc' ?>"><i data-lucide="<?= $m['type'] === 'youtube' ? 'play' : 'file-text' ?>"></i></span>
<div class="grow"><b><?= e($m['title']) ?></b><div class="small muted"><?= $m['type'] === 'youtube' ? 'YouTube · encrypted · ' . ($m['view_limit'] ? (int)$m['view_limit'] . ' views/student' : 'unlimited views') . ' · ' . (int)$m['tv'] . ' total views' : e($m['file_name']) ?></div>
<?php if ($m['description']): ?><div class="small muted"><?= e($m['description']) ?></div><?php endif; ?></div>
<div class="acts"><?php if ($m['type'] === 'document'): ?><a class="btn btn-o btn-sm" href="<?= url('download?id=' . $m['id']) ?>">Open</a><?php endif; ?>
<?php if ($m['type'] === 'youtube' && $m['view_limit']): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="mat_resetviews"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="btn btn-o btn-sm" data-confirm="Reset view counts for all students?">Reset views</button></form><?php endif; ?>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="mat_del"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Delete this material?">Delete</button></form></div></div>
<?php endforeach; ?></div>

<?php elseif ($tab === 'students'):
  $sts = rows('SELECT u.*,e.created_at joined,(SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.user_id=u.id AND p.lesson_id=? AND p.status="approved") paid FROM enrollments e JOIN users u ON u.id=e.user_id WHERE e.lesson_id=? ORDER BY u.name', [$lid, $lid]);
  $others = rows('SELECT id,name,mobile FROM users WHERE role="student" AND id NOT IN (SELECT user_id FROM enrollments WHERE lesson_id=?) ORDER BY name', [$lid]); ?>
<div class="card"><h3><i data-lucide="user-plus"></i>Add a student manually</h3>
<form method="post" class="two"><?= csrf_field() ?><input type="hidden" name="act" value="enroll_add">
<label class="f">Student<select name="student_id" required><option value="">— select —</option><?php foreach ($others as $o) echo '<option value="' . $o['id'] . '">' . e($o['name']) . ' (' . e(show_mobile($o['mobile'])) . ')</option>'; ?></select></label>
<div style="align-self:end;margin-bottom:14px"><button class="btn btn-r">Add to lesson</button></div></form></div><br>
<div class="tablewrap"><table><tr><th>Student</th><th>Mobile</th><th>NIC</th><th>Paid</th><th>Joined</th><th></th></tr>
<?php foreach ($sts as $s): ?><tr><td><b><?= e($s['name']) ?></b><?= $s['active'] ? '' : ' <span class="pill err">disabled</span>' ?></td><td><?= e(show_mobile($s['mobile'])) ?></td><td><?= e($s['nic'] ?: '—') ?></td><td><?= e(money($s['paid'])) ?></td><td><?= e(date('d M Y', strtotime($s['joined']))) ?></td>
<td><div class="acts"><a class="btn btn-b btn-sm" href="<?= url('admin/student?id=' . $s['id']) ?>">View / edit</a><a class="btn btn-o btn-sm" href="<?= url('admin/student?id=' . $s['id'] . '#pw') ?>">Change password</a>
<a class="btn btn-o btn-sm" href="<?= url('admin/student?id=' . $s['id'] . '#pay') ?>">Payments</a>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="enroll_del"><input type="hidden" name="student_id" value="<?= $s['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Remove this student from the lesson?">Remove</button></form></div></td></tr>
<?php endforeach; if (!$sts) echo '<tr><td colspan="6" class="empty">No students enrolled yet.</td></tr>'; ?></table></div>

<?php else: $live = rows('SELECT * FROM sessions_live WHERE lesson_id=? ORDER BY COALESCE(start_at,created_at) DESC', [$lid]); ?>
<div class="card"><h3><i data-lucide="video"></i>Add live session</h3>
<?php if (!zoom_configured()): ?><div class="alert err small">Zoom API is not configured – "Create Zoom meeting" needs the keys in <a href="<?= url('admin/settings#zoom') ?>"><u>Site settings → Zoom</u></a>. You can still paste a Zoom or YouTube live link.</div><?php endif; ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="live_add">
<label class="f">Session type<select name="kind" data-toggle="lk">
<option value="zoom_api">Create Zoom meeting automatically</option><option value="zoom_link">Upload / paste a Zoom link</option><option value="youtube_live">YouTube live link</option></select></label>
<div class="two"><?php field('Title', 'title', '', ['req' => 1, 'attr' => 'placeholder="Week 1 – Atomic structure"']); field('Start date & time', 'start_at', '', ['type' => 'datetime-local']); ?></div>
<div class="two"><?php field('Duration (minutes)', 'duration', '60', ['type' => 'number', 'attr' => 'min="5" max="1440"']); ?>
<div data-show-for="lk:zoom_link,youtube_live" hidden><?php field('Passcode (optional)', 'passcode', ''); ?></div></div>
<div data-show-for="lk:zoom_link,youtube_live" hidden><?php field('Link', 'link', '', ['type' => 'url', 'req' => 1, 'hint' => 'Stored encrypted', 'attr' => 'placeholder="https://..."']); ?></div>
<button class="btn btn-r">Create / add</button></form></div><br>
<div class="card"><h3><i data-lucide="calendar"></i>Sessions (<?= count($live) ?>)</h3>
<?php if (!$live) echo '<p class="empty">No live sessions yet.</p>'; foreach ($live as $s): ?>
<div class="matitem"><span class="ic <?= $s['kind'] === 'youtube_live' ? 'yt' : 'doc' ?>"><i data-lucide="<?= $s['kind'] === 'youtube_live' ? 'youtube' : 'video' ?>"></i></span>
<div class="grow"><b><?= e($s['title']) ?></b><div class="small muted"><?= ['zoom_api' => 'Zoom (auto-created)', 'zoom_link' => 'Zoom link', 'youtube_live' => 'YouTube live'][$s['kind']] ?> · <?= $s['start_at'] ? e(date('d M Y h:i A', strtotime($s['start_at']))) : 'no time' ?> · <?= (int)$s['duration_min'] ?> min<?= $s['passcode'] ? ' · passcode ' . e($s['passcode']) : '' ?></div></div>
<div class="acts"><?php if ($s['kind'] === 'zoom_api' && $s['start_url_enc']): ?><a class="btn btn-b btn-sm" href="<?= url('admin/zoomstart?id=' . $s['id']) ?>" target="_blank">Start as host</a><?php endif; ?>
<a class="btn btn-o btn-sm" href="<?= url('live?id=' . $s['id']) ?>" target="_blank">Join link</a>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="live_del"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn btn-danger btn-sm" data-confirm="Delete this session?">Delete</button></form></div></div>
<?php endforeach; ?></div>
<?php endif; ?>
<?php admin_foot();
