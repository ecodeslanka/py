<?php
/**
 * face_recognition.php
 * Employee Face Registration & Recognition
 *
 * DB columns expected on `employees`:
 *   face_descriptor  TEXT  NULL   — JSON array of 128-float face descriptor
 *   face_image       TEXT  NULL   — base64 data-URI of the saved face photo
 *
 * Run migration once:
 *   ALTER TABLE employees ADD COLUMN face_descriptor TEXT NULL;
 *   ALTER TABLE employees ADD COLUMN face_image      TEXT NULL;
 */

include 'config.php';

/* ─── Auto-add columns if missing ─────────────────────────────────── */
mysqli_query($conn, "ALTER TABLE employees ADD COLUMN IF NOT EXISTS face_descriptor TEXT NULL");
mysqli_query($conn, "ALTER TABLE employees ADD COLUMN IF NOT EXISTS face_image TEXT NULL");

/* ─── AJAX handlers ────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    header('Content-Type: application/json');

    /* ── SAVE face descriptor for an employee ── */
    if ($_POST['action'] === 'save_face') {
        $emp_id     = intval($_POST['employee_id'] ?? 0);
        $descriptor = $_POST['descriptor']   ?? '';
        $image      = $_POST['face_image']   ?? '';

        if (!$emp_id || !$descriptor) {
            echo json_encode(['success' => false, 'message' => 'Missing data']);
            exit;
        }

        // Basic validation – must be a JSON float array of 128 items
        $arr = json_decode($descriptor, true);
        if (!is_array($arr) || count($arr) !== 128) {
            echo json_encode(['success' => false, 'message' => 'Invalid face descriptor (must have 128 values)']);
            exit;
        }

        $desc_safe  = mysqli_real_escape_string($conn, $descriptor);
        $image_safe = mysqli_real_escape_string($conn, $image);

        $sql = "UPDATE employees SET face_descriptor = '$desc_safe', face_image = '$image_safe' WHERE id = $emp_id";
        if (mysqli_query($conn, $sql)) {
            // Return updated employee info
            $row = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT id, employee_id, employee_full_name FROM employees WHERE id = $emp_id"));
            echo json_encode(['success' => true, 'message' => 'Face saved successfully', 'employee' => $row]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        }
        exit;
    }

    /* ── DELETE face for an employee ── */
    if ($_POST['action'] === 'delete_face') {
        $emp_id = intval($_POST['employee_id'] ?? 0);
        if (!$emp_id) { echo json_encode(['success' => false]); exit; }
        mysqli_query($conn, "UPDATE employees SET face_descriptor = NULL, face_image = NULL WHERE id = $emp_id");
        echo json_encode(['success' => true]);
        exit;
    }

    /* ── GET all registered faces for recognition ── */
    if ($_POST['action'] === 'get_all_faces') {
        $result = mysqli_query($conn,
            "SELECT e.id, e.employee_id, e.employee_full_name, e.designation_id,
                    e.face_descriptor, e.face_image,
                    c.company_code, b.branch_name, d.designation_name
             FROM employees e
             LEFT JOIN companies c ON e.company_id = c.id
             LEFT JOIN branches b ON e.branch_id = b.id
             LEFT JOIN designations d ON e.designation_id = d.id
             WHERE e.face_descriptor IS NOT NULL AND e.active = 1");
        $faces = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $faces[] = [
                'id'           => $row['id'],
                'employee_id'  => $row['employee_id'],
                'full_name'    => $row['employee_full_name'],
                'company'      => $row['company_code'] ?? '',
                'branch'       => $row['branch_name'] ?? '',
                'designation'  => $row['designation_name'] ?? '',
                'descriptor'   => json_decode($row['face_descriptor'], true),
                'face_image'   => $row['face_image'] ?? '',
            ];
        }
        echo json_encode(['success' => true, 'faces' => $faces]);
        exit;
    }
}

