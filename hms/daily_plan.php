<?php
// ── daily_plan.php ─────────────────────────────────────────────────────────
// Daily Delivery Plan — create / edit
// Flow: Delivery Date → Delivery Persons load (with loading amount) → Save Plan
// ──────────────────────────────────────────────────────────────────────────
session_start();
date_default_timezone_set('Asia/Colombo');

if (!isset($_SESSION['employee'])) {
    header('Location: login.php');
    exit;
}

require_once 'config.php';

$createdByCode = $_SESSION['employee']['employee_id']        ?? 'SYSTEM';
$createdByName = $_SESSION['employee']['employee_full_name'] ?? 'System';

// ── Normalise mysqli $conn → PDO $pdo ────────────────────────────────────────
if (!isset($pdo)) {
    $h = defined('DB_HOST') ? DB_HOST : (isset($db_host) ? $db_host : 'localhost');
    $u = defined('DB_USER') ? DB_USER : (isset($db_user) ? $db_user : 'root');
    $pw= defined('DB_PASS') ? DB_PASS : (isset($db_pass) ? $db_pass : '');
    $d = defined('DB_NAME') ? DB_NAME : (isset($db_name) ? $db_name : '');
    try {
        $pdo = new PDO("mysql:host=$h;dbname=$d;charset=utf8mb4", $u, $pw, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        die('DB connection failed: ' . $e->getMessage());
    }
}

// ── Create / migrate tables ───────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS daily_plans (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    plan_code        VARCHAR(30)  NOT NULL UNIQUE,
    delivery_date    DATE         NOT NULL,
    delivery_person  VARCHAR(100) DEFAULT NULL,
    vehicle_id       INT          DEFAULT NULL,
    driver_name      VARCHAR(255) DEFAULT NULL,
    plan_staff_count INT          DEFAULT 0,
    porters_count    INT          DEFAULT 0,
    loading_amount   DECIMAL(15,2) DEFAULT 0.00,
    status           ENUM('draft','confirmed','cancelled') DEFAULT 'draft',
    created_by       VARCHAR(50)  DEFAULT NULL,
    created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_delivery_date (delivery_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Migration safety: rename bill_date → delivery_date if old column exists, add new columns
try { $pdo->exec("ALTER TABLE daily_plans CHANGE COLUMN bill_date delivery_date DATE NOT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE daily_plans ADD COLUMN loading_amount DECIMAL(15,2) DEFAULT 0.00 AFTER plan_staff_count"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE daily_plans ADD COLUMN porters_count INT DEFAULT 0 AFTER plan_staff_count"); } catch (PDOException $e) {}
/* driver_is_cc = 1 → the driver is also the CC: driver + CC are counted as ONE person */
try { $chk = $pdo->query("SHOW COLUMNS FROM daily_plans LIKE 'driver_is_cc'"); if ($chk && !$chk->fetch()) $pdo->exec("ALTER TABLE daily_plans ADD COLUMN driver_is_cc TINYINT(1) NOT NULL DEFAULT 0 AFTER driver_name"); } catch (PDOException $e) {}

$pdo->exec("CREATE TABLE IF NOT EXISTS daily_plan_staff (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    plan_id     INT         NOT NULL,
    employee_id VARCHAR(50) NOT NULL,
    staff_type  ENUM('CC','ACC') NOT NULL,
    INDEX idx_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── AJAX: delivery persons + loading amount by delivery date ──────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'delivery_persons') {
    header('Content-Type: application/json');
    $date = $_GET['date'] ?? '';
    if (!$date) { echo json_encode([]); exit; }

    // Filter by the delivery_date stored directly in detail rows (set from import form).
    // Also accept the parent import's delivery_date as a fallback join condition.
    // Include both 'completed' and 'processing' imports so nothing is missed.
    $stmt = $pdo->prepare(
        "SELECT d.delivery_person,
                COALESCE(SUM(d.final_bill_amount), 0) AS loading_amount
         FROM loading_summary_import_details d
         INNER JOIN loading_summary_imports i ON i.id = d.import_id
         WHERE (d.delivery_date = ? OR (d.delivery_date IS NULL AND i.delivery_date = ?))
           AND i.status IN ('completed', 'processing')
           AND d.status = 'imported'
           AND d.delivery_person IS NOT NULL
           AND d.delivery_person <> ''
         GROUP BY d.delivery_person
         ORDER BY d.delivery_person"
    );
    $stmt->execute([$date, $date]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── AJAX: get vehicle driver name by vehicle id ───────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'vehicle_driver') {
    header('Content-Type: application/json');
    $vid = intval($_GET['vehicle_id'] ?? 0);
    if ($vid) {
        $stmt = $pdo->prepare("SELECT driver_name FROM vehicles WHERE id = ? AND active = 1 LIMIT 1");
        $stmt->execute([$vid]);
        $row = $stmt->fetch();
        echo json_encode(['driver_name' => $row ? ($row['driver_name'] ?? '') : '']);
    } else {
        echo json_encode(['driver_name' => '']);
    }
    exit;
}

// ── POST: save / update ───────────────────────────────────────────────────────
$save_error   = '';
$save_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action         = $_POST['action'];
    $plan_id        = intval($_POST['plan_id']          ?? 0);
    $delivery_date  = trim($_POST['delivery_date']       ?? '');
    $del_person     = trim($_POST['delivery_person']     ?? '');
    $vehicle_id     = intval($_POST['vehicle_id']        ?? 0) ?: null;
    $driver_name    = trim($_POST['driver_name']         ?? '');
    $driver_is_cc   = !empty($_POST['driver_is_cc']) ? 1 : 0;
    $plan_staff     = intval($_POST['plan_staff_count']  ?? 0);
    $porters_count  = intval($_POST['porters_count']     ?? 0);
    $loading_amount = floatval($_POST['loading_amount']  ?? 0);
    $cc_staff       = json_decode($_POST['cc_staff']     ?? '[]', true) ?: [];
    $acc_staff      = json_decode($_POST['acc_staff']    ?? '[]', true) ?: [];

    if (!$delivery_date || !$del_person) {
        $save_error = 'Delivery Date and Delivery Person are required.';
    } else {
        if ($action === 'save') {
            $dup = $pdo->prepare("SELECT id FROM daily_plans WHERE delivery_date=? AND delivery_person=? LIMIT 1");
            $dup->execute([$delivery_date, $del_person]);
            if ($dup->fetch()) {
                $save_error = 'A plan already exists for this date and delivery person.';
            } else {
                $code = 'PLN-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 4));
                $ins  = $pdo->prepare(
                    "INSERT INTO daily_plans
                        (plan_code,delivery_date,delivery_person,vehicle_id,driver_name,driver_is_cc,
                         plan_staff_count,porters_count,loading_amount,status,created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,'draft',?)"
                );
                $ins->execute([$code, $delivery_date, $del_person, $vehicle_id,
                               $driver_name, $driver_is_cc, $plan_staff, $porters_count, $loading_amount, $createdByCode]);
                $plan_id = (int)$pdo->lastInsertId();

                $ist = $pdo->prepare("INSERT INTO daily_plan_staff (plan_id,employee_id,staff_type) VALUES (?,?,?)");
                foreach ($cc_staff  as $e) $ist->execute([$plan_id, $e, 'CC']);
                foreach ($acc_staff as $e) $ist->execute([$plan_id, $e, 'ACC']);

                $save_success = "Plan <strong>$code</strong> saved successfully!";
            }
        } elseif ($action === 'update' && $plan_id) {
            $upd = $pdo->prepare(
                "UPDATE daily_plans
                 SET delivery_date=?,delivery_person=?,vehicle_id=?,driver_name=?,driver_is_cc=?,
                     plan_staff_count=?,porters_count=?,loading_amount=?
                 WHERE id=?"
            );
            $upd->execute([$delivery_date, $del_person, $vehicle_id,
                           $driver_name, $driver_is_cc, $plan_staff, $porters_count, $loading_amount, $plan_id]);

            $pdo->prepare("DELETE FROM daily_plan_staff WHERE plan_id=?")->execute([$plan_id]);

            $ist = $pdo->prepare("INSERT INTO daily_plan_staff (plan_id,employee_id,staff_type) VALUES (?,?,?)");
            foreach ($cc_staff  as $e) $ist->execute([$plan_id, $e, 'CC']);
            foreach ($acc_staff as $e) $ist->execute([$plan_id, $e, 'ACC']);

            $save_success = 'Plan updated successfully!';
        }
    }
}

// ── Vehicles ──────────────────────────────────────────────────────────────────
$vehicles = [];
$vstmt = $pdo->query("SELECT id, vehicle_number, owner, driver_name FROM vehicles WHERE active=1 ORDER BY vehicle_number");
if ($vstmt) $vehicles = $vstmt->fetchAll();

// Build vehicle→driver map for JS
$vehicleDriverMap = [];
foreach ($vehicles as $v) {
    $vehicleDriverMap[(int)$v['id']] = $v['driver_name'] ?? '';
}

// ── CC & ACC employees — load ALL staff with their type ───────────────────────
$all_staff     = [];
$cc_employees  = [];
$acc_employees = [];

foreach (['designation_id', 'staff_category_id'] as $col) {
    try {
        $pdo->query("SELECT $col FROM employees LIMIT 0");
    } catch (PDOException $e) {
        continue;
    }

    $allRes = $pdo->query(
        "SELECT e.employee_id, e.employee_full_name, sc.category_code
         FROM employees e
         JOIN staff_categories sc ON sc.id = e.$col
         WHERE sc.category_code IN ('CC','ACC') AND e.active = 1
         ORDER BY sc.category_code, e.employee_full_name"
    );
    if ($allRes) $all_staff = $allRes->fetchAll();

    foreach ($all_staff as $s) {
        if ($s['category_code'] === 'CC')  $cc_employees[]  = $s;
        else                               $acc_employees[] = $s;
    }

    if (!empty($all_staff)) break;
}

// ── Edit mode pre-load ────────────────────────────────────────────────────────
$edit_plan  = null;
$edit_staff = [];
$edit_id    = intval($_GET['edit'] ?? 0);
if ($edit_id) {
    $ep = $pdo->prepare("SELECT * FROM daily_plans WHERE id=? LIMIT 1");
    $ep->execute([$edit_id]);
    $edit_plan = $ep->fetch() ?: null;
    if ($edit_plan) {
        $est = $pdo->prepare("SELECT employee_id, staff_type FROM daily_plan_staff WHERE plan_id=?");
        $est->execute([$edit_id]);
        $edit_staff = $est->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Yelo HMS — Daily Plan</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">

<style>
/* ── Select2 overrides ── */
.select2-container{width:100%!important;max-width:100%!important;}
.select2-container--default .select2-selection--single{height:44px!important;border:1px solid var(--sep)!important;border-radius:12px!important;background:var(--bg3)!important;outline:none!important;display:flex!important;align-items:center!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:1!important;padding:0 36px 0 14px!important;font-size:14px!important;font-weight:600!important;color:var(--label)!important;font-family:var(--sf)!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;width:100%!important;display:block!important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:44px!important;top:0!important;right:10px!important;}
.select2-container--default .select2-selection--single .select2-selection__placeholder{color:var(--label3)!important;font-weight:400!important;}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open  .select2-selection--single{border-color:var(--yellow)!important;box-shadow:0 0 0 3px var(--yellow-dim)!important;background:var(--bg2)!important;}
.select2-dropdown{border:1px solid var(--sep)!important;border-radius:16px!important;background:var(--bg2)!important;overflow:hidden;font-family:var(--sf)!important;box-shadow:0 8px 32px rgba(0,0,0,.14)!important;max-width:calc(100vw - 40px)!important;}
.select2-results__option{color:var(--label)!important;background:var(--bg2)!important;padding:10px 14px!important;border-bottom:0.5px solid var(--sep)!important;line-height:1.2!important;}
.select2-results__option:last-child{border-bottom:none!important;}
.s2-name{display:block;font-size:13px;font-weight:600;color:var(--label);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.s2-meta{display:block;font-size:11px;font-weight:500;color:var(--label2);margin-top:2px;white-space:nowrap;}
.select2-results__option--highlighted{background:var(--yellow-dim)!important;color:var(--label)!important;}
.select2-results__option--highlighted .s2-name,
.select2-results__option--highlighted .s2-meta{color:var(--label)!important;}
.select2-search--dropdown{padding:8px!important;border-bottom:0.5px solid var(--sep)!important;}
.select2-search--dropdown input{border:1px solid var(--sep)!important;border-radius:10px!important;background:var(--bg3)!important;color:var(--label)!important;font-size:13px!important;padding:8px 12px!important;outline:none!important;width:100%!important;font-family:var(--sf)!important;}
.select2-search--dropdown input:focus{border-color:var(--yellow)!important;}
.select2-results__options{max-height:220px!important;overflow-y:auto!important;}
@media(max-width:480px){.select2-dropdown{max-width:calc(100vw - 40px)!important;}.select2-results__options{max-height:180px!important;}}
</style>

<style>
:root{
  --sf:-apple-system,'SF Pro Display','SF Pro Text','Helvetica Neue','Inter',sans-serif;
  --yellow:#F5C518;--yellow-dim:rgba(245,197,24,0.16);
  --bg:#F2F2F7;--bg2:#FFFFFF;--bg3:rgba(118,118,128,0.10);
  --label:#000000;--label2:rgba(60,60,67,0.60);--label3:rgba(60,60,67,0.28);
  --sep:rgba(60,60,67,0.13);
  --red:#FF3B30;--green:#34C759;--blue:#007AFF;--orange:#FF9F0A;--purple:#AF52DE;
  --shadow:0 2px 20px rgba(0,0,0,0.07),0 1px 3px rgba(0,0,0,0.05);
  --shadow-lg:0 8px 32px rgba(0,0,0,0.13),0 2px 8px rgba(0,0,0,0.06);
  --island:#000;
}
[data-theme="dark"]{
  --bg:#000000;--bg2:#1C1C1E;--bg3:rgba(118,118,128,0.22);
  --label:#FFFFFF;--label2:rgba(235,235,245,0.60);--label3:rgba(235,235,245,0.18);
  --sep:rgba(84,84,88,0.60);
  --shadow:0 2px 20px rgba(0,0,0,0.50);
  --shadow-lg:0 8px 32px rgba(0,0,0,0.60);
  --island:#1C1C1E;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent;}
html,body{height:100%;background:var(--bg);font-family:var(--sf);-webkit-font-smoothing:antialiased;overscroll-behavior:none;transition:background .3s;}
body{display:flex;justify-content:center;}
.shell{width:100%;max-width:480px;min-height:100dvh;background:var(--bg);display:flex;flex-direction:column;transition:background .3s;}

/* TOPBAR */
.topbar{display:flex;align-items:center;justify-content:space-between;padding:14px 20px 0;flex-shrink:0;position:sticky;top:0;z-index:100;background:var(--bg);transition:background .3s;}
.topbar-left{display:flex;align-items:center;gap:10px;}
.back-btn{height:34px;width:34px;background:var(--bg3);border:none;border-radius:50%;font-size:15px;color:var(--label);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;text-decoration:none;}
.back-btn:active{opacity:.7;transform:scale(.95);}
.island{width:118px;height:34px;background:var(--island);border-radius:20px;transition:background .3s;}
.icon-pill{height:34px;width:34px;background:var(--bg3);border:none;border-radius:20px;font-size:13px;color:var(--label);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;}
.icon-pill:active{opacity:.7;transform:scale(.96);}

.scroll{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none;padding-bottom:40px;}
.scroll::-webkit-scrollbar{display:none;}

.large-title{padding:8px 22px 16px;font-size:34px;font-weight:800;letter-spacing:-1px;color:var(--label);transition:color .3s;}
.large-title em{color:var(--yellow);font-style:normal;}

@keyframes fadeUp{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);}}
@keyframes spin{to{transform:rotate(360deg);}}
@keyframes pulseGlow{0%,100%{box-shadow:0 0 0 0 rgba(245,197,24,.4);}50%{box-shadow:0 0 0 8px rgba(245,197,24,0);}}

/* ALERT */
.dp-alert{margin:0 20px 14px;padding:12px 16px;border-radius:14px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;animation:fadeUp .3s both;}
.dp-alert-ok {background:rgba(52,199,89,.12);color:var(--green);border:1px solid rgba(52,199,89,.25);}
.dp-alert-err{background:rgba(255,59,48,.10);color:var(--red); border:1px solid rgba(255,59,48,.20);}

/* CARDS */
.dp-card{margin:0 20px 14px;background:var(--bg2);border-radius:22px;overflow:hidden;box-shadow:var(--shadow);animation:fadeUp .4s both;transition:background .3s;}
.dp-card-head{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:0.5px solid var(--sep);}
.dp-card-icon{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
.dp-card-title{font-size:14px;font-weight:700;color:var(--label);letter-spacing:-.2px;transition:color .3s;}
.dp-card-body{padding:16px 18px;}

/* FORM FIELDS */
.dp-row  {display:grid;gap:14px;margin-bottom:14px;}
.dp-row:last-child{margin-bottom:0;}
.dp-row-2{grid-template-columns:1fr 1fr;}
.dp-row-1{grid-template-columns:1fr;}
@media(max-width:440px){.dp-row-2{grid-template-columns:1fr;}}

.dp-group{display:flex;flex-direction:column;gap:6px;}
.dp-label{font-size:11px;font-weight:700;color:var(--label2);text-transform:uppercase;letter-spacing:.4px;transition:color .3s;}
.dp-input,.dp-select{height:44px;border:1px solid var(--sep);border-radius:12px;padding:0 14px;font-size:14px;font-family:var(--sf);color:var(--label);background:var(--bg3);width:100%;transition:border-color .2s,background .3s;outline:none;-webkit-appearance:none;}
.dp-input:focus,.dp-select:focus{border-color:var(--yellow);background:var(--bg2);box-shadow:0 0 0 3px var(--yellow-dim);}
.dp-input::placeholder{color:var(--label3);}
.dp-hint{font-size:11px;color:var(--label3);transition:color .3s;}

/* Driver = CC checkbox */
.drv-cc-chk{display:flex;align-items:center;gap:10px;margin-top:12px;padding:10px 13px;border-radius:12px;background:rgba(0,122,255,.07);border:1px solid rgba(0,122,255,.18);cursor:pointer;}
.drv-cc-chk input{width:20px;height:20px;accent-color:var(--blue);flex-shrink:0;}
.drv-cc-chk span{display:flex;flex-direction:column;font-size:13px;color:var(--label);}
.drv-cc-chk small{font-size:11px;color:var(--label2);font-weight:500;margin-top:1px;}
/* Driver auto-fill indicator */
.dp-input.auto-filled{border-color:var(--green)!important;background:rgba(52,199,89,.06)!important;}
.driver-tag{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:700;color:var(--green);margin-top:2px;opacity:0;transition:opacity .2s;}
.driver-tag.visible{opacity:1;}

/* LOADING spinner */
.dp-loading{display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--bg3);border-radius:12px;font-size:13px;color:var(--label2);}
.dp-loading i{animation:spin .7s linear infinite;}

/* LOADING AMOUNT BANNER */
.loading-amount-banner{display:none;margin-top:12px;padding:14px 16px;background:linear-gradient(135deg,rgba(245,197,24,.18) 0%,rgba(245,197,24,.06) 100%);border:1px solid rgba(245,197,24,.35);border-radius:14px;align-items:center;gap:12px;animation:fadeUp .3s both;}
.loading-amount-banner.visible{display:flex;}
.la-icon{width:40px;height:40px;border-radius:12px;background:rgba(245,197,24,.20);display:flex;align-items:center;justify-content:center;font-size:18px;color:#b8900c;flex-shrink:0;}
.la-body{flex:1;min-width:0;}
.la-label{font-size:10px;font-weight:700;color:var(--label2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;}
.la-value{font-size:22px;font-weight:800;color:var(--label);letter-spacing:-0.5px;line-height:1;}
.la-sub{font-size:11px;color:var(--label2);margin-top:2px;}

/* STAFF */
.staff-selects{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;}
@media(max-width:440px){.staff-selects{grid-template-columns:1fr;}}

.staff-list{display:flex;flex-direction:column;gap:6px;margin-top:4px;}
.staff-item{display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--bg3);border-radius:12px;}
.staff-av{width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;flex-shrink:0;}
.staff-name{flex:1;font-size:13px;font-weight:600;color:var(--label);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;transition:color .3s;}
.staff-badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:8px;white-space:nowrap;}
.staff-rm{border:none;background:none;cursor:pointer;color:var(--label3);font-size:14px;padding:2px 4px;border-radius:5px;line-height:1;transition:all .15s;}
.staff-rm:hover{background:rgba(255,59,48,.12);color:var(--red);}

/* Staff count note — auto total */
.staff-count-note{display:flex;align-items:center;gap:6px;padding:8px 12px;background:rgba(0,122,255,.07);border:1px solid rgba(0,122,255,.18);border-radius:10px;margin-top:10px;font-size:11px;color:var(--blue);font-weight:600;}

/* Porters row */
.porters-row{display:flex;align-items:center;gap:12px;margin-top:14px;padding:14px 16px;background:rgba(255,159,10,.07);border:1px solid rgba(255,159,10,.22);border-radius:14px;}
.porters-icon{width:36px;height:36px;border-radius:10px;background:rgba(255,159,10,.16);display:flex;align-items:center;justify-content:center;font-size:16px;color:var(--orange);flex-shrink:0;}
.porters-body{flex:1;min-width:0;}
.porters-label{font-size:10px;font-weight:700;color:var(--label2);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.porters-stepper{display:flex;align-items:center;gap:8px;}
.porters-btn{width:34px;height:34px;border-radius:10px;border:1px solid var(--sep);background:var(--bg3);color:var(--label);font-size:18px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;transition:all .15s;flex-shrink:0;}
.porters-btn:active{opacity:.6;transform:scale(.93);}
.porters-input{height:34px;width:64px;border:1px solid var(--sep);border-radius:10px;background:var(--bg3);color:var(--label);font-size:18px;font-weight:800;text-align:center;font-family:var(--sf);outline:none;transition:border-color .2s;-webkit-appearance:none;}
.porters-input:focus{border-color:var(--orange);box-shadow:0 0 0 3px rgba(255,159,10,.18);}
.porters-hint{font-size:10px;color:var(--label3);margin-top:3px;}

/* Manual plan staff count input box */
.plan-staff-manual{margin-top:14px;padding-top:14px;border-top:0.5px solid var(--sep);}
.plan-staff-manual .dp-label{display:flex;align-items:center;gap:6px;margin-bottom:6px;}
.plan-staff-manual .dp-label .manual-badge{font-size:9px;font-weight:700;background:rgba(255,159,10,.16);color:var(--orange);padding:2px 7px;border-radius:6px;text-transform:uppercase;letter-spacing:.3px;}
.plan-staff-manual .dp-input{font-size:18px;font-weight:700;color:var(--label);text-align:center;letter-spacing:-.3px;}
.plan-staff-manual .dp-hint{text-align:center;}

/* COUNT BAR */
.count-bar{display:flex;gap:6px;margin-top:12px;flex-wrap:wrap;}
.count-chip{flex:1;min-width:56px;text-align:center;padding:10px 4px;background:var(--bg3);border-radius:14px;}
.count-n{font-size:20px;font-weight:800;color:var(--label);transition:color .3s;}
.count-l{font-size:9px;color:var(--label2);font-weight:600;margin-top:2px;transition:color .3s;}

/* SUMMARY TABLE */
.ps-table{width:100%;border-collapse:collapse;font-size:13px;}
.ps-table td{padding:8px 0;border-bottom:0.5px solid var(--sep);}
.ps-table tr:last-child td{border-bottom:none;}
.ps-key{color:var(--label2);width:46%;transition:color .3s;}
.ps-val{font-weight:700;color:var(--label);transition:color .3s;}
.ps-amount{font-size:16px;color:#b8900c;font-weight:800;}
.ps-manual-highlight{color:var(--orange)!important;}
.ps-porter-highlight{color:var(--orange)!important;}
.ps-grand-total{font-size:15px;color:var(--blue)!important;font-weight:800;}

/* SAVE BUTTON */
.dp-save-btn{width:100%;height:54px;background:var(--label);color:var(--bg);border:none;border-radius:16px;font-size:16px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;font-family:var(--sf);transition:all .2s;letter-spacing:-.2px;margin-top:14px;box-shadow:0 4px 20px rgba(0,0,0,.14);}
.dp-save-btn:not(:disabled):active{transform:scale(.97);}
.dp-save-btn:disabled{opacity:.32;cursor:not-allowed;box-shadow:none;}
.dp-save-btn.edit-mode{background:var(--blue);}
.btn-spinner{width:20px;height:20px;border:2.5px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;display:none;}

/* HOME BAR */
.home-bar-wrap{height:34px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.home-bar{width:134px;height:5px;background:var(--label);border-radius:3px;opacity:.15;}

/* TOAST */
.toast{position:fixed;bottom:calc(34px + 16px);left:50%;transform:translateX(-50%) translateY(20px);background:var(--label);color:var(--bg);padding:11px 20px;border-radius:14px;font-size:14px;font-weight:600;white-space:nowrap;z-index:9999;opacity:0;transition:all .28s;pointer-events:none;box-shadow:0 8px 24px rgba(0,0,0,.2);}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0);}
.toast.error{background:var(--red);color:#fff;}
</style>
</head>
<body>
<div class="shell">

  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-left">
      <a href="daily_plans_list.php" class="back-btn" aria-label="Back">
        <i class="fa-solid fa-chevron-left"></i>
      </a>
     
    </div>
    <button class="icon-pill" id="themeBtn" aria-label="Toggle theme">
      <i class="fa-solid fa-moon" id="themeIcon"></i>
    </button>
  </div>

  <div class="scroll">

    <div class="large-title"><em>Daily Plan</em></div>

    <?php if ($save_success): ?>
    <div class="dp-alert dp-alert-ok">
      <i class="fa-solid fa-circle-check"></i>
      <span><?= $save_success ?></span>
    </div>
    <?php endif; ?>
    <?php if ($save_error): ?>
    <div class="dp-alert dp-alert-err">
      <i class="fa-solid fa-circle-exclamation"></i>
      <span><?= htmlspecialchars($save_error) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" id="planForm">
      <input type="hidden" name="plan_id"        id="hidPlanId"        value="<?= $edit_plan ? (int)$edit_plan['id'] : 0 ?>">
      <input type="hidden" name="action"         id="hidAction"        value="<?= $edit_plan ? 'update' : 'save' ?>">
      <input type="hidden" name="cc_staff"       id="hidCcStaff"       value="[]">
      <input type="hidden" name="acc_staff"      id="hidAccStaff"      value="[]">
      <input type="hidden" name="loading_amount" id="hidLoadingAmount" value="<?= $edit_plan ? htmlspecialchars($edit_plan['loading_amount'] ?? '0') : '0' ?>">
      <input type="hidden" id="hidAutoStaffCount" value="0">

      <!-- STEP 1: Delivery Date → loads delivery persons -->
      <div class="dp-card" style="animation-delay:.04s">
        <div class="dp-card-head">
          <div class="dp-card-icon" style="background:rgba(0,122,255,.12);color:var(--blue)">
            <i class="fa-solid fa-calendar-days"></i>
          </div>
          <span class="dp-card-title">Step 1 — Delivery Date &amp; Delivery Person</span>
        </div>
        <div class="dp-card-body">
          <div class="dp-row dp-row-2">
            <div class="dp-group">
              <label class="dp-label">Delivery Date <span style="color:var(--red)">*</span></label>
              <input type="date" class="dp-input" id="billDate" name="delivery_date"
                     value="<?= htmlspecialchars($edit_plan['delivery_date'] ?? '') ?>"
                     onchange="loadDeliveryPersons()" required>
            </div>
            <div class="dp-group">
              <label class="dp-label">Delivery Person <span style="color:var(--red)">*</span></label>
              <div id="dpWrap">
                <select class="dp-select" id="dpSelect" name="delivery_person" required>
                  <option value="">— Select date first —</option>
                  <?php if ($edit_plan && $edit_plan['delivery_person']): ?>
                  <option value="<?= htmlspecialchars($edit_plan['delivery_person']) ?>" selected>
                    <?= htmlspecialchars($edit_plan['delivery_person']) ?>
                  </option>
                  <?php endif; ?>
                </select>
              </div>
              <span class="dp-hint" id="dpHint">Select a delivery date to load persons</span>
            </div>
          </div>

          <!-- Loading Amount Banner -->
          <div class="loading-amount-banner" id="loadingAmountBanner">
            <div class="la-icon"><i class="fa-solid fa-coins"></i></div>
            <div class="la-body">
              <div class="la-label">Total Loading Amount</div>
              <div class="la-value" id="laValue">Rs 0.00</div>
              <div class="la-sub" id="laSub">Based on final bill amounts for selected person</div>
            </div>
          </div>

        </div>
      </div>

      <!-- STEP 2: Vehicle + Driver -->
      <div class="dp-card" style="animation-delay:.07s">
        <div class="dp-card-head">
          <div class="dp-card-icon" style="background:rgba(255,59,48,.10);color:var(--red)">
            <i class="fa-solid fa-truck"></i>
          </div>
          <span class="dp-card-title">Step 2 — Vehicle &amp; Driver</span>
        </div>
        <div class="dp-card-body">
          <div class="dp-row dp-row-2">
            <div class="dp-group">
              <label class="dp-label">Vehicle No</label>
              <select class="dp-select" id="vehicleSelect" name="vehicle_id" onchange="onVehicleChange()">
                <option value="">— Select Vehicle —</option>
                <?php foreach ($vehicles as $v): ?>
                <option value="<?= (int)$v['id'] ?>"
                        data-driver="<?= htmlspecialchars($v['driver_name'] ?? '') ?>"
                  <?= ($edit_plan && $edit_plan['vehicle_id'] == $v['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($v['vehicle_number']) ?>
                  <?= $v['owner'] ? ' · ' . htmlspecialchars($v['owner']) : '' ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="dp-group">
              <label class="dp-label">Driver Name</label>
              <input type="text" class="dp-input" name="driver_name" id="driverInput"
                     placeholder="Auto-filled from vehicle"
                     value="<?= htmlspecialchars($edit_plan['driver_name'] ?? '') ?>"
                     oninput="updateAutoCount();updateSummary()">
              <span class="driver-tag" id="driverAutoTag">
                <i class="fa-solid fa-circle-check"></i> Auto-filled from vehicle
              </span>
            </div>
          </div>
          <label class="drv-cc-chk" for="driverIsCc">
            <input type="checkbox" name="driver_is_cc" id="driverIsCc" value="1" <?= !empty($edit_plan['driver_is_cc']) ? 'checked' : '' ?> onchange="updateAutoCount();updateSummary()">
            <span><b>Driver and CC are the same person</b><small>Driver + CC are counted as 1 person</small></span>
          </label>
        </div>
      </div>

      <!-- STEP 3: CC & ACC Staff + Porters -->
      <div class="dp-card" style="animation-delay:.10s">
        <div class="dp-card-head">
          <div class="dp-card-icon" style="background:rgba(52,199,89,.12);color:var(--green)">
            <i class="fa-solid fa-users"></i>
          </div>
          <span class="dp-card-title">Step 3 — Plan Staff</span>
        </div>
        <div class="dp-card-body">

          <!-- Staff selects: CC and ACC -->
          <div class="staff-selects">
            <div class="dp-group">
              <label class="dp-label" style="color:var(--blue);opacity:.8">
                <i class="fa-solid fa-circle-user" style="font-size:10px"></i> CC Staff
              </label>
              <select class="dp-select" id="ccSelect">
                <option value="">+ Add CC Staff</option>
                <?php foreach ($all_staff as $e): ?>
                <option value="<?= htmlspecialchars($e['employee_id']) ?>"
                        data-name="<?= htmlspecialchars($e['employee_full_name']) ?>"
                        data-eid="<?= htmlspecialchars($e['employee_id']) ?>"
                        data-type="CC"
                        data-cat="<?= htmlspecialchars($e['category_code']) ?>">
                  <?= htmlspecialchars($e['employee_full_name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="dp-group">
              <label class="dp-label" style="color:var(--purple);opacity:.8">
                <i class="fa-solid fa-circle-user" style="font-size:10px"></i> ACC Staff
              </label>
              <select class="dp-select" id="accSelect">
                <option value="">+ Add ACC Staff</option>
                <?php foreach ($all_staff as $e): ?>
                <option value="<?= htmlspecialchars($e['employee_id']) ?>"
                        data-name="<?= htmlspecialchars($e['employee_full_name']) ?>"
                        data-eid="<?= htmlspecialchars($e['employee_id']) ?>"
                        data-type="ACC"
                        data-cat="<?= htmlspecialchars($e['category_code']) ?>">
                  <?= htmlspecialchars($e['employee_full_name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="staff-list" id="staffList"></div>

          <!-- Auto staff count note -->
          <div class="staff-count-note" id="staffCountNote" style="display:none">
            <i class="fa-solid fa-circle-info"></i>
            <span id="staffCountNoteText">Auto count</span>
          </div>

          <!-- COUNT BAR: CC | ACC | Driver | Porters | Auto Total -->
          <div class="count-bar">
            <div class="count-chip"><div class="count-n" id="cntCC">0</div><div class="count-l">CC</div></div>
            <div class="count-chip"><div class="count-n" id="cntACC">0</div><div class="count-l">ACC</div></div>
            <div class="count-chip"><div class="count-n" id="cntDriver">0</div><div class="count-l">Driver</div></div>
            <div class="count-chip" style="background:rgba(255,159,10,.10)">
              <div class="count-n" id="cntPorters" style="color:var(--orange)">0</div>
              <div class="count-l" style="color:var(--orange)">Porters</div>
            </div>
            <div class="count-chip" style="background:rgba(0,122,255,.10)">
              <div class="count-n" id="cntAutoTotal" style="color:var(--blue)">0</div>
              <div class="count-l" style="color:var(--blue)">Total</div>
            </div>
          </div>

          <!-- ── PORTERS COUNT ── -->
          <div class="porters-row">
            <div class="porters-icon"><i class="fa-solid fa-person-carry-box"></i></div>
            <div class="porters-body">
              <div class="porters-label">Porters Count</div>
              <div class="porters-stepper">
                <button type="button" class="porters-btn" onclick="changePorters(-1)">−</button>
                <input type="number" class="porters-input" name="porters_count" id="portersInput"
                       min="0" value="<?= $edit_plan ? (int)$edit_plan['porters_count'] : 0 ?>"
                       oninput="onPortersInput()">
                <button type="button" class="porters-btn" onclick="changePorters(1)">+</button>
              </div>
              <div class="porters-hint">Included in total staff count</div>
            </div>
          </div>

          <!-- ── MANUAL PLAN STAFF COUNT ── -->
          <div class="plan-staff-manual">
            <label class="dp-label">
              Plan Staff Count
              <span class="manual-badge">Manual Override</span>
            </label>
            <input type="number" class="dp-input" name="plan_staff_count" id="planStaffCountInput"
                   min="0" placeholder="0"
                   value="<?= $edit_plan ? (int)$edit_plan['plan_staff_count'] : 0 ?>"
                   oninput="updateSummary()">
            <span class="dp-hint" id="planStaffHint">Leave 0 to use auto total</span>
          </div>

        </div>
      </div>

      <!-- PLAN SUMMARY + SAVE -->
      <div class="dp-card" style="animation-delay:.13s">
        <div class="dp-card-head">
          <div class="dp-card-icon" style="background:rgba(245,197,24,.16);color:#b8900c">
            <i class="fa-solid fa-clipboard-list"></i>
          </div>
          <span class="dp-card-title">Plan Summary</span>
        </div>
        <div class="dp-card-body">
          <table class="ps-table">
            <tr><td class="ps-key">Delivery Date</td>          <td class="ps-val" id="sBillDate">—</td></tr>
            <tr><td class="ps-key">Delivery Person</td>        <td class="ps-val" id="sDP">—</td></tr>
            <tr><td class="ps-key">Loading Amount</td>         <td class="ps-val ps-amount" id="sLoadingAmount">Rs 0.00</td></tr>
            <tr><td class="ps-key">Vehicle</td>                <td class="ps-val" id="sVehicle">—</td></tr>
            <tr><td class="ps-key">Driver</td>                 <td class="ps-val" id="sDriver">—</td></tr>
            <tr><td class="ps-key">CC Staff</td>               <td class="ps-val" id="sCC">0</td></tr>
            <tr><td class="ps-key">ACC Staff</td>              <td class="ps-val" id="sACC">0</td></tr>
            <tr><td class="ps-key">Driver</td>                 <td class="ps-val" id="sDriverCount">0</td></tr>
            <tr><td class="ps-key">Porters</td>                <td class="ps-val ps-porter-highlight" id="sPorters">0</td></tr>
            <tr><td class="ps-key">Auto Total (CC+ACC+Driver+Porters)</td>
                                                                <td class="ps-val ps-grand-total" id="sAutoTotal">0</td></tr>
            <tr><td class="ps-key">Plan Staff Count <small style="color:var(--orange);font-weight:700">(manual)</small></td>
                                                                <td class="ps-val ps-manual-highlight" id="sStaffCount">0</td></tr>
            <tr><td class="ps-key">Created By</td>             <td class="ps-val"><?= htmlspecialchars($createdByCode . ' — ' . $createdByName) ?></td></tr>
            <?php if ($edit_plan): ?>
            <tr><td class="ps-key">Plan Code</td>              <td class="ps-val"><?= htmlspecialchars($edit_plan['plan_code']) ?></td></tr>
            <tr><td class="ps-key">Last Updated</td>           <td class="ps-val"><?= date('d M Y H:i', strtotime($edit_plan['updated_at'])) ?></td></tr>
            <?php endif; ?>
          </table>

          <button type="submit" class="dp-save-btn <?= $edit_plan ? 'edit-mode' : '' ?>"
                  id="saveBtn" disabled>
            <div class="btn-spinner" id="btnSpinner"></div>
            <i class="fa-solid <?= $edit_plan ? 'fa-pen' : 'fa-floppy-disk' ?>" id="btnIcon"></i>
            <span id="btnLabel"><?= $edit_plan ? 'Update Plan' : 'Save Plan' ?></span>
          </button>
        </div>
      </div>

    </form>
  </div><!-- /scroll -->

  <div class="home-bar-wrap"><div class="home-bar"></div></div>
</div><!-- /shell -->

<div class="toast" id="toast"></div>

<!-- Vehicle→Driver map from PHP -->
<script>
var VEHICLE_DRIVER_MAP = <?= json_encode($vehicleDriverMap, JSON_UNESCAPED_UNICODE) ?>;
</script>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
/* ── THEME ── */
(function(){
  var html=document.documentElement,btn=document.getElementById('themeBtn'),icon=document.getElementById('themeIcon');
  function apply(t){html.setAttribute('data-theme',t);icon.className=t==='dark'?'fa-solid fa-sun':'fa-solid fa-moon';localStorage.setItem('yelo_theme',t);}
  var s=localStorage.getItem('yelo_theme');
  if(s) apply(s);
  else if(window.matchMedia('(prefers-color-scheme:dark)').matches) apply('dark');
  btn.addEventListener('click',function(){apply(html.getAttribute('data-theme')==='dark'?'light':'dark');});
})();

/* ── STATE ── */
var S = {
  staffCC:       [],
  staffACC:      [],
  dpData:        [],
  currentAmount: 0
};

// ── Pre-load staff if editing ──────────────────────────────────────────────
<?php if ($edit_plan && !empty($edit_staff)): ?>
(function(){
  <?php foreach ($edit_staff as $s): ?>
  (function(){
    var type='<?= $s['staff_type'] ?>',eid='<?= addslashes($s['employee_id']) ?>';
    var $sel=document.getElementById(type==='CC'?'ccSelect':'accSelect');
    var opt=$sel?$sel.querySelector('option[value="'+eid+'"]'):null;
    var name=opt?opt.getAttribute('data-name'):eid;
    if(type==='CC') S.staffCC.push({id:eid,name:name||eid});
    else            S.staffACC.push({id:eid,name:name||eid});
  })();
  <?php endforeach; ?>
  renderStaff(); syncHidden();
})();
<?php endif; ?>

<?php if ($edit_plan && isset($edit_plan['loading_amount'])): ?>
S.currentAmount = <?= floatval($edit_plan['loading_amount']) ?>;
<?php endif; ?>

/* ─────────────────────────────────────────────────────────────
   getPortersCount() — reads the porters input safely
───────────────────────────────────────────────────────────── */
function getPortersCount(){
  return Math.max(0, parseInt(document.getElementById('portersInput').value) || 0);
}

/* ─────────────────────────────────────────────────────────────
   changePorters() — stepper buttons +/-
───────────────────────────────────────────────────────────── */
function changePorters(delta){
  var inp = document.getElementById('portersInput');
  var val = Math.max(0, (parseInt(inp.value) || 0) + delta);
  inp.value = val;
  updateAutoCount();
  updateSummary();
}

/* ─────────────────────────────────────────────────────────────
   onPortersInput() — called on manual typing into porters field
───────────────────────────────────────────────────────────── */
function onPortersInput(){
  updateAutoCount();
  updateSummary();
}

/* ─────────────────────────────────────────────────────────────
   updateAutoCount()
   Computes CC + ACC + driver + porters → updates count bar.
   Does NOT touch planStaffCountInput (that is manual).
───────────────────────────────────────────────────────────── */
function updateAutoCount(){
  var driver    = (document.getElementById('driverInput').value || '').trim();
  var staffQty  = S.staffCC.length + S.staffACC.length;
  var sameCc    = driverSameAsCc();
  var driverN   = (driver && !sameCc) ? 1 : 0;          /* driver is also the CC → already counted with the CC */
  if (sameCc && !S.staffCC.length) driverN = 1;          /* no CC picked yet → the driver alone is 1 */
  var portersN  = getPortersCount();
  var autoTotal = staffQty + driverN + portersN;

  document.getElementById('hidAutoStaffCount').value   = autoTotal;
  document.getElementById('cntDriver').textContent     = sameCc ? (driverN ? 1 : '=CC') : driverN;
  document.getElementById('cntPorters').textContent    = portersN;
  document.getElementById('cntAutoTotal').textContent  = autoTotal;

  // Note text
  var note    = document.getElementById('staffCountNote');
  var noteTxt = document.getElementById('staffCountNoteText');
  if(autoTotal > 0){
    note.style.display = 'flex';
    var parts = [];
    if(staffQty) parts.push(staffQty + ' staff');
    if(sameCc)   parts.push('driver = CC (counted once)');
    else if(driverN)  parts.push('1 driver');
    if(portersN) parts.push(portersN + ' porter' + (portersN > 1 ? 's' : ''));
    noteTxt.textContent = 'Auto total: ' + autoTotal + ' (' + parts.join(' + ') + ')';
  } else {
    note.style.display = 'none';
  }
}

/* driver and CC are the same person (checkbox) */
function driverSameAsCc(){ var c = document.getElementById('driverIsCc'); return !!(c && c.checked); }

/* ── VEHICLE CHANGE → auto-fill driver ── */
function onVehicleChange(){
  var vEl    = document.getElementById('vehicleSelect');
  var vid    = vEl ? parseInt(vEl.value) || 0 : 0;
  var dInput = document.getElementById('driverInput');
  var dTag   = document.getElementById('driverAutoTag');

  if(vid && VEHICLE_DRIVER_MAP.hasOwnProperty(vid) && VEHICLE_DRIVER_MAP[vid]){
    dInput.value = VEHICLE_DRIVER_MAP[vid];
    dInput.classList.add('auto-filled');
    dTag.classList.add('visible');
  } else {
    if(dInput.classList.contains('auto-filled')){
      dInput.value = '';
      dInput.classList.remove('auto-filled');
    }
    dTag.classList.remove('visible');
  }
  updateAutoCount();
  updateSummary();
}

/* Detect manual edits to driver → remove auto-filled style */
document.getElementById('driverInput').addEventListener('input', function(){
  this.classList.remove('auto-filled');
  document.getElementById('driverAutoTag').classList.remove('visible');
  updateAutoCount();
  updateSummary();
});

/* ── ADD STAFF ── */
function addStaff(type){
  var sel   = document.getElementById(type==='CC'?'ccSelect':'accSelect');
  var eid   = sel.value;
  var opt   = sel.options[sel.selectedIndex];
  var ename = opt ? opt.getAttribute('data-name') : eid;
  var ecat  = opt ? (opt.getAttribute('data-cat') || type) : type;

  if(!eid){ return; }

  var inCC  = S.staffCC.find(function(s){return s.id===eid;});
  var inACC = S.staffACC.find(function(s){return s.id===eid;});
  if(inCC || inACC){
    showToast('Staff already added.',false);
    sel.value='';
    if(typeof $ !== 'undefined' && $.fn.select2) $(sel).val('').trigger('change');
    return;
  }

  var realType = ecat;
  if(realType==='CC')  S.staffCC.push({id:eid,name:ename||eid});
  else                 S.staffACC.push({id:eid,name:ename||eid});

  sel.value='';
  if(typeof $ !== 'undefined' && $.fn.select2) $(sel).val('').trigger('change');

  renderStaff(); syncHidden(); updateAutoCount(); updateSummary();
}

function removeStaff(type,id){
  if(type==='CC') S.staffCC  = S.staffCC.filter(function(s){return s.id!==id;});
  else            S.staffACC = S.staffACC.filter(function(s){return s.id!==id;});
  renderStaff(); syncHidden(); updateAutoCount(); updateSummary();
}

function renderStaff(){
  var list = document.getElementById('staffList');
  var all  = S.staffCC.map(function(s){return{type:'CC',data:s};})
             .concat(S.staffACC.map(function(s){return{type:'ACC',data:s};}));
  list.innerHTML = all.map(function(item){
    var cc  = item.type==='CC';
    var bg  = cc ? 'rgba(0,122,255,.12)' : 'rgba(175,82,222,.12)';
    var col = cc ? '#007AFF' : '#AF52DE';
    var init= item.data.name.split(' ').map(function(w){return w[0]||'';}).join('').slice(0,2).toUpperCase()||'??';
    return '<div class="staff-item">'+
      '<div class="staff-av" style="background:'+bg+';color:'+col+'">'+init+'</div>'+
      '<span class="staff-name">'+escHtml(item.data.name)+'</span>'+
      '<span class="staff-badge" style="background:'+bg+';color:'+col+'">'+item.type+'</span>'+
      '<button type="button" class="staff-rm" onclick="removeStaff(\''+item.type+'\',\''+escJs(item.data.id)+'\')">&#10005;</button>'+
      '</div>';
  }).join('');
  document.getElementById('cntCC').textContent  = S.staffCC.length;
  document.getElementById('cntACC').textContent = S.staffACC.length;
}

/* ── SYNC HIDDEN ── */
function syncHidden(){
  document.getElementById('hidCcStaff').value  = JSON.stringify(S.staffCC.map(function(s){return s.id;}));
  document.getElementById('hidAccStaff').value = JSON.stringify(S.staffACC.map(function(s){return s.id;}));
}

/* ── LOAD DELIVERY PERSONS by delivery date ── */
function loadDeliveryPersons(){
  var date   = document.getElementById('billDate').value;
  var hint   = document.getElementById('dpHint');
  var wrap   = document.getElementById('dpWrap');
  var banner = document.getElementById('loadingAmountBanner');

  if(!date){
    wrap.innerHTML='<select class="dp-select" id="dpSelect" name="delivery_person" onchange="onDpChange()" required><option value="">— Select date first —</option></select>';
    hint.textContent='Select a delivery date to load persons';
    banner.classList.remove('visible');
    S.dpData=[];S.currentAmount=0;
    updateSummary(); return;
  }

  wrap.innerHTML='<div class="dp-loading"><i class="fa-solid fa-spinner"></i> Loading persons…</div>';
  hint.textContent='Fetching from database…';
  banner.classList.remove('visible');

  fetch('daily_plan.php?ajax=delivery_persons&date='+encodeURIComponent(date))
    .then(function(r){return r.json();})
    .then(function(data){
      S.dpData = data;
      var html='<select class="dp-select" id="dpSelect" name="delivery_person" required>';
      if(!data.length){
        html+='<option value="">No persons found for this date</option>';
        hint.textContent='No delivery persons found for '+date;
      } else {
        html+='<option value="">— Select Person —</option>';
        data.forEach(function(row){
          html+='<option value="'+escHtml(row.delivery_person)+'">'+escHtml(row.delivery_person)+'</option>';
        });
        hint.textContent=data.length+' person(s) found';
      }
      html+='</select>';
      wrap.innerHTML=html;

      if(typeof $ !== 'undefined' && $.fn.select2){
        $('#dpSelect').select2({width:'100%',dropdownAutoWidth:false});
        $('#dpSelect').on('change',function(){onDpChange();});
      } else {
        document.getElementById('dpSelect').addEventListener('change',onDpChange);
      }
      updateSummary();
    })
    .catch(function(){
      wrap.innerHTML='<select class="dp-select" id="dpSelect" name="delivery_person" onchange="onDpChange()" required><option value="">Error loading — retry</option></select>';
      hint.textContent='Failed to load. Check connection.';
      showToast('Could not load delivery persons.',true);
    });
}

/* ── DELIVERY PERSON SELECTION ── */
function onDpChange(){
  var dpEl   = document.getElementById('dpSelect');
  var sel    = dpEl ? dpEl.value : '';
  var banner = document.getElementById('loadingAmountBanner');
  var laVal  = document.getElementById('laValue');
  var laSub  = document.getElementById('laSub');

  if(!sel){
    banner.classList.remove('visible');
    S.currentAmount=0;
    document.getElementById('hidLoadingAmount').value='0';
    updateSummary(); return;
  }

  var row = S.dpData.find(function(r){ return r.delivery_person===sel; });
  var amt = row ? parseFloat(row.loading_amount)||0 : 0;
  S.currentAmount = amt;
  document.getElementById('hidLoadingAmount').value = amt.toFixed(2);

  laVal.textContent = 'Rs ' + amt.toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
  laSub.textContent = 'Total final bill amount for '+sel;
  banner.classList.add('visible');
  updateSummary();
}

/* ─────────────────────────────────────────────────────────────
   updateSummary()
───────────────────────────────────────────────────────────── */
function updateSummary(){
  var date      = document.getElementById('billDate').value || '—';
  var dpEl      = document.getElementById('dpSelect');
  var dp        = dpEl ? dpEl.value : '';
  var vEl       = document.getElementById('vehicleSelect');
  var vTxt      = (vEl && vEl.value) ? vEl.options[vEl.selectedIndex].text : '—';
  var driver    = document.getElementById('driverInput').value || '—';
  var driverN   = (document.getElementById('driverInput').value || '').trim() ? 1 : 0;
  if (driverSameAsCc() && S.staffCC.length) driverN = 0;
  var amt       = S.currentAmount || 0;
  var amtFmt    = 'Rs ' + amt.toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
  var portersN  = getPortersCount();
  var autoTotal = parseInt(document.getElementById('hidAutoStaffCount').value) || 0;
  var manualVal = parseInt(document.getElementById('planStaffCountInput').value) || 0;

  document.getElementById('sBillDate').textContent      = date;
  document.getElementById('sDP').textContent            = dp || '—';
  document.getElementById('sLoadingAmount').textContent = amtFmt;
  document.getElementById('sVehicle').textContent       = (vEl && vEl.value) ? vTxt : '—';
  document.getElementById('sDriver').textContent        = driver;
  document.getElementById('sDriverCount').textContent   = driverSameAsCc() && S.staffCC.length ? '0 (same as CC)' : driverN;
  document.getElementById('sCC').textContent            = S.staffCC.length;
  document.getElementById('sACC').textContent           = S.staffACC.length;
  document.getElementById('sPorters').textContent       = portersN;
  document.getElementById('sAutoTotal').textContent     = autoTotal;
  document.getElementById('sStaffCount').textContent    = manualVal;

  // Update hint under manual input
  var hint = document.getElementById('planStaffHint');
  if(autoTotal > 0){
    hint.textContent = 'Auto total is ' + autoTotal + (driverSameAsCc() ? ' (CC + ACC + porters — driver is the CC)' : ' (CC + ACC + driver + porters)');
  } else {
    hint.textContent = 'Leave 0 to use auto total';
  }

  syncHidden();

  document.getElementById('saveBtn').disabled = !(
    document.getElementById('billDate').value && dpEl && dpEl.value
  );
}

/* ── FORM SUBMIT spinner ── */
document.getElementById('planForm').addEventListener('submit',function(){
  var btn=document.getElementById('saveBtn');
  var sp=document.getElementById('btnSpinner');
  var ic=document.getElementById('btnIcon');
  var lb=document.getElementById('btnLabel');
  btn.disabled=true;
  sp.style.display='block';
  ic.style.display='none';
  lb.textContent='Saving…';
});

/* ── HELPERS ── */
function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function escJs(s){return String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'")}

/* ── TOAST ── */
var _tt;
function showToast(msg,isError){
  var t=document.getElementById('toast');
  t.textContent=msg;
  t.className='toast'+(isError?' error':'');
  void t.offsetWidth;
  t.classList.add('show');
  clearTimeout(_tt);
  _tt=setTimeout(function(){t.classList.remove('show');},3000);
}

/* ── SELECT2 TEMPLATES ── */
function s2FormatOption(option){
  if(!option.id||!option.id.trim()) return option.text;
  var $el  = $(option.element);
  var eid  = $el.data('eid')  || option.id;
  var type = $el.data('cat')  || $el.data('type') || '';
  var name = $el.data('name') || option.text;
  var ccColor ={'CC':'#007AFF','ACC':'#AF52DE'};
  var ccBg    ={'CC':'rgba(0,122,255,.14)','ACC':'rgba(175,82,222,.14)'};
  var col = ccColor[type]||'var(--label2)';
  var bg  = ccBg[type]  ||'var(--bg3)';
  var badge = type
    ? '<span style="display:inline-block;background:'+bg+';color:'+col
      +';font-size:9px;font-weight:800;padding:2px 7px;border-radius:6px;margin-left:7px;vertical-align:middle;">'+type+'</span>'
    : '';
  return $('<span>'
    +'<span class="s2-name">'+$('<span>').text(name).html()+badge+'</span>'
    +'<span class="s2-meta">Employee ID: '+$('<span>').text(eid).html()+'</span>'
    +'</span>');
}
function s2FormatSelection(option){
  if(!option.id||!option.id.trim()) return option.text;
  var $el=$( option.element);
  return $el.data('name')||option.text;
}

function initSelect2(selector,onChangeFn,richTemplate){
  if(typeof $==='undefined'||!$.fn.select2) return;
  var opts={width:'100%',dropdownAutoWidth:false};
  if(richTemplate){opts.templateResult=s2FormatOption;opts.templateSelection=s2FormatSelection;opts.escapeMarkup=function(m){return m;};}
  $(selector).select2(opts);
  if(onChangeFn) $(selector).on('change',onChangeFn);
}

function initAllSelect2(){
  initSelect2('#vehicleSelect', function(){ onVehicleChange(); },   false);
  initSelect2('#ccSelect',      function(){ addStaff('CC');  },     true);
  initSelect2('#accSelect',     function(){ addStaff('ACC'); },     true);
  var dp=document.getElementById('dpSelect');
  if(dp&&dp.tagName==='SELECT') initSelect2('#dpSelect',onDpChange,false);
}

/* ── INIT ── */
window.addEventListener('DOMContentLoaded',function(){
  renderStaff();
  updateAutoCount();
  updateSummary();
  initAllSelect2();

  <?php if ($edit_plan && !empty($edit_plan['driver_name'])): ?>
  document.getElementById('driverInput').classList.add('auto-filled');
  document.getElementById('driverAutoTag').classList.add('visible');
  <?php endif; ?>

  <?php if ($edit_plan): ?>
  loadDeliveryPersonsForEdit(
    '<?= addslashes($edit_plan['delivery_date']) ?>',
    '<?= addslashes($edit_plan['delivery_person']) ?>',
    <?= floatval($edit_plan['loading_amount'] ?? 0) ?>
  );
  <?php endif; ?>
});

<?php if ($edit_plan): ?>
function loadDeliveryPersonsForEdit(date,selected,savedAmt){
  fetch('daily_plan.php?ajax=delivery_persons&date='+encodeURIComponent(date))
    .then(function(r){return r.json();})
    .then(function(data){
      S.dpData=data;
      var wrap=document.getElementById('dpWrap');
      var html='<select class="dp-select" id="dpSelect" name="delivery_person" required>';
      html+='<option value="">— Select Person —</option>';
      data.forEach(function(row){
        var sel=row.delivery_person===selected?' selected':'';
        html+='<option value="'+escHtml(row.delivery_person)+'"'+sel+'>'+escHtml(row.delivery_person)+'</option>';
      });
      html+='</select>';
      wrap.innerHTML=html;
      document.getElementById('dpHint').textContent=data.length+' person(s) found';

      initSelect2('#dpSelect',onDpChange,false);

      var row=data.find(function(r){return r.delivery_person===selected;});
      S.currentAmount = row ? (parseFloat(row.loading_amount)||0) : (savedAmt||0);
      document.getElementById('hidLoadingAmount').value=S.currentAmount.toFixed(2);

      if(selected&&S.currentAmount>0){
        document.getElementById('laValue').textContent='Rs '+S.currentAmount.toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
        document.getElementById('laSub').textContent='Total final bill amount for '+selected;
        document.getElementById('loadingAmountBanner').classList.add('visible');
      }
      updateSummary();
    });
}
<?php endif; ?>
</script>
</body>
</html>