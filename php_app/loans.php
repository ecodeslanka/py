<?php
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$session_user = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';

// ── CREATE TABLE ───────────────────────────────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS loan_requests (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        employee_id         INT NOT NULL,
        request_date        DATE NOT NULL,
        payroll_period_id   INT,
        loan_type           VARCHAR(50) NOT NULL DEFAULT 'Office Loan',
        loan_amount         DECIMAL(12,2) NOT NULL,
        no_of_instalments   INT NOT NULL,
        monthly_instalment  DECIMAL(12,2) NOT NULL,
        interest_rate       DECIMAL(8,4) NOT NULL DEFAULT 0,
        interest_frequency  VARCHAR(20) NOT NULL DEFAULT 'Monthly',
        total_interest      DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_repayment     DECIMAL(12,2) NOT NULL DEFAULT 0,
        reason              TEXT,
        status              ENUM('Pending','Approved','Rejected','Settled') NOT NULL DEFAULT 'Pending',
        approved_by         VARCHAR(100),
        approved_at         DATETIME,
        rejection_note      TEXT,
        remarks             TEXT,
        paid_amount         DECIMAL(12,2) NOT NULL DEFAULT 0,
        settled_by          VARCHAR(100),
        settled_at          DATETIME,
        created_by          VARCHAR(100),
        created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── CREATE TABLE: settlement / payment history ──────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS loan_settlements (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        loan_id             INT NOT NULL,
        settlement_date     DATE NOT NULL,
        amount              DECIMAL(12,2) NOT NULL,
        payment_method      VARCHAR(50) NOT NULL DEFAULT 'Cash',
        note                TEXT,
        created_by          VARCHAR(100),
        created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_loan_id (loan_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── ALTER TABLE: add missing columns if upgrading from old schema ───────────
$existing_cols = [];
$col_res = mysqli_query($conn, "SHOW COLUMNS FROM loan_requests");
if ($col_res) { while ($c = mysqli_fetch_assoc($col_res)) $existing_cols[] = $c['Field']; }
$alter_cols = [
    'loan_type'          => "ADD COLUMN loan_type VARCHAR(50) NOT NULL DEFAULT 'Office Loan' AFTER payroll_period_id",
    'interest_rate'      => "ADD COLUMN interest_rate DECIMAL(8,4) NOT NULL DEFAULT 0 AFTER monthly_instalment",
    'interest_frequency' => "ADD COLUMN interest_frequency VARCHAR(20) NOT NULL DEFAULT 'Monthly' AFTER interest_rate",
    'total_interest'     => "ADD COLUMN total_interest DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER interest_frequency",
    'total_repayment'    => "ADD COLUMN total_repayment DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_interest",
    'paid_amount'        => "ADD COLUMN paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER remarks",
    'settled_by'         => "ADD COLUMN settled_by VARCHAR(100) AFTER paid_amount",
    'settled_at'         => "ADD COLUMN settled_at DATETIME AFTER settled_by",
];
foreach ($alter_cols as $col => $sql) {
    if (!in_array($col, $existing_cols)) mysqli_query($conn, "ALTER TABLE loan_requests $sql");
}
// Make sure the status ENUM includes 'Settled' even on older installs (safe/idempotent)
mysqli_query($conn, "ALTER TABLE loan_requests MODIFY COLUMN status ENUM('Pending','Approved','Rejected','Settled') NOT NULL DEFAULT 'Pending'");

$msg = ''; $msg_type = '';

// ── HANDLE ACTIONS ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'approve') {
        $lid     = intval($_POST['loan_id']);
        $by      = mysqli_real_escape_string($conn, $session_user);
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
        mysqli_query($conn, "UPDATE loan_requests SET status='Approved', approved_by='$by', approved_at=NOW(), remarks='$remarks' WHERE id=$lid AND status='Pending'");
        $msg = "Loan request approved successfully."; $msg_type = 'success';
    }

    if ($_POST['action'] === 'reject') {
        $lid     = intval($_POST['loan_id']);
        $note    = mysqli_real_escape_string($conn, $_POST['rejection_note'] ?? '');
        $by      = mysqli_real_escape_string($conn, $session_user);
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
        mysqli_query($conn, "UPDATE loan_requests SET status='Rejected', approved_by='$by', approved_at=NOW(), rejection_note='$note', remarks='$remarks' WHERE id=$lid AND status='Pending'");
        $msg = "Loan request rejected."; $msg_type = 'danger';
    }

    if ($_POST['action'] === 'delete') {
        $lid = intval($_POST['loan_id']);
        mysqli_query($conn, "DELETE FROM loan_requests WHERE id=$lid AND status='Pending'");
        $msg = "Request deleted."; $msg_type = 'warning';
    }

    if ($_POST['action'] === 'settle') {
        $lid           = intval($_POST['loan_id']);
        $settle_amount = floatval($_POST['settlement_amount'] ?? 0);
        $settle_date   = mysqli_real_escape_string($conn, $_POST['settlement_date'] ?? date('Y-m-d'));
        $pay_method    = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'Cash');
        $settle_note   = mysqli_real_escape_string($conn, $_POST['settlement_note'] ?? '');
        $by            = mysqli_real_escape_string($conn, $session_user);

        $lres = mysqli_query($conn, "SELECT * FROM loan_requests WHERE id=$lid AND status='Approved' LIMIT 1");
        $lrow = $lres ? mysqli_fetch_assoc($lres) : null;

        if (!$lrow) {
            $msg = "Only Approved loans can be settled, or the request was not found.";
            $msg_type = 'danger';
        } elseif ($settle_amount <= 0) {
            $msg = "Settlement amount must be greater than 0.";
            $msg_type = 'danger';
        } else {
            $total_due   = ($lrow['total_repayment'] > 0) ? floatval($lrow['total_repayment']) : floatval($lrow['loan_amount']);
            $already_paid= floatval($lrow['paid_amount'] ?? 0);
            $outstanding = round($total_due - $already_paid, 2);

            if ($settle_amount > $outstanding + 0.01) {
                $msg = "Settlement amount (LKR ".number_format($settle_amount,2).") exceeds the outstanding balance (LKR ".number_format($outstanding,2).").";
                $msg_type = 'danger';
            } else {
                mysqli_query($conn, "INSERT INTO loan_settlements (loan_id, settlement_date, amount, payment_method, note, created_by)
                    VALUES ($lid, '$settle_date', $settle_amount, '$pay_method', '$settle_note', '$by')");

                $new_paid        = round($already_paid + $settle_amount, 2);
                $new_outstanding = round($total_due - $new_paid, 2);

                if ($new_outstanding <= 0.01) {
                    mysqli_query($conn, "UPDATE loan_requests SET paid_amount=$new_paid, status='Settled', settled_by='$by', settled_at=NOW() WHERE id=$lid");
                    $msg = "Loan fully settled. Total paid: LKR ".number_format($new_paid,2).".";
                    $msg_type = 'success';
                } else {
                    mysqli_query($conn, "UPDATE loan_requests SET paid_amount=$new_paid WHERE id=$lid");
                    $msg = "Payment of LKR ".number_format($settle_amount,2)." recorded. Outstanding balance: LKR ".number_format($new_outstanding,2).".";
                    $msg_type = 'success';
                }
            }
        }
    }

    if ($_POST['action'] === 'delete_settlement') {
        $sid  = intval($_POST['settlement_id']);
        $srow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM loan_settlements WHERE id=$sid LIMIT 1"));

        if (!$srow) {
            $msg = "Payment record not found.";
            $msg_type = 'danger';
        } else {
            $lid = intval($srow['loan_id']);
            $deleted_amount = floatval($srow['amount']);
            mysqli_query($conn, "DELETE FROM loan_settlements WHERE id=$sid");

            // Recalculate paid_amount from the remaining settlement records to avoid drift
            $sum_row  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM loan_settlements WHERE loan_id=$lid"));
            $new_paid = round(floatval($sum_row['total']), 2);

            $lrow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM loan_requests WHERE id=$lid LIMIT 1"));
            if ($lrow) {
                $total_due       = ($lrow['total_repayment'] > 0) ? floatval($lrow['total_repayment']) : floatval($lrow['loan_amount']);
                $new_outstanding = round($total_due - $new_paid, 2);

                if ($lrow['status'] === 'Settled' && $new_outstanding > 0.01) {
                    // Loan is no longer fully paid — revert it to Approved
                    mysqli_query($conn, "UPDATE loan_requests SET paid_amount=$new_paid, status='Approved', settled_by=NULL, settled_at=NULL WHERE id=$lid");
                    $msg = "Payment of LKR ".number_format($deleted_amount,2)." deleted. Loan reverted to Approved — outstanding balance: LKR ".number_format($new_outstanding,2).".";
                    $msg_type = 'warning';
                } else {
                    mysqli_query($conn, "UPDATE loan_requests SET paid_amount=$new_paid WHERE id=$lid");
                    $msg = "Payment of LKR ".number_format($deleted_amount,2)." deleted.";
                    $msg_type = 'warning';
                }
            }
        }
    }
}

