<?php
include 'config.php';

// Handle form submission (Update rates)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $epf_employer = floatval($_POST['epf_employer']);
    $epf_employee = floatval($_POST['epf_employee']);
    $etf_employer = floatval($_POST['etf_employer']);

    // Check if a record already exists
    $check_sql = "SELECT id FROM epf_etf_settings LIMIT 1";
    $check_result = mysqli_query($conn, $check_sql);

    if (mysqli_num_rows($check_result) > 0) {
        // Update existing record
        $row = mysqli_fetch_assoc($check_result);
        $id = $row['id'];
        $sql = "UPDATE epf_etf_settings SET epf_employer = '$epf_employer', epf_employee = '$epf_employee', etf_employer = '$etf_employer', updated_at = NOW() WHERE id = $id";
    } else {
        // Insert first record
        $sql = "INSERT INTO epf_etf_settings (epf_employer, epf_employee, etf_employer) VALUES ('$epf_employer', '$epf_employee', '$etf_employer')";
    }

    if (mysqli_query($conn, $sql)) {
        $success_message = "EPF/ETF settings updated successfully!";
    } else {
        $error_message = "Error updating settings: " . mysqli_error($conn);
    }
}

// Get current settings
$settings_sql = "SELECT * FROM epf_etf_settings LIMIT 1";
$settings_result = mysqli_query($conn, $settings_sql);
$settings = $settings_result ? mysqli_fetch_assoc($settings_result) : null;

include 'header.php';
?>

<!-- EPF/ETF Settings Page -->
<div class="page-header">
    <h2 class="page-title">EPF / ETF Settings</h2>
    <p class="page-subtitle">Configure EPF and ETF contribution rates</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i>
    <?php echo $success_message; ?>
</div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
<div class="alert alert-error">
    <i class="fa-solid fa-circle-exclamation"></i>
    <?php echo $error_message; ?>
</div>
<?php endif; ?>

<!-- Info Banner -->
<div class="info-banner">
    <i class="fa-solid fa-circle-info"></i>
    <span>These rates are applied as percentages (%) of the employee's basic salary. Changes will take effect on the next payroll cycle.</span>
</div>

<div class="settings-grid">

    <!-- EPF Section -->
    <div class="content-card section-card">
        <div class="section-header epf-header">
            <div class="section-icon">
                <i class="fa-solid fa-building-columns"></i>
            </div>
            <div>
                <h3 class="section-title">EPF — Employees' Provident Fund</h3>
                <p class="section-subtitle">Set contribution rates for employer and employee</p>
            </div>
        </div>

        <form method="POST" action="" class="form" id="epfEtfForm">
            <div class="rates-row">
                <div class="rate-card">
                    <div class="rate-label">
                        <i class="fa-solid fa-briefcase"></i>
                        Employer EPF Rate
                    </div>
                    <div class="rate-input-wrap">
                        <input
                            type="number"
                            name="epf_employer"
                            id="epf_employer"
                            class="rate-input"
                            placeholder="0.00"
                            value="<?php echo $settings ? htmlspecialchars($settings['epf_employer']) : '12.00'; ?>"
                            step="0.01"
                            min="0"
                            max="100"
                            required
                        >
                        <span class="rate-suffix">%</span>
                    </div>
                    <small class="rate-hint">Standard rate: 12%</small>
                </div>

                <div class="rate-divider">
                    <i class="fa-solid fa-plus"></i>
                </div>

                <div class="rate-card">
                    <div class="rate-label">
                        <i class="fa-solid fa-user"></i>
                        Employee EPF Rate
                    </div>
                    <div class="rate-input-wrap">
                        <input
                            type="number"
                            name="epf_employee"
                            id="epf_employee"
                            class="rate-input"
                            placeholder="0.00"
                            value="<?php echo $settings ? htmlspecialchars($settings['epf_employee']) : '8.00'; ?>"
                            step="0.01"
                            min="0"
                            max="100"
                            required
                        >
                        <span class="rate-suffix">%</span>
                    </div>
                    <small class="rate-hint">Standard rate: 8%</small>
                </div>
            </div>
    </div>

    <!-- ETF Section -->
    <div class="content-card section-card">
        <div class="section-header etf-header">
            <div class="section-icon etf-icon">
                <i class="fa-solid fa-hands-holding-circle"></i>
            </div>
            <div>
                <h3 class="section-title">ETF — Employees' Trust Fund</h3>
                <p class="section-subtitle">Set contribution rate for employer</p>
            </div>
        </div>

        <div class="rates-row rates-single">
            <div class="rate-card">
                <div class="rate-label">
                    <i class="fa-solid fa-briefcase"></i>
                    Employer ETF Rate
                </div>
                <div class="rate-input-wrap">
                    <input
                        type="number"
                        name="etf_employer"
                        id="etf_employer"
                        class="rate-input"
                        placeholder="0.00"
                        value="<?php echo $settings ? htmlspecialchars($settings['etf_employer']) : '3.00'; ?>"
                        step="0.01"
                        min="0"
                        max="100"
                        required
                    >
                    <span class="rate-suffix">%</span>
                </div>
                <small class="rate-hint">Standard rate: 3%</small>
            </div>
        </div>
    </div>

