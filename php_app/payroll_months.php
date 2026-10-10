<?php
include 'config.php';

// ── CREATE TABLE IF NOT EXISTS ─────────────────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS payroll_periods (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        year        INT NOT NULL,
        month       INT NOT NULL,
        open_date   DATE NOT NULL,
        close_date  DATE NOT NULL,
        status      ENUM('Open','Locked') NOT NULL DEFAULT 'Open',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_year_month (year, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$msg = ''; $msg_type = '';

// ── HANDLE ACTIONS ─────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'generate') {
        $gen_year   = intval($_POST['gen_year']);
        $open_date  = mysqli_real_escape_string($conn, $_POST['open_date']);
        $close_date = mysqli_real_escape_string($conn, $_POST['close_date']);
        $inserted   = 0; $skipped = 0;
        for ($m = 1; $m <= 12; $m++) {
            mysqli_query($conn,
                "INSERT IGNORE INTO payroll_periods (year, month, open_date, close_date, status)
                 VALUES ($gen_year, $m, '$open_date', '$close_date', 'Open')");
            if (mysqli_affected_rows($conn) > 0) $inserted++;
            else $skipped++;
        }
        $msg = "Generated $inserted month(s) for $gen_year." . ($skipped ? " $skipped already existed (skipped)." : '');
        $msg_type = 'success';
    }

    if ($_POST['action'] === 'update_row') {
        $rid        = intval($_POST['row_id']);
        $open_date  = mysqli_real_escape_string($conn, $_POST['open_date']);
        $close_date = mysqli_real_escape_string($conn, $_POST['close_date']);
        $status     = $_POST['status'] === 'Locked' ? 'Locked' : 'Open';
        mysqli_query($conn, "UPDATE payroll_periods SET open_date='$open_date', close_date='$close_date', status='$status' WHERE id=$rid");
        $msg = "Period updated successfully."; $msg_type = 'success';
    }

    if ($_POST['action'] === 'lock_all') {
        $lock_year = intval($_POST['lock_year']);
        mysqli_query($conn, "UPDATE payroll_periods SET status='Locked' WHERE year=$lock_year");
        $msg = "All months in $lock_year locked."; $msg_type = 'success';
    }

    if ($_POST['action'] === 'unlock_all') {
        $lock_year = intval($_POST['lock_year']);
        mysqli_query($conn, "UPDATE payroll_periods SET status='Open' WHERE year=$lock_year");
        $msg = "All months in $lock_year unlocked."; $msg_type = 'success';
    }

    if ($_POST['action'] === 'delete_year') {
        $del_year = intval($_POST['del_year']);
        mysqli_query($conn, "DELETE FROM payroll_periods WHERE year=$del_year");
        $msg = "All periods for $del_year deleted."; $msg_type = 'danger';
    }

    // ── Bulk Month Range (first → last day of each month) ─────────────────
    if ($_POST['action'] === 'bulk_month_range') {
        $bulk_year   = intval($_POST['bulk_year']);
        $open_day    = max(1, min(28, intval($_POST['open_day'])));
        $close_day   = intval($_POST['close_day']); // 0 = last day, else 1-28
        $bulk_target = $_POST['bulk_target'];
        $updated     = 0;

        for ($m = 1; $m <= 12; $m++) {
            if ($bulk_target === 'open_only') {
                $chk = mysqli_query($conn, "SELECT status FROM payroll_periods WHERE year=$bulk_year AND month=$m");
                $row = mysqli_fetch_assoc($chk);
                if ($row && $row['status'] === 'Locked') continue;
            }

            $max_days   = cal_days_in_month(CAL_GREGORIAN, $m, $bulk_year);
            $real_open  = min($open_day, $max_days);
            $real_close = ($close_day === 0 || $close_day > $max_days) ? $max_days : $close_day;

            $open_date  = mysqli_real_escape_string($conn, sprintf('%04d-%02d-%02d', $bulk_year, $m, $real_open));
            $close_date = mysqli_real_escape_string($conn, sprintf('%04d-%02d-%02d', $bulk_year, $m, $real_close));

            mysqli_query($conn,
                "UPDATE payroll_periods SET open_date='$open_date', close_date='$close_date'
                 WHERE year=$bulk_year AND month=$m");
            $updated += mysqli_affected_rows($conn);
        }

        $open_label  = $open_day === 1 ? '1st' : "{$open_day}th";
        $close_label = $close_day === 0 ? 'last day' : "{$close_day}th";
        $scope       = ($bulk_target === 'open_only') ? 'Open' : 'all';
        $msg         = "Date range applied to $updated $scope month(s) in $bulk_year. "
                     . "(Open: $open_label of month → Close: $close_label of month)";
        $msg_type    = 'success';
    }
}

// Toggle single status via GET
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $tid = intval($_GET['id']);
    mysqli_query($conn, "UPDATE payroll_periods SET status = IF(status='Open','Locked','Open') WHERE id=$tid");
    header('Location: payroll_months.php?year='.(int)($_GET['year']??date('Y')).'&toggled=1'); exit;
}

