<?php
include 'config.php';
include 'header.php';

$sr_code      = trim($_GET['sr_code']      ?? '');
$date         = trim($_GET['date']         ?? '');
$date_from    = trim($_GET['date_from']    ?? $date);
$date_to      = trim($_GET['date_to']      ?? $date);
$type         = trim($_GET['type']         ?? ''); // 'bank' | 'bo' | '' = all
$collected_by = strtolower(trim($_GET['collected_by'] ?? '')); // 'sr' | 'cc' | '' = all

// Support both ?date= (single) and ?date_from=&date_to= (range)
if (!$date_from) $date_from = $date;
if (!$date_to)   $date_to   = $date_from;

if (!$sr_code || !$date_from) {
    echo '<div style="padding:40px;text-align:center;color:#9ca3af;">Missing parameters.</div>';
    include 'footer.php'; exit;
}

$sr_esc        = mysqli_real_escape_string($conn, $sr_code);
$date_from_esc = mysqli_real_escape_string($conn, $date_from);
$date_to_esc   = mysqli_real_escape_string($conn, $date_to);
$col_by_esc    = mysqli_real_escape_string($conn, $collected_by);

// For backward compat — keep $date for any legacy references
$date     = $date_from;
$is_range = ($date_from !== $date_to);

$type_where   = '';
if ($type === 'bank') $type_where  = " AND d.handed_over_bo = 0";
if ($type === 'bo')   $type_where  = " AND d.handed_over_bo = 1";

$col_by_where = '';
if ($collected_by === 'sr') $col_by_where = " AND LOWER(COALESCE(d.collected_by,'')) = 'sr'";
if ($collected_by === 'cc') $col_by_where = " AND LOWER(COALESCE(d.collected_by,'')) = 'cc'";

/*
 * KEY CHANGE: Filter by r.delivery_date (per-rep row) instead of d.delivery_date (parent).
 * Also fetch r.delivery_date per rep row so we can display it in the breakdown table.
 */
$sql = "
    SELECT
        d.id, d.deposit_date, d.cash_receive_date,
        d.amount, d.handed_over_bo, d.collected_by, d.remark, d.created_at,
        COALESCE(NULLIF(e.name_with_initials,''), e.employee_full_name) AS emp_name,
        COALESCE(NULLIF(e.employee_id,''), CONCAT('EMP-', e.id))        AS emp_code,
        CONCAT(
            COALESCE(NULLIF(b.bank_name,''), cba.bank_code, ''), ' / ',
            COALESCE(NULLIF(bb.branch_name,''), cba.branch_code, ''),
            ' (', COALESCE(cba.account_no,''), ')'
        ) AS bank_label,
        /* rep_code | amount | delivery_date per row */
        (SELECT GROUP_CONCAT(r.rep_code,'|',r.amount,'|',COALESCE(r.delivery_date,'')
                             ORDER BY r.sort_order SEPARATOR ';;')
         FROM cc_cash_deposit_reps r WHERE r.deposit_id = d.id) AS reps_data,
        /* earliest delivery_date among matching rep rows — used as the card's delivery date */
        (SELECT MIN(r2.delivery_date)
         FROM cc_cash_deposit_reps r2
         WHERE r2.deposit_id = d.id
           AND r2.rep_code = '$sr_esc'
           AND r2.delivery_date BETWEEN '$date_from_esc' AND '$date_to_esc') AS delivery_date,
        (SELECT COUNT(*) FROM cc_cash_deposit_attachments a WHERE a.deposit_id = d.id) AS attach_count
    FROM cc_cash_deposits d
    LEFT JOIN employees e               ON e.id = d.employee_id
    LEFT JOIN company_bank_accounts cba ON cba.id = d.bank_account_id
    LEFT JOIN banks b                   ON b.bank_code  = cba.bank_code
    LEFT JOIN bank_branches bb          ON bb.bank_code = cba.bank_code AND bb.branch_code = cba.branch_code
    WHERE EXISTS (
        SELECT 1 FROM cc_cash_deposit_reps r2
        WHERE r2.deposit_id  = d.id
          AND r2.rep_code    = '$sr_esc'
          AND r2.delivery_date BETWEEN '$date_from_esc' AND '$date_to_esc'
    )
    $type_where
    $col_by_where
    ORDER BY delivery_date ASC, d.id ASC";