</div>

<!-- Summary Card -->
<div class="content-card summary-card">
    <h3 class="card-title">Contribution Summary</h3>
    <div class="summary-table">
        <div class="summary-row summary-head">
            <span>Contribution</span>
            <span>Party</span>
            <span>Rate</span>
        </div>
        <div class="summary-row">
            <span><strong>EPF</strong></span>
            <span>Employer</span>
            <span class="rate-display" id="summary_epf_employer">
                <?php echo $settings ? htmlspecialchars($settings['epf_employer']) : '12.00'; ?>%
            </span>
        </div>
        <div class="summary-row">
            <span><strong>EPF</strong></span>
            <span>Employee</span>
            <span class="rate-display" id="summary_epf_employee">
                <?php echo $settings ? htmlspecialchars($settings['epf_employee']) : '8.00'; ?>%
            </span>
        </div>
        <div class="summary-row">
            <span><strong>ETF</strong></span>
            <span>Employer</span>
            <span class="rate-display" id="summary_etf_employer">
                <?php echo $settings ? htmlspecialchars($settings['etf_employer']) : '3.00'; ?>%
            </span>
        </div>
        <div class="summary-row summary-total">
            <span><strong>Total Employer Cost</strong></span>
            <span></span>
            <span class="rate-display" id="summary_total">
                <?php
                    $epf_emp = $settings ? floatval($settings['epf_employer']) : 12.00;
                    $etf_emp = $settings ? floatval($settings['etf_employer']) : 3.00;
                    echo number_format($epf_emp + $etf_emp, 2);
                ?>%
            </span>
        </div>
    </div>
</div>

<!-- Save Button -->
<div class="form-actions save-actions">
    <button type="submit" form="epfEtfForm" class="btn btn-primary">
        <i class="fa-solid fa-floppy-disk"></i>
        Save Settings
    </button>
    <button type="reset" form="epfEtfForm" class="btn btn-secondary">
        <i class="fa-solid fa-rotate-left"></i>
        Reset
    </button>
</div>

</form>

<?php if ($settings && !empty($settings['updated_at'])): ?>
<p class="last-updated">
    <i class="fa-solid fa-clock"></i>
    Last updated: <?php echo date('M d, Y h:i A', strtotime($settings['updated_at'])); ?>
</p>
<?php endif; ?>

<!-- SQL Reference -->
<!--
CREATE TABLE epf_etf_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    epf_employer DECIMAL(5,2) NOT NULL DEFAULT 12.00,
    epf_employee DECIMAL(5,2) NOT NULL DEFAULT 8.00,
    etf_employer DECIMAL(5,2) NOT NULL DEFAULT 3.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
-->

<script>
// Live summary update
function updateSummary() {
    const epfEmp = parseFloat(document.getElementById('epf_employer').value) || 0;
    const epfEmpl = parseFloat(document.getElementById('epf_employee').value) || 0;
    const etfEmp = parseFloat(document.getElementById('etf_employer').value) || 0;

    document.getElementById('summary_epf_employer').textContent = epfEmp.toFixed(2) + '%';
    document.getElementById('summary_epf_employee').textContent = epfEmpl.toFixed(2) + '%';
    document.getElementById('summary_etf_employer').textContent = etfEmp.toFixed(2) + '%';
    document.getElementById('summary_total').textContent = (epfEmp + etfEmp).toFixed(2) + '%';
}

document.getElementById('epf_employer').addEventListener('input', updateSummary);
document.getElementById('epf_employee').addEventListener('input', updateSummary);
document.getElementById('etf_employer').addEventListener('input', updateSummary);
</script>

