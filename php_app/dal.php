
<?php
include 'config.php';

// ══════════════════════════════════════════════════════════════════
//  TABLE SETUP
// ══════════════════════════════════════════════════════════════════
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sscl_vat_email_entries (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    email_date            DATE NULL,
    description           TEXT NULL,
    attachment_evaluation TEXT NULL,
    total_net             DECIMAL(14,4) DEFAULT 0,
    total_vat             DECIMAL(14,4) DEFAULT 0,
    total_amount          DECIMAL(14,4) DEFAULT 0,
    created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP
)");

// ── Email attachments (the email files themselves) ────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sscl_vat_email_attachments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    entry_id    INT NOT NULL,
    file_path   VARCHAR(500) NULL,
    file_name   VARCHAR(255) NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entry (entry_id)
)");

// ── Evaluation files (pdf/doc/img per evaluation section) ─────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sscl_vat_eval_files (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    entry_id    INT NOT NULL,
    file_path   VARCHAR(500) NULL,
    file_name   VARCHAR(255) NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entry (entry_id)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sscl_vat_email_lines (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    entry_id        INT NOT NULL,
    line_type       ENUM('customer','employee') DEFAULT 'customer',
    ref_id          INT NULL,
    ref_code        VARCHAR(100) NULL,
    ref_name        VARCHAR(255) NULL,
    net_amount      DECIMAL(14,4) DEFAULT 0,
    vat_amount      DECIMAL(14,4) DEFAULT 0,
    total_amount    DECIMAL(14,4) DEFAULT 0,
    sort_order      INT DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entry (entry_id)
)");

// ── Evaluation notes (multiple per entry) ─────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sscl_vat_email_evaluations (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    entry_id        INT NOT NULL,
    evaluation_text TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entry (entry_id)
)");

// ── Settlement link table ─────────────────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sscl_vat_settlements (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    entry_id        INT NOT NULL,
    source_type     ENUM('claim_item','cheque_ack') NOT NULL,
    source_id       INT NOT NULL,
    reference_no    VARCHAR(150) NULL,
    entity          VARCHAR(150) NULL,
    description     VARCHAR(500) NULL,
    claim_type      VARCHAR(100) NULL,
    amount          DECIMAL(14,4) DEFAULT 0,
    settled_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    settled_by      VARCHAR(100) DEFAULT 'system',
    INDEX idx_entry (entry_id),
    INDEX idx_source (source_type, source_id)
)");

// ── Auto-migrate: add missing columns ────────────────────────────
$_migrate_entries = [
    'description'           => "TEXT NULL AFTER email_date",
    'attachment_evaluation' => "TEXT NULL AFTER description",
];
foreach ($_migrate_entries as $_col => $_def) {
    $_chk = mysqli_query($conn, "SHOW COLUMNS FROM sscl_vat_email_entries LIKE '$_col'");
    if ($_chk && mysqli_num_rows($_chk) === 0)
        @mysqli_query($conn, "ALTER TABLE sscl_vat_email_entries ADD COLUMN $_col $_def");
}
$sve_settle_cols = [
    'entry_id'     => "INT NOT NULL DEFAULT 0",
    'source_type'  => "VARCHAR(20) NOT NULL DEFAULT 'claim_item'",
    'source_id'    => "INT NOT NULL DEFAULT 0",
    'reference_no' => "VARCHAR(150) NULL",
    'entity'       => "VARCHAR(150) NULL",
    'description'  => "VARCHAR(500) NULL",
    'claim_type'   => "VARCHAR(100) NULL",
    'amount'       => "DECIMAL(14,4) DEFAULT 0",
    'settled_at'   => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    'settled_by'   => "VARCHAR(100) DEFAULT 'system'",
];
foreach ($sve_settle_cols as $sve_col => $sve_def) {
    $sve_chk = mysqli_query($conn, "SHOW COLUMNS FROM sscl_vat_settlements LIKE '$sve_col'");
    if ($sve_chk && mysqli_num_rows($sve_chk) === 0)
        @mysqli_query($conn, "ALTER TABLE sscl_vat_settlements ADD COLUMN $sve_col $sve_def");
}

// ── Defensive creation of claim tables ───────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS dl_confirmed_payments (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    claim_item_id       INT NOT NULL,
    upload_id           INT NOT NULL,
    customer_code       VARCHAR(50) NULL,
    customer_name       VARCHAR(255) NULL,
    ledger_type         VARCHAR(100) NULL,
    status              VARCHAR(100) NULL,
    claim_type          VARCHAR(100) NULL,
    tax_invoice_no      VARCHAR(100) NULL,
    invoice_date        DATE NULL,
    banking_date        DATE NULL,
    entity              VARCHAR(50) NULL,
    claim_description   TEXT NULL,
    actual_amount       DECIMAL(18,4) DEFAULT 0,
    vat_amount          DECIMAL(18,4) DEFAULT 0,
    total_amount        DECIMAL(18,4) DEFAULT 0,
    dl_record_id        INT NULL,
    confirmed_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    confirmed_by        VARCHAR(100) DEFAULT 'system',
    cheque_ack_status   ENUM('pending','sent') DEFAULT 'pending',
    INDEX idx_claim_item (claim_item_id),
    INDEX idx_dl_record  (dl_record_id)
)");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS dl_cheque_acknowledgments (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    confirmed_payment_id INT NULL,
    claim_item_id        INT NOT NULL,
    tax_invoice_no       VARCHAR(100) NULL,
    invoice_date         DATE NULL,
    banking_date         DATE NULL,
    entity               VARCHAR(50) NULL,
    claim_description    TEXT NULL,
    actual_amount        DECIMAL(18,4) DEFAULT 0,
    vat_amount           DECIMAL(18,4) DEFAULT 0,
    total_amount         DECIMAL(18,4) DEFAULT 0,
    ledger_type          VARCHAR(100) NULL,
    status               VARCHAR(100) NULL,
    claim_type           VARCHAR(100) NULL,
    customer_code        VARCHAR(50) NULL,
    customer_name        VARCHAR(255) NULL,
    ack_sent_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    ack_sent_by          VARCHAR(100) DEFAULT 'system',
    INDEX idx_cp (confirmed_payment_id),
    INDEX idx_ci (claim_item_id)
)");

// ══════════════════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════════════════
function recalc_entry_totals($conn, $entry_id) {
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(net_amount),0) as tn,
                COALESCE(SUM(vat_amount),0) as tv,
                COALESCE(SUM(total_amount),0) as tt
         FROM sscl_vat_email_lines WHERE entry_id=" . intval($entry_id)
    ));
    mysqli_query($conn, "UPDATE sscl_vat_email_entries SET
        total_net="    . floatval($row['tn']) . ",
        total_vat="    . floatval($row['tv']) . ",
        total_amount=" . floatval($row['tt']) . "
        WHERE id="     . intval($entry_id));
    return $row;
}

function do_upload_files($conn, $files_key, $entry_id, $dir, $table) {
    $uploaded = [];
    if (empty($_FILES[$files_key]['name'][0])) return $uploaded;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','txt'];
    $names = $_FILES[$files_key]['name'];
    $tmps  = $_FILES[$files_key]['tmp_name'];
    $errs  = $_FILES[$files_key]['error'];
    for ($i = 0; $i < count($names); $i++) {
        if ($errs[$i] !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) continue;
        $safe = uniqid('sve_', true) . '.' . $ext;
        if (move_uploaded_file($tmps[$i], $dir . $safe)) {
            $fp = mysqli_real_escape_string($conn, $dir . $safe);
            $fn = mysqli_real_escape_string($conn, $names[$i]);
            mysqli_query($conn,
                "INSERT INTO $table (entry_id, file_path, file_name) VALUES ($entry_id, '$fp', '$fn')");
            $uploaded[] = ['id' => mysqli_insert_id($conn), 'path' => $dir . $safe, 'name' => $names[$i]];
        }
    }
    return $uploaded;
}

function settled_badge_html($settled, $target) {
    $settled = floatval($settled);
    $target  = floatval($target);
    if ($settled <= 0) return '<span class="settled-badge none">Not Settled</span>';
    if ($target > 0 && abs($settled - $target) < 0.01)
        return '<span class="settled-badge full">Fully Settled<br>' . number_format($settled, 2) . '</span>';
    return '<span class="settled-badge partial">Partial<br>' . number_format($settled, 2) . ' / ' . number_format($target, 2) . '</span>';
}

function entry_type_badge_html($type, $distinctCount) {
    $distinctCount = intval($distinctCount);
    if ($distinctCount === 0) return '<span style="color:#d1d5db;font-size:11px">—</span>';
    if ($distinctCount > 1)   return '<span class="tag" style="background:#fef3c7;color:#92400e"><i class="fa-solid fa-shuffle"></i> Mixed</span>';
    if ($type === 'employee') return '<span class="type-pill-emp"><i class="fa-solid fa-id-badge"></i> Employee</span>';
    return '<span class="type-pill-cust"><i class="fa-solid fa-user"></i> Customer</span>';
}

