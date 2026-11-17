<?php
/**
 * customer_claim_report.php
 * "CC Report" — Customer Claim Certificate Report
 * Shows ALL imported claim_cert_items with date filters, search, customer/entity/status filters,
 * KPI summary, and Excel export.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
include_once 'config.php';

function ensure_claim_tables_r($conn) {
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS claim_cert_uploads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_name VARCHAR(255) NOT NULL,
        customer_code VARCHAR(50) DEFAULT NULL,
        customer_name VARCHAR(255) DEFAULT NULL,
        total_rows INT DEFAULT 0,
        imported INT DEFAULT 0,
        skipped_dup INT DEFAULT 0,
        upload_date DATE DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_customer(customer_code),
        INDEX idx_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS claim_cert_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        upload_id INT NOT NULL,
        row_no INT DEFAULT NULL,
        customer_code VARCHAR(50) DEFAULT NULL,
        customer_name VARCHAR(255) DEFAULT NULL,
        ledger_type VARCHAR(100) DEFAULT NULL,
        status VARCHAR(100) DEFAULT NULL,
        claim_type VARCHAR(100) DEFAULT NULL,
        tax_invoice_no VARCHAR(100) DEFAULT NULL,
        invoice_date DATE DEFAULT NULL,
        banking_date DATE DEFAULT NULL,
        entity VARCHAR(50) DEFAULT NULL,
        claim_description TEXT DEFAULT NULL,
        actual_amount DECIMAL(18,4) DEFAULT 0,
        vat_amount DECIMAL(18,4) DEFAULT 0,
        total_amount DECIMAL(18,4) DEFAULT 0,
        imported_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        imported_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_upload(upload_id),
        INDEX idx_tax_inv(tax_invoice_no),
        INDEX idx_customer(customer_code),
        UNIQUE KEY uniq_tax_inv_entity(tax_invoice_no, entity)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* Build WHERE clause shared by report + export */
/**
 * Scheme no of a claim description, e.g.
 *   "41429551-B483131026-SR Advane - Sep 26"            → 41429551 (only the first part has a number)          → source F
 *   "41448039-B505221026-41444095Vim Dishwash Bar 3 Ban" → 41444095 (the middle number differs from the first)   → source M
 *   first and middle number the same                     → that number                                         → source M
 * The "middle" number = a 6+ digit number after the first "-" that is not part of a batch code (B483131026).
 * Returns [scheme_no, 'F'|'M'|''].
 */
function cc_scheme_of($desc) {
    $desc = (string)$desc; $first = ''; $mid = '';
    $dash = strpos($desc, '-');
    $head = $dash === false ? $desc : substr($desc, 0, $dash);
    if (preg_match('/^\s*(\d{6,})\s*$/', $head, $m)) $first = $m[1];
    elseif (preg_match('/^\s*(\d{6,})/', $head, $m)) $first = $m[1];
    $rest = $dash === false ? '' : substr($desc, $dash + 1);
    if ($rest !== '' && preg_match('/(?<![A-Za-z0-9])(\d{6,})/', $rest, $m)) $mid = $m[1];
    if ($first !== '' && $mid !== '') return [$mid, 'M'];          // different → the middle one; same → "normal" → M
    if ($first !== '') return [$first, 'F'];                       // only the first part has a number
    if ($mid !== '') return [$mid, 'M'];
    return ['', ''];
}

function cc_build_where($conn) {
    $w = [];

    $dateField = $_REQUEST['date_field'] ?? 'imported_at';
    $allowedFields = ['invoice_date', 'banking_date', 'imported_at', 'upload_date'];
    if (!in_array($dateField, $allowedFields, true)) $dateField = 'imported_at';
    $col = $dateField === 'upload_date' ? 'cu.upload_date' : 'ci.' . $dateField;

    $from = trim($_REQUEST['date_from'] ?? '');
    $to   = trim($_REQUEST['date_to']   ?? '');
    if ($from !== '') {
        $from_e = mysqli_real_escape_string($conn, $from);
        $w[] = "$col >= '$from_e" . ($dateField === 'imported_at' ? ' 00:00:00' : '') . "'";
    }
    if ($to !== '') {
        $to_e = mysqli_real_escape_string($conn, $to);
        $w[] = "$col <= '$to_e" . ($dateField === 'imported_at' ? ' 23:59:59' : '') . "'";
    }

    $search = trim($_REQUEST['search'] ?? '');
    if ($search !== '') {
        $s_e = mysqli_real_escape_string($conn, $search);
        $w[] = "(ci.tax_invoice_no LIKE '%$s_e%' OR ci.claim_description LIKE '%$s_e%' " .
               "OR ci.customer_name LIKE '%$s_e%' OR ci.customer_code LIKE '%$s_e%' " .
               "OR cu.file_name LIKE '%$s_e%')";
    }

    $cust = trim($_REQUEST['customer_code'] ?? '');
    if ($cust !== '') { $c_e = mysqli_real_escape_string($conn, $cust); $w[] = "ci.customer_code='$c_e'"; }

    $entity = trim($_REQUEST['entity'] ?? '');
    if ($entity !== '') { $e_e = mysqli_real_escape_string($conn, $entity); $w[] = "ci.entity='$e_e'"; }

    $status = trim($_REQUEST['status'] ?? '');
    if ($status !== '') { $st_e = mysqli_real_escape_string($conn, $status); $w[] = "ci.status='$st_e'"; }

    $claimTypes = array_values(array_filter(array_map('trim', explode('||', (string)($_REQUEST['claim_type'] ?? ''))), fn($v) => $v !== ''));
    if ($claimTypes) $w[] = "ci.claim_type IN ('" . implode("','", array_map(fn($v) => mysqli_real_escape_string($conn, $v), $claimTypes)) . "')";

    $uploadId = trim($_REQUEST['upload_id'] ?? '');
    if ($uploadId !== '' && ctype_digit($uploadId)) { $w[] = "ci.upload_id=" . intval($uploadId); }

    return $w ? ('WHERE ' . implode(' AND ', $w)) : '';
}

/* ══ SSCL & VAT: "Send to SSCL & VAT" puts the claim certificate on the SSCL & VAT page's claim list (table sscl_vat_claim_list);
   there it is picked as a matched certificate when the payment is entered, as normal. Matches already made are read from sscl_vat_settlements. */
