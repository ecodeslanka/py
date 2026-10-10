<?php
include 'config.php';

// Create customers table if not exists
$createCustomersTable = "CREATE TABLE IF NOT EXISTS customers (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    t_code VARCHAR(50) NOT NULL UNIQUE,
    shop_name VARCHAR(255) NOT NULL,
    company_id INT(11) NULL,
    branch_id INT(11) NULL,
    route VARCHAR(255) NULL,
    address TEXT NULL,
    telephone_number VARCHAR(20) NULL,
    primary_channel VARCHAR(100) NULL,
    channel VARCHAR(100) NULL,
    payment_mode ENUM('cash', 'credit', 'cheque') DEFAULT 'cash',
    credit_limit DECIMAL(10, 2) DEFAULT 0,
    credit_days INT(11) DEFAULT 0,
    special_credit_policy_days VARCHAR(255) NULL,
    customer_seal VARCHAR(255) NULL,
    customer_signature VARCHAR(255) NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_t_code (t_code),
    INDEX idx_shop_name (shop_name),
    INDEX idx_route (route)
)";
mysqli_query($conn, $createCustomersTable);

// Create customer_bank_accounts table
$createBankAccountsTable = "CREATE TABLE IF NOT EXISTS customer_bank_accounts (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    customer_id INT(11) NOT NULL,
    account_holder_name VARCHAR(255) NOT NULL,
    bank_code VARCHAR(50) NOT NULL,
    branch_code VARCHAR(50) NOT NULL,
    account_number VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_customer_id (customer_id)
)";
mysqli_query($conn, $createBankAccountsTable);

/* ── AUTO-MIGRATE: blacklist columns (same schema used by the
     Customer Credit Risk Report, so both pages stay in sync) ── */
$cols_check = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'blacklisted'");
if ($cols_check && mysqli_num_rows($cols_check) === 0) {
    mysqli_query($conn, "ALTER TABLE customers
        ADD COLUMN blacklisted      TINYINT(1)  NOT NULL DEFAULT 0,
        ADD COLUMN blacklist_reason TEXT        NULL,
        ADD COLUMN blacklist_date   DATETIME    NULL");
}
$bt_col = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'blacklist_type'");
if ($bt_col && mysqli_num_rows($bt_col) === 0) {
    mysqli_query($conn, "ALTER TABLE customers
        ADD COLUMN blacklist_type VARCHAR(20) NULL DEFAULT NULL");
}

/* Allowed blacklist category keys — shared by the write and filter paths.
   Kept identical to the Credit Risk Report:
     temporary        — Temporary Block
     no_cheque_cod     — Block Cheque on Delivery
     no_cash_cod       — Block Cash on Delivery
     permanent         — Permanent Block
     no_service        — Not Serviced (no delivery at all) */
$BL_TYPES = ['temporary', 'no_cheque_cod', 'no_cash_cod', 'permanent', 'no_service'];

/* cheques_will_delay is set on Add / Edit Customer and the Credit Policy import */
$cd_col = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'cheques_will_delay'");
$HAS_CHQ_DELAY = $cd_col && mysqli_num_rows($cd_col) > 0;
$CHQ_DELAY_SQL = $HAS_CHQ_DELAY ? "COALESCE(c.cheques_will_delay,0)" : "0";

/* WHERE clause from the page filters — shared by the list and the Excel export */
function cust_where($conn, $src, $BL_TYPES, $CHQ_DELAY_SQL) {
    $search    = mysqli_real_escape_string($conn, $src['search'] ?? '');
    $f_primary = mysqli_real_escape_string($conn, $src['filter_primary_channel'] ?? '');
    $f_channel = mysqli_real_escape_string($conn, $src['filter_channel'] ?? '');
    $f_payment = mysqli_real_escape_string($conn, $src['filter_payment_mode'] ?? '');
    $f_status  = $src['filter_status'] ?? 'active';
    $f_bltype  = trim($src['filter_blacklist'] ?? '');
    $f_delay   = $src['filter_cheque_delay'] ?? '';

    $where = [];
    if ($search !== '')    $where[] = "(c.t_code LIKE '%$search%' OR c.shop_name LIKE '%$search%')";
    if ($f_primary !== '') $where[] = "c.primary_channel = '$f_primary'";
    if ($f_channel !== '') $where[] = "c.channel = '$f_channel'";
    if ($f_payment !== '') $where[] = "c.payment_mode = '$f_payment'";
    if ($f_status === 'active')       $where[] = "c.active = 1";
    if ($f_status === 'non_approved') $where[] = "c.active = 0";
    if ($f_delay === 'yes') $where[] = "$CHQ_DELAY_SQL = 1";
    if ($f_delay === 'no')  $where[] = "$CHQ_DELAY_SQL = 0";

    /* Blacklist filter — '' = no filter, '__any__' = any blacklisted customer, or a specific category key */
    if ($f_bltype === '__any__') {
        $where[] = "COALESCE(c.blacklisted,0) = 1";
    } elseif ($f_bltype !== '' && in_array($f_bltype, $BL_TYPES, true)) {
        $bl_esc = mysqli_real_escape_string($conn, $f_bltype);
        $where[] = "COALESCE(c.blacklisted,0) = 1 AND c.blacklist_type = '$bl_esc'";
    }
    return $where ? 'WHERE ' . implode(' AND ', $where) : '';
}

/* ── EXPORT TO EXCEL (.xlsx, every customer matching the current filters) ── */
if (isset($_GET['export'])) {
    $where_sql = cust_where($conn, $_GET, $BL_TYPES, $CHQ_DELAY_SQL);
    $res = mysqli_query($conn, "SELECT c.t_code, c.shop_name, c.route, c.address, c.telephone_number,
                                       c.primary_channel, c.channel, c.payment_mode, c.credit_limit, c.credit_days,
                                       c.special_credit_policy_days, $CHQ_DELAY_SQL AS cheques_delay, c.active,
                                       COALESCE(c.blacklisted,0) AS blacklisted, COALESCE(c.blacklist_type,'') AS blacklist_type,
                                       COALESCE(c.blacklist_reason,'') AS blacklist_reason, c.blacklist_date, c.created_at
                                  FROM customers c $where_sql
                              ORDER BY c.created_at DESC");
    $BL_LABELS = ['temporary' => 'Temporary Block', 'no_cheque_cod' => 'No Cheque on Delivery', 'no_cash_cod' => 'No Cash on Delivery',
                  'permanent' => 'Permanent Block', 'no_service' => 'Not Serviced'];
    $head = ['#', 'T-Code', 'Customer Name', 'Route', 'Address', 'Telephone', 'Primary Channel', 'Channel', 'Payment Mode',
             'Credit Limit (Rs)', 'Credit Days', 'Special Credit Policy Days', 'Cheques Delay', 'Status',
             'Blacklisted', 'Blacklist Category', 'Blacklist Reason', 'Blacklist Date', 'Created'];
    $rows = []; $i = 0;
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $isBl = (int)$r['blacklisted'] === 1;
        $rows[] = [++$i, $r['t_code'], $r['shop_name'], $r['route'], $r['address'], $r['telephone_number'],
                   $r['primary_channel'], $r['channel'], ucfirst((string)$r['payment_mode']),
                   (float)$r['credit_limit'], (int)$r['credit_days'], $r['special_credit_policy_days'],
                   (int)$r['cheques_delay'] === 1 ? 'Yes' : 'No', (int)$r['active'] === 1 ? 'Approved' : 'Non-Approved',
                   $isBl ? 'Yes' : 'No', $isBl ? ($BL_LABELS[$r['blacklist_type']] ?? 'Blocked') : '',
                   $isBl ? $r['blacklist_reason'] : '', $isBl && $r['blacklist_date'] ? date('Y-m-d H:i', strtotime($r['blacklist_date'])) : '',
                   $r['created_at'] ? date('Y-m-d', strtotime($r['created_at'])) : ''];
    }
    $fname = 'customers_' . date('Y-m-d_His');
    if (class_exists('ZipArchive')) {
        $file = cust_xlsx($head, $rows, 'Customers', [6, 12, 30, 16, 34, 14, 22, 22, 13, 15, 11, 18, 13, 13, 11, 22, 30, 16, 12], [9]);
        if ($file) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $fname . '.xlsx"');
            header('Content-Length: ' . filesize($file));
            header('Cache-Control: max-age=0');
            readfile($file); @unlink($file);
            exit;
        }
    }
    /* fallback: CSV (opens in Excel) */
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, $head);
    foreach ($rows as $r) fputcsv($o, $r);
    fclose($o);
    exit;
}

