<?php
/**
 * process_credit_bills.php
 * NO third-party libraries — uses PHP's built-in ZipArchive + SimpleXML
 * to read .xlsx files directly (xlsx = ZIP of XML files).
 */

ob_start();
error_reporting(0);
ini_set('display_errors', 0);
include 'config.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST only']); exit;
}

$action = trim($_POST['action'] ?? '');

/* ════════════════════════ HELPERS ════════════════════════ */
function esc($c, $v) { return mysqli_real_escape_string($c, trim((string)($v ?? ''))); }

function colLetterToIndex($col) {
    $col = strtoupper(trim($col)); $index = 0; $len = strlen($col);
    for ($i = 0; $i < $len; $i++) $index = $index * 26 + (ord($col[$i]) - ord('A') + 1);
    return $index;
}

function parseCellRef($ref) {
    if (!preg_match('/^([A-Z]+)(\d+)$/i', trim($ref), $m)) return [0,0];
    return [colLetterToIndex($m[1]), (int)$m[2]];
}

function excelSerialToDate($serial) {
    if (!is_numeric($serial) || $serial <= 0) return null;
    $serial = (int)$serial;
    $base   = ($serial >= 60) ? $serial - 1 : $serial;
    return date('Y-m-d', mktime(0,0,0,1,1,1900) + ($base - 1) * 86400);
}

function parseDate($value) {
    if ($value === null || $value === '') return null;
    if (is_numeric($value) && (float)$value > 1) return excelSerialToDate((int)$value);
    $s = trim((string)$value);
    if ($s === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) return substr($s, 0, 10);
    foreach (['d/m/Y','m/d/Y','d-m-Y','d.m.Y','Y/m/d'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $s);
        if ($d) return $d->format('Y-m-d');
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}

/* ════════════════════════ XLSX PARSER ════════════════════════
   .xlsx = ZIP of XML files. No third-party lib needed.
   Uses: ZipArchive (php-zip), SimpleXML (both standard on all hosts)
══════════════════════════════════════════════════════════════ */
function parseXlsx($filePath) {
    if (!class_exists('ZipArchive'))
        return ['error' => 'ZipArchive PHP extension not enabled. Enable php-zip in php.ini.'];

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true)
        return ['error' => 'Cannot open file as XLSX. Ensure it is a valid .xlsx file (not .xls or .csv).'];

    /* Shared strings */
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = @simplexml_load_string($ssXml);
        if ($ss) {
            foreach ($ss->si as $si) {
                if (isset($si->t)) { $sharedStrings[] = (string)$si->t; }
                else { $t=''; foreach($si->r as $r) if(isset($r->t)) $t.=(string)$r->t; $sharedStrings[]=$t; }
            }
        }
    }

    /* Date xf indexes from styles.xml */
    $dateXfIndexes = [];
    $stylesXml = $zip->getFromName('xl/styles.xml');
    if ($stylesXml !== false) {
        $st = @simplexml_load_string($stylesXml);
        if ($st) {
            $dateFmtIds = array_flip([14,15,16,17,22]);
            if (isset($st->numFmts->numFmt)) {
                foreach ($st->numFmts->numFmt as $nf) {
                    $id = (int)$nf['numFmtId']; $fmt = strtolower((string)$nf['formatCode']);
                    if (preg_match('/[ymd]/', $fmt) && !preg_match('/[#0]/', $fmt)) $dateFmtIds[$id] = true;
                }
            }
            if (isset($st->cellXfs->xf)) {
                $xi = 0;
                foreach ($st->cellXfs->xf as $xf) { if (isset($dateFmtIds[(int)$xf['numFmtId']])) $dateXfIndexes[$xi]=true; $xi++; }
            }
        }
    }

    /* Worksheet */
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        $wbXml = $zip->getFromName('xl/workbook.xml');
        if ($wbXml !== false) {
            $wb = @simplexml_load_string($wbXml);
            if ($wb && isset($wb->sheets->sheet)) {
                $sid = (string)$wb->sheets->sheet[0]['sheetId'];
                $sheetXml = $zip->getFromName("xl/worksheets/sheet{$sid}.xml");
            }
        }
    }
    $zip->close();

    if ($sheetXml === false) return ['error' => 'Could not find worksheet data inside the Excel file.'];

    $ws = @simplexml_load_string($sheetXml);
    if (!$ws) return ['error' => 'Could not parse worksheet XML. File may be corrupt.'];

    /* Build grid [rowNum][colNum] = value */
    $grid = [];
    foreach ($ws->sheetData->row as $row) {
        $rowNum = (int)$row['r'];
        foreach ($row->c as $cell) {
            list($colNum,) = parseCellRef((string)$cell['r']);
            $type  = (string)$cell['t'];
            $style = isset($cell['s']) ? (int)$cell['s'] : -1;
            $v     = isset($cell->v) ? (string)$cell->v : null;
            $value = null;
            if ($type === 's') {
                $value = $sharedStrings[(int)$v] ?? '';
            } elseif ($type === 'b') {
                $value = $v === '1' ? 'TRUE' : 'FALSE';
            } elseif ($type === 'str' || $type === 'inlineStr') {
                $value = isset($cell->is->t) ? (string)$cell->is->t : $v;
            } else {
                if ($v !== null) {
                    $num = (float)$v;
                    $value = ($style >= 0 && isset($dateXfIndexes[$style]))
                        ? excelSerialToDate((int)$num)
                        : ($num == (int)$num ? (int)$num : $num);
                }
            }
            $grid[$rowNum][$colNum] = $value;
        }
    }

    if (empty($grid)) return ['error' => 'The worksheet appears to be empty.'];

    /* Find header row */
    ksort($grid);
    $dataStartRow = null;
    foreach ($grid as $rowNum => $cols) {
        foreach ($cols as $val) {
            $s = strtolower((string)$val);
            if (strpos($s,'bill number')!==false || strpos($s,'bill no')!==false) {
                $dataStartRow = $rowNum + 1; break 2;
            }
        }
    }

    if ($dataStartRow === null)
        return ['error' => 'Header row not found. File must contain a row with "Bill Number" or "Bill No" as a column heading.'];

    /* Extract rows */
    $rows = [];
    foreach ($grid as $rowNum => $cols) {
        if ($rowNum < $dataStartRow) continue;
        $get = fn(int $i) => $cols[$i] ?? null;
        $bill_no = trim((string)($get(6) ?? ''));
        $t_code  = trim((string)($get(4) ?? ''));
        if ($bill_no === '') continue;
        $rows[] = [
            'salesperson_code'  => trim((string)($get(2)  ?? '')),
            't_code'            => $t_code,
            'bill_no'           => $bill_no,
            'bill_date'         => parseDate($get(8)) ?? '',
            'party_name'        => trim((string)($get(10) ?? '')),
            'final_bill_amount' => round((float)($get(22) ?? 0), 2),
            'delivery_date'     => parseDate($get(26)) ?? '',
        ];
    }

    if (empty($rows)) return ['error' => 'No data rows found after the header. Check the file column layout.'];
    return ['rows' => $rows];
}