function cc_sv_tables($conn) {
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sscl_vat (id INT AUTO_INCREMENT PRIMARY KEY, paid_date DATE NULL, tax_type VARCHAR(255) NULL, description VARCHAR(500) NULL, paid_amount DECIMAL(14,2) NULL, vat DECIMAL(14,2) NULL,
        total_amount DECIMAL(14,2) NULL, document_path VARCHAR(500) NULL, document_name VARCHAR(255) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP)");
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sscl_vat_settlements (id INT AUTO_INCREMENT PRIMARY KEY, sv_id INT NOT NULL, tax_invoice_no VARCHAR(255) NULL, banking_date DATE NULL, bank_ref VARCHAR(255) NULL,
        amount DECIMAL(14,2) NULL DEFAULT 0, vat_amount DECIMAL(14,2) NULL DEFAULT 0, total_amount DECIMAL(14,2) NULL DEFAULT 0, claim_description VARCHAR(500) NULL, entity VARCHAR(255) NULL, claim_cert_item_id INT NULL,
        document_path VARCHAR(500) NULL, document_name VARCHAR(255) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_sv (sv_id))");
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sscl_vat_claim_list (claim_cert_item_id INT NOT NULL PRIMARY KEY, sent_by VARCHAR(100) NULL, sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
}
function cc_sv_can($conn) { if (!function_exists('rbac_can')) @include_once __DIR__ . '/rbac.php'; return function_exists('rbac_can') ? (rbac_can('create', 'sscl_vat') || rbac_can('edit', 'sscl_vat')) : true; }
function cc_sv_is_tax($r) { $t = strtoupper(($r['claim_type'] ?? '') . ' ' . ($r['claim_description'] ?? '')); return strpos($t, 'SSCL') !== false || strpos($t, 'VAT') !== false; }
/* per claim: what is already matched on SSCL & VAT payments + which payment(s) */
function cc_sv_links($conn, array $ids) {
    $out = []; $ids = array_values(array_filter(array_map('intval', $ids))); if (!$ids) return $out;
    cc_sv_tables($conn); $in = implode(',', $ids);
    $q = @mysqli_query($conn, "SELECT s.claim_cert_item_id AS cid, s.sv_id, SUM(COALESCE(s.total_amount, s.amount, 0)) AS amt, MAX(v.paid_date) AS pd, MAX(v.tax_type) AS tt
                               FROM sscl_vat_settlements s LEFT JOIN sscl_vat v ON v.id = s.sv_id WHERE s.claim_cert_item_id IN ($in) GROUP BY s.claim_cert_item_id, s.sv_id");
    while ($q && ($x = mysqli_fetch_assoc($q))) { $c = (int)$x['cid']; $out[$c]['settled'] = ($out[$c]['settled'] ?? 0) + (float)$x['amt']; $out[$c]['pays'][] = ['id' => (int)$x['sv_id'], 'amt' => round((float)$x['amt'], 2), 'pd' => $x['pd'], 'tt' => $x['tt']]; }
    $q = @mysqli_query($conn, "SELECT claim_cert_item_id AS cid, sent_at FROM sscl_vat_claim_list WHERE claim_cert_item_id IN ($in)");
    while ($q && ($x = mysqli_fetch_assoc($q))) $out[(int)$x['cid']]['listed'] = $x['sent_at'];
    $t = @mysqli_query($conn, "SHOW TABLES LIKE 'dl_cheque_acknowledgments'");
    if ($t && mysqli_num_rows($t)) { $q = @mysqli_query($conn, "SELECT claim_item_id AS cid, confirmed_payment_id AS sv FROM dl_cheque_acknowledgments WHERE source = 'ssclvat_claim' AND claim_item_id IN ($in)");
        while ($q && ($x = mysqli_fetch_assoc($q))) $out[(int)$x['cid']]['ack'] = (int)$x['sv']; }
    return $out;
}
function cc_sv_user() { return $_SESSION['username'] ?? ($_SESSION['user_name'] ?? ($_SESSION['full_name'] ?? 'system')); }
if (isset($_REQUEST['ajax_action']) && $_REQUEST['ajax_action'] === 'sv_list' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start(); header('Content-Type: application/json'); cc_sv_tables($conn);
    $fail = function ($m) { ob_end_clean(); echo json_encode(['success' => false, 'message' => $m]); exit; };
    if (!cc_sv_can($conn)) $fail('You do not have the right to add to the SSCL & VAT page.');
    $ids = array_values(array_unique(array_filter(array_map('intval', json_decode($_POST['ids'] ?? '[]', true) ?: [])))); if (!$ids) $fail('Select at least one claim.');
    $L = cc_sv_links($conn, $ids); $u = mysqli_real_escape_string($conn, cc_sv_user()); $n = 0; $skip = 0;
    $q = mysqli_query($conn, "SELECT id, total_amount FROM claim_cert_items WHERE id IN (" . implode(',', $ids) . ")");
    while ($q && ($x = mysqli_fetch_assoc($q))) { $c = (int)$x['id'];
        if (!empty($L[$c]['listed']) || ((float)$x['total_amount'] - ($L[$c]['settled'] ?? 0)) <= 0.004) { $skip++; continue; }
        if (mysqli_query($conn, "INSERT IGNORE INTO sscl_vat_claim_list (claim_cert_item_id, sent_by) VALUES ($c, '$u')")) $n += mysqli_affected_rows($conn); }
    ob_end_clean(); echo json_encode(['success' => true, 'message' => $n . ' claim(s) sent to the SSCL & VAT list' . ($skip ? ' (' . $skip . ' already there or fully matched)' : '') . '. Pick them on the SSCL & VAT page when you enter the payment.']); exit;
}
if (isset($_REQUEST['ajax_action']) && $_REQUEST['ajax_action'] === 'sv_unlist' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start(); header('Content-Type: application/json'); cc_sv_tables($conn);
    $fail = function ($m) { ob_end_clean(); echo json_encode(['success' => false, 'message' => $m]); exit; };
    if (!cc_sv_can($conn)) $fail('You do not have the right to change the SSCL & VAT list.');
    $c = intval($_POST['id'] ?? 0); if (!$c) $fail('Nothing to reverse.'); $L = cc_sv_links($conn, [$c]);
    if (!empty($L[$c]['pays'])) $fail('This claim is already matched on SSCL & VAT payment #' . implode(', #', array_column($L[$c]['pays'], 'id')) . ' — remove that payment line on the SSCL & VAT page first.');
    if (!empty($L[$c]['ack'])) $fail('This claim was sent to Acknowledgment from SSCL & VAT payment #' . $L[$c]['ack'] . ' — reverse it there first.');
    $ok = mysqli_query($conn, "DELETE FROM sscl_vat_claim_list WHERE claim_cert_item_id = $c");
    ob_end_clean(); echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Reversed — the claim is removed from the SSCL & VAT list.' : mysqli_error($conn)]); exit;
}

/* ── AJAX: get_report_data (paginated) ── */
if (isset($_REQUEST['ajax_action']) && $_REQUEST['ajax_action'] === 'get_report_data') {
    ob_start(); header('Content-Type: application/json');
    ensure_claim_tables_r($conn);

    $where = cc_build_where($conn);
    $page  = max(1, intval($_REQUEST['page'] ?? 1));
    $per   = max(1, min(2000, intval($_REQUEST['per_page'] ?? 100)));
    $off   = ($page - 1) * $per;

    $sortField = $_REQUEST['sort'] ?? 'imported_at';
    $allowedSort = ['imported_at','invoice_date','banking_date','tax_invoice_no','actual_amount','vat_amount','total_amount','customer_code'];
    if (!in_array($sortField, $allowedSort, true)) $sortField = 'imported_at';
    $sortDir = (strtoupper($_REQUEST['dir'] ?? 'DESC') === 'ASC') ? 'ASC' : 'DESC';

    $baseSql = "FROM claim_cert_items ci LEFT JOIN claim_cert_uploads cu ON ci.upload_id = cu.id $where";

    $cntRes = mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(SUM(ci.actual_amount),0) sa,
                                            COALESCE(SUM(ci.vat_amount),0) sv, COALESCE(SUM(ci.total_amount),0) st,
                                            COUNT(DISTINCT ci.customer_code) cust_n,
                                            COUNT(DISTINCT ci.upload_id) upload_n
                                     $baseSql");
    $summary = $cntRes ? mysqli_fetch_assoc($cntRes) : ['c'=>0,'sa'=>0,'sv'=>0,'st'=>0,'cust_n'=>0,'upload_n'=>0];

    $dataRes = mysqli_query($conn, "SELECT ci.*, cu.file_name, cu.upload_date
                                     $baseSql
                                     ORDER BY ci.$sortField $sortDir
                                     LIMIT $per OFFSET $off");
    $rows = [];
    if ($dataRes) while ($r = mysqli_fetch_assoc($dataRes)) { [$r['scheme_no'], $r['scheme_src']] = cc_scheme_of($r['claim_description']); $rows[] = $r; }
    $L = cc_sv_links($conn, array_column($rows, 'id'));
    foreach ($rows as &$r) { $c = (int)$r['id']; $r['sv_settled'] = round($L[$c]['settled'] ?? 0, 2); $r['sv_pays'] = $L[$c]['pays'] ?? []; $r['sv_ack'] = $L[$c]['ack'] ?? 0; $r['sv_listed'] = $L[$c]['listed'] ?? ''; $r['sv_tax'] = cc_sv_is_tax($r) ? 1 : 0; } unset($r);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'rows'    => $rows,
        'summary' => $summary,
        'page'    => $page,
        'per_page'=> $per,
        'total'   => intval($summary['c']),
        'pages'   => max(1, ceil(intval($summary['c']) / $per)),
    ]);
    exit;
}

/* ── Excel export: EVERY row of the table (all pages, current filters), built on the server — no row limit, no browser tool needed ── */
if (isset($_GET['export_xlsx'])) {
    ensure_claim_tables_r($conn);
    if (!class_exists('ZipArchive')) { while (ob_get_level()) ob_end_clean(); header('Content-Type: text/plain'); exit('The PHP zip extension is required for Excel export.'); }
    @set_time_limit(300); @ini_set('memory_limit', '1024M');
    require_once __DIR__ . '/rbac_xlsx.php';
    $where = cc_build_where($conn);
    $res = mysqli_query($conn, "SELECT ci.*, cu.file_name, cu.upload_date FROM claim_cert_items ci LEFT JOIN claim_cert_uploads cu ON ci.upload_id = cu.id $where ORDER BY ci.imported_at DESC, ci.id DESC", MYSQLI_USE_RESULT);
    $X = new MiniXlsx(); $sh = $X->sheet('CC Report');
    $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '312E81', 'h' => 'center', 'border' => 1, 'wrap' => 1];
    $heads = ['#', 'Customer Code', 'Customer Name', 'Upload File', 'Upload Date', 'Ledger Type', 'Status', 'Claim Type', 'Tax Invoice No', 'Invoice Date', 'Banking Date', 'Entity', 'Claim Description', 'Actual Amount', 'VAT Amount', 'Total Amount', 'Imported At', 'Imported By', 'Scheme No Source (F = first no · M = middle / normal)', 'Scheme No'];
    $sh->set(1, 1, 'Customer Claim Report — all rows of the table' . ((trim($_REQUEST['date_from'] ?? '') . trim($_REQUEST['date_to'] ?? '')) !== '' ? ' (' . trim($_REQUEST['date_from'] ?? '…') . ' to ' . trim($_REQUEST['date_to'] ?? '…') . ')' : ''), ['b' => 1, 'sz' => 14]);
    $fl = [];
    foreach (['claim_type' => 'Claim type', 'entity' => 'Entity', 'status' => 'Status', 'customer_code' => 'Customer', 'search' => 'Search'] as $k => $lab) { $v = trim((string)($_REQUEST[$k] ?? '')); if ($v !== '') $fl[] = $lab . ': ' . str_replace('||', ', ', $v); }
    $sh->set(2, 1, $fl ? 'Filters — ' . implode(' · ', $fl) : 'No filters — all rows', ['i' => 1, 'color' => '6B7280']);
    foreach ($heads as $i => $h) $sh->set(3, $i + 1, $h, $H);
    $r = 4; $n = 0; $ta = $tv = $tt = 0.0; $byType = [];
    if ($res) while ($x = mysqli_fetch_assoc($res)) {
        $n++; $desc = (string)$x['claim_description']; [$sn, $src] = cc_scheme_of($desc);
        $a = (float)$x['actual_amount']; $v = (float)$x['vat_amount']; $t = (float)$x['total_amount']; $ta += $a; $tv += $v; $tt += $t;
        $tk = trim((string)$x['claim_type']) ?: '(no type)'; if (!isset($byType[$tk])) $byType[$tk] = [0, 0.0, 0.0, 0.0]; $byType[$tk][0]++; $byType[$tk][1] += $a; $byType[$tk][2] += $v; $byType[$tk][3] += $t;
        $vals = [$n, $x['customer_code'], $x['customer_name'], $x['file_name'], $x['upload_date'], $x['ledger_type'], $x['status'], $x['claim_type'], $x['tax_invoice_no'], $x['invoice_date'], $x['banking_date'], $x['entity'], $desc, $a, $v, $t, $x['imported_at'], $x['imported_by'], $src, $sn];
        foreach ($vals as $i => $val) $sh->set($r, $i + 1, $val === null ? '' : $val, ['border' => 1] + (in_array($i, [13, 14, 15], true) ? ['h' => 'right', 'nf' => 4] : []) + ($i === 17 ? ['b' => 1, 'color' => '312E81'] : []) + ($i === 16 ? ['h' => 'center', 'b' => 1, 'color' => $src === 'F' ? 'B45309' : '15803D'] : []));
        $r++;
    }
    if ($res instanceof mysqli_result) mysqli_free_result($res);
    $tb = ['b' => 1, 'border' => 1, 'bg' => 'FDE68A', 'h' => 'right', 'nf' => 4];
    $sh->set($r, 13, 'TOTAL (' . number_format($n) . ' rows)', ['b' => 1, 'border' => 1, 'bg' => 'FDE68A']); $sh->set($r, 14, round($ta, 2), $tb); $sh->set($r, 15, round($tv, 2), $tb); $sh->set($r, 16, round($tt, 2), $tb);
    foreach ([5, 14, 24, 24, 12, 14, 12, 16, 18, 12, 12, 10, 44, 14, 12, 14, 18, 14, 18, 16] as $i => $w) $sh->widths[$i + 1] = $w;
    $sh->freeze = [3, 0]; $sh->filter = 'A3:' . MiniXlsx::col(count($heads)) . max(4, $r - 1);
    /* second sheet: totals per claim type (of the filtered rows) */
    $s2 = $X->sheet('By Claim Type'); $s2->set(1, 1, 'Totals by claim type' . ($fl ? ' — ' . implode(' · ', $fl) : ''), ['b' => 1, 'sz' => 14]);
    foreach (['Claim Type', 'Rows', 'Actual Amount', 'VAT Amount', 'Total Amount'] as $i => $h) $s2->set(3, $i + 1, $h, $H);
    ksort($byType); $r2 = 4; foreach ($byType as $tk => $v) { $s2->set($r2, 1, $tk, ['border' => 1]); $s2->set($r2, 2, $v[0], ['border' => 1, 'h' => 'right']); foreach ([1, 2, 3] as $q) $s2->set($r2, $q + 2, round($v[$q], 2), ['border' => 1, 'h' => 'right', 'nf' => 4]); $r2++; }
    $s2->set($r2, 1, 'TOTAL', ['b' => 1, 'border' => 1, 'bg' => 'FDE68A']); $s2->set($r2, 2, $n, ['b' => 1, 'border' => 1, 'bg' => 'FDE68A', 'h' => 'right']); foreach ([[3, $ta], [4, $tv], [5, $tt]] as [$q, $val]) $s2->set($r2, $q, round($val, 2), $tb);
    foreach ([30, 10, 18, 16, 18] as $i => $w) $s2->widths[$i + 1] = $w; $s2->freeze = [3, 0];
    $tmp = tempnam(sys_get_temp_dir(), 'cc'); $X->save($tmp);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="CC_Report_' . date('Ymd_His') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}

/* ── AJAX: export_report (no pagination — full filtered set) ── */
if (isset($_REQUEST['ajax_action']) && $_REQUEST['ajax_action'] === 'export_report') {
    ob_start(); header('Content-Type: application/json');
    ensure_claim_tables_r($conn);
    $where = cc_build_where($conn);
    $res = mysqli_query($conn, "SELECT ci.*, cu.file_name, cu.upload_date
                                 FROM claim_cert_items ci LEFT JOIN claim_cert_uploads cu ON ci.upload_id = cu.id
                                 $where ORDER BY ci.imported_at DESC LIMIT 50000");
    $rows = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) { [$r['scheme_no'], $r['scheme_src']] = cc_scheme_of($r['claim_description']); $rows[] = $r; }
    ob_end_clean();
    echo json_encode(['success'=>true,'rows'=>$rows]);
    exit;
}

/* ── AJAX: get_filter_options ── */
if (isset($_REQUEST['ajax_action']) && $_REQUEST['ajax_action'] === 'get_filter_options') {
    ob_start(); header('Content-Type: application/json');
    ensure_claim_tables_r($conn);
    $custRes   = mysqli_query($conn, "SELECT DISTINCT customer_code, customer_name FROM claim_cert_items WHERE customer_code<>'' ORDER BY customer_name ASC");
    $entRes    = mysqli_query($conn, "SELECT DISTINCT entity FROM claim_cert_items WHERE entity<>'' ORDER BY entity ASC");
    $statRes   = mysqli_query($conn, "SELECT DISTINCT status FROM claim_cert_items WHERE status<>'' ORDER BY status ASC");
    $typeRes   = mysqli_query($conn, "SELECT DISTINCT claim_type FROM claim_cert_items WHERE claim_type<>'' ORDER BY claim_type ASC");
    $customers = []; while ($r = mysqli_fetch_assoc($custRes)) $customers[] = $r;
    $entities  = []; while ($r = mysqli_fetch_assoc($entRes))  $entities[]  = $r['entity'];
    $statuses  = []; while ($r = mysqli_fetch_assoc($statRes)) $statuses[]  = $r['status'];
    $types     = []; while ($r = mysqli_fetch_assoc($typeRes)) $types[]     = $r['claim_type'];
    ob_end_clean();
    echo json_encode(['success'=>true,'customers'=>$customers,'entities'=>$entities,'statuses'=>$statuses,'claim_types'=>$types]);
    exit;
}

include 'header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
:root{
  --ink:#0f172a;--ink2:#1e293b;--ink3:#334155;--muted:#64748b;
  --lite:#f8fafc;--card:#ffffff;--bdr:#e2e8f0;
  --pri:#1e3a5f;--pri2:#2563eb;--teal:#0d9488;
  --green:#16a34a;--red:#dc2626;--amber:#d97706;--violet:#7c3aed;
  --shadow:0 1px 3px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.05);
}
.ccr-wrap{width:100%;margin:0;padding:20px 12px 80px;font-family:'Segoe UI',system-ui,sans-serif;}