/* Minimal .xlsx writer (no Composer): bold frozen header, filter, column widths, money format. */
function cust_xlsx($head, $rows, $sheetName, $widths, $moneyCols) {
    $x = function ($v) { return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$v), ENT_QUOTES | ENT_XML1, 'UTF-8'); };
    $col = function ($n) { $s = ''; $n++; while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); } return $s; };
    $last = $col(count($head) - 1);
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
         . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
    foreach ($widths as $i => $w) $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    $xml .= '</cols><sheetData><row r="1">';
    foreach ($head as $i => $h) $xml .= '<c r="' . $col($i) . '1" t="inlineStr" s="1"><is><t>' . $x($h) . '</t></is></c>';
    $xml .= '</row>';
    foreach ($rows as $ri => $r) {
        $rn = $ri + 2; $xml .= '<row r="' . $rn . '">';
        foreach (array_values($r) as $ci => $v) {
            $ref = $col($ci) . $rn;
            if ($v === null || $v === '') continue;
            if (is_int($v) || is_float($v)) $xml .= '<c r="' . $ref . '"' . (in_array($ci, $moneyCols, true) ? ' s="2"' : '') . '><v>' . $v . '</v></c>';
            else $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $x($v) . '</t></is></c>';
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData><autoFilter ref="A1:' . $last . max(1, count($rows) + 1) . '"/></worksheet>';

    $tmp = tempnam(sys_get_temp_dir(), 'cx');
    $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) return false;
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $x($sheetName) . '" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . $x($sheetName) . '\'!$A$1:$' . $last . '$' . max(1, count($rows) + 1) . '</definedName></definedNames></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F2937"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $z->addFromString('xl/worksheets/sheet1.xml', $xml);
    $z->close();
    return $tmp;
}

