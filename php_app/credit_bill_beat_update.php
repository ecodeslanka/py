<?php
include 'config.php';

/* ═══════════════════════════════════════════════════════════
   ENSURE LOG TABLE EXISTS (for Update + Reverse history)
   ═══════════════════════════════════════════════════════════ */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `credit_bill_beat_update_log` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `batch_id`        VARCHAR(40)  NOT NULL,
    `lsid_id`         INT          NOT NULL,
    `bill_no`         VARCHAR(100) DEFAULT NULL,
    `t_code`          VARCHAR(50)  DEFAULT NULL,
    `old_route_name`  VARCHAR(255) DEFAULT NULL,
    `new_route_name`  VARCHAR(255) DEFAULT NULL,
    `old_sr_code`     VARCHAR(100) DEFAULT NULL,
    `new_sr_code`     VARCHAR(100) DEFAULT NULL,
    `reversed`        TINYINT(1)   NOT NULL DEFAULT 0,
    `reversed_at`     DATETIME     DEFAULT NULL,
    `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_batch` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ═══════════════════════════════════════════════════════════
   AJAX HANDLERS — must run before header.php include
   ═══════════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) || isset($_POST['ajax'])) {

    header('Content-Type: application/json');
    mysqli_report(MYSQLI_REPORT_OFF);
    $ajax = $_GET['ajax'] ?? $_POST['ajax'];

    /* ─────────────────────────────────────────────
       PREVIEW: match uploaded T-Codes against the
       outstanding "credit bill base" invoices
       (same base logic as credit_bill_issue.php)
       Route/SR ONLY — route_name is never touched.
       The "Route" value shown/updated here is
       loading_summary_import_details.route_code.
       ───────────────────────────────────────────── */
    if ($ajax === 'preview') {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        $upload_rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

        // Normalize + dedupe uploaded rows by T Code (last occurrence wins)
        $upload_map = []; // t_code => ['route_name'=>.., 'sr_code'=>..]
        foreach ($upload_rows as $r) {
            $tc = trim($r['t_code'] ?? '');
            if ($tc === '' || strtoupper($tc) === 'T CODE') continue;
            $upload_map[$tc] = [
                'route_name' => trim($r['route_name'] ?? ''),
                'sr_code'    => trim($r['sr_code'] ?? ''),
            ];
        }

        if (empty($upload_map)) {
            echo json_encode(['success' => false, 'error' => 'No valid rows found in the uploaded file.']);
            exit;
        }

        $t_codes = array_keys($upload_map);
        $in_list = "'" . implode("','", array_map(function($v) use ($conn) {
            return mysqli_real_escape_string($conn, $v);
        }, $t_codes)) . "'";

        /* Outstanding credit bill base invoices matching these T-Codes */
        $sql = "
            SELECT
                fsd.id                                                                     AS detail_id,
                fsd.t_code                                                                  AS t_code,
                fsd.invoice_num                                                             AS invoice_num,
                COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code)             AS customer_name,
                (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) AS balance
            FROM field_summary_details fsd
            INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
            LEFT JOIN customers c ON c.t_code = fsd.t_code
            LEFT JOIN (
                SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
                FROM secondary_invoice_import_details GROUP BY bill_no
            ) siid ON siid.bill_no = fsd.invoice_num
            LEFT JOIN (
                SELECT field_summary_detail_id, SUM(amount) AS total_paid
                FROM invoice_payments WHERE is_reversed = 0
                GROUP BY field_summary_detail_id
            ) pay ON pay.field_summary_detail_id = fsd.id
            LEFT JOIN (
                SELECT field_summary_detail_id, SUM(amount) AS total_cn
                FROM credit_notes WHERE is_deleted = 0
                GROUP BY field_summary_detail_id
            ) cn ON cn.field_summary_detail_id = fsd.id
            WHERE fsd.updated = 1
              AND fsd.t_code IN ($in_list)
              AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) > 0
        ";
        $res = mysqli_query($conn, $sql);
        if (!$res) { echo json_encode(['success'=>false,'error'=>'Query failed: '.mysqli_error($conn)]); exit; }

        $base_invoices = [];
        while ($row = mysqli_fetch_assoc($res)) $base_invoices[] = $row;

        if (empty($base_invoices)) {
            echo json_encode(['success' => true, 'rows' => [], 'unmatched_count' => count($upload_map)]);
            exit;
        }

        $bill_nos = array_unique(array_column($base_invoices, 'invoice_num'));
        $bn_list = "'" . implode("','", array_map(function($v) use ($conn) {
            return mysqli_real_escape_string($conn, $v);
        }, $bill_nos)) . "'";

        /* Current loading_summary_import_details rows for these bills (the actual update target).
           NOTE: route_code (not route_name) is the "Route" field we read/update here. */
        $lsid_rows = [];
        $lres = mysqli_query($conn, "
            SELECT id, bill_no, t_code, route_code, sales_person_code, status
            FROM loading_summary_import_details
            WHERE bill_no IN ($bn_list)
              AND status IN ('imported','cancelled')
        ");
        if ($lres) {
            while ($lr = mysqli_fetch_assoc($lres)) $lsid_rows[$lr['bill_no']][] = $lr;
        }

        $preview = [];
        $matched_tcodes = [];

        foreach ($base_invoices as $inv) {
            $tc = $inv['t_code'];
            if (!isset($upload_map[$tc])) continue;
            $matched_tcodes[$tc] = true;

            $new_route_name = $upload_map[$tc]['route_name'];
            $new_sr_code    = $upload_map[$tc]['sr_code'];

            $rows_for_bill = $lsid_rows[$inv['invoice_num']] ?? [];
            if (empty($rows_for_bill)) {
                // No loading_summary_import_details record exists for this bill — cannot update
                $preview[] = [
                    'lsid_ids'        => [],
                    't_code'          => $tc,
                    'invoice_num'     => $inv['invoice_num'],
                    'customer_name'   => $inv['customer_name'],
                    'balance'         => round(floatval($inv['balance']), 2),
                    'old_route_name'  => '',
                    'old_sr_code'     => '',
                    'new_route_name'  => $new_route_name,
                    'new_sr_code'     => $new_sr_code,
                    'has_target'      => false,
                    'changed'         => false,
                ];
                continue;
            }

            // Use the first row's current values as the displayed "old" (all rows for this bill get updated together)
            $first = $rows_for_bill[0];
            $old_route_name = $first['route_code'] ?? '';
            $old_sr_code    = $first['sales_person_code'] ?? '';

            $changed = (
                ($new_route_name !== '' && $new_route_name !== $old_route_name) ||
                ($new_sr_code !== '' && $new_sr_code !== $old_sr_code)
            );

            $preview[] = [
                'lsid_ids'        => array_column($rows_for_bill, 'id'),
                't_code'          => $tc,
                'invoice_num'     => $inv['invoice_num'],
                'customer_name'   => $inv['customer_name'],
                'balance'         => round(floatval($inv['balance']), 2),
                'old_route_name'  => $old_route_name,
                'old_sr_code'     => $old_sr_code,
                'new_route_name'  => $new_route_name,
                'new_sr_code'     => $new_sr_code,
                'has_target'      => true,
                'changed'         => $changed,
            ];
        }

        $unmatched_count = count($upload_map) - count($matched_tcodes);

        echo json_encode(['success' => true, 'rows' => $preview, 'unmatched_count' => $unmatched_count]);
        exit;
    }

    /* ─────────────────────────────────────────────
       UPDATE: write selected rows to
       loading_summary_import_details
       (route_code + sales_person_code ONLY —
        route_name is never touched)
       Logs old/new values under one batch_id so the
       whole batch can be reversed later.
       ───────────────────────────────────────────── */
    if ($ajax === 'do_update') {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        if (empty($items)) {
            echo json_encode(['success' => false, 'error' => 'No rows selected.']);
            exit;
        }

        $batch_id = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $updated_bills = 0;
        $updated_rows  = 0;

        mysqli_begin_transaction($conn);
        try {
            foreach ($items as $item) {
                $lsid_ids = is_array($item['lsid_ids'] ?? null) ? $item['lsid_ids'] : [];
                if (empty($lsid_ids)) continue;

                $new_route_name = trim($item['new_route_name'] ?? '');
                $new_sr_code    = trim($item['new_sr_code'] ?? '');
                $t_code         = trim($item['t_code'] ?? '');
                $invoice_num    = trim($item['invoice_num'] ?? '');

                if ($new_route_name === '' && $new_sr_code === '') continue;

                $ids_escaped = array_map('intval', $lsid_ids);
                $ids_list = implode(',', $ids_escaped);

                // Fetch current values first so we can log + support reverse
                $cur_res = mysqli_query($conn, "SELECT id, route_code, sales_person_code FROM loading_summary_import_details WHERE id IN ($ids_list)");
                if (!$cur_res) throw new Exception(mysqli_error($conn));

                $sets = [];
                if ($new_route_name !== '') $sets[] = "route_code = '" . mysqli_real_escape_string($conn, $new_route_name) . "'";
                if ($new_sr_code    !== '') $sets[] = "sales_person_code = '" . mysqli_real_escape_string($conn, $new_sr_code) . "'";
                if (empty($sets)) continue;

                while ($cur = mysqli_fetch_assoc($cur_res)) {
                    $log_sql = "INSERT INTO credit_bill_beat_update_log
                        (batch_id, lsid_id, bill_no, t_code, old_route_name, new_route_name, old_sr_code, new_sr_code)
                        VALUES (
                            '" . mysqli_real_escape_string($conn, $batch_id) . "',
                            " . intval($cur['id']) . ",
                            '" . mysqli_real_escape_string($conn, $invoice_num) . "',
                            '" . mysqli_real_escape_string($conn, $t_code) . "',
                            '" . mysqli_real_escape_string($conn, $cur['route_code'] ?? '') . "',
                            '" . mysqli_real_escape_string($conn, $new_route_name !== '' ? $new_route_name : ($cur['route_code'] ?? '')) . "',
                            '" . mysqli_real_escape_string($conn, $cur['sales_person_code'] ?? '') . "',
                            '" . mysqli_real_escape_string($conn, $new_sr_code !== '' ? $new_sr_code : ($cur['sales_person_code'] ?? '')) . "'
                        )";
                    if (!mysqli_query($conn, $log_sql)) throw new Exception(mysqli_error($conn));
                }

                $sql = "UPDATE loading_summary_import_details SET " . implode(', ', $sets) . " WHERE id IN ($ids_list)";
                if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));

                $updated_rows += mysqli_affected_rows($conn);
                $updated_bills++;
            }
            mysqli_commit($conn);
        } catch (Exception $e) {
            mysqli_rollback($conn);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }

        echo json_encode(['success' => true, 'batch_id' => $batch_id, 'updated_bills' => $updated_bills, 'updated_rows' => $updated_rows]);
        exit;
    }

    /* ─────────────────────────────────────────────
       LIST BATCHES for the Recent Updates panel
       ───────────────────────────────────────────── */
    if ($ajax === 'list_batches') {
        $res = mysqli_query($conn, "
            SELECT
                batch_id,
                MIN(created_at) AS created_at,
                COUNT(*) AS row_count,
                SUM(CASE WHEN reversed = 0 THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN reversed = 1 THEN 1 ELSE 0 END) AS reversed_count
            FROM credit_bill_beat_update_log
            GROUP BY batch_id
            ORDER BY MIN(created_at) DESC
            LIMIT 25
        ");
        $batches = [];
        if ($res) while ($r = mysqli_fetch_assoc($res)) $batches[] = $r;
        echo json_encode(['success' => true, 'batches' => $batches]);
        exit;
    }

    /* ─────────────────────────────────────────────
       REVERSE a batch: restore old_route_name (route_code) /
       old_sr_code for every not-yet-reversed row
       in that batch.
       ───────────────────────────────────────────── */
    if ($ajax === 'reverse_batch') {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        $batch_id = trim($payload['batch_id'] ?? '');

        if ($batch_id === '') {
            echo json_encode(['success' => false, 'error' => 'Missing batch id.']);
            exit;
        }

        $esc_batch = mysqli_real_escape_string($conn, $batch_id);
        $res = mysqli_query($conn, "SELECT * FROM credit_bill_beat_update_log WHERE batch_id = '$esc_batch' AND reversed = 0");
        if (!$res) { echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]); exit; }

        $log_rows = [];
        while ($r = mysqli_fetch_assoc($res)) $log_rows[] = $r;

        if (empty($log_rows)) {
            echo json_encode(['success' => false, 'error' => 'Nothing to reverse for this batch (already reversed).']);
            exit;
        }

        $reversed_count = 0;
        mysqli_begin_transaction($conn);
        try {
            foreach ($log_rows as $lr) {
                $sql = "UPDATE loading_summary_import_details SET
                            route_code = '" . mysqli_real_escape_string($conn, $lr['old_route_name']) . "',
                            sales_person_code = '" . mysqli_real_escape_string($conn, $lr['old_sr_code']) . "'
                        WHERE id = " . intval($lr['lsid_id']);
                if (!mysqli_query($conn, $sql)) throw new Exception(mysqli_error($conn));

                if (!mysqli_query($conn, "UPDATE credit_bill_beat_update_log SET reversed = 1, reversed_at = NOW() WHERE id = " . intval($lr['id']))) {
                    throw new Exception(mysqli_error($conn));
                }
                $reversed_count++;
            }
            mysqli_commit($conn);
        } catch (Exception $e) {
            mysqli_rollback($conn);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }

        echo json_encode(['success' => true, 'reversed_count' => $reversed_count]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

include 'header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<style>
*{box-sizing:border-box;}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;}
.page-title{font-size:20px;font-weight:800;color:#1f2937;display:flex;align-items:center;gap:8px;}

.upload-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:20px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.upload-title{font-size:13px;font-weight:700;color:#374151;margin-bottom:14px;display:flex;align-items:center;gap:6px;}
.upload-row{display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
.file-input-wrap{flex:1;min-width:260px;}
.file-input-wrap input[type=file]{border:1px dashed #c7c7f5;border-radius:8px;padding:10px 12px;width:100%;font-size:13px;background:#faf9ff;}

.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s;white-space:nowrap;}
.btn-primary{background:#6366f1;color:#fff;}.btn-primary:hover{background:#4f46e5;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-success{background:#16a34a;color:#fff;}.btn-success:hover{background:#15803d;}
.btn-amber{background:#d97706;color:#fff;}.btn-amber:hover{background:#b45309;}
.btn:disabled{opacity:.5;cursor:not-allowed;}
.btn-sm{padding:6px 12px;font-size:12px;}

.file-meta{font-size:12px;color:#6b7280;margin-top:8px;}
.file-meta strong{color:#374151;}

.summary-strip{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:18px;}
.sstat{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:12px 18px;min-width:130px;}
.sstat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.sstat-value{font-size:18px;font-weight:800;color:#1f2937;}
.sstat-value.violet{color:#6366f1;}.sstat-value.green{color:#16a34a;}.sstat-value.amber{color:#d97706;}.sstat-value.gray{color:#9ca3af;}

.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);margin-bottom:20px;}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;}
.pill-violet{background:#ede9fe;color:#5b21b6;}
.pill-green{background:#dcfce7;color:#166534;}
.pill-gray{background:#f3f4f6;color:#6b7280;}
.pill-amber{background:#fef3c7;color:#92400e;}

.dt-wrap{overflow-x:auto;max-height:640px;overflow-y:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead th{position:sticky;top:0;z-index:2;padding:9px 8px;text-align:left;font-weight:700;font-size:11px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;}
.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr.row-changed td{background:#fefce8;}
.data-table tbody tr.row-changed:hover td{background:#fef9c3;}
.data-table tbody tr.row-nochange td{background:#fff;}
.data-table tbody tr.row-nochange:hover td{background:#f9fafb;}
.data-table tbody tr.row-notarget td{background:#fef2f2;}
.data-table td{padding:7px 8px;color:#374151;vertical-align:middle;}
.tc{text-align:center;}

.code-old{font-family:monospace;font-size:12px;color:#9ca3af;text-decoration:line-through;}
.code-new{font-family:monospace;font-size:12px;font-weight:700;color:#16a34a;}
.code-same{font-family:monospace;font-size:12px;color:#374151;}
.arrow-sep{color:#c7c7c7;margin:0 4px;font-size:11px;}

.status-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:9px;font-size:10px;font-weight:700;}
.status-changed{background:#fef9c3;color:#854d0e;border:1px solid #fde047;}
.status-nochange{background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;}
.status-notarget{background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;}

.action-bar{display:none;position:sticky;bottom:0;z-index:100;background:#1e1b4b;color:#fff;padding:12px 20px;align-items:center;justify-content:space-between;gap:12px;border-top:2px solid #6366f1;border-radius:0 0 10px 10px;}
.action-bar.visible{display:flex;}
.ab-info{font-size:13px;font-weight:700;display:flex;align-items:center;gap:10px;}
.ab-count{background:#6366f1;color:#fff;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:700;}

.batch-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 18px;border-bottom:1px solid #f3f4f6;font-size:12.5px;}
.batch-row:last-child{border-bottom:none;}
.batch-id{font-family:monospace;font-weight:700;color:#374151;}
.batch-meta{color:#9ca3af;font-size:11px;}
.batch-empty{padding:24px;text-align:center;color:#9ca3af;font-size:13px;}

.state-box{text-align:center;padding:60px 30px;color:#9ca3af;}
.state-box i{font-size:40px;display:block;margin-bottom:12px;opacity:.4;}
.state-box p{font-size:14px;color:#6b7280;margin:0 0 6px;}
.state-box small{font-size:12px;}

#__bu_toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(80px);z-index:99999;padding:11px 22px;border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.2);transition:transform .3s;display:flex;align-items:center;gap:8px;background:#166534;color:#fff;white-space:nowrap;}
</style>

<div class="page-header">
    <div class="page-title">
        <i class="fa-solid fa-route" style="color:#6366f1;"></i>
        Credit Bill — Beat / Route / SR Update
    </div>
    <div style="display:flex;gap:8px;">
        <a href="credit_bill_issue.php" class="btn btn-secondary btn-sm">
            <i class="fa-solid fa-paper-plane"></i> Credit Bill Issue
        </a>
    </div>
</div>

<div class="upload-card">
    <div class="upload-title">
        <i class="fa-solid fa-file-excel"></i>
        Upload Beat Mapping File (columns: T Code, Route, SR Code) — updates Route Code &amp; SR Code only. Route Name is never touched.
    </div>
    <div class="upload-row">
        <div class="file-input-wrap">
            <input type="file" id="beatFile" accept=".xlsx,.xls">
        </div>
        <button class="btn btn-primary" id="parseBtn" onclick="parseAndPreview()">
            <i class="fa-solid fa-magnifying-glass"></i> Parse &amp; Preview
        </button>
    </div>
    <div class="file-meta" id="fileMeta"></div>
</div>

<div id="resultsWrap" style="display:none;">

    <div class="summary-strip">
        <div class="sstat"><div class="sstat-label">Rows In File</div><div class="sstat-value" id="stFileRows">0</div></div>
        <div class="sstat"><div class="sstat-label">Matched Credit Bills</div><div class="sstat-value violet" id="stMatched">0</div></div>
        <div class="sstat"><div class="sstat-label">Needs Update</div><div class="sstat-value amber" id="stChanged">0</div></div>
        <div class="sstat"><div class="sstat-label">Already Same</div><div class="sstat-value gray" id="stSame">0</div></div>
        <div class="sstat"><div class="sstat-label">No Loading Record</div><div class="sstat-value" style="color:#dc2626;" id="stNoTarget">0</div></div>
        <div class="sstat"><div class="sstat-label">Not In Outstanding Bills</div><div class="sstat-value gray" id="stUnmatched">0</div></div>
    </div>

    <div class="table-card">
        <div class="table-toolbar">
            <div class="tbl-title">
                <i class="fa-solid fa-table"></i> Preview
                <span class="pill pill-violet" id="rowCountBadge">0 rows</span>
            </div>
            <label style="font-size:12px;font-weight:700;color:#6b7280;display:flex;align-items:center;gap:5px;cursor:pointer;">
                <input type="checkbox" id="selectAll" onchange="toggleSelectAll()"> Select All (changed only)
            </label>
        </div>
        <div class="dt-wrap">
        <table class="data-table" id="previewTable">
            <thead>
                <tr>
                    <th class="tc"><i class="fa-solid fa-square-check" style="color:#a5b4fc;"></i></th>
                    <th>T Code</th>
                    <th>Invoice / Bill No</th>
                    <th>Customer</th>
                    <th class="tc">Route Code (Old → New)</th>
                    <th class="tc">SR Code (Old → New)</th>
                    <th class="tc">Status</th>
                </tr>
            </thead>
            <tbody id="previewBody"></tbody>
        </table>
        </div>
        <div class="action-bar" id="actionBar">
            <div class="ab-info">
                <i class="fa-solid fa-square-check" style="color:#a5b4fc;"></i>
                <span>Selected:</span> <span class="ab-count" id="selCount">0</span>
            </div>
            <button class="btn btn-success" onclick="doUpdate()" id="updateBtn">
                <i class="fa-solid fa-floppy-disk"></i> Update Selected
            </button>
        </div>
    </div>
</div>

<!-- RECENT UPDATES / REVERSE PANEL -->
<div class="table-card">
    <div class="table-toolbar">
        <div class="tbl-title">
            <i class="fa-solid fa-clock-rotate-left"></i> Recent Update Batches
            <span class="pill pill-gray" id="batchCountBadge">0</span>
        </div>
        <button class="btn btn-secondary btn-sm" onclick="loadBatches()">
            <i class="fa-solid fa-rotate"></i> Refresh
        </button>
    </div>
    <div id="batchList"><div class="batch-empty">Loading…</div></div>
</div>

<div id="__bu_toast"></div>

<script>
let PREVIEW_ROWS = [];

function escHtml(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function showToast(msg,type='success'){
    const t=document.getElementById('__bu_toast');
    t.style.background=type==='success'?'#166534':'#dc2626';
    t.textContent=msg; t.style.transform='translateX(-50%) translateY(0)';
    clearTimeout(t._tm);
    t._tm=setTimeout(()=>{ t.style.transform='translateX(-50%) translateY(80px)'; },4000);
}

document.getElementById('beatFile').addEventListener('change', function(){
    const f = this.files[0];
    document.getElementById('fileMeta').innerHTML = f
        ? 'Selected: <strong>'+escHtml(f.name)+'</strong> ('+(f.size/1024).toFixed(1)+' KB)'
        : '';
});

function parseAndPreview(){
    const fileInput = document.getElementById('beatFile');
    const file = fileInput.files[0];
    if(!file){ showToast('Please choose a file first','error'); return; }

    const btn = document.getElementById('parseBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Reading file...';

    const reader = new FileReader();
    reader.onload = function(e){
        try{
            const data = new Uint8Array(e.target.result);
            const wb = XLSX.read(data, {type:'array'});
            const sheetName = wb.SheetNames.includes('Outlet Service Info') ? 'Outlet Service Info' : wb.SheetNames[0];
            const ws = wb.Sheets[sheetName];
            const json = XLSX.utils.sheet_to_json(ws, {header:1, raw:false, defval:''});

            // First row is header: T Code, Route, SR Code
            const rows = [];
            for(let i=1;i<json.length;i++){
                const r = json[i];
                if(!r || !r[0]) continue;
                rows.push({
                    t_code: String(r[0]).trim(),
                    route_name: String(r[1]||'').trim(),
                    sr_code: String(r[2]||'').trim()
                });
            }

            if(!rows.length){ showToast('No data rows found in the file','error'); resetParseBtn(); return; }

            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Matching against credit bills...';

            fetch('credit_bill_beat_update.php?ajax=preview',{
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body: JSON.stringify({rows: rows})
            })
            .then(r=>r.json())
            .then(data=>{
                resetParseBtn();
                if(!data.success){ showToast(data.error||'Preview failed','error'); return; }
                PREVIEW_ROWS = data.rows;
                renderPreview(rows.length, data.unmatched_count);
            })
            .catch(()=>{ resetParseBtn(); showToast('Network error while matching','error'); });

        }catch(err){
            resetParseBtn();
            showToast('Could not read Excel file: '+err.message,'error');
        }
    };
    reader.readAsArrayBuffer(file);
}

function resetParseBtn(){
    const btn = document.getElementById('parseBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Parse &amp; Preview';
}

function renderPreview(fileRowCount, unmatchedCount){
    document.getElementById('resultsWrap').style.display = '';
    document.getElementById('stFileRows').textContent = fileRowCount;
    document.getElementById('stMatched').textContent = PREVIEW_ROWS.length;
    document.getElementById('stUnmatched').textContent = unmatchedCount;

    let changed=0, same=0, noTarget=0;
    const tbody = document.getElementById('previewBody');
    tbody.innerHTML = '';

    PREVIEW_ROWS.forEach((row, idx) => {
        let rowClass, statusHtml, cbDisabled = '';

        if(!row.has_target){
            noTarget++;
            rowClass = 'row-notarget';
            statusHtml = '<span class="status-badge status-notarget"><i class="fa-solid fa-triangle-exclamation"></i> No Loading Record</span>';
            cbDisabled = 'disabled';
        } else if(!row.changed){
            same++;
            rowClass = 'row-nochange';
            statusHtml = '<span class="status-badge status-nochange"><i class="fa-solid fa-check"></i> Already Same</span>';
        } else {
            changed++;
            rowClass = 'row-changed';
            statusHtml = '<span class="status-badge status-changed"><i class="fa-solid fa-pen"></i> Needs Update</span>';
        }

        const routeNameCell = `<span class="code-old">${escHtml(row.old_route_name||'—')}</span><span class="arrow-sep">→</span><span class="${row.new_route_name && row.new_route_name!==row.old_route_name?'code-new':'code-same'}">${escHtml(row.new_route_name||row.old_route_name||'—')}</span>`;

        const srCell = `<span class="code-old">${escHtml(row.old_sr_code||'—')}</span><span class="arrow-sep">→</span><span class="${row.new_sr_code && row.new_sr_code!==row.old_sr_code?'code-new':'code-same'}">${escHtml(row.new_sr_code||row.old_sr_code||'—')}</span>`;

        const tr = document.createElement('tr');
        tr.className = rowClass;
        tr.innerHTML = `
            <td class="tc"><input type="checkbox" class="row-cb" data-idx="${idx}" onchange="onCheckChange()" ${cbDisabled} ${row.changed?'checked':''}></td>
            <td><span style="font-family:monospace;font-weight:700;">${escHtml(row.t_code)}</span></td>
            <td><span style="font-family:monospace;">${escHtml(row.invoice_num)}</span></td>
            <td>${escHtml(row.customer_name)}</td>
            <td class="tc">${routeNameCell}</td>
            <td class="tc">${srCell}</td>
            <td class="tc">${statusHtml}</td>
        `;
        tbody.appendChild(tr);
    });

    document.getElementById('stChanged').textContent = changed;
    document.getElementById('stSame').textContent = same;
    document.getElementById('stNoTarget').textContent = noTarget;
    document.getElementById('rowCountBadge').textContent = PREVIEW_ROWS.length + ' rows';
    document.getElementById('selectAll').checked = false;
    onCheckChange();
}

function toggleSelectAll(){
    const all = document.getElementById('selectAll').checked;
    document.querySelectorAll('.row-cb:not(:disabled)').forEach(cb=>cb.checked=all);
    onCheckChange();
}

function onCheckChange(){
    const checked = document.querySelectorAll('.row-cb:checked');
    document.getElementById('selCount').textContent = checked.length;
    document.getElementById('actionBar').classList.toggle('visible', checked.length>0);
}

function doUpdate(){
    const checked = document.querySelectorAll('.row-cb:checked');
    if(!checked.length){ showToast('No rows selected','error'); return; }

    const items = [];
    checked.forEach(cb=>{
        const row = PREVIEW_ROWS[parseInt(cb.dataset.idx)];
        items.push({
            lsid_ids: row.lsid_ids,
            t_code: row.t_code,
            invoice_num: row.invoice_num,
            new_route_name: row.new_route_name || '',
            new_sr_code: row.new_sr_code || ''
        });
    });

    const btn = document.getElementById('updateBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Updating...';

    fetch('credit_bill_beat_update.php?ajax=do_update',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({items: items})
    })
    .then(r=>r.json())
    .then(data=>{
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Update Selected';
        if(data.success){
            showToast('✓ Updated '+data.updated_bills+' bill(s) — Batch '+data.batch_id,'success');
            checked.forEach(cb=>{
                const idx = parseInt(cb.dataset.idx);
                const row = PREVIEW_ROWS[idx];
                row.old_route_name = row.new_route_name || row.old_route_name;
                row.old_sr_code    = row.new_sr_code || row.old_sr_code;
                row.changed = false;
            });
            renderPreview(document.getElementById('stFileRows').textContent, document.getElementById('stUnmatched').textContent);
            loadBatches();
        } else {
            showToast(data.error||'Update failed','error');
        }
    })
    .catch(()=>{
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Update Selected';
        showToast('Network error','error');
    });
}

/* ═══════ RECENT BATCHES / REVERSE ═══════ */
function loadBatches(){
    const list = document.getElementById('batchList');
    list.innerHTML = '<div class="batch-empty">Loading…</div>';
    fetch('credit_bill_beat_update.php?ajax=list_batches')
    .then(r=>r.json())
    .then(data=>{
        if(!data.success){ list.innerHTML = '<div class="batch-empty">Failed to load history</div>'; return; }
        renderBatches(data.batches);
    })
    .catch(()=>{ list.innerHTML = '<div class="batch-empty">Network error</div>'; });
}

function renderBatches(batches){
    document.getElementById('batchCountBadge').textContent = batches.length;
    const list = document.getElementById('batchList');
    if(!batches.length){ list.innerHTML = '<div class="batch-empty">No updates yet</div>'; return; }

    list.innerHTML = '';
    batches.forEach(b=>{
        const fullyReversed = parseInt(b.active_count) === 0;
        const row = document.createElement('div');
        row.className = 'batch-row';
        row.innerHTML = `
            <div>
                <div class="batch-id">${escHtml(b.batch_id)}</div>
                <div class="batch-meta">${escHtml(b.created_at)} — ${b.row_count} row(s)${fullyReversed?' <span class="pill pill-gray">Reversed</span>':''}</div>
            </div>
            <button class="btn btn-amber btn-sm" ${fullyReversed?'disabled':''} onclick="reverseBatch('${escHtml(b.batch_id)}', this)">
                <i class="fa-solid fa-rotate-left"></i> Reverse
            </button>
        `;
        list.appendChild(row);
    });
}

function reverseBatch(batchId, btnEl){
    if(!confirm('Reverse batch '+batchId+'? This restores the previous Route Code / SR Code values for every row in this batch.')) return;

    btnEl.disabled = true; btnEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

    fetch('credit_bill_beat_update.php?ajax=reverse_batch',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({batch_id: batchId})
    })
    .then(r=>r.json())
    .then(data=>{
        if(data.success){
            showToast('✓ Reversed '+data.reversed_count+' row(s) in batch '+batchId,'success');
            loadBatches();
        } else {
            showToast(data.error||'Reverse failed','error');
            btnEl.disabled = false; btnEl.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Reverse';
        }
    })
    .catch(()=>{
        showToast('Network error','error');
        btnEl.disabled = false; btnEl.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Reverse';
    });
}

document.addEventListener('DOMContentLoaded', loadBatches);
</script>

<?php include 'footer.php'; ?>