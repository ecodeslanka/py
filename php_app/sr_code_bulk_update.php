<?php
include 'config.php';
include 'header.php';

/* ══════════════════════════════════════════════════════════════════
   SR CODE (+ ROUTE) BULK UPDATE
   Two supported upload formats, auto-detected from header row:

   1) INVOICE MODE  — "Invoice Number", "New SR Code"
      -> matches field_summary_details.invoice_num, updates
         field_summary.sr_code (+ best-effort sync to
         loading_summary_import_details.sales_person_code)

   2) INVOICE + ROUTE MODE  — "Invoice Number", "NEW SR cODE", "Route Code"
      -> ALSO matches field_summary_details.invoice_num (the confirmed
         complete master invoice list per credit_bill_summary2.php),
         updates field_summary.sr_code AND field_summary.route directly —
         the same columns credit_bill_summary2.php itself displays.
         Also best-effort syncs sales_person_code/route_code into any
         matching rows in loading_summary_import_details and
         secondary_invoice_import_details, but a missing row there never
         blocks the real update on field_summary.

   Both modes share one preview -> confirm -> apply -> history/reverse flow.
   ══════════════════════════════════════════════════════════════════ */

/* ─── ENSURE TABLES / COLUMNS EXIST ─── */
mysqli_query($conn,"CREATE TABLE IF NOT EXISTS `sr_code_update_batches` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `batch_code`     VARCHAR(30)  NOT NULL UNIQUE,
    `file_name`      VARCHAR(255) DEFAULT '',
    `total_rows`     INT DEFAULT 0,
    `matched_rows`   INT DEFAULT 0,
    `changed_rows`   INT DEFAULT 0,
    `unmatched_rows` INT DEFAULT 0,
    `created_by`     VARCHAR(100) DEFAULT NULL,
    `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn,"CREATE TABLE IF NOT EXISTS `sr_code_updates` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `batch_id`       INT NOT NULL,
    `detail_id`      INT NOT NULL,
    `invoice_num`    VARCHAR(80)  NOT NULL DEFAULT '',
    `customer_name`  VARCHAR(200) NOT NULL DEFAULT '',
    `old_sr_code`    VARCHAR(50)  NOT NULL DEFAULT '',
    `new_sr_code`    VARCHAR(50)  NOT NULL DEFAULT '',
    `old_fs_sr_code`   VARCHAR(50) NOT NULL DEFAULT '',
    `old_lsid_sr_code` VARCHAR(50) NOT NULL DEFAULT '',
    `field_summary_id` INT NULL,
    `status`         ENUM('active','reversed') NOT NULL DEFAULT 'active',
    `applied_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `reversed_at`    DATETIME DEFAULT NULL,
    KEY `idx_batch` (`batch_id`),
    KEY `idx_detail` (`detail_id`),
    KEY `idx_invoice` (`invoice_num`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* add columns if this table already existed from an older version of this tool */
foreach ([
    'old_fs_sr_code'   => "ALTER TABLE sr_code_updates ADD COLUMN old_fs_sr_code VARCHAR(50) NOT NULL DEFAULT '' AFTER new_sr_code",
    'old_lsid_sr_code' => "ALTER TABLE sr_code_updates ADD COLUMN old_lsid_sr_code VARCHAR(50) NOT NULL DEFAULT '' AFTER old_fs_sr_code",
    'field_summary_id' => "ALTER TABLE sr_code_updates ADD COLUMN field_summary_id INT NULL AFTER old_lsid_sr_code",
    /* --- new columns to support the Invoice + Route import mode --- */
    'match_type'       => "ALTER TABLE sr_code_updates ADD COLUMN match_type ENUM('invoice','tcode') NOT NULL DEFAULT 'invoice' AFTER field_summary_id",
    't_code'           => "ALTER TABLE sr_code_updates ADD COLUMN t_code VARCHAR(50) NOT NULL DEFAULT '' AFTER match_type",
    'old_route_code'   => "ALTER TABLE sr_code_updates ADD COLUMN old_route_code VARCHAR(100) NOT NULL DEFAULT '' AFTER t_code",
    'new_route_code'   => "ALTER TABLE sr_code_updates ADD COLUMN new_route_code VARCHAR(100) NOT NULL DEFAULT '' AFTER old_route_code",
    /* what this row was matched/written against — now always
       'field_summary'; kept for older batches from earlier tool versions */
    'source_table'     => "ALTER TABLE sr_code_updates ADD COLUMN source_table VARCHAR(64) NOT NULL DEFAULT 'field_summary' AFTER new_route_code",
] as $col => $alterSql) {
    $chk = mysqli_query($conn,"SHOW COLUMNS FROM sr_code_updates LIKE '$col'");
    if ($chk && mysqli_num_rows($chk) === 0) mysqli_query($conn, $alterSql);
}

/* self-heal route_code on the import-batch tables used for the best-effort
   sync below — added only if genuinely missing, never overwritten. Neither
   is required for matching or the real update anymore (that's
   field_summary), this only keeps the sync step from erroring. */
$scu_schema_errors = [];
foreach (['secondary_invoice_import_details', 'loading_summary_import_details'] as $scu_tbl) {
    $tbl_chk = mysqli_query($conn,"SHOW TABLES LIKE '$scu_tbl'");
    if (!$tbl_chk || mysqli_num_rows($tbl_chk) === 0) continue; // table doesn't exist here, skip
    $chk = mysqli_query($conn,"SHOW COLUMNS FROM $scu_tbl LIKE 'route_code'");
    if ($chk && mysqli_num_rows($chk) === 0) {
        if (!mysqli_query($conn,"ALTER TABLE $scu_tbl ADD COLUMN route_code VARCHAR(100) NULL AFTER sales_person_code")) {
            $scu_schema_errors[] = "Could not add `route_code` column to `$scu_tbl`: " . mysqli_error($conn);
        }
    }
}

/* NOTE: Invoice-mode SR code updates are written directly and unconditionally to BOTH real
   source columns — field_summary.sr_code AND loading_summary_import_details.sales_person_code
   (whichever rows exist for that invoice) — so every page that reads either
   column (credit_bill_issue.php, credit_bill_summary2.php, etc.) picks up the
   change immediately. No separate override column is used.

   Invoice+Route-mode updates write directly to field_summary.sr_code and
   field_summary.route, matched via field_summary_details.invoice_num, plus
   a best-effort sync into any matching loading_summary_import_details /
   secondary_invoice_import_details rows. */

if (session_status() === PHP_SESSION_NONE) session_start();

$current_user = $_SESSION['username'] ?? ($_SESSION['user_name'] ?? 'system');

/* ══════════════════════════════════════════════════════════════════
   HELPERS
   ══════════════════════════════════════════════════════════════════ */

function scu_xlsx_col_to_index($col){
    $col = strtoupper(preg_replace('/[^A-Z]/','', $col));
    $idx = 0;
    for ($i=0; $i<strlen($col); $i++) $idx = $idx*26 + (ord($col[$i]) - 64);
    return $idx - 1;
}

/* very lightweight .xlsx reader for simple flat sheets (no external library) */
function scu_read_xlsx($path){
    $rows = [];
    if (!class_exists('ZipArchive')) return $rows;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return $rows;

    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ssDom = @simplexml_load_string($ssXml);
        if ($ssDom) {
            foreach ($ssDom->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else {
                    $text = '';
                    if (isset($si->r)) foreach ($si->r as $r) $text .= (string)$r->t;
                    $shared[] = $text;
                }
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) return $rows;

    $dom = @simplexml_load_string($sheetXml);
    if (!$dom || !isset($dom->sheetData->row)) return $rows;

    foreach ($dom->sheetData->row as $row) {
        $rowData = [];
        if (isset($row->c)) {
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $colLetters = preg_replace('/[0-9]/', '', $ref);
                $idx = $colLetters !== '' ? scu_xlsx_col_to_index($colLetters) : count($rowData);
                $type = (string)$c['t'];
                $val = '';
                if (isset($c->v)) {
                    $v = (string)$c->v;
                    $val = ($type === 's') ? ($shared[intval($v)] ?? '') : $v;
                } elseif (isset($c->is->t)) {
                    $val = (string)$c->is->t;
                }
                while (count($rowData) < $idx) $rowData[] = '';
                $rowData[$idx] = $val;
            }
        }
        $rows[] = $rowData;
    }
    return $rows;
}

function scu_read_csv($path){
    $rows = [];
    $fh = fopen($path, 'r');
    if (!$fh) return $rows;
    $first = fgets($fh);
    rewind($fh);
    $delim = (substr_count((string)$first, "\t") > substr_count((string)$first, ',')) ? "\t" : ',';
    while (($data = fgetcsv($fh, 0, $delim)) !== false) {
        $rows[] = $data;
    }
    fclose($fh);
    return $rows;
}

function scu_parse_file($tmpPath, $origName){
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (in_array($ext, ['xlsx','xlsm'])) return scu_read_xlsx($tmpPath);
    return scu_read_csv($tmpPath); // csv / txt / xls-saved-as-csv fallback
}

function scu_gen_batch_code($conn){
    $code = 'SRB' . date('ymd') . '-' . strtoupper(substr(uniqid(),-5));
    return mysqli_real_escape_string($conn, $code);
}

/* effective current SR code for a given field_summary_details row, mirrors
   the COALESCE logic used in credit_bill_issue.php (loading summary > field summary) */
function scu_lookup_by_invoice($conn, $invoice){
    $esc = mysqli_real_escape_string($conn, trim($invoice));
    $sql = "
    SELECT
        fsd.id                                                              AS detail_id,
        fsd.invoice_num,
        fs.id                                                               AS field_summary_id,
        COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code)     AS customer_name,
        lsid_sr.sales_person_code                                           AS lsid_sr_code,
        fs.sr_code                                                          AS fs_sr_code,
        COALESCE(lsid_sr.sales_person_code, fs.sr_code)                     AS current_sr_code
    FROM field_summary_details fsd
    INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
    LEFT  JOIN customers c      ON c.t_code = fsd.t_code
    LEFT  JOIN (
        SELECT bill_no, MIN(sales_person_code) AS sales_person_code
        FROM loading_summary_import_details
        WHERE status = 'imported' AND sales_person_code IS NOT NULL AND sales_person_code <> ''
        GROUP BY bill_no
    ) lsid_sr ON lsid_sr.bill_no = fsd.invoice_num
    WHERE fsd.invoice_num = '$esc'
      AND fsd.updated = 1
    ";
    $res = mysqli_query($conn, $sql);
    $out = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $out[] = $r;
    return $out;
}

/* Invoice Number (from the file) matches field_summary_details.invoice_num —
   confirmed by credit_bill_summary2.php as the COMPLETE master list of
   invoices (that page has no restrictive filter on it at all, and pulls
   Route Code / SR Code straight from field_summary.route / field_summary.sr_code,
   joined via field_summary_id). That is the real single source of truth
   these values are read from everywhere else in the system, so that's what
   this tool now writes to as well — not the import-batch tables, which
   only ever contain a subset of invoices (whatever was in that particular
   upload) and were the reason genuine invoices kept showing "Not Found".

   No 'updated' or status restriction — field_summary_details.invoice_num
   is checked exactly as credit_bill_summary2.php reads it, so anything
   visible on that screen is matchable here too. */
function scu_lookup_by_tcode($conn, $tcode){
    $esc = mysqli_real_escape_string($conn, trim($tcode));
    if ($esc === '') return ['matches' => [], 'debug' => 'Empty value after trim.'];

    $sql = "
    SELECT
        fsd.id                                                              AS detail_id,
        fsd.invoice_num,
        fs.id                                                               AS field_summary_id,
        fsd.t_code,
        COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code)     AS customer_name,
        fs.sr_code                                                          AS sales_person_code,
        fs.route                                                            AS route_code
    FROM field_summary_details fsd
    INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
    LEFT  JOIN customers c      ON c.t_code = fsd.t_code
    WHERE fsd.invoice_num = '$esc'
    ";
    $res = mysqli_query($conn, $sql);
    $sql_error = $res ? '' : mysqli_error($conn);
    $out = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $out[] = $r;
    if (!empty($out)) return ['matches' => $out, 'debug' => ''];

    /* still nothing — build a debug hint: exact byte length of the value
       searched for (catches invisible characters like a stray tab or
       non-breaking space from the excel cell), plus a few real invoice_num
       samples from field_summary_details so the formats can be compared
       side by side right in the preview. */
    if ($sql_error !== '') {
        $debug = 'DB query error: ' . $sql_error;
    } else {
        $len_bytes = strlen($esc);
        $len_chars = function_exists('mb_strlen') ? mb_strlen($esc, 'UTF-8') : $len_bytes;
        $hint = "Searched for '$esc' (".$len_chars." chars, ".$len_bytes." bytes) in field_summary_details.invoice_num.";
        $samp_res = mysqli_query($conn, "SELECT invoice_num FROM field_summary_details WHERE invoice_num IS NOT NULL AND invoice_num <> '' ORDER BY id DESC LIMIT 4");
        $samples = [];
        if ($samp_res) while ($sr = mysqli_fetch_assoc($samp_res)) $samples[] = "'".$sr['invoice_num']."'";
        $hint .= empty($samples)
            ? ' field_summary_details has no invoice_num values at all.'
            : ' Recent invoice_num values: ' . implode(', ', $samples) . '.';
        $debug = $hint;
    }
    return ['matches' => [], 'debug' => $debug];
}