$res      = mysqli_query($conn, $sql);
$deposits = [];
if ($res) while ($row = mysqli_fetch_assoc($res)) $deposits[] = $row;

$attach_map = [];
if (!empty($deposits)) {
    $ids = implode(',', array_map('intval', array_column($deposits, 'id')));
    $ar  = mysqli_query($conn, "SELECT id, deposit_id, filename, original_name, file_size
                                FROM cc_cash_deposit_attachments
                                WHERE deposit_id IN ($ids) ORDER BY id ASC");
    if ($ar) while ($ar_row = mysqli_fetch_assoc($ar))
        $attach_map[$ar_row['deposit_id']][] = $ar_row;
}

$total_banked = 0;
$total_bo     = 0;
foreach ($deposits as $d) {
    if (intval($d['handed_over_bo']) === 1) $total_bo     += floatval($d['amount']);
    else                                     $total_banked += floatval($d['amount']);
}
$grand_total = $total_banked + $total_bo;

$type_label = match($type) {
    'bank'  => 'Bank Deposits',
    'bo'    => 'Handed to BO',
    default => 'All Deposits',
};

$cb_label = match($collected_by) {
    'sr'    => 'Sales Rep (SR)',
    'cc'    => 'Cash Collector (CC)',
    default => '',
};

function fmt_date_range($df, $dt, $is_range) {
    if ($is_range) return date('d M Y', strtotime($df)) . ' &ndash; ' . date('d M Y', strtotime($dt));
    return date('d M Y', strtotime($df));
}

function isValidDate2($d) {
    return !empty($d) && $d !== '0000-00-00' && strtotime($d) > 0;
}
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');
:root{
    --bg:#eef0f3;--surface:#fff;--bdr:#d2d6db;--bdrs:#e4e7ec;
    --tx:#1a1f2e;--txm:#58626e;--txs:#9aa3ad;
    --fn:'Inter',sans-serif;--mn:'JetBrains Mono',monospace;
    --r:10px;--sh:0 1px 3px rgba(0,0,0,.07),0 4px 14px rgba(0,0,0,.05);
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:13px;}
.pg{padding:24px 20px 60px;}

/* ── Topbar ── */
.topbar{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:22px;flex-wrap:wrap;}
.topbar-left{flex:1;min-width:0;}
.topbar-right{display:flex;gap:8px;flex-wrap:wrap;align-items:center;flex-shrink:0;}
.back-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#fff;border:1px solid var(--bdr);border-radius:7px;font-size:12px;font-weight:600;color:var(--txm);text-decoration:none;transition:all .15s;white-space:nowrap;}
.back-btn:hover{background:#f5f5f5;color:var(--tx);}
.btn-print{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:7px;font-size:12px;font-weight:600;color:#475569;cursor:pointer;font-family:inherit;white-space:nowrap;transition:all .15s;}
.btn-print:hover{background:#e2e8f0;}
.pg-badge{display:inline-flex;align-items:center;gap:7px;background:#1e1b4b;color:#e0e7ff;border-radius:8px;padding:5px 14px;font-size:11px;font-weight:700;letter-spacing:.04em;margin-bottom:8px;}
.pg-h1{font-size:22px;font-weight:800;letter-spacing:-.02em;line-height:1.2;}
.pg-h1 em{font-style:normal;color:#1e40af;}
.pg-sub{font-size:12px;color:var(--txs);margin-top:6px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.chip{display:inline-flex;align-items:center;gap:5px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:3px 10px;font-size:11px;font-weight:700;color:#334155;}
.chip.bank{background:#dbeafe;border-color:#93c5fd;color:#1e40af;}
.chip.bo{background:#ede9fe;border-color:#c4b5fd;color:#5b21b6;}
.chip.count{background:#f0fdf4;border-color:#86efac;color:#166534;}
.chip.cb-sr{background:#fef3c7;border-color:#fde68a;color:#92400e;}
.chip.cb-cc{background:#d1fae5;border-color:#6ee7b7;color:#065f46;}
.chip.range{background:#fdf4ff;border-color:#e9d5ff;color:#6b21a8;}

/* ── Stat cards ── */
.stat-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px;}
.stat-card{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:16px 18px;box-shadow:var(--sh);position:relative;overflow:hidden;}
.stat-card::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;}
.stat-card.blue::before{background:#3b82f6;}
.stat-card.purple::before{background:#7c3aed;}
.stat-card.green::before{background:#22c55e;}
.stat-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--txs);margin-bottom:7px;display:flex;align-items:center;gap:5px;}
.stat-val{font-size:19px;font-weight:800;font-family:var(--mn);line-height:1;}
.stat-card.blue   .stat-val{color:#1e40af;}
.stat-card.purple .stat-val{color:#5b21b6;}
.stat-card.green  .stat-val{color:#166534;}
.stat-note{font-size:10px;color:var(--txs);margin-top:5px;}

/* ── Deposit card ── */
.dep-card{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);box-shadow:var(--sh);margin-bottom:16px;overflow:hidden;}
.dep-card-header{display:flex;justify-content:space-between;align-items:center;padding:12px 20px;background:#f8fafc;border-bottom:1px solid var(--bdr);flex-wrap:wrap;gap:10px;}
.dep-card-header-left{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.dep-card-num{font-size:12px;font-weight:700;color:var(--txs);font-family:var(--mn);background:#e2e8f0;border-radius:5px;padding:2px 8px;}
.dep-type-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:10px;font-size:11px;font-weight:700;}
.badge-bank{background:#dbeafe;color:#1e40af;}
.badge-bo{background:#ede9fe;color:#5b21b6;}
.badge-cb-sr{background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:9px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:4px;}
.badge-cb-cc{background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:9px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:4px;}
.badge-cb-none{background:#f3f4f6;color:#9ca3af;padding:3px 10px;border-radius:9px;font-size:10px;font-weight:600;display:inline-flex;align-items:center;gap:4px;}
.dep-total{font-size:15px;font-weight:800;font-family:var(--mn);}
.dep-total.bank{color:#1e40af;}
.dep-total.bo{color:#5b21b6;}

.dep-card-body{padding:18px 20px;}

/* info grid */
.dep-info-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:16px;}
.dep-info-cell{display:flex;flex-direction:column;gap:4px;}
.dil{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--txs);display:flex;align-items:center;gap:4px;}
.div{font-size:13px;font-weight:600;color:var(--tx);}
.div.mono{font-family:var(--mn);}

/* section label */
.section-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--txs);margin-bottom:8px;display:flex;align-items:center;gap:5px;padding-bottom:5px;border-bottom:1px solid var(--bdrs);}

/* reps table */
.rep-table{width:100%;border-collapse:collapse;font-size:12.5px;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;}
.rep-table thead th{padding:7px 12px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#fff;background:#1e3a8a;text-align:left;}
.rep-table thead th.tr{text-align:right;}
.rep-table tbody td{padding:8px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.rep-table tbody tr:last-child td{border-bottom:none;}
.rep-table tbody tr:nth-child(even) td{background:#f8fafc;}
.rep-code-pill{background:#dbeafe;color:#1e40af;border-radius:5px;padding:2px 9px;font-size:11px;font-weight:700;display:inline-block;}
.rep-table td.tr{text-align:right;font-weight:700;font-family:var(--mn);color:#1e40af;}
.rep-del-chip{display:inline-flex;align-items:center;gap:4px;background:#fef9c3;color:#713f12;border:1px solid #fde68a;border-radius:5px;padding:1px 7px;font-size:10px;font-weight:600;white-space:nowrap;font-family:var(--mn);}
.rep-table tfoot td{padding:7px 12px;background:#1e3a8a;}

/* remark */
.remark-box{background:#fffbeb;border:1px solid #fde68a;border-radius:7px;padding:10px 14px;font-size:12px;color:#78350f;display:flex;align-items:flex-start;gap:8px;}
.remark-box i{color:#f59e0b;margin-top:1px;flex-shrink:0;}

/* attachments */
.attach-section{border-top:1px solid var(--bdrs);padding-top:14px;margin-top:14px;}
.attach-grid{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;}
.attach-item{display:inline-flex;align-items:center;gap:8px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:7px;padding:7px 13px;font-size:12px;font-weight:600;color:#15803d;text-decoration:none;transition:all .15s;}
.attach-item:hover{background:#dcfce7;border-color:#86efac;box-shadow:0 0 0 2px rgba(21,128,61,.12);}
.attach-item i{font-size:14px;}
.attach-size{font-size:10px;color:#86efac;font-weight:400;margin-left:2px;}

/* meta */
.dep-meta{margin-top:14px;padding-top:10px;border-top:1px solid var(--bdrs);display:flex;align-items:center;gap:6px;font-size:10.5px;color:var(--txs);}

/* card index divider */
.card-index-label{font-size:10px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
.card-index-label::after{content:'';flex:1;height:1px;background:var(--bdrs);}

/* delivery date badge inside card */
.del-date-badge{display:inline-flex;align-items:center;gap:5px;background:#f0fdf4;border:1px solid #86efac;border-radius:7px;padding:2px 9px;font-size:10px;font-weight:700;color:#166534;font-family:var(--mn);}

/* empty */
.empty{text-align:center;padding:70px 20px;color:var(--txs);}
.empty i{font-size:44px;display:block;margin-bottom:14px;opacity:.2;}
.empty-title{font-size:15px;font-weight:700;margin-bottom:6px;color:var(--txm);}

/* summary bar */
.summary-bar{background:#0f172a;border-radius:var(--r);padding:16px 22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:8px;}
.sum-info{color:#94a3b8;font-size:12px;font-weight:600;display:flex;align-items:center;gap:7px;flex-wrap:wrap;}
.sum-cols{display:flex;gap:22px;flex-wrap:wrap;align-items:center;}
.sum-col{text-align:right;}
.sum-col-lbl{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.sum-col-val{font-size:16px;font-weight:800;font-family:var(--mn);}
.sum-col.bank .sum-col-lbl{color:#60a5fa;}.sum-col.bank .sum-col-val{color:#93c5fd;}
.sum-col.bo   .sum-col-lbl{color:#a78bfa;}.sum-col.bo   .sum-col-val{color:#c4b5fd;}
.sum-col.total{padding-left:16px;border-left:1px solid #334155;}
.sum-col.total .sum-col-lbl{color:#34d399;}.sum-col.total .sum-col-val{font-size:20px;color:#6ee7b7;}

@media(max-width:900px){.dep-info-grid{grid-template-columns:repeat(3,1fr);}}
@media(max-width:720px){.dep-info-grid{grid-template-columns:1fr 1fr;}.stat-row{grid-template-columns:1fr;}}
@media(max-width:480px){.dep-info-grid{grid-template-columns:1fr;}}
@media print{
    .no-print{display:none!important;}
    .dep-card{break-inside:avoid;box-shadow:none;border:1px solid #ccc;}
    body{background:#fff;}
    .pg{padding:10px;}
    .stat-row,.summary-bar{display:none;}
    .print-header{display:block!important;}
}
</style>

<div class="pg">

<!-- Print header -->
<div class="print-header" style="display:none;margin-bottom:16px;">
    <div style="font-size:17px;font-weight:800;margin-bottom:3px;">CC Deposit History &mdash; <?php echo htmlspecialchars($sr_code); ?></div>
    <div style="font-size:12px;color:#555;">
        <?php if ($is_range): ?>
            Period: <?php echo date('d M Y', strtotime($date_from)); ?> &ndash; <?php echo date('d M Y', strtotime($date_to)); ?>
        <?php else: ?>
            Delivery Date: <?php echo date('d M Y', strtotime($date_from)); ?>
        <?php endif; ?>
        &nbsp;|&nbsp; Type: <?php echo htmlspecialchars($type_label); ?>
        <?php if ($cb_label): ?> &nbsp;|&nbsp; Collected By: <?php echo htmlspecialchars($cb_label); ?><?php endif; ?>
        &nbsp;|&nbsp; Records: <?php echo count($deposits); ?>
    </div>
    <hr style="margin:10px 0;border:none;border-top:2px solid #1e40af;">
</div>

<!-- Topbar -->
<div class="topbar no-print">
    <div class="topbar-left">
        <div class="pg-badge"><i class="fa-solid fa-building-columns"></i> CC Deposit History</div>
        <h1 class="pg-h1">Deposit History &mdash; <em><?php echo htmlspecialchars($sr_code); ?></em></h1>
        <div class="pg-sub">
            <span class="chip <?php echo $is_range ? 'range' : ''; ?>">
                <i class="fa-solid fa-<?php echo $is_range ? 'calendar-week' : 'calendar-day'; ?>"></i>
                <?php echo fmt_date_range($date_from, $date_to, $is_range); ?>
            </span>
            <span class="chip"><i class="fa-solid fa-id-badge"></i> <?php echo htmlspecialchars($sr_code); ?></span>
            <?php if ($type === 'bank'): ?>
                <span class="chip bank"><i class="fa-solid fa-building-columns"></i> Bank Deposits Only</span>
            <?php elseif ($type === 'bo'): ?>
                <span class="chip bo"><i class="fa-solid fa-hand-holding-dollar"></i> Handed to BO Only</span>
            <?php endif; ?>
            <?php if ($collected_by === 'sr'): ?>
                <span class="chip cb-sr"><i class="fa-solid fa-person-walking"></i> Collected by SR</span>
            <?php elseif ($collected_by === 'cc'): ?>
                <span class="chip cb-cc"><i class="fa-solid fa-user-tie"></i> Collected by CC</span>
            <?php endif; ?>
            <span class="chip count"><i class="fa-solid fa-layer-group"></i>
                <?php echo count($deposits); ?> record<?php echo count($deposits) != 1 ? 's' : ''; ?>
            </span>
        </div>
    </div>
    <div class="topbar-right">
        <button onclick="window.print()" class="btn-print"><i class="fa-solid fa-print"></i> Print</button>
        <a href="javascript:history.back()" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>
</div>

<?php if (empty($deposits)): ?>
<div class="empty">
    <i class="fa-solid fa-building-columns"></i>
    <div class="empty-title">No Deposits Found</div>
    <p>No <?php echo strtolower($type_label); ?> records<?php echo $cb_label ? ' collected by <strong>'.htmlspecialchars($cb_label).'</strong>' : ''; ?> found for
       <strong><?php echo htmlspecialchars($sr_code); ?></strong>
       <?php if ($is_range): ?>
           from <strong><?php echo date('d M Y', strtotime($date_from)); ?></strong> to <strong><?php echo date('d M Y', strtotime($date_to)); ?></strong>.
       <?php else: ?>
           on <?php echo date('d M Y', strtotime($date_from)); ?>.
       <?php endif; ?>
    </p>
    <br>
    <a href="javascript:history.back()"
       style="display:inline-flex;align-items:center;gap:6px;padding:9px 20px;background:#1e40af;
              color:#fff;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;margin-top:6px;">
        <i class="fa-solid fa-arrow-left"></i> Go Back
    </a>
</div>

<?php else: ?>

<!-- Stat Cards -->
<?php
$cnt_bank = count(array_filter($deposits, fn($d) => intval($d['handed_over_bo']) === 0));
$cnt_bo   = count(array_filter($deposits, fn($d) => intval($d['handed_over_bo']) === 1));
?>
<div class="stat-row no-print">
    <div class="stat-card blue">
        <div class="stat-lbl"><i class="fa-solid fa-building-columns"></i> Bank Deposited</div>
        <div class="stat-val">Rs. <?php echo number_format($total_banked, 2); ?></div>
        <div class="stat-note"><?php echo $cnt_bank; ?> bank deposit record<?php echo $cnt_bank != 1 ? 's' : ''; ?></div>
    </div>
    <div class="stat-card purple">
        <div class="stat-lbl"><i class="fa-solid fa-hand-holding-dollar"></i> Handed to BO</div>
        <div class="stat-val">Rs. <?php echo number_format($total_bo, 2); ?></div>
        <div class="stat-note"><?php echo $cnt_bo; ?> BO handover record<?php echo $cnt_bo != 1 ? 's' : ''; ?></div>
    </div>
    <div class="stat-card green">
        <div class="stat-lbl"><i class="fa-solid fa-sigma"></i> Grand Total</div>
        <div class="stat-val">Rs. <?php echo number_format($grand_total, 2); ?></div>
        <div class="stat-note">
            <?php echo count($deposits); ?> total record<?php echo count($deposits) != 1 ? 's' : ''; ?>
            <?php if ($cb_label): ?>
                &nbsp;·&nbsp; <?php echo htmlspecialchars($cb_label); ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Deposit Cards -->
<?php foreach ($deposits as $idx => $d):
    $isBO        = intval($d['handed_over_bo']) === 1;
    $attachments = $attach_map[$d['id']] ?? [];
    $cb_row      = strtolower($d['collected_by'] ?? '');

    /*
     * Parse reps_data — now format: rep_code|amount|delivery_date
     * Show delivery_date chip per rep row in the breakdown table.
     */
    $reps = [];
    if (!empty($d['reps_data'])) {
        foreach (explode(';;', $d['reps_data']) as $rp) {
            $parts = array_pad(explode('|', $rp, 3), 3, '');
            [$rc, $am, $rdd] = $parts;
            if (trim($rc) !== '') $reps[] = [
                'code'         => trim($rc),
                'amount'       => floatval($am),
                'delivery_date'=> trim($rdd),
            ];
        }
    }

    /* destination display */
    if ($isBO) {
        $dest_icon  = 'fa-user';
        $dest_label = 'Handed To';
        $dest_val   = trim(($d['emp_code'] ?? '') . ' — ' . ($d['emp_name'] ?? ''), ' — ') ?: '—';
        $dest_color = '#5b21b6';
    } else {
        $dest_icon  = 'fa-building-columns';
        $dest_label = 'Bank Account';
        $dest_val   = trim($d['bank_label'] ?? '', " \t\n\r/") ?: '—';
        $dest_color = '#1e40af';
    }

    /* collected_by badge */
    if ($cb_row === 'sr')
        $cb_badge = '<span class="badge-cb-sr"><i class="fa-solid fa-person-walking"></i> SR</span>';
    elseif ($cb_row === 'cc')
        $cb_badge = '<span class="badge-cb-cc"><i class="fa-solid fa-user-tie"></i> CC</span>';
    else
        $cb_badge = '<span class="badge-cb-none"><i class="fa-solid fa-circle-question"></i> —</span>';

    /* date labels: bank deposits show Deposit Date; BO shows Cash Receive Date */
    $date2_label = $isBO ? 'Cash Recv. Date' : 'Deposit Date';
    $date2_value = $isBO
        ? (isValidDate2($d['cash_receive_date']) ? date('d M Y', strtotime($d['cash_receive_date'])) : '—')
        : (isValidDate2($d['deposit_date'])      ? date('d M Y', strtotime($d['deposit_date']))      : '—');

    /* delivery_date for this card = the matching rep row's delivery_date (from subquery) */
    $card_del_date = isValidDate2($d['delivery_date'] ?? '') ? $d['delivery_date'] : null;

    /* check if this deposit has reps for OTHER dates too (multi-date deposit) */
    $has_multi_dates = count(array_unique(array_filter(
        array_column($reps, 'delivery_date'),
        fn($x) => !empty($x) && $x !== '0000-00-00'
    ))) > 1;
?>

<div class="card-index-label">Record <?php echo $idx + 1; ?> of <?php echo count($deposits); ?></div>

<div class="dep-card">
    <!-- Header -->
    <div class="dep-card-header">
        <div class="dep-card-header-left">
            <span class="dep-card-num"># <?php echo intval($d['id']); ?></span>
            <?php if ($card_del_date): ?>
                <span class="del-date-badge">
                    <i class="fa-solid fa-calendar-day"></i>
                    <?php echo date('d M Y', strtotime($card_del_date)); ?>
                </span>
            <?php endif; ?>
            <span class="dep-type-badge <?php echo $isBO ? 'badge-bo' : 'badge-bank'; ?>">
                <i class="fa-solid <?php echo $isBO ? 'fa-hand-holding-dollar' : 'fa-building-columns'; ?>"></i>
                <?php echo $isBO ? 'Handed to BO' : 'Bank Deposit'; ?>
            </span>
            <?php echo $cb_badge; ?>
            <?php if (!empty($attachments)): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:#f0fdf4;border:1px solid #bbf7d0;
                         border-radius:8px;padding:3px 9px;font-size:10px;font-weight:700;color:#15803d;">
                <i class="fa-solid fa-paperclip"></i>
                <?php echo count($attachments); ?> slip<?php echo count($attachments) != 1 ? 's' : ''; ?>
            </span>
            <?php endif; ?>
        </div>
        <span class="dep-total <?php echo $isBO ? 'bo' : 'bank'; ?>">
            Rs. <?php echo number_format(floatval($d['amount']), 2); ?>
        </span>
    </div>

    <!-- Body -->
    <div class="dep-card-body">

        <!-- Info Grid (5 columns) -->
        <div class="dep-info-grid">
            <div class="dep-info-cell">
                <span class="dil"><i class="fa-solid fa-calendar-day"></i> Delivery Date</span>
                <span class="div mono">
                    <?php echo $card_del_date ? date('d M Y', strtotime($card_del_date)) : '—'; ?>
                </span>
            </div>
            <div class="dep-info-cell">
                <span class="dil"><i class="fa-solid fa-calendar-check"></i> <?php echo htmlspecialchars($date2_label); ?></span>
                <span class="div mono"><?php echo $date2_value; ?></span>
            </div>
            <div class="dep-info-cell">
                <span class="dil"><i class="fa-solid fa-person-walking-arrow-right"></i> Collected By</span>
                <span class="div">
                    <?php if ($cb_row === 'sr'): ?>
                        <span style="color:#92400e;font-weight:700;">Sales Rep (SR)</span>
                    <?php elseif ($cb_row === 'cc'): ?>
                        <span style="color:#065f46;font-weight:700;">Cash Collector (CC)</span>
                    <?php else: ?>
                        <span style="color:var(--txs);">—</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="dep-info-cell">
                <span class="dil"><i class="fa-solid <?php echo $dest_icon; ?>"></i> <?php echo htmlspecialchars($dest_label); ?></span>
                <span class="div" style="color:<?php echo $dest_color; ?>;">
                    <?php echo htmlspecialchars($dest_val); ?>
                </span>
            </div>
            <div class="dep-info-cell">
                <span class="dil"><i class="fa-solid fa-coins"></i> Total Amount</span>
                <span class="div mono" style="color:<?php echo $dest_color; ?>;font-size:15px;font-weight:800;">
                    Rs. <?php echo number_format(floatval($d['amount']), 2); ?>
                </span>
            </div>
        </div>

        <!-- Sales Reps Breakdown — now shows per-rep delivery date -->
        <?php if (!empty($reps)): ?>
        <div style="margin-bottom:14px;">
            <div class="section-label">
                <i class="fa-solid fa-users"></i> Sales Rep Breakdown
                <?php if ($has_multi_dates): ?>
                    <span style="font-size:9px;font-weight:600;color:#d97706;background:#fef3c7;border:1px solid #fde68a;
                                 border-radius:4px;padding:1px 6px;margin-left:4px;text-transform:none;letter-spacing:0;">
                        multiple delivery dates
                    </span>
                <?php endif; ?>
            </div>
            <table class="rep-table">
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <th>Sales Rep</th>
                        <th>Delivery Date</th>
                        <th class="tr">Amount (Rs.)</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reps as $ri => $rep): ?>
                <tr>
                    <td style="color:var(--txs);font-size:11px;"><?php echo $ri + 1; ?></td>
                    <td><span class="rep-code-pill"><?php echo htmlspecialchars($rep['code']); ?></span></td>
                    <td>
                        <?php if (isValidDate2($rep['delivery_date'])): ?>
                            <span class="rep-del-chip">
                                <i class="fa-solid fa-calendar-day"></i>
                                <?php echo date('d M Y', strtotime($rep['delivery_date'])); ?>
                            </span>
                        <?php else: ?>
                            <span style="color:var(--txs);font-size:11px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="tr"><?php echo number_format($rep['amount'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if (count($reps) > 1): ?>
                <tfoot>
                    <tr>
                        <td colspan="3" style="padding:7px 12px;font-size:11px;font-weight:700;color:#93c5fd;">Total</td>
                        <td style="padding:7px 12px;text-align:right;font-weight:800;font-family:var(--mn);color:#60a5fa;">
                            <?php echo number_format(array_sum(array_column($reps, 'amount')), 2); ?>
                        </td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
        <?php endif; ?>

        <!-- Remark -->
        <?php if (!empty(trim($d['remark'] ?? ''))): ?>
        <div style="margin-bottom:12px;">
            <div class="section-label"><i class="fa-solid fa-note-sticky"></i> Remark</div>
            <div class="remark-box">
                <i class="fa-solid fa-quote-left"></i>
                <?php echo nl2br(htmlspecialchars($d['remark'])); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Attachments -->
        <?php if (!empty($attachments)): ?>
        <div class="attach-section">
            <div class="section-label">
                <i class="fa-solid fa-paperclip"></i>
                Slip Attachments (<?php echo count($attachments); ?>)
            </div>
            <div class="attach-grid">
                <?php foreach ($attachments as $att):
                    $ext      = strtolower(pathinfo($att['original_name'], PATHINFO_EXTENSION));
                    $ico      = match(true) {
                        in_array($ext, ['jpg','jpeg','png','gif','webp']) => 'fa-file-image',
                        $ext === 'pdf' => 'fa-file-pdf',
                        default        => 'fa-file',
                    };
                    $size_str = $att['file_size'] >= 1048576
                        ? round($att['file_size'] / 1048576, 1).' MB'
                        : round($att['file_size'] / 1024).' KB';
                ?>
                <a class="attach-item"
                   href="uploads/cc_deposits/<?php echo urlencode($att['filename']); ?>"
                   target="_blank">
                    <i class="fa-solid <?php echo $ico; ?>"></i>
                    <?php echo htmlspecialchars($att['original_name']); ?>
                    <span class="attach-size">(<?php echo $size_str; ?>)</span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Created timestamp -->
        <div class="dep-meta">
            <i class="fa-solid fa-clock"></i>
            Created: <?php echo date('d M Y, h:i A', strtotime($d['created_at'])); ?>
        </div>

    </div><!-- /dep-card-body -->
</div><!-- /dep-card -->

<?php endforeach; ?>

<!-- Summary Bar -->
<div class="summary-bar">
    <div class="sum-info">
        <i class="fa-solid fa-sigma" style="color:#60a5fa;"></i>
        Summary &mdash;
        <span style="color:#e0e7ff;font-weight:700;"><?php echo count($deposits); ?></span> record<?php echo count($deposits) != 1 ? 's' : ''; ?>
        &nbsp;for&nbsp;
        <span style="color:#e0e7ff;font-weight:700;"><?php echo htmlspecialchars($sr_code); ?></span>
        &nbsp;
        <span style="color:#e0e7ff;font-family:var(--mn);">
            <?php echo fmt_date_range($date_from, $date_to, $is_range); ?>
        </span>
        <?php if ($cb_label): ?>
            &nbsp;&middot;&nbsp;
            <span style="color:<?php echo $collected_by==='cc'?'#6ee7b7':'#fde68a';?>;font-weight:700;">
                <?php echo htmlspecialchars($cb_label); ?>
            </span>
        <?php endif; ?>
    </div>
    <div class="sum-cols">
        <?php if ($total_banked > 0): ?>
        <div class="sum-col bank">
            <div class="sum-col-lbl"><i class="fa-solid fa-building-columns"></i> Bank Deposited</div>
            <div class="sum-col-val">Rs. <?php echo number_format($total_banked, 2); ?></div>
        </div>
        <?php endif; ?>
        <?php if ($total_bo > 0): ?>
        <div class="sum-col bo">
            <div class="sum-col-lbl"><i class="fa-solid fa-hand-holding-dollar"></i> Handed to BO</div>
            <div class="sum-col-val">Rs. <?php echo number_format($total_bo, 2); ?></div>
        </div>
        <?php endif; ?>
        <div class="sum-col total">
            <div class="sum-col-lbl"><i class="fa-solid fa-sigma"></i> Grand Total</div>
            <div class="sum-col-val">Rs. <?php echo number_format($grand_total, 2); ?></div>
        </div>
    </div>
</div>

<?php endif; ?>
</div><!-- /pg -->

<?php include 'footer.php'; ?>