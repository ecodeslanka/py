<?php
include 'config.php';

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $agreement_no       = mysqli_real_escape_string($conn, trim($_POST['agreement_no'] ?? ''));
    $stl_limit_amount   = floatval($_POST['stl_limit_amount'] ?? 0);
    $effective_date     = mysqli_real_escape_string($conn, $_POST['effective_date'] ?? '');
    $status             = mysqli_real_escape_string($conn, $_POST['status'] ?? 'active');
    $notes              = mysqli_real_escape_string($conn, trim($_POST['notes'] ?? ''));

    // Check if a record already exists
    $check_sql = "SELECT id FROM stl_settings LIMIT 1";
    $check_result = mysqli_query($conn, $check_sql);

    if ($check_result && mysqli_num_rows($check_result) > 0) {
        $row = mysqli_fetch_assoc($check_result);
        $sql = "UPDATE stl_settings SET
                    agreement_no     = '$agreement_no',
                    stl_limit_amount = '$stl_limit_amount',
                    effective_date   = " . ($effective_date ? "'$effective_date'" : "NULL") . ",
                    status           = '$status',
                    notes            = '$notes',
                    updated_at       = NOW()
                WHERE id = {$row['id']}";
    } else {
        $sql = "INSERT INTO stl_settings (agreement_no, stl_limit_amount, effective_date, status, notes, created_at, updated_at)
                VALUES ('$agreement_no', '$stl_limit_amount', " . ($effective_date ? "'$effective_date'" : "NULL") . ", '$status', '$notes', NOW(), NOW())";
    }

    if (mysqli_query($conn, $sql)) {
        $success_message = "STL Settings updated successfully.";
    } else {
        $error_message = "Error: " . mysqli_error($conn);
    }
}

// Load current settings
$settings = [];
$load_sql = "SELECT * FROM stl_settings LIMIT 1";
$load_result = mysqli_query($conn, $load_sql);
if ($load_result && mysqli_num_rows($load_result) > 0) {
    $settings = mysqli_fetch_assoc($load_result);
}

include 'header.php';
?>

<div class="page-header">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 class="page-title">STL Settings</h2>
            <p class="page-subtitle">Manage STL agreement and credit limit configuration</p>
        </div>
        <a href="settings.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i>
            Back to Settings
        </a>
    </div>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i>
    <?php echo htmlspecialchars($success_message); ?>
</div>
<?php endif; ?>

<?php if ($error_message): ?>
<div class="alert alert-error">
    <i class="fa-solid fa-circle-exclamation"></i>
    <?php echo htmlspecialchars($error_message); ?>
</div>
<?php endif; ?>

<form method="POST" action="" id="stlSettingsForm">

    <!-- Agreement Information -->
    <div class="content-card">
        <h3 class="card-title">
            <i class="fa-solid fa-file-contract"></i> Agreement Information
        </h3>

        <div class="form-row">
            <div class="form-group required-field">
                <label class="form-label">Agreement No <span class="required">*</span></label>
                <input type="text"
                       name="agreement_no"
                       class="form-input"
                       required
                       placeholder="Enter agreement number"
                       value="<?php echo htmlspecialchars($settings['agreement_no'] ?? ''); ?>">
                <span class="form-hint">Unique STL agreement reference number</span>
            </div>

            <div class="form-group">
                <label class="form-label">Effective Date</label>
                <input type="date"
                       name="effective_date"
                       class="form-input"
                       value="<?php echo htmlspecialchars($settings['effective_date'] ?? ''); ?>">
                <span class="form-hint">Date from which this agreement is effective</span>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group required-field">
                <label class="form-label">Status <span class="required">*</span></label>
                <select name="status" class="form-input select2" required>
                    <option value="active"   <?php echo (($settings['status'] ?? '') === 'active'   ? 'selected' : ''); ?>>Active</option>
                    <option value="inactive" <?php echo (($settings['status'] ?? '') === 'inactive' ? 'selected' : ''); ?>>Inactive</option>
                    <option value="expired"  <?php echo (($settings['status'] ?? '') === 'expired'  ? 'selected' : ''); ?>>Expired</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-input" rows="2" placeholder="Optional notes or remarks"><?php echo htmlspecialchars($settings['notes'] ?? ''); ?></textarea>
            </div>
        </div>
    </div>

    <!-- STL Limit Configuration -->
    <div class="content-card">
        <h3 class="card-title">
            <i class="fa-solid fa-gauge-high"></i> STL Limit Configuration
        </h3>

        <div class="form-row">
            <div class="form-group required-field">
                <label class="form-label">STL Limit Amount (Rs.) <span class="required">*</span></label>
                <div class="input-prefix-wrapper">
                    <span class="input-prefix">Rs.</span>
                    <input type="number"
                           name="stl_limit_amount"
                           class="form-input input-with-prefix"
                           step="0.01"
                           min="0"
                           required
                           placeholder="0.00"
                           value="<?php echo htmlspecialchars($settings['stl_limit_amount'] ?? '0'); ?>">
                </div>
                <span class="form-hint">Maximum credit exposure allowed under this STL agreement</span>
            </div>

            <div class="form-group">
                <label class="form-label">&nbsp;</label>
                <div class="stl-summary-box">
                    <div class="summary-row">
                        <span class="summary-label"><i class="fa-solid fa-file-contract"></i> Agreement No</span>
                        <span class="summary-value" id="preview_agreement">
                            <?php echo htmlspecialchars($settings['agreement_no'] ?? '—'); ?>
                        </span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label"><i class="fa-solid fa-circle-dollar-to-slot"></i> STL Limit</span>
                        <span class="summary-value" id="preview_limit">
                            Rs. <?php echo number_format(floatval($settings['stl_limit_amount'] ?? 0), 2); ?>
                        </span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label"><i class="fa-solid fa-circle-dot"></i> Status</span>
                        <span id="preview_status">
                            <?php
                            $st = $settings['status'] ?? 'active';
                            $badge_class = $st === 'active' ? 'badge-active' : ($st === 'expired' ? 'badge-expired' : 'badge-inactive');
                            echo '<span class="status-badge ' . $badge_class . '">' . ucfirst($st) . '</span>';
                            ?>
                        </span>
                    </div>
                    <?php if (!empty($settings['updated_at'])): ?>
                    <div class="summary-row">
                        <span class="summary-label"><i class="fa-solid fa-clock-rotate-left"></i> Last Updated</span>
                        <span class="summary-value">
                            <?php echo date('d M Y, H:i', strtotime($settings['updated_at'])); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="form-actions">
        <a href="settings.php" class="btn btn-secondary">
            <i class="fa-solid fa-xmark"></i>
            Cancel
        </a>
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-floppy-disk"></i>
            Update Settings
        </button>
    </div>

