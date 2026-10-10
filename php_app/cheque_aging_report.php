<?php
/**
 * cheque_aging_report.php — Cheque Aging Analysis Report
 *
 * Aging Type: delivery_to_cheque : age = DATEDIFF(cheque_date, delivery_date)
 * Excludes cleared cheques.
 * Excludes fully-settled sent_back and returned cheques.
 * Totals based on unsettled balances.
 * Row colours: RED if age_days > credit_days (cheque customers), YELLOW if special credit customer.
 * Received Date filter: from / to on COALESCE(ch.received_date, ip.payment_date)
 * (the same "Received Date" shown in the table), compared by date only.
 */

if (session_status() === PHP_SESSION_NONE) session_start();

/* ═══════════════════════════════════════════════════
   AJAX — must be before include 'header.php'
═══════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'aging_data') {
    include_once 'config.php';
    header('Content-Type: application/json');

    $as_at    = trim($_GET['as_at']      ?? date('Y-m-d'));
    $f_status = trim($_GET['status']     ?? '');
    $f_sr     = trim($_GET['sr_code']    ?? '');
    $f_bank   = trim($_GET['bank_code']  ?? '');
    $f_pm     = trim($_GET['pay_mode']   ?? '');
    $f_search = trim($_GET['q']          ?? '');
    $f_bucket = trim($_GET['bucket']     ?? '');
    $f_rfrom  = trim($_GET['recv_from']  ?? '');
    $f_rto    = trim($_GET['recv_to']    ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $as_at)) $as_at = date('Y-m-d');

    /* Received date range — only real YYYY-MM-DD dates are used; swap if entered backwards */
    $valid_date = function ($d) {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    };
    if (!$valid_date($f_rfrom)) $f_rfrom = '';
    if (!$valid_date($f_rto))   $f_rto   = '';
    if ($f_rfrom !== '' && $f_rto !== '' && $f_rfrom > $f_rto) { $tmp = $f_rfrom; $f_rfrom = $f_rto; $f_rto = $tmp; }
    $as_at_esc = mysqli_real_escape_string($conn, $as_at);

    /* Age expression: Delivery Date → Cheque Date */
    $age_expr  = "DATEDIFF(ch.cheque_date, fs.delivery_date)";
    $age_label = "Delivery Date → Cheque Date";

    $where = [
        "fs.delivery_date IS NOT NULL",
        "fs.delivery_date != '0000-00-00'",
        "fs.delivery_date <= '$as_at_esc'",
        "ch.status != 'cleared'",
        "NOT (ch.status = 'sent_back' AND COALESCE(ch.sb_settled,0) = 1)",
        "NOT (ch.status = 'returned'  AND COALESCE(ch.return_settled,0) = 1)",
    ];
    if ($f_status) $where[] = "ch.status = '" . mysqli_real_escape_string($conn, $f_status) . "'";
    if ($f_sr)     $where[] = "fs.sr_code = '" . mysqli_real_escape_string($conn, $f_sr) . "'";
    if ($f_bank)   $where[] = "ch.bank_code = '" . mysqli_real_escape_string($conn, $f_bank) . "'";
    if ($f_pm)     $where[] = "COALESCE(c.payment_mode,'cash') = '" . mysqli_real_escape_string($conn, $f_pm) . "'";
    /* Received Date = cheque received date, else the payment date (same value shown in the table) */
    $recv_expr = "DATE(COALESCE(ch.received_date, ip.payment_date))";
    if ($f_rfrom !== '') $where[] = "$recv_expr >= '" . mysqli_real_escape_string($conn, $f_rfrom) . "'";
    if ($f_rto   !== '') $where[] = "$recv_expr <= '" . mysqli_real_escape_string($conn, $f_rto) . "'";
    if ($f_search !== '') {
        $s = '%' . mysqli_real_escape_string($conn, $f_search) . '%';
        $where[] = "(ch.cheque_no LIKE '$s'
            OR FORMAT(ch.total_amount,2) LIKE '$s'
            OR CAST(ch.total_amount AS CHAR) LIKE '$s'
            OR ch.t_code LIKE '$s'
            OR ch.bank_code LIKE '$s'
            OR COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) LIKE '$s')";
    }
    $where_sql = implode(' AND ', $where);

    $bmap = [
        '0'     => "($age_expr) <= 0",
        '1-7'   => "($age_expr) BETWEEN 1 AND 7",
        '8-14'  => "($age_expr) BETWEEN 8 AND 14",
        '15-21' => "($age_expr) BETWEEN 15 AND 21",
        '22-28' => "($age_expr) BETWEEN 22 AND 28",
        '29-35' => "($age_expr) BETWEEN 29 AND 35",
        '35+'   => "($age_expr) > 35",
    ];
    $having_extra = '';
    if ($f_bucket !== '' && isset($bmap[$f_bucket])) {
        $having_extra = 'HAVING ' . $bmap[$f_bucket];
    }

    $sql = "SELECT
        ch.id, ch.cheque_no, ch.cheque_date, ch.total_amount,
        ch.status, ch.bank_code, ch.bank_name, ch.branch_code, ch.branch_name,
        ch.t_code, ch.cheque_mode, ch.verified,
        COALESCE(ch.received_date, ip.payment_date) AS received_date,
        fs.sr_code, fs.delivery_date,
        COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) AS customer_name,
        COALESCE(c.payment_mode, 'cash')                                AS payment_mode,
        COALESCE(c.credit_days, 0)                                      AS credit_days,
        COALESCE(c.special_credit_policy_days, '')                      AS special_credit_policy_days,
        CASE WHEN cr.detail_id IS NOT NULL THEN 1 ELSE 0 END            AS is_special,
        ($age_expr)                                                      AS age_days,
        COALESCE(ch.sb_settled, 0)                                      AS sb_settled,
        COALESCE(ch.sb_settlement_amount, 0)                            AS sb_settlement_amount,
        COALESCE(ch.return_settled, 0)                                  AS return_settled,
        COALESCE(ch.settlement_amount, 0)                               AS return_settlement_amount
    FROM cheques ch
    INNER JOIN invoice_payments ip  ON ip.id  = ch.invoice_payment_id
    INNER JOIN field_summary   fs   ON fs.id  = ip.field_summary_id
    LEFT  JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
    LEFT  JOIN customers c ON c.t_code = ch.t_code
    LEFT  JOIN (
        SELECT field_summary_detail_id AS detail_id
        FROM   credit_requests
        GROUP  BY field_summary_detail_id
    ) cr ON cr.detail_id = ip.field_summary_detail_id
    WHERE $where_sql
    $having_extra
    ORDER BY age_days DESC, ch.cheque_date ASC";

    $res = mysqli_query($conn, $sql);
    if (!$res) { echo json_encode(['success' => false, 'error' => mysqli_error($conn)]); exit; }

    $rows        = [];
    $grand_total = 0.0;
    $grand_count = 0;
    $dist  = [
        '0'     => ['label' => '≤ 0 Days',   'count' => 0, 'amount' => 0.0],
        '1-7'   => ['label' => '1–7 Days',   'count' => 0, 'amount' => 0.0],
        '8-14'  => ['label' => '8–14 Days',  'count' => 0, 'amount' => 0.0],
        '15-21' => ['label' => '15–21 Days', 'count' => 0, 'amount' => 0.0],
        '22-28' => ['label' => '22–28 Days', 'count' => 0, 'amount' => 0.0],
        '29-35' => ['label' => '29–35 Days', 'count' => 0, 'amount' => 0.0],
        '35+'   => ['label' => '35+ Days',   'count' => 0, 'amount' => 0.0],
    ];

    $raw_rows = [];
    while ($row = mysqli_fetch_assoc($res)) { $raw_rows[] = $row; }

    $invoice_map = [];
    if (!empty($raw_rows)) {
        $cheque_nos_esc = array_map(
            fn($r) => "'" . mysqli_real_escape_string($conn, $r['cheque_no']) . "'",
            $raw_rows
        );
        $inv_sql = "SELECT ipc.cheque_no, ipc.invoice_num, ip2.field_summary_detail_id
                    FROM invoice_payment_cheques ipc
                    LEFT JOIN invoice_payments ip2 ON ip2.id = ipc.invoice_payment_id
                    WHERE ipc.cheque_no IN (" . implode(',', $cheque_nos_esc) . ")
                      AND ipc.is_reversed = 0
                    ORDER BY ipc.cheque_no, ipc.invoice_num";
        $inv_res = mysqli_query($conn, $inv_sql);
        if ($inv_res) {
            while ($inv = mysqli_fetch_assoc($inv_res)) {
                $invoice_map[$inv['cheque_no']][] = [
                    'num' => $inv['invoice_num'],
                    'id'  => $inv['field_summary_detail_id'],
                ];
            }
        }
    }

    foreach ($raw_rows as $row) {
        $age_raw  = $row['age_days'];
        $amt_face = floatval($row['total_amount']);
        $st       = strtolower(trim($row['status']));

        if ($st === 'sent_back') {
            $paid    = floatval($row['sb_settlement_amount']);
            $eff_amt = max(0, $amt_face - $paid);
        } elseif ($st === 'returned') {
            $paid    = floatval($row['return_settlement_amount']);
            $eff_amt = max(0, $amt_face - $paid);
        } else {
            $eff_amt = $amt_face;
        }

        $row['age_days']         = ($age_raw !== null) ? intval($age_raw) : null;
        $row['credit_days']      = intval($row['credit_days']);
        $row['is_special']       = intval($row['is_special']);
        $row['effective_amount'] = $eff_amt;
        $row['invoices']         = $invoice_map[$row['cheque_no']] ?? [];
        $rows[]                  = $row;
        $grand_total            += $eff_amt;
        $grand_count++;

        if ($age_raw === null) continue;
        $age = intval($age_raw);
        if      ($age <= 0)  { $dist['0']['count']++;     $dist['0']['amount']     += $eff_amt; }
        elseif  ($age <= 7)  { $dist['1-7']['count']++;   $dist['1-7']['amount']   += $eff_amt; }
        elseif  ($age <= 14) { $dist['8-14']['count']++;  $dist['8-14']['amount']  += $eff_amt; }
        elseif  ($age <= 21) { $dist['15-21']['count']++; $dist['15-21']['amount'] += $eff_amt; }
        elseif  ($age <= 28) { $dist['22-28']['count']++; $dist['22-28']['amount'] += $eff_amt; }
        elseif  ($age <= 35) { $dist['29-35']['count']++; $dist['29-35']['amount'] += $eff_amt; }
        else                 { $dist['35+']['count']++;   $dist['35+']['amount']   += $eff_amt; }
    }

    echo json_encode([
        'success'      => true,
        'rows'         => $rows,
        'distribution' => $dist,
        'grand_total'  => $grand_total,
        'grand_count'  => $grand_count,
        'as_at'        => $as_at,
        'aging_label'  => $age_label,
        'recv_from'    => $f_rfrom,
        'recv_to'      => $f_rto,
    ]);
    exit;
}