/* ─── Load employees for dropdown ─────────────────────────────────── */
$employees_result = mysqli_query($conn,
    "SELECT e.id, e.employee_id, e.employee_full_name,
            e.face_descriptor, e.face_image,
            c.company_code, b.branch_name, d.designation_name
     FROM employees e
     LEFT JOIN companies c ON e.company_id = c.id
     LEFT JOIN branches b ON e.branch_id = b.id
     LEFT JOIN designations d ON e.designation_id = d.id
     WHERE e.active = 1
     ORDER BY e.employee_full_name ASC");

$all_emps       = [];
$registered_ct  = 0;
$total_ct       = 0;

while ($r = mysqli_fetch_assoc($employees_result)) {
    $total_ct++;
    if (!empty($r['face_descriptor'])) $registered_ct++;
    $all_emps[] = $r;
}

include 'header.php';
?>

<!-- ═══════════════════════════════════════════════════════════════════
     PAGE WRAPPER
════════════════════════════════════════════════════════════════════ -->
<div class="fr-page">

    <!-- ── Page Header ── -->
    <div class="fr-top-bar">
        <div class="fr-top-left">
            <div class="fr-title-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="22" height="22">
                    <path d="M2 7V5a2 2 0 012-2h2M2 17v2a2 2 0 002 2h2M22 7V5a2 2 0 00-2-2h-2M22 17v2a2 2 0 01-2 2h-2"/>
                    <circle cx="12" cy="10" r="3"/>
                    <path d="M7 20.662V19a2 2 0 012-2h6a2 2 0 012 2v1.662"/>
                </svg>
            </div>
            <div>
                <h1 class="fr-h1">Face Recognition System</h1>
                <p class="fr-subtitle">Register &amp; verify employee identities using biometric face scan</p>
            </div>
        </div>
        <div class="fr-top-stats">
            <div class="fr-stat">
                <span class="fr-stat-val" id="statRegistered"><?php echo $registered_ct; ?></span>
                <span class="fr-stat-lbl">Registered</span>
            </div>
            <div class="fr-stat-div"></div>
            <div class="fr-stat">
                <span class="fr-stat-val"><?php echo $total_ct; ?></span>
                <span class="fr-stat-lbl">Total Employees</span>
            </div>
            <div class="fr-stat-div"></div>
            <div class="fr-stat">
                <span class="fr-stat-val" id="statUnreg"><?php echo $total_ct - $registered_ct; ?></span>
                <span class="fr-stat-lbl">Unregistered</span>
            </div>
        </div>
    </div>

    <!-- ── Mode Tabs ── -->
    <div class="fr-mode-tabs">
        <button class="fr-mode-tab active" id="tabRegister" onclick="setMode('register')">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="16" height="16" aria-hidden="true"><circle cx="10" cy="7" r="3.5"/><path d="M3 17c0-3.314 3.134-6 7-6s7 2.686 7 6"/><path d="M14 4l1.5 1.5L18 3"/></svg>
            Register Face
        </button>
        <button class="fr-mode-tab" id="tabRecognize" onclick="setMode('recognize')">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="16" height="16" aria-hidden="true"><circle cx="10" cy="7" r="3.5"/><path d="M3 17c0-3.314 3.134-6 7-6s7 2.686 7 6"/><path d="M13 10l1.5 1.5 3-3"/></svg>
            Recognize &amp; Verify
        </button>
        <button class="fr-mode-tab" id="tabManage" onclick="setMode('manage')">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="16" height="16" aria-hidden="true"><rect x="3" y="4" width="14" height="12" rx="2"/><path d="M7 8h6M7 12h4"/></svg>
            Manage Records
        </button>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         REGISTER PANEL
    ═════════════════════════════════════════════════════════════ -->
    <div class="fr-panel" id="panelRegister">
        <div class="fr-two-col">

            <!-- Left: Camera -->
            <div class="fr-camera-card">
                <div class="fr-card-header">
                    <span class="fr-card-title">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="16" height="16" aria-hidden="true"><path d="M2 7a2 2 0 012-2h1l1.5-2h7L15 5h1a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V7z"/><circle cx="10" cy="11" r="3"/></svg>
                        Camera Capture
                    </span>
                    <span class="fr-badge" id="regCamStatus">Camera Off</span>
                </div>

                <div class="fr-video-wrap" id="regVideoWrap">
                    <video id="regVideo" autoplay playsinline muted></video>
                    <canvas id="regOverlay" class="fr-overlay-canvas"></canvas>
                    <div class="fr-scan-frame" id="regScanFrame">
                        <div class="fr-scan-corner tl"></div>
                        <div class="fr-scan-corner tr"></div>
                        <div class="fr-scan-corner bl"></div>
                        <div class="fr-scan-corner br"></div>
                        <div class="fr-scan-line" id="regScanLine"></div>
                    </div>
                    <div class="fr-video-placeholder" id="regPlaceholder">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" width="48" height="48" aria-hidden="true"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
                        <span>Camera not started</span>
                    </div>
                </div>

                <!-- Capture preview -->
                <canvas id="regCaptureCanvas" style="display:none;"></canvas>
                <div class="fr-preview-wrap" id="regPreviewWrap" style="display:none;">
                    <img id="regPreviewImg" alt="Captured face" class="fr-preview-img"/>
                    <div class="fr-preview-meta" id="regPreviewMeta"></div>
                </div>

                <div class="fr-cam-controls">
                    <button class="fr-btn fr-btn-outline" id="regBtnStart" onclick="startRegCamera()">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14" aria-hidden="true"><polygon points="6,4 17,10 6,16"/></svg>
                        Start Camera
                    </button>
                    <button class="fr-btn fr-btn-primary" id="regBtnCapture" onclick="captureRegFace()" disabled>
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14" aria-hidden="true"><circle cx="10" cy="10" r="3"/><path d="M2 8V6a2 2 0 012-2h1l1.5-2h7L15 4h1a2 2 0 012 2v2"/><rect x="2" y="8" width="16" height="10" rx="1.5"/></svg>
                        Capture Face
                    </button>
                    <button class="fr-btn fr-btn-ghost" id="regBtnRetake" onclick="retakeRegFace()" style="display:none;">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14" aria-hidden="true"><path d="M4 4v5h5M16 16v-5h-5"/><path d="M4 9a8 8 0 1112.36-1"/></svg>
                        Retake
                    </button>
                    <button class="fr-btn fr-btn-danger-outline" id="regBtnStop" onclick="stopRegCamera()" style="display:none;">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14" aria-hidden="true"><rect x="5" y="5" width="10" height="10" rx="1"/></svg>
                        Stop
                    </button>
                </div>

                <div class="fr-detect-info" id="regDetectInfo"></div>
            </div>

            <!-- Right: Employee selector + Save -->
            <div class="fr-form-card">
                <div class="fr-card-header">
                    <span class="fr-card-title">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="16" height="16" aria-hidden="true"><circle cx="10" cy="7" r="3.5"/><path d="M3 17c0-3.314 3.134-6 7-6s7 2.686 7 6"/></svg>
                        Select Employee
                    </span>
                </div>

                <!-- Search-select for employee -->
                <div class="fr-search-wrap">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="15" height="15" class="fr-search-icon" aria-hidden="true"><circle cx="9" cy="9" r="5.5"/><path d="M16 16l-2.5-2.5"/></svg>
                    <input type="text" id="empSearchBox" class="fr-search-input" placeholder="Search employee by name or ID…" oninput="filterEmployees(this.value)" autocomplete="off">
                    <button class="fr-search-clear" id="empSearchClear" onclick="clearEmpSearch()" style="display:none;" title="Clear">×</button>
                </div>

                <div class="fr-emp-list" id="empList">
                    <?php foreach ($all_emps as $emp): ?>
                    <div class="fr-emp-item <?php echo !empty($emp['face_descriptor']) ? 'has-face' : ''; ?>"
                         data-id="<?php echo $emp['id']; ?>"
                         data-search="<?php echo strtolower(htmlspecialchars($emp['employee_id'].' '.$emp['employee_full_name'])); ?>"
                         onclick="selectEmployee(<?php echo $emp['id']; ?>, '<?php echo addslashes($emp['employee_full_name']); ?>', '<?php echo htmlspecialchars($emp['employee_id']); ?>', '<?php echo addslashes($emp['designation_name'] ?? ''); ?>', '<?php echo addslashes($emp['company_code'] ?? ''); ?>', '<?php echo addslashes($emp['branch_name'] ?? ''); ?>', <?php echo !empty($emp['face_descriptor']) ? 'true' : 'false'; ?>, '<?php echo htmlspecialchars(addslashes($emp['face_image'] ?? '')); ?>')">
                        <div class="fr-emp-avatar <?php echo !empty($emp['face_descriptor']) ? 'avatar-green' : ''; ?>">
                            <?php if (!empty($emp['face_image'])): ?>
                            <img src="<?php echo htmlspecialchars($emp['face_image']); ?>" alt="">
                            <?php else: ?>
                            <?php echo strtoupper(substr($emp['employee_full_name'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="fr-emp-info">
                            <div class="fr-emp-name"><?php echo htmlspecialchars($emp['employee_full_name']); ?></div>
                            <div class="fr-emp-meta"><?php echo htmlspecialchars($emp['employee_id']); ?><?php echo $emp['designation_name'] ? ' · '.$emp['designation_name'] : ''; ?></div>
                        </div>
                        <?php if (!empty($emp['face_descriptor'])): ?>
                        <div class="fr-emp-face-badge" title="Face registered">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12" aria-hidden="true"><path d="M3 8.5L6.5 12 13 5"/></svg>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Selected employee card -->
                <div class="fr-selected-card" id="selectedCard" style="display:none;">
                    <div class="fr-selected-inner">
                        <div class="fr-selected-avatar" id="selectedAvatar">?</div>
                        <div class="fr-selected-info">
                            <div class="fr-selected-name" id="selectedName">—</div>
                            <div class="fr-selected-sub" id="selectedSub">—</div>
                            <div id="selectedFaceStatus"></div>
                        </div>
                    </div>
                    <div style="margin-top:14px;">
                        <button class="fr-btn fr-btn-save" id="btnSaveFace" onclick="saveFace()" disabled>
                            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14" aria-hidden="true"><path d="M17 3H5a2 2 0 00-2 2v12l4-2 3 2 3-2 4 2V5a2 2 0 00-2-2z"/><path d="M9 9h2M9 12h4"/></svg>
                            Save Face Data
                        </button>
                        <button class="fr-btn fr-btn-danger-outline" id="btnDeleteFace" onclick="deleteFace()" style="display:none;margin-left:8px;">
                            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14" aria-hidden="true"><path d="M3 6h14M8 6V4h4v2M6 6v10a1 1 0 001 1h6a1 1 0 001-1V6"/></svg>
                            Remove Face
                        </button>
                    </div>
                </div>

                <div class="fr-tips">
                    <div class="fr-tip"><span class="fr-tip-num">1</span> Select an employee from the list</div>
                    <div class="fr-tip"><span class="fr-tip-num">2</span> Start the camera and position face inside the frame</div>
                    <div class="fr-tip"><span class="fr-tip-num">3</span> Click <strong>Capture Face</strong> — system will detect and extract the biometric descriptor</div>
                    <div class="fr-tip"><span class="fr-tip-num">4</span> Click <strong>Save Face Data</strong> to register</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         RECOGNIZE PANEL
    ═════════════════════════════════════════════════════════════ -->
    <div class="fr-panel" id="panelRecognize" style="display:none;">
        <div class="fr-two-col">

            <!-- Left: Camera -->
            <div class="fr-camera-card">
                <div class="fr-card-header">
                    <span class="fr-card-title">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="16" height="16" aria-hidden="true"><circle cx="10" cy="9" r="5"/><path d="M4 17c0-2.761 2.686-5 6-5s6 2.239 6 5"/></svg>
                        Live Recognition
                    </span>
                    <span class="fr-badge" id="recCamStatus">Camera Off</span>
                </div>

                <div class="fr-video-wrap" id="recVideoWrap">
                    <video id="recVideo" autoplay playsinline muted></video>
                    <canvas id="recOverlay" class="fr-overlay-canvas"></canvas>
                    <div class="fr-video-placeholder" id="recPlaceholder">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" width="48" height="48" aria-hidden="true"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
                        <span>Camera not started</span>
                    </div>
                </div>

                <canvas id="recCaptureCanvas" style="display:none;"></canvas>

                <div class="fr-cam-controls">
                    <button class="fr-btn fr-btn-outline" id="recBtnStart" onclick="startRecCamera()">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14" aria-hidden="true"><polygon points="6,4 17,10 6,16"/></svg>
                        Start Camera
                    </button>
                    <button class="fr-btn fr-btn-primary" id="recBtnScan" onclick="scanAndRecognize()" disabled>
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14" aria-hidden="true"><path d="M2 6h16M2 14h16M6 2v16M14 2v16" stroke-linecap="round"/></svg>
                        Scan &amp; Identify
                    </button>
                    <button class="fr-btn fr-btn-ghost" id="recBtnLive" onclick="toggleLiveRecognize()" style="display:none;">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14" aria-hidden="true"><circle cx="10" cy="10" r="3"/><path d="M3.5 3.5l2 2M16.5 3.5l-2 2M3.5 16.5l2-2M16.5 16.5l-2-2"/></svg>
                        Live Mode
                    </button>
                    <button class="fr-btn fr-btn-danger-outline" id="recBtnStop" onclick="stopRecCamera()" style="display:none;">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14" aria-hidden="true"><rect x="5" y="5" width="10" height="10" rx="1"/></svg>
                        Stop
                    </button>
                </div>
            </div>

            <!-- Right: Result -->
            <div class="fr-result-card">
                <div class="fr-card-header">
                    <span class="fr-card-title">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="16" height="16" aria-hidden="true"><path d="M9 2H4a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2V9M14 2l4 4M14 2v4h4"/><path d="M7 12l2 2 4-4"/></svg>
                        Recognition Result
                    </span>
                    <span class="fr-badge" id="recFacesLoaded">0 faces loaded</span>
                </div>

                <!-- Default empty state -->
                <div class="fr-result-empty" id="recResultEmpty">
                    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.2" width="52" height="52" aria-hidden="true">
                        <rect x="6" y="6" width="36" height="36" rx="4"/>
                        <circle cx="24" cy="20" r="7"/>
                        <path d="M10 42c0-7.732 6.268-14 14-14s14 6.268 14 14"/>
                        <path d="M30 8l2.5 2.5 5-5"/>
                    </svg>
                    <p>Capture a face to identify an employee</p>
                </div>

                <!-- Scanning state -->
                <div class="fr-result-scanning" id="recScanning" style="display:none;">
                    <div class="fr-spinner"></div>
                    <p>Analyzing biometrics…</p>
                </div>

                <!-- Match result -->
                <div class="fr-result-match" id="recResult" style="display:none;">
                    <div class="fr-match-header" id="matchHeader">
                        <div class="fr-match-icon" id="matchIcon"></div>
                        <div class="fr-match-title" id="matchTitle"></div>
                    </div>
                    <div class="fr-match-body" id="matchBody"></div>
                    <div class="fr-match-confidence" id="matchConfidence"></div>
                    <div style="margin-top:16px;">
                        <button class="fr-btn fr-btn-outline" onclick="resetRecResult()">
                            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14" aria-hidden="true"><path d="M4 4v5h5M16 16v-5h-5"/><path d="M4 9a8 8 0 1112.36-1"/></svg>
                            Scan Again
                        </button>
                    </div>
                </div>

                <!-- Scan history -->
                <div class="fr-scan-history" id="scanHistory" style="display:none;">
                    <div class="fr-section-title">Recent Scans</div>
                    <div id="scanHistoryList"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         MANAGE PANEL
    ═════════════════════════════════════════════════════════════ -->
    <div class="fr-panel" id="panelManage" style="display:none;">
        <div class="fr-manage-bar">
            <div class="fr-search-wrap" style="max-width:340px;">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="15" height="15" class="fr-search-icon" aria-hidden="true"><circle cx="9" cy="9" r="5.5"/><path d="M16 16l-2.5-2.5"/></svg>
                <input type="text" id="manageSearch" class="fr-search-input" placeholder="Search registered employees…" oninput="filterManage(this.value)">
            </div>
            <div class="fr-manage-legend">
                <span class="fr-legend-dot green"></span> Face Registered &nbsp;
                <span class="fr-legend-dot gray"></span> Not Registered
            </div>
        </div>

        <div class="fr-manage-grid" id="manageGrid">
            <?php foreach ($all_emps as $emp):
                $has_face = !empty($emp['face_descriptor']);
            ?>
            <div class="fr-manage-item <?php echo $has_face ? 'has-face' : 'no-face'; ?>"
                 data-manage-search="<?php echo strtolower(htmlspecialchars($emp['employee_id'].' '.$emp['employee_full_name'])); ?>">
                <div class="fr-manage-avatar <?php echo $has_face ? 'avatar-green' : ''; ?>">
                    <?php if ($has_face && !empty($emp['face_image'])): ?>
                    <img src="<?php echo htmlspecialchars($emp['face_image']); ?>" alt="">
                    <?php else: ?>
                    <?php echo strtoupper(substr($emp['employee_full_name'], 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="fr-manage-info">
                    <div class="fr-manage-name"><?php echo htmlspecialchars($emp['employee_full_name']); ?></div>
                    <div class="fr-manage-id"><?php echo htmlspecialchars($emp['employee_id']); ?></div>
                    <?php if ($emp['designation_name']): ?>
                    <div class="fr-manage-desig"><?php echo htmlspecialchars($emp['designation_name']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="fr-manage-status">
                    <?php if ($has_face): ?>
                    <span class="fr-status-badge green">
                        <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" width="10" height="10" aria-hidden="true"><path d="M2 6l3 3 5-5"/></svg>
                        Registered
                    </span>
                    <button class="fr-btn-mini fr-btn-danger-outline" onclick="deleteFaceById(<?php echo $emp['id']; ?>, this)" title="Remove face">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" width="11" height="11" aria-hidden="true"><path d="M2 4h12M5 4V2h6v2M5 4v8a1 1 0 001 1h4a1 1 0 001-1V4"/></svg>
                    </button>
                    <?php else: ?>
                    <span class="fr-status-badge gray">Not Registered</span>
                    <a href="face_recognition.php?register=<?php echo $emp['id']; ?>" class="fr-btn-mini fr-btn-primary-mini" onclick="goRegister(event, <?php echo $emp['id']; ?>)">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" width="11" height="11" aria-hidden="true"><path d="M8 3v10M3 8h10"/></svg>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div><!-- /fr-page -->

<!-- ════════════════════════════════════════════════════════════════
     TOAST NOTIFICATION
═══════════════════════════════════════════════════════════════════ -->
<div class="fr-toast" id="frToast" role="alert" aria-live="polite"></div>

<!-- ════════════════════════════════════════════════════════════════
     FACE-API.JS  (uses TinyFaceDetector + FaceRecognitionNet)
     Loaded from jsDelivr CDN
═══════════════════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>

<script>
/* ══════════════════════════════════════════════════════════════════
   GLOBALS
══════════════════════════════════════════════════════════════════ */
const MODEL_URL = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/model/';
let modelsLoaded   = false;
let regStream      = null;
let recStream      = null;
let regDescriptor  = null;   // Float32Array
let selectedEmpId  = null;
let selectedEmpHasFace = false;
let knownFaces     = [];     // [{id, employee_id, full_name, …, descriptor: Float32Array}]
let liveRecognizeInterval = null;
let scanHistoryArr = [];

/* ══════════════════════════════════════════════════════════════════
   LOAD MODELS
══════════════════════════════════════════════════════════════════ */
async function loadModels() {
    if (modelsLoaded) return true;
    showToast('Loading AI models…', 'info', 8000);
    try {
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
            faceapi.nets.faceLandmark68TinyNet.loadFromUri(MODEL_URL),
            faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
        ]);
        modelsLoaded = true;
        showToast('AI models loaded ✓', 'success');
        return true;
    } catch (e) {
        showToast('Failed to load AI models. Check network.', 'error', 6000);
        console.error(e);
        return false;
    }
}

window.addEventListener('DOMContentLoaded', () => {
    loadModels();
    // Pre-fetch registered faces for recognition
    fetchKnownFaces();

    // If URL has ?register=ID, auto-select employee and switch to register tab
    const params = new URLSearchParams(location.search);
    if (params.has('register')) {
        const id = parseInt(params.get('register'));
        const item = document.querySelector(`.fr-emp-item[data-id="${id}"]`);
        if (item) item.click();
    }
});

/* ══════════════════════════════════════════════════════════════════
   MODE TABS
══════════════════════════════════════════════════════════════════ */
function setMode(mode) {
    ['register','recognize','manage'].forEach(m => {
        document.getElementById('tab' + capitalize(m)).classList.toggle('active', m === mode);
        document.getElementById('panel' + capitalize(m)).style.display = m === mode ? 'block' : 'none';
    });
    if (mode === 'recognize') fetchKnownFaces();
}

function capitalize(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

/* ══════════════════════════════════════════════════════════════════
   REGISTER — CAMERA
══════════════════════════════════════════════════════════════════ */
async function startRegCamera() {
    if (!await loadModels()) return;
    try {
        regStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: 640, height: 480 }, audio: false });
        const vid = document.getElementById('regVideo');
        vid.srcObject = regStream;
        document.getElementById('regPlaceholder').style.display = 'none';
        document.getElementById('regBtnStart').style.display    = 'none';
        document.getElementById('regBtnStop').style.display     = 'inline-flex';
        document.getElementById('regBtnCapture').disabled       = false;
        document.getElementById('regCamStatus').textContent     = 'Live';
        document.getElementById('regCamStatus').classList.add('badge-green');
        document.getElementById('regScanLine').classList.add('scanning');
    } catch(e) {
        showToast('Camera access denied or unavailable.', 'error');
    }
}

function stopRegCamera() {
    if (regStream) { regStream.getTracks().forEach(t => t.stop()); regStream = null; }
    document.getElementById('regPlaceholder').style.display = 'flex';
    document.getElementById('regBtnStart').style.display    = 'inline-flex';
    document.getElementById('regBtnStop').style.display     = 'none';
    document.getElementById('regBtnCapture').disabled       = true;
    document.getElementById('regCamStatus').textContent     = 'Camera Off';
    document.getElementById('regCamStatus').classList.remove('badge-green');
    document.getElementById('regScanLine').classList.remove('scanning');
}

async function captureRegFace() {
    const vid = document.getElementById('regVideo');
    if (!vid.srcObject) return;
    if (!modelsLoaded) { showToast('Models still loading…', 'info'); return; }

    document.getElementById('regBtnCapture').disabled = true;
    document.getElementById('regDetectInfo').innerHTML = '<span class="fr-detect-loading">Detecting face…</span>';

    const canvas  = document.getElementById('regCaptureCanvas');
    const ctx     = canvas.getContext('2d');
    canvas.width  = vid.videoWidth  || 640;
    canvas.height = vid.videoHeight || 480;
    ctx.drawImage(vid, 0, 0);

    const opts = new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.5 });

    try {
        const detection = await faceapi
            .detectSingleFace(canvas, opts)
            .withFaceLandmarks(true)
            .withFaceDescriptor();

        if (!detection) {
            document.getElementById('regDetectInfo').innerHTML = '<span class="fr-detect-fail"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><circle cx="8" cy="8" r="6"/><path d="M5 5l6 6M11 5l-6 6"/></svg> No face detected. Try better lighting and centre your face.</span>';
            document.getElementById('regBtnCapture').disabled = false;
            return;
        }

        regDescriptor = detection.descriptor; // Float32Array(128)

        // Show preview
        const previewImg = document.getElementById('regPreviewImg');
        previewImg.src   = canvas.toDataURL('image/jpeg', 0.8);
        document.getElementById('regPreviewWrap').style.display = 'block';

        const box = detection.detection.box;
        document.getElementById('regPreviewMeta').innerHTML =
            `<span class="fr-detect-ok"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><circle cx="8" cy="8" r="6"/><path d="M5 8l2 2 4-4"/></svg>
            Face detected — confidence ${(detection.detection.score * 100).toFixed(1)}%</span>`;

        document.getElementById('regDetectInfo').innerHTML =
            `<span class="fr-detect-ok">✓ Face captured &amp; descriptor extracted (128-point biometric)</span>`;

        document.getElementById('regBtnRetake').style.display  = 'inline-flex';
        document.getElementById('regBtnCapture').style.display = 'none';

        // Enable save button if employee selected
        if (selectedEmpId) document.getElementById('btnSaveFace').disabled = false;

    } catch(e) {
        document.getElementById('regDetectInfo').innerHTML = `<span class="fr-detect-fail">Detection error: ${e.message}</span>`;
        document.getElementById('regBtnCapture').disabled = false;
    }
}

function retakeRegFace() {
    regDescriptor = null;
    document.getElementById('regPreviewWrap').style.display = 'none';
    document.getElementById('regBtnRetake').style.display   = 'none';
    document.getElementById('regBtnCapture').style.display  = 'inline-flex';
    document.getElementById('regBtnCapture').disabled       = false;
    document.getElementById('regDetectInfo').innerHTML      = '';
    document.getElementById('btnSaveFace').disabled         = true;
}

/* ══════════════════════════════════════════════════════════════════
   REGISTER — EMPLOYEE SELECTION
══════════════════════════════════════════════════════════════════ */
function filterEmployees(q) {
    q = q.trim().toLowerCase();
    document.getElementById('empSearchClear').style.display = q ? 'block' : 'none';
    document.querySelectorAll('.fr-emp-item').forEach(item => {
        item.style.display = (!q || item.dataset.search.includes(q)) ? 'flex' : 'none';
    });
}

function clearEmpSearch() {
    document.getElementById('empSearchBox').value = '';
    document.getElementById('empSearchClear').style.display = 'none';
    document.querySelectorAll('.fr-emp-item').forEach(item => item.style.display = 'flex');
}

function selectEmployee(id, name, empId, designation, company, branch, hasFace, faceImage) {
    selectedEmpId       = id;
    selectedEmpHasFace  = hasFace;

    document.querySelectorAll('.fr-emp-item').forEach(i => i.classList.remove('selected'));
    document.querySelector(`.fr-emp-item[data-id="${id}"]`).classList.add('selected');

    const card = document.getElementById('selectedCard');
    card.style.display = 'block';

    const av = document.getElementById('selectedAvatar');
    if (faceImage) {
        av.innerHTML = `<img src="${faceImage}" alt="">`;
        av.classList.add('avatar-img');
    } else {
        av.textContent = name.charAt(0).toUpperCase();
        av.classList.remove('avatar-img');
    }

    document.getElementById('selectedName').textContent = name;
    document.getElementById('selectedSub').textContent  = empId + (designation ? ' · ' + designation : '') + (company ? ' · ' + company : '');

    const statusEl = document.getElementById('selectedFaceStatus');
    if (hasFace) {
        statusEl.innerHTML = `<span class="fr-inline-badge green">
            <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" width="10" height="10"><path d="M2 6l3 3 5-5"/></svg>
            Face already registered — saving will update it
        </span>`;
        document.getElementById('btnDeleteFace').style.display = 'inline-flex';
    } else {
        statusEl.innerHTML = `<span class="fr-inline-badge gray">No face registered yet</span>`;
        document.getElementById('btnDeleteFace').style.display = 'none';
    }

    document.getElementById('btnSaveFace').disabled = !regDescriptor;
}

/* ══════════════════════════════════════════════════════════════════
   REGISTER — SAVE FACE
══════════════════════════════════════════════════════════════════ */
async function saveFace() {
    if (!selectedEmpId || !regDescriptor) return;

    const btn = document.getElementById('btnSaveFace');
    btn.disabled   = true;
    btn.innerHTML  = '<span class="fr-spinner-mini"></span> Saving…';

    const descriptorJson = JSON.stringify(Array.from(regDescriptor));
    const faceImage      = document.getElementById('regPreviewImg').src || '';

    const fd = new FormData();
    fd.append('action',      'save_face');
    fd.append('employee_id', selectedEmpId);
    fd.append('descriptor',  descriptorJson);
    fd.append('face_image',  faceImage);

    try {
        const res  = await fetch('face_recognition.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            showToast(`✓ Face saved for ${data.employee.employee_full_name}`, 'success');
            // Update UI
            const item = document.querySelector(`.fr-emp-item[data-id="${selectedEmpId}"]`);
            if (item) {
                item.classList.add('has-face');
                if (!item.querySelector('.fr-emp-face-badge')) {
                    item.insertAdjacentHTML('beforeend',
                        `<div class="fr-emp-face-badge" title="Face registered">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><path d="M3 8.5L6.5 12 13 5"/></svg>
                        </div>`);
                }
                const av = item.querySelector('.fr-emp-avatar');
                if (av) {
                    av.classList.add('avatar-green');
                    av.innerHTML = `<img src="${faceImage}" alt="">`;
                }
            }
            document.getElementById('selectedFaceStatus').innerHTML =
                `<span class="fr-inline-badge green"><svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" width="10" height="10"><path d="M2 6l3 3 5-5"/></svg> Face registered</span>`;
            document.getElementById('btnDeleteFace').style.display = 'inline-flex';

            // Update stats
            updateStatCounts();
            fetchKnownFaces();
        } else {
            showToast('Save failed: ' + data.message, 'error');
        }
    } catch(e) {
        showToast('Network error. Please try again.', 'error');
    }

    btn.disabled  = false;
    btn.innerHTML = `<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14"><path d="M17 3H5a2 2 0 00-2 2v12l4-2 3 2 3-2 4 2V5a2 2 0 00-2-2z"/><path d="M9 9h2M9 12h4"/></svg> Save Face Data`;
}

async function deleteFace() {
    if (!selectedEmpId) return;
    if (!confirm('Remove the registered face data for this employee?')) return;
    await deleteFaceById(selectedEmpId, null, true);
}

async function deleteFaceById(empId, btnEl, fromRegister = false) {
    if (!fromRegister && !confirm('Remove face registration for this employee?')) return;

    const fd = new FormData();
    fd.append('action', 'delete_face');
    fd.append('employee_id', empId);

    const res  = await fetch('face_recognition.php', { method: 'POST', body: fd });
    const data = await res.json();

    if (data.success) {
        showToast('Face registration removed.', 'info');

        // Update manage grid
        const manageItem = document.querySelector(`#manageGrid .fr-manage-item[data-manage-id="${empId}"]`);
        // Update register list
        const listItem = document.querySelector(`.fr-emp-item[data-id="${empId}"]`);
        if (listItem) {
            listItem.classList.remove('has-face');
            const badge = listItem.querySelector('.fr-emp-face-badge');
            if (badge) badge.remove();
            const av = listItem.querySelector('.fr-emp-avatar');
            if (av) {
                av.classList.remove('avatar-green');
                av.innerHTML = av.dataset.initial || listItem.querySelector('.fr-emp-name').textContent.charAt(0).toUpperCase();
            }
        }
        if (selectedEmpId == empId) {
            document.getElementById('selectedFaceStatus').innerHTML =
                `<span class="fr-inline-badge gray">No face registered yet</span>`;
            document.getElementById('btnDeleteFace').style.display = 'none';
            document.getElementById('selectedAvatar').innerHTML     = document.getElementById('selectedName').textContent.charAt(0).toUpperCase();
        }
        // Refresh manage grid entry
        if (btnEl) {
            const item = btnEl.closest('.fr-manage-item');
            if (item) {
                item.classList.remove('has-face');
                item.classList.add('no-face');
                item.querySelector('.fr-manage-status').innerHTML =
                    `<span class="fr-status-badge gray">Not Registered</span>
                     <button class="fr-btn-mini fr-btn-primary-mini" onclick="goRegisterManage(${empId})">
                         <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" width="11" height="11"><path d="M8 3v10M3 8h10"/></svg>
                     </button>`;
                const av2 = item.querySelector('.fr-manage-avatar');
                if (av2) { av2.classList.remove('avatar-green'); av2.innerHTML = av2.dataset.initial || '?'; }
            }
        }
        updateStatCounts();
        fetchKnownFaces();
    }
}

function goRegisterManage(id) {
    setMode('register');
    const item = document.querySelector(`.fr-emp-item[data-id="${id}"]`);
    if (item) { item.click(); item.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
}

function goRegister(e, id) {
    e.preventDefault();
    goRegisterManage(id);
}

/* ══════════════════════════════════════════════════════════════════
   RECOGNIZE — CAMERA
══════════════════════════════════════════════════════════════════ */
async function startRecCamera() {
    if (!await loadModels()) return;
    await fetchKnownFaces();
    try {
        recStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: 640, height: 480 }, audio: false });
        const vid = document.getElementById('recVideo');
        vid.srcObject = recStream;
        document.getElementById('recPlaceholder').style.display = 'none';
        document.getElementById('recBtnStart').style.display    = 'none';
        document.getElementById('recBtnStop').style.display     = 'inline-flex';
        document.getElementById('recBtnScan').disabled          = false;
        document.getElementById('recBtnLive').style.display     = 'inline-flex';
        document.getElementById('recCamStatus').textContent     = 'Live';
        document.getElementById('recCamStatus').classList.add('badge-green');
    } catch(e) {
        showToast('Camera access denied.', 'error');
    }
}