/* ════════════════════════ ENSURE COLUMNS ════════════════════════ */
$r = mysqli_query($conn,"SHOW COLUMNS FROM credit_requests LIKE 'credit_bill_no'");
if (!$r || mysqli_num_rows($r)===0) mysqli_query($conn,"ALTER TABLE credit_requests ADD COLUMN credit_bill_no VARCHAR(100) NOT NULL DEFAULT '' AFTER reason");
$r2 = mysqli_query($conn,"SHOW COLUMNS FROM field_summary_details LIKE 'is_credit_bill'");
if (!$r2 || mysqli_num_rows($r2)===0) mysqli_query($conn,"ALTER TABLE field_summary_details ADD COLUMN is_credit_bill TINYINT(1) NOT NULL DEFAULT 0");

/* ════════════════════════ ACTION: PREVIEW ════════════════════════ */
if ($action === 'preview') {
    $errCodes = [1=>'File too large (server limit)',2=>'File too large (form limit)',3=>'Partial upload',4=>'No file uploaded',6=>'Missing tmp folder',7=>'Cannot write to disk',8=>'PHP extension blocked upload'];
    if (empty($_FILES['excel_file']['tmp_name']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['excel_file']['error'] ?? 4;
        echo json_encode(['success'=>false,'error'=>$errCodes[$code] ?? "Upload error $code"]); exit;
    }

    $safePath = sys_get_temp_dir().'/cb_'.time().'_'.mt_rand(1000,9999).'.xlsx';
    if (!move_uploaded_file($_FILES['excel_file']['tmp_name'], $safePath)) {
        echo json_encode(['success'=>false,'error'=>'Server cannot save uploaded file. Check tmp directory permissions.']); exit;
    }

    $parsed = parseXlsx($safePath);
    @unlink($safePath);

    if (isset($parsed['error'])) { echo json_encode(['success'=>false,'error'=>$parsed['error']]); exit; }

    $rows_out = []; $new_fs_dates = [];
    foreach ($parsed['rows'] as $r) {
        $ebn = esc($conn, $r['bill_no']);
        if (!$r['delivery_date']) {
            $rows_out[] = array_merge($r,['status'=>'fail','error'=>'Missing delivery date (Col 26)','fs_exists'=>false,'fs_code'=>'']); continue;
        }
        if ($r['final_bill_amount'] <= 0) {
            $rows_out[] = array_merge($r,['status'=>'fail','error'=>'Final bill amount is zero or missing (Col 22)','fs_exists'=>false,'fs_code'=>'']); continue;
        }
        $dup = mysqli_query($conn,"SELECT id FROM credit_requests WHERE invoice_num='$ebn' LIMIT 1");
        if ($dup && mysqli_num_rows($dup)>0) {
            $rows_out[] = array_merge($r,['status'=>'skip','skip_reason'=>'Bill already has a credit request','fs_exists'=>true,'fs_code'=>'']); continue;
        }
        $dup2 = mysqli_query($conn,"SELECT id FROM field_summary_details WHERE invoice_num='$ebn' AND is_credit_bill=1 LIMIT 1");
        if ($dup2 && mysqli_num_rows($dup2)>0) {
            $rows_out[] = array_merge($r,['status'=>'skip','skip_reason'=>'Bill already imported in field summary','fs_exists'=>true,'fs_code'=>'']); continue;
        }
        $edate = esc($conn,$r['delivery_date']);
        $fs_r  = mysqli_query($conn,"SELECT id,field_summary_code FROM field_summary WHERE delivery_date='$edate' ORDER BY id LIMIT 1");
        $fs_exists=false; $fs_code='';
        if ($fs_r && mysqli_num_rows($fs_r)>0) {
            $fsr=$mysqli_fetch_assoc($fs_r); $fs_exists=true; $fs_code=$fsr['field_summary_code'];
        } else {
            $proposed='DATECREDIT-'.str_replace('-','',$r['delivery_date']);
            $ep=esc($conn,$proposed);
            $ex=mysqli_query($conn,"SELECT id,field_summary_code FROM field_summary WHERE field_summary_code='$ep' LIMIT 1");
            if ($ex && mysqli_num_rows($ex)>0) { $exr=mysqli_fetch_assoc($ex); $fs_exists=true; $fs_code=$exr['field_summary_code']; }
            else { $fs_code=$proposed; if(!in_array($r['delivery_date'],$new_fs_dates)) $new_fs_dates[]=$r['delivery_date']; }
        }
        $rows_out[] = array_merge($r,['status'=>'new','fs_exists'=>$fs_exists,'fs_code'=>$fs_code]);
    }
    echo json_encode(['success'=>true,'rows'=>$rows_out,'new_fs_dates'=>$new_fs_dates]); exit;
}

/* ════════════════════════ ACTION: IMPORT_ROW ════════════════════════ */
if ($action === 'import_row') {
    $delivery_date     = trim($_POST['delivery_date']     ?? '');
    $t_code            = trim($_POST['t_code']            ?? '');
    $bill_no           = trim($_POST['bill_no']           ?? '');
    $bill_date         = trim($_POST['bill_date']         ?? '') ?: null;
    $party_name        = trim($_POST['party_name']        ?? '');
    $final_bill_amount = round((float)($_POST['final_bill_amount'] ?? 0), 2);

    if (!$delivery_date || !$bill_no || !$t_code) { echo json_encode(['success'=>false,'error'=>'Missing required fields']); exit; }
    if ($final_bill_amount <= 0) { echo json_encode(['success'=>false,'error'=>'Final bill amount must be > 0']); exit; }

    $ebn    = esc($conn,$bill_no);
    $edate  = esc($conn,$delivery_date);
    $etcode = esc($conn,$t_code);
    $eparty = esc($conn,$party_name);

    $dup = mysqli_query($conn,"SELECT id FROM credit_requests WHERE invoice_num='$ebn' LIMIT 1");
    if ($dup && mysqli_num_rows($dup)>0) { echo json_encode(['success'=>true,'skipped'=>true,'reason'=>'Already in credit_requests']); exit; }
    $dup2 = mysqli_query($conn,"SELECT id FROM field_summary_details WHERE invoice_num='$ebn' AND is_credit_bill=1 LIMIT 1");
    if ($dup2 && mysqli_num_rows($dup2)>0) { echo json_encode(['success'=>true,'skipped'=>true,'reason'=>'Already imported']); exit; }

    /* Find or create field_summary */
    $fs_r = mysqli_query($conn,"SELECT id,field_summary_code FROM field_summary WHERE delivery_date='$edate' ORDER BY id LIMIT 1");
    $fs_id=0; $fs_code='';
    if ($fs_r && mysqli_num_rows($fs_r)>0) {
        $fsr=mysqli_fetch_assoc($fs_r); $fs_id=(int)$fsr['id']; $fs_code=$fsr['field_summary_code'];
    } else {
        $proposed='DATECREDIT-'.str_replace('-','',$delivery_date);
        $ep=esc($conn,$proposed);
        $fs_ex=mysqli_query($conn,"SELECT id FROM field_summary WHERE field_summary_code='$ep' LIMIT 1");
        if ($fs_ex && mysqli_num_rows($fs_ex)>0) {
            $fser=mysqli_fetch_assoc($fs_ex); $fs_id=(int)$fser['id']; $fs_code=$proposed;
        } else {
            $avail=[]; $cr=mysqli_query($conn,"SHOW COLUMNS FROM field_summary");
            if($cr) while($c=mysqli_fetch_assoc($cr)) $avail[$c['Field']]=true;
            $ic=['field_summary_code','delivery_date','route','sr_code'];
            $iv=["'$ep'","'$edate'","'CREDIT'","'CREDIT'"];
            foreach(['status'=>"'pending'",'visit_date'=>"'$edate'",'summary_date'=>"'$edate'"] as $col=>$val) {
                if(isset($avail[$col])){$ic[]=$col;$iv[]=$val;}
            }
            if (!mysqli_query($conn,"INSERT INTO field_summary (".implode(',',$ic).") VALUES (".implode(',',$iv).")")) {
                echo json_encode(['success'=>false,'error'=>'Cannot create field_summary: '.mysqli_error($conn)]); exit;
            }
            $fs_id=mysqli_insert_id($conn); $fs_code=$proposed;
        }
    }

    /* Insert field_summary_details */
    if (!mysqli_query($conn,"INSERT INTO field_summary_details
        (field_summary_id,invoice_num,t_code,customer_name,route,
         net_value,adjust_net_value,ikea_value,payment_status,updated,is_special_credit,is_credit_bill)
        VALUES ($fs_id,'$ebn','$etcode','$eparty','CREDIT',
         $final_bill_amount,$final_bill_amount,$final_bill_amount,'Pending',0,0,1)")) {
        echo json_encode(['success'=>false,'error'=>'Cannot insert detail: '.mysqli_error($conn)]); exit;
    }
    $detail_id=mysqli_insert_id($conn);

    /* Insert credit_requests */
    $reason=esc($conn,'Credit Bill Import');
    if (!mysqli_query($conn,"INSERT INTO credit_requests
        (field_summary_id,field_summary_detail_id,t_code,invoice_num,credit_amount,reason,credit_bill_no,status)
        VALUES ($fs_id,$detail_id,'$etcode','$ebn',$final_bill_amount,'$reason','$ebn','pending')")) {
        mysqli_query($conn,"DELETE FROM field_summary_details WHERE id=$detail_id");
        echo json_encode(['success'=>false,'error'=>'Cannot insert credit_request: '.mysqli_error($conn)]); exit;
    }

    echo json_encode(['success'=>true,'field_summary_id'=>$fs_id,'fs_code'=>$fs_code,'detail_id'=>$detail_id,'credit_request_id'=>mysqli_insert_id($conn),'credit_amount'=>$final_bill_amount]);
    exit;
}

echo json_encode(['success'=>false,'error'=>'Unknown action']);