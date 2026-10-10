<?php
include 'config.php';

// ── Handle delete BEFORE any output ────────────────────────────
if (isset($_GET['delete_import'])) {
    $import_id = intval($_GET['delete_import']);
    mysqli_query($conn, "DELETE FROM unloading_summary_import_details WHERE import_id = $import_id");
    mysqli_query($conn, "DELETE FROM unloading_data WHERE import_id = $import_id");
    if (mysqli_query($conn, "DELETE FROM unloading_summary_imports WHERE id = $import_id")) {
        header('Location: unloading_import_history.php?deleted=1');
    } else {
        header('Location: unloading_import_history.php?delete_error=1');
    }
    exit;
}

include 'header.php';

$success_message = isset($_GET['deleted'])      ? "Import record deleted successfully!" : '';
$error_message   = isset($_GET['delete_error']) ? "Failed to delete import record."     : '';

// Filters
$filter_status        = isset($_GET['filter_status'])        ? trim($_GET['filter_status'])        : '';
$filter_date          = isset($_GET['filter_date'])          ? trim($_GET['filter_date'])          : '';
$filter_delivery_date = isset($_GET['filter_delivery_date']) ? trim($_GET['filter_delivery_date']) : '';

// Build WHERE (on imports table only)
$where = "1=1";
if ($filter_status) {
    $fs    = mysqli_real_escape_string($conn, $filter_status);
    $where .= " AND i.status = '$fs'";
}
if ($filter_date) {
    $fd    = mysqli_real_escape_string($conn, $filter_date);
    $where .= " AND DATE(i.imported_at) = '$fd'";
}
if ($filter_delivery_date) {
    $fdd   = mysqli_real_escape_string($conn, $filter_delivery_date);
    $where .= " AND DATE(i.delivery_date) = '$fdd'";
}

