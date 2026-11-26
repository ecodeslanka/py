<?php
require __DIR__ . '/../inc/bootstrap.php'; require __DIR__ . '/../inc/layout.php'; require __DIR__ . '/../inc/home.php';
require_admin();
const HOME_IMAGES = ['hero_image' => 'Hero photo (used when there are no slides)', 'about_image' => 'About photo', 'why_image' => 'Why-section photo', 'community_image' => 'WhatsApp box photo'];
if (is_post()) {
    try {
        $out = [];
        foreach (home_schema() as $sec) foreach ($sec[1] as $f) {
            [$key, , $type] = $f;
            if ($type === 'list') {
                $rows_ = [];
                foreach (($_POST['l'][$key] ?? []) as $r) {
                    $r = array_map(fn($v) => trim((string)$v), $r);
                    if (implode('', $r) !== '') $rows_[] = array_intersect_key($r, array_flip(array_column($f[3], 0)));
                }
                $out[$key] = $rows_;
            } else $out[$key] = trim((string)($_POST['f'][$key] ?? ''));
        }
        set_setting('home_json', json_encode($out, JSON_UNESCAPED_UNICODE));
        foreach (HOME_IMAGES as $k => $_) {
            if (!empty($_POST['reset_' . $k])) { del_upload('images', setting($k)); set_setting($k, ''); }
            if ($up = save_upload($k, 'images', IMG_EXT, 8)) { del_upload('images', setting($k)); set_setting($k, $up['name']); }
        }
        flash('Home page saved.');
    } catch (Throwable $ex) { flash($ex->getMessage(), 'err'); }
    redirect('admin/home');
}
admin_head('Home page', 'home');
$H = home();
function list_row(string $key, array $cols, array $vals, string $idx): void {
    echo '<div class="lrow"><div class="lcols">';
    foreach ($cols as [$c, $lab]) echo '<label class="f">' . e($lab) . '<input name="l[' . e($key) . '][' . $idx . '][' . e($c) . ']" value="' . e($vals[$c] ?? '') . '"></label>';
    echo '</div><button type="button" class="btn btn-danger btn-sm" data-delrow>Remove</button></div>';
}
?>
<p class="muted">Everything on the public home page can be edited here. Icon names come from <a href="https://lucide.dev/icons" target="_blank" rel="noopener"><u>lucide.dev/icons</u></a> (e.g. <code>book-open</code>, <code>map-pin</code>). Batches, slides, WhatsApp groups, phone and social links are managed in their own pages.</p><br>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
<?php foreach (home_schema() as $sk => [$title, $fields]): ?>
<details class="card sect" <?= $sk === 'hero' ? 'open' : '' ?>><summary><?= e($title) ?></summary><div class="sbody">
  <?php foreach ($fields as $f): [$key, $label, $type] = $f; ?>
    <?php if ($type === 'text'): ?><label class="f"><?= e($label) ?><input name="f[<?= e($key) ?>]" value="<?= e($H[$key]) ?>"></label>
    <?php elseif ($type === 'area'): ?><label class="f"><?= e($label) ?><textarea name="f[<?= e($key) ?>]" rows="5"><?= e($H[$key]) ?></textarea></label>
    <?php else: ?>
      <div class="f"><b><?= e($label) ?></b>
      <div class="lrows" data-key="<?= e($key) ?>"><?php foreach ($H[$key] as $i => $r) list_row($key, $f[3], $r, (string)$i); ?></div>
      <template id="tpl_<?= e($key) ?>"><?php list_row($key, $f[3], [], '__i__'); ?></template>
      <button type="button" class="btn btn-o btn-sm" data-addrow="<?= e($key) ?>">+ Add</button></div>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if ($sk === 'hero'): ?><?php foreach (['hero_image'] as $ik): echo imgfield($ik); endforeach; endif; ?>
  <?php if ($sk === 'about') echo imgfield('about_image'); if ($sk === 'why') echo imgfield('why_image'); if ($sk === 'community') echo imgfield('community_image'); ?>
</div></details>
<?php endforeach; ?>
<div class="savebar"><button class="btn btn-r">Save home page</button> <a class="btn btn-o" href="<?= url() ?>" target="_blank">View site</a></div>
</form>
<script>
document.querySelectorAll('[data-addrow]').forEach(function(b){b.addEventListener('click',function(){
  var k=b.dataset.addrow,t=document.getElementById('tpl_'+k).innerHTML.replace(/__i__/g,Date.now()+Math.floor(Math.random()*999));
  document.querySelector('.lrows[data-key="'+k+'"]').insertAdjacentHTML('beforeend',t);});});
document.addEventListener('click',function(e){var d=e.target.closest('[data-delrow]');if(d)d.closest('.lrow').remove();});
</script>
<?php
function imgfield(string $k): string {
    $cur = setting($k);
    return '<div class="f"><b>' . e(HOME_IMAGES[$k]) . '</b>' . ($cur ? '<br><img class="thumb" style="width:110px;height:76px" src="' . e(img($cur)) . '" alt=""> <label class="chk" style="display:inline-flex;margin-left:10px"><input type="checkbox" name="reset_' . $k . '">Use default photo</label>' : '<span class="hint">Using default photo</span>')
         . '<input type="file" name="' . $k . '" accept="image/*"></div>';
}
admin_foot();