// ── AJAX endpoints ────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {

    /* ── BLACKLIST ACTION (blacklist or unblock, single or bulk) ──────────── */
    if ($_GET['ajax'] === 'blacklist_action' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');

        $body   = json_decode(file_get_contents('php://input'), true);
        $tcodes = isset($body['t_codes']) && is_array($body['t_codes']) ? $body['t_codes'] : [];
        $action = isset($body['action']) ? trim($body['action']) : 'blacklist';
        $reason = isset($body['reason']) ? trim($body['reason']) : '';
        $type   = isset($body['type']) ? trim($body['type']) : '';

        if (empty($tcodes)) {
            echo json_encode(['error' => 'No customers selected']); exit;
        }

        $escaped = array_map(function($tc) use ($conn) {
            return "'" . mysqli_real_escape_string($conn, $tc) . "'";
        }, $tcodes);
        $in = implode(',', $escaped);
        $reason_esc = mysqli_real_escape_string($conn, $reason);

        if ($action === 'blacklist') {
            if (!in_array($type, $BL_TYPES, true)) {
                echo json_encode(['error' => 'Please select a valid blacklist category']); exit;
            }
            $type_esc = mysqli_real_escape_string($conn, $type);
            $sql = "UPDATE customers SET blacklisted = 1, blacklist_reason = '$reason_esc',
                    blacklist_type = '$type_esc', blacklist_date = NOW()
                    WHERE t_code IN ($in)";
        } else {
            $sql = "UPDATE customers SET blacklisted = 0, blacklist_reason = NULL,
                    blacklist_type = NULL, blacklist_date = NULL
                    WHERE t_code IN ($in)";
        }

        $res = mysqli_query($conn, $sql);
        if (!$res) { echo json_encode(['error' => mysqli_error($conn)]); exit; }
        echo json_encode(['success' => true, 'affected' => mysqli_affected_rows($conn), 'action' => $action, 'type' => $type]);
        exit;
    }

    /* ── BLACKLIST STATUS CHECK (used when opening a fresh blacklist modal) ── */
    if ($_GET['ajax'] === 'blacklist_status') {
        header('Content-Type: application/json');
        $tcodes_raw = isset($_GET['t_codes']) ? $_GET['t_codes'] : '';
        $tcodes = array_filter(array_map('trim', explode(',', $tcodes_raw)));
        if (empty($tcodes)) { echo json_encode(['statuses' => []]); exit; }
        $escaped = array_map(function($tc) use ($conn) {
            return "'" . mysqli_real_escape_string($conn, $tc) . "'";
        }, $tcodes);
        $in  = implode(',', $escaped);
        $res = mysqli_query($conn, "SELECT t_code, COALESCE(blacklisted,0) AS blacklisted,
                                    COALESCE(blacklist_reason,'') AS reason,
                                    COALESCE(blacklist_type,'')   AS blacklist_type,
                                    blacklist_date FROM customers WHERE t_code IN ($in)");
        $statuses = [];
        if ($res) while ($r = mysqli_fetch_assoc($res)) $statuses[$r['t_code']] = $r;
        echo json_encode(['statuses' => $statuses]);
        exit;
    }

    /* ── MAIN LIST (fast paginated data loading) ───────────────────────────── */
    if ($_GET['ajax'] == '1') {
        header('Content-Type: application/json');

        $page      = max(1, intval($_GET['page'] ?? 1));
        $limit     = 25;
        $offset    = ($page - 1) * $limit;
        $where_sql = cust_where($conn, $_GET, $BL_TYPES, $CHQ_DELAY_SQL);

        $total_res = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM customers c $where_sql");
        $total     = mysqli_fetch_assoc($total_res)['cnt'];
        $pages     = ceil($total / $limit);

        $sql  = "SELECT c.id, c.t_code, c.shop_name, c.telephone_number,
                        c.primary_channel, c.channel, c.payment_mode, c.credit_limit,
                        c.route AS route_name, c.active, c.credit_days,
                        $CHQ_DELAY_SQL AS cheques_delay,
                        COALESCE(c.blacklisted,0)     AS blacklisted,
                        COALESCE(c.blacklist_type,'') AS blacklist_type,
                        COALESCE(c.blacklist_reason,'') AS blacklist_reason,
                        c.blacklist_date              AS blacklist_date
                 FROM customers c
                 $where_sql
                 ORDER BY c.created_at DESC
                 LIMIT $limit OFFSET $offset";
        $res  = mysqli_query($conn, $sql);

        $rows = [];
        while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;

        echo json_encode(['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page]);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unknown ajax action']);
    exit;
}

include 'header.php';
?>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <div>
            <h2 class="page-title">Customer Management</h2>
            <p class="page-subtitle">Manage all customers and their details</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="button" class="btn btn-excel" id="exportBtn" onclick="exportExcel()" title="Download every customer matching the current search and filters">
                <i class="fa-solid fa-file-excel"></i> Export to Excel
            </button>
            <a href="add_customer.php" class="btn btn-primary">
                <i class="fa-solid fa-plus"></i> Add New Customer
            </a>
        </div>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success" id="successAlert">
    <i class="fa-solid fa-circle-check"></i> Customer saved successfully.
</div>
<?php endif; ?>

<!-- Search & Filter Bar -->
<div style="margin-bottom:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <div style="position:relative;flex:1;max-width:420px;">
        <i class="fa-solid fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;"></i>
        <input type="text" id="searchInput" class="search-input" placeholder="Search by T-Code, Customer Name..." autocomplete="off">
        <span id="searchSpinner" style="display:none;position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#9ca3af;">
            <i class="fa-solid fa-spinner fa-spin"></i>
        </span>
    </div>

    <!-- Status Toggle Buttons -->
    <div class="status-toggle">
        <button class="status-btn active" id="btnActive" onclick="setStatus('active')">
            <i class="fa-solid fa-circle-check"></i> Approved
        </button>
        <button class="status-btn" id="btnNon_approved" onclick="setStatus('non_approved')">
            <i class="fa-solid fa-circle-xmark"></i> Non-Approved
        </button>
        <button class="status-btn" id="btnAll" onclick="setStatus('all')">
            <i class="fa-solid fa-list"></i> All
        </button>
    </div>

    <button onclick="toggleFilters()" class="btn-filter-toggle" id="filterToggleBtn">
        <i class="fa-solid fa-sliders"></i> Filters
        <i class="fa-solid fa-chevron-down" id="filter-icon" style="font-size:11px;"></i>
    </button>
</div>

<!-- Filters Panel -->
<div class="content-card filters-section" id="filters-section" style="display:none;margin-bottom:16px;">
    <div class="filter-grid">
        <div class="filter-group">
            <label class="filter-label">PRIMARY CHANNEL</label>
            <select id="f_primary" class="filter-input" onchange="loadData(1)">
                <option value="">All Primary Channels</option>
                <option value="Distributive Grocery">Distributive Grocery</option>
                <option value="Distributive Other Channels">Distributive Other Channels</option>
                <option value="Out of Home">Out of Home</option>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">CHANNEL</label>
            <select id="f_channel" class="filter-input" onchange="loadData(1)">
                <option value="">All Channels</option>
                <option value="Book shops and Hardware">Book shops and Hardware</option>
                <option value="Cosmetic Standard Large">Cosmetic Standard Large</option>
                <option value="Cosmetic Standard Small">Cosmetic Standard Small</option>
                <option value="DUMMY">DUMMY</option>
                <option value="Emergency Top up Upper">Emergency Top up Upper</option>
                <option value="Estate outlets">Estate outlets</option>
                <option value="ETUP LARGE">ETUP LARGE</option>
                <option value="ETUP LITE">ETUP LITE</option>
                <option value="ETUP MEDIUM">ETUP MEDIUM</option>
                <option value="ETUP SMALL">ETUP SMALL</option>
                <option value="Family Grocery Large">Family Grocery Large</option>
                <option value="Family Grocery Small">Family Grocery Small</option>
                <option value="INDEPENDENT SUPER MARKER LARGE">INDEPENDENT SUPER MARKER LARGE</option>
                <option value="INDEPENDENT SUPER MARKER MEDIUM">INDEPENDENT SUPER MARKER MEDIUM</option>
                <option value="INDEPENDENT SUPER MARKER SMALL">INDEPENDENT SUPER MARKER SMALL</option>
                <option value="Institutions Forces">Institutions Forces</option>
                <option value="Institutions Private">Institutions Private</option>
                <option value="Out of home Others">Out of home Others</option>
                <option value="Pharmacy General Large">Pharmacy General Large</option>
                <option value="Pharmacy General Small">Pharmacy General Small</option>
                <option value="Saubhagya Entrepreneurs">Saubhagya Entrepreneurs</option>
                <option value="Textile Premium">Textile Premium</option>
                <option value="Textile Standard">Textile Standard</option>
                <option value="Wholesale Dominant">Wholesale Dominant</option>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">PAYMENT MODE</label>
            <select id="f_payment" class="filter-input" onchange="loadData(1)">
                <option value="">All Payment Modes</option>
                <option value="cash">Cash</option>
                <option value="credit">Credit</option>
                <option value="cheque">Cheque</option>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">BLACKLIST STATUS</label>
            <select id="f_blacklist" class="filter-input" onchange="loadData(1)">
                <option value="">All Customers</option>
                <option value="__any__">Any Blacklist Category</option>
                <option value="temporary">Temporary Block</option>
                <option value="no_cheque_cod">No Cheque on Delivery</option>
                <option value="no_cash_cod">No Cash on Delivery</option>
                <option value="permanent">Permanent Block</option>
                <option value="no_service">Not Serviced</option>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">CHEQUES DELAY</label>
            <select id="f_delay" class="filter-input" onchange="loadData(1)">
                <option value="">All Customers</option>
                <option value="yes">Cheques Delay — Yes</option>
                <option value="no">Cheques Delay — No</option>
            </select>
        </div>
        <div class="filter-group" style="justify-content:flex-end;align-items:flex-end;">
            <button onclick="clearFilters()" class="btn btn-light" style="width:100%;">
                <i class="fa-solid fa-xmark"></i> Clear Filters
            </button>
        </div>
    </div>
</div>

<!-- Blacklist bulk-action toolbar (appears when rows are selected) -->
<div class="blacklist-toolbar" id="blacklistToolbar">
    <div class="bl-info">
        <i class="fa-solid fa-ban" style="color:#818cf8;font-size:18px;"></i>
        <div class="bl-count"><span id="blSelectedCount">0</span> customer(s) selected</div>
    </div>
    <div class="bl-actions">
        <button class="bl-btn bl-btn-block" onclick="openBlacklistModal('blacklist')">
            <i class="fa-solid fa-ban"></i> Blacklist Selected
        </button>
        <button class="bl-btn bl-btn-unblock" onclick="openBlacklistModal('unblock')">
            <i class="fa-solid fa-circle-check"></i> Remove from Blacklist
        </button>
        <button class="bl-btn bl-btn-clear" onclick="clearSelection()">
            <i class="fa-solid fa-xmark"></i> Clear Selection
        </button>
    </div>
</div>

<!-- Table Card -->
<div class="content-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
        <h3 class="card-title" style="margin:0;">
            <span id="tableTitle">Approved Customers</span> &nbsp;<span id="totalCount" class="count-badge">—</span>
        </h3>
        <div style="display:flex;align-items:center;gap:12px;">
            <button class="btn-filter-toggle" onclick="selectAllVisible()"><i class="fa-regular fa-square-check"></i> Select All</button>
            <div id="tableInfo" style="font-size:12px;color:#9ca3af;"></div>
        </div>
    </div>

    <!-- Skeleton loader -->
    <div id="skeletonLoader">
        <?php for($i=0;$i<8;$i++): ?>
        <div class="skeleton-row">
            <div class="skeleton-cell" style="width:80px;"></div>
            <div class="skeleton-cell" style="width:160px;"></div>
            <div class="skeleton-cell" style="width:100px;"></div>
            <div class="skeleton-cell" style="width:110px;"></div>
            <div class="skeleton-cell" style="width:130px;"></div>
            <div class="skeleton-cell" style="width:130px;"></div>
            <div class="skeleton-cell" style="width:70px;"></div>
            <div class="skeleton-cell" style="width:90px;"></div>
            <div class="skeleton-cell" style="width:80px;"></div>
        </div>
        <?php endfor; ?>
    </div>

    <div id="tableWrapper" style="display:none;">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:32px;"><input type="checkbox" class="th-check-box" id="masterCheck" onchange="masterCheckChange(this)"></th>
                        <th>T-Code</th>
                        <th>Customer Name</th>
                        <th>Route</th>
                        <th>Telephone</th>
                        <th>Primary Channel</th>
                        <th>Channel</th>
                        <th>Payment</th>
                        <th>Credit Limit</th>
                        <th>Cheques Delay</th>
                        <th>Blacklist</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="tableBody"></tbody>
            </table>
        </div>

        <!-- Empty state -->
        <div id="emptyState" class="empty-state" style="display:none;">
            <i class="fa-solid fa-users" style="font-size:48px;color:#d1d5db;margin-bottom:12px;"></i>
            <h3 style="color:#6b7280;margin:0 0 6px 0;">No customers found</h3>
            <p style="color:#9ca3af;margin:0;">Try adjusting your search or filters</p>
        </div>
    </div>

    <!-- Pagination -->
    <div id="paginationWrapper" style="display:none;margin-top:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <span id="pageInfo" style="font-size:12px;color:#6b7280;"></span>
            <div id="paginationBtns" style="display:flex;gap:4px;flex-wrap:wrap;"></div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     BLACKLIST MODAL (category picker + reason)
════════════════════════════════════════════════════ -->
<div class="bl-modal-backdrop" id="blBackdrop" onclick="closeBlModal()"></div>
<div class="bl-modal" id="blModal">
    <div class="bl-modal-header">
        <h3 id="blModalTitle"><i class="fa-solid fa-ban" style="color:#dc2626;"></i> Blacklist Customers</h3>
        <button class="drawer-close" onclick="closeBlModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="bl-modal-body">
        <div id="blModalContent"></div>
    </div>
    <div class="bl-modal-footer">
        <button class="btn btn-light" onclick="closeBlModal()">Cancel</button>
        <button class="btn btn-primary" id="blConfirmBtn" onclick="confirmBlacklist()">
            <i class="fa-solid fa-check"></i> Confirm
        </button>
    </div>
</div>

<style>
.page-header { margin-bottom: 24px; }
.page-title { font-size: 28px; font-weight: 700; color: #111827; margin: 0 0 4px 0; }
.page-subtitle { font-size: 13px; color: #6b7280; margin: 0; }

.content-card {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    padding: 20px;
    margin-bottom: 16px;
}
.card-title { font-size: 15px; font-weight: 600; color: #111827; margin: 0 0 16px 0; }

.count-badge {
    background: #f3f4f6;
    color: #374151;
    font-size: 12px;
    font-weight: 600;
    padding: 2px 10px;
    border-radius: 20px;
}

/* Search */
.search-input {
    width: 100%;
    padding: 9px 36px 9px 36px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    transition: border-color 0.2s;
    box-sizing: border-box;
}
.search-input:focus { outline: none; border-color: #000; }

/* Status Toggle */
.status-toggle {
    display: inline-flex;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    overflow: hidden;
    background: #fff;
}
.status-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 14px;
    border: none;
    background: #fff;
    font-size: 12px;
    font-weight: 500;
    color: #6b7280;
    cursor: pointer;
    transition: all 0.2s;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
    border-right: 1px solid #e5e7eb;
}
.status-btn:last-child { border-right: none; }
.status-btn:hover { background: #f9fafb; }
.status-btn.active {
    background: #000;
    color: #fff;
}
.status-btn.non-approved-active {
    background: #ef4444;
    color: #fff;
}

/* Filter toggle */
.btn-filter-toggle {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 16px;
    background: #fff; border: 1px solid #d1d5db; border-radius: 8px;
    font-size: 13px; font-weight: 500; color: #374151;
    cursor: pointer; transition: all 0.2s; font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.btn-filter-toggle:hover { background: #f9fafb; }
.btn-filter-toggle.active { background: #000; color: #fff; border-color: #000; }

/* Filters grid */
.filter-grid { display: grid; grid-template-columns: repeat(6,1fr); gap: 16px; }
.filter-group { display: flex; flex-direction: column; gap: 6px; }
.filter-label { font-size: 10px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; }
.filter-input {
    padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px;
    font-size: 13px; font-family: 'Inter', sans-serif; background: #fff;
}
.filter-input:focus { outline: none; border-color: #000; }

/* Skeleton */
.skeleton-row {
    display: flex; gap: 16px; padding: 12px 0;
    border-bottom: 1px solid #f3f4f6; align-items: center;
}
.skeleton-cell {
    height: 14px; background: linear-gradient(90deg, #f3f4f6 25%, #e9eaec 50%, #f3f4f6 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite;
    border-radius: 4px; flex-shrink: 0;
}
@keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }

/* Table */
.table-responsive { overflow-x: auto; }
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table thead { background: #f9fafb; border-bottom: 2px solid #e5e7eb; }
.data-table th {
    padding: 10px 14px; text-align: left; font-weight: 600;
    color: #6b7280; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;
    white-space: nowrap;
}
.data-table tbody tr { border-bottom: 1px solid #f3f4f6; transition: background 0.15s; }
.data-table tbody tr:hover { background: #fafafa; }
.data-table tbody tr.selected-row { background: #eff6ff !important; }
.badge-delay-yes { background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; font-weight:700; white-space:nowrap; }
.badge-delay-no  { background:#f3f4f6; color:#9ca3af; border:1px solid #e5e7eb; }
.btn-excel { background:#15803d; color:#fff; border:1px solid #15803d; display:inline-flex; align-items:center; gap:7px; cursor:pointer; }
.btn-excel:hover { background:#166534; color:#fff; }
.btn-excel:disabled { opacity:.7; cursor:wait; }
.data-table tbody tr.blacklisted-row { opacity: .65; }
.data-table td { padding: 12px 14px; color: #111827; vertical-align: middle; }

.row-check, .th-check-box { width:16px; height:16px; accent-color:#4338ca; cursor:pointer; }

/* Badges */
.badge {
    display: inline-block; padding: 3px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 600; white-space: nowrap;
}
.badge-primary   { background:#ede9fe; color:#6b21a8; }
.badge-channel   { background:#dbeafe; color:#1e40af; }
.badge-success   { background:#dcfce7; color:#166534; }
.badge-warning   { background:#fef3c7; color:#92400e; }
.badge-info      { background:#e0f2fe; color:#075985; }
.badge-secondary { background:#f3f4f6; color:#6b7280; }
.badge-non-approved { background:#fee2e2; color:#991b1b; }
.badge-approved  { background:#dcfce7; color:#166534; }

/* Blacklist category badges — same palette as the Credit Risk Report */
.rbadge { display:inline-flex; align-items:center; gap:4px; padding:2px 8px;
          border-radius:20px; font-size:10.5px; font-weight:700; white-space:nowrap; border:1px solid; }
.rbadge-bl-temporary     { background:#fffbeb; color:#b45309; border-color:#fde68a; }
.rbadge-bl-no_cheque_cod { background:#fff7ed; color:#c2410c; border-color:#fed7aa; }
.rbadge-bl-no_cash_cod   { background:#fff1f2; color:#be123c; border-color:#fecdd3; }
.rbadge-bl-permanent     { background:#1e1b4b; color:#c7d2fe; border-color:#4338ca; }
.rbadge-bl-no_service    { background:#111827; color:#f87171; border-color:#374151; }

/* Blacklist bulk toolbar */
.blacklist-toolbar { display:none; background:linear-gradient(135deg,#1e1b4b,#312e81);
                     border-radius:10px; padding:12px 16px; margin-bottom:16px;
                     border:1px solid #4338ca; box-shadow:0 4px 20px rgba(67,56,202,.25);
                     align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.blacklist-toolbar.visible { display:flex; }
.bl-info { display:flex; align-items:center; gap:10px; }
.bl-count { font-size:13px; font-weight:700; color:#c7d2fe; }
.bl-count span { color:#fff; font-size:16px; }
.bl-actions { display:flex; gap:8px; flex-wrap:wrap; }
.bl-btn { height:34px; padding:0 14px; border-radius:8px; font-size:12px; font-weight:700;
          cursor:pointer; font-family:'Inter',sans-serif; transition:all .15s; border:1px solid; display:flex; align-items:center; gap:5px; }
.bl-btn-block   { background:#dc2626; border-color:#dc2626; color:#fff; }
.bl-btn-block:hover { background:#b91c1c; }
.bl-btn-unblock { background:#16a34a; border-color:#16a34a; color:#fff; }
.bl-btn-unblock:hover { background:#15803d; }
.bl-btn-clear   { background:transparent; border-color:#6366f1; color:#a5b4fc; }
.bl-btn-clear:hover { background:rgba(99,102,241,.15); }

/* Blacklist modal */
.bl-modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:1010;
                     opacity:0; pointer-events:none; transition:opacity .22s; }
.bl-modal-backdrop.open { opacity:1; pointer-events:all; }
.bl-modal { position:fixed; top:50%; left:50%; transform:translate(-50%,-45%);
            width:min(500px,95vw); background:#fff; border-radius:16px;
            box-shadow:0 20px 60px rgba(0,0,0,.3); z-index:1011;
            opacity:0; pointer-events:none; transition:all .3s cubic-bezier(.34,1.56,.64,1); }
.bl-modal.open { opacity:1; pointer-events:all; transform:translate(-50%,-50%); }
.bl-modal-header { padding:18px 22px; display:flex; align-items:center; justify-content:space-between;
                   border-bottom:1px solid #f3f4f6; }
.bl-modal-header h3 { font-size:15px; font-weight:800; color:#111; margin:0; display:flex; align-items:center; gap:8px; }
.bl-modal-body { padding:20px 22px; max-height:60vh; overflow-y:auto; }
.bl-modal-footer { padding:16px 22px; border-top:1px solid #f3f4f6; display:flex; gap:8px; justify-content:flex-end; }
.drawer-close { width:32px; height:32px; border-radius:8px; border:1px solid #e5e7eb;
                background:#fff; font-size:16px; cursor:pointer; display:flex;
                align-items:center; justify-content:center; transition:all .12s; }
.drawer-close:hover { background:#111; color:#fff; border-color:#111; }
.bl-modal-list { max-height:160px; overflow-y:auto; border:1px solid #e5e7eb; border-radius:8px;
                 margin-bottom:14px; font-size:12px; }
.bl-modal-list-item { padding:7px 12px; border-bottom:1px solid #f3f4f6; display:flex;
                       align-items:center; justify-content:space-between; gap:8px; }
.bl-modal-list-item:last-child { border-bottom:none; }
.bl-modal-list-item .tc-code { font-family:monospace; font-weight:700; color:#1e40af; font-size:12px; }
.bl-modal-list-item .tc-name { font-size:12px; color:#6b7280; }
.bl-reason-label { font-size:12px; font-weight:600; color:#374151; margin-bottom:6px; }
.bl-reason-input { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px;
                   font-size:13px; font-family:'Inter',sans-serif; outline:none; resize:vertical;
                   min-height:60px; transition:border-color .15s; box-sizing:border-box; }
.bl-reason-input:focus { border-color:#4338ca; }
.bl-type-label { font-size:12px; font-weight:600; color:#374151; margin-bottom:6px; margin-top:14px; }
.bl-type-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:14px; }
.bl-type-option { display:flex; align-items:flex-start; gap:8px; border:1px solid #e5e7eb; border-radius:10px;
                   padding:9px 10px; cursor:pointer; transition:all .12s; }
.bl-type-option:hover { border-color:#9ca3af; background:#f9fafb; }
.bl-type-option.selected { border-color:#4338ca; background:#eef2ff; box-shadow:0 0 0 1px #4338ca inset; }
.bl-type-option input { margin-top:2px; accent-color:#4338ca; }
.bl-type-option-text .t-name { font-size:12px; font-weight:700; color:#111; display:block; }
.bl-type-option-text .t-desc { font-size:10.5px; color:#6b7280; display:block; margin-top:1px; }
.bl-warning { background:#fef2f2; border:1px solid #fecaca; border-radius:8px;
              padding:10px 14px; font-size:12px; color:#dc2626; margin-bottom:14px; line-height:1.5; }
.bl-success { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px;
              padding:10px 14px; font-size:12px; color:#16a34a; margin-bottom:14px; }

/* Action buttons */
.action-buttons { display: flex; gap: 6px; }
.btn-action {
    display:inline-flex;align-items:center;justify-content:center;
    width:30px;height:30px;border-radius:6px;
    border:1px solid #e5e7eb;background:#fff;color:#6b7280;
    cursor:pointer;transition:all 0.2s;text-decoration:none;font-size:12px;
}
.btn-action:hover { transform:translateY(-1px); box-shadow:0 2px 4px rgba(0,0,0,0.1); }
.btn-view:hover  { background:#3b82f6;color:#fff;border-color:#3b82f6; }
.btn-edit:hover  { background:#000;color:#fff;border-color:#000; }
.btn-delete:hover{ background:#ef4444;color:#fff;border-color:#ef4444; }
.btn-approve:hover{ background:#16a34a;color:#fff;border-color:#16a34a; }
.btn-blacklist:hover { background:#4338ca;color:#fff;border-color:#4338ca; }
.btn-unblacklist:hover { background:#16a34a;color:#fff;border-color:#16a34a; }

/* Pagination */
.page-btn {
    display:inline-flex;align-items:center;justify-content:center;
    min-width:32px;height:32px;padding:0 8px;
    border:1px solid #e5e7eb;border-radius:6px;background:#fff;
    font-size:12px;font-weight:500;color:#374151;cursor:pointer;
    transition:all 0.2s;font-family:'Inter',sans-serif;
}
.page-btn:hover { background:#f3f4f6; }
.page-btn.active { background:#000;color:#fff;border-color:#000; }
.page-btn:disabled { opacity:0.4;cursor:not-allowed; }

/* Misc */
.empty-state { text-align:center;padding:48px 20px; }
.btn {
    display:inline-flex;align-items:center;gap:6px;padding:9px 18px;
    border:none;border-radius:8px;font-size:13px;font-weight:600;
    cursor:pointer;transition:all 0.2s;text-decoration:none;font-family:'Inter',sans-serif;
}
.btn-primary { background:#000;color:#fff; }
.btn-primary:hover { background:#1f2937; }
.btn-light { background:#f9fafb;color:#374151;border:1px solid #d1d5db; }
.btn-light:hover { background:#f3f4f6; }

.alert {
    padding:10px 14px;border-radius:8px;margin-bottom:16px;
    display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500;
}
.alert-success { background:#dcfce7;color:#166534;border:1px solid #bbf7d0; }

@media(max-width:1024px){ .filter-grid{grid-template-columns:repeat(2,1fr);} }
@media(max-width:768px){
    .filter-grid{grid-template-columns:1fr;}
    .page-title{font-size:22px;}
    .status-toggle { order: 3; width: 100%; }
    .status-btn { flex: 1; justify-content: center; }
    .bl-type-grid { grid-template-columns:1fr; }
}
</style>

<script>
let searchTimer = null;
let currentPage = 1;
let currentStatus = 'active';
let currentRows = [];               // rows currently rendered (for the blacklist modal list)
let selectedTcodes = new Set();     // t_codes selected via row checkboxes
let blAction = 'blacklist';
let selectedBlType = '';

/* Blacklist category definitions — identical to the Credit Risk Report */
const BL_TYPE_META = {
    temporary:      { label: 'Temporary Block',       desc: 'Short-term hold, expected to be lifted later.',         icon: 'fa-hourglass-half',   cls: 'rbadge-bl-temporary' },
    no_cheque_cod:  { label: 'No Cheque on Delivery',  desc: 'Cheques no longer accepted at delivery.',               icon: 'fa-money-check',      cls: 'rbadge-bl-no_cheque_cod' },
    no_cash_cod:    { label: 'No Cash on Delivery',    desc: 'Cash no longer accepted at delivery.',                  icon: 'fa-money-bill-wave',  cls: 'rbadge-bl-no_cash_cod' },
    permanent:      { label: 'Permanent Block',        desc: 'Hard, indefinite stop on new credit transactions.',     icon: 'fa-lock',             cls: 'rbadge-bl-permanent' },
    no_service:     { label: 'Not Serviced',           desc: 'No deliveries to this customer until further notice.', icon: 'fa-ban',              cls: 'rbadge-bl-no_service' },
};

// ── Initial load ──────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    loadData(1);

    // Debounced search
    document.getElementById('searchInput').addEventListener('input', () => {
        clearTimeout(searchTimer);
        document.getElementById('searchSpinner').style.display = 'inline';
        searchTimer = setTimeout(() => loadData(1), 320);
    });

    // Auto-dismiss success alert
    const alert = document.getElementById('successAlert');
    if (alert) setTimeout(() => alert.style.display='none', 4000);
});

// ── Status toggle ─────────────────────────────────────────────────────────────
function setStatus(status) {
    currentStatus = status;

    document.querySelectorAll('.status-btn').forEach(btn => {
        btn.classList.remove('active', 'non-approved-active');
    });

    if (status === 'non_approved') {
        document.getElementById('btnNon_approved').classList.add('non-approved-active');
    } else if (status === 'active') {
        document.getElementById('btnActive').classList.add('active');
    } else {
        document.getElementById('btnAll').classList.add('active');
    }

    const titles = { active: 'Approved Customers', non_approved: 'Non-Approved Customers', all: 'All Customers' };
    document.getElementById('tableTitle').textContent = titles[status] || 'All Customers';

    loadData(1);
}

// ── Current search / filters (used by the list and the Excel export) ─────────
function currentFilters() {
    return {
        search:                 document.getElementById('searchInput').value.trim(),
        filter_primary_channel: document.getElementById('f_primary')?.value || '',
        filter_channel:         document.getElementById('f_channel')?.value || '',
        filter_payment_mode:    document.getElementById('f_payment')?.value || '',
        filter_status:          currentStatus,
        filter_blacklist:       document.getElementById('f_blacklist')?.value || '',
        filter_cheque_delay:    document.getElementById('f_delay')?.value || ''
    };
}

// ── Export to Excel: every row that matches, not just this page ──────────────
function exportExcel() {
    const btn = document.getElementById('exportBtn');
    const params = new URLSearchParams(Object.assign({ export: 'xlsx' }, currentFilters()));
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Preparing…';
    window.location.href = 'customers.php?' + params;
    setTimeout(() => { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-file-excel"></i> Export to Excel'; }, 2500);
}

// ── Core data loader ──────────────────────────────────────────────────────────
function loadData(page) {
    currentPage = page;

    const params = new URLSearchParams(Object.assign({ ajax: '1', page }, currentFilters()));

    document.getElementById('skeletonLoader').style.display = 'block';
    document.getElementById('tableWrapper').style.display  = 'none';
    document.getElementById('paginationWrapper').style.display = 'none';

    // Selections don't carry across pages/filters
    selectedTcodes.clear();
    updateBlToolbar();

    fetch('customers.php?' + params)
        .then(r => r.json())
        .then(data => {
            document.getElementById('searchSpinner').style.display = 'none';
            document.getElementById('skeletonLoader').style.display = 'none';
            document.getElementById('tableWrapper').style.display  = 'block';

            currentRows = data.rows || [];
            renderTable(currentRows);
            renderPagination(data.page, data.pages, data.total);

            document.getElementById('totalCount').textContent = data.total.toLocaleString();
        })
        .catch(() => {
            document.getElementById('searchSpinner').style.display = 'none';
            document.getElementById('skeletonLoader').style.display = 'none';
            document.getElementById('tableWrapper').style.display  = 'block';
            document.getElementById('tableBody').innerHTML =
                '<tr><td colspan="12" style="text-align:center;color:#ef4444;padding:32px;">Error loading data. Please refresh.</td></tr>';
        });
}

// ── Render table rows ─────────────────────────────────────────────────────────
function renderTable(rows) {
    const tbody = document.getElementById('tableBody');
    const empty = document.getElementById('emptyState');

    if (!rows.length) {
        tbody.innerHTML = '';
        empty.style.display = 'block';
        return;
    }
    empty.style.display = 'none';

    const paymentBadge = { cash:'badge-success', credit:'badge-warning', cheque:'badge-info' };

    tbody.innerHTML = rows.map(r => {
        const isBl = r.blacklisted == 1;
        const isSelected = selectedTcodes.has(r.t_code);
        return `
        <tr class="${isBl?'blacklisted-row':''}${isSelected?' selected-row':''}">
            <td onclick="event.stopPropagation()">
                <input type="checkbox" class="row-check" data-tc="${esc(r.t_code)}" ${isSelected?'checked':''} onchange="toggleSelect(this)">
            </td>
            <td><strong>${esc(r.t_code)}</strong></td>
            <td>
                ${esc(r.shop_name)}
                ${r.active == 0 ? ' <span class="badge badge-non-approved" style="font-size:10px;padding:2px 6px;">Non-Approved</span>' : ''}
            </td>
            <td>${r.route_name ? esc(r.route_name) : '<span style="color:#ccc;">—</span>'}</td>
            <td>${r.telephone_number ? esc(r.telephone_number) : '<span style="color:#ccc;">—</span>'}</td>
            <td>${r.primary_channel ? `<span class="badge badge-primary">${esc(r.primary_channel)}</span>` : '<span style="color:#ccc;">—</span>'}</td>
            <td>${r.channel ? `<span class="badge badge-channel">${esc(r.channel)}</span>` : '<span style="color:#ccc;">—</span>'}</td>
            <td><span class="badge ${paymentBadge[r.payment_mode] || 'badge-secondary'}">${cap(r.payment_mode)}</span></td>
            <td>${r.payment_mode === 'credit' ? `<strong>Rs. ${parseFloat(r.credit_limit).toLocaleString('en', {minimumFractionDigits:2})}</strong>` : '<span style="color:#ccc;">—</span>'}</td>
            <td>${r.cheques_delay == 1
                ? '<span class="badge badge-delay-yes" title="Customer\'s cheques will be delayed"><i class="fa-solid fa-clock"></i> Yes</span>'
                : '<span class="badge badge-delay-no">No</span>'}</td>
            <td>${isBl ? blTypeBadgeHtml(r.blacklist_type, r.blacklist_reason) : '<span style="color:#ccc;">—</span>'}</td>
            <td>
    <div class="action-buttons">
        <a href="view_customer.php?id=${r.id}" class="btn-action btn-view" title="View"><i class="fa-solid fa-eye"></i></a>
        <a href="edit_customer.php?id=${r.id}" class="btn-action btn-edit" title="Edit"><i class="fa-solid fa-pen"></i></a>
        <a href="upload_signature.php?id=${r.id}" class="btn-action btn-signature" title="Signature & Seal"><i class="fa-solid fa-file-signature"></i></a>
        ${r.active == 0 ? `<a href="approve_customer.php?id=${r.id}" class="btn-action btn-approve" title="Approve" onclick="return confirm('Approve this customer?')"><i class="fa-solid fa-check"></i></a>` : ''}
        ${isBl
            ? `<button type="button" class="btn-action btn-unblacklist" title="Remove from Blacklist" onclick="openSingleBlacklist('${esc(r.t_code)}','unblock')"><i class="fa-solid fa-circle-check"></i></button>`
            : `<button type="button" class="btn-action btn-blacklist" title="Blacklist Customer" onclick="openSingleBlacklist('${esc(r.t_code)}','blacklist')"><i class="fa-solid fa-ban"></i></button>`}
        <a href="delete_customer.php?id=${r.id}" class="btn-action btn-delete" title="Delete"
           onclick="return confirm('Delete this customer?')"><i class="fa-solid fa-trash"></i></a>
    </div>
</td>
        </tr>`;
    }).join('');

    updateMasterCheck();
}

function blTypeBadgeHtml(type, reason) {
    const meta = BL_TYPE_META[type];
    const title = reason ? `${meta ? meta.label : 'Blacklisted'} — ${reason}` : (meta ? meta.desc : 'Blacklisted');
    if (!meta) return `<span class="rbadge" style="background:#1e1b4b;color:#c7d2fe;border-color:#4338ca;" title="${esc(title)}"><i class="fa-solid fa-ban"></i> Blocked</span>`;
    return `<span class="rbadge ${meta.cls}" title="${esc(title)}"><i class="fa-solid ${meta.icon}"></i> ${esc(meta.label)}</span>`;
}

// ── Render pagination ─────────────────────────────────────────────────────────
function renderPagination(page, pages, total) {
    const wrapper  = document.getElementById('paginationWrapper');
    const btns     = document.getElementById('paginationBtns');
    const pageInfo = document.getElementById('pageInfo');
    const limit    = 25;
    const from     = (page-1)*limit + 1;
    const to       = Math.min(page*limit, total);

    if (pages <= 1) { wrapper.style.display = 'none'; return; }

    wrapper.style.display = 'block';
    pageInfo.textContent = `Showing ${from}–${to} of ${total.toLocaleString()} customers`;

    let html = '';
    html += `<button class="page-btn" onclick="loadData(${page-1})" ${page===1?'disabled':''}>‹</button>`;

    const range = [];
    range.push(1);
    if (page > 3) range.push('...');
    for (let i = Math.max(2, page-1); i <= Math.min(pages-1, page+1); i++) range.push(i);
    if (page < pages-2) range.push('...');
    if (pages > 1) range.push(pages);

    range.forEach(p => {
        if (p === '...') {
            html += `<span class="page-btn" style="cursor:default;">…</span>`;
        } else {
            html += `<button class="page-btn ${p===page?'active':''}" onclick="loadData(${p})">${p}</button>`;
        }
    });

    html += `<button class="page-btn" onclick="loadData(${page+1})" ${page===pages?'disabled':''}>›</button>`;
    btns.innerHTML = html;
}

/* ═══════════════════════════════════════════════════
   SELECTION (row checkboxes → bulk blacklist toolbar)
   ═══════════════════════════════════════════════════ */
function toggleSelect(cb) {
    const tc = cb.dataset.tc;
    if (cb.checked) selectedTcodes.add(tc); else selectedTcodes.delete(tc);
    const tr = cb.closest('tr');
    if (tr) tr.classList.toggle('selected-row', cb.checked);
    updateBlToolbar(); updateMasterCheck();
}
function masterCheckChange(cb) {
    document.querySelectorAll('.row-check').forEach(rc => {
        rc.checked = cb.checked;
        const tc = rc.dataset.tc;
        if (cb.checked) selectedTcodes.add(tc); else selectedTcodes.delete(tc);
        rc.closest('tr')?.classList.toggle('selected-row', cb.checked);
    });
    updateBlToolbar();
}
function selectAllVisible() {
    document.querySelectorAll('.row-check').forEach(rc => {
        rc.checked = true;
        selectedTcodes.add(rc.dataset.tc);
        rc.closest('tr')?.classList.add('selected-row');
    });
    updateBlToolbar(); updateMasterCheck();
}
function clearSelection() {
    selectedTcodes.clear();
    document.querySelectorAll('.row-check').forEach(rc => {
        rc.checked = false;
        rc.closest('tr')?.classList.remove('selected-row');
    });
    const mc = document.getElementById('masterCheck');
    if (mc) mc.checked = false;
    updateBlToolbar();
}
function updateMasterCheck() {
    const checks = document.querySelectorAll('.row-check');
    const mc = document.getElementById('masterCheck');
    if (!mc || !checks.length) return;
    const c = [...checks].filter(x => x.checked).length;
    mc.checked = c === checks.length && checks.length > 0;
    mc.indeterminate = c > 0 && c < checks.length;
}
function updateBlToolbar() {
    const toolbar = document.getElementById('blacklistToolbar');
    const count   = document.getElementById('blSelectedCount');
    const n = selectedTcodes.size;
    if (n > 0) { toolbar.classList.add('visible'); count.textContent = n; }
    else        { toolbar.classList.remove('visible'); }
}

/* Individual-row shortcut: select just this one customer, then open the modal */
function openSingleBlacklist(tc, action) {
    selectedTcodes.clear();
    selectedTcodes.add(tc);
    updateBlToolbar();
    openBlacklistModal(action);
}

/* ═══════════════════════════════════════════════════
   BLACKLIST MODAL
   ═══════════════════════════════════════════════════ */
function openBlacklistModal(action) {
    if (selectedTcodes.size === 0) { alert('Select at least one customer first.'); return; }
    blAction = action;
    selectedBlType = '';
    const isBlocking = action === 'blacklist';
    const title = isBlocking ? '🚫 Blacklist Customers' : '✅ Remove from Blacklist';
    document.getElementById('blModalTitle').innerHTML =
        `<i class="fa-solid fa-${isBlocking?'ban':'circle-check'}" style="color:${isBlocking?'#dc2626':'#16a34a'};"></i> ${title}`;
    const confirmBtn = document.getElementById('blConfirmBtn');
    confirmBtn.style.background   = isBlocking ? '#dc2626' : '#16a34a';
    confirmBtn.innerHTML = `<i class="fa-solid fa-${isBlocking?'ban':'circle-check'}"></i> ${isBlocking?'Confirm Blacklist':'Confirm Remove'}`;

    // Build the customer list from whatever rows are currently on screen
    const byTc = {};
    currentRows.forEach(r => byTc[r.t_code] = r);

    let listHtml = `<div class="bl-modal-list">`;
    selectedTcodes.forEach(tc => {
        const r = byTc[tc];
        listHtml += `<div class="bl-modal-list-item">
            <div><span class="tc-code">${esc(tc)}</span><span class="tc-name" style="margin-left:8px;">${r ? esc(r.shop_name) : ''}</span></div>
            ${r && r.blacklisted == 1 ? blTypeBadgeHtml(r.blacklist_type, '') : ''}
        </div>`;
    });
    listHtml += `</div>`;

    let content = '';
    if (isBlocking) {
        let typeGrid = `<div class="bl-type-label">Blacklist Category <span style="color:#dc2626;">*</span></div><div class="bl-type-grid">`;
        Object.entries(BL_TYPE_META).forEach(([key, meta]) => {
            typeGrid += `<label class="bl-type-option" data-bltype="${key}">
                <input type="radio" name="blType" value="${key}" onchange="selectedBlType='${key}'; refreshBlTypeSelection();">
                <span class="bl-type-option-text">
                    <span class="t-name"><i class="fa-solid ${meta.icon}"></i> ${esc(meta.label)}</span>
                    <span class="t-desc">${esc(meta.desc)}</span>
                </span>
            </label>`;
        });
        typeGrid += `</div>`;

        content = `<div class="bl-warning">⚠️ <strong>Warning:</strong> Blacklisting will restrict these ${selectedTcodes.size} customer(s) according to the category you choose below.</div>
            ${listHtml}
            ${typeGrid}
            <div class="bl-reason-label">Reason / Remark <span style="color:#dc2626;">*</span></div>
            <textarea class="bl-reason-input" id="blReason" placeholder="Enter reason or remark…"></textarea>`;
    } else {
        content = `<div class="bl-success">✅ These ${selectedTcodes.size} customer(s) will be removed from the blacklist and their category cleared.</div>${listHtml}`;
    }
    document.getElementById('blModalContent').innerHTML = content;
    document.getElementById('blBackdrop').classList.add('open');
    document.getElementById('blModal').classList.add('open');
}
function refreshBlTypeSelection() {
    document.querySelectorAll('.bl-type-option').forEach(el => {
        el.classList.toggle('selected', el.dataset.bltype === selectedBlType);
    });
}
function closeBlModal() {
    document.getElementById('blBackdrop').classList.remove('open');
    document.getElementById('blModal').classList.remove('open');
}
function confirmBlacklist() {
    const reason = document.getElementById('blReason') ? document.getElementById('blReason').value.trim() : '';
    if (blAction === 'blacklist') {
        if (!selectedBlType) { alert('Please choose a blacklist category.'); return; }
        if (!reason) { alert('Please enter a reason / remark for blacklisting.'); return; }
    }
    const confirmBtn = document.getElementById('blConfirmBtn');
    const oldHtml = confirmBtn.innerHTML;
    confirmBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    confirmBtn.disabled  = true;

    fetch('customers.php?ajax=blacklist_action', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: blAction, t_codes: [...selectedTcodes], reason, type: selectedBlType }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) { alert('Error: ' + data.error); }
        else {
            closeBlModal(); clearSelection();
            const typeLabel = (BL_TYPE_META[data.type] || {}).label || '';
            showToast(blAction === 'blacklist'
                ? `✅ ${data.affected} customer(s) blacklisted${typeLabel ? ' — ' + typeLabel : ''}.`
                : `✅ ${data.affected} customer(s) removed from blacklist.`,
                blAction === 'blacklist' ? '#dc2626' : '#16a34a');
            loadData(currentPage);
        }
    })
    .catch(err => alert('Network error: ' + err.message))
    .finally(() => { confirmBtn.disabled = false; confirmBtn.innerHTML = oldHtml; });
}

function showToast(msg, color='#111') {
    const t = document.createElement('div');
    t.style.cssText = `position:fixed;bottom:24px;right:24px;z-index:9999;background:${color};color:#fff;
                       padding:12px 20px;border-radius:10px;font-size:13px;font-weight:700;
                       box-shadow:0 4px 20px rgba(0,0,0,.25);`;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

function toggleFilters() {
    const sec = document.getElementById('filters-section');
    const btn = document.getElementById('filterToggleBtn');
    const open = sec.style.display === 'none';
    sec.style.display = open ? 'block' : 'none';
    btn.classList.toggle('active', open);
}

function clearFilters() {
    document.getElementById('f_primary').value = '';
    document.getElementById('f_channel').value = '';
    document.getElementById('f_payment').value = '';
    document.getElementById('f_blacklist').value = '';
    document.getElementById('f_delay').value = '';
    document.getElementById('searchInput').value = '';
    loadData(1);
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeBlModal();
});
</script>

<?php include 'footer.php'; ?>