// ── MAIN QUERY ──────────────────────────────────────────────────────────────
// charge_to_employee / absorb_by_company / pay_variance in unloading_data are:
//   - NULL  → pay not yet allocated
//   - filled → pay has been explicitly saved via the Pay modal
//
// Strategy per column:
//   charge_to_employee : if column has value use it; else compute tur*ABS(short_excess) for shorts
//   absorb_by_company  : ONLY the real saved value. NO excess fallback.
//   pay_variance       : if column has value use it; else compute tur*short_excess (signed)
//
// ── ABSORB FIX ──────────────────────────────────────────────────────────────
// "Absorb by Company" must reflect ONLY amounts that were actually saved through
// the Pay Allocation modal. The previous version fell back to tur*short_excess for
// excess rows, which wrongly reported excess money as "Absorbed by Company" even
// when nothing had been absorbed. That fallback is now removed — we simply sum the
// stored absorb_by_company values (0 → shown as a dash in the UI).
// ────────────────────────────────────────────────────────────────────────────
$query = "
    SELECT
        i.id, i.filename, i.delivery_date, i.imported_at, i.status,
        i.imported_records, i.failed_records,

        /* ── Adj Qty — from import_details ── */
        (SELECT COALESCE(SUM(adj_qty_good_units + adj_qty_damage), 0)
         FROM unloading_summary_import_details
         WHERE import_id = i.id)                                                  AS total_adj_qty,

        /* ── Actual Qty — from unloading_data ── */
        (SELECT COALESCE(SUM(actual_qty), 0)
         FROM unloading_data
         WHERE import_id = i.id)                                                  AS total_actual_qty,

        /* ── Shortage Qty (short_excess < 0) ── */
        (SELECT COALESCE(SUM(ABS(short_excess)), 0)
         FROM unloading_data
         WHERE import_id = i.id AND short_excess < 0)                            AS total_shortage_qty,

        /* ── Shortage Value (tur × |short_excess| where short) ── */
        (SELECT COALESCE(SUM(tur * ABS(short_excess)), 0)
         FROM unloading_data
         WHERE import_id = i.id AND short_excess < 0 AND tur > 0)               AS total_shortage_value,

        /* ── Excess Qty (short_excess > 0) ── */
        (SELECT COALESCE(SUM(short_excess), 0)
         FROM unloading_data
         WHERE import_id = i.id AND short_excess > 0)                            AS total_excess_qty,

        /* ── Excess Value (tur × short_excess where excess) ── */
        (SELECT COALESCE(SUM(tur * short_excess), 0)
         FROM unloading_data
         WHERE import_id = i.id AND short_excess > 0 AND tur > 0)               AS total_excess_value,

        /* ── Total S/E Value (all rows with short_excess set, tur * ABS) ── */
        (SELECT COALESCE(SUM(tur * ABS(short_excess)), 0)
         FROM unloading_data
         WHERE import_id = i.id AND short_excess IS NOT NULL AND tur > 0)        AS total_se_value,

        /* ── Charge to Employee ──────────────────────────────────────────
             If charge_to_employee is filled (pay allocated), use it.
             Otherwise fall back: tur * ABS(short_excess) for short rows.
             This makes the column meaningful before pay is allocated. ── */
        (SELECT COALESCE(
            NULLIF(SUM(charge_to_employee), 0),
            SUM(CASE WHEN short_excess < 0 AND tur > 0
                     THEN tur * ABS(short_excess) ELSE 0 END)
         )
         FROM unloading_data
         WHERE import_id = i.id)                                                  AS total_charge,

        /* ── Absorb by Company (FIXED) ───────────────────────────────────
             ONLY the real saved absorb_by_company amounts. No excess
             fallback — nothing is 'absorbed' until it is explicitly saved
             in the Pay Allocation modal. ── */
        (SELECT COALESCE(SUM(absorb_by_company), 0)
         FROM unloading_data
         WHERE import_id = i.id)                                                  AS total_absorb,

        /* ── Pay Variance ────────────────────────────────────────────────
             If pay_variance is filled, use it.
             Otherwise compute: SUM(tur * short_excess) — signed value
             (negative = net shortage, positive = net excess). ── */
        (SELECT COALESCE(
            NULLIF(SUM(pay_variance), 0),
            SUM(CASE WHEN short_excess IS NOT NULL AND tur > 0
                     THEN tur * short_excess ELSE 0 END)
         )
         FROM unloading_data
         WHERE import_id = i.id)                                                  AS total_variance,

        /* ── Progress counters ── */
        (SELECT COUNT(*)
         FROM unloading_data
         WHERE import_id = i.id AND actual_qty IS NOT NULL)                      AS rows_filled,

        (SELECT COUNT(*)
         FROM unloading_data
         WHERE import_id = i.id)                                                  AS rows_total

    FROM unloading_summary_imports i
    WHERE $where
    ORDER BY i.delivery_date DESC, i.imported_at DESC
";
$result = mysqli_query($conn, $query);

// Overall stats (unfiltered)
$stats = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total_imports,
            COALESCE(SUM(imported_records),0) as total_imported,
            COALESCE(SUM(failed_records),0) as total_failed
     FROM unloading_summary_imports"));

// ── Grand totals (filtered) ──────────────────────────────────────────────────
// Same fallback logic as per-row above, applied across all filtered imports.
// Absorb uses the FIXED logic: real saved values only, no excess fallback.
// ─────────────────────────────────────────────────────────────────────────────
$totals_where_ids = "SELECT id FROM unloading_summary_imports WHERE $where";