// ── FILTERS ────────────────────────────────────────────────────────────────
$filter_status    = isset($_GET['status'])    ? $_GET['status']    : '';
$filter_loan_type = isset($_GET['loan_type']) ? $_GET['loan_type'] : '';
$search           = isset($_GET['search'])    ? mysqli_real_escape_string($conn, $_GET['search']) : '';

$where = "WHERE 1=1";
if ($filter_status)    $where .= " AND lr.status='".mysqli_real_escape_string($conn,$filter_status)."'";
if ($filter_loan_type) $where .= " AND lr.loan_type='".mysqli_real_escape_string($conn,$filter_loan_type)."'";
if ($search)           $where .= " AND (e.employee_full_name LIKE '%$search%' OR e.employee_id LIKE '%$search%')";

$loans = [];
$res = mysqli_query($conn, "
    SELECT lr.*,
           e.employee_full_name, e.employee_id as emp_code, e.basic_salary,
           e.date_of_join, e.status as emp_status,
           pp.year as pay_year, pp.month as pay_month, pp.status as period_status
    FROM loan_requests lr
    JOIN employees e ON lr.employee_id = e.id
    LEFT JOIN payroll_periods pp ON lr.payroll_period_id = pp.id
    $where
    ORDER BY lr.created_at DESC
");
while ($r = mysqli_fetch_assoc($res)) $loans[] = $r;

// Settlement history per loan (for the History modal)
$settlements_by_loan = [];
$sres = mysqli_query($conn, "SELECT * FROM loan_settlements ORDER BY settlement_date DESC, id DESC");
if ($sres) {
    while ($s = mysqli_fetch_assoc($sres)) {
        $settlements_by_loan[$s['loan_id']][] = [
            'id'     => (int)$s['id'],
            'date'   => $s['settlement_date'],
            'amount' => floatval($s['amount']),
            'method' => $s['payment_method'],
            'note'   => $s['note'],
            'by'     => $s['created_by'],
        ];
    }
}

// Stats
$stats = ['Pending'=>0,'Approved'=>0,'Rejected'=>0,'Settled'=>0,'total_amount'=>0,'total_repayment'=>0,'total_outstanding'=>0,'office'=>0,'welfare'=>0];
foreach ($loans as $l) {
    $stats[$l['status']]++;
    if ($l['status']==='Approved' || $l['status']==='Settled') {
        $stats['total_amount']    += $l['loan_amount'];
        $stats['total_repayment'] += $l['total_repayment'] ?? $l['loan_amount'];
    }
    if ($l['status']==='Approved') {
        $due = ($l['total_repayment'] ?? 0) > 0 ? $l['total_repayment'] : $l['loan_amount'];
        $stats['total_outstanding'] += max(0, $due - floatval($l['paid_amount'] ?? 0));
    }
    if (($l['loan_type'] ?? 'Office Loan') === 'Office Loan')  $stats['office']++;
    else $stats['welfare']++;
}

$month_names = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

// Helper: years of service
function yearsOfService($doj) {
    if (!$doj || $doj === '0000-00-00') return null;
    $diff = (new DateTime())->diff(new DateTime($doj));
    return $diff->y + round($diff->m / 12, 1);
}

include 'header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;margin:0 0 4px;color:#111;display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-file-invoice-dollar" style="color:#3b82f6;"></i> Loan Requests
        </h2>
        <p style="font-size:13px;color:#666;margin:0;">Manage employee loan requests and approvals</p>
    </div>
    <a href="add_loan.php" class="lr-btn lr-btn-p">
        <i class="fa-solid fa-plus"></i> New Request
    </a>
</div>

<?php if (isset($_GET['added'])): ?>
<div class="lr-alert lr-alert-success" style="margin-bottom:14px;"><i class="fa-solid fa-circle-check"></i> Loan request added successfully.</div>
<?php elseif (isset($_GET['updated'])): ?>
<div class="lr-alert lr-alert-success" style="margin-bottom:14px;"><i class="fa-solid fa-circle-check"></i> Loan request updated successfully.</div>
<?php elseif ($msg): ?>
<div class="lr-alert lr-alert-<?php echo $msg_type; ?>" style="margin-bottom:14px;">
    <i class="fa-solid fa-<?php echo $msg_type==='success'?'circle-check':($msg_type==='danger'?'circle-xmark':'triangle-exclamation'); ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<!-- STATS -->
<div class="lr-stats-row">
    <div class="lr-stat-card lr-stat-blue">
        <div class="lr-stat-icon"><i class="fa-solid fa-clock"></i></div>
        <div><div class="lr-stat-val"><?php echo $stats['Pending']; ?></div><div class="lr-stat-lbl">Pending</div></div>
    </div>
    <div class="lr-stat-card lr-stat-green">
        <div class="lr-stat-icon"><i class="fa-solid fa-circle-check"></i></div>
        <div><div class="lr-stat-val"><?php echo $stats['Approved']; ?></div><div class="lr-stat-lbl">Approved</div></div>
    </div>
    <div class="lr-stat-card lr-stat-red">
        <div class="lr-stat-icon"><i class="fa-solid fa-circle-xmark"></i></div>
        <div><div class="lr-stat-val"><?php echo $stats['Rejected']; ?></div><div class="lr-stat-lbl">Rejected</div></div>
    </div>
    <div class="lr-stat-card lr-stat-purple">
        <div class="lr-stat-icon"><i class="fa-solid fa-coins"></i></div>
        <div><div class="lr-stat-val">LKR <?php echo number_format($stats['total_amount'],2); ?></div><div class="lr-stat-lbl">Total Approved</div></div>
    </div>
    <div class="lr-stat-card lr-stat-indigo">
        <div class="lr-stat-icon"><i class="fa-solid fa-building"></i></div>
        <div><div class="lr-stat-val"><?php echo $stats['office']; ?></div><div class="lr-stat-lbl">Office Loans</div></div>
    </div>
    <div class="lr-stat-card lr-stat-teal">
        <div class="lr-stat-icon"><i class="fa-solid fa-handshake-angle"></i></div>
        <div><div class="lr-stat-val"><?php echo $stats['welfare']; ?></div><div class="lr-stat-lbl">Welfare Loans</div></div>
    </div>
    <div class="lr-stat-card lr-stat-amber">
        <div class="lr-stat-icon"><i class="fa-solid fa-hand-holding-dollar"></i></div>
        <div><div class="lr-stat-val"><?php echo $stats['Settled']; ?></div><div class="lr-stat-lbl">Settled</div></div>
    </div>
    <div class="lr-stat-card lr-stat-cyan">
        <div class="lr-stat-icon"><i class="fa-solid fa-scale-unbalanced"></i></div>
        <div><div class="lr-stat-val">LKR <?php echo number_format($stats['total_outstanding'],2); ?></div><div class="lr-stat-lbl">Outstanding</div></div>
    </div>
</div>

<!-- FILTERS -->
<div class="lr-filter-bar">
    <form method="GET" style="display:contents;">
        <input type="text" name="search" placeholder="Search employee..." class="lr-search" value="<?php echo htmlspecialchars($_GET['search']??''); ?>">
        <select name="status" class="lr-select" onchange="this.form.submit()">
            <option value="">All Status</option>
            <?php foreach(['Pending','Approved','Rejected','Settled'] as $s): ?>
            <option value="<?php echo $s; ?>" <?php echo $filter_status===$s?'selected':''; ?>><?php echo $s; ?></option>
            <?php endforeach; ?>
        </select>
        <select name="loan_type" class="lr-select" onchange="this.form.submit()">
            <option value="">All Types</option>
            <option value="Office Loan"  <?php echo $filter_loan_type==='Office Loan' ?'selected':''; ?>>🏢 Office Loan</option>
            <option value="Welfare Loan" <?php echo $filter_loan_type==='Welfare Loan'?'selected':''; ?>>🤝 Welfare Loan</option>
        </select>
        <button type="submit" class="lr-btn lr-btn-g"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
        <?php if ($filter_status||$search||$filter_loan_type): ?>
        <a href="loans.php" class="lr-btn lr-btn-g"><i class="fa-solid fa-xmark"></i> Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- TABLE -->
<div class="lr-card">
    <div class="lr-table-wrap">
        <table class="lr-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Employee</th>
                    <th>Service / Status</th>
                    <th>Request Date</th>
                    <th>Payroll Period</th>
                    <th>Loan Type</th>
                    <th>Loan Amount</th>
                    <th>Interest</th>
                    <th>Instalments</th>
                    <th>Monthly</th>
                    <th>Total Repayment</th>
                    <th>Outstanding</th>
                    <th>Status</th>
                    <th>Approved By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($loans)): ?>
                <tr><td colspan="15" style="text-align:center;padding:40px;color:#9ca3af;">
                    <i class="fa-solid fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>
                    No loan requests found.
                </td></tr>
                <?php else: foreach ($loans as $i => $l):
                    $yrs = yearsOfService($l['date_of_join']);
                    $emp_status_colors = [
                        'Permanent' => ['bg'=>'#dcfce7','color'=>'#166534'],
                        'Probation' => ['bg'=>'#fef3c7','color'=>'#92400e'],
                        'Resigned'  => ['bg'=>'#fee2e2','color'=>'#991b1b'],
                    ];
                    $sc = $emp_status_colors[$l['emp_status']] ?? ['bg'=>'#f3f4f6','color'=>'#6b7280'];
                ?>
                <tr>
                    <td style="color:#9ca3af;font-size:12px;"><?php echo $i+1; ?></td>
                    <td>
                        <div style="font-weight:600;color:#111;"><?php echo htmlspecialchars($l['employee_full_name']); ?></div>
                        <div style="font-size:11px;color:#9ca3af;"><?php echo htmlspecialchars($l['emp_code']); ?></div>
                    </td>
                    <td>
                        <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;background:<?php echo $sc['bg']; ?>;color:<?php echo $sc['color']; ?>;margin-bottom:4px;">
                            <?php echo htmlspecialchars($l['emp_status']); ?>
                        </span>
                        <?php if ($yrs !== null): ?>
                        <div style="font-size:11px;color:#6b7280;">
                            <i class="fa-solid fa-briefcase" style="font-size:10px;"></i>
                            <?php echo number_format($yrs, 1); ?> yr<?php echo $yrs!=1?'s':''; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('d M Y', strtotime($l['request_date'])); ?></td>
                    <td>
                        <?php if ($l['pay_year']): ?>
                        <span class="lr-period-badge <?php echo $l['period_status']==='Locked'?'lr-period-locked':'lr-period-open'; ?>">
                            <?php echo $month_names[$l['pay_month']].' '.$l['pay_year']; ?>
                        </span>
                        <?php else: ?><span style="color:#ccc;font-size:12px;">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php $lt = $l['loan_type'] ?? 'Office Loan'; ?>
                        <span class="lr-badge <?php echo $lt==='Welfare Loan' ? 'lr-badge-welfare' : 'lr-badge-office'; ?>">
                            <?php echo $lt==='Welfare Loan' ? '🤝' : '🏢'; ?>
                            <?php echo htmlspecialchars($lt); ?>
                        </span>
                    </td>
                    <td style="font-weight:700;color:#111;">LKR <?php echo number_format($l['loan_amount'],2); ?></td>
                    <td>
                        <?php if (($l['interest_rate'] ?? 0) > 0): ?>
                        <div style="font-size:12px;font-weight:700;color:#92400e;">
                            <?php echo rtrim(rtrim(number_format($l['interest_rate'],4,'.',','),'0'),'.'); ?>%
                        </div>
                        <div style="font-size:11px;color:#9ca3af;"><?php echo htmlspecialchars($l['interest_frequency'] ?? 'Monthly'); ?></div>
                        <?php if (($l['total_interest'] ?? 0) > 0): ?>
                        <div style="font-size:11px;color:#b45309;margin-top:1px;">+LKR <?php echo number_format($l['total_interest'],2); ?></div>
                        <?php endif; ?>
                        <?php else: ?>
                        <span style="font-size:11px;font-weight:700;color:#166534;background:#f0fdf4;border:1px solid #bbf7d0;padding:2px 7px;border-radius:10px;">0% Free</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;font-weight:600;"><?php echo $l['no_of_instalments']; ?></td>
                    <td style="font-weight:600;color:#2563eb;">LKR <?php echo number_format($l['monthly_instalment'],2); ?></td>
                    <td style="font-weight:700;color:#111;">
                        LKR <?php echo number_format(($l['total_repayment'] ?? 0) > 0 ? $l['total_repayment'] : $l['loan_amount'], 2); ?>
                        <?php if (($l['total_interest'] ?? 0) > 0): ?>
                        <div style="font-size:10px;color:#9ca3af;font-weight:400;">incl. interest</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $due_amt  = ($l['total_repayment'] ?? 0) > 0 ? floatval($l['total_repayment']) : floatval($l['loan_amount']);
                        $paid_amt = floatval($l['paid_amount'] ?? 0);
                        $outstanding_amt = round($due_amt - $paid_amt, 2);
                        ?>
                        <?php if ($l['status'] === 'Settled'): ?>
                        <span style="font-size:11px;font-weight:700;color:#166534;background:#f0fdf4;border:1px solid #bbf7d0;padding:2px 7px;border-radius:10px;">
                            <i class="fa-solid fa-circle-check"></i> Fully Settled
                        </span>
                        <?php elseif ($l['status'] === 'Approved'): ?>
                        <div style="font-weight:700;color:<?php echo $outstanding_amt>0 ? '#b45309' : '#166534'; ?>;">LKR <?php echo number_format($outstanding_amt,2); ?></div>
                        <?php if ($paid_amt > 0): ?>
                        <div style="font-size:10px;color:#9ca3af;">paid LKR <?php echo number_format($paid_amt,2); ?></div>
                        <?php endif; ?>
                        <?php else: ?>
                        <span style="color:#ccc;font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $sbadge = ['Pending'=>'lr-badge-pending','Approved'=>'lr-badge-approved','Rejected'=>'lr-badge-rejected','Settled'=>'lr-badge-settled'];
                        $sicon  = ['Pending'=>'clock','Approved'=>'circle-check','Rejected'=>'circle-xmark','Settled'=>'hand-holding-dollar'];
                        ?>
                        <span class="lr-badge <?php echo $sbadge[$l['status']]; ?>">
                            <i class="fa-solid fa-<?php echo $sicon[$l['status']]; ?>"></i>
                            <?php echo $l['status']; ?>
                        </span>
                        <?php if ($l['status'] === 'Settled' && $l['settled_at']): ?>
                        <div style="font-size:10px;color:#9ca3af;margin-top:2px;">on <?php echo date('d M Y', strtotime($l['settled_at'])); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($l['approved_by']): ?>
                        <div style="font-size:12px;font-weight:600;"><?php echo htmlspecialchars($l['approved_by']); ?></div>
                        <div style="font-size:11px;color:#9ca3af;"><?php echo $l['approved_at'] ? date('d M Y', strtotime($l['approved_at'])) : ''; ?></div>
                        <?php else: echo '<span style="color:#ccc;font-size:12px;">—</span>'; endif; ?>
                    </td>
                    <td>
                        <div class="lr-actions">
                            <a href="edit_loan.php?id=<?php echo $l['id']; ?>" class="lr-icon-btn lr-icon-edit" title="Edit/View"><i class="fa-solid fa-pen"></i></a>
                            <?php if ($l['status']==='Pending'): ?>
                            <button onclick="openApproveModal(<?php echo $l['id']; ?>,<?php echo htmlspecialchars(json_encode($l['employee_full_name'])); ?>,<?php echo $l['loan_amount']; ?>,<?php echo $l['no_of_instalments']; ?>,<?php echo $l['monthly_instalment']; ?>,'<?php echo addslashes($l['emp_status']); ?>',<?php echo $l['date_of_join']&&$l['date_of_join']!='0000-00-00'?json_encode($l['date_of_join']):'null'; ?>,<?php echo floatval($l['interest_rate'] ?? 0); ?>,'<?php echo addslashes($l['interest_frequency'] ?? 'Monthly'); ?>',<?php echo floatval($l['total_interest'] ?? 0); ?>,<?php echo floatval(($l['total_repayment'] ?? 0) > 0 ? $l['total_repayment'] : $l['loan_amount']); ?>,'<?php echo addslashes($l['loan_type'] ?? 'Office Loan'); ?>')" class="lr-icon-btn lr-icon-approve" title="Approve"><i class="fa-solid fa-check"></i></button>
                            <button onclick="openRejectModal(<?php echo $l['id']; ?>,<?php echo htmlspecialchars(json_encode($l['employee_full_name'])); ?>)" class="lr-icon-btn lr-icon-reject" title="Reject"><i class="fa-solid fa-xmark"></i></button>
                            <button onclick="deleteLoan(<?php echo $l['id']; ?>,'<?php echo addslashes($l['employee_full_name']); ?>')" class="lr-icon-btn lr-icon-delete" title="Delete"><i class="fa-solid fa-trash"></i></button>
                            <?php endif; ?>
                            <?php if ($l['status']==='Approved'): ?>
                            <button onclick="openSettleModal(<?php echo $l['id']; ?>,<?php echo htmlspecialchars(json_encode($l['employee_full_name'])); ?>,<?php echo $due_amt; ?>,<?php echo $paid_amt; ?>,<?php echo $outstanding_amt; ?>,'<?php echo addslashes($l['loan_type'] ?? 'Office Loan'); ?>')" class="lr-icon-btn lr-icon-settle" title="Settle / Record Payment"><i class="fa-solid fa-hand-holding-dollar"></i></button>
                            <?php endif; ?>
                            <?php if ($l['status']==='Approved' || $l['status']==='Settled'): ?>
                            <button onclick="openHistoryModal(<?php echo $l['id']; ?>,<?php echo htmlspecialchars(json_encode($l['employee_full_name'])); ?>)" class="lr-icon-btn lr-icon-history" title="Settlement History"><i class="fa-solid fa-clock-rotate-left"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- APPROVE MODAL -->
