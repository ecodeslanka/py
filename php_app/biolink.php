<?php
include 'config.php';

// Create fingerprint_machines table if not exists
$createTable = "CREATE TABLE IF NOT EXISTS fingerprint_machines (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    branch_id INT(11) NOT NULL,
    machine_name VARCHAR(255) NOT NULL,
    machine_code VARCHAR(50) NOT NULL UNIQUE,
    ip_address VARCHAR(45) NOT NULL,
    mac_address VARCHAR(17) NULL,
    port INT(5) DEFAULT 80,
    username VARCHAR(100) DEFAULT 'admin',
    password VARCHAR(255) DEFAULT 'admin12345',
    model VARCHAR(100) DEFAULT 'DS-K1T804BMF',
    location_desc VARCHAR(255) NULL,
    last_ping TIMESTAMP NULL,
    last_sync TIMESTAMP NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    INDEX idx_branch_id (branch_id),
    INDEX idx_ip_address (ip_address)
)";
mysqli_query($conn, $createTable);

// Handle Delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $sql = "DELETE FROM fingerprint_machines WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        $success_message = "Machine deleted successfully!";
    } else {
        $error_message = "Error deleting machine: " . mysqli_error($conn);
    }
}

// Handle Test Connection (AJAX)
if (isset($_GET['test_connection'])) {
    $id = intval($_GET['test_connection']);
    $machine_sql = "SELECT * FROM fingerprint_machines WHERE id = $id";
    $machine_result = mysqli_query($conn, $machine_sql);
    $machine = mysqli_fetch_assoc($machine_result);

    if ($machine) {
        $ip       = $machine['ip_address'];
        $port     = $machine['port'];
        $user     = $machine['username'];
        $pass     = $machine['password'];

        $url = "http://$ip:$port/ISAPI/System/deviceInfo";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HTTPAUTH        => CURLAUTH_DIGEST,
            CURLOPT_USERPWD         => "$user:$pass",
            CURLOPT_TIMEOUT         => 8,
            CURLOPT_CONNECTTIMEOUT  => 5,

            // ✅ Fix: HTTP/0.9 error — force HTTP 1.1 and set required headers
            CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER      => [
                'Accept: application/xml, text/xml, */*',
                'Content-Type: application/xml',
                'Connection: close',
            ],

            // ✅ Fix: Follow redirects in case device redirects port 80
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,

            // ✅ Fix: Ignore SSL errors if device uses HTTPS on port 443
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYHOST  => false,
        ]);

        $response = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // ✅ Accept 200 or 401 — both mean device is ONLINE
        // 401 = device responded but needs auth (still reachable!)
        if (in_array($httpCode, [200, 401]) || (!$curlError && $response)) {
            mysqli_query($conn, "UPDATE fingerprint_machines SET last_ping = NOW() WHERE id = $id");
            $msg = $httpCode == 401
                ? 'Device is online! (Check username/password — got 401 Unauthorized)'
                : 'Connection successful! Device is online.';
            echo json_encode(['status' => 'success', 'message' => $msg, 'http_code' => $httpCode]);
        } else {
            $errMsg = $curlError ? $curlError : "HTTP $httpCode - Device unreachable";
            echo json_encode(['status' => 'error', 'message' => 'Connection failed: ' . $errMsg]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Machine not found.']);
    }
    exit;
}