function stopRecCamera() {
    stopLiveRecognize();
    if (recStream) { recStream.getTracks().forEach(t => t.stop()); recStream = null; }
    document.getElementById('recPlaceholder').style.display = 'flex';
    document.getElementById('recBtnStart').style.display    = 'inline-flex';
    document.getElementById('recBtnStop').style.display     = 'none';
    document.getElementById('recBtnScan').disabled          = true;
    document.getElementById('recBtnLive').style.display     = 'none';
    document.getElementById('recCamStatus').textContent     = 'Camera Off';
    document.getElementById('recCamStatus').classList.remove('badge-green');
}

/* ══════════════════════════════════════════════════════════════════
   RECOGNIZE — FETCH KNOWN FACES
══════════════════════════════════════════════════════════════════ */
async function fetchKnownFaces() {
    const fd = new FormData();
    fd.append('action', 'get_all_faces');
    const res  = await fetch('face_recognition.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        knownFaces = data.faces.map(f => ({
            ...f,
            descriptor: new Float32Array(f.descriptor)
        }));
        document.getElementById('recFacesLoaded').textContent = knownFaces.length + ' face' + (knownFaces.length !== 1 ? 's' : '') + ' loaded';
    }
}

/* ══════════════════════════════════════════════════════════════════
   RECOGNIZE — SCAN & MATCH
══════════════════════════════════════════════════════════════════ */
async function scanAndRecognize() {
    const vid = document.getElementById('recVideo');
    if (!vid.srcObject) return;
    if (!modelsLoaded)  { showToast('Models still loading…', 'info'); return; }
    if (knownFaces.length === 0) {
        showToast('No registered faces in the database. Register employees first.', 'info', 5000);
        return;
    }

    document.getElementById('recResultEmpty').style.display   = 'none';
    document.getElementById('recResult').style.display        = 'none';
    document.getElementById('recScanning').style.display      = 'flex';

    const canvas = document.getElementById('recCaptureCanvas');
    const ctx    = canvas.getContext('2d');
    canvas.width  = vid.videoWidth  || 640;
    canvas.height = vid.videoHeight || 480;
    ctx.drawImage(vid, 0, 0);

    const opts = new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.4 });

    try {
        const detection = await faceapi
            .detectSingleFace(canvas, opts)
            .withFaceLandmarks(true)
            .withFaceDescriptor();

        document.getElementById('recScanning').style.display = 'none';

        if (!detection) {
            showRecResult('no_face', null, 0);
            return;
        }

        // Compare against all known faces
        const query = detection.descriptor;
        let bestMatch  = null;
        let bestDist   = Infinity;

        knownFaces.forEach(known => {
            const dist = faceapi.euclideanDistance(query, known.descriptor);
            if (dist < bestDist) { bestDist = dist; bestMatch = known; }
        });

        // Threshold: ≤ 0.5 is a confident match (face-api recommendation)
        const confidence = Math.max(0, Math.round((1 - bestDist) * 100));

        if (bestDist <= 0.5) {
            showRecResult('match', bestMatch, confidence, bestDist);
        } else {
            showRecResult('no_match', bestMatch, confidence, bestDist);
        }

        // Add to scan history
        addScanHistory(detection, bestMatch, bestDist, canvas.toDataURL('image/jpeg', 0.6));

    } catch(e) {
        document.getElementById('recScanning').style.display = 'none';
        showToast('Recognition error: ' + e.message, 'error');
    }
}