<style>
/* Alert */
.alert {
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13px;
    font-weight: 500;
}
.alert i { font-size: 18px; }
.alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

/* Info Banner */
.info-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 18px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    font-size: 13px;
    color: #1e40af;
    margin-bottom: 28px;
}
.info-banner i { font-size: 16px; flex-shrink: 0; }

/* Grid Layout */
.settings-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
}

@media (max-width: 900px) {
    .settings-grid { grid-template-columns: 1fr; }
}

/* Section Cards */
.section-card { padding: 28px; }

.section-header {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 28px;
    padding-bottom: 20px;
    border-bottom: 1px solid #f0f0f0;
}

.section-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: #000000;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 20px;
    flex-shrink: 0;
}

.etf-icon {
    background: #1d4ed8;
}

.section-title {
    font-size: 15px;
    font-weight: 700;
    color: #111111;
    margin: 0 0 4px 0;
}

.section-subtitle {
    font-size: 12px;
    color: #666666;
    margin: 0;
}

/* Rate Cards */
.rates-row {
    display: flex;
    align-items: center;
    gap: 16px;
}

.rates-single {
    justify-content: flex-start;
}

.rate-card {
    flex: 1;
    background: #fafafa;
    border: 1px solid #e5e5e5;
    border-radius: 10px;
    padding: 16px 18px;
}

.rate-label {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 12px;
    font-weight: 600;
    color: #555555;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 12px;
}

.rate-label i { font-size: 12px; }

.rate-input-wrap {
    display: flex;
    align-items: center;
    background: #ffffff;
    border: 1.5px solid #e5e5e5;
    border-radius: 8px;
    overflow: hidden;
    transition: border-color 0.2s;
}

.rate-input-wrap:focus-within {
    border-color: #000000;
    box-shadow: 0 0 0 3px rgba(0,0,0,0.05);
}

.rate-input {
    flex: 1;
    border: none;
    outline: none;
    padding: 12px 14px;
    font-size: 20px;
    font-weight: 700;
    color: #111111;
    font-family: 'Inter', sans-serif;
    background: transparent;
    width: 100%;
}

.rate-input::-webkit-inner-spin-button,
.rate-input::-webkit-outer-spin-button { opacity: 1; }

.rate-suffix {
    padding: 0 14px 0 4px;
    font-size: 20px;
    font-weight: 700;
    color: #999999;
}

.rate-hint {
    display: block;
    font-size: 11px;
    color: #999999;
    margin-top: 8px;
}

.rate-divider {
    color: #cccccc;
    font-size: 18px;
    flex-shrink: 0;
    margin-top: -16px;
}

/* Summary Card */
.summary-card { margin-bottom: 24px; }

.summary-table { margin-top: 16px; }

.summary-row {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr;
    padding: 12px 16px;
    border-radius: 6px;
    font-size: 13px;
    color: #333333;
}

.summary-head {
    background: #fafafa;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #888888;
    margin-bottom: 4px;
}

.summary-row:not(.summary-head):not(.summary-total):hover {
    background: #fafafa;
}

.summary-total {
    background: #f5f5f5;
    border-top: 2px solid #e5e5e5;
    margin-top: 8px;
    font-weight: 700;
    font-size: 14px;
    color: #111111;
}

.rate-display {
    font-weight: 700;
    color: #111111;
}

/* Save Actions */
.save-actions { display: flex; gap: 12px; margin-bottom: 16px; }

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 28px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
    font-family: 'Inter', sans-serif;
}
.btn i { font-size: 16px; }
.btn-primary { background: #000000; color: #ffffff; }
.btn-primary:hover { background: #333333; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.btn-secondary { background: #f5f5f5; color: #333333; border: 1px solid #e5e5e5; }
.btn-secondary:hover { background: #e5e5e5; }

/* Last Updated */
.last-updated {
    font-size: 12px;
    color: #999999;
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 4px;
}

@media (max-width: 600px) {
    .rates-row { flex-direction: column; }
    .rate-divider { transform: rotate(90deg); margin: 0; }
    .save-actions { flex-direction: column; }
    .btn { width: 100%; justify-content: center; }
    .summary-row { grid-template-columns: 1.5fr 1fr 1fr; font-size: 12px; }
}
</style>

<?php include 'footer.php'; ?>