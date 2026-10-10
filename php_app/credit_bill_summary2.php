<?php
include 'config.php';

/* ── Ensure credit_notes table exists ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `credit_notes` (
    `id`                       INT AUTO_INCREMENT PRIMARY KEY,
    `field_summary_detail_id`  INT NOT NULL,
    `amount`                   DECIMAL(12,2) NOT NULL,
    `reason`                   TEXT,
    `note_date`                DATE NOT NULL,
    `created_at`               DATETIME DEFAULT CURRENT_TIMESTAMP,
    `is_deleted`               TINYINT(1) NOT NULL DEFAULT 0,
    INDEX `idx_fsd_id` (`field_summary_detail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$chknd = mysqli_query($conn,"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credit_notes' AND COLUMN_NAME='note_date' LIMIT 1");
if($chknd && mysqli_num_rows($chknd)===0) mysqli_query($conn,"ALTER TABLE credit_notes ADD COLUMN `note_date` DATE NOT NULL DEFAULT (CURDATE()) AFTER reason");

/* ── Ensure to_be_delivery column exists ── */
$chktbd = mysqli_query($conn, "SHOW COLUMNS FROM field_summary_details LIKE 'to_be_delivery'");
if ($chktbd && mysqli_num_rows($chktbd) === 0)
    mysqli_query($conn, "ALTER TABLE field_summary_details ADD COLUMN to_be_delivery TINYINT(1) NOT NULL DEFAULT 0");

