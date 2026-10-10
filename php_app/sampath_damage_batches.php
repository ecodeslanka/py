<?php
// ============================================================
//  sampath_damage_batches.php  –  Batch List + Credit Notes
// ============================================================
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── AJAX: Create credit notes ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'create_credit_notes') {
    header('Content-Type: application/json');
    $items   = json_decode($_POST['items'] ?? '[]', true);
    $batch_id = (int)($_POST['batch_id'] ?? 0);
    if (!$batch_id || empty($items)) { echo json_encode(['ok'=>false,'msg'=>'No items provided.']); exit; }

    $created = 0; $skipped = 0; $errors = [];
    foreach ($items as $it) {
        $grn_header_id = (int)($it['grn_header_id'] ?? 0);
        $customer_id   = (int)($it['customer_id'] ?? 0);
        $grn_no        = mysqli_real_escape_string($conn, $it['grn_no'] ?? '');
        $amount        = round((float)($it['amount'] ?? 0), 2);
        $branch_name   = mysqli_real_escape_string($conn, $it['branch_name'] ?? '');
        $created_by    = mysqli_real_escape_string($conn, $_SESSION['username'] ?? 'system');

        if (!$grn_header_id || !$customer_id || $amount <= 0) { $skipped++; continue; }

        // Check duplicate
        $dup = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM sampath_damage_credit_notes WHERE grn_header_id=$grn_header_id LIMIT 1"));
        if ($dup) { $skipped++; continue; }

        $ok = mysqli_query($conn, "INSERT INTO sampath_damage_credit_notes
            (batch_id, grn_header_id, customer_id, grn_no, branch_name, amount, created_by, created_at)
            VALUES ($batch_id, $grn_header_id, $customer_id, '$grn_no', '$branch_name', $amount, '$created_by', NOW())");
        if ($ok) $created++; else $errors[] = mysqli_error($conn);
    }

    echo json_encode(['ok'=>true,'created'=>$created,'skipped'=>$skipped,'errors'=>$errors]);
    exit;
}

// ── AJAX: Delete credit note(s) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_credit_notes') {
    header('Content-Type: application/json');
    $ids = json_decode($_POST['ids'] ?? '[]', true);
    $ids = array_filter(array_map('intval', $ids));
    if (empty($ids)) { echo json_encode(['ok'=>false,'msg'=>'No IDs.']); exit; }
    $id_list = implode(',', $ids);
    $ok = mysqli_query($conn, "DELETE FROM sampath_damage_credit_notes WHERE id IN ($id_list)");
    echo json_encode(['ok'=>$ok,'deleted'=>count($ids)]);
    exit;
}

// ── AJAX: Toggle credit note status ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'toggle_cn_status') {
    header('Content-Type: application/json');
    $id = (int)($_POST['cn_id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Invalid ID.']); exit; }
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM sampath_damage_credit_notes WHERE id=$id LIMIT 1"));
    if (!$row) { echo json_encode(['ok'=>false,'msg'=>'Not found.']); exit; }
    $new_status = ($row['status'] === 'used') ? 'unused' : 'used';
    $esc = mysqli_real_escape_string($conn, $new_status);
    mysqli_query($conn, "UPDATE sampath_damage_credit_notes SET status='$esc' WHERE id=$id");
    echo json_encode(['ok'=>true,'status'=>$new_status]);
    exit;
}

// ── Delete batch ─────────────────────────────────────────────
if (isset($_GET['delete_batch']) && is_numeric($_GET['delete_batch'])) {
    $bid = (int)$_GET['delete_batch'];
    mysqli_query($conn, "DELETE FROM sampath_damage_credit_notes WHERE batch_id = $bid");
    mysqli_query($conn, "DELETE FROM sampath_damage_batches WHERE id = $bid");
    header("Location: sampath_damage_batches.php?deleted=1"); exit;
}

// ── Delete single GRN ────────────────────────────────────────
if (isset($_GET['delete_grn']) && is_numeric($_GET['delete_grn'])) {
    $gid = (int)$_GET['delete_grn'];
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT batch_id FROM sampath_damage_grn_headers WHERE id=$gid"));
    $back_batch = $r ? $r['batch_id'] : null;
    mysqli_query($conn, "DELETE FROM sampath_damage_credit_notes WHERE grn_header_id=$gid");
    mysqli_query($conn, "DELETE FROM sampath_damage_grn_headers WHERE id=$gid");
    if ($back_batch) {
        $tot = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as gc, SUM(total_value) as tv, SUM(total_qty) as tq FROM sampath_damage_grn_headers WHERE batch_id=$back_batch"));
        $ti  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as ic FROM sampath_damage_grn_items WHERE grn_header_id IN (SELECT id FROM sampath_damage_grn_headers WHERE batch_id=$back_batch)"));
        $gc=$tot['gc']??0; $tv=round((float)($tot['tv']??0),2); $tq=$tot['tq']??0; $ic=$ti['ic']??0;
        mysqli_query($conn, "UPDATE sampath_damage_batches SET total_grns=$gc, total_items=$ic, total_value=$tv WHERE id=$back_batch");
        header("Location: sampath_damage_batches.php?view_batch=$back_batch&grn_deleted=1"); exit;
    }
    header("Location: sampath_damage_batches.php?grn_deleted=1"); exit;
}

