<?php
/* ============================================================
   attendance_common.php
   Shared bootstrap for the attendance pages:
     • DB connection (reuses config.php) + new database/tables
     • helpers, .xlsx reader, per-person/day aggregation
     • shared styling (NORMAL sans-serif font) + top nav
   Included by attendance_import.php / _batches.php / _records.php
   ============================================================ */
error_reporting(0);
ini_set('display_errors', 0);
include 'config.php';
mysqli_report(MYSQLI_REPORT_OFF);

/* ── New database + tables (self-initialising) ── */
@mysqli_query($conn, "CREATE DATABASE IF NOT EXISTS attendance_system
    DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
@mysqli_select_db($conn, 'attendance_system');

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS attendance_imports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_name VARCHAR(255) NOT NULL,
    file_hash CHAR(64) NOT NULL,
    date_from DATE NULL, date_to DATE NULL,
    raw_events INT NOT NULL DEFAULT 0,
    attendance_rows INT NOT NULL DEFAULT 0,
    person_count INT NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'imported',
    imported_at DATETIME NOT NULL,
    UNIQUE KEY uq_hash (file_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS attendance_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_id INT NOT NULL,
    person_id VARCHAR(50) NOT NULL,
    person_name VARCHAR(255) NOT NULL,
    department VARCHAR(150) NULL,
    attendance_date DATE NOT NULL,
    time_in DATETIME NOT NULL, time_out DATETIME NOT NULL,
    in_mode VARCHAR(50) NULL, out_mode VARCHAR(50) NULL,
    work_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
    punch_count INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_person_date (person_id, attendance_date),
    KEY idx_date (attendance_date), KEY idx_import (import_id), KEY idx_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* upgrade older installs that pre-date the mode columns */
@mysqli_query($conn, "ALTER TABLE attendance_records ADD COLUMN in_mode VARCHAR(50) NULL AFTER time_out");
@mysqli_query($conn, "ALTER TABLE attendance_records ADD COLUMN out_mode VARCHAR(50) NULL AFTER in_mode");

/* ── Temp stash dir (so "Re-import" needs no re-upload) ── */
$TMP = __DIR__ . '/attendance_tmp';
if (!is_dir($TMP)) @mkdir($TMP, 0775, true);
foreach (glob($TMP.'/*.xlsx') as $f) { if (filemtime($f) < time()-3600) @unlink($f); }

/* ── Helpers ── */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function colIndex($letters){ $n=0; $letters=strtoupper($letters);
    for($i=0;$i<strlen($letters);$i++) $n=$n*26+(ord($letters[$i])-64); return $n-1; }
function fmtDur($sec){ $sec=max(0,(int)$sec); return intdiv($sec,3600).'h '.
    str_pad(intdiv($sec%3600,60),2,'0',STR_PAD_LEFT).'m'; }

/* Normalise a raw cell value into 'Y-m-d H:i:s'.
   The access-control export always writes the Time column as a plain
   TEXT string in Y-m-d H:i:s format (e.g. "2026-07-31 19:03:48").
   We parse that exact format explicitly first (no locale / format
   guessing), then fall back to Excel serial numbers, then finally to
   strtotime() as a last resort for anything unexpected. */
function normDT($v){
    $v=trim((string)$v); if($v==='') return '';

    /* 1) Exact expected format: Y-m-d H:i:s (e.g. 2026-07-31 19:03:48) */
    $d=DateTime::createFromFormat('Y-m-d H:i:s', $v);
    if ($d && $d->format('Y-m-d H:i:s')===$v) return $v;

    /* 2) Date-only, same style: Y-m-d */
    $d=DateTime::createFromFormat('Y-m-d', $v);
    if ($d && $d->format('Y-m-d')===$v) return $v.' 00:00:00';

    /* 3) Excel numeric date serial (no dashes/colons at all) */
    if (is_numeric($v) && strpos($v,'-')===false && strpos($v,':')===false){
        return date('Y-m-d H:i:s', (int)round((floatval($v)-25569)*86400));
    }

    /* 4) Last-resort generic parse for anything else encountered */
    $ts=strtotime($v); return $ts?date('Y-m-d H:i:s',$ts):'';
}

/* ── Dependency-free .xlsx reader (ZipArchive + DOM) ── */
function readXlsx($path){
    if (!class_exists('ZipArchive')) return [false,'PHP zip extension is not enabled on the server.'];
    $zip=new ZipArchive();
    if ($zip->open($path)!==true) return [false,'File is not a readable .xlsx workbook.'];
    $shared=[]; $ss=$zip->getFromName('xl/sharedStrings.xml');
    if ($ss!==false){ $d=new DOMDocument();
        if (@$d->loadXML($ss)) foreach ($d->getElementsByTagName('si') as $si){
            $t=''; foreach ($si->getElementsByTagName('t') as $x) $t.=$x->nodeValue; $shared[]=$t; } }
    $sheet=$zip->getFromName('xl/worksheets/sheet1.xml'); $zip->close();
    if ($sheet===false) return [false,'Could not find the first worksheet inside the file.'];
    $d=new DOMDocument();
    if (!@$d->loadXML($sheet)) return [false,'Worksheet XML could not be parsed.'];
    $rows=[];
    foreach ($d->getElementsByTagName('row') as $row){
        $cells=[];
        foreach ($row->getElementsByTagName('c') as $c){
            $ci=colIndex(preg_replace('/[0-9]+/','',$c->getAttribute('r')));
            $t=$c->getAttribute('t'); $val='';
            if ($t==='inlineStr'){ foreach ($c->getElementsByTagName('t') as $tt) $val.=$tt->nodeValue; }
            elseif ($t==='s'){ $v=$c->getElementsByTagName('v');
                if ($v->length){ $idx=(int)$v->item(0)->nodeValue; $val=$shared[$idx]??''; } }
            else { $v=$c->getElementsByTagName('v'); if ($v->length) $val=$v->item(0)->nodeValue; }
            $cells[$ci]=$val;
        }
        $rows[]=$cells;
    }
    return [true,$rows];
}

/* ── Raw rows → per-person/day (captures verification mode of in & out punch) ── */
function aggregate($rows){
    $hdr=-1; $m=[];
    foreach ($rows as $i=>$r){
        $labs=array_map(function($x){ return strtolower(trim((string)$x)); }, $r);
        if (in_array('person id',$labs) && in_array('time',$labs)){
            foreach ($labs as $ci=>$l) $m[$l]=$ci; $hdr=$i; break;
        }
    }
    if ($hdr<0) return [false,'Could not find the header row ("Person ID" … "Time").',[]];
    $ci_id=$m['person id']??null; $ci_name=$m['person name']??null;
    $ci_dept=$m['department']??null; $ci_mode=$m['verification mode']??null; $ci_time=$m['time']??null;
    if ($ci_id===null || $ci_time===null) return [false,'Required columns "Person ID" / "Time" missing.',[]];

    $agg=[]; $raw=0;
    for ($i=$hdr+1;$i<count($rows);$i++){
        $r=$rows[$i];
        $pid =trim((string)($r[$ci_id]??''));
        $name=$ci_name!==null?trim((string)($r[$ci_name]??'')):'';
        $dept=$ci_dept!==null?trim((string)($r[$ci_dept]??'')):'';
        $mode=$ci_mode!==null?trim((string)($r[$ci_mode]??'')):'';
        $tstr=normDT($r[$ci_time]??'');
        if ($pid===''||$tstr==='') continue;
        $ts=strtotime($tstr); if(!$ts) continue;
        $raw++; $date=date('Y-m-d',$ts); $k=$pid.'|'.$date;
        if (!isset($agg[$k])) $agg[$k]=['pid'=>$pid,'name'=>$name,'dept'=>$dept,'date'=>$date,
            'min'=>$ts,'max'=>$ts,'in_mode'=>$mode,'out_mode'=>$mode,'cnt'=>0];
        if ($ts<$agg[$k]['min']){ $agg[$k]['min']=$ts; $agg[$k]['in_mode']=$mode; }
        if ($ts>$agg[$k]['max']){ $agg[$k]['max']=$ts; $agg[$k]['out_mode']=$mode; }
        if ($agg[$k]['name']===''&&$name!=='') $agg[$k]['name']=$name;
        if ($agg[$k]['dept']===''&&$dept!=='') $agg[$k]['dept']=$dept;
        $agg[$k]['cnt']++;
    }
    if (!count($agg)) return [false,'No valid attendance rows found in the file.',[]];
    usort($agg, function($a,$b){
        if ($a['date']!==$b['date']) return strcmp($a['date'],$b['date']);
        return intval($a['pid'])<=>intval($b['pid']);
    });
    return [true,$raw,$agg];
}

/* ── Persist an aggregation (upsert; last-write-wins) ──
   Throws RuntimeException with the real MySQL error on any failure —
   the caller must catch this and show it, otherwise a failed DB write
   would look identical to a successful one (the preview page renders
   from the in-memory $agg array regardless of whether the DB write
   actually happened). */
function storeImport($conn,$fileName,$hash,$agg,$rawEvents){
    $now=date('Y-m-d H:i:s');
    $dates=array_column($agg,'date'); $from=min($dates); $to=max($dates);
    $persons=count(array_unique(array_column($agg,'pid'))); $rowsN=count($agg);
    $fn=mysqli_real_escape_string($conn,$fileName); $hh=mysqli_real_escape_string($conn,$hash);

    $ok1=mysqli_query($conn, "INSERT INTO attendance_imports
        (file_name,file_hash,date_from,date_to,raw_events,attendance_rows,person_count,status,imported_at)
        VALUES ('$fn','$hh','$from','$to',$rawEvents,$rowsN,$persons,'imported','$now')
        ON DUPLICATE KEY UPDATE file_name=VALUES(file_name),date_from=VALUES(date_from),
            date_to=VALUES(date_to),raw_events=VALUES(raw_events),attendance_rows=VALUES(attendance_rows),
            person_count=VALUES(person_count),status='imported',imported_at=VALUES(imported_at)");
    if (!$ok1) throw new RuntimeException('Could not save the batch (attendance_imports): '.mysqli_error($conn));

    $importId=(int)mysqli_insert_id($conn);
    if (!$importId){ $q=mysqli_query($conn,"SELECT id FROM attendance_imports WHERE file_hash='$hh' LIMIT 1");
        if ($q&&($r=mysqli_fetch_assoc($q))) $importId=(int)$r['id']; }
    if (!$importId) throw new RuntimeException('Batch was saved but its ID could not be resolved.');

    $stmt=mysqli_prepare($conn, "INSERT INTO attendance_records
        (import_id,person_id,person_name,department,attendance_date,time_in,time_out,in_mode,out_mode,work_hours,punch_count,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE import_id=VALUES(import_id),person_name=VALUES(person_name),
            department=VALUES(department),time_in=VALUES(time_in),time_out=VALUES(time_out),
            in_mode=VALUES(in_mode),out_mode=VALUES(out_mode),work_hours=VALUES(work_hours),
            punch_count=VALUES(punch_count),created_at=VALUES(created_at)");
    if (!$stmt) throw new RuntimeException('Could not prepare the attendance_records insert: '.mysqli_error($conn));

    $inserted=0; $failed=0; $lastErr='';
    foreach ($agg as $a){
        $in=date('Y-m-d H:i:s',$a['min']); $out=date('Y-m-d H:i:s',$a['max']);
        $hrs=round(($a['max']-$a['min'])/3600,2);
        mysqli_stmt_bind_param($stmt,'issssssssdis',
            $importId,$a['pid'],$a['name'],$a['dept'],$a['date'],$in,$out,
            $a['in_mode'],$a['out_mode'],$hrs,$a['cnt'],$now);
        if (mysqli_stmt_execute($stmt)) $inserted++;
        else { $failed++; $lastErr=mysqli_stmt_error($stmt); }
    }
    mysqli_stmt_close($stmt);

    if ($inserted===0 && $rowsN>0){
        throw new RuntimeException("All $rowsN attendance_records rows failed to insert. MySQL said: ".$lastErr);
    }

    return ['id'=>$importId,'from'=>$from,'to'=>$to,'persons'=>$persons,'rows'=>$rowsN,'raw'=>$rawEvents,
             'inserted'=>$inserted,'failed'=>$failed,'lastErr'=>$lastErr];
}

/* ── Shared page chrome ── */
function att_head($active){
    include 'header.php';
    ?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
:root{
    --font:'Inter',-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
    --ink:#111827;--muted:#6b7280;--bdr:#e2e5eb;--bg:#f4f5f8;
    --sur:#fff;--acc:#0f3460;--acc2:#16213e;
    --red:#c0392b;--grn:#166534;--amb:#92600a;
    --alt:#f8f9fc;--sub:#eef1f7;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--font);background:var(--bg);color:var(--ink);
     font-variant-numeric:tabular-nums;}
.shell{padding:16px;max-width:1400px;margin:0 auto;}

/* nav */
.nav{background:var(--sur);border:1px solid var(--bdr);border-radius:12px;
    padding:10px 14px;margin-bottom:16px;display:flex;align-items:center;gap:6px;
    box-shadow:0 1px 4px rgba(0,0,0,.05);flex-wrap:wrap;}
.nav .brand{font-weight:700;font-size:15px;color:var(--acc);margin-right:12px;
    display:flex;align-items:center;gap:8px;}
.nav .brand small{font-weight:500;color:var(--muted);font-size:11px;}
.nav a{text-decoration:none;color:var(--muted);font-size:13px;font-weight:600;
    padding:8px 14px;border-radius:8px;transition:all .15s;}
.nav a:hover{background:var(--sub);color:var(--acc);}
.nav a.on{background:var(--acc);color:#fff;}

/* cards / forms */
.card{background:var(--sur);border:1px solid var(--bdr);border-radius:12px;
    padding:16px 18px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.card .ttl{font-size:13px;font-weight:700;color:var(--acc);margin-bottom:12px;
    display:flex;align-items:center;gap:8px;}
.frm{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:11px;font-weight:600;color:var(--muted);}
.fg input,.fg select{padding:8px 11px;border:1.5px solid var(--bdr);border-radius:8px;
    font-size:13px;font-family:var(--font);outline:none;background:var(--sur);min-width:150px;}
.fg input:focus,.fg select:focus{border-color:var(--acc);}
.fg input[type=file]{padding:6px;cursor:pointer;min-width:300px;}
.btn{border:none;border-radius:8px;padding:9px 20px;font-family:var(--font);font-size:13px;
    font-weight:600;cursor:pointer;color:#fff;background:var(--acc);transition:all .15s;text-decoration:none;
    display:inline-flex;align-items:center;gap:6px;}
.btn:hover{background:var(--acc2);}
.btn.grn{background:#1a7340;} .btn.grn:hover{background:#145a31;}
.btn.amb{background:#8a5a00;} .btn.amb:hover{background:#6e4700;}
.btn.red{background:var(--red);} .btn.red:hover{background:#9c2d21;}
.btn.gr{background:#eef1f7;color:var(--acc);} .btn.gr:hover{background:#e2e6f2;}
.btn.sm{padding:6px 12px;font-size:12px;}
.hint{font-size:11.5px;color:var(--muted);width:100%;margin-top:2px;line-height:1.5;}

/* flash */
.flash{border-radius:10px;padding:11px 16px;margin-bottom:16px;font-size:13px;font-weight:500;
    display:flex;align-items:center;gap:10px;border:1px solid;}
.flash.ok{background:#ecfdf3;border-color:#a7f3cf;color:#166534;}
.flash.err{background:#fef2f2;border-color:#fecaca;color:#b91c1c;}
.flash.warn{background:#fffbeb;border-color:#fde68a;color:#92600a;}
.flash b{font-size:15px;}

/* stats */
.stats{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;}
.stat{background:var(--sur);border:1px solid var(--bdr);border-radius:11px;padding:12px 20px;
    min-width:130px;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.stat span{font-size:11px;font-weight:600;color:var(--muted);display:block;margin-bottom:2px;}
.stat strong{font-size:22px;color:var(--acc);font-weight:700;}

/* table */
.rw{background:var(--sur);border:1px solid var(--bdr);border-radius:12px;overflow:hidden;
    box-shadow:0 1px 6px rgba(0,0,0,.06);margin-bottom:16px;}
.ph{padding:13px 18px;border-bottom:1px solid var(--bdr);display:flex;justify-content:space-between;
    align-items:center;flex-wrap:wrap;gap:8px;}
.ph .co{font-size:15px;font-weight:700;color:var(--acc);}
.ph .me{font-size:12px;color:var(--muted);margin-top:3px;display:flex;gap:16px;flex-wrap:wrap;}
.ph .me strong{color:var(--ink);}
.ph-btns{display:flex;gap:8px;flex-shrink:0;}
.to{overflow-x:auto;}
table.ft{width:100%;border-collapse:collapse;font-size:12.5px;}
table.ft th,table.ft td{border-bottom:1px solid #eceef4;padding:8px 12px;white-space:nowrap;text-align:left;}
table.ft thead th{background:var(--acc);color:#fff;font-size:11px;font-weight:600;
    letter-spacing:.3px;position:sticky;top:0;z-index:5;border-bottom:none;}
table.ft thead th.ctr,table.ft td.ctr{text-align:center;}
table.ft thead th.num,table.ft td.num{text-align:right;}
table.ft td.nm{font-weight:600;color:#1e293b;}
table.ft td.mut{color:var(--muted);}
table.ft td.warn{color:var(--red);font-weight:600;}
table.ft tbody tr:nth-child(even) td{background:var(--alt);}
table.ft tbody tr:hover td{background:#f0f3fa;}
table.ft tr.tot td{background:var(--sub)!important;font-weight:700;color:var(--acc);}
.pill{display:inline-block;font-size:10.5px;font-weight:600;padding:2px 9px;border-radius:20px;
    letter-spacing:.2px;}
.pill.face{background:#e0edff;color:#1e40af;}
.pill.fp{background:#e6f4ea;color:#166534;}
.pill.oth{background:#f1f2f6;color:#4b5563;}
.badge{display:inline-block;background:#dcfce7;color:#166534;font-size:10.5px;font-weight:600;
    padding:2px 10px;border-radius:20px;}
.es{text-align:center;padding:54px;color:var(--muted);}
.es span{font-size:38px;display:block;margin-bottom:10px;}
.es p{font-size:14px;font-weight:600;}

@media print{
    @page{size:A4 landscape;margin:10mm 8mm;}
    *{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .nav,.card,.stats,.flash,.ph-btns,header,nav,.sidebar,footer{display:none!important;}
    body{background:#fff!important;} .shell{padding:0!important;max-width:none;}
    .print-header{display:block!important;text-align:center;border-bottom:2px solid #000;
        padding-bottom:4mm;margin-bottom:4mm;}
    .print-header h1{font-size:14pt;font-weight:800;text-transform:uppercase;letter-spacing:1px;}
    .print-header p{font-size:9pt;color:#333;}
    .rw{box-shadow:none!important;border:none!important;}
    .ph{border-bottom:1px solid #000!important;padding:0 0 3mm 0!important;}
    table.ft{font-size:7.5pt!important;} table.ft th,table.ft td{border:.5px solid #000!important;
        padding:2px 4px!important;color:#000!important;background:#fff!important;position:static!important;}
    table.ft thead th{background:#1a1a1a!important;color:#fff!important;}
    table.ft tbody tr:nth-child(even) td{background:#f5f5f5!important;}
    table.ft tr.tot td{background:#ccc!important;color:#000!important;}
    .pill{border:.5px solid #666;background:#fff!important;color:#000!important;}
    thead{display:table-header-group;} tr{page-break-inside:avoid;}
}
.print-header{display:none;}
</style>

<div class="shell">
<div class="nav">
    <div class="brand">Yelo Attendance <small>Access Records</small></div>
    <a href="attendance_import.php"  class="<?= $active==='import' ?'on':'' ?>">Import</a>
    <a href="attendance_records.php" class="<?= $active==='records'?'on':'' ?>">Attendance</a>
    <a href="attendance_batches.php" class="<?= $active==='batches'?'on':'' ?>">Batches</a>
</div>
<?php
}

function att_foot(){
    echo "</div>\n";
    include 'footer.php';
}

/* Render a verification-mode pill */
function modePill($mode){
    $m=strtolower(trim((string)$mode));
    if ($m==='') return '<span class="mut">—</span>';
    if (strpos($m,'face')!==false) $cls='face';
    elseif (strpos($m,'finger')!==false) $cls='fp';
    else $cls='oth';
    return '<span class="pill '.$cls.'">'.h($mode).'</span>';
}