function showRecResult(type, match, confidence, distance) {
    const result  = document.getElementById('recResult');
    const icon    = document.getElementById('matchIcon');
    const title   = document.getElementById('matchTitle');
    const body    = document.getElementById('matchBody');
    const conf    = document.getElementById('matchConfidence');
    const header  = document.getElementById('matchHeader');

    result.style.display = 'block';
    header.className     = 'fr-match-header';

    if (type === 'no_face') {
        header.classList.add('mh-unknown');
        icon.innerHTML  = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="32" height="32"><circle cx="12" cy="10" r="4"/><path d="M4 20c0-4.418 3.582-8 8-8s8 3.582 8 8"/><path d="M12 2v2M12 18v2M2 12h2M20 12h2"/></svg>`;
        title.textContent = 'No Face Detected';
        body.innerHTML  = '<p>Position your face in the camera frame with good lighting and try again.</p>';
        conf.innerHTML  = '';
        return;
    }

    if (type === 'match') {
        header.classList.add('mh-success');
        icon.innerHTML  = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="32" height="32"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4.418 3.582-8 8-8s8 3.582 8 8"/><path d="M7 13l3 3 7-7" stroke-width="2"/></svg>`;
        title.textContent = 'Employee Identified';
        body.innerHTML  = `
            <div class="fr-match-emp">
                ${match.face_image ? `<img src="${match.face_image}" class="fr-match-img" alt="">` : `<div class="fr-match-placeholder">${match.full_name.charAt(0)}</div>`}
                <div>
                    <div class="fr-match-name">${match.full_name}</div>
                    <div class="fr-match-id">${match.employee_id}</div>
                    ${match.designation ? `<div class="fr-match-meta">${match.designation}</div>` : ''}
                    ${match.company     ? `<div class="fr-match-meta">${match.company}${match.branch ? ' · ' + match.branch : ''}</div>` : ''}
                </div>
            </div>
            <div style="margin-top:10px;">
                <a href="view_employee.php?id=${match.id}" class="fr-btn fr-btn-outline fr-btn-sm">View Profile</a>
            </div>`;
    } else {
        header.classList.add('mh-danger');
        icon.innerHTML  = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="32" height="32"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4.418 3.582-8 8-8s8 3.582 8 8"/><path d="M9 9l6 6M15 9l-6 6" stroke-width="2"/></svg>`;
        title.textContent = 'Not Recognised';
        body.innerHTML  = `<p>This face does not match any registered employee (closest distance: ${distance ? distance.toFixed(3) : '—'}).</p>`;
    }

    const pct = Math.min(confidence, 100);
    conf.innerHTML = `
        <div class="fr-conf-label">Match confidence</div>
        <div class="fr-conf-bar-wrap">
            <div class="fr-conf-bar" style="width:${pct}%; background:${pct >= 70 ? '#22c55e' : pct >= 40 ? '#f59e0b' : '#ef4444'}"></div>
        </div>
        <div class="fr-conf-pct">${pct}%</div>`;
}

