<?php
include 'config.php';

// Ensure description column exists
@mysqli_query($conn, "ALTER TABLE company_bank_accounts ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER branch_code");
// Ensure sort_order column exists (controls the order accounts are listed everywhere)
@mysqli_query($conn, "ALTER TABLE company_bank_accounts ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0");

// Handle Delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $sql = "DELETE FROM company_bank_accounts WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        $success_message = "Bank account deleted successfully!";
    } else {
        $error_message = "Error deleting bank account: " . mysqli_error($conn);
    }
}

// Handle Edit - Get bank account data
$edit_mode = false;
$edit_account = null;
if (isset($_GET['edit'])) {
    $edit_mode = true;
    $id = intval($_GET['edit']);
    $sql = "SELECT * FROM company_bank_accounts WHERE id = $id";
    $result = mysqli_query($conn, $sql);
    $edit_account = mysqli_fetch_assoc($result);
}

// Handle form submission (Create or Update)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $company_id   = intval($_POST['company_id']);
    $account_type = mysqli_real_escape_string($conn, $_POST['account_type']);
    $account_name = mysqli_real_escape_string($conn, $_POST['account_name']);
    $account_no   = mysqli_real_escape_string($conn, $_POST['account_no']);
    $bank_code    = mysqli_real_escape_string($conn, $_POST['bank_code']);
    $branch_code  = mysqli_real_escape_string($conn, $_POST['branch_code']);
    $description  = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
    $active       = isset($_POST['active']) ? 1 : 0;
    $sort_order   = intval($_POST['sort_order'] ?? 0);

    if (isset($_POST['account_id']) && !empty($_POST['account_id'])) {
        $id = intval($_POST['account_id']);
        $sql = "UPDATE company_bank_accounts SET company_id='$company_id', account_type='$account_type', account_name='$account_name', account_no='$account_no', bank_code='$bank_code', branch_code='$branch_code', description='$description', sort_order=$sort_order, active='$active' WHERE id=$id";
        if (mysqli_query($conn, $sql)) {
            $success_message = "Bank account updated successfully!";
            $edit_mode = false;
            $edit_account = null;
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    } else {
        $sql = "INSERT INTO company_bank_accounts (company_id, account_type, account_name, account_no, bank_code, branch_code, description, sort_order, active) VALUES ('$company_id', '$account_type', '$account_name', '$account_no', '$bank_code', '$branch_code', '$description', $sort_order, '$active')";
        if (mysqli_query($conn, $sql)) {
            $success_message = "Bank account created successfully!";
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    }
}

// Get all companies for dropdown
$companies_result = mysqli_query($conn, "SELECT id, company_code, company_name FROM companies WHERE active = 1 ORDER BY company_name ASC");
$companies_arr = [];
while ($c = mysqli_fetch_assoc($companies_result)) {
    $companies_arr[] = $c;
}

// Get all banks for dropdown
$banks_result = mysqli_query($conn, "SELECT id, bank_code, bank_name FROM banks WHERE active = 1 ORDER BY bank_name ASC");
$banks_arr = [];
while ($b = mysqli_fetch_assoc($banks_result)) {
    $banks_arr[] = $b;
}

// Get all bank accounts with company + bank info
$accounts_sql = "
    SELECT cba.*, c.company_name, b.bank_name, bb.branch_name
    FROM company_bank_accounts cba
    LEFT JOIN companies c ON cba.company_id = c.id
    LEFT JOIN banks b ON cba.bank_code = b.bank_code
    LEFT JOIN bank_branches bb ON cba.branch_code = bb.branch_code AND bb.bank_code = cba.bank_code
    ORDER BY cba.sort_order ASC, cba.id ASC
";
$accounts_result = mysqli_query($conn, $accounts_sql);

// Next order number for new accounts
$next_order = intval(mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(MAX(sort_order),0) + 1 FROM company_bank_accounts"))[0] ?? 1);

include 'header.php';
?>

<!-- Select2 CSS & JS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- PAGE HEADER -->
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <h2 class="page-title">Company Bank Accounts</h2>
        <p class="page-subtitle">Manage bank accounts linked to companies</p>
    </div>
    <button class="btn btn-primary" onclick="openModal()">
        <i class="fa-solid fa-plus"></i> Add Bank Account
    </button>
</div>

<!-- ALERTS -->
<?php if (isset($success_message)): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i> <?php echo $success_message; ?>
</div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert alert-error">
    <i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?>
</div>
<?php endif; ?>

<!-- BANK ACCOUNTS TABLE -->
<div class="content-card">
    <h3 class="card-title">All Bank Accounts</h3>

    <?php if ($accounts_result && mysqli_num_rows($accounts_result) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>ID</th>
                    <th>Company</th>
                    <th>Account Type</th>
                    <th>Account Name</th>
                    <th>Account No</th>
                    <th>Bank</th>
                    <th>Branch</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($acc = mysqli_fetch_assoc($accounts_result)): ?>
                <tr>
                    <td><span class="order-badge"><?php echo intval($acc['sort_order']); ?></span></td>
                    <td><?php echo $acc['id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($acc['company_name']); ?></strong></td>
                    <td>
                        <?php if ($acc['account_type'] == 'current'): ?>
                            <span class="badge badge-type-current">Current Account</span>
                        <?php else: ?>
                            <span class="badge badge-type-savings">Savings Account</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($acc['account_name']); ?></td>
                    <td><code class="account-no"><?php echo htmlspecialchars($acc['account_no']); ?></code></td>
                    <td><?php echo htmlspecialchars($acc['bank_name'] ?? $acc['bank_code']); ?></td>
                    <td><?php echo htmlspecialchars($acc['branch_name'] ?? $acc['branch_code']); ?></td>
                    <td class="desc-cell">
                        <?php if (!empty($acc['description'])): ?>
                            <span title="<?php echo htmlspecialchars($acc['description']); ?>"><?php echo nl2br(htmlspecialchars($acc['description'])); ?></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($acc['active']): ?>
                            <span class="badge badge-success"><i class="fa-solid fa-circle-check"></i> Active</span>
                        <?php else: ?>
                            <span class="badge badge-inactive"><i class="fa-solid fa-circle-xmark"></i> Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn-action btn-edit" title="Edit"
                                onclick="openEditModal(
                                    '<?php echo $acc['id']; ?>',
                                    '<?php echo $acc['company_id']; ?>',
                                    '<?php echo $acc['account_type']; ?>',
                                    '<?php echo htmlspecialchars(addslashes($acc['account_name'])); ?>',
                                    '<?php echo htmlspecialchars(addslashes($acc['account_no'])); ?>',
                                    '<?php echo htmlspecialchars(addslashes($acc['bank_code'])); ?>',
                                    '<?php echo htmlspecialchars(addslashes($acc['branch_code'])); ?>',
                                    '<?php echo $acc['active']; ?>',
                                    <?php echo htmlspecialchars(json_encode($acc['description'] ?? '')); ?>,
                                    '<?php echo intval($acc['sort_order']); ?>'
                                )">
                                <i class="fa-solid fa-pen"></i>
                            </button>
                            <a href="?delete=<?php echo $acc['id']; ?>"
                               class="btn-action btn-delete" title="Delete"
                               onclick="return confirm('Are you sure you want to delete this bank account?')">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <i class="fa-solid fa-building-columns"></i>
        <p>No bank accounts found.</p>
        <button class="btn btn-primary" onclick="openModal()">
            <i class="fa-solid fa-plus"></i> Add First Account
        </button>
    </div>
    <?php endif; ?>
</div>


<!-- ═══════════════ MODAL ═══════════════ -->
<div id="bankModal" class="modal-overlay" onclick="handleOverlayClick(event)">
    <div class="modal-box">

        <div class="modal-header">
            <div>
                <h3 class="modal-title" id="modalTitle">Add Bank Account</h3>
                <p class="modal-subtitle" id="modalSubtitle">Fill in the details below</p>
            </div>
            <button class="modal-close" onclick="closeModal()" title="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="modal-body">
            <form method="POST" action="" id="bankForm">
                <input type="hidden" name="account_id" id="modal_account_id">

                <div class="form-grid">

                    <!-- Company – full width -->
                    <div class="form-group full-width">
                        <label class="form-label">Company <span class="required">*</span></label>
                        <select name="company_id" id="modal_company_id" class="form-select modal-select2" required>
                            <option value="">-- Select Company --</option>
                            <?php foreach ($companies_arr as $c): ?>
                            <option value="<?php echo $c['id']; ?>">
                                [<?php echo htmlspecialchars($c['company_code']); ?>] <?php echo htmlspecialchars($c['company_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Account Type -->
                    <div class="form-group">
                        <label class="form-label">Account Type <span class="required">*</span></label>
                        <select name="account_type" id="modal_account_type" class="form-select modal-select2" required>
                            <option value="">-- Select Type --</option>
                            <option value="current">Current Account</option>
                            <option value="savings">Savings Account</option>
                        </select>
                    </div>

                    <!-- Account Name -->
                    <div class="form-group">
                        <label class="form-label">Account Name <span class="required">*</span></label>
                        <input type="text" name="account_name" id="modal_account_name"
                               class="form-input" placeholder="Enter account name" required>
                    </div>

                    <!-- Account No – full width -->
                    <div class="form-group full-width">
                        <label class="form-label">Account No <span class="required">*</span></label>
                        <input type="text" name="account_no" id="modal_account_no"
                               class="form-input" placeholder="Enter account number" required>
                    </div>

                    <!-- Bank -->
                    <div class="form-group">
                        <label class="form-label">Bank <span class="required">*</span></label>
                        <select name="bank_code" id="modal_bank_code" class="form-select modal-select2" required>
                            <option value="">-- Select Bank --</option>
                            <?php foreach ($banks_arr as $b): ?>
                            <option value="<?php echo htmlspecialchars($b['bank_code']); ?>">
                                <?php echo htmlspecialchars($b['bank_code'] . ' - ' . $b['bank_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Branch – populated dynamically -->
                    <div class="form-group">
                        <label class="form-label">Bank Branch <span class="required">*</span></label>
                        <select name="branch_code" id="modal_branch_code" class="form-select modal-select2" required>
                            <option value="">-- Select Bank First --</option>
                        </select>
                        <!-- Loading spinner -->
                        <div id="branchLoader" class="branch-loader" style="display:none;">
                            <span class="spinner"></span> Loading branches...
                        </div>
                    </div>

                    <!-- Display order -->
                    <div class="form-group full-width">
                        <label class="form-label">Display Order</label>
                        <input type="number" name="sort_order" id="modal_sort_order" class="form-input" step="1"
                               placeholder="1 = shown first" style="max-width:200px">
                        <div style="font-size:12px;color:#888;margin-top:6px;">Lower numbers are shown first in Bank Balances and the Cash Flow dashboard.</div>
                    </div>

                    <!-- Description – full width -->
                    <div class="form-group full-width">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="modal_description" class="form-input form-textarea"
                                  rows="3" placeholder="Enter a description (optional)"></textarea>
                    </div>

                    <!-- Status – full width -->
                    <div class="form-group full-width">
                        <label class="form-label">Status</label>
                        <div class="checkbox-wrapper">
                            <label class="checkbox-label">
                                <input type="checkbox" name="active" id="modal_active" class="form-checkbox" checked>
                                <span class="checkbox-text">Active</span>
                            </label>
                        </div>
                    </div>

                </div><!-- /form-grid -->

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">
                        <i class="fa-solid fa-xmark"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fa-solid fa-plus"></i> Add Account
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>


<!-- ═══════════════ STYLES ═══════════════ -->
<style>
/* Alerts */
.alert { padding:16px 20px; border-radius:8px; margin-bottom:24px; display:flex; align-items:center; gap:12px; font-size:13px; font-weight:500; }
.alert i { font-size:18px; }
.alert-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.alert-error   { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }

/* Form elements */
.form-label { display:block; font-size:13px; font-weight:600; margin-bottom:8px; color:#333; }
.required { color:#ef4444; }
.form-input { width:100%; padding:12px 16px; border:1px solid #e5e5e5; border-radius:8px; font-size:14px; font-family:'Inter',sans-serif; transition:all 0.3s; background:#fff; box-sizing:border-box; }
.form-input:focus { outline:none; border-color:#000; box-shadow:0 0 0 3px rgba(0,0,0,0.05); }
.form-input::placeholder { color:#999; }
.form-textarea { resize:vertical; min-height:80px; line-height:1.5; }
.desc-cell { max-width:260px; white-space:normal; word-break:break-word; font-size:12px; color:#555; }
.text-muted { color:#bbb; }
.order-badge { display:inline-block; min-width:26px; text-align:center; padding:2px 6px; border-radius:6px; background:#f5f5f5; border:1px solid #e5e5e5; font-weight:600; font-size:12px; }
.checkbox-wrapper { display:flex; align-items:center; }
.checkbox-label { display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px; color:#333; }
.form-checkbox { width:18px; height:18px; cursor:pointer; accent-color:#000; }
.checkbox-text { font-weight:500; }

/* Branch loading indicator */
.branch-loader { display:flex; align-items:center; gap:8px; font-size:12px; color:#888; margin-top:6px; }
.spinner { width:14px; height:14px; border:2px solid #e5e5e5; border-top-color:#000; border-radius:50%; display:inline-block; animation:spin 0.6s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }

/* Branch select disabled state */
#modal_branch_code:disabled + .branch-loader { display:flex !important; }
.select2-container--disabled .select2-selection--single { background:#fafafa !important; cursor:not-allowed !important; }

/* Buttons */
.btn { display:inline-flex; align-items:center; gap:8px; padding:12px 24px; border:none; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; transition:all 0.3s; text-decoration:none; font-family:'Inter',sans-serif; }
.btn i { font-size:16px; }
.btn-primary { background:#000; color:#fff; }
.btn-primary:hover { background:#333; transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,0.15); }
.btn-secondary { background:#f5f5f5; color:#333; border:1px solid #e5e5e5; }
.btn-secondary:hover { background:#e5e5e5; }

/* Table */
.table-responsive { overflow-x:auto; margin-top:20px; }
.data-table { width:100%; border-collapse:collapse; font-size:13px; }
.data-table thead { background:#fafafa; border-bottom:2px solid #e5e5e5; }
.data-table th { padding:12px 16px; text-align:left; font-weight:600; color:#333; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; }
.data-table tbody tr { border-bottom:1px solid #f0f0f0; transition:background 0.2s; }
.data-table tbody tr:hover { background:#fafafa; }
.data-table td { padding:14px 16px; color:#333; }
.account-no { background:#f5f5f5; padding:3px 8px; border-radius:4px; font-size:12px; letter-spacing:0.5px; font-family:monospace; }

/* Badges */
.badge { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:12px; font-size:11px; font-weight:600; }
.badge i { font-size:10px; }
.badge-success      { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.badge-inactive     { background:#fafafa; color:#666; border:1px solid #e5e5e5; }
.badge-type-current { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
.badge-type-savings { background:#fdf4ff; color:#7e22ce; border:1px solid #e9d5ff; }

/* Action buttons */
.action-buttons { display:flex; gap:8px; }
.btn-action { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:6px; border:1px solid #e5e5e5; background:#fff; color:#666; cursor:pointer; transition:all 0.2s; text-decoration:none; }
.btn-action:hover { transform:translateY(-2px); box-shadow:0 2px 8px rgba(0,0,0,0.1); }
.btn-edit:hover   { background:#000; color:#fff; border-color:#000; }
.btn-delete:hover { background:#ef4444; color:#fff; border-color:#ef4444; }

/* Empty state */
.empty-state { text-align:center; padding:48px 20px; color:#999; }
.empty-state i { font-size:40px; margin-bottom:16px; display:block; color:#ddd; }
.empty-state p { font-size:14px; margin-bottom:20px; }

/* ─── MODAL ─── */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9999; align-items:center; justify-content:center; padding:20px; backdrop-filter:blur(2px); }
.modal-overlay.active { display:flex; animation:mFadeIn 0.2s ease; }
@keyframes mFadeIn { from { opacity:0; } to { opacity:1; } }
@keyframes mSlideUp { from { opacity:0; transform:translateY(28px); } to { opacity:1; transform:translateY(0); } }
.modal-box { background:#fff; border-radius:16px; width:100%; max-width:680px; max-height:92vh; overflow-y:auto; box-shadow:0 24px 64px rgba(0,0,0,0.2); animation:mSlideUp 0.25s ease; }
.modal-header { display:flex; align-items:flex-start; justify-content:space-between; padding:28px 28px 20px; gap:16px; border-bottom:1px solid #f0f0f0; }
.modal-title  { font-size:18px; font-weight:700; color:#111; margin:0 0 4px; }
.modal-subtitle { font-size:13px; color:#888; margin:0; }
.modal-close { width:36px; height:36px; border-radius:8px; border:1px solid #e5e5e5; background:#fafafa; color:#555; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:16px; flex-shrink:0; transition:all 0.2s; }
.modal-close:hover { background:#000; color:#fff; border-color:#000; }
.modal-body { padding:24px 28px; }
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:0 20px; }
.form-group { margin-bottom:20px; }
.form-group.full-width { grid-column:1 / -1; }
.modal-footer { display:flex; justify-content:flex-end; gap:12px; padding-top:16px; border-top:1px solid #f0f0f0; margin-top:4px; }

/* ─── Select2 theme ─── */
.select2-container { width:100% !important; }
.select2-container--default .select2-selection--single { height:46px; border:1px solid #e5e5e5; border-radius:8px; background:#fff; display:flex; align-items:center; transition:all 0.3s; padding:0 16px; }
.select2-container--default.select2-container--open .select2-selection--single,
.select2-container--default.select2-container--focus .select2-selection--single { border-color:#000; box-shadow:0 0 0 3px rgba(0,0,0,0.05); outline:none; }
.select2-container--default .select2-selection--single .select2-selection__rendered { color:#333; font-size:14px; font-family:'Inter',sans-serif; line-height:44px; padding:0; }
.select2-container--default .select2-selection--single .select2-selection__placeholder { color:#999; }
.select2-container--default .select2-selection--single .select2-selection__arrow { height:44px; right:12px; }
.select2-container--default .select2-selection--single .select2-selection__arrow b { border-color:#666 transparent transparent transparent; }
.select2-container--default.select2-container--open .select2-selection--single .select2-selection__arrow b { border-color:transparent transparent #000 transparent; }
.select2-dropdown { border:1px solid #e5e5e5; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,0.1); font-family:'Inter',sans-serif; font-size:14px; overflow:hidden; z-index:99999 !important; }
.select2-container--default .select2-search--dropdown .select2-search__field { border:1px solid #e5e5e5; border-radius:6px; padding:8px 12px; font-size:13px; font-family:'Inter',sans-serif; }
.select2-container--default .select2-search--dropdown .select2-search__field:focus { border-color:#000; outline:none; }
.select2-container--default .select2-results__option { padding:10px 16px; color:#333; font-size:14px; }
.select2-container--default .select2-results__option--highlighted[aria-selected] { background:#000; color:#fff; }
.select2-container--default .select2-results__option[aria-selected=true] { background:#f5f5f5; color:#333; }

/* Responsive */
@media (max-width: 600px) {
    .form-grid { grid-template-columns:1fr; }
    .modal-box { border-radius:12px; }
    .modal-header, .modal-body { padding-left:16px; padding-right:16px; }
    .modal-footer { flex-direction:column-reverse; }
    .modal-footer .btn { width:100%; justify-content:center; }
    .data-table { font-size:12px; }
    .data-table th, .data-table td { padding:10px; }
}
</style>


<!-- ═══════════════ SCRIPTS ═══════════════ -->
<script>
$(document).ready(function () {

    // ── Init Select2 ──
    initModalSelect2();

    // ── When Bank changes → load branches ──
    $('#modal_bank_code').on('change', function () {
        const bankCode = $(this).val();
        loadBankBranches(bankCode, null);
    });

    // ── Auto-open for edit (via GET ?edit=) ──
    <?php if ($edit_mode && $edit_account): ?>
    openEditModal(
        '<?php echo $edit_account['id']; ?>',
        '<?php echo $edit_account['company_id']; ?>',
        '<?php echo $edit_account['account_type']; ?>',
        '<?php echo htmlspecialchars(addslashes($edit_account['account_name'])); ?>',
        '<?php echo htmlspecialchars(addslashes($edit_account['account_no'])); ?>',
        '<?php echo htmlspecialchars(addslashes($edit_account['bank_code'])); ?>',
        '<?php echo htmlspecialchars(addslashes($edit_account['branch_code'])); ?>',
        '<?php echo $edit_account['active']; ?>',
        <?php echo json_encode($edit_account['description'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        '<?php echo intval($edit_account['sort_order'] ?? 0); ?>'
    );
    <?php endif; ?>

    // ── Auto-open on failed POST ──
    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($error_message)): ?>
    openModal();
    $('#modal_account_id').val('<?php echo isset($_POST['account_id']) ? $_POST['account_id'] : ''; ?>');
    $('#modal_company_id').val('<?php echo isset($_POST['company_id']) ? intval($_POST['company_id']) : ''; ?>').trigger('change');
    $('#modal_account_type').val('<?php echo isset($_POST['account_type']) ? htmlspecialchars(addslashes($_POST['account_type'])) : ''; ?>').trigger('change');
    $('#modal_account_name').val('<?php echo isset($_POST['account_name']) ? htmlspecialchars(addslashes($_POST['account_name'])) : ''; ?>');
    $('#modal_account_no').val('<?php echo isset($_POST['account_no']) ? htmlspecialchars(addslashes($_POST['account_no'])) : ''; ?>');
    $('#modal_sort_order').val('<?php echo isset($_POST['sort_order']) ? intval($_POST['sort_order']) : ''; ?>');
    $('#modal_description').val(<?php echo json_encode($_POST['description'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>);
    $('#modal_active').prop('checked', <?php echo (isset($_POST['active']) ? 'true' : 'false'); ?>);
    // Restore bank + branch
    <?php if (!empty($_POST['bank_code'])): ?>
    loadBankBranches('<?php echo addslashes($_POST['bank_code']); ?>', '<?php echo addslashes($_POST['branch_code'] ?? ''); ?>');
    <?php endif; ?>
    <?php if (isset($_POST['account_id']) && !empty($_POST['account_id'])): ?>
    $('#modalTitle').text('Edit Bank Account');
    $('#modalSubtitle').text('Update the bank account information');
    $('#submitBtn').html('<i class="fa-solid fa-check"></i> Update Account');
    <?php endif; ?>
    <?php endif; ?>
});

// ── Init Select2 inside modal ──
function initModalSelect2() {
    $('.modal-select2').select2({
        dropdownParent: $('#bankModal'),
        allowClear: true,
        width: '100%'
    });
}

// ── Load bank branches via AJAX (same as add_customer.php pattern) ──
function loadBankBranches(bankCode, preselectBranchCode) {
    const $branchSelect = $('#modal_branch_code');
    const $loader       = $('#branchLoader');

    // Reset branch dropdown
    $branchSelect.val(null).trigger('change');
    $branchSelect.empty().append('<option value="">-- Select Bank First --</option>');

    if (!bankCode) {
        // No bank selected – disable branch
        $branchSelect.prop('disabled', true).trigger('change');
        return;
    }

    // Show loader, disable branch while fetching
    $loader.show();
    $branchSelect.prop('disabled', true).trigger('change');

    fetch('get_bank_branches.php?bank_code=' + encodeURIComponent(bankCode))
        .then(response => response.json())
        .then(data => {
            $branchSelect.empty().append('<option value="">-- Select Branch --</option>');

            if (data.length === 0) {
                $branchSelect.append('<option value="" disabled>No branches found</option>');
            } else {
                data.forEach(function (branch) {
                    const opt = new Option(
                        branch.branch_code + ' - ' + branch.branch_name,
                        branch.branch_code,
                        false,
                        branch.branch_code === preselectBranchCode
                    );
                    $branchSelect.append(opt);
                });
            }

            $branchSelect.prop('disabled', false);

            // Refresh Select2 to show new options
            $branchSelect.trigger('change');

            // Preselect if we have a value (edit mode)
            if (preselectBranchCode) {
                $branchSelect.val(preselectBranchCode).trigger('change');
            }
        })
        .catch(err => {
            console.error('Error loading branches:', err);
            $branchSelect.empty().append('<option value="">Error loading branches</option>');
            $branchSelect.prop('disabled', false).trigger('change');
        })
        .finally(() => {
            $loader.hide();
        });
}

// ── Open modal (add mode) ──
function openModal() {
    $('#bankForm')[0].reset();
    $('#modal_account_id').val('');
    $('.modal-select2').val(null).trigger('change');
    $('#modal_active').prop('checked', true);
    $('#modal_sort_order').val('<?php echo $next_order; ?>');

    // Reset branch dropdown
    $('#modal_branch_code').empty()
        .append('<option value="">-- Select Bank First --</option>')
        .prop('disabled', true)
        .trigger('change');

    $('#modalTitle').text('Add Bank Account');
    $('#modalSubtitle').text('Fill in the details to add a new bank account');
    $('#submitBtn').html('<i class="fa-solid fa-plus"></i> Add Account');
    $('#bankModal').addClass('active');
    document.body.style.overflow = 'hidden';
}

// ── Open modal (edit mode) ──
function openEditModal(id, company_id, account_type, account_name, account_no, bank_code, branch_code, active, description, sort_order) {
    // Basic fields
    $('#modal_account_id').val(id);
    $('#modal_sort_order').val(sort_order || 0);
    $('#modal_description').val(description || '');
    $('#modal_company_id').val(company_id).trigger('change');
    $('#modal_account_type').val(account_type).trigger('change');
    $('#modal_account_name').val(account_name);
    $('#modal_account_no').val(account_no);
    $('#modal_active').prop('checked', active == 1);

    // Bank → then auto-load and preselect branch
    $('#modal_bank_code').val(bank_code).trigger('change');
    loadBankBranches(bank_code, branch_code);

    $('#modalTitle').text('Edit Bank Account');
    $('#modalSubtitle').text('Update the bank account information');
    $('#submitBtn').html('<i class="fa-solid fa-check"></i> Update Account');
    $('#bankModal').addClass('active');
    document.body.style.overflow = 'hidden';
}

// ── Close modal ──
function closeModal() {
    $('#bankModal').removeClass('active');
    document.body.style.overflow = '';
}

function handleOverlayClick(e) {
    if (e.target.id === 'bankModal') closeModal();
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeModal();
});
</script>

<?php include 'footer.php'; ?>