/* ══════════════════════════════════════════════════════════════════
   AJAX: REVERSE SINGLE ROW / REVERSE WHOLE BATCH
   ══════════════════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'reverse_row') {
    header('Content-Type: application/json');
    $id = intval($_GET['id'] ?? 0);
    $r = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM sr_code_updates WHERE id=$id AND status='active'"));
    if (!$r) { echo json_encode(['success'=>false,'error'=>'Record not found or already reversed']); exit; }

    if ($r['match_type'] === 'tcode') {
        /* Invoice+Route mode: revert BOTH sr_code and route directly on
           field_summary (matched by field_summary_id, the master record
           this row was matched against). */
        $fs_id     = intval($r['field_summary_id']);
        $old_sr    = mysqli_real_escape_string($conn, $r['old_sr_code']);
        $old_route = mysqli_real_escape_string($conn, $r['old_route_code']);
        if ($fs_id) {
            $sets = [];
            if ($r['old_sr_code']    !== '') $sets[] = "sr_code = '$old_sr'";
            if ($r['old_route_code'] !== '') $sets[] = "route = '$old_route'";
            if (!empty($sets)) {
                mysqli_query($conn,"UPDATE field_summary SET ".implode(', ', $sets)." WHERE id = $fs_id");
            }
        }
    } else {
        $invoice   = mysqli_real_escape_string($conn, $r['invoice_num']);
        $old_fs    = mysqli_real_escape_string($conn, $r['old_fs_sr_code']);
        $old_lsid  = mysqli_real_escape_string($conn, $r['old_lsid_sr_code']);
        $fs_id     = intval($r['field_summary_id']);

        if ($fs_id) {
            mysqli_query($conn,"UPDATE field_summary SET sr_code = '$old_fs' WHERE id = $fs_id");
        }
        if ($old_lsid === '') {
            mysqli_query($conn,"UPDATE loading_summary_import_details SET sales_person_code = NULL WHERE bill_no = '$invoice' AND status = 'imported'");
        } else {
            mysqli_query($conn,"UPDATE loading_summary_import_details SET sales_person_code = '$old_lsid' WHERE bill_no = '$invoice' AND status = 'imported'");
        }
    }
    mysqli_query($conn,"UPDATE sr_code_updates SET status='reversed', reversed_at=NOW() WHERE id=$id");

    echo json_encode(['success'=>true]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'reverse_batch') {
    header('Content-Type: application/json');
    $batch_id = intval($_GET['batch_id'] ?? 0);
    $res = mysqli_query($conn,"SELECT * FROM sr_code_updates WHERE batch_id=$batch_id AND status='active'");
    $count = 0;
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            if ($r['match_type'] === 'tcode') {
                $fs_id     = intval($r['field_summary_id']);
                $old_sr    = mysqli_real_escape_string($conn, $r['old_sr_code']);
                $old_route = mysqli_real_escape_string($conn, $r['old_route_code']);
                if ($fs_id) {
                    $sets = [];
                    if ($r['old_sr_code']    !== '') $sets[] = "sr_code = '$old_sr'";
                    if ($r['old_route_code'] !== '') $sets[] = "route = '$old_route'";
                    if (!empty($sets)) {
                        mysqli_query($conn,"UPDATE field_summary SET ".implode(', ', $sets)." WHERE id = $fs_id");
                    }
                }
            } else {
                $invoice  = mysqli_real_escape_string($conn, $r['invoice_num']);
                $old_fs   = mysqli_real_escape_string($conn, $r['old_fs_sr_code']);
                $old_lsid = mysqli_real_escape_string($conn, $r['old_lsid_sr_code']);
                $fs_id    = intval($r['field_summary_id']);

                if ($fs_id) {
                    mysqli_query($conn,"UPDATE field_summary SET sr_code = '$old_fs' WHERE id = $fs_id");
                }
                if ($old_lsid === '') {
                    mysqli_query($conn,"UPDATE loading_summary_import_details SET sales_person_code = NULL WHERE bill_no = '$invoice' AND status = 'imported'");
                } else {
                    mysqli_query($conn,"UPDATE loading_summary_import_details SET sales_person_code = '$old_lsid' WHERE bill_no = '$invoice' AND status = 'imported'");
                }
            }
            mysqli_query($conn,"UPDATE sr_code_updates SET status='reversed', reversed_at=NOW() WHERE id=".intval($r['id']));
            $count++;
        }
    }
    echo json_encode(['success'=>true,'count'=>$count]);
    exit;
}

