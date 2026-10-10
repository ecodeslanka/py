<?php
// ============================================================
//  sampath_super_payment.php  –  Super Payment Entry
// ============================================================
ob_start();
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── AJAX handlers ────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    ob_end_clean();
    header('Content-Type: application/json');

    if ($_GET['ajax'] === 'search_invoice') {
        $q = trim(mysqli_real_escape_string($conn, $_GET['q'] ?? ''));
        if (strlen($q) < 2) { echo json_encode([]); exit; }
        $sql = "SELECT d.id, d.bill_no, d.party_name, d.customer_name, d.route_name,
                       d.sales_person_code, d.final_bill_amount, d.bill_date,
                       d.gross_sales, d.total_discount, d.bill_value,
                       d.good_returns_value, d.damage_expiry_shortage_value,
                       i.delivery_date, i.filename
                FROM secondary_invoice_import_details d
                JOIN secondary_invoice_imports i ON i.id = d.import_id
                WHERE d.bill_no LIKE '%" . mysqli_real_escape_string($conn, $_GET['q']) . "%'
                ORDER BY i.delivery_date DESC, d.bill_no ASC
                LIMIT 20";
        $res  = mysqli_query($conn, $sql);
        $rows = [];
        if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        echo json_encode($rows);
        exit;
    }

    if ($_GET['ajax'] === 'get_credit_notes') {
        $sql = "SELECT id, grn_no, amount, branch_name, customer_id, status
                FROM sampath_damage_credit_notes
                WHERE status = 'unused'
                ORDER BY created_at DESC";
        $res = mysqli_query($conn, $sql);
        if (!$res) { echo json_encode(['error' => mysqli_error($conn)]); exit; }
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $r['customer_name'] = $r['branch_name'];
            $rows[] = $r;
        }
        echo json_encode($rows);
        exit;
    }

    echo json_encode(['error' => 'Unknown ajax action']);
    exit;
}

// ── AJAX: Save payment ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_payment') {
    ob_end_clean();
    header('Content-Type: application/json');

    $invoices     = json_decode($_POST['invoices']     ?? '[]', true);
    $credit_notes = json_decode($_POST['credit_notes'] ?? '[]', true);
    $payment_date = mysqli_real_escape_string($conn, $_POST['payment_date'] ?? date('Y-m-d'));
    $reference    = mysqli_real_escape_string($conn, $_POST['reference']    ?? '');
    $remarks      = mysqli_real_escape_string($conn, $_POST['remarks']      ?? '');
    $created_by   = mysqli_real_escape_string($conn, $_SESSION['username']  ?? 'system');

    if (empty($invoices)) {
        echo json_encode(['ok' => false, 'msg' => 'No invoices selected.']);
        exit;
    }

    $total_invoices = round(array_sum(array_column($invoices,     'final_bill_amount')), 2);
    $total_cn       = round(array_sum(array_column($credit_notes, 'amount')),            2);
    $net_payable    = round($total_invoices - $total_cn, 2);

    $ok = mysqli_query($conn, "INSERT INTO sampath_super_payments
        (payment_date, reference, remarks, total_invoice_amount, total_credit_note_amount, net_payable, created_by, created_at)
        VALUES ('$payment_date','$reference','$remarks',$total_invoices,$total_cn,$net_payable,'$created_by',NOW())");

    if (!$ok) { echo json_encode(['ok' => false, 'msg' => 'DB error: ' . mysqli_error($conn)]); exit; }

    $payment_id = mysqli_insert_id($conn);

    foreach ($invoices as $inv) {
        $bill_no    = mysqli_real_escape_string($conn, $inv['bill_no']    ?? '');
        $party_name = mysqli_real_escape_string($conn, $inv['party_name'] ?? '');
        $amount     = round((float)($inv['final_bill_amount'] ?? 0), 2);
        $detail_id  = (int)($inv['id'] ?? 0);
        mysqli_query($conn, "INSERT INTO sampath_super_payment_invoices
            (payment_id, detail_id, bill_no, party_name, amount)
            VALUES ($payment_id, $detail_id, '$bill_no', '$party_name', $amount)");
    }

    foreach ($credit_notes as $cn) {
        $cn_id  = (int)($cn['id']     ?? 0);
        $grn_no = mysqli_real_escape_string($conn, $cn['grn_no'] ?? '');
        $amount = round((float)($cn['amount'] ?? 0), 2);
        mysqli_query($conn, "INSERT INTO sampath_super_payment_credit_notes
            (payment_id, cn_id, grn_no, amount)
            VALUES ($payment_id, $cn_id, '$grn_no', $amount)");
        if ($cn_id) {
            mysqli_query($conn, "UPDATE sampath_damage_credit_notes SET status='used' WHERE id=$cn_id");
        }
    }

    echo json_encode(['ok' => true, 'payment_id' => $payment_id, 'net_payable' => $net_payable]);
    exit;
}

// ── Ensure tables exist ───────────────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_super_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_date DATE NOT NULL,
    reference VARCHAR(100) NULL,
    remarks TEXT NULL,
    total_invoice_amount DECIMAL(14,2) DEFAULT 0,
    total_credit_note_amount DECIMAL(14,2) DEFAULT 0,
    net_payable DECIMAL(14,2) DEFAULT 0,
    created_by VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment_date (payment_date)
)");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_super_payment_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    detail_id INT NULL,
    bill_no VARCHAR(100) NULL,
    party_name VARCHAR(255) NULL,
    amount DECIMAL(12,2) DEFAULT 0,
    INDEX idx_payment_id (payment_id)
)");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_super_payment_credit_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    cn_id INT NULL,
    grn_no VARCHAR(100) NULL,
    amount DECIMAL(12,2) DEFAULT 0,
    INDEX idx_payment_id (payment_id)
)");

