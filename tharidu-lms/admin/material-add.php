<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php'; require __DIR__ . '/../inc/player.php';
require_admin();
$l = lesson_ctx(); $lid = (int)$l['id'];
if (is_post()) {
    try {
        $type = post('mtype'); $title = post('title');
        if ($title === '') throw new RuntimeException('Title is required.');
        if (!isset(MATERIAL_TYPES[$type])) throw new RuntimeException('Choose a material type.');
        $link = post('link'); $url_enc = null; $file = null; $orig = null; $vl = 0; $pass = null;
        if ($type === 'youtube') {
            $yid = youtube_id($link); if (!$yid) throw new RuntimeException('Enter a valid YouTube link or video ID.');
            $url_enc = enc($yid); $vl = max(0, (int)post('view_limit'));
        } elseif (in_array($type, ['gdrive', 'assessment', 'zoom_rec'], true)) {
            if (!preg_match('~^https://~i', $link)) throw new RuntimeException('Enter a valid https:// link.');
            $url_enc = enc($link); if ($type === 'zoom_rec') $pass = post('passcode') ?: null;
        } elseif ($type === 'document') {
            $f = save_upload('doc', 'docs', ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'zip'], 25); if (!$f) throw new RuntimeException('Choose a file to upload.');
            $file = $f['name']; $orig = $f['orig'];
        } elseif ($type === 'notice') {
            $f = save_upload('doc', 'docs', ['jpg', 'jpeg', 'png', 'webp', 'gif'], 10); if (!$f) throw new RuntimeException('Choose an image to upload.');
            $file = $f['name']; $orig = $f['orig'];
        }
        $so = (int)val('SELECT COALESCE(MAX(sort_order),0)+1 FROM materials WHERE lesson_id=? AND type=?', [$lid, $type]);
        q('INSERT INTO materials(lesson_id,type,title,description,url_enc,file_path,file_name,view_limit,group_name,sort_order,passcode) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
          [$lid, $type, $title, post('description') ?: null, $url_enc, $file, $orig, $vl, post('group_name') ?: null, $so, $pass]);
        flash('Material added.'); redirect('admin/materials?lesson=' . $lid . '&tab=' . $type);
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); redirect('admin/material-add?lesson=' . $lid); }
}
admin_head('Add learning materials', 'lessons');
lesson_banner($l, 'Add material');
$groups = array_column(rows('SELECT DISTINCT group_name FROM materials WHERE lesson_id=? AND group_name IS NOT NULL ORDER BY group_name', [$lid]), 'group_name');
?>
<div class="card formcard"><form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
<?php field('Material title', 'title', '', ['req' => 1, 'attr' => 'placeholder="Enter material title"']); ?>
<label class="f">Material type<select name="mtype" required data-toggle="mt"><option value="" disabled selected>Choose type</option>
<?php foreach (MATERIAL_TYPES as $k => [$lab]) echo '<option value="' . $k . '">' . e($lab) . '</option>'; ?></select></label>
<div data-show-for="mt:youtube,gdrive,assessment,zoom_rec" hidden><?php field('Link / video code', 'link', '', ['req' => 1, 'hint' => 'YouTube link or video ID · Drive / assessment / Zoom recording https:// link. Stored encrypted.', 'attr' => 'placeholder="https://..."']); ?></div>
<?php field('Short description (optional)', 'description', '', ['type' => 'textarea', 'rows' => 3]); ?>
<div data-show-for="mt:youtube" hidden><?php field('View count per student (optional)', 'view_limit', '', ['type' => 'number', 'attr' => 'min="0" placeholder="Optional view count"', 'hint' => 'Empty / 0 = unlimited views']); ?></div>
<div data-show-for="mt:zoom_rec" hidden><?php field('Passcode (optional)', 'passcode', ''); ?></div>
<label class="f">Group name<span class="hint">Materials with the same group are shown together to students.</span><input name="group_name" list="grp" placeholder="Group Name"><datalist id="grp"><?php foreach ($groups as $g) echo '<option value="' . e($g) . '">'; ?></datalist></label>
<div data-show-for="mt:document,notice" hidden><label class="f">File<span class="hint">PDF Notes: PDF, Word, PowerPoint, Excel, ZIP (25MB) · Notice: PNG / JPG / WEBP image</span><input type="file" name="doc" required></label></div>
<button class="btn btn-block" style="margin-top:6px">Save now</button></form></div>
<?php admin_foot();
