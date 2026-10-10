<?php
include 'config.php';

/* ═══════════════════════════════════════════════════
   AJAX: CHECK STOCK — available qty = IN − OUT
   Called from JS before preview. Receives JSON:
   { action:"check_stock", codes:[ "471...", ... ] }
   Returns per product_code:
   { found, name, ucode, available }
═══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'check_stock') {
    header('Content-Type: application/json');
    $data  = json_decode(file_get_contents('php://input'), true);
    $codes = array_filter(array_map('strval', $data['codes'] ?? []));
    $out   = [];

    if ($codes) {
        $esc = array_map(fn($c) => "'" . mysqli_real_escape_string($conn, $c) . "'", $codes);
        $in  = implode(',', $esc);

        /* 1. Item master info */
        $r = mysqli_query($conn, "SELECT product_code, product_name, unilever_code
                                  FROM ushop_items WHERE product_code IN ($in)");
        while ($row = mysqli_fetch_assoc($r)) {
            $out[$row['product_code']] = [
                'found'     => true,
                'name'      => $row['product_name'],
                'ucode'     => $row['unilever_code'],
                'available' => 0
            ];
        }

        /* 2. Available qty from stock history: IN (OPENING / STOCK IN / etc.)
              minus OUT (any txn_type containing OUT) */
        $r2 = mysqli_query($conn, "
            SELECT product_code,
                   SUM(CASE WHEN txn_type LIKE '%OUT%' THEN -qty ELSE qty END) AS avail
            FROM ushop_stock_history
            WHERE product_code IN ($in)
            GROUP BY product_code
        ");
        if ($r2) {
            while ($row = mysqli_fetch_assoc($r2)) {
                if (isset($out[$row['product_code']])) {
                    $out[$row['product_code']]['available'] = (float)$row['avail'];
                }
            }
        }
    }

    echo json_encode(['success' => true, 'stock' => $out]);
    exit;
}

