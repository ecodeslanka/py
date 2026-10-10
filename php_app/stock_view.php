<?php
include 'config.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: stock_list.php'); exit; }

// ── AJAX ──────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    if ($_GET['action'] === 'delete_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $item_id = intval($_POST['item_id'] ?? 0);
        $ok = mysqli_query($conn, "DELETE FROM stock_upload_items WHERE id=$item_id AND upload_id=$id");
        if ($ok) {
            mysqli_query($conn, "UPDATE stock_uploads SET
                total_rows  =(SELECT COUNT(*)              FROM stock_upload_items WHERE upload_id=$id),
                total_units =(SELECT COALESCE(SUM(units),0) FROM stock_upload_items WHERE upload_id=$id),
                total_value =(SELECT COALESCE(SUM(cur_stk_value),0) FROM stock_upload_items WHERE upload_id=$id)
                WHERE id=$id");
        }
        echo json_encode(['success'=>(bool)$ok,'message'=>$ok?'':mysqli_error($conn)]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

// ── FETCH HEADER ──────────────────────────────────────────────────
$upload = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM stock_uploads WHERE id=$id LIMIT 1"));
if (!$upload) { header('Location: stock_list.php'); exit; }

// ── FILTERS ───────────────────────────────────────────────────────
$f_div    = trim($_GET['f_div']    ?? '');
$f_loc    = trim($_GET['f_loc']    ?? '');
$f_search = trim($_GET['f_search'] ?? '');
$f_expiry = trim($_GET['f_expiry'] ?? '');

$iw = ["upload_id=$id"];
if ($f_div)    $iw[] = "division='".mysqli_real_escape_string($conn,$f_div)."'";
if ($f_loc)    $iw[] = "location='".mysqli_real_escape_string($conn,$f_loc)."'";
if ($f_search) $iw[] = "(product_name LIKE '%".mysqli_real_escape_string($conn,$f_search)."%'
                        OR sku7 LIKE '%".mysqli_real_escape_string($conn,$f_search)."%'
                        OR batch_code LIKE '%".mysqli_real_escape_string($conn,$f_search)."%')";
if ($f_expiry === 'expired')  $iw[] = "days_to_expire < 0";
if ($f_expiry === 'expiring') $iw[] = "days_to_expire BETWEEN 0 AND 90";
if ($f_expiry === 'ok')       $iw[] = "days_to_expire > 90";
$iw_sql = implode(' AND ', $iw);

$items = [];
$ires  = mysqli_query($conn, "SELECT * FROM stock_upload_items WHERE $iw_sql ORDER BY sr_no ASC");
if ($ires) while ($r = mysqli_fetch_assoc($ires)) $items[] = $r;

// Batch stats (unfiltered)
$bs = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as total,
            COALESCE(SUM(units),0) as total_units,
            COALESCE(SUM(cur_stk_value),0) as total_value,
            COALESCE(SUM(tonnage),0) as total_tonnage,
            SUM(CASE WHEN days_to_expire < 0 THEN 1 ELSE 0 END) as expired,
            SUM(CASE WHEN days_to_expire BETWEEN 0 AND 90 THEN 1 ELSE 0 END) as expiring,
            COUNT(DISTINCT division) as divisions,
            COUNT(DISTINCT location) as locations
     FROM stock_upload_items WHERE upload_id=$id"));

// Dropdown options
$divs = []; $dr = mysqli_query($conn,"SELECT DISTINCT division FROM stock_upload_items WHERE upload_id=$id AND division!='' ORDER BY division");
if ($dr) while ($r=mysqli_fetch_assoc($dr)) $divs[]=$r['division'];

$locs = []; $lr = mysqli_query($conn,"SELECT DISTINCT location FROM stock_upload_items WHERE upload_id=$id AND location!='' ORDER BY location");
if ($lr) while ($r=mysqli_fetch_assoc($lr)) $locs[]=$r['location'];

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .18s;white-space:nowrap;}
.btn-sm{padding:6px 12px;font-size:12px;}
.btn-xs{padding:4px 9px;font-size:11px;border-radius:5px;}
.btn-primary{background:#0f172a;color:#fff;}.btn-primary:hover{background:#1e293b;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e0e0e0;}.btn-secondary:hover{background:#ececec;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-teal{background:#0d9488;color:#fff;}.btn-teal:hover{background:#0f766e;}

.breadcrumb{display:flex;align-items:center;gap:7px;font-size:12.5px;color:#9ca3af;margin-bottom:18px;}
.breadcrumb a{color:#6b7280;text-decoration:none;font-weight:600;}.breadcrumb a:hover{color:#0f172a;}
.breadcrumb .sep{color:#d1d5db;}

.info-strip{background:#0f172a;border-radius:12px;padding:20px 24px;margin-bottom:22px;display:flex;flex-wrap:wrap;gap:24px;align-items:center;}
.is-cell{display:flex;flex-direction:column;gap:2px;}
.is-lbl{font-size:9.5px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;}
.is-val{font-size:15px;font-weight:800;color:#f1f5f9;line-height:1.2;}
.is-val.teal{color:#2dd4bf;}.is-val.blue{color:#60a5fa;}.is-val.amber{color:#fbbf24;}.is-val.green{color:#4ade80;}
.is-divider{width:1px;height:36px;background:#1e293b;flex-shrink:0;}

.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:12px;margin-bottom:20px;}
.s-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;position:relative;overflow:hidden;}
.s-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.s-card.blue::before{background:linear-gradient(90deg,#3b82f6,#60a5fa);}
.s-card.green::before{background:linear-gradient(90deg,#22c55e,#4ade80);}
.s-card.teal::before{background:linear-gradient(90deg,#0d9488,#2dd4bf);}
.s-card.amber::before{background:linear-gradient(90deg,#f59e0b,#fbbf24);}
.s-card.red::before{background:linear-gradient(90deg,#ef4444,#f87171);}
.s-card.purple::before{background:linear-gradient(90deg,#8b5cf6,#a78bfa);}
.s-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.s-value{font-size:19px;font-weight:800;color:#0f172a;line-height:1;}
.s-sub{font-size:10.5px;color:#9ca3af;margin-top:3px;}

.filter-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:13px 16px;margin-bottom:16px;}
.filter-row{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;}
.ffg{display:flex;flex-direction:column;gap:4px;}
.ffg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
.fctrl{padding:7px 10px;border:1px solid #e0e0e0;border-radius:7px;font-size:12.5px;font-family:inherit;color:#111827;background:#fff;outline:none;}
.fctrl:focus{border-color:#0f172a;}

.qf-pills{display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-bottom:14px;}
.qf-pill{padding:4px 13px;border-radius:20px;font-size:11.5px;font-weight:700;cursor:pointer;border:1.5px solid transparent;transition:all .15s;text-decoration:none;}
.qf-pill.all{background:#0f172a;color:#fff;}
.qf-pill.ok{background:#dcfce7;color:#166534;border-color:#86efac;}.qf-pill.ok.active,.qf-pill.ok:hover{background:#15803d;color:#fff;border-color:#15803d;}
.qf-pill.warn{background:#fef9c3;color:#854d0e;border-color:#fde68a;}.qf-pill.warn.active,.qf-pill.warn:hover{background:#d97706;color:#fff;border-color:#d97706;}
.qf-pill.danger{background:#fee2e2;color:#991b1b;border-color:#fca5a5;}.qf-pill.danger.active,.qf-pill.danger:hover{background:#dc2626;color:#fff;border-color:#dc2626;}

.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #f0f0f0;gap:8px;flex-wrap:wrap;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;}
.dt-wrap{overflow-x:auto;max-height:65vh;overflow-y:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table thead{position:sticky;top:0;z-index:5;}
.data-table thead th{padding:9px 10px;text-align:left;font-weight:700;font-size:10.5px;color:#f1f5f9;background:#0f172a;white-space:nowrap;}
.data-table thead th.tr{text-align:right;}.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr:hover td{background:#f8fafc;}
.data-table tbody tr.row-expired td{background:#fff8f8!important;}
.data-table tbody tr.row-expiring td{background:#fffbeb!important;}
.data-table td{padding:8px 10px;color:#374151;vertical-align:middle;background:#fff;}
.data-table td.tr{text-align:right;}.data-table td.tc{text-align:center;}
.data-table tfoot td{padding:9px 10px;font-weight:800;font-size:11.5px;background:#0f172a;color:#e2e8f0;}
.empty-state{text-align:center;padding:50px 20px;color:#9ca3af;}

.exp-badge{display:inline-block;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;}
.exp-ok{background:#dcfce7;color:#166534;}
.exp-warn{background:#fef9c3;color:#854d0e;}
.exp-danger{background:#fee2e2;color:#991b1b;}

#svToast{position:fixed;bottom:28px;right:28px;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;color:#fff;z-index:99999;display:none;box-shadow:0 4px 16px rgba(0,0,0,.18);}
#svToast.success{background:#15803d;}
#svToast.error{background:#dc2626;}

@media(max-width:640px){.info-strip{flex-direction:column;gap:14px;}.is-divider{display:none;}.filter-row{flex-direction:column;}}
</style>

<div class="breadcrumb">
    <a href="stock_list.php"><i class="fa-solid fa-layer-group"></i> Stock Uploads</a>
    <span class="sep">/</span>
    <span><?php echo htmlspecialchars($upload['batch_ref']); ?></span>
</div>

<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
    <div>
        <h2 class="page-title"><i class="fa-solid fa-magnifying-glass-chart"></i> Stock Upload — Detail View</h2>
        <p class="page-subtitle">Batch <strong><?php echo htmlspecialchars($upload['batch_ref']); ?></strong> &mdash; uploaded <?php echo date('d M Y H:i',strtotime($upload['created_at'])); ?></p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="stock_list.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <a href="stock_upload.php" class="btn btn-teal btn-sm"><i class="fa-solid fa-file-arrow-up"></i> New Upload</a>
    </div>
</div>

<!-- INFO STRIP -->
<div class="info-strip">
    <div class="is-cell"><div class="is-lbl">Batch Ref</div><div class="is-val teal"><?php echo htmlspecialchars($upload['batch_ref']); ?></div></div>
    <div class="is-divider"></div>
    <div class="is-cell"><div class="is-lbl">Delivery Date</div><div class="is-val amber"><?php echo date('d M Y',strtotime($upload['deliver_date'])); ?></div></div>
    <div class="is-divider"></div>
    <div class="is-cell"><div class="is-lbl">RS Name</div><div class="is-val"><?php echo htmlspecialchars($upload['rs_name']??'—'); ?></div></div>
    <div class="is-divider"></div>
    <div class="is-cell"><div class="is-lbl">Report Date</div><div class="is-val"><?php echo (!empty($upload['report_date'])&&$upload['report_date']!=='0000-00-00')?date('d M Y',strtotime($upload['report_date'])):'—'; ?></div></div>
    <div class="is-divider"></div>
    <div class="is-cell"><div class="is-lbl">Total Rows</div><div class="is-val blue"><?php echo number_format($upload['total_rows']); ?></div></div>
    <div class="is-divider"></div>
    <div class="is-cell"><div class="is-lbl">Total Units</div><div class="is-val green"><?php echo number_format($upload['total_units']); ?></div></div>
    <div class="is-divider"></div>
    <div class="is-cell"><div class="is-lbl">Stock Value</div><div class="is-val" style="color:#c4b5fd;"><?php echo number_format($upload['total_value'],2); ?></div></div>
    <?php if (!empty($upload['uploaded_by'])): ?>
    <div class="is-divider"></div>
    <div class="is-cell"><div class="is-lbl">Uploaded By</div><div class="is-val" style="font-size:13px;"><?php echo htmlspecialchars($upload['uploaded_by']); ?></div></div>
    <?php endif; ?>
    <?php if (!empty($upload['notes'])): ?>
    <div class="is-divider"></div>
    <div class="is-cell"><div class="is-lbl">Notes</div><div class="is-val" style="font-size:12px;font-weight:500;color:#cbd5e1;max-width:200px;"><?php echo htmlspecialchars($upload['notes']); ?></div></div>
    <?php endif; ?>
    <div style="margin-left:auto;">
        <span style="background:<?php echo $upload['status']==='Active'?'#16a34a':'#4b5563'; ?>;color:#fff;padding:5px 14px;border-radius:8px;font-size:12px;font-weight:700;">
            <?php echo $upload['status']; ?>
        </span>
    </div>
</div>

<!-- SUMMARY GRID -->
<?php $good = $bs['total'] - $bs['expired'] - $bs['expiring']; ?>
<div class="summary-grid">
    <div class="s-card blue"><div class="s-label">Total SKUs</div><div class="s-value"><?php echo number_format($bs['total']); ?></div><div class="s-sub">Filtered: <?php echo count($items); ?></div></div>
    <div class="s-card teal"><div class="s-label">Total Units</div><div class="s-value"><?php echo number_format($bs['total_units']); ?></div><div class="s-sub">All rows</div></div>
    <div class="s-card green"><div class="s-label">Stock Value</div><div class="s-value" style="font-size:14px;"><?php echo number_format($bs['total_value'],2); ?></div><div class="s-sub">All rows</div></div>
    <div class="s-card amber"><div class="s-label">Tonnage</div><div class="s-value"><?php echo number_format($bs['total_tonnage'],2); ?></div><div class="s-sub">Total</div></div>
    <div class="s-card red"><div class="s-label">Expired SKUs</div><div class="s-value" style="color:#dc2626;"><?php echo number_format($bs['expired']); ?></div><div class="s-sub">Days &lt; 0</div></div>
    <div class="s-card purple"><div class="s-label">Expiring Soon</div><div class="s-value" style="color:#7c3aed;"><?php echo number_format($bs['expiring']); ?></div><div class="s-sub">Within 90 days</div></div>
    <div class="s-card blue"><div class="s-label">Divisions</div><div class="s-value"><?php echo number_format($bs['divisions']); ?></div><div class="s-sub">Unique</div></div>
    <div class="s-card teal"><div class="s-label">Locations</div><div class="s-value"><?php echo number_format($bs['locations']); ?></div><div class="s-sub">Unique</div></div>
</div>

<!-- QUICK FILTER PILLS -->
<?php
$base   = 'stock_view.php?id='.$id;
$extras = array_filter(['f_div'=>$f_div,'f_loc'=>$f_loc,'f_search'=>$f_search]);
$ex     = $extras ? '&'.http_build_query($extras) : '';
?>
<div class="qf-pills">
    <span style="font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;">Quick Filter:</span>
    <a href="<?php echo $base.$ex; ?>"                           class="qf-pill all <?php echo !$f_expiry?'active':''; ?>">All (<?php echo $bs['total']; ?>)</a>
    <a href="<?php echo $base.'&f_expiry=ok'.$ex; ?>"            class="qf-pill ok <?php echo $f_expiry==='ok'?'active':''; ?>">✓ Good (<?php echo $good; ?>)</a>
    <a href="<?php echo $base.'&f_expiry=expiring'.$ex; ?>"      class="qf-pill warn <?php echo $f_expiry==='expiring'?'active':''; ?>">⚠ Expiring (<?php echo $bs['expiring']; ?>)</a>
    <a href="<?php echo $base.'&f_expiry=expired'.$ex; ?>"       class="qf-pill danger <?php echo $f_expiry==='expired'?'active':''; ?>">✕ Expired (<?php echo $bs['expired']; ?>)</a>
</div>

<!-- FILTER -->
<div class="filter-card">
    <form method="GET">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <?php if ($f_expiry): ?><input type="hidden" name="f_expiry" value="<?php echo htmlspecialchars($f_expiry); ?>"><?php endif; ?>
        <div class="filter-row">
            <div class="ffg">
                <label>Search</label>
                <input type="text" name="f_search" class="fctrl" style="width:200px;" placeholder="Product / SKU / Batch…" value="<?php echo htmlspecialchars($f_search); ?>">
            </div>
            <div class="ffg">
                <label>Division</label>
                <select name="f_div" class="fctrl" style="width:130px;">
                    <option value="">— All —</option>
                    <?php foreach ($divs as $d): ?><option value="<?php echo htmlspecialchars($d); ?>" <?php echo $f_div===$d?'selected':''; ?>><?php echo htmlspecialchars($d); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="ffg">
                <label>Location</label>
                <select name="f_loc" class="fctrl" style="width:180px;">
                    <option value="">— All —</option>
                    <?php foreach ($locs as $l): ?><option value="<?php echo htmlspecialchars($l); ?>" <?php echo $f_loc===$l?'selected':''; ?>><?php echo htmlspecialchars($l); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="ffg" style="flex-direction:row;gap:6px;align-items:flex-end;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
                <a href="stock_view.php?id=<?php echo $id; ?>" class="btn btn-secondary btn-sm"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </div>
    </form>
</div>

<!-- TABLE -->
<div class="table-card">
    <div class="table-toolbar">
        <div class="tbl-title">
            <i class="fa-solid fa-table" style="color:#0d9488;"></i> Stock Items
            <span style="background:#f1f5f9;color:#374151;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700;"><?php echo count($items); ?> rows</span>
            <?php if ($f_div||$f_loc||$f_search||$f_expiry): ?>
            <span style="background:#fef9c3;color:#854d0e;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700;"><i class="fa-solid fa-filter"></i> Filtered</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="dt-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Sr No</th>
                    <th>Division</th>
                    <th>Basepack</th>
                    <th>SKU7</th>
                    <th>Product Name</th>
                    <th>Location</th>
                    <th>PKM</th>
                    <th>Batch Code</th>
                    <th>Expiry Date</th>
                    <th class="tr">Days Left</th>
                    <th class="tc">UPC</th>
                    <th class="tr">Units</th>
                    <th class="tr">Pur. Rate</th>
                    <th class="tr">Pur+Tax</th>
                    <th class="tr">TUR</th>
                    <th class="tr">MRP</th>
                    <th class="tr">Stk Value</th>
                    <th class="tr">Tonnage</th>
                    <th class="tc">Del</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="20">
                    <div class="empty-state">
                        <i class="fa-solid fa-box-open" style="font-size:34px;display:block;margin-bottom:12px;opacity:.2;"></i>
                        <p>No items match the current filters.</p>
                    </div>
                </td></tr>
            <?php else: $rn=1; foreach ($items as $it):
                $days = intval($it['days_to_expire']);
                $row_cls = $days < 0 ? 'row-expired' : ($days <= 90 ? 'row-expiring' : '');
                $exp_cls = $days < 0 ? 'exp-danger' : ($days <= 90 ? 'exp-warn' : 'exp-ok');
                $exp_ico = $days < 0 ? 'fa-circle-xmark' : ($days <= 90 ? 'fa-triangle-exclamation' : 'fa-circle-check');
            ?>
                <tr class="<?php echo $row_cls; ?>" id="item-tr-<?php echo $it['id']; ?>">
                    <td style="color:#9ca3af;font-size:10.5px;"><?php echo $rn++; ?></td>
                    <td style="color:#9ca3af;font-size:11px;"><?php echo $it['sr_no']; ?></td>
                    <td><span style="background:#e0f2fe;color:#0369a1;padding:1px 7px;border-radius:6px;font-size:10.5px;font-weight:700;"><?php echo htmlspecialchars($it['division']); ?></span></td>
                    <td style="font-size:11.5px;font-family:monospace;"><?php echo htmlspecialchars($it['basepack_code']); ?></td>
                    <td style="font-size:11.5px;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($it['sku7']); ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;" title="<?php echo htmlspecialchars($it['product_name']); ?>"><?php echo htmlspecialchars($it['product_name']); ?></td>
                    <td style="font-size:11px;color:#6b7280;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($it['location']); ?>"><?php echo htmlspecialchars($it['location']); ?></td>
                    <td style="font-size:11.5px;font-family:monospace;"><?php echo htmlspecialchars($it['pkm']); ?></td>
                    <td><code style="background:#f1f5f9;padding:1px 5px;border-radius:4px;font-size:11px;"><?php echo htmlspecialchars($it['batch_code']); ?></code></td>
                    <td style="white-space:nowrap;font-size:11.5px;">
                        <?php echo (!empty($it['expiry_date'])&&$it['expiry_date']!=='0000-00-00')
                            ? '<span style="background:#f8fafc;border:1px solid #e2e8f0;padding:1px 7px;border-radius:5px;font-size:11px;">'.date('d M Y',strtotime($it['expiry_date'])).'</span>'
                            : '—'; ?>
                    </td>
                    <td class="tr">
                        <span class="exp-badge <?php echo $exp_cls; ?>">
                            <i class="fa-solid <?php echo $exp_ico; ?>" style="font-size:9px;"></i>
                            <?php echo number_format($days); ?>d
                        </span>
                    </td>
                    <td class="tc" style="font-weight:700;"><?php echo $it['upc']; ?></td>
                    <td class="tr"><strong><?php echo number_format($it['units']); ?></strong></td>
                    <td class="tr" style="font-size:11.5px;"><?php echo number_format($it['pur_rate'],3); ?></td>
                    <td class="tr" style="font-size:11.5px;"><?php echo number_format($it['pur_rate_tax'],3); ?></td>
                    <td class="tr" style="font-size:11.5px;"><?php echo number_format($it['tur'],3); ?></td>
                    <td class="tr" style="color:#1d4ed8;font-weight:600;font-size:11.5px;"><?php echo number_format($it['mrp'],2); ?></td>
                    <td class="tr" style="font-weight:700;color:#15803d;"><?php echo number_format($it['cur_stk_value'],2); ?></td>
                    <td class="tr" style="font-size:11px;color:#6b7280;"><?php echo number_format($it['tonnage'],3); ?></td>
                    <td class="tc">
                        <button class="btn btn-xs btn-danger" onclick="deleteItem(<?php echo $it['id']; ?>)" title="Remove row">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($items)):
                $ft_units = array_sum(array_column($items,'units'));
                $ft_val   = array_sum(array_column($items,'cur_stk_value'));
                $ft_ton   = array_sum(array_column($items,'tonnage'));
            ?>
            <tfoot>
                <tr>
                    <td colspan="12" style="text-align:right;font-size:10.5px;opacity:.7;">TOTALS — <?php echo count($items); ?> rows</td>
                    <td class="tr" style="color:#e2e8f0;"><?php echo number_format($ft_units); ?></td>
                    <td colspan="4"></td>
                    <td class="tr" style="color:#4ade80;"><?php echo number_format($ft_val,2); ?></td>
                    <td class="tr" style="color:#94a3b8;"><?php echo number_format($ft_ton,3); ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<div id="svToast"></div>
<script>
function deleteItem(itemId) {
    if (!confirm('Remove this stock row? This cannot be undone.')) return;
    const fd = new FormData(); fd.append('item_id', itemId);
    fetch('stock_view.php?id=<?php echo $id; ?>&action=delete_item', {method:'POST',body:fd})
    .then(r=>r.json()).then(res=>{
        if (res.success) { document.getElementById('item-tr-'+itemId)?.remove(); showToast('Row removed.','success'); }
        else showToast('Error: '+(res.message||'Delete failed'),'error');
    }).catch(()=>showToast('Network error.','error'));
}
function showToast(msg,type){
    const t=document.getElementById('svToast');
    t.textContent=msg;t.className=type;t.style.display='block';
    clearTimeout(t._t);t._t=setTimeout(()=>t.style.display='none',3500);
}
</script>
<?php include 'footer.php'; ?>
