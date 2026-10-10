<?php
include 'config.php';

// Filters
$search      = isset($_GET['search'])  ? mysqli_real_escape_string($conn, trim($_GET['search']))  : '';
$status_filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';
$company_filter = isset($_GET['company_id']) ? intval($_GET['company_id']) : 0;

// Build WHERE
$where = "WHERE 1=1";
if ($search)         $where .= " AND (e.employee_full_name LIKE '%$search%' OR e.employee_id LIKE '%$search%' OR e.id_number LIKE '%$search%' OR e.name_with_initials LIKE '%$search%')";
if ($status_filter)  $where .= " AND e.status = '$status_filter'";
if ($company_filter) $where .= " AND e.company_id = $company_filter";

// Fetch employees with joins
$sql = "SELECT e.id, e.employee_id, e.epf_number, e.name_with_initials, e.employee_full_name,
               e.id_number, e.date_of_birth, e.driving_licence_number,
               e.telephone_home, e.telephone_mobile, e.date_of_join,
               e.epf_etf_assignee_name, e.epf_assignee_contact, e.relationship,
               e.profile_picture, e.application_form, e.id_copy, e.driver_licence_copy,
               e.status, e.address,
               d.designation_name
        FROM employees e
        LEFT JOIN designations d ON e.designation_id = d.id
        $where
        ORDER BY e.employee_id ASC";
$result = mysqli_query($conn, $sql);
$employees = [];
while ($row = mysqli_fetch_assoc($result)) $employees[] = $row;

// For each employee, get their uploaded documents (keyed by employee_id => [doc_type => file_path])
$emp_ids = array_column($employees, 'id');
$doc_map = [];
if (!empty($emp_ids)) {
    $ids_str = implode(',', $emp_ids);
    $doc_sql = "SELECT employee_id, document_type, document_name, file_path, id
                FROM employee_documents
                WHERE employee_id IN ($ids_str)
                ORDER BY uploaded_at DESC";
    $doc_res = mysqli_query($conn, $doc_sql);
    while ($d = mysqli_fetch_assoc($doc_res)) {
        $eid  = $d['employee_id'];
        $type = $d['document_type'];
        // Keep only first (most recent) per type
        if (!isset($doc_map[$eid][$type])) {
            $doc_map[$eid][$type] = $d;
        }
    }
}

// Document columns definition: [key_used_in_db, display_label, short_label]
$doc_columns = [
    ['Job Agreement',           'Agreement',         'AGR'],
    ['Birth Certificate',       'Birth Cert.',       'BC'],
    ['O/L Certificate',         'Edu. Cert.',        'EDU'],
    ['Sch Leaving Certificate', 'School Leaving',    'SCH'],
    ['Other Professional',      'Charters Cert.',    'CHR'],
    ['GS Certificate',          'GS Cert.',          'GS'],
    ['id_copy_file',            'ID Copy',           'ID'],   // from initial upload
    ['driver_licence_copy',     'Driving Lic.',      'DL'],   // from initial upload
];

// Companies for filter
$companies_result = mysqli_query($conn, "SELECT id, company_name, company_code FROM companies WHERE active=1 ORDER BY company_name");

