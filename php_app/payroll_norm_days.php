<?php
include 'config.php';

// ── CREATE / ALTER TABLE ───────────────────────────────────────────────────
// Add norm_working_days to payroll_periods if it doesn't exist
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS payroll_periods (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        year              INT NOT NULL,
        month             INT NOT NULL,
        open_date         DATE NOT NULL,
        close_date        DATE NOT NULL,
        status            ENUM('Open','Locked') NOT NULL DEFAULT 'Open',
        norm_working_days INT DEFAULT NULL,
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_year_month (year, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Add column if table already existed without it
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM payroll_periods LIKE 'norm_working_days'");
if (mysqli_num_rows($col_check) === 0) {
    mysqli_query($conn, "ALTER TABLE payroll_periods ADD COLUMN norm_working_days INT DEFAULT NULL AFTER close_date");
}

$msg = ''; $msg_type = '';

// ── HANDLE ACTIONS ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Update single row's norm_working_days
    if ($_POST['action'] === 'update_row') {
        $rid  = intval($_POST['row_id']);
        $nwd  = $_POST['norm_working_days'] === '' ? 'NULL' : intval($_POST['norm_working_days']);
        $val  = $nwd === 'NULL' ? 'NULL' : $nwd;
        mysqli_query($conn, "UPDATE payroll_periods SET norm_working_days=$val WHERE id=$rid");
        $msg = "Normal working days updated."; $msg_type = 'success';
    }

    // Bulk update: same value for all months in year
    if ($_POST['action'] === 'bulk_update') {
        $bulk_year = intval($_POST['bulk_year']);
        $nwd = $_POST['bulk_days'] === '' ? 'NULL' : intval($_POST['bulk_days']);
        mysqli_query($conn, "UPDATE payroll_periods SET norm_working_days=$nwd WHERE year=$bulk_year");
        $msg = "All months in $bulk_year updated to " . ($nwd === 'NULL' ? 'N/A' : $nwd) . " working days.";
        $msg_type = 'success';
    }

    // Bulk update: each month individually (from bulk table form)
    if ($_POST['action'] === 'bulk_update_each') {
        $bulk_year = intval($_POST['bulk_year']);
        $rows = $_POST['month_days'] ?? [];
        $updated = 0;
        foreach ($rows as $rid => $nwd) {
            $rid = intval($rid);
            $val = ($nwd === '' || $nwd === null) ? 'NULL' : intval($nwd);
            mysqli_query($conn, "UPDATE payroll_periods SET norm_working_days=$val WHERE id=$rid AND year=$bulk_year");
            $updated++;
        }
        $msg = "Updated $updated month(s) for $bulk_year."; $msg_type = 'success';
    }
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

$total = count($periods);
$filled = count(array_filter($periods, fn($p) => $p['norm_working_days'] !== null));
$missing = $total - $filled;

include 'header.php';
?>

<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;margin:0 0 4px;color:#111;display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-business-time" style="color:#3b82f6;"></i> Normal Working Days
        </h2>
        <p style="font-size:13px;color:#666;margin:0;">Set the number of normal working days for each payroll month</p>
    </div>
    <a href="payroll_months.php" class="pm-btn pm-btn-g">
        <i class="fa-solid fa-calendar-check"></i> Payroll Months
    </a>
</div>

<?php if ($msg): ?>
<div class="pm-alert pm-alert-<?php echo $msg_type; ?>" style="margin-bottom:14px;">
    <i class="fa-solid fa-<?php echo $msg_type==='success'?'circle-check':'circle-xmark'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
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
        <span class="pm-stat pm-stat-open"><i class="fa-solid fa-circle-check"></i> <?php echo $filled; ?> Set</span>
        <?php if ($missing > 0): ?>
        <span class="pm-stat pm-stat-missing"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $missing; ?> Missing</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($total === 0): ?>
<!-- No data -->
<div class="pm-wrap">
    <div style="text-align:center;padding:60px 20px;color:#aaa;">
        <i class="fa-solid fa-calendar-xmark" style="font-size:40px;display:block;margin-bottom:14px;color:#ddd;"></i>
        <div style="font-size:15px;font-weight:600;margin-bottom:6px;">No payroll periods for <?php echo $sel_year; ?></div>
        <div style="font-size:13px;margin-bottom:18px;">Please generate payroll months first in <a href="payroll_months.php" style="color:#3b82f6;">Payroll Month Management</a>.</div>
    </div>
</div>
<?php else: ?>

<!-- ── BULK ACTIONS BAR ────────────────────────────────────────────────── -->
<div class="pm-wrap" style="margin-bottom:14px;">
    <div style="padding:16px 18px;border-bottom:1px solid #f0f0f0;background:#fafafa;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <i class="fa-solid fa-layer-group" style="color:#3b82f6;font-size:14px;"></i>
        <span style="font-size:12px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.4px;">Bulk Set All Months</span>
        <span style="font-size:11px;color:#aaa;">— apply the same value to every month in <?php echo $sel_year; ?></span>
    </div>
    <div style="padding:16px 18px;">
        <form method="POST" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;"
              onsubmit="return confirm('Set all months in <?php echo $sel_year; ?> to this working days value?')">
            <input type="hidden" name="action" value="bulk_update">
            <input type="hidden" name="bulk_year" value="<?php echo $sel_year; ?>">
            <div style="display:flex;flex-direction:column;gap:5px;">
                <label class="pm-ml">Normal Working Days</label>
                <input type="number" name="bulk_days" min="1" max="31" class="pm-num-in"
                       placeholder="e.g. 22" style="width:130px;">
                <small class="pm-hint">Leave blank to clear all</small>
            </div>
            <button type="submit" class="pm-btn pm-btn-bulk">
                <i class="fa-solid fa-arrows-rotate"></i> Apply to All Months
            </button>
        </form>
    </div>
</div>

<!-- ── MAIN TABLE with per-row + save-all ─────────────────────────────── -->
<div class="pm-wrap">
    <div style="padding:16px 18px;border-bottom:1px solid #f0f0f0;background:#fafafa;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-table-list" style="color:#3b82f6;font-size:14px;"></i>
            <span style="font-size:12px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.4px;">Monthly Working Days — <?php echo $sel_year; ?></span>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="button" onclick="saveAllRows()" class="pm-btn pm-btn-p pm-btn-sm">
                <i class="fa-solid fa-floppy-disk"></i> Save All
            </button>
        </div>
    </div>

    <form method="POST" id="allRowsForm">
        <input type="hidden" name="action" value="bulk_update_each">
        <input type="hidden" name="bulk_year" value="<?php echo $sel_year; ?>">
        <table class="pm-table">
            <thead>
                <tr>
                    <th style="width:36px;text-align:center;">#</th>
                    <th>Payroll Month</th>
                    <th style="text-align:center;">Year</th>
                    <th style="text-align:center;">Status</th>
                    <th style="text-align:center;">Open Date</th>
                    <th style="text-align:center;">Close Date</th>
                    <th style="text-align:center;">Normal Working Days</th>
                    <th style="text-align:center;">Quick Save</th>
                </tr>
            </thead>
            <tbody>
            <?php for ($m = 1; $m <= 12; $m++):
                $p = $periods[$m] ?? null;
                $is_locked = $p && $p['status'] === 'Locked';
                $nwd = $p['norm_working_days'] ?? '';
                $has_nwd = $nwd !== '' && $nwd !== null;
                $row_class = !$p ? 'pm-missing' : ($has_nwd ? 'pm-open' : 'pm-nodays');
            ?>
            <tr class="pm-row <?php echo $row_class; ?>">
                <td style="text-align:center;color:#bbb;font-size:11px;"><?php echo $m; ?></td>
                <td class="pm-month-name">
                    <i class="fa-solid fa-circle-dot" style="font-size:8px;color:<?php echo !$p?'#e5e7eb':($has_nwd?'#22c55e':'#f59e0b'); ?>;margin-right:6px;"></i>
                    <?php echo $month_names[$m-1]; ?>
                </td>
                <td style="text-align:center;font-weight:700;color:#374151;"><?php echo $p ? $p['year'] : '—'; ?></td>
                <td style="text-align:center;">
                    <?php if ($p): ?>
                    <span class="pm-status-badge <?php echo $is_locked?'badge-locked':'badge-open'; ?>">
                        <?php echo $is_locked ? '🔒 Locked' : '🔓 Open'; ?>
                    </span>
                    <?php else: ?>
                    <span style="color:#d1d5db;font-size:11px;font-style:italic;">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;font-size:12px;color:#6b7280;">
                    <?php echo $p ? date('d M Y', strtotime($p['open_date'])) : '—'; ?>
                </td>
                <td style="text-align:center;font-size:12px;color:#6b7280;">
                    <?php echo $p ? date('d M Y', strtotime($p['close_date'])) : '—'; ?>
                </td>

                <?php if ($p): ?>
                <td style="text-align:center;">
                    <!-- This input feeds the Save All form -->
                    <input type="number" name="month_days[<?php echo $p['id']; ?>]"
                           min="1" max="31"
                           class="pm-num-in pm-num-center"
                           value="<?php echo htmlspecialchars($nwd); ?>"
                           placeholder="—"
                           id="nwd_<?php echo $m; ?>"
                           data-row="<?php echo $m; ?>"
                           data-rid="<?php echo $p['id']; ?>">
                    <?php if ($has_nwd): ?>
                    <span style="font-size:10px;color:#9ca3af;display:block;margin-top:2px;"><?php echo $nwd; ?> day<?php echo $nwd!=1?'s':''; ?></span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;">
                    <!-- Single-row quick save -->
                    <button type="button"
                            onclick="saveRow(<?php echo $m; ?>, <?php echo $p['id']; ?>)"
                            class="pm-btn pm-btn-save pm-btn-sm"
                            id="svbtn_<?php echo $m; ?>">
                        <i class="fa-solid fa-floppy-disk"></i> Save
                    </button>
                    <span id="saved_<?php echo $m; ?>" style="display:none;font-size:11px;color:#22c55e;font-weight:700;">
                        <i class="fa-solid fa-circle-check"></i> Saved
                    </span>
                </td>
                <?php else: ?>
                <td style="text-align:center;color:#d1d5db;font-size:11px;font-style:italic;" colspan="2">
                    Not generated — <a href="payroll_months.php" style="color:#3b82f6;">Create period</a>
                </td>
                <?php endif; ?>
            </tr>
            <?php endfor; ?>
            </tbody>
        </table>

        <!-- Save All hidden submit -->
        <div style="padding:14px 18px;border-top:1px solid #f0f0f0;background:#fafafa;display:flex;justify-content:flex-end;gap:8px;">
            <button type="submit" class="pm-btn pm-btn-p" onclick="return confirm('Save all working days for <?php echo $sel_year; ?>?')">
                <i class="fa-solid fa-floppy-disk"></i> Save All Months
            </button>
        </div>
    </form>
</div>

<?php endif; ?>

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

.pm-open   {border-left:3px solid #22c55e;background:#fff;}
.pm-nodays {border-left:3px solid #f59e0b;background:#fffdf7;}
.pm-missing{border-left:3px solid #e5e7eb;background:#fefefe;}

.pm-status-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-open  {background:#dcfce7;color:#166534;}
.badge-locked{background:#f3f4f6;color:#4b5563;}

.pm-num-in{padding:6px 9px;border:1.5px solid #e5e7eb;border-radius:7px;font-size:13px;font-family:inherit;color:#111;background:#fff;width:90px;text-align:center;}
.pm-num-in:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.12);}
.pm-num-center{text-align:center;}

.pm-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap;}
.pm-btn-sm {padding:5px 10px;font-size:11px;border-radius:6px;}
.pm-btn-p  {background:#111;color:#fff;} .pm-btn-p:hover{background:#333;}
.pm-btn-g  {background:#f5f5f5;color:#333;border:1px solid #e5e5e5;} .pm-btn-g:hover{background:#eee;}
.pm-btn-save{background:#3b82f6;color:#fff;} .pm-btn-save:hover{background:#2563eb;}
.pm-btn-bulk{background:#7c3aed;color:#fff;} .pm-btn-bulk:hover{background:#6d28d9;}

.pm-stat{display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border-radius:20px;font-size:11px;font-weight:700;}
.pm-stat-total  {background:#f4f4f5;color:#555;}
.pm-stat-open   {background:#dcfce7;color:#166534;}
.pm-stat-missing{background:#fef3c7;color:#92400e;}

.pm-select{padding:8px 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;background:#fff;color:#111;cursor:pointer;}
.pm-select:focus{outline:none;border-color:#3b82f6;}

.pm-alert{display:flex;align-items:center;gap:9px;padding:11px 14px;border-radius:8px;font-size:13px;}
.pm-alert-success{background:#dcfce7;border:1px solid #bbf7d0;color:#166534;}
.pm-alert-danger {background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}

.pm-ml{font-size:11px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.4px;}
.pm-hint{font-size:10px;color:#9ca3af;margin-top:2px;}

@media(max-width:680px){
    .pm-table{font-size:11px;}
    .pm-num-in{width:70px;}
}
</style>

<script>
// Quick save a single row via AJAX-style form POST
function saveRow(m, rid) {
    const nwd = document.getElementById('nwd_' + m).value;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';

    const fields = {action: 'update_row', row_id: rid, norm_working_days: nwd};
    for (const [k,v] of Object.entries(fields)) {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = k; i.value = v;
        form.appendChild(i);
    }
    document.body.appendChild(form);

    // Show saving state
    const btn   = document.getElementById('svbtn_' + m);
    const saved = document.getElementById('saved_' + m);
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    btn.disabled  = true;

    // Small delay for UX feel then submit
    setTimeout(() => form.submit(), 200);
}

// Submit the full table form (all rows at once)
function saveAllRows() {
    if (confirm('Save all working days for this year?')) {
        document.getElementById('allRowsForm').submit();
    }
}
</script>

<?php include 'footer.php'; ?>
