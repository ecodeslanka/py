<?php
/**
 * admin/parts_services.php — Home Collections
 * Manages the big image tiles on the home page ("Shop the collections"), the
 * "Collections" menu, the mobile drawer list and the footer column
 * (includes/store_content.php → dewansa_parts_services()).
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Home Collections';
$active    = 'parts_services';

ensure_home_services_table();

/** Icon choices offered in the form (any Font Awesome class can also be typed). */
$iconChoices = [
    'fa-solid fa-shirt' => 'T-shirt / clothing', 'fa-solid fa-person' => 'Men', 'fa-solid fa-person-dress' => 'Women',
    'fa-solid fa-child-reaching' => 'Kids', 'fa-solid fa-star' => 'New / featured', 'fa-solid fa-tags' => 'Sale / offers',
    'fa-solid fa-shoe-prints' => 'Footwear', 'fa-solid fa-hat-cowboy' => 'Caps / hats', 'fa-solid fa-bag-shopping' => 'Bags',
    'fa-solid fa-glasses' => 'Accessories', 'fa-solid fa-dumbbell' => 'Activewear', 'fa-solid fa-gift' => 'Gifts',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_service') {
        $id    = (int)($_POST['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? ''));
        $icon  = trim((string)($_POST['icon'] ?? ''));
        $link  = trim((string)($_POST['link'] ?? ''));
        $sort  = (int)($_POST['sort_order'] ?? 0);
        $feat  = isset($_POST['is_featured']) ? 1 : 0;
        $act   = isset($_POST['is_active']) ? 1 : 0;

        if ($title === '' || mb_strlen($title) > 120) {
            flash('err', 'Title is required (max 120 characters).');
            redirect('/admin/parts_services');
        }
        if (!preg_match('/^[a-z0-9 -]{3,80}$/i', $icon)) { $icon = 'fa-solid fa-shirt'; }
        $oldImg = null;
        if ($id) { $q = db()->prepare('SELECT image FROM home_services WHERE id = ?'); $q->execute([$id]); $oldImg = $q->fetchColumn() ?: null; }
        if (!empty($_POST['remove_image']) && $oldImg) { $f = __DIR__ . '/../' . ltrim($oldImg, '/'); if (is_file($f)) { @unlink($f); } $oldImg = null; }
        [$image, $imgErr] = save_uploaded_site_image('image', $oldImg, 'collections', 5);
        if ($imgErr) { flash('err', $imgErr); redirect('/admin/parts_services'); }
        $desc = mb_substr($desc, 0, 255);
        $link = mb_substr($link, 0, 255);
        if ($link !== '' && preg_match('~^\s*javascript:~i', $link)) { $link = ''; }

        if ($id) {
            db()->prepare('UPDATE home_services SET title=?, description=?, icon=?, link=?, image=?, sort_order=?, is_featured=?, is_active=? WHERE id=?')
                ->execute([$title, $desc, $icon, $link, $image, $sort, $feat, $act, $id]);
            flash('ok', 'Updated.');
        } else {
            db()->prepare('INSERT INTO home_services (title, description, icon, link, image, sort_order, is_featured, is_active) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$title, $desc, $icon, $link, $image, $sort, $feat, $act]);
            flash('ok', 'Added.');
        }
        redirect('/admin/parts_services');
    }

    if ($action === 'toggle_service') {
        db()->prepare('UPDATE home_services SET is_active = 1 - is_active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('ok', 'Visibility updated.');
        redirect('/admin/parts_services');
    }

    if ($action === 'delete_service') {
        db()->prepare('DELETE FROM home_services WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('ok', 'Deleted.');
        redirect('/admin/parts_services');
    }
}

$services = db()->query('SELECT * FROM home_services ORDER BY sort_order ASC, id ASC')->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success">✔ <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error">⚠ <?= e($m) ?></div><?php endif; ?>

<div class="panel">
  <div class="panel-head">
    <h3>Home Collections (<?= count($services) ?>)</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="mini-btn toggle" href="<?= BASE_URL ?>/#parts" target="_blank" rel="noopener"><i class="fa-solid fa-store"></i> See on home page</a>
      <button type="button" class="btn-add" onclick="openAddService()">+ Add</button>
    </div>
  </div>
  <p class="hint" style="padding:12px 20px 0;margin:0">
    These are the big <b>image tiles</b> on the home page ("Shop the collections") and also appear in the <b>Collections</b> menu, the mobile menu and the footer.
    Upload a tall photo (about <b>800 × 1000 px</b>) for each. <b>Link</b> can be a search word (e.g. <code>polo</code>), a site page (e.g. <code>/products?category=men</code>) or a full web address.
    A <b>Featured</b> tile is shown extra wide.
  </p>
  <div class="panel-body" style="padding:0">
    <table>
      <thead><tr><th></th><th>Title</th><th>Description</th><th>Link</th><th>Order</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($services as $sv): ?>
        <tr>
          <td><?php if (!empty($sv['image'])): ?><img class="items-thumb" src="<?= BASE_URL . e($sv['image']) ?>" alt="" style="object-fit:cover"><?php else: ?><span class="items-thumb" style="display:grid;place-items:center;font-size:17px;color:#b8963f;background:#f7f0dc"><i class="<?= e($sv['icon']) ?>"></i></span><?php endif; ?></td>
          <td><b><?= e($sv['title']) ?></b><?php if ($sv['is_featured']): ?> <span class="tag tag-pre">Featured</span><?php endif; ?></td>
          <td style="color:var(--muted);font-size:13px;max-width:280px"><?= e($sv['description']) ?></td>
          <td><a href="<?= e(service_link_url($sv['link'])) ?>" target="_blank" rel="noopener" title="Open this link on the website"><?= e($sv['link'] ?: '/products') ?> <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:10px"></i></a></td>
          <td><?= (int)$sv['sort_order'] ?></td>
          <td>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_service">
              <input type="hidden" name="id" value="<?= (int)$sv['id'] ?>">
              <button type="submit" class="tag <?= $sv['is_active'] ? 'tag-on' : 'tag-off' ?>" style="border:none;cursor:pointer"><?= $sv['is_active'] ? 'Shown' : 'Hidden' ?></button>
            </form>
          </td>
          <td>
            <div class="row-actions">
              <button type="button" class="mini-btn edit" onclick='openEditService(<?= htmlspecialchars(json_encode($sv), ENT_QUOTES, "UTF-8") ?>)'>Edit</button>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this card?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_service">
                <input type="hidden" name="id" value="<?= (int)$sv['id'] ?>">
                <button class="mini-btn del" type="submit">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$services): ?>
        <tr><td colspan="7" style="color:var(--muted)">Nothing yet — click "+ Add".</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== MODAL: Add/Edit ===== -->
<div id="svcOverlay" class="modal-overlay" onclick="if(event.target===this) closeService()">
  <div class="modal-box">
    <h3 id="svcTitle">Add Collection</h3>
    <form method="post" class="form-grid" id="svcForm" autocomplete="off" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_service">
      <input type="hidden" name="id" id="svc_id">
      <label style="grid-column:1/-1">Title
        <input type="text" name="title" id="svc_title_in" required maxlength="120" placeholder="e.g. Men's T-Shirts">
      </label>
      <label style="grid-column:1/-1">Short description
        <input type="text" name="description" id="svc_desc" maxlength="255" placeholder="e.g. Heavyweight cotton, every colour">
      </label>
      <label style="grid-column:1/-1">Tile image (tall photo, JPG/PNG/WEBP, max 5MB)
        <span style="display:flex;gap:12px;align-items:center;margin-top:6px">
          <span id="svc_img_prev" style="width:64px;height:80px;border-radius:10px;background:#f7f0dc;display:grid;place-items:center;overflow:hidden;flex:none;color:#b8963f">🖼</span>
          <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,.gif" onchange="svcPreview(this)">
        </span>
        <label id="svc_rm_wrap" style="display:none;align-items:center;gap:8px;margin-top:6px;font-weight:500"><input type="checkbox" name="remove_image" value="1" style="width:auto"> Remove current image</label>
      </label>
      <label>Icon
        <select name="icon" id="svc_icon" onchange="document.getElementById('svc_icon_prev').className=this.value">
          <?php foreach ($iconChoices as $cls => $lbl): ?>
          <option value="<?= e($cls) ?>"><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Preview
        <span style="display:grid;place-items:center;width:46px;height:46px;border-radius:12px;background:#f7f0dc;color:#b8963f;font-size:20px"><i id="svc_icon_prev" class="fa-solid fa-shirt"></i></span>
      </label>
      <label style="grid-column:1/-1">Link (search word, site page or web address)
        <input type="text" name="link" id="svc_link" maxlength="255" placeholder="e.g. polo   or   /products?category=men">
      </label>
      <label>Display order
        <input type="number" name="sort_order" id="svc_sort" value="0">
      </label>
      <div style="display:flex;flex-direction:column;gap:8px;justify-content:end">
        <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="is_featured" id="svc_feat" value="1" style="width:auto"> Featured (wide tile)</label>
        <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="is_active" id="svc_act" value="1" checked style="width:auto"> Show on website</label>
      </div>
      <div style="display:flex;gap:10px;grid-column:1/-1">
        <button type="submit" class="btn-add">Save</button>
        <button type="button" class="mini-btn toggle" onclick="closeService()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function setIcon(v){
  var sel = document.getElementById('svc_icon');
  if (v && !Array.from(sel.options).some(function(o){ return o.value === v; })) {
    var o = document.createElement('option'); o.value = v; o.textContent = v; sel.appendChild(o);
  }
  sel.value = v || 'fa-solid fa-shirt';
  document.getElementById('svc_icon_prev').className = sel.value;
}
function openAddService(){
  document.getElementById('svcTitle').textContent = 'Add Collection';
  document.getElementById('svcForm').reset();
  document.getElementById('svc_id').value = '';
  document.getElementById('svc_act').checked = true;
  svcSetPreview('');
  setIcon('fa-solid fa-shirt');
  document.getElementById('svcOverlay').classList.add('show');
}
function openEditService(s){
  document.getElementById('svcTitle').textContent = 'Edit Collection';
  document.getElementById('svcForm').reset();
  svcSetPreview(s.image ? '<?= BASE_URL ?>' + s.image : '');
  document.getElementById('svc_id').value = s.id;
  document.getElementById('svc_title_in').value = s.title;
  document.getElementById('svc_desc').value = s.description || '';
  document.getElementById('svc_link').value = s.link || '';
  document.getElementById('svc_sort').value = s.sort_order || 0;
  document.getElementById('svc_feat').checked = String(s.is_featured) === '1';
  document.getElementById('svc_act').checked = String(s.is_active) === '1';
  setIcon(s.icon);
  document.getElementById('svcOverlay').classList.add('show');
}
function svcSetPreview(src){
  var box = document.getElementById('svc_img_prev');
  box.innerHTML = src ? '<img src="' + src + '" style="width:100%;height:100%;object-fit:cover">' : '🖼';
  document.getElementById('svc_rm_wrap').style.display = src ? 'flex' : 'none';
}
function svcPreview(input){
  if (!input.files || !input.files[0]) return;
  var r = new FileReader(); r.onload = function(e){ document.getElementById('svc_img_prev').innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover">'; }; r.readAsDataURL(input.files[0]);
}
function closeService(){ document.getElementById('svcOverlay').classList.remove('show'); }
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