$totals = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        /* Adj Qty from import_details */
        (SELECT COALESCE(SUM(adj_qty_good_units + adj_qty_damage), 0)
         FROM unloading_summary_import_details
         WHERE import_id IN ($totals_where_ids))                                   AS grand_adj_qty,

        COALESCE(SUM(ud.actual_qty), 0)                                            AS grand_actual_qty,

        COALESCE(SUM(CASE WHEN ud.short_excess < 0
                          THEN ABS(ud.short_excess) ELSE 0 END), 0)               AS grand_shortage_qty,

        COALESCE(SUM(CASE WHEN ud.short_excess < 0 AND ud.tur > 0
                          THEN ud.tur * ABS(ud.short_excess) ELSE 0 END), 0)      AS grand_shortage_value,

        COALESCE(SUM(CASE WHEN ud.short_excess > 0
                          THEN ud.short_excess ELSE 0 END), 0)                    AS grand_excess_qty,

        COALESCE(SUM(CASE WHEN ud.short_excess > 0 AND ud.tur > 0
                          THEN ud.tur * ud.short_excess ELSE 0 END), 0)           AS grand_excess_value,

        COALESCE(SUM(CASE WHEN ud.short_excess IS NOT NULL AND ud.tur > 0
                          THEN ud.tur * ABS(ud.short_excess) ELSE 0 END), 0)      AS grand_se_value,

        /* Charge: use saved value if present, else compute for shorts */
        COALESCE(
            NULLIF(SUM(ud.charge_to_employee), 0),
            SUM(CASE WHEN ud.short_excess < 0 AND ud.tur > 0
                     THEN ud.tur * ABS(ud.short_excess) ELSE 0 END)
        )                                                                           AS grand_charge,

        /* Absorb (FIXED): real saved absorb_by_company only, no excess fallback */
        COALESCE(SUM(ud.absorb_by_company), 0)                                      AS grand_absorb,

        /* Variance: use saved value if present, else signed tur × s/e */
        COALESCE(
            NULLIF(SUM(ud.pay_variance), 0),
            SUM(CASE WHEN ud.short_excess IS NOT NULL AND ud.tur > 0
                     THEN ud.tur * ud.short_excess ELSE 0 END)
        )                                                                           AS grand_variance

    FROM unloading_data ud
    WHERE ud.import_id IN ($totals_where_ids)
"));
?>

<div class="page-header">
    <h2 class="page-title">
        <i class="fa-solid fa-history"></i> Unloading Import History
    </h2>
    <p class="page-subtitle">View and manage previously imported unloading summary files</p>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success_message; ?></div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-error"><i class="fa-solid fa-circle-xmark"></i> <?php echo $error_message; ?></div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="stats-row">
    <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff;color:#1e40af;"><i class="fa-solid fa-file-import"></i></div>
        <div class="stat-info">
            <span class="stat-value"><?php echo intval($stats['total_imports']); ?></span>
            <span class="stat-label">Total Imports</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f0fdf4;color:#166534;"><i class="fa-solid fa-circle-check"></i></div>
        <div class="stat-info">
            <span class="stat-value"><?php echo number_format($stats['total_imported']); ?></span>
            <span class="stat-label">Records Imported</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fef2f2;color:#991b1b;"><i class="fa-solid fa-circle-xmark"></i></div>
        <div class="stat-info">
            <span class="stat-value"><?php echo number_format($stats['total_failed']); ?></span>
            <span class="stat-label">Records Failed</span>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="content-card">
    <form method="GET" class="filter-form">
        <div class="filter-row">
            <div class="filter-group">
                <label class="form-label">Delivery Date</label>
                <input type="date" name="filter_delivery_date" class="form-input"
                       value="<?php echo htmlspecialchars($filter_delivery_date); ?>">
            </div>
            <div class="filter-group">
                <label class="form-label">Imported Date</label>
                <input type="date" name="filter_date" class="form-input"
                       value="<?php echo htmlspecialchars($filter_date); ?>">
            </div>
            <div class="filter-group">
                <label class="form-label">Status</label>
                <select name="filter_status" class="form-input">
                    <option value="">All Status</option>
                    <option value="completed"  <?php echo $filter_status==='completed'  ? 'selected':''; ?>>Completed</option>
                    <option value="processing" <?php echo $filter_status==='processing' ? 'selected':''; ?>>Processing</option>
                    <option value="failed"     <?php echo $filter_status==='failed'     ? 'selected':''; ?>>Failed</option>
                    <option value="pending"    <?php echo $filter_status==='pending'    ? 'selected':''; ?>>Pending</option>
                </select>
            </div>
            <div class="filter-group filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-search"></i> Filter
                </button>
                <a href="unloading_import_history.php" class="btn btn-secondary">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </a>
            </div>
        </div>
    </form>
</div>