// Handle Auto-Discover by MAC (AJAX)
if (isset($_GET['discover_ip'])) {
    $mac = strtolower(trim($_GET['mac']));
    $arpTable = shell_exec('arp -a 2>&1');
    $lines = explode("\n", $arpTable);
    $found_ip = null;
    foreach ($lines as $line) {
        if (stripos($line, $mac) !== false) {
            preg_match('/(\d+\.\d+\.\d+\.\d+)/', $line, $matches);
            if (!empty($matches[1])) {
                $found_ip = $matches[1];
                break;
            }
        }
    }
    if ($found_ip) {
        echo json_encode(['status' => 'success', 'ip' => $found_ip, 'message' => "Device found at $found_ip"]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Device not found on network. Make sure the device is powered on.']);
    }
    exit;
}

// Handle form submission (Create or Update)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $branch_id      = intval($_POST['branch_id']);
    $machine_name   = mysqli_real_escape_string($conn, $_POST['machine_name']);
    $machine_code   = mysqli_real_escape_string($conn, strtoupper($_POST['machine_code']));
    $ip_address     = mysqli_real_escape_string($conn, $_POST['ip_address']);
    $mac_address    = mysqli_real_escape_string($conn, $_POST['mac_address']);
    $port           = intval($_POST['port']) ?: 80;
    $username       = mysqli_real_escape_string($conn, $_POST['username']);
    $password       = mysqli_real_escape_string($conn, $_POST['password']);
    $model          = mysqli_real_escape_string($conn, $_POST['model']);
    $location_desc  = mysqli_real_escape_string($conn, $_POST['location_desc']);
    $active         = isset($_POST['active']) ? 1 : 0;

    if (isset($_POST['machine_id']) && !empty($_POST['machine_id'])) {
        $id = intval($_POST['machine_id']);
        $sql = "UPDATE fingerprint_machines SET
                    branch_id = '$branch_id',
                    machine_name = '$machine_name',
                    machine_code = '$machine_code',
                    ip_address = '$ip_address',
                    mac_address = '$mac_address',
                    port = '$port',
                    username = '$username',
                    password = '$password',
                    model = '$model',
                    location_desc = '$location_desc',
                    active = '$active'
                WHERE id = $id";
        if (mysqli_query($conn, $sql)) {
            $success_message = "Machine updated successfully!";
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    } else {
        $check_sql = "SELECT id FROM fingerprint_machines WHERE machine_code = '$machine_code'";
        $check_result = mysqli_query($conn, $check_sql);
        if (mysqli_num_rows($check_result) > 0) {
            $error_message = "Machine code already exists. Please use a different code.";
        } else {
            $sql = "INSERT INTO fingerprint_machines 
                        (branch_id, machine_name, machine_code, ip_address, mac_address, port, username, password, model, location_desc, active)
                    VALUES 
                        ('$branch_id', '$machine_name', '$machine_code', '$ip_address', '$mac_address', '$port', '$username', '$password', '$model', '$location_desc', '$active')";
            if (mysqli_query($conn, $sql)) {
                $success_message = "Machine added successfully!";
            } else {
                $error_message = "Error: " . mysqli_error($conn);
            }
        }
    }
}

// Get all machines with branch names
$machines_sql = "SELECT fm.*, b.branch_name, b.branch_code
                 FROM fingerprint_machines fm
                 LEFT JOIN branches b ON fm.branch_id = b.id
                 ORDER BY fm.created_at DESC";
$machines_result = mysqli_query($conn, $machines_sql);

// Get all active branches for dropdown
$branches_sql = "SELECT id, branch_code, branch_name FROM branches WHERE active = 1 ORDER BY branch_name ASC";
$branches_result = mysqli_query($conn, $branches_sql);

include 'header.php';
?>

<!-- Fingerprint Machines Page -->
<div class="page-header">
    <h2 class="page-title">Fingerprint Machines</h2>
    <p class="page-subtitle">Manage DS-K1T804BMF device connections</p>
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

<!-- Connection Alert (shown by JS) -->
<div id="connectionAlert" class="alert" style="display:none;">
    <i class="fa-solid fa-circle-info" id="connectionAlertIcon"></i>
    <span id="connectionAlertMsg"></span>
</div>

<!-- Add Machine Button -->
<div style="margin-bottom: 20px;">
    <button onclick="openModal()" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i>
        Add New Machine
    </button>
</div>

<!-- Machines List -->
<div class="content-card">
    <h3 class="card-title">
        <i class="fa-solid fa-fingerprint" style="margin-right:8px;"></i>
        All Fingerprint Machines
    </h3>

    <?php if (mysqli_num_rows($machines_result) > 0): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Branch</th>
                    <th>Machine</th>
                    <th>IP Address</th>
                    <th>MAC Address</th>
                    <th>Model</th>
                    <th>Last Ping</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($machine = mysqli_fetch_assoc($machines_result)): ?>
                <tr id="row-<?php echo $machine['id']; ?>">
                    <td><?php echo $machine['id']; ?></td>
                    <td>
                        <strong><?php echo htmlspecialchars($machine['branch_code']); ?></strong><br>
                        <small style="color: #666;"><?php echo htmlspecialchars($machine['branch_name']); ?></small>
                    </td>
                    <td>
                        <strong><?php echo htmlspecialchars($machine['machine_code']); ?></strong><br>
                        <small style="color: #666;"><?php echo htmlspecialchars($machine['machine_name']); ?></small>
                        <?php if ($machine['location_desc']): ?>
                            <br><small style="color:#999;"><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($machine['location_desc']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <code class="ip-badge"><?php echo htmlspecialchars($machine['ip_address']); ?>:<?php echo $machine['port']; ?></code>
                    </td>
                    <td>
                        <?php if ($machine['mac_address']): ?>
                            <code style="font-size:11px; color:#666;"><?php echo htmlspecialchars($machine['mac_address']); ?></code>
                        <?php else: ?>
                            <span style="color:#999;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-model">
                            <i class="fa-solid fa-microchip"></i>
                            <?php echo htmlspecialchars($machine['model']); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($machine['last_ping']): ?>
                            <small style="color:#166534;"><i class="fa-solid fa-clock"></i> <?php echo date('M d, H:i', strtotime($machine['last_ping'])); ?></small>
                        <?php else: ?>
                            <small style="color:#999;">Never</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($machine['active']): ?>
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
                            <!-- Test Connection -->
                            <button 
                                onclick="testConnection(<?php echo $machine['id']; ?>, this)" 
                                class="btn-action btn-test" 
                                title="Test Connection">
                                <i class="fa-solid fa-plug"></i>
                            </button>
                            <!-- Auto-Discover IP (only if has MAC) -->
                            <?php if ($machine['mac_address']): ?>
                            <button 
                                onclick="discoverIp(<?php echo $machine['id']; ?>, '<?php echo htmlspecialchars($machine['mac_address']); ?>', this)" 
                                class="btn-action btn-discover" 
                                title="Auto-Discover IP by MAC">
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </button>
                            <?php endif; ?>
                            <!-- Edit -->
                            <a href="#" 
                               onclick="editMachine(<?php echo htmlspecialchars(json_encode($machine)); ?>)" 
                               class="btn-action btn-edit" 
                               title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <!-- Delete -->
                            <a href="?delete=<?php echo $machine['id']; ?>" 
                               class="btn-action btn-delete" 
                               title="Delete"
                               onclick="return confirm('Are you sure you want to delete this machine?')">
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
    <p style="color: #666; font-size: 13px; text-align: center; padding: 40px 20px;">
        <i class="fa-solid fa-fingerprint" style="font-size:32px; display:block; margin-bottom:12px; color:#ccc;"></i>
        No machines found. Add your first DS-K1T804BMF device using the button above.
    </p>
    <?php endif; ?>
</div>

<!-- Modal Dialog -->
<div id="machineModal" class="modal">
    <div class="modal-content" style="max-width: 680px;">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">
                <i class="fa-solid fa-fingerprint" style="margin-right:8px;"></i>
                Add New Machine
            </h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" id="machineForm">
            <input type="hidden" name="machine_id" id="machine_id">

            <div class="modal-body">

                <!-- Section: Branch & Identity -->
                <div class="form-section-title">
                    <i class="fa-solid fa-building"></i> Branch & Identity
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="branch_id" class="form-label">
                            Branch <span class="required">*</span>
                        </label>
                        <select id="branch_id" name="branch_id" class="form-input" required>
                            <option value="">Select Branch</option>
                            <?php
                            mysqli_data_seek($branches_result, 0);
                            while ($branch = mysqli_fetch_assoc($branches_result)):
                            ?>
                                <option value="<?php echo $branch['id']; ?>">
                                    <?php echo htmlspecialchars($branch['branch_code'] . ' - ' . $branch['branch_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="machine_code" class="form-label">
                            Machine Code <span class="required">*</span>
                        </label>
                        <input type="text" id="machine_code" name="machine_code" class="form-input"
                            placeholder="e.g., FP001" required>
                        <small class="form-hint">Unique identifier for this machine</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="machine_name" class="form-label">
                        Machine Name <span class="required">*</span>
                    </label>
                    <input type="text" id="machine_name" name="machine_name" class="form-input"
                        placeholder="e.g., Main Entrance Fingerprint Reader" required>
                </div>

                <div class="form-group">
                    <label for="location_desc" class="form-label">
                        Location Description <span class="optional">(Optional)</span>
                    </label>
                    <input type="text" id="location_desc" name="location_desc" class="form-input"
                        placeholder="e.g., Ground Floor - Front Door">
                </div>

                <!-- Section: Network Settings -->
                <div class="form-section-title" style="margin-top:20px;">
                    <i class="fa-solid fa-network-wired"></i> Network Settings
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="ip_address" class="form-label">
                            IP Address <span class="required">*</span>
                        </label>
                        <input type="text" id="ip_address" name="ip_address" class="form-input"
                            placeholder="e.g., 192.168.1.64"
                            pattern="^(\d{1,3}\.){3}\d{1,3}$"
                            title="Enter a valid IP address"
                            required>
                        <small class="form-hint">
                            <i class="fa-solid fa-circle-info"></i>
                            Set a <strong>static IP</strong> on the device to avoid changes after power cuts
                        </small>
                    </div>
                    <div class="form-group">
                        <label for="port" class="form-label">Port</label>
                        <input type="number" id="port" name="port" class="form-input"
                            value="80" min="1" max="65535" placeholder="80">
                        <small class="form-hint">Default is 80 (HTTP)</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="mac_address" class="form-label">
                        MAC Address <span class="optional">(Optional but recommended)</span>
                    </label>
                    <input type="text" id="mac_address" name="mac_address" class="form-input"
                        placeholder="e.g., aa:bb:cc:dd:ee:ff"
                        pattern="^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$"
                        title="Enter MAC address in format aa:bb:cc:dd:ee:ff">
                    <small class="form-hint">
                        <i class="fa-solid fa-circle-info"></i>
                        Found on the device label. Used for <strong>auto-discover</strong> if IP changes after power cut
                    </small>
                </div>

                <!-- Section: Authentication -->
                <div class="form-section-title" style="margin-top:20px;">
                    <i class="fa-solid fa-lock"></i> Device Authentication
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" id="username" name="username" class="form-input"
                            value="admin" placeholder="admin">
                    </div>
                    <div class="form-group">
                        <label for="password" class="form-label">Password</label>
                        <div style="position:relative;">
                            <input type="password" id="password" name="password" class="form-input"
                                value="admin12345" placeholder="Enter device password"
                                style="padding-right: 44px;">
                            <button type="button" onclick="togglePassword()" 
                                style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#666;padding:0;">
                                <i class="fa-solid fa-eye" id="togglePassIcon"></i>
                            </button>
                        </div>
                        <small class="form-hint">Default is admin12345</small>
                    </div>
                </div>

                <!-- Section: Model & Status -->
                <div class="form-section-title" style="margin-top:20px;">
                    <i class="fa-solid fa-microchip"></i> Device Info
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="model" class="form-label">Model</label>
                        <select id="model" name="model" class="form-input">
                            <option value="DS-K1T804BMF">DS-K1T804BMF</option>
                            <option value="DS-K1T804BEF">DS-K1T804BEF</option>
                            <option value="DS-K1T341BMF">DS-K1T341BMF</option>
                            <option value="DS-K1T671">DS-K1T671</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <div class="checkbox-wrapper">
                            <label class="checkbox-label">
                                <input type="checkbox" name="active" id="active" class="form-checkbox" checked>
                                <span class="checkbox-text">Active</span>
                            </label>
                        </div>
                    </div>
                </div>

            </div><!-- end modal-body -->

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check"></i>
                    <span id="submitBtnText">Save Machine</span>
                </button>
            </div>
        </form>
    </div>
</div>

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
.alert i { font-size: 18px; }
.alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.alert-info    { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0; top: 0;
    width: 100%; height: 100%;
    background-color: rgba(0,0,0,0.5);
    animation: fadeIn 0.3s;
}
.modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}
@keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
@keyframes slideDown {
    from { opacity:0; transform: translateY(-50px); }
    to   { opacity:1; transform: translateY(0); }
}
.modal-content {
    background: #ffffff;
    border-radius: 12px;
    width: 90%;
    max-width: 680px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    animation: slideDown 0.3s;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 24px;
    border-bottom: 1px solid #e5e5e5;
}
.modal-title { font-size: 18px; font-weight: 600; margin: 0; }
.modal-close {
    background: none; border: none; font-size: 24px; cursor: pointer;
    color: #666666; padding: 0; width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 6px; transition: all 0.2s;
}
.modal-close:hover { background: #f5f5f5; color: #000000; }
.modal-body { padding: 24px; }
.modal-footer {
    display: flex; justify-content: flex-end; gap: 12px;
    padding: 20px 24px; border-top: 1px solid #e5e5e5;
}

/* Form */
.form-section-title {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #666666;
    margin-bottom: 16px;
    padding-bottom: 8px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.form-group { margin-bottom: 20px; }
.form-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #333333; }
.required { color: #ef4444; }
.optional { color: #999999; font-weight: 400; font-size: 12px; }
.form-input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    transition: all 0.3s;
    background: #ffffff;
    box-sizing: border-box;
}
.form-input:focus { outline: none; border-color: #000000; box-shadow: 0 0 0 3px rgba(0,0,0,0.05); }
.form-input::placeholder { color: #999999; }
.form-hint { display: block; font-size: 11px; color: #666666; margin-top: 6px; }
select.form-input { cursor: pointer; }
.checkbox-wrapper { display: flex; align-items: center; }
.checkbox-label { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 14px; color: #333333; }
.form-checkbox { width: 18px; height: 18px; cursor: pointer; accent-color: #000000; }
.checkbox-text { font-weight: 500; }

/* Buttons */
.btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 24px; border: none; border-radius: 8px;
    font-size: 14px; font-weight: 600; cursor: pointer;
    transition: all 0.3s; text-decoration: none; font-family: 'Inter', sans-serif;
}
.btn i { font-size: 16px; }
.btn-primary { background: #000000; color: #ffffff; }
.btn-primary:hover { background: #333333; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.btn-secondary { background: #f5f5f5; color: #333333; border: 1px solid #e5e5e5; }
.btn-secondary:hover { background: #e5e5e5; }

/* Table */
.table-responsive { overflow-x: auto; margin-top: 20px; }
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table thead { background: #fafafa; border-bottom: 2px solid #e5e5e5; }
.data-table th { padding: 12px 16px; text-align: left; font-weight: 600; color: #333333; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
.data-table tbody tr { border-bottom: 1px solid #f0f0f0; transition: background 0.2s; }
.data-table tbody tr:hover { background: #fafafa; }
.data-table td { padding: 14px 16px; color: #333333; }

/* Badges */
.badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
.badge i { font-size: 10px; }
.badge-success  { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.badge-inactive { background: #fafafa; color: #666666; border: 1px solid #e5e5e5; }
.badge-model    { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }

.ip-badge {
    display: inline-block;
    background: #f9fafb;
    border: 1px solid #e5e5e5;
    border-radius: 6px;
    padding: 3px 8px;
    font-size: 12px;
    font-family: monospace;
    color: #333;
}

/* Action Buttons */
.action-buttons { display: flex; gap: 6px; flex-wrap: wrap; }
.btn-action {
    display: inline-flex; align-items: center; justify-content: center;
    width: 32px; height: 32px; border-radius: 6px; border: 1px solid #e5e5e5;
    background: #ffffff; color: #666666; cursor: pointer; transition: all 0.2s; text-decoration: none;
}
.btn-action:hover { transform: translateY(-2px); box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.btn-edit:hover    { background: #000000; color: #ffffff; border-color: #000000; }
.btn-delete:hover  { background: #ef4444; color: #ffffff; border-color: #ef4444; }
.btn-test:hover    { background: #16a34a; color: #ffffff; border-color: #16a34a; }
.btn-discover:hover{ background: #7c3aed; color: #ffffff; border-color: #7c3aed; }

/* Spinner */
.fa-spin { animation: spin 1s linear infinite; }
@keyframes spin { 0%{transform:rotate(0deg);} 100%{transform:rotate(360deg);} }

/* Responsive */
@media (max-width: 768px) {
    .modal-content { width: 95%; margin: 20px; }
    .modal-header, .modal-body, .modal-footer { padding: 16px; }
    .modal-footer { flex-direction: column; }
    .modal-footer .btn { width: 100%; justify-content: center; }
    .form-row { grid-template-columns: 1fr; }
    .table-responsive { overflow-x: auto; }
    .data-table { font-size: 12px; }
    .data-table th, .data-table td { padding: 10px; }
}
</style>

<script>
function openModal() {
    document.getElementById('machineModal').classList.add('active');
    document.getElementById('machineForm').reset();
    document.getElementById('machine_id').value = '';
    document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-fingerprint" style="margin-right:8px;"></i> Add New Machine';
    document.getElementById('submitBtnText').textContent = 'Save Machine';
    document.getElementById('port').value = '80';
    document.getElementById('username').value = 'admin';
    document.getElementById('password').value = 'admin12345';
    document.getElementById('active').checked = true;
}

function closeModal() {
    document.getElementById('machineModal').classList.remove('active');
}

function editMachine(machine) {
    document.getElementById('machineModal').classList.add('active');
    document.getElementById('machine_id').value       = machine.id;
    document.getElementById('branch_id').value        = machine.branch_id;
    document.getElementById('machine_code').value     = machine.machine_code;
    document.getElementById('machine_name').value     = machine.machine_name;
    document.getElementById('ip_address').value       = machine.ip_address;
    document.getElementById('mac_address').value      = machine.mac_address || '';
    document.getElementById('port').value             = machine.port;
    document.getElementById('username').value         = machine.username;
    document.getElementById('password').value         = machine.password;
    document.getElementById('model').value            = machine.model;
    document.getElementById('location_desc').value    = machine.location_desc || '';
    document.getElementById('active').checked         = machine.active == 1;
    document.getElementById('modalTitle').innerHTML   = '<i class="fa-solid fa-fingerprint" style="margin-right:8px;"></i> Edit Machine';
    document.getElementById('submitBtnText').textContent = 'Update Machine';
}

function togglePassword() {
    const passInput = document.getElementById('password');
    const icon      = document.getElementById('togglePassIcon');
    if (passInput.type === 'password') {
        passInput.type = 'text';
        icon.className = 'fa-solid fa-eye-slash';
    } else {
        passInput.type = 'password';
        icon.className = 'fa-solid fa-eye';
    }
}

function showAlert(message, type) {
    const alertBox  = document.getElementById('connectionAlert');
    const alertMsg  = document.getElementById('connectionAlertMsg');
    const alertIcon = document.getElementById('connectionAlertIcon');

    alertBox.className = 'alert alert-' + type;
    alertIcon.className = type === 'success'
        ? 'fa-solid fa-circle-check'
        : (type === 'info' ? 'fa-solid fa-circle-info' : 'fa-solid fa-circle-exclamation');
    alertMsg.textContent = message;
    alertBox.style.display = 'flex';

    // Scroll to alert
    alertBox.scrollIntoView({ behavior: 'smooth', block: 'start' });

    // Auto-hide after 6 seconds
    setTimeout(() => { alertBox.style.display = 'none'; }, 6000);
}

function testConnection(machineId, btn) {
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i>';
    btn.disabled = true;

    fetch('?test_connection=' + machineId)
        .then(res => res.json())
        .then(data => {
            btn.innerHTML = originalHTML;
            btn.disabled = false;
            if (data.status === 'success') {
                showAlert('✅ ' + data.message, 'success');
                // Update last ping display in row
                const row = document.getElementById('row-' + machineId);
                if (row) {
                    const pingCell = row.querySelectorAll('td')[6];
                    const now = new Date();
                    const formatted = now.toLocaleString('en-US', {month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit', hour12:false});
                    pingCell.innerHTML = '<small style="color:#166534;"><i class="fa-solid fa-clock"></i> ' + formatted + '</small>';
                }
            } else {
                showAlert('❌ ' + data.message, 'error');
            }
        })
        .catch(err => {
            btn.innerHTML = originalHTML;
            btn.disabled = false;
            showAlert('❌ Request failed: ' + err.message, 'error');
        });
}

function discoverIp(machineId, mac, btn) {
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i>';
    btn.disabled = true;

    showAlert('🔍 Scanning network for MAC: ' + mac + ' ...', 'info');

    fetch('?discover_ip=1&mac=' + encodeURIComponent(mac))
        .then(res => res.json())
        .then(data => {
            btn.innerHTML = originalHTML;
            btn.disabled = false;
            if (data.status === 'success') {
                showAlert('✅ ' + data.message + ' — Please update the IP address in Edit if it changed.', 'success');
                // Highlight IP cell
                const row = document.getElementById('row-' + machineId);
                if (row) {
                    const ipCell = row.querySelectorAll('td')[3];
                    ipCell.innerHTML = '<code class="ip-badge" style="border-color:#16a34a;color:#16a34a;">' + data.ip + '</code><br><small style="color:#16a34a;">Discovered ✓</small>';
                }
            } else {
                showAlert('❌ ' + data.message, 'error');
            }
        })
        .catch(err => {
            btn.innerHTML = originalHTML;
            btn.disabled = false;
            showAlert('❌ Discover failed: ' + err.message, 'error');
        });
}

// Close modal when clicking outside
document.getElementById('machineModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php include 'footer.php'; ?>