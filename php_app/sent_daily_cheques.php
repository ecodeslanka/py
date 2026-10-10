<?php
/**
 * sent_daily_cheques.php — Sent Daily Cheques List
 * Lists all cheque sent batches with summary and drill-down to view details
 */

/* ── AJAX: delete batch ── */
if (session_status() === PHP_SESSION_NONE) session_start();
function get_current_user_label_sdc() {
    return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ?? $_SESSION['full_name'] ?? $_SESSION['email'] ?? (isset($_SESSION['user_id']) ? 'User #'.$_SESSION['user_id'] : 'system');
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_sent_batch') {
    include 'config.php';
    header('Content-Type: application/json');
    ob_start(); ob_end_clean();
    $bid = intval($_POST['batch_id'] ?? 0);
    if (!$bid) { echo json_encode(['success'=>false,'error'=>'Invalid batch ID']); exit; }

    /* Delete items first, then batch */
    mysqli_query($conn, "DELETE FROM cheque_sent_items WHERE batch_id=$bid");
    if (mysqli_query($conn, "DELETE FROM cheque_sent_batches WHERE id=$bid")) {
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]);
    }
    exit;
}

include 'config.php';
include 'header.php';

/* ── Filters ── */
$f_from = trim($_GET['date_from'] ?? '');
$f_to   = trim($_GET['date_to'] ?? '');
$f_code = trim($_GET['batch_code'] ?? '');

$where = ["1=1"];
if ($f_from)  $where[] = "sb.sent_date >= '".mysqli_real_escape_string($conn, $f_from)."'";
if ($f_to)    $where[] = "sb.sent_date <= '".mysqli_real_escape_string($conn, $f_to)."'";
if ($f_code)  $where[] = "sb.batch_code LIKE '%".mysqli_real_escape_string($conn, $f_code)."%'";
$where_sql = implode(' AND ', $where);

/* ── Fetch batches ── */
$sql = "SELECT sb.*,
        (SELECT COUNT(*) FROM cheque_sent_items si WHERE si.batch_id = sb.id) AS item_count,
        (SELECT COALESCE(SUM(si.total_amount),0) FROM cheque_sent_items si WHERE si.batch_id = sb.id) AS calc_total
        FROM cheque_sent_batches sb WHERE $where_sql ORDER BY sb.sent_date DESC, sb.id DESC";
$res = mysqli_query($conn, $sql);
$batches = [];
if ($res) while ($r = mysqli_fetch_assoc($res)) $batches[] = $r;

