<?php
/**
 * admin/variations.php — Variation Options (global attribute library)
 *
 * These are NOT tied to a product or category. Define reusable option
 * groups (e.g. "Color", "Size") and their values (e.g. Red, Blue, XL).
 * How/where they get applied to products is decided separately.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Variation Options';
$active    = 'variations';

/* ---------------- POST actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    /* ----- OPTION: create / update ----- */
    if ($action === 'option_create' || $action === 'option_update') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $sort = (int)($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : (($action === 'option_create') ? 1 : 0);

        $err = '';
        if ($name === '' || mb_strlen($name) > 60) {
            $err = 'Name is required (max 60 characters), e.g. Color, Size, Storage.';
        } else {
            $chk = db()->prepare('SELECT id FROM variation_options WHERE name = ?' . ($action === 'option_update' ? ' AND id != ?' : ''));
            $chk->execute($action === 'option_update' ? [$name, $id] : [$name]);
            if ($chk->fetch()) {
                $err = 'An option named "' . $name . '" already exists.';
            }
        }

        if ($err === '') {
            if ($action === 'option_create') {
                db()->prepare('INSERT INTO variation_options (name, sort_order, is_active) VALUES (?, ?, ?)')
                   ->execute([$name, $sort, $isActive]);
                flash('ok', 'Option "' . $name . '" created successfully.');
            } else {
                if (!get_variation_option($id)) {
                    flash('err', 'Option not found.');
                    redirect('/admin/variations');
                }
                db()->prepare('UPDATE variation_options SET name = ?, sort_order = ?, is_active = ? WHERE id = ?')
                   ->execute([$name, $sort, $isActive, $id]);
                flash('ok', 'Option "' . $name . '" updated successfully.');
            }
        } else {
            flash('err', $err);
        }
        redirect('/admin/variations');
    }

    if ($action === 'option_toggle') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE variation_options SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        flash('ok', 'Status updated.');
        redirect('/admin/variations');
    }

    if ($action === 'option_delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM variation_options WHERE id = ?')->execute([$id]);
        flash('ok', 'Option deleted (including its values).');
        redirect('/admin/variations');
    }

    /* ----- VALUE: create / update ----- */
    if ($action === 'value_create' || $action === 'value_update') {
        $id       = (int)($_POST['id'] ?? 0);
        $optionId = (int)($_POST['option_id'] ?? 0);
        $value    = trim((string)($_POST['value'] ?? ''));
        $sort     = (int)($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : (($action === 'value_create') ? 1 : 0);

        $err = '';
        if (!get_variation_option($optionId)) {
            $err = 'Please choose a valid option.';
        } elseif ($value === '' || mb_strlen($value) > 60) {
            $err = 'Value is required (max 60 characters), e.g. Red, XL, 128GB.';
        } else {
            $chk = db()->prepare('SELECT id FROM variation_option_values WHERE option_id = ? AND value = ?' . ($action === 'value_update' ? ' AND id != ?' : ''));
            $chk->execute($action === 'value_update' ? [$optionId, $value, $id] : [$optionId, $value]);
            if ($chk->fetch()) {
                $err = 'That value already exists for this option.';
            }
        }

        if ($err === '') {
            if ($action === 'value_create') {
                db()->prepare('INSERT INTO variation_option_values (option_id, value, sort_order, is_active) VALUES (?, ?, ?, ?)')
                   ->execute([$optionId, $value, $sort, $isActive]);
                flash('ok', 'Value "' . $value . '" added successfully.');
            } else {
                if (!get_variation_option_value($id)) {
                    flash('err', 'Value not found.');
                    redirect('/admin/variations');
                }
                db()->prepare('UPDATE variation_option_values SET option_id = ?, value = ?, sort_order = ?, is_active = ? WHERE id = ?')
                   ->execute([$optionId, $value, $sort, $isActive, $id]);
                flash('ok', 'Value "' . $value . '" updated successfully.');
            }
        } else {
            flash('err', $err);
        }
        redirect('/admin/variations');
    }

    if ($action === 'value_toggle') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE variation_option_values SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        flash('ok', 'Status updated.');
        redirect('/admin/variations');
    }

    if ($action === 'value_delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM variation_option_values WHERE id = ?')->execute([$id]);
        flash('ok', 'Value deleted.');
        redirect('/admin/variations');
    }
}

/* ---------------- Data ---------------- */
$options       = get_all_variation_options();
$valuesByOption = [];
foreach ($options as $opt) {
    $valuesByOption[(int)$opt['id']] = get_variation_option_values((int)$opt['id']);
}

require __DIR__ . '/includes/header.php';
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success">✔ <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error">⚠ <?= e($m) ?></div><?php endif; ?>

<div class="alert" style="background:#f7f0dc;color:#b8963f;border:1px solid #e6d8ad">
  ℹ These are shared, reusable options (e.g. <b>Color</b> → Red, Blue, Green). They aren't linked to any product yet —
  how they get applied is handled separately.
