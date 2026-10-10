<?php
include 'config.php';

/* ── ensure tables exist ── */
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS `outlet_service_info_uploads` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `filename`      VARCHAR(255) NOT NULL,
  `total_rows`    INT(11)      NOT NULL DEFAULT 0,
  `split_rows`    INT(11)      NOT NULL DEFAULT 0,
  `uploaded_by`   VARCHAR(100) DEFAULT NULL,
  `uploaded_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note`          TEXT         DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS `outlet_service_info_data` (
  `id`                            INT(11)        NOT NULL AUTO_INCREMENT,
  `upload_id`                     INT(11)        NOT NULL,
  `source_row_no`                 INT(11)        DEFAULT NULL,
  `sr_no`                         INT(11)        DEFAULT NULL,
  `rs_code`                       VARCHAR(50)    DEFAULT NULL,
  `rs_name`                       VARCHAR(255)   DEFAULT NULL,
  `rssp_code`                     VARCHAR(50)    DEFAULT NULL,
  `rssp_name`                     VARCHAR(255)   DEFAULT NULL,
  `original_rssp_code`            VARCHAR(100)   DEFAULT NULL,
  `original_rssp_name`            VARCHAR(500)   DEFAULT NULL,
  `split_group_no`                INT(11)        NOT NULL DEFAULT 1,
  `split_group_total`             INT(11)        NOT NULL DEFAULT 1,
  `new_beat_name`                 VARCHAR(255)   DEFAULT NULL,
  `existing_visit_frequency`      VARCHAR(50)    DEFAULT NULL,
  `outlet_hul_code`                VARCHAR(100)   DEFAULT NULL,
  `party_name`                    VARCHAR(255)   DEFAULT NULL,
  `address`                       VARCHAR(500)   DEFAULT NULL,
  `channel`                       VARCHAR(150)   DEFAULT NULL,
  `category`                      VARCHAR(100)   DEFAULT NULL,
  `servicing_day`                 VARCHAR(50)    DEFAULT NULL,
  `avg_sale_monthly`              DECIMAL(18,4)  DEFAULT NULL,
  `contri_pct`                    DECIMAL(12,8)  DEFAULT NULL,
  `outlet_rank`                   INT(11)        DEFAULT NULL,
  `contribution_80pct`            DECIMAL(8,4)   DEFAULT NULL,
  `avg_lppc_monthly`              DECIMAL(15,4)  DEFAULT NULL,
  `avg_asmt_monthly`              DECIMAL(15,4)  DEFAULT NULL,
  `avg_productive_calls_monthly`  DECIMAL(15,4)  DEFAULT NULL,
  `new_active_inactive_status`    VARCHAR(50)    DEFAULT NULL,
  `new_beat`                      VARCHAR(255)   DEFAULT NULL,
  `new_visit_frequency`           VARCHAR(50)    DEFAULT NULL,
  `new_servicing_day`             VARCHAR(100)   DEFAULT NULL,
  `split_out`                     VARCHAR(100)   DEFAULT NULL,
  `new_rssp_code`                 VARCHAR(50)    DEFAULT NULL,
  `new_rssp_name`                 VARCHAR(255)   DEFAULT NULL,
  `original_new_rssp_code`        VARCHAR(100)   DEFAULT NULL,
  `original_new_rssp_name`        VARCHAR(500)   DEFAULT NULL,
  `outlet_latitude`               DECIMAL(10,6)  DEFAULT NULL,
  `outlet_longitude`              DECIMAL(10,6)  DEFAULT NULL,
  `source_format`                 VARCHAR(20)    DEFAULT NULL,
  `created_at`                    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_upload_id`  (`upload_id`),
  KEY `idx_rssp_code`  (`rssp_code`),
  KEY `idx_rs_code`    (`rs_code`),
  KEY `idx_outlet`     (`outlet_hul_code`),
  CONSTRAINT `fk_osi_upload` FOREIGN KEY (`upload_id`)
    REFERENCES `outlet_service_info_uploads`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* safe migration: add source_format column if this table pre-dates this version */
mysqli_report(MYSQLI_REPORT_OFF);
$colcheck = mysqli_query($conn, "SHOW COLUMNS FROM outlet_service_info_data LIKE 'source_format'");
if ($colcheck && mysqli_num_rows($colcheck) === 0) {
    mysqli_query($conn, "ALTER TABLE outlet_service_info_data ADD COLUMN `source_format` VARCHAR(20) DEFAULT NULL AFTER `outlet_longitude`");
}

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
function safeStr($v, $max = 255) {
    if ($v === null) return null;
    $s = trim((string)$v);
    return $s === '' ? null : mb_substr($s, 0, $max);
}
function esc($conn, $v) {
    return $v === null ? 'NULL' : "'".mysqli_real_escape_string($conn, $v)."'";
}

/**
 * Expand a "combined" RSSP code like "SMN00002/003" into ["SMN00002","SMN00003"].
 * Rule: the first slash-part is the full base code (letters + digits).
 * Every following slash-part is a short numeric suffix that replaces the
 * rightmost digits of the base code's numeric part, keeping the same
 * total digit length and the same letter prefix.
 *   "SMN00002/003"       -> ["SMN00002", "SMN00003"]
 *   "SMN00016/009"       -> ["SMN00016", "SMN00009"]
 *   "SMN00002/003/004"   -> ["SMN00002", "SMN00003", "SMN00004"]
 * If a slash-part doesn't look like a pure numeric suffix (e.g. it's
 * already a full alpha+numeric code), it is used as-is.
 * Only relevant for the RSSP-based "Outlet Service Info" format — files
 * without an RSSP Code column (e.g. per-day route files) simply won't
 * have anything to expand and each row imports as a single row.
 */
function expandCombinedCode($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return [];
    if (strpos($raw, '/') === false) return [$raw];

    $parts = array_map('trim', explode('/', $raw));
    $parts = array_filter($parts, fn($p) => $p !== '');
    $parts = array_values($parts);
    if (!$parts) return [];

    $base = $parts[0];
    $out  = [$base];

    if (preg_match('/^([A-Za-z]*)(\d+)$/', $base, $m)) {
        $prefix = $m[1];
        $digits = $m[2];
        $len    = strlen($digits);

        for ($i = 1; $i < count($parts); $i++) {
            $suffix = $parts[$i];
            if (preg_match('/^\d+$/', $suffix)) {
                if (strlen($suffix) >= $len) {
                    $newDigits = str_pad($suffix, $len, '0', STR_PAD_LEFT);
                } else {
                    $newDigits = substr($digits, 0, $len - strlen($suffix)) . $suffix;
                }
                $out[] = $prefix . $newDigits;
            } else {
                // not a pure numeric suffix — treat as its own full code
                $out[] = $suffix;
            }
        }
    } else {
        // base code doesn't match letters+digits pattern; just append the rest verbatim
        for ($i = 1; $i < count($parts); $i++) $out[] = $parts[$i];
    }

    return $out;
}

/**
 * Expand a "combined" name like
 * "B.D.K.P.CHANAKA PUSHPAKUMARA/H. K. NUWAN WARNAKULA SENADHEE"
 * into ["B.D.K.P.CHANAKA PUSHPAKUMARA","H. K. NUWAN WARNAKULA SENADHEE"].
 */
function expandCombinedName($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return [];
    if (strpos($raw, '/') === false) return [$raw];
    $parts = array_map('trim', explode('/', $raw));
    $parts = array_filter($parts, fn($p) => $p !== '');
    return array_values($parts);
}

/* ── delete an upload (and its data rows via ON DELETE CASCADE) ── */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    if ($del_id > 0) {
        mysqli_query($conn, "DELETE FROM outlet_service_info_uploads WHERE id=$del_id");
    }
    header('Location: '.basename(__FILE__));
    exit;
}

/* ── AJAX upload handler ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    header('Content-Type: application/json');

    $note = trim($_POST['note'] ?? '');

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

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        echo json_encode(['ok'=>false,'msg'=>'Cannot open xlsx file. Make sure it is a valid .xlsx file.']);
        exit;
    }

    /*
     * Locate the target sheet. Prefer a sheet literally named
     * "Outlet Service Info" (the RTM/Locus workbook format). If no such
     * sheet exists — e.g. a simpler per-day route file like "Monday.xlsx"
     * that only has one sheet named after the weekday — fall back to the
     * first sheet in the workbook.
     */
    $sheet_names_xml = $zip->getFromName('xl/workbook.xml');
    $target_sheet     = 'xl/worksheets/sheet1.xml';
    if ($sheet_names_xml) {
        libxml_use_internal_errors(true);
        $wb = simplexml_load_string($sheet_names_xml);
        if ($wb) {
            foreach ($wb->sheets->sheet as $sh) {
                $attr = $sh->attributes();
                if (stripos((string)$attr['name'], 'Outlet Service Info') !== false) {
                    $ns   = $sh->attributes('r', true);
                    $rId  = (string)$ns['id'];
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
        echo json_encode(['ok'=>false,'msg'=>'Cannot read a usable data sheet from this file.']);
        exit;
    }

    $shared = [];
    if ($shared_xml) {
        libxml_use_internal_errors(true);
        $sxml = simplexml_load_string($shared_xml);
        if ($sxml) {
            foreach ($sxml->si as $si) {
                $t = '';
                if (isset($si->t))     { $t = (string)$si->t; }
                elseif (isset($si->r)) { foreach ($si->r as $r) { if (isset($r->t)) $t .= (string)$r->t; } }
                $shared[] = $t;
            }
        }
    }

    $date_format_ids = [];
    if ($styles_xml) {
        libxml_use_internal_errors(true);
        $stxml = simplexml_load_string($styles_xml);
        if ($stxml && isset($stxml->cellXfs)) {
            $built_in_date   = [14,15,16,17,18,19,20,21,22,45,46,47];
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

    $col_index = function($letters) {
        $letters = strtoupper($letters);
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++)
            $index = $index * 26 + (ord($letters[$i]) - 64);
        return $index - 1;
    };

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
            $ref = (string)$cell['r'];
            preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
            $cIdx  = $col_index($m[1]);
            $t     = (string)$cell['t'];
            $s_idx = isset($cell['s']) ? (int)$cell['s'] : -1;
            $raw   = isset($cell->v) ? (string)$cell->v : '';

            if ($t === 'inlineStr') {
                $val = '';
                if (isset($cell->is->t)) { $val = (string)$cell->is->t; }
                elseif (isset($cell->is->r)) { foreach ($cell->is->r as $r) { if (isset($r->t)) $val .= (string)$r->t; } }
            } elseif ($t === 's') {
                $val = isset($shared[(int)$raw]) ? $shared[(int)$raw] : '';
            } elseif ($t === 'b') {
                $val = $raw ? 'TRUE' : 'FALSE';
            } elseif (in_array($s_idx, $date_format_ids) && is_numeric($raw)) {
                $unix = ((float)$raw - 25569) * 86400;
                $val  = $unix > 0 ? date('Y-m-d', (int)$unix) : '';
            } else {
                $val = $raw;
            }
            $matrix[$rnum][$cIdx] = $val;
        }
    }

    /*
     * Find the header row. Two supported layouts:
     *   1. RTM/Locus "Outlet Service Info" sheet — has "RSSP Code" + "Party Name"
     *   2. Simple per-day route file (e.g. Monday.xlsx) — has "Outlet HUL Code"
     *      + "Party Name" but no RSSP columns at all
     * Either signature is accepted as long as "Party Name" is present.
     */
    $header_row = null;
    $col_map    = [];
    foreach ($matrix as $rnum => $row) {
        $lower = array_map(fn($v) => strtolower(trim((string)$v)), $row);
        $has_party = in_array('party name', $lower, true);
        $has_rssp  = in_array('rssp code', $lower, true);
        $has_hul   = in_array('outlet hul code', $lower, true);
        if ($has_party && ($has_rssp || $has_hul)) {
            $header_row = $rnum;
            foreach ($row as $idx => $hdr) {
                $col_map[strtolower(trim((string)$hdr))] = $idx;
            }
            break;
        }
    }

    if ($header_row === null) {
        echo json_encode(['ok'=>false,'msg'=>'Could not find header row. Expected at least "Party Name" plus either "RSSP Code" or "Outlet HUL Code" as column headers.']);
        exit;
    }

    /* which format did we detect? drives the split-group logic and the summary message */
    $is_rssp_format = isset($col_map['rssp code']);
    $source_format  = $is_rssp_format ? 'rssp' : 'simple';

    $CM = [
        'sr_no'       => $col_map['sr no']                          ?? null,
        'rs_code'     => $col_map['rs code']                        ?? null,
        'rs_name'     => $col_map['rs name']                        ?? null,
        'rssp_code'   => $col_map['rssp code']                      ?? null,
        'rssp_name'   => $col_map['rssp name']                      ?? null,
        'new_beat_nm' => $col_map['new beat name']                  ?? null,
        'exist_freq'  => $col_map['existing visit frequency']       ?? null,
        'hul_code'    => $col_map['outlet hul code']                ?? null,
        'party_name'  => $col_map['party name']                     ?? null,
        'address'     => $col_map['address']                        ?? null,
        'channel'     => $col_map['channel']                        ?? null,
        'category'    => $col_map['category']                       ?? null,
        'serv_day'    => $col_map['servicing day']                  ?? null,
        'avg_sale'    => $col_map['avg sale(monthly)']               ?? null,
        'contri_pct'  => $col_map['contri %']                       ?? null,
        'out_rank'    => $col_map['outlet rank']                    ?? null,
        'contrib80'   => $col_map['80% contribution']                ?? null,
        'avg_lppc'    => $col_map['avg lppc(monthly)']               ?? null,
        'avg_asmt'    => $col_map['avg asmt(monthly)']               ?? null,
        'avg_calls'   => $col_map['avg productive calls(monthly)']  ?? null,
        'new_status'  => $col_map['new active inactive status']    ?? null,
        'new_beat'    => $col_map['new beat']                       ?? null,
        'new_freq'    => $col_map['new visit frequency']            ?? null,
        'new_serv_day'=> $col_map['new servicing day']               ?? null,
        'split_out'   => $col_map['split out']                      ?? null,
        'new_rssp'    => $col_map['new rssp code']                  ?? null,
        'new_rssp_nm' => $col_map['new rssp name']                  ?? null,
        'lat'         => $col_map['outlet latitude']                ?? null,
        'lng'         => $col_map['outlet longitude']                ?? null,
    ];

    function getC($row, $idx) {
        if ($idx === null) return null;
        return isset($row[$idx]) ? $row[$idx] : null;
    }

    $fname = mysqli_real_escape_string($conn, $file['name']);
    $dnote = mysqli_real_escape_string($conn, $note);
    mysqli_query($conn, "INSERT INTO outlet_service_info_uploads (filename,total_rows,split_rows,note) VALUES ('$fname',0,0,'$dnote')");
    $upload_id = mysqli_insert_id($conn);

    if (!$upload_id) {
        echo json_encode(['ok'=>false,'msg'=>'Failed to create upload record.']);
        exit;
    }

    $batch        = [];
    $batch_size   = 200;
    $source_rows  = 0;
    $inserted     = 0;

    $flush = function() use (&$batch, &$inserted, $conn, $upload_id) {
        if (!$batch) return;
        $vals = implode(',', $batch);
        mysqli_query($conn, "INSERT INTO outlet_service_info_data
          (upload_id,source_row_no,sr_no,rs_code,rs_name,rssp_code,rssp_name,
           original_rssp_code,original_rssp_name,split_group_no,split_group_total,
           new_beat_name,existing_visit_frequency,outlet_hul_code,party_name,address,
           channel,category,servicing_day,avg_sale_monthly,contri_pct,outlet_rank,
           contribution_80pct,avg_lppc_monthly,avg_asmt_monthly,avg_productive_calls_monthly,
           new_active_inactive_status,new_beat,new_visit_frequency,new_servicing_day,split_out,
           new_rssp_code,new_rssp_name,original_new_rssp_code,original_new_rssp_name,
           outlet_latitude,outlet_longitude,source_format)
          VALUES $vals");
        $inserted += count($batch);
        $batch = [];
    };

    foreach ($matrix as $rnum => $row) {
        if ($rnum <= $header_row) continue;

        $rssp_code_raw = getC($row, $CM['rssp_code']);
        $party_name    = getC($row, $CM['party_name']);
        $hul_code      = getC($row, $CM['hul_code']);
        if ((!$rssp_code_raw || trim((string)$rssp_code_raw) === '') &&
            (!$party_name    || trim((string)$party_name)    === '') &&
            (!$hul_code      || trim((string)$hul_code)      === '')) { continue; }

        $source_rows++;

        /* splitting is driven by which columns actually exist in this file,
           not by the overall detected format — a simple route file can still
           carry a "New RSSP Code" column that needs the same split logic */
        $has_rssp_col     = $CM['rssp_code'] !== null;
        $has_new_rssp_col = $CM['new_rssp']  !== null;

        $rssp_codes = $has_rssp_col ? expandCombinedCode($rssp_code_raw) : [];
        if (!$rssp_codes) $rssp_codes = [null];
        $rssp_names = $has_rssp_col ? expandCombinedName(getC($row, $CM['rssp_name'])) : [];

        $new_rssp_raw    = getC($row, $CM['new_rssp']);
        $new_rssp_nm_raw = getC($row, $CM['new_rssp_nm']);
        $new_rssp_codes  = $has_new_rssp_col ? expandCombinedCode($new_rssp_raw) : [];
        $new_rssp_names  = $has_new_rssp_col ? expandCombinedName($new_rssp_nm_raw) : [];

        $groupTotal = max(1, count($rssp_codes), count($new_rssp_codes));

        for ($gi = 0; $gi < $groupTotal; $gi++) {
            $rssp_code   = $rssp_codes[$gi] ?? end($rssp_codes) ?: null;
            $rssp_name   = $rssp_names[$gi] ?? (end($rssp_names) ?: null);
            $new_rssp    = $new_rssp_codes ? ($new_rssp_codes[$gi] ?? end($new_rssp_codes)) : null;
            $new_rssp_nm = $new_rssp_names ? ($new_rssp_names[$gi] ?? end($new_rssp_names)) : null;

            $batch[] = sprintf("(%d,%d,%s,%s,%s,%s,%s,%s,%s,%d,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                $upload_id,
                $rnum,
                safeInt(getC($row,$CM['sr_no'])) !== null ? safeInt(getC($row,$CM['sr_no'])) : 'NULL',
                esc($conn, safeStr(getC($row,$CM['rs_code']),50)),
                esc($conn, safeStr(getC($row,$CM['rs_name']))),
                esc($conn, safeStr($rssp_code,50)),
                esc($conn, safeStr($rssp_name)),
                esc($conn, safeStr($rssp_code_raw,100)),
                esc($conn, safeStr(getC($row,$CM['rssp_name']),500)),
                $gi + 1,
                $groupTotal,
                esc($conn, safeStr(getC($row,$CM['new_beat_nm']))),
                esc($conn, safeStr(getC($row,$CM['exist_freq']),50)),
                esc($conn, safeStr($hul_code)),
                esc($conn, safeStr($party_name)),
                esc($conn, safeStr(getC($row,$CM['address']),500)),
                esc($conn, safeStr(getC($row,$CM['channel']),150)),
                esc($conn, safeStr(getC($row,$CM['category']))),
                esc($conn, safeStr(getC($row,$CM['serv_day']),50)),
                safeNum(getC($row,$CM['avg_sale']))   ?? 'NULL',
                safeNum(getC($row,$CM['contri_pct'])) ?? 'NULL',
                safeInt(getC($row,$CM['out_rank']))   !== null ? safeInt(getC($row,$CM['out_rank'])) : 'NULL',
                safeNum(getC($row,$CM['contrib80']))  ?? 'NULL',
                safeNum(getC($row,$CM['avg_lppc']))   ?? 'NULL',
                safeNum(getC($row,$CM['avg_asmt']))   ?? 'NULL',
                safeNum(getC($row,$CM['avg_calls']))  ?? 'NULL',
                esc($conn, safeStr(getC($row,$CM['new_status']),50)),
                esc($conn, safeStr(getC($row,$CM['new_beat']))),
                esc($conn, safeStr(getC($row,$CM['new_freq']),50)),
                esc($conn, safeStr(getC($row,$CM['new_serv_day']),100)),
                esc($conn, safeStr(getC($row,$CM['split_out']),100)),
                esc($conn, safeStr($new_rssp,50)),
                esc($conn, safeStr($new_rssp_nm)),
                esc($conn, safeStr($new_rssp_raw,100)),
                esc($conn, safeStr($new_rssp_nm_raw,500)),
                safeNum(getC($row,$CM['lat'])) ?? 'NULL',
                safeNum(getC($row,$CM['lng'])) ?? 'NULL',
                esc($conn, $source_format)
            );

            if (count($batch) >= $batch_size) $flush();
        }
    }
    $flush();

    $split_rows = $inserted - $source_rows;
    mysqli_query($conn, "UPDATE outlet_service_info_uploads SET total_rows=$source_rows, split_rows=".max(0,$split_rows)." WHERE id=$upload_id");

    if ($inserted === 0) {
        mysqli_query($conn, "DELETE FROM outlet_service_info_uploads WHERE id=$upload_id");
        echo json_encode(['ok'=>false,'msg'=>"No data rows found in the sheet."]);
        exit;
    }

    if ($is_rssp_format) {
        $extraMsg = $split_rows > 0 ? " ($split_rows extra rows created from split RSSP codes)" : '';
        $msg = "Successfully imported $source_rows outlet rows as $inserted database rows".$extraMsg.'.';
    } else {
        $msg = "Successfully imported $source_rows outlet rows (simple route-file format, no RSSP splitting).";
    }

    echo json_encode(['ok'=>true,'msg'=>$msg]);
    exit;
}

/* ── page stats ── */
$stats = mysqli_fetch_assoc(mysqli_query($conn,"
  SELECT COUNT(*) AS total_uploads,
         COALESCE(SUM(total_rows),0) AS total_rows,
         COALESCE(SUM(split_rows),0) AS split_rows,
         MAX(uploaded_at) AS last_upload
  FROM outlet_service_info_uploads
")) ?: ['total_uploads'=>0,'total_rows'=>0,'split_rows'=>0,'last_upload'=>null];

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
.btn-teal{background:#0f766e;color:#fff;}.btn-teal:hover{background:#115e59;}
.btn-sm{padding:5px 10px;font-size:12px;}

.osi-label{display:inline-flex;align-items:center;gap:5px;background:#f0fdfa;border:1px solid #99f6e4;color:#0f766e;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:700;margin-left:8px;vertical-align:middle;}

.sum-cards{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:22px;}
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 22px;flex:1;min-width:160px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.sum-card-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.sum-card-val{font-size:22px;font-weight:700;color:#111827;}
.sum-card-accent{border-top:3px solid #0f766e;}

.upload-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:22px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.upload-card-hdr{padding:18px 24px;border-bottom:1px solid #f3f4f6;background:linear-gradient(135deg,#f0fdfa 0%,#fff 100%);border-radius:12px 12px 0 0;}
.upload-card-hdr h3{margin:0 0 4px;font-size:15px;color:#111827;}
.upload-card-hdr p{margin:0;font-size:12px;color:#6b7280;}
.upload-card-body{padding:24px;}

.form-group{margin-bottom:18px;}
.form-group label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px;}
.form-control{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:7px;font-size:13px;font-family:inherit;color:#111827;outline:none;transition:border .15s;}
.form-control:focus{border-color:#0f766e;box-shadow:0 0 0 3px rgba(15,118,110,.1);}

.drop-zone{border:2px dashed #d1d5db;border-radius:10px;padding:36px;text-align:center;cursor:pointer;transition:all .18s;background:#fafafa;}
.drop-zone:hover,.drop-zone.dragover{border-color:#0f766e;background:#f0fdfa;}
.drop-zone.file-chosen{border-color:#15803d;background:#f0fdf4;}
.dz-icon{font-size:36px;color:#9ca3af;margin-bottom:10px;}
.dz-text{font-size:13px;font-weight:600;color:#374151;margin-bottom:4px;}
.dz-sub{font-size:11px;color:#9ca3af;}
#fileInput{display:none;}

.info-box{background:#f0fdfa;border:1px solid #99f6e4;border-radius:8px;padding:12px 16px;font-size:12px;color:#115e59;margin-bottom:18px;line-height:1.6;}
.info-box b{color:#0f766e;}

.progress-wrap{display:none;margin:14px 0;}
.progress-bar-outer{background:#e5e7eb;border-radius:20px;height:8px;overflow:hidden;}
.progress-bar-inner{height:8px;background:linear-gradient(90deg,#0f766e,#2dd4bf);border-radius:20px;width:0%;transition:width .3s;}
.progress-label{font-size:11px;color:#6b7280;margin-top:6px;}

.result-box{display:none;padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-top:14px;}
.result-ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.result-err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}

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
.badge-teal{background:#ccfbf1;color:#0f766e;}
.badge-green{background:#dcfce7;color:#15803d;}
.badge-amber{background:#fef3c7;color:#92400e;}

.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:16px;flex-wrap:wrap;}
.breadcrumb a{color:#0f766e;text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}

#toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;display:none;opacity:0;z-index:9999;transition:opacity .3s;max-width:380px;box-shadow:0 4px 20px rgba(0,0,0,.15);}
.toast-ok{background:#166534;color:#fff;}
.toast-err{background:#991b1b;color:#fff;}
</style>

<div class="breadcrumb">
  <a href="index.php"><i class="fa-solid fa-house"></i> Home</a>
  <span class="sep">›</span>
  <span style="color:#0f766e;font-weight:700;"><i class="fa-solid fa-route"></i> Outlet Service Info</span>
</div>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-route" style="color:#0f766e;margin-right:8px;"></i>Outlet Service Info — Import
      <span class="osi-label"><i class="fa-solid fa-circle-check"></i> RTM PLAN</span>
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Upload the "Outlet Service Info" sheet from the RTM & Locus Route Plan workbook, or a simple per-day route file (e.g. Monday/Tuesday). Split RSSP codes are automatically expanded into separate rows when present.</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="outlet_service_info_view.php" class="btn btn-teal"><i class="fa-solid fa-table"></i> View Data</a>
  </div>
</div>

<div class="sum-cards">
  <div class="sum-card sum-card-accent">
    <div class="sum-card-label"><i class="fa-solid fa-upload" style="margin-right:4px;color:#0f766e;"></i>Total Uploads</div>
    <div class="sum-card-val" style="color:#0f766e;"><?= number_format($stats['total_uploads']) ?></div>
  </div>
  <div class="sum-card sum-card-accent">
    <div class="sum-card-label"><i class="fa-solid fa-shop" style="margin-right:4px;color:#0f766e;"></i>Outlet Rows</div>
    <div class="sum-card-val" style="color:#0f766e;"><?= number_format($stats['total_rows']) ?></div>
  </div>
  <div class="sum-card sum-card-accent">
    <div class="sum-card-label"><i class="fa-solid fa-code-branch" style="margin-right:4px;color:#0f766e;"></i>Split Rows Created</div>
    <div class="sum-card-val" style="color:#0f766e;"><?= number_format($stats['split_rows']) ?></div>
  </div>
  <div class="sum-card sum-card-accent">
    <div class="sum-card-label"><i class="fa-solid fa-clock" style="margin-right:4px;color:#0f766e;"></i>Last Upload</div>
    <div class="sum-card-val" style="font-size:13px;padding-top:5px;">
      <?= $stats['last_upload'] ? date('d M Y H:i', strtotime($stats['last_upload'])) : '—' ?>
    </div>
  </div>
</div>

<div class="upload-card">
  <div class="upload-card-hdr">
    <h3><i class="fa-solid fa-file-arrow-up" style="margin-right:8px;color:#0f766e;"></i>Upload New Outlet Service Info File</h3>
    <p>Choose either the RTM Plan .xlsx file (sheet named "Outlet Service Info") or a simple per-day route file, and click Upload. Data is saved to <strong>outlet_service_info_data</strong>.</p>
  </div>
  <div class="upload-card-body">

    <div class="info-box">
      <b><i class="fa-solid fa-circle-info"></i> Two supported formats:</b><br>
      1) <b>RTM/Locus format</b> — sheet named "Outlet Service Info" with an <b>RSSP Code</b> column. A code like
      <b>SMN00002/003</b> is split into 2 rows (<b>SMN00002</b> and <b>SMN00003</b>), each keeping its own matching
      RSSP Name and all other outlet details duplicated. <b>SMN00016/009/010</b> would create 3 rows the same way.<br>
      2) <b>Simple route format</b> (e.g. Monday.xlsx) — single sheet, header on row 1, columns like
      <b>Outlet HUL Code, Party Name, Address, Channel, AVG Sale(Monthly)…</b> with no RSSP Code column. These
      import one row per outlet, no splitting.
    </div>

    <div class="form-group">
      <label><i class="fa-solid fa-file-excel" style="margin-right:5px;"></i>Excel File (.xlsx / .xls) <span style="color:#dc2626;">*</span></label>
      <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
        <div class="dz-icon"><i class="fa-solid fa-cloud-arrow-up" style="color:#0f766e;"></i></div>
        <div class="dz-text" id="dzText">Drop file here or click to browse</div>
        <div class="dz-sub" id="dzSub">Supports: .xlsx, .xls &nbsp;·&nbsp; RTM "Outlet Service Info" sheet or simple day-route file</div>
      </div>
      <input type="file" id="fileInput" accept=".xlsx,.xls">
    </div>
    <div class="form-group">
      <label><i class="fa-solid fa-note-sticky" style="margin-right:5px;"></i>Note (optional)</label>
      <input type="text" id="uploadNote" class="form-control" placeholder="e.g. RTM Plan 2026 — v1, or Monday Route…">
    </div>

    <div class="progress-wrap" id="progressWrap">
      <div class="progress-bar-outer"><div class="progress-bar-inner" id="progressBar"></div></div>
      <div class="progress-label" id="progressLabel">Uploading…</div>
    </div>
    <div class="result-box" id="resultBox"></div>

    <button class="btn btn-teal" id="uploadBtn" onclick="doUpload()" style="width:100%;margin-top:6px;height:42px;font-size:14px;">
      <i class="fa-solid fa-database"></i> Upload to Database
    </button>
  </div>
</div>

<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title"><i class="fa-solid fa-clock-rotate-left" style="margin-right:6px;color:#0f766e;"></i>Recent Uploads (last 10)</div>
  </div>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Filename</th>
        <th class="tr">Outlet Rows</th>
        <th class="tr">Split Rows</th>
        <th>Note</th>
        <th>Uploaded At</th>
        <th class="tc">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $recent = mysqli_query($conn,"SELECT * FROM outlet_service_info_uploads ORDER BY uploaded_at DESC LIMIT 10");
    $i = 1;
    while ($r = mysqli_fetch_assoc($recent)):
    ?>
      <tr>
        <td style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
        <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11.5px;" title="<?= htmlspecialchars($r['filename']) ?>"><?= htmlspecialchars($r['filename']) ?></td>
        <td class="tr"><span class="badge badge-teal"><?= number_format($r['total_rows']) ?></span></td>
        <td class="tr"><span class="badge badge-amber"><?= number_format($r['split_rows']) ?></span></td>
        <td style="font-size:11.5px;color:#6b7280;"><?= htmlspecialchars($r['note'] ?: '—') ?></td>
        <td style="font-size:11.5px;"><?= date('d M Y H:i', strtotime($r['uploaded_at'])) ?></td>
        <td class="tc" style="white-space:nowrap;">
          <a href="outlet_service_info_view.php?upload_id=<?= $r['id'] ?>" class="btn btn-teal btn-sm" title="View Data"><i class="fa-solid fa-eye"></i></a>
          <a href="?delete=<?= $r['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this upload and all its data?')" title="Delete"><i class="fa-solid fa-trash"></i></a>
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
  if (!selectedFile) { showToast('Please choose an Excel file.','err'); return; }

  const fd = new FormData();
  fd.append('action','upload');
  fd.append('note', document.getElementById('uploadNote').value);
  fd.append('xlsx_file', selectedFile);

  document.getElementById('progressWrap').style.display = 'block';
  document.getElementById('uploadBtn').disabled = true;
  document.getElementById('uploadBtn').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';

  const xhr = new XMLHttpRequest();
  xhr.open('POST','outlet_service_info_upload.php');

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