</form>

<style>
/* Required field highlight */
.required-field .form-input,
.required-field .select2-container--default .select2-selection--single {
    background-color: #fffbeb !important;
}

.required {
    color: #ef4444;
}

/* Content Card */
.content-card {
    background: #ffffff;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
}

.card-title {
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 16px;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 8px;
}

.card-title i {
    color: #6b7280;
    font-size: 14px;
}

/* Form Styles */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 12px;
}

.form-group {
    margin-bottom: 12px;
}

.form-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    margin-bottom: 6px;
    color: #374151;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.form-input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #e5e5e5;
    border-radius: 6px;
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    transition: all 0.2s;
    box-sizing: border-box;
}

.form-input:focus {
    outline: none;
    border-color: #000000;
    box-shadow: 0 0 0 2px rgba(0,0,0,0.05);
}

textarea.form-input {
    resize: vertical;
    min-height: 60px;
}

.form-hint {
    display: block;
    font-size: 10px;
    color: #9ca3af;
    margin-top: 4px;
}

/* Rs. Prefix Input */
.input-prefix-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.input-prefix {
    position: absolute;
    left: 10px;
    font-size: 12px;
    font-weight: 700;
    color: #6b7280;
    pointer-events: none;
    z-index: 1;
}

.input-with-prefix {
    padding-left: 36px !important;
}

/* STL Summary Box */
.stl-summary-box {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
}

.summary-label {
    color: #6b7280;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
}

.summary-label i {
    width: 12px;
    text-align: center;
}

.summary-value {
    font-weight: 600;
    color: #111827;
    font-size: 12px;
    font-family: 'JetBrains Mono', monospace;
}

/* Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    text-transform: capitalize;
}

.badge-active {
    background: #d1fae5;
    color: #065f46;
}

.badge-inactive {
    background: #f3f4f6;
    color: #4b5563;
}

.badge-expired {
    background: #fee2e2;
    color: #991b1b;
}

/* Form Actions */
.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 16px;
    padding: 12px;
    background: #f9fafb;
    border-radius: 6px;
}

/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    font-family: 'Inter', sans-serif;
}

.btn-primary {
    background: #000000;
    color: #ffffff;
}

.btn-primary:hover {
    background: #333333;
}

.btn-secondary {
    background: #f5f5f5;
    color: #333333;
    border: 1px solid #e5e5e5;
}

.btn-secondary:hover {
    background: #e5e5e5;
}

/* Select2 */
.select2-container--default .select2-selection--single {
    height: 34px !important;
    border: 1px solid #e5e5e5 !important;
    border-radius: 6px !important;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 32px !important;
    padding-left: 12px !important;
    font-size: 13px !important;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 32px !important;
}

.select2-container--default.select2-container--focus .select2-selection--single {
    border-color: #000000 !important;
}

/* Alerts */
.alert {
    padding: 10px 14px;
    border-radius: 6px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 500;
}

.alert-success {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.alert-error {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }

    .form-actions {
        flex-direction: column;
    }

    .form-actions .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function () {
    $('.select2').select2({ width: '100%' });

    // Live preview — Agreement No
    $('input[name="agreement_no"]').on('input', function () {
        const val = $(this).val().trim();
        $('#preview_agreement').text(val || '—');
    });

    // Live preview — STL Limit
    $('input[name="stl_limit_amount"]').on('input', function () {
        const val = parseFloat($(this).val()) || 0;
        $('#preview_limit').text('Rs. ' + val.toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    });

    // Live preview — Status badge
    $('select[name="status"]').on('change', function () {
        const val = $(this).val();
        const label = val.charAt(0).toUpperCase() + val.slice(1);
        const cls = val === 'active' ? 'badge-active' : (val === 'expired' ? 'badge-expired' : 'badge-inactive');
        $('#preview_status').html('<span class="status-badge ' + cls + '">' + label + '</span>');
    });
});
</script>

<?php include 'footer.php'; ?>