/* ══════════════════════════════════════════════════════════════════
   POST: UPLOAD -> BUILD PREVIEW (stored in session, not yet applied)
   ══════════════════════════════════════════════════════════════════ */
$preview        = null;   // array of preview rows to render
$preview_stats  = null;
$preview_mode   = 'invoice';
$page_mode      = 'upload'; // upload | preview
$upload_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_preview') {

    if (!isset($_FILES['sr_file']) || $_FILES['sr_file']['error'] !== UPLOAD_ERR_OK) {
        $upload_error = 'Please choose a valid file to upload.';
    } else {
        $tmpPath  = $_FILES['sr_file']['tmp_name'];
        $origName = $_FILES['sr_file']['name'];
        $rawRows  = scu_parse_file($tmpPath, $origName);

        if (empty($rawRows)) {
            $upload_error = 'Could not read any rows from the uploaded file. Use .csv or .xlsx with headers "Invoice Number, New SR Code" OR "T Code, Invoice Number, Route Code, NEW SR cODE".';
        } else {
            /* detect which of the two supported formats this file is */
            $header  = array_map(function($h){ return strtolower(trim((string)$h)); }, $rawRows[0]);
            $t_col = null; $inv_col = null; $sr_col = null; $route_col = null;

            /* the deciding factor is whether a Route column is present — that's
               what tells us to use the bill_no + route_code update path,
               regardless of whether the match column is headed "T Code" or
               "Invoice Number" (both refer to the same loading_summary_import_details.bill_no).
               Prefer a header that says "route code" over a plain "route name" —
               in this file's layout "Route Code" is the column holding the NEW
               route value, so it must win over "Route Name" (which is the
               existing/old route label sitting next to it). */
            foreach ($header as $i => $h) {
                if (strpos($h,'route') !== false && strpos($h,'code') !== false) { $route_col = $i; break; }
            }
            if ($route_col === null) {
                foreach ($header as $i => $h) {
                    if (strpos($h,'route') !== false) { $route_col = $i; break; }
                }
            }
            $import_mode = ($route_col !== null) ? 'tcode' : 'invoice';

            /* Customer / Route Name columns — purely informational, read
               straight from the file so every row in the preview shows its
               real identifying details (who/where) even if the T Code /
               Invoice fails to match anything in the database. */
            $customer_col = null; $route_name_col = null;
            foreach ($header as $i => $h) {
                if ($customer_col === null && strpos($h,'customer') !== false) $customer_col = $i;
            }
            foreach ($header as $i => $h) {
                if ($route_name_col === null && strpos($h,'route') !== false && strpos($h,'name') !== false) $route_name_col = $i;
            }

            if ($import_mode === 'tcode') {
                foreach ($header as $i => $h) {
                    if ($t_col  === null && strpos($h,'invoice') !== false) $t_col = $i;
                    if ($t_col  === null && (strpos($h,'t code') !== false || strpos($h,'t_code') !== false || strpos($h,'tcode') !== false)) $t_col = $i;
                }
                if ($t_col === null) $t_col = 0;

                /* NEW SR code column — a file like this one has BOTH an existing
                   "SR Code" column (old value) and a "NEW SR cODE" column (the
                   value to write). Any header containing "sr" would match the
                   old column first unless we explicitly prefer a header that
                   also contains "new". Only fall back to a bare "sr" header
                   when no "new ... sr" header exists at all. */
                foreach ($header as $i => $h) {
                    if (strpos($h,'new') !== false && strpos($h,'sr') !== false) { $sr_col = $i; break; }
                }
                if ($sr_col === null) {
                    foreach ($header as $i => $h) {
                        if (strpos($h,'sr') !== false) { $sr_col = $i; break; }
                    }
                }
                if ($sr_col === null) $sr_col = 2;
            } else {
                foreach ($header as $i => $h) {
                    if ($inv_col === null && strpos($h,'invoice') !== false) $inv_col = $i;
                }
                foreach ($header as $i => $h) {
                    if (strpos($h,'new') !== false && strpos($h,'sr') !== false) { $sr_col = $i; break; }
                }
                if ($sr_col === null) {
                    foreach ($header as $i => $h) {
                        if (strpos($h,'sr') !== false) { $sr_col = $i; break; }
                    }
                }
                $data_rows = $rawRows;
                if ($inv_col !== null || $sr_col !== null) {
                    array_shift($data_rows); // drop header row
                } else {
                    $inv_col = 0; $sr_col = 1; // no recognizable header, assume col A / B
                }
                if ($inv_col === null) $inv_col = 0;
                if ($sr_col  === null) $sr_col  = 1;
            }

            if ($import_mode === 'tcode') {
                $data_rows = $rawRows;
                array_shift($data_rows); // this format always has a header row
            }

            $preview_rows = [];
            $matched = 0; $changed = 0; $unmatched = 0;

            if ($import_mode === 'tcode') {
                foreach ($data_rows as $rowNum => $r) {
                    $tcode        = trim((string)($r[$t_col] ?? ''));
                    $newCode      = strtoupper(trim((string)($r[$sr_col] ?? '')));
                    $newRoute     = trim((string)($r[$route_col] ?? ''));
                    $fileCustomer = $customer_col   !== null ? trim((string)($r[$customer_col]   ?? '')) : '';
                    $fileRouteNm  = $route_name_col !== null ? trim((string)($r[$route_name_col] ?? '')) : '';
                    if ($tcode === '' && $newCode === '' && $newRoute === '') continue; // blank line

                    if ($tcode === '') {
                        $preview_rows[] = [
                            'row' => $rowNum+2, 't_code' => $tcode, 'new_code' => $newCode, 'new_route' => $newRoute,
                            'detail_id' => null, 'customer' => $fileCustomer, 'file_route_name' => $fileRouteNm,
                            'old_code' => '', 'old_route' => '',
                            'match' => 'no_invoice', 'will_change' => false
                        ];
                        $unmatched++;
                        continue;
                    }

                    $lookup  = scu_lookup_by_tcode($conn, $tcode);
                    $matches = $lookup['matches'];

                    if (empty($matches)) {
                        $preview_rows[] = [
                            'row' => $rowNum+2, 't_code' => $tcode, 'new_code' => $newCode, 'new_route' => $newRoute,
                            'detail_id' => null, 'customer' => $fileCustomer, 'file_route_name' => $fileRouteNm,
                            'old_code' => '', 'old_route' => '', 'debug' => $lookup['debug'],
                            'match' => 'not_found', 'will_change' => false
                        ];
                        $unmatched++;
                        continue;
                    }

                    foreach ($matches as $m) {
                        $old_code  = (string)$m['sales_person_code'];
                        $old_route = (string)$m['route_code'];
                        $matched++;
                        $will_change = ($newCode !== '' && strtoupper($old_code) !== $newCode)
                                    || ($newRoute !== '' && $old_route !== $newRoute);
                        if ($will_change) $changed++;
                        $preview_rows[] = [
                            'row'              => $rowNum+2,
                            't_code'           => $tcode,
                            'new_code'         => $newCode,
                            'new_route'        => $newRoute,
                            'detail_id'        => $m['detail_id'],
                            'field_summary_id' => $m['field_summary_id'],
                            'invoice_num'      => $m['invoice_num'],
                            /* prefer the file's own Customer column when it has a value —
                               the DB fallback is just the bill number re-used as a label */
                            'customer'         => $fileCustomer !== '' ? $fileCustomer : $m['customer_name'],
                            'file_route_name'  => $fileRouteNm,
                            'old_code'         => $old_code,
                            'old_route'        => $old_route,
                            'source_table'     => 'field_summary',
                            'match'            => 'found',
                            'will_change'     => $will_change
                        ];
                    }
                }
            } else {
                foreach ($data_rows as $rowNum => $r) {
                    $invoice = trim((string)($r[$inv_col] ?? ''));
                    $newCode = strtoupper(trim((string)($r[$sr_col] ?? '')));
                    if ($invoice === '' && $newCode === '') continue; // blank line
                    if ($invoice === '') {
                        $preview_rows[] = [
                            'row' => $rowNum+2, 'invoice' => $invoice, 'new_code' => $newCode,
                            'detail_id' => null, 'customer' => '', 'old_code' => '',
                            'match' => 'no_invoice', 'will_change' => false
                        ];
                        $unmatched++;
                        continue;
                    }

                    $matches = scu_lookup_by_invoice($conn, $invoice);

                    if (empty($matches)) {
                        $preview_rows[] = [
                            'row' => $rowNum+2, 'invoice' => $invoice, 'new_code' => $newCode,
                            'detail_id' => null, 'customer' => '', 'old_code' => '',
                            'match' => 'not_found', 'will_change' => false
                        ];
                        $unmatched++;
                        continue;
                    }

                    foreach ($matches as $m) {
                        $old_code = (string)$m['current_sr_code'];
                        $matched++;
                        $will_change = ($newCode !== '' && strtoupper($old_code) !== $newCode);
                        if ($will_change) $changed++;
                        $preview_rows[] = [
                            'row'              => $rowNum+2,
                            'invoice'          => $invoice,
                            'new_code'         => $newCode,
                            'detail_id'        => $m['detail_id'],
                            'field_summary_id' => $m['field_summary_id'],
                            'lsid_sr_code'     => $m['lsid_sr_code'],
                            'fs_sr_code'       => $m['fs_sr_code'],
                            'customer'         => $m['customer_name'],
                            'old_code'         => $old_code,
                            'match'            => 'found',
                            'will_change'      => $will_change
                        ];
                    }
                }
            }

            $_SESSION['scu_pending'] = [
                'rows'      => $preview_rows,
                'file_name' => $origName,
                'mode'      => $import_mode,
                'stats'     => [
                    'total'     => count($preview_rows),
                    'matched'   => $matched,
                    'changed'   => $changed,
                    'unmatched' => $unmatched
                ]
            ];
            $page_mode = 'preview';
        }
    }
}