/* ═══════════════════════════════════════════════════
   AUTO-CREATE TABLES
═══════════════════════════════════════════════════ */
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS ushop_invoice_imports (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  import_date     DATE NOT NULL,
  filename        VARCHAR(255) NOT NULL,
  note            TEXT,
  date_from       DATE,
  date_to         DATE,
  total_invoices  INT DEFAULT 0,
  total_items     INT DEFAULT 0,
  total_amount    DECIMAL(15,2) DEFAULT 0,
  stock_updated   TINYINT(1) DEFAULT 0,
  imported_at     DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* Add stock_updated column if table already existed without it */
$colChk = mysqli_query($conn, "SHOW COLUMNS FROM ushop_invoice_imports LIKE 'stock_updated'");
if ($colChk && mysqli_num_rows($colChk) == 0) {
    mysqli_query($conn, "ALTER TABLE ushop_invoice_imports ADD COLUMN stock_updated TINYINT(1) DEFAULT 0 AFTER total_amount");
}

mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS ushop_invoices (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  import_id       INT NOT NULL,
  doc_no          VARCHAR(50),
  unique_inv_no   VARCHAR(50),
  invoice_date    DATE,
  customer_name   VARCHAR(255),
  customer_code   VARCHAR(50),
  cashier         VARCHAR(100),
  total_qty       DECIMAL(12,3) DEFAULT 0,
  total_discount  DECIMAL(12,2) DEFAULT 0,
  total_amount    DECIMAL(12,2) DEFAULT 0,
  FOREIGN KEY (import_id) REFERENCES ushop_invoice_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS ushop_invoice_items (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id      INT NOT NULL,
  import_id       INT NOT NULL,
  product_code    VARCHAR(50),
  barcode         VARCHAR(50),
  ref_code        VARCHAR(50),
  product_name    VARCHAR(255),
  unilever_code   VARCHAR(50),
  price           DECIMAL(12,2) DEFAULT 0,
  qty             DECIMAL(12,3) DEFAULT 0,
  discount        DECIMAL(12,2) DEFAULT 0,
  amount          DECIMAL(12,2) DEFAULT 0,
  FOREIGN KEY (invoice_id) REFERENCES ushop_invoices(id) ON DELETE CASCADE,
  FOREIGN KEY (import_id)  REFERENCES ushop_invoice_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS ushop_invoice_payments (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id      INT NOT NULL,
  import_id       INT NOT NULL,
  pay_type        VARCHAR(100),
  amount          DECIMAL(12,2) DEFAULT 0,
  FOREIGN KEY (invoice_id) REFERENCES ushop_invoices(id) ON DELETE CASCADE,
  FOREIGN KEY (import_id)  REFERENCES ushop_invoice_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.page-wrap{max-width:1300px;margin:0 auto;padding:0 8px;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:14px;flex-wrap:wrap;}
.breadcrumb a{color:#0e7490;text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-teal{background:#0e7490;color:#fff;}.btn-teal:hover{background:#155e75;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-green{background:#15803d;color:#fff;}.btn-green:hover{background:#166534;}
.btn-sm{padding:5px 11px;font-size:12px;}

.upload-card{background:#fff;border:2px dashed #a5f3fc;border-radius:14px;padding:36px;text-align:center;cursor:pointer;transition:.2s;margin-bottom:22px;}
.upload-card:hover,.upload-card.drag{border-color:#0e7490;background:#f0fdff;}
.upload-card .icon{font-size:44px;color:#0e7490;margin-bottom:10px;}
.upload-card h3{margin:0 0 6px;font-size:16px;font-weight:700;color:#111827;}
.upload-card p{margin:0;font-size:12px;color:#6b7280;}
#fileInput{display:none;}

.form-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px 24px;margin-bottom:22px;}
.form-row{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;}
.form-group{display:flex;flex-direction:column;gap:5px;flex:1;min-width:200px;}
.form-group label{font-size:11.5px;font-weight:700;color:#374151;text-transform:uppercase;}
.form-group input,.form-group textarea,.form-group select{padding:9px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:13px;font-family:inherit;color:#111827;outline:none;transition:.15s;}
.form-group input:focus,.form-group textarea:focus{border-color:#0e7490;box-shadow:0 0 0 3px rgba(14,116,144,.1);}

/* Stock OUT checkbox option */
.stock-opt{display:flex;align-items:flex-start;gap:10px;background:#fff7ed;border:1.5px solid #fdba74;border-radius:9px;padding:12px 14px;margin-bottom:14px;cursor:pointer;transition:.15s;user-select:none;}
.stock-opt:hover{background:#ffedd5;}
.stock-opt.off{background:#f9fafb;border-color:#e5e7eb;}
.stock-opt input[type=checkbox]{width:17px;height:17px;margin-top:1px;accent-color:#ea580c;cursor:pointer;flex-shrink:0;}
.stock-opt .so-title{font-size:13px;font-weight:700;color:#9a3412;display:flex;align-items:center;gap:6px;}
.stock-opt.off .so-title{color:#6b7280;}
.stock-opt .so-desc{font-size:11.5px;color:#78716c;margin-top:2px;line-height:1.45;}
.stock-opt.off .so-desc{color:#9ca3af;}

/* Preview */
#previewSection{display:none;}
.preview-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px;}
.stat-pills{display:flex;flex-wrap:wrap;gap:8px;}
.stat-pill{background:#f0fdff;border:1px solid #a5f3fc;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;color:#0e7490;}
.stat-pill span{color:#374151;font-weight:400;}
.stat-pill.pill-orange{background:#fff7ed;border-color:#fdba74;color:#9a3412;}
.stat-pill.pill-red{background:#fef2f2;border-color:#fecaca;color:#dc2626;}
.stat-pill.pill-green{background:#f0fdf4;border-color:#bbf7d0;color:#15803d;}
.stat-pill.pill-gray{background:#f3f4f6;border-color:#e5e7eb;color:#6b7280;}

/* Invoice list — compact, no scroll gap issues */
.inv-list{display:flex;flex-direction:column;gap:6px;margin-bottom:16px;}
.inv-item{border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.inv-head{display:flex;align-items:center;gap:8px;padding:10px 14px;cursor:pointer;background:#f9fafb;user-select:none;flex-wrap:wrap;transition:background .15s;}
.inv-head:hover{background:#ecfeff;}
.inv-head.has-problem{background:#fff7ed;}
.inv-head.has-problem:hover{background:#ffedd5;}
.inv-badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;flex-shrink:0;}
.inv-badge-blue{background:#dbeafe;color:#1e40af;}
.inv-badge-teal{background:#cffafe;color:#0e7490;}
.inv-badge-green{background:#dcfce7;color:#15803d;}
.inv-badge-gray{background:#f3f4f6;color:#6b7280;}
.inv-badge-purple{background:#ede9fe;color:#5b21b6;}
.inv-badge-red{background:#fee2e2;color:#dc2626;}
.inv-badge-orange{background:#ffedd5;color:#c2410c;}
.inv-body{display:none;border-top:1px solid #e5e7eb;}
.inv-body.open{display:block;}
.inv-sub{padding:10px 14px;}
.sub-table{width:100%;border-collapse:collapse;font-size:11.5px;margin-bottom:10px;}
.sub-table th{background:#f0fdff;padding:6px 10px;text-align:left;font-size:10.5px;text-transform:uppercase;color:#0e7490;border-bottom:1px solid #a5f3fc;white-space:nowrap;}
.sub-table td{padding:5px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.sub-table tr:last-child td{border:none;}
.tr{text-align:right!important;}
.ucode-tag{display:inline-block;background:#ede9fe;color:#5b21b6;border-radius:4px;padding:1px 6px;font-size:10px;font-weight:700;font-family:monospace;}
.pay-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;}
.pay-chip{background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:4px 10px;font-size:11.5px;font-weight:700;color:#15803d;}

/* Stock status badges in item rows */
.st-badge{display:inline-block;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:700;white-space:nowrap;}
.st-ok{background:#dcfce7;color:#15803d;}
.st-short{background:#ffedd5;color:#c2410c;}
.st-notfound{background:#fee2e2;color:#dc2626;}
.avail-num{font-weight:700;}
.avail-ok{color:#15803d;}
.avail-short{color:#c2410c;}
.avail-nf{color:#dc2626;}

/* Collapsed list: show all invoices in compact table-like rows */
.inv-head .doc-col{font-family:monospace;font-size:11px;color:#0e7490;font-weight:700;min-width:84px;}
.inv-head .date-col{font-size:11px;color:#6b7280;min-width:78px;}
.inv-head .cust-col{flex:1;font-size:12px;font-weight:600;color:#111827;min-width:100px;}
.inv-head .items-col{font-size:11px;color:#6b7280;min-width:56px;text-align:right;}
.inv-head .amt-col{font-size:12px;font-weight:700;color:#15803d;min-width:90px;text-align:right;}
.inv-head .chev-col{color:#9ca3af;font-size:10px;margin-left:4px;}

/* Progress */
#progressSection{display:none;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:22px;text-align:center;}
.progress-bar-wrap{background:#f3f4f6;border-radius:20px;height:12px;margin:16px 0 8px;overflow:hidden;}
.progress-bar-fill{height:100%;background:linear-gradient(90deg,#0e7490,#06b6d4);border-radius:20px;transition:width .3s;}
#progressMsg{font-size:13px;color:#6b7280;}

/* Alert */
.alert{padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:16px;}
.alert-ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.alert-err{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;}

/* Parse debug badge */
.parse-warning{background:#fef9c3;color:#854d0e;border:1px solid #fde047;border-radius:8px;padding:8px 14px;font-size:12px;margin-bottom:12px;display:none;}

/* Stock problem summary box */
.stock-problem-box{background:#fff7ed;border:1px solid #fdba74;border-radius:10px;padding:12px 16px;font-size:12px;color:#9a3412;margin-bottom:14px;display:none;}
.stock-problem-box ul{margin:6px 0 0;padding-left:18px;}
.stock-problem-box li{margin-bottom:2px;font-size:11.5px;}
</style>

<div class="page-wrap">
<div class="breadcrumb">
  <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
  <span class="sep">›</span>
  <a href="ushop_items_import.php"><i class="fa-solid fa-boxes-stacked"></i> UShop Items Import</a>
  <span class="sep">›</span>
  <span style="color:#0e7490;font-weight:700;"><i class="fa-solid fa-file-invoice"></i> Invoice Import</span>
</div>

<div id="mainMsg"></div>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
  <div>
    <h2 style="margin:0;font-size:19px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-file-invoice" style="color:#0e7490;margin-right:8px;"></i>UShop — Invoice Import (POS Bill Wise)
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Import POS Bill Wise Sales Report Excel. Available stock (IN − OUT) checked per item before import.</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="ushop_invoice_history.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
    <a href="ushop_items_view.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-cubes"></i> Items</a>
    <a href="ushop_stock_history.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-layer-group"></i> Stock History</a>
  </div>
</div>

<!-- Upload Area -->
<div class="upload-card" id="uploadZone" onclick="document.getElementById('fileInput').click()">
  <div class="icon"><i class="fa-solid fa-file-excel"></i></div>
  <h3>Drop or Click to Select Excel File</h3>
  <p>POS Bill Wise Sales Report (.xlsx) — Sales Details + Items + Payments</p>
  <input type="file" id="fileInput" accept=".xlsx,.xls">
</div>

<!-- Note & Options -->
<div class="form-card" id="optionsCard" style="display:none;">
  <div class="form-row">
    <div class="form-group">
      <label><i class="fa-solid fa-note-sticky"></i> Import Note (Optional)</label>
      <input type="text" id="importNote" placeholder="e.g. June 2026 POS Sales">
    </div>
    <div class="form-group" style="max-width:200px;min-width:160px;">
      <label><i class="fa-solid fa-calendar"></i> Import Date</label>
      <input type="date" id="importDate" value="<?= date('Y-m-d') ?>">
    </div>
  </div>

  <!-- ── STOCK OUT OPTION ── -->
  <label class="stock-opt" id="stockOptBox">
    <input type="checkbox" id="stockOutCheck" checked onchange="toggleStockOpt()">
    <div>
      <div class="so-title"><i class="fa-solid fa-arrow-trend-down"></i> Import STOCK OUT from Available Stock</div>
      <div class="so-desc">
        When ON: each invoice item's quantity will be <strong>deducted from available stock</strong> and a
        <strong>STOCK OUT</strong> entry will be saved in the stock log (per item, linked to the invoice).
        When OFF: invoices are saved only — stock and stock log are not touched.
      </div>
    </div>
  </label>

  <button class="btn btn-teal" id="previewBtn" onclick="runPreview()">
    <i class="fa-solid fa-eye"></i> Parse & Preview
  </button>
  <span id="stockCheckMsg" style="font-size:12px;color:#6b7280;margin-left:10px;"></span>
</div>

<!-- Preview Section -->
<div id="previewSection">
  <div class="preview-header">
    <h3 style="margin:0;font-size:15px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-eye" style="color:#0e7490;margin-right:6px;"></i>Import Preview
    </h3>
    <div class="stat-pills" id="statPills"></div>
  </div>
  <div id="parseWarning" class="parse-warning"></div>
  <div id="stockProblemBox" class="stock-problem-box"></div>

  <!-- Invoice list header -->
  <div style="display:flex;align-items:center;gap:8px;padding:6px 14px;background:#f0fdff;border:1px solid #a5f3fc;border-radius:8px 8px 0 0;font-size:10.5px;font-weight:700;text-transform:uppercase;color:#0e7490;margin-bottom:0;">
    <span style="min-width:84px;">Doc No</span>
    <span style="min-width:78px;">Date</span>
    <span style="flex:1;">Customer</span>
    <span style="min-width:56px;text-align:right;">Items</span>
    <span style="min-width:90px;text-align:right;">Amount</span>
    <span style="width:18px;"></span>
  </div>
  <div id="invList" class="inv-list" style="border-radius:0 0 10px 10px;border:1px solid #e5e7eb;border-top:none;padding:6px;gap:3px;background:#fafafa;"></div>

  <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap;">
    <button class="btn btn-green" id="importBtn" onclick="doImport()"><i class="fa-solid fa-upload"></i> Confirm & Import</button>
    <button class="btn btn-secondary" onclick="resetAll()"><i class="fa-solid fa-xmark"></i> Cancel</button>
  </div>
</div>

<!-- Progress -->
<div id="progressSection">
  <i class="fa-solid fa-circle-notch fa-spin" style="color:#0e7490;font-size:24px;"></i>
  <div style="font-size:14px;font-weight:700;color:#111827;margin-top:10px;">Importing Data…</div>
  <div class="progress-bar-wrap"><div class="progress-bar-fill" id="progressBar" style="width:0%"></div></div>
  <div id="progressMsg">Preparing…</div>
</div>

</div><!-- .page-wrap -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
let parsedData = null;
let stockMap   = null;   /* { product_code: {found, name, ucode, available} } */

/* ── Stock OUT option toggle (visual state) ── */
function toggleStockOpt() {
  const chk = document.getElementById('stockOutCheck');
  document.getElementById('stockOptBox').classList.toggle('off', !chk.checked);
}

/* ── Drag & Drop ── */
const zone = document.getElementById('uploadZone');
zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag'); });
zone.addEventListener('dragleave', () => zone.classList.remove('drag'));
zone.addEventListener('drop', e => { e.preventDefault(); zone.classList.remove('drag'); handleFile(e.dataTransfer.files[0]); });
document.getElementById('fileInput').addEventListener('change', e => handleFile(e.target.files[0]));

function handleFile(file) {
  if (!file) return;
  zone.innerHTML = `<div class="icon"><i class="fa-solid fa-file-excel" style="color:#22c55e;"></i></div>
    <h3>${file.name}</h3>
    <p>File selected — fill options below and click Parse & Preview</p>
    <input type="file" id="fileInput" accept=".xlsx,.xls">`;
  document.getElementById('fileInput').addEventListener('change', e => handleFile(e.target.files[0]));
  document.getElementById('optionsCard').style.display = 'block';
  document.getElementById('previewSection').style.display = 'none';
  parsedData = null;
  stockMap   = null;
  readExcel(file);
}

function readExcel(file) {
  const reader = new FileReader();
  reader.onload = e => {
    try {
      /* cellDates:false so we get raw serial numbers — we handle date conversion ourselves */
      const wb = XLSX.read(e.target.result, { type: 'array', cellDates: false });
      const ws = wb.Sheets[wb.SheetNames[0]];
      const raw = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '', raw: true });
      parsedData = parseInvoices(raw, file.name);
    } catch (err) {
      showMsg('err', '<i class="fa-solid fa-triangle-exclamation"></i> Failed to read file: ' + err.message);
    }
  };
  reader.readAsArrayBuffer(file);
}

/* ─────────────────────────────────────────────────
   Excel serial → YYYY-MM-DD
   Excel epoch is 1899-12-30 (accounting for the
   Lotus 1-2-3 leap-year bug).
───────────────────────────────────────────────── */
function excelSerialToDate(serial) {
  if (!serial && serial !== 0) return '';
  const n = Number(serial);
  if (isNaN(n) || n < 1) return '';
  /* Excel day 1 = 1900-01-01 but Excel thinks 1900 was a leap year,
     so serial 60 = 1900-02-29 (doesn't exist). Offset: use 25569 for
     JS Date (Unix epoch = Excel serial 25569 = 1970-01-01). */
  const ms = (n - 25569) * 86400000;
  const d = new Date(ms);
  if (isNaN(d)) return '';
  const y = d.getUTCFullYear();
  const mo = String(d.getUTCMonth() + 1).padStart(2, '0');
  const dy = String(d.getUTCDate()).padStart(2, '0');
  return `${y}-${mo}-${dy}`;
}

/* ─────────────────────────────────────────────────
   Main parser — matches real Unilever POS Bill
   Wise Sales Report format.

   Column layout per item row (0-indexed):
     0  product_code   (13-digit barcode style)
     1  barcode        (sometimes same as col 0, sometimes blank)
     2  ref_code       (sometimes numeric code, sometimes same as col 0)
     3  product_name   (may have "-ULVR_CODE" suffix e.g. "PB CLG BT 100ML-3865701")
     4  inv_no
     5  date           (Excel serial integer like 46183)
     6  cashier
     7  price
     8  qty
     9  discount
    10  amount

   Unilever codes: 5-10 digit numeric suffix separated by '-' at the END
   of the product description (NOT the beginning)
   e.g. "PB CLG BT 100ML-3865701"  → cleanName="PB CLG BT 100ML", ulvrCode="3865701"
   e.g. "CMP 800G-26031732"        → cleanName="CMP 800G",        ulvrCode="26031732"
   Some rows have no suffix.
───────────────────────────────────────────────── */
function parseInvoices(rows, filename) {
  const invoices = [];
  let cur = null;
  let inPaid = false;
  let dateFrom = null, dateTo = null;
  let warnings = [];

  const str = v => (v === null || v === undefined) ? '' : String(v).trim();

  /* Is this string a product code? (10+ digit numeric) */
  const isProductCode = s => /^\d{10,}$/.test(s.trim());

  for (let i = 0; i < rows.length; i++) {
    const row = rows[i];
    const c0 = str(row[0]);

    /* ── New invoice header ── */
    if (c0 === 'Document No') {
      if (cur) invoices.push(finalize(cur));
      inPaid = false;

      let cname = str(row[3]);
      let ccode = '';
      /* Customer code: trailing "-digits" pattern at the END of the name
         e.g. "ABC TRADERS-23" -> name="ABC TRADERS", code="23"
         (allows optional spaces around the dash, e.g. "ABC TRADERS - 23") */
      const cm = cname.match(/^(.+?)\s*-\s*(\d+)\s*$/);
      if (cm) { cname = cm[1].trim(); ccode = cm[2]; }

      cur = {
        doc_no:         str(row[1]),
        unique_inv_no:  str(row[5]),
        customer_name:  cname,
        customer_code:  ccode,
        invoice_date:   '',
        cashier:        '',
        items:          [],
        payments:       [],
        total_qty:      0,
        total_discount: 0,
        total_amount:   0
      };
      continue;
    }

    /* ── Payment section marker ── */
    if (c0 === 'Paid Type') { inPaid = true; continue; }

    /* ── Skip header/metadata rows before first invoice ── */
    if (!cur) continue;

    /* ── Payment rows (after "Paid Type" until next "Document No") ── */
    if (inPaid) {
      /* Valid payment: non-empty non-numeric label + numeric amount */
      if (c0 && isNaN(Number(c0)) && row[1] !== '' && !isNaN(parseFloat(row[1]))) {
        cur.payments.push({ pay_type: c0, amount: parseFloat(row[1]) });
      }
      /* All other rows in paid section (subtotals, invoice total row) — skip */
      continue;
    }

    /* ── Item row ── */
    if (isProductCode(c0)) {
      const rawName = str(row[3]);

      /* Extract unilever code: trailing digits at the END of the item
         description, separated by '-'. Codes are typically 5-10 digits.
         e.g. "PB CLG BT 100ML-3865701" -> name="PB CLG BT 100ML", code="3865701"
         e.g. "CMP 800G-26031732"       -> name="CMP 800G",       code="26031732"
         (allows optional spaces around the dash) */
      let ulvrCode = '';
      let cleanName = rawName;
      const um = rawName.match(/^(.+?)\s*-\s*(\d{5,10})\s*$/);
      if (um) {
        cleanName = um[1].trim();
        ulvrCode  = um[2];
      }

      /* Date from col 5 — Excel serial integer */
      const dateSerial = row[5];
      let idate = '';
      if (dateSerial instanceof Date) {
        /* In case xlsx lib did convert it */
        idate = dateSerial.toISOString().slice(0, 10);
      } else if (typeof dateSerial === 'number' && dateSerial > 40000) {
        idate = excelSerialToDate(dateSerial);
      } else if (str(dateSerial).match(/^\d{4}-\d{2}-\d{2}/)) {
        idate = str(dateSerial).slice(0, 10);
      }

      if (!cur.invoice_date && idate) cur.invoice_date = idate;
      if (!cur.cashier && row[6])     cur.cashier = str(row[6]);

      /* Track date range across all invoices */
      if (idate) {
        if (!dateFrom || idate < dateFrom) dateFrom = idate;
        if (!dateTo   || idate > dateTo)   dateTo   = idate;
      }

      const qty  = parseFloat(row[8])  || 0;
      const disc = parseFloat(row[9])  || 0;
      const amt  = parseFloat(row[10]) || 0;
      const price= parseFloat(row[7])  || 0;

      cur.items.push({
        product_code:  c0,
        barcode:       str(row[1]),
        ref_code:      str(row[2]),
        product_name:  cleanName,
        unilever_code: ulvrCode,
        price, qty, discount: disc, amount: amt
      });
    }
  }

  if (cur) invoices.push(finalize(cur));

  /* Sanity check */
  const emptyDates = invoices.filter(inv => !inv.invoice_date).length;
  if (emptyDates > 0)
    warnings.push(`${emptyDates} invoice(s) have no date — check Excel date column format.`);
  const emptyItems = invoices.filter(inv => inv.items.length === 0).length;
  if (emptyItems > 0)
    warnings.push(`${emptyItems} invoice(s) have no items — they will still be imported.`);

  return { invoices, filename, dateFrom, dateTo, warnings };
}

function finalize(inv) {
  inv.total_qty      = inv.items.reduce((s, i) => s + i.qty,      0);
  inv.total_discount = inv.items.reduce((s, i) => s + i.discount, 0);
  inv.total_amount   = inv.items.reduce((s, i) => s + i.amount,   0);
  return inv;
}

/* ─────────────────────────────────────────────────
   STOCK CHECK — send unique product codes to server,
   get back available qty (IN − OUT) per product.
───────────────────────────────────────────────── */
function checkStock(codes) {
  return fetch('ushop_invoice_import.php?action=check_stock', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ action: 'check_stock', codes })
  })
  .then(r => r.json())
  .then(res => res.success ? (res.stock || {}) : {});
}

/* Compute total needed qty per product across ALL invoices in file */
function neededPerProduct(invoices) {
  const need = {};
  invoices.forEach(inv => inv.items.forEach(it => {
    need[it.product_code] = (need[it.product_code] || 0) + it.qty;
  }));
  return need;
}

/* Per-product status vs stockMap:
   'notfound' — product code not in ushop_items
   'short'    — available < total needed in this file
   'ok'       — enough stock                                          */
function productStatus(code, need) {
  const s = stockMap ? stockMap[code] : null;
  if (!s || !s.found) return 'notfound';
  if (s.available < need[code]) return 'short';
  return 'ok';
}

/* ─────────────────────────────────────────────────
   Preview renderer (async — checks stock first)
───────────────────────────────────────────────── */
async function runPreview() {
  if (!parsedData) { alert('Please select a file first.'); return; }
  const { invoices, dateFrom, dateTo, warnings } = parsedData;
  const stockOut = document.getElementById('stockOutCheck').checked;
  const btn = document.getElementById('previewBtn');
  const chkMsg = document.getElementById('stockCheckMsg');

  /* ── 1. Check available stock for all product codes ── */
  const codes = [...new Set(invoices.flatMap(inv => inv.items.map(it => it.product_code)))];
  btn.disabled = true;
  chkMsg.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Checking available stock for ' + codes.length + ' products…';
  try {
    stockMap = await checkStock(codes);
  } catch (e) {
    stockMap = {};
    chkMsg.innerHTML = '<span style="color:#dc2626;">Stock check failed — showing preview without availability.</span>';
  }
  btn.disabled = false;
  chkMsg.innerHTML = '';

  const need = neededPerProduct(invoices);

  /* ── 2. Aggregate stats ── */
  const totalInv   = invoices.length;
  const totalItems = invoices.reduce((s, inv) => s + inv.items.length, 0);
  const totalAmt   = invoices.reduce((s, inv) => s + inv.total_amount, 0);
  const totalQty   = invoices.reduce((s, inv) => s + inv.total_qty, 0);

  /* Stock problems */
  const notFound = codes.filter(c => productStatus(c, need) === 'notfound');
  const shortage = codes.filter(c => productStatus(c, need) === 'short');
  const okCount  = codes.length - notFound.length - shortage.length;

  const stockPill = stockOut
    ? `<div class="stat-pill pill-orange"><i class="fa-solid fa-arrow-trend-down"></i> Stock OUT: <span>ON — ${totalQty.toLocaleString()} qty will be deducted</span></div>`
    : `<div class="stat-pill pill-gray"><i class="fa-solid fa-ban"></i> Stock OUT: <span>OFF — stock not affected</span></div>`;

  document.getElementById('statPills').innerHTML = `
    <div class="stat-pill">Invoices: <span>${totalInv}</span></div>
    <div class="stat-pill">Items: <span>${totalItems}</span></div>
    <div class="stat-pill">Total Qty: <span>${totalQty.toLocaleString()}</span></div>
    <div class="stat-pill">Total Amount: <span>Rs.${totalAmt.toLocaleString('en', {minimumFractionDigits:2})}</span></div>
    <div class="stat-pill">Date Range: <span>${dateFrom || '?'} → ${dateTo || '?'}</span></div>
    <div class="stat-pill pill-green"><i class="fa-solid fa-circle-check"></i> Stock OK: <span>${okCount}</span></div>
    ${shortage.length ? `<div class="stat-pill pill-orange"><i class="fa-solid fa-triangle-exclamation"></i> Insufficient: <span>${shortage.length}</span></div>` : ''}
    ${notFound.length ? `<div class="stat-pill pill-red"><i class="fa-solid fa-circle-xmark"></i> Not in Items: <span>${notFound.length}</span></div>` : ''}
    ${stockPill}`;

  /* ── 3. Stock problem summary box ── */
  const probBox = document.getElementById('stockProblemBox');
  if (notFound.length || shortage.length) {
    let html = '<i class="fa-solid fa-triangle-exclamation"></i> <strong>Stock check found problems:</strong><ul>';
    shortage.slice(0, 10).forEach(c => {
      const s = stockMap[c];
      html += `<li><strong>${s.name || c}</strong> (${c}) — needed <strong>${need[c].toLocaleString()}</strong>, available only <strong>${s.available.toLocaleString()}</strong> (will go negative by ${(need[c]-s.available).toLocaleString()})</li>`;
    });
    if (shortage.length > 10) html += `<li>…and ${shortage.length - 10} more products with insufficient stock</li>`;
    notFound.slice(0, 10).forEach(c => {
      html += `<li><strong>${c}</strong> — product code not found in Items master (import items first, or it will be logged without item link)</li>`;
    });
    if (notFound.length > 10) html += `<li>…and ${notFound.length - 10} more product codes not found</li>`;
    html += '</ul>';
    probBox.innerHTML = html;
    probBox.style.display = 'block';
  } else {
    probBox.style.display = 'none';
  }

  /* ── 4. Warnings ── */
  const warnEl = document.getElementById('parseWarning');
  if (warnings.length) {
    warnEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + warnings.join(' &nbsp;|&nbsp; ');
    warnEl.style.display = 'block';
  } else {
    warnEl.style.display = 'none';
  }

  /* ── 5. Build accordion rows ── */
  const list = document.getElementById('invList');
  list.innerHTML = '';

  invoices.forEach((inv, idx) => {
    const custLabel = inv.customer_name || '<span style="color:#9ca3af;font-style:italic;">Walk-in</span>';

    /* Does this invoice contain any problem products? */
    const invProblems = inv.items.filter(it => productStatus(it.product_code, need) !== 'ok').length;

    const payHtml = inv.payments.length
      ? inv.payments.map(p =>
          `<span class="pay-chip"><i class="fa-solid fa-money-bill-wave"></i> ${p.pay_type}: Rs.${p.amount.toLocaleString('en', {minimumFractionDigits:2})}</span>`
        ).join('')
      : '<span style="color:#9ca3af;font-size:11px;font-style:italic;">No payment records</span>';

    const itemRows = inv.items.map(it => {
      const refCell = it.ref_code && it.ref_code !== it.product_code
        ? `<span style="font-family:monospace;font-size:10px;color:#9ca3af;">${it.ref_code}</span>` : '';

      /* Availability cells */
      const st = productStatus(it.product_code, need);
      const s  = stockMap ? stockMap[it.product_code] : null;
      let availCell, statusCell;
      if (st === 'notfound') {
        availCell  = `<span class="avail-num avail-nf">—</span>`;
        statusCell = `<span class="st-badge st-notfound"><i class="fa-solid fa-circle-xmark"></i> NOT FOUND</span>`;
      } else if (st === 'short') {
        availCell  = `<span class="avail-num avail-short">${s.available.toLocaleString()}</span>`;
        statusCell = `<span class="st-badge st-short"><i class="fa-solid fa-triangle-exclamation"></i> SHORT (need ${need[it.product_code].toLocaleString()})</span>`;
      } else {
        availCell  = `<span class="avail-num avail-ok">${s.available.toLocaleString()}</span>`;
        statusCell = `<span class="st-badge st-ok"><i class="fa-solid fa-check"></i> OK</span>`;
      }

      return `<tr>
        <td style="font-family:monospace;font-size:10.5px;">${it.product_code}</td>
        <td>
          ${it.product_name}
          ${it.unilever_code ? ` <span class="ucode-tag">${it.unilever_code}</span>` : ''}
          ${refCell}
        </td>
        <td class="tr">${it.qty}</td>
        <td class="tr">${availCell}</td>
        <td class="tr">${statusCell}</td>
        <td class="tr">${it.price.toFixed(2)}</td>
        <td class="tr">${it.discount.toFixed(2)}</td>
        <td class="tr" style="font-weight:700;">${it.amount.toFixed(2)}</td>
      </tr>`;
    }).join('');

    const el = document.createElement('div');
    el.className = 'inv-item';
    el.innerHTML = `
      <div class="inv-head ${invProblems ? 'has-problem' : ''}" onclick="toggleInv(${idx})">
        <span class="doc-col">${inv.doc_no}</span>
        <span class="date-col">${inv.invoice_date || '<span style="color:#ef4444;">no date</span>'}</span>
        <span class="cust-col">${custLabel}${inv.customer_code ? ` <span class="inv-badge inv-badge-blue">#${inv.customer_code}</span>` : ''}</span>
        <span class="items-col">
          <span class="inv-badge inv-badge-gray">${inv.items.length} items</span>
          ${invProblems ? `<span class="inv-badge inv-badge-orange" title="Items with stock problems">⚠ ${invProblems}</span>` : ''}
        </span>
        <span class="amt-col">Rs.${inv.total_amount.toLocaleString('en', {minimumFractionDigits:2})}</span>
        <i class="fa-solid fa-chevron-down chev-col" id="chev-${idx}" style="transition:transform .2s;"></i>
      </div>
      <div class="inv-body" id="body-${idx}">
        <div class="inv-sub">
          <table class="sub-table">
            <thead><tr>
              <th>Product Code</th><th>Product Name</th>
              <th class="tr">Qty</th>
              <th class="tr">Available</th>
              <th class="tr">Stock Status</th>
              <th class="tr">Price</th>
              <th class="tr">Discount</th><th class="tr">Amount</th>
            </tr></thead>
            <tbody>${itemRows}</tbody>
            <tfoot>
              <tr style="background:#f9fafb;font-weight:700;">
                <td colspan="2" style="font-size:11.5px;color:#374151;">TOTALS</td>
                <td class="tr">${inv.total_qty.toLocaleString()}</td>
                <td></td><td></td><td></td>
                <td class="tr">${inv.total_discount.toFixed(2)}</td>
                <td class="tr" style="color:#0e7490;">Rs.${inv.total_amount.toFixed(2)}</td>
              </tr>
            </tfoot>
          </table>
          <div class="pay-row">${payHtml}</div>
        </div>
      </div>`;
    list.appendChild(el);
  });

  document.getElementById('previewSection').style.display = 'block';
  document.getElementById('previewSection').scrollIntoView({ behavior: 'smooth' });
}

function toggleInv(idx) {
  const body = document.getElementById('body-' + idx);
  const chev = document.getElementById('chev-' + idx);
  const open = body.classList.toggle('open');
  chev.style.transform = open ? 'rotate(180deg)' : '';
}

/* ─────────────────────────────────────────────────
   Import
───────────────────────────────────────────────── */
function doImport() {
  if (!parsedData) return;

  const stockOut = document.getElementById('stockOutCheck').checked;

  /* Confirm when stock will be deducted — include shortage info */
  if (stockOut) {
    const need = neededPerProduct(parsedData.invoices);
    const codes = Object.keys(need);
    const shortage = codes.filter(c => productStatus(c, need) === 'short');
    const notFound = codes.filter(c => productStatus(c, need) === 'notfound');
    const totalQty = parsedData.invoices.reduce((s, inv) => s + inv.total_qty, 0);

    let msg = `Stock OUT is ON.\n\n${totalQty.toLocaleString()} total quantity will be DEDUCTED from available stock and saved in the stock log as STOCK OUT.`;
    if (shortage.length) msg += `\n\n⚠ WARNING: ${shortage.length} product(s) do NOT have enough available stock — their stock will go NEGATIVE.`;
    if (notFound.length) msg += `\n\n⚠ WARNING: ${notFound.length} product code(s) are not in the Items master.`;
    msg += `\n\nContinue?`;
    if (!confirm(msg)) return;
  }

  document.getElementById('previewSection').style.display = 'none';
  document.getElementById('progressSection').style.display = 'block';
  document.getElementById('progressSection').scrollIntoView({ behavior: 'smooth' });

  const { invoices, filename, dateFrom, dateTo } = parsedData;
  const note    = document.getElementById('importNote').value.trim();
  const impDate = document.getElementById('importDate').value;

  const payload = {
    action:           'import_invoices',
    filename, note,
    import_date:      impDate,
    date_from:        dateFrom,
    date_to:          dateTo,
    import_stock_out: stockOut ? 1 : 0,
    invoices
  };

  setProgress(10, stockOut ? 'Sending data to server (stock will be deducted)…' : 'Sending data to server…');

  fetch('api_ushop_invoice_import.php', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify(payload)
  })
  .then(r => r.json())
  .then(res => {
    setProgress(100, 'Done!');
    setTimeout(() => {
      document.getElementById('progressSection').style.display = 'none';
      if (res.success) {
        const stockMsg = stockOut
          ? ` Stock deducted &amp; stock log updated with <strong>${res.stock_rows || 0}</strong> STOCK OUT entries.`
          : ` <span style="color:#6b7280;">(Stock OUT was OFF — stock not changed.)</span>`;
        showMsg('ok', `<i class="fa-solid fa-circle-check"></i> Successfully imported <strong>${res.invoices}</strong> invoices, <strong>${res.items}</strong> items.${stockMsg} <a href="ushop_invoice_history.php" style="color:#166534;">View History →</a>`);
        resetAll();
      } else {
        showMsg('err', `<i class="fa-solid fa-triangle-exclamation"></i> Import failed: ${res.message || 'Unknown error'}`);
      }
    }, 600);
  })
  .catch(err => {
    document.getElementById('progressSection').style.display = 'none';
    showMsg('err', `<i class="fa-solid fa-triangle-exclamation"></i> Error: ${err.message}`);
  });
}

function setProgress(pct, msg) {
  document.getElementById('progressBar').style.width = pct + '%';
  document.getElementById('progressMsg').textContent = msg;
}

function showMsg(type, html) {
  const el = document.getElementById('mainMsg');
  el.innerHTML = `<div class="alert alert-${type === 'ok' ? 'ok' : 'err'}">${html}</div>`;
  el.scrollIntoView({ behavior: 'smooth' });
}

function resetAll() {
  parsedData = null;
  stockMap   = null;
  document.getElementById('previewSection').style.display = 'none';
  document.getElementById('optionsCard').style.display = 'none';
  document.getElementById('progressSection').style.display = 'none';
  document.getElementById('fileInput').value = '';
  document.getElementById('importNote').value = '';
  document.getElementById('stockOutCheck').checked = true;
  toggleStockOpt();
  zone.innerHTML = `<div class="icon"><i class="fa-solid fa-file-excel"></i></div>
    <h3>Drop or Click to Select Excel File</h3>
    <p>POS Bill Wise Sales Report (.xlsx) — Sales Details + Items + Payments</p>
    <input type="file" id="fileInput" accept=".xlsx,.xls">`;
  document.getElementById('fileInput').addEventListener('change', e => handleFile(e.target.files[0]));
}
</script>
<?php include 'footer.php'; ?>