// ── Fetch batches ─────────────────────────────────────────────
$batches = [];
$br = mysqli_query($conn, "SELECT * FROM sampath_damage_batches ORDER BY created_at DESC");
if ($br) while ($b = mysqli_fetch_assoc($br)) $batches[] = $b;

// ── View single batch ─────────────────────────────────────────
$view_batch = null; $grns = []; $credit_notes = [];
if (isset($_GET['view_batch']) && is_numeric($_GET['view_batch'])) {
    $vbid = (int)$_GET['view_batch'];
    $vr = mysqli_query($conn, "SELECT * FROM sampath_damage_batches WHERE id=$vbid");
    if ($vr) $view_batch = mysqli_fetch_assoc($vr);
    if ($view_batch) {
        // Fetch GRNs
        $gr = mysqli_query($conn, "SELECT h.*, c.shop_name AS branch_name, c.id AS matched_customer_id
            FROM sampath_damage_grn_headers h
            LEFT JOIN customers c ON c.id = h.branch_id
            WHERE h.batch_id = $vbid ORDER BY h.grn_date DESC, h.grn_no");
        if ($gr) while ($row = mysqli_fetch_assoc($gr)) {
            $ir = mysqli_query($conn, "SELECT * FROM sampath_damage_grn_items WHERE grn_header_id = {$row['id']} ORDER BY line_no");
            $row['items'] = [];
            if ($ir) while ($item = mysqli_fetch_assoc($ir)) $row['items'][] = $item;
            $grns[] = $row;
        }
        // Fetch credit notes for this batch
        $cnr = mysqli_query($conn, "SELECT cn.*, c.shop_name AS customer_name
            FROM sampath_damage_credit_notes cn
            LEFT JOIN customers c ON c.id = cn.customer_id
            WHERE cn.batch_id = $vbid ORDER BY cn.created_at DESC");
        if ($cnr) while ($cn = mysqli_fetch_assoc($cnr)) $credit_notes[] = $cn;
    }
}

// Build a set of grn_header_ids that already have credit notes
$cn_grn_ids = array_column($credit_notes, 'grn_header_id');

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
:root{
    --ink:#0f172a;--muted:#64748b;--border:#e2e8f0;--bg:#f8fafc;
    --accent:#0ea5e9;--accent-dark:#0284c7;--success:#10b981;
    --warn:#f59e0b;--danger:#ef4444;--white:#fff;--purple:#8b5cf6;
    --radius:10px;--shadow:0 2px 12px rgba(15,23,42,.07);
}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--ink);}
.sdmg-hd{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px;flex-wrap:wrap;gap:12px;}
.sdmg-title{font-size:22px;font-weight:800;margin:0 0 3px;}
.sdmg-sub{font-size:13px;color:var(--muted);margin:0;}
.sdmg-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px;}
.sdmg-card-h{display:flex;align-items:center;gap:10px;padding:14px 20px;border-bottom:1px solid var(--border);background:#f1f5f9;font-size:14px;font-weight:700;color:var(--ink);}
.sdmg-card-b{padding:0;}
.sdmg-alert{display:flex;align-items:flex-start;gap:10px;padding:11px 16px;border-radius:8px;font-size:13px;margin-bottom:14px;}
.sdmg-alert-success{background:#dcfce7;border:1px solid #bbf7d0;color:#166534;}
.sdmg-alert-danger{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.sdmg-alert-info{background:#e0f2fe;border:1px solid #bae6fd;color:#075985;}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap;}
.btn-primary{background:var(--accent);color:#fff;}.btn-primary:hover{background:var(--accent-dark);}
.btn-ghost{background:#f1f5f9;color:var(--ink);border:1px solid var(--border);}.btn-ghost:hover{background:#e2e8f0;}
.btn-danger{background:#fee2e2;color:var(--danger);border:1px solid #fecaca;}.btn-danger:hover{background:var(--danger);color:#fff;}
.btn-purple{background:#ede9fe;color:var(--purple);border:1px solid #ddd6fe;}.btn-purple:hover{background:var(--purple);color:#fff;}
.btn-success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}.btn-success:hover{background:var(--success);color:#fff;}
.btn-sm{padding:6px 12px;font-size:12px;}
.btn:disabled{opacity:.5;cursor:not-allowed;}

/* Batch table */
.btable{width:100%;border-collapse:collapse;font-size:13px;}
.btable thead{background:#f8fafc;border-bottom:2px solid var(--border);}
.btable th{padding:10px 16px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:700;white-space:nowrap;}
.btable td{padding:12px 16px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.btable tbody tr:hover{background:#fafafa;}
.btable tbody tr:last-child td{border-bottom:none;}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-blue{background:#dbeafe;color:#1e40af;}
.badge-green{background:#dcfce7;color:#166534;}
.badge-amber{background:#fef3c7;color:#92400e;}
.badge-purple{background:#ede9fe;color:#6d28d9;}
.badge-red{background:#fee2e2;color:#991b1b;}
.badge-used{background:#dcfce7;color:#166534;}
.badge-unused{background:#fef3c7;color:#92400e;}

/* Status toggle button */
.status-toggle{display:inline-flex;align-items:center;gap:5px;padding:4px 11px;border-radius:20px;font-size:11px;font-weight:700;cursor:pointer;border:none;font-family:inherit;transition:all .18s;}
.status-toggle.used{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
.status-toggle.used:hover{background:#bbf7d0;}
.status-toggle.unused{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.status-toggle.unused:hover{background:#fde68a;}

/* GRN card view */
.grn-card{border:1px solid var(--border);border-radius:8px;margin-bottom:10px;overflow:hidden;}
.grn-card-h{display:flex;align-items:center;justify-content:space-between;padding:11px 16px;background:#fafafa;cursor:pointer;gap:10px;flex-wrap:wrap;}
.grn-card-h:hover{background:#f0f9ff;}
.grn-expand{display:none;}
.grn-expand.open{display:block;}
.items-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.items-tbl th{padding:7px 12px;background:#f1f5f9;font-size:10px;text-transform:uppercase;letter-spacing:.3px;color:var(--muted);font-weight:700;text-align:left;}
.items-tbl td{padding:7px 12px;border-top:1px solid #f1f5f9;color:var(--ink);}
.items-tbl tr:hover td{background:#f8fafc;}
.items-tbl tfoot td{background:#f1f5f9;font-weight:700;padding:7px 12px;}

/* Summary stats */
.stat-row{display:flex;gap:16px;flex-wrap:wrap;padding:14px 20px;border-bottom:1px solid var(--border);background:#fafafe;}
.stat-item{font-size:13px;color:var(--muted);}
.stat-item strong{color:var(--ink);font-weight:700;}

/* Empty */
.empty-box{text-align:center;padding:52px 20px;color:var(--muted);}
.empty-box i{font-size:44px;margin-bottom:14px;display:block;color:#cbd5e1;}

/* Back breadcrumb */
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--muted);margin-bottom:16px;}
.breadcrumb a{color:var(--accent);text-decoration:none;font-weight:600;}
.breadcrumb a:hover{text-decoration:underline;}

/* ── Credit Note Modal ── */
.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:9000;display:flex;align-items:center;justify-content:center;padding:16px;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-overlay.open{opacity:1;pointer-events:all;}
.modal-box{background:#fff;border-radius:14px;width:100%;max-width:760px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(15,23,42,.22);transform:translateY(16px);transition:transform .22s;}
.modal-overlay.open .modal-box{transform:translateY(0);}
.modal-hd{display:flex;align-items:center;justify-content:space-between;padding:16px 22px;border-bottom:1px solid var(--border);gap:12px;flex-shrink:0;}
.modal-hd h3{margin:0;font-size:16px;font-weight:800;display:flex;align-items:center;gap:8px;}
.modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:var(--muted);padding:4px 8px;border-radius:6px;line-height:1;}
.modal-close:hover{background:#f1f5f9;color:var(--ink);}
.modal-body{padding:18px 22px;overflow-y:auto;flex:1;}
.modal-ft{padding:14px 22px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;flex-shrink:0;background:#f8fafc;border-radius:0 0 14px 14px;}

/* CN Select table */
.cn-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.cn-tbl thead th{padding:9px 12px;background:#f1f5f9;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:var(--muted);font-weight:700;text-align:left;border-bottom:2px solid var(--border);}
.cn-tbl td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.cn-tbl tbody tr:hover td{background:#f0f9ff;}
.cn-tbl tbody tr.already-created td{background:#f0fdf4;color:var(--muted);}
.cn-tbl tbody tr.already-created td input[type=checkbox]{cursor:not-allowed;}
.cn-check{width:16px;height:16px;cursor:pointer;accent-color:var(--purple);}

/* CN List table */
.cn-list-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.cn-list-tbl thead th{padding:10px 14px;background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:700;text-align:left;border-bottom:2px solid var(--border);}
.cn-list-tbl td{padding:11px 14px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.cn-list-tbl tbody tr:hover td{background:#fafafa;}
.cn-list-tbl tbody tr:last-child td{border-bottom:none;}
.cn-bulk-bar{display:flex;align-items:center;gap:10px;padding:10px 16px;background:#fef9c3;border-bottom:1px solid #fde68a;font-size:13px;flex-wrap:wrap;}

/* Toast */
.toast-wrap{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px;}
.toast{padding:12px 18px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.15);display:flex;align-items:center;gap:8px;animation:toastIn .25s ease;max-width:340px;}
.toast-success{background:#166534;color:#fff;}
.toast-error{background:#991b1b;color:#fff;}
@keyframes toastIn{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}

@media(max-width:700px){
    .stat-row{flex-direction:column;gap:8px;}
    .grn-card-h{flex-direction:column;align-items:flex-start;}
    .modal-box{max-width:100%;max-height:95vh;}
}
</style>

<?php if ($view_batch): ?>
<!-- ══════════════════════════════════════════════════════
     SINGLE BATCH VIEW
════════════════════════════════════════════════════════ -->
<div class="breadcrumb">
    <a href="sampath_damage_batches.php"><i class="fa-solid fa-layer-group"></i> All Batches</a>
    <i class="fa-solid fa-chevron-right" style="font-size:10px;"></i>
    <span><?php echo htmlspecialchars($view_batch['batch_ref']); ?></span>
</div>

<?php if (isset($_GET['grn_deleted'])): ?>
<div class="sdmg-alert sdmg-alert-success"><i class="fa-solid fa-circle-check"></i> GRN deleted successfully.</div>
<?php endif; ?>

<div class="sdmg-hd">
    <div>
        <h2 class="sdmg-title"><i class="fa-solid fa-box-archive" style="color:var(--accent);"></i> <?php echo htmlspecialchars($view_batch['batch_ref']); ?></h2>
        <p class="sdmg-sub">Imported by <strong><?php echo htmlspecialchars($view_batch['imported_by']); ?></strong> on <?php echo $view_batch['import_date']; ?></p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button class="btn btn-purple" onclick="openCNModal()"><i class="fa-solid fa-file-invoice-dollar"></i> Create Credit Note</button>
        <a href="sampath_damage_import.php" class="btn btn-primary"><i class="fa-solid fa-file-import"></i> New Import</a>
        <a href="sampath_damage_batches.php?delete_batch=<?php echo $view_batch['id']; ?>"
           class="btn btn-danger"
           onclick="return confirm('Delete this entire batch and all its GRNs/items?\n\nBatch: <?php echo htmlspecialchars($view_batch['batch_ref']); ?>\n\nThis CANNOT be undone.')">
            <i class="fa-solid fa-trash"></i> Delete Batch
        </a>
    </div>
</div>

<!-- Batch summary -->
<div class="sdmg-card" style="margin-bottom:18px;">
    <div class="sdmg-card-h"><i class="fa-solid fa-chart-bar" style="color:var(--warn);"></i> Batch Summary</div>
    <div class="stat-row">
        <div class="stat-item">GRNs: <strong><?php echo $view_batch['total_grns']; ?></strong></div>
        <div class="stat-item">Items: <strong><?php echo $view_batch['total_items']; ?></strong></div>
        <div class="stat-item">Total Value: <strong style="color:var(--accent-dark);">LKR <?php echo number_format($view_batch['total_value'], 2); ?></strong></div>
        <div class="stat-item">Supplier: <strong>YELO DISTRIBUTORS (PVT) LTD</strong></div>
        <div class="stat-item">Credit Notes Created: <strong style="color:var(--purple);"><?php echo count($credit_notes); ?></strong></div>
        <?php if ($view_batch['notes']): ?>
        <div class="stat-item">Notes: <strong><?php echo htmlspecialchars($view_batch['notes']); ?></strong></div>
        <?php endif; ?>
    </div>
</div>

<!-- GRN List -->
<div class="sdmg-card">
    <div class="sdmg-card-h">
        <i class="fa-solid fa-receipt" style="color:var(--success);"></i>
        <span>GRN Details (<?php echo count($grns); ?>)</span>
        <span style="font-size:12px;font-weight:500;color:var(--muted);margin-left:auto;">Click to expand items · <a href="#" onclick="expandAll();return false;" style="color:var(--accent);">Expand All</a> / <a href="#" onclick="collapseAll();return false;" style="color:var(--muted);">Collapse</a></span>
    </div>
    <div style="padding:16px 20px;">
        <?php if (empty($grns)): ?>
        <div class="empty-box"><i class="fa-solid fa-inbox"></i><p>No GRNs in this batch.</p></div>
        <?php else: ?>
        <?php foreach ($grns as $gi => $g): ?>
        <?php $hasCN = in_array($g['id'], $cn_grn_ids); ?>
        <div class="grn-card">
            <div class="grn-card-h" onclick="toggleG(<?php echo $gi; ?>)">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <strong style="font-size:13px;"><?php echo htmlspecialchars($g['grn_no']); ?></strong>
                    <span class="badge badge-blue"><?php echo htmlspecialchars($g['grn_branch_code']); ?></span>
                    <?php if ($g['branch_name']): ?>
                    <span class="badge badge-green" style="font-size:10px;"><?php echo htmlspecialchars($g['branch_name']); ?></span>
                    <?php else: ?>
                    <span class="badge" style="background:#fee2e2;color:#991b1b;font-size:10px;"><i class="fa-solid fa-unlink"></i> No match</span>
                    <?php endif; ?>
                    <span style="font-size:12px;color:var(--muted);"><?php echo $g['grn_date']; ?></span>
                    <?php if ($hasCN): ?>
                    <span class="badge badge-purple"><i class="fa-solid fa-file-invoice-dollar"></i> CN Created</span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <span class="badge badge-green"><?php echo count($g['items']); ?> items</span>
                    <span class="badge badge-amber">LKR <?php echo number_format($g['total_value'], 2); ?></span>
                    <a href="sampath_damage_batches.php?delete_grn=<?php echo $g['id']; ?>&view_batch=<?php echo $view_batch['id']; ?>"
                       class="btn btn-danger btn-sm"
                       onclick="event.stopPropagation();return confirm('Delete GRN <?php echo htmlspecialchars($g['grn_no']); ?>?\nThis will also delete all its line items and any credit note linked to it.');">
                        <i class="fa-solid fa-trash"></i>
                    </a>
                    <i class="fa-solid fa-chevron-down" id="ch<?php echo $gi; ?>" style="color:var(--muted);font-size:11px;transition:transform .2s;"></i>
                </div>
            </div>
            <div class="grn-expand" id="ge-<?php echo $gi; ?>">
                <table class="items-tbl">
                    <thead><tr>
                        <th>#</th><th>Product Code</th><th>Product Name</th>
                        <th>Location</th>
                        <th style="text-align:right;">Unit Price</th>
                        <th style="text-align:right;">Qty</th>
                        <th style="text-align:right;">Total</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($g['items'] as $item): ?>
                    <tr>
                        <td><?php echo $item['line_no']; ?></td>
                        <td><code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:11px;"><?php echo htmlspecialchars($item['product_code']); ?></code></td>
                        <td style="max-width:280px;"><?php echo htmlspecialchars($item['product_name']); ?></td>
                        <td><span style="font-size:11px;color:var(--muted);"><?php echo htmlspecialchars($item['location']); ?></span></td>
                        <td style="text-align:right;"><?php echo number_format($item['unit_price'], 2); ?></td>
                        <td style="text-align:right;font-weight:700;"><?php echo $item['qty']; ?></td>
                        <td style="text-align:right;font-weight:700;color:var(--accent-dark);"><?php echo number_format($item['line_total'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr>
                        <td colspan="5">Total</td>
                        <td style="text-align:right;"><?php echo $g['total_qty']; ?></td>
                        <td style="text-align:right;">LKR <?php echo number_format($g['total_value'], 2); ?></td>
                    </tr></tfoot>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── Credit Note List ── -->
<div class="sdmg-card" id="cn-list-card">
    <div class="sdmg-card-h">
        <i class="fa-solid fa-file-invoice-dollar" style="color:var(--purple);"></i>
        <span>Credit Notes</span>
        <span class="badge badge-purple" style="margin-left:4px;" id="cn-count-badge"><?php echo count($credit_notes); ?></span>
        <?php if (!empty($credit_notes)): ?>
        <span style="margin-left:auto;display:flex;gap:8px;align-items:center;">
            <label style="font-size:12px;font-weight:500;color:var(--muted);cursor:pointer;display:flex;align-items:center;gap:5px;">
                <input type="checkbox" id="cn-select-all" onchange="cnToggleAll(this)" style="cursor:pointer;accent-color:var(--danger);"> Select All
            </label>
            <button class="btn btn-danger btn-sm" id="cn-bulk-del-btn" onclick="cnBulkDelete()" style="display:none;">
                <i class="fa-solid fa-trash"></i> Delete Selected
            </button>
        </span>
        <?php endif; ?>
    </div>
    <div id="cn-list-wrap">
    <?php if (empty($credit_notes)): ?>
    <div class="empty-box" id="cn-empty-box">
        <i class="fa-solid fa-file-circle-xmark" style="color:#cbd5e1;"></i>
        <p style="margin:8px 0 0;">No credit notes created yet for this batch.</p>
        <p style="margin:6px 0 0;font-size:12px;">Use the <strong>Create Credit Note</strong> button above.</p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="cn-list-tbl" id="cn-list-tbl">
        <thead><tr>
            <th style="width:36px;"><input type="checkbox" id="cn-select-all-tbl" onchange="cnToggleAll(this)" style="cursor:pointer;accent-color:var(--danger);"></th>
            <th>#</th>
            <th>GRN No</th>
            <th>Outlet / Customer</th>
            <th>Customer ID</th>
            <th style="text-align:right;">Credit Amount (LKR)</th>
            <th style="text-align:center;">Status</th>
            <th>Created By</th>
            <th>Created At</th>
            <th style="text-align:center;">Action</th>
        </tr></thead>
        <tbody id="cn-tbody">
        <?php foreach ($credit_notes as $ci => $cn): ?>
        <tr id="cn-row-<?php echo $cn['id']; ?>">
            <td><input type="checkbox" class="cn-row-check" value="<?php echo $cn['id']; ?>" onchange="cnCheckChanged()" style="cursor:pointer;accent-color:var(--danger);"></td>
            <td style="color:var(--muted);font-size:12px;"><?php echo $ci+1; ?></td>
            <td><span class="badge badge-blue"><?php echo htmlspecialchars($cn['grn_no']); ?></span></td>
            <td>
                <div style="font-weight:600;"><?php echo htmlspecialchars($cn['customer_name'] ?? $cn['branch_name'] ?? '—'); ?></div>
            </td>
            <td style="font-size:12px;color:var(--muted);">#<?php echo $cn['customer_id']; ?></td>
            <td style="text-align:right;font-weight:700;color:var(--purple);"><?php echo number_format($cn['amount'], 2); ?></td>
            <td style="text-align:center;">
                <?php $st = $cn['status'] ?? 'unused'; ?>
                <button class="status-toggle <?php echo $st; ?>" id="st-btn-<?php echo $cn['id']; ?>"
                    onclick="toggleCNStatus(<?php echo $cn['id']; ?>)"
                    title="Click to toggle status">
                    <?php if ($st === 'used'): ?>
                        <i class="fa-solid fa-circle-check"></i> Used
                    <?php else: ?>
                        <i class="fa-solid fa-circle"></i> Unused
                    <?php endif; ?>
                </button>
            </td>
            <td style="font-size:12px;"><?php echo htmlspecialchars($cn['created_by']); ?></td>
            <td style="font-size:12px;color:var(--muted);"><?php echo $cn['created_at']; ?></td>
            <td style="text-align:center;">
                <button class="btn btn-danger btn-sm" onclick="cnDeleteSingle(<?php echo $cn['id']; ?>,'<?php echo htmlspecialchars($cn['grn_no']); ?>')">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr>
            <td colspan="5" style="font-weight:700;padding:10px 14px;background:#f8fafc;font-size:12px;text-transform:uppercase;letter-spacing:.3px;color:var(--muted);">Total</td>
            <td style="text-align:right;font-weight:800;color:var(--purple);background:#f8fafc;padding:10px 14px;" id="cn-total-cell">
                LKR <?php echo number_format(array_sum(array_column($credit_notes,'amount')),2); ?>
            </td>
            <td colspan="4" style="background:#f8fafc;"></td>
        </tr>
        </tfoot>
    </table>
    </div>
    <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     CREATE CREDIT NOTE MODAL
════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="cn-modal">
    <div class="modal-box">
        <div class="modal-hd">
            <h3><i class="fa-solid fa-file-invoice-dollar" style="color:var(--purple);"></i> Create Credit Notes</h3>
            <button class="modal-close" onclick="closeCNModal()">×</button>
        </div>
        <div class="modal-body">
            <div class="sdmg-alert sdmg-alert-info" style="margin-bottom:14px;">
                <i class="fa-solid fa-circle-info"></i>
                <div>Select the outlets (GRNs) you want to create credit notes for. Each credit note will be linked to the matched customer with the GRN amount as the credit value. Rows marked <span class="badge badge-purple" style="font-size:10px;"><i class="fa-solid fa-check"></i> CN Created</span> already have a credit note.</div>
            </div>
            <table class="cn-tbl">
                <thead>
                    <tr>
                        <th style="width:36px;"><input type="checkbox" id="cn-modal-all" onchange="cnModalToggleAll(this)" style="cursor:pointer;accent-color:var(--purple);"></th>
                        <th>GRN No</th>
                        <th>Outlet / Branch</th>
                        <th>Customer ID</th>
                        <th style="text-align:right;">Amount (LKR)</th>
                        <th style="text-align:center;">Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($grns as $g):
                    $hasCN = in_array($g['id'], $cn_grn_ids);
                    $hasMatch = !empty($g['matched_customer_id']);
                ?>
                <tr class="<?php echo $hasCN ? 'already-created' : ''; ?>" data-grn-id="<?php echo $g['id']; ?>">
                    <td>
                        <input type="checkbox" class="cn-check modal-row-check"
                            <?php echo ($hasCN || !$hasMatch) ? 'disabled' : ''; ?>
                            data-grn-id="<?php echo $g['id']; ?>"
                            data-grn-no="<?php echo htmlspecialchars($g['grn_no']); ?>"
                            data-customer-id="<?php echo (int)($g['matched_customer_id'] ?? 0); ?>"
                            data-branch-name="<?php echo htmlspecialchars($g['branch_name'] ?? ''); ?>"
                            data-amount="<?php echo $g['total_value']; ?>"
                            onchange="cnModalSumUpdate()">
                    </td>
                    <td><span class="badge badge-blue"><?php echo htmlspecialchars($g['grn_no']); ?></span></td>
                    <td>
                        <?php if ($g['branch_name']): ?>
                            <strong><?php echo htmlspecialchars($g['branch_name']); ?></strong>
                        <?php else: ?>
                            <span style="color:#ef4444;font-size:12px;"><i class="fa-solid fa-unlink"></i> No customer match</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:var(--muted);">
                        <?php echo $hasMatch ? '#'.(int)$g['matched_customer_id'] : '—'; ?>
                    </td>
                    <td style="text-align:right;font-weight:700;"><?php echo number_format($g['total_value'],2); ?></td>
                    <td style="text-align:center;">
                        <?php if ($hasCN): ?>
                            <span class="badge badge-purple"><i class="fa-solid fa-check"></i> CN Created</span>
                        <?php elseif (!$hasMatch): ?>
                            <span class="badge badge-red"><i class="fa-solid fa-ban"></i> No Customer</span>
                        <?php else: ?>
                            <span class="badge badge-green"><i class="fa-solid fa-circle"></i> Ready</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="modal-ft">
            <div style="font-size:13px;color:var(--muted);">
                Selected: <strong id="cn-sel-count">0</strong> GRN(s) &nbsp;|&nbsp;
                Total: <strong id="cn-sel-total" style="color:var(--purple);">LKR 0.00</strong>
            </div>
            <div style="display:flex;gap:8px;">
                <button class="btn btn-ghost" onclick="closeCNModal()">Cancel</button>
                <button class="btn btn-purple" id="cn-save-btn" onclick="saveCreditNotes()" disabled>
                    <i class="fa-solid fa-floppy-disk"></i> Save Credit Notes
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Toast container -->
<div class="toast-wrap" id="toast-wrap"></div>

<script>
const BATCH_ID = <?php echo $vbid; ?>;

// ── GRN accordion ─────────────────────────────────────────────
function toggleG(i) {
    const el = document.getElementById('ge-'+i);
    const ch = document.getElementById('ch'+i);
    const open = el.classList.toggle('open');
    ch.style.transform = open ? 'rotate(180deg)' : '';
}
function expandAll() {
    document.querySelectorAll('.grn-expand').forEach(el => el.classList.add('open'));
    document.querySelectorAll('[id^="ch"]').forEach(ch => ch.style.transform = 'rotate(180deg)');
}
function collapseAll() {
    document.querySelectorAll('.grn-expand').forEach(el => el.classList.remove('open'));
    document.querySelectorAll('[id^="ch"]').forEach(ch => ch.style.transform = '');
}

// ── Credit Note Modal ─────────────────────────────────────────
function openCNModal() {
    document.getElementById('cn-modal').classList.add('open');
}
function closeCNModal() {
    document.getElementById('cn-modal').classList.remove('open');
}

function cnModalToggleAll(cb) {
    document.querySelectorAll('.modal-row-check:not(:disabled)').forEach(c => {
        c.checked = cb.checked;
    });
    cnModalSumUpdate();
}

function cnModalSumUpdate() {
    const checks = document.querySelectorAll('.modal-row-check:checked');
    let total = 0, count = 0;
    checks.forEach(c => { total += parseFloat(c.dataset.amount)||0; count++; });
    document.getElementById('cn-sel-count').textContent = count;
    document.getElementById('cn-sel-total').textContent = 'LKR ' + total.toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('cn-save-btn').disabled = count === 0;
    // sync all-check state
    const allChecks = document.querySelectorAll('.modal-row-check:not(:disabled)');
    const allModal  = document.getElementById('cn-modal-all');
    if (allModal) allModal.checked = allChecks.length > 0 && [...allChecks].every(c=>c.checked);
}

async function saveCreditNotes() {
    const checks = document.querySelectorAll('.modal-row-check:checked');
    if (!checks.length) return;
    const items = [];
    checks.forEach(c => {
        items.push({
            grn_header_id: c.dataset.grnId,
            grn_no:        c.dataset.grnNo,
            customer_id:   c.dataset.customerId,
            branch_name:   c.dataset.branchName,
            amount:        c.dataset.amount
        });
    });

    const btn = document.getElementById('cn-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    try {
        const fd = new FormData();
        fd.append('ajax_action','create_credit_notes');
        fd.append('batch_id', BATCH_ID);
        fd.append('items', JSON.stringify(items));
        const res  = await fetch(location.pathname, {method:'POST', body:fd});
        const data = await res.json();
        if (data.ok) {
            closeCNModal();
            showToast(`✓ ${data.created} credit note(s) created${data.skipped ? `, ${data.skipped} skipped (already exist)` : ''}.`, 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast('Error: ' + (data.msg||'Unknown error'), 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Credit Notes';
        }
    } catch(e) {
        showToast('Network error. Please try again.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Credit Notes';
    }
}

// ── CN List ───────────────────────────────────────────────────
function cnToggleAll(cb) {
    document.querySelectorAll('.cn-row-check').forEach(c => c.checked = cb.checked);
    // sync both checkboxes
    const all1 = document.getElementById('cn-select-all');
    const all2 = document.getElementById('cn-select-all-tbl');
    if(all1) all1.checked = cb.checked;
    if(all2) all2.checked = cb.checked;
    cnCheckChanged();
}

function cnCheckChanged() {
    const any = [...document.querySelectorAll('.cn-row-check')].some(c=>c.checked);
    const btn = document.getElementById('cn-bulk-del-btn');
    if (btn) btn.style.display = any ? '' : 'none';
}

async function cnDeleteSingle(id, grnNo) {
    if (!confirm(`Delete credit note for GRN ${grnNo}?\n\nThis cannot be undone.`)) return;
    await deleteCNs([id]);
}

async function cnBulkDelete() {
    const ids = [...document.querySelectorAll('.cn-row-check:checked')].map(c=>c.value);
    if (!ids.length) return;
    if (!confirm(`Delete ${ids.length} selected credit note(s)?\n\nThis cannot be undone.`)) return;
    await deleteCNs(ids);
}

async function deleteCNs(ids) {
    try {
        const fd = new FormData();
        fd.append('ajax_action','delete_credit_notes');
        fd.append('ids', JSON.stringify(ids));
        const res  = await fetch(location.pathname, {method:'POST', body:fd});
        const data = await res.json();
        if (data.ok) {
            ids.forEach(id => {
                const row = document.getElementById('cn-row-'+id);
                if (row) row.remove();
            });
            // Update badge count
            const remaining = document.querySelectorAll('.cn-row-check').length;
            const badge = document.getElementById('cn-count-badge');
            if (badge) badge.textContent = remaining;
            // If none left, show empty box
            if (remaining === 0) {
                document.getElementById('cn-list-wrap').innerHTML =
                    `<div class="empty-box" id="cn-empty-box"><i class="fa-solid fa-file-circle-xmark" style="color:#cbd5e1;"></i><p style="margin:8px 0 0;">All credit notes deleted.</p></div>`;
            }
            // Update bulk delete btn
            const bulkBtn = document.getElementById('cn-bulk-del-btn');
            if (bulkBtn) bulkBtn.style.display='none';
            showToast(`✓ ${data.deleted} credit note(s) deleted.`, 'success');
        } else {
            showToast('Error deleting. Please try again.', 'error');
        }
    } catch(e) {
        showToast('Network error. Please try again.', 'error');
    }
}

// ── Toast ─────────────────────────────────────────────────────
function showToast(msg, type='success') {
    const wrap = document.getElementById('toast-wrap');
    const t = document.createElement('div');
    t.className = `toast toast-${type}`;
    t.innerHTML = `<i class="fa-solid fa-${type==='success'?'circle-check':'circle-exclamation'}"></i> ${msg}`;
    wrap.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

// ── Toggle CN Status ──────────────────────────────────────────
async function toggleCNStatus(id) {
    const btn = document.getElementById('st-btn-'+id);
    if (!btn) return;
    const origHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

    try {
        const fd = new FormData();
        fd.append('ajax_action','toggle_cn_status');
        fd.append('cn_id', id);
        const res  = await fetch(location.pathname, {method:'POST', body:fd});
        const data = await res.json();
        if (data.ok) {
            const s = data.status;
            btn.className = 'status-toggle ' + s;
            btn.innerHTML = s === 'used'
                ? '<i class="fa-solid fa-circle-check"></i> Used'
                : '<i class="fa-solid fa-circle"></i> Unused';
            btn.disabled = false;
            showToast(`Status updated to "${s}".`, 'success');
        } else {
            btn.innerHTML = origHTML;
            btn.disabled = false;
            showToast('Failed to update status.', 'error');
        }
    } catch(e) {
        btn.innerHTML = origHTML;
        btn.disabled = false;
        showToast('Network error.', 'error');
    }
}

// Close modal on backdrop click
document.getElementById('cn-modal').addEventListener('click', function(e) {
    if (e.target === this) closeCNModal();
});
</script>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════
     BATCH LIST
════════════════════════════════════════════════════════ -->
<div class="sdmg-hd">
    <div>
        <h2 class="sdmg-title"><i class="fa-solid fa-layer-group" style="color:var(--accent);"></i> Damage Return Batches</h2>
        <p class="sdmg-sub">All imported Sampath damage return GRN batches</p>
    </div>
    <a href="sampath_damage_import.php" class="btn btn-primary"><i class="fa-solid fa-file-import"></i> New Import</a>
</div>

<?php if (isset($_GET['imported'])): ?>
<div class="sdmg-alert sdmg-alert-success"><i class="fa-solid fa-circle-check"></i> Batch <strong><?php echo htmlspecialchars($_GET['batch']??''); ?></strong> imported successfully.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
<div class="sdmg-alert sdmg-alert-danger"><i class="fa-solid fa-trash"></i> Batch deleted successfully.</div>
<?php endif; ?>

<div class="sdmg-card">
    <div class="sdmg-card-h"><i class="fa-solid fa-table" style="color:var(--success);"></i> <span>All Batches</span> <span class="badge badge-blue" style="margin-left:6px;"><?php echo count($batches); ?></span></div>
    <div class="sdmg-card-b">
    <?php if (empty($batches)): ?>
    <div class="empty-box">
        <i class="fa-solid fa-box-open"></i>
        <h3 style="margin:0 0 8px;color:#374151;">No batches imported yet</h3>
        <p style="margin:0 0 16px;font-size:13px;">Import a damage return Excel file to get started.</p>
        <a href="sampath_damage_import.php" class="btn btn-primary"><i class="fa-solid fa-file-import"></i> Import Now</a>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="btable">
        <thead><tr>
            <th>Batch Ref</th>
            <th>Import Date</th>
            <th>Imported By</th>
            <th style="text-align:right;">GRNs</th>
            <th style="text-align:right;">Items</th>
            <th style="text-align:right;">Total Value (LKR)</th>
            <th>Notes</th>
            <th style="text-align:center;">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($batches as $b): ?>
        <tr>
            <td>
                <a href="sampath_damage_batches.php?view_batch=<?php echo $b['id']; ?>" style="font-weight:700;color:var(--accent);text-decoration:none;">
                    <?php echo htmlspecialchars($b['batch_ref']); ?>
                </a>
            </td>
            <td><?php echo $b['import_date']; ?></td>
            <td><?php echo htmlspecialchars($b['imported_by']); ?></td>
            <td style="text-align:right;"><span class="badge badge-blue"><?php echo $b['total_grns']; ?></span></td>
            <td style="text-align:right;"><span class="badge badge-green"><?php echo $b['total_items']; ?></span></td>
            <td style="text-align:right;font-weight:700;color:var(--accent-dark);"><?php echo number_format($b['total_value'], 2); ?></td>
            <td style="max-width:160px;font-size:12px;color:var(--muted);"><?php echo htmlspecialchars($b['notes'] ?? '—'); ?></td>
            <td>
                <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                    <a href="sampath_damage_batches.php?view_batch=<?php echo $b['id']; ?>" class="btn btn-ghost btn-sm"><i class="fa-solid fa-eye"></i> View</a>
                    <a href="sampath_damage_batches.php?delete_batch=<?php echo $b['id']; ?>"
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('Delete batch <?php echo htmlspecialchars($b['batch_ref']); ?> and ALL its GRNs and items?\n\nThis cannot be undone.')">
                        <i class="fa-solid fa-trash"></i>
                    </a>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    </div>
</div>

<script>
function toggleG(i){}
function expandAll(){}
function collapseAll(){}
</script>
<?php endif; ?>

<?php include 'footer.php'; ?>