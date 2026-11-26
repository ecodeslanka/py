<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php'; require __DIR__ . '/../inc/zoom.php';
require_admin();
$l = lesson_ctx(); $lid = (int)$l['id'];
if (is_post()) {
    try {
        $kind = post('kind'); $title = post('title'); $start = post('start_at') ? date('Y-m-d H:i:s', strtotime(post('start_at'))) : null; $dur = max(5, (int)post('duration') ?: 60);
        if ($title === '') throw new RuntimeException('Title is required.');
        if ($kind === 'zoom_api') {
            $z = zoom_create_meeting($title, $start, $dur);
            q('INSERT INTO sessions_live(lesson_id,kind,title,start_at,duration_min,join_url_enc,start_url_enc,passcode) VALUES(?,?,?,?,?,?,?,?)', [$lid, 'zoom_api', $title, $start, $dur, enc($z['join_url']), enc($z['start_url']), $z['password'] ?? null]);
            flash('Zoom meeting created.');
        } elseif ($kind === 'zoom_link' || $kind === 'youtube_live') {
            $link = post('link');
            if (!preg_match('~^https://~i', $link)) throw new RuntimeException('Enter a valid https:// link.');
            if ($kind === 'youtube_live' && !youtube_embed($link)) throw new RuntimeException('Enter a valid YouTube live link.');
            q('INSERT INTO sessions_live(lesson_id,kind,title,start_at,duration_min,join_url_enc,passcode) VALUES(?,?,?,?,?,?,?)', [$lid, $kind, $title, $start, $dur, enc($link), post('passcode') ?: null]);
            flash('Live session added.');
        } else throw new RuntimeException('Choose a session type.');
        redirect('admin/sessions?lesson=' . $lid);
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); redirect('admin/session-add?lesson=' . $lid); }
}
admin_head('Add live session', 'lessons');
lesson_banner($l, 'Add session');
?>
<div class="card formcard">
<?php if (!zoom_configured()): ?><div class="alert err small">Zoom API is not configured – “Create Zoom meeting” needs the keys in <a href="<?= url('admin/settings#zoom') ?>"><u>Site settings → Zoom</u></a>. You can still paste a Zoom or YouTube live link.</div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<label class="f">Session type<select name="kind" data-toggle="lk">
<option value="zoom_api">Create Zoom meeting automatically</option><option value="zoom_link">Upload / paste a Zoom link</option><option value="youtube_live">YouTube live link</option></select></label>
<?php field('Title', 'title', '', ['req' => 1, 'attr' => 'placeholder="Week 1 – Atomic structure"']); ?>
<div class="two"><?php field('Start date & time', 'start_at', '', ['type' => 'datetime-local']); field('Duration (minutes)', 'duration', '60', ['type' => 'number', 'attr' => 'min="5" max="1440"']); ?></div>
<div data-show-for="lk:zoom_link,youtube_live" hidden><?php field('Link', 'link', '', ['type' => 'url', 'req' => 1, 'hint' => 'Stored encrypted', 'attr' => 'placeholder="https://..."']); field('Passcode (optional)', 'passcode', ''); ?></div>
<button class="btn">Create / add</button> <a class="btn btn-o" href="<?= url('admin/sessions?lesson=' . $lid) ?>">Cancel</a></form></div>
<?php admin_foot();
