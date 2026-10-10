<?php
include 'config.php';
/* ══════════════════════════════════════════════════════════════
   daily_delivery_monitor.php
   Daily Delivery Monitor Summary — Delivery Date wise

   One row block per Delivery Person (CC) per delivery date:
     Lorry            : selected in the modal (vehicles table, Select2)
     CC               : delivery person of the field summary
                        (fs.delivery_person_raw_name, same as fs_se.php)
                        — can be changed in the modal (employees, Select2)
     No of Bills      : all bills of that CC on the date (Edit Field Summary)
     Unload Bills     : bills with a saved Cancel Bill Reason AND Diff 1 ≠ 0
                        (Diff 1 = Net Invoice Value − Ikea Value; a bill that is
                        not in the Ikea import counts as Ikea 0).
                        This is exactly when fs_se.php shows the
                        "Remarks: Cancel bill reason" select.
     Delivered Bills  : No of Bills − Unload Bills
     Values           : total Net Invoice Value of all the CC's bills
     Unload Bills list: customer name + value, Reason = saved cancel reason
     Start / End time : entered manually in the modal

   Effective date = TBD date when the row is To-Be-Delivery (same as fs_se.php)
   Ikea value     = secondary_invoice_import_details (status 'imported'),
                    looked up live by effective date + bill no (same as fs_se.php)
   Export = real .xlsx laid out like the Excel sheet.
══════════════════════════════════════════════════════════════ */

/* Unload Value per bill: 'net'   = Net Invoice Value of the bill
                          'diff1' = absolute Diff 1 (Net Invoice − Ikea), as shown on fs_se.php */
if (!defined('DM_UNLOAD_VALUE_MODE')) define('DM_UNLOAD_VALUE_MODE', 'net');

function dm_col_exists($conn, $table, $col) {
    $col = mysqli_real_escape_string($conn, $col);
    $r = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
    return ($r && mysqli_num_rows($r) > 0);
}
function dm_date_col($conn) {
    foreach (['delivery_date', 'visit_date', 'summary_date'] as $c) {
        if (dm_col_exists($conn, 'field_summary', $c)) return $c;
    }
    return 'delivery_date';
}
function dm_out($a) { echo json_encode($a); exit; }
function dm_time_label($t) {
    $t = trim((string)$t);
    if ($t === '') return '';
    $ts = strtotime('2000-01-01 ' . $t);
    return $ts ? date('g.i A', $ts) : $t;
}
function dm_group_key($dp, $fs_code) {
    $dp = trim(preg_replace('/\s+/', ' ', (string)$dp));
    return $dp !== '' ? 'DP:' . mb_strtolower($dp, 'UTF-8') : 'FS:' . $fs_code;
}