/* ── Ensure credit_bill_remarks table exists ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `credit_bill_remarks` (
    `id`                       INT AUTO_INCREMENT PRIMARY KEY,
    `field_summary_detail_id`  INT NOT NULL,
    `remark`                   TEXT NOT NULL,
    `created_at`               DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_fsd_id` (`field_summary_detail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Ensure credit_bill_notes table exists ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `credit_bill_notes` (
    `id`                       INT AUTO_INCREMENT PRIMARY KEY,
    `field_summary_detail_id`  INT NOT NULL,
    `note_date`                DATE NOT NULL,
    `note`                     TEXT NOT NULL,
    `created_at`               DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_fsd_id` (`field_summary_detail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── AJAX: Payment History ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'payment_history' && isset($_GET['detail_id'])) {
    header('Content-Type: application/json');
    $detail_id  = intval($_GET['detail_id']);
    $ajax_as_at = trim($_GET['as_at_date'] ?? '');

    $info_sql = "SELECT fsd.invoice_num,
                        COALESCE(siid.final_bill_amount, fsd.adjust_net_value) AS net_value,
                        COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS customer_name,
                        fsd.t_code, fs.delivery_date, fs.route AS route_code, fs.sr_code
                 FROM field_summary_details fsd
                 INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
                 LEFT JOIN customers c ON c.t_code = fsd.t_code
                 LEFT JOIN (
                     SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
                     FROM secondary_invoice_import_details
                     GROUP BY bill_no
                 ) siid ON siid.bill_no = fsd.invoice_num
                 WHERE fsd.id = $detail_id LIMIT 1";
    $info_res = mysqli_query($conn, $info_sql);
    $info = $info_res ? mysqli_fetch_assoc($info_res) : null;

    $ajax_date_clause = '';
    if ($ajax_as_at) {
        $ajax_as_at_esc  = mysqli_real_escape_string($conn, $ajax_as_at);
        $ajax_date_clause = "AND ip.payment_date <= '$ajax_as_at_esc'";
    }

    $pay_sql = "SELECT ip.id, ip.payment_method, ip.payment_date, ip.amount, ip.amount_to_bank,
                       ip.reference_no, ip.collected_by, ip.cheque_mode, ip.remarks, ip.payment_source, ip.created_at,
                       DATE_FORMAT(ip.payment_date,'%d %b %Y') AS pay_date_fmt,
                       DATE_FORMAT(ip.created_at,'%d %b %Y %H:%i') AS created_fmt,
                       ipc.cheque_no, ipc.cheque_date, ipc.bank_name, ipc.branch_name,
                       COALESCE(ch.status,'pending') AS cheque_status
                FROM invoice_payments ip
                LEFT JOIN invoice_payment_cheques ipc ON ipc.invoice_payment_id = ip.id
                LEFT JOIN cheques ch ON ch.cheque_no=ipc.cheque_no AND ch.bank_code=ipc.bank_code AND ch.branch_code=ipc.branch_code
                WHERE ip.field_summary_detail_id = $detail_id AND ip.is_reversed = 0 $ajax_date_clause
                ORDER BY ip.payment_date DESC, ip.id DESC";
    $pay_res = mysqli_query($conn, $pay_sql);
    $payments = [];
    $total_paid = 0;
    if ($pay_res) {
        while ($p = mysqli_fetch_assoc($pay_res)) {
            $payments[] = $p;
            if ($p['payment_method'] === 'cash') $total_paid += floatval($p['amount']);
            elseif (strtolower(trim($p['cheque_status'] ?? '')) === 'cleared') $total_paid += floatval($p['amount']);
        }
    }

    $cn_total = 0;
    $cn_res = mysqli_query($conn,"SELECT COALESCE(SUM(amount),0) AS t FROM credit_notes WHERE field_summary_detail_id=$detail_id AND is_deleted=0");
    if($cn_res) $cn_total = floatval(mysqli_fetch_assoc($cn_res)['t']);

    echo json_encode([
        'success'    => true,
        'info'       => $info,
        'payments'   => $payments,
        'total_paid' => $total_paid,
        'total_cn'   => $cn_total,
        'balance'    => $info ? floatval($info['net_value']) - $total_paid - $cn_total : 0,
        'as_at_date' => $ajax_as_at
    ]);
    exit;
}

/* ── AJAX: Remark List ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'remark_list' && isset($_GET['detail_id'])) {
    header('Content-Type: application/json');
    $detail_id = intval($_GET['detail_id']);
    $rres = mysqli_query($conn,"SELECT id, remark, created_at, DATE_FORMAT(created_at,'%d %b %Y %H:%i') AS created_fmt
                                 FROM credit_bill_remarks
                                 WHERE field_summary_detail_id = $detail_id
                                 ORDER BY created_at DESC, id DESC");
    $remarks = [];
    if ($rres) while ($r = mysqli_fetch_assoc($rres)) $remarks[] = $r;
    echo json_encode(['success'=>true,'remarks'=>$remarks]);
    exit;
}

/* ── AJAX: Remark Add (always inserts a new remark — never overwrites) ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'remark_add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $detail_id = intval($_POST['detail_id'] ?? 0);
    $remark    = trim($_POST['remark'] ?? '');
    if ($detail_id <= 0 || $remark === '') { echo json_encode(['success'=>false,'error'=>'Remark text is required']); exit; }
    $remark_esc = mysqli_real_escape_string($conn, $remark);
    mysqli_query($conn,"INSERT INTO credit_bill_remarks (field_summary_detail_id, remark) VALUES ($detail_id, '$remark_esc')");
    echo json_encode([
        'success'      => true,
        'id'           => mysqli_insert_id($conn),
        'remark'       => $remark,
        'created_fmt'  => date('d M Y H:i')
    ]);
    exit;
}

/* ── AJAX: Note List ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'note_list' && isset($_GET['detail_id'])) {
    header('Content-Type: application/json');
    $detail_id = intval($_GET['detail_id']);
    $nres = mysqli_query($conn,"SELECT id, note, note_date,
                                        DATE_FORMAT(note_date,'%d %b %Y') AS note_date_fmt,
                                        DATE_FORMAT(created_at,'%d %b %Y %H:%i') AS created_fmt
                                 FROM credit_bill_notes
                                 WHERE field_summary_detail_id = $detail_id
                                 ORDER BY note_date DESC, id DESC");
    $notes = [];
    if ($nres) while ($n = mysqli_fetch_assoc($nres)) $notes[] = $n;
    echo json_encode(['success'=>true,'notes'=>$notes]);
    exit;
}

/* ── AJAX: Note Add ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'note_add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $detail_id = intval($_POST['detail_id'] ?? 0);
    $note      = trim($_POST['note'] ?? '');
    $note_date = trim($_POST['note_date'] ?? '');
    if ($detail_id <= 0 || $note === '' || $note_date === '') { echo json_encode(['success'=>false,'error'=>'Note and date are required']); exit; }
    $note_esc      = mysqli_real_escape_string($conn, $note);
    $note_date_esc = mysqli_real_escape_string($conn, $note_date);
    mysqli_query($conn,"INSERT INTO credit_bill_notes (field_summary_detail_id, note_date, note) VALUES ($detail_id, '$note_date_esc', '$note_esc')");
    echo json_encode([
        'success'       => true,
        'id'            => mysqli_insert_id($conn),
        'note'          => $note,
        'note_date'     => $note_date,
        'note_date_fmt' => date('d M Y', strtotime($note_date)),
        'created_fmt'   => date('d M Y H:i')
    ]);
    exit;
}

/* ══════════════════════════════════════════════════════════════
   PRINT VIEW
══════════════════════════════════════════════════════════════ */
if (isset($_GET['printview'])) {
    $f_route   = trim($_GET['route']         ?? '');
    $f_sr      = trim($_GET['sr_code']       ?? '');
    $f_date    = trim($_GET['delivery_date'] ?? '');
    $f_as_at   = trim($_GET['as_at_date']    ?? '');
    $f_tbd     = trim($_GET['to_be_delivery'] ?? '');
    $f_amt_min = trim($_GET['amount_min']    ?? '');
    $f_amt_max = trim($_GET['amount_max']    ?? '');

    $pv_where = ["fsd.updated = 1"];
    if ($f_route) $pv_where[] = "(COALESCE(lsid_main.route_code, fs.route) = '" . mysqli_real_escape_string($conn,$f_route) . "')";
    if ($f_sr)    $pv_where[] = "(fs.sr_code = '" . mysqli_real_escape_string($conn,$f_sr) . "' OR lsid_sr.sales_person_code = '" . mysqli_real_escape_string($conn,$f_sr) . "')";
    if ($f_date)  $pv_where[] = "fs.delivery_date = '" . mysqli_real_escape_string($conn,$f_date)  . "'";
    if ($f_as_at) $pv_where[] = "fs.delivery_date <= '". mysqli_real_escape_string($conn,$f_as_at) . "'";
    if ($f_tbd === '1') $pv_where[] = "fsd.to_be_delivery = 1";
    if ($f_tbd === '0') $pv_where[] = "fsd.to_be_delivery = 0";
    $pv_where_sql = implode(' AND ', $pv_where);

    $pv_pay_date_filter = $f_as_at
        ? "WHERE is_reversed = 0 AND payment_date <= '" . mysqli_real_escape_string($conn,$f_as_at) . "'"
        : "WHERE is_reversed = 0";

    $pv_amt_filter = '';
    if ($f_amt_min !== '') $pv_amt_filter .= " AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) >= " . floatval($f_amt_min);
    if ($f_amt_max !== '') $pv_amt_filter .= " AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) <= " . floatval($f_amt_max);

    $pv_sql = "
    SELECT fsd.id AS detail_id,
           COALESCE(lsid_main.route_code, fs.route) AS route_code,
           COALESCE(r2.route_name, r.route_name, COALESCE(lsid_main.route_code, fs.route)) AS route_name,
           COALESCE(lsid_sr.sales_person_code, fs.sr_code) AS sr_code,
           fs.delivery_date,
           DATEDIFF(" . ($f_as_at ? "'" . mysqli_real_escape_string($conn,$f_as_at) . "'" : "CURDATE()") . ", fs.delivery_date) AS aging_days,
           fsd.t_code,
           COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS customer_name,
           fsd.invoice_num,
           COALESCE(fsd.to_be_delivery,0) AS to_be_delivery,
           COALESCE(siid.final_bill_amount, fsd.adjust_net_value) AS net_value,
           COALESCE(pay.total_paid,0)   AS paid,
           COALESCE(pay.cash_paid,0)    AS cash_paid,
           COALESCE(pay.cheque_paid,0)  AS cheque_paid,
           COALESCE(cn.total_cn,0)      AS total_cn,
           (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) AS balance,
           CASE WHEN cr.detail_id IS NOT NULL THEN 1 ELSE 0 END AS is_special
    FROM field_summary_details fsd
    INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
    LEFT  JOIN routes r   ON r.route_code = fs.route
    LEFT  JOIN (
        SELECT bill_no, MIN(route_code) AS route_code
        FROM loading_summary_import_details
        WHERE status IN ('imported', 'cancelled') AND route_code IS NOT NULL AND route_code <> ''
        GROUP BY bill_no
    ) lsid_main ON lsid_main.bill_no = fsd.invoice_num
    LEFT  JOIN routes r2 ON r2.route_code = lsid_main.route_code
    LEFT  JOIN (
        SELECT bill_no, MIN(sales_person_code) AS sales_person_code
        FROM loading_summary_import_details
        WHERE status IN ('imported', 'cancelled') AND sales_person_code IS NOT NULL AND sales_person_code <> ''
        GROUP BY bill_no
    ) lsid_sr ON lsid_sr.bill_no = fsd.invoice_num
    LEFT  JOIN customers c ON c.t_code = fsd.t_code
    LEFT  JOIN (
        SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
        FROM secondary_invoice_import_details GROUP BY bill_no
    ) siid ON siid.bill_no = fsd.invoice_num
    LEFT  JOIN (
        SELECT field_summary_detail_id,
               SUM(amount) AS total_paid,
               SUM(CASE WHEN payment_method='cash'   THEN amount ELSE 0 END) AS cash_paid,
               SUM(CASE WHEN payment_method='cheque' THEN amount ELSE 0 END) AS cheque_paid
        FROM   invoice_payments $pv_pay_date_filter
        GROUP  BY field_summary_detail_id
    ) pay ON pay.field_summary_detail_id = fsd.id
    LEFT  JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_cn
        FROM   credit_notes WHERE is_deleted=0
        GROUP  BY field_summary_detail_id
    ) cn ON cn.field_summary_detail_id = fsd.id
    LEFT  JOIN (SELECT field_summary_detail_id AS detail_id FROM credit_requests GROUP BY field_summary_detail_id) cr ON cr.detail_id = fsd.id
    WHERE $pv_where_sql AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) > 0 $pv_amt_filter
    ORDER BY aging_days DESC, balance DESC, fs.delivery_date ASC, COALESCE(lsid_main.route_code, fs.route), COALESCE(lsid_sr.sales_person_code, fs.sr_code), fsd.invoice_num";

    $pv_res  = mysqli_query($conn, $pv_sql);
    $pv_rows = [];
    $pv_net = $pv_paid = $pv_bal = $pv_cash = $pv_cheque = $pv_cn = 0;
    $pv_special = $pv_normal = 0;
    if ($pv_res) {
        while ($pv_r = mysqli_fetch_assoc($pv_res)) {
            $pv_rows[] = $pv_r;
            $pv_net    += floatval($pv_r['net_value']);
            $pv_paid   += floatval($pv_r['paid']);
            $pv_cash   += floatval($pv_r['cash_paid']);
            $pv_cheque += floatval($pv_r['cheque_paid']);
            $pv_cn     += floatval($pv_r['total_cn']);
            $pv_bal    += floatval($pv_r['balance']);
            if ($pv_r['is_special']) $pv_special++; else $pv_normal++;
        }
    }
    $pv_count = count($pv_rows);
    function pvAgingCls($d){ if($d>=90)return'aging-red'; if($d>=60)return'aging-orange'; if($d>=30)return'aging-yellow'; return'aging-green'; }
    function pvAgingLbl($d){ if($d>=90)return'Critical'; if($d>=60)return'Overdue'; if($d>=30)return'Warning'; return'Fresh'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Credit Bill Summary &mdash; Print</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
@page{size:A4 landscape;margin:8mm 8mm 10mm 8mm;}
body{font-family:Arial,Helvetica,sans-serif;font-size:9px;color:#000;background:#fff;}
.print-btn-bar{display:flex;align-items:center;justify-content:space-between;background:#1e1b4b;color:#fff;padding:10px 18px;gap:10px;position:sticky;top:0;z-index:99;}
.print-btn-bar h1{font-size:14px;font-weight:800;}
.btns{display:flex;gap:8px;}
.pbtn{display:inline-flex;align-items:center;gap:6px;padding:7px 18px;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;font-family:Arial,sans-serif;}
.pbtn-print{background:#6366f1;color:#fff;}.pbtn-print:hover{background:#4f46e5;}
.pbtn-close{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);}.pbtn-close:hover{background:rgba(220,38,38,.8);}
.rpt-header{border-bottom:2.5px solid #1e1b4b;padding:10px 0 8px;margin-bottom:10px;}
.rpt-header-title{font-size:16px;font-weight:900;color:#1e1b4b;margin-bottom:4px;}
.rpt-meta{display:flex;flex-wrap:wrap;gap:0 18px;font-size:8.5px;color:#444;}
.rpt-meta strong{color:#1e1b4b;font-weight:800;}
.rpt-summary{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;}
.rpt-sum-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:5px;padding:4px 12px;font-size:8.5px;display:inline-flex;align-items:center;gap:5px;}
.rpt-sum-box .lbl{font-weight:600;color:#6b7280;font-size:8px;text-transform:uppercase;}
.rpt-sum-box .val{color:#1e1b4b;font-size:10px;font-weight:900;}
.rpt-sum-box.red .val{color:#dc2626;}.rpt-sum-box.green .val{color:#16a34a;}.rpt-sum-box.blue .val{color:#2563eb;}.rpt-sum-box.orange .val{color:#ea580c;}
.as-at-notice{background:#fef3c7;border:1px solid #fde68a;border-radius:5px;padding:4px 12px;font-size:8px;color:#92400e;font-weight:700;display:inline-flex;align-items:center;gap:5px;}
.tbd-notice{background:#ede9fe;border:1px solid #c4b5fd;border-radius:5px;padding:4px 12px;font-size:8px;color:#4c1d95;font-weight:700;display:inline-flex;align-items:center;gap:5px;}
.amt-notice{background:#fef9c3;border:1px solid #fde047;border-radius:5px;padding:4px 12px;font-size:8px;color:#854d0e;font-weight:700;display:inline-flex;align-items:center;gap:5px;}
table{width:100%;border-collapse:collapse;font-size:8.5px;table-layout:fixed;}
thead th{background:#1e1b4b;color:#fff;padding:5px 4px;font-size:8px;font-weight:700;border:1px solid #334155;white-space:nowrap;text-align:left;}
thead th.tc{text-align:center;}thead th.tr{text-align:right;}
tbody tr{border-bottom:1px solid #e5e5e5;page-break-inside:avoid;}
tbody tr.normal-row td{background:#fff;}
tbody tr.special-row td{background:#fefce8;}
td{padding:4px 4px;border:1px solid #e8e8e8;vertical-align:middle;word-break:break-word;color:#111;}
td.tc{text-align:center;}td.tr{text-align:right;}
tfoot tr{page-break-inside:avoid;}
tfoot td{background:#1e1b4b !important;color:#fff !important;padding:6px 4px;font-size:9px;font-weight:900;border:1px solid #334155;}
tfoot td.tr{text-align:right;}
th:nth-child(1){width:18px;}th:nth-child(2){width:42px;}th:nth-child(3){width:34px;}
th:nth-child(4){width:72px;}th:nth-child(5){width:96px;}th:nth-child(6){width:66px;}
th:nth-child(7){width:50px;}th:nth-child(8){width:28px;}th:nth-child(9){width:28px;}
th:nth-child(10){width:54px;}th:nth-child(11){width:48px;}th:nth-child(12){width:48px;}
th:nth-child(13){width:48px;}th:nth-child(14){width:52px;}
.special-badge{color:#854d0e;font-weight:700;font-size:7.5px;}
.tbd-badge-print{color:#4c1d95;font-weight:700;font-size:7.5px;}
.sr-code{color:#5b21b6;font-weight:700;}.t-code{color:#1e40af;font-weight:700;font-family:monospace;}
.inv-num{font-family:monospace;font-weight:700;color:#1e1b4b;font-size:8px;}
.balance{color:#dc2626;font-weight:700;}.paid-val{color:#16a34a;font-weight:700;}.cheque-val{color:#2563eb;font-weight:700;}.cn-val{color:#ea580c;font-weight:700;}
.date-val{font-size:8px;}.aging-val{font-weight:700;font-size:8.5px;}
.aging-green{color:#16a34a;}.aging-yellow{color:#d97706;}.aging-orange{color:#ea580c;}.aging-red{color:#dc2626;}
.rn{color:#9ca3af;font-size:8px;text-align:center;}.route-name{font-size:7.5px;color:#6b7280;}
@media screen{body{background:#e2e8f0;}.page-preview{background:#fff;width:297mm;min-height:210mm;margin:16px auto;padding:8mm;box-shadow:0 4px 24px rgba(0,0,0,.18);border-radius:4px;}}
@media print{
    .print-btn-bar{display:none !important;}
    .page-preview{margin:0 !important;padding:0 !important;box-shadow:none !important;width:auto !important;}
    body{background:#fff !important;}
    tbody tr.special-row td{background:#fefce8 !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    tfoot td{background:#1e1b4b !important;color:#fff !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    thead th{background:#1e1b4b !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}
</style>
</head>
<body>
<div class="print-btn-bar">
    <h1>&#128438; Credit Bill Summary &mdash; Print Preview</h1>
    <div class="btns">
        <button class="pbtn pbtn-print" onclick="window.print()">&#128438;&nbsp;Print / Save PDF</button>
        <button class="pbtn pbtn-close" onclick="window.close()">&#10005;&nbsp;Close</button>
    </div>
</div>
<div class="page-preview">
<div class="rpt-header">
    <div class="rpt-header-title">Credit Bill Summary Report</div>
    <div class="rpt-meta">
        <?php if($f_route): ?><span><strong>Route:</strong> <?php echo htmlspecialchars($f_route); ?></span><?php endif; ?>
        <?php if($f_sr):    ?><span><strong>SR Code:</strong> <?php echo htmlspecialchars($f_sr); ?></span><?php endif; ?>
        <?php if($f_date):  ?><span><strong>Delivery Date:</strong> <?php echo date('d M Y',strtotime($f_date)); ?></span><?php endif; ?>
        <?php if($f_as_at): ?><span><strong>As At:</strong> <?php echo date('d M Y',strtotime($f_as_at)); ?></span><?php endif; ?>
        <?php if($f_tbd === '1'): ?><span><strong>To Be Delivery:</strong> Yes Only</span><?php endif; ?>
        <?php if($f_tbd === '0'): ?><span><strong>To Be Delivery:</strong> No Only</span><?php endif; ?>
        <?php if($f_amt_min !== ''): ?><span><strong>Balance Min:</strong> Rs. <?php echo number_format(floatval($f_amt_min),2); ?></span><?php endif; ?>
        <?php if($f_amt_max !== ''): ?><span><strong>Balance Max:</strong> Rs. <?php echo number_format(floatval($f_amt_max),2); ?></span><?php endif; ?>
        <?php if(!$f_route && !$f_sr && !$f_date && !$f_as_at && $f_tbd === '' && $f_amt_min === '' && $f_amt_max === ''): ?><span><strong>Scope:</strong> All Records</span><?php endif; ?>
        <span><strong>Printed:</strong> <?php echo date('d M Y, H:i'); ?></span>
    </div>
    <div class="rpt-summary">
        <div class="rpt-sum-box"><span class="lbl">Invoices</span><span class="val"><?php echo $pv_count; ?></span></div>
        <div class="rpt-sum-box"><span class="lbl">Special</span><span class="val"><?php echo $pv_special; ?></span></div>
        <div class="rpt-sum-box"><span class="lbl">Normal</span><span class="val"><?php echo $pv_normal; ?></span></div>
        <div class="rpt-sum-box"><span class="lbl">Ikea Value</span><span class="val">Rs.&nbsp;<?php echo number_format($pv_net,2); ?></span></div>
        <div class="rpt-sum-box green"><span class="lbl">Cash Paid</span><span class="val">Rs.&nbsp;<?php echo number_format($pv_cash,2); ?></span></div>
        <div class="rpt-sum-box blue"><span class="lbl">Cheque Paid</span><span class="val">Rs.&nbsp;<?php echo number_format($pv_cheque,2); ?></span></div>
        <?php if($pv_cn > 0): ?>
        <div class="rpt-sum-box orange"><span class="lbl">Credit Notes</span><span class="val">Rs.&nbsp;<?php echo number_format($pv_cn,2); ?></span></div>
        <?php endif; ?>
        <div class="rpt-sum-box red"><span class="lbl">Total Balance</span><span class="val">Rs.&nbsp;<?php echo number_format($pv_bal,2); ?></span></div>
        <?php if($f_as_at): ?>
        <div class="as-at-notice">&#9200; Payments counted up to <?php echo date('d M Y',strtotime($f_as_at)); ?> only</div>
        <?php endif; ?>
        <?php if($f_tbd === '1'): ?>
        <div class="tbd-notice">&#128666; To Be Delivery: Yes Only</div>
        <?php elseif($f_tbd === '0'): ?>
        <div class="tbd-notice">&#128666; To Be Delivery: No Only</div>
        <?php endif; ?>
        <?php if($f_amt_min !== '' || $f_amt_max !== ''): ?>
        <div class="amt-notice">&#128176; Balance Range: <?php echo $f_amt_min !== '' ? 'Rs. '.number_format(floatval($f_amt_min),2) : 'Any'; ?> &mdash; <?php echo $f_amt_max !== '' ? 'Rs. '.number_format(floatval($f_amt_max),2) : 'Any'; ?></div>
        <?php endif; ?>
    </div>
</div>
<table>
    <thead>
        <tr>
            <th class="tc">No</th><th>T Code</th><th class="tc">SR</th><th>Route</th>
            <th>Customer</th><th>Invoice No.</th><th class="tc">Del. Date</th>
            <th class="tc">Aging</th>
            <th class="tc">TBD</th>
            <th class="tr">Ikea Value</th>
            <th class="tr">Cash<?php echo $f_as_at?' (as at)':''; ?></th>
            <th class="tr">Cheque<?php echo $f_as_at?' (as at)':''; ?></th>
            <th class="tr" style="color:#fed7aa;">Credit Notes</th>
            <th class="tr">Balance<?php echo $f_as_at?' (as at)':''; ?></th>
        </tr>
    </thead>
    <tbody>
    <?php $pvn=1; foreach($pv_rows as $pv_r):
        $pvNet    = floatval($pv_r['net_value']);
        $pvCash   = floatval($pv_r['cash_paid']);
        $pvCheque = floatval($pv_r['cheque_paid']);
        $pvCN     = floatval($pv_r['total_cn']);
        $pvBal    = floatval($pv_r['balance']);
        $pvAging  = max(0, intval($pv_r['aging_days']));
        $pvTbd    = intval($pv_r['to_be_delivery'] ?? 0);
        $pvDelf   = $pv_r['delivery_date'] ? date('d M Y', strtotime($pv_r['delivery_date'])) : '&mdash;';
        $pvRowCls = $pv_r['is_special'] ? 'special-row' : 'normal-row';
        $pvAgCls  = pvAgingCls($pvAging);
        $pvAgLbl  = pvAgingLbl($pvAging);
    ?>
    <tr class="<?php echo $pvRowCls; ?>">
        <td class="rn"><?php echo $pvn++; ?></td>
        <td><span class="t-code"><?php echo htmlspecialchars($pv_r['t_code']); ?></span></td>
        <td class="tc"><span class="sr-code"><?php echo htmlspecialchars($pv_r['sr_code']); ?></span></td>
        <td><div style="font-weight:700;font-size:8.5px;"><?php echo htmlspecialchars($pv_r['route_code']); ?></div><div class="route-name"><?php echo htmlspecialchars($pv_r['route_name']); ?></div></td>
        <td style="font-size:8.5px;"><?php echo htmlspecialchars($pv_r['customer_name']); ?><?php if($pv_r['is_special']): ?><br><span class="special-badge">&#9733; Special</span><?php endif; ?></td>
        <td><span class="inv-num"><?php echo htmlspecialchars($pv_r['invoice_num']); ?></span></td>
        <td class="tc date-val"><?php echo $pvDelf; ?></td>
        <td class="tc"><span class="aging-val <?php echo $pvAgCls; ?>" title="<?php echo $pvAgLbl; ?>"><?php echo $pvAging; ?>d</span></td>
        <td class="tc"><?php echo $pvTbd ? '<span class="tbd-badge-print">&#128666; Yes</span>' : '<span style="color:#9ca3af;">No</span>'; ?></td>
        <td class="tr"><?php echo number_format($pvNet,2); ?></td>
        <td class="tr <?php echo $pvCash>0?'paid-val':''; ?>"><?php echo $pvCash>0 ? number_format($pvCash,2) : '&mdash;'; ?></td>
        <td class="tr <?php echo $pvCheque>0?'cheque-val':''; ?>"><?php echo $pvCheque>0 ? number_format($pvCheque,2) : '&mdash;'; ?></td>
        <td class="tr <?php echo $pvCN>0?'cn-val':''; ?>"><?php echo $pvCN>0 ? number_format($pvCN,2) : '&mdash;'; ?></td>
        <td class="tr balance">Rs.&nbsp;<?php echo number_format($pvBal,2); ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="9" style="text-align:right;font-size:8px;letter-spacing:.03em;opacity:.85;">
                TOTAL &mdash; <?php echo $pv_count; ?> invoice<?php echo $pv_count!=1?'s':''; ?>
                &nbsp;(<?php echo $pv_special; ?> special, <?php echo $pv_normal; ?> normal)
                <?php if($f_as_at): ?>&nbsp;&mdash; Payments as at <?php echo date('d M Y',strtotime($f_as_at)); ?><?php endif; ?>
                <?php if($f_tbd === '1'): ?>&nbsp;&mdash; TBD: Yes Only<?php endif; ?>
                <?php if($f_tbd === '0'): ?>&nbsp;&mdash; TBD: No Only<?php endif; ?>
                <?php if($f_amt_min !== '' || $f_amt_max !== ''): ?>&nbsp;&mdash; Balance: <?php echo $f_amt_min !== '' ? 'Min Rs.'.number_format(floatval($f_amt_min),2) : ''; ?><?php echo ($f_amt_min !== '' && $f_amt_max !== '') ? ' – ' : ''; ?><?php echo $f_amt_max !== '' ? 'Max Rs.'.number_format(floatval($f_amt_max),2) : ''; ?><?php endif; ?>
            </td>
            <td class="tr">Rs.&nbsp;<?php echo number_format($pv_net,2); ?></td>
            <td class="tr">Rs.&nbsp;<?php echo number_format($pv_cash,2); ?></td>
            <td class="tr">Rs.&nbsp;<?php echo number_format($pv_cheque,2); ?></td>
            <td class="tr">Rs.&nbsp;<?php echo number_format($pv_cn,2); ?></td>
            <td class="tr">Rs.&nbsp;<?php echo number_format($pv_bal,2); ?></td>
        </tr>
    </tfoot>
</table>
</div>
<script>window.addEventListener('load',function(){window.print();});</script>
</body>
</html>
<?php
    exit;
}

include 'header.php';

/* ── FILTER OPTIONS: Route (union of fs.route + lsid route_code, same as credit_bill_issue.php) ── */
$routes_res = mysqli_query($conn,"
    SELECT DISTINCT
        COALESCE(lsid_r.route_code, fs.route)                       AS route_code,
        COALESCE(r2.route_name, r.route_name, COALESCE(lsid_r.route_code, fs.route)) AS route_name
    FROM field_summary fs
    INNER JOIN field_summary_details fsd ON fsd.field_summary_id = fs.id
    LEFT  JOIN routes r ON r.route_code = fs.route
    LEFT  JOIN (
        SELECT bill_no, MIN(route_code) AS route_code
        FROM loading_summary_import_details
        WHERE status IN ('imported', 'cancelled') AND route_code IS NOT NULL AND route_code <> ''
        GROUP BY bill_no
    ) lsid_r ON lsid_r.bill_no = fsd.invoice_num
    LEFT  JOIN routes r2 ON r2.route_code = lsid_r.route_code
    WHERE fsd.updated = 1
    ORDER BY route_name
");
$all_routes = [];
while ($r = mysqli_fetch_assoc($routes_res)) $all_routes[] = $r;

/* ── SR FILTER DROPDOWN: union of field_summary.sr_code + lsid.sales_person_code (same as credit_bill_issue.php) ── */
$sr_res = mysqli_query($conn,"
    SELECT DISTINCT fs.sr_code AS sr_code
    FROM field_summary fs
    INNER JOIN field_summary_details fsd ON fsd.field_summary_id = fs.id
    WHERE fsd.updated = 1

    UNION

    SELECT DISTINCT lsid.sales_person_code AS sr_code
    FROM loading_summary_import_details lsid
    INNER JOIN field_summary_details fsd ON fsd.invoice_num = lsid.bill_no
    WHERE fsd.updated = 1
      AND lsid.sales_person_code IS NOT NULL
      AND lsid.sales_person_code <> ''
      AND lsid.status IN ('imported', 'cancelled')

    ORDER BY sr_code
");
$all_sr = [];
while ($r = mysqli_fetch_assoc($sr_res)) $all_sr[] = $r['sr_code'];

/* ── READ FILTERS ── */
$f_route   = trim($_GET['route']          ?? '');
$f_sr      = trim($_GET['sr_code']        ?? '');
$f_date    = trim($_GET['delivery_date']  ?? '');
$f_as_at   = trim($_GET['as_at_date']     ?? '');
$f_tbd     = trim($_GET['to_be_delivery'] ?? ''); // '', '1', '0'
$f_amt_min = trim($_GET['amount_min']     ?? '');
$f_amt_max = trim($_GET['amount_max']     ?? '');

/* ── QUERY ── */
$rows = [];
$t_net = $t_paid = $t_balance = $t_cash = $t_cheque = $t_cn = 0;
$t_special = $t_normal = $total_count = 0;

$where = ["fsd.updated = 1"];
if ($f_route) $where[] = "(COALESCE(lsid_main.route_code, fs.route) = '" . mysqli_real_escape_string($conn,$f_route) . "')";
if ($f_sr)    $where[] = "(fs.sr_code = '" . mysqli_real_escape_string($conn,$f_sr) . "' OR lsid_sr.sales_person_code = '" . mysqli_real_escape_string($conn,$f_sr) . "')";
if ($f_date)  $where[] = "fs.delivery_date = '" . mysqli_real_escape_string($conn,$f_date)  . "'";
if ($f_as_at) $where[] = "fs.delivery_date <= '". mysqli_real_escape_string($conn,$f_as_at) . "'";
if ($f_tbd === '1') $where[] = "fsd.to_be_delivery = 1";
if ($f_tbd === '0') $where[] = "fsd.to_be_delivery = 0";
$where_sql = implode(' AND ',$where);

$pay_date_filter = $f_as_at
    ? "WHERE is_reversed = 0 AND payment_date <= '" . mysqli_real_escape_string($conn,$f_as_at) . "'"
    : "WHERE is_reversed = 0";

$aging_base = $f_as_at
    ? "'" . mysqli_real_escape_string($conn,$f_as_at) . "'"
    : "CURDATE()";

/* amount filter clause */
$amt_filter = '';
if ($f_amt_min !== '') $amt_filter .= " AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) >= " . floatval($f_amt_min);
if ($f_amt_max !== '') $amt_filter .= " AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) <= " . floatval($f_amt_max);

/* ensure bill_verified column exists */
$cv = mysqli_query($conn,"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credit_requests' AND COLUMN_NAME='bill_verified' LIMIT 1");
if($cv && mysqli_num_rows($cv)===0) mysqli_query($conn,"ALTER TABLE credit_requests ADD COLUMN `bill_verified` TINYINT(1) NOT NULL DEFAULT 0");

$sql = "
SELECT
    fsd.id                                                                              AS detail_id,
    fs.id                                                                               AS fs_id,
    fs.field_summary_code,
    COALESCE(lsid_main.route_code, fs.route)                                            AS route_code,
    fs.delivery_date,
    DATEDIFF($aging_base, fs.delivery_date)                                            AS aging_days,
    COALESCE(r2.route_name, r.route_name, COALESCE(lsid_main.route_code, fs.route))    AS route_name,
    COALESCE(lsid_sr.sales_person_code, fs.sr_code)                                     AS sr_code,
    fsd.t_code,
    COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code)                    AS customer_name,
    fsd.invoice_num,
    COALESCE(fsd.to_be_delivery, 0)                                                     AS to_be_delivery,
    COALESCE(siid.final_bill_amount, fsd.adjust_net_value)                              AS net_value,
    COALESCE(pay.total_paid,  0)                                                        AS paid,
    COALESCE(pay.cash_paid,   0)                                                        AS cash_paid,
    COALESCE(pay.cheque_paid, 0)                                                        AS cheque_paid,
    COALESCE(cn.total_cn,     0)                                                        AS total_cn,
    (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) AS balance,
    CASE WHEN cr.detail_id IS NOT NULL THEN 1 ELSE 0 END                               AS is_special,
    COALESCE(cr.cr_count, 0)                                                            AS cr_count,
    COALESCE(cr.verified_count, 0)                                                      AS verified_count,
    COALESCE(cr.cr_count,0) - COALESCE(cr.verified_count,0)                            AS unverified_count,
    COALESCE(bi.bill_count, 0)                                                          AS bill_count,
    fsd.bill_verified                                                                   AS bill_verified,
    rm.remark                                                                           AS last_remark,
    rm.created_fmt                                                                      AS last_remark_fmt
FROM field_summary_details fsd
INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
LEFT  JOIN routes    r   ON r.route_code  = fs.route
LEFT  JOIN (
    SELECT bill_no, MIN(route_code) AS route_code
    FROM loading_summary_import_details
    WHERE status IN ('imported', 'cancelled') AND route_code IS NOT NULL AND route_code <> ''
    GROUP BY bill_no
) lsid_main ON lsid_main.bill_no = fsd.invoice_num
LEFT  JOIN routes r2 ON r2.route_code = lsid_main.route_code
LEFT  JOIN (
    SELECT bill_no, MIN(sales_person_code) AS sales_person_code
    FROM loading_summary_import_details
    WHERE status IN ('imported', 'cancelled') AND sales_person_code IS NOT NULL AND sales_person_code <> ''
    GROUP BY bill_no
) lsid_sr ON lsid_sr.bill_no = fsd.invoice_num
LEFT  JOIN customers c   ON c.t_code      = fsd.t_code
LEFT  JOIN (
    SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
    FROM secondary_invoice_import_details GROUP BY bill_no
) siid ON siid.bill_no = fsd.invoice_num
LEFT  JOIN (
    SELECT field_summary_detail_id,
           SUM(amount) AS total_paid,
           SUM(CASE WHEN payment_method='cash'   THEN amount ELSE 0 END) AS cash_paid,
           SUM(CASE WHEN payment_method='cheque' THEN amount ELSE 0 END) AS cheque_paid
    FROM   invoice_payments $pay_date_filter
    GROUP  BY field_summary_detail_id
) pay ON pay.field_summary_detail_id = fsd.id
LEFT  JOIN (
    SELECT field_summary_detail_id, SUM(amount) AS total_cn
    FROM   credit_notes WHERE is_deleted=0
    GROUP  BY field_summary_detail_id
) cn ON cn.field_summary_detail_id = fsd.id
LEFT  JOIN (
    SELECT field_summary_detail_id AS detail_id,
           COUNT(*) AS cr_count,
           SUM(COALESCE(bill_verified,0)) AS verified_count
    FROM   credit_requests
    GROUP  BY field_summary_detail_id
) cr ON cr.detail_id = fsd.id
LEFT  JOIN (
    SELECT field_summary_detail_id, COUNT(*) AS bill_count
    FROM   credit_bill_images GROUP BY field_summary_detail_id
) bi ON bi.field_summary_detail_id = fsd.id
LEFT  JOIN (
    SELECT cbr1.field_summary_detail_id, cbr1.remark,
           DATE_FORMAT(cbr1.created_at,'%d %b %Y %H:%i') AS created_fmt
    FROM   credit_bill_remarks cbr1
    INNER JOIN (
        SELECT field_summary_detail_id, MAX(id) AS max_id
        FROM credit_bill_remarks
        GROUP BY field_summary_detail_id
    ) cbr2 ON cbr2.field_summary_detail_id = cbr1.field_summary_detail_id AND cbr2.max_id = cbr1.id
) rm ON rm.field_summary_detail_id = fsd.id
WHERE $where_sql
  AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) > 0
  $amt_filter
ORDER BY aging_days DESC, balance DESC, fs.delivery_date ASC, COALESCE(lsid_main.route_code, fs.route), COALESCE(lsid_sr.sales_person_code, fs.sr_code), fsd.invoice_num
";

$result = mysqli_query($conn, $sql);
if (!$result) {
    $sql2 = str_replace(
        "LEFT  JOIN (\n    SELECT field_summary_detail_id, COUNT(*) AS bill_count\n    FROM   credit_bill_images GROUP BY field_summary_detail_id\n) bi ON bi.field_summary_detail_id = fsd.id",
        "-- credit_bill_images not yet created", $sql);
    $sql2 = str_replace("    COALESCE(bi.bill_count, 0)                                                          AS bill_count","    0 AS bill_count",$sql2);
    $result = mysqli_query($conn, $sql2);
}
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[]    = $row;
        $t_net     += floatval($row['net_value']);
        $t_paid    += floatval($row['paid']);
        $t_cash    += floatval($row['cash_paid']);
        $t_cheque  += floatval($row['cheque_paid']);
        $t_cn      += floatval($row['total_cn']);
        $t_balance += floatval($row['balance']);
        if ($row['is_special']) $t_special++; else $t_normal++;
    }
}
$total_count = count($rows);
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
*{box-sizing:border-box;}
.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:18px 20px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.filter-title{font-size:13px;font-weight:700;color:#374151;margin-bottom:14px;display:flex;align-items:center;gap:6px;}
.filter-grid{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;}
.filter-inputs{display:grid;grid-template-columns:1fr 1fr 150px 150px 120px 120px 150px;gap:12px;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.fg input,.fg select{border:1px solid #e5e5e5;border-radius:7px;padding:8px 11px;font-size:13px;font-family:'Inter',sans-serif;color:#1f2937;width:100%;transition:border .2s;}
.fg input:focus,.fg select:focus{outline:none;border-color:#6366f1;}

.search-bar-wrap{display:flex;align-items:center;gap:10px;background:#fff;border:1.5px solid #6366f1;border-radius:10px;padding:8px 14px;margin-bottom:16px;box-shadow:0 2px 10px rgba(99,102,241,.1);}
.search-bar-wrap i{color:#6366f1;font-size:15px;flex-shrink:0;}
#liveSearch{border:none;outline:none;flex:1;font-size:14px;font-family:'Inter',sans-serif;color:#1f2937;background:transparent;}
#liveSearch::placeholder{color:#9ca3af;}
#searchClear{background:none;border:none;color:#9ca3af;cursor:pointer;font-size:14px;padding:0;line-height:1;display:none;}
#searchClear:hover{color:#dc2626;}
#searchMatchCount{font-size:11px;font-weight:700;color:#6366f1;white-space:nowrap;flex-shrink:0;background:#ede9fe;padding:2px 10px;border-radius:20px;}

.as-at-banner{display:flex;align-items:center;gap:8px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:8px 14px;margin-bottom:14px;font-size:12px;font-weight:600;color:#92400e;}
.tbd-banner{display:flex;align-items:center;gap:8px;background:#ede9fe;border:1px solid #c4b5fd;border-radius:8px;padding:8px 14px;margin-bottom:14px;font-size:12px;font-weight:600;color:#4c1d95;}
.amt-banner{display:flex;align-items:center;gap:8px;background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:8px 14px;margin-bottom:14px;font-size:12px;font-weight:600;color:#854d0e;}

.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;text-decoration:none;transition:all .2s;white-space:nowrap;}
.btn-primary{background:#6366f1;color:#fff;}.btn-primary:hover{background:#4f46e5;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-success{background:#16a34a;color:#fff;}.btn-success:hover{background:#15803d;}
.btn-bill{background:#0f172a;color:#fff;padding:5px 10px;font-size:11px;border-radius:6px;gap:4px;}.btn-bill:hover{background:#1e293b;}
.btn-history{background:#0e7490;color:#fff;padding:5px 10px;font-size:11px;border-radius:6px;gap:4px;}.btn-history:hover{background:#0c6080;}
.btn-cn{background:#c2410c;color:#fff;padding:5px 10px;font-size:11px;border-radius:6px;gap:4px;}.btn-cn:hover{background:#9a3412;}
.btn-cn.has-notes{background:#ea580c;box-shadow:0 0 0 2px rgba(234,88,12,.3);}
.btn-sm{padding:5px 11px;font-size:11px;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}

/* STAT CARDS */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:14px;margin-bottom:20px;}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px;}
.stat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.stat-value{font-size:18px;font-weight:800;color:#1f2937;}
.stat-value.red{color:#dc2626;}.stat-value.green{color:#16a34a;}.stat-value.blue{color:#2563eb;}.stat-value.amber{color:#d97706;}.stat-value.orange{color:#ea580c;}.stat-value.violet{color:#7c3aed;}

.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;}
.pill-violet{background:#ede9fe;color:#5b21b6;}.pill-blue{background:#dbeafe;color:#1e40af;}.pill-green{background:#dcfce7;color:#166534;}
.legend{display:flex;align-items:center;gap:14px;font-size:11px;font-weight:600;color:#6b7280;}
.legend-item{display:flex;align-items:center;gap:5px;}
.ldot{width:11px;height:11px;border-radius:3px;flex-shrink:0;}
.ldot-y{background:#fef08a;border:1px solid #facc15;}.ldot-w{background:#fff;border:1px solid #d1d5db;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead th{padding:9px 8px;text-align:left;font-weight:700;font-size:11px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);}
.data-table thead th:last-child{border-right:none;}
.data-table thead th.tr{text-align:right;}.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr.normal-row td{background:#fff;}.data-table tbody tr.normal-row:hover td{background:#f9fafb;}
.data-table tbody tr.special-row td{background:#fefce8;}.data-table tbody tr.special-row:hover td{background:#fef9c3;}
.data-table tbody tr.row-hidden{display:none !important;}
.data-table tbody td mark{background:#fef08a;color:#111;border-radius:2px;padding:0 1px;}
.data-table tfoot td{padding:10px 8px;font-weight:800;font-size:13px;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155;}
.data-table tfoot td.tr{text-align:right;}
.no-results-row{display:none;}
.no-results-row td{text-align:center;padding:30px;color:#9ca3af;font-size:13px;font-style:italic;}
.sr-pill{background:#ede9fe;color:#5b21b6;padding:2px 7px;border-radius:9px;font-size:11px;font-weight:700;}
.special-badge{background:#fef08a;color:#854d0e;border:1px solid #fde047;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.tbd-badge{background:#ede9fe;color:#4c1d95;border:1px solid #c4b5fd;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.balance-amt{font-weight:700;color:#dc2626;}
.verified-badge{background:#dcfce7;color:#166534;border:1px solid #86efac;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.unverified-badge{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.bill-count-badge{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.date-badge{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;white-space:nowrap;}
.cn-badge{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;white-space:nowrap;}
.remark-cell{max-width:220px;}
.remark-cell .rc-text{font-size:11.5px;color:#374151;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4;}
.remark-cell .rc-date{font-size:9.5px;color:#9ca3af;margin-top:2px;}
.remark-cell .rc-empty{color:#d1d5db;font-size:11px;font-style:italic;}

.aging-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;border:1px solid;}
.aging-green {background:#f0fdf4;color:#16a34a;border-color:#bbf7d0;}
.aging-yellow{background:#fefce8;color:#d97706;border-color:#fde68a;}
.aging-orange{background:#fff7ed;color:#ea580c;border-color:#fed7aa;}
.aging-red   {background:#fef2f2;color:#dc2626;border-color:#fecaca;}

.inv-link{font-family:monospace;font-size:12px;font-weight:700;color:#4338ca;cursor:pointer;text-decoration:none;border-bottom:1px dashed #a5b4fc;padding-bottom:1px;transition:all .2s;}
.inv-link:hover{color:#6366f1;border-bottom-color:#6366f1;background:#ede9fe;border-radius:3px;padding:1px 4px;margin:-1px -4px;}

.bal-breakdown{font-size:9.5px;color:#ea580c;font-weight:600;margin-top:2px;display:flex;align-items:center;gap:3px;}
.actions-row{display:flex;align-items:center;gap:4px;justify-content:center;flex-wrap:nowrap;}

/* ═══════════════════════════════════════
   PAYMENT HISTORY MODAL
═══════════════════════════════════════ */
#payHistoryModal{display:none;position:fixed;inset:0;z-index:99998;background:rgba(10,14,26,.85);align-items:center;justify-content:center;padding:20px;}
#payHistoryModal.open{display:flex;}
.ph-modal{background:#fff;border-radius:14px;width:100%;max-width:720px;max-height:85vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.4);animation:phSlideUp .25s ease-out;}
@keyframes phSlideUp{from{opacity:0;transform:translateY(30px);}to{opacity:1;transform:translateY(0);}}
.ph-header{background:linear-gradient(135deg,#1e1b4b,#312e81);color:#fff;padding:16px 20px;display:flex;align-items:center;gap:12px;border-bottom:2px solid #4338ca;flex-shrink:0;}
.ph-header-icon{width:40px;height:40px;background:rgba(255,255,255,.12);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.ph-header-text{flex:1;}.ph-header-text h3{margin:0;font-size:15px;font-weight:800;}.ph-header-text p{margin:2px 0 0;font-size:11px;color:#c7d2fe;font-weight:500;}
.ph-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:34px;height:34px;border-radius:8px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:background .2s;}
.ph-close:hover{background:rgba(220,38,38,.8);}
.ph-body{padding:18px 20px;overflow-y:auto;flex:1;}
.ph-summary{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:18px;}
.ph-sum-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;text-align:center;}
.ph-sum-card .ph-sum-label{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.ph-sum-card .ph-sum-val{font-size:16px;font-weight:800;}
.ph-sum-val.text-net{color:#1f2937;}.ph-sum-val.text-paid{color:#16a34a;}.ph-sum-val.text-cn{color:#ea580c;}.ph-sum-val.text-bal{color:#dc2626;}
.ph-as-at-note{display:flex;align-items:center;gap:7px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:7px 12px;margin-bottom:14px;font-size:11px;font-weight:600;color:#92400e;}
.ph-table{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:4px;}
.ph-table thead th{padding:9px 10px;text-align:left;font-weight:700;font-size:11px;color:#64748b;background:#f1f5f9;border-bottom:2px solid #e2e8f0;text-transform:uppercase;letter-spacing:.04em;}
.ph-table thead th.tr{text-align:right;}.ph-table thead th.tc{text-align:center;}
.ph-table tbody td{padding:10px 10px;border-bottom:1px solid #f1f5f9;color:#374151;}
.ph-table tbody tr:hover td{background:#f8fafc;}
.ph-table tbody td.tr{text-align:right;}.ph-table tbody td.tc{text-align:center;}
.ph-table tfoot td{padding:10px 10px;font-weight:800;font-size:13px;background:#f8fafc;border-top:2px solid #e2e8f0;color:#1f2937;}
.ph-table tfoot td.tr{text-align:right;}
.ph-pay-method{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:9px;font-size:10px;font-weight:700;}
.ph-method-cash{background:#dcfce7;color:#166534;}.ph-method-cheque{background:#dbeafe;color:#1e40af;}.ph-method-bank{background:#fef3c7;color:#92400e;}.ph-method-other{background:#f3f4f6;color:#374151;}
.ph-cheque-status{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.ph-status-pending{background:#fef3c7;color:#92400e;}.ph-status-cleared{background:#dcfce7;color:#166534;}.ph-status-bounced{background:#fef2f2;color:#dc2626;}
.ph-empty{text-align:center;padding:40px 20px;color:#94a3b8;}
.ph-empty i{font-size:36px;display:block;margin-bottom:10px;opacity:.4;}
.ph-loading{text-align:center;padding:40px;color:#6366f1;font-size:14px;}

/* ═══════════════════════════════════════
   CREDIT NOTE MODAL
═══════════════════════════════════════ */
#cnModal{display:none;position:fixed;inset:0;z-index:99999;background:rgba(10,14,26,.88);align-items:center;justify-content:center;padding:20px;}
#cnModal.open{display:flex;}
.cn-modal{background:#fff;border-radius:14px;width:100%;max-width:640px;max-height:88vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.45);animation:phSlideUp .25s ease-out;}
.cn-header{background:linear-gradient(135deg,#7c2d12,#c2410c);color:#fff;padding:16px 20px;display:flex;align-items:center;gap:12px;border-bottom:2px solid #ea580c;flex-shrink:0;}
.cn-header-icon{width:40px;height:40px;background:rgba(255,255,255,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.cn-header-text{flex:1;}.cn-header-text h3{margin:0;font-size:15px;font-weight:800;}.cn-header-text p{margin:2px 0 0;font-size:11px;color:#fed7aa;font-weight:500;}
.cn-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:34px;height:34px;border-radius:8px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:background .2s;}
.cn-close:hover{background:rgba(220,38,38,.8);}
.cn-body{padding:20px;overflow-y:auto;flex:1;}
.cn-summary{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:18px;}
.cn-sum-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;text-align:center;}
.cn-sum-card .cn-sum-label{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.cn-sum-card .cn-sum-val{font-size:16px;font-weight:800;}
.cn-sum-val.cn-net{color:#1f2937;}.cn-sum-val.cn-paid{color:#16a34a;}.cn-sum-val.cn-cn{color:#ea580c;}.cn-sum-val.cn-bal{color:#dc2626;}
.cn-list{margin-bottom:18px;}
.cn-list-title{font-size:12px;font-weight:700;color:#374151;margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.cn-item{background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:12px 14px;margin-bottom:8px;display:flex;align-items:flex-start;gap:12px;}
.cn-item-icon{width:36px;height:36px;background:#ea580c;border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;flex-shrink:0;}
.cn-item-body{flex:1;}
.cn-item-top{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;}
.cn-item-amount{font-size:15px;font-weight:800;color:#c2410c;}
.cn-item-date{font-size:11px;font-weight:600;color:#92400e;background:#fef3c7;padding:2px 8px;border-radius:12px;}
.cn-item-reason{font-size:12px;color:#78350f;font-style:italic;}
.cn-item-created{font-size:10px;color:#d97706;margin-top:3px;}
.cn-item-del{background:none;border:none;color:#d97706;cursor:pointer;padding:4px;border-radius:6px;transition:all .2s;flex-shrink:0;align-self:center;}
.cn-item-del:hover{background:#fef2f2;color:#dc2626;}
.cn-empty{text-align:center;padding:28px 20px;color:#94a3b8;border:2px dashed #e5e7eb;border-radius:10px;margin-bottom:18px;}
.cn-empty i{font-size:32px;display:block;margin-bottom:8px;opacity:.4;}
.cn-add-box{background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px 18px;}
.cn-add-title{font-size:12px;font-weight:700;color:#374151;margin-bottom:14px;display:flex;align-items:center;gap:7px;text-transform:uppercase;letter-spacing:.04em;}
.cn-form-row{display:grid;grid-template-columns:140px 1fr 150px;gap:10px;margin-bottom:10px;}
.cn-fg{display:flex;flex-direction:column;gap:5px;}
.cn-fg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;}
.cn-fg input,.cn-fg textarea{border:1px solid #d1d5db;border-radius:7px;padding:8px 11px;font-size:13px;font-family:'Inter',sans-serif;color:#1f2937;width:100%;transition:border .2s;background:#fff;}
.cn-fg input:focus,.cn-fg textarea:focus{outline:none;border-color:#ea580c;box-shadow:0 0 0 3px rgba(234,88,12,.1);}
.cn-fg textarea{resize:vertical;min-height:38px;}
.btn-add-cn{background:#c2410c;color:#fff;border:none;border-radius:7px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s;width:100%;justify-content:center;}
.btn-add-cn:hover{background:#9a3412;}
.btn-add-cn:disabled{opacity:.6;cursor:not-allowed;}

/* ═══════════════════════════════════════
   FULLSCREEN BILL MODAL
═══════════════════════════════════════ */
#billModal{display:none;position:fixed !important;inset:0 !important;z-index:100000 !important;background:#000 !important;overflow:hidden !important;flex-direction:column;}
#billModal.open{display:flex !important;}
body.modal-open{overflow:hidden !important;}
.bill-modal{background:#1e2535;width:100%;flex:1;display:flex;flex-direction:column;overflow:hidden;min-height:0;}
#billModalClose{position:fixed;top:14px;right:16px;z-index:100001;background:#dc2626;border:none;color:#fff;width:36px;height:36px;border-radius:50%;cursor:pointer;font-size:18px;display:none;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(220,38,38,.6);line-height:1;}
#billModalClose.show{display:flex !important;}
#billModalClose:hover{background:#b91c1c;transform:scale(1.08);}

.bm-header{height:46px;flex-shrink:0;background:#0a0a0a;border-bottom:1px solid #1f1f1f;display:flex;align-items:center;padding:0 56px 0 16px;gap:10px;}
.bm-title{font-size:13px;font-weight:800;color:#e2e8f0;letter-spacing:.04em;}
.bm-subtitle{font-size:11px;color:#444;margin-left:2px;}
#bm-badges{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-left:6px;}

.bm-body{flex:1;min-height:0;display:grid;grid-template-columns:20% 45% 35%;height:calc(100vh - 46px);overflow:hidden;}

.bm-left{background:#0d0d0d;border-right:1px solid #1f1f1f;display:flex;flex-direction:column;overflow:hidden;}
.bm-info-block{flex-shrink:0;padding:14px 16px 10px;border-bottom:1px solid #1f1f1f;display:flex;flex-direction:column;gap:10px;}
.bm-info-row{display:flex;flex-direction:column;gap:2px;}
.bm-info-row .lbl{font-size:9.5px;font-weight:700;color:#3a3a3a;text-transform:uppercase;letter-spacing:.07em;}
.bm-info-row .val{font-size:13px;font-weight:700;color:#c8c8c8;line-height:1.3;}
.bm-info-row .val.mono{font-family:monospace;color:#818cf8;font-size:13px;}
.bm-info-row .val.amber{color:#fbbf24;}
.bm-info-row .val.green{color:#4ade80;}
.bm-info-row .val.red{color:#f87171;}
.bm-other-inv{flex-shrink:0;padding:10px 16px 12px;border-bottom:1px solid #1f1f1f;}
.bm-other-inv-title{font-size:9.5px;font-weight:700;color:#3a3a3a;text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px;display:flex;align-items:center;gap:5px;}
.bm-other-inv-item{display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px solid #161616;font-size:11.5px;}
.bm-other-inv-item:last-child{border-bottom:none;}
.bm-other-inv-item .inv-no{font-family:monospace;color:#818cf8;font-weight:700;}
.bm-other-inv-item .inv-bal{color:#f87171;font-weight:700;}
.bm-other-total{margin-top:8px;padding-top:7px;border-top:1px solid #2a2a2a;display:flex;flex-direction:column;gap:1px;}
.bm-other-total .ot-label{font-size:9.5px;color:#3a3a3a;font-weight:700;text-transform:uppercase;letter-spacing:.05em;}
.bm-other-total .ot-val{font-size:14px;font-weight:800;color:#fbbf24;}
.bm-upload-area{flex-shrink:0;padding:10px 14px;border-bottom:1px solid #1f1f1f;display:flex;flex-direction:column;gap:8px;}
.btn-upload-pill{width:100%;display:flex;align-items:center;justify-content:center;gap:7px;background:#111;color:#aaa;border:1px solid #333;border-radius:7px;padding:10px 14px;font-size:13px;font-weight:700;cursor:pointer;transition:all .2s;font-family:inherit;}
.btn-upload-pill:hover{background:#1e1e2e;border-color:#6366f1;color:#818cf8;}
.bm-thumbs{display:flex;gap:6px;flex-wrap:wrap;}
.bm-thumb{width:52px;height:52px;flex-shrink:0;border-radius:6px;overflow:hidden;cursor:pointer;border:2px solid #222;transition:border-color .2s;position:relative;}
.bm-thumb.active{border-color:#6366f1;}
.bm-thumb img{width:100%;height:100%;object-fit:cover;}
.bm-thumb-del{position:absolute;top:2px;right:2px;background:rgba(220,38,38,.9);color:#fff;border:none;border-radius:3px;width:15px;height:15px;cursor:pointer;font-size:8px;display:flex;align-items:center;justify-content:center;}
#uploadProgress{display:none;align-items:center;gap:6px;font-size:11px;color:#6366f1;padding:4px 0;}
.bm-verify-row{flex-shrink:0;margin-top:auto;padding:10px 14px;background:#0a0a0a;border-top:1px solid #1f1f1f;display:flex;flex-direction:column;gap:7px;}
#cvStatusDisplay{font-size:12px;font-weight:700;display:flex;align-items:center;gap:5px;}
.bm-verify-status.is-verified{color:#4ade80;}
.bm-verify-status.is-unverified{color:#f87171;}
.bm-verify-status.is-pending{color:#444;}
.bm-verify-btns{display:grid;grid-template-columns:1fr 1fr;gap:7px;}
.verify-btn{padding:9px 10px;border:1.5px solid;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:5px;transition:all .2s;}
.verify-btn.btn-verify{background:#071a0f;color:#86efac;border-color:#166534;}
.verify-btn.btn-unverify{background:#1a0707;color:#fca5a5;border-color:#991b1b;}
.verify-btn.btn-verify.active-v,.verify-btn.btn-verify:hover{background:#16a34a !important;color:#fff !important;border-color:#16a34a !important;}
.verify-btn.btn-unverify.active-u,.verify-btn.btn-unverify:hover{background:#dc2626 !important;color:#fff !important;border-color:#dc2626 !important;}

.bm-middle{background:repeating-conic-gradient(#141414 0% 25%,#0d0d0d 0% 50%) 0 0/28px 28px;display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative;}
.bm-middle img#billMainImg{max-width:100%;max-height:100%;object-fit:contain;display:block;cursor:zoom-in;filter:drop-shadow(0 4px 24px rgba(0,0,0,.9));}
.bm-middle .no-bill-msg{color:#2a2a2a;text-align:center;padding:40px 20px;}
.bm-middle .no-bill-msg i{font-size:60px;display:block;margin-bottom:12px;opacity:.25;}
.bm-middle .no-bill-msg span{font-size:13px;color:#333;}

.bm-right{background:#0d0d0d;border-left:1px solid #1f1f1f;display:flex;flex-direction:column;overflow:hidden;}
.bm-right-header{flex-shrink:0;padding:12px 14px 10px;border-bottom:1px solid #1f1f1f;display:flex;align-items:center;justify-content:space-between;}
.bm-right-title{font-size:13px;font-weight:800;color:#e2e8f0;letter-spacing:.02em;}
.bm-cr-count-badge{background:#dc2626;color:#fff;border-radius:20px;padding:2px 10px;font-size:10.5px;font-weight:700;display:inline-flex;align-items:center;gap:4px;}
.bm-right-body{flex:1;min-height:0;overflow-y:auto;display:flex;flex-direction:column;padding:10px 12px;gap:8px;}
.bm-emg-doc{flex-shrink:0;background:#111;border:1px solid #222;border-radius:8px;overflow:hidden;cursor:pointer;transition:border-color .2s;}
.bm-emg-doc:hover{border-color:#555;}
.bm-emg-doc img{width:100%;display:block;object-fit:contain;max-height:520px;}
.bm-emg-doc-empty{min-height:260px;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#222;gap:8px;font-size:12px;}
.bm-small-docs{display:grid;grid-template-columns:1fr 1fr;gap:7px;flex-shrink:0;}
.bm-sig-seal{display:grid;grid-template-columns:1fr 1fr;gap:7px;flex-shrink:0;}
.bm-sig-card{background:#111;border:1px solid #222;border-radius:6px;overflow:hidden;}
.bm-sig-card .zoom-wrap{height:auto !important;cursor:default !important;}
.bm-sig-card .zoom-wrap img.zoomable{max-width:100%;width:100%;height:auto;transform:none !important;pointer-events:none;padding:8px;}
.bm-sig-card-empty{height:100px;}
.bm-sig-label{font-size:9px;font-weight:700;color:#444;text-transform:uppercase;letter-spacing:.06em;padding:4px 7px;border-top:1px solid #1e1e1e;background:#0d0d0d;text-align:center;}

.zoom-wrap{position:relative;overflow:hidden;width:100%;background:#0d0d0d;cursor:zoom-in;display:flex;align-items:center;justify-content:center;}
.zoom-wrap.panning{cursor:grabbing;}
.zoom-wrap img.zoomable{display:block;max-width:100%;width:auto;height:auto;object-fit:contain;transform-origin:0 0;transition:transform .04s ease;user-select:none;pointer-events:none;padding:6px;}
.zoom-hint{position:absolute;bottom:4px;right:5px;font-size:9px;color:#333;background:rgba(0,0,0,.7);padding:2px 4px;border-radius:3px;pointer-events:none;}
.bm-loading{display:flex;align-items:center;justify-content:center;flex:1;color:#444;font-size:13px;gap:10px;}

.lightbox{display:none;position:fixed !important;inset:0 !important;z-index:2147483647 !important;background:rgba(0,0,0,.97) !important;align-items:center;justify-content:center;}
.lightbox.open{display:flex !important;}
.lightbox img{max-width:90vw;max-height:90vh;border-radius:8px;object-fit:contain;box-shadow:0 8px 40px rgba(0,0,0,.6);}
.lightbox-close{position:fixed !important;top:16px;right:20px;color:#fff;font-size:28px;cursor:pointer;background:rgba(220,38,38,.9);border:none;width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;z-index:2147483647 !important;line-height:1;}

.state-box{text-align:center;padding:70px 20px;color:#9ca3af;}
.state-box i{font-size:44px;display:block;margin-bottom:14px;opacity:.35;}

.select2-container .select2-selection--single{height:37px !important;border:1px solid #e5e5e5 !important;border-radius:7px !important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:35px !important;padding-left:11px !important;color:#1f2937;font-size:13px;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:35px !important;}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:#6366f1 !important;}
.select2-dropdown{border:1px solid #e5e5e5 !important;border-radius:7px !important;box-shadow:0 4px 20px rgba(0,0,0,.1) !important;font-size:13px;}
.select2-results__option--highlighted{background:#6366f1 !important;}

@media(max-width:1100px){.filter-inputs{grid-template-columns:1fr 1fr 1fr;}}
@media(max-width:900px){
    .filter-inputs{grid-template-columns:1fr 1fr;}
    .stat-grid{grid-template-columns:repeat(2,1fr);}
    .ph-summary{grid-template-columns:1fr 1fr;}
    .cn-summary{grid-template-columns:1fr 1fr;}
    .cn-form-row{grid-template-columns:1fr;}
    .bm-body{grid-template-columns:1fr;overflow-y:auto;}
    .actions-row{flex-wrap:wrap;}
}

/* ═══════════════════════════════════════
   ROW ICON BUTTONS (Remark / Notes)
═══════════════════════════════════════ */
.rn-icon-btn{width:26px;height:26px;border:1px solid #e5e5e7;border-radius:6px;background:#f9fafb;color:#6b7280;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:11px;transition:all .15s;flex-shrink:0;}
.rn-icon-btn:hover{background:#eef2ff;color:#4338ca;border-color:#c7d2fe;}
.rn-remark-btn.has-remarks{background:#ecfeff;color:#0e7490;border-color:#a5f3fc;}
.rn-notes-btn.has-notes{background:#fefce8;color:#a16207;border-color:#fde68a;}

/* ═══════════════════════════════════════
   REMARK MODAL
═══════════════════════════════════════ */
#remarkModal{display:none;position:fixed;inset:0;z-index:99999;background:rgba(10,14,26,.88);align-items:center;justify-content:center;padding:20px;}
#remarkModal.open{display:flex;}
.rm-modal{background:#fff;border-radius:14px;width:100%;max-width:600px;max-height:88vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.45);animation:phSlideUp .25s ease-out;}
.rm-header{background:linear-gradient(135deg,#0e7490,#0891b2);color:#fff;padding:16px 20px;display:flex;align-items:center;gap:12px;border-bottom:2px solid #06b6d4;flex-shrink:0;}
.rm-header-icon{width:40px;height:40px;background:rgba(255,255,255,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.rm-header-text{flex:1;}.rm-header-text h3{margin:0;font-size:15px;font-weight:800;}.rm-header-text p{margin:2px 0 0;font-size:11px;color:#cffafe;font-weight:500;}
.rm-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:34px;height:34px;border-radius:8px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:background .2s;}
.rm-close:hover{background:rgba(220,38,38,.8);}
.rm-body{padding:20px;overflow-y:auto;flex:1;}
.rm-current-box{background:#ecfeff;border:1.5px solid #a5f3fc;border-radius:10px;padding:14px 16px;margin-bottom:16px;}
.rm-current-label{font-size:10px;font-weight:700;color:#0e7490;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
.rm-current-text{font-size:13.5px;color:#164e63;font-weight:600;line-height:1.5;white-space:pre-wrap;}
.rm-current-date{font-size:10.5px;color:#0e7490;margin-top:6px;}
.rm-add-box{background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px 18px;margin-bottom:18px;}
.rm-add-title{font-size:12px;font-weight:700;color:#374151;margin-bottom:10px;display:flex;align-items:center;gap:7px;text-transform:uppercase;letter-spacing:.04em;}
.rm-add-box textarea{border:1px solid #d1d5db;border-radius:7px;padding:9px 12px;font-size:13px;font-family:'Inter',sans-serif;color:#1f2937;width:100%;resize:vertical;min-height:60px;margin-bottom:10px;background:#fff;}
.rm-add-box textarea:focus{outline:none;border-color:#0e7490;box-shadow:0 0 0 3px rgba(14,116,144,.1);}
.btn-add-rm{background:#0e7490;color:#fff;border:none;border-radius:7px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s;width:100%;justify-content:center;}
.btn-add-rm:hover{background:#0c5f76;}
.btn-add-rm:disabled{opacity:.6;cursor:not-allowed;}
.rm-history-title{font-size:12px;font-weight:700;color:#374151;margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.rm-history-item{background:#f9fafb;border:1px solid #e5e7eb;border-radius:9px;padding:10px 13px;margin-bottom:8px;}
.rm-history-text{font-size:12.5px;color:#374151;line-height:1.5;white-space:pre-wrap;}
.rm-history-date{font-size:10px;color:#9ca3af;margin-top:5px;}
.rm-empty{text-align:center;padding:28px 20px;color:#94a3b8;border:2px dashed #e5e7eb;border-radius:10px;}
.rm-empty i{font-size:32px;display:block;margin-bottom:8px;opacity:.4;}

/* ═══════════════════════════════════════
   NOTES MODAL
═══════════════════════════════════════ */
#notesModal{display:none;position:fixed;inset:0;z-index:99999;background:rgba(10,14,26,.88);align-items:center;justify-content:center;padding:20px;}
#notesModal.open{display:flex;}
.nt-modal{background:#fff;border-radius:14px;width:100%;max-width:640px;max-height:88vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.45);animation:phSlideUp .25s ease-out;}
.nt-header{background:linear-gradient(135deg,#78350f,#a16207);color:#fff;padding:16px 20px;display:flex;align-items:center;gap:12px;border-bottom:2px solid #d97706;flex-shrink:0;}
.nt-header-icon{width:40px;height:40px;background:rgba(255,255,255,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.nt-header-text{flex:1;}.nt-header-text h3{margin:0;font-size:15px;font-weight:800;}.nt-header-text p{margin:2px 0 0;font-size:11px;color:#fef3c7;font-weight:500;}
.nt-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:34px;height:34px;border-radius:8px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:background .2s;}
.nt-close:hover{background:rgba(220,38,38,.8);}
.nt-body{padding:20px;overflow-y:auto;flex:1;}
.nt-add-box{background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px 18px;margin-bottom:18px;}
.nt-add-title{font-size:12px;font-weight:700;color:#374151;margin-bottom:14px;display:flex;align-items:center;gap:7px;text-transform:uppercase;letter-spacing:.04em;}
.nt-form-row{display:grid;grid-template-columns:150px 1fr;gap:10px;margin-bottom:10px;}
.nt-fg{display:flex;flex-direction:column;gap:5px;}
.nt-fg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;}
.nt-fg input,.nt-fg textarea{border:1px solid #d1d5db;border-radius:7px;padding:8px 11px;font-size:13px;font-family:'Inter',sans-serif;color:#1f2937;width:100%;transition:border .2s;background:#fff;}
.nt-fg input:focus,.nt-fg textarea:focus{outline:none;border-color:#d97706;box-shadow:0 0 0 3px rgba(217,119,6,.1);}
.nt-fg textarea{resize:vertical;min-height:44px;}
.btn-add-nt{background:#a16207;color:#fff;border:none;border-radius:7px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s;width:100%;justify-content:center;}
.btn-add-nt:hover{background:#854d0e;}
.btn-add-nt:disabled{opacity:.6;cursor:not-allowed;}
.nt-table-title{font-size:12px;font-weight:700;color:#374151;margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.nt-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.nt-table thead th{padding:9px 10px;text-align:left;font-weight:700;font-size:11px;color:#64748b;background:#f1f5f9;border-bottom:2px solid #e2e8f0;text-transform:uppercase;letter-spacing:.04em;}
.nt-table tbody td{padding:10px 10px;border-bottom:1px solid #f1f5f9;color:#374151;vertical-align:top;white-space:pre-wrap;}
.nt-table tbody tr:hover td{background:#f8fafc;}
.nt-empty{text-align:center;padding:28px 20px;color:#94a3b8;border:2px dashed #e5e7eb;border-radius:10px;}
.nt-empty i{font-size:32px;display:block;margin-bottom:8px;opacity:.4;}

@media print{
    @page{margin:8mm 8mm 10mm 8mm;size:A4 landscape;}
    *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;box-sizing:border-box;}
    .no-print,.filter-card,.search-bar-wrap,.as-at-banner,.tbd-banner,.amt-banner,.table-toolbar,.stat-grid,#billModal,#billModalClose,#payHistoryModal,#cnModal,#remarkModal,#notesModal,.lightbox,nav,header,footer{display:none !important;}
    html,body{margin:0 !important;padding:0 !important;background:#fff !important;font-family:Arial,Helvetica,sans-serif !important;font-size:10px !important;color:#000 !important;}
    .print-header{display:block !important;border-bottom:2px solid #1e1b4b;padding-bottom:6px;margin-bottom:8px;}
    .print-header-title{font-size:15px;font-weight:900;color:#1e1b4b;}
    .print-header-meta{display:flex;gap:4px;margin-top:4px;flex-wrap:wrap;font-size:9px;color:#555;}
    .print-header-meta span{font-weight:700;color:#1e1b4b;}
    .table-card{border:none !important;box-shadow:none !important;border-radius:0 !important;margin:0 !important;padding:0 !important;}
    .dt-wrap{overflow:visible !important;}
    .data-table{width:100% !important;border-collapse:collapse !important;font-size:8px !important;table-layout:fixed;}
    .data-table thead th{background:#1e1b4b !important;color:#fff !important;padding:5px 3px !important;font-size:7.5px !important;font-weight:700 !important;border:1px solid #334155 !important;white-space:nowrap;}
    .data-table tbody tr{border-bottom:1px solid #e5e5e5 !important;page-break-inside:avoid;}
    .data-table tbody tr.normal-row td{background:#fff !important;}
    .data-table tbody tr.special-row td{background:#fefce8 !important;}
    .data-table tbody tr.row-hidden{display:none !important;}
    .data-table td{padding:3px 3px !important;font-size:8px !important;border:1px solid #e5e5e5 !important;color:#000 !important;vertical-align:middle !important;word-break:break-word;}
    .data-table tfoot tr{page-break-inside:avoid;}
    .data-table tfoot td{background:#1e1b4b !important;color:#fff !important;padding:5px 3px !important;font-size:8.5px !important;font-weight:900 !important;border:1px solid #334155 !important;}
    .data-table tfoot td.tr{text-align:right !important;}
    .sr-pill,.special-badge,.tbd-badge,.date-badge,.aging-badge,.cn-badge,.verified-badge,.unverified-badge,.bill-count-badge{background:transparent !important;border:none !important;padding:0 !important;font-size:7.5px !important;color:#000 !important;border-radius:0 !important;display:inline !important;}
    .tbd-badge{color:#4c1d95 !important;font-weight:700 !important;}
    .inv-link{color:#1e1b4b !important;text-decoration:none !important;border-bottom:none !important;font-size:8px !important;}
    .balance-amt{color:#dc2626 !important;font-weight:700 !important;}
    .bal-breakdown{display:none !important;}
    .btn-bill,.btn-history,.btn-cn,.btn,.actions-row,.rn-icon-btn{display:none !important;}
    .no-results-row{display:none !important;}
}



/* ── MAGNIFIER LENS ── */
.bm-middle { cursor: none; }
#billMagnifierLens {
  position: absolute;
  border-radius: 50%;
  width: 150px;
  height: 150px;
  border: 2px solid rgba(255,255,255,0.65);
  box-shadow: 0 0 0 1px rgba(0,0,0,0.35), 0 4px 24px rgba(0,0,0,0.5);
  pointer-events: none;
  display: none;
  overflow: hidden;
  z-index: 20;
  transform: translate(-50%, -50%);
  background-repeat: no-repeat;
}
#billMagnifierLens::before,
#billMagnifierLens::after {
  content: '';
  position: absolute;
  background: rgba(255,255,255,0.3);
  pointer-events: none;
}
#billMagnifierLens::before { width: 1px; height: 35%; left: 50%; top: 32.5%; }
#billMagnifierLens::after  { height: 1px; width: 35%; top: 50%; left: 32.5%; }
#billZoomBar {
  position: absolute;
  bottom: 12px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 21;
  display: none;
  align-items: center;
  gap: 8px;
  background: rgba(0,0,0,0.7);
  border-radius: 20px;
  padding: 5px 14px;
  font-size: 11px;
  color: #ccc;
  white-space: nowrap;
}
#billZoomBar input[type=range] {
  width: 90px;
  accent-color: #818cf8;
}
#billZoomLabel { color: #818cf8; font-weight: 700; font-size: 12px; min-width: 24px; }


</style>

<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;" class="no-print">
    <div>
        <h2 class="page-title"><i class="fa-solid fa-file-invoice-dollar"></i> Credit Bill Summary</h2>
        <p class="page-subtitle">
            <span style="color:#d97706;font-weight:700;">■</span> Yellow = Special Credit &nbsp;|&nbsp;
            <span style="color:#9ca3af;font-weight:600;">■</span> White = Normal Credit &nbsp;|&nbsp;
            <span style="font-weight:700;">Aging:</span>
            <span class="aging-badge aging-green" style="font-size:10px;padding:1px 7px;">0–29d Fresh</span>
            <span class="aging-badge aging-yellow" style="font-size:10px;padding:1px 7px;">30–59d Warning</span>
            <span class="aging-badge aging-orange" style="font-size:10px;padding:1px 7px;">60–89d Overdue</span>
            <span class="aging-badge aging-red" style="font-size:10px;padding:1px 7px;">90d+ Critical</span>
        </p>
    </div>
  <?php if($total_count > 0): ?>
<div style="display:flex;gap:8px;" class="no-print">
    <button onclick="openPrintView()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print (Old)</button>
    <button onclick="openBWPrint()" class="btn btn-secondary btn-sm" style="background:#111;color:#fff;border-color:#111;"><i class="fa-solid fa-file-lines"></i> Print B&amp;W</button>
    <button onclick="exportCSV()" class="btn btn-success btn-sm"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
</div>
<?php endif; ?>
</div>

<!-- FILTERS -->
<div class="filter-card no-print">
    <div class="filter-title"><i class="fa-solid fa-filter"></i> Filter Credit Bills</div>
    <form method="GET" id="filterForm">
        <div class="filter-grid">
            <div class="filter-inputs">
                <div class="fg">
                    <label><i class="fa-solid fa-route"></i> Route</label>
                    <select name="route" id="routeSelect" style="width:100%;">
                        <option value="">— All Routes —</option>
                        <?php foreach($all_routes as $rt): ?>
                        <option value="<?php echo htmlspecialchars($rt['route_code']); ?>" <?php echo $f_route===$rt['route_code']?'selected':''; ?>>
                            <?php echo htmlspecialchars($rt['route_code'].' — '.$rt['route_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label><i class="fa-solid fa-id-badge"></i> SR Code</label>
                    <select name="sr_code" id="srSelect" style="width:100%;">
                        <option value="">— All SR Codes —</option>
                        <?php foreach($all_sr as $sr): ?>
                        <option value="<?php echo htmlspecialchars($sr); ?>" <?php echo $f_sr===$sr?'selected':''; ?>>
                            <?php echo htmlspecialchars($sr); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label><i class="fa-solid fa-calendar-day"></i> Delivery Date</label>
                    <input type="date" name="delivery_date" value="<?php echo htmlspecialchars($f_date); ?>">
                </div>
                <div class="fg">
                    <label><i class="fa-solid fa-calendar-check"></i> As At Date</label>
                    <input type="date" name="as_at_date" value="<?php echo htmlspecialchars($f_as_at); ?>" title="Show balances as of this date">
                </div>
                <!-- ★ Amount Between Filter ★ -->
                <div class="fg">
                    <label><i class="fa-solid fa-coins"></i> Balance Min</label>
                    <input type="number" name="amount_min" step="0.01" min="0" placeholder="Min" value="<?php echo htmlspecialchars($f_amt_min); ?>">
                </div>
                <div class="fg">
                    <label><i class="fa-solid fa-coins"></i> Balance Max</label>
                    <input type="number" name="amount_max" step="0.01" min="0" placeholder="Max" value="<?php echo htmlspecialchars($f_amt_max); ?>">
                </div>
                <!-- ★ To Be Delivery Filter ★ -->
                <div class="fg">
                    <label><i class="fa-solid fa-truck"></i> To Be Delivery</label>
                    <select name="to_be_delivery" id="tbdSelect">
                        <option value=""  <?php echo $f_tbd===''  ? 'selected':''; ?>>— All —</option>
                        <option value="1" <?php echo $f_tbd==='1' ? 'selected':''; ?>>&#128666; Yes — Pending Delivery</option>
                        <option value="0" <?php echo $f_tbd==='0' ? 'selected':''; ?>>&#10003; No — Not Pending</option>
                    </select>
                </div>
            </div>
            <div class="fg" style="flex-direction:row;gap:8px;align-items:flex-end;">
                <button type="submit" class="btn btn-primary" id="searchBtn" style="flex:1;">
                    <i class="fa-solid fa-magnifying-glass"></i> Filter
                </button>
                <a href="credit_bill_summary2.php" class="btn btn-secondary" title="Clear All"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </div>
    </form>
</div>

<?php if(empty($rows)): ?>
<div class="table-card">
    <div class="state-box"><i class="fa-solid fa-inbox"></i><p>No outstanding invoices found.</p></div>
</div>
<?php else: ?>

<?php if($f_as_at): ?>
<div class="as-at-banner no-print">
    <i class="fa-solid fa-clock-rotate-left"></i>
    <strong>As At View:</strong> Showing outstanding balances as of <strong><?php echo date('d M Y', strtotime($f_as_at)); ?></strong>.
    Payments received after this date are excluded from Cash / Cheque / Balance figures.
</div>
<?php endif; ?>

<?php if($f_tbd === '1'): ?>
<div class="tbd-banner no-print">
    <i class="fa-solid fa-truck"></i>
    <strong>To Be Delivery Filter Active:</strong> Showing only invoices where delivery is still <strong>pending</strong>.
</div>
<?php elseif($f_tbd === '0'): ?>
<div class="tbd-banner no-print">
    <i class="fa-solid fa-truck"></i>
    <strong>To Be Delivery Filter Active:</strong> Showing only invoices where delivery is <strong>not pending</strong>.
</div>
<?php endif; ?>

<?php if($f_amt_min !== '' || $f_amt_max !== ''): ?>
<div class="amt-banner no-print">
    <i class="fa-solid fa-coins"></i>
    <strong>Balance Amount Filter Active:</strong> Showing invoices with balance
    <?php if($f_amt_min !== '' && $f_amt_max !== ''): ?>
        between <strong>Rs. <?php echo number_format(floatval($f_amt_min),2); ?></strong> and <strong>Rs. <?php echo number_format(floatval($f_amt_max),2); ?></strong>.
    <?php elseif($f_amt_min !== ''): ?>
        &ge; <strong>Rs. <?php echo number_format(floatval($f_amt_min),2); ?></strong>.
    <?php else: ?>
        &le; <strong>Rs. <?php echo number_format(floatval($f_amt_max),2); ?></strong>.
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- STAT CARDS -->
<div class="stat-grid" id="statCards">
    <div class="stat-card"><div class="stat-label">Total Invoices</div><div class="stat-value blue" id="sc-count"><?php echo $total_count; ?></div></div>
    <div class="stat-card"><div class="stat-label">Ikea Value</div><div class="stat-value" id="sc-net">Rs. <?php echo number_format($t_net,2); ?></div></div>
    <div class="stat-card">
        <div class="stat-label">Cash Paid<?php echo $f_as_at?' (as at '.date('d M Y',strtotime($f_as_at)).')':''; ?></div>
        <div class="stat-value green" id="sc-cash">Rs. <?php echo number_format($t_cash,2); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Cheque Paid<?php echo $f_as_at?' (as at '.date('d M Y',strtotime($f_as_at)).')':''; ?></div>
        <div class="stat-value blue" id="sc-cheque">Rs. <?php echo number_format($t_cheque,2); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Credit Notes</div>
        <div class="stat-value orange" id="sc-cn">Rs. <?php echo number_format($t_cn,2); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Balance<?php echo $f_as_at?' (as at '.date('d M Y',strtotime($f_as_at)).')':''; ?></div>
        <div class="stat-value red" id="sc-balance">Rs. <?php echo number_format($t_balance,2); ?></div>
    </div>
    <div class="stat-card"><div class="stat-label">Special / Normal</div><div class="stat-value amber" id="sc-type"><?php echo $t_special; ?> <span style="color:#9ca3af;font-size:13px;">/</span> <span style="color:#475569;"><?php echo $t_normal; ?></span></div></div>
</div>

<!-- LIVE SEARCH BAR -->
<div class="search-bar-wrap no-print">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input type="text" id="liveSearch" placeholder="Search by T Code, customer, invoice, route, SR code, date…" autocomplete="off">
    <button id="searchClear" onclick="clearLiveSearch()" title="Clear search"><i class="fa-solid fa-xmark"></i></button>
    <span id="searchMatchCount" style="display:none;"></span>
</div>

<!-- PRINT-ONLY REPORT HEADER -->
<div class="print-header" style="display:none;">
    <div class="print-header-title">Credit Bill Summary Report</div>
    <div class="print-header-meta">
        <?php if($f_route): ?><span>Route:</span> <?php echo htmlspecialchars($f_route); ?> &nbsp;<?php endif; ?>
        <?php if($f_sr):    ?><span>SR Code:</span> <?php echo htmlspecialchars($f_sr); ?> &nbsp;<?php endif; ?>
        <?php if($f_date):  ?><span>Del. Date:</span> <?php echo date('d M Y',strtotime($f_date)); ?> &nbsp;<?php endif; ?>
        <?php if($f_as_at): ?><span>As At:</span> <?php echo date('d M Y',strtotime($f_as_at)); ?> &nbsp;<?php endif; ?>
        <?php if($f_tbd === '1'): ?><span>TBD:</span> Yes Only &nbsp;<?php endif; ?>
        <?php if($f_tbd === '0'): ?><span>TBD:</span> No Only &nbsp;<?php endif; ?>
        <?php if($f_amt_min !== ''): ?><span>Bal Min:</span> Rs.<?php echo number_format(floatval($f_amt_min),2); ?> &nbsp;<?php endif; ?>
        <?php if($f_amt_max !== ''): ?><span>Bal Max:</span> Rs.<?php echo number_format(floatval($f_amt_max),2); ?> &nbsp;<?php endif; ?>
        <?php if(!$f_route && !$f_sr && !$f_date && !$f_as_at && $f_tbd === '' && $f_amt_min === '' && $f_amt_max === ''): ?><span>Scope:</span> All Records &nbsp;<?php endif; ?>
        &nbsp;|&nbsp; <span>Total:</span> <?php echo $total_count; ?> invoices
        &nbsp;|&nbsp; <span>Net:</span> Rs.&nbsp;<?php echo number_format($t_net,2); ?>
        &nbsp;|&nbsp; <span>Cash:</span> Rs.&nbsp;<?php echo number_format($t_cash,2); ?>
        &nbsp;|&nbsp; <span>Cheque:</span> Rs.&nbsp;<?php echo number_format($t_cheque,2); ?>
        &nbsp;|&nbsp; <span>CN:</span> Rs.&nbsp;<?php echo number_format($t_cn,2); ?>
        &nbsp;|&nbsp; <span>Balance:</span> Rs.&nbsp;<?php echo number_format($t_balance,2); ?>
        &nbsp;|&nbsp; <span>Printed:</span> <?php echo date('d M Y H:i'); ?>
    </div>
</div>

<!-- MAIN TABLE -->
<div class="table-card">
    <div class="table-toolbar no-print">
        <div class="tbl-title">
            <i class="fa-solid fa-table"></i> Credit Bill Summary
            <span class="pill pill-violet" id="visibleCount"><?php echo $total_count; ?> rows</span>
            <?php if($f_route): ?><span class="pill pill-blue">Route: <?php echo htmlspecialchars($f_route); ?></span><?php endif; ?>
            <?php if($f_sr): ?><span class="pill pill-violet">SR: <?php echo htmlspecialchars($f_sr); ?></span><?php endif; ?>
            <?php if($f_date): ?><span class="pill pill-green"><?php echo date('d M Y',strtotime($f_date)); ?></span><?php endif; ?>
            <?php if($f_as_at): ?><span class="pill" style="background:#fef3c7;color:#92400e;"><i class="fa-solid fa-clock" style="font-size:9px;"></i> As at: <?php echo date('d M Y',strtotime($f_as_at)); ?></span><?php endif; ?>
            <?php if($f_amt_min !== ''): ?><span class="pill" style="background:#fef9c3;color:#854d0e;"><i class="fa-solid fa-coins" style="font-size:9px;"></i> Min: Rs.<?php echo number_format(floatval($f_amt_min),2); ?></span><?php endif; ?>
            <?php if($f_amt_max !== ''): ?><span class="pill" style="background:#fef9c3;color:#854d0e;"><i class="fa-solid fa-coins" style="font-size:9px;"></i> Max: Rs.<?php echo number_format(floatval($f_amt_max),2); ?></span><?php endif; ?>
            <?php if($f_tbd === '1'): ?><span class="pill" style="background:#ede9fe;color:#4c1d95;"><i class="fa-solid fa-truck" style="font-size:9px;"></i> TBD: Yes</span><?php endif; ?>
            <?php if($f_tbd === '0'): ?><span class="pill" style="background:#f0fdf4;color:#166534;"><i class="fa-solid fa-circle-check" style="font-size:9px;"></i> TBD: No</span><?php endif; ?>
            <?php if(!$f_route && !$f_sr && !$f_date && !$f_as_at && $f_tbd === '' && $f_amt_min === '' && $f_amt_max === ''): ?><span class="pill" style="background:#fef9c3;color:#854d0e;">All Records</span><?php endif; ?>
        </div>
        <div class="legend">
            <div class="legend-item"><div class="ldot ldot-y"></div> Special Credit</div>
            <div class="legend-item"><div class="ldot ldot-w"></div> Normal Credit</div>
        </div>
    </div>
    <div class="dt-wrap">
    <table class="data-table" id="mainTable">
        <thead>
            <tr>
                <th class="tc no-print" style="width:58px;">Remark /<br>Notes</th>
                <th style="width:32px;">No</th>
                <th>T Code</th>
                <th class="tc">Rep Code</th>
                <th>Route</th>
                <th>Customer</th>
                <th>Invoice Number</th>
                <th class="tc">Del. Date</th>
                <th class="tc">Aging<?php echo $f_as_at?'<br><span style="font-size:8px;font-weight:400;opacity:.7;">('.date('d M Y',strtotime($f_as_at)).')</span>':''; ?></th>
                <th class="tc" title="To Be Delivery">TBD</th>
                <th class="tr">Ikea Value</th>
                <th class="tr" style="color:#86efac;">Cash Paid<?php echo $f_as_at?'<br><span style="font-size:8px;font-weight:400;opacity:.7;">(as at)</span>':''; ?></th>
                <th class="tr" style="color:#93c5fd;">Cheque Paid<?php echo $f_as_at?'<br><span style="font-size:8px;font-weight:400;opacity:.7;">(as at)</span>':''; ?></th>
                <th class="tr" style="color:#fdba74;">Credit Notes</th>
                <th class="tr">Balance<?php echo $f_as_at?'<br><span style="font-size:8px;font-weight:400;opacity:.7;">(as at)</span>':''; ?></th>
                <th class="tc" style="min-width:200px;">Actions</th>
                <th style="min-width:180px;">Remark</th>
            </tr>
        </thead>
        <tbody id="mainTbody">
        <?php $rn=1; foreach($rows as $row):
            $is_special  = intval($row['is_special']);
            $row_class   = $is_special ? 'special-row' : 'normal-row';
            $net         = floatval($row['net_value']);
            $paid        = floatval($row['paid']);
            $cash_paid   = floatval($row['cash_paid']);
            $cheque_paid = floatval($row['cheque_paid']);
            $total_cn    = floatval($row['total_cn']);
            $balance     = floatval($row['balance']);
            $bill_count  = intval($row['bill_count']);
            $bill_verified = isset($row['bill_verified']) ? $row['bill_verified'] : null;
            $tbd         = intval($row['to_be_delivery'] ?? 0);
            $del_date_raw= $row['delivery_date'] ?? '';
            $del_date_fmt= $del_date_raw ? date('d M Y', strtotime($del_date_raw)) : '—';
            $aging_days  = isset($row['aging_days']) ? max(0, intval($row['aging_days'])) : 0;
            $last_remark     = $row['last_remark'] ?? '';
            $last_remark_fmt = $row['last_remark_fmt'] ?? '';

            if ($aging_days >= 90)     { $ag_class='aging-red';    $ag_icon='fa-fire';                $ag_label=$aging_days.'d'; $ag_title='Critical — overdue 90+ days'; }
            elseif ($aging_days >= 60) { $ag_class='aging-orange'; $ag_icon='fa-triangle-exclamation';$ag_label=$aging_days.'d'; $ag_title='Overdue — 60–89 days'; }
            elseif ($aging_days >= 30) { $ag_class='aging-yellow'; $ag_icon='fa-clock';               $ag_label=$aging_days.'d'; $ag_title='Warning — 30–59 days'; }
            else                       { $ag_class='aging-green';  $ag_icon='fa-circle-check';        $ag_label=$aging_days.'d'; $ag_title='Fresh — under 30 days'; }
        ?>
        <tr class="<?php echo $row_class; ?>" id="trow-<?php echo $row['detail_id']; ?>"
            data-net="<?php echo $net; ?>"
            data-paid="<?php echo $paid; ?>"
            data-cash="<?php echo $cash_paid; ?>"
            data-cheque="<?php echo $cheque_paid; ?>"
            data-cn="<?php echo $total_cn; ?>"
            data-balance="<?php echo $balance; ?>"
            data-date="<?php echo htmlspecialchars($del_date_raw); ?>"
            data-aging="<?php echo $aging_days; ?>"
            data-special="<?php echo $is_special; ?>"
            data-tbd="<?php echo $tbd; ?>"
            data-search="<?php echo strtolower(htmlspecialchars(
                $row['t_code'].' '.$row['customer_name'].' '.$row['invoice_num'].' '.
                $row['route_code'].' '.$row['route_name'].' '.$row['sr_code'].' '.$del_date_fmt.
                ($tbd ? ' to be delivery tbd pending' : '')
            )); ?>">

            <td class="tc no-print">
                <div style="display:flex;gap:4px;justify-content:center;">
                    <button class="rn-icon-btn rn-remark-btn<?php echo $last_remark !== '' ? ' has-remarks' : ''; ?>"
                        data-detail-id="<?php echo $row['detail_id']; ?>"
                        data-inv="<?php echo htmlspecialchars($row['invoice_num'], ENT_QUOTES); ?>"
                        data-cust="<?php echo htmlspecialchars($row['customer_name'], ENT_QUOTES); ?>"
                        onclick="openRemarkModal(this.dataset.detailId, this.dataset.inv, this.dataset.cust)"
                        title="Remarks"><i class="fa-solid fa-comment-dots"></i></button>
                    <button class="rn-icon-btn rn-notes-btn"
                        data-detail-id="<?php echo $row['detail_id']; ?>"
                        data-inv="<?php echo htmlspecialchars($row['invoice_num'], ENT_QUOTES); ?>"
                        data-cust="<?php echo htmlspecialchars($row['customer_name'], ENT_QUOTES); ?>"
                        onclick="openNotesModal(this.dataset.detailId, this.dataset.inv, this.dataset.cust)"
                        title="Notes"><i class="fa-solid fa-note-sticky"></i></button>
                </div>
            </td>
            <td class="row-num" style="color:#9ca3af;font-size:11px;font-weight:600;"><?php echo $rn++; ?></td>
            <td><span style="font-family:monospace;font-size:11.5px;font-weight:700;color:#4338ca;"><?php echo htmlspecialchars($row['t_code']); ?></span></td>
            <td class="tc"><span class="sr-pill"><?php echo htmlspecialchars($row['sr_code']); ?></span></td>
            <td>
                <div style="font-size:12px;font-weight:700;color:#1f2937;"><?php echo htmlspecialchars($row['route_code']); ?></div>
                <div style="font-size:10px;color:#6b7280;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($row['route_name']); ?>"><?php echo htmlspecialchars($row['route_name']); ?></div>
            </td>
            <td>
                <div style="font-weight:600;color:#111827;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($row['customer_name']); ?>"><?php echo htmlspecialchars($row['customer_name']); ?></div>
                <?php if($is_special): ?><div style="margin-top:3px;"><span class="special-badge"><i class="fa-solid fa-star" style="font-size:8px;"></i> Special</span></div><?php endif; ?>
            </td>
            <td>
                <a class="inv-link" href="javascript:void(0)"
                   onclick="openPayHistory(<?php echo $row['detail_id']; ?>, '<?php echo addslashes(htmlspecialchars($row['invoice_num'])); ?>', '<?php echo addslashes(htmlspecialchars($row['customer_name'])); ?>')"
                   title="Click to view payment history">
                    <i class="fa-solid fa-receipt" style="font-size:10px;opacity:.6;margin-right:2px;"></i>
                    <?php echo htmlspecialchars($row['invoice_num']); ?>
                </a>
            </td>
            <td class="tc">
                <span class="date-badge"><i class="fa-solid fa-calendar-day" style="font-size:9px;"></i> <?php echo htmlspecialchars($del_date_fmt); ?></span>
            </td>
            <td class="tc">
                <span class="aging-badge <?php echo $ag_class; ?>" title="<?php echo $ag_title; ?>">
                    <i class="fa-solid <?php echo $ag_icon; ?>" style="font-size:9px;"></i> <?php echo $ag_label; ?>
                </span>
            </td>
            <!-- ★ TBD COLUMN ★ -->
            <td class="tc">
                <?php if($tbd): ?>
                <span class="tbd-badge" title="To Be Delivery — Pending"><i class="fa-solid fa-truck" style="font-size:9px;"></i> Yes</span>
                <?php else: ?>
                <span style="color:#d1d5db;font-size:11px;">—</span>
                <?php endif; ?>
            </td>
            <td class="tr" style="font-weight:500;"><?php echo number_format($net,2); ?></td>
            <td class="tr" style="color:#16a34a;font-weight:700;">
                <?php echo $cash_paid > 0 ? number_format($cash_paid,2) : '<span style="color:#d1d5db;font-weight:400;">—</span>'; ?>
            </td>
            <td class="tr" style="color:#2563eb;font-weight:700;">
                <?php echo $cheque_paid > 0 ? number_format($cheque_paid,2) : '<span style="color:#d1d5db;font-weight:400;">—</span>'; ?>
            </td>
            <td class="tr" id="cn-cell-<?php echo $row['detail_id']; ?>" style="color:#ea580c;font-weight:700;">
                <?php if($total_cn > 0): ?>
                    <span class="cn-cell-val"><?php echo number_format($total_cn,2); ?></span>
                <?php else: ?>
                    <span style="color:#d1d5db;font-weight:400;" class="cn-cell-val-zero">—</span>
                <?php endif; ?>
            </td>
            <td class="tr" id="bal-cell-<?php echo $row['detail_id']; ?>">
                <span class="balance-amt">Rs. <?php echo number_format($balance,2); ?></span>
                <?php if($total_cn > 0): ?>
                <div class="bal-breakdown">
                    <i class="fa-solid fa-file-minus" style="font-size:8px;"></i> CN: -<?php echo number_format($total_cn,2); ?>
                </div>
                <?php endif; ?>
            </td>
            <td class="tc" id="bill-cell-<?php echo $row['detail_id']; ?>">
                <div class="actions-row no-print">
                   <button class="btn btn-bill"
    data-detail-id="<?php echo $row['detail_id']; ?>"
    data-inv="<?php echo htmlspecialchars($row['invoice_num'], ENT_QUOTES); ?>"
    data-cust="<?php echo htmlspecialchars($row['customer_name'], ENT_QUOTES); ?>"
    onclick="openBillModal(this.dataset.detailId, this.dataset.inv, this.dataset.cust)"
    title="View / upload credit bill">
    <i class="fa-solid fa-file-image"></i> Verify
</button>
                   <button class="btn btn-history"
    data-detail-id="<?php echo $row['detail_id']; ?>"
    data-inv="<?php echo htmlspecialchars($row['invoice_num'], ENT_QUOTES); ?>"
    data-cust="<?php echo htmlspecialchars($row['customer_name'], ENT_QUOTES); ?>"
    onclick="openPayHistory(this.dataset.detailId, this.dataset.inv, this.dataset.cust)"
    title="View payment history">
    <i class="fa-solid fa-clock-rotate-left"></i> History
</button>
                    <button class="btn btn-cn <?php echo $total_cn > 0 ? 'has-notes' : ''; ?>"
    id="cnbtn-<?php echo $row['detail_id']; ?>"
    data-detail-id="<?php echo $row['detail_id']; ?>"
    data-inv="<?php echo htmlspecialchars($row['invoice_num'], ENT_QUOTES); ?>"
    data-cust="<?php echo htmlspecialchars($row['customer_name'], ENT_QUOTES); ?>"
    data-net="<?php echo $net; ?>"
    data-paid="<?php echo $paid; ?>"
    onclick="openCNModal(this.dataset.detailId, this.dataset.inv, this.dataset.cust, this.dataset.net, this.dataset.paid)"
    title="View / Add credit notes">
    <i class="fa-solid fa-file-minus"></i> CN<?php echo $total_cn > 0 ? ' ('.number_format($total_cn,0).')' : ''; ?>
</button>
                    <?php if($bill_count > 0): ?>
                        <span class="bill-count-badge"><i class="fa-solid fa-images"></i> <?php echo $bill_count; ?></span>
                    <?php endif; ?>
                    <?php if($bill_verified === '1' || $bill_verified === 1): ?>
                        <span id="vstatus-<?php echo $row['detail_id']; ?>" class="verified-badge"><i class="fa-solid fa-circle-check"></i> Verified</span>
                    <?php elseif($bill_verified === '0' || $bill_verified === 0): ?>
                        <span id="vstatus-<?php echo $row['detail_id']; ?>" class="unverified-badge"><i class="fa-solid fa-clock"></i> Not Verified</span>
                    <?php else: ?>
                        <span id="vstatus-<?php echo $row['detail_id']; ?>"></span>
                    <?php endif; ?>
                </div>
            </td>
            <td class="remark-cell" id="remark-cell-<?php echo $row['detail_id']; ?>">
                <?php if($last_remark !== ''): ?>
                    <div class="rc-text" title="<?php echo htmlspecialchars($last_remark, ENT_QUOTES); ?>"><?php echo htmlspecialchars($last_remark); ?></div>
                    <div class="rc-date"><i class="fa-regular fa-clock" style="font-size:8px;"></i> <?php echo htmlspecialchars($last_remark_fmt); ?></div>
                <?php else: ?>
                    <span class="rc-empty">No remark</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <tr class="no-results-row" id="noResultsRow">
            <td colspan="17"><i class="fa-solid fa-search" style="margin-right:6px;"></i>No rows match your search.</td>
        </tr>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="8" style="text-align:right;font-size:11px;opacity:.8;" id="foot-label">
                    TOTAL — <?php echo $total_count; ?> invoices (<?php echo $t_special; ?> special, <?php echo $t_normal; ?> normal)
                    <?php if($f_as_at): ?> &mdash; payments as at <?php echo date('d M Y',strtotime($f_as_at)); ?><?php endif; ?>
                    <?php if($f_tbd === '1'): ?> &mdash; TBD: Yes Only<?php endif; ?>
                    <?php if($f_tbd === '0'): ?> &mdash; TBD: No Only<?php endif; ?>
                    <?php if($f_amt_min !== '' || $f_amt_max !== ''): ?> &mdash; Balance: <?php echo $f_amt_min !== '' ? 'Min Rs.'.number_format(floatval($f_amt_min),2) : ''; ?><?php echo ($f_amt_min !== '' && $f_amt_max !== '') ? ' – ' : ''; ?><?php echo $f_amt_max !== '' ? 'Max Rs.'.number_format(floatval($f_amt_max),2) : ''; ?><?php endif; ?>
                </td>
                <td></td>
                <td></td>
                <td class="tr" id="foot-net">Rs. <?php echo number_format($t_net,2); ?></td>
                <td class="tr" id="foot-cash" style="color:#86efac;">Rs. <?php echo number_format($t_cash,2); ?></td>
                <td class="tr" id="foot-cheque" style="color:#93c5fd;">Rs. <?php echo number_format($t_cheque,2); ?></td>
                <td class="tr" id="foot-cn" style="color:#fdba74;">Rs. <?php echo number_format($t_cn,2); ?></td>
                <td class="tr" id="foot-balance">Rs. <?php echo number_format($t_balance,2); ?></td>
                <td></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- ═══ PAYMENT HISTORY MODAL ═══ -->
<div id="payHistoryModal" onclick="if(event.target===this)closePayHistory()">
    <div class="ph-modal">
        <div class="ph-header">
            <div class="ph-header-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <div class="ph-header-text">
                <h3 id="ph-title">Payment History</h3>
                <p id="ph-subtitle">Loading...</p>
            </div>
            <button class="ph-close" onclick="closePayHistory()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="ph-body" id="ph-body">
            <div class="ph-loading"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading payment history...</div>
        </div>
    </div>
</div>

<!-- ═══ CREDIT NOTE MODAL ═══ -->
<div id="cnModal" onclick="if(event.target===this)closeCNModal()">
    <div class="cn-modal">
        <div class="cn-header">
            <div class="cn-header-icon"><i class="fa-solid fa-file-minus"></i></div>
            <div class="cn-header-text">
                <h3 id="cn-title">Credit Notes</h3>
                <p id="cn-subtitle">Loading...</p>
            </div>
            <button class="cn-close" onclick="closeCNModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="cn-body" id="cn-body">
            <div class="ph-loading"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading credit notes...</div>
        </div>
    </div>
</div>

<!-- ═══ REMARK MODAL ═══ -->
<div id="remarkModal" onclick="if(event.target===this)closeRemarkModal()">
    <div class="rm-modal">
        <div class="rm-header">
            <div class="rm-header-icon"><i class="fa-solid fa-comment-dots"></i></div>
            <div class="rm-header-text">
                <h3 id="rm-title">Remarks</h3>
                <p id="rm-subtitle">Loading...</p>
            </div>
            <button class="rm-close" onclick="closeRemarkModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="rm-body" id="rm-body">
            <div class="ph-loading"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading remarks...</div>
        </div>
    </div>
</div>

<!-- ═══ NOTES MODAL ═══ -->
<div id="notesModal" onclick="if(event.target===this)closeNotesModal()">
    <div class="nt-modal">
        <div class="nt-header">
            <div class="nt-header-icon"><i class="fa-solid fa-note-sticky"></i></div>
            <div class="nt-header-text">
                <h3 id="nt-title">Notes</h3>
                <p id="nt-subtitle">Loading...</p>
            </div>
            <button class="nt-close" onclick="closeNotesModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="nt-body" id="nt-body">
            <div class="ph-loading"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading notes...</div>
        </div>
    </div>
</div>

<!-- ═══ FULLSCREEN BILL MODAL ═══ -->
<div id="billModal">
    <div class="bill-modal">
        <div class="bm-header">
            <div class="bm-header-info">
                <div>
                    <div class="bm-title"><i class="fa-solid fa-file-image"></i> Credit Bill Verification</div>
                    <div class="bm-subtitle" id="bm-sub">Loading...</div>
                </div>
                <div id="bm-badges" style="display:flex;gap:6px;flex-wrap:wrap;margin-left:10px;"></div>
            </div>
        </div>
        <div class="bm-body" id="bm-body">
            <div class="bm-loading"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading...</div>
        </div>
    </div>
</div>
<button id="billModalClose" onclick="closeBillModal()" title="Close (Esc)">&#x2715;</button>

<!-- Image lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
    <button class="lightbox-close" onclick="closeLightbox()"><i class="fa-solid fa-xmark"></i></button>
    <img src="" id="lightbox-img" alt="Preview">
</div>

<script>
const PAGE_AS_AT = <?php echo $f_as_at ? json_encode($f_as_at) : 'null'; ?>;

$(function(){
    $('#routeSelect').select2({placeholder:'— All Routes —',allowClear:true,width:'100%'});
    $('#srSelect').select2({placeholder:'— All SR Codes —',allowClear:true,width:'100%'});
});
document.getElementById('filterForm')?.addEventListener('submit',function(){
    const btn=document.getElementById('searchBtn');
    btn.disabled=true;
    btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Loading...';
});

/* ═══════════════════════════════════════
   LIVE SEARCH
═══════════════════════════════════════ */
(function(){
    const input        = document.getElementById('liveSearch');
    const clearBtn     = document.getElementById('searchClear');
    const matchBadge   = document.getElementById('searchMatchCount');
    const visCount     = document.getElementById('visibleCount');
    const noResultsRow = document.getElementById('noResultsRow');
    if(!input) return;

    const allRows = Array.from(document.querySelectorAll('#mainTbody tr[data-search]'));

    const totalNet     = <?php echo $t_net; ?>;
    const totalCash    = <?php echo $t_cash; ?>;
    const totalCheque  = <?php echo $t_cheque; ?>;
    const totalCn      = <?php echo $t_cn; ?>;
    const totalBalance = <?php echo $t_balance; ?>;
    const totalCount   = <?php echo $total_count; ?>;
    const totalSpecial = <?php echo $t_special; ?>;
    const totalNormal  = <?php echo $t_normal; ?>;

    function fmtMoney(v){ return 'Rs. '+v.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }

    function runSearch(){
        const q = input.value.trim().toLowerCase();
        clearBtn.style.display = q ? 'block' : 'none';

        if(!q){
            allRows.forEach((tr,i)=>{ tr.classList.remove('row-hidden'); tr.querySelector('.row-num').textContent=i+1; });
            if(noResultsRow) noResultsRow.style.display='none';
            if(matchBadge) matchBadge.style.display='none';
            if(visCount) visCount.textContent = totalCount+' rows';
            resetFooter(); resetStatCards();
            return;
        }

        let shown=0,net=0,cash=0,cheque=0,cn=0,balance=0,special=0,normal=0,rn=1;
        allRows.forEach(tr=>{
            const hay   = tr.dataset.search||'';
            const match = hay.includes(q);
            tr.classList.toggle('row-hidden',!match);
            if(match){
                tr.querySelector('.row-num').textContent=rn++;
                net     += parseFloat(tr.dataset.net)    ||0;
                cash    += parseFloat(tr.dataset.cash)   ||0;
                cheque  += parseFloat(tr.dataset.cheque) ||0;
                cn      += parseFloat(tr.dataset.cn)     ||0;
                balance += parseFloat(tr.dataset.balance)||0;
                if(parseInt(tr.dataset.special)) special++; else normal++;
                shown++;
            }
        });

        if(noResultsRow) noResultsRow.style.display = shown?'none':'table-row';
        if(matchBadge){ matchBadge.style.display='inline-flex'; matchBadge.textContent=shown+' match'+(shown!==1?'es':''); }
        if(visCount) visCount.textContent=shown+' rows';

        const fl=document.getElementById('foot-label');
        const fn=document.getElementById('foot-net');
        const fc=document.getElementById('foot-cash');
        const fq=document.getElementById('foot-cheque');
        const fcn=document.getElementById('foot-cn');
        const fb=document.getElementById('foot-balance');
        if(fl)  fl.textContent='FILTERED — '+shown+' invoices ('+special+' special, '+normal+' normal)';
        if(fn)  fn.textContent=fmtMoney(net);
        if(fc)  fc.textContent=fmtMoney(cash);
        if(fq)  fq.textContent=fmtMoney(cheque);
        if(fcn) fcn.textContent=fmtMoney(cn);
        if(fb)  fb.textContent=fmtMoney(balance);

        const sc  =document.getElementById('sc-count');
        const sn  =document.getElementById('sc-net');
        const sca =document.getElementById('sc-cash');
        const sq  =document.getElementById('sc-cheque');
        const scn =document.getElementById('sc-cn');
        const sb  =document.getElementById('sc-balance');
        const st  =document.getElementById('sc-type');
        if(sc)  sc.textContent =shown;
        if(sn)  sn.textContent =fmtMoney(net);
        if(sca) sca.textContent=fmtMoney(cash);
        if(sq)  sq.textContent =fmtMoney(cheque);
        if(scn) scn.textContent=fmtMoney(cn);
        if(sb)  sb.textContent =fmtMoney(balance);
        if(st)  st.innerHTML   =special+' <span style="color:#9ca3af;font-size:13px;">/</span> <span style="color:#475569;">'+normal+'</span>';
    }

    function resetFooter(){
        const fl=document.getElementById('foot-label');
        const fn=document.getElementById('foot-net');
        const fc=document.getElementById('foot-cash');
        const fq=document.getElementById('foot-cheque');
        const fcn=document.getElementById('foot-cn');
        const fb=document.getElementById('foot-balance');
        if(fl) fl.textContent='TOTAL — '+totalCount+' invoices ('+totalSpecial+' special, '+totalNormal+' normal)';
        if(fn) fn.textContent=fmtMoney(totalNet);
        if(fc) fc.textContent=fmtMoney(totalCash);
        if(fq) fq.textContent=fmtMoney(totalCheque);
        if(fcn) fcn.textContent=fmtMoney(totalCn);
        if(fb) fb.textContent=fmtMoney(totalBalance);
    }

    function resetStatCards(){
        const sc  =document.getElementById('sc-count');
        const sn  =document.getElementById('sc-net');
        const sca =document.getElementById('sc-cash');
        const sq  =document.getElementById('sc-cheque');
        const scn =document.getElementById('sc-cn');
        const sb  =document.getElementById('sc-balance');
        const st  =document.getElementById('sc-type');
        if(sc)  sc.textContent =totalCount;
        if(sn)  sn.textContent =fmtMoney(totalNet);
        if(sca) sca.textContent=fmtMoney(totalCash);
        if(sq)  sq.textContent =fmtMoney(totalCheque);
        if(scn) scn.textContent=fmtMoney(totalCn);
        if(sb)  sb.textContent =fmtMoney(totalBalance);
        if(st)  st.innerHTML   =totalSpecial+' <span style="color:#9ca3af;font-size:13px;">/</span> <span style="color:#475569;">'+totalNormal+'</span>';
    }

    let _t;
    input.addEventListener('input',()=>{ clearTimeout(_t); _t=setTimeout(runSearch,120); });
    input.addEventListener('keydown',e=>{ if(e.key==='Escape') clearLiveSearch(); });
    window.clearLiveSearch = function(){ input.value=''; clearBtn.style.display='none'; runSearch(); input.focus(); };
})();

/* ═══════════════════════════════════════
   PAYMENT HISTORY MODAL
═══════════════════════════════════════ */
function openPayHistory(detailId, invNum, custName) {
    const modal = document.getElementById('payHistoryModal');
    document.getElementById('ph-title').textContent = 'Payment History';
    document.getElementById('ph-subtitle').textContent = invNum+' — '+custName;
    document.getElementById('ph-body').innerHTML = '<div class="ph-loading"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading payment history...</div>';
    modal.classList.add('open');
    let url = 'credit_bill_summary2.php?ajax=payment_history&detail_id='+detailId;
    if(PAGE_AS_AT) url += '&as_at_date='+encodeURIComponent(PAGE_AS_AT);
    fetch(url).then(r=>r.json()).then(data=>{
        if(!data.success){ document.getElementById('ph-body').innerHTML='<div class="ph-empty"><i class="fa-solid fa-circle-exclamation"></i><div>'+(data.error||'Failed to load')+'</div></div>'; return; }
        renderPayHistory(data, invNum, custName);
    }).catch(()=>{
        document.getElementById('ph-body').innerHTML='<div class="ph-empty"><i class="fa-solid fa-circle-exclamation"></i><div>Network error.</div></div>';
    });
}

function renderPayHistory(data, invNum, custName) {
    const info      = data.info||{};
    const payments  = data.payments||[];
    const netVal    = parseFloat(info.net_value||0);
    const totalPaid = parseFloat(data.total_paid||0);
    const totalCN   = parseFloat(data.total_cn||0);
    const balance   = parseFloat(data.balance||0);
    const asAtDate  = data.as_at_date||null;

    function fmtM(v){ return 'Rs. '+parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    function fmtDateLabel(iso){ if(!iso)return''; const d=new Date(iso+'T00:00:00'); return d.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}); }

    let html = '';
    if(asAtDate) html += `<div class="ph-as-at-note"><i class="fa-solid fa-clock-rotate-left"></i><span>Showing payments up to <strong>${fmtDateLabel(asAtDate)}</strong> only.</span></div>`;

    html += `<div class="ph-summary">
        <div class="ph-sum-card"><div class="ph-sum-label">Ikea Value</div><div class="ph-sum-val text-net">${fmtM(netVal)}</div></div>
        <div class="ph-sum-card"><div class="ph-sum-label">Paid (Cash+Cleared)</div><div class="ph-sum-val text-paid">${fmtM(totalPaid)}</div></div>
        <div class="ph-sum-card"><div class="ph-sum-label">Credit Notes</div><div class="ph-sum-val text-cn">${fmtM(totalCN)}</div></div>
        <div class="ph-sum-card" style="border-color:${balance>0?'#fecaca':'#86efac'};"><div class="ph-sum-label">Balance</div><div class="ph-sum-val text-bal">${fmtM(balance)}</div></div>
    </div>`;

    html += `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;font-size:11px;">
        <span style="background:#ede9fe;color:#5b21b6;padding:3px 10px;border-radius:20px;font-weight:700;"><i class="fa-solid fa-receipt"></i> ${invNum}</span>`;
    if(info.t_code)        html += `<span style="background:#f3f4f6;color:#374151;padding:3px 10px;border-radius:20px;font-weight:600;">${info.t_code}</span>`;
    if(info.route_code)    html += `<span style="background:#dbeafe;color:#1e40af;padding:3px 10px;border-radius:20px;font-weight:600;"><i class="fa-solid fa-route"></i> ${info.route_code}</span>`;
    if(info.delivery_date) html += `<span style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:20px;font-weight:600;"><i class="fa-solid fa-calendar-day"></i> ${fmtDateLabel(info.delivery_date)}</span>`;
    if(asAtDate)           html += `<span style="background:#fef9c3;color:#854d0e;border:1px solid #fde047;padding:3px 10px;border-radius:20px;font-weight:700;"><i class="fa-solid fa-clock"></i> As at: ${fmtDateLabel(asAtDate)}</span>`;
    html += `</div>`;

    if(payments.length === 0){
        html += `<div class="ph-empty"><i class="fa-solid fa-money-bill-wave"></i><div style="font-size:14px;font-weight:600;color:#6b7280;">No payments recorded${asAtDate?' up to '+fmtDateLabel(asAtDate):''}</div></div>`;
    } else {
        html += `<div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
            <i class="fa-solid fa-list"></i> Payment Records
            <span style="background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;">${payments.length}</span>
        </div>
        <table class="ph-table"><thead><tr>
            <th style="width:28px;">#</th><th>Date</th><th>Method</th><th>Reference / Cheque</th>
            <th>Bank / Branch</th><th class="tc">Status</th><th class="tr">Amount</th><th>Recorded</th>
        </tr></thead><tbody>`;

        let grandTotal = 0;
        payments.forEach((p,i)=>{
            const amt = parseFloat(p.amount||0); grandTotal += amt;
            const method = (p.payment_method||'other').toLowerCase();
            let mClass='ph-method-other',mLabel=method.charAt(0).toUpperCase()+method.slice(1),mIcon='fa-money-bill';
            if(method==='cash'){mClass='ph-method-cash';mIcon='fa-money-bill';}
            else if(method==='cheque'||method==='check'){mClass='ph-method-cheque';mLabel='Cheque';mIcon='fa-money-check';}
            else if(['bank','bank_transfer','transfer'].includes(method)){mClass='ph-method-bank';mLabel='Bank Transfer';mIcon='fa-building-columns';}
            const ref = p.reference_no||p.cheque_no||'—';
            const bankBranch = (p.bank_name||p.branch_name)?[p.bank_name,p.branch_name].filter(Boolean).join(' / '):'—';
            let statusHtml = '';
            if(method==='cheque'||method==='check'){
                const st=(p.cheque_status||'pending').toLowerCase();
                let stC='ph-status-pending',stI='fa-clock',stL='Pending';
                if(st==='cleared'){stC='ph-status-cleared';stI='fa-circle-check';stL='Cleared';}
                else if(st==='bounced'||st==='returned'){stC='ph-status-bounced';stI='fa-circle-xmark';stL='Bounced';}
                statusHtml = `<span class="ph-cheque-status ${stC}"><i class="fa-solid ${stI}"></i> ${stL}</span>`;
            } else {
                statusHtml = `<span class="ph-cheque-status ph-status-cleared"><i class="fa-solid fa-circle-check"></i> Received</span>`;
            }
            const remarksAttr = p.remarks?` title="${p.remarks.replace(/"/g,'&quot;')}"` :'';
            html += `<tr${remarksAttr}>
                <td style="color:#9ca3af;font-size:11px;">${i+1}</td>
                <td><strong>${p.pay_date_fmt||p.payment_date||'—'}</strong></td>
                <td><span class="ph-pay-method ${mClass}"><i class="fa-solid ${mIcon}"></i> ${mLabel}</span></td>
                <td style="font-size:11px;font-family:monospace;font-weight:600;">${ref}</td>
                <td style="font-size:10px;color:#6b7280;">${bankBranch}</td>
                <td class="tc">${statusHtml}</td>
                <td class="tr" style="font-weight:700;color:#16a34a;">${fmtM(amt)}</td>
                <td style="font-size:10px;color:#9ca3af;">${p.created_fmt||'—'}</td>
            </tr>`;
        });
        html += `</tbody><tfoot><tr>
            <td colspan="6" style="text-align:right;font-size:11px;color:#64748b;font-weight:700;">TOTAL (all listed entries)</td>
            <td class="tr" style="font-weight:800;">${fmtM(grandTotal)}</td><td></td>
        </tr></tfoot></table>`;
        if(grandTotal!==totalPaid) html += `<div style="margin-top:10px;padding:8px 12px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;font-size:11px;color:#92400e;display:flex;align-items:center;gap:6px;"><i class="fa-solid fa-circle-info"></i> Paid total (${fmtM(totalPaid)}) includes cash and cleared cheques only. Pending cheques are listed but not counted until cleared.</div>`;
    }
    document.getElementById('ph-body').innerHTML = html;
}

function closePayHistory(){ document.getElementById('payHistoryModal').classList.remove('open'); }

/* ═══════════════════════════════════════
   CREDIT NOTE MODAL
═══════════════════════════════════════ */
let _cnDetailId = null, _cnNetValue = 0, _cnPaidValue = 0, _cnInvNum = '', _cnCustName = '';

function openCNModal(detailId, invNum, custName, netValue, paidValue) {
    _cnDetailId  = detailId;
    _cnNetValue  = parseFloat(netValue)||0;
    _cnPaidValue = parseFloat(paidValue)||0;
    _cnInvNum    = invNum;
    _cnCustName  = custName;

    document.getElementById('cn-title').textContent    = 'Credit Notes';
    document.getElementById('cn-subtitle').textContent = invNum+' — '+custName;
    document.getElementById('cn-body').innerHTML = '<div class="ph-loading"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading...</div>';
    document.getElementById('cnModal').classList.add('open');

    fetch('save_credit_note.php?action=list&detail_id='+detailId)
    .then(r=>r.json())
    .then(data=>{
        if(!data.success){ document.getElementById('cn-body').innerHTML='<div class="ph-empty"><i class="fa-solid fa-circle-exclamation"></i><div>'+(data.error||'Load failed')+'</div></div>'; return; }
        renderCNModal(data.notes||[], parseFloat(data.total_cn||0));
    })
    .catch(()=>{ document.getElementById('cn-body').innerHTML='<div class="ph-empty"><i class="fa-solid fa-circle-exclamation"></i><div>Network error.</div></div>'; });
}

function renderCNModal(notes, totalCN) {
    function fmtM(v){ return 'Rs. '+parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    const balance = _cnNetValue - _cnPaidValue - totalCN;

    let html = `<div class="cn-summary">
        <div class="cn-sum-card"><div class="cn-sum-label">Ikea Value</div><div class="cn-sum-val cn-net">${fmtM(_cnNetValue)}</div></div>
        <div class="cn-sum-card"><div class="cn-sum-label">Total Paid</div><div class="cn-sum-val cn-paid">${fmtM(_cnPaidValue)}</div></div>
        <div class="cn-sum-card"><div class="cn-sum-label">Credit Notes</div><div class="cn-sum-val cn-cn" id="cn-modal-total">${fmtM(totalCN)}</div></div>
        <div class="cn-sum-card" style="border-color:${balance>0?'#fecaca':'#bbf7d0'};"><div class="cn-sum-label">Balance</div><div class="cn-sum-val cn-bal" id="cn-modal-balance">${fmtM(balance)}</div></div>
    </div>`;

    html += `<div class="cn-list" id="cnListContainer">`;
    html += `<div class="cn-list-title"><i class="fa-solid fa-file-minus"></i> Credit Notes <span style="background:#fff7ed;color:#c2410c;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;" id="cn-count-badge">${notes.length}</span></div>`;

    if(notes.length === 0){
        html += `<div class="cn-empty" id="cnEmptyMsg"><i class="fa-solid fa-file-circle-minus"></i><div style="font-size:13px;font-weight:600;color:#6b7280;">No credit notes yet</div><div style="font-size:11px;color:#9ca3af;margin-top:4px;">Add a credit note below to deduct from the balance.</div></div>`;
    } else {
        html += `<div id="cnItemsWrap">`;
        notes.forEach(n=>{ html += buildCNItem(n); });
        html += `</div>`;
    }
    html += `</div>`;

    html += `<div class="cn-add-box">
        <div class="cn-add-title"><i class="fa-solid fa-plus-circle"></i> Add New Credit Note</div>
        <div class="cn-form-row">
            <div class="cn-fg"><label>Amount (Rs.)</label><input type="number" id="cnAmount" placeholder="0.00" min="0.01" step="0.01"></div>
            <div class="cn-fg"><label>Reason</label><textarea id="cnReason" placeholder="Enter reason for credit note…" rows="2"></textarea></div>
            <div class="cn-fg"><label>Date</label><input type="date" id="cnDate" value="${new Date().toISOString().split('T')[0]}"></div>
        </div>
        <button class="btn-add-cn" id="cnAddBtn" onclick="addCreditNote()"><i class="fa-solid fa-plus"></i> Add Credit Note</button>
    </div>`;

    document.getElementById('cn-body').innerHTML = html;
}

function buildCNItem(n){
    const amt = parseFloat(n.amount||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    const reason = n.reason ? n.reason : '<em style="color:#d97706;">No reason given</em>';
    return `<div class="cn-item" id="cn-item-${n.id}">
        <div class="cn-item-icon"><i class="fa-solid fa-file-minus"></i></div>
        <div class="cn-item-body">
            <div class="cn-item-top">
                <span class="cn-item-amount">-Rs. ${amt}</span>
                <span class="cn-item-date"><i class="fa-solid fa-calendar-day" style="font-size:9px;"></i> ${n.note_date_fmt||n.note_date||'—'}</span>
            </div>
            <div class="cn-item-reason">${reason}</div>
            <div class="cn-item-created"><i class="fa-regular fa-clock" style="font-size:9px;"></i> Added: ${n.created_fmt||n.created_at||'—'}</div>
        </div>
        <button class="cn-item-del" onclick="deleteCreditNote(${n.id},${n.amount})" title="Delete this credit note"><i class="fa-solid fa-trash"></i></button>
    </div>`;
}

function addCreditNote(){
    const amtInput  = document.getElementById('cnAmount');
    const reaInput  = document.getElementById('cnReason');
    const dateInput = document.getElementById('cnDate');
    const addBtn    = document.getElementById('cnAddBtn');
    const amount = parseFloat(amtInput.value)||0;
    const reason = reaInput.value.trim();
    const date   = dateInput.value;
    if(amount <= 0){ showToast('Please enter a valid amount','error'); amtInput.focus(); return; }
    if(!date)      { showToast('Please select a date','error'); dateInput.focus(); return; }
    addBtn.disabled = true;
    addBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding...';
    const fd = new FormData();
    fd.append('action','add'); fd.append('detail_id',_cnDetailId);
    fd.append('amount',amount); fd.append('reason',reason); fd.append('note_date',date);
    fetch('save_credit_note.php',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(data=>{
        addBtn.disabled = false;
        addBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Add Credit Note';
        if(!data.success){ showToast(data.error||'Failed to add credit note','error'); return; }
        const now = new Date();
        const createdFmt = now.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})+' '+now.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'});
        const newNote = {id:data.id,amount:data.amount,reason:data.reason,note_date:data.note_date,note_date_fmt:data.note_date_fmt,created_fmt:createdFmt};
        const emptyMsg = document.getElementById('cnEmptyMsg');
        if(emptyMsg) emptyMsg.remove();
        let wrap = document.getElementById('cnItemsWrap');
        if(!wrap){ wrap = document.createElement('div'); wrap.id='cnItemsWrap'; document.getElementById('cnListContainer').appendChild(wrap); }
        wrap.insertAdjacentHTML('afterbegin', buildCNItem(newNote));
        const badge = document.getElementById('cn-count-badge');
        if(badge) badge.textContent = (parseInt(badge.textContent)||0)+1;
        updateCNModalSummary(data.total_cn);
        updateTableRowCN(_cnDetailId, data.total_cn);
        amtInput.value=''; reaInput.value=''; dateInput.value=new Date().toISOString().split('T')[0];
        showToast('Credit note added — Rs. '+parseFloat(data.amount).toLocaleString('en-US',{minimumFractionDigits:2}),'success');
    })
    .catch(()=>{ addBtn.disabled=false; addBtn.innerHTML='<i class="fa-solid fa-plus"></i> Add Credit Note'; showToast('Network error','error'); });
}

function deleteCreditNote(cnId, cnAmount){
    if(!confirm('Delete this credit note of Rs. '+parseFloat(cnAmount).toLocaleString('en-US',{minimumFractionDigits:2})+'?\n\nThis will add back this amount to the outstanding balance.')) return;
    const fd = new FormData(); fd.append('action','delete'); fd.append('id',cnId);
    fetch('save_credit_note.php',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(data=>{
        if(!data.success){ showToast(data.error||'Delete failed','error'); return; }
        const item = document.getElementById('cn-item-'+cnId);
        if(item) item.remove();
        const wrap = document.getElementById('cnItemsWrap');
        if(wrap && wrap.children.length === 0){
            wrap.remove();
            const listContainer = document.getElementById('cnListContainer');
            if(listContainer) listContainer.insertAdjacentHTML('beforeend',`<div class="cn-empty" id="cnEmptyMsg"><i class="fa-solid fa-file-circle-minus"></i><div style="font-size:13px;font-weight:600;color:#6b7280;">No credit notes</div></div>`);
        }
        const badge = document.getElementById('cn-count-badge');
        if(badge) badge.textContent = Math.max(0,(parseInt(badge.textContent)||0)-1);
        updateCNModalSummary(data.total_cn);
        updateTableRowCN(_cnDetailId, data.total_cn);
        showToast('Credit note deleted','success');
    })
    .catch(()=>showToast('Network error','error'));
}

function updateCNModalSummary(totalCN){
    function fmtM(v){ return 'Rs. '+parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    const newBalance = _cnNetValue - _cnPaidValue - parseFloat(totalCN||0);
    const totalEl  = document.getElementById('cn-modal-total');
    const balEl    = document.getElementById('cn-modal-balance');
    if(totalEl) totalEl.textContent = fmtM(totalCN);
    if(balEl){ balEl.textContent = fmtM(newBalance); balEl.closest('.cn-sum-card').style.borderColor = newBalance > 0 ? '#fecaca' : '#bbf7d0'; }
}

function updateTableRowCN(detailId, newTotalCN){
    const cn  = parseFloat(newTotalCN||0);
    const tr  = document.getElementById('trow-'+detailId);
    if(!tr) return;
    const net    = parseFloat(tr.dataset.net  ||0);
    const paid   = parseFloat(tr.dataset.paid ||0);
    const newBal = net - paid - cn;
    tr.dataset.cn      = cn;
    tr.dataset.balance = newBal;
    const cnCell = document.getElementById('cn-cell-'+detailId);
    if(cnCell){
        if(cn > 0){ cnCell.innerHTML=`<span class="cn-cell-val">${cn.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</span>`; cnCell.style.color='#ea580c'; cnCell.style.fontWeight='700'; }
        else { cnCell.innerHTML=`<span style="color:#d1d5db;font-weight:400;" class="cn-cell-val-zero">—</span>`; }
    }
    const balCell = document.getElementById('bal-cell-'+detailId);
    if(balCell){
        let html = `<span class="balance-amt">Rs. ${newBal.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</span>`;
        if(cn > 0) html += `<div class="bal-breakdown"><i class="fa-solid fa-file-minus" style="font-size:8px;"></i> CN: -${cn.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</div>`;
        balCell.innerHTML = html;
    }
    const cnBtn = document.getElementById('cnbtn-'+detailId);
    if(cnBtn){ cnBtn.innerHTML=`<i class="fa-solid fa-file-minus"></i> CN${cn>0?' ('+cn.toLocaleString('en-US',{maximumFractionDigits:0})+')':''}`; cnBtn.classList.toggle('has-notes',cn>0); }
    if(newBal <= 0) tr.classList.add('row-hidden');
    recalcFooterTotals();
}

function recalcFooterTotals(){
    function fmtM(v){ return 'Rs. '+parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    let net=0,cash=0,cheque=0,cn=0,balance=0,count=0,special=0,normal=0;
    document.querySelectorAll('#mainTbody tr[data-search]:not(.row-hidden)').forEach(tr=>{
        net     += parseFloat(tr.dataset.net    ||0);
        cash    += parseFloat(tr.dataset.cash   ||0);
        cheque  += parseFloat(tr.dataset.cheque ||0);
        cn      += parseFloat(tr.dataset.cn     ||0);
        balance += parseFloat(tr.dataset.balance||0);
        if(parseInt(tr.dataset.special)) special++; else normal++;
        count++;
    });
    const fn=document.getElementById('foot-net');
    const fc=document.getElementById('foot-cash');
    const fq=document.getElementById('foot-cheque');
    const fcn=document.getElementById('foot-cn');
    const fb=document.getElementById('foot-balance');
    const fl=document.getElementById('foot-label');
    const vc=document.getElementById('visibleCount');
    if(fn) fn.textContent=fmtM(net); if(fc) fc.textContent=fmtM(cash); if(fq) fq.textContent=fmtM(cheque);
    if(fcn) fcn.textContent=fmtM(cn); if(fb) fb.textContent=fmtM(balance);
    if(fl) fl.textContent='TOTAL — '+count+' invoices ('+special+' special, '+normal+' normal)';
    if(vc) vc.textContent=count+' rows';
    const sc=document.getElementById('sc-count'),sn=document.getElementById('sc-net'),sca=document.getElementById('sc-cash');
    const sq=document.getElementById('sc-cheque'),scn=document.getElementById('sc-cn'),sb=document.getElementById('sc-balance'),st=document.getElementById('sc-type');
    if(sc) sc.textContent=count; if(sn) sn.textContent=fmtM(net); if(sca) sca.textContent=fmtM(cash);
    if(sq) sq.textContent=fmtM(cheque); if(scn) scn.textContent=fmtM(cn); if(sb) sb.textContent=fmtM(balance);
    if(st) st.innerHTML=special+' <span style="color:#9ca3af;font-size:13px;">/</span> <span style="color:#475569;">'+normal+'</span>';
}

function closeCNModal(){ document.getElementById('cnModal').classList.remove('open'); }

/* ═══════════════════════════════════════
   REMARK MODAL
═══════════════════════════════════════ */
let _rmDetailId = null;

function openRemarkModal(detailId, invNum, custName){
    _rmDetailId = detailId;
    document.getElementById('rm-title').textContent = 'Remarks';
    document.getElementById('rm-subtitle').textContent = invNum+' — '+custName;
    document.getElementById('rm-body').innerHTML = '<div class="ph-loading"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading...</div>';
    document.getElementById('remarkModal').classList.add('open');
    loadRemarks(detailId);
}

function loadRemarks(detailId){
    fetch('credit_bill_summary2.php?ajax=remark_list&detail_id='+detailId)
    .then(r=>r.json())
    .then(data=>{
        if(!data.success){ document.getElementById('rm-body').innerHTML='<div class="rm-empty"><i class="fa-solid fa-circle-exclamation"></i><div>Failed to load remarks</div></div>'; return; }
        renderRemarkModal(data.remarks||[]);
    })
    .catch(()=>{ document.getElementById('rm-body').innerHTML='<div class="rm-empty"><i class="fa-solid fa-circle-exclamation"></i><div>Network error.</div></div>'; });
}

function renderRemarkModal(remarks){
    let html = '';
    if(remarks.length > 0){
        const cur = remarks[0];
        html += `<div class="rm-current-box">
            <div class="rm-current-label"><i class="fa-solid fa-thumbtack"></i> Current Remark</div>
            <div class="rm-current-text">${escapeHtml(cur.remark)}</div>
            <div class="rm-current-date"><i class="fa-regular fa-clock"></i> ${cur.created_fmt||''}</div>
        </div>`;
    } else {
        html += `<div class="rm-current-box" style="background:#f9fafb;border-color:#e5e7eb;">
            <div class="rm-current-label" style="color:#9ca3af;"><i class="fa-solid fa-thumbtack"></i> Current Remark</div>
            <div class="rm-current-text" style="color:#9ca3af;font-style:italic;">No remark added yet</div>
        </div>`;
    }

    html += `<div class="rm-add-box">
        <div class="rm-add-title"><i class="fa-solid fa-plus-circle"></i> Add New Remark</div>
        <textarea id="rmText" placeholder="Type your remark here…" rows="3"></textarea>
        <button class="btn-add-rm" id="rmAddBtn" onclick="addRemark()"><i class="fa-solid fa-plus"></i> Add Remark</button>
    </div>`;

    if(remarks.length > 1){
        html += `<div class="rm-history-title"><i class="fa-solid fa-clock-rotate-left"></i> Remark History <span style="background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;">${remarks.length-1}</span></div>`;
        html += `<div id="rmHistoryWrap">`;
        remarks.slice(1).forEach(r=>{ html += buildRemarkHistoryItem(r); });
        html += `</div>`;
    } else {
        html += `<div class="rm-history-title"><i class="fa-solid fa-clock-rotate-left"></i> Remark History</div>
        <div class="rm-empty" id="rmHistoryEmpty"><i class="fa-solid fa-comment-slash"></i><div style="font-size:12px;">No older remarks</div></div>`;
    }

    document.getElementById('rm-body').innerHTML = html;
}

function buildRemarkHistoryItem(r){
    return `<div class="rm-history-item">
        <div class="rm-history-text">${escapeHtml(r.remark)}</div>
        <div class="rm-history-date"><i class="fa-regular fa-clock"></i> ${r.created_fmt||''}</div>
    </div>`;
}

function addRemark(){
    const txt = document.getElementById('rmText');
    const btn = document.getElementById('rmAddBtn');
    const val = txt.value.trim();
    if(!val){ showToast('Please enter a remark','error'); txt.focus(); return; }
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding...';
    const fd = new FormData();
    fd.append('detail_id', _rmDetailId); fd.append('remark', val);
    fetch('credit_bill_summary2.php?ajax=remark_add',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(data=>{
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-plus"></i> Add Remark';
        if(!data.success){ showToast(data.error||'Failed to add remark','error'); return; }
        showToast('Remark added','success');
        loadRemarks(_rmDetailId);
        markRowHasRemark(_rmDetailId);
        updateTableRowRemark(_rmDetailId, data.remark, data.created_fmt);
    })
    .catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-plus"></i> Add Remark'; showToast('Network error','error'); });
}

function markRowHasRemark(detailId){
    const btn = document.querySelector('#trow-'+detailId+' .rn-remark-btn');
    if(btn) btn.classList.add('has-remarks');
}

function updateTableRowRemark(detailId, remarkText, createdFmt){
    const cell = document.getElementById('remark-cell-'+detailId);
    if(!cell) return;
    const safeText = escapeHtml(remarkText);
    cell.innerHTML = `<div class="rc-text" title="${safeText}">${safeText}</div><div class="rc-date"><i class="fa-regular fa-clock" style="font-size:8px;"></i> ${createdFmt||''}</div>`;
}

function closeRemarkModal(){ document.getElementById('remarkModal').classList.remove('open'); }

/* ═══════════════════════════════════════
   NOTES MODAL
═══════════════════════════════════════ */
let _ntDetailId = null;

function openNotesModal(detailId, invNum, custName){
    _ntDetailId = detailId;
    document.getElementById('nt-title').textContent = 'Notes';
    document.getElementById('nt-subtitle').textContent = invNum+' — '+custName;
    document.getElementById('nt-body').innerHTML = '<div class="ph-loading"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading...</div>';
    document.getElementById('notesModal').classList.add('open');
    loadNotes(detailId);
}

function loadNotes(detailId){
    fetch('credit_bill_summary2.php?ajax=note_list&detail_id='+detailId)
    .then(r=>r.json())
    .then(data=>{
        if(!data.success){ document.getElementById('nt-body').innerHTML='<div class="nt-empty"><i class="fa-solid fa-circle-exclamation"></i><div>Failed to load notes</div></div>'; return; }
        renderNotesModal(data.notes||[]);
    })
    .catch(()=>{ document.getElementById('nt-body').innerHTML='<div class="nt-empty"><i class="fa-solid fa-circle-exclamation"></i><div>Network error.</div></div>'; });
}

function renderNotesModal(notes){
    const today = new Date().toISOString().split('T')[0];
    let html = `<div class="nt-add-box">
        <div class="nt-add-title"><i class="fa-solid fa-plus-circle"></i> Add New Note</div>
        <div class="nt-form-row">
            <div class="nt-fg"><label>Date</label><input type="date" id="ntDate" value="${today}"></div>
            <div class="nt-fg"><label>Note</label><textarea id="ntText" placeholder="Enter note…" rows="2"></textarea></div>
        </div>
        <button class="btn-add-nt" id="ntAddBtn" onclick="addNote()"><i class="fa-solid fa-plus"></i> Add Note</button>
    </div>`;

    html += `<div class="nt-table-title"><i class="fa-solid fa-table-list"></i> Notes <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;" id="nt-count-badge">${notes.length}</span></div>`;

    if(notes.length === 0){
        html += `<div class="nt-empty" id="ntEmptyMsg"><i class="fa-solid fa-note-sticky"></i><div style="font-size:13px;font-weight:600;color:#6b7280;">No notes yet</div></div>`;
    } else {
        html += `<table class="nt-table" id="ntTable"><thead><tr><th style="width:100px;">Date</th><th>Note</th><th style="width:130px;">Added</th></tr></thead><tbody id="ntTbody">`;
        notes.forEach(n=>{ html += buildNoteRow(n); });
        html += `</tbody></table>`;
    }

    document.getElementById('nt-body').innerHTML = html;
}

function buildNoteRow(n){
    return `<tr>
        <td><strong>${n.note_date_fmt||n.note_date||'—'}</strong></td>
        <td>${escapeHtml(n.note)}</td>
        <td style="font-size:10.5px;color:#9ca3af;">${n.created_fmt||''}</td>
    </tr>`;
}

function addNote(){
    const dateInput = document.getElementById('ntDate');
    const textInput = document.getElementById('ntText');
    const btn = document.getElementById('ntAddBtn');
    const date = dateInput.value;
    const note = textInput.value.trim();
    if(!date){ showToast('Please select a date','error'); dateInput.focus(); return; }
    if(!note){ showToast('Please enter a note','error'); textInput.focus(); return; }
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding...';
    const fd = new FormData();
    fd.append('detail_id', _ntDetailId); fd.append('note_date', date); fd.append('note', note);
    fetch('credit_bill_summary2.php?ajax=note_add',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(data=>{
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-plus"></i> Add Note';
        if(!data.success){ showToast(data.error||'Failed to add note','error'); return; }
        showToast('Note added','success');
        loadNotes(_ntDetailId);
        markRowHasNote(_ntDetailId);
    })
    .catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-plus"></i> Add Note'; showToast('Network error','error'); });
}

function markRowHasNote(detailId){
    const btn = document.querySelector('#trow-'+detailId+' .rn-notes-btn');
    if(btn) btn.classList.add('has-notes');
}

function closeNotesModal(){ document.getElementById('notesModal').classList.remove('open'); }

function escapeHtml(s){
    if(!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ═══════════════════════════════════════
   KEYBOARD CLOSE
═══════════════════════════════════════ */
document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){
        if(document.getElementById('remarkModal').classList.contains('open'))         { closeRemarkModal(); }
        else if(document.getElementById('notesModal').classList.contains('open'))     { closeNotesModal(); }
        else if(document.getElementById('cnModal').classList.contains('open'))            { closeCNModal(); }
        else if(document.getElementById('payHistoryModal').classList.contains('open')){ closePayHistory(); }
        else { closeBillModal(); closeLightbox(); }
    }
});

/* ═══════════════════════════════════════
   BILL MODAL
═══════════════════════════════════════ */
let _detailId=null, _modalData=null;

function openBillModal(detailId, invNum, custName) {
    const modal=document.getElementById('billModal');
    const closeBtn=document.getElementById('billModalClose');
    if(modal.parentElement!==document.body) document.body.appendChild(modal);
    if(closeBtn&&closeBtn.parentElement!==document.body) document.body.appendChild(closeBtn);
    _detailId=detailId;
    document.getElementById('bm-sub').textContent=invNum+' — '+custName;
    document.getElementById('bm-badges').innerHTML='';
    document.getElementById('bm-body').innerHTML='<div class="bm-loading"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading...</div>';
    modal.classList.add('open');
    if(closeBtn) closeBtn.classList.add('show');
    document.body.classList.add('modal-open');
    loadModalData(detailId);
}

function closeBillModal(){
    const modal=document.getElementById('billModal');
    const closeBtn=document.getElementById('billModalClose');
    modal.classList.remove('open');
    if(closeBtn) closeBtn.classList.remove('show');
    document.body.classList.remove('modal-open');
    _detailId=null; _modalData=null;
}

function loadModalData(detailId){
    fetch('save_credit_bill_verify.php?action=load&detail_id='+detailId)
    .then(r=>r.json())
    .then(data=>{ if(!data.success){showBodyError(data.error||'Load failed');return;} _modalData=data; renderModal(data); })
    .catch(()=>showBodyError('Network error'));
}

function showBodyError(msg){
    document.getElementById('bm-body').innerHTML=`<div class="bm-loading" style="color:#dc2626;"><i class="fa-solid fa-circle-exclamation"></i> ${msg}</div>`;
}

function renderModal(data) {
    const d    = data.detail;
    const crs  = data.credit_requests || [];
    const imgs = data.bill_images || [];
    const isEmg = crs.length > 0;
    const detailVerified = (d.bill_verified !== null && d.bill_verified !== undefined && d.bill_verified !== '')
        ? parseInt(d.bill_verified) : null;

    let badges = '';
    if (isEmg) badges += `<span style="background:#dc2626;color:#fff;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;"><i class="fa-solid fa-bolt"></i> Emergency</span>`;
    badges += `<span style="background:#1a1a1a;color:#666;padding:2px 9px;border-radius:20px;font-size:11px;">${d.t_code}</span>`;
    if (d.delivery_date) {
        const df  = new Date(d.delivery_date + 'T00:00:00');
        const fmt = df.toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'});
        const agD = Math.max(0, Math.floor((Date.now() - df.getTime()) / 86400000));
        const agC = agD >= 90 ? '#f87171' : agD >= 60 ? '#fb923c' : agD >= 30 ? '#fbbf24' : '#4ade80';
        badges += `<span style="background:#111;color:#555;border:1px solid #2a2a2a;padding:2px 9px;border-radius:20px;font-size:11px;"><i class="fa-solid fa-calendar-day"></i> ${fmt}</span>`;
        badges += `<span style="color:${agC};background:#111;border:1px solid ${agC}44;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;"><i class="fa-solid fa-hourglass-half"></i> ${agD}d</span>`;
    }
    document.getElementById('bm-badges').innerHTML = badges;

   const net     = parseFloat(d.adjust_net_value || d.net_value || 0);
const tr      = document.getElementById('trow-' + _detailId);
const paid    = tr ? parseFloat(tr.dataset.paid || 0) : parseFloat(d.total_paid || 0);
const totalCN = tr ? parseFloat(tr.dataset.cn   || 0) : parseFloat(d.total_cn   || 0);
const balance = net - paid - totalCN;
    const otherInvoices    = data.other_invoices || [];
// With — filter out current invoice in case it's included:
const totalOutstanding = otherInvoices
    .filter(i => i.invoice_num !== d.invoice_num)
    .reduce((s, i) => s + parseFloat(i.balance || 0), 0) + balance;    const allCrDocs = [];
    crs.forEach(cr => { if (cr.doc_paths) cr.doc_paths.split('||').filter(p => p).forEach(p => allCrDocs.push(p)); });
    const sig     = d.customer_signature || '';
    const seal    = d.customer_seal || '';
    const firstImg = imgs.length > 0 ? imgs[0] : null;
    const vIsVerified   = detailVerified === 1;
    const vIsUnverified = detailVerified === 0;
    const vStatusHtml   = vIsVerified
        ? `<span class="bm-verify-status is-verified"><i class="fa-solid fa-circle-check"></i> Verified</span>`
        : vIsUnverified
        ? `<span class="bm-verify-status is-unverified"><i class="fa-solid fa-circle-xmark"></i> Not Verified</span>`
        : `<span class="bm-verify-status is-pending"><i class="fa-regular fa-clock"></i> Pending</span>`;

    let otherInvRows = '';
    if (otherInvoices.length === 0) {
        otherInvRows = `<div style="font-size:11px;color:#333;font-style:italic;">No other outstanding invoices.</div>`;
    } else {
        otherInvoices.forEach(inv => {
            otherInvRows += `<div class="bm-other-inv-item"><span class="inv-no">${inv.invoice_num}</span><span class="inv-bal">Rs.&nbsp;${parseFloat(inv.balance||0).toLocaleString('en-US',{minimumFractionDigits:2})}</span></div>`;
        });
    }

    const leftHtml = `
    <div class="bm-left">
      <div class="bm-info-block">
        <div class="bm-info-row"><span class="lbl">T Code</span><span class="val mono">${d.t_code || '—'}</span></div>
        <div class="bm-info-row"><span class="lbl">Invoice No</span><span class="val mono">${d.invoice_num || '—'}</span></div>
        <div class="bm-info-row"><span class="lbl">Customer Name</span><span class="val">${d.display_name || d.customer_name || '—'}</span></div>
        <div class="bm-info-row">
          <span class="lbl">Invoice No — Balance</span>
          <span class="val ${balance > 0 ? 'amber' : 'green'}">${d.invoice_num || '—'} — Rs.&nbsp;${balance.toLocaleString('en-US',{minimumFractionDigits:2})}</span>
        </div>
      </div>
      <div class="bm-other-inv">
        <div class="bm-other-inv-title"><i class="fa-solid fa-file-invoice"></i> Other Credit Invoices</div>
        ${otherInvRows}
        <div class="bm-other-total">
          <span class="ot-label">Total Outstanding (with this)</span>
          <span class="ot-val">Rs.&nbsp;${totalOutstanding.toLocaleString('en-US',{minimumFractionDigits:2})}</span>
        </div>
      </div>
      <div class="bm-upload-area">
        <button class="btn-upload-pill" onclick="document.getElementById('billFileInput').click();"><i class="fa-solid fa-upload"></i> Upload Image/Images</button>
        <div id="uploadProgress"><i class="fa-solid fa-spinner fa-spin"></i> Uploading…</div>
        <div class="bm-thumbs" id="billThumbsRow">
          ${imgs.map((img, idx) => `
            <div class="bm-thumb ${idx===0?'active':''}" id="billthumb-${img.id}" onclick="switchBillImg('${img.file_path}',${img.id})">
              <img src="${img.file_path}" alt="">
              <button class="bm-thumb-del" onclick="event.stopPropagation();deleteBillImage(${img.id})"><i class="fa-solid fa-xmark"></i></button>
            </div>`).join('')}
        </div>
        <input type="file" id="billFileInput" accept="image/*" style="display:none" onchange="uploadBillImage(this)">
        <div class="bm-info-row" style="margin-top:6px;">
          <span class="lbl">To Be Delivery</span>
          <span class="val ${d.to_be_delivery == 1 ? 'amber' : ''}">
            ${d.to_be_delivery == 1
              ? '<i class="fa-solid fa-truck" style="color:#fbbf24;"></i> Yes — Pending Delivery'
              : '<i class="fa-solid fa-circle-check" style="color:#4ade80;"></i> No'}
          </span>
        </div>
      </div>
      <div class="bm-verify-row">
        <div id="cvStatusDisplay">${vStatusHtml}</div>
        <div class="bm-verify-btns">
          <button class="verify-btn btn-verify ${vIsVerified?'active-v':''}" id="vbtn-main" onclick="setDetailVerify(1)"><i class="fa-solid fa-circle-check"></i> Verified</button>
          <button class="verify-btn btn-unverify ${vIsUnverified?'active-u':''}" id="uvbtn-main" onclick="setDetailVerify(0)"><i class="fa-solid fa-circle-xmark"></i> Not Verified</button>
        </div>
      </div>
    </div>`;

    const middleHtml = `
    <div class="bm-middle" id="billDisplayArea">
      ${firstImg
        ? `<img src="${firstImg.file_path}" alt="Invoice" id="billMainImg" onclick="openLightbox('${firstImg.file_path}')">`
        : `<div class="no-bill-msg"><i class="fa-solid fa-file-image"></i><span>No bill uploaded yet</span></div>`}
    </div>`;

    const crDoc1 = allCrDocs[0] || null;
    const rightHtml = `
    <div class="bm-right">
      <div class="bm-right-header">
        <span class="bm-right-title">Emergency Credit Bill</span>
        ${isEmg ? `<span class="bm-cr-count-badge"><i class="fa-solid fa-bolt"></i> ${crs.length} Request${crs.length>1?'s':''}</span>` : `<span style="font-size:10px;color:#333;">No requests</span>`}
      </div>
      <div class="bm-right-body">
        <div class="bm-emg-doc" onclick="${crDoc1?`openLightbox('${crDoc1}')`:''}" style="${!crDoc1?'cursor:default;':''}">
          ${crDoc1 ? `<img src="${crDoc1}" alt="Credit Request Doc">` : `<div class="bm-emg-doc-empty"><i class="fa-solid fa-file-circle-question"></i><span>No Document</span></div>`}
        </div>
        <div class="bm-small-docs"></div>
        <div class="bm-sig-seal">
          <div class="bm-sig-card">
            ${sig ? `<div class="zoom-wrap" style="height:88px;" data-src="${sig}" onwheel="zoomAt(event,this)" onmousedown="panStart(event,this)" onmousemove="panMove(event,this)" onmouseup="panEnd(this)" onmouseleave="panEnd(this)"><img class="zoomable" src="${sig}" alt="Signature" data-scale="1" data-ox="0" data-oy="0"><span class="zoom-hint">scroll·drag</span></div>` : `<div class="bm-sig-card-empty" style="display:flex;align-items:center;justify-content:center;flex-direction:column;gap:4px;"><i class="fa-solid fa-pen-fancy" style="color:#333;font-size:20px;"></i><span style="font-size:10px;color:#333;">No Signature</span></div>`}
            <div class="bm-sig-label">Customer Signature</div>
          </div>
          <div class="bm-sig-card">
            ${seal ? `<div class="zoom-wrap" style="height:88px;" data-src="${seal}" onwheel="zoomAt(event,this)" onmousedown="panStart(event,this)" onmousemove="panMove(event,this)" onmouseup="panEnd(this)" onmouseleave="panEnd(this)"><img class="zoomable" src="${seal}" alt="Seal" data-scale="1" data-ox="0" data-oy="0"><span class="zoom-hint">scroll·drag</span></div>` : `<div class="bm-sig-card-empty" style="display:flex;align-items:center;justify-content:center;flex-direction:column;gap:4px;"><i class="fa-solid fa-stamp" style="color:#333;font-size:20px;"></i><span style="font-size:10px;color:#333;">No Seal</span></div>`}
            <div class="bm-sig-label">Seal</div>
          </div>
        </div>
      </div>
    </div>`;

    document.getElementById('bm-body').innerHTML = leftHtml + middleHtml + rightHtml;
}

function handleDrop(e){e.preventDefault();document.getElementById('uploadZone')?.classList.remove('drag');const file=e.dataTransfer.files[0];if(file)doUpload(file);}
function uploadBillImage(input){if(input.files[0])doUpload(input.files[0]);}

function doUpload(file) {
    const prog = document.getElementById('uploadProgress');
    if (prog) prog.style.display = 'flex';
    const fd = new FormData();
    fd.append('action', 'upload_bill'); fd.append('detail_id', _detailId); fd.append('bill_image', file);
    fetch('save_credit_bill_verify.php', {method:'POST', body:fd})
    .then(r => r.json())
    .then(data => {
        if (prog) prog.style.display = 'none';
        if (data.success) {
            const da = document.getElementById('billDisplayArea');
            if (da) da.innerHTML = `<img src="${data.file_path}" alt="Invoice" id="billMainImg" onclick="openLightbox('${data.file_path}')">`;
            const tr = document.getElementById('billThumbsRow');
            if (tr) {
                tr.querySelectorAll('.bm-thumb').forEach(el => el.classList.remove('active'));
                const td = document.createElement('div');
                td.className = 'bm-thumb active'; td.id = 'billthumb-' + data.image_id;
                td.onclick = () => switchBillImg(data.file_path, data.image_id);
                td.innerHTML = `<img src="${data.file_path}" alt=""><button class="bm-thumb-del" onclick="event.stopPropagation();deleteBillImage(${data.image_id})"><i class="fa-solid fa-xmark"></i></button>`;
                tr.prepend(td);
            }
            updateTableBillCount(1);
            showToast('Bill uploaded successfully', 'success');
        } else showToast(data.error || 'Upload failed', 'error');
    })
    .catch(() => { if (prog) prog.style.display = 'none'; showToast('Network error', 'error'); });
}

function switchBillImg(src, imgId) {
    const mi = document.getElementById('billMainImg');
    if (mi) { mi.src = src; mi.onclick = () => openLightbox(src); }
    document.querySelectorAll('.bm-thumb').forEach(el => el.classList.remove('active'));
    const t = document.getElementById('billthumb-' + imgId);
    if (t) t.classList.add('active');
}

function deleteBillImage(imgId) {
    if (!confirm('Delete this bill image?')) return;
    const fd = new FormData(); fd.append('action', 'delete_bill_image'); fd.append('image_id', imgId);
    fetch('save_credit_bill_verify.php', {method:'POST', body:fd})
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const thumb = document.getElementById('billthumb-' + imgId);
            if (thumb) thumb.remove();
            const nt = document.querySelector('.bm-thumb');
            const da = document.getElementById('billDisplayArea');
            if (nt) { nt.classList.add('active'); const ns = nt.querySelector('img')?.src || ''; if (da) da.innerHTML = `<img src="${ns}" alt="Invoice" id="billMainImg" onclick="openLightbox('${ns}')">`; }
            else { if (da) da.innerHTML = `<div class="no-bill-msg"><i class="fa-solid fa-file-image"></i><span>No bill uploaded yet</span></div>`; }
            updateTableBillCount(-1);
            showToast('Image deleted', 'success');
        } else showToast(data.error || 'Delete failed', 'error');
    });
}

function updateTableBillCount(delta) {
    const cell = document.getElementById('bill-cell-' + _detailId);
    if (!cell) return;
    let badge = cell.querySelector('.bill-count-badge');
    if (!badge) { badge = document.createElement('span'); badge.className = 'bill-count-badge'; badge.innerHTML = '<i class="fa-solid fa-images"></i> 0'; const ar = cell.querySelector('.actions-row'); if (ar) ar.appendChild(badge); }
    const cur = parseInt(badge.textContent.trim()) || 0;
    badge.innerHTML = `<i class="fa-solid fa-images"></i> ${Math.max(0, cur + delta)}`;
}

function setDetailVerify(verified) {
    const fd = new FormData();
    fd.append('action', 'set_detail_verify'); fd.append('detail_id', _detailId); fd.append('verified', verified);
    fetch('save_credit_bill_verify.php', {method:'POST', body:fd})
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const vbtn=document.getElementById('vbtn-main'), uvbtn=document.getElementById('uvbtn-main'), disp=document.getElementById('cvStatusDisplay');
            if (verified === 1) { vbtn?.classList.add('active-v'); uvbtn?.classList.remove('active-u'); if (disp) disp.innerHTML=`<span class="bm-verify-status is-verified"><i class="fa-solid fa-circle-check"></i> Verified</span>`; showToast('Bill marked as Verified ✓', 'success'); }
            else { uvbtn?.classList.add('active-u'); vbtn?.classList.remove('active-v'); if (disp) disp.innerHTML=`<span class="bm-verify-status is-unverified"><i class="fa-solid fa-circle-xmark"></i> Not Verified</span>`; showToast('Bill marked as Not Verified', 'error'); }
            updateTableVerifyBadge(verified);
        } else showToast(data.error || 'Update failed', 'error');
    });
}

function updateTableVerifyBadge(status) {
    const vs = document.getElementById('vstatus-' + _detailId);
    if (!vs) return;
    if (status === 1) { vs.className='verified-badge'; vs.innerHTML='<i class="fa-solid fa-circle-check"></i> Verified'; }
    else { vs.className='unverified-badge'; vs.innerHTML='<i class="fa-solid fa-clock"></i> Not Verified'; }
}

function openLightbox(src) {
    const lb = document.getElementById('lightbox');
    document.body.appendChild(lb);
    lb.style.cssText = 'display:flex !important;position:fixed !important;inset:0 !important;z-index:2147483647 !important;background:rgba(0,0,0,.97) !important;align-items:center !important;justify-content:center !important;';
    document.getElementById('lightbox-img').src = src;
}
function closeLightbox() {
    const lb = document.getElementById('lightbox');
    lb.style.cssText = ''; lb.classList.remove('open');
    document.getElementById('lightbox-img').src = '';
}

function zoomAt(e,wrap){e.preventDefault();const img=wrap.querySelector('img.zoomable');let sc=parseFloat(img.dataset.scale)||1,ox=parseFloat(img.dataset.ox)||0,oy=parseFloat(img.dataset.oy)||0;const rect=wrap.getBoundingClientRect(),mx=e.clientX-rect.left,my=e.clientY-rect.top,factor=e.deltaY<0?1.10:0.91,ns=Math.min(Math.max(sc*factor,1),4);let nox=mx-(mx-ox)*(ns/sc),noy=my-(my-oy)*(ns/sc);if(ns<=1){nox=0;noy=0;}if(nox>0)nox=0;if(noy>0)noy=0;const mox=rect.width*(1-ns),moy=rect.height*(1-ns);if(nox<mox)nox=mox;if(noy<moy)noy=moy;img.dataset.scale=ns;img.dataset.ox=nox;img.dataset.oy=noy;img.style.transformOrigin='0 0';img.style.transform=`translate(${nox}px,${noy}px) scale(${ns})`;wrap.style.cursor=ns>1?'grab':'zoom-in';wrap.onclick=ns>1?null:()=>openLightbox(wrap.dataset.src);}
let _panActive=false,_panSx=0,_panSy=0,_panOx=0,_panOy=0,_panWrap=null;
function panStart(e,wrap){const img=wrap.querySelector('img.zoomable');const sc=parseFloat(img.dataset.scale)||1;if(sc<=1){openLightbox(wrap.dataset.src);return;}e.preventDefault();_panActive=true;_panSx=e.clientX;_panSy=e.clientY;_panOx=parseFloat(img.dataset.ox)||0;_panOy=parseFloat(img.dataset.oy)||0;_panWrap=wrap;wrap.classList.add('panning');}
function panMove(e,wrap){if(!_panActive||_panWrap!==wrap)return;const img=wrap.querySelector('img.zoomable');const rect=wrap.getBoundingClientRect();const sc=parseFloat(img.dataset.scale)||1;let ox=_panOx+(e.clientX-_panSx),oy=_panOy+(e.clientY-_panSy);if(ox>0)ox=0;if(oy>0)oy=0;const mx=rect.width*(1-sc),my=rect.height*(1-sc);if(ox<mx)ox=mx;if(oy<my)oy=my;img.dataset.ox=ox;img.dataset.oy=oy;img.style.transform=`translate(${ox}px,${oy}px) scale(${sc})`;}
function panEnd(wrap){if(_panWrap===wrap){_panActive=false;_panWrap=null;}wrap.classList.remove('panning');}

/* ═══════════════════════════════════════
   TOAST
═══════════════════════════════════════ */
function showToast(msg,type='success'){
    let t=document.getElementById('__toast');
    if(!t){t=document.createElement('div');t.id='__toast';t.style='position:fixed;bottom:24px;right:24px;z-index:99999;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.2);transform:translateY(100px);transition:transform .3s;display:flex;align-items:center;gap:8px;';document.body.appendChild(t);}
    t.style.background=type==='success'?'#166534':'#dc2626';t.style.color='#fff';
    t.textContent=msg;t.style.transform='translateY(0)';
    clearTimeout(t._timer);t._timer=setTimeout(()=>{t.style.transform='translateY(100px)';},3500);
}

/* ═══════════════════════════════════════
   PRINT VIEW — passes all filters
═══════════════════════════════════════ */
function openPrintView(){
    const params=new URLSearchParams(window.location.search);
    params.set('printview','1');
    window.open('credit_bill_summary2.php?'+params.toString(),'_blank');
}

// ── NEW: opens the standalone B&W portrait print page ──
function openBWPrint(){
    const params = new URLSearchParams(window.location.search);
    window.open('credit_bill_print_bw.php?' + params.toString(), '_blank');
}
/* ═══════════════════════════════════════
   CSV EXPORT — includes TBD column
═══════════════════════════════════════ */
function exportCSV(){
    const rows=document.querySelectorAll('#mainTbody tr[data-search]:not(.row-hidden)');
    if(!rows.length){alert('No data to export.');return;}
    const asAtLabel=PAGE_AS_AT?' (as at '+PAGE_AS_AT+')':'';
const headers = ['No','T Code','SR Code','Route Code','Route Name','Customer','Invoice Number','Del. Date','Aging (Days)','To Be Delivery','Ikea Value','Cash Paid'+asAtLabel,'Cheque Paid'+asAtLabel,'Credit Notes','Balance'+asAtLabel,'Type','Bill Verified Status'];
    const lines=[headers.join(',')];
  rows.forEach((tr, i) => {
    const cells = tr.querySelectorAll('td');
    const esc = v => '"' + (v || '').replace(/"/g, '""').replace(/\s+/g, ' ').trim() + '"';
    const isSpecial = tr.classList.contains('special-row') ? 'Special' : 'Normal';
    const isTbd = parseInt(tr.dataset.tbd || 0) ? 'Yes' : 'No';
    const routeDivs = cells[4]?.querySelectorAll('div');

    // Extract verified status from the badge element in the table row
    const detailId = tr.id?.replace('trow-', '');
    const vBadge = document.getElementById('vstatus-' + detailId);
    let verifiedStatus = 'Pending';
    if (vBadge) {
        if (vBadge.classList.contains('verified-badge'))   verifiedStatus = 'Verified';
        else if (vBadge.classList.contains('unverified-badge')) verifiedStatus = 'Not Verified';
    }

    lines.push([
        i + 1,
        esc(cells[2]?.textContent),
        esc(cells[3]?.textContent),
        esc(routeDivs?.[0]?.textContent),
        esc(routeDivs?.[1]?.textContent),
        esc(cells[5]?.querySelector('div')?.textContent || cells[5]?.textContent),
        esc(cells[6]?.textContent),
        esc(tr.dataset.date || ''),
        parseInt(tr.dataset.aging || 0),
        isTbd,
        parseFloat(tr.dataset.net || 0).toFixed(2),
        parseFloat(tr.dataset.cash || 0).toFixed(2),
        parseFloat(tr.dataset.cheque || 0).toFixed(2),
        parseFloat(tr.dataset.cn || 0).toFixed(2),
        parseFloat(tr.dataset.balance || 0).toFixed(2),
        isSpecial,
        esc(verifiedStatus)   // ← new column
    ].join(','));
});
    const blob=new Blob([lines.join('\n')],{type:'text/csv'});
    const url=URL.createObjectURL(blob);
    const a=document.createElement('a');a.href=url;
    a.download='credit_bill_summary_<?php echo date("Ymd_Hi"); ?>.csv';
    a.click();URL.revokeObjectURL(url);
}


/* ═══════════════════════════════════════
   MAGNIFIER LENS FOR BILL MODAL
═══════════════════════════════════════ */
(function(){
  let _magZoom = 3;
  let _magLensSize = 150;
  let _magAttached = false;

  function attachMagnifier() {
    const area = document.getElementById('billDisplayArea');
    if (!area || _magAttached) return;
    _magAttached = true;

    // Create lens
    let lens = document.getElementById('billMagnifierLens');
    if (!lens) {
      lens = document.createElement('div');
      lens.id = 'billMagnifierLens';
      area.appendChild(lens);
    }

    // Create zoom bar
    let bar = document.getElementById('billZoomBar');
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'billZoomBar';
      bar.innerHTML = `<i class="fa-solid fa-magnifying-glass-plus" style="font-size:11px;"></i>
        <input type="range" id="billZoomSlider" min="1.5" max="6" step="0.5" value="3">
        <span id="billZoomLabel">3×</span>`;
      area.appendChild(bar);

      bar.querySelector('#billZoomSlider').addEventListener('input', function() {
        _magZoom = parseFloat(this.value);
        bar.querySelector('#billZoomLabel').textContent = _magZoom + '×';
      });
    }

    area.addEventListener('mouseenter', () => { bar.style.display = 'flex'; });
    area.addEventListener('mouseleave', () => {
      lens.style.display = 'none';
      bar.style.display = 'none';
      area.style.cursor = 'default';
    });

    area.addEventListener('mousemove', function(e) {
      const img = area.querySelector('#billMainImg');
      if (!img) { lens.style.display = 'none'; return; }

      const rect = area.getBoundingClientRect();
      const x = e.clientX - rect.left;
      const y = e.clientY - rect.top;

      // Stay within area bounds
      if (x < 0 || y < 0 || x > rect.width || y > rect.height) {
        lens.style.display = 'none'; return;
      }

      lens.style.display = 'block';
      area.style.cursor = 'none';

      lens.style.left = x + 'px';
      lens.style.top  = y + 'px';

      const imgRect = img.getBoundingClientRect();
      const imgX = e.clientX - imgRect.left;
      const imgY = e.clientY - imgRect.top;

      const bgX = -(imgX * _magZoom - _magLensSize / 2);
      const bgY = -(imgY * _magZoom - _magLensSize / 2);

      lens.style.backgroundImage    = `url('${img.src}')`;
      lens.style.backgroundSize     = `${imgRect.width * _magZoom}px ${imgRect.height * _magZoom}px`;
      lens.style.backgroundPosition = `${bgX}px ${bgY}px`;
    });
  }

  // Hook into renderModal — run after the modal HTML is injected
  const _origRenderModal = window.renderModal;
  if (typeof _origRenderModal === 'function') {
    window.renderModal = function(data) {
      _magAttached = false;
      _origRenderModal(data);
      setTimeout(attachMagnifier, 100);
    };
  }

  // Also expose for manual call
  window.attachBillMagnifier = attachMagnifier;
})();
</script>

<?php include 'footer.php'; ?>