<?php
// ── Yelo Group HMS — Attendance Report ──────────────────────────────────────
ob_start();
include 'config.php';

/*
 * ACTIVE EMPLOYEE FILTER
 * Excludes employees whose employment_status is 'resigned' or 'terminated'.
 * Adjust the field name / values below if your DB uses different naming
 * (e.g. status = 'inactive', employment_status = 'Resigned', etc.)
 */
define('ACTIVE_EMP_FILTER', "e.active = 1 AND (e.status IS NULL OR e.status NOT IN ('resigned','terminated'))");
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    ob_clean();
    header('Content-Type: application/json');

    $page   = max(1, intval($_GET['page'] ?? 1));
    $limit  = 25;
    $offset = ($page - 1) * $limit;

    $search      = mysqli_real_escape_string($conn, trim($_GET['search']     ?? ''));
    $f_date_from = mysqli_real_escape_string($conn, trim($_GET['date_from'] ?? ''));
    $f_date_to   = mysqli_real_escape_string($conn, trim($_GET['date_to']   ?? ''));
    $f_status    = mysqli_real_escape_string($conn, trim($_GET['f_status']  ?? ''));
    $f_emp       = intval($_GET['f_emp'] ?? 0);

    $where = ['1=1'];
    // Only include attendance records belonging to active (non-resigned/terminated) employees
    $where[] = ACTIVE_EMP_FILTER;
    if ($search)      $where[] = "(e.employee_id LIKE '%$search%' OR e.employee_full_name LIKE '%$search%')";
    if ($f_date_from) $where[] = "a.att_date >= '$f_date_from'";
    if ($f_date_to)   $where[] = "a.att_date <= '$f_date_to'";
    if ($f_emp > 0)   $where[] = "a.employee_id = $f_emp";
    if ($f_status === 'checkedin')  $where[] = "a.check_in IS NOT NULL AND a.check_out IS NULL";
    if ($f_status === 'checkedout') $where[] = "a.check_in IS NOT NULL AND a.check_out IS NOT NULL";

    $where_sql = implode(' AND ', $where);

    $cnt_res = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE $where_sql");
    $total   = mysqli_fetch_assoc($cnt_res)['cnt'];
    $pages   = $total > 0 ? ceil($total / $limit) : 1;

    $sql = "SELECT a.*, e.employee_id AS emp_code, e.employee_full_name,
                   TIMESTAMPDIFF(MINUTE, a.check_in, IFNULL(a.check_out, NOW())) AS minutes_worked
            FROM attendance a
            JOIN employees e ON e.id = a.employee_id
            WHERE $where_sql
            ORDER BY a.att_date DESC, a.check_in DESC
            LIMIT $limit OFFSET $offset";
    $res  = mysqli_query($conn, $sql);
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;

    $today    = date('Y-m-d');
    // Today's stats also scoped to active employees only
    $stat_res = mysqli_query($conn, "SELECT
        COUNT(*) AS total,
        SUM(a.check_in IS NOT NULL) AS checked_in,
        SUM(a.check_out IS NOT NULL) AS checked_out,
        SUM(a.check_in IS NOT NULL AND a.check_out IS NOT NULL) AS completed
        FROM attendance a
        JOIN employees e ON e.id = a.employee_id
        WHERE a.att_date = '$today'
          AND " . ACTIVE_EMP_FILTER);
    $stats = mysqli_fetch_assoc($stat_res);

    echo json_encode(['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page, 'stats' => $stats]);
    exit;
}

// ── AJAX: Attendance Summary ─────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] == 'summary') {
    ob_clean();
    header('Content-Type: application/json');

    $f_date_from = mysqli_real_escape_string($conn, trim($_GET['date_from'] ?? date('Y-m-01')));
    $f_date_to   = mysqli_real_escape_string($conn, trim($_GET['date_to']   ?? date('Y-m-d')));

    // Total working days in range (distinct dates that appear in attendance)
    $days_res = mysqli_query($conn, "SELECT COUNT(DISTINCT att_date) AS working_days FROM attendance WHERE att_date BETWEEN '$f_date_from' AND '$f_date_to'");
    $working_days = (int)mysqli_fetch_assoc($days_res)['working_days'];

    // Per-employee summary — active employees only
    $emp_sql = "SELECT
        e.id AS emp_id,
        e.employee_id AS emp_code,
        e.employee_full_name,
        COALESCE(d.designation_name, 'Unassigned')  AS designation,
        COALESCE(sc.category_name,  'Unassigned')   AS staff_category,
        COUNT(DISTINCT a.att_date) AS present_days,
        SUM(CASE WHEN a.check_in IS NOT NULL AND a.check_out IS NOT NULL THEN 1 ELSE 0 END) AS completed_days,
        SUM(CASE WHEN a.check_in IS NOT NULL AND a.check_out IS NULL THEN 1 ELSE 0 END) AS incomplete_days,
        ROUND(AVG(TIMESTAMPDIFF(MINUTE, a.check_in, a.check_out))/60, 1) AS avg_hours
        FROM employees e
        LEFT JOIN designations     d  ON e.designation_id    = d.id
        LEFT JOIN staff_categories sc ON e.staff_category_id = sc.id
        LEFT JOIN attendance a ON a.employee_id = e.id AND a.att_date BETWEEN '$f_date_from' AND '$f_date_to'
        WHERE " . ACTIVE_EMP_FILTER . "
        GROUP BY e.id
        ORDER BY e.employee_full_name";

    $emp_res = mysqli_query($conn, $emp_sql);
    $emp_rows = [];
    while ($r = mysqli_fetch_assoc($emp_res)) {
        $r['absent_days'] = max(0, $working_days - (int)$r['present_days']);
        $r['attendance_pct'] = $working_days > 0 ? round(($r['present_days'] / $working_days) * 100, 1) : 0;
        $emp_rows[] = $r;
    }

    // Per-designation summary — active employees only
    $cat_sql = "SELECT
        COALESCE(d.designation_name, 'Unassigned') AS category,
        COUNT(DISTINCT e.id) AS total_employees,
        COUNT(DISTINCT CONCAT(a.employee_id,'-',a.att_date)) AS total_present_records,
        COUNT(DISTINCT e.id) * $working_days AS total_possible_days,
        ROUND(COUNT(DISTINCT CONCAT(a.employee_id,'-',a.att_date)) / GREATEST(COUNT(DISTINCT e.id) * $working_days, 1) * 100, 1) AS attendance_pct
        FROM employees e
        LEFT JOIN designations     d  ON e.designation_id    = d.id
        LEFT JOIN staff_categories sc ON e.staff_category_id = sc.id
        LEFT JOIN attendance a ON a.employee_id = e.id AND a.att_date BETWEEN '$f_date_from' AND '$f_date_to'
        WHERE " . ACTIVE_EMP_FILTER . "
        GROUP BY e.designation_id
        ORDER BY category";

    $cat_res = mysqli_query($conn, $cat_sql);
    $cat_rows = [];
    while ($r = mysqli_fetch_assoc($cat_res)) {
        $r['total_absent_records'] = max(0, (int)$r['total_possible_days'] - (int)$r['total_present_records']);
        $cat_rows[] = $r;
    }

    echo json_encode([
        'working_days' => $working_days,
        'date_from'    => $f_date_from,
        'date_to'      => $f_date_to,
        'employees'    => $emp_rows,
        'categories'   => $cat_rows,
    ]);
    exit;
}

// ── Employee list for filter dropdown (page load) — active employees only ─────
$emp_res = mysqli_query($conn, "
    SELECT e.id, e.employee_id, e.employee_full_name,
           COALESCE(d.designation_name,'Unassigned') AS designation_name,
           COALESCE(sc.category_name,'Unassigned')   AS staff_category_name
    FROM employees e
    LEFT JOIN designations d    ON e.designation_id    = d.id
    LEFT JOIN staff_categories sc ON e.staff_category_id = sc.id
    WHERE " . ACTIVE_EMP_FILTER . "
    ORDER BY e.employee_full_name
");
$employees = [];
while ($e = mysqli_fetch_assoc($emp_res)) $employees[] = $e;

include 'header.php';
?>

<!-- Select2 CSS & JS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title">Attendance Report</h2>
            <p class="page-subtitle">Track daily check-in / check-out, location, selfies &amp; device info</p>
        </div>
        <div style="display:flex;gap:8px;">
            <button onclick="exportCSV()" class="btn btn-light">
                <i class="fa-solid fa-file-csv"></i> Export CSV
            </button>
        </div>
    </div>
</div>

<!-- Stat Cards -->
<div class="stat-grid" id="statGrid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fa-solid fa-users"></i></div>
        <div class="stat-body">
            <div class="stat-val" id="s-total">—</div>
            <div class="stat-lbl">Today's Records</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#ecfdf5;color:#059669;"><i class="fa-solid fa-right-to-bracket"></i></div>
        <div class="stat-body">
            <div class="stat-val" id="s-in">—</div>
            <div class="stat-lbl">Checked In</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff;color:#2563eb;"><i class="fa-solid fa-right-from-bracket"></i></div>
        <div class="stat-body">
            <div class="stat-val" id="s-out">—</div>
            <div class="stat-lbl">Checked Out</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fefce8;color:#ca8a04;"><i class="fa-solid fa-circle-check"></i></div>
        <div class="stat-body">
            <div class="stat-val" id="s-done">—</div>
            <div class="stat-lbl">Completed</div>
        </div>
    </div>
</div>

<!-- ── Tab navigation ── -->
<div class="tab-bar">
    <button class="tab-btn active" id="tab-records" onclick="switchTab('records')">
        <i class="fa-solid fa-table-list"></i> Attendance Records
    </button>
    <button class="tab-btn" id="tab-summary" onclick="switchTab('summary')">
        <i class="fa-solid fa-chart-bar"></i> Attendance Summary
    </button>
</div>

<!-- ══════════════════════ TAB: RECORDS ══════════════════════ -->
<div id="pane-records">

<!-- Search & Filters Bar -->
<div style="margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <div style="position:relative;flex:1;min-width:220px;max-width:380px;">
        <i class="fa-solid fa-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;z-index:1;"></i>
        <input type="text" id="searchInput" class="search-input" placeholder="Search by Emp ID or Name…" autocomplete="off">
        <span id="searchSpinner" style="display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#9ca3af;">
            <i class="fa-solid fa-spinner fa-spin"></i>
        </span>
    </div>

    <div class="pill-group">
        <button class="pill active" onclick="setQuick('today',this)">Today</button>
        <button class="pill" onclick="setQuick('week',this)">This Week</button>
        <button class="pill" onclick="setQuick('month',this)">This Month</button>
        <button class="pill" onclick="setQuick('custom',this)">Custom</button>
    </div>

    <div id="customDates" style="display:none;gap:6px;align-items:center;flex-wrap:nowrap;">
        <input type="date" id="dateFrom" class="filter-input" style="padding:7px 10px;" onchange="loadData(1)">
        <span style="font-size:12px;color:#9ca3af;">to</span>
        <input type="date" id="dateTo" class="filter-input" style="padding:7px 10px;" onchange="loadData(1)">
    </div>

    <div class="status-toggle">
        <button class="status-btn active" id="btn-all" onclick="setStatus('',this)">All</button>
        <button class="status-btn" id="btn-in" onclick="setStatus('checkedin',this)">
            <i class="fa-solid fa-circle" style="font-size:7px;color:#22c55e;"></i> In Only
        </button>
        <button class="status-btn" id="btn-out" onclick="setStatus('checkedout',this)">
            <i class="fa-solid fa-circle" style="font-size:7px;color:#3b82f6;"></i> Completed
        </button>
    </div>

    <button onclick="toggleFilters()" class="btn-filter-toggle" id="filterToggleBtn">
        <i class="fa-solid fa-sliders"></i> Filters
        <i class="fa-solid fa-chevron-down" id="filter-icon" style="font-size:11px;"></i>
    </button>
</div>

<!-- Advanced Filters -->
<div class="content-card" id="filters-section" style="display:none;margin-bottom:14px;padding:16px 20px;">
    <div class="filter-grid">
        <div class="filter-group">
            <label class="filter-label">Employee</label>
            <select id="f_emp" class="filter-input select2-employee" style="width:100%;">
                <option value="">All Employees</option>
                <?php foreach($employees as $e): ?>
                <option value="<?php echo $e['id']; ?>" data-designation="<?php echo htmlspecialchars($e['designation_name'] ?? ''); ?>">
                    <?php echo htmlspecialchars($e['employee_id'].' — '.$e['employee_full_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">Date From</label>
            <input type="date" id="f_date_from" class="filter-input" onchange="loadData(1)">
        </div>
        <div class="filter-group">
            <label class="filter-label">Date To</label>
            <input type="date" id="f_date_to" class="filter-input" onchange="loadData(1)">
        </div>
        <div class="filter-group" style="justify-content:flex-end;align-items:flex-end;">
            <button onclick="clearFilters()" class="btn btn-light" style="width:100%;">
                <i class="fa-solid fa-xmark"></i> Clear All
            </button>
        </div>
    </div>
</div>

<!-- Table Card -->
<div class="content-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h3 class="card-title" style="margin:0;">
            Attendance Records &nbsp;<span id="totalCount" class="count-badge">—</span>
        </h3>
        <div id="tableInfo" style="font-size:12px;color:#9ca3af;"></div>
    </div>

    <div id="skeletonLoader">
        <?php for($i=0;$i<8;$i++): ?>
        <div class="skeleton-row">
            <?php for($j=0;$j<9;$j++): ?>
            <div class="skeleton-cell" style="width:<?php echo [70,120,80,80,110,110,70,70,100][$j]; ?>px;"></div>
            <?php endfor; ?>
        </div>
        <?php endfor; ?>
    </div>

    <div id="tableWrapper" style="display:none;">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Employee</th>
                        <th>Check In</th>
                        <th>Check Out</th>
                        <th>Hours</th>
                        <th>Location (In / Out)</th>
                        <th>Device</th>
                        <th>IP</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="tableBody"></tbody>
            </table>
        </div>
        <div id="emptyState" class="empty-state" style="display:none;">
            <i class="fa-solid fa-calendar-xmark" style="font-size:48px;color:#d1d5db;margin-bottom:12px;"></i>
            <h3 style="color:#6b7280;margin:0 0 6px;">No records found</h3>
            <p style="color:#9ca3af;margin:0;">Try adjusting your filters or date range</p>
        </div>
    </div>

    <div id="paginationWrapper" style="display:none;margin-top:14px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <span id="pageInfo" style="font-size:12px;color:#6b7280;"></span>
            <div id="paginationBtns" style="display:flex;gap:4px;flex-wrap:wrap;"></div>
        </div>
    </div>
</div>

</div><!-- /pane-records -->


<!-- ══════════════════════ TAB: SUMMARY ══════════════════════ -->
<div id="pane-summary" style="display:none;">

    <!-- Summary Filters -->
    <div class="content-card" style="margin-bottom:14px;padding:16px 20px;">
        <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <div class="filter-group" style="min-width:160px;">
                <label class="filter-label">Date From</label>
                <input type="date" id="sum_date_from" class="filter-input" onchange="loadSummary()">
            </div>
            <div class="filter-group" style="min-width:160px;">
                <label class="filter-label">Date To</label>
                <input type="date" id="sum_date_to" class="filter-input" onchange="loadSummary()">
            </div>
            <div class="filter-group" style="min-width:220px;">
                <label class="filter-label">Employee (optional)</label>
                <select id="sum_emp" class="filter-input select2-summary" style="width:100%;" onchange="filterSummaryTable()">
                    <option value="">All Employees</option>
                    <?php foreach($employees as $e): ?>
                    <option value="<?php echo $e['id']; ?>">
                        <?php echo htmlspecialchars($e['employee_id'].' — '.$e['employee_full_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group" style="min-width:180px;">
                <label class="filter-label">Designation / Category</label>
                <select id="sum_cat" class="filter-input" onchange="filterSummaryTable()">
                    <option value="">All Categories</option>
                </select>
            </div>
            <div>
                <button onclick="exportSummaryCSV()" class="btn btn-light" style="height:36px;">
                    <i class="fa-solid fa-file-csv"></i> Export
                </button>
            </div>
        </div>
    </div>

    <!-- Summary Meta Banner -->
    <div id="sumMeta" style="display:none;" class="sum-meta-banner">
        <div class="sum-meta-item">
            <span class="sum-meta-lbl">Period</span>
            <span class="sum-meta-val" id="sum-period">—</span>
        </div>
        <div class="sum-meta-item">
            <span class="sum-meta-lbl">Working Days</span>
            <span class="sum-meta-val" id="sum-wdays">—</span>
        </div>
        <div class="sum-meta-item">
            <span class="sum-meta-lbl">Total Employees</span>
            <span class="sum-meta-val" id="sum-emps">—</span>
        </div>
        <div class="sum-meta-item">
            <span class="sum-meta-lbl">Avg Attendance</span>
            <span class="sum-meta-val" id="sum-avg-pct">—</span>
        </div>
    </div>

    <!-- Category Summary -->
    <div class="content-card" style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <h3 class="card-title" style="margin:0;"><i class="fa-solid fa-layer-group" style="color:#6b7280;margin-right:6px;"></i>Summary by Designation / Category</h3>
        </div>
        <div id="sumCatLoader"><div class="skeleton-row" style="height:38px;"></div></div>
        <div id="sumCatWrap" style="display:none;">
            <div class="table-responsive">
                <table class="data-table" id="sumCatTable">
                    <thead>
                        <tr>
                            <th>Designation / Category</th>
                            <th style="text-align:center;">Employees</th>
                            <th style="text-align:center;">Working Days</th>
                            <th style="text-align:center;">Possible Days</th>
                            <th style="text-align:center;">Present Days</th>
                            <th style="text-align:center;">Absent Days</th>
                            <th style="text-align:center;">Attendance %</th>
                        </tr>
                    </thead>
                    <tbody id="sumCatBody"></tbody>
                </table>
            </div>
            <div id="sumCatEmpty" class="empty-state" style="display:none;">
                <i class="fa-solid fa-inbox" style="font-size:40px;color:#d1d5db;"></i>
                <p style="color:#9ca3af;margin:8px 0 0;">No data for selected period</p>
            </div>
        </div>
    </div>

    <!-- Employee Summary -->
    <div class="content-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <h3 class="card-title" style="margin:0;"><i class="fa-solid fa-user-group" style="color:#6b7280;margin-right:6px;"></i>Summary by Employee
                <span id="sumEmpCount" class="count-badge" style="margin-left:6px;">—</span>
            </h3>
            <input type="text" id="sumEmpSearch" class="search-input" placeholder="Search employee…" style="max-width:220px;padding-left:12px;" oninput="filterSummaryTable()">
        </div>
        <div id="sumEmpLoader"><div class="skeleton-row" style="height:38px;"></div></div>
        <div id="sumEmpWrap" style="display:none;">
            <div class="table-responsive">
                <table class="data-table" id="sumEmpTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Employee</th>
                            <th>Designation</th>
                            <th style="text-align:center;">Present Days</th>
                            <th style="text-align:center;">Absent Days</th>
                            <th style="text-align:center;">Completed</th>
                            <th style="text-align:center;">Incomplete</th>
                            <th style="text-align:center;">Avg Hours/Day</th>
                            <th style="text-align:center;">Attendance %</th>
                        </tr>
                    </thead>
                    <tbody id="sumEmpBody"></tbody>
                </table>
            </div>
            <div id="sumEmpEmpty" class="empty-state" style="display:none;">
                <i class="fa-solid fa-user-slash" style="font-size:40px;color:#d1d5db;"></i>
                <p style="color:#9ca3af;margin:8px 0 0;">No employees match the current filter</p>
            </div>
        </div>
    </div>

</div><!-- /pane-summary -->


<!-- ════════════════════════════ DETAIL MODAL ════════════════════════════ -->
<div id="detailModal" class="modal-overlay" onclick="closeModal(event)">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div>
                <div class="modal-title" id="m-name">—</div>
                <div class="modal-sub" id="m-date">—</div>
            </div>
            <button class="modal-close" onclick="document.getElementById('detailModal').classList.remove('open')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="m-time-strip">
            <div class="m-time-block">
                <div class="m-time-lbl">Check In</div>
                <div class="m-time-val green" id="m-in">—</div>
            </div>
            <div class="m-time-arrow"><i class="fa-solid fa-arrow-right"></i></div>
            <div class="m-time-block">
                <div class="m-time-lbl">Check Out</div>
                <div class="m-time-val blue" id="m-out">—</div>
            </div>
            <div class="m-time-arrow"><i class="fa-solid fa-clock"></i></div>
            <div class="m-time-block">
                <div class="m-time-lbl">Duration</div>
                <div class="m-time-val amber" id="m-hrs">—</div>
            </div>
        </div>

        <div class="modal-body">
            <div class="selfie-row">
                <div class="selfie-card">
                    <div class="selfie-label"><i class="fa-solid fa-right-to-bracket"></i> Check-In Photo</div>
                    <div class="selfie-wrap" id="m-photo-in">
                        <div class="selfie-empty"><i class="fa-solid fa-image-slash"></i><span>No Photo</span></div>
                    </div>
                </div>
                <div class="selfie-card">
                    <div class="selfie-label"><i class="fa-solid fa-right-from-bracket"></i> Check-Out Photo</div>
                    <div class="selfie-wrap" id="m-photo-out">
                        <div class="selfie-empty"><i class="fa-solid fa-image-slash"></i><span>No Photo</span></div>
                    </div>
                </div>
            </div>

            <div class="info-grid">
                <div class="info-card">
                    <div class="info-card-title"><i class="fa-solid fa-location-dot"></i> Location</div>
                    <div class="info-row"><span class="info-key">Address (In)</span><span class="info-val" id="m-loc-in">—</span></div>
                    <div class="info-row"><span class="info-key">Coords (In)</span><span class="info-val mono" id="m-coords-in">—</span></div>
                    <div class="info-row"><span class="info-key">Address (Out)</span><span class="info-val" id="m-loc-out">—</span></div>
                    <div class="info-row"><span class="info-key">Coords (Out)</span><span class="info-val mono" id="m-coords-out">—</span></div>
                    <div class="maps-row" id="mapsRow" style="display:none;">
                        <div class="map-thumb" id="mapThumbIn" onclick="openBigMap('in')" title="Click to view larger map">
                            <iframe id="mapFrameIn" width="100%" height="150" style="border:0;" loading="lazy" tabindex="-1"></iframe>
                            <div class="map-thumb-overlay">
                                <span class="map-thumb-tag in"><i class="fa-solid fa-right-to-bracket"></i> Check-In</span>
                                <i class="fa-solid fa-up-right-and-down-left-from-center map-thumb-expand"></i>
                            </div>
                        </div>
                        <div class="map-thumb" id="mapThumbOut" onclick="openBigMap('out')" title="Click to view larger map">
                            <iframe id="mapFrameOut" width="100%" height="150" style="border:0;" loading="lazy" tabindex="-1"></iframe>
                            <div class="map-thumb-overlay">
                                <span class="map-thumb-tag out"><i class="fa-solid fa-right-from-bracket"></i> Check-Out</span>
                                <i class="fa-solid fa-up-right-and-down-left-from-center map-thumb-expand"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="info-card">
                    <div class="info-card-title"><i class="fa-solid fa-mobile-screen"></i> Device &amp; Network</div>
                    <div class="info-row"><span class="info-key">Device</span><span class="info-val" id="m-device">—</span></div>
                    <div class="info-row"><span class="info-key">OS</span><span class="info-val" id="m-os">—</span></div>
                    <div class="info-row"><span class="info-key">Browser</span><span class="info-val" id="m-browser">—</span></div>
                    <div class="info-row"><span class="info-key">Screen</span><span class="info-val mono" id="m-screen">—</span></div>
                    <div class="info-row"><span class="info-key">Platform</span><span class="info-val" id="m-platform">—</span></div>
                    <div class="info-row"><span class="info-key">Language</span><span class="info-val" id="m-language">—</span></div>
                    <div class="info-row"><span class="info-key">IP Address</span><span class="info-val mono" id="m-ip">—</span></div>
                    <div class="info-row" style="border-top:1px dashed #e5e7eb;padding-top:8px;margin-top:2px;">
                        <span class="info-key" style="font-style:italic;color:#c1c5cb;">Captured at check-in only — the schema doesn't store a separate check-out device/IP.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- ════════════════════════════ BIG MAP MODAL ════════════════════════════ -->
<div id="bigMapModal" class="modal-overlay" onclick="closeBigMap(event)">
    <div class="modal-box bigmap-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div>
                <div class="modal-title" id="bigMap-title">—</div>
                <div class="modal-sub mono" id="bigMap-coords">—</div>
            </div>
            <button class="modal-close" onclick="document.getElementById('bigMapModal').classList.remove('open')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="bigmap-frame-wrap">
            <iframe id="bigMapFrame" width="100%" height="480" style="border:0;display:block;" allowfullscreen loading="lazy"></iframe>
        </div>
        <div class="bigmap-footer">
            <div class="bigmap-switch" id="bigMapSwitch"></div>
            <a id="bigMap-gmaps" href="#" target="_blank" class="btn btn-light">
                <i class="fa-solid fa-map-location-dot"></i> Open in Google Maps
            </a>
        </div>
    </div>
</div>


<!-- ════════════════════════════ STYLES ════════════════════════════ -->
<style>
/* ── Base ── */
.page-header { margin-bottom: 20px; }
.page-title  { font-size: 26px; font-weight: 700; color: #111827; margin: 0 0 3px; }
.page-subtitle{ font-size: 13px; color: #6b7280; margin: 0; }

/* ── Tab Bar ── */
.tab-bar {
    display: flex; gap: 4px; margin-bottom: 16px;
    border-bottom: 2px solid #e5e7eb; padding-bottom: 0;
}
.tab-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 18px; border: none; background: none;
    font-size: 13px; font-weight: 600; color: #6b7280;
    cursor: pointer; font-family: inherit;
    border-bottom: 2px solid transparent; margin-bottom: -2px;
    transition: all .18s;
}
.tab-btn:hover { color: #374151; }
.tab-btn.active { color: #111827; border-bottom-color: #111827; }

/* ── Stat grid ── */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 16px;
}
.stat-card {
    background: #fff; border-radius: 10px;
    box-shadow: 0 1px 3px rgba(0,0,0,.08);
    padding: 14px 16px;
    display: flex; align-items: center; gap: 14px;
}
.stat-icon {
    width: 42px; height: 42px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; flex-shrink: 0;
}
.stat-val  { font-size: 24px; font-weight: 800; color: #111827; line-height: 1; }
.stat-lbl  { font-size: 11px; color: #6b7280; margin-top: 3px; font-weight: 500; }

/* ── Search / filters ── */
.search-input {
    width: 100%; padding: 8px 34px 8px 34px;
    border: 1px solid #d1d5db; border-radius: 8px;
    font-size: 13px; font-family: inherit; transition: border-color .2s;
    box-sizing: border-box;
}
.search-input:focus { outline: none; border-color: #000; }

.pill-group { display: inline-flex; gap: 4px; }
.pill {
    padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 20px;
    background: #fff; font-size: 12px; font-weight: 500; color: #6b7280;
    cursor: pointer; transition: all .18s; font-family: inherit;
}
.pill:hover { background: #f9fafb; }
.pill.active { background: #111827; color: #fff; border-color: #111827; }

.status-toggle { display: inline-flex; border: 1px solid #d1d5db; border-radius: 8px; overflow: hidden; }
.status-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 7px 13px; border: none; background: #fff;
    font-size: 12px; font-weight: 500; color: #6b7280;
    cursor: pointer; transition: all .18s; font-family: inherit;
    border-right: 1px solid #e5e7eb; white-space: nowrap;
}
.status-btn:last-child { border-right: none; }
.status-btn.active { background: #111827; color: #fff; }

.btn-filter-toggle {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; background: #fff; border: 1px solid #d1d5db;
    border-radius: 8px; font-size: 13px; font-weight: 500; color: #374151;
    cursor: pointer; transition: all .18s; font-family: inherit;
}
.btn-filter-toggle.active { background: #111827; color: #fff; border-color: #111827; }

.content-card {
    background: #fff; border-radius: 10px;
    box-shadow: 0 1px 3px rgba(0,0,0,.08);
    padding: 18px 20px; margin-bottom: 14px;
}
.card-title { font-size: 14px; font-weight: 600; color: #111827; }
.count-badge {
    background: #f3f4f6; color: #374151;
    font-size: 11px; font-weight: 600; padding: 2px 9px; border-radius: 20px;
}
.filter-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; }
.filter-group { display: flex; flex-direction: column; gap: 5px; }
.filter-label { font-size: 10px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .4px; }
.filter-input {
    padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 6px;
    font-size: 13px; font-family: inherit; background: #fff; color: #111827;
}
.filter-input:focus { outline: none; border-color: #000; }

/* ── Select2 overrides to match HMS style ── */
.select2-container--default .select2-selection--single {
    border: 1px solid #d1d5db !important;
    border-radius: 6px !important;
    height: 36px !important;
    font-size: 13px;
    font-family: inherit;
    display: flex; align-items: center;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 34px !important;
    padding-left: 10px !important;
    padding-right: 30px !important;
    color: #111827;
    font-size: 13px;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 34px !important;
    right: 6px !important;
}
.select2-container--default .select2-selection--single .select2-selection__arrow b {
    border-color: #9ca3af transparent transparent transparent !important;
}
.select2-container--default.select2-container--open .select2-selection--single .select2-selection__arrow b {
    border-color: transparent transparent #9ca3af transparent !important;
}
.select2-container--default .select2-results__option--highlighted[aria-selected] {
    background-color: #111827 !important;
}
.select2-container--default .select2-search--dropdown .select2-search__field {
    border: 1px solid #d1d5db; border-radius: 5px;
    padding: 6px 10px; font-size: 13px; font-family: inherit;
}
.select2-container--default .select2-search--dropdown .select2-search__field:focus {
    outline: none; border-color: #111827;
}
.select2-dropdown {
    border: 1px solid #d1d5db !important;
    border-radius: 8px !important;
    box-shadow: 0 4px 16px rgba(0,0,0,.1) !important;
    font-size: 13px;
    font-family: inherit;
}
.select2-results__option {
    padding: 8px 12px !important;
    font-size: 13px !important;
}

/* ── Summary Meta Banner ── */
.sum-meta-banner {
    display: flex; gap: 0; background: #fff; border-radius: 10px;
    box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 14px;
    overflow: hidden;
}
.sum-meta-item {
    flex: 1; display: flex; flex-direction: column; gap: 3px;
    padding: 14px 20px; border-right: 1px solid #f3f4f6; text-align: center;
}
.sum-meta-item:last-child { border-right: none; }
.sum-meta-lbl { font-size: 10px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: .4px; }
.sum-meta-val { font-size: 20px; font-weight: 800; color: #111827; }

/* ── Skeleton ── */
.skeleton-row { display: flex; gap: 14px; padding: 11px 0; border-bottom: 1px solid #f3f4f6; align-items: center; }
.skeleton-cell {
    height: 13px; background: linear-gradient(90deg,#f3f4f6 25%,#e9eaec 50%,#f3f4f6 75%);
    background-size: 200% 100%; animation: shimmer 1.4s infinite; border-radius: 4px; flex-shrink: 0;
}
@keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }

/* ── Table ── */
.table-responsive { overflow-x: auto; }
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table thead { background: #f9fafb; border-bottom: 2px solid #e5e7eb; }
.data-table th {
    padding: 9px 13px; text-align: left; font-weight: 600;
    color: #6b7280; font-size: 10px; text-transform: uppercase; letter-spacing: .5px;
    white-space: nowrap;
}
.data-table tbody tr { border-bottom: 1px solid #f3f4f6; transition: background .12s; }
.data-table tbody tr:hover { background: #fafafa; }
.data-table td { padding: 10px 13px; color: #111827; vertical-align: middle; }

/* Badges */
.badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.badge-in    { background:#dcfce7;color:#166534; }
.badge-out   { background:#dbeafe;color:#1e40af; }
.badge-none  { background:#f3f4f6;color:#9ca3af; }
.badge-done  { background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0; }
.badge-absent{ background:#fef2f2;color:#dc2626;border:1px solid #fecaca; }
.badge-present{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0; }

/* Attendance % bar */
.pct-bar-wrap { display:flex; align-items:center; gap:8px; }
.pct-bar-bg   { flex:1; height:6px; background:#f3f4f6; border-radius:20px; overflow:hidden; min-width:60px; }
.pct-bar-fill { height:100%; border-radius:20px; transition:width .4s; }
.pct-label    { font-size:12px; font-weight:700; white-space:nowrap; }

/* Action buttons */
.action-buttons { display: flex; gap: 5px; }
.btn-action {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 6px;
    border: 1px solid #e5e7eb; background: #fff; color: #6b7280;
    cursor: pointer; transition: all .18s; text-decoration: none; font-size: 11px;
}
.btn-action:hover { transform: translateY(-1px); box-shadow: 0 2px 4px rgba(0,0,0,.1); }
.btn-view:hover { background:#3b82f6;color:#fff;border-color:#3b82f6; }
.btn-map:hover  { background:#16a34a;color:#fff;border-color:#16a34a; }

/* Pagination */
.page-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 30px; height: 30px; padding: 0 7px;
    border: 1px solid #e5e7eb; border-radius: 6px; background: #fff;
    font-size: 12px; font-weight: 500; color: #374151; cursor: pointer;
    transition: all .18s; font-family: inherit;
}
.page-btn:hover { background: #f3f4f6; }
.page-btn.active { background: #111827; color: #fff; border-color: #111827; }
.page-btn:disabled { opacity: .4; cursor: not-allowed; }

/* Misc */
.empty-state { text-align: center; padding: 48px 20px; }
.btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;
    border: none; border-radius: 8px; font-size: 13px; font-weight: 600;
    cursor: pointer; transition: all .2s; text-decoration: none; font-family: inherit;
}
.btn-light { background: #f9fafb; color: #374151; border: 1px solid #d1d5db; }
.btn-light:hover { background: #f3f4f6; }

/* ── Modal ── */
.modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 9000;
    display: none; align-items: center; justify-content: center; padding: 16px;
    backdrop-filter: blur(3px);
}
.modal-overlay.open { display: flex; animation: fadeIn .2s; }
@keyframes fadeIn { from{opacity:0} to{opacity:1} }

.modal-box {
    background: #fff; border-radius: 14px; width: 100%; max-width: 820px;
    max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.2);
    animation: slideUp .22s;
}
@keyframes slideUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }

.modal-header {
    display: flex; align-items: flex-start; justify-content: space-between;
    padding: 18px 20px 14px; border-bottom: 1px solid #f3f4f6;
    position: sticky; top: 0; background: #fff; z-index: 2;
}
.modal-title { font-size: 17px; font-weight: 700; color: #111827; }
.modal-sub   { font-size: 12px; color: #6b7280; margin-top: 2px; }
.modal-close {
    width: 32px; height: 32px; border-radius: 8px; border: none;
    background: #f3f4f6; color: #6b7280; cursor: pointer; font-size: 14px;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    transition: all .18s;
}
.modal-close:hover { background: #e5e7eb; color: #111827; }

.m-time-strip {
    display: flex; align-items: center; gap: 0;
    padding: 14px 20px; border-bottom: 1px solid #f3f4f6; background: #fafafa;
}
.m-time-block { flex: 1; text-align: center; }
.m-time-lbl   { font-size: 10px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 4px; }
.m-time-val   { font-size: 22px; font-weight: 800; color: #111827; letter-spacing: -.5px; }
.m-time-val.green { color: #16a34a; }
.m-time-val.blue  { color: #2563eb; }
.m-time-val.amber { color: #d97706; }
.m-time-arrow { font-size: 14px; color: #d1d5db; padding: 0 10px; flex-shrink: 0; }

.modal-body { padding: 16px 20px; }
.selfie-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
.selfie-label { font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 6px; display: flex; align-items: center; gap: 5px; }
.selfie-wrap { border-radius: 10px; overflow: hidden; background: #f3f4f6; aspect-ratio: 4/3; display: flex; align-items: center; justify-content: center; }
.selfie-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }
.selfie-empty { display: flex; flex-direction: column; align-items: center; gap: 6px; color: #d1d5db; font-size: 22px; }
.selfie-empty span { font-size: 11px; color: #9ca3af; font-weight: 500; }

.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.info-card { background: #f9fafb; border-radius: 10px; padding: 14px; border: 1px solid #f3f4f6; }
.info-card-title { font-size: 12px; font-weight: 700; color: #374151; margin-bottom: 10px; display: flex; align-items: center; gap: 7px; padding-bottom: 8px; border-bottom: 1px solid #e5e7eb; }
.info-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; padding: 5px 0; border-bottom: 1px solid #f3f4f6; }
.info-row:last-child { border-bottom: none; }
.info-key { font-size: 11px; color: #9ca3af; font-weight: 500; white-space: nowrap; flex-shrink: 0; }
.info-val { font-size: 12px; color: #111827; font-weight: 500; text-align: right; word-break: break-all; }
.info-val.mono { font-family: 'SF Mono', 'Fira Mono', monospace; font-size: 11px; }
.dev-chip { display: inline-flex; align-items: center; gap: 4px; background: #f3f4f6; border-radius: 6px; padding: 2px 7px; font-size: 11px; font-weight: 500; color: #374151; }

/* Row highlight for absent */
.row-all-absent td { color: #9ca3af; }
.row-all-absent .emp-name { color: #dc2626 !important; }

/* ── Modal: check-in / check-out map thumbnails ── */
.maps-row {
    display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
    margin-top: 10px;
}
.map-thumb {
    position: relative; border-radius: 10px; overflow: hidden;
    height: 150px; background: #f3f4f6; cursor: pointer;
    border: 1px solid #e5e7eb; transition: box-shadow .18s, transform .18s;
}
.map-thumb:hover { box-shadow: 0 4px 14px rgba(0,0,0,.14); transform: translateY(-1px); }
.map-thumb iframe { pointer-events: none; } /* clicks always open the big map, never scroll the mini map */
.map-thumb-overlay {
    position: absolute; inset: 0;
    display: flex; align-items: flex-start; justify-content: space-between;
    padding: 7px 8px; pointer-events: none;
}
.map-thumb-tag {
    display: inline-flex; align-items: center; gap: 5px;
    background: rgba(17,24,39,.78); color: #fff;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px;
    padding: 3px 8px; border-radius: 20px;
}
.map-thumb-tag.in  i { color: #4ade80; }
.map-thumb-tag.out i { color: #60a5fa; }
.map-thumb-expand {
    background: rgba(17,24,39,.78); color: #fff;
    width: 24px; height: 24px; border-radius: 6px;
    display: flex; align-items: center; justify-content: center; font-size: 11px;
}

/* ── Big map modal ── */
.bigmap-box { max-width: 900px; padding: 0; }
.bigmap-frame-wrap { background: #f3f4f6; }
.bigmap-footer {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    padding: 14px 20px; border-top: 1px solid #f3f4f6;
}
.bigmap-switch { display: inline-flex; border: 1px solid #d1d5db; border-radius: 8px; overflow: hidden; }
.bigmap-switch button {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; border: none; background: #fff;
    font-size: 12px; font-weight: 600; color: #6b7280;
    cursor: pointer; font-family: inherit; border-right: 1px solid #e5e7eb;
    transition: all .18s;
}
.bigmap-switch button:last-child { border-right: none; }
.bigmap-switch button.active { background: #111827; color: #fff; }
.bigmap-switch button:disabled { opacity: .4; cursor: not-allowed; }

/* Location In/Out stacked cell */
.loc-stack { display: flex; flex-direction: column; gap: 2px; font-size: 11px; max-width: 190px; }
.loc-stack .loc-line { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.loc-stack .loc-tag { font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: .3px; margin-right: 4px; }
.loc-stack .loc-tag.in  { color: #16a34a; }
.loc-stack .loc-tag.out { color: #2563eb; }

@media(max-width:1024px) { .stat-grid{grid-template-columns:repeat(2,1fr);} .filter-grid{grid-template-columns:repeat(2,1fr);} .sum-meta-banner{flex-wrap:wrap;} .sum-meta-item{min-width:50%;} }
@media(max-width:768px)  {
    .stat-grid{grid-template-columns:repeat(2,1fr);}
    .selfie-row,.info-grid{grid-template-columns:1fr;}
    .page-title{font-size:20px;}
    .m-time-val{font-size:18px;}
    .pill-group{display:none;}
    .sum-meta-item{min-width:100%;}
}
</style>


<!-- ════════════════════════════ SCRIPTS ════════════════════════════ -->
<script>
let searchTimer = null;
let currentPage = 1;
let currentStatus = '';
let quickMode = 'today';
let allRows = [];
let summaryData = null;

// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(tab) {
    document.getElementById('pane-records').style.display = tab === 'records' ? 'block' : 'none';
    document.getElementById('pane-summary').style.display = tab === 'summary' ? 'block' : 'none';
    document.getElementById('tab-records').classList.toggle('active', tab === 'records');
    document.getElementById('tab-summary').classList.toggle('active', tab === 'summary');
    if (tab === 'summary' && !summaryData) loadSummary();
}

// ── Init ─────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    setQuick('today', document.querySelector('.pill.active'));

    document.getElementById('searchInput').addEventListener('input', () => {
        clearTimeout(searchTimer);
        document.getElementById('searchSpinner').style.display = 'inline';
        searchTimer = setTimeout(() => loadData(1), 300);
    });

    if (typeof $ !== 'undefined' && $.fn.select2) {
        $('.select2-employee').select2({
            placeholder: 'All Employees',
            allowClear: true,
            width: '100%',
        }).on('change', function() { loadData(1); });

        $('.select2-summary').select2({
            placeholder: 'All Employees',
            allowClear: true,
            width: '100%',
        }).on('change', function() { filterSummaryTable(); });
    }

    const today = new Date().toISOString().split('T')[0];
    document.getElementById('sum_date_from').value = today.slice(0,7) + '-01';
    document.getElementById('sum_date_to').value   = today;
});

// ── Quick date pills ──────────────────────────────────────────────────────────
function setQuick(mode, btn) {
    quickMode = mode;
    document.querySelectorAll('.pill').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');

    const from  = document.getElementById('f_date_from');
    const to    = document.getElementById('f_date_to');
    const cd    = document.getElementById('customDates');
    const today = new Date().toISOString().split('T')[0];

    if (mode === 'today') {
        from.value = today; to.value = today; cd.style.display = 'none';
    } else if (mode === 'week') {
        const d = new Date(); d.setDate(d.getDate() - d.getDay());
        from.value = d.toISOString().split('T')[0]; to.value = today; cd.style.display = 'none';
    } else if (mode === 'month') {
        from.value = today.slice(0,7)+'-01'; to.value = today; cd.style.display = 'none';
    } else {
        cd.style.cssText = 'display:flex;gap:6px;align-items:center;'; from.value = ''; to.value = '';
    }
    loadData(1);
}

// ── Status toggle ─────────────────────────────────────────────────────────────
function setStatus(val, btn) {
    currentStatus = val;
    document.querySelectorAll('.status-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    loadData(1);
}

// ── Load records data ─────────────────────────────────────────────────────────
function loadData(page) {
    currentPage = page;
    const search = document.getElementById('searchInput').value.trim();
    const f_emp  = document.getElementById('f_emp')?.value || '';
    const df     = document.getElementById('f_date_from').value;
    const dt     = document.getElementById('f_date_to').value;

    const params = new URLSearchParams({
        ajax:'1', page, search, f_status: currentStatus,
        f_emp, date_from: df, date_to: dt
    });

    document.getElementById('skeletonLoader').style.display    = 'block';
    document.getElementById('tableWrapper').style.display      = 'none';
    document.getElementById('paginationWrapper').style.display = 'none';

    fetch('attendance_report.php?' + params)
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(text => {
            document.getElementById('searchSpinner').style.display = 'none';
            document.getElementById('skeletonLoader').style.display = 'none';
            document.getElementById('tableWrapper').style.display  = 'block';

            let data;
            try {
                const jsonStart = text.indexOf('{');
                const jsonStr   = jsonStart >= 0 ? text.slice(jsonStart) : text;
                data = JSON.parse(jsonStr);
            } catch(e) {
                console.error('JSON parse error. Raw response:', text);
                document.getElementById('tableBody').innerHTML =
                    '<tr><td colspan="9" style="text-align:center;color:#ef4444;padding:32px;">' +
                    '<i class="fa-solid fa-triangle-exclamation" style="margin-right:6px;"></i>' +
                    'Server response error. Check browser console for details.</td></tr>';
                return;
            }

            if (data.stats) {
                document.getElementById('s-total').textContent = data.stats.total || 0;
                document.getElementById('s-in').textContent    = data.stats.checked_in || 0;
                document.getElementById('s-out').textContent   = data.stats.checked_out || 0;
                document.getElementById('s-done').textContent  = data.stats.completed || 0;
            }

            allRows = data.rows || [];
            renderTable(allRows);
            renderPagination(data.page, data.pages, data.total);
            document.getElementById('totalCount').textContent = Number(data.total || 0).toLocaleString();
        })
        .catch(err => {
            document.getElementById('searchSpinner').style.display = 'none';
            document.getElementById('skeletonLoader').style.display = 'none';
            document.getElementById('tableWrapper').style.display  = 'block';
            console.error('Fetch error:', err);
            document.getElementById('tableBody').innerHTML =
                '<tr><td colspan="9" style="text-align:center;color:#ef4444;padding:32px;">' +
                '<i class="fa-solid fa-triangle-exclamation" style="margin-right:6px;"></i>' +
                'Could not connect. Check your network and try again.</td></tr>';
        });
}

// ── Render records table ──────────────────────────────────────────────────────
function renderTable(rows) {
    const tbody = document.getElementById('tableBody');
    const empty = document.getElementById('emptyState');
    if (!rows.length) { tbody.innerHTML = ''; empty.style.display = 'block'; return; }
    empty.style.display = 'none';

    tbody.innerHTML = rows.map((r,i) => {
        const inTime  = r.check_in  ? r.check_in.slice(11,16)  : null;
        const outTime = r.check_out ? r.check_out.slice(11,16) : null;
        const mins    = parseInt(r.minutes_worked) || 0;
        const hrsDisp = mins ? `${Math.floor(mins/60)}h ${mins%60}m` : '—';
        const dateDisp = r.att_date ? new Date(r.att_date+'T00:00:00').toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : '—';

        const devOS = r.device_os  ? `<span class="dev-chip"><i class="fa-solid fa-mobile-screen" style="font-size:9px;"></i> ${esc(r.device_os)}</span>` : '<span style="color:#d1d5db;">—</span>';
        const ipVal = r.ip_address ? `<span style="font-family:monospace;font-size:11px;">${esc(r.ip_address)}</span>` : '<span style="color:#d1d5db;">—</span>';

        // Location: show both check-in and check-out addresses, stacked.
        const locInShort  = r.location_name_in  ? esc(r.location_name_in.split(',').slice(0,2).join(','))  : null;
        const locOutShort = r.location_name_out ? esc(r.location_name_out.split(',').slice(0,2).join(',')) : null;
        let locCell;
        if (!locInShort && !locOutShort) {
            locCell = '<span style="color:#d1d5db;">—</span>';
        } else {
            locCell = '<div class="loc-stack">';
            locCell += `<div class="loc-line"><span class="loc-tag in">In</span>${locInShort || '<span style="color:#d1d5db;">—</span>'}</div>`;
            locCell += `<div class="loc-line"><span class="loc-tag out">Out</span>${locOutShort || '<span style="color:#d1d5db;">—</span>'}</div>`;
            locCell += '</div>';
        }

        return `<tr>
            <td><strong>${esc(dateDisp)}</strong></td>
            <td>
                <div style="font-weight:600;">${esc(r.employee_full_name)}</div>
                <div style="font-size:11px;color:#9ca3af;">${esc(r.emp_code)}</div>
            </td>
            <td>${inTime  ? `<span class="badge badge-in"  style="font-size:12px;padding:3px 9px;">${esc(inTime)}</span>`  : '<span style="color:#d1d5db;">—</span>'}</td>
            <td>${outTime ? `<span class="badge badge-out" style="font-size:12px;padding:3px 9px;">${esc(outTime)}</span>` : '<span style="color:#d1d5db;">—</span>'}</td>
            <td><strong style="color:#d97706;">${hrsDisp}</strong></td>
            <td>${locCell}</td>
            <td>${devOS}</td>
            <td>${ipVal}</td>
            <td>
                <div class="action-buttons">
                    <button class="btn-action btn-view" onclick="openModal(${i})" title="View Details">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                    ${r.latitude_in && r.longitude_in ? `
                    <a class="btn-action btn-map" href="https://www.google.com/maps?q=${r.latitude_in},${r.longitude_in}" target="_blank" title="Open in Google Maps">
                        <i class="fa-solid fa-map-location-dot"></i>
                    </a>` : ''}
                </div>
            </td>
        </tr>`;
    }).join('');
}

// ── Load Summary ──────────────────────────────────────────────────────────────
function loadSummary() {
    const df = document.getElementById('sum_date_from').value;
    const dt = document.getElementById('sum_date_to').value;
    if (!df || !dt) return;

    summaryData = null;

    document.getElementById('sumCatLoader').style.display  = 'block';
    document.getElementById('sumCatWrap').style.display    = 'none';
    document.getElementById('sumEmpLoader').style.display  = 'block';
    document.getElementById('sumEmpWrap').style.display    = 'none';
    document.getElementById('sumMeta').style.display       = 'none';

    fetch(`attendance_report.php?ajax=summary&date_from=${df}&date_to=${dt}`)
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(text => {
            let data;
            try {
                const jsonStart = text.indexOf('{');
                data = JSON.parse(jsonStart >= 0 ? text.slice(jsonStart) : text);
            } catch(e) {
                console.error('Summary JSON parse error:', text);
                document.getElementById('sumCatLoader').style.display = 'none';
                document.getElementById('sumEmpLoader').style.display = 'none';
                document.getElementById('sumCatBody').innerHTML = '<tr><td colspan="7" style="text-align:center;color:#ef4444;padding:20px;"><i class="fa-solid fa-triangle-exclamation" style="margin-right:6px;"></i>Server response error. Check browser console.</td></tr>';
                document.getElementById('sumEmpBody').innerHTML  = '<tr><td colspan="9" style="text-align:center;color:#ef4444;padding:20px;"></td></tr>';
                document.getElementById('sumCatWrap').style.display = 'block';
                document.getElementById('sumEmpWrap').style.display = 'block';
                return;
            }
            summaryData = data;

            const catSel = document.getElementById('sum_cat');
            const existing = Array.from(catSel.options).map(o => o.value);
            data.categories.forEach(c => {
                if (!existing.includes(c.category)) {
                    const opt = new Option(c.category, c.category);
                    catSel.add(opt);
                }
            });

            document.getElementById('sum-period').textContent  = fmtDate(data.date_from) + ' — ' + fmtDate(data.date_to);
            document.getElementById('sum-wdays').textContent   = data.working_days;
            document.getElementById('sum-emps').textContent    = data.employees.length;
            const avgPct = data.employees.length
                ? (data.employees.reduce((s,e) => s + parseFloat(e.attendance_pct||0), 0) / data.employees.length).toFixed(1)
                : '0';
            document.getElementById('sum-avg-pct').textContent = avgPct + '%';
            document.getElementById('sumMeta').style.display   = 'flex';

            document.getElementById('sumCatLoader').style.display = 'none';
            document.getElementById('sumCatWrap').style.display   = 'block';
            document.getElementById('sumEmpLoader').style.display = 'none';
            document.getElementById('sumEmpWrap').style.display   = 'block';

            filterSummaryTable();
        })
        .catch(() => {
            document.getElementById('sumCatLoader').style.display = 'none';
            document.getElementById('sumEmpLoader').style.display = 'none';
            document.getElementById('sumCatBody').innerHTML = '<tr><td colspan="7" style="text-align:center;color:#ef4444;padding:20px;">Error loading summary.</td></tr>';
            document.getElementById('sumEmpBody').innerHTML  = '<tr><td colspan="9" style="text-align:center;color:#ef4444;padding:20px;">Error loading summary.</td></tr>';
            document.getElementById('sumCatWrap').style.display = 'block';
            document.getElementById('sumEmpWrap').style.display = 'block';
        });
}

// ── Filter + render summary tables ───────────────────────────────────────────
function filterSummaryTable() {
    if (!summaryData) return;

    const filterEmp    = (document.getElementById('sum_emp').value || '').toString();
    const filterCat    = document.getElementById('sum_cat').value || '';
    const filterSearch = (document.getElementById('sumEmpSearch')?.value || '').toLowerCase();
    const wdays        = summaryData.working_days;

    let emps = summaryData.employees;
    if (filterEmp)    emps = emps.filter(e => e.emp_id.toString() === filterEmp);
    if (filterCat)    emps = emps.filter(e => e.designation === filterCat);
    if (filterSearch) emps = emps.filter(e =>
        e.employee_full_name.toLowerCase().includes(filterSearch) ||
        e.emp_code.toLowerCase().includes(filterSearch)
    );

    const empBody  = document.getElementById('sumEmpBody');
    const empEmpty = document.getElementById('sumEmpEmpty');
    document.getElementById('sumEmpCount').textContent = emps.length;

    if (!emps.length) {
        empBody.innerHTML = '';
        empEmpty.style.display = 'block';
    } else {
        empEmpty.style.display = 'none';
        empBody.innerHTML = emps.map((e,i) => {
            const pct      = parseFloat(e.attendance_pct) || 0;
            const pctColor = pct >= 80 ? '#16a34a' : pct >= 50 ? '#d97706' : '#dc2626';
            const isAbsent = parseInt(e.present_days) === 0;
            const avgH     = e.avg_hours ? e.avg_hours + 'h' : '—';
            return `<tr class="${isAbsent ? 'row-all-absent' : ''}">
                <td style="color:#9ca3af;font-size:12px;">${i+1}</td>
                <td>
                    <div class="emp-name" style="font-weight:600;">${esc(e.employee_full_name)}</div>
                    <div style="font-size:11px;color:#9ca3af;">${esc(e.emp_code)}</div>
                </td>
                <td><span style="font-size:12px;color:#6b7280;">${esc(e.designation)}</span></td>
                <td style="text-align:center;">
                    <span class="badge badge-present">${e.present_days}</span>
                    <div style="font-size:10px;color:#9ca3af;margin-top:2px;">of ${wdays}</div>
                </td>
                <td style="text-align:center;">
                    ${parseInt(e.absent_days) > 0
                        ? `<span class="badge badge-absent">${e.absent_days}</span>`
                        : `<span style="color:#9ca3af;font-size:12px;">0</span>`}
                </td>
                <td style="text-align:center;"><span style="font-size:13px;font-weight:600;color:#16a34a;">${e.completed_days}</span></td>
                <td style="text-align:center;"><span style="font-size:13px;font-weight:600;color:#d97706;">${e.incomplete_days}</span></td>
                <td style="text-align:center;"><span style="font-size:13px;color:#374151;">${avgH}</span></td>
                <td style="text-align:center;min-width:130px;">
                    <div class="pct-bar-wrap">
                        <div class="pct-bar-bg">
                            <div class="pct-bar-fill" style="width:${pct}%;background:${pctColor};"></div>
                        </div>
                        <span class="pct-label" style="color:${pctColor};">${pct}%</span>
                    </div>
                </td>
            </tr>`;
        }).join('');
    }

    let cats = summaryData.categories;
    if (filterCat) cats = cats.filter(c => c.category === filterCat);

    const catBody  = document.getElementById('sumCatBody');
    const catEmpty = document.getElementById('sumCatEmpty');

    if (!cats.length) {
        catBody.innerHTML = '';
        catEmpty.style.display = 'block';
    } else {
        catEmpty.style.display = 'none';
        catBody.innerHTML = cats.map(c => {
            const pct      = parseFloat(c.attendance_pct) || 0;
            const pctColor = pct >= 80 ? '#16a34a' : pct >= 50 ? '#d97706' : '#dc2626';
            return `<tr>
                <td style="font-weight:600;">${esc(c.category)}</td>
                <td style="text-align:center;"><strong>${c.total_employees}</strong></td>
                <td style="text-align:center;">${wdays}</td>
                <td style="text-align:center;">${c.total_possible_days}</td>
                <td style="text-align:center;"><span class="badge badge-present">${c.total_present_records}</span></td>
                <td style="text-align:center;">
                    ${parseInt(c.total_absent_records) > 0
                        ? `<span class="badge badge-absent">${c.total_absent_records}</span>`
                        : `<span style="color:#9ca3af;">0</span>`}
                </td>
                <td style="text-align:center;min-width:130px;">
                    <div class="pct-bar-wrap">
                        <div class="pct-bar-bg">
                            <div class="pct-bar-fill" style="width:${pct}%;background:${pctColor};"></div>
                        </div>
                        <span class="pct-label" style="color:${pctColor};">${pct}%</span>
                    </div>
                </td>
            </tr>`;
        }).join('');
    }
}

// ── Modal ─────────────────────────────────────────────────────────────────────
function openModal(idx) {
    const r = allRows[idx];
    if (!r) return;
    const modal = document.getElementById('detailModal');
    document.getElementById('m-name').textContent = r.employee_full_name + ' (' + r.emp_code + ')';
    const dateDisp = r.att_date ? new Date(r.att_date+'T00:00:00').toLocaleDateString('en-GB',{weekday:'long',day:'2-digit',month:'long',year:'numeric'}) : '—';
    document.getElementById('m-date').textContent = dateDisp;
    const inTime  = r.check_in  ? r.check_in.slice(11,16)  : '—';
    const outTime = r.check_out ? r.check_out.slice(11,16) : '—';
    const mins    = parseInt(r.minutes_worked) || 0;
    const hrsDisp = mins ? `${Math.floor(mins/60)}h ${mins%60}m` : '—';
    document.getElementById('m-in').textContent  = inTime;
    document.getElementById('m-out').textContent = outTime;
    document.getElementById('m-hrs').textContent = hrsDisp;
    const pIn  = document.getElementById('m-photo-in');
    const pOut = document.getElementById('m-photo-out');
    pIn.innerHTML  = r.selfie_in  ? `<img src="${r.selfie_in}" alt="Check-In Selfie">`  : `<div class="selfie-empty"><i class="fa-solid fa-image-slash"></i><span>No Photo</span></div>`;
    pOut.innerHTML = r.selfie_out ? `<img src="${r.selfie_out}" alt="Check-Out Selfie">` : `<div class="selfie-empty"><i class="fa-solid fa-image-slash"></i><span>No Photo</span></div>`;
    document.getElementById('m-loc-in').textContent    = r.location_name_in  || '—';
    document.getElementById('m-loc-out').textContent   = r.location_name_out || '—';
    document.getElementById('m-coords-in').textContent  = (r.latitude_in && r.longitude_in)  ? `${parseFloat(r.latitude_in).toFixed(6)}, ${parseFloat(r.longitude_in).toFixed(6)}`   : '—';
    document.getElementById('m-coords-out').textContent = (r.latitude_out && r.longitude_out) ? `${parseFloat(r.latitude_out).toFixed(6)}, ${parseFloat(r.longitude_out).toFixed(6)}` : '—';
    setupModalMaps(r);
    document.getElementById('m-device').textContent   = r.device_model    || '—';
    document.getElementById('m-os').textContent       = r.device_os       || '—';
    document.getElementById('m-browser').textContent  = r.device_browser  || '—';
    document.getElementById('m-screen').textContent   = r.device_screen   || '—';
    document.getElementById('m-platform').textContent = r.device_platform || '—';
    document.getElementById('m-language').textContent = r.device_language || '—';
    document.getElementById('m-ip').textContent       = r.ip_address      || '—';
    modal.classList.add('open');
}

function closeModal(e) {
    if (e.target === document.getElementById('detailModal'))
        document.getElementById('detailModal').classList.remove('open');
}

// ── Check-in / check-out mini maps + big map modal ────────────────────────────
let currentMapData = { in: null, out: null };

function osmEmbedSrc(lat, lng, zoom) {
    const d = zoom || 0.005;
    return `https://www.openstreetmap.org/export/embed.html?bbox=${lng-d},${lat-d},${lng+d},${lat+d}&layer=mapnik&marker=${lat},${lng}`;
}

function setupModalMaps(r) {
    const hasIn  = !!(r.latitude_in  && r.longitude_in);
    const hasOut = !!(r.latitude_out && r.longitude_out);

    currentMapData.in  = hasIn  ? { lat: parseFloat(r.latitude_in),  lng: parseFloat(r.longitude_in),  label: 'Check-In Location',  address: r.location_name_in  || '' } : null;
    currentMapData.out = hasOut ? { lat: parseFloat(r.latitude_out), lng: parseFloat(r.longitude_out), label: 'Check-Out Location', address: r.location_name_out || '' } : null;

    const mapsRow   = document.getElementById('mapsRow');
    const thumbIn   = document.getElementById('mapThumbIn');
    const thumbOut  = document.getElementById('mapThumbOut');
    const frameIn   = document.getElementById('mapFrameIn');
    const frameOut  = document.getElementById('mapFrameOut');

    if (!hasIn && !hasOut) {
        mapsRow.style.display = 'none';
        return;
    }
    mapsRow.style.display = 'grid';

    if (hasIn) {
        frameIn.src = osmEmbedSrc(currentMapData.in.lat, currentMapData.in.lng);
        thumbIn.style.display = '';
    } else {
        thumbIn.style.display = 'none';
    }

    if (hasOut) {
        frameOut.src = osmEmbedSrc(currentMapData.out.lat, currentMapData.out.lng);
        thumbOut.style.display = '';
    } else {
        thumbOut.style.display = 'none';
    }
}

function openBigMap(which) {
    const point = currentMapData[which];
    if (!point) return;

    document.getElementById('bigMap-title').textContent  = point.label;
    document.getElementById('bigMap-coords').textContent = point.address
        ? `${point.address} · ${point.lat.toFixed(6)}, ${point.lng.toFixed(6)}`
        : `${point.lat.toFixed(6)}, ${point.lng.toFixed(6)}`;
    document.getElementById('bigMapFrame').src = osmEmbedSrc(point.lat, point.lng, 0.0025);
    document.getElementById('bigMap-gmaps').href = `https://www.google.com/maps?q=${point.lat},${point.lng}`;

    renderBigMapSwitch(which);
    document.getElementById('bigMapModal').classList.add('open');
}

function renderBigMapSwitch(active) {
    const wrap = document.getElementById('bigMapSwitch');
    const mk = (key, icon, label) => {
        const has = !!currentMapData[key];
        return `<button ${has ? '' : 'disabled'} class="${key===active?'active':''}" onclick="${has ? `openBigMap('${key}')` : ''}">
            <i class="fa-solid ${icon}"></i> ${label}
        </button>`;
    };
    wrap.innerHTML = mk('in', 'fa-right-to-bracket', 'Check-In') + mk('out', 'fa-right-from-bracket', 'Check-Out');
}

function closeBigMap(e) {
    if (e.target === document.getElementById('bigMapModal'))
        document.getElementById('bigMapModal').classList.remove('open');
}

// ── Pagination ────────────────────────────────────────────────────────────────
function renderPagination(page, pages, total) {
    const wrapper  = document.getElementById('paginationWrapper');
    const btns     = document.getElementById('paginationBtns');
    const pageInfo = document.getElementById('pageInfo');
    const limit    = 25;
    const from     = (page-1)*limit + 1;
    const to       = Math.min(page*limit, total);
    if (pages <= 1) { wrapper.style.display = 'none'; return; }
    wrapper.style.display = 'block';
    pageInfo.textContent = `Showing ${from}–${to} of ${Number(total).toLocaleString()} records`;
    let html = `<button class="page-btn" onclick="loadData(${page-1})" ${page===1?'disabled':''}>‹</button>`;
    const range = [1];
    if (page > 3) range.push('...');
    for (let i=Math.max(2,page-1); i<=Math.min(pages-1,page+1); i++) range.push(i);
    if (page < pages-2) range.push('...');
    if (pages > 1) range.push(pages);
    range.forEach(p => {
        if (p==='...') html += `<span class="page-btn" style="cursor:default;">…</span>`;
        else html += `<button class="page-btn ${p===page?'active':''}" onclick="loadData(${p})">${p}</button>`;
    });
    html += `<button class="page-btn" onclick="loadData(${page+1})" ${page===pages?'disabled':''}>›</button>`;
    btns.innerHTML = html;
}

// ── Filters toggle ────────────────────────────────────────────────────────────
function toggleFilters() {
    const sec = document.getElementById('filters-section');
    const btn = document.getElementById('filterToggleBtn');
    const open = sec.style.display === 'none';
    sec.style.display = open ? 'block' : 'none';
    btn.classList.toggle('active', open);
}

function clearFilters() {
    if (typeof $ !== 'undefined' && $.fn.select2) {
        $('#f_emp').val('').trigger('change');
    } else {
        document.getElementById('f_emp').value = '';
    }
    document.getElementById('f_date_from').value = '';
    document.getElementById('f_date_to').value = '';
    document.getElementById('searchInput').value = '';
    currentStatus = '';
    document.querySelectorAll('.status-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('btn-all').classList.add('active');
    loadData(1);
}

// ── CSV Exports ───────────────────────────────────────────────────────────────
function exportCSV() {
    if (!allRows.length) { alert('No data to export.'); return; }
    const cols = ['Date','Employee ID','Employee Name','Check In','Check Out','Minutes Worked',
                  'Location In','Lat In','Lng In','Location Out','Lat Out','Lng Out',
                  'Device Model','OS','Browser','Screen','Platform','Language','IP Address'];
    const keys = ['att_date','emp_code','employee_full_name','check_in','check_out','minutes_worked',
                  'location_name_in','latitude_in','longitude_in','location_name_out','latitude_out','longitude_out',
                  'device_model','device_os','device_browser','device_screen','device_platform','device_language','ip_address'];
    let csv = cols.join(',') + '\n';
    allRows.forEach(r => {
        csv += keys.map(k => '"'+(r[k]||'').toString().replace(/"/g,'""')+'"').join(',') + '\n';
    });
    dlCSV(csv, 'attendance_records_');
}

function exportSummaryCSV() {
    if (!summaryData) { alert('Load the summary first.'); return; }
    const wdays = summaryData.working_days;
    let csv = 'Employee ID,Employee Name,Designation,Present Days,Absent Days,Working Days,Completed Days,Incomplete Days,Avg Hours/Day,Attendance %\n';
    summaryData.employees.forEach(e => {
        csv += [e.emp_code, e.employee_full_name, e.designation,
                e.present_days, e.absent_days, wdays,
                e.completed_days, e.incomplete_days,
                e.avg_hours || 0, e.attendance_pct]
            .map(v => '"'+(v||'').toString().replace(/"/g,'""')+'"').join(',') + '\n';
    });
    dlCSV(csv, 'attendance_summary_');
}

function dlCSV(csv, prefix) {
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = prefix + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtDate(d) {
    if (!d) return '—';
    return new Date(d+'T00:00:00').toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
}
</script>

<?php include 'footer.php'; ?>