</div>

<!-- ===== OPTIONS + VALUES TREE ===== -->
<div class="panel">
  <div class="panel-head">
    <h3>Variation Options (<?= count($options) ?>)</h3>
    <div style="display:flex;gap:8px">
      <button type="button" class="btn-add" onclick="openAddOption()">+ Add Option</button>
      <button type="button" class="mini-btn toggle" onclick="expandAll()">Expand all</button>
      <button type="button" class="mini-btn toggle" onclick="collapseAll()">Collapse all</button>
    </div>
  </div>
  <div class="panel-body">
    <ul class="cat-tree">
      <?php foreach ($options as $opt): ?>
      <?php $vals = $valuesByOption[(int)$opt['id']]; ?>
      <li class="cat-node">
        <div class="cat-row">
          <button type="button" class="cat-toggle <?= $vals ? '' : 'leaf' ?>" onclick="toggleNode(this)"><?= $vals ? '▾' : '•' ?></button>
          <b class="cat-name"><?= e($opt['name']) ?></b>
          <span class="tag tag-lvl"><?= count($vals) ?> value<?= count($vals) === 1 ? '' : 's' ?></span>
          <span class="tag <?= $opt['is_active'] ? 'tag-on' : 'tag-off' ?>"><?= $opt['is_active'] ? 'Active' : 'Hidden' ?></span>
          <span class="crumb">sort: <?= (int)$opt['sort_order'] ?></span>

          <div class="row-actions cat-actions">
            <button type="button" class="mini-btn edit" onclick='openEditOption(<?= json_encode([
              "id"=>(int)$opt["id"],"name"=>$opt["name"],"sort_order"=>(int)$opt["sort_order"],"is_active"=>(int)$opt["is_active"]
            ]) ?>)'>Edit</button>
            <button type="button" class="mini-btn add-val" onclick="openAddValue(<?= (int)$opt['id'] ?>, '<?= e(addslashes($opt['name'])) ?>')">+ Value</button>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="option_toggle">
              <input type="hidden" name="id" value="<?= (int)$opt['id'] ?>">
              <button class="mini-btn toggle" type="submit"><?= $opt['is_active'] ? 'Hide' : 'Show' ?></button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete “<?= e($opt['name']) ?>” and ALL of its values?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="option_delete">
              <input type="hidden" name="id" value="<?= (int)$opt['id'] ?>">
              <button class="mini-btn del" type="submit">Delete</button>
            </form>
          </div>
        </div>

        <?php if ($vals): ?>
        <ul class="cat-children">
          <?php foreach ($vals as $v): ?>
          <li class="cat-node">
            <div class="cat-row">
              <span class="cat-toggle leaf">•</span>
              <b class="cat-name"><?= e($v['value']) ?></b>
              <span class="tag <?= $v['is_active'] ? 'tag-on' : 'tag-off' ?>"><?= $v['is_active'] ? 'Active' : 'Hidden' ?></span>
              <span class="crumb">sort: <?= (int)$v['sort_order'] ?></span>

              <div class="row-actions cat-actions">
                <button type="button" class="mini-btn edit" onclick='openEditValue(<?= json_encode([
                  "id"=>(int)$v["id"],"option_id"=>(int)$v["option_id"],"value"=>$v["value"],
                  "sort_order"=>(int)$v["sort_order"],"is_active"=>(int)$v["is_active"]
                ]) ?>)'>Edit</button>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="value_toggle">
                  <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                  <button class="mini-btn toggle" type="submit"><?= $v['is_active'] ? 'Hide' : 'Show' ?></button>
                </form>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete this value?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="value_delete">
                  <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                  <button class="mini-btn del" type="submit">Delete</button>
                </form>
              </div>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
      <?php if (!$options): ?>
        <li style="color:var(--muted)">No options yet — click "+ Add Option" above (e.g. Color, Size).</li>
      <?php endif; ?>
    </ul>
  </div>
</div>

<!-- ===== ADD OPTION MODAL ===== -->
<div id="addOptionOverlay" class="modal-overlay" onclick="if(event.target===this) closeAddOption()">
  <div class="modal-box">
    <h3>Add Option</h3>
    <form method="post" class="form-grid" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="option_create">
      <label>Option Name
        <input type="text" name="name" required maxlength="60" placeholder="e.g. Color">
      </label>
      <label>Sort Order
        <input type="number" name="sort_order" value="0" min="0" max="9999">
      </label>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn-add">+ Add Option</button>
        <button type="button" class="mini-btn toggle" onclick="closeAddOption()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== EDIT OPTION MODAL ===== -->
