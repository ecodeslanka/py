<?php
include 'config.php';

// Create vehicles table if not exists
$createVehiclesTable = "CREATE TABLE IF NOT EXISTS vehicles (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    vehicle_number VARCHAR(50) NOT NULL UNIQUE,
    owner VARCHAR(255) NULL,
    driver_name VARCHAR(255) NULL,
    vehicle_make VARCHAR(255) NULL,
    vehicle_model VARCHAR(255) NULL,
    chassis_no VARCHAR(100) NULL,
    engine_no VARCHAR(100) NULL,
    year_of_manufacture VARCHAR(4) NULL,
    size VARCHAR(100) NULL,
    engine_capacity VARCHAR(100) NULL,
    tyre_size VARCHAR(100) NULL,
    wheel_type ENUM('single', 'double') NULL,
    book_copy VARCHAR(255) NULL,
    revenue_licence VARCHAR(255) NULL,
    insurance VARCHAR(255) NULL,
    report_document VARCHAR(255) NULL,
    lorry_image_front VARCHAR(255) NULL,
    lorry_image_rear VARCHAR(255) NULL,
    lorry_image_left VARCHAR(255) NULL,
    lorry_image_right VARCHAR(255) NULL,
    company_id INT(11) NULL,
    branch_id INT(11) NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
)";
mysqli_query($conn, $createVehiclesTable);

// Add driver_name column if it doesn't exist (for existing tables)
$alter_sql = "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS driver_name VARCHAR(255) NULL AFTER owner";
mysqli_query($conn, $alter_sql);

// Add vehicle_make, chassis_no, year_of_manufacture columns if they don't exist (for existing tables)
mysqli_query($conn, "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS vehicle_make VARCHAR(255) NULL AFTER driver_name");
mysqli_query($conn, "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS vehicle_model VARCHAR(255) NULL AFTER vehicle_make");
mysqli_query($conn, "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS chassis_no VARCHAR(100) NULL AFTER vehicle_model");
mysqli_query($conn, "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS engine_no VARCHAR(100) NULL AFTER chassis_no");
mysqli_query($conn, "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS year_of_manufacture VARCHAR(4) NULL AFTER engine_no");
mysqli_query($conn, "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS report_document VARCHAR(255) NULL AFTER insurance");

// Add current_mileage and vehicle_type_id columns if they don't exist (for existing tables)
mysqli_query($conn, "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS current_mileage INT(11) NULL AFTER tyre_size");
mysqli_query($conn, "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS vehicle_type_id INT(11) NULL AFTER wheel_type");

// Make sure the vehicle_types table exists (managed on vehicle_types.php) before we reference it
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_types (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Safety-net: add the FK from vehicles.vehicle_type_id -> vehicle_types.id if missing
function vehAddForeignKeyIfMissing($conn, $constraintName, $alterSql) {
    $r = mysqli_query($conn, "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND CONSTRAINT_NAME = '$constraintName'");
    if ($r && mysqli_num_rows($r) === 0) { mysqli_query($conn, $alterSql); }
}
vehAddForeignKeyIfMissing($conn, 'fk_vehicles_vehicle_type', "ALTER TABLE vehicles ADD CONSTRAINT fk_vehicles_vehicle_type FOREIGN KEY (vehicle_type_id) REFERENCES vehicle_types(id) ON DELETE SET NULL");

// Create uploads directory if not exists
$upload_dir = 'uploads/vehicles/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Allowed file types / max size for uploads
$allowed_doc_ext   = ['pdf', 'jpg', 'jpeg', 'png'];
$allowed_image_ext = ['jpg', 'jpeg', 'png'];
$max_file_size     = 5 * 1024 * 1024; // 5MB

$success_message = null;
$error_message   = null;
$form_errors     = [];

// Handle Delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    // Get vehicle files before deletion
    $get_files_sql = "SELECT * FROM vehicles WHERE id = $id";
    $files_result = mysqli_query($conn, $get_files_sql);
    $vehicle_files = mysqli_fetch_assoc($files_result);

    // Delete vehicle record
    $sql = "DELETE FROM vehicles WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        // Delete associated files
        $file_fields = ['book_copy', 'revenue_licence', 'insurance', 'report_document', 'lorry_image_front', 'lorry_image_rear', 'lorry_image_left', 'lorry_image_right'];
        foreach ($file_fields as $field) {
            if (!empty($vehicle_files[$field]) && file_exists($vehicle_files[$field])) {
                unlink($vehicle_files[$field]);
            }
        }
        $success_message = "Vehicle deleted successfully!";
    } else {
        $error_message = "Error deleting vehicle: " . mysqli_error($conn);
    }
}

// Validate a single uploaded file (returns an error string, or null if OK / not uploaded)
function validateFile($file, $allowed_ext, $max_size, $label) {
    if (empty($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // nothing uploaded, that's fine (optional field)
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return "$label: upload failed (error code {$file['error']}).";
    }
    if ($file['size'] > $max_size) {
        return "$label: file is too large. Maximum allowed size is 5MB.";
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext)) {
        return "$label: invalid file type '.$ext'. Allowed types: " . implode(', ', $allowed_ext) . ".";
    }
    return null;
}

