<?php
/**
 * cheque_edit.php — Cheque Edit Page
 * - Loads cheque by ?id=N
 * - All editable fields with before/after diff tracking
 * - Every save writes detailed rows to cheque_logs
 * - Shows full activity log in right panel
 * - Bank + Branch Select2 dropdowns
 */

if (session_status() === PHP_SESSION_NONE) session_start();

function get_current_user_label() {
    return $_SESSION['username']   ??
           $_SESSION['user_name']  ??
           $_SESSION['name']       ??
           $_SESSION['full_name']  ??
           $_SESSION['email']      ??
           (isset($_SESSION['user_id']) ? 'User #'.$_SESSION['user_id'] : 'system');
}

/* ══════════════════════════════════════════════════════
   AJAX — SAVE handler (must be before header.php)
══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_cheque_edit') {
    include_once 'config.php';
    header('Content-Type: application/json');

    $id = intval($_POST['cheque_id'] ?? 0);
    if (!$id) { echo json_encode(['success' => false, 'error' => 'Invalid cheque ID']); exit; }

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cheque_id INT NOT NULL,
        action VARCHAR(100) NOT NULL,
        old_value TEXT,
        new_value TEXT,
        note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_cid (cheque_id),
        INDEX idx_cat (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $cur_r = mysqli_query($conn, "SELECT * FROM cheques WHERE id=$id LIMIT 1");
    if (!$cur_r || !($cur = mysqli_fetch_assoc($cur_r))) {
        echo json_encode(['success' => false, 'error' => 'Cheque not found']); exit;
    }

    $fields = [
        'cheque_no'        => ['label' => 'Cheque No.',       'type' => 'string'],
        'cheque_date'      => ['label' => 'Cheque Date',      'type' => 'date'],
        'amount'           => ['label' => 'Amount',            'type' => 'decimal'],
        'total_amount'     => ['label' => 'Total Amount',      'type' => 'decimal'],
        'bank_code'        => ['label' => 'Bank Code',         'type' => 'string'],
        'bank_name'        => ['label' => 'Bank Name',         'type' => 'string'],
        'branch_code'      => ['label' => 'Branch Code',       'type' => 'string'],
        'branch_name'      => ['label' => 'Branch Name',       'type' => 'string'],
        'cheque_mode'      => ['label' => 'Cheque Mode',       'type' => 'string'],
        'status'           => ['label' => 'Status',            'type' => 'string'],
        'bulk_flag'        => ['label' => 'Bulk Deposit',      'type' => 'int'],
        'received_date'    => ['label' => 'Received Date',     'type' => 'date'],
        'to_be_bank_date'  => ['label' => 'To Be Bank Date',   'type' => 'date'],
        'deposit_date'     => ['label' => 'Deposit Date',      'type' => 'date'],
        'deposit_type'     => ['label' => 'Deposit Type',      'type' => 'string'],
        'sent_back_reason' => ['label' => 'Send Back Reason',  'type' => 'string'],
        'notes'            => ['label' => 'Notes',             'type' => 'text'],
    ];

    $sets   = [];
    $cu     = mysqli_real_escape_string($conn, get_current_user_label());
    $logged = 0;

    foreach ($fields as $col => $meta) {
        if (!array_key_exists($col, $_POST)) continue;
        $new_raw = trim($_POST[$col] ?? '');
        $old_raw = trim($cur[$col] ?? '');

        $changed = false;
        $sql_val = '';

        if ($meta['type'] === 'decimal') {
            $old_f = floatval($old_raw);
            $new_f = ($new_raw === '') ? null : floatval($new_raw);
            if ($new_f !== null && abs($old_f - $new_f) < 0.0001) continue;
            if ($new_f === null && $old_raw === '') continue;
            $sql_val = ($new_raw === '') ? 'NULL' : floatval($new_raw);
            $changed = true;
        } elseif ($meta['type'] === 'int') {
            $old_i = intval($old_raw);
            $new_i = intval($new_raw);
            if ($old_i === $new_i) continue;
            $sql_val = $new_i;
            $changed = true;
        } elseif ($meta['type'] === 'date') {
            $old_norm = ($old_raw === '0000-00-00') ? '' : $old_raw;
            if ($new_raw === $old_norm) continue;
            $sql_val = ($new_raw === '') ? 'NULL' : "'".mysqli_real_escape_string($conn, $new_raw)."'";
            $changed = true;
        } else {
            if ($new_raw === $old_raw) continue;
            $sql_val = "'".mysqli_real_escape_string($conn, $new_raw)."'";
            $changed = true;
        }

        if (!$changed) continue;

        $sets[] = "`$col`=$sql_val";

        $old_log = mysqli_real_escape_string($conn, $old_raw);
        $new_log = mysqli_real_escape_string($conn, $new_raw);
        $lbl     = mysqli_real_escape_string($conn, $meta['label']);
        mysqli_query($conn, "INSERT INTO cheque_logs
            (cheque_id, action, old_value, new_value, note, created_by)
            VALUES ($id, 'edit_field', '$old_log', '$new_log', 'Field: $lbl', '$cu')");
        $logged++;
    }

    if (empty($sets)) {
        echo json_encode(['success' => false, 'error' => 'No changes detected']); exit;
    }

    $update_sql = "UPDATE cheques SET " . implode(', ', $sets) . " WHERE id=$id";
    if (mysqli_query($conn, $update_sql)) {
        mysqli_query($conn, "INSERT INTO cheque_logs
            (cheque_id, action, old_value, new_value, note, created_by)
            VALUES ($id, 'edit_save', '', '$logged',
                    'Saved $logged field change(s) via Edit page', '$cu')");
        echo json_encode(['success' => true, 'changed' => $logged]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    }
    exit;
}

/* ── AJAX: fetch logs ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'logs') {
    include_once 'config.php';
    header('Content-Type: application/json');
    $id = intval($_GET['cheque_id'] ?? 0);

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cheque_id INT NOT NULL,
        action VARCHAR(100) NOT NULL,
        old_value TEXT, new_value TEXT, note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_cid (cheque_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $logs = [];
    $r = mysqli_query($conn, "SELECT * FROM cheque_logs WHERE cheque_id=$id ORDER BY created_at DESC");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $logs[] = $row;
    echo json_encode(['success' => true, 'logs' => $logs]);
    exit;
}

/* ── AJAX: get branches for a bank ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'branches') {
    include_once 'config.php';
    header('Content-Type: application/json');
    $bank_code = mysqli_real_escape_string($conn, trim($_GET['bank_code'] ?? ''));
    $rows = [];
    if ($bank_code) {
        $r = mysqli_query($conn, "SELECT branch_code, branch_name FROM bank_branches
                                   WHERE bank_code='$bank_code' AND active=1
                                   ORDER BY branch_name ASC");
        if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    }
    echo json_encode(['success' => true, 'branches' => $rows]);
    exit;
}

/* ══════════════════════════════════════════════════════
   PAGE LOAD
══════════════════════════════════════════════════════ */
include 'config.php';
include 'header.php';