<div id="editOptionOverlay" class="modal-overlay" onclick="if(event.target===this) closeEditOption()">
  <div class="modal-box">
    <h3>Edit Option</h3>
    <form method="post" class="form-grid" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="option_update">
      <input type="hidden" name="id" id="opt_edit_id">
      <label>Option Name
        <input type="text" name="name" id="opt_edit_name" required maxlength="60">
      </label>
      <label>Sort Order
        <input type="number" name="sort_order" id="opt_edit_sort_order" min="0" max="9999">
      </label>
      <label style="display:flex;align-items:center;gap:8px;margin-top:6px">
        <input type="checkbox" name="is_active" id="opt_edit_is_active" style="width:auto;margin:0">
        Active
      </label>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn-add">Save Changes</button>
        <button type="button" class="mini-btn toggle" onclick="closeEditOption()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== ADD VALUE MODAL ===== -->
<div id="addValueOverlay" class="modal-overlay" onclick="if(event.target===this) closeAddValue()">
  <div class="modal-box">
    <h3>Add Value <span id="addValueOptionLabel" style="color:var(--muted);font-weight:600;font-size:14px"></span></h3>
    <form method="post" class="form-grid" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="value_create">
      <input type="hidden" name="option_id" id="val_add_option_id">
      <label>Value
        <input type="text" name="value" required maxlength="60" placeholder="e.g. Red">
      </label>
      <label>Sort Order
        <input type="number" name="sort_order" value="0" min="0" max="9999">
      </label>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn-add">+ Add Value</button>
        <button type="button" class="mini-btn toggle" onclick="closeAddValue()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== EDIT VALUE MODAL ===== -->
<div id="editValueOverlay" class="modal-overlay" onclick="if(event.target===this) closeEditValue()">
  <div class="modal-box">
    <h3>Edit Value</h3>
    <form method="post" class="form-grid" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="value_update">
      <input type="hidden" name="id" id="val_edit_id">
      <label>Option
        <select name="option_id" id="val_edit_option_id" required>
          <?php foreach ($options as $opt): ?>
            <option value="<?= (int)$opt['id'] ?>"><?= e($opt['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Value
        <input type="text" name="value" id="val_edit_value" required maxlength="60">
      </label>
      <label>Sort Order
        <input type="number" name="sort_order" id="val_edit_sort_order" min="0" max="9999">
      </label>
      <label style="display:flex;align-items:center;gap:8px;margin-top:6px">
        <input type="checkbox" name="is_active" id="val_edit_is_active" style="width:auto;margin:0">
        Active
      </label>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn-add">Save Changes</button>
        <button type="button" class="mini-btn toggle" onclick="closeEditValue()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleNode(btn){
  if (btn.classList.contains('leaf')) return;
  const li = btn.closest('.cat-node');
  const kids = li.querySelector(':scope > .cat-children');
  if (!kids) return;
  const collapsed = kids.style.display === 'none';
  kids.style.display = collapsed ? '' : 'none';
  btn.textContent = collapsed ? '▾' : '▸';
}
function expandAll(){
  document.querySelectorAll('.cat-children').forEach(el => el.style.display = '');
  document.querySelectorAll('.cat-toggle:not(.leaf)').forEach(el => el.textContent = '▾');
}
function collapseAll(){
  document.querySelectorAll('.cat-tree > .cat-node > .cat-children').forEach(el => el.style.display = 'none');
  document.querySelectorAll('.cat-tree > .cat-node > .cat-row > .cat-toggle:not(.leaf)').forEach(el => el.textContent = '▸');
}

function openAddOption(){ document.getElementById('addOptionOverlay').classList.add('show'); }
function closeAddOption(){ document.getElementById('addOptionOverlay').classList.remove('show'); }

function openEditOption(opt){
  document.getElementById('opt_edit_id').value = opt.id;
  document.getElementById('opt_edit_name').value = opt.name;
  document.getElementById('opt_edit_sort_order').value = opt.sort_order;
  document.getElementById('opt_edit_is_active').checked = !!opt.is_active;
  document.getElementById('editOptionOverlay').classList.add('show');
}
function closeEditOption(){ document.getElementById('editOptionOverlay').classList.remove('show'); }

function openAddValue(optionId, optionName){
  document.getElementById('val_add_option_id').value = optionId;
  document.getElementById('addValueOptionLabel').textContent = 'for "' + optionName + '"';
  document.getElementById('addValueOverlay').classList.add('show');
}
function closeAddValue(){ document.getElementById('addValueOverlay').classList.remove('show'); }

function openEditValue(v){
  document.getElementById('val_edit_id').value = v.id;
  document.getElementById('val_edit_option_id').value = v.option_id;
  document.getElementById('val_edit_value').value = v.value;
  document.getElementById('val_edit_sort_order').value = v.sort_order;
  document.getElementById('val_edit_is_active').checked = !!v.is_active;
  document.getElementById('editValueOverlay').classList.add('show');
}
function closeEditValue(){ document.getElementById('editValueOverlay').classList.remove('show'); }
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
