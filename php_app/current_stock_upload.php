<?php
include 'config.php';

/* ── ensure upload table exists ── */
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS `current_stock_uploads` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `entry_date`  DATE         NOT NULL,
  `filename`    VARCHAR(255) NOT NULL,
  `total_rows`  INT(11)      NOT NULL DEFAULT 0,
  `uploaded_by` VARCHAR(100) DEFAULT NULL,
  `uploaded_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note`        TEXT         DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_entry_date` (`entry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS `current_stock_data` (
  `id`                   INT(11)       NOT NULL AUTO_INCREMENT,
  `upload_id`            INT(11)       NOT NULL,
  `entry_date`           DATE          NOT NULL,
  `sr_no`                INT(11)       DEFAULT NULL,
  `division`             VARCHAR(100)  DEFAULT NULL,
  `basepack_code`        VARCHAR(100)  DEFAULT NULL,
  `sku7`                 VARCHAR(50)   DEFAULT NULL,
  `product_name`         VARCHAR(500)  DEFAULT NULL,
  `location`             VARCHAR(255)  DEFAULT NULL,
  `pkm`                  DECIMAL(15,4) DEFAULT NULL,
  `batch_code`           VARCHAR(100)  DEFAULT NULL,
  `expiry_month`         VARCHAR(20)   DEFAULT NULL,
  `expiry_date`          DATE          DEFAULT NULL,
  `no_of_days_to_expire` INT(11)       DEFAULT NULL,
  `upc`                  BIGINT(20)    DEFAULT NULL,
  `units`                INT(11)       DEFAULT NULL,
  `stocks_in_days`       VARCHAR(50)   DEFAULT NULL,
  `pur_rate`             DECIMAL(15,4) DEFAULT NULL,
  `pur_rate_tax`         DECIMAL(15,4) DEFAULT NULL,
  `tur`                  DECIMAL(15,4) DEFAULT NULL,
  `mrp`                  DECIMAL(15,4) DEFAULT NULL,
  `cur_stk_value`        DECIMAL(18,4) DEFAULT NULL,
  `tonnage`              DECIMAL(15,6) DEFAULT NULL,
  `created_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_upload_id`  (`upload_id`),
  KEY `idx_entry_date` (`entry_date`),
  KEY `idx_division`   (`division`),
  KEY `idx_location`   (`location`),
  CONSTRAINT `fk_stock_upload`
    FOREIGN KEY (`upload_id`) REFERENCES `current_stock_uploads`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ── helper: safe num ── */
function safeNum($v) {
    if ($v === null || $v === '' || (is_string($v) && strtolower(trim($v)) === 'nan')) return null;
    $v = str_replace(',', '', trim($v));
    return is_numeric($v) ? $v : null;
}
function safeInt($v) {
    $n = safeNum($v);
    return $n !== null ? intval($n) : null;
}
function safeDate($v) {
    if ($v === null || $v === '') return null;
    if ($v instanceof DateTime) return $v->format('Y-m-d');
    $s = trim((string)$v);
    if ($s === '' || $s === '0000-00-00') return null;
    $t = strtotime($s);
    return $t ? date('Y-m-d', $t) : null;
}

/* ── AJAX upload handler ── */
$msg = ''; $msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    header('Content-Type: application/json');

    $entry_date = trim($_POST['entry_date'] ?? '');
    $note       = trim($_POST['note'] ?? '');

    if (!$entry_date || !strtotime($entry_date)) {
        echo json_encode(['ok'=>false,'msg'=>'Please select a valid Entry Date.']);
        exit;
    }

    if (!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok'=>false,'msg'=>'File upload failed. Please try again.']);
        exit;
    }

    $file     = $_FILES['xlsx_file'];
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx','xls'])) {
        echo json_encode(['ok'=>false,'msg'=>'Only .xlsx or .xls files are allowed.']);
        exit;
    }

    $tmp = $file['tmp_name'];

    /* ── Parse xlsx using native PHP built-in extensions only ──
       Uses: ZipArchive (zip), SimpleXML (xml), libxml — all standard PHP
       No vendor, no composer, no third-party libraries required            ── */

    $rows_parsed = [];

    // 1. Open xlsx as ZIP
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        echo json_encode(['ok'=>false,'msg'=>'Cannot open xlsx file. Make sure it is a valid .xlsx file.']);
        exit;
    }

    $shared_xml = $zip->getFromName('xl/sharedStrings.xml');
    $sheet_xml  = $zip->getFromName('xl/worksheets/sheet1.xml');
    $styles_xml = $zip->getFromName('xl/styles.xml');
    $zip->close();

    if (!$sheet_xml) {
        echo json_encode(['ok'=>false,'msg'=>'Cannot read sheet data from xlsx file.']);
        exit;
    }

    // 2. Build shared strings table
    $shared = [];
    if ($shared_xml) {
        libxml_use_internal_errors(true);
        $sxml = simplexml_load_string($shared_xml);
        if ($sxml) {
            foreach ($sxml->si as $si) {
                $t = '';
                if (isset($si->t)) {
                    $t = (string)$si->t;
                } elseif (isset($si->r)) {
                    foreach ($si->r as $r) {
                        if (isset($r->t)) $t .= (string)$r->t;
                    }
                }
                $shared[] = $t;
            }
        }
    }

    // 3. Detect date format indices from styles (to convert Excel serial dates)
    $date_format_ids = [];
    if ($styles_xml) {
        libxml_use_internal_errors(true);
        $stxml = simplexml_load_string($styles_xml);
        if ($stxml && isset($stxml->cellXfs)) {
            $built_in_date = [14,15,16,17,18,19,20,21,22,45,46,47];
            $custom_date_ids = [];
            if (isset($stxml->numFmts)) {
                foreach ($stxml->numFmts->numFmt as $nf) {
                    $id  = (int)$nf['numFmtId'];
                    $fmt = strtolower((string)$nf['formatCode']);
                    if (strpos($fmt,'y')!==false || strpos($fmt,'d')!==false) {
                        $custom_date_ids[] = $id;
                    }
                }
            }
            $xi = 0;
            foreach ($stxml->cellXfs->xf as $xf) {
                $nfid = (int)$xf['numFmtId'];
                if (in_array($nfid, $built_in_date) || in_array($nfid, $custom_date_ids)) {
                    $date_format_ids[] = $xi;
                }
                $xi++;
            }
        }
    }

    // Helper: convert Excel serial date to Y-m-d
    $serial_to_date = function($serial) {
        if (!is_numeric($serial) || $serial <= 0) return null;
        $unix = ((float)$serial - 25569) * 86400;
        return $unix > 0 ? date('Y-m-d', (int)$unix) : null;
    };

    // Helper: column letters to zero-based index (A=0, B=1, Z=25, AA=26…)
    $col_index = function($letters) {
        $letters = strtoupper($letters);
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return $index - 1;
    };

    // 4. Parse sheet XML into a row/col matrix
    libxml_use_internal_errors(true);
    $sxml   = simplexml_load_string($sheet_xml);
    if (!$sxml) {
        echo json_encode(['ok'=>false,'msg'=>'Failed to parse sheet XML.']);
        exit;
    }

    $matrix = [];
    foreach ($sxml->sheetData->row as $row) {
        $ri = (int)$row['r'] - 1;  // 0-based row index
        foreach ($row->c as $c) {
            $ref  = (string)$c['r'];
            preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
            if (!isset($m[1])) continue;
            $ci   = $col_index($m[1]);
            $type = (string)$c['t'];
            $s    = isset($c['s']) ? (int)$c['s'] : -1;
            $raw  = isset($c->v) ? (string)$c->v : '';

            if ($type === 's') {
                // Shared string
                $val = $shared[(int)$raw] ?? '';
            } elseif ($type === 'b') {
                $val = $raw ? 'TRUE' : 'FALSE';
            } elseif ($type === 'str' || $type === 'inlineStr') {
                $val = isset($c->is->t) ? (string)$c->is->t : $raw;
            } else {
                // Numeric — check if it's a date style
                if ($raw !== '' && $s >= 0 && in_array($s, $date_format_ids)) {
                    $val = $serial_to_date($raw) ?? $raw;
                } else {
                    $val = $raw;
                }
            }
            $matrix[$ri][$ci] = $val;
        }
    }

    // 5. Find header row (the row containing "Sr No")
    $header_row = null;
    $header_ri  = null;
    foreach ($matrix as $ri => $row) {
        foreach ($row as $cell) {
            if (strtolower(trim((string)$cell)) === 'sr no') {
                $header_row = $row;
                $header_ri  = $ri;
                break 2;
            }
        }
    }
    if ($header_row === null) {
        echo json_encode(['ok'=>false,'msg'=>'Header row (Sr No) not found in file. Make sure this is the correct Current Stock Report format.']);
        exit;
    }

    // Sort header columns by index and build headers array
    ksort($header_row);
    $col_keys   = array_keys($header_row);
    $headers    = array_map('trim', array_values($header_row));
    $col_count  = count($headers);

    // 6. Build rows array — each row mapped to header names
    foreach ($matrix as $ri => $row) {
        if ($ri <= $header_ri) continue;
        $vals = [];
        foreach ($col_keys as $ci) {
            $vals[] = isset($row[$ci]) ? $row[$ci] : null;
        }
        // Skip fully empty rows
        if (empty(array_filter($vals, fn($x) => $x !== null && $x !== ''))) continue;
        $rows_parsed[] = array_combine($headers, $vals);
    }

    if (empty($rows_parsed)) {
        echo json_encode(['ok'=>false,'msg'=>'No data rows found in the file.']);
        exit;
    }

    /* ── Insert into DB ── */
    // Enable mysqli exceptions so errors are catchable
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    mysqli_begin_transaction($conn);
    try {
        // Insert upload batch record
        $filename_esc = mysqli_real_escape_string($conn, $file['name']);
        $entry_esc    = mysqli_real_escape_string($conn, $entry_date);
        $note_esc     = mysqli_real_escape_string($conn, $note);
        $total        = count($rows_parsed);

        $ok = mysqli_query($conn, "INSERT INTO current_stock_uploads
            (entry_date, filename, total_rows, note, uploaded_at)
            VALUES ('$entry_esc','$filename_esc',$total,'$note_esc',NOW())");
        if (!$ok) throw new Exception('Failed to insert upload record: '.mysqli_error($conn));

        $upload_id = mysqli_insert_id($conn);
        if (!$upload_id) throw new Exception('Could not get upload ID after insert.');

        /* ── Prepared statement
           22 columns = 22 bind types:
           i  upload_id           INT
           s  entry_date          DATE string
           i  sr_no               INT (nullable)
           s  division            VARCHAR
           s  basepack_code       VARCHAR
           s  sku7                VARCHAR
           s  product_name        VARCHAR
           s  location            VARCHAR
           s  pkm                 DECIMAL → pass as string (s), MySQL casts it
           s  batch_code          VARCHAR
           s  expiry_month        VARCHAR
           s  expiry_date         DATE string
           i  no_of_days_to_expire INT (nullable)
           s  upc                 BIGINT → string avoids int overflow on 32-bit
           i  units               INT (nullable)
           s  stocks_in_days      VARCHAR
           s  pur_rate            DECIMAL → string
           s  pur_rate_tax        DECIMAL → string
           s  tur                 DECIMAL → string
           s  mrp                 DECIMAL → string
           s  cur_stk_value       DECIMAL → string
           s  tonnage             DECIMAL → string
        ── */
        $sql = "INSERT INTO current_stock_data
            (upload_id, entry_date, sr_no, division, basepack_code, sku7,
             product_name, location, pkm, batch_code, expiry_month, expiry_date,
             no_of_days_to_expire, upc, units, stocks_in_days,
             pur_rate, pur_rate_tax, tur, mrp, cur_stk_value, tonnage)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) throw new Exception('Prepare failed: '.mysqli_error($conn));

        $inserted = 0;
        foreach ($rows_parsed as $r) {
            // Lowercase key map for flexible column name matching
            $rk = [];
            foreach ($r as $k => $v) $rk[strtolower(trim((string)$k))] = $v;
            $get = fn($k) => $rk[$k] ?? null;

            // --- Scalar values ---
            $v_upload_id  = (int)$upload_id;
            $v_entry_date = $entry_date;                                               // string s
            $v_sr_no      = safeInt($get('sr no'));                                    // i
            $v_division   = substr(trim((string)($get('division')   ?? '')),0,100) ?: null; // s
            $v_bp_code    = substr(trim((string)($get('basepack code') ?? '')),0,100) ?: null; // s
            $v_sku7       = substr(trim((string)($get('sku7')        ?? '')),0,50)  ?: null; // s
            $v_prod       = substr(trim((string)($get('product name') ?? '')),0,500) ?: null; // s
            $v_location   = substr(trim((string)($get('location')    ?? '')),0,255) ?: null; // s
            $v_pkm        = safeNum($get('pkm'));                                       // s decimal
            $v_batch      = substr(trim((string)($get('batch code')  ?? '')),0,100) ?: null; // s

            // Expiry month — can be numeric like 0424 or string
            $raw_em   = $get('expiry month');
            $v_exp_month = null;
            if ($raw_em !== null && $raw_em !== '') {
                $n = safeNum($raw_em);
                $v_exp_month = ($n !== null) ? (string)intval($n) : substr(trim((string)$raw_em),0,20);
            }

            // Expiry date — may come in as already-converted Y-m-d string from our parser
            // or still as an Excel serial number if styles detection missed it
            $raw_ed   = $get('expiry date');
            $v_exp_date = null;
            if ($raw_ed !== null && $raw_ed !== '') {
                $s_ed = trim((string)$raw_ed);
                if (is_numeric($s_ed) && (float)$s_ed > 1000) {
                    // Excel serial date
                    $unix = ((float)$s_ed - 25569) * 86400;
                    $v_exp_date = ($unix > 0) ? date('Y-m-d', (int)$unix) : null;
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s_ed)) {
                    $v_exp_date = $s_ed; // already Y-m-d
                } else {
                    $t = strtotime($s_ed);
                    $v_exp_date = $t ? date('Y-m-d', $t) : null;
                }
            }

            $v_days_exp = safeInt($get('no of days to expire'));                       // i
            $v_upc      = ($get('upc') !== null && $get('upc') !== '') ? (string)$get('upc') : null; // s (bigint safe)
            $v_units    = safeInt($get('units'));                                       // i
            $v_stk_days = substr(trim((string)($get('stocks in days') ?? '')),0,50) ?: null; // s
            $v_pur_rate = safeNum($get('pur.rate'));                                   // s decimal
            $v_pur_tax  = safeNum($get('pur.rate + tax'));                             // s decimal
            $v_tur      = safeNum($get('tur'));                                         // s decimal
            $v_mrp      = safeNum($get('mrp'));                                         // s decimal
            $v_stk_val  = safeNum($get('cur.stk value'));                              // s decimal
            $v_tonnage  = safeNum($get('tonnage'));                                    // s decimal

            // Type string: i s i s s s s s s s s s i s i s s s s s s s  = 22 chars
            mysqli_stmt_bind_param($stmt,
                'isisssssssssisisssssss',
                $v_upload_id,
                $v_entry_date,
                $v_sr_no,
                $v_division,
                $v_bp_code,
                $v_sku7,
                $v_prod,
                $v_location,
                $v_pkm,
                $v_batch,
                $v_exp_month,
                $v_exp_date,
                $v_days_exp,
                $v_upc,
                $v_units,
                $v_stk_days,
                $v_pur_rate,
                $v_pur_tax,
                $v_tur,
                $v_mrp,
                $v_stk_val,
                $v_tonnage
            );

            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception('Row insert failed: '.mysqli_stmt_error($stmt));
            }
            $inserted++;
        }

        mysqli_stmt_close($stmt);
        mysqli_commit($conn);

        echo json_encode([
            'ok'        => true,
            'msg'       => "Upload successful! $inserted rows inserted.",
            'upload_id' => $upload_id,
            'rows'      => $inserted
        ]);

    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['ok'=>false,'msg'=>'Error: '.$e->getMessage()]);
    }
    exit;
}