$id     = intval($_GET['id'] ?? 0);
$cheque = null;

if ($id) {
    $r = mysqli_query($conn, "
        SELECT ch.*,
               COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) AS cust_name,
               fs.sr_code,
               fs.delivery_date
        FROM cheques ch
        INNER JOIN invoice_payments      ip  ON ip.id  = ch.invoice_payment_id
        INNER JOIN field_summary         fs  ON fs.id  = ip.field_summary_id
        LEFT  JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        LEFT  JOIN customers             c   ON c.t_code = ch.t_code
        WHERE ch.id = $id
        LIMIT 1");
    if ($r) $cheque = mysqli_fetch_assoc($r);
}

if (!$cheque) {
    echo '<div style="padding:40px;text-align:center;color:#dc2626;font-size:16px;font-family:inherit;">
            <i class="fa-solid fa-triangle-exclamation"></i> Cheque not found or invalid ID.
            <br><br><a href="cheques.php" style="color:#6366f1;">← Back to Cheque Register</a>
          </div>';
    include 'footer.php';
    exit;
}

/* ── Banks list ── */
$banks_list = [];
$bq = mysqli_query($conn, "SELECT bank_code, bank_name FROM banks WHERE active=1 ORDER BY bank_name ASC");
if ($bq) while ($b = mysqli_fetch_assoc($bq)) $banks_list[] = $b;