// Handle file upload
function uploadFile($file, $upload_dir, $prefix = '') {
    if (!empty($file) && $file['error'] === UPLOAD_ERR_OK) {
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $file_name = $prefix . '_' . time() . '_' . uniqid() . '.' . $file_ext;
        $file_path = $upload_dir . $file_name;

        if (move_uploaded_file($file['tmp_name'], $file_path)) {
            return $file_path;
        }
    }
    return null;
}

// Handle form submission (Create or Update)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // ---------- Server-side validation ----------
    $vehicle_number_raw = trim($_POST['vehicle_number'] ?? '');
    $year_raw           = trim($_POST['year_of_manufacture'] ?? '');
    $wheel_type_raw     = trim($_POST['wheel_type'] ?? '');
    $mileage_raw        = trim($_POST['current_mileage'] ?? '');
    $vehicle_type_raw   = trim($_POST['vehicle_type_id'] ?? '');

    if ($vehicle_number_raw === '') {
        $form_errors[] = "Vehicle Number is required.";
    }

    if ($year_raw !== '') {
        if (!ctype_digit($year_raw) || strlen($year_raw) !== 4 || (int)$year_raw < 1950 || (int)$year_raw > (int)date('Y') + 1) {
            $form_errors[] = "Year of Manufacture must be a valid 4-digit year.";
        }
    }

    if ($wheel_type_raw !== '' && !in_array($wheel_type_raw, ['single', 'double'])) {
        $form_errors[] = "Wheel Type must be either Single or Double.";
    }

    if ($mileage_raw !== '' && (!ctype_digit($mileage_raw) || (int)$mileage_raw < 0)) {
        $form_errors[] = "Current Mileage must be a valid positive number.";
    }

    // Validate document uploads
    $doc_fields = [
        'book_copy'        => 'Book Copy',
        'revenue_licence'  => 'Revenue Licence',
        'insurance'        => 'Insurance',
        'report_document'  => 'Report',
    ];
    foreach ($doc_fields as $field => $label) {
        $err = validateFile($_FILES[$field] ?? null, $allowed_doc_ext, $max_file_size, $label);
        if ($err) $form_errors[] = $err;
    }

    // Validate image uploads
    $image_fields = [
        'lorry_image_front' => 'Front Image',
        'lorry_image_rear'  => 'Rear Image',
        'lorry_image_left'  => 'Left Image',
        'lorry_image_right' => 'Right Image',
    ];
    foreach ($image_fields as $field => $label) {
        $err = validateFile($_FILES[$field] ?? null, $allowed_image_ext, $max_file_size, $label);
        if ($err) $form_errors[] = $err;
    }

    if (!empty($form_errors)) {
        $error_message = implode(' ', $form_errors);
    } else {
        // ---------- Sanitize inputs ----------
        $vehicle_number      = mysqli_real_escape_string($conn, $vehicle_number_raw);
        $owner               = mysqli_real_escape_string($conn, trim($_POST['owner'] ?? ''));
        $driver_name         = mysqli_real_escape_string($conn, trim($_POST['driver_name'] ?? ''));
        $vehicle_make        = mysqli_real_escape_string($conn, trim($_POST['vehicle_make'] ?? ''));
        $vehicle_model       = mysqli_real_escape_string($conn, trim($_POST['vehicle_model'] ?? ''));
        $chassis_no          = mysqli_real_escape_string($conn, trim($_POST['chassis_no'] ?? ''));
        $engine_no           = mysqli_real_escape_string($conn, trim($_POST['engine_no'] ?? ''));
        $year_of_manufacture = mysqli_real_escape_string($conn, $year_raw);
        $size                = mysqli_real_escape_string($conn, trim($_POST['size'] ?? ''));
        $engine_capacity     = mysqli_real_escape_string($conn, trim($_POST['engine_capacity'] ?? ''));
        $tyre_size           = mysqli_real_escape_string($conn, trim($_POST['tyre_size'] ?? ''));
        $wheel_type          = mysqli_real_escape_string($conn, $wheel_type_raw);
        $current_mileage     = ($mileage_raw !== '') ? intval($mileage_raw) : null;
        $vehicle_type_id     = ($vehicle_type_raw !== '') ? intval($vehicle_type_raw) : null;
        $company_id          = !empty($_POST['company_id']) ? intval($_POST['company_id']) : null;
        $branch_id           = !empty($_POST['branch_id']) ? intval($_POST['branch_id']) : null;
        $active              = isset($_POST['active']) ? 1 : 0;

        if (isset($_POST['vehicle_id']) && !empty($_POST['vehicle_id'])) {
            // Update existing vehicle
            $id = intval($_POST['vehicle_id']);

            // Get existing files
            $get_sql = "SELECT * FROM vehicles WHERE id = $id";
            $get_result = mysqli_query($conn, $get_sql);
            $existing = mysqli_fetch_assoc($get_result);

            // Handle file uploads
            $book_copy          = !empty($_FILES['book_copy']['name']) ? uploadFile($_FILES['book_copy'], $upload_dir, 'book') : $existing['book_copy'];
            $revenue_licence    = !empty($_FILES['revenue_licence']['name']) ? uploadFile($_FILES['revenue_licence'], $upload_dir, 'revenue') : $existing['revenue_licence'];
            $insurance          = !empty($_FILES['insurance']['name']) ? uploadFile($_FILES['insurance'], $upload_dir, 'insurance') : $existing['insurance'];
            $report_document    = !empty($_FILES['report_document']['name']) ? uploadFile($_FILES['report_document'], $upload_dir, 'report') : $existing['report_document'];
            $lorry_image_front  = !empty($_FILES['lorry_image_front']['name']) ? uploadFile($_FILES['lorry_image_front'], $upload_dir, 'front') : $existing['lorry_image_front'];
            $lorry_image_rear   = !empty($_FILES['lorry_image_rear']['name']) ? uploadFile($_FILES['lorry_image_rear'], $upload_dir, 'rear') : $existing['lorry_image_rear'];
            $lorry_image_left   = !empty($_FILES['lorry_image_left']['name']) ? uploadFile($_FILES['lorry_image_left'], $upload_dir, 'left') : $existing['lorry_image_left'];
            $lorry_image_right  = !empty($_FILES['lorry_image_right']['name']) ? uploadFile($_FILES['lorry_image_right'], $upload_dir, 'right') : $existing['lorry_image_right'];

            // Check vehicle number uniqueness against other rows
            $check_sql = "SELECT id FROM vehicles WHERE vehicle_number = '$vehicle_number' AND id != $id";
            $check_result = mysqli_query($conn, $check_sql);

            if (mysqli_num_rows($check_result) > 0) {
                $error_message = "Vehicle number already exists!";
            } else {
                $sql = "UPDATE vehicles SET
                        vehicle_number = '$vehicle_number',
                        owner = '$owner',
                        driver_name = '$driver_name',
                        vehicle_make = '$vehicle_make',
                        vehicle_model = '$vehicle_model',
                        chassis_no = '$chassis_no',
                        engine_no = '$engine_no',
                        year_of_manufacture = '$year_of_manufacture',
                        size = '$size',
                        engine_capacity = '$engine_capacity',
                        tyre_size = '$tyre_size',
                        wheel_type = " . ($wheel_type !== '' ? "'$wheel_type'" : "NULL") . ",
                        current_mileage = " . ($current_mileage !== null ? $current_mileage : "NULL") . ",
                        vehicle_type_id = " . ($vehicle_type_id !== null ? $vehicle_type_id : "NULL") . ",
                        book_copy = '$book_copy',
                        revenue_licence = '$revenue_licence',
                        insurance = '$insurance',
                        report_document = '$report_document',
                        lorry_image_front = '$lorry_image_front',
                        lorry_image_rear = '$lorry_image_rear',
                        lorry_image_left = '$lorry_image_left',
                        lorry_image_right = '$lorry_image_right',
                        company_id = " . ($company_id ? "'$company_id'" : "NULL") . ",
                        branch_id = " . ($branch_id ? "'$branch_id'" : "NULL") . ",
                        active = '$active'
                        WHERE id = $id";

                if (mysqli_query($conn, $sql)) {
                    $success_message = "Vehicle updated successfully!";
                } else {
                    $error_message = "Error: " . mysqli_error($conn);
                }
            }
        } else {
            // Check if vehicle number exists
            $check_sql = "SELECT id FROM vehicles WHERE vehicle_number = '$vehicle_number'";
            $check_result = mysqli_query($conn, $check_sql);

            if (mysqli_num_rows($check_result) > 0) {
                $error_message = "Vehicle number already exists!";
            } else {
                // Handle file uploads
                $book_copy          = uploadFile($_FILES['book_copy'] ?? null, $upload_dir, 'book');
                $revenue_licence    = uploadFile($_FILES['revenue_licence'] ?? null, $upload_dir, 'revenue');
                $insurance          = uploadFile($_FILES['insurance'] ?? null, $upload_dir, 'insurance');
                $report_document    = uploadFile($_FILES['report_document'] ?? null, $upload_dir, 'report');
                $lorry_image_front  = uploadFile($_FILES['lorry_image_front'] ?? null, $upload_dir, 'front');
                $lorry_image_rear   = uploadFile($_FILES['lorry_image_rear'] ?? null, $upload_dir, 'rear');
                $lorry_image_left   = uploadFile($_FILES['lorry_image_left'] ?? null, $upload_dir, 'left');
                $lorry_image_right  = uploadFile($_FILES['lorry_image_right'] ?? null, $upload_dir, 'right');

                $sql = "INSERT INTO vehicles (vehicle_number, owner, driver_name, vehicle_make, vehicle_model, chassis_no, engine_no, year_of_manufacture, size, engine_capacity, tyre_size, wheel_type, current_mileage, vehicle_type_id,
                        book_copy, revenue_licence, insurance, report_document, lorry_image_front, lorry_image_rear,
                        lorry_image_left, lorry_image_right, company_id, branch_id, active)
                        VALUES ('$vehicle_number', '$owner', '$driver_name', '$vehicle_make', '$vehicle_model', '$chassis_no', '$engine_no', '$year_of_manufacture', '$size', '$engine_capacity', '$tyre_size', " . ($wheel_type !== '' ? "'$wheel_type'" : "NULL") . ", " .
                        ($current_mileage !== null ? $current_mileage : "NULL") . ", " .
                        ($vehicle_type_id !== null ? $vehicle_type_id : "NULL") . ",
                        '$book_copy', '$revenue_licence', '$insurance', '$report_document', '$lorry_image_front', '$lorry_image_rear',
                        '$lorry_image_left', '$lorry_image_right', " .
                        ($company_id ? "'$company_id'" : "NULL") . ", " .
                        ($branch_id ? "'$branch_id'" : "NULL") . ", '$active')";

                if (mysqli_query($conn, $sql)) {
                    $success_message = "Vehicle created successfully!";
                } else {
                    $error_message = "Error: " . mysqli_error($conn);
                }
            }
        }
    }
}