.ccr-hero{background:linear-gradient(135deg,#0f3460 0%,#16213e 50%,#0a1628 100%);border-radius:16px;padding:20px 26px;display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin-bottom:20px;position:relative;overflow:hidden;}
.ccr-hero::before{content:'';position:absolute;top:-40px;right:-60px;width:240px;height:240px;border-radius:50%;background:rgba(99,102,241,.15);pointer-events:none;}
.hero-icon{width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;flex-shrink:0;border:1px solid rgba(255,255,255,.18);}
.hero-title{color:#fff;font-size:20px;font-weight:800;line-height:1.2;}
.hero-sub{color:rgba(255,255,255,.65);font-size:12px;margin-top:3px;}
.hero-right{margin-left:auto;display:flex;align-items:center;gap:10px;flex-wrap:wrap;position:relative;z-index:1;}

.kpi-strip{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:16px;}
@media(max-width:980px){.kpi-strip{grid-template-columns:repeat(3,1fr);}}
@media(max-width:520px){.kpi-strip{grid-template-columns:repeat(2,1fr);}}
.kpi-mini{background:var(--card);border:1.5px solid var(--bdr);border-radius:12px;padding:12px 14px;box-shadow:var(--shadow);}
.kpi-mini .km-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:4px;}
.kpi-mini .km-val{font-size:19px;font-weight:900;color:var(--ink);}
.kpi-mini .km-val.green{color:var(--green);}
.kpi-mini .km-val.amber{color:var(--amber);}
.kpi-mini .km-val.violet{color:var(--violet);}
.kpi-mini .km-val.sky{color:#0284c7;}

.rc-card{background:var(--card);border:1.5px solid var(--bdr);border-radius:14px;box-shadow:var(--shadow);overflow:hidden;}
.rc-card-hdr{padding:14px 20px;border-bottom:1.5px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:linear-gradient(to right,#fafbff,#fff);}
.rc-card-title{font-size:14px;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:8px;}
.rc-card-body{padding:16px 18px;}

.filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:12px;}
.f-field{display:flex;flex-direction:column;gap:4px;}
.f-field label{font-size:10.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;}
.f-field input,.f-field select{border:1.5px solid var(--bdr);border-radius:8px;padding:8px 10px;font-size:12.5px;font-family:inherit;outline:none;color:var(--ink);background:#fff;}
.f-field input:focus,.f-field select:focus{border-color:var(--pri2);}
.ms{position:relative}.ms-btn{width:100%;text-align:left;border:1.5px solid var(--bdr);border-radius:8px;padding:8px 10px;font-size:12.5px;font-family:inherit;background:#fff;color:var(--ink);cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ms-panel{display:none;position:fixed;z-index:2147483000;max-height:300px;overflow:auto;background:#fff;border:1.5px solid var(--bdr);border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,.15);padding:6px;margin-top:3px}.ms.open .ms-panel{display:block}
.ms-panel label{display:flex;gap:7px;align-items:center;padding:5px 7px;border-radius:6px;font-size:12.5px;text-transform:none;letter-spacing:0;font-weight:500;color:var(--ink);cursor:pointer;white-space:nowrap}.ms-panel label:hover{background:#eef2ff}.ms-panel .ms-clear{font-size:11px;color:#6366f1;cursor:pointer;padding:3px 7px;display:block}
.filter-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}

.btn-primary{display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,#4f46e5,#6366f1);color:#fff;border:none;border-radius:9px;padding:9px 18px;font-size:12.5px;font-weight:800;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(99,102,241,.3);}
.btn-primary:hover{filter:brightness(1.08);}
.btn-secondary{background:var(--lite);border:1.5px solid var(--bdr);border-radius:9px;padding:8px 16px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit;color:var(--ink3);}
.btn-secondary:hover{background:#e2e8f0;}
.btn-success{display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,#15803d,var(--green));color:#fff;border:none;border-radius:9px;padding:9px 18px;font-size:12.5px;font-weight:800;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(22,163,74,.3);}
.btn-success:hover{filter:brightness(1.08);}

.prev-outer{border:1.5px solid var(--bdr);border-radius:10px;overflow:hidden;}
.prev-scroll{overflow-x:auto;max-height:600px;overflow-y:auto;}
.prev-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1300px;}
.prev-table thead th{padding:9px 11px;background:#0f172a;color:#e2e8f0;font-size:10.5px;font-weight:700;text-align:left;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);position:sticky;top:0;z-index:5;cursor:pointer;user-select:none;}
.prev-table thead th.tr{text-align:right;}
.prev-table thead th:hover{background:#1e293b;}
.prev-table thead th .sort-ic{opacity:.5;font-size:9px;margin-left:3px;}
.prev-table tbody tr{border-bottom:1px solid #f1f5f9;}
.prev-table tbody tr:hover td{background:#f8faff !important;}
.prev-table td{padding:8px 11px;vertical-align:middle;background:#fff;}
.tr{text-align:right;}
.tc{text-align:center;}

.pill{display:inline-flex;align-items:center;gap:3px;padding:2px 9px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}
.p-blue{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;}
.p-green{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.p-red{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
.p-violet{background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe;}
.p-gray{background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;}
.p-teal{background:#ccfbf1;color:#065f46;border:1px solid #5eead4;}
.p-amber{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}

.pager{display:flex;align-items:center;gap:10px;justify-content:flex-end;padding:12px 16px;flex-wrap:wrap;}
.pager select{border:1.5px solid var(--bdr);border-radius:7px;padding:6px 10px;font-size:12px;font-family:inherit;}
.pager button{background:#fff;border:1.5px solid var(--bdr);border-radius:7px;padding:6px 12px;font-size:12px;font-weight:700;cursor:pointer;color:var(--ink3);}
.pager button:disabled{opacity:.4;cursor:not-allowed;}
.pager button:hover:not(:disabled){background:#f1f5f9;}
.pager .pg-info{font-size:12px;color:var(--muted);font-weight:600;}

#toast{position:fixed;bottom:30px;right:26px;background:#166534;color:#fff;padding:12px 22px;border-radius:12px;font-size:13px;font-weight:700;z-index:9999;opacity:0;pointer-events:none;transition:opacity .3s;max-width:360px;}
#toast.show{opacity:1;}
#toast.err{background:var(--red);}
</style>

<div class="ccr-wrap">
  <div class="ccr-hero">
    <div class="hero-icon"><i class="fa-solid fa-chart-line"></i></div>
    <div style="position:relative;z-index:1;">
      <div class="hero-title">Customer Claim Report <span style="font-weight:500;opacity:.7;">(CC Report)</span></div>
      <div class="hero-sub">All imported claim certificate data · Date filters · Search · Export to Excel</div>
    </div>
    <div class="hero-right">
      <button class="btn-success" onclick="exportReport()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
    </div>
  </div>

  <div class="kpi-strip">
    <div class="kpi-mini"><div class="km-lbl">Records</div><div class="km-val sky" id="kpiCount">—</div></div>
    <div class="kpi-mini"><div class="km-lbl">Customers</div><div class="km-val violet" id="kpiCust">—</div></div>
    <div class="kpi-mini"><div class="km-lbl">Uploads</div><div class="km-val" id="kpiUploads">—</div></div>
    <div class="kpi-mini"><div class="km-lbl">Actual Amt</div><div class="km-val green" id="kpiActual">—</div></div>
    <div class="kpi-mini"><div class="km-lbl">VAT Amt</div><div class="km-val amber" id="kpiVat">—</div></div>
    <div class="kpi-mini"><div class="km-lbl">Total Amt</div><div class="km-val" id="kpiTotal">—</div></div>
  </div>

  <div class="rc-card" style="margin-bottom:16px;">
    <div class="rc-card-hdr">
      <div class="rc-card-title"><i class="fa-solid fa-filter"></i> Filters</div>
      <div class="filter-actions">
        <button class="btn-secondary" onclick="clearFilters()"><i class="fa-solid fa-rotate-left"></i> Clear</button>
        <button class="btn-primary" onclick="loadReport(1)"><i class="fa-solid fa-magnifying-glass"></i> Apply</button>
      </div>
    </div>
    <div class="rc-card-body">
      <div class="filter-grid">
        <div class="f-field">
          <label>Date Field</label>
          <select id="fDateField">
            <option value="imported_at">Import Date</option>
            <option value="invoice_date">Invoice Date</option>
            <option value="banking_date">Banking Date</option>
            <option value="upload_date">Upload Date</option>
          </select>
        </div>
        <div class="f-field"><label>From</label><input type="date" id="fFrom"></div>
        <div class="f-field"><label>To</label><input type="date" id="fTo"></div>
        <div class="f-field" style="grid-column:span 2;">
          <label>Search</label>
          <input type="text" id="fSearch" placeholder="Tax invoice, description, customer, file name…">
        </div>
        <div class="f-field">
          <label>Customer</label>
          <select id="fCustomer"><option value="">All Customers</option></select>
        </div>
        <div class="f-field">
          <label>Entity</label>
          <select id="fEntity"><option value="">All Entities</option></select>
        </div>
        <div class="f-field">
          <label>Status</label>
          <select id="fStatus"><option value="">All Statuses</option></select>
        </div>
        <div class="f-field">
          <label>Claim Type</label>
          <div class="ms" id="fClaimType"><button type="button" class="ms-btn" id="ctBtn">All types ▾</button><div class="ms-panel" id="ctPanel"></div></div>
        </div>
      </div>
    </div>
  </div>

  <div class="rc-card">
    <div class="rc-card-hdr">
      <div class="rc-card-title"><i class="fa-solid fa-table-list"></i> Claim Records</div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><button class="btn-primary" id="svBulk" type="button" onclick="svList(svPicked())" disabled style="background:linear-gradient(135deg,#0f766e,#0d9488)"><i class="fa-solid fa-file-circle-check"></i> Send selected to SSCL &amp; VAT <span id="svBulkN"></span></button>
      <div style="font-size:11.5px;color:var(--muted);" id="resultInfo"></div></div>
    </div>
    <div class="prev-outer">
      <div class="prev-scroll">
        <table class="prev-table">
          <thead>
            <tr>
              <th style="cursor:default" title="Select claims to send to SSCL &amp; VAT"><input type="checkbox" id="svAll"></th>
              <th>#</th>
              <th onclick="setSort('customer_code')">Customer <span class="sort-ic" id="si-customer_code"></span></th>
              <th>File / Upload</th>
              <th>Ledger Type</th><th>Status</th><th>Claim Type</th>
              <th onclick="setSort('tax_invoice_no')">Tax Invoice No. <span class="sort-ic" id="si-tax_invoice_no"></span></th>
              <th onclick="setSort('invoice_date')">Invoice Date <span class="sort-ic" id="si-invoice_date"></span></th>
              <th onclick="setSort('banking_date')">Banking Date <span class="sort-ic" id="si-banking_date"></span></th>
              <th>Entity</th>
              <th>Claim Description</th>
              <th class="tr" onclick="setSort('actual_amount')">Actual Amt <span class="sort-ic" id="si-actual_amount"></span></th>
              <th class="tr" onclick="setSort('vat_amount')">VAT Amt <span class="sort-ic" id="si-vat_amount"></span></th>
              <th class="tr" onclick="setSort('total_amount')">Total <span class="sort-ic" id="si-total_amount"></span></th>
              <th onclick="setSort('imported_at')">Imported At <span class="sort-ic" id="si-imported_at"></span></th>
              <th title="F = only the first number of the description · M = middle number / normal" style="text-align:center">F / M</th>
              <th>Scheme No</th>
              <th style="cursor:default">SSCL &amp; VAT</th>
            </tr>
          </thead>
          <tbody id="repBody">
            <tr><td colspan="18" style="padding:40px;text-align:center;color:var(--muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:8px;"></i>Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="pager">
      <span class="pg-info" id="pgInfo"></span>
      <select id="perPageSel" onchange="loadReport(1)">
        <option value="50">50 / page</option>
        <option value="100" selected>100 / page</option>
        <option value="250">250 / page</option>
        <option value="500">500 / page</option>
      </select>
      <button id="pgFirst" onclick="loadReport(1)"><i class="fa-solid fa-angles-left"></i></button>
      <button id="pgPrev" onclick="loadReport(_curPage-1)"><i class="fa-solid fa-angle-left"></i></button>
      <span class="pg-info" id="pgPageLbl">Page 1</span>
      <button id="pgNext" onclick="loadReport(_curPage+1)"><i class="fa-solid fa-angle-right"></i></button>
      <button id="pgLast" onclick="loadReport(_totalPages)"><i class="fa-solid fa-angles-right"></i></button>
    </div>
  </div>
</div>

<div id="toast"></div>

<style>
/*m*/.svm{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;z-index:9000;padding:14px}.svm.open{display:flex}
.svm-box{font-family:'Segoe UI',system-ui,sans-serif;background:#fff;border-radius:16px;width:min(1180px,100%);max-height:94vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.35)}
.svm-hd{background:linear-gradient(120deg,#134e4a,#0f766e 55%,#14b8a6);color:#fff;padding:14px 18px;display:flex;gap:12px;align-items:flex-start;justify-content:space-between}.svm-hd button{background:rgba(255,255,255,.18);border:none;color:#fff;font-size:22px;width:36px;height:36px;border-radius:10px;cursor:pointer}
.svm-bd{padding:14px 18px;overflow:auto;display:flex;flex-direction:column;gap:14px}.svm-t{font-weight:800;font-size:13px;color:#134e4a;margin-bottom:6px}
.svt{width:100%;border-collapse:collapse;font-size:12px}.svt th{background:#0f172a;color:#e2e8f0;padding:7px 9px;text-align:left;font-size:10.5px;position:sticky;top:0}.svt th.tr,.svt td.tr{text-align:right}.svt td{padding:6px 9px;border-bottom:1px solid #f1f5f9}
.svt tr.pk td{background:#ccfbf1!important}.svt tbody tr.pay{cursor:pointer}.svt tbody tr.pay:hover td{background:#f0fdfa}.svt input.amt{width:120px;text-align:right;border:1.5px solid #5eead4;border-radius:7px;padding:5px 7px;font:inherit;font-weight:700}
.prev-table input[type=checkbox],#svAll{appearance:auto!important;-webkit-appearance:checkbox!important;display:inline-block!important;opacity:1!important;visibility:visible!important;position:static!important;width:16px!important;height:16px!important;margin:0!important;cursor:pointer;accent-color:#0d9488}
.sv-rev{display:inline-flex;gap:4px;align-items:center;border:1.5px solid #fca5a5;background:#fff;color:#b91c1c;border-radius:7px;padding:2px 8px;font-size:10.5px;font-weight:800;cursor:pointer}.sv-rev:hover{background:#fef2f2}
.sv-btn.o{background:linear-gradient(135deg,#64748b,#94a3b8)}
.sv-btn{display:inline-flex;gap:5px;align-items:center;border:none;border-radius:8px;padding:5px 10px;font-size:11px;font-weight:800;cursor:pointer;background:linear-gradient(135deg,#0f766e,#0d9488);color:#fff;white-space:nowrap}.sv-btn:hover{filter:brightness(1.1)}
</style>
<script>
let SVROWS = {};
function svFull(r){ return parseFloat(r.sv_settled||0)>0 && parseFloat(r.total_amount||0)-parseFloat(r.sv_settled||0)<=0.004; }
function svCanSend(r){ return !r.sv_listed && !svFull(r); }
function svCell(r){
  let h='';
  (r.sv_pays||[]).forEach(p=>{ h+='<span class="pill '+(svFull(r)?'p-green':'p-amber')+'" title="Matched on SSCL &amp; VAT payment #'+p.id+' — to undo, remove that payment line on the SSCL &amp; VAT page">'+(svFull(r)?'<i class="fa-solid fa-circle-check"></i> Matched':'Partly matched')+' #'+p.id+(p.pd?' ('+esc(p.pd)+')':'')+' · '+fmtN(p.amt)+'</span> '; });
  if(r.sv_ack>0) h+='<span class="pill p-blue">Ack #'+r.sv_ack+'</span> ';
  if(r.sv_listed && !svFull(r)) h+='<span class="pill p-teal" title="Sent '+esc(r.sv_listed)+' — pick it on the SSCL &amp; VAT page when entering the payment"><i class="fa-solid fa-list-check"></i> In SSCL &amp; VAT list</span> '
     +((r.sv_pays||[]).length||r.sv_ack>0?'':'<button type="button" class="sv-rev" onclick="svUnlist('+r.id+',\''+esc(r.tax_invoice_no||'')+'\')" title="Take this claim off the SSCL &amp; VAT list"><i class="fa-solid fa-rotate-left"></i> Reverse</button>');
  if(svCanSend(r)) h+='<button type="button" class="sv-btn'+(r.sv_tax==1?'':' o')+'" onclick="svList(['+r.id+'])" title="'+(r.sv_tax==1?'Add to the SSCL &amp; VAT claim list':'This claim type is not SSCL / VAT — check before sending')+'"><i class="fa-solid fa-paper-plane"></i> Send to SSCL &amp; VAT</button>';
  return h;
}
function svPicked(){ return [].slice.call(document.querySelectorAll('.svChk:checked')).map(c=>+c.value); }
function svSync(){ const n=svPicked().length; document.getElementById('svBulk').disabled=!n; document.getElementById('svBulkN').textContent=n?'('+n+')':''; const all=document.querySelectorAll('.svChk'); document.getElementById('svAll').checked=all.length>0&&n===all.length; }
document.getElementById('svAll').addEventListener('change',e=>{ document.querySelectorAll('.svChk').forEach(c=>c.checked=e.target.checked); svSync(); });
document.getElementById('repBody').addEventListener('change',e=>{ if(e.target.classList.contains('svChk')) svSync(); });
async function svPost(data){ const fd=new FormData(); Object.keys(data).forEach(k=>fd.append(k,data[k])); const d=await (await fetch('customer_claim_report.php',{method:'POST',body:fd})).json(); if(!d.success) throw new Error(d.message); return d; }
async function svList(ids){
  if(!ids.length) return; const other=ids.filter(id=>SVROWS[id]&&SVROWS[id].sv_tax!=1).length;
  if(!confirm('Send '+ids.length+' claim(s) to the SSCL & VAT list?'+(other?'\n\n'+other+' of them are not SSCL / VAT claim types.':'')+'\n\nThey will appear on the SSCL & VAT page to pick when you enter the payment.')) return;
  try{ const d=await svPost({ajax_action:'sv_list',ids:JSON.stringify(ids)}); showToast(d.message); loadReport(_curPage); }catch(e){ showToast(e.message,'err'); } }
async function svUnlist(id,ti){
  if(!confirm('Reverse — take claim '+ti+' off the SSCL & VAT list?')) return;
  try{ const d=await svPost({ajax_action:'sv_unlist',id:id}); showToast(d.message); loadReport(_curPage); }catch(e){ showToast(e.message,'err'); } }
</script>

<script>
let _curPage = 1, _totalPages = 1, _sortField = 'imported_at', _sortDir = 'DESC';
let _searchDebounce = null;

function esc(s){ if(s==null) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
function fmtN(v){ return parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtMoney(v){ return 'Rs. ' + fmtN(v); }
function showToast(msg,type){
  const t=document.getElementById('toast');
  t.className = type==='err' ? 'err' : '';
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(t._t);
  t._t=setTimeout(()=>t.classList.remove('show'),3500);
}

/* ── Load filter dropdown options ── */
async function loadFilterOptions(){
  try{
    const res = await fetch('customer_claim_report.php?ajax_action=get_filter_options');
    const d = await res.json();
    if(!d.success) return;
    const custSel = document.getElementById('fCustomer');
    d.customers.forEach(c=>{
      const opt = document.createElement('option');
      opt.value = c.customer_code;
      opt.textContent = c.customer_code + (c.customer_name ? ' — '+c.customer_name : '');
      custSel.appendChild(opt);
    });
    const entSel = document.getElementById('fEntity');
    d.entities.forEach(e=>{
      const opt = document.createElement('option'); opt.value = e; opt.textContent = e; entSel.appendChild(opt);
    });
    const stSel = document.getElementById('fStatus');
    d.statuses.forEach(s=>{
      const opt = document.createElement('option'); opt.value = s; opt.textContent = s; stSel.appendChild(opt);
    });
    document.getElementById('ctPanel').innerHTML = '<a class="ms-clear" id="ctClear">Clear selection</a>' + d.claim_types.map(t=>'<label><input type="checkbox" class="ctChk" value="'+esc(t)+'"> '+esc(t)+'</label>').join('');
    document.getElementById('ctClear').addEventListener('click', ()=>{ document.querySelectorAll('.ctChk').forEach(c=>c.checked=false); ctUpdate(); loadReport(1); });
    document.querySelectorAll('.ctChk').forEach(c=>c.addEventListener('change', ()=>{ ctUpdate(); loadReport(1); }));
  }catch(e){ /* silent */ }
}

function ctSelected(){ return [].slice.call(document.querySelectorAll('.ctChk:checked')).map(c=>c.value); }
function ctUpdate(){ const s=ctSelected(); document.getElementById('ctBtn').textContent = !s.length ? 'All types ▾' : (s.length<=2 ? s.join(', ') : s.length+' types selected')+' ▾'; }
function ctPlace(){ const b=document.getElementById('ctBtn').getBoundingClientRect(), p=document.getElementById('ctPanel');   // fixed → never clipped by the filter card
  p.style.left=Math.max(8,Math.min(b.left,window.innerWidth-Math.max(b.width,240)-8))+'px'; p.style.minWidth=Math.max(b.width,240)+'px';
  const below=window.innerHeight-b.bottom-12; if(below<180 && b.top>below){ p.style.top='auto'; p.style.bottom=(window.innerHeight-b.top+4)+'px'; p.style.maxHeight=Math.min(300,b.top-12)+'px'; } else { p.style.bottom='auto'; p.style.top=(b.bottom+4)+'px'; p.style.maxHeight=Math.min(300,below)+'px'; } }
document.getElementById('ctBtn').addEventListener('click', e=>{ e.stopPropagation(); const w=document.getElementById('fClaimType'); w.classList.toggle('open'); if(w.classList.contains('open')) ctPlace(); });
window.addEventListener('resize', ()=>document.getElementById('fClaimType').classList.remove('open'));
window.addEventListener('scroll', e=>{ if(e.target && e.target.nodeType===1 && e.target.closest('#ctPanel')) return; document.getElementById('fClaimType').classList.remove('open'); }, true);
document.addEventListener('click', e=>{ if(!e.target.closest('#fClaimType')) document.getElementById('fClaimType').classList.remove('open'); });
function getFilterParams(){
  return {
    date_field: document.getElementById('fDateField').value,
    date_from:  document.getElementById('fFrom').value,
    date_to:    document.getElementById('fTo').value,
    search:     document.getElementById('fSearch').value.trim(),
    customer_code: document.getElementById('fCustomer').value,
    entity:     document.getElementById('fEntity').value,
    status:     document.getElementById('fStatus').value,
    claim_type: ctSelected().join('||'),
  };
}

function clearFilters(){
  document.getElementById('fDateField').value = 'imported_at';
  document.getElementById('fFrom').value = '';
  document.getElementById('fTo').value = '';
  document.getElementById('fSearch').value = '';
  document.getElementById('fCustomer').value = '';
  document.getElementById('fEntity').value = '';
  document.getElementById('fStatus').value = '';
  document.querySelectorAll('.ctChk').forEach(c=>c.checked=false); ctUpdate();
  loadReport(1);
}

function setSort(field){
  if(_sortField === field){ _sortDir = _sortDir === 'ASC' ? 'DESC' : 'ASC'; }
  else { _sortField = field; _sortDir = 'DESC'; }
  document.querySelectorAll('.sort-ic').forEach(el=>el.innerHTML='');
  const ic = document.getElementById('si-'+field);
  if(ic) ic.innerHTML = _sortDir === 'ASC' ? '<i class="fa-solid fa-arrow-up"></i>' : '<i class="fa-solid fa-arrow-down"></i>';
  loadReport(1);
}

const statusBadge = s => {
  if (!s) return '<span style="color:var(--muted)">—</span>';
  if (s === 'Completed')  return '<span class="pill p-green">' + esc(s) + '</span>';
  if (s === 'Pending' || s === 'In Progress') return '<span class="pill p-amber">' + esc(s) + '</span>';
  return '<span class="pill p-gray">' + esc(s) + '</span>';
};
const typePill = t => {
  if (!t) return '—';
  if (t.includes('Damages')) return '<span class="pill p-red">' + esc(t) + '</span>';
  if (t.includes('Drive') || t.includes('Loyalty')) return '<span class="pill p-violet">' + esc(t) + '</span>';
  return '<span class="pill p-teal">' + esc(t) + '</span>';
};

async function loadReport(page){
  page = Math.max(1, page || 1);
  _curPage = page;
  document.getElementById('repBody').innerHTML =
    '<tr><td colspan="18" style="padding:40px;text-align:center;color:var(--muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:8px;"></i>Loading…</td></tr>';

  const params = getFilterParams();
  const per = document.getElementById('perPageSel').value;
  const qs = new URLSearchParams({
    ajax_action: 'get_report_data',
    page: page, per_page: per, sort: _sortField, dir: _sortDir,
    ...params
  });

  try{
    const res = await fetch('customer_claim_report.php?' + qs.toString());
    const d = await res.json();
    if(!d.success) throw new Error('Load failed');

    _totalPages = d.pages;
    document.getElementById('kpiCount').textContent   = d.summary.c;
    document.getElementById('kpiCust').textContent    = d.summary.cust_n;
    document.getElementById('kpiUploads').textContent = d.summary.upload_n;
    document.getElementById('kpiActual').textContent  = fmtMoney(d.summary.sa);
    document.getElementById('kpiVat').textContent      = fmtMoney(d.summary.sv);
    document.getElementById('kpiTotal').textContent    = fmtMoney(d.summary.st);

    document.getElementById('resultInfo').textContent = d.total + ' record(s) found';
    document.getElementById('pgInfo').textContent = 'Showing ' + ((page-1)*per + (d.rows.length?1:0)) + '–' + ((page-1)*per + d.rows.length) + ' of ' + d.total;
    document.getElementById('pgPageLbl').textContent = 'Page ' + page + ' of ' + _totalPages;
    document.getElementById('pgFirst').disabled = page <= 1;
    document.getElementById('pgPrev').disabled  = page <= 1;
    document.getElementById('pgNext').disabled  = page >= _totalPages;
    document.getElementById('pgLast').disabled  = page >= _totalPages;

    if(!d.rows.length){
      document.getElementById('repBody').innerHTML =
        '<tr><td colspan="18" style="padding:34px;text-align:center;color:var(--muted);">No records match the current filters.</td></tr>';
      return;
    }

    let h = '';
    d.rows.forEach((r, i) => {
      SVROWS[r.id] = r;
      h += '<tr data-id="' + r.id + '">' +
        '<td style="text-align:center">' + (svCanSend(r) ? '<input type="checkbox" class="svChk" value="' + r.id + '" title="Select to send to SSCL &amp; VAT">' : '<i class="fa-solid fa-circle-check" style="color:#16a34a" title="Already matched on SSCL &amp; VAT"></i>') + '</td>' +
        '<td style="font-family:monospace;font-size:11px;color:var(--muted);">' + ((( _curPage-1)*per)+i+1) + '</td>' +
        '<td><span class="pill p-violet">' + esc(r.customer_code||'—') + '</span> <span style="font-size:11px;color:var(--muted);">' + esc(r.customer_name||'') + '</span></td>' +
        '<td style="font-size:11px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(r.file_name||'') + '">' + esc(r.file_name||'—') + '<br><span style="color:var(--muted);">' + esc(r.upload_date||'') + '</span></td>' +
        '<td><span class="pill p-blue">' + esc(r.ledger_type||'—') + '</span></td>' +
        '<td>' + statusBadge(r.status) + '</td>' +
        '<td>' + typePill(r.claim_type) + '</td>' +
        '<td style="font-family:monospace;font-size:12px;font-weight:700;color:#312e81;">' + esc(r.tax_invoice_no) + '</td>' +
        '<td style="font-size:11.5px;">' + esc(r.invoice_date||'—') + '</td>' +
        '<td style="font-size:11.5px;">' + esc(r.banking_date||'—') + '</td>' +
        '<td><span class="pill p-gray">' + esc(r.entity||'—') + '</span></td>' +
        '<td style="font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(r.claim_description||'') + '">' + esc((r.claim_description||'').slice(0,50)) + '</td>' +
        '<td class="tr" style="font-weight:700;">' + fmtN(r.actual_amount) + '</td>' +
        '<td class="tr" style="color:var(--muted);">' + fmtN(r.vat_amount) + '</td>' +
        '<td class="tr" style="font-weight:800;color:#312e81;">' + fmtN(r.total_amount) + '</td>' +
        '<td style="font-size:10.5px;color:var(--muted);">' + esc(r.imported_at||'—') + '</td>' +
        '<td style="text-align:center;">' + (r.scheme_src ? '<span class="pill ' + (r.scheme_src==='F' ? 'p-amber' : 'p-green') + '" title="' + (r.scheme_src==='F' ? 'Only the first number of the description' : 'Middle number / normal') + '">' + esc(r.scheme_src) + '</span>' : '<span style="color:#d1d5db">—</span>') + '</td>' +
        '<td style="font-family:monospace;font-weight:800;color:#312e81;">' + esc(r.scheme_no||'—') + '</td>' +
        '<td>' + svCell(r) + '</td>' +
        '</tr>';
    });
    document.getElementById('repBody').innerHTML = h; svSync();
  }catch(e){
    document.getElementById('repBody').innerHTML =
      '<tr><td colspan="18" style="padding:34px;text-align:center;color:#dc2626;">Failed to load report data.</td></tr>';
  }
}

/* debounce search */
document.getElementById('fSearch').addEventListener('input', function(){
  clearTimeout(_searchDebounce);
  _searchDebounce = setTimeout(()=>loadReport(1), 450);
});
['fCustomer','fEntity','fStatus','fDateField'].forEach(id=>{
  document.getElementById(id).addEventListener('change', ()=>loadReport(1));
});

/* ── Export to Excel (full filtered set, no pagination) ── */
function exportReport(){
  /* every row of the table (all pages) with the current filters — built on the server */
  showToast('Preparing the Excel with all rows…');
  const qs = new URLSearchParams({ export_xlsx: '1', ...getFilterParams() });
  window.location.href = 'customer_claim_report.php?' + qs.toString();
}

/* init */
loadFilterOptions();
loadReport(1);
</script>
<?php include 'footer.php'; ?>