include 'header.php';
?>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title">Employee Register</h2>
            <p class="page-subtitle">Complete employee document status overview</p>
        </div>
        <div style="display:flex;gap:10px;">
            <a href="add_employee.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Employee</a>
            <button onclick="exportToExcel()" class="btn btn-success"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
            <button onclick="window.print()" class="btn btn-secondary"><i class="fa-solid fa-print"></i> Print</button>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="filter-bar">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;width:100%;">
        <div class="filter-group">
            <label class="filter-label">Search</label>
            <div class="search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="search" class="filter-input" placeholder="Name, ID, NIC..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
        </div>
        <div class="filter-group">
            <label class="filter-label">Company</label>
            <select name="company_id" class="filter-input">
                <option value="">All Companies</option>
                <?php while ($c = mysqli_fetch_assoc($companies_result)): ?>
                <option value="<?php echo $c['id']; ?>" <?php echo $company_filter == $c['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($c['company_code'].' - '.$c['company_name']); ?>
                </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">Status</label>
            <select name="status" class="filter-input">
                <option value="">All Status</option>
                <option value="Probation"  <?php echo $status_filter=='Probation'  ? 'selected':''; ?>>Probation</option>
                <option value="Permanent"  <?php echo $status_filter=='Permanent'  ? 'selected':''; ?>>Permanent</option>
                <option value="Resigned"   <?php echo $status_filter=='Resigned'   ? 'selected':''; ?>>Resigned</option>
                <option value="Terminated" <?php echo $status_filter=='Terminated' ? 'selected':''; ?>>Terminated</option>
            </select>
        </div>
        <div class="filter-group" style="margin-top:auto;">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Filter</button>
            <a href="employee_register.php" class="btn btn-secondary" style="margin-left:6px;">Clear</a>
        </div>
        <div class="filter-group" style="margin-top:auto;margin-left:auto;">
            <span class="result-count"><strong><?php echo count($employees); ?></strong> employees found</span>
        </div>
    </form>
</div>

<!-- Legend -->
<div class="legend-bar">
    <span class="legend-item"><span class="doc-tick">✓</span> Document submitted — click to view</span>
    <span class="legend-item"><span class="doc-cross">✗</span> Not submitted</span>
    <span class="legend-item"><span class="status-dot dot-probation"></span> Probation</span>
    <span class="legend-item"><span class="status-dot dot-permanent"></span> Permanent</span>
    <span class="legend-item"><span class="status-dot dot-resigned"></span> Resigned / Terminated</span>
</div>

<!-- Register Table -->
<div class="register-wrap">
<table class="register-table" id="registerTable">
    <thead>
        <tr class="header-row-1">
            <th rowspan="2" class="th-fixed th-no">#</th>
            <th rowspan="2" class="th-fixed th-epf">EPF No.</th>
            <th rowspan="2" class="th-fixed th-code">Emp. Code</th>
            <th rowspan="2" class="th-fixed th-designation">Designation</th>
            <th rowspan="2" class="th-fixed th-join">Join Date</th>
            <th rowspan="2" class="th-fixed th-name">Name with Initials</th>
            <th rowspan="2" class="th-fixed th-nic">NIC No.</th>
            <th rowspan="2" class="th-fixed th-dob">DOB</th>
            <th rowspan="2" class="th-col">Driving<br>Lic. No.</th>
            <th rowspan="2" class="th-col">Tel. Home</th>
            <th rowspan="2" class="th-col">Tel. Mobile</th>
            <th rowspan="2" class="th-col">EPF Assign<br>Details</th>
            <th rowspan="2" class="th-col th-status">Status</th>
            <th colspan="14" class="th-docs-header">Documents</th>
            <th rowspan="2" class="th-col th-actions">Actions</th>
        </tr>
        <tr class="header-row-2">
            <th class="th-doc">Profile<br>Photo</th>
            <th class="th-doc">Application<br>Form</th>
            <th class="th-doc">ID<br>Copy</th>
            <th class="th-doc">Driving<br>Lic.</th>
            <th class="th-doc">Job<br>Description</th>
            <th class="th-doc">Job<br>Agreement</th>
            <th class="th-doc">Code of<br>Conduct</th>
            <th class="th-doc">GS<br>Cert.</th>
            <th class="th-doc">Birth<br>Cert.</th>
            <th class="th-doc">O/L<br>Cert.</th>
            <th class="th-doc">A/L<br>Cert.</th>
            <th class="th-doc">Other<br>Academic</th>
            <th class="th-doc">Other<br>Professional</th>
            <th class="th-doc">Other<br>Docs</th>
        </tr>
    </thead>
    <tbody>
    <?php
    // Helper declared once — outputs a doc tick or cross table cell
    function regDocCell($ok, $url, $label) {
        if ($ok && $url) {
            echo '<td class="td-doc"><a href="'.htmlspecialchars($url).'" target="_blank" class="doc-tick" title="View: '.htmlspecialchars($label).'"><i class="fa-solid fa-circle-check"></i></a></td>';
        } elseif ($ok) {
            echo '<td class="td-doc"><span class="doc-tick" title="'.htmlspecialchars($label).' — Uploaded"><i class="fa-solid fa-circle-check"></i></span></td>';
        } else {
            echo '<td class="td-doc"><span class="doc-cross" title="'.htmlspecialchars($label).' — Not submitted"><i class="fa-solid fa-circle-xmark"></i></span></td>';
        }
    }
    ?>
    <?php if (empty($employees)): ?>
        <tr><td colspan="28" style="text-align:center;padding:40px;color:#999;">No employees found.</td></tr>
    <?php endif; ?>
    <?php foreach ($employees as $i => $emp):
        $eid   = $emp['id'];
        $docs  = $doc_map[$eid] ?? [];

        // EPF assign detail string
        $epf_detail = trim(($emp['epf_etf_assignee_name'] ? $emp['epf_etf_assignee_name'] : '').
                     ($emp['epf_assignee_contact'] ? ' / '.$emp['epf_assignee_contact'] : '').
                     ($emp['relationship'] ? ' ('.$emp['relationship'].')' : ''));

        // Status class
        $st_class = ['Probation'=>'badge-warning','Permanent'=>'badge-success','Resigned'=>'badge-inactive','Terminated'=>'badge-danger'][$emp['status']] ?? 'badge-inactive';

        // Doc checks
        $doc_keys = [
            'Job Agreement',
            'Birth Certificate',
            'Sch Leaving Certificate',
            'O/L Certificate',
            'Other Professional',
            'GS Certificate',
        ];
    ?>
    <tr class="emp-row <?php echo strtolower($emp['status']); ?>-row" data-id="<?php echo $eid; ?>">
        <td class="td-center td-muted"><?php echo $i + 1; ?></td>
        <td class="td-center"><span class="epf-no"><?php echo $emp['epf_number'] ? htmlspecialchars($emp['epf_number']) : '—'; ?></span></td>
        <td><a href="view_employee.php?id=<?php echo $eid; ?>" class="emp-id-link"><?php echo htmlspecialchars($emp['employee_id']); ?></a></td>
        <td><span class="desig-text"><?php echo htmlspecialchars($emp['designation_name'] ?? '—'); ?></span></td>
        <td class="td-center td-date"><?php echo $emp['date_of_join'] ? date('d M Y', strtotime($emp['date_of_join'])) : '—'; ?></td>
        <td>
            <div class="emp-name-cell">
                <?php if (!empty($emp['profile_picture']) && file_exists($emp['profile_picture'])): ?>
                <img src="<?php echo $emp['profile_picture']; ?>" class="emp-avatar" alt="">
                <?php else: ?>
                <div class="emp-avatar-placeholder"><i class="fa-solid fa-user"></i></div>
                <?php endif; ?>
                <div>
                    <div class="emp-name"><?php echo htmlspecialchars($emp['name_with_initials'] ?: $emp['employee_full_name']); ?></div>
                    <div class="emp-full"><?php echo htmlspecialchars($emp['employee_full_name']); ?></div>
                </div>
            </div>
        </td>
        <td class="td-center mono"><?php echo htmlspecialchars($emp['id_number']); ?></td>
        <td class="td-center td-date"><?php echo $emp['date_of_birth'] ? date('d M Y', strtotime($emp['date_of_birth'])) : '—'; ?></td>
        <td class="td-center mono"><?php echo $emp['driving_licence_number'] ? htmlspecialchars($emp['driving_licence_number']) : '—'; ?></td>
        <td class="td-center"><?php echo $emp['telephone_home'] ? htmlspecialchars($emp['telephone_home']) : '—'; ?></td>
        <td class="td-center"><strong><?php echo htmlspecialchars($emp['telephone_mobile']); ?></strong></td>
        <td class="td-epf-detail"><?php echo $epf_detail ? htmlspecialchars($epf_detail) : '—'; ?></td>
        <td class="td-center"><span class="badge <?php echo $st_class; ?>"><?php echo $emp['status']; ?></span></td>

        <?php
        // Profile Photo
        $pp_ok  = !empty($emp['profile_picture']) && @file_exists($emp['profile_picture']);
        $pp_url = $pp_ok ? $emp['profile_picture'] : null;
        if ($pp_ok && $pp_url) {
            echo '<td class="td-doc"><a href="'.htmlspecialchars($pp_url).'" target="_blank" class="doc-tick" title="View: Profile Photo"><img src="'.htmlspecialchars($pp_url).'" style="width:28px;height:28px;border-radius:50%;object-fit:cover;border:2px solid #16a34a;"></a></td>';
        } else {
            echo '<td class="td-doc"><span class="doc-cross" title="Profile Photo — Not uploaded"><i class="fa-solid fa-circle-xmark"></i></span></td>';
        }

        // Application Form — employees.application_form field
        $af_ok  = !empty($emp['application_form']) && @file_exists($emp['application_form']);
        regDocCell($af_ok, $emp['application_form'] ?? null, 'Application Form');

        // ID Copy — employees.id_copy field
        $ic_ok  = !empty($emp['id_copy']) && @file_exists($emp['id_copy']);
        regDocCell($ic_ok, $emp['id_copy'] ?? null, 'ID Copy');

        // Driving Licence Copy — employees.driver_licence_copy field
        $dl_ok  = !empty($emp['driver_licence_copy']) && @file_exists($emp['driver_licence_copy']);
        regDocCell($dl_ok, $emp['driver_licence_copy'] ?? null, 'Driving Licence Copy');

        // Documents from employee_documents table
        $reg_doc_types = [
            'Job Description',
            'Job Agreement',
            'Code of Conduct',
            'GS Certificate',
            'Birth Certificate',
            'O/L Certificate',
            'A/L Certificate',
            'Other Academic',
            'Other Professional',
        ];
        foreach ($reg_doc_types as $rdt) {
            $d = $docs[$rdt] ?? null;
            regDocCell($d !== null, $d ? $d['file_path'] : null, $rdt);
        }

        // Other docs — any uploaded doc that is NOT in the fixed list
        $fixed_reg = ['GS Certificate','Birth Certificate','O/L Certificate','A/L Certificate',
                      'Other Academic','Other Professional','Job Agreement','Sch Leaving Certificate',
                      'Job Description','Code of Conduct'];
        $other_reg = array_filter($docs, fn($d) => !in_array($d['document_type'], $fixed_reg));
        if (count($other_reg) > 0) {
            $first_other = reset($other_reg);
            $other_count = count($other_reg);
            $title = implode(', ', array_map(fn($d) => $d['document_type'].($d['document_name'] ? ' ('.$d['document_name'].')':''), $other_reg));
            echo '<td class="td-doc"><a href="'.htmlspecialchars($first_other['file_path']).'" target="_blank" class="doc-tick" title="'.htmlspecialchars($title).'">';
            if ($other_count > 1) echo '<span style="font-size:9px;font-weight:700;line-height:1;">'.$other_count.'</span>';
            else echo '<i class="fa-solid fa-circle-check"></i>';
            echo '</a></td>';
        } else {
            echo '<td class="td-doc"><span class="doc-cross" title="No other documents submitted"><i class="fa-solid fa-circle-xmark"></i></span></td>';
        }
        ?>

        <!-- Actions -->
        <td class="td-actions">
            <a href="view_employee.php?id=<?php echo $eid; ?>" class="btn-action btn-view" title="View"><i class="fa-solid fa-eye"></i></a>
            <a href="edit_employee.php?id=<?php echo $eid; ?>" class="btn-action btn-edit" title="Edit"><i class="fa-solid fa-pen"></i></a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- Summary Footer -->
<div class="summary-bar">
    <?php
    $total     = count($employees);
    $probation = count(array_filter($employees, fn($e) => $e['status'] === 'Probation'));
    $permanent = count(array_filter($employees, fn($e) => $e['status'] === 'Permanent'));
    $resigned  = count(array_filter($employees, fn($e) => in_array($e['status'], ['Resigned','Terminated'])));
    ?>
    <div class="summary-item"><span class="summary-num"><?php echo $total; ?></span><span class="summary-label">Total</span></div>
    <div class="summary-divider"></div>
    <div class="summary-item"><span class="summary-num warning"><?php echo $probation; ?></span><span class="summary-label">Probation</span></div>
    <div class="summary-divider"></div>
    <div class="summary-item"><span class="summary-num success"><?php echo $permanent; ?></span><span class="summary-label">Permanent</span></div>
    <div class="summary-divider"></div>
    <div class="summary-item"><span class="summary-num muted"><?php echo $resigned; ?></span><span class="summary-label">Resigned / Terminated</span></div>
</div>

<style>
/* ── Page ── */
* { box-sizing: border-box; }
.page-header { margin-bottom: 20px; }
.page-title  { font-size: 22px; font-weight: 700; margin: 0 0 4px; color: #111; }
.page-subtitle { font-size: 13px; color: #666; margin: 0; }

/* ── Filters ── */
.filter-bar { background: #fff; border: 1px solid #e5e5e5; border-radius: 10px; padding: 16px 20px; margin-bottom: 16px; display: flex; }
.filter-group { display: flex; flex-direction: column; gap: 6px; }
.filter-label { font-size: 11px; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: .4px; }
.filter-input { padding: 9px 14px; border: 1px solid #e5e5e5; border-radius: 7px; font-size: 13px; font-family: 'Inter', sans-serif; min-width: 160px; }
.filter-input:focus { outline: none; border-color: #000; }
.search-wrap { position: relative; }
.search-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #aaa; font-size: 13px; }
.search-wrap .filter-input { padding-left: 34px; }
.result-count { font-size: 13px; color: #555; background: #f5f5f5; padding: 8px 14px; border-radius: 7px; white-space: nowrap; }

/* ── Legend ── */
.legend-bar { display: flex; gap: 20px; align-items: center; padding: 10px 16px; background: #fafafa; border: 1px solid #e5e5e5; border-radius: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.legend-item { display: flex; align-items: center; gap: 7px; font-size: 12px; color: #555; }
.status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
.dot-probation { background: #f59e0b; }
.dot-permanent { background: #22c55e; }
.dot-resigned  { background: #9ca3af; }

/* ── Register Table Wrapper ── */
.register-wrap { width: 100%; overflow-x: auto; border: 1px solid #e5e5e5; border-radius: 12px; background: #fff; }

/* ── Register Table ── */
.register-table { width: 100%; border-collapse: collapse; font-size: 12px; font-family: 'Inter', sans-serif; min-width: 1400px; }

/* Header */
.register-table thead th { background: #111; color: #fff; padding: 11px 10px; text-align: center; font-size: 11px; font-weight: 600; letter-spacing: .3px; border-right: 1px solid #333; white-space: nowrap; vertical-align: middle; }
.register-table thead th:last-child { border-right: none; }
.th-docs-header { background: #1d4ed8 !important; font-size: 11px; letter-spacing: .5px; text-transform: uppercase; }
.header-row-2 th { background: #2563eb !important; font-size: 10px; border-top: 1px solid #1d4ed8; }
.th-fixed { min-width: 80px; }
.th-name  { min-width: 160px; }
.th-designation { min-width: 130px; }
.th-col   { min-width: 90px; }
.th-doc   { min-width: 70px; width: 70px; background: #1e40af !important; }
.th-actions { min-width: 70px; }
.th-status { min-width: 90px; }

/* Body */
.register-table tbody tr { border-bottom: 1px solid #f0f0f0; transition: background .15s; }
.register-table tbody tr:hover { background: #f8faff; }
.register-table tbody tr:last-child { border-bottom: none; }
.register-table td { padding: 10px 10px; vertical-align: middle; border-right: 1px solid #f0f0f0; color: #333; }
.register-table td:last-child { border-right: none; }

/* Row tinting by status */
.probation-row   { border-left: 3px solid #f59e0b; }
.permanent-row   { border-left: 3px solid #22c55e; }
.resigned-row,
.terminated-row  { border-left: 3px solid #d1d5db; opacity: .8; }

/* Specific cell styles */
.td-center { text-align: center; }
.td-muted  { color: #aaa; font-size: 11px; }
.td-date   { white-space: nowrap; font-size: 11px; color: #555; }
.td-doc    { text-align: center; padding: 8px 4px; }
.td-actions { text-align: center; white-space: nowrap; }
.td-epf-detail { font-size: 11px; color: #555; max-width: 120px; }
.mono { font-family: 'Courier New', monospace; font-size: 11px; letter-spacing: .5px; }
.epf-no { font-family: 'Courier New', monospace; font-size: 11px; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }

/* Employee name cell */
.emp-name-cell { display: flex; align-items: center; gap: 8px; }
.emp-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid #e5e5e5; flex-shrink: 0; }
.emp-avatar-placeholder { width: 30px; height: 30px; border-radius: 50%; background: #f3f4f6; display: flex; align-items: center; justify-content: center; flex-shrink: 0; border: 1px solid #e5e5e5; }
.emp-avatar-placeholder i { font-size: 12px; color: #9ca3af; }
.emp-name { font-weight: 600; font-size: 12px; color: #111; line-height: 1.3; }
.emp-full { font-size: 10px; color: #888; line-height: 1.2; }
.emp-id-link { font-weight: 700; color: #1d4ed8; text-decoration: none; font-family: 'Courier New', monospace; font-size: 11px; }
.emp-id-link:hover { text-decoration: underline; }
.desig-text { font-size: 11px; color: #444; }

/* ── Document tick / cross ── */
.doc-tick {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #dcfce7;
    color: #16a34a;
    font-size: 18px;
    text-decoration: none;
    transition: all .2s;
    cursor: pointer;
    border: 2px solid #bbf7d0;
}
.doc-tick:hover {
    background: #16a34a;
    color: #fff;
    border-color: #16a34a;
    transform: scale(1.12);
    box-shadow: 0 2px 8px rgba(22,163,74,.3);
}
.doc-cross {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #fef2f2;
    color: #dc2626;
    font-size: 18px;
    border: 2px solid #fecaca;
    cursor: default;
}

/* ── Badges ── */
.badge { display: inline-block; padding: 3px 9px; border-radius: 12px; font-size: 10px; font-weight: 700; white-space: nowrap; }
.badge-success  { background: #dcfce7; color: #166534; }
.badge-warning  { background: #fef3c7; color: #92400e; }
.badge-inactive { background: #f3f4f6; color: #6b7280; }
.badge-danger   { background: #fee2e2; color: #991b1b; }

/* ── Action buttons ── */
.btn-action { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 6px; border: 1px solid #e5e7eb; background: #fff; color: #6b7280; cursor: pointer; transition: all .2s; text-decoration: none; font-size: 12px; }
.btn-view:hover { background: #3b82f6; color: #fff; border-color: #3b82f6; }
.btn-edit:hover { background: #000; color: #fff; border-color: #000; }

/* ── Summary bar ── */
.summary-bar { display: flex; align-items: center; gap: 20px; padding: 14px 20px; background: #fff; border: 1px solid #e5e5e5; border-radius: 10px; margin-top: 16px; flex-wrap: wrap; }
.summary-item { display: flex; flex-direction: column; align-items: center; gap: 2px; }
.summary-num  { font-size: 22px; font-weight: 800; color: #111; line-height: 1; }
.summary-num.warning { color: #f59e0b; }
.summary-num.success { color: #22c55e; }
.summary-num.muted   { color: #9ca3af; }
.summary-label { font-size: 11px; color: #888; font-weight: 500; }
.summary-divider { width: 1px; height: 36px; background: #e5e5e5; }

/* ── Top buttons ── */
.btn { display: inline-flex; align-items: center; gap: 7px; padding: 10px 18px; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all .2s; text-decoration: none; font-family: 'Inter', sans-serif; }
.btn-primary   { background: #000; color: #fff; } .btn-primary:hover { background: #333; }
.btn-secondary { background: #f5f5f5; color: #333; border: 1px solid #e5e5e5; } .btn-secondary:hover { background: #e5e5e5; }
.btn-success   { background: #16a34a; color: #fff; border: 1px solid #15803d; } .btn-success:hover { background: #15803d; }

/* ── Print ── */
@media print {
    .filter-bar, .legend-bar, .btn, .page-header div:last-child, .summary-bar, .btn-action { display: none !important; }
    .register-wrap { border: none; overflow: visible; }
    .register-table { font-size: 9px; min-width: unset; width: 100%; }
    .register-table thead th { background: #333 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .th-docs-header, .header-row-2 th, .th-doc { background: #1d4ed8 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .doc-tick { background: #dcfce7 !important; color: #16a34a !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .doc-cross { background: #fef2f2 !important; color: #dc2626 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    @page { size: A3 landscape; margin: 8mm; }
}

/* ── Responsive ── */
@media (max-width: 768px) {
    .filter-bar form { flex-direction: column; }
    .filter-group { width: 100%; }
    .filter-input { width: 100%; }
}
</style>

<script>
function exportToExcel() {
    // Collect headers
    const table = document.getElementById('registerTable');
    if (!table) return;

    // Build header rows (2 rows merged)
    let csv = [];

    // Get all header cells from both header rows
    const headerRow1 = table.querySelectorAll('thead tr.header-row-1 th');
    const headerRow2 = table.querySelectorAll('thead tr.header-row-2 th');

    // Build a flat single header row for Excel
    let headers = [];
    headerRow1.forEach(th => {
        let txt = th.innerText.replace(/
/g, ' ').trim();
        let rowspan = parseInt(th.getAttribute('rowspan') || 1);
        let colspan = parseInt(th.getAttribute('colspan') || 1);
        if (rowspan === 2) {
            headers.push(txt);
        }
        // colspan cells will be filled by header-row-2
    });
    // Insert doc sub-headers at correct position
    let finalHeaders = [];
    let docInserted = false;
    headerRow1.forEach(th => {
        let txt = th.innerText.replace(/
/g, ' ').trim();
        let rowspan = parseInt(th.getAttribute('rowspan') || 1);
        if (rowspan === 2) {
            finalHeaders.push(txt);
        } else {
            // This is the Documents group — insert sub-headers
            headerRow2.forEach(th2 => {
                finalHeaders.push(th2.innerText.replace(/
/g, ' ').trim());
            });
        }
    });
    csv.push(finalHeaders.map(h => '"' + h.replace(/"/g, '""') + '"').join(','));

    // Data rows
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        let rowData = [];
        row.querySelectorAll('td').forEach(td => {
            // For doc cells: get tick/cross text from icon or link
            let val = '';
            const tick = td.querySelector('.doc-tick');
            const cross = td.querySelector('.doc-cross');
            const badge = td.querySelector('.badge');
            const link = td.querySelector('a.emp-id-link');
            const img = td.querySelector('img.emp-avatar');

            if (cross) {
                val = 'Not Submitted';
            } else if (tick) {
                // Check if it's a profile photo cell (has img inside tick)
                val = tick.querySelector('img') ? 'Uploaded' : 'Submitted';
            } else if (badge) {
                val = badge.innerText.trim();
            } else if (link) {
                val = link.innerText.trim();
            } else if (img && td.querySelector('.emp-name')) {
                // Name cell - get just the name text
                const nameDiv = td.querySelector('.emp-name');
                val = nameDiv ? nameDiv.innerText.trim() : td.innerText.trim();
            } else {
                val = td.innerText.replace(/
+/g, ' ').trim();
            }
            rowData.push('"' + val.replace(/"/g, '""') + '"');
        });
        if (rowData.length > 1) csv.push(rowData.join(','));
    });

    // Create and trigger download
    const csvContent = '﻿' + csv.join('
'); // BOM for Excel UTF-8
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'Employee_Register_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>

<?php include 'footer.php'; ?>