// ── FILTERS ────────────────────────────────────────────────────────────────
$sel_year = isset($_GET['year']) ? intval($_GET['year']) : (int)date('Y');

$periods = [];
$res = mysqli_query($conn, "SELECT * FROM payroll_periods WHERE year=$sel_year ORDER BY month ASC");
while ($r = mysqli_fetch_assoc($res)) $periods[$r['month']] = $r;

$years_res = mysqli_query($conn, "SELECT DISTINCT year FROM payroll_periods ORDER BY year DESC");
$existing_years = [];
while ($yr = mysqli_fetch_assoc($years_res)) $existing_years[] = $yr['year'];

$month_names = ['January','February','March','April','May','June',
                'July','August','September','October','November','December'];

$total   = count($periods);
$locked  = count(array_filter($periods, fn($p) => $p['status'] === 'Locked'));
$open_ct = $total - $locked;

include 'header.php';
?>

<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;margin:0 0 4px;color:#111;display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-calendar-check" style="color:#3b82f6;"></i> Payroll Month Management
        </h2>
        <p style="font-size:13px;color:#666;margin:0;">Manage payroll periods — open, lock, and configure each month</p>
    </div>
    <button onclick="document.getElementById('genModal').style.display='flex'" class="pm-btn pm-btn-p">
        <i class="fa-solid fa-plus"></i> Generate Year
    </button>
</div>

<?php if ($msg): ?>
<div class="pm-alert pm-alert-<?php echo $msg_type; ?>" style="margin-bottom:14px;">
    <i class="fa-solid fa-<?php echo $msg_type==='success'?'circle-check':'circle-xmark'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>
<?php if (isset($_GET['toggled'])): ?>
<div class="pm-alert pm-alert-success" style="margin-bottom:14px;">
    <i class="fa-solid fa-circle-check"></i> Status toggled successfully.
</div>
<?php endif; ?>

<!-- YEAR SELECTOR + STATS -->
<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
    <form method="GET" style="display:flex;align-items:center;gap:8px;">
        <label style="font-size:11px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.4px;">Year</label>
        <select name="year" onchange="this.form.submit()" class="pm-select">
            <?php for ($y = (int)date('Y')+2; $y >= (int)date('Y')-5; $y--): ?>
            <option value="<?php echo $y; ?>" <?php echo $sel_year==$y?'selected':''; ?>>
                <?php echo $y; ?><?php echo in_array($y,$existing_years)?' ●':''; ?>
            </option>
            <?php endfor; ?>
        </select>
    </form>

    <?php if ($total > 0): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <span class="pm-stat pm-stat-total"><i class="fa-solid fa-calendar"></i> <?php echo $total; ?> months</span>
        <span class="pm-stat pm-stat-open"><i class="fa-solid fa-lock-open"></i> <?php echo $open_ct; ?> Open</span>
        <span class="pm-stat pm-stat-locked"><i class="fa-solid fa-lock"></i> <?php echo $locked; ?> Locked</span>
    </div>

    <!-- Bulk actions -->
    <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;">
        <button onclick="document.getElementById('bulkDateModal').style.display='flex'" class="pm-btn pm-btn-range">
            <i class="fa-solid fa-calendar-range"></i> Bulk Date Range
        </button>
        <form method="POST" onsubmit="return confirm('Lock all months in <?php echo $sel_year; ?>?')">
            <input type="hidden" name="action" value="lock_all">
            <input type="hidden" name="lock_year" value="<?php echo $sel_year; ?>">
            <button class="pm-btn pm-btn-lock"><i class="fa-solid fa-lock"></i> Lock All</button>
        </form>
        <form method="POST" onsubmit="return confirm('Unlock all months in <?php echo $sel_year; ?>?')">
            <input type="hidden" name="action" value="unlock_all">
            <input type="hidden" name="lock_year" value="<?php echo $sel_year; ?>">
            <button class="pm-btn pm-btn-unlock"><i class="fa-solid fa-lock-open"></i> Unlock All</button>
        </form>
        <form method="POST" onsubmit="return confirm('DELETE all payroll periods for <?php echo $sel_year; ?>? This cannot be undone.')">
            <input type="hidden" name="action" value="delete_year">
            <input type="hidden" name="del_year" value="<?php echo $sel_year; ?>">
            <button class="pm-btn pm-btn-del"><i class="fa-solid fa-trash"></i> Delete Year</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<!-- MAIN TABLE -->
