<?php
/**
 * patch.php
 * Standalone DB patch utility — adds/removes the Collector Details columns
 * (collected_by, delivery_person, sr_code, employee_id, employee_name)
 * on cheque_settlement_payments (used by return_cheques.php settlement modal).
 *
 * Apply  → ALTER TABLE ... ADD COLUMN (idempotent, SHOW COLUMNS guarded)
 * Reverse→ ALTER TABLE ... DROP COLUMN (idempotent, SHOW COLUMNS guarded)
 */

/* ══════════════════════════════════════════════════════
   AJAX-SAFE FATAL ERROR TRAP
   Any uncaught fatal error / exception during config.php
   or the queries below would otherwise leak an HTML error
   page to fetch() and break JSON.parse() on the client.
   This converts it into a clean JSON error instead.
══════════════════════════════════════════════════════ */
$__IS_AJAX = (isset($_GET['ajax']) || isset($_POST['ajax_action']));
if ($__IS_AJAX) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    header('Content-Type: application/json');
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            while (ob_get_level() > 0) { @ob_end_clean(); }
            echo json_encode([
                'success' => false,
                'error'   => 'Server error: ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')',
            ]);
        }
    });
}

const PATCH_TABLE = 'cheque_settlement_payments';
const PATCH_COLUMNS = [
    'collected_by'    => "VARCHAR(20)  DEFAULT NULL",
    'delivery_person' => "VARCHAR(100) DEFAULT NULL",
    'sr_code'         => "VARCHAR(50)  DEFAULT NULL",
    'employee_id'     => "INT          DEFAULT NULL",
    'employee_name'   => "VARCHAR(150) DEFAULT NULL",
];

