<?php
// ============================================================
//  sampath_item_rejection.php
//  SAMPATH ITEM REJECTION ENTRIES (GRN) — list, item summary and supporting documents
//
//  Data: grn_headers / grn_items — the same GRN entries that feed "Sampath Rejected Qty"
//  on the Sampath stock reports (statuses saved / cc_checked / st_checked count as rejected;
//  draft and cancelled entries are excluded from the Item Summary).
//
//    Entries tab       -> one row per GRN entry (ref, delivery date, invoice, outlet, rejected qty,
//                         amount, status) with its optional supporting document
//    Item Summary tab  -> rejected qty and amount per item for the filtered entries
//    Supporting document (one per entry): PDF / JPG / PNG / WEBP / DOC / DOCX / XLS / XLSX, max 5 MB,
//    stored in uploads/grn-documents/ (blocked from direct web access by that folder's .htaccess)
//    and served only through this page (?doc=<id>) after login.
// ============================================================
include 'config.php';
include_once 'auth.php';
requireLogin();

define('GRN_DOC_DIR_REL', 'uploads/grn-documents');
define('GRN_DOC_MAX_BYTES', 5 * 1024 * 1024);

function sir_table($conn, $t) {
    $q = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'");
    return $q && mysqli_num_rows($q) > 0;
}
$have_grn   = sir_table($conn, 'grn_headers') && sir_table($conn, 'grn_items');
$have_users = sir_table($conn, 'users');

/* make sure the document columns exist */
if ($have_grn) {
    foreach (['document_path' => 'VARCHAR(255) DEFAULT NULL', 'document_name' => 'VARCHAR(255) DEFAULT NULL', 'document_uploaded_at' => 'DATETIME DEFAULT NULL'] as $col => $def) {
        $c = mysqli_query($conn, "SHOW COLUMNS FROM grn_headers LIKE '$col'");
        if ($c && mysqli_num_rows($c) === 0) mysqli_query($conn, "ALTER TABLE grn_headers ADD COLUMN $col $def");
    }
}

function sir_doc_types() {
    return [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        'doc'  => ['application/msword', 'application/octet-stream', 'application/x-ole-storage', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls'  => ['application/vnd.ms-excel', 'application/octet-stream', 'application/x-ole-storage', 'application/CDFV2'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];
}
function sir_doc_dir() { return __DIR__ . '/' . GRN_DOC_DIR_REL; }

/** Validate $_FILES['document']. Returns ['error'=>..] or ['tmp','ext','name'] */
function sir_validate_doc() {
    if (empty($_FILES['document']) || !isset($_FILES['document']['error']) || $_FILES['document']['error'] === UPLOAD_ERR_NO_FILE)
        return ['error' => 'Choose a file to upload.'];
    $f = $_FILES['document'];
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) return ['error' => 'Document is too large. Maximum size is 5 MB.'];
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return ['error' => 'Document upload failed (code ' . intval($f['error']) . '). Please try again.'];
    if ($f['size'] <= 0 || $f['size'] > GRN_DOC_MAX_BYTES) return ['error' => 'Document is too large. Maximum size is 5 MB.'];
    $orig = basename((string)$f['name']);
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = sir_doc_types();
    if (!isset($allowed[$ext])) return ['error' => 'File type not allowed. Use PDF, JPG, PNG, WEBP, DOC, DOCX, XLS or XLSX.'];
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? finfo_file($fi, $f['tmp_name']) : '';
        if ($fi) finfo_close($fi);
        if ($mime && !in_array($mime, $allowed[$ext], true)) return ['error' => 'The file content does not match its extension (.' . $ext . ').'];
    }
    $clean = mb_substr(preg_replace('/[^\w\s.\-()]+/u', '_', $orig), 0, 200);
    return ['tmp' => $f['tmp_name'], 'ext' => $ext, 'name' => $clean];
}
function sir_store_doc($doc, $grn_id) {
    $dir = sir_doc_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order Allow,Deny\n    Deny from all\n</IfModule>\n");
    $name = 'grn' . intval($grn_id) . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $doc['ext'];
    if (!move_uploaded_file($doc['tmp'], $dir . '/' . $name)) return false;
    return GRN_DOC_DIR_REL . '/' . $name;
}
function sir_delete_doc_file($rel) {
    if (!$rel) return;
    $abs = realpath(__DIR__ . '/' . $rel);
    $dir = realpath(sir_doc_dir());
    if ($abs && $dir && strpos($abs, $dir . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) @unlink($abs);
}

/* ── view / download the attached document ── */
if ($have_grn && isset($_GET['doc'])) {
    $id  = (int)$_GET['doc'];
    $q   = $id ? mysqli_query($conn, "SELECT document_path, document_name FROM grn_headers WHERE id = $id LIMIT 1") : false;
    $row = $q ? mysqli_fetch_assoc($q) : null;
    $abs = ($row && $row['document_path']) ? realpath(__DIR__ . '/' . $row['document_path']) : false;
    $base = realpath(sir_doc_dir());
    if (!$abs || !$base || strpos($abs, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) {
        http_response_code(404);
        echo '<p style="font-family:sans-serif;padding:24px;">Document not found.</p>';
        exit;
    }
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $types = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','doc'=>'application/msword',
              'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','xls'=>'application/vnd.ms-excel',
              'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    $inline = in_array($ext, ['pdf','jpg','jpeg','png','webp'], true) && empty($_GET['download']);
    $fname  = $row['document_name'] ?: basename($abs);
    $ascii  = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $fname);
    while (ob_get_level()) @ob_end_clean();
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($fname));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($abs);
    exit;
}

