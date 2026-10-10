<?php
include 'config.php';

/* ── ensure tables exist ── */
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS `invoice_wise_sales_uploads` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `delivery_date` DATE         NOT NULL,
  `filename`      VARCHAR(255) NOT NULL,
  `total_rows`    INT(11)      NOT NULL DEFAULT 0,
  `uploaded_by`   VARCHAR(100) DEFAULT NULL,
  `uploaded_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note`          TEXT         DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_delivery_date` (`delivery_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS `invoice_wise_sales_data` (
  `id`                          INT(11)        NOT NULL AUTO_INCREMENT,
  `upload_id`                   INT(11)        NOT NULL,
  `delivery_date`               DATE           NOT NULL,
  `sr_no`                       INT(11)        DEFAULT NULL,
  `salesperson_code`            VARCHAR(50)    DEFAULT NULL,
  `salesperson_name`            VARCHAR(255)   DEFAULT NULL,
  `outlet_hul_code`             VARCHAR(100)   DEFAULT NULL,
  `outlet_code`                 VARCHAR(100)   DEFAULT NULL,
  `outlet_name`                 VARCHAR(255)   DEFAULT NULL,
  `outlet_category`             VARCHAR(100)   DEFAULT NULL,
  `bill_number`                 VARCHAR(50)    DEFAULT NULL,
  `moc`                         VARCHAR(50)    DEFAULT NULL,
  `bill_date`                   DATE           DEFAULT NULL,
  `basepack_code`               VARCHAR(100)   DEFAULT NULL,
  `product_code`                VARCHAR(100)   DEFAULT NULL,
  `product_description`         VARCHAR(500)   DEFAULT NULL,
  `mrp`                         DECIMAL(15,4)  DEFAULT NULL,
  `tur`                         DECIMAL(15,4)  DEFAULT NULL,
  `pkd`                         INT(11)        DEFAULT NULL,
  `batch_code`                  VARCHAR(100)   DEFAULT NULL,
  `expiry_date`                 DATE           DEFAULT NULL,
  `units`                       INT(11)        DEFAULT NULL,
  `free_qty`                    INT(11)        DEFAULT NULL,
  `gross_sales`                 DECIMAL(18,4)  DEFAULT NULL,
  `scheme_disc`                 DECIMAL(15,4)  DEFAULT NULL,
  `rs_discount`                 DECIMAL(15,4)  DEFAULT NULL,
  `tot_disc`                    DECIMAL(15,4)  DEFAULT NULL,
  `total_discount`              DECIMAL(15,4)  DEFAULT NULL,
  `taxable_amount`              DECIMAL(18,4)  DEFAULT NULL,
  `total_tax`                   DECIMAL(15,4)  DEFAULT NULL,
  `total_tax_pct`               DECIMAL(8,4)   DEFAULT NULL,
  `bill_value`                  DECIMAL(18,4)  DEFAULT NULL,
  `good_returns_qty`            INT(11)        DEFAULT NULL,
  `good_returns_value`          DECIMAL(15,4)  DEFAULT NULL,
  `damage_expiry_shortage_qty`  INT(11)        DEFAULT NULL,
  `damage_expiry_shortage_val`  DECIMAL(15,4)  DEFAULT NULL,
  `final_bill_amount`           DECIMAL(18,4)  DEFAULT NULL,
  `sch_per_unit_after_tax`      DECIMAL(15,4)  DEFAULT NULL,
  `bill_cap`                    VARCHAR(20)    DEFAULT NULL,
  `delivery_person_name`        VARCHAR(255)   DEFAULT NULL,
  `bill_posting_date`           DATE           DEFAULT NULL,
  `created_at`                  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_upload_id`     (`upload_id`),
  KEY `idx_delivery_date` (`delivery_date`),
  KEY `idx_salesperson`   (`salesperson_code`),
  KEY `idx_outlet`        (`outlet_code`),
  KEY `idx_product`       (`product_description`(100)),
  KEY `idx_bill_date`     (`bill_date`),
  CONSTRAINT `fk_iws_upload` FOREIGN KEY (`upload_id`)
    REFERENCES `invoice_wise_sales_uploads`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ── helpers ── */
function safeNum($v) {
    if ($v === null || $v === '' || (is_string($v) && strtolower(trim($v)) === 'nan')) return null;
    $v = str_replace(',', '', trim((string)$v));
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
    // Handle Excel serial number
    if (is_numeric($s) && $s > 1000) {
        $unix = ((float)$s - 25569) * 86400;
        return $unix > 0 ? date('Y-m-d', (int)$unix) : null;
    }
    $t = strtotime($s);
    return $t ? date('Y-m-d', $t) : null;
}
function safeStr($v, $max = 255) {
    if ($v === null) return null;
    $s = trim((string)$v);
    return $s === '' ? null : mb_substr($s, 0, $max);
}
function esc($conn, $v) {
    return $v === null ? 'NULL' : "'".mysqli_real_escape_string($conn, $v)."'";
}

/* ── AJAX upload handler ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    header('Content-Type: application/json');

    $delivery_date = trim($_POST['delivery_date'] ?? '');
    $note          = trim($_POST['note'] ?? '');

    if (!$delivery_date || !strtotime($delivery_date)) {
        echo json_encode(['ok'=>false,'msg'=>'Please select a valid Delivery Date.']);
        exit;
    }

    if (!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok'=>false,'msg'=>'File upload failed. Please try again.']);
        exit;
    }

    $file = $_FILES['xlsx_file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx','xls'])) {
        echo json_encode(['ok'=>false,'msg'=>'Only .xlsx or .xls files are allowed.']);
        exit;
    }

    $tmp = $file['tmp_name'];

    /* ── Parse xlsx natively (ZipArchive + SimpleXML, no composer) ── */
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        echo json_encode(['ok'=>false,'msg'=>'Cannot open xlsx file. Make sure it is a valid .xlsx file.']);
        exit;
    }

    // Try both sheets — prefer "Details Report"
    $sheet_names_xml = $zip->getFromName('xl/workbook.xml');
    $target_sheet    = 'xl/worksheets/sheet1.xml';
    if ($sheet_names_xml) {
        libxml_use_internal_errors(true);
        $wb = simplexml_load_string($sheet_names_xml);
        if ($wb) {
            $ns = $wb->getNamespaces(true);
            $wb->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            foreach ($wb->sheets->sheet as $sh) {
                $attr = $sh->attributes();
                if (stripos((string)$attr['name'], 'Details') !== false) {
                    $rId = (string)$sh->attributes('r', true)['id'];
                    // Get relationship to find actual file
                    $rels_xml = $zip->getFromName('xl/_rels/workbook.xml.rels');
                    if ($rels_xml) {
                        $rxml = simplexml_load_string($rels_xml);
                        foreach ($rxml->Relationship as $rel) {
                            if ((string)$rel['Id'] === $rId) {
                                $target_sheet = 'xl/'.(string)$rel['Target'];
                                break 2;
                            }
                        }
                    }
                }
            }
        }
    }

    $shared_xml = $zip->getFromName('xl/sharedStrings.xml');
    $sheet_xml  = $zip->getFromName($target_sheet);
    $styles_xml = $zip->getFromName('xl/styles.xml');
    $zip->close();

    if (!$sheet_xml) {
        echo json_encode(['ok'=>false,'msg'=>'Cannot read sheet data from xlsx. Ensure it has a "Details Report" sheet.']);
        exit;
    }

    /* shared strings */
    $shared = [];
    if ($shared_xml) {
        libxml_use_internal_errors(true);
        $sxml = simplexml_load_string($shared_xml);
        if ($sxml) {
            foreach ($sxml->si as $si) {
                $t = '';
                if (isset($si->t))      { $t = (string)$si->t; }
                elseif (isset($si->r))  { foreach ($si->r as $r) { if (isset($r->t)) $t .= (string)$r->t; } }
                $shared[] = $t;
            }
        }
    }

    /* date format indices */
    $date_format_ids = [];
    if ($styles_xml) {
        libxml_use_internal_errors(true);
        $stxml = simplexml_load_string($styles_xml);
        if ($stxml && isset($stxml->cellXfs)) {
            $built_in_date  = [14,15,16,17,18,19,20,21,22,45,46,47];
            $custom_date_ids = [];
            if (isset($stxml->numFmts)) {
                foreach ($stxml->numFmts->numFmt as $nf) {
                    $id  = (int)$nf['numFmtId'];
                    $fmt = strtolower((string)$nf['formatCode']);
                    if (strpos($fmt,'y') !== false || strpos($fmt,'d') !== false)
                        $custom_date_ids[] = $id;
                }
            }
            $xi = 0;
            foreach ($stxml->cellXfs->xf as $xf) {
                $nfid = (int)$xf['numFmtId'];
                if (in_array($nfid, $built_in_date) || in_array($nfid, $custom_date_ids))
                    $date_format_ids[] = $xi;
                $xi++;
            }
        }
    }

    $serial_to_date = function($s) {
        if (!is_numeric($s) || $s <= 0) return null;
        $unix = ((float)$s - 25569) * 86400;
        return $unix > 0 ? date('Y-m-d', (int)$unix) : null;
    };

    $col_index = function($letters) {
        $letters = strtoupper($letters);
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++)
            $index = $index * 26 + (ord($letters[$i]) - 64);
        return $index - 1;
    };

    /* parse sheet XML */
    libxml_use_internal_errors(true);
    $sxml = simplexml_load_string($sheet_xml);
    if (!$sxml) {
        echo json_encode(['ok'=>false,'msg'=>'Failed to parse sheet XML.']);
        exit;
    }

    $matrix = [];
    foreach ($sxml->sheetData->row as $row) {
        $rnum = (int)$row['r'];
        $matrix[$rnum] = [];
        foreach ($row->c as $cell) {
            $ref   = (string)$cell['r'];
            preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
            $cIdx  = $col_index($m[1]);
            $t     = (string)$cell['t'];
            $s_idx = isset($cell['s']) ? (int)$cell['s'] : -1;
            $raw   = isset($cell->v) ? (string)$cell->v : '';

            if ($t === 'inlineStr') {
                // <is><t>text</t></is> — used in this file format
                $val = '';
                if (isset($cell->is->t)) {
                    $val = (string)$cell->is->t;
                } elseif (isset($cell->is->r)) {
                    foreach ($cell->is->r as $r) {
                        if (isset($r->t)) $val .= (string)$r->t;
                    }
                }
            } elseif ($t === 's') {
                $val = isset($shared[(int)$raw]) ? $shared[(int)$raw] : '';
            } elseif ($t === 'b') {
                $val = $raw ? 'TRUE' : 'FALSE';
            } elseif (in_array($s_idx, $date_format_ids) && is_numeric($raw)) {
                $val = $serial_to_date($raw);
            } else {
                $val = $raw;
            }
            $matrix[$rnum][$cIdx] = $val;
        }
    }

    /* find header row — look for "Salesperson code" anywhere in a row
       (Sr No column is not required; we detect by a required column name) */
    $header_row = null;
    $col_map    = [];
    foreach ($matrix as $rnum => $row) {
        foreach ($row as $ci => $cell_val) {
            if (strtolower(trim((string)$cell_val)) === 'salesperson code') {
                $header_row = $rnum;
                foreach ($row as $idx => $hdr) {
                    $col_map[strtolower(trim((string)$hdr))] = $idx;
                }
                break 2;
            }
        }
    }

    if ($header_row === null) {
        echo json_encode(['ok'=>false,'msg'=>'Could not find header row. Expected a column named "Salesperson code" in the Details Report sheet.']);
        exit;
    }

    /* map expected columns */
    $CM = [
        'sr_no'       => $col_map['sr no']                          ?? null,
        'sp_code'     => $col_map['salesperson code']               ?? null,
        'sp_name'     => $col_map['salesperson name']               ?? null,
        'hul_code'    => $col_map['outlet_hul code']                ?? null,
        'out_code'    => $col_map['outlet code']                    ?? null,
        'out_name'    => $col_map['outlet name']                    ?? null,
        'out_cat'     => $col_map['outlet_category']                ?? null,
        'bill_no'     => $col_map['bill number']                    ?? null,
        'moc'         => $col_map['moc']                            ?? null,
        'bill_date'   => $col_map['bill date']                      ?? null,
        'bp_code'     => $col_map['basepack code']                  ?? null,
        'prod_code'   => $col_map['product code']                   ?? null,
        'prod_desc'   => $col_map['product description']            ?? null,
        'mrp'         => $col_map['mrp']                            ?? null,
        'tur'         => $col_map['tur']                            ?? null,
        'pkd'         => $col_map['pkd']                            ?? null,
        'batch_code'  => $col_map['batch code']                     ?? null,
        'exp_date'    => $col_map['expiry date']                    ?? null,
        'units'       => $col_map['units']                          ?? null,
        'free_qty'    => $col_map['free qty']                       ?? null,
        'gross_sales' => $col_map['gross sales']                    ?? null,
        'sch_disc'    => $col_map['scheme disc']                    ?? null,
        'rs_disc'     => $col_map['rs discount']                    ?? null,
        'tot_disc'    => $col_map['tot disc']                       ?? null,
        'total_disc'  => $col_map['total discount']                 ?? null,
        'tax_amt'     => $col_map['taxable amount']                 ?? null,
        'tot_tax'     => $col_map['total tax']                      ?? null,
        'tax_pct'     => $col_map['total tax %']                    ?? null,
        'bill_val'    => $col_map['bill value']                     ?? null,
        'gr_qty'      => $col_map['good returns qty']               ?? null,
        'gr_val'      => $col_map['good returns value']             ?? null,
        'des_qty'     => $col_map['damage-expiry shortage qty']     ?? null,
        'des_val'     => $col_map['damage-expiry shortage value']   ?? null,
        'final_amt'   => $col_map['final bill amount(to be collected)'] ?? null,
        'sch_per_unit'=> $col_map['sch per unit( after tax)']       ?? null,
        'bill_cap'    => $col_map['bill cap']                       ?? null,
        'del_person'  => $col_map['delivery person name']           ?? null,
        'del_date'    => $col_map['delivery date']                  ?? null,
        'post_date'   => $col_map['bill posting date']              ?? null,
    ];

    function getC($row, $idx) {
        if ($idx === null) return null;
        return isset($row[$idx]) ? $row[$idx] : null;
    }

    /* insert upload record */
    $fname = mysqli_real_escape_string($conn, $file['name']);
    $dnote = mysqli_real_escape_string($conn, $note);
    $ddate = mysqli_real_escape_string($conn, $delivery_date);
    mysqli_query($conn, "INSERT INTO invoice_wise_sales_uploads (delivery_date,filename,total_rows,note) VALUES ('$ddate','$fname',0,'$dnote')");
    $upload_id = mysqli_insert_id($conn);

    if (!$upload_id) {
        echo json_encode(['ok'=>false,'msg'=>'Failed to create upload record.']);
        exit;
    }

    /* insert data rows in batches */
    $batch      = [];
    $batch_size = 200;
    $inserted   = 0;
    $skipped    = 0;

    $flush = function() use (&$batch, &$inserted, $conn, $upload_id, $delivery_date) {
        if (!$batch) return;
        $vals = implode(',', $batch);
        mysqli_query($conn, "INSERT INTO invoice_wise_sales_data
          (upload_id,delivery_date,sr_no,salesperson_code,salesperson_name,
           outlet_hul_code,outlet_code,outlet_name,outlet_category,
           bill_number,moc,bill_date,basepack_code,product_code,product_description,
           mrp,tur,pkd,batch_code,expiry_date,units,free_qty,gross_sales,
           scheme_disc,rs_discount,tot_disc,total_discount,taxable_amount,
           total_tax,total_tax_pct,bill_value,good_returns_qty,good_returns_value,
           damage_expiry_shortage_qty,damage_expiry_shortage_val,final_bill_amount,
           sch_per_unit_after_tax,bill_cap,delivery_person_name,bill_posting_date)
          VALUES $vals");
        $inserted += count($batch);
        $batch = [];
    };

    foreach ($matrix as $rnum => $row) {
        if ($rnum <= $header_row) continue;

        // Skip empty/footer rows — use salesperson_code as anchor
        $sp_code = getC($row, $CM['sp_code']);
        if ($sp_code === null || trim((string)$sp_code) === '') { $skipped++; continue; }

        $sr = getC($row, $CM['sr_no']);

        $bill_date_val = safeDate(getC($row, $CM['bill_date']));
        $exp_date_val  = safeDate(getC($row, $CM['exp_date']));
        $del_date_val  = safeDate(getC($row, $CM['del_date']));
        $post_date_val = safeDate(getC($row, $CM['post_date']));

        $batch[] = sprintf("(%d,'%s',%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
            $upload_id,
            mysqli_real_escape_string($conn, $delivery_date),
            safeInt($sr)   !== null ? safeInt($sr)   : 'NULL',
            esc($conn, safeStr(getC($row,$CM['sp_code']),50)),
            esc($conn, safeStr(getC($row,$CM['sp_name']))),
            esc($conn, safeStr(getC($row,$CM['hul_code']))),
            esc($conn, safeStr(getC($row,$CM['out_code']))),
            esc($conn, safeStr(getC($row,$CM['out_name']))),
            esc($conn, safeStr(getC($row,$CM['out_cat']))),
            esc($conn, safeStr(getC($row,$CM['bill_no']),50)),
            esc($conn, safeStr(getC($row,$CM['moc']),50)),
            $bill_date_val ? "'$bill_date_val'" : 'NULL',
            esc($conn, safeStr(getC($row,$CM['bp_code']))),
            esc($conn, safeStr(getC($row,$CM['prod_code']))),
            esc($conn, safeStr(getC($row,$CM['prod_desc']),500)),
            safeNum(getC($row,$CM['mrp']))         ?? 'NULL',
            safeNum(getC($row,$CM['tur']))         ?? 'NULL',
            safeInt(getC($row,$CM['pkd']))         !== null ? safeInt(getC($row,$CM['pkd'])) : 'NULL',
            esc($conn, safeStr(getC($row,$CM['batch_code']))),
            $exp_date_val  ? "'$exp_date_val'"  : 'NULL',
            safeInt(getC($row,$CM['units']))       !== null ? safeInt(getC($row,$CM['units'])) : 'NULL',
            safeInt(getC($row,$CM['free_qty']))    !== null ? safeInt(getC($row,$CM['free_qty'])) : 'NULL',
            safeNum(getC($row,$CM['gross_sales'])) ?? 'NULL',
            safeNum(getC($row,$CM['sch_disc']))    ?? 'NULL',
            safeNum(getC($row,$CM['rs_disc']))     ?? 'NULL',
            safeNum(getC($row,$CM['tot_disc']))    ?? 'NULL',
            safeNum(getC($row,$CM['total_disc']))  ?? 'NULL',
            safeNum(getC($row,$CM['tax_amt']))     ?? 'NULL',
            safeNum(getC($row,$CM['tot_tax']))     ?? 'NULL',
            safeNum(getC($row,$CM['tax_pct']))     ?? 'NULL',
            safeNum(getC($row,$CM['bill_val']))    ?? 'NULL',
            safeInt(getC($row,$CM['gr_qty']))      !== null ? safeInt(getC($row,$CM['gr_qty'])) : 'NULL',
            safeNum(getC($row,$CM['gr_val']))      ?? 'NULL',
            safeInt(getC($row,$CM['des_qty']))     !== null ? safeInt(getC($row,$CM['des_qty'])) : 'NULL',
            safeNum(getC($row,$CM['des_val']))     ?? 'NULL',
            safeNum(getC($row,$CM['final_amt']))   ?? 'NULL',
            safeNum(getC($row,$CM['sch_per_unit']))?? 'NULL',
            esc($conn, safeStr(getC($row,$CM['bill_cap']),20)),
            esc($conn, safeStr(getC($row,$CM['del_person']))),
            $post_date_val ? "'$post_date_val'" : 'NULL'
        );

        if (count($batch) >= $batch_size) $flush();
    }
    $flush();

    /* update total_rows */
    mysqli_query($conn, "UPDATE invoice_wise_sales_uploads SET total_rows=$inserted WHERE id=$upload_id");

    if ($inserted === 0) {
        mysqli_query($conn, "DELETE FROM invoice_wise_sales_uploads WHERE id=$upload_id");
        echo json_encode(['ok'=>false,'msg'=>"No data rows found. Skipped: $skipped rows. Check file format."]);
        exit;
    }

    echo json_encode(['ok'=>true,'msg'=>"Successfully imported $inserted rows".($skipped?" ($skipped skipped)":'').". Delivery Date: ".date('d M Y', strtotime($delivery_date))]);
    exit;
}

/* ── page stats ── */
$stats = mysqli_fetch_assoc(mysqli_query($conn,"
  SELECT COUNT(*) AS total_uploads,
         COALESCE(SUM(total_rows),0) AS total_rows,
         COUNT(DISTINCT delivery_date) AS unique_dates,
         MAX(uploaded_at) AS last_upload
  FROM invoice_wise_sales_uploads
")) ?: ['total_uploads'=>0,'total_rows'=>0,'unique_dates'=>0,'last_upload'=>null];

include 'header.php';
?>

<style>
*{box-sizing:border-box;}
.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-success{background:#15803d;color:#fff;}.btn-success:hover{background:#166534;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-sm{padding:5px 10px;font-size:12px;}

/* Summary Cards */
.sum-cards{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:22px;}
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 22px;flex:1;min-width:160px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.sum-card-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.sum-card-val{font-size:22px;font-weight:700;color:#111827;}

/* Upload card */
.upload-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:22px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.upload-card-hdr{padding:18px 24px;border-bottom:1px solid #f3f4f6;}
.upload-card-hdr h3{margin:0 0 4px;font-size:15px;color:#111827;}
.upload-card-hdr p{margin:0;font-size:12px;color:#6b7280;}
.upload-card-body{padding:24px;}

.form-group{margin-bottom:18px;}
.form-group label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px;}
.form-control{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:7px;font-size:13px;font-family:inherit;color:#111827;outline:none;transition:border .15s;}
.form-control:focus{border-color:#1e40af;box-shadow:0 0 0 3px rgba(30,64,175,.1);}

.drop-zone{border:2px dashed #d1d5db;border-radius:10px;padding:36px;text-align:center;cursor:pointer;transition:all .18s;background:#fafafa;}
.drop-zone:hover,.drop-zone.dragover{border-color:#1e40af;background:#eff6ff;}
.drop-zone.file-chosen{border-color:#15803d;background:#f0fdf4;}
.dz-icon{font-size:36px;color:#9ca3af;margin-bottom:10px;}
.dz-text{font-size:13px;font-weight:600;color:#374151;margin-bottom:4px;}
.dz-sub{font-size:11px;color:#9ca3af;}
#fileInput{display:none;}

.progress-wrap{display:none;margin:14px 0;}
.progress-bar-outer{background:#e5e7eb;border-radius:20px;height:8px;overflow:hidden;}
.progress-bar-inner{height:8px;background:linear-gradient(90deg,#1e40af,#3b82f6);border-radius:20px;width:0%;transition:width .3s;}
.progress-label{font-size:11px;color:#6b7280;margin-top:6px;}

.result-box{display:none;padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-top:14px;}
.result-ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.result-err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}

/* Table card */
.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table th{padding:10px 12px;text-align:left;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:11px;text-transform:uppercase;white-space:nowrap;}
.data-table td{padding:9px 12px;border-bottom:1px solid #f3f4f6;color:#111827;}
.data-table tr:hover td{background:#f9fafb;}
.tr{text-align:right!important;}
.tc{text-align:center!important;}

.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.badge-blue{background:#dbeafe;color:#1e40af;}
.badge-green{background:#dcfce7;color:#15803d;}
.badge-orange{background:#ffedd5;color:#c2410c;}

/* Toast */
#toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;display:none;opacity:0;z-index:9999;transition:opacity .3s;max-width:380px;box-shadow:0 4px 20px rgba(0,0,0,.15);}
.toast-ok{background:#166534;color:#fff;}
.toast-err{background:#991b1b;color:#fff;}
</style>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-truck" style="color:#1e40af;margin-right:8px;"></i>Invoice Wise Sales — Import
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Upload Invoice Wise Sales Excel reports. Filter by Delivery Date.</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="invoice_wise_sales_view.php" class="btn btn-primary"><i class="fa-solid fa-table"></i> View Data</a>
    <a href="invoice_wise_sales_history.php" class="btn btn-secondary"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
  </div>
</div>

<!-- SUMMARY CARDS -->
<div class="sum-cards">
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-upload" style="margin-right:4px;"></i>Total Uploads</div>
    <div class="sum-card-val"><?= number_format($stats['total_uploads']) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-list-ol" style="margin-right:4px;"></i>Total Rows</div>
    <div class="sum-card-val"><?= number_format($stats['total_rows']) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-calendar-days" style="margin-right:4px;"></i>Delivery Dates</div>
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
    <p>Select the Delivery Date, choose your Invoice Wise Sales .xlsx file and click Upload.</p>
  </div>
  <div class="upload-card-body">
    <div class="form-group">
      <label><i class="fa-solid fa-truck-fast" style="margin-right:5px;"></i>Delivery Date <span style="color:#dc2626;">*</span></label>
      <input type="date" id="deliveryDate" class="form-control" value="<?= date('Y-m-d') ?>">
      <div style="font-size:11px;color:#6b7280;margin-top:4px;">This date will be stored with every row of data from this file.</div>
    </div>
    <div class="form-group">
      <label><i class="fa-solid fa-file-excel" style="margin-right:5px;"></i>Excel File (.xlsx / .xls) <span style="color:#dc2626;">*</span></label>
      <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
        <div class="dz-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
        <div class="dz-text" id="dzText">Drop file here or click to browse</div>
        <div class="dz-sub" id="dzSub">Supports: .xlsx, .xls &nbsp;·&nbsp; Invoice Wise Sales Report format</div>
      </div>
      <input type="file" id="fileInput" accept=".xlsx,.xls">
    </div>
    <div class="form-group">
      <label><i class="fa-solid fa-note-sticky" style="margin-right:5px;"></i>Note (optional)</label>
      <input type="text" id="uploadNote" class="form-control" placeholder="e.g. Week 16, April batch…">
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
    <a href="invoice_wise_sales_history.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-list"></i> View All</a>
  </div>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Delivery Date</th>
        <th>Filename</th>
        <th class="tr">Rows</th>
        <th>Note</th>
        <th>Uploaded At</th>
        <th class="tc">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $recent = mysqli_query($conn,"SELECT * FROM invoice_wise_sales_uploads ORDER BY uploaded_at DESC LIMIT 10");
    $i = 1;
    while ($r = mysqli_fetch_assoc($recent)):
    ?>
      <tr>
        <td style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
        <td><span class="badge badge-blue"><?= date('d M Y', strtotime($r['delivery_date'])) ?></span></td>
        <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11.5px;" title="<?= htmlspecialchars($r['filename']) ?>"><?= htmlspecialchars($r['filename']) ?></td>
        <td class="tr"><span class="badge badge-green"><?= number_format($r['total_rows']) ?></span></td>
        <td style="font-size:11.5px;color:#6b7280;"><?= htmlspecialchars($r['note'] ?: '—') ?></td>
        <td style="font-size:11.5px;"><?= date('d M Y H:i', strtotime($r['uploaded_at'])) ?></td>
        <td class="tc" style="white-space:nowrap;">
          <a href="invoice_wise_sales_view.php?upload_id=<?= $r['id'] ?>" class="btn btn-primary btn-sm" title="View Data"><i class="fa-solid fa-eye"></i></a>
          <a href="invoice_wise_sales_history.php?delete=<?= $r['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this upload and all its data?')" title="Delete"><i class="fa-solid fa-trash"></i></a>
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
const dropZone  = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
let selectedFile = null;

dropZone.addEventListener('dragover', e=>{e.preventDefault();dropZone.classList.add('dragover');});
dropZone.addEventListener('dragleave',()=>dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', e=>{
  e.preventDefault(); dropZone.classList.remove('dragover');
  const f = e.dataTransfer.files[0];
  if (f) setFile(f);
});
fileInput.addEventListener('change',()=>{ if(fileInput.files[0]) setFile(fileInput.files[0]); });

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
  const date = document.getElementById('deliveryDate').value;
  if (!date)         { showToast('Please select a Delivery Date.','err'); return; }
  if (!selectedFile) { showToast('Please choose an Excel file.','err'); return; }

  const fd = new FormData();
  fd.append('action','upload');
  fd.append('delivery_date', date);
  fd.append('note', document.getElementById('uploadNote').value);
  fd.append('xlsx_file', selectedFile);

  document.getElementById('progressWrap').style.display = 'block';
  document.getElementById('uploadBtn').disabled = true;
  document.getElementById('uploadBtn').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';

  const xhr = new XMLHttpRequest();
  xhr.open('POST','invoice_wise_sales_upload.php');

  xhr.upload.onprogress = e => {
    if (e.lengthComputable) {
      const pct = Math.round(e.loaded/e.total*100);
      document.getElementById('progressBar').style.width   = pct+'%';
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
        document.getElementById('progressBar').style.width = '100%';
        showToast(res.msg,'ok');
        setTimeout(()=>location.reload(), 1800);
      } else {
        box.className = 'result-box result-err';
        box.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> '+res.msg;
        showToast(res.msg,'err');
      }
    } catch(e) {
      document.getElementById('resultBox').className = 'result-box result-err';
      document.getElementById('resultBox').innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Unexpected server response.';
      document.getElementById('resultBox').style.display = 'block';
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

function showToast(msg,type) {
  const t = document.getElementById('toast');
  t.className = type==='ok' ? 'toast-ok' : 'toast-err';
  t.textContent = msg; t.style.display='block'; t.style.opacity='1';
  clearTimeout(t._t);
  t._t = setTimeout(()=>{t.style.opacity='0';setTimeout(()=>t.style.display='none',300);},3200);
}
</script>

<?php include 'footer.php'; ?>