/* ══════════════════════════════════════════════════════
   AJAX — patch status (which columns currently exist)
══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'patch_status') {
    mysqli_report(MYSQLI_REPORT_OFF);
    ob_start(); include_once 'config.php'; ob_end_clean();
    header('Content-Type: application/json');

    try {
        $tbl_chk = mysqli_query($conn, "SHOW TABLES LIKE '" . PATCH_TABLE . "'");
        $table_exists = $tbl_chk && mysqli_num_rows($tbl_chk) > 0;

        $status = [];
        foreach (PATCH_COLUMNS as $col => $def) {
            $exists = false;
            if ($table_exists) {
                $chk = mysqli_query($conn, "SHOW COLUMNS FROM `" . PATCH_TABLE . "` LIKE '$col'");
                $exists = $chk && mysqli_num_rows($chk) > 0;
            }
            $status[$col] = $exists;
        }
        $all_applied = $table_exists && !in_array(false, $status, true);
        $none_applied = !$table_exists || !in_array(true, $status, true);

        echo json_encode([
            'success'      => true,
            'table_exists' => $table_exists,
            'columns'      => $status,
            'all_applied'  => $all_applied,
            'none_applied' => $none_applied,
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Status check failed: ' . $e->getMessage()]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════
   AJAX — apply patch (add columns)
══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'apply_patch') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');

    /* Ensure base table exists before patching (defensive — should already exist via return_cheques.php) */
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `" . PATCH_TABLE . "` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cheque_id INT NOT NULL,
        payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
        payment_date DATE NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        reference_no VARCHAR(100) DEFAULT NULL,
        remarks TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_csp_cid (cheque_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $added = [];
    $errors = [];
    foreach (PATCH_COLUMNS as $col => $def) {
        $chk = mysqli_query($conn, "SHOW COLUMNS FROM `" . PATCH_TABLE . "` LIKE '$col'");
        if (!$chk || mysqli_num_rows($chk) === 0) {
            $ok = mysqli_query($conn, "ALTER TABLE `" . PATCH_TABLE . "` ADD COLUMN `$col` $def");
            if ($ok) $added[] = $col;
            else $errors[] = "$col: " . mysqli_error($conn);
        }
    }

    if (empty($errors)) {
        echo json_encode([
            'success' => true,
            'added'   => $added,
            'message' => $added ? ('Patch applied. Added: ' . implode(', ', $added)) : 'Already up to date — nothing to add.',
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'added'   => $added,
            'error'   => implode(' | ', $errors),
        ]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════
   AJAX — reverse patch (drop columns)
══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'reverse_patch') {
    ob_start(); include_once 'config.php'; mysqli_report(MYSQLI_REPORT_OFF); ob_end_clean();
    header('Content-Type: application/json');

    $tbl_chk = mysqli_query($conn, "SHOW TABLES LIKE '" . PATCH_TABLE . "'");
    if (!$tbl_chk || mysqli_num_rows($tbl_chk) === 0) {
        echo json_encode(['success' => true, 'removed' => [], 'message' => 'Table does not exist — nothing to reverse.']);
        exit;
    }

    $removed = [];
    $errors = [];
    foreach (array_keys(PATCH_COLUMNS) as $col) {
        $chk = mysqli_query($conn, "SHOW COLUMNS FROM `" . PATCH_TABLE . "` LIKE '$col'");
        if ($chk && mysqli_num_rows($chk) > 0) {
            $ok = mysqli_query($conn, "ALTER TABLE `" . PATCH_TABLE . "` DROP COLUMN `$col`");
            if ($ok) $removed[] = $col;
            else $errors[] = "$col: " . mysqli_error($conn);
        }
    }

    if (empty($errors)) {
        echo json_encode([
            'success' => true,
            'removed' => $removed,
            'message' => $removed ? ('Patch reversed. Removed: ' . implode(', ', $removed)) : 'Nothing to remove — columns not present.',
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'removed' => $removed,
            'error'   => implode(' | ', $errors),
        ]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════
   NORMAL PAGE
══════════════════════════════════════════════════════ */
include 'config.php';
include 'header.php';
?>
<style>
*,*::before,*::after{box-sizing:border-box}
.patch-wrap{max-width:760px;margin:0 auto;}
.page-title{font-size:22px;font-weight:800;color:#1e1b4b;margin:0 0 4px}
.page-subtitle{font-size:13px;color:#6b7280;margin:0 0 20px}
.patch-card{background:#fff;border:1px solid #e5e5e5;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.05);overflow:hidden;margin-bottom:18px}
.patch-card-header{padding:14px 20px;background:#fffbeb;border-bottom:1px solid #fde68a;display:flex;align-items:center;gap:10px}
.patch-card-header i{color:#d97706;font-size:16px}
.patch-card-header h3{margin:0;font-size:14px;font-weight:800;color:#92400e}
.patch-card-body{padding:18px 20px}
.patch-meta{font-size:12px;color:#6b7280;margin-bottom:14px;display:flex;gap:16px;flex-wrap:wrap}
.patch-meta strong{color:#374151}
.col-list{border:1px solid #f0f0f0;border-radius:9px;overflow:hidden;margin-bottom:18px}
.col-row{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #f5f5f5;font-size:13px}
.col-row:last-child{border-bottom:none}
.col-name{font-family:'Courier New',monospace;font-weight:700;color:#374151}
.col-def{font-size:11px;color:#9ca3af;margin-left:8px}
.col-status{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
.col-status.yes{background:#dcfce7;color:#166534;border:1px solid #86efac}
.col-status.no{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.col-status.loading{background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb}
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .2s;white-space:nowrap}
.btn-apply{background:linear-gradient(135deg,#16a34a,#22c55e);color:#fff}
.btn-apply:hover{filter:brightness(1.07)}
.btn-apply:disabled{opacity:.5;cursor:not-allowed}
.btn-reverse{background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff}
.btn-reverse:hover{filter:brightness(1.07)}
.btn-reverse:disabled{opacity:.5;cursor:not-allowed}
.btn-refresh{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5}
.btn-refresh:hover{background:#e8e8e8}
.btn-row{display:flex;gap:10px;flex-wrap:wrap}
.patch-log{margin-top:18px;background:#0f172a;border-radius:9px;padding:12px 16px;font-family:'Courier New',monospace;font-size:11.5px;color:#94a3b8;max-height:180px;overflow-y:auto;display:none}
.patch-log.show{display:block}
.patch-log .log-line{padding:2px 0;border-bottom:1px solid rgba(255,255,255,.05)}
.patch-log .log-ok{color:#4ade80}
.patch-log .log-err{color:#f87171}
.spinner{border:3px solid rgba(255,255,255,.3);border-top:3px solid #fff;border-radius:50%;width:14px;height:14px;animation:spin 1s linear infinite;display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}
#patchToast{position:fixed;bottom:28px;right:28px;z-index:99999;padding:12px 22px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 6px 24px rgba(0,0,0,.2);color:#fff;transform:translateY(80px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none}
#patchToast.show{transform:translateY(0);opacity:1}
</style>

<div class="patch-wrap">
  <h2 class="page-title"><i class="fa-solid fa-database" style="color:#d97706;"></i> DB Patch — Collector Columns</h2>
  <p class="page-subtitle">Adds/removes the Collector Details columns on <code>cheque_settlement_payments</code>, used by the Settlement modal in <code>return_cheques.php</code>.</p>

  <div class="patch-card">
    <div class="patch-card-header">
      <i class="fa-solid fa-table-columns"></i>
      <h3>Target Table: cheque_settlement_payments</h3>
    </div>
    <div class="patch-card-body">
      <div class="patch-meta">
        <span>Table exists: <strong id="metaTableExists">checking…</strong></span>
        <span>Status: <strong id="metaOverallStatus">checking…</strong></span>
      </div>

      <div class="col-list" id="colList">
        <div class="col-row"><span style="color:#9ca3af;font-size:12px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading column status…</span></div>
      </div>

      <div class="btn-row">
        <button type="button" class="btn btn-apply" id="btnApply" onclick="runPatch('apply')">
          <i class="fa-solid fa-cloud-arrow-up"></i> Apply Patch
        </button>
        <button type="button" class="btn btn-reverse" id="btnReverse" onclick="runPatch('reverse')">
          <i class="fa-solid fa-rotate-left"></i> Reverse Patch
        </button>
        <button type="button" class="btn btn-refresh" onclick="loadStatus()">
          <i class="fa-solid fa-arrows-rotate"></i> Refresh Status
        </button>
      </div>

      <div class="patch-log" id="patchLog"></div>
    </div>
  </div>
</div>

<div id="patchToast"></div>

<script>
const PATCH_COLUMNS = <?php echo json_encode(array_map(function($def){ return $def; }, PATCH_COLUMNS)); ?>;

function escH(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function logLine(msg, type){
    const log = document.getElementById('patchLog');
    log.classList.add('show');
    const div = document.createElement('div');
    div.className = 'log-line' + (type === 'ok' ? ' log-ok' : type === 'err' ? ' log-err' : '');
    const ts = new Date().toLocaleTimeString('en-GB');
    div.textContent = '[' + ts + '] ' + msg;
    log.appendChild(div);
    log.scrollTop = log.scrollHeight;
}

function showToast(msg, type){
    const t = document.getElementById('patchToast');
    t.style.background = type === 'ok' ? '#166534' : '#dc2626';
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(t._t);
    t._t = setTimeout(() => t.classList.remove('show'), 3400);
}

function renderColumns(columns, tableExists){
    const wrap = document.getElementById('colList');
    let html = '';
    Object.keys(PATCH_COLUMNS).forEach(col => {
        const exists = !!columns[col];
        const statusCls = !tableExists ? 'loading' : (exists ? 'yes' : 'no');
        const statusTxt = !tableExists ? 'No Table' : (exists ? 'Applied' : 'Missing');
        const statusIcon = !tableExists ? 'fa-circle-question' : (exists ? 'fa-circle-check' : 'fa-circle-xmark');
        html += `<div class="col-row">
            <div><span class="col-name">${escH(col)}</span><span class="col-def">${escH(PATCH_COLUMNS[col])}</span></div>
            <span class="col-status ${statusCls}"><i class="fa-solid ${statusIcon}"></i> ${statusTxt}</span>
        </div>`;
    });
    wrap.innerHTML = html;
}

async function loadStatus(){
    document.getElementById('metaTableExists').textContent = 'checking…';
    document.getElementById('metaOverallStatus').textContent = 'checking…';
    try{
        const res = await fetch('patch.php?ajax=patch_status');
        const data = await res.json();
        if(!data.success){ showToast('Failed to load status','err'); return; }
        document.getElementById('metaTableExists').textContent = data.table_exists ? 'Yes' : 'No';
        document.getElementById('metaOverallStatus').textContent = data.all_applied
            ? 'Fully Patched'
            : (data.none_applied ? 'Not Patched' : 'Partially Patched');
        renderColumns(data.columns, data.table_exists);

        const btnApply = document.getElementById('btnApply');
        const btnReverse = document.getElementById('btnReverse');
        btnApply.disabled = !!data.all_applied;
        btnReverse.disabled = !!data.none_applied;
    }catch(e){
        showToast('Network error: ' + e.message, 'err');
        document.getElementById('colList').innerHTML = `<div class="col-row"><span style="color:#dc2626;font-size:12px;"><i class="fa-solid fa-triangle-exclamation"></i> Could not load status</span></div>`;
    }
}

async function runPatch(mode){
    const isApply = mode === 'apply';
    const label = isApply ? 'Apply' : 'Reverse';
    const confirmMsg = isApply
        ? 'Apply patch? This will ADD the collector columns to cheque_settlement_payments.'
        : 'Reverse patch? This will DROP the collector columns from cheque_settlement_payments — any saved collector data on existing rows will be lost.';
    if(!confirm(confirmMsg)) return;

    const btn = document.getElementById(isApply ? 'btnApply' : 'btnReverse');
    const otherBtn = document.getElementById(isApply ? 'btnReverse' : 'btnApply');
    const origHtml = btn.innerHTML;
    btn.disabled = true; otherBtn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> ' + label + 'ing…';

    logLine(label + ' patch requested…');

    try{
        const fd = new FormData();
        fd.append('ajax_action', isApply ? 'apply_patch' : 'reverse_patch');
        const res = await fetch('patch.php', { method: 'POST', body: fd });
        const data = await res.json();

        if(data.success){
            logLine(data.message || (label + ' completed.'), 'ok');
            showToast(data.message || (label + ' completed'), 'ok');
        } else {
            logLine('Error: ' + (data.error || 'Unknown error'), 'err');
            showToast(label + ' failed — see log', 'err');
        }
    }catch(e){
        logLine('Network error: ' + e.message, 'err');
        showToast('Network error: ' + e.message, 'err');
    }finally{
        btn.innerHTML = origHtml;
        await loadStatus();
    }
}

document.addEventListener('DOMContentLoaded', loadStatus);
</script>

<?php include 'footer.php'; ?>