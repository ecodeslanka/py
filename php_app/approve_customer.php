<?php
include 'config.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: customers.php');
    exit;
}

// Fetch customer
$res = mysqli_query($conn, "SELECT * FROM customers WHERE id = $id LIMIT 1");
$customer = mysqli_fetch_assoc($res);

if (!$customer) {
    header('Location: customers.php');
    exit;
}

$action  = $_POST['action']  ?? '';
$confirm = $_POST['confirm'] ?? '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $confirm === 'yes') {
    if ($action === 'approve') {
        mysqli_query($conn, "UPDATE customers SET active = 1, updated_at = NOW() WHERE id = $id");
        header('Location: customers.php?success=approved');
        exit;
    } elseif ($action === 'deactivate') {
        mysqli_query($conn, "UPDATE customers SET active = 0, updated_at = NOW() WHERE id = $id");
        header('Location: customers.php?success=deactivated');
        exit;
    }
}

include 'header.php';
?>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                <a href="customers.php" style="color:#6b7280;text-decoration:none;font-size:13px;display:inline-flex;align-items:center;gap:4px;">
                    <i class="fa-solid fa-arrow-left"></i> Back to Customers
                </a>
            </div>
            <h2 class="page-title">
                <?= $customer['active'] ? 'Deactivate Customer' : 'Approve Customer' ?>
            </h2>
            <p class="page-subtitle">
                <?= $customer['active'] ? 'Mark this customer as non-approved' : 'Approve this customer to activate their account' ?>
            </p>
        </div>
    </div>
</div>

