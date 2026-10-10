<?php
/**
 * bill_cancel_reasons.php
 * ─────────────────────────────────────────────────────────────────────
 * Master list of BILL CANCEL REASONS (add / edit / activate / delete).
 * Modelled on emergency_credit_reasons.php. The reasons are picked per bill
 * on canceled_bills.php.
 *
 *  • A reason that is already used on a canceled bill cannot be deleted
 *    (it would leave those bills without a reason) — mark it Inactive
 *    instead. Inactive reasons stay on the bills that use them but no
 *    longer appear in the dropdown for other bills.
 *  • Create / edit / delete need a login and a security token.
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');

/* config.php ends with stray whitespace after "?>" – swallow it so redirects work */
ob_start();
include_once 'config.php';
ob_end_clean();
include_once 'auth.php';
requireLogin();                       /* before any create / edit / delete can run */

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['bcr_csrf'])) $_SESSION['bcr_csrf'] = bin2hex(random_bytes(16));

function bcr_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* PHP 8.1+ throws on a failed query, older PHP returns false — behave the same on both */
function bcr_q($conn, $sql) {
    try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; }
}

/* same definitions canceled_bills.php uses */
function bcr_ensure($conn) {
    bcr_q($conn, "CREATE TABLE IF NOT EXISTS bill_cancel_reasons (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        reason     VARCHAR(255) NOT NULL,
        active     TINYINT(1)   NOT NULL DEFAULT 1,
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    bcr_q($conn, "CREATE TABLE IF NOT EXISTS canceled_bill_reasons (
        id                      INT AUTO_INCREMENT PRIMARY KEY,
        field_summary_detail_id INT          NOT NULL,
        invoice_num             VARCHAR(100) NOT NULL,
        reason_id               INT          NOT NULL,
        reason_text             VARCHAR(255) NOT NULL,
        updated_by              VARCHAR(100) NULL,
        updated_at              DATETIME     NOT NULL,
        UNIQUE KEY uq_detail (field_summary_detail_id),
        INDEX idx_reason (reason_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
bcr_ensure($conn);

/* ─────────── handle create / edit / delete ─────────── */
$flash  = null;      /* ['type' => 'ok'|'error', 'text' => ...] */
$reopen = null;      /* form values to put back in the dialog after an error */
if (!empty($_SESSION['bcr_flash'])) { $flash = $_SESSION['bcr_flash']; unset($_SESSION['bcr_flash']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)$_SESSION['bcr_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $flash = ['type' => 'error', 'text' => 'Security check failed. Reload the page and try again.'];
    } elseif (($_POST['action'] ?? '') === 'save') {

        $id      = (int)($_POST['reason_id'] ?? 0);
        $raw     = (string)($_POST['reason'] ?? '');
        $active  = isset($_POST['active']) ? 1 : 0;
        $reason  = $raw;
        $err     = '';

        if (!preg_match('//u', $reason)) {
            $err = 'The reason contains characters that cannot be saved.';
        } else {
            $reason = trim(preg_replace('/\s+/u', ' ', $reason));
            if ($reason === '')                                  $err = 'Enter a reason.';
            elseif (!preg_match('/^.{1,255}$/us', $reason))      $err = 'The reason must be 255 characters or fewer.';
            elseif (preg_match('/[\x00-\x1F\x7F]/', $reason))    $err = 'The reason contains characters that cannot be saved.';
        }

        if ($err === '') {                                       /* duplicate (case-insensitive) */
            try {
                $st = mysqli_prepare($conn, "SELECT id FROM bill_cancel_reasons WHERE reason = ? AND id <> ? LIMIT 1");
                mysqli_stmt_bind_param($st, 'si', $reason, $id);
                mysqli_stmt_execute($st);
                $rs = mysqli_stmt_get_result($st);
                if ($rs && mysqli_fetch_assoc($rs)) $err = 'This reason already exists. Use a different reason.';
                mysqli_stmt_close($st);
            } catch (Throwable $e) { $err = 'The reason could not be checked. Try again.'; }
        }

        if ($err === '' && $id > 0) {                            /* editing something that was deleted meanwhile */
            $ex = bcr_q($conn, "SELECT id FROM bill_cancel_reasons WHERE id = $id LIMIT 1");
            if (!$ex || !mysqli_fetch_assoc($ex)) $err = 'This reason no longer exists. Reload the page.';
        }

        if ($err === '') {
            try {
                if ($id > 0) {
                    $st = mysqli_prepare($conn, "UPDATE bill_cancel_reasons SET reason = ?, active = ? WHERE id = ?");
                    mysqli_stmt_bind_param($st, 'sii', $reason, $active, $id);
                } else {
                    $st = mysqli_prepare($conn, "INSERT INTO bill_cancel_reasons (reason, active) VALUES (?, ?)");
                    mysqli_stmt_bind_param($st, 'si', $reason, $active);
                }
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);
                $_SESSION['bcr_flash'] = ['type' => 'ok', 'text' => $id > 0 ? 'Reason updated.' : 'Reason added.'];
                header('Location: ' . basename(__FILE__));
                exit;
            } catch (Throwable $e) { $err = 'The reason could not be saved. Try again.'; }
        }

        $flash  = ['type' => 'error', 'text' => $err];
        $reopen = ['id' => $id, 'reason' => $raw, 'active' => $active];

    } elseif (($_POST['action'] ?? '') === 'delete') {

        $id   = (int)($_POST['reason_id'] ?? 0);
        $used = 0;
        $r = bcr_q($conn, "SELECT COUNT(*) AS n FROM canceled_bill_reasons WHERE reason_id = $id");
        if ($r) $used = (int)mysqli_fetch_assoc($r)['n'];

        if ($used > 0) {
            $flash = ['type' => 'error', 'text' => "This reason is used on $used canceled bill" . ($used === 1 ? '' : 's') . ". Set it to Inactive instead of deleting it."];
        } else {
            bcr_q($conn, "DELETE FROM bill_cancel_reasons WHERE id = $id");
            $_SESSION['bcr_flash'] = ['type' => 'ok', 'text' => 'Reason deleted.'];
            header('Location: ' . basename(__FILE__));
            exit;
        }
    }
}

$reasons = [];
$r = bcr_q($conn, "SELECT r.id, r.reason, r.active,
                          (SELECT COUNT(*) FROM canceled_bill_reasons m WHERE m.reason_id = r.id) AS used
                   FROM bill_cancel_reasons r
                   ORDER BY r.active DESC, r.reason ASC");
if ($r) while ($row = mysqli_fetch_assoc($r)) $reasons[] = $row;

include 'header.php';
?>
<style>
.bcr { font-family: 'Inter', system-ui, sans-serif; font-size: 13px; color: #111; padding: 22px 26px 48px; max-width: 900px; }
.bcr *, .bcr *::before, .bcr *::after { box-sizing: border-box; }
.bcr h1 { margin: 0; font-size: 21px; font-weight: 700; }
.bcr .sub { margin: 4px 0 18px; color: #666; }
.bcr .alert { padding: 11px 14px; border-radius: 8px; margin-bottom: 16px; font-weight: 500; }
.bcr .alert.ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.bcr .alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.bcr .btn { display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 16px; border: 1px solid transparent; border-radius: 8px; font: inherit; font-weight: 600; cursor: pointer; }
.bcr .btn-dark { background: #000; color: #fff; }
.bcr .btn-dark:hover { background: #333; }
.bcr .btn-light { background: #f5f5f5; color: #333; border-color: #e5e5e5; }
.bcr .btn-sm { height: 30px; padding: 0 12px; font-size: 12.5px; }
.bcr .btn-danger { background: #fff; color: #b91c1c; border-color: #fecaca; }
.bcr .btn:disabled { opacity: .45; cursor: not-allowed; }
.bcr .btn:focus-visible, .bcr input:focus-visible { outline: 2px solid #000; outline-offset: 2px; }
.bcr .card { margin-top: 16px; background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; overflow-x: auto; }
.bcr table { width: 100%; border-collapse: collapse; }
.bcr th { white-space: nowrap; padding: 11px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #555; background: #fafafa; border-bottom: 1px solid #e5e5e5; }
.bcr td { padding: 12px 16px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
.bcr tbody tr:last-child td { border-bottom: 0; }
.bcr .num { text-align: right; width: 110px; white-space: nowrap; }
.bcr .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.bcr .badge.on { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.bcr .badge.off { background: #fafafa; color: #666; border: 1px solid #e5e5e5; }
.bcr .actions { display: flex; gap: 8px; justify-content: flex-end; }
.bcr .actions form { margin: 0; }
.bcr .empty { padding: 32px 16px; text-align: center; color: #666; }
.bcr .modal { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.5); }
.bcr .modal[hidden] { display: none; }
.bcr .dialog { width: 100%; max-width: 480px; background: #fff; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.bcr .dialog h2 { margin: 0; padding: 18px 20px; font-size: 16px; border-bottom: 1px solid #e5e5e5; }
.bcr .dbody { padding: 18px 20px; }
.bcr .dfoot { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 20px; border-top: 1px solid #e5e5e5; background: #fafafa; border-radius: 0 0 12px 12px; }
.bcr label.lbl { display: block; margin-bottom: 6px; font-weight: 600; color: #333; }
.bcr input[type="text"] { width: 100%; height: 40px; padding: 0 12px; border: 1px solid #ddd; border-radius: 8px; font: inherit; font-size: 14px; }
.bcr .chk { display: flex; align-items: center; gap: 8px; margin-top: 16px; cursor: pointer; }
.bcr .chk input { width: 17px; height: 17px; accent-color: #000; }
.bcr .hint { display: block; margin-top: 5px; color: #666; font-size: 12px; }
</style>

<div class="bcr">
    <h1>Bill cancel reasons</h1>
    <p class="sub">Reasons your team picks for each canceled bill on the Canceled Bills report.</p>

    <?php if ($flash): ?>
        <div class="alert <?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>" role="<?php echo $flash['type'] === 'ok' ? 'status' : 'alert'; ?>"><?php echo bcr_h($flash['text']); ?></div>
    <?php endif; ?>

    <button type="button" class="btn btn-dark" id="bcrAdd">Add new reason</button>

    <div class="card">
        <?php if ($reasons): ?>
        <table>
            <thead><tr><th>Reason</th><th>Status</th><th class="num">Used on</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($reasons as $row): ?>
                <tr>
                    <td><?php echo bcr_h($row['reason']); ?></td>
                    <td><span class="badge <?php echo $row['active'] ? 'on' : 'off'; ?>"><?php echo $row['active'] ? 'Active' : 'Inactive'; ?></span></td>
                    <td class="num"><?php echo (int)$row['used']; ?> bill<?php echo (int)$row['used'] === 1 ? '' : 's'; ?></td>
                    <td>
                        <div class="actions">
                            <button type="button" class="btn btn-light btn-sm" data-edit
                                    data-id="<?php echo (int)$row['id']; ?>"
                                    data-reason="<?php echo bcr_h($row['reason']); ?>"
                                    data-active="<?php echo (int)$row['active']; ?>">Edit</button>
                            <form method="post" action="<?php echo bcr_h(basename(__FILE__)); ?>" data-delete="<?php echo bcr_h($row['reason']); ?>">
                                <input type="hidden" name="csrf" value="<?php echo bcr_h($_SESSION['bcr_csrf']); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="reason_id" value="<?php echo (int)$row['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm" <?php echo (int)$row['used'] > 0 ? 'disabled title="Used on canceled bills. Set it to Inactive instead."' : ''; ?>>Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div class="empty"><strong>No reasons yet.</strong><br>Add the reasons your team uses when a bill is canceled, then pick them on the Canceled Bills report.</div>
        <?php endif; ?>
    </div>

    <div class="modal" id="bcrModal" hidden>
        <form class="dialog" method="post" action="<?php echo bcr_h(basename(__FILE__)); ?>" role="dialog" aria-modal="true" aria-labelledby="bcrTitle">
            <h2 id="bcrTitle">Add new reason</h2>
            <div class="dbody">
                <input type="hidden" name="csrf" value="<?php echo bcr_h($_SESSION['bcr_csrf']); ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="reason_id" id="bcrId" value="">
                <label class="lbl" for="bcrReason">Reason</label>
                <input type="text" name="reason" id="bcrReason" maxlength="255" autocomplete="off" required>
                <span class="hint">A short, clear reason, for example: Customer refused the bill.</span>
                <label class="chk"><input type="checkbox" name="active" id="bcrActive" checked> Active (shown in the reason dropdown)</label>
            </div>
            <div class="dfoot">
                <button type="button" class="btn btn-light" id="bcrCancel">Cancel</button>
                <button type="submit" class="btn btn-dark" id="bcrSave">Save reason</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('bcrModal');
    var idEl = document.getElementById('bcrId'), reasonEl = document.getElementById('bcrReason'), activeEl = document.getElementById('bcrActive');
    var lastFocus = null;

    function openDialog(id, reason, active) {
        lastFocus = document.activeElement;
        idEl.value = id || '';
        reasonEl.value = reason || '';
        activeEl.checked = !!active;
        document.getElementById('bcrTitle').textContent = id ? 'Edit reason' : 'Add new reason';
        document.getElementById('bcrSave').textContent = id ? 'Update reason' : 'Save reason';
        modal.hidden = false;
        reasonEl.focus(); reasonEl.select();
    }
    function closeDialog() { modal.hidden = true; if (lastFocus && document.contains(lastFocus)) lastFocus.focus(); }

    document.getElementById('bcrAdd').addEventListener('click', function () { openDialog('', '', true); });
    document.getElementById('bcrCancel').addEventListener('click', closeDialog);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeDialog(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) closeDialog(); });

    document.querySelectorAll('[data-edit]').forEach(function (b) {
        b.addEventListener('click', function () { openDialog(b.dataset.id, b.dataset.reason, b.dataset.active === '1'); });
    });
    document.querySelectorAll('form[data-delete]').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            if (!confirm('Delete the reason "' + f.dataset.delete + '"?')) e.preventDefault();
        });
    });

    <?php if ($reopen): ?>
    openDialog(<?php echo json_encode($reopen['id'] ? (string)$reopen['id'] : ''); ?>, <?php echo json_encode((string)$reopen['reason'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo $reopen['active'] ? 'true' : 'false'; ?>);
    <?php endif; ?>
})();
</script>

<?php include 'footer.php'; ?>
