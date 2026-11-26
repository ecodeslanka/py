<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php';
require_admin();
$l = lesson_ctx(); $lid = (int)$l['id'];
if (is_post()) {
    try {
        $type = post('mtype'); $title = post('title'); if ($title === '') throw new RuntimeException('Title is required.');
        if ($type === 'youtube') {
            $link = post('yt'); if (!youtube_embed($link)) throw new RuntimeException('Enter a valid YouTube link.');
            q('INSERT INTO materials(lesson_id,type,title,description,url_enc,view_limit) VALUES(?,?,?,?,?,?)', [$lid, 'youtube', $title, post('description') ?: null, enc($link), max(0, (int)post('view_limit'))]);
        } elseif ($type === 'document') {
            $f = save_upload('doc', 'docs', ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'zip'], 25);
            if (!$f) throw new RuntimeException('Choose a document to upload.');
            q('INSERT INTO materials(lesson_id,type,title,description,file_path,file_name) VALUES(?,?,?,?,?,?)', [$lid, 'document', $title, post('description') ?: null, $f['name'], $f['orig']]);
        } else throw new RuntimeException('Choose a material type.');
        flash('Material added.'); redirect('admin/materials?lesson=' . $lid);
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); redirect('admin/material-add?lesson=' . $lid); }
}
admin_head('Add material', 'lessons');
lesson_banner($l, 'Add material');
?>
<div class="card formcard"><form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
<div class="f"><b>Material type</b></div>
<div class="seg"><label><input type="radio" name="mtype" value="youtube" data-toggle="mt" checked><i data-lucide="youtube"></i>YouTube recording</label>
<label><input type="radio" name="mtype" value="document" data-toggle="mt"><i data-lucide="file-text"></i>Document</label></div>
<?php field('Title', 'title', '', ['req' => 1]); ?>
<div data-show-for="mt:youtube"><?php field('YouTube link', 'yt', '', ['type' => 'url', 'req' => 1, 'hint' => 'Stored encrypted. Students never see the raw link.', 'attr' => 'placeholder="https://www.youtube.com/watch?v=..."']);
field('View count per student (optional)', 'view_limit', '', ['type' => 'number', 'attr' => 'min="0" placeholder="Unlimited"', 'hint' => 'Leave empty / 0 for unlimited views']); ?></div>
<div data-show-for="mt:document" hidden><label class="f">Document file<span class="hint">PDF, Word, PowerPoint, Excel, image, ZIP – max 25MB</span><input type="file" name="doc" required></label></div>
<?php field('Description (optional)', 'description', '', ['type' => 'textarea', 'rows' => 3]); ?>
<button class="btn">Save material</button> <a class="btn btn-o" href="<?= url('admin/materials?lesson=' . $lid) ?>">Cancel</a></form></div>
<?php admin_foot();
