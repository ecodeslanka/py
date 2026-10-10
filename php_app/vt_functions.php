<?php
/*
  vt_functions.php
  Shared helpers for the Vehicle Tracking Report system.
  Include this from vehicle_report_upload.php, vehicle_report_view.php, vehicle_report.php
*/

mysqli_report(MYSQLI_REPORT_OFF);

/* ───────────────────────── table setup ───────────────────────── */
function vt_ensure_tables($conn) {

    mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `vt_uploads` (
      `id`            INT(11)      NOT NULL AUTO_INCREMENT,
      `batch_id`      VARCHAR(40)  DEFAULT NULL,
      `report_type`   VARCHAR(30)  NOT NULL,
      `filename`      VARCHAR(255) NOT NULL,
      `device_name`   VARCHAR(100) DEFAULT NULL,
      `period_start`  DATE         DEFAULT NULL,
      `period_end`    DATE         DEFAULT NULL,
      `total_rows`    INT(11)      NOT NULL DEFAULT 0,
      `skipped_rows`  INT(11)      NOT NULL DEFAULT 0,
      `uploaded_by`   VARCHAR(100) DEFAULT NULL,
      `uploaded_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `note`          TEXT         DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_type` (`report_type`),
      KEY `idx_device` (`device_name`),
      KEY `idx_batch` (`batch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "ALTER TABLE vt_uploads ADD COLUMN IF NOT EXISTS batch_id VARCHAR(40) DEFAULT NULL AFTER id");
    mysqli_query($conn, "ALTER TABLE vt_uploads ADD INDEX IF NOT EXISTS idx_batch (batch_id)");

    mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `vt_daily_moving` (
      `id`                  INT(11)      NOT NULL AUTO_INCREMENT,
      `upload_id`           INT(11)      NOT NULL,
      `device_name`         VARCHAR(100) NOT NULL,
      `report_date`         DATE         NOT NULL,
      `drive_time_sec`      INT(11)      DEFAULT 0,
      `park_time_sec`       INT(11)      DEFAULT 0,
      `total_position`      INT(11)      DEFAULT NULL,
      `invalid_location`    INT(11)      DEFAULT NULL,
      `alarm_times`         INT(11)      DEFAULT NULL,
      `drive_mileage_km`    DECIMAL(12,3) DEFAULT 0,
      `avg_speed`           DECIMAL(8,2) DEFAULT NULL,
      `engine_start_times`  INT(11)      DEFAULT NULL,
      `engine_on_time_sec`  INT(11)      DEFAULT 0,
      `door_open_times`     INT(11)      DEFAULT NULL,
      `door_opened_time_sec` INT(11)     DEFAULT 0,
      `shake_times`         INT(11)      DEFAULT NULL,
      `shaking_time_sec`    INT(11)      DEFAULT 0,
      `start_time`          DATETIME     DEFAULT NULL,
      `end_time`            DATETIME     DEFAULT NULL,
      `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_device_date` (`device_name`,`report_date`),
      KEY `idx_upload` (`upload_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `vt_daily_summary` (
      `id`                      INT(11)      NOT NULL AUTO_INCREMENT,
      `upload_id`               INT(11)      NOT NULL,
      `device_name`             VARCHAR(100) NOT NULL,
      `drive_time_sec`          INT(11)      DEFAULT 0,
      `park_time_sec`           INT(11)      DEFAULT 0,
      `total_mileage_km`        DECIMAL(12,3) DEFAULT 0,
      `work_drive_time_sec`     INT(11)      DEFAULT 0,
      `work_drive_mileage_km`   DECIMAL(12,3) DEFAULT 0,
      `unwork_drive_time_sec`   INT(11)      DEFAULT 0,
      `unwork_drive_mileage_km` DECIMAL(12,3) DEFAULT 0,
      `period_start`            DATETIME     DEFAULT NULL,
      `period_end`              DATETIME     DEFAULT NULL,
      `start_work`              TIME         DEFAULT NULL,
      `end_work`                TIME         DEFAULT NULL,
      `created_at`              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_device_period` (`device_name`,`period_start`,`period_end`),
      KEY `idx_upload` (`upload_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `vt_mileage_summary` (
      `id`               INT(11)      NOT NULL AUTO_INCREMENT,
      `upload_id`        INT(11)      NOT NULL,
      `device_name`      VARCHAR(100) NOT NULL,
      `drive_mileage_km` DECIMAL(12,3) DEFAULT 0,
      `fuel_l_per_hkm`   DECIMAL(12,3) DEFAULT NULL,
      `cost`             DECIMAL(14,2) DEFAULT NULL,
      `period_start`     DATETIME     DEFAULT NULL,
      `period_end`       DATETIME     DEFAULT NULL,
      `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_device_period` (`device_name`,`period_start`,`period_end`),
      KEY `idx_upload` (`upload_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `vt_park` (
      `id`             INT(11)       NOT NULL AUTO_INCREMENT,
      `upload_id`      INT(11)       NOT NULL,
      `device_name`    VARCHAR(100)  NOT NULL,
      `start_time`     DATETIME      NOT NULL,
      `end_time`       DATETIME      DEFAULT NULL,
      `park_time_sec`  INT(11)       DEFAULT 0,
      `address`        VARCHAR(500)  DEFAULT NULL,
      `longitude`      DECIMAL(12,7) DEFAULT NULL,
      `latitude`       DECIMAL(12,7) DEFAULT NULL,
      `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_device_start` (`device_name`,`start_time`),
      KEY `idx_upload` (`upload_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `vt_travel` (
      `id`               INT(11)       NOT NULL AUTO_INCREMENT,
      `upload_id`        INT(11)       NOT NULL,
      `device_name`      VARCHAR(100)  NOT NULL,
      `start_time`       DATETIME      NOT NULL,
      `end_time`         DATETIME      DEFAULT NULL,
      `drive_time_sec`   INT(11)       DEFAULT 0,
      `drive_mileage_km` DECIMAL(12,3) DEFAULT 0,
      `max_speed`        DECIMAL(8,2)  DEFAULT NULL,
      `avg_speed`        DECIMAL(8,2)  DEFAULT NULL,
      `spd_30_60`        INT(11)       DEFAULT NULL,
      `spd_60_90`        INT(11)       DEFAULT NULL,
      `spd_90_120`       INT(11)       DEFAULT NULL,
      `spd_gt120`        INT(11)       DEFAULT NULL,
      `total_alarms`     INT(11)       DEFAULT NULL,
      `start_address`    VARCHAR(500)  DEFAULT NULL,
      `end_address`      VARCHAR(500)  DEFAULT NULL,
      `start_lng`        DECIMAL(12,7) DEFAULT NULL,
      `start_lat`        DECIMAL(12,7) DEFAULT NULL,
      `end_lng`          DECIMAL(12,7) DEFAULT NULL,
      `end_lat`          DECIMAL(12,7) DEFAULT NULL,
      `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_device_start` (`device_name`,`start_time`),
      KEY `idx_upload` (`upload_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* Vehicle/Driver are stored directly on vt_uploads (no separate assignment table / date-matching layer). */
    mysqli_query($conn, "ALTER TABLE vt_uploads ADD COLUMN IF NOT EXISTS vehicle_id INT(11) DEFAULT NULL AFTER batch_id");
    mysqli_query($conn, "ALTER TABLE vt_uploads ADD COLUMN IF NOT EXISTS driver_id  INT(11) DEFAULT NULL AFTER vehicle_id");
    mysqli_query($conn, "ALTER TABLE vt_uploads ADD INDEX IF NOT EXISTS idx_vehicle (vehicle_id)");
    mysqli_query($conn, "ALTER TABLE vt_uploads ADD INDEX IF NOT EXISTS idx_driver (driver_id)");

    /* Route(s) selected for a batch — simple link table, one row per selected route (a batch can have several). */
    mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `vt_upload_routes` (
      `id`         INT(11)      NOT NULL AUTO_INCREMENT,
      `batch_id`   VARCHAR(40)  NOT NULL,
      `route_id`   INT(11)      NOT NULL,
      `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_batch_route` (`batch_id`,`route_id`),
      KEY `idx_batch` (`batch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `vt_position` (
      `id`            INT(11)       NOT NULL AUTO_INCREMENT,
      `upload_id`     INT(11)       NOT NULL,
      `device_name`   VARCHAR(100)  NOT NULL,
      `device_sn`     VARCHAR(50)   DEFAULT NULL,
      `time`          DATETIME      NOT NULL,
      `alarm_state`   VARCHAR(100)  DEFAULT NULL,
      `device_state`  VARCHAR(150)  DEFAULT NULL,
      `car_state`     VARCHAR(50)   DEFAULT NULL,
      `extend_state`  VARCHAR(150)  DEFAULT NULL,
      `speed`         DECIMAL(8,2)  DEFAULT NULL,
      `fuel_pct`      DECIMAL(8,2)  DEFAULT NULL,
      `fuel_l`        DECIMAL(10,2) DEFAULT NULL,
      `mileage_km`    DECIMAL(14,3) DEFAULT NULL,
      `temperature`   DECIMAL(6,2)  DEFAULT NULL,
      `gps`           VARCHAR(20)   DEFAULT NULL,
      `gsm`           VARCHAR(20)   DEFAULT NULL,
      `direction`     VARCHAR(20)   DEFAULT NULL,
      `longitude`     DECIMAL(12,7) DEFAULT NULL,
      `latitude`      DECIMAL(12,7) DEFAULT NULL,
      `address`       VARCHAR(500)  DEFAULT NULL,
      `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_device_time` (`device_name`,`time`),
      KEY `idx_upload` (`upload_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ───────────────────────── value parsing ───────────────────────── */

/** Trim, strip BOM/tab noise, return null for empty. */
function vt_clean($v) {
    if ($v === null) return null;
    $v = str_replace("\xEF\xBB\xBF", '', (string)$v);
    $v = trim($v);
    return $v === '' ? null : $v;
}

/** Parse duration strings like "6M 15S", "7H 18M 36S", "5d 11H 23M 4S", "0 S" into seconds. */
function vt_duration_to_sec($v) {
    $v = vt_clean($v);
    if ($v === null) return 0;
    $sec = 0;
    if (preg_match('/(\d+)\s*d/i', $v, $m)) $sec += (int)$m[1] * 86400;
    if (preg_match('/(\d+)\s*H/i', $v, $m)) $sec += (int)$m[1] * 3600;
    if (preg_match('/(\d+)\s*M/i', $v, $m)) $sec += (int)$m[1] * 60;
    if (preg_match('/(\d+)\s*S/i', $v, $m)) $sec += (int)$m[1];
    return $sec;
}

/** Format seconds back into a readable "Xd XXh XXm" / "XXh XXm" / "XXm XXs" string. */
function vt_sec_to_duration($sec) {
    $sec = (int)$sec;
    if ($sec <= 0) return '0m';
    $d = intdiv($sec, 86400);  $sec %= 86400;
    $h = intdiv($sec, 3600);   $sec %= 3600;
    $m = intdiv($sec, 60);     $s = $sec % 60;
    $out = [];
    if ($d) $out[] = $d.'d';
    if ($h) $out[] = $h.'h';
    if ($m) $out[] = $m.'m';
    if (!$d && !$h) $out[] = $s.'s';
    return implode(' ', $out);
}

function vt_num($v) {
    $v = vt_clean($v);
    if ($v === null) return null;
    $v = str_replace(',', '', $v);
    return is_numeric($v) ? (float)$v : null;
}
function vt_int($v) {
    $n = vt_num($v);
    return $n === null ? null : (int)$n;
}
function vt_datetime($v) {
    $v = vt_clean($v);
    if ($v === null) return null;
    $t = strtotime($v);
    return $t ? date('Y-m-d H:i:s', $t) : null;
}
function vt_date($v) {
    $v = vt_clean($v);
    if ($v === null) return null;
    $t = strtotime($v);
    return $t ? date('Y-m-d', $t) : null;
}
function vt_time($v) {
    $v = vt_clean($v);
    if ($v === null) return null;
    $t = strtotime($v);
    return $t ? date('H:i:s', $t) : null;
}
function vt_str($v, $max = 500) {
    $v = vt_clean($v);
    return $v === null ? null : mb_substr($v, 0, $max);
}
function vt_esc($conn, $v) {
    return $v === null ? 'NULL' : "'".mysqli_real_escape_string($conn, $v)."'";
}
function vt_numsql($v) {
    return $v === null ? 'NULL' : $v;
}

/* ───────────────────────── CSV reading ───────────────────────── */

/** Read a CSV/TXT upload into an array of associative rows keyed by lowercased, tab-stripped header names. */
function vt_read_csv($path) {
    $rows = [];
    $fh = fopen($path, 'r');
    if (!$fh) return [null, []];

    // Strip BOM from first bytes if present
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);

    $header = null;
    $headerKeys = [];
    while (($data = fgetcsv($fh)) !== false) {
        // skip fully blank lines
        $nonEmpty = array_filter($data, fn($c) => vt_clean($c) !== null);
        if (!$nonEmpty) continue;

        $clean = array_map(function($c) {
            $c = str_replace("\xEF\xBB\xBF", '', (string)$c);
            return trim(str_replace("\t", '', $c));
        }, $data);

        if ($header === null) {
            $header = $clean;
            foreach ($header as $i => $h) {
                $headerKeys[$i] = strtolower(trim($h));
            }
            continue;
        }
        $row = [];
        foreach ($headerKeys as $i => $key) {
            $row[$key] = $clean[$i] ?? null;
        }
        $rows[] = $row;
    }
    fclose($fh);
    return [$headerKeys, $rows];
}

/** Detect which of the 6 known report formats a header set belongs to. */
function vt_detect_report_type($headerKeys) {
    $set = array_values($headerKeys);
    $has = fn($needle) => in_array($needle, $set, true);

    if ($has('date') && $has('drive time') && $has('park time') && $has('drive mileage(km)') && $has('engine on time')) {
        return 'daily_moving';
    }
    if ($has('total mileage(km)') && $has('work driving time') && $has('start work')) {
        return 'daily_summary';
    }
    if ($has('fuel(l/hkm)') && $has('cost(dollar)')) {
        return 'mileage_summary';
    }
    if ($has('address') && $has('park time') && $has('longitude(°)') && !$has('drive mileage(km)')) {
        return 'park';
    }
    if ($has('device id / sn') && $has('mileage(km)') && $has('speed(km/h)')) {
        return 'position';
    }
    if ($has('start address') && $has('end address') && $has('drive time') && $has('max speed(km/h)')) {
        return 'travel';
    }
    return null;
}

function vt_report_type_label($type) {
    $labels = [
        'daily_moving'    => 'Daily Moving Report (per-day)',
        'daily_summary'   => 'Daily Moving Report (period summary)',
        'mileage_summary' => 'Mileage Report (period summary)',
        'park'            => 'Park Report',
        'position'        => 'Position Report',
        'travel'          => 'Travel Report',
    ];
    return $labels[$type] ?? $type;
}

/* ───────────────────────── routes / vehicles / drivers / assignments ───────────────────────── */

/** All active routes from the real routes master (routes.php), id => "code - name" */
function vt_all_routes($conn) {
    $out = [];
    $res = mysqli_query($conn, "SELECT id, route_code, route_name FROM routes WHERE active=1 ORDER BY route_name");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $out[$r['id']] = $r['route_code'].' - '.$r['route_name'];
    return $out;
}

/** All active vehicles, id => vehicle_number (from the main vehicles master) */
function vt_all_vehicles($conn) {
    $out = [];
    $res = mysqli_query($conn, "SELECT id, vehicle_number FROM vehicles WHERE active=1 ORDER BY vehicle_number");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $out[$r['id']] = $r['vehicle_number'];
    return $out;
}

/** All active employees usable as drivers, id => display name (from the main employees master) */
function vt_all_drivers($conn) {
    $out = [];
    $res = mysqli_query($conn, "SELECT id, employee_id, employee_full_name FROM employees WHERE active=1 ORDER BY employee_full_name");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $out[$r['id']] = $r['employee_full_name'].' ('.$r['employee_id'].')';
    return $out;
}

/**
 * Fetch every vt_uploads row for a device that overlaps [dateFrom, dateTo] and has a vehicle/driver
 * tagged, with vehicle/driver names + route names (via vt_upload_routes) joined in.
 * Returns array of rows ordered by period_start ASC — used to resolve which vehicle/driver/route
 * applied on a given day within the report range.
 */
function vt_upload_tags_for_device($conn, $device, $dateFrom, $dateTo) {
    $devEsc = "'".mysqli_real_escape_string($conn, $device)."'";
    $out = [];
    $res = mysqli_query($conn, "
        SELECT u.id, u.batch_id, u.period_start, u.period_end, u.vehicle_id, u.driver_id,
               v.vehicle_number, e.employee_full_name, e.employee_id AS driver_code
        FROM vt_uploads u
        LEFT JOIN vehicles v ON u.vehicle_id = v.id
        LEFT JOIN employees e ON u.driver_id = e.id
        WHERE u.device_name = $devEsc
          AND (u.vehicle_id IS NOT NULL OR u.driver_id IS NOT NULL)
          AND u.period_start <= '".mysqli_real_escape_string($conn,$dateTo)."'
          AND u.period_end   >= '".mysqli_real_escape_string($conn,$dateFrom)."'
        ORDER BY u.period_start ASC");
    if (!$res) return $out;
    while ($r = mysqli_fetch_assoc($res)) {
        $r['route_names'] = [];
        if ($r['batch_id']) {
            $rres = mysqli_query($conn, "
                SELECT rt.route_name FROM vt_upload_routes ur
                JOIN routes rt ON ur.route_id = rt.id
                WHERE ur.batch_id = ".vt_esc($conn, $r['batch_id']));
            if ($rres) while ($rr = mysqli_fetch_assoc($rres)) $r['route_names'][] = $rr['route_name'];
        }
        $out[] = $r;
    }
    return $out;
}

/** Given rows from vt_upload_tags_for_device() and a specific date, find the one covering it. */
function vt_upload_tag_for_date($tags, $date) {
    foreach ($tags as $t) {
        if ($date >= $t['period_start'] && $date <= $t['period_end']) return $t;
    }
    return null;
}

/** Most recently used vehicle_id for a GPS device, resolved from vt_uploads (set when uploading/tagging). */
function vt_vehicle_id_for_device($conn, $device) {
    $res = mysqli_query($conn, "SELECT vehicle_id FROM vt_uploads WHERE device_name=".vt_esc($conn,$device)." AND vehicle_id IS NOT NULL ORDER BY uploaded_at DESC LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return $row ? (int)$row['vehicle_id'] : null;
}

/**
 * Route Schedule rows (vehicle_schedule_routes) covering a device + date range, resolved via the
 * device's vehicle_id. Route / Driver / Sales Rep / Route Helper names are joined in.
 * Returns rows ordered by date_from ASC.
 */
function vt_schedule_routes_for_device($conn, $device, $dateFrom, $dateTo) {
    $vehicleId = vt_vehicle_id_for_device($conn, $device);
    if (!$vehicleId) return [];
    $out = [];
    $res = mysqli_query($conn, "
        SELECT sr.*, r.route_code, r.route_name,
               d.employee_full_name AS driver_name, d.employee_id AS driver_code,
               sre.employee_full_name AS sales_rep_name, sre.employee_id AS sales_rep_code,
               h.employee_full_name AS helper_name, h.employee_id AS helper_code
        FROM vehicle_schedule_routes sr
        JOIN vehicle_schedules vs ON sr.schedule_id = vs.id
        LEFT JOIN routes r ON sr.route_id = r.id
        LEFT JOIN employees d ON sr.driver_id = d.id
        LEFT JOIN employees sre ON sr.sales_rep_id = sre.id
        LEFT JOIN employees h ON sr.route_helper_id = h.id
        WHERE vs.vehicle_id = $vehicleId
          AND sr.date_from <= '".mysqli_real_escape_string($conn,$dateTo)."'
          AND sr.date_to   >= '".mysqli_real_escape_string($conn,$dateFrom)."'
        ORDER BY sr.date_from ASC");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $out[] = $r;
    return $out;
}

/** Given rows from vt_schedule_routes_for_device() and a specific 'YYYY-MM-DD' date, find the one covering it. */
function vt_schedule_route_for_date($rows, $date) {
    foreach ($rows as $r) {
        if ($date >= substr($r['date_from'],0,10) && $date <= substr($r['date_to'],0,10)) return $r;
    }
    return null;
}

/** All Vehicle Schedules (vehicle+month/year), for the report's schedule quick-select.
 *  Includes route_date_from/route_date_to = the actual min/max dates covered by that schedule's
 *  Route Schedule rows (falls back to the full calendar month if it has no route rows yet). */
function vt_all_vehicle_schedules($conn) {
    $out = [];
    $months = vt_month_names();
    $res = mysqli_query($conn, "
        SELECT s.id, s.month, s.year, s.vehicle_id, v.vehicle_number,
               MIN(sr.date_from) AS route_date_from, MAX(sr.date_to) AS route_date_to
        FROM vehicle_schedules s
        LEFT JOIN vehicles v ON s.vehicle_id = v.id
        LEFT JOIN vehicle_schedule_routes sr ON sr.schedule_id = s.id
        GROUP BY s.id
        ORDER BY s.year DESC, s.month DESC, v.vehicle_number ASC");
    if ($res) while ($r = mysqli_fetch_assoc($res)) {
        $r['label'] = ($r['vehicle_number'] ?: '—').' — '.$months[$r['month']].' '.$r['year'];
        if (!$r['route_date_from']) $r['route_date_from'] = sprintf('%04d-%02d-01', $r['year'], $r['month']);
        if (!$r['route_date_to'])   $r['route_date_to']   = date('Y-m-t', strtotime($r['route_date_from']));
        $out[] = $r;
    }
    return $out;
}

/**
 * Device names matching Vehicle/Driver/SalesRep/Route filters, resolved through the Route Schedule
 * (vehicle_schedule_routes -> vehicle_schedules -> vt_uploads.vehicle_id -> device_name).
 * Any filter left null is not restricted. Returns null if no filters given (caller should skip filtering).
 */
function vt_devices_matching_schedule_filters($conn, $vehicleId, $driverId, $salesRepId, $routeId, $dateFrom, $dateTo) {
    if (!$vehicleId && !$driverId && !$salesRepId && !$routeId) return null;

    $where = [
        "sr.date_from <= '".mysqli_real_escape_string($conn,$dateTo)."'",
        "sr.date_to   >= '".mysqli_real_escape_string($conn,$dateFrom)."'",
    ];
    if ($vehicleId)  $where[] = "vs.vehicle_id = ".(int)$vehicleId;
    if ($driverId)   $where[] = "sr.driver_id = ".(int)$driverId;
    if ($salesRepId) $where[] = "sr.sales_rep_id = ".(int)$salesRepId;
    if ($routeId)    $where[] = "sr.route_id = ".(int)$routeId;

    $sql = "SELECT DISTINCT u.device_name
            FROM vehicle_schedule_routes sr
            JOIN vehicle_schedules vs ON sr.schedule_id = vs.id
            JOIN vt_uploads u ON u.vehicle_id = vs.vehicle_id AND u.device_name IS NOT NULL
            WHERE ".implode(' AND ', $where);
    $out = [];
    $res = mysqli_query($conn, $sql);
    if ($res) while ($r = mysqli_fetch_assoc($res)) $out[] = $r['device_name'];
    return $out;
}

/** vehicle_id => [device_name, device_name, ...] map, for the report's schedule-select JS autofill. */
function vt_vehicle_device_map($conn) {
    $out = [];
    $res = mysqli_query($conn, "SELECT DISTINCT vehicle_id, device_name FROM vt_uploads WHERE vehicle_id IS NOT NULL AND device_name IS NOT NULL");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $out[$r['vehicle_id']][] = $r['device_name'];
    return $out;
}

/* ───────────────────────── Vehicle Schedule module ───────────────────────── */

function vt_ensure_schedule_tables($conn) {
    mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `vehicle_schedules` (
      `id`          INT(11)      NOT NULL AUTO_INCREMENT,
      `vehicle_id`  INT(11)      NOT NULL,
      `month`       TINYINT(2)   NOT NULL,
      `year`        SMALLINT(4)  NOT NULL,
      `created_by`  VARCHAR(100) DEFAULT NULL,
      `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_vehicle_month` (`vehicle_id`,`month`,`year`),
      KEY `idx_vehicle` (`vehicle_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `vehicle_schedule_routes` (
      `id`               INT(11)      NOT NULL AUTO_INCREMENT,
      `schedule_id`      INT(11)      NOT NULL,
      `route_id`         INT(11)      DEFAULT NULL,
      `date_from`        DATE         NOT NULL,
      `date_to`          DATE         NOT NULL,
      `driver_id`        INT(11)      DEFAULT NULL,
      `sales_rep_id`     INT(11)      DEFAULT NULL,
      `route_helper_id`  INT(11)      DEFAULT NULL,
      `remark`           VARCHAR(500) DEFAULT NULL,
      `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_schedule` (`schedule_id`),
      KEY `idx_dates` (`date_from`,`date_to`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    /* back to plain dates (no time) */
    mysqli_query($conn, "ALTER TABLE vehicle_schedule_routes MODIFY COLUMN date_from DATE NOT NULL");
    mysqli_query($conn, "ALTER TABLE vehicle_schedule_routes MODIFY COLUMN date_to DATE NOT NULL");

    /* link uploaded report files to a Vehicle Schedule (optional — set when uploading from the schedule page) */
    mysqli_query($conn, "ALTER TABLE vt_uploads ADD COLUMN IF NOT EXISTS schedule_id INT(11) DEFAULT NULL AFTER driver_id");
    mysqli_query($conn, "ALTER TABLE vt_uploads ADD INDEX IF NOT EXISTS idx_schedule (schedule_id)");
}

/** All months for a <select>, 1 => January ... 12 => December */
function vt_month_names() {
    return [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
            7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
}

/**
 * Shared row-import routine used by both the upload page and the Vehicle Schedule upload modal.
 * Inserts $rows (as returned by vt_read_csv) into the correct vt_* table for $type, tagged with $upload_id.
 * Returns ['inserted'=>int,'skipped'=>int,'deviceNames'=>array,'minDate'=>?string,'maxDate'=>?string]
 */
function vt_import_report_rows($conn, $upload_id, $type, $rows) {
    $inserted = 0; $skipped = 0; $deviceNames = []; $minDate = null; $maxDate = null;

    switch ($type) {

    /* ── Daily Moving (per-day rows) ── */
    case 'daily_moving':
        foreach ($rows as $r) {
            $device = vt_str($r['device name'] ?? null, 100);
            $date   = vt_date($r['date'] ?? null);
            if (!$device || !$date) { $skipped++; continue; }
            $deviceNames[$device] = true;
            if (!$minDate || $date < $minDate) $minDate = $date;
            if (!$maxDate || $date > $maxDate) $maxDate = $date;

            mysqli_query($conn, "INSERT INTO vt_daily_moving
                (upload_id,device_name,report_date,drive_time_sec,park_time_sec,total_position,
                 invalid_location,alarm_times,drive_mileage_km,avg_speed,engine_start_times,
                 engine_on_time_sec,door_open_times,door_opened_time_sec,shake_times,shaking_time_sec,
                 start_time,end_time)
                VALUES ($upload_id,".vt_esc($conn,$device).",".vt_esc($conn,$date).",
                 ".vt_duration_to_sec($r['drive time'] ?? null).",
                 ".vt_duration_to_sec($r['park time'] ?? null).",
                 ".vt_numsql(vt_int($r['total number of position'] ?? null)).",
                 ".vt_numsql(vt_int($r['total number of invalid location'] ?? null)).",
                 ".vt_numsql(vt_int($r['alarm times'] ?? null)).",
                 ".vt_numsql(vt_num($r['drive mileage(km)'] ?? null) ?? 0).",
                 ".vt_numsql(vt_num($r['average speed(km/h)'] ?? null)).",
                 ".vt_numsql(vt_int($r['engine start times'] ?? null)).",
                 ".vt_duration_to_sec($r['engine on time'] ?? null).",
                 ".vt_numsql(vt_int($r['door open times'] ?? null)).",
                 ".vt_duration_to_sec($r['door opened time'] ?? null).",
                 ".vt_numsql(vt_int($r['shake times'] ?? null)).",
                 ".vt_duration_to_sec($r['shaking time'] ?? null).",
                 ".vt_esc($conn, vt_datetime($r['start time'] ?? null)).",
                 ".vt_esc($conn, vt_datetime($r['end time'] ?? null)).")
                ON DUPLICATE KEY UPDATE
                 upload_id=VALUES(upload_id),
                 drive_time_sec=VALUES(drive_time_sec), park_time_sec=VALUES(park_time_sec),
                 total_position=VALUES(total_position), invalid_location=VALUES(invalid_location),
                 alarm_times=VALUES(alarm_times), drive_mileage_km=VALUES(drive_mileage_km),
                 avg_speed=VALUES(avg_speed), engine_start_times=VALUES(engine_start_times),
                 engine_on_time_sec=VALUES(engine_on_time_sec), door_open_times=VALUES(door_open_times),
                 door_opened_time_sec=VALUES(door_opened_time_sec), shake_times=VALUES(shake_times),
                 shaking_time_sec=VALUES(shaking_time_sec), start_time=VALUES(start_time), end_time=VALUES(end_time)");
            $inserted++;
        }
        break;

    /* ── Daily Summary (period aggregate) ── */
    case 'daily_summary':
        foreach ($rows as $r) {
            $device = vt_str($r['device name'] ?? null, 100);
            $ps = vt_datetime($r['start time'] ?? null);
            $pe = vt_datetime($r['end time'] ?? null);
            if (!$device || !$ps) { $skipped++; continue; }
            $deviceNames[$device] = true;
            $d1 = substr($ps,0,10); $d2 = $pe ? substr($pe,0,10) : $d1;
            if (!$minDate || $d1 < $minDate) $minDate = $d1;
            if (!$maxDate || $d2 > $maxDate) $maxDate = $d2;

            mysqli_query($conn, "INSERT INTO vt_daily_summary
                (upload_id,device_name,drive_time_sec,park_time_sec,total_mileage_km,
                 work_drive_time_sec,work_drive_mileage_km,unwork_drive_time_sec,unwork_drive_mileage_km,
                 period_start,period_end,start_work,end_work)
                VALUES ($upload_id,".vt_esc($conn,$device).",
                 ".vt_duration_to_sec($r['drive time'] ?? null).",
                 ".vt_duration_to_sec($r['park time'] ?? null).",
                 ".vt_numsql(vt_num($r['total mileage(km)'] ?? null) ?? 0).",
                 ".vt_duration_to_sec($r['work driving time'] ?? null).",
                 ".vt_numsql(vt_num($r['work driving mileage(km)'] ?? null) ?? 0).",
                 ".vt_duration_to_sec($r['unwork driving time'] ?? null).",
                 ".vt_numsql(vt_num($r['unwork driving mileage(km)'] ?? null) ?? 0).",
                 ".vt_esc($conn,$ps).",".vt_esc($conn,$pe).",
                 ".vt_esc($conn, vt_time($r['start work'] ?? null)).",
                 ".vt_esc($conn, vt_time($r['end work'] ?? null)).")
                ON DUPLICATE KEY UPDATE
                 upload_id=VALUES(upload_id),
                 drive_time_sec=VALUES(drive_time_sec), park_time_sec=VALUES(park_time_sec),
                 total_mileage_km=VALUES(total_mileage_km), work_drive_time_sec=VALUES(work_drive_time_sec),
                 work_drive_mileage_km=VALUES(work_drive_mileage_km), unwork_drive_time_sec=VALUES(unwork_drive_time_sec),
                 unwork_drive_mileage_km=VALUES(unwork_drive_mileage_km), start_work=VALUES(start_work), end_work=VALUES(end_work)");
            $inserted++;
        }
        break;

    /* ── Mileage Summary ── */
    case 'mileage_summary':
        foreach ($rows as $r) {
            $device = vt_str($r['device name'] ?? null, 100);
            $ps = vt_datetime($r['start time'] ?? null);
            $pe = vt_datetime($r['end time'] ?? null);
            if (!$device || !$ps) { $skipped++; continue; }
            $deviceNames[$device] = true;
            $d1 = substr($ps,0,10); $d2 = $pe ? substr($pe,0,10) : $d1;
            if (!$minDate || $d1 < $minDate) $minDate = $d1;
            if (!$maxDate || $d2 > $maxDate) $maxDate = $d2;

            mysqli_query($conn, "INSERT INTO vt_mileage_summary
                (upload_id,device_name,drive_mileage_km,fuel_l_per_hkm,cost,period_start,period_end)
                VALUES ($upload_id,".vt_esc($conn,$device).",
                 ".vt_numsql(vt_num($r['drive mileage(km)'] ?? null) ?? 0).",
                 ".vt_numsql(vt_num($r['fuel(l/hkm)'] ?? null)).",
                 ".vt_numsql(vt_num($r['cost(dollar)'] ?? null)).",
                 ".vt_esc($conn,$ps).",".vt_esc($conn,$pe).")
                ON DUPLICATE KEY UPDATE
                 upload_id=VALUES(upload_id),
                 drive_mileage_km=VALUES(drive_mileage_km), fuel_l_per_hkm=VALUES(fuel_l_per_hkm), cost=VALUES(cost)");
            $inserted++;
        }
        break;

    /* ── Park Report ── */
    case 'park':
        foreach ($rows as $r) {
            $device = vt_str($r['device name'] ?? null, 100);
            $st = vt_datetime($r['start time'] ?? null);
            if (!$device || !$st) { $skipped++; continue; }
            $deviceNames[$device] = true;
            $d1 = substr($st,0,10);
            if (!$minDate || $d1 < $minDate) $minDate = $d1;
            if (!$maxDate || $d1 > $maxDate) $maxDate = $d1;

            mysqli_query($conn, "INSERT INTO vt_park
                (upload_id,device_name,start_time,end_time,park_time_sec,address,longitude,latitude)
                VALUES ($upload_id,".vt_esc($conn,$device).",".vt_esc($conn,$st).",
                 ".vt_esc($conn, vt_datetime($r['end time'] ?? null)).",
                 ".vt_duration_to_sec($r['park time'] ?? null).",
                 ".vt_esc($conn, vt_str($r['address'] ?? null, 500)).",
                 ".vt_numsql(vt_num($r['longitude(°)'] ?? null)).",
                 ".vt_numsql(vt_num($r['latitude(°)'] ?? null)).")
                ON DUPLICATE KEY UPDATE
                 upload_id=VALUES(upload_id),
                 end_time=VALUES(end_time), park_time_sec=VALUES(park_time_sec),
                 address=VALUES(address), longitude=VALUES(longitude), latitude=VALUES(latitude)");
            $inserted++;
        }
        break;

    /* ── Travel Report ── */
    case 'travel':
        foreach ($rows as $r) {
            $device = vt_str($r['device name'] ?? null, 100);
            $st = vt_datetime($r['start time'] ?? null);
            if (!$device || !$st) { $skipped++; continue; }
            $deviceNames[$device] = true;
            $d1 = substr($st,0,10);
            if (!$minDate || $d1 < $minDate) $minDate = $d1;
            if (!$maxDate || $d1 > $maxDate) $maxDate = $d1;

            mysqli_query($conn, "INSERT INTO vt_travel
                (upload_id,device_name,start_time,end_time,drive_time_sec,drive_mileage_km,max_speed,avg_speed,
                 spd_30_60,spd_60_90,spd_90_120,spd_gt120,total_alarms,start_address,end_address,
                 start_lng,start_lat,end_lng,end_lat)
                VALUES ($upload_id,".vt_esc($conn,$device).",".vt_esc($conn,$st).",
                 ".vt_esc($conn, vt_datetime($r['end time'] ?? null)).",
                 ".vt_duration_to_sec($r['drive time'] ?? null).",
                 ".vt_numsql(vt_num($r['drive mileage(km)'] ?? null) ?? 0).",
                 ".vt_numsql(vt_num($r['max speed(km/h)'] ?? null)).",
                 ".vt_numsql(vt_num($r['average speed(km/h)'] ?? null)).",
                 ".vt_numsql(vt_int($r['30-60 km/h'] ?? null)).",
                 ".vt_numsql(vt_int($r['60-90 km/h'] ?? null)).",
                 ".vt_numsql(vt_int($r['90-120 km/h'] ?? null)).",
                 ".vt_numsql(vt_int($r['> 120 km/h'] ?? null)).",
                 ".vt_numsql(vt_int($r['total'] ?? null)).",
                 ".vt_esc($conn, vt_str($r['start address'] ?? null, 500)).",
                 ".vt_esc($conn, vt_str($r['end address'] ?? null, 500)).",
                 ".vt_numsql(vt_num($r['start longitude(°)'] ?? null)).",
                 ".vt_numsql(vt_num($r['start latitude(°)'] ?? null)).",
                 ".vt_numsql(vt_num($r['end longitude(°)'] ?? null)).",
                 ".vt_numsql(vt_num($r['end latitude(°)'] ?? null)).")
                ON DUPLICATE KEY UPDATE
                 upload_id=VALUES(upload_id),
                 end_time=VALUES(end_time), drive_time_sec=VALUES(drive_time_sec), drive_mileage_km=VALUES(drive_mileage_km),
                 max_speed=VALUES(max_speed), avg_speed=VALUES(avg_speed), spd_30_60=VALUES(spd_30_60),
                 spd_60_90=VALUES(spd_60_90), spd_90_120=VALUES(spd_90_120), spd_gt120=VALUES(spd_gt120),
                 total_alarms=VALUES(total_alarms), start_address=VALUES(start_address), end_address=VALUES(end_address),
                 start_lng=VALUES(start_lng), start_lat=VALUES(start_lat), end_lng=VALUES(end_lng), end_lat=VALUES(end_lat)");
            $inserted++;
        }
        break;

    /* ── Position Report ── */
    case 'position':
        $batch = [];
        $flushPos = function() use (&$batch, $conn, &$inserted) {
            if (!$batch) return;
            mysqli_query($conn, "INSERT INTO vt_position
                (upload_id,device_name,device_sn,time,alarm_state,device_state,car_state,extend_state,
                 speed,fuel_pct,fuel_l,mileage_km,temperature,gps,gsm,direction,longitude,latitude,address)
                VALUES ".implode(',', $batch)."
                ON DUPLICATE KEY UPDATE
                 upload_id=VALUES(upload_id),
                 device_sn=VALUES(device_sn), alarm_state=VALUES(alarm_state), device_state=VALUES(device_state),
                 car_state=VALUES(car_state), extend_state=VALUES(extend_state), speed=VALUES(speed),
                 fuel_pct=VALUES(fuel_pct), fuel_l=VALUES(fuel_l), mileage_km=VALUES(mileage_km),
                 temperature=VALUES(temperature), gps=VALUES(gps), gsm=VALUES(gsm), direction=VALUES(direction),
                 longitude=VALUES(longitude), latitude=VALUES(latitude), address=VALUES(address)");
            $inserted += count($batch);
            $batch = [];
        };
        foreach ($rows as $r) {
            $device = vt_str($r['device name'] ?? null, 100);
            $time   = vt_datetime($r['time'] ?? null);
            if (!$device || !$time) { $skipped++; continue; }
            $deviceNames[$device] = true;
            $d1 = substr($time,0,10);
            if (!$minDate || $d1 < $minDate) $minDate = $d1;
            if (!$maxDate || $d1 > $maxDate) $maxDate = $d1;

            $batch[] = "($upload_id,".vt_esc($conn,$device).",".vt_esc($conn, vt_str($r['device id / sn'] ?? null,50)).",
                ".vt_esc($conn,$time).",
                ".vt_esc($conn, vt_str($r['alarm state'] ?? null,100)).",
                ".vt_esc($conn, vt_str($r['device state'] ?? null,150)).",
                ".vt_esc($conn, vt_str($r['car state'] ?? null,50)).",
                ".vt_esc($conn, vt_str($r['extend state'] ?? null,150)).",
                ".vt_numsql(vt_num($r['speed(km/h)'] ?? null)).",
                ".vt_numsql(vt_num($r['fuel(%)'] ?? null)).",
                ".vt_numsql(vt_num($r['fuel(l)'] ?? null)).",
                ".vt_numsql(vt_num($r['mileage(km)'] ?? null)).",
                ".vt_numsql(vt_num($r['temperature(℃)'] ?? null)).",
                ".vt_esc($conn, vt_str($r['gps'] ?? null,20)).",
                ".vt_esc($conn, vt_str($r['gsm'] ?? null,20)).",
                ".vt_esc($conn, vt_str($r['direction'] ?? null,20)).",
                ".vt_numsql(vt_num($r['longitude(°)'] ?? null)).",
                ".vt_numsql(vt_num($r['latitude(°)'] ?? null)).",
                ".vt_esc($conn, vt_str($r['address'] ?? null,500)).")";

            if (count($batch) >= 300) $flushPos();
        }
        $flushPos();
        break;
    }

    return ['inserted'=>$inserted, 'skipped'=>$skipped, 'deviceNames'=>array_keys($deviceNames), 'minDate'=>$minDate, 'maxDate'=>$maxDate];
}
