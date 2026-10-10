<?php
include 'config.php';

// ══════════════════════════════════════════════════════════════════
//  TABLE SETUP
// ══════════════════════════════════════════════════════════════════
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sscl_vat_email_entries (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    email_date      DATE NULL,
    description     TEXT NULL,
    total_net       DECIMAL(14,4) DEFAULT 0,
    total_vat       DECIMAL(14,4) DEFAULT 0,
    total_amount    DECIMAL(14,4) DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sscl_vat_email_attachments (
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
    note            VARCHAR(500) NULL,
    sort_order      INT DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entry (entry_id)
)");

// Auto-migrate older columns if missing
foreach (['description'] as $col) {
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM sscl_vat_email_entries LIKE '$col'");
    if ($chk && mysqli_num_rows($chk) === 0) {
        @mysqli_query($conn, "ALTER TABLE sscl_vat_email_entries ADD COLUMN description TEXT NULL AFTER email_date");
    }
}

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
        total_net=" . floatval($row['tn']) . ",
        total_vat=" . floatval($row['tv']) . ",
        total_amount=" . floatval($row['tt']) . "
        WHERE id=" . intval($entry_id));
    return $row;
}

// ══════════════════════════════════════════════════════════════════
//  AJAX HANDLERS
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // ── Search customers ──
    if ($action === 'search_customers') {
        $q = mysqli_real_escape_string($conn, trim($_GET['q'] ?? ''));
        $sql = "SELECT id, t_code, shop_name FROM customers WHERE active=1";
        if ($q) $sql .= " AND (t_code LIKE '%$q%' OR shop_name LIKE '%$q%')";
        $sql .= " ORDER BY shop_name ASC LIMIT 60";
        $res = mysqli_query($conn, $sql);
        $rows = [];
        while ($r = mysqli_fetch_assoc($res))
            $rows[] = ['id' => $r['id'], 'text' => '[' . $r['t_code'] . '] ' . $r['shop_name'],
                       'code' => $r['t_code'], 'name' => $r['shop_name']];
        echo json_encode(['results' => $rows]);
        exit;
    }

    // ── Search employees ──
    if ($action === 'search_employees') {
        $q = mysqli_real_escape_string($conn, trim($_GET['q'] ?? ''));
        $sql = "SELECT id, employee_id, employee_full_name FROM employees WHERE active=1";
        if ($q) $sql .= " AND (employee_id LIKE '%$q%' OR employee_full_name LIKE '%$q%')";
        $sql .= " ORDER BY employee_full_name ASC LIMIT 60";
        $res = mysqli_query($conn, $sql);
        $rows = [];
        while ($r = mysqli_fetch_assoc($res))
            $rows[] = ['id' => $r['id'], 'text' => '[' . $r['employee_id'] . '] ' . $r['employee_full_name'],
                       'code' => $r['employee_id'], 'name' => $r['employee_full_name']];
        echo json_encode(['results' => $rows]);
        exit;
    }

    // ── Save entry (create / update) ──
    if ($action === 'save_entry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id          = intval($_POST['id'] ?? 0);
        $email_date  = mysqli_real_escape_string($conn, trim($_POST['email_date'] ?? ''));
        $description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
        $ed_sql      = $email_date ? "'$email_date'" : 'NULL';

        if ($id > 0) {
            mysqli_query($conn, "UPDATE sscl_vat_email_entries SET email_date=$ed_sql, description='$description' WHERE id=$id");
        } else {
            mysqli_query($conn, "INSERT INTO sscl_vat_email_entries (email_date,description,total_net,total_vat,total_amount) VALUES ($ed_sql,'$description',0,0,0)");
            $id = mysqli_insert_id($conn);
        }

        // Handle file uploads
        $uploaded = [];
        if (!empty($_FILES['attachments']['name'][0])) {
            $dir = 'uploads/sscl_vat_emails/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png'];
            $names = $_FILES['attachments']['name'];
            $tmps  = $_FILES['attachments']['tmp_name'];
            $errs  = $_FILES['attachments']['error'];
            for ($i = 0; $i < count($names); $i++) {
                if ($errs[$i] !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed)) continue;
                $safe = uniqid('sve_', true) . '.' . $ext;
                if (move_uploaded_file($tmps[$i], $dir . $safe)) {
                    $fp = mysqli_real_escape_string($conn, $dir . $safe);
                    $fn = mysqli_real_escape_string($conn, $names[$i]);
                    mysqli_query($conn, "INSERT INTO sscl_vat_email_attachments (entry_id,file_path,file_name) VALUES ($id,'$fp','$fn')");
                    $uploaded[] = ['id' => mysqli_insert_id($conn), 'path' => $dir . $safe, 'name' => $names[$i]];
                }
            }
        }

        // Save lines (replace all)
        $lines_json = $_POST['lines'] ?? '[]';
        $lines      = json_decode($lines_json, true);
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
                $note   = mysqli_real_escape_string($conn, trim($line['note'] ?? ''));
                $sort++;
                mysqli_query($conn, "INSERT INTO sscl_vat_email_lines
                    (entry_id,line_type,ref_id,ref_code,ref_name,net_amount,vat_amount,total_amount,note,sort_order)
                    VALUES ($id,'$ltype',$ref_id,'$code','$name',$net,$vat,$tot,'$note',$sort)");
            }
        }

        $totals = recalc_entry_totals($conn, $id);
        echo json_encode(['success' => true, 'id' => $id, 'totals' => $totals, 'uploaded' => $uploaded]);
        exit;
    }

    // ── Get entry details ──
    if ($action === 'get_entry') {
        $id  = intval($_GET['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sscl_vat_email_entries WHERE id=$id"));
        $attachments = [];
        $ar = mysqli_query($conn, "SELECT * FROM sscl_vat_email_attachments WHERE entry_id=$id ORDER BY id");
        while ($a = mysqli_fetch_assoc($ar)) $attachments[] = $a;
        $lines = [];
        $lr = mysqli_query($conn, "SELECT * FROM sscl_vat_email_lines WHERE entry_id=$id ORDER BY sort_order, id");
        while ($l = mysqli_fetch_assoc($lr)) $lines[] = $l;
        echo json_encode(['success' => (bool)$row, 'data' => $row, 'attachments' => $attachments, 'lines' => $lines]);
        exit;
    }

    // ── Delete attachment ──
    if ($action === 'delete_attachment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $aid = intval($_POST['id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sscl_vat_email_attachments WHERE id=$aid"));
        if ($row && !empty($row['file_path']) && file_exists($row['file_path'])) @unlink($row['file_path']);
        $ok = mysqli_query($conn, "DELETE FROM sscl_vat_email_attachments WHERE id=$aid");
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    // ── Delete entry ──
    if ($action === 'delete_entry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id  = intval($_POST['id'] ?? 0);
        $ar = mysqli_query($conn, "SELECT file_path FROM sscl_vat_email_attachments WHERE entry_id=$id");
        while ($a = mysqli_fetch_assoc($ar)) {
            if (!empty($a['file_path']) && file_exists($a['file_path'])) @unlink($a['file_path']);
        }
        mysqli_query($conn, "DELETE FROM sscl_vat_email_attachments WHERE entry_id=$id");
        mysqli_query($conn, "DELETE FROM sscl_vat_email_lines WHERE entry_id=$id");
        $ok = mysqli_query($conn, "DELETE FROM sscl_vat_email_entries WHERE id=$id");
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    // ── Upload more attachments to existing entry ──
    if ($action === 'upload_attachments' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['entry_id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false]); exit; }
        $dir = 'uploads/sscl_vat_emails/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png'];
        $uploaded = [];
        if (!empty($_FILES['files']['name'][0])) {
            $names = $_FILES['files']['name'];
            $tmps  = $_FILES['files']['tmp_name'];
            $errs  = $_FILES['files']['error'];
            for ($i = 0; $i < count($names); $i++) {
                if ($errs[$i] !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed)) continue;
                $safe = uniqid('sve_', true) . '.' . $ext;
                if (move_uploaded_file($tmps[$i], $dir . $safe)) {
                    $fp = mysqli_real_escape_string($conn, $dir . $safe);
                    $fn = mysqli_real_escape_string($conn, $names[$i]);
                    mysqli_query($conn, "INSERT INTO sscl_vat_email_attachments (entry_id,file_path,file_name) VALUES ($id,'$fp','$fn')");
                    $uploaded[] = ['id' => mysqli_insert_id($conn), 'path' => $dir . $safe, 'name' => $names[$i]];
                }
            }
        }
        echo json_encode(['success' => true, 'uploaded' => $uploaded]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  PAGE LOAD — fetch entries list
// ══════════════════════════════════════════════════════════════════
$filter_from = mysqli_real_escape_string($conn, $_GET['filter_from'] ?? '');
$filter_to   = mysqli_real_escape_string($conn, $_GET['filter_to']   ?? '');

$where = [];
if ($filter_from) $where[] = "e.email_date >= '$filter_from'";
if ($filter_to)   $where[] = "e.email_date <= '$filter_to'";
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$entries = [];
$res = mysqli_query($conn,
    "SELECT e.*,
            (SELECT COUNT(*) FROM sscl_vat_email_attachments a WHERE a.entry_id=e.id) AS attach_count,
            (SELECT COUNT(*) FROM sscl_vat_email_lines l WHERE l.entry_id=e.id) AS line_count
     FROM sscl_vat_email_entries e $where_sql
     ORDER BY e.email_date DESC, e.id DESC"
);
if ($res) while ($r = mysqli_fetch_assoc($res)) $entries[] = $r;

$totals_row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as cnt,
            COALESCE(SUM(total_net),0) as sum_net,
            COALESCE(SUM(total_vat),0) as sum_vat,
            COALESCE(SUM(total_amount),0) as sum_total
     FROM sscl_vat_email_entries e $where_sql"
));

include 'header.php';
?>

<!------------------ EXTERNAL LIBS -------------------------------->
<link  href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
/* ═══════════════════════════════════════
   BASE / RESET
═══════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif}

/* ═══════════════════════════════════════
   PAGE HEADER
═══════════════════════════════════════ */
.sve-page-header{
    display:flex;justify-content:space-between;align-items:center;
    flex-wrap:wrap;gap:14px;margin-bottom:24px;
}
.sve-page-title{font-size:24px;font-weight:800;color:#0f172a;margin:0;
    display:flex;align-items:center;gap:10px}
.sve-page-title i{color:#0369a1}
.sve-page-sub{font-size:13px;color:#64748b;margin:3px 0 0}

/* ═══════════════════════════════════════
   SUMMARY STRIP
═══════════════════════════════════════ */
.sve-summary{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
    gap:12px;margin-bottom:22px;
}
.sve-sum-card{
    background:#fff;border:1px solid #e2e8f0;border-radius:10px;
    padding:16px 18px;
}
.sve-sum-lbl{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;
    letter-spacing:.6px;margin-bottom:6px}
.sve-sum-val{font-size:20px;font-weight:800;color:#0f172a}
.sve-sum-sub{font-size:11px;color:#cbd5e1;margin-top:3px}

/* ═══════════════════════════════════════
   FILTER BAR
═══════════════════════════════════════ */
.sve-filter{
    display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;
    background:#fff;border:1px solid #e2e8f0;border-radius:10px;
    padding:14px 18px;margin-bottom:18px;
}
.sve-fg{display:flex;flex-direction:column;gap:4px}
.sve-fl{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
.sve-fi{padding:8px 12px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;
    font-family:inherit;outline:none;height:36px;background:#fff}
.sve-fi:focus{border-color:#0369a1;box-shadow:0 0 0 2px rgba(3,105,161,.1)}

/* ═══════════════════════════════════════
   BUTTONS
═══════════════════════════════════════ */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 20px;border:none;
    border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;
    font-family:inherit;white-space:nowrap;transition:all .18s;text-decoration:none}
.btn-sm{padding:7px 14px;font-size:12px}
.btn-xs{padding:4px 10px;font-size:11px}
.btn-primary{background:#0369a1;color:#fff}.btn-primary:hover{background:#0284c7}
.btn-dark{background:#0f172a;color:#fff}.btn-dark:hover{background:#1e293b}
.btn-light{background:#f8fafc;color:#374151;border:1px solid #e2e8f0}
.btn-light:hover{background:#f1f5f9}
.btn-danger{background:#fef2f2;color:#dc2626;border:1px solid #fca5a5}
.btn-danger:hover{background:#dc2626;color:#fff}
.btn-success{background:#f0fdf4;color:#16a34a;border:1px solid #86efac}
.btn-success:hover{background:#16a34a;color:#fff}
.btn-amber{background:#fffbeb;color:#d97706;border:1px solid #fde68a}
.btn-amber:hover{background:#d97706;color:#fff}
.btn-new{
    background:linear-gradient(135deg,#0369a1,#0284c7);
    color:#fff;box-shadow:0 2px 8px rgba(3,105,161,.3);
    padding:10px 22px;font-size:14px;
}
.btn-new:hover{filter:brightness(1.08);transform:translateY(-1px);
    box-shadow:0 4px 14px rgba(3,105,161,.35)}

/* ═══════════════════════════════════════
   TABLE
═══════════════════════════════════════ */
.sve-table-card{
    background:#fff;border:1px solid #e2e8f0;border-radius:12px;
    overflow:hidden;
}
.sve-table-header{
    display:flex;justify-content:space-between;align-items:center;
    padding:16px 20px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:10px;
}
.sve-table-title{font-size:14px;font-weight:700;color:#0f172a;
    display:flex;align-items:center;gap:8px}
.sve-count-badge{background:#f1f5f9;color:#475569;font-size:11px;font-weight:700;
    padding:2px 9px;border-radius:10px}

.sve-search-wrap{position:relative}
.sve-search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);
    color:#94a3b8;font-size:12px;pointer-events:none}
.sve-search{width:220px;padding:7px 12px 7px 30px;border:1px solid #e2e8f0;
    border-radius:7px;font-size:13px;font-family:inherit;outline:none}
.sve-search:focus{border-color:#0369a1}

.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead th{
    background:#f8fafc;padding:10px 14px;text-align:left;
    font-weight:700;font-size:11px;color:#475569;text-transform:uppercase;
    letter-spacing:.4px;white-space:nowrap;border-bottom:2px solid #e2e8f0;
}
.data-table th.num,.data-table td.num{text-align:right}
.data-table tbody tr{border-bottom:1px solid #f8fafc;transition:background .1s}
.data-table tbody tr:hover{background:#f8fafc}
.data-table td{padding:11px 14px;color:#1e293b;vertical-align:middle;white-space:nowrap}
.data-table tfoot td{
    padding:11px 14px;font-weight:800;color:#0f172a;
    background:#f0f9ff;border-top:2px solid #bae6fd;font-size:13px;
}
.empty-state{text-align:center;padding:60px 20px;color:#94a3b8}
.empty-state i{font-size:40px;display:block;margin-bottom:14px;color:#cbd5e1}
.empty-state p{font-size:13px;margin:0}

/* Tags & badges */
.tag{display:inline-flex;align-items:center;gap:3px;padding:2px 9px;border-radius:10px;
    font-size:10px;font-weight:700}
.tag-blue{background:#dbeafe;color:#1e40af}
.tag-green{background:#dcfce7;color:#166534}
.tag-amber{background:#fef3c7;color:#92400e}
.tag-gray{background:#f3f4f6;color:#374151}
.date-badge{
    display:inline-flex;align-items:center;gap:5px;
    font-size:12px;font-weight:600;color:#1e293b;
}
.amt-net{color:#0369a1;font-weight:700}
.amt-vat{color:#7c3aed;font-weight:700}
.amt-tot{color:#16a34a;font-weight:800;font-size:14px}

/* Action buttons */
.action-btns{display:flex;gap:5px;align-items:center}
.abtn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;
    border-radius:6px;border:1px solid #e2e8f0;background:#fff;
    color:#6b7280;cursor:pointer;font-size:12px;transition:all .18s}
.abtn:hover{transform:translateY(-1px);box-shadow:0 2px 4px rgba(0,0,0,.08)}
.abtn-view:hover{background:#3b82f6;color:#fff;border-color:#3b82f6}
.abtn-edit:hover{background:#0369a1;color:#fff;border-color:#0369a1}
.abtn-del:hover{background:#ef4444;color:#fff;border-color:#ef4444}

/* ═══════════════════════════════════════
   MODAL — ENTRY
═══════════════════════════════════════ */
.mo{
    position:fixed;inset:0;background:rgba(15,23,42,.6);
    z-index:9000;display:none;align-items:flex-start;
    justify-content:center;padding:20px;overflow-y:auto;
    backdrop-filter:blur(2px);
}
.mo.open{display:flex;animation:moFade .2s ease}
@keyframes moFade{from{opacity:0}to{opacity:1}}
.mo-box{
    background:#fff;border-radius:16px;width:100%;max-width:860px;
    box-shadow:0 24px 80px rgba(0,0,0,.22);overflow:hidden;
    animation:moSlide .25s ease;margin:auto;
}
@keyframes moSlide{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}

.mo-hdr{
    background:linear-gradient(135deg,#0c4a6e,#0369a1,#0284c7);
    padding:20px 26px;display:flex;align-items:center;justify-content:space-between;
}
.mo-hdr-title{color:#fff;font-size:17px;font-weight:800;display:flex;align-items:center;gap:10px}
.mo-hdr-title i{opacity:.8}
.mo-close{
    background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);
    color:#fff;border-radius:8px;padding:7px 16px;font-size:13px;font-weight:700;
    cursor:pointer;font-family:inherit;transition:background .2s;
}
.mo-close:hover{background:rgba(255,255,255,.25)}

.mo-body{padding:24px 28px;max-height:calc(100vh - 160px);overflow-y:auto}
.mo-footer{
    padding:16px 28px;border-top:1px solid #f1f5f9;
    display:flex;justify-content:flex-end;gap:10px;
    background:#fafafa;
}

/* ── Form groups ── */
.fgrp{display:flex;flex-direction:column;gap:5px;margin-bottom:18px}
.flbl{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px}
.flbl .opt{font-size:10px;font-weight:400;color:#94a3b8;text-transform:none;letter-spacing:0}
.finp,.fsel{
    padding:10px 13px;border:1px solid #d1d5db;border-radius:8px;
    font-size:14px;font-family:inherit;outline:none;width:100%;background:#fff;
}
.finp:focus,.fsel:focus{border-color:#0369a1;box-shadow:0 0 0 3px rgba(3,105,161,.1)}
textarea.finp{resize:vertical;min-height:60px}

.form-row2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}

/* ── Section divider ── */
.sec-divider{
    display:flex;align-items:center;gap:12px;margin:22px 0 16px;
}
.sec-divider-line{flex:1;height:1px;background:#e2e8f0}
.sec-divider-label{
    font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;
    letter-spacing:.6px;white-space:nowrap;
}

/* ── File upload zone ── */
.file-zone{
    border:2px dashed #bfdbfe;border-radius:10px;padding:20px;
    text-align:center;cursor:pointer;background:#f0f9ff;
    position:relative;transition:all .2s;
}
.file-zone:hover,.file-zone.drag{border-color:#0369a1;background:#e0f2fe}
.file-zone input[type=file]{
    position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;
}
.file-zone-icon{font-size:26px;color:#7dd3fc;display:block;margin-bottom:6px}
.file-zone-text{font-size:13px;color:#475569;font-weight:500}
.file-zone-sub{font-size:11px;color:#94a3b8;margin-top:3px}

/* Pending files list */
.pending-files{display:flex;flex-direction:column;gap:6px;margin-top:10px}
.pfile{
    display:flex;align-items:center;gap:8px;background:#f0fdf4;
    border:1px solid #86efac;border-radius:7px;padding:7px 12px;
}
.pfile-name{flex:1;font-size:12px;font-weight:600;color:#166534;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pfile-size{font-size:11px;color:#94a3b8;flex-shrink:0}
.pfile-rm{
    background:none;border:none;cursor:pointer;color:#dc2626;
    font-size:13px;padding:2px 4px;line-height:1;flex-shrink:0;
}

/* Saved attachments */
.saved-files{display:flex;flex-direction:column;gap:6px;margin-top:8px}
.sfile{
    display:flex;align-items:center;gap:8px;background:#eff6ff;
    border:1px solid #bfdbfe;border-radius:7px;padding:7px 12px;
}
.sfile-name{
    flex:1;font-size:12px;font-weight:600;color:#1e40af;
    text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.sfile-name:hover{text-decoration:underline}
.sfile-rm{
    background:none;border:none;cursor:pointer;color:#dc2626;
    font-size:12px;padding:2px 5px;line-height:1;flex-shrink:0;
}
.sfile-date{font-size:10px;color:#94a3b8;flex-shrink:0}

/* ─────────────────────────────────────
   LINES TABLE (inside modal)
───────────────────────────────────── */
.lines-wrap{border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:14px}
.lines-tbl{width:100%;border-collapse:collapse;font-size:13px}
.lines-tbl thead th{
    background:#f8fafc;padding:9px 12px;text-align:left;
    font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;
    letter-spacing:.4px;border-bottom:1px solid #e2e8f0;
}
.lines-tbl th.r,.lines-tbl td.r{text-align:right}
.lines-tbl tbody tr{border-bottom:1px solid #f1f5f9}
.lines-tbl tbody tr:last-child{border-bottom:none}
.lines-tbl td{padding:8px 10px;vertical-align:top}

/* Type toggle pill */
.type-toggle{
    display:inline-flex;border:1px solid #e2e8f0;border-radius:7px;overflow:hidden;
}
.type-btn{
    padding:5px 12px;font-size:11px;font-weight:700;cursor:pointer;
    border:none;background:#fff;color:#64748b;font-family:inherit;
    transition:all .15s;
}
.type-btn.active.cust{background:#dbeafe;color:#1e40af}
.type-btn.active.emp{background:#fef3c7;color:#92400e}

/* Select2 inline in table */
.lines-tbl .select2-container{min-width:180px !important}
.lines-tbl .select2-container--default .select2-selection--single{
    height:34px;border:1px solid #d1d5db;border-radius:6px;
    display:flex;align-items:center;padding:0 10px;
}
.lines-tbl .select2-container--default .select2-selection--single .select2-selection__rendered{
    line-height:32px;font-size:13px;font-family:inherit;color:#1e293b;padding:0;
}
.lines-tbl .select2-container--default .select2-selection--single .select2-selection__arrow{
    height:32px;right:6px;
}
.lines-tbl .select2-container--default.select2-container--focus .select2-selection--single,
.lines-tbl .select2-container--default.select2-container--open .select2-selection--single{
    border-color:#0369a1;box-shadow:0 0 0 2px rgba(3,105,161,.12);
}

/* Amount inputs in line */
.line-net-inp{
    width:110px;padding:6px 9px;border:1px solid #bfdbfe;border-radius:6px;
    font-size:13px;font-family:inherit;font-weight:700;color:#0369a1;
    background:#f0f9ff;outline:none;text-align:right;
}
.line-net-inp:focus{border-color:#0369a1;box-shadow:0 0 0 2px rgba(3,105,161,.1)}
.line-ro{
    width:100px;padding:6px 9px;border:1px solid #e2e8f0;border-radius:6px;
    font-size:13px;font-family:inherit;font-weight:700;
    background:#f8fafc;color:#475569;outline:none;text-align:right;
}
.line-ro.green{color:#16a34a;background:#f0fdf4;border-color:#bbf7d0}
.line-ro.purple{color:#7c3aed;background:#faf5ff;border-color:#e9d5ff}
.note-inp{
    width:130px;padding:6px 9px;border:1px solid #d1d5db;border-radius:6px;
    font-size:12px;font-family:inherit;color:#374151;outline:none;
}
.note-inp:focus{border-color:#0369a1}
.line-del-btn{
    background:none;border:1px solid #fca5a5;border-radius:6px;
    color:#dc2626;cursor:pointer;padding:5px 9px;font-size:11px;
    display:inline-flex;align-items:center;gap:3px;font-family:inherit;
    transition:all .15s;
}
.line-del-btn:hover{background:#fef2f2}

/* Lines summary row */
.lines-summary{
    display:flex;gap:20px;padding:12px 16px;background:#f0f9ff;
    border-top:2px solid #bae6fd;border-radius:0 0 10px 10px;flex-wrap:wrap;
}
.ls-item{display:flex;flex-direction:column;gap:2px}
.ls-lbl{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.4px;font-weight:600}
.ls-val{font-size:16px;font-weight:800}
.ls-val.net{color:#0369a1}.ls-val.vat{color:#7c3aed}.ls-val.tot{color:#16a34a}

/* Add line button row */
.add-line-row{
    display:flex;gap:8px;padding:12px 14px;background:#fafafa;
    border-top:1px solid #f1f5f9;
}

/* ═══════════════════════════════════════
   VIEW DRAWER
═══════════════════════════════════════ */
.drw{
    position:fixed;inset:0;background:rgba(15,23,42,.55);
    z-index:9500;display:none;align-items:flex-start;
    justify-content:center;padding:20px;overflow-y:auto;
}
.drw.open{display:flex;animation:moFade .2s ease}
.drw-box{
    background:#fff;border-radius:16px;width:100%;max-width:900px;
    box-shadow:0 24px 80px rgba(0,0,0,.25);overflow:hidden;
    animation:moSlide .25s ease;margin:auto;
}
.drw-hdr{
    background:linear-gradient(135deg,#064e3b,#065f46,#047857);
    padding:20px 26px;display:flex;align-items:center;justify-content:space-between;
}
.drw-hdr-title{color:#fff;font-size:16px;font-weight:800;display:flex;align-items:center;gap:10px}
.drw-close{
    background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);
    color:#fff;border-radius:8px;padding:7px 16px;font-size:13px;font-weight:700;
    cursor:pointer;font-family:inherit;transition:background .2s;
}
.drw-close:hover{background:rgba(255,255,255,.25)}

.drw-strip{
    display:flex;flex-wrap:wrap;background:#f8fafc;border-bottom:2px solid #e2e8f0;
}
.drw-strip-cell{
    display:flex;flex-direction:column;gap:2px;
    padding:13px 20px;border-right:1px solid #e2e8f0;min-width:110px;
}
.drw-strip-cell:last-child{border-right:none}
.ds-lbl{font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;font-weight:600}
.ds-val{font-size:15px;font-weight:800;color:#0f172a}
.ds-val.sky{color:#0369a1}.ds-val.purple{color:#7c3aed}.ds-val.green{color:#16a34a}

.drw-body{padding:22px 26px}

/* Lines view table */
.vlines-tbl{width:100%;border-collapse:collapse;font-size:13px;margin-top:12px}
.vlines-tbl thead th{
    background:#f0fdf4;padding:9px 12px;text-align:left;
    font-size:11px;font-weight:700;color:#166534;text-transform:uppercase;
    letter-spacing:.4px;border-bottom:2px solid #bbf7d0;
}
.vlines-tbl th.r,.vlines-tbl td.r{text-align:right}
.vlines-tbl tbody tr{border-bottom:1px solid #f1f5f9}
.vlines-tbl tbody tr:last-child{border-bottom:none}
.vlines-tbl td{padding:10px 12px;vertical-align:middle}
.vlines-tbl tfoot td{
    padding:10px 12px;font-weight:800;background:#f0fdf4;
    border-top:2px solid #bbf7d0;color:#0f172a;
}

.type-pill-cust{
    display:inline-flex;align-items:center;gap:4px;
    background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;
    border-radius:5px;padding:2px 9px;font-size:10px;font-weight:700;
}
.type-pill-emp{
    display:inline-flex;align-items:center;gap:4px;
    background:#fef3c7;color:#92400e;border:1px solid #fde68a;
    border-radius:5px;padding:2px 9px;font-size:10px;font-weight:700;
}
.ref-name{font-weight:600;color:#1e293b}
.ref-code{font-size:10px;color:#94a3b8;font-family:monospace}

/* Attachments list in drawer */
.att-grid{display:flex;flex-direction:column;gap:6px;margin-top:10px}
.att-item{
    display:flex;align-items:center;gap:8px;
    background:#eff6ff;border:1px solid #bfdbfe;border-radius:7px;padding:8px 12px;
}
.att-link{flex:1;font-size:12px;font-weight:600;color:#1e40af;text-decoration:none;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.att-link:hover{text-decoration:underline}
.att-date{font-size:10px;color:#94a3b8;flex-shrink:0}

.no-items{
    text-align:center;padding:28px;color:#94a3b8;font-size:13px;
}
.no-items i{font-size:26px;display:block;margin-bottom:8px;color:#cbd5e1}

/* ═══════════════════════════════════════
   SELECT2 GLOBAL OVERRIDES
═══════════════════════════════════════ */
.select2-container{width:100% !important}
.select2-container--default .select2-selection--single{
    height:42px;border:1px solid #d1d5db;border-radius:8px;
    display:flex;align-items:center;padding:0 12px;
}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open .select2-selection--single{
    border-color:#0369a1;box-shadow:0 0 0 3px rgba(3,105,161,.1);
}
.select2-container--default .select2-selection--single .select2-selection__rendered{
    line-height:40px;font-size:14px;font-family:inherit;color:#1e293b;padding:0;
}
.select2-container--default .select2-selection--single .select2-selection__placeholder{
    color:#94a3b8;
}
.select2-container--default .select2-selection--single .select2-selection__arrow{
    height:40px;right:10px;
}
.select2-dropdown{
    border:1px solid #d1d5db;border-radius:10px;
    box-shadow:0 10px 30px rgba(0,0,0,.1);
    font-size:13px;font-family:inherit;z-index:99999 !important;
}
.select2-container--default .select2-search--dropdown .select2-search__field{
    border:1px solid #d1d5db;border-radius:6px;padding:8px 12px;
    font-size:13px;font-family:inherit;outline:none;
}
.select2-container--default .select2-results__option{padding:10px 14px}
.select2-container--default .select2-results__option--highlighted[aria-selected]{
    background:#0369a1;color:#fff;
}

/* ═══════════════════════════════════════
   TOAST
═══════════════════════════════════════ */
#toast{
    position:fixed;bottom:24px;right:24px;padding:13px 22px;
    border-radius:10px;font-size:14px;font-weight:600;color:#fff;
    z-index:99999;display:none;box-shadow:0 4px 20px rgba(0,0,0,.18);
}
#toast.success{background:#16a34a}
#toast.error{background:#dc2626}
#toast.info{background:#0369a1}
#toast.warn{background:#d97706}

/* Skeleton loading */
.skel{
    height:14px;background:linear-gradient(90deg,#f1f5f9 25%,#e2e8f0 50%,#f1f5f9 75%);
    background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;
}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

@media(max-width:680px){
    .form-row2,.form-row3{grid-template-columns:1fr}
    .lines-tbl{font-size:11px}
    .mo-body{padding:16px}
    .drw-body{padding:16px}
}
</style>

<!-- ══════════════════════════════════════════════════
     PAGE
══════════════════════════════════════════════════ -->
<div class="sve-page-header">
    <div>
        <h2 class="sve-page-title"><i class="fa-solid fa-envelope-open-text"></i> SSCL &amp; VAT — Email Entries</h2>
        <p class="sve-page-sub">Record email-based claim entries with customer / employee line items and attachments</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <a href="sscl_vat.php" class="btn btn-light btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to SSCL VAT</a>
        <button class="btn btn-new" onclick="openNewEntry()">
            <i class="fa-solid fa-plus"></i> New Entry
        </button>
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
    <a href="sscl_vat_email.php" class="btn btn-light btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
</form>

<!-- TABLE -->
<div class="sve-table-card">
    <div class="sve-table-header">
        <div class="sve-table-title">
            <i class="fa-solid fa-table"></i>
            All Entries
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
                <th>Description</th>
                <th class="num">Net Amount</th>
                <th class="num">VAT 18%</th>
                <th class="num">Total Amount</th>
                <th style="text-align:center">Lines</th>
                <th style="text-align:center">Files</th>
                <th style="text-align:center">Actions</th>
            </tr>
        </thead>
        <tbody id="mainTbody">
        <?php foreach ($entries as $idx => $e):
            $net = floatval($e['total_net']);
            $vat = floatval($e['total_vat']);
            $tot = floatval($e['total_amount']);
        ?>
        <tr id="etr-<?php echo $e['id']; ?>"
            data-search="<?php echo strtolower(htmlspecialchars(($e['email_date'] ?? '') . ' ' . ($e['description'] ?? ''))); ?>">
            <td style="color:#94a3b8;font-size:11px"><?php echo $idx + 1; ?></td>
            <td>
                <div class="date-badge">
                    <i class="fa-regular fa-calendar" style="color:#0369a1"></i>
                    <?php echo $e['email_date'] ? date('d M Y', strtotime($e['email_date'])) : '—'; ?>
                </div>
            </td>
            <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;font-size:12px;color:#475569"
                title="<?php echo htmlspecialchars($e['description'] ?? ''); ?>">
                <?php echo htmlspecialchars(mb_substr($e['description'] ?? '—', 0, 60)); ?>
                <?php if (mb_strlen($e['description'] ?? '') > 60): ?>…<?php endif; ?>
            </td>
            <td class="num amt-net" id="td-net-<?php echo $e['id']; ?>"><?php echo $net > 0 ? number_format($net, 2) : '—'; ?></td>
            <td class="num amt-vat" id="td-vat-<?php echo $e['id']; ?>"><?php echo $vat > 0 ? number_format($vat, 2) : '—'; ?></td>
            <td class="num amt-tot" id="td-tot-<?php echo $e['id']; ?>"><?php echo $tot > 0 ? number_format($tot, 2) : '—'; ?></td>
            <td style="text-align:center">
                <?php $lc = intval($e['line_count']); ?>
                <?php if ($lc > 0): ?>
                    <span class="tag tag-green"><i class="fa-solid fa-list-check"></i> <?php echo $lc; ?> line<?php echo $lc > 1 ? 's' : ''; ?></span>
                <?php else: ?><span style="color:#d1d5db">—</span><?php endif; ?>
            </td>
            <td style="text-align:center">
                <?php $ac = intval($e['attach_count']); ?>
                <?php if ($ac > 0): ?>
                    <span class="tag tag-blue"><i class="fa-solid fa-paperclip"></i> <?php echo $ac; ?></span>
                <?php else: ?><span style="color:#d1d5db">—</span><?php endif; ?>
            </td>
            <td style="text-align:center">
                <div class="action-btns" style="justify-content:center">
                    <button class="abtn abtn-view" title="View" onclick="openView(<?php echo $e['id']; ?>)"><i class="fa-solid fa-eye"></i></button>
                    <button class="abtn abtn-edit" title="Edit" onclick="openEditEntry(<?php echo $e['id']; ?>)"><i class="fa-solid fa-pen"></i></button>
                    <button class="abtn abtn-del"  title="Delete" onclick="deleteEntry(<?php echo $e['id']; ?>)"><i class="fa-solid fa-trash"></i></button>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" style="text-align:right;font-size:11px;color:#64748b;font-weight:600">Page Totals</td>
                <td class="num" style="color:#0369a1"><?php echo number_format($totals_row['sum_net'] ?? 0, 2); ?></td>
                <td class="num" style="color:#7c3aed"><?php echo number_format($totals_row['sum_vat'] ?? 0, 2); ?></td>
                <td class="num" style="color:#16a34a"><?php echo number_format($totals_row['sum_total'] ?? 0, 2); ?></td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════
     ENTRY MODAL (Add / Edit)
═══════════════════════════════════════════════════════ -->
<div class="mo" id="entryModal">
<div class="mo-box">
    <div class="mo-hdr">
        <div class="mo-hdr-title"><i class="fa-solid fa-envelope-open-text"></i><span id="modalTitle">New Entry</span></div>
        <button class="mo-close" onclick="closeEntryModal()"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>
    <div class="mo-body">
        <input type="hidden" id="fe_id" value="0">

        <!-- Date & Description -->
        <div class="form-row2">
            <div class="fgrp">
                <label class="flbl">Email Date <span style="color:#ef4444">*</span></label>
                <input type="date" class="finp" id="fe_date">
            </div>
            <div class="fgrp">
                <label class="flbl">Description <span class="opt">(optional)</span></label>
                <input type="text" class="finp" id="fe_desc" placeholder="Brief description of this claim email…">
            </div>
        </div>

        <!-- Attachments -->
        <div class="sec-divider">
            <div class="sec-divider-line"></div>
            <div class="sec-divider-label"><i class="fa-solid fa-paperclip"></i> Email Attachments</div>
            <div class="sec-divider-line"></div>
        </div>

        <!-- Saved attachments (edit mode) -->
        <div id="savedFilesWrap" style="display:none;margin-bottom:10px">
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px">
                Existing Files
            </div>
            <div class="saved-files" id="savedFilesList"></div>
        </div>

        <div class="file-zone" id="fileZone"
             ondragover="event.preventDefault();this.classList.add('drag')"
             ondragleave="this.classList.remove('drag')"
             ondrop="handleFileDrop(event)">
            <input type="file" id="fe_files" multiple
                accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"
                onchange="handleFileSelect(this)">
            <i class="fa-solid fa-cloud-arrow-up file-zone-icon"></i>
            <div class="file-zone-text">Click or drag &amp; drop to attach files</div>
            <div class="file-zone-sub">PDF, DOC, DOCX, XLS, XLSX, JPG, PNG — multiple files supported</div>
        </div>
        <div class="pending-files" id="pendingFilesList"></div>

        <!-- Lines -->
        <div class="sec-divider" style="margin-top:24px">
            <div class="sec-divider-line"></div>
            <div class="sec-divider-label"><i class="fa-solid fa-list-check"></i> Customer &amp; Employee Lines</div>
            <div class="sec-divider-line"></div>
        </div>

        <div class="lines-wrap">
            <table class="lines-tbl">
                <thead>
                    <tr>
                        <th style="width:100px">Type</th>
                        <th style="min-width:200px">Customer / Employee</th>
                        <th class="r" style="width:120px">Net Amount</th>
                        <th class="r" style="width:110px">VAT 18%</th>
                        <th class="r" style="width:115px">Total</th>
                        <th style="width:140px">Note</th>
                        <th style="width:46px"></th>
                    </tr>
                </thead>
                <tbody id="linesTbody">
                    <!-- rows injected by JS -->
                </tbody>
            </table>
            <div class="lines-summary" id="linesSummary">
                <div class="ls-item"><div class="ls-lbl">Net Total</div><div class="ls-val net" id="ls_net">0.00</div></div>
                <div class="ls-item"><div class="ls-lbl">VAT 18%</div><div class="ls-val vat" id="ls_vat">0.00</div></div>
                <div class="ls-item"><div class="ls-lbl">Grand Total</div><div class="ls-val tot" id="ls_tot">0.00</div></div>
            </div>
            <div class="add-line-row">
                <button class="btn btn-success btn-sm" onclick="addLine('customer')">
                    <i class="fa-solid fa-user-plus"></i> Add Customer Line
                </button>
                <button class="btn btn-amber btn-sm" onclick="addLine('employee')">
                    <i class="fa-solid fa-id-badge"></i> Add Employee Line
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

<!-- ═══════════════════════════════════════════════════════
     VIEW DRAWER
═══════════════════════════════════════════════════════ -->
<div class="drw" id="viewDrawer">
<div class="drw-box">
    <div class="drw-hdr">
        <div class="drw-hdr-title"><i class="fa-solid fa-eye"></i><span id="drwTitle">Entry Details</span></div>
        <button class="drw-close" onclick="closeView()"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>
    <div class="drw-strip" id="drwStrip">
        <div class="drw-strip-cell"><div class="ds-lbl">Email Date</div><div class="ds-val" id="drw_date">—</div></div>
        <div class="drw-strip-cell"><div class="ds-lbl">Net Amount</div><div class="ds-val sky" id="drw_net">—</div></div>
        <div class="drw-strip-cell"><div class="ds-lbl">VAT 18%</div><div class="ds-val purple" id="drw_vat">—</div></div>
        <div class="drw-strip-cell"><div class="ds-lbl">Total</div><div class="ds-val green" id="drw_tot">—</div></div>
        <div class="drw-strip-cell"><div class="ds-lbl">Lines</div><div class="ds-val" id="drw_lines">—</div></div>
        <div class="drw-strip-cell"><div class="ds-lbl">Files</div><div class="ds-val" id="drw_files">—</div></div>
    </div>
    <div class="drw-body">
        <!-- Description -->
        <div id="drwDescWrap" style="margin-bottom:18px;display:none">
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px">Description</div>
            <div id="drwDesc" style="font-size:13px;color:#475569;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;line-height:1.6"></div>
        </div>

        <!-- Attachments -->
        <div style="margin-bottom:22px">
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px">
                <i class="fa-solid fa-paperclip"></i> Attachments
            </div>
            <div id="drwAttachments" class="att-grid">
                <div class="no-items"><i class="fa-solid fa-folder-open"></i> No attachments</div>
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
            <button class="btn btn-primary btn-sm" id="drwEditBtn" onclick="openEditFromView()">
                <i class="fa-solid fa-pen"></i> Edit This Entry
            </button>
        </div>
    </div>
</div>
</div>

<div id="toast"></div>

<!-- ═══════════════════════════════════════════════════════
     JAVASCRIPT
═══════════════════════════════════════════════════════ -->
<script>
const fN  = v => parseFloat(v || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
const eh  = s => String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

function toast(msg, type) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = type; t.style.display = 'block';
    clearTimeout(t._t); t._t = setTimeout(() => t.style.display = 'none', 4000);
}

/* ────────────────────────────────────
   TABLE SEARCH
──────────────────────────────────── */
function doTableSearch() {
    const q = document.getElementById('tblSearch').value.toLowerCase().trim();
    let vis = 0;
    document.querySelectorAll('#mainTbody tr').forEach(row => {
        const show = !q || (row.dataset.search || '').includes(q);
        row.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    document.getElementById('entryCountBadge').textContent = vis;
}

/* ────────────────────────────────────
   PENDING FILES MANAGEMENT
──────────────────────────────────── */
let _pendingFiles = [];

function handleFileSelect(inp) {
    Array.from(inp.files).forEach(f => addPendingFile(f));
    inp.value = ''; // reset so same file can be re-selected
}
function handleFileDrop(e) {
    e.preventDefault();
    document.getElementById('fileZone').classList.remove('drag');
    Array.from(e.dataTransfer.files).forEach(f => addPendingFile(f));
}
function addPendingFile(f) {
    if (_pendingFiles.find(p => p.name === f.name && p.size === f.size)) return;
    _pendingFiles.push(f);
    renderPendingFiles();
}
function removePendingFile(idx) {
    _pendingFiles.splice(idx, 1);
    renderPendingFiles();
}
function renderPendingFiles() {
    const list = document.getElementById('pendingFilesList');
    if (!_pendingFiles.length) { list.innerHTML = ''; return; }
    list.innerHTML = _pendingFiles.map((f, i) =>
        `<div class="pfile">
            <i class="fa-solid fa-file" style="color:#16a34a;font-size:13px;flex-shrink:0"></i>
            <span class="pfile-name">${eh(f.name)}</span>
            <span class="pfile-size">${(f.size / 1024).toFixed(1)} KB</span>
            <button class="pfile-rm" onclick="removePendingFile(${i})" title="Remove"><i class="fa-solid fa-xmark"></i></button>
        </div>`
    ).join('');
}

/* Saved attachments in edit mode */
let _savedAttachments = [];
function renderSavedFiles() {
    const wrap = document.getElementById('savedFilesWrap');
    const list = document.getElementById('savedFilesList');
    if (!_savedAttachments.length) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'block';
    list.innerHTML = _savedAttachments.map(a =>
        `<div class="sfile" id="sfile-${a.id}">
            <i class="fa-solid fa-paperclip" style="color:#1e40af;font-size:13px;flex-shrink:0"></i>
            <a href="${eh(a.file_path)}" target="_blank" class="sfile-name">${eh(a.file_name)}</a>
            <span class="sfile-date">${(a.uploaded_at || '').slice(0,10)}</span>
            <button class="sfile-rm" onclick="deleteSavedFile(${a.id})" title="Remove"><i class="fa-solid fa-trash" style="font-size:11px"></i></button>
        </div>`
    ).join('');
}
async function deleteSavedFile(aid) {
    if (!confirm('Remove this attachment?')) return;
    const fd = new FormData(); fd.append('id', aid);
    const res = await fetch('sscl_vat_email.php?action=delete_attachment', {method:'POST', body:fd});
    const d = await res.json();
    if (d.success) {
        _savedAttachments = _savedAttachments.filter(a => a.id !== aid);
        document.getElementById('sfile-' + aid)?.remove();
        if (!_savedAttachments.length) document.getElementById('savedFilesWrap').style.display = 'none';
        toast('Attachment removed.', 'info');
    } else toast('Failed to remove.', 'error');
}

/* ────────────────────────────────────
   LINE ITEMS
──────────────────────────────────── */
let _lineCounter = 0;

function addLine(type = 'customer') {
    const id = ++_lineCounter;
    const tbody = document.getElementById('linesTbody');
    const tr = document.createElement('tr');
    tr.id = 'lr-' + id;
    tr.dataset.type = type;
    tr.innerHTML = buildLineHTML(id, type);
    tbody.appendChild(tr);
    initLineSelect(id, type);
    recalcSummary();
}

function buildLineHTML(id, type) {
    const isCust = type === 'customer';
    return `
    <td>
        <div class="type-toggle">
            <button type="button" class="type-btn ${isCust ? 'active cust' : ''}" id="tbtn-cust-${id}" onclick="switchLineType(${id},'customer')">
                <i class="fa-solid fa-user"></i> Cust
            </button>
            <button type="button" class="type-btn ${!isCust ? 'active emp' : ''}" id="tbtn-emp-${id}" onclick="switchLineType(${id},'employee')">
                <i class="fa-solid fa-id-badge"></i> Emp
            </button>
        </div>
    </td>
    <td>
        <select id="lsel-${id}" style="min-width:200px"></select>
    </td>
    <td class="r">
        <input type="number" step="0.01" min="0" class="line-net-inp" id="lnet-${id}"
            placeholder="0.00" oninput="onNetChange(${id})">
    </td>
    <td class="r">
        <input type="text" class="line-ro purple" id="lvat-${id}" readonly value="0.00">
    </td>
    <td class="r">
        <input type="text" class="line-ro green" id="ltot-${id}" readonly value="0.00">
    </td>
    <td>
        <input type="text" class="note-inp" id="lnote-${id}" placeholder="Note…">
    </td>
    <td>
        <button class="line-del-btn" onclick="removeLine(${id})" title="Remove line">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </td>`;
}

function initLineSelect(id, type, preVal, preText) {
    const sel = $('#lsel-' + id);
    // Destroy if already initialized
    if (sel.hasClass('select2-hidden-accessible')) sel.select2('destroy');

    const isCust = type === 'customer';
    sel.select2({
        dropdownParent: $('#entryModal'),
        placeholder: isCust ? '— Select Customer —' : '— Select Employee —',
        allowClear: true,
        minimumInputLength: 0,
        ajax: {
            url: 'sscl_vat_email.php',
            dataType: 'json',
            delay: 200,
            data: params => ({
                action: isCust ? 'search_customers' : 'search_employees',
                q: params.term || ''
            }),
            processResults: data => ({ results: data.results || [] }),
            cache: true
        }
    });

    if (preVal && preText) {
        const option = new Option(preText, preVal, true, true);
        sel.append(option).trigger('change');
    }
}

function switchLineType(id, newType) {
    const tr = document.getElementById('lr-' + id);
    if (!tr) return;
    tr.dataset.type = newType;
    const isCust = newType === 'customer';
    document.getElementById('tbtn-cust-' + id).className = 'type-btn' + (isCust ? ' active cust' : '');
    document.getElementById('tbtn-emp-' + id).className  = 'type-btn' + (!isCust ? ' active emp' : '');
    // Re-init select (destroy + rebuild)
    const netVal = document.getElementById('lnet-' + id).value;
    const noteVal = document.getElementById('lnote-' + id).value;
    initLineSelect(id, newType);
    document.getElementById('lnet-' + id).value = netVal;
    document.getElementById('lnote-' + id).value = noteVal;
    onNetChange(id);
}

function onNetChange(id) {
    const net = parseFloat(document.getElementById('lnet-' + id).value || 0) || 0;
    const vat = Math.round(net * 0.18 * 100) / 100;
    const tot = net + vat;
    document.getElementById('lvat-' + id).value = fN(vat);
    document.getElementById('ltot-' + id).value = fN(tot);
    recalcSummary();
}

function removeLine(id) {
    document.getElementById('lr-' + id)?.remove();
    recalcSummary();
}

function recalcSummary() {
    let totalNet = 0, totalVat = 0;
    document.querySelectorAll('#linesTbody tr').forEach(tr => {
        const net = parseFloat(document.getElementById('lnet-' + tr.id.split('-')[1])?.value || 0) || 0;
        const vat = Math.round(net * 0.18 * 100) / 100;
        totalNet += net;
        totalVat += vat;
    });
    const totalTot = totalNet + totalVat;
    document.getElementById('ls_net').textContent = fN(totalNet);
    document.getElementById('ls_vat').textContent = fN(totalVat);
    document.getElementById('ls_tot').textContent = fN(totalTot);
}

function collectLines() {
    const lines = [];
    document.querySelectorAll('#linesTbody tr').forEach(tr => {
        const rowId   = tr.id.split('-')[1];
        const type    = tr.dataset.type || 'customer';
        const selEl   = $('#lsel-' + rowId);
        const selData = selEl.select2('data');
        const chosen  = selData && selData.length ? selData[0] : null;
        const net     = parseFloat(document.getElementById('lnet-' + rowId)?.value || 0) || 0;
        const note    = document.getElementById('lnote-' + rowId)?.value || '';
        if (!chosen || net <= 0) return; // skip empty rows
        lines.push({
            type:   type,
            ref_id: chosen.id || 0,
            code:   chosen.code || '',
            name:   chosen.name || (chosen.text || ''),
            net:    net,
            note:   note,
        });
    });
    return lines;
}

/* ────────────────────────────────────
   MODAL OPEN / CLOSE
──────────────────────────────────── */
function openNewEntry() {
    resetModal();
    document.getElementById('modalTitle').textContent = 'New Entry';
    document.getElementById('fe_id').value = '0';
    document.getElementById('entryModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    // Add one line by default
    addLine('customer');
}

async function openEditEntry(id) {
    resetModal();
    document.getElementById('modalTitle').textContent = 'Edit Entry #' + id;
    document.getElementById('fe_id').value = id;

    const res = await fetch('sscl_vat_email.php?action=get_entry&id=' + id);
    const d   = await res.json();
    if (!d.success || !d.data) { toast('Could not load entry.', 'error'); return; }

    document.getElementById('fe_date').value = d.data.email_date || '';
    document.getElementById('fe_desc').value = d.data.description || '';

    // Saved attachments
    _savedAttachments = d.attachments || [];
    renderSavedFiles();

    // Restore lines
    for (const line of (d.lines || [])) {
        const lid = ++_lineCounter;
        const tbody = document.getElementById('linesTbody');
        const tr = document.createElement('tr');
        tr.id = 'lr-' + lid;
        tr.dataset.type = line.line_type || 'customer';
        tr.innerHTML = buildLineHTML(lid, line.line_type || 'customer');
        tbody.appendChild(tr);
        const preText = '[' + line.ref_code + '] ' + line.ref_name;
        initLineSelect(lid, line.line_type || 'customer', line.ref_id, preText);
        document.getElementById('lnet-' + lid).value = parseFloat(line.net_amount || 0).toFixed(2);
        document.getElementById('lnote-' + lid).value = line.note || '';
        onNetChange(lid);
    }
    if (!(d.lines || []).length) addLine('customer');

    document.getElementById('entryModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function openEditFromView() {
    const id = parseInt(document.getElementById('_viewId')?.value || 0);
    closeView();
    if (id) openEditEntry(id);
}

function closeEntryModal() {
    document.getElementById('entryModal').classList.remove('open');
    document.body.style.overflow = '';
}

function resetModal() {
    _pendingFiles = [];
    _savedAttachments = [];
    _lineCounter = 0;
    document.getElementById('fe_date').value = '';
    document.getElementById('fe_desc').value = '';
    document.getElementById('pendingFilesList').innerHTML = '';
    document.getElementById('savedFilesWrap').style.display = 'none';
    document.getElementById('savedFilesList').innerHTML = '';
    document.getElementById('linesTbody').innerHTML = '';
    recalcSummary();
}

/* ────────────────────────────────────
   SAVE ENTRY
──────────────────────────────────── */
async function saveEntry() {
    const id       = document.getElementById('fe_id').value;
    const date     = document.getElementById('fe_date').value;
    const desc     = document.getElementById('fe_desc').value;
    const lines    = collectLines();

    if (!date) { toast('Please select an email date.', 'error'); return; }

    const btn = document.getElementById('saveEntryBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('id', id);
    fd.append('email_date', date);
    fd.append('description', desc);
    fd.append('lines', JSON.stringify(lines));

    // Attach pending files
    _pendingFiles.forEach(f => fd.append('attachments[]', f));

    try {
        const res = await fetch('sscl_vat_email.php?action=save_entry', {method:'POST', body:fd});
        const d = await res.json();
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Entry';

        if (!d.success) { toast('Error saving entry.', 'error'); return; }
        toast(parseInt(id) > 0 ? 'Entry updated!' : 'Entry created!', 'success');
        closeEntryModal();
        setTimeout(() => location.reload(), 700);
    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Entry';
        toast('Network error: ' + e.message, 'error');
    }
}

/* ────────────────────────────────────
   DELETE ENTRY
──────────────────────────────────── */
async function deleteEntry(id) {
    if (!confirm('Delete this entry along with all its lines and attachments?\n\nThis cannot be undone.')) return;
    const fd = new FormData(); fd.append('id', id);
    const res = await fetch('sscl_vat_email.php?action=delete_entry', {method:'POST', body:fd});
    const d = await res.json();
    if (d.success) {
        document.getElementById('etr-' + id)?.remove();
        toast('Entry deleted.', 'warn');
    } else toast('Delete failed.', 'error');
}

/* ────────────────────────────────────
   VIEW DRAWER
──────────────────────────────────── */
let _viewCurrentId = 0;

async function openView(id) {
    _viewCurrentId = id;
    // inject hidden id holder
    let hid = document.getElementById('_viewId');
    if (!hid) { hid = document.createElement('input'); hid.type='hidden'; hid.id='_viewId'; document.body.appendChild(hid); }
    hid.value = id;

    document.getElementById('drwTitle').textContent = 'Entry #' + id;
    document.getElementById('viewDrawer').classList.add('open');
    document.body.style.overflow = 'hidden';

    // Reset
    document.getElementById('drw_date').textContent   = '…';
    document.getElementById('drw_net').textContent    = '…';
    document.getElementById('drw_vat').textContent    = '…';
    document.getElementById('drw_tot').textContent    = '…';
    document.getElementById('drw_lines').textContent  = '…';
    document.getElementById('drw_files').textContent  = '…';
    document.getElementById('drwAttachments').innerHTML = '<div class="no-items"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    document.getElementById('drwLines').innerHTML     = '<div class="no-items"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    document.getElementById('drwDescWrap').style.display = 'none';

    try {
        const res = await fetch('sscl_vat_email.php?action=get_entry&id=' + id);
        const d = await res.json();
        if (!d.success || !d.data) { toast('Could not load entry.', 'error'); return; }
        const data = d.data;

        // Strip
        document.getElementById('drw_date').textContent = data.email_date ? new Date(data.email_date + 'T00:00:00').toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) : '—';
        document.getElementById('drw_net').textContent  = fN(data.total_net);
        document.getElementById('drw_vat').textContent  = fN(data.total_vat);
        document.getElementById('drw_tot').textContent  = fN(data.total_amount);
        document.getElementById('drw_lines').textContent = (d.lines || []).length;
        document.getElementById('drw_files').textContent = (d.attachments || []).length;

        // Description
        if (data.description) {
            document.getElementById('drwDescWrap').style.display = 'block';
            document.getElementById('drwDesc').textContent = data.description;
        }

        // Attachments
        const attWrap = document.getElementById('drwAttachments');
        if (!(d.attachments || []).length) {
            attWrap.innerHTML = '<div class="no-items"><i class="fa-solid fa-folder-open"></i> No attachments uploaded</div>';
        } else {
            attWrap.innerHTML = d.attachments.map(a => {
                const ext = (a.file_path || '').split('.').pop().toLowerCase();
                const iconMap = {pdf:'fa-file-pdf',doc:'fa-file-word',docx:'fa-file-word',xls:'fa-file-excel',xlsx:'fa-file-excel',jpg:'fa-file-image',jpeg:'fa-file-image',png:'fa-file-image'};
                const icon = iconMap[ext] || 'fa-file';
                return `<div class="att-item">
                    <i class="fa-solid ${icon}" style="color:#1e40af;font-size:14px;flex-shrink:0"></i>
                    <a href="${eh(a.file_path)}" target="_blank" class="att-link">${eh(a.file_name)}</a>
                    <span class="att-date">${(a.uploaded_at || '').slice(0,10)}</span>
                </div>`;
            }).join('');
        }

        // Lines
        const linesWrap = document.getElementById('drwLines');
        if (!(d.lines || []).length) {
            linesWrap.innerHTML = '<div class="no-items"><i class="fa-solid fa-list-check"></i> No line items</div>';
        } else {
            let totNet = 0, totVat = 0, totTot = 0;
            const rows = d.lines.map(l => {
                const isCust = l.line_type === 'customer';
                totNet += parseFloat(l.net_amount || 0);
                totVat += parseFloat(l.vat_amount || 0);
                totTot += parseFloat(l.total_amount || 0);
                const pill = isCust
                    ? `<span class="type-pill-cust"><i class="fa-solid fa-user"></i> Customer</span>`
                    : `<span class="type-pill-emp"><i class="fa-solid fa-id-badge"></i> Employee</span>`;
                return `<tr>
                    <td>${pill}</td>
                    <td>
                        <div class="ref-name">${eh(l.ref_name)}</div>
                        <div class="ref-code">${eh(l.ref_code)}</div>
                    </td>
                    <td class="r" style="color:#0369a1;font-weight:700">${fN(l.net_amount)}</td>
                    <td class="r" style="color:#7c3aed;font-weight:700">${fN(l.vat_amount)}</td>
                    <td class="r" style="color:#16a34a;font-weight:800">${fN(l.total_amount)}</td>
                    <td style="font-size:11px;color:#94a3b8">${eh(l.note || '')}</td>
                </tr>`;
            }).join('');
            linesWrap.innerHTML = `<table class="vlines-tbl">
                <thead><tr>
                    <th style="width:110px">Type</th>
                    <th>Name</th>
                    <th class="r">Net Amount</th>
                    <th class="r">VAT 18%</th>
                    <th class="r">Total</th>
                    <th>Note</th>
                </tr></thead>
                <tbody>${rows}</tbody>
                <tfoot><tr>
                    <td colspan="2" style="font-size:11px;color:#64748b">Totals</td>
                    <td class="r" style="color:#0369a1">${fN(totNet)}</td>
                    <td class="r" style="color:#7c3aed">${fN(totVat)}</td>
                    <td class="r" style="color:#16a34a">${fN(totTot)}</td>
                    <td></td>
                </tr></tfoot>
            </table>`;
        }

    } catch(e) {
        toast('Load error: ' + e.message, 'error');
    }
}

function closeView() {
    document.getElementById('viewDrawer').classList.remove('open');
    document.body.style.overflow = '';
}

/* ── Close modals on backdrop click ── */
document.getElementById('entryModal').addEventListener('click', e => { if (e.target === document.getElementById('entryModal')) closeEntryModal(); });
document.getElementById('viewDrawer').addEventListener('click', e => { if (e.target === document.getElementById('viewDrawer')) closeView(); });
</script>

<?php include 'footer.php'; ?>