// ══════════════════════════════════════════════════════════════════
//  AJAX HANDLERS
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // ── Search customers ──────────────────────────────────────────
    if ($action === 'search_customers') {
        $q   = mysqli_real_escape_string($conn, trim($_GET['q'] ?? ''));
        $sql = "SELECT id, t_code, shop_name FROM customers WHERE active=1";
        if ($q) $sql .= " AND (t_code LIKE '%$q%' OR shop_name LIKE '%$q%')";
        $sql .= " ORDER BY shop_name ASC LIMIT 60";
        $res  = mysqli_query($conn, $sql);
        $rows = [];
        if ($res) while ($r = mysqli_fetch_assoc($res))
            $rows[] = ['id'=>$r['id'],'text'=>'['.$r['t_code'].'] '.$r['shop_name'],'code'=>$r['t_code'],'name'=>$r['shop_name']];
        echo json_encode(['results' => $rows]);
        exit;
    }

    // ── Search employees ──────────────────────────────────────────
    if ($action === 'search_employees') {
        $q   = mysqli_real_escape_string($conn, trim($_GET['q'] ?? ''));
        $sql = "SELECT id, employee_id, employee_full_name FROM employees WHERE active=1";
        if ($q) $sql .= " AND (employee_id LIKE '%$q%' OR employee_full_name LIKE '%$q%')";
        $sql .= " ORDER BY employee_full_name ASC LIMIT 60";
        $res  = mysqli_query($conn, $sql);
        $rows = [];
        if ($res) while ($r = mysqli_fetch_assoc($res))
            $rows[] = ['id'=>$r['id'],'text'=>'['.$r['employee_id'].'] '.$r['employee_full_name'],'code'=>$r['employee_id'],'name'=>$r['employee_full_name']];
        echo json_encode(['results' => $rows]);
        exit;
    }

    // ── Save entry (create / update) ──────────────────────────────
    if ($action === 'save_entry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id          = intval($_POST['id'] ?? 0);
        $email_date  = mysqli_real_escape_string($conn, trim($_POST['email_date']  ?? ''));
        $description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
        $ed_sql      = $email_date ? "'$email_date'" : 'NULL';

        if ($id > 0) {
            mysqli_query($conn,
                "UPDATE sscl_vat_email_entries SET email_date='$email_date', description='$description' WHERE id=$id");
        } else {
            mysqli_query($conn,
                "INSERT INTO sscl_vat_email_entries (email_date, description, total_net, total_vat, total_amount)
                 VALUES ($ed_sql, '$description', 0, 0, 0)");
            $id = mysqli_insert_id($conn);
        }

        // ── Upload email files ─────────────────────────────────────
        $uploaded_email = do_upload_files($conn, 'email_files', $id,
            'uploads/sscl_vat_emails/', 'sscl_vat_email_attachments');

        // ── Upload evaluation files ────────────────────────────────
        $uploaded_eval = do_upload_files($conn, 'eval_files', $id,
            'uploads/sscl_vat_eval_files/', 'sscl_vat_eval_files');

        // ── Save evaluation notes (append-only) ───────────────────
        $new_evals   = json_decode($_POST['new_evaluations'] ?? '[]', true);
        $added_evals = [];
        if (is_array($new_evals)) {
            foreach ($new_evals as $ev_text) {
                $ev_text = trim((string)$ev_text);
                if ($ev_text === '') continue;
                $ev_esc = mysqli_real_escape_string($conn, $ev_text);
                mysqli_query($conn,
                    "INSERT INTO sscl_vat_email_evaluations (entry_id, evaluation_text) VALUES ($id, '$ev_esc')");
                $added_evals[] = ['id' => mysqli_insert_id($conn), 'evaluation_text' => $ev_text];
            }
        }

        // ── Save lines (replace all) ───────────────────────────────
        $lines = json_decode($_POST['lines'] ?? '[]', true);
        if (is_array($lines)) {
            mysqli_query($conn, "DELETE FROM sscl_vat_email_lines WHERE entry_id=$id");
            $sort = 0;
            foreach ($lines as $line) {
                $ltype  = in_array($line['type'] ?? '', ['customer','employee']) ? $line['type'] : 'customer';
                $ref_id = intval($line['ref_id'] ?? 0);
                $code   = mysqli_real_escape_string($conn, trim($line['code'] ?? ''));
                $name   = mysqli_real_escape_string($conn, trim($line['name'] ?? ''));
                $net    = is_numeric($line['net'] ?? '') ? floatval($line['net']) : 0;
                $vat    = round($net * 0.18, 4);
                $tot    = $net + $vat;
                $sort++;
                mysqli_query($conn,
                    "INSERT INTO sscl_vat_email_lines
                        (entry_id,line_type,ref_id,ref_code,ref_name,net_amount,vat_amount,total_amount,sort_order)
                     VALUES ($id,'$ltype',$ref_id,'$code','$name',$net,$vat,$tot,$sort)");
            }
        }

        $totals = recalc_entry_totals($conn, $id);
        echo json_encode([
            'success'            => true,
            'id'                 => $id,
            'totals'             => $totals,
            'uploaded_email'     => $uploaded_email,
            'uploaded_eval'      => $uploaded_eval,
            'evaluations_added'  => $added_evals,
        ]);
        exit;
    }

    // ── Get entry details ─────────────────────────────────────────
    if ($action === 'get_entry') {
        $id  = intval($_GET['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sscl_vat_email_entries WHERE id=$id"));

        $email_attachments = [];
        $ar = mysqli_query($conn, "SELECT * FROM sscl_vat_email_attachments WHERE entry_id=$id ORDER BY id");
        while ($a = mysqli_fetch_assoc($ar)) $email_attachments[] = $a;

        $eval_files = [];
        $efr = mysqli_query($conn, "SELECT * FROM sscl_vat_eval_files WHERE entry_id=$id ORDER BY id");
        while ($ef = mysqli_fetch_assoc($efr)) $eval_files[] = $ef;

        $evaluations = [];
        $evr = mysqli_query($conn, "SELECT * FROM sscl_vat_email_evaluations WHERE entry_id=$id ORDER BY id");
        while ($ev = mysqli_fetch_assoc($evr)) $evaluations[] = $ev;

        $lines = [];
        $lr = mysqli_query($conn, "SELECT * FROM sscl_vat_email_lines WHERE entry_id=$id ORDER BY sort_order, id");
        while ($l = mysqli_fetch_assoc($lr)) $lines[] = $l;

        echo json_encode([
            'success'           => (bool)$row,
            'data'              => $row,
            'email_attachments' => $email_attachments,
            'eval_files'        => $eval_files,
            'evaluations'       => $evaluations,
            'lines'             => $lines,
        ]);
        exit;
    }

    // ── Delete email attachment ───────────────────────────────────
    if ($action === 'delete_email_attachment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $aid = intval($_POST['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sscl_vat_email_attachments WHERE id=$aid"));
        if ($row && !empty($row['file_path']) && file_exists($row['file_path'])) @unlink($row['file_path']);
        $ok = mysqli_query($conn, "DELETE FROM sscl_vat_email_attachments WHERE id=$aid");
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    // ── Delete evaluation file ────────────────────────────────────
    if ($action === 'delete_eval_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $fid = intval($_POST['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sscl_vat_eval_files WHERE id=$fid"));
        if ($row && !empty($row['file_path']) && file_exists($row['file_path'])) @unlink($row['file_path']);
        $ok = mysqli_query($conn, "DELETE FROM sscl_vat_eval_files WHERE id=$fid");
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    // ── Delete evaluation note ────────────────────────────────────
    if ($action === 'delete_evaluation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $eid = intval($_POST['id'] ?? 0);
        $ok  = mysqli_query($conn, "DELETE FROM sscl_vat_email_evaluations WHERE id=$eid");
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    // ── Delete entry ──────────────────────────────────────────────
    if ($action === 'delete_entry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        // remove email attachment files
        $ar = mysqli_query($conn, "SELECT file_path FROM sscl_vat_email_attachments WHERE entry_id=$id");
        while ($a = mysqli_fetch_assoc($ar))
            if (!empty($a['file_path']) && file_exists($a['file_path'])) @unlink($a['file_path']);
        // remove eval files
        $efr = mysqli_query($conn, "SELECT file_path FROM sscl_vat_eval_files WHERE entry_id=$id");
        while ($ef = mysqli_fetch_assoc($efr))
            if (!empty($ef['file_path']) && file_exists($ef['file_path'])) @unlink($ef['file_path']);

        mysqli_query($conn, "DELETE FROM sscl_vat_email_attachments WHERE entry_id=$id");
        mysqli_query($conn, "DELETE FROM sscl_vat_eval_files         WHERE entry_id=$id");
        mysqli_query($conn, "DELETE FROM sscl_vat_email_evaluations  WHERE entry_id=$id");
        mysqli_query($conn, "DELETE FROM sscl_vat_email_lines        WHERE entry_id=$id");
        $ok = mysqli_query($conn, "DELETE FROM sscl_vat_email_entries WHERE id=$id");
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    // ── Get settlement data ───────────────────────────────────────
    if ($action === 'get_settle_data') {
        $entry_id = intval($_GET['entry_id'] ?? 0);
        $search   = mysqli_real_escape_string($conn, trim($_GET['search'] ?? ''));
        if (!$entry_id) { echo json_encode(['success'=>false,'message'=>'Invalid entry.']); exit; }

        $entry = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sscl_vat_email_entries WHERE id=$entry_id"));
        if (!$entry) { echo json_encode(['success'=>false,'message'=>'Entry not found.']); exit; }
        $target = floatval($entry['total_amount']);

        $pc_where = [
            "ci.id NOT IN (SELECT claim_item_id FROM dl_confirmed_payments WHERE claim_item_id IS NOT NULL)",
            "ci.id NOT IN (SELECT claim_item_id FROM dl_cheque_acknowledgments WHERE claim_item_id IS NOT NULL)",
            "ci.id NOT IN (SELECT source_id FROM sscl_vat_settlements WHERE source_type='claim_item')",
        ];
        if ($search) $pc_where[] = "(ci.tax_invoice_no LIKE '%$search%' OR ci.claim_description LIKE '%$search%' OR ci.entity LIKE '%$search%')";
        $pc_sql = "SELECT ci.* FROM claim_cert_items ci WHERE " . implode(' AND ', $pc_where) .
                  " ORDER BY (ABS(ci.total_amount-$target)<0.01) DESC, ci.invoice_date DESC, ci.id DESC LIMIT 300";
        $pending = [];
        $pr = mysqli_query($conn, $pc_sql);
        if ($pr) while ($r = mysqli_fetch_assoc($pr)) $pending[] = $r;

        $ca_where = ["ca.id NOT IN (SELECT source_id FROM sscl_vat_settlements WHERE source_type='cheque_ack')"];
        if ($search) $ca_where[] = "(ca.tax_invoice_no LIKE '%$search%' OR ca.claim_description LIKE '%$search%' OR ca.entity LIKE '%$search%')";
        $ca_sql = "SELECT ca.* FROM dl_cheque_acknowledgments ca WHERE " . implode(' AND ', $ca_where) .
                  " ORDER BY (ABS(ca.total_amount-$target)<0.01) DESC, ca.invoice_date DESC, ca.id DESC LIMIT 300";
        $acks = [];
        $ar = mysqli_query($conn, $ca_sql);
        if ($ar) while ($r = mysqli_fetch_assoc($ar)) $acks[] = $r;

        $settled = [];
        $sr = mysqli_query($conn, "SELECT * FROM sscl_vat_settlements WHERE entry_id=$entry_id ORDER BY settled_at DESC");
        if ($sr) while ($r = mysqli_fetch_assoc($sr)) $settled[] = $r;
        $settled_total = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COALESCE(SUM(amount),0) as t FROM sscl_vat_settlements WHERE entry_id=$entry_id"));

        echo json_encode([
            'success'       => true,
            'entry'         => $entry,
            'pending'       => $pending,
            'cheque_acks'   => $acks,
            'settled'       => $settled,
            'settled_total' => floatval($settled_total['t']),
        ]);
        exit;
    }

    // ── Add settlements ───────────────────────────────────────────
    if ($action === 'add_settlement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $entry_id = intval($_POST['entry_id'] ?? 0);
        $items    = json_decode($_POST['items'] ?? '[]', true);
        if (!$entry_id || !is_array($items) || empty($items)) {
            echo json_encode(['success'=>false,'message'=>'No items selected.']); exit;
        }
        $settled_by = mysqli_real_escape_string($conn, $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'system');
        $inserted = 0; $skipped = 0;
        foreach ($items as $it) {
            $stype = (($it['source_type'] ?? '') === 'cheque_ack') ? 'cheque_ack' : 'claim_item';
            $sid   = intval($it['source_id'] ?? 0);
            if (!$sid) { $skipped++; continue; }
            $chk = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT id FROM sscl_vat_settlements WHERE source_type='$stype' AND source_id=$sid LIMIT 1"));
            if ($chk) { $skipped++; continue; }
            $table = $stype === 'cheque_ack' ? 'dl_cheque_acknowledgments' : 'claim_cert_items';
            $row   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM $table WHERE id=$sid LIMIT 1"));
            if (!$row) { $skipped++; continue; }
            $ref  = mysqli_real_escape_string($conn, $row['tax_invoice_no']    ?? '');
            $ent  = mysqli_real_escape_string($conn, $row['entity']            ?? '');
            $desc = mysqli_real_escape_string($conn, $row['claim_description'] ?? '');
            $ctyp = mysqli_real_escape_string($conn, $row['claim_type']        ?? '');
            $amt  = floatval($row['total_amount'] ?? 0);
            $ok   = mysqli_query($conn,
                "INSERT INTO sscl_vat_settlements
                    (entry_id,source_type,source_id,reference_no,entity,description,claim_type,amount,settled_by)
                 VALUES ($entry_id,'$stype',$sid,'$ref','$ent','$desc','$ctyp',$amt,'$settled_by')");
            if ($ok) $inserted++; else $skipped++;
        }
        $tot = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COALESCE(SUM(amount),0) as t, COUNT(*) as c FROM sscl_vat_settlements WHERE entry_id=$entry_id"));
        echo json_encode(['success'=>true,'inserted'=>$inserted,'skipped'=>$skipped,
            'settled_total'=>floatval($tot['t']),'settled_count'=>intval($tot['c'])]);
        exit;
    }

    // ── Remove settlement ─────────────────────────────────────────
    if ($action === 'delete_settlement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id  = intval($_POST['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT entry_id FROM sscl_vat_settlements WHERE id=$id"));
        if (!$row) { echo json_encode(['success'=>false,'message'=>'Settlement not found.']); exit; }
        $entry_id = intval($row['entry_id']);
        $ok  = mysqli_query($conn, "DELETE FROM sscl_vat_settlements WHERE id=$id");
        $tot = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COALESCE(SUM(amount),0) as t, COUNT(*) as c FROM sscl_vat_settlements WHERE entry_id=$entry_id"));
        echo json_encode(['success'=>(bool)$ok,'settled_total'=>floatval($tot['t']),'settled_count'=>intval($tot['c'])]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  PAGE LOAD
// ══════════════════════════════════════════════════════════════════
$filter_from = mysqli_real_escape_string($conn, $_GET['filter_from'] ?? '');
$filter_to   = mysqli_real_escape_string($conn, $_GET['filter_to']   ?? '');
$where       = [];
if ($filter_from) $where[] = "e.email_date >= '$filter_from'";
if ($filter_to)   $where[] = "e.email_date <= '$filter_to'";
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$entries = [];
$res = mysqli_query($conn,
    "SELECT e.*,
            (SELECT COUNT(*) FROM sscl_vat_email_attachments a  WHERE a.entry_id=e.id)  AS email_file_count,
            (SELECT COUNT(*) FROM sscl_vat_eval_files        ef WHERE ef.entry_id=e.id) AS eval_file_count,
            (SELECT COUNT(*) FROM sscl_vat_email_lines       l  WHERE l.entry_id=e.id)  AS line_count,
            (SELECT COUNT(*) FROM sscl_vat_email_evaluations ev WHERE ev.entry_id=e.id) AS eval_count,
            (SELECT GROUP_CONCAT(ev2.evaluation_text SEPARATOR ' ') FROM sscl_vat_email_evaluations ev2 WHERE ev2.entry_id=e.id) AS eval_search_text,
            (SELECT COUNT(*) FROM sscl_vat_settlements       s  WHERE s.entry_id=e.id)  AS settled_count,
            COALESCE((SELECT SUM(s.amount) FROM sscl_vat_settlements s WHERE s.entry_id=e.id),0) AS settled_total,
            (SELECT l2.line_type FROM sscl_vat_email_lines l2 WHERE l2.entry_id=e.id ORDER BY l2.sort_order ASC, l2.id ASC LIMIT 1) AS entry_type,
            (SELECT COUNT(DISTINCT l3.line_type) FROM sscl_vat_email_lines l3 WHERE l3.entry_id=e.id) AS distinct_type_count
     FROM sscl_vat_email_entries e $where_sql
     ORDER BY e.email_date DESC, e.id DESC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $entries[] = $r;

$totals_row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as cnt,
            COALESCE(SUM(total_net),0)    as sum_net,
            COALESCE(SUM(total_vat),0)    as sum_vat,
            COALESCE(SUM(total_amount),0) as sum_total
     FROM sscl_vat_email_entries e $where_sql"));

include 'header.php';
?>
<!------------------ EXTERNAL LIBS -------------------------------->
<link  href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
*,*::before,*::after{box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif}

.sve-page-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;margin-bottom:24px}
.sve-page-title{font-size:24px;font-weight:800;color:#0f172a;margin:0;display:flex;align-items:center;gap:10px}
.sve-page-title i{color:#0369a1}
.sve-page-sub{font-size:13px;color:#64748b;margin:3px 0 0}

.sve-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:22px}
.sve-sum-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px 18px}
.sve-sum-lbl{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px}
.sve-sum-val{font-size:20px;font-weight:800;color:#0f172a}
.sve-sum-sub{font-size:11px;color:#cbd5e1;margin-top:3px}

.sve-filter{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;margin-bottom:18px}
.sve-fg{display:flex;flex-direction:column;gap:4px}
.sve-fl{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
.sve-fi{padding:8px 12px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit;outline:none;height:36px;background:#fff}
.sve-fi:focus{border-color:#0369a1}

.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 20px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap;transition:all .18s;text-decoration:none}
.btn-sm{padding:7px 14px;font-size:12px}
.btn-xs{padding:4px 10px;font-size:11px}
.btn-primary{background:#0369a1;color:#fff}.btn-primary:hover{background:#0284c7}
.btn-dark{background:#0f172a;color:#fff}.btn-dark:hover{background:#1e293b}
.btn-light{background:#f8fafc;color:#374151;border:1px solid #e2e8f0}.btn-light:hover{background:#f1f5f9}
.btn-danger{background:#fef2f2;color:#dc2626;border:1px solid #fca5a5}.btn-danger:hover{background:#dc2626;color:#fff}
.btn-success{background:#f0fdf4;color:#16a34a;border:1px solid #86efac}.btn-success:hover{background:#16a34a;color:#fff}
.btn-amber{background:#fffbeb;color:#d97706;border:1px solid #fde68a}.btn-amber:hover{background:#d97706;color:#fff}
.btn-new{background:linear-gradient(135deg,#0369a1,#0284c7);color:#fff;box-shadow:0 2px 8px rgba(3,105,161,.3);padding:10px 22px;font-size:14px}
.btn-new:hover{filter:brightness(1.08);transform:translateY(-1px)}

.sve-table-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}
.sve-table-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:10px}
.sve-table-title{font-size:14px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px}
.sve-count-badge{background:#f1f5f9;color:#475569;font-size:11px;font-weight:700;padding:2px 9px;border-radius:10px}
.sve-search-wrap{position:relative}
.sve-search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;pointer-events:none}
.sve-search{width:220px;padding:7px 12px 7px 30px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit;outline:none}
.sve-search:focus{border-color:#0369a1}

.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead th{background:#f8fafc;padding:10px 14px;text-align:left;font-weight:700;font-size:11px;color:#475569;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;border-bottom:2px solid #e2e8f0}
.data-table th.num,.data-table td.num{text-align:right}
.data-table tbody tr{border-bottom:1px solid #f8fafc;transition:background .1s}
.data-table tbody tr:hover{background:#f8fafc}
.data-table td{padding:11px 14px;color:#1e293b;vertical-align:middle;white-space:nowrap}
.data-table tfoot td{padding:11px 14px;font-weight:800;color:#0f172a;background:#f0f9ff;border-top:2px solid #bae6fd;font-size:13px}
.empty-state{text-align:center;padding:60px 20px;color:#94a3b8}
.empty-state i{font-size:40px;display:block;margin-bottom:14px;color:#cbd5e1}

.tag{display:inline-flex;align-items:center;gap:3px;padding:2px 9px;border-radius:10px;font-size:10px;font-weight:700}
.tag-blue{background:#dbeafe;color:#1e40af}
.tag-green{background:#dcfce7;color:#166534}
.tag-orange{background:#fff7ed;color:#c2410c}
.date-badge{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:#1e293b}
.amt-net{color:#0369a1;font-weight:700}
.amt-vat{color:#7c3aed;font-weight:700}
.amt-tot{color:#16a34a;font-weight:800;font-size:14px}

.action-btns{display:flex;gap:5px;align-items:center}
.abtn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid #e2e8f0;background:#fff;color:#6b7280;cursor:pointer;font-size:12px;transition:all .18s}
.abtn:hover{transform:translateY(-1px);box-shadow:0 2px 4px rgba(0,0,0,.08)}
.abtn-view:hover{background:#3b82f6;color:#fff;border-color:#3b82f6}
.abtn-edit:hover{background:#0369a1;color:#fff;border-color:#0369a1}
.abtn-del:hover{background:#ef4444;color:#fff;border-color:#ef4444}
.abtn-settle:hover{background:#4338ca;color:#fff;border-color:#4338ca}

.settled-badge{display:inline-flex;flex-direction:column;align-items:center;gap:1px;font-size:10px;font-weight:700;padding:4px 9px;border-radius:8px;line-height:1.4}
.settled-badge.full{background:#dcfce7;color:#166534}
.settled-badge.partial{background:#fef3c7;color:#92400e}
.settled-badge.none{background:#f1f5f9;color:#94a3b8}

.eval-badge{display:inline-flex;align-items:center;gap:4px;background:#ccfbf1;color:#0f766e;border:1px solid #99f6e4;border-radius:6px;padding:2px 9px;font-size:10px;font-weight:700}

/* ── Settle modal ── */
.stl-strip{display:flex;flex-wrap:wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden}
.stl-cell{flex:1;min-width:140px;padding:12px 18px;border-right:1px solid #e2e8f0}
.stl-cell:last-child{border-right:none}
.stl-lbl{font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;font-weight:700;margin-bottom:3px}
.stl-val{font-size:17px;font-weight:800;color:#0f172a}
.stl-val.sky{color:#0369a1}.stl-val.green{color:#16a34a}.stl-val.amber{color:#d97706}
.stl-tbl-scroll{overflow-x:auto;margin-bottom:18px}
.stl-tbl{width:100%;border-collapse:collapse;font-size:12.5px;min-width:760px}
.stl-tbl thead th{background:#f8fafc;padding:8px 10px;text-align:left;font-size:10.5px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.3px;border-bottom:1px solid #e2e8f0;white-space:nowrap}
.stl-tbl th.r,.stl-tbl td.r{text-align:right}
.stl-tbl tbody tr{border-bottom:1px solid #f1f5f9}
.stl-tbl td{padding:8px 10px;vertical-align:middle}
.stl-tbl tr.stl-match{background:#f0fdf4}
.stl-match-tag{display:inline-flex;align-items:center;gap:3px;background:#dcfce7;color:#166534;font-size:9px;font-weight:800;padding:1px 7px;border-radius:8px;margin-left:6px;text-transform:uppercase;vertical-align:middle}
.stl-empty{text-align:center;padding:18px;color:#94a3b8;font-size:12px;background:#fafafa;border:1px dashed #e2e8f0;border-radius:8px;margin-bottom:18px}
.stl-settled-item{display:flex;align-items:center;gap:10px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:9px 14px;margin-bottom:7px}
.stl-settled-rm{background:none;border:1px solid #fca5a5;border-radius:6px;color:#dc2626;cursor:pointer;padding:5px 9px;font-size:11px;font-family:inherit;white-space:nowrap}
.stl-settled-rm:hover{background:#fef2f2}

/* ── Modal ── */
.mo{position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:9000;display:none;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto;backdrop-filter:blur(2px)}
.mo.open{display:flex;animation:moFade .2s ease}
@keyframes moFade{from{opacity:0}to{opacity:1}}
.mo-box{background:#fff;border-radius:16px;width:100%;max-width:900px;box-shadow:0 24px 80px rgba(0,0,0,.22);animation:moSlide .25s ease;margin:auto;display:flex;flex-direction:column}
@keyframes moSlide{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.mo-hdr{background:linear-gradient(135deg,#0c4a6e,#0369a1,#0284c7);padding:20px 26px;display:flex;align-items:center;justify-content:space-between;border-radius:16px 16px 0 0;flex-shrink:0}
.mo-hdr-title{color:#fff;font-size:17px;font-weight:800;display:flex;align-items:center;gap:10px}
.mo-close{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:8px;padding:7px 16px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}
.mo-close:hover{background:rgba(255,255,255,.25)}
.mo-body{padding:24px 28px;overflow:visible}
.mo-footer{padding:16px 28px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:10px;background:#fafafa;border-radius:0 0 16px 16px;flex-shrink:0}

.fgrp{display:flex;flex-direction:column;gap:5px;margin-bottom:18px}
.flbl{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px}
.flbl .opt{font-size:10px;font-weight:400;color:#94a3b8;text-transform:none;letter-spacing:0}
.finp{padding:10px 13px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit;outline:none;width:100%;background:#fff}
.finp:focus{border-color:#0369a1;box-shadow:0 0 0 3px rgba(3,105,161,.1)}
.ftarea{padding:10px 13px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;font-family:inherit;outline:none;width:100%;background:#fff;resize:vertical;line-height:1.55}
.ftarea:focus{border-color:#0369a1;box-shadow:0 0 0 3px rgba(3,105,161,.1)}

/* ── File upload zones ── */
.file-zone{border:2px dashed #bfdbfe;border-radius:10px;padding:16px;text-align:center;cursor:pointer;background:#f0f9ff;position:relative;transition:all .2s}
.file-zone:hover,.file-zone.drag{border-color:#0369a1;background:#e0f2fe}
.file-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.file-zone-icon{font-size:22px;color:#7dd3fc;display:block;margin-bottom:4px}
.file-zone-text{font-size:13px;color:#475569;font-weight:600}
.file-zone-sub{font-size:11px;color:#94a3b8;margin-top:2px}

.file-zone-eval{border-color:#99f6e4;background:#f0fdfa}
.file-zone-eval:hover,.file-zone-eval.drag{border-color:#0f766e;background:#ccfbf1}
.file-zone-eval .file-zone-icon{color:#2dd4bf}

.pending-files{display:flex;flex-direction:column;gap:5px;margin-top:8px}
.pfile{display:flex;align-items:center;gap:8px;border-radius:7px;padding:6px 11px;font-size:12px}
.pfile-email{background:#f0fdf4;border:1px solid #86efac}
.pfile-eval{background:#f0fdfa;border:1px solid #99f6e4}
.pfile-name{flex:1;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#166534}
.pfile-eval .pfile-name{color:#0f766e}
.pfile-size{font-size:11px;color:#94a3b8;flex-shrink:0}
.pfile-rm{background:none;border:none;cursor:pointer;color:#dc2626;font-size:13px;padding:2px 4px;line-height:1;flex-shrink:0}

.saved-files{display:flex;flex-direction:column;gap:5px;margin-top:6px}
.sfile{display:flex;align-items:center;gap:8px;border-radius:7px;padding:6px 11px}
.sfile-email{background:#eff6ff;border:1px solid #bfdbfe}
.sfile-eval{background:#f0fdfa;border:1px solid #99f6e4}
.sfile-name{flex:1;font-size:12px;font-weight:600;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sfile-email .sfile-name{color:#1e40af}
.sfile-eval  .sfile-name{color:#0f766e}
.sfile-name:hover{text-decoration:underline}
.sfile-rm{background:none;border:none;cursor:pointer;color:#dc2626;font-size:12px;padding:2px 5px;line-height:1;flex-shrink:0}
.sfile-date{font-size:10px;color:#94a3b8;flex-shrink:0}

/* ── Evaluation notes ── */
.eval-field-wrap{background:#f0fdfa;border:1px solid #99f6e4;border-radius:10px;padding:14px 16px;margin-bottom:18px}
.eval-field-hdr{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.eval-field-hdr i{color:#0f766e;font-size:14px}
.eval-field-label{font-size:11px;font-weight:800;color:#0f766e;text-transform:uppercase;letter-spacing:.5px}
.eval-field-hint{font-size:10px;color:#5eead4;margin-left:auto}
.eval-char-count{font-size:10px;color:#94a3b8;text-align:right;margin-top:4px}

.pending-evals{display:flex;flex-direction:column;gap:6px;margin-bottom:8px}
.peval{display:flex;align-items:flex-start;gap:8px;background:#f0fdf4;border:1px solid #86efac;border-radius:7px;padding:8px 12px}
.peval-text{flex:1;font-size:12px;color:#166534;line-height:1.5;white-space:pre-wrap;word-break:break-word}
.peval-rm{background:none;border:none;cursor:pointer;color:#dc2626;font-size:13px;padding:2px 4px;line-height:1;flex-shrink:0;margin-top:1px}
.saved-evals{display:flex;flex-direction:column;gap:6px;margin-bottom:8px}
.seval{display:flex;align-items:flex-start;gap:8px;background:#fff;border:1px solid #99f6e4;border-radius:7px;padding:8px 12px}
.seval-text{flex:1;font-size:12px;color:#134e4a;line-height:1.5;white-space:pre-wrap;word-break:break-word}
.seval-date{font-size:10px;color:#5eead4;flex-shrink:0;white-space:nowrap;margin-top:1px}
.seval-rm{background:none;border:none;cursor:pointer;color:#dc2626;font-size:12px;padding:2px 5px;line-height:1;flex-shrink:0;margin-top:1px}

.form-row2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.sec-divider{display:flex;align-items:center;gap:12px;margin:22px 0 14px}
.sec-divider-line{flex:1;height:1px;background:#e2e8f0}
.sec-divider-label{font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.6px;white-space:nowrap}

/* ── Lines table ── */
.lines-wrap{border:1px solid #e2e8f0;border-radius:10px;overflow:visible;margin-bottom:14px}
.lines-tbl-scroll{overflow-x:auto;border-radius:10px 10px 0 0}
.lines-tbl{width:100%;border-collapse:collapse;font-size:13px;min-width:560px}
.lines-tbl thead th{background:#f8fafc;padding:9px 12px;text-align:left;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #e2e8f0}
.lines-tbl th.r,.lines-tbl td.r{text-align:right}
.lines-tbl tbody tr{border-bottom:1px solid #f1f5f9}
.lines-tbl tbody tr:last-child{border-bottom:none}
.lines-tbl td{padding:8px 10px;vertical-align:middle}

.type-toggle{display:inline-flex;border:1px solid #e2e8f0;border-radius:7px;overflow:hidden}
.type-btn{padding:5px 12px;font-size:11px;font-weight:700;cursor:pointer;border:none;background:#fff;color:#64748b;font-family:inherit;transition:all .15s}
.type-btn.active.cust{background:#dbeafe;color:#1e40af}
.type-btn.active.emp{background:#fef3c7;color:#92400e}
.type-btn:disabled{opacity:.5;cursor:not-allowed}
.entry-type-select{display:flex;align-items:center;flex-wrap:wrap;gap:4px;margin-bottom:14px}

.lines-tbl .select2-container{min-width:200px !important;max-width:260px}
.lines-tbl .select2-container--default .select2-selection--single{height:34px;border:1px solid #d1d5db;border-radius:6px;display:flex;align-items:center;padding:0 10px}
.lines-tbl .select2-container--default .select2-selection--single .select2-selection__rendered{line-height:32px;font-size:13px;font-family:inherit;color:#1e293b;padding:0}
.lines-tbl .select2-container--default .select2-selection--single .select2-selection__arrow{height:32px;right:6px}
.lines-tbl .select2-container--default.select2-container--focus .select2-selection--single,
.lines-tbl .select2-container--default.select2-container--open .select2-selection--single{border-color:#0369a1;box-shadow:0 0 0 2px rgba(3,105,161,.12)}

.line-net-inp{width:120px;padding:6px 9px;border:1px solid #bfdbfe;border-radius:6px;font-size:13px;font-family:inherit;font-weight:700;color:#0369a1;background:#f0f9ff;outline:none;text-align:right}
.line-net-inp:focus{border-color:#0369a1;box-shadow:0 0 0 2px rgba(3,105,161,.1)}
.line-ro{width:110px;padding:6px 9px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;font-family:inherit;font-weight:700;background:#f8fafc;color:#475569;outline:none;text-align:right}
.line-ro.green{color:#16a34a;background:#f0fdf4;border-color:#bbf7d0}
.line-ro.purple{color:#7c3aed;background:#faf5ff;border-color:#e9d5ff}
.line-del-btn{background:none;border:1px solid #fca5a5;border-radius:6px;color:#dc2626;cursor:pointer;padding:5px 9px;font-size:11px;display:inline-flex;align-items:center;gap:3px;font-family:inherit;transition:all .15s}
.line-del-btn:hover{background:#fef2f2}
.lines-summary{display:flex;gap:20px;padding:12px 16px;background:#f0f9ff;border:1px solid #e2e8f0;border-top:2px solid #bae6fd;flex-wrap:wrap}
.ls-item{display:flex;flex-direction:column;gap:2px}
.ls-lbl{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.4px;font-weight:600}
.ls-val{font-size:16px;font-weight:800}
.ls-val.net{color:#0369a1}.ls-val.vat{color:#7c3aed}.ls-val.tot{color:#16a34a}
.add-line-row{display:flex;gap:8px;padding:12px 14px;background:#fafafa;border:1px solid #e2e8f0;border-top:1px solid #f1f5f9;border-radius:0 0 10px 10px}

/* ── View drawer ── */
.drw{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9500;display:none;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto}
.drw.open{display:flex;animation:moFade .2s ease}
.drw-box{background:#fff;border-radius:16px;width:100%;max-width:920px;box-shadow:0 24px 80px rgba(0,0,0,.25);overflow:hidden;animation:moSlide .25s ease;margin:auto}
.drw-hdr{background:linear-gradient(135deg,#064e3b,#065f46,#047857);padding:20px 26px;display:flex;align-items:center;justify-content:space-between}
.drw-hdr-title{color:#fff;font-size:16px;font-weight:800;display:flex;align-items:center;gap:10px}
.drw-close{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:8px;padding:7px 16px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}
.drw-close:hover{background:rgba(255,255,255,.25)}
.drw-strip{display:flex;flex-wrap:wrap;background:#f8fafc;border-bottom:2px solid #e2e8f0}
.drw-strip-cell{display:flex;flex-direction:column;gap:2px;padding:13px 20px;border-right:1px solid #e2e8f0;min-width:100px}
.drw-strip-cell:last-child{border-right:none}
.ds-lbl{font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;font-weight:600}
.ds-val{font-size:15px;font-weight:800;color:#0f172a}
.ds-val.sky{color:#0369a1}.ds-val.purple{color:#7c3aed}.ds-val.green{color:#16a34a}
.drw-body{padding:22px 26px}

.eval-view-box{background:#f0fdfa;border:1px solid #99f6e4;border-radius:10px;padding:14px 18px;margin-bottom:18px}
.eval-view-hdr{display:flex;align-items:center;gap:8px;margin-bottom:8px}
.eval-view-hdr i{color:#0f766e}
.eval-view-label{font-size:11px;font-weight:800;color:#0f766e;text-transform:uppercase;letter-spacing:.5px}
.eval-view-text{font-size:13px;color:#134e4a;line-height:1.65;white-space:pre-wrap;word-break:break-word}

.vlines-tbl{width:100%;border-collapse:collapse;font-size:13px;margin-top:12px}
.vlines-tbl thead th{background:#f0fdf4;padding:9px 12px;text-align:left;font-size:11px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #bbf7d0}
.vlines-tbl th.r,.vlines-tbl td.r{text-align:right}
.vlines-tbl tbody tr{border-bottom:1px solid #f1f5f9}
.vlines-tbl td{padding:10px 12px;vertical-align:middle}
.vlines-tbl tfoot td{padding:10px 12px;font-weight:800;background:#f0fdf4;border-top:2px solid #bbf7d0;color:#0f172a}
.type-pill-cust{display:inline-flex;align-items:center;gap:4px;background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;border-radius:5px;padding:2px 9px;font-size:10px;font-weight:700}
.type-pill-emp{display:inline-flex;align-items:center;gap:4px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:5px;padding:2px 9px;font-size:10px;font-weight:700}
.ref-name{font-weight:600;color:#1e293b}.ref-code{font-size:10px;color:#94a3b8;font-family:monospace}
.att-grid{display:flex;flex-direction:column;gap:6px;margin-top:8px}
.att-item{display:flex;align-items:center;gap:8px;border-radius:7px;padding:8px 12px}
.att-item-email{background:#eff6ff;border:1px solid #bfdbfe}
.att-item-eval{background:#f0fdfa;border:1px solid #99f6e4}
.att-link{flex:1;font-size:12px;font-weight:600;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.att-item-email .att-link{color:#1e40af}
.att-item-eval  .att-link{color:#0f766e}
.att-link:hover{text-decoration:underline}
.att-date{font-size:10px;color:#94a3b8;flex-shrink:0}
.no-items{text-align:center;padding:24px;color:#94a3b8;font-size:13px}
.no-items i{font-size:24px;display:block;margin-bottom:8px;color:#cbd5e1}

/* ── Select2 global ── */
.select2-container{width:100% !important}
.select2-container--default .select2-selection--single{height:42px;border:1px solid #d1d5db;border-radius:8px;display:flex;align-items:center;padding:0 12px}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open  .select2-selection--single{border-color:#0369a1;box-shadow:0 0 0 3px rgba(3,105,161,.1)}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:40px;font-size:14px;font-family:inherit;color:#1e293b;padding:0}
.select2-container--default .select2-selection--single .select2-selection__placeholder{color:#94a3b8}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:40px;right:10px}
.select2-dropdown{border:1px solid #d1d5db;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.1);font-size:13px;font-family:inherit;z-index:99999 !important}
.select2-container--default .select2-search--dropdown .select2-search__field{border:1px solid #d1d5db;border-radius:6px;padding:8px 12px;font-size:13px;font-family:inherit;outline:none}
.select2-container--default .select2-results__option{padding:10px 14px}
.select2-container--default .select2-results__option--highlighted[aria-selected]{background:#0369a1;color:#fff}

#toast{position:fixed;bottom:24px;right:24px;padding:13px 22px;border-radius:10px;font-size:14px;font-weight:600;color:#fff;z-index:99999;display:none;box-shadow:0 4px 20px rgba(0,0,0,.18)}
#toast.success{background:#16a34a}#toast.error{background:#dc2626}#toast.info{background:#0369a1}#toast.warn{background:#d97706}

@media(max-width:680px){.form-row2{grid-template-columns:1fr}.lines-tbl{font-size:11px}.mo-body,.drw-body{padding:16px}}
</style>

<!-- ══ PAGE ══════════════════════════════════════════════════════ -->
<div class="sve-page-header">
    <div>
        <h2 class="sve-page-title"><i class="fa-solid fa-envelope-open-text"></i> Drivers &amp; Loyalty</h2>
        <p class="sve-page-sub">Record email-based claim entries with customer / employee line items, email files &amp; evaluation files</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <button class="btn btn-new" onclick="openNewEntry()"><i class="fa-solid fa-plus"></i> New Entry</button>
    </div>
</div>

<!-- SUMMARY -->
<div class="sve-summary">
    <div class="sve-sum-card">
        <div class="sve-sum-lbl">Total Entries</div>
        <div class="sve-sum-val"><?php echo number_format($totals_row['cnt'] ?? 0); ?></div>
        <div class="sve-sum-sub">All records</div>
    </div>
    <div class="sve-sum-card">
        <div class="sve-sum-lbl">Total Net Amount</div>
        <div class="sve-sum-val" style="color:#0369a1"><?php echo number_format($totals_row['sum_net'] ?? 0, 2); ?></div>
        <div class="sve-sum-sub">Excl. VAT</div>
    </div>
    <div class="sve-sum-card">
        <div class="sve-sum-lbl">Total VAT 18%</div>
        <div class="sve-sum-val" style="color:#7c3aed"><?php echo number_format($totals_row['sum_vat'] ?? 0, 2); ?></div>
        <div class="sve-sum-sub">18% on net</div>
    </div>
    <div class="sve-sum-card">
        <div class="sve-sum-lbl">Total Amount</div>
        <div class="sve-sum-val" style="color:#16a34a"><?php echo number_format($totals_row['sum_total'] ?? 0, 2); ?></div>
        <div class="sve-sum-sub">Net + VAT</div>
    </div>
</div>

<!-- FILTER -->
<form method="GET" class="sve-filter">
    <div class="sve-fg">
        <label class="sve-fl">Email Date From</label>
        <input type="date" name="filter_from" class="sve-fi" value="<?php echo htmlspecialchars($filter_from); ?>" style="width:145px">
    </div>
    <div class="sve-fg">
        <label class="sve-fl">To</label>
        <input type="date" name="filter_to" class="sve-fi" value="<?php echo htmlspecialchars($filter_to); ?>" style="width:145px">
    </div>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
    <a href="dal.php" class="btn btn-light btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
</form>

<!-- TABLE -->
<div class="sve-table-card">
    <div class="sve-table-header">
        <div class="sve-table-title">
            <i class="fa-solid fa-table"></i> All Entries
            <span class="sve-count-badge" id="entryCountBadge"><?php echo count($entries); ?></span>
        </div>
        <div class="sve-search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="sve-search" id="tblSearch" placeholder="Search…" oninput="doTableSearch()">
        </div>
    </div>

    <?php if (empty($entries)): ?>
    <div class="empty-state">
        <i class="fa-solid fa-envelope-open-text"></i>
        <p>No entries yet. Click <strong>New Entry</strong> to get started.</p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="data-table" id="mainTable">
        <thead>
            <tr>
                <th>#</th>
                <th>Email Date</th>
                <th style="text-align:center">Type</th>
                <th>Description</th>
                <th style="text-align:center">Evaluation</th>
                <th class="num">Net Amount</th>
                <th class="num">VAT 18%</th>
                <th class="num">Total Amount</th>
                <th style="text-align:center">Lines</th>
                <th style="text-align:center">Email Files</th>
                <th style="text-align:center">Eval Files</th>
                <th style="text-align:center">Settled</th>
                <th style="text-align:center">Actions</th>
            </tr>
        </thead>
        <tbody id="mainTbody">
        <?php foreach ($entries as $idx => $e):
            $net        = floatval($e['total_net']);
            $vat        = floatval($e['total_vat']);
            $tot        = floatval($e['total_amount']);
            $evalCount  = intval($e['eval_count'] ?? 0);
            $evalSearch = trim($e['eval_search_text'] ?? '');
            $efc        = intval($e['email_file_count'] ?? 0);
            $evfc       = intval($e['eval_file_count']  ?? 0);
        ?>
        <tr id="etr-<?php echo $e['id']; ?>"
            data-search="<?php echo strtolower(htmlspecialchars(($e['email_date']??'').' '.($e['description']??'').' '.($e['entry_type']??'').' '.$evalSearch)); ?>">
            <td style="color:#94a3b8;font-size:11px"><?php echo $idx + 1; ?></td>
            <td>
                <div class="date-badge">
                    <i class="fa-regular fa-calendar" style="color:#0369a1"></i>
                    <?php echo $e['email_date'] ? date('d M Y', strtotime($e['email_date'])) : '—'; ?>
                </div>
            </td>
            <td style="text-align:center"><?php echo entry_type_badge_html($e['entry_type'] ?? null, $e['distinct_type_count'] ?? 0); ?></td>
            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;font-size:12px;color:#475569"
                title="<?php echo htmlspecialchars($e['description'] ?? ''); ?>">
                <?php echo htmlspecialchars(mb_substr($e['description'] ?? '—', 0, 45));
                      if (mb_strlen($e['description'] ?? '') > 45) echo '…'; ?>
            </td>
            <td style="text-align:center">
                <?php if ($evalCount > 0): ?>
                    <span class="eval-badge" title="<?php echo htmlspecialchars(mb_substr($evalSearch,0,200)); ?>">
                        <i class="fa-solid fa-microscope"></i> <?php echo $evalCount; ?> eval<?php echo $evalCount>1?'s':''; ?>
                    </span>
                <?php else: ?><span style="color:#d1d5db;font-size:11px">—</span><?php endif; ?>
            </td>
            <td class="num amt-net" id="td-net-<?php echo $e['id']; ?>"><?php echo $net>0?number_format($net,2):'—'; ?></td>
            <td class="num amt-vat" id="td-vat-<?php echo $e['id']; ?>"><?php echo $vat>0?number_format($vat,2):'—'; ?></td>
            <td class="num amt-tot" id="td-tot-<?php echo $e['id']; ?>"><?php echo $tot>0?number_format($tot,2):'—'; ?></td>
            <td style="text-align:center">
                <?php $lc=intval($e['line_count']); ?>
                <?php if($lc>0): ?><span class="tag tag-green"><i class="fa-solid fa-list-check"></i> <?php echo $lc; ?> line<?php echo $lc>1?'s':''; ?></span>
                <?php else: ?><span style="color:#d1d5db">—</span><?php endif; ?>
            </td>
            <td style="text-align:center">
                <?php if($efc>0): ?><span class="tag tag-blue"><i class="fa-solid fa-envelope"></i> <?php echo $efc; ?></span>
                <?php else: ?><span style="color:#d1d5db">—</span><?php endif; ?>
            </td>
            <td style="text-align:center">
                <?php if($evfc>0): ?><span class="tag tag-orange"><i class="fa-solid fa-file-lines"></i> <?php echo $evfc; ?></span>
                <?php else: ?><span style="color:#d1d5db">—</span><?php endif; ?>
            </td>
            <td style="text-align:center" id="td-settled-<?php echo $e['id']; ?>">
                <?php echo settled_badge_html($e['settled_total']??0, $tot); ?>
            </td>
            <td style="text-align:center">
                <div class="action-btns" style="justify-content:center">
                    <button class="abtn abtn-view"   title="View"   onclick="openView(<?php echo $e['id']; ?>)"><i class="fa-solid fa-eye"></i></button>
                    <button class="abtn abtn-edit"   title="Edit"   onclick="openEditEntry(<?php echo $e['id']; ?>)"><i class="fa-solid fa-pen"></i></button>
                    <button class="abtn abtn-settle" title="Settle" onclick="openSettle(<?php echo $e['id']; ?>,<?php echo $tot; ?>,'<?php echo htmlspecialchars($e['email_date']??'',ENT_QUOTES); ?>')"><i class="fa-solid fa-handshake"></i></button>
                    <button class="abtn abtn-del"    title="Delete" onclick="deleteEntry(<?php echo $e['id']; ?>)"><i class="fa-solid fa-trash"></i></button>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" style="text-align:right;font-size:11px;color:#64748b;font-weight:600">Page Totals</td>
                <td class="num" style="color:#0369a1"><?php echo number_format($totals_row['sum_net']??0,2); ?></td>
                <td class="num" style="color:#7c3aed"><?php echo number_format($totals_row['sum_vat']??0,2); ?></td>
                <td class="num" style="color:#16a34a"><?php echo number_format($totals_row['sum_total']??0,2); ?></td>
                <td colspan="5"></td>
            </tr>
        </tfoot>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- ══ ADD / EDIT MODAL ══════════════════════════════════════════ -->
<div class="mo" id="entryModal">
<div class="mo-box">
    <div class="mo-hdr">
        <div class="mo-hdr-title"><i class="fa-solid fa-envelope-open-text"></i><span id="modalTitle">New Entry</span></div>
        <button class="mo-close" onclick="closeEntryModal()"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>
    <div class="mo-body">
        <input type="hidden" id="fe_id" value="0">

        <div class="form-row2">
            <div class="fgrp">
                <label class="flbl">Email Date <span style="color:#ef4444">*</span></label>
                <input type="date" class="finp" id="fe_date">
            </div>
            <div class="fgrp">
                <label class="flbl">Description <span class="opt">(optional)</span></label>
                <input type="text" class="finp" id="fe_desc" placeholder="Brief description…">
            </div>
        </div>

        <!-- ══ EMAIL FILES ════════════════════════════════════════ -->
        <div class="sec-divider">
            <div class="sec-divider-line"></div>
            <div class="sec-divider-label"><i class="fa-solid fa-envelope"></i> Email Files</div>
            <div class="sec-divider-line"></div>
        </div>
        <div id="savedEmailFilesWrap" style="display:none;margin-bottom:8px">
            <div style="font-size:11px;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px">Existing Email Files</div>
            <div class="saved-files" id="savedEmailFilesList"></div>
        </div>
        <div class="file-zone" id="emailFileZone"
             ondragover="event.preventDefault();this.classList.add('drag')"
             ondragleave="this.classList.remove('drag')"
             ondrop="handleFileDrop(event,'email')">
            <input type="file" id="fe_email_files" multiple
                accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.txt"
                onchange="handleFileSelect(this,'email')">
            <i class="fa-solid fa-envelope file-zone-icon"></i>
            <div class="file-zone-text">Click or drag &amp; drop email files</div>
            <div class="file-zone-sub">PDF, DOC, DOCX, XLS, XLSX, JPG, PNG — multiple files</div>
        </div>
        <div class="pending-files" id="pendingEmailFilesList"></div>

        <!-- ══ EVALUATION SECTION ═════════════════════════════════ -->
        <div class="sec-divider">
            <div class="sec-divider-line"></div>
            <div class="sec-divider-label"><i class="fa-solid fa-microscope"></i> Attachment Evaluation</div>
            <div class="sec-divider-line"></div>
        </div>

        <!-- Evaluation notes -->
        <div class="eval-field-wrap">
            <div class="eval-field-hdr">
                <i class="fa-solid fa-note-sticky"></i>
                <span class="eval-field-label">Evaluation Notes</span>
                <span class="eval-field-hint">Each note saved separately</span>
            </div>
            <div id="savedEvalsWrap" style="display:none">
                <div style="font-size:11px;font-weight:700;color:#0f766e;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px">Existing Notes</div>
                <div class="saved-evals" id="savedEvalsList"></div>
            </div>
            <div class="pending-evals" id="pendingEvalsList"></div>
            <div style="display:flex;gap:8px;align-items:flex-start">
                <textarea class="ftarea" id="fe_eval_new" rows="2" style="flex:1"
                    placeholder="e.g. Verified against original invoice — amounts match."
                    oninput="updateEvalCharCount()"></textarea>
                <button type="button" class="btn btn-success btn-sm" style="flex-shrink:0" onclick="addPendingEval()">
                    <i class="fa-solid fa-plus"></i> Add
                </button>
            </div>
            <div class="eval-char-count" id="evalCharCount">0 characters</div>
        </div>

        <!-- Evaluation files -->
        <div style="margin-bottom:18px">
            <div style="font-size:11px;font-weight:700;color:#0f766e;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px">
                <i class="fa-solid fa-file-lines"></i> Evaluation Files
            </div>
            <div id="savedEvalFilesWrap" style="display:none;margin-bottom:8px">
                <div style="font-size:11px;font-weight:700;color:#0f766e;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px">Existing Evaluation Files</div>
                <div class="saved-files" id="savedEvalFilesList"></div>
            </div>
            <div class="file-zone file-zone-eval" id="evalFileZone"
                 ondragover="event.preventDefault();this.classList.add('drag')"
                 ondragleave="this.classList.remove('drag')"
                 ondrop="handleFileDrop(event,'eval')">
                <input type="file" id="fe_eval_files" multiple
                    accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.txt"
                    onchange="handleFileSelect(this,'eval')">
                <i class="fa-solid fa-file-lines file-zone-icon"></i>
                <div class="file-zone-text">Click or drag &amp; drop evaluation files</div>
                <div class="file-zone-sub">PDF, DOC, DOCX, XLS, XLSX, JPG, PNG — multiple files</div>
            </div>
            <div class="pending-files" id="pendingEvalFilesList"></div>
        </div>

        <!-- ══ LINES ══════════════════════════════════════════════ -->
        <div class="sec-divider">
            <div class="sec-divider-line"></div>
            <div class="sec-divider-label"><i class="fa-solid fa-list-check"></i> Customer &amp; Employee Lines</div>
            <div class="sec-divider-line"></div>
        </div>

        <div class="entry-type-select">
            <span class="flbl" style="margin-right:10px">Entry Type</span>
            <div class="type-toggle" id="entryTypeToggle">
                <button type="button" class="type-btn active cust" id="etype-cust" onclick="setEntryType('customer')">
                    <i class="fa-solid fa-user"></i> Customer
                </button>
                <button type="button" class="type-btn" id="etype-emp" onclick="setEntryType('employee')">
                    <i class="fa-solid fa-id-badge"></i> Employee
                </button>
            </div>
            <span class="opt" id="entryTypeHint" style="margin-left:10px"></span>
        </div>

        <div class="lines-wrap">
            <div class="lines-tbl-scroll">
            <table class="lines-tbl">
                <thead>
                    <tr>
                        <th style="width:110px">Type</th>
                        <th style="min-width:240px">Customer / Employee</th>
                        <th class="r" style="width:130px">Net Amount</th>
                        <th class="r" style="width:115px">VAT 18%</th>
                        <th class="r" style="width:120px">Total</th>
                        <th style="width:50px;text-align:center"></th>
                    </tr>
                </thead>
                <tbody id="linesTbody"></tbody>
            </table>
            </div>
            <div class="lines-summary">
                <div class="ls-item"><div class="ls-lbl">Net Total</div><div class="ls-val net" id="ls_net">0.00</div></div>
                <div class="ls-item"><div class="ls-lbl">VAT 18%</div><div class="ls-val vat" id="ls_vat">0.00</div></div>
                <div class="ls-item"><div class="ls-lbl">Grand Total</div><div class="ls-val tot" id="ls_tot">0.00</div></div>
            </div>
            <div class="add-line-row">
                <button class="btn btn-success btn-sm" id="addLineBtn" onclick="addLine()">
                    <i class="fa-solid fa-plus"></i> Add Line
                </button>
            </div>
        </div>
    </div>
    <div class="mo-footer">
        <button class="btn btn-light" onclick="closeEntryModal()">Cancel</button>
        <button class="btn btn-dark" id="saveEntryBtn" onclick="saveEntry()">
            <i class="fa-solid fa-floppy-disk"></i> Save Entry
        </button>
    </div>
</div>
</div>

<!-- ══ VIEW DRAWER ═══════════════════════════════════════════════ -->
<div class="drw" id="viewDrawer">
<div class="drw-box">
    <div class="drw-hdr">
        <div class="drw-hdr-title"><i class="fa-solid fa-eye"></i><span id="drwTitle">Entry Details</span></div>
        <button class="drw-close" onclick="closeView()"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>
    <div class="drw-strip">
        <div class="drw-strip-cell"><div class="ds-lbl">Email Date</div><div class="ds-val"       id="drw_date">—</div></div>
        <div class="drw-strip-cell"><div class="ds-lbl">Net Amount</div><div class="ds-val sky"   id="drw_net">—</div></div>
        <div class="drw-strip-cell"><div class="ds-lbl">VAT 18%</div>  <div class="ds-val purple" id="drw_vat">—</div></div>
        <div class="drw-strip-cell"><div class="ds-lbl">Total</div>    <div class="ds-val green"  id="drw_tot">—</div></div>
        <div class="drw-strip-cell"><div class="ds-lbl">Lines</div>    <div class="ds-val"        id="drw_lines">—</div></div>
        <div class="drw-strip-cell"><div class="ds-lbl">Email Files</div><div class="ds-val"      id="drw_efiles">—</div></div>
        <div class="drw-strip-cell"><div class="ds-lbl">Eval Files</div><div class="ds-val"       id="drw_evfiles">—</div></div>
        <div class="drw-strip-cell"><div class="ds-lbl">Evaluations</div><div class="ds-val"      id="drw_evals">—</div></div>
    </div>
    <div class="drw-body">
        <div id="drwDescWrap" style="margin-bottom:14px;display:none">
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px">Description</div>
            <div id="drwDesc" style="font-size:13px;color:#475569;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;line-height:1.6"></div>
        </div>

        <!-- Email files -->
        <div style="margin-bottom:18px">
            <div style="font-size:11px;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px">
                <i class="fa-solid fa-envelope"></i> Email Files
            </div>
            <div id="drwEmailFiles" class="att-grid">
                <div class="no-items"><i class="fa-solid fa-folder-open"></i> No email files</div>
            </div>
        </div>

        <!-- Evaluation notes + files -->
        <div id="drwEvalWrap" style="display:none;margin-bottom:18px">
            <div class="eval-view-box">
                <div class="eval-view-hdr">
                    <i class="fa-solid fa-microscope"></i>
                    <span class="eval-view-label">Evaluation Notes</span>
                </div>
                <div id="drwEvalList"></div>
            </div>
        </div>

        <div style="margin-bottom:18px">
            <div style="font-size:11px;font-weight:700;color:#0f766e;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px">
                <i class="fa-solid fa-file-lines"></i> Evaluation Files
            </div>
            <div id="drwEvalFiles" class="att-grid">
                <div class="no-items"><i class="fa-solid fa-folder-open"></i> No evaluation files</div>
            </div>
        </div>

        <!-- Lines -->
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px">
                <i class="fa-solid fa-list-check"></i> Line Items
            </div>
            <div id="drwLines"></div>
        </div>

        <div style="display:flex;gap:10px;margin-top:20px;justify-content:flex-end">
            <button class="btn btn-light" onclick="closeView()">Close</button>
            <button class="btn btn-primary btn-sm" onclick="openEditFromView()">
                <i class="fa-solid fa-pen"></i> Edit This Entry
            </button>
        </div>
    </div>
</div>
</div>

<input type="hidden" id="_viewId" value="0">

<!-- ══ SETTLE MODAL ══════════════════════════════════════════════ -->
<div class="mo" id="settleModal">
<div class="mo-box" style="max-width:1120px">
    <div class="mo-hdr" style="background:linear-gradient(135deg,#312e81,#3730a3,#4338ca)">
        <div class="mo-hdr-title"><i class="fa-solid fa-handshake"></i><span id="settleTitle">Manage Claim Settlement</span></div>
        <button class="mo-close" onclick="closeSettleModal()"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>
    <div class="mo-body">
        <input type="hidden" id="stl_entry_id" value="0">
        <div class="stl-strip">
            <div class="stl-cell"><div class="stl-lbl">Email Date</div><div class="stl-val" id="stl_date">—</div></div>
            <div class="stl-cell"><div class="stl-lbl">Target Amount</div><div class="stl-val sky" id="stl_target">0.00</div></div>
            <div class="stl-cell"><div class="stl-lbl">Settled So Far</div><div class="stl-val green" id="stl_settled">0.00</div></div>
            <div class="stl-cell"><div class="stl-lbl">Remaining</div><div class="stl-val amber" id="stl_remaining">0.00</div></div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;margin:16px 0 6px">
            <div class="sve-search-wrap" style="flex:1">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" class="sve-search" id="stlSearch" style="width:100%"
                    placeholder="Search invoice no / description / entity… (Enter to search)"
                    onkeydown="if(event.key==='Enter'){loadSettleData();}">
            </div>
            <button class="btn btn-light btn-sm" onclick="loadSettleData()"><i class="fa-solid fa-rotate"></i> Refresh</button>
        </div>
        <div class="sec-divider" style="margin-top:14px">
            <div class="sec-divider-line"></div>
            <div class="sec-divider-label"><i class="fa-solid fa-check-double"></i> Already Settled For This Entry</div>
            <div class="sec-divider-line"></div>
        </div>
        <div id="stlSettledWrap" style="margin-bottom:8px"></div>
        <div class="sec-divider">
            <div class="sec-divider-line"></div>
            <div class="sec-divider-label"><i class="fa-solid fa-hourglass-half"></i> Pending Claims</div>
            <div class="sec-divider-line"></div>
        </div>
        <div id="stlPendingWrap"></div>
        <div class="sec-divider">
            <div class="sec-divider-line"></div>
            <div class="sec-divider-label"><i class="fa-solid fa-money-check-dollar"></i> Cheque Acknowledgments</div>
            <div class="sec-divider-line"></div>
        </div>
        <div id="stlAckWrap"></div>
    </div>
    <div class="mo-footer">
        <div style="flex:1;font-size:12px;color:#64748b;font-weight:600" id="stlSelInfo">0 item(s) selected</div>
        <button class="btn btn-light" onclick="closeSettleModal()">Close</button>
        <button class="btn btn-dark" id="stlAddBtn" onclick="addSelectedSettlements()">
            <i class="fa-solid fa-link"></i> Add Selected as Settled
        </button>
    </div>
</div>
</div>

<div id="toast"></div>

<script>
const fN  = v => parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
const eh  = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const $id = id => document.getElementById(id);

function toast(msg,type){
    const t=$id('toast');t.textContent=msg;t.className=type;t.style.display='block';
    clearTimeout(t._t);t._t=setTimeout(()=>t.style.display='none',4000);
}

function updateEvalCharCount(){
    const len=($id('fe_eval_new').value||'').length;
    $id('evalCharCount').textContent=len.toLocaleString()+' character'+(len!==1?'s':'');
}

function doTableSearch(){
    const q=$id('tblSearch').value.toLowerCase().trim();
    let vis=0;
    document.querySelectorAll('#mainTbody tr').forEach(row=>{
        const show=!q||(row.dataset.search||'').includes(q);
        row.style.display=show?'':'none';
        if(show)vis++;
    });
    $id('entryCountBadge').textContent=vis;
}

/* ════════════════════════════════════════
   FILE UPLOADS — two separate queues
════════════════════════════════════════ */
let _pendingEmailFiles = [];
let _pendingEvalFiles  = [];
let _savedEmailFiles   = [];
let _savedEvalFiles    = [];

function handleFileSelect(inp, bucket) {
    Array.from(inp.files).forEach(f => addPendingFile(f, bucket));
    inp.value = '';
}
function handleFileDrop(e, bucket) {
    e.preventDefault();
    const zone = bucket === 'email' ? $id('emailFileZone') : $id('evalFileZone');
    zone.classList.remove('drag');
    Array.from(e.dataTransfer.files).forEach(f => addPendingFile(f, bucket));
}
function addPendingFile(f, bucket) {
    const list = bucket === 'email' ? _pendingEmailFiles : _pendingEvalFiles;
    if (list.find(p => p.name === f.name && p.size === f.size)) return;
    list.push(f);
    renderPendingFiles(bucket);
}
function removePendingFile(idx, bucket) {
    if (bucket === 'email') _pendingEmailFiles.splice(idx, 1);
    else                    _pendingEvalFiles.splice(idx, 1);
    renderPendingFiles(bucket);
}
function renderPendingFiles(bucket) {
    const list    = bucket === 'email' ? _pendingEmailFiles : _pendingEvalFiles;
    const el      = $id(bucket === 'email' ? 'pendingEmailFilesList' : 'pendingEvalFilesList');
    const cls     = bucket === 'email' ? 'pfile-email' : 'pfile-eval';
    el.innerHTML  = list.map((f,i)=>
        `<div class="pfile ${cls}">
            <i class="fa-solid fa-file" style="font-size:13px;flex-shrink:0;color:${bucket==='email'?'#16a34a':'#0f766e'}"></i>
            <span class="pfile-name">${eh(f.name)}</span>
            <span class="pfile-size">${(f.size/1024).toFixed(1)} KB</span>
            <button class="pfile-rm" onclick="removePendingFile(${i},'${bucket}')"><i class="fa-solid fa-xmark"></i></button>
        </div>`
    ).join('');
}

function renderSavedEmailFiles() {
    const wrap = $id('savedEmailFilesWrap');
    const list = $id('savedEmailFilesList');
    if (!_savedEmailFiles.length) { wrap.style.display='none'; return; }
    wrap.style.display = 'block';
    list.innerHTML = _savedEmailFiles.map(a=>
        `<div class="sfile sfile-email" id="semf-${a.id}">
            <i class="fa-solid fa-paperclip" style="color:#1e40af;font-size:13px;flex-shrink:0"></i>
            <a href="${eh(a.file_path)}" target="_blank" class="sfile-name">${eh(a.file_name)}</a>
            <span class="sfile-date">${(a.uploaded_at||'').slice(0,10)}</span>
            <button class="sfile-rm" onclick="deleteSavedEmailFile(${a.id})"><i class="fa-solid fa-trash" style="font-size:11px"></i></button>
        </div>`
    ).join('');
}
async function deleteSavedEmailFile(aid) {
    if (!confirm('Remove this email file?')) return;
    const fd=new FormData(); fd.append('id',aid);
    const res=await fetch('dal.php?action=delete_email_attachment',{method:'POST',body:fd});
    const d=await res.json();
    if(d.success){
        _savedEmailFiles=_savedEmailFiles.filter(a=>a.id!==aid);
        $id('semf-'+aid)?.remove();
        if(!_savedEmailFiles.length)$id('savedEmailFilesWrap').style.display='none';
        toast('Email file removed.','info');
    } else toast('Failed to remove.','error');
}

function renderSavedEvalFiles() {
    const wrap = $id('savedEvalFilesWrap');
    const list = $id('savedEvalFilesList');
    if (!_savedEvalFiles.length) { wrap.style.display='none'; return; }
    wrap.style.display = 'block';
    list.innerHTML = _savedEvalFiles.map(f=>
        `<div class="sfile sfile-eval" id="sevf-${f.id}">
            <i class="fa-solid fa-file-lines" style="color:#0f766e;font-size:13px;flex-shrink:0"></i>
            <a href="${eh(f.file_path)}" target="_blank" class="sfile-name">${eh(f.file_name)}</a>
            <span class="sfile-date">${(f.uploaded_at||'').slice(0,10)}</span>
            <button class="sfile-rm" onclick="deleteSavedEvalFile(${f.id})"><i class="fa-solid fa-trash" style="font-size:11px"></i></button>
        </div>`
    ).join('');
}
async function deleteSavedEvalFile(fid) {
    if (!confirm('Remove this evaluation file?')) return;
    const fd=new FormData(); fd.append('id',fid);
    const res=await fetch('dal.php?action=delete_eval_file',{method:'POST',body:fd});
    const d=await res.json();
    if(d.success){
        _savedEvalFiles=_savedEvalFiles.filter(f=>f.id!==fid);
        $id('sevf-'+fid)?.remove();
        if(!_savedEvalFiles.length)$id('savedEvalFilesWrap').style.display='none';
        toast('Evaluation file removed.','info');
    } else toast('Failed to remove.','error');
}

/* ════════════════════════════════════════
   EVALUATION NOTES
════════════════════════════════════════ */
let _pendingEvals = [];
let _savedEvals   = [];

function addPendingEval(){
    const txt=($id('fe_eval_new').value||'').trim();
    if(!txt){toast('Type an evaluation note first.','error');return;}
    _pendingEvals.push(txt);
    $id('fe_eval_new').value='';
    updateEvalCharCount();
    renderPendingEvals();
}
function removePendingEval(idx){_pendingEvals.splice(idx,1);renderPendingEvals();}
function renderPendingEvals(){
    $id('pendingEvalsList').innerHTML=_pendingEvals.map((t,i)=>
        `<div class="peval">
            <i class="fa-solid fa-note-sticky" style="color:#16a34a;font-size:12px;flex-shrink:0;margin-top:2px"></i>
            <div class="peval-text">${eh(t)}</div>
            <button class="peval-rm" onclick="removePendingEval(${i})"><i class="fa-solid fa-xmark"></i></button>
        </div>`
    ).join('');
}
function renderSavedEvals(){
    const wrap=$id('savedEvalsWrap');
    if(!_savedEvals.length){wrap.style.display='none';return;}
    wrap.style.display='block';
    $id('savedEvalsList').innerHTML=_savedEvals.map(ev=>
        `<div class="seval" id="seval-${ev.id}">
            <i class="fa-solid fa-note-sticky" style="color:#0f766e;font-size:12px;flex-shrink:0;margin-top:2px"></i>
            <div class="seval-text">${eh(ev.evaluation_text)}</div>
            <span class="seval-date">${(ev.created_at||'').slice(0,10)}</span>
            <button class="seval-rm" onclick="deleteSavedEval(${ev.id})"><i class="fa-solid fa-trash" style="font-size:11px"></i></button>
        </div>`
    ).join('');
}
async function deleteSavedEval(evId){
    if(!confirm('Remove this evaluation note?'))return;
    const fd=new FormData();fd.append('id',evId);
    const res=await fetch('dal.php?action=delete_evaluation',{method:'POST',body:fd});
    const d=await res.json();
    if(d.success){
        _savedEvals=_savedEvals.filter(ev=>ev.id!==evId);
        $id('seval-'+evId)?.remove();
        if(!_savedEvals.length)$id('savedEvalsWrap').style.display='none';
        toast('Note removed.','info');
    } else toast('Failed to remove.','error');
}

/* ════════════════════════════════════════
   LINE ITEMS
════════════════════════════════════════ */
let _lineCounter=0, _entryType='customer';

function setEntryType(type){
    if($id('etype-cust').disabled||$id('etype-emp').disabled)return;
    _entryType=type;
    const isCust=type==='customer';
    $id('etype-cust').className='type-btn'+(isCust?' active cust':'');
    $id('etype-emp').className='type-btn'+(!isCust?' active emp':'');
    $id('addLineBtn').className='btn btn-sm '+(isCust?'btn-success':'btn-amber');
}
function lockEntryTypeToggle(lock){
    $id('etype-cust').disabled=lock;
    $id('etype-emp').disabled=lock;
    $id('entryTypeHint').textContent=lock?'(remove all lines to change type)':'';
}
function addLine(){
    const type=_entryType, id=++_lineCounter;
    const tr=document.createElement('tr');
    tr.id='lr-'+id; tr.dataset.type=type;
    tr.innerHTML=buildLineHTML(id,type);
    $id('linesTbody').appendChild(tr);
    initLineSelect(id,type);
    lockEntryTypeToggle(true);
    recalcSummary();
}
function buildLineHTML(id,type){
    const isCust=type==='customer';
    return `<td>${isCust
        ?'<span class="type-pill-cust"><i class="fa-solid fa-user"></i> Customer</span>'
        :'<span class="type-pill-emp"><i class="fa-solid fa-id-badge"></i> Employee</span>'}</td>
    <td><select id="lsel-${id}"></select></td>
    <td class="r"><input type="number" step="0.01" min="0" class="line-net-inp" id="lnet-${id}" placeholder="0.00" oninput="onNetChange(${id})"></td>
    <td class="r"><input type="text" class="line-ro purple" id="lvat-${id}" readonly value="0.00"></td>
    <td class="r"><input type="text" class="line-ro green"  id="ltot-${id}" readonly value="0.00"></td>
    <td style="text-align:center;vertical-align:middle">
        <button class="line-del-btn" onclick="removeLine(${id})"><i class="fa-solid fa-xmark"></i></button>
    </td>`;
}
function initLineSelect(id,type,preVal,preText){
    const sel=$('#lsel-'+id);
    if(sel.hasClass('select2-hidden-accessible'))sel.select2('destroy');
    const isCust=type==='customer';
    sel.select2({
        dropdownParent:$('#entryModal'),
        placeholder:isCust?'— Search Customer —':'— Search Employee —',
        allowClear:true,minimumInputLength:0,
        ajax:{url:'dal.php',dataType:'json',delay:250,
            data:params=>({action:isCust?'search_customers':'search_employees',q:params.term||''}),
            processResults:data=>({results:data.results||[]}),cache:true}
    });
    if(preVal&&preText){sel.append(new Option(preText,preVal,true,true)).trigger('change');}
}
function onNetChange(id){
    const net=parseFloat($id('lnet-'+id).value||0)||0;
    const vat=Math.round(net*0.18*100)/100;
    $id('lvat-'+id).value=fN(vat);
    $id('ltot-'+id).value=fN(net+vat);
    recalcSummary();
}
function removeLine(id){
    $id('lr-'+id)?.remove();
    if(!document.querySelectorAll('#linesTbody tr').length)lockEntryTypeToggle(false);
    recalcSummary();
}
function recalcSummary(){
    let tn=0,tv=0;
    document.querySelectorAll('#linesTbody tr').forEach(tr=>{
        const rid=tr.id.split('-')[1];
        const net=parseFloat($id('lnet-'+rid)?.value||0)||0;
        tn+=net; tv+=Math.round(net*0.18*100)/100;
    });
    $id('ls_net').textContent=fN(tn);
    $id('ls_vat').textContent=fN(tv);
    $id('ls_tot').textContent=fN(tn+tv);
}
function collectLines(){
    const lines=[];
    document.querySelectorAll('#linesTbody tr').forEach(tr=>{
        const rid=tr.id.split('-')[1], type=tr.dataset.type||'customer';
        const sel=$('#lsel-'+rid).select2('data');
        const ch=sel&&sel.length?sel[0]:null;
        const net=parseFloat($id('lnet-'+rid)?.value||0)||0;
        if(!ch||!ch.id||net<=0)return;
        lines.push({type,ref_id:ch.id,code:ch.code||'',name:ch.name||ch.text||'',net});
    });
    return lines;
}

/* ════════════════════════════════════════
   MODAL OPEN / CLOSE / RESET
════════════════════════════════════════ */
function resetModal(){
    _pendingEmailFiles=[];_pendingEvalFiles=[];
    _savedEmailFiles=[];_savedEvalFiles=[];
    _pendingEvals=[];_savedEvals=[];
    _lineCounter=0;
    $id('fe_date').value='';$id('fe_desc').value='';$id('fe_eval_new').value='';
    updateEvalCharCount();
    $id('pendingEmailFilesList').innerHTML='';
    $id('pendingEvalFilesList').innerHTML='';
    $id('savedEmailFilesWrap').style.display='none';$id('savedEmailFilesList').innerHTML='';
    $id('savedEvalFilesWrap').style.display='none';$id('savedEvalFilesList').innerHTML='';
    $id('pendingEvalsList').innerHTML='';
    $id('savedEvalsWrap').style.display='none';$id('savedEvalsList').innerHTML='';
    $id('linesTbody').innerHTML='';
    lockEntryTypeToggle(false);setEntryType('customer');recalcSummary();
}

function openNewEntry(){
    resetModal();
    $id('modalTitle').textContent='New Entry';
    $id('fe_id').value='0';
    $id('entryModal').classList.add('open');
    document.body.style.overflow='hidden';
    addLine();
}

async function openEditEntry(id){
    resetModal();
    $id('modalTitle').textContent='Edit Entry #'+id;
    $id('fe_id').value=id;
    const res=await fetch('dal.php?action=get_entry&id='+id);
    const d=await res.json();
    if(!d.success||!d.data){toast('Could not load entry.','error');return;}

    $id('fe_date').value=d.data.email_date||'';
    $id('fe_desc').value=d.data.description||'';
    updateEvalCharCount();

    _savedEmailFiles=d.email_attachments||[];renderSavedEmailFiles();
    _savedEvalFiles=d.eval_files||[];renderSavedEvalFiles();
    _savedEvals=d.evaluations||[];renderSavedEvals();

    const linesData=d.lines||[];
    setEntryType((linesData[0]&&linesData[0].line_type)||'customer');
    for(const line of linesData){
        const lid=++_lineCounter;
        const tr=document.createElement('tr');
        tr.id='lr-'+lid;tr.dataset.type=line.line_type||'customer';
        tr.innerHTML=buildLineHTML(lid,line.line_type||'customer');
        $id('linesTbody').appendChild(tr);
        initLineSelect(lid,line.line_type||'customer',line.ref_id,'['+line.ref_code+'] '+line.ref_name);
        $id('lnet-'+lid).value=parseFloat(line.net_amount||0).toFixed(2);
        onNetChange(lid);
    }
    if(linesData.length)lockEntryTypeToggle(true); else addLine();
    $id('entryModal').classList.add('open');
    document.body.style.overflow='hidden';
}

function openEditFromView(){
    const id=parseInt($id('_viewId').value||0);
    closeView();if(id)openEditEntry(id);
}
function closeEntryModal(){
    $id('entryModal').classList.remove('open');
    document.body.style.overflow='';
}

/* ════════════════════════════════════════
   SAVE ENTRY
════════════════════════════════════════ */
async function saveEntry(){
    const id=$id('fe_id').value;
    const date=$id('fe_date').value;
    if(!date){toast('Please select an email date.','error');return;}
    const lines=collectLines();

    const btn=$id('saveEntryBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd=new FormData();
    fd.append('id',id);
    fd.append('email_date',date);
    fd.append('description',$id('fe_desc').value);
    fd.append('new_evaluations',JSON.stringify(_pendingEvals));
    fd.append('lines',JSON.stringify(lines));
    _pendingEmailFiles.forEach(f=>fd.append('email_files[]',f));
    _pendingEvalFiles.forEach(f=>fd.append('eval_files[]',f));

    try{
        const res=await fetch('dal.php?action=save_entry',{method:'POST',body:fd});
        const d=await res.json();
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Entry';
        if(!d.success){toast('Error saving entry.','error');return;}
        toast(parseInt(id)>0?'Entry updated!':'Entry created!','success');
        closeEntryModal();
        setTimeout(()=>location.reload(),700);
    }catch(e){
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Entry';
        toast('Network error: '+e.message,'error');
    }
}

/* ════════════════════════════════════════
   DELETE ENTRY
════════════════════════════════════════ */
async function deleteEntry(id){
    if(!confirm('Delete this entry along with all its lines and files?\n\nThis cannot be undone.'))return;
    const fd=new FormData();fd.append('id',id);
    const res=await fetch('dal.php?action=delete_entry',{method:'POST',body:fd});
    const d=await res.json();
    if(d.success){$id('etr-'+id)?.remove();toast('Entry deleted.','warn');}
    else toast('Delete failed.','error');
}

/* ════════════════════════════════════════
   VIEW DRAWER
════════════════════════════════════════ */
const iconMap={pdf:'fa-file-pdf',doc:'fa-file-word',docx:'fa-file-word',xls:'fa-file-excel',xlsx:'fa-file-excel',jpg:'fa-file-image',jpeg:'fa-file-image',png:'fa-file-image',gif:'fa-file-image',txt:'fa-file-lines'};
function fileIcon(path){return iconMap[(path||'').split('.').pop().toLowerCase()]||'fa-file';}

async function openView(id){
    $id('_viewId').value=id;
    $id('drwTitle').textContent='Entry #'+id;
    $id('viewDrawer').classList.add('open');
    document.body.style.overflow='hidden';

    ['drw_date','drw_net','drw_vat','drw_tot','drw_lines','drw_efiles','drw_evfiles','drw_evals'].forEach(k=>$id(k).textContent='…');
    $id('drwEmailFiles').innerHTML='<div class="no-items"><i class="fa-solid fa-spinner fa-spin"></i></div>';
    $id('drwEvalFiles').innerHTML='<div class="no-items"><i class="fa-solid fa-spinner fa-spin"></i></div>';
    $id('drwLines').innerHTML='<div class="no-items"><i class="fa-solid fa-spinner fa-spin"></i></div>';
    $id('drwDescWrap').style.display='none';
    $id('drwEvalWrap').style.display='none';

    try{
        const res=await fetch('dal.php?action=get_entry&id='+id);
        const d=await res.json();
        if(!d.success||!d.data){toast('Could not load entry.','error');return;}
        const data=d.data;

        $id('drw_date').textContent=data.email_date
            ?new Date(data.email_date+'T00:00:00').toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}):'—';
        $id('drw_net').textContent=fN(data.total_net);
        $id('drw_vat').textContent=fN(data.total_vat);
        $id('drw_tot').textContent=fN(data.total_amount);
        $id('drw_lines').textContent=(d.lines||[]).length;
        $id('drw_efiles').textContent=(d.email_attachments||[]).length;
        $id('drw_evfiles').textContent=(d.eval_files||[]).length;
        $id('drw_evals').textContent=(d.evaluations||[]).length;

        if(data.description){$id('drwDescWrap').style.display='block';$id('drwDesc').textContent=data.description;}

        // Email files
        const ea=d.email_attachments||[];
        $id('drwEmailFiles').innerHTML=ea.length
            ?ea.map(a=>`<div class="att-item att-item-email">
                <i class="fa-solid ${fileIcon(a.file_path)}" style="color:#1e40af;font-size:14px;flex-shrink:0"></i>
                <a href="${eh(a.file_path)}" target="_blank" class="att-link">${eh(a.file_name)}</a>
                <span class="att-date">${(a.uploaded_at||'').slice(0,10)}</span>
            </div>`).join('')
            :'<div class="no-items"><i class="fa-solid fa-folder-open"></i> No email files</div>';

        // Evaluation notes
        const evals=d.evaluations||[];
        if(evals.length){
            $id('drwEvalWrap').style.display='block';
            $id('drwEvalList').innerHTML=evals.map((ev,i)=>
                `<div class="eval-view-text" style="${i<evals.length-1?'margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid #99f6e4;':''}">
                    ${eh(ev.evaluation_text)}
                    <div style="font-size:10px;color:#5eead4;margin-top:4px">${(ev.created_at||'').slice(0,10)}</div>
                </div>`
            ).join('');
        }

        // Evaluation files
        const ef=d.eval_files||[];
        $id('drwEvalFiles').innerHTML=ef.length
            ?ef.map(f=>`<div class="att-item att-item-eval">
                <i class="fa-solid ${fileIcon(f.file_path)}" style="color:#0f766e;font-size:14px;flex-shrink:0"></i>
                <a href="${eh(f.file_path)}" target="_blank" class="att-link">${eh(f.file_name)}</a>
                <span class="att-date">${(f.uploaded_at||'').slice(0,10)}</span>
            </div>`).join('')
            :'<div class="no-items"><i class="fa-solid fa-folder-open"></i> No evaluation files</div>';

        // Lines
        const lw=$id('drwLines');
        if(!(d.lines||[]).length){lw.innerHTML='<div class="no-items"><i class="fa-solid fa-list-check"></i> No line items</div>';}
        else{
            let tn=0,tv=0,tt=0;
            const rows=d.lines.map(l=>{
                tn+=parseFloat(l.net_amount||0);tv+=parseFloat(l.vat_amount||0);tt+=parseFloat(l.total_amount||0);
                const pill=l.line_type==='customer'
                    ?'<span class="type-pill-cust"><i class="fa-solid fa-user"></i> Customer</span>'
                    :'<span class="type-pill-emp"><i class="fa-solid fa-id-badge"></i> Employee</span>';
                return `<tr><td>${pill}</td>
                    <td><div class="ref-name">${eh(l.ref_name)}</div><div class="ref-code">${eh(l.ref_code)}</div></td>
                    <td class="r" style="color:#0369a1;font-weight:700">${fN(l.net_amount)}</td>
                    <td class="r" style="color:#7c3aed;font-weight:700">${fN(l.vat_amount)}</td>
                    <td class="r" style="color:#16a34a;font-weight:800">${fN(l.total_amount)}</td></tr>`;
            }).join('');
            lw.innerHTML=`<table class="vlines-tbl">
                <thead><tr><th style="width:110px">Type</th><th>Name</th>
                <th class="r">Net</th><th class="r">VAT 18%</th><th class="r">Total</th></tr></thead>
                <tbody>${rows}</tbody>
                <tfoot><tr><td colspan="2" style="font-size:11px;color:#64748b">Totals</td>
                <td class="r" style="color:#0369a1">${fN(tn)}</td>
                <td class="r" style="color:#7c3aed">${fN(tv)}</td>
                <td class="r" style="color:#16a34a">${fN(tt)}</td></tr></tfoot>
            </table>`;
        }
    }catch(e){toast('Load error: '+e.message,'error');}
}
function closeView(){$id('viewDrawer').classList.remove('open');document.body.style.overflow='';}

/* ════════════════════════════════════════
   SETTLEMENT
════════════════════════════════════════ */
let _stlEntry=null,_stlPending=[],_stlAcks=[],_stlSettled=[];

function openSettle(id,totalAmount,emailDate){
    _stlEntry={id,total_amount:parseFloat(totalAmount||0),email_date:emailDate};
    $id('stl_entry_id').value=id;
    $id('settleTitle').textContent='Manage Claim Settlement — Entry #'+id;
    $id('stl_date').textContent=emailDate
        ?new Date(emailDate+'T00:00:00').toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}):'—';
    $id('stl_target').textContent=fN(_stlEntry.total_amount);
    $id('stl_settled').textContent='0.00';
    $id('stl_remaining').textContent=fN(_stlEntry.total_amount);
    $id('stlSearch').value='';
    $id('settleModal').classList.add('open');
    document.body.style.overflow='hidden';
    loadSettleData();
}
function closeSettleModal(){$id('settleModal').classList.remove('open');document.body.style.overflow='';_stlEntry=null;}

async function loadSettleData(){
    if(!_stlEntry)return;
    $id('stlPendingWrap').innerHTML='<div class="stl-empty"><i class="fa-solid fa-spinner fa-spin"></i> Loading pending claims…</div>';
    $id('stlAckWrap').innerHTML='<div class="stl-empty"><i class="fa-solid fa-spinner fa-spin"></i> Loading cheque acknowledgments…</div>';
    $id('stlSettledWrap').innerHTML='<div class="stl-empty"><i class="fa-solid fa-spinner fa-spin"></i> Loading settled items…</div>';
    const params=new URLSearchParams({action:'get_settle_data',entry_id:_stlEntry.id,search:$id('stlSearch').value||''});
    try{
        const res=await fetch('dal.php?'+params.toString());
        const d=await res.json();
        if(!d.success)throw new Error(d.message||'Failed to load settlement data.');
        _stlPending=d.pending||[];_stlAcks=d.cheque_acks||[];_stlSettled=d.settled||[];
        $id('stl_settled').textContent=fN(d.settled_total||0);
        $id('stl_remaining').textContent=fN((_stlEntry.total_amount||0)-(d.settled_total||0));
        renderStlSettled();
        renderStlTable('stlPendingWrap',_stlPending,'claim_item');
        renderStlTable('stlAckWrap',_stlAcks,'cheque_ack');
        updateStlSelInfo();
    }catch(e){
        $id('stlPendingWrap').innerHTML='<div class="stl-empty">Error: '+eh(e.message)+'</div>';
        $id('stlAckWrap').innerHTML='';$id('stlSettledWrap').innerHTML='';
    }
}
function renderStlSettled(){
    const wrap=$id('stlSettledWrap');
    if(!_stlSettled.length){wrap.innerHTML='<div class="stl-empty"><i class="fa-solid fa-inbox"></i> No items settled yet.</div>';return;}
    wrap.innerHTML=_stlSettled.map(s=>{
        const tl=s.source_type==='cheque_ack'?'Cheque Ack':'Pending Claim';
        return `<div class="stl-settled-item" id="stlset-${s.id}">
            <i class="fa-solid fa-link" style="color:#1e40af"></i>
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:12.5px;color:#1e293b">${eh(s.reference_no||'—')} <span style="font-size:10px;color:#94a3b8;font-weight:600">(${tl})</span></div>
                <div style="font-size:11px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${eh((s.description||'').slice(0,90))}${s.entity?' — '+eh(s.entity):''}</div>
            </div>
            <div style="font-weight:800;color:#16a34a;font-size:13px;white-space:nowrap">${fN(s.amount)}</div>
            <button class="stl-settled-rm" onclick="removeSettlement(${s.id})"><i class="fa-solid fa-link-slash"></i> Remove</button>
        </div>`;
    }).join('');
}
function renderStlTable(wrapId,rows,sourceType){
    const wrap=$id(wrapId);
    if(!rows.length){wrap.innerHTML='<div class="stl-empty"><i class="fa-solid fa-inbox"></i> No records found.</div>';return;}
    const target=_stlEntry?_stlEntry.total_amount:0;
    const rowsHtml=rows.map(r=>{
        const amt=parseFloat(r.total_amount||0);
        const isMatch=target>0&&Math.abs(amt-target)<0.01;
        return `<tr class="${isMatch?'stl-match':''}">
            <td style="width:32px;text-align:center"><input type="checkbox" class="stl-chk" data-type="${sourceType}" data-id="${r.id}" data-amt="${amt}" onchange="updateStlSelInfo()"></td>
            <td style="font-family:monospace;font-weight:700;color:#312e81;white-space:nowrap">${eh(r.tax_invoice_no||'—')}${isMatch?'<span class="stl-match-tag">Match</span>':''}</td>
            <td style="white-space:nowrap">${r.invoice_date||'—'}</td>
            <td><span class="tag tag-blue" style="font-size:10px">${eh(r.entity||'—')}</span></td>
            <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${eh(r.claim_description||'')}">${eh((r.claim_description||'').slice(0,70))}</td>
            <td style="font-size:10px;color:#94a3b8;white-space:nowrap">${eh(r.claim_type||'—')}</td>
            <td class="r" style="font-weight:800;color:${isMatch?'#16a34a':'#1e293b'};white-space:nowrap">${fN(amt)}</td>
        </tr>`;
    }).join('');
    wrap.innerHTML=`<div class="stl-tbl-scroll"><table class="stl-tbl">
        <thead><tr>
            <th style="width:32px"><input type="checkbox" onchange="toggleAllStl(this,'${wrapId}')"></th>
            <th>Invoice / Ref No</th><th>Date</th><th>Entity</th><th>Description</th><th>Claim Type</th><th class="r">Amount</th>
        </tr></thead>
        <tbody>${rowsHtml}</tbody>
    </table></div>`;
}
function toggleAllStl(cb,wrapId){
    document.querySelectorAll('#'+wrapId+' .stl-chk').forEach(c=>c.checked=cb.checked);
    updateStlSelInfo();
}
function updateStlSelInfo(){
    const checked=document.querySelectorAll('#settleModal .stl-chk:checked');
    let sum=0;checked.forEach(c=>sum+=parseFloat(c.dataset.amt||0));
    $id('stlSelInfo').textContent=checked.length+' item(s) selected — '+fN(sum)+' total';
}
async function addSelectedSettlements(){
    if(!_stlEntry)return;
    const checked=document.querySelectorAll('#settleModal .stl-chk:checked');
    if(!checked.length){toast('Select at least one item to settle.','error');return;}
    const items=Array.from(checked).map(c=>({source_type:c.dataset.type,source_id:parseInt(c.dataset.id)}));
    const btn=$id('stlAddBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Adding…';
    const fd=new FormData();fd.append('entry_id',_stlEntry.id);fd.append('items',JSON.stringify(items));
    try{
        const res=await fetch('dal.php?action=add_settlement',{method:'POST',body:fd});
        const d=await res.json();
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-link"></i> Add Selected as Settled';
        if(!d.success){toast(d.message||'Failed to add settlement.','error');return;}
        toast(`${d.inserted} item(s) settled.${d.skipped?' '+d.skipped+' skipped (already settled).':''}`, 'success');
        updateRowSettledBadge(_stlEntry.id,d.settled_total,_stlEntry.total_amount);
        loadSettleData();
    }catch(e){
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-link"></i> Add Selected as Settled';
        toast('Network error: '+e.message,'error');
    }
}
async function removeSettlement(settlementId){
    if(!confirm('Remove this settled item?'))return;
    const fd=new FormData();fd.append('id',settlementId);
    try{
        const res=await fetch('dal.php?action=delete_settlement',{method:'POST',body:fd});
        const d=await res.json();
        if(!d.success){toast(d.message||'Failed to remove.','error');return;}
        toast('Settlement removed.','warn');
        if(_stlEntry)updateRowSettledBadge(_stlEntry.id,d.settled_total,_stlEntry.total_amount);
        loadSettleData();
    }catch(e){toast('Network error: '+e.message,'error');}
}
function updateRowSettledBadge(entryId,settledTotal,targetTotal){
    const cell=$id('td-settled-'+entryId);if(!cell)return;
    settledTotal=parseFloat(settledTotal||0);targetTotal=parseFloat(targetTotal||0);
    if(settledTotal<=0){cell.innerHTML='<span class="settled-badge none">Not Settled</span>';return;}
    if(targetTotal>0&&Math.abs(settledTotal-targetTotal)<0.01){cell.innerHTML=`<span class="settled-badge full">Fully Settled<br>${fN(settledTotal)}</span>`;return;}
    cell.innerHTML=`<span class="settled-badge partial">Partial<br>${fN(settledTotal)} / ${fN(targetTotal)}</span>`;
}

$id('viewDrawer').addEventListener('click',e=>{if(e.target===$id('viewDrawer'))closeView();});
$id('settleModal').addEventListener('click',e=>{if(e.target===$id('settleModal'))closeSettleModal();});
</script>

<?php include 'footer.php'; ?>