/* ── schema safety nets ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS delivery_monitor_entries (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    delivery_date   DATE          NOT NULL,
    group_key       VARCHAR(255)  NOT NULL,
    delivery_person VARCHAR(255)  NULL,
    vehicle_id      INT           NULL,
    vehicle_number  VARCHAR(50)   NULL,
    cc_employee_id  INT           NULL,
    cc_name         VARCHAR(255)  NULL,
    start_time      VARCHAR(10)   NULL,
    end_time        VARCHAR(10)   NULL,
    updated_by      VARCHAR(100)  NULL,
    updated_at      DATETIME      NULL,
    UNIQUE KEY uq_date_group (delivery_date, group_key),
    INDEX idx_date (delivery_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
/* Cancel bill reasons — same definitions as fs_se.php / bill_cancel_reasons.php */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS bill_cancel_reasons (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    reason     VARCHAR(255) NOT NULL,
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS canceled_bill_reasons (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    field_summary_detail_id INT          NOT NULL,
    invoice_num             VARCHAR(100) NOT NULL,
    reason_id               INT          NOT NULL,
    reason_text             VARCHAR(255) NOT NULL,
    updated_by              VARCHAR(100) NULL,
    updated_at              DATETIME     NOT NULL,
    UNIQUE KEY uq_detail (field_summary_detail_id),
    INDEX idx_reason (reason_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if (!dm_col_exists($conn, 'field_summary', 'delivery_person_raw_name'))
    mysqli_query($conn, "ALTER TABLE field_summary ADD COLUMN delivery_person_raw_name VARCHAR(255) DEFAULT NULL");
if (!dm_col_exists($conn, 'field_summary_details', 'to_be_delivery'))
    mysqli_query($conn, "ALTER TABLE field_summary_details ADD COLUMN to_be_delivery TINYINT(1) NOT NULL DEFAULT 0");
if (!dm_col_exists($conn, 'field_summary_details', 'to_be_delivery_date'))
    mysqli_query($conn, "ALTER TABLE field_summary_details ADD COLUMN to_be_delivery_date DATE NULL DEFAULT NULL");

/* ══════════════════════════════════════════
   AJAX: save lorry / CC / start / end time for one row
══════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'save_entry') {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') dm_out(['success' => false, 'error' => 'POST required']);

    $date      = trim($_POST['delivery_date'] ?? '');
    $group_key = trim($_POST['group_key'] ?? '');
    $dp        = trim($_POST['delivery_person'] ?? '');
    $veh_id    = intval($_POST['vehicle_id'] ?? 0);
    $cc_val    = trim($_POST['cc'] ?? '');
    $start     = trim($_POST['start_time'] ?? '');
    $end       = trim($_POST['end_time'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) dm_out(['success' => false, 'error' => 'Invalid delivery date']);
    if ($group_key === '' || mb_strlen($group_key) > 255) dm_out(['success' => false, 'error' => 'Row key is missing']);
    foreach (['Start time' => $start, 'End time' => $end] as $lbl => $t) {
        if ($t !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) dm_out(['success' => false, 'error' => "$lbl is not valid"]);
    }

    /* lorry */
    $veh_no = null;
    if ($veh_id > 0) {
        $vr = mysqli_query($conn, "SELECT id, vehicle_number FROM vehicles WHERE id = $veh_id LIMIT 1");
        $v  = $vr ? mysqli_fetch_assoc($vr) : null;
        if (!$v) dm_out(['success' => false, 'error' => 'Selected lorry not found']);
        $veh_no = $v['vehicle_number'];
    } else { $veh_id = 0; }

    /* CC — "e:<id>" = employee, anything else = typed name */
    $cc_emp = 0; $cc_name = '';
    if (preg_match('/^e:(\d+)$/', $cc_val, $m)) {
        $cc_emp = intval($m[1]);
        $er = mysqli_query($conn, "SELECT id, employee_full_name, name_with_initials FROM employees WHERE id = $cc_emp LIMIT 1");
        $e  = $er ? mysqli_fetch_assoc($er) : null;
        if (!$e) dm_out(['success' => false, 'error' => 'Selected CC not found']);
        $cc_name = trim($e['name_with_initials'] ?: $e['employee_full_name']);
    } else {
        $cc_name = mb_substr(preg_replace('/^t:/', '', $cc_val), 0, 255);
    }
    if ($cc_name === '') $cc_name = $dp;

    if (session_status() === PHP_SESSION_NONE) @session_start();
    $by = '';
    foreach (['username', 'user_name', 'name', 'full_name', 'user_id'] as $k) {
        if (!empty($_SESSION[$k]) && is_scalar($_SESSION[$k])) { $by = (string)$_SESSION[$k]; break; }
    }
    $q = function ($v) use ($conn) { return ($v === null || $v === '') ? 'NULL' : "'" . mysqli_real_escape_string($conn, (string)$v) . "'"; };
    $now = date('Y-m-d H:i:s');

    $ok = mysqli_query($conn, "INSERT INTO delivery_monitor_entries
            (delivery_date, group_key, delivery_person, vehicle_id, vehicle_number, cc_employee_id, cc_name, start_time, end_time, updated_by, updated_at)
        VALUES (" . $q($date) . ", " . $q($group_key) . ", " . $q($dp) . ", " . ($veh_id ?: 'NULL') . ", " . $q($veh_no) . ",
                " . ($cc_emp ?: 'NULL') . ", " . $q($cc_name) . ", " . $q($start) . ", " . $q($end) . ", " . $q(mb_substr($by, 0, 100)) . ", '$now')
        ON DUPLICATE KEY UPDATE delivery_person = VALUES(delivery_person), vehicle_id = VALUES(vehicle_id),
            vehicle_number = VALUES(vehicle_number), cc_employee_id = VALUES(cc_employee_id), cc_name = VALUES(cc_name),
            start_time = VALUES(start_time), end_time = VALUES(end_time), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
    if (!$ok) dm_out(['success' => false, 'error' => 'Database error: ' . mysqli_error($conn)]);

    dm_out(['success' => true, 'entry' => [
        'vehicle_id'     => $veh_id,
        'vehicle_number' => (string)$veh_no,
        'cc'             => $cc_emp ? 'e:' . $cc_emp : 't:' . $cc_name,
        'cc_name'        => $cc_name,
        'start_time'     => $start,
        'end_time'       => $end,
        'start_label'    => dm_time_label($start),
        'end_label'      => dm_time_label($end),
    ]]);
}

/* ══════════════════════════════════════════
   FILTERS
══════════════════════════════════════════ */
$today       = date('Y-m-d');
$date_from   = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : $today;
$date_to     = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? $_GET['date_to']   : $today;
$only_unload = isset($_GET['applied']) ? (isset($_GET['only_unload']) ? 1 : 0) : 1;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = $today;
if ($date_from > $date_to) { $tmp = $date_from; $date_from = $date_to; $date_to = $tmp; }
$df = mysqli_real_escape_string($conn, $date_from);
$dt = mysqli_real_escape_string($conn, $date_to);

$date_col = dm_date_col($conn);
$eff = "IF(d.to_be_delivery = 1 AND d.to_be_delivery_date IS NOT NULL, d.to_be_delivery_date, fs.`$date_col`)";

/* ── all bills in the period ── */
$sql = "SELECT fs.id AS fs_id, fs.field_summary_code, $eff AS delivery_date,
               fs.delivery_person_raw_name AS delivery_person,
               d.id AS detail_id, d.invoice_num,
               COALESCE(NULLIF(d.customer_name,''), c.shop_name, d.invoice_num) AS customer_name,
               d.net_value,
               cbr.reason_text
        FROM field_summary fs
        JOIN field_summary_details d ON d.field_summary_id = fs.id
        LEFT JOIN customers c ON c.t_code = d.t_code
        LEFT JOIN canceled_bill_reasons cbr ON cbr.field_summary_detail_id = d.id
        WHERE $eff BETWEEN '$df' AND '$dt'
        ORDER BY $eff ASC, fs.delivery_person_raw_name ASC, d.invoice_num ASC";
$res  = mysqli_query($conn, $sql);
$rows = [];
if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;

/* ── Ikea value lookup (for Diff 1) — same source as fs_se.php ── */
$sinv = [];
$dates = array_unique(array_column($rows, 'delivery_date'));
if ($dates) {
    $in = implode(',', array_map(function ($d) use ($conn) { return "'" . mysqli_real_escape_string($conn, $d) . "'"; }, $dates));
    $sq = mysqli_query($conn, "SELECT delivery_date, bill_no, final_bill_amount FROM secondary_invoice_import_details
                               WHERE delivery_date IN ($in) AND status = 'imported'");
    if ($sq) while ($s = mysqli_fetch_assoc($sq)) $sinv[$s['delivery_date']][trim($s['bill_no'])] = floatval($s['final_bill_amount']);
}

/* ── saved lorry / CC / times ── */
$entries = [];
$er = mysqli_query($conn, "SELECT * FROM delivery_monitor_entries WHERE delivery_date BETWEEN '$df' AND '$dt'");
if ($er) while ($e = mysqli_fetch_assoc($er)) $entries[$e['delivery_date']][$e['group_key']] = $e;

/* ── masters for the modal ── */
$vehicles = [];
$vr = mysqli_query($conn, "SELECT id, vehicle_number, driver_name FROM vehicles WHERE active = 1 ORDER BY vehicle_number ASC");
if ($vr) while ($v = mysqli_fetch_assoc($vr)) $vehicles[] = $v;

$employees = [];
$emr = mysqli_query($conn, "SELECT e.id, e.employee_id, e.employee_full_name, e.name_with_initials, d.designation_name
                            FROM employees e LEFT JOIN designations d ON d.id = e.designation_id
                            WHERE e.active = 1 ORDER BY e.employee_full_name ASC");
if ($emr) while ($e = mysqli_fetch_assoc($emr)) $employees[] = $e;
$emp_by_name = [];
foreach ($employees as $e) {
    foreach ([$e['employee_full_name'], $e['name_with_initials']] as $n) {
        $n = mb_strtolower(trim((string)$n), 'UTF-8');
        if ($n !== '' && !isset($emp_by_name[$n])) $emp_by_name[$n] = intval($e['id']);
    }
}

/* ══════════════════════════════════════════
   BUILD: date → CC group
══════════════════════════════════════════ */
$report = [];
foreach ($rows as $r) {
    $d   = $r['delivery_date'];
    $dp  = trim(preg_replace('/\s+/', ' ', (string)$r['delivery_person']));
    $key = dm_group_key($dp, $r['field_summary_code']);
    if (!isset($report[$d])) $report[$d] = ['groups' => [], 'all_values' => 0.0];
    if (!isset($report[$d]['groups'][$key])) {
        $report[$d]['groups'][$key] = ['key' => $key, 'dp' => $dp !== '' ? $dp : $r['field_summary_code'],
            'fs_codes' => [], 'bills' => 0, 'values' => 0.0, 'unload' => [], 'unload_value' => 0.0];
    }
    $g =& $report[$d]['groups'][$key];
    $g['fs_codes'][$r['field_summary_code']] = intval($r['fs_id']);

    /* Diff 1 = Net Invoice Value − Ikea Value — same rule as fs_se.php */
    $inv        = trim($r['invoice_num']);
    $ikea_found = isset($sinv[$d][$inv]);
    $ikea       = $ikea_found ? $sinv[$d][$inv] : 0.0;
    $net        = floatval($r['net_value']);
    $diff1      = ($ikea == 0 && $net == 0) ? 0.0 : round($net - $ikea, 2);

    $g['bills']++;
    $g['values'] += $net;
    $report[$d]['all_values'] += $net;

    /* Unload bill = saved cancel reason on a bill whose Diff 1 ≠ 0
       (fs_se.php only offers the cancel reason select in that case) */
    $reason = trim((string)($r['reason_text'] ?? ''));
    if ($reason !== '' && abs($diff1) >= 0.005) {
        $uv = DM_UNLOAD_VALUE_MODE === 'diff1' ? abs($diff1) : $net;
        $g['unload'][] = ['customer' => $r['customer_name'], 'invoice' => $inv, 'value' => $uv, 'reason' => $reason,
                          'ikea_missing' => !$ikea_found, 'diff1' => $diff1];
        $g['unload_value'] += $uv;
    }
    unset($g);
}

/* attach entries, filter, sort, totals */
$period = ['bills' => 0, 'unload' => 0, 'delivered' => 0, 'values' => 0.0, 'unload_value' => 0.0, 'all_values' => 0.0];
foreach ($report as $d => &$day) {
    $day['all_values'] = round($day['all_values'], 2);
    $period['all_values'] += $day['all_values'];
    $tot = ['bills' => 0, 'unload' => 0, 'delivered' => 0, 'values' => 0.0, 'unload_value' => 0.0];
    foreach ($day['groups'] as $k => &$g) {
        if ($only_unload && !$g['unload']) { unset($day['groups'][$k]); continue; }
        $e = $entries[$d][$k] ?? null;
        $auto_emp = $emp_by_name[mb_strtolower($g['dp'], 'UTF-8')] ?? 0;
        $g['entry'] = [
            'vehicle_id'     => $e ? intval($e['vehicle_id']) : 0,
            'vehicle_number' => $e ? (string)$e['vehicle_number'] : '',
            'cc_name'        => ($e && $e['cc_name'] !== null && $e['cc_name'] !== '') ? $e['cc_name'] : $g['dp'],
            'cc'             => ($e && intval($e['cc_employee_id']) > 0) ? 'e:' . intval($e['cc_employee_id'])
                              : (($e && $e['cc_name']) ? 't:' . $e['cc_name'] : ($auto_emp ? 'e:' . $auto_emp : 't:' . $g['dp'])),
            'start_time'     => $e ? (string)$e['start_time'] : '',
            'end_time'       => $e ? (string)$e['end_time'] : '',
        ];
        $g['n_unload']    = count($g['unload']);
        $g['delivered']   = $g['bills'] - $g['n_unload'];
        $tot['bills']        += $g['bills'];
        $tot['unload']       += $g['n_unload'];
        $tot['delivered']    += $g['delivered'];
        $tot['values']       += $g['values'];
        $tot['unload_value'] += $g['unload_value'];
    }
    unset($g);
    uasort($day['groups'], function ($a, $b) { return strcasecmp($a['entry']['cc_name'], $b['entry']['cc_name']); });
    $day['tot'] = $tot;
    foreach ($tot as $k => $v) $period[$k] += $v;
}
unset($day);
$report = array_filter($report, function ($day) { return !empty($day['groups']) || $day['all_values'] > 0; });
$title = 'Daily Delivery Monitor Summary - ' . $date_from . ($date_to !== $date_from ? ' to ' . $date_to : '');

/* ══════════════════════════════════════════
   EXCEL EXPORT (.xlsx, same layout as the sheet)
══════════════════════════════════════════ */
function dm_xl_col($i) { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intval(($i - $m) / 26); } return $s; }
function dm_xl_esc($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    /* style ids (see styles.xml below) */
    $S = ['title'=>1,'head'=>2,'txt'=>3,'int'=>4,'money'=>5,'utxt'=>6,'umoney'=>7,'dmlbl'=>8,'dmint'=>9,
          'dmmoney'=>10,'glbl'=>11,'gmoney'=>12,'gblank'=>13,'date'=>14,'dmblank'=>15,'ctxt'=>16,'dmintr'=>17,'ublank'=>18];
    $NC = 12;
    $sheet = []; $merges = []; $rn = 0;
    $add = function (array $cells) use (&$sheet, &$rn) { $rn++; $sheet[$rn] = $cells; return $rn; };
    $blank = function ($style) use ($NC) { return array_fill(0, $NC, [null, $style]); };

    $r = $add([[$title, $S['title']]] + array_fill(1, $NC - 1, [null, $S['title']]));
    $merges[] = "A$r:L$r";
    $add(array_map(function ($h) use ($S) { return [$h, $S['head']]; },
        ['Date','Lorry','CC','No of Bills','Number of Unload Bills','Delived Bills','Values','Unload Bills','Unload Value','Reason','Start time','End Time']));

    foreach ($report as $d => $day) {
        $first_of_date = true;
        foreach ($day['groups'] as $g) {
            $e = $g['entry'];
            $n = max(1, count($g['unload']));
            for ($i = 0; $i < $n; $i++) {
                $u = $g['unload'][$i] ?? null;
                $row = $blank($S['txt']);
                $row[7] = [null, $S['ublank']]; $row[8] = [null, $S['ublank']];
                if ($i === 0) {
                    if ($first_of_date) { $row[0] = [date('n/j/Y', strtotime($d)), $S['date']]; $first_of_date = false; }
                    $row[1]  = [$e['vehicle_number'], $S['ctxt']];
                    $row[2]  = [$e['cc_name'], $S['txt']];
                    $row[3]  = [$g['bills'], $S['int']];
                    $row[4]  = [$g['n_unload'], $S['int']];
                    $row[5]  = [$g['delivered'], $S['int']];
                    $row[6]  = [round($g['values'], 2), $S['money']];
                    $row[10] = [dm_time_label($e['start_time']), $S['txt']];
                    $row[11] = [dm_time_label($e['end_time']), $S['txt']];
                }
                if ($u) {
                    $row[7] = [$u['customer'], $S['utxt']];
                    $row[8] = [round($u['value'], 2), $S['umoney']];
                    $row[9] = [$u['reason'], $S['txt']];
                }
                $add($row);
            }
        }
        $t = $day['tot'];
        $row = $blank($S['dmblank']);
        $row[0] = ['DM Total', $S['dmlbl']];
        $row[3] = [$t['bills'], $S['dmint']];
        $row[4] = [$t['unload'], $S['dmint']];
        $row[5] = [$t['delivered'], $S['dmint']];
        $row[6] = [round($t['values'], 2), $S['dmmoney']];
        $row[7] = [$t['unload'], $S['dmintr']];
        $row[8] = [round($t['unload_value'], 2), $S['dmmoney']];
        $add($row);
        $add($blank($S['txt']));
        $add($blank($S['txt']));
        $row = $blank($S['gblank']);
        $row[0] = [$d . ' Grand Total', $S['glbl']];
        $row[8] = [round($day['all_values'], 2), $S['gmoney']];
        $gr = $add($row);
        $merges[] = "A$gr:B$gr";
        $add($blank(0));
    }
    if (count($report) > 1) {
        $row = $blank($S['gblank']);
        $row[0] = ['Period Grand Total', $S['glbl']];
        $row[3] = [$period['bills'], $S['dmint']];
        $row[4] = [$period['unload'], $S['dmint']];
        $row[5] = [$period['delivered'], $S['dmint']];
        $row[6] = [round($period['values'], 2), $S['gmoney']];
        $row[8] = [round($period['all_values'], 2), $S['gmoney']];
        $gr = $add($row);
        $merges[] = "A$gr:B$gr";
    }

    /* sheet xml */
    $widths = [13, 12, 18, 10, 20, 13, 14, 20, 14, 38, 11, 11];
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
       . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
       . '<sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
       . '<cols>';
    foreach ($widths as $i => $w) $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    $x .= '</cols><sheetData>';
    foreach ($sheet as $rowNo => $cells) {
        $x .= '<row r="' . $rowNo . '"' . ($rowNo === 1 ? ' ht="24" customHeight="1"' : '') . '>';
        foreach ($cells as $ci => $cell) {
            list($val, $st) = $cell;
            $ref = dm_xl_col($ci) . $rowNo;
            if ($val === null || $val === '') $x .= '<c r="' . $ref . '" s="' . $st . '"/>';
            elseif (is_int($val) || is_float($val)) $x .= '<c r="' . $ref . '" s="' . $st . '"><v>' . $val . '</v></c>';
            else $x .= '<c r="' . $ref . '" s="' . $st . '" t="inlineStr"><is><t xml:space="preserve">' . dm_xl_esc($val) . '</t></is></c>';
        }
        $x .= '</row>';
    }
    $x .= '</sheetData>';
    if ($merges) { $x .= '<mergeCells count="' . count($merges) . '">'; foreach ($merges as $m) $x .= '<mergeCell ref="' . $m . '"/>'; $x .= '</mergeCells>'; }
    $x .= '<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
        . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';

    $b  = '<border><left style="thin"><color rgb="FF000000"/></left><right style="thin"><color rgb="FF000000"/></right><top style="thin"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom><diagonal/></border>';
    $xf = function ($font, $fill, $border, $numFmt, $h) {
        return '<xf numFmtId="' . $numFmt . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0"'
             . ($numFmt ? ' applyNumberFormat="1"' : '') . ' applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
             . '<alignment horizontal="' . $h . '" vertical="center"/></xf>';
    };
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><u/><sz val="14"/><name val="Calibri"/></font></fonts>'
        . '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFA9D08E"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFDDEBF7"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF2CC"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>' . $b . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="19">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'  /* 0 default */
        . $xf(2, 0, 0, 0, 'center')     /* 1 title */
        . $xf(1, 2, 1, 0, 'center')     /* 2 header */
        . $xf(0, 0, 1, 0, 'left')       /* 3 text */
        . $xf(0, 0, 1, 1, 'center')     /* 4 int */
        . $xf(0, 0, 1, 4, 'right')      /* 5 money */
        . $xf(0, 3, 1, 0, 'left')       /* 6 unload text */
        . $xf(0, 3, 1, 4, 'right')      /* 7 unload money */
        . $xf(1, 3, 1, 0, 'center')     /* 8 DM label */
        . $xf(0, 3, 1, 1, 'center')     /* 9 DM int */
        . $xf(0, 3, 1, 4, 'right')      /* 10 DM money */
        . $xf(1, 4, 1, 0, 'center')     /* 11 grand label */
        . $xf(1, 4, 1, 4, 'right')      /* 12 grand money */
        . $xf(0, 4, 1, 0, 'left')       /* 13 grand blank */
        . $xf(1, 0, 1, 0, 'center')     /* 14 date */
        . $xf(0, 3, 1, 0, 'left')       /* 15 DM blank */
        . $xf(0, 0, 1, 0, 'center')     /* 16 centred text */
        . $xf(0, 3, 1, 1, 'right')      /* 17 DM int right */
        . $xf(0, 3, 1, 0, 'left')       /* 18 unload blank */
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="DM Summary" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $x,
    ];

    $fname = 'daily_delivery_monitor_' . $date_from . '_to_' . $date_to . '.xlsx';
    if (!class_exists('ZipArchive')) { http_response_code(500); echo 'PHP zip extension (ZipArchive) is required for Excel export.'; exit; }
    $tmp = tempnam(sys_get_temp_dir(), 'dmx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($files as $path => $content) $zip->addFromString($path, $content);
    $zip->close();
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: max-age=0');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

include 'header.php';
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$export_qs = http_build_query(['date_from' => $date_from, 'date_to' => $date_to, 'applied' => 1, 'only_unload' => $only_unload ?: null, 'export' => 'xlsx']);
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;text-decoration:none;}
.btn-primary{background:#000;color:#fff;}.btn-primary:hover{background:#333;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-export{background:#166534;color:#fff;}.btn-export:hover{background:#14532d;}
.filter-bar{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;background:#fafafa;border:1px solid #e5e5e5;border-radius:8px;padding:14px 16px;margin-bottom:10px;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.04em;}
.fctrl{padding:8px 10px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;color:#333;background:#fff;outline:none;min-width:150px;}
.fctrl:focus{border-color:#000;}
.chk-fg{display:flex;align-items:center;gap:6px;padding-bottom:8px;}
.chk-fg label{font-size:12px;font-weight:600;color:#374151;cursor:pointer;}
.quick-range{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;}
.quick-range button{padding:6px 10px;border:1px solid #e0e0e0;border-radius:5px;background:#fff;font-size:11px;font-weight:600;color:#374151;cursor:pointer;}
.quick-range button:hover{background:#f0f0f0;}
.hint{font-size:12px;color:#6b7280;margin-bottom:12px;}

/* ─── Excel-like report ─── */
.dm-wrap{overflow:auto;max-height:75vh;border:1px solid #d1d5db;border-radius:6px;}
.dm-title{text-align:center;font-weight:800;font-size:17px;text-decoration:underline;padding:12px 0 10px;color:#111;font-family:Calibri,'Inter',sans-serif;}
.dm{border-collapse:collapse;width:100%;min-width:1250px;font-family:Calibri,'Inter',sans-serif;font-size:13.5px;}
.dm th,.dm td{border:1px solid #1f2937;padding:3px 6px;white-space:nowrap;color:#111;}
.dm thead th{background:#a9d08e;font-weight:800;text-align:center;position:sticky;top:0;z-index:5;box-shadow:0 1px 0 #1f2937;}
.dm td.c{text-align:center;}.dm td.r{text-align:right;}
.dm td.u{background:#ddebf7;}
.dm td.date{font-weight:700;text-align:center;}
.dm tr.dm-total td{background:#ddebf7;font-weight:700;}
.dm tr.grand td{background:#fff2cc;font-weight:800;}
.dm tr.spacer td{border-left:1px solid #e5e7eb;border-right:1px solid #e5e7eb;height:20px;}
.dm tr.grp-first td{border-top:2px solid #111;}
.dm tbody tr:not(.dm-total):not(.grand):not(.spacer):hover td{background:#fef9c3;}
.edit-cell{cursor:pointer;position:relative;}
.edit-cell:hover{outline:2px solid #7c3aed;outline-offset:-2px;}
.pick{display:inline-flex;align-items:center;gap:4px;color:#7c3aed;font-size:11.5px;font-weight:700;border:1px dashed #c4b5fd;border-radius:4px;padding:1px 6px;background:#faf5ff;}
.fs-hint{display:block;font-size:10px;color:#6b7280;font-weight:500;}
.ikea-miss{display:inline-block;margin-left:5px;padding:0 5px;border-radius:3px;font-size:10px;font-weight:800;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;vertical-align:middle;}
.no-data{text-align:center;padding:40px;color:#9ca3af;}

/* ─── modal ─── */
.modal-backdrop{position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;padding:16px;}
.modal-backdrop.open{display:flex;}
.modal-dialog{background:#fff;border-radius:12px;width:100%;max-width:520px;box-shadow:0 24px 80px rgba(0,0,0,.25);overflow:visible;}
.modal-header{display:flex;justify-content:space-between;align-items:flex-start;padding:16px 20px;border-bottom:1px solid #e5e5e5;background:#fafafa;border-radius:12px 12px 0 0;}
.modal-header h3{font-size:16px;font-weight:700;margin:0;color:#1f2937;}
.modal-sub{font-size:12px;color:#6b7280;margin-top:3px;}
.modal-close{width:32px;height:32px;border-radius:7px;border:1px solid #e5e5e5;background:#fff;cursor:pointer;}
.modal-body{padding:18px 20px;display:flex;flex-direction:column;gap:14px;}
.gr2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.modal-footer{padding:13px 20px;border-top:1px solid #e5e5e5;background:#fafafa;display:flex;justify-content:flex-end;gap:8px;border-radius:0 0 12px 12px;}
.btn-save{background:#7c3aed;color:#fff;}.btn-save:hover{background:#6d28d9;}.btn-save:disabled{opacity:.6;}
.select2-container{width:100%!important;}
.select2-container--default .select2-selection--single{height:37px!important;border:1px solid #e0e0e0!important;border-radius:6px!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:35px!important;padding-left:10px!important;font-size:13px!important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:35px!important;}
.select2-dropdown{border:1px solid #e0e0e0!important;border-radius:6px!important;font-size:13px!important;z-index:1000001!important;}
.select2-results__option--highlighted{background:#000!important;}
#dmToast{position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:10000000;padding:12px 26px;border-radius:10px;font-size:14px;font-weight:700;box-shadow:0 8px 32px rgba(0,0,0,.25);display:none;}
.toast-ok{background:#166534;color:#fff;}.toast-err{background:#dc2626;color:#fff;}
@media(max-width:600px){.gr2{grid-template-columns:1fr;}}
</style>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <div>
      <h2 class="page-title"><i class="fa-solid fa-truck-fast"></i> Daily Delivery Monitor Summary</h2>
      <p class="page-subtitle">Delivery date wise. Unload bills = bills with a saved cancel reason where Diff 1 (Net Invoice − Ikea) ≠ 0, set on the Short / Excess report.</p>
    </div>
    <div style="display:flex;gap:8px;">
      <a href="fs_se.php?date_from=<?php echo $h($date_from); ?>&date_to=<?php echo $h($date_to); ?>" class="btn btn-secondary"><i class="fa-solid fa-scale-balanced"></i> Short / Excess</a>
      <a href="bill_cancel_reasons.php" class="btn btn-secondary"><i class="fa-solid fa-ban"></i> Cancel Reasons</a>
      <a href="field_summary_list.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>
  </div>
</div>

<div class="content-card">
  <form method="GET" action="daily_delivery_monitor.php" id="filterForm">
    <input type="hidden" name="applied" value="1">
    <div class="filter-bar">
      <div class="fg"><label>Delivery Date From</label><input type="date" name="date_from" id="dateFrom" class="fctrl" value="<?php echo $h($date_from); ?>"></div>
      <div class="fg"><label>Delivery Date To</label><input type="date" name="date_to" id="dateTo" class="fctrl" value="<?php echo $h($date_to); ?>"></div>
      <div class="chk-fg">
        <input type="checkbox" name="only_unload" id="onlyUnload" value="1" <?php echo $only_unload ? 'checked' : ''; ?>>
        <label for="onlyUnload">Only lorries with unload bills</label>
      </div>
      <div class="fg"><label>&nbsp;</label><button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> View</button></div>
      <div class="fg"><label>&nbsp;</label><a class="btn btn-export" href="daily_delivery_monitor.php?<?php echo $h($export_qs); ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a></div>
    </div>
    <div class="quick-range">
      <button type="button" onclick="setRange(0,0)">Today</button>
      <button type="button" onclick="setRange(1,1)">Yesterday</button>
      <button type="button" onclick="setRange(6,0)">Last 7 Days</button>
      <button type="button" onclick="setRange(29,0)">Last 30 Days</button>
    </div>
  </form>
  <div class="hint"><i class="fa-solid fa-hand-pointer"></i> Click a <b>Lorry</b>, <b>CC</b>, <b>Start time</b> or <b>End Time</b> cell to select the lorry / CC and enter the times.</div>

  <div class="dm-wrap">
    <div class="dm-title"><?php echo $h($title); ?></div>
    <table class="dm">
      <thead><tr>
        <th>Date</th><th>Lorry</th><th>CC</th><th>No of Bills</th><th>Number of Unload Bills</th><th>Delived Bills</th>
        <th>Values</th><th>Unload Bills</th><th>Unload Value</th><th>Reason</th><th>Start time</th><th>End Time</th>
      </tr></thead>
      <tbody>
      <?php if (!$report): ?>
        <tr><td colspan="12"><div class="no-data"><i class="fa-solid fa-inbox"></i> No deliveries found for the selected dates.</div></td></tr>
      <?php else: $gid = 0;
        foreach ($report as $d => $day):
          $first_of_date = true;
          foreach ($day['groups'] as $g):
            $gid++; $e = $g['entry'];
            $n = max(1, count($g['unload']));
            $payload = [
              'gid' => $gid, 'date' => $d, 'key' => $g['key'], 'dp' => $g['dp'],
              'fs' => implode(', ', array_keys($g['fs_codes'])),
              'vehicle_id' => $e['vehicle_id'], 'vehicle_number' => $e['vehicle_number'],
              'cc' => $e['cc'], 'cc_name' => $e['cc_name'], 'start_time' => $e['start_time'], 'end_time' => $e['end_time'],
            ];
            $attr = 'data-row="' . $h(json_encode($payload)) . '"';
            for ($i = 0; $i < $n; $i++):
              $u = $g['unload'][$i] ?? null; ?>
          <tr class="<?php echo $i === 0 ? 'grp-first' : ''; ?>" id="<?php echo $i === 0 ? 'grp-' . $gid : ''; ?>">
            <?php if ($i === 0): ?>
              <td class="date"><?php if ($first_of_date) { echo $h(date('n/j/Y', strtotime($d))); $first_of_date = false; } ?></td>
              <td class="c edit-cell js-lorry" <?php echo $attr; ?> title="Select lorry">
                <?php echo $e['vehicle_number'] !== '' ? $h($e['vehicle_number']) : '<span class="pick"><i class="fa-solid fa-truck"></i> Select</span>'; ?>
              </td>
              <td class="edit-cell js-cc" <?php echo $attr; ?> title="Field summary: <?php echo $h(implode(', ', array_keys($g['fs_codes']))); ?>">
                <?php echo $h($e['cc_name']); ?>
              </td>
              <td class="c"><?php echo intval($g['bills']); ?></td>
              <td class="c"><?php echo intval($g['n_unload']); ?></td>
              <td class="c"><?php echo intval($g['delivered']); ?></td>
              <td class="r"><?php echo number_format($g['values'], 2); ?></td>
            <?php else: ?>
              <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
            <?php endif; ?>
            <td class="u"<?php if ($u): ?> title="<?php echo $h('Invoice ' . $u['invoice'] . ' | Diff 1 ' . number_format($u['diff1'], 2) . ($u['ikea_missing'] ? ' | Not in Ikea import' : '')); ?>"<?php endif; ?>>
              <?php if ($u): echo $h($u['customer']); if ($u['ikea_missing']): ?><span class="ikea-miss">Not in Ikea</span><?php endif; endif; ?>
            </td>
            <td class="u r"><?php echo $u ? number_format($u['value'], 2) : ''; ?></td>
            <td><?php echo $u ? $h($u['reason']) : ''; ?></td>
            <?php if ($i === 0): ?>
              <td class="edit-cell js-start" <?php echo $attr; ?>><?php echo $e['start_time'] !== '' ? $h(dm_time_label($e['start_time'])) : '<span class="pick"><i class="fa-regular fa-clock"></i> Enter</span>'; ?></td>
              <td class="edit-cell js-end" <?php echo $attr; ?>><?php echo $e['end_time'] !== '' ? $h(dm_time_label($e['end_time'])) : '<span class="pick"><i class="fa-regular fa-clock"></i> Enter</span>'; ?></td>
            <?php else: ?>
              <td></td><td></td>
            <?php endif; ?>
          </tr>
          <?php endfor; endforeach; $t = $day['tot']; ?>
          <tr class="dm-total">
            <td class="c">DM Total</td><td></td><td></td>
            <td class="c"><?php echo $t['bills']; ?></td>
            <td class="c"><?php echo $t['unload']; ?></td>
            <td class="c"><?php echo $t['delivered']; ?></td>
            <td class="r"><?php echo number_format($t['values'], 2); ?></td>
            <td class="r"><?php echo $t['unload']; ?></td>
            <td class="r"><?php echo number_format($t['unload_value'], 2); ?></td>
            <td></td><td></td><td></td>
          </tr>
          <tr class="spacer"><td colspan="12"></td></tr>
          <tr class="grand">
            <td colspan="2" class="c"><?php echo $h($d); ?> Grand Total</td>
            <td></td><td></td><td></td><td></td><td></td><td></td>
            <td class="r"><?php echo number_format($day['all_values'], 2); ?></td>
            <td></td><td></td><td></td>
          </tr>
          <tr class="spacer"><td colspan="12"></td></tr>
      <?php endforeach;
        if (count($report) > 1): ?>
          <tr class="grand">
            <td colspan="2" class="c">Period Grand Total</td><td></td>
            <td class="c"><?php echo $period['bills']; ?></td>
            <td class="c"><?php echo $period['unload']; ?></td>
            <td class="c"><?php echo $period['delivered']; ?></td>
            <td class="r"><?php echo number_format($period['values'], 2); ?></td>
            <td></td>
            <td class="r"><?php echo number_format($period['all_values'], 2); ?></td>
            <td></td><td></td><td></td>
          </tr>
      <?php endif; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ─── Lorry / CC / Time modal ─── -->
<div class="modal-backdrop" id="dmModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <div>
        <h3><i class="fa-solid fa-truck"></i> Lorry &amp; CC</h3>
        <div class="modal-sub" id="dmSub"></div>
      </div>
      <button type="button" class="modal-close" onclick="closeDm()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="fg">
        <label>Lorry</label>
        <select id="dmLorry">
          <option value="">— Select lorry —</option>
          <?php foreach ($vehicles as $v): ?>
            <option value="<?php echo intval($v['id']); ?>"><?php echo $h($v['vehicle_number'] . ($v['driver_name'] ? ' — ' . $v['driver_name'] : '')); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label>CC <span style="font-weight:500;text-transform:none;color:#6b7280;">(defaults to the delivery person)</span></label>
        <select id="dmCc">
          <?php foreach ($employees as $emp): ?>
            <option value="e:<?php echo intval($emp['id']); ?>"><?php
              echo $h(($emp['name_with_initials'] ?: $emp['employee_full_name']) . ' [' . $emp['employee_id'] . ']' . ($emp['designation_name'] ? ' — ' . $emp['designation_name'] : '')); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="gr2">
        <div class="fg"><label>Start time</label><input type="time" id="dmStart" class="fctrl"></div>
        <div class="fg"><label>End Time</label><input type="time" id="dmEnd" class="fctrl"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeDm()">Cancel</button>
      <button type="button" class="btn btn-save" id="dmSave" onclick="saveDm()"><i class="fa-solid fa-floppy-disk"></i> Save</button>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
let dmCur = null;

function setRange(fromAgo, toAgo){
  const f = new Date(), t = new Date();
  f.setDate(f.getDate() - fromAgo); t.setDate(t.getDate() - toAgo);
  const iso = d => d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
  document.getElementById('dateFrom').value = iso(f);
  document.getElementById('dateTo').value   = iso(t);
  document.getElementById('filterForm').submit();
}

function toast(msg, ok){
  let t = document.getElementById('dmToast');
  if (!t){ t = document.createElement('div'); t.id = 'dmToast'; document.body.appendChild(t); }
  t.className = ok ? 'toast-ok' : 'toast-err';
  t.textContent = msg; t.style.display = 'block';
  clearTimeout(t._h); t._h = setTimeout(() => t.style.display = 'none', 2600);
}
function esc(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

$(function(){
  $('#dmLorry').select2({ dropdownParent: $('#dmModal .modal-dialog'), placeholder: '— Select lorry —', allowClear: true, width: '100%' });
  $('#dmCc').select2({ dropdownParent: $('#dmModal .modal-dialog'), tags: true, width: '100%',
    createTag: p => { const t = $.trim(p.term); return t ? { id: 't:' + t, text: t, newTag: true } : null; } });

  $(document).on('click', '.edit-cell', function(){
    dmCur = JSON.parse(this.getAttribute('data-row'));
    const focus = this.classList.contains('js-start') ? 'dmStart' : this.classList.contains('js-end') ? 'dmEnd'
                : this.classList.contains('js-cc') ? 'cc' : 'lorry';
    openDm(focus);
  });
  $('#dmModal').on('mousedown', function(e){ if (e.target === this) closeDm(); });
  $(document).on('keydown', e => { if (e.key === 'Escape') closeDm(); });
});

function openDm(focus){
  document.getElementById('dmSub').textContent =
    dmCur.date + ' | Delivery person: ' + dmCur.dp + (dmCur.fs ? ' | FS: ' + dmCur.fs : '');
  $('#dmLorry').val(dmCur.vehicle_id ? String(dmCur.vehicle_id) : '').trigger('change');
  const $cc = $('#dmCc');
  $cc.find('option[data-tmp]').remove();
  if (!$cc.find('option').filter((i, o) => o.value === dmCur.cc).length){
    const txt = dmCur.cc.startsWith('t:') ? dmCur.cc.slice(2) : dmCur.cc_name;
    $cc.append($('<option data-tmp="1">').val(dmCur.cc).text(txt));
  }
  $cc.val(dmCur.cc).trigger('change');
  document.getElementById('dmStart').value = dmCur.start_time || '';
  document.getElementById('dmEnd').value   = dmCur.end_time || '';
  document.getElementById('dmModal').classList.add('open');
  setTimeout(() => {
    if (focus === 'lorry') $('#dmLorry').select2('open');
    else if (focus === 'cc') $('#dmCc').select2('open');
    else document.getElementById(focus).focus();
  }, 60);
}
function closeDm(){
  $('#dmLorry').select2('close'); $('#dmCc').select2('close');
  document.getElementById('dmModal').classList.remove('open');
}

function saveDm(){
  if (!dmCur) return;
  const btn = document.getElementById('dmSave');
  const fd = new FormData();
  fd.append('delivery_date', dmCur.date);
  fd.append('group_key', dmCur.key);
  fd.append('delivery_person', dmCur.dp);
  fd.append('vehicle_id', $('#dmLorry').val() || '');
  fd.append('cc', $('#dmCc').val() || '');
  fd.append('start_time', document.getElementById('dmStart').value);
  fd.append('end_time', document.getElementById('dmEnd').value);
  btn.disabled = true;
  fetch('daily_delivery_monitor.php?ajax=save_entry', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      btn.disabled = false;
      if (!res.success){ toast(res.error || 'Save failed', false); return; }
      const e = res.entry;
      Object.assign(dmCur, { vehicle_id: e.vehicle_id, vehicle_number: e.vehicle_number, cc: e.cc, cc_name: e.cc_name,
                             start_time: e.start_time, end_time: e.end_time });
      const row = document.getElementById('grp-' + dmCur.gid);
      const json = JSON.stringify(dmCur);
      row.querySelectorAll('.edit-cell').forEach(c => c.setAttribute('data-row', json));
      row.querySelector('.js-lorry').innerHTML = e.vehicle_number ? esc(e.vehicle_number) : '<span class="pick"><i class="fa-solid fa-truck"></i> Select</span>';
      row.querySelector('.js-cc').innerHTML    = esc(e.cc_name);
      row.querySelector('.js-start').innerHTML = e.start_label ? esc(e.start_label) : '<span class="pick"><i class="fa-regular fa-clock"></i> Enter</span>';
      row.querySelector('.js-end').innerHTML   = e.end_label ? esc(e.end_label) : '<span class="pick"><i class="fa-regular fa-clock"></i> Enter</span>';
      closeDm();
      toast('Saved', true);
    })
    .catch(() => { btn.disabled = false; toast('Network error', false); });
}
</script>
<?php if (file_exists('footer.php')) include 'footer.php'; ?>