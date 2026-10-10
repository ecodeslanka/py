<?php
include 'config.php';

// Create employees table if not exists
$createEmployeesTable = "CREATE TABLE IF NOT EXISTS employees (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(50) NOT NULL UNIQUE,
    company_id INT(11) NULL,
    branch_id INT(11) NULL,
    employee_full_name VARCHAR(255) NOT NULL,
    name_with_initials VARCHAR(255) NULL,
    designation_id INT(11) NULL,
    staff_category_id INT(11) NULL,
    tr_code VARCHAR(50) NULL,
    date_of_birth DATE NULL,
    date_of_join DATE NULL,
    id_number VARCHAR(20) NULL,
    gender ENUM('male', 'female') NULL,
    driving_licence_number VARCHAR(50) NULL,
    blood_group VARCHAR(10) NULL,
    telephone_home VARCHAR(20) NULL,
    telephone_mobile VARCHAR(20) NOT NULL,
    telephone_office VARCHAR(20) NULL,
    whatsapp_number VARCHAR(20) NULL,
    email_address VARCHAR(255) NULL,
    epf_etf_assignee_name VARCHAR(255) NULL,
    relationship VARCHAR(100) NULL,
    nic_number VARCHAR(20) NULL,
    bank_name VARCHAR(255) NULL,
    bank_branch VARCHAR(255) NULL,
    account_number VARCHAR(50) NULL,
    application_form VARCHAR(255) NULL,
    id_copy VARCHAR(255) NULL,
    driver_licence_copy VARCHAR(255) NULL,
    status ENUM('Probation', 'Permanent', 'Resigned', 'Terminated') DEFAULT 'Probation',
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (designation_id) REFERENCES designations(id) ON DELETE SET NULL,
    INDEX idx_employee_id (employee_id)
)";
mysqli_query($conn, $createEmployeesTable);

// Create staff_categories table if not exists
$createStaffCategoriesTable = "CREATE TABLE IF NOT EXISTS staff_categories (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(255) NOT NULL,
    category_code VARCHAR(50) NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
mysqli_query($conn, $createStaffCategoriesTable);

// Handle filters
$filter_company        = isset($_GET['filter_company'])        ? intval($_GET['filter_company'])                                  : '';
$filter_branch         = isset($_GET['filter_branch'])         ? intval($_GET['filter_branch'])                                   : '';
$filter_status         = isset($_GET['filter_status'])         ? mysqli_real_escape_string($conn, $_GET['filter_status'])         : '';
$filter_designation    = isset($_GET['filter_designation'])    ? intval($_GET['filter_designation'])                              : '';
$filter_staff_category = isset($_GET['filter_staff_category']) ? intval($_GET['filter_staff_category'])                          : '';
$filter_date_from      = isset($_GET['filter_date_from'])      ? mysqli_real_escape_string($conn, $_GET['filter_date_from'])      : '';
$filter_date_to        = isset($_GET['filter_date_to'])        ? mysqli_real_escape_string($conn, $_GET['filter_date_to'])        : '';

$where_clauses = [];
if ($filter_company)        $where_clauses[] = "e.company_id = $filter_company";
if ($filter_branch)         $where_clauses[] = "e.branch_id = $filter_branch";
if ($filter_status)         $where_clauses[] = "e.status = '$filter_status'";
if ($filter_designation)    $where_clauses[] = "e.designation_id = $filter_designation";
if ($filter_staff_category) $where_clauses[] = "e.staff_category_id = $filter_staff_category";

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Get all employees with filters
$employees_sql = "SELECT e.*, c.company_name, c.company_code, b.branch_name, d.designation_name,
                         sc.category_name AS staff_category_name, sc.category_code AS staff_category_code
                  FROM employees e
                  LEFT JOIN companies c ON e.company_id = c.id
                  LEFT JOIN branches b ON e.branch_id = b.id
                  LEFT JOIN designations d ON e.designation_id = d.id
                  LEFT JOIN staff_categories sc ON e.staff_category_id = sc.id
                  $where_sql
                  ORDER BY e.created_at DESC";
$employees_result = mysqli_query($conn, $employees_sql);

// Collect all employees + compute EPF qualification (join date >= 3 months ago)
$all_employees   = [];
$epf_count       = 0;
$epf_added_count = 0;
$resigned_count  = 0;
$active_count    = 0; // excludes resigned
$today           = new DateTime();

while ($emp = mysqli_fetch_assoc($employees_result)) {
    $epf_qualified = false;
    $epf_added     = !empty($emp['epf_number']);
    $is_resigned   = ($emp['status'] === 'Resigned');

    if (!empty($emp['date_of_join'])) {
        $doj            = new DateTime($emp['date_of_join']);
        $diff           = $today->diff($doj);
        $months_elapsed = ($diff->y * 12) + $diff->m;
        if ($months_elapsed >= 3) {
            $epf_qualified = true;
            if (!$is_resigned) {
                if ($epf_added) {
                    $epf_added_count++;
                } else {
                    $epf_count++;
                }
            }
        }
    }

    if ($is_resigned) {
        $resigned_count++;
    } else {
        $active_count++;
    }

    $emp['epf_qualified'] = $epf_qualified;
    $emp['epf_added']     = $epf_added;
    $all_employees[]      = $emp;
}

// Get companies for filter
$companies_sql    = "SELECT id, company_code, company_name FROM companies WHERE active = 1 ORDER BY company_name";
$companies_result = mysqli_query($conn, $companies_sql);

// Get designations for filter
$designations_sql    = "SELECT id, designation_name FROM designations ORDER BY designation_name";
$designations_result = mysqli_query($conn, $designations_sql);

// Get staff categories for filter
$staff_categories_sql    = "SELECT id, category_code, category_name FROM staff_categories WHERE active = 1 ORDER BY category_name";
$staff_categories_result = mysqli_query($conn, $staff_categories_sql);