include 'header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>

<style>
*{box-sizing:border-box;}
.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-success{background:#15803d;color:#fff;}.btn-success:hover{background:#166534;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-sm{padding:5px 12px;font-size:12px;}

/* upload card */
.upload-card{background:#fff;border:1px solid #e5e5e5;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden;max-width:680px;margin:0 auto 30px;}
.upload-card-hdr{background:linear-gradient(135deg,#1e1b4b,#1e40af);padding:22px 28px;color:#fff;}
.upload-card-hdr h3{margin:0 0 4px;font-size:17px;font-weight:800;}
.upload-card-hdr p{margin:0;font-size:12.5px;opacity:.75;}
.upload-card-body{padding:28px;}

.form-group{margin-bottom:18px;}
.form-group label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em;}
.form-control{width:100%;padding:9px 12px;border:1.5px solid #e0e0e0;border-radius:7px;font-size:13px;font-family:inherit;color:#111;outline:none;transition:border .15s;}
.form-control:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1);}

/* drop zone */
.drop-zone{border:2.5px dashed #c7d2fe;border-radius:10px;padding:36px 20px;text-align:center;cursor:pointer;transition:all .2s;background:#f8faff;}
.drop-zone:hover,.drop-zone.dragover{border-color:#3b82f6;background:#eff6ff;}
.drop-zone .dz-icon{font-size:36px;color:#6366f1;margin-bottom:10px;}
.drop-zone .dz-text{font-size:13.5px;font-weight:700;color:#374151;margin-bottom:4px;}
.drop-zone .dz-sub{font-size:11.5px;color:#9ca3af;}
.file-chosen{background:#f0fdf4;border-color:#86efac;border-style:solid;}
.file-chosen .dz-icon{color:#16a34a;}
#fileInput{display:none;}

/* progress */
.progress-wrap{margin-top:16px;display:none;}
.progress-bar-outer{background:#e5e7eb;border-radius:8px;height:10px;overflow:hidden;}
.progress-bar-inner{background:linear-gradient(90deg,#3b82f6,#6366f1);height:100%;width:0%;transition:width .3s;border-radius:8px;}
.progress-label{font-size:11.5px;color:#6b7280;margin-top:6px;text-align:center;}

/* result box */
.result-box{margin-top:18px;padding:14px 18px;border-radius:8px;font-size:13px;font-weight:600;display:none;}
.result-ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.result-err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}

/* summary cards */
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
@media(max-width:900px){.summary-grid{grid-template-columns:1fr 1fr;}}
.sum-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.sum-card-label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;}
.sum-card-val{font-size:22px;font-weight:800;color:#1e40af;}

/* history table */
.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;border-bottom:1px solid #f0f0f0;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead th{padding:7px 10px;text-align:left;font-weight:700;font-size:11px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;}
.data-table thead th.tr{text-align:right;}
.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr:hover td{background:#f0f7ff;}
.data-table td{padding:8px 10px;color:#374151;vertical-align:middle;background:#fff;}
.data-table td.tr{text-align:right;}
.data-table td.tc{text-align:center;}
.badge{display:inline-block;padding:2px 10px;border-radius:10px;font-size:10.5px;font-weight:700;}
.badge-blue{background:#dbeafe;color:#1e40af;}
.badge-green{background:#d1fae5;color:#065f46;}

/* toast */
#toast{position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:100020;padding:12px 26px;border-radius:9px;font-size:13px;font-weight:700;font-family:inherit;box-shadow:0 8px 28px rgba(0,0,0,.2);display:none;pointer-events:none;}
.toast-ok{background:#166534;color:#fff;}.toast-err{background:#dc2626;color:#fff;}
</style>

<!-- PAGE HEADER -->
<div class="ph-row">
  <div>
    <h2 class="page-title" style="margin:0 0 4px;">Current Stock Upload</h2>
    <p style="margin:0;font-size:13px;color:#6b7280;">Upload Current Stock Excel files into the database by Entry Date.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="current_stock_history.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-clock-rotate-left"></i> Upload History</a>
    <a href="current_stock_view.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-table"></i> View Data</a>
  </div>
</div>

<!-- SUMMARY CARDS -->
<?php
$stats = mysqli_fetch_assoc(mysqli_query($conn,"
  SELECT COUNT(*) AS total_uploads,
         COALESCE(SUM(total_rows),0) AS total_rows,
         COUNT(DISTINCT entry_date) AS unique_dates,
         MAX(uploaded_at) AS last_upload
  FROM current_stock_uploads
"));
?>
<div class="summary-grid">
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-upload" style="margin-right:4px;"></i>Total Uploads</div>
    <div class="sum-card-val"><?= number_format($stats['total_uploads']) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-table-rows" style="margin-right:4px;"></i>Total Rows</div>
    <div class="sum-card-val"><?= number_format($stats['total_rows']) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-calendar-days" style="margin-right:4px;"></i>Entry Dates</div>
    <div class="sum-card-val"><?= number_format($stats['unique_dates']) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-clock" style="margin-right:4px;"></i>Last Upload</div>
    <div class="sum-card-val" style="font-size:13px;padding-top:5px;">
      <?= $stats['last_upload'] ? date('d M Y H:i', strtotime($stats['last_upload'])) : '—' ?>
    </div>
  </div>
</div>

<!-- UPLOAD FORM -->
<div class="upload-card">
  <div class="upload-card-hdr">
    <h3><i class="fa-solid fa-file-arrow-up" style="margin-right:8px;"></i>Upload New File</h3>
    <p>Select entry date, choose your Current Stock .xlsx file and click Upload.</p>
  </div>
  <div class="upload-card-body">
    <div class="form-group">
      <label><i class="fa-solid fa-calendar-day" style="margin-right:5px;"></i>Entry Date <span style="color:#dc2626;">*</span></label>
      <input type="date" id="entryDate" class="form-control" value="<?= date('Y-m-d') ?>">
      <div style="font-size:11px;color:#6b7280;margin-top:4px;">This date will be stored with every row of data from this file.</div>
    </div>
    <div class="form-group">
      <label><i class="fa-solid fa-file-excel" style="margin-right:5px;"></i>Excel File (.xlsx / .xls) <span style="color:#dc2626;">*</span></label>
      <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
        <div class="dz-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
        <div class="dz-text" id="dzText">Drop file here or click to browse</div>
        <div class="dz-sub" id="dzSub">Supports: .xlsx, .xls &nbsp;·&nbsp; Current Stock Report format</div>
      </div>
      <input type="file" id="fileInput" accept=".xlsx,.xls">
    </div>
    <div class="form-group">
      <label><i class="fa-solid fa-note-sticky" style="margin-right:5px;"></i>Note (optional)</label>
      <input type="text" id="uploadNote" class="form-control" placeholder="e.g. Week 16 upload, April batch…">
    </div>

    <div class="progress-wrap" id="progressWrap">
      <div class="progress-bar-outer"><div class="progress-bar-inner" id="progressBar"></div></div>
      <div class="progress-label" id="progressLabel">Uploading…</div>
    </div>

    <div class="result-box" id="resultBox"></div>

    <button class="btn btn-success" id="uploadBtn" onclick="doUpload()" style="width:100%;margin-top:6px;height:42px;font-size:14px;">
      <i class="fa-solid fa-database"></i> Upload to Database
    </button>
  </div>
</div>

<!-- RECENT UPLOADS TABLE -->
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title">Recent Uploads</div>
    <a href="current_stock_history.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-list"></i> View All</a>
  </div>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Entry Date</th>
        <th>Filename</th>
        <th class="tr">Rows</th>
        <th>Note</th>
        <th>Uploaded At</th>
        <th class="tc">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $recent = mysqli_query($conn,"SELECT * FROM current_stock_uploads ORDER BY uploaded_at DESC LIMIT 10");
    $i=1;
    while ($r = mysqli_fetch_assoc($recent)):
    ?>
      <tr>
        <td style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
        <td><span class="badge badge-blue"><?= date('d M Y',strtotime($r['entry_date'])) ?></span></td>
        <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11.5px;" title="<?= htmlspecialchars($r['filename']) ?>"><?= htmlspecialchars($r['filename']) ?></td>
        <td class="tr"><span class="badge badge-green"><?= number_format($r['total_rows']) ?></span></td>
        <td style="font-size:11.5px;color:#6b7280;"><?= htmlspecialchars($r['note'] ?: '—') ?></td>
        <td style="font-size:11.5px;"><?= date('d M Y H:i',strtotime($r['uploaded_at'])) ?></td>
        <td class="tc" style="white-space:nowrap;">
          <a href="current_stock_view.php?upload_id=<?= $r['id'] ?>" class="btn btn-primary btn-sm" title="View"><i class="fa-solid fa-eye"></i></a>
          <a href="current_stock_history.php?delete=<?= $r['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this upload and all its data?')" title="Delete"><i class="fa-solid fa-trash"></i></a>
        </td>
      </tr>
    <?php endwhile; ?>
    <?php if ($i === 1): ?>
      <tr><td colspan="7" style="text-align:center;padding:40px;color:#9ca3af;">No uploads yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div id="toast"></div>

<script>
const dropZone   = document.getElementById('dropZone');
const fileInput  = document.getElementById('fileInput');
let selectedFile = null;

// Drag & drop
dropZone.addEventListener('dragover', e=>{e.preventDefault();dropZone.classList.add('dragover');});
dropZone.addEventListener('dragleave',()=>dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', e=>{
  e.preventDefault(); dropZone.classList.remove('dragover');
  const f = e.dataTransfer.files[0];
  if (f) setFile(f);
});
fileInput.addEventListener('change', ()=>{ if(fileInput.files[0]) setFile(fileInput.files[0]); });

function setFile(f) {
  const ext = f.name.split('.').pop().toLowerCase();
  if (!['xlsx','xls'].includes(ext)) { showToast('Only .xlsx or .xls files allowed.','err'); return; }
  selectedFile = f;
  dropZone.classList.add('file-chosen');
  document.getElementById('dzText').textContent = f.name;
  document.getElementById('dzSub').textContent  = (f.size/1024/1024).toFixed(2)+' MB  ·  Click to change';
  document.getElementById('resultBox').style.display = 'none';
}

function doUpload() {
  const date = document.getElementById('entryDate').value;
  if (!date) { showToast('Please select an Entry Date.','err'); return; }
  if (!selectedFile) { showToast('Please choose an Excel file.','err'); return; }

  const fd = new FormData();
  fd.append('action','upload');
  fd.append('entry_date', date);
  fd.append('note', document.getElementById('uploadNote').value);
  fd.append('xlsx_file', selectedFile);

  document.getElementById('progressWrap').style.display = 'block';
  document.getElementById('uploadBtn').disabled = true;
  document.getElementById('uploadBtn').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';

  const xhr = new XMLHttpRequest();
  xhr.open('POST', 'current_stock_upload.php');

  xhr.upload.onprogress = e => {
    if (e.lengthComputable) {
      const pct = Math.round(e.loaded/e.total*100);
      document.getElementById('progressBar').style.width  = pct+'%';
      document.getElementById('progressLabel').textContent = 'Uploading file… '+pct+'%';
    }
  };

  xhr.onload = () => {
    document.getElementById('progressLabel').textContent = 'Processing rows…';
    try {
      const res = JSON.parse(xhr.responseText);
      const box = document.getElementById('resultBox');
      box.style.display = 'block';
      if (res.ok) {
        box.className = 'result-box result-ok';
        box.innerHTML = '<i class="fa-solid fa-circle-check"></i> '+res.msg;
        document.getElementById('progressBar').style.width='100%';
        showToast(res.msg,'ok');
        setTimeout(()=>location.reload(), 1800);
      } else {
        box.className = 'result-box result-err';
        box.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> '+res.msg;
        showToast(res.msg,'err');
      }
    } catch(e) {
      document.getElementById('resultBox').className='result-box result-err';
      document.getElementById('resultBox').innerHTML='<i class="fa-solid fa-circle-xmark"></i> Unexpected server response.';
      document.getElementById('resultBox').style.display='block';
    }
    document.getElementById('uploadBtn').disabled = false;
    document.getElementById('uploadBtn').innerHTML = '<i class="fa-solid fa-database"></i> Upload to Database';
  };
  xhr.onerror = () => {
    showToast('Network error. Please try again.','err');
    document.getElementById('uploadBtn').disabled = false;
    document.getElementById('uploadBtn').innerHTML = '<i class="fa-solid fa-database"></i> Upload to Database';
  };
  xhr.send(fd);
}

function showToast(msg,type){
  const t=document.getElementById('toast');
  t.className=type==='ok'?'toast-ok':'toast-err';
  t.textContent=msg;t.style.display='block';t.style.opacity='1';
  clearTimeout(t._t);
  t._t=setTimeout(()=>{t.style.opacity='0';setTimeout(()=>t.style.display='none',300);},3200);
}
</script>

<?php include 'footer.php'; ?>