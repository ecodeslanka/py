<?php
/**
 * stl_letters.php
 * STL Letter Generation — list all letters, create new via modal
 */
if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';

/* ═══════════════════════════════════
   AJAX — Save new STL letter
═══════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    ob_start();
    header('Content-Type: application/json');

    $action = $_POST['ajax_action'];

    if ($action === 'save_stl_letter') {
        $letter_date           = mysqli_real_escape_string($conn, $_POST['letter_date'] ?? date('Y-m-d'));
        $company_account_id    = intval($_POST['company_account_id'] ?? 0);
        $request_grant_amount  = floatval($_POST['request_grant_amount'] ?? 0) * 1000000; // stored in Rs
        $stl_days              = intval($_POST['stl_days'] ?? 21);
        $stl_approved_amount   = floatval($_POST['stl_approved_amount'] ?? 0) * 1000000;  // stored in Rs
        $notes                 = mysqli_real_escape_string($conn, trim($_POST['notes'] ?? ''));

        if (!$letter_date || !$company_account_id || !$request_grant_amount) {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => 'Required fields missing.']);
            exit;
        }

        // Auto-generate letter_no  STL-YYYY-NNN
        $yr  = date('Y', strtotime($letter_date));
        $cnt_r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM stl_letters WHERE YEAR(letter_date)='$yr'");
        $cnt   = ($cnt_r && $row = mysqli_fetch_assoc($cnt_r)) ? intval($row['c']) + 1 : 1;
        $letter_no = 'STL-' . $yr . '-' . str_pad($cnt, 3, '0', STR_PAD_LEFT);

        // Fetch account details
        $ar = mysqli_query($conn,
            "SELECT cba.account_no, cba.bank_code, cba.branch_code,
                    COALESCE(NULLIF(b.bank_name,''), cba.bank_code, '')    AS bank_name,
                    COALESCE(NULLIF(bb.branch_name,''), cba.branch_code,'') AS branch_name
             FROM company_bank_accounts cba
             LEFT JOIN banks         b  ON b.bank_code    = cba.bank_code
             LEFT JOIN bank_branches bb ON bb.bank_code   = cba.bank_code
                                       AND bb.branch_code = cba.branch_code
             WHERE cba.id=$company_account_id LIMIT 1");
        $account_no  = '';
        $bank_name   = '';
        $branch_name = '';
        if ($ar && $arow = mysqli_fetch_assoc($ar)) {
            $account_no  = mysqli_real_escape_string($conn, $arow['account_no']);
            $bank_name   = mysqli_real_escape_string($conn, $arow['bank_name']);
            $branch_name = mysqli_real_escape_string($conn, $arow['branch_name']);
        }

        $sql = "INSERT INTO stl_letters
                    (letter_no, letter_date, company_account_id, account_no, bank_name, branch_name,
                     request_grant_amount, stl_days, stl_approved_amount, notes, created_at)
                VALUES
                    ('$letter_no','$letter_date',$company_account_id,'$account_no','$bank_name','$branch_name',
                     $request_grant_amount,$stl_days,$stl_approved_amount,'$notes',NOW())";

        if (mysqli_query($conn, $sql)) {
            $new_id = mysqli_insert_id($conn);
            ob_end_clean();
            echo json_encode(['success' => true, 'id' => $new_id, 'letter_no' => $letter_no]);
        } else {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        }
        exit;
    }

    if ($action === 'delete_stl_letter') {
        $id = intval($_POST['id'] ?? 0);
        if ($id && mysqli_query($conn, "DELETE FROM stl_letters WHERE id=$id")) {
            ob_end_clean();
            echo json_encode(['success' => true]);
        } else {
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        }
        exit;
    }

    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

/* ═══════════════════════════════════
   LOAD STL SETTINGS (defaults)
═══════════════════════════════════ */
$stl_settings = [];
$ss_r = mysqli_query($conn, "SELECT * FROM stl_settings LIMIT 1");
if ($ss_r && $ss_row = mysqli_fetch_assoc($ss_r)) {
    $stl_settings = $ss_row;
}
$default_days     = intval($stl_settings['stl_days'] ?? 21);
// stl_limit_amount stored in Rs → show in Mn
$default_appr_mn  = floatval($stl_settings['stl_limit_amount'] ?? 0) / 1000000;

