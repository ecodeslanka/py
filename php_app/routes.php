<?php
include 'config.php';

// Create routes table if not exists
$createRoutesTable = "CREATE TABLE IF NOT EXISTS routes (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    route_code VARCHAR(50) NOT NULL UNIQUE,
    route_name VARCHAR(255) NOT NULL,
    company_id INT(11) NULL,
    branch_id INT(11) NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
)";
mysqli_query($conn, $createRoutesTable);

// Handle Delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $sql = "DELETE FROM routes WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        $success_message = "Route deleted successfully!";
    } else {
        $error_message = "Error deleting route: " . mysqli_error($conn);
    }
}

// Handle form submission (Create or Update)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $route_code = isset($_POST['route_code']) ? mysqli_real_escape_string($conn, trim($_POST['route_code'])) : '';
    $route_name = isset($_POST['route_name']) ? mysqli_real_escape_string($conn, trim($_POST['route_name'])) : '';
    $company_id = !empty($_POST['company_id']) ? intval($_POST['company_id']) : null;
    $branch_id = !empty($_POST['branch_id']) ? intval($_POST['branch_id']) : null;
    $active = isset($_POST['active']) ? 1 : 0;
    
    // Validate required fields
    if (empty($route_code) || empty($route_name)) {
        $error_message = "Route code and name are required fields.";
    } else {
        if (isset($_POST['route_id']) && !empty($_POST['route_id'])) {
            // Update existing route
            $id = intval($_POST['route_id']);
            
            // Check if route code already exists (excluding current record)
            $check_sql = "SELECT id FROM routes WHERE route_code = '$route_code' AND id != $id";
            $check_result = mysqli_query($conn, $check_sql);
            
            if (mysqli_num_rows($check_result) > 0) {
                $error_message = "Route code already exists. Please use a different code.";
            } else {
                $sql = "UPDATE routes SET 
                        route_code = '$route_code',
                        route_name = '$route_name',
                        company_id = " . ($company_id ? "'$company_id'" : "NULL") . ",
                        branch_id = " . ($branch_id ? "'$branch_id'" : "NULL") . ",
                        active = '$active' 
                        WHERE id = $id";
                
                if (mysqli_query($conn, $sql)) {
                    $success_message = "Route updated successfully!";
                } else {
                    $error_message = "Error: " . mysqli_error($conn);
                }
            }
        } else {
            // Check if route code already exists
            $check_sql = "SELECT id FROM routes WHERE route_code = '$route_code'";
            $check_result = mysqli_query($conn, $check_sql);
            
            if (mysqli_num_rows($check_result) > 0) {
                $error_message = "Route code already exists. Please use a different code.";
            } else {
                // Insert new route
                $sql = "INSERT INTO routes (route_code, route_name, company_id, branch_id, active) 
                       VALUES ('$route_code', '$route_name', 
                       " . ($company_id ? "'$company_id'" : "NULL") . ", 
                       " . ($branch_id ? "'$branch_id'" : "NULL") . ", 
                       '$active')";
                
                if (mysqli_query($conn, $sql)) {
                    $success_message = "Route created successfully!";
                } else {
                    $error_message = "Error: " . mysqli_error($conn);
                }
            }
        }
    }
}

// Handle search
$search = '';
$search_query = '';
if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $search = mysqli_real_escape_string($conn, trim($_GET['search']));
    $search_query = " WHERE r.route_code LIKE '%$search%' 
                     OR r.route_name LIKE '%$search%'
                     OR c.company_name LIKE '%$search%'
                     OR c.company_code LIKE '%$search%'
                     OR b.branch_name LIKE '%$search%'
                     OR b.branch_code LIKE '%$search%'";
}

// Get all routes with company and branch names
$routes_sql = "SELECT r.*, 
               c.company_name, c.company_code,
               b.branch_name, b.branch_code
               FROM routes r 
               LEFT JOIN companies c ON r.company_id = c.id
               LEFT JOIN branches b ON r.branch_id = b.id
               $search_query
               ORDER BY r.created_at DESC";
$routes_result = mysqli_query($conn, $routes_sql);