<div id="approveModal" class="lr-modal-bg" style="display:none;" onclick="if(event.target===this)this.style.display='none'">
    <div class="lr-modal">
        <div class="lr-modal-h">
            <i class="fa-solid fa-circle-check" style="color:#22c55e;font-size:18px;"></i>
            <h3>Approve Loan Request</h3>
            <button onclick="document.getElementById('approveModal').style.display='none'" class="lr-modal-close">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="loan_id" id="approve_id">
            <div class="lr-modal-b">
                <p style="margin:0 0 12px;font-size:14px;">Approving loan for <strong id="approve_emp"></strong></p>

                <!-- Loan Summary -->
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px;margin-bottom:14px;">
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px;">
                        <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;">Loan Amount</div>
                        <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;">Instalments</div>
                        <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;">Monthly</div>
                        <div style="font-size:14px;font-weight:800;color:#166534;" id="app_amount"></div>
                        <div style="font-size:14px;font-weight:800;color:#166534;" id="app_inst"></div>
                        <div style="font-size:14px;font-weight:800;color:#166534;" id="app_monthly"></div>
                    </div>
                    <div id="app_interest_row" style="display:none;border-top:1px solid #bbf7d0;padding-top:10px;margin-top:4px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
                            <div>
                                <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;margin-bottom:4px;">Loan Type</div>
                                <div style="font-size:13px;font-weight:700;color:#111;" id="app_loan_type"></div>
                            </div>
                            <div>
                                <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;margin-bottom:4px;">Interest</div>
                                <div style="font-size:13px;font-weight:700;color:#b45309;" id="app_interest_info"></div>
                            </div>
                            <div>
                                <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;margin-bottom:4px;">Total Repayment</div>
                                <div style="font-size:14px;font-weight:800;color:#166534;" id="app_total_repayment"></div>
                            </div>
                        </div>
                    </div>
                    <div id="app_noint_row" style="display:none;border-top:1px solid #bbf7d0;padding-top:8px;margin-top:8px;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div>
                                <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;margin-bottom:4px;">Loan Type</div>
                                <div style="font-size:13px;font-weight:700;color:#111;" id="app_loan_type2"></div>
                            </div>
                            <span style="font-size:12px;font-weight:700;color:#166534;background:#dcfce7;border:1px solid #bbf7d0;padding:3px 10px;border-radius:10px;margin-left:auto;">
                                <i class="fa-solid fa-circle-check"></i> Interest-Free
                            </span>
                        </div>
                    </div>
                    <div style="font-size:12px;color:#6b7280;margin-top:10px;border-top:1px solid #bbf7d0;padding-top:8px;">Approving as: <strong><?php echo htmlspecialchars($session_user); ?></strong></div>
                </div>

                <!-- Employee Info in Approval -->
                <div style="background:#f8faff;border:1px solid #dbeafe;border-radius:8px;padding:12px 14px;margin-bottom:14px;">
                    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
                        <div>
                            <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;font-weight:600;margin-bottom:3px;">Employee Status</div>
                            <span id="app_emp_status_badge" style="padding:3px 10px;border-radius:12px;font-size:12px;font-weight:700;"></span>
                        </div>
                        <div id="app_service_wrap">
                            <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;font-weight:600;margin-bottom:3px;">Years of Service</div>
                            <div style="font-size:15px;font-weight:800;color:#2563eb;" id="app_service"></div>
                        </div>
                        <div id="app_doj_wrap">
                            <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;font-weight:600;margin-bottom:3px;">Joined</div>
                            <div style="font-size:13px;font-weight:600;color:#111;" id="app_doj"></div>
                        </div>
                    </div>
                </div>

                <div class="lr-form-group">
                    <label class="lr-form-label">Remarks <span style="font-size:11px;color:#9ca3af;">(Optional)</span></label>
                    <textarea name="remarks" class="lr-form-input" rows="2" placeholder="Add any remarks..."></textarea>
                </div>
            </div>
            <div class="lr-modal-f">
                <button type="submit" class="lr-btn lr-btn-success"><i class="fa-solid fa-check"></i> Confirm Approval</button>
                <button type="button" onclick="document.getElementById('approveModal').style.display='none'" class="lr-btn lr-btn-g">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- REJECT MODAL -->