/* ── AJAX: items of one entry ── */
if ($have_grn && ($_GET['ajax'] ?? '') === 'items') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['grn_id'] ?? 0);
    $hq = mysqli_query($conn, "SELECT grn_ref, outlet_name, t_code, delivery_date, invoice_no, total_amount, status FROM grn_headers WHERE id = $id LIMIT 1");
    $h  = $hq ? mysqli_fetch_assoc($hq) : null;
    $items = [];
    $iq = mysqli_query($conn, "SELECT product_code, product_description, qty, tur, total_amount, reason_name FROM grn_items WHERE grn_id = $id ORDER BY id");
    while ($iq && $r = mysqli_fetch_assoc($iq)) $items[] = $r;
    echo json_encode(['header' => $h, 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ── POST: upload / replace / remove a document ── */
$flash_ok = $flash_err = '';
if ($have_grn && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['upload_document', 'remove_document'], true)) {
    $id  = (int)($_POST['grn_id'] ?? 0);
    $q   = $id ? mysqli_query($conn, "SELECT document_path FROM grn_headers WHERE id = $id LIMIT 1") : false;
    $old = $q ? mysqli_fetch_assoc($q) : null;
    $back = [];
    parse_str((string)($_POST['qs'] ?? ''), $back);
    unset($back['msg'], $back['err']);
    if (!$old) {
        $back['err'] = 'Entry not found.';
    } elseif ($_POST['action'] === 'remove_document') {
        mysqli_query($conn, "UPDATE grn_headers SET document_path = NULL, document_name = NULL, document_uploaded_at = NULL WHERE id = $id");
        sir_delete_doc_file($old['document_path']);
        $back['msg'] = 'Document removed.';
    } else {
        $doc = sir_validate_doc();
        if (isset($doc['error'])) {
            $back['err'] = $doc['error'];
        } else {
            $rel = sir_store_doc($doc, $id);
            if (!$rel) {
                $back['err'] = 'The document could not be stored on the server.';
            } else {
                $p = mysqli_real_escape_string($conn, $rel);
                $n = mysqli_real_escape_string($conn, $doc['name']);
                mysqli_query($conn, "UPDATE grn_headers SET document_path = '$p', document_name = '$n', document_uploaded_at = NOW() WHERE id = $id");
                sir_delete_doc_file($old['document_path']);   // a new file replaces the old one
                $back['msg'] = 'Document saved.';
            }
        }
    }
    header('Location: sampath_item_rejection.php?' . http_build_query($back));
    exit;
}
if (isset($_GET['msg'])) $flash_ok  = (string)$_GET['msg'];
if (isset($_GET['err'])) $flash_err = (string)$_GET['err'];

/* ── filters ── */
$f_tab    = ($_GET['tab'] ?? 'entries') === 'items' ? 'items' : 'entries';
$f_from   = trim($_GET['date_from'] ?? '');
$f_to     = trim($_GET['date_to'] ?? '');
$f_status = trim($_GET['status'] ?? '');
$f_doc    = in_array($_GET['doc_filter'] ?? '', ['with', 'without']) ? $_GET['doc_filter'] : '';
$f_search = trim($_GET['search'] ?? '');
$e = function ($v) use ($conn) { return mysqli_real_escape_string($conn, $v); };

$where = ['1=1'];
if ($f_from)   $where[] = "h.delivery_date >= '" . $e($f_from) . "'";
if ($f_to)     $where[] = "h.delivery_date <= '" . $e($f_to) . "'";
if ($f_status) $where[] = "h.status = '" . $e($f_status) . "'";
if ($f_doc === 'with')    $where[] = "(h.document_path IS NOT NULL AND h.document_path <> '')";
if ($f_doc === 'without') $where[] = "(h.document_path IS NULL OR h.document_path = '')";
if ($f_search) {
    $s = $e($f_search);
    $where[] = "(h.grn_ref LIKE '%$s%' OR h.outlet_name LIKE '%$s%' OR h.outlet_code LIKE '%$s%' OR h.invoice_no LIKE '%$s%' OR h.t_code LIKE '%$s%')";
}
$where_sql = implode(' AND ', $where);

$statuses = [];
$entries = $summary = [];
$total_entries = 0; $pages = 1; $page = max(1, (int)($_GET['page'] ?? 1)); $per_page = 25;
$kpi = ['entries' => 0, 'qty' => 0, 'amount' => 0, 'docs' => 0];
$errors = [];

if ($have_grn) {
    $q = mysqli_query($conn, "SELECT DISTINCT status FROM grn_headers WHERE status IS NOT NULL AND status <> '' ORDER BY status");
    while ($q && $r = mysqli_fetch_assoc($q)) $statuses[] = $r['status'];

    /* KPIs over the filtered entries that count as rejected (not draft / cancelled) */
    $q = mysqli_query($conn, "SELECT COUNT(DISTINCT h.id) AS n, COALESCE(SUM(i.qty),0) AS qty, COALESCE(SUM(i.total_amount),0) AS amt,
            COUNT(DISTINCT CASE WHEN h.document_path IS NOT NULL AND h.document_path <> '' THEN h.id END) AS docs
        FROM grn_headers h LEFT JOIN grn_items i ON i.grn_id = h.id
        WHERE $where_sql AND h.status NOT IN ('draft','cancelled')");
    if ($q && $r = mysqli_fetch_assoc($q)) $kpi = ['entries' => (int)$r['n'], 'qty' => (float)$r['qty'], 'amount' => (float)$r['amt'], 'docs' => (int)$r['docs']];

    if ($f_tab === 'entries') {
        $q = mysqli_query($conn, "SELECT COUNT(*) AS c FROM grn_headers h WHERE $where_sql");
        $total_entries = $q ? (int)mysqli_fetch_assoc($q)['c'] : 0;
        $pages = max(1, (int)ceil($total_entries / $per_page));
        $page = min($page, $pages);
        $off = ($page - 1) * $per_page;
        $user_sel  = $have_users ? ', u.username AS created_by_name' : ", NULL AS created_by_name";
        $user_join = $have_users ? 'LEFT JOIN users u ON u.id = h.created_by' : '';
        $q = mysqli_query($conn, "SELECT h.id, h.grn_ref, h.outlet_code, h.outlet_name, h.t_code, h.delivery_date, h.invoice_no, h.total_amount,
                h.status, h.created_at, h.document_name, h.document_uploaded_at,
                (SELECT COUNT(*) FROM grn_items i WHERE i.grn_id = h.id) AS item_count,
                (SELECT COALESCE(SUM(i.qty),0) FROM grn_items i WHERE i.grn_id = h.id) AS rej_qty
                $user_sel
            FROM grn_headers h $user_join
            WHERE $where_sql ORDER BY h.delivery_date DESC, h.id DESC LIMIT $per_page OFFSET $off");
        if (!$q) $errors[] = mysqli_error($conn);
        while ($q && $r = mysqli_fetch_assoc($q)) $entries[] = $r;
    } else {
        $q = mysqli_query($conn, "SELECT i.product_code, MAX(i.product_description) AS nm, SUM(i.qty) AS qty, SUM(i.total_amount) AS amt, COUNT(DISTINCT h.id) AS n
            FROM grn_items i JOIN grn_headers h ON h.id = i.grn_id
            WHERE $where_sql AND h.status NOT IN ('draft','cancelled') AND i.product_code <> ''
            GROUP BY i.product_code ORDER BY i.product_code");
        if (!$q) $errors[] = mysqli_error($conn);
        while ($q && $r = mysqli_fetch_assoc($q)) $summary[] = ['code' => $r['product_code'], 'name' => $r['nm'], 'qty' => (float)$r['qty'], 'amt' => (float)$r['amt'], 'n' => (int)$r['n']];
    }
}

$qs_now  = http_build_query(array_filter(['tab' => $f_tab, 'date_from' => $f_from, 'date_to' => $f_to, 'status' => $f_status, 'doc_filter' => $f_doc, 'search' => $f_search, 'page' => $page > 1 ? $page : null], function ($v) { return $v !== '' && $v !== null; }));
$qs_base = ['date_from' => $f_from, 'date_to' => $f_to, 'status' => $f_status, 'doc_filter' => $f_doc, 'search' => $f_search];
include 'header.php';
function sir_n($v) { return number_format(round($v), 0); }
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
<style>
.sir{padding:0 4px;}
.sir h2{font-size:22px;font-weight:800;margin:0 0 4px;}
.sir .sub{font-size:12px;color:#8892a4;margin-bottom:16px;}
.sir-card{background:#fff;border:1.5px solid #e2e6f0;border-radius:14px;padding:16px 20px;margin-bottom:18px;}
.sir-f{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.sir-f label{display:block;font-size:10px;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:4px;}
.sir-f input,.sir-f select{border:1.5px solid #e2e6f0;border-radius:8px;padding:8px 12px;font-size:13px;background:#fff;}
.sir-b{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;background:#0a0c14;color:#fff;text-decoration:none;}
.sir-b.l{background:#f7f8fc;color:#475569;border:1.5px solid #e2e6f0;} .sir-b.g{background:#14532d;} .sir-b.bl{background:#1e40af;} .sir-b.r{background:#dc2626;}
.sir-b.sm{padding:5px 10px;font-size:12px;}
.sir-tabs{display:inline-flex;border:1.5px solid #e2e6f0;border-radius:9px;overflow:hidden;margin-bottom:14px;}
.sir-tabs a{padding:8px 18px;font-size:13px;font-weight:700;text-decoration:none;color:#475569;background:#fff;}
.sir-tabs a.on{background:#0a0c14;color:#fff;}
.sir-kpis{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px;}
.sir-kpi{background:#fff;border:1.5px solid #e2e6f0;border-radius:12px;padding:12px 18px;min-width:150px;}
.sir-kpi .l{font-size:10px;font-weight:700;color:#8892a4;text-transform:uppercase;} .sir-kpi .v{font-size:20px;font-weight:800;margin-top:2px;}
.sir-wrap{overflow:auto;border:1.5px solid #e2e6f0;border-radius:12px;background:#fff;}
table.sir-t{width:100%;border-collapse:collapse;font-size:12px;}
table.sir-t th{background:#0a0c14;color:#fff;padding:10px;text-align:left;font-size:10.5px;text-transform:uppercase;white-space:nowrap;}
table.sir-t td{padding:8px 10px;border-bottom:1px solid #eef0f6;white-space:nowrap;vertical-align:middle;}
table.sir-t .n{text-align:right;font-family:monospace;} table.sir-t .c{text-align:center;}
table.sir-t tfoot td{background:#f1f5f9;font-weight:800;}
.sir-pill{display:inline-block;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700;background:#dcfce7;color:#166534;}
.sir-pill.cancelled{background:#fee2e2;color:#991b1b;} .sir-pill.draft{background:#f3f4f6;color:#4b5563;} .sir-pill.cc_checked,.sir-pill.st_checked{background:#dbeafe;color:#1e40af;}
.sir-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;border-radius:8px;padding:10px 16px;margin-bottom:14px;font-size:13px;}
.sir-err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:10px 16px;margin-bottom:14px;font-size:13px;}
.sir-pag{display:flex;gap:6px;align-items:center;justify-content:center;margin:14px 0;flex-wrap:wrap;}
.sir-pag a,.sir-pag span{padding:6px 12px;border:1.5px solid #e2e6f0;border-radius:8px;font-size:12px;text-decoration:none;color:#475569;background:#fff;}
.sir-pag span.on{background:#0a0c14;color:#fff;border-color:#0a0c14;}
.sir-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;}
.sir-modal.open{display:flex;}
.sir-box{background:#fff;border-radius:14px;width:min(860px,94vw);max-height:86vh;display:flex;flex-direction:column;}
.sir-box header{padding:16px 20px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;}
.sir-box .body{padding:0 20px 18px;overflow:auto;}
.sir-x{border:none;background:#f3f4f6;border-radius:8px;width:32px;height:32px;cursor:pointer;font-size:16px;}
</style>

<div class="sir">
    <h2><i class="fa-solid fa-ban"></i> Sampath Item Rejection Entries</h2>
    <div class="sub">GRN rejection entries (the feed for Sampath Rejected Qty) with item totals and an optional supporting document per entry.</div>

    <?php if ($flash_ok): ?><div class="sir-ok"><?php echo htmlspecialchars($flash_ok); ?></div><?php endif; ?>
    <?php if ($flash_err): ?><div class="sir-err"><?php echo htmlspecialchars($flash_err); ?></div><?php endif; ?>
    <?php if (!$have_grn): ?><div class="sir-err">The GRN tables (grn_headers / grn_items) were not found on this database yet. They are created when the first GRN entry is saved in the GRN Create app.</div><?php endif; ?>
    <?php foreach ($errors as $er): ?><div class="sir-err"><?php echo htmlspecialchars($er); ?></div><?php endforeach; ?>

    <div class="sir-kpis">
        <div class="sir-kpi"><div class="l">Entries</div><div class="v"><?php echo sir_n($kpi['entries']); ?></div></div>
        <div class="sir-kpi"><div class="l">Rejected Qty</div><div class="v"><?php echo sir_n($kpi['qty']); ?></div></div>
        <div class="sir-kpi"><div class="l">Amount</div><div class="v">Rs. <?php echo number_format($kpi['amount'], 2); ?></div></div>
        <div class="sir-kpi"><div class="l">With Document</div><div class="v"><?php echo sir_n($kpi['docs']); ?> / <?php echo sir_n($kpi['entries']); ?></div></div>
    </div>

    <div class="sir-tabs">
        <a class="<?php echo $f_tab === 'entries' ? 'on' : ''; ?>" href="?<?php echo http_build_query($qs_base + ['tab' => 'entries']); ?>">Entries</a>
        <a class="<?php echo $f_tab === 'items' ? 'on' : ''; ?>" href="?<?php echo http_build_query($qs_base + ['tab' => 'items']); ?>">Item Summary</a>
    </div>

    <form method="GET" class="sir-card">
        <input type="hidden" name="tab" value="<?php echo $f_tab; ?>">
        <div class="sir-f">
            <div><label>Delivery Date From</label><input type="date" name="date_from" value="<?php echo htmlspecialchars($f_from); ?>"></div>
            <div><label>Delivery Date To</label><input type="date" name="date_to" value="<?php echo htmlspecialchars($f_to); ?>"></div>
            <div><label>Status</label>
                <select name="status"><option value="">All</option>
                <?php foreach ($statuses as $st): ?><option value="<?php echo htmlspecialchars($st); ?>" <?php echo $f_status === $st ? 'selected' : ''; ?>><?php echo htmlspecialchars($st); ?></option><?php endforeach; ?>
                </select></div>
            <div><label>Document</label>
                <select name="doc_filter"><option value="">All</option>
                    <option value="with" <?php echo $f_doc === 'with' ? 'selected' : ''; ?>>With document</option>
                    <option value="without" <?php echo $f_doc === 'without' ? 'selected' : ''; ?>>Without document</option></select></div>
            <div><label>Search</label><input type="text" name="search" value="<?php echo htmlspecialchars($f_search); ?>" placeholder="GRN ref / outlet / invoice / T code" style="min-width:230px"></div>
            <button class="sir-b" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
            <a class="sir-b l" href="sampath_item_rejection.php?tab=<?php echo $f_tab; ?>">Reset</a>
            <button class="sir-b g" type="button" onclick="sirExport()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
        </div>
    </form>

<?php if ($f_tab === 'entries'): ?>
    <form id="docForm" method="POST" enctype="multipart/form-data" style="display:none">
        <input type="hidden" name="action" value="upload_document">
        <input type="hidden" name="grn_id" id="docGrnId">
        <input type="hidden" name="qs" value="<?php echo htmlspecialchars($qs_now); ?>">
        <input type="file" name="document" id="docFile" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx" onchange="if(this.files.length) this.form.submit();">
    </form>
    <form id="docRemoveForm" method="POST" style="display:none">
        <input type="hidden" name="action" value="remove_document">
        <input type="hidden" name="grn_id" id="docRemoveId">
        <input type="hidden" name="qs" value="<?php echo htmlspecialchars($qs_now); ?>">
    </form>
    <div class="sir-wrap"><table class="sir-t">
        <thead><tr><th>GRN Ref</th><th>Delivery Date</th><th>Invoice No</th><th>Outlet</th><th>T Code</th><th class="n">Items</th><th class="n">Rejected Qty</th><th class="n">Amount</th><th>Status</th><th>Created By</th><th>Document</th><th class="c">Items</th></tr></thead>
        <tbody>
        <?php foreach ($entries as $r): $has = !empty($r['document_name']); ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($r['grn_ref']); ?></strong></td>
                <td><?php echo $r['delivery_date'] ? date('d M Y', strtotime($r['delivery_date'])) : '—'; ?></td>
                <td><?php echo htmlspecialchars($r['invoice_no'] ?? ''); ?></td>
                <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['outlet_name'] ?? ''); ?>"><?php echo htmlspecialchars($r['outlet_name'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($r['t_code'] ?? ''); ?></td>
                <td class="n"><?php echo sir_n($r['item_count']); ?></td>
                <td class="n"><strong><?php echo sir_n($r['rej_qty']); ?></strong></td>
                <td class="n"><?php echo number_format((float)$r['total_amount'], 2); ?></td>
                <td><span class="sir-pill <?php echo htmlspecialchars($r['status']); ?>"><?php echo htmlspecialchars($r['status']); ?></span></td>
                <td><?php echo htmlspecialchars($r['created_by_name'] ?? '—'); ?></td>
                <td>
                    <?php if ($has): ?>
                        <a href="?doc=<?php echo $r['id']; ?>" target="_blank" title="View"><i class="fa-solid fa-paperclip"></i> <?php echo htmlspecialchars(mb_strimwidth($r['document_name'], 0, 26, '…')); ?></a>
                        <a class="sir-b l sm" href="?doc=<?php echo $r['id']; ?>&download=1" title="Download"><i class="fa-solid fa-download"></i></a>
                        <button class="sir-b l sm" type="button" onclick="sirPick(<?php echo $r['id']; ?>)" title="Replace"><i class="fa-solid fa-arrows-rotate"></i></button>
                        <button class="sir-b r sm" type="button" onclick="sirRemove(<?php echo $r['id']; ?>)" title="Remove"><i class="fa-solid fa-trash"></i></button>
                    <?php else: ?>
                        <button class="sir-b bl sm" type="button" onclick="sirPick(<?php echo $r['id']; ?>)"><i class="fa-solid fa-upload"></i> Upload</button>
                    <?php endif; ?>
                </td>
                <td class="c"><button class="sir-b sm" type="button" onclick="sirItems(<?php echo $r['id']; ?>)"><i class="fa-solid fa-list"></i></button></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$entries): ?><tr><td colspan="12" style="text-align:center;padding:40px;color:#9ca3af;">No entries found.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?php if ($pages > 1):
        $base = '?' . http_build_query($qs_base + ['tab' => 'entries']) . '&page=';
    ?>
    <div class="sir-pag">
        <?php if ($page > 1): ?><a href="<?php echo $base . ($page - 1); ?>">‹ Prev</a><?php endif; ?>
        <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): echo $p == $page ? "<span class='on'>$p</span>" : "<a href='{$base}{$p}'>$p</a>"; endfor; ?>
        <?php if ($page < $pages): ?><a href="<?php echo $base . ($page + 1); ?>">Next ›</a><?php endif; ?>
        <span><?php echo number_format($total_entries); ?> entries</span>
    </div>
    <?php endif; ?>
<?php else: ?>
    <div class="sub" style="margin-top:-6px">Draft and cancelled entries are excluded from the item totals.</div>
    <div class="sir-wrap"><table class="sir-t">
        <thead><tr><th>Item Code</th><th>Product Name</th><th class="n">Entries</th><th class="n">Rejected Qty</th><th class="n">Amount</th></tr></thead>
        <tbody>
        <?php $tq = $ta = 0; foreach ($summary as $r): $tq += $r['qty']; $ta += $r['amt']; ?>
            <tr><td><strong><?php echo htmlspecialchars($r['code']); ?></strong></td><td><?php echo htmlspecialchars($r['name'] ?? ''); ?></td>
                <td class="n"><?php echo sir_n($r['n']); ?></td><td class="n"><?php echo sir_n($r['qty']); ?></td><td class="n"><?php echo number_format($r['amt'], 2); ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$summary): ?><tr><td colspan="5" style="text-align:center;padding:40px;color:#9ca3af;">No rejected items found.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr><td colspan="3" style="text-align:right;">TOTAL — <?php echo count($summary); ?> items</td><td class="n"><?php echo sir_n($tq); ?></td><td class="n"><?php echo number_format($ta, 2); ?></td></tr></tfoot>
    </table></div>
<?php endif; ?>
</div>

<div class="sir-modal" id="sirModal" onclick="if(event.target===this)sirClose()">
    <div class="sir-box">
        <header><div><div style="font-size:16px;font-weight:800" id="sirMTitle">Entry Items</div><div style="font-size:12px;color:#6b7280" id="sirMSub"></div></div>
            <button class="sir-x" type="button" onclick="sirClose()">&times;</button></header>
        <div class="body"><table class="sir-t" style="margin-top:12px">
            <thead><tr><th>Item Code</th><th>Product</th><th>Reason</th><th class="n">Qty</th><th class="n">TUR</th><th class="n">Amount</th></tr></thead>
            <tbody id="sirMBody"></tbody></table></div>
    </div>
</div>

<script>
const SIR_TAB = <?php echo json_encode($f_tab); ?>;
const SIR_ENTRIES = <?php echo json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
const SIR_SUMMARY = <?php echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fm = (v, d=0) => Number(v || 0).toLocaleString('en-US', {minimumFractionDigits:d, maximumFractionDigits:d});

function sirPick(id){ document.getElementById('docGrnId').value = id; const f = document.getElementById('docFile'); f.value = ''; f.click(); }
function sirRemove(id){ if (!confirm('Remove the attached document from this entry?')) return; document.getElementById('docRemoveId').value = id; document.getElementById('docRemoveForm').submit(); }
function sirClose(){ document.getElementById('sirModal').classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') sirClose(); });
function sirItems(id){
    document.getElementById('sirMTitle').textContent = 'Entry Items'; document.getElementById('sirMSub').textContent = 'Loading…';
    document.getElementById('sirMBody').innerHTML = ''; document.getElementById('sirModal').classList.add('open');
    fetch('sampath_item_rejection.php?ajax=items&grn_id=' + id).then(r => r.json()).then(d => {
        const h = d.header || {};
        document.getElementById('sirMTitle').textContent = 'Entry ' + (h.grn_ref || '');
        document.getElementById('sirMSub').textContent = [h.outlet_name, h.invoice_no && ('Invoice ' + h.invoice_no), h.delivery_date, h.status].filter(Boolean).join(' · ');
        document.getElementById('sirMBody').innerHTML = d.items.length ? d.items.map(i =>
            `<tr><td><strong>${esc(i.product_code)}</strong></td><td>${esc(i.product_description)}</td><td>${esc(i.reason_name)}</td>
             <td class="n">${fm(i.qty)}</td><td class="n">${i.tur === null ? '—' : fm(i.tur, 2)}</td><td class="n">${i.total_amount === null ? '—' : fm(i.total_amount, 2)}</td></tr>`).join('')
            : '<tr><td colspan="6" style="text-align:center;padding:20px;color:#9ca3af">No items.</td></tr>';
    }).catch(e => { document.getElementById('sirMSub').textContent = 'Failed to load: ' + e.message; });
}

async function sirExport(){
    const wb = new ExcelJS.Workbook(), ws = wb.addWorksheet(SIR_TAB === 'items' ? 'Item Summary' : 'Entries');
    const head = SIR_TAB === 'items'
        ? ['Item Code','Product Name','Entries','Rejected Qty','Amount']
        : ['GRN Ref','Delivery Date','Invoice No','Outlet','T Code','Items','Rejected Qty','Amount','Status','Created By','Document'];
    const h = ws.addRow(head); h.eachCell(c => { c.font = {bold:true}; c.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FFFBE5D6'}}; });
    if (SIR_TAB === 'items') SIR_SUMMARY.forEach(r => ws.addRow([r.code, r.name, r.n, r.qty, r.amt]));
    else SIR_ENTRIES.forEach(r => ws.addRow([r.grn_ref, r.delivery_date, r.invoice_no, r.outlet_name, r.t_code, +r.item_count, +r.rej_qty, +r.total_amount, r.status, r.created_by_name || '', r.document_name || '']));
    ws.columns.forEach(c => c.width = 18);
    const buf = await wb.xlsx.writeBuffer();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([buf], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));
    a.download = 'sampath_item_rejection_' + SIR_TAB + '_' + new Date().toISOString().slice(0,10) + '.xlsx'; a.click(); URL.revokeObjectURL(a.href);
}
</script>
<?php include 'footer.php'; ?>