<div class="pm-wrap">
<?php if ($total === 0): ?>
<div style="text-align:center;padding:60px 20px;color:#aaa;">
    <i class="fa-solid fa-calendar-xmark" style="font-size:40px;display:block;margin-bottom:14px;color:#ddd;"></i>
    <div style="font-size:15px;font-weight:600;margin-bottom:6px;">No payroll periods for <?php echo $sel_year; ?></div>
    <div style="font-size:13px;margin-bottom:18px;">Click <strong>Generate Year</strong> to create all 12 months.</div>
    <button onclick="document.getElementById('genModal').style.display='flex'" class="pm-btn pm-btn-p">
        <i class="fa-solid fa-plus"></i> Generate <?php echo $sel_year; ?>
    </button>
</div>
<?php else: ?>
<table class="pm-table" id="pmTable">
    <thead>
        <tr>
            <th style="width:36px;text-align:center;">#</th>
            <th>Payroll Month</th>
            <th>Year</th>
            <th>Open Date</th>
            <th>Close Date</th>
            <th style="text-align:center;">Status</th>
            <th style="text-align:center;">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php for ($m = 1; $m <= 12; $m++):
        $p = $periods[$m] ?? null;
        $is_locked = $p && $p['status'] === 'Locked';
        $row_class = $p ? ($is_locked ? 'pm-locked' : 'pm-open') : 'pm-missing';
    ?>
    <tr class="pm-row <?php echo $row_class; ?>" id="row_<?php echo $m; ?>">
        <td style="text-align:center;color:#bbb;font-size:11px;"><?php echo $m; ?></td>
        <td class="pm-month-name">
            <i class="fa-solid fa-circle-dot" style="font-size:8px;color:<?php echo $p?($is_locked?'#6b7280':'#22c55e'):'#e5e7eb'; ?>;margin-right:6px;"></i>
            <?php echo $month_names[$m-1]; ?>
        </td>
        <td style="font-weight:700;color:#374151;"><?php echo $p ? $p['year'] : '—'; ?></td>

        <?php if ($p): ?>
        <td>
            <form method="POST" class="pm-inline-form" id="form_<?php echo $m; ?>">
                <input type="hidden" name="action" value="update_row">
                <input type="hidden" name="row_id" value="<?php echo $p['id']; ?>">
                <input type="date" name="open_date" class="pm-date-in" value="<?php echo $p['open_date']; ?>"
                       id="od_<?php echo $m; ?>" <?php echo $is_locked?'disabled':''; ?>>
        </td>
        <td>
                <input type="date" name="close_date" class="pm-date-in" value="<?php echo $p['close_date']; ?>"
                       id="cd_<?php echo $m; ?>" <?php echo $is_locked?'disabled':''; ?>>
        </td>
        <td style="text-align:center;">
                <select name="status" id="st_<?php echo $m; ?>"
                        class="pm-status-sel <?php echo $is_locked?'sel-locked':'sel-open'; ?>"
                        onchange="onStatusChange(<?php echo $m; ?>, this)">
                    <option value="Open"   <?php echo !$is_locked?'selected':''; ?>>🔓 Open</option>
                    <option value="Locked" <?php echo $is_locked?'selected':''; ?>>🔒 Locked</option>
                </select>
        </td>
        <td style="text-align:center;">
                <button type="submit" id="sv_<?php echo $m; ?>"
                        class="pm-btn pm-btn-save pm-btn-sm"
                        style="<?php echo $is_locked?'display:none;':''; ?>">
                    <i class="fa-solid fa-floppy-disk"></i> Save
                </button>
                <span id="lk_<?php echo $m; ?>" class="pm-locked-badge" style="<?php echo $is_locked?'':'display:none;'; ?>">
                    <i class="fa-solid fa-lock"></i> Locked
                </span>
            </form>
        </td>
        <?php else: ?>
        <td colspan="4" style="text-align:center;color:#d1d5db;font-size:12px;font-style:italic;">
            Not generated — <a href="#" onclick="openGenModal(<?php echo $sel_year;?>);return false;" style="color:#3b82f6;">Generate now</a>
        </td>
        <?php endif; ?>
    </tr>
    <?php endfor; ?>
    </tbody>