<div id="rejectModal" class="lr-modal-bg" style="display:none;" onclick="if(event.target===this)this.style.display='none'">
    <div class="lr-modal">
        <div class="lr-modal-h">
            <i class="fa-solid fa-circle-xmark" style="color:#ef4444;font-size:18px;"></i>
            <h3>Reject Loan Request</h3>
            <button onclick="document.getElementById('rejectModal').style.display='none'" class="lr-modal-close">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="loan_id" id="reject_id">
            <div class="lr-modal-b">
                <p style="margin:0 0 14px;font-size:14px;">Rejecting loan for <strong id="reject_emp"></strong></p>
                <div style="background:#fff5f5;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#991b1b;">
                    Rejecting as: <strong><?php echo htmlspecialchars($session_user); ?></strong>
                </div>
                <div class="lr-form-group">
                    <label class="lr-form-label">Rejection Note <span style="color:#ef4444;">*</span></label>
                    <textarea name="rejection_note" class="lr-form-input" rows="2" placeholder="Reason for rejection..." required></textarea>
                </div>
                <div class="lr-form-group">
                    <label class="lr-form-label">Remarks <span style="font-size:11px;color:#9ca3af;">(Optional)</span></label>
                    <textarea name="remarks" class="lr-form-input" rows="2" placeholder="Additional remarks..."></textarea>
                </div>
            </div>
            <div class="lr-modal-f">
                <button type="submit" class="lr-btn lr-btn-danger"><i class="fa-solid fa-xmark"></i> Confirm Rejection</button>
                <button type="button" onclick="document.getElementById('rejectModal').style.display='none'" class="lr-btn lr-btn-g">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- SETTLE / RECORD PAYMENT MODAL -->