include 'header.php';
?>

<style>
/* ── Base ─────────────────────────────────────────────────── */
*{box-sizing:border-box;}
:root{
    --ink:#0d1117;--muted:#5b6675;--border:#dde3ec;--bg:#f4f6fa;
    --white:#fff;
    --sky:#0ea5e9;--sky-dark:#0284c7;--sky-light:#e0f2fe;
    --emerald:#10b981;--emerald-light:#dcfce7;
    --amber:#f59e0b;--amber-light:#fef3c7;
    --rose:#ef4444;--rose-light:#fee2e2;
    --violet:#7c3aed;--violet-light:#ede9fe;
    --radius:10px;
    --shadow:0 2px 16px rgba(13,17,23,.08);
    --shadow-lg:0 8px 40px rgba(13,17,23,.13);
}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--ink);font-size:14px;}

/* ── Page Header ─────────────────────────────────────────── */
.ssp-page-hd{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:22px;flex-wrap:wrap;gap:12px;}
.ssp-title{font-size:21px;font-weight:800;margin:0 0 3px;display:flex;align-items:center;gap:10px;}
.ssp-sub{font-size:13px;color:var(--muted);margin:0;}

/* ── Layout ──────────────────────────────────────────────── */
.ssp-layout{display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start;}
@media(max-width:900px){.ssp-layout{grid-template-columns:1fr;}}

/* ── Cards ───────────────────────────────────────────────── */
.ssp-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:18px;}
.ssp-card-h{display:flex;align-items:center;gap:9px;padding:13px 18px;border-bottom:1px solid var(--border);background:#f8fafc;font-size:13px;font-weight:700;color:var(--ink);}
.ssp-card-b{padding:18px;}
.ssp-card-h .badge-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;border-radius:10px;background:var(--sky-light);color:var(--sky-dark);font-size:11px;font-weight:800;padding:0 6px;margin-left:auto;}

/* ── Invoice Search ──────────────────────────────────────── */
.inv-search-wrap{position:relative;margin-bottom:14px;}
.inv-search-input{width:100%;padding:11px 16px 11px 42px;border:2px solid var(--border);border-radius:8px;font-size:14px;font-family:inherit;color:var(--ink);transition:border-color .18s;outline:none;background:var(--white);}
.inv-search-input:focus{border-color:var(--sky);}
.inv-search-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:15px;pointer-events:none;}
.inv-search-clear{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;padding:4px;border-radius:4px;display:none;}
.inv-search-clear:hover{color:var(--rose);}