<!-- History Table -->
<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title">
            <i class="fa-solid fa-list"></i> Import Records
            <?php if ($filter_delivery_date || $filter_date || $filter_status): ?>
                <span class="badge badge-warning" style="font-size:11px;margin-left:6px;">
                    <i class="fa-solid fa-filter"></i> Filtered
                </span>
            <?php endif; ?>
        </h3>
        <a href="import_unloading.php" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-plus"></i> New Import
        </a>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th rowspan="2">#</th>
                    <th rowspan="2">Delivery Date</th>
                    <th rowspan="2" style="text-align:right;">Adj Qty</th>
                    <th rowspan="2" style="text-align:right;">Actual Qty</th>
                    <th colspan="2" style="text-align:center;background:#fef2f2;color:#b91c1c;">Shortage</th>
                    <th colspan="2" style="text-align:center;background:#f0fdf4;color:#15803d;">Excess</th>
                    <th rowspan="2" style="text-align:right;">S/E Value</th>
                    <th rowspan="2" style="text-align:right;">Charge to<br>Employee</th>
                    <th rowspan="2" style="text-align:right;">Absorb by<br>Company</th>
                    <th rowspan="2" style="text-align:right;">Variance</th>
                    <th rowspan="2">Progress</th>
                    <th rowspan="2">Status</th>
                    <th rowspan="2">Imported At</th>
                    <th rowspan="2" style="text-align:center;">Actions</th>
                </tr>
                <tr>
                    <th style="text-align:right;background:#fef2f2;color:#b91c1c;font-size:11px;">Qty</th>
                    <th style="text-align:right;background:#fef2f2;color:#b91c1c;font-size:11px;">Value</th>
                    <th style="text-align:right;background:#f0fdf4;color:#15803d;font-size:11px;">Qty</th>
                    <th style="text-align:right;background:#f0fdf4;color:#15803d;font-size:11px;">Value</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($result && mysqli_num_rows($result) > 0): ?>
                <?php
                $row_num = 1;
                while ($row = mysqli_fetch_assoc($result)):
                    $adj_qty       = floatval($row['total_adj_qty']       ?? 0);
                    $actual_qty    = floatval($row['total_actual_qty']     ?? 0);
                    $shortage_qty  = floatval($row['total_shortage_qty']   ?? 0);
                    $shortage_val  = floatval($row['total_shortage_value'] ?? 0);
                    $excess_qty    = floatval($row['total_excess_qty']     ?? 0);
                    $excess_val    = floatval($row['total_excess_value']   ?? 0);
                    $se_value      = floatval($row['total_se_value']       ?? 0);
                    $charge        = floatval($row['total_charge']         ?? 0);
                    $absorb        = floatval($row['total_absorb']         ?? 0);
                    $variance      = floatval($row['total_variance']       ?? 0);
                    $rows_filled   = intval($row['rows_filled']            ?? 0);
                    $rows_total    = intval($row['rows_total']             ?? 0);
                    $progress_pct  = $rows_total > 0 ? round(($rows_filled / $rows_total) * 100) : 0;
                    $delivery_date = !empty($row['delivery_date']) ? date('Y-m-d', strtotime($row['delivery_date'])) : '';
                ?>
                <tr>
                    <td><?php echo $row_num++; ?></td>
                    <td style="font-weight:600;"><?php echo $delivery_date ?: '—'; ?></td>

                    <!-- Adj Qty -->
                    <td style="text-align:right;font-weight:700;">
                        <?php echo $adj_qty > 0 ? number_format($adj_qty, 2) : '<span class="dash">—</span>'; ?>
                    </td>

                    <!-- Actual Qty -->
                    <td style="text-align:right;font-weight:700;color:#1e40af;">
                        <?php echo $actual_qty > 0 ? number_format($actual_qty, 2) : '<span class="dash">—</span>'; ?>
                    </td>

                    <!-- Shortage Qty -->
                    <td style="text-align:right;background:#fff8f8;">
                        <?php if ($shortage_qty > 0): ?>
                            <span style="color:#dc2626;font-weight:700;"><?php echo number_format($shortage_qty, 2); ?></span>
                        <?php else: ?><span class="dash">—</span><?php endif; ?>
                    </td>

                    <!-- Shortage Value -->
                    <td style="text-align:right;background:#fff8f8;">
                        <?php if ($shortage_val > 0): ?>
                            <span style="color:#dc2626;font-weight:700;"><?php echo number_format($shortage_val, 2); ?></span>
                        <?php else: ?><span class="dash">—</span><?php endif; ?>
                    </td>

                    <!-- Excess Qty -->
                    <td style="text-align:right;background:#f8fff8;">
                        <?php if ($excess_qty > 0): ?>
                            <span style="color:#16a34a;font-weight:700;"><?php echo number_format($excess_qty, 2); ?></span>
                        <?php else: ?><span class="dash">—</span><?php endif; ?>
                    </td>

                    <!-- Excess Value -->
                    <td style="text-align:right;background:#f8fff8;">
                        <?php if ($excess_val > 0): ?>
                            <span style="color:#16a34a;font-weight:700;"><?php echo number_format($excess_val, 2); ?></span>
                        <?php else: ?><span class="dash">—</span><?php endif; ?>
                    </td>

                    <!-- S/E Value -->
                    <td style="text-align:right;font-weight:700;">
                        <?php if ($se_value > 0): ?>
                            <span style="color:#7c3aed;"><?php echo number_format($se_value, 2); ?></span>
                        <?php else: ?><span class="dash">—</span><?php endif; ?>
                    </td>

                    <!-- Charge to Employee -->
                    <td style="text-align:right;font-weight:700;">
                        <?php if ($charge > 0): ?>
                            <span style="color:#dc2626;"><?php echo number_format($charge, 2); ?></span>
                        <?php else: ?><span class="dash">—</span><?php endif; ?>
                    </td>

                    <!-- Absorb by Company (shows only real saved absorb amounts) -->
                    <td style="text-align:right;font-weight:700;">
                        <?php if ($absorb > 0): ?>
                            <span style="color:#d97706;"><?php echo number_format($absorb, 2); ?></span>
                        <?php else: ?><span class="dash">—</span><?php endif; ?>
                    </td>

                    <!-- Variance -->
                    <td style="text-align:right;font-weight:700;">
                        <?php if (abs($variance) > 0.005): ?>
                            <span style="color:<?php echo $variance < 0 ? '#dc2626' : '#16a34a'; ?>;">
                                <?php echo ($variance > 0 ? '+' : '') . number_format($variance, 2); ?>
                            </span>
                        <?php else: ?><span class="dash">—</span><?php endif; ?>
                    </td>

                    <!-- Progress -->
                    <td style="min-width:100px;">
                        <?php if ($rows_total > 0): ?>
                        <div style="font-size:11px;color:#6b7280;margin-bottom:3px;">
                            <?php echo $rows_filled; ?>/<?php echo $rows_total; ?> rows
                        </div>
                        <div class="prog-bar">
                            <div class="prog-fill" style="width:<?php echo $progress_pct; ?>%;background:<?php echo $progress_pct==100?'#16a34a':'#7c3aed'; ?>;"></div>
                        </div>
                        <div style="font-size:10px;color:#9ca3af;margin-top:2px;"><?php echo $progress_pct; ?>%</div>
                        <?php else: ?>
                            <span class="dash">—</span>
                        <?php endif; ?>
                    </td>

                    <!-- Status -->
                    <td>
                        <?php
                        $status = $row['status'];
                        $bc = 'badge-inactive'; $ic = 'fa-clock';
                        if ($status==='completed')      { $bc='badge-success'; $ic='fa-circle-check'; }
                        elseif ($status==='processing') { $bc='badge-warning'; $ic='fa-spinner fa-spin'; }
                        elseif ($status==='failed')     { $bc='badge-error';   $ic='fa-circle-xmark'; }
                        ?>
                        <span class="badge <?php echo $bc; ?>">
                            <i class="fa-solid <?php echo $ic; ?>"></i> <?php echo ucfirst($status); ?>
                        </span>
                    </td>

                    <!-- Imported At -->
                    <td style="font-size:12px;color:#666;white-space:nowrap;">
                        <?php echo date('Y-m-d H:i', strtotime($row['imported_at'])); ?>
                    </td>

                    <!-- Actions -->
                    <td>
                        <div class="action-buttons" style="justify-content:center;">
                            <a href="shortage.php?id=<?php echo $row['id']; ?>"
                               class="btn-action btn-view" title="View Shortage Details">
                                <i class="fa-solid fa-eye"></i>
                            </a>

                            <a href="reconcile_shortage.php?id=<?php echo $row['id']; ?>"
                               class="btn-action btn-reconcile" title="Reconcile Short/Excess">
                                <i class="fa-solid fa-scale-balanced"></i>
                            </a>

                            <a href="javascript:void(0)"
                               onclick="deleteImport(<?php echo $row['id']; ?>, '<?php echo addslashes(htmlspecialchars($row['filename'])); ?>')"
                               class="btn-action btn-delete" title="Delete">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>

                <!-- ══ GRAND TOTALS ROW ══ -->
                <?php
                $gt_adj      = floatval($totals['grand_adj_qty']        ?? 0);
                $gt_actual   = floatval($totals['grand_actual_qty']     ?? 0);
                $gt_sQty     = floatval($totals['grand_shortage_qty']   ?? 0);
                $gt_sVal     = floatval($totals['grand_shortage_value'] ?? 0);
                $gt_eQty     = floatval($totals['grand_excess_qty']     ?? 0);
                $gt_eVal     = floatval($totals['grand_excess_value']   ?? 0);
                $gt_se       = floatval($totals['grand_se_value']       ?? 0);
                $gt_charge   = floatval($totals['grand_charge']         ?? 0);
                $gt_absorb   = floatval($totals['grand_absorb']         ?? 0);
                $gt_variance = floatval($totals['grand_variance']       ?? 0);
                ?>
                <tr class="totals-row">
                    <td colspan="2" style="font-weight:700;font-size:13px;color:#1f2937;">
                        <i class="fa-solid fa-sigma" style="color:#7c3aed;margin-right:6px;"></i> Grand Totals
                    </td>
                    <td style="text-align:right;font-weight:800;font-size:14px;">
                        <?php echo number_format($gt_adj, 2); ?>
                    </td>
                    <td style="text-align:right;font-weight:800;font-size:14px;color:#1e40af;">
                        <?php echo number_format($gt_actual, 2); ?>
                    </td>
                    <td style="text-align:right;font-weight:800;font-size:14px;background:#fff0f0;">
                        <?php echo $gt_sQty > 0 ? '<span style="color:#dc2626;">'.number_format($gt_sQty,2).'</span>' : '<span class="dash">—</span>'; ?>
                    </td>
                    <td style="text-align:right;font-weight:800;font-size:14px;background:#fff0f0;">
                        <?php echo $gt_sVal > 0 ? '<span style="color:#dc2626;">'.number_format($gt_sVal,2).'</span>' : '<span class="dash">—</span>'; ?>
                    </td>
                    <td style="text-align:right;font-weight:800;font-size:14px;background:#f0fff0;">
                        <?php echo $gt_eQty > 0 ? '<span style="color:#16a34a;">'.number_format($gt_eQty,2).'</span>' : '<span class="dash">—</span>'; ?>
                    </td>
                    <td style="text-align:right;font-weight:800;font-size:14px;background:#f0fff0;">
                        <?php echo $gt_eVal > 0 ? '<span style="color:#16a34a;">'.number_format($gt_eVal,2).'</span>' : '<span class="dash">—</span>'; ?>
                    </td>
                    <td style="text-align:right;font-weight:800;font-size:14px;">
                        <?php echo $gt_se > 0 ? '<span style="color:#7c3aed;">'.number_format($gt_se,2).'</span>' : '<span class="dash">—</span>'; ?>
                    </td>
                    <td style="text-align:right;font-weight:800;font-size:14px;">
                        <?php echo $gt_charge > 0 ? '<span style="color:#dc2626;">'.number_format($gt_charge,2).'</span>' : '<span class="dash">—</span>'; ?>
                    </td>
                    <td style="text-align:right;font-weight:800;font-size:14px;">
                        <?php echo $gt_absorb > 0 ? '<span style="color:#d97706;">'.number_format($gt_absorb,2).'</span>' : '<span class="dash">—</span>'; ?>
                    </td>
                    <td style="text-align:right;font-weight:800;font-size:14px;">
                        <?php if (abs($gt_variance) > 0.005): ?>
                            <span style="color:<?php echo $gt_variance<0?'#dc2626':'#16a34a'; ?>;">
                                <?php echo ($gt_variance>0?'+':'').number_format($gt_variance,2); ?>
                            </span>
                        <?php else: ?><span class="dash">—</span><?php endif; ?>
                    </td>
                    <td colspan="4"></td>
                </tr>

            <?php else: ?>
                <tr>
                    <td colspan="16" class="empty-state">
                        <i class="fa-solid fa-inbox"></i>
                        <p>No import records found</p>
                        <a href="import_unloading.php" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-upload"></i> Import Now
                        </a>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