// Get total count for display
$total_routes = mysqli_num_rows($routes_result);

// Get all companies for dropdown
$companies_sql = "SELECT id, company_code, company_name FROM companies WHERE active = 1 ORDER BY company_name";
$companies_result = mysqli_query($conn, $companies_sql);

// Get all branches for dropdown
$branches_sql = "SELECT id, branch_code, branch_name, company_id FROM branches WHERE active = 1 ORDER BY branch_name";
$branches_result = mysqli_query($conn, $branches_sql);

include 'header.php';
?>

<!-- Routes Page -->
<div class="page-header">
    <h2 class="page-title">Routes Management</h2>
    <p class="page-subtitle">Manage routes, assign to companies and branches</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i>
    <?php echo $success_message; ?>
</div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
<div class="alert alert-error">
    <i class="fa-solid fa-circle-exclamation"></i>
    <?php echo $error_message; ?>
</div>
<?php endif; ?>

<!-- Action Bar with Search and Add Button -->
<div class="action-bar">
    <div class="search-box">
        <form method="GET" action="" id="searchForm">
            <div class="search-input-wrapper">
                <i class="fa-solid fa-search search-icon"></i>
                <input 
                    type="text" 
                    name="search" 
                    id="searchInput"
                    class="search-input" 
                    placeholder="Search routes, companies, or branches..."
                    value="<?php echo htmlspecialchars($search); ?>"
                >
                <?php if (!empty($search)): ?>
                <button type="button" class="clear-search" onclick="clearSearch()">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <?php endif; ?>
            </div>
        </form>
        <?php if (!empty($search)): ?>
        <small class="search-results-text">
            Found <?php echo $total_routes; ?> result<?php echo $total_routes != 1 ? 's' : ''; ?> for "<?php echo htmlspecialchars($search); ?>"
        </small>
        <?php endif; ?>
    </div>
    
    <button onclick="openModal()" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i>
        Add New Route
    </button>
</div>