/* ── Dropdown results ────────────────────────────────────── */
.inv-dropdown{position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--white);border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-lg);z-index:999;max-height:320px;overflow-y:auto;display:none;}
.inv-dropdown.show{display:block;}
.inv-drop-item{padding:11px 16px;cursor:pointer;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;gap:10px;transition:background .13s;}
.inv-drop-item:last-child{border-bottom:none;}
.inv-drop-item:hover{background:#f0f9ff;}
.inv-drop-item.already{opacity:.5;cursor:default;background:#f9fafb;}
.inv-drop-bill{font-weight:700;font-size:13px;color:var(--sky-dark);}
.inv-drop-party{font-size:12px;color:var(--muted);margin-top:2px;}
.inv-drop-amount{font-weight:800;font-size:13px;color:var(--emerald);white-space:nowrap;}
.inv-drop-meta{font-size:10px;color:#9ca3af;}
.inv-drop-loading,.inv-drop-empty{padding:18px 16px;text-align:center;color:var(--muted);font-size:13px;}

/* ── Invoice Table ───────────────────────────────────────── */
.inv-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.inv-tbl thead{background:#f8fafc;}
.inv-tbl th{padding:9px 12px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:700;border-bottom:2px solid var(--border);white-space:nowrap;}
.inv-tbl td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.inv-tbl tbody tr:hover td{background:#f8fafc;}
.inv-tbl tbody tr:last-child td{border-bottom:none;}
.inv-tbl tfoot td{background:#f0f9ff;font-weight:700;padding:10px 12px;border-top:2px solid var(--border);}
.inv-empty-row td{text-align:center;padding:32px 12px;color:var(--muted);font-size:13px;}
.inv-del-btn{background:none;border:none;color:#d1d5db;cursor:pointer;font-size:14px;padding:4px 6px;border-radius:5px;transition:all .15s;}
.inv-del-btn:hover{color:var(--rose);background:var(--rose-light);}

/* ── CN Custom Picker ────────────────────────────────────── */
.cn-picker-wrap{position:relative;margin-bottom:14px;}
.cn-picker-input-row{display:flex;gap:8px;align-items:center;}
.cn-picker-box{
    flex:1;display:flex;align-items:center;gap:10px;
    padding:10px 14px;
    border:2px solid var(--border);border-radius:8px;
    cursor:pointer;background:var(--white);
    transition:border-color .18s;
    min-width:0;user-select:none;
}
.cn-picker-box:hover{border-color:var(--violet);}
.cn-picker-icon{color:var(--violet);font-size:15px;flex-shrink:0;}
.cn-picker-arrow{color:var(--muted);font-size:11px;margin-left:auto;flex-shrink:0;transition:transform .2s;}
#cn-picker-label{
    font-size:13px;color:var(--muted);
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    flex:1;min-width:0;
}
.cn-picker-drop{
    display:none;
    position:absolute;top:calc(100% + 4px);left:0;right:0;
    background:var(--white);
    border:1px solid var(--border);border-radius:8px;
    box-shadow:var(--shadow-lg);
    z-index:1000;overflow:hidden;
}
.cn-picker-drop.open{display:block;}
.cn-picker-search-wrap{position:relative;padding:10px 10px 6px;}
.cn-ps-icon{
    position:absolute;left:20px;top:50%;
    transform:translateY(-50%);
    color:var(--muted);font-size:13px;pointer-events:none;margin-top:2px;
}
.cn-picker-search{
    width:100%;padding:8px 12px 8px 34px;
    border:1.5px solid var(--border);border-radius:7px;
    font-size:13px;font-family:inherit;color:var(--ink);
    background:#f8fafc;outline:none;
    transition:border-color .18s;
}
.cn-picker-search:focus{border-color:var(--violet);}
.cn-picker-list{max-height:240px;overflow-y:auto;}
.cn-picker-item{
    padding:10px 14px;cursor:pointer;
    border-bottom:1px solid #f5f3ff;
    transition:background .13s;
}
.cn-picker-item:last-child{border-bottom:none;}
.cn-picker-item:hover,.cn-picker-item.selected{background:#f5f3ff;}
.cn-pi-top{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:3px;}
.cn-pi-grn{font-weight:700;font-size:13px;color:#6d28d9;}
.cn-pi-amt{font-weight:800;font-size:13px;color:var(--emerald);white-space:nowrap;}
.cn-pi-branch{font-size:12px;color:var(--muted);}
.cn-picker-loading,.cn-picker-empty{
    padding:18px 14px;text-align:center;
    font-size:13px;color:var(--muted);
}

/* ── CN Table ────────────────────────────────────────────── */
.cn-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.cn-tbl thead{background:#f5f3ff;}
.cn-tbl th{padding:9px 12px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#6d28d9;font-weight:700;border-bottom:2px solid #ddd6fe;white-space:nowrap;}
.cn-tbl td{padding:10px 12px;border-bottom:1px solid #f5f3ff;vertical-align:middle;}
.cn-tbl tbody tr:hover td{background:#faf5ff;}
.cn-tbl tbody tr:last-child td{border-bottom:none;}
.cn-tbl tfoot td{background:#f5f3ff;font-weight:700;padding:10px 12px;border-top:2px solid #ddd6fe;}
.cn-empty-row td{text-align:center;padding:22px 12px;color:var(--muted);font-size:13px;}

/* ── Summary Sidebar ─────────────────────────────────────── */
.summary-card{background:linear-gradient(135deg,#0ea5e9 0%,#0284c7 100%);border-radius:var(--radius);padding:22px;color:#fff;margin-bottom:18px;box-shadow:0 8px 24px rgba(14,165,233,.28);}
.summary-card h3{margin:0 0 18px;font-size:15px;font-weight:700;opacity:.9;display:flex;align-items:center;gap:8px;}
.summary-row{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.15);font-size:13px;}
.summary-row:last-of-type{border-bottom:none;}
.summary-row .lbl{opacity:.85;}
.summary-row .val{font-weight:700;font-size:14px;}
.summary-row.cn-row .val{color:#a7f3d0;}
.summary-divider{border:none;border-top:2px solid rgba(255,255,255,.3);margin:12px 0;}
.summary-net-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0 0;}
.summary-net-lbl{font-size:14px;font-weight:700;}
.summary-net-val{font-size:22px;font-weight:900;letter-spacing:-.5px;}

/* ── Payment Meta Card ───────────────────────────────────── */
.meta-group{margin-bottom:14px;}
.meta-label{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.meta-input{width:100%;padding:10px 13px;border:2px solid var(--border);border-radius:7px;font-size:13px;font-family:inherit;color:var(--ink);transition:border-color .18s;outline:none;background:var(--white);}
.meta-input:focus{border-color:var(--sky);}
textarea.meta-input{resize:vertical;min-height:62px;}

/* ── Buttons ─────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap;}
.btn-sm{padding:6px 12px;font-size:12px;}
.btn-sky{background:var(--sky);color:#fff;}.btn-sky:hover{background:var(--sky-dark);}
.btn-emerald{background:var(--emerald);color:#fff;box-shadow:0 4px 14px rgba(16,185,129,.28);}.btn-emerald:hover{background:#059669;}
.btn-ghost{background:#f1f5f9;color:var(--ink);border:1px solid var(--border);}.btn-ghost:hover{background:#e2e8f0;}
.btn-violet{background:var(--violet);color:#fff;}.btn-violet:hover{background:#6d28d9;}
.btn-rose{background:var(--rose-light);color:#991b1b;border:1px solid #fecaca;}.btn-rose:hover{background:var(--rose);color:#fff;}
.btn:disabled{opacity:.5;cursor:not-allowed;}
.btn-block{width:100%;justify-content:center;}

/* ── Badge ───────────────────────────────────────────────── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-sky{background:var(--sky-light);color:var(--sky-dark);}
.badge-violet{background:var(--violet-light);color:var(--violet);}
.badge-emerald{background:var(--emerald-light);color:#065f46;}
.badge-amber{background:var(--amber-light);color:#92400e;}

/* ── Toast ───────────────────────────────────────────────── */
.toast-wrap{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px;}
.toast{padding:12px 18px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.15);display:flex;align-items:center;gap:8px;animation:toastIn .25s ease;max-width:360px;}
.toast-success{background:#065f46;color:#fff;}
.toast-error{background:#991b1b;color:#fff;}
@keyframes toastIn{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}

/* ── Success overlay ─────────────────────────────────────── */
.success-overlay{position:fixed;inset:0;background:rgba(13,17,23,.55);z-index:9900;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .22s;}
.success-overlay.show{opacity:1;pointer-events:all;}
.success-box{background:#fff;border-radius:16px;padding:38px 40px;text-align:center;max-width:440px;width:100%;box-shadow:var(--shadow-lg);}
.success-icon{width:64px;height:64px;background:var(--emerald-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;font-size:28px;color:var(--emerald);}
.success-box h3{margin:0 0 8px;font-size:20px;font-weight:800;}
.success-box p{margin:0 0 24px;color:var(--muted);font-size:14px;}
.success-net{font-size:32px;font-weight:900;color:var(--sky-dark);margin:16px 0;}

/* ── Misc ────────────────────────────────────────────────── */
.sep{height:1px;background:var(--border);margin:14px 0;}

@media(max-width:700px){
    .ssp-layout{grid-template-columns:1fr;}
    .inv-tbl,.cn-tbl{font-size:12px;}
    .cn-picker-input-row{flex-wrap:wrap;}
}
</style>

<div class="ssp-page-hd">
    <div>
        <h2 class="ssp-title">
            <i class="fa-solid fa-building-columns" style="color:var(--sky);"></i>
            Sampath Super Payment
        </h2>
        <p class="ssp-sub">Enter invoices and apply credit notes to calculate net payable amount</p>
    </div>
    <a href="sampath_damage_batches.php" class="btn btn-ghost btn-sm">
        <i class="fa-solid fa-arrow-left"></i> Back to Batches
    </a>
</div>

<div class="ssp-layout">

    <!-- ══ LEFT COLUMN ══════════════════════════════════════ -->
    <div>

        <!-- ── Invoice Search & Entry ───────────────────── -->
        <div class="ssp-card">
            <div class="ssp-card-h">
                <i class="fa-solid fa-file-invoice" style="color:var(--sky);"></i>
                Invoices
                <span class="badge-count" id="inv-count">0</span>
            </div>
            <div class="ssp-card-b">

                <div class="inv-search-wrap" id="inv-search-wrap">
                    <i class="fa-solid fa-magnifying-glass inv-search-icon"></i>
                    <input type="text" id="inv-search" class="inv-search-input"
                           placeholder="Type invoice no. to search…" autocomplete="off">
                    <button class="inv-search-clear" id="inv-clear-btn" onclick="clearInvSearch()">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                    <div class="inv-dropdown" id="inv-dropdown">
                        <div class="inv-drop-loading" id="inv-drop-loading" style="display:none;">
                            <i class="fa-solid fa-spinner fa-spin"></i> Searching…
                        </div>
                        <div id="inv-drop-results"></div>
                        <div class="inv-drop-empty" id="inv-drop-empty" style="display:none;">
                            No matching invoices found.
                        </div>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                <table class="inv-tbl" id="inv-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Invoice No</th>
                            <th>Party Name</th>
                            <th>Route</th>
                            <th>Bill Date</th>
                            <th style="text-align:right;">Gross Sales</th>
                            <th style="text-align:right;">Discount</th>
                            <th style="text-align:right;">Returns</th>
                            <th style="text-align:right;">Final Amount</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="inv-tbody">
                        <tr class="inv-empty-row" id="inv-empty-row">
                            <td colspan="10">
                                <i class="fa-solid fa-inbox" style="display:block;font-size:28px;margin-bottom:8px;color:#d1d5db;"></i>
                                Search and add invoices above
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="8" style="text-align:right;font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);padding:10px 12px;">Total Invoice Amount</td>
                            <td style="text-align:right;font-size:15px;color:var(--sky-dark);" id="inv-total-cell">0.00</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                </div>
            </div>
        </div>

        <!-- ── Credit Notes ──────────────────────────────── -->
        <div class="ssp-card">
            <div class="ssp-card-h">
                <i class="fa-solid fa-file-invoice-dollar" style="color:var(--violet);"></i>
                Credit Notes
                <span class="badge-count" id="cn-count" style="background:var(--violet-light);color:var(--violet);">0</span>
            </div>
            <div class="ssp-card-b">

                <!-- CN custom searchable picker -->
                <div class="cn-picker-wrap" id="cn-picker-wrap">
                    <div class="cn-picker-input-row">
                        <div class="cn-picker-box" id="cn-picker-box" onclick="toggleCNPicker()">
                            <i class="fa-solid fa-file-invoice-dollar cn-picker-icon"></i>
                            <span id="cn-picker-label">Search GRN No or outlet name…</span>
                            <i class="fa-solid fa-chevron-down cn-picker-arrow" id="cn-picker-arrow"></i>
                        </div>
                        <button class="btn btn-violet btn-sm" onclick="addSelectedCN()" id="cn-add-btn" disabled>
                            <i class="fa-solid fa-plus"></i> Add
                        </button>
                    </div>
                    <div class="cn-picker-drop" id="cn-picker-drop">
                        <div class="cn-picker-search-wrap">
                            <i class="fa-solid fa-magnifying-glass cn-ps-icon"></i>
                            <input type="text" id="cn-picker-search" class="cn-picker-search"
                                   placeholder="Type GRN No or outlet…"
                                   oninput="filterCNOptions()" autocomplete="off">
                        </div>
                        <div class="cn-picker-list" id="cn-picker-list">
                            <div class="cn-picker-loading">
                                <i class="fa-solid fa-spinner fa-spin"></i> Loading…
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CN Table -->
                <div style="overflow-x:auto;">
                <table class="cn-tbl" id="cn-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>GRN No</th>
                            <th>Outlet / Branch</th>
                            <th>Customer ID</th>
                            <th style="text-align:right;">Credit Amount (LKR)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="cn-tbody">
                        <tr class="cn-empty-row" id="cn-empty-row">
                            <td colspan="6">
                                <i class="fa-solid fa-file-circle-xmark" style="display:block;font-size:22px;margin-bottom:6px;color:#d1d5db;"></i>
                                No credit notes added yet
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" style="text-align:right;font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:#6d28d9;padding:10px 12px;">Total Credit Notes</td>
                            <td style="text-align:right;font-size:15px;color:var(--violet);" id="cn-total-cell">0.00</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                </div>

            </div>
        </div>

    </div>

    <!-- ══ RIGHT COLUMN ═════════════════════════════════════ -->
    <div>

        <!-- Payment Summary -->
        <div class="summary-card">
            <h3><i class="fa-solid fa-calculator"></i> Payment Summary</h3>
            <div class="summary-row">
                <span class="lbl"><i class="fa-solid fa-file-invoice"></i> Total Invoices</span>
                <span class="val" id="sum-inv-count">0 invoice(s)</span>
            </div>
            <div class="summary-row">
                <span class="lbl">Invoice Amount</span>
                <span class="val" id="sum-inv-amount">LKR 0.00</span>
            </div>
            <div class="summary-row cn-row">
                <span class="lbl"><i class="fa-solid fa-file-invoice-dollar"></i> Credit Notes</span>
                <span class="val" id="sum-cn-count">0 note(s)</span>
            </div>
            <div class="summary-row cn-row">
                <span class="lbl">Credit Note Total</span>
                <span class="val" id="sum-cn-amount">– LKR 0.00</span>
            </div>
            <hr class="summary-divider">
            <div class="summary-net-row">
                <span class="summary-net-lbl">Net Payable</span>
                <span class="summary-net-val" id="sum-net">LKR 0.00</span>
            </div>
        </div>

        <!-- Payment Meta -->
        <div class="ssp-card">
            <div class="ssp-card-h">
                <i class="fa-solid fa-pen-to-square" style="color:var(--amber);"></i>
                Payment Details
            </div>
            <div class="ssp-card-b">
                <div class="meta-group">
                    <div class="meta-label">Payment Date</div>
                    <input type="date" class="meta-input" id="pay-date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="meta-group">
                    <div class="meta-label">Reference / Cheque No.</div>
                    <input type="text" class="meta-input" id="pay-ref" placeholder="e.g. CHQ-001234">
                </div>
                <div class="meta-group">
                    <div class="meta-label">Remarks</div>
                    <textarea class="meta-input" id="pay-remarks" placeholder="Optional notes…"></textarea>
                </div>
            </div>
        </div>

        <!-- Save Button -->
        <button class="btn btn-emerald btn-block" id="save-btn" onclick="savePayment()" disabled>
            <i class="fa-solid fa-floppy-disk"></i> Save Payment Entry
        </button>
        <p style="font-size:12px;color:var(--muted);text-align:center;margin-top:8px;">
            Add at least one invoice to save.
        </p>

    </div>
</div>

<!-- Toast container -->
<div class="toast-wrap" id="toast-wrap"></div>

<!-- Success overlay -->
<div class="success-overlay" id="success-overlay">
    <div class="success-box">
        <div class="success-icon"><i class="fa-solid fa-circle-check"></i></div>
        <h3>Payment Saved!</h3>
        <p>Your payment entry has been recorded successfully.</p>
        <div class="success-net" id="success-net-val">LKR 0.00</div>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
            <button class="btn btn-ghost" onclick="resetAll()">
                <i class="fa-solid fa-plus"></i> New Payment
            </button>
            <a href="sampath_damage_batches.php" class="btn btn-sky">
                <i class="fa-solid fa-arrow-left"></i> Back to Batches
            </a>
        </div>
    </div>
</div>

<script>
// ═══════════════════════════════════════════════════════════
//  STATE
// ═══════════════════════════════════════════════════════════
let invoices    = [];
let creditNotes = [];
let allCNs      = [];
let selectedCN  = null;

// ═══════════════════════════════════════════════════════════
//  INVOICE SEARCH
// ═══════════════════════════════════════════════════════════
let searchTimer = null;
const searchInput = document.getElementById('inv-search');
const dropdown    = document.getElementById('inv-dropdown');
const dropResults = document.getElementById('inv-drop-results');
const dropLoading = document.getElementById('inv-drop-loading');
const dropEmpty   = document.getElementById('inv-drop-empty');
const clearBtn    = document.getElementById('inv-clear-btn');

searchInput.addEventListener('input', function() {
    const q = this.value.trim();
    clearBtn.style.display = q ? 'block' : 'none';
    if (q.length < 2) { hideDropdown(); return; }
    clearTimeout(searchTimer);
    dropLoading.style.display = 'block';
    dropEmpty.style.display   = 'none';
    dropResults.innerHTML     = '';
    dropdown.classList.add('show');
    searchTimer = setTimeout(() => fetchInvoices(q), 320);
});

searchInput.addEventListener('focus', function() {
    if (this.value.trim().length >= 2) dropdown.classList.add('show');
});

document.addEventListener('click', function(e) {
    if (!document.getElementById('inv-search-wrap').contains(e.target)) hideDropdown();
});

function hideDropdown() { dropdown.classList.remove('show'); }

function clearInvSearch() {
    searchInput.value = '';
    clearBtn.style.display = 'none';
    hideDropdown();
    dropResults.innerHTML = '';
}

async function fetchInvoices(q) {
    try {
        const res  = await fetch(`?ajax=search_invoice&q=${encodeURIComponent(q)}`);
        const data = await res.json();
        dropLoading.style.display = 'none';
        renderDropdown(data);
    } catch(e) {
        dropLoading.style.display = 'none';
        dropEmpty.style.display   = 'block';
        dropEmpty.textContent     = 'Error searching. Please try again.';
    }
}

function renderDropdown(rows) {
    dropResults.innerHTML = '';
    if (!rows.length) { dropEmpty.style.display = 'block'; return; }
    dropEmpty.style.display = 'none';
    rows.forEach(r => {
        const alreadyAdded = invoices.some(i => i.id == r.id);
        const div = document.createElement('div');
        div.className = 'inv-drop-item' + (alreadyAdded ? ' already' : '');
        div.innerHTML = `
            <div style="flex:1;min-width:0;">
                <div class="inv-drop-bill">${esc(r.bill_no)}</div>
                <div class="inv-drop-party">${esc(r.party_name || r.customer_name || '—')}</div>
                <div class="inv-drop-meta">${r.bill_date ? fmtDate(r.bill_date) : ''} &nbsp;·&nbsp; ${esc(r.route_name || '—')}</div>
            </div>
            <div style="text-align:right;flex-shrink:0;">
                <div class="inv-drop-amount">LKR ${fmt(r.final_bill_amount)}</div>
                ${alreadyAdded ? '<div class="inv-drop-meta" style="color:#22c55e;">✓ Added</div>' : ''}
            </div>`;
        if (!alreadyAdded) {
            div.onclick = () => { addInvoice(r); clearInvSearch(); hideDropdown(); };
        }
        dropResults.appendChild(div);
    });
}

function addInvoice(r) {
    if (invoices.some(i => i.id == r.id)) { showToast('Invoice already added.', 'error'); return; }
    invoices.push(r);
    renderInvTable();
    updateSummary();
    showToast(`Invoice ${r.bill_no} added.`, 'success');
}

function removeInvoice(id) {
    invoices = invoices.filter(i => i.id != id);
    renderInvTable();
    updateSummary();
}

function renderInvTable() {
    const tbody = document.getElementById('inv-tbody');
    document.getElementById('inv-count').textContent = invoices.length;

    if (!invoices.length) {
        tbody.innerHTML = `<tr class="inv-empty-row"><td colspan="10">
            <i class="fa-solid fa-inbox" style="display:block;font-size:28px;margin-bottom:8px;color:#d1d5db;"></i>
            Search and add invoices above</td></tr>`;
        document.getElementById('inv-total-cell').textContent = '0.00';
        return;
    }

    let totalFinal = 0;
    tbody.innerHTML = invoices.map((r, i) => {
        const gross  = parseFloat(r.gross_sales  || 0);
        const disc   = parseFloat(r.total_discount || 0);
        const ret    = parseFloat(r.good_returns_value || 0) + parseFloat(r.damage_expiry_shortage_value || 0);
        const final_ = parseFloat(r.final_bill_amount || 0);
        totalFinal += final_;
        return `<tr>
            <td style="color:var(--muted);font-size:12px;">${i+1}</td>
            <td><span class="badge badge-sky">${esc(r.bill_no)}</span></td>
            <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(r.party_name || r.customer_name || '—')}</td>
            <td style="font-size:12px;color:var(--muted);">${esc(r.route_name || '—')}</td>
            <td style="font-size:12px;color:var(--muted);">${r.bill_date ? fmtDate(r.bill_date) : '—'}</td>
            <td style="text-align:right;">${fmt(gross)}</td>
            <td style="text-align:right;color:var(--rose);">(${fmt(disc)})</td>
            <td style="text-align:right;color:var(--amber);">(${fmt(ret)})</td>
            <td style="text-align:right;font-weight:700;color:var(--sky-dark);">${fmt(final_)}</td>
            <td style="text-align:center;">
                <button class="inv-del-btn" onclick="removeInvoice(${r.id})" title="Remove">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </td>
        </tr>`;
    }).join('');

    document.getElementById('inv-total-cell').textContent = fmt(totalFinal);
}

// ═══════════════════════════════════════════════════════════
//  CREDIT NOTES — Custom Picker
// ═══════════════════════════════════════════════════════════
async function loadCreditNotes() {
    const listEl = document.getElementById('cn-picker-list');
    try {
        const res  = await fetch(location.pathname + '?ajax=get_credit_notes');
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch(e) {
            listEl.innerHTML = '<div class="cn-picker-empty">Failed to load credit notes.</div>';
            showToast('Failed to load credit notes.', 'error');
            return;
        }
        if (data && data.error) {
            listEl.innerHTML = '<div class="cn-picker-empty">DB error: ' + esc(data.error) + '</div>';
            showToast('DB error: ' + data.error, 'error');
            return;
        }
        if (!Array.isArray(data)) {
            listEl.innerHTML = '<div class="cn-picker-empty">Unexpected response.</div>';
            return;
        }
        allCNs = data;
        renderCNPickerList('');
    } catch(e) {
        listEl.innerHTML = '<div class="cn-picker-empty">Could not load credit notes.</div>';
        showToast('Could not load credit notes.', 'error');
    }
}

function renderCNPickerList(q) {
    const list = document.getElementById('cn-picker-list');
    if (!list) return;
    const term = q.toLowerCase().trim();

    const filtered = allCNs.filter(cn => {
        if (creditNotes.some(c => c.id == cn.id)) return false;
        if (!term) return true;
        return (cn.grn_no   || '').toLowerCase().includes(term)
            || (cn.branch_name || '').toLowerCase().includes(term);
    });

    if (!filtered.length) {
        list.innerHTML = allCNs.length === 0
            ? '<div class="cn-picker-empty"><i class="fa-solid fa-circle-info" style="margin-right:6px;"></i>No unused credit notes available.</div>'
            : '<div class="cn-picker-empty">No results match your search.</div>';
        return;
    }

    list.innerHTML = filtered.map(cn => `
        <div class="cn-picker-item${selectedCN && selectedCN.id == cn.id ? ' selected' : ''}"
             data-id="${cn.id}" onclick="selectCNItem(${cn.id})">
            <div class="cn-pi-top">
                <span class="cn-pi-grn">${esc(cn.grn_no)}</span>
                <span class="cn-pi-amt">LKR ${fmt(cn.amount)}</span>
            </div>
            <div class="cn-pi-branch">${esc(cn.branch_name || cn.customer_name || '—')}${cn.customer_id ? ' &nbsp;<span style="color:#9ca3af;font-size:11px;">#'+cn.customer_id+'</span>' : ''}</div>
        </div>`).join('');
}

function filterCNOptions() {
    selectedCN = null;
    document.getElementById('cn-add-btn').disabled = true;
    renderCNPickerList(document.getElementById('cn-picker-search').value);
}

function selectCNItem(id) {
    const cn = allCNs.find(c => c.id == id);
    if (!cn) return;
    selectedCN = cn;
    document.querySelectorAll('.cn-picker-item').forEach(el =>
        el.classList.toggle('selected', el.dataset.id == id));
    const lbl = document.getElementById('cn-picker-label');
    lbl.textContent = cn.grn_no + '  —  ' + (cn.branch_name || cn.customer_name || '—') + '  —  LKR ' + fmt(cn.amount);
    lbl.style.color = '#7c3aed';
    document.getElementById('cn-add-btn').disabled = false;
    closeCNPicker();
}

function toggleCNPicker() {
    const drop = document.getElementById('cn-picker-drop');
    const open = drop.classList.toggle('open');
    document.getElementById('cn-picker-arrow').style.transform = open ? 'rotate(180deg)' : '';
    if (open) {
        document.getElementById('cn-picker-search').value = '';
        renderCNPickerList('');
        setTimeout(() => document.getElementById('cn-picker-search').focus(), 50);
    }
}

function closeCNPicker() {
    document.getElementById('cn-picker-drop').classList.remove('open');
    document.getElementById('cn-picker-arrow').style.transform = '';
}

function addSelectedCN() {
    if (!selectedCN) { showToast('Select a credit note first.', 'error'); return; }
    if (creditNotes.some(c => c.id == selectedCN.id)) { showToast('Already added.', 'error'); return; }
    creditNotes.push(selectedCN);
    selectedCN = null;
    const lbl = document.getElementById('cn-picker-label');
    lbl.textContent = 'Search GRN No or outlet name…';
    lbl.style.color = '';
    document.getElementById('cn-add-btn').disabled = true;
    renderCNTable();
    updateSummary();
    renderCNPickerList('');
    showToast('Credit note added.', 'success');
}

document.addEventListener('click', function(e) {
    const wrap = document.getElementById('cn-picker-wrap');
    if (wrap && !wrap.contains(e.target)) closeCNPicker();
});

function removeCN(id) {
    creditNotes = creditNotes.filter(c => c.id != id);
    renderCNTable();
    updateSummary();
    renderCNPickerList(document.getElementById('cn-picker-search').value || '');
}

function renderCNTable() {
    const tbody = document.getElementById('cn-tbody');
    document.getElementById('cn-count').textContent = creditNotes.length;

    if (!creditNotes.length) {
        tbody.innerHTML = `<tr class="cn-empty-row"><td colspan="6">
            <i class="fa-solid fa-file-circle-xmark" style="display:block;font-size:22px;margin-bottom:6px;color:#d1d5db;"></i>
            No credit notes added yet</td></tr>`;
        document.getElementById('cn-total-cell').textContent = '0.00';
        return;
    }

    let totalCN = 0;
    tbody.innerHTML = creditNotes.map((cn, i) => {
        const amt = parseFloat(cn.amount || 0);
        totalCN += amt;
        return `<tr>
            <td style="color:var(--muted);font-size:12px;">${i+1}</td>
            <td><span class="badge badge-violet">${esc(cn.grn_no)}</span></td>
            <td style="font-weight:600;">${esc(cn.branch_name || cn.customer_name || '—')}</td>
            <td style="font-size:12px;color:var(--muted);">${cn.customer_id ? '#'+cn.customer_id : '—'}</td>
            <td style="text-align:right;font-weight:700;color:var(--violet);">${fmt(amt)}</td>
            <td style="text-align:center;">
                <button class="inv-del-btn" onclick="removeCN(${cn.id})" title="Remove">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </td>
        </tr>`;
    }).join('');

    document.getElementById('cn-total-cell').textContent = fmt(totalCN);
}

// ═══════════════════════════════════════════════════════════
//  SUMMARY
// ═══════════════════════════════════════════════════════════
function updateSummary() {
    const totalInv = invoices.reduce((s, r)    => s + parseFloat(r.final_bill_amount || 0), 0);
    const totalCN  = creditNotes.reduce((s, c) => s + parseFloat(c.amount || 0), 0);
    const net      = Math.max(0, totalInv - totalCN);

    document.getElementById('sum-inv-count').textContent  = `${invoices.length} invoice(s)`;
    document.getElementById('sum-inv-amount').textContent = `LKR ${fmt(totalInv)}`;
    document.getElementById('sum-cn-count').textContent   = `${creditNotes.length} note(s)`;
    document.getElementById('sum-cn-amount').textContent  = `– LKR ${fmt(totalCN)}`;
    document.getElementById('sum-net').textContent        = `LKR ${fmt(net)}`;
    document.getElementById('save-btn').disabled          = invoices.length === 0;
}

// ═══════════════════════════════════════════════════════════
//  SAVE PAYMENT
// ═══════════════════════════════════════════════════════════
async function savePayment() {
    if (!invoices.length) { showToast('Add at least one invoice.', 'error'); return; }

    const btn = document.getElementById('save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('ajax_action',  'save_payment');
    fd.append('invoices',     JSON.stringify(invoices));
    fd.append('credit_notes', JSON.stringify(creditNotes));
    fd.append('payment_date', document.getElementById('pay-date').value);
    fd.append('reference',    document.getElementById('pay-ref').value);
    fd.append('remarks',      document.getElementById('pay-remarks').value);

    try {
        const res  = await fetch(location.pathname, {method:'POST', body:fd});
        const data = await res.json();
        if (data.ok) {
            document.getElementById('success-net-val').textContent = `LKR ${fmt(data.net_payable)}`;
            document.getElementById('success-overlay').classList.add('show');
        } else {
            showToast('Error: ' + (data.msg || 'Unknown error'), 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Payment Entry';
        }
    } catch(e) {
        showToast('Network error. Try again.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Payment Entry';
    }
}

function resetAll() {
    invoices    = [];
    creditNotes = [];
    selectedCN  = null;
    renderInvTable();
    renderCNTable();
    updateSummary();
    document.getElementById('pay-ref').value     = '';
    document.getElementById('pay-remarks').value = '';
    document.getElementById('pay-date').value    = new Date().toISOString().slice(0,10);
    document.getElementById('success-overlay').classList.remove('show');
    document.getElementById('save-btn').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Payment Entry';
    const lbl = document.getElementById('cn-picker-label');
    lbl.textContent = 'Search GRN No or outlet name…';
    lbl.style.color = '';
    document.getElementById('cn-add-btn').disabled = true;
    renderCNPickerList('');
}

// ═══════════════════════════════════════════════════════════
//  UTILS
// ═══════════════════════════════════════════════════════════
function fmt(n) {
    return parseFloat(n || 0).toLocaleString('en-LK', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function fmtDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'});
}
function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function showToast(msg, type='success') {
    const wrap = document.getElementById('toast-wrap');
    const t    = document.createElement('div');
    t.className = `toast toast-${type}`;
    t.innerHTML = `<i class="fa-solid fa-${type==='success'?'circle-check':'circle-exclamation'}"></i> ${msg}`;
    wrap.appendChild(t);
    setTimeout(() => t.remove(), 3800);
}

// ── Init ──────────────────────────────────────────────────
loadCreditNotes();
updateSummary();
</script>

<?php include 'footer.php'; ?>