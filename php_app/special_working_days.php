<?php
include 'config.php';

// ── Create Table ──────────────────────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS special_holidays (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    target_type ENUM('all','staff_category','designation','employee') NOT NULL DEFAULT 'all',
    target_ids TEXT NULL COMMENT 'JSON array of IDs',
    notes TEXT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// ══════════════════════════════════════════════════════════════
//  SPECIAL HOLIDAYS – actions
// ══════════════════════════════════════════════════════════════
if (isset($_GET['delete_sh'])) {
    $id = intval($_GET['delete_sh']);
    if (mysqli_query($conn, "DELETE FROM special_holidays WHERE id = $id"))
        $success_message = "Special holiday deleted successfully!";
    else
        $error_message = "Error: " . mysqli_error($conn);
}
if (isset($_GET['toggle_sh'])) {
    $id  = intval($_GET['toggle_sh']);
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT active FROM special_holidays WHERE id = $id"));
    if ($row) {
        $newActive = $row['active'] ? 0 : 1;
        mysqli_query($conn, "UPDATE special_holidays SET active = $newActive WHERE id = $id");
        $success_message = "Status updated!";
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'sh') {
    $title       = mysqli_real_escape_string($conn, trim($_POST['title'] ?? ''));
    $date_from   = mysqli_real_escape_string($conn, trim($_POST['date_from'] ?? ''));
    $date_to     = mysqli_real_escape_string($conn, trim($_POST['date_to'] ?? ''));
    $target_type = mysqli_real_escape_string($conn, trim($_POST['target_type'] ?? 'all'));
    $notes       = mysqli_real_escape_string($conn, trim($_POST['notes'] ?? ''));
    $active      = isset($_POST['active']) ? 1 : 0;
    $target_ids  = null;
    if ($target_type === 'staff_category' && !empty($_POST['target_staff_categories']))
        $target_ids = mysqli_real_escape_string($conn, json_encode(array_map('intval', (array)$_POST['target_staff_categories'])));
    elseif ($target_type === 'designation' && !empty($_POST['target_designations']))
        $target_ids = mysqli_real_escape_string($conn, json_encode(array_map('intval', (array)$_POST['target_designations'])));
    elseif ($target_type === 'employee' && !empty($_POST['target_employees']))
        $target_ids = mysqli_real_escape_string($conn, json_encode(array_map('intval', (array)$_POST['target_employees'])));

    if (empty($title) || empty($date_from) || empty($date_to))
        $error_message = "Title and date range are required.";
    elseif ($date_to < $date_from)
        $error_message = "End date must be on or after start date.";
    else {
        $tid_sql = $target_ids ? "'$target_ids'" : "NULL";
        if (!empty($_POST['sh_id'])) {
            $id = intval($_POST['sh_id']);
            $sql = "UPDATE special_holidays SET title='$title',date_from='$date_from',date_to='$date_to',target_type='$target_type',target_ids=$tid_sql,notes='$notes',active=$active WHERE id=$id";
            mysqli_query($conn, $sql) ? $success_message = "Updated successfully!" : $error_message = mysqli_error($conn);
        } else {
            $sql = "INSERT INTO special_holidays (title,date_from,date_to,target_type,target_ids,notes,active) VALUES ('$title','$date_from','$date_to','$target_type',$tid_sql,'$notes',$active)";
            mysqli_query($conn, $sql) ? $success_message = "Added successfully!" : $error_message = mysqli_error($conn);
        }
    }
}

// ── Fetch data ────────────────────────────────────────────────
$sh_search = '';
if (!empty($_GET['sh_search'])) $sh_search = mysqli_real_escape_string($conn, trim($_GET['sh_search']));
$sh_where  = $sh_search ? " WHERE title LIKE '%$sh_search%' OR notes LIKE '%$sh_search%'" : '';

$shs_result = mysqli_query($conn, "SELECT * FROM special_holidays $sh_where ORDER BY date_from DESC, id DESC");
$all_shs    = [];
while ($r = mysqli_fetch_assoc($shs_result)) $all_shs[] = $r;

// ── Reference data ────────────────────────────────────────────
$staff_cats   = mysqli_query($conn, "SELECT id, category_code, category_name FROM staff_categories WHERE active = 1 ORDER BY category_name");
$designations = mysqli_query($conn, "SELECT id, designation_name FROM designations ORDER BY designation_name");
$employees    = mysqli_query($conn, "SELECT id, employee_id, employee_full_name FROM employees WHERE active = 1 ORDER BY employee_full_name");

// ── Helpers ───────────────────────────────────────────────────
function resolveTargetLabel($conn, $target_type, $target_ids_json) {
    if ($target_type === 'all') return '<span class="tgt-badge tgt-all">All Employees</span>';
    if (empty($target_ids_json)) return '<span class="tgt-badge tgt-none">—</span>';
    $ids = json_decode($target_ids_json, true);
    if (!$ids || !is_array($ids)) return '<span class="tgt-badge tgt-none">—</span>';
    $id_list = implode(',', array_map('intval', $ids));
    $labels  = []; $cls = '';
    if ($target_type === 'staff_category') {
        $res = mysqli_query($conn, "SELECT category_name FROM staff_categories WHERE id IN ($id_list)");
        while ($r = mysqli_fetch_assoc($res)) $labels[] = $r['category_name']; $cls = 'tgt-cat';
    } elseif ($target_type === 'designation') {
        $res = mysqli_query($conn, "SELECT designation_name FROM designations WHERE id IN ($id_list)");
        while ($r = mysqli_fetch_assoc($res)) $labels[] = $r['designation_name']; $cls = 'tgt-des';
    } elseif ($target_type === 'employee') {
        $res = mysqli_query($conn, "SELECT employee_id, employee_full_name FROM employees WHERE id IN ($id_list)");
        while ($r = mysqli_fetch_assoc($res)) $labels[] = $r['employee_id'] . ' — ' . $r['employee_full_name']; $cls = 'tgt-emp';
    } else { return '—'; }
    if (empty($labels)) return '<span class="tgt-badge tgt-none">—</span>';
    $shown = array_slice($labels, 0, 2); $more = count($labels) - 2;
    $html  = '';
    foreach ($shown as $l) $html .= '<span class="tgt-badge '.$cls.'">'.htmlspecialchars($l).'</span> ';
    if ($more > 0) $html .= '<span class="tgt-badge tgt-more">+'.$more.' more</span>';
    return $html;
}

function computeHolidayDays($date_from, $date_to) {
    $start = new DateTime($date_from); $end = new DateTime($date_to); $end->modify('+1 day');
    $days = []; $count = 0;
    foreach (new DatePeriod($start, new DateInterval('P1D'), $end) as $dt) {
        $days[] = ['display'=>$dt->format('d M Y'),'dow'=>$dt->format('D')]; $count++;
    }
    return ['days'=>$days,'count'=>$count];
}

include 'header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>

<!-- ═══════════════════ PAGE HEADER ═══════════════════ -->
<div class="page-header">
    <h2 class="page-title">Special Holidays</h2>
    <p class="page-subtitle">Manage company-declared extra holidays</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success_message; ?></div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?></div>
<?php endif; ?>

<!-- ═══════════════════ ACTION BAR ═══════════════════ -->
<div class="swd-action-bar">
    <div class="swd-search-wrap">
        <form method="GET" id="shSearchForm">
            <div class="search-input-wrapper">
                <i class="fa-solid fa-search search-icon"></i>
                <input type="text" name="sh_search" id="shSearchInput" class="search-input"
                    placeholder="Search by title or notes…"
                    value="<?php echo htmlspecialchars($sh_search); ?>">
                <?php if ($sh_search): ?>
                <button type="button" class="clear-btn" onclick="clearSearch()"><i class="fa-solid fa-xmark"></i></button>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($sh_search): ?>
        <small class="search-note"><?php echo count($all_shs); ?> result<?php echo count($all_shs)!=1?'s':''; ?> for "<?php echo htmlspecialchars($sh_search); ?>"</small>
        <?php endif; ?>
    </div>
    <button class="btn btn-sh" onclick="openShModal()">
        <i class="fa-solid fa-plus"></i> Add Special Holiday
    </button>
</div>

<!-- ═══════════════════ TABLE CARD ═══════════════════ -->
<div class="content-card">
    <div class="card-header-row">
        <div class="card-header-left">
            <div class="card-icon-wrap sh-icon"><i class="fa-solid fa-umbrella-beach"></i></div>
            <div>
                <h3 class="card-title">Special Holidays</h3>
                <p class="card-sub">Company-declared extra holidays beyond public holidays</p>
            </div>
        </div>
        <span class="item-count"><?php echo count($all_shs); ?> record<?php echo count($all_shs)!=1?'s':''; ?></span>
    </div>

    <?php if (!empty($all_shs)): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead><tr>
                <th>#</th><th>Title</th><th>Date Range</th><th>Total Days</th>
                <th>Day Breakdown</th><th>Applies To</th><th>Notes</th><th>Status</th><th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($all_shs as $i => $sh):
                $single   = $sh['date_from'] === $sh['date_to'];
                $hd       = computeHolidayDays($sh['date_from'], $sh['date_to']);
                $hdays    = $hd['days']; $hcount = $hd['count'];
            ?>
            <tr>
                <td class="td-num"><?php echo $i+1; ?></td>
                <td><strong><?php echo htmlspecialchars($sh['title']); ?></strong></td>
                <td><?php if ($single): ?>
                    <span class="date-single"><?php echo date('d M Y', strtotime($sh['date_from'])); ?></span>
                    <small class="dow"><?php echo date('l', strtotime($sh['date_from'])); ?></small>
                <?php else: ?>
                    <div class="date-range-wrap">
                        <span class="dr-from"><?php echo date('d M Y', strtotime($sh['date_from'])); ?></span>
                        <span class="dr-arr">→</span>
                        <span class="dr-to"><?php echo date('d M Y', strtotime($sh['date_to'])); ?></span>
                    </div>
                <?php endif; ?></td>
                <td><span class="total-days-badge"><?php echo $hcount; ?> day<?php echo $hcount!=1?'s':''; ?></span></td>
                <td>
                    <div class="matched-days-wrap">
                        <?php foreach (array_slice($hdays,0,3) as $d): ?>
                        <span class="matched-chip mc-hol" title="<?php echo $d['dow']; ?>">
                            <?php echo $d['display']; ?>
                            <em><?php echo $d['dow']; ?></em>
                        </span>
                        <?php endforeach; ?>
                        <?php if (count($hdays)>3): ?><span class="matched-more">+<?php echo count($hdays)-3; ?> more</span><?php endif; ?>
                    </div>
                </td>
                <td><?php echo resolveTargetLabel($conn,$sh['target_type'],$sh['target_ids']); ?></td>
                <td><?php if ($sh['notes']): ?><span class="notes-cell" title="<?php echo htmlspecialchars($sh['notes']); ?>"><?php echo htmlspecialchars(mb_strimwidth($sh['notes'],0,40,'…')); ?></span><?php else: ?><span style="color:#d1d5db">—</span><?php endif; ?></td>
                <td>
                    <a href="?toggle_sh=<?php echo $sh['id']; ?><?php echo $sh_search?'&sh_search='.urlencode($sh_search):''; ?>"
                       class="badge <?php echo $sh['active']?'badge-success':'badge-inactive'; ?>" style="cursor:pointer;text-decoration:none;">
                        <?php echo $sh['active']?'<i class="fa-solid fa-circle-check"></i> Active':'<i class="fa-solid fa-circle-xmark"></i> Inactive'; ?>
                    </a>
                </td>
                <td>
                    <div class="action-buttons">
                        <a href="#" onclick="editSh(<?php echo $sh['id']; ?>)" class="btn-action btn-edit" title="Edit"><i class="fa-solid fa-pen"></i></a>
                        <a href="?delete_sh=<?php echo $sh['id']; ?><?php echo $sh_search?'&sh_search='.urlencode($sh_search):''; ?>"
                           class="btn-action btn-delete" title="Delete" onclick="return confirm('Delete this special holiday?')">
                            <i class="fa-solid fa-trash"></i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <i class="fa-solid fa-umbrella-beach"></i>
        <h3>No Special Holidays</h3>
        <p><?php echo $sh_search?'No records match your search.':'Add your first special holiday using the button above.'; ?></p>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════
     MODAL — SPECIAL HOLIDAY
══════════════════════════════════════════════════════ -->
<div id="shModal" class="modal">
    <div class="modal-content">
        <div class="modal-header mh-sh">
            <div class="modal-header-left">
                <div class="modal-header-icon sh-hdr-icon"><i class="fa-solid fa-umbrella-beach"></i></div>
                <h3 class="modal-title" id="shModalTitle">Add Special Holiday</h3>
            </div>
            <button class="modal-close" onclick="closeShModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="shForm">
            <input type="hidden" name="form_type" value="sh">
            <input type="hidden" name="sh_id" id="sh_id">
            <div class="modal-body">
                <div class="sh-info-banner">
                    <i class="fa-solid fa-circle-info"></i>
                    <span>Special holidays are <strong>company-declared</strong> extra days off — separate from public holidays. All calendar days in the range are counted as holiday.</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Holiday Title <span class="req">*</span></label>
                    <input type="text" name="title" id="sh_f_title" class="form-input" placeholder="e.g. Company Anniversary Holiday, Festival Break" required>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label">Date From <span class="req">*</span></label>
                        <input type="date" name="date_from" id="sh_f_date_from" class="form-input" required onchange="onShDateChange()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date To <span class="req">*</span></label>
                        <input type="date" name="date_to" id="sh_f_date_to" class="form-input" required onchange="onShDateChange()">
                        <small class="form-hint">Same as From = single day</small>
                    </div>
                </div>

                <!-- SH Day Preview -->
                <div id="shDayPreview" class="matched-preview sh-preview" style="display:none;">
                    <div class="mp-header sh-mp-header">
                        <i class="fa-solid fa-umbrella-beach"></i>
                        <strong>Holiday Days</strong>
                        <span class="mp-count-badge sh-count-badge" id="shMpCount">0</span>
                        <span class="mp-range-label" id="shMpRangeLabel"></span>
                    </div>
                    <div id="shMpBody" class="mp-body"></div>
                </div>

                <div class="form-group">
                    <label class="form-label">Applies To <span class="req">*</span></label>
                    <div class="target-type-tabs" id="shTargetTypeTabs">
                        <button type="button" class="tt-tab active" data-val="all"            onclick="setShTargetType('all')">All Employees</button>
                        <button type="button" class="tt-tab"        data-val="staff_category" onclick="setShTargetType('staff_category')">Staff Category</button>
                        <button type="button" class="tt-tab"        data-val="designation"    onclick="setShTargetType('designation')">Designation</button>
                        <button type="button" class="tt-tab"        data-val="employee"       onclick="setShTargetType('employee')">Specific Employees</button>
                    </div>
                    <input type="hidden" name="target_type" id="sh_f_target_type" value="all">
                </div>
                <div class="form-group target-field" id="sh_field_staff_category" style="display:none;">
                    <label class="form-label">Select Staff Categories</label>
                    <select name="target_staff_categories[]" id="sh_sel_staff_categories" class="select2-field" multiple>
                        <?php mysqli_data_seek($staff_cats,0); while ($sc=mysqli_fetch_assoc($staff_cats)): ?>
                        <option value="<?php echo $sc['id']; ?>"><?php echo htmlspecialchars(($sc['category_code']?$sc['category_code'].' — ':'').$sc['category_name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group target-field" id="sh_field_designation" style="display:none;">
                    <label class="form-label">Select Designations</label>
                    <select name="target_designations[]" id="sh_sel_designations" class="select2-field" multiple>
                        <?php mysqli_data_seek($designations,0); while ($d=mysqli_fetch_assoc($designations)): ?>
                        <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['designation_name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group target-field" id="sh_field_employee" style="display:none;">
                    <label class="form-label">Select Employees</label>
                    <select name="target_employees[]" id="sh_sel_employees" class="select2-field" multiple>
                        <?php mysqli_data_seek($employees,0); while ($e=mysqli_fetch_assoc($employees)): ?>
                        <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['employee_id'].' — '.$e['employee_full_name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" id="sh_f_notes" class="form-input form-textarea" placeholder="Reason for this special holiday…" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <div class="switch-wrap">
                        <label class="switch"><input type="checkbox" name="active" id="sh_f_active" checked><span class="slider"></span></label>
                        <span class="switch-label">Active</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeShModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-sh" id="shSubmitBtn"><i class="fa-solid fa-check"></i> <span id="shSubmitBtnText">Save</span></button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════ JS DATA ═══════════════════ -->
<script>
const SH_DATA = <?php echo json_encode($all_shs, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
</script>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function () {
    const base = { width:'100%', allowClear:true };
    $('#sh_sel_staff_categories').select2({...base, placeholder:'Search staff categories…', dropdownParent:$('#shModal')});
    $('#sh_sel_designations').select2({...base, placeholder:'Search designations…',         dropdownParent:$('#shModal')});
    $('#sh_sel_employees').select2({...base, placeholder:'Search employees…',               dropdownParent:$('#shModal')});
});

const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const DAYS   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
function fmtDate(d)    { return d.getDate().toString().padStart(2,'0')+' '+MONTHS[d.getMonth()]+' '+d.getFullYear(); }
function fmtDateKey(d) { return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }

/* ── Modal open/close ──────────────────────────── */
function openShModal() {
    document.getElementById('shModal').classList.add('active');
    document.getElementById('shForm').reset();
    document.getElementById('sh_id').value = '';
    document.getElementById('shModalTitle').textContent    = 'Add Special Holiday';
    document.getElementById('shSubmitBtnText').textContent = 'Save';
    setShTargetType('all');
    $('#sh_sel_staff_categories,#sh_sel_designations,#sh_sel_employees').val(null).trigger('change');
    document.getElementById('shDayPreview').style.display = 'none';
}
function closeShModal() { document.getElementById('shModal').classList.remove('active'); }
document.getElementById('shModal').addEventListener('click', function(e){ if(e.target===this) closeShModal(); });

/* ── SH day preview ────────────────────────────── */
function onShDateChange() {
    const fromStr = document.getElementById('sh_f_date_from').value;
    const toStr   = document.getElementById('sh_f_date_to').value;
    const preview = document.getElementById('shDayPreview');
    if (!fromStr || !toStr || toStr < fromStr) { preview.style.display='none'; return; }
    const start = new Date(fromStr+'T12:00:00'), end = new Date(toStr+'T12:00:00');
    const days = []; const cur = new Date(start);
    while (cur <= end) {
        days.push({display:fmtDate(cur), dow:DAYS[cur.getDay()]});
        cur.setDate(cur.getDate()+1);
    }
    const sameDay  = fromStr === toStr;
    const rangeLabel = sameDay
        ? fmtDate(new Date(fromStr+'T12:00:00'))
        : fmtDate(new Date(fromStr+'T12:00:00'))+' → '+fmtDate(new Date(toStr+'T12:00:00'));
    document.getElementById('shMpRangeLabel').textContent = rangeLabel;
    document.getElementById('shMpCount').textContent = days.length+' day'+(days.length!==1?'s':'');
    const byMonth = {};
    days.forEach((d,i) => {
        const dt = new Date(fromStr+'T12:00:00'); dt.setDate(dt.getDate()+i);
        const mon = fmtDateKey(dt).substring(0,7);
        if (!byMonth[mon]) byMonth[mon] = []; byMonth[mon].push(d);
    });
    let html = '';
    Object.keys(byMonth).sort().forEach(mon => {
        const [yr,mo] = mon.split('-');
        html += `<div class="mp-month-group"><div class="mp-month-label">${MONTHS[parseInt(mo)-1]+' '+yr}</div><div class="mp-chips">`;
        byMonth[mon].forEach(d => {
            html += `<div class="mp-chip mpc-hol"><span class="mpc-dow">${d.dow}</span><span class="mpc-date">${d.display}</span></div>`;
        });
        html += `</div></div>`;
    });
    document.getElementById('shMpBody').innerHTML = html;
    preview.style.display = 'block';
}

/* ── Target types ──────────────────────────────── */
function setShTargetType(val) {
    document.getElementById('sh_f_target_type').value = val;
    document.querySelectorAll('#shTargetTypeTabs .tt-tab').forEach(t => t.classList.toggle('active', t.dataset.val===val));
    document.getElementById('sh_field_staff_category').style.display = val==='staff_category' ? 'block':'none';
    document.getElementById('sh_field_designation').style.display    = val==='designation'    ? 'block':'none';
    document.getElementById('sh_field_employee').style.display       = val==='employee'       ? 'block':'none';
}

/* ── Edit SH ───────────────────────────────────── */
function editSh(id) {
    const s = SH_DATA.find(x => x.id == id); if (!s) return;
    document.getElementById('shModal').classList.add('active');
    document.getElementById('sh_id').value            = s.id;
    document.getElementById('sh_f_title').value       = s.title;
    document.getElementById('sh_f_date_from').value   = s.date_from;
    document.getElementById('sh_f_date_to').value     = s.date_to;
    document.getElementById('sh_f_notes').value       = s.notes || '';
    document.getElementById('sh_f_active').checked    = s.active == 1;
    document.getElementById('shModalTitle').textContent    = 'Edit Special Holiday';
    document.getElementById('shSubmitBtnText').textContent = 'Update';
    $('#sh_sel_staff_categories,#sh_sel_designations,#sh_sel_employees').val(null).trigger('change');
    setShTargetType(s.target_type);
    if (s.target_ids) {
        let ids; try { ids = JSON.parse(s.target_ids); } catch(e) { ids = []; }
        if (s.target_type==='staff_category') $('#sh_sel_staff_categories').val(ids.map(String)).trigger('change');
        else if (s.target_type==='designation') $('#sh_sel_designations').val(ids.map(String)).trigger('change');
        else if (s.target_type==='employee') $('#sh_sel_employees').val(ids.map(String)).trigger('change');
    }
    setTimeout(onShDateChange, 50);
}

/* ── Search ────────────────────────────────────── */
function clearSearch() {
    document.getElementById('shSearchInput').value = '';
    document.getElementById('shSearchForm').submit();
}
let shSt;
document.getElementById('shSearchInput').addEventListener('input', function(){
    clearTimeout(shSt);
    shSt = setTimeout(() => document.getElementById('shSearchForm').submit(), 500);
});

/* ── Submit spinner ────────────────────────────── */
document.getElementById('shForm').addEventListener('submit', function(){
    const btn = document.getElementById('shSubmitBtn'); btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
});
</script>

<!-- ═══════════════════ STYLES ═══════════════════ -->
<style>
/* ── Alerts ── */
.alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:8px;margin-bottom:20px;font-size:13px;font-weight:500;animation:slideDown .3s ease}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}

/* ── Action bar ── */
.swd-action-bar{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px}
.swd-search-wrap{flex:1;max-width:480px}
.search-input-wrapper{position:relative;display:flex;align-items:center}
.search-icon{position:absolute;left:14px;color:#9ca3af;font-size:13px;pointer-events:none}
.search-input{width:100%;padding:11px 16px 11px 40px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;transition:all .2s;box-sizing:border-box}
.search-input:focus{outline:none;border-color:#d97706;box-shadow:0 0 0 3px rgba(217,119,6,.08)}
.clear-btn{position:absolute;right:10px;background:#f3f4f6;border:none;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#6b7280;font-size:11px}
.search-note{display:block;margin-top:6px;font-size:12px;color:#6b7280}

/* ── Card ── */
.content-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;margin-bottom:24px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid #f0f0f0}
.card-header-left{display:flex;align-items:center;gap:12px}
.card-icon-wrap{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.sh-icon{background:#fff7ed;color:#d97706}
.card-title{font-size:14px;font-weight:700;color:#111;margin:0 0 2px}
.card-sub{font-size:11px;color:#9ca3af;margin:0}
.item-count{background:#f9fafb;color:#6b7280;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;border:1px solid #e5e7eb}

/* ── Table ── */
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead{background:#f9fafb;border-bottom:1px solid #e5e7eb}
.data-table th{padding:11px 14px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;transition:background .15s}
.data-table tbody tr:hover{background:#fffbf5}
.data-table td{padding:13px 14px;color:#111;vertical-align:top}
.td-num{color:#9ca3af;font-size:12px}
.date-single{font-weight:700;font-size:13px}
.dow{display:block;font-size:11px;color:#9ca3af;margin-top:2px}
.date-range-wrap{display:flex;flex-direction:column;gap:2px}
.dr-from,.dr-to{font-size:12px;font-weight:600}
.dr-arr{color:#9ca3af;font-size:11px}
.total-days-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:#fff7ed;color:#b45309;border:1px solid #fed7aa}
.matched-days-wrap{display:flex;flex-wrap:wrap;gap:4px;align-items:center;max-width:260px}
.matched-chip{display:inline-flex;flex-direction:column;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:600;line-height:1.3}
.matched-chip em{font-style:normal;font-size:10px;font-weight:400;opacity:.8}
.mc-hol{background:#fefce8;color:#854d0e;border:1px solid #fef08a}
.matched-more{font-size:11px;color:#6b7280;font-weight:600;background:#f3f4f6;padding:2px 8px;border-radius:10px}
.tgt-badge{display:inline-flex;align-items:center;padding:2px 9px;border-radius:5px;font-size:11px;font-weight:600;margin:1px}
.tgt-all{background:#f3f4f6;color:#374151}
.tgt-cat{background:#f0f4ff;color:#3730a3;border:1px solid #c7d2fe}
.tgt-des{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe}
.tgt-emp{background:#fdf4ff;color:#6b21a8;border:1px solid #e9d5ff}
.tgt-more{background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb}
.tgt-none{color:#d1d5db}
.notes-cell{font-size:12px;color:#6b7280;cursor:help}
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600}
.badge-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.badge-inactive{background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb}
.action-buttons{display:flex;gap:6px}
.btn-action{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;color:#6b7280;cursor:pointer;transition:all .2s;text-decoration:none}
.btn-action:hover{transform:translateY(-1px);box-shadow:0 2px 6px rgba(0,0,0,.08)}
.btn-edit:hover{background:#d97706;color:#fff;border-color:#d97706}
.btn-delete:hover{background:#ef4444;color:#fff;border-color:#ef4444}
.empty-state{text-align:center;padding:60px 20px;color:#6b7280}
.empty-state i{font-size:56px;color:#fde68a;margin-bottom:16px}
.empty-state h3{font-size:16px;font-weight:600;color:#374151;margin-bottom:8px}
.empty-state p{font-size:13px;max-width:360px;margin:0 auto}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none;font-family:inherit;white-space:nowrap}
.btn-sh{background:linear-gradient(135deg,#d97706,#b45309);color:#fff}.btn-sh:hover{background:linear-gradient(135deg,#b45309,#92400e)}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e7eb}.btn-secondary:hover{background:#e5e5e5}

/* ══════════════════════════════════
   MODAL
══════════════════════════════════ */
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center}
.modal.active{display:flex}
.modal-content{background:#fff;border-radius:14px;width:92%;max-width:780px;max-height:92vh;overflow-y:auto;box-shadow:0 24px 64px rgba(0,0,0,.25)}
.modal-header{padding:20px 24px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:10}
.modal-header-left{display:flex;align-items:center;gap:12px}
.modal-header-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.sh-hdr-icon{background:#fff7ed;color:#d97706}
.mh-sh{border-bottom-color:#fed7aa}
.modal-title{font-size:16px;font-weight:700;color:#111}
.modal-close{background:#f3f4f6;border:none;width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#6b7280;font-size:16px;transition:all .2s}
.modal-close:hover{background:#e5e7eb;color:#111}
.modal-body{padding:24px}
.modal-footer{padding:18px 24px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:#fff;z-index:10}

/* ── SH info banner ── */
.sh-info-banner{display:flex;align-items:flex-start;gap:10px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 14px;margin-bottom:20px;font-size:13px;color:#78350f}
.sh-info-banner i{color:#d97706;font-size:15px;flex-shrink:0;margin-top:1px}

/* ── Form ── */
.form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.form-group{margin-bottom:20px}
.form-label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:7px}
.req{color:#ef4444}
.form-input{width:100%;padding:11px 14px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;transition:all .2s;box-sizing:border-box;background:#fff}
.form-input:focus{outline:none;border-color:#d97706;box-shadow:0 0 0 3px rgba(217,119,6,.08)}
.form-textarea{resize:vertical;min-height:80px}
.form-hint{display:block;font-size:11px;color:#9ca3af;margin-top:5px}

/* ── Matched preview ── */
.matched-preview{background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;margin-bottom:20px;overflow:hidden;animation:fadeIn .25s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
.mp-header{display:flex;align-items:center;gap:8px;padding:11px 16px;font-size:13px}
.sh-mp-header{background:#fef9c3;border-bottom:1px solid #fde68a}
.sh-mp-header i{color:#d97706}
.sh-mp-header strong{font-weight:700;color:#78350f}
.mp-count-badge{padding:1px 9px;border-radius:20px;font-size:11px;font-weight:700;color:#fff}
.sh-count-badge{background:#d97706}
.mp-range-label{margin-left:auto;font-size:11px;font-weight:600;color:#92400e}
.mp-body{padding:14px 16px;display:flex;flex-direction:column;gap:14px}
.mp-month-label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#d97706;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.mp-month-label::after{content:'';flex:1;height:1px;background:#fef08a}
.mp-chips{display:flex;flex-wrap:wrap;gap:6px}
.mp-chip{display:flex;flex-direction:column;padding:6px 12px;border-radius:8px;font-size:12px;line-height:1.4;border:1px solid transparent;min-width:110px}
.mpc-dow{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;opacity:.7}
.mpc-date{font-weight:700;font-size:12px}
.mpc-hol{background:#fefce8;border-color:#fef08a;color:#713f12}

/* ── Target tabs ── */
.target-type-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:4px}
.tt-tab{padding:8px 14px;border:1px solid #e5e7eb;border-radius:20px;background:#fff;font-size:12px;font-weight:600;cursor:pointer;color:#6b7280;transition:all .2s;font-family:inherit}
.tt-tab:hover{border-color:#d1d5db;color:#374151;background:#f9fafb}
.tt-tab.active{background:linear-gradient(135deg,#d97706,#b45309);color:#fff;border-color:#d97706}

/* ── Switch ── */
.switch-wrap{display:flex;align-items:center;gap:10px}
.switch{position:relative;display:inline-block;width:44px;height:22px}
.switch input{opacity:0;width:0;height:0}
.slider{position:absolute;cursor:pointer;inset:0;background:#e5e7eb;transition:.3s;border-radius:22px}
.slider:before{position:absolute;content:"";height:16px;width:16px;left:3px;bottom:3px;background:#fff;transition:.3s;border-radius:50%}
input:checked+.slider{background:#d97706}
input:checked+.slider:before{transform:translateX(22px)}
.switch-label{font-size:13px;font-weight:500;color:#374151}

/* ── Select2 overrides ── */
.select2-container--default .select2-selection--multiple{border:1px solid #e5e7eb!important;border-radius:8px!important;min-height:44px!important;padding:4px 8px!important;font-family:inherit}
.select2-container--default.select2-container--focus .select2-selection--multiple,.select2-container--default.select2-container--open .select2-selection--multiple{border-color:#d97706!important;box-shadow:0 0 0 3px rgba(217,119,6,.08)!important;outline:none!important}
.select2-container--default .select2-selection--multiple .select2-selection__choice{background:#d97706!important;color:#fff!important;border:none!important;border-radius:5px!important;padding:2px 8px!important;font-size:12px!important;font-weight:600!important}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove{color:rgba(255,255,255,.7)!important;margin-right:5px!important}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover{color:#fff!important}
.select2-container--default .select2-results__option--highlighted{background:#d97706!important;color:#fff!important}
.select2-dropdown{border:1px solid #e5e7eb!important;border-radius:8px!important;box-shadow:0 8px 24px rgba(0,0,0,.1)!important;font-size:13px;font-family:inherit}
.select2-container--default .select2-search--dropdown .select2-search__field{border:1px solid #e5e7eb!important;border-radius:6px!important;padding:8px 12px!important;font-family:inherit;font-size:13px}
.select2-container--default .select2-search--dropdown .select2-search__field:focus{border-color:#d97706!important;outline:none!important}
.select2-results__option{padding:9px 12px!important}

/* ── Responsive ── */
@media(max-width:768px){
    .swd-action-bar{flex-direction:column}
    .swd-search-wrap{max-width:100%;width:100%}
    .btn{width:100%;justify-content:center}
    .form-row-2{grid-template-columns:1fr}
    .modal-content{width:96%;margin:10px}
    .modal-footer{flex-direction:column}
    .modal-footer .btn{width:100%}
    .target-type-tabs{gap:4px}
    .mp-chip{min-width:90px}
}
</style>

<?php include 'footer.php'; ?>