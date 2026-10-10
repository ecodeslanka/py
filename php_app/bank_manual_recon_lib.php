<?php
/**
 * bank_manual_recon_lib.php
 * Shared bits for the "Manual Reconcile with reason" feature.
 *   bmr_ensure($conn)        – creates tables / columns it needs
 *   bmr_reasons($conn)       – active reasons for the dropdown
 *   bmr_render_modal($conn)  – modal + CSS + JS (include once per page, after header.php)
 *
 * Buttons on the pages:
 *   <button class="bmr-btn"  data-id=".." data-date=".." data-desc=".." data-amt="..">Not reconciled → Reconcile</button>
 *   <button class="bmr-undo" data-id="..">Undo</button>
 */
if (!defined('BMR_SOURCE'))   define('BMR_SOURCE', 'manual_recon');
if (!defined('BMR_CATEGORY')) define('BMR_CATEGORY', 'Manual Reconcile');

function bmr_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function bmr_has_col($conn, $table, $col) {
    $r = bmr_q($conn, "SHOW COLUMNS FROM `$table` LIKE '" . mysqli_real_escape_string($conn, $col) . "'");
    return $r && mysqli_num_rows($r) > 0;
}

function bmr_ensure($conn) {
    static $done = false;
    if ($done) return;
    $done = true;

    bmr_q($conn, "CREATE TABLE IF NOT EXISTS bank_recon_reasons (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        reason VARCHAR(255) NOT NULL,
        active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_active (active)
    )");
    $r = bmr_q($conn, "SELECT COUNT(*) AS c FROM bank_recon_reasons");
    if ($r && (int)mysqli_fetch_assoc($r)['c'] === 0) {
        foreach (['Bank charges', 'Bank interest', 'Inter-account fund transfer', 'Direct deposit by customer', 'Returned cheque', 'Other'] as $x) {
            bmr_q($conn, "INSERT INTO bank_recon_reasons (reason) VALUES ('" . mysqli_real_escape_string($conn, $x) . "')");
        }
    }

    /* history of manual reconciles (also kept after undo) */
    bmr_q($conn, "CREATE TABLE IF NOT EXISTS bank_manual_recon (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        bank_txn_id INT UNSIGNED  NOT NULL,
        reason_id   INT           NOT NULL,
        reason      VARCHAR(255)  NOT NULL,
        remark      VARCHAR(500)  NULL,
        amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
        side        VARCHAR(2)    NULL,
        created_by  VARCHAR(100)  NULL,
        created_at  DATETIME      NULL,
        undone_by   VARCHAR(100)  NULL,
        undone_at   DATETIME      NULL,
        INDEX idx_txn (bank_txn_id),
        INDEX idx_reason (reason_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    /* same recon columns the other reconcile pages use */
    if (bmr_has_col($conn, 'bank_statement_transactions', 'id')) {
        foreach ([
            'recon_status'   => "VARCHAR(20)  NULL DEFAULT NULL",
            'recon_source'   => "VARCHAR(30)  NULL DEFAULT NULL",
            'recon_category' => "VARCHAR(50)  NULL DEFAULT NULL",
            'recon_ref_id'   => "INT          NULL DEFAULT NULL",
            'recon_remark'   => "TEXT         NULL",
            'recon_by'       => "VARCHAR(100) NULL DEFAULT NULL",
            'recon_at'       => "DATETIME     NULL DEFAULT NULL",
        ] as $c => $def) {
            if (!bmr_has_col($conn, 'bank_statement_transactions', $c)) {
                bmr_q($conn, "ALTER TABLE bank_statement_transactions ADD COLUMN `$c` $def");
            }
        }
    }
}

function bmr_reasons($conn) {
    $out = [];
    $r = bmr_q($conn, "SELECT id, reason FROM bank_recon_reasons WHERE active = 1 ORDER BY reason ASC");
    if ($r) while ($x = mysqli_fetch_assoc($r)) $out[] = ['id' => (int)$x['id'], 'reason' => $x['reason']];
    return $out;
}

/* button for one bank line (not reconciled → Reconcile, manual → Undo) */
function bmr_button($t) {
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    if (empty($t['recon_status'])) {
        $amt = (float)$t['credit'] > 0 ? 'Cr ' . number_format((float)$t['credit'], 2) : 'Dr ' . number_format((float)$t['debit'], 2);
        return '<button type="button" class="bmr-btn no-print" data-id="' . (int)$t['id'] . '" data-date="' . $h($t['transaction_date']) . '"'
             . ' data-desc="' . $h(trim((string)$t['description'])) . '" data-amt="' . $h($amt) . '" title="Mark as manually reconciled with a reason">'
             . '<i class="fa-solid fa-hand-pointer"></i> Reconcile</button>';
    }
    if (($t['recon_source'] ?? '') === BMR_SOURCE) {
        return '<button type="button" class="bmr-undo no-print" data-id="' . (int)$t['id'] . '" title="Remove the manual reconcile">'
             . '<i class="fa-solid fa-rotate-left"></i> Undo</button>';
    }
    return '';
}

function bmr_render_modal($conn) {
    if (empty($_SESSION['bmr_csrf'])) $_SESSION['bmr_csrf'] = bin2hex(random_bytes(16));
    $reasons = bmr_reasons($conn);
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    ?>
<style>
.bmr-btn, .bmr-undo { display: inline-flex; align-items: center; gap: 5px; margin-top: 5px; height: 26px; padding: 0 10px; border-radius: 6px; font: inherit; font-size: 11.5px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.bmr-btn  { background: #111827; color: #fff; border: 1px solid #111827; }
.bmr-btn:hover { background: #374151; }
.bmr-undo { background: #fff; color: #b91c1c; border: 1px solid #fecaca; }
.bmr-undo:hover { background: #fef2f2; }
.bmr-btn[disabled], .bmr-undo[disabled] { opacity: .5; cursor: wait; }
.bmr-ov { display: none; position: fixed; inset: 0; z-index: 10000; background: rgba(0,0,0,.45); align-items: center; justify-content: center; padding: 16px; }
.bmr-ov.on { display: flex; }
.bmr-box { background: #fff; border-radius: 12px; width: 100%; max-width: 480px; box-shadow: 0 20px 60px rgba(0,0,0,.3); font-family: 'Inter', system-ui, sans-serif; font-size: 13px; color: #111827; }
.bmr-hd { display: flex; justify-content: space-between; align-items: center; padding: 18px 20px; border-bottom: 1px solid #e5e7eb; }
.bmr-hd h3 { margin: 0; font-size: 16px; font-weight: 700; }
.bmr-x { background: none; border: 0; font-size: 20px; cursor: pointer; color: #6b7280; }
.bmr-bd { padding: 18px 20px; }
.bmr-line { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 12px; margin-bottom: 16px; }
.bmr-line .d { font-size: 12px; color: #374151; word-break: break-word; }
.bmr-line .m { display: flex; justify-content: space-between; gap: 8px; margin-top: 6px; font-size: 12px; color: #6b7280; }
.bmr-line .m b { color: #111827; font-variant-numeric: tabular-nums; }
.bmr-bd label { display: block; font-size: 12px; font-weight: 600; margin: 0 0 6px; color: #374151; }
.bmr-bd label .opt { font-weight: 400; color: #9ca3af; }
.bmr-bd select, .bmr-bd textarea { width: 100%; box-sizing: border-box; padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 8px; font: inherit; background: #fff; margin-bottom: 14px; }
.bmr-bd textarea { min-height: 70px; resize: vertical; }
.bmr-bd .hint { font-size: 11.5px; color: #6b7280; margin-top: -8px; margin-bottom: 12px; }
.bmr-bd .hint a { color: #1d4ed8; }
.bmr-err { display: none; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 8px 10px; border-radius: 8px; font-size: 12px; margin-bottom: 12px; }
.bmr-ft { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 20px; border-top: 1px solid #e5e7eb; }
.bmr-ft button { height: 36px; padding: 0 16px; border-radius: 8px; font: inherit; font-weight: 600; cursor: pointer; }
.bmr-cancel { background: #fff; border: 1px solid #d1d5db; color: #111827; }
.bmr-save { background: #15803d; border: 1px solid #15803d; color: #fff; display: inline-flex; align-items: center; gap: 7px; }
.bmr-save[disabled] { opacity: .6; cursor: wait; }
.bmr-toast { position: fixed; right: 20px; bottom: 20px; z-index: 10001; padding: 11px 16px; border-radius: 8px; color: #fff; font-size: 13px; font-weight: 600; display: none; box-shadow: 0 6px 20px rgba(0,0,0,.2); }
@media print { .bmr-btn, .bmr-undo, .bmr-ov, .bmr-toast { display: none !important; } }
</style>

<div class="bmr-ov" id="bmrOv" role="dialog" aria-modal="true" aria-labelledby="bmrTitle">
    <div class="bmr-box">
        <div class="bmr-hd">
            <h3 id="bmrTitle"><i class="fa-solid fa-hand-pointer"></i> Manual reconcile</h3>
            <button type="button" class="bmr-x" id="bmrX" aria-label="Close">&times;</button>
        </div>
        <div class="bmr-bd">
            <div class="bmr-line">
                <div class="d" id="bmrDesc"></div>
                <div class="m"><span id="bmrDate"></span><b id="bmrAmt"></b></div>
            </div>
            <div class="bmr-err" id="bmrErr"></div>
            <label for="bmrReason">Reason <span style="color:#ef4444">*</span></label>
            <select id="bmrReason">
                <option value="">— Select reason —</option>
                <?php foreach ($reasons as $r): ?>
                <option value="<?php echo (int)$r['id']; ?>"><?php echo $h($r['reason']); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="hint"><?php echo $reasons ? 'Missing a reason?' : 'No active reasons yet.'; ?> <a href="bank_recon_reasons.php" target="_blank">Manage reconcile reasons</a></div>
            <label for="bmrRemark">Remark <span class="opt">(optional)</span></label>
            <textarea id="bmrRemark" maxlength="500" placeholder="Any note about this line"></textarea>
        </div>
        <div class="bmr-ft">
            <button type="button" class="bmr-cancel" id="bmrCancel">Cancel</button>
            <button type="button" class="bmr-save" id="bmrSave"><i class="fa-solid fa-check"></i> Save as reconciled</button>
        </div>
    </div>
</div>
<div class="bmr-toast" id="bmrToast"></div>

<script>
(function () {
    'use strict';
    var CSRF = <?php echo json_encode($_SESSION['bmr_csrf']); ?>;
    var ov = document.getElementById('bmrOv'), err = document.getElementById('bmrErr');
    var sel = document.getElementById('bmrReason'), rem = document.getElementById('bmrRemark'), save = document.getElementById('bmrSave');
    var curId = 0;

    function toast(msg, ok) {
        var t = document.getElementById('bmrToast');
        t.textContent = msg; t.style.background = ok ? '#15803d' : '#b91c1c'; t.style.display = 'block';
        clearTimeout(t._t); t._t = setTimeout(function () { t.style.display = 'none'; }, 3500);
    }
    function post(data) {
        var fd = new FormData();
        fd.append('csrf', CSRF);
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch('bank_manual_recon_api.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .catch(function () { return { ok: false, msg: 'Network or server error. Please try again.' }; });
    }
    function open(b) {
        curId = parseInt(b.dataset.id, 10) || 0;
        document.getElementById('bmrDesc').textContent = b.dataset.desc || '—';
        document.getElementById('bmrDate').textContent = b.dataset.date || '';
        document.getElementById('bmrAmt').textContent  = b.dataset.amt || '';
        sel.value = ''; rem.value = ''; err.style.display = 'none';
        save.disabled = false;
        ov.classList.add('on');
        setTimeout(function () { sel.focus(); }, 30);
    }
    function close() { ov.classList.remove('on'); curId = 0; }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('.bmr-btn');
        if (b) { e.preventDefault(); e.stopPropagation(); open(b); return; }
        var u = e.target.closest('.bmr-undo');
        if (u) {
            e.preventDefault(); e.stopPropagation();
            if (!confirm('Remove the manual reconcile from this bank line?')) return;
            u.disabled = true;
            post({ action: 'undo', txn_id: u.dataset.id }).then(function (res) {
                toast(res.msg, res.ok);
                if (res.ok) setTimeout(function () { location.reload(); }, 600); else u.disabled = false;
            });
        }
    });
    document.getElementById('bmrX').addEventListener('click', close);
    document.getElementById('bmrCancel').addEventListener('click', close);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && ov.classList.contains('on')) close(); });

    save.addEventListener('click', function () {
        if (!sel.value) { err.textContent = 'Please select a reason.'; err.style.display = 'block'; sel.focus(); return; }
        save.disabled = true; err.style.display = 'none';
        post({ action: 'save', txn_id: curId, reason_id: sel.value, remark: rem.value }).then(function (res) {
            if (!res.ok) { err.textContent = res.msg; err.style.display = 'block'; save.disabled = false; return; }
            close(); toast(res.msg, true);
            setTimeout(function () { location.reload(); }, 600);
        });
    });
})();
</script>
    <?php
}