function resetRecResult() {
    document.getElementById('recResult').style.display      = 'none';
    document.getElementById('recResultEmpty').style.display = 'flex';
}

/* ══════════════════════════════════════════════════════════════════
   LIVE RECOGNITION
══════════════════════════════════════════════════════════════════ */
let liveActive = false;

function toggleLiveRecognize() {
    if (liveActive) {
        stopLiveRecognize();
    } else {
        startLiveRecognize();
    }
}

function startLiveRecognize() {
    liveActive = true;
    document.getElementById('recBtnLive').innerHTML = `
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14"><rect x="5" y="5" width="10" height="10" rx="1"/></svg>
        Stop Live`;
    liveRecognizeInterval = setInterval(() => scanAndRecognize(), 2500);
    showToast('Live recognition started — scanning every 2.5s', 'info');
}

function stopLiveRecognize() {
    liveActive = false;
    if (liveRecognizeInterval) { clearInterval(liveRecognizeInterval); liveRecognizeInterval = null; }
    const btn = document.getElementById('recBtnLive');
    if (btn) btn.innerHTML = `<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14"><circle cx="10" cy="10" r="3"/><path d="M3.5 3.5l2 2M16.5 3.5l-2 2M3.5 16.5l2-2M16.5 16.5l-2-2"/></svg> Live Mode`;
}

