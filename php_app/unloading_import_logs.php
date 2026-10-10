<?php
/**
 * unloading_import_logs.php
 * View, delete, and reverse re-import log entries.
 */
include 'config.php';
include 'header.php';

// Ensure all three log tables exist (same DDL as processor)
mysqli_query($conn,"CREATE TABLE IF NOT EXISTS unloading_import_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_id         INT NOT NULL,
    log_action        VARCHAR(50)  DEFAULT 'reimport',
    old_filename      VARCHAR(255) NULL,
    old_delivery_date DATE NULL,
    old_total_records INT DEFAULT 0,
    old_imported      INT DEFAULT 0,
    old_failed        INT DEFAULT 0,
    old_status        VARCHAR(20)  NULL,
    new_filename      VARCHAR(255) NULL,
    new_delivery_date DATE NULL,
    rows_added        INT DEFAULT 0,
    rows_updated      INT DEFAULT 0,
    rows_removed      INT DEFAULT 0,
    can_reverse       TINYINT(1) DEFAULT 1,
    reversed          TINYINT(1) DEFAULT 0,
    reversed_at       TIMESTAMP NULL,
    logged_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    logged_by         INT NULL,
    INDEX idx_import_id (import_id)
)");

mysqli_query($conn,"CREATE TABLE IF NOT EXISTS unloading_import_log_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    log_id               INT NOT NULL,
    import_id            INT NOT NULL,
    orig_detail_id       INT NULL,
    record_date          DATE NULL,
    delivery_person_code VARCHAR(100) NULL,
    delivery_person_name VARCHAR(255) NULL,
    vehicle              VARCHAR(255) NULL,
    sku_code             VARCHAR(100) NULL,
    sku_desc             VARCHAR(500) NULL,
    tur                  DECIMAL(12,2) DEFAULT 0,
    mrp                  DECIMAL(12,2) DEFAULT 0,
    adj_qty_good_units   DECIMAL(12,2) DEFAULT 0,
    adj_qty_damage       DECIMAL(12,2) DEFAULT 0,
    delivery_date        DATE NULL,
    status               VARCHAR(40) DEFAULT 'imported',
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log(log_id), INDEX idx_import(import_id)
)");

mysqli_query($conn,"CREATE TABLE IF NOT EXISTS unloading_import_log_ud_snapshot (
    id INT AUTO_INCREMENT PRIMARY KEY,
    log_id               INT NOT NULL,
    import_id            INT NOT NULL,
    orig_ud_id           INT NOT NULL,
    record_date          DATE NULL,
    delivery_person_code VARCHAR(100) NULL,
    delivery_person_name VARCHAR(255) NULL,
    vehicle              VARCHAR(255) NULL,
    sku_code             VARCHAR(100) NULL,
    sku_desc             VARCHAR(500) NULL,
    tur                  DECIMAL(12,2) DEFAULT 0,
    mrp                  DECIMAL(12,2) DEFAULT 0,
    adj_qty_good_units   DECIMAL(12,2) DEFAULT 0,
    adj_qty_damage       DECIMAL(12,2) DEFAULT 0,
    actual_qty           DECIMAL(12,2) NULL,
    actual_damage_qty    DECIMAL(12,2) NULL,
    short_excess         DECIMAL(12,2) NULL,
    charge_to_employee   DECIMAL(12,2) NULL,
    absorb_by_company    DECIMAL(12,2) NULL,
    pay_variance         DECIMAL(12,2) NULL,
    delivery_date        DATE NULL,
    status               VARCHAR(40) DEFAULT 'imported',
    snapped_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log(log_id), INDEX idx_import(import_id)
)");

// ── Filter params ─────────────────────────────────────────────────────────
$filter_import = isset($_GET['filter_import']) ? intval($_GET['filter_import']) : 0;
$filter_date   = isset($_GET['filter_date'])   ? trim($_GET['filter_date'])    : '';

$where = '1=1';
if ($filter_import) $where .= " AND l.import_id = $filter_import";
if ($filter_date)   $where .= " AND DATE(l.logged_at) = '".mysqli_real_escape_string($conn,$filter_date)."'";

