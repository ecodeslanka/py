<?php
ob_start();
include 'config.php';

$success_message = '';
$error_message   = '';
$edit_mode       = false;
$edit_incentive  = null;

mysqli_query($conn, "ALTER TABLE incentive_types ADD COLUMN IF NOT EXISTS calculation_rules JSON DEFAULT NULL");
mysqli_query($conn, "ALTER TABLE incentive_types ADD COLUMN IF NOT EXISTS allowed_category_ids TEXT DEFAULT NULL");
mysqli_query($conn, "ALTER TABLE incentive_types ADD COLUMN IF NOT EXISTS discretionary_support TINYINT(1) NOT NULL DEFAULT 0");

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    if (mysqli_query($conn, "DELETE FROM incentive_types WHERE id = $id")) {
        $success_message = "Incentive type deleted successfully!";
    } else {
        $error_message = "Delete failed: " . mysqli_error($conn);
    }
}

if (isset($_GET['edit']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $id     = intval($_GET['edit']);
    $result = mysqli_query($conn, "SELECT * FROM incentive_types WHERE id = $id");
    if ($result && mysqli_num_rows($result) > 0) {
        $edit_incentive = mysqli_fetch_assoc($result);
        $edit_mode      = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type_name             = mysqli_real_escape_string($conn, trim($_POST['type_name']));
    $description           = mysqli_real_escape_string($conn, trim($_POST['description']));
    $active                = isset($_POST['active']) ? 1 : 0;
    $discretionary_support = isset($_POST['discretionary_support']) ? 1 : 0;
    $type_code             = mysqli_real_escape_string($conn, strtoupper(preg_replace('/\s+/', '_', trim($_POST['type_name']))));

    $calc_rules = [];
    if (!empty($_POST['calc_label']) && is_array($_POST['calc_label'])) {
        foreach ($_POST['calc_label'] as $i => $label) {
            $label = trim($label);
            $rate  = isset($_POST['calc_rate'][$i]) && $_POST['calc_rate'][$i] !== '' ? floatval($_POST['calc_rate'][$i]) : null;
            if ($label !== '') {
                $calc_rules[] = ['label' => $label, 'rate' => $rate];
            }
        }
    }

    $allowed_cats = '';
    if (!empty($_POST['allowed_categories']) && is_array($_POST['allowed_categories'])) {
        $allowed_cats = implode(',', array_map('intval', $_POST['allowed_categories']));
    }

    $calc_json   = mysqli_real_escape_string($conn, json_encode($calc_rules));
    $allowed_esc = mysqli_real_escape_string($conn, $allowed_cats);

    if (empty($type_name)) {
        $error_message = "Type Name is required.";
    } else {
        if (!empty($_POST['incentive_id'])) {
            $id  = intval($_POST['incentive_id']);
            $sql = "UPDATE incentive_types SET
                        type_name             = '$type_name',
                        description           = '$description',
                        active                = $active,
                        discretionary_support = $discretionary_support,
                        calculation_rules     = '$calc_json',
                        allowed_category_ids  = '$allowed_esc'
                    WHERE id = $id";
            if (mysqli_query($conn, $sql)) {
                $success_message = "Incentive type updated successfully!";
                $edit_mode       = false;
                $edit_incentive  = null;
            } else {
                $error_message  = "Update failed: " . mysqli_error($conn);
                $edit_mode      = true;
                $edit_incentive = [
                    'id'                   => $id,
                    'type_name'            => $_POST['type_name'],
                    'description'          => $_POST['description'],
                    'active'               => $active,
                    'discretionary_support'=> $discretionary_support,
                    'calculation_rules'    => json_encode($calc_rules),
                    'allowed_category_ids' => $allowed_cats,
                ];
            }
        } else {
            $base_code = $type_code;
            $check     = mysqli_query($conn, "SELECT id FROM incentive_types WHERE type_code='$type_code'");
            if ($check && mysqli_num_rows($check) > 0) {
                $type_code = $base_code . '_' . rand(100, 999);
            }
            $sql = "INSERT INTO incentive_types
                        (type_code, type_name, description, active, discretionary_support, calculation_rules, allowed_category_ids)
                    VALUES
                        ('$type_code', '$type_name', '$description', $active, $discretionary_support, '$calc_json', '$allowed_esc')";
            if (mysqli_query($conn, $sql)) {
                $success_message = "Incentive type created successfully!";
            } else {
                $error_message = "Save failed: " . mysqli_error($conn);
            }
        }
    }
}

$types_result = mysqli_query($conn, "SELECT * FROM incentive_types ORDER BY created_at DESC");

$cats_result    = mysqli_query($conn, "SELECT id, category_code, category_name FROM staff_categories WHERE active = 1 ORDER BY category_name ASC");
$all_categories = [];
if ($cats_result) {
    while ($row = mysqli_fetch_assoc($cats_result)) {
        $all_categories[] = $row;
    }
}

$entry_counts = [];
$ec_res = mysqli_query($conn, "
    SELECT incentive_type_id, COUNT(DISTINCT CONCAT(employee_id,'_',payroll_period_id)) as cnt
    FROM incentive_entries
    GROUP BY incentive_type_id
");
if ($ec_res) {
    while ($ec = mysqli_fetch_assoc($ec_res)) {
        $entry_counts[$ec['incentive_type_id']] = $ec['cnt'];
    }
}

include 'header.php';
?>

<div class="it-page-header">
    <div class="it-header-left">
        <div class="it-header-icon"><i class="fa-solid fa-tag"></i></div>
        <div>
            <h2 class="page-title">Incentive Types</h2>
            <p class="it-page-sub">Manage incentive types, calculation rules, and allowed staff categories</p>
        </div>
    </div>
    <button onclick="openAddModal()" class="it-btn it-btn-primary">
        <i class="fa-solid fa-plus"></i> Add New Incentive Type
    </button>
</div>

<?php if ($success_message): ?>
<div class="it-alert it-alert-success" id="mainAlert">
    <i class="fa-solid fa-circle-check"></i>
    <span><?php echo htmlspecialchars($success_message); ?></span>
    <button onclick="this.parentElement.remove()" class="it-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="it-alert it-alert-error" id="mainAlert">
    <i class="fa-solid fa-circle-exclamation"></i>
    <span><?php echo htmlspecialchars($error_message); ?></span>
    <button onclick="this.parentElement.remove()" class="it-alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>

<?php
$total_types = 0; $active_types = 0; $entry_types = 0; $disc_types = 0;
$stats_res = mysqli_query($conn, "SELECT active, calculation_rules, allowed_category_ids, discretionary_support FROM incentive_types");
if ($stats_res) {
    while ($st = mysqli_fetch_assoc($stats_res)) {
        $total_types++;
        if ($st['active']) $active_types++;
        if ($st['discretionary_support']) $disc_types++;
        $r  = json_decode($st['calculation_rules'] ?? '[]', true) ?: [];
        $ne = false;
        if (empty($r)) { $ne = true; }
        else { foreach ($r as $ri) { if ($ri['rate'] === null || $ri['rate'] === '' || floatval($ri['rate']) == 0) { $ne = true; break; } } }
        if ($ne) $entry_types++;
    }
}
?>
<div class="it-stats-bar">
    <div class="it-stat-card">
        <div class="it-stat-num"><?php echo $total_types; ?></div>
        <div class="it-stat-lbl">Total Types</div>
    </div>
    <div class="it-stat-card it-stat-green">
        <div class="it-stat-num"><?php echo $active_types; ?></div>
        <div class="it-stat-lbl">Active</div>
    </div>
    <div class="it-stat-card it-stat-blue">
        <div class="it-stat-num"><?php echo $entry_types; ?></div>
        <div class="it-stat-lbl">Manual Entry Types</div>
    </div>
    <div class="it-stat-card it-stat-amber">
        <div class="it-stat-num"><?php echo array_sum($entry_counts); ?></div>
        <div class="it-stat-lbl">Total Entries Saved</div>
    </div>
    <div class="it-stat-card it-stat-cyan">
        <div class="it-stat-num"><?php echo $disc_types; ?></div>
        <div class="it-stat-lbl">Discretionary</div>
    </div>
</div>

<div class="it-card">
    <div class="it-card-toolbar">
        <div class="it-card-title-wrap">
            <h3 class="it-card-title">All Incentive Types</h3>
            <span class="it-card-count" id="tableCount">--</span>
        </div>
        <div class="it-toolbar-right">
            <div class="it-search-wrap">
                <i class="fa-solid fa-magnifying-glass it-search-icon"></i>
                <input type="text" id="typeSearchInput" class="it-search-input"
                       placeholder="Search types..." oninput="filterTable(this.value)" autocomplete="off">
                <button class="it-search-clear" id="searchClear" onclick="clearSearch()" style="display:none;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="it-filter-tabs">
                <button class="it-ftab active" onclick="setStatusFilter('all', this)">All</button>
                <button class="it-ftab" onclick="setStatusFilter('active', this)">Active</button>
                <button class="it-ftab" onclick="setStatusFilter('inactive', this)">Inactive</button>
                <button class="it-ftab it-ftab-entry" onclick="setStatusFilter('entry', this)">
                    <i class="fa-solid fa-table-cells-large"></i> Entry Types
                </button>
                <button class="it-ftab it-ftab-disc" onclick="setStatusFilter('disc', this)">
                    <i class="fa-solid fa-hand-holding-heart"></i> Discretionary
                </button>
            </div>
        </div>
    </div>

    <?php
    $types_result2 = mysqli_query($conn, "SELECT * FROM incentive_types ORDER BY created_at DESC");
    $has_rows      = $types_result2 && mysqli_num_rows($types_result2) > 0;
    ?>
    <?php if ($has_rows): ?>
    <div class="it-table-wrap">
        <table class="it-table" id="typesTable">
            <thead>
                <tr>
                    <th class="it-th">#</th>
                    <th class="it-th">Type Name</th>
                    <th class="it-th">Description</th>
                    <th class="it-th">Components / Rates</th>
                    <th class="it-th">Allowed Categories</th>
                    <th class="it-th it-th-disc">Discretionary</th>
                    <th class="it-th">Status</th>
                    <th class="it-th">Created</th>
                    <th class="it-th">Actions</th>
                    <th class="it-th it-th-entry">Entry</th>
                </tr>
            </thead>
            <tbody id="typesTableBody">
                <?php while ($incentive = mysqli_fetch_assoc($types_result2)):
                    $rules     = json_decode($incentive['calculation_rules'] ?? '[]', true) ?: [];
                    $cat_ids   = array_filter(explode(',', $incentive['allowed_category_ids'] ?? ''));
                    $cat_names = [];
                    foreach ($all_categories as $c) {
                        if (in_array($c['id'], $cat_ids)) $cat_names[] = $c;
                    }

                    $needs_entry = false;
                    if (empty($rules)) {
                        $needs_entry = true;
                    } else {
                        foreach ($rules as $r) {
                            if ($r['rate'] === null || $r['rate'] === '' || floatval($r['rate']) == 0) {
                                $needs_entry = true; break;
                            }
                        }
                    }

                    $is_disc     = !empty($incentive['discretionary_support']);
                    $entry_count = $entry_counts[$incentive['id']] ?? 0;

                    $search_str = strtolower(
                        $incentive['type_name'] . ' ' .
                        $incentive['description'] . ' ' .
                        implode(' ', array_column($rules, 'label')) . ' ' .
                        implode(' ', array_column($cat_names, 'category_name')) . ' ' .
                        implode(' ', array_column($cat_names, 'category_code'))
                    );
                ?>
                <tr class="it-tr <?php echo $incentive['active'] ? 'it-tr-active' : 'it-tr-inactive'; ?> <?php echo $needs_entry ? 'it-tr-entry' : ''; ?> <?php echo $is_disc ? 'it-tr-disc' : ''; ?>"
                    data-search="<?php echo htmlspecialchars($search_str); ?>"
                    data-active="<?php echo $incentive['active'] ? '1' : '0'; ?>"
                    data-entry="<?php echo $needs_entry ? '1' : '0'; ?>"
                    data-disc="<?php echo $is_disc ? '1' : '0'; ?>">

                    <td class="it-td">
                        <span class="it-id-num"><?php echo intval($incentive['id']); ?></span>
                    </td>

                    <td class="it-td">
                        <div class="it-name-cell">
                            <div class="it-type-avatar"><?php echo strtoupper(substr($incentive['type_name'], 0, 1)); ?></div>
                            <div>
                                <div class="it-type-name"><?php echo htmlspecialchars($incentive['type_name']); ?></div>
                                <?php if ($incentive['type_code'] ?? ''): ?>
                                <div class="it-type-code"><?php echo htmlspecialchars($incentive['type_code']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>

                    <td class="it-td">
                        <?php if ($incentive['description']): ?>
                        <span class="it-desc-text"><?php echo htmlspecialchars($incentive['description']); ?></span>
                        <?php else: ?><span class="it-null">--</span><?php endif; ?>
                    </td>

                    <td class="it-td">
                        <?php if ($rules): ?>
                        <div class="it-rules-wrap">
                            <?php foreach ($rules as $r):
                                $has_rate = ($r['rate'] !== null && $r['rate'] !== '' && floatval($r['rate']) > 0);
                            ?>
                            <div class="it-rule-chip <?php echo $has_rate ? '' : 'it-rule-chip-entry'; ?>">
                                <span class="it-rule-label"><?php echo htmlspecialchars($r['label']); ?></span>
                                <?php if ($has_rate): ?>
                                <span class="it-rule-rate"><?php echo number_format((float)$r['rate'], 1); ?>%</span>
                                <?php else: ?>
                                <span class="it-rule-manual"><i class="fa-solid fa-pen-to-square"></i></span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="it-rule-chip it-rule-chip-entry">
                            <span class="it-rule-label">Free Entry</span>
                            <span class="it-rule-manual"><i class="fa-solid fa-pen-to-square"></i></span>
                        </div>
                        <?php endif; ?>
                    </td>

                    <td class="it-td">
                        <?php if ($cat_names): ?>
                        <div class="it-cats-wrap">
                            <?php foreach (array_slice($cat_names, 0, 3) as $cn): ?>
                            <span class="it-cat-badge"><?php echo htmlspecialchars($cn['category_code']); ?></span>
                            <?php endforeach; ?>
                            <?php if (count($cat_names) > 3): ?>
                            <span class="it-cat-more">+<?php echo count($cat_names) - 3; ?></span>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <span class="it-all-badge"><i class="fa-solid fa-users"></i> All</span>
                        <?php endif; ?>
                    </td>

                    <!-- DISCRETIONARY SUPPORT COLUMN -->
                    <td class="it-td">
                        <?php if ($is_disc): ?>
                        <span class="it-disc-badge it-disc-yes">
                            <i class="fa-solid fa-hand-holding-heart"></i> Yes
                        </span>
                        <?php else: ?>
                        <span class="it-disc-badge it-disc-no">
                            <i class="fa-solid fa-minus"></i> No
                        </span>
                        <?php endif; ?>
                    </td>

                    <td class="it-td">
                        <?php if ($incentive['active']): ?>
                        <span class="it-status-badge it-status-active">
                            <i class="fa-solid fa-circle" style="font-size:6px;"></i> Active
                        </span>
                        <?php else: ?>
                        <span class="it-status-badge it-status-inactive">
                            <i class="fa-solid fa-circle" style="font-size:6px;"></i> Inactive
                        </span>
                        <?php endif; ?>
                    </td>

                    <td class="it-td">
                        <span class="it-date"><?php echo date('d M Y', strtotime($incentive['created_at'])); ?></span>
                    </td>

                    <td class="it-td">
                        <div class="it-action-group">
                            <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($incentive), ENT_QUOTES); ?>)"
                                    class="it-action-btn it-action-edit" title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </button>
                            <a href="incentive_types.php?delete=<?php echo intval($incentive['id']); ?>"
                               class="it-action-btn it-action-delete" title="Delete"
                               onclick="return confirm('Delete \'<?php echo htmlspecialchars(addslashes($incentive['type_name'])); ?>\'? This cannot be undone.')">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </div>
                    </td>

                    <td class="it-td">
                        <?php if ($needs_entry): ?>
                        <a href="incentive_entry.php?type_id=<?php echo intval($incentive['id']); ?>"
                           class="it-enter-btn" title="Enter employee data for this incentive type">
                            <i class="fa-solid fa-table-cells-large"></i>
                            Enter Data
                            <?php if ($entry_count > 0): ?>
                            <span class="it-entry-count"><?php echo $entry_count; ?></span>
                            <?php endif; ?>
                        </a>
                        <?php else: ?>
                        <div class="it-auto-badge" title="Rates are auto-calculated">
                            <i class="fa-solid fa-gear"></i> Auto
                        </div>
                        <?php endif; ?>
                    </td>

                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <div id="noResultsRow" style="display:none;" class="it-no-results">
            <i class="fa-solid fa-magnifying-glass"></i>
            <p>No incentive types match your search.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="it-empty-state">
        <div class="it-empty-icon"><i class="fa-solid fa-tag"></i></div>
        <h3>No incentive types yet</h3>
        <p>Create your first incentive type to get started.</p>
        <button onclick="openAddModal()" class="it-btn it-btn-primary" style="margin-top:12px;">
            <i class="fa-solid fa-plus"></i> Add First Type
        </button>
    </div>
    <?php endif; ?>
</div>


<!-- MODAL -->
<div id="incentiveModal" class="it-modal-bg">
    <div class="it-modal">

        <div class="it-modal-header">
            <div class="it-modal-header-inner">
                <div class="it-modal-icon"><i class="fa-solid fa-tag"></i></div>
                <div>
                    <h3 class="it-modal-title" id="modalTitle">Add New Incentive Type</h3>
                    <p class="it-modal-sub" id="modalSubtitle">Fill in the details below</p>
                </div>
            </div>
            <button class="it-modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" action="incentive_types.php" id="incentiveForm">
            <input type="hidden" name="incentive_id" id="incentive_id">

            <div class="it-modal-body">

                <!-- 01 Basic Info -->
                <div class="it-modal-section">
                    <div class="it-section-label">
                        <span class="it-section-num">01</span>
                        <i class="fa-solid fa-circle-info"></i> Basic Information
                    </div>
                    <div class="it-form-row-2">
                        <div class="it-form-group">
                            <label class="it-form-label">Type Name <span class="it-required">*</span></label>
                            <input type="text" id="type_name" name="type_name" class="it-form-input"
                                   placeholder="e.g. Sales Incentive, Staff Bonus" required>
                        </div>
                        <div class="it-form-group">
                            <label class="it-form-label">Description</label>
                            <input type="text" id="description" name="description" class="it-form-input"
                                   placeholder="Optional description">
                        </div>
                    </div>
                </div>

                <!-- 02 Calculation Rules -->
                <div class="it-modal-section">
                    <div class="it-section-label">
                        <span class="it-section-num">02</span>
                        <i class="fa-solid fa-calculator"></i> Calculation Components &amp; Rates
                    </div>

                    <!-- Enforce 100% toggle -->
                    <div class="it-enforce-wrap">
                        <label class="it-enforce-label" id="enforceToggleLabel">
                            <input type="checkbox" id="enforce100Chk" onchange="onEnforceChange()" checked>
                            <span class="it-toggle-track"><span class="it-toggle-thumb"></span></span>
                            <span class="it-enforce-text">Enforce rates must total exactly <strong>100%</strong></span>
                        </label>
                        <div class="it-enforce-hint">Disable for manual-entry components (no fixed rate)</div>
                    </div>

                    <!-- DISCRETIONARY SUPPORT CHECKBOX -->
                    <div class="it-disc-wrap">
                        <label class="it-disc-label" id="discToggleLabel">
                            <input type="checkbox" name="discretionary_support" id="discretionary_support" onchange="onDiscChange()">
                            <span class="it-toggle-track it-disc-track"><span class="it-toggle-thumb"></span></span>
                            <div class="it-disc-label-text">
                                <span class="it-disc-title">
                                    <i class="fa-solid fa-hand-holding-heart"></i> Discretionary Support
                                </span>
                                <span class="it-disc-hint-text">
                                    When enabled, rates do <strong>not</strong> need to total 100% -- amounts are at management discretion. The 100% enforcement is automatically disabled.
                                </span>
                            </div>
                        </label>
                        <div class="it-disc-notice" id="discNotice" style="display:none;">
                            <i class="fa-solid fa-circle-info"></i>
                            Discretionary Support is ON -- 100% enforcement and tally bar are disabled.
                        </div>
                    </div>

                    <!-- Tally bar (hidden when discretionary is on) -->
                    <div id="tallySection" style="display:none; margin-bottom:14px;">
                        <div class="it-tally-wrap">
                            <div class="it-tally-track">
                                <div class="it-tally-fill" id="tallyBarFill"></div>
                            </div>
                            <div class="it-tally-info">
                                <span>Total: <strong id="tallyValue">0.00</strong>%</span>
                                <span id="tallyStatus" class="it-tally-status it-tally-under">-- needs 100%</span>
                            </div>
                        </div>
                    </div>

                    <p class="it-section-desc" id="noEnforceDesc" style="display:none;">
                        Add components with a label. Leave rate blank for manual employee-wise entry.
                        <strong>Components without a rate will show an "Enter Data" button on the types list.</strong>
                    </p>

                    <div id="calcRulesContainer"></div>
                    <button type="button" class="it-add-row-btn" onclick="addCalcRow()">
                        <i class="fa-solid fa-plus"></i> Add Component
                    </button>
                </div>

                <!-- 03 Allowed Staff Categories -->
                <div class="it-modal-section">
                    <div class="it-section-label">
                        <span class="it-section-num">03</span>
                        <i class="fa-solid fa-users"></i> Allowed Staff Categories
                    </div>
                    <p class="it-section-desc">Select which staff categories can receive this incentive. Leave none selected to allow all.</p>

                    <?php if (!empty($all_categories)): ?>
                    <div class="it-cat-search-wrap">
                        <i class="fa-solid fa-magnifying-glass it-cat-search-icon"></i>
                        <input type="text" id="catSearch" class="it-form-input it-cat-search-input"
                               placeholder="Search categories..." oninput="filterCatPills(this.value)" autocomplete="off">
                    </div>
                    <div class="it-cat-ctrl-row">
                        <button type="button" class="it-cat-ctrl-btn" onclick="selectAllCats()">
                            <i class="fa-solid fa-check-double"></i> Select All
                        </button>
                        <button type="button" class="it-cat-ctrl-btn" onclick="clearAllCats()">
                            <i class="fa-solid fa-xmark"></i> Clear All
                        </button>
                        <span class="it-cat-count-label" id="catCountLabel">0 selected (= All)</span>
                    </div>
                    <div class="it-cat-pill-grid" id="catPillGrid">
                        <?php foreach ($all_categories as $cat): ?>
                        <label class="it-cat-pill" id="catPill_<?php echo $cat['id']; ?>"
                               data-search="<?php echo strtolower(htmlspecialchars($cat['category_code'] . ' ' . $cat['category_name'])); ?>">
                            <input type="checkbox" name="allowed_categories[]"
                                   value="<?php echo $cat['id']; ?>" class="cat-chk" onchange="updateCatCount()">
                            <span class="it-cat-code"><?php echo htmlspecialchars($cat['category_code']); ?></span>
                            <span class="it-cat-name"><?php echo htmlspecialchars($cat['category_name']); ?></span>
                            <i class="fa-solid fa-check it-cat-check"></i>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="it-selected-cats" id="selectedCatsDisplay"></div>
                    <?php else: ?>
                    <p class="it-section-desc">
                        No active staff categories found.
                        <a href="staff_categories.php" target="_blank" class="it-link">
                            Manage Categories <i class="fa-solid fa-arrow-up-right-from-square"></i>
                        </a>
                    </p>
                    <?php endif; ?>
                </div>

                <!-- 04 Status -->
                <div class="it-modal-section">
                    <div class="it-section-label">
                        <span class="it-section-num">04</span>
                        <i class="fa-solid fa-toggle-on"></i> Status
                    </div>
                    <label class="it-toggle-label">
                        <input type="checkbox" name="active" id="active" class="it-toggle-input" checked onchange="updateToggleText()">
                        <span class="it-toggle-track"><span class="it-toggle-thumb"></span></span>
                        <span class="it-toggle-text" id="toggleText">Active -- available for use</span>
                    </label>
                </div>

            </div>

            <div class="it-modal-footer">
                <button type="button" class="it-btn it-btn-ghost" onclick="closeModal()">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </button>
                <button type="submit" class="it-btn it-btn-primary" id="submitBtn">
                    <i class="fa-solid fa-check"></i>
                    <span id="submitBtnText">Save Incentive Type</span>
                </button>
            </div>
        </form>
    </div>
</div>


<style>
@import url('https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,700;1,9..40,300&family=JetBrains+Mono:wght@400;600;700&display=swap');

:root {
    --it-bg:         #f5f5f4;
    --it-white:      #ffffff;
    --it-border:     #e5e7eb;
    --it-text:       #111827;
    --it-muted:      #6b7280;
    --it-ink:        #18181b;
    --it-blue:       #2563eb;
    --it-blue-light: #eff6ff;
    --it-green:      #16a34a;
    --it-green-light:#f0fdf4;
    --it-amber:      #d97706;
    --it-amber-light:#fffbeb;
    --it-red:        #dc2626;
    --it-red-light:  #fef2f2;
    --it-entry:      #7c3aed;
    --it-entry-light:#f5f3ff;
    --it-disc:       #0891b2;
    --it-disc-light: #ecfeff;
    --it-sans:       'DM Sans', sans-serif;
    --it-display:    'Syne', sans-serif;
    --it-mono:       'JetBrains Mono', monospace;
    --it-radius:     12px;
    --it-shadow:     0 1px 3px rgba(0,0,0,.08), 0 4px 16px rgba(0,0,0,.04);
}

.it-page-header { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:22px; flex-wrap:wrap; }
.it-header-left { display:flex; align-items:center; gap:14px; }
.it-header-icon { width:46px; height:46px; background:var(--it-ink); color:#fff; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
.page-title { font-size:22px; font-weight:800; color:var(--it-text); margin:0 0 3px; font-family:var(--it-display); letter-spacing:-.3px; }
.it-page-sub { font-size:12px; color:var(--it-muted); margin:0; font-family:var(--it-sans); }

.it-alert { display:flex; align-items:center; gap:10px; padding:13px 16px; border-radius:var(--it-radius); font-size:13px; font-weight:500; margin-bottom:16px; font-family:var(--it-sans); animation:it-slide-in .3s ease; }
@keyframes it-slide-in { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }
.it-alert-success { background:var(--it-green-light); border:1px solid #bbf7d0; color:#166534; }
.it-alert-error   { background:var(--it-red-light);   border:1px solid #fecaca; color:var(--it-red); }
.it-alert-close { margin-left:auto; background:none; border:none; cursor:pointer; color:inherit; opacity:.5; font-size:14px; padding:0; }
.it-alert-close:hover { opacity:1; }

.it-stats-bar { display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap; }
.it-stat-card { background:var(--it-white); border:1.5px solid var(--it-border); border-radius:var(--it-radius); padding:14px 20px; flex:1; min-width:100px; display:flex; flex-direction:column; gap:3px; box-shadow:var(--it-shadow); transition:transform .2s; }
.it-stat-card:hover { transform:translateY(-2px); }
.it-stat-num { font-size:26px; font-weight:800; color:var(--it-text); font-family:var(--it-display); line-height:1; }
.it-stat-lbl { font-size:11px; font-weight:600; color:var(--it-muted); text-transform:uppercase; letter-spacing:.4px; font-family:var(--it-sans); }
.it-stat-green  { border-color:#bbf7d0; background:var(--it-green-light); }
.it-stat-green  .it-stat-num { color:var(--it-green); }
.it-stat-blue   { border-color:#bfdbfe; background:var(--it-blue-light); }
.it-stat-blue   .it-stat-num { color:var(--it-blue); }
.it-stat-amber  { border-color:#fde68a; background:var(--it-amber-light); }
.it-stat-amber  .it-stat-num { color:var(--it-amber); }
.it-stat-cyan   { border-color:#a5f3fc; background:var(--it-disc-light); }
.it-stat-cyan   .it-stat-num { color:var(--it-disc); }

.it-card { background:var(--it-white); border:1.5px solid var(--it-border); border-radius:16px; overflow:hidden; box-shadow:var(--it-shadow); margin-bottom:24px; }
.it-card-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:16px 20px; border-bottom:1px solid var(--it-border); flex-wrap:wrap; background:#fafafa; }
.it-card-title-wrap { display:flex; align-items:center; gap:10px; }
.it-card-title { font-size:15px; font-weight:700; color:var(--it-text); margin:0; font-family:var(--it-display); }
.it-card-count { background:#f3f4f6; color:var(--it-muted); border-radius:20px; padding:2px 9px; font-size:11px; font-weight:700; font-family:var(--it-mono); }
.it-toolbar-right { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }

.it-search-wrap { position:relative; }
.it-search-icon { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#aaa; font-size:13px; pointer-events:none; }
.it-search-input { padding:9px 34px; border:1.5px solid var(--it-border); border-radius:8px; font-size:13px; font-family:var(--it-sans); width:220px; background:#fff; color:var(--it-text); transition:all .2s; }
.it-search-input:focus { outline:none; border-color:var(--it-ink); box-shadow:0 0 0 3px rgba(0,0,0,.06); }
.it-search-input::placeholder { color:#bbb; }
.it-search-clear { position:absolute; right:9px; top:50%; transform:translateY(-50%); background:#f3f4f6; border:none; border-radius:50%; width:20px; height:20px; display:flex; align-items:center; justify-content:center; cursor:pointer; color:var(--it-muted); font-size:10px; }
.it-search-clear:hover { background:#e5e7eb; }

.it-filter-tabs { display:flex; gap:4px; flex-wrap:wrap; }
.it-ftab { padding:7px 13px; border:1.5px solid var(--it-border); border-radius:7px; background:#fff; font-size:12px; font-weight:600; color:var(--it-muted); cursor:pointer; transition:all .2s; font-family:var(--it-sans); display:inline-flex; align-items:center; gap:5px; }
.it-ftab:hover { border-color:#aaa; color:var(--it-text); }
.it-ftab.active { background:var(--it-ink); border-color:var(--it-ink); color:#fff; }
.it-ftab-entry { border-color:#ddd4fe; color:var(--it-entry); }
.it-ftab-entry:hover { background:var(--it-entry-light); border-color:var(--it-entry); }
.it-ftab-entry.active { background:var(--it-entry); border-color:var(--it-entry); color:#fff; }
.it-ftab-disc { border-color:#a5f3fc; color:var(--it-disc); }
.it-ftab-disc:hover { background:var(--it-disc-light); border-color:var(--it-disc); }
.it-ftab-disc.active { background:var(--it-disc); border-color:var(--it-disc); color:#fff; }

.it-table-wrap { overflow-x:auto; }
.it-table { width:100%; border-collapse:collapse; font-size:13px; font-family:var(--it-sans); }
.it-th { padding:10px 14px; text-align:left; font-size:10px; font-weight:700; color:var(--it-muted); text-transform:uppercase; letter-spacing:.5px; border-bottom:1px solid var(--it-border); background:#fafafa; white-space:nowrap; }
.it-th-entry { color:var(--it-entry); }
.it-th-disc  { color:var(--it-disc); }
.it-tr { border-bottom:1px solid var(--it-border); transition:background .12s; }
.it-tr:last-child { border-bottom:none; }
.it-tr:hover { background:#f9fafb; }
.it-tr.it-tr-inactive { opacity:.7; }
.it-tr.it-tr-entry { border-left:3px solid var(--it-entry); }
.it-tr.it-tr-disc  { border-left:3px solid var(--it-disc); }
.it-tr.it-tr-entry.it-tr-disc { border-left:3px solid var(--it-disc); }
.it-tr.it-tr-hidden { display:none; }
.it-td { padding:12px 14px; vertical-align:middle; color:var(--it-text); }

.it-id-num { font-size:11px; font-family:var(--it-mono); color:#d1d5db; font-weight:600; }
.it-name-cell { display:flex; align-items:center; gap:10px; }
.it-type-avatar { width:36px; height:36px; background:var(--it-ink); color:#fff; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:15px; font-weight:800; font-family:var(--it-display); flex-shrink:0; }
.it-type-name { font-weight:700; color:var(--it-text); font-size:13px; }
.it-type-code { font-size:10px; font-family:var(--it-mono); color:var(--it-muted); margin-top:2px; }
.it-desc-text { font-size:12px; color:var(--it-muted); }
.it-null { color:#e5e7eb; font-size:13px; }

.it-rules-wrap { display:flex; flex-wrap:wrap; gap:4px; }
.it-rule-chip { display:inline-flex; align-items:center; gap:5px; background:#f9fafb; border:1px solid var(--it-border); border-radius:6px; padding:3px 9px; font-size:11px; white-space:nowrap; }
.it-rule-chip-entry { background:var(--it-entry-light); border-color:#ddd4fe; }
.it-rule-label { color:var(--it-text); font-weight:500; }
.it-rule-rate { background:#fef3c7; color:#92400e; border-radius:4px; padding:1px 5px; font-size:10px; font-weight:700; font-family:var(--it-mono); }
.it-rule-manual { color:var(--it-entry); font-size:10px; }

.it-cats-wrap { display:flex; flex-wrap:wrap; gap:4px; }
.it-cat-badge { display:inline-flex; padding:2px 8px; background:#dbeafe; border:1px solid #bfdbfe; border-radius:5px; font-size:10px; font-weight:700; color:#1e40af; font-family:var(--it-mono); }
.it-cat-more { font-size:10px; color:var(--it-muted); padding:2px 6px; background:#f3f4f6; border-radius:5px; font-weight:600; }
.it-all-badge { display:inline-flex; align-items:center; gap:5px; font-size:11px; color:var(--it-muted); }

/* Discretionary badge */
.it-disc-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; white-space:nowrap; }
.it-disc-yes { background:var(--it-disc-light); color:#0e7490; border:1px solid #a5f3fc; }
.it-disc-no  { background:#f9fafb; color:#d1d5db; border:1px solid var(--it-border); }

.it-status-badge { display:inline-flex; align-items:center; gap:6px; padding:4px 11px; border-radius:20px; font-size:11px; font-weight:700; white-space:nowrap; }
.it-status-active   { background:var(--it-green-light); color:#166534; border:1px solid #bbf7d0; }
.it-status-inactive { background:#f9fafb; color:var(--it-muted); border:1px solid var(--it-border); }
.it-date { font-size:11px; color:var(--it-muted); font-family:var(--it-mono); white-space:nowrap; }

.it-action-group { display:flex; gap:6px; }
.it-action-btn { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:7px; border:1.5px solid var(--it-border); background:var(--it-white); color:var(--it-muted); cursor:pointer; transition:all .18s; text-decoration:none; font-size:12px; }
.it-action-edit:hover   { background:var(--it-ink); color:#fff; border-color:var(--it-ink); transform:translateY(-1px); }
.it-action-delete:hover { background:var(--it-red); color:#fff; border-color:var(--it-red); transform:translateY(-1px); }

.it-enter-btn { display:inline-flex; align-items:center; gap:7px; padding:7px 14px; background:var(--it-entry); color:#fff; border-radius:8px; font-size:12px; font-weight:700; text-decoration:none; transition:all .2s; white-space:nowrap; font-family:var(--it-sans); box-shadow:0 2px 6px rgba(124,58,237,.2); }
.it-enter-btn:hover { background:#6d28d9; transform:translateY(-2px); box-shadow:0 4px 14px rgba(124,58,237,.35); }
.it-enter-btn:active { transform:translateY(0); }
.it-entry-count { background:rgba(255,255,255,.25); border-radius:20px; padding:1px 7px; font-size:10px; font-weight:800; font-family:var(--it-mono); }
.it-auto-badge { display:inline-flex; align-items:center; gap:5px; font-size:11px; color:#9ca3af; }
.it-auto-badge i { color:#d1d5db; }

.it-no-results { padding:50px; text-align:center; color:var(--it-muted); font-family:var(--it-sans); }
.it-no-results i { font-size:28px; color:#e5e7eb; margin-bottom:10px; display:block; }
.it-no-results p { font-size:13px; margin:0; }
.it-empty-state { padding:70px 30px; text-align:center; font-family:var(--it-sans); }
.it-empty-icon { width:60px; height:60px; border-radius:50%; background:#f3f4f6; display:flex; align-items:center; justify-content:center; font-size:24px; color:#d1d5db; margin:0 auto 16px; }
.it-empty-state h3 { font-size:16px; font-weight:700; margin:0 0 6px; }
.it-empty-state p  { font-size:13px; color:var(--it-muted); margin:0; }

/* Modal */
.it-modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:9999; align-items:center; justify-content:center; padding:20px; backdrop-filter:blur(4px); }
.it-modal-bg.active { display:flex; }
@keyframes it-modal-up { from{opacity:0;transform:translateY(40px) scale(.97)} to{opacity:1;transform:translateY(0) scale(1)} }
.it-modal { background:var(--it-white); border-radius:18px; width:100%; max-width:980px; max-height:92vh; overflow-y:auto; box-shadow:0 32px 80px rgba(0,0,0,.3); animation:it-modal-up .32s cubic-bezier(.22,.68,0,1.2); font-family:var(--it-sans); }
.it-modal::-webkit-scrollbar { width:4px; }
.it-modal::-webkit-scrollbar-thumb { background:#e5e7eb; border-radius:2px; }

.it-modal-header { display:flex; justify-content:space-between; align-items:flex-start; padding:26px 30px 22px; border-bottom:1px solid var(--it-border); background:linear-gradient(135deg,#fafafa,#fff); border-radius:18px 18px 0 0; position:sticky; top:0; z-index:10; }
.it-modal-header-inner { display:flex; align-items:center; gap:14px; }
.it-modal-icon { width:48px; height:48px; border-radius:13px; background:var(--it-ink); color:#fff; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; }
.it-modal-title { font-size:19px; font-weight:800; margin:0 0 4px; color:var(--it-text); font-family:var(--it-display); }
.it-modal-sub   { font-size:12px; color:var(--it-muted); margin:0; }
.it-modal-close { background:none; border:none; font-size:19px; cursor:pointer; color:var(--it-muted); width:36px; height:36px; display:flex; align-items:center; justify-content:center; border-radius:8px; transition:all .2s; flex-shrink:0; }
.it-modal-close:hover { background:#f3f4f6; color:var(--it-text); }
.it-modal-body { padding:26px 30px; display:flex; flex-direction:column; gap:18px; }
.it-modal-section { background:#fafafa; border:1.5px solid var(--it-border); border-radius:12px; padding:20px 22px; }
.it-section-label { display:flex; align-items:center; gap:8px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:var(--it-muted); margin-bottom:16px; font-family:var(--it-sans); }
.it-section-num { background:var(--it-ink); color:#fff; border-radius:5px; padding:1px 6px; font-size:10px; font-weight:700; font-family:var(--it-mono); }
.it-section-desc { font-size:12px; color:var(--it-muted); margin:-6px 0 14px; line-height:1.6; }
.it-section-desc strong { color:var(--it-text); }

.it-form-row-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.it-form-group { display:flex; flex-direction:column; gap:6px; }
.it-form-label { font-size:12px; font-weight:700; color:var(--it-text); font-family:var(--it-sans); }
.it-required { color:var(--it-red); }
.it-form-input { width:100%; padding:10px 13px; border:1.5px solid var(--it-border); border-radius:8px; font-size:13px; font-family:var(--it-sans); background:var(--it-white); color:var(--it-text); transition:all .2s; box-sizing:border-box; }
.it-form-input:focus { outline:none; border-color:var(--it-ink); box-shadow:0 0 0 3px rgba(0,0,0,.06); }
.it-form-input::placeholder { color:#c4c9d1; }
.it-link { color:var(--it-blue); text-decoration:none; font-weight:600; font-size:12px; }
.it-link:hover { text-decoration:underline; }

/* Enforce toggle */
.it-enforce-wrap { margin-bottom:12px; }
.it-enforce-label { display:inline-flex; align-items:center; gap:12px; cursor:pointer; padding:10px 14px; background:var(--it-white); border:1.5px solid var(--it-border); border-radius:9px; transition:all .2s; user-select:none; }
.it-enforce-label:hover { border-color:#aaa; }
.it-enforce-label:has(input:checked) { border-color:#bbf7d0; background:var(--it-green-light); }
.it-enforce-label input { display:none; }
.it-enforce-text { font-size:13px; color:#444; font-weight:500; }
.it-enforce-text strong { color:var(--it-text); }
.it-enforce-hint { font-size:11px; color:var(--it-muted); margin-top:6px; padding-left:2px; }

/* Discretionary Support toggle */
.it-disc-wrap { margin-bottom:14px; }
.it-disc-label { display:flex; align-items:flex-start; gap:12px; cursor:pointer; padding:12px 16px; background:var(--it-white); border:1.5px solid var(--it-border); border-radius:9px; transition:all .2s; user-select:none; }
.it-disc-label:hover { border-color:var(--it-disc); }
.it-disc-label:has(input:checked) { border-color:#a5f3fc; background:var(--it-disc-light); }
.it-disc-label input { display:none; }
.it-disc-track { flex-shrink:0; margin-top:2px; }
.it-disc-label:has(input:checked) .it-disc-track { background:var(--it-disc); }
.it-disc-label-text { display:flex; flex-direction:column; gap:4px; }
.it-disc-title { font-size:13px; font-weight:700; color:var(--it-text); display:flex; align-items:center; gap:7px; }
.it-disc-title i { color:var(--it-disc); }
.it-disc-hint-text { font-size:11px; color:var(--it-muted); line-height:1.55; }
.it-disc-hint-text strong { color:var(--it-text); }
.it-disc-notice { display:flex; align-items:center; gap:8px; margin-top:8px; padding:10px 14px; background:var(--it-disc-light); border:1px solid #a5f3fc; border-radius:8px; font-size:12px; font-weight:600; color:#0e7490; }
.it-disc-notice i { font-size:14px; flex-shrink:0; }

/* Toggle track/thumb (shared) */
.it-toggle-track { width:40px; height:22px; border-radius:11px; background:#d1d5db; position:relative; transition:background .25s; flex-shrink:0; display:inline-block; }
.it-toggle-thumb { width:16px; height:16px; border-radius:50%; background:#fff; position:absolute; top:3px; left:3px; transition:transform .25s; box-shadow:0 1px 3px rgba(0,0,0,.2); }
.it-toggle-input { display:none; }
.it-toggle-input:checked + .it-toggle-track { background:var(--it-ink); }
.it-toggle-input:checked + .it-toggle-track .it-toggle-thumb { transform:translateX(18px); }
.it-toggle-label { display:flex; align-items:center; gap:12px; cursor:pointer; }
.it-toggle-text { font-size:13px; color:#444; font-weight:500; }

/* Tally */
.it-tally-track { height:8px; background:#e5e7eb; border-radius:99px; overflow:hidden; margin-bottom:8px; }
.it-tally-fill { height:100%; border-radius:99px; background:#22c55e; transition:width .3s, background .3s; width:0%; }
.it-tally-fill.over  { background:var(--it-red); }
.it-tally-fill.exact { background:#22c55e; }
.it-tally-fill.under { background:var(--it-amber); }
.it-tally-info { display:flex; align-items:center; gap:10px; font-size:12px; color:var(--it-muted); }
.it-tally-info strong { color:var(--it-text); font-size:14px; font-family:var(--it-mono); }
.it-tally-status { font-size:11px; font-weight:700; padding:2px 9px; border-radius:9px; }
.it-tally-exact { background:#dcfce7; color:#166534; }
.it-tally-over  { background:#fee2e2; color:#991b1b; }
.it-tally-under { background:#fef3c7; color:#92400e; }

/* Calc rows */
.it-calc-row { display:grid; grid-template-columns:1fr 160px 38px; gap:8px; align-items:center; margin-bottom:8px; animation:it-row-in .18s ease; }
@keyframes it-row-in { from{opacity:0;transform:translateY(-5px)} to{opacity:1;transform:translateY(0)} }
.it-calc-num-wrap { display:flex; align-items:center; gap:8px; }
.it-calc-num { background:var(--it-ink); color:#fff; width:22px; height:22px; border-radius:50%; font-size:10px; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-family:var(--it-mono); }
.it-rate-wrap { display:flex; align-items:stretch; border:1.5px solid var(--it-border); border-radius:8px; overflow:hidden; transition:all .2s; background:#fff; }
.it-rate-wrap:focus-within { border-color:var(--it-ink); box-shadow:0 0 0 3px rgba(0,0,0,.06); }
.it-rate-inp { border:none !important; box-shadow:none !important; border-radius:0 !important; flex:1; padding:10px 8px !important; font-family:var(--it-mono) !important; font-weight:600; text-align:right; }
.it-rate-inp:focus { outline:none; }
.it-rate-sfx { background:#f5f5f5; color:var(--it-muted); font-size:12px; font-weight:700; padding:0 9px; display:flex; align-items:center; border-left:1.5px solid var(--it-border); font-family:var(--it-mono); }
.it-del-row-btn { background:none; border:1.5px solid var(--it-border); border-radius:7px; width:36px; height:36px; display:flex; align-items:center; justify-content:center; cursor:pointer; color:var(--it-muted); transition:all .18s; flex-shrink:0; }
.it-del-row-btn:hover { background:#fee2e2; border-color:#fca5a5; color:var(--it-red); }
.it-add-row-btn { display:inline-flex; align-items:center; gap:7px; background:var(--it-entry-light); border:1.5px dashed #c4b5fd; color:var(--it-entry); border-radius:8px; padding:8px 16px; font-size:12px; font-weight:700; cursor:pointer; transition:all .18s; margin-top:4px; font-family:var(--it-sans); }
.it-add-row-btn:hover { background:#ede9fe; border-color:var(--it-entry); }

/* Category pills */
.it-cat-search-wrap { position:relative; margin-bottom:10px; }
.it-cat-search-icon { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#aaa; font-size:13px; pointer-events:none; }
.it-cat-search-input { padding-left:34px !important; }
.it-cat-ctrl-row { display:flex; align-items:center; gap:8px; margin-bottom:10px; }
.it-cat-ctrl-btn { display:inline-flex; align-items:center; gap:5px; background:var(--it-white); border:1.5px solid var(--it-border); border-radius:6px; padding:5px 11px; font-size:12px; font-weight:600; color:var(--it-muted); cursor:pointer; transition:all .18s; font-family:var(--it-sans); }
.it-cat-ctrl-btn:hover { background:#f3f4f6; border-color:#aaa; }
.it-cat-count-label { margin-left:auto; font-size:12px; font-weight:600; color:var(--it-muted); }
.it-cat-pill-grid { display:flex; flex-wrap:wrap; gap:7px; max-height:200px; overflow-y:auto; padding:2px 2px 6px; }
.it-cat-pill-grid::-webkit-scrollbar { width:4px; }
.it-cat-pill-grid::-webkit-scrollbar-thumb { background:#e0e0e0; border-radius:2px; }
.it-cat-pill { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border:1.5px solid var(--it-border); border-radius:20px; background:var(--it-white); cursor:pointer; transition:all .18s; user-select:none; white-space:nowrap; }
.it-cat-pill input { display:none; }
.it-cat-pill:hover { border-color:#999; background:#f9fafb; }
.it-cat-pill:has(.cat-chk:checked) { background:var(--it-ink); border-color:var(--it-ink); color:#fff; }
.it-cat-pill:has(.cat-chk:checked) .it-cat-code { color:#fff; }
.it-cat-pill:has(.cat-chk:checked) .it-cat-name { color:#a1a1aa; }
.it-cat-pill:has(.cat-chk:checked) .it-cat-check { opacity:1; }
.it-cat-pill.hidden { display:none; }
.it-cat-code { font-size:11px; font-weight:700; color:#374151; font-family:var(--it-mono); }
.it-cat-name { font-size:11px; color:var(--it-muted); }
.it-cat-check { font-size:9px; opacity:0; transition:opacity .15s; color:#fff; }
.it-selected-cats { display:flex; flex-wrap:wrap; gap:5px; margin-top:10px; min-height:22px; }
.it-sel-tag { display:inline-flex; align-items:center; gap:5px; background:var(--it-ink); color:#fff; border-radius:6px; padding:3px 9px; font-size:11px; font-weight:700; }
.it-sel-tag button { background:none; border:none; color:#fff; cursor:pointer; font-size:11px; padding:0; opacity:.6; }
.it-sel-tag button:hover { opacity:1; }

.it-modal-footer { display:flex; justify-content:flex-end; gap:10px; padding:18px 30px 26px; border-top:1px solid var(--it-border); position:sticky; bottom:0; background:var(--it-white); z-index:10; }
.it-btn { display:inline-flex; align-items:center; gap:8px; padding:11px 22px; border:none; border-radius:9px; font-size:13px; font-weight:700; cursor:pointer; transition:all .2s; text-decoration:none; font-family:var(--it-sans); white-space:nowrap; }
.it-btn-primary { background:var(--it-ink); color:#fff; }
.it-btn-primary:hover { background:#374151; transform:translateY(-1px); box-shadow:0 4px 12px rgba(0,0,0,.15); }
.it-btn-ghost { background:#f5f5f5; color:#374151; border:1.5px solid var(--it-border); }
.it-btn-ghost:hover { background:#e5e5e5; }

@media(max-width:900px) {
    .it-form-row-2 { grid-template-columns:1fr; }
    .it-stats-bar { display:grid; grid-template-columns:1fr 1fr; }
    .it-modal-header,.it-modal-body,.it-modal-footer { padding:16px 18px; }
    .it-calc-row { grid-template-columns:1fr 130px 36px; }
}
@media(max-width:640px) {
    .it-card-toolbar { flex-direction:column; align-items:flex-start; }
    .it-search-input { width:100%; }
    .it-modal-footer { flex-direction:column; }
    .it-modal-footer .it-btn { width:100%; justify-content:center; }
    .it-page-header { flex-direction:column; align-items:flex-start; }
}
</style>

<script>
const ALL_CATS = <?php echo json_encode($all_categories); ?>;
let rowCount = 0;
let currentStatusFilter = 'all';

function filterTable(q) {
    q = q.trim().toLowerCase();
    document.getElementById('searchClear').style.display = q ? 'flex' : 'none';
    applyFilters(q, currentStatusFilter);
}

function clearSearch() {
    document.getElementById('typeSearchInput').value = '';
    filterTable('');
    document.getElementById('typeSearchInput').focus();
}

function setStatusFilter(status, btn) {
    currentStatusFilter = status;
    document.querySelectorAll('.it-ftab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyFilters(document.getElementById('typeSearchInput').value, status);
}

function applyFilters(q, status) {
    const rows = document.querySelectorAll('#typesTableBody .it-tr');
    let visible = 0;
    rows.forEach(row => {
        const search  = row.getAttribute('data-search') || '';
        const active  = row.getAttribute('data-active');
        const isEntry = row.getAttribute('data-entry') === '1';
        const isDisc  = row.getAttribute('data-disc')  === '1';
        const matchQ  = !q || search.includes(q.toLowerCase());
        const matchS  = status === 'all'
                     || (status === 'active'   && active === '1')
                     || (status === 'inactive' && active === '0')
                     || (status === 'entry'    && isEntry)
                     || (status === 'disc'     && isDisc);
        const show = matchQ && matchS;
        row.classList.toggle('it-tr-hidden', !show);
        if (show) visible++;
    });
    const countEl = document.getElementById('tableCount');
    if (countEl) countEl.textContent = visible + ' type' + (visible !== 1 ? 's' : '');
    const noRes = document.getElementById('noResultsRow');
    if (noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
}

function openAddModal() {
    resetModal();
    document.getElementById('modalTitle').textContent    = 'Add New Incentive Type';
    document.getElementById('modalSubtitle').textContent = 'Fill in the details to create a new incentive type';
    document.getElementById('submitBtnText').textContent = 'Save Incentive Type';
    document.getElementById('incentiveModal').classList.add('active');
    setTimeout(() => document.getElementById('type_name').focus(), 200);
}

function openEditModal(data) {
    resetModal();
    document.getElementById('incentive_id').value = data.id;
    document.getElementById('type_name').value    = data.type_name   || '';
    document.getElementById('description').value  = data.description || '';
    document.getElementById('active').checked     = data.active == 1;
    updateToggleText();

    // Set discretionary support
    const discChk = document.getElementById('discretionary_support');
    discChk.checked = (data.discretionary_support == 1 || data.discretionary_support === '1');
    onDiscChange();

    let rules = [];
    try { rules = JSON.parse(data.calculation_rules || '[]') || []; } catch(e){}
    rules.forEach(r => addCalcRow(r.label, r.rate != null ? r.rate : ''));

    const catIds = (data.allowed_category_ids || '').split(',').map(s => s.trim()).filter(Boolean);
    document.querySelectorAll('.cat-chk').forEach(chk => {
        chk.checked = catIds.includes(String(chk.value));
    });
    updateCatCount();
    renderSelectedTags();

    document.getElementById('modalTitle').textContent    = 'Edit Incentive Type';
    document.getElementById('modalSubtitle').textContent = 'Editing: ' + (data.type_name || '');
    document.getElementById('submitBtnText').textContent = 'Update Incentive Type';
    document.getElementById('incentiveModal').classList.add('active');
}

function closeModal() {
    document.getElementById('incentiveModal').classList.remove('active');
}

function resetModal() {
    document.getElementById('incentiveForm').reset();
    document.getElementById('incentive_id').value = '';
    document.getElementById('calcRulesContainer').innerHTML = '';
    rowCount = 0;
    document.getElementById('enforce100Chk').checked = true;
    document.getElementById('discretionary_support').checked = false;
    // Re-enable enforce toggle in case it was locked
    const enforceChk = document.getElementById('enforce100Chk');
    enforceChk.disabled = false;
    document.getElementById('enforceToggleLabel').style.opacity = '';
    document.getElementById('enforceToggleLabel').style.pointerEvents = '';
    document.getElementById('discNotice').style.display = 'none';
    onEnforceChange();
    updateToggleText();
    document.querySelectorAll('.cat-chk').forEach(c => c.checked = false);
    const s = document.getElementById('catSearch');
    if (s) { s.value = ''; filterCatPills(''); }
    updateCatCount();
    renderSelectedTags();
}

window.addEventListener('click', e => {
    if (e.target === document.getElementById('incentiveModal')) closeModal();
});

// DISCRETIONARY SUPPORT LOGIC
// When disc is ON: disable enforce100 checkbox and hide tally bar entirely.
// When disc is OFF: restore enforce100 to user control.
function onDiscChange() {
    const discOn     = document.getElementById('discretionary_support').checked;
    const enforceChk = document.getElementById('enforce100Chk');
    const label      = document.getElementById('enforceToggleLabel');
    const notice     = document.getElementById('discNotice');

    if (discOn) {
        enforceChk.checked  = false;
        enforceChk.disabled = true;
        label.style.opacity        = '0.45';
        label.style.pointerEvents  = 'none';
        notice.style.display = 'flex';
    } else {
        enforceChk.disabled = false;
        label.style.opacity       = '';
        label.style.pointerEvents = '';
        notice.style.display = 'none';
    }
    onEnforceChange();
}

function onEnforceChange() {
    const on = document.getElementById('enforce100Chk').checked;
    document.getElementById('tallySection').style.display  = on ? 'block' : 'none';
    document.getElementById('noEnforceDesc').style.display = on ? 'none'  : 'block';
    if (on) updateTally();
}

function addCalcRow(label, rate) {
    label = label !== undefined ? label : '';
    rate  = rate  !== undefined ? rate  : '';
    rowCount++;
    const container = document.getElementById('calcRulesContainer');
    const div = document.createElement('div');
    div.className = 'it-calc-row';
    div.id = 'calcRow_' + rowCount;
    div.innerHTML =
        '<div class="it-calc-num-wrap">' +
            '<div class="it-calc-num">' + rowCount + '</div>' +
            '<input type="text" name="calc_label[]" class="it-form-input"' +
            '       placeholder="Component label (e.g. Base Pay, Allowance)"' +
            '       value="' + escHtml(String(label)) + '">' +
        '</div>' +
        '<div class="it-rate-wrap">' +
            '<input type="number" name="calc_rate[]" class="it-form-input it-rate-inp"' +
            '       placeholder="Optional" min="0" max="100" step="0.01"' +
            '       value="' + (rate !== '' && rate !== null ? escHtml(String(rate)) : '') + '"' +
            '       oninput="updateTally()">' +
            '<span class="it-rate-sfx">%</span>' +
        '</div>' +
        '<button type="button" class="it-del-row-btn" onclick="removeCalcRow(\'calcRow_' + rowCount + '\')" title="Remove">' +
            '<i class="fa-solid fa-xmark"></i>' +
        '</button>';
    container.appendChild(div);
    renumberRows();
    updateTally();
}

function removeCalcRow(id) {
    const el = document.getElementById(id);
    if (el) { el.remove(); renumberRows(); updateTally(); }
}

function renumberRows() {
    document.querySelectorAll('#calcRulesContainer .it-calc-row').forEach((row, i) => {
        const num = row.querySelector('.it-calc-num');
        if (num) num.textContent = i + 1;
    });
}

function updateTally() {
    const inputs = document.querySelectorAll('#calcRulesContainer input[name="calc_rate[]"]');
    let total = 0;
    inputs.forEach(inp => { total += parseFloat(inp.value || 0); });
    total = Math.round(total * 100) / 100;
    const pct    = Math.min(total, 100);
    const fill   = document.getElementById('tallyBarFill');
    const val    = document.getElementById('tallyValue');
    const status = document.getElementById('tallyStatus');
    if (!fill) return;
    val.textContent  = total.toFixed(2);
    fill.style.width = pct + '%';
    fill.className   = 'it-tally-fill';
    status.className = 'it-tally-status';
    if (total === 100) {
        fill.classList.add('exact'); status.classList.add('it-tally-exact');
        status.textContent = 'Exactly 100% -- perfect!';
    } else if (total > 100) {
        fill.classList.add('over'); status.classList.add('it-tally-over');
        status.textContent = 'Over by ' + (total - 100).toFixed(2) + '%';
    } else {
        fill.classList.add('under'); status.classList.add('it-tally-under');
        status.textContent = (100 - total).toFixed(2) + '% remaining';
    }
}

function updateCatCount() {
    const count = document.querySelectorAll('.cat-chk:checked').length;
    const lbl   = document.getElementById('catCountLabel');
    if (lbl) lbl.textContent = count === 0 ? '0 selected (= All)' : count + ' selected';
    renderSelectedTags();
}

function renderSelectedTags() {
    const display = document.getElementById('selectedCatsDisplay');
    if (!display) return;
    display.innerHTML = '';
    document.querySelectorAll('.cat-chk:checked').forEach(chk => {
        const pill = chk.closest('.it-cat-pill');
        const code = pill ? pill.querySelector('.it-cat-code').textContent : chk.value;
        const name = pill ? pill.querySelector('.it-cat-name').textContent : '';
        const tag  = document.createElement('div');
        tag.className = 'it-sel-tag';
        tag.innerHTML = escHtml(code) + ' -- ' + escHtml(name) +
            '<button type="button" onclick="removeCatById(\'' + chk.value + '\')" title="Remove">x</button>';
        display.appendChild(tag);
    });
}

function removeCatById(val) {
    const chk = document.querySelector('.cat-chk[value="' + val + '"]');
    if (chk) { chk.checked = false; updateCatCount(); }
}

function filterCatPills(q) {
    const term = q.trim().toLowerCase();
    document.querySelectorAll('.it-cat-pill').forEach(pill => {
        const search = pill.dataset.search || '';
        pill.classList.toggle('hidden', term !== '' && !search.includes(term));
    });
}

function selectAllCats() {
    document.querySelectorAll('.it-cat-pill:not(.hidden) .cat-chk').forEach(c => c.checked = true);
    updateCatCount();
}

function clearAllCats() {
    document.querySelectorAll('.cat-chk').forEach(c => c.checked = false);
    updateCatCount();
}

function updateToggleText() {
    const tog = document.getElementById('active');
    const txt = document.getElementById('toggleText');
    if (!tog || !txt) return;
    txt.textContent = tog.checked ? 'Active -- available for use' : 'Inactive -- hidden from use';
}

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('active').addEventListener('change', updateToggleText);

    document.getElementById('incentiveForm').addEventListener('submit', function (e) {
        // Skip 100% validation if Discretionary Support is ON
        const discOn    = document.getElementById('discretionary_support').checked;
        const enforceOn = document.getElementById('enforce100Chk').checked;
        if (!discOn && enforceOn) {
            const inputs = document.querySelectorAll('#calcRulesContainer input[name="calc_rate[]"]');
            if (inputs.length > 0) {
                let total = 0;
                inputs.forEach(inp => { total += parseFloat(inp.value || 0); });
                total = Math.round(total * 100) / 100;
                if (total !== 100) {
                    e.preventDefault();
                    alert(
                        'Enforce 100% is ON -- rates must total exactly 100%.\n' +
                        'Current total: ' + total.toFixed(2) + '%\n\n' +
                        'Tip: Enable "Discretionary Support" if amounts vary at management discretion.'
                    );
                    return false;
                }
            }
        }
    });

    applyFilters('', 'all');

    setTimeout(function() {
        const al = document.getElementById('mainAlert');
        if (al) { al.style.opacity = '0'; al.style.transition = 'opacity .4s'; setTimeout(function(){ al.remove(); }, 400); }
    }, 5000);
});

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php include 'footer.php'; ?>