<?php
/**
 * payroll_headers.php — Payroll Headers (salary additions / deductions by name).
 * Create, edit and delete headers; tick the staff categories each one is used for.
 * The salary sheets offer the ticked headers in Additional Earnings / Deductions (payroll_headers_lib.php).
 */
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
include_once __DIR__ . '/payroll_headers_lib.php';
ph_ensure($conn);
$ph_user = $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'system';

/* staff categories (with a code — the salary sheets use the code) */
function ph_categories($conn) {
    $o = []; $r = ph_q($conn, "SELECT id, category_name, category_code, COALESCE(active,1) AS active FROM staff_categories ORDER BY category_name");
    while ($r && ($x = mysqli_fetch_assoc($r))) $o[] = $x;
    return $o;
}

/* ── AJAX ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ph_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = (string)$_POST['ph_action'];
    if (function_exists('rbac_can') && !rbac_can($act === 'delete' ? 'delete' : 'edit', 'payroll_headers')) { echo json_encode(['ok' => false, 'msg' => 'Permission blocked for your role.']); exit; }
    $e = function ($v) use ($conn) { return mysqli_real_escape_string($conn, (string)$v); };
    if ($act === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? '')));
        $type = ($_POST['header_type'] ?? '') === 'deduction' ? 'deduction' : 'addition';
        $amtR = trim(str_replace(',', '', (string)($_POST['default_amount'] ?? '')));
        $amt  = $amtR === '' ? 'NULL' : round((float)$amtR, 2);
        $desc = trim(mb_substr((string)($_POST['description'] ?? ''), 0, 255));
        $act1 = !empty($_POST['active']) ? 1 : 0;
        $sort = (int)($_POST['sort_order'] ?? 0);
        $cats = array_values(array_unique(array_filter(array_map('trim', (array)json_decode((string)($_POST['categories'] ?? '[]'), true)), 'strlen')));
        if ($name === '' || mb_strlen($name) > 150) { echo json_encode(['ok' => false, 'msg' => 'Enter the header name (up to 150 letters).']); exit; }
        if ($amtR !== '' && (!is_numeric($amtR) || (float)$amtR < 0)) { echo json_encode(['ok' => false, 'msg' => 'Enter a valid default amount, or leave it empty.']); exit; }
        if (!$cats) { echo json_encode(['ok' => false, 'msg' => 'Tick at least one staff category.']); exit; }
        $r = ph_q($conn, "SELECT id FROM payroll_headers WHERE name = '" . $e($name) . "' AND header_type = '$type' AND id <> $id LIMIT 1");
        if ($r && mysqli_num_rows($r)) { echo json_encode(['ok' => false, 'msg' => 'A' . ($type === 'addition' ? 'n addition' : ' deduction') . ' named "' . $name . '" already exists.']); exit; }
        $now = date('Y-m-d H:i:s');
        if ($id) {
            $ok = ph_q($conn, "UPDATE payroll_headers SET name = '" . $e($name) . "', header_type = '$type', default_amount = $amt, description = " . ($desc !== '' ? "'" . $e($desc) . "'" : 'NULL') . ",
                               active = $act1, sort_order = $sort, updated_by = '" . $e($ph_user) . "', updated_at = '$now' WHERE id = $id");
        } else {
            $ok = ph_q($conn, "INSERT INTO payroll_headers (name, header_type, default_amount, description, active, sort_order, created_by)
                               VALUES ('" . $e($name) . "', '$type', $amt, " . ($desc !== '' ? "'" . $e($desc) . "'" : 'NULL') . ", $act1, $sort, '" . $e($ph_user) . "')");
            if ($ok) $id = (int)mysqli_insert_id($conn);
        }
        if (!$ok) { echo json_encode(['ok' => false, 'msg' => 'Could not save: ' . mysqli_error($conn)]); exit; }
        ph_q($conn, "DELETE FROM payroll_header_categories WHERE header_id = $id");
        foreach ($cats as $c) ph_q($conn, "INSERT IGNORE INTO payroll_header_categories (header_id, category_code) VALUES ($id, '" . $e($c) . "')");
        echo json_encode(['ok' => true, 'id' => $id, 'msg' => 'Saved.']); exit;
    }
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        ph_q($conn, "DELETE FROM payroll_header_categories WHERE header_id = $id");
        $ok = ph_q($conn, "DELETE FROM payroll_headers WHERE id = $id");
        echo json_encode($ok ? ['ok' => true, 'msg' => 'Deleted.'] : ['ok' => false, 'msg' => 'Could not delete.']); exit;
    }
    if ($act === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $ok = ph_q($conn, "UPDATE payroll_headers SET active = 1 - active, updated_by = '" . $e($ph_user) . "', updated_at = NOW() WHERE id = $id");
        echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'msg' => 'Could not change.']); exit;
    }
    /* ── the additions / deductions already typed in the saved salary sheets (Additional Earnings / Deductions) ── */
    if ($act === 'scan' || $act === 'import') {
        $found = [];                                              /* type|lower name → name, type, uses, amounts, categories */
        $have = (ph_q($conn, "SHOW TABLES LIKE 'salary_sheet_entries'") ?: null);
        if ($have && mysqli_num_rows($have)) {
            $r = ph_q($conn, "SELECT s.additional_earnings, s.additional_deductions, COALESCE(sc.category_code,'') AS cat
                              FROM salary_sheet_entries s LEFT JOIN employees e ON e.id = s.employee_id LEFT JOIN staff_categories sc ON sc.id = e.staff_category_id
                              WHERE (s.additional_earnings IS NOT NULL AND s.additional_earnings NOT IN ('','[]','null')) OR (s.additional_deductions IS NOT NULL AND s.additional_deductions NOT IN ('','[]','null'))");
            while ($r && ($x = mysqli_fetch_assoc($r))) {
                foreach (['addition' => 'additional_earnings', 'deduction' => 'additional_deductions'] as $t => $col) {
                    $list = json_decode((string)$x[$col], true); if (!is_array($list)) continue;
                    foreach ($list as $it) {
                        $n = trim(preg_replace('/\s+/', ' ', (string)($it['desc'] ?? ''))); if ($n === '' || mb_strlen($n) > 150) continue;
                        $k = $t . '|' . mb_strtolower($n);
                        if (!isset($found[$k])) $found[$k] = ['type' => $t, 'names' => [], 'uses' => 0, 'amounts' => [], 'cats' => []];
                        $found[$k]['names'][$n] = ($found[$k]['names'][$n] ?? 0) + 1; $found[$k]['uses']++;
                        $a = round((float)($it['amount'] ?? 0), 2); if ($a > 0) $found[$k]['amounts'][(string)$a] = ($found[$k]['amounts'][(string)$a] ?? 0) + 1;
                        if (trim($x['cat']) !== '') $found[$k]['cats'][trim($x['cat'])] = 1;
                    }
                }
            }
        }
        $exist = []; $r = ph_q($conn, "SELECT id, name, header_type FROM payroll_headers"); while ($r && ($x = mysqli_fetch_assoc($r))) $exist[$x['header_type'] . '|' . mb_strtolower($x['name'])] = (int)$x['id'];
        $out = [];
        foreach ($found as $k => $f) {
            arsort($f['names']); arsort($f['amounts']);
            $amts = array_keys($f['amounts']);
            $out[] = ['key' => $k, 'name' => array_key_first($f['names']), 'type' => $f['type'], 'uses' => $f['uses'], 'cats' => array_keys($f['cats']),
                      'amount' => count($amts) === 1 ? (float)$amts[0] : null, 'amounts' => array_slice(array_map('floatval', $amts), 0, 4), 'exists' => $exist[$k] ?? 0];
        }
        usort($out, function ($a, $b) { return strcmp($a['type'], $b['type']) ?: ($b['uses'] <=> $a['uses']) ?: strcasecmp($a['name'], $b['name']); });
        if ($act === 'scan') { echo json_encode(['ok' => true, 'rows' => $out]); exit; }
        /* import the ticked ones: new name → new header (default amount only when it was always the same); existing name → its categories are added */
        $pick = array_flip((array)json_decode((string)($_POST['keys'] ?? '[]'), true)); $n = 0; $m = 0;
        foreach ($out as $o) {
            if (!isset($pick[$o['key']])) continue;
            $id = (int)$o['exists'];
            if (!$id) {
                $ok = ph_q($conn, "INSERT INTO payroll_headers (name, header_type, default_amount, description, active, created_by)
                                   VALUES ('" . $e($o['name']) . "', '{$o['type']}', " . ($o['amount'] !== null ? $o['amount'] : 'NULL') . ", 'Created from the salary sheets', 1, '" . $e($ph_user) . "')");
                if (!$ok) continue; $id = (int)mysqli_insert_id($conn); $n++;
            } else $m++;
            foreach ($o['cats'] as $c) ph_q($conn, "INSERT IGNORE INTO payroll_header_categories (header_id, category_code) VALUES ($id, '" . $e($c) . "')");
        }
        echo json_encode(['ok' => true, 'created' => $n, 'updated' => $m, 'msg' => $n . ' header(s) created' . ($m ? ', ' . $m . ' existing header(s) got their categories' : '') . '.']); exit;
    }
    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']); exit;
}

include 'header.php';
$cats = ph_categories($conn);
$catName = []; foreach ($cats as $c) if (trim((string)$c['category_code']) !== '') $catName[$c['category_code']] = $c['category_name'];
$rows = [];
$r = ph_q($conn, "SELECT h.*, (SELECT GROUP_CONCAT(category_code ORDER BY category_code SEPARATOR ',') FROM payroll_header_categories c WHERE c.header_id = h.id) AS cats
                  FROM payroll_headers h ORDER BY h.header_type, h.sort_order, h.name");
while ($r && ($x = mysqli_fetch_assoc($r))) { $x['cat_list'] = $x['cats'] ? explode(',', $x['cats']) : []; $rows[] = $x; }
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$nAdd = count(array_filter($rows, function ($x) { return $x['header_type'] !== 'deduction'; })); $nDed = count($rows) - $nAdd;
?>
<style>
.ph { padding: 4px 2px 30px; }
.ph-top { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
.ph-top h2 { margin: 0; font-size: 22px; color: #1e293b; }
.ph-top p { margin: 4px 0 0; color: #64748b; font-size: 13px; }
.ph-btn { border: 0; border-radius: 8px; padding: 9px 16px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.ph-new { background: #4f46e5; color: #fff; } .ph-new:hover { background: #4338ca; }
.ph-tabs { display: flex; gap: 6px; margin-bottom: 10px; flex-wrap: wrap; }
.ph-tabs button { border: 1px solid #e2e8f0; background: #fff; border-radius: 20px; padding: 6px 14px; font-weight: 700; font-size: 12.5px; color: #475569; cursor: pointer; }
.ph-tabs button.on { background: #1e293b; color: #fff; border-color: #1e293b; }
.ph-tabs input { margin-left: auto; border: 1px solid #e2e8f0; border-radius: 20px; padding: 6px 14px; font-size: 13px; min-width: 220px; }
.ph-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: auto; }
.ph-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.ph-tbl th { background: #f8fafc; text-align: left; padding: 10px 12px; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
.ph-tbl td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.ph-tbl tr.off td { opacity: .5; }
.ph-type { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 12px; font-size: 11.5px; font-weight: 800; white-space: nowrap; }
.ph-type.addition { background: #dcfce7; color: #166534; } .ph-type.deduction { background: #fee2e2; color: #991b1b; }
.ph-chip { display: inline-block; margin: 2px 4px 2px 0; padding: 2px 8px; border-radius: 10px; background: #eef2ff; color: #3730a3; font-size: 11px; font-weight: 700; }
.ph-act { white-space: nowrap; text-align: right; }
.ph-act button { border: 1px solid #e2e8f0; background: #fff; border-radius: 7px; width: 32px; height: 30px; cursor: pointer; color: #475569; }
.ph-act button:hover { background: #f1f5f9; } .ph-act .del:hover { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
.ph-sw { position: relative; width: 36px; height: 20px; display: inline-block; cursor: pointer; }
.ph-sw input { display: none; } .ph-sw span { position: absolute; inset: 0; background: #cbd5e1; border-radius: 20px; transition: .2s; }
.ph-sw span:after { content: ''; position: absolute; width: 16px; height: 16px; left: 2px; top: 2px; background: #fff; border-radius: 50%; transition: .2s; }
.ph-sw input:checked + span { background: #22c55e; } .ph-sw input:checked + span:after { left: 18px; }
.ph-empty { text-align: center; padding: 40px 10px; color: #94a3b8; }
.ph-mo { position: fixed; inset: 0; background: rgba(15,23,42,.55); z-index: 2147483000; display: none; align-items: center; justify-content: center; padding: 16px; }
.ph-mo.open { display: flex; }
.ph-box { background: #fff; border-radius: 14px; width: 100%; max-width: 620px; max-height: 92vh; overflow: auto; box-shadow: 0 24px 70px rgba(0,0,0,.3); }
.ph-bh { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-bottom: 1px solid #f1f5f9; }
.ph-bh h3 { margin: 0; font-size: 16px; color: #1e293b; }
.ph-bh button { border: 1px solid #e2e8f0; background: #fff; border-radius: 8px; width: 32px; height: 32px; cursor: pointer; }
.ph-bb { padding: 16px 18px; display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.ph-bb .w2 { grid-column: 1 / -1; }
.ph-bb label.l { display: block; font-size: 12px; font-weight: 700; color: #374151; margin-bottom: 5px; }
.ph-bb input[type=text], .ph-bb input[type=number] { width: 100%; box-sizing: border-box; padding: 9px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
.ph-seg { display: flex; border: 1px solid #d1d5db; border-radius: 8px; overflow: hidden; }
.ph-seg label { flex: 1; text-align: center; padding: 9px 6px; cursor: pointer; font-weight: 700; font-size: 13px; color: #475569; }
.ph-seg input { display: none; }
.ph-seg label.on.addition { background: #16a34a; color: #fff; } .ph-seg label.on.deduction { background: #dc2626; color: #fff; }
.ph-cats { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 6px; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px; max-height: 220px; overflow: auto; }
.ph-cats label { display: flex; align-items: center; gap: 7px; font-size: 13px; padding: 5px 6px; border-radius: 6px; cursor: pointer; }
.ph-cats label:hover { background: #f8fafc; } .ph-cats input { width: 16px; height: 16px; accent-color: #4f46e5; }
.ph-cats small { color: #94a3b8; }
.ph-cats-tools { display: flex; gap: 10px; margin-top: 6px; font-size: 12px; }
.ph-cats-tools a { color: #4f46e5; cursor: pointer; font-weight: 700; }
.ph-msg { grid-column: 1 / -1; display: none; padding: 9px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.ph-bf { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 18px; border-top: 1px solid #f1f5f9; }
.ph-cancel { background: #f1f5f9; color: #334155; }
.ph-imp { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; } .ph-imp:hover { background: #d1fae5; }
.ph-scan { width: 100%; border-collapse: collapse; font-size: 13px; }
.ph-scan th { position: sticky; top: 0; background: #f8fafc; text-align: left; padding: 8px 10px; font-size: 11px; text-transform: uppercase; color: #64748b; border-bottom: 1px solid #e5e7eb; }
.ph-scan td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.ph-scan tr.ex td { background: #f8fafc; }
.ph-scan input { width: 16px; height: 16px; accent-color: #059669; }
.ph-new-tag { font-size: 10.5px; font-weight: 800; padding: 1px 7px; border-radius: 9px; background: #dcfce7; color: #166534; }
.ph-ex-tag { font-size: 10.5px; font-weight: 800; padding: 1px 7px; border-radius: 9px; background: #e0e7ff; color: #3730a3; }
.ph-toast { position: fixed; bottom: 22px; left: 50%; transform: translateX(-50%); background: #1e293b; color: #fff; padding: 10px 18px; border-radius: 10px; font-weight: 700; font-size: 13px; z-index: 2147483600; display: none; }
@media (max-width: 600px) { .ph-bb { grid-template-columns: 1fr; } .ph-tabs input { margin-left: 0; width: 100%; } }
</style>

<div class="ph">
  <div class="ph-top">
    <div><h2><i class="fa-solid fa-list-check"></i> Payroll Headers</h2>
      <p>Salary additions and deductions by name. Tick the staff categories each one is used for — the salary sheets offer them in <b>Additional Earnings / Deductions</b>.</p></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap"><button type="button" class="ph-btn ph-imp" onclick="phScan()"><i class="fa-solid fa-wand-magic-sparkles"></i> Import from Salary Sheets</button>
    <button type="button" class="ph-btn ph-new" onclick="phOpen(0)"><i class="fa-solid fa-plus"></i> New Header</button></div>
  </div>
  <div class="ph-tabs">
    <button type="button" class="on" data-f="all" onclick="phFilter(this)">All (<?= count($rows) ?>)</button>
    <button type="button" data-f="addition" onclick="phFilter(this)">Additions (<?= $nAdd ?>)</button>
    <button type="button" data-f="deduction" onclick="phFilter(this)">Deductions (<?= $nDed ?>)</button>
    <input type="search" id="phSearch" placeholder="Search header or category…" oninput="phApply()">
  </div>
  <div class="ph-card">
    <table class="ph-tbl">
      <thead><tr><th style="width:40px">#</th><th>Header</th><th>Type</th><th style="text-align:right">Default Amount</th><th>Staff Categories</th><th>Active</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody id="phBody">
      <?php if (!$rows): ?><tr><td colspan="7" class="ph-empty"><i class="fa-solid fa-list-check" style="font-size:26px;display:block;margin-bottom:8px"></i>No payroll headers yet — click <b>New Header</b>.</td></tr><?php endif; ?>
      <?php foreach ($rows as $i => $x): $t = $x['header_type'] === 'deduction' ? 'deduction' : 'addition'; ?>
        <tr class="<?= $x['active'] ? '' : 'off' ?>" data-type="<?= $t ?>" data-s="<?= $h(mb_strtolower($x['name'] . ' ' . implode(' ', array_map(function ($c) use ($catName) { return $c . ' ' . ($catName[$c] ?? ''); }, $x['cat_list'])))) ?>">
          <td style="color:#94a3b8"><?= $i + 1 ?></td>
          <td><b><?= $h($x['name']) ?></b><?= $x['description'] ? '<div style="font-size:11.5px;color:#64748b">' . $h($x['description']) . '</div>' : '' ?></td>
          <td><span class="ph-type <?= $t ?>"><i class="fa-solid fa-<?= $t === 'addition' ? 'plus' : 'minus' ?>"></i> <?= $t === 'addition' ? 'Addition' : 'Deduction' ?></span></td>
          <td style="text-align:right;font-weight:700"><?= $x['default_amount'] !== null ? number_format((float)$x['default_amount'], 2) : '<span style="color:#cbd5e1">—</span>' ?></td>
          <td><?php foreach ($x['cat_list'] as $c) echo '<span class="ph-chip" title="' . $h($c) . '">' . $h($catName[$c] ?? $c) . '</span>'; if (!$x['cat_list']) echo '<span style="color:#cbd5e1">—</span>'; ?></td>
          <td><label class="ph-sw" title="Active"><input type="checkbox" <?= $x['active'] ? 'checked' : '' ?> onchange="phToggle(<?= (int)$x['id'] ?>, this)"><span></span></label></td>
          <td class="ph-act"><button type="button" title="Edit" onclick="phOpen(<?= (int)$x['id'] ?>)"><i class="fa-solid fa-pen"></i></button> <button type="button" class="del" title="Delete" onclick="phDelete(<?= (int)$x['id'] ?>)"><i class="fa-solid fa-trash"></i></button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="ph-mo" id="phMo" onclick="if(event.target===this)phClose()">
  <div class="ph-box" role="dialog" aria-modal="true" aria-labelledby="phTitle">
    <div class="ph-bh"><h3 id="phTitle">New Header</h3><button type="button" onclick="phClose()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="ph-bb">
      <div class="ph-msg" id="phMsg"></div>
      <div class="w2"><label class="l" for="phName">Header Name <span style="color:#dc2626">*</span></label><input type="text" id="phName" maxlength="150" placeholder="e.g. Attendance Allowance, Uniform Deduction"></div>
      <div><label class="l">Type <span style="color:#dc2626">*</span></label>
        <div class="ph-seg" id="phSeg"><label class="addition on"><input type="radio" name="phType" value="addition" checked onchange="phSeg()"> + Addition</label><label class="deduction"><input type="radio" name="phType" value="deduction" onchange="phSeg()"> − Deduction</label></div></div>
      <div><label class="l" for="phAmt">Default Amount <span style="font-weight:400;color:#94a3b8">(optional)</span></label><input type="number" id="phAmt" min="0" step="0.01" placeholder="Filled in when picked"></div>
      <div class="w2"><label class="l">Staff Categories <span style="color:#dc2626">*</span></label>
        <div class="ph-cats" id="phCats">
          <?php foreach ($cats as $c): $code = trim((string)$c['category_code']); ?>
            <label title="<?= $code === '' ? 'This category has no code — set one in Staff Category' : $h($code) ?>"<?= $code === '' ? ' style="opacity:.45;cursor:not-allowed"' : '' ?>><input type="checkbox" value="<?= $h($code) ?>" <?= $code === '' ? 'disabled' : '' ?>> <?= $h($c['category_name']) ?> <small><?= $h($code) ?></small></label>
          <?php endforeach; ?>
          <?php if (!$cats): ?><span style="color:#94a3b8">No staff categories found.</span><?php endif; ?>
        </div>
        <div class="ph-cats-tools"><a onclick="phAll(true)">Tick all</a><a onclick="phAll(false)">Clear</a></div></div>
      <div class="w2"><label class="l" for="phDesc">Note</label><input type="text" id="phDesc" maxlength="255" placeholder="Optional"></div>
      <div><label class="l" for="phSort">Order</label><input type="number" id="phSort" step="1" value="0"></div>
      <div style="display:flex;align-items:flex-end;gap:8px;padding-bottom:8px"><label class="ph-sw"><input type="checkbox" id="phActive" checked><span></span></label> <b style="font-size:13px">Active</b></div>
    </div>
    <div class="ph-bf"><button type="button" class="ph-btn ph-cancel" onclick="phClose()">Cancel</button><button type="button" class="ph-btn ph-new" id="phSave" onclick="phSave()"><i class="fa-solid fa-floppy-disk"></i> Save</button></div>
  </div>
</div>
<div class="ph-mo" id="phScanMo" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="ph-box" role="dialog" aria-modal="true" aria-labelledby="phScanTitle" style="max-width:860px">
    <div class="ph-bh"><h3 id="phScanTitle"><i class="fa-solid fa-wand-magic-sparkles"></i> Import from Salary Sheets</h3><button type="button" onclick="document.getElementById('phScanMo').classList.remove('open')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
    <div style="padding:12px 18px 0;font-size:13px;color:#475569">The additions and deductions typed in the saved salary sheets (Additional Earnings / Deductions). The staff categories come from the employees they were used for; the default amount is filled only when it was always the same. Tick the ones to create.</div>
    <div style="padding:10px 18px;max-height:56vh;overflow:auto"><table class="ph-scan"><thead><tr><th><input type="checkbox" id="phScanAll" onchange="document.querySelectorAll('.ph-sk').forEach(function(c){c.checked=document.getElementById('phScanAll').checked;})"></th><th>Name</th><th>Type</th><th style="text-align:right">Used</th><th>Amount(s)</th><th>Staff Categories</th><th></th></tr></thead><tbody id="phScanBody"></tbody></table></div>
    <div class="ph-bf"><span id="phScanInfo" style="margin-right:auto;font-size:12.5px;color:#64748b;align-self:center"></span><button type="button" class="ph-btn ph-cancel" onclick="document.getElementById('phScanMo').classList.remove('open')">Cancel</button><button type="button" class="ph-btn ph-new" id="phImpBtn" onclick="phImport()"><i class="fa-solid fa-file-import"></i> Create Ticked</button></div>
  </div>
</div>
<div class="ph-toast" id="phToast"></div>

<script>
var PH = <?= json_encode(array_map(function ($x) { return ['id' => (int)$x['id'], 'name' => $x['name'], 'type' => $x['header_type'], 'amount' => $x['default_amount'], 'desc' => $x['description'], 'active' => (int)$x['active'], 'sort' => (int)$x['sort_order'], 'cats' => $x['cat_list']]; }, $rows)) ?>;
var phCur = 0, phF = 'all';
function phEl(i){ return document.getElementById(i); }
function phToast(t){ var e=phEl('phToast'); e.textContent=t; e.style.display='block'; clearTimeout(phToast.t); phToast.t=setTimeout(function(){ e.style.display='none'; },2600); }
function phSeg(){ document.querySelectorAll('#phSeg label').forEach(function(l){ l.classList.toggle('on', l.querySelector('input').checked); }); }
function phAll(v){ document.querySelectorAll('#phCats input:not(:disabled)').forEach(function(c){ c.checked=v; }); }
function phOpen(id){
  var x = PH.find(function(r){ return r.id===id; }) || {name:'',type:'addition',amount:null,desc:'',active:1,sort:0,cats:[]};
  phCur = id; phEl('phTitle').textContent = id ? 'Edit Header' : 'New Header';
  phEl('phName').value = x.name; phEl('phAmt').value = x.amount===null||x.amount===undefined ? '' : (+x.amount);
  phEl('phDesc').value = x.desc||''; phEl('phSort').value = x.sort||0; phEl('phActive').checked = !!x.active;
  document.querySelectorAll('input[name=phType]').forEach(function(r){ r.checked = r.value===(x.type==='deduction'?'deduction':'addition'); }); phSeg();
  document.querySelectorAll('#phCats input').forEach(function(c){ c.checked = x.cats.indexOf(c.value) >= 0; });
  phEl('phMsg').style.display='none';
  var m=phEl('phMo'); if(m.parentElement!==document.body) document.body.appendChild(m); m.classList.add('open');
  setTimeout(function(){ phEl('phName').focus(); },50);
}
function phClose(){ phEl('phMo').classList.remove('open'); }
function phPost(data){ var fd=new FormData(); Object.keys(data).forEach(function(k){ fd.append(k,data[k]); }); return fetch(location.pathname,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){ return r.json(); }); }
function phSave(){
  var cats = [].map.call(document.querySelectorAll('#phCats input:checked'), function(c){ return c.value; });
  var b=phEl('phSave'), m=phEl('phMsg'); b.disabled=true; m.style.display='none';
  phPost({ph_action:'save', id:phCur, name:phEl('phName').value, header_type:(document.querySelector('input[name=phType]:checked')||{}).value||'addition',
          default_amount:phEl('phAmt').value, description:phEl('phDesc').value, sort_order:phEl('phSort').value, active:phEl('phActive').checked?1:0, categories:JSON.stringify(cats)})
  .then(function(d){ b.disabled=false; if(!d.ok){ m.textContent=d.msg||'Could not save.'; m.style.display='block'; return; } sessionStorage.setItem('ph_msg', phCur?'Header updated':'Header created'); location.reload(); })
  .catch(function(){ b.disabled=false; m.textContent='Could not reach the server.'; m.style.display='block'; });
}
function phDelete(id){
  var x = PH.find(function(r){ return r.id===id; }); if(!x) return;
  if(!confirm('Delete the ' + (x.type==='deduction'?'deduction':'addition') + ' header "' + x.name + '"?\n\nSalary sheets already saved keep their rows; it is only removed from the list.')) return;
  phPost({ph_action:'delete', id:id}).then(function(d){ if(!d.ok){ alert(d.msg||'Could not delete.'); return; } sessionStorage.setItem('ph_msg','Header deleted'); location.reload(); });
}
function phToggle(id, el){
  phPost({ph_action:'toggle', id:id}).then(function(d){ if(!d.ok){ el.checked=!el.checked; alert(d.msg||'Could not change.'); return; } el.closest('tr').classList.toggle('off', !el.checked); phToast(el.checked?'Activated':'Deactivated'); });
}
var CAT_NAME = <?= json_encode($catName) ?>;
function phEsc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
function phScan(){
  var m=phEl('phScanMo'); if(m.parentElement!==document.body) document.body.appendChild(m); m.classList.add('open');
  phEl('phScanBody').innerHTML='<tr><td colspan="7" style="text-align:center;padding:24px;color:#94a3b8">Reading the salary sheets…</td></tr>'; phEl('phScanInfo').textContent='';
  phPost({ph_action:'scan'}).then(function(d){
    if(!d.ok){ phEl('phScanBody').innerHTML='<tr><td colspan="7">'+phEsc(d.msg)+'</td></tr>'; return; }
    var nNew=d.rows.filter(function(r){return !r.exists;}).length;
    phEl('phScanInfo').textContent=d.rows.length+' found · '+nNew+' new';
    phEl('phScanBody').innerHTML = d.rows.length ? d.rows.map(function(r){
      return '<tr class="'+(r.exists?'ex':'')+'"><td><input type="checkbox" class="ph-sk" value="'+phEsc(r.key)+'"'+(r.exists?'':' checked')+'></td><td><b>'+phEsc(r.name)+'</b></td>'
        +'<td><span class="ph-type '+r.type+'">'+(r.type==='addition'?'+ Addition':'− Deduction')+'</span></td><td style="text-align:right">'+r.uses+'</td>'
        +'<td>'+(r.amounts.length? r.amounts.map(function(a){return Number(a).toLocaleString('en-US',{minimumFractionDigits:2});}).join(', ')+(r.amount===null&&r.amounts.length>1?' <small style="color:#94a3b8">(varies — no default)</small>':''):'<span style="color:#cbd5e1">—</span>')+'</td>'
        +'<td>'+(r.cats.length? r.cats.map(function(c){return '<span class="ph-chip">'+phEsc(CAT_NAME[c]||c)+'</span>';}).join(''):'<span style="color:#cbd5e1">—</span>')+'</td>'
        +'<td>'+(r.exists?'<span class="ph-ex-tag">Exists — adds categories</span>':'<span class="ph-new-tag">New</span>')+'</td></tr>';
    }).join('') : '<tr><td colspan="7" style="text-align:center;padding:24px;color:#94a3b8">No additions or deductions found in the saved salary sheets.</td></tr>';
    phEl('phScanAll').checked = nNew>0 && nNew===d.rows.length;
  }).catch(function(){ phEl('phScanBody').innerHTML='<tr><td colspan="7">Could not reach the server.</td></tr>'; });
}
function phImport(){
  var keys=[].map.call(document.querySelectorAll('.ph-sk:checked'),function(c){return c.value;}); if(!keys.length){ alert('Tick at least one.'); return; }
  var b=phEl('phImpBtn'); b.disabled=true;
  phPost({ph_action:'import', keys:JSON.stringify(keys)}).then(function(d){ b.disabled=false; if(!d.ok){ alert(d.msg||'Could not import.'); return; } sessionStorage.setItem('ph_msg', d.msg); location.reload(); })
  .catch(function(){ b.disabled=false; alert('Could not reach the server.'); });
}
function phFilter(btn){ phF = btn.dataset.f; document.querySelectorAll('.ph-tabs button').forEach(function(b){ b.classList.toggle('on', b===btn); }); phApply(); }
function phApply(){ var q=(phEl('phSearch').value||'').trim().toLowerCase(); document.querySelectorAll('#phBody tr[data-type]').forEach(function(tr){ tr.style.display = (phF==='all'||tr.dataset.type===phF) && (!q||tr.dataset.s.indexOf(q)>=0) ? '' : 'none'; }); }
document.addEventListener('keydown', function(e){ if(!phEl('phMo').classList.contains('open')) return; if(e.key==='Escape') phClose(); if(e.key==='Enter' && e.target.tagName==='INPUT' && e.target.type!=='checkbox'){ e.preventDefault(); phSave(); } });
try { var _m=sessionStorage.getItem('ph_msg'); if(_m){ sessionStorage.removeItem('ph_msg'); phToast(_m); } } catch(e){}
</script>
<?php if (is_file(__DIR__ . '/footer.php')) include 'footer.php'; ?>
