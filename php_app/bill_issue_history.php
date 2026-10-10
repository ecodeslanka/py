<?php
include 'config.php';
include 'header.php';

/* ─── Safety: make sure the linked columns exist (same guard used elsewhere) ─── */
mysqli_query($conn,"ALTER TABLE `credit_bill_issues` ADD COLUMN IF NOT EXISTS `employee_id` INT DEFAULT NULL AFTER `person_name`");

/* ─── INPUT ─── */
$raw_input        = trim($_GET['invoice_no'] ?? '');
$selected_invoice = '';
$candidates       = [];
$bill_rows        = [];
$issue_rows       = [];

if ($raw_input !== '') {
    $esc = mysqli_real_escape_string($conn, $raw_input);

    /* 1) exact (case-insensitive) match first — either on the bill itself
          or on any issue record that ever referenced this invoice number */
    $exact_sql = "
        SELECT invoice_num FROM field_summary_details      WHERE LOWER(invoice_num) = LOWER('$esc')
        UNION
        SELECT invoice_num FROM credit_bill_issue_items     WHERE LOWER(invoice_num) = LOWER('$esc')
        LIMIT 1
    ";
    $exact_res = mysqli_query($conn, $exact_sql);
    if ($exact_res && mysqli_num_rows($exact_res) > 0) {
        $selected_invoice = mysqli_fetch_assoc($exact_res)['invoice_num'];
    } else {
        /* 2) no exact hit — fall back to a partial search so the user can pick */
        $cand_sql = "
            SELECT invoice_num FROM (
                SELECT invoice_num FROM field_summary_details  WHERE invoice_num LIKE '%$esc%'
                UNION
                SELECT invoice_num FROM credit_bill_issue_items WHERE invoice_num LIKE '%$esc%'
            ) t
            WHERE invoice_num <> ''
            ORDER BY invoice_num
            LIMIT 40
        ";
        $cand_res = mysqli_query($conn, $cand_sql);
        while ($r = mysqli_fetch_assoc($cand_res)) $candidates[] = $r['invoice_num'];

        if (count($candidates) === 1) {
            $selected_invoice = $candidates[0];
            $candidates = [];
        }
    }
}

/* ─── BILL HEADER + ISSUE HISTORY for the selected invoice ─── */
if ($selected_invoice !== '') {
    $inv_esc = mysqli_real_escape_string($conn, $selected_invoice);

    /* Bill / credit-bill info (there is normally one row, but loop just in case) */
    $bill_sql = "
        SELECT
            fsd.id                                                              AS detail_id,
            fsd.invoice_num,
            fsd.t_code,
            COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code)      AS customer_name,
            fsd.route,
            fs.sr_code,
            fs.delivery_date,
            fs.field_summary_code,
            COALESCE(siid.final_bill_amount, fsd.adjust_net_value, fsd.net_value) AS bill_amount,
            COALESCE(siid.bill_date, fs.delivery_date)                          AS bill_date,
            fsd.payment_status,
            COALESCE(pay.total_paid,   0)                                       AS total_paid,
            COALESCE(pay.cash_paid,    0)                                       AS cash_paid,
            COALESCE(pay.cheque_paid,  0)                                       AS cheque_paid,
            COALESCE(cn.total_cn,      0)                                       AS total_cn,
            (COALESCE(siid.final_bill_amount, fsd.adjust_net_value, fsd.net_value)
                - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0))          AS balance
        FROM field_summary_details fsd
        LEFT JOIN field_summary fs ON fs.id = fsd.field_summary_id
        LEFT JOIN customers c      ON c.t_code = fsd.t_code
        LEFT JOIN (
            SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount, MAX(bill_date) AS bill_date
            FROM secondary_invoice_import_details GROUP BY bill_no
        ) siid ON siid.bill_no = fsd.invoice_num
        LEFT JOIN (
            SELECT field_summary_detail_id,
                   SUM(amount) AS total_paid,
                   SUM(CASE WHEN payment_method='cash'   THEN amount ELSE 0 END) AS cash_paid,
                   SUM(CASE WHEN payment_method='cheque' THEN amount ELSE 0 END) AS cheque_paid
            FROM invoice_payments GROUP BY field_summary_detail_id
        ) pay ON pay.field_summary_detail_id = fsd.id
        LEFT JOIN (
            SELECT field_summary_detail_id, SUM(amount) AS total_cn
            FROM credit_notes WHERE is_deleted = 0 GROUP BY field_summary_detail_id
        ) cn ON cn.field_summary_detail_id = fsd.id
        WHERE fsd.invoice_num = '$inv_esc'
        ORDER BY fs.delivery_date DESC
    ";
    $bill_res = mysqli_query($conn, $bill_sql);
    if ($bill_res) while ($r = mysqli_fetch_assoc($bill_res)) $bill_rows[] = $r;

    /* Full issue history — every time this invoice/credit-bill was issued out */
    $issue_sql = "
        SELECT
            bi.id                                AS issue_id,
            bi.issue_code,
            bi.issue_date,
            bi.person_type,
            bi.person_code,
            bi.person_name,
            bi.notes                             AS issue_notes,
            bi.created_at                        AS issue_created_at,
            COALESCE(emp.employee_id,'')         AS emp_code,
            COALESCE(emp.employee_full_name,'')  AS emp_name,
            COALESCE(d.designation_name,'')      AS emp_desig,
            bii.id                               AS item_id,
            bii.status                           AS item_status,
            bii.balance                          AS item_balance,
            bii.returned_at,
            bii.customer_name                    AS item_customer_name
        FROM credit_bill_issue_items bii
        INNER JOIN credit_bill_issues bi ON bi.id = bii.issue_id
        LEFT JOIN employees emp    ON emp.id = bi.employee_id
        LEFT JOIN designations d   ON d.id = emp.designation_id
        WHERE bii.invoice_num = '$inv_esc'
        ORDER BY bi.issue_date DESC, bii.id DESC
    ";
    $issue_res = mysqli_query($conn, $issue_sql);
    if ($issue_res) while ($r = mysqli_fetch_assoc($issue_res)) $issue_rows[] = $r;
}