<div id="settleModal" class="lr-modal-bg" style="display:none;" onclick="if(event.target===this)this.style.display='none'">
    <div class="lr-modal">
        <div class="lr-modal-h">
            <i class="fa-solid fa-hand-holding-dollar" style="color:#7c3aed;font-size:18px;"></i>
            <h3>Settle Loan / Record Payment</h3>
            <button onclick="document.getElementById('settleModal').style.display='none'" class="lr-modal-close">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="settle">
            <input type="hidden" name="loan_id" id="settle_id">
            <div class="lr-modal-b">
                <p style="margin:0 0 12px;font-size:14px;">Recording payment for <strong id="settle_emp"></strong> <span style="color:#9ca3af;font-size:12px;" id="settle_loan_type"></span></p>

                <div style="background:#faf5ff;border:1px solid #e9d5ff;border-radius:8px;padding:14px;margin-bottom:14px;">
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
                        <div>
                            <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;margin-bottom:4px;">Total Due</div>
                            <div style="font-size:14px;font-weight:800;color:#111;" id="settle_due"></div>
                        </div>
                        <div>
                            <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;margin-bottom:4px;">Already Paid</div>
                            <div style="font-size:14px;font-weight:800;color:#166534;" id="settle_paid"></div>
                        </div>
                        <div>
                            <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;margin-bottom:4px;">Outstanding</div>
                            <div style="font-size:14px;font-weight:800;color:#b45309;" id="settle_outstanding"></div>
                        </div>
                    </div>
                </div>

                <div class="lr-form-group">
                    <label class="lr-form-label">Payment Amount (LKR) <span style="color:#ef4444;">*</span></label>
                    <input type="number" name="settlement_amount" id="settle_amount" class="lr-form-input" min="0.01" step="0.01" required>
                    <small style="display:block;font-size:11px;color:#9ca3af;margin-top:4px;">Defaults to the full outstanding balance. Reduce this for a partial payment — the loan stays <strong>Approved</strong> until fully paid, then becomes <strong>Settled</strong> automatically.</small>
                </div>

                <div class="lr-form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="lr-form-group">
                        <label class="lr-form-label">Payment Date</label>
                        <input type="date" name="settlement_date" id="settle_date" class="lr-form-input" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="lr-form-group">
                        <label class="lr-form-label">Payment Method</label>
                        <select name="payment_method" class="lr-form-input">
                            <option value="Cash">Cash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Salary Deduction">Salary Deduction</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>

                <div class="lr-form-group">
                    <label class="lr-form-label">Note <span style="font-size:11px;color:#9ca3af;">(Optional)</span></label>
                    <textarea name="settlement_note" class="lr-form-input" rows="2" placeholder="Reference no., remarks..."></textarea>
                </div>

                <div style="font-size:12px;color:#6b7280;">Recorded by: <strong><?php echo htmlspecialchars($session_user); ?></strong></div>
            </div>
            <div class="lr-modal-f">
                <button type="submit" class="lr-btn lr-btn-success"><i class="fa-solid fa-check"></i> Record Payment</button>
                <button type="button" onclick="document.getElementById('settleModal').style.display='none'" class="lr-btn lr-btn-g">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- SETTLEMENT HISTORY MODAL -->
