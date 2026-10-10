<?php
ob_start();
include 'config.php';
ob_end_clean();
include 'header.php';

/* ═══════════════════════════════════════════════════════════════════
   employee_charge_report.php
   Employee Charge (Shortage) Report — with per-item breakdown
   Updated: distinguishes SHORT vs EXCESS items per employee.
   Shows: Total Short Charge, Total Excess Credit, Net Charge (Short − Excess)
   Updated: all employee name displays now use name_with_initials
            (table = bold name_with_initials, modal header, co-employee
            pills, and Excel exports all show name_with_initials only).
   ═══════════════════════════════════════════════════════════════════ */

$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';
$filtered  = isset($_GET['filtered'])  && $_GET['filtered'] === '1';

$summary_rows  = [];
$grand_total   = 0;
$detail_by_emp = [];
$items_by_emp  = [];
$df_display    = '';
$dt_display    = '';

if ($filtered && !empty($date_from) && !empty($date_to)) {

    $df_safe = mysqli_real_escape_string($conn, $date_from);
    $dt_safe = mysqli_real_escape_string($conn, $date_to);

    $df_display = date('d M Y', strtotime($date_from));
    $dt_display = date('d M Y', strtotime($date_to));

    /* ── Summary: per-employee ── */
    $summary_sql = "
        SELECT
            upt.employee_id                      AS db_emp_id,
            upt.employee_name                    AS emp_name,
            e.name_with_initials                 AS name_with_initials,
            COUNT(DISTINCT upt.import_id)        AS import_count,
            COUNT(DISTINCT upt.import_detail_id) AS item_count,
            COUNT(upt.id)                        AS tx_count,
            SUM(upt.amount)                      AS total_amount
        FROM unloading_pay_transactions upt
        INNER JOIN unloading_summary_imports usi ON usi.id = upt.import_id
        LEFT  JOIN employees e ON e.id = upt.employee_id
        WHERE upt.entry_type  = 'charge'
          AND upt.employee_id IS NOT NULL
          AND usi.delivery_date BETWEEN '$df_safe' AND '$dt_safe'
        GROUP BY upt.employee_id, upt.employee_name, e.name_with_initials
        ORDER BY upt.employee_name ASC
    ";

    $res = mysqli_query($conn, $summary_sql);
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $r['total_amount'] = floatval($r['total_amount']);
            $grand_total      += $r['total_amount'];
            $summary_rows[]    = $r;
        }
    }

    /* ── Per-employee per-item detail — include se_value sign ── */
    /*
       We need to know whether each item was short or excess.
       unloading_data.short_excess: negative = SHORT (employee owes),
                                    positive = EXCESS (employee gets credit).
       We join unloading_data to get the short_excess sign.
    */
    $items_sql = "
        SELECT
            upt.employee_id                 AS db_emp_id,
            upt.employee_name               AS emp_name,
            e.name_with_initials            AS name_with_initials,
            upt.import_detail_id,
            upt.import_id,
            usi.delivery_date,
            usi.filename,
            upt.amount,
            upt.se_value,
            upt.transaction_date,
            upt.payroll_month_label,
            COALESCE(ud.short_excess, 0)    AS short_excess_qty
        FROM unloading_pay_transactions upt
        INNER JOIN unloading_summary_imports usi ON usi.id = upt.import_id
        LEFT  JOIN unloading_data ud ON ud.id = upt.import_detail_id
        LEFT  JOIN employees e ON e.id = upt.employee_id
        WHERE upt.entry_type  = 'charge'
          AND upt.employee_id IS NOT NULL
          AND usi.delivery_date BETWEEN '$df_safe' AND '$dt_safe'
        ORDER BY upt.employee_id ASC, usi.delivery_date ASC, upt.import_detail_id ASC
    ";

    $ires = mysqli_query($conn, $items_sql);
    if ($ires) {
        while ($d = mysqli_fetch_assoc($ires)) {
            $items_by_emp[intval($d['db_emp_id'])][] = $d;
        }
    }

    /* ── Co-responsible employees per item ── */
    $all_detail_ids = [];
    foreach ($items_by_emp as $rows) {
        foreach ($rows as $r) {
            $all_detail_ids[intval($r['import_detail_id'])] = true;
        }
    }

    $co_map = [];
    if (!empty($all_detail_ids)) {
        $ids_str = implode(',', array_keys($all_detail_ids));
        $co_sql  = "
            SELECT c.import_detail_id, c.employee_id, c.employee_name,
                   e.name_with_initials AS name_with_initials, c.amount
            FROM unloading_pay_transactions c
            LEFT JOIN employees e ON e.id = c.employee_id
            WHERE c.entry_type = 'charge'
              AND c.employee_id IS NOT NULL
              AND c.import_detail_id IN ($ids_str)
            ORDER BY c.import_detail_id ASC, c.employee_name ASC
        ";
        $cres = mysqli_query($conn, $co_sql);
        if ($cres) {
            while ($c = mysqli_fetch_assoc($cres)) {
                $co_map[intval($c['import_detail_id'])][] = $c;
            }
        }
    }

    /* ── Legacy detail_by_emp (stat cards) ── */
    $detail_sql = "
        SELECT
            upt.employee_id   AS db_emp_id,
            upt.import_id,
            usi.delivery_date,
            usi.filename
        FROM unloading_pay_transactions upt
        INNER JOIN unloading_summary_imports usi ON usi.id = upt.import_id
        WHERE upt.entry_type  = 'charge'
          AND upt.employee_id IS NOT NULL
          AND usi.delivery_date BETWEEN '$df_safe' AND '$dt_safe'
        GROUP BY upt.employee_id, upt.import_id, usi.delivery_date, usi.filename
        ORDER BY upt.employee_id ASC, usi.delivery_date ASC
    ";
    $dres = mysqli_query($conn, $detail_sql);
    if ($dres) {
        while ($d = mysqli_fetch_assoc($dres)) {
            $detail_by_emp[intval($d['db_emp_id'])][] = $d;
        }
    }
}
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<style>
/* ── Base ──────────────────────────────────────────────────────── */
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:22px;margin-bottom:20px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.card-title{font-size:15px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;margin:0}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;font-family:inherit;text-decoration:none;white-space:nowrap}
.btn-sm{padding:7px 13px;font-size:12px}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e0e0e0}
.btn-secondary:hover{background:#e8e8e8}
.btn-primary{background:#2563eb;color:#fff}
.btn-primary:hover{background:#1d4ed8}
.btn-excel{background:#166534;color:#fff}
.btn-excel:hover{background:#14532d}

/* ── Filter bar ── */
.filter-bar{display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;padding:16px 20px;background:#f9fafb;border:1px solid #e5e5e5;border-radius:10px;margin-bottom:20px}
.fg{display:flex;flex-direction:column;gap:5px}
.fg label{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.06em}
.fg input[type=date]{padding:8px 12px;border:1.5px solid #e0e0e0;border-radius:6px;font-size:13px;font-family:inherit;color:#1f2937;background:#fff;outline:none;transition:border-color .15s}
.fg input[type=date]:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.08)}
.date-range-label{display:flex;align-items:center;gap:6px;font-size:12px;color:#6b7280;margin-left:auto}
.date-range-label strong{color:#1f2937}

/* ── Stat cards ── */
.stat-cards{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:18px 22px;position:relative;overflow:hidden}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.stat-card.red::before{background:linear-gradient(90deg,#dc2626,#ef4444)}
.stat-card.blue::before{background:linear-gradient(90deg,#2563eb,#3b82f6)}
.stat-card.green::before{background:linear-gradient(90deg,#166534,#16a34a)}
.stat-card.purple::before{background:linear-gradient(90deg,#7c3aed,#8b5cf6)}
.stat-card.amber::before{background:linear-gradient(90deg,#b45309,#d97706)}
.sc-lbl{font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;font-weight:600}
.sc-val{font-size:26px;font-weight:800;line-height:1}
.sc-val.red{color:#dc2626}
.sc-val.blue{color:#2563eb}
.sc-val.green{color:#166534}
.sc-val.purple{color:#7c3aed}
.sc-val.amber{color:#b45309}
.sc-sub{font-size:11px;color:#9ca3af;margin-top:5px}

/* ── Summary table ── */
.report-table-wrap{overflow-x:auto;border-radius:8px;border:1px solid #e5e5e5}
.report-table{width:100%;border-collapse:collapse;font-size:13px}
.report-table thead tr{background:linear-gradient(135deg,#1e3a5f,#2563eb)}
.report-table thead th{color:#fff;padding:11px 15px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap;text-align:left;border:none}
.report-table thead th.num{text-align:right}
.report-table thead th.short-hdr{background:rgba(220,38,38,.25)}
.report-table thead th.excess-hdr{background:rgba(22,163,74,.25)}
.report-table thead th.net-hdr{background:rgba(124,58,237,.25)}
.report-table tbody tr{border-bottom:1px solid #f0f0f0;cursor:pointer;transition:background .1s}
.report-table tbody tr:hover td{background:#eff6ff}
.report-table tbody td{padding:10px 15px;color:#1f2937;vertical-align:middle}
.report-table tbody td.num{text-align:right;font-variant-numeric:tabular-nums}
.report-table tfoot td{padding:11px 15px;font-weight:700;background:#f0f4ff;border-top:2px solid #1e3a5f;font-size:13px}
.report-table tfoot td.num{text-align:right}

/* ── Row elements ── */
.emp-avatar{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:#1e40af;font-size:11px;font-weight:800;flex-shrink:0}
.emp-info{display:flex;align-items:center;gap:10px}
.emp-name-cell{font-weight:700;color:#1f2937;font-size:13px}
.emp-initials-tag{font-size:11px;color:#6b7280;font-style:italic;margin-top:1px}
.emp-id-tag{font-size:10px;color:#9ca3af;margin-top:1px}
.row-num{width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;background:#f5f5f5;border-radius:6px;font-size:12px;font-weight:700;color:#374151}
.amount-short{font-weight:700;font-size:13px;color:#dc2626;font-variant-numeric:tabular-nums}
.amount-excess{font-weight:700;font-size:13px;color:#16a34a;font-variant-numeric:tabular-nums}
.amount-net-positive{font-weight:800;font-size:13.5px;color:#dc2626;font-variant-numeric:tabular-nums}
.amount-net-zero{font-weight:700;font-size:13px;color:#6b7280}
.amount-net-negative{font-weight:800;font-size:13.5px;color:#16a34a;font-variant-numeric:tabular-nums}
.tx-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:600;background:#f0f4ff;color:#1e40af;border:1px solid #dbeafe}
.import-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:600;background:#fefce8;color:#854d0e;border:1px solid #fef08a}
.item-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:600;background:#fdf4ff;color:#7c3aed;border:1px solid #e9d5ff}
.short-count-badge{display:inline-flex;align-items:center;gap:3px;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:600;background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.excess-count-badge{display:inline-flex;align-items:center;gap:3px;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:600;background:#f0fdf4;color:#166634;border:1px solid #bbf7d0}

/* ── Net formula strip ── */
.net-formula{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:600;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:6px;padding:3px 10px;color:#7c3aed;white-space:nowrap}
.net-formula .nf-short{color:#dc2626}
.net-formula .nf-excess{color:#16a34a}
.net-formula .nf-result{color:#7c3aed;font-size:12px}

/* ── Empty / no-data ── */
.empty-state{text-align:center;padding:60px 20px}
.empty-icon{font-size:52px;color:#dbeafe;margin-bottom:16px}
.empty-title{font-size:18px;font-weight:700;color:#1f2937;margin-bottom:8px}
.empty-sub{font-size:14px;color:#6b7280;margin-bottom:24px}
.no-data-row td{text-align:center;padding:40px;color:#9ca3af;font-size:14px}

/* ── Modal ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:1000;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(2px)}
.modal-overlay.open{display:flex}
.detail-modal{background:#fff;border-radius:14px;width:100%;max-width:1160px;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 32px 80px rgba(0,0,0,.24)}
.modal-header{display:flex;justify-content:space-between;align-items:flex-start;padding:20px 24px;border-bottom:1px solid #f0f0f0;flex-shrink:0}
.modal-emp-name{font-size:17px;font-weight:800;color:#1f2937;display:flex;align-items:center;gap:10px}
.modal-emp-sub{font-size:12px;color:#6b7280;margin-top:4px}
.modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:#9ca3af;padding:4px 8px;border-radius:6px;transition:all .15s;line-height:1}
.modal-close:hover{background:#f5f5f5;color:#374151}
.modal-body{flex:1;overflow-y:auto;padding:22px 24px}
.modal-footer{padding:14px 24px;border-top:1px solid #f0f0f0;flex-shrink:0;display:flex;justify-content:flex-end;gap:10px}

/* ── Modal summary strip — 6 cells for short/excess/net ── */
.modal-strip{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:20px}
.ms-cell{border-radius:10px;padding:14px 16px;text-align:center;border:1px solid #e5e5e5}
.ms-cell.red{background:#fff5f5;border-color:#fecaca}
.ms-cell.blue{background:#eff6ff;border-color:#bfdbfe}
.ms-cell.amber{background:#fffbeb;border-color:#fde68a}
.ms-cell.purple{background:#fdf4ff;border-color:#e9d5ff}
.ms-cell.green{background:#f0fdf4;border-color:#bbf7d0}
.ms-cell.net{background:#f5f3ff;border-color:#ddd6fe;border-width:2px}
.ms-lbl{font-size:10px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;font-weight:700}
.ms-val{font-size:18px;font-weight:800}
.ms-val.red{color:#dc2626}
.ms-val.blue{color:#1e40af}
.ms-val.amber{color:#92400e}
.ms-val.purple{color:#7c3aed}
.ms-val.green{color:#16a34a}
.ms-val.net-positive{color:#dc2626}
.ms-val.net-negative{color:#16a34a}
.ms-val.net-zero{color:#6b7280}

/* ── Net bar inside modal ── */
.net-bar{display:flex;align-items:center;gap:10px;padding:10px 16px;background:#f9fafb;border:1px solid #e5e5e5;border-radius:8px;margin-bottom:16px;font-size:13px;flex-wrap:wrap}
.net-bar-lbl{font-weight:700;color:#374151;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.net-bar-formula{display:flex;align-items:center;gap:8px;flex:1;flex-wrap:wrap}
.nb-short{color:#dc2626;font-weight:700}
.nb-excess{color:#16a34a;font-weight:700}
.nb-net{font-weight:800;font-size:14px;padding:3px 12px;border-radius:6px}
.nb-net.positive{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
.nb-net.negative{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0}
.nb-net.zero{background:#f5f5f5;color:#6b7280;border:1px solid #e5e5e5}

/* ── View tabs ── */
.view-tabs{display:flex;gap:4px;margin-bottom:16px;background:#f3f4f6;padding:4px;border-radius:8px;width:fit-content}
.view-tab{padding:6px 16px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:none;background:transparent;color:#6b7280;transition:all .15s;display:flex;align-items:center;gap:6px}
.view-tab.active{background:#fff;color:#1e40af;box-shadow:0 1px 3px rgba(0,0,0,.12)}

/* ── Item breakdown table ── */
.modal-tbl-wrap{overflow-x:auto;border:1px solid #e5e5e5;border-radius:10px}
.modal-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.modal-tbl thead th{background:#f8fafc;padding:9px 12px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#374151;border-bottom:1px solid #e5e5e5;text-align:left;white-space:nowrap}
.modal-tbl thead th.num{text-align:right}
.modal-tbl thead th.short-col{background:#fef2f2;color:#991b1b}
.modal-tbl thead th.excess-col{background:#f0fdf4;color:#166634}
.modal-tbl thead th.net-col{background:#f5f3ff;color:#6d28d9}
.modal-tbl tbody td{padding:9px 12px;border-bottom:1px solid #f5f5f5;vertical-align:middle}
.modal-tbl tbody tr:last-child td{border-bottom:none}
.modal-tbl tbody tr:hover td{background:#f9fafb}
.modal-tbl tbody tr.row-short{border-left:3px solid #fca5a5}
.modal-tbl tbody tr.row-excess{border-left:3px solid #86efac}
.modal-tbl tfoot td{padding:9px 12px;font-weight:700;background:#f0f4ff;border-top:2px solid #dbeafe;font-size:12.5px}
.modal-tbl tfoot td.num{text-align:right}

/* ── Type pill ── */
.type-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;white-space:nowrap}
.type-short{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.type-excess{background:#f0fdf4;color:#166634;border:1px solid #bbf7d0}

/* ── Cell helpers ── */
.date-chip{display:inline-flex;align-items:center;gap:5px;background:#eff6ff;color:#1e40af;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:600}
.payroll-chip{font-size:10.5px;color:#6b7280;background:#f5f5f5;padding:2px 7px;border-radius:5px}
.item-id-chip{display:inline-flex;align-items:center;gap:4px;background:#fdf4ff;color:#7c3aed;padding:2px 8px;border-radius:5px;font-size:10.5px;font-weight:700;font-family:monospace}
.shared-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:600}
.shared-solo{background:#dcfce7;color:#166534}
.shared-split{background:#fef3c7;color:#92400e}
.shared-multi{background:#fee2e2;color:#991b1b}
.se-full-val{font-size:11px;color:#9ca3af;text-decoration:line-through;display:block;line-height:1.3}
.se-divided-val{font-weight:700;color:#374151;font-variant-numeric:tabular-nums}
.se-divide-note{font-size:10px;color:#6b7280;display:block;line-height:1.3;margin-top:1px}
.co-emp-list{margin-top:4px;display:flex;flex-wrap:wrap;gap:4px}
.co-emp-pill{display:inline-flex;align-items:center;gap:4px;background:#f1f5f9;border:1px solid #e2e8f0;color:#374151;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:500}
.co-emp-pill.is-self{background:#dbeafe;border-color:#93c5fd;color:#1e40af;font-weight:700}
.group-row td{background:#f8fafc;font-size:11px;font-weight:700;color:#374151;padding:7px 12px;border-bottom:1px solid #e5e5e5;border-top:2px solid #e2e8f0}

@media(max-width:900px){
    .stat-cards{grid-template-columns:repeat(3,1fr)}
    .modal-strip{grid-template-columns:repeat(3,1fr)}
}
@media(max-width:640px){
    .stat-cards{grid-template-columns:1fr 1fr}
    .modal-strip{grid-template-columns:1fr 1fr}
    .filter-bar{gap:10px}
}
</style>

<!-- ── Page Header ── -->
<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-file-invoice-dollar"></i>
                Employee Charge Report
            </h2>
            <p class="page-subtitle">Short charges &amp; excess credits per employee — net charge breakdown</p>
        </div>
        <a href="gse_list.php" class="btn btn-secondary btn-sm">
            <i class="fa-solid fa-arrow-left"></i> Back
        </a>
    </div>
</div>

<!-- ── Filter Bar ── -->
<form method="GET" action="">
    <input type="hidden" name="filtered" value="1">
    <div class="filter-bar">
        <div class="fg">
            <label><i class="fa-regular fa-calendar"></i> Delivery Date From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" required>
        </div>
        <div class="fg">
            <label><i class="fa-regular fa-calendar"></i> Delivery Date To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" required>
        </div>
        <div style="display:flex;gap:8px;align-items:flex-end;">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-magnifying-glass"></i> Generate Report
            </button>
            <?php if ($filtered): ?>
            <a href="employee_charge_report.php" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-rotate"></i> Reset
            </a>
            <?php endif; ?>
        </div>
        <?php if ($filtered && $df_display): ?>
        <div class="date-range-label">
            <i class="fa-solid fa-calendar-week" style="color:#2563eb;"></i>
            <strong><?php echo $df_display; ?></strong>
            <i class="fa-solid fa-arrow-right" style="font-size:10px;"></i>
            <strong><?php echo $dt_display; ?></strong>
        </div>
        <?php endif; ?>
    </div>
</form>

<?php if (!$filtered): ?>
<div class="content-card">
    <div class="empty-state">
        <div class="empty-icon"><i class="fa-solid fa-calendar-days"></i></div>
        <div class="empty-title">Select a Delivery Date Range</div>
        <div class="empty-sub">Choose a date range above and click <strong>Generate Report</strong> to view employee charges.</div>
    </div>
</div>

<?php else: ?>

<!-- ── Stat Cards ── -->
<?php
    $all_imports = [];
    $all_items   = 0;
    foreach ($items_by_emp as $rows) {
        foreach ($rows as $r) {
            $all_imports[$r['import_id']] = $r['delivery_date'];
            $all_items++;
        }
    }
?>
<div class="stat-cards">
    <div class="stat-card blue">
        <div class="sc-lbl"><i class="fa-solid fa-users"></i> Employees</div>
        <div class="sc-val blue"><?php echo count($summary_rows); ?></div>
        <div class="sc-sub">unique employees</div>
    </div>
    <div class="stat-card amber">
        <div class="sc-lbl"><i class="fa-solid fa-calendar-check"></i> Delivery Days</div>
        <div class="sc-val amber"><?php echo count($all_imports); ?></div>
        <div class="sc-sub">import batches</div>
    </div>
    <div class="stat-card red">
        <div class="sc-lbl"><i class="fa-solid fa-arrow-trend-down"></i> Total Short Charge</div>
        <div class="sc-val red" id="stat_total_short">—</div>
        <div class="sc-sub">shortage charges</div>
    </div>
    <div class="stat-card green">
        <div class="sc-lbl"><i class="fa-solid fa-arrow-trend-up"></i> Total Excess Credit</div>
        <div class="sc-val green" id="stat_total_excess">—</div>
        <div class="sc-sub">excess returns</div>
    </div>
    <div class="stat-card purple">
        <div class="sc-lbl"><i class="fa-solid fa-scale-balanced"></i> Net Charge (Short − Excess)</div>
        <div class="sc-val" id="stat_net_charge" style="color:#7c3aed;">—</div>
        <div class="sc-sub" id="stat_net_sub">net payable</div>
    </div>
</div>

<!-- ── Report Table ── -->
<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title">
            <i class="fa-solid fa-table-list"></i>
            Employee Charge Summary
            <?php if ($df_display): ?>
            <span style="font-size:12px;font-weight:400;color:#9ca3af;margin-left:4px;">
                <?php echo $df_display; ?> &rarr; <?php echo $dt_display; ?>
            </span>
            <?php endif; ?>
        </h3>
        <div style="display:flex;gap:8px;align-items:center;">
            <!-- Legend -->
            <span style="font-size:11px;color:#991b1b;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:4px 10px;font-weight:600;">
                <i class="fa-solid fa-arrow-trend-down"></i> Short = charged to employee
            </span>
            <span style="font-size:11px;color:#166634;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:4px 10px;font-weight:600;">
                <i class="fa-solid fa-arrow-trend-up"></i> Excess = credit back
            </span>
            <button class="btn btn-excel btn-sm" onclick="exportSummaryExcel()">
                <i class="fa-solid fa-file-excel"></i> Export Excel
            </button>
        </div>
    </div>

    <div class="report-table-wrap">
        <table class="report-table" id="summaryTable">
            <thead>
                <tr>
                    <th style="width:44px;">#</th>
                    <th>Employee</th>
                    <th class="num">Days</th>
                    <th class="num">Items</th>
                    <th class="num short-hdr">
                        <i class="fa-solid fa-arrow-trend-down"></i> Short Items
                    </th>
                    <th class="num short-hdr">Short Charge (Rs.)</th>
                    <th class="num excess-hdr">
                        <i class="fa-solid fa-arrow-trend-up"></i> Excess Items
                    </th>
                    <th class="num excess-hdr">Excess Credit (Rs.)</th>
                    <th class="num net-hdr">Net Charge (Rs.)</th>
                    <th style="width:72px;text-align:center;">Detail</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($summary_rows)): ?>
                <tr class="no-data-row">
                    <td colspan="10">
                        <i class="fa-solid fa-inbox" style="font-size:30px;display:block;margin-bottom:10px;opacity:.35;"></i>
                        No charge records found for this date range
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($summary_rows as $i => $row):
                    $display_name = !empty($row['name_with_initials']) ? $row['name_with_initials'] : $row['emp_name'];
                    $initials = '';
                    $parts = explode(' ', trim($display_name));
                    foreach (array_slice($parts, 0, 2) as $p) $initials .= strtoupper(substr($p, 0, 1));
                ?>
                <tr onclick="openModal(<?php echo intval($row['db_emp_id']); ?>)" title="Click to view item breakdown">
                    <td><span class="row-num"><?php echo $i + 1; ?></span></td>
                    <td>
                        <div class="emp-info">
                            <span class="emp-avatar"><?php echo htmlspecialchars($initials ?: '?'); ?></span>
                            <div>
                                <div class="emp-name-cell"><?php echo htmlspecialchars($display_name); ?></div>
                                <div class="emp-id-tag">ID #<?php echo intval($row['db_emp_id']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="num">
                        <span class="import-badge">
                            <i class="fa-solid fa-calendar-day"></i>
                            <?php echo intval($row['import_count']); ?>
                        </span>
                    </td>
                    <td class="num">
                        <span class="item-badge">
                            <i class="fa-solid fa-boxes-stacked"></i>
                            <?php echo intval($row['item_count']); ?>
                        </span>
                    </td>
                    <!-- JS fills these 5 columns -->
                    <td class="num" id="sct_<?php echo intval($row['db_emp_id']); ?>">—</td>
                    <td class="num" id="sca_<?php echo intval($row['db_emp_id']); ?>">—</td>
                    <td class="num" id="ect_<?php echo intval($row['db_emp_id']); ?>">—</td>
                    <td class="num" id="eca_<?php echo intval($row['db_emp_id']); ?>">—</td>
                    <td class="num" id="net_<?php echo intval($row['db_emp_id']); ?>">—</td>
                    <td style="text-align:center;">
                        <span style="display:inline-flex;align-items:center;gap:4px;color:#2563eb;font-size:12px;font-weight:600;cursor:pointer;">
                            <i class="fa-solid fa-eye"></i> View
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($summary_rows)): ?>
            <tfoot>
                <tr>
                    <td colspan="2" style="text-align:right;">
                        <strong>TOTAL — <?php echo count($summary_rows); ?> Employee<?php echo count($summary_rows) !== 1 ? 's' : ''; ?></strong>
                    </td>
                    <td class="num"><strong><?php echo count($all_imports); ?></strong></td>
                    <td class="num"><strong><?php echo $all_items; ?></strong></td>
                    <td class="num" id="ft_sc">—</td>
                    <td class="num" id="ft_sca" style="color:#dc2626;font-size:13.5px;">—</td>
                    <td class="num" id="ft_ec">—</td>
                    <td class="num" id="ft_eca" style="color:#16a34a;font-size:13.5px;">—</td>
                    <td class="num" id="ft_net" style="font-size:14px;">—</td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

    <!-- Footer net formula explanation -->
    <div style="margin-top:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span style="font-size:11px;color:#6b7280;">Net formula:</span>
        <span class="net-formula">
            <span class="nf-short"><i class="fa-solid fa-arrow-trend-down"></i> Short Charge</span>
            <span style="color:#374151;">−</span>
            <span class="nf-excess"><i class="fa-solid fa-arrow-trend-up"></i> Excess Credit</span>
            <span style="color:#374151;">=</span>
            <span class="nf-result"><i class="fa-solid fa-scale-balanced"></i> Net Charge (payable)</span>
        </span>
        <span style="font-size:11px;color:#9ca3af;">· Positive net = employee still owes · Negative net = company owes employee</span>
    </div>
</div>
<?php endif; ?>

<?php if ($filtered): ?>
<!-- ════════════════════════════════════════════════════════════════
     EMPLOYEE DETAIL MODAL
     ════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="detailModal">
    <div class="detail-modal">
        <div class="modal-header">
            <div>
                <div class="modal-emp-name">
                    <span id="modal_avatar" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:#1e40af;font-size:12px;font-weight:800;flex-shrink:0;"></span>
                    <span id="modal_emp_name">—</span>
                </div>
                <div class="modal-emp-sub" id="modal_emp_sub"></div>
            </div>
            <button class="modal-close" onclick="closeModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="modal-body">

            <!-- 6-cell summary strip -->
            <div class="modal-strip">
                <div class="ms-cell blue">
                    <div class="ms-lbl"><i class="fa-solid fa-calendar-week"></i> Days</div>
                    <div class="ms-val blue" id="modal_days">—</div>
                </div>
                <div class="ms-cell amber">
                    <div class="ms-lbl"><i class="fa-solid fa-boxes-stacked"></i> Total Items</div>
                    <div class="ms-val amber" id="modal_items">—</div>
                </div>
                <div class="ms-cell red">
                    <div class="ms-lbl"><i class="fa-solid fa-arrow-trend-down"></i> Short Items</div>
                    <div class="ms-val red" id="modal_short_items">—</div>
                </div>
                <div class="ms-cell red" style="border-width:2px;">
                    <div class="ms-lbl"><i class="fa-solid fa-sack-dollar"></i> Short Charge</div>
                    <div class="ms-val red" id="modal_short_amt">—</div>
                </div>
                <div class="ms-cell green" style="border-width:2px;">
                    <div class="ms-lbl"><i class="fa-solid fa-arrow-trend-up"></i> Excess Credit</div>
                    <div class="ms-val green" id="modal_excess_amt">—</div>
                </div>
                <div class="ms-cell net" style="border-width:2px;">
                    <div class="ms-lbl"><i class="fa-solid fa-scale-balanced"></i> Net Charge</div>
                    <div class="ms-val" id="modal_net_amt">—</div>
                </div>
            </div>

            <!-- Net formula bar -->
            <div class="net-bar">
                <span class="net-bar-lbl"><i class="fa-solid fa-scale-balanced"></i> Net:</span>
                <div class="net-bar-formula">
                    <span class="nb-short" id="nb_short">Rs. 0.00 short</span>
                    <span style="color:#374151;font-weight:700;">−</span>
                    <span class="nb-excess" id="nb_excess">Rs. 0.00 excess</span>
                    <span style="color:#374151;font-weight:700;">=</span>
                    <span class="nb-net" id="nb_net">Rs. 0.00</span>
                </div>
            </div>

            <!-- View tabs -->
            <div class="view-tabs">
                <button class="view-tab active" id="tab_items" onclick="switchTab('items')">
                    <i class="fa-solid fa-boxes-stacked"></i> By Item
                </button>
                <button class="view-tab" id="tab_days" onclick="switchTab('days')">
                    <i class="fa-solid fa-calendar-week"></i> By Delivery Day
                </button>
            </div>

            <!-- Item breakdown table -->
            <div id="view_items">
                <div class="modal-tbl-wrap">
                    <table class="modal-tbl">
                        <thead>
                            <tr>
                                <th style="width:32px;">#</th>
                                <th>Type</th>
                                <th>Delivery Date</th>
                                <th>Item ID</th>
                                <th>Import File</th>
                                <th class="num short-col">Item SE Value (Rs.)</th>
                                <th class="num short-col">This Employee's Share (Rs.)</th>
                                <th>Shared With</th>
                                <th>Payroll Month</th>
                            </tr>
                        </thead>
                        <tbody id="modalItemBody">
                            <tr><td colspan="9" style="text-align:center;padding:24px;color:#9ca3af;">Loading…</td></tr>
                        </tbody>
                        <tfoot id="modalItemFoot" style="display:none">
                            <tr>
                                <td colspan="5"><strong>Total</strong></td>
                                <td class="num short-col" id="modal_item_se_total">—</td>
                                <td class="num short-col" id="modal_item_amt_total">—</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Day breakdown table -->
            <div id="view_days" style="display:none">
                <div class="modal-tbl-wrap">
                    <table class="modal-tbl">
                        <thead>
                            <tr>
                                <th style="width:32px;">#</th>
                                <th>Delivery Date</th>
                                <th>Import File</th>
                                <th class="num">Items</th>
                                <th class="num short-col">Short Charge (Rs.)</th>
                                <th class="num excess-col">Excess Credit (Rs.)</th>
                                <th class="num net-col">Net (Rs.)</th>
                                <th>Payroll Month</th>
                            </tr>
                        </thead>
                        <tbody id="modalDayBody">
                            <tr><td colspan="8" style="text-align:center;padding:24px;color:#9ca3af;">Loading…</td></tr>
                        </tbody>
                        <tfoot id="modalDayFoot" style="display:none">
                            <tr>
                                <td colspan="3"><strong>Total</strong></td>
                                <td class="num" id="modal_day_items_total">—</td>
                                <td class="num" id="modal_day_short_total">—</td>
                                <td class="num" id="modal_day_excess_total">—</td>
                                <td class="num" id="modal_day_net_total">—</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-excel btn-sm" id="modal_excel_btn">
                <i class="fa-solid fa-file-excel"></i> Export Detail
            </button>
            <button class="btn btn-secondary btn-sm" onclick="closeModal()">
                <i class="fa-solid fa-xmark"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     JS — data embedded as JSON
     ════════════════════════════════════════════════════════════════ -->
<script>
const DATE_FROM = <?php echo json_encode($df_display ?: $date_from); ?>;
const DATE_TO   = <?php echo json_encode($dt_display  ?: $date_to);  ?>;

const ITEMS_DATA = <?php echo json_encode($items_by_emp, JSON_UNESCAPED_UNICODE); ?>;
const CO_MAP     = <?php echo json_encode($co_map, JSON_UNESCAPED_UNICODE); ?>;
const SUMMARY_DATA = <?php
    $js_sum = [];
    foreach ($summary_rows as $r) $js_sum[$r['db_emp_id']] = $r;
    echo json_encode($js_sum, JSON_UNESCAPED_UNICODE);
?>;

let _currentEmpId = null;
let _currentTab   = 'items';

/* ── Helpers ── */
function f2(n){return parseFloat(n||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
function fmtDate(s){if(!s)return'—';const d=new Date(s+'T00:00:00');if(isNaN(d))return s;return d.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function initials(name){const p=String(name||'').trim().split(' ');return p.slice(0,2).map(x=>x[0]||'').join('').toUpperCase();}

/* dispName — always prefer name_with_initials over the plain employee name,
   for any object that carries one (summary rows, item rows, co-map rows). */
function dispName(obj){return (obj && (obj.name_with_initials || obj.emp_name || obj.employee_name)) || '';}

/*
   getDividedSeValue — returns:
     seVal   : absolute SE value (always positive — it's the monetary magnitude)
     count   : number of co-responsible employees
     divided : seVal ÷ count — this employee's share
     isShort : true  → short (short_excess_qty < 0) → charge to employee
     isExcess: true  → excess (short_excess_qty > 0) → credit back
*/
function getDividedSeValue(r) {
    const detailId   = parseInt(r.import_detail_id);
    const coAll      = (CO_MAP[detailId] || []).filter(c => c.employee_id !== null);
    const count      = coAll.length || 1;
    const seVal      = parseFloat(r.se_value || 0);   /* always positive monetary amount */
    const divided    = seVal / count;

    /* Determine direction from short_excess_qty column.
       If se_value itself carries sign, we can also infer from it.
       short_excess_qty < 0 means SHORT (employee owes company).
       short_excess_qty > 0 means EXCESS (company owes employee). */
    const seQty      = parseFloat(r.short_excess_qty || 0);
    const isShort    = seQty < 0;
    const isExcess   = seQty > 0;

    return { seVal, count, divided, isShort, isExcess };
}

/*
   empBreakdown — computes per-employee short/excess totals.
   Returns { shortItems, shortAmt, excessItems, excessAmt, netCharge }
*/
function empBreakdown(empId) {
    const rows = ITEMS_DATA[empId] || [];
    let shortItems = 0, shortAmt = 0, excessItems = 0, excessAmt = 0;
    rows.forEach(r => {
        const { divided, isShort, isExcess } = getDividedSeValue(r);
        if (isShort)  { shortItems++;  shortAmt  += divided; }
        if (isExcess) { excessItems++; excessAmt += divided; }
    });
    const netCharge = shortAmt - excessAmt;
    return { shortItems, shortAmt, excessItems, excessAmt, netCharge };
}

/* ════════════════════════════════════════════════════════════════════
   ON PAGE LOAD — fill summary table + stat cards
   ════════════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function() {
    let grandShortAmt = 0, grandExcessAmt = 0;
    let grandShortCt  = 0, grandExcessCt  = 0;

    Object.keys(ITEMS_DATA).forEach(function(empId) {
        const id = parseInt(empId);
        const { shortItems, shortAmt, excessItems, excessAmt, netCharge } = empBreakdown(id);

        grandShortAmt  += shortAmt;
        grandExcessAmt += excessAmt;
        grandShortCt   += shortItems;
        grandExcessCt  += excessItems;

        /* Short items count */
        const sct = document.getElementById('sct_' + id);
        if (sct) sct.innerHTML = shortItems > 0
            ? `<span class="short-count-badge"><i class="fa-solid fa-arrow-trend-down"></i>${shortItems}</span>`
            : `<span style="color:#d1d5db;">—</span>`;

        /* Short amount */
        const sca = document.getElementById('sca_' + id);
        if (sca) sca.innerHTML = shortAmt > 0
            ? `<span class="amount-short">${f2(shortAmt)}</span>`
            : `<span style="color:#d1d5db;">—</span>`;

        /* Excess items count */
        const ect = document.getElementById('ect_' + id);
        if (ect) ect.innerHTML = excessItems > 0
            ? `<span class="excess-count-badge"><i class="fa-solid fa-arrow-trend-up"></i>${excessItems}</span>`
            : `<span style="color:#d1d5db;">—</span>`;

        /* Excess amount */
        const eca = document.getElementById('eca_' + id);
        if (eca) eca.innerHTML = excessAmt > 0
            ? `<span class="amount-excess">${f2(excessAmt)}</span>`
            : `<span style="color:#d1d5db;">—</span>`;

        /* Net charge */
        const net = document.getElementById('net_' + id);
        if (net) {
            if (Math.abs(netCharge) < 0.005) {
                net.innerHTML = `<span class="amount-net-zero">0.00</span>`;
            } else if (netCharge > 0) {
                net.innerHTML = `<span class="amount-net-positive">${f2(netCharge)}</span>`;
            } else {
                net.innerHTML = `<span class="amount-net-negative">(${f2(Math.abs(netCharge))})</span>`;
            }
        }
    });

    const grandNet = grandShortAmt - grandExcessAmt;

    /* Footer row */
    const ftSc  = document.getElementById('ft_sc');
    const ftSca = document.getElementById('ft_sca');
    const ftEc  = document.getElementById('ft_ec');
    const ftEca = document.getElementById('ft_eca');
    const ftNet = document.getElementById('ft_net');
    if (ftSc)  ftSc.innerHTML  = `<span class="short-count-badge"><i class="fa-solid fa-arrow-trend-down"></i>${grandShortCt}</span>`;
    if (ftSca) ftSca.textContent = f2(grandShortAmt);
    if (ftEc)  ftEc.innerHTML  = `<span class="excess-count-badge"><i class="fa-solid fa-arrow-trend-up"></i>${grandExcessCt}</span>`;
    if (ftEca) ftEca.textContent = f2(grandExcessAmt);
    if (ftNet) {
        if (Math.abs(grandNet) < 0.005) {
            ftNet.innerHTML = `<span class="amount-net-zero">0.00</span>`;
        } else if (grandNet > 0) {
            ftNet.innerHTML = `<strong class="amount-net-positive">${f2(grandNet)}</strong>`;
        } else {
            ftNet.innerHTML = `<strong class="amount-net-negative">(${f2(Math.abs(grandNet))})</strong>`;
        }
    }

    /* Stat cards */
    const sts  = document.getElementById('stat_total_short');
    const ste  = document.getElementById('stat_total_excess');
    const stn  = document.getElementById('stat_net_charge');
    const stns = document.getElementById('stat_net_sub');
    if (sts) sts.textContent = 'Rs. ' + f2(grandShortAmt);
    if (ste) ste.textContent = 'Rs. ' + f2(grandExcessAmt);
    if (stn) {
        stn.textContent = 'Rs. ' + f2(Math.abs(grandNet));
        stn.className   = 'sc-val ' + (grandNet > 0 ? 'red' : grandNet < 0 ? 'green' : '');
        stn.style.color = grandNet > 0 ? '#dc2626' : grandNet < 0 ? '#16a34a' : '#7c3aed';
    }
    if (stns) {
        if (grandNet > 0)       stns.textContent = 'employees still owe';
        else if (grandNet < 0)  stns.textContent = 'company owes employees';
        else                    stns.textContent  = 'fully balanced';
    }
});

/* ── Tab switch ── */
function switchTab(tab) {
    _currentTab = tab;
    document.getElementById('view_items').style.display = tab === 'items' ? '' : 'none';
    document.getElementById('view_days').style.display  = tab === 'days'  ? '' : 'none';
    document.getElementById('tab_items').classList.toggle('active', tab === 'items');
    document.getElementById('tab_days').classList.toggle('active',  tab === 'days');
}

/* ── Open modal ── */
function openModal(empId) {
    _currentEmpId = empId;
    const sum  = SUMMARY_DATA[empId];
    const rows = ITEMS_DATA[empId] || [];
    const name = sum ? dispName(sum) : ('Employee #' + empId);

    document.getElementById('modal_emp_name').textContent = name;
    document.getElementById('modal_avatar').textContent   = initials(name);
    document.getElementById('modal_emp_sub').textContent  =
        'ID #' + empId + ' · ' + DATE_FROM + ' → ' + DATE_TO;

    /* Breakdown */
    const { shortItems, shortAmt, excessItems, excessAmt, netCharge } = empBreakdown(empId);
    const days = new Set(rows.map(r => r.delivery_date)).size;

    document.getElementById('modal_days').textContent        = days;
    document.getElementById('modal_items').textContent       = rows.length;
    document.getElementById('modal_short_items').textContent = shortItems;
    document.getElementById('modal_short_amt').textContent   = 'Rs. ' + f2(shortAmt);
    document.getElementById('modal_excess_amt').textContent  = 'Rs. ' + f2(excessAmt);

    /* Net cell */
    const netEl = document.getElementById('modal_net_amt');
    if (Math.abs(netCharge) < 0.005) {
        netEl.textContent = 'Rs. 0.00';
        netEl.className = 'ms-val net-zero';
    } else if (netCharge > 0) {
        netEl.textContent = 'Rs. ' + f2(netCharge);
        netEl.className = 'ms-val net-positive';
    } else {
        netEl.textContent = '(Rs. ' + f2(Math.abs(netCharge)) + ')';
        netEl.className = 'ms-val net-negative';
    }

    /* Net bar */
    document.getElementById('nb_short').textContent = 'Rs. ' + f2(shortAmt) + ' short';
    document.getElementById('nb_excess').textContent = 'Rs. ' + f2(excessAmt) + ' excess';
    const nbNet = document.getElementById('nb_net');
    nbNet.textContent = (netCharge < 0 ? '(Rs. ' + f2(Math.abs(netCharge)) + ')' : 'Rs. ' + f2(netCharge));
    nbNet.className = 'nb-net ' + (netCharge > 0 ? 'positive' : netCharge < 0 ? 'negative' : 'zero');

    switchTab('items');
    buildItemTable(empId, rows);
    buildDayTable(empId, rows);

    document.getElementById('modal_excel_btn').onclick = () => exportDetail(empId);
    document.getElementById('detailModal').classList.add('open');
}

/* ── Build item-level table ── */
function buildItemTable(empId, rows) {
    const tbody = document.getElementById('modalItemBody');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:24px;color:#9ca3af;">No items found</td></tr>';
        document.getElementById('modalItemFoot').style.display = 'none';
        return;
    }

    let html = '', seDividedTotal = 0;

    rows.forEach((r, i) => {
        const { seVal, count, divided, isShort, isExcess } = getDividedSeValue(r);
        seDividedTotal += divided;

        const detailId  = parseInt(r.import_detail_id);
        const coList    = (CO_MAP[detailId] || []).filter(c => parseInt(c.employee_id) !== empId);
        const totalCo   = (CO_MAP[detailId] || []).filter(c => c.employee_id !== null);

        /* Type pill */
        const typePill = isShort
            ? `<span class="type-pill type-short"><i class="fa-solid fa-arrow-trend-down"></i> Short</span>`
            : isExcess
                ? `<span class="type-pill type-excess"><i class="fa-solid fa-arrow-trend-up"></i> Excess</span>`
                : `<span class="type-pill" style="background:#f5f5f5;color:#6b7280;border:1px solid #e5e5e5;">Zero</span>`;

        /* Shared badge */
        let sharedBadge = '';
        if (totalCo.length <= 1)      sharedBadge = `<span class="shared-badge shared-solo"><i class="fa-solid fa-user"></i> Solo</span>`;
        else if (totalCo.length === 2) sharedBadge = `<span class="shared-badge shared-split"><i class="fa-solid fa-user-group"></i> Split ÷2</span>`;
        else                           sharedBadge = `<span class="shared-badge shared-multi"><i class="fa-solid fa-users"></i> ÷${totalCo.length}</span>`;

        /* SE value cell */
        let seCell = '';
        if (count > 1) {
            seCell = `<span class="se-full-val" title="Full SE value">${f2(seVal)}</span>
                      <span class="se-divided-val">${f2(divided)}</span>
                      <span class="se-divide-note">${f2(seVal)} ÷ ${count}</span>
                      ${sharedBadge}`;
        } else {
            seCell = `<span class="se-divided-val">${f2(divided)}</span> ${sharedBadge}`;
        }

        /* Amount cell — color by type */
        const amtClass  = isShort ? 'color:#dc2626' : isExcess ? 'color:#16a34a' : 'color:#6b7280';
        const amtPrefix = isExcess ? '<span style="font-size:10px;color:#16a34a;">credit </span>' : '';

        /* Co-employee pills — always show name_with_initials */
        let coPills = '';
        if (coList.length > 0) {
            coPills = '<div class="co-emp-list">';
            coList.forEach(c => {
                const coName = dispName(c);
                coPills += `<span class="co-emp-pill" title="${esc(coName)}"><i class="fa-solid fa-user" style="font-size:8px;"></i> ${esc(coName)}</span>`;
            });
            coPills += '</div>';
        } else {
            coPills = '<span style="font-size:10.5px;color:#9ca3af;">—</span>';
        }

        const rowClass = isShort ? 'row-short' : isExcess ? 'row-excess' : '';

        html += `<tr class="${rowClass}">
            <td style="color:#9ca3af;font-size:11px;">${i+1}</td>
            <td>${typePill}</td>
            <td><span class="date-chip"><i class="fa-regular fa-calendar"></i>${fmtDate(r.delivery_date)}</span></td>
            <td><span class="item-id-chip">#${detailId}</span></td>
            <td style="font-size:11px;color:#374151;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(r.filename)}">${esc(r.filename||'—')}</td>
            <td class="num">${seCell}</td>
            <td class="num" style="font-weight:700;${amtClass};">${amtPrefix}${f2(divided)}</td>
            <td>${coPills}</td>
            <td><span class="payroll-chip">${esc(r.payroll_month_label||'—')}</span></td>
        </tr>`;
    });

    tbody.innerHTML = html;
    document.getElementById('modal_item_se_total').textContent  = f2(seDividedTotal);
    document.getElementById('modal_item_amt_total').textContent = f2(seDividedTotal);
    document.getElementById('modalItemFoot').style.display = '';
}

/* ── Build day-level table ── */
function buildDayTable(empId, rows) {
    const tbody = document.getElementById('modalDayBody');

    const dayMap = {};
    rows.forEach(r => {
        const key = r.delivery_date + '|' + r.import_id;
        if (!dayMap[key]) {
            dayMap[key] = {
                delivery_date:       r.delivery_date,
                import_id:           r.import_id,
                filename:            r.filename,
                payroll_month_label: r.payroll_month_label,
                items:       0,
                shortAmt:    0,
                excessAmt:   0
            };
        }
        const { divided, isShort, isExcess } = getDividedSeValue(r);
        dayMap[key].items++;
        if (isShort)  dayMap[key].shortAmt  += divided;
        if (isExcess) dayMap[key].excessAmt += divided;
    });

    const days = Object.values(dayMap).sort((a,b) => a.delivery_date.localeCompare(b.delivery_date));

    if (!days.length) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:#9ca3af;">No records</td></tr>';
        document.getElementById('modalDayFoot').style.display = 'none';
        return;
    }

    let html = '', totalItems = 0, totalShort = 0, totalExcess = 0;
    days.forEach((d, i) => {
        totalItems  += d.items;
        totalShort  += d.shortAmt;
        totalExcess += d.excessAmt;
        const net = d.shortAmt - d.excessAmt;
        const netClass = net > 0.005 ? 'color:#dc2626' : net < -0.005 ? 'color:#16a34a' : 'color:#6b7280';

        html += `<tr>
            <td style="color:#9ca3af;font-size:11px;">${i+1}</td>
            <td><span class="date-chip"><i class="fa-regular fa-calendar"></i>${fmtDate(d.delivery_date)}</span></td>
            <td style="font-size:11.5px;color:#374151;">${esc(d.filename||'—')}</td>
            <td class="num"><span class="item-badge"><i class="fa-solid fa-boxes-stacked"></i>${d.items}</span></td>
            <td class="num" style="font-weight:700;color:${d.shortAmt>0?'#dc2626':'#d1d5db'};">${d.shortAmt>0?f2(d.shortAmt):'—'}</td>
            <td class="num" style="font-weight:700;color:${d.excessAmt>0?'#16a34a':'#d1d5db'};">${d.excessAmt>0?f2(d.excessAmt):'—'}</td>
            <td class="num" style="font-weight:800;${netClass};">${net<-0.005?'('+f2(Math.abs(net))+')':f2(net)}</td>
            <td><span class="payroll-chip">${esc(d.payroll_month_label||'—')}</span></td>
        </tr>`;
    });

    tbody.innerHTML = html;
    const grandNet = totalShort - totalExcess;
    const grandNetCls = grandNet > 0.005 ? 'color:#dc2626' : grandNet < -0.005 ? 'color:#16a34a' : 'color:#6b7280';
    document.getElementById('modal_day_items_total').textContent = totalItems;
    document.getElementById('modal_day_short_total').innerHTML  = `<span style="font-weight:700;color:#dc2626;">${f2(totalShort)}</span>`;
    document.getElementById('modal_day_excess_total').innerHTML = `<span style="font-weight:700;color:#16a34a;">${f2(totalExcess)}</span>`;
    document.getElementById('modal_day_net_total').innerHTML    = `<span style="font-weight:800;${grandNetCls};">${grandNet<-0.005?'('+f2(Math.abs(grandNet))+')':f2(grandNet)}</span>`;
    document.getElementById('modalDayFoot').style.display = '';
}

/* ── Close ── */
function closeModal(){document.getElementById('detailModal').classList.remove('open');}
document.getElementById('detailModal').addEventListener('click',function(e){if(e.target===this)closeModal();});

/* ── Excel: summary — Employee column shows name_with_initials only ── */
function exportSummaryExcel() {
    const wb = XLSX.utils.book_new();
    const ws = [];
    ws.push([]);
    ws.push(['','','','Employee Charge Report — Short / Excess / Net']);
    ws.push([]);
    ws.push(['Date From', DATE_FROM, '', 'Date To', DATE_TO]);
    ws.push([]);
    ws.push(['#','Employee (Name w/ Initials)','Emp ID','Days','Total Items','Short Items','Short Charge (Rs.)','Excess Items','Excess Credit (Rs.)','Net Charge (Rs.)']);
    const arr = Object.values(SUMMARY_DATA);
    let gShort=0, gExcess=0;
    let gShortCt=0, gExcessCt=0;
    arr.forEach((r,i) => {
        const id  = parseInt(r.db_emp_id);
        const { shortItems, shortAmt, excessItems, excessAmt, netCharge } = empBreakdown(id);
        gShort    += shortAmt;   gExcess   += excessAmt;
        gShortCt  += shortItems; gExcessCt += excessItems;
        ws.push([i+1, dispName(r), r.db_emp_id, r.import_count, r.item_count,
            shortItems, shortAmt, excessItems, excessAmt, netCharge]);
    });
    ws.push([]);
    ws.push(['','TOTAL','',
        arr.reduce((s,r)=>s+parseInt(r.import_count||0),0),
        arr.reduce((s,r)=>s+parseInt(r.item_count||0),0),
        gShortCt, gShort, gExcessCt, gExcess, gShort-gExcess
    ]);
    const sheet = XLSX.utils.aoa_to_sheet(ws);
    sheet['!cols']=[{wch:5},{wch:42},{wch:10},{wch:8},{wch:12},{wch:12},{wch:20},{wch:12},{wch:22},{wch:18}];
    XLSX.utils.book_append_sheet(wb, sheet, 'Summary');
    XLSX.writeFile(wb,'Employee_Charge_Report_'+DATE_FROM.replace(/ /g,'_')+'_to_'+DATE_TO.replace(/ /g,'_')+'.xlsx');
}

/* ── Excel: per-employee item detail — name_with_initials only ── */
function exportDetail(empId) {
    const sum  = SUMMARY_DATA[empId];
    const rows = ITEMS_DATA[empId] || [];
    if (!sum) return;

    const empDisplayName = dispName(sum);
    const wb = XLSX.utils.book_new();

    /* Sheet 1: Items */
    const ws1 = [];
    ws1.push([]);
    ws1.push(['','Employee Charge Detail — By Item (Short / Excess)']);
    ws1.push([]);
    ws1.push(['Employee', empDisplayName, '', 'ID #' + empId]);
    ws1.push(['Date From', DATE_FROM, '', 'Date To', DATE_TO]);
    ws1.push([]);
    ws1.push(['#','Type','Delivery Date','Item ID','Import File',
              'Full SE Value (Rs.)','SE ÷ Employees','This Employee Share (Rs.)',
              'Employee Count','Shared With','Payroll Month']);
    let shortTotal=0, excessTotal=0;
    rows.forEach((r,i) => {
        const { seVal, count, divided, isShort, isExcess } = getDividedSeValue(r);
        if (isShort)  shortTotal  += divided;
        if (isExcess) excessTotal += divided;
        const coList = (CO_MAP[r.import_detail_id]||[])
            .filter(c=>parseInt(c.employee_id)!==empId)
            .map(c=>dispName(c))
            .join(', ');
        ws1.push([
            i+1,
            isShort ? 'Short' : isExcess ? 'Excess' : 'Zero',
            r.delivery_date, r.import_detail_id, r.filename,
            seVal, divided, divided, count,
            coList||'Solo', r.payroll_month_label||''
        ]);
    });
    ws1.push([]);
    ws1.push(['','','','','Total','','Short Total:', shortTotal,'Excess Total:',excessTotal,'Net:', shortTotal-excessTotal]);
    const sheet1 = XLSX.utils.aoa_to_sheet(ws1);
    sheet1['!cols']=[{wch:5},{wch:8},{wch:13},{wch:10},{wch:24},{wch:18},{wch:14},{wch:22},{wch:14},{wch:38},{wch:20}];
    XLSX.utils.book_append_sheet(wb, sheet1, 'By Item');

    /* Sheet 2: By Day */
    const ws2 = [];
    ws2.push([]);
    ws2.push(['','Employee Charge Detail — By Delivery Day (Short / Excess / Net)']);
    ws2.push([]);
    ws2.push(['Employee', empDisplayName]);
    ws2.push([]);
    ws2.push(['#','Delivery Date','Import File','Items','Short Charge (Rs.)','Excess Credit (Rs.)','Net Charge (Rs.)','Payroll Month']);
    const dayMap = {};
    rows.forEach(r => {
        const key = r.delivery_date+'|'+r.import_id;
        if(!dayMap[key]) dayMap[key]={delivery_date:r.delivery_date,filename:r.filename,payroll_month_label:r.payroll_month_label,items:0,shortAmt:0,excessAmt:0};
        const { divided, isShort, isExcess } = getDividedSeValue(r);
        dayMap[key].items++;
        if(isShort)  dayMap[key].shortAmt  += divided;
        if(isExcess) dayMap[key].excessAmt += divided;
    });
    let dayTotalItems=0, dayTotalShort=0, dayTotalExcess=0;
    Object.values(dayMap).sort((a,b)=>a.delivery_date.localeCompare(b.delivery_date)).forEach((d,i)=>{
        dayTotalItems  += d.items;
        dayTotalShort  += d.shortAmt;
        dayTotalExcess += d.excessAmt;
        ws2.push([i+1, d.delivery_date, d.filename, d.items,
            d.shortAmt, d.excessAmt, d.shortAmt-d.excessAmt, d.payroll_month_label||'']);
    });
    ws2.push([]);
    ws2.push(['','','Total', dayTotalItems, dayTotalShort, dayTotalExcess, dayTotalShort-dayTotalExcess,'']);
    const sheet2 = XLSX.utils.aoa_to_sheet(ws2);
    sheet2['!cols']=[{wch:5},{wch:13},{wch:26},{wch:8},{wch:20},{wch:20},{wch:18},{wch:20}];
    XLSX.utils.book_append_sheet(wb, sheet2, 'By Day');

    XLSX.writeFile(wb,'Charge_Detail_'+String(empDisplayName||'').replace(/\s+/g,'_')+'.xlsx');
}
</script>
<?php endif; ?>

<?php include 'footer.php'; ?>