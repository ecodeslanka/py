<?php
// role_form.php - shared form (included by role_add.php / role_edit.php).
// Expects: $form_title, $role (array), $perms (module => row), $errors (array), $submit_label.
$tree = menuTree();
?>
<style>
.rf{max-width:1200px;margin:20px auto;padding:0 16px}
.rf .card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;margin-bottom:16px}
.rf label{font-weight:600;font-size:13px}
.rf input[type=text],.rf textarea{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;margin-top:4px}
.rf .err{background:#fee2e2;color:#991b1b;padding:10px;border-radius:6px;margin-bottom:12px}
.rf .sec{background:#1e3a8a;color:#fff;padding:8px 12px;border-radius:6px;margin:16px 0 8px;font-weight:700}
.rf .main{background:#eef2ff;padding:7px 12px;font-weight:700;display:flex;justify-content:space-between;align-items:center;border-radius:6px;margin-top:8px}
.rf .sub{background:#f9fafb;padding:5px 12px 5px 28px;font-size:12px;font-weight:700;color:#4b5563;display:flex;justify-content:space-between}
.rf table{width:100%;border-collapse:collapse}
.rf th,.rf td{padding:6px 10px;border-bottom:1px solid #f1f5f9;font-size:13px}
.rf th{text-align:center;width:80px;font-size:12px}
.rf td.c{text-align:center}
.rf td.pg{padding-left:44px}
.rf .btn{padding:9px 18px;border:0;border-radius:6px;cursor:pointer;font-weight:600;text-decoration:none;display:inline-block}
.rf .btn-p{background:#2563eb;color:#fff}.rf .btn-s{background:#e5e7eb;color:#111}
.rf .mini{font-size:11px;font-weight:600;margin-left:6px;cursor:pointer;color:#2563eb;background:none;border:0}
.rf .search{margin-bottom:10px}
</style>
<div class="rf">
  <h2><?= h($form_title) ?></h2>
  <?php foreach ($errors as $e): ?><div class="err"><?= h($e) ?></div><?php endforeach; ?>
  <form method="post" id="roleForm">
    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
    <div class="card">
      <label>Role Name *</label>
      <input type="text" name="role_name" maxlength="100" required value="<?= h($role['role_name'] ?? '') ?>">
      <label style="display:block;margin-top:12px">Description</label>
      <textarea name="description" rows="2"><?= h($role['description'] ?? '') ?></textarea>
      <label style="display:block;margin-top:12px"><input type="checkbox" name="active" value="1" <?= ($role['active'] ?? 1) ? 'checked' : '' ?>> Active</label>
    </div>

    <div class="card">
      <h3 style="margin-top:0">Page Permissions</h3>
      <p style="font-size:12px;color:#6b7280">View = page appears in menu / can be opened. Add, Edit and Delete work only when View is ticked.</p>
      <input class="search" type="text" id="pgSearch" placeholder="Search pages..." oninput="filterPages(this.value)">
      <button type="button" class="mini" onclick="bulk(null,'all')">Select all</button>
      <button type="button" class="mini" onclick="bulk(null,'none')">Clear all</button>
      <button type="button" class="mini" onclick="bulk(null,'view')">View only</button>

      <?php $gi = 0; foreach ($tree as $sec => $mains): ?>
        <div class="sec" data-grp="s<?= ++$gi ?>"><?= h($sec) ?>
          <button type="button" class="mini" style="color:#fff" onclick="bulk('s<?= $gi ?>','all')">all</button>
          <button type="button" class="mini" style="color:#fff" onclick="bulk('s<?= $gi ?>','none')">none</button>
        </div>
        <?php $mi = 0; foreach ($mains as $main => $subs): $mg = "s{$gi}m" . (++$mi); ?>
          <?php if ($main !== $sec): ?>
          <div class="main"><span><?= h($main) ?></span>
            <span><button type="button" class="mini" onclick="bulk('<?= $mg ?>','all')">all</button>
            <button type="button" class="mini" onclick="bulk('<?= $mg ?>','none')">none</button></span></div>
          <?php endif; ?>
          <?php foreach ($subs as $sub => $pages): ?>
            <?php if ($sub !== ''): ?><div class="sub"><span><?= h($sub) ?></span></div><?php endif; ?>
            <table data-grp="<?= $mg ?> s<?= $gi ?>">
              
              <thead><tr><th style="text-align:left;width:auto"></th><th>View</th><th>Add</th><th>Edit</th><th>Delete</th></tr></thead>
              <?php foreach ($pages as $pg): $k = $pg['key']; $p = $perms[$k] ?? []; ?>
              <tr class="pgrow" data-name="<?= h(strtolower($pg['label'] . ' ' . $k)) ?>">
                <td class="pg"><?= h($pg['label']) ?> <small style="color:#9ca3af"><?= h($k) ?>.php</small></td>
                <?php foreach (['access','create','edit','delete'] as $act): ?>
                <td class="c"><input type="checkbox" class="cb-<?= $act ?>" name="perm[<?= h($k) ?>][<?= $act ?>]" value="1"
                    <?= !empty($p['can_' . $act]) ? 'checked' : '' ?> onchange="syncRow(this)"></td>
                <?php endforeach; ?>
              </tr>
              <?php endforeach; ?>
            </table>
          <?php endforeach; ?>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>

    <button class="btn btn-p" type="submit"><?= h($submit_label) ?></button>
    <a class="btn btn-s" href="role_manage.php">Cancel</a>
  </form>
</div>
<script>
function syncRow(cb){
  const tr=cb.closest('tr');
  if(cb.classList.contains('cb-access')){
    if(!cb.checked) tr.querySelectorAll('.cb-create,.cb-edit,.cb-delete').forEach(x=>x.checked=false);
  } else if(cb.checked){ tr.querySelector('.cb-access').checked=true; }
}
function bulk(grp,mode){
  const scope=grp?[...document.querySelectorAll('table[data-grp~="'+grp+'"]')]:[document.getElementById('roleForm')];
  scope.forEach(s=>s.querySelectorAll('tr.pgrow').forEach(tr=>{
    if(tr.style.display==='none') return;
    const a=tr.querySelector('.cb-access'),c=tr.querySelector('.cb-create'),e=tr.querySelector('.cb-edit'),d=tr.querySelector('.cb-delete');
    a.checked=c.checked=e.checked=d.checked=(mode==='all');
    if(mode==='view') a.checked=true;
  }));
}
function filterPages(q){
  q=q.toLowerCase();
  document.querySelectorAll('tr.pgrow').forEach(tr=>tr.style.display=tr.dataset.name.includes(q)?'':'none');
}
</script>
