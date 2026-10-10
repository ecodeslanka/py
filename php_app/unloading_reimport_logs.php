<?php
// ══════════════════════════════════════════════════════════════════════════════
//  unloading_reimport_logs.php
//  View all re-import log events.
//  Per log: see archived detail rows, and REVERSE (restore) the old data.
//
//  Reversal logic:
//    1. Archive the CURRENT import_details into a new log entry (so the reversal
//       itself is logged and nothing is permanently lost)
//    2. Delete current unloading_summary_import_details for the import_id
//    3. Re-insert the archived rows from unloading_reimport_detail_logs back
//       into unloading_summary_import_details
//    4. Update unloading_data adj_qty fields from the restored detail rows
//       (matching by delivery_person_code + sku_code, preserving user-entered data)
//    5. Update unloading_summary_imports header counts & filename
//    6. Mark the log entry as reversed
// ══════════════════════════════════════════════════════════════════════════════

error_reporting(0);
ini_set('display_errors', 0);

ob_start();
include 'config.php';
ob_end_clean();
include 'header.php';

if (!$conn) {
    die('<p style="color:red;padding:20px;">Database connection failed.</p>');
}

// ── Ensure tables exist (safe to run every page load) ────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS unloading_reimport_logs (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    import_id           INT NOT NULL,
    reimport_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    old_filename        VARCHAR(255) NULL,
    new_filename        VARCHAR(255) NULL,
    old_record_count    INT DEFAULT 0,
    new_record_count    INT DEFAULT 0,
    updated_rows        INT DEFAULT 0,
    added_rows          INT DEFAULT 0,
    skipped_rows        INT DEFAULT 0,
    reversed            TINYINT(1) DEFAULT 0,
    reversed_at         TIMESTAMP NULL,
    reversed_by_log_id  INT NULL,
    note                TEXT NULL,
    INDEX idx_import_id (import_id)
)");

// Add reversed columns if they don't exist yet (upgrade-safe)
@mysqli_query($conn, "ALTER TABLE unloading_reimport_logs ADD COLUMN reversed TINYINT(1) DEFAULT 0");
@mysqli_query($conn, "ALTER TABLE unloading_reimport_logs ADD COLUMN reversed_at TIMESTAMP NULL");
@mysqli_query($conn, "ALTER TABLE unloading_reimport_logs ADD COLUMN reversed_by_log_id INT NULL");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS unloading_reimport_detail_logs (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    log_id                  INT NOT NULL,
    import_id               INT NOT NULL,
    original_detail_id      INT NULL,
    record_date             DATE NULL,
    delivery_person_code    VARCHAR(100) NULL,
    delivery_person_name    VARCHAR(255) NULL,
    vehicle                 VARCHAR(255) NULL,
    sku_code                VARCHAR(100) NULL,
    sku_desc                VARCHAR(500) NULL,
    tur                     DECIMAL(12,2) DEFAULT 0,
    mrp                     DECIMAL(12,2) DEFAULT 0,
    adj_qty_good_units      DECIMAL(12,2) DEFAULT 0,
    adj_qty_damage          DECIMAL(12,2) DEFAULT 0,
    delivery_date           DATE NULL,
    archived_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_id (log_id),
    INDEX idx_import_id (import_id)
)");

// ── REVERSAL ACTION ───────────────────────────────────────────────────────────
$action_msg   = '';
$action_type  = ''; // 'success' | 'error'

