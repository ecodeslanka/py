<?php
/**
 * credit_policy_import.php
 * Import HUL Credit Policy Excel → match customers by t_code → preview & bulk-update
 * Pure PHP xlsx parser — no shell_exec, no composer required.
 */
include 'config.php';

// customers.cheques_will_delay is a shared production table column that should already exist
// (added via edit_customer.php). We only detect it here — never ALTER the `customers` table
// from this page, since ALTER on a large shared table can lock/rebuild it and hang the request.
$CUSTOMERS_HAS_CHEQUES_DELAY = false;
$cp_col_chk = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'cheques_will_delay'");
if ($cp_col_chk && mysqli_num_rows($cp_col_chk) > 0) {
    $CUSTOMERS_HAS_CHEQUES_DELAY = true;
}

// ── Pure PHP XLSX parser (ZipArchive + SimpleXML) ────────────────────────────
function parseXlsx($filepath, &$error) {
    if (!class_exists('ZipArchive')) { $error = 'ZipArchive extension not available.'; return []; }
    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) { $error = 'Cannot open Excel file.'; return []; }

    // Shared strings
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = simplexml_load_string($ssXml);
        foreach ($ss->si as $si) {
            $t = '';
            if (isset($si->t)) {
                $t = (string)$si->t;
            } else {
                foreach ($si->r as $r) {
                    if (isset($r->t)) $t .= (string)$r->t;
                }
            }
            $sharedStrings[] = $t;
        }
    }

    // Find first sheet
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheetXml) {
        // Try workbook to find first sheet name
        $wbXml = $zip->getFromName('xl/workbook.xml');
        if ($wbXml) {
            $wb = simplexml_load_string($wbXml);
            $wb->registerXPathNamespace('ns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $sheets = $wb->xpath('//ns:sheet');
            if (!empty($sheets)) {
                $rid = (string)$sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
                if ($relsXml) {
                    $rels = simplexml_load_string($relsXml);
                    foreach ($rels->Relationship as $rel) {
                        if ((string)$rel['Id'] === $rid) {
                            $target = 'xl/' . ltrim((string)$rel['Target'], '/');
                            $sheetXml = $zip->getFromName($target);
                            break;
                        }
                    }
                }
            }
        }
    }
    $zip->close();

    if (!$sheetXml) { $error = 'Could not read sheet data from Excel file.'; return []; }

    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $sheet = simplexml_load_string($sheetXml);
    $sheet->registerXPathNamespace('ns', $ns);

    $rows = $sheet->xpath('//ns:row');
    $headers = [];
    $data = [];

    foreach ($rows as $rowIdx => $row) {
        $rowData = [];
        foreach ($row->c as $cell) {
            // Get column letter from ref (e.g. "A1" → "A")
            $ref = (string)$cell['r'];
            preg_match('/^([A-Z]+)/', $ref, $m);
            $col = $m[1] ?? '';
            $colNum = 0;
            for ($i = 0; $i < strlen($col); $i++) {
                $colNum = $colNum * 26 + (ord($col[$i]) - ord('A') + 1);
            }
            $colNum--; // 0-based

            $t = (string)$cell['t'];
            $v = (string)$cell->v;

            if ($t === 's') {
                $val = isset($sharedStrings[(int)$v]) ? $sharedStrings[(int)$v] : '';
            } elseif ($t === 'inlineStr') {
                $val = (string)$cell->is->t;
            } else {
                $val = $v;
            }
            $rowData[$colNum] = trim($val);
        }

        if ($rowIdx === 0) {
            // Header row
            $headers = $rowData;
        } else {
            // Map by header name
            $mapped = [];
            foreach ($headers as $ci => $hdr) {
                $mapped[$hdr] = $rowData[$ci] ?? '';
            }
            $hul = trim($mapped['Outlet HUL Code'] ?? '');
            if ($hul === '' || strtolower($hul) === 'outlet hul code') continue;
            $pm = strtolower(trim($mapped['PAYEMNT MODE'] ?? $mapped['PAYMENT MODE'] ?? ''));
            $pd = trim($mapped['POLICY DAYS'] ?? '');
            $cd_raw = trim($mapped['CHEQUES DELAY'] ?? '');
            // CHEQUES DELAY column: 1 = will delay, 0/blank = will not delay
            $cd = ($cd_raw !== '' && intval($cd_raw) === 1) ? 1 : 0;
            $data[] = [
                'hul_code'      => $hul,
                'party_name'    => trim($mapped['Party Name'] ?? ''),
                'pay_mode'      => $pm,
                'policy_days'   => $pd,
                'cheques_delay' => $cd,
            ];
        }
    }
    return $data;
}