<!-- Routes List -->
<div class="content-card">
    <div class="card-header-with-count">
        <h3 class="card-title">All Routes</h3>
        <span class="item-count"><?php echo $total_routes; ?> route<?php echo $total_routes != 1 ? 's' : ''; ?></span>
    </div>
    
    <?php if (mysqli_num_rows($routes_result) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Route Code</th>
                    <th>Route Name</th>
                    <th>Company</th>
                    <th>Branch</th>
                    <th>Status</th>
                    <th>Created Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($route = mysqli_fetch_assoc($routes_result)): ?>
                <tr>
                    <td><?php echo $route['id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($route['route_code']); ?></strong></td>
                    <td><?php echo htmlspecialchars($route['route_name']); ?></td>
                    <td>
                        <?php if ($route['company_name']): ?>
                            <span class="entity-tag">
                                <i class="fa-solid fa-building"></i>
                                <?php echo htmlspecialchars($route['company_code'] . ' - ' . $route['company_name']); ?>
                            </span>
                        <?php else: ?>
                            <span style="color: #999;">Not assigned</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($route['branch_name']): ?>
                            <span class="entity-tag">
                                <i class="fa-solid fa-code-branch"></i>
                                <?php echo htmlspecialchars($route['branch_code'] . ' - ' . $route['branch_name']); ?>
                            </span>
                        <?php else: ?>
                            <span style="color: #999;">Not assigned</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($route['active']): ?>
                            <span class="badge badge-success">
                                <i class="fa-solid fa-circle-check"></i> Active
                            </span>
                        <?php else: ?>
                            <span class="badge badge-inactive">
                                <i class="fa-solid fa-circle-xmark"></i> Inactive
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('M d, Y', strtotime($route['created_at'])); ?></td>
                    <td>
                        <div class="action-buttons">
                            <a href="#" 
                               onclick="editRoute(<?php echo $route['id']; ?>)" 
                               class="btn-action btn-edit" 
                               title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <a href="?delete=<?php echo $route['id']; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                               class="btn-action btn-delete" 
                               title="Delete"
                               onclick="return confirm('Are you sure you want to delete this route?')">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <i class="fa-solid fa-route"></i>
        <h3>No Routes Found</h3>
        <p>
            <?php if (!empty($search)): ?>
                No routes match your search criteria. Try a different search term.
            <?php else: ?>
                Create your first route using the "Add New Route" button above.
            <?php endif; ?>
        </p>
        <?php if (!empty($search)): ?>
        <button onclick="clearSearch()" class="btn btn-secondary">
            <i class="fa-solid fa-xmark"></i>
            Clear Search
        </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Modal Dialog -->
<div id="routeModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add New Route</h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        
        <form method="POST" action="" id="routeForm">
            <input type="hidden" name="route_id" id="route_id">
            
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="route_code" class="form-label">
                            Route Code <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="route_code" 
                            name="route_code" 
                            class="form-input" 
                            placeholder="Enter route code (e.g., RT 001)"
                            required
                        >
                        <small class="form-hint" id="route_code_hint">Unique code for the route</small>
                    </div>

                    <div class="form-group">
                        <label for="route_name" class="form-label">
                            Route Name <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="route_name" 
                            name="route_name" 
                            class="form-input" 
                            placeholder="Enter route name"
                            required
                        >
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="company_id" class="form-label">
                            Company
                        </label>
                        <select 
                            id="company_id" 
                            name="company_id" 
                            class="form-select select2"
                        >
                            <option value="">Select Company (Optional)</option>
                            <?php 
                            mysqli_data_seek($companies_result, 0);
                            while ($company = mysqli_fetch_assoc($companies_result)): 
                            ?>
                            <option value="<?php echo $company['id']; ?>">
                                <?php echo htmlspecialchars($company['company_code'] . ' - ' . $company['company_name']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                        <small class="form-hint">Assign route to a company</small>
                    </div>

                    <div class="form-group">
                        <label for="branch_id" class="form-label">
                            Branch
                        </label>
                        <select 
                            id="branch_id" 
                            name="branch_id" 
                            class="form-select select2"
                        >
                            <option value="">Select Branch (Optional)</option>
                            <?php 
                            mysqli_data_seek($branches_result, 0);
                            while ($branch = mysqli_fetch_assoc($branches_result)): 
                            ?>
                            <option value="<?php echo $branch['id']; ?>" data-company="<?php echo $branch['company_id']; ?>">
                                <?php echo htmlspecialchars($branch['branch_code'] . ' - ' . $branch['branch_name']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                        <small class="form-hint">Assign route to a branch</small>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Status</label>
                    <div class="checkbox-wrapper">
                        <label class="switch">
                            <input type="checkbox" name="active" id="active" checked>
                            <span class="slider"></span>
                        </label>
                        <span class="switch-label">Active</span>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">
                    <i class="fa-solid fa-xmark"></i>
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check"></i>
                    <span id="submitBtnText">Save Route</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Include Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
/* Alert Styles */
.alert {
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13px;
    font-weight: 500;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.alert i {
    font-size: 18px;
}

.alert-success {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.alert-error {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

/* Action Bar with Search */
.action-bar {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    margin-bottom: 24px;
}

.search-box {
    flex: 1;
    max-width: 500px;
}

.search-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.search-icon {
    position: absolute;
    left: 16px;
    color: #666666;
    font-size: 14px;
    pointer-events: none;
}

.search-input {
    width: 100%;
    padding: 12px 16px 12px 44px;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    transition: all 0.3s;
    background: #ffffff;
}

.search-input:focus {
    outline: none;
    border-color: #000000;
    box-shadow: 0 0 0 3px rgba(0,0,0,0.05);
}

.clear-search {
    position: absolute;
    right: 12px;
    background: #f0f0f0;
    border: none;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #666666;
    transition: all 0.2s;
}

.clear-search:hover {
    background: #e5e5e5;
    color: #000000;
}

.search-results-text {
    display: block;
    margin-top: 8px;
    font-size: 12px;
    color: #666666;
}

/* Card Header with Count */
.card-header-with-count {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.item-count {
    background: #fafafa;
    color: #666666;
    padding: 6px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid #e5e5e5;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #666666;
}

.empty-state i {
    font-size: 64px;
    color: #e5e5e5;
    margin-bottom: 20px;
}

.empty-state h3 {
    font-size: 18px;
    font-weight: 600;
    color: #333333;
    margin-bottom: 8px;
}

.empty-state p {
    font-size: 14px;
    margin-bottom: 20px;
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
}

/* Button Styles */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    text-decoration: none;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}

.btn i {
    font-size: 16px;
}

.btn-primary {
    background: #000000;
    color: #ffffff;
}

.btn-primary:hover {
    background: #333333;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.btn-secondary {
    background: #f5f5f5;
    color: #333333;
    border: 1px solid #e5e5e5;
}

.btn-secondary:hover {
    background: #e5e5e5;
}

/* Table Styles */
.table-responsive {
    overflow-x: auto;
    margin-top: 20px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.data-table thead {
    background: #fafafa;
    border-bottom: 2px solid #e5e5e5;
}

.data-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: #333333;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.data-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.2s;
}

.data-table tbody tr:hover {
    background: #fafafa;
}

.data-table td {
    padding: 14px 16px;
    color: #333333;
}

/* Entity Tag Styles */
.entity-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    background: #fafafa;
    border: 1px solid #e5e5e5;
    border-radius: 6px;
    font-size: 12px;
    color: #333333;
}

.entity-tag i {
    font-size: 11px;
    color: #666666;
}

/* Badge Styles */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.badge i {
    font-size: 10px;
}

.badge-success {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.badge-inactive {
    background: #fafafa;
    color: #666666;
    border: 1px solid #e5e5e5;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 8px;
}

.btn-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 6px;
    border: 1px solid #e5e5e5;
    background: #ffffff;
    color: #666666;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}

.btn-action:hover {
    transform: translateY(-2px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.btn-edit:hover {
    background: #000000;
    color: #ffffff;
    border-color: #000000;
}

.btn-delete:hover {
    background: #ef4444;
    color: #ffffff;
    border-color: #ef4444;
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: #ffffff;
    border-radius: 12px;
    width: 90%;
    max-width: 700px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

.modal-header {
    padding: 24px;
    border-bottom: 1px solid #e5e5e5;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-title {
    font-size: 18px;
    font-weight: 700;
    color: #000000;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    color: #666666;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: all 0.2s;
}

.modal-close:hover {
    background: #f0f0f0;
    color: #000000;
}

.modal-body {
    padding: 24px;
}

.modal-footer {
    padding: 20px 24px;
    border-top: 1px solid #e5e5e5;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

/* Form Styles */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 8px;
    color: #333333;
}

.required {
    color: #ef4444;
}

.form-input,
.form-select {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    transition: all 0.3s;
    background: #ffffff;
}

.form-input:focus,
.form-select:focus {
    outline: none;
    border-color: #000000;
    box-shadow: 0 0 0 3px rgba(0,0,0,0.05);
}

.form-input:disabled {
    background: #f5f5f5;
    color: #666666;
    cursor: not-allowed;
    opacity: 0.7;
}

.form-input.readonly-field,
.form-input[readonly] {
    background: #f5f5f5;
    color: #666666;
    cursor: not-allowed;
    opacity: 0.7;
}

.form-hint {
    display: block;
    font-size: 11px;
    color: #666666;
    margin-top: 6px;
}

/* Switch Toggle */
.checkbox-wrapper {
    display: flex;
    align-items: center;
    gap: 12px;
}

.switch {
    position: relative;
    display: inline-block;
    width: 48px;
    height: 24px;
}

.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #e5e5e5;
    transition: 0.3s;
    border-radius: 24px;
}

.slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: 0.3s;
    border-radius: 50%;
}

input:checked + .slider {
    background-color: #000000;
}

input:checked + .slider:before {
    transform: translateX(24px);
}

.switch-label {
    font-size: 14px;
    font-weight: 500;
    color: #333333;
}

/* Select2 Custom Styling */
.select2-container--default .select2-selection--single {
    height: 46px !important;
    border: 1px solid #e5e5e5 !important;
    border-radius: 8px !important;
    padding: 8px !important;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 28px !important;
    padding-left: 8px !important;
    color: #333333 !important;
    font-family: 'Inter', sans-serif !important;
    font-size: 14px !important;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 44px !important;
    right: 8px !important;
}

.select2-container--default.select2-container--focus .select2-selection--single {
    border-color: #000000 !important;
    box-shadow: 0 0 0 3px rgba(0,0,0,0.05) !important;
}

.select2-dropdown {
    border: 1px solid #e5e5e5 !important;
    border-radius: 8px !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1) !important;
}

.select2-container--default .select2-results__option--highlighted[aria-selected] {
    background-color: #000000 !important;
}

.select2-search--dropdown .select2-search__field {
    border: 1px solid #e5e5e5 !important;
    border-radius: 6px !important;
    padding: 8px 12px !important;
    font-family: 'Inter', sans-serif !important;
}

/* Responsive */
@media (max-width: 768px) {
    .action-bar {
        flex-direction: column;
    }
    
    .search-box {
        max-width: 100%;
        width: 100%;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .modal-content {
        width: 95%;
        margin: 10px;
    }
    
    .modal-header,
    .modal-body,
    .modal-footer {
        padding: 16px;
    }
    
    .modal-footer {
        flex-direction: column;
    }
    
    .modal-footer .btn {
        width: 100%;
    }
    
    .table-responsive {
        font-size: 12px;
    }
    
    .entity-tag {
        font-size: 11px;
        padding: 3px 8px;
    }
}
</style>

<!-- Include jQuery (required for Select2) -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Include Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
// Initialize Select2
$(document).ready(function() {
    $('.select2').select2({
        placeholder: 'Select an option',
        allowClear: true,
        width: '100%',
        dropdownParent: $('#routeModal')
    });
    
    // Filter branches based on selected company
    $('#company_id').on('change', function() {
        var companyId = $(this).val();
        var branchSelect = $('#branch_id');
        
        // Reset branch selection
        branchSelect.val('').trigger('change');
        
        if (companyId) {
            // Show only branches for selected company
            branchSelect.find('option').each(function() {
                var branchCompany = $(this).data('company');
                if (branchCompany == companyId || $(this).val() == '') {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        } else {
            // Show all branches
            branchSelect.find('option').show();
        }
        
        // Refresh Select2
        branchSelect.select2({
            placeholder: 'Select Branch',
            allowClear: true,
            width: '100%',
            dropdownParent: $('#routeModal')
        });
    });
    
    // Real-time search
    let searchTimeout;
    $('#searchInput').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            $('#searchForm').submit();
        }, 500);
    });
});

function openModal() {
    document.getElementById('routeModal').classList.add('active');
    document.getElementById('routeForm').reset();
    document.getElementById('route_id').value = '';
    document.getElementById('modalTitle').textContent = 'Add New Route';
    document.getElementById('submitBtnText').textContent = 'Save Route';
    
    // Reset Select2
    $('.select2').val('').trigger('change');
}

function closeModal() {
    document.getElementById('routeModal').classList.remove('active');
}

function clearSearch() {
    document.getElementById('searchInput').value = '';
    document.getElementById('searchForm').submit();
}

function editRoute(routeId) {
    // Fetch route data via AJAX
    fetch('get_route.php?id=' + routeId)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert(data.error);
                return;
            }
            
            document.getElementById('routeModal').classList.add('active');
            document.getElementById('route_id').value = data.id;
            document.getElementById('route_code').value = data.route_code;
            document.getElementById('route_name').value = data.route_name;
            document.getElementById('active').checked = data.active == 1;
            
            // Set Select2 values
            $('#company_id').val(data.company_id).trigger('change');
            $('#branch_id').val(data.branch_id).trigger('change');
            
            document.getElementById('modalTitle').textContent = 'Edit Route';
            document.getElementById('submitBtnText').textContent = 'Update Route';
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading route data');
        });
}

// Close modal when clicking outside
document.getElementById('routeModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

// Handle form submission with loading state
document.getElementById('routeForm').addEventListener('submit', function() {
    const submitBtn = document.querySelector('.modal-footer .btn-primary');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
});
</script>

<?php include 'footer.php'; ?>