// Get all vehicles
$vehicles_sql = "SELECT v.*, c.company_name, c.company_code, b.branch_name, b.branch_code, vt.name AS vehicle_type_name
                 FROM vehicles v
                 LEFT JOIN companies c ON v.company_id = c.id
                 LEFT JOIN branches b ON v.branch_id = b.id
                 LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
                 ORDER BY v.created_at DESC";
$vehicles_result = mysqli_query($conn, $vehicles_sql);

// Get companies for dropdown
$companies_sql = "SELECT id, company_code, company_name FROM companies WHERE active = 1 ORDER BY company_name";
$companies_result = mysqli_query($conn, $companies_sql);

// Get vehicle types for dropdown
$vehicle_types_sql = "SELECT id, name FROM vehicle_types ORDER BY name ASC";
$vehicle_types_result = mysqli_query($conn, $vehicle_types_sql);
$vehicle_types_list = [];
if ($vehicle_types_result) { while ($vt = mysqli_fetch_assoc($vehicle_types_result)) { $vehicle_types_list[] = $vt; } }

include 'header.php';
?>

<!-- Vehicles Page -->
<div class="page-header">
    <h2 class="page-title">Transport Vehicle Management</h2>
    <p class="page-subtitle">Manage all transport vehicles and their documents</p>