include 'header.php';
?>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- Page Header                                                  -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title">Employee Management</h2>
            <p class="page-subtitle">Manage all employees across companies and branches</p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <button onclick="exportToExcel()" class="btn btn-excel" id="exportBtn" title="Export current view to Excel">
                <i class="fa-solid fa-file-excel"></i>
                <span>Export to Excel</span>
                <span class="export-count-badge" id="exportCountBadge">0</span>
            </button>
            <a href="add_employee.php" class="btn btn-primary">
                <i class="fa-solid fa-plus"></i> Add New Employee
            </a>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- Search + Table Layout                                        -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="emp-layout" id="empLayout">

    <!-- ── LEFT: Table panel ── -->
    <div class="emp-table-panel" id="empTablePanel">

        <!-- Search bar (above table) -->
        <div class="search-bar-wrap">
            <div class="search-input-wrap">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input
                    type="text"
                    id="empSearchInput"
                    class="search-input"
                    placeholder="Search by name, ID, mobile, NIC…"
                    autocomplete="off"
                    oninput="handleSearch(this.value)"
                >
                <button class="search-clear" id="searchClear" onclick="clearSearch()" style="display:none;" title="Clear">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <span class="search-count" id="searchCount"><?php echo $active_count; ?> employees</span>
        </div>

        <!-- ── TABS ── -->
        <div class="emp-tabs" id="empTabs">
            <button class="emp-tab active" id="tabAll" onclick="switchTab('all')">
                <i class="fa-solid fa-users"></i>
                All Employees
                <span class="tab-count" id="tabAllCount"><?php echo $active_count; ?></span>
            </button>
            <button class="emp-tab epf-tab" id="tabEpf" onclick="switchTab('epf')">
                <i class="fa-solid fa-triangle-exclamation"></i>
                EPF Needed
                <?php if ($epf_count > 0): ?>
                <span class="tab-badge-red" id="tabEpfCount"><?php echo $epf_count; ?></span>
                <?php else: ?>
                <span class="tab-count" id="tabEpfCount">0</span>
                <?php endif; ?>
            </button>
            <button class="emp-tab epf-added-tab" id="tabEpfAdded" onclick="switchTab('epf_added')">
                <i class="fa-solid fa-circle-check"></i>
                EPF Added
                <span class="tab-count tab-count-green" id="tabEpfAddedCount"><?php echo $epf_added_count; ?></span>
            </button>
            <button class="emp-tab resigned-tab" id="tabResigned" onclick="switchTab('resigned')">
                <i class="fa-solid fa-user-minus"></i>
                Resigned
                <span class="tab-count tab-count-resigned" id="tabResignedCount"><?php echo $resigned_count; ?></span>
            </button>
        </div>

        <!-- Filters Toggle -->
        <div style="margin-bottom:16px;">
            <button onclick="toggleFilters()" class="btn-filter-toggle" id="filterToggleBtn">
                <i class="fa-solid fa-filter"></i>
                Filters
                <?php
                $active_filters = array_filter([$filter_company, $filter_branch, $filter_status, $filter_designation, $filter_staff_category, $filter_date_from, $filter_date_to]);
                if (count($active_filters) > 0): ?>
                    <span class="filter-badge"><?php echo count($active_filters); ?></span>
                <?php endif; ?>
                <i class="fa-solid fa-chevron-down" id="filter-icon"></i>
            </button>
        </div>

        <!-- Filters Panel -->
        <div class="content-card filters-section" id="filters-section" style="display:none;">
            <form method="GET" action="" class="filter-form">
                <div class="filter-grid">
                    <!-- Company -->
                    <div class="filter-group">
                        <label class="filter-label">COMPANY</label>
                        <select name="filter_company" class="filter-input" onchange="loadFilterBranches(this.value)">
                            <option value="">All Companies</option>
                            <?php mysqli_data_seek($companies_result, 0); while ($company = mysqli_fetch_assoc($companies_result)): ?>
                            <option value="<?php echo $company['id']; ?>" <?php echo $filter_company == $company['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($company['company_code'] . ' - ' . $company['company_name']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <!-- Branch -->
                    <div class="filter-group">
                        <label class="filter-label">BRANCH</label>
                        <select name="filter_branch" id="filter_branch" class="filter-input">
                            <option value="">All Branches</option>
                        </select>
                    </div>

                    <!-- Designation -->
                    <div class="filter-group">
                        <label class="filter-label">DESIGNATION</label>
                        <select name="filter_designation" class="filter-input">
                            <option value="">All Designations</option>
                            <?php while ($designation = mysqli_fetch_assoc($designations_result)): ?>
                            <option value="<?php echo $designation['id']; ?>" <?php echo $filter_designation == $designation['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($designation['designation_name']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <!-- Staff Category -->
                    <div class="filter-group">
                        <label class="filter-label">STAFF CATEGORY</label>
                        <select name="filter_staff_category" class="filter-input">
                            <option value="">All Categories</option>
                            <?php while ($cat = mysqli_fetch_assoc($staff_categories_result)): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $filter_staff_category == $cat['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(($cat['category_code'] ? $cat['category_code'] . ' - ' : '') . $cat['category_name']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <!-- Status -->
                    <div class="filter-group">
                        <label class="filter-label">STATUS</label>
                        <select name="filter_status" class="filter-input">
                            <option value="">All Status</option>
                            <option value="Probation"  <?php echo $filter_status=='Probation'  ? 'selected':''; ?>>Probation</option>
                            <option value="Permanent"  <?php echo $filter_status=='Permanent'  ? 'selected':''; ?>>Permanent</option>
                            <option value="Resigned"   <?php echo $filter_status=='Resigned'   ? 'selected':''; ?>>Resigned</option>
                            <option value="Terminated" <?php echo $filter_status=='Terminated' ? 'selected':''; ?>>Terminated</option>
                        </select>
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-dark"><i class="fa-solid fa-search"></i> Apply Filters</button>
                    <a href="employees.php" class="btn btn-light"><i class="fa-solid fa-xmark"></i> Clear Filters</a>
                </div>
            </form>
        </div>

        <!-- Table -->
        <div class="content-card">
            <div class="card-title-bar">
                <h3 class="card-title" id="tableTitle">All Employees (<?php echo $active_count; ?>)</h3>
                <!-- EPF Needed legend shown only on EPF tab -->
                <div class="epf-legend" id="epfLegend" style="display:none;">
                    <span class="epf-legend-dot"></span>
                    <span>Joined 3+ months ago — EPF/ETF registration required</span>
                </div>
                <!-- EPF Added legend -->
                <div class="epf-added-legend" id="epfAddedLegend" style="display:none;">
                    <span class="epf-added-legend-dot"></span>
                    <span>EPF number has been registered</span>
                </div>
                <!-- Resigned legend -->
                <div class="resigned-legend" id="resignedLegend" style="display:none;">
                    <span class="resigned-legend-dot"></span>
                    <span>Employees who have resigned — excluded from active headcount</span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="data-table" id="empTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Employee ID</th>
                            <th>Full Name</th>
                            <th>Company</th>
                            <th>Branch</th>
                            <th>Designation</th>
                            <th>Staff Category</th>
                            <th>Mobile</th>
                            <th>Date of Join</th>
                            <th>Status</th>
                            <th>EPF</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="empTableBody">
                        <?php foreach ($all_employees as $employee):
                            $status_class = 'badge-secondary';
                            switch($employee['status']) {
                                case 'Probation':  $status_class = 'badge-warning';  break;
                                case 'Permanent':  $status_class = 'badge-success';  break;
                                case 'Resigned':   $status_class = 'badge-inactive'; break;
                                case 'Terminated': $status_class = 'badge-danger';   break;
                            }
                            $is_resigned  = ($employee['status'] === 'Resigned') ? 'true' : 'false';
                            $is_epf       = $employee['epf_qualified'] ? 'true' : 'false';
                            $is_epf_added = $employee['epf_added']     ? 'true' : 'false';
                            $doj_display  = '';
                            if (!empty($employee['date_of_join'])) {
                                $doj_display = date('M j, Y', strtotime($employee['date_of_join']));
                            }
                            $row_extra_class = '';
                            if ($employee['status'] === 'Resigned')      $row_extra_class = 'resigned-row';
                            elseif ($employee['epf_added'])              $row_extra_class = 'epf-added-row';
                            elseif ($employee['epf_qualified'])          $row_extra_class = 'epf-row';
                        ?>
                        <tr class="emp-row <?php echo $row_extra_class; ?>"
                            data-id="<?php echo $employee['id']; ?>"
                            data-epf="<?php echo $is_epf; ?>"
                            data-epf-added="<?php echo $is_epf_added; ?>"
                            data-resigned="<?php echo $is_resigned; ?>"
                            data-search="<?php echo strtolower(htmlspecialchars(
                                $employee['id'].' '.
                                $employee['employee_id'].' '.
                                $employee['employee_full_name'].' '.
                                ($employee['name_with_initials'] ?? '').' '.
                                $employee['telephone_mobile'].' '.
                                ($employee['id_number'] ?? '').' '.
                                ($employee['email_address'] ?? '').' '.
                                ($employee['company_name'] ?? '').' '.
                                ($employee['branch_name'] ?? '').' '.
                                ($employee['designation_name'] ?? '').' '.
                                ($employee['staff_category_name'] ?? '').' '.
                                ($employee['epf_number'] ?? '')
                            )); ?>"
                            onclick="showEmployeePanel(<?php echo $employee['id']; ?>)"
                            style="cursor:pointer;">
                            <td><?php echo htmlspecialchars($employee['id']); ?></td>
                            <td><strong><?php echo htmlspecialchars($employee['employee_id']); ?></strong></td>
                            <td>
                                <?php echo htmlspecialchars($employee['employee_full_name']); ?>
                                <?php if ($employee['status'] === 'Resigned'): ?>
                                <span class="resigned-dot-inline" title="Resigned"></span>
                                <?php elseif ($employee['epf_added']): ?>
                                <span class="epf-dot-inline epf-dot-green" title="EPF Registered"></span>
                                <?php elseif ($employee['epf_qualified']): ?>
                                <span class="epf-dot-inline" title="EPF Registration Needed"></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $employee['company_name'] ? '<small>'.htmlspecialchars($employee['company_code']).'</small>' : '<span style="color:#999">-</span>'; ?></td>
                            <td><?php echo $employee['branch_name'] ? '<small>'.htmlspecialchars($employee['branch_name']).'</small>' : '<span style="color:#999">-</span>'; ?></td>
                            <td><?php echo $employee['designation_name'] ? '<span class="badge badge-designation">'.htmlspecialchars($employee['designation_name']).'</span>' : '<span style="color:#999">-</span>'; ?></td>
                            <td>
                                <?php if ($employee['staff_category_name']): ?>
                                <span class="badge badge-category"><?php echo htmlspecialchars($employee['staff_category_name']); ?></span>
                                <?php else: ?>
                                <span style="color:#999">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($employee['telephone_mobile']); ?></td>
                            <td>
                                <?php if ($doj_display): ?>
                                <span class="doj-cell <?php echo $employee['epf_qualified'] && !$employee['epf_added'] && $employee['status'] !== 'Resigned' ? 'doj-epf' : ($employee['epf_added'] ? 'doj-epf-added' : ''); ?>">
                                    <?php echo $doj_display; ?>
                                </span>
                                <?php else: ?>
                                <span style="color:#999">-</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?php echo $status_class; ?>"><?php echo $employee['status']; ?></span></td>
                            <td>
                                <?php if ($employee['status'] === 'Resigned'): ?>
                                <span class="badge badge-resigned-epf"><i class="fa-solid fa-user-minus"></i> Resigned</span>
                                <?php elseif ($employee['epf_added']): ?>
                                <div class="epf-cell-added">
                                    <span class="badge badge-epf-added"><i class="fa-solid fa-circle-check"></i> Added</span>
                                    <span class="epf-number-display"><?php echo htmlspecialchars($employee['epf_number']); ?></span>
                                </div>
                                <?php elseif ($employee['epf_qualified']): ?>
                                <span class="badge badge-epf-needed"><i class="fa-solid fa-triangle-exclamation"></i> Needed</span>
                                <?php else: ?>
                                <span class="badge badge-epf-pending">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons" onclick="event.stopPropagation()">
                                    <a href="view_employee.php?id=<?php echo $employee['id']; ?>" class="btn-action btn-view" title="View"><i class="fa-solid fa-eye"></i></a>
                                    <a href="edit_employee.php?id=<?php echo $employee['id']; ?>" class="btn-action btn-edit" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <a href="delete_employee.php?id=<?php echo $employee['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Delete this employee?')"><i class="fa-solid fa-trash"></i></a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p id="noResultsMsg" style="display:none;text-align:center;padding:30px;color:#999;font-size:13px;">No employees match your search.</p>
            </div>
        </div>
    </div>

    <!-- ── RIGHT: Detail panel ── -->
    <div class="emp-detail-panel" id="empDetailPanel">
        <div class="detail-empty" id="detailEmpty">
            <div class="detail-empty-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
            <p>Search or click an employee<br>to see their details here</p>
        </div>
        <div class="detail-content" id="detailContent" style="display:none;"></div>
    </div>

</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- SheetJS for Excel export                                     -->
<!-- ═══════════════════════════════════════════════════════════ -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- Employee data for JS                                         -->
<!-- ═══════════════════════════════════════════════════════════ -->
<script>
const EMP_DATA = <?php
$js_data = [];
foreach ($all_employees as $e) {
    $months_elapsed = 0;
    if (!empty($e['date_of_join'])) {
        $doj  = new DateTime($e['date_of_join']);
        $diff = $today->diff($doj);
        $months_elapsed = ($diff->y * 12) + $diff->m;
    }
    $js_data[] = [
        'id'                  => $e['id'],
        'employee_id'         => $e['employee_id'],
        'full_name'           => $e['employee_full_name'],
        'initials'            => $e['name_with_initials'] ?? '',
        'mobile'              => $e['telephone_mobile'],
        'home'                => $e['telephone_home'] ?? '',
        'office'              => $e['telephone_office'] ?? '',
        'whatsapp'            => $e['whatsapp_number'] ?? '',
        'email'               => $e['email_address'] ?? '',
        'nic'                 => $e['id_number'] ?? '',
        'dob'                 => $e['date_of_birth'] ?? '',
        'gender'              => $e['gender'] ?? '',
        'blood'               => $e['blood_group'] ?? '',
        'doj'                 => $e['date_of_join'] ?? '',
        'status'              => $e['status'],
        'epf_qualified'       => $e['epf_qualified'],
        'epf_added'           => $e['epf_added'],
        'months_elapsed'      => $months_elapsed,
        'epf'                 => $e['epf_number'] ?? '',
        'company'             => ($e['company_code'] ?? '') . ($e['company_name'] ? ' — '.$e['company_name'] : ''),
        'branch'              => $e['branch_name'] ?? '',
        'designation'         => $e['designation_name'] ?? '',
        'staff_category'      => $e['staff_category_name'] ?? '',
        'staff_category_code' => $e['staff_category_code'] ?? '',
        'tr_code'             => $e['tr_code'] ?? '',
        'bank_name'           => $e['bank_name'] ?? '',
        'bank_branch'         => $e['bank_branch'] ?? '',
        'account'             => $e['account_number'] ?? '',
        'address'             => $e['address'] ?? '',
        'profile_picture'     => $e['profile_picture'] ?? '',
    ];
}
echo json_encode($js_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>;

/* ══════════════════════════════════
   TAB SWITCHING
══════════════════════════════════ */
let activeTab = 'all';

function switchTab(tab) {
    activeTab = tab;
    document.getElementById('tabAll').classList.toggle('active',       tab === 'all');
    document.getElementById('tabEpf').classList.toggle('active',       tab === 'epf');
    document.getElementById('tabEpfAdded').classList.toggle('active',  tab === 'epf_added');
    document.getElementById('tabResigned').classList.toggle('active',  tab === 'resigned');

    document.getElementById('epfLegend').style.display      = tab === 'epf'       ? 'flex' : 'none';
    document.getElementById('epfAddedLegend').style.display = tab === 'epf_added' ? 'flex' : 'none';
    document.getElementById('resignedLegend').style.display = tab === 'resigned'  ? 'flex' : 'none';

    handleSearch(document.getElementById('empSearchInput').value);
    closeDetailPanel();
}

/* ══════════════════════════════════
   SEARCH
══════════════════════════════════ */
function handleSearch(q) {
    q = q.trim().toLowerCase();
    document.getElementById('searchClear').style.display = q ? 'flex' : 'none';

    const rows = document.querySelectorAll('.emp-row');
    let visibleAll = 0, visibleEpf = 0, visibleEpfAdded = 0, visibleResigned = 0, visibleCurrent = 0;

    rows.forEach(row => {
        const haystack   = row.getAttribute('data-search');
        const isEpf      = row.getAttribute('data-epf')       === 'true';
        const isEpfAdded = row.getAttribute('data-epf-added') === 'true';
        const isResigned = row.getAttribute('data-resigned')  === 'true';
        const matchText  = !q || haystack.includes(q);

        const matchTab = (activeTab === 'all'       && !isResigned)
                      || (activeTab === 'epf'       && isEpf && !isEpfAdded && !isResigned)
                      || (activeTab === 'epf_added' && isEpfAdded && !isResigned)
                      || (activeTab === 'resigned'  && isResigned);

        const show = matchText && matchTab;

        row.style.display = show ? '' : 'none';
        row.classList.toggle('row-highlight', show && q.length > 0);

        if (matchText) {
            if (!isResigned)                      visibleAll++;
            if (isEpf && !isEpfAdded && !isResigned) visibleEpf++;
            if (isEpfAdded && !isResigned)        visibleEpfAdded++;
            if (isResigned)                       visibleResigned++;
        }
        if (show) visibleCurrent++;
    });

    document.getElementById('tabAllCount').textContent      = visibleAll;
    document.getElementById('tabEpfCount').textContent      = visibleEpf;
    document.getElementById('tabEpfAddedCount').textContent = visibleEpfAdded;
    document.getElementById('tabResignedCount').textContent = visibleResigned;

    const titleMap = {
        'all':       'All Employees',
        'epf':       'EPF Registration Needed',
        'epf_added': 'EPF Added Employees',
        'resigned':  'Resigned Employees',
    };
    document.getElementById('searchCount').textContent = visibleCurrent + ' employee' + (visibleCurrent !== 1 ? 's' : '');
    document.getElementById('tableTitle').textContent  = (titleMap[activeTab] || 'All Employees') + ' (' + visibleCurrent + ')';
    document.getElementById('noResultsMsg').style.display = visibleCurrent === 0 ? 'block' : 'none';

    // Update export badge count
    updateExportBadge(visibleCurrent);

    if (visibleCurrent === 1 && q.length > 0) {
        const visibleRow = [...rows].find(r => r.style.display !== 'none');
        if (visibleRow) showEmployeePanel(parseInt(visibleRow.getAttribute('data-id')));
    } else if (q.length === 0) {
        closeDetailPanel();
    }
}

function clearSearch() {
    const input = document.getElementById('empSearchInput');
    input.value = '';
    handleSearch('');
    input.focus();
}

/* ══════════════════════════════════
   EXPORT TO EXCEL
══════════════════════════════════ */
function updateExportBadge(count) {
    const badge = document.getElementById('exportCountBadge');
    if (badge) badge.textContent = count;
}

function getExportData() {
    // Mirror exactly the same filter logic as handleSearch()
    const q = document.getElementById('empSearchInput').value.trim().toLowerCase();

    return EMP_DATA.filter(emp => {
        const isResigned = (emp.status === 'Resigned');
        const isEpf      = emp.epf_qualified;
        const isEpfAdded = emp.epf_added;

        // Tab filter
        const matchTab = (activeTab === 'all'       && !isResigned)
                      || (activeTab === 'epf'       && isEpf && !isEpfAdded && !isResigned)
                      || (activeTab === 'epf_added' && isEpfAdded && !isResigned)
                      || (activeTab === 'resigned'  && isResigned);

        if (!matchTab) return false;

        // Search filter — build the same haystack as data-search attribute
        if (q) {
            const haystack = [
                emp.id, emp.employee_id, emp.full_name, emp.initials,
                emp.mobile, emp.nic, emp.email,
                emp.company, emp.branch, emp.designation,
                emp.staff_category, emp.epf, emp.tr_code
            ].join(' ').toLowerCase();
            if (!haystack.includes(q)) return false;
        }

        return true;
    });
}

function exportToExcel() {
    if (typeof XLSX === 'undefined') {
        alert('Excel library not loaded yet. Please wait a moment and try again.');
        return;
    }

    const exportData = getExportData();

    if (exportData.length === 0) {
        alert('No employees to export in the current view.');
        return;
    }

    const tabLabels = {
        'all':       'All Employees',
        'epf':       'EPF Needed',
        'epf_added': 'EPF Added',
        'resigned':  'Resigned',
    };

    // Column definitions
    const columns = [
        { label: 'ID',                fn: e => e.id                || '' },
        { label: 'Employee ID',      fn: e => e.employee_id      || '' },
        { label: 'Full Name',        fn: e => e.full_name         || '' },
        { label: 'Name w/ Initials', fn: e => e.initials          || '' },
        { label: 'Company',          fn: e => e.company           || '' },
        { label: 'Branch',           fn: e => e.branch            || '' },
        { label: 'Designation',      fn: e => e.designation       || '' },
        { label: 'Staff Category',   fn: e => e.staff_category    || '' },
        { label: 'TR Code',          fn: e => e.tr_code           || '' },
        { label: 'Status',           fn: e => e.status            || '' },
        { label: 'Date of Join',     fn: e => e.doj               || '' },
        { label: 'Date of Birth',    fn: e => e.dob               || '' },
        { label: 'NIC / ID Number',  fn: e => e.nic               || '' },
        { label: 'Gender',           fn: e => e.gender ? e.gender.charAt(0).toUpperCase() + e.gender.slice(1) : '' },
        { label: 'Blood Group',      fn: e => e.blood             || '' },
        { label: 'Mobile',           fn: e => e.mobile            || '' },
        { label: 'Home Phone',       fn: e => e.home              || '' },
        { label: 'Office Phone',     fn: e => e.office            || '' },
        { label: 'WhatsApp',         fn: e => e.whatsapp          || '' },
        { label: 'Email',            fn: e => e.email             || '' },
        { label: 'EPF Number',       fn: e => e.epf               || '' },
        { label: 'EPF Status',       fn: e => {
            if (e.status === 'Resigned') return 'Resigned';
            if (e.epf_added)             return 'Added';
            if (e.epf_qualified)         return 'Needed';
            return 'Pending';
        }},
        { label: 'Months Employed',  fn: e => e.months_elapsed != null ? e.months_elapsed : '' },
        { label: 'Bank Name',        fn: e => e.bank_name         || '' },
        { label: 'Bank Branch',      fn: e => e.bank_branch       || '' },
        { label: 'Account Number',   fn: e => e.account           || '' },
    ];

    // Build array-of-arrays: header + data rows
    const wsData = [
        columns.map(c => c.label),
        ...exportData.map(emp => columns.map(c => c.fn(emp)))
    ];

    const ws = XLSX.utils.aoa_to_sheet(wsData);

    // Column widths
    ws['!cols'] = [8,12,28,24,28,18,20,18,10,12,14,14,16,10,10,16,16,16,16,28,14,12,6,20,18,18]
                  .map(w => ({ wch: w }));

    // Freeze header row
    ws['!freeze'] = { xSplit: 0, ySplit: 1, topLeftCell: 'A2', activePane: 'bottomLeft' };

    // Workbook
    const wb = XLSX.utils.book_new();
    const sheetName = (tabLabels[activeTab] || 'Employees').substring(0, 31);
    XLSX.utils.book_append_sheet(wb, ws, sheetName);

    // Filename with date
    const now     = new Date();
    const dateStr = now.getFullYear() + '-'
                  + String(now.getMonth()+1).padStart(2,'0') + '-'
                  + String(now.getDate()).padStart(2,'0');
    const filename = `Employees_${sheetName.replace(/\s+/g,'_')}_${dateStr}.xlsx`;

    // Button animation
    const btn  = document.getElementById('exportBtn');
    const span = btn.querySelector('span:not(.export-count-badge)');
    btn.classList.add('exporting');
    if (span) span.textContent = 'Exporting…';

    setTimeout(() => {
        XLSX.writeFile(wb, filename);
        btn.classList.remove('exporting');
        if (span) span.textContent = 'Export to Excel';
        btn.classList.add('export-done');
        setTimeout(() => btn.classList.remove('export-done'), 2000);
    }, 250);
}

/* ══════════════════════════════════
   DETAIL PANEL
══════════════════════════════════ */
let activeRowId = null;

function showEmployeePanel(id) {
    const emp = EMP_DATA.find(e => e.id === id);
    if (!emp) return;

    document.querySelectorAll('.emp-row').forEach(r => r.classList.remove('row-active'));
    const activeRow = document.querySelector(`.emp-row[data-id="${id}"]`);
    if (activeRow) activeRow.classList.add('row-active');
    activeRowId = id;

    const statusMap = {
        'Probation':  { cls: 'status-probation',  label: 'Probation'  },
        'Permanent':  { cls: 'status-permanent',   label: 'Permanent'  },
        'Resigned':   { cls: 'status-resigned',    label: 'Resigned'   },
        'Terminated': { cls: 'status-terminated',  label: 'Terminated' },
    };
    const st = statusMap[emp.status] || { cls: '', label: emp.status };

    const avatarHtml = emp.profile_picture
        ? `<img src="${emp.profile_picture}" class="detail-avatar-img" alt="">`
        : `<div class="detail-avatar-placeholder"><i class="fa-solid fa-user"></i></div>`;

    const row = (label, value) => value
        ? `<div class="dp-row"><span class="dp-label">${label}</span><span class="dp-value">${value}</span></div>`
        : '';

    const fmt = (d) => {
        if (!d) return '';
        const parts = d.split('-');
        if (parts.length !== 3) return d;
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[parseInt(parts[1])-1] + ' ' + parseInt(parts[2]) + ', ' + parts[0];
    };

    // EPF / Resigned banner
    let epfBanner = '';
    if (emp.status === 'Resigned') {
        epfBanner = `<div class="dp-epf-banner dp-resigned-banner">
            <i class="fa-solid fa-user-minus"></i>
            <div>
                <strong>Resigned Employee</strong>
                <span>This employee has resigned and is excluded from active headcount</span>
            </div>
        </div>`;
    } else if (emp.epf_added) {
        epfBanner = `<div class="dp-epf-banner dp-epf-done">
            <i class="fa-solid fa-circle-check"></i>
            <div>
                <strong>EPF Added — ${emp.epf}</strong>
                <span>Employed ${emp.months_elapsed} month${emp.months_elapsed !== 1 ? 's' : ''} — EPF number registered</span>
            </div>
        </div>`;
    } else if (emp.epf_qualified) {
        epfBanner = `<div class="dp-epf-banner dp-epf-needed">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <strong>EPF Registration Needed</strong>
                <span>Employed ${emp.months_elapsed} month${emp.months_elapsed !== 1 ? 's' : ''} — EPF number not yet added</span>
            </div>
        </div>`;
    } else {
        epfBanner = `<div class="dp-epf-banner dp-epf-pending">
            <i class="fa-solid fa-clock"></i>
            <div>
                <strong>EPF Pending</strong>
                <span>Employed ${emp.months_elapsed} month${emp.months_elapsed !== 1 ? 's' : ''} — qualifies after 3 months</span>
            </div>
        </div>`;
    }

    // Staff category badge for detail panel
    const categoryBadge = emp.staff_category
        ? `<span class="dp-category-badge"><i class="fa-solid fa-layer-group" style="font-size:10px;"></i> ${emp.staff_category}</span>`
        : '';

    document.getElementById('detailContent').innerHTML = `
        <div class="dp-header ${emp.status === 'Resigned' ? 'dp-header-resigned' : ''}">
            <div class="dp-avatar">${avatarHtml}</div>
            <div class="dp-header-info">
                <div class="dp-name">${emp.full_name}</div>
                <div class="dp-empid">${emp.employee_id}</div>
                ${emp.designation ? `<div class="dp-designation">${emp.designation}</div>` : ''}
                ${categoryBadge}
                <span class="dp-status ${st.cls}">${st.label}</span>
            </div>
            <button class="dp-close" onclick="closeDetailPanel()" title="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>

        ${epfBanner}

        <div class="dp-actions">
            <a href="view_employee.php?id=${emp.id}" class="dp-btn dp-btn-primary"><i class="fa-solid fa-eye"></i> View Full Profile</a>
            <a href="edit_employee.php?id=${emp.id}" class="dp-btn dp-btn-secondary"><i class="fa-solid fa-pen"></i> Edit</a>
        </div>

        <div class="dp-section">
            <div class="dp-section-title"><i class="fa-solid fa-building"></i> Employment</div>
            ${row('Company', emp.company)}
            ${row('Branch', emp.branch)}
            ${emp.staff_category
                ? `<div class="dp-row"><span class="dp-label">Staff Category</span><span class="dp-value"><span class="dp-cat-inline">${emp.staff_category}</span></span></div>`
                : ''}
            ${row('TR Code', emp.tr_code)}
            ${row('Date of Join', fmt(emp.doj))}
            ${emp.status !== 'Resigned'
                ? (emp.epf_added
                    ? `<div class="dp-row"><span class="dp-label">EPF Number</span><span class="dp-value dp-epf-num"><i class="fa-solid fa-circle-check" style="color:#22c55e;font-size:11px;"></i> ${emp.epf}</span></div>`
                    : emp.epf_qualified
                        ? `<div class="dp-row"><span class="dp-label">EPF Number</span><span class="dp-value dp-epf-missing"><i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;font-size:11px;"></i> Not added yet</span></div>`
                        : '')
                : ''
            }
        </div>

        <div class="dp-section">
            <div class="dp-section-title"><i class="fa-solid fa-user"></i> Personal</div>
            ${row('NIC', emp.nic)}
            ${row('Date of Birth', fmt(emp.dob))}
            ${row('Gender', emp.gender ? emp.gender.charAt(0).toUpperCase() + emp.gender.slice(1) : '')}
            ${row('Blood Group', emp.blood)}
            ${emp.address ? `<div class="dp-row"><span class="dp-label">Address</span><span class="dp-value dp-address">${emp.address.replace(/\n/g,'<br>')}</span></div>` : ''}
        </div>

        <div class="dp-section">
            <div class="dp-section-title"><i class="fa-solid fa-phone"></i> Contact</div>
            ${row('Mobile', `<a href="tel:${emp.mobile}" class="dp-link">${emp.mobile}</a>`)}
            ${row('Home', emp.home)}
            ${row('Office', emp.office)}
            ${row('WhatsApp', emp.whatsapp)}
            ${row('Email', emp.email ? `<a href="mailto:${emp.email}" class="dp-link">${emp.email}</a>` : '')}
        </div>

        ${(emp.bank_name || emp.account) ? `
        <div class="dp-section">
            <div class="dp-section-title"><i class="fa-solid fa-building-columns"></i> Bank</div>
            ${row('Bank', emp.bank_name)}
            ${row('Branch', emp.bank_branch)}
            ${row('Account', emp.account)}
        </div>` : ''}
    `;

    document.getElementById('detailEmpty').style.display   = 'none';
    document.getElementById('detailContent').style.display = 'block';
    document.getElementById('empLayout').classList.add('panel-open');
}

function closeDetailPanel() {
    document.getElementById('detailEmpty').style.display   = 'flex';
    document.getElementById('detailContent').style.display = 'none';
    document.getElementById('empLayout').classList.remove('panel-open');
    document.querySelectorAll('.emp-row').forEach(r => r.classList.remove('row-active'));
    activeRowId = null;
}

/* ══════════════════════════════════
   FILTERS
══════════════════════════════════ */
function toggleFilters() {
    const s   = document.getElementById('filters-section');
    const btn = document.getElementById('filterToggleBtn');
    const showing = s.style.display !== 'none';
    s.style.display = showing ? 'none' : 'block';
    btn.classList.toggle('active', !showing);
}

function loadFilterBranches(companyId) {
    const branchSelect = document.getElementById('filter_branch');
    branchSelect.innerHTML = '<option value="">All Branches</option>';
    if (companyId) {
        fetch('get_branches.php?company_id=' + companyId)
            .then(r => r.json())
            .then(data => {
                data.forEach(branch => {
                    const opt       = document.createElement('option');
                    opt.value       = branch.id;
                    opt.textContent = branch.branch_code + ' - ' + branch.branch_name;
                    branchSelect.appendChild(opt);
                });
            });
    }
}

window.addEventListener('DOMContentLoaded', function () {
    const hasActiveFilter = <?php echo ($filter_company || $filter_branch || $filter_status || $filter_designation || $filter_staff_category || $filter_date_from || $filter_date_to) ? 'true' : 'false'; ?>;
    if (hasActiveFilter) {
        document.getElementById('filters-section').style.display = 'block';
        document.getElementById('filterToggleBtn').classList.add('active');
    }
    <?php if ($filter_company): ?>
    loadFilterBranches(<?php echo $filter_company; ?>);
    setTimeout(() => { document.getElementById('filter_branch').value = '<?php echo $filter_branch; ?>'; }, 500);
    <?php endif; ?>

    document.getElementById('empSearchInput').focus();

    // On initial load, hide resigned rows from "all" tab
    handleSearch('');
});
</script>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- Styles                                                        -->
<!-- ═══════════════════════════════════════════════════════════ -->
<style>
/* ── Layout ── */
.emp-layout {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
    transition: grid-template-columns 0.35s cubic-bezier(.4,0,.2,1);
}
.emp-layout.panel-open {
    grid-template-columns: 1fr 360px;
}

/* ── Export Button ── */
.btn-excel {
    background: #16a34a;
    color: #fff;
    border: none;
    position: relative;
    overflow: hidden;
    transition: background .2s, transform .15s, box-shadow .2s;
}
.btn-excel:hover {
    background: #15803d;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(22,163,74,.35);
}
.btn-excel:active { transform: translateY(0); }

.btn-excel.exporting {
    background: #15803d;
    pointer-events: none;
    opacity: .85;
}
.btn-excel.export-done {
    background: #0f766e;
}
.btn-excel.export-done::after {
    content: ' ✓';
}

.export-count-badge {
    background: rgba(255,255,255,.25);
    color: #fff;
    border-radius: 10px;
    padding: 1px 7px;
    font-size: 11px;
    font-weight: 700;
    min-width: 20px;
    text-align: center;
}

/* ── Tabs ── */
.emp-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
    border-bottom: 2px solid #f0f0f0;
    padding-bottom: 0;
}
.emp-tab {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    color: #6b7280;
    border-radius: 8px 8px 0 0;
    transition: all .2s;
    font-family: inherit;
}
.emp-tab:hover { color: #111; background: #f9fafb; }
.emp-tab.active { color: #111; border-bottom-color: #111; background: #fff; }

.emp-tab.epf-tab.active  { color: #dc2626; border-bottom-color: #dc2626; }
.emp-tab.epf-tab:hover   { color: #dc2626; }

.emp-tab.epf-added-tab.active { color: #16a34a; border-bottom-color: #16a34a; }
.emp-tab.epf-added-tab:hover  { color: #16a34a; }

.emp-tab.resigned-tab.active { color: #6b7280; border-bottom-color: #6b7280; }
.emp-tab.resigned-tab:hover  { color: #374151; }

.tab-count {
    background: #f3f4f6;
    color: #6b7280;
    border-radius: 12px;
    padding: 1px 8px;
    font-size: 11px;
    font-weight: 700;
}
.emp-tab.active .tab-count { background: #e5e7eb; color: #374151; }

.tab-count-green { background: #dcfce7; color: #166534; }
.emp-tab.active .tab-count-green { background: #bbf7d0; color: #15803d; }

.tab-count-resigned { background: #f3f4f6; color: #6b7280; }
.emp-tab.active .tab-count-resigned { background: #e5e7eb; color: #374151; }

.tab-badge-red {
    background: #ef4444;
    color: #fff;
    border-radius: 12px;
    padding: 1px 8px;
    font-size: 11px;
    font-weight: 700;
    animation: pulse-red 2s ease-in-out infinite;
}
.emp-tab.active .tab-badge-red {
    background: #fff;
    color: #dc2626;
    border: 1.5px solid #dc2626;
    animation: none;
}
@keyframes pulse-red {
    0%,100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.4); }
    50%      { box-shadow: 0 0 0 4px rgba(239,68,68,0); }
}

/* ── EPF row highlights ── */
.emp-row.epf-row td:first-child   { border-left: 3px solid #ef4444; }
.emp-row.epf-row                   { background: #fff8f8; }
.emp-row.epf-row:hover             { background: #fff0f0 !important; }

.emp-row.epf-added-row td:first-child { border-left: 3px solid #22c55e; }
.emp-row.epf-added-row                 { background: #f0fdf4; }
.emp-row.epf-added-row:hover           { background: #dcfce7 !important; }

/* ── Resigned row highlight ── */
.emp-row.resigned-row td:first-child { border-left: 3px solid #9ca3af; }
.emp-row.resigned-row                 { background: #f9fafb; opacity: 0.85; }
.emp-row.resigned-row:hover           { background: #f3f4f6 !important; opacity: 1; }

/* ── Dots inline ── */
.epf-dot-inline {
    display: inline-block;
    width: 7px; height: 7px;
    background: #ef4444;
    border-radius: 50%;
    margin-left: 5px;
    vertical-align: middle;
    flex-shrink: 0;
}
.epf-dot-inline.epf-dot-green { background: #22c55e; }

.resigned-dot-inline {
    display: inline-block;
    width: 7px; height: 7px;
    background: #9ca3af;
    border-radius: 50%;
    margin-left: 5px;
    vertical-align: middle;
    flex-shrink: 0;
}

/* ── Date of join cell ── */
.doj-cell             { font-size: 12px; color: #6b7280; }
.doj-cell.doj-epf     { color: #dc2626; font-weight: 600; }
.doj-cell.doj-epf-added { color: #16a34a; font-weight: 600; }

/* ── EPF cell in table ── */
.epf-cell-added {
    display: flex;
    flex-direction: column;
    gap: 3px;
    align-items: flex-start;
}
.epf-number-display {
    font-size: 11px;
    font-weight: 700;
    color: #166534;
    letter-spacing: .3px;
    padding-left: 2px;
}

/* ── EPF / status badges ── */
.badge-epf-added {
    background: #dcfce7;
    color: #166534;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.badge-epf-needed {
    background: #fff7ed;
    color: #c2410c;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px solid #fed7aa;
}
.badge-epf-pending { background: #f3f4f6; color: #9ca3af; }

.badge-resigned-epf {
    background: #f3f4f6;
    color: #6b7280;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px solid #e5e7eb;
}

/* ── Staff Category badge ── */
.badge-category {
    background: #f0f4ff;
    color: #3730a3;
    border: 1px solid #c7d2fe;
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 5px;
    font-weight: 600;
}

/* ── Card title bar ── */
.card-title-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    padding: 16px 20px;
    border-bottom: 1px solid #f0f0f0;
}
.card-title-bar .card-title { padding: 0; border-bottom: none; margin: 0; }

/* ── Legends ── */
.epf-legend, .epf-added-legend, .resigned-legend {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 12px;
    font-weight: 500;
}
.epf-legend       { color: #dc2626; }
.epf-added-legend { color: #16a34a; }
.resigned-legend  { color: #6b7280; }

.epf-legend-dot {
    width: 10px; height: 10px;
    background: #ef4444;
    border-radius: 50%;
    flex-shrink: 0;
}
.epf-added-legend-dot {
    width: 10px; height: 10px;
    background: #22c55e;
    border-radius: 50%;
    flex-shrink: 0;
}
.resigned-legend-dot {
    width: 10px; height: 10px;
    background: #9ca3af;
    border-radius: 50%;
    flex-shrink: 0;
}

/* ── Detail panel banners ── */
.dp-epf-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    font-size: 12px;
}
.dp-epf-banner i       { font-size: 18px; flex-shrink: 0; }
.dp-epf-banner strong  { display: block; font-weight: 700; font-size: 12px; }
.dp-epf-banner span    { font-size: 11px; }

.dp-epf-banner.dp-epf-needed {
    background: #fff7ed;
    border-left: 3px solid #f59e0b;
    color: #92400e;
}
.dp-epf-banner.dp-epf-needed i      { color: #f59e0b; }
.dp-epf-banner.dp-epf-needed strong { color: #92400e; }
.dp-epf-banner.dp-epf-needed span   { color: #b45309; }

.dp-epf-banner.dp-epf-done {
    background: #f0fdf4;
    border-left: 3px solid #22c55e;
    color: #166534;
}
.dp-epf-banner.dp-epf-done i      { color: #22c55e; }
.dp-epf-banner.dp-epf-done strong { color: #15803d; }
.dp-epf-banner.dp-epf-done span   { color: #16a34a; }

.dp-epf-banner.dp-epf-pending {
    background: #f9fafb;
    border-left: 3px solid #d1d5db;
    color: #6b7280;
}
.dp-epf-banner.dp-epf-pending i      { color: #9ca3af; }
.dp-epf-banner.dp-epf-pending strong { color: #374151; }
.dp-epf-banner.dp-epf-pending span   { color: #9ca3af; }

.dp-epf-banner.dp-resigned-banner {
    background: #f3f4f6;
    border-left: 3px solid #9ca3af;
    color: #6b7280;
}
.dp-epf-banner.dp-resigned-banner i      { color: #9ca3af; }
.dp-epf-banner.dp-resigned-banner strong { color: #374151; }
.dp-epf-banner.dp-resigned-banner span   { color: #9ca3af; }

/* ── Detail panel header resigned variant ── */
.dp-header-resigned { background: #f9fafb; }

/* ── Detail panel EPF value styling ── */
.dp-epf-num {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: #15803d;
    font-weight: 700;
}
.dp-epf-missing {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: #b45309;
    font-weight: 600;
    font-style: italic;
}

/* ── Detail panel staff category ── */
.dp-category-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #f0f4ff;
    color: #3730a3;
    border: 1px solid #c7d2fe;
    border-radius: 5px;
    padding: 2px 8px;
    font-size: 11px;
    font-weight: 600;
    margin-bottom: 6px;
}
.dp-cat-inline {
    display: inline-block;
    background: #f0f4ff;
    color: #3730a3;
    border: 1px solid #c7d2fe;
    border-radius: 5px;
    padding: 1px 8px;
    font-size: 11px;
    font-weight: 600;
}

/* ── Search bar ── */
.search-bar-wrap     { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; }
.search-input-wrap   { position: relative; flex: 1; max-width: 480px; }
.search-icon         { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 14px; pointer-events: none; }
.search-input        { width: 100%; padding: 12px 40px 12px 40px; border: 2px solid #e5e7eb; border-radius: 10px; font-size: 14px; font-family: inherit; background: #fff; color: #111; transition: border-color .2s, box-shadow .2s; box-sizing: border-box; }
.search-input:focus  { outline: none; border-color: #000; box-shadow: 0 0 0 3px rgba(0,0,0,.06); }
.search-input::placeholder { color: #adb5bd; }
.search-clear        { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: #f3f4f6; border: none; border-radius: 50%; width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #6b7280; font-size: 11px; transition: background .2s; }
.search-clear:hover  { background: #e5e7eb; color: #111; }
.search-count        { font-size: 12px; color: #6b7280; font-weight: 600; white-space: nowrap; }

/* ── Table row states ── */
.emp-row                  { transition: background .15s; }
.emp-row.row-highlight    { background: #fffbeb !important; }
.emp-row.row-active       { background: #eff6ff !important; outline: 2px solid #3b82f6; outline-offset: -2px; }
.emp-row:not(.epf-row):not(.epf-added-row):not(.resigned-row):hover { background: #f9fafb; }

/* ── Detail panel ── */
.emp-detail-panel {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    overflow: hidden;
    position: sticky;
    top: 20px;
    max-height: calc(100vh - 60px);
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    scrollbar-width: thin;
    scrollbar-color: #e5e7eb transparent;
}
.emp-layout:not(.panel-open) .emp-detail-panel { display: none; }

.detail-empty {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    height: 300px; color: #9ca3af; text-align: center; padding: 32px; gap: 14px;
}
.detail-empty-icon {
    width: 56px; height: 56px; background: #f3f4f6; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; font-size: 22px; color: #d1d5db;
}
.detail-empty p { font-size: 13px; line-height: 1.6; margin: 0; }
.detail-content { padding: 0; }

.dp-header {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 20px 20px 16px; background: #fafafa;
    border-bottom: 1px solid #f0f0f0; position: relative;
}
.dp-avatar { flex-shrink: 0; }
.detail-avatar-img         { width: 64px; height: 64px; border-radius: 10px; object-fit: cover; border: 2px solid #e5e7eb; }
.detail-avatar-placeholder { width: 64px; height: 64px; background: #f3f4f6; border-radius: 10px; display: flex; align-items: center; justify-content: center; border: 2px solid #e5e7eb; color: #9ca3af; font-size: 26px; }
.dp-header-info    { flex: 1; min-width: 0; }
.dp-name           { font-size: 15px; font-weight: 700; color: #111; line-height: 1.3; word-break: break-word; }
.dp-empid          { font-size: 12px; color: #6b7280; margin: 2px 0 4px; font-weight: 600; letter-spacing: .3px; }
.dp-designation    { font-size: 12px; color: #3b82f6; font-weight: 600; margin-bottom: 4px; }
.dp-status         { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: .3px; margin-top: 4px; }
.status-probation  { background: #fef3c7; color: #92400e; }
.status-permanent  { background: #dcfce7; color: #15803d; }
.status-resigned   { background: #f3f4f6; color: #6b7280; }
.status-terminated { background: #fee2e2; color: #991b1b; }

.dp-close { position: absolute; top: 14px; right: 14px; background: #f3f4f6; border: none; border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #6b7280; font-size: 13px; transition: all .2s; }
.dp-close:hover { background: #e5e7eb; color: #111; }

.dp-actions         { display: flex; gap: 8px; padding: 12px 20px; border-bottom: 1px solid #f0f0f0; }
.dp-btn             { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 7px; font-size: 12px; font-weight: 600; text-decoration: none; transition: all .2s; border: 1px solid transparent; }
.dp-btn-primary     { background: #111; color: #fff; } .dp-btn-primary:hover { background: #333; }
.dp-btn-secondary   { background: #f5f5f5; color: #333; border-color: #e5e7eb; } .dp-btn-secondary:hover { background: #e5e5e5; }

.dp-section         { padding: 14px 20px; border-bottom: 1px solid #f3f4f6; }
.dp-section:last-child { border-bottom: none; }
.dp-section-title   { display: flex; align-items: center; gap: 7px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; color: #9ca3af; margin-bottom: 10px; }
.dp-row             { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; padding: 5px 0; border-bottom: 1px solid #f9fafb; font-size: 13px; }
.dp-row:last-child  { border-bottom: none; }
.dp-label           { color: #6b7280; font-weight: 500; flex-shrink: 0; min-width: 90px; }
.dp-value           { color: #111; font-weight: 600; text-align: right; word-break: break-word; }
.dp-address         { text-align: right; line-height: 1.5; }
.dp-link            { color: #2563eb; text-decoration: none; } .dp-link:hover { text-decoration: underline; }

/* ── Filter toggle ── */
.btn-filter-toggle              { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; background: #fff; border: 1px solid #e5e5e5; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all .3s; color: #333; }
.btn-filter-toggle:hover        { background: #f5f5f5; border-color: #d1d5db; }
.btn-filter-toggle i:last-child { transition: transform .3s; }
.btn-filter-toggle.active i:last-child { transform: rotate(180deg); }
.filter-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 20px; padding: 0 6px; background: #000; color: #fff; border-radius: 10px; font-size: 11px; font-weight: 700; }

/* ── Filters section ── */
.filters-section { margin-bottom: 16px; }
.filter-form     { padding: 16px 20px; }
.filter-grid     { display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px; margin-bottom: 16px; }
.filter-group    { display: flex; flex-direction: column; }
.filter-label    { font-size: 11px; font-weight: 700; margin-bottom: 6px; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; }
.filter-input    { padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; font-family: inherit; background: #fff; }
.filter-input:focus { outline: none; border-color: #000; box-shadow: 0 0 0 3px rgba(0,0,0,.05); }
.filter-actions  { display: flex; gap: 10px; }

/* ── Shared cards / table ── */
.content-card     { background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; overflow: hidden; }
.card-title       { font-size: 14px; font-weight: 700; color: #111; }
.table-responsive { overflow-x: auto; }
.data-table       { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table thead { background: #f9fafb; border-bottom: 1px solid #e5e7eb; }
.data-table th    { padding: 11px 14px; text-align: left; font-weight: 600; color: #6b7280; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
.data-table tbody tr { border-bottom: 1px solid #f3f4f6; }
.data-table td    { padding: 13px 14px; color: #111827; }

.badge             { display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:600; }
.badge-success     { background:#dcfce7;color:#166534; }
.badge-warning     { background:#fef3c7;color:#92400e; }
.badge-inactive    { background:#f3f4f6;color:#6b7280; }
.badge-danger      { background:#fee2e2;color:#991b1b; }
.badge-designation { background:#dbeafe;color:#1e40af; }

.action-buttons    { display:flex;gap:6px; }
.btn-action        { display:inline-flex;align-items:center;justify-content:width:32px;height:32px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;color:#6b7280;cursor:pointer;transition:all .2s;text-decoration:none; }
.btn-action        { display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;color:#6b7280;cursor:pointer;transition:all .2s;text-decoration:none; }
.btn-action:hover  { transform:translateY(-1px);box-shadow:0 2px 4px rgba(0,0,0,.08); }
.btn-view:hover    { background:#3b82f6;color:#fff;border-color:#3b82f6; }
.btn-edit:hover    { background:#000;color:#fff;border-color:#000; }
.btn-delete:hover  { background:#ef4444;color:#fff;border-color:#ef4444; }

.btn               { display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .3s;text-decoration:none;font-family:inherit; }
.btn-primary       { background:#000;color:#fff; } .btn-primary:hover { background:#1f2937; }
.btn-dark          { background:#000;color:#fff; } .btn-dark:hover { background:#1f2937; }
.btn-light         { background:#f9fafb;color:#374151;border:1px solid #d1d5db; } .btn-light:hover { background:#f3f4f6; }

/* ── Responsive ── */
@media (max-width: 1200px) { .filter-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 1100px) {
    .emp-layout.panel-open { grid-template-columns: 1fr; }
    .emp-layout.panel-open .emp-detail-panel {
        position: fixed; right: 0; top: 0; bottom: 0;
        width: 340px; z-index: 999; border-radius: 0;
        box-shadow: -4px 0 24px rgba(0,0,0,.12); max-height: 100vh;
    }
}
@media (max-width: 900px)  { .filter-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 600px) {
    .filter-grid { grid-template-columns: 1fr; }
    .filter-actions { flex-direction: column; }
    .emp-layout.panel-open .emp-detail-panel { width: 100%; }
    .search-bar-wrap { flex-wrap: wrap; }
    .emp-tabs { overflow-x: auto; }
    .btn-excel span:first-of-type { display: none; }
}
</style>

<?php include 'footer.php'; ?>