/* ── Grand totals ── */
$g_count = 0; $g_amount = 0; $g_cheques = 0;
foreach ($batches as $b) {
    $g_count++;
    $g_amount += floatval($b['calc_total']);
    $g_cheques += intval($b['item_count']);
}
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box}
.page-title{font-size:22px;font-weight:800;color:#1e1b4b;margin:0 0 4px}
.page-subtitle{font-size:13px;color:#6b7280;margin:0}
.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:16px 18px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.filter-row{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end}
.ffg{display:flex;flex-direction:column;gap:5px}
.ffg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em}
.ffg input{border:1px solid #e5e5e5;border-radius:7px;padding:8px 11px;font-size:13px;font-family:inherit;color:#1f2937;width:100%;transition:border .2s}
.ffg input:focus{outline:none;border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.1)}
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s;white-space:nowrap}
.btn-primary{background:#7c3aed;color:#fff}.btn-primary:hover{background:#6d28d9}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover{background:#e8e8e8}
.btn-danger{background:#dc2626;color:#fff}.btn-danger:hover{background:#b91c1c}
.btn-sm{padding:5px 12px;font-size:11px}

.stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.stat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
.stat-value{font-size:22px;font-weight:800;line-height:1}
.sv-violet{color:#7c3aed}.sv-amber{color:#d97706}.sv-green{color:#16a34a}
.stat-sub{font-size:10px;color:#9ca3af;margin-top:3px}

.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.table-toolbar{padding:12px 16px;border-bottom:1px solid #f0f0f0;background:#fafafa;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:7px}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap}
.p-violet{background:#ede9fe;color:#5b21b6}

.dt-outer{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;min-width:900px}
.data-table thead th{padding:10px 10px;text-align:left;font-weight:700;font-size:10.5px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.1)}
.data-table thead th:last-child{border-right:none}
.data-table thead th.tr{text-align:right}
.data-table thead th.tc{text-align:center}
.data-table tbody tr{border-bottom:1px solid #f0f2f5;transition:background .12s}
.data-table tbody tr:hover td{background:#faf5ff}
.data-table td{padding:9px 10px;color:#374151;vertical-align:middle;background:#fff}
.tr{text-align:right}.tc{text-align:center}
.data-table tfoot td{padding:10px;font-weight:800;font-size:12px;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155}
.data-table tfoot td.tr{text-align:right}
.mono{font-family:'Courier New',monospace;font-weight:700;letter-spacing:.02em}
.batch-code{background:#ede9fe;color:#5b21b6;padding:4px 10px;border-radius:6px;font-family:'Courier New',monospace;font-size:12px;font-weight:800;white-space:nowrap;display:inline-block;}
.date-txt{font-size:12px;color:#374151;white-space:nowrap}
.remark-cell{max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:#6b7280}
.created-by{font-size:11px;color:#6b7280;display:flex;align-items:center;gap:4px}
.amt-cell{font-weight:700;color:#1f2937;white-space:nowrap}
.view-btn{display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;border:none;border-radius:6px;padding:5px 12px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;transition:filter .2s;white-space:nowrap}
.view-btn:hover{filter:brightness(1.1);color:#fff;}
.print-btn{display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#0369a1,#0284c7);color:#fff;border:none;border-radius:6px;padding:5px 12px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;transition:filter .2s;white-space:nowrap}
.print-btn:hover{filter:brightness(1.1);color:#fff;}
.del-btn{display:inline-flex;align-items:center;gap:4px;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:6px;padding:5px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .2s;white-space:nowrap}
.del-btn:hover{background:#dc2626;color:#fff;border-color:#dc2626;}
.empty-state{text-align:center;padding:60px 20px;color:#9ca3af}
.empty-state i{font-size:48px;display:block;margin-bottom:12px;opacity:.3}
.empty-state p{font-size:14px;font-weight:500}
#toast{position:fixed;bottom:28px;right:28px;z-index:99999;padding:12px 22px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 6px 24px rgba(0,0,0,.2);color:#fff;transform:translateY(80px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none}
#toast.show{transform:translateY(0);opacity:1}
@media(max-width:768px){.filter-row{grid-template-columns:1fr 1fr}.stat-grid{grid-template-columns:1fr}}
@media print{.no-print{display:none!important}.data-table thead th{background:#1e1b4b!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}.data-table tfoot td{background:#0f172a!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>

<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;" class="no-print">
  <div>
    <h2 class="page-title"><i class="fa-solid fa-paper-plane" style="color:#7c3aed;"></i> Sent Daily Cheques</h2>
    <p class="page-subtitle">History of all cheque batches sent — view, print, and manage sent records.</p>
  </div>
  <div style="display:flex;gap:8px;" class="no-print">
    <a href="cheques.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to Cheques</a>
    <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</button>
  </div>
</div>

<!-- FILTERS -->
<div class="filter-card no-print">
  <form method="GET" action="">
    <div class="filter-row">
      <div class="ffg"><label><i class="fa-solid fa-calendar-day"></i> Date From</label><input type="date" name="date_from" value="<?=htmlspecialchars($f_from)?>"></div>
      <div class="ffg"><label><i class="fa-solid fa-calendar-day"></i> Date To</label><input type="date" name="date_to" value="<?=htmlspecialchars($f_to)?>"></div>
      <div class="ffg"><label><i class="fa-solid fa-hashtag"></i> Batch Code</label><input type="text" name="batch_code" value="<?=htmlspecialchars($f_code)?>" placeholder="Search batch code…"></div>
      <div class="ffg" style="flex-direction:row;gap:8px;align-items:flex-end;">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
        <a href="sent_daily_cheques.php" class="btn btn-secondary" title="Clear"><i class="fa-solid fa-rotate-left"></i></a>
      </div>
    </div>
  </form>
</div>

<!-- STATS -->
<div class="stat-grid">
  <div class="stat-card"><div class="stat-label">Total Batches</div><div class="stat-value sv-violet"><?=$g_count?></div><div class="stat-sub">sent batches</div></div>
  <div class="stat-card"><div class="stat-label">Total Cheques Sent</div><div class="stat-value sv-amber"><?=$g_cheques?></div><div class="stat-sub">individual cheques</div></div>
  <div class="stat-card"><div class="stat-label">Total Amount</div><div class="stat-value sv-green">Rs.&nbsp;<?=number_format($g_amount,0)?></div><div class="stat-sub">across all batches</div></div>
</div>

<!-- TABLE -->
<div class="table-card">
  <div class="table-toolbar no-print">
    <div class="tbl-title"><i class="fa-solid fa-table-list"></i> Sent Batches <span class="pill p-violet"><?=count($batches)?> records</span></div>
  </div>
  <div class="dt-outer">
    <table class="data-table">
      <thead><tr>
        <th>#</th>
        <th>Batch Code</th>
        <th class="tc">Sent Date</th>
        <th class="tc">Cheques</th>
        <th class="tr">Total Amount</th>
        <th>Remark</th>
        <th>Created By</th>
        <th class="tc">Created At</th>
        <th class="tc no-print">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($batches)): ?>
        <tr><td colspan="9">
          <div class="empty-state"><i class="fa-solid fa-inbox"></i><p>No sent batches found.</p></div>
        </td></tr>
        <?php else: $n=0; foreach ($batches as $b): $n++; ?>
        <tr>
          <td style="color:#9ca3af;font-size:11px;font-weight:600;"><?=$n?></td>
          <td><span class="batch-code"><?=htmlspecialchars($b['batch_code'])?></span></td>
          <td class="tc"><?php
            $sd = $b['sent_date'];
            if ($sd && $sd !== '0000-00-00') {
                $dt = new DateTime($sd);
                echo '<span class="date-txt">' . $dt->format('d M Y') . '</span>';
            } else echo '<span class="date-txt" style="color:#d1d5db;">—</span>';
          ?></td>
          <td class="tc"><span style="background:#ede9fe;color:#5b21b6;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:700;"><?=intval($b['item_count'])?></span></td>
          <td class="tr"><span class="amt-cell">Rs.&nbsp;<?=number_format(floatval($b['calc_total']),2)?></span></td>
          <td><span class="remark-cell" title="<?=htmlspecialchars($b['remark'] ?? '')?>"><?=htmlspecialchars($b['remark'] ?? '—')?></span></td>
          <td><span class="created-by"><i class="fa-solid fa-user" style="font-size:10px;color:#9ca3af;"></i> <?=htmlspecialchars($b['created_by'] ?? 'system')?></span></td>
          <td class="tc"><?php
            $ca = $b['created_at'] ?? '';
            if ($ca) { $dt2 = new DateTime($ca); echo '<span class="date-txt">'.$dt2->format('d M Y H:i').'</span>'; }
            else echo '—';
          ?></td>
          <td class="tc no-print">
            <div style="display:flex;align-items:center;justify-content:center;gap:5px;flex-wrap:wrap;">
              <a href="view_sent_batch.php?id=<?=$b['id']?>" class="view-btn" title="View details"><i class="fa-solid fa-eye"></i> View</a>
              <a href="view_sent_batch.php?id=<?=$b['id']?>&print=1" class="print-btn" target="_blank" title="Print document"><i class="fa-solid fa-print"></i> Print</a>
              <button class="del-btn" onclick="deleteBatch(<?=$b['id']?>,'<?=htmlspecialchars($b['batch_code'])?>')" title="Delete"><i class="fa-solid fa-trash-can"></i></button>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if (!empty($batches)): ?>
      <tfoot><tr>
        <td colspan="3"></td>
        <td class="tc" style="color:#c4b5fd;font-weight:800;"><?=$g_cheques?></td>
        <td class="tr">Rs.&nbsp;<?=number_format($g_amount,2)?></td>
        <td colspan="4" style="font-size:11px;opacity:.65;">TOTAL — <?=$g_count?> BATCHES</td>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<div id="toast"></div>

<script>
function showToast(msg,type){const t=document.getElementById('toast');t.style.background=type==='ok'?'#166534':'#dc2626';t.textContent=msg;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),3200);}

async function deleteBatch(id, code) {
    if (!confirm('Delete batch ' + code + '?\n\nThis will remove all sent items in this batch. The original cheques will NOT be affected.')) return;
    const fd = new FormData();
    fd.append('ajax_action', 'delete_sent_batch');
    fd.append('batch_id', id);
    try {
        const res = await fetch('sent_daily_cheques.php', {method:'POST', body:fd});
        const data = await res.json();
        if (data.success) {
            showToast('Batch ' + code + ' deleted ✓', 'ok');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(data.error || 'Delete failed', 'err');
        }
    } catch(e) {
        showToast('Network error', 'err');
    }
}
</script>

<?php include 'footer.php'; ?>
