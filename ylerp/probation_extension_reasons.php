<?php
/** probation_extension_reasons.php — master list of reasons for extending an employee's probation */
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/probation_lib.php';
prob_ensure($conn);
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? ''; $id = (int)($_POST['id'] ?? 0); $reason = trim($_POST['reason'] ?? '');
    $e = fn($v) => mysqli_real_escape_string($conn, $v); $u = $e(prob_user());
    if ($act === 'save') {
        if ($reason === '') $err = 'Type the reason.';
        elseif ($id) { $ok = prob_q($conn, "UPDATE probation_extension_reasons SET reason = '" . $e($reason) . "' WHERE id = $id"); $ok ? $msg = 'Reason updated.' : $err = 'Could not save — the same reason may already be there.'; }
        else { $ok = prob_q($conn, "INSERT INTO probation_extension_reasons (reason, created_by) VALUES ('" . $e($reason) . "', '$u')"); $ok ? $msg = 'Reason added.' : $err = 'Could not save — the same reason may already be there.'; }
    } elseif ($act === 'toggle' && $id) { prob_q($conn, "UPDATE probation_extension_reasons SET is_active = 1 - is_active WHERE id = $id"); $msg = 'Status changed.'; }
    elseif ($act === 'delete' && $id) {
        $r = prob_q($conn, "SELECT COUNT(*) FROM employee_probation_extensions WHERE reason_id = $id"); $n = $r ? (int)mysqli_fetch_row($r)[0] : 0;
        if ($n) $err = "This reason is used in $n extension(s) — set it Inactive instead of deleting.";
        else { prob_q($conn, "DELETE FROM probation_extension_reasons WHERE id = $id"); $msg = 'Reason deleted.'; }
    }
}
$used = []; $r = prob_q($conn, "SELECT reason_id, COUNT(*) FROM employee_probation_extensions GROUP BY reason_id"); while ($r && ($x = mysqli_fetch_row($r))) $used[(int)$x[0]] = (int)$x[1];
$reasons = prob_reasons($conn, true);
include 'header.php';
?>
<style>
.pr{padding:16px;max-width:900px}
.pr h2{margin:0 0 4px;font-size:20px;font-weight:800}.pr .sub{color:#6b7280;font-size:13px;margin-bottom:14px}
.pr-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;margin-bottom:14px}
.pr-f{display:flex;gap:8px;flex-wrap:wrap}.pr-f input{flex:1;min-width:220px;border:1.5px solid #e5e7eb;border-radius:8px;padding:8px 10px;font-size:13px}
.pr-b{border:none;border-radius:8px;padding:8px 14px;font-weight:700;font-size:13px;cursor:pointer}
.pr-b.p{background:#2563eb;color:#fff}.pr-b.g{background:#f3f4f6;color:#374151}.pr-b.r{background:#fee2e2;color:#b91c1c}
.pr-t{width:100%;border-collapse:collapse;font-size:13px}.pr-t th{background:#f9fafb;text-align:left;padding:9px 12px;font-size:11px;color:#6b7280;text-transform:uppercase}
.pr-t td{padding:9px 12px;border-top:1px solid #f3f4f6}
.al{padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:13px;font-weight:600}.al.ok{background:#dcfce7;color:#166534}.al.er{background:#fee2e2;color:#991b1b}
.st{border-radius:99px;padding:2px 9px;font-size:11px;font-weight:800}.st.a{background:#dcfce7;color:#166534}.st.i{background:#f3f4f6;color:#6b7280}
</style>
<div class="pr">
  <h2><i class="fa-solid fa-hourglass-half" style="color:#d97706"></i> Probation Extension Reasons</h2>
  <div class="sub">Reasons picked when extending an employee's probation (Employees → View → Extend Probation).</div>
  <?php if ($msg): ?><div class="al ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="al er"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <div class="pr-card">
    <form method="post" class="pr-f" id="rf">
      <input type="hidden" name="act" value="save"><input type="hidden" name="id" id="rid" value="">
      <input name="reason" id="rtxt" placeholder="New reason — e.g. Performance needs improvement" maxlength="255" required>
      <button class="pr-b p" id="rbtn" data-perm="create"><i class="fa-solid fa-plus"></i> Add</button>
      <button type="button" class="pr-b g" id="rcancel" style="display:none" onclick="cancelEdit()">Cancel</button>
    </form>
  </div>
  <div class="pr-card" style="padding:0;overflow:auto">
    <table class="pr-t"><thead><tr><th>#</th><th>Reason</th><th>Used</th><th>Status</th><th>Added</th><th></th></tr></thead><tbody>
    <?php if (!$reasons): ?><tr><td colspan="6" style="text-align:center;color:#6b7280;padding:22px">No reasons yet — add the first one above.</td></tr><?php endif; ?>
    <?php foreach ($reasons as $i => $x): ?>
      <tr><td><?= $i + 1 ?></td><td><b><?= htmlspecialchars($x['reason']) ?></b></td><td><?= (int)($used[(int)$x['id']] ?? 0) ?></td>
        <td><span class="st <?= $x['is_active'] ? 'a' : 'i' ?>"><?= $x['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td style="font-size:12px;color:#6b7280"><?= htmlspecialchars($x['created_at']) ?> <?= htmlspecialchars($x['created_by'] ?? '') ?></td>
        <td style="white-space:nowrap">
          <button class="pr-b g" data-perm="edit" onclick='editR(<?= (int)$x['id'] ?>, <?= json_encode($x['reason'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fa-solid fa-pen"></i></button>
          <form method="post" style="display:inline"><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="pr-b g" data-perm="edit" title="Active / Inactive"><i class="fa-solid fa-power-off"></i></button></form>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this reason?')"><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="pr-b r" data-perm="delete"><i class="fa-solid fa-trash"></i></button></form>
        </td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
</div>
<script>
function editR(id, t){ rid.value=id; rtxt.value=t; rbtn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save'; rcancel.style.display=''; rtxt.focus(); }
function cancelEdit(){ rid.value=''; rtxt.value=''; rbtn.innerHTML='<i class="fa-solid fa-plus"></i> Add'; rcancel.style.display='none'; }
</script>
<?php include 'footer.php'; ?>