<!-- Customer Info Card -->
<div class="content-card">
    <div style="display:flex;align-items:flex-start;gap:20px;flex-wrap:wrap;">
        <!-- Avatar -->
        <div class="customer-avatar">
            <?= strtoupper(substr($customer['shop_name'], 0, 2)) ?>
        </div>

        <!-- Info -->
        <div style="flex:1;min-width:200px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap;">
                <h3 style="margin:0;font-size:20px;font-weight:700;color:#111827;">
                    <?= htmlspecialchars($customer['shop_name']) ?>
                </h3>
                <span class="badge <?= $customer['active'] ? 'badge-approved' : 'badge-non-approved' ?>">
                    <i class="fa-solid <?= $customer['active'] ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
                    <?= $customer['active'] ? 'Approved' : 'Non-Approved' ?>
                </span>
            </div>
            <div style="font-size:13px;color:#6b7280;font-weight:600;margin-bottom:14px;">
                <?= htmlspecialchars($customer['t_code']) ?>
            </div>

            <!-- Details Grid -->
            <div class="info-grid">
                <?php if ($customer['route']): ?>
                <div class="info-item">
                    <span class="info-label"><i class="fa-solid fa-route"></i> Route</span>
                    <span class="info-value"><?= htmlspecialchars($customer['route']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($customer['telephone_number']): ?>
                <div class="info-item">
                    <span class="info-label"><i class="fa-solid fa-phone"></i> Telephone</span>
                    <span class="info-value"><?= htmlspecialchars($customer['telephone_number']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($customer['primary_channel']): ?>
                <div class="info-item">
                    <span class="info-label"><i class="fa-solid fa-tag"></i> Primary Channel</span>
                    <span class="info-value"><?= htmlspecialchars($customer['primary_channel']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($customer['channel']): ?>
                <div class="info-item">
                    <span class="info-label"><i class="fa-solid fa-layer-group"></i> Channel</span>
                    <span class="info-value"><?= htmlspecialchars($customer['channel']) ?></span>
                </div>
                <?php endif; ?>
                <div class="info-item">
                    <span class="info-label"><i class="fa-solid fa-credit-card"></i> Payment Mode</span>
                    <span class="info-value"><?= ucfirst($customer['payment_mode']) ?></span>
                </div>
                <?php if ($customer['payment_mode'] === 'credit'): ?>
                <div class="info-item">
                    <span class="info-label"><i class="fa-solid fa-coins"></i> Credit Limit</span>
                    <span class="info-value">Rs. <?= number_format($customer['credit_limit'], 2) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($customer['address']): ?>
                <div class="info-item" style="grid-column:1/-1;">
                    <span class="info-label"><i class="fa-solid fa-location-dot"></i> Address</span>
                    <span class="info-value"><?= nl2br(htmlspecialchars($customer['address'])) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Action Card -->
<div class="content-card action-card <?= $customer['active'] ? 'action-deactivate' : 'action-approve' ?>">
    <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;">
        <div class="action-icon-wrap">
            <i class="fa-solid <?= $customer['active'] ? 'fa-ban' : 'fa-circle-check' ?>"></i>
        </div>
        <div style="flex:1;">
            <h4 style="margin:0 0 6px 0;font-size:16px;font-weight:700;color:#111827;">
                <?= $customer['active'] ? 'Deactivate this customer?' : 'Approve this customer?' ?>
            </h4>
            <p style="margin:0 0 20px 0;font-size:13px;color:#6b7280;line-height:1.6;">
                <?php if ($customer['active']): ?>
                    This will mark <strong><?= htmlspecialchars($customer['shop_name']) ?></strong> as <strong>Non-Approved</strong>.
                    They will no longer appear in active customer lists until re-approved.
                <?php else: ?>
                    This will mark <strong><?= htmlspecialchars($customer['shop_name']) ?></strong> as <strong>Approved</strong>.
                    They will be fully active and appear in all customer lists.
                <?php endif; ?>
            </p>

            <form method="POST" id="actionForm">
                <input type="hidden" name="action"  value="<?= $customer['active'] ? 'deactivate' : 'approve' ?>">
                <input type="hidden" name="confirm" value="yes">
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button type="submit" class="btn <?= $customer['active'] ? 'btn-danger' : 'btn-approve' ?>" id="confirmBtn">
                        <i class="fa-solid <?= $customer['active'] ? 'fa-ban' : 'fa-circle-check' ?>"></i>
                        <?= $customer['active'] ? 'Yes, Deactivate' : 'Yes, Approve Customer' ?>
                    </button>
                    <a href="customers.php" class="btn btn-light">
                        <i class="fa-solid fa-xmark"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.page-header  { margin-bottom: 24px; }
.page-title   { font-size: 26px; font-weight: 700; color: #111827; margin: 0 0 4px 0; }
.page-subtitle{ font-size: 13px; color: #6b7280; margin: 0; }

.content-card {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    padding: 24px;
    margin-bottom: 16px;
}

/* Avatar */
.customer-avatar {
    width: 64px; height: 64px; border-radius: 14px;
    background: #111827; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; font-weight: 700; flex-shrink: 0;
    letter-spacing: 1px;
}

/* Info grid */
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
}
.info-item {
    display: flex; flex-direction: column; gap: 3px;
}
.info-label {
    font-size: 10px; font-weight: 600; color: #9ca3af;
    text-transform: uppercase; letter-spacing: 0.5px;
    display: flex; align-items: center; gap: 5px;
}
.info-value {
    font-size: 13px; font-weight: 500; color: #111827;
}

/* Badges */
.badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 600; white-space: nowrap;
}
.badge-approved     { background: #dcfce7; color: #166534; }
.badge-non-approved { background: #fee2e2; color: #991b1b; }

/* Action card variants */
.action-card {
    border: 2px solid transparent;
}
.action-approve {
    border-color: #bbf7d0;
    background: #f0fdf4;
}
.action-deactivate {
    border-color: #fecaca;
    background: #fff5f5;
}

.action-icon-wrap {
    width: 44px; height: 44px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.action-approve  .action-icon-wrap { background: #dcfce7; color: #16a34a; }
.action-deactivate .action-icon-wrap { background: #fee2e2; color: #dc2626; }

/* Buttons */
.btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 10px 20px; border: none; border-radius: 8px;
    font-size: 13px; font-weight: 600; cursor: pointer;
    transition: all 0.2s; text-decoration: none;
    font-family: 'Inter', sans-serif;
}
.btn-approve {
    background: #16a34a; color: #fff;
}
.btn-approve:hover { background: #15803d; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(22,163,74,0.3); }

.btn-danger {
    background: #ef4444; color: #fff;
}
.btn-danger:hover  { background: #dc2626; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(239,68,68,0.3); }

.btn-light {
    background: #f9fafb; color: #374151;
    border: 1px solid #d1d5db;
}
.btn-light:hover { background: #f3f4f6; }
</style>

<script>
// Prevent double-submit
document.getElementById('actionForm').addEventListener('submit', function() {
    const btn = document.getElementById('confirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
});
</script>

<?php include 'footer.php'; ?>