<div id="historyModal" class="lr-modal-bg" style="display:none;" onclick="if(event.target===this)this.style.display='none'">
    <div class="lr-modal" style="max-width:560px;">
        <div class="lr-modal-h">
            <i class="fa-solid fa-clock-rotate-left" style="color:#0e7490;font-size:18px;"></i>
            <h3>Settlement History — <span id="history_emp"></span></h3>
            <button onclick="document.getElementById('historyModal').style.display='none'" class="lr-modal-close">&times;</button>
        </div>
        <div class="lr-modal-b" style="max-height:420px;overflow-y:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:13px;" id="history_table">
                <thead>
                    <tr style="background:#f8faff;">
                        <th style="padding:8px 10px;text-align:left;">Date</th>
                        <th style="padding:8px 10px;text-align:right;">Amount</th>
                        <th style="padding:8px 10px;text-align:left;">Method</th>
                        <th style="padding:8px 10px;text-align:left;">By</th>
                        <th style="padding:8px 10px;text-align:center;">&nbsp;</th>
                    </tr>
                </thead>
                <tbody id="history_rows"></tbody>
            </table>
            <p id="history_empty" style="display:none;text-align:center;color:#9ca3af;padding:20px;margin:0;">No payments recorded yet.</p>
        </div>
        <div class="lr-modal-f">
            <button type="button" onclick="document.getElementById('historyModal').style.display='none'" class="lr-btn lr-btn-g">Close</button>
        </div>
    </div>