/* redisplay preview if it exists in session (e.g. after a GET refresh) */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['view'] ?? '') === 'preview' && !empty($_SESSION['scu_pending'])) {
    $page_mode = 'preview';
}

if ($page_mode === 'preview' && !empty($_SESSION['scu_pending'])) {
    $preview       = $_SESSION['scu_pending']['rows'];
    $preview_stats = $_SESSION['scu_pending']['stats'];
    $preview_file  = $_SESSION['scu_pending']['file_name'];
    $preview_mode  = $_SESSION['scu_pending']['mode'] ?? 'invoice';
}

/* ══════════════════════════════════════════════════════════════════
   POST: CONFIRM & APPLY
   ══════════════════════════════════════════════════════════════════ */
$apply_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_apply') {

    if (empty($_SESSION['scu_pending']['rows'])) {
        $upload_error = 'Nothing to apply — please upload a file first.';
    } else {
        $rows        = $_SESSION['scu_pending']['rows'];
        $file_name   = $_SESSION['scu_pending']['file_name'];
        $import_mode = $_SESSION['scu_pending']['mode'] ?? 'invoice';
        $batch_code  = scu_gen_batch_code($conn);
        $esc_file    = mysqli_real_escape_string($conn, $file_name);
        $esc_user    = mysqli_real_escape_string($conn, $current_user);

        mysqli_query($conn,"INSERT INTO sr_code_update_batches
            (batch_code, file_name, total_rows, matched_rows, changed_rows, unmatched_rows, created_by)
            VALUES ('$batch_code','$esc_file',
                    ".intval($preview_stats['total'] ?? count($rows)).",
                    0,0,0,'$esc_user')");
        $batch_id = mysqli_insert_id($conn);

        $applied = 0;

        if ($import_mode === 'tcode') {
            foreach ($rows as $r) {
                if ($r['match'] !== 'found' || empty($r['field_summary_id'])) continue;

                $fs_id     = intval($r['field_summary_id']);
                $detail_id = intval($r['detail_id']);
                $new_code  = mysqli_real_escape_string($conn, $r['new_code']);
                $new_route = mysqli_real_escape_string($conn, $r['new_route']);
                $old_code  = mysqli_real_escape_string($conn, $r['old_code']);
                $old_route = mysqli_real_escape_string($conn, $r['old_route']);
                $tcode     = mysqli_real_escape_string($conn, $r['t_code']);
                $invoice   = mysqli_real_escape_string($conn, $r['invoice_num'] ?? '');
                $customer  = mysqli_real_escape_string($conn, $r['customer']);

                /* write straight onto field_summary — the actual source
                   credit_bill_summary2.php and everything else reads
                   sr_code/route from — only for the fields the file
                   actually supplied a value for */
                $sets = [];
                if ($r['new_code']  !== '') $sets[] = "sr_code = '$new_code'";
                if ($r['new_route'] !== '') $sets[] = "route = '$new_route'";
                if (!empty($sets)) {
                    $upd_ok = mysqli_query($conn,"UPDATE field_summary SET ".implode(', ', $sets)." WHERE id = $fs_id");
                    if (!$upd_ok) {
                        $scu_schema_errors[] = "field_summary id=$fs_id failed to update: " . mysqli_error($conn);
                        continue; // don't log a fake success in history if the write itself failed
                    }
                }

                /* best-effort sync: if this invoice also has a row in either
                   import-batch table, keep it in sync too — but a missing
                   row there is expected and never blocks the real update above */
                if ($invoice !== '') {
                    if ($new_code !== '')  mysqli_query($conn,"UPDATE loading_summary_import_details SET sales_person_code = '$new_code' WHERE bill_no = '$invoice'");
                    if ($new_route !== '') mysqli_query($conn,"UPDATE loading_summary_import_details SET route_code = '$new_route' WHERE bill_no = '$invoice'");
                    if ($new_code !== '')  mysqli_query($conn,"UPDATE secondary_invoice_import_details SET sales_person_code = '$new_code' WHERE bill_no = '$invoice'");
                    if ($new_route !== '') mysqli_query($conn,"UPDATE secondary_invoice_import_details SET route_code = '$new_route' WHERE bill_no = '$invoice'");
                }

                mysqli_query($conn,"INSERT INTO sr_code_updates
                    (batch_id, detail_id, invoice_num, customer_name, old_sr_code, new_sr_code, old_fs_sr_code, old_lsid_sr_code, field_summary_id, match_type, t_code, old_route_code, new_route_code, source_table, status)
                    VALUES ($batch_id, $detail_id, '$invoice', '$customer', '$old_code', '$new_code', '', '', $fs_id, 'tcode', '$tcode', '$old_route', '$new_route', 'field_summary', 'active')");

                $applied++;
            }
        } else {
            foreach ($rows as $r) {
                /* apply to every matched row — even if the new code looks the same as what
                   we previously computed, since that computed value may itself have been
                   stale/out of sync between field_summary and loading_summary_import_details.
                   Re-applying always re-syncs both tables to the uploaded value. */
                if ($r['match'] !== 'found' || empty($r['detail_id']) || $r['new_code'] === '') continue;

                $detail_id = intval($r['detail_id']);
                $new_code  = mysqli_real_escape_string($conn, $r['new_code']);
                $old_code  = mysqli_real_escape_string($conn, $r['old_code']);
                $invoice   = mysqli_real_escape_string($conn, $r['invoice']);
                $customer  = mysqli_real_escape_string($conn, $r['customer']);
                $fs_id     = intval($r['field_summary_id'] ?? 0);
                $old_fs    = mysqli_real_escape_string($conn, (string)($r['fs_sr_code'] ?? ''));
                $old_lsid  = mysqli_real_escape_string($conn, (string)($r['lsid_sr_code'] ?? ''));

                /* write to BOTH real source tables unconditionally, so every page —
                   regardless of which column it reads (fs.sr_code directly, or
                   COALESCE(lsid, fs)) — shows the updated code immediately. */
                if ($fs_id) {
                    mysqli_query($conn,"UPDATE field_summary SET sr_code = '$new_code' WHERE id = $fs_id");
                }
                mysqli_query($conn,"UPDATE loading_summary_import_details
                    SET sales_person_code = '$new_code'
                    WHERE bill_no = '$invoice' AND status = 'imported'");

                mysqli_query($conn,"INSERT INTO sr_code_updates
                    (batch_id, detail_id, invoice_num, customer_name, old_sr_code, new_sr_code, old_fs_sr_code, old_lsid_sr_code, field_summary_id, match_type, status)
                    VALUES ($batch_id, $detail_id, '$invoice', '$customer', '$old_code', '$new_code', '$old_fs', '$old_lsid', ".($fs_id ?: 'NULL').", 'invoice', 'active')");

                $applied++;
            }
        }

        $matched_ct   = 0; $unmatched_ct = 0;
        foreach ($rows as $r) { if ($r['match']==='found') $matched_ct++; else $unmatched_ct++; }
        mysqli_query($conn,"UPDATE sr_code_update_batches SET matched_rows=$matched_ct, changed_rows=$applied, unmatched_rows=$unmatched_ct WHERE id=$batch_id");

        unset($_SESSION['scu_pending']);
        $apply_result = ['batch_code'=>$batch_code, 'applied'=>$applied, 'batch_id'=>$batch_id];
        $page_mode = 'upload';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_preview') {
    unset($_SESSION['scu_pending']);
    $page_mode = 'upload';
}

/* ══════════════════════════════════════════════════════════════════
   HISTORY (batches + row detail)
   ══════════════════════════════════════════════════════════════════ */
$batches = [];
$b_res = mysqli_query($conn,"SELECT * FROM sr_code_update_batches ORDER BY id DESC LIMIT 50");
if ($b_res) while ($b = mysqli_fetch_assoc($b_res)) $batches[] = $b;

$history_rows_by_batch = [];
if (!empty($batches)) {
    $ids = implode(',', array_map(function($b){ return intval($b['id']); }, $batches));
    $h_res = mysqli_query($conn,"SELECT * FROM sr_code_updates WHERE batch_id IN ($ids) ORDER BY id ASC");
    if ($h_res) while ($h = mysqli_fetch_assoc($h_res)) $history_rows_by_batch[$h['batch_id']][] = $h;
}
?>

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet"/>

<style>
*{box-sizing:border-box;}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;}
.page-title{font-size:20px;font-weight:800;color:#1f2937;display:flex;align-items:center;gap:8px;}
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s;white-space:nowrap;}
.btn-primary{background:#6366f1;color:#fff;}.btn-primary:hover{background:#4f46e5;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-success{background:#16a34a;color:#fff;}.btn-success:hover{background:#15803d;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-sm{padding:5px 11px;font-size:11px;}
.btn:disabled{opacity:.5;cursor:not-allowed;}

.card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:18px 20px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.card-title{font-size:13px;font-weight:700;color:#374151;margin-bottom:14px;display:flex;align-items:center;gap:6px;}

.upload-drop{border:2px dashed #c7c9f5;border-radius:10px;padding:34px 20px;text-align:center;background:#f8f8ff;transition:border .2s;}
.upload-drop.dragover{border-color:#6366f1;background:#eef2ff;}
.upload-drop i{font-size:34px;color:#6366f1;margin-bottom:10px;display:block;}
.upload-drop p{font-size:13px;color:#4b5563;margin:0 0 4px;font-weight:600;}
.upload-drop small{font-size:11px;color:#9ca3af;display:block;}
.upload-file-name{margin-top:12px;font-size:12px;font-weight:700;color:#4338ca;display:none;}

.alert{padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.alert-info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;}

.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:20px;}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px;}
.stat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.stat-value{font-size:19px;font-weight:800;color:#1f2937;}
.stat-value.green{color:#16a34a;}.stat-value.red{color:#dc2626;}.stat-value.amber{color:#d97706;}.stat-value.blue{color:#2563eb;}

.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead th{padding:9px 8px;text-align:left;font-weight:700;font-size:11px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;}
.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr td{padding:7px 8px;color:#374151;vertical-align:middle;}
.data-table tbody tr.row-changed td{background:#f0fdf4;}
.data-table tbody tr.row-nochange td{background:#fff;}
.data-table tbody tr.row-unmatched td{background:#fef2f2;}
.data-table tbody tr:hover td{filter:brightness(0.98);}
.tc{text-align:center;}
.old-code{font-family:monospace;font-weight:700;color:#9ca3af;text-decoration:line-through;}
.new-code{font-family:monospace;font-weight:800;color:#166534;}
.old-route{font-family:monospace;font-weight:600;color:#9ca3af;text-decoration:line-through;font-size:11.5px;}
.new-route{font-family:monospace;font-weight:700;color:#1e40af;font-size:11.5px;}
.arrow-ico{color:#a5b4fc;margin:0 6px;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:9px;font-size:10.5px;font-weight:700;}
.badge-change{background:#dcfce7;color:#166534;}
.badge-same{background:#f3f4f6;color:#6b7280;}
.badge-notfound{background:#fee2e2;color:#991b1b;}
.badge-noinv{background:#fef3c7;color:#92400e;}
.badge-active{background:#dbeafe;color:#1e40af;}
.badge-reversed{background:#f3f4f6;color:#9ca3af;}
.badge-mode{background:#ede9fe;color:#5b21b6;}

.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);margin-bottom:20px;}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:10px;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;}

.batch-block{border-bottom:1px solid #f0f0f0;}
.batch-block:last-child{border-bottom:none;}
.batch-head{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;background:#f9fafb;cursor:pointer;flex-wrap:wrap;gap:10px;}
.batch-head:hover{background:#f3f4f6;}
.batch-code{font-family:monospace;font-weight:800;color:#4338ca;font-size:12.5px;}
.batch-meta{font-size:11px;color:#6b7280;display:flex;gap:14px;flex-wrap:wrap;align-items:center;}
.batch-body{display:none;padding:0 0 6px;}
.batch-body.open{display:block;}
.chev{transition:transform .2s;color:#9ca3af;}
.chev.open{transform:rotate(90deg);}

.tbl-search-wrap{position:relative;display:inline-flex;align-items:center;}
.tbl-search-wrap i{position:absolute;left:10px;color:#9ca3af;font-size:12px;pointer-events:none;}
.tbl-search{border:1px solid #e5e5e5;border-radius:7px;padding:7px 10px 7px 30px;font-size:12px;width:220px;outline:none;transition:border .2s;}
.tbl-search:focus{border-color:#6366f1;}

#__scu_toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(80px);z-index:99999;padding:11px 22px;border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.2);transition:transform .3s;display:flex;align-items:center;gap:8px;background:#166534;color:#fff;white-space:nowrap;}

.confirm-bar{position:sticky;bottom:0;background:#1e1b4b;color:#fff;padding:14px 20px;border-radius:10px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-top:6px;}
.confirm-info{font-size:13px;font-weight:700;}
.confirm-info span{color:#a5b4fc;}
</style>

<div class="page-header">
    <div class="page-title">
        <i class="fa-solid fa-arrows-rotate" style="color:#6366f1;"></i>
        SR Code &amp; Route Bulk Update
    </div>
    <div style="display:flex;gap:8px;">
        <a href="credit_bill_summary.php" class="btn btn-secondary btn-sm">
            <i class="fa-solid fa-file-invoice"></i> Bill Summary
        </a>
    </div>
</div>

<?php if (!empty($scu_schema_errors)): ?>
<div class="alert alert-error">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <div>
        <?php foreach ($scu_schema_errors as $e): ?>
            <?php echo htmlspecialchars($e); ?><br>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($upload_error): ?>
<div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($upload_error); ?></div>
<?php endif; ?>

<?php if ($apply_result): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i>
    Batch <strong><?php echo htmlspecialchars($apply_result['batch_code']); ?></strong> applied —
    <?php echo intval($apply_result['applied']); ?> record<?php echo $apply_result['applied']!==1?'s':''; ?> updated.
</div>
<?php endif; ?>

<?php if ($page_mode === 'preview' && $preview !== null): ?>
<!-- ═══════════════ IMPORT PREVIEW (FULL PAGE) ═══════════════ -->
<div class="alert alert-info">
    <i class="fa-solid fa-file-import"></i>
    Previewing <strong><?php echo htmlspecialchars($preview_file); ?></strong>
    <span class="badge badge-mode" style="margin-left:6px;">
        <?php echo $preview_mode === 'tcode' ? 'Invoice + SR Code + Route mode' : 'Invoice + SR Code mode'; ?>
    </span>
    — nothing has been saved yet. Review below, then confirm to apply.
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-label">Rows in File</div><div class="stat-value blue"><?php echo intval($preview_stats['total']); ?></div></div>
    <div class="stat-card"><div class="stat-label">Matched — Will Be Applied</div><div class="stat-value green"><?php echo intval($preview_stats['matched']); ?></div></div>
    <div class="stat-card"><div class="stat-label">— of which, Something Differs</div><div class="stat-value"><?php echo intval($preview_stats['changed']); ?></div></div>
    <div class="stat-card"><div class="stat-label">Not Found</div><div class="stat-value red"><?php echo intval($preview_stats['unmatched']); ?></div></div>
</div>

<div class="table-card">
    <div class="table-toolbar">
        <div class="tbl-title"><i class="fa-solid fa-table"></i> Import Preview</div>
        <div class="tbl-search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="tbl-search" id="previewSearch" placeholder="Search…" oninput="filterPreview()">
        </div>
    </div>
    <div class="dt-wrap">
    <table class="data-table" id="previewTable">
        <thead>
        <?php if ($preview_mode === 'tcode'): ?>
            <tr>
                <th class="tc">#</th>
                <th>Invoice / T Code</th>
                <th>Customer / Route</th>
                <th class="tc">SR Code</th>
                <th class="tc">Route Code</th>
                <th class="tc">Result</th>
            </tr>
        <?php else: ?>
            <tr>
                <th class="tc">#</th>
                <th>Invoice Number</th>
                <th>Customer</th>
                <th class="tc">Old SR Code</th>
                <th class="tc"></th>
                <th class="tc">New SR Code</th>
                <th class="tc">Result</th>
            </tr>
        <?php endif; ?>
        </thead>
        <tbody>
        <?php foreach ($preview as $p):
            if ($p['match'] === 'found' && $p['will_change']) { $rowClass = 'row-changed'; }
            elseif ($p['match'] === 'found') { $rowClass = 'row-nochange'; }
            else { $rowClass = 'row-unmatched'; }

            if ($preview_mode === 'tcode') {
                $searchStr = strtolower(($p['t_code'] ?? '').' '.($p['invoice_num'] ?? '').' '.($p['customer'] ?? '').' '.($p['file_route_name'] ?? '').' '.($p['old_code'] ?? '').' '.($p['new_code'] ?? '').' '.($p['old_route'] ?? '').' '.($p['new_route'] ?? ''));
            } else {
                $searchStr = strtolower($p['invoice'].' '.$p['customer'].' '.$p['old_code'].' '.$p['new_code']);
            }
        ?>
            <tr class="<?php echo $rowClass; ?>" data-search="<?php echo htmlspecialchars($searchStr, ENT_QUOTES); ?>">
            <?php if ($preview_mode === 'tcode'): ?>
                <td class="tc" style="color:#9ca3af;"><?php echo intval($p['row']); ?></td>
                <td><span style="font-family:monospace;font-weight:700;"><?php echo htmlspecialchars($p['t_code'] ?: '—'); ?></span></td>
                <td>
                    <?php echo htmlspecialchars(($p['customer'] ?? '') !== '' ? $p['customer'] : '—'); ?>
                    <?php if (!empty($p['file_route_name'])): ?><br><span style="color:#9ca3af;font-size:11px;"><?php echo htmlspecialchars($p['file_route_name']); ?></span><?php endif; ?>
                </td>
                <td class="tc">
                    <span class="old-code"><?php echo htmlspecialchars($p['old_code'] ?: '—'); ?></span>
                    <i class="fa-solid fa-arrow-right arrow-ico"></i>
                    <span class="new-code"><?php echo htmlspecialchars($p['new_code'] ?: '—'); ?></span>
                </td>
                <td class="tc">
                    <span class="old-route"><?php echo htmlspecialchars($p['old_route'] ?: '—'); ?></span>
                    <i class="fa-solid fa-arrow-right arrow-ico"></i>
                    <span class="new-route"><?php echo htmlspecialchars($p['new_route'] ?: '—'); ?></span>
                </td>
                <td class="tc" style="text-align:left;">
                    <?php if ($p['match'] === 'not_found'): ?>
                        <span class="badge badge-notfound"><i class="fa-solid fa-circle-xmark"></i> Invoice Not Found</span>
                        <?php if (!empty($p['debug'])): ?>
                            <div style="font-size:10.5px;color:#9ca3af;margin-top:4px;line-height:1.4;"><?php echo htmlspecialchars($p['debug']); ?></div>
                        <?php endif; ?>
                    <?php elseif ($p['match'] === 'no_invoice'): ?>
                        <span class="badge badge-noinv"><i class="fa-solid fa-triangle-exclamation"></i> Missing Invoice Number</span>
                    <?php elseif ($p['will_change']): ?>
                        <span class="badge badge-change"><i class="fa-solid fa-pen"></i> Will Update</span>
                    <?php else: ?>
                        <span class="badge badge-same"><i class="fa-solid fa-rotate"></i> Same — Will Re-Sync</span>
                    <?php endif; ?>
                    <?php if (!empty($p['loose_match'])): ?>
                        <br><span class="badge badge-noinv" style="margin-top:3px;"><i class="fa-solid fa-magnifying-glass"></i> Fuzzy match — verify bill no.</span>
                    <?php endif; ?>
                    <?php if ($p['match'] === 'found' && !empty($p['source_table'])): ?>
                        <br><span style="font-size:10px;color:#9ca3af;margin-top:3px;display:inline-block;">table: <?php echo htmlspecialchars($p['source_table']); ?></span>
                    <?php endif; ?>
                </td>
            <?php else: ?>
                <td class="tc" style="color:#9ca3af;"><?php echo intval($p['row']); ?></td>
                <td><span style="font-family:monospace;font-weight:700;"><?php echo htmlspecialchars($p['invoice'] ?: '—'); ?></span></td>
                <td><?php echo htmlspecialchars($p['customer'] ?: '—'); ?></td>
                <td class="tc"><span class="old-code"><?php echo htmlspecialchars($p['old_code'] ?: '—'); ?></span></td>
                <td class="tc"><i class="fa-solid fa-arrow-right arrow-ico"></i></td>
                <td class="tc"><span class="new-code"><?php echo htmlspecialchars($p['new_code'] ?: '—'); ?></span></td>
                <td class="tc">
                    <?php if ($p['match'] === 'not_found'): ?>
                        <span class="badge badge-notfound"><i class="fa-solid fa-circle-xmark"></i> Invoice Not Found</span>
                    <?php elseif ($p['match'] === 'no_invoice'): ?>
                        <span class="badge badge-noinv"><i class="fa-solid fa-triangle-exclamation"></i> Missing Invoice #</span>
                    <?php elseif ($p['will_change']): ?>
                        <span class="badge badge-change"><i class="fa-solid fa-pen"></i> Will Update</span>
                    <?php else: ?>
                        <span class="badge badge-same"><i class="fa-solid fa-rotate"></i> Same Code — Will Re-Sync</span>
                    <?php endif; ?>
                </td>
            <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="confirm-bar">
    <div class="confirm-info">
        <span><?php echo intval($preview_stats['matched']); ?></span> matched record(s) will be written to the database
        (<?php echo intval($preview_stats['changed']); ?> with a different value, <?php echo intval($preview_stats['matched']) - intval($preview_stats['changed']); ?> already matching but re-synced anyway).
        Old values are saved to history so this can be reversed later.
    </div>
    <div style="display:flex;gap:8px;">
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="cancel_preview">
            <button type="submit" class="btn btn-secondary"><i class="fa-solid fa-xmark"></i> Cancel</button>
        </form>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Apply and re-sync '+<?php echo intval($preview_stats['matched']); ?>+' matched record(s)? This will be recorded in history and can be reversed.');">
            <input type="hidden" name="action" value="confirm_apply">
            <button type="submit" class="btn btn-success" <?php echo intval($preview_stats['matched'])===0?'disabled':''; ?>>
                <i class="fa-solid fa-circle-check"></i> Confirm &amp; Apply <?php echo intval($preview_stats['matched']); ?> Record(s)
            </button>
        </form>
    </div>
</div>

<script>
function filterPreview(){
    const q = document.getElementById('previewSearch').value.toLowerCase();
    document.querySelectorAll('#previewTable tbody tr').forEach(tr=>{
        tr.style.display = (!q || tr.dataset.search.includes(q)) ? '' : 'none';
    });
}
</script>

<?php else: ?>
<!-- ═══════════════ UPLOAD FORM ═══════════════ -->
<div class="card">
    <div class="card-title"><i class="fa-solid fa-file-arrow-up"></i> Upload SR Code / Route Update File</div>
    <form method="POST" enctype="multipart/form-data" id="uploadForm">
        <input type="hidden" name="action" value="upload_preview">
        <label class="upload-drop" id="dropZone" for="srFileInput">
            <i class="fa-solid fa-cloud-arrow-up"></i>
            <p>Click to choose file, or drag & drop here</p>
            <small>.csv or .xlsx — either: <strong>Invoice Number, New SR Code</strong></small>
            <small>or: <strong>T Code, Invoice Number, Route Code, NEW SR cODE</strong> (Route Code = new route, NEW SR cODE = new SR code; updates both in loading summary)</small>
            <div class="upload-file-name" id="fileNameDisplay"></div>
        </label>
        <input type="file" id="srFileInput" name="sr_file" accept=".csv,.xlsx,.xlsm,.txt" style="display:none;" required>
        <div style="text-align:right;margin-top:14px;">
            <button type="submit" class="btn btn-primary" id="uploadBtn">
                <i class="fa-solid fa-magnifying-glass"></i> Parse &amp; Preview
            </button>
        </div>
    </form>
</div>

<div class="table-card">
    <div class="table-toolbar">
        <div class="tbl-title"><i class="fa-solid fa-clock-rotate-left"></i> Update History
            <span class="badge badge-active" style="margin-left:6px;"><?php echo count($batches); ?> batch<?php echo count($batches)!==1?'es':''; ?></span>
        </div>
    </div>

    <?php if (empty($batches)): ?>
        <div style="padding:40px;text-align:center;color:#9ca3af;">
            <i class="fa-solid fa-inbox" style="font-size:32px;display:block;margin-bottom:10px;opacity:.4;"></i>
            No SR code / route updates have been imported yet.
        </div>
    <?php else: ?>
        <?php foreach ($batches as $b):
            $rows_h = $history_rows_by_batch[$b['id']] ?? [];
            $active_ct = 0; foreach ($rows_h as $rh) if ($rh['status']==='active') $active_ct++;
            $batch_mode = !empty($rows_h) ? $rows_h[0]['match_type'] : 'invoice';
        ?>
        <div class="batch-block">
            <div class="batch-head" onclick="toggleBatch(<?php echo $b['id']; ?>)">
                <div style="display:flex;align-items:center;gap:10px;">
                    <i class="fa-solid fa-chevron-right chev" id="chev-<?php echo $b['id']; ?>"></i>
                    <span class="batch-code"><?php echo htmlspecialchars($b['batch_code']); ?></span>
                    <span class="badge badge-mode"><?php echo $batch_mode === 'tcode' ? 'Invoice + Route' : 'Invoice'; ?></span>
                    <span style="font-size:12px;color:#6b7280;"><?php echo htmlspecialchars($b['file_name']); ?></span>
                </div>
                <div class="batch-meta">
                    <span><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> <?php echo intval($b['changed_rows']); ?> updated</span>
                    <span><i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i> <?php echo intval($b['unmatched_rows']); ?> not found</span>
                    <span><i class="fa-solid fa-user"></i> <?php echo htmlspecialchars($b['created_by']); ?></span>
                    <span><i class="fa-solid fa-calendar"></i> <?php echo date('d M Y, h:i A', strtotime($b['created_at'])); ?></span>
                    <?php if ($active_ct > 0): ?>
                    <button class="btn btn-danger btn-sm" onclick="event.stopPropagation();reverseBatch(<?php echo $b['id']; ?>, '<?php echo htmlspecialchars($b['batch_code'], ENT_QUOTES); ?>')">
                        <i class="fa-solid fa-rotate-left"></i> Reverse Batch
                    </button>
                    <?php else: ?>
                    <span class="badge badge-reversed"><i class="fa-solid fa-rotate-left"></i> Fully Reversed</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="batch-body" id="batch-<?php echo $b['id']; ?>">
                <div class="dt-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Invoice Number</th>
                            <th>Customer</th>
                            <th class="tc">Old SR Code</th>
                            <th class="tc"></th>
                            <th class="tc">New SR Code</th>
                            <?php if ($batch_mode === 'tcode'): ?><th class="tc">Route Name (Old → New)</th><?php endif; ?>
                            <th class="tc">Status</th>
                            <th class="tc">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows_h as $rh): ?>
                        <tr id="hrow-<?php echo $rh['id']; ?>">
                            <td><span style="font-family:monospace;font-weight:700;"><?php echo htmlspecialchars($rh['match_type']==='tcode' ? $rh['t_code'] : $rh['invoice_num']); ?></span></td>
                            <td><?php echo htmlspecialchars($rh['customer_name'] ?: '—'); ?></td>
                            <td class="tc"><span class="old-code"><?php echo htmlspecialchars($rh['old_sr_code'] ?: '—'); ?></span></td>
                            <td class="tc"><i class="fa-solid fa-arrow-right arrow-ico"></i></td>
                            <td class="tc"><span class="new-code"><?php echo htmlspecialchars($rh['new_sr_code']); ?></span></td>
                            <?php if ($batch_mode === 'tcode'): ?>
                            <td class="tc">
                                <span class="old-route"><?php echo htmlspecialchars($rh['old_route_code'] ?: '—'); ?></span>
                                <i class="fa-solid fa-arrow-right arrow-ico"></i>
                                <span class="new-route"><?php echo htmlspecialchars($rh['new_route_code'] ?: '—'); ?></span>
                            </td>
                            <?php endif; ?>
                            <td class="tc" id="hstatus-<?php echo $rh['id']; ?>">
                                <?php if ($rh['status']==='active'): ?>
                                    <span class="badge badge-active"><i class="fa-solid fa-circle-check"></i> Active</span>
                                <?php else: ?>
                                    <span class="badge badge-reversed"><i class="fa-solid fa-rotate-left"></i> Reversed</span>
                                <?php endif; ?>
                            </td>
                            <td class="tc" id="haction-<?php echo $rh['id']; ?>">
                                <?php if ($rh['status']==='active'): ?>
                                    <button class="btn btn-secondary btn-sm" onclick="reverseRow(<?php echo $rh['id']; ?>)">
                                        <i class="fa-solid fa-rotate-left"></i> Reverse
                                    </button>
                                <?php else: ?>
                                    <span style="color:#d1d5db;font-size:11px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div id="__scu_toast"></div>

<script>
/* drag & drop + file name display */
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('srFileInput');
if (dropZone && fileInput) {
    fileInput.addEventListener('change', updateFileName);
    ['dragover','dragenter'].forEach(ev=>dropZone.addEventListener(ev, e=>{ e.preventDefault(); dropZone.classList.add('dragover'); }));
    ['dragleave','drop'].forEach(ev=>dropZone.addEventListener(ev, e=>{ e.preventDefault(); dropZone.classList.remove('dragover'); }));
    dropZone.addEventListener('drop', e=>{
        if (e.dataTransfer.files.length) { fileInput.files = e.dataTransfer.files; updateFileName(); }
    });
}
function updateFileName(){
    const disp = document.getElementById('fileNameDisplay');
    if (fileInput.files.length) {
        disp.textContent = '📄 ' + fileInput.files[0].name;
        disp.style.display = 'block';
    }
}
document.getElementById('uploadForm')?.addEventListener('submit', function(){
    const btn = document.getElementById('uploadBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Parsing...';
});

function toggleBatch(id){
    const body = document.getElementById('batch-'+id);
    const chev = document.getElementById('chev-'+id);
    body.classList.toggle('open');
    chev.classList.toggle('open');
}

function reverseRow(id){
    if (!confirm('Reverse this update? The old value(s) will be restored.')) return;
    fetch('?ajax=reverse_row&id='+id)
        .then(r=>r.json())
        .then(data=>{
            if (data.success) {
                document.getElementById('hstatus-'+id).innerHTML = '<span class="badge badge-reversed"><i class="fa-solid fa-rotate-left"></i> Reversed</span>';
                document.getElementById('haction-'+id).innerHTML = '<span style="color:#d1d5db;font-size:11px;">—</span>';
                showToast('Reversed successfully','success');
            } else {
                showToast(data.error || 'Failed to reverse','error');
            }
        })
        .catch(()=>showToast('Network error','error'));
}

function reverseBatch(batchId, code){
    if (!confirm('Reverse ALL active updates in batch '+code+'? This will restore all old values in this batch.')) return;
    fetch('?ajax=reverse_batch&batch_id='+batchId)
        .then(r=>r.json())
        .then(data=>{
            if (data.success) {
                showToast(data.count+' record(s) reversed','success');
                setTimeout(()=>location.reload(), 900);
            } else {
                showToast(data.error || 'Failed to reverse batch','error');
            }
        })
        .catch(()=>showToast('Network error','error'));
}

function showToast(msg, type){
    const t = document.getElementById('__scu_toast');
    t.style.background = type==='success' ? '#166534' : '#dc2626';
    t.textContent = msg;
    t.style.transform = 'translateX(-50%) translateY(0)';
    clearTimeout(t._tm);
    t._tm = setTimeout(()=>{ t.style.transform = 'translateX(-50%) translateY(80px)'; }, 3200);
}
</script>

<?php include 'footer.php'; ?>