/* ══════════════════════════════════════════════════════════════════
   SCAN HISTORY
══════════════════════════════════════════════════════════════════ */
function addScanHistory(detection, match, distance, imageDataUrl) {
    const matched = match && distance <= 0.5;
    const entry = {
        time:     new Date().toLocaleTimeString(),
        name:     matched ? match.full_name : 'Unknown',
        matched,
        distance: distance ? distance.toFixed(3) : '—',
    };
    scanHistoryArr.unshift(entry);
    if (scanHistoryArr.length > 10) scanHistoryArr.pop();

    const histEl = document.getElementById('scanHistory');
    const listEl = document.getElementById('scanHistoryList');
    histEl.style.display = 'block';

    listEl.innerHTML = scanHistoryArr.map(h => `
        <div class="fr-history-item">
            <span class="fr-history-dot ${h.matched ? 'green' : 'red'}"></span>
            <span class="fr-history-name">${h.name}</span>
            <span class="fr-history-time">${h.time}</span>
        </div>`).join('');
}

/* ══════════════════════════════════════════════════════════════════
   MANAGE — FILTER
══════════════════════════════════════════════════════════════════ */
function filterManage(q) {
    q = q.trim().toLowerCase();
    document.querySelectorAll('.fr-manage-item').forEach(item => {
        item.style.display = (!q || item.dataset.manageSearch.includes(q)) ? 'flex' : 'none';
    });
}