/* ═══════════════════════════════════
   LOAD COMPANY BANK ACCOUNTS
═══════════════════════════════════ */
$accounts = [];
$accs_r = mysqli_query($conn,
    "SELECT cba.id, cba.account_no, cba.bank_code, cba.branch_code,
            COALESCE(NULLIF(b.bank_name,''), cba.bank_code,'')    AS bank_name,
            COALESCE(NULLIF(bb.branch_name,''), cba.branch_code,'') AS branch_name
     FROM company_bank_accounts cba
     LEFT JOIN banks         b  ON b.bank_code    = cba.bank_code
     LEFT JOIN bank_branches bb ON bb.bank_code   = cba.bank_code
                               AND bb.branch_code = cba.branch_code
     ORDER BY bank_name");
if ($accs_r) while ($a = mysqli_fetch_assoc($accs_r)) $accounts[] = $a;

/* ═══════════════════════════════════
   LOAD LETTERS LIST
═══════════════════════════════════ */
$letters = [];
$list_r  = mysqli_query($conn,
    "SELECT * FROM stl_letters ORDER BY letter_date DESC, id DESC");
if ($list_r) while ($l = mysqli_fetch_assoc($list_r)) $letters[] = $l;

include 'header.php';
?>

<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <div>
            <h2 class="page-title">STL Letters</h2>
            <p class="page-subtitle">Generate and manage Short Term Loan request letters</p>
        </div>
        <div style="display:flex;gap:8px;">
            <a href="stl_settings.php" class="btn btn-secondary">
                <i class="fa-solid fa-gear"></i> STL Settings
            </a>
            <button class="btn btn-primary" onclick="openCreateModal()">
                <i class="fa-solid fa-plus"></i> New STL Letter
            </button>
        </div>
    </div>
</div>

<!-- Flash message -->
<div id="flashMsg" style="display:none;" class="alert"></div>

<!-- ═══════════════════════════════════
     LETTERS TABLE
═══════════════════════════════════ -->
<div class="content-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h3 class="card-title" style="margin:0;">
            <i class="fa-solid fa-file-contract"></i> STL Letter History
        </h3>
        <span class="badge-count"><?= count($letters) ?> letters</span>
    </div>

    <?php if (empty($letters)): ?>
    <div class="empty-state">
        <i class="fa-solid fa-file-circle-plus"></i>
        <p>No STL letters yet. Click <strong>New STL Letter</strong> to generate one.</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Letter No</th>
                    <th>Date</th>
                    <th>Bank / Branch</th>
                    <th>Account No</th>
                    <th>Grant Amount</th>
                    <th>STL Days</th>
                    <th>Approved Limit</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($letters as $i => $l):
                $grant_mn = floatval($l['request_grant_amount']) / 1000000;
                $appr_mn  = floatval($l['stl_approved_amount'])  / 1000000;
            ?>
                <tr id="row-<?= $l['id'] ?>">
                    <td style="color:#9ca3af;font-size:11px;"><?= $i+1 ?></td>
                    <td><span class="letter-badge"><?= htmlspecialchars($l['letter_no']) ?></span></td>
                    <td><?= htmlspecialchars(date('d M Y', strtotime($l['letter_date']))) ?></td>
                    <td>
                        <div style="font-size:12px;font-weight:600;"><?= htmlspecialchars($l['bank_name']) ?></div>
                        <div style="font-size:11px;color:#6b7280;"><?= htmlspecialchars($l['branch_name']) ?></div>
                    </td>
                    <td style="font-family:'JetBrains Mono',monospace;font-size:12px;">
                        <?= htmlspecialchars($l['account_no']) ?>
                    </td>
                    <td>
                        <span class="amount-pill amount-grant">Rs <?= number_format($grant_mn,2) ?>Mn</span>
                    </td>
                    <td style="text-align:center;">
                        <span class="days-badge"><?= intval($l['stl_days']) ?> days</span>
                    </td>
                    <td>
                        <span class="amount-pill amount-appr">Rs <?= number_format($appr_mn,2) ?>Mn</span>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;">
                            <a href="deposit_letter_stl.php?letter_id=<?= $l['id'] ?>" target="_blank"
                               class="btn-action btn-print-ltr" title="Print Letter">
                                <i class="fa-solid fa-print"></i>
                            </a>
                            <button class="btn-action btn-del"
                                    onclick="deleteLetter(<?= $l['id'] ?>, '<?= htmlspecialchars($l['letter_no']) ?>')"
                                    title="Delete">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════
     CREATE LETTER MODAL
═══════════════════════════════════ -->
<div id="createModal" class="modal-backdrop" style="display:none;">
    <div class="modal-box">

        <div class="modal-header">
            <div class="modal-title">
                <i class="fa-solid fa-file-signature"></i>
                Generate New STL Letter
            </div>
            <button class="modal-close" onclick="closeCreateModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="modal-body">

            <!-- Letter Reference -->
            <div class="modal-section-label">Letter Reference</div>
            <div class="form-row-modal">
                <div class="form-group">
                    <label class="form-label">Letter No <span class="form-hint-inline">(auto-generated)</span></label>
                    <input type="text" id="preview_letter_no" class="form-input" readonly
                           placeholder="STL-2026-XXX" style="background:#f9fafb;color:#6b7280;">
                </div>
                <div class="form-group required-field">
                    <label class="form-label">Letter Date <span class="required">*</span></label>
                    <input type="date" id="m_letter_date" class="form-input" required
                           value="<?= date('Y-m-d') ?>">
                </div>
            </div>

            <!-- Bank Account -->
            <div class="modal-section-label">Bank Account</div>
            <div class="form-group required-field">
                <label class="form-label">Company Bank Account <span class="required">*</span></label>
                <select id="m_company_account_id" class="form-input select2-modal" required>
                    <option value="">— Select bank account —</option>
                    <?php foreach ($accounts as $acc): ?>
                    <option value="<?= $acc['id'] ?>"
                            data-bank="<?= htmlspecialchars($acc['bank_name']) ?>"
                            data-branch="<?= htmlspecialchars($acc['branch_name']) ?>"
                            data-accno="<?= htmlspecialchars($acc['account_no']) ?>">
                        <?= htmlspecialchars($acc['bank_name'] . ' — ' . $acc['branch_name'] . ' · ' . $acc['account_no']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Amounts -->
            <div class="modal-section-label">Loan Configuration</div>
            <div class="form-row-modal">
                <div class="form-group required-field">
                    <label class="form-label">Request Grant Amount (Mn) <span class="required">*</span></label>
                    <div class="input-prefix-wrapper">
                        <span class="input-prefix">Rs</span>
                        <input type="number" id="m_request_grant_amount" class="form-input input-with-prefix"
                               step="0.01" min="0.01" required placeholder="e.g. 2.50"
                               oninput="updatePreview()">
                        <span class="input-suffix">Mn</span>
                    </div>
                    <span class="form-hint">Enter amount in millions (e.g. 2.5 = Rs 2.5Mn)</span>
                </div>

                <div class="form-group required-field">
                    <label class="form-label">STL Days <span class="required">*</span></label>
                    <input type="number" id="m_stl_days" class="form-input" min="1" required
                           value="<?= $default_days ?>" oninput="updatePreview()">
                    <span class="form-hint">Loan tenure in days</span>
                </div>
            </div>

            <div class="form-row-modal">
                <div class="form-group required-field">
                    <label class="form-label">Total Approved STL Limit (Mn) <span class="required">*</span></label>
                    <div class="input-prefix-wrapper">
                        <span class="input-prefix">Rs</span>
                        <input type="number" id="m_stl_approved_amount" class="form-input input-with-prefix"
                               step="0.01" min="0" required
                               value="<?= number_format($default_appr_mn, 2, '.', '') ?>"
                               oninput="updatePreview()">
                        <span class="input-suffix">Mn</span>
                    </div>
                    <span class="form-hint">Pre-filled from STL Settings</span>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea id="m_notes" class="form-input" rows="2" placeholder="Optional remarks"></textarea>
                </div>
            </div>

            <!-- Live Letter Preview -->
            <div class="letter-preview-box" id="letterPreview">
                <div class="preview-label"><i class="fa-solid fa-eye"></i> Letter Preview</div>
                <div class="preview-text" id="previewText">
                    Fill in the fields above to preview the letter content.
                </div>
            </div>

        </div><!-- /modal-body -->

        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeCreateModal()">
                <i class="fa-solid fa-xmark"></i> Cancel
            </button>
            <button class="btn btn-primary" id="btnSaveLetter" onclick="saveLetter()">
                <i class="fa-solid fa-floppy-disk"></i> Generate Letter
            </button>
        </div>

    </div>
</div>

<style>
/* ── Base ── */
.required { color:#ef4444; }
.required-field .form-input,
.required-field .select2-container--default .select2-selection--single {
    background-color:#fffbeb !important;
}
.form-hint-inline { font-size:10px; color:#9ca3af; font-weight:400; }

/* ── Card ── */
.content-card {
    background:#fff; border:1px solid #e5e5e5;
    border-radius:8px; padding:16px; margin-bottom:16px;
}
.card-title {
    font-size:15px; font-weight:600; margin-bottom:12px;
    color:#1f2937; display:flex; align-items:center; gap:8px;
}
.card-title i { color:#6b7280; font-size:14px; }
.badge-count {
    background:#f3f4f6; color:#6b7280;
    font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;
}

/* ── Form base ── */
.form-group { margin-bottom:12px; }
.form-label {
    display:block; font-size:11px; font-weight:600;
    margin-bottom:5px; color:#374151; text-transform:uppercase; letter-spacing:.03em;
}
.form-input {
    width:100%; padding:8px 12px; border:1px solid #e5e5e5;
    border-radius:6px; font-size:13px; font-family:'Inter',sans-serif;
    transition:all .2s; box-sizing:border-box;
}
.form-input:focus { outline:none; border-color:#000; box-shadow:0 0 0 2px rgba(0,0,0,.05); }
textarea.form-input { resize:vertical; min-height:58px; }
.form-hint { display:block; font-size:10px; color:#9ca3af; margin-top:3px; }

/* ── Rs prefix/suffix ── */
.input-prefix-wrapper { position:relative; display:flex; align-items:center; }
.input-prefix {
    position:absolute; left:10px; font-size:12px; font-weight:700;
    color:#6b7280; pointer-events:none; z-index:1;
}
.input-with-prefix { padding-left:36px !important; }
.input-suffix {
    position:absolute; right:10px; font-size:11px; font-weight:600;
    color:#9ca3af; pointer-events:none;
}

/* ── Empty state ── */
.empty-state {
    text-align:center; padding:48px 20px; color:#9ca3af;
}
.empty-state i { font-size:36px; display:block; margin-bottom:12px; color:#d1d5db; }
.empty-state p { font-size:13px; }

/* ── Table ── */
.table-wrap { overflow-x:auto; }
.data-table { width:100%; border-collapse:collapse; font-size:12px; }
.data-table thead th {
    background:#f9fafb; border-bottom:1px solid #e5e7eb;
    padding:9px 12px; text-align:left; font-size:11px;
    font-weight:700; color:#374151; white-space:nowrap;
    text-transform:uppercase; letter-spacing:.04em;
}
.data-table tbody tr { border-bottom:1px solid #f3f4f6; transition:background .15s; }
.data-table tbody tr:hover { background:#fafafa; }
.data-table tbody td { padding:10px 12px; vertical-align:middle; }

/* ── Badges & pills ── */
.letter-badge {
    background:#eef2ff; color:#4338ca;
    font-size:11px; font-weight:700; padding:3px 9px;
    border-radius:5px; font-family:'JetBrains Mono',monospace; letter-spacing:.04em;
}
.amount-pill {
    font-size:11px; font-weight:700; padding:3px 9px;
    border-radius:20px; font-family:'JetBrains Mono',monospace;
}
.amount-grant { background:#ecfdf5; color:#065f46; }
.amount-appr  { background:#eff6ff; color:#1d4ed8; }
.days-badge {
    background:#fef9c3; color:#854d0e;
    font-size:11px; font-weight:600; padding:3px 9px; border-radius:20px;
}

/* ── Action buttons ── */
.btn-action {
    display:inline-flex; align-items:center; justify-content:center;
    width:30px; height:30px; border:none; border-radius:6px;
    cursor:pointer; font-size:12px; transition:all .2s; text-decoration:none;
}
.btn-print-ltr { background:#0f172a; color:#fff; }
.btn-print-ltr:hover { background:#1e293b; }
.btn-del { background:#fee2e2; color:#991b1b; }
.btn-del:hover { background:#fecaca; }

/* ── Buttons ── */
.btn {
    display:inline-flex; align-items:center; gap:6px;
    padding:8px 16px; border:none; border-radius:6px;
    font-size:13px; font-weight:600; cursor:pointer;
    transition:all .2s; text-decoration:none; font-family:'Inter',sans-serif;
}
.btn-primary { background:#000; color:#fff; }
.btn-primary:hover { background:#333; }
.btn-secondary { background:#f5f5f5; color:#333; border:1px solid #e5e5e5; }
.btn-secondary:hover { background:#e5e5e5; }

/* ── Alert ── */
.alert {
    padding:10px 14px; border-radius:6px; margin-bottom:16px;
    display:flex; align-items:center; gap:8px; font-size:12px; font-weight:500;
}
.alert-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.alert-error   { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }

/* ── Modal ── */
.modal-backdrop {
    position:fixed; inset:0; background:rgba(0,0,0,.45);
    z-index:1000; display:flex; align-items:center; justify-content:center;
    padding:16px;
}
.modal-box {
    background:#fff; border-radius:10px; width:100%;
    max-width:680px; max-height:90vh; display:flex;
    flex-direction:column; overflow:hidden;
    box-shadow:0 20px 60px rgba(0,0,0,.25);
}
.modal-header {
    display:flex; justify-content:space-between; align-items:center;
    padding:16px 20px; border-bottom:1px solid #e5e5e5; flex-shrink:0;
}
.modal-title {
    font-size:15px; font-weight:700; color:#1f2937;
    display:flex; align-items:center; gap:8px;
}
.modal-title i { color:#6b7280; }
.modal-close {
    background:none; border:none; cursor:pointer; color:#9ca3af;
    font-size:16px; padding:4px; border-radius:4px; transition:color .2s;
}
.modal-close:hover { color:#111; }
.modal-body { overflow-y:auto; padding:20px; flex:1; }
.modal-footer {
    display:flex; gap:8px; justify-content:flex-end;
    padding:14px 20px; border-top:1px solid #e5e5e5; flex-shrink:0;
    background:#f9fafb;
}
.modal-section-label {
    font-size:10px; font-weight:700; color:#6b7280;
    text-transform:uppercase; letter-spacing:.08em;
    margin:4px 0 10px; padding-bottom:6px;
    border-bottom:1px solid #f3f4f6;
}
.form-row-modal {
    display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:4px;
}

/* ── Letter preview box ── */
.letter-preview-box {
    background:#f8fafc; border:1px solid #e2e8f0;
    border-left:3px solid #6366f1; border-radius:6px;
    padding:14px 16px; margin-top:8px;
}
.preview-label {
    font-size:10px; font-weight:700; color:#6366f1;
    text-transform:uppercase; letter-spacing:.06em;
    margin-bottom:10px; display:flex; align-items:center; gap:6px;
}
.preview-text {
    font-family:'Georgia', serif; font-size:11.5px; line-height:1.9;
    color:#1e293b; white-space:pre-wrap;
}

/* ── Select2 ── */
.select2-container--default .select2-selection--single {
    height:34px !important; border:1px solid #e5e5e5 !important;
    border-radius:6px !important;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height:32px !important; padding-left:12px !important; font-size:13px !important;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height:32px !important;
}
.select2-container--default.select2-container--focus .select2-selection--single {
    border-color:#000 !important;
}

@media(max-width:640px) {
    .form-row-modal { grid-template-columns:1fr; }
}
</style>

<!-- jQuery + Select2 -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
/* ══════════════════════════════════════
   MODAL OPEN / CLOSE
══════════════════════════════════════ */
function openCreateModal() {
    document.getElementById('createModal').style.display = 'flex';
    setTimeout(() => {
        $('.select2-modal').select2({
            width: '100%',
            dropdownParent: $('#createModal')
        });
    }, 50);
    updatePreview();
}

function closeCreateModal() {
    document.getElementById('createModal').style.display = 'none';
}

document.getElementById('createModal').addEventListener('click', function(e) {
    if (e.target === this) closeCreateModal();
});

/* ══════════════════════════════════════
   LIVE PREVIEW
══════════════════════════════════════ */
function fmtMn(v) {
    return 'Rs ' + parseFloat(v || 0).toFixed(2) + 'Mn';
}

function updatePreview() {
    const grantMn  = parseFloat(document.getElementById('m_request_grant_amount').value) || 0;
    const days     = parseInt(document.getElementById('m_stl_days').value) || 21;
    const apprMn   = parseFloat(document.getElementById('m_stl_approved_amount').value) || 0;
    const accSel   = document.getElementById('m_company_account_id');
    const selOpt   = accSel.options[accSel.selectedIndex];
    const accNo    = selOpt && selOpt.value ? (selOpt.getAttribute('data-accno') || '___________') : '___________';
    const bankName = selOpt && selOpt.value ? (selOpt.getAttribute('data-bank') || '___________') : '___________';
    const branchName = selOpt && selOpt.value ? (selOpt.getAttribute('data-branch') || '') : '';

    const dateVal = document.getElementById('m_letter_date').value;
    const dateDisp = dateVal ? new Date(dateVal).toLocaleDateString('en-LK', {year:'numeric',month:'2-digit',day:'2-digit'}).replace(/\//g,'.') : 'YYYY.MM.DD';

    let previewHtml = '';

    if (grantMn > 0) {
        previewHtml =
            `<strong>Subject:</strong> Request for granting of STL amounting of Rs ${grantMn.toFixed(2)}Mn\n\n` +
            `Dear Sir,\n\n` +
            `Kindly request to grant STL amounting of <strong>Rs ${grantMn.toFixed(2)}Mn</strong> for ` +
            `<strong>${days} days</strong> as a part disbursement of approved STL of ` +
            `<u>Rs ${apprMn.toFixed(2)} Mn</u>\n\n` +
            `Please Credit the A/C – <strong>${accNo}</strong>\n` +
            (bankName !== '___________' ? `Bank: ${bankName}${branchName ? ', ' + branchName : ''}\n` : '') +
            `\nYour prompt action in this regard is much appreciated.`;
    } else {
        previewHtml = 'Fill in the fields above to preview the letter content.';
    }

    document.getElementById('previewText').innerHTML = previewHtml;
}

// Watch select2 change
$(document).on('change', '#m_company_account_id', updatePreview);
$(document).on('change', '#m_letter_date', updatePreview);

/* ══════════════════════════════════════
   SAVE LETTER
══════════════════════════════════════ */
function saveLetter() {
    const letterDate  = document.getElementById('m_letter_date').value;
    const accountId   = document.getElementById('m_company_account_id').value;
    const grantAmt    = document.getElementById('m_request_grant_amount').value;
    const stlDays     = document.getElementById('m_stl_days').value;
    const apprAmt     = document.getElementById('m_stl_approved_amount').value;
    const notes       = document.getElementById('m_notes').value;

    if (!letterDate || !accountId || !grantAmt) {
        showFlash('Please fill all required fields.', 'error');
        return;
    }

    const btn = document.getElementById('btnSaveLetter');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating…';

    fetch('stl_letters.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            ajax_action: 'save_stl_letter',
            letter_date: letterDate,
            company_account_id: accountId,
            request_grant_amount: grantAmt,
            stl_days: stlDays,
            stl_approved_amount: apprAmt,
            notes: notes
        })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Generate Letter';

        if (data.success) {
            closeCreateModal();
            showFlash('Letter ' + data.letter_no + ' generated successfully!', 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            showFlash('Error: ' + (data.error || 'Unknown error'), 'error');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Generate Letter';
        showFlash('Network error. Please try again.', 'error');
    });
}

/* ══════════════════════════════════════
   DELETE LETTER
══════════════════════════════════════ */
function deleteLetter(id, letterNo) {
    if (!confirm('Delete letter ' + letterNo + '? This cannot be undone.')) return;

    fetch('stl_letters.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ ajax_action: 'delete_stl_letter', id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('row-' + id)?.remove();
            showFlash('Letter ' + letterNo + ' deleted.', 'success');
        } else {
            showFlash('Error: ' + (data.error || 'Delete failed'), 'error');
        }
    });
}

/* ══════════════════════════════════════
   FLASH MESSAGE
══════════════════════════════════════ */
function showFlash(msg, type) {
    const el = document.getElementById('flashMsg');
    el.className = 'alert alert-' + type;
    el.innerHTML = '<i class="fa-solid fa-' + (type === 'success' ? 'circle-check' : 'circle-exclamation') + '"></i> ' + msg;
    el.style.display = 'flex';
    setTimeout(() => el.style.display = 'none', 4000);
}
</script>

<?php include 'footer.php'; ?>