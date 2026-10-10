<?php
/**
 * field_summary_risk_bill_reasons.php
 * ─────────────────────────────────────────────────────────────────────
 * Master list of FIELD SUMMARY RISK BILL REASONS (add / edit / delete).
 * Modelled on bill_cancel_reasons.php.
 *
 *  • Each reason has a SHORT CODE (e.g. OVL, RTC) and a full REASON text.
 *  • Short codes are stored in upper case and must be unique. Spaces are allowed
 *    inside a code (e.g. "NO CR"); extra spaces are squeezed to one.
 *  • Reason text must be unique too (case-insensitive).
 *  • Inactive reasons stay in the list but should not be offered for new
 *    bills (query with WHERE active = 1 when you build a dropdown).
 *  • Create / edit / delete need a login and a security token.
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');

/* config.php ends with stray whitespace after "?>" – swallow it so redirects work */
ob_start();
include_once 'config.php';
ob_end_clean();
include_once 'auth.php';
requireLogin();

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['fsrr_csrf'])) $_SESSION['fsrr_csrf'] = bin2hex(random_bytes(16));

function fsrr_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* PHP 8.1+ throws on a failed query, older PHP returns false — behave the same on both */
function fsrr_q($conn, $sql) {
    try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; }
}

function fsrr_ensure($conn) {
    fsrr_q($conn, "CREATE TABLE IF NOT EXISTS field_summary_risk_bill_reasons (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        short_code VARCHAR(20)  NOT NULL,
        reason     VARCHAR(255) NOT NULL,
        active     TINYINT(1)   NOT NULL DEFAULT 1,
        created_by VARCHAR(100) NULL,
        updated_by VARCHAR(100) NULL,
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_short_code (short_code),
        INDEX idx_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
fsrr_ensure($conn);

/* how many bills on risk_customer_bills_report.php use each reason: reason_id => count */
function fsrr_used($conn) {
    $out = [];
    $r = fsrr_q($conn, "SELECT reason_id, COUNT(*) AS n FROM risk_customer_bill_remarks WHERE reason_id IS NOT NULL GROUP BY reason_id");
    if ($r) while ($x = mysqli_fetch_assoc($r)) $out[(int)$x['reason_id']] = (int)$x['n'];
    return $out;
}

/* who is logged in (same session keys other pages use; falls back gracefully) */
$fsrr_user = (string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ?? $_SESSION['user_id'] ?? '');

/* ─────────── handle create / edit / delete ─────────── */
$flash  = null;      /* ['type' => 'ok'|'error', 'text' => ...] */
$reopen = null;      /* form values to put back in the dialog after an error */
if (!empty($_SESSION['fsrr_flash'])) { $flash = $_SESSION['fsrr_flash']; unset($_SESSION['fsrr_flash']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)$_SESSION['fsrr_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $flash = ['type' => 'error', 'text' => 'Security check failed. Reload the page and try again.'];
    } elseif (($_POST['action'] ?? '') === 'save') {

        $id       = (int)($_POST['reason_id'] ?? 0);
        $raw_code = (string)($_POST['short_code'] ?? '');
        $raw      = (string)($_POST['reason'] ?? '');
        $active   = isset($_POST['active']) ? 1 : 0;
        $err      = '';

        /* short code: upper case, any characters including spaces, 1–20 chars.
           Spaces inside the code are allowed; leading/trailing spaces are removed and
           several spaces in a row are squeezed to one. */
        $code = preg_match('//u', $raw_code) ? mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $raw_code)), 'UTF-8') : '';
        if ($code === '')                                      $err = 'Enter a short code.';
        elseif (mb_strlen($code, 'UTF-8') > 20)                $err = 'The short code must be 20 characters or fewer.';
        elseif (preg_match('/[\x00-\x1F\x7F]/', $code))       $err = 'The short code contains characters that cannot be saved.';

        /* reason text */
        $reason = $raw;
        if ($err === '') {
            if (!preg_match('//u', $reason)) {
                $err = 'The reason contains characters that cannot be saved.';
            } else {
                $reason = trim(preg_replace('/\s+/u', ' ', $reason));
                if ($reason === '')                                  $err = 'Enter a reason.';
                elseif (!preg_match('/^.{1,255}$/us', $reason))      $err = 'The reason must be 255 characters or fewer.';
                elseif (preg_match('/[\x00-\x1F\x7F]/', $reason))    $err = 'The reason contains characters that cannot be saved.';
            }
        }

        /* duplicates */
        if ($err === '') {
            try {
                $st = mysqli_prepare($conn, "SELECT id FROM field_summary_risk_bill_reasons WHERE short_code = ? AND id <> ? LIMIT 1");
                mysqli_stmt_bind_param($st, 'si', $code, $id);
                mysqli_stmt_execute($st);
                $rs = mysqli_stmt_get_result($st);
                if ($rs && mysqli_fetch_assoc($rs)) $err = 'The short code "' . $code . '" is already used. Use a different code.';
                mysqli_stmt_close($st);

                if ($err === '') {
                    $st = mysqli_prepare($conn, "SELECT id FROM field_summary_risk_bill_reasons WHERE reason = ? AND id <> ? LIMIT 1");
                    mysqli_stmt_bind_param($st, 'si', $reason, $id);
                    mysqli_stmt_execute($st);
                    $rs = mysqli_stmt_get_result($st);
                    if ($rs && mysqli_fetch_assoc($rs)) $err = 'This reason already exists. Use a different reason.';
                    mysqli_stmt_close($st);
                }
            } catch (Throwable $e) { $err = 'The reason could not be checked. Try again.'; }
        }

        if ($err === '' && $id > 0) {                            /* editing something that was deleted meanwhile */
            $ex = fsrr_q($conn, "SELECT id FROM field_summary_risk_bill_reasons WHERE id = $id LIMIT 1");
            if (!$ex || !mysqli_fetch_assoc($ex)) $err = 'This reason no longer exists. Reload the page.';
        }

        if ($err === '') {
            try {
                if ($id > 0) {
                    $st = mysqli_prepare($conn, "UPDATE field_summary_risk_bill_reasons SET short_code = ?, reason = ?, active = ?, updated_by = ? WHERE id = ?");
                    mysqli_stmt_bind_param($st, 'ssisi', $code, $reason, $active, $fsrr_user, $id);
                } else {
                    $st = mysqli_prepare($conn, "INSERT INTO field_summary_risk_bill_reasons (short_code, reason, active, created_by, updated_by) VALUES (?, ?, ?, ?, ?)");
                    mysqli_stmt_bind_param($st, 'ssiss', $code, $reason, $active, $fsrr_user, $fsrr_user);
                }
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);
                $_SESSION['fsrr_flash'] = ['type' => 'ok', 'text' => $id > 0 ? 'Reason updated.' : 'Reason added.'];
                header('Location: ' . basename(__FILE__));
                exit;
            } catch (Throwable $e) { $err = 'The reason could not be saved. Try again.'; }
        }

        $flash  = ['type' => 'error', 'text' => $err];
        $reopen = ['id' => $id, 'short_code' => $raw_code, 'reason' => $raw, 'active' => $active];

    } elseif (($_POST['action'] ?? '') === 'delete') {

        $id   = (int)($_POST['reason_id'] ?? 0);
        $used = fsrr_used($conn)[$id] ?? 0;
        if ($used > 0) {
            $flash = ['type' => 'error', 'text' => "This reason is used on $used bill" . ($used === 1 ? '' : 's') . " in the Risk Customer Bills report. Set it to Inactive instead of deleting it."];
        } elseif ($id > 0 && fsrr_q($conn, "DELETE FROM field_summary_risk_bill_reasons WHERE id = $id")) {
            $_SESSION['fsrr_flash'] = ['type' => 'ok', 'text' => 'Reason deleted.'];
            header('Location: ' . basename(__FILE__));
            exit;
        } else {
            $flash = ['type' => 'error', 'text' => 'The reason could not be deleted. Try again.'];
        }
    }
}