// ── AJAX: Update single customer ─────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'update_one') {
    header('Content-Type: application/json');
    $cust_id     = intval($_POST['customer_id'] ?? 0);
    $pay_mode    = strtolower(trim($_POST['payment_mode'] ?? ''));
    $policy_days = intval($_POST['policy_days'] ?? 0);
    $hul_code    = trim($_POST['hul_code'] ?? '');
    $cheques_delay = isset($_POST['cheques_delay']) && intval($_POST['cheques_delay']) === 1 ? 1 : 0;
    if (!$cust_id) { echo json_encode(['ok'=>false,'msg'=>'Invalid ID']); exit; }
    $allowed = ['cash','credit','cheque'];
    if (!in_array($pay_mode, $allowed)) { echo json_encode(['ok'=>false,'msg'=>'Invalid payment mode']); exit; }
    $pm_esc  = mysqli_real_escape_string($conn, $pay_mode);
    $hul_esc = mysqli_real_escape_string($conn, $hul_code);
    $cd_set_sql = $CUSTOMERS_HAS_CHEQUES_DELAY ? ", cheques_will_delay=$cheques_delay" : "";
    if (mysqli_query($conn, "UPDATE customers SET payment_mode='$pm_esc', credit_days=$policy_days$cd_set_sql WHERE id=$cust_id")) {
        mysqli_query($conn, "INSERT INTO credit_policy_import_history
            (customer_id, hul_code, payment_mode, credit_days, cheques_delay, updated_at, source)
            VALUES ($cust_id,'$hul_esc','$pm_esc',$policy_days,$cheques_delay,NOW(),'excel_import')");
        echo json_encode(['ok'=>true]);
    } else {
        echo json_encode(['ok'=>false,'msg'=>mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Update all ─────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'update_all') {
    header('Content-Type: application/json');
    $rows = json_decode(file_get_contents('php://input'), true) ?? [];
    $updated = 0; $failed = 0;
    foreach ($rows as $r) {
        $cid  = intval($r['customer_id'] ?? 0);
        $pm   = strtolower(trim($r['payment_mode'] ?? ''));
        $pd   = intval($r['policy_days'] ?? 0);
        $cd   = isset($r['cheques_delay']) && intval($r['cheques_delay']) === 1 ? 1 : 0;
        $hul  = mysqli_real_escape_string($conn, $r['hul_code'] ?? '');
        $pm_e = mysqli_real_escape_string($conn, $pm);
        if (!$cid || !in_array($pm, ['cash','credit','cheque'])) { $failed++; continue; }
        $cd_set_sql = $CUSTOMERS_HAS_CHEQUES_DELAY ? ", cheques_will_delay=$cd" : "";
        if (mysqli_query($conn, "UPDATE customers SET payment_mode='$pm_e', credit_days=$pd$cd_set_sql WHERE id=$cid")) {
            mysqli_query($conn, "INSERT INTO credit_policy_import_history
                (customer_id,hul_code,payment_mode,credit_days,cheques_delay,updated_at,source)
                VALUES ($cid,'$hul','$pm_e',$pd,$cd,NOW(),'excel_import')");
            $updated++;
        } else { $failed++; }
    }
    echo json_encode(['ok'=>true,'updated'=>$updated,'failed'=>$failed]);
    exit;
}

// ── Ensure history table exists / migrate ────────────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS credit_policy_import_history (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    customer_id  INT NOT NULL,
    hul_code     VARCHAR(100),
    payment_mode VARCHAR(20),
    credit_days  INT,
    cheques_delay TINYINT(1) DEFAULT 0,
    updated_at   DATETIME,
    source       VARCHAR(50) DEFAULT 'excel_import',
    upload_batch VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// SHOW COLUMNS safety net in case an older version of this small history table already exists on the server.
// (This table is owned by this feature, so altering it here is safe.)
$col_chk = mysqli_query($conn, "SHOW COLUMNS FROM credit_policy_import_history LIKE 'cheques_delay'");
if ($col_chk && mysqli_num_rows($col_chk) === 0) {
    mysqli_query($conn, "ALTER TABLE credit_policy_import_history ADD COLUMN cheques_delay TINYINT(1) DEFAULT 0 AFTER credit_days");
}


// ── Handle upload ─────────────────────────────────────────────────────────────
$preview_rows   = [];
$unmatched_rows = [];
$upload_error   = '';
$file_name      = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $upload_error = 'Upload error code: ' . $file['error'];
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx','xls'])) {
            $upload_error = 'Only .xlsx / .xls files accepted.';
        } else {
            $file_name = basename($file['name']);
            $parsed = parseXlsx($file['tmp_name'], $upload_error);

            if (empty($upload_error) && is_array($parsed)) {
                foreach ($parsed as $row) {
                    $hul    = $row['hul_code'];
                    $pm_raw = $row['pay_mode'];
                    $pd_raw = $row['policy_days'];
                    $cd_val = $row['cheques_delay'];
                    if (empty($hul)) continue;

                    $hul_esc = mysqli_real_escape_string($conn, $hul);
                    $cd_col_sql = $CUSTOMERS_HAS_CHEQUES_DELAY ? ", cheques_will_delay" : "";
                    $cust_q  = mysqli_query($conn,
                        "SELECT id, t_code, shop_name, payment_mode, credit_days$cd_col_sql
                         FROM customers WHERE t_code = '$hul_esc' LIMIT 1");

                    $pm_map  = ['cash'=>'cash','credit'=>'credit','cheque'=>'cheque','check'=>'cheque'];
                    $pm_norm = $pm_map[$pm_raw] ?? $pm_raw;
                    $pd_int  = is_numeric($pd_raw) ? intval($pd_raw) : null;

                    if ($cust_q && mysqli_num_rows($cust_q) > 0) {
                        $cust = mysqli_fetch_assoc($cust_q);
                        $db_cd = intval($cust['cheques_will_delay'] ?? 0);
                        $preview_rows[] = [
                            'customer_id'    => $cust['id'],
                            'db_t_code'      => $cust['t_code'],
                            'hul_code'       => $hul,
                            'shop_name'      => $cust['shop_name'],
                            'db_pay_mode'    => $cust['payment_mode'],
                            'db_policy_days' => $cust['credit_days'],
                            'db_cheques_delay' => $db_cd,
                            'xl_party_name'  => $row['party_name'],
                            'xl_pay_mode'    => $pm_norm,
                            'xl_policy_days' => $pd_int,
                            'xl_cheques_delay' => $cd_val,
                            'changed'        => ($pm_norm !== strtolower($cust['payment_mode'])) ||
                                               ($pd_int !== null && $pd_int != intval($cust['credit_days'])) ||
                                               ($cd_val != $db_cd)
                        ];
                    } else {
                        $unmatched_rows[] = [
                            'hul_code'    => $hul,
                            'party_name'  => $row['party_name'],
                            'xl_pay_mode' => $pm_norm,
                            'xl_pd'       => $pd_int,
                            'xl_cd'       => $cd_val,
                        ];
                    }
                }
            }
        }
    }
}

// ── Load history ──────────────────────────────────────────────────────────────
$history_rows = [];
$hr = mysqli_query($conn,
    "SELECT h.*, c.shop_name, c.t_code
     FROM credit_policy_import_history h
     LEFT JOIN customers c ON c.id = h.customer_id
     ORDER BY h.updated_at DESC LIMIT 100");
if ($hr) { while ($row = mysqli_fetch_assoc($hr)) $history_rows[] = $row; }

include 'header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>
:root{ --green:#16a34a;--red:#dc2626;--blue:#2563eb; }
.page-title{ font-size:20px;font-weight:700;color:#111; }
.page-sub{ font-size:13px;color:#64748b;margin-top:2px; }

/* Tabs */
.tab-bar{ display:flex;gap:4px;border-bottom:2px solid #e5e7eb;margin-bottom:20px; }
.tab-btn{ padding:8px 18px;font-size:13px;font-weight:600;border:none;background:none;
    cursor:pointer;color:#6b7280;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .2s; }
.tab-btn.active{ color:#111;border-bottom-color:#111; }
.tab-pane{ display:none; }
.tab-pane.active{ display:block; }

/* Upload zone */
.upload-zone{ background:#fff;border:2px dashed #d1d5db;border-radius:12px;padding:36px 24px;
    text-align:center;cursor:pointer;transition:all .2s; }
.upload-zone:hover,.upload-zone.drag-over{ border-color:#2563eb;background:#eff6ff; }
.upload-zone i{ font-size:36px;color:#16a34a;display:block;margin-bottom:12px; }
#excel_file{ display:none; }

/* Stats */
.stats-strip{ display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap; }
.stat-box{ flex:1;min-width:130px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;text-align:center; }
.stat-box .num{ font-size:24px;font-weight:800; }
.stat-box .lbl{ font-size:11px;color:#6b7280;font-weight:600;margin-top:2px; }
.s-total .num{ color:#111; }
.s-matched .num{ color:var(--green); }
.s-changed .num{ color:var(--blue); }
.s-unmatch .num{ color:var(--red); }

/* Table */
.dt-wrap{ overflow-x:auto; }
table.dt{ width:100%;border-collapse:collapse;font-size:12px; }
table.dt th{ background:#f1f5f9;padding:8px 10px;text-align:left;font-size:11px;font-weight:700;
    color:#475569;border-bottom:2px solid #e2e8f0;white-space:nowrap; }
table.dt td{ padding:8px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle; }
table.dt tr:hover td{ background:#f8fafc; }
table.dt tr.changed td{ background:#fffbeb; }
table.dt tr.updated-ok td{ background:#f0fdf4!important; }
table.dt tr.update-fail td{ background:#fef2f2!important; }

/* Badges */
.badge{ display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase; }
.badge-cash{ background:#dcfce7;color:#166534; }
.badge-credit{ background:#dbeafe;color:#1e40af; }
.badge-cheque{ background:#fef9c3;color:#854d0e; }
.badge-unknown{ background:#f3f4f6;color:#6b7280; }
.badge-changed{ background:#fef3c7;color:#92400e; }
.badge-same{ background:#f0fdf4;color:#166534; }
.badge-delay-yes{ background:#fde68a;color:#92400e; }
.badge-delay-no{ background:#f3f4f6;color:#6b7280; }

/* Buttons */
.btn-upd{ background:#111;color:#fff;border:none;padding:5px 12px;border-radius:5px;font-size:11px;
    font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:all .2s;white-space:nowrap; }
.btn-upd:hover{ background:#333; }
.btn-upd:disabled{ background:#9ca3af;cursor:not-allowed; }
.btn-upd.done{ background:var(--green); }
.btn-upd.fail{ background:var(--red); }
.btn-bulk{ background:#2563eb;color:#fff;border:none;padding:8px 18px;border-radius:7px;font-size:13px;
    font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s; }
.btn-bulk:hover{ background:#1d4ed8; }
.btn-bulk:disabled{ background:#9ca3af;cursor:not-allowed; }
.btn-sec{ background:#f5f5f5;color:#333;border:1px solid #e5e5e5;padding:7px 14px;border-radius:6px;
    font-size:12px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none; }
.btn-sec:hover{ background:#e5e5e5; }
.btn-parse{ background:#000;color:#fff;border:none;padding:10px 28px;border-radius:7px;font-size:13px;
    font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;margin-top:16px; }
.btn-parse:hover{ background:#333; }

/* Sticky bar */
.sticky-bar{ position:sticky;bottom:0;background:#fff;border-top:1px solid #e5e7eb;
    padding:10px 0;z-index:10;display:flex;gap:8px;align-items:center;flex-wrap:wrap; }

/* Progress */
#progressBar{ display:none;margin-bottom:12px; }
.bar-wrap{ height:6px;background:#e5e7eb;border-radius:99px;overflow:hidden; }
.bar-fill{ height:100%;background:#2563eb;border-radius:99px;transition:width .3s; }
.bar-lbl{ font-size:11px;color:#6b7280;margin-top:4px; }

/* Alerts */
.alert{ padding:10px 14px;border-radius:6px;margin-bottom:14px;display:flex;align-items:center;gap:8px;font-size:12px;font-weight:500; }
.alert-error{ background:#fef2f2;color:#991b1b;border:1px solid #fecaca; }
.alert-success{ background:#f0fdf4;color:#166534;border:1px solid #bbf7d0; }
.alert-info{ background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe; }

/* Card */
.content-card{ background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;margin-bottom:16px; }
.card-title{ font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:7px;margin-bottom:14px; }

/* Unmatched */
.unmatched-title{ color:var(--red); }

@media(max-width:768px){ .stats-strip{ gap:6px; } .stat-box{ min-width:100px; } }
</style>

<!-- Page Header -->
<div class="page-header" style="margin-bottom:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <div>
            <h2 class="page-title"><i class="fa-solid fa-file-import"></i> Credit Policy Import</h2>
            <p class="page-sub">Upload HUL Excel → Match by T-Code → Preview &amp; Update payment mode, credit days &amp; cheques-will-delay</p>
        </div>
        <a href="customers.php" class="btn-sec"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>
</div>

<!-- Tabs -->
<div class="tab-bar">
    <button class="tab-btn active" onclick="showTab('tab-import',this)">
        <i class="fa-solid fa-upload"></i> Import &amp; Preview
    </button>
    <button class="tab-btn" onclick="showTab('tab-history',this)">
        <i class="fa-solid fa-clock-rotate-left"></i> Update History
        <?php if(count($history_rows)>0): ?>
        <span style="background:#e5e7eb;border-radius:20px;padding:1px 7px;font-size:10px;margin-left:4px;"><?php echo count($history_rows); ?></span>
        <?php endif; ?>
    </button>
</div>

<!-- ═══ TAB 1: IMPORT ════════════════════════════════════════════════════════ -->
<div id="tab-import" class="tab-pane active">

    <!-- Upload card -->
    <div class="content-card">
        <div class="card-title"><i class="fa-solid fa-cloud-arrow-up"></i> Upload Credit Policy Excel</div>
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="upload-zone" id="dropZone" onclick="document.getElementById('excel_file').click()">
                <i class="fa-solid fa-file-excel"></i>
                <h3 style="font-size:15px;font-weight:700;color:#111;margin:0 0 4px;">Drop your Excel file here or click to browse</h3>
                <p style="font-size:12px;color:#6b7280;margin:0;">Accepts .xlsx — Columns: <strong>Outlet HUL Code, Party Name, PAYEMNT MODE, POLICY DAYS, CHEQUES DELAY</strong></p>
                <div id="chosenFile" style="margin-top:10px;font-size:12px;font-weight:600;color:#2563eb;"></div>
            </div>
            <input type="file" name="excel_file" id="excel_file" accept=".xlsx,.xls" onchange="showFileName(this)">
            <div style="text-align:center;">
                <button type="submit" class="btn-parse"><i class="fa-solid fa-magnifying-glass"></i> Parse &amp; Preview</button>
            </div>
        </form>
    </div>

    <?php if ($upload_error): ?>
    <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($upload_error); ?></div>
    <?php endif; ?>

    <?php if (!empty($preview_rows) || !empty($unmatched_rows)):
        $total   = count($preview_rows) + count($unmatched_rows);
        $matched = count($preview_rows);
        $changed = count(array_filter($preview_rows, fn($r) => $r['changed']));
        $unmatched = count($unmatched_rows);
    ?>

    <!-- Stats -->
    <div class="stats-strip">
        <div class="stat-box s-total"><div class="num"><?php echo $total; ?></div><div class="lbl">Total Rows</div></div>
        <div class="stat-box s-matched"><div class="num"><?php echo $matched; ?></div><div class="lbl">Matched</div></div>
        <div class="stat-box s-changed"><div class="num"><?php echo $changed; ?></div><div class="lbl">Policy Changed</div></div>
        <div class="stat-box s-unmatch"><div class="num"><?php echo $unmatched; ?></div><div class="lbl">Unmatched</div></div>
    </div>

    <div id="bulkResult"></div>

    <!-- Progress bar -->
    <div id="progressBar">
        <div class="bar-wrap"><div class="bar-fill" id="barFill" style="width:0%"></div></div>
        <div class="bar-lbl" id="barLbl">Updating...</div>
    </div>

    <!-- Sticky action bar -->
    <div class="sticky-bar">
        <span style="font-size:12px;color:#6b7280;"><span id="selCount">0</span> selected</span>
        <button class="btn-bulk" onclick="updateSelected()" id="btnSel">
            <i class="fa-solid fa-rotate"></i> Update Selected
        </button>
        <button class="btn-bulk" style="background:var(--green);" onclick="updateAllChanged()" id="btnChg">
            <i class="fa-solid fa-circle-check"></i> Update All Changed (<?php echo $changed; ?>)
        </button>
        <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <label style="font-size:12px;display:flex;align-items:center;gap:5px;cursor:pointer;">
                <input type="checkbox" id="filterChanged" onchange="filterTable()"> Changed only
            </label>
            <input type="text" id="searchBox" placeholder="🔍 Search name / code…" oninput="filterTable()"
                style="padding:6px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:12px;width:190px;">
        </div>
    </div>

    <?php if (!empty($preview_rows)): ?>
    <!-- Matched table -->
    <div class="content-card" style="padding:12px;">
        <div class="card-title" style="margin-bottom:10px;">
            <i class="fa-solid fa-table-list"></i> Matched Customers
            <span style="font-size:11px;font-weight:400;color:#64748b;margin-left:4px;">(<?php echo $matched; ?> found in DB)</span>
        </div>
        <div class="dt-wrap">
        <table class="dt" id="previewTable">
            <thead>
            <tr>
                <th><input type="checkbox" id="cbAll" onchange="toggleAll(this)" style="cursor:pointer;"></th>
                <th>#</th>
                <th>HUL / T-Code</th>
                <th>Shop Name</th>
                <th>DB Pay Mode</th>
                <th>XL Pay Mode</th>
                <th>DB Days</th>
                <th>XL Days</th>
                <th>DB Cheques Delay</th>
                <th>XL Cheques Delay</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php
            $badge_cls = fn($m) => match(strtolower($m??'')) {
                'cash'  =>'badge-cash','credit'=>'badge-credit','cheque'=>'badge-cheque',default=>'badge-unknown'
            };
            $delay_badge = fn($v) => $v ? '<span class="badge badge-delay-yes"><i class="fa-solid fa-clock"></i> Yes</span>' : '<span class="badge badge-delay-no">No</span>';
            foreach ($preview_rows as $i => $r):
                $cls = $r['changed'] ? 'changed' : '';
            ?>
            <tr class="<?php echo $cls; ?>"
                data-cid="<?php echo $r['customer_id']; ?>"
                data-hul="<?php echo htmlspecialchars($r['hul_code'],ENT_QUOTES); ?>"
                data-pm="<?php echo htmlspecialchars($r['xl_pay_mode'],ENT_QUOTES); ?>"
                data-pd="<?php echo intval($r['xl_policy_days']); ?>"
                data-cd="<?php echo intval($r['xl_cheques_delay']); ?>"
                data-name="<?php echo strtolower(htmlspecialchars($r['shop_name'],ENT_QUOTES)); ?>"
                data-tcode="<?php echo strtolower(htmlspecialchars($r['db_t_code'],ENT_QUOTES)); ?>"
                data-changed="<?php echo $r['changed']?'1':'0'; ?>">
                <td><input type="checkbox" class="row-cb" onchange="updateSelCount()"></td>
                <td style="color:#9ca3af;font-size:11px;"><?php echo $i+1; ?></td>
                <td>
                    <div style="font-weight:700;font-size:12px;"><?php echo htmlspecialchars($r['hul_code']); ?></div>
                    <?php if($r['hul_code']!==$r['db_t_code']): ?>
                    <div style="font-size:10px;color:#9ca3af;">DB: <?php echo htmlspecialchars($r['db_t_code']); ?></div>
                    <?php endif; ?>
                </td>
                <td style="font-weight:600;"><?php echo htmlspecialchars($r['shop_name']); ?></td>
                <td><span class="badge <?php echo $badge_cls($r['db_pay_mode']); ?>"><?php echo htmlspecialchars($r['db_pay_mode']?:'—'); ?></span></td>
                <td><span class="badge <?php echo $badge_cls($r['xl_pay_mode']); ?>"><?php echo htmlspecialchars($r['xl_pay_mode']?:'—'); ?></span></td>
                <td style="text-align:center;font-weight:600;"><?php echo htmlspecialchars($r['db_policy_days']??'—'); ?></td>
                <td style="text-align:center;font-weight:600;color:#2563eb;"><?php echo $r['xl_policy_days']!==null?$r['xl_policy_days']:'—'; ?></td>
                <td style="text-align:center;"><?php echo $delay_badge($r['db_cheques_delay']); ?></td>
                <td style="text-align:center;"><?php echo $delay_badge($r['xl_cheques_delay']); ?></td>
                <td>
                    <?php if($r['changed']): ?>
                    <span class="badge badge-changed"><i class="fa-solid fa-arrow-right-arrow-left"></i> Changed</span>
                    <?php else: ?>
                    <span class="badge badge-same"><i class="fa-solid fa-check"></i> Same</span>
                    <?php endif; ?>
                </td>
                <td>
                    <button class="btn-upd" id="upd-<?php echo $r['customer_id']; ?>"
                        onclick="updateOne(this,<?php echo $r['customer_id']; ?>,'<?php echo htmlspecialchars($r['hul_code'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($r['xl_pay_mode'],ENT_QUOTES); ?>',<?php echo intval($r['xl_policy_days']); ?>,<?php echo intval($r['xl_cheques_delay']); ?>)">
                        <i class="fa-solid fa-rotate"></i> Update
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($unmatched_rows)): ?>
    <!-- Unmatched -->
    <div class="content-card">
        <div class="card-title unmatched-title">
            <i class="fa-solid fa-circle-xmark"></i> Unmatched HUL Codes
            <span style="font-size:11px;font-weight:400;color:#6b7280;margin-left:4px;">(<?php echo $unmatched; ?> not found in DB)</span>
        </div>
        <div class="dt-wrap">
        <table class="dt">
            <thead><tr><th>#</th><th>HUL Code</th><th>Party Name (Excel)</th><th>XL Pay Mode</th><th>XL Days</th><th>XL Cheques Delay</th></tr></thead>
            <tbody>
            <?php foreach($unmatched_rows as $i=>$r): ?>
            <tr>
                <td style="color:#9ca3af;"><?php echo $i+1; ?></td>
                <td style="font-weight:700;color:var(--red);"><?php echo htmlspecialchars($r['hul_code']); ?></td>
                <td><?php echo htmlspecialchars($r['party_name']); ?></td>
                <td>
                    <?php $c=match(strtolower($r['xl_pay_mode']??'')){'cash'=>'badge-cash','credit'=>'badge-credit','cheque'=>'badge-cheque',default=>'badge-unknown'}; ?>
                    <span class="badge <?php echo $c; ?>"><?php echo htmlspecialchars($r['xl_pay_mode']??'—'); ?></span>
                </td>
                <td style="text-align:center;"><?php echo $r['xl_pd']!==null?$r['xl_pd']:'—'; ?></td>
                <td style="text-align:center;"><?php echo $r['xl_cd'] ? '<span class="badge badge-delay-yes">Yes</span>' : '<span class="badge badge-delay-no">No</span>'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // end if preview/unmatched ?>
</div>

<!-- ═══ TAB 2: HISTORY ═══════════════════════════════════════════════════════ -->
<div id="tab-history" class="tab-pane">
    <div class="content-card">
        <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Import Update History <span style="font-size:11px;font-weight:400;color:#64748b;">(last 100)</span></div>
        <?php if(empty($history_rows)): ?>
        <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> No updates yet. Upload an Excel file and apply updates to see records here.</div>
        <?php else: ?>
        <div class="dt-wrap">
        <table class="dt">
            <thead><tr><th>#</th><th>Updated At</th><th>HUL Code</th><th>Customer</th><th>Pay Mode Set</th><th>Days Set</th><th>Cheques Delay Set</th><th>Source</th></tr></thead>
            <tbody>
            <?php foreach($history_rows as $i=>$h):
                $c=match(strtolower($h['payment_mode']??'')){'cash'=>'badge-cash','credit'=>'badge-credit','cheque'=>'badge-cheque',default=>'badge-unknown'};
            ?>
            <tr>
                <td style="color:#9ca3af;"><?php echo $i+1; ?></td>
                <td style="white-space:nowrap;color:#64748b;font-size:11px;"><?php echo htmlspecialchars($h['updated_at']); ?></td>
                <td style="font-weight:600;font-size:11px;"><?php echo htmlspecialchars($h['hul_code']); ?></td>
                <td>
                    <div style="font-weight:600;"><?php echo htmlspecialchars($h['shop_name']??'—'); ?></div>
                    <div style="font-size:10px;color:#9ca3af;"><?php echo htmlspecialchars($h['t_code']??''); ?></div>
                </td>
                <td><span class="badge <?php echo $c; ?>"><?php echo htmlspecialchars($h['payment_mode']); ?></span></td>
                <td style="text-align:center;font-weight:600;"><?php echo intval($h['credit_days']); ?></td>
                <td style="text-align:center;"><?php echo intval($h['cheques_delay']??0) ? '<span class="badge badge-delay-yes">Yes</span>' : '<span class="badge badge-delay-no">No</span>'; ?></td>
                <td><span class="badge badge-unknown"><?php echo htmlspecialchars($h['source']); ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
function showTab(id,btn){
    document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.getElementById(id).classList.add('active');
    btn.classList.add('active');
}
function showFileName(inp){
    const n=inp.files[0]?.name||'';
    document.getElementById('chosenFile').textContent=n?'📎 '+n:'';
}
// Drag & drop
const dz=document.getElementById('dropZone');
if(dz){
    dz.addEventListener('dragover',e=>{e.preventDefault();dz.classList.add('drag-over');});
    dz.addEventListener('dragleave',()=>dz.classList.remove('drag-over'));
    dz.addEventListener('drop',e=>{
        e.preventDefault();dz.classList.remove('drag-over');
        const f=e.dataTransfer.files[0];
        if(f){const dt=new DataTransfer();dt.items.add(f);
        document.getElementById('excel_file').files=dt.files;showFileName({files:[f]});}
    });
}
function toggleAll(cb){
    document.querySelectorAll('#previewTable tbody tr:not([style*="none"]) .row-cb').forEach(c=>c.checked=cb.checked);
    updateSelCount();
}
function updateSelCount(){
    document.getElementById('selCount').textContent=document.querySelectorAll('#previewTable .row-cb:checked').length;
}
function filterTable(){
    const q=(document.getElementById('searchBox')?.value||'').toLowerCase();
    const onlyChg=document.getElementById('filterChanged')?.checked;
    document.querySelectorAll('#previewTable tbody tr').forEach(tr=>{
        const matchQ=!q||tr.dataset.name.includes(q)||tr.dataset.tcode.includes(q);
        const matchC=!onlyChg||tr.dataset.changed==='1';
        tr.style.display=(matchQ&&matchC)?'':'none';
    });
    updateSelCount();
}
function updateOne(btn,cid,hul,pm,pd,cd){
    btn.disabled=true;
    btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Updating…';
    const fd=new FormData();
    fd.append('customer_id',cid);fd.append('hul_code',hul);
    fd.append('payment_mode',pm);fd.append('policy_days',pd);fd.append('cheques_delay',cd);
    fetch('?action=update_one',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{
            if(d.ok){
                btn.innerHTML='<i class="fa-solid fa-check"></i> Done';
                btn.classList.add('done');
                btn.closest('tr').classList.remove('changed');
                btn.closest('tr').classList.add('updated-ok');
            } else {
                btn.innerHTML='<i class="fa-solid fa-xmark"></i> Failed';
                btn.classList.add('fail');btn.disabled=false;
            }
        }).catch(()=>{btn.innerHTML='<i class="fa-solid fa-xmark"></i> Error';btn.classList.add('fail');btn.disabled=false;});
}
function collectRows(onlyChg){
    const rows=[];
    const sel=onlyChg?'#previewTable tbody tr[data-changed="1"]':'#previewTable tbody tr';
    document.querySelectorAll(sel).forEach(tr=>{
        if(onlyChg||tr.querySelector('.row-cb')?.checked){
            const btn=document.getElementById('upd-'+tr.dataset.cid);
            if(btn&&!btn.classList.contains('done')){
                rows.push({customer_id:tr.dataset.cid,hul_code:tr.dataset.hul,
                            payment_mode:tr.dataset.pm,policy_days:tr.dataset.pd,
                            cheques_delay:tr.dataset.cd});
            }
        }
    });
    return rows;
}
async function bulkUpdate(rows){
    if(!rows.length){alert('No eligible rows to update.');return;}
    if(!confirm(`Update ${rows.length} customer(s)?`))return;
    document.getElementById('progressBar').style.display='block';
    document.getElementById('btnSel').disabled=true;
    document.getElementById('btnChg').disabled=true;
    const chunk=50;let done=0;
    for(let i=0;i<rows.length;i+=chunk){
        const slice=rows.slice(i,i+chunk);
        const pct=Math.round(((i+slice.length)/rows.length)*100);
        document.getElementById('barFill').style.width=pct+'%';
        document.getElementById('barLbl').textContent=`Updating… ${i+slice.length} / ${rows.length}`;
        await fetch('?action=update_all',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(slice)})
            .then(r=>r.json()).then(d=>{done+=d.updated;});
        slice.forEach(r=>{
            const tr=document.querySelector(`#previewTable tr[data-cid="${r.customer_id}"]`);
            const btn=document.getElementById('upd-'+r.customer_id);
            if(tr){tr.classList.remove('changed');tr.classList.add('updated-ok');}
            if(btn){btn.innerHTML='<i class="fa-solid fa-check"></i> Done';btn.classList.add('done');btn.disabled=true;}
        });
    }
    document.getElementById('barFill').style.width='100%';
    document.getElementById('barLbl').textContent=`Complete! ${done} updated.`;
    document.getElementById('bulkResult').innerHTML=
        `<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i>
         Updated <strong>${done}</strong> customer(s) successfully.</div>`;
    document.getElementById('btnSel').disabled=false;
    document.getElementById('btnChg').disabled=false;
}
function updateSelected(){bulkUpdate(collectRows(false));}
function updateAllChanged(){bulkUpdate(collectRows(true));}
</script>

<?php include 'footer.php'; ?>