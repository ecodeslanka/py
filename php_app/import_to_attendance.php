<?php
// ── Yelo Group HMS — Import Access Batch → Attendance ────────────────────────
// Pushes an imported access-records batch (attendance_records)
// into the original `attendance` table. Matches each person to employees.id
// (or employees.employee_id), skips days already marked, and logs the push as a
// reversible batch.

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/attendance_error.log');

if (file_exists(__DIR__ . '/config.php')) {
    include __DIR__ . '/config.php';
} else {
    $host = '127.0.0.1';
    $db   = 'u645685294_ylerp';
    $user = 'your_db_user';
    $pass = 'your_db_pass';
    $conn = mysqli_connect($host, $user, $pass, $db);
}

function is_ajax() {
    return (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' &&
              (isset($_POST['ajax_import']) || isset($_POST['ajax_reverse'])))
        || isset($_GET['ajax_preview']) || isset($_GET['ajax_history']);
}

// Find the database that actually holds the imported access batches. On shared
// hosting a separate `attendance_system` DB often can't be created, so the import
// pages fall back to the current database — we detect whichever one has the data.
function pickAttDb($conn, $orig) {
    $best = null; $bestN = -1;
    foreach (['attendance_system', $orig] as $db) {
        if ($db === '') continue;
        $de = mysqli_real_escape_string($conn, $db);
        $t = @mysqli_query($conn, "SELECT COUNT(*) c FROM information_schema.tables
             WHERE table_schema='$de' AND table_name='attendance_imports'");
        $exists = ($t && ($row = mysqli_fetch_assoc($t)) && (int)$row['c'] > 0);
        if (!$exists) continue;
        $cq = @mysqli_query($conn, "SELECT COUNT(*) c FROM `$db`.attendance_imports");
        $n = ($cq && ($cr = mysqli_fetch_assoc($cq))) ? (int)$cr['c'] : 0;
        if ($n > $bestN) { $bestN = $n; $best = $db; }
    }
    if ($best !== null) return $best;
    // No data yet anywhere — prefer a real attendance_system DB if we can make one.
    @mysqli_query($conn, "CREATE DATABASE IF NOT EXISTS attendance_system
        DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $s = @mysqli_query($conn, "SELECT SCHEMA_NAME FROM information_schema.schemata
         WHERE SCHEMA_NAME='attendance_system'");
    return ($s && mysqli_num_rows($s) > 0) ? 'attendance_system' : $orig;
}

if (!$conn || mysqli_connect_errno()) {
    if (is_ajax()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . mysqli_connect_error()]);
        exit;
    }
    die('<h3 style="color:red;font-family:sans-serif;padding:20px;">Database connection failed: ' . htmlspecialchars(mysqli_connect_error()) . '</h3>');
}
mysqli_set_charset($conn, 'utf8mb4');

// IMPORTANT (PHP 8.1+): mysqli defaults to throwing exceptions on any failed
// query. This page runs CREATE DATABASE / cross-database statements that may
// legitimately fail (privileges, not-yet-created tables); without this line an
// uncaught exception + display_errors=0 produces a BLANK WHITE PAGE. Turning
// reporting OFF makes failed queries return false, which is guarded everywhere.
mysqli_report(MYSQLI_REPORT_OFF);

// original (target) database name — where employees + attendance live
$ORIG_DB = '';
$r = mysqli_query($conn, "SELECT DATABASE() d");
if ($r && ($x = mysqli_fetch_assoc($r))) $ORIG_DB = $x['d'];

// Resolve where the attendance schema lives (see pickAttDb above).
$ATT = pickAttDb($conn, $ORIG_DB);
if (!defined('ATT_DB')) define('ATT_DB', $ATT);

// ── Ensure the schema tables exist in the resolved database ──────────────────
// Source: import batches (one row per uploaded Excel file)
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS ".ATT_DB.".attendance_imports (
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

// Source: one row per person per date (first-in / last-out + verification modes)
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS ".ATT_DB.".attendance_records (
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

// Target: the original HMS attendance table (created only if it does not exist;
// an existing table with more columns is left completely untouched by IF NOT EXISTS).
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    att_date DATE NOT NULL,
    check_in DATETIME NULL,
    check_out DATETIME NULL,
    KEY idx_emp_date (employee_id, att_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Push log — lets each import into `attendance` be reversed.
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS ".ATT_DB.".attendance_push_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_import_id INT NOT NULL, source_file VARCHAR(255) NULL,
    match_field VARCHAR(20) NOT NULL, target_db VARCHAR(64) NOT NULL,
    inserted_count INT NOT NULL DEFAULT 0, skipped_count INT NOT NULL DEFAULT 0,
    unmatched_count INT NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'active',
    pushed_at DATETIME NOT NULL, reversed_at DATETIME NULL, KEY idx_source (source_import_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS ".ATT_DB.".attendance_push_rows (
    id INT AUTO_INCREMENT PRIMARY KEY, push_id INT NOT NULL,
    employee_id INT NOT NULL, att_date DATE NOT NULL, attendance_id INT NULL,
    KEY idx_push (push_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Build a map: matchKey → [emp_id,name]; flags collisions as ambiguous ──────
function buildEmployeeMap($conn, $field) {
    $map = []; $ambiguous = [];
    $res = mysqli_query($conn, "SELECT id, employee_id, employee_full_name FROM employees");
    if (!$res) return [[], []];
    while ($e = mysqli_fetch_assoc($res)) {
        if ($field === 'employee_id') {
            $key = strtolower(trim((string)$e['employee_id']));
        } else { // 'id'
            $key = (string)(int)$e['id'];
        }
        if ($key === '') continue;
        if (isset($map[$key])) { $ambiguous[$key] = true; }
        $map[$key] = ['id' => (int)$e['id'], 'name' => $e['employee_full_name']];
    }
    return [$map, $ambiguous];
}
function matchKey($field, $person_id) {
    return ($field === 'employee_id')
        ? strtolower(trim((string)$person_id))
        : (string)(int)$person_id;
}

// ── Resolve a batch against employees + existing attendance ───────────────────
// Returns [rows[], stats], where each row: person_id, person_name, date,
// time_in, time_out, punch_count, emp_id|null, emp_name, state(new|skip|unmatched|ambiguous)
function resolveBatch($conn, $import_id, $field) {
    [$map, $amb] = buildEmployeeMap($conn, $field);

    // source rows (live rows owned by this batch)
    $rows = [];
    $q = mysqli_query($conn, "SELECT person_id,person_name,attendance_date,time_in,time_out,punch_count
        FROM ".ATT_DB.".attendance_records WHERE import_id=" . (int)$import_id . "
        ORDER BY attendance_date, CAST(person_id AS UNSIGNED), person_id");
    if ($q) while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;

    // preload existing attendance for matched employees within the batch date span
    $dates = array_column($rows, 'attendance_date');
    $existing = [];
    if (count($dates)) {
        $from = min($dates); $to = max($dates);
        $from = mysqli_real_escape_string($conn, $from);
        $to   = mysqli_real_escape_string($conn, $to);
        $er = mysqli_query($conn, "SELECT employee_id, att_date FROM attendance
                                   WHERE att_date BETWEEN '$from' AND '$to'");
        if ($er) while ($x = mysqli_fetch_assoc($er)) $existing[$x['employee_id'] . '|' . $x['att_date']] = true;
    }

    $out = []; $st = ['new'=>0,'skip'=>0,'unmatched'=>0,'ambiguous'=>0];
    $unmatchedIds = [];
    foreach ($rows as $r) {
        $key = matchKey($field, $r['person_id']);
        $state=''; $emp_id=null; $emp_name='';
        if (isset($amb[$key])) {
            $state='ambiguous'; $st['ambiguous']++;
        } elseif (!isset($map[$key])) {
            $state='unmatched'; $st['unmatched']++;
            $unmatchedIds[$r['person_id']] = $r['person_name'];
        } else {
            $emp_id = $map[$key]['id']; $emp_name = $map[$key]['name'];
            if (isset($existing[$emp_id . '|' . $r['attendance_date']])) {
                $state='skip'; $st['skip']++;
            } else {
                $state='new'; $st['new']++;
            }
        }
        $r['emp_id']=$emp_id; $r['emp_name']=$emp_name; $r['state']=$state;
        $out[] = $r;
    }
    return [$out, $st, $unmatchedIds];
}

// ── AJAX: Preview ─────────────────────────────────────────────────────────────
if (isset($_GET['ajax_preview'])) {
    header('Content-Type: application/json');
    $import_id = intval($_GET['import_id'] ?? 0);
    $field = ($_GET['match'] ?? 'id') === 'employee_id' ? 'employee_id' : 'id';
    if (!$import_id) { echo json_encode(['success'=>false,'message'=>'No batch selected.']); exit; }

    [$rows,$st,$unmatched] = resolveBatch($conn, $import_id, $field);
    if (!count($rows)) { echo json_encode(['success'=>false,'message'=>'This batch has no live rows to import.']); exit; }

    $sample = [];
    foreach (array_slice($rows,0,80) as $r) {
        $single = ((int)$r['punch_count'] <= 1);
        $sample[] = [
            'person_id'=>$r['person_id'], 'person_name'=>$r['person_name'],
            'emp_id'=>$r['emp_id'], 'emp_name'=>$r['emp_name'],
            'date'=>$r['attendance_date'],
            'time_in'=>substr($r['time_in'],11,5),
            'time_out'=>$single ? '' : substr($r['time_out'],11,5),
            'state'=>$r['state'],
        ];
    }
    $un = [];
    foreach ($unmatched as $pid=>$nm) $un[] = ['id'=>$pid,'name'=>$nm];
    echo json_encode(['success'=>true,'stats'=>$st,'total'=>count($rows),
                      'sample'=>$sample,'unmatched'=>array_slice($un,0,60),
                      'unmatched_total'=>count($un)]);
    exit;
}

// ── AJAX: Import (push batch) ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_import'])) {
    header('Content-Type: application/json');
    $import_id = intval($_POST['import_id'] ?? 0);
    $field = ($_POST['match'] ?? 'id') === 'employee_id' ? 'employee_id' : 'id';
    if (!$import_id) { echo json_encode(['success'=>false,'message'=>'No batch selected.']); exit; }

    [$rows,$st,$unmatched] = resolveBatch($conn, $import_id, $field);
    if (!count($rows)) { echo json_encode(['success'=>false,'message'=>'This batch has no live rows to import.']); exit; }

    // source file name for the log
    $srcFile=''; $q=mysqli_query($conn,"SELECT file_name FROM ".ATT_DB.".attendance_imports WHERE id=$import_id LIMIT 1");
    if ($q && ($x=mysqli_fetch_assoc($q))) $srcFile=$x['file_name'];

    $now=date('Y-m-d H:i:s');
    $fe=mysqli_real_escape_string($conn,$field);
    $sf=mysqli_real_escape_string($conn,$srcFile);
    $tdb=mysqli_real_escape_string($conn,$ORIG_DB);
    mysqli_query($conn, "INSERT INTO ".ATT_DB.".attendance_push_batches
        (source_import_id,source_file,match_field,target_db,pushed_at,status)
        VALUES ($import_id,'$sf','$fe','$tdb','$now','active')");
    $push_id = (int)mysqli_insert_id($conn);

    $insAtt = mysqli_prepare($conn,
        "INSERT INTO attendance (employee_id, att_date, check_in, check_out) VALUES (?,?,?,?)");
    $logRow = mysqli_prepare($conn,
        "INSERT INTO ".ATT_DB.".attendance_push_rows (push_id,employee_id,att_date,attendance_id)
         VALUES (?,?,?,?)");

    $inserted=0; $skipped=$st['skip']; $unmatchedN=$st['unmatched']+$st['ambiguous'];
    foreach ($rows as $r) {
        if ($r['state'] !== 'new') continue;
        $emp=(int)$r['emp_id']; $date=$r['attendance_date'];
        $ci=$r['time_in'];
        $single=((int)$r['punch_count']<=1) || (strtotime($r['time_out'])<=strtotime($r['time_in']));
        $co=$single ? null : $r['time_out'];
        mysqli_stmt_bind_param($insAtt,'isss',$emp,$date,$ci,$co);
        if (mysqli_stmt_execute($insAtt)) {
            $attId=(int)mysqli_insert_id($conn);
            mysqli_stmt_bind_param($logRow,'iisi',$push_id,$emp,$date,$attId);
            mysqli_stmt_execute($logRow);
            $inserted++;
        }
    }
    mysqli_stmt_close($insAtt); mysqli_stmt_close($logRow);

    mysqli_query($conn, "UPDATE ".ATT_DB.".attendance_push_batches
        SET inserted_count=$inserted, skipped_count=$skipped, unmatched_count=$unmatchedN
        WHERE id=$push_id");

    echo json_encode(['success'=>true,'push_id'=>$push_id,'inserted'=>$inserted,
        'skipped'=>$skipped,'unmatched'=>$unmatchedN,
        'message'=>"$inserted marked, $skipped already-marked skipped, $unmatchedN unmatched."]);
    exit;
}

// ── AJAX: Reverse a push (delete only the rows it inserted) ───────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_reverse'])) {
    header('Content-Type: application/json');
    $push_id=intval($_POST['push_id'] ?? 0);
    if (!$push_id) { echo json_encode(['success'=>false,'message'=>'Invalid push id.']); exit; }

    $chk=mysqli_query($conn,"SELECT status FROM ".ATT_DB.".attendance_push_batches WHERE id=$push_id LIMIT 1");
    $b=$chk?mysqli_fetch_assoc($chk):null;
    if (!$b) { echo json_encode(['success'=>false,'message'=>'Push batch not found.']); exit; }
    if ($b['status']==='reversed') { echo json_encode(['success'=>false,'message'=>'This batch is already reversed.']); exit; }

    // delete exactly the attendance rows this push created
    $del = mysqli_query($conn, "DELETE a FROM attendance a
        INNER JOIN ".ATT_DB.".attendance_push_rows pr ON pr.attendance_id = a.id
        WHERE pr.push_id = $push_id");
    $deleted = $del ? mysqli_affected_rows($conn) : 0;

    $now=date('Y-m-d H:i:s');
    mysqli_query($conn, "UPDATE ".ATT_DB.".attendance_push_batches
        SET status='reversed', reversed_at='$now' WHERE id=$push_id");

    echo json_encode(['success'=>true,'deleted'=>$deleted,
        'message'=>"Reversed — $deleted attendance record(s) removed."]);
    exit;
}

// ── AJAX: Push history (for live refresh) ─────────────────────────────────────
if (isset($_GET['ajax_history'])) {
    header('Content-Type: application/json');
    $list=[];
    $q=mysqli_query($conn,"SELECT * FROM ".ATT_DB.".attendance_push_batches ORDER BY id DESC LIMIT 50");
    if ($q) while ($r=mysqli_fetch_assoc($q)) $list[]=$r;
    echo json_encode(['success'=>true,'history'=>$list]);
    exit;
}

// ── Source batches for the selector ───────────────────────────────────────────
$batches = [];
$q = mysqli_query($conn, "SELECT id,file_name,date_from,date_to,attendance_rows,person_count
                          FROM ".ATT_DB.".attendance_imports ORDER BY id DESC");
if ($q) while ($r = mysqli_fetch_assoc($q)) $batches[] = $r;

// ── Push history (initial render) ─────────────────────────────────────────────
$history = [];
$q = mysqli_query($conn, "SELECT * FROM ".ATT_DB.".attendance_push_batches ORDER BY id DESC LIMIT 50");
if ($q) while ($r = mysqli_fetch_assoc($q)) $history[] = $r;

if (file_exists(__DIR__ . '/header.php')) {
    include __DIR__ . '/header.php';
} else {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Import Access Batch → Attendance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    </head><body style="font-family:sans-serif;background:#f3f4f6;padding:20px;">';
}

function hh($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
?>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-file-import" style="color:#2563eb;margin-right:8px;"></i>Import Access Batch → Attendance
            </h2>
            <p class="page-subtitle">Push an imported access-records batch into HMS attendance. Days already marked are skipped; each push can be reversed.</p>
        </div>
        <a href="add_attendance.php" class="btn btn-light">
            <i class="fa-solid fa-calendar-plus"></i> Add Attendance
        </a>
    </div>
</div>

<!-- Step 1: choose batch + match -->
<div class="content-card" style="margin-bottom:16px;">
    <div class="card-section-title">
        <i class="fa-solid fa-1 step-num"></i> Select Batch &amp; Matching
    </div>
    <div class="top-selectors">
        <div class="selector-group" style="flex:2;min-width:280px;">
            <label class="field-label">Import Batch <span class="req">*</span></label>
            <select id="batchSelect" class="native-select" onchange="clearPreview()">
                <option value="">— Choose an imported batch —</option>
                <?php foreach ($batches as $b): ?>
                <option value="<?php echo (int)$b['id']; ?>">
                    #<?php echo (int)$b['id']; ?> — <?php echo hh($b['file_name']); ?>
                    (<?php echo hh(date('d/m/Y',strtotime($b['date_from'])).' – '.date('d/m/Y',strtotime($b['date_to']))); ?>,
                    <?php echo (int)$b['attendance_rows']; ?> rows)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="selector-group" style="flex:1;min-width:220px;">
            <label class="field-label">Match Person ID against <span class="req">*</span></label>
            <select id="matchField" class="native-select" onchange="clearPreview()">
                <option value="id">employees.id (DB ID)</option>
                <option value="employee_id">employees.employee_id (Code)</option>
            </select>
        </div>
        <div class="selector-group" style="justify-content:flex-end;align-items:flex-end;">
            <button class="btn btn-primary" onclick="previewBatch()" id="btnPreview">
                <i class="fa-solid fa-magnifying-glass"></i> Preview
            </button>
        </div>
    </div>
    <?php if (!count($batches)): ?>
    <div style="margin-top:14px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:13px;color:#92600a;">
        <i class="fa-solid fa-triangle-exclamation"></i> No import batches found. Import an access-records Excel first (Import page).
    </div>
    <?php endif; ?>
</div>

<!-- Step 2: preview + import -->
<div class="content-card" id="previewCard" style="display:none;margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
        <div class="card-section-title" style="margin:0;">
            <i class="fa-solid fa-2 step-num"></i> Preview &amp; Import
        </div>
        <button class="btn btn-primary" onclick="runImport()" id="btnImport" disabled>
            <i class="fa-solid fa-download"></i> Import to Attendance
        </button>
    </div>

    <div class="summary-bar" id="previewStats"></div>

    <div id="unmatchedBox" style="display:none;margin-top:12px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:12px;color:#7f1d1d;"></div>

    <div class="table-responsive" style="margin-top:14px;">
        <table class="att-table" id="previewTable">
            <thead><tr>
                <th>Person ID</th><th>Access Name</th><th>→ Employee</th>
                <th>Date</th><th>In</th><th>Out</th><th>Result</th>
            </tr></thead>
            <tbody id="previewBody"></tbody>
        </table>
    </div>
    <div style="font-size:11px;color:#9ca3af;margin-top:8px;">Showing up to 80 rows. All matching rows are imported on confirm.</div>
</div>

<!-- Push history -->
<div class="content-card">
    <div class="card-section-title">
        <i class="fa-solid fa-clock-rotate-left" style="color:#2563eb;margin-right:8px;"></i> Push History
    </div>
    <div class="table-responsive">
        <table class="att-table" id="histTable">
            <thead><tr>
                <th>Push</th><th>Source Batch</th><th>Match</th>
                <th>Marked</th><th>Skipped</th><th>Unmatched</th>
                <th>Pushed At</th><th>Status</th><th style="width:90px;">Action</th>
            </tr></thead>
            <tbody id="histBody"></tbody>
        </table>
    </div>
</div>

<!-- Reverse confirm modal -->
<div id="revModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99998;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:26px 28px;max-width:400px;width:90%;box-shadow:0 12px 40px rgba(0,0,0,.2);">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <div style="width:42px;height:42px;background:#fef2f2;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fa-solid fa-rotate-left" style="color:#dc2626;font-size:18px;"></i>
            </div>
            <div>
                <div style="font-weight:700;font-size:15px;color:#111827;">Reverse This Push</div>
                <div style="font-size:12px;color:#6b7280;">Removes only the rows this push inserted.</div>
            </div>
        </div>
        <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;margin-bottom:18px;font-size:13px;color:#7f1d1d;" id="revMsg"></div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button onclick="closeRev()" class="btn btn-light">Cancel</button>
            <button onclick="confirmRev()" class="btn btn-danger" id="btnRev"><i class="fa-solid fa-rotate-left"></i> Reverse</button>
        </div>
    </div>
</div>

<style>
.page-header{margin-bottom:20px;}
.page-title{font-size:24px;font-weight:700;color:#111827;margin:0 0 3px;}
.page-subtitle{font-size:13px;color:#6b7280;margin:0;}
.content-card{background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:20px 22px;}
.card-section-title{font-size:14px;font-weight:700;color:#111827;display:flex;align-items:center;margin-bottom:16px;}
.step-num{background:#2563eb;color:#fff;border-radius:50%;width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;margin-right:8px;}
.field-label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;display:block;}
.req{color:#ef4444;}
.top-selectors{display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;}
.selector-group{display:flex;flex-direction:column;}
.native-select{height:38px;padding:0 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;font-family:inherit;background:#fff;color:#111827;cursor:pointer;width:100%;}
.native-select:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12);}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap;}
.btn-primary{background:#2563eb;color:#fff;}
.btn-primary:hover{background:#1d4ed8;transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,99,235,.35);}
.btn-primary:disabled{background:#93c5fd;cursor:not-allowed;transform:none;box-shadow:none;}
.btn-light{background:#f9fafb;color:#374151;border:1px solid #d1d5db;}
.btn-light:hover{background:#f3f4f6;}
.btn-danger{background:#dc2626;color:#fff;}
.btn-danger:hover{background:#b91c1c;}
.btn-danger:disabled{background:#fca5a5;cursor:not-allowed;}
.btn-rev{display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border:none;border-radius:6px;background:#fef2f2;color:#dc2626;cursor:pointer;font-size:11px;font-weight:600;font-family:inherit;}
.btn-rev:hover{background:#fee2e2;}
.btn-rev:disabled{opacity:.4;cursor:not-allowed;}
.table-responsive{overflow-x:auto;}
.att-table{width:100%;border-collapse:collapse;font-size:13px;}
.att-table thead{background:#f8fafc;border-bottom:2px solid #e2e8f0;}
.att-table th{padding:9px 12px;text-align:left;font-weight:600;color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;}
.att-table tbody tr{border-bottom:1px solid #f1f5f9;}
.att-table tbody tr:hover{background:#f8fafc;}
.att-table td{padding:7px 12px;vertical-align:middle;white-space:nowrap;}
.st-badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.st-new{background:#dcfce7;color:#16a34a;}
.st-skip{background:#fed7aa;color:#c2410c;}
.st-unmatched{background:#fee2e2;color:#dc2626;}
.st-ambiguous{background:#e9d5ff;color:#7c3aed;}
.st-active{background:#dcfce7;color:#16a34a;}
.st-reversed{background:#f3f4f6;color:#9ca3af;}
.mono{font-family:monospace;}
.mut{color:#9ca3af;}
.summary-bar{display:flex;gap:18px;padding:12px 16px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;flex-wrap:wrap;}
.sum-item{display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#374151;}
.sum-num{font-size:18px;font-weight:700;}
.sum-item.green .sum-num{color:#16a34a;} .sum-item.orange .sum-num{color:#c2410c;}
.sum-item.red .sum-num{color:#dc2626;} .sum-item.blue .sum-num{color:#2563eb;}
.sum-item.purple .sum-num{color:#7c3aed;}
@media(max-width:768px){.top-selectors{flex-direction:column;}.selector-group{width:100%!important;}}
@keyframes slideInRight{from{opacity:0;transform:translateX(20px);}to{opacity:1;transform:translateX(0);}}
</style>

<script>
const HISTORY0 = <?php echo json_encode($history); ?>;
let revTarget = null;

function clearPreview(){
    document.getElementById('previewCard').style.display='none';
    document.getElementById('btnImport').disabled = true;
}

function previewBatch(){
    const id=document.getElementById('batchSelect').value;
    const match=document.getElementById('matchField').value;
    if(!id){ toast('⚠️ Choose a batch first.','warn'); return; }
    const btn=document.getElementById('btnPreview');
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Loading…';
    fetch('import_to_attendance.php?ajax_preview=1&import_id='+id+'&match='+match)
        .then(r=>r.json()).then(data=>{
            btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-magnifying-glass"></i> Preview';
            if(!data.success){ toast('❌ '+data.message,'error'); return; }
            renderPreview(data);
        }).catch(e=>{
            btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-magnifying-glass"></i> Preview';
            toast('❌ '+e.message,'error');
        });
}

function renderPreview(d){
    const s=d.stats;
    document.getElementById('previewStats').innerHTML =
        item('blue',d.total,'Live Rows')+
        item('green',s.new,'Will Mark')+
        item('orange',s.skip,'Already Marked (skip)')+
        item('red',s.unmatched,'Unmatched')+
        (s.ambiguous?item('purple',s.ambiguous,'Ambiguous'):'');

    const ub=document.getElementById('unmatchedBox');
    if(d.unmatched && d.unmatched.length){
        ub.style.display='block';
        ub.innerHTML='<strong><i class="fa-solid fa-user-slash"></i> Unmatched person IDs ('+d.unmatched_total+'):</strong> '+
            d.unmatched.map(u=>u.id+' ('+esc(u.name)+')').join(', ')+
            (d.unmatched_total>d.unmatched.length?' …':'')+
            '<br><span style="color:#9ca3af;">Try switching the match field above if these should match employees.</span>';
    } else ub.style.display='none';

    let html='';
    d.sample.forEach(r=>{
        const cls='st-'+r.state;
        const label={new:'✓ Mark',skip:'Skip (exists)',unmatched:'No employee',ambiguous:'Ambiguous'}[r.state]||r.state;
        const emp = r.emp_id ? ('#'+r.emp_id+' '+esc(r.emp_name)) : '<span class="mut">—</span>';
        html+='<tr>'+
            '<td class="mono">'+esc(r.person_id)+'</td>'+
            '<td>'+esc(r.person_name)+'</td>'+
            '<td>'+emp+'</td>'+
            '<td class="mono">'+r.date+'</td>'+
            '<td class="mono">'+(r.time_in||'—')+'</td>'+
            '<td class="mono">'+(r.time_out||'<span class="mut">—</span>')+'</td>'+
            '<td><span class="st-badge '+cls+'">'+label+'</span></td>'+
            '</tr>';
    });
    document.getElementById('previewBody').innerHTML=html||'<tr><td colspan="7" class="mut" style="text-align:center;padding:24px;">No rows.</td></tr>';
    document.getElementById('previewCard').style.display='block';
    document.getElementById('btnImport').disabled = (s.new===0);
    document.getElementById('previewCard').scrollIntoView({behavior:'smooth',block:'start'});
}
function item(c,n,l){ return '<div class="sum-item '+c+'"><span class="sum-num">'+n+'</span> '+l+'</div>'; }

function runImport(){
    const id=document.getElementById('batchSelect').value;
    const match=document.getElementById('matchField').value;
    if(!id) return;
    const btn=document.getElementById('btnImport');
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Importing…';
    const fd=new FormData();
    fd.append('ajax_import','1'); fd.append('import_id',id); fd.append('match',match);
    fetch('import_to_attendance.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(data=>{
            btn.innerHTML='<i class="fa-solid fa-download"></i> Import to Attendance';
            if(data.success){
                toast('✅ '+data.message,'success');
                refreshHistory();
                previewBatch(); // refresh preview (now those days will show as skip)
            } else { btn.disabled=false; toast('❌ '+(data.message||'Import failed.'),'error'); }
        }).catch(e=>{
            btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-download"></i> Import to Attendance';
            toast('❌ '+e.message,'error');
        });
}

// ── History ──
function renderHistory(list){
    const tb=document.getElementById('histBody');
    if(!list.length){ tb.innerHTML='<tr><td colspan="9" class="mut" style="text-align:center;padding:24px;">No pushes yet.</td></tr>'; return; }
    let html='';
    list.forEach(b=>{
        const reversed = b.status==='reversed';
        html+='<tr>'+
            '<td class="mono">#'+b.id+'</td>'+
            '<td>#'+b.source_import_id+' '+(b.source_file?('<span class="mut">'+esc(b.source_file)+'</span>'):'')+'</td>'+
            '<td class="mono">'+esc(b.match_field)+'</td>'+
            '<td class="mono">'+b.inserted_count+'</td>'+
            '<td class="mono">'+b.skipped_count+'</td>'+
            '<td class="mono">'+b.unmatched_count+'</td>'+
            '<td class="mono mut">'+fmt(b.pushed_at)+'</td>'+
            '<td><span class="st-badge '+(reversed?'st-reversed':'st-active')+'">'+b.status+'</span></td>'+
            '<td>'+(reversed
                ? '<span class="mut" style="font-size:11px;">reversed</span>'
                : '<button class="btn-rev" onclick="openRev('+b.id+','+b.inserted_count+')"><i class="fa-solid fa-rotate-left"></i> Reverse</button>')+
            '</td></tr>';
    });
    tb.innerHTML=html;
}
function refreshHistory(){
    fetch('import_to_attendance.php?ajax_history=1').then(r=>r.json())
        .then(d=>{ if(d.success) renderHistory(d.history); });
}

function openRev(id,cnt){
    revTarget=id;
    document.getElementById('revMsg').innerHTML='Reverse push <strong>#'+id+'</strong> and delete the <strong>'+cnt+'</strong> attendance record(s) it inserted? Pre-existing records are not touched.';
    document.getElementById('revModal').style.display='flex';
}
function closeRev(){ document.getElementById('revModal').style.display='none'; revTarget=null; }
function confirmRev(){
    if(!revTarget) return;
    const id=revTarget;
    const btn=document.getElementById('btnRev');
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Reversing…';
    const fd=new FormData(); fd.append('ajax_reverse','1'); fd.append('push_id',id);
    fetch('import_to_attendance.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(data=>{
            btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-rotate-left"></i> Reverse';
            closeRev();
            if(data.success){ toast('🗑️ '+data.message,'success'); refreshHistory();
                const pc=document.getElementById('previewCard');
                if(pc.style.display!=='none') previewBatch();
            } else toast('❌ '+(data.message||'Reverse failed.'),'error');
        }).catch(e=>{
            btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-rotate-left"></i> Reverse';
            closeRev(); toast('❌ '+e.message,'error');
        });
}
document.getElementById('revModal').addEventListener('click',function(e){ if(e.target===this) closeRev(); });

// helpers
function esc(s){ return (s==null?'':String(s)).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
function fmt(dt){ if(!dt) return '—'; return dt.replace('T',' ').substring(0,16); }
function toast(msg,type){
    const colors={success:'#16a34a',error:'#dc2626',warn:'#d97706'};
    const t=document.createElement('div');
    t.style.cssText='position:fixed;top:20px;right:20px;z-index:99999;background:#fff;border-left:4px solid '+
        (colors[type]||'#2563eb')+';border-radius:8px;padding:12px 18px;font-size:13px;font-weight:600;color:#111827;'+
        'box-shadow:0 8px 24px rgba(0,0,0,.15);animation:slideInRight .25s ease;max-width:380px;';
    t.textContent=msg; document.body.appendChild(t);
    setTimeout(()=>{ t.style.opacity='0'; t.style.transition='opacity .3s'; setTimeout(()=>t.remove(),300); },3500);
}

renderHistory(HISTORY0);
</script>

<?php
if (file_exists(__DIR__ . '/footer.php')) {
    include __DIR__ . '/footer.php';
} else {
    echo '</body></html>';
}
?>