// ── Load logs with snapshot counts ───────────────────────────────────────
$logs_r = mysqli_query($conn,"
    SELECT l.*,
           i.filename     AS cur_filename,
           i.delivery_date AS cur_delivery_date,
           (SELECT COUNT(*) FROM unloading_import_log_details  ld WHERE ld.log_id=l.id) AS snap_det_count,
           (SELECT COUNT(*) FROM unloading_import_log_ud_snapshot us WHERE us.log_id=l.id) AS snap_ud_count
    FROM unloading_import_logs l
    LEFT JOIN unloading_summary_imports i ON i.id = l.import_id
    WHERE $where
    ORDER BY l.logged_at DESC");

// ── Stats ─────────────────────────────────────────────────────────────────
$stat_r = mysqli_query($conn,"SELECT
    COUNT(*)                        AS total_logs,
    SUM(reversed=0 AND can_reverse=1) AS reversible,
    SUM(reversed=1)                  AS reversed_count
    FROM unloading_import_logs");
$stats = mysqli_fetch_assoc($stat_r) ?? [];

// ── Build import list for filter dropdown ─────────────────────────────────
$imp_r = mysqli_query($conn,"SELECT id, filename, delivery_date FROM unloading_summary_imports ORDER BY imported_at DESC");
?>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title"><i class="fa-solid fa-clock-rotate-left"></i> Re-Import Logs</h2>
            <p class="page-subtitle">History of all re-import operations — view, reverse, or delete log entries</p>
        </div>
        <a href="unloading_import_history.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i> Back to History
        </a>
    </div>
</div>

<!-- ── Stats ──────────────────────────────────────────────────────────────── -->
<div class="stats-row">
    <div class="stat-card">
        <div class="stat-icon si-blue"><i class="fa-solid fa-list-check"></i></div>
        <div class="stat-info">
            <span class="stat-value"><?php echo intval($stats['total_logs'] ?? 0); ?></span>
            <span class="stat-label">Total Log Entries</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon si-amber"><i class="fa-solid fa-rotate-left"></i></div>
        <div class="stat-info">
            <span class="stat-value"><?php echo intval($stats['reversible'] ?? 0); ?></span>
            <span class="stat-label">Can Be Reversed</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon si-green"><i class="fa-solid fa-check-double"></i></div>
        <div class="stat-info">
            <span class="stat-value"><?php echo intval($stats['reversed_count'] ?? 0); ?></span>
            <span class="stat-label">Already Reversed</span>
        </div>
    </div>
</div>

<!-- ── Filters ────────────────────────────────────────────────────────────── -->
<div class="content-card">
    <form method="GET" class="filter-form">
        <div class="filter-row">
            <div class="filter-group">
                <label class="form-label">Import</label>
                <select name="filter_import" class="form-input">
                    <option value="">All Imports</option>
                    <?php if($imp_r) while($ir=mysqli_fetch_assoc($imp_r)): ?>
                        <option value="<?php echo $ir['id']; ?>"
                            <?php echo ($filter_import===$ir['id'])?'selected':''; ?>>
                            #<?php echo $ir['id']; ?> — <?php echo htmlspecialchars($ir['filename']); ?>
                            <?php echo $ir['delivery_date']?' ('.$ir['delivery_date'].')':''; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="filter-group">
                <label class="form-label">Log Date</label>
                <input type="date" name="filter_date" class="form-input" value="<?php echo htmlspecialchars($filter_date); ?>">
            </div>
            <div class="filter-group filter-actions">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-search"></i> Filter</button>
                <a href="unloading_import_logs.php" class="btn btn-secondary"><i class="fa-solid fa-rotate-left"></i> Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- ── Log table ──────────────────────────────────────────────────────────── -->
<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title"><i class="fa-solid fa-table-list"></i> Log Entries</h3>
    </div>

    <div class="table-responsive">
        <table class="data-table" id="logTable">
            <thead>
                <tr>
                    <th>Log #</th>
                    <th>Import #</th>
                    <th>Logged At</th>
                    <th>Old File</th>
                    <th>Old Del. Date</th>
                    <th>New File</th>
                    <th>New Del. Date</th>
                    <th style="text-align:right;">Old Rows</th>
                    <th style="text-align:right;">Added</th>
                    <th style="text-align:right;">Updated</th>
                    <th style="text-align:right;">Removed</th>
                    <th style="text-align:right;">Det. Snap</th>
                    <th style="text-align:right;">UD Snap</th>
                    <th>Status</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($logs_r && mysqli_num_rows($logs_r) > 0):
                while ($log = mysqli_fetch_assoc($logs_r)):
                    $is_reversed  = intval($log['reversed'])    === 1;
                    $can_reverse  = intval($log['can_reverse'])  === 1 && !$is_reversed;
                    $has_snapshot = intval($log['snap_det_count']) > 0;
            ?>
                <tr id="log-row-<?php echo $log['id']; ?>">
                    <td><strong>#<?php echo $log['id']; ?></strong></td>
                    <td>
                        <a href="unloading_import_history_view.php?id=<?php echo $log['import_id']; ?>"
                           class="link-import">#<?php echo $log['import_id']; ?></a>
                    </td>
                    <td style="font-size:12px;color:#555;">
                        <?php echo date('Y-m-d H:i:s',strtotime($log['logged_at'])); ?>
                    </td>
                    <td>
                        <span class="fn-text" title="<?php echo htmlspecialchars($log['old_filename'] ?? ''); ?>">
                            <i class="fa-solid fa-file-excel" style="color:#166534;"></i>
                            <?php echo htmlspecialchars($log['old_filename'] ?? '—'); ?>
                        </span>
                    </td>
                    <td><?php echo $log['old_delivery_date'] ?: '—'; ?></td>
                    <td>
                        <span class="fn-text" title="<?php echo htmlspecialchars($log['new_filename'] ?? ''); ?>">
                            <i class="fa-solid fa-file-excel" style="color:#2563eb;"></i>
                            <?php echo htmlspecialchars($log['new_filename'] ?? '—'); ?>
                        </span>
                    </td>
                    <td><?php echo $log['new_delivery_date'] ?: '—'; ?></td>
                    <td style="text-align:right;font-weight:600;"><?php echo intval($log['old_imported']); ?></td>
                    <td style="text-align:right;color:#16a34a;font-weight:600;"><?php echo intval($log['rows_added']); ?></td>
                    <td style="text-align:right;color:#2563eb;font-weight:600;"><?php echo intval($log['rows_updated']); ?></td>
                    <td style="text-align:right;color:#dc2626;font-weight:600;"><?php echo intval($log['rows_removed']); ?></td>
                    <td style="text-align:right;">
                        <?php if ($has_snapshot): ?>
                            <span class="badge badge-snap" onclick="openSnapshotModal(<?php echo $log['id']; ?>, 'details')" style="cursor:pointer;" title="View detail snapshot">
                                <?php echo intval($log['snap_det_count']); ?> rows
                            </span>
                        <?php else: ?>
                            <span style="color:#d1d5db;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <?php if (intval($log['snap_ud_count']) > 0): ?>
                            <span class="badge badge-snap-ud" onclick="openSnapshotModal(<?php echo $log['id']; ?>, 'ud')" style="cursor:pointer;" title="View working data snapshot">
                                <?php echo intval($log['snap_ud_count']); ?> rows
                            </span>
                        <?php else: ?>
                            <span style="color:#d1d5db;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($is_reversed): ?>
                            <span class="badge badge-reversed">
                                <i class="fa-solid fa-rotate-left"></i> Reversed
                                <?php if (!empty($log['reversed_at'])): ?>
                                    <span style="font-weight:400;"> <?php echo date('m/d H:i',strtotime($log['reversed_at'])); ?></span>
                                <?php endif; ?>
                            </span>
                        <?php elseif ($can_reverse && $has_snapshot): ?>
                            <span class="badge badge-can-reverse">
                                <i class="fa-solid fa-circle-check"></i> Ready
                            </span>
                        <?php else: ?>
                            <span class="badge badge-no-snap">
                                <i class="fa-solid fa-circle-xmark"></i> No Snapshot
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <div class="action-buttons" style="justify-content:center;">
                            <!-- View snapshot -->
                            <?php if ($has_snapshot): ?>
                            <button class="btn-action btn-view"
                                    onclick="openSnapshotModal(<?php echo $log['id']; ?>, 'details')"
                                    title="View Snapshot">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                            <?php endif; ?>

                            <!-- Reverse -->
                            <?php if ($can_reverse && $has_snapshot): ?>
                            <button class="btn-action btn-reverse"
                                    onclick="confirmReverse(<?php echo $log['id']; ?>, <?php echo $log['import_id']; ?>, '<?php echo addslashes($log['old_filename'] ?? ''); ?>')"
                                    title="Reverse this re-import">
                                <i class="fa-solid fa-rotate-left"></i>
                            </button>
                            <?php elseif ($is_reversed): ?>
                            <button class="btn-action" style="opacity:.4;cursor:default;" disabled title="Already reversed">
                                <i class="fa-solid fa-rotate-left"></i>
                            </button>
                            <?php endif; ?>

                            <!-- Delete log -->
                            <button class="btn-action btn-delete"
                                    onclick="confirmDeleteLog(<?php echo $log['id']; ?>)"
                                    title="Delete Log Entry">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr>
                    <td colspan="15" class="empty-state">
                        <i class="fa-solid fa-inbox"></i>
                        <p>No log entries found</p>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>


<!-- ════════════════════════════════════════════════════════════════
     SNAPSHOT VIEWER MODAL
     ════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="snapshotModal" onclick="if(event.target===this)closeSnapshotModal()">
    <div class="modal-box modal-xl">
        <div class="modal-header-row">
            <div>
                <div class="modal-title"><i class="fa-solid fa-database" style="color:#7c3aed;"></i> Snapshot Viewer</div>
                <div class="modal-sub" id="snapModalSub">Loading...</div>
            </div>
            <button class="modal-close" onclick="closeSnapshotModal()">×</button>
        </div>

        <!-- Tab switcher -->
        <div class="snap-tabs">
            <button class="snap-tab active" id="tabDet" onclick="switchSnapTab('details')">
                <i class="fa-solid fa-list"></i> Import Details Snapshot
            </button>
            <button class="snap-tab" id="tabUd" onclick="switchSnapTab('ud')">
                <i class="fa-solid fa-table"></i> Working Data Snapshot
            </button>
        </div>

        <div id="snapBody" style="overflow:auto;max-height:55vh;">
            <div style="text-align:center;padding:40px;color:#9ca3af;">
                <i class="fa-solid fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading...
            </div>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════════════════════════════
     REVERSE CONFIRM MODAL
     ════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="reverseModal" onclick="if(event.target===this)closeReverseModal()">
    <div class="modal-box" style="max-width:480px;text-align:center;">
        <div style="font-size:48px;color:#d97706;margin-bottom:14px;">
            <i class="fa-solid fa-rotate-left"></i>
        </div>
        <div class="modal-title" style="justify-content:center;">Reverse This Re-Import?</div>
        <div class="modal-sub" style="margin:10px 0 20px;" id="reverseModalDesc">
            This will restore the import to its previous state.
        </div>
        <div class="ri-warn" style="text-align:left;margin-bottom:20px;">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div style="font-size:12px;">
                <strong>What will be restored:</strong>
                <ul style="margin:4px 0 0 14px;line-height:1.8;">
                    <li>Raw import details will revert to the <strong>old snapshot</strong></li>
                    <li>Working data (actual qty, pay allocations, etc.) will revert to the <strong>pre-reimport values</strong></li>
                    <li>Any rows <em>added</em> by the reimport will be <strong>deleted</strong></li>
                    <li>Rows <em>removed</em> by the reimport will be <strong>restored</strong></li>
                    <li>Import header (filename, date, counts) will revert</li>
                    <li>This action <strong>cannot be undone</strong></li>
                </ul>
            </div>
        </div>
        <div style="display:flex;gap:10px;justify-content:center;">
            <button class="btn btn-secondary" onclick="closeReverseModal()">Cancel</button>
            <button class="btn btn-amber" id="confirmReverseBtn" onclick="executeReverse()">
                <i class="fa-solid fa-rotate-left"></i> Yes, Reverse
            </button>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════════════════════════════
     DELETE LOG CONFIRM MODAL
     ════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="deleteLogModal" onclick="if(event.target===this)closeDeleteLogModal()">
    <div class="modal-box" style="max-width:420px;text-align:center;">
        <div style="font-size:44px;color:#dc2626;margin-bottom:14px;">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <div class="modal-title" style="justify-content:center;">Delete Log Entry?</div>
        <div class="modal-sub" style="margin:10px 0 20px;" id="deleteLogDesc">
            This will permanently delete the log entry and its snapshots.
            <strong>You will no longer be able to reverse this reimport.</strong>
        </div>
        <div style="display:flex;gap:10px;justify-content:center;">
            <button class="btn btn-secondary" onclick="closeDeleteLogModal()">Cancel</button>
            <button class="btn btn-danger" id="confirmDelLogBtn" onclick="executeDeleteLog()">
                <i class="fa-solid fa-trash"></i> Delete Log
            </button>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="toast"></div>


<style>
/* ── Base ──────────────────────────────────────────────────────────── */
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:0;color:#1f2937;display:flex;align-items:center;gap:8px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;display:flex;align-items:center;gap:16px}
.stat-icon{width:48px;height:48px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px}
.si-blue {background:#eff6ff;color:#1e40af}
.si-amber{background:#fffbeb;color:#d97706}
.si-green{background:#f0fdf4;color:#166534}
.stat-info{display:flex;flex-direction:column}
.stat-value{font-size:24px;font-weight:700;color:#1f2937}
.stat-label{font-size:12px;color:#6b7280;margin-top:2px}
.filter-form{margin:0}
.filter-row{display:flex;gap:16px;align-items:flex-end}
.filter-group{flex:1}
.filter-actions{display:flex;gap:8px;flex:none}
.form-label{display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:#374151}
.form-input{width:100%;padding:10px 14px;border:1px solid #e5e5e5;border-radius:6px;font-size:14px;font-family:'Inter',sans-serif;box-sizing:border-box;outline:none}
.form-input:focus{border-color:#000}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;font-family:'Inter',sans-serif;text-decoration:none}
.btn-primary{background:#000;color:#fff}.btn-primary:hover{background:#333}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover{background:#e5e5e5}
.btn-amber{background:#d97706;color:#fff}.btn-amber:hover{background:#b45309}
.btn-danger{background:#dc2626;color:#fff}.btn-danger:hover{background:#b91c1c}
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead{background:#fafafa;border-bottom:2px solid #e5e5e5}
.data-table th{padding:11px 12px;text-align:left;font-weight:600;color:#333;font-size:12px;white-space:nowrap}
.data-table tbody tr{border-bottom:1px solid #f0f0f0}
.data-table tbody tr:hover{background:#fafafa}
.data-table td{padding:10px 12px;color:#333;white-space:nowrap}
.fn-text{display:inline-flex;align-items:center;gap:5px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.link-import{color:#2563eb;text-decoration:none;font-weight:600}.link-import:hover{text-decoration:underline}
.action-buttons{display:flex;gap:5px}
.btn-action{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#666;cursor:pointer;transition:all .2s;font-size:12px}
.btn-action:hover{transform:translateY(-2px);box-shadow:0 2px 6px rgba(0,0,0,.1)}
.btn-view{background:#eff6ff;color:#1e40af;border-color:#bfdbfe}.btn-view:hover{background:#1e40af;color:#fff}
.btn-reverse{background:#fffbeb;color:#d97706;border-color:#fde68a}.btn-reverse:hover{background:#d97706;color:#fff}
.btn-delete:hover{background:#ef4444;color:#fff;border-color:#ef4444}

/* ── Badges ─────────────────────────────────────────────────────── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 9px;border-radius:10px;font-size:11px;font-weight:600;white-space:nowrap}
.badge-reversed  {background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.badge-can-reverse{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.badge-no-snap   {background:#fafafa;color:#9ca3af;border:1px solid #e5e5e5}
.badge-snap      {background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;cursor:pointer}
.badge-snap:hover{background:#7c3aed;color:#fff}
.badge-snap-ud   {background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;cursor:pointer}
.badge-snap-ud:hover{background:#1e40af;color:#fff}
.empty-state{text-align:center;padding:60px 20px !important;color:#999}
.empty-state i{font-size:48px;display:block;margin-bottom:12px;color:#ddd}
.empty-state p{margin-bottom:16px;font-size:14px}

/* ── Warn strip (reused from reimport modal) ─────────────────────── */
.ri-warn{display:flex;gap:12px;align-items:flex-start;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px 18px;font-size:13px;color:#92400e}
.ri-warn i{font-size:18px;color:#d97706;margin-top:2px;flex-shrink:0}

/* ══ MODAL BASE ═════════════════════════════════════════════════════ */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;display:none;align-items:center;justify-content:center;padding:16px}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:12px;padding:28px 32px;width:94%;max-width:560px;box-shadow:0 20px 60px rgba(0,0,0,.22);max-height:92vh;overflow-y:auto}
.modal-xl{max-width:1000px;padding:0;overflow:hidden;display:flex;flex-direction:column}
.modal-header-row{display:flex;justify-content:space-between;align-items:flex-start;padding:20px 24px 14px;border-bottom:1px solid #f0f0f0;flex-shrink:0}
.modal-title{font-size:17px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px}
.modal-sub{font-size:12px;color:#9ca3af;margin-top:3px}
.modal-close{background:none;border:none;cursor:pointer;color:#9ca3af;font-size:26px;line-height:1;padding:0}
.modal-close:hover{color:#1f2937}

/* ── Snapshot tabs ───────────────────────────────────────────────── */
.snap-tabs{display:flex;gap:0;border-bottom:2px solid #e5e5e5;padding:0 24px;flex-shrink:0;background:#fafafa}
.snap-tab{padding:12px 20px;font-size:13px;font-weight:600;border:none;background:none;cursor:pointer;color:#6b7280;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .2s;font-family:'Inter',sans-serif;display:inline-flex;align-items:center;gap:6px}
.snap-tab.active{color:#7c3aed;border-bottom-color:#7c3aed}
.snap-tab:hover{color:#1f2937}

/* ── Snapshot table ──────────────────────────────────────────────── */
.snap-table{width:100%;border-collapse:collapse;font-size:12px}
.snap-table thead{position:sticky;top:0;z-index:5}
.snap-table th{background:#f0f0f0;padding:9px 10px;text-align:left;font-weight:600;color:#333;white-space:nowrap;border-bottom:2px solid #d5d5d5}
.snap-table tbody tr{border-bottom:1px solid #f0f0f0}
.snap-table tbody tr:hover{background:#fafafa}
.snap-table td{padding:7px 10px;color:#333;white-space:nowrap}
.snap-table td.num{text-align:right}
.col-highlight{background:#fef3c7}

/* ── Toast ───────────────────────────────────────────────────────── */
#toast{position:fixed;bottom:28px;right:28px;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;color:#fff;z-index:9999;display:none;box-shadow:0 4px 16px rgba(0,0,0,.18)}
#toast.success{background:#16a34a}
#toast.error{background:#dc2626}
#toast.info{background:#2563eb}

@media(max-width:640px){
    .stats-row{grid-template-columns:1fr}
    .filter-row{flex-direction:column}
}
</style>


<script>
/* ══════════════════════════════════════════════════════════════════
   SNAPSHOT MODAL
   ══════════════════════════════════════════════════════════════════ */
let snapLogId   = null;
let snapTabMode = 'details'; // 'details' | 'ud'
let snapCache   = {};

function openSnapshotModal(logId, tab) {
    snapLogId   = logId;
    snapTabMode = tab || 'details';
    snapCache   = {};
    document.getElementById('snapModalSub').textContent = 'Log #' + logId;
    switchSnapTab(snapTabMode);
    document.getElementById('snapshotModal').classList.add('open');
}

function closeSnapshotModal() {
    document.getElementById('snapshotModal').classList.remove('open');
}

function switchSnapTab(tab) {
    snapTabMode = tab;
    document.getElementById('tabDet').classList.toggle('active', tab==='details');
    document.getElementById('tabUd').classList.toggle('active',  tab==='ud');
    loadSnapData();
}

function loadSnapData() {
    if (!snapLogId) return;
    const cacheKey = snapLogId + '_' + snapTabMode;
    if (snapCache[cacheKey]) {
        document.getElementById('snapBody').innerHTML = snapCache[cacheKey];
        return;
    }

    document.getElementById('snapBody').innerHTML =
        '<div style="text-align:center;padding:40px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading...</div>';

    fetch('get_log_snapshot.php?log_id=' + snapLogId + '&type=' + snapTabMode)
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                document.getElementById('snapBody').innerHTML =
                    '<div style="text-align:center;padding:30px;color:#dc2626;">' + res.message + '</div>';
                return;
            }
            const html = snapTabMode === 'details'
                ? buildDetailsTable(res.rows)
                : buildUdTable(res.rows);
            snapCache[cacheKey] = html;
            document.getElementById('snapBody').innerHTML = html;
        })
        .catch(err => {
            document.getElementById('snapBody').innerHTML =
                '<div style="text-align:center;padding:30px;color:#dc2626;">Error: ' + err.message + '</div>';
        });
}

function buildDetailsTable(rows) {
    if (!rows || !rows.length)
        return '<div style="text-align:center;padding:30px;color:#9ca3af;">No snapshot rows found.</div>';

    let html = `<table class="snap-table"><thead><tr>
        <th>#</th><th>Date</th><th>Del. Person</th><th>SKU Code</th><th>SKU Desc</th>
        <th class="num">TUR</th><th class="num">MRP</th>
        <th class="num">Adj Good</th><th class="num">Adj Dmg</th><th>Status</th>
    </tr></thead><tbody>`;
    rows.forEach((r,i) => {
        html += `<tr>
            <td style="color:#9ca3af;">${i+1}</td>
            <td>${r.record_date||'—'}</td>
            <td>${esc(r.delivery_person_name||r.delivery_person_code||'—')}</td>
            <td><strong>${esc(r.sku_code||'—')}</strong></td>
            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;" title="${esc(r.sku_desc||'')}">${esc(r.sku_desc||'—')}</td>
            <td class="num">${fmtN(r.tur)}</td>
            <td class="num">${fmtN(r.mrp)}</td>
            <td class="num" style="color:#166534;font-weight:600;">${fmtN(r.adj_qty_good_units)}</td>
            <td class="num" style="color:#991b1b;font-weight:600;">${fmtN(r.adj_qty_damage)}</td>
            <td><span class="badge" style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;">${esc(r.status||'')}</span></td>
        </tr>`;
    });
    return html + '</tbody></table>';
}

function buildUdTable(rows) {
    if (!rows || !rows.length)
        return '<div style="text-align:center;padding:30px;color:#9ca3af;">No working-data snapshot found.</div>';

    let html = `<table class="snap-table"><thead><tr>
        <th>#</th><th>SKU Code</th><th>Del. Person</th>
        <th class="num">Adj Good</th><th class="num">Adj Dmg</th>
        <th class="num col-highlight">Actual Qty</th><th class="num col-highlight">Actual Dmg</th>
        <th class="num col-highlight">S/E</th>
        <th class="num col-highlight">Charge</th><th class="num col-highlight">Absorb</th>
        <th class="num col-highlight">Variance</th>
        <th>Status</th>
    </tr></thead><tbody>`;
    rows.forEach((r,i) => {
        const hasActual = r.actual_qty !== null && r.actual_qty !== undefined && r.actual_qty !== '';
        html += `<tr>
            <td style="color:#9ca3af;">${i+1}</td>
            <td><strong>${esc(r.sku_code||'—')}</strong></td>
            <td>${esc(r.delivery_person_name||r.delivery_person_code||'—')}</td>
            <td class="num" style="color:#166534;font-weight:600;">${fmtN(r.adj_qty_good_units)}</td>
            <td class="num" style="color:#991b1b;font-weight:600;">${fmtN(r.adj_qty_damage)}</td>
            <td class="num col-highlight" style="font-weight:600;">${r.actual_qty!==null&&r.actual_qty!==undefined?fmtN(r.actual_qty):'<span style="color:#d1d5db;">—</span>'}</td>
            <td class="num col-highlight">${r.actual_damage_qty!==null&&r.actual_damage_qty!==undefined?fmtN(r.actual_damage_qty):'<span style="color:#d1d5db;">—</span>'}</td>
            <td class="num col-highlight">${r.short_excess!==null&&r.short_excess!==undefined?seSpan(r.short_excess):'<span style="color:#d1d5db;">—</span>'}</td>
            <td class="num col-highlight" style="color:#dc2626;">${r.charge_to_employee?fmtN(r.charge_to_employee):'<span style="color:#d1d5db;">—</span>'}</td>
            <td class="num col-highlight" style="color:#0369a1;">${r.absorb_by_company?fmtN(r.absorb_by_company):'<span style="color:#d1d5db;">—</span>'}</td>
            <td class="num col-highlight">${r.pay_variance!==null&&r.pay_variance!==undefined?seSpan(r.pay_variance):'<span style="color:#d1d5db;">—</span>'}</td>
            <td><span class="badge" style="font-size:10px;background:#f9fafb;border:1px solid #e5e5e5;color:#555;">${esc(r.status||'')}</span></td>
        </tr>`;
    });
    return html + '</tbody></table>';
}

function seSpan(v) {
    v = parseFloat(v);
    const cls = v < 0 ? 'color:#dc2626' : v > 0 ? 'color:#16a34a' : 'color:#6b7280';
    return `<span style="font-weight:700;${cls}">${v > 0 ? '+' : ''}${v.toFixed(2)}</span>`;
}
function fmtN(v) { return v !== null && v !== undefined ? parseFloat(v).toFixed(2) : '—'; }
function esc(s) { const d=document.createElement('div');d.textContent=s;return d.innerHTML; }


/* ══════════════════════════════════════════════════════════════════
   REVERSE
   ══════════════════════════════════════════════════════════════════ */
let pendingReverseLogId = null;

function confirmReverse(logId, importId, oldFilename) {
    pendingReverseLogId = logId;
    document.getElementById('reverseModalDesc').innerHTML =
        `Restore Import <strong>#${importId}</strong> to file: <em>${oldFilename || 'previous version'}</em>.<br>` +
        `This will overwrite the current import data with the snapshot from Log <strong>#${logId}</strong>.`;
    document.getElementById('reverseModal').classList.add('open');
}

function closeReverseModal() {
    document.getElementById('reverseModal').classList.remove('open');
}

function executeReverse() {
    if (!pendingReverseLogId) return;
    const btn = document.getElementById('confirmReverseBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Reversing...';

    fetch('process_reverse_reimport.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ log_id: pendingReverseLogId })
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Yes, Reverse';

        if (res.success) {
            closeReverseModal();
            showToast(
                `Reversed! Restored ${res.restored_det} detail rows, ` +
                `${res.restored_ud} working rows, removed ${res.deleted_ud} new rows.`,
                'success'
            );
            // Update row UI
            const logRow = document.getElementById('log-row-' + pendingReverseLogId);
            if (logRow) {
                setTimeout(() => location.reload(), 1800);
            }
            pendingReverseLogId = null;
        } else {
            showToast('Error: ' + res.message, 'error');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Yes, Reverse';
        showToast('Network error: ' + err.message, 'error');
    });
}


/* ══════════════════════════════════════════════════════════════════
   DELETE LOG
   ══════════════════════════════════════════════════════════════════ */
let pendingDelLogId = null;

function confirmDeleteLog(logId) {
    pendingDelLogId = logId;
    document.getElementById('deleteLogDesc').innerHTML =
        `Delete Log <strong>#${logId}</strong> and all its snapshots?<br>` +
        `<strong>You will no longer be able to reverse this reimport.</strong> This action cannot be undone.`;
    document.getElementById('deleteLogModal').classList.add('open');
}

function closeDeleteLogModal() {
    document.getElementById('deleteLogModal').classList.remove('open');
}

function executeDeleteLog() {
    if (!pendingDelLogId) return;
    const btn = document.getElementById('confirmDelLogBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting...';

    fetch('delete_import_log.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ log_id: pendingDelLogId })
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete Log';
        if (res.success) {
            const logRow = document.getElementById('log-row-' + pendingDelLogId);
            if (logRow) logRow.remove();
            closeDeleteLogModal();
            showToast('Log entry deleted.', 'success');
            pendingDelLogId = null;
        } else {
            showToast('Error: ' + res.message, 'error');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete Log';
        showToast('Network error: ' + err.message, 'error');
    });
}


/* ══════════════════════════════════════════════════════════════════
   TOAST
   ══════════════════════════════════════════════════════════════════ */
function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = type; t.style.display = 'block';
    clearTimeout(t._t);
    t._t = setTimeout(() => t.style.display = 'none', 4000);
}
</script>

<?php include 'footer.php'; ?>