/* ─── ISSUE COUNT DETAILS (summary over the issue history) ─── */
$total_events   = count($issue_rows);
$returned_count = 0;
$paid_count     = 0;
$active_count   = 0;
$persons        = [];
$first_date     = null;
$last_date      = null;
$current_holder = null;

foreach ($issue_rows as $ir) {
    if ($ir['item_status'] === 'returned') {
        $returned_count++;
    } elseif ($ir['item_status'] === 'issued' && floatval($ir['item_balance']) <= 0) {
        $paid_count++;
    } elseif ($ir['item_status'] === 'issued') {
        $active_count++;
        if ($current_holder === null) $current_holder = $ir;
    }
    $pkey = $ir['person_type'].'|'.$ir['person_code'];
    if (!isset($persons[$pkey])) $persons[$pkey] = ['type'=>$ir['person_type'],'code'=>$ir['person_code'],'name'=>$ir['person_name']];

    $d = $ir['issue_date'];
    if ($first_date === null || $d < $first_date) $first_date = $d;
    if ($last_date  === null || $d > $last_date)  $last_date  = $d;
}
$distinct_persons = count($persons);
?>

<style>
*{box-sizing:border-box;}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;}
.page-title{font-size:20px;font-weight:800;color:#1f2937;display:flex;align-items:center;gap:8px;}
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s;white-space:nowrap;}
.btn-primary{background:#6366f1;color:#fff;}.btn-primary:hover{background:#4f46e5;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-sm{padding:8px 14px;font-size:12px;}

.search-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:20px 22px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.search-title{font-size:13px;font-weight:700;color:#374151;margin-bottom:14px;display:flex;align-items:center;gap:6px;}
.search-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
.search-fg{display:flex;flex-direction:column;gap:5px;flex:1;min-width:220px;}
.search-fg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.search-fg input{border:1.5px solid #e5e5e5;border-radius:7px;padding:10px 12px;font-size:14px;font-family:monospace;font-weight:700;color:#1f2937;width:100%;transition:border .2s;}
.search-fg input:focus{outline:none;border-color:#6366f1;}

.state-box{text-align:center;padding:60px 30px;color:#9ca3af;background:#fff;border:1px solid #e5e5e5;border-radius:10px;}
.state-box i{font-size:40px;display:block;margin-bottom:12px;opacity:.4;}
.state-box p{font-size:14px;color:#6b7280;margin:0 0 6px;}
.state-box .sub{font-size:12px;color:#9ca3af;}

.cand-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);margin-bottom:20px;}
.cand-header{padding:13px 18px;border-bottom:1px solid #f0f0f0;font-size:13px;font-weight:700;color:#374151;background:#fffbeb;}
.cand-list{max-height:400px;overflow-y:auto;}
.cand-item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 18px;border-bottom:1px solid #f3f4f6;text-decoration:none;color:inherit;transition:background .1s;}
.cand-item:hover{background:#f8fafc;}
.cand-inv{font-family:monospace;font-weight:800;color:#4338ca;font-size:13px;}
.cand-arrow{color:#9ca3af;}

.bill-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:20px 22px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.bill-card-title{font-size:14px;font-weight:800;color:#1f2937;display:flex;align-items:center;gap:8px;margin-bottom:16px;}
.bill-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;}
.bill-field{display:flex;flex-direction:column;gap:3px;}
.bill-field .lbl{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;}
.bill-field .val{font-size:14px;font-weight:700;color:#1f2937;}
.bill-field .val.mono{font-family:monospace;color:#4338ca;}
.bill-field .val.red{color:#dc2626;}
.bill-field .val.green{color:#16a34a;}

.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:20px;}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px;}
.stat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.stat-value{font-size:18px;font-weight:800;color:#1f2937;}
.stat-value.blue{color:#2563eb;}.stat-value.green{color:#16a34a;}.stat-value.red{color:#dc2626;}.stat-value.amber{color:#d97706;}.stat-value.teal{color:#0d9488;}.stat-value.violet{color:#6366f1;}

.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.table-toolbar{padding:13px 18px;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;background:#ede9fe;color:#5b21b6;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead th{padding:9px 10px;text-align:left;font-weight:700;font-size:11px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;}
.data-table thead th.tr{text-align:right;}.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr:hover td{background:#f8fafc;}
.data-table td{padding:9px 10px;color:#374151;vertical-align:middle;}
.data-table td.tr{text-align:right;}.data-table td.tc{text-align:center;}

.sr-pill{background:#ede9fe;color:#5b21b6;padding:2px 7px;border-radius:9px;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:3px;}
.cc-pill{background:#fef3c7;color:#92400e;padding:2px 7px;border-radius:9px;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:3px;}
.issued-b{background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.returned-b{background:#dcfce7;color:#166534;border:1px solid #86efac;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.paid-b{background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.person-cell{display:flex;flex-direction:column;gap:2px;}
.person-code{font-family:monospace;font-size:12px;font-weight:800;color:#1f2937;}
.person-name-sub{font-size:10px;color:#6b7280;}
.emp-sub{font-size:10px;color:#0891b2;}
.latest-tag{background:#6366f1;color:#fff;font-size:9px;font-weight:800;padding:1px 6px;border-radius:6px;margin-left:6px;letter-spacing:.03em;}
</style>

<!-- PAGE HEADER -->
<div class="page-header">
    <div class="page-title">
        <i class="fa-solid fa-file-invoice-dollar" style="color:#6366f1;"></i> Bill Issue History
    </div>
    <div style="display:flex;gap:8px;">
        <a href="credit_bill_issue_history.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-clock-rotate-left"></i> All Issue History</a>
        <a href="credit_bill_issue.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-paper-plane"></i> Issue Bills</a>
    </div>
</div>

<!-- SEARCH -->
<div class="search-card">
    <div class="search-title"><i class="fa-solid fa-magnifying-glass"></i> Search by Invoice / Credit Bill No</div>
    <form method="GET">
        <div class="search-row">
            <div class="search-fg">
                <label><i class="fa-solid fa-hashtag"></i> Invoice No</label>
                <input type="text" name="invoice_no" value="<?php echo htmlspecialchars($raw_input); ?>" placeholder="Enter invoice / credit bill number…" autofocus>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            <?php if($raw_input !== ''): ?>
            <a href="bill_issue_history.php" class="btn btn-secondary"><i class="fa-solid fa-rotate-left"></i> Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($raw_input === ''): ?>

    <div class="state-box">
        <i class="fa-solid fa-file-invoice-dollar"></i>
        <p>Enter an invoice number above to view its issue history.</p>
        <div class="sub">Shows every time this credit bill was issued to an SR / CC, its current status, and full issue counts.</div>
    </div>

<?php elseif (!empty($candidates)): ?>

    <div class="cand-card">
        <div class="cand-header"><i class="fa-solid fa-list-check"></i> Multiple invoices match "<?php echo htmlspecialchars($raw_input); ?>" — select one to view its full history</div>
        <div class="cand-list">
            <?php foreach($candidates as $cand): ?>
            <a class="cand-item" href="bill_issue_history.php?invoice_no=<?php echo urlencode($cand); ?>">
                <span class="cand-inv"><?php echo htmlspecialchars($cand); ?></span>
                <span class="cand-arrow"><i class="fa-solid fa-chevron-right"></i></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

<?php elseif ($selected_invoice === '' || (empty($bill_rows) && empty($issue_rows))): ?>

    <div class="state-box">
        <i class="fa-solid fa-circle-exclamation"></i>
        <p>No invoice or issue record found for "<?php echo htmlspecialchars($raw_input); ?>".</p>
        <div class="sub">Double check the invoice number and try again.</div>
    </div>

<?php else: ?>

    <!-- ══ BILL / CREDIT BILL INFO ══ -->
    <?php foreach($bill_rows as $b):
        $b_bal = floatval($b['balance']);
    ?>
    <div class="bill-card">
        <div class="bill-card-title"><i class="fa-solid fa-file-invoice" style="color:#6366f1;"></i> Credit Bill Details — <span style="font-family:monospace;color:#4338ca;"><?php echo htmlspecialchars($b['invoice_num']); ?></span></div>
        <div class="bill-grid">
            <div class="bill-field"><span class="lbl">Customer</span><span class="val"><?php echo htmlspecialchars($b['customer_name'] ?: '—'); ?></span></div>
            <div class="bill-field"><span class="lbl">T-Code</span><span class="val mono"><?php echo htmlspecialchars($b['t_code'] ?: '—'); ?></span></div>
            <div class="bill-field"><span class="lbl">Route</span><span class="val"><?php echo htmlspecialchars($b['route'] ?: '—'); ?></span></div>
            <div class="bill-field"><span class="lbl">SR Code</span><span class="val"><?php echo htmlspecialchars($b['sr_code'] ?: '—'); ?></span></div>
            <div class="bill-field"><span class="lbl">Bill / Delivery Date</span><span class="val"><?php echo $b['bill_date'] ? date('d M Y', strtotime($b['bill_date'])) : '—'; ?></span></div>
            <div class="bill-field"><span class="lbl">Bill Amount</span><span class="val">Rs. <?php echo number_format(floatval($b['bill_amount']),2); ?></span></div>
            <div class="bill-field"><span class="lbl">Cash Paid</span><span class="val green">Rs. <?php echo number_format(floatval($b['cash_paid']),2); ?></span></div>
            <div class="bill-field"><span class="lbl">Cheque Paid</span><span class="val" style="color:#2563eb;">Rs. <?php echo number_format(floatval($b['cheque_paid']),2); ?></span></div>
            <div class="bill-field"><span class="lbl">Credit Notes</span><span class="val" style="color:#ea580c;">Rs. <?php echo number_format(floatval($b['total_cn']),2); ?></span></div>
            <div class="bill-field"><span class="lbl">Outstanding Balance</span><span class="val <?php echo $b_bal>0?'red':'green'; ?>">Rs. <?php echo number_format($b_bal,2); ?></span></div>
            <div class="bill-field"><span class="lbl">Payment Status</span><span class="val"><?php echo htmlspecialchars($b['payment_status'] ?: '—'); ?></span></div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- ══ ISSUE COUNT DETAILS ══ -->
    <div class="stat-grid">
        <div class="stat-card"><div class="stat-label">Total Times Issued</div><div class="stat-value violet"><?php echo $total_events; ?></div></div>
        <div class="stat-card"><div class="stat-label">Currently With Person</div><div class="stat-value blue"><?php echo $active_count; ?></div></div>
        <div class="stat-card"><div class="stat-label">Paid to Customer</div><div class="stat-value teal"><?php echo $paid_count; ?></div></div>
        <div class="stat-card"><div class="stat-label">Returned Count</div><div class="stat-value green"><?php echo $returned_count; ?></div></div>
        <div class="stat-card"><div class="stat-label">Persons Handled</div><div class="stat-value amber"><?php echo $distinct_persons; ?></div></div>
        <div class="stat-card"><div class="stat-label">First Issued</div><div class="stat-value" style="font-size:13px;"><?php echo $first_date ? date('d M Y', strtotime($first_date)) : '—'; ?></div></div>
        <div class="stat-card"><div class="stat-label">Last Issued</div><div class="stat-value" style="font-size:13px;"><?php echo $last_date ? date('d M Y', strtotime($last_date)) : '—'; ?></div></div>
        <div class="stat-card">
            <div class="stat-label">Current Holder</div>
            <div class="stat-value" style="font-size:13px;">
                <?php if($current_holder): ?>
                    <?php echo htmlspecialchars($current_holder['person_code']); ?>
                    <span style="font-size:10px;color:#9ca3af;"> (<?php echo htmlspecialchars($current_holder['person_type']); ?>)</span>
                <?php else: ?>
                    <span style="color:#9ca3af;">Not issued</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ══ ISSUE DETAILS (full history) ══ -->
    <div class="table-card">
        <div class="table-toolbar">
            <div class="tbl-title">
                <i class="fa-solid fa-list"></i> Issue Details
                <span class="pill"><?php echo $total_events; ?> event<?php echo $total_events!=1?'s':''; ?></span>
            </div>
        </div>
        <?php if($total_events === 0): ?>
            <div class="state-box" style="border:none;">
                <i class="fa-solid fa-inbox"></i>
                <p>This invoice has never been issued to an SR / CC.</p>
            </div>
        <?php else: ?>
        <div class="dt-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:30px;">No</th>
                    <th>Issue Code</th>
                    <th class="tc">Type</th>
                    <th>Issued To</th>
                    <th>Employee</th>
                    <th class="tc">Status</th>
                    <th class="tr">Balance at Issue</th>
                    <th>Issue Date</th>
                    <th>Returned At</th>
                    <th>Notes</th>
                    <th>Recorded At</th>
                </tr>
            </thead>
            <tbody>
            <?php $rn=1; foreach($issue_rows as $i => $ir):
                $bal = floatval($ir['item_balance']);
                if ($ir['item_status']==='returned') { $badge = '<span class="returned-b"><i class="fa-solid fa-rotate-left"></i> Returned</span>'; }
                elseif ($ir['item_status']==='issued' && $bal<=0) { $badge = '<span class="paid-b"><i class="fa-solid fa-circle-check"></i> Paid to Customer</span>'; }
                else { $badge = '<span class="issued-b"><i class="fa-solid fa-paper-plane"></i> Active / Outstanding</span>'; }
            ?>
                <tr>
                    <td style="color:#9ca3af;font-size:11px;font-weight:600;"><?php echo $rn++; ?></td>
                    <td>
                        <span style="font-family:monospace;font-size:12px;font-weight:800;color:#4338ca;"><?php echo htmlspecialchars($ir['issue_code']); ?></span>
                        <?php if($i===0): ?><span class="latest-tag">LATEST</span><?php endif; ?>
                    </td>
                    <td class="tc">
                        <?php if($ir['person_type']==='SR'): ?>
                            <span class="sr-pill"><i class="fa-solid fa-id-badge"></i> SR</span>
                        <?php else: ?>
                            <span class="cc-pill"><i class="fa-solid fa-wallet"></i> CC</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="person-cell">
                            <span class="person-code"><?php echo htmlspecialchars($ir['person_code'] ?: '—'); ?></span>
                            <?php if($ir['person_name']): ?><span class="person-name-sub"><?php echo htmlspecialchars($ir['person_name']); ?></span><?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php if($ir['emp_name']): ?>
                            <span class="emp-sub"><i class="fa-solid fa-user-check" style="font-size:9px;"></i> <?php echo htmlspecialchars($ir['emp_name']); ?><?php echo $ir['emp_desig']?' ('.htmlspecialchars($ir['emp_desig']).')':''; ?></span>
                        <?php else: ?>
                            <span style="color:#d1d5db;font-size:11px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="tc"><?php echo $badge; ?></td>
                    <td class="tr"><span style="font-weight:700;"><?php echo $bal>0 ? 'Rs. '.number_format($bal,2) : '<span style="color:#9ca3af;">Rs. 0.00</span>'; ?></span></td>
                    <td style="white-space:nowrap;"><?php echo $ir['issue_date'] ? date('d M Y', strtotime($ir['issue_date'])) : '—'; ?></td>
                    <td style="white-space:nowrap;"><?php echo $ir['returned_at'] ? date('d M Y H:i', strtotime($ir['returned_at'])) : '<span style="color:#d1d5db;">—</span>'; ?></td>
                    <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:#6b7280;" title="<?php echo htmlspecialchars($ir['issue_notes']??''); ?>"><?php echo htmlspecialchars($ir['issue_notes'] ?: '—'); ?></td>
                    <td style="white-space:nowrap;font-size:11px;color:#6b7280;"><?php echo $ir['issue_created_at'] ? date('d M Y H:i', strtotime($ir['issue_created_at'])) : '—'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php include 'footer.php'; ?>