</div>

<?php if (isset($success_message) && $success_message): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-circle-check"></i>
    <?php echo $success_message; ?>
</div>
<?php endif; ?>

<?php if (isset($error_message) && $error_message): ?>
<div class="alert alert-error">
    <i class="fa-solid fa-circle-exclamation"></i>
    <?php echo htmlspecialchars($error_message); ?>
</div>
<?php endif; ?>

<!-- Add Vehicle Button -->
<div style="margin-bottom: 20px; display:flex; gap:10px; flex-wrap:wrap;">
    <button onclick="openModal()" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i>
        Add New Vehicle
    </button>
    <a href="vehicle_types.php" class="btn btn-secondary">
        <i class="fa-solid fa-truck"></i>
        Manage Vehicle Types
    </a>
</div>

<!-- Vehicles List -->
<div class="content-card">
    <h3 class="card-title">All Vehicles</h3>

    <?php if (mysqli_num_rows($vehicles_result) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Vehicle Number</th>
                    <th>Owner</th>
                    <th>Driver Name</th>
                    <th>Make</th>
                    <th>Model</th>
                    <th>Chassis No</th>
                    <th>Engine No</th>
                    <th>Year</th>
                    <th>Size</th>
                    <th>Wheel Type</th>
                    <th>Vehicle Type</th>
                    <th>Current Mileage</th>
                    <th>Company</th>
                    <th>Branch</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($vehicle = mysqli_fetch_assoc($vehicles_result)): ?>
                <tr>
                    <td><?php echo $vehicle['id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($vehicle['vehicle_number']); ?></strong></td>
                    <td><?php echo htmlspecialchars($vehicle['owner']) ?: '<span style="color: #999;">-</span>'; ?></td>
                    <td><?php echo htmlspecialchars($vehicle['driver_name']) ?: '<span style="color: #999;">-</span>'; ?></td>
                    <td><?php echo htmlspecialchars($vehicle['vehicle_make']) ?: '<span style="color: #999;">-</span>'; ?></td>
                    <td><?php echo htmlspecialchars($vehicle['vehicle_model']) ?: '<span style="color: #999;">-</span>'; ?></td>
                    <td><?php echo htmlspecialchars($vehicle['chassis_no']) ?: '<span style="color: #999;">-</span>'; ?></td>
                    <td><?php echo htmlspecialchars($vehicle['engine_no']) ?: '<span style="color: #999;">-</span>'; ?></td>
                    <td><?php echo htmlspecialchars($vehicle['year_of_manufacture']) ?: '<span style="color: #999;">-</span>'; ?></td>
                    <td><?php echo htmlspecialchars($vehicle['size']) ?: '<span style="color: #999;">-</span>'; ?></td>
                    <td>
                        <?php if ($vehicle['wheel_type']): ?>
                            <span class="badge badge-wheel">
                                <?php echo ucfirst($vehicle['wheel_type']); ?> Wheel
                            </span>
                        <?php else: ?>
                            <span style="color: #999;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($vehicle['vehicle_type_name'])): ?>
                            <span class="badge badge-type"><?php echo htmlspecialchars($vehicle['vehicle_type_name']); ?></span>
                        <?php else: ?>
                            <span style="color: #999;">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo ($vehicle['current_mileage'] !== null) ? number_format($vehicle['current_mileage']) . ' km' : '<span style="color: #999;">-</span>'; ?></td>
                    <td>
                        <?php if ($vehicle['company_name']): ?>
                            <small><?php echo htmlspecialchars($vehicle['company_code']); ?></small>
                        <?php else: ?>
                            <span style="color: #999;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($vehicle['branch_name']): ?>
                            <small><?php echo htmlspecialchars($vehicle['branch_code']); ?></small>
                        <?php else: ?>
                            <span style="color: #999;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($vehicle['active']): ?>
                            <span class="badge badge-success">
                                <i class="fa-solid fa-circle-check"></i> Active
                            </span>
                        <?php else: ?>
                            <span class="badge badge-inactive">
                                <i class="fa-solid fa-circle-xmark"></i> Inactive
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="view_vehicle.php?id=<?php echo $vehicle['id']; ?>"
                               class="btn-action btn-view"
                               title="View Details">
                                <i class="fa-solid fa-eye"></i>
                            </a>
                            <a href="#"
                               onclick="editVehicle(<?php echo $vehicle['id']; ?>)"
                               class="btn-action btn-edit"
                               title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <a href="?delete=<?php echo $vehicle['id']; ?>"
                               class="btn-action btn-delete"
                               title="Delete"
                               onclick="return confirm('Are you sure you want to delete this vehicle?')">
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
    <p style="color: #666; font-size: 13px; text-align: center; padding: 20px;">No vehicles found. Create your first vehicle using the button above.</p>
    <?php endif; ?>
</div>

<!-- Modal Dialog -->
<div id="vehicleModal" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add New Vehicle</h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" id="vehicleForm" enctype="multipart/form-data" onsubmit="return validateVehicleForm()">
            <input type="hidden" name="vehicle_id" id="vehicle_id">

            <div class="modal-body">
                <!-- Client-side validation summary -->
                <div id="formErrorBox" class="alert alert-error" style="display:none;"></div>

                <!-- Vehicle Information -->
                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-truck"></i> Vehicle Information</h4>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="vehicle_number" class="form-label">
                                Vehicle Number <span class="required">*</span>
                            </label>
                            <input
                                type="text"
                                id="vehicle_number"
                                name="vehicle_number"
                                class="form-input"
                                placeholder="Enter vehicle number"
                                required
                            >
                            <small class="form-error" id="err_vehicle_number"></small>
                        </div>

                        <div class="form-group">
                            <label for="owner" class="form-label">
                                Owner
                            </label>
                            <input
                                type="text"
                                id="owner"
                                name="owner"
                                class="form-input"
                                placeholder="Enter owner name"
                            >
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="driver_name" class="form-label">
                                Driver Name
                            </label>
                            <input
                                type="text"
                                id="driver_name"
                                name="driver_name"
                                class="form-input"
                                placeholder="Enter driver name"
                            >
                        </div>

                        <div class="form-group">
                            <label for="vehicle_make" class="form-label">
                                Vehicle Make
                            </label>
                            <input
                                type="text"
                                id="vehicle_make"
                                name="vehicle_make"
                                class="form-input"
                                placeholder="e.g. Tata, Isuzu, Mitsubishi"
                            >
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="vehicle_model" class="form-label">
                                Vehicle Model
                            </label>
                            <input
                                type="text"
                                id="vehicle_model"
                                name="vehicle_model"
                                class="form-input"
                                placeholder="e.g. LPT 709, Canter, FE Series"
                            >
                        </div>

                        <div class="form-group">
                            <label for="year_of_manufacture" class="form-label">
                                Year of Manufacture
                            </label>
                            <input
                                type="number"
                                id="year_of_manufacture"
                                name="year_of_manufacture"
                                class="form-input"
                                placeholder="e.g. 2018"
                                min="1950"
                                max="2100"
                            >
                            <small class="form-error" id="err_year_of_manufacture"></small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="chassis_no" class="form-label">
                                Chassis No
                            </label>
                            <input
                                type="text"
                                id="chassis_no"
                                name="chassis_no"
                                class="form-input"
                                placeholder="Enter chassis number"
                            >
                        </div>

                        <div class="form-group">
                            <label for="engine_no" class="form-label">
                                Engine No
                            </label>
                            <input
                                type="text"
                                id="engine_no"
                                name="engine_no"
                                class="form-input"
                                placeholder="Enter engine number"
                            >
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="size" class="form-label">
                                Size
                            </label>
                            <input
                                type="text"
                                id="size"
                                name="size"
                                class="form-input"
                                placeholder="Enter vehicle size"
                            >
                        </div>

                        <div class="form-group">
                            <label for="engine_capacity" class="form-label">
                                Engine Capacity
                            </label>
                            <input
                                type="text"
                                id="engine_capacity"
                                name="engine_capacity"
                                class="form-input"
                                placeholder="Enter engine capacity"
                            >
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="tyre_size" class="form-label">
                                Tyre Size
                            </label>
                            <input
                                type="text"
                                id="tyre_size"
                                name="tyre_size"
                                class="form-input"
                                placeholder="Enter tyre size"
                            >
                        </div>

                        <div class="form-group">
                            <label for="wheel_type" class="form-label">
                                Wheel Type
                            </label>
                            <select id="wheel_type" name="wheel_type" class="form-input">
                                <option value="">Select One Option</option>
                                <option value="single">Single Wheel</option>
                                <option value="double">Double Wheel</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="vehicle_type_id" class="form-label">
                                Vehicle Type
                            </label>
                            <select id="vehicle_type_id" name="vehicle_type_id" class="form-input select2-vehicle-type">
                                <option value="">Select Vehicle Type (optional)</option>
                                <?php foreach ($vehicle_types_list as $vt): ?>
                                    <option value="<?php echo $vt['id']; ?>"><?php echo htmlspecialchars($vt['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-hint">Optional. Manage the list of types via "Manage Vehicle Types".</small>
                        </div>

                        <div class="form-group">
                            <label for="current_mileage" class="form-label">
                                Current Mileage (KM)
                            </label>
                            <input
                                type="number"
                                id="current_mileage"
                                name="current_mileage"
                                class="form-input"
                                placeholder="e.g. 125000"
                                min="0"
                            >
                            <small class="form-error" id="err_current_mileage"></small>
                        </div>
                    </div>
                </div>

                <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e5e5;">

                <!-- Documents -->
                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-file-lines"></i> Documents (Scan Copy)</h4>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="book_copy" class="form-label">
                                Book Copy
                            </label>
                            <input
                                type="file"
                                id="book_copy"
                                name="book_copy"
                                class="form-input file-input"
                                accept=".pdf,.jpg,.jpeg,.png"
                            >
                            <small class="form-hint">PDF, JPG, PNG (Max 5MB)</small>
                            <small class="form-error" id="err_book_copy"></small>
                        </div>

                        <div class="form-group">
                            <label for="revenue_licence" class="form-label">
                                Revenue Licence
                            </label>
                            <input
                                type="file"
                                id="revenue_licence"
                                name="revenue_licence"
                                class="form-input file-input"
                                accept=".pdf,.jpg,.jpeg,.png"
                            >
                            <small class="form-hint">PDF, JPG, PNG (Max 5MB)</small>
                            <small class="form-error" id="err_revenue_licence"></small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="insurance" class="form-label">
                                Insurance
                            </label>
                            <input
                                type="file"
                                id="insurance"
                                name="insurance"
                                class="form-input file-input"
                                accept=".pdf,.jpg,.jpeg,.png"
                            >
                            <small class="form-hint">PDF, JPG, PNG (Max 5MB)</small>
                            <small class="form-error" id="err_insurance"></small>
                        </div>

                        <div class="form-group">
                            <label for="report_document" class="form-label">
                                Report
                            </label>
                            <input
                                type="file"
                                id="report_document"
                                name="report_document"
                                class="form-input file-input"
                                accept=".pdf,.jpg,.jpeg,.png"
                            >
                            <small class="form-hint">PDF, JPG, PNG (Max 5MB)</small>
                            <small class="form-error" id="err_report_document"></small>
                        </div>
                    </div>
                </div>

                <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e5e5;">

                <!-- Lorry Images -->
                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-images"></i> Lorry Images</h4>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="lorry_image_front" class="form-label">
                                Front
                            </label>
                            <input
                                type="file"
                                id="lorry_image_front"
                                name="lorry_image_front"
                                class="form-input file-input"
                                accept=".jpg,.jpeg,.png"
                            >
                            <small class="form-error" id="err_lorry_image_front"></small>
                        </div>

                        <div class="form-group">
                            <label for="lorry_image_rear" class="form-label">
                                Rear
                            </label>
                            <input
                                type="file"
                                id="lorry_image_rear"
                                name="lorry_image_rear"
                                class="form-input file-input"
                                accept=".jpg,.jpeg,.png"
                            >
                            <small class="form-error" id="err_lorry_image_rear"></small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="lorry_image_left" class="form-label">
                                Left Side
                            </label>
                            <input
                                type="file"
                                id="lorry_image_left"
                                name="lorry_image_left"
                                class="form-input file-input"
                                accept=".jpg,.jpeg,.png"
                            >
                            <small class="form-error" id="err_lorry_image_left"></small>
                        </div>

                        <div class="form-group">
                            <label for="lorry_image_right" class="form-label">
                                Right Side
                            </label>
                            <input
                                type="file"
                                id="lorry_image_right"
                                name="lorry_image_right"
                                class="form-input file-input"
                                accept=".jpg,.jpeg,.png"
                            >
                            <small class="form-error" id="err_lorry_image_right"></small>
                        </div>
                    </div>
                </div>

                <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e5e5;">

                <!-- Company & Branch -->
                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-building"></i> Company & Branch Assignment</h4>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="company_id" class="form-label">
                                Company
                            </label>
                            <select id="company_id" name="company_id" class="form-input" onchange="loadVehicleBranches()">
                                <option value="">Select Company</option>
                                <?php
                                mysqli_data_seek($companies_result, 0);
                                while ($company = mysqli_fetch_assoc($companies_result)):
                                ?>
                                    <option value="<?php echo $company['id']; ?>">
                                        <?php echo htmlspecialchars($company['company_code'] . ' - ' . $company['company_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="branch_id" class="form-label">
                                Branch
                            </label>
                            <select id="branch_id" name="branch_id" class="form-input">
                                <option value="">Select Branch</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <div class="checkbox-wrapper">
                            <label class="checkbox-label">
                                <input
                                    type="checkbox"
                                    name="active"
                                    id="active"
                                    class="form-checkbox"
                                    checked
                                >
                                <span class="checkbox-text">Active</span>
                            </label>
                        </div>
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
                    <span id="submitBtnText">Save Vehicle</span>
                </button>
            </div>
        </form>
    </div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

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

.form-error {
    display: block;
    font-size: 11px;
    color: #dc2626;
    margin-top: 6px;
    min-height: 14px;
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 10001;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    animation: fadeIn 0.3s;
}

.modal.active {
    display: flex;
    align-items: flex-start;
    justify-content: center;
    overflow-y: auto;
    padding: 80px 20px 60px;
    box-sizing: border-box;
}

.modal-content {
    background: #ffffff;
    border-radius: 12px;
    width: 90%;
    max-width: 800px;
    max-height: none;
    overflow-y: visible;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    animation: slideDown 0.3s;
    margin-bottom: 20px;
}

.modal-large {
    max-width: 900px;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 24px;
    border-bottom: 1px solid #e5e5e5;
}

.modal-title {
    font-size: 18px;
    font-weight: 600;
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666666;
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
    background: #f5f5f5;
    color: #000000;
}

.modal-body {
    padding: 24px;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 20px 24px;
    border-top: 1px solid #e5e5e5;
}

/* Form Section */
.form-section {
    margin-bottom: 24px;
}

.section-title {
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 16px;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Form Styles */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
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

.form-input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    transition: all 0.3s;
    background: #ffffff;
}

.form-input:focus {
    outline: none;
    border-color: #000000;
    box-shadow: 0 0 0 3px rgba(0,0,0,0.05);
}

.form-input.input-error {
    border-color: #dc2626;
}

.file-input {
    padding: 10px 12px;
}

.form-hint {
    display: block;
    font-size: 11px;
    color: #666666;
    margin-top: 6px;
}

select.form-input {
    cursor: pointer;
}

/* Checkbox Styles */
.checkbox-wrapper {
    display: flex;
    align-items: center;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    font-size: 14px;
    color: #333333;
}

.form-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: #000000;
}

.checkbox-text {
    font-weight: 500;
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
    white-space: nowrap;
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
    white-space: nowrap;
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
    white-space: nowrap;
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

.badge-wheel {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
}

.badge-type {
    background: #eff6ff;
    color: #1e40af;
    border: 1px solid #bfdbfe;
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

.btn-view:hover {
    background: #3b82f6;
    color: #ffffff;
    border-color: #3b82f6;
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

/* Responsive */
@media (max-width: 768px) {
    .modal-content {
        width: 95%;
    }

    .form-row {
        grid-template-columns: 1fr;
    }

    .modal-footer {
        flex-direction: column;
    }

    .modal-footer .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<script>
const ALLOWED_DOC_EXT   = ['pdf', 'jpg', 'jpeg', 'png'];
const ALLOWED_IMAGE_EXT = ['jpg', 'jpeg', 'png'];
const MAX_FILE_SIZE     = 5 * 1024 * 1024; // 5MB

function openModal() {
    document.getElementById('vehicleModal').classList.add('active');
    document.getElementById('vehicleForm').reset();
    document.getElementById('vehicle_id').value = '';
    document.getElementById('modalTitle').textContent = 'Add New Vehicle';
    document.getElementById('submitBtnText').textContent = 'Save Vehicle';
    clearFormErrors();
    if (window.jQuery) { $('.select2-vehicle-type').val('').trigger('change'); }
}

function closeModal() {
    document.getElementById('vehicleModal').classList.remove('active');
    clearFormErrors();
}

function clearFormErrors() {
    document.querySelectorAll('.form-error').forEach(el => el.textContent = '');
    document.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));
    const box = document.getElementById('formErrorBox');
    box.style.display = 'none';
    box.textContent = '';
}

function setFieldError(fieldId, message) {
    const errEl = document.getElementById('err_' + fieldId);
    const inputEl = document.getElementById(fieldId);
    if (errEl) errEl.textContent = message;
    if (inputEl) inputEl.classList.add('input-error');
}

function validateFileField(fieldId, label, allowedExt) {
    const input = document.getElementById(fieldId);
    if (!input || !input.files || input.files.length === 0) return null;

    const file = input.files[0];
    const ext = file.name.split('.').pop().toLowerCase();

    if (file.size > MAX_FILE_SIZE) {
        return label + ': file is too large. Maximum allowed size is 5MB.';
    }
    if (!allowedExt.includes(ext)) {
        return label + ': invalid file type. Allowed types: ' + allowedExt.join(', ') + '.';
    }
    return null;
}

function validateVehicleForm() {
    clearFormErrors();
    let errors = [];

    // Vehicle Number required
    const vehicleNumber = document.getElementById('vehicle_number').value.trim();
    if (!vehicleNumber) {
        setFieldError('vehicle_number', 'Vehicle Number is required.');
        errors.push('Vehicle Number is required.');
    }

    // Year of Manufacture (optional, but must be valid if provided)
    const year = document.getElementById('year_of_manufacture').value.trim();
    if (year) {
        const yearNum = parseInt(year, 10);
        const currentYear = new Date().getFullYear();
        if (!/^\d{4}$/.test(year) || yearNum < 1950 || yearNum > currentYear + 1) {
            setFieldError('year_of_manufacture', 'Enter a valid 4-digit year.');
            errors.push('Year of Manufacture must be a valid 4-digit year.');
        }
    }

    // Current Mileage (optional, but must be a valid non-negative number if provided)
    const mileage = document.getElementById('current_mileage').value.trim();
    if (mileage) {
        const mileageNum = parseInt(mileage, 10);
        if (!/^\d+$/.test(mileage) || mileageNum < 0) {
            setFieldError('current_mileage', 'Enter a valid mileage in KM.');
            errors.push('Current Mileage must be a valid positive number.');
        }
    }

    // Document uploads
    const docFields = {
        book_copy: 'Book Copy',
        revenue_licence: 'Revenue Licence',
        insurance: 'Insurance',
        report_document: 'Report'
    };
    for (const [fieldId, label] of Object.entries(docFields)) {
        const err = validateFileField(fieldId, label, ALLOWED_DOC_EXT);
        if (err) {
            setFieldError(fieldId, err);
            errors.push(err);
        }
    }

    // Image uploads
    const imageFields = {
        lorry_image_front: 'Front Image',
        lorry_image_rear: 'Rear Image',
        lorry_image_left: 'Left Image',
        lorry_image_right: 'Right Image'
    };
    for (const [fieldId, label] of Object.entries(imageFields)) {
        const err = validateFileField(fieldId, label, ALLOWED_IMAGE_EXT);
        if (err) {
            setFieldError(fieldId, err);
            errors.push(err);
        }
    }

    if (errors.length > 0) {
        const box = document.getElementById('formErrorBox');
        box.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + errors.join(' ');
        box.style.display = 'flex';
        box.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return false;
    }

    return true;
}

function loadVehicleBranches() {
    const companyId = document.getElementById('company_id').value;
    const branchSelect = document.getElementById('branch_id');

    branchSelect.innerHTML = '<option value="">Select Branch</option>';

    if (companyId) {
        fetch('get_branches.php?company_id=' + companyId)
            .then(response => response.json())
            .then(data => {
                data.forEach(branch => {
                    const option = document.createElement('option');
                    option.value = branch.id;
                    option.textContent = branch.branch_code + ' - ' + branch.branch_name;
                    branchSelect.appendChild(option);
                });
            });
    }
}

function editVehicle(vehicleId) {
    fetch('get_vehicle.php?id=' + vehicleId)
        .then(response => response.json())
        .then(data => {
            document.getElementById('vehicleModal').classList.add('active');
            clearFormErrors();
            document.getElementById('vehicle_id').value = data.id;
            document.getElementById('vehicle_number').value = data.vehicle_number;
            document.getElementById('owner').value = data.owner || '';
            document.getElementById('driver_name').value = data.driver_name || '';
            document.getElementById('vehicle_make').value = data.vehicle_make || '';
            document.getElementById('vehicle_model').value = data.vehicle_model || '';
            document.getElementById('chassis_no').value = data.chassis_no || '';
            document.getElementById('engine_no').value = data.engine_no || '';
            document.getElementById('year_of_manufacture').value = data.year_of_manufacture || '';
            document.getElementById('size').value = data.size || '';
            document.getElementById('engine_capacity').value = data.engine_capacity || '';
            document.getElementById('tyre_size').value = data.tyre_size || '';
            document.getElementById('wheel_type').value = data.wheel_type || '';
            document.getElementById('current_mileage').value = data.current_mileage || '';
            document.getElementById('company_id').value = data.company_id || '';
            document.getElementById('active').checked = data.active == 1;

            if (window.jQuery) {
                $('#vehicle_type_id').val(data.vehicle_type_id || '').trigger('change');
            } else {
                document.getElementById('vehicle_type_id').value = data.vehicle_type_id || '';
            }

            if (data.company_id) {
                loadVehicleBranches();
                setTimeout(() => {
                    document.getElementById('branch_id').value = data.branch_id || '';
                }, 500);
            }

            document.getElementById('modalTitle').textContent = 'Edit Vehicle';
            document.getElementById('submitBtnText').textContent = 'Update Vehicle';
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading vehicle data');
        });
}

document.addEventListener('DOMContentLoaded', function() {
    if (window.jQuery && $.fn.select2) {
        $('.select2-vehicle-type').select2({ width: '100%', placeholder: 'Select Vehicle Type (optional)', dropdownParent: $('#vehicleModal'), allowClear: true });
    }
});
</script>

<?php include 'footer.php'; ?>