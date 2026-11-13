<?php
include 'config.php';
include 'header.php';

$import_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$import_id) {
    header('Location: gse_list.php');
    exit;
}

// Get import header info
$import_result = mysqli_query($conn, "SELECT * FROM unloading_summary_imports WHERE id = $import_id");
if (!$import_result || mysqli_num_rows($import_result) === 0) {
    header('Location: gse_list.php');
    exit;
}
$import = mysqli_fetch_assoc($import_result);

/*
 * FIX: group short/excess by SKU across ALL delivery persons.
 * Previously each unloading_data row was listed separately, so the same item
 * appeared once per person (or looked like only one person's value).
 * Now every SKU shows ONE row with:
 *   - total short qty   (sum of all negative S/E for that SKU)
 *   - total excess qty  (sum of all positive S/E for that SKU)
 *   - net S/E           (short + excess combined)
 *   - per-person breakdown
 */
mysqli_query($conn, "SET SESSION group_concat_max_len = 10000");
$rows_result = mysqli_query($conn,
    "SELECT sku_code,
            MAX(sku_desc)                                                   AS sku_desc,
            SUM(COALESCE(actual_qty,0))                                     AS actual_qty,
            SUM(short_excess)                                               AS net_se,
            SUM(CASE WHEN short_excess < 0 THEN -short_excess ELSE 0 END)   AS short_qty,
            SUM(CASE WHEN short_excess > 0 THEN  short_excess ELSE 0 END)   AS excess_qty,
            COUNT(*)                                                        AS line_count,
            COUNT(DISTINCT delivery_person_name)                            AS person_count,
            GROUP_CONCAT(
                CONCAT(COALESCE(NULLIF(delivery_person_name,''),'(no name)'),
                       ' (', IF(short_excess > 0, '+', ''), FORMAT(short_excess, 2), ')')
                ORDER BY delivery_person_name SEPARATOR ', ')               AS breakdown
     FROM unloading_data
     WHERE import_id = $import_id
       AND short_excess IS NOT NULL
       AND short_excess <> 0
     GROUP BY sku_code
     ORDER BY net_se ASC, sku_code ASC");

// Totals
$total_short  = 0;
$total_excess = 0;
$total_net    = 0;
$total_actual = 0;
$se_rows = [];
if ($rows_result) {
    while ($r = mysqli_fetch_assoc($rows_result)) {
        $total_short  += floatval($r['short_qty']);
        $total_excess += floatval($r['excess_qty']);
        $total_net    += floatval($r['net_se']);
        $total_actual += floatval($r['actual_qty']);
        $se_rows[] = $r;
    }
}
?>

<div class="page-header">
    <h2 class="page-title">
        <i class="fa-solid fa-scale-balanced"></i> Reconcile Short / Excess
    </h2>
    <p class="page-subtitle">
        <?php echo htmlspecialchars($import['filename'] ?? ''); ?>
        <?php if (!empty($import['delivery_date'])): ?>
            &middot; Delivery Date: <?php echo date('Y-m-d', strtotime($import['delivery_date'])); ?>
        <?php endif; ?>
    </p>
</div>

<div class="stats-row">
    <div class="stat-card">
        <div class="stat-icon" style="background:#fef2f2;color:#991b1b;"><i class="fa-solid fa-arrow-down"></i></div>
        <div class="stat-info">
            <span class="stat-value" style="color:#dc2626;"><?php echo number_format($total_short, 2); ?></span>
            <span class="stat-label">Total Shortage Qty</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f0fdf4;color:#166534;"><i class="fa-solid fa-arrow-up"></i></div>
        <div class="stat-info">
            <span class="stat-value" style="color:#16a34a;"><?php echo number_format($total_excess, 2); ?></span>
            <span class="stat-label">Total Excess Qty</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f5f3ff;color:#7c3aed;"><i class="fa-solid fa-list-ol"></i></div>
        <div class="stat-info">
            <span class="stat-value"><?php echo count($se_rows); ?></span>
            <span class="stat-label">SKUs With S/E</span>
        </div>
    </div>
</div>

<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title">
            <i class="fa-solid fa-file-excel"></i> Reconcile Against LeverEDGE Stock Adjustment Excel
        </h3>
    </div>
    <p style="font-size:13px;color:#6b7280;margin:0 0 14px;">
        Upload the LeverEDGE "Stock Adjustments" export. Rows tagged with a
        <code>Delivery Shorts</code> / <code>Delivery Excess</code> remark are matched to this
        import's short/excess rows by <b>delivery date + SKU code</b>, and compared for a tally.
        Rows that can't be auto-matched can be linked manually below.
    </p>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <input type="file" id="excelFile" accept=".xlsx" class="form-input" style="max-width:360px;">
        <button type="button" class="btn btn-primary btn-sm" onclick="previewReconcile()">
            <i class="fa-solid fa-magnifying-glass-chart"></i> Preview Tally
        </button>
        <span id="previewSpinner" style="display:none;color:#6b7280;font-size:13px;">
            <i class="fa-solid fa-spinner fa-spin"></i> Parsing excel...
        </span>
    </div>

    <div id="previewSection" style="display:none;margin-top:20px;">
        <div class="card-header-row">
            <h4 class="card-title" style="font-size:14px;">
                <i class="fa-solid fa-table-list"></i> Preview Tally — <span id="previewFilename"></span>
            </h4>
            <button type="button" class="btn btn-primary btn-sm" onclick="saveReconciliation()">
                <i class="fa-solid fa-floppy-disk"></i> Save Reconciliation
            </button>
        </div>
        <div class="table-responsive">
            <table class="data-table" id="previewTable">
                <thead>
                    <tr>
                        <th style="width:30px;"><input type="checkbox" id="chkAll" onclick="toggleAllPreview(this)"></th>
                        <th>SKU Code</th>
                        <th>SKU Name</th>
                        <th>Delivery Date</th>
                        <th style="text-align:right;">System S/E (Net)</th>
                        <th style="text-align:right;">Excel Qty</th>
                        <th>Tally Status</th>
                        <th style="width:100px;">Action</th>
                    </tr>
                </thead>
                <tbody id="previewBody"></tbody>
            </table>
        </div>
    </div>
</div>

<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title">
            <i class="fa-solid fa-clipboard-check"></i> Saved Reconciliations
        </h3>
        <button type="button" class="btn btn-danger-outline btn-sm" id="clearAllBtn" style="display:none;" onclick="deleteAllReconciliations()">
            <i class="fa-solid fa-trash-can"></i> Delete All
        </button>
    </div>
    <div class="table-responsive">
        <table class="data-table" id="savedTable">
            <thead>
                <tr>
                    <th>SKU Code</th>
                    <th>SKU Name</th>
                    <th>Delivery Date</th>
                    <th style="text-align:right;">System S/E</th>
                    <th style="text-align:right;">Excel Qty</th>
                    <th>Tally Status</th>
                    <th>Excel File</th>
                    <th>Saved At</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="savedBody">
                <tr><td colspan="9" class="empty-state" style="padding:24px!important;">
                    <i class="fa-solid fa-spinner fa-spin"></i> Loading...
                </td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title">
            <i class="fa-solid fa-list"></i> Short / Excess Items
            <span style="font-size:12px;font-weight:400;color:#6b7280;">(same SKU totalled across all delivery persons)</span>
        </h3>
        <div style="display:flex;gap:8px;">
            <a href="shortage.php?id=<?php echo $import_id; ?>" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-eye"></i> Full Details
            </a>
            <a href="gse_list.php" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>SKU Code</th>
                    <th>SKU Name</th>
                    <th style="text-align:center;">Persons</th>
                    <th style="text-align:right;">Qty</th>
                    <th style="text-align:right;">Short</th>
                    <th style="text-align:right;">Excess</th>
                    <th style="text-align:right;">Net Excess / Short</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($se_rows)): ?>
                <?php $n = 1; foreach ($se_rows as $r):
                    $short_q  = floatval($r['short_qty']);
                    $excess_q = floatval($r['excess_qty']);
                    $net      = floatval($r['net_se']);
                    if (abs($net) < 0.005) $net = 0;
                    $net_color = $net < 0 ? '#dc2626' : ($net > 0 ? '#16a34a' : '#6b7280');
                    $net_bg    = $net < 0 ? '#fff8f8' : ($net > 0 ? '#f8fff8' : '#fafafa');
                    $net_txt   = $net < 0 ? '-' . number_format(abs($net), 2)
                               : ($net > 0 ? '+' . number_format($net, 2) : '0.00');
                ?>
                <tr>
                    <td><?php echo $n++; ?></td>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($r['sku_code']); ?></td>
                    <td>
                        <?php echo htmlspecialchars($r['sku_desc']); ?>
                        <?php if (intval($r['line_count']) > 1): ?>
                            <div class="se-breakdown" title="<?php echo htmlspecialchars($r['breakdown']); ?>">
                                <i class="fa-solid fa-users"></i> <?php echo htmlspecialchars($r['breakdown']); ?>
                            </div>
                        <?php else: ?>
                            <div class="se-breakdown"><i class="fa-solid fa-user"></i> <?php echo htmlspecialchars($r['breakdown']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <span class="person-count"><?php echo intval($r['person_count']); ?></span>
                    </td>
                    <td style="text-align:right;"><?php echo number_format(floatval($r['actual_qty']), 2); ?></td>
                    <td style="text-align:right;">
                        <?php if ($short_q > 0): ?>
                            <span style="font-weight:600;color:#dc2626;">-<?php echo number_format($short_q, 2); ?></span>
                        <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <?php if ($excess_q > 0): ?>
                            <span style="font-weight:600;color:#16a34a;">+<?php echo number_format($excess_q, 2); ?></span>
                        <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
                    </td>
                    <td style="text-align:right;background:<?php echo $net_bg; ?>;">
                        <span style="font-weight:700;color:<?php echo $net_color; ?>;"><?php echo $net_txt; ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php
                    if (abs($total_net) < 0.005) $total_net = 0;
                    $tn_color = $total_net < 0 ? '#dc2626' : ($total_net > 0 ? '#16a34a' : '#6b7280');
                    $tn_txt   = $total_net < 0 ? '-' . number_format(abs($total_net), 2)
                              : ($total_net > 0 ? '+' . number_format($total_net, 2) : '0.00');
                ?>
                <tr class="se-total-row">
                    <td colspan="4" style="text-align:right;">TOTAL</td>
                    <td style="text-align:right;"><?php echo number_format($total_actual, 2); ?></td>
                    <td style="text-align:right;color:#dc2626;">-<?php echo number_format($total_short, 2); ?></td>
                    <td style="text-align:right;color:#16a34a;">+<?php echo number_format($total_excess, 2); ?></td>
                    <td style="text-align:right;color:<?php echo $tn_color; ?>;"><?php echo $tn_txt; ?></td>
                </tr>
            <?php else: ?>
                <tr>
                    <td colspan="8" class="empty-state">
                        <i class="fa-solid fa-circle-check"></i>
                        <p>No shortage or excess items for this import</p>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Manual Reconcile Modal -->
<div id="manualMatchModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-header">
            <h4><i class="fa-solid fa-link"></i> Manually Match Excel Row</h4>
            <button type="button" class="modal-close" onclick="closeManualMatch()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="modal-subheader">
            <div class="modal-subheader-label">System item (not found in Excel)</div>
            <div id="manualMatchSysInfo" class="modal-subheader-value"></div>
        </div>
        <div class="modal-search">
            <i class="fa-solid fa-magnifying-glass modal-search-icon"></i>
            <input type="text" id="manualMatchSearch" class="modal-search-input" placeholder="Search excel-only rows by SKU code or name..." oninput="filterManualCandidates()">
        </div>
        <div id="manualMatchList" class="modal-list"></div>
    </div>
</div>

<!-- Toast Notification Container -->
<div id="toastContainer" class="toast-container" aria-live="polite"></div>

<style>
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:0;color:#1f2937;display:flex;align-items:center;gap:8px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;display:flex;align-items:center;gap:16px}
.stat-icon{width:48px;height:48px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px}
.stat-info{display:flex;flex-direction:column}
.stat-value{font-size:24px;font-weight:700;color:#1f2937}
.stat-label{font-size:12px;color:#6b7280;margin-top:2px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;font-family:'Inter',sans-serif;text-decoration:none}
.btn-sm{padding:8px 14px;font-size:13px}
.btn-xs{padding:5px 10px;font-size:11.5px}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead{background:#fafafa}
.data-table th{padding:10px 12px;text-align:left;font-weight:600;color:#333;font-size:12px;white-space:nowrap;border-bottom:1px solid #e5e5e5;border-right:1px solid #f0f0f0}
.data-table tbody tr{border-bottom:1px solid #f0f0f0;transition:background .1s}
.data-table tbody tr:hover{background:#fafafa}
.data-table td{padding:10px 12px;color:#333;vertical-align:middle}
.empty-state{text-align:center;padding:60px 20px!important;color:#999}
.empty-state i{font-size:48px;display:block;margin-bottom:12px;color:#ddd}
.empty-state p{margin-bottom:16px;font-size:14px}
.tally-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap}
.tally-matched{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.tally-mismatch{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.tally-not_in_excel{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.tally-not_in_system{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.tally-manual{background:#eef2ff;color:#4338ca;border:1px solid #c7d2fe}
.tally-damage_cleared{background:#f0f9ff;color:#0058a3;border:1px solid #bae6fd}
.btn-icon-danger{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;border:1px solid #fecaca;background:#fef2f2;color:#dc2626;cursor:pointer;text-decoration:none}
.btn-icon-danger:hover{background:#dc2626;color:#fff}
.btn-ikea{background:#0058a3;color:#fff;border:1px solid #0058a3;}
.btn-ikea:hover{background:#004686;border-color:#004686;}
code{background:#f3f4f6;padding:1px 6px;border-radius:4px;font-size:12px;}
.action-cell{display:flex;flex-direction:column;gap:6px;align-items:flex-start;}
.manual-note{margin-top:6px;font-size:11.5px;color:#4338ca;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;padding:6px 9px;display:flex;align-items:flex-start;gap:6px;max-width:270px}
.manual-note i{margin-top:2px;}
.ikea-note{margin-top:6px;font-size:11.5px;color:#0058a3;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:6px 9px;display:flex;align-items:flex-start;gap:6px;max-width:270px}
.ikea-note i{margin-top:2px;}
.manual-note-used{margin-top:6px;font-size:11.5px;color:#6b7280;background:#f5f5f5;border:1px solid #e5e5e5;border-radius:6px;padding:6px 9px;display:flex;align-items:flex-start;gap:6px;max-width:270px}
.undo-link{margin-top:2px;background:none;border:none;padding:0;font-size:11px;color:#9ca3af;text-decoration:underline;cursor:pointer;display:inline-flex;align-items:center;gap:4px;}
.undo-link:hover{color:#dc2626;}
.consumed-row{opacity:.55;}
.consumed-row td{background:#fafafa!important;}

/* ---------------- Grouped S/E table ---------------- */
.se-breakdown{margin-top:3px;font-size:11px;color:#6b7280;max-width:420px;white-space:normal;line-height:1.4;}
.se-breakdown i{font-size:10px;color:#9ca3af;margin-right:2px;}
.person-count{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:22px;padding:0 7px;border-radius:11px;background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe;font-size:11.5px;font-weight:700;}
.se-total-row td{background:#f9fafb!important;font-weight:800;border-top:2px solid #e5e5e5;}

/* ---------------- Modal ---------------- */
.modal-overlay{position:fixed;inset:0;background:rgba(17,24,39,.6);backdrop-filter:blur(2px);display:flex;align-items:center;justify-content:center;z-index:1000;padding:20px;animation:modalFadeIn .15s ease-out;}
@keyframes modalFadeIn{from{opacity:0;}to{opacity:1;}}
@keyframes modalSlideUp{from{opacity:0;transform:translateY(14px) scale(.98);}to{opacity:1;transform:translateY(0) scale(1);}}
.modal-box{background:#fff;border-radius:14px;width:100%;max-width:540px;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(17,24,39,.35);overflow:hidden;animation:modalSlideUp .18s ease-out;}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid #eef0f2;background:#fafbfc;}
.modal-header h4{margin:0;font-size:15px;font-weight:700;color:#111827;display:flex;align-items:center;gap:9px;}
.modal-header h4 i{color:#4338ca;font-size:14px;}
.modal-close{background:#fff;border:1px solid #e5e7eb;cursor:pointer;font-size:13px;color:#6b7280;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;transition:all .15s;}
.modal-close:hover{background:#fee2e2;border-color:#fecaca;color:#dc2626;}
.modal-subheader{padding:16px 22px 0;}
.modal-subheader-label{font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.03em;color:#9ca3af;margin-bottom:6px;}
.modal-subheader-value{font-size:13.5px;color:#1f2937;background:#f8fafc;border:1px solid #eef0f2;border-radius:8px;padding:10px 12px;line-height:1.5;}
.modal-search{padding:16px 22px 0;position:relative;}
.modal-search-icon{position:absolute;left:36px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:12.5px;pointer-events:none;}
.modal-search-input{width:100%;box-sizing:border-box;padding:10px 14px 10px 34px;font-size:13.5px;font-family:'Inter',sans-serif;border:1px solid #e5e7eb;border-radius:9px;background:#fff;color:#111827;outline:none;transition:border-color .15s, box-shadow .15s;}
.modal-search-input::placeholder{color:#9ca3af;}
.modal-search-input:focus{border-color:#4338ca;box-shadow:0 0 0 3px rgba(67,56,202,.12);}
.modal-list{padding:16px 22px 22px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:10px;}
.manual-candidate-row{display:flex;align-items:center;justify-content:space-between;gap:14px;border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px;transition:border-color .15s, background .15s;}
.manual-candidate-row:hover{background:#fafbfc;border-color:#c7d2fe;}
.manual-candidate-info{font-size:12.5px;color:#333;line-height:1.6;}
.manual-candidate-info > div:first-child{font-size:13px;}
.btn-danger-outline{background:#fff;color:#dc2626;border:1px solid #fecaca;}
.btn-danger-outline:hover{background:#fef2f2;border-color:#fca5a5;}

/* ---------------- Toast Notifications ---------------- */
.toast-container{position:fixed;top:22px;right:22px;z-index:2000;display:flex;flex-direction:column;gap:12px;max-width:380px;}
.toast{position:relative;overflow:hidden;background:#fff;border-radius:12px;box-shadow:0 14px 34px rgba(17,24,39,.18);padding:14px 16px;display:flex;align-items:flex-start;gap:12px;animation:toastIn .4s cubic-bezier(.34,1.56,.64,1);}
.toast.toast-leaving{animation:toastOut .28s ease-in forwards;}
@keyframes toastIn{0%{transform:translateX(120%) scale(.95);opacity:0;}100%{transform:translateX(0) scale(1);opacity:1;}}
@keyframes toastOut{to{transform:translateX(120%) scale(.95);opacity:0;}}
@keyframes toastBar{from{width:100%;}to{width:0%;}}
@keyframes toastPop{0%{transform:scale(.4) rotate(-10deg);opacity:0;}60%{transform:scale(1.15) rotate(4deg);opacity:1;}100%{transform:scale(1) rotate(0);}}
.toast::after{content:'';position:absolute;left:0;bottom:0;height:3px;animation:toastBar 3.6s linear forwards;}
.toast-success{border-left:4px solid #16a34a;}
.toast-success::after{background:linear-gradient(90deg,#16a34a,#4ade80);}
.toast-error{border-left:4px solid #dc2626;}
.toast-error::after{background:linear-gradient(90deg,#dc2626,#f87171);}
.toast-info{border-left:4px solid #4338ca;}
.toast-info::after{background:linear-gradient(90deg,#4338ca,#818cf8);}
.toast-icon{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;animation:toastPop .5s cubic-bezier(.34,1.56,.64,1) .05s both;}
.toast-success .toast-icon{background:linear-gradient(135deg,#dcfce7,#bbf7d0);color:#16a34a;}
.toast-error .toast-icon{background:linear-gradient(135deg,#fee2e2,#fecaca);color:#dc2626;}
.toast-info .toast-icon{background:linear-gradient(135deg,#e0e7ff,#c7d2fe);color:#4338ca;}
.toast-body{flex:1;min-width:0;}
.toast-title{font-weight:700;font-size:13.5px;color:#111827;margin-bottom:2px;display:flex;align-items:center;gap:6px;}
.toast-msg{font-size:12.5px;color:#4b5563;line-height:1.45;}
.toast-close{background:none;border:none;color:#c1c5cc;cursor:pointer;font-size:12px;padding:3px;flex-shrink:0;border-radius:5px;}
.toast-close:hover{color:#111827;background:#f3f4f6;}
@media(max-width:768px){
    .stats-row{grid-template-columns:1fr}
    .modal-box{max-width:100%;max-height:90vh;}
    .modal-header,.modal-subheader,.modal-search,.modal-list{padding-left:16px;padding-right:16px;}
    .toast-container{left:12px;right:12px;top:12px;max-width:none;}
}
</style>

<script>
const RECONCILE_IMPORT_ID = <?php echo $import_id; ?>;
<?php
// Net S/E per SKU (same values as "Net Excess / Short" column) for the preview tally
$net_map = [];
foreach ($se_rows as $r) {
    $net_map[strtoupper(trim($r['sku_code']))] = [
        'net'      => round(floatval($r['net_se']), 2),
        'sku_code' => $r['sku_code'],
        'sku_desc' => $r['sku_desc'],
    ];
}
?>
const SYSTEM_NET_SE = <?php
    $net_json = json_encode($net_map ?: new stdClass(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS);
    echo ($net_json !== false && $net_json !== '') ? $net_json : '{}';
?>;
const IMPORT_DELIVERY_DATE = '<?php echo !empty($import['delivery_date']) ? date('Y-m-d', strtotime($import['delivery_date'])) : ''; ?>';
let currentPreview = [];
let manualMatchSysId = null;

/* ---------------- Toast Notifications ---------------- */
function showToast(message, type = 'success', title = null){
    const container = document.getElementById('toastContainer');
    if (!container) { console.log(message); return; }

    const icons = { success: 'fa-circle-check', error: 'fa-triangle-exclamation', info: 'fa-circle-info' };
    const titles = { success: '✨ All set!', error: 'Something went wrong', info: 'Heads up' };

    const el = document.createElement('div');
    el.className = `toast toast-${type}`;
    el.innerHTML = `
        <div class="toast-icon"><i class="fa-solid ${icons[type] || icons.info}"></i></div>
        <div class="toast-body">
            <div class="toast-title">${title || titles[type] || titles.info}</div>
            <div class="toast-msg">${message}</div>
        </div>
        <button type="button" class="toast-close" onclick="this.closest('.toast').remove()">
            <i class="fa-solid fa-xmark"></i>
        </button>`;
    container.appendChild(el);

    const remove = () => {
        el.classList.add('toast-leaving');
        setTimeout(() => el.remove(), 280);
    };
    setTimeout(remove, 4000);
}

function tallyBadge(status){
    const map = {matched:'Matched',mismatch:'Mismatch',not_in_excel:'Not in Excel',not_in_system:'Not in System',manual:'Manual Match',damage_cleared:'Damage Cleared (IKEA)'};
    const icon = {matched:'fa-circle-check',mismatch:'fa-triangle-exclamation',not_in_excel:'fa-file-excel',not_in_system:'fa-database',manual:'fa-link',damage_cleared:'fa-shield-halved'};
    return `<span class="tally-badge tally-${status}"><i class="fa-solid ${icon[status]||'fa-question'}"></i> ${map[status]||status}</span>`;
}
function fmtQty(v){ return (v===null||v===undefined||v==='') ? '<span class="dash">—</span>' : Number(v).toFixed(2); }
function esc(v){ return (v===null||v===undefined) ? '' : String(v); }

async function previewReconcile(){
    const fileInput = document.getElementById('excelFile');
    if (!fileInput.files.length){ showToast('Please choose the LeverEDGE .xlsx file first.', 'error'); return; }
    const spinner = document.getElementById('previewSpinner');
    spinner.style.display = 'inline';
    try{
        const fd = new FormData();
        fd.append('excel_file', fileInput.files[0]);
        fd.append('import_id', RECONCILE_IMPORT_ID);
        const res = await fetch('api_reconcile.php?action=preview', { method:'POST', body: fd });
        const data = await res.json();
        if (!data.success){ showToast(data.message || 'Failed to parse excel', 'error'); return; }
        if (!data.preview.length){ showToast(data.message || 'No matching delivery short/excess rows found in this file.', 'info'); return; }
        // Replace per-person System S/E with the final NET S/E per SKU, then re-tally
        const merged = applyNetSystemSe(data.preview);
        if (!merged.length){ showToast('No short/excess rows to tally after netting per SKU.', 'info'); return; }
        // Tag each row with a stable id + consumed flag so manual matching survives re-renders
        currentPreview = merged.map((r, i) => ({ ...r, _id: i, consumed: false }));
        document.getElementById('previewFilename').textContent = data.filename;
        renderPreview();
        document.getElementById('previewSection').style.display = 'block';
    } catch(e){
        showToast('Error: ' + e.message, 'error');
    } finally {
        spinner.style.display = 'none';
    }
}

/*
 * Collapse the API preview so every SKU the system knows about appears ONCE,
 * with System S/E = final NET S/E (sum of all delivery persons' S/E for that SKU —
 * exactly the "Net Excess / Short" value in the Short / Excess Items table).
 * Excel qty for that SKU = sum of its distinct Excel rows. Status is re-tallied:
 *   matched      -> |net S/E| equals |Excel qty|
 *   mismatch     -> both present but different (incl. net 0 with an Excel row)
 *   not_in_excel -> net S/E ≠ 0 but no Excel row
 * SKUs whose net is 0 and have no Excel row are dropped (nothing to reconcile).
 * Excel-only SKUs (not in system) are left exactly as the API returned them.
 */
function applyNetSystemSe(preview){
    const key = s => String(s || '').trim().toUpperCase();
    const groups = new Map();
    const order  = [];

    preview.forEach(r => {
        const k = key(r.sku_code);
        if (!SYSTEM_NET_SE[k]) { order.push({ single: r }); return; }
        if (!groups.has(k)) { groups.set(k, []); order.push({ key: k }); }
        groups.get(k).push(r);
    });

    // System SKUs with S/E that the API didn't return at all → add as "Not in Excel"
    Object.keys(SYSTEM_NET_SE).forEach(k => {
        if (!groups.has(k)) { groups.set(k, []); order.push({ key: k }); }
    });

    const out = [];
    order.forEach(o => {
        if (o.single) { out.push(o.single); return; }

        const rows = groups.get(o.key);
        const sys  = SYSTEM_NET_SE[o.key];
        const net  = Math.round(Number(sys.net) * 100) / 100;

        // Excel total: distinct Excel rows only (same Excel row matched to several persons counts once)
        const seen = new Set();
        let excelTotal = null;
        rows.forEach(r => {
            if (r.excel_qty === null || r.excel_qty === undefined || r.excel_qty === '') return;
            const q = Number(r.excel_qty);
            if (isNaN(q)) return;
            const sig = (r.delivery_date || '') + '|' + q.toFixed(4);
            if (seen.has(sig)) return;
            seen.add(sig);
            excelTotal = (excelTotal || 0) + q;
        });

        if (Math.abs(net) < 0.005 && excelTotal === null) return;

        let status;
        if (excelTotal === null) status = 'not_in_excel';
        else status = Math.abs(Math.abs(net) - Math.abs(excelTotal)) < 0.005 ? 'matched' : 'mismatch';

        const base = rows.find(r => r.match_status !== 'not_in_system') || rows[0] || {};
        out.push({
            ...base,
            sku_code:      base.sku_code || sys.sku_code,
            sku_desc:      base.sku_desc || sys.sku_desc,
            delivery_date: base.delivery_date || IMPORT_DELIVERY_DATE,
            system_qty:    net,
            excel_qty:     excelTotal === null ? null : Math.round(excelTotal * 100) / 100,
            match_status:  status
        });
    });
    return out;
}

function renderPreview(){
    const tbody = document.getElementById('previewBody');
    tbody.innerHTML = currentPreview.map(r => {
        const isConsumed = !!r.consumed;
        const checkedAttr = isConsumed ? '' : 'checked';
        const disabledAttr = isConsumed ? 'disabled' : '';

        let statusExtra = '';
        if (r.match_status === 'manual') {
            statusExtra = `<div class="manual-note"><i class="fa-solid fa-link"></i> Manually matched with <b>${esc(r.manual_matched_sku)}</b> — ${esc(r.manual_matched_desc)} (Excel Qty: ${fmtQty(r.manual_matched_qty)})</div>
                <button type="button" class="undo-link" onclick="resetRowStatus(${r._id})"><i class="fa-solid fa-rotate-left"></i> Undo</button>`;
        } else if (r.match_status === 'damage_cleared') {
            statusExtra = `<div class="ikea-note"><i class="fa-solid fa-shield-halved"></i> Marked damage cleared in IKEA${r.damage_cleared_ref ? (' &middot; Ref: <b>' + esc(r.damage_cleared_ref) + '</b>') : ''}</div>
                <button type="button" class="undo-link" onclick="resetRowStatus(${r._id})"><i class="fa-solid fa-rotate-left"></i> Undo</button>`;
        } else if (isConsumed) {
            statusExtra = `<div class="manual-note-used"><i class="fa-solid fa-check"></i> Used in manual match with <b>${esc(r.consumed_by_sku)}</b></div>`;
        }

        let actionCell = '';
        if (r.match_status === 'not_in_excel' && !isConsumed) {
            actionCell = `<div class="action-cell">
                <button type="button" class="btn btn-secondary btn-xs" onclick="openManualMatch(${r._id})">
                    <i class="fa-solid fa-plus"></i> Add
                </button>
                <button type="button" class="btn btn-ikea btn-xs" onclick="markDamageCleared(${r._id})">
                    <i class="fa-solid fa-shield-halved"></i> Mark Damage Cleared (IKEA)
                </button>
            </div>`;
        } else if (r.match_status === 'mismatch' && !isConsumed) {
            actionCell = `<div class="action-cell">
                <button type="button" class="btn btn-ikea btn-xs" onclick="markDamageCleared(${r._id})">
                    <i class="fa-solid fa-shield-halved"></i> Mark Damage Cleared (IKEA)
                </button>
            </div>`;
        }

        return `
        <tr class="${isConsumed ? 'consumed-row' : ''}">
            <td><input type="checkbox" class="prevChk" data-id="${r._id}" ${checkedAttr} ${disabledAttr}></td>
            <td style="font-weight:600;">${esc(r.sku_code)}</td>
            <td>${esc(r.sku_desc)}</td>
            <td>${esc(r.delivery_date)}</td>
            <td style="text-align:right;">${fmtQty(r.system_qty)}</td>
            <td style="text-align:right;">${fmtQty(r.excel_qty)}</td>
            <td>${tallyBadge(r.match_status)}${statusExtra}</td>
            <td>${actionCell}</td>
        </tr>`;
    }).join('');
}

function toggleAllPreview(el){
    document.querySelectorAll('.prevChk:not(:disabled)').forEach(c => c.checked = el.checked);
}

/* ---------------- Manual Reconcile Modal ---------------- */

function openManualMatch(sysId){
    const sysItem = currentPreview.find(r => r._id === sysId);
    if (!sysItem) return;
    manualMatchSysId = sysId;
    document.getElementById('manualMatchSysInfo').innerHTML =
        `<b>${esc(sysItem.sku_code)}</b> — ${esc(sysItem.sku_desc)} &middot; Delivery: ${esc(sysItem.delivery_date)} &middot; System S/E: ${fmtQty(sysItem.system_qty)}`;
    document.getElementById('manualMatchSearch').value = '';
    renderManualCandidates('');
    document.getElementById('manualMatchModal').style.display = 'flex';
}

function closeManualMatch(){
    document.getElementById('manualMatchModal').style.display = 'none';
    manualMatchSysId = null;
}

function getManualCandidates(){
    // Excel-only rows (present in excel, no system match found) that aren't already used
    return currentPreview.filter(r => r.match_status === 'not_in_system' && !r.consumed);
}

function renderManualCandidates(filterText){
    const listEl = document.getElementById('manualMatchList');
    const q = (filterText || '').trim().toLowerCase();
    let candidates = getManualCandidates();
    if (q) {
        candidates = candidates.filter(c =>
            (c.sku_code || '').toLowerCase().includes(q) ||
            (c.sku_desc || '').toLowerCase().includes(q)
        );
    }
    if (!candidates.length){
        listEl.innerHTML = `<div class="empty-state" style="padding:30px!important;">
            <i class="fa-solid fa-inbox"></i>
            <p>No unmatched Excel-only rows available${q ? ' for that search' : ''}.</p>
        </div>`;
        return;
    }
    listEl.innerHTML = candidates.map(c => `
        <div class="manual-candidate-row">
            <div class="manual-candidate-info">
                <div style="font-weight:600;">${esc(c.sku_code)}</div>
                <div>${esc(c.sku_desc)}</div>
                <div style="color:#6b7280;">Delivery: ${esc(c.delivery_date)} &middot; Excel Qty: ${fmtQty(c.excel_qty)}</div>
            </div>
            <button type="button" class="btn btn-primary btn-xs" onclick="confirmManualMatch(${c._id})">
                <i class="fa-solid fa-link"></i> Match
            </button>
        </div>`).join('');
}

function filterManualCandidates(){
    renderManualCandidates(document.getElementById('manualMatchSearch').value);
}

function confirmManualMatch(excelId){
    const sysItem = currentPreview.find(r => r._id === manualMatchSysId);
    const excelItem = currentPreview.find(r => r._id === excelId);
    if (!sysItem || !excelItem) return;

    sysItem.excel_qty = excelItem.excel_qty;
    sysItem.match_status = 'manual';
    sysItem.manual_matched_sku = excelItem.sku_code;
    sysItem.manual_matched_desc = excelItem.sku_desc;
    sysItem.manual_matched_qty = excelItem.excel_qty;
    sysItem.manual_matched_delivery_date = excelItem.delivery_date;

    excelItem.consumed = true;
    excelItem.consumed_by_sku = sysItem.sku_code;

    closeManualMatch();
    renderPreview();
}

document.getElementById('manualMatchModal').addEventListener('click', function(e){
    if (e.target === this) closeManualMatch();
});

/* ---------------- Mark as Damage Cleared (IKEA) ---------------- */

function markDamageCleared(sysId){
    const item = currentPreview.find(r => r._id === sysId);
    if (!item) return;
    const ref = prompt('Optional: enter the IKEA damage / claim reference number (leave blank if none):', '');
    if (ref === null) return; // cancelled
    item.match_status = 'damage_cleared';
    item.damage_cleared_ref = ref.trim();
    renderPreview();
}

/* ---------------- Undo (works for manual match or damage-cleared) ---------------- */

function resetRowStatus(sysId){
    const item = currentPreview.find(r => r._id === sysId);
    if (!item) return;

    if (item.match_status === 'manual') {
        // free up the excel-only row that was consumed by this manual match
        const freed = currentPreview.find(r => r.consumed && r.consumed_by_sku === item.sku_code);
        if (freed) { freed.consumed = false; delete freed.consumed_by_sku; }
        delete item.manual_matched_sku;
        delete item.manual_matched_desc;
        delete item.manual_matched_qty;
        delete item.manual_matched_delivery_date;
        item.excel_qty = null;
    }

    if (item.match_status === 'damage_cleared') {
        delete item.damage_cleared_ref;
    }

    item.match_status = 'not_in_excel';
    renderPreview();
}

/* ---------------- Save / Load / Delete ---------------- */

async function saveReconciliation(){
    const checked = Array.from(document.querySelectorAll('.prevChk:checked'))
        .map(c => currentPreview.find(r => r._id === parseInt(c.dataset.id)))
        .filter(Boolean);
    if (!checked.length){ showToast('Select at least one row to save.', 'error'); return; }
    const filename = document.getElementById('previewFilename').textContent;
    try{
        const res = await fetch('api_reconcile.php?action=save', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ import_id: RECONCILE_IMPORT_ID, filename: filename, rows: checked })
        });
        const data = await res.json();
        if (data.success){
            const n = data.saved;
            const msg = `${n} reconciliation row${n === 1 ? '' : 's'} tucked away safely` + (data.errors ? ` &middot; ${data.errors} couldn't be saved` : '.');
            showToast(msg, data.errors ? 'info' : 'success', '🎉 Reconciliation Saved!');
            document.getElementById('previewSection').style.display = 'none';
            document.getElementById('excelFile').value = '';
            currentPreview = [];
            loadSavedReconciliations();
        } else {
            showToast(data.message || 'Save failed.', 'error');
        }
    } catch(e){ showToast('Error: ' + e.message, 'error'); }
}

async function loadSavedReconciliations(){
    const tbody = document.getElementById('savedBody');
    const clearAllBtn = document.getElementById('clearAllBtn');
    try{
        const res = await fetch('api_reconcile.php?action=list&import_id=' + RECONCILE_IMPORT_ID);
        const data = await res.json();
        if (!data.success || !data.items.length){
            tbody.innerHTML = '<tr><td colspan="9" class="empty-state" style="padding:24px!important;"><i class="fa-solid fa-inbox"></i><p>No saved reconciliations yet</p></td></tr>';
            if (clearAllBtn) clearAllBtn.style.display = 'none';
            return;
        }
        if (clearAllBtn) clearAllBtn.style.display = 'inline-flex';
        tbody.innerHTML = data.items.map(it => {
            let manualNote = '';
            if (it.match_status === 'manual') {
                manualNote = `<div class="manual-note"><i class="fa-solid fa-link"></i> Manually matched${it.manual_matched_sku ? (' with <b>' + esc(it.manual_matched_sku) + '</b>') : ''}</div>`;
            } else if (it.match_status === 'damage_cleared') {
                manualNote = `<div class="ikea-note"><i class="fa-solid fa-shield-halved"></i> Damage cleared in IKEA${it.damage_cleared_ref ? (' &middot; Ref: <b>' + esc(it.damage_cleared_ref) + '</b>') : ''}</div>`;
            }
            return `
            <tr id="savedRow-${it.id}">
                <td style="font-weight:600;">${esc(it.sku_code)}</td>
                <td>${esc(it.sku_desc)}</td>
                <td>${esc(it.delivery_date)}</td>
                <td style="text-align:right;">${fmtQty(it.system_qty)}</td>
                <td style="text-align:right;">${fmtQty(it.excel_qty)}</td>
                <td>${tallyBadge(it.match_status)}${manualNote}</td>
                <td style="font-size:12px;color:#666;">${esc(it.excel_filename)}</td>
                <td style="font-size:12px;color:#666;white-space:nowrap;">${esc(it.created_at)}</td>
                <td><button class="btn-icon-danger" title="Delete" onclick="deleteReconciliation(${it.id})"><i class="fa-solid fa-trash"></i></button></td>
            </tr>`;
        }).join('');
    } catch(e){
        tbody.innerHTML = '<tr><td colspan="9" class="empty-state">Failed to load.</td></tr>';
    }
}

async function deleteReconciliation(id){
    if (!confirm('Delete this reconciliation record?')) return;
    try{
        const res = await fetch('api_reconcile.php?action=delete', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id })
        });
        const data = await res.json();
        if (data.success){
            const row = document.getElementById('savedRow-' + id);
            if (row) row.remove();
            showToast('Reconciliation record removed.', 'success', 'Deleted');
            const remaining = document.querySelectorAll('#savedBody tr[id^="savedRow-"]').length;
            if (!remaining) loadSavedReconciliations();
        } else {
            showToast(data.message || 'Delete failed.', 'error');
        }
    } catch(e){ showToast('Error: ' + e.message, 'error'); }
}

async function deleteAllReconciliations(){
    if (!confirm('Delete ALL saved reconciliation records for this import? This cannot be undone.')) return;
    try{
        const res = await fetch('api_reconcile.php?action=delete_all', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ import_id: RECONCILE_IMPORT_ID })
        });
        const data = await res.json();
        if (data.success){
            const n = data.deleted ?? 0;
            showToast(`Cleared ${n} reconciliation record${n === 1 ? '' : 's'} for this import.`, 'success', '🧹 All Clear!');
            loadSavedReconciliations();
        } else {
            showToast(data.message || 'Failed to clear reconciliations.', 'error');
        }
    } catch(e){ showToast('Error: ' + e.message, 'error'); }
}

document.addEventListener('DOMContentLoaded', loadSavedReconciliations);
</script>

<?php include 'footer.php'; ?>