</div>

<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="loan_id" id="delete_id">
</form>

<form method="POST" id="deleteSettlementForm" style="display:none;">
    <input type="hidden" name="action" value="delete_settlement">
    <input type="hidden" name="settlement_id" id="delete_settlement_id">
</form>

<style>
*{box-sizing:border-box;}
.lr-stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:14px;margin-bottom:18px;}
.lr-stat-card{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 20px;box-shadow:0 2px 8px rgba(0,0,0,.04);}
.lr-stat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.lr-stat-blue   .lr-stat-icon{background:#dbeafe;color:#2563eb;}
.lr-stat-green  .lr-stat-icon{background:#dcfce7;color:#16a34a;}
.lr-stat-red    .lr-stat-icon{background:#fee2e2;color:#dc2626;}
.lr-stat-purple .lr-stat-icon{background:#ede9fe;color:#7c3aed;}
.lr-stat-indigo .lr-stat-icon{background:#e0e7ff;color:#4338ca;}
.lr-stat-teal   .lr-stat-icon{background:#ccfbf1;color:#0f766e;}
.lr-stat-amber  .lr-stat-icon{background:#fef3c7;color:#b45309;}
.lr-stat-cyan   .lr-stat-icon{background:#cffafe;color:#0e7490;}
.lr-stat-val{font-size:18px;font-weight:800;color:#111;line-height:1.2;}
.lr-stat-lbl{font-size:11px;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:.4px;}
.lr-filter-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center;}
.lr-search{padding:9px 14px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;width:220px;}
.lr-search:focus{outline:none;border-color:#3b82f6;}
.lr-select{padding:9px 14px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;background:#fff;cursor:pointer;}
.lr-select:focus{outline:none;border-color:#3b82f6;}
.lr-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.05);margin-bottom:16px;}
.lr-table-wrap{overflow-x:auto;}
.lr-table{width:100%;border-collapse:collapse;font-size:13px;}
.lr-table thead tr{background:#18181b;}
.lr-table thead th{padding:11px 14px;color:#fff;font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;border-right:1px solid #2a2a2e;white-space:nowrap;}
.lr-table thead th:last-child{border-right:none;}
.lr-table tbody tr{border-bottom:1px solid #f0f0f0;}
.lr-table tbody tr:hover{background:#f8faff;}
.lr-table td{padding:11px 14px;vertical-align:middle;}
.lr-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.lr-badge-pending {background:#fef3c7;color:#92400e;}
.lr-badge-approved{background:#dcfce7;color:#166534;}
.lr-badge-rejected{background:#fee2e2;color:#991b1b;}
.lr-badge-settled {background:#ede9fe;color:#5b21b6;}
.lr-badge-office  {background:#dbeafe;color:#1e40af;}
.lr-badge-welfare {background:#fce7f3;color:#9d174d;}
.lr-period-badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.lr-period-open{background:#dcfce7;color:#166534;}
.lr-period-locked{background:#f3f4f6;color:#6b7280;}
.lr-actions{display:flex;gap:5px;}
.lr-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;cursor:pointer;font-size:12px;color:#6b7280;transition:all .15s;text-decoration:none;}
.lr-icon-edit:hover   {background:#3b82f6;color:#fff;border-color:#3b82f6;}
.lr-icon-approve:hover{background:#22c55e;color:#fff;border-color:#22c55e;}
.lr-icon-reject:hover {background:#f59e0b;color:#fff;border-color:#f59e0b;}
.lr-icon-delete:hover {background:#ef4444;color:#fff;border-color:#ef4444;}
.lr-icon-settle:hover {background:#7c3aed;color:#fff;border-color:#7c3aed;}
.lr-icon-history:hover{background:#0e7490;color:#fff;border-color:#0e7490;}
.lr-row-del-btn{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:6px;border:1px solid #fecaca;background:#fff;color:#ef4444;cursor:pointer;font-size:11px;transition:all .15s;}
.lr-row-del-btn:hover{background:#ef4444;color:#fff;}
.lr-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap;}
.lr-btn-p{background:#111;color:#fff;}.lr-btn-p:hover{background:#333;}
.lr-btn-g{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.lr-btn-g:hover{background:#eee;}
.lr-btn-success{background:#22c55e;color:#fff;}.lr-btn-success:hover{background:#16a34a;}
.lr-btn-danger{background:#ef4444;color:#fff;}.lr-btn-danger:hover{background:#dc2626;}
.lr-alert{display:flex;align-items:center;gap:9px;padding:11px 16px;border-radius:8px;font-size:13px;}
.lr-alert-success{background:#dcfce7;border:1px solid #bbf7d0;color:#166534;}
.lr-alert-danger{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.lr-alert-warning{background:#fef3c7;border:1px solid #fde68a;color:#92400e;}
.lr-modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;}
.lr-modal{background:#fff;border-radius:14px;width:100%;max-width:540px;box-shadow:0 20px 60px rgba(0,0,0,.2);overflow:hidden;}
.lr-modal-h{display:flex;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid #f0f0f0;background:#fafafa;}
.lr-modal-h h3{font-size:14px;font-weight:700;margin:0;flex:1;color:#111;}
.lr-modal-close{background:none;border:none;font-size:22px;color:#aaa;cursor:pointer;}.lr-modal-close:hover{color:#111;}
.lr-modal-b{padding:20px;}
.lr-modal-f{display:flex;gap:8px;padding:14px 20px;border-top:1px solid #f0f0f0;background:#fafafa;}
.lr-form-group{margin-bottom:14px;}
.lr-form-label{display:block;font-size:12px;font-weight:600;color:#555;margin-bottom:6px;text-transform:uppercase;letter-spacing:.3px;}
.lr-form-input{width:100%;padding:10px 14px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;color:#111;}
.lr-form-input:focus{outline:none;border-color:#3b82f6;}
@media(max-width:1100px){.lr-stats-row{grid-template-columns:repeat(3,1fr);}}
@media(max-width:900px) {.lr-stats-row{grid-template-columns:1fr 1fr;}}
@media(max-width:540px) {.lr-stats-row{grid-template-columns:1fr;}.lr-search{width:100%;}}
</style>

<script>
const settlementsByLoan = <?php echo json_encode($settlements_by_loan, JSON_NUMERIC_CHECK); ?>;

const empStatusColors = {
    'Permanent': {bg:'#dcfce7', color:'#166534'},
    'Probation': {bg:'#fef3c7', color:'#92400e'},
    'Resigned':  {bg:'#fee2e2', color:'#991b1b'},
};

function calcYears(doj) {
    if (!doj) return null;
    const now  = new Date();
    const join = new Date(doj);
    const diff = (now - join) / (1000 * 60 * 60 * 24 * 365.25);
    return diff >= 0 ? diff : null;
}

function openApproveModal(id, name, amount, inst, monthly, empStatus, doj) {
    document.getElementById('approve_id').value = id;
    document.getElementById('approve_emp').textContent = name;
    document.getElementById('app_amount').textContent  = 'LKR ' + parseFloat(amount).toLocaleString('en-US',{minimumFractionDigits:2});
    document.getElementById('app_inst').textContent    = inst + ' months';
    document.getElementById('app_monthly').textContent = 'LKR ' + parseFloat(monthly).toLocaleString('en-US',{minimumFractionDigits:2});

    // Employee status badge
    const sc = empStatusColors[empStatus] || {bg:'#f3f4f6',color:'#6b7280'};
    const badge = document.getElementById('app_emp_status_badge');
    badge.textContent = empStatus;
    badge.style.background = sc.bg;
    badge.style.color = sc.color;

    // Years of service
    const yrs = calcYears(doj);
    const svcWrap = document.getElementById('app_service_wrap');
    const dojWrap = document.getElementById('app_doj_wrap');
    if (yrs !== null) {
        const yInt = Math.floor(yrs);
        const mRem = Math.round((yrs - yInt) * 12);
        let txt = yInt + ' yr' + (yInt !== 1 ? 's' : '');
        if (mRem > 0) txt += ' ' + mRem + ' mo';
        document.getElementById('app_service').textContent = txt;
        svcWrap.style.display = 'block';

        const d = new Date(doj);
        document.getElementById('app_doj').textContent = d.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
        dojWrap.style.display = 'block';
    } else {
        svcWrap.style.display = 'none';
        dojWrap.style.display = 'none';
    }

    document.getElementById('approveModal').style.display = 'flex';
}

function openRejectModal(id, name) {
    document.getElementById('reject_id').value = id;
    document.getElementById('reject_emp').textContent = name;
    document.getElementById('rejectModal').style.display = 'flex';
}

function deleteLoan(id, name) {
    if (confirm('Delete loan request for ' + name + '? This cannot be undone.')) {
        document.getElementById('delete_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}

function fmtMoney(val) {
    return 'LKR ' + parseFloat(val || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function openSettleModal(id, name, due, paid, outstanding, loanType) {
    document.getElementById('settle_id').value = id;
    document.getElementById('settle_emp').textContent = name;
    document.getElementById('settle_loan_type').textContent = '(' + loanType + ')';
    document.getElementById('settle_due').textContent = fmtMoney(due);
    document.getElementById('settle_paid').textContent = fmtMoney(paid);
    document.getElementById('settle_outstanding').textContent = fmtMoney(outstanding);

    const amountInput = document.getElementById('settle_amount');
    amountInput.value = outstanding.toFixed(2);
    amountInput.max = outstanding.toFixed(2);

    document.getElementById('settleModal').style.display = 'flex';
}

function openHistoryModal(id, name) {
    document.getElementById('history_emp').textContent = name;
    const rows = document.getElementById('history_rows');
    const emptyMsg = document.getElementById('history_empty');
    const table = document.getElementById('history_table');
    rows.innerHTML = '';

    const records = settlementsByLoan[id] || [];
    if (records.length === 0) {
        table.style.display = 'none';
        emptyMsg.style.display = 'block';
    } else {
        table.style.display = 'table';
        emptyMsg.style.display = 'none';
        records.forEach(r => {
            const tr = document.createElement('tr');
            tr.style.borderBottom = '1px solid #f0f0f0';
            const d = new Date(r.date);
            const dateTxt = isNaN(d) ? r.date : d.toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'});
            tr.innerHTML =
                '<td style="padding:8px 10px;">' + dateTxt + '</td>' +
                '<td style="padding:8px 10px;text-align:right;font-weight:700;color:#166534;">' + fmtMoney(r.amount) + '</td>' +
                '<td style="padding:8px 10px;">' + (r.method || '') + '</td>' +
                '<td style="padding:8px 10px;color:#6b7280;">' + (r.by || '') + '</td>' +
                '<td style="padding:8px 10px;text-align:center;"></td>';
            const delTd = tr.lastElementChild;
            const delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'lr-row-del-btn';
            delBtn.title = 'Delete this payment';
            delBtn.innerHTML = '<i class="fa-solid fa-trash"></i>';
            delBtn.onclick = () => deleteSettlement(r.id, r.amount, dateTxt);
            delTd.appendChild(delBtn);
            rows.appendChild(tr);
        });
    }

    document.getElementById('historyModal').style.display = 'flex';
}

function deleteSettlement(settlementId, amount, dateTxt) {
    if (confirm('Delete the payment of ' + fmtMoney(amount) + ' recorded on ' + dateTxt + '?\n\nThis cannot be undone and will update the loan\'s outstanding balance. If the loan was Settled, it may revert to Approved.')) {
        document.getElementById('delete_settlement_id').value = settlementId;
        document.getElementById('deleteSettlementForm').submit();
    }
}
</script>

<?php include 'footer.php'; ?>