if (isset($_POST['action']) && $_POST['action'] === 'reverse') {
    $rev_log_id   = intval($_POST['log_id']   ?? 0);
    $rev_import_id = intval($_POST['import_id'] ?? 0);

    if ($rev_log_id <= 0 || $rev_import_id <= 0) {
        $action_type = 'error';
        $action_msg  = 'Invalid log_id or import_id for reversal.';
    } else {
        // Check log exists and is not already reversed
        $log_check = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM unloading_reimport_logs WHERE id = $rev_log_id AND import_id = $rev_import_id"));

        if (!$log_check) {
            $action_type = 'error';
            $action_msg  = "Log entry #$rev_log_id not found.";
        } elseif ($log_check['reversed']) {
            $action_type = 'error';
            $action_msg  = "Log entry #$rev_log_id has already been reversed.";
        } else {
            // ── Count archived rows available for restoration ──
            $arch_cnt = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) as c FROM unloading_reimport_detail_logs WHERE log_id = $rev_log_id"));
            $arch_rows = intval($arch_cnt['c'] ?? 0);

            if ($arch_rows === 0) {
                $action_type = 'error';
                $action_msg  = "No archived detail rows found for log #$rev_log_id. Cannot reverse.";
            } else {
                // ── STEP A: Get current import header ──
                $cur_imp = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT * FROM unloading_summary_imports WHERE id = $rev_import_id"));

                if (!$cur_imp) {
                    $action_type = 'error';
                    $action_msg  = "Import #$rev_import_id not found.";
                } else {
                    // ── STEP B: Archive CURRENT state before reversal (safety log) ──
                    $cur_count_r = mysqli_fetch_assoc(mysqli_query($conn,
                        "SELECT COUNT(*) as c FROM unloading_summary_import_details WHERE import_id = $rev_import_id"));
                    $cur_count = intval($cur_count_r['c'] ?? 0);
                    $arch_fn   = mysqli_real_escape_string($conn, $cur_imp['filename']);
                    $old_fn    = mysqli_real_escape_string($conn, $log_check['old_filename'] ?? '');

                    $safety_ins = "INSERT INTO unloading_reimport_logs
                        (import_id, old_filename, new_filename, old_record_count, new_record_count,
                         updated_rows, added_rows, skipped_rows, note)
                        VALUES ($rev_import_id, '$arch_fn', '$old_fn', $cur_count, $arch_rows,
                                0, 0, 0, 'Pre-reversal safety snapshot before reversing log #$rev_log_id')";
                    mysqli_query($conn, $safety_ins);
                    $safety_log_id = mysqli_insert_id($conn);

                    // Archive current details into the safety log
                    mysqli_query($conn, "INSERT INTO unloading_reimport_detail_logs
                        (log_id, import_id, original_detail_id,
                         record_date, delivery_person_code, delivery_person_name, vehicle,
                         sku_code, sku_desc, tur, mrp, adj_qty_good_units, adj_qty_damage,
                         delivery_date)
                        SELECT $safety_log_id, import_id, id,
                               record_date, delivery_person_code, delivery_person_name, vehicle,
                               sku_code, sku_desc, tur, mrp, adj_qty_good_units, adj_qty_damage,
                               delivery_date
                        FROM unloading_summary_import_details
                        WHERE import_id = $rev_import_id");

                    // ── STEP C: Delete current import_details ──
                    mysqli_query($conn, "DELETE FROM unloading_summary_import_details WHERE import_id = $rev_import_id");

                    // ── STEP D: Re-insert archived rows back into import_details ──
                    $restored   = 0;
                    $rest_failed = 0;
                    $arch_res = mysqli_query($conn,
                        "SELECT * FROM unloading_reimport_detail_logs WHERE log_id = $rev_log_id ORDER BY id ASC");

                    // Build unloading_data lookup for sync
                    $ud_index = [];
                    $ud_q = mysqli_query($conn,
                        "SELECT id, delivery_person_code, sku_code FROM unloading_data WHERE import_id = $rev_import_id");
                    if ($ud_q) {
                        while ($ur = mysqli_fetch_assoc($ud_q)) {
                            $k = strtolower(trim($ur['delivery_person_code'])) . '|' . strtolower(trim($ur['sku_code']));
                            $ud_index[$k] = intval($ur['id']);
                        }
                    }

                    $dd_from_log = !empty($log_check['old_filename']) ? 'NULL' : 'NULL';
                    // Use delivery_date from the first archived row
                    $first_dd_r = mysqli_fetch_assoc(mysqli_query($conn,
                        "SELECT delivery_date FROM unloading_reimport_detail_logs
                         WHERE log_id = $rev_log_id AND delivery_date IS NOT NULL LIMIT 1"));
                    $restore_dd = ($first_dd_r && !empty($first_dd_r['delivery_date']))
                        ? "'" . $first_dd_r['delivery_date'] . "'"
                        : 'NULL';

                    $ud_updated = 0;
                    $ud_added   = 0;

                    if ($arch_res) {
                        while ($ar = mysqli_fetch_assoc($arch_res)) {
                            $rd  = !empty($ar['record_date']) ? "'" . $ar['record_date'] . "'" : 'NULL';
                            $dd  = !empty($ar['delivery_date']) ? "'" . $ar['delivery_date'] . "'" : 'NULL';
                            $dpc = mysqli_real_escape_string($conn, $ar['delivery_person_code']);
                            $dpn = mysqli_real_escape_string($conn, $ar['delivery_person_name']);
                            $veh = mysqli_real_escape_string($conn, $ar['vehicle']);
                            $sku = mysqli_real_escape_string($conn, $ar['sku_code']);
                            $skd = mysqli_real_escape_string($conn, $ar['sku_desc']);
                            $tur = floatval($ar['tur']);
                            $mrp = floatval($ar['mrp']);
                            $ag  = floatval($ar['adj_qty_good_units']);
                            $adm = floatval($ar['adj_qty_damage']);

                            $ins = "INSERT INTO unloading_summary_import_details
                                (import_id, record_date, delivery_person_code, delivery_person_name,
                                 vehicle, sku_code, sku_desc, tur, mrp,
                                 adj_qty_good_units, adj_qty_damage, delivery_date, status)
                                VALUES
                                ($rev_import_id, $rd, '$dpc', '$dpn',
                                 '$veh', '$sku', '$skd', $tur, $mrp,
                                 $ag, $adm, $dd, 'imported')";

                            if (mysqli_query($conn, $ins)) {
                                $new_detail_id = mysqli_insert_id($conn);
                                $restored++;

                                // ── STEP E: Sync unloading_data ──
                                if ($ag == 0 && $adm == 0) continue;

                                $key = strtolower($ar['delivery_person_code']) . '|' . strtolower($ar['sku_code']);
                                if (isset($ud_index[$key])) {
                                    $uid = $ud_index[$key];
                                    mysqli_query($conn, "UPDATE unloading_data SET
                                        adj_qty_good_units = $ag,
                                        adj_qty_damage     = $adm,
                                        tur                = $tur,
                                        mrp                = $mrp,
                                        vehicle            = '$veh',
                                        import_detail_id   = $new_detail_id,
                                        delivery_date      = $dd,
                                        record_date        = $rd
                                        WHERE id = $uid AND import_id = $rev_import_id");
                                    $ud_updated++;
                                } else {
                                    $ins_ud = "INSERT INTO unloading_data
                                        (import_id, import_detail_id, record_date,
                                         delivery_person_code, delivery_person_name, vehicle,
                                         sku_code, sku_desc, tur, mrp,
                                         adj_qty_good_units, adj_qty_damage,
                                         delivery_date, status)
                                        VALUES
                                        ($rev_import_id, $new_detail_id, $rd,
                                         '$dpc', '$dpn', '$veh',
                                         '$sku', '$skd', $tur, $mrp,
                                         $ag, $adm, $dd, 'imported')";
                                    if (mysqli_query($conn, $ins_ud)) {
                                        $ud_added++;
                                        $ud_index[$key] = mysqli_insert_id($conn);
                                    }
                                }
                            } else {
                                $rest_failed++;
                            }
                        }
                    }

                    // ── STEP F: Update import header ──
                    $old_fn_header = mysqli_real_escape_string($conn, $log_check['old_filename'] ?? $cur_imp['filename']);
                    mysqli_query($conn, "UPDATE unloading_summary_imports
                        SET filename         = '$old_fn_header',
                            total_records    = $restored,
                            imported_records = $restored,
                            failed_records   = $rest_failed,
                            delivery_date    = $restore_dd,
                            status           = 'completed'
                        WHERE id = $rev_import_id");

                    // ── STEP G: Mark original log as reversed ──
                    mysqli_query($conn, "UPDATE unloading_reimport_logs
                        SET reversed           = 1,
                            reversed_at        = NOW(),
                            reversed_by_log_id = $safety_log_id,
                            note = CONCAT(COALESCE(note,''), ' | REVERSED at ', NOW(), ' — safety log #$safety_log_id')
                        WHERE id = $rev_log_id");

                    // Update safety log note with final counts
                    mysqli_query($conn, "UPDATE unloading_reimport_logs
                        SET new_record_count = $restored,
                            updated_rows     = $ud_updated,
                            added_rows       = $ud_added,
                            note = CONCAT(COALESCE(note,''), ' | Restored: $restored detail rows, $ud_updated ud-updated, $ud_added ud-added')
                        WHERE id = $safety_log_id");

                    $action_type = 'success';
                    $action_msg  = "✓ Reversal complete for Import #$rev_import_id — Log #$rev_log_id. "
                                 . "$restored detail rows restored. Unloading data: $ud_updated updated, $ud_added added. "
                                 . "Safety snapshot saved as Log #$safety_log_id.";
                }
            }
        }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_import = isset($_GET['import_id']) ? intval($_GET['import_id']) : 0;
$filter_date   = trim($_GET['filter_date'] ?? '');

$where = '1=1';
if ($filter_import > 0) $where .= " AND l.import_id = $filter_import";
if (!empty($filter_date)) {
    $fd = mysqli_real_escape_string($conn, $filter_date);
    $where .= " AND DATE(l.reimport_at) = '$fd'";
}

// ── Fetch logs with import filename ──────────────────────────────────────────
$logs_res = mysqli_query($conn, "
    SELECT l.*,
           i.filename  AS current_filename,
           i.delivery_date AS import_delivery_date,
           (SELECT COUNT(*) FROM unloading_reimport_detail_logs d WHERE d.log_id = l.id) AS archived_rows
    FROM unloading_reimport_logs l
    LEFT JOIN unloading_summary_imports i ON i.id = l.import_id
    WHERE $where
    ORDER BY l.reimport_at DESC
");

// ── Overall stats ─────────────────────────────────────────────────────────────
$stats = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total_logs,
            SUM(old_record_count) as total_archived,
            SUM(reversed) as total_reversed
     FROM unloading_reimport_logs"));

// ── Distinct import list for filter dropdown ──────────────────────────────────
$imports_for_filter = mysqli_query($conn,
    "SELECT DISTINCT l.import_id, i.filename
     FROM unloading_reimport_logs l
     LEFT JOIN unloading_summary_imports i ON i.id = l.import_id
     ORDER BY l.import_id DESC");
?>

<!-- ── Styles ──────────────────────────────────────────────────────────────── -->
<style>
*,*::before,*::after{box-sizing:border-box}

/* ── Layout ──────────────────────────────────────────────────────── */
.page-header{margin-bottom:24px}
.page-title{font-size:22px;font-weight:800;color:#1f2937;margin:0 0 4px;display:flex;align-items:center;gap:10px}
.page-subtitle{font-size:13px;color:#6b7280;margin:0}

/* ── Stats row ───────────────────────────────────────────────────── */
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:22px}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:18px 20px;display:flex;align-items:center;gap:14px}
.stat-icon{width:46px;height:46px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.stat-info{display:flex;flex-direction:column}
.stat-value{font-size:26px;font-weight:800;color:#1f2937;line-height:1}
.stat-label{font-size:12px;color:#6b7280;margin-top:3px}

/* ── Card ────────────────────────────────────────────────────────── */
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:20px;margin-bottom:20px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.card-title{font-size:15px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;margin:0}

/* ── Alerts ──────────────────────────────────────────────────────── */
.alert{display:flex;align-items:flex-start;gap:10px;padding:14px 16px;border-radius:8px;margin-bottom:20px;font-size:13px;line-height:1.6}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #86efac}
.alert-error  {background:#fef2f2;color:#991b1b;border:1px solid #fca5a5}
.alert i{margin-top:2px;flex-shrink:0}

/* ── Filter form ─────────────────────────────────────────────────── */
.filter-row{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}
.filter-group{flex:1;min-width:160px}
.filter-group.filter-actions{flex:none}
.form-label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;text-transform:uppercase;letter-spacing:.3px}
.form-input{width:100%;padding:9px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;outline:none;transition:border-color .2s}
.form-input:focus{border-color:#b45309;box-shadow:0 0 0 3px rgba(180,83,9,.07)}

/* ── Buttons ─────────────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;text-decoration:none;white-space:nowrap;transition:all .2s}
.btn-sm{padding:7px 13px;font-size:12px}
.btn-xs{padding:4px 10px;font-size:11px}
.btn-primary{background:#1f2937;color:#fff}
.btn-primary:hover{background:#111827}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}
.btn-danger{background:#dc2626;color:#fff}
.btn-danger:hover{background:#b91c1c}
.btn-danger:disabled{opacity:.5;cursor:not-allowed}
.btn-amber{background:#b45309;color:#fff}
.btn-amber:hover{background:#92400e}
.btn-info{background:#0369a1;color:#fff}
.btn-info:hover{background:#075985}
.btn-success{background:#16a34a;color:#fff}
.btn-success:hover{background:#15803d}

/* ── Table ───────────────────────────────────────────────────────── */
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead{background:#f8f8f8;border-bottom:2px solid #e0e0e0}
.data-table th{padding:11px 13px;text-align:left;font-weight:700;color:#374151;font-size:11px;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
.data-table th.num{text-align:right}
.data-table tbody tr{border-bottom:1px solid #f0f0f0;transition:background .12s}
.data-table tbody tr:hover{background:#fafafa}
.data-table td{padding:11px 13px;color:#374151;vertical-align:middle}
.data-table td.num{text-align:right;font-variant-numeric:tabular-nums}
.data-table tbody tr.reversed-row{background:#fafafa;opacity:.75}
.data-table tbody tr.reversed-row td{color:#9ca3af}

/* ── Badges ──────────────────────────────────────────────────────── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:700;white-space:nowrap}
.badge-amber  {background:#fffbeb;color:#b45309;border:1px solid #fde68a}
.badge-green  {background:#f0fdf4;color:#166534;border:1px solid #86efac}
.badge-red    {background:#fef2f2;color:#991b1b;border:1px solid #fca5a5}
.badge-blue   {background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.badge-gray   {background:#f3f4f6;color:#6b7280;border:1px solid #e5e5e5}
.badge-safety {background:#fdf4ff;color:#7c3aed;border:1px solid #e9d5ff}

/* ── Expandable detail panel ────────────────────────────────────── */
.detail-panel{display:none;background:#f9fafb;border-top:1px solid #e5e5e5}
.detail-panel.open{display:table-row}
.detail-inner{padding:16px 20px}
.detail-title{font-size:13px;font-weight:700;color:#374151;margin-bottom:10px;display:flex;align-items:center;gap:8px}
.detail-meta{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px}
.meta-item{font-size:12px;color:#6b7280}
.meta-item strong{color:#1f2937}
.detail-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px}
.detail-table th{background:#f0f0f0;padding:7px 10px;text-align:left;font-weight:700;color:#374151;font-size:11px}
.detail-table th.num{text-align:right}
.detail-table td{padding:7px 10px;border-bottom:1px solid #e5e5e5;color:#374151}
.detail-table td.num{text-align:right;font-variant-numeric:tabular-nums}
.detail-table tbody tr:last-child td{border-bottom:none}

/* ── Reversal confirm modal ─────────────────────────────────────── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:none;align-items:center;justify-content:center;padding:16px}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:12px;padding:28px 32px;width:94%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.22);max-height:90vh;overflow-y:auto}
.modal-icon{font-size:46px;text-align:center;margin-bottom:14px}
.modal-title{font-size:18px;font-weight:800;color:#1f2937;text-align:center;margin-bottom:8px}
.modal-desc{font-size:13px;color:#6b7280;text-align:center;margin-bottom:20px;line-height:1.7}
.modal-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:20px}
.mi-cell{background:#f9fafb;border:1px solid #e5e5e5;border-radius:7px;padding:10px 12px}
.mi-lbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px}
.mi-val{font-size:13px;font-weight:700;color:#1f2937;word-break:break-all}
.modal-warning{background:#fffbeb;border:1px solid #fde68a;border-radius:7px;padding:12px 14px;font-size:12px;color:#92400e;margin-bottom:20px;line-height:1.6}
.modal-actions{display:flex;gap:10px;justify-content:flex-end}

/* ── Toast ───────────────────────────────────────────────────────── */
#toast{position:fixed;bottom:28px;right:28px;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;color:#fff;z-index:9999;display:none;box-shadow:0 4px 16px rgba(0,0,0,.18)}
#toast.success{background:#16a34a}
#toast.error  {background:#dc2626}

/* ── Empty state ─────────────────────────────────────────────────── */
.empty-state{text-align:center;padding:60px 20px;color:#9ca3af}
.empty-state i{font-size:44px;display:block;margin-bottom:12px;color:#d1d5db}
.empty-state p{font-size:14px;margin:0}

@media(max-width:680px){
    .stats-row{grid-template-columns:1fr 1fr}
    .filter-row{flex-direction:column}
    .modal-info-grid{grid-template-columns:1fr}
}
</style>

<!-- ── Page Header ───────────────────────────────────────────────────────── -->
<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-clock-rotate-left" style="color:#b45309;"></i>
                Re-Import Logs
            </h2>
            <p class="page-subtitle">Full audit trail of all re-import events &mdash; view archived data and reverse any re-import</p>
        </div>
        <a href="unloading_import_history.php" class="btn btn-secondary btn-sm">
            <i class="fa-solid fa-arrow-left"></i> Import History
        </a>
    </div>
</div>

<!-- ── Action message ────────────────────────────────────────────────────── -->
<?php if ($action_msg): ?>
<div class="alert alert-<?php echo $action_type; ?>">
    <i class="fa-solid fa-<?php echo $action_type === 'success' ? 'circle-check' : 'circle-xmark'; ?>"></i>
    <div><?php echo htmlspecialchars($action_msg); ?></div>
</div>
<?php endif; ?>

<!-- ── Stats ─────────────────────────────────────────────────────────────── -->
<div class="stats-row">
    <div class="stat-card">
        <div class="stat-icon" style="background:#fffbeb;color:#b45309;">
            <i class="fa-solid fa-clock-rotate-left"></i>
        </div>
        <div class="stat-info">
            <span class="stat-value"><?php echo intval($stats['total_logs'] ?? 0); ?></span>
            <span class="stat-label">Total Log Events</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff;color:#1e40af;">
            <i class="fa-solid fa-archive"></i>
        </div>
        <div class="stat-info">
            <span class="stat-value"><?php echo number_format(intval($stats['total_archived'] ?? 0)); ?></span>
            <span class="stat-label">Total Archived Rows</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fdf4ff;color:#7c3aed;">
            <i class="fa-solid fa-rotate-left"></i>
        </div>
        <div class="stat-info">
            <span class="stat-value"><?php echo intval($stats['total_reversed'] ?? 0); ?></span>
            <span class="stat-label">Reversals Done</span>
        </div>
    </div>
</div>

<!-- ── Filters ────────────────────────────────────────────────────────────── -->
<div class="content-card">
    <form method="GET" style="margin:0;">
        <div class="filter-row">
            <div class="filter-group">
                <label class="form-label">Filter by Import</label>
                <select name="import_id" class="form-input">
                    <option value="">All Imports</option>
                    <?php if ($imports_for_filter): while ($ifr = mysqli_fetch_assoc($imports_for_filter)): ?>
                    <option value="<?php echo $ifr['import_id']; ?>"
                        <?php echo ($filter_import == $ifr['import_id']) ? 'selected' : ''; ?>>
                        #<?php echo $ifr['import_id']; ?> — <?php echo htmlspecialchars($ifr['filename'] ?? 'unknown'); ?>
                    </option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div class="filter-group">
                <label class="form-label">Re-Import Date</label>
                <input type="date" name="filter_date" class="form-input"
                       value="<?php echo htmlspecialchars($filter_date); ?>">
            </div>
            <div class="filter-group filter-actions" style="display:flex;gap:8px;align-items:flex-end;">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-search"></i> Filter
                </button>
                <a href="unloading_reimport_logs.php" class="btn btn-secondary btn-sm">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </a>
            </div>
        </div>
    </form>
</div>

<!-- ── Log Table ──────────────────────────────────────────────────────────── -->
<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title">
            <i class="fa-solid fa-list-check"></i> Log Entries
        </h3>
        <span style="font-size:12px;color:#6b7280;" id="logCount"></span>
    </div>

    <div class="table-responsive">
        <table class="data-table" id="logTable">
            <thead>
                <tr>
                    <th style="width:36px;"></th>
                    <th>Log #</th>
                    <th>Import #</th>
                    <th>Re-Imported At</th>
                    <th>Old Filename</th>
                    <th>New Filename</th>
                    <th class="num">Old Rows</th>
                    <th class="num">New Rows</th>
                    <th class="num">UD Updated</th>
                    <th class="num">UD Added</th>
                    <th class="num">Archived</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody id="logBody">
            <?php
            $row_num = 1;
            if ($logs_res && mysqli_num_rows($logs_res) > 0):
                while ($log = mysqli_fetch_assoc($logs_res)):
                    $is_reversed  = (bool)$log['reversed'];
                    $is_safety    = str_contains($log['note'] ?? '', 'Pre-reversal safety snapshot');
                    $row_class    = $is_reversed ? 'reversed-row' : '';
                    $type_badge   = $is_safety
                        ? '<span class="badge badge-safety"><i class="fa-solid fa-shield"></i> Safety</span>'
                        : '<span class="badge badge-amber"><i class="fa-solid fa-rotate"></i> Re-Import</span>';
                    $status_badge = $is_reversed
                        ? '<span class="badge badge-gray"><i class="fa-solid fa-rotate-left"></i> Reversed</span>'
                        : '<span class="badge badge-green"><i class="fa-solid fa-circle-check"></i> Active</span>';
            ?>
            <tr class="log-row <?php echo $row_class; ?>" id="logrow-<?php echo $log['id']; ?>">
                <td>
                    <button class="btn btn-secondary btn-xs"
                            onclick="toggleDetail(<?php echo $log['id']; ?>)"
                            id="expand-<?php echo $log['id']; ?>"
                            title="View archived rows">
                        <i class="fa-solid fa-chevron-right" id="chevron-<?php echo $log['id']; ?>"></i>
                    </button>
                </td>
                <td><strong>#<?php echo $log['id']; ?></strong></td>
                <td>
                    <a href="shortage.php?id=<?php echo $log['import_id']; ?>" style="color:#1e40af;font-weight:600;text-decoration:none;">
                        #<?php echo $log['import_id']; ?>
                    </a>
                </td>
                <td style="font-size:12px;color:#6b7280;white-space:nowrap;">
                    <?php echo date('Y-m-d H:i:s', strtotime($log['reimport_at'])); ?>
                    <?php if ($is_reversed && !empty($log['reversed_at'])): ?>
                        <br><span style="color:#7c3aed;font-size:11px;"><i class="fa-solid fa-rotate-left"></i> Reversed: <?php echo date('Y-m-d H:i', strtotime($log['reversed_at'])); ?></span>
                    <?php endif; ?>
                </td>
                <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($log['old_filename'] ?? ''); ?>">
                    <i class="fa-solid fa-file-excel" style="color:#166534;"></i>
                    <?php echo htmlspecialchars($log['old_filename'] ?? '—'); ?>
                </td>
                <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($log['new_filename'] ?? ''); ?>">
                    <i class="fa-solid fa-file-excel" style="color:#b45309;"></i>
                    <?php echo htmlspecialchars($log['new_filename'] ?? '—'); ?>
                </td>
                <td class="num"><?php echo number_format(intval($log['old_record_count'])); ?></td>
                <td class="num"><?php echo number_format(intval($log['new_record_count'])); ?></td>
                <td class="num" style="color:#1e40af;"><?php echo number_format(intval($log['updated_rows'])); ?></td>
                <td class="num" style="color:#16a34a;"><?php echo number_format(intval($log['added_rows'])); ?></td>
                <td class="num">
                    <span class="badge badge-blue" style="font-size:11px;">
                        <?php echo number_format(intval($log['archived_rows'])); ?>
                    </span>
                </td>
                <td><?php echo $type_badge; ?></td>
                <td><?php echo $status_badge; ?></td>
                <td style="text-align:center;white-space:nowrap;">
                    <div style="display:flex;gap:5px;justify-content:center;">
                        <button class="btn btn-info btn-xs"
                                onclick="toggleDetail(<?php echo $log['id']; ?>)">
                            <i class="fa-solid fa-eye"></i> View
                        </button>
                        <?php if (!$is_reversed && !$is_safety): ?>
                        <button class="btn btn-danger btn-xs"
                                onclick="openReverseModal(<?php echo $log['id']; ?>, <?php echo $log['import_id']; ?>,
                                    '<?php echo addslashes(htmlspecialchars($log['old_filename'] ?? '')); ?>',
                                    '<?php echo addslashes(htmlspecialchars($log['new_filename'] ?? '')); ?>',
                                    <?php echo intval($log['old_record_count']); ?>,
                                    <?php echo intval($log['archived_rows']); ?>)">
                            <i class="fa-solid fa-rotate-left"></i> Reverse
                        </button>
                        <?php elseif ($is_reversed): ?>
                        <span style="font-size:11px;color:#9ca3af;font-style:italic;">Reversed</span>
                        <?php else: ?>
                        <span style="font-size:11px;color:#7c3aed;font-style:italic;">Safety snapshot</span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <!-- Detail Panel Row -->
            <tr class="detail-panel" id="detail-<?php echo $log['id']; ?>">
                <td colspan="14">
                    <div class="detail-inner" id="detail-inner-<?php echo $log['id']; ?>">
                        <div style="color:#9ca3af;font-size:13px;text-align:center;padding:12px 0;">
                            <i class="fa-solid fa-spinner fa-spin"></i> Loading archived rows…
                        </div>
                    </div>
                </td>
            </tr>
            <?php $row_num++; endwhile;
            else: ?>
            <tr>
                <td colspan="14">
                    <div class="empty-state">
                        <i class="fa-solid fa-inbox"></i>
                        <p>No re-import log entries found.</p>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>


<!-- ════════════════════════════════════════════════════════════════
     REVERSAL CONFIRM MODAL
     ════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="reverseModal" onclick="if(event.target===this)closeReverseModal()">
    <div class="modal-box">
        <div class="modal-icon">⏪</div>
        <div class="modal-title">Confirm Reversal</div>
        <div class="modal-desc">
            You are about to reverse this re-import. The import details will be
            <strong>restored to the state archived in this log entry</strong>.
        </div>

        <div class="modal-info-grid" id="reverseInfoGrid">
            <!-- filled by JS -->
        </div>

        <div class="modal-warning">
            <i class="fa-solid fa-shield" style="color:#b45309;"></i>
            <strong> A safety snapshot of the current state will be saved first</strong> — so this reversal is itself reversible.<br><br>
            <i class="fa-solid fa-lock" style="color:#7c3aed;"></i>
            User-entered data (actual qty, damage qty, pay allocations) in the working table
            will <strong>NOT</strong> be deleted — only the base adj qty values will be rolled back.
        </div>

        <form method="POST" id="reverseForm">
            <input type="hidden" name="action"    value="reverse">
            <input type="hidden" name="log_id"    id="reverseLogId"    value="">
            <input type="hidden" name="import_id" id="reverseImportId" value="">
        </form>

        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeReverseModal()">Cancel</button>
            <button class="btn btn-danger" id="confirmReverseBtn" onclick="submitReversal()">
                <i class="fa-solid fa-rotate-left"></i> Yes, Reverse It
            </button>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="toast"></div>

<!-- ════════════════════════════════════════════════════════════════
     JAVASCRIPT
     ════════════════════════════════════════════════════════════════ -->
<script>
/* ── Count rows ──────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
    const rows = document.querySelectorAll('#logBody .log-row');
    document.getElementById('logCount').textContent = rows.length + ' log event' + (rows.length !== 1 ? 's' : '');
});

/* ── Expand / Collapse detail panel ─────────────────────────────── */
const loadedDetails = {};

function toggleDetail(logId) {
    const panel   = document.getElementById('detail-' + logId);
    const chevron = document.getElementById('chevron-' + logId);
    const isOpen  = panel.classList.contains('open');

    if (isOpen) {
        panel.classList.remove('open');
        chevron.className = 'fa-solid fa-chevron-right';
        return;
    }

    panel.classList.add('open');
    chevron.className = 'fa-solid fa-chevron-down';

    if (loadedDetails[logId]) return; // already loaded

    // AJAX load archived rows
    fetch('unloading_reimport_logs.php?ajax=detail&log_id=' + logId)
        .then(r => r.json())
        .then(res => {
            loadedDetails[logId] = true;
            const inner = document.getElementById('detail-inner-' + logId);
            if (!res.success || !res.rows || res.rows.length === 0) {
                inner.innerHTML = '<div style="color:#9ca3af;font-size:13px;padding:10px;">No archived rows for this log entry.</div>';
                return;
            }
            let html = `
                <div class="detail-title">
                    <i class="fa-solid fa-archive" style="color:#b45309;"></i>
                    Archived Detail Rows — Log #${logId} &nbsp;
                    <span class="badge badge-blue">${res.rows.length} rows</span>
                </div>
                <div class="detail-meta">
                    <span class="meta-item"><strong>Import #:</strong> ${res.import_id}</span>
                    <span class="meta-item"><strong>Archived at:</strong> ${res.archived_at}</span>
                </div>
                <div style="overflow-x:auto;">
                <table class="detail-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Record Date</th>
                            <th>Delivery Person</th>
                            <th>Vehicle</th>
                            <th>SKU Code</th>
                            <th>SKU Description</th>
                            <th class="num">TUR</th>
                            <th class="num">MRP</th>
                            <th class="num">Adj Qty Good</th>
                            <th class="num">Adj Qty Damage</th>
                            <th>Delivery Date</th>
                        </tr>
                    </thead>
                    <tbody>`;
            res.rows.forEach((r, idx) => {
                html += `<tr>
                    <td>${idx + 1}</td>
                    <td>${r.record_date || '—'}</td>
                    <td>${escHtml(r.delivery_person_name || '')}</td>
                    <td>${escHtml(r.vehicle || '')}</td>
                    <td><strong>${escHtml(r.sku_code || '')}</strong></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escHtml(r.sku_desc || '')}">${escHtml(r.sku_desc || '')}</td>
                    <td class="num">${fmt2(r.tur)}</td>
                    <td class="num">${fmt2(r.mrp)}</td>
                    <td class="num" style="color:#166534;font-weight:600;">${fmt2(r.adj_qty_good_units)}</td>
                    <td class="num" style="color:#991b1b;font-weight:600;">${fmt2(r.adj_qty_damage)}</td>
                    <td>${r.delivery_date || '—'}</td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            inner.innerHTML = html;
        })
        .catch(() => {
            document.getElementById('detail-inner-' + logId).innerHTML =
                '<div style="color:#dc2626;font-size:13px;padding:10px;"><i class="fa-solid fa-circle-xmark"></i> Failed to load detail rows.</div>';
        });
}

/* ── Reversal modal ──────────────────────────────────────────────── */
function openReverseModal(logId, importId, oldFile, newFile, oldRows, archivedRows) {
    document.getElementById('reverseLogId').value    = logId;
    document.getElementById('reverseImportId').value = importId;

    document.getElementById('reverseInfoGrid').innerHTML = `
        <div class="mi-cell">
            <div class="mi-lbl">Log #</div>
            <div class="mi-val">#${logId}</div>
        </div>
        <div class="mi-cell">
            <div class="mi-lbl">Import #</div>
            <div class="mi-val">#${importId}</div>
        </div>
        <div class="mi-cell">
            <div class="mi-lbl">Will Restore (old file)</div>
            <div class="mi-val" style="font-size:11px;">${escHtml(oldFile)}</div>
        </div>
        <div class="mi-cell">
            <div class="mi-lbl">Will Replace (current file)</div>
            <div class="mi-val" style="font-size:11px;">${escHtml(newFile)}</div>
        </div>
        <div class="mi-cell">
            <div class="mi-lbl">Rows to Restore</div>
            <div class="mi-val" style="color:#166534;">${archivedRows}</div>
        </div>
        <div class="mi-cell">
            <div class="mi-lbl">Old Row Count</div>
            <div class="mi-val">${oldRows}</div>
        </div>`;

    document.getElementById('confirmReverseBtn').disabled = false;
    document.getElementById('confirmReverseBtn').innerHTML = '<i class="fa-solid fa-rotate-left"></i> Yes, Reverse It';
    document.getElementById('reverseModal').classList.add('open');
}

function closeReverseModal() {
    document.getElementById('reverseModal').classList.remove('open');
}

function submitReversal() {
    const btn = document.getElementById('confirmReverseBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Reversing…';
    showToast('Processing reversal…', 'success');
    document.getElementById('reverseForm').submit();
}

/* ── Helpers ─────────────────────────────────────────────────────── */
function fmt2(v) {
    const n = parseFloat(v);
    return isNaN(n) ? '0.00' : n.toFixed(2);
}
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = type; t.style.display = 'block';
    clearTimeout(t._t); t._t = setTimeout(() => t.style.display = 'none', 3500);
}
</script>

<?php
// ── AJAX endpoint for detail rows ─────────────────────────────────────────────
// Called by JS: fetch('unloading_reimport_logs.php?ajax=detail&log_id=X')
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detail') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $log_id = intval($_GET['log_id'] ?? 0);
    if ($log_id <= 0) {
        echo json_encode(['success' => false, 'rows' => []]);
        exit;
    }
    // Get log metadata
    $lm = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT import_id, reimport_at FROM unloading_reimport_logs WHERE id = $log_id"));
    // Get archived rows
    $dr = mysqli_query($conn,
        "SELECT record_date, delivery_person_name, vehicle, sku_code, sku_desc,
                tur, mrp, adj_qty_good_units, adj_qty_damage, delivery_date
         FROM unloading_reimport_detail_logs
         WHERE log_id = $log_id
         ORDER BY delivery_person_name, sku_code
         LIMIT 2000");
    $rows = [];
    if ($dr) while ($rr = mysqli_fetch_assoc($dr)) $rows[] = $rr;
    echo json_encode([
        'success'     => true,
        'import_id'   => $lm['import_id'] ?? 0,
        'archived_at' => !empty($lm['reimport_at']) ? date('Y-m-d H:i:s', strtotime($lm['reimport_at'])) : '',
        'rows'        => $rows,
    ]);
    exit;
}
?>

<?php include 'footer.php'; ?>