</table>
<?php endif; ?>
</div>

<!-- ── GENERATE MODAL ──────────────────────────────────────────────────── -->
<div id="genModal" class="pm-modal-bg" style="display:none;" onclick="if(event.target===this)this.style.display='none'">
    <div class="pm-modal">
        <div class="pm-modal-h">
            <i class="fa-solid fa-calendar-plus" style="color:#3b82f6;"></i>
            <h3>Generate Payroll Year</h3>
            <button onclick="document.getElementById('genModal').style.display='none'" class="pm-modal-close">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="generate">
            <div class="pm-modal-b">
                <p style="font-size:13px;color:#666;margin:0 0 16px;">This will create all 12 payroll months for the selected year. Existing months will be skipped.</p>
                <div class="pm-mrow">
                    <div class="pm-mg">
                        <label class="pm-ml">Year</label>
                        <select name="gen_year" class="pm-select" id="genYearSel">
                            <?php for ($y = (int)date('Y')+2; $y >= (int)date('Y')-3; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $sel_year==$y?'selected':''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div class="pm-mrow">
                    <div class="pm-mg">
                        <label class="pm-ml">Default Open Date</label>
                        <input type="date" name="open_date" class="pm-date-in" required value="<?php echo date('Y-m-01'); ?>">
                        <small class="pm-hint">Applied to all 12 months (you can edit individually after)</small>
                    </div>
                    <div class="pm-mg">
                        <label class="pm-ml">Default Close Date</label>
                        <input type="date" name="close_date" class="pm-date-in" required value="<?php echo date('Y-m-t'); ?>">
                    </div>
                </div>
            </div>
            <div class="pm-modal-f">
                <button type="submit" class="pm-btn pm-btn-p"><i class="fa-solid fa-circle-plus"></i> Generate All 12 Months</button>
                <button type="button" onclick="document.getElementById('genModal').style.display='none'" class="pm-btn pm-btn-g">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ── BULK DATE RANGE MODAL (FIRST → LAST/CUSTOM DAY OF EACH MONTH) ──── -->
<div id="bulkDateModal" class="pm-modal-bg" style="display:none;" onclick="if(event.target===this)this.style.display='none'">
    <div class="pm-modal" style="max-width:580px;">
        <div class="pm-modal-h">
            <i class="fa-solid fa-calendar-range" style="color:#8b5cf6;"></i>
            <h3>Bulk Date Range</h3>
            <button onclick="document.getElementById('bulkDateModal').style.display='none'" class="pm-modal-close">&times;</button>
        </div>
        <form method="POST" onsubmit="return confirmBulkDate()">
            <input type="hidden" name="action" value="bulk_month_range">
            <input type="hidden" name="bulk_year" value="<?php echo $sel_year; ?>">
            <div class="pm-modal-b">

                <!-- Info banner -->
                <div class="pm-info-banner">
                    <i class="fa-solid fa-circle-info"></i>
                    <span>Set the <strong>open day</strong> and <strong>close day</strong> within each month. Example: Open day <strong>1</strong> → Close day <strong>Last day</strong> sets January to <em>Jan 1 → Jan 31</em>, February to <em>Feb 1 → Feb 28</em>, etc.</span>
                </div>

                <!-- Day inputs -->
                <div class="pm-mrow" style="align-items:flex-end;">
                    <div class="pm-mg">
                        <label class="pm-ml">Open Day <span style="font-weight:400;text-transform:none;font-size:10px;">(of each month)</span></label>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <input type="number" name="open_day" id="openDay" class="pm-date-in"
                                   min="1" max="28" value="1"
                                   style="width:80px;text-align:center;font-size:15px;font-weight:700;"
                                   oninput="renderPreview()">
                            <span style="font-size:12px;color:#888;">th of month</span>
                        </div>
                    </div>
                    <div class="pm-mg">
                        <label class="pm-ml">Close Day <span style="font-weight:400;text-transform:none;font-size:10px;">(of each month)</span></label>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <select name="close_day" id="closeDay" class="pm-select"
                                    style="font-size:13px;font-weight:700;min-width:130px;"
                                    onchange="renderPreview()">
                                <option value="0">Last day of month</option>
                                <?php for ($d = 1; $d <= 28; $d++): ?>
                                <option value="<?php echo $d; ?>"><?php echo $d; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Apply to -->
                <div class="pm-mg" style="margin-bottom:16px;">
                    <label class="pm-ml">Apply To</label>
                    <div style="display:flex;gap:8px;margin-top:6px;">
                        <label class="pm-radio-card">
                            <input type="radio" name="bulk_target" value="all" checked onchange="renderPreview()">
                            <span>
                                <i class="fa-solid fa-calendar-days"></i>
                                All Months
                                <small>Updates all <?php echo $total; ?> months regardless of status</small>
                            </span>
                        </label>
                        <label class="pm-radio-card">
                            <input type="radio" name="bulk_target" value="open_only" onchange="renderPreview()">
                            <span>
                                <i class="fa-solid fa-lock-open"></i>
                                Open Months Only
                                <small>Skips <?php echo $locked; ?> locked month(s)</small>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Live preview -->
                <div>
                    <label class="pm-ml" style="margin-bottom:6px;display:block;">Preview</label>
                    <div id="bulkPreviewTable" style="border:1px solid #e9d5ff;border-radius:8px;overflow:hidden;font-size:12px;max-height:280px;overflow-y:auto;"></div>
                </div>

            </div>
            <div class="pm-modal-f">
                <button type="submit" class="pm-btn pm-btn-range-submit">
                    <i class="fa-solid fa-floppy-disk"></i> Apply to All Months
                </button>
                <button type="button" onclick="document.getElementById('bulkDateModal').style.display='none'" class="pm-btn pm-btn-g">Cancel</button>
            </div>
        </form>
    </div>
</div>

<style>
*{box-sizing:border-box;}

.pm-wrap{background:#fff;border:1px solid #d0d5dd;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.06);margin-bottom:16px;}

.pm-table{width:100%;border-collapse:collapse;font-size:13px;}
.pm-table thead tr{background:#18181b;}
.pm-table thead th{padding:11px 14px;color:#fff;font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;border-right:1px solid #2a2a2e;white-space:nowrap;}
.pm-table thead th:last-child{border-right:none;}

.pm-row td{padding:9px 14px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
.pm-row:last-child td{border-bottom:none;}
.pm-row:hover{background:#f8faff!important;}
.pm-month-name{font-weight:600;color:#111;white-space:nowrap;}

.pm-locked{border-left:3px solid #6b7280;background:#fafafa;}
.pm-open  {border-left:3px solid #22c55e;background:#fff;}
.pm-missing{border-left:3px solid #e5e7eb;background:#fefefe;}

.pm-status-sel{padding:4px 10px;border-radius:20px;border:none;font-size:11px;font-weight:700;cursor:pointer;outline:none;appearance:none;-webkit-appearance:none;text-align:center;}
.sel-open  {background:#dcfce7;color:#166534;}
.sel-locked{background:#f3f4f6;color:#4b5563;}

.pm-date-in{padding:6px 9px;border:1.5px solid #e5e7eb;border-radius:7px;font-size:12px;font-family:inherit;color:#111;background:#fff;width:auto;}
.pm-date-in:focus{outline:none;border-color:#3b82f6;}
.pm-date-in:disabled{background:#f5f5f5;color:#aaa;cursor:not-allowed;}

.pm-inline-form{display:contents;}

.pm-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap;}
.pm-btn:disabled{opacity:.4;cursor:not-allowed;}
.pm-btn-sm{padding:5px 10px;font-size:11px;border-radius:6px;}
.pm-btn-p  {background:#111;color:#fff;} .pm-btn-p:not(:disabled):hover{background:#333;}
.pm-btn-g  {background:#f5f5f5;color:#333;border:1px solid #e5e5e5;} .pm-btn-g:hover{background:#eee;}
.pm-btn-save{background:#3b82f6;color:#fff;} .pm-btn-save:not(:disabled):hover{background:#2563eb;}
.pm-btn-lock  {background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;} .pm-btn-lock:hover{background:#e5e7eb;}
.pm-btn-unlock{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;} .pm-btn-unlock:hover{background:#22c55e;color:#fff;}
.pm-btn-del   {background:#fee2e2;color:#991b1b;border:1px solid #fecaca;} .pm-btn-del:hover{background:#ef4444;color:#fff;}
.pm-btn-range       {background:#ede9fe;color:#5b21b6;border:1px solid #ddd6fe;} .pm-btn-range:hover{background:#8b5cf6;color:#fff;}
.pm-btn-range-submit{background:#7c3aed;color:#fff;} .pm-btn-range-submit:hover{background:#6d28d9;}

.pm-stat{display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border-radius:20px;font-size:11px;font-weight:700;}
.pm-stat-total {background:#f4f4f5;color:#555;}
.pm-stat-open  {background:#dcfce7;color:#166534;}
.pm-stat-locked{background:#f3f4f6;color:#4b5563;}

.pm-select{padding:8px 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;background:#fff;color:#111;cursor:pointer;}
.pm-select:focus{outline:none;border-color:#3b82f6;}

.pm-alert{display:flex;align-items:center;gap:9px;padding:11px 14px;border-radius:8px;font-size:13px;}
.pm-alert-success{background:#dcfce7;border:1px solid #bbf7d0;color:#166534;}
.pm-alert-danger {background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}

.pm-modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;}
.pm-modal{background:#fff;border-radius:14px;width:100%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,.2);overflow:hidden;}
.pm-modal-h{display:flex;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid #f0f0f0;background:#fafafa;}
.pm-modal-h h3{font-size:14px;font-weight:700;margin:0;flex:1;color:#111;}
.pm-modal-close{background:none;border:none;font-size:22px;color:#aaa;cursor:pointer;line-height:1;padding:0 4px;} .pm-modal-close:hover{color:#111;}
.pm-modal-b{padding:20px;}
.pm-modal-f{display:flex;gap:8px;padding:14px 20px;border-top:1px solid #f0f0f0;background:#fafafa;}
.pm-mrow{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
.pm-mg{display:flex;flex-direction:column;gap:5px;flex:1;min-width:140px;}
.pm-ml{font-size:11px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.4px;}
.pm-hint{font-size:10px;color:#9ca3af;margin-top:2px;}

.pm-locked-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;background:#f3f4f6;color:#6b7280;font-size:11px;font-weight:700;}

.pm-info-banner{display:flex;align-items:flex-start;gap:9px;background:#ede9fe;border:1px solid #ddd6fe;border-radius:8px;padding:10px 13px;font-size:12px;color:#5b21b6;margin-bottom:16px;line-height:1.5;}
.pm-info-banner i{margin-top:1px;flex-shrink:0;}

.pm-radio-card{display:flex;align-items:flex-start;gap:0;cursor:pointer;flex:1;}
.pm-radio-card input[type=radio]{position:absolute;opacity:0;width:0;height:0;}
.pm-radio-card span{display:flex;flex-direction:column;gap:2px;padding:10px 13px;border:2px solid #e5e7eb;border-radius:9px;font-size:13px;font-weight:600;color:#374151;width:100%;transition:all .15s;}
.pm-radio-card span small{font-size:11px;font-weight:400;color:#9ca3af;}
.pm-radio-card input[type=radio]:checked + span{border-color:#7c3aed;background:#faf5ff;color:#5b21b6;}
.pm-radio-card span i{font-size:14px;margin-bottom:4px;color:#8b5cf6;}
.pm-radio-card:hover span{border-color:#c4b5fd;}

@media(max-width:680px){
    .pm-table{font-size:11px;}
    .pm-date-in{width:130px;}
    .pm-radio-card span{padding:8px 10px;font-size:12px;}
}
</style>

<script>
function onStatusChange(m, sel) {
    const isLocked = sel.value === 'Locked';
    sel.className   = 'pm-status-sel ' + (isLocked ? 'sel-locked' : 'sel-open');
    const saveBtn   = document.getElementById('sv_' + m);
    const lockBadge = document.getElementById('lk_' + m);
    const odInput   = document.getElementById('od_' + m);
    const cdInput   = document.getElementById('cd_' + m);
    if (isLocked) {
        saveBtn.style.display   = 'none';
        lockBadge.style.display = 'inline-flex';
        odInput.disabled = true;
        cdInput.disabled = true;
    } else {
        saveBtn.style.display   = 'inline-flex';
        lockBadge.style.display = 'none';
        odInput.disabled = false;
        cdInput.disabled = false;
    }
}

function openGenModal(year) {
    document.getElementById('genYearSel').value = year;
    document.getElementById('genModal').style.display = 'flex';
}

// ── Preview for bulk month range ───────────────────────────────────────────
const MONTH_NAMES = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const MONTH_FULL  = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const SEL_YEAR    = <?php echo $sel_year; ?>;

function daysInMonth(y, m) { return new Date(y, m, 0).getDate(); }
function pad(n) { return String(n).padStart(2,'0'); }

function renderPreview() {
    const openDay  = Math.min(28, Math.max(1, parseInt(document.getElementById('openDay').value) || 1));
    const closeSel = parseInt(document.getElementById('closeDay').value); // 0=last, else day number

    let rows = '';
    for (let m = 1; m <= 12; m++) {
        const maxDays   = daysInMonth(SEL_YEAR, m);
        const realOpen  = Math.min(openDay, maxDays);
        const realClose = (closeSel === 0 || closeSel > maxDays) ? maxDays : closeSel;

        const openLabel  = `${MONTH_NAMES[m-1]} ${realOpen}, ${SEL_YEAR}`;
        const closeLabel = `${MONTH_NAMES[m-1]} ${realClose}, ${SEL_YEAR}`;
        const closeTip   = closeSel === 0 ? ` (last day)` : '';

        const bg = m % 2 === 0 ? '#faf5ff' : '#fff';
        rows += `<div style="display:flex;align-items:center;padding:6px 12px;background:${bg};border-bottom:1px solid #f3e8ff;gap:8px;">
            <span style="width:80px;font-weight:700;font-size:11px;color:#374151;">${MONTH_FULL[m-1]}</span>
            <span style="flex:1;font-size:11px;display:flex;align-items:center;gap:6px;">
                <span style="background:#ede9fe;padding:2px 8px;border-radius:4px;color:#5b21b6;font-weight:600;">${openLabel}</span>
                <span style="color:#aaa;font-size:10px;">→</span>
                <span style="background:#f0fdf4;padding:2px 8px;border-radius:4px;color:#166534;font-weight:600;">${closeLabel}${closeTip}</span>
            </span>
        </div>`;
    }
    document.getElementById('bulkPreviewTable').innerHTML = rows;
}

function confirmBulkDate() {
    const openDay  = parseInt(document.getElementById('openDay').value);
    const closeSel = parseInt(document.getElementById('closeDay').value);
    if (!openDay || openDay < 1 || openDay > 28) {
        alert('Please enter a valid open day between 1 and 28.');
        return false;
    }
    const target     = document.querySelector('input[name="bulk_target"]:checked').value;
    const scope      = target === 'open_only' ? 'Open months only' : 'ALL months';
    const closeLabel = closeSel === 0 ? 'last day of month' : `${closeSel}th of month`;
    return confirm(`Apply date range to ${scope} in <?php echo $sel_year; ?>?\n\nOpen: ${openDay}th of each month\nClose: ${closeLabel}\n\nThis cannot be undone.`);
}

// Init
renderPreview();
</script>

<?php include 'footer.php'; ?>