$reasons = [];
$r = fsrr_q($conn, "SELECT id, short_code, reason, active, updated_by, updated_at
                    FROM field_summary_risk_bill_reasons
                    ORDER BY active DESC, short_code ASC");
$used_map = fsrr_used($conn);
if ($r) while ($row = mysqli_fetch_assoc($r)) { $row['used'] = $used_map[(int)$row['id']] ?? 0; $reasons[] = $row; }

include 'header.php';
?>
<style>
.fsrr { font-family: 'Inter', system-ui, sans-serif; font-size: 13px; color: #111; padding: 22px 26px 48px; max-width: 1000px; }
.fsrr *, .fsrr *::before, .fsrr *::after { box-sizing: border-box; }
.fsrr h1 { margin: 0; font-size: 21px; font-weight: 700; }
.fsrr .sub { margin: 4px 0 18px; color: #666; }
.fsrr .alert { padding: 11px 14px; border-radius: 8px; margin-bottom: 16px; font-weight: 500; }
.fsrr .alert.ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.fsrr .alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.fsrr .toolbar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.fsrr .toolbar input[type="search"] { height: 36px; width: 260px; max-width: 100%; padding: 0 12px; border: 1px solid #ddd; border-radius: 8px; font: inherit; }
.fsrr .btn { display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 16px; border: 1px solid transparent; border-radius: 8px; font: inherit; font-weight: 600; cursor: pointer; }
.fsrr .btn-dark { background: #000; color: #fff; }
.fsrr .btn-dark:hover { background: #333; }
.fsrr .btn-light { background: #f5f5f5; color: #333; border-color: #e5e5e5; }
.fsrr .btn-sm { height: 30px; padding: 0 12px; font-size: 12.5px; }
.fsrr .btn-danger { background: #fff; color: #b91c1c; border-color: #fecaca; }
.fsrr .btn:focus-visible, .fsrr input:focus-visible { outline: 2px solid #000; outline-offset: 2px; }
.fsrr .card { margin-top: 16px; background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; overflow-x: auto; }
.fsrr table { width: 100%; border-collapse: collapse; }
.fsrr th { white-space: nowrap; padding: 11px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #555; background: #fafafa; border-bottom: 1px solid #e5e5e5; }
.fsrr td { padding: 12px 16px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
.fsrr tbody tr:last-child td { border-bottom: 0; }
.fsrr .code { display: inline-block; padding: 3px 9px; border-radius: 6px; background: #111; color: #fff; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; font-weight: 600; letter-spacing: .3px; }
.fsrr .muted { color: #777; font-size: 12px; white-space: nowrap; }
.fsrr .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.fsrr .badge.on { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.fsrr .badge.off { background: #fafafa; color: #666; border: 1px solid #e5e5e5; }
.fsrr .actions { display: flex; gap: 8px; justify-content: flex-end; }
.fsrr .actions form { margin: 0; }
.fsrr .empty { padding: 32px 16px; text-align: center; color: #666; }
.fsrr .modal { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.5); }
.fsrr .modal[hidden] { display: none; }
.fsrr .dialog { width: 100%; max-width: 500px; background: #fff; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.fsrr .dialog h2 { margin: 0; padding: 18px 20px; font-size: 16px; border-bottom: 1px solid #e5e5e5; }
.fsrr .dbody { padding: 18px 20px; }
.fsrr .dfoot { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 20px; border-top: 1px solid #e5e5e5; background: #fafafa; border-radius: 0 0 12px 12px; }
.fsrr .field + .field { margin-top: 16px; }
.fsrr label.lbl { display: block; margin-bottom: 6px; font-weight: 600; color: #333; }
.fsrr .dbody input[type="text"] { width: 100%; height: 40px; padding: 0 12px; border: 1px solid #ddd; border-radius: 8px; font: inherit; font-size: 14px; }
.fsrr #fsrrCode { text-transform: uppercase; font-family: ui-monospace, Menlo, Consolas, monospace; max-width: 200px; }
.fsrr .chk { display: flex; align-items: center; gap: 8px; margin-top: 16px; cursor: pointer; }
.fsrr .chk input { width: 17px; height: 17px; accent-color: #000; }
.fsrr .hint { display: block; margin-top: 5px; color: #666; font-size: 12px; }
</style>

<div class="fsrr">
    <h1>Field summary risk bill reasons</h1>
    <p class="sub">Short codes and reasons used to mark risk bills on the field summary.</p>

    <?php if ($flash): ?>
        <div class="alert <?php echo $flash['type'] === 'ok' ? 'ok' : 'error'; ?>" role="<?php echo $flash['type'] === 'ok' ? 'status' : 'alert'; ?>"><?php echo fsrr_h($flash['text']); ?></div>
    <?php endif; ?>

    <div class="toolbar">
        <button type="button" class="btn btn-dark" id="fsrrAdd">Add new reason</button>
        <?php if ($reasons): ?>
            <input type="search" id="fsrrSearch" placeholder="Search code or reason…" aria-label="Search code or reason">
        <?php endif; ?>
    </div>

    <div class="card">
        <?php if ($reasons): ?>
        <table>
            <thead><tr><th>#</th><th>Short code</th><th>Reason</th><th>Status</th><th>Used on</th><th>Last updated</th><th></th></tr></thead>
            <tbody id="fsrrBody">
            <?php $n = 0; foreach ($reasons as $row): $n++; ?>
                <tr data-search="<?php echo fsrr_h(strtolower($row['short_code'] . ' ' . $row['reason'])); ?>">
                    <td class="muted"><?php echo $n; ?></td>
                    <td><span class="code"><?php echo fsrr_h($row['short_code']); ?></span></td>
                    <td><?php echo fsrr_h($row['reason']); ?></td>
                    <td><span class="badge <?php echo $row['active'] ? 'on' : 'off'; ?>"><?php echo $row['active'] ? 'Active' : 'Inactive'; ?></span></td>
                    <td class="muted"><?php echo (int)$row['used']; ?> bill<?php echo (int)$row['used'] === 1 ? '' : 's'; ?></td>
                    <td class="muted"><?php echo fsrr_h($row['updated_at'] ? date('Y-m-d H:i', strtotime($row['updated_at'])) : ''); ?><?php echo $row['updated_by'] !== null && $row['updated_by'] !== '' ? '<br>' . fsrr_h($row['updated_by']) : ''; ?></td>
                    <td>
                        <div class="actions">
                            <button type="button" class="btn btn-light btn-sm" data-edit
                                    data-id="<?php echo (int)$row['id']; ?>"
                                    data-code="<?php echo fsrr_h($row['short_code']); ?>"
                                    data-reason="<?php echo fsrr_h($row['reason']); ?>"
                                    data-active="<?php echo (int)$row['active']; ?>">Edit</button>
                            <form method="post" action="<?php echo fsrr_h(basename(__FILE__)); ?>" data-delete="<?php echo fsrr_h($row['short_code'] . ' – ' . $row['reason']); ?>">
                                <input type="hidden" name="csrf" value="<?php echo fsrr_h($_SESSION['fsrr_csrf']); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="reason_id" value="<?php echo (int)$row['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm" <?php echo (int)$row['used'] > 0 ? 'disabled title="Used on bills. Set it to Inactive instead."' : ''; ?>>Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="empty" id="fsrrNoMatch" hidden>No reasons match your search.</div>
        <?php else: ?>
            <div class="empty"><strong>No reasons yet.</strong><br>Add a short code and reason for each kind of risk bill.</div>
        <?php endif; ?>
    </div>

    <div class="modal" id="fsrrModal" hidden>
        <form class="dialog" method="post" action="<?php echo fsrr_h(basename(__FILE__)); ?>" role="dialog" aria-modal="true" aria-labelledby="fsrrTitle">
            <h2 id="fsrrTitle">Add new reason</h2>
            <div class="dbody">
                <input type="hidden" name="csrf" value="<?php echo fsrr_h($_SESSION['fsrr_csrf']); ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="reason_id" id="fsrrId" value="">
                <div class="field">
                    <label class="lbl" for="fsrrCode">Short code</label>
                    <input type="text" name="short_code" id="fsrrCode" maxlength="20" autocomplete="off" required>
                    <span class="hint">Up to 20 characters. Spaces are allowed. Example: OVL or NO CR</span>
                </div>
                <div class="field">
                    <label class="lbl" for="fsrrReason">Reason</label>
                    <input type="text" name="reason" id="fsrrReason" maxlength="255" autocomplete="off" required>
                    <span class="hint">Example: Customer is over the credit limit.</span>
                </div>
                <label class="chk"><input type="checkbox" name="active" id="fsrrActive" checked> Active (shown when choosing a reason)</label>
            </div>
            <div class="dfoot">
                <button type="button" class="btn btn-light" id="fsrrCancel">Cancel</button>
                <button type="submit" class="btn btn-dark" id="fsrrSave">Save reason</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('fsrrModal');
    var idEl = document.getElementById('fsrrId'), codeEl = document.getElementById('fsrrCode'),
        reasonEl = document.getElementById('fsrrReason'), activeEl = document.getElementById('fsrrActive');
    var lastFocus = null;

    function openDialog(id, code, reason, active) {
        lastFocus = document.activeElement;
        idEl.value = id || '';
        codeEl.value = code || '';
        reasonEl.value = reason || '';
        activeEl.checked = !!active;
        document.getElementById('fsrrTitle').textContent = id ? 'Edit reason' : 'Add new reason';
        document.getElementById('fsrrSave').textContent = id ? 'Update reason' : 'Save reason';
        modal.hidden = false;
        codeEl.focus(); codeEl.select();
    }
    function closeDialog() { modal.hidden = true; if (lastFocus && document.contains(lastFocus)) lastFocus.focus(); }

    codeEl.addEventListener('input', function () {
        var p = codeEl.selectionStart;
        codeEl.value = codeEl.value.toUpperCase();   /* spaces allowed; extra spaces are tidied when saved */
        try { codeEl.setSelectionRange(p, p); } catch (e) {}
    });

    document.getElementById('fsrrAdd').addEventListener('click', function () { openDialog('', '', '', true); });
    document.getElementById('fsrrCancel').addEventListener('click', closeDialog);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeDialog(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) closeDialog(); });

    document.querySelectorAll('[data-edit]').forEach(function (b) {
        b.addEventListener('click', function () { openDialog(b.dataset.id, b.dataset.code, b.dataset.reason, b.dataset.active === '1'); });
    });
    document.querySelectorAll('form[data-delete]').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            if (!confirm('Delete the reason "' + f.dataset.delete + '"?')) e.preventDefault();
        });
    });

    var search = document.getElementById('fsrrSearch');
    if (search) {
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase(), shown = 0;
            document.querySelectorAll('#fsrrBody tr').forEach(function (tr) {
                var ok = q === '' || tr.dataset.search.indexOf(q) !== -1;
                tr.hidden = !ok; if (ok) shown++;
            });
            document.getElementById('fsrrNoMatch').hidden = shown !== 0;
        });
    }

    <?php if ($reopen): ?>
    openDialog(<?php echo json_encode($reopen['id'] ? (string)$reopen['id'] : ''); ?>,
               <?php echo json_encode((string)$reopen['short_code'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
               <?php echo json_encode((string)$reopen['reason'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
               <?php echo $reopen['active'] ? 'true' : 'false'; ?>);
    <?php endif; ?>
})();
</script>

<?php include 'footer.php'; ?>