/* ══════════════════════════════════════════════════════════════════
   UTILITIES
══════════════════════════════════════════════════════════════════ */
function updateStatCounts() {
    const reg    = document.querySelectorAll('.fr-emp-item.has-face').length;
    const total  = document.querySelectorAll('.fr-emp-item').length;
    document.getElementById('statRegistered').textContent = reg;
    document.getElementById('statUnreg').textContent      = total - reg;
}

let toastTimer;
function showToast(msg, type = 'info', duration = 3500) {
    const t = document.getElementById('frToast');
    const colors = { success: '#22c55e', error: '#ef4444', info: '#3b82f6', warning: '#f59e0b' };
    t.style.borderLeftColor = colors[type] || colors.info;
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), duration);
}
</script>

<!-- ════════════════════════════════════════════════════════════════
     STYLES
═══════════════════════════════════════════════════════════════════ -->
<style>
/* ─── Reset / base ─────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;}
.fr-page{padding:0 0 40px;}

/* ─── Top bar ──────────────────────────────────────────────────── */
.fr-top-bar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:24px;padding:20px 0 20px;}
.fr-top-left{display:flex;align-items:center;gap:14px;}
.fr-title-icon{width:46px;height:46px;background:#f0f0f0;border-radius:12px;display:flex;align-items:center;justify-content:center;color:#111;flex-shrink:0;}
.fr-h1{font-size:22px;font-weight:700;margin:0 0 2px;color:#111;letter-spacing:-.3px;}
.fr-subtitle{font-size:13px;color:#6b7280;margin:0;}

.fr-top-stats{display:flex;align-items:center;gap:0;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;}
.fr-stat{padding:12px 20px;text-align:center;}
.fr-stat-val{display:block;font-size:22px;font-weight:700;color:#111;line-height:1;}
.fr-stat-lbl{display:block;font-size:11px;color:#6b7280;margin-top:2px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;}
.fr-stat-div{width:1px;background:#e5e7eb;align-self:stretch;}

/* ─── Mode tabs ─────────────────────────────────────────────────── */
.fr-mode-tabs{display:flex;gap:4px;margin-bottom:24px;background:#f3f4f6;border-radius:10px;padding:4px;}
.fr-mode-tab{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;background:transparent;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;color:#6b7280;transition:all .2s;font-family:inherit;}
.fr-mode-tab:hover{color:#111;background:rgba(255,255,255,.7);}
.fr-mode-tab.active{background:#fff;color:#111;box-shadow:0 1px 4px rgba(0,0,0,.1);}

/* ─── Two column layout ─────────────────────────────────────────── */
.fr-two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;}
@media(max-width:900px){.fr-two-col{grid-template-columns:1fr;}}

/* ─── Cards ─────────────────────────────────────────────────────── */
.fr-camera-card,.fr-form-card,.fr-result-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;}
.fr-card-header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #f0f0f0;background:#fafafa;}
.fr-card-title{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;color:#111;}

/* ─── Badge ─────────────────────────────────────────────────────── */
.fr-badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;background:#f3f4f6;color:#6b7280;letter-spacing:.3px;}
.fr-badge.badge-green{background:#dcfce7;color:#166534;}

/* ─── Video area ─────────────────────────────────────────────────── */
.fr-video-wrap{position:relative;width:100%;aspect-ratio:4/3;background:#0a0a0a;overflow:hidden;}
.fr-video-wrap video{width:100%;height:100%;object-fit:cover;transform:scaleX(-1);}
.fr-overlay-canvas{position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;}
.fr-video-placeholder{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;color:#4b5563;background:#111;}
.fr-video-placeholder span{font-size:13px;color:#6b7280;}

/* Scan frame corners */
.fr-scan-frame{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:55%;aspect-ratio:1;pointer-events:none;}
.fr-scan-corner{position:absolute;width:20px;height:20px;border-color:#fff;border-style:solid;border-width:0;opacity:.7;}
.fr-scan-corner.tl{top:0;left:0;border-top-width:3px;border-left-width:3px;border-radius:3px 0 0 0;}
.fr-scan-corner.tr{top:0;right:0;border-top-width:3px;border-right-width:3px;border-radius:0 3px 0 0;}
.fr-scan-corner.bl{bottom:0;left:0;border-bottom-width:3px;border-left-width:3px;border-radius:0 0 0 3px;}
.fr-scan-corner.br{bottom:0;right:0;border-bottom-width:3px;border-right-width:3px;border-radius:0 0 3px 0;}
.fr-scan-line{position:absolute;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,rgba(0,200,100,.8),transparent);top:0;transition:none;}
.fr-scan-line.scanning{animation:scanAnim 2s ease-in-out infinite;}
@keyframes scanAnim{0%{top:0%;opacity:1;}50%{top:95%;}100%{top:0%;opacity:1;}}

/* ─── Camera controls ────────────────────────────────────────────── */
.fr-cam-controls{display:flex;flex-wrap:wrap;gap:8px;padding:14px 16px;border-bottom:1px solid #f0f0f0;}
.fr-detect-info{padding:8px 16px 10px;font-size:12px;min-height:32px;}
.fr-detect-ok{display:inline-flex;align-items:center;gap:6px;color:#16a34a;font-weight:600;}
.fr-detect-fail{display:inline-flex;align-items:center;gap:6px;color:#dc2626;font-weight:600;}
.fr-detect-loading{color:#3b82f6;font-weight:600;}

/* ─── Preview ────────────────────────────────────────────────────── */
.fr-preview-wrap{padding:10px 16px;display:flex;align-items:center;gap:12px;background:#f9fafb;border-top:1px solid #f0f0f0;}
.fr-preview-img{width:64px;height:64px;border-radius:8px;object-fit:cover;border:2px solid #e5e7eb;}
.fr-preview-meta{font-size:12px;}

/* ─── Employee search & list ─────────────────────────────────────── */
.fr-search-wrap{position:relative;padding:12px 16px;border-bottom:1px solid #f0f0f0;}
.fr-search-icon{position:absolute;left:28px;top:50%;transform:translateY(-50%);color:#9ca3af;pointer-events:none;}
.fr-search-input{width:100%;padding:10px 36px 10px 36px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;background:#fff;color:#111;transition:border-color .2s;}
.fr-search-input:focus{outline:none;border-color:#111;}
.fr-search-clear{position:absolute;right:24px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:16px;color:#9ca3af;line-height:1;}
.fr-search-clear:hover{color:#111;}

.fr-emp-list{max-height:280px;overflow-y:auto;border-bottom:1px solid #f0f0f0;scrollbar-width:thin;scrollbar-color:#e5e7eb transparent;}
.fr-emp-item{display:flex;align-items:center;gap:12px;padding:10px 16px;cursor:pointer;transition:background .15s;border-bottom:1px solid #f9fafb;}
.fr-emp-item:hover{background:#f9fafb;}
.fr-emp-item.selected{background:#eff6ff;border-left:3px solid #3b82f6;}
.fr-emp-item.has-face{background:#f0fdf4;}
.fr-emp-item.has-face.selected{background:#dcfce7;border-left-color:#22c55e;}

.fr-emp-avatar{width:38px;height:38px;border-radius:8px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:#6b7280;flex-shrink:0;overflow:hidden;}
.fr-emp-avatar.avatar-green{background:#dcfce7;color:#166534;}
.fr-emp-avatar img{width:100%;height:100%;object-fit:cover;}
.fr-emp-info{flex:1;min-width:0;}
.fr-emp-name{font-size:13px;font-weight:600;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.fr-emp-meta{font-size:11px;color:#9ca3af;margin-top:1px;}
.fr-emp-face-badge{width:20px;height:20px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#166534;flex-shrink:0;}

/* ─── Selected employee card ──────────────────────────────────────── */
.fr-selected-card{padding:14px 16px;border-top:1px solid #f0f0f0;background:#fafafa;}
.fr-selected-inner{display:flex;align-items:center;gap:12px;}
.fr-selected-avatar{width:48px;height:48px;border-radius:10px;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;color:#6b7280;flex-shrink:0;overflow:hidden;}
.fr-selected-avatar.avatar-img img{width:100%;height:100%;object-fit:cover;}
.fr-selected-name{font-size:14px;font-weight:700;color:#111;}
.fr-selected-sub{font-size:12px;color:#6b7280;margin:2px 0 5px;}

.fr-inline-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;}
.fr-inline-badge.green{background:#dcfce7;color:#166534;}
.fr-inline-badge.gray{background:#f3f4f6;color:#9ca3af;}

/* ─── Tips ───────────────────────────────────────────────────────── */
.fr-tips{padding:14px 16px;border-top:1px solid #f0f0f0;}
.fr-tip{display:flex;align-items:flex-start;gap:9px;font-size:12px;color:#6b7280;margin-bottom:7px;line-height:1.4;}
.fr-tip:last-child{margin-bottom:0;}
.fr-tip-num{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#e5e7eb;color:#374151;font-size:10px;font-weight:700;flex-shrink:0;margin-top:1px;}

/* ─── Buttons ────────────────────────────────────────────────────── */
.fr-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;border:1px solid transparent;font-family:inherit;text-decoration:none;}
.fr-btn:disabled{opacity:.5;cursor:not-allowed;}
.fr-btn-primary{background:#111;color:#fff;border-color:#111;}.fr-btn-primary:not(:disabled):hover{background:#333;}
.fr-btn-outline{background:#fff;color:#374151;border-color:#d1d5db;}.fr-btn-outline:hover{background:#f9fafb;}
.fr-btn-ghost{background:transparent;color:#6b7280;border-color:transparent;}.fr-btn-ghost:hover{background:#f3f4f6;color:#111;}
.fr-btn-danger-outline{background:#fff;color:#dc2626;border-color:#fca5a5;}.fr-btn-danger-outline:hover{background:#fff7f7;}
.fr-btn-save{background:#111;color:#fff;border:none;padding:11px 20px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;font-family:inherit;transition:background .2s;}.fr-btn-save:not(:disabled):hover{background:#333;}.fr-btn-save:disabled{opacity:.5;cursor:not-allowed;}
.fr-btn-sm{padding:7px 13px;font-size:12px;}

.fr-btn-mini{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;cursor:pointer;transition:all .2s;}
.fr-btn-danger-outline.fr-btn-mini:hover{background:#fee2e2;border-color:#fca5a5;color:#dc2626;}
.fr-btn-primary-mini{background:#111;border-color:#111;color:#fff;}.fr-btn-primary-mini:hover{background:#333;}

/* ─── Result area ────────────────────────────────────────────────── */
.fr-result-empty,.fr-result-scanning{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:50px 20px;color:#9ca3af;text-align:center;}
.fr-result-empty svg{color:#d1d5db;}
.fr-result-empty p,.fr-result-scanning p{font-size:13px;margin:0;line-height:1.5;}

.fr-result-match{padding:20px;}
.fr-match-header{display:flex;align-items:center;gap:14px;padding:16px;border-radius:10px;margin-bottom:14px;}
.mh-success{background:#f0fdf4;}.mh-danger{background:#fff7f7;}.mh-unknown{background:#f9fafb;}
.mh-success .fr-match-icon svg{color:#22c55e;}
.mh-danger  .fr-match-icon svg{color:#ef4444;}
.mh-unknown .fr-match-icon svg{color:#9ca3af;}
.fr-match-title{font-size:16px;font-weight:700;color:#111;}

.fr-match-emp{display:flex;align-items:center;gap:12px;}
.fr-match-img{width:52px;height:52px;border-radius:8px;object-fit:cover;border:2px solid #e5e7eb;}
.fr-match-placeholder{width:52px;height:52px;border-radius:8px;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;color:#6b7280;}
.fr-match-name{font-size:15px;font-weight:700;color:#111;}
.fr-match-id{font-size:12px;color:#6b7280;font-weight:600;margin:2px 0;}
.fr-match-meta{font-size:12px;color:#9ca3af;}

.fr-conf-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.fr-conf-bar-wrap{height:6px;background:#f3f4f6;border-radius:99px;overflow:hidden;width:100%;}
.fr-conf-bar{height:100%;border-radius:99px;transition:width .6s;}
.fr-conf-pct{font-size:13px;font-weight:700;color:#111;margin-top:4px;}
.fr-match-confidence{margin-top:12px;padding-top:12px;border-top:1px solid #f0f0f0;}

/* ─── Scan history ───────────────────────────────────────────────── */
.fr-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;padding:14px 20px 8px;}
.fr-scan-history{border-top:1px solid #f0f0f0;}
.fr-history-item{display:flex;align-items:center;gap:9px;padding:7px 20px;font-size:12px;border-bottom:1px solid #f9fafb;}
.fr-history-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.fr-history-dot.green{background:#22c55e;}.fr-history-dot.red{background:#ef4444;}
.fr-history-name{flex:1;font-weight:600;color:#374151;}
.fr-history-time{color:#9ca3af;}

/* ─── Manage grid ────────────────────────────────────────────────── */
.fr-manage-bar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;}
.fr-manage-legend{font-size:12px;color:#6b7280;display:flex;align-items:center;gap:4px;}
.fr-legend-dot{width:10px;height:10px;border-radius:50%;display:inline-block;}
.fr-legend-dot.green{background:#22c55e;}.fr-legend-dot.gray{background:#d1d5db;}

.fr-manage-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:10px;}
.fr-manage-item{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px;}
.fr-manage-item.has-face{border-color:#bbf7d0;background:#f0fdf4;}
.fr-manage-avatar{width:42px;height:42px;border-radius:8px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:700;color:#6b7280;flex-shrink:0;overflow:hidden;}
.fr-manage-avatar.avatar-green{background:#dcfce7;color:#166534;}
.fr-manage-avatar img{width:100%;height:100%;object-fit:cover;}
.fr-manage-info{flex:1;min-width:0;}
.fr-manage-name{font-size:13px;font-weight:600;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.fr-manage-id{font-size:11px;color:#9ca3af;font-weight:600;}
.fr-manage-desig{font-size:11px;color:#6b7280;}
.fr-manage-status{display:flex;align-items:center;gap:6px;flex-shrink:0;}
.fr-status-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap;}
.fr-status-badge.green{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
.fr-status-badge.gray{background:#f3f4f6;color:#9ca3af;}

/* ─── Spinner ─────────────────────────────────────────────────────── */
.fr-spinner{width:32px;height:32px;border:3px solid #e5e7eb;border-top-color:#111;border-radius:50%;animation:spin .8s linear infinite;}
.fr-spinner-mini{display:inline-block;width:12px;height:12px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;}
@keyframes spin{to{transform:rotate(360deg);}}

/* ─── Toast ───────────────────────────────────────────────────────── */
.fr-toast{position:fixed;bottom:28px;right:28px;background:#fff;border:1px solid #e5e7eb;border-left:4px solid #3b82f6;border-radius:10px;padding:12px 18px;font-size:13px;font-weight:600;color:#111;box-shadow:0 4px 20px rgba(0,0,0,.12);z-index:9999;transform:translateY(20px);opacity:0;transition:all .3s;pointer-events:none;max-width:340px;}
.fr-toast.show{transform:translateY(0);opacity:1;}

/* ─── Responsive ──────────────────────────────────────────────────── */
@media(max-width:640px){
  .fr-top-bar{flex-direction:column;align-items:flex-start;}
  .fr-top-stats{width:100%;}
  .fr-mode-tabs{overflow-x:auto;}
  .fr-mode-tab{white-space:nowrap;}
  .fr-manage-grid{grid-template-columns:1fr;}
}
</style>

<?php include 'footer.php'; ?>