/* ── Branches for current bank ── */
$branches_list = [];
$cur_bank = $cheque['bank_code'] ?? '';
if ($cur_bank) {
    $brc = mysqli_real_escape_string($conn, $cur_bank);
    $brq = mysqli_query($conn, "SELECT branch_code, branch_name FROM bank_branches
                                 WHERE bank_code='$brc' AND active=1
                                 ORDER BY branch_name ASC");
    if ($brq) while ($b = mysqli_fetch_assoc($brq)) $branches_list[] = $b;
}

$statuses = ['pending','to_be_bank','deposited','sent_back','cleared','returned'];

function fval($cheque, $key) {
    return htmlspecialchars($cheque[$key] ?? '', ENT_QUOTES, 'UTF-8');
}
function fdate($val) {
    if (!$val || $val === '0000-00-00' || $val === '0000-00-00 00:00:00') return '';
    return substr($val, 0, 10);
}
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
*,*::before,*::after{box-sizing:border-box}

/* ── Layout ── */
.edit-wrap{display:grid;grid-template-columns:1fr 390px;gap:22px;max-width:1380px;margin:0 auto;}
@media(max-width:1100px){.edit-wrap{grid-template-columns:1fr}}

/* ── Top bar ── */
.edit-topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;max-width:1380px;margin-left:auto;margin-right:auto;}
.back-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;background:#fff;border:1.5px solid #e0e7ff;border-radius:9px;color:#4f46e5;font-size:13px;font-weight:700;text-decoration:none;transition:all .15s;font-family:inherit;cursor:pointer;}
.back-btn:hover{background:#ede9fe;border-color:#a5b4fc;}
.page-title{font-size:21px;font-weight:800;color:#1e1b4b;margin:0;}
.page-sub{font-size:12px;color:#6b7280;margin-top:2px;}
.cheque-no-badge{background:linear-gradient(135deg,#1e1b4b,#4f46e5);color:#fff;padding:6px 18px;border-radius:10px;font-family:'Courier New',monospace;font-size:15px;font-weight:800;letter-spacing:.06em;box-shadow:0 4px 14px rgba(99,102,241,.28);}

/* ── Status pill ── */
.st-pill{display:inline-flex;align-items:center;gap:5px;padding:5px 14px;border-radius:8px;font-size:12px;font-weight:700;border:1.5px solid;}
.st-pending{background:#fef3c7;color:#92400e;border-color:#fde68a}
.st-to_be_bank{background:#e0f2fe;color:#0369a1;border-color:#7dd3fc}
.st-deposited{background:#dbeafe;color:#1e40af;border-color:#bfdbfe}
.st-sent_back{background:#fdf4ff;color:#7e22ce;border-color:#d8b4fe}
.st-cleared{background:#dcfce7;color:#166534;border-color:#86efac}
.st-returned{background:#fee2e2;color:#991b1b;border-color:#fecaca}

/* ── Cards ── */
.card{background:#fff;border-radius:14px;border:1px solid #e8eaf0;box-shadow:0 2px 8px rgba(0,0,0,.05);overflow:hidden;}
.card-hdr{padding:14px 20px;border-bottom:1px solid #f0f2f5;display:flex;align-items:center;gap:10px;}
.card-icon{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.ci-purple{background:#ede9fe;color:#4f46e5}
.ci-teal{background:#ccfbf1;color:#0d9488}
.card-title{font-size:13px;font-weight:800;color:#1e1b4b;}
.card-sub{font-size:11px;color:#9ca3af;margin-top:1px;}
.card-body{padding:20px;}

/* ── Summary strip ── */
.sum-strip{background:linear-gradient(135deg,#f5f3ff,#eef2ff);border-bottom:1px solid #e0e7ff;padding:12px 20px;display:grid;grid-template-columns:repeat(4,1fr);gap:10px;}
@media(max-width:700px){.sum-strip{grid-template-columns:1fr 1fr}}
.si-item{display:flex;flex-direction:column;gap:2px;}
.si-lbl{font-size:10px;font-weight:700;color:#6366f1;text-transform:uppercase;letter-spacing:.06em;}
.si-val{font-size:13px;font-weight:700;color:#1e1b4b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}

/* ── Form ── */
.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.fgrid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;}
@media(max-width:700px){.fgrid,.fgrid-3{grid-template-columns:1fr}}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg.span2{grid-column:1/-1;}
.fg label{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.05em;display:flex;align-items:center;gap:6px;}
.change-dot{width:7px;height:7px;background:#f59e0b;border-radius:50%;flex-shrink:0;display:none;}
.change-dot.on{display:inline-block;}
.fg input,.fg select,.fg textarea{
    border:1.5px solid #e8eaf0;border-radius:9px;padding:9px 12px;
    font-size:13px;font-family:inherit;color:#1f2937;background:#fafbff;
    width:100%;transition:border .15s,box-shadow .15s,background .15s;
}
.fg input:focus,.fg select:focus,.fg textarea:focus{
    outline:none;border-color:#6366f1;background:#fff;
    box-shadow:0 0 0 3px rgba(99,102,241,.1);
}
.fg textarea{min-height:80px;resize:vertical;line-height:1.55;}
.inp-changed{border-color:#f59e0b!important;background:#fffbeb!important;}

/* ── Section label ── */
.sec-lbl{font-size:10px;font-weight:800;color:#6366f1;text-transform:uppercase;letter-spacing:.08em;
          grid-column:1/-1;display:flex;align-items:center;gap:7px;
          margin-top:6px;padding-bottom:8px;border-bottom:1.5px dashed #e0e7ff;}

/* ── Save bar ── */
.save-bar{background:linear-gradient(135deg,#1e1b4b,#312e81);padding:14px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.save-info{font-size:12px;color:rgba(255,255,255,.7);display:flex;align-items:center;gap:8px;}
.n-badge{background:rgba(255,255,255,.2);color:#fff;padding:1px 10px;border-radius:8px;font-size:11px;font-weight:700;display:none;}
.n-badge.on{display:inline-block;}
.btn-save{background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;border:none;border-radius:9px;padding:10px 28px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:7px;transition:filter .18s;}
.btn-save:hover{filter:brightness(1.08)}
.btn-save:disabled{opacity:.5;cursor:not-allowed}
.btn-discard{background:rgba(255,255,255,.1);color:rgba(255,255,255,.8);border:1.5px solid rgba(255,255,255,.2);border-radius:9px;padding:10px 20px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .18s;}
.btn-discard:hover{background:rgba(255,255,255,.2);}

/* ── Log panel ── */
.log-panel{display:flex;flex-direction:column;}
.log-sticky{position:sticky;top:20px;}
.log-scroll{max-height:calc(100vh - 240px);overflow-y:auto;padding:14px 18px;}
.log-item{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid #f3f4f6;align-items:flex-start;}
.log-item:last-child{border-bottom:none;}
.log-ico{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;margin-top:1px;}
.lic-edit{background:#ede9fe;color:#4f46e5}
.lic-save{background:#fef3c7;color:#d97706}
.lic-status{background:#dbeafe;color:#1e40af}
.lic-verify{background:#dcfce7;color:#166634}
.lic-back{background:#fee2e2;color:#991b1b}
.lic-deposit{background:#ccfbf1;color:#0d9488}
.lic-other{background:#f3f4f6;color:#6b7280}
.log-body{flex:1;min-width:0;}
.log-act{font-size:12px;font-weight:700;color:#1f2937;margin-bottom:3px;}
.log-diff{display:flex;align-items:center;gap:5px;flex-wrap:wrap;margin-bottom:3px;}
.lv{background:#f3f4f6;border-radius:4px;padding:1px 7px;font-size:10.5px;font-weight:600;color:#374151;font-family:'Courier New',monospace;white-space:nowrap;max-width:115px;overflow:hidden;text-overflow:ellipsis;}
.lv.old{background:#fee2e2;color:#991b1b;}
.lv.new{background:#dcfce7;color:#166634;}
.log-note{font-size:11px;color:#6b7280;margin-bottom:3px;word-break:break-word;}
.log-meta{font-size:10px;color:#9ca3af;display:flex;align-items:center;gap:4px;flex-wrap:wrap;}
.user-pill{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:600;display:inline-flex;align-items:center;gap:3px;}
.log-empty{text-align:center;padding:40px 16px;color:#9ca3af;}
.log-empty i{font-size:32px;display:block;margin-bottom:10px;opacity:.4;}
.log-refresh-btn{background:none;border:none;color:#6366f1;font-size:11.5px;cursor:pointer;font-family:inherit;font-weight:700;display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border-radius:6px;transition:background .15s;}
.log-refresh-btn:hover{background:#ede9fe;}

/* ── Branch loading state ── */
.branch-loading{font-size:11px;color:#6b7280;display:flex;align-items:center;gap:5px;padding:4px 0;display:none;}
.branch-loading.show{display:flex;}

/* ── Toast ── */
#toast{position:fixed;bottom:28px;right:28px;z-index:99999;padding:12px 22px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 6px 24px rgba(0,0,0,.2);color:#fff;transform:translateY(80px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none;}
#toast.show{transform:translateY(0);opacity:1;}

.mono{font-family:'Courier New',monospace;font-weight:700;}

/* ── Select2 overrides — match form style ── */
.select2-container--default .select2-selection--single{
    height:40px!important;
    border:1.5px solid #e8eaf0!important;
    border-radius:9px!important;
    background:#fafbff!important;
    transition:border .15s,box-shadow .15s,background .15s;
}
.select2-container--default .select2-selection--single .select2-selection__rendered{
    line-height:38px!important;
    padding-left:12px!important;
    color:#1f2937!important;
    font-size:13px!important;
    font-family:inherit!important;
}
.select2-container--default .select2-selection--single .select2-selection__arrow{
    height:38px!important;
    right:6px!important;
}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open .select2-selection--single{
    border-color:#6366f1!important;
    background:#fff!important;
    box-shadow:0 0 0 3px rgba(99,102,241,.1)!important;
}
/* Changed state for Select2 */
.select2-changed .select2-selection--single{
    border-color:#f59e0b!important;
    background:#fffbeb!important;
}
.select2-dropdown{
    border:1.5px solid #e0e7ff!important;
    border-radius:10px!important;
    box-shadow:0 8px 30px rgba(0,0,0,.12)!important;
    font-size:13px!important;
    z-index:99999!important;
}
.select2-results__option--highlighted{
    background:#6366f1!important;
}
.select2-search--dropdown .select2-search__field{
    border:1.5px solid #e0e7ff!important;
    border-radius:7px!important;
    padding:7px 10px!important;
    font-size:13px!important;
    font-family:inherit!important;
}
.select2-search--dropdown .select2-search__field:focus{
    outline:none!important;
    border-color:#6366f1!important;
}
</style>

<!-- TOP BAR -->
<div class="edit-topbar">
  <div style="display:flex;align-items:center;gap:14px;">
    <a href="cheques.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back to Register</a>
    <div>
      <h2 class="page-title"><i class="fa-solid fa-pen-to-square" style="color:#6366f1;font-size:18px;"></i> Edit Cheque</h2>
      <p class="page-sub">All changes are individually tracked and logged with timestamps.</p>
    </div>
  </div>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
    <span class="cheque-no-badge"><i class="fa-solid fa-money-check" style="font-size:11px;opacity:.75;margin-right:5px;"></i><?= fval($cheque, 'cheque_no') ?></span>
    <?php
    $st = strtolower($cheque['status'] ?? 'pending');
    $st_lbl = ucwords(str_replace('_',' ', $st));
    echo "<span class=\"st-pill st-$st\"><i class=\"fa-solid fa-circle\" style=\"font-size:7px;\"></i> $st_lbl</span>";
    ?>
  </div>
</div>

<!-- LAYOUT -->
<div class="edit-wrap">

  <!-- ════ LEFT: Form ════ -->
  <div style="display:flex;flex-direction:column;gap:18px;">

    <!-- Summary info strip -->
    <div class="card">
      <div class="sum-strip">
        <div class="si-item">
          <span class="si-lbl"><i class="fa-solid fa-user-tag"></i> T-Code</span>
          <span class="si-val mono"><?= fval($cheque,'t_code') ?></span>
        </div>
        <div class="si-item">
          <span class="si-lbl"><i class="fa-solid fa-store"></i> Customer</span>
          <span class="si-val" title="<?= fval($cheque,'cust_name') ?>"><?= fval($cheque,'cust_name') ?: '—' ?></span>
        </div>
        <div class="si-item">
          <span class="si-lbl"><i class="fa-solid fa-id-badge"></i> SR Code</span>
          <span class="si-val"><?= fval($cheque,'sr_code') ?></span>
        </div>
        <div class="si-item">
          <span class="si-lbl"><i class="fa-solid fa-truck"></i> Delivery Date</span>
          <span class="si-val">
            <?= ($cheque['delivery_date'] && $cheque['delivery_date'] !== '0000-00-00')
                ? date('d M Y', strtotime($cheque['delivery_date'])) : '—' ?>
          </span>
        </div>
      </div>
    </div>

    <!-- Edit form card -->
    <div class="card" id="formCard">
      <div class="card-hdr">
        <span class="card-icon ci-purple"><i class="fa-solid fa-money-check"></i></span>
        <div>
          <div class="card-title">Cheque Details</div>
          <div class="card-sub">Edit fields below — unsaved changes are highlighted in amber</div>
        </div>
      </div>

      <div class="card-body">
        <form id="editForm" onsubmit="return false;">
          <input type="hidden" id="cheque_id" name="cheque_id" value="<?= $id ?>">
          <!-- Hidden fields to carry bank_name and branch_name (auto-filled by JS) -->
          <input type="hidden" name="bank_name"   id="f_bank_name"
                 value="<?= fval($cheque,'bank_name') ?>"
                 data-orig="<?= fval($cheque,'bank_name') ?>">
          <input type="hidden" name="branch_name" id="f_branch_name"
                 value="<?= fval($cheque,'branch_name') ?>"
                 data-orig="<?= fval($cheque,'branch_name') ?>">

          <div class="fgrid">

            <!-- ── Cheque Identity ── -->
            <div class="sec-lbl"><i class="fa-solid fa-hashtag"></i> Cheque Identity</div>

            <div class="fg">
              <label><span class="change-dot" id="dot_cheque_no"></span><i class="fa-solid fa-money-check"></i> Cheque No.</label>
              <input type="text" name="cheque_no" id="f_cheque_no" class="mono"
                     value="<?= fval($cheque,'cheque_no') ?>"
                     data-orig="<?= fval($cheque,'cheque_no') ?>" oninput="trackChange(this)">
            </div>

            <div class="fg">
              <label><span class="change-dot" id="dot_cheque_date"></span><i class="fa-solid fa-calendar-day"></i> Cheque Date</label>
              <input type="date" name="cheque_date" id="f_cheque_date"
                     value="<?= fdate($cheque['cheque_date']) ?>"
                     data-orig="<?= fdate($cheque['cheque_date']) ?>" oninput="trackChange(this)">
            </div>

            <div class="fg">
              <label><span class="change-dot" id="dot_amount"></span><i class="fa-solid fa-coins"></i> Amount</label>
              <input type="number" step="0.01" name="amount" id="f_amount"
                     value="<?= fval($cheque,'amount') ?>"
                     data-orig="<?= fval($cheque,'amount') ?>" oninput="trackChange(this)">
            </div>

            <div class="fg">
              <label><span class="change-dot" id="dot_total_amount"></span><i class="fa-solid fa-sigma"></i> Total Amount</label>
              <input type="number" step="0.01" name="total_amount" id="f_total_amount"
                     value="<?= fval($cheque,'total_amount') ?>"
                     data-orig="<?= fval($cheque,'total_amount') ?>" oninput="trackChange(this)">
            </div>

            <!-- ── Bank Details ── -->
            <div class="sec-lbl"><i class="fa-solid fa-building-columns"></i> Bank Details</div>

            <!-- Bank Select2 -->
            <div class="fg">
              <label><span class="change-dot" id="dot_bank_code"></span><i class="fa-solid fa-landmark"></i> Bank</label>
              <select name="bank_code" id="f_bank_code"
                      data-orig="<?= fval($cheque,'bank_code') ?>">
                <option value="">— Select Bank —</option>
                <?php foreach($banks_list as $b): ?>
                <option value="<?= htmlspecialchars($b['bank_code']) ?>"
                        data-name="<?= htmlspecialchars($b['bank_name']) ?>"
                        <?= ($cheque['bank_code']??'')===$b['bank_code']?'selected':'' ?>>
                  <?= htmlspecialchars($b['bank_code']) ?> – <?= htmlspecialchars($b['bank_name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Branch Select2 -->
            <div class="fg">
              <label><span class="change-dot" id="dot_branch_code"></span><i class="fa-solid fa-code-branch"></i> Branch</label>
              <div style="position:relative;">
                <select name="branch_code" id="f_branch_code"
                        data-orig="<?= fval($cheque,'branch_code') ?>">
                  <option value="">— Select Branch —</option>
                  <?php foreach($branches_list as $b): ?>
                  <option value="<?= htmlspecialchars($b['branch_code']) ?>"
                          data-name="<?= htmlspecialchars($b['branch_name']) ?>"
                          <?= ($cheque['branch_code']??'')===$b['branch_code']?'selected':'' ?>>
                    <?= htmlspecialchars($b['branch_code']) ?> – <?= htmlspecialchars($b['branch_name']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <div class="branch-loading" id="branchLoading">
                  <i class="fa-solid fa-spinner fa-spin" style="color:#6366f1;font-size:11px;"></i> Loading branches…
                </div>
              </div>
            </div>

            <!-- ── Classification ── -->
            <div class="sec-lbl"><i class="fa-solid fa-sliders"></i> Classification &amp; Status</div>

            <div class="fg">
              <label><span class="change-dot" id="dot_cheque_mode"></span><i class="fa-solid fa-layer-group"></i> Cheque Mode</label>
              <select name="cheque_mode" id="f_cheque_mode"
                      data-orig="<?= fval($cheque,'cheque_mode') ?>" onchange="trackChange(this)">
                <option value="">— Select Mode —</option>
                <?php foreach(['payee_only'=>'Payee Only','cash'=>'Bearer / Cash','third_party_cash'=>'3rd Party Cash'] as $v=>$lbl): ?>
                <option value="<?= $v ?>" <?= ($cheque['cheque_mode']??'')===$v?'selected':'' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="fg">
              <label><span class="change-dot" id="dot_status"></span><i class="fa-solid fa-circle-half-stroke"></i> Status</label>
              <select name="status" id="f_status"
                      data-orig="<?= fval($cheque,'status') ?>" onchange="trackChange(this)">
                <?php foreach($statuses as $s): ?>
                <option value="<?= $s ?>" <?= ($cheque['status']??'')===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="fg">
              <label><span class="change-dot" id="dot_bulk_flag"></span><i class="fa-solid fa-cubes"></i> Bulk Deposit</label>
              <select name="bulk_flag" id="f_bulk_flag"
                      data-orig="<?= fval($cheque,'bulk_flag') ?>" onchange="trackChange(this)">
                <option value="0" <?= !intval($cheque['bulk_flag']??0)?'selected':'' ?>>No</option>
                <option value="1" <?= intval($cheque['bulk_flag']??0)?'selected':'' ?>>Yes</option>
              </select>
            </div>

            <!-- ── Key Dates ── -->
            <div class="sec-lbl"><i class="fa-solid fa-calendar-check"></i> Key Dates</div>

            <div class="fg">
              <label><span class="change-dot" id="dot_received_date"></span><i class="fa-solid fa-calendar-check"></i> Received Date</label>
              <input type="date" name="received_date" id="f_received_date"
                     value="<?= fdate($cheque['received_date']) ?>"
                     data-orig="<?= fdate($cheque['received_date']) ?>" oninput="trackChange(this)">
            </div>

            <div class="fg">
              <label><span class="change-dot" id="dot_to_be_bank_date"></span><i class="fa-solid fa-inbox"></i> To Be Bank Date</label>
              <input type="date" name="to_be_bank_date" id="f_to_be_bank_date"
                     value="<?= fdate($cheque['to_be_bank_date'] ?? '') ?>"
                     data-orig="<?= fdate($cheque['to_be_bank_date'] ?? '') ?>" oninput="trackChange(this)">
            </div>

            <div class="fg">
              <label><span class="change-dot" id="dot_deposit_date"></span><i class="fa-solid fa-building-columns"></i> Deposit Date</label>
              <input type="date" name="deposit_date" id="f_deposit_date"
                     value="<?= fdate($cheque['deposit_date'] ?? '') ?>"
                     data-orig="<?= fdate($cheque['deposit_date'] ?? '') ?>" oninput="trackChange(this)">
            </div>

            <div class="fg">
              <label><span class="change-dot" id="dot_deposit_type"></span><i class="fa-solid fa-layer-group"></i> Deposit Type</label>
              <select name="deposit_type" id="f_deposit_type"
                      data-orig="<?= fval($cheque,'deposit_type') ?>" onchange="trackChange(this)">
                <option value="">— None —</option>
                <option value="normal" <?= ($cheque['deposit_type']??'')==='normal'?'selected':'' ?>>Normal</option>
                <option value="bulk"   <?= ($cheque['deposit_type']??'')==='bulk'?'selected':'' ?>>Bulk</option>
              </select>
            </div>

            <!-- ── Notes ── -->
            <div class="sec-lbl"><i class="fa-solid fa-comment-dots"></i> Additional Information</div>

            <div class="fg span2">
              <label><span class="change-dot" id="dot_sent_back_reason"></span><i class="fa-solid fa-rotate-left"></i> Send Back Reason</label>
              <input type="text" name="sent_back_reason" id="f_sent_back_reason"
                     value="<?= fval($cheque,'sent_back_reason') ?>"
                     data-orig="<?= fval($cheque,'sent_back_reason') ?>" oninput="trackChange(this)"
                     placeholder="Reason if cheque was sent back…">
            </div>

            <div class="fg span2">
              <label><span class="change-dot" id="dot_notes"></span><i class="fa-solid fa-note-sticky"></i> Notes</label>
              <textarea name="notes" id="f_notes"
                        data-orig="<?= fval($cheque,'notes') ?>" oninput="trackChange(this)"
                        placeholder="Internal notes about this cheque…"><?= fval($cheque,'notes') ?></textarea>
            </div>

          </div><!-- /fgrid -->
        </form>
      </div><!-- /card-body -->

      <!-- Sticky save bar -->
      <div class="save-bar">
        <div class="save-info">
          <i class="fa-solid fa-pen-to-square"></i>
          <span id="changeInfo">No unsaved changes</span>
          <span class="n-badge" id="changeBadge">0</span>
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
          <button class="btn-discard" onclick="discardChanges()"><i class="fa-solid fa-rotate-left"></i> Discard</button>
          <button class="btn-save" id="saveBtn" onclick="saveChanges()">
            <i class="fa-solid fa-floppy-disk"></i> Save Changes
          </button>
        </div>
      </div>
    </div><!-- /card -->

  </div><!-- /left -->

  <!-- ════ RIGHT: Activity Logs ════ -->
  <div class="log-panel">
    <div class="log-sticky">
      <div class="card">
        <div class="card-hdr" style="justify-content:space-between;">
          <div style="display:flex;align-items:center;gap:10px;">
            <span class="card-icon ci-teal"><i class="fa-solid fa-clock-rotate-left"></i></span>
            <div>
              <div class="card-title">Activity Logs</div>
              <div class="card-sub" id="logCountSub">Loading…</div>
            </div>
          </div>
          <button class="log-refresh-btn" onclick="loadLogs()" title="Refresh">
            <i class="fa-solid fa-arrows-rotate"></i> Refresh
          </button>
        </div>
        <div class="log-scroll" id="logScroll">
          <div class="log-empty"><i class="fa-solid fa-spinner fa-spin"></i><span style="font-size:12px;display:block;margin-top:8px;">Loading…</span></div>
        </div>
      </div>
    </div>
  </div>

</div><!-- /edit-wrap -->

<div id="toast"></div>

<script>
const CHEQUE_ID = <?= $id ?>;
let changedFields = new Set();
let origValues    = {};

/* Collect originals on load (inputs, selects, textareas with data-orig) */
document.querySelectorAll('[data-orig]').forEach(el => {
    origValues[el.name] = el.dataset.orig;
});

/* ══ Change tracking ══ */
function trackChange(el) {
    const name = el.name;
    const cur  = el.value;
    const orig = origValues[name] ?? '';
    const dot  = document.getElementById('dot_' + name);
    if (cur !== orig) {
        changedFields.add(name);
        el.classList.add('inp-changed');
        if (dot) dot.classList.add('on');
    } else {
        changedFields.delete(name);
        el.classList.remove('inp-changed');
        if (dot) dot.classList.remove('on');
    }
    updateBar();
}

/* Select2 wrapper — fires trackChange for hidden inputs too */
function trackSelect2Change(name) {
    const sel = document.getElementById('f_' + name);
    if (sel) trackChange(sel);
}

function updateBar() {
    const n     = changedFields.size;
    const info  = document.getElementById('changeInfo');
    const badge = document.getElementById('changeBadge');
    if (!n) {
        info.textContent = 'No unsaved changes';
        badge.classList.remove('on');
    } else {
        info.textContent = n === 1 ? '1 field changed' : n + ' fields changed';
        badge.textContent = n;
        badge.classList.add('on');
    }
}

/* ══ Select2 init for Bank + Branch ══ */
$(function () {

    /* ── Bank select2 ── */
    $('#f_bank_code').select2({
        placeholder : '— Select Bank —',
        allowClear  : true,
        width       : '100%'
    });

    /* ── Branch select2 ── */
    $('#f_branch_code').select2({
        placeholder : '— Select Branch —',
        allowClear  : true,
        width       : '100%'
    });

    /* ── Bank change handler ── */
    $('#f_bank_code').on('change', function () {
        const sel     = this;
        const bankCode= $(this).val() || '';
        const opt     = sel.options[sel.selectedIndex];
        const bankName= opt ? (opt.dataset.name || opt.textContent.split('–').slice(1).join('–').trim() || '') : '';

        /* Update hidden bank_name */
        const bnEl = document.getElementById('f_bank_name');
        bnEl.value = bankName;

        /* Track bank_code change */
        trackChange(sel);
        /* Track bank_name change */
        trackChange(bnEl);

        /* Highlight Select2 wrapper if changed */
        const orig = sel.dataset.orig || '';
        if (bankCode !== orig) {
            $('#f_bank_code').next('.select2-container').find('.select2-selection--single').addClass('inp-changed');
            document.getElementById('dot_bank_code')?.classList.add('on');
        } else {
            $('#f_bank_code').next('.select2-container').find('.select2-selection--single').removeClass('inp-changed');
            document.getElementById('dot_bank_code')?.classList.remove('on');
        }

        /* Reset branch */
        loadBranches(bankCode, '');
    });

    /* ── Branch change handler ── */
    $('#f_branch_code').on('change', function () {
        const sel        = this;
        const branchCode = $(this).val() || '';
        const opt        = sel.options[sel.selectedIndex];
        const branchName = opt ? (opt.dataset.name || opt.textContent.split('–').slice(1).join('–').trim() || '') : '';

        /* Update hidden branch_name */
        const bnEl = document.getElementById('f_branch_name');
        bnEl.value = branchName;

        trackChange(sel);
        trackChange(bnEl);

        /* Highlight Select2 wrapper if changed */
        const orig = sel.dataset.orig || '';
        if (branchCode !== orig) {
            $('#f_branch_code').next('.select2-container').find('.select2-selection--single').addClass('inp-changed');
            document.getElementById('dot_branch_code')?.classList.add('on');
        } else {
            $('#f_branch_code').next('.select2-container').find('.select2-selection--single').removeClass('inp-changed');
            document.getElementById('dot_branch_code')?.classList.remove('on');
        }
    });

    /* Highlight Select2 on init if value != orig */
    ['f_bank_code', 'f_branch_code'].forEach(id => {
        const sel = document.getElementById(id);
        if (!sel) return;
        if (sel.value !== (sel.dataset.orig || '')) {
            $('#' + id).next('.select2-container').find('.select2-selection--single').addClass('inp-changed');
        }
    });
});

/* ══ Load branches via AJAX ══ */
async function loadBranches(bankCode, selectVal) {
    const brSel  = document.getElementById('f_branch_code');
    const loader = document.getElementById('branchLoading');

    /* Destroy Select2, clear options */
    try { $('#f_branch_code').select2('destroy'); } catch(e) {}
    brSel.innerHTML = '<option value="">— Select Branch —</option>';
    loader.classList.add('show');

    if (!bankCode) {
        loader.classList.remove('show');
        initBranchSelect2();
        return;
    }

    try {
        const res  = await fetch('cheque_edit.php?ajax=branches&bank_code=' + encodeURIComponent(bankCode));
        const data = await res.json();
        if (data.success && data.branches.length) {
            data.branches.forEach(b => {
                const opt = document.createElement('option');
                opt.value        = b.branch_code;
                opt.textContent  = b.branch_code + ' – ' + b.branch_name;
                opt.dataset.name = b.branch_name;
                if (selectVal && selectVal === b.branch_code) opt.selected = true;
                brSel.appendChild(opt);
            });
        }
    } catch(e) {
        console.error('Branch load error:', e);
    }

    loader.classList.remove('show');
    initBranchSelect2();
}

function initBranchSelect2() {
    $('#f_branch_code').select2({
        placeholder : '— Select Branch —',
        allowClear  : true,
        width       : '100%'
    }).on('change', function () {
        const sel        = this;
        const branchCode = $(this).val() || '';
        const opt        = sel.options[sel.selectedIndex];
        const branchName = opt ? (opt.dataset.name || '') : '';
        const bnEl       = document.getElementById('f_branch_name');
        bnEl.value = branchName;
        trackChange(sel);
        trackChange(bnEl);
        const orig = sel.dataset.orig || '';
        if (branchCode !== orig) {
            $('#f_branch_code').next('.select2-container').find('.select2-selection--single').addClass('inp-changed');
            document.getElementById('dot_branch_code')?.classList.add('on');
        } else {
            $('#f_branch_code').next('.select2-container').find('.select2-selection--single').removeClass('inp-changed');
            document.getElementById('dot_branch_code')?.classList.remove('on');
        }
    });
}

/* ══ Discard ══ */
function discardChanges() {
    if (!changedFields.size) return;
    if (!confirm('Discard all unsaved changes?')) return;

    document.querySelectorAll('[data-orig]').forEach(el => {
        el.value = el.dataset.orig;
        el.classList.remove('inp-changed');
        const dot = document.getElementById('dot_' + el.name);
        if (dot) dot.classList.remove('on');
    });

    /* Restore Select2 bank */
    const origBank = document.getElementById('f_bank_code').dataset.orig || '';
    $('#f_bank_code').val(origBank).trigger('change.select2');
    /* Suppress branch reload — restore branch directly after */
    const origBranch = document.getElementById('f_branch_code').dataset.orig || '';

    /* Reload branches for original bank then set original branch */
    loadBranches(origBank, origBranch).then(() => {
        /* Clear amber highlight */
        ['f_bank_code','f_branch_code'].forEach(id => {
            $('#'+id).next('.select2-container').find('.select2-selection--single').removeClass('inp-changed');
        });
    });

    changedFields.clear();
    updateBar();
}

/* ══ Save ══ */
async function saveChanges() {
    if (!changedFields.size) { showToast('Nothing to save', 'warn'); return; }
    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('ajax_action', 'save_cheque_edit');
    fd.append('cheque_id', CHEQUE_ID);

    /* Collect all named fields including hidden bank_name / branch_name */
    document.querySelectorAll('[data-orig]').forEach(el => {
        fd.append(el.name, el.value);
    });

    try {
        const res  = await fetch('cheque_edit.php', { method: 'POST', body: fd });
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); }
        catch(e) { showToast('Server error: ' + text.substring(0,100), 'err'); resetBtn(btn); return; }

        if (data.success) {
            /* Accept new values as originals */
            document.querySelectorAll('[data-orig]').forEach(el => {
                el.dataset.orig = el.value;
                el.classList.remove('inp-changed');
                const dot = document.getElementById('dot_' + el.name);
                if (dot) dot.classList.remove('on');
            });
            /* Clear Select2 amber highlights */
            ['f_bank_code','f_branch_code'].forEach(id => {
                $('#'+id).next('.select2-container').find('.select2-selection--single').removeClass('inp-changed');
            });
            changedFields.clear();
            updateBar();
            showToast('✓ ' + data.changed + ' field(s) saved and logged', 'ok');
            loadLogs();
        } else {
            showToast(data.error || 'Save failed', 'err');
        }
    } catch(e) {
        showToast('Network error: ' + e.message, 'err');
    }
    resetBtn(btn);
}

function resetBtn(btn) {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
}

/* ══ LOGS ══ */
const A_LABELS = {
    edit_field:'Field Edited', edit_save:'Edit Saved',
    status_change:'Status Changed', verified:'Cheque Verified',
    sent_back:'Sent Back', deposited:'Deposited',
};
const A_ICONS = {
    edit_field  :'<i class="fa-solid fa-pen"></i>',
    edit_save   :'<i class="fa-solid fa-floppy-disk"></i>',
    status_change:'<i class="fa-solid fa-arrow-right-arrow-left"></i>',
    verified    :'<i class="fa-solid fa-shield-check"></i>',
    sent_back   :'<i class="fa-solid fa-rotate-left"></i>',
    deposited   :'<i class="fa-solid fa-building-columns"></i>',
};
const A_CSS = {
    edit_field:'lic-edit', edit_save:'lic-save', status_change:'lic-status',
    verified:'lic-verify', sent_back:'lic-back', deposited:'lic-deposit',
};

async function loadLogs() {
    const scroll = document.getElementById('logScroll');
    const sub    = document.getElementById('logCountSub');
    scroll.innerHTML = '<div class="log-empty"><i class="fa-solid fa-spinner fa-spin"></i></div>';
    try {
        const res  = await fetch('cheque_edit.php?ajax=logs&cheque_id=' + CHEQUE_ID);
        const data = await res.json();
        if (!data.success || !data.logs.length) {
            scroll.innerHTML = '<div class="log-empty"><i class="fa-solid fa-clock-rotate-left"></i><span style="font-size:12px;display:block;margin-top:8px;">No activity logs yet.</span></div>';
            sub.textContent = '0 entries';
            return;
        }
        sub.textContent = data.logs.length + ' entr' + (data.logs.length===1?'y':'ies');
        let html = '';
        data.logs.forEach(log => {
            const act  = log.action || 'other';
            const lbl  = A_LABELS[act] || act.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase());
            const icon = A_ICONS[act]  || '<i class="fa-solid fa-circle-dot"></i>';
            const css  = A_CSS[act]    || 'lic-other';
            const dt   = log.created_at ? new Date(log.created_at.replace(' ','T')) : null;
            const dtStr= dt ? dt.toLocaleString('en-GB',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';
            const user = log.created_by || 'system';
            const uPill= `<span class="user-pill"><i class="fa-solid fa-user" style="font-size:9px;"></i> ${esc(user)}</span>`;

            let diffHtml = '';
            if (act === 'edit_field') {
                const o  = log.old_value||'(empty)', n2 = log.new_value||'(empty)';
                diffHtml = `<div class="log-diff">
                    <span class="lv old" title="${esc(o)}">${esc(o.substring(0,20))}${o.length>20?'…':''}</span>
                    <i class="fa-solid fa-arrow-right" style="color:#9ca3af;font-size:9px;"></i>
                    <span class="lv new" title="${esc(n2)}">${esc(n2.substring(0,20))}${n2.length>20?'…':''}</span>
                </div>`;
            }

            const noteHtml = log.note
                ? `<div class="log-note"><i class="fa-solid fa-comment-dots" style="font-size:9px;margin-right:3px;"></i>${esc(log.note)}</div>`
                : '';

            html += `
            <div class="log-item">
              <div class="log-ico ${css}">${icon}</div>
              <div class="log-body">
                <div class="log-act">${esc(lbl)}</div>
                ${diffHtml}${noteHtml}
                <div class="log-meta"><i class="fa-regular fa-clock" style="font-size:9px;"></i> ${esc(dtStr)} · ${uPill}</div>
              </div>
            </div>`;
        });
        scroll.innerHTML = html;
    } catch(e) {
        scroll.innerHTML = '<div class="log-empty" style="color:#dc2626;"><i class="fa-solid fa-triangle-exclamation"></i><span style="font-size:12px;display:block;">Error: '+esc(e.message)+'</span></div>';
    }
}

window.addEventListener('beforeunload', e => {
    if (changedFields.size) { e.preventDefault(); e.returnValue = ''; }
});

function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.style.background = type==='ok'?'#166634':type==='warn'?'#d97706':'#dc2626';
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(t._t);
    t._t = setTimeout(() => t.classList.remove('show'), 3200);
}
function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

document.addEventListener('DOMContentLoaded', loadLogs);
</script>

<?php include 'footer.php'; ?>