/* ═══════════════════════════════════════════════════
   PAGE SETUP
═══════════════════════════════════════════════════ */
include 'config.php';
include 'header.php';

$sr_res = mysqli_query($conn,
    "SELECT DISTINCT fs.sr_code
     FROM cheques ch
     INNER JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id
     INNER JOIN field_summary fs ON fs.id = ip.field_summary_id
     WHERE fs.sr_code IS NOT NULL AND fs.sr_code != ''
       AND ch.status != 'cleared'
       AND NOT (ch.status = 'sent_back' AND COALESCE(ch.sb_settled,0) = 1)
       AND NOT (ch.status = 'returned'  AND COALESCE(ch.return_settled,0) = 1)
     ORDER BY fs.sr_code");
$all_sr = [];
if ($sr_res) while ($r = mysqli_fetch_assoc($sr_res)) $all_sr[] = $r['sr_code'];

$bank_res = mysqli_query($conn,
    "SELECT DISTINCT bank_code FROM cheques
     WHERE bank_code IS NOT NULL AND bank_code != '' AND status != 'cleared'
       AND NOT (status = 'sent_back' AND COALESCE(sb_settled,0) = 1)
       AND NOT (status = 'returned'  AND COALESCE(return_settled,0) = 1)
     ORDER BY bank_code");
$all_banks = [];
if ($bank_res) while ($r = mysqli_fetch_assoc($bank_res)) $all_banks[] = $r['bank_code'];

$statuses      = ['pending', 'to_be_bank', 'deposited', 'sent_back', 'returned'];
$status_labels = [
    'pending'    => 'Pending',
    'to_be_bank' => 'To Be Bank',
    'deposited'  => 'Deposited',
    'sent_back'  => 'Sent Back (Unsettled)',
    'returned'   => 'Returned (Unsettled)',
];
?>
<link href="https://fonts.googleapis.com/css2?family=Barlow:wght@700;800;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box}
.page-title{font-size:22px;font-weight:800;color:#1e1b4b;margin:0 0 4px}
.page-subtitle{font-size:13px;color:#6b7280;margin:0}

/* ── Filter card ── */
.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;margin-bottom:18px;box-shadow:0 1px 4px rgba(0,0,0,.05);overflow:hidden}
.filter-header{display:flex;align-items:center;justify-content:space-between;padding:11px 18px;cursor:pointer;user-select:none;background:#fafafa;border-bottom:1px solid transparent;transition:border-color .2s}
.filter-header.open{border-bottom-color:#e5e5e5}
.filter-header:hover{background:#f3f4f6}
.filter-title{font-size:13px;font-weight:700;color:#374151;display:flex;align-items:center;gap:6px;margin:0}
.filter-toggle-icon{color:#6b7280;transition:transform .25s;font-size:12px}
.filter-toggle-icon.open{transform:rotate(180deg)}
.filter-body{display:none;padding:14px 18px 16px}
.filter-body.open{display:block}
.f-row{display:grid;gap:10px;align-items:end}
.f-row-4{grid-template-columns:1fr 1fr 1fr 1fr}
.ffg{display:flex;flex-direction:column;gap:5px}
.ffg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em}
.ffg input,.ffg select{border:1px solid #e5e5e5;border-radius:7px;padding:8px 11px;font-size:13px;font-family:inherit;color:#1f2937;width:100%;transition:border .2s}
.ffg input:focus,.ffg select:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1)}
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .2s;white-space:nowrap;text-decoration:none}
.btn-primary{background:#6366f1;color:#fff}.btn-primary:hover{background:#4f46e5}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover{background:#e8e8e8}
.btn-success{background:#16a34a;color:#fff}.btn-success:hover{background:#15803d}
.btn-sm{padding:5px 12px;font-size:11px}

/* ── Legend ── */
.row-legend{display:flex;align-items:center;gap:14px;margin-bottom:14px;font-size:12px;font-weight:600;color:#374151;flex-wrap:wrap}
.legend-dot{width:16px;height:16px;border-radius:4px;flex-shrink:0;border:1px solid rgba(0,0,0,.1)}
.legend-red{background:#fef2f2;border-color:#fecaca}
.legend-yellow{background:#fefce8;border-color:#fef08a}
.legend-white{background:#fff;border-color:#d1d5db}

/* ── As-At banner ── */
.as-at-banner{background:linear-gradient(135deg,#1e1b4b,#312e81);border-radius:10px;padding:10px 20px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.as-at-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(165,180,252,.8)}
.as-at-date{font-size:18px;font-weight:800;color:#fff;font-family:'Courier New',monospace}
.as-at-type{font-size:12px;color:#a5b4fc;background:rgba(255,255,255,.1);border-radius:20px;padding:4px 14px;border:1px solid rgba(165,180,252,.3);font-weight:600}
.as-at-total{text-align:right}
.as-at-total-lbl{font-size:10px;font-weight:700;text-transform:uppercase;color:rgba(165,180,252,.7);letter-spacing:.06em}
.as-at-total-val{font-size:18px;font-weight:800;color:#a5f3fc;font-family:'Courier New',monospace}

/* ── Distribution cards ── */
.dist-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:10px;margin-bottom:20px}
.dist-card{border-radius:10px;padding:12px 14px;cursor:pointer;transition:all .18s;border:2px solid transparent;position:relative;overflow:hidden}
.dist-card:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.12)}
.dist-card.active-bucket{border-color:#1e1b4b!important;box-shadow:0 0 0 3px rgba(30,27,75,.15)!important}
.dist-card.dc-0   {background:#f9fafb;border-color:#e5e7eb}
.dist-card.dc-1-7 {background:#eff6ff;border-color:#dbeafe}
.dist-card.dc-8-14{background:#ecfdf5;border-color:#d1fae5}
.dist-card.dc-15-21{background:#fef3c7;border-color:#fde68a}
.dist-card.dc-22-28{background:#fff7ed;border-color:#fed7aa}
.dist-card.dc-29-35{background:#fef2f2;border-color:#fecaca}
.dist-card.dc-35p {background:#fdf4ff;border-color:#e9d5ff}
.dist-card-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px}
.dc-0 .dist-card-lbl{color:#6b7280}.dc-1-7 .dist-card-lbl{color:#1d4ed8}.dc-8-14 .dist-card-lbl{color:#065f46}
.dc-15-21 .dist-card-lbl{color:#92400e}.dc-22-28 .dist-card-lbl{color:#c2410c}.dc-29-35 .dist-card-lbl{color:#b91c1c}.dc-35p .dist-card-lbl{color:#6b21a8}
.dist-card-count{font-size:18px;font-weight:700;line-height:1;margin-bottom:4px}
.dc-0 .dist-card-count{color:#374151}.dc-1-7 .dist-card-count{color:#1e40af}.dc-8-14 .dist-card-count{color:#065f46}
.dc-15-21 .dist-card-count{color:#92400e}.dc-22-28 .dist-card-count{color:#c2410c}.dc-29-35 .dist-card-count{color:#991b1b}.dc-35p .dist-card-count{color:#581c87}
.dist-card-amt{font-size:20px;font-weight:900;font-family:'Barlow',sans-serif;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dc-0 .dist-card-amt{color:#374151}.dc-1-7 .dist-card-amt{color:#1e40af}.dc-8-14 .dist-card-amt{color:#065f46}
.dc-15-21 .dist-card-amt{color:#92400e}.dc-22-28 .dist-card-amt{color:#c2410c}.dc-29-35 .dist-card-amt{color:#991b1b}.dc-35p .dist-card-amt{color:#581c87}
.dist-clear-pill{position:absolute;top:7px;right:8px;font-size:9px;font-weight:700;background:rgba(0,0,0,.08);color:inherit;border-radius:8px;padding:1px 6px;display:none;cursor:pointer}
.dist-card.active-bucket .dist-clear-pill{display:inline}

/* ── Table card ── */
.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #f0f0f0;background:#fafafa;flex-wrap:wrap;gap:8px}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap}
.p-violet{background:#ede9fe;color:#5b21b6}.p-blue{background:#dbeafe;color:#1e40af}
.p-green{background:#dcfce7;color:#166534}.p-amber{background:#fef3c7;color:#92400e}
.p-red{background:#fee2e2;color:#991b1b}.p-gray{background:#f3f4f6;color:#374151}
.search-wrap{position:relative;display:flex;align-items:center}
.search-wrap input{border:1.5px solid #e0e7ff;border-radius:8px;padding:7px 34px 7px 32px;font-size:12.5px;font-family:inherit;color:#1f2937;width:280px;transition:all .2s;outline:none}
.search-wrap input:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1);width:320px}
.search-wrap .si{position:absolute;left:10px;color:#9ca3af;font-size:12px;pointer-events:none}
.search-wrap .clr{position:absolute;right:8px;background:none;border:none;color:#9ca3af;font-size:11px;cursor:pointer;padding:2px;display:none}
.search-wrap .clr.show{display:block}
.dt-outer{overflow-x:auto;max-height:72vh;overflow-y:auto}

/* ── Sortable table ── */
.data-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1500px}
.data-table thead th{
    padding:8px 6px;text-align:left;font-weight:700;font-size:10.5px;color:#e0e7ff;
    background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1);
    position:sticky;top:0;z-index:10;cursor:pointer;user-select:none;
    transition:background .15s;
}
.data-table thead th:last-child{border-right:none}
.data-table thead th:hover{background:#312e81}
.data-table thead th.tr{text-align:right}
.data-table thead th.tc{text-align:center}
.data-table thead th .sort-ico{margin-left:4px;opacity:.4;font-size:9px;display:inline-block}
.data-table thead th.sort-asc .sort-ico,
.data-table thead th.sort-desc .sort-ico{opacity:1}
.data-table thead th.sort-asc .sort-ico::after{content:'▲'}
.data-table thead th.sort-desc .sort-ico::after{content:'▼'}
.data-table thead th:not(.sort-asc):not(.sort-desc) .sort-ico::after{content:'⇅'}

.data-table tbody tr{border-bottom:1px solid #f0f2f5;transition:background .1s}
.data-table tbody tr.row-normal td{background:#fff}
.data-table tbody tr.row-normal:hover td{background:#f0f9ff!important}
.data-table tbody tr.row-red td{background:#fef2f2!important}
.data-table tbody tr.row-red:hover td{background:#fee2e2!important}
.data-table tbody tr.row-yellow td{background:#fefce8!important}
.data-table tbody tr.row-yellow:hover td{background:#fef9c3!important}
.data-table td{padding:5px 6px;color:#374151;vertical-align:middle}
.tr{text-align:right}.tc{text-align:center}
.data-table tfoot td{padding:9px 6px;font-weight:800;font-size:12px;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155;position:sticky;bottom:0}
.data-table tfoot td.tr{text-align:right}

/* Status badges */
.st-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
.st-pending  {background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.st-to_be_bank{background:#e0f2fe;color:#0369a1;border:1px solid #7dd3fc}
.st-deposited{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe}
.st-sent_back{background:#fdf4ff;color:#7e22ce;border:1px solid #d8b4fe}
.st-returned {background:#fee2e2;color:#991b1b;border:1px solid #fecaca}

/* Payment mode badges */
.pm-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 7px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
.pm-cash  {background:#dcfce7;color:#166534;border:1px solid #86efac}
.pm-credit{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.pm-cheque{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe}

/* Age cell */
.age-cell{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:20px;font-weight:900;font-size:13px;font-family:'Barlow',sans-serif;white-space:nowrap;letter-spacing:.02em}
.age-0   {background:#f3f4f6;color:#374151}
.age-1-7 {background:#dbeafe;color:#1e40af}
.age-8-14{background:#d1fae5;color:#065f46}
.age-15-21{background:#fef3c7;color:#92400e}
.age-22-28{background:#ffedd5;color:#c2410c}
.age-29-35{background:#fee2e2;color:#b91c1c}
.age-35p {background:#f3e8ff;color:#6b21a8}

/* Credit policy days cell */
.credit-days-cell{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:20px;font-size:13px;font-weight:900;font-family:'Barlow',sans-serif;white-space:nowrap;letter-spacing:.02em;border:1px solid #bfdbfe;background:#eff6ff;color:#1e40af}
.credit-days-overdue{background:#fef2f2;color:#991b1b;border-color:#fecaca}

/* Diff vs Policy column */
.diff-cell{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:20px;font-size:13px;font-weight:900;font-family:'Barlow',sans-serif;white-space:nowrap;letter-spacing:.02em}
.diff-over {background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.diff-under{background:#dcfce7;color:#166534;border:1px solid #86efac}
.diff-exact{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb}

.bucket-tag{display:inline-block;padding:1px 7px;border-radius:5px;font-size:10px;font-weight:700;white-space:nowrap}
.bt-0   {background:#f3f4f6;color:#374151}.bt-1-7{background:#dbeafe;color:#1e40af}
.bt-8-14{background:#d1fae5;color:#065f46}.bt-15-21{background:#fef3c7;color:#92400e}
.bt-22-28{background:#ffedd5;color:#c2410c}.bt-29-35{background:#fee2e2;color:#b91c1c}.bt-35p{background:#f3e8ff;color:#6b21a8}

/* Invoice pills */
.inv-pill{display:inline-block;background:#ede9fe;color:#3730a3;border:1px solid #c4b5fd;border-radius:5px;padding:1px 6px;font-size:10px;font-weight:700;font-family:'Courier New',monospace;white-space:nowrap;margin:1px 1px 1px 0}

/* Settled info strip */
.settled-strip{margin-top:3px;font-size:10px;color:#0d9488;font-weight:600;display:flex;align-items:center;gap:4px;flex-wrap:wrap}
.settled-strip .sb-paid{color:#7c3aed}.settled-strip .sb-bal{color:#dc2626;font-weight:700}

.mono{font-family:'Courier New',monospace;font-weight:700}
.sr-pill{background:#ede9fe;color:#5b21b6;padding:2px 7px;border-radius:8px;font-size:11px;font-weight:700;white-space:nowrap}
.date-cell{font-size:11.5px;white-space:nowrap}
.date-none{color:#d1d5db;font-style:italic}
.cust-sub{font-size:10px;color:#6b7280;margin-top:1px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.special-badge{background:#fef08a;color:#854d0e;border:1px solid #fde047;display:inline-flex;align-items:center;gap:3px;padding:2px 6px;border-radius:9px;font-size:10px;font-weight:700;margin-top:2px}

/* Loading */
.tbl-wrap{position:relative}
.tbl-loading{display:none;position:absolute;inset:0;background:rgba(255,255,255,.85);z-index:50;align-items:center;justify-content:center;flex-direction:column;gap:10px;font-size:13px;color:#6366f1;font-weight:600;border-radius:10px}
.tbl-loading.show{display:flex}
.spinner{width:32px;height:32px;border:4px solid #e0e7ff;border-top-color:#6366f1;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* Toast */
#toast{position:fixed;bottom:28px;right:28px;z-index:99999;padding:12px 22px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 6px 24px rgba(0,0,0,.2);color:#fff;transform:translateY(80px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none}
#toast.show{transform:translateY(0);opacity:1}

/* Select2 */
.select2-container--default .select2-selection--single{height:37px!important;border:1px solid #e5e5e5!important;border-radius:7px!important}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:35px!important;padding-left:11px!important;color:#1f2937!important;font-size:13px!important;font-family:inherit!important}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:35px!important}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:#6366f1!important;box-shadow:0 0 0 3px rgba(99,102,241,.1)!important}
.select2-dropdown{border:1px solid #e5e5e5!important;border-radius:8px!important;box-shadow:0 8px 30px rgba(0,0,0,.12)!important;font-size:13px!important;z-index:10000000!important}
.select2-results__option--highlighted{background:#6366f1!important}

/* Received date quick ranges */
.recv-quick{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px dashed #e5e5e5}
.recv-quick-lbl{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-right:2px}
.recv-chip{border:1px solid #e0e7ff;background:#fff;color:#4338ca;border-radius:14px;padding:4px 11px;font-size:11.5px;font-weight:600;cursor:pointer;font-family:inherit}
.recv-chip:hover{background:#eef2ff}
.recv-chip.active{background:#6366f1;border-color:#6366f1;color:#fff}
.recv-chip:focus-visible{outline:2px solid #6366f1;outline-offset:2px}

mark.hl{background:#fef08a;color:#713f12;border-radius:2px;padding:0 1px}
.state-box{text-align:center;padding:60px 20px;color:#9ca3af}
.state-box i{font-size:48px;display:block;margin-bottom:14px;opacity:.3}
@media print{
  .no-print{display:none!important}
  .data-table thead th{background:#1e1b4b!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .data-table tfoot td{background:#0f172a!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .as-at-banner{background:#1e1b4b!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .data-table tbody tr.row-red td{background:#fef2f2!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .data-table tbody tr.row-yellow td{background:#fefce8!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
@media(max-width:1200px){.dist-grid{grid-template-columns:repeat(4,1fr)}}
@media(max-width:860px){.dist-grid{grid-template-columns:repeat(3,1fr)}.f-row-4{grid-template-columns:1fr 1fr}}
@media(max-width:560px){.dist-grid{grid-template-columns:1fr 1fr}.f-row-4{grid-template-columns:1fr}}

.inv-pill{
    display:inline-block;background:#ede9fe;color:#3730a3;border:1px solid #c4b5fd;
    border-radius:5px;padding:1px 6px;font-size:10px;font-weight:700;
    font-family:'Courier New',monospace;white-space:nowrap;margin:1px 1px 1px 0;
    transition:background .15s;
}
.inv-pill:hover{background:#ddd6fe;border-color:#a78bfa;}  /* add this */
</style>

<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;" class="no-print">
  <div>
    <h2 class="page-title"><i class="fa-solid fa-clock-rotate-left" style="color:#6366f1;"></i> Cheque Aging Report</h2>
    <p class="page-subtitle">Aging: delivery date → cheque date. Cleared &amp; fully-settled cheques excluded. Totals reflect unsettled balances. Filter by received date if needed.</p>
  </div>
  <div style="display:flex;gap:8px;" class="no-print">
    <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</button>
    <button onclick="exportExcel()" class="btn btn-success btn-sm"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
  </div>
</div>

<!-- ROW COLOUR LEGEND -->
<div class="row-legend no-print">
  <span style="font-size:12px;font-weight:700;color:#374151;margin-right:4px;">Row Colours:</span>
  <div style="display:flex;align-items:center;gap:6px;">
    <div class="legend-dot legend-red"></div>
    <span style="color:#991b1b;">Overdue — Age exceeds credit policy days (cheque customers)</span>
  </div>
  <div style="display:flex;align-items:center;gap:6px;">
    <div class="legend-dot legend-yellow"></div>
    <span style="color:#92400e;">Special Credit Customer</span>
  </div>
  <div style="display:flex;align-items:center;gap:6px;">
    <div class="legend-dot legend-white"></div>
    <span style="color:#6b7280;">Normal</span>
  </div>
</div>

<!-- FILTER CARD -->
<div class="filter-card no-print">
  <div class="filter-header open" id="filterHeader" onclick="toggleFilter()">
    <div class="filter-title"><i class="fa-solid fa-sliders" style="color:#6366f1;"></i> Filters &amp; Settings</div>
    <i class="fa-solid fa-chevron-down filter-toggle-icon open" id="filterIcon"></i>
  </div>
  <div class="filter-body open" id="filterBody">
    <div class="f-row f-row-4" style="margin-bottom:10px;">
      <div class="ffg">
        <label><i class="fa-solid fa-calendar-star"></i> As At Date <span style="color:#dc2626;">*</span></label>
        <input type="date" id="asAtDate" value="<?=date('Y-m-d')?>">
      </div>
      <div class="ffg">
        <label><i class="fa-solid fa-circle-half-stroke"></i> Status</label>
        <select id="selStatus">
          <option value="">— All Status —</option>
          <?php foreach($statuses as $s): ?>
          <option value="<?=$s?>"><?=htmlspecialchars($status_labels[$s])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ffg">
        <label><i class="fa-solid fa-id-badge"></i> SR Code</label>
        <select id="selSR">
          <option value="">— All SR —</option>
          <?php foreach($all_sr as $sr): ?>
          <option value="<?=htmlspecialchars($sr)?>"><?=htmlspecialchars($sr)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ffg">
        <label><i class="fa-solid fa-wallet"></i> Payment Mode</label>
        <select id="selPayMode">
          <option value="">— All Modes —</option>
          <option value="cash">Cash</option>
          <option value="credit">Credit</option>
          <option value="cheque">Cheque</option>
        </select>
      </div>
    </div>
    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <div class="ffg" style="flex:1;min-width:160px;">
        <label><i class="fa-solid fa-building-columns"></i> Bank Code</label>
        <select id="selBank">
          <option value="">— All Banks —</option>
          <?php foreach($all_banks as $bk): ?>
          <option value="<?=htmlspecialchars($bk)?>"><?=htmlspecialchars($bk)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ffg" style="flex:1;min-width:150px;">
        <label><i class="fa-solid fa-calendar-day"></i> Received Date From</label>
        <input type="date" id="recvFrom">
      </div>
      <div class="ffg" style="flex:1;min-width:150px;">
        <label><i class="fa-solid fa-calendar-day"></i> Received Date To</label>
        <input type="date" id="recvTo">
      </div>
      <div class="ffg" style="flex:2;min-width:200px;">
        <label><i class="fa-solid fa-magnifying-glass"></i> Search (Cheque No / Amount / Customer / T-Code)</label>
        <input type="text" id="fSearch" placeholder="Type to search…">
      </div>
      <div style="display:flex;gap:8px;align-items:center;padding-bottom:1px;">
        <button class="btn btn-primary" onclick="applyFilters()"><i class="fa-solid fa-magnifying-glass"></i> Load Report</button>
        <button class="btn btn-secondary" onclick="clearFilters()" title="Reset all"><i class="fa-solid fa-rotate-left"></i></button>
      </div>
    </div>
    <div class="recv-quick">
      <span class="recv-quick-lbl"><i class="fa-solid fa-bolt"></i> Received:</span>
      <button type="button" class="recv-chip" data-range="today">Today</button>
      <button type="button" class="recv-chip" data-range="yesterday">Yesterday</button>
      <button type="button" class="recv-chip" data-range="7d">Last 7 days</button>
      <button type="button" class="recv-chip" data-range="month">This month</button>
      <button type="button" class="recv-chip" data-range="lastmonth">Last month</button>
      <button type="button" class="recv-chip" data-range="">Any date</button>
    </div>
  </div>
</div>

<!-- AS-AT BANNER -->
<div class="as-at-banner" id="asAtBanner">
  <div>
    <div class="as-at-label"><i class="fa-solid fa-calendar-check"></i> Report As At</div>
    <div class="as-at-date" id="bannerDate">—</div>
  </div>
  <div style="text-align:center;">
    <div class="as-at-label">Aging Type</div>
    <div class="as-at-type" id="bannerType">—</div>
  </div>
  <div style="text-align:center;" id="bannerRecvWrap" hidden>
    <div class="as-at-label">Received Date</div>
    <div class="as-at-type" id="bannerRecv">—</div>
  </div>
  <div style="text-align:center;">
    <div class="as-at-label">Total Cheques</div>
    <div class="as-at-date" id="bannerCount">0</div>
  </div>
  <div class="as-at-total">
    <div class="as-at-total-lbl">Total Unsettled Amount</div>
    <div class="as-at-total-val" id="bannerTotal">Rs. 0.00</div>
  </div>
</div>

<!-- AGING DISTRIBUTION CARDS -->
<div class="dist-grid" id="distGrid">
  <?php
  $dist_configs = [
    ['key'=>'0',     'lbl'=>'≤ 0 Days',   'cls'=>'dc-0'],
    ['key'=>'1-7',   'lbl'=>'1–7 Days',   'cls'=>'dc-1-7'],
    ['key'=>'8-14',  'lbl'=>'8–14 Days',  'cls'=>'dc-8-14'],
    ['key'=>'15-21', 'lbl'=>'15–21 Days', 'cls'=>'dc-15-21'],
    ['key'=>'22-28', 'lbl'=>'22–28 Days', 'cls'=>'dc-22-28'],
    ['key'=>'29-35', 'lbl'=>'29–35 Days', 'cls'=>'dc-29-35'],
    ['key'=>'35+',   'lbl'=>'35+ Days',   'cls'=>'dc-35p'],
  ];
  foreach($dist_configs as $dc): ?>
  <div class="dist-card <?=$dc['cls']?>" id="dcard-<?=htmlspecialchars($dc['key'])?>"
       onclick="filterBucket('<?=htmlspecialchars($dc['key'])?>')" title="Click to filter">
    <div class="dist-card-lbl"><?=htmlspecialchars($dc['lbl'])?></div>
    <div class="dist-card-count" id="dcnt-<?=htmlspecialchars($dc['key'])?>">—</div>
    <div class="dist-card-amt" id="damt-<?=htmlspecialchars($dc['key'])?>">Rs. —</div>
    <span class="dist-clear-pill" onclick="event.stopPropagation();filterBucket('')">✕ Clear</span>
  </div>
  <?php endforeach; ?>
</div>

<!-- TABLE CARD -->
<div class="table-card tbl-wrap">
  <div class="tbl-loading" id="tblLoading"><div class="spinner"></div>Loading aging data…</div>
  <div class="table-toolbar no-print">
    <div class="tbl-title">
      <i class="fa-solid fa-table-list"></i> Aging Detail
      <span class="pill p-violet" id="visCount">0 records</span>
      <span class="pill p-amber" id="bucketPill" style="display:none;"></span>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <div class="search-wrap">
        <i class="fa-solid fa-magnifying-glass si"></i>
        <input type="text" id="tableSearch" placeholder="Search table…" oninput="filterTable(this)">
        <button class="clr" id="tableClr" onclick="clearTableSearch()"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>
  </div>
  <div class="dt-outer">
    <table class="data-table" id="mainTable">
      <thead>
        <tr>
          <th style="width:32px;" data-col="#" data-type="num"># <span class="sort-ico"></span></th>
          <th class="tc" data-col="sr_code" data-type="str">SR Code <span class="sort-ico"></span></th>
          <th class="tc" data-col="delivery_date" data-type="date">Delivery Date <span class="sort-ico"></span></th>
          <th data-col="t_code" data-type="str">T-Code / Customer <span class="sort-ico"></span></th>
          <th class="tc" data-col="payment_mode" data-type="str">Pay Mode <span class="sort-ico"></span></th>
          <th data-col="cheque_no" data-type="str">Cheque No. <span class="sort-ico"></span></th>
         <th data-col="invoices" data-type="str">Invoices <span class="sort-ico"></span></th>
          <th class="tc" data-col="cheque_date" data-type="date">Cheque Date <span class="sort-ico"></span></th>
          <th class="tc" data-col="received_date" data-type="date">Received Date <span class="sort-ico"></span></th>
          <th data-col="bank_name" data-type="str">Bank / Branch <span class="sort-ico"></span></th>
          <th class="tc" data-col="bank_code" data-type="str">Bank Code <span class="sort-ico"></span></th>
          <th class="tr" data-col="total_amount" data-type="num">Amount (Rs.) <span class="sort-ico"></span></th>
          <th class="tc" data-col="status" data-type="str">Status <span class="sort-ico"></span></th>
          <th class="tc" data-col="age_days" data-type="num">Age (Days) <span class="sort-ico"></span></th>
          <th class="tc" data-col="credit_days" data-type="num">Credit Policy <span class="sort-ico"></span></th>
          <th class="tc" data-col="diff" data-type="num">Diff vs Policy <span class="sort-ico"></span></th>
          <th class="tc" data-col="age_days" data-type="num">Bucket <span class="sort-ico"></span></th>
        </tr>
      </thead>
      <tbody id="mainTbody">
        <tr>
          <td colspan="17">
            <div class="state-box">
              <i class="fa-solid fa-clock-rotate-left"></i>
              <p style="font-size:14px;font-weight:600;">Set filters and click <strong>Load Report</strong></p>
              <small>Select As At Date, then load.</small>
            </div>
          </td>
        </tr>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="11"></td>
          <td class="tr" id="footerTotal">—</td>
          <td colspan="5" style="font-size:11px;opacity:.65;">TOTAL — <span id="footerCount">0</span> CHEQUES</td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<div id="toast"></div>

<script>
/* ═══════════════════════════════════════════════════════
   INIT SELECT2
═══════════════════════════════════════════════════════ */
$(function(){
    $('#selStatus').select2({placeholder:'— All Status —',allowClear:true,width:'100%'});
    $('#selSR').select2({placeholder:'— All SR —',allowClear:true,width:'100%'});
    $('#selBank').select2({placeholder:'— All Banks —',allowClear:true,width:'100%'});
    $('#selPayMode').select2({placeholder:'— All Modes —',allowClear:true,width:'100%'});
});

/* ═══════════════════════════════════════════════════════
   STATE
═══════════════════════════════════════════════════════ */
let _allRows      = [];
let _filteredRows = [];
let _activeBucket = '';
let _lastData     = null;
let _sortCol      = null;   /* column key being sorted */
let _sortDir      = 'asc';  /* 'asc' | 'desc' */

/* ═══════════════════════════════════════════════════════
   COLUMN SORT
═══════════════════════════════════════════════════════ */
document.querySelectorAll('.data-table thead th[data-col]').forEach(th => {
    th.addEventListener('click', () => {
        const col  = th.dataset.col;
        const type = th.dataset.type || 'str';
        if(_sortCol === col){
            _sortDir = (_sortDir === 'asc') ? 'desc' : 'asc';
        } else {
            _sortCol = col;
            _sortDir = 'asc';
        }
        /* Update header classes */
        document.querySelectorAll('.data-table thead th[data-col]').forEach(h => {
            h.classList.remove('sort-asc','sort-desc');
        });
        th.classList.add(_sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
        sortAndRender(col, type);
    });
});

function sortAndRender(col, type){
    const dir = _sortDir === 'asc' ? 1 : -1;
    const sorted = [..._filteredRows].sort((a, b) => {
        let av, bv;
        if(col === 'diff'){
            const aAge = parseInt(a.age_days ?? 0);
            const bAge = parseInt(b.age_days ?? 0);
            av = (a.payment_mode === 'cheque' && a.credit_days > 0) ? aAge - a.credit_days : -Infinity;
            bv = (b.payment_mode === 'cheque' && b.credit_days > 0) ? bAge - b.credit_days : -Infinity;
        } else if(type === 'num'){
            av = parseFloat(a[col] ?? 0);
            bv = parseFloat(b[col] ?? 0);
        } else if(type === 'date'){
            av = a[col] ? new Date(a[col]).getTime() : 0;
            bv = b[col] ? new Date(b[col]).getTime() : 0;
        } else if(col === 'invoices'){
            // Sort by first invoice number in the array (alphabetically)
            av = (a.invoices && a.invoices.length) ? String(a.invoices[0].num || '').toLowerCase() : '';
            bv = (b.invoices && b.invoices.length) ? String(b.invoices[0].num || '').toLowerCase() : '';
        } else {
            av = (a[col] || '').toString().toLowerCase();
            bv = (b[col] || '').toString().toLowerCase();
        }
        if(av < bv) return -1 * dir;
        if(av > bv) return  1 * dir;
        return 0;
    });
    renderRows(sorted);
}

/* ═══════════════════════════════════════════════════════
   FILTER HELPERS
═══════════════════════════════════════════════════════ */
function toggleFilter(){
    const h=document.getElementById('filterHeader'),b=document.getElementById('filterBody'),i=document.getElementById('filterIcon');
    const o=b.classList.contains('open');
    b.classList.toggle('open',!o); h.classList.toggle('open',!o); i.classList.toggle('open',!o);
}

/* ── Received date quick ranges ── */
function ymd(d){ return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
function recvRange(key){
    const t = new Date(); t.setHours(0,0,0,0);
    const add = n => { const d = new Date(t); d.setDate(d.getDate()+n); return d; };
    switch(key){
        case 'today':     return [ymd(t), ymd(t)];
        case 'yesterday': return [ymd(add(-1)), ymd(add(-1))];
        case '7d':        return [ymd(add(-6)), ymd(t)];
        case 'month':     return [ymd(new Date(t.getFullYear(), t.getMonth(), 1)), ymd(t)];
        case 'lastmonth': return [ymd(new Date(t.getFullYear(), t.getMonth()-1, 1)), ymd(new Date(t.getFullYear(), t.getMonth(), 0))];
        default:          return ['', ''];
    }
}
function markRecvChip(){
    const f = document.getElementById('recvFrom').value, t = document.getElementById('recvTo').value;
    document.querySelectorAll('.recv-chip').forEach(b=>{
        const [a, z] = recvRange(b.dataset.range);
        b.classList.toggle('active', a === f && z === t);
    });
}
function recvRangeText(f, t, html){
    const fmt = d => html ? fmtDateStr(d) : d;
    if(f && t) return f === t ? fmt(f) : fmt(f) + ' → ' + fmt(t);
    if(f) return 'From ' + fmt(f);
    if(t) return 'Up to ' + fmt(t);
    return 'Any';
}
document.querySelectorAll('.recv-chip').forEach(b=>{
    b.addEventListener('click', ()=>{
        const [a, z] = recvRange(b.dataset.range);
        document.getElementById('recvFrom').value = a;
        document.getElementById('recvTo').value   = z;
        markRecvChip();
        applyFilters();
    });
});
['recvFrom','recvTo'].forEach(id=>document.getElementById(id).addEventListener('change', markRecvChip));
markRecvChip();

function getFilters(){
    return {
        as_at     : document.getElementById('asAtDate').value || '',
        status    : document.getElementById('selStatus').value || '',
        sr_code   : document.getElementById('selSR').value || '',
        bank_code : document.getElementById('selBank').value || '',
        pay_mode  : document.getElementById('selPayMode').value || '',
        q         : document.getElementById('fSearch').value.trim() || '',
        recv_from : document.getElementById('recvFrom').value || '',
        recv_to   : document.getElementById('recvTo').value || '',
        bucket    : _activeBucket,
    };
}

function applyFilters(){
    _activeBucket = '';
    _sortCol = null; _sortDir = 'asc';
    document.querySelectorAll('.dist-card').forEach(c=>c.classList.remove('active-bucket'));
    document.querySelectorAll('.data-table thead th[data-col]').forEach(h=>h.classList.remove('sort-asc','sort-desc'));
    document.getElementById('bucketPill').style.display = 'none';
    fetchData();
}

function clearFilters(){
    document.getElementById('asAtDate').value = new Date().toISOString().split('T')[0];
    ['selStatus','selSR','selBank','selPayMode'].forEach(id=>{
        const el=document.getElementById(id);
        if(el){ el.value=''; if(window.$) $(el).val('').trigger('change'); }
    });
    document.getElementById('fSearch').value = '';
    document.getElementById('recvFrom').value = '';
    document.getElementById('recvTo').value = '';
    markRecvChip();
    _activeBucket = ''; _sortCol = null; _sortDir = 'asc';
    document.querySelectorAll('.dist-card').forEach(c=>c.classList.remove('active-bucket'));
    document.querySelectorAll('.data-table thead th[data-col]').forEach(h=>h.classList.remove('sort-asc','sort-desc'));
    document.getElementById('bucketPill').style.display = 'none';
    _allRows = []; _filteredRows = [];
    renderRows([]);
    resetDistCards();
    updateBanner(null);
}

function filterBucket(key){
    _activeBucket = (_activeBucket === key) ? '' : key;
    document.querySelectorAll('.dist-card').forEach(c=>c.classList.remove('active-bucket'));
    if(_activeBucket){
        const card = document.getElementById('dcard-'+_activeBucket);
        if(card) card.classList.add('active-bucket');
        const pill = document.getElementById('bucketPill');
        const bLbls = {'0':'≤ 0 Days','1-7':'1–7 Days','8-14':'8–14 Days','15-21':'15–21 Days','22-28':'22–28 Days','29-35':'29–35 Days','35+':'35+ Days'};
        pill.textContent = '🔍 ' + (bLbls[_activeBucket]||_activeBucket);
        pill.style.display = 'inline';
    } else {
        document.getElementById('bucketPill').style.display = 'none';
    }
    applyBucketFilter();
}

function applyBucketFilter(){
    if(!_allRows.length) return;
    let rows = _allRows;
    if(_activeBucket){
        rows = rows.filter(r => {
            const age = r.age_days;
            if(age === null || age === undefined) return false;
            switch(_activeBucket){
                case '0':     return age <= 0;
                case '1-7':   return age >= 1 && age <= 7;
                case '8-14':  return age >= 8 && age <= 14;
                case '15-21': return age >= 15 && age <= 21;
                case '22-28': return age >= 22 && age <= 28;
                case '29-35': return age >= 29 && age <= 35;
                case '35+':   return age > 35;
                default: return true;
            }
        });
    }
    const srch = document.getElementById('tableSearch').value.trim().toLowerCase();
    if(srch) rows = rows.filter(r=>rowMatchesSearch(r,srch));
    _filteredRows = rows;
    if(_sortCol){
        const th = document.querySelector(`.data-table thead th[data-col="${_sortCol}"]`);
        sortAndRender(_sortCol, th ? th.dataset.type : 'str');
    } else {
        renderRows(_filteredRows);
    }
}

/* ═══════════════════════════════════════════════════════
   FETCH
═══════════════════════════════════════════════════════ */
let _lastCtrl = null;
async function fetchData(){
    if(_lastCtrl) _lastCtrl.abort();
    _lastCtrl = new AbortController();
    showLoading(true);
    const f = getFilters();
    if(!f.as_at){ showToast('Please select an As At Date','err'); showLoading(false); return; }
    if(f.recv_from && f.recv_to && f.recv_from > f.recv_to){
        /* entered backwards — swap so the range still works */
        document.getElementById('recvFrom').value = f.recv_to;
        document.getElementById('recvTo').value   = f.recv_from;
        [f.recv_from, f.recv_to] = [f.recv_to, f.recv_from];
    }
    const params = new URLSearchParams({ ajax:'aging_data', ...f });
    try {
        const res  = await fetch('cheque_aging_report.php?' + params.toString(), {signal:_lastCtrl.signal});
        if(!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if(!data.success) throw new Error(data.error || 'Server error');
        _lastData = data;
        _allRows  = data.rows || [];
        updateBanner(data);
        updateDistCards(data.distribution || {});
        applyBucketFilter();
    } catch(e){
        if(e.name==='AbortError') return;
        showToast('Error: ' + e.message, 'err');
        document.getElementById('mainTbody').innerHTML =
            `<tr><td colspan="17"><div class="state-box"><i class="fa-solid fa-triangle-exclamation"></i><p>Error loading data</p><small>${esc(e.message)}</small></div></td></tr>`;
    } finally { showLoading(false); }
}

/* ═══════════════════════════════════════════════════════
   BANNER + DIST CARDS
═══════════════════════════════════════════════════════ */
function updateBanner(data){
    if(!data){
        document.getElementById('bannerDate').textContent='—';
        document.getElementById('bannerType').textContent='—';
        document.getElementById('bannerCount').textContent='0';
        document.getElementById('bannerTotal').textContent='Rs. 0.00';
        document.getElementById('bannerRecvWrap').hidden = true;
        return;
    }
    const rf = data.recv_from || '', rt = data.recv_to || '';
    document.getElementById('bannerRecvWrap').hidden = !(rf || rt);
    document.getElementById('bannerRecv').innerHTML = recvRangeText(rf, rt, true);
    document.getElementById('bannerDate').textContent  = fmtDateStr(data.as_at) || data.as_at;
    document.getElementById('bannerType').textContent  = data.aging_label || '—';
    document.getElementById('bannerCount').textContent = data.grand_count.toLocaleString();
    document.getElementById('bannerTotal').textContent = 'Rs. ' + data.grand_total.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
}

function resetDistCards(){
    ['0','1-7','8-14','15-21','22-28','29-35','35+'].forEach(k=>{
        const c=document.getElementById('dcnt-'+k); if(c) c.textContent='—';
        const a=document.getElementById('damt-'+k); if(a) a.textContent='—';
    });
}

function updateDistCards(dist){
    Object.entries(dist).forEach(([key, d])=>{
        const c=document.getElementById('dcnt-'+key); if(c) c.textContent = d.count.toLocaleString();
        const a=document.getElementById('damt-'+key); if(a) a.textContent = d.amount.toLocaleString('en-US',{minimumFractionDigits:0,maximumFractionDigits:0});
    });
}

/* ═══════════════════════════════════════════════════════
   RENDER TABLE
═══════════════════════════════════════════════════════ */
const ST_ICONS = {
    pending:'fa-clock', to_be_bank:'fa-inbox',
    deposited:'fa-building-columns', sent_back:'fa-rotate-left', returned:'fa-circle-xmark'
};
const ST_LBL = {
    pending:'Pending', to_be_bank:'To Be Bank',
    deposited:'Deposited', sent_back:'Sent Back', returned:'Returned'
};
const PM_ICONS = { cash:'fa-money-bill', credit:'fa-file-invoice-dollar', cheque:'fa-money-check' };
const PM_LBLS  = { cash:'Cash', credit:'Credit', cheque:'Cheque' };

function ageClass(age){
    if(age<=0)   return 'age-0';
    if(age<=7)   return 'age-1-7';
    if(age<=14)  return 'age-8-14';
    if(age<=21)  return 'age-15-21';
    if(age<=28)  return 'age-22-28';
    if(age<=35)  return 'age-29-35';
    return 'age-35p';
}
function bucketKey(age){
    if(age===null||age===undefined) return {k:'',lbl:'—',cls:''};
    if(age<=0)   return {k:'0',     lbl:'≤ 0 Days',   cls:'bt-0'};
    if(age<=7)   return {k:'1-7',   lbl:'1–7 Days',   cls:'bt-1-7'};
    if(age<=14)  return {k:'8-14',  lbl:'8–14 Days',  cls:'bt-8-14'};
    if(age<=21)  return {k:'15-21', lbl:'15–21 Days', cls:'bt-15-21'};
    if(age<=28)  return {k:'22-28', lbl:'22–28 Days', cls:'bt-22-28'};
    if(age<=35)  return {k:'29-35', lbl:'29–35 Days', cls:'bt-29-35'};
    return {k:'35+', lbl:'35+ Days', cls:'bt-35p'};
}

function renderRows(rows){
    document.getElementById('visCount').textContent = rows.length + ' records';
    const balTotal = rows.reduce((s,r)=>s+parseFloat(r.effective_amount||0), 0);
    document.getElementById('footerCount').textContent = rows.length;
    document.getElementById('footerTotal').textContent =
        rows.length ? 'Rs. ' + balTotal.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) : '—';

    if(!rows.length){
        document.getElementById('mainTbody').innerHTML =
            `<tr><td colspan="17"><div class="state-box"><i class="fa-solid fa-inbox"></i><p>No cheques found for this filter.</p></div></td></tr>`;
        return;
    }

    const srch = document.getElementById('tableSearch').value.trim();
    let html = '';
    rows.forEach((row, idx)=>{
        const age      = row.age_days;
        const hasAge   = (age !== null && age !== undefined);
        const aCls     = hasAge ? ageClass(age) : 'age-0';
        const bkt      = bucketKey(age);
        const st       = (row.status||'pending').toLowerCase();
        const stIco    = ST_ICONS[st]||'fa-circle-dot';
        const stLbl    = ST_LBL[st]||st;
        const pm       = (row.payment_mode||'cash').toLowerCase();
        const pmIco    = PM_ICONS[pm]||'fa-money-bill';
        const pmLbl    = PM_LBLS[pm]||pm;
        const pmCls    = 'pm-'+pm;
        const cdDays   = parseInt(row.credit_days||0);
        const isSpec   = parseInt(row.is_special||0) === 1;
        const faceAmt  = parseFloat(row.total_amount||0);
        const effAmt   = parseFloat(row.effective_amount||0);

        /* Row colour */
        let rowCls = 'row-normal';
        if(hasAge && pm === 'cheque' && cdDays > 0 && age > cdDays){
            rowCls = 'row-red';
        } else if(isSpec){
            rowCls = 'row-yellow';
        }

        /* ── Invoice pills ── */
      const invs = row.invoices || [];
let invHtml = invs.length
    ? invs.map(inv => `<a target="_blank" href="view_invoice.php?id=${encodeURIComponent(inv.id)}" 
          class="inv-pill" 
          style="text-decoration:none;cursor:pointer;" 
          title="View invoice ${esc(inv.num)}">${esc(inv.num)}</a>`).join('')
    : '<span style="color:#d1d5db;font-size:11px;">—</span>';

        /* ── Status cell — badge + settlement/balance info (no sent_back reason) ── */
        let statusCellHtml = `<span class="st-badge st-${st}"><i class="fa-solid ${stIco}"></i> ${stLbl}</span>`;

        if(st === 'sent_back'){
            const sbPaid = parseFloat(row.sb_settlement_amount||0);
            const sbBal  = Math.max(0, faceAmt - sbPaid);
            if(sbPaid > 0){
                statusCellHtml += `
                <div class="settled-strip">
                    <i class="fa-solid fa-circle-half-stroke" style="font-size:9px;"></i>
                    Paid: <span class="sb-paid">Rs.${sbPaid.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</span>
                    &nbsp;|&nbsp; Bal: <span class="sb-bal">Rs.${sbBal.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</span>
                </div>`;
            } else {
                statusCellHtml += `
                <div class="settled-strip">
                    <i class="fa-solid fa-coins" style="font-size:9px;color:#7c3aed;"></i>
                    Bal: <span class="sb-bal">Rs.${faceAmt.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</span>
                </div>`;
            }
            /* sent_back_reason intentionally removed */
        } else if(st === 'returned'){
            const rtnPaid = parseFloat(row.return_settlement_amount||0);
            const rtnBal  = Math.max(0, faceAmt - rtnPaid);
            if(rtnPaid > 0){
                statusCellHtml += `
                <div class="settled-strip">
                    <i class="fa-solid fa-circle-half-stroke" style="font-size:9px;"></i>
                    Paid: <span class="sb-paid">Rs.${rtnPaid.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</span>
                    &nbsp;|&nbsp; Bal: <span class="sb-bal">Rs.${rtnBal.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</span>
                </div>`;
            } else {
                statusCellHtml += `
                <div class="settled-strip">
                    <i class="fa-solid fa-coins" style="font-size:9px;color:#dc2626;"></i>
                    Bal: <span class="sb-bal">Rs.${faceAmt.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}</span>
                </div>`;
            }
        }

        /* ── Credit Policy Days cell ── */
        let cdHtml = '<span style="color:#d1d5db;font-style:italic;font-size:11px;">—</span>';
        if(pm === 'cheque'){
            if(cdDays > 0){
                const cdCls = (hasAge && age > cdDays) ? 'credit-days-cell credit-days-overdue' : 'credit-days-cell';
                const cdIcon = (hasAge && age > cdDays)
                    ? '<i class="fa-solid fa-triangle-exclamation" style="font-size:10px;"></i>'
                    : '<i class="fa-solid fa-calendar-check" style="font-size:10px;"></i>';
                cdHtml = `<span class="${cdCls}">${cdIcon} <b>${cdDays} d</b></span>`;
            } else {
                cdHtml = '<span style="color:#d97706;font-size:11px;font-style:italic;">Not set</span>';
            }
        }

        /* ── Age cell ── */
        let ageHtml = '<span style="color:#d1d5db;font-style:italic;font-size:12px;">—</span>';
        if(hasAge){
            ageHtml = `<span class="age-cell ${aCls}">
                <i class="fa-solid fa-hourglass-half" style="font-size:10px;"></i>
                <b>${age} d</b>
            </span>`;
        }

        /* ── Diff vs Policy column ── */
        let diffHtml = '<span style="color:#d1d5db;font-style:italic;font-size:11px;">—</span>';
        if(pm === 'cheque' && cdDays > 0 && hasAge){
            const diff = age - cdDays;
            if(diff > 0){
                diffHtml = `<span class="diff-cell diff-over"><i class="fa-solid fa-arrow-up" style="font-size:10px;"></i><b>+${diff} d</b></span>`;
            } else if(diff < 0){
                diffHtml = `<span class="diff-cell diff-under"><i class="fa-solid fa-arrow-down" style="font-size:10px;"></i><b>${diff} d</b></span>`;
            } else {
                diffHtml = `<span class="diff-cell diff-exact"><i class="fa-solid fa-equals" style="font-size:10px;"></i><b>0 d</b></span>`;
            }
        }

        html += `
        <tr class="${rowCls}"
            data-age="${age}" data-bucket="${esc(bkt.k)}" data-status="${esc(st)}"
            data-cheqno="${esc(row.cheque_no||'')}" data-tcode="${esc(row.t_code||'')}"
            data-cust="${esc(row.customer_name||'')}" data-amt="${faceAmt.toFixed(2)}"
            data-bank="${esc(row.bank_code||'')}" data-pm="${esc(pm)}">
          <td style="color:#9ca3af;font-size:11px;font-weight:600;">${idx+1}</td>
          <td class="tc"><span class="sr-pill">${esc(row.sr_code||'—')}</span></td>
          <td class="tc"><span class="date-cell">${fmtDateStr(row.delivery_date)}</span></td>
          <td>
            <span class="mono" style="font-size:11.5px;color:#4338ca;">${highlight(row.t_code||'—',srch)}</span>
            ${row.customer_name ? `<div class="cust-sub" title="${esc(row.customer_name)}">${highlight(row.customer_name,srch)}</div>` : ''}
            ${isSpec ? `<div><span class="special-badge"><i class="fa-solid fa-star" style="font-size:8px;"></i> Special</span></div>` : ''}
          </td>
          <td class="tc">
            <span class="pm-badge ${pmCls}">
              <i class="fa-solid ${pmIco}" style="font-size:10px;"></i> ${pmLbl}
            </span>
          </td>
          <td><span class="mono" style="color:#4338ca;font-size:12.5px;">${highlight(row.cheque_no||'—',srch)}</span></td>
          <td style="max-width:170px;">${invHtml}</td>
          <td class="tc"><span class="date-cell">${fmtDateStr(row.cheque_date)}</span></td>
          <td class="tc"><span class="date-cell">${fmtDateStr(row.received_date)}</span></td>
          <td>
            <div style="font-size:12px;font-weight:600;white-space:nowrap;">${highlight(row.bank_name||'—',srch)}</div>
            ${row.branch_name?`<div class="cust-sub">${esc(row.branch_name)}</div>`:''}
          </td>
          <td class="tc"><span class="mono" style="font-size:11px;">${highlight(row.bank_code||'—',srch)}</span></td>
          <td class="tr" style="font-weight:700;white-space:nowrap;">
            Rs.&nbsp;${highlight(faceAmt.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}),srch)}
          </td>
          <td class="tc">${statusCellHtml}</td>
          <td class="tc">${ageHtml}</td>
          <td class="tc">${cdHtml}</td>
          <td class="tc">${diffHtml}</td>
          <td class="tc">
            ${hasAge
              ? `<span class="bucket-tag ${bkt.cls}">${esc(bkt.lbl)}</span>`
              : `<span style="color:#d1d5db;font-size:11px;font-style:italic;">—</span>`}
          </td>
        </tr>`;
    });
    document.getElementById('mainTbody').innerHTML = html;
}

/* ═══════════════════════════════════════════════════════
   TABLE SEARCH (CLIENT-SIDE)
═══════════════════════════════════════════════════════ */
let _searchTimer = null;
function filterTable(inp){
    const clr = document.getElementById('tableClr');
    clr.classList.toggle('show', inp.value.length > 0);
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(()=>{
        const srch = inp.value.trim().toLowerCase();
        let rows = _activeBucket
            ? _allRows.filter(r=>bucketKey(r.age_days).k === _activeBucket)
            : _allRows;
        if(srch) rows = rows.filter(r=>rowMatchesSearch(r,srch));
        _filteredRows = rows;
        if(_sortCol){
            const th = document.querySelector(`.data-table thead th[data-col="${_sortCol}"]`);
            sortAndRender(_sortCol, th ? th.dataset.type : 'str');
        } else {
            renderRows(_filteredRows);
        }
    }, 200);
    
    
    
}




function clearTableSearch(){
    document.getElementById('tableSearch').value = '';
    document.getElementById('tableClr').classList.remove('show');
    filterTable({value:''});
}
function rowMatchesSearch(r, srch){
    return (r.cheque_no||'').toLowerCase().includes(srch)
        || (r.t_code||'').toLowerCase().includes(srch)
        || (r.customer_name||'').toLowerCase().includes(srch)
        || (r.bank_code||'').toLowerCase().includes(srch)
        || (r.bank_name||'').toLowerCase().includes(srch)
        || String(r.total_amount||'').includes(srch)
        || (r.sr_code||'').toLowerCase().includes(srch)
        || (r.status||'').toLowerCase().includes(srch)
        || (r.payment_mode||'').toLowerCase().includes(srch)
        || (r.invoices||[]).some(inv=>(inv.num||'').toLowerCase().includes(srch));
}

/* ═══════════════════════════════════════════════════════
   EXCEL EXPORT
═══════════════════════════════════════════════════════ */
function exportExcel(){
    if(!_filteredRows.length){ showToast('No data to export','err'); return; }
    const asAt = document.getElementById('asAtDate').value || '';
    const rF = (_lastData && _lastData.recv_from) || '', rT = (_lastData && _lastData.recv_to) || '';
    const recvNote = (rF || rT) ? ' | Received Date: ' + recvRangeText(rF, rT, false) : '';
    const headers = [
        '#','SR Code','Delivery Date','T-Code','Customer','Payment Mode',
        'Cheque No','Invoices','Cheque Date','Received Date','Bank Code','Bank Name',
        'Branch Code','Branch Name','Amount (Rs.)','Status','Settlement Notes',
        'Age (Days)','Credit Policy (Days)','Diff vs Policy (Days)','Bucket','Special'
    ];
    const bktLbl = (age)=>{
        if(age===null||age===undefined) return '—';
        if(age<=0) return '≤ 0 Days'; if(age<=7) return '1–7 Days'; if(age<=14) return '8–14 Days';
        if(age<=21) return '15–21 Days'; if(age<=28) return '22–28 Days'; if(age<=35) return '29–35 Days';
        return '35+ Days';
    };
    const sheetData = [
        ['Cheque Aging Report — As At: '+asAt+recvNote+' | Type: Delivery Date → Cheque Date | Cleared & Settled Cheques Excluded'],
        [],
        headers,
        ..._filteredRows.map((r,i)=>{
            const pm     = (r.payment_mode||'cash').toLowerCase();
            const cdDays = parseInt(r.credit_days||0);
            const age    = r.age_days;
            const faceAmt = parseFloat(r.total_amount||0);
            let diff = '';
            if(pm === 'cheque' && cdDays > 0 && age !== null && age !== undefined){
                diff = age - cdDays;
            }
            let settlementNote = '';
            const st = (r.status||'').toLowerCase();
            if(st === 'sent_back'){
                const sbPaid = parseFloat(r.sb_settlement_amount||0);
                const sbBal  = Math.max(0, faceAmt - sbPaid);
                settlementNote = sbPaid > 0
                    ? `Paid: Rs.${sbPaid.toFixed(2)} | Bal: Rs.${sbBal.toFixed(2)}`
                    : `Bal: Rs.${faceAmt.toFixed(2)}`;
            } else if(st === 'returned'){
                const rtnPaid = parseFloat(r.return_settlement_amount||0);
                const rtnBal  = Math.max(0, faceAmt - rtnPaid);
                settlementNote = rtnPaid > 0
                    ? `Paid: Rs.${rtnPaid.toFixed(2)} | Bal: Rs.${rtnBal.toFixed(2)}`
                    : `Bal: Rs.${faceAmt.toFixed(2)}`;
            }
            return [
                i+1,
                r.sr_code||'', r.delivery_date||'', r.t_code||'', r.customer_name||'',
                (PM_LBLS[pm]||pm),
                r.cheque_no||'',
                (r.invoices||[]).map(inv=>inv.num||'').join(', '),
                r.cheque_date||'', (r.received_date||'').split(' ')[0]||'',
                r.bank_code||'', r.bank_name||'', r.branch_code||'', r.branch_name||'',
                faceAmt,
                r.status||'',
                settlementNote,
                age,
                pm === 'cheque' ? cdDays : '',
                diff,
                bktLbl(age),
                r.is_special==1 ? 'Special' : 'Normal'
            ];
        }),
        [],
        ['TOTAL','','','','','','','','','','','','','',
          _filteredRows.reduce((s,r)=>s+parseFloat(r.effective_amount||0),0),
          '','','','','','','']
    ];

    if(typeof XLSX !== 'undefined'){
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(sheetData);
        ws['!cols'] = [5,10,14,12,22,12,16,20,14,14,12,22,12,22,16,14,28,14,10,14,14,10].map(w=>({wch:w}));
        XLSX.utils.book_append_sheet(wb, ws, 'Aging Report');
        XLSX.writeFile(wb, 'cheque_aging_' + asAt.replace(/-/g,'') + '.xlsx');
    } else {
        const q = v => '"'+(String(v||'').replace(/"/g,'""'))+'"';
        const csv = sheetData.slice(2).map(r=>r.map(q).join(',')).join('\n');
        const blob = new Blob([csv],{type:'text/csv'});
        const a = document.createElement('a'); a.href=URL.createObjectURL(blob);
        a.download = 'cheque_aging_'+asAt.replace(/-/g,'')+'.csv'; a.click();
        URL.revokeObjectURL(a.href);
    }
}

/* ═══════════════════════════════════════════════════════
   UTILITIES
═══════════════════════════════════════════════════════ */
function showLoading(on){ document.getElementById('tblLoading').classList.toggle('show', on); }

function fmtDateStr(d){
    if(!d||d==='0000-00-00'||d==='0000-00-00 00:00:00') return '<span class="date-none">—</span>';
    const dt = new Date((d+'').replace(' ','T'));
    const m  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return String(dt.getDate()).padStart(2,'0')+' '+m[dt.getMonth()]+' '+dt.getFullYear();
}

function esc(s){
    if(s===null||s===undefined) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

function highlight(text, term){
    if(!term||!text) return esc(text||'');
    const re = new RegExp('('+term.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi');
    return esc(text).replace(re,'<mark class="hl">$1</mark>');
}

function showToast(msg, type){
    const t=document.getElementById('toast');
    t.style.background = (type==='ok') ? '#166534' : '#dc2626';
    t.textContent = msg; t.classList.add('show');
    clearTimeout(t._t); t._t=setTimeout(()=>t.classList.remove('show'),3200);
}
</script>

<!-- SheetJS for Excel export -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<?php include 'footer.php'; ?>