/* ── Base ── */
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:0;color:#1f2937;display:flex;align-items:center;gap:8px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;display:flex;align-items:center;gap:16px}
.stat-icon{width:48px;height:48px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px}
.stat-info{display:flex;flex-direction:column}
.stat-value{font-size:24px;font-weight:700;color:#1f2937}
.stat-label{font-size:12px;color:#6b7280;margin-top:2px}
/* ── Filter ── */
.filter-form{margin:0}
.filter-row{display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap}
.filter-group{flex:1;min-width:160px}
.filter-actions{display:flex;gap:8px;flex:none}
.form-label{display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:#374151}
.form-input{width:100%;padding:10px 14px;border:1px solid #e5e5e5;border-radius:6px;font-size:14px;font-family:'Inter',sans-serif;box-sizing:border-box}
.form-input:focus{outline:none;border-color:#000}
/* ── Alerts ── */
.alert{padding:12px 16px;border-radius:6px;margin-bottom:20px;display:flex;align-items:center;gap:8px;font-size:13px}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;font-family:'Inter',sans-serif;text-decoration:none}
.btn-sm{padding:8px 14px;font-size:13px}
.btn-primary{background:#000;color:#fff}
.btn-primary:hover{background:#333}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}
/* ── Table ── */
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead{background:#fafafa}
.data-table th{padding:10px 12px;text-align:left;font-weight:600;color:#333;font-size:12px;white-space:nowrap;border-bottom:1px solid #e5e5e5;border-right:1px solid #f0f0f0}
.data-table tbody tr{border-bottom:1px solid #f0f0f0;transition:background .1s}
.data-table tbody tr:hover{background:#fafafa}
.data-table td{padding:10px 12px;color:#333;white-space:nowrap;vertical-align:middle}
.dash{color:#d1d5db}
/* ── Totals row ── */
.totals-row{background:#f5f3ff!important;border-top:2px solid #7c3aed!important;border-bottom:2px solid #7c3aed!important}
.totals-row td{padding:13px 12px!important}
.totals-row:hover{background:#ede9fe!important}
/* ── Progress bar ── */
.prog-bar{height:6px;background:#e5e5e5;border-radius:3px;overflow:hidden}
.prog-fill{height:100%;border-radius:3px;transition:width .3s}
/* ── Badges ── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap}
.badge-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.badge-warning{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.badge-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.badge-inactive{background:#fafafa;color:#666;border:1px solid #e5e5e5}
/* ── Action buttons ── */
.action-buttons{display:flex;gap:5px}
.btn-action{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#666;cursor:pointer;transition:all .2s;text-decoration:none;font-size:12px}
.btn-action:hover{transform:translateY(-1px);box-shadow:0 2px 6px rgba(0,0,0,.1)}
.btn-view{background:#eff6ff;color:#1e40af;border-color:#bfdbfe}
.btn-view:hover{background:#1e40af;color:#fff}
.btn-edit{background:#fdf4ff;color:#7c3aed;border-color:#e9d5ff}
.btn-edit:hover{background:#7c3aed;color:#fff}
.btn-reconcile{background:#fffbeb;color:#d97706;border-color:#fde68a}
.btn-reconcile:hover{background:#d97706;color:#fff}
.btn-delete:hover{background:#ef4444;color:#fff;border-color:#ef4444}
/* ── Empty ── */
.empty-state{text-align:center;padding:60px 20px!important;color:#999}
.empty-state i{font-size:48px;display:block;margin-bottom:12px;color:#ddd}
.empty-state p{margin-bottom:16px;font-size:14px}
@media(max-width:768px){
    .stats-row{grid-template-columns:1fr}
    .filter-row{flex-direction:column}
    .filter-group{min-width:100%}
}
</style>

<script>
function deleteImport(id, filename) {
    if (confirm('Delete import "' + filename + '" and ALL its records?\n\nThis cannot be undone.')) {
        window.location.href = 'unloading_import_history.php?delete_import=' + id;
    }
}